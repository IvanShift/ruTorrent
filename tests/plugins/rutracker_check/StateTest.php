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
require_once(testFindRepoRoot() . '/plugins/rutracker_check/state.php');

$suite = new StrictTestSuite();

$suite->test('update() creates the file from an empty state when none exists yet', function () {
    $tmp = sys_get_temp_dir() . '/chk-state-create-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
        RuTrackerState::update('demo', function ($state) {
            strictAssertSame(array(), $state, 'callable sees an empty array for a file that does not exist yet');
            $state['a'] = 1;
            return $state;
        });
        strictAssertSame(array('a' => 1), RuTrackerState::load('demo'), 'the mutated state was persisted');
    } finally {
        strictRemoveTree($tmp);
    }
});

$suite->test('update() applies the callable on top of the CURRENT on-disk content, not a snapshot taken earlier', function () {
    $tmp = sys_get_temp_dir() . '/chk-state-update-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
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
    } finally {
        strictRemoveTree($tmp);
    }
});

$suite->test('update() persists whatever the callable returns, including removed keys', function () {
    $tmp = sys_get_temp_dir() . '/chk-state-remove-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
        RuTrackerState::save('demo', array('keep' => 1, 'drop' => 2));
        RuTrackerState::update('demo', function ($state) {
            unset($state['drop']);
            return $state;
        });
        strictAssertSame(array('keep' => 1), RuTrackerState::load('demo'), 'dropped key stays dropped');
    } finally {
        strictRemoveTree($tmp);
    }
});

$suite->test('load() stays a plain read: it never creates or modifies the file', function () {
    $tmp = sys_get_temp_dir() . '/chk-state-load-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
        strictAssertSame(array(), RuTrackerState::load('missing'), 'missing file reads as empty array');
        strictAssertTrue(!is_file($tmp . '/missing.json'), 'load() must not create the file as a side effect');
    } finally {
        strictRemoveTree($tmp);
    }
});

$suite->test('acquireCycleLock() admits one cycle and turns the next one away', function () {
    $tmp = sys_get_temp_dir() . '/chk-state-cyclelock-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
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
    } finally {
        strictRemoveTree($tmp);
    }
});


$suite->test('a state json_encode() cannot represent keeps the old document instead of truncating it', function () {
    $tmp = sys_get_temp_dir() . '/chk-state-badjson-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
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
    } finally {
        strictRemoveTree($tmp);
    }
});

$suite->test('update() replaces the document whole: no reader can ever see it empty or half-written', function () {
    $tmp = sys_get_temp_dir() . '/chk-state-whole-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
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
    } finally {
        strictRemoveTree($tmp);
    }
});


$suite->test('update() really excludes a concurrent writer: two processes never lose an increment', function () {
    $tmp = sys_get_temp_dir() . '/chk-state-mutex-' . getmypid();
    strictRemoveTree($tmp);
    mkdir($tmp, 0777, true);

    // Everything above exercises update() single-process, which re-reads the
    // file but would also pass with the flock deleted outright. Only two
    // genuinely concurrent writers can pin the lock itself: without it the
    // read-modify-write interleaves and increments vanish.
    $rounds = 60;
    $child = $tmp . '/child.php';
    file_put_contents($child, '<?php
        require ' . var_export(testFindRepoRoot() . '/plugins/rutracker_check/state.php', true) . ';
        $dir = new ReflectionProperty("RuTrackerState", "dir");
        $dir->setAccessible(true);
        $dir->setValue(null, ' . var_export($tmp, true) . ');
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
    }
});

exit($suite->run());
