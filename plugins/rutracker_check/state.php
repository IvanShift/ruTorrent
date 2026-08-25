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

    // The SHARED settings path, not the calling profile's. What these
    // documents hold -- the per-host announce budget, the 403 cooldown, the
    // forum sweep cooldown -- exists to bound outbound traffic to a tracker,
    // and a tracker sees one installation however many ruTorrent profiles
    // drive it. Keeping them per-profile multiplied every one of those caps
    // by the number of profiles. Same path, and the same reasoning, as the
    // cycle lock below.
    //
    // makeDirectory() rather than mkdir(): it wraps the call in umask(0) so
    // the requested mode actually takes, and chmods a directory that already
    // exists. A plain mkdir under the usual umask 022 leaves the directory
    // 0755, and then the OTHER OS user of a split scheduler/web-server
    // install cannot create the lock or temp files inside it -- which is
    // exactly the failure the per-file chmod in replace() was added to
    // prevent, undone one level up.
    static private function dir()
    {
        $dir = self::$dir !== null ? self::$dir
            : FileUtil::getSettingsPathEx('') . '/rutracker_check';
        // makeDirectory() also chmods an existing path. An older release may
        // already have created it through umask 022 as 0755/0700, which keeps
        // the other OS user of a split scheduler/web-server install from
        // creating the lock and temporary files inside it.
        FileUtil::makeDirectory($dir);
        return $dir;
    }

    // A whole-cycle mutex, and a different thing from the per-document locks
    // below: those keep one JSON file internally consistent, this keeps two
    // cycles from doing the same outward-facing work twice. Overlapping passes
    // are not merely wasteful. Every safeguard that bounds outbound traffic --
    // the announce cap, the forum-dump memo, the Kinozal session latch -- is a
    // per-process static and cannot see its twin, so two cycles silently double
    // each limit. They also share one loginmgr cookie jar: a failed request in
    // one erases the stored session for both, the other logs in again, and a
    // tracker that allows a single live session per account keeps knocking the
    // pair out in turn.
    //
    // Non-blocking on purpose: a cycle that cannot get in has nothing useful to
    // wait for, since the running one is already doing exactly its work. The
    // handle is returned for the caller to hold for the process lifetime; the
    // lock is released when the process exits and the descriptor closes, so a
    // cycle killed mid-run cannot wedge the next one.
    //
    // Returns the open handle when the lock is taken, false when another cycle
    // holds it, and true when no lock file could be created at all -- a guard
    // that cannot be built must not stop the cycle, since losing the guard is
    // better than losing every cycle.
    // Deliberately NOT dir(): that path sits inside one ruTorrent user's
    // profile, and the live system had two of them -- an anonymous one and
    // "torrent" -- driving the very same rTorrent. Each got its own lock, so
    // neither could see the other and both cycles ran, which is the whole
    // reason the guard did nothing. What needs protecting is the daemon and
    // the trackers behind it, not a profile, so the lock lives outside every
    // user's profile and is keyed by how this ruTorrent reaches its rTorrent:
    // a genuine multiuser install with a daemon per user keeps a lock per
    // daemon and never serialises unrelated cycles.
    static private function cycleLockPath()
    {
        global $scgi_host, $scgi_port, $XMLRPCMountPoint;

        // dir() is that same shared location (and the test override stands in
        // for it wholesale, so a run stays inside its own temporary tree).
        $dir = self::dir();
        $daemon = md5((isset($scgi_host) ? $scgi_host : '')
            . ':' . (isset($scgi_port) ? $scgi_port : '')
            . ':' . (isset($XMLRPCMountPoint) ? $XMLRPCMountPoint : ''));
        return $dir . '/cycle-' . substr($daemon, 0, 8) . '.lock';
    }

    // Open (creating if needed) a lock file that BOTH OS users can take.
    // The umask is narrowed for the create itself so the file never exists
    // with a mode the other user cannot open, and the chmod afterwards repairs
    // a file an earlier version left too tight.
    static private function openShared($path)
    {
        global $profileMask;
        $mask = (isset($profileMask) ? $profileMask : 0777) & 0666;
        $previous = @umask(~$mask & 0777);
        $fp = @fopen($path, 'c');
        @umask($previous);
        if ($fp !== false) @chmod($path, $mask);
        return $fp;
    }

    static public function acquireCycleLock()
    {
        global $profileMask;
        $path = self::cycleLockPath();
        // Whichever OS user gets here first creates the file; the guard is
        // worth nothing unless the other one can open it too, and this
        // function fails OPEN, so a permission problem here would silently let
        // both cycles run -- the exact thing the lock exists to stop.
        //
        // The mask is set for the CREATE rather than chmod'ed afterwards:
        // between fopen() and a later chmod the file exists with whatever the
        // process umask allowed, and the second user arriving inside that
        // window is turned away. The chmod stays as well, for a file an older
        // version already created too tightly.
        $fp = self::openShared($path);
        if ($fp === false) return true;
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            @fclose($fp);
            return false;
        }
        return $fp;
    }

    /**
     * A short cross-process lock for one logical resource. Unlike the cycle
     * lock, this one blocks: feed and crawl write the same per-torrent forum
     * mapping, and the loser must re-read after the winner rather than make a
     * decision from a snapshot taken before it acquired the lock.
     *
     * The returned handle owns the lock until releaseScopedLock() is called.
     * false means no guard could be established, so callers must fail closed
     * and leave their durable obligation intact.
     */
    static public function acquireScopedLock($scope, $key)
    {
        $scope = preg_replace('/[^a-z0-9_-]+/i', '-', (string) $scope);
        $path = self::dir() . '/guard-' . trim($scope, '-') . '-'
            . substr(hash('sha256', (string) $key), 0, 16) . '.lock';
        $fp = self::openShared($path);
        if ($fp === false) return false;
        if (!@flock($fp, LOCK_EX)) {
            @fclose($fp);
            return false;
        }
        return $fp;
    }

    static public function releaseScopedLock($fp)
    {
        if (!is_resource($fp)) return;
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }

    /**
     * The stored document, and whether it could actually be READ.
     *
     * @param bool|null $readable out: false only when a document IS there and
     *   could not be read or parsed. An ABSENT document is a readable answer --
     *   "nothing has been stored yet" is a fact, not a failure -- and so is an
     *   empty one written by a previous save().
     *
     * The distinction exists because update() is a read-modify-write over the
     * whole document: handed an empty array for a document it merely failed to
     * read, it wrote back the mutator's single key and destroyed every other
     * one -- announce cooldowns, the crawl queue, the miss backoff, the
     * per-hash claims. Callers that only READ can keep ignoring the flag; the
     * safe direction for them is already "nothing cached, fetch again".
     */
    static public function load($name, &$readable = null)
    {
        $path = self::dir() . '/' . $name . '.json';
        $raw = @file_get_contents($path);
        if ($raw === false) {
            $readable = !@file_exists($path);
            return array();
        }
        $state = @json_decode($raw, true);
        // Every document this class writes is valid JSON produced by
        // json_encode, so bytes that do not decode are a truncated or
        // corrupted write, never a legitimate state.
        $readable = is_array($state);
        return is_array($state) ? $state : array();
    }

    // @return bool -- whether the document on disk now holds $state. Callers
    // that act on the strength of a write having landed must consult it.
    static public function save($name, $state)
    {
        return self::replace(self::dir(), $name, $state);
    }

    // Complete-file replacement, following the trafic precedent named in the
    // header: write a sibling temp file, rename it over the target, then open
    // the result up to the profile mask -- tempnam() creates 0600 files owned
    // by whichever OS user wrote first, and the scheduler's rTorrent user and
    // the web server's are routinely different, so without the chmod the
    // second user of the pair silently loses the document. The write is
    // skipped when json_encode() cannot represent the state: a stale document
    // beats a truncated one. This is also what keeps the lock-free load()
    // safe: a reader always sees a complete old or complete new document,
    // and a writer killed mid-write leaves the old one intact.
    //
    // @return bool -- true only once the rename has published the document.
    static private function replace($dir, $name, $state)
    {
        global $profileMask;
        $json = json_encode($state);
        if ($json === false) return false;
        $tmp = @tempnam($dir, $name);
        if ($tmp === false) return false;
        if (@file_put_contents($tmp, $json) === false) {
            @unlink($tmp);
            return false;
        }
        // The mode is set on the TEMP file, before the rename publishes it.
        // Doing it afterwards left the live document at tempnam's 0600 for the
        // window in between, and a second OS user reading in that window gets
        // nothing -- which load() cannot tell apart from "no document at all".
        // A failure here still publishes: the data matters more than the mode,
        // and losing it would be the worse outcome. But it is no longer
        // silent.
        if (!@chmod($tmp, (isset($profileMask) ? $profileMask : 0777) & 0666)
            && class_exists('ruTrackerChecker'))
            ruTrackerChecker::logDebug('state: could not open ' . $name . '.json to the profile mask;'
                . ' another OS user may not be able to read it');
        if (!@rename($tmp, $dir . '/' . $name . '.json')) {
            @unlink($tmp);
            return false;
        }
        return true;
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
    // Removes a document (and its lock) outright -- for per-forum dumps
    // whose retention has lapsed, where an empty-but-present file would just
    // accumulate. Safe against a concurrent update(): its rename would
    // simply recreate the file, which is the fresher fact anyway.
    static public function drop($name)
    {
        $dir = self::dir();
        @unlink($dir . '/' . $name . '.json');
        @unlink($dir . '/' . $name . '.lock');
    }

    // Atomically promotes a staged document to the target name.
    static public function promote($stagedName, $targetName)
    {
        $dir = self::dir();
        $source = $dir . '/' . $stagedName . '.json';
        $target = $dir . '/' . $targetName . '.json';
        if (!is_file($source)) return false;
        $renamed = @rename($source, $target);
        if ($renamed) {
            global $profileMask;
            $mask = (isset($profileMask) ? $profileMask : 0777) & 0666;
            @chmod($target, $mask);
        }
        return $renamed;
    }

    // @return bool -- whether the mutated state reached the disk. A caller
    // whose next step assumes the write landed (the announce budget spends a
    // slot this way) has to fail closed on false rather than carry on.
    static public function update($name, $mutator)
    {
        global $profileMask;
        $dir = self::dir();
        // The lock lives BESIDE the document, never on it: the write below
        // replaces the document wholesale by rename, and a lock taken on the
        // replaced inode would let the next writer base its mutation on a
        // document the previous writer had already superseded. The lock file
        // itself is never renamed, so every update() for one $name contends
        // on the same inode; it is opened up to the profile mask for the
        // same cross-user reason replace() documents.
        $lock = $dir . '/' . $name . '.lock';
        $fp = self::openShared($lock);
        if ($fp === false) {
            if (class_exists('ruTrackerChecker'))
                ruTrackerChecker::logDebug('state: cannot open ' . $lock . ', the ' . $name . ' update is lost');
            return false;
        }

        try {
            if (!flock($fp, LOCK_EX)) return false;
            try {
                $readable = true;
                $current = self::load($name, $readable);
                // Fail closed. Rebuilding the document from an empty array is
                // indistinguishable from a legitimate first write, and it
                // silently discards everything the file held.
                if (!$readable) {
                    if (class_exists('ruTrackerChecker'))
                        ruTrackerChecker::logDebug('state: the ' . $name . ' document is present but could'
                            . ' not be read; the update is abandoned rather than written over it');
                    return false;
                }
                $state = call_user_func($mutator, $current);
                return self::replace($dir, $name, $state);
            } finally {
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }
}
