<?php

/**
 * Tests for plugins/rutracker_check/state.php.
 *
 * RuTrackerState::update() is the fix for the lost-update risk: three
 * writers (the hourly update.php, its detached forumcrawl.php, and a
 * detached batch_check.php per manual "check" click) all read-modify-write
 * the same JSON documents, with overlapping lifetimes -- forumindex.php's
 * fetchDump() alone holds a 30-second HTTP fetch between its read and its
 * write. update() must apply its callable to whatever is CURRENTLY on disk
 * at write time, not to a snapshot obtained earlier, so a concurrent
 * writer's work can never be silently erased.
 */

require_once(__DIR__ . '/TestLib.php');
// The real FileUtil, not a stub: state.php creates its directory through
// FileUtil::makeDirectory(), whose umask(0) wrapper is exactly what makes the
// requested mode take effect for the second OS user of a split
// scheduler/web-server install. A stub here would test nothing about that.
$testLibPrevCwd = getcwd();
chdir(testFindRepoRoot() . '/php/utility');
require_once(testFindRepoRoot() . '/php/utility/fileutil.php');
chdir($testLibPrevCwd);
require_once(testFindRepoRoot() . '/plugins/rutracker_check/state.php');

$suite = new StrictTestSuite();

$suite->test('update() creates the file from an empty state when none exists yet', function () {
    strictWithStateDir('chk-state-create', function () {
        RuTrackerState::update('demo', function ($state) {
            strictAssertSame(array(), $state, 'callable sees an empty array for a file that does not exist yet');
            $state['a'] = 1;
            return $state;
        });
        strictAssertSame(array('a' => 1), RuTrackerState::load('demo'), 'the mutated state was persisted');
    });
});

$suite->test('update() applies the callable on top of the CURRENT on-disk content, not a snapshot taken earlier', function () {
    strictWithStateDir('chk-state-update', function () {
        RuTrackerState::save('demo', array('a' => 1));

        // Simulate the exact shape of the bug: a caller obtains state (as
        // fetchDump() used to, via a plain load() before its HTTP fetch)...
        $obtained = RuTrackerState::load('demo');

        // ...then, before that caller writes anything back, a second writer
        // modifies the file on disk (e.g. forumcrawl.php recording a sweep
        // while a dump fetch is still in flight).
        RuTrackerState::save('demo', array_merge($obtained, array('b' => 2)));

        // The first caller now finally updates. A plain load()+save() using
        // $obtained would overwrite 'b' with the stale snapshot it read
        // before the concurrent write. update() must not: it re-reads
        // whatever is on disk right now.
        RuTrackerState::update('demo', function ($state) {
            $state['c'] = 3;
            return $state;
        });

        strictAssertSame(array('a' => 1, 'b' => 2, 'c' => 3), RuTrackerState::load('demo'),
            'the concurrent writer\'s "b" survives: update() read current content, not the earlier snapshot');
    });
});

$suite->test('update() persists whatever the callable returns, including removed keys', function () {
    strictWithStateDir('chk-state-remove', function () {
        RuTrackerState::save('demo', array('keep' => 1, 'drop' => 2));
        RuTrackerState::update('demo', function ($state) {
            unset($state['drop']);
            return $state;
        });
        strictAssertSame(array('keep' => 1), RuTrackerState::load('demo'), 'dropped key stays dropped');
    });
});

$suite->test('load() stays a plain read: it never creates or modifies the file', function () {
    strictWithStateDir('chk-state-load', function ($tmp) {
        strictAssertSame(array(), RuTrackerState::load('missing'), 'missing file reads as empty array');
        strictAssertTrue(!is_file($tmp . '/missing.json'), 'load() must not create the file as a side effect');
    });
});

$suite->test('acquireCycleLock() admits one cycle and turns the next one away', function () {
    strictWithStateDir('chk-state-cyclelock', function () {
        $first = RuTrackerState::acquireCycleLock();
        strictAssertTrue(is_resource($first), 'the first cycle takes the lock');
        strictAssertSame(false, RuTrackerState::acquireCycleLock(),
            'a second cycle is turned away while the first still holds it');

        // Closing the handle is what a finishing process does implicitly, and
        // it is the whole reason a killed cycle cannot wedge the next one.
        fclose($first);
        $third = RuTrackerState::acquireCycleLock();
        strictAssertTrue(is_resource($third), 'the lock is free again once the holder lets go');
        fclose($third);
    });
});

$suite->test('a scoped guard serialises two processes writing the same logical resource', function () {
    $tmp = sys_get_temp_dir() . '/chk-state-scoped-lock-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);
    $child = $tmp . '/waiter.php';

    try {
        $first = RuTrackerState::acquireScopedLock('forum-map', 'HASH');
        strictAssertTrue(is_resource($first), 'the first mapping writer owns the guard');
        file_put_contents($child, '<?php
            chdir(' . var_export(testFindRepoRoot() . '/php/utility', true) . ');
            require ' . var_export(testFindRepoRoot() . '/php/utility/fileutil.php', true) . ';
            require ' . var_export(testFindRepoRoot() . '/plugins/rutracker_check/state.php', true) . ';
            $dir = new ReflectionProperty("RuTrackerState", "dir");
            $dir->setAccessible(true);
            $dir->setValue(null, ' . var_export($tmp, true) . ');
            $lock = RuTrackerState::acquireScopedLock("forum-map", "HASH");
            file_put_contents(' . var_export($tmp . '/second-entered', true) . ', "1");
            RuTrackerState::releaseScopedLock($lock);
        ');
        $spec = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
        $process = proc_open(PHP_BINARY . ' ' . escapeshellarg($child), $spec, $pipes);
        strictAssertTrue(is_resource($process), 'the competing writer launched');
        usleep(200000);
        strictAssertTrue(!file_exists($tmp . '/second-entered'),
            'the second writer cannot pass while the first still owns the guard');

        RuTrackerState::releaseScopedLock($first);
        $deadline = microtime(true) + 3;
        while (!file_exists($tmp . '/second-entered') && microtime(true) < $deadline) usleep(10000);
        foreach ($pipes as $pipe) {
            stream_get_contents($pipe);
            fclose($pipe);
        }
        strictAssertSame(0, proc_close($process), 'the competing writer exited cleanly');
        strictAssertTrue(file_exists($tmp . '/second-entered'),
            'the second writer enters immediately after the guard is released');
    } finally {
        if (isset($first) && is_resource($first)) RuTrackerState::releaseScopedLock($first);
        strictRemoveTree($tmp);
        strictSetPrivateStatic('RuTrackerState', 'dir', null);
    }
});


$suite->test('a state json_encode() cannot represent keeps the old document instead of truncating it', function () {
    strictWithStateDir('chk-state-badjson', function () {
        RuTrackerState::save('demo', array('a' => 1));
        // Invalid UTF-8 makes json_encode() return false; the write must be
        // refused wholesale -- a stale document beats a truncated one.
        RuTrackerState::update('demo', function ($state) {
            $state['bad'] = "\xB0";
            return $state;
        });
        strictAssertSame(array('a' => 1), RuTrackerState::load('demo'),
            'the previous document survives an unencodable update');
        RuTrackerState::save('demo', array('bad' => "\xB0"));
        strictAssertSame(array('a' => 1), RuTrackerState::load('demo'),
            'and an unencodable save');
    });
});

$suite->test('update() replaces the document whole: no reader can ever see it empty or half-written', function () {
    strictWithStateDir('chk-state-whole', function ($tmp) {
        RuTrackerState::save('demo', array('a' => 1));
        // load() takes no lock by design, so the only thing that keeps a
        // concurrent reader safe is the document being replaced by rename
        // rather than rewritten in place: in-place rewriting exposes an
        // empty/partial file between the truncate and the write, and a
        // writer killed there truncates the document for good.
        clearstatcache();
        $before = fileinode($tmp . '/demo.json');
        RuTrackerState::update('demo', function ($state) use ($tmp) {
            $raw = file_get_contents($tmp . '/demo.json');
            strictAssertSame(array('a' => 1), json_decode($raw, true),
                'mid-update, a lock-free reader still gets the complete old document');
            $state['b'] = 2;
            return $state;
        });
        strictAssertSame(array('a' => 1, 'b' => 2), RuTrackerState::load('demo'), 'the new document lands whole');
        clearstatcache();
        strictAssertTrue(fileinode($tmp . '/demo.json') !== $before,
            'the document was replaced by rename, not rewritten in place');
        // ... which is also why the write lock must live BESIDE the document:
        // a lock on the replaced inode would serialise nothing.
        strictAssertTrue(is_file($tmp . '/demo.lock'), 'the lock file sits beside the document');
    });
});


$suite->test('update() really excludes a concurrent writer: two processes never lose an increment', function () {
    $tmp = sys_get_temp_dir() . '/chk-state-mutex-' . getmypid();
    strictRemoveTree($tmp);
    mkdir($tmp, 0777, true);

    // Everything above exercises update() single-process, which re-reads the
    // file but would also pass with the flock deleted outright. Only two
    // genuinely concurrent writers can pin the lock itself: without it the
    // read-modify-write interleaves and increments vanish.
    //
    // And "two processes" is not the same as "at the same time". Launched and
    // left to it, the first can finish all its rounds before the second is
    // even through PHP's startup, and then nothing ever interleaves and the
    // case passes with no lock at all -- proving only that a process can count
    // to sixty. So both children spin on a barrier file and start together.
    $rounds = 60;
    $child = $tmp . '/child.php';
    file_put_contents($child, '<?php
        chdir(' . var_export(testFindRepoRoot() . '/php/utility', true) . ');
        require ' . var_export(testFindRepoRoot() . '/php/utility/fileutil.php', true) . ';
        require ' . var_export(testFindRepoRoot() . '/plugins/rutracker_check/state.php', true) . ';
        $dir = new ReflectionProperty("RuTrackerState", "dir");
        $dir->setAccessible(true);
        $dir->setValue(null, ' . var_export($tmp, true) . ');
        $barrier = ' . var_export($tmp . '/go', true) . ';
        $deadline = microtime(true) + 10;
        while (!file_exists($barrier) && microtime(true) < $deadline) usleep(200);
        for ($i = 0; $i < ' . $rounds . '; $i++)
            RuTrackerState::update("counter", function ($state) {
                $state["n"] = ($state["n"] ?? 0) + 1;
                return $state;
            });
    ');

    try {
        $spec = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
        $a = proc_open(PHP_BINARY . ' ' . escapeshellarg($child), $spec, $pipesA);
        $b = proc_open(PHP_BINARY . ' ' . escapeshellarg($child), $spec, $pipesB);
        strictAssertTrue(is_resource($a) && is_resource($b), 'both writers launched');
        // Both are up and spinning on the barrier; release them together.
        usleep(300000);
        file_put_contents($tmp . '/go', '1');
        foreach (array($pipesA, $pipesB) as $pipes) {
            stream_get_contents($pipes[1]); fclose($pipes[1]);
            stream_get_contents($pipes[2]); fclose($pipes[2]);
        }
        strictAssertSame(0, proc_close($a), 'writer A exited cleanly');
        strictAssertSame(0, proc_close($b), 'writer B exited cleanly');

        strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);
        strictAssertSame(2 * $rounds, RuTrackerState::load('counter')['n'],
            'every increment survived: the side lock serialised the writers');
    } finally {
        strictRemoveTree($tmp);
        strictSetPrivateStatic('RuTrackerState', 'dir', null);
    }
});

$suite->test('the state directory is created past the umask, so a second OS user can write in it', function () {
    $tmp = sys_get_temp_dir() . '/chk-state-mode-' . getmypid() . '/rutracker_check';
    strictRemoveTree(dirname($tmp));
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);
    $GLOBALS['profileMask'] = 0777;
    $oldUmask = umask(022);   // the usual default, and what silently narrowed it

    try {
        RuTrackerState::save('demo', array('a' => 1));
        clearstatcache();
        strictAssertTrue(is_dir($tmp), 'the directory was created');
        // 0755 is what a plain mkdir(0777) leaves under umask 022, and a
        // directory nobody else can write is one nobody else can lock or
        // rename into -- the per-file chmod would then be pointless.
        strictAssertSame('0777', substr(sprintf('%o', fileperms($tmp)), -4),
            'the profile mask survives the umask');
        strictAssertSame('0666', substr(sprintf('%o', fileperms($tmp . '/demo.json')), -4),
            'and the document itself is writable by both users');
    } finally {
        umask($oldUmask);
        unset($GLOBALS['profileMask']);
        strictRemoveTree(dirname($tmp));
        strictSetPrivateStatic('RuTrackerState', 'dir', null);
    }
});

$suite->test('an existing state directory is reopened to the profile mask', function () {
    $tmp = sys_get_temp_dir() . '/chk-state-existing-mode-' . getmypid() . '/rutracker_check';
    strictRemoveTree(dirname($tmp));
    mkdir($tmp, 0700, true);
    chmod($tmp, 0700);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);
    $GLOBALS['profileMask'] = 0777;

    try {
        strictAssertSame(true, RuTrackerState::save('demo', array('a' => 1)),
            'the current user can still write through the old restrictive mode');
        clearstatcache();
        strictAssertSame('0777', substr(sprintf('%o', fileperms($tmp)), -4),
            'entering an existing state directory repairs it for the other OS user');
    } finally {
        unset($GLOBALS['profileMask']);
        strictRemoveTree(dirname($tmp));
        strictSetPrivateStatic('RuTrackerState', 'dir', null);
    }
});

$suite->test('the cycle lock is created writable by both OS users, or it guards nothing', function () {
    $tmp = sys_get_temp_dir() . '/chk-state-cyclemode-' . getmypid() . '/rutracker_check';
    strictRemoveTree(dirname($tmp));
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);
    $GLOBALS['profileMask'] = 0777;
    $oldUmask = umask(022);

    try {
        $fp = RuTrackerState::acquireCycleLock();
        strictAssertTrue(is_resource($fp), 'the first cycle takes the lock');
        $found = glob($tmp . '/cycle-*.lock');
        strictAssertSame(1, count($found), 'exactly one lock file, keyed by the daemon');
        clearstatcache();
        // acquireCycleLock() fails OPEN by design -- a guard that cannot be
        // built must not stop the cycle -- so a lock file the other OS user
        // cannot open would silently let both cycles run, which is the one
        // outcome the lock exists to prevent.
        strictAssertSame('0666', substr(sprintf('%o', fileperms($found[0])), -4),
            'and it is writable by the other user of a split install');
        fclose($fp);
    } finally {
        umask($oldUmask);
        unset($GLOBALS['profileMask']);
        strictRemoveTree(dirname($tmp));
        strictSetPrivateStatic('RuTrackerState', 'dir', null);
    }
});

// save() and update() used to return void on every failure path, so a caller
// could not tell a stored write from a lost one -- and the announce budget,
// which spends its slot inside update(), read a lost write as a spent slot
// and let every process probe on the same untouched allowance.
$suite->test('a write that did not land says so', function () {
    $tmp = sys_get_temp_dir() . '/chk-state-report-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
        strictAssertSame(true, RuTrackerState::save('doc', array('a' => 1)),
            'a write that landed reports true');
        strictAssertSame(true, RuTrackerState::update('doc', function ($state) {
            $state['b'] = 2;
            return $state;
        }), 'and so does a mutation that landed');
        strictAssertSame(array('a' => 1, 'b' => 2), RuTrackerState::load('doc'), 'both are on disk');

        // A state json_encode() cannot represent: the lock is taken, the
        // mutator runs, and the write is skipped -- a stale document beats a
        // truncated one -- so the answer must be false, not silence.
        strictAssertSame(false, RuTrackerState::update('doc', function ($state) {
            $state['c'] = NAN;
            return $state;
        }), 'a mutation that could not be encoded reports false');
        strictAssertSame(array('a' => 1, 'b' => 2), RuTrackerState::load('doc'),
            'and leaves the previous document intact, which is the point');

        // A directory that cannot exist: even root gets ENOTDIR under a file.
        $blocked = $tmp . '/not-a-directory';
        file_put_contents($blocked, 'x');
        strictSetPrivateStatic('RuTrackerState', 'dir', $blocked . '/store');
        strictAssertSame(false, RuTrackerState::save('doc', array('a' => 1)),
            'a write with nowhere to go reports false');
        strictAssertSame(false, RuTrackerState::update('doc', function ($state) { return $state; }),
            'and so does a mutation with nowhere to go');
    } finally {
        strictSetPrivateStatic('RuTrackerState', 'dir', null);
        strictRemoveTree($tmp);
    }
});

$suite->test('a document that cannot be read is never rebuilt from nothing', function () {
    // update() is a read-modify-write over the WHOLE document. load() answered
    // the same empty array for "no document yet" and for "a document is there
    // but could not be read or parsed", so the second case handed the mutator
    // an empty state and the write that followed replaced every key the file
    // held -- every announce cooldown, the crawl queue, the miss backoff, the
    // per-hash claims -- with the one key the mutator happened to add.
    strictWithStateDir('chk-state-unreadable', function ($tmp) {
        $path = $tmp . '/counter.json';
        file_put_contents($path, 'this is not json');

        $ran = false;
        $ok = RuTrackerState::update('counter', function ($state) use (&$ran) {
            $ran = true;
            $state['fresh'] = 1;
            return $state;
        });

        strictAssertSame(false, $ok, 'the update reports that it did not happen');
        strictAssertSame(false, $ran, 'and the mutator is never handed a state that was not read');
        strictAssertSame('this is not json', file_get_contents($path),
            'the unreadable document is left exactly as it was, not replaced by one key');

        // An ABSENT document is a different fact: "nothing stored yet" is a
        // real answer, and building on it is exactly what update() is for.
        $ok = RuTrackerState::update('brandnew', function ($state) {
            $state['fresh'] = 1;
            return $state;
        });
        strictAssertSame(true, $ok, 'a document that does not exist yet is still created');
        strictAssertSame(array('fresh' => 1), RuTrackerState::load('brandnew'), 'and holds what was written');
    });
});

$suite->test('load() says whether it could read, so callers can tell absent from unreadable', function () {
    strictWithStateDir('chk-state-readable', function ($tmp) {
        $readable = false;
        strictAssertSame(array(), RuTrackerState::load('missing', $readable), 'nothing stored yet');
        strictAssertSame(true, $readable, 'an absent document is a readable answer: there is nothing');

        file_put_contents($tmp . '/broken.json', '{"half":');
        $readable = true;
        strictAssertSame(array(), RuTrackerState::load('broken', $readable), 'nothing usable came back');
        strictAssertSame(false, $readable, 'and the caller is told the document is there but unreadable');

        RuTrackerState::save('good', array('k' => 'v'));
        $readable = false;
        strictAssertSame(array('k' => 'v'), RuTrackerState::load('good', $readable), 'a real document');
        strictAssertSame(true, $readable, 'read without trouble');
    });
});

exit($suite->run());
