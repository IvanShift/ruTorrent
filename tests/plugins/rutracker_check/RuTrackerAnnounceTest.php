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

$suite->test('classify: valid success variants are registered', function () {
    strictAssertSame('registered',
        RuTrackerAnnounce::classify(200, 'd8:intervali3021e5:peers6:' . "\x01\x02\x03\x04\x05\x06" . 'e'), 'clean dict with peers');
    strictAssertSame('registered',
        RuTrackerAnnounce::classify(200, 'd8:intervali1800e5:peers0:e'), 'numwant=0 clean dict with 0 peers');
    strictAssertSame('registered',
        RuTrackerAnnounce::classify(200,
            'd8:intervali1800e5:peers0:12:min intervali900e8:completei10e10:incompletei5ee'),
        'clean dict with full stats');
    strictAssertSame('registered',
        RuTrackerAnnounce::classify(200,
            'd8:intervali1800e5:peersld2:ip9:127.0.0.14:porti6881eeee'),
        'dictionary-list peers with a string ip and bounded integer port');
    strictAssertSame('registered',
        RuTrackerAnnounce::classify(200, 'd8:intervali0e5:peerslee'),
        'an empty dictionary-list peer response is valid and distinct from an empty dictionary');
    strictAssertSame('registered',
        RuTrackerAnnounce::classify(200, 'd8:intervali1800e5:peers0:7:warning5:helloe'),
        'unknown extension keys remain allowed after the required schema is valid');
    strictAssertSame('registered',
        RuTrackerAnnounce::classify(200, 'd8:intervali1800e5:peers0:1:xi-1ee'),
        'a canonical negative integer remains valid inside an unknown extension');
    strictAssertSame('registered',
        RuTrackerAnnounce::classify(200, 'd8:intervali1800e5:peers0:1:xli0ei-1eee'),
        'canonical integers remain valid inside an unknown extension list');
    strictAssertSame('registered',
        RuTrackerAnnounce::classify(200, 'd8:intervali1800e5:peers0:1:xd1:yi42eee'),
        'canonical integers remain valid inside an unknown extension dictionary');
});

$suite->test('classify: everything else is uncertain', function () {
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(403, ''), '403');
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, '<html>challenge</html>'), 'html');
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, ''), 'empty');
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, 'de'), 'empty bencode dict');
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, 'le'), 'bencode non-dict');
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, 'd3:fooe'), 'broken bencode');
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200,
        'd8:intervali1800e5:peers0:e<html>'),
        'otherwise-valid success dictionary with trailing unparsed html');
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, 'd3:fooi123ee'), 'arbitrary dict without interval');
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, 'd5:hello5:worlde'), 'arbitrary dict without interval 2');
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, 'd8:intervali-1ee'), 'negative interval');
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, 'd8:interval4:1800e'), 'string interval');
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, 'd8:intervall123eee'), 'list interval');
});

$suite->test('classify: malformed positive announce schemas stay uncertain', function () {
    foreach (array(
        'interval without peers' => 'd8:intervali1800ee',
        'peers without interval' => 'd5:peers0:e',
        'integer peers' => 'd8:intervali1800e5:peersi0ee',
        'compact peers not divisible by six' => 'd8:intervali1800e5:peers5:abcdee',
        'empty dictionary is not an empty peer list' => 'd8:intervali1800e5:peersdee',
        'peer list member is not a dictionary' => 'd8:intervali1800e5:peersli6881eee',
        'peer dictionary missing ip' => 'd8:intervali1800e5:peersld4:porti6881eeee',
        'peer dictionary missing port' => 'd8:intervali1800e5:peersld2:ip9:127.0.0.1eee',
        'peer ip is not a string' => 'd8:intervali1800e5:peersld2:ipi1e4:porti6881eeee',
        'peer port is not an integer' => 'd8:intervali1800e5:peersld2:ip9:127.0.0.14:port4:6881eee',
        'peer port is zero' => 'd8:intervali1800e5:peersld2:ip9:127.0.0.14:porti0eeee',
        'peer port exceeds 65535' => 'd8:intervali1800e5:peersld2:ip9:127.0.0.14:porti65536eeee',
        'interval has a leading zero' => 'd8:intervali01800e5:peers0:e',
        'interval is negative zero' => 'd8:intervali-0e5:peers0:e',
        'interval uses a plus sign' => 'd8:intervali+1800e5:peers0:e',
        'interval is a decimal token' => 'd8:intervali1800.0e5:peers0:e',
        'complete is negative' => 'd8:intervali1800e5:peers0:8:completei-1ee',
        'incomplete has a leading zero' => 'd8:intervali1800e5:peers0:10:incompletei01ee',
        'min interval is a string' => 'd8:intervali1800e5:peers0:12:min interval1:0e',
        'unknown keys cannot replace peers' => 'd8:intervali1800e7:warning5:helloe',
    ) as $label => $body) {
        strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, $body), $label);
    }
});

$suite->test('classify: noncanonical integers in unknown extensions invalidate the whole envelope', function () {
    $successPrefix = 'd8:intervali1800e5:peers0:';
    $reason = RuTrackerAnnounce::UNREGISTERED_FAILURE_REASON;
    $failurePrefix = 'd14:failure reason' . strlen($reason) . ':' . $reason;
    foreach (array('i01e', 'i-0e', 'i+1e', 'i1.0e') as $token) {
        foreach (array(
            'direct unknown value' => $successPrefix . '1:x' . $token . 'e',
            'unknown list value' => $successPrefix . '1:xl' . $token . 'ee',
            'unknown dictionary value' => $successPrefix . '1:xd1:y' . $token . 'ee',
        ) as $context => $body) {
            strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, $body),
                $context . ' rejects ' . $token);
        }
        strictAssertSame('uncertain', RuTrackerAnnounce::classify(200,
            $failurePrefix . '1:x' . $token . 'e'),
            'the exact failure reason cannot bless an invalid envelope containing ' . $token);
    }
});

$suite->test('classify: duplicate required announce keys stay uncertain', function () {
    foreach (array(
        'duplicate interval' => 'd8:intervali1800e8:intervali1801e5:peers0:e',
        'duplicate peers' => 'd8:intervali1800e5:peers0:5:peers0:e',
    ) as $label => $body) {
        strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, $body), $label);
    }
});

$suite->test('classify: duplicate optional counters stay uncertain', function () {
    foreach (array(
        'duplicate complete' => 'd8:intervali1800e5:peers0:8:completei1e8:completei2ee',
        'duplicate incomplete' => 'd8:intervali1800e5:peers0:10:incompletei1e10:incompletei2ee',
        'duplicate min interval' => 'd8:intervali1800e5:peers0:12:min intervali1e12:min intervali2ee',
    ) as $label => $body) {
        strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, $body), $label);
    }
});

$suite->test('classify: duplicate failure reason stays uncertain', function () {
    $reason = RuTrackerAnnounce::UNREGISTERED_FAILURE_REASON;
    $body = 'd14:failure reason' . strlen($reason) . ':' . $reason
        . '14:failure reason' . strlen($reason) . ':' . $reason . 'e';
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, $body), 'duplicate failure reason');
});

$suite->test('classify: duplicate peer dictionary keys stay uncertain', function () {
    foreach (array(
        'duplicate peer ip' => 'd8:intervali1800e5:peersld2:ip9:127.0.0.1'
            . '2:ip9:127.0.0.24:porti6881eeee',
        'duplicate peer port' => 'd8:intervali1800e5:peersld2:ip9:127.0.0.1'
            . '4:porti6881e4:porti6882eeee',
    ) as $label => $body) {
        strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, $body), $label);
    }
});

$suite->test('classify: the measured RuTracker failure reason confirms deregistration; a different reason stays inconclusive', function () {
    $measured = RuTrackerAnnounce::UNREGISTERED_FAILURE_REASON;
    $measuredBody = 'd14:failure reason' . strlen($measured) . ':' . $measured . 'e';
    strictAssertSame('unregistered', RuTrackerAnnounce::classify(200, $measuredBody),
        'the exact 2026-08-07 measured text confirms deregistration');

    // A realistic rate-limit notice: superficially the same shape (a bencode
    // dict with a non-empty failure reason) but not the measured text -- must
    // not be accepted as proof of deregistration.
    $rateLimited = 'Too many requests, slow down';
    $rateLimitedBody = 'd14:failure reason' . strlen($rateLimited) . ':' . $rateLimited . 'e';
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, $rateLimitedBody),
        'a different failure reason (e.g. rate limiting) stays inconclusive, not unregistered');

    // The HTTP status stands guard on its own: the same well-formed body that
    // confirms deregistration at 200 proves nothing behind an error page.
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(500, $measuredBody),
        'a 500 with a well-formed body stays inconclusive');
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(403, 'd8:intervali1800ee'),
        'a 403 with a well-formed body stays inconclusive');

    // The body is network-controlled and the decoder recursive: anything far
    // beyond a real announce reply must be refused BEFORE decoding, and the
    // refusal must not depend on the oversized body failing to parse.
    $pad = str_repeat('x', 70000);
    $huge = 'd3:pad' . strlen($pad) . ':' . $pad . '8:intervali1800ee';
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, $huge),
        'an oversized but well-formed dict is refused unread');
});

// $window is generous (1h) in every test below that is not itself testing
// window expiry, so the windowed cap never resets mid-test by accident.
const RAT_WINDOW = 3600;

// The boolean view of probeDecision(), local to these tests. Production does
// NOT decide through it -- reserveProbe() does, because deciding and taking
// must be one locked write -- but many assertions here have to ask the same
// question repeatedly without spending the budget they are measuring, which is
// what the non-consuming reader is for. Both go through one shared rule
// (judge()), so asking through this one still measures what production gets.
function ratAllowProbe($host, $now, $cap, $window)
{
    return RuTrackerAnnounce::probeDecision($host, $now, $cap, $window) === 'allow';
}

// A whole probe as production performs it: take the slot, then record what
// the tracker answered. The cap is deliberately unbounded here -- these tests
// set the budget up, and the ones that exercise the cap assert through
// reserveProbe()/probeDecision() directly.
function ratProbe($host, $now, $status, $window)
{
    RuTrackerAnnounce::reserveProbe($host, $now, PHP_INT_MAX, $window);
    RuTrackerAnnounce::recordOutcome($host, $now, $status);
}

$suite->test('announce budget: cap and 403 cooldown doubling', function () {
    strictWithStateDir('chk-announce', function () {
        for ($i = 0; $i < 10; $i++) {
            strictAssertTrue(ratAllowProbe('bt.t-ru.org', 1000 + $i, 10, RAT_WINDOW), "probe {$i} allowed");
            ratProbe('bt.t-ru.org', 1000 + $i, 200, RAT_WINDOW);
        }
        strictAssertTrue(!ratAllowProbe('bt.t-ru.org', 1010, 10, RAT_WINDOW), 'cap reached');
        strictAssertTrue(ratAllowProbe('bt2.t-ru.org', 1010, 10, RAT_WINDOW), 'cap is per host');

        ratProbe('bt2.t-ru.org', 1010, 403, RAT_WINDOW);           // 403 -> cooldown 3600
        strictAssertTrue(!ratAllowProbe('bt2.t-ru.org', 1011, 10, RAT_WINDOW), 'cooldown blocks');
        strictAssertTrue(!ratAllowProbe('bt2.t-ru.org', 2000, 10, RAT_WINDOW), 'cooldown survives cycles (persistent)');
        strictAssertTrue(ratAllowProbe('bt2.t-ru.org', 1011 + 3601, 10, RAT_WINDOW), 'cooldown expires');
        ratProbe('bt2.t-ru.org', 1011 + 3601, 403, RAT_WINDOW);    // second 403 -> 7200
        strictAssertTrue(!ratAllowProbe('bt2.t-ru.org', 1011 + 3601 + 7000, 10, RAT_WINDOW), 'doubled cooldown');
    });
});

// probeDecision() answers WHY, not just whether, so a skipped layer 2 can name
// the budget that stopped it instead of leaving the log with a bare "denied".
$suite->test('probeDecision names the budget that denied a probe', function () {
    strictWithStateDir('chk-announce-reason', function () {
        strictAssertSame('allow', RuTrackerAnnounce::probeDecision('bt8.t-ru.org', 1000, 2, RAT_WINDOW),
            'a fresh host may be probed');

        ratProbe('bt8.t-ru.org', 1000, 200, RAT_WINDOW);
        ratProbe('bt8.t-ru.org', 1001, 200, RAT_WINDOW);
        strictAssertSame('cap', RuTrackerAnnounce::probeDecision('bt8.t-ru.org', 1002, 2, RAT_WINDOW),
            'a spent window budget is reported as the cap');

        // A 403 installs the cooldown, which outranks the cap: a host that is
        // both capped and cooling down must report the cooldown, since that is
        // the one that survives the window rolling over.
        ratProbe('bt9.t-ru.org', 1000, 403, RAT_WINDOW);
        strictAssertSame('cooldown', RuTrackerAnnounce::probeDecision('bt9.t-ru.org', 1001, 10, RAT_WINDOW),
            'a running 403 cooldown is reported as the cooldown');
    });
});

$suite->test('announce budget: a successful probe clears the remembered cooldown length', function () {
    strictWithStateDir('chk-announce-reset', function () {
        ratProbe('bt3.t-ru.org', 1000, 403, RAT_WINDOW);           // first 403 -> cooldown 3600
        strictAssertTrue(!ratAllowProbe('bt3.t-ru.org', 1000 + 3600, 10, RAT_WINDOW), 'still inside first cooldown');

        ratProbe('bt3.t-ru.org', 1000 + 3601, 200, RAT_WINDOW);   // success clears cooldown_length

        ratProbe('bt3.t-ru.org', 2000, 403, RAT_WINDOW);           // next 403 must restart at 3600, not double to 7200
        strictAssertTrue(
            ratAllowProbe('bt3.t-ru.org', 2000 + 3601, 10, RAT_WINDOW),
            'cooldown restarted at 3600 instead of doubling from the old length'
        );
    });
});

// --- Risk B: the per-cycle announce cap must survive across processes -----

// Real processes, not "simulated" ones. This test used to run five iterations
// of one loop in one process and call that a process boundary, which is
// mechanically the cap test above with a different host name: the regression
// its own comment names -- an in-memory-only counter -- would have sailed
// straight through it. Every manual "check" click really does spawn a fresh
// batch_check.php, so the only thing that can hold the cap is the file, and
// the only way to prove the file holds it is to leave the process.
$suite->test('announce budget: the windowed cap holds across genuinely separate processes', function () {
    $tmp = sys_get_temp_dir() . '/chk-announce-window-durable-' . getmypid();
    strictRemoveTree($tmp);
    mkdir($tmp, 0777, true);

    // announce.php reaches php/Torrent.php, whose own requires are relative,
    // so the child has to run with its cwd inside php/ -- the same bootstrap
    // TestLib performs.
    $child = $tmp . '/child.php';
    file_put_contents($child, '<?php
        chdir(' . var_export(testFindRepoRoot() . '/php/utility', true) . ');
        require ' . var_export(testFindRepoRoot() . '/php/utility/fileutil.php', true) . ';
        chdir(' . var_export(testFindRepoRoot() . '/php', true) . ');
        require ' . var_export(testFindRepoRoot() . '/plugins/rutracker_check/announce.php', true) . ';
        $dir = new ReflectionProperty("RuTrackerState", "dir");
        $dir->setAccessible(true);
        $dir->setValue(null, ' . var_export($tmp, true) . ');
        echo RuTrackerAnnounce::reserveProbe("bt4.t-ru.org", 1000, 5, ' . RAT_WINDOW . ');
    ');

    try {
        $answers = array();
        for ($click = 0; $click < 6; $click++) {
            $spec = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
            $proc = proc_open(PHP_BINARY . ' ' . escapeshellarg($child), $spec, $pipes);
            strictAssertTrue(is_resource($proc), 'click ' . $click . ' launched');
            $answers[] = trim(stream_get_contents($pipes[1]));
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            strictAssertSame(0, proc_close($proc), 'click ' . $click . ' exited cleanly: ' . $stderr);
        }

        strictAssertSame(array('allow', 'allow', 'allow', 'allow', 'allow', 'cap'), $answers,
            'six separate processes share one window: the sixth click finds the budget spent');
    } finally {
        strictRemoveTree($tmp);
    }
});

$suite->test('announce budget: the window expires after the configured interval and the count restarts', function () {
    strictWithStateDir('chk-announce-window-expire', function () {
        $window = 3600;
        for ($i = 0; $i < 5; $i++) {
            ratProbe('bt5.t-ru.org', 1000 + $i, 200, $window);
        }
        strictAssertTrue(!ratAllowProbe('bt5.t-ru.org', 1004, 5, $window), 'cap holds inside the window');
        strictAssertTrue(!ratAllowProbe('bt5.t-ru.org', 1000 + $window - 1, 5, $window), 'still capped just before it elapses');
        strictAssertTrue(ratAllowProbe('bt5.t-ru.org', 1000 + $window, 5, $window), 'a fresh budget once the window elapses');

        // The restarted window itself must enforce the cap again, not stay
        // permanently open.
        for ($i = 0; $i < 5; $i++) {
            ratProbe('bt5.t-ru.org', 1000 + $window + $i, 200, $window);
        }
        strictAssertTrue(!ratAllowProbe('bt5.t-ru.org', 1000 + $window + 4, 5, $window), 'the restarted window caps again');
    });
});

$suite->test('announce budget: a zero window (disabled scheduler) is floored so the cap still holds', function () {
    strictWithStateDir('chk-announce-window-floor', function () {
        // conf.php documents $updateInterval = 0 as "disable the
        // scheduler", but manual batch_check.php clicks still compute the
        // window as $updateInterval * 60 -- i.e. 0. Without a floor every
        // click would open (and instantly close) its own window, buying a
        // fresh cap every time, exactly the bug this fix closes.
        for ($i = 0; $i < 3; $i++) {
            strictAssertTrue(ratAllowProbe('bt6.t-ru.org', 1000, 3, 0), "click {$i} allowed");
            ratProbe('bt6.t-ru.org', 1000, 200, 0);
        }
        strictAssertTrue(!ratAllowProbe('bt6.t-ru.org', 1000, 3, 0),
            'three same-instant clicks with window=0 still hit the cap: the floor keeps the window open');
    });
});

// --- The slot must be taken by the same locked write that judges it --------

// What this proves is the SHAPE of the call, not the lock: four sequential
// calls in one process cannot interleave, so deleting the flock from
// RuTrackerState::update() would leave every assertion below green. The lock
// itself is pinned where it can be — StateTest's two-process mutex test, and
// the cross-process cap test above.
$suite->test('reserveProbe decides and takes in one call, so asking twice cannot spend one slot twice', function () {
    strictWithStateDir('chk-announce-reserve', function () {
        // probeDecision() only reads: asked twice with one slot left it says
        // "allow" twice, which is exactly how two cycles both spent it.
        strictAssertSame('allow', RuTrackerAnnounce::probeDecision('bt.t-ru.org', 1000, 1, RAT_WINDOW), 'reader sees the slot');
        strictAssertSame('allow', RuTrackerAnnounce::probeDecision('bt.t-ru.org', 1000, 1, RAT_WINDOW), 'and sees it again');

        // reserveProbe() consumes it, so the second asker is refused.
        strictAssertSame('allow', RuTrackerAnnounce::reserveProbe('bt.t-ru.org', 1000, 1, RAT_WINDOW), 'first reservation takes the slot');
        strictAssertSame('cap', RuTrackerAnnounce::reserveProbe('bt.t-ru.org', 1000, 1, RAT_WINDOW), 'the second finds none left');
        strictAssertSame('cap', RuTrackerAnnounce::probeDecision('bt.t-ru.org', 1000, 1, RAT_WINDOW), 'and the reader agrees');

        // A reservation refused by the cooldown must not consume anything.
        RuTrackerAnnounce::recordOutcome('bt2.t-ru.org', 1000, 403);
        strictAssertSame('cooldown', RuTrackerAnnounce::reserveProbe('bt2.t-ru.org', 1001, 5, RAT_WINDOW), 'cooldown refuses');
        strictAssertSame(0, (int) (RuTrackerState::load('announce')['bt2.t-ru.org']['window_count'] ?? 0),
            'a refused reservation spends no budget');
    });
});

$suite->test('releaseProbe gives back a slot whose request never went out', function () {
    strictWithStateDir('chk-announce-release', function () {
        strictAssertSame('allow', RuTrackerAnnounce::reserveProbe('bt.t-ru.org', 1000, 1, RAT_WINDOW), 'slot taken');
        RuTrackerAnnounce::releaseProbe('bt.t-ru.org', 1000);
        strictAssertSame('allow', RuTrackerAnnounce::reserveProbe('bt.t-ru.org', 1000, 1, RAT_WINDOW),
            'the budget is whole again when the probe was abandoned');
    });
});

$suite->test('a success cannot clear a cooldown installed while it was in flight', function () {
    strictWithStateDir('chk-announce-late', function () {
        // Probe A leaves at t=1000. Probe B, concurrent, gets a 403 at t=1005
        // and installs the cooldown. A's 200 lands at t=1010: it is older
        // news about the host and must not undo B's cooldown.
        RuTrackerAnnounce::recordOutcome('bt.t-ru.org', 1005, 403);
        $installed = (int) RuTrackerState::load('announce')['bt.t-ru.org']['cooldown_until'];
        RuTrackerAnnounce::recordOutcome('bt.t-ru.org', 1010, 200);
        strictAssertSame($installed, (int) RuTrackerState::load('announce')['bt.t-ru.org']['cooldown_until'],
            'the live cooldown survives a late success');
        strictAssertSame('cooldown', RuTrackerAnnounce::probeDecision('bt.t-ru.org', 1011, 10, RAT_WINDOW),
            'and still refuses probes');

        // Once it has lapsed, a success does reset the doubling.
        RuTrackerAnnounce::recordOutcome('bt.t-ru.org', $installed + 1, 200);
        strictAssertSame(0, (int) RuTrackerState::load('announce')['bt.t-ru.org']['cooldown_until'],
            'an expired cooldown is cleared by the next success');
    });
});

// The size cap on an announce answer is really a bound on the decoder's
// recursion depth: the parser inherits Torrent's recursive descent and has no
// depth limit, so a body that is nothing but nesting costs about one stack
// frame per two bytes. Exhausting memory there is a FATAL error -- classify()'s
// catch(Exception) cannot see it -- so the cap has to be small enough that the
// worst body it admits is still cheap.
$suite->test('an announce answer too large to decode safely is refused before decoding', function () {
    $cap = RuTrackerAnnounce::MAX_ANNOUNCE_BODY;

    // One byte over the cap is refused without being parsed -- and it is a
    // WELL-FORMED dictionary, so only the size can be what rejected it.
    $filler = str_repeat('x', $cap);
    $oversize = 'd4:note' . strlen($filler) . ':' . $filler . 'e';
    strictAssertTrue(strlen($oversize) > $cap, 'the fixture really is over the cap');
    strictAssertSame('uncertain', RuTrackerAnnounce::classify(200, $oversize),
        'an answer past the cap is inconclusive, never decoded');

    // And the safety property the cap exists for: the most hostile body the
    // cap DOES admit -- maximal nesting, one list per two bytes -- decodes
    // without exhausting a small memory_limit. If the cap is ever raised,
    // this is the test that should stop it.
    // Measured in a CHILD with the limit actually applied, not with
    // memory_get_usage() around the call: by the time classify() returns, its
    // temporaries and stack frames are already freed, so a transient peak
    // above the limit would leave the reading — and the test — perfectly
    // green. Exhausting memory is a fatal error, so the only honest question
    // is whether a process configured that way survives, and the only way to
    // ask it is to be that process.
    // Fail FAST if the cap is ever raised, rather than letting the child below
    // grind through a pathological body: the worst case grows with the cap and
    // stops being cheap to reason about long before it stops being decodable.
    strictAssertTrue($cap <= 16384,
        'the cap is a bound on recursion depth as much as on transfer size; raising it needs this test rewritten');

    $depth = intdiv($cap - 33, 2);
    $deep = 'd8:intervali1800e5:peers0:1:a' . str_repeat('l', $depth) . str_repeat('e', $depth) . 'e';
    strictAssertTrue(strlen($deep) <= $cap, 'the deepest admitted body is within the cap');

    $dir = sys_get_temp_dir() . '/chk-announce-mem-' . getmypid();
    strictRemoveTree($dir);
    mkdir($dir, 0777, true);
    $child = $dir . '/child.php';
    file_put_contents($child, '<?php
        chdir(' . var_export(testFindRepoRoot() . '/php/utility', true) . ');
        require ' . var_export(testFindRepoRoot() . '/php/utility/fileutil.php', true) . ';
        chdir(' . var_export(testFindRepoRoot() . '/php', true) . ');
        require ' . var_export(testFindRepoRoot() . '/plugins/rutracker_check/announce.php', true) . ';
        $cap = RuTrackerAnnounce::MAX_ANNOUNCE_BODY;
        $depth = intdiv($cap - 33, 2);
        echo RuTrackerAnnounce::classify(200,
            "d8:intervali1800e5:peers0:1:a" . str_repeat("l", $depth) . str_repeat("e", $depth) . "e");
    ');

    try {
        $spec = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
        $proc = proc_open(escapeshellarg(PHP_BINARY) . ' -d memory_limit=8M ' . escapeshellarg($child),
            $spec, $pipes);
        strictAssertTrue(is_resource($proc), 'the child launched');
        $answer = trim(stream_get_contents($pipes[1]));
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        // It parses as a dictionary carrying no failure reason, which is the
        // conservative reading ("the tracker still knows this hash") -- the
        // point here is only that it PARSES, inside 8M, instead of taking the
        // process down.
        strictAssertSame(0, $code,
            'the deepest admitted body decodes under an 8M limit: ' . $stderr);
        strictAssertSame('registered', $answer, 'and answers, rather than dying mid-parse');
        strictAssertTrue(strpos($stderr, 'memory') === false,
            'with nothing said about memory: ' . $stderr);
    } finally {
        strictRemoveTree($dir);
    }

    // The answers this probe actually asks for are two orders of magnitude
    // smaller, so the cap costs nothing.
    strictAssertSame('unregistered', RuTrackerAnnounce::classify(200,
        'd14:failure reason' . strlen(RuTrackerAnnounce::UNREGISTERED_FAILURE_REASON) . ':'
        . RuTrackerAnnounce::UNREGISTERED_FAILURE_REASON . 'e'),
        'a real failure reason is nowhere near the cap');
});

// Only a served answer says the host is talking to us again. A 429, a 5xx or
// the negative status Snoopy reports for a connect/read failure used to read
// as success and wipe cooldown_length, so the next genuine 403 restarted the
// backoff at one hour instead of continuing to double.
$suite->test('a refusal that is not a 403 neither doubles nor resets the backoff', function () {
    foreach (array(0, -2, 429, 500, 503) as $status) {
        strictWithStateDir('chk-announce-notok', function () use ($status) {
            ratProbe('bt.t-ru.org', 1000, 403, RAT_WINDOW);             // -> 3600
            $state = RuTrackerState::load('announce')['bt.t-ru.org'];
            strictAssertSame(3600, (int) $state['cooldown_length'], 'the first 403 sets an hour');

            // Well after the cooldown lapsed, so a 200 here WOULD reset it.
            ratProbe('bt.t-ru.org', 1000 + 3601, $status, RAT_WINDOW);
            $state = RuTrackerState::load('announce')['bt.t-ru.org'];
            strictAssertSame(3600, (int) $state['cooldown_length'],
                'status ' . $status . ' leaves the earned backoff standing');

            // ...and the next 403 therefore doubles rather than starting over.
            ratProbe('bt.t-ru.org', 1000 + 7202, 403, RAT_WINDOW);
            $state = RuTrackerState::load('announce')['bt.t-ru.org'];
            strictAssertSame(7200, (int) $state['cooldown_length'],
                'status ' . $status . ' did not hand the next 403 a fresh hour');
        });
    }
});

// A reservation abandoned across a window boundary belongs to a window that
// no longer exists. Refunding it into the current one would create a probe
// out of nothing -- which is what a fresh time() at release time did, since
// it could not tell the two windows apart.
$suite->test('an abandoned reservation is not refunded into a later window', function () {
    strictWithStateDir('chk-announce-latewindow', function () {
        // Window A: the single slot is taken at t=1000 and the request is
        // then abandoned -- but not before window B has opened and spent its
        // own only slot.
        strictAssertSame('allow', RuTrackerAnnounce::reserveProbe('bt.t-ru.org', 1000, 1, RAT_WINDOW), 'window A slot taken');
        $later = 1000 + RAT_WINDOW + 5;
        strictAssertSame('allow', RuTrackerAnnounce::reserveProbe('bt.t-ru.org', $later, 1, RAT_WINDOW), 'window B opens and spends its slot');

        RuTrackerAnnounce::releaseProbe('bt.t-ru.org', 1000);
        strictAssertSame('cap', RuTrackerAnnounce::reserveProbe('bt.t-ru.org', $later + 1, 1, RAT_WINDOW),
            'the stale refund did not conjure a slot in the new window');

        // Within its own window the refund still works, which is the whole
        // point of releaseProbe().
        RuTrackerAnnounce::releaseProbe('bt.t-ru.org', $later);
        strictAssertSame('allow', RuTrackerAnnounce::reserveProbe('bt.t-ru.org', $later + 2, 1, RAT_WINDOW),
            'a refund inside its own window is honoured');
    });
});

// A budget that cannot be written is a budget of zero. reserveProbe() takes
// the slot inside the locked write, so if the store refuses -- an unwritable
// settings directory, a full disk -- nothing is holding it: the next process
// reads the same untouched allowance and probes too. The cap stopped capping
// exactly when the machine was least healthy.
$suite->test('a budget that cannot be stored refuses the probe instead of granting it', function () {
    $tmp = sys_get_temp_dir() . '/chk-announce-readonly-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
        // A healthy store first, so the difference is the store and nothing else.
        strictAssertSame('allow', RuTrackerAnnounce::reserveProbe('bt.t-ru.org', 1000, 5, RAT_WINDOW),
            'a writable store grants the slot');
        strictAssertSame(1, (int) RuTrackerState::load('announce')['bt.t-ru.org']['window_count'],
            'and records it');

        // Now take the write away. Not by chmod -- these suites run as root
        // often enough that permission bits prove nothing -- but by pointing
        // the store at a path UNDER a regular file, where even root's fopen
        // gets ENOTDIR. The rule still says 'allow'; the refusal has to come
        // from the write.
        $blocked = $tmp . '/not-a-directory';
        file_put_contents($blocked, 'x');
        strictSetPrivateStatic('RuTrackerState', 'dir', $blocked . '/store');
        $decision = RuTrackerAnnounce::reserveProbe('bt.t-ru.org', 1001, 5, RAT_WINDOW);
        strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

        strictAssertSame('unstorable', $decision,
            'an unrecordable slot is refused, not spent unrecorded');
        strictAssertSame(1, (int) RuTrackerState::load('announce')['bt.t-ru.org']['window_count'],
            'and nothing was written, which is exactly why it had to be refused');
    } finally {
        strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);
        strictRemoveTree($tmp);
    }
});

$suite->test('the budget is keyed on one spelling of the host', function () {
    strictWithStateDir('chk-announce-hostkey', function () {
        strictAssertSame('allow', RuTrackerAnnounce::reserveProbe('bt.t-ru.org', 1000, 1, RAT_WINDOW), 'the slot is taken');
        // Host names are case-insensitive; a second spelling must not buy a
        // second allowance.
        strictAssertSame('cap', RuTrackerAnnounce::reserveProbe('BT.T-RU.ORG', 1000, 1, RAT_WINDOW),
            'the same host in capitals shares the budget');
        strictAssertSame('cap', RuTrackerAnnounce::probeDecision('Bt.T-Ru.Org.', 1000, 1, RAT_WINDOW),
            'and so does the fully-qualified form');

        // A 403 recorded under one spelling stops the others too.
        RuTrackerAnnounce::recordOutcome('BT2.T-RU.ORG', 1000, 403);
        strictAssertSame('cooldown', RuTrackerAnnounce::probeDecision('bt2.t-ru.org', 1001, 10, RAT_WINDOW),
            'the cooldown is not spelling-dependent either');

        // The fourth function that keys the budget, and the one this case used
        // to miss: a slot given back under another spelling must refund the
        // host it was taken from, not open a second allowance beside it.
        strictAssertSame('allow', RuTrackerAnnounce::reserveProbe('bt3.t-ru.org', 2000, 1, RAT_WINDOW),
            'a slot is taken on the third host');
        RuTrackerAnnounce::releaseProbe('BT3.T-RU.ORG.', 2000);
        strictAssertSame('allow', RuTrackerAnnounce::reserveProbe('bt3.t-ru.org', 2000, 1, RAT_WINDOW),
            'the refund landed on the same budget, so the one slot is free again');
    });
});

$suite->test('the probe budget and pause are bounded at BOTH ends, not just floored', function () {
    // conf.php promises out-of-range values are clamped where they are read.
    // These two had a floor and no ceiling, so a mistyped cap removed the
    // request limiter outright and a mistyped pause stalled the cycle.
    strictAssertSame(RuTrackerAnnounce::PROBE_CAP_MAX, RuTrackerAnnounce::probeCap(100000),
        'a cap past the tracker\'s own measured limit buys refusals, not confirmations');
    strictAssertSame(0, RuTrackerAnnounce::probeCap(-5), 'a negative cap is no allowance');
    strictAssertSame(0, RuTrackerAnnounce::probeCap(0),
        'zero survives the clamp: it is how the probe is switched off per host');
    strictAssertSame(10, RuTrackerAnnounce::probeCap(10), 'the shipped default passes through');

    strictAssertSame(RuTrackerAnnounce::PROBE_PAUSE_MAX, RuTrackerAnnounce::probePause(99999),
        'a pause past a minute spends the whole cycle on one host');
    strictAssertSame(0, RuTrackerAnnounce::probePause(-1), 'a negative pause is not a negative sleep');
    strictAssertSame(5, RuTrackerAnnounce::probePause(5), 'the shipped default passes through');
});

exit($suite->run());
