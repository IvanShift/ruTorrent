<?php

/**
 * Tests for plugins/rutracker_check/trackers/rutracker.php.
 *
 * download_torrent() now runs the four-layer post-API flow (layer1Verdict ->
 * layer 2 announce probe -> forum dump classification -> RuTrackerMetaFetch),
 * so this file needs the layer modules (detector/announce/forumindex,
 * pulled in transitively by rutracker.php itself) plus a recording rTorrent
 * double for layer 4 -- exactly like MetaFetchTest.php's own double, since
 * RuTrackerMetaFetch::begin() is exercised here only far enough to prove it
 * was reached with the right hash; its own internals are MetaFetchTest's job.
 *
 * Absorption remains an active dump-status contract: tor_status 7 maps to the
 * absorbed verdict and message without a separate forum-HTML parser.
 */

define('TESTLIB_HANDLER_STUBS', 1);
require_once(__DIR__ . '/TestLib.php');

class rTorrent
{
    public static $magnets = array();
    public static $sendResult = null;

    public static function sendMagnet($magnet, $isStart, $isAddPath, $directory, $label, $addition = null)
    {
        self::$magnets[] = compact('magnet', 'isStart', 'directory', 'addition');
        return self::$sendResult;
    }
}

require_once(testFindRepoRoot() . '/plugins/rutracker_check/trackers/rutracker.php');

$suite = new StrictTestSuite();
$hash = str_repeat('A', 40);
$newHash = str_repeat('B', 40);
$topicId = 42;
$topicUrl = 'https://rutracker.org/forum/viewtopic.php?t=' . $topicId;
$announceUrl = 'http://bt.t-ru.org/ann?pk=SECRET';
$oldTorrent = new Torrent(strictTorrentRaw('release name', $announceUrl, $topicUrl));

$GLOBALS['tmpState'] = sys_get_temp_dir() . '/chk-handler-' . getmypid();

// Fresh RuTrackerState directory (this also clears the persisted announce
// budget -- cooldown and windowed cap alike -- since both live under it),
// and sane config globals (layer 2 off by default -- individual tests opt
// in). TESTLIB_HANDLER_STUBS never loads conf.php, so every config global
// the handler reads must be set here explicitly.
function hReset()
{
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = null;
    strictRemoveTree($GLOBALS['tmpState']);
    strictSetPrivateStatic('RuTrackerState', 'dir', $GLOBALS['tmpState']);
    // fetchDump()'s per-process memo (finding 5) is scoped to one real cycle
    // (one update.php/batch_check.php process); this whole test file runs as
    // a single PHP process, so each test here models a fresh cycle and must
    // clear the memo itself, or a later test reusing the same forum id would
    // be served an earlier test's stale dump instead of its own fixture.
    strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    $GLOBALS['updateInterval'] = 60;
    $GLOBALS['rutrackerDeleteCycles'] = 3;
    // -3: max(0, -3 + random_int(0, 3)) is always 0, so no probing test ever
    // sleeps; 0 alone still sleeps up to 3 random seconds per probe.
    $GLOBALS['rutrackerAnnouncePause'] = -3;
    $GLOBALS['rutrackerAnnounceCap'] = 10;
    $GLOBALS['rutrackerLayer2Enabled'] = false;
}


// t.multicall row shape layer1Verdict() maps into RuTrackerDetector::classify()'s
// [url, enabled, failed, success] fields.
function hAliveRow($host = 'bt.t-ru.org')
{
    return array("http://{$host}/ann?pk=x", 1, 0, 5);
}

function hCandidateRow($host = 'bt.t-ru.org', $failed = 6)
{
    return array("http://{$host}/ann?pk=x", 1, $failed, 0);
}

// Both counters zero: the torrent has not announced in this rTorrent session,
// which is the normal state of a stopped one. RuTrackerDetector calls that
// 'cold' -- no signal either way, as opposed to a tracker that answered badly.
function hColdRow($host = 'bt.t-ru.org')
{
    return array("http://{$host}/ann?pk=x", 1, 0, 0);
}

function hQueueLayer1($rows, $message = '')
{
    // Mirrors the real transport (php/xmlrpc.php): layer1Verdict() issues
    // d.get_tracker_size, t.multicall and d.get_message as ONE request, so
    // the answer is a single flat list -- the row count, each row's 4 values
    // in order, then the message.
    //
    // array_values(), not the row as it comes: array_merge() PRESERVES string
    // keys, so a caller that spells a row out as array('url' => ..., 'enabled'
    // => ...) -- which reads perfectly naturally -- produced a map, not a list.
    // layer1Verdict() then indexed it numerically, found nothing at 1..4, and
    // parsed ZERO rows out of the answer. The verdict came back 'none' either
    // way, so the case still went green while exercising "the multicall answer
    // was unreadable" instead of the branch it names.
    $flat = array(count($rows));
    foreach ($rows as $row) $flat = array_merge($flat, array_values($row));
    $flat[] = $message;
    rXMLRPCRequest::queue('d.get_tracker_size|t.multicall|d.get_message', true, false, $flat);
}

// A positive system.multicall response cut after four scalars. The first value
// is the tracker-count prefix the hardened request will carry; the remaining
// three values are only a prefix of its first tracker row. Returned rather than
// queued: two tests need this exact reply, and a second spelling of it could
// drift away from the first.
function hTruncatedLayer1Reply()
{
    return array(
        1,
        'http://bt.t-ru.org/ann?pk=x',
        1,
        0,
    );
}

function hQueueRawLayer1($values)
{
    rXMLRPCRequest::queue('d.get_tracker_size|t.multicall|d.get_message', true, false, $values);
}

// rememberTopic()'s read, pre-answered as "already known" so its write
// branch never fires -- tests that care about the write exercise
// rememberTopic() directly instead.
function hQueueTopicKnown($topicId)
{
    rXMLRPCRequest::queue('d.get_custom', true, false, array((string) $topicId));
}

// download_torrent()'s superseded short-circuit reads chk-state and chk-msg
// as ONE paired request ('d.get_custom|d.get_custom'), a key no other read in
// this handler uses. Tests that do not queue it get the double's default
// fault, which is exactly the production "no usable record -> run the normal
// flow" answer; the two tests that exercise the short-circuit queue it with
// hQueueSuperseded() below.
function hQueueSuperseded($successorHash, $state = ruTrackerChecker::STE_NOT_NEED)
{
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array(
        (string) $state,
        ruTrackerChecker::CHKMSG_SUPERSEDED . '|' . $successorHash,
    ));
}

// The message text recorded for the Nth setMessage call, so token assertions
// read as one value while the transport remains independently observable.
function hMessage($index)
{
    $calls = ruTrackerChecker::callsFor('setMessage');
    if (!isset($calls[$index])) return null;
    return $calls[$index]['arguments'][1];
}

function hQueueForum($forumId)
{
    rXMLRPCRequest::queue('d.get_custom', true, false, array((string) $forumId));
}

// RuTrackerAnnounceTest.php's own bencode fixtures for classify(): a clean
// dict (registered) and a dict with the canonical failure reason
// (unregistered).
function hRegisteredBody()
{
    return 'd8:intervali3021e5:peers6:' . "\x01\x02\x03\x04\x05\x06" . 'e';
}

function hUnregisteredBody()
{
    $reason = RuTrackerAnnounce::UNREGISTERED_FAILURE_REASON;
    return 'd14:failure reason' . strlen($reason) . ':' . $reason . 'e';
}

// Layer1 candidate -> layer2 unregistered -> forum dump carrying $newHash
// under $topicId (layer 3 says "updated") -> RuTrackerMetaFetch::begin()'s
// own successful-load sequence (mirrors MetaFetchTest.php's "begin loads a
// stopped magnet..." queuing). Shared by the two tests that need the full
// four-layer path to actually reach layer 4.
function hQueueMetaFetchFlow($hash, $newHash, $topicId, $forumId = 1106, $rows = null)
{
    hQueueTopicKnown($topicId);
    hQueueLayer1($rows === null ? array(hCandidateRow()) : $rows);
    Snoopy::queueAny(200, hUnregisteredBody());
    hQueueForum($forumId);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . $forumId, 200, fiDump($topicId, 0, $newHash));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());         // row found: resetDeletion
    ruTrackerChecker::queueResult('torrentExists', false);               // begin(): collision check, missing
    ruTrackerChecker::queueResult('awaitMetadata', false);                // leave the service fetch pending
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true, false, function () use ($hash, $topicId) {
            $deadline = '';
            if (isset(rTorrent::$magnets[0]['addition'])) {
                foreach (rTorrent::$magnets[0]['addition'] as $addition) {
                    if (strpos($addition, 'chk-meta-until,') !== false) {
                        $deadline = substr($addition, strpos($addition, 'chk-meta-until,') + strlen('chk-meta-until,'));
                    }
                }
            }
            return array($hash, (string) (int) $topicId, $deadline, 1);
        }
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
    // markOldTorrent: chk-meta-new and chk-meta-until (chk-meta-run removed in M-03)
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());
}

// --- Active private helper contracts ---------------------------------------

$suite->test('extractTopicId accepts only canonical positive int32 topic IDs', function () {
    foreach (array(
        '1' => 1,
        '2147483647' => 2147483647,
        '0' => null,
        '-1' => null,
        '01' => null,
        '+1' => null,
        '2147483648' => null,
        '%201' => null,
        '1%20' => null,
        '1&t[]=1' => null,
    ) as $query => $expected) {
        $actual = strictInvoke('RuTrackerCheckImpl', 'extractTopicId', array(
            'https://rutracker.org/forum/viewtopic.php?t=' . $query,
        ));
        strictAssertSame($expected, $actual, 'topic query t=' . $query);
    }
});

$suite->test('layer1Verdict maps the multicall row plus d.get_message through RuTrackerDetector', function () use ($hash) {
    ruTrackerChecker::reset();
    hQueueLayer1(array(hAliveRow()), '');
    strictAssertSame('alive', strictInvoke('RuTrackerCheckImpl', 'layer1Verdict', array($hash)), 'alive row');

    ruTrackerChecker::reset();
    hQueueLayer1(array(hCandidateRow()), 'Tracker: [Failure reason "unregistered"]');
    strictAssertSame('candidate', strictInvoke('RuTrackerCheckImpl', 'layer1Verdict', array($hash)),
        'candidate row, non-transport message');

    ruTrackerChecker::reset();
    hQueueLayer1(array(hCandidateRow()), 'Tracker: [Could not resolve hostname]');
    strictAssertSame('transport', strictInvoke('RuTrackerCheckImpl', 'layer1Verdict', array($hash)),
        'transport message');
});

$suite->test('layer1Verdict treats a failed XMLRPC request as retryable, not as "none"', function () use ($hash) {
    ruTrackerChecker::reset();
    // Nothing queued -> the double answers with a fault.
    strictAssertSame('transport', strictInvoke('RuTrackerCheckImpl', 'layer1Verdict', array($hash)),
        'a local RPC failure must not be mistaken for a disabled tracker row');
});

// Two rows, one guard: both replies are refused because their LENGTH
// contradicts the tracker count they declare, at the single
// `count($values) !== $expectedValues` return. Every other 'transport' verdict
// in this file leaves layer1Verdict() at a different statement -- the RPC
// fault, the count that is not canonical, the message that is not a string,
// the row URL that is not a string, the counter that will not parse -- so each
// of those stays a case of its own rather than joining this table.
$suite->test('layer1Verdict rejects a reply whose length contradicts its declared tracker count', function () use ($hash) {
    foreach (array(
        'a positively parsed reply cut after four scalars' => array(
            hTruncatedLayer1Reply(),
            'A positive transport status cannot make an incomplete tracker projection authoritative',
        ),
        'a count of one followed immediately by the message' => array(
            array(1, ''),
            'A count of one followed immediately by a message is missing its declared tracker row',
        ),
    ) as $label => $case) {
        ruTrackerChecker::reset();
        hQueueRawLayer1($case[0]);

        strictAssertSame(
            'transport',
            strictInvoke('RuTrackerCheckImpl', 'layer1Verdict', array($hash)),
            $label . ': ' . $case[1]
        );
    }
});

$suite->test('layer1Verdict rejects a noncanonical declared tracker count', function () use ($hash) {
    $cases = array(
        'leading zero' => '01',
        'leading plus' => '+1',
        'float' => 1.0,
        'object' => new stdClass(),
    );
    foreach ($cases as $label => $trackerCount) {
        ruTrackerChecker::reset();
        hQueueRawLayer1(array($trackerCount, ''));
        strictAssertSame(
            'transport',
            strictInvoke('RuTrackerCheckImpl', 'layer1Verdict', array($hash)),
            $label . ': the row-count command must contribute a canonical nonnegative integer'
        );
    }
});

$suite->test('download_torrent keeps a successful truncated layer1 reply retryable', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    hQueueTopicKnown($topicId);
    hQueueRawLayer1(hTruncatedLayer1Reply());
    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(
        ruTrackerChecker::STE_CANT_REACH_TRACKER,
        $result,
        'A truncated local RPC answer must retry instead of permanently stamping STE_NOT_NEED'
    );
    strictAssertSame(array(), Snoopy::$requests, 'No network layer runs from an incomplete local projection');
});

$suite->test('layer1Verdict rejects a non-string tracker URL', function () use ($hash) {
    ruTrackerChecker::reset();
    hQueueRawLayer1(array(1, 123, 1, 6, 0, ''));

    strictAssertSame(
        'transport',
        strictInvoke('RuTrackerCheckImpl', 'layer1Verdict', array($hash)),
        'A tracker row without the daemon URL scalar is not a complete projection'
    );
});

$suite->test('layer1Verdict rejects a non-string download message', function () use ($hash) {
    ruTrackerChecker::reset();
    hQueueRawLayer1(array(0, new stdClass()));

    strictAssertSame(
        'transport',
        strictInvoke('RuTrackerCheckImpl', 'layer1Verdict', array($hash)),
        'The final command must contribute its expected message scalar'
    );
});

$suite->test('resolveForum reads chk-forum, treating blank or non-numeric as unknown', function () use ($hash) {
    ruTrackerChecker::reset();
    hQueueForum(1106);
    strictAssertSame(1106, strictInvoke('RuTrackerCheckImpl', 'resolveForum', array($hash)), 'numeric forum id');

    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.get_custom', true, false, array(''));
    strictAssertSame(null, strictInvoke('RuTrackerCheckImpl', 'resolveForum', array($hash)), 'blank -> unknown');

    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.get_custom', true, false, array('not-a-number'));
    strictAssertSame(null, strictInvoke('RuTrackerCheckImpl', 'resolveForum', array($hash)), 'garbage -> unknown');
});

// chk-forum is the persisted RPC custom layer 3 fetches a dump BY, and those
// dump rows are what decide whether the torrent is deleted. ctype_digit() plus
// a bare (int) read "007" as the forum 7 and "0" as the forum 0 -- a dump
// fetched from a forum the stored value never named. A spelling this plugin
// could not have written names no forum, so the crawl is queued instead.
$suite->test('a non-canonical chk-forum names no forum and fetches no dump', function () use ($hash) {
    foreach (array('leading zero' => '007', 'zero' => '0', 'plus sign' => '+7',
        'negative' => '-7', 'trailing text' => '7abc', 'overflow' => '2147483648',
        'float' => '7.0', 'hex' => '0x7') as $label => $stored) {
        ruTrackerChecker::reset();
        rXMLRPCRequest::queue('d.get_custom', true, false, array($stored));
        strictAssertSame(null,
            strictInvoke('RuTrackerCheckImpl', 'resolveForum', array($hash)),
            $label . ': ' . var_export($stored, true) . ' is not a forum id');
    }

    // The control: canonical ids across the whole domain still resolve.
    foreach (array('1', '1106', '2147483647') as $stored) {
        ruTrackerChecker::reset();
        rXMLRPCRequest::queue('d.get_custom', true, false, array($stored));
        strictAssertSame((int) $stored,
            strictInvoke('RuTrackerCheckImpl', 'resolveForum', array($hash)),
            'a canonical forum id still resolves: ' . $stored);
    }
});

$suite->test('rememberTopic writes chk-topic only when it was blank', function () use ($hash, $topicId) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.get_custom', true, false, array(''));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    strictInvoke('RuTrackerCheckImpl', 'rememberTopic', array($hash, $topicId));
    $writes = rXMLRPCRequest::requestsFor('d.set_custom');
    strictAssertSame(1, count($writes), 'one write when blank');
    strictAssertSame(array($hash, 'chk-topic', (string) $topicId), $writes[0]['commands'][0]->params,
        'writes the resolved topic id');

    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.get_custom', true, false, array((string) $topicId));
    strictInvoke('RuTrackerCheckImpl', 'rememberTopic', array($hash, $topicId));
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')), 'no write when already known');
});

$suite->test('resetDeletion clears its own custom field', function () use ($hash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    strictInvoke('RuTrackerCheckImpl', 'resetDeletion', array($hash));
    strictAssertSame(array($hash, 'chk-del', ''),
        rXMLRPCRequest::requestsFor('d.set_custom')[0]['commands'][0]->params, 'chk-del cleared');
});

$suite->test('confirmDeletion increments at most once per interval and reaches STE_DELETED at the cycle cap', function () use ($hash) {
    $now = 1000000;

    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.get_custom', true, false, array(''));  // no prior chk-del
    rXMLRPCRequest::queue('d.set_custom', true, false, array());    // chk-del write
    $state = strictInvoke('RuTrackerCheckImpl', 'confirmDeletion', array($hash, $now, 3600));
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'first miss: 1/3');
    strictAssertSame(array($hash, 'chk-del', '1:' . $now),
        rXMLRPCRequest::requestsFor('d.set_custom')[0]['commands'][0]->params, 'count starts at 1');
    strictAssertSame('deleting|1/3', hMessage(0), 'the deleting token carries the cycle counter');

    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.get_custom', true, false, array('2:' . ($now - 10))); // fresh -> no increment
    rXMLRPCRequest::queue('d.get_custom', true, false, array(''));                  // no later healthy verdict
    $state = strictInvoke('RuTrackerCheckImpl', 'confirmDeletion', array($hash, $now, 3600));
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'within the interval: no increment');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')), 'chk-del is untouched inside the interval');
    strictAssertSame('deleting|2/3', hMessage(0), 'the unchanged count is restated, not advanced');

    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.get_custom', true, false, array('2:' . ($now - 7200))); // old -> increments to 3
    rXMLRPCRequest::queue('d.get_custom', true, false, array(''));                    // no later healthy verdict
    rXMLRPCRequest::queue('d.set_custom', true, false, array());                     // chk-del write
    $state = strictInvoke('RuTrackerCheckImpl', 'confirmDeletion', array($hash, $now, 3600));
    strictAssertSame(ruTrackerChecker::STE_DELETED, $state, 'third confirmation reaches the cap');
    strictAssertSame(array($hash, 'chk-del', '3:' . $now),
        rXMLRPCRequest::requestsFor('d.set_custom')[0]['commands'][0]->params, 'count reaches 3');
    strictAssertSame('deleting|3/3', hMessage(0), 'the final cycle is still the deleting token');

    foreach (ruTrackerChecker::callsFor('setMessage') as $call)
        strictAssertTrue(preg_match('/^[a-z-]+\|[^|]+$/', $call['arguments'][1]) === 1,
            'token|parameter, one separator, no prose: ' . $call['arguments'][1]);

    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'row missing from the dump for',
        'the consecutive-cycle count is logged');
    strictAssertEnglish($line, 'the deletion-progress line');
    strictAssertTrue(strpos($line, '3 of 3 consecutive cycles') !== false, 'the counts survive the move: ' . $line);
    strictAssertTrue(strpos($line, 'tracker confirming the deletion') !== false,
        'so does the tracker confirmation: ' . $line);
    strictAssertTrue(strpos($line, $hash) !== false, 'the line names the torrent');
});

// chk-del is "count:timestamp", and the count alone decides whether a torrent
// reaches STE_DELETED. Read with a bare (int) behind /^(\d+):/, "03" was the
// count 3 -- a non-canonical spelling this plugin never writes, arriving at the
// threshold with fewer real cycles behind it than the count claims. A count
// that cannot be believed restarts rather than counting toward a deletion.
$suite->test('a non-canonical deletion counter reaches no deletion verdict', function () use ($hash) {
    $now = 1000000;
    $GLOBALS['rutrackerDeleteCycles'] = 3;
    foreach (array('leading zero' => '03:1000', 'padded count' => ' 3:1000',
        'padded stamp' => '3: 1000', 'leading zero stamp' => '3:01000') as $label => $stored) {
        ruTrackerChecker::reset();
        rXMLRPCRequest::queue('d.get_custom', true, false, array($stored)); // chk-del
        rXMLRPCRequest::queue('d.get_custom', true, false, array(''));      // no healthy verdict yet
        rXMLRPCRequest::queue('d.set_custom', true, false, array());        // the restart

        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
            strictInvoke('RuTrackerCheckImpl', 'confirmDeletion', array($hash, $now, 3600)),
            $label . ': a counter nobody can read is not three consecutive cycles');
        $writes = rXMLRPCRequest::requestsFor('d.set_custom');
        strictAssertSame(1, count($writes), $label . ': the counter is rewritten canonically');
        strictAssertSame(array($hash, 'chk-del', '1:' . $now), $writes[0]['commands'][0]->params,
            $label . ': and it restarts at one rather than resuming a number it could not read');
    }

    // Control: the canonical spelling of the same count still reaches the cap.
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.get_custom', true, false, array('2:1000'));
    rXMLRPCRequest::queue('d.get_custom', true, false, array(''));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    strictAssertSame(ruTrackerChecker::STE_DELETED,
        strictInvoke('RuTrackerCheckImpl', 'confirmDeletion', array($hash, $now, 3600)),
        'a canonical count still reaches the third consecutive cycle');
});

// The same counter, read by the OTHER function: deletionConfirmedOnce() is the
// durable record that a full confirmation run happened, and a settled DELETED
// verdict is held on its word alone when the probe budget is spent.
$suite->test('a non-canonical deletion counter is not a settled deletion either',
    function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    foreach (array('leading zero' => '03:1000', 'padded' => ' 3:1000',
        'plus sign' => '+3:1000') as $label => $stored) {
        hReset();
        $GLOBALS['rutrackerLayer2Enabled'] = true;
        $GLOBALS['rutrackerAnnounceCap'] = 0; // the recheck lands on an exhausted budget
        hQueueTopicKnown($topicId);
        hQueueLayer1(array(hCandidateRow()));
        hQueueForum(1106);
        Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump(99999, 0, str_repeat('C', 40)));
        rXMLRPCRequest::queue('d.set_custom', true, false, array());        // forgetForum
        rXMLRPCRequest::queue('d.get_custom', true, false, array($stored)); // chk-del

        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
            RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent),
            $label . ': an unreadable count is no proof the threshold was ever reached');
    }
});

// Finding 3: conf.php documents $updateInterval = 0 as "disable the
// scheduler", but manual batch_check.php clicks still call confirmDeletion()
// with $updateInterval * 60 -- i.e. 0. Without a floor the per-cycle cap
// never holds, so repeated manual clicks could reach STE_DELETED in three
// clicks instead of three real cycles.
$suite->test('confirmDeletion floors a zero interval so the per-cycle cap survives a disabled scheduler', function () use ($hash) {
    $now = 1000000;

    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.get_custom', true, false, array(''));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    $state = strictInvoke('RuTrackerCheckImpl', 'confirmDeletion', array($hash, $now, 0));
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'first click: 1/3');
    strictAssertSame(array($hash, 'chk-del', '1:' . $now),
        rXMLRPCRequest::requestsFor('d.set_custom')[0]['commands'][0]->params, 'count starts at 1');

    // A second click the very same instant (interval "disabled" -> 0) must
    // not be free to advance the count: the floor still applies.
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.get_custom', true, false, array('1:' . $now));
    rXMLRPCRequest::queue('d.get_custom', true, false, array(''));
    $state = strictInvoke('RuTrackerCheckImpl', 'confirmDeletion', array($hash, $now, 0));
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'same-instant re-click stays at 1/3');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')), 'chk-del remains untouched');

    // A third click 30 seconds later is still well inside the floor.
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.get_custom', true, false, array('1:' . $now));
    rXMLRPCRequest::queue('d.get_custom', true, false, array(''));
    $state = strictInvoke('RuTrackerCheckImpl', 'confirmDeletion', array($hash, $now + 30, 0));
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'still 1/3, thirty seconds later');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')), 'three clicks in a row must not touch chk-del');
});

$suite->test('classifyDump follows the design table order for a found row, and null means the row is missing', function () use ($topicId) {
    $localHash = str_repeat('A', 40);
    $newHash = str_repeat('B', 40);
    $rowOf = function ($status, $hash) use ($topicId) {
        return array($topicId => array('tor_status' => $status, 'info_hash' => $hash, 'seeders' => 1));
    };
    $classify = function ($rows) use ($topicId, $localHash) {
        return strictInvoke('RuTrackerCheckImpl', 'classifyDump', array($rows, $topicId, $localHash));
    };

    strictAssertSame(null, $classify(array()), 'missing row');
    strictAssertSame(array('verdict' => 'absorbed', 'status' => 7), $classify($rowOf(7, $newHash)), 'absorbed');
    strictAssertSame(array('verdict' => 'closed', 'status' => 1), $classify($rowOf(1, $newHash)), 'closed (1)');
    strictAssertSame(array('verdict' => 'closed', 'status' => 5), $classify($rowOf(5, $newHash)), 'closed (5)');
    strictAssertSame(array('verdict' => 'unknown', 'status' => 9), $classify($rowOf(9, $newHash)), 'ambiguous status');
    strictAssertSame(array('verdict' => 'unknown', 'status' => 42), $classify($rowOf(42, $newHash)), 'unrecognised status');
    strictAssertSame(array('verdict' => 'uptodate', 'status' => 0), $classify($rowOf(0, $localHash)), 'hash matches');
    strictAssertSame(array('verdict' => 'updated', 'status' => 0, 'newHash' => $newHash),
        $classify($rowOf(0, $newHash)), 'valid status, hash differs');
});

// --- download_torrent(): the four-layer flow, end to end --------------------

$suite->test('an unrecognised url is declined without any request', function () {
    ruTrackerChecker::reset();
    $result = RuTrackerCheckImpl::download_torrent('not a rutracker url', str_repeat('A', 40), null);
    strictAssertSame(ruTrackerChecker::STE_DECLINED, $result, 'no topic id -> not this handler');
    strictAssertSame(array(), rXMLRPCRequest::$requests, 'no request attempted');
});

$suite->test('1: layer1 alive short-circuits with no HTTP requests and resets chk-del', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hAliveRow()));
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // resetDeletion

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result, 'alive -> up to date');
    strictAssertSame(array(), Snoopy::$requests, 'layer 1 makes no HTTP requests at all');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom');
    strictAssertSame(array($hash, 'chk-del', ''), $writes[0]['commands'][0]->params, 'chk-del reset');
    strictAssertSame('', hMessage(0), 'chk-msg cleared through the checker seam');
});

// The host test that reads a TOPIC url was a fifth hand-written copy of "is
// this RuTracker?", anchored at the start of the host and unaware of
// rutracker.cc. So a comment of the form the site's own links take --
// https://www.rutracker.org/forum/viewtopic.php?t=N -- did not parse, the
// handler answered STE_NOT_NEED, and the scheduler wrote "no need to check"
// permanently for a torrent that was perfectly ordinary.
$suite->test('a topic URL on any of RuTracker\'s own hosts is read, not dismissed', function () use ($hash, $topicId, $announceUrl) {
    foreach (array(
        'https://www.rutracker.org/forum/viewtopic.php?t=' . $topicId => 'the www host the site itself links to',
        'https://rutracker.cc/forum/viewtopic.php?t=' . $topicId      => 'the .cc domain, which the detector has always known',
        'https://RuTracker.ORG/forum/viewtopic.php?t=' . $topicId     => 'and case is not a difference',
    ) as $url => $why) {
        hReset();
        hQueueTopicKnown($topicId);
        hQueueLayer1(array(hAliveRow()));
        rXMLRPCRequest::queue('d.set_custom', true, false, array()); // resetDeletion

        $torrent = new Torrent(strictTorrentRaw('release name', $announceUrl, $url));
        strictAssertSame(ruTrackerChecker::STE_UPTODATE,
            RuTrackerCheckImpl::download_torrent($url, $hash, $torrent),
            $why . ': the check runs');
    }

    // A host that merely looks like one is still refused, and refused before
    // anything is asked of the daemon. The torrent's own comment has to be a
    // look-alike too: the handler falls back to it when the URL it was handed
    // does not parse, and the ordinary fixture carries a real topic URL that
    // would rescue this case and hide what is being tested.
    hReset();
    $lookalike = 'https://rutracker.org.evil.example/forum/viewtopic.php?t=' . $topicId;
    $strangerTorrent = new Torrent(strictTorrentRaw('release name', 'http://bt.t-ru.org/ann?pk=SECRET', $lookalike));
    strictAssertSame(ruTrackerChecker::STE_DECLINED,
        RuTrackerCheckImpl::download_torrent($lookalike, $hash, $strangerTorrent),
        'a look-alike host is not a topic URL');
    strictAssertSame(array(), rXMLRPCRequest::$requests, 'and nothing is asked about it');
});

$suite->test('2: layer1 candidate + layer2 registered -> up to date via exactly one passkey-less announce', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    Snoopy::queueAny(200, hRegisteredBody());
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // resetDeletion

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result, 'registered -> up to date');
    strictAssertSame(1, count(Snoopy::$requests), 'exactly one announce request');
    strictAssertTrue(strpos(Snoopy::$requests[0][1], 'pk=') === false, 'passkey stripped from the probe URL');
    strictAssertTrue(strpos(Snoopy::$requests[0][1], 'event=stopped') !== false, 'probe carries event=stopped');

    // The operator's only window into an unattended hourly job, asserted on
    // the scenario that produced it rather than on a second run of the same
    // setup: the log is a contract too, and a replay of an identical fixture
    // proves nothing the original could not.
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'layer2 verdict=', 'the probe answer is logged');
    strictAssertEnglish($line, 'the layer-2 verdict line');
    strictAssertTrue(strpos($line, 'layer2 verdict=registered') !== false, 'the classified answer: ' . $line);
    strictAssertTrue(strpos($line, 'http=200') !== false, 'the HTTP status the answer came with: ' . $line);
    strictAssertTrue(strpos($line, 'pk=') === false, 'the probe URL never reaches the log: ' . $line);
});

$suite->test('3: layer1 candidate + layer2 unregistered + dump with a new hash begins the metadata fetch', function () use ($hash, $newHash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    rTorrent::$sendResult = $newHash;
    hQueueMetaFetchFlow($hash, $newHash, $topicId);

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_META_PENDING, $result, 'a new hash starts the metadata fetch');
    strictAssertSame(1, count(rTorrent::$magnets), 'exactly one magnet sent');
    strictAssertTrue(strpos(rTorrent::$magnets[0]['magnet'], 'magnet:?xt=urn:btih:' . $newHash) === 0,
        'magnet targets the new hash');

    // Layer 4's opening lines, including the topic id -- the only place the
    // handler-to-begin() wiring is pinned at all.
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'metafetch: begin', 'layer 4 announces itself');
    strictAssertEnglish($line, 'the layer-4 start line');
    strictAssertTrue(strpos($line, $hash) !== false, 'the old hash: ' . $line);
    strictAssertTrue(strpos($line, $newHash) !== false, 'the new hash: ' . $line);
    strictAssertTrue(strpos($line, 'topic=' . $topicId) !== false, 'the topic id: ' . $line);
    $loaded = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'loaded the metadata stub',
        'the successful load is logged');
    strictAssertEnglish($loaded, 'the layer-4 stub load line');
    strictAssertTrue(strpos($loaded, $newHash) !== false, 'the loaded stub hash: ' . $loaded);
});

$suite->test('4: dump row tor_status=7 is absorbed, with the topic id tokenised into chk-msg', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump($topicId, 7, str_repeat('C', 40)));
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // row found: resetDeletion

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_ABSORBED, $result, 'tor_status 7 -> absorbed');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom');
    strictAssertSame(1, count($writes), 'only the handler-owned chk-del reset uses XMLRPC');
    strictAssertSame('', hMessage(0), 'the stale message is cleared first');
    strictAssertSame(ruTrackerChecker::CHKMSG_ABSORBED . '|' . $topicId, hMessage(1),
        'the bare topic id, not a URL: init.js builds the link itself');
});

$suite->test('4b: a closed/duplicate dump row tokenises its tor_status and is terminal', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump($topicId, 5, str_repeat('C', 40)));
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // row found: resetDeletion

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_NOT_NEED, $result, 'tor_status 5 -> nothing to do');
    strictAssertSame(ruTrackerChecker::CHKMSG_TOPIC_STATUS . '|5', hMessage(1),
        'the raw tor_status is the only parameter');

    // Layer 3's three log lines, on the run that produced them.
    $joined = implode("\n", ruTrackerChecker::$logs);
    strictAssertTrue(strpos($joined, 'layer3 forum=1106 from the chk-forum cache') !== false,
        'the forum id\'s source: ' . $joined);
    strictAssertTrue(strpos($joined, 'layer3 dump forum=1106 fetched, 1 rows') !== false,
        'what the dump returned: ' . $joined);
    strictAssertTrue(strpos($joined, 'layer3 topic=' . $topicId . ' verdict=closed tor_status=5') !== false,
        'the classification decision: ' . $joined);
    foreach (ruTrackerChecker::$logs as $line) strictAssertEnglish($line, 'every layer-3 log line');
});

$suite->test('5: dump row with an ambiguous tor_status (9) is retried, not acted on, but still resets chk-del', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump($topicId, 9, str_repeat('C', 40)));
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // row found: resetDeletion

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result, 'ambiguous status is retried, not acted on');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom');
    strictAssertSame(array($hash, 'chk-del', ''), $writes[0]['commands'][0]->params,
        'row present -> chk-del reset even though the verdict itself is ambiguous');
    strictAssertSame('', hMessage(0), 'stale deletion-progress message cleared');
});

$suite->test('6: dump row with a matching hash is up to date and resets chk-del', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump($topicId, 0, $hash));
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // resetDeletion

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result, 'matching hash -> up to date');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom');
    strictAssertSame(array($hash, 'chk-del', ''), $writes[0]['commands'][0]->params, 'chk-del reset');
    strictAssertSame('', hMessage(0), 'chk-msg cleared');
});

$suite->test('7: missing row + tracker-confirmed + a stale chk-del count reaches STE_DELETED', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    $now = time();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    Snoopy::queueAny(200, hUnregisteredBody());
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump(99999, 0, str_repeat('C', 40))); // our topic absent
    rXMLRPCRequest::queue('d.set_custom', true, false, array());                    // forgetForum (chk-forum)
    rXMLRPCRequest::queue('d.get_custom', true, false, array('2:' . ($now - 7200))); // confirmDeletion read: old
    rXMLRPCRequest::queue('d.get_custom', true, false, array(''));                    // no later healthy verdict
    rXMLRPCRequest::queue('d.set_custom', true, false, array());                    // chk-del write

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_DELETED, $result, 'third stale-row confirmation -> deleted');
    strictAssertTrue(in_array($topicId, RuTrackerForumIndex::takeQueuePeek(), true), 'topic re-queued for forum resolution');
});

$suite->test('8: missing row + tracker-confirmed + a fresh chk-del count does not increment', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    $now = time();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    Snoopy::queueAny(200, hUnregisteredBody());
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump(99999, 0, str_repeat('C', 40)));
    rXMLRPCRequest::queue('d.get_custom', true, false, array('2:' . ($now - 10))); // fresh: less than the interval ago
    rXMLRPCRequest::queue('d.get_custom', true, false, array(''));                 // no later healthy verdict

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result, 'still pending, not deleted');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom');
    strictAssertSame(0, count($writes),
        'a manual re-check within the interval cannot advance chk-del or clear chk-forum');
    strictAssertSame('deleting|2/3', hMessage(0), 'the progress message is restated through the checker seam');
});

$suite->test('9: with layer 2 disabled a missing row can never reach STE_DELETED', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset(); // layer 2 disabled by default
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump(99999, 0, str_repeat('C', 40)));
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // forgetForum

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result, 'never deleted without an independent tracker confirmation');
    strictAssertSame(array(array('fetchComplex', RuTrackerForumIndex::DUMP_URL . '1106')), Snoopy::$requests,
        'layer 2 disabled: only the dump fetch happens, no announce attempted');
    foreach (rXMLRPCRequest::requestsFor('d.set_custom') as $write)
        strictAssertTrue($write['commands'][0]->params[1] !== 'chk-del', 'chk-del must never be touched without layer 2');
    // Diagnostics only: nothing here is worth a sentence in the UI, so
    // chk-msg is cleared (never left carrying a stale token) and the reason
    // goes to the debug log instead.
    strictAssertSame('', hMessage(0), 'the unconfirmed-miss notice clears chk-msg instead of writing prose');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs,
        'row missing from the dump, but the tracker never confirmed deletion',
        'the reason is logged, not shown');
    strictAssertTrue(strpos($line, $hash) !== false, 'the log line names the torrent');
    strictAssertEnglish($line, 'the unconfirmed-miss diagnostic');
});

$suite->test('10: an unresolved forum queues the topic for the crawl and stays retryable', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    rXMLRPCRequest::queue('d.get_custom', true, false, array('')); // resolveForum: blank

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result, 'forum unknown -> retry later');
    strictAssertSame(array($topicId), RuTrackerForumIndex::takeQueuePeek(), 'topic queued for the forum crawl');
    strictAssertSame(array(), Snoopy::$requests, 'no dump fetch is attempted without a forum id');
    strictAssertSame('', hMessage(0), 'a queued-for-sweep notice is diagnostics, so chk-msg is cleared');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'forum unknown for topic',
        'the notice is logged instead');
    strictAssertTrue(strpos($line, 'queued for a sweep') !== false,
        'the notice still says the topic was queued: ' . $line);
    strictAssertEnglish($line, 'the unknown-forum diagnostic');
});

$suite->test('11: regression -- nothing in the flow ever requests rutracker.org', function () use ($hash, $newHash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    rTorrent::$sendResult = $newHash;
    hQueueMetaFetchFlow($hash, $newHash, $topicId);

    RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertTrue(count(Snoopy::$requests) > 0, 'sanity: the flow actually made HTTP requests');
    foreach (Snoopy::$requests as $request)
        strictAssertTrue(!preg_match('/rutracker\.org/i', $request[1]), 'no request ever targets rutracker.org: ' . $request[1]);
});

$suite->test('12: layer2 enabled but the announce budget denies the probe -- no announce, no deletion, stays retryable', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    $GLOBALS['rutrackerAnnounceCap'] = 0; // cap exhausted before any probe is attempted
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump(99999, 0, str_repeat('C', 40))); // our topic absent
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // forgetForum

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result, 'budget denial cannot conclude deletion, only retry');
    strictAssertSame(array(array('fetchComplex', RuTrackerForumIndex::DUMP_URL . '1106')), Snoopy::$requests,
        'budget denied the probe: only the dump fetch happens, no announce request is issued');
    foreach (rXMLRPCRequest::requestsFor('d.set_custom') as $write)
        strictAssertTrue($write['commands'][0]->params[1] !== 'chk-del', 'chk-del must never be touched without an independent tracker confirmation');
});

// init.js appends chk-msg to whatever chk-state is current, so a token an
// earlier cycle stored describes a verdict that may no longer be the one on
// screen. The sharpest pairing is "No need" under "the topic is missing from
// the forum list; confirmation cycle 2/3" -- two statements that cannot both
// be about the same torrent.
$suite->test('an exit that changes the verdict clears the sentence that explained the old one', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    foreach (array(
        // No RuTracker row at all: not a candidate, nothing to check.
        'the torrent is no longer a candidate' => array(
            'rows' => array(array('url' => 'http://other.example/ann', 'enabled' => 1, 'failed' => 0, 'success' => 5)),
            'state' => ruTrackerChecker::STE_NOT_NEED,
        ),
        // rTorrent itself blames the network.
        'the tracker cannot be reached at all' => array(
            'rows' => array(hCandidateRow()),
            'message' => 'Tracker: [Could not connect to server]',
            'state' => ruTrackerChecker::STE_CANT_REACH_TRACKER,
        ),
    ) as $label => $case) {
        hReset();
        hQueueTopicKnown($topicId);
        hQueueLayer1($case['rows'], isset($case['message']) ? $case['message'] : '');

        strictAssertSame($case['state'],
            RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent),
            $label . ': the verdict changes');
        $writes = rXMLRPCRequest::requestsFor('d.set_custom');
        strictAssertSame(0, count($writes), $label . ': no handler-owned custom write follows');
        strictAssertSame('', hMessage(0),
            $label . ': the stale sentence is cleared through the checker seam');
    }
});

$suite->test('13: layer1 candidate + layer2 failure reason other than the measured text stays inconclusive: no deletion progress, layer 3 never reached', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    // Same shape as a real "unregistered" answer (bencode dict, non-empty
    // failure reason) but not RuTrackerAnnounce::UNREGISTERED_FAILURE_REASON
    // -- e.g. a rate-limit notice. classify() must call this 'uncertain'.
    $rateLimited = 'Too many requests, slow down';
    Snoopy::queueAny(200, 'd14:failure reason' . strlen($rateLimited) . ':' . $rateLimited . 'e');

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
        'an unmatched failure reason cannot confirm deregistration, only retry');
    strictAssertSame(1, count(Snoopy::$requests), 'only the announce probe runs; the forum dump (layer 3) is never fetched');
    strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.set_custom'),
        'chk-del/chk-msg are never touched -- an inconclusive layer 2 cannot contribute to a deletion verdict');
});

// --- The superseded short-circuit -------------------------------------------
//
// "The topic's current version is already in the client" can never stop being
// true by itself, yet the full chain used to re-run every cycle: an announce
// probe out of the host's budget, plus a forum dump fetch, forever. The
// recorded chk-msg token is the record, and one existence probe re-verifies it.

$suite->test('14: a recorded superseded token short-circuits the whole chain with one existence probe', function () use ($hash, $newHash, $oldTorrent, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    hQueueSuperseded($newHash);
    ruTrackerChecker::queueResult('torrentExists', true); // the successor is still there

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_NOT_NEED, $result, 'settled: still nothing to replace');
    strictAssertSame(array(), Snoopy::$requests, 'no announce probe, no dump fetch -- no HTTP at all');
    $keys = array_map(function ($request) { return $request['key']; }, rXMLRPCRequest::$requests);
    strictAssertSame(array('d.get_custom|d.get_custom'), $keys,
        'only the token read reaches the XMLRPC transport');
    strictAssertSame(array($newHash), ruTrackerChecker::callsFor('torrentExists')[0]['arguments'],
        'the successor hash is handed to the existence seam once');
    strictAssertSame(array(), ruTrackerChecker::callsFor('setMessage'), 'a settled torrent is not rewritten');
});

// check.php's run() writes STE_INPROGRESS immediately before dispatching, so
// STE_INPROGRESS is the state this handler actually sees in production; the
// short-circuit is worthless if it only recognises the stored verdict.
$suite->test('14b: the short-circuit also fires under the in-flight STE_INPROGRESS marker', function () use ($hash, $newHash, $oldTorrent, $topicUrl) {
    hReset();
    hQueueSuperseded($newHash, ruTrackerChecker::STE_INPROGRESS);
    ruTrackerChecker::queueResult('torrentExists', true);

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_NOT_NEED, $result, 'the check-in-progress marker is not another verdict');
    strictAssertSame(array(), Snoopy::$requests, 'still no HTTP request');
});

$suite->test('15: the short-circuit falls through once the successor is gone, clearing the stale token', function () use ($hash, $newHash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    hQueueSuperseded($newHash);
    ruTrackerChecker::queueResult('torrentExists', false);      // the user deleted the successor
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hAliveRow()));
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // resetDeletion

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result, 'the normal flow decides again');
    strictAssertSame('', hMessage(0), 'the stale superseded token is cleared before falling through');
    strictAssertSame(array($hash, ''),
        ruTrackerChecker::callsFor('setMessage')[0]['arguments'], 'cleared on the checked torrent');
});

$suite->test('16: a superseded token recorded against another state is stale and never short-circuits', function () use ($hash, $newHash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array(
        (string) ruTrackerChecker::STE_DELETED,
        ruTrackerChecker::CHKMSG_SUPERSEDED . '|' . $newHash,
    ));
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hAliveRow()));
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // resetDeletion

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result, 'another verdict has since been recorded');
    strictAssertSame(array(), ruTrackerChecker::callsFor('torrentExists'),
        'no existence probe is spent on a stale token');
});

// --- The per-candidate debug log --------------------------------------------
//
// With the debug flag on, one candidate's cycle must be explainable from the
// log alone: which layer decided what, and on what evidence. Every line here is
// English (the log is the maintainer's, not the torrent owner's) and every line
// is per-candidate -- the healthy majority never reaches any of them, which is
// what keeps the log from drowning in hundreds of lines an hour.


$suite->test('18: a candidate logs layer 1\'s verdict together with the counters it came from, and never its passkey', function () use ($hash) {
    ruTrackerChecker::reset();
    hQueueLayer1(array(hAliveRow()), '');
    strictAssertSame('alive', strictInvoke('RuTrackerCheckImpl', 'layer1Verdict', array($hash)), 'healthy row');
    strictAssertSame(array(), ruTrackerChecker::$logs,
        'the healthy majority logs nothing at all -- hundreds of lines an hour would bury everything else');

    ruTrackerChecker::reset();
    hQueueLayer1(array(hCandidateRow('bt.t-ru.org', 6)), '');
    strictAssertSame('candidate', strictInvoke('RuTrackerCheckImpl', 'layer1Verdict', array($hash)), 'candidate row');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'layer1 verdict=candidate',
        'a candidate logs its verdict');
    strictAssertEnglish($line, 'the layer-1 verdict line');
    strictAssertTrue(strpos($line, 'failed=6') !== false && strpos($line, 'success=0') !== false
        && strpos($line, 'enabled=1') !== false, 'the counters the verdict came from: ' . $line);
    strictAssertTrue(strpos($line, 'bt.t-ru.org') !== false, 'the announce host is named: ' . $line);
    // hCandidateRow()'s URL carries "?pk=x": the host is diagnostics, the
    // passkey never is.
    strictAssertTrue(strpos($line, 'pk=') === false, 'a tracker URL (and its passkey) never reaches the log: ' . $line);
});

$suite->test('20: layer 2 names which of its three skip reasons applied', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    // (a) switched off in the configuration.
    hReset();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    rXMLRPCRequest::queue('d.get_custom', true, false, array('')); // resolveForum: blank, stop here
    RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'layer2 skipped:', 'a skip is logged');
    strictAssertEnglish($line, 'the layer-2 skip line');
    strictAssertTrue(strpos($line, 'disabled in the configuration') !== false, 'reason (a): ' . $line);

    // (b) the per-host cap is exhausted.
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    $GLOBALS['rutrackerAnnounceCap'] = 0;
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    rXMLRPCRequest::queue('d.get_custom', true, false, array(''));
    RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'layer2 skipped:', 'a skip is logged');
    strictAssertEnglish($line, 'the layer-2 skip line');
    strictAssertTrue(strpos($line, 'announce cap') !== false && strpos($line, 'exhausted') !== false,
        'reason (b): ' . $line);

    // (c) a 403 cooldown is still running for this host. recordOutcome()
    // with got403 = true is what installs it, exactly as a real 403 would.
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    RuTrackerAnnounce::reserveProbe('bt.t-ru.org', time(), PHP_INT_MAX, 3600);
    RuTrackerAnnounce::recordOutcome('bt.t-ru.org', time(), 403);
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    rXMLRPCRequest::queue('d.get_custom', true, false, array(''));
    RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'layer2 skipped:', 'a skip is logged');
    strictAssertEnglish($line, 'the layer-2 skip line');
    strictAssertTrue(strpos($line, 'cooldown') !== false, 'reason (c): ' . $line);
    strictAssertSame(array(), Snoopy::$requests, 'a cooldown really does stop the probe');
});

$suite->test('22: layer 3 reports an unavailable dump and a missing row distinctly', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    // Unavailable: a non-200 dump fetch tells layer 3 nothing at all.
    hReset();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 503, '');

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent), 'an unreadable dump is retryable');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'layer3 dump forum=1106',
        'the unavailable dump is logged');
    strictAssertTrue(strpos($line, 'unavailable') !== false, 'named as unavailable: ' . $line);
    strictAssertSame(array(), strictLogsMatching(ruTrackerChecker::$logs, 'layer3 topic='),
        'no classification is claimed for a dump that was never read');

    // Present but missing our row: that IS a classification outcome.
    hReset();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump(99999, 0, str_repeat('C', 40)));
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // forgetForum

    RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'layer3 topic=' . $topicId,
        'the missing row is logged as a classification outcome');
    strictAssertTrue(strpos($line, 'row missing from the dump') !== false, 'named as missing: ' . $line);
    strictAssertEnglish($line, 'the missing-row classification line');
});

// A stopped torrent never announces, so its tracker counters stay at zero and
// layer 1 answers 'cold' -- it has nothing to judge by. That is not the same as
// a tracker that could not be reached, and it must not overwrite the verdict the
// torrent already carries: updatepass.php has always skipped cold rows without
// writing state (see its "no request-worthy signal yet" branch), while a manual
// check went through this handler and turned every stopped torrent's status into
// "error accessing the tracker" -- permanently, because a stopped torrent is not
// in the seeding view the hourly cycle walks.
$suite->test('a cold torrent keeps its previous verdict instead of being reported unreachable',
    function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hColdRow()));

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_UNCHANGED, $result,
        'cold means "no data to judge by", so the stored verdict must be left alone');
    strictAssertSame(array(), Snoopy::$requests, 'a cold verdict costs no HTTP request');
});


// Layer 2's verdict is only meaningful when the announce it probes IS
// RuTracker's. The handler is dispatched by the topic comment and layer 1
// judges the t-ru tracker ROW, so a torrent whose primary announce belongs to
// another tracker gets this far too -- probing that host would both bother a
// tracker that never asked for it and let its answer stand in for RuTracker's.
$suite->test('a chk-forum that could not be READ queues nothing: it is not the same as unset', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    // resolveForum() answered null for "unset", "malformed" and "the read
    // failed" alike, and layer 3 acts on null by queueing the topic for a
    // tracker-wide forum walk. So one transient RPC failure spent a crawl of
    // the whole tracker on a torrent whose forum was very probably cached.
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = false;
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    rXMLRPCRequest::queue('d.get_custom', false, false, array());  // resolveForum: the read fails

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent),
        'nothing was learned, so the row stays retryable');
    strictAssertSame(array(), RuTrackerForumIndex::takeQueuePeek(),
        'and no crawl is queued on the strength of a read that did not happen');
    strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.set_custom'),
        'the stored sentence stands: an unread field explains nothing');
});

$suite->test('the configured announce cap is clamped ON THE WAY INTO the budget, not only in the helper', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    // The round that added RuTrackerAnnounce::probeCap() first shipped it as
    // dead code: the helper existed, its own unit test passed, and layer 2
    // still handed reserveProbe() the raw global. So this case drives the
    // PRODUCTION path -- an absurd configured cap plus a window already at the
    // real ceiling must be refused, which can only happen if the clamp is
    // actually wired in.
    $tmp = sys_get_temp_dir() . '/chk-capwire-' . bin2hex(random_bytes(4));
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);
    @mkdir($tmp, 0777, true);
    $previous = isset($GLOBALS['rutrackerAnnounceCap']) ? $GLOBALS['rutrackerAnnounceCap'] : null;
    try {
        hReset();
        $GLOBALS['rutrackerLayer2Enabled'] = true;
        $GLOBALS['rutrackerAnnounceCap'] = 100000;
        // The host has already spent the ceiling this window.
        RuTrackerState::save('announce', array('bt.t-ru.org' => array(
            'window_start' => time(), 'window_count' => RuTrackerAnnounce::PROBE_CAP_MAX,
        )));
        hQueueTopicKnown($topicId);
        hQueueLayer1(array(hCandidateRow()));
        rXMLRPCRequest::queue('d.get_custom', true, false, array(''));  // resolveForum: blank, stop after layer 2

        RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

        strictAssertSame(0, count(Snoopy::$requests),
            'no announce goes out: the budget is judged on the clamped cap, not the configured one');
    } finally {
        if ($previous === null) unset($GLOBALS['rutrackerAnnounceCap']);
        else $GLOBALS['rutrackerAnnounceCap'] = $previous;
        strictRemoveTree($tmp);
    }
});

$suite->test('layer 2 refuses a host that merely looks like RuTracker\'s', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    // The broad detector may classify the row as a candidate, but the URL
    // selector must drop it before layer 2 constructs any outgoing action.
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow('rutracker.evil.example')));
    rXMLRPCRequest::queue('d.get_custom', true, false, array('')); // resolveForum: blank, stop here

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
        'no layer may conclude anything from a look-alike host');
    strictAssertSame(array(), Snoopy::$requests, 'and nothing at all is sent to it');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'layer2 skipped:', 'the skip is logged');
    strictAssertEnglish($line, 'the layer-2 skip line');
    strictAssertTrue(strpos($line, 'no announce host') !== false,
        'the reason records that no canonical row crossed the URL boundary: ' . $line);
});

$suite->test('layer 2 accepts RuTracker\'s own host written as a fully qualified name', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    // 'bt.t-ru.org.' with the DNS root dot is the same host, and a resolver
    // that hands it back that way is not doing anything unusual. The anchored
    // pattern ends at '$', so the trailing dot has to be normalised away before
    // it is applied -- layer 1 did that and these two outgoing gates did not,
    // which made one torrent RuTracker's for the purpose of being CLASSIFIED
    // and a stranger for the purpose of being CHECKED: layer 2 skipped, and
    // metafetch refused to build the magnet.
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow('bt.t-ru.org.')));
    Snoopy::queueAny(200, hRegisteredBody());
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // resetDeletion

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result, 'the probe runs and settles the topic');
    strictAssertSame(1, count(Snoopy::$requests), 'exactly one announce request, to the real host');
    strictAssertLogsClean(ruTrackerChecker::$logs, 'layer2 skipped:',
        'and no skip is recorded for a host that is RuTracker\'s');
});

$suite->test('layer 2 probes the torrent\'s RuTracker row, not whatever tracker happens to be primary', function () use ($hash, $topicId, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    // A torrent whose PRIMARY announce is a third-party tracker, with
    // RuTracker further down its announce-list. announce() would hand layer 2
    // the stranger's URL -- proving nothing about the topic, and asking a
    // tracker that never heard of it.
    $foreignPrimary = new Torrent(strictTorrentRaw('release name', 'http://tracker.example.org/ann?pk=SECRET', $topicUrl));
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    Snoopy::queueAny(200, hRegisteredBody());
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // resetDeletion

    strictAssertSame(ruTrackerChecker::STE_UPTODATE,
        RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $foreignPrimary),
        'the RuTracker row answered, so the topic is up to date');
    strictAssertSame(1, count(Snoopy::$requests), 'exactly one probe');
    strictAssertTrue(strpos(Snoopy::$requests[0][1], 'bt.t-ru.org') !== false,
        'and it went to the RuTracker row: ' . Snoopy::$requests[0][1]);
    strictAssertTrue(strpos(Snoopy::$requests[0][1], 'tracker.example.org') === false,
        'never to the primary announce');
});

// The only place a real 403 installs the persisted cooldown is the recordOutcome
// call in download_torrent() -- `(int) $client->status === 403`. Test 20's (c)
// installs the cooldown by calling recordOutcome(403) directly, which pins the
// budget but not this wiring: hardcoding got403=false there would still pass
// it. Drive an actual 403 response through the handler instead.
$suite->test('a real 403 answer from the tracker installs the persisted cooldown', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    Snoopy::queueAny(403, '');
    rXMLRPCRequest::queue('d.get_custom', true, false, array('')); // resolveForum: blank, stop here

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result, 'a 403 concludes nothing');
    strictAssertSame('cooldown', RuTrackerAnnounce::probeDecision('bt.t-ru.org', time(), 10, 3600),
        'the 403 landed in the persisted cooldown, exactly as recordOutcome(403) would');
    $state = RuTrackerState::load('announce');
    strictAssertTrue((int) $state['bt.t-ru.org']['cooldown_until'] > time(),
        'and the persisted announce state carries cooldown_until in the future');
});


// The other half of verdict stability: on the recheck, a probe the BUDGET
// refused proves nothing, and must not cost a fully-confirmed deletion its
// verdict. chk-del is the durable record that the threshold was reached.
$suite->test('a spent probe budget cannot downgrade a fully-confirmed DELETED verdict', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    $GLOBALS['rutrackerAnnounceCap'] = 0; // the recheck lands on an exhausted budget
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump(99999, 0, str_repeat('C', 40))); // row still missing
    rXMLRPCRequest::queue('d.set_custom', true, false, array());          // forgetForum
    rXMLRPCRequest::queue('d.get_custom', true, false, array('3:1000'));  // chk-del: confirmed to the threshold

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_DELETED, $result,
        'the settled verdict stands: only a probe that actually ran may move it');
    strictAssertSame(0, count(ruTrackerChecker::callsFor('setMessage')),
        'and its deleting|3/3 message is left in place, not blanked');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'verdict stands', 'the hold is logged');
    strictAssertEnglish($line, 'the verdict-stands line');

    // Control: the same denial with the deletion NOT yet fully confirmed
    // still reports "can't reach" -- the shortcut is for settled rows only.
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    $GLOBALS['rutrackerAnnounceCap'] = 0;
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump(99999, 0, str_repeat('C', 40)));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());          // forgetForum
    rXMLRPCRequest::queue('d.get_custom', true, false, array('2:1000'));  // one confirmation short

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent),
        'an unconfirmed deletion is still just unreachable');
});

// The successor hash arrives from the forum dump -- off the network -- and
// from there it becomes a magnet target and a torrent the client is asked to
// load. Anything that is not a hash must not become a verdict.
$suite->test('a dump row whose info_hash is not a hash starts no metadata fetch', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    foreach (array('', 'not-a-hash', str_repeat('F', 39), str_repeat('F', 41),
                   'magnet:?xt=urn:btih:' . str_repeat('F', 40)) as $garbage) {
        hReset();
        hQueueTopicKnown($topicId);
        hQueueLayer1(array(hCandidateRow()));
        hQueueForum(1106);
        Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump($topicId, 0, $garbage));
        rXMLRPCRequest::queue('d.set_custom', true, false, array()); // resetDeletion

        $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
            'an unusable hash is "try again later", never a replacement: ' . var_export($garbage, true));
        strictAssertSame(0, count(rTorrent::$magnets),
            'and nothing is loaded from it: ' . var_export($garbage, true));
    }
});

// chk-forum is what lets layer 3 fetch the dump at all. Clearing it on a
// missing row -- the very case the confirmation counter exists for -- meant
// the next cycle stopped at "forum unknown", the count never passed 1, and a
// genuinely deleted topic (the one no crawl can ever resolve again) could
// never reach STE_DELETED.
$suite->test('a missing row keeps the forum, so the confirmation count can actually advance', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    $GLOBALS['rutrackerDeleteCycles'] = 2;
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    Snoopy::queueAny(200, hUnregisteredBody());
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump(99999, 0, str_repeat('C', 40)));
    rXMLRPCRequest::queue('d.get_custom', true, false, array('1:1000'));  // one confirmation already, long ago
    rXMLRPCRequest::queue('d.get_custom', true, false, array(''));        // no later healthy verdict
    rXMLRPCRequest::queue('d.set_custom', true, false, array());          // chk-del -> 2

    strictAssertSame(ruTrackerChecker::STE_DELETED,
        RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent),
        'the second confirmation settles it');
    foreach (rXMLRPCRequest::requestsFor('d.set_custom') as $write)
        strictAssertTrue($write['commands'][0]->params[1] !== 'chk-forum',
            'chk-forum is never cleared on a missing row');
});

// A transport hiccup reading chk-del must not walk a torrent at 2 of 3 back
// to the start.
$suite->test('an unreadable deletion counter defers instead of restarting the count', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    Snoopy::queueAny(200, hUnregisteredBody());
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump(99999, 0, str_repeat('C', 40)));
    rXMLRPCRequest::queue('d.get_custom', false, false, array()); // the counter cannot be read

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent),
        'nothing is concluded from a failed read');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
        'and the counter is left exactly as it was');
    strictAssertEnglish(strictAssertOneLogMatching(ruTrackerChecker::$logs, 'counter unreadable',
        'the deferral is logged'), 'the deferral line');
});

// "N of M CONSECUTIVE cycles" rests entirely on resetDeletion() clearing the
// counter whenever a cycle comes back healthy -- and that write is one
// fire-and-forget d.set_custom. A clear that never landed left a counter for
// the next missing row to resume, so a SINGLE miss could finish a confirmation
// the tracker never gave consecutively. chk-stime is the independent record of
// the last up-to-date verdict, so a count older than it cannot be consecutive.
$suite->test('a deletion count older than the last healthy verdict is restarted, not resumed', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    foreach (array(
        // The lost-clear case: two cycles counted, then a healthy cycle whose
        // clear did not land, then the row goes missing again.
        'the healthy verdict is newer than the count' => array(
            'chkDel' => '2:1000', 'stime' => '2000', 'expect' => ruTrackerChecker::STE_CANT_REACH_TRACKER,
            'written' => '1:', 'why' => 'the run was broken, so the count starts over'),
        // The genuine run: the count is newer than the last healthy verdict.
        'the count is newer than any healthy verdict' => array(
            'chkDel' => '2:3000', 'stime' => '2000', 'expect' => ruTrackerChecker::STE_DELETED,
            'written' => '3:', 'why' => 'an unbroken run still reaches the verdict'),
        // A torrent that was never up to date has no chk-stime at all.
        'there is no healthy verdict on record' => array(
            'chkDel' => '2:1000', 'stime' => '', 'expect' => ruTrackerChecker::STE_DELETED,
            'written' => '3:', 'why' => 'an empty chk-stime invalidates nothing'),
    ) as $label => $case) {
        hReset();
        $GLOBALS['rutrackerLayer2Enabled'] = true;
        hQueueTopicKnown($topicId);
        hQueueLayer1(array(hCandidateRow()));
        Snoopy::queueAny(200, hUnregisteredBody());
        hQueueForum(1106);
        Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump(99999, 0, str_repeat('C', 40)));
        rXMLRPCRequest::queue('d.get_custom', true, false, array($case['chkDel']));   // chk-del
        rXMLRPCRequest::queue('d.get_custom', true, false, array($case['stime']));    // chk-stime
        rXMLRPCRequest::queue('d.set_custom', true, false, array());                  // the advance

        strictAssertSame($case['expect'],
            RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent),
            $label . ': ' . $case['why']);

        $counters = array();
        foreach (rXMLRPCRequest::requestsFor('d.set_custom') as $write)
            if ($write['commands'][0]->params[1] === 'chk-del')
                $counters[] = $write['commands'][0]->params[2];
        strictAssertSame(1, count($counters), $label . ': the counter is advanced once');
        strictAssertTrue(strpos($counters[0], $case['written']) === 0,
            $label . ': it now reads ' . $case['written'] . '..., saw ' . $counters[0]);
    }
});

// chk-stime is the independent record the consecutiveness guard rests on, and
// a bare (int) answered 0 for every spelling it could not read -- which is
// smaller than any real last-increment stamp, so the guard silently passed and
// the count stood. A stamp that will not read cannot prove a run consecutive.
$suite->test('a non-canonical healthy-verdict timestamp restarts the deletion count', function () use ($hash) {
    $GLOBALS['rutrackerDeleteCycles'] = 3;
    foreach (array('text' => 'garbage', 'leading zero' => '02000', 'padded' => ' 2000',
        'float' => '2000.0', 'plus sign' => '+2000', 'negative' => '-2000') as $label => $stime) {
        ruTrackerChecker::reset();
        rXMLRPCRequest::queue('d.get_custom', true, false, array('2:3000')); // chk-del at the door
        rXMLRPCRequest::queue('d.get_custom', true, false, array($stime));   // chk-stime
        rXMLRPCRequest::queue('d.set_custom', true, false, array());         // the restart

        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
            strictInvoke('RuTrackerCheckImpl', 'confirmDeletion', array($hash, 5000, 1000)),
            $label . ': an unreadable guard is never a deletion verdict');
        $writes = rXMLRPCRequest::requestsFor('d.set_custom');
        strictAssertSame(1, count($writes), $label . ': the count is rewritten');
        strictAssertSame('1:5000', $writes[0]['commands'][0]->params[2],
            $label . ': and it starts over rather than reaching the third cycle');
        strictAssertEnglish(strictAssertOneLogMatching(ruTrackerChecker::$logs, 'restarting it',
            $label . ': the restart is logged'), $label . ': the restart line');
        foreach (ruTrackerChecker::$logs as $line)
            strictAssertTrue(strpos($line, $stime) === false,
                $label . ': the unreadable value itself is never echoed back: ' . $line);
    }

    // Control: a canonical stamp older than the count leaves the run standing.
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.get_custom', true, false, array('2:3000'));
    rXMLRPCRequest::queue('d.get_custom', true, false, array('2000'));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    strictAssertSame(ruTrackerChecker::STE_DELETED,
        strictInvoke('RuTrackerCheckImpl', 'confirmDeletion', array($hash, 5000, 1000)),
        'a canonical older stamp still leaves an unbroken run alone');
});

$suite->test('an unreadable healthy-verdict timestamp cannot advance an existing deletion count', function () use ($hash) {
    $GLOBALS['rutrackerDeleteCycles'] = 3;
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.get_custom', true, false, array('2:1000')); // chk-del
    rXMLRPCRequest::queue('d.get_custom', false, false, array());       // chk-stime unreadable

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        strictInvoke('RuTrackerCheckImpl', 'confirmDeletion', array($hash, 5000, 3600)),
        'an unreadable consecutiveness guard is retryable, never a deletion verdict');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
        'the old counter is not advanced until chk-stime can be read');
});

// A verdict must not outrun its own durable record: STE_DELETED settles and
// rests for a week, while a later budget-denied cycle asks chk-del -- not this
// run's local count -- whether the deletion was ever fully confirmed.
$suite->test('a deletion verdict whose counter could not be written is deferred', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    Snoopy::queueAny(200, hUnregisteredBody());
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump(99999, 0, str_repeat('C', 40)));
    rXMLRPCRequest::queue('d.get_custom', true, false, array('2:3000'));  // chk-del, at the threshold's door
    rXMLRPCRequest::queue('d.get_custom', true, false, array('2000'));    // chk-stime, older: the run stands
    rXMLRPCRequest::queue('d.set_custom', false, false, array());         // ...and the advance is lost

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent),
        'a verdict with nothing behind it is deferred, not published');
    strictAssertEnglish(strictAssertOneLogMatching(ruTrackerChecker::$logs, 'could not be advanced',
        'the deferral is logged'), 'the deferral line');
});

// A settled DELETED verdict must survive a probe that did not run -- and the
// guard used to enumerate two of the reasons a probe can be skipped rather
// than testing "it did not run". With layer 2 switched off in the
// configuration the missing case is permanent: $trackerConfirmed can never
// become true again, so a downgraded verdict can never be re-earned, and every
// previously deleted topic costs a dump lookup every hour for ever.
$suite->test('a settled deletion survives every way a probe can fail to run', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    foreach (array(
        'the per-host cap is spent' => array('layer2' => true, 'host' => 'bt.t-ru.org', 'cap' => 0),
        'layer 2 is switched off'   => array('layer2' => false, 'host' => 'bt.t-ru.org', 'cap' => 10),
    ) as $label => $case) {
        hReset();
        $GLOBALS['rutrackerLayer2Enabled'] = $case['layer2'];
        $GLOBALS['rutrackerAnnounceCap'] = $case['cap'];
        hQueueTopicKnown($topicId);
        hQueueLayer1(array(hCandidateRow()));
        hQueueForum(1106);
        Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump(99999, 0, str_repeat('C', 40)));
        // The counter is already at the threshold: the deletion was fully
        // confirmed once, and only a probe that RAN may overturn that.
        rXMLRPCRequest::queue('d.get_custom', true, false, array('3:1000'));

        strictAssertSame(ruTrackerChecker::STE_DELETED,
            RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent),
            $label . ': the settled verdict stands rather than being re-litigated hourly');
        strictAssertSame(0, count(Snoopy::$requests) - 1,
            $label . ': and no announce probe went out to earn it');
    }
    unset($GLOBALS['rutrackerAnnounceCap']);
});

// The row being present in the dump disproves "missing", so the counter is
// stale -- but only if the clear lands. confirmDeletion()'s chk-stime safety
// net does not cover this site: setState() writes chk-stime for STE_UPTODATE
// alone, so on a closed/absorbed/updated row the stale counter would survive
// and one later missing cycle would finish a confirmation never given.
$suite->test('a present row whose stale deletion counter cannot be cleared defers', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    hQueueForum(1106);
    // tor_status 5: a closed topic -- terminal, and NOT an up-to-date verdict,
    // so nothing would write chk-stime to invalidate the counter later.
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump($topicId, 5, str_repeat('C', 40)));
    rXMLRPCRequest::queue('d.set_custom', false, false, array());   // the clear is lost

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent),
        'the verdict waits for a cycle that can actually clear the counter');
    strictAssertEnglish(strictAssertOneLogMatching(ruTrackerChecker::$logs, 'could not be cleared',
        'the deferral is logged'), 'the deferral line');
});

$suite->test('an enabled official tracker row feeds both layer 2 and layer 4 after a lookalike', function () use ($hash, $newHash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    rTorrent::$sendResult = $newHash;

    // Row 1 is a lookalike; Row 2 is the official RuTracker host
    $rows = array(
        array('http://rutracker.evil.example/announce', 1, 0, 0),
        array('http://bt.t-ru.org/announce', 1, 6, 0),
    );
    hQueueMetaFetchFlow($hash, $newHash, $topicId, 1106, $rows);

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_META_PENDING, $result,
        'the official row survives through the updated-topic metadata path');
    strictAssertTrue(count(Snoopy::$requests) >= 1, 'layer 2 issued its announce request');
    strictAssertTrue(strpos(Snoopy::$requests[0][1], 'bt.t-ru.org') !== false,
        'probe targeted official bt.t-ru.org host, not evil lookalike');
    strictAssertSame(1, count(rTorrent::$magnets), 'layer 4 sent exactly one metadata magnet');
    strictAssertTrue(strpos(rTorrent::$magnets[0]['magnet'],
        '&tr=' . rawurlencode('http://bt.t-ru.org/announce')) !== false,
        'layer 4 receives the same official row selected for layer 2');
    strictAssertTrue(strpos(rTorrent::$magnets[0]['magnet'], 'rutracker.evil.example') === false,
        'the preceding lookalike never crosses either outgoing boundary');
});

$suite->test('ruTrackerRowUrl returns empty when every enabled row is a lookalike', function () {
    $rows = array(
        array('url' => 'http://rutracker.evil.example/announce', 'enabled' => 1),
        array('url' => 'http://bt.t-ru.org.evil.example/announce', 'enabled' => 1),
    );

    strictAssertSame('', strictInvoke('RuTrackerCheckImpl', 'ruTrackerRowUrl', array($rows)),
        'no noncanonical URL may leave the row-selection boundary');
});

// The per-row counters are read through the same one canonical parser the
// declared row count is, so a non-canonical spelling of a number in range is
// still no evidence at all -- never a counter this handler invents for it.
// Malformed daemon counters are incomplete local evidence, not a candidate
// tracker verdict. The reply array(1, 'http://bt.t-ru.org/ann?pk=x', 1, '6oops',
// 0, '') that used to say so in a case of its own is the 'failed as a digits
// then letters' row below, byte for byte.
$suite->test('layer1Verdict rejects a noncanonical counter in any tracker-row column', function () use ($hash) {
    $bad = array('leading zero' => '01', 'leading plus' => '+1', 'minus zero' => '-0',
        'negative' => '-1', 'padded' => ' 1', 'trailing space' => '1 ',
        'digits then letters' => '6oops', 'decimal string' => '1.0', 'float' => 1.0,
        'bool' => true, 'null' => null, 'object' => new stdClass());
    // enabled, failed, success are values 2, 3 and 4 of a one-row reply.
    foreach (array('enabled' => 2, 'failed' => 3, 'success' => 4) as $column => $slot) {
        foreach ($bad as $label => $value) {
            $reply = array(1, 'http://bt.t-ru.org/ann?pk=x', 1, 6, 0, '');
            $reply[$slot] = $value;
            ruTrackerChecker::reset();
            hQueueRawLayer1($reply);
            strictAssertSame('transport',
                strictInvoke('RuTrackerCheckImpl', 'layer1Verdict', array($hash)),
                $column . ' as a ' . $label . ': an unreadable counter is not a verdict');
        }
    }

    // Control: the canonical int and canonical string spellings of the very
    // same reply still produce the real verdict.
    foreach (array('ints' => array(1, 'http://bt.t-ru.org/ann?pk=x', 1, 6, 0, ''),
                   'strings' => array('1', 'http://bt.t-ru.org/ann?pk=x', '1', '6', '0', '')) as $label => $reply) {
        ruTrackerChecker::reset();
        hQueueRawLayer1($reply);
        strictAssertSame('candidate',
            strictInvoke('RuTrackerCheckImpl', 'layer1Verdict', array($hash)),
            $label . ': a canonical reply still yields the real layer 1 verdict');
    }
});

$exitCode = $suite->run();
strictRemoveTree($GLOBALS['tmpState']);
strictSetPrivateStatic('RuTrackerState', 'dir', null);
exit($exitCode);
