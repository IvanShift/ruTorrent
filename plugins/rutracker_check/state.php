<?php

// Shared JSON-backed persistence for rutracker_check's per-layer state files
// (announce.json, forumindex.json, ...). Each name resolves to
// <settings path>/rutracker_check/<name>.json. save() writes atomically
// (temp file + rename), following the precedent in
// plugins/trafic/update.php:37-54 and plugins/trafic/stat.php:49-64.
//
// load()+save() is a read-modify-write over the WHOLE document with no
// locking, which is fine for a read-only caller but unsafe for a
// read-modify-write one: three writers exist by design and overlap in time
// (the hourly update.php, its detached forumcrawl.php, and a detached
// batch_check.php per manual "check" click), so a caller that loads, does
// something slow (e.g. forumindex.php's fetchDump(), a 30s HTTP fetch), and
// only then saves can persist a snapshot that is already stale, silently
// erasing whatever a concurrent writer recorded in between. update() is the
// safe alternative for every such site: see its own docblock below.
class RuTrackerState
{
    // Test-only override for the storage directory, set through
    // strictSetPrivateStatic() in tests/plugins/rutracker_check/TestLib.php.
    private static $dir = null;

    static private function dir()
    {
        $dir = self::$dir !== null ? self::$dir : FileUtil::getSettingsPath() . '/rutracker_check';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        return $dir;
    }

    static public function load($name)
    {
        $raw = @file_get_contents(self::dir() . '/' . $name . '.json');
        $state = is_string($raw) ? @json_decode($raw, true) : null;
        return is_array($state) ? $state : array();
    }

    static public function save($name, $state)
    {
        $dir = self::dir();
        $tmp = @tempnam($dir, $name);
        if ($tmp === false) return;
        if (@file_put_contents($tmp, json_encode($state)) === false) {
            @unlink($tmp);
            return;
        }
        if (!@rename($tmp, $dir . '/' . $name . '.json')) {
            @unlink($tmp);
        }
    }

    // Atomic read-modify-write: opens <name>.json (creating it if missing),
    // takes an exclusive lock, reads whatever is CURRENTLY on disk, applies
    // $mutator to it (a callable taking the current state array and
    // returning the new one), writes the result back, and releases the
    // lock. Because the read happens under the lock and after it is
    // acquired, $mutator's input is always the latest write from any other
    // caller of update(), never a snapshot obtained earlier -- unlike
    // load()+save(), no caller can persist something older than what is on
    // disk at write time.
    //
    // $mutator must stay quick: the lock is held for its entire duration,
    // and every other update()/save() call for the same $name blocks on it.
    // In particular it must never wrap a slow operation like an HTTP fetch
    // -- callers with one (forumindex.php's fetchDump()) do the fetch
    // outside update() entirely and only reach for it to apply the result.
    static public function update($name, $mutator)
    {
        $dir = self::dir();
        $fp = @fopen($dir . '/' . $name . '.json', 'c+');
        if ($fp === false) return;

        try {
            if (!flock($fp, LOCK_EX)) return;
            try {
                $raw = stream_get_contents($fp);
                $state = is_string($raw) && $raw !== '' ? @json_decode($raw, true) : null;
                $state = is_array($state) ? $state : array();

                $state = call_user_func($mutator, $state);

                rewind($fp);
                ftruncate($fp, 0);
                fwrite($fp, json_encode($state));
                fflush($fp);
            } finally {
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }
}
