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
 * detectAbsorbedTopic() and its helpers stay in rutracker.php, dormant
 * (design doc 4.5): the old download_torrent() no longer calls them, so the
 * only coverage they get now is the direct strictInvoke tests below.
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
    RuTrackerAnnounce::resetCycle();
    $GLOBALS['updateInterval'] = 60;
    $GLOBALS['rutrackerDeleteCycles'] = 3;
    $GLOBALS['rutrackerAnnouncePause'] = 0;
    $GLOBALS['rutrackerAnnounceCap'] = 10;
    $GLOBALS['rutrackerLayer2Enabled'] = false;
}

// Same shape as Task 4's ForumIndexTest.php fixture (duplicated locally per
// the brief: forumindex.php's own test already owns the canonical copy).
function fiDump($topicId, $status, $hash, $seeders = 7)
{
    return json_encode(array(
        'format' => array('topic_id' => array('tor_status', 'seeders', 'reg_time', 'tor_size_bytes',
            'keeping_priority', 'keepers', 'seeder_last_seen', 'info_hash', 'topic_poster', 'leechers')),
        'result' => array((string) $topicId => array($status, $seeders, 1, 2, 0, array(), 3, $hash, 4, 0)),
    ));
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

function hQueueLayer1($rows, $message = '')
{
    // Mirrors the real transport (php/xmlrpc.php): layer1Verdict() issues
    // the t.multicall and d.get_message as ONE request, so the answer is a
    // single flat list -- each row's 4 values in order, then the message.
    $flat = array();
    foreach ($rows as $row) $flat = array_merge($flat, $row);
    $flat[] = $message;
    rXMLRPCRequest::queue('t.multicall|d.get_message', true, false, $flat);
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

// The message text the fake ruTrackerChecker recorded for the Nth setMessage
// call, so token assertions read as one value rather than an XMLRPC params
// triple.
function hMessage($index)
{
    $messages = ruTrackerChecker::$messages;
    if (!isset($messages[$index])) return null;
    return $messages[$index]['message'];
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
function hQueueMetaFetchFlow($hash, $newHash, $topicId, $forumId = 1106)
{
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    Snoopy::queueAny(200, hUnregisteredBody());
    hQueueForum($forumId);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . $forumId, 200, fiDump($topicId, 0, $newHash));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());         // row found: resetDeletion
    rXMLRPCRequest::queue('d.set_custom', true, false, array());         // row found: chk-msg cleared
    rXMLRPCRequest::queue('d.hash', true, true, array());                // begin(): collision check, missing
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1)); // begin(): old torrent seeding
    rXMLRPCRequest::queue('d.get_custom', true, false, array($hash));    // begin(): wait-poll, ours
    rXMLRPCRequest::queue('d.start', true, false, array());
    // markOldTorrent: chk-meta-new, chk-meta-until AND chk-meta-run
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom', 'd.set_custom'), true, false, array());
}

function ruTopicUrl($topicId, $start = null)
{
    $url = 'https://rutracker.org/forum/viewtopic.php?t=' . $topicId;
    return $start === null ? $url : $url . '&start=' . $start;
}

function ruUserPost($topicId)
{
    return strictCp1251(
        '<table class="topic"><tbody id="post_100" class="row1"><tr>'
        . '<td class="poster_info td1"><p class="nick nick-author">ordinary-user</p>'
        . '<p class="rank_img"><img class="user-rank" alt="User"></p></td>'
        . '<td><div class="post_body"><a href="viewtopic.php?t=' . $topicId . '">other topic</a>'
        . ' Этот фильм было Поглощено вниманием зрителей.</div><!--/post_body--></td></tr></tbody></table>'
    );
}

function ruModeratorPost($topicId, $prefix = '')
{
    return strictCp1251(
        '<table class="topic">' . $prefix . '<tbody id="post_200" class="row1"><tr>'
        . '<td class="poster_info td1"><p class="nick nick-author">tracker-moderator</p>'
        . '<p class="rank_img"><img class="user-rank" alt="Moderator"></p></td>'
        . '<td><div class="post_body"><a class="postLink" href="viewtopic.php?t=' . $topicId . '">replacement</a>'
        . '<span class="post-b">Поглощено</span></div><!--/post_body--></td></tr></tbody></table>'
    );
}

// --- Dormant group: detectAbsorbedTopic() and its helpers ------------------
// Exercised directly (strictInvoke), decoupled from the retired dl.php glue
// that used to wrap them -- see the file docblock.

$suite->test('detectAbsorbedTopic resolves the single link in a final exact moderator notice', function () use ($topicId) {
    ruTrackerChecker::reset();
    Snoopy::queue(ruTopicUrl($topicId), 200, ruModeratorPost(99));
    $result = strictInvoke('RuTrackerCheckImpl', 'detectAbsorbedTopic', array(new Snoopy(), $topicId));
    strictAssertSame(99, $result, 'exact final moderator absorption notice resolves to topic 99');
});

$suite->test('detectAbsorbedTopic follows real pagination and ignores same-topic start links inside post bodies', function () use ($topicId) {
    ruTrackerChecker::reset();
    $firstPage = strictCp1251(
        '<a class="pg" href="viewtopic.php?t=' . $topicId . '&amp;start=50">2</a>'
        . '<tbody id="post_100"><tr><td><div class="post_body">'
        . '<a href="viewtopic.php?t=' . $topicId . '&amp;start=999999">stale user link</a>'
        . '</div><!--/post_body--></td></tr></tbody>'
    );
    Snoopy::queue(ruTopicUrl($topicId), 200, $firstPage);
    Snoopy::queue(ruTopicUrl($topicId, 50), 200, ruModeratorPost(99));
    $result = strictInvoke('RuTrackerCheckImpl', 'detectAbsorbedTopic', array(new Snoopy(), $topicId));
    strictAssertSame(99, $result, 'pagination followed to the real last page');
});

$suite->test('detectAbsorbedTopic returns null for an ambiguous multi-link moderator notice', function () use ($topicId) {
    ruTrackerChecker::reset();
    $ambiguous = strictCp1251(
        '<table class="topic"><tbody id="post_200" class="row1"><tr>'
        . '<td><img class="user-rank" alt="Moderator"></td><td><div class="post_body">'
        . '<a href="viewtopic.php?t=98">related</a><a href="viewtopic.php?t=99">replacement</a>'
        . '<span>Поглощено</span></div><!--/post_body--></td></tr></tbody></table>'
    );
    Snoopy::queue(ruTopicUrl($topicId), 200, $ambiguous);
    $result = strictInvoke('RuTrackerCheckImpl', 'detectAbsorbedTopic', array(new Snoopy(), $topicId));
    strictAssertSame(null, $result, 'two candidate links cannot be resolved automatically');
});

$suite->test('detectAbsorbedTopic ignores an ordinary user post containing the absorption phrase', function () use ($topicId) {
    ruTrackerChecker::reset();
    Snoopy::queue(ruTopicUrl($topicId), 200, ruUserPost(99));
    $result = strictInvoke('RuTrackerCheckImpl', 'detectAbsorbedTopic', array(new Snoopy(), $topicId));
    strictAssertSame(null, $result, 'a non-moderator post never triggers absorption detection');
});

// --- New private helpers, unit-tested directly ------------------------------

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

$suite->test('forgetForum and resetDeletion clear their own custom fields', function () use ($hash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    strictInvoke('RuTrackerCheckImpl', 'forgetForum', array($hash));
    strictAssertSame(array($hash, 'chk-forum', ''),
        rXMLRPCRequest::requestsFor('d.set_custom')[0]['commands'][0]->params, 'chk-forum cleared');

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
    rXMLRPCRequest::queue('d.set_custom', true, false, array());    // chk-msg write
    $state = strictInvoke('RuTrackerCheckImpl', 'confirmDeletion', array($hash, $now, 3600));
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'first miss: 1/3');
    strictAssertSame(array($hash, 'chk-del', '1:' . $now),
        rXMLRPCRequest::requestsFor('d.set_custom')[0]['commands'][0]->params, 'count starts at 1');
    strictAssertSame('deleting|1/3', hMessage(0), 'the deleting token carries the cycle counter');

    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.get_custom', true, false, array('2:' . ($now - 10))); // fresh -> no increment
    rXMLRPCRequest::queue('d.set_custom', true, false, array());                   // chk-msg only
    $state = strictInvoke('RuTrackerCheckImpl', 'confirmDeletion', array($hash, $now, 3600));
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'within the interval: no increment');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.set_custom')), 'only the message is written');
    strictAssertSame('deleting|2/3', hMessage(0), 'the unchanged count is restated, not advanced');

    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.get_custom', true, false, array('2:' . ($now - 7200))); // old -> increments to 3
    rXMLRPCRequest::queue('d.set_custom', true, false, array());                     // chk-del write
    rXMLRPCRequest::queue('d.set_custom', true, false, array());                     // chk-msg write
    $state = strictInvoke('RuTrackerCheckImpl', 'confirmDeletion', array($hash, $now, 3600));
    strictAssertSame(ruTrackerChecker::STE_DELETED, $state, 'third confirmation reaches the cap');
    strictAssertSame(array($hash, 'chk-del', '3:' . $now),
        rXMLRPCRequest::requestsFor('d.set_custom')[0]['commands'][0]->params, 'count reaches 3');
    strictAssertSame('deleting|3/3', hMessage(0), 'the final cycle is still the deleting token');
});

// Every chk-msg the handler writes is a "<token>|<parameter>" pair (check.php's
// CHKMSG_* constants), never prose: the sentence lives in the browser's own
// language file, so the exact emitted form is part of the contract.
$suite->test('the emitted chk-msg tokens carry exactly one parameter and no prose', function () use ($hash) {
    $now = 1000000;

    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.get_custom', true, false, array(''));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    strictInvoke('RuTrackerCheckImpl', 'confirmDeletion', array($hash, $now, 3600));
    strictAssertSame(ruTrackerChecker::CHKMSG_DELETING . '|1/3', hMessage(0), 'deleting|N/M');

    foreach (ruTrackerChecker::$messages as $written)
        strictAssertTrue(preg_match('/^[a-z-]+\|[^|]+$/', $written['message']) === 1,
            'token|parameter, one separator, no prose: ' . $written['message']);
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
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    $state = strictInvoke('RuTrackerCheckImpl', 'confirmDeletion', array($hash, $now, 0));
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'first click: 1/3');
    strictAssertSame(array($hash, 'chk-del', '1:' . $now),
        rXMLRPCRequest::requestsFor('d.set_custom')[0]['commands'][0]->params, 'count starts at 1');

    // A second click the very same instant (interval "disabled" -> 0) must
    // not be free to advance the count: the floor still applies.
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.get_custom', true, false, array('1:' . $now));
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // message only
    $state = strictInvoke('RuTrackerCheckImpl', 'confirmDeletion', array($hash, $now, 0));
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'same-instant re-click stays at 1/3');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.set_custom')), 'only the message is rewritten, chk-del untouched');

    // A third click 30 seconds later is still well inside the floor.
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.get_custom', true, false, array('1:' . $now));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    $state = strictInvoke('RuTrackerCheckImpl', 'confirmDeletion', array($hash, $now + 30, 0));
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'still 1/3, thirty seconds later');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.set_custom')), 'three clicks in a row must not reach STE_DELETED');
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

$suite->test('an unrecognised url returns STE_NOT_NEED without any request', function () {
    ruTrackerChecker::reset();
    $result = RuTrackerCheckImpl::download_torrent('not a rutracker url', str_repeat('A', 40), null);
    strictAssertSame(ruTrackerChecker::STE_NOT_NEED, $result, 'no topic id -> not needed');
    strictAssertSame(array(), rXMLRPCRequest::$requests, 'no request attempted');
});

$suite->test('1: layer1 alive short-circuits with no HTTP requests and resets chk-del', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hAliveRow()));
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // resetDeletion
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // chk-msg cleared

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result, 'alive -> up to date');
    strictAssertSame(array(), Snoopy::$requests, 'layer 1 makes no HTTP requests at all');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom');
    strictAssertSame(array($hash, 'chk-del', ''), $writes[0]['commands'][0]->params, 'chk-del reset');
    strictAssertSame(array($hash, 'chk-msg', ''), $writes[1]['commands'][0]->params, 'chk-msg cleared');
});

$suite->test('2: layer1 candidate + layer2 registered -> up to date via exactly one passkey-less announce', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    Snoopy::queueAny(200, hRegisteredBody());
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // resetDeletion
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // chk-msg cleared

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result, 'registered -> up to date');
    strictAssertSame(1, count(Snoopy::$requests), 'exactly one announce request');
    strictAssertTrue(strpos(Snoopy::$requests[0][1], 'pk=') === false, 'passkey stripped from the probe URL');
    strictAssertTrue(strpos(Snoopy::$requests[0][1], 'event=stopped') !== false, 'probe carries event=stopped');
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
});

$suite->test('4: dump row tor_status=7 is absorbed, with the topic id tokenised into chk-msg', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump($topicId, 7, str_repeat('C', 40)));
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // row found: resetDeletion
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // row found: chk-msg cleared
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // absorbed message

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_ABSORBED, $result, 'tor_status 7 -> absorbed');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom');
    strictAssertSame(3, count($writes), 'chk-del reset, chk-msg cleared, then the absorbed message');
    strictAssertSame(ruTrackerChecker::CHKMSG_ABSORBED . '|' . $topicId, $writes[2]['commands'][0]->params[2],
        'the bare topic id, not a URL: init.js builds the link itself');
});

$suite->test('4b: a closed/duplicate dump row tokenises its tor_status and is terminal', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump($topicId, 5, str_repeat('C', 40)));
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // row found: resetDeletion
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // row found: chk-msg cleared
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // topic-status message

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_NOT_NEED, $result, 'tor_status 5 -> nothing to do');
    strictAssertSame(ruTrackerChecker::CHKMSG_TOPIC_STATUS . '|5',
        rXMLRPCRequest::requestsFor('d.set_custom')[2]['commands'][0]->params[2],
        'the raw tor_status is the only parameter');
});

$suite->test('5: dump row with an ambiguous tor_status (9) is retried, not acted on, but still resets chk-del', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump($topicId, 9, str_repeat('C', 40)));
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // row found: resetDeletion
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // row found: chk-msg cleared

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result, 'ambiguous status is retried, not acted on');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom');
    strictAssertSame(array($hash, 'chk-del', ''), $writes[0]['commands'][0]->params,
        'row present -> chk-del reset even though the verdict itself is ambiguous');
    strictAssertSame(array($hash, 'chk-msg', ''), $writes[1]['commands'][0]->params,
        'stale deletion-progress message cleared');
});

$suite->test('6: dump row with a matching hash is up to date and resets chk-del', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump($topicId, 0, $hash));
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // resetDeletion
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // chk-msg cleared

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result, 'matching hash -> up to date');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom');
    strictAssertSame(array($hash, 'chk-del', ''), $writes[0]['commands'][0]->params, 'chk-del reset');
    strictAssertSame(array($hash, 'chk-msg', ''), $writes[1]['commands'][0]->params, 'chk-msg cleared');
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
    rXMLRPCRequest::queue('d.set_custom', true, false, array());                    // chk-del write
    rXMLRPCRequest::queue('d.set_custom', true, false, array());                    // chk-msg write

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
    rXMLRPCRequest::queue('d.set_custom', true, false, array());                  // forgetForum
    rXMLRPCRequest::queue('d.get_custom', true, false, array('2:' . ($now - 10))); // fresh: less than the interval ago
    rXMLRPCRequest::queue('d.set_custom', true, false, array());                  // chk-msg only

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result, 'still pending, not deleted');
    strictAssertSame(2, count(rXMLRPCRequest::requestsFor('d.set_custom')),
        'only chk-forum and chk-msg were written; a manual re-check within the interval cannot advance chk-del');
});

$suite->test('9: with layer 2 disabled a missing row can never reach STE_DELETED', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset(); // layer 2 disabled by default
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump(99999, 0, str_repeat('C', 40)));
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // forgetForum
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // chk-msg

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
    rXMLRPCRequest::queue('d.set_custom', true, false, array());   // chk-msg

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
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // chk-msg

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result, 'budget denial cannot conclude deletion, only retry');
    strictAssertSame(array(array('fetchComplex', RuTrackerForumIndex::DUMP_URL . '1106')), Snoopy::$requests,
        'budget denied the probe: only the dump fetch happens, no announce request is issued');
    foreach (rXMLRPCRequest::requestsFor('d.set_custom') as $write)
        strictAssertTrue($write['commands'][0]->params[1] !== 'chk-del', 'chk-del must never be touched without an independent tracker confirmation');
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
// recorded chk-msg token is the record, and one d.hash probe re-verifies it.

$suite->test('14: a recorded superseded token short-circuits the whole chain with one existence probe', function () use ($hash, $newHash, $oldTorrent, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    hQueueSuperseded($newHash);
    rXMLRPCRequest::queue('d.hash', true, false, array($newHash)); // the successor is still there

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_NOT_NEED, $result, 'settled: still nothing to replace');
    strictAssertSame(array(), Snoopy::$requests, 'no announce probe, no dump fetch -- no HTTP at all');
    $keys = array_map(function ($request) { return $request['key']; }, rXMLRPCRequest::$requests);
    strictAssertSame(array('d.get_custom|d.get_custom', 'd.hash'), $keys,
        'exactly two requests: the token read and the existence probe');
    strictAssertSame(array(), ruTrackerChecker::$messages, 'a settled torrent is not rewritten');
});

// check.php's run() writes STE_INPROGRESS immediately before dispatching, so
// STE_INPROGRESS is the state this handler actually sees in production; the
// short-circuit is worthless if it only recognises the stored verdict.
$suite->test('14b: the short-circuit also fires under the in-flight STE_INPROGRESS marker', function () use ($hash, $newHash, $oldTorrent, $topicUrl) {
    hReset();
    hQueueSuperseded($newHash, ruTrackerChecker::STE_INPROGRESS);
    rXMLRPCRequest::queue('d.hash', true, false, array($newHash));

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_NOT_NEED, $result, 'the check-in-progress marker is not another verdict');
    strictAssertSame(array(), Snoopy::$requests, 'still no HTTP request');
});

$suite->test('15: the short-circuit falls through once the successor is gone, clearing the stale token', function () use ($hash, $newHash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    hQueueSuperseded($newHash);
    rXMLRPCRequest::queue('d.hash', true, true, array());        // the user deleted the successor
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // stale token cleared
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hAliveRow()));
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // resetDeletion
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // chk-msg cleared

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result, 'the normal flow decides again');
    strictAssertSame('', hMessage(0), 'the stale superseded token is cleared before falling through');
    strictAssertSame(array($hash, 'chk-msg', ''),
        rXMLRPCRequest::requestsFor('d.set_custom')[0]['commands'][0]->params, 'cleared on the checked torrent');
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
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // chk-msg cleared

    $result = RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result, 'another verdict has since been recorded');
    strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.hash'), 'no existence probe is spent on a stale token');
});

// --- The per-candidate debug log --------------------------------------------
//
// With the debug flag on, one candidate's cycle must be explainable from the
// log alone: which layer decided what, and on what evidence. Every line here is
// English (the log is the maintainer's, not the torrent owner's) and every line
// is per-candidate -- the healthy majority never reaches any of them, which is
// what keeps the log from drowning in hundreds of lines an hour.

$suite->test('17: the deletion-confirmation progress is logged in English, not written into chk-msg as prose', function () use ($hash) {
    $now = 1000000;
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.get_custom', true, false, array('2:' . ($now - 7200)));
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // chk-del
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // chk-msg

    strictAssertSame(ruTrackerChecker::STE_DELETED,
        strictInvoke('RuTrackerCheckImpl', 'confirmDeletion', array($hash, $now, 3600)),
        'the third confirmation reaches the cap');

    // chk-msg stays a bare token: the status label already says "probably
    // deleted" and "deleting|N/M" already carries the count, so the sentence
    // needs no translated token of its own -- it belongs in the log.
    strictAssertSame(ruTrackerChecker::CHKMSG_DELETING . '|3/3', hMessage(0), 'still just the token');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'row missing from the dump for',
        'the consecutive-cycle count is logged');
    strictAssertEnglish($line, 'the deletion-progress line');
    strictAssertTrue(strpos($line, '3 of 3 consecutive cycles') !== false, 'the counts survive the move: ' . $line);
    strictAssertTrue(strpos($line, 'tracker confirming the deletion') !== false,
        'so does the tracker confirmation: ' . $line);
    strictAssertTrue(strpos($line, $hash) !== false, 'the line names the torrent');
});

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

$suite->test('19: layer 2 logs its verdict with the HTTP status when it runs', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    Snoopy::queueAny(200, hRegisteredBody());
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    rXMLRPCRequest::queue('d.set_custom', true, false, array());

    strictAssertSame(ruTrackerChecker::STE_UPTODATE,
        RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent), 'registered -> up to date');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'layer2 verdict=', 'the probe answer is logged');
    strictAssertEnglish($line, 'the layer-2 verdict line');
    strictAssertTrue(strpos($line, 'layer2 verdict=registered') !== false, 'the classified answer: ' . $line);
    strictAssertTrue(strpos($line, 'http=200') !== false, 'the HTTP status the answer came with: ' . $line);
    strictAssertTrue(strpos($line, 'pk=') === false, 'the probe URL never reaches the log: ' . $line);
});

$suite->test('20: layer 2 names which of its three skip reasons applied', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    // (a) switched off in the configuration.
    hReset();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    rXMLRPCRequest::queue('d.get_custom', true, false, array('')); // resolveForum: blank, stop here
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
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
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'layer2 skipped:', 'a skip is logged');
    strictAssertEnglish($line, 'the layer-2 skip line');
    strictAssertTrue(strpos($line, 'announce cap') !== false && strpos($line, 'exhausted') !== false,
        'reason (b): ' . $line);

    // (c) a 403 cooldown is still running for this host. recordProbe() with
    // got403 = true is what installs it, exactly as a real 403 would.
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    RuTrackerAnnounce::recordProbe('bt.t-ru.org', time(), true, 3600);
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    rXMLRPCRequest::queue('d.get_custom', true, false, array(''));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'layer2 skipped:', 'a skip is logged');
    strictAssertEnglish($line, 'the layer-2 skip line');
    strictAssertTrue(strpos($line, 'cooldown') !== false, 'reason (c): ' . $line);
    strictAssertSame(array(), Snoopy::$requests, 'a cooldown really does stop the probe');
});

$suite->test('21: layer 3 logs where the forum id came from, what the dump returned and the classification', function () use ($hash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    hQueueTopicKnown($topicId);
    hQueueLayer1(array(hCandidateRow()));
    hQueueForum(1106);
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106', 200, fiDump($topicId, 5, str_repeat('C', 40)));
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // resetDeletion
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // chk-msg cleared
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // topic-status message

    strictAssertSame(ruTrackerChecker::STE_NOT_NEED,
        RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent), 'tor_status 5 is terminal');

    $joined = implode("\n", ruTrackerChecker::$logs);
    strictAssertTrue(strpos($joined, 'layer3 forum=1106 from the chk-forum cache') !== false,
        'the forum id\'s source: ' . $joined);
    strictAssertTrue(strpos($joined, 'layer3 dump forum=1106 fetched, 1 rows') !== false,
        'what the dump returned: ' . $joined);
    strictAssertTrue(strpos($joined, 'layer3 topic=' . $topicId . ' verdict=closed tor_status=5') !== false,
        'the classification decision: ' . $joined);
    foreach (ruTrackerChecker::$logs as $line) strictAssertEnglish($line, 'every layer-3 log line');
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
    rXMLRPCRequest::queue('d.set_custom', true, false, array()); // chk-msg

    RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent);
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'layer3 topic=' . $topicId,
        'the missing row is logged as a classification outcome');
    strictAssertTrue(strpos($line, 'row missing from the dump') !== false, 'named as missing: ' . $line);
    strictAssertEnglish($line, 'the missing-row classification line');
});

$suite->test('23: layer 4 logs the hashes, the topic and the recorded run state as it starts', function () use ($hash, $newHash, $oldTorrent, $topicId, $topicUrl) {
    hReset();
    $GLOBALS['rutrackerLayer2Enabled'] = true;
    rTorrent::$sendResult = $newHash;
    hQueueMetaFetchFlow($hash, $newHash, $topicId);

    strictAssertSame(ruTrackerChecker::STE_META_PENDING,
        RuTrackerCheckImpl::download_torrent($topicUrl, $hash, $oldTorrent), 'the metadata fetch begins');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'metafetch: begin', 'layer 4 announces itself');
    strictAssertEnglish($line, 'the layer-4 start line');
    strictAssertTrue(strpos($line, $hash) !== false, 'the old hash: ' . $line);
    strictAssertTrue(strpos($line, $newHash) !== false, 'the new hash: ' . $line);
    strictAssertTrue(strpos($line, 'topic=' . $topicId) !== false, 'the topic id: ' . $line);
    // The run state is a line of its own: begin() now announces itself before
    // the first probe, so that every exit before the state read still has a
    // line to be read against.
    $state = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'old run state=',
        'the recorded run state is logged too');
    strictAssertEnglish($state, 'the layer-4 run-state line');
    strictAssertTrue(strpos($state, 'old run state=started') !== false, 'the recorded run state: ' . $state);
});

$exitCode = $suite->run();
strictRemoveTree($GLOBALS['tmpState']);
strictSetPrivateStatic('RuTrackerState', 'dir', null);
exit($exitCode);
