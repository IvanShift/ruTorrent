<?php

define('TESTLIB_HANDLER_STUBS', 1);
require_once(__DIR__ . '/TestLib.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/detector.php');

$suite = new StrictTestSuite();

function detRow($failed, $success, $enabled = 1, $url = 'http://bt2.t-ru.org/ann?pk=x')
{
    return array('url' => $url, 'enabled' => $enabled, 'failed' => $failed, 'success' => $success);
}

$suite->test('parseTrackerBlob splits rows and fields', function () {
    $rows = RuTrackerDetector::parseTrackerBlob('http://bt.t-ru.org/ann?pk=x|1|2|5#dht://|1|0|0#');
    strictAssertSame(2, count($rows), 'two rows');
    strictAssertSame(array('url' => 'http://bt.t-ru.org/ann?pk=x', 'enabled' => 1, 'failed' => 2, 'success' => 5),
        $rows[0], 'first row');
    strictAssertSame(array(), RuTrackerDetector::parseTrackerBlob(''), 'empty blob');
});

// A '|' inside a tracker URL breaks the field framing, so the row carries
// unknowable counters and must be dropped rather than mis-parsed.
$suite->test('parseTrackerBlob drops a row whose field count is wrong', function () {
    $rows = RuTrackerDetector::parseTrackerBlob('http://bt.t-ru.org/ann?pk=a|b|1|2|5#http://bt2.t-ru.org/ann|1|0|7#');
    strictAssertSame(1, count($rows), 'only the well-formed row survives');
    strictAssertSame('http://bt2.t-ru.org/ann', $rows[0]['url'], 'surviving row');
    strictAssertSame(array(), RuTrackerDetector::parseTrackerBlob('http://bt.t-ru.org/ann|1|2#'), 'too few fields');
});

$suite->test('classify follows the layer-1 rules', function () {
    strictAssertSame('alive', RuTrackerDetector::classify(array(detRow(0, 5)), ''), 'failed=0');
    strictAssertSame('cold', RuTrackerDetector::classify(array(detRow(0, 0)), ''), 'counters zero');
    strictAssertSame('candidate', RuTrackerDetector::classify(array(detRow(6, 0)), ''), 'failed, empty message');
    strictAssertSame('transport',
        RuTrackerDetector::classify(array(detRow(2, 1)), 'Tracker: [Could not resolve hostname]'), 'transport message');
    strictAssertSame('candidate',
        RuTrackerDetector::classify(array(detRow(6, 0)), 'Tracker: [Failure reason "unregistered"]'), 'non-transport message');
    strictAssertSame('none', RuTrackerDetector::classify(array(detRow(6, 0, 0)), ''), 'disabled row');
    strictAssertSame('none', RuTrackerDetector::classify(array(detRow(6, 0, 1, 'http://other.example/ann')), ''), 'no t-ru row');
    strictAssertSame('alive',
        RuTrackerDetector::classify(array(detRow(0, 3), array('url' => 'dht://', 'enabled' => 1, 'failed' => 9, 'success' => 0)), ''),
        'dht row ignored');
});

// The original analysis treated a short announce interval
// (t.normal_interval near its 300s clamp, seen right after first contact) as a
// sign of a dead torrent; 29 live torrents measured at that same clamped
// interval proved the rule wrong, so it was dropped rather than ported.
// classify()'s row shape (url/enabled/failed/success) has no interval field at
// all, so this is a structural regression guard: a torrent whose success
// counter has barely ticked up -- exactly the moment the rejected rule would
// have seen a short interval -- must still read as alive on failed=0 alone.
$suite->test('classify has no interval input to regress on: failed=0 is alive from the very first successful announce', function () {
    strictAssertSame('alive', RuTrackerDetector::classify(array(detRow(0, 1)), ''), 'first successful announce, failed=0');
    strictAssertSame('alive', RuTrackerDetector::classify(array(detRow(0, 600)), ''), 'long-lived, failed=0');
});

$suite->test('fuse trips per host with an absolute floor', function () {
    $stats = array(
        'bt.t-ru.org' => array('total' => 57, 'candidates' => 12),   // ceil(11.4)=12 -> trips
        'bt2.t-ru.org' => array('total' => 66, 'candidates' => 13),  // ceil(13.2)=14 -> holds
        'bt3.t-ru.org' => array('total' => 4, 'candidates' => 2),    // floor 3 -> holds
        'bt4.t-ru.org' => array('total' => 4, 'candidates' => 3),    // floor 3 reached -> trips
    );
    strictAssertSame(array('bt.t-ru.org', 'bt4.t-ru.org'),
        RuTrackerDetector::fuseTrips($stats, 0.2, 3), 'trip set');
});

exit($suite->run());
