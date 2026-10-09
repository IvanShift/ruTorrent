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
    private $presence;
    private $restoredStart = false;

    // $rpc reaches daemon methods; $presence answers 'absent', 'present' or
    // 'unknown' for the job's hash and is the only proof that may retire an
    // untouched job of a removed torrent.
    private function __construct($path, $data, $rpc, $move, $presence)
    {
        $this->path = $path;
        $this->data = $data;
        $this->rpc = $rpc;
        $this->move = $move;
        $this->presence = $presence;
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

    public static function create($journal, $data, $rpc, $move, $presence)
    {
        self::journalDirectory($journal);
        if (!is_callable($rpc) || !is_callable($move) || !is_callable($presence) || !is_array($data)
            || !isset($data['hash'], $data['directory'], $data['local_id'],
                $data['files'], $data['source'], $data['destination'], $data['add'], $data['move'],
                $data['previous_directory'])
            || preg_match('/^[0-9A-F]{40}$/D', $data['hash']) !== 1
            || !is_array($data['files']) || !is_string($data['local_id'])
            || $data['local_id'] === '' || !is_string($data['previous_directory'])
            || $data['previous_directory'] === '' || !is_string($data['directory'])
            || !is_string($data['source']) || !is_string($data['destination']))
            throw new RuntimeException('invalid-job');
        // The daemon strings are the daemon's own bytes. Version 2 was
        // written while rXMLRPCRequest escaped them (see ambiguous()); without
        // a quote or backslash, both readings are the same bytes. Such a
        // job stays version 2, and a release that reads only version 2 can
        // still run it after a rollback. Version 3 marks a job that needs the
        // new reading.
        $version = self::hasSpecialDaemonPathByte($data, '\\"') ? 3 : 2;
        $data = array('version' => $version, 'phase' => 'new', 'job' => bin2hex(random_bytes(16)),
            'hash' => $data['hash'], 'directory' => $data['directory'],
            'add' => (bool) $data['add'], 'move' => (bool) $data['move'],
            'source' => $data['source'], 'destination' => $data['destination'],
            'files' => $data['files'], 'local_id' => $data['local_id'],
            'previous_directory' => $data['previous_directory']);
        $path = $journal . '/' . $data['hash'] . '.json';
        DataDirDocument::publish($path, $data, true, 'job');
        return new self($path, $data, $rpc, $move, $presence);
    }

    public static function load($path, $rpc, $move, $presence)
    {
        if (!is_callable($rpc) || !is_callable($move) || !is_callable($presence)
            || is_link($path) || !is_file($path))
            throw new RuntimeException('job-unavailable');
        $json = @file_get_contents($path, false, null, 0, DataDirDocument::MAX_BYTES + 1);
        $data = is_string($json) && strlen($json) <= DataDirDocument::MAX_BYTES ? json_decode($json, true) : null;
        $keys = array('version', 'phase', 'job',
            'hash', 'directory', 'add', 'move', 'source', 'destination', 'files', 'local_id',
            'previous_directory');
        if (is_array($data) && array_key_exists('cancel_reason', $data))
            $keys[] = 'cancel_reason';
        if (!is_array($data) || array_keys($data) !== $keys
            || (array_key_exists('cancel_reason', $data) && ($data['version'] !== 3
                || !is_string($data['cancel_reason'])
                || preg_match('/^[a-z0-9.-]{1,160}$/D', $data['cancel_reason']) !== 1
                || !in_array($data['phase'], array('cancel-pending', 'ack-pending'), true)))
            || !in_array($data['version'], array(2, 3), true)
            || !in_array($data['phase'], array('new', 'claimed',
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
        return new self($path, $data, $rpc, $move, $presence);
    }

    public function id() { return $this->data['job']; }
    public function hash() { return $this->data['hash']; }
    public function phase() { return $this->data['phase']; }
    public function movesPayload() { return $this->data['move']; }
    // True when this run's own finish restored a started download.
    public function restoredStart() { return $this->restoredStart; }

    private function phaseSet($phase, $reason = null)
    {
        $next = $this->data;
        $next['phase'] = $phase;
        if ($reason !== null) {
            $next['version'] = 3;
            $next['cancel_reason'] = $reason;
        }
        DataDirDocument::publish($this->path, $next, false, 'job');
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

    // An older release wrote version 2 with previous_directory, source,
    // destination and files from a parser that put a backslash before every
    // '"' and '\' it read (AUD-ESC-08). With a backslash in any of them, the
    // stored bytes and their unescaped reading may both name real paths, and
    // nothing in the job tells which one the daemon meant. Without one, both
    // readings are the same bytes: the same rule as an unmarked erasedata
    // manifest, and the reason create() still writes such a job as version 2.
    private function ambiguous()
    {
        return $this->data['version'] === 2 && self::hasSpecialDaemonPathByte($this->data);
    }

    // The daemon strings of a job; the request's own directory is not one.
    private static function hasSpecialDaemonPathByte($data, $characters = '\\')
    {
        $strings = array_merge(array($data['previous_directory'], $data['source'],
            $data['destination']), $data['files']);
        foreach ($strings as $value)
            if (is_string($value) && strpbrk($value, $characters) !== false)
                return true;
        return false;
    }

    private function retire($intent)
    {
        if ($intent !== null && !$intent->retire())
            throw new RuntimeException('move-receipt-retire-failed');
        if (!@unlink($this->path))
            throw new RuntimeException('job-retire-failed');
        return true;
    }

    // An untouched new job whose torrent is attestedly gone has nothing to
    // recover: retire it and refuse. Any other presence answer keeps it.
    private function retireAbsent()
    {
        if (call_user_func($this->presence, $this->data['hash']) !== 'absent')
            return;
        $this->retire(null);
        throw new DataDirMoveRefused('torrent-absent');
    }

    // Retirement completes the safe cancellation, then reports its refusal.
    private function cancelUnmoved($state, $intent, $reason = null)
    {
        if ($this->data['phase'] !== 'cancel-pending')
        {
            if ($intent !== null && $intent->phase() !== 'preparing'
                && !$intent->beginAbortUnmoved())
                throw new RuntimeException('unmoved-abort-unconfirmed');
            // This phase must survive receipt deletion: a restarted worker
            // must finish cancellation instead of preparing another move.
            $this->phaseSet('cancel-pending', $reason);
        }
        if ($intent !== null)
        {
            if (!in_array($intent->phase(), array('preparing', 'aborting'), true))
                throw new RuntimeException('cancel-with-move-receipt');
            if (!$intent->abortUnmoved())
                throw new RuntimeException('unmoved-abort-unconfirmed');
        }
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
        $this->retire(null);
        // Old cancellation records did not preserve the triggering refusal.
        // Report that uncertainty rather than silently presenting a success.
        throw new DataDirMoveRefused(isset($this->data['cancel_reason'])
            ? $this->data['cancel_reason'] : 'interrupted-cancellation');
    }

    public function run()
    {
        $job = $this->data['job'];
        $movePath = $this->moveReceipt();
        $intent = is_file($movePath) ? DataDirMoveIntent::load($movePath, $this->move) : null;
        if ($intent === null && @lstat($movePath) !== false)
            throw new RuntimeException('move-receipt-untrusted');
        try {
            $state = self::state($this->call('d.stop_close_claim_state', array($job)));
        } catch (RuntimeException $error) {
            // A missing hash faults the addressed claim read. Only a separate
            // attested absence may retire an untouched new job on that answer.
            if ($this->data['phase'] === 'new' && $intent === null)
                $this->retireAbsent();
            throw $error;
        }
        if ($this->data['phase'] === 'ack-pending')
        {
            if ($state['kind'] !== 'absent' && $state['kind'] !== 'done')
                throw new RuntimeException('ack-pending-without-done');
            if ($state['kind'] === 'done')
                $this->call('d.ack_stop_close_claim', array($job));
            $this->retire($intent);
            if (isset($this->data['cancel_reason']))
                throw new DataDirMoveRefused($this->data['cancel_reason']);
            return true;
        }
        if ($state['kind'] === 'absent')
        {
            if ($this->data['phase'] !== 'new' || $intent !== null)
                throw new RuntimeException('claim-lost-before-ack');
            try {
                $state = self::state('active|' . $this->call('d.stop_close_claim', array($job)));
            } catch (RuntimeException $error) {
                // A lost reply may already own the original run state. Read
                // this exact job again before deciding that nothing was taken.
                $readback = call_user_func($this->rpc, 'd.stop_close_claim_state',
                    $this->data['hash'], array($job));
                if ($readback === 'absent') {
                    $this->retire(null);
                    throw new DataDirMoveRefused('daemon-claim-refused');
                }
                $this->retireAbsent();
                throw $error;
            }
        }
        elseif ($state['kind'] === 'active')
            $state = self::state('active|' . $this->call('d.stop_close_claim', array($job)));

        if ($state['local_id'] !== $this->data['local_id'])
        {
            if ($state['kind'] === 'active' && $intent === null)
            {
                $this->cancelUnmoved($state, null, 'torrent-generation-changed');
            }
            throw new RuntimeException('torrent-generation-changed');
        }
        if ($this->data['phase'] === 'cancel-pending'
            || ($intent !== null && $intent->phase() === 'aborting'))
            return $this->cancelUnmoved($state, $intent);
        // An ambiguous job may not compare any daemon answer with its strings
        // or move a payload file by them: they may be the escaped spelling of
        // the download's paths and name a neighbour. Until a payload file has
        // moved, that holds for its receipt too: a preparing one would be
        // prepared again from these strings, and a ready one bound its witness
        // links and identities to the files these strings named, so advancing
        // would move exactly those files. Drop the preparation or the unmoved
        // witness links, cancel the claim, which restores the original run
        // state, and retire the job. A ready receipt whose files cannot all be
        // proven still unmoved (one was moved, say) is held: advancing would
        // move more of them, and nothing here can tell where a moved one
        // belongs. Past the physical move (a finishing or later receipt,
        // setter-pending) the job keeps running: the setter sends the
        // request's own physical directory, never a daemon string, and
        // cancel-pending and ack-pending send only the job id and token.
        if ($state['kind'] === 'active' && $this->ambiguous()
            && ($this->data['phase'] === 'new' || $this->data['phase'] === 'claimed')
            && ($intent === null || in_array($intent->phase(), array('preparing', 'ready'), true)))
        {
            if ($intent !== null && $intent->phase() === 'ready' && !$intent->beginAbortUnmoved())
                throw new RuntimeException('job-paths-ambiguous-unmoved-unproven');
            $this->cancelUnmoved($state, $intent, 'job-paths-ambiguous');
        }
        if ($state['kind'] === 'active' && $intent === null
            && ($this->data['phase'] === 'new' || $this->data['phase'] === 'claimed')
            && $this->call('d.directory') !== $this->data['previous_directory'])
        {
            $this->cancelUnmoved($state, null, 'torrent-directory-changed');
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
                    // At most 0755, since the umask only removes bits: never
                    // group/other-writable, which the payload rule refuses
                    // above the configured top.
                    if (!is_dir($this->data['destination'])
                        && !@mkdir($this->data['destination'], 0755, true)
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
                    $this->cancelUnmoved($state, $partial, $e->getMessage());
                }
            }
            if ($intent->phase() === 'ready')
            {
                $result = $intent->advance();
                if ($result !== 'complete')
                {
                    // Retire a refused job only after abortUnmoved proves that
                    // no payload was published. Partial moves retain recovery.
                    try { $this->cancelUnmoved($state, $intent, 'move-' . $result); }
                    catch (DataDirMoveRefused $e) { throw $e; }
                    catch (RuntimeException $e) {
                        throw new RuntimeException('move-' . $result, 0, $e);
                    }
                }
                if (!$intent->markFinishing())
                    throw new RuntimeException('move-finishing-unconfirmed');
            }
        }

        if ($state['kind'] === 'active')
        {
            if ($this->data['move'] && $intent->phase() !== 'finishing')
                throw new RuntimeException('move-not-finished');
            if ($this->data['move']
                && (!ErasedataFilesystemOps::payloadDirectoryTrusted(rtrim($this->data['directory'], '/'))
                    || !ErasedataFilesystemOps::payloadDirectoryTrusted($this->data['destination'])))
                throw new RuntimeException('untrusted-directory');
            if ($this->data['phase'] !== 'setter-pending')
                $this->phaseSet('setter-pending');
            $setter = $this->data['add'] ? 'd.directory.set_if_stop_close_claim'
                : 'd.directory.base.set_if_stop_close_claim';
            if ((string) $this->call($setter, array($state['token'], $this->data['directory'])) !== '1')
                throw new RuntimeException('directory-set-refused');
            $finish = $state['state'] === 1 ? 'd.start_if_stop_close_claim'
                : ($state['open'] === 1 ? 'd.open_if_stop_close_claim' : 'd.release_stop_close_claim');
            // 1 means the claim restored the original run state; 0 keeps a
            // later user Stop. Callers check what a restored start became.
            $finished = $this->call($finish, array($state['token']));
            $this->restoredStart = $finish === 'd.start_if_stop_close_claim'
                && (string) $finished === '1';
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
