<?php

require_once __DIR__ . '/moveintent.php';

// A refusal with proven cancellation has no outstanding recovery obligation.
class DataDirMoveRefused extends RuntimeException {}

// One profile-local job binds the daemon claim to the payload witness receipt.
class DataDirMoveJob
{
    private $path;
    private $data;
    private $rpc;
    private $move;

    private function __construct($path, $data, $rpc, $move)
    {
        $this->path = $path;
        $this->data = $data;
        $this->rpc = $rpc;
        $this->move = $move;
    }

    private static function write($path, $data, $exclusive)
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 16777216)
            throw new RuntimeException('job-too-large');
        $parent = dirname($path);
        $tmp = @tempnam($parent, '.datadir-job-');
        if ($tmp === false)
            throw new RuntimeException('job-staging-failed');
        $ok = @file_put_contents($tmp, $json) === strlen($json) && @chmod($tmp, 0600)
            && ($exclusive ? @link($tmp, $path) : @rename($tmp, $path));
        @unlink($tmp);
        if (!$ok)
            throw new RuntimeException('job-publish-failed');
    }

    public static function journalDirectory($path)
    {
        if (!is_dir($path) && !@mkdir($path, 0700))
            throw new RuntimeException('job-directory-unavailable');
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $canonical = @realpath($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
            || ($stat['mode'] & 0077) !== 0 || $canonical === false)
            throw new RuntimeException('job-directory-untrusted');
        return $canonical;
    }

    public static function create($journal, $data, $rpc, $move)
    {
        self::journalDirectory($journal);
        if (!is_callable($rpc) || !is_callable($move) || !is_array($data)
            || !isset($data['hash'], $data['directory'], $data['local_id'],
                $data['files'], $data['source'], $data['destination'], $data['add'], $data['move'],
                $data['previous_directory'])
            || preg_match('/^[0-9A-F]{40}$/D', $data['hash']) !== 1
            || !is_array($data['files']) || !is_string($data['local_id'])
            || $data['local_id'] === '' || !is_string($data['previous_directory'])
            || $data['previous_directory'] === '' || !is_string($data['directory'])
            || !is_string($data['source']) || !is_string($data['destination']))
            throw new RuntimeException('invalid-job');
        $data = array('version' => 2, 'phase' => 'new', 'job' => bin2hex(random_bytes(16)),
            'hash' => $data['hash'], 'directory' => $data['directory'],
            'add' => (bool) $data['add'], 'move' => (bool) $data['move'],
            'source' => $data['source'], 'destination' => $data['destination'],
            'files' => $data['files'], 'local_id' => $data['local_id'],
            'previous_directory' => $data['previous_directory']);
        $path = $journal . '/' . $data['hash'] . '.json';
        self::write($path, $data, true);
        return new self($path, $data, $rpc, $move);
    }

    public static function load($path, $rpc, $move)
    {
        if (!is_callable($rpc) || !is_callable($move) || is_link($path) || !is_file($path))
            throw new RuntimeException('job-unavailable');
        $json = @file_get_contents($path, false, null, 0, 16777217);
        $data = is_string($json) && strlen($json) <= 16777216 ? json_decode($json, true) : null;
        if (!is_array($data) || array_keys($data) !== array('version', 'phase', 'job',
            'hash', 'directory', 'add', 'move', 'source', 'destination', 'files', 'local_id',
            'previous_directory')
            || $data['version'] !== 2 || !in_array($data['phase'], array('new', 'claimed',
                'setter-pending', 'cancel-pending', 'ack-pending'), true)
            || preg_match('/^[0-9a-f]{32}$/D', $data['job']) !== 1
            || preg_match('/^[0-9A-F]{40}$/D', $data['hash']) !== 1
            || basename($path) !== $data['hash'] . '.json'
            || !is_bool($data['add']) || !is_bool($data['move'])
            || !is_string($data['directory']) || !is_string($data['source'])
            || !is_string($data['destination']) || !is_array($data['files'])
            || !is_string($data['local_id']) || !is_string($data['previous_directory'])
            || $data['previous_directory'] === '')
            throw new RuntimeException('job-corrupt');
        return new self($path, $data, $rpc, $move);
    }

    public function id() { return $this->data['job']; }
    public function hash() { return $this->data['hash']; }
    public function phase() { return $this->data['phase']; }

    private function phaseSet($phase)
    {
        $next = $this->data;
        $next['phase'] = $phase;
        self::write($this->path, $next, false);
        $this->data = $next;
    }

    private function call($method, $args = array())
    {
        $value = call_user_func($this->rpc, $method, $this->data['hash'], $args);
        if ($value === false || $value === null)
            throw new RuntimeException('daemon-' . str_replace('.', '-', $method) . '-unconfirmed');
        return $value;
    }

    private static function state($value)
    {
        if (!is_string($value))
            throw new RuntimeException('claim-state-invalid');
        if ($value === 'absent')
            return array('kind' => 'absent');
        $parts = explode('|', $value);
        $kind = array_shift($parts);
        $mode = null;
        if ($kind === 'terminal' || $kind === 'done')
            $mode = array_shift($parts);
        if (!in_array($kind, array('active', 'terminal', 'done'), true)
            || ($mode !== null && !in_array($mode, array('S', 'O', 'R', 'K'), true))
            || count($parts) !== 5 || !is_string($parts[0]) || $parts[0] === ''
            || !in_array($parts[1], array('0', '1'), true)
            || !in_array($parts[2], array('0', '1'), true)
            || !in_array($parts[3], array('0', '1'), true) || $parts[4] === '')
            throw new RuntimeException('claim-state-invalid');
        return array('kind' => $kind, 'mode' => $mode, 'token' => $parts[0],
            'state' => (int) $parts[1], 'open' => (int) $parts[2],
            'active' => (int) $parts[3], 'local_id' => $parts[4]);
    }

    private function moveReceipt() { return $this->path . '.move'; }

    private function retire($intent)
    {
        if ($intent !== null && !$intent->retire())
            throw new RuntimeException('move-receipt-retire-failed');
        if (!@unlink($this->path))
            throw new RuntimeException('job-retire-failed');
        return true;
    }

    private function cancelUnmoved($state, $intent)
    {
        if ($this->data['phase'] !== 'cancel-pending')
        {
            if ($intent !== null && !$intent->abortUnmoved())
                throw new RuntimeException('unmoved-abort-unconfirmed');
            $this->phaseSet('cancel-pending');
        }
        elseif ($intent !== null)
            throw new RuntimeException('cancel-with-move-receipt');
        if ($state['kind'] === 'active')
        {
            $finish = $state['state'] === 1 ? 'd.start_if_stop_close_claim'
                : ($state['open'] === 1 ? 'd.open_if_stop_close_claim' : 'd.release_stop_close_claim');
            $this->call($finish, array($state['token']));
        }
        $done = self::state($this->call('d.stop_close_claim_state', array($this->data['job'])));
        if ($done['kind'] === 'terminal')
        {
            $this->call('d.replay_stop_close_claim', array($this->data['job']));
            $done = self::state($this->call('d.stop_close_claim_state', array($this->data['job'])));
        }
        if ($done['kind'] !== 'done')
            throw new RuntimeException('unmoved-done-unconfirmed');
        $this->phaseSet('ack-pending');
        $this->call('d.ack_stop_close_claim', array($this->data['job']));
        return $this->retire(null);
    }

    public function run()
    {
        $job = $this->data['job'];
        $state = self::state($this->call('d.stop_close_claim_state', array($job)));
        $movePath = $this->moveReceipt();
        $intent = is_file($movePath) ? DataDirMoveIntent::load($movePath, $this->move) : null;
        if ($this->data['phase'] === 'ack-pending')
        {
            if ($state['kind'] !== 'absent' && $state['kind'] !== 'done')
                throw new RuntimeException('ack-pending-without-done');
            if ($state['kind'] === 'done')
                $this->call('d.ack_stop_close_claim', array($job));
            return $this->retire($intent);
        }
        if ($state['kind'] === 'absent')
        {
            if ($this->data['phase'] !== 'new' || $intent !== null)
                throw new RuntimeException('claim-lost-before-ack');
            $state = self::state('active|' . $this->call('d.stop_close_claim', array($job)));
        }
        elseif ($state['kind'] === 'active')
            $state = self::state('active|' . $this->call('d.stop_close_claim', array($job)));

        if ($state['local_id'] !== $this->data['local_id'])
        {
            if ($state['kind'] === 'active' && $intent === null)
            {
                $this->cancelUnmoved($state, null);
                throw new DataDirMoveRefused('torrent-generation-changed');
            }
            throw new RuntimeException('torrent-generation-changed');
        }
        if ($this->data['phase'] === 'cancel-pending')
            return $this->cancelUnmoved($state, $intent);
        if ($state['kind'] === 'active' && $intent === null
            && ($this->data['phase'] === 'new' || $this->data['phase'] === 'claimed')
            && $this->call('d.directory') !== $this->data['previous_directory'])
        {
            $this->cancelUnmoved($state, null);
            throw new DataDirMoveRefused('torrent-directory-changed');
        }
        if ($this->data['phase'] === 'new')
            $this->phaseSet('claimed');

        if ($this->data['move'])
        {
            if (($state['kind'] === 'terminal' || $state['kind'] === 'done')
                && ($intent === null || !in_array($intent->phase(),
                    array('finishing', 'terminal', 'ack-pending'), true)))
                throw new RuntimeException('daemon-terminal-without-payload-receipt');
            if ($intent !== null && $intent->phase() === 'preparing')
            {
                if (!$intent->abortPreparation())
                    throw new RuntimeException('move-preparation-cleanup-failed');
                $intent = null;
            }
            if ($intent === null)
            {
                try {
                    if (!is_dir($this->data['destination'])
                        && !@mkdir($this->data['destination'], 0777, true)
                        && !is_dir($this->data['destination']))
                        throw new RuntimeException('destination-root-unavailable');
                    if (@realpath($this->data['destination']) !== $this->data['destination'])
                        throw new RuntimeException('destination-root-changed');
                    $intent = DataDirMoveIntent::prepare($movePath, $job,
                        $this->data['hash'], $this->data['source'],
                        $this->data['destination'], $this->data['files'], $this->move);
                } catch (RuntimeException $e) {
                    $partial = is_file($movePath)
                        ? DataDirMoveIntent::load($movePath, $this->move) : null;
                    $this->cancelUnmoved($state, $partial);
                    throw new DataDirMoveRefused($e->getMessage());
                }
            }
            if ($intent->phase() === 'ready')
            {
                $result = $intent->advance();
                if ($result !== 'complete')
                    throw new RuntimeException('move-' . $result);
                if (!$intent->markFinishing())
                    throw new RuntimeException('move-finishing-unconfirmed');
            }
        }

        if ($state['kind'] === 'active')
        {
            if ($this->data['move'] && $intent->phase() !== 'finishing')
                throw new RuntimeException('move-not-finished');
            if ($this->data['move']
                && (!DataDirMoveIntent::trustedDirectory(rtrim($this->data['directory'], '/'))
                    || !DataDirMoveIntent::trustedDirectory($this->data['destination'])))
                throw new RuntimeException('untrusted-directory');
            if ($this->data['phase'] !== 'setter-pending')
                $this->phaseSet('setter-pending');
            $setter = $this->data['add'] ? 'd.directory.set_if_stop_close_claim'
                : 'd.directory.base.set_if_stop_close_claim';
            if ((string) $this->call($setter, array($state['token'], $this->data['directory'])) !== '1')
                throw new RuntimeException('directory-set-refused');
            $finish = $state['state'] === 1 ? 'd.start_if_stop_close_claim'
                : ($state['open'] === 1 ? 'd.open_if_stop_close_claim' : 'd.release_stop_close_claim');
            $this->call($finish, array($state['token']));
        }
        $state = self::state($this->call('d.stop_close_claim_state', array($job)));
        if ($state['kind'] === 'terminal')
        {
            $this->call('d.replay_stop_close_claim', array($job));
            $state = self::state($this->call('d.stop_close_claim_state', array($job)));
        }
        if ($state['kind'] !== 'done')
            throw new RuntimeException('daemon-done-unconfirmed');
        if ($intent !== null)
        {
            if ($intent->phase() === 'finishing' && !$intent->markTerminal())
                throw new RuntimeException('move-terminal-unconfirmed');
            if (!$intent->markAckPending())
                throw new RuntimeException('move-ack-preparation-failed');
        }
        $this->phaseSet('ack-pending');
        $this->call('d.ack_stop_close_claim', array($job));
        return $this->retire($intent);
    }
}
