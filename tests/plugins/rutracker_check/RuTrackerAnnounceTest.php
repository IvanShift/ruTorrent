<?php

define('TESTLIB_HANDLER_STUBS', 1);
require_once(__DIR__ . '/TestLib.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/announce.php');

$suite = new StrictTestSuite();
$hash = 'C6EE8D83000000000000000000000000000000AB';

$suite->test('buildUrl strips passkey and carries the full identity', function () use ($hash) {
    $url = RuTrackerAnnounce::buildUrl('http://bt.t-ru.org/ann?pk=deadbeef', $hash, '-RC0001-abcdef123456', 63981, 'k1');
    strictAssertTrue(strpos($url, 'pk=') === false, 'passkey must be stripped');
    strictAssertTrue(strpos($url, 'http://bt.t-ru.org/ann?') === 0, 'base preserved');
    strictAssertTrue(strpos($url, 'info_hash=' . rawurlencode(hex2bin($hash))) !== false, 'binary info_hash');
    foreach (array('peer_id=-RC0001-abcdef123456', 'port=63981', 'uploaded=0', 'downloaded=0',
                   'left=0', 'compact=1', 'numwant=0', 'event=stopped', 'key=k1') as $chunk)
        strictAssertTrue(strpos($url, $chunk) !== false, "missing {$chunk}");
});

$suite->test('buildUrl rejects a non-hex hash', function () {
    strictAssertSame(null, RuTrackerAnnounce::buildUrl('http://bt.t-ru.org/ann', 'not-a-hash', '-RC0001-x', 63981, 'k'), 'null on bad hash');
});

$suite->test('makePeerId is 20 bytes with the plugin prefix', function () {
    $id = RuTrackerAnnounce::makePeerId();
    strictAssertSame(20, strlen($id), 'length');
    strictAssertSame('-RC0001-', substr($id, 0, 8), 'prefix');
    strictAssertTrue($id !== RuTrackerAnnounce::makePeerId(), 'random tail');
});

$suite->test('classify: dict with failure reason is unregistered', function () {
    strictAssertSame('unregistered',
        RuTrackerAnnounce::classify(200, 'd14:failure reason25:unregistered torrent passe'), 'failure reason');
});

$suite->test('classify: dict without failure reason is registered', function () {
    strictAssertSame('registered',
        RuTrackerAnnounce::classify(200, 'd8:intervali3021e5:peers6:' . "\x01\x02\x03\x04\x05\x06" . 'e'), 'clean dict');
});

$suite->test('classify: everything else is uncertain', function () {
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(403, ''), '403');
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, '<html>challenge</html>'), 'html');
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, ''), 'empty');
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, 'le'), 'bencode non-dict');
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, 'd3:fooe'), 'broken bencode');
});

$suite->test('classify: expectedFailure narrows the unregistered verdict', function () {
    $body = 'd14:failure reason25:unregistered torrent passe';
    strictAssertSame('unregistered', RuTrackerAnnounce::classify(200, $body, 'unregistered torrent pass'), 'exact match');
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, $body, 'different text'), 'mismatch is uncertain');
});

$suite->test('classify: the measured RuTracker failure reason confirms deregistration; a different reason stays inconclusive', function () {
    $measured = RuTrackerAnnounce::UNREGISTERED_FAILURE_REASON;
    $measuredBody = 'd14:failure reason' . strlen($measured) . ':' . $measured . 'e';
    strictAssertSame('unregistered',
        RuTrackerAnnounce::classify(200, $measuredBody, RuTrackerAnnounce::UNREGISTERED_FAILURE_REASON),
        'the exact 2026-08-07 measured text confirms deregistration');

    // A realistic rate-limit notice: superficially the same shape (a bencode
    // dict with a non-empty failure reason) but not the measured text -- must
    // not be accepted as proof of deregistration.
    $rateLimited = 'Too many requests, slow down';
    $rateLimitedBody = 'd14:failure reason' . strlen($rateLimited) . ':' . $rateLimited . 'e';
    strictAssertSame('uncertain',
        RuTrackerAnnounce::classify(200, $rateLimitedBody, RuTrackerAnnounce::UNREGISTERED_FAILURE_REASON),
        'a different failure reason (e.g. rate limiting) stays inconclusive, not unregistered');
});

// $window is generous (1h) in every test below that is not itself testing
// window expiry, so the windowed cap never resets mid-test by accident.
const RAT_WINDOW = 3600;

$suite->test('announce budget: cap, 403 cooldown doubling and success reset', function () {
    $tmp = sys_get_temp_dir() . '/chk-announce-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);
    RuTrackerAnnounce::resetCycle();

    try {
        for ($i = 0; $i < 10; $i++) {
            strictAssertTrue(RuTrackerAnnounce::allowProbe('bt.t-ru.org', 1000 + $i, 10, RAT_WINDOW), "probe {$i} allowed");
            RuTrackerAnnounce::recordProbe('bt.t-ru.org', 1000 + $i, false, RAT_WINDOW);
        }
        strictAssertTrue(!RuTrackerAnnounce::allowProbe('bt.t-ru.org', 1010, 10, RAT_WINDOW), 'cap reached');
        strictAssertTrue(RuTrackerAnnounce::allowProbe('bt2.t-ru.org', 1010, 10, RAT_WINDOW), 'cap is per host');

        RuTrackerAnnounce::recordProbe('bt2.t-ru.org', 1010, true, RAT_WINDOW);           // 403 -> cooldown 3600
        strictAssertTrue(!RuTrackerAnnounce::allowProbe('bt2.t-ru.org', 1011, 10, RAT_WINDOW), 'cooldown blocks');
        RuTrackerAnnounce::resetCycle();
        strictAssertTrue(!RuTrackerAnnounce::allowProbe('bt2.t-ru.org', 2000, 10, RAT_WINDOW), 'cooldown survives cycles (persistent)');
        strictAssertTrue(RuTrackerAnnounce::allowProbe('bt2.t-ru.org', 1011 + 3601, 10, RAT_WINDOW), 'cooldown expires');
        RuTrackerAnnounce::recordProbe('bt2.t-ru.org', 1011 + 3601, true, RAT_WINDOW);    // second 403 -> 7200
        strictAssertTrue(!RuTrackerAnnounce::allowProbe('bt2.t-ru.org', 1011 + 3601 + 7000, 10, RAT_WINDOW), 'doubled cooldown');
    } finally {
        strictRemoveTree($tmp);
    }
});

// allowProbe() answers the decision; probeDecision() answers WHY, so a skipped
// layer 2 can name the budget that stopped it instead of leaving the log with a
// bare "denied". The two must never disagree.
$suite->test('probeDecision names the budget that denied a probe, and agrees with allowProbe', function () {
    $tmp = sys_get_temp_dir() . '/chk-announce-reason-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
        strictAssertSame('allow', RuTrackerAnnounce::probeDecision('bt8.t-ru.org', 1000, 2, RAT_WINDOW),
            'a fresh host may be probed');

        RuTrackerAnnounce::recordProbe('bt8.t-ru.org', 1000, false, RAT_WINDOW);
        RuTrackerAnnounce::recordProbe('bt8.t-ru.org', 1001, false, RAT_WINDOW);
        strictAssertSame('cap', RuTrackerAnnounce::probeDecision('bt8.t-ru.org', 1002, 2, RAT_WINDOW),
            'a spent window budget is reported as the cap');

        // A 403 installs the cooldown, which outranks the cap: a host that is
        // both capped and cooling down must report the cooldown, since that is
        // the one that survives the window rolling over.
        RuTrackerAnnounce::recordProbe('bt9.t-ru.org', 1000, true, RAT_WINDOW);
        strictAssertSame('cooldown', RuTrackerAnnounce::probeDecision('bt9.t-ru.org', 1001, 10, RAT_WINDOW),
            'a running 403 cooldown is reported as the cooldown');

        foreach (array('bt8.t-ru.org', 'bt9.t-ru.org', 'bt10.t-ru.org') as $host)
            foreach (array(0, 2, 10) as $cap)
                strictAssertSame(
                    RuTrackerAnnounce::probeDecision($host, 1002, $cap, RAT_WINDOW) === 'allow',
                    RuTrackerAnnounce::allowProbe($host, 1002, $cap, RAT_WINDOW),
                    "allowProbe and probeDecision agree for {$host} at cap {$cap}"
                );
    } finally {
        strictRemoveTree($tmp);
    }
});

$suite->test('announce budget: a successful probe clears the remembered cooldown length', function () {
    $tmp = sys_get_temp_dir() . '/chk-announce-reset-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);
    RuTrackerAnnounce::resetCycle();

    try {
        RuTrackerAnnounce::recordProbe('bt3.t-ru.org', 1000, true, RAT_WINDOW);           // first 403 -> cooldown 3600
        strictAssertTrue(!RuTrackerAnnounce::allowProbe('bt3.t-ru.org', 1000 + 3600, 10, RAT_WINDOW), 'still inside first cooldown');

        RuTrackerAnnounce::recordProbe('bt3.t-ru.org', 1000 + 3601, false, RAT_WINDOW);   // success clears cooldown_length

        RuTrackerAnnounce::recordProbe('bt3.t-ru.org', 2000, true, RAT_WINDOW);           // next 403 must restart at 3600, not double to 7200
        strictAssertTrue(
            RuTrackerAnnounce::allowProbe('bt3.t-ru.org', 2000 + 3601, 10, RAT_WINDOW),
            'cooldown restarted at 3600 instead of doubling from the old length'
        );
    } finally {
        strictRemoveTree($tmp);
    }
});

// --- Risk B: the per-cycle announce cap must survive across processes -----

$suite->test('announce budget: the windowed cap survives across simulated processes (resetCycle() no longer voids it)', function () {
    $tmp = sys_get_temp_dir() . '/chk-announce-window-durable-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
        // Ten manual "check" clicks, each its own process in production:
        // every click spawns a fresh batch_check.php, so nothing in memory
        // survives between them. Model that here by calling resetCycle()
        // between every probe -- the persisted windowed counter must still
        // hold the cap regardless.
        for ($i = 0; $i < 5; $i++) {
            RuTrackerAnnounce::resetCycle();
            strictAssertTrue(RuTrackerAnnounce::allowProbe('bt4.t-ru.org', 1000 + $i, 5, RAT_WINDOW), "click {$i} allowed");
            RuTrackerAnnounce::recordProbe('bt4.t-ru.org', 1000 + $i, false, RAT_WINDOW);
        }
        RuTrackerAnnounce::resetCycle();
        strictAssertTrue(!RuTrackerAnnounce::allowProbe('bt4.t-ru.org', 1005, 5, RAT_WINDOW),
            'cap holds after 5 separate simulated processes, each calling resetCycle() on the way in');
    } finally {
        strictRemoveTree($tmp);
    }
});

$suite->test('announce budget: the window expires after the configured interval and the count restarts', function () {
    $tmp = sys_get_temp_dir() . '/chk-announce-window-expire-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
        $window = 3600;
        for ($i = 0; $i < 5; $i++) {
            RuTrackerAnnounce::recordProbe('bt5.t-ru.org', 1000 + $i, false, $window);
        }
        strictAssertTrue(!RuTrackerAnnounce::allowProbe('bt5.t-ru.org', 1004, 5, $window), 'cap holds inside the window');
        strictAssertTrue(!RuTrackerAnnounce::allowProbe('bt5.t-ru.org', 1000 + $window - 1, 5, $window), 'still capped just before it elapses');
        strictAssertTrue(RuTrackerAnnounce::allowProbe('bt5.t-ru.org', 1000 + $window, 5, $window), 'a fresh budget once the window elapses');

        // The restarted window itself must enforce the cap again, not stay
        // permanently open.
        for ($i = 0; $i < 5; $i++) {
            RuTrackerAnnounce::recordProbe('bt5.t-ru.org', 1000 + $window + $i, false, $window);
        }
        strictAssertTrue(!RuTrackerAnnounce::allowProbe('bt5.t-ru.org', 1000 + $window + 4, 5, $window), 'the restarted window caps again');
    } finally {
        strictRemoveTree($tmp);
    }
});

$suite->test('announce budget: a zero window (disabled scheduler) is floored so the cap still holds', function () {
    $tmp = sys_get_temp_dir() . '/chk-announce-window-floor-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
        // conf.php documents $updateInterval = 0 as "disable the
        // scheduler", but manual batch_check.php clicks still compute the
        // window as $updateInterval * 60 -- i.e. 0. Without a floor every
        // click would open (and instantly close) its own window, buying a
        // fresh cap every time, exactly the bug this fix closes.
        for ($i = 0; $i < 3; $i++) {
            strictAssertTrue(RuTrackerAnnounce::allowProbe('bt6.t-ru.org', 1000, 3, 0), "click {$i} allowed");
            RuTrackerAnnounce::recordProbe('bt6.t-ru.org', 1000, false, 0);
        }
        strictAssertTrue(!RuTrackerAnnounce::allowProbe('bt6.t-ru.org', 1000, 3, 0),
            'three same-instant clicks with window=0 still hit the cap: the floor keeps the window open');
    } finally {
        strictRemoveTree($tmp);
    }
});

$suite->test('announce budget: the windowed cap is independent per host, like the cooldown', function () {
    $tmp = sys_get_temp_dir() . '/chk-announce-window-per-host-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
        for ($i = 0; $i < 3; $i++) RuTrackerAnnounce::recordProbe('bt7a.t-ru.org', 1000 + $i, false, RAT_WINDOW);
        strictAssertTrue(!RuTrackerAnnounce::allowProbe('bt7a.t-ru.org', 1002, 3, RAT_WINDOW), 'host a capped');
        strictAssertTrue(RuTrackerAnnounce::allowProbe('bt7b.t-ru.org', 1002, 3, RAT_WINDOW), 'host b unaffected');
    } finally {
        strictRemoveTree($tmp);
    }
});

exit($suite->run());
