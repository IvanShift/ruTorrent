<?php

/**
 * Tests for plugins/rutracker_check/updatepass.php.
 *
 * RuTrackerUpdatePass::run() writes through ruTrackerChecker::setState()/
 * setMessage() directly (no wrapper, no reflection -- see task-8 brief), so
 * this file needs the REAL ruTrackerChecker (its STE_* constants and its
 * actual d.set_custom writes), not TestLib's handler-stub double, which is
 * missing setState() and the META_PENDING/ABSORBED constants entirely. It
 * loads the real class the same way CheckerTest.php does: eval the class
 * body out of check.php (skipping that file's own top-level requires/eval,
 * which need a live plugin-conf/tracker-registration environment this test
 * has no reason to stand up) behind light doubles for getCmd()/Snoopy.
 */

require_once(__DIR__ . '/TestLib.php');

function loadClassDefinition($filename, $className)
{
    $source = file_get_contents($filename);
    $offset = strpos($source, 'class ' . $className);
    if ($offset === false)
        throw new RuntimeException("Class {$className} was not found in {$filename}");
    // ruTrackerChecker is the final declaration in check.php.
    return substr($source, $offset);
}

// getCmd() is identity in every existing rutracker_check test (CheckerTest.php
// included): the real alias table (php/settings.php:302-312) only remaps a
// handful of legacy names, none of which change the shape of what these
// tests assert on, and every production call already goes through getCmd()
// so the alias substitution itself is exercised by php/SnoopyTest.php-style
// coverage elsewhere, not here.
function getCmd($command)
{
    return $command;
}

eval(loadClassDefinition(
    testFindRepoRoot() . '/plugins/rutracker_check/check.php',
    'ruTrackerChecker'
));

require_once(testFindRepoRoot() . '/plugins/rutracker_check/detector.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/forumindex.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/announce.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/updatepass.php');

// Minimal Snoopy double for RuTrackerUpdatePass::pollFeed()'s default
// (no-$client) branch: a single response queue is enough because pollFeed
// only ever fetches one URL (the feed) through it, and records the raw
// request headers so a test can assert If-None-Match was actually sent.
class Snoopy
{
    public static $queue = array();
    public static $requests = array();

    public $status = 0;
    public $results = '';
    public $headers = array();
    public $rawheaders = array();
    public $read_timeout = 0;
    public $_fp_timeout = 0;
    public $agent = '';

    public static function reset()
    {
        self::$queue = array();
        self::$requests = array();
    }

    public static function queue($status, $results, $headers = array())
    {
        self::$queue[] = array($status, $results, $headers);
    }

    public function fetchComplex($url, $method = 'GET', $contentType = '', $body = '')
    {
        self::$requests[] = array('url' => $url, 'rawheaders' => $this->rawheaders);
        if (!count(self::$queue))
            throw new RuntimeException("Unexpected feed fetch: {$url}");
        list($this->status, $this->results, $this->headers) = array_shift(self::$queue);
        return true;
    }
}

$suite = new StrictTestSuite();

// 9 columns in the current wire-format order (hash, state, time, stime,
// label, message, chk-del, chk-msg, tracker blob).
function upRow($hash, $failed, $host = 'bt.t-ru.org', $state = '3', $message = '', $label = '', $del = '', $msg = '')
{
    return array($hash, $state, '100', '100', $label, $message, $del, $msg,
        "http://{$host}/ann?pk=x|1|{$failed}|" . ($failed ? '0' : '5') . '#');
}

function upFeed()
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        . '<feed xmlns="http://www.w3.org/2005/Atom">'
        . '<entry><title>[Обновлено] Topic one</title>'
        . '<link href="https://rutracker.org/forum/viewtopic.php?t=100"/>'
        . '<category term="f-11"/></entry>'
        . '<entry><title>Topic two</title>'
        . '<link href="https://rutracker.org/forum/viewtopic.php?t=200"/>'
        . '<category term="f-22"/></entry>'
        . '</feed>';
}

$suite->test('parseMulticall maps all 9 columns and drops a trailing partial row', function () {
    $values = array_merge(
        array('AAAA', '3', '100', '200', 'lbl', 'msg', '2:150', 'дамп: строки нет, цикл 2/3',
            'http://bt.t-ru.org/ann?pk=x|1|0|5#'),
        array('leftover') // fewer than COLUMNS values left over: must be dropped, not guessed at
    );
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    strictAssertSame(1, count($rows), 'the partial trailing group is dropped');
    strictAssertSame(array(
        'hash' => 'AAAA', 'state' => 3, 'time' => 100, 'stime' => 200, 'label' => 'lbl',
        'message' => 'msg', 'del' => '2:150', 'msg' => 'дамп: строки нет, цикл 2/3',
        'trackers' => array(array('url' => 'http://bt.t-ru.org/ann?pk=x', 'enabled' => 1, 'failed' => 0, 'success' => 5)),
    ), $rows[0], 'full field mapping');
});

$suite->test('isTrackerSupported matches any tracker row, not just the first', function () {
    $filters = array('/t-ru\.org/i');
    strictAssertTrue(RuTrackerUpdatePass::isTrackerSupported(
        array(array('url' => 'dht://'), array('url' => 'http://bt.t-ru.org/ann')), $filters
    ), 'RuTracker row after a leading dht:// row must still match');
    strictAssertTrue(!RuTrackerUpdatePass::isTrackerSupported(
        array(array('url' => 'dht://'), array('url' => 'http://example.com/ann')), $filters
    ), 'no row matches -> unsupported');
    strictAssertTrue(!RuTrackerUpdatePass::isTrackerSupported(array(), $filters), 'no rows at all -> unsupported');
});

$suite->test('candidates go to the checker, alive stay home, fuse trips per host', function () {
    $values = array_merge(
        upRow(str_repeat('A', 40), 0),               // alive
        upRow(str_repeat('B', 40), 6),                // candidate bt
        upRow(str_repeat('C', 40), 6, 'bt2.t-ru.org'),
        upRow(str_repeat('D', 40), 6, 'bt2.t-ru.org'),
        upRow(str_repeat('E', 40), 6, 'bt2.t-ru.org'),
        upRow(str_repeat('F', 40), 0, 'bt2.t-ru.org')  // alive
    );
    // bt2: total 4, candidates 3 -> floor 3 reached -> fuse trips; bt: 1 of 2 -> holds below the floor.
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    strictAssertSame(6, count($rows), 'six torrents parsed');

    $checked = array();
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) use (&$checked) { $checked[] = $hash; });
    rXMLRPCRequest::reset();
    // Two setState(UPTODATE) writes (A, F): chk-state + chk-time + chk-stime.
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom', 'd.set_custom'), true, false, array());
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom', 'd.set_custom'), true, false, array());
    // Three setState(CANT_REACH_TRACKER) writes (C, D, E): chk-state + chk-time.
    for ($i = 0; $i < 3; $i++) rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());
    // Three setMessage writes (C, D, E).
    for ($i = 0; $i < 3; $i++) rXMLRPCRequest::queue('d.set_custom', true, false, array());

    $result = RuTrackerUpdatePass::run($rows);
    strictAssertSame(array(str_repeat('B', 40)), $result['checked'], 'only the bt candidate is checked');
    strictAssertSame(array('bt2.t-ru.org'), $result['fused'], 'bt2 fuse tripped');
    strictAssertSame(2, $result['uptodate'], 'A and F counted as up to date');
    strictAssertSame($checked, $result['checked'], 'checker callback used');

    strictAssertSame(2, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom|d.set_custom')), 'two UPTODATE writes');
    strictAssertSame(3, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')), 'three fused CANT_REACH_TRACKER writes');
    strictAssertSame(3, count(rXMLRPCRequest::requestsFor('d.set_custom')), 'three fuse messages');

    $messages = rXMLRPCRequest::requestsFor('d.set_custom');
    foreach ($messages as $request)
        strictAssertSame(ruTrackerChecker::CHKMSG_FUSE . '|bt2.t-ru.org', $request['commands'][0]->params[2],
            'the fuse token carries the tripped host and nothing else');
});

$suite->test('cold torrents are skipped entirely: no checker call, no state write', function () {
    $values = upRow(str_repeat('A', 40), 0);
    $values[8] = 'http://bt.t-ru.org/ann?pk=x|1|0|0#'; // failed=0, success=0 -> cold
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) { throw new RuntimeException('must not run'); });
    rXMLRPCRequest::reset();
    $result = RuTrackerUpdatePass::run($rows);
    strictAssertSame(array(), $result['checked'], 'cold not checked');
    strictAssertSame(0, $result['uptodate'], 'cold is not up to date either');
    strictAssertSame(array(), rXMLRPCRequest::$requests, 'no state write for a torrent whose counters never moved');
});

$suite->test('a disabled tracker row (verdict none) is skipped entirely', function () {
    $values = upRow(str_repeat('A', 40), 6);
    $values[8] = 'http://bt.t-ru.org/ann?pk=x|0|6|0#'; // enabled=0
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) { throw new RuntimeException('must not run'); });
    rXMLRPCRequest::reset();
    $result = RuTrackerUpdatePass::run($rows);
    strictAssertSame(array(), $result['checked'], 'none not checked');
    strictAssertSame(array(), rXMLRPCRequest::$requests, 'no state write either');
});

$suite->test('a transport-error message marks CANT_REACH_TRACKER without running the checker', function () {
    $values = upRow(str_repeat('A', 40), 6, 'bt.t-ru.org', '3', 'Tracker: [Could not resolve hostname]');
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) { throw new RuntimeException('must not run'); });
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    $result = RuTrackerUpdatePass::run($rows);
    strictAssertSame(array(), $result['checked'], 'transport candidates are never checked directly');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')), 'CANT_REACH_TRACKER written once');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')), 'no message written for transport error');
});

$suite->test('an alive row with a leftover deletion counter and message clears both, with no extra read', function () {
    $values = upRow(str_repeat('A', 40), 0, 'bt.t-ru.org', '3', '', '', '2:900', 'deleting|2/3');
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) { throw new RuntimeException('alive rows never reach the checker'); });
    rXMLRPCRequest::reset();
    // Everything needed (chk-del, chk-msg) already rode in on the row, so
    // only two multicalls are ever issued: the UPTODATE state write and the
    // clearing write -- no extra read in between.
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom', 'd.set_custom'), true, false, array());
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    $result = RuTrackerUpdatePass::run($rows);

    strictAssertSame(1, $result['uptodate'], 'still counted as up to date');
    strictAssertSame(2, count(rXMLRPCRequest::$requests), 'exactly two requests: state write and clear, no probing read');
    $clears = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom');
    strictAssertSame(1, count($clears), 'one clearing multicall');
    strictAssertSame(array(str_repeat('A', 40), 'chk-del', ''), $clears[0]['commands'][0]->params, 'deletion counter reset');
    strictAssertSame(array(str_repeat('A', 40), 'chk-msg', ''), $clears[0]['commands'][1]->params, 'stale message cleared');
});

$suite->test('an alive row with nothing to clear writes only the state, no clearing round trip', function () {
    $values = upRow(str_repeat('A', 40), 0); // chk-del and chk-msg both blank
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom', 'd.set_custom'), true, false, array());

    $result = RuTrackerUpdatePass::run($rows);

    strictAssertSame(1, $result['uptodate'], 'still counted as up to date');
    strictAssertSame(1, count(rXMLRPCRequest::$requests), 'only the state write; nothing to clear means no second request');
    strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom'), 'no clearing multicall for a row with neither field set');
});

// Finding 4: check.php's run() has always honoured $ignoreLabels; this
// pass's direct setState() writes for alive/transport verdicts must match,
// or an ignored torrent flaps between STE_IGNORED and a scheduler-derived
// state depending on which path last touched it.
$suite->test('an ignored-label row is written STE_IGNORED, never UPTODATE/CANT_REACH_TRACKER, and never reaches the checker', function () {
    $GLOBALS['ignoreLabels'] = array('tv-sonarr');
    try {
        $values = array_merge(
            upRow(str_repeat('A', 40), 0, 'bt.t-ru.org', '3', '', 'tv-sonarr'), // alive verdict
            upRow(str_repeat('B', 40), 6, 'bt.t-ru.org', '3', 'Tracker: [Could not resolve hostname]', 'tv-sonarr') // transport verdict
        );
        $rows = RuTrackerUpdatePass::parseMulticall($values);
        strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) { throw new RuntimeException('an ignored row must never reach the checker'); });
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

        $result = RuTrackerUpdatePass::run($rows);

        strictAssertSame(array(), $result['checked'], 'an ignored row is never dispatched to the checker');
        strictAssertSame(0, $result['uptodate'], 'an ignored row must not count as up to date');
        $writes = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom');
        strictAssertSame(2, count($writes), 'one STE_IGNORED write per ignored row');
        strictAssertSame(
            array(str_repeat('A', 40), 'chk-state', (string) ruTrackerChecker::STE_IGNORED),
            $writes[0]['commands'][0]->params,
            'alive-verdict row written IGNORED, not UPTODATE'
        );
        strictAssertSame(
            array(str_repeat('B', 40), 'chk-state', (string) ruTrackerChecker::STE_IGNORED),
            $writes[1]['commands'][0]->params,
            'transport-verdict row written IGNORED, not CANT_REACH_TRACKER'
        );
    } finally {
        unset($GLOBALS['ignoreLabels']);
    }
});

$suite->test('META_PENDING torrents are always dispatched to the checker', function () {
    $values = upRow(str_repeat('A', 40), 0, 'bt.t-ru.org', '9');
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    $checked = array();
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) use (&$checked) { $checked[] = $hash; });
    rXMLRPCRequest::reset();
    $result = RuTrackerUpdatePass::run($rows);
    strictAssertSame(array(str_repeat('A', 40)), $checked, 'meta-pending dispatched despite alive counters');
    strictAssertSame(array(), rXMLRPCRequest::$requests, 'no quiet state write races the checker for a meta-pending row');
});

$suite->test('the production default checker calls ruTrackerChecker::run with the parsed row', function () {
    $values = upRow(str_repeat('A', 40), 0, 'bt.t-ru.org', '9', 'hello');
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', null); // restore the production default
    rXMLRPCRequest::reset();
    // ruTrackerChecker::run() with a non-null $state probes torrentExists() (d.hash)
    // first; a faulted-but-answered probe means "target gone" and run() returns
    // immediately, so this alone proves the seam reached the real run() with the
    // right hash without also re-verifying run()'s own internals (CheckerTest.php's job).
    rXMLRPCRequest::queue('d.hash', true, true, array());

    $result = RuTrackerUpdatePass::run($rows);
    strictAssertSame(array(str_repeat('A', 40)), $result['checked'], 'dispatched through the real default checker');
    $probes = rXMLRPCRequest::requestsFor('d.hash');
    strictAssertSame(1, count($probes), 'ruTrackerChecker::run reached its own torrentExists probe');
    strictAssertSame(str_repeat('A', 40), $probes[0]['commands'][0]->params, 'probed the dispatched hash');
});

// Finding 6: chk-feed-upd used to be stamped here even though nothing in the
// plugin ever reads it back; only chk-forum (which download_torrent()'s
// resolveForum() actually reads) is written.
$suite->test('pollFeed writes chk-forum only for a topic whose forum is not already cached', function () {
    rXMLRPCRequest::reset();
    $client = (object) array('status' => 200, 'results' => upFeed());
    rXMLRPCRequest::queue('d.multicall', true, false, array(
        'HASH1', '100', '',    // topic 100 known to the feed, forum not yet cached
        'HASH2', '200', '55',  // topic 200 known, forum already cached: no write needed
        'HASH3', '0', '',      // no chk-topic yet
        'HASH4', '999', '',    // chk-topic the feed does not know about
    ));

    RuTrackerUpdatePass::pollFeed($client);

    $writes = rXMLRPCRequest::requestsFor('d.set_custom');
    strictAssertSame(1, count($writes), 'only HASH1 needed a write');
    strictAssertSame(array('HASH1', 'chk-forum', '11'), $writes[0]['commands'][0]->params, 'forum id learned from the feed');
    strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom'), 'chk-feed-upd is no longer written at all');
});

$suite->test('pollFeed is a no-op, not an error, when the feed is unreachable', function () {
    rXMLRPCRequest::reset();
    $client = (object) array('status' => 500, 'results' => '');
    RuTrackerUpdatePass::pollFeed($client);
    strictAssertSame(array(), rXMLRPCRequest::$requests, 'an unreachable feed never even reaches the main-view multicall');
});

$suite->test('pollFeed sends If-None-Match from a cached ETag and treats 304 as unchanged', function () {
    $tmp = sys_get_temp_dir() . '/chk-updatepass-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);
    Snoopy::reset();
    rXMLRPCRequest::reset();

    try {
        // First poll: no cached ETag yet, empty feed body (so pollFeed returns
        // right after parseFeed without needing a main-view multicall queued).
        Snoopy::queue(200, '<feed xmlns="http://www.w3.org/2005/Atom"></feed>', array('ETag: "v1"'));
        RuTrackerUpdatePass::pollFeed();
        strictAssertTrue(!isset(Snoopy::$requests[0]['rawheaders']['If-None-Match']), 'nothing cached yet on the first poll');
        strictAssertSame('v1', trim(RuTrackerState::load('updatepass')['feed_etag'], '"'), 'ETag persisted');

        // Second poll: the cached ETag must be sent, and a 304 must not blow up
        // or touch the XMLRPC layer at all.
        Snoopy::queue(304, '', array());
        RuTrackerUpdatePass::pollFeed();
        strictAssertSame('"v1"', Snoopy::$requests[1]['rawheaders']['If-None-Match'], 'conditional GET carries the cached ETag');
        strictAssertSame(array(), rXMLRPCRequest::$requests, '304 short-circuits before any XMLRPC request');
    } finally {
        strictRemoveTree($tmp);
        strictSetPrivateStatic('RuTrackerState', 'dir', null);
    }
});

// reapOrphans (design doc 4.4 point 4, task 12): a service download stub
// carries chk-meta-old for as long as pump() manages it off the old
// torrent's own markers. These tests drive the sweep directly against the
// XMLRPC double: a "main" d.multicall scan (hash, chk-meta-old,
// chk-meta-until) followed by, for every marked item, a chk-meta-new AND
// chk-state read off the old hash it names -- claimed only when both
// still point at this stub and META_PENDING (finding 2: a stale
// chk-meta-new alone is not enough once the old torrent has moved to
// another state, e.g. STE_IGNORED, and pump() will never run again).

$suite->test('reapOrphans erases an orphan once its deadline has passed', function () {
    $stub = str_repeat('S', 40);
    $old = str_repeat('O', 40);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($stub, $old, '500'));
    // old torrent's chk-meta-new points elsewhere (or nowhere); chk-state is irrelevant here.
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('', (string) ruTrackerChecker::STE_META_PENDING));

    RuTrackerUpdatePass::reapOrphans(1000);

    $claims = rXMLRPCRequest::requestsFor('d.get_custom|d.get_custom');
    strictAssertSame(1, count($claims), 'one claim read');
    strictAssertSame(array($old, 'chk-meta-new'), $claims[0]['commands'][0]->params, 'claim read targets the old torrent named by chk-meta-old');
    strictAssertSame(array($old, 'chk-state'), $claims[0]['commands'][1]->params, 'claim read also checks the old torrent is still META_PENDING');

    $erased = rXMLRPCRequest::requestsFor('d.erase');
    strictAssertSame(1, count($erased), 'past-deadline orphan erased');
    strictAssertSame($stub, $erased[0]['commands'][0]->params, 'erased the stub, not the old torrent');
});

$suite->test('reapOrphans leaves an orphan alone before its deadline', function () {
    $stub = str_repeat('S', 40);
    $old = str_repeat('O', 40);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($stub, $old, '99999'));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('', (string) ruTrackerChecker::STE_META_PENDING));

    RuTrackerUpdatePass::reapOrphans(1000);

    strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.erase'), 'deadline not yet passed: nothing erased');
});

$suite->test('reapOrphans never erases a claimed stub still owned by a META_PENDING old torrent, even past its deadline', function () {
    $stub = str_repeat('S', 40);
    $old = str_repeat('O', 40);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($stub, $old, '500'));
    // old torrent still claims this exact stub AND is still META_PENDING.
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($stub, (string) ruTrackerChecker::STE_META_PENDING));

    RuTrackerUpdatePass::reapOrphans(1000);

    strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.erase'), "claimed stub is pump()'s to manage, never reaped here");
});

// Finding 2's own scenario: a torrent mid-fetch gains a label listed in
// $ignoreLabels. check.php's run() applies the label check BEFORE the
// META_PENDING short-circuit, writes STE_IGNORED, and pump() never runs
// again -- but chk-meta-new still names this stub. Without the chk-state
// check, reapOrphans() would treat that stale marker as a live claim and
// leave the stub (and its .chk-meta directory) behind forever.
$suite->test('reapOrphans reaps a stub once its old torrent has moved on to STE_IGNORED, even though chk-meta-new still names it', function () {
    $stub = str_repeat('S', 40);
    $old = str_repeat('O', 40);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($stub, $old, '500'));
    // chk-meta-new is stale-still-pointing-at-the-stub, but the old torrent
    // itself moved to STE_IGNORED: the label check pre-empted pump().
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($stub, (string) ruTrackerChecker::STE_IGNORED));

    RuTrackerUpdatePass::reapOrphans(1000);

    $erased = rXMLRPCRequest::requestsFor('d.erase');
    strictAssertSame(1, count($erased), 'a stale chk-meta-new marker on a no-longer-META_PENDING old torrent must not be treated as a live claim');
    strictAssertSame($stub, $erased[0]['commands'][0]->params, 'erased the stub');
});

$suite->test('reapOrphans never touches an ordinary torrent with no chk-meta-old marker', function () {
    $ordinary = str_repeat('T', 40);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($ordinary, '', '0'));

    RuTrackerUpdatePass::reapOrphans(1000);

    strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.get_custom|d.get_custom'), 'no claim read for a torrent that is not a stub');
    strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.erase'), 'no erase either');
});

$suite->test('reapOrphans reaps a stub whose old torrent no longer exists, once past the deadline', function () {
    $stub = str_repeat('S', 40);
    $old = str_repeat('O', 40);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($stub, $old, '500'));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, true, array()); // old torrent is gone entirely: faulted read

    RuTrackerUpdatePass::reapOrphans(1000);

    $erased = rXMLRPCRequest::requestsFor('d.erase');
    strictAssertSame(1, count($erased), 'a stub whose old torrent vanished is treated as an orphan and reaped past its deadline');
    strictAssertSame($stub, $erased[0]['commands'][0]->params, 'erased the stub');
});

// This pass is the scheduler's ONLY route into ruTrackerChecker::run(), and it
// carries every registered tracker, not just RuTracker. The layer-1 detector
// reads RuTracker tracker rows exclusively, so it answers 'none' for a
// Kinozal/NNMClub/Toloka/tfile torrent -- which must not be read as "no signal
// worth a request", or those handlers stop being called at all.
$suite->test('a torrent from another supported tracker still reaches its handler', function () {
    $kinozal = str_repeat('K', 40);
    $nnmclub = str_repeat('N', 40);
    $rutracker = str_repeat('A', 40);
    $values = array_merge(
        upRow($kinozal, 0, 'tr2.torrent4me.com', '4'),  // left at STE_DELETED by an earlier cycle
        upRow($nnmclub, 3, 'bt.nnmclub.to'),
        upRow($rutracker, 0)                            // alive, takes the fast path
    );
    $rows = RuTrackerUpdatePass::parseMulticall($values);

    $checked = array();
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) use (&$checked) { $checked[] = $hash; });
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom', 'd.set_custom'), true, false, array());

    $result = RuTrackerUpdatePass::run($rows);

    strictAssertSame(array($kinozal, $nnmclub), $result['checked'],
        'both foreign-tracker rows are dispatched, in row order');
    strictAssertSame(array($kinozal, $nnmclub), $checked, 'the checker itself received them');
    strictAssertSame(array(), $result['fused'], 'a foreign announce host never feeds the RuTracker fuse');
    strictAssertSame(1, $result['uptodate'], 'only the RuTracker row took the alive fast path');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom|d.set_custom')),
        'a dispatched row gets no scheduler-side state write -- its own handler decides');
});

$suite->test('an ignored label still short-circuits a foreign-tracker torrent', function () {
    $GLOBALS['ignoreLabels'] = array('tv-sonarr');
    try {
        $rows = RuTrackerUpdatePass::parseMulticall(
            upRow(str_repeat('K', 40), 0, 'tr2.torrent4me.com', '4', '', 'tv-sonarr'));
        strictSetPrivateStatic('RuTrackerUpdatePass', 'checker',
            function ($hash) { throw new RuntimeException('an ignored row must never reach the checker'); });
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

        $result = RuTrackerUpdatePass::run($rows);

        strictAssertSame(array(), $result['checked'], 'the ignore list outranks the foreign-tracker dispatch');
        $writes = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom');
        strictAssertSame(
            array(str_repeat('K', 40), 'chk-state', (string) ruTrackerChecker::STE_IGNORED),
            $writes[0]['commands'][0]->params,
            'written IGNORED like every other ignored row'
        );
    } finally {
        unset($GLOBALS['ignoreLabels']);
    }
});

// sweepReplacements: the recovery pass for a replacement transaction that
// died between the commit (the predecessor erased) and the activation. Such
// a row is invisible to everything else in the plugin -- it is stopped and
// closed, so it is not in the "seeding" view the cycle scans, and its old
// torrent is gone, so no check will ever reach createTorrent()'s adoption
// path again. The marker and the record it left behind are the only handle.
//
// The sweep's whole safety argument is that it acts on run state ONLY when a
// record this plugin wrote itself decodes AND the recorded predecessor is
// provably gone. Everything else is left exactly as found.

function sweepScan($rows)
{
    $flat = array();
    foreach ($rows as $row)
        foreach ($row as $cell)
            $flat[] = $cell;
    rXMLRPCRequest::queue('d.multicall', true, false, $flat);
}

// state, is_open, chunks_hashed, completed_bytes, complete, message, chk-stime, chk-state, directory_base
function sweepDetail($state, $open, $hashed = 0, $bytes = 0, $complete = 0, $message = '', $stime = '0', $chkState = '2', $base = '')
{
    rXMLRPCRequest::queue(
        array('d.get_state', 'd.is_open', 'd.get_chunks_hashed', 'd.get_completed_bytes', 'd.get_complete',
            'd.get_message', 'd.get_custom', 'd.get_custom', 'd.get_directory_base'),
        true, false, array($state, $open, $hashed, $bytes, $complete, $message, $stime, $chkState, $base));
}

$suite->test('sweepReplacements scans main for exactly the hash, the marker and the record', function () {
    rXMLRPCRequest::reset();
    sweepScan(array());

    RuTrackerUpdatePass::sweepReplacements(1000);

    $scans = rXMLRPCRequest::requestsFor('d.multicall');
    strictAssertSame(1, count($scans), 'one fleet scan per cycle');
    strictAssertSame(
        array('main', 'd.get_hash=', 'd.get_custom=chk-replacement', 'd.get_custom=chk-replaces'),
        $scans[0]['commands'][0]->params,
        'the scan must walk main: a stranded replacement is stopped and closed, so it is absent from seeding'
    );
    strictAssertSame(false, $scans[0]['important'], 'a repair pass may never sink the cycle it runs in');
});

$suite->test('sweepReplacements ignores a row with no marker', function () {
    rXMLRPCRequest::reset();
    sweepScan(array(array(str_repeat('A', 40), '', '')));

    RuTrackerUpdatePass::sweepReplacements(1000);

    strictAssertSame(0, count(rXMLRPCRequest::requestsFor(
        'd.get_state|d.is_open|d.get_chunks_hashed|d.get_completed_bytes|d.get_complete|d.get_message|d.get_custom|d.get_custom|d.get_directory_base')),
        'an unmarked hash is foreign and must not cost even a read');
});

$suite->test('sweepReplacements leaves a transaction younger than the lock window alone', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME);

    strictAssertSame(0, count(rXMLRPCRequest::requestsFor(
        'd.get_state|d.is_open|d.get_chunks_hashed|d.get_completed_bytes|d.get_complete|d.get_message|d.get_custom|d.get_custom|d.get_directory_base')),
        'a transaction still inside the lock window may simply be in flight');
});

// THE INCIDENT, reproduced: the predecessor is gone, so the commit did
// happen; the copy is stopped and closed with nothing hashed, so the
// activation did not.
$suite->test('sweepReplacements finishes a stranded replacement whose predecessor is gone', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    sweepScan(array(array($hash, 'nonce', $old . '-started-1000')));
    sweepDetail(0, 0);
    // transport ok, daemon faults: torrentExists() reads that as "gone",
    // whereas a dead transport is "unknowable" and must never be acted on.
    rXMLRPCRequest::queue('d.hash', true, true, array());          // the predecessor is gone
    rXMLRPCRequest::queue(array('d.open', 'd.start'), true, false, array(0, 0));
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1));
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    $probe = rXMLRPCRequest::requestsFor('d.hash');
    strictAssertSame(1, count($probe), 'the predecessor is probed exactly once');
    strictAssertSame($old, $probe[0]['commands'][0]->params, 'the probe reads the recorded predecessor, and only reads it');
    $act = rXMLRPCRequest::requestsFor('d.open|d.start');
    strictAssertSame(1, count($act), 'one activation attempt per cycle: the cycle is the retry loop');
    strictAssertSame($hash, $act[0]['commands'][0]->params, 'd.open targets the marked hash');
    strictAssertSame($hash, $act[0]['commands'][1]->params, 'd.start targets the marked hash');
    $clear = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom');
    strictAssertSame(1, count($clear), 'a verified activation closes the transaction');
    strictAssertSame(array($hash, 'chk-replacement', ''), $clear[0]['commands'][0]->params, 'the marker is cleared');
});

$suite->test('sweepReplacements opens a recorded paused predecessor without starting it', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-open-1000')));
    sweepDetail(0, 0);
    // transport ok, daemon faults: torrentExists() reads that as "gone",
    // whereas a dead transport is "unknowable" and must never be acted on.
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue('d.open', true, false, array(0));
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(0, 1));
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.open')), 'a paused predecessor is restored with d.open alone');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')), 'and must never be started');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')), 'the transaction is closed');
});

$suite->test('sweepReplacements never resurrects a replacement whose predecessor the user had stopped', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-stopped-1000')));
    sweepDetail(0, 0);
    // transport ok, daemon faults: torrentExists() reads that as "gone",
    // whereas a dead transport is "unknowable" and must never be acted on.
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open')), 'a stopped predecessor stays stopped');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')), 'and is certainly never started');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
        'but the transaction IS finished: leaving it stopped was the intended outcome');
});

$suite->test('sweepReplacements does not restart a copy that has been opened since it was staged', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
    // hashed chunks and bytes on disk: somebody opened it after the crash and
    // stopped it again, so the current run state is their decision, not ours.
    sweepDetail(0, 0, 42, 1024, 0, '', '1000', '2');
    // transport ok, daemon faults: torrentExists() reads that as "gone",
    // whereas a dead transport is "unknowable" and must never be acted on.
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')), 'a copy somebody has already handled is not restarted');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open')), 'nor reopened');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom');
    strictAssertSame(1, count($writes), 'the transaction is closed all the same');
    strictAssertSame(array($hash, 'chk-replacement', ''), $writes[0]['commands'][0]->params, 'by clearing the keys, not by labelling');
});

$suite->test('sweepReplacements does not touch a row whose recorded predecessor still exists', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
    sweepDetail(0, 0);
    rXMLRPCRequest::queue('d.hash', true, false, array(str_repeat('B', 40)));   // still there

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open')), 'the commit never happened, so there is nothing to activate');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')), 'nothing is started');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')), 'and the keys stay, because the row is still adoptable');
});

$suite->test('sweepReplacements defers when the predecessor probe cannot be answered', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
    sweepDetail(0, 0);
    // no d.hash response queued: the transport itself fails

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')), 'an unknowable fact is never acted on');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')), 'and nothing is cleared');
});

$suite->test('sweepReplacements clears the keys of a marked row that is already live', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    sweepScan(array(array($hash, 'nonce', '')));
    sweepDetail(1, 1, 100, 500, 1, '', '1000', '2');
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    $writes = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom');
    strictAssertSame(1, count($writes),
        'a running marked torrent is a finished replacement whose final clear was lost -- including one a human started by hand');
    // The key alone cannot tell a clear from a state write: setState() sends
    // the same two-command shape. Assert the parameters, or a branch that
    // labels the row instead of retiring it would pass unnoticed.
    strictAssertSame(array(str_repeat('A', 40), 'chk-replacement', ''), $writes[0]['commands'][0]->params,
        'the write must be the marker clear, not a state label');
    strictAssertSame(array(str_repeat('A', 40), 'chk-replaces', ''), $writes[0]['commands'][1]->params,
        'and the record goes with it');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open')), 'its run state is somebody else\'s decision');
});

// The legacy orphan: marked, but staged before the record existed. Its
// predecessor and its intended run state are both unrecoverable, so the row
// is labelled once and never touched again.
$suite->test('sweepReplacements labels a record-less stranded row and starts nothing', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    sweepScan(array(array($hash, 'nonce', '')));
    sweepDetail(0, 0, 0, 0, 0, '', '1000', '2');
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.hash')), 'there is no predecessor to probe');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open')), 'and no intent to act on');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')), 'nothing is started');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom');
    strictAssertSame(1, count($writes), 'exactly one write: the state label');
    strictAssertSame(array($hash, 'chk-state', (string) ruTrackerChecker::STE_ERROR), $writes[0]['commands'][0]->params,
        'the row is labelled so it is visible in the UI');
    strictAssertSame('chk-time', $writes[0]['commands'][1]->params[1], 'setState stamps the time alongside');
});

$suite->test('sweepReplacements writes nothing on a legacy row it has already labelled', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    sweepScan(array(array($hash, 'nonce', '')));
    sweepDetail(0, 0, 0, 0, 0, '', '1000', (string) ruTrackerChecker::STE_ERROR);

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
        'a settled legacy row must cost nothing on every later cycle');
});

$suite->test('sweepReplacements refuses to start a copy carrying an rTorrent message', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
    sweepDetail(0, 0, 0, 0, 0, 'Tracker: [Failure reason "torrent not registered"]', '1000', '2');
    // transport ok, daemon faults: torrentExists() reads that as "gone",
    // whereas a dead transport is "unknowable" and must never be acted on.
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')), 'a copy the daemon is already unhappy about is not started blind');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom');
    strictAssertSame(1, count($writes), 'it is labelled instead');
    strictAssertSame(array($hash, 'chk-state', (string) ruTrackerChecker::STE_ERROR), $writes[0]['commands'][0]->params, 'and the label is the error state');
});

$suite->test('sweepReplacements keeps the keys when the activation cannot be verified', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
    sweepDetail(0, 0);
    // transport ok, daemon faults: torrentExists() reads that as "gone",
    // whereas a dead transport is "unknowable" and must never be acted on.
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue(array('d.open', 'd.start'), true, false, array(0, 0));
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(0, 0));   // it did not come up
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    $writes = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom');
    strictAssertSame(1, count($writes), 'the row is labelled');
    strictAssertSame(array($hash, 'chk-state', (string) ruTrackerChecker::STE_ERROR), $writes[0]['commands'][0]->params,
        'an activation the daemon did not confirm is an error, not a success');
    foreach ($writes as $write)
        strictAssertTrue($write['commands'][0]->params[1] !== 'chk-replacement',
            'and the keys stay: believe the measurement, never the ack');
});

$suite->test('sweepReplacements never erases, stops or closes anything', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    sweepScan(array(
        array($hash, 'nonce', str_repeat('B', 40) . '-started-1000'),
        array(str_repeat('C', 40), 'nonce2', ''),
    ));
    sweepDetail(0, 0);
    // transport ok, daemon faults: torrentExists() reads that as "gone",
    // whereas a dead transport is "unknowable" and must never be acted on.
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue(array('d.open', 'd.start'), true, false, array(0, 0));
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1));
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());
    sweepDetail(0, 0, 0, 0, 0, '', '1000', '2');
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    foreach (rXMLRPCRequest::$requests as $request)
        foreach ($request['commands'] as $command)
        {
            strictAssertTrue(strpos($command->command, 'erase') === false, 'the sweep must never erase');
            strictAssertTrue($command->command !== 'd.stop' && $command->command !== 'd.close',
                'the sweep must never stop or close a download');
        }
});

$suite->test('sweepReplacements only ever writes to the hash carrying the marker', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    sweepScan(array(array($hash, 'nonce', $old . '-started-1000')));
    sweepDetail(0, 0);
    // transport ok, daemon faults: torrentExists() reads that as "gone",
    // whereas a dead transport is "unknowable" and must never be acted on.
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue(array('d.open', 'd.start'), true, false, array(0, 0));
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1));
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    foreach (rXMLRPCRequest::$requests as $request)
        foreach ($request['commands'] as $command)
        {
            if (strpos($command->command, 'd.set_custom') !== 0 && $command->command !== 'd.open' && $command->command !== 'd.start')
                continue;
            $target = is_array($command->params) ? $command->params[0] : $command->params;
            strictAssertSame($hash, $target, 'the recorded predecessor is an input to a read, never a write target');
        }
});

$suite->test('sweepReplacements writes nothing when the fleet scan itself fails', function () {
    rXMLRPCRequest::reset();
    // no d.multicall response queued

    RuTrackerUpdatePass::sweepReplacements(1000);

    strictAssertSame(1, count(rXMLRPCRequest::$requests), 'it asks once and gives up');
});


exit($suite->run());
