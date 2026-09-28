<?php

// The witness hard links keep every original inode allocated across a worker
// crash, so a replacement at the destination cannot reuse its identity.
class DataDirMoveIntent
{
    const MAX_RECEIPT_BYTES = 16777216;
    const MAX_FILES = 100000;

    private $receipt;
    private $data;
    private $move;

    private function __construct($receipt, $data, $move)
    {
        $this->receipt = $receipt;
        $this->data = $data;
        $this->move = $move;
    }

    private static function statPath($path)
    {
        clearstatcache(true, $path);
        return @lstat($path);
    }

    private static function identity($path)
    {
        $stat = self::statPath($path);
        if ($stat === false)
            return false;
        if (($stat['mode'] & 0170000) !== 0100000)
            return 'nonregular';
        return (string) $stat['dev'] . ':' . (string) $stat['ino'];
    }

    private static function inside($path, $root)
    {
        return $path === $root || strpos($path, $root . '/') === 0;
    }

    private static function path($root, $relative)
    {
        if (!is_string($relative) || $relative === '' || $relative[0] === '/'
            || strpos($relative, "\0") !== false || strlen($relative) > 4096)
            throw new RuntimeException('invalid-file-path');
        foreach (explode('/', $relative) as $part)
            if ($part === '' || $part === '.' || $part === '..')
                throw new RuntimeException('invalid-file-path');
        return $root . '/' . $relative;
    }

    private static function trustedFile($path)
    {
        $stat = self::statPath($path);
        return is_array($stat) && ($stat['mode'] & 0170000) === 0100000
            && ($stat['uid'] === 0 || $stat['uid'] === posix_geteuid())
            && ($stat['mode'] & 0022) === 0;
    }

    // A different UID must not be able to replace any component of a path
    // between the inode witness check and the daemon directory setter.
    public static function trustedDirectory($path)
    {
        if (!is_string($path) || $path === '' || $path[0] !== '/'
            || @realpath($path) !== $path || !function_exists('posix_geteuid'))
            return false;
        $uid = posix_geteuid();
        $current = '';
        foreach (array_merge(array(''), array_filter(explode('/', $path), 'strlen')) as $part)
        {
            $current = rtrim($current, '/') . '/' . $part;
            $stat = self::statPath($current);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
                || ($stat['uid'] !== 0 && $stat['uid'] !== $uid)
                || (($stat['mode'] & 0022) !== 0 && ($stat['mode'] & 01000) === 0))
                return false;
        }
        return true;
    }

    private static function mounts()
    {
        $lines = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES);
        if (!is_array($lines) || count($lines) > 20000)
            throw new RuntimeException('mount-identity-unavailable');
        $mounts = array();
        foreach ($lines as $line)
        {
            $fields = explode(' ', $line);
            if (count($fields) < 5 || !ctype_digit($fields[0]))
                throw new RuntimeException('mount-identity-unavailable');
            $path = strtr($fields[4], array('\\040' => ' ', '\\011' => "\t",
                '\\012' => "\n", '\\134' => '\\'));
            $mounts[] = array($path, $fields[0]);
        }
        return $mounts;
    }

    private static function mountId($path, $mounts)
    {
        $best = -1;
        $id = false;
        foreach ($mounts as $mount)
        {
            $prefix = rtrim($mount[0], '/') . '/';
            if ($path === $mount[0] || strpos($path, $prefix) === 0)
            {
                $length = strlen($mount[0]);
                if ($length > $best)
                {
                    $best = $length;
                    $id = $mount[1];
                }
            }
        }
        return $id;
    }

    private static function writeReceipt($path, $data, $exclusive)
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > self::MAX_RECEIPT_BYTES)
            throw new RuntimeException('receipt-too-large');
        $parent = @realpath(dirname($path));
        if ($parent === false || !is_dir($parent))
            throw new RuntimeException('receipt-parent-unavailable');
        $staging = @tempnam($parent, '.datadir-intent-');
        if ($staging === false)
            throw new RuntimeException('receipt-staging-failed');
        $written = @file_put_contents($staging, $json);
        @chmod($staging, 0600);
        $published = $written === strlen($json)
            && ($exclusive ? @link($staging, $path) : @rename($staging, $path));
        @unlink($staging);
        if (!$published)
            throw new RuntimeException('receipt-publish-failed');
    }

    private function updatePhase($phase)
    {
        $next = $this->data;
        $next['phase'] = $phase;
        self::writeReceipt($this->receipt, $next, false);
        $this->data = $next;
        return true;
    }

    private function witnessPath($index)
    {
        return $this->data['witness'] . '/' . $index;
    }

    public static function prepare($receipt, $jobId, $hash, $sourceRoot, $destinationRoot, $files, $move = null)
    {
        if (!is_string($receipt) || $receipt === '' || !is_string($jobId)
            || preg_match('/^[0-9a-f]{32}$/D', $jobId) !== 1
            || !is_string($hash) || preg_match('/^[0-9A-Fa-f]{40}$/D', $hash) !== 1
            || !is_array($files) || count($files) > self::MAX_FILES)
            throw new RuntimeException('invalid-intent');
        if (!is_callable($move))
            throw new RuntimeException('move-unavailable');
        $sourceRoot = @realpath($sourceRoot);
        $destinationRoot = @realpath($destinationRoot);
        if ($sourceRoot === false || $destinationRoot === false
            || !is_dir($sourceRoot) || !is_dir($destinationRoot)
            || $sourceRoot === $destinationRoot)
            throw new RuntimeException('invalid-roots');
        if (!self::trustedDirectory($sourceRoot) || !self::trustedDirectory($destinationRoot))
            throw new RuntimeException('untrusted-directory');

        $entries = array();
        $seen = array();
        $mounts = self::mounts();
        $trusted = array();
        foreach ($files as $relative)
        {
            $source = self::path($sourceRoot, $relative);
            $destination = self::path($destinationRoot, $relative);
            if (isset($seen[$relative]))
                throw new RuntimeException('duplicate-file-path');
            $seen[$relative] = true;
            $sourceParent = @realpath(dirname($source));
            if ($sourceParent === false || !self::inside($sourceParent, $sourceRoot))
                throw new RuntimeException('source-outside-root');
            if (!isset($trusted[$sourceParent]) && !self::trustedDirectory($sourceParent))
                throw new RuntimeException('untrusted-directory');
            $trusted[$sourceParent] = true;
            $sourceIdentity = self::identity($source);
            if ($sourceIdentity === false || $sourceIdentity === 'nonregular')
                throw new RuntimeException('missing-or-nonregular-source');
            if (!self::trustedFile($source))
                throw new RuntimeException('untrusted-file');
            if (!is_dir(dirname($destination)) && !@mkdir(dirname($destination), 0777, true))
                throw new RuntimeException('destination-parent-unavailable');
            $destinationParent = @realpath(dirname($destination));
            if ($destinationParent === false || !self::inside($destinationParent, $destinationRoot))
                throw new RuntimeException('destination-outside-root');
            if (!isset($trusted[$destinationParent]) && !self::trustedDirectory($destinationParent))
                throw new RuntimeException('untrusted-directory');
            $trusted[$destinationParent] = true;
            if (self::identity($destination) !== false)
                throw new RuntimeException('occupied-destination');
            $sourceStat = @stat($sourceParent);
            $destinationStat = @stat($destinationParent);
            if ($sourceStat === false || $destinationStat === false)
                throw new RuntimeException('unreadable-parent');
            if ($sourceStat['dev'] !== $destinationStat['dev']
                || self::mountId($sourceParent . '/' . basename($source), $mounts)
                    !== self::mountId($destinationParent, $mounts))
                throw new RuntimeException('cross-filesystem');
            $entries[] = array('path' => $relative, 'identity' => '');
        }
        $witness = $destinationRoot . '/.rutorrent-datadir-' . $jobId;
        if (self::statPath($witness) !== false)
            throw new RuntimeException('occupied-witness');
        $data = array('version' => 2, 'phase' => 'preparing', 'job' => $jobId,
            'hash' => strtoupper($hash), 'source' => $sourceRoot,
            'destination' => $destinationRoot, 'witness' => $witness, 'files' => $entries);
        self::writeReceipt($receipt, $data, true);
        $intent = new self($receipt, $data, $move);
        if (!@mkdir($witness, 0700))
            throw new RuntimeException('witness-create-failed');
        foreach ($entries as $index => $entry)
        {
            $source = self::path($sourceRoot, $entry['path']);
            $link = $intent->witnessPath($index);
            if (!@link($source, $link))
                throw new RuntimeException('witness-link-failed');
            $identity = self::identity($link);
            if ($identity === false || $identity === 'nonregular' || self::identity($source) !== $identity)
                throw new RuntimeException('witness-source-changed');
            $data['files'][$index]['identity'] = $identity;
        }
        $intent->data = $data;
        $intent->updatePhase('ready');
        return $intent;
    }

    public static function load($receipt, $move = null)
    {
        if (!is_string($receipt) || !is_file($receipt) || is_link($receipt))
            throw new RuntimeException('receipt-unavailable');
        $json = @file_get_contents($receipt, false, null, 0, self::MAX_RECEIPT_BYTES + 1);
        if (!is_string($json) || strlen($json) > self::MAX_RECEIPT_BYTES)
            throw new RuntimeException('receipt-unreadable');
        $data = json_decode($json, true);
        if (!is_array($data) || array_keys($data) !== array('version', 'phase', 'job', 'hash',
            'source', 'destination', 'witness', 'files')
            || $data['version'] !== 2 || !in_array($data['phase'], array('preparing', 'ready', 'finishing', 'terminal', 'ack-pending'), true)
            || !is_string($data['job']) || preg_match('/^[0-9a-f]{32}$/D', $data['job']) !== 1
            || !is_string($data['hash']) || preg_match('/^[0-9A-F]{40}$/D', $data['hash']) !== 1
            || !is_string($data['source']) || !is_string($data['destination'])
            || !is_string($data['witness']) || !is_array($data['files'])
            || count($data['files']) > self::MAX_FILES)
            throw new RuntimeException('receipt-corrupt');
        $sourceRoot = @realpath($data['source']);
        if (($sourceRoot !== $data['source']
                && !($data['phase'] === 'ack-pending' && self::statPath($data['source']) === false))
            || @realpath($data['destination']) !== $data['destination']
            || $data['witness'] !== $data['destination'] . '/.rutorrent-datadir-' . $data['job'])
            throw new RuntimeException('receipt-roots-changed');
        $seen = array();
        foreach ($data['files'] as $entry)
        {
            if (!is_array($entry) || array_keys($entry) !== array('path', 'identity')
                || !is_string($entry['identity'])
                || ($data['phase'] !== 'preparing'
                    && preg_match('/^[0-9]+:[0-9]+$/D', $entry['identity']) !== 1))
                throw new RuntimeException('receipt-corrupt');
            self::path($data['source'], $entry['path']);
            if (isset($seen[$entry['path']]))
                throw new RuntimeException('receipt-corrupt');
            $seen[$entry['path']] = true;
        }
        return new self($receipt, $data, $move);
    }

    public function phase()
    {
        return $this->data['phase'];
    }

    public function advance($maxFiles = PHP_INT_MAX)
    {
        if (!is_int($maxFiles) || $maxFiles < 0)
            throw new RuntimeException('invalid-step-limit');
        if ($this->data['phase'] !== 'ready' && $this->data['phase'] !== 'finishing')
            return 'wrong-phase';
        if (!self::trustedDirectory($this->data['source'])
            || !self::trustedDirectory($this->data['destination']))
            return 'untrusted-directory';
        $trusted = array();
        $moved = 0;
        foreach ($this->data['files'] as $index => $entry)
        {
            $source = self::path($this->data['source'], $entry['path']);
            $destination = self::path($this->data['destination'], $entry['path']);
            $sourceParent = @realpath(dirname($source));
            $destinationParent = @realpath(dirname($destination));
            if ($sourceParent === false || !self::inside($sourceParent, $this->data['source']))
                return 'source-outside-root';
            if (!isset($trusted[$sourceParent]) && !self::trustedDirectory($sourceParent))
                return 'untrusted-directory';
            $trusted[$sourceParent] = true;
            if ($destinationParent === false || !self::inside($destinationParent, $this->data['destination']))
                return 'destination-outside-root';
            if (!isset($trusted[$destinationParent]) && !self::trustedDirectory($destinationParent))
                return 'untrusted-directory';
            $trusted[$destinationParent] = true;
            $expected = $entry['identity'];
            if (self::identity($this->witnessPath($index)) !== $expected)
                return 'missing-or-changed-witness';
            if (!self::trustedFile($this->witnessPath($index)))
                return 'untrusted-file';
            $sourceIdentity = self::identity($source);
            $destinationIdentity = self::identity($destination);
            if ($destinationIdentity !== false && $destinationIdentity !== $expected)
                return 'occupied-destination';
            if ($sourceIdentity !== false && $sourceIdentity !== $expected)
                return 'changed-source';
            if (($sourceIdentity === $expected && !self::trustedFile($source))
                || ($destinationIdentity === $expected && !self::trustedFile($destination)))
                return 'untrusted-file';
            if ($sourceIdentity === false && $destinationIdentity === $expected)
                continue;
            if ($destinationIdentity === $expected)
                return 'duplicate-owned-inode';
            if ($moved >= $maxFiles)
                return 'pending';
            if ($sourceIdentity === false)
                $ok = @link($this->witnessPath($index), $destination);
            else
            {
                if (!is_callable($this->move))
                    return 'move-unavailable';
                $ok = call_user_func($this->move, $source, $destination);
            }
            $sourceAfter = self::identity($source);
            $destinationAfter = self::identity($destination);
            if ($sourceAfter === false && $destinationAfter === $expected)
            {
                $moved++;
                continue;
            }
            if ($destinationAfter !== false && $destinationAfter !== $expected)
                return 'occupied-destination';
            if ($sourceAfter !== false && $sourceAfter !== $expected)
                return 'changed-source';
            return $ok ? 'move-incomplete' : 'move-refused';
        }
        return 'complete';
    }

    public function markFinishing()
    {
        return $this->data['phase'] === 'ready' && $this->advance(0) === 'complete'
            ? $this->updatePhase('finishing') : false;
    }

    // Caller must first confirm a job-matched durable daemon DONE outcome.
    public function markTerminal()
    {
        return $this->data['phase'] === 'finishing' && $this->advance(0) === 'complete'
            ? $this->updatePhase('terminal') : false;
    }

    private function cleanWitness($checkIdentity)
    {
        $witness = $this->data['witness'];
        $directory = self::statPath($witness);
        if ($directory === false)
            return true;
        if (($directory['mode'] & 0170000) !== 0040000
            || @realpath($witness) !== $witness)
            return false;
        $entries = @scandir($witness);
        if (!is_array($entries))
            return false;
        foreach (array_diff($entries, array('.', '..')) as $name)
        {
            if (!ctype_digit($name) || (string) (int) $name !== $name
                || !isset($this->data['files'][(int) $name]))
                return false;
            $path = $witness . '/' . $name;
            $identity = self::identity($path);
            if ($identity === false || $identity === 'nonregular'
                || ($checkIdentity && $identity !== $this->data['files'][(int) $name]['identity']))
                return false;
            if (!@unlink($path))
                return false;
        }
        return @rmdir($witness);
    }

    // Preparing has no payload move. Ready may be cancelled only while every
    // original inode is still at source and every destination is absent.
    public function abortPreparation()
    {
        if ($this->data['phase'] !== 'preparing' || !$this->cleanWitness(false))
            return false;
        return @unlink($this->receipt);
    }

    public function abortUnmoved()
    {
        if ($this->data['phase'] === 'preparing')
            return $this->abortPreparation();
        if ($this->data['phase'] !== 'ready')
            return false;
        foreach ($this->data['files'] as $index => $entry)
        {
            $source = self::path($this->data['source'], $entry['path']);
            $destination = self::path($this->data['destination'], $entry['path']);
            if (self::identity($this->witnessPath($index)) !== $entry['identity']
                || self::identity($source) !== $entry['identity']
                || self::identity($destination) !== false)
                return false;
        }
        return $this->cleanWitness(true) && @unlink($this->receipt);
    }

    // Keep the receipt discoverable after witness cleanup until daemon ACK.
    public function markAckPending()
    {
        if ($this->data['phase'] === 'ack-pending')
            return self::statPath($this->data['witness']) === false;
        return $this->data['phase'] === 'terminal' && $this->cleanWitness(true)
            ? $this->updatePhase('ack-pending') : false;
    }

    // Caller invokes this only after the daemon has ACKed this job's DONE.
    public function retire()
    {
        if ($this->data['phase'] !== 'ack-pending'
            || self::statPath($this->data['witness']) !== false)
            return false;
        return @unlink($this->receipt);
    }
}
