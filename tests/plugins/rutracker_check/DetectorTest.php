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

$suite->test('parseTrackerBlob marks incomplete on truncated blob without trailing delimiter', function () {
    $complete = true;
    $rows = RuTrackerDetector::parseTrackerBlob('http://bt.t-ru.org/ann?pk=x|1|2|5', $complete);
    strictAssertSame(false, $complete, 'blob without trailing hash delimiter is incomplete');
    strictAssertSame(1, count($rows), 'valid row parsed despite missing trailing hash');
});

$suite->test('parseTrackerBlob drops rows with non-numeric counter fields and marks incomplete', function () {
    $complete = true;
    $rows = RuTrackerDetector::parseTrackerBlob('http://bt.t-ru.org/ann?pk=x|foo|2|5#', $complete);
    strictAssertSame(false, $complete, 'row with non-numeric field is incomplete');
    strictAssertSame(0, count($rows), 'row with non-numeric field is dropped');
});

$suite->test('a dropped tracker row makes the download-global message unattributable', function () {
    $complete = true;
    $rows = RuTrackerDetector::parseTrackerBlob(
        'http://bt.t-ru.org/ann|1|6|0#http://foreign.example/ann?token=a|b|1|4|0#',
        $complete
    );

    strictAssertSame(false, $complete, 'the caller is told that tracker framing lost a row');
    strictAssertSame('candidate', RuTrackerDetector::classify(
        $rows,
        'Tracker: [Could not resolve hostname]',
        $complete
    ), 'a global message cannot excuse RuTracker when an enabled foreign row may have written it');
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

// A RuTracker torrent normally carries several announce rows (bt.t-ru.org,
// bt2..., bt3...). Deciding on whichever comes first let a single disabled or
// failing row speak for all of them -- and 'none' in particular meant the
// torrent was never checked again.
$suite->test('classify weighs every RuTracker row, not just the first', function () {
    $row = function ($host, $enabled, $failed, $success) {
        return array('url' => 'http://' . $host . '/ann?pk=x', 'enabled' => $enabled,
            'failed' => $failed, 'success' => $success);
    };

    // A disabled first row must not hide a working one behind it.
    strictAssertSame('alive', RuTrackerDetector::classify(array(
        $row('bt.t-ru.org', 0, 0, 0),
        $row('bt2.t-ru.org', 1, 0, 5),
    ), ''), 'a disabled row judges nothing; the enabled one answers');

    // One row still announcing successfully proves the topic is there, even
    // while another is failing -- and 'alive' is the safe direction, since
    // 'candidate' is what ultimately replaces the user's torrent.
    strictAssertSame('alive', RuTrackerDetector::classify(array(
        $row('bt.t-ru.org', 1, 6, 0),
        $row('bt2.t-ru.org', 1, 0, 5),
    ), ''), 'a success anywhere outranks a failure elsewhere');

    // Every enabled row failing is still a candidate.
    strictAssertSame('candidate', RuTrackerDetector::classify(array(
        $row('bt.t-ru.org', 1, 6, 0),
        $row('bt2.t-ru.org', 1, 3, 0),
    ), ''), 'all rows failing is what a re-upload looks like');

    // Every RuTracker row disabled is "not my jurisdiction", as before.
    strictAssertSame('none', RuTrackerDetector::classify(array(
        $row('bt.t-ru.org', 0, 6, 0),
        $row('bt2.t-ru.org', 0, 3, 0),
    ), ''), 'no enabled RuTracker row at all');

    // No counters anywhere is 'cold', whichever row is looked at.
    strictAssertSame('cold', RuTrackerDetector::classify(array(
        $row('bt.t-ru.org', 1, 0, 0),
        $row('bt2.t-ru.org', 1, 0, 0),
    ), ''), 'a stopped torrent announces nothing at all');
});

// The host form of the tracker pattern decides whether to SEND a request, so
// a substring match is not good enough.
$suite->test('TRACKER_HOST_PATTERN matches whole domain labels, never a substring', function () {
    foreach (array('bt.t-ru.org', 'bt4.t-ru.org', 't-ru.org', 'rutracker.org', 'api.rutracker.cc') as $host)
        strictAssertSame(1, preg_match(RuTrackerDetector::TRACKER_HOST_PATTERN, $host), $host . ' is RuTracker\'s');

    foreach (array('rutracker.evil.example', 'bt.t-ru.org.evil.example', 'evil-rutracker.example',
                   'nott-ru.org.example', 'rutracker.org.attacker.net',
                   // The TLD is part of the identity, not a wildcard: whoever
                   // writes the announce URL picks the host, so an unlisted
                   // top-level domain must not buy a request.
                   'rutracker.xyz', 'rutracker.local', 'rutracker.zip',
                   'bt.rutracker.pw', 't-ru.net') as $host)
        strictAssertSame(0, preg_match(RuTrackerDetector::TRACKER_HOST_PATTERN, $host), $host . ' is not');

    // The URL-matching form stays a substring test on purpose: it is applied
    // to whole announce URLs, where the host sits in the middle.
    strictAssertSame(1, preg_match(RuTrackerDetector::TRACKER_PATTERN, 'http://bt.t-ru.org/ann?pk=x'),
        'the URL form still matches a full announce URL');
});

// d.message is download-global -- the most recent tracker event of ANY row.
$suite->test('a third party\'s transport message cannot excuse the RuTracker rows', function () {
    $tru = array('url' => 'http://bt.t-ru.org/ann?pk=x', 'enabled' => 1, 'failed' => 6, 'success' => 0);
    $other = array('url' => 'http://tracker.example.org/ann', 'enabled' => 1, 'failed' => 4, 'success' => 0);
    $timeout = 'Tracker: [Could not resolve hostname]';

    strictAssertSame('transport', RuTrackerDetector::classify(array($tru), $timeout),
        'with only RuTracker rows the message can only be theirs');
    strictAssertSame('candidate', RuTrackerDetector::classify(array($tru, $other), $timeout),
        'with a foreign row present the message proves nothing, so the counters speak');
    strictAssertSame('transport', RuTrackerDetector::classify(array($tru,
        array('url' => 'http://tracker.example.org/ann', 'enabled' => 0, 'failed' => 4, 'success' => 0)), $timeout),
        'a DISABLED foreign row writes no events, so it does not muddy the message');
});

// TRANSPORT_PATTERN is matched against d.message, whose tail is the TRACKER's
// own prose. Unanchored, a tracker could put "timed out" in any failure
// reason and veto its own deletion detection for good.
$suite->test('only rTorrent\'s own transport wording counts as a transport failure', function () {
    $tru = array('url' => 'http://bt.t-ru.org/ann?pk=x', 'enabled' => 1, 'failed' => 6, 'success' => 0);

    foreach (array(
        'Tracker: [Could not resolve hostname]',
        'Tracker: [Could not connect to server]',
        'Tracker: [Timed out]',
        'Timeout was reached',
    ) as $real)
        strictAssertSame('transport', RuTrackerDetector::classify(array($tru), $real),
            'rTorrent\'s own wording: ' . $real);

    foreach (array(
        'Tracker: [Failure reason "Torrent not registered, your session timed out"]',
        'Tracker: [Failure reason "connection could not connect, please retry"]',
    ) as $prose)
        strictAssertSame('candidate', RuTrackerDetector::classify(array($tru), $prose),
            'the tracker\'s own words cannot veto the verdict: ' . $prose);
});

$suite->test('a look-alike host cannot certify the topic alive', function () {
    // 'alive' is the verdict that STOPS the check: the scheduler writes
    // UPTODATE from it without spending a single request. Row selection is
    // deliberately the loose substring test -- a torrent whose announce was
    // tampered with is still a RuTracker topic layers 2 and 3 must look at --
    // but anyone who can put a URL into a torrent can register
    // rutracker.evil.example, announce to it successfully, and would otherwise
    // have the plugin certify the real topic up to date for ever.
    foreach (array(
        'http://rutracker.evil.example/ann'   => 'a registrable look-alike domain',
        'http://bt.t-ru.org.evil.example/ann' => 'a look-alike with the real host as a prefix',
        'http://myrutracker.org/ann'          => 'a host that merely ends in the real one',
    ) as $url => $why) {
        strictAssertSame('candidate',
            RuTrackerDetector::classify(array(array('url' => $url, 'enabled' => 1, 'failed' => 0, 'success' => 9)), ''),
            $why . ' must not answer alive');
        strictAssertSame(false, RuTrackerDetector::isTrackerRow($url), $why . ' is not a RuTracker row');
    }

    // And the real thing still is, in every spelling the daemon may hand over.
    foreach (array('http://bt.t-ru.org/ann?pk=x', 'http://BT2.T-RU.ORG/ann', 'http://bt3.t-ru.org./ann',
                   'https://rutracker.org/ann', 'https://rutracker.cr/ann') as $url) {
        strictAssertSame(true, RuTrackerDetector::isTrackerRow($url), $url . ' is RuTracker\'s');
        strictAssertSame('alive',
            RuTrackerDetector::classify(array(array('url' => $url, 'enabled' => 1, 'failed' => 0, 'success' => 9)), ''),
            $url . ' still certifies the topic alive');
    }
});

// rTorrent does not hand out one message per row -- it joins every failing
// row's message into d.message with ' /// '. Live sample from the fleet on
// 2026-08-21: "Tracker: [Could not connect to server /// Could not resolve
// hostname]", on a torrent whose two failing rows were a retracker and an IPv6
// mirror while its two working rows said nothing. The anchored pattern read
// only as far as the first part and answered for the whole string.
$suite->test('a transport excuse must cover the whole of d.message, not just its first part', function () {
    $rows = array(array('url' => 'http://bt.t-ru.org/ann', 'enabled' => 1, 'failed' => 4, 'success' => 0));

    foreach (array(
        'Tracker: [Could not connect to server /// Could not resolve hostname]',
        'Tracker: [Timeout /// Timed out]',
        'Could not resolve hostname',
    ) as $message)
        strictAssertSame('transport', RuTrackerDetector::classify($rows, $message),
            'every part is the network: ' . $message);

    // One part that is not, and the message no longer excuses anything. This
    // is the case that used to be lost: a topic deregistered on one mirror
    // while another mirror could not be resolved read as a pure network
    // failure, so layer 1 said "do nothing" and kept saying it.
    foreach (array(
        'Tracker: [Could not connect to server /// Failure reason "not registered"]',
        'Tracker: [Could not resolve hostname /// Requested download is not authorized]',
    ) as $message)
        strictAssertSame('candidate', RuTrackerDetector::classify($rows, $message),
            'something in there is not the network: ' . $message);

    // And the rule on its own, in both directions.
    strictAssertSame(true, RuTrackerDetector::isTransportFailure('Tracker: [Timed out /// Timed out]'),
        'a pure aggregate');
    strictAssertSame(false, RuTrackerDetector::isTransportFailure('Tracker: [Timed out /// Failure reason "x"]'),
        'a mixed aggregate');
    strictAssertSame(false, RuTrackerDetector::isTransportFailure(''), 'nothing at all is not an excuse');
    strictAssertSame(false, RuTrackerDetector::isTransportFailure(null), 'and neither is a non-string');
});

exit($suite->run());
