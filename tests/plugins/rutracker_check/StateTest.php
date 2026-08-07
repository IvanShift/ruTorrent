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

exit($suite->run());
