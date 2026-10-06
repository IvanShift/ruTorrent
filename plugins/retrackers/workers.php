<?php

// Stable lock slots bound RPC concurrency across CLI workers for one daemon.
// Lock files stay in place; removing them could give another worker a new inode.
final class RetrackersWorkerSlots
{
    private static $current = null;
    private static $poolPath = null;
    private $handle;

    private function __construct($handle) { $this->handle = $handle; }
    public function __destruct() { $this->release(); }
    public function release() {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
    }
    public static function retainCurrent($lease) { self::$current = $lease; }
    public static function releaseCurrent() {
        if (self::$current instanceof self) self::$current->release();
        self::$current = null;
    }
    public static function diagnosticPoolPath() { return self::$poolPath; }
    private static function trustedParents($path, $uid) {
        for ($at = $path; ; $at = dirname($at)) {
            $stat = @lstat($at);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 ||
                !in_array($stat['uid'], array(0, $uid), true) ||
                (($stat['mode'] & 0022) !== 0 && ($stat['mode'] & 01000) === 0)) return false;
            if ($at === '/') return true;
        }
    }
    public static function acquire($root, $daemonIdentity, &$failure = null, $limit = 8, $timeout = 300.0) {
        self::$poolPath = null;
        $failure = 'worker-slots-unavailable';
        if (!function_exists('posix_geteuid') || !is_string($daemonIdentity) ||
            !is_int($limit) || $limit < 1 || $limit > 32 || !is_numeric($timeout) || $timeout < 0) return false;
        $uid = posix_geteuid();
        $root = is_string($root) ? realpath($root) : false;
        if ($root === false) return false;
        $pool = $root . '/rutorrent-retrackers-workers-' . $uid . '-' . substr(hash('sha256', $daemonIdentity), 0, 16);
        self::$poolPath = $pool;
        if (!self::trustedParents($root, $uid)) return false;
        $mask = umask(0077);
        try { @mkdir($pool, 0700); } finally { umask($mask); }
        $directory = @lstat($pool);
        if (!is_array($directory) || ($directory['mode'] & 0170000) !== 0040000 ||
            ($directory['mode'] & 0777) !== 0700 || $directory['uid'] !== $uid) return false;
        $deadline = hrtime(true) + ((float)$timeout) * 1000000000.0;
        do {
            for ($slot = 0; $slot < $limit; $slot++) {
                $path = $pool . '/' . $slot . '.lock';
                $mask = umask(0077);
                try { $handle = @fopen($path, 'x+b'); } finally { umask($mask); }
                if (!is_resource($handle)) $handle = @fopen($path, 'rb');
                $opened = is_resource($handle) ? @fstat($handle) : false;
                clearstatcache(true, $path);
                $named = @lstat($path);
                $safe = is_array($opened) && is_array($named) &&
                    ($opened['mode'] & 0170000) === 0100000 && ($opened['mode'] & 0777) === 0600 &&
                    $opened['uid'] === $uid && $opened['nlink'] === 1 &&
                    $named['dev'] === $opened['dev'] && $named['ino'] === $opened['ino'];
                if (!$safe) {
                    if (is_resource($handle)) fclose($handle);
                    return false;
                }
                if (@flock($handle, LOCK_EX | LOCK_NB)) {
                    $failure = null;
                    return new self($handle);
                }
                fclose($handle);
            }
            if (hrtime(true) >= $deadline) break;
            usleep(50000);
        } while (true);
        $failure = 'worker-slots-timeout';
        return false;
    }
}
