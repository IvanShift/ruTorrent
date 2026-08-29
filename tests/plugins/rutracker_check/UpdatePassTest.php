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

eval(loadClassDefinition(
    testFindRepoRoot() . '/plugins/rutracker_check/check.php',
    'ruTrackerChecker'
));

require_once(testFindRepoRoot() . '/plugins/rutracker_check/detector.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/forumindex.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/announce.php');

if (!defined('ERASEDATA_CLEANUP_NONE')) define('ERASEDATA_CLEANUP_NONE', 'none');
if (!defined('ERASEDATA_CLEANUP_READY')) define('ERASEDATA_CLEANUP_READY', 'ready');
if (!defined('ERASEDATA_CLEANUP_RETRY')) define('ERASEDATA_CLEANUP_RETRY', 'retry');

class UpdatePassErasedataFake
{
    public static $recoverResults = array();
    public static $cancelResults = array();
    public static $kickResults = array();
    public static $recoverCalls = array();
    public static $cancelCalls = array();
    public static $kickCalls = array();
    public static $events = array();

    public static function reset()
    {
        self::$recoverResults = array();
        self::$cancelResults = array();
        self::$kickResults = array();
        self::$recoverCalls = array();
        self::$cancelCalls = array();
        self::$kickCalls = array();
        self::$events = array();
    }

    public static function next(&$results, $default)
    {
        return count($results) ? array_shift($results) : $default;
    }
}

function erasedataRecoverObsoleteCleanup($oldHash, $newHash, $marker, $replacementRecord, &$reason = null)
{
    UpdatePassErasedataFake::$recoverCalls[] = array($oldHash, $newHash, $marker, $replacementRecord);
    UpdatePassErasedataFake::$events[] = 'recover:' . $oldHash;
    return UpdatePassErasedataFake::next(UpdatePassErasedataFake::$recoverResults, ERASEDATA_CLEANUP_NONE);
}

function erasedataCancelObsoleteCleanupGeneration($oldHash, $newHash, $marker, $replacementRecord)
{
    UpdatePassErasedataFake::$cancelCalls[] = array($oldHash, $newHash, $marker, $replacementRecord);
    UpdatePassErasedataFake::$events[] = 'cancel:' . $oldHash;
    return UpdatePassErasedataFake::next(UpdatePassErasedataFake::$cancelResults, ERASEDATA_CLEANUP_NONE);
}

function erasedataKickCollector($oldHash)
{
    UpdatePassErasedataFake::$kickCalls[] = $oldHash;
    UpdatePassErasedataFake::$events[] = 'kick:' . $oldHash;
    return UpdatePassErasedataFake::next(UpdatePassErasedataFake::$kickResults, true);
}

require_once(testFindRepoRoot() . '/plugins/rutracker_check/updatepass.php');

// The real checker delegates META_PENDING rows to this collaborator. Its own
// state machine is covered by MetaFetchTest; here the small double lets the
// scheduler prove that a pump step does not acknowledge forum-aware work.
class RuTrackerMetaFetch
{
    public static $calls = array();
    public static $result = 9;

    public static function pump($hash, $now)
    {
        self::$calls[] = array('hash' => $hash, 'now' => $now);
        return self::$result;
    }
}

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

function upTest($suite, $name, $callback)
{
    $suite->test($name, function () use ($callback) {
        UpdatePassErasedataFake::reset();
        return strictWithStateDir('chk-updatepass', $callback);
    });
}

// 8 columns in the current wire-format order (hash, state, time, label,
// message, chk-del, chk-msg, tracker blob). chk-stime was dropped from the
// scan: it was carried to run() and read by nobody. The FIELD still exists and
// the deletion logic still reads it on its own.
function upRow($hash, $failed, $host = 'bt.t-ru.org', $state = '3', $message = '', $label = '', $del = '', $msg = '', $time = '100')
{
    return array($hash, $state, $time, $label, $message, $del, $msg,
        "http://{$host}/ann?pk=x|1|{$failed}|" . ($failed ? '0' : '5') . '#');
}

// run() no longer writes its fast-path verdicts where it decides them: they are
// buffered and flushed behind ONE fresh scan, so a row a concurrent
// batch_check.php moved since the cycle-start snapshot is left alone (see
// RuTrackerUpdatePass::flushVerdicts). Unless a case is specifically about a row
// that moved, the scan just confirms what the snapshot said.
function upQueueUnchanged($rows)
{
    upQueueProjection($rows);
}

function upQueueProjection($rows, $callback = null)
{
    if ($callback !== null) {
        rXMLRPCRequest::queue('d.multicall', true, false, $callback);
        return;
    }
    $live = array();
    foreach ($rows as $row)
        $live = array_merge($live, array($row['hash'], (string) $row['state'],
            (string) $row['time'], (string) $row['del'], (string) $row['msg']));
    rXMLRPCRequest::queue('d.multicall', true, false, $live);
}

function upApplyVerdictCommands(&$model, $commands, $prefix = null)
{
    $limit = $prefix === null ? count($commands) : min((int) $prefix, count($commands));
    for ($index = 0; $index < $limit; $index++) {
        $params = $commands[$index]->params;
        $field = array(
            'chk-state' => 'state', 'chk-time' => 'time', 'chk-stime' => 'stime',
            'chk-msg' => 'msg', 'chk-del' => 'del',
        )[$params[1]];
        $model[$field] = (string) $params[2];
    }
    return array();
}

function upProjectionValues($model, $commands)
{
    $values = array();
    foreach ($commands as $command) {
        $field = array(
            'chk-state' => 'state', 'chk-time' => 'time', 'chk-stime' => 'stime',
            'chk-msg' => 'msg', 'chk-del' => 'del',
        )[$command->params[1]];
        $values[] = (string) $model[$field];
    }
    return $values;
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

upTest($suite, 'parseMulticall maps all 8 columns and drops a trailing partial row', function () {
    $values = array_merge(
        array('AAAA', '3', '100', 'lbl', 'msg', '2:150', 'дамп: строки нет, цикл 2/3',
            'http://bt.t-ru.org/ann?pk=x|1|0|5#'),
        array('leftover') // fewer than COLUMNS values left over: must be dropped, not guessed at
    );
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    strictAssertSame(1, count($rows), 'the partial trailing group is dropped');
    strictAssertSame(array(
        'hash' => 'AAAA', 'state' => 3, 'time' => 100, 'label' => 'lbl',
        'message' => 'msg', 'del' => '2:150', 'msg' => 'дамп: строки нет, цикл 2/3',
        'trackers' => array(array('url' => 'http://bt.t-ru.org/ann?pk=x', 'enabled' => 1, 'failed' => 0, 'success' => 5)),
        'trackers_complete' => true,
    ), $rows[0], 'full field mapping');
});

upTest($suite, 'a malformed tracker frame cannot lend its global message to RuTracker', function () {
    $hash = str_repeat('A', 40);
    $values = array(
        $hash, '3', '100', '', 'Tracker: [Could not resolve hostname]', '', '',
        'http://bt.t-ru.org/ann|1|6|0#http://foreign.example/ann?token=a|b|1|4|0#',
    );
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    strictAssertSame(false, $rows[0]['trackers_complete'], 'the framing loss survives parseMulticall');

    $checked = array();
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($seen) use (&$checked) {
        $checked[] = $seen;
    });
    $GLOBALS['rutrackerFuseShare'] = 0.2;
    $GLOBALS['rutrackerFuseFloor'] = 3;
    rXMLRPCRequest::reset();

    $result = RuTrackerUpdatePass::run($rows);
    strictAssertSame(array($hash), $result['checked'],
        'the uncertain global message cannot suppress the full checker');
    strictAssertSame(array($hash), $checked, 'the checker receives the candidate');
});

upTest($suite, 'isTrackerSupported matches any tracker row, not just the first', function () {
    $filters = array('/t-ru\.org/i');
    strictAssertTrue(RuTrackerUpdatePass::isTrackerSupported(
        array(array('url' => 'dht://'), array('url' => 'http://bt.t-ru.org/ann')), $filters
    ), 'RuTracker row after a leading dht:// row must still match');
    strictAssertTrue(!RuTrackerUpdatePass::isTrackerSupported(
        array(array('url' => 'dht://'), array('url' => 'http://example.com/ann')), $filters
    ), 'no row matches -> unsupported');
    strictAssertTrue(!RuTrackerUpdatePass::isTrackerSupported(array(), $filters), 'no rows at all -> unsupported');
});

upTest($suite, 'candidates go to the checker, alive stay home, fuse trips per host', function () {
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
    // A/F carry state+time+stime. C/D/E carry state+time+the fuse message.
    // Both projections are three fields, and each row is written in one bundle.
    for ($i = 0; $i < 5; $i++)
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom', 'd.set_custom'), true, false, array());

    upQueueUnchanged($rows);
    $result = RuTrackerUpdatePass::run($rows);
    strictAssertSame(array(str_repeat('B', 40)), $result['checked'], 'only the bt candidate is checked');
    strictAssertSame(array('bt2.t-ru.org'), $result['fused'], 'bt2 fuse tripped');
    strictAssertSame(2, $result['uptodate'], 'A and F counted as up to date');
    strictAssertSame($checked, $result['checked'], 'checker callback used');

    $bundles = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom|d.set_custom');
    strictAssertSame(5, count($bundles), 'two UPTODATE and three fused verdicts use one bundle each');
    $fusedWrites = array_values(array_filter($bundles, function ($request) {
        return $request['commands'][1]->params[1] === 'chk-time'
            && $request['commands'][2]->params[1] === 'chk-msg';
    }));
    strictAssertSame(3, count($fusedWrites), 'the three fused rows carry their message inside the verdict bundle');
    foreach ($fusedWrites as $request)
        strictAssertSame(ruTrackerChecker::CHKMSG_FUSE . '|bt2.t-ru.org', $request['commands'][2]->params[2],
            'the fuse token carries the tripped host and nothing else');
});

upTest($suite, 'cold torrents are skipped entirely: no checker call, no state write', function () {
    $values = upRow(str_repeat('A', 40), 0);
    // The blob is the LAST column, addressed from the end so a change to the
    // column list cannot silently append a ninth value instead of replacing it.
    $values[count($values) - 1] = 'http://bt.t-ru.org/ann?pk=x|1|0|0#'; // failed=0, success=0 -> cold
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) { throw new RuntimeException('must not run'); });
    rXMLRPCRequest::reset();
    $result = RuTrackerUpdatePass::run($rows);
    strictAssertSame(array(), $result['checked'], 'cold not checked');
    strictAssertSame(0, $result['uptodate'], 'cold is not up to date either');
    strictAssertSame(array(), rXMLRPCRequest::$requests, 'no state write for a torrent whose counters never moved');
});

// Every one of these knobs sits in the shared config where a typo is a
// plausible accident, and each has a value that quietly turns a safeguard off
// rather than failing loudly. The share is the sharpest: written as 20 instead
// of 0.2 -- the obvious mistake for "20%" -- the threshold becomes unreachable
// and the fuse never trips again, with nothing said anywhere.
upTest($suite, 'a misconfigured fuse clamps into range instead of switching itself off', function () {
    $saved = array(
        'share' => isset($GLOBALS['rutrackerFuseShare']) ? $GLOBALS['rutrackerFuseShare'] : null,
        'floor' => isset($GLOBALS['rutrackerFuseFloor']) ? $GLOBALS['rutrackerFuseFloor'] : null,
    );
    try {
        $values = array_merge(
            upRow(str_repeat('C', 40), 6, 'bt2.t-ru.org'),
            upRow(str_repeat('D', 40), 6, 'bt2.t-ru.org'),
            upRow(str_repeat('E', 40), 6, 'bt2.t-ru.org')
        );

        // "20" meant as 20%. Clamped to 1.0 the fuse still works, just at its
        // most conservative: every candidate on the host must be failing.
        // Left at 20 the threshold is ceil(20 * total) -- unreachable -- and
        // the fuse is silently inert for good.
        $GLOBALS['rutrackerFuseShare'] = 20;
        $GLOBALS['rutrackerFuseFloor'] = 3;
        strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) {});
        rXMLRPCRequest::reset();
        for ($i = 0; $i < 3; $i++)
            rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom', 'd.set_custom'), true, false, array());
        $result = RuTrackerUpdatePass::run(RuTrackerUpdatePass::parseMulticall($values));
        strictAssertSame(array('bt2.t-ru.org'), $result['fused'],
            'a share of 20 clamps to 1.0 -- every candidate failing -- rather than never tripping');

        // Zero and zero: the floor keeps a host with no candidates at all from
        // fusing, which would otherwise stop the plugin checking anything.
        $GLOBALS['rutrackerFuseShare'] = 0;
        $GLOBALS['rutrackerFuseFloor'] = 0;
        strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) {});
        rXMLRPCRequest::reset();
        for ($i = 0; $i < 8; $i++) rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom', 'd.set_custom'), true, false, array());
        $healthy = RuTrackerUpdatePass::parseMulticall(upRow(str_repeat('A', 40), 0, 'bt3.t-ru.org'));
        $result = RuTrackerUpdatePass::run($healthy);
        strictAssertSame(array(), $result['fused'],
            'a host with no candidates is never fused, whatever the floor is set to');
    } finally {
        foreach ($saved as $key => $value) {
            $name = 'rutrackerFuse' . ucfirst($key);
            if ($value === null) unset($GLOBALS[$name]);
            else $GLOBALS[$name] = $value;
        }
    }
});

// The fuse's whole point is that it excludes settled verdicts from its
// statistics: a deleted topic answers 'candidate' for ever by design, so
// counting a few of them would hold their host tripped permanently. But a
// settled row falls through the week-long rest gate once every seven days, and
// overwriting it there would make it un-settled -- so the NEXT cycle counts it.
// Each outage would convert a few more graveyard rows into evidence of an
// outage, and since the fuse branch never dispatches, nothing could put them
// back. The end state is a host that stays tripped on nothing but its own dead
// topics, with every live candidate on it never investigated again.
upTest($suite, 'a fused host defers a settled verdict rather than eating it', function () {
    $past = time() - RuTrackerUpdatePass::SETTLED_RECHECK - 1;
    $values = array_merge(
        // Three live candidates on the host trip the fuse.
        upRow(str_repeat('A', 40), 6, 'bt2.t-ru.org'),
        upRow(str_repeat('B', 40), 6, 'bt2.t-ru.org'),
        upRow(str_repeat('C', 40), 6, 'bt2.t-ru.org'),
        // And one long-settled DELETED row, past its weekly rest, on the same
        // host: it falls through the gate on exactly this cycle.
        upRow(str_repeat('D', 40), 6, 'bt2.t-ru.org', (string) ruTrackerChecker::STE_DELETED,
              '', '', '', '', (string) $past)
    );
    $rows = RuTrackerUpdatePass::parseMulticall($values);

    $checked = array();
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) use (&$checked) { $checked[] = $hash; });
    rXMLRPCRequest::reset();
    for ($i = 0; $i < 3; $i++)
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom', 'd.set_custom'), true, false, array());

    upQueueUnchanged($rows);
    $result = RuTrackerUpdatePass::run($rows);
    strictAssertSame(array('bt2.t-ru.org'), $result['fused'], 'the three live candidates trip the fuse');

    $written = array();
    foreach (rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom|d.set_custom') as $write)
        $written[] = $write['commands'][0]->params[0];
    strictAssertTrue(!in_array(str_repeat('D', 40), $written, true),
        'the settled row is left exactly as it was -- rewriting it would un-settle it');
    strictAssertSame(3, count($written), 'only the three live candidates are marked fused');
    strictAssertSame(array(), $checked, 'and a fused host dispatches nothing');
});

// Domain names are case-insensitive, so one host written two ways is one host.
// Keyed on the raw spelling, BT.T-RU.ORG became a fuse group of its own and
// neither half could reach the floor -- a fleet-wide outage would then be
// mistaken for many individual dead torrents. The announce budget already had
// this rule; the fuse now shares it rather than owning a second copy.
upTest($suite, 'the fuse counts one host once, however it is spelled', function () {
    $values = array_merge(
        upRow(str_repeat('C', 40), 6, 'bt2.t-ru.org'),
        upRow(str_repeat('D', 40), 6, 'BT2.T-RU.ORG'),
        upRow(str_repeat('E', 40), 6, 'bt2.t-ru.org.'),
        upRow(str_repeat('F', 40), 0, 'Bt2.T-Ru.Org')   // alive
    );
    $rows = RuTrackerUpdatePass::parseMulticall($values);

    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) {});
    rXMLRPCRequest::reset();
    for ($i = 0; $i < 4; $i++)
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom', 'd.set_custom'), true, false, array());

    $result = RuTrackerUpdatePass::run($rows);
    strictAssertSame(array('bt2.t-ru.org'), $result['fused'],
        'four spellings are one group of four, which reaches the floor and trips');
    strictAssertSame(array(), $result['checked'],
        'so nothing is checked: the host is down, not the torrents');
});

// A disabled t-ru row answers 'none' in classify() AND '' in hostOf(): the
// torrent takes the same generic-dispatch path as one with no RuTracker row
// at all. Anything else -- a verdict of 'none' with a reported host -- left
// it stranded between the two paths, frozen at whatever chk-state it last
// carried (a stale "checking..." included), with nothing ever touching it.
upTest($suite, 'a disabled tracker row is handed to the generic dispatch, never frozen between the paths', function () {
    $values = upRow(str_repeat('A', 40), 6);
    // From the END: the blob is the last column, and addressing it by a fixed
    // index silently APPENDED a ninth value when the wire format shrank to
    // eight -- leaving the original enabled=1 blob in place, so the case
    // exercised the ordinary candidate path under the name of a disabled row.
    $values[count($values) - 1] = 'http://bt.t-ru.org/ann?pk=x|0|6|0#'; // enabled=0
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    $ran = array();
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) use (&$ran) { $ran[] = $hash; });
    rXMLRPCRequest::reset();
    $result = RuTrackerUpdatePass::run($rows);
    strictAssertSame(array(str_repeat('A', 40)), $ran, 'the generic dispatch runs it, exactly like a no-t-ru-row torrent');
    strictAssertSame(array(str_repeat('A', 40)), $result['checked'], 'and reports it checked');
});

upTest($suite, 'a transport-error message marks CANT_REACH_TRACKER without running the checker', function () {
    $values = upRow(str_repeat('A', 40), 6, 'bt.t-ru.org', '3', 'Tracker: [Could not resolve hostname]');
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) { throw new RuntimeException('must not run'); });
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    upQueueUnchanged($rows);
    $result = RuTrackerUpdatePass::run($rows);
    strictAssertSame(array(), $result['checked'], 'transport candidates are never checked directly');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')), 'CANT_REACH_TRACKER written once');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')), 'no message written for transport error');
});

upTest($suite, 'a fast-path verdict is dropped when the row moved since the cycle-start snapshot', function () {
    // The four fast paths decide from the snapshot update.php took at the top
    // of the cycle and write without asking again. A "check" click runs through
    // batch_check.php, which takes NO cycle lock, reads state live and can leave
    // STE_META_PENDING behind while this pass still holds the old value --
    // writing UPTODATE over it strands the metadata fetch until the orphan
    // sweep. The verdicts are therefore flushed behind one fresh scan.
    $values = upRow(str_repeat('A', 40), 0);
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    rXMLRPCRequest::reset();
    // The row is no longer what the snapshot said: a click got there first.
    rXMLRPCRequest::queue('d.multicall', true, false,
        array(str_repeat('A', 40), (string) ruTrackerChecker::STE_META_PENDING, '100', '', ''));

    $result = RuTrackerUpdatePass::run($rows);

    strictAssertSame(0, $result['uptodate'], 'a verdict that was not written is not counted');
    strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom|d.set_custom'),
        'the newer state a concurrent worker wrote is left alone');
});

upTest($suite, 'the verification scan runs once for the whole cycle, not once per row', function () {
    // The per-row cost is what this pass exists to avoid: ~340 rows a cycle.
    $values = array();
    foreach (range(1, 5) as $n)
        $values = array_merge($values, upRow(str_repeat(chr(64 + $n), 40), 0));
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    rXMLRPCRequest::reset();
    $live = array();
    foreach (range(1, 5) as $n)
        $live = array_merge($live, array(str_repeat(chr(64 + $n), 40), '3', '100', '', ''));
    rXMLRPCRequest::queue('d.multicall', true, false, $live);
    foreach (range(1, 5) as $n)
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom', 'd.set_custom'), true, false, array());

    $result = RuTrackerUpdatePass::run($rows);

    strictAssertSame(5, $result['uptodate'], 'every unchanged row still gets its verdict');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.multicall')),
        'one scan for the cycle, whatever the row count');
});

upTest($suite, 'a verification scan that fails writes nothing rather than guessing', function () {
    $values = upRow(str_repeat('A', 40), 0);
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', false, false, array());

    $result = RuTrackerUpdatePass::run($rows);

    strictAssertSame(0, $result['uptodate'], 'nothing is claimed to have been written');
    strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom|d.set_custom'),
        'these verdicts are cheap to derive again next cycle; a blind write is not');
});

upTest($suite, 'an alive row with a leftover deletion counter and message clears both, with no extra read', function () {
    $values = upRow(str_repeat('A', 40), 0, 'bt.t-ru.org', '3', '', '', '2:900', 'deleting|2/3');
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) { throw new RuntimeException('alive rows never reach the checker'); });
    rXMLRPCRequest::reset();
    // Everything needed (chk-del, chk-msg) already rode in on the row. The
    // state, shared timestamp and both clears are one five-field bundle.
    rXMLRPCRequest::queue(array_fill(0, 5, 'd.set_custom'), true, false, array());

    upQueueUnchanged($rows);
    $result = RuTrackerUpdatePass::run($rows);

    strictAssertSame(1, $result['uptodate'], 'still counted as up to date');
    strictAssertSame(2, count(rXMLRPCRequest::$requests),
        'the cycle scan and one complete verdict bundle -- and nothing per row');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.multicall')),
        'one verification scan for the whole cycle, not one read per row');
    $bundles = rXMLRPCRequest::requestsFor(
        'd.set_custom|d.set_custom|d.set_custom|d.set_custom|d.set_custom');
    strictAssertSame(1, count($bundles), 'one cohesive verdict multicall');
    strictAssertSame(array(str_repeat('A', 40), 'chk-msg', ''), $bundles[0]['commands'][3]->params,
        'stale message reset inside the verdict');
    strictAssertSame(array(str_repeat('A', 40), 'chk-del', ''), $bundles[0]['commands'][4]->params,
        'deletion counter reset inside the verdict');
});

upTest($suite, 'an alive row with nothing to clear writes only the state, no clearing round trip', function () {
    $values = upRow(str_repeat('A', 40), 0); // chk-del and chk-msg both blank
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom', 'd.set_custom'), true, false, array());

    upQueueUnchanged($rows);
    $result = RuTrackerUpdatePass::run($rows);

    strictAssertSame(1, $result['uptodate'], 'still counted as up to date');
    strictAssertSame(2, count(rXMLRPCRequest::$requests),
        'the cycle scan and the state write; nothing to clear means no clearing request');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.multicall')),
        'and still just one verification scan');
    strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom'), 'no clearing multicall for a row with neither field set');
});

// Finding 4: check.php's run() has always honoured $ignoreLabels; this
// pass's direct setState() writes for alive/transport verdicts must match,
// or an ignored torrent flaps between STE_IGNORED and a scheduler-derived
// state depending on which path last touched it.
upTest($suite, 'an ignored-label row is written STE_IGNORED, never UPTODATE/CANT_REACH_TRACKER, and never reaches the checker', function () {
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

        upQueueUnchanged($rows);
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

upTest($suite, 'META_PENDING torrents are always dispatched to the checker', function () {
    $values = upRow(str_repeat('A', 40), 0, 'bt.t-ru.org', '9');
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    $checked = array();
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) use (&$checked) { $checked[] = $hash; });
    rXMLRPCRequest::reset();
    $result = RuTrackerUpdatePass::run($rows);
    strictAssertSame(array(str_repeat('A', 40)), $checked, 'meta-pending dispatched despite alive counters');
    strictAssertSame(array(), rXMLRPCRequest::$requests, 'no quiet state write races the checker for a meta-pending row');
});

upTest($suite, 'the production default checker calls ruTrackerChecker::run with the parsed row', function () {
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
// The feed is the one cheap authoritative source of topic -> forum, and it was
// consulted only for topics that had no forum yet. Nothing else in the plugin
// rewrites chk-forum (see topicsAwaitingForum()'s docblock), so a topic that
// MOVED kept its stale id for good: layer 3 read the wrong forum's dump, found
// nothing, and with layer 2 confirming "unregistered" for the re-uploaded topic
// that path ends in a DELETED verdict for a topic that plainly still exists.
upTest($suite, 'pollFeed corrects a stale chk-forum and leaves a correct one alone', function () {
    rXMLRPCRequest::reset();
    $client = (object) array('status' => 200, 'results' => upFeed());
    rXMLRPCRequest::queue('d.multicall', true, false, array(
        'HASH1', '100', '',    // topic 100 known to the feed, forum not yet cached
        'HASH2', '200', '55',  // topic 200 moved: the feed says f-22, the cache says 55
        'HASH3', '0', '',      // no chk-topic yet
        'HASH4', '999', '',    // chk-topic the feed does not know about
        'HASH5', '100', '11',  // already carries exactly what the feed says
    ));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('100', ''));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('200', '55'));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    rXMLRPCRequest::queue('d.set_custom', true, false, array());

    $changed = RuTrackerUpdatePass::pollFeed($client);

    $writes = rXMLRPCRequest::requestsFor('d.set_custom');
    strictAssertSame(2, count($writes), 'the unknown one and the stale one, and nothing else');
    strictAssertSame(array('HASH1', 'chk-forum', '11'), $writes[0]['commands'][0]->params,
        'forum id learned from the feed');
    strictAssertSame(array('HASH2', 'chk-forum', '22'), $writes[1]['commands'][0]->params,
        'and the stale id is corrected to where the topic is now');
    strictAssertSame(array('HASH1', 'HASH2'), $changed,
        'successfully changed rows are returned so this cycle can recheck them');
    // HASH5 is the reason the correction is affordable: asking about known
    // topics would otherwise mean a request per fleet topic per cycle for
    // nothing at all.
    foreach ($writes as $w)
        strictAssertTrue($w['commands'][0]->params[0] !== 'HASH5',
            'a row that already carries the right id is not rewritten');
    strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom'),
        'chk-feed-upd is no longer written at all');
});

upTest($suite, 'a feed forum correction rechecks a fresh settled candidate in the same cycle', function () {
    $hash = str_repeat('B', 40);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($hash, '200', '55'));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('200', '55'));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    $changed = RuTrackerUpdatePass::pollFeed((object) array('status' => 200, 'results' => upFeed()));
    strictAssertSame(array($hash), $changed, 'the successful correction is carried into dispatch');

    $rows = RuTrackerUpdatePass::parseMulticall(upRow(
        $hash,
        6,
        'bt.t-ru.org',
        (string) ruTrackerChecker::STE_NOT_NEED,
        '',
        '',
        '',
        ruTrackerChecker::CHKMSG_TOPIC_STATUS . '|5',
        (string) time()
    ));
    $checked = array();
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($candidate) use (&$checked) {
        $checked[] = $candidate;
    });
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('200', '22'));

    RuTrackerUpdatePass::run($rows, $changed);

    strictAssertSame(array($hash), $checked,
        'the authoritative new forum mapping outranks the week-long rest gate');
});

upTest($suite, 'pollFeed persists the recheck obligation before committing its ETag', function () {
    $hash = str_repeat('B', 40);
    Snoopy::reset();
    Snoopy::queue(200, upFeed(), array('ETag: "feed-etag-test-123"'));

    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($hash, '200', '55'));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('200', '55'));

    $mappingChecked = false;
    rXMLRPCRequest::queue('d.set_custom', true, false, function ($commands) use ($hash, &$mappingChecked) {
        $state = RuTrackerState::load('updatepass');
        $pending = $state['forum_corrections'][$hash] ?? null;
        strictAssertSame(200, $pending['topic'] ?? null,
            'at mapping time the durable obligation names the topic');
        strictAssertSame(22, $pending['forum'] ?? null,
            'at mapping time the durable obligation names the authoritative target forum');
        strictAssertTrue(!isset($state['feed_etag']),
            'at mapping time the feed ETag has NOT yet been committed');
        $mappingChecked = true;
        return array(0);
    });

    RuTrackerUpdatePass::pollFeed();

    strictAssertTrue($mappingChecked, 'the mapping write occurred and verified intermediate order');
    $finalState = RuTrackerState::load('updatepass');
    strictAssertSame('"feed-etag-test-123"', $finalState['feed_etag'] ?? null,
        'the fresh feed ETag is committed after durable application');
    $pending = $finalState['forum_corrections'][$hash] ?? null;
    strictAssertSame(200, $pending['topic'] ?? null,
        'the durable obligation names the topic that moved');
    strictAssertSame(22, $pending['forum'] ?? null,
        'the durable obligation names the authoritative target forum');
    strictAssertTrue(isset($pending['at']) && (int) $pending['at'] > 0,
        'the obligation is timestamped so vanished hashes can age out');
});

upTest($suite, 'a durable feed correction survives a later 304 and the torrent leaving seeding', function () {
    $hash = str_repeat('B', 40);
    RuTrackerState::save('updatepass', array('forum_corrections' => array(
        $hash => array('topic' => 200, 'forum' => 22),
    )));
    $rows = RuTrackerUpdatePass::parseMulticall(upRow(
        $hash,
        6,
        'bt.t-ru.org',
        (string) ruTrackerChecker::STE_DELETED,
        '',
        '',
        '',
        ruTrackerChecker::CHKMSG_DELETING . '|3/3',
        (string) time()
    ));
    $checked = array();
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($candidate) use (&$checked) {
        $checked[] = $candidate;
    });
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('200', '22'));

    RuTrackerUpdatePass::run($rows); // no in-memory pollFeed() changed-set

    strictAssertSame(array($hash), $checked,
        'the persisted correction bypasses the settled rest gate in a later process');
});

upTest($suite, 'a metadata pump keeps a durable forum correction for a later handler check', function () {
    $hash = str_repeat('E', 40);
    $record = array('topic' => 200, 'forum' => 22, 'at' => time());
    RuTrackerState::save('updatepass', array('forum_corrections' => array($hash => $record)));
    $rows = RuTrackerUpdatePass::parseMulticall(upRow(
        $hash, 0, 'bt.t-ru.org', (string) ruTrackerChecker::STE_META_PENDING
    ));
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', null);
    RuTrackerMetaFetch::$calls = array();
    RuTrackerMetaFetch::$result = ruTrackerChecker::STE_META_PENDING;
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('200', '22'));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_custom1'), true, false,
        array((string) ruTrackerChecker::STE_META_PENDING, (string) time(), ''));
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    RuTrackerUpdatePass::run($rows);

    strictAssertSame(1, count(RuTrackerMetaFetch::$calls),
        'the pending metadata transaction still advances');
    strictAssertSame($record,
        RuTrackerState::load('updatepass')['forum_corrections'][$hash] ?? null,
        'metadata progress cannot retire work that only a tracker handler consumes');
});

upTest($suite, 'a durable feed correction repairs an old mapping left behind by a crash', function () {
    $hash = str_repeat('C', 40);
    RuTrackerState::save('updatepass', array('forum_corrections' => array(
        $hash => array('topic' => 200, 'forum' => 22, 'at' => time()),
    )));
    $rows = RuTrackerUpdatePass::parseMulticall(upRow(
        $hash,
        6,
        'bt.t-ru.org',
        (string) ruTrackerChecker::STE_DELETED,
        '',
        '',
        '',
        ruTrackerChecker::CHKMSG_DELETING . '|3/3',
        (string) time()
    ));
    $checked = array();
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($candidate) use (&$checked) {
        $checked[] = $candidate;
    });
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('200', '55'));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());

    RuTrackerUpdatePass::run($rows);

    strictAssertSame(array($hash), $checked,
        'the persisted target is installed and checked even when the feed is unavailable later');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom');
    strictAssertSame(1, count($writes), 'the old mapping is repaired once');
    strictAssertSame(array($hash, 'chk-forum', '22'), $writes[0]['commands'][0]->params,
        'the durable feed target, not the stale mapping, is restored');
});

upTest($suite, 'a corrupt durable correction writes no forum and dispatches nothing', function () {
    // The fixture above with ONLY the stored spelling changed. Read through a
    // bare (int) every one of these authorised a real d.set_custom of
    // chk-forum: '22abc', '022' and 22.9 all became the forum 22, true became
    // 1, '0x16' became 0 and '-22' stayed -22 -- and 0 and -22 are ids
    // resolveForum() can never accept, so the chk-forum they installed made
    // writeForumMapping() answer FORUM_WRITE_CURRENT for ever and bought a
    // full destructive checker run every cycle with nothing ever clearing it.
    $malformed = array(
        'forum-trailing-text' => array('topic' => 200, 'forum' => '22abc'),
        'forum-leading-zero' => array('topic' => 200, 'forum' => '022'),
        'forum-float' => array('topic' => 200, 'forum' => 22.9),
        'forum-bool' => array('topic' => 200, 'forum' => true),
        'forum-hex' => array('topic' => 200, 'forum' => '0x16'),
        'forum-negative' => array('topic' => 200, 'forum' => '-22'),
        'forum-zero' => array('topic' => 200, 'forum' => '0'),
        'forum-array' => array('topic' => 200, 'forum' => array(22)),
        'topic-leading-zero' => array('topic' => '0200', 'forum' => 22),
        'topic-float' => array('topic' => 200.7, 'forum' => 22),
        'topic-trailing-text' => array('topic' => '200abc', 'forum' => 22),
        'at-leading-zero' => array('topic' => 200, 'forum' => 22, 'at' => '0100'),
        'at-text' => array('topic' => 200, 'forum' => 22, 'at' => 'recently'),
        'at-null' => array('topic' => 200, 'forum' => 22, 'at' => null),
    );
    foreach ($malformed as $label => $stored) {
        // Every case but the two about 'at' carries a perfectly good stamp,
        // so the refusal is provably about the field under test.
        if (!array_key_exists('at', $stored)) $stored['at'] = time();
        $hash = str_repeat('C', 40);
        RuTrackerState::save('updatepass', array('forum_corrections' => array($hash => $stored)));
        $rows = RuTrackerUpdatePass::parseMulticall(upRow(
            $hash,
            6,
            'bt.t-ru.org',
            (string) ruTrackerChecker::STE_DELETED,
            '',
            '',
            '',
            ruTrackerChecker::CHKMSG_DELETING . '|3/3',
            (string) time()
        ));
        $checked = array();
        strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($candidate) use (&$checked) {
            $checked[] = $candidate;
        });
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('200', '55'));
        rXMLRPCRequest::queue('d.set_custom', true, false, array());

        RuTrackerUpdatePass::run($rows);

        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
            $label . ': a stored spelling no reader accepts writes no chk-forum');
        strictAssertSame(array(), $checked,
            $label . ': and buys no destructive checker run');
        strictAssertSame($stored,
            RuTrackerState::load('updatepass')['forum_corrections'][$hash] ?? null,
            $label . ': and the bytes nobody could read are RETAINED, byte for byte');
    }

    // The bytes stay because deleting them buys nothing and costs the only
    // copy of the evidence. Nothing is dispatched and nothing is written
    // before this branch, so a retained row costs one log line per cycle
    // rather than a checker run, and rememberForumCorrection()'s prune drops
    // an unreadable row the next time the feed applies anything at all. The
    // same rule the checker's claim store already follows: the hash and the
    // document are named, the value never is.
    $hash = str_repeat('C', 40);
    $log = upCapturedLog(function () use ($hash) {
        RuTrackerState::save('updatepass', array('forum_corrections' => array(
            $hash => array('topic' => 200, 'forum' => '22abc', 'at' => time()),
        )));
        $rows = RuTrackerUpdatePass::parseMulticall(upRow(
            $hash, 6, 'bt.t-ru.org', (string) ruTrackerChecker::STE_DELETED, '', '', '',
            ruTrackerChecker::CHKMSG_DELETING . '|3/3', (string) time()));
        strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function () {
            throw new RuntimeException('a row no reader accepts must dispatch nothing');
        });
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('200', '55'));
        rXMLRPCRequest::queue('d.set_custom', true, false, array());
        RuTrackerUpdatePass::run($rows);
    });
    strictAssertTrue(strpos($log, 'updatepass.json') !== false,
        'the refusal names the document the unreadable row is in');
    strictAssertTrue(strpos($log, $hash) !== false, 'and the hash it is stored under');
    strictAssertTrue(strpos($log, '22abc') === false,
        'and never the stored value itself, which is not the log\'s to render');
    strictAssertSame(array('topic' => 200, 'forum' => '22abc', 'at' => (int) RuTrackerState::load(
            'updatepass')['forum_corrections'][$hash]['at'],),
        RuTrackerState::load('updatepass')['forum_corrections'][$hash] ?? null,
        'and the row an operator would need to diagnose it is still there');

    // Control: the canonical spelling of the same obligation still repairs
    // the stale mapping and still dispatches.
    $hash = str_repeat('C', 40);
    RuTrackerState::save('updatepass', array('forum_corrections' => array(
        $hash => array('topic' => 200, 'forum' => 22, 'at' => time()),
    )));
    $rows = RuTrackerUpdatePass::parseMulticall(upRow(
        $hash, 6, 'bt.t-ru.org', (string) ruTrackerChecker::STE_DELETED, '', '', '',
        ruTrackerChecker::CHKMSG_DELETING . '|3/3', (string) time()));
    $checked = array();
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($candidate) use (&$checked) {
        $checked[] = $candidate;
    });
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('200', '55'));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    RuTrackerUpdatePass::run($rows);
    strictAssertSame(array($hash), $checked, 'control: the well-formed obligation still dispatches');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom');
    strictAssertSame(1, count($writes), 'control: the well-formed obligation still repairs the mapping');
    strictAssertSame(array($hash, 'chk-forum', '22'), $writes[0]['commands'][0]->params,
        'control: with the id the feed actually named');
});

upTest($suite, 'an older durable correction cannot overwrite a newer one while it becomes ready', function () {
    $hash = str_repeat('D', 40);
    $old = array('topic' => 200, 'forum' => 22, 'at' => 100);
    $new = array('topic' => 200, 'forum' => 33, 'at' => 200);
    RuTrackerState::save('updatepass', array('forum_corrections' => array($hash => $new)));
    rXMLRPCRequest::reset();
    // The old implementation reaches rTorrent and would accept this old
    // mapping. The guarded implementation rejects the stale obligation
    // from the durable state before issuing any XMLRPC request.
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('200', '22'));

    strictAssertSame(false,
        strictInvoke('RuTrackerUpdatePass', 'installForumCorrection', array($hash, $old)),
        'a superseded obligation is not ready for dispatch');
    strictAssertSame(array(), rXMLRPCRequest::$requests,
        'the stale generation is rejected while holding the shared mapping lock');
    strictAssertSame($new,
        RuTrackerState::load('updatepass')['forum_corrections'][$hash] ?? null,
        'the newer durable obligation remains intact');
});

upTest($suite, 'a durable forum correction survives when checker dispatch fails before consumption', function () {
    $hash = str_repeat('F', 40);
    $record = array('topic' => 200, 'forum' => 22, 'at' => time());
    RuTrackerState::save('updatepass', array('forum_corrections' => array($hash => $record)));
    $rows = RuTrackerUpdatePass::parseMulticall(upRow(
        $hash,
        6,
        'bt.t-ru.org',
        (string) ruTrackerChecker::STE_DELETED,
        '',
        '',
        '',
        ruTrackerChecker::CHKMSG_DELETING . '|3/3',
        (string) time()
    ));

    // Attempt 1: Checker fails during execution (e.g. throwing an error or not completing)
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($candidate) {
        throw new RuntimeException('simulated dispatch failure');
    });
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('200', '22'));

    $thrown = null;
    try {
        RuTrackerUpdatePass::run($rows);
    } catch (RuntimeException $e) {
        $thrown = $e;
    }
    strictAssertTrue($thrown instanceof RuntimeException, 'checker exception must be propagated');
    strictAssertSame('simulated dispatch failure', $thrown->getMessage(), 'exception message matches');

    // Verify the obligation is still present in state
    strictAssertSame($record,
        RuTrackerState::load('updatepass')['forum_corrections'][$hash] ?? null,
        'failed dispatch must not clear durable correction');

    // Attempt 2: Successful check consumes and clears the obligation
    $checked = array();
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($candidate) use (&$checked) {
        $checked[] = $candidate;
    });
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('200', '22'));

    RuTrackerUpdatePass::run($rows);

    strictAssertSame(array($hash), $checked, 'retried run dispatches the candidate');
    strictAssertSame(null,
        RuTrackerState::load('updatepass')['forum_corrections'][$hash] ?? null,
        'successful consumption clears durable correction');
});

upTest($suite, 'pollFeed withholds the ETag of a feed it could not read', function () {
    // The readability flag is only half the fix; this is the half that matters
    // in production. An ETag committed for a feed nobody parsed makes every
    // later conditional GET answer 304 for a map that was never applied, and
    // the tracker-wide crawl redoes work the feed had already done. Asserting
    // parseFeed()'s out-parameter alone leaves that unpinned: delete the guard
    // in pollFeed() and only this case notices.
    Snoopy::reset();
    rXMLRPCRequest::reset();
    Snoopy::$queue[] = array(200,
        '<feed xmlns="http://www.w3.org/2005/Atom">'
        . '<entry><link href="https://rutracker.org/forum/viewtopic.php?t=555"/>'
        . '<category term="f-42"/></entry>'
        . '<entry><link href="https://rutracker.org/forum/viewtopic.php?t=556"/></entry>'
        . '</feed>',
        array('ETag: "feed-v2"'));

    RuTrackerUpdatePass::pollFeed();

    strictAssertSame(array(), RuTrackerState::load('updatepass'),
        'nothing is remembered about a feed that could not be read');
    strictAssertSame(array(), rXMLRPCRequest::$requests,
        'and the fleet is never scanned for a map that does not exist');
});

upTest($suite, 'pollFeed is a no-op, not an error, when the feed is unreachable', function () {
    rXMLRPCRequest::reset();
    $client = (object) array('status' => 500, 'results' => '');
    RuTrackerUpdatePass::pollFeed($client);
    strictAssertSame(array(), rXMLRPCRequest::$requests, 'an unreachable feed never even reaches the main-view multicall');
});

upTest($suite, 'pollFeed sends If-None-Match from a cached ETag and treats 304 as unchanged', function () {
    Snoopy::reset();
    rXMLRPCRequest::reset();

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
});

// reapOrphans: a service download stub
// carries chk-meta-old for as long as pump() manages it off the old
// torrent's own markers. These tests drive the sweep directly against the
// XMLRPC double: a "main" d.multicall scan (hash, chk-meta-old,
// chk-meta-until) followed by, for every marked item, a chk-meta-new AND
// chk-state read off the old hash it names -- claimed only when both
// still point at this stub and META_PENDING (finding 2: a stale
// chk-meta-new alone is not enough once the old torrent has moved to
// another state, e.g. STE_IGNORED, and pump() will never run again).

upTest($suite, 'reapOrphans erases an orphan once its deadline has passed', function () {
    $stub = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($stub, $old, '500'));
    // old torrent's chk-meta-new points elsewhere (or nowhere); chk-state is irrelevant here.
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_custom'), true, false, array('', (string) ruTrackerChecker::STE_META_PENDING, '500'));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));

    RuTrackerUpdatePass::reapOrphans(1000);

    $claims = rXMLRPCRequest::requestsFor('d.get_custom|d.get_custom|d.get_custom');
    strictAssertSame(1, count($claims), 'one claim read');
    strictAssertSame(array($old, 'chk-meta-new'), $claims[0]['commands'][0]->params, 'claim read targets the old torrent named by chk-meta-old');
    strictAssertSame(array($old, 'chk-state'), $claims[0]['commands'][1]->params, 'claim read also checks the old torrent is still META_PENDING');
    strictAssertSame(array($old, 'chk-meta-until'), $claims[0]['commands'][2]->params, 'and how long that claim is good for');

    $erased = rXMLRPCRequest::requestsFor('branch');
    strictAssertSame(1, count($erased), 'past-deadline orphan erased');
    strictAssertSame($stub, $erased[0]['commands'][0]->params[0], 'atomic erase targets the stub, not the old torrent');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')), 'no standalone erase is issued');
});

upTest($suite, 'reapOrphans leaves an orphan alone before its deadline', function () {
    $stub = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($stub, $old, '99999'));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_custom'), true, false, array('', (string) ruTrackerChecker::STE_META_PENDING, '500'));

    RuTrackerUpdatePass::reapOrphans(1000);

    strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.erase'), 'deadline not yet passed: nothing erased');
});

// A live claim is pump()'s to manage -- but only while it is live. Only pump()
// retires a META_PENDING claim, and the scheduler reaches pump() through
// update.php's "seeding" scan alone, so a predecessor the user stopped (or one
// this plugin's own transaction left stopped) never gets there. An unbounded
// claim would then protect the stub for good, leaving it downloading with
// nobody watching. Past the deadline the fetch is dead whoever holds it.
upTest($suite, 'a claim protects a stub only until the deadline it was made under', function () {
    foreach (array(
        'inside the deadline' => array('until' => '99999', 'erased' => 0),
        'past the deadline'   => array('until' => '500',   'erased' => 1),
        // The two sides can disagree: adoptStub() refreshes the stub's copy.
        // The later one wins, so a fetch pump() would still be nursing is
        // never reaped out from under it.
        'the stub is stale but the old torrent still has time'
                              => array('until' => '500', 'oldUntil' => '99999', 'erased' => 0),
    ) as $label => $case) {
        $stub = str_repeat('A', 40);
        $old = str_repeat('B', 40);
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('d.multicall', true, false, array($stub, $old, $case['until']));
        // The old torrent still claims this exact stub AND is still META_PENDING.
        rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_custom'), true, false,
            array($stub, (string) ruTrackerChecker::STE_META_PENDING,
                  isset($case['oldUntil']) ? $case['oldUntil'] : $case['until']));
        if ($case['erased'])
            rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));

        RuTrackerUpdatePass::reapOrphans(1000);

        strictAssertSame($case['erased'], count(rXMLRPCRequest::requestsFor('branch')),
            $label . ': the claim is honoured exactly as long as the fetch could still be alive');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')),
            $label . ': ownership cleanup is never a standalone erase');
    }
});

// Finding 2's own scenario: a torrent mid-fetch gains a label listed in
// $ignoreLabels. check.php's run() applies the label check BEFORE the
// META_PENDING short-circuit, writes STE_IGNORED, and pump() never runs
// again -- but chk-meta-new still names this stub. Without the chk-state
// check, reapOrphans() would treat that stale marker as a live claim and
// leave the stub (and its .chk-meta directory) behind forever.
upTest($suite, 'reapOrphans reaps a stub once its old torrent has moved on to STE_IGNORED, even though chk-meta-new still names it', function () {
    $stub = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($stub, $old, '500'));
    // chk-meta-new is stale-still-pointing-at-the-stub, but the old torrent
    // itself moved to STE_IGNORED: the label check pre-empted pump().
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_custom'), true, false, array($stub, (string) ruTrackerChecker::STE_IGNORED, '500'));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));

    RuTrackerUpdatePass::reapOrphans(1000);

    $erased = rXMLRPCRequest::requestsFor('branch');
    strictAssertSame(1, count($erased), 'a stale chk-meta-new marker on a no-longer-META_PENDING old torrent must not be treated as a live claim');
    strictAssertSame($stub, $erased[0]['commands'][0]->params[0], 'atomically erased the stub');
});

upTest($suite, 'reapOrphans never touches an ordinary torrent with no chk-meta-old marker', function () {
    $ordinary = str_repeat('T', 40);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($ordinary, '', '0'));

    RuTrackerUpdatePass::reapOrphans(1000);

    strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.get_custom|d.get_custom|d.get_custom'),
        'no claim read for a torrent that is not a stub');
    strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.erase'), 'no erase either');
});

upTest($suite, 'reapOrphans reaps a stub whose old torrent no longer exists, once past the deadline', function () {
    $stub = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($stub, $old, '500'));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_custom'), true, true, array()); // old torrent is gone entirely: faulted read
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));

    RuTrackerUpdatePass::reapOrphans(1000);

    $erased = rXMLRPCRequest::requestsFor('branch');
    strictAssertSame(1, count($erased), 'a stub whose old torrent vanished is treated as an orphan and reaped past its deadline');
    strictAssertSame($stub, $erased[0]['commands'][0]->params[0], 'atomically erased the stub');
});

// The fleet row and the predecessor claim are both snapshots. A concurrent
// adopt/harvest may refresh or replace the item at the same hash before the
// sweep reaches d.erase, so a failed claim read may not turn the stale fleet
// deadline into permission to erase whatever is there now.
upTest($suite, 'reapOrphans revalidates the stub identity and deadline inside the erase branch', function () {
    $stub = str_repeat('A', 40);
    $old = str_repeat('B', 40);

    foreach (array(
        'a concurrent adoption refreshed the deadline' => array($old, '2000'),
        'a real replacement took over the same hash' => array('', '0'),
    ) as $label => $fresh) {
        $occupant = array('owner' => $old, 'until' => '500');
        $boundarySwaps = 0;
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('d.multicall', true, false, array($stub, $old, '500'));
        rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_custom'), false, false, array());
        // execute() records the branch request before invoking this callback.
        // Move the daemon-side occupant only now: the fleet snapshot and the
        // last PHP-side probe have already completed, but the branch has not
        // evaluated its byte-exact condition yet.
        rXMLRPCRequest::queue('branch', true, false,
            function ($commands) use (&$occupant, &$boundarySwaps, $fresh, $old) {
                strictAssertSame(array('owner' => $old, 'until' => '500'), $occupant,
                    'the callback starts from the generation PHP observed');
                $occupant = array('owner' => $fresh[0], 'until' => $fresh[1]);
                $boundarySwaps++;
                strictAssertSame(1, count(rXMLRPCRequest::requestsFor('branch')),
                    'the occupant changes only after the atomic request is recorded');
                return array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED);
            });

        RuTrackerUpdatePass::reapOrphans(1000);

        strictAssertSame(1, $boundarySwaps,
            $label . ': the daemon-boundary occupant swap really executed');
        strictAssertSame(array('owner' => $fresh[0], 'until' => $fresh[1]), $occupant,
            $label . ': the model now represents the replacement occupant');
        $branches = rXMLRPCRequest::requestsFor('branch');
        strictAssertSame(1, count($branches), $label . ': one daemon-side ownership decision is made');
        strictAssertTrue(strpos($branches[0]['commands'][0]->params[1], 'chk-meta-old') !== false,
            $label . ': the branch checks the exact owner at the destructive boundary');
        strictAssertTrue(strpos($branches[0]['commands'][0]->params[1], 'chk-meta-until') !== false,
            $label . ': the branch checks the exact raw deadline at the destructive boundary');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')),
            $label . ': a stale scan cannot erase the current occupant');
    }
});

// This pass is the scheduler's ONLY route into ruTrackerChecker::run(), and it
// carries every registered tracker, not just RuTracker. The layer-1 detector
// reads RuTracker tracker rows exclusively, so it answers 'none' for a
// Kinozal/NNMClub/Toloka/tfile torrent -- which must not be read as "no signal
// worth a request", or those handlers stop being called at all.
upTest($suite, 'a torrent from another supported tracker still reaches its handler', function () {
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

    upQueueUnchanged($rows);
    $result = RuTrackerUpdatePass::run($rows);

    strictAssertSame(array($kinozal, $nnmclub), $result['checked'],
        'both foreign-tracker rows are dispatched, in row order');
    strictAssertSame(array($kinozal, $nnmclub), $checked, 'the checker itself received them');
    strictAssertSame(array(), $result['fused'], 'a foreign announce host never feeds the RuTracker fuse');
    strictAssertSame(1, $result['uptodate'], 'only the RuTracker row took the alive fast path');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom|d.set_custom')),
        'a dispatched row gets no scheduler-side state write -- its own handler decides');
});

upTest($suite, 'F-01: foreign authoritative comment with RuTracker cross-seed announce is dispatched to foreign handler', function () {
    $kinozalMixed = str_repeat('K', 40);
    $nnmclubMixed = str_repeat('M', 40);
    $rutracker = str_repeat('A', 40);

    // Kinozal torrent with a RuTracker announce row that is 'alive' (0 failed)
    $kinozalTrackers = "http://tr2.torrent4me.com/ann?pk=x|1|0|5#http://bt.t-ru.org/ann?pk=x|1|0|5#";
    // NNMClub torrent with a RuTracker announce row that is in transport error (6 failed)
    $nnmclubTrackers = "http://bt.nnmclub.to/ann?pk=x|1|0|5#http://bt.t-ru.org/ann?pk=x|1|6|0#";

    $rows = array(
        array(
            'hash' => $kinozalMixed,
            'state' => 3,
            'time' => 100,
            'label' => '',
            'message' => '',
            'del' => '',
            'msg' => '',
            'trackers' => RuTrackerDetector::parseTrackerBlob($kinozalTrackers),
            'trackers_complete' => true,
            'comment' => 'http://kinozal.tv/details.php?id=12345',
        ),
        array(
            'hash' => $nnmclubMixed,
            'state' => 3,
            'time' => 100,
            'label' => '',
            'message' => 'Tracker: [Could not resolve hostname]',
            'del' => '',
            'msg' => '',
            'trackers' => RuTrackerDetector::parseTrackerBlob($nnmclubTrackers),
            'trackers_complete' => true,
            'comment' => 'http://nnmclub.to/forum/viewtopic.php?t=67890',
        ),
        array(
            'hash' => $rutracker,
            'state' => 3,
            'time' => 100,
            'label' => '',
            'message' => '',
            'del' => '',
            'msg' => '',
            'trackers' => RuTrackerDetector::parseTrackerBlob("http://bt.t-ru.org/ann?pk=x|1|0|5#"),
            'trackers_complete' => true,
            'comment' => 'http://rutracker.org/forum/viewtopic.php?t=11111',
        ),
    );

    $checked = array();
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) use (&$checked) { $checked[] = $hash; });
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom', 'd.set_custom'), true, false, array());

    upQueueUnchanged($rows);
    $result = RuTrackerUpdatePass::run($rows);

    strictAssertSame(array($kinozalMixed, $nnmclubMixed), $result['checked'],
        'both mixed-tracker foreign-comment torrents are dispatched to checker');
    strictAssertSame(array($kinozalMixed, $nnmclubMixed), $checked, 'checker received both foreign-comment hashes');
    strictAssertSame(1, $result['uptodate'], 'only the genuine RuTracker row took the alive fast path');
});

upTest($suite, 'F-01: production scheduler rows resolve foreign ownership from the session torrent', function () {
    $repo = testFindRepoRoot();
    $code = '$repo=' . var_export($repo, true) . ';'
        . 'require $repo."/tests/plugins/rutracker_check/TestLib.php";'
        . '$previous=getcwd();chdir($repo."/php");require_once $repo."/php/Torrent.php";chdir($previous);'
        . 'class UpdatePassSessionTorrentEncoder extends Torrent {'
        . 'public static function raw($value){return self::encode($value);}}'
        . 'eval(loadClassDefinition($repo."/plugins/rutracker_check/check.php","ruTrackerChecker"));'
        . 'foreach(array("rutracker","anidub","kinozal","nnmclub","tapocheknet","tfile","toloka") as $tracker)'
        . '{require_once $repo."/plugins/rutracker_check/trackers/".$tracker.".php";}'
        . 'require_once $repo."/plugins/rutracker_check/updatepass.php";'
        . 'strictWithStateDir("chk-updatepass-session",function($tmp){'
        . '$hash=str_repeat("B",40);$kinozal="http://tr2.torrent4me.com/ann?pk=x";'
        . '$rutracker="http://bt.t-ru.org/ann?pk=x";'
        . '$raw=UpdatePassSessionTorrentEncoder::raw(array('
        . '"announce"=>$kinozal,"announce-list"=>array(array($kinozal),array($rutracker)),'
        . '"comment"=>"http://kinozal.tv/details.php?id=12345",'
        . '"info"=>array("length"=>1,"name"=>"mixed-owner.bin","piece length"=>16384,'
        . '"pieces"=>str_repeat("\\0",20))));'
        . 'rTorrentSettings::get()->session=$tmp."/";file_put_contents($tmp."/".$hash.".torrent",$raw);'
        . '$torrent=new Torrent($tmp."/".$hash.".torrent");'
        . 'strictAssertSame(false,$torrent->errors(),"the session torrent is readable by production Torrent");'
        . 'strictAssertSame(7,count(ruTrackerChecker::supportedTrackers()),"all production tracker registrations are loaded");'
        . '$values=array($hash,"3","100","","","","",'
        . '$kinozal."|1|0|5#".$rutracker."|1|0|5#");'
        . '$rows=RuTrackerUpdatePass::parseMulticall($values);'
        . 'strictAssertSame(1,count($rows),"one actual eight-field scheduler row is parsed");'
        . 'strictAssertTrue(!array_key_exists("comment",$rows[0]),"the scheduler row has no synthetic comment field");'
        . '$checked=array();strictSetPrivateStatic("RuTrackerUpdatePass","checker",'
        . 'function($seen)use(&$checked){$checked[]=$seen;});'
        . '$GLOBALS["rutrackerFuseShare"]=0.2;$GLOBALS["rutrackerFuseFloor"]=3;'
        . 'rXMLRPCRequest::reset();'
        . 'rXMLRPCRequest::queue(array("d.set_custom","d.set_custom","d.set_custom"),true,false,array());'
        . 'rXMLRPCRequest::queue("d.multicall",true,false,array($hash,"3","100","",""));'
        . '$result=RuTrackerUpdatePass::run($rows);'
        . 'strictAssertSame(array($hash),$result["checked"],'
        . '"the production session-file owner bypasses the RuTracker alive path");'
        . 'strictAssertSame(array($hash),$checked,"the foreign owner is dispatched exactly once");'
        . '});echo "production session fallback dispatched foreign owner\\n";';

    $output = array();
    $status = 0;
    exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -r '.escapeshellarg($code).' 2>&1', $output, $status);
    strictAssertSame(0, $status,
        'the production registration/session fallback scheduler route passes: '.implode("\n", $output));
    strictAssertTrue(in_array('production session fallback dispatched foreign owner', $output, true),
        'the subprocess reached the real session-file dispatch assertion');
});

upTest($suite, 'an ignored label still short-circuits a foreign-tracker torrent', function () {
    $GLOBALS['ignoreLabels'] = array('tv-sonarr');
    try {
        $rows = RuTrackerUpdatePass::parseMulticall(
            upRow(str_repeat('K', 40), 0, 'tr2.torrent4me.com', '4', '', 'tv-sonarr'));
        strictSetPrivateStatic('RuTrackerUpdatePass', 'checker',
            function ($hash) { throw new RuntimeException('an ignored row must never reach the checker'); });
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

        upQueueUnchanged($rows);
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

// Rows of (hash, chk-replacement, chk-replaces[, chk-replacing]). The fourth
// column is the predecessor's own recovery key; most rows do not carry one, so
// it defaults to empty rather than being repeated at every call site.
function sweepFixtureMarker($marker)
{
    // Historical fixtures called genuine plugin generations nonce/nonce2/etc.
    // Turn only those aliases into the real 32-hex grammar. Deliberately leave
    // every other string untouched so non-plugin-marker tests stay hostile.
    return is_string($marker) && strpos($marker, 'nonce') === 0 ? md5($marker) : $marker;
}

function sweepScan($rows)
{
    $flat = array();
    foreach ($rows as $row) {
        $row = array_values($row);
        while (count($row) < RuTrackerUpdatePass::SWEEP_COLUMNS) $row[] = '';
		$row[1] = sweepFixtureMarker($row[1]);
        foreach ($row as $cell) $flat[] = $cell;
    }
    rXMLRPCRequest::queue('d.multicall', true, false, $flat);
}

// state, is_open, chunks_hashed, completed_bytes, complete, message, chk-stime, chk-state, directory_base
// The last two columns are the marker and the record, RE-READ at the moment of
// acting: everything the sweep does from here is irreversible, and the values
// it was handed came from a fleet scan several round trips ago. They default
// to the shape sweepScan() uses, so a test only names them when it is about
// the row changing under the sweep.
function sweepDetail($state, $open, $hashed = 0, $bytes = 0, $complete = 0, $message = '', $stime = '0', $chkState = '2',
                     $marker = 'nonce', $record = null)
{
    if ($record === null) $record = str_repeat('B', 40) . '-started-1000';
	$marker = sweepFixtureMarker($marker);
    rXMLRPCRequest::queue(
        array('d.get_state', 'd.is_open', 'd.get_chunks_hashed', 'd.get_completed_bytes', 'd.get_complete',
            'd.get_message', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom'),
        true, false, array($state, $open, $hashed, $bytes, $complete, $message, $stime, $chkState,
            $marker, $record));
}

// The record-less branch writes nothing at all -- its only observable effect
// is a debug line -- so reading the line back is the only way to prove it
// still fires. ruTrackerChecker::logDebug() goes through the real
// FileUtil::toLog(), which appends to $log_file when debugging is on.
function upCapturedLog($body)
{
    return testCapturedAppLog($body, true);
}

// Which of the two irreversible outcomes the branch carries: runState() puts
// d.open (and d.start) in its true body, clearCustoms() only ever puts
// d.set_custom there. Counting branch requests alone cannot tell "the
// replacement was activated" from "its keys were retired and its run state
// left alone", which is exactly the difference this guard decides.
function sweepBranchOpens($hash)
{
    $requests = sweepBranchRequestsForHash($hash);
    if (!count($requests)) return null;
    return strpos((string) $requests[0]['commands'][0]->params[2], 'd.open') !== false;
}

function sweepBranchRequestsForHash($hash)
{
    return array_values(array_filter(rXMLRPCRequest::requestsFor('branch'), function ($request) use ($hash) {
        return isset($request['commands'][0]->params[0])
            && $request['commands'][0]->params[0] === $hash;
    }));
}

function sweepAssertNoStandaloneOwnershipMutation($message)
{
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')), $message . ': no standalone erase');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open')), $message . ': no standalone open');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')), $message . ': no standalone open/start');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
        $message . ': no standalone paired custom write');
}

upTest($suite, 'testSweepPublishesCommittedTmpBeforeActivationOrClear', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    $record = $old . '-started-1000';
    UpdatePassErasedataFake::$recoverResults = array(ERASEDATA_CLEANUP_READY);
    UpdatePassErasedataFake::$kickResults = array(false);
    sweepScan(array(array($hash, 'nonce', $record)));
    sweepDetail(0, 0, 0, 0, 0, '', '1000', '2', 'nonce', $record);
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue('branch', true, false, function () use ($hash) {
        UpdatePassErasedataFake::$events[] = 'activate:' . $hash;
        return array(RuTrackerAtomicOwnership::SENTINEL_ACTED);
    });

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(array(
        'recover:' . $old,
        'kick:' . $old,
        'activate:' . $hash,
    ), UpdatePassErasedataFake::$events,
        'committed cleanup is recovered and kicked before activation may clear ownership');
    strictAssertSame(array(array($old, $hash, sweepFixtureMarker('nonce'), $record)),
        UpdatePassErasedataFake::$recoverCalls, 'recovery receives the exact marked generation');
});

upTest($suite, 'testSweepPublishRetryKeepsReplacementKeys', function () {
    $hash = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    $record = $old . '-started-1000';
    foreach (array('documented retry' => ERASEDATA_CLEANUP_RETRY, 'unexpected status' => 'unexpected') as $label => $status) {
        rXMLRPCRequest::reset();
        UpdatePassErasedataFake::reset();
        UpdatePassErasedataFake::$recoverResults = array($status);
        sweepScan(array(array($hash, 'nonce', $record)));
        sweepDetail(0, 0, 0, 0, 0, '', '1000', '2', 'nonce', $record);
        rXMLRPCRequest::queue('d.hash', true, true, array());
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));

        RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

        strictAssertSame(array('recover:' . $old), UpdatePassErasedataFake::$events,
            $label . ': a retained cleanup stops this row before activation or collector kick');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('branch')),
            $label . ': every non-ready/non-none cleanup result keeps both successor ownership keys intact');
    }
});

upTest($suite, 'testSweepCancelsPreparedTmpBeforePredecessorRevival', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    $record = $old . '-started-1000';
    UpdatePassErasedataFake::$cancelResults = array(ERASEDATA_CLEANUP_READY);
    sweepScan(array(array($hash, 'nonce', $record)));
    sweepDetail(0, 0, 0, 0, 0, '', '1000', '2', 'nonce', $record);
    rXMLRPCRequest::queue('d.hash', true, false, array($old));
    rXMLRPCRequest::queue('branch', true, false, function () use ($old) {
        UpdatePassErasedataFake::$events[] = 'revive:' . $old;
        return array(RuTrackerAtomicOwnership::SENTINEL_REVIVED);
    });
    rXMLRPCRequest::queue('branch', true, false, function () use ($hash) {
        UpdatePassErasedataFake::$events[] = 'discard:' . $hash;
        return array(RuTrackerAtomicOwnership::SENTINEL_ERASED);
    });

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(array(
        'cancel:' . $old,
        'revive:' . $old,
        'discard:' . $hash,
    ), UpdatePassErasedataFake::$events,
        'prepared cleanup is cancelled before predecessor revival and staged discard');
});

upTest($suite, 'testSweepCancelRetryKeepsBothGenerations', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    $record = $old . '-started-1000';
    UpdatePassErasedataFake::$cancelResults = array(ERASEDATA_CLEANUP_RETRY);
    sweepScan(array(array($hash, 'nonce', $record)));
    sweepDetail(0, 0, 0, 0, 0, '', '1000', '2', 'nonce', $record);
    rXMLRPCRequest::queue('d.hash', true, false, array($old));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_REVIVED));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(array('cancel:' . $old), UpdatePassErasedataFake::$events,
        'cancellation RETRY stops before predecessor or staged generation mutation');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('branch')),
        'both exact replacement generations remain retryable');
});

upTest($suite, 'testAlreadyLiveMarkedRowStillReconcilesCleanup', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    $record = $old . '-started-1000';
    UpdatePassErasedataFake::$recoverResults = array(ERASEDATA_CLEANUP_READY);
    sweepScan(array(array($hash, 'nonce', $record)));
    sweepDetail(1, 1, 100, 500, 1, '', '1000', '2', 'nonce', $record);
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue('branch', true, false, function () use ($hash) {
        UpdatePassErasedataFake::$events[] = 'clear:' . $hash;
        return array(RuTrackerAtomicOwnership::SENTINEL_CLEARED);
    });

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(array(
        'recover:' . $old,
        'kick:' . $old,
        'clear:' . $hash,
    ), UpdatePassErasedataFake::$events,
        'an already-live successor reconciles durable cleanup before its final key clear');
});

upTest($suite, 'testSweepWithoutCleanupArtifactPreservesExistingBehavior', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    $record = $old . '-started-1000';
    UpdatePassErasedataFake::$recoverResults = array(ERASEDATA_CLEANUP_NONE);
    sweepScan(array(array($hash, 'nonce', $record)));
    sweepDetail(0, 0, 0, 0, 0, '', '1000', '2', 'nonce', $record);
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue('branch', true, false, function () use ($hash) {
        UpdatePassErasedataFake::$events[] = 'activate:' . $hash;
        return array(RuTrackerAtomicOwnership::SENTINEL_ACTED);
    });

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(array('recover:' . $old, 'activate:' . $hash), UpdatePassErasedataFake::$events,
        'NONE follows the pre-existing activation path without a collector kick');
    strictAssertSame(array(), UpdatePassErasedataFake::$kickCalls, 'no artifact means there is nothing to kick');
});

upTest($suite, 'testOneRetainedCleanupDoesNotStopFollowingRows', function () {
    rXMLRPCRequest::reset();
    $firstHash = str_repeat('A', 40);
    $firstOld = str_repeat('B', 40);
    $secondHash = str_repeat('C', 40);
    $secondOld = str_repeat('D', 40);
    $firstRecord = $firstOld . '-started-1000';
    $secondRecord = $secondOld . '-started-1000';
    UpdatePassErasedataFake::$recoverResults = array(ERASEDATA_CLEANUP_RETRY, ERASEDATA_CLEANUP_NONE);
    sweepScan(array(
        array($firstHash, 'nonce-a', $firstRecord),
        array($secondHash, 'nonce-b', $secondRecord),
    ));
    sweepDetail(0, 0, 0, 0, 0, '', '1000', '2', 'nonce-a', $firstRecord);
    sweepDetail(0, 0, 0, 0, 0, '', '1000', '2', 'nonce-b', $secondRecord);
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(array($firstOld, $secondOld), array_map(function ($call) { return $call[0]; },
        UpdatePassErasedataFake::$recoverCalls), 'each marked row gets its own cleanup decision');
    $branches = sweepBranchRequestsForHash($secondHash);
    strictAssertSame(1, count($branches), 'a retained first cleanup does not suppress the following row');
    strictAssertSame(0, count(sweepBranchRequestsForHash($firstHash)), 'the retained row itself keeps its keys');
});

upTest($suite, 'testReplacingRowCannotClearRecoveryKeyBeforeCleanupCancel', function () {
    $old = str_repeat('A', 40);
    $successor = str_repeat('B', 40);
    $encoded = $successor . '-started-1000';
    $marker = sweepFixtureMarker('nonce');
    $record = $old . '-started-1000';

    rXMLRPCRequest::reset();
    UpdatePassErasedataFake::$cancelResults = array(ERASEDATA_CLEANUP_RETRY);
    sweepScan(array(array($old, '', '', $encoded)));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_state', 'd.is_open'), true, false,
        array($encoded, 0, 0));
    rXMLRPCRequest::queue(array('d.hash', 'd.get_custom', 'd.get_custom'), true, false,
        array($successor, $marker, $record));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(array(array($old, $successor, $marker, $record)), UpdatePassErasedataFake::$cancelCalls,
        'the coherent successor generation is passed to cleanup cancellation');
    strictAssertSame(0, count(sweepBranchRequestsForHash($old)),
        'cancellation RETRY keeps the predecessor recovery key');

    rXMLRPCRequest::reset();
    UpdatePassErasedataFake::reset();
    UpdatePassErasedataFake::$cancelResults = array(ERASEDATA_CLEANUP_READY);
    sweepScan(array(array($old, '', '', $encoded)));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_state', 'd.is_open'), true, false,
        array($encoded, 0, 0));
    rXMLRPCRequest::queue(array('d.hash', 'd.get_custom', 'd.get_custom'), true, false,
        array($successor, $marker, $record));
    rXMLRPCRequest::queue('branch', true, false, function () use ($old) {
        UpdatePassErasedataFake::$events[] = 'clear:' . $old;
        return array(RuTrackerAtomicOwnership::SENTINEL_CLEARED);
    });

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(array('cancel:' . $old, 'clear:' . $old), UpdatePassErasedataFake::$events,
        'READY cancellation precedes the exact predecessor-key clear');
});

function sweepAssertNoncanonicalReplacingPairRetained($label, $encoded, $successorRecord)
{
    rXMLRPCRequest::reset();
    $old = str_repeat('A', 40);
    $successor = str_repeat('B', 40);
    $marker = sweepFixtureMarker('nonce');
    sweepScan(array(array($old, '', '', $encoded)));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_state', 'd.is_open'), true, false,
        array($encoded, 0, 0));
    rXMLRPCRequest::queue(array('d.hash', 'd.get_custom', 'd.get_custom'), true, false,
        array($successor, $marker, $successorRecord));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(array(), UpdatePassErasedataFake::$cancelCalls,
        $label . ': decoded noncanonical bytes cannot cancel the cleanup generation');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('branch')),
        $label . ': neither generation may be cleared, revived, or discarded');
    sweepAssertNoStandaloneOwnershipMutation($label . ': both generations remain intact');
}

upTest($suite, 'testReplacingRowRetainsLowercaseHashPredecessorRecord', function () {
    sweepAssertNoncanonicalReplacingPairRetained('lowercase predecessor hash',
        strtolower(str_repeat('B', 40)) . '-started-1000',
        str_repeat('A', 40) . '-started-1000');
});

upTest($suite, 'testReplacingRowRetainsLeadingZeroEpochPredecessorRecord', function () {
    sweepAssertNoncanonicalReplacingPairRetained('leading-zero predecessor epoch',
        str_repeat('B', 40) . '-started-01000',
        str_repeat('A', 40) . '-started-1000');
});

upTest($suite, 'testReplacingRowRetainsLowercaseHashSuccessorRecord', function () {
    sweepAssertNoncanonicalReplacingPairRetained('lowercase successor hash',
        str_repeat('B', 40) . '-started-1000',
        strtolower(str_repeat('A', 40)) . '-started-1000');
});

upTest($suite, 'testReplacingRowRetainsLeadingZeroEpochSuccessorRecord', function () {
    sweepAssertNoncanonicalReplacingPairRetained('leading-zero successor epoch',
        str_repeat('B', 40) . '-started-1000',
        str_repeat('A', 40) . '-started-01000');
});

upTest($suite, 'sweepReplacements scans main for the hash, both markers and the record', function () {
    rXMLRPCRequest::reset();
    sweepScan(array());

    RuTrackerUpdatePass::sweepReplacements(1000);

    $scans = rXMLRPCRequest::requestsFor('d.multicall');
    strictAssertSame(1, count($scans), 'one fleet scan per cycle');
    strictAssertSame(
        array('main', 'd.get_hash=', 'd.get_custom=chk-replacement', 'd.get_custom=chk-replaces',
              'd.get_custom=chk-replacing'),
        $scans[0]['commands'][0]->params,
        'the scan must walk main: BOTH halves of a stranded transaction are stopped and closed,'
        . ' so neither is in seeding -- the staged copy carries chk-replacement, the predecessor chk-replacing'
    );
    strictAssertSame(false, $scans[0]['important'], 'a repair pass may never sink the cycle it runs in');
});

// The window nothing could see into. createTorrent() stops and closes the
// user's torrent, and only a round trip later does a staged copy exist to
// carry the transaction's marker. A death in between left the torrent stopped,
// closed -- so outside the "seeding" view the cycle scans -- carrying neither
// marker either sweep looked for, with nothing anywhere recording why. Now the
// stop and the record are one multicall, and this is what reads it.
upTest($suite, 'a predecessor stopped for a replacement that never staged is put back', function () {
    foreach (array(
        // A 'started' wish atomically sends d.open+d.start; an 'open' one
        // sends d.open alone, because reopening a paused torrent must not start it.
        'it was seeding'          => array('run' => 'started', 'sentinel' => RuTrackerAtomicOwnership::SENTINEL_ACTED),
        'it was paused'           => array('run' => 'open',    'sentinel' => RuTrackerAtomicOwnership::SENTINEL_ACTED),
        // Stopped and closed BEFORE the transaction began: being stopped now
        // is where the user left it, not a strand.
        'it was already stopped'  => array('run' => 'stopped', 'sentinel' => RuTrackerAtomicOwnership::SENTINEL_CLEARED),
    ) as $label => $case) {
        rXMLRPCRequest::reset();
        $old = str_repeat('A', 40);
        $successor = str_repeat('B', 40);
        // No chk-replacement, no chk-replaces -- only the predecessor's own key.
        sweepScan(array(array($old, '', '', $successor . '-' . $case['run'] . '-1000')));
        // The sweep re-reads the recovery key before it acts: everything below
        // is irreversible and the scan's value is already a few round trips old.
        $encoded = $successor . '-' . $case['run'] . '-1000';
        rXMLRPCRequest::queue(array('d.get_custom', 'd.get_state', 'd.is_open'), true, false,
            array($encoded, 0, 0));
        rXMLRPCRequest::queue(array('d.hash', 'd.get_custom', 'd.get_custom'), false, true, array());
        rXMLRPCRequest::queue('d.hash', true, true, array());   // the successor never appeared
        rXMLRPCRequest::queue('branch', true, false, array($case['sentinel']));

        $sweepNow = 1000 + ruTrackerChecker::MAX_LOCK_TIME + 1;
        RuTrackerUpdatePass::sweepReplacements($sweepNow);

        $branches = sweepBranchRequestsForHash($old);
        strictAssertSame(1, count($branches), $label . ': one exact-generation branch settles recovery');
        strictAssertTrue(strpos($branches[0]['commands'][0]->params[1], 'chk-replacing') !== false,
            $label . ': the recovery generation is checked at the action boundary');
        strictAssertTrue(strpos($branches[0]['commands'][0]->params[1], 'd.get_state=') !== false
            && strpos($branches[0]['commands'][0]->params[1], 'd.is_open=') !== false,
            $label . ': observed stopped/closed state is part of the same branch');
        sweepAssertNoStandaloneOwnershipMutation($label);
    }

    // A transaction that DID stage owns itself through the marked-row branch;
    // the predecessor's key is then only a leftover to clear.
    rXMLRPCRequest::reset();
    $old = str_repeat('A', 40);
    $successor = str_repeat('B', 40);
    sweepScan(array(array($old, '', '', $successor . '-started-1000')));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_state', 'd.is_open'), true, false,
        array($successor . '-started-1000', 0, 0));
    rXMLRPCRequest::queue(array('d.hash', 'd.get_custom', 'd.get_custom'), true, false,
        array($successor, str_repeat('d', 32), $old . '-started-1000'));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
        'a staged transaction is not restored from this branch');
    strictAssertSame(1, count(sweepBranchRequestsForHash($old)), 'the exact leftover generation goes atomically');

    // The key moved between the scan and the act: a fresh replacement started
    // under this sweep, and acting on the old record would restart a torrent
    // that live transaction has just deliberately stopped.
    rXMLRPCRequest::reset();
    sweepScan(array(array($old, '', '', $successor . '-started-1000')));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_state', 'd.is_open'), true, false,
        array($successor . '-started-9999', 0, 0));
    // Everything the UNGUARDED path would go on to use, queued so that the
    // guard is the only thing standing between this fixture and a restart.
    // Without them the sweep would stop at an unanswered request and the
    // assertions below would hold for the wrong reason.
    rXMLRPCRequest::queue(array('d.hash', 'd.get_custom', 'd.get_custom'), false, true, array());
    rXMLRPCRequest::queue('d.hash', true, true, array());               // successor absent

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);
    strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.open|d.start'),
        'the sweep stands down instead of restarting a torrent somebody else is holding');
    strictAssertSame(array(), rXMLRPCRequest::requestsFor('branch'),
        'and never blanks the recovery key that live transaction is relying on');

    // Still inside the lock window: it may simply be in flight.
    rXMLRPCRequest::reset();
    sweepScan(array(array($old, '', '', $successor . '-started-1000')));
    RuTrackerUpdatePass::sweepReplacements(1000 + 1);
    strictAssertSame(1, count(rXMLRPCRequest::$requests), 'a young transaction is not touched at all');
});

upTest($suite, 'sweepReplacements restores predecessor when successor hash is foreign or record-less', function () {
    foreach (array(
        'foreign occupant with empty marker' => array('', ''),
        'malformed non-plugin marker'        => array('marker-value', str_repeat('A', 40) . '-started-1000'),
        'unmatched predecessor in record'    => array(str_repeat('d', 32), str_repeat('C', 40) . '-started-1000'),
        'unreadable record'                  => array(str_repeat('d', 32), 'garbage'),
    ) as $label => $succState) {
        list($succMarker, $succRecord) = $succState;
        rXMLRPCRequest::reset();
        $old = str_repeat('A', 40);
        $successor = str_repeat('B', 40);
        sweepScan(array(array($old, '', '', $successor . '-started-1000')));
        rXMLRPCRequest::queue(array('d.get_custom', 'd.get_state', 'd.is_open'), true, false,
            array($successor . '-started-1000', 0, 0));
        rXMLRPCRequest::queue(array('d.hash', 'd.get_custom', 'd.get_custom'), true, false,
            array($successor, $succMarker, $succRecord));
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));

        $sweepNow = 1000 + ruTrackerChecker::MAX_LOCK_TIME + 1;
        RuTrackerUpdatePass::sweepReplacements($sweepNow);
        strictAssertSame(1, count(sweepBranchRequestsForHash($old)),
            $label . ': predecessor must be restored and its exact recovery generation cleared atomically');
        sweepAssertNoStandaloneOwnershipMutation($label);
    }
});

upTest($suite, 'predecessor sweep proves successor existence and ownership in one observation', function () {
    rXMLRPCRequest::reset();
    $old = str_repeat('A', 40);
    $successor = str_repeat('B', 40);
    $encoded = $successor . '-started-1000';
    sweepScan(array(array($old, '', '', $encoded)));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_state', 'd.is_open'), true, false,
        array($encoded, 0, 0));
    rXMLRPCRequest::queue(array('d.hash', 'd.get_custom', 'd.get_custom'), true, false,
        array($successor, '', ''));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));

    $sweepNow = 1000 + ruTrackerChecker::MAX_LOCK_TIME + 1;
    RuTrackerUpdatePass::sweepReplacements($sweepNow);

    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.hash|d.get_custom|d.get_custom')),
        'existence and both ownership fields come from one coherent request');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.hash')),
        'there is no separate existence observation to mix with another successor generation');
    strictAssertSame(1, count(sweepBranchRequestsForHash($old)),
        'a coherently foreign successor restores the predecessor and clears the exact generation atomically');
    sweepAssertNoStandaloneOwnershipMutation('coherent foreign successor recovery');

    $after = ruTrackerChecker::claimCheckForWorker($old, $sweepNow);
    strictAssertTrue($after !== false, 'the predecessor claim is released after successful recovery');
    ruTrackerChecker::releaseCheckForWorker($old, $after);

    foreach (array(
        'successor confirmed present after a failed coherent read' => array(true, false, array($successor)),
        'successor existence remains unknown' => array(false, false, array()),
    ) as $label => $existence) {
        rXMLRPCRequest::reset();
        sweepScan(array(array($old, '', '', $encoded)));
        rXMLRPCRequest::queue(array('d.get_custom', 'd.get_state', 'd.is_open'), true, false,
            array($encoded, 0, 0));
        rXMLRPCRequest::queue(array('d.hash', 'd.get_custom', 'd.get_custom'), false, true, array());
        rXMLRPCRequest::queue('d.hash', $existence[0], $existence[1], $existence[2]);

        RuTrackerUpdatePass::sweepReplacements($sweepNow);

        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('branch')),
            $label . ': indeterminate ownership authorizes no restore');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
            $label . ': indeterminate ownership cannot clear the recovery generation');
        $released = ruTrackerChecker::claimCheckForWorker($old, $sweepNow);
        strictAssertTrue($released !== false, $label . ': early return releases the predecessor claim');
        ruTrackerChecker::releaseCheckForWorker($old, $released);
    }
});

upTest($suite, 'predecessor sweep holds and finally releases its claim on callback failure', function () {
    rXMLRPCRequest::reset();
    $old = str_repeat('A', 40);
    $successor = str_repeat('B', 40);
    $encoded = $successor . '-started-1000';
    sweepScan(array(array($old, '', '', $encoded)));
    $sweepNow = 1000 + ruTrackerChecker::MAX_LOCK_TIME + 1;
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_state', 'd.is_open'), true, false,
        function () use ($old, $encoded, $sweepNow) {
        strictAssertSame(false, ruTrackerChecker::claimCheckForWorker($old, $sweepNow),
            'the predecessor claim is held during its generation reread');
        return array($encoded, 0, 0);
    });
    rXMLRPCRequest::queue(array('d.hash', 'd.get_custom', 'd.get_custom'), true, false,
        function () use ($old, $sweepNow) {
            strictAssertSame(false, ruTrackerChecker::claimCheckForWorker($old, $sweepNow),
                'the predecessor claim remains held during successor proof');
            throw new RuntimeException('injected successor probe failure');
        });

    try {
        RuTrackerUpdatePass::sweepReplacements($sweepNow);
        throw new RuntimeException('the injected callback must escape');
    } catch (RuntimeException $error) {
        strictAssertSame('injected successor probe failure', $error->getMessage(),
            'the intended callback failure was observed');
    }
    $after = ruTrackerChecker::claimCheckForWorker($old, $sweepNow);
    strictAssertTrue($after !== false, 'finally releases the predecessor claim after an exception');
    ruTrackerChecker::releaseCheckForWorker($old, $after);
});

upTest($suite, 'marked-row sweep owns the predecessor through reread and irreversible recovery', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    $record = $old . '-started-1000';
    $sweepNow = 1000 + ruTrackerChecker::MAX_LOCK_TIME + 1;
    $competing = array();
    $unexpectedTokens = array();
    $attempt = function () use ($old, $sweepNow, &$competing, &$unexpectedTokens) {
        $token = ruTrackerChecker::claimCheckForWorker($old, $sweepNow);
        $competing[] = $token;
        if ($token !== false) $unexpectedTokens[] = $token;
    };

    sweepScan(array(array($hash, 'nonce', $record)));
    rXMLRPCRequest::queue(
        array('d.get_state', 'd.is_open', 'd.get_chunks_hashed', 'd.get_completed_bytes', 'd.get_complete',
            'd.get_message', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom'),
        true, false, function ($commands) use ($attempt, $record) {
            $attempt();
            return array(0, 0, 0, 0, 0, '', '1000', '2', sweepFixtureMarker('nonce'), $record);
        });
    rXMLRPCRequest::queue('d.hash', true, true, function ($commands) use ($attempt) {
        $attempt();
        return array();
    });
    rXMLRPCRequest::queue('branch', true, false,
        function ($commands) use ($attempt) {
            $attempt();
            return array(RuTrackerAtomicOwnership::SENTINEL_ACTED);
        });

    RuTrackerUpdatePass::sweepReplacements($sweepNow);

    foreach ($unexpectedTokens as $token)
        ruTrackerChecker::releaseCheckForWorker($old, $token);
    strictAssertSame(array_fill(0, 3, false), $competing,
        'a cooperative predecessor worker is rejected from generation reread through final clear');
    $after = ruTrackerChecker::claimCheckForWorker($old, $sweepNow);
    strictAssertTrue($after !== false, 'the marked-row finally path releases the predecessor claim');
    ruTrackerChecker::releaseCheckForWorker($old, $after);
});

upTest($suite, 'a malformed predecessor recovery record is retained fail closed', function () {
    rXMLRPCRequest::reset();
    $old = str_repeat('A', 40);
    sweepScan(array(array($old, '', '', 'garbage')));
    // These make the former unchecked-clear path fully executable.
    rXMLRPCRequest::queue('d.get_custom', true, false, array('garbage'));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());

    $sweepNow = 1000 + ruTrackerChecker::MAX_LOCK_TIME + 1;
    RuTrackerUpdatePass::sweepReplacements($sweepNow);

    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
        'malformed bytes cannot authorize clearing the only recovery handle');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
        'an unreadable run policy authorizes no restoration');
    $after = ruTrackerChecker::claimCheckForWorker($old, $sweepNow);
    strictAssertTrue($after !== false, 'malformed-record early return releases the predecessor claim');
    ruTrackerChecker::releaseCheckForWorker($old, $after);
});

upTest($suite, 'a non-plugin marker never authorizes marked-sweep discard or activation', function () {
    foreach (array('predecessor still exists' => true, 'predecessor is gone' => false) as $label => $present) {
        rXMLRPCRequest::reset();
        $hash = str_repeat('A', 40);
        $old = str_repeat('B', 40);
        $marker = 'not-a-plugin-marker';
        $record = $old . '-started-1000';
        sweepScan(array(array($hash, $marker, $record)));
        sweepDetail(0, 0, 0, 0, 0, '', '1000', '2', $marker, $record);
        if ($present) {
            rXMLRPCRequest::queue('d.hash', true, false, array($old));
            rXMLRPCRequest::queue(array('d.get_state', 'd.is_open', 'd.get_custom'), true, false,
                array(1, 1, ''));
            rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false,
                array($marker, $record));
            rXMLRPCRequest::queue('d.erase', true, false, array(0));
        } else {
            rXMLRPCRequest::queue('d.hash', true, true, array());
            rXMLRPCRequest::queue(array('d.open', 'd.start'), true, false, array(0, 0));
            rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1));
            rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array(0, 0));
        }

        RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')),
            $label . ': foreign marker cannot authorize discarding the occupant');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
            $label . ': foreign marker cannot authorize activation');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
            $label . ': foreign marker cannot authorize clearing ownership keys');
    }
});

upTest($suite, 'an unknown marked-row run token is malformed, never record-less legacy', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    $marker = '0123456789abcdef0123456789abcdef';
    $record = $old . '-mystery-1000';
    sweepScan(array(array($hash, $marker, $record)));
    // A live row made the old decoder's implicit "stopped" record look
    // finished and therefore authorized clearing both keys.
    sweepDetail(1, 1, 0, 0, 0, '', '1000', '2', $marker, $record);
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array(0, 0));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
        'malformed nonempty bytes retain the recovery keys instead of entering legacy policy');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
        'an unknown run policy authorizes no activation');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')),
        'and no staged copy is discarded');
});

upTest($suite, 'sweepReplacements does not clear predecessor recovery key if generation changed before clear', function () {
    rXMLRPCRequest::reset();
    $old = str_repeat('A', 40);
    $successor = str_repeat('B', 40);
    $generation = $successor . '-started-1000';
    sweepScan(array(array($old, '', '', $generation)));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_state', 'd.is_open'), true, false,
        array($generation, 0, 0));
    rXMLRPCRequest::queue(array('d.hash', 'd.get_custom', 'd.get_custom'), true, false,
        array($successor, str_repeat('d', 32), $old . '-started-1000')); // owned
    // The atomic branch observes that chk-replacing moved after the coherent
    // owner probe but before its conditional clear.
    rXMLRPCRequest::queue('branch', true, false,
        array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);
    $branches = sweepBranchRequestsForHash($old);
    strictAssertSame(1, count($branches),
        'the exact generation is checked at the clear boundary');
    strictAssertTrue(strpos($branches[0]['commands'][0]->params[1], 'cat=' . $generation) !== false,
        'the atomic condition names the generation observed before the race');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
        'a skipped conditional clear never falls back to an unconditional write');
});

upTest($suite, 'sweepReplacements ignores a row with no marker', function () {
    rXMLRPCRequest::reset();
    sweepScan(array(array(str_repeat('A', 40), '', '')));

    RuTrackerUpdatePass::sweepReplacements(1000);

    // Asserted on what was sent, not on a spelled-out pipeline key: the key
    // moves whenever a column is added or dropped, and a key that no longer
    // matches makes this assertion pass for the wrong reason -- which is
    // exactly what happened when the dead d.get_directory_base column went.
    strictAssertSame(1, count(rXMLRPCRequest::$requests),
        'an unmarked hash is foreign and must not cost even a read: only the fleet scan was sent');
    strictAssertSame('d.multicall', rXMLRPCRequest::$requests[0]['key'], 'and that one request IS the scan');
});

upTest($suite, 'sweepReplacements leaves a transaction younger than the lock window alone', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME);

    strictAssertSame(1, count(rXMLRPCRequest::$requests),
        'a transaction still inside the lock window may simply be in flight: nothing beyond the scan');
    strictAssertSame('d.multicall', rXMLRPCRequest::$requests[0]['key'], 'and that one request IS the scan');
});

// THE INCIDENT, reproduced: the predecessor is gone, so the commit did
// happen; the copy is stopped and closed with nothing hashed, so the
// activation did not.
upTest($suite, 'sweepReplacements finishes a stranded replacement whose predecessor is gone', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    sweepScan(array(array($hash, 'nonce', $old . '-started-1000')));
    sweepDetail(0, 0);
    // transport ok, daemon faults: torrentExists() reads that as "gone",
    // whereas a dead transport is "unknowable" and must never be acted on.
    rXMLRPCRequest::queue('d.hash', true, true, array());          // the predecessor is gone
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    $probe = rXMLRPCRequest::requestsFor('d.hash');
    strictAssertSame(1, count($probe), 'the predecessor is probed exactly once');
    strictAssertSame($old, $probe[0]['commands'][0]->params, 'the probe reads the recorded predecessor, and only reads it');
    $act = sweepBranchRequestsForHash($hash);
    strictAssertSame(1, count($act), 'one atomic activation attempt per cycle: the cycle is the retry loop');
    strictAssertTrue(strpos($act[0]['commands'][0]->params[1], 'chk-replacement') !== false
        && strpos($act[0]['commands'][0]->params[1], 'chk-replaces') !== false,
        'activation and ownership-key clear share one exact-generation branch');
    strictAssertTrue(strpos($act[0]['commands'][0]->params[1], 'd.get_state=') !== false
        && strpos($act[0]['commands'][0]->params[1], 'd.is_open=') !== false,
        'the branch includes the observed stopped/closed projection');
    sweepAssertNoStandaloneOwnershipMutation('stranded activation');
});

upTest($suite, 'sweepReplacements opens a recorded paused predecessor without starting it', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $record = str_repeat('B', 40) . '-open-1000';
    sweepScan(array(array($hash, 'nonce', $record)));
    sweepDetail(0, 0, 0, 0, 0, '', '0', '2', 'nonce', $record);
    // transport ok, daemon faults: torrentExists() reads that as "gone",
    // whereas a dead transport is "unknowable" and must never be acted on.
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    $branches = sweepBranchRequestsForHash($hash);
    strictAssertSame(1, count($branches), 'a paused predecessor is restored and its transaction closed atomically');
    strictAssertTrue(strpos($branches[0]['commands'][0]->params[2], 'd.open=') !== false,
        'the atomic action opens the replacement');
    strictAssertTrue(strpos($branches[0]['commands'][0]->params[2], 'd.start=') === false,
        'a paused predecessor policy must never start it');
    sweepAssertNoStandaloneOwnershipMutation('paused replacement activation');
});

upTest($suite, 'sweepReplacements never resurrects a replacement whose predecessor the user had stopped', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $record = str_repeat('B', 40) . '-stopped-1000';
    sweepScan(array(array($hash, 'nonce', $record)));
    sweepDetail(0, 0, 0, 0, 0, '', '0', '2', 'nonce', $record);
    // transport ok, daemon faults: torrentExists() reads that as "gone",
    // whereas a dead transport is "unknowable" and must never be acted on.
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open')), 'a stopped predecessor stays stopped');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')), 'and is certainly never started');
    strictAssertSame(1, count(sweepBranchRequestsForHash($hash)),
        'the exact transaction is finished atomically: leaving it stopped was the intended outcome');
    strictAssertTrue(strpos(sweepBranchRequestsForHash($hash)[0]['commands'][0]->params[1], 'd.get_state=') !== false
        && strpos(sweepBranchRequestsForHash($hash)[0]['commands'][0]->params[1], 'd.is_open=') !== false,
        'the stopped/closed projection is checked in the same clear branch');
});

upTest($suite, 'sweepReplacements does not restart a copy that has been opened since it was staged', function () {
    // hashed chunks, bytes on disk, or the complete flag: each alone proves
    // somebody opened the copy after the crash and stopped it again, so the
    // current run state is their decision, not ours. One case per signal --
    // a combined fixture would let any single disjunct be deleted unnoticed.
    foreach (array(
        'hashed chunks' => array(42, 0, 0),
        'completed bytes' => array(0, 1024, 0),
        'the complete flag' => array(0, 0, 1),
    ) as $signal => $detail) {
        rXMLRPCRequest::reset();
        $hash = str_repeat('A', 40);
        sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
        sweepDetail(0, 0, $detail[0], $detail[1], $detail[2], '', '1000', '2');
        // transport ok, daemon faults: torrentExists() reads that as "gone",
        // whereas a dead transport is "unknowable" and must never be acted on.
        rXMLRPCRequest::queue('d.hash', true, true, array());
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

        RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
            $signal . ' alone must stop the restart');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open')), 'nor may it be reopened');
        $writes = sweepBranchRequestsForHash($hash);
        strictAssertSame(1, count($writes), 'the transaction is closed all the same');
        strictAssertTrue(strpos($writes[0]['commands'][0]->params[1], 'chk-replacement') !== false,
            'by atomically clearing the exact keys, not by labelling');
    }
});

// A d.get_completed_bytes above PHP_INT_MAX is a WELL-FORMED reading, not a
// corrupt one: on a 32-bit build that is every torrent past 2 GiB, and every
// $req->val slot arrives as a string. Funnelled through the canonical
// nonnegative parser its roundtrip check failed, inspectMarkedRow() returned
// before any branch ran, and the staged row's keys were never retired --
// cycle after cycle, for ever. S06 makes MALFORMED input fail closed; it must
// not make a legitimate byte counter fail at all.
upTest($suite, 'a byte counter wider than the platform integer still finishes the transaction', function () {
    foreach (array(
        'just past a 32-bit signed counter' => '2147483648',
        'just past a 64-bit signed counter' => '9223372036854775808',
        'a genuinely huge library file'     => '18446744073709551615',
    ) as $label => $bytes) {
        rXMLRPCRequest::reset();
        $hash = str_repeat('A', 40);
        sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
        sweepDetail(0, 0, 0, $bytes, 0, '', '1000', '2');
        // transport ok, daemon faults: torrentExists() reads that as "gone".
        rXMLRPCRequest::queue('d.hash', true, true, array());
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

        RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
            $label . ': bytes on disk still prove somebody opened the copy');
        strictAssertSame(1, count(sweepBranchRequestsForHash($hash)),
            $label . ': and the staged replacement keys are retired rather than left for ever');
    }
});

// The record-less branch's whole effect is one diagnostic line, and the case
// it exists for is a row whose readings an operator cannot interpret alone.
// Hoisting the canonical parse of every counter ABOVE that branch silenced it
// exactly then: the sweep returned before the only thing it had to say.
upTest($suite, 'the record-less diagnostic still fires for a row whose counters do not parse', function () {
    foreach (array(
        'an unparsable run state'         => array('state' => 'what', 'bytes' => 0),
        'an unparsable chunk count'       => array('state' => 0, 'bytes' => 0, 'hashed' => '01'),
        'a byte counter past PHP_INT_MAX' => array('state' => 0, 'bytes' => '9223372036854775808'),
    ) as $label => $case) {
        $hash = str_repeat('A', 40);
        $log = upCapturedLog(function () use ($hash, $case) {
            rXMLRPCRequest::reset();
            sweepScan(array(array($hash, 'nonce', '')));
            sweepDetail($case['state'], 0, isset($case['hashed']) ? $case['hashed'] : 0,
                $case['bytes'], 0, '', '1000', '2', 'nonce', '');
            RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);
        });

        strictAssertTrue(strpos($log, 'carries a replacement marker with no record') !== false,
            $label . ': the operator still gets the line, saw: ' . $log);
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
            $label . ': and it is still only a line -- nothing is written');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
            $label . ': nothing is cleared either');
    }
});

// The live-guard must fire on EITHER half of the reading: open-but-stopped
// (ruTorrent's pause) is just as much somebody's run state as started.
// "Finished" is decided against the RECORD, not against a label or a bare
// "is it running": a row that already answers what its record asked for is
// done, whoever put it in that state.
upTest($suite, 'a row that already answers its record is retired', function () {
    // The predecessor was PAUSED, and the replacement is open: satisfied.
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $record = str_repeat('B', 40) . '-open-1000';
    sweepScan(array(array($hash, 'nonce', $record)));
    sweepDetail(0, 1, 0, 0, 0, '', '1000', '2', 'nonce', $record);
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.hash')),
        'a satisfied record still proves commit state before reconciling cleanup');
    strictAssertSame(1, count(sweepBranchRequestsForHash($hash)),
        'its keys are retired exactly like a running row\'s');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')), 'and nothing is started');

    // A record asking for "started", answered by a started row: also done,
    // even though its final clear was evidently lost.
    rXMLRPCRequest::reset();
    sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
    sweepDetail(1, 1, 0, 0, 0, '', '1000', '2');
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);
    strictAssertSame(1, count(sweepBranchRequestsForHash($hash)),
        'a started record answered by a started row is finished');
});

// The activation verdict must be read from the column the record asks about:
// a started-record row confirmed only by d.is_open is NOT done.
upTest($suite, 'sweepReplacements judges a started record on d.get_state, never on d.is_open alone', function () {
    // Open but not started after the atomic activation attempt: keep the keys.
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
    sweepDetail(0, 0);
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_UNCONFIRMED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(1, count(sweepBranchRequestsForHash($hash)), 'exactly one conditional activation attempt');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
        'open-but-not-started remains owned and retryable; no unguarded error label or key clear');

    // Started but still closed (a scheduler-queued start): that IS satisfied.
    rXMLRPCRequest::reset();
    sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
    sweepDetail(0, 0);
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(1, count(sweepBranchRequestsForHash($hash)),
        'd.get_state answering 1 lets the same atomic branch clear the exact generation');
});

// The legacy (record-less) branch has its own age gate on chk-stime: a row
// staged recently, or one with no stime at all, may simply be in flight.
upTest($suite, 'sweepReplacements leaves a young or stime-less legacy row unlabelled', function () {
    foreach (array(
        'no stime at all' => '0',
        'staged just inside the window' => (string) (5000),
    ) as $label => $stime) {
        rXMLRPCRequest::reset();
        $hash = str_repeat('A', 40);
        sweepScan(array(array($hash, 'nonce', '')));
        sweepDetail(0, 0, 0, 0, 0, '', $stime, '2');

        RuTrackerUpdatePass::sweepReplacements(5000 + 10); // well inside MAX_LOCK_TIME of any stime > 0

        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
            $label . ': nothing may be labelled');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
            $label . ': and nothing cleared');
    }
});

// --- The revive half of the stranded-transaction story -----------------------
//
// A crash BEFORE the commit erase leaves the predecessor in the client but
// stopped and closed -- outside the seeding view, where no check will ever
// find it. The sweep restores its recorded run state (once) so its own check
// can redo the replacement; the staged copy's keys stay untouched throughout.

// The flag is the whole protection, so losing it costs the user's next stop:
// unmarked, that stop reads as the crash signature and gets revived over. A
// blip on one d.set_custom is not a reason to give the protection up.
upTest($suite, 'an unconfirmed atomic revival is not retried or cleaned up blind', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    sweepScan(array(array($hash, 'nonce', $old . '-started-1000')));
    sweepDetail(0, 0);
    rXMLRPCRequest::queue('d.hash', true, false, array($old));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_UNCONFIRMED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(1, count(sweepBranchRequestsForHash($old)),
        'the combined revive+stamp operation is attempted exactly once');
    strictAssertSame(0, count(sweepBranchRequestsForHash($hash)),
        'the staged generation remains when revival was not confirmed');
    sweepAssertNoStandaloneOwnershipMutation('unconfirmed revival');
});

// The flag is spent per TRANSACTION, not per torrent: it carries the staging
// stamp of the strand it was spent on. Nothing ever clears it, so testing it
// for mere non-emptiness disabled the revival path on that torrent for good --
// a later, unrelated replacement stranding the same way would be refused on
// evidence about a transaction long gone.
upTest($suite, 'the one revival is spent per transaction, not per torrent', function () {
    foreach (array(
        'the same transaction, revived before' => array('flag' => '1000', 'revived' => 0),
        'a later, unrelated transaction'       => array('flag' => '500',  'revived' => 1),
    ) as $label => $case) {
        rXMLRPCRequest::reset();
        $hash = str_repeat('A', 40);
        $old = str_repeat('B', 40);
        sweepScan(array(array($hash, 'nonce', $old . '-started-1000')));
        sweepDetail(0, 0);
        rXMLRPCRequest::queue('d.hash', true, false, array($old));
        $reviveSentinel = $case['flag'] === '1000'
            ? RuTrackerAtomicOwnership::SENTINEL_SPENT
            : RuTrackerAtomicOwnership::SENTINEL_REVIVED;
        rXMLRPCRequest::queue('branch', true, false, array($reviveSentinel));
        if ($reviveSentinel === RuTrackerAtomicOwnership::SENTINEL_SPENT)
            rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));

        RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

        strictAssertSame($case['revived'] ? 1 : 2, count(sweepBranchRequestsForHash($old)),
            $label . ': a spent strand closes its predecessor generation; a new strand revives once');
        strictAssertSame(1, count(sweepBranchRequestsForHash($hash)),
            $label . ': settled revival outcomes discard the exact staged generation');
        sweepAssertNoStandaloneOwnershipMutation($label);
    }
});

upTest($suite, 'sweepReplacements does not flag a revival the daemon did not actually perform', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    sweepScan(array(array($hash, 'nonce', $old . '-started-1000')));
    sweepDetail(0, 0);
    rXMLRPCRequest::queue('d.hash', true, false, array($old));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_UNCONFIRMED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(1, count(sweepBranchRequestsForHash($old)), 'one atomic revival decision is made');
    strictAssertSame(0, count(sweepBranchRequestsForHash($hash)),
        'an unconfirmed revival retains the staged generation for a later retry');
});

// A staged copy is the marker by which a stranded transaction can be found at
// all -- and nothing more. Once the predecessor is up, it carries everything
// needed to redo the replacement, while the copy sitting at the successor hash
// BLOCKS that redo: begin() finds an item already at the hash it means to
// fetch, sees the replacement marker and hands the transaction back to this
// sweep, which hands it back to the predecessor's check. Neither ever acts.
upTest($suite, 'a staged copy is discarded even when its record says the predecessor was stopped', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    sweepScan(array(array($hash, 'nonce', $old . '-stopped-1000')));
    sweepDetail(0, 0, 0, 0, 0, '', '0', '2', 'nonce', $old . '-stopped-1000');
    rXMLRPCRequest::queue('d.hash', true, false, array($old));   // the predecessor is still there
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open', 'd.get_custom'), true, false,
        array(0, 0, $hash . '-stopped-1000'));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
        'a torrent the user had stopped is not resurrected by its replacement');
    strictAssertSame(1, count(sweepBranchRequestsForHash($old)),
        'the stopped predecessor relinquishes only this exact recovery generation');
    strictAssertSame(1, count(sweepBranchRequestsForHash($hash)),
        'then the exact staged copy goes, or nothing can ever redo it');
    sweepAssertNoStandaloneOwnershipMutation('stopped-policy cleanup');
});

upTest($suite, 'a staged copy whose predecessor is up is discarded, so the redo has a clear field', function () {
    foreach (array(
        'the predecessor was already running' => array('state' => array(1, 1), 'revived' => 0),
        'the sweep had to revive it'          => array('state' => array(0, 0), 'revived' => 1),
    ) as $label => $case) {
        rXMLRPCRequest::reset();
        $hash = str_repeat('A', 40);
        $old = str_repeat('B', 40);
        sweepScan(array(array($hash, 'nonce', $old . '-started-1000')));
        sweepDetail(0, 0);
        rXMLRPCRequest::queue('d.hash', true, false, array($old));
        if ($case['revived']) {
            rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_REVIVED));
        } else {
            rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED));
            rXMLRPCRequest::queue(array('d.get_state', 'd.is_open', 'd.get_custom', 'd.get_custom'), true, false,
                array(1, 1, '', $hash . '-started-1000'));
            rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));
        }
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));

        RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

        strictAssertSame($case['revived'] ? 1 : 2, count(sweepBranchRequestsForHash($old)),
            $label . ': stopped predecessor revives once; running predecessor only closes its generation');
        strictAssertSame(1, count(sweepBranchRequestsForHash($hash)),
            $label . ': the exact staged copy is discarded either way');
        sweepAssertNoStandaloneOwnershipMutation($label);
    }
});

upTest($suite, 'sweepReplacements defers when the predecessor probe cannot be answered', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
    sweepDetail(0, 0);
    // no d.hash response queued: the transport itself fails

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')), 'an unknowable fact is never acted on');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')), 'and nothing is cleared');
});

// Everything sweepMarkedRow does is irreversible -- it erases the staged copy,
// or starts a download -- and the marker it acts on came from a fleet scan
// several round trips earlier. batch_check.php takes no cycle lock, so a
// concurrent createTorrent() can finish that transaction and stage a NEW one
// at the same hash in between; acting on the stale reading would erase a live
// transaction's only marker.
upTest($suite, 'a row that changed between the scan and the act is left to whoever owns it now', function () {
    foreach (array(
        'the marker is gone'      => array('marker' => '',      'record' => str_repeat('B', 40) . '-started-1000'),
        'a different transaction' => array('marker' => 'other',  'record' => str_repeat('C', 40) . '-started-9999'),
        'the same one, re-staged' => array('marker' => 'nonce',  'record' => str_repeat('B', 40) . '-started-9999'),
        'marker generation only changed' => array('marker' => 'nonce-B', 'record' => str_repeat('B', 40) . '-started-1000'),
        'run token changed in the same second' => array('marker' => 'nonce', 'record' => str_repeat('B', 40) . '-open-1000'),
    ) as $label => $case) {
        rXMLRPCRequest::reset();
        $hash = str_repeat('A', 40);
        $old = str_repeat('B', 40);
        sweepScan(array(array($hash, 'nonce', $old . '-started-1000')));
        sweepDetail(0, 0, 0, 0, 0, '', '1000', '2', $case['marker'], $case['record']);

        RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

        strictAssertSame(2, count(rXMLRPCRequest::$requests),
            $label . ': the scan and the re-read, and then nothing');
        foreach (array('d.erase', 'd.open|d.start', 'd.set_custom|d.set_custom') as $key)
            strictAssertSame(0, count(rXMLRPCRequest::requestsFor($key)),
                $label . ': nothing irreversible on a reading that no longer holds');
    }

    // And the row that has NOT changed is acted on exactly as before.
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    sweepScan(array(array($hash, 'nonce', $old . '-started-1000')));
    sweepDetail(0, 0, 0, 0, 0, '', '1000', '2', 'nonce', $old . '-started-1000');
    rXMLRPCRequest::queue('d.hash', true, false, array($old));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED));
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open', 'd.get_custom', 'd.get_custom'), true, false,
        array(1, 1, '', $hash . '-started-1000'));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);
    strictAssertSame(1, count(sweepBranchRequestsForHash($hash)), 'an unchanged staged row is still swept atomically');
});

upTest($suite, 'discarding a staged copy revalidates its exact transaction generation', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    sweepScan(array(array($hash, 'nonce-a', $old . '-started-1000')));
    sweepDetail(0, 0, 0, 0, 0, '', '1000', '2', 'nonce-a', $old . '-started-1000');
    rXMLRPCRequest::queue('d.hash', true, false, array($old));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_REVIVED));
    // A concurrent replacement staged a new generation at the same hash
    // after the predecessor revival was confirmed, so the atomic erase skips.
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    $cleanup = sweepBranchRequestsForHash($hash);
    strictAssertSame(1, count($cleanup), 'the marker and inheritance record are checked at the final erase boundary');
    strictAssertTrue(strpos($cleanup[0]['commands'][0]->params[1], sweepFixtureMarker('nonce-a')) !== false
        && strpos($cleanup[0]['commands'][0]->params[1], $old . '-started-1000') !== false,
        'the branch is pinned to the exact old generation');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')),
        'the fresh generation is left to the transaction that owns it');
});

upTest($suite, 'sweepReplacements clears the keys of a marked row that is already live', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    $record = $old . '-started-1000';
    sweepScan(array(array($hash, 'nonce', $record)));
    sweepDetail(1, 1, 100, 500, 1, '', '1000', '2', 'nonce', $record);
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    $writes = sweepBranchRequestsForHash($hash);
    strictAssertSame(1, count($writes),
        'a running marked torrent is a finished replacement whose final clear was lost -- including one a human started by hand');
    strictAssertTrue(strpos($writes[0]['commands'][0]->params[1], 'chk-replacement') !== false
        && strpos($writes[0]['commands'][0]->params[1], 'chk-replaces') !== false,
        'the branch atomically checks and clears both exact ownership values');
    strictAssertTrue(strpos($writes[0]['commands'][0]->params[1], 'd.get_state=') !== false
        && strpos($writes[0]['commands'][0]->params[1], 'd.is_open=') !== false,
        'the observed live projection is checked at the clear boundary');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open')), 'its run state is somebody else\'s decision');
});

upTest($suite, 'a live marker-only row retains ownership keys and performs no run action', function () {
    rXMLRPCRequest::reset();
	$hash = str_repeat('A', 40);
	sweepScan(array(array($hash, 'nonce', '')));
	sweepDetail(1, 1, 100, 500, 1, '', '1000', '2', 'nonce', '');

	RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

	strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
		'marker-only ownership authorizes no write to a possibly re-added occupant');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')),
        'marker-only ownership never authorizes erase');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
        'marker-only ownership never authorizes start');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open')),
        'marker-only ownership never authorizes open');
});

// The legacy orphan: marked, but staged before the record existed. Its
// predecessor and intended run state are unrecoverable, so it is observable
// only in the debug log; a post-probe state write could hit a re-added row.
upTest($suite, 'sweepReplacements only logs a record-less stranded row', function () {
	rXMLRPCRequest::reset();
	$hash = str_repeat('A', 40);
	sweepScan(array(array($hash, 'nonce', '')));
	sweepDetail(0, 0, 0, 0, 0, '', '1000', '2', 'nonce', '');

	RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

	strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.hash')), 'there is no predecessor to probe');
	strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open')), 'and no intent to act on');
	strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')), 'nothing is started');
	strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
		'no diagnostic write may race a same-hash replacement after the probe');
});

upTest($suite, 'sweepReplacements retains an owned copy carrying an rTorrent message', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
    sweepDetail(0, 0, 0, 0, 0, 'Tracker: [Failure reason "torrent not registered"]', '1000', '2');
    // transport ok, daemon faults: torrentExists() reads that as "gone",
    // whereas a dead transport is "unknowable" and must never be acted on.
    rXMLRPCRequest::queue('d.hash', true, true, array());

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')), 'a copy the daemon is already unhappy about is not started blind');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
        'no unguarded error write can race a newer ownership generation');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('branch')),
        'the daemon message authorizes neither activation nor cleanup');
});

upTest($suite, 'a daemon error retains ownership even when opened counters are present', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
    sweepDetail(0, 0, 1, 0, 0, 'File error: permission denied', '1000', '2');
    rXMLRPCRequest::queue('d.hash', true, true, array());

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
        'the daemon error remains recoverable without an unguarded label write');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('branch')),
        'ownership keys are retained and no run action is attempted');
});

upTest($suite, 'sweepReplacements keeps the keys when the activation cannot be verified', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
    sweepDetail(0, 0);
    // transport ok, daemon faults: torrentExists() reads that as "gone",
    // whereas a dead transport is "unknowable" and must never be acted on.
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_UNCONFIRMED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(1, count(sweepBranchRequestsForHash($hash)), 'one atomic activation was attempted');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
        'unconfirmed activation keeps the ownership keys without an unguarded error write');
    sweepAssertNoStandaloneOwnershipMutation('unconfirmed stranded activation');
});

// Scoped deliberately: this is the branch where the recorded predecessor is
// GONE. The sweep does erase in another branch -- a staged copy whose
// predecessor is back up is discarded so the redo has a clear field -- so a
// name promising "never erases anything" would be a false universal rule and
// could invite someone to break the correct branch.
upTest($suite, 'with the predecessor gone, the sweep neither erases, stops nor closes', function () {
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
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
    sweepDetail(0, 0, 0, 0, 0, '', '1000', '2', 'nonce2', '');
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

// Also scoped to the predecessor-gone branch. The recovery branch writes
// chk-revived on the PREDECESSOR by design, so "only ever writes to the hash
// carrying the marker" is not a rule the sweep as a whole obeys.
upTest($suite, 'with the predecessor gone, the sweep writes only to the hash carrying the marker', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $old = str_repeat('B', 40);
    sweepScan(array(array($hash, 'nonce', $old . '-started-1000')));
    sweepDetail(0, 0);
    // transport ok, daemon faults: torrentExists() reads that as "gone",
    // whereas a dead transport is "unknowable" and must never be acted on.
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(1, count(sweepBranchRequestsForHash($hash)),
        'the replacement itself receives the one ownership-sensitive action');
    strictAssertSame(0, count(sweepBranchRequestsForHash($old)),
        'the recorded predecessor is an input to a read, never a write target');
    sweepAssertNoStandaloneOwnershipMutation('predecessor-gone activation target');
});

upTest($suite, 'sweepReplacements writes nothing when the fleet scan itself fails', function () {
    rXMLRPCRequest::reset();
    // A failed scan that nonetheless carries a full, well-formed marked row.
    // Queueing nothing would have proved nothing: the double answers an
    // unqueued request with an EMPTY value list, so the row loop could not
    // run even with the success() guard deleted. In production a faulting
    // member injects faultCode/faultString into the flat list, so values
    // ARE present on a failed scan -- and acting on them would start and
    // label downloads picked out of a fault message.
    rXMLRPCRequest::queue('d.multicall', false, false,
        array(str_repeat('A', 40), 'nonce', str_repeat('B', 40) . '-started-1000'));

    RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

    strictAssertSame(1, count(rXMLRPCRequest::$requests), 'it asks once and gives up');
});


// --- Settled verdicts rest instead of burning the probe budget hourly --------

upTest($suite, 'a settled DELETED/ABSORBED verdict is not re-litigated until its recheck clock runs out', function () {
    foreach (array(ruTrackerChecker::STE_DELETED, ruTrackerChecker::STE_ABSORBED) as $settled) {
        // Fresh verdict: skipped outright -- no dispatch, no state write, and
        // most importantly no announce probe drawn from the shared budget.
        $values = upRow(str_repeat('A', 40), 6, 'bt.t-ru.org', (string) $settled, '', '', '', '', (string) time());
        $rows = RuTrackerUpdatePass::parseMulticall($values);
        $ran = array();
        strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) use (&$ran) { $ran[] = $hash; });
        rXMLRPCRequest::reset();
        $result = RuTrackerUpdatePass::run($rows);
        strictAssertSame(array(), $ran, 'state ' . $settled . ': a fresh settled verdict rests');
        strictAssertSame(array(), rXMLRPCRequest::$requests, 'and costs nothing at all');

        // The same verdict past SETTLED_RECHECK: dispatched again, so a topic
        // that was restored on the tracker (or judged wrongly) heals itself.
        $values = upRow(str_repeat('A', 40), 6, 'bt.t-ru.org', (string) $settled, '', '', '', '',
            (string) (time() - RuTrackerUpdatePass::SETTLED_RECHECK - 1));
        $rows = RuTrackerUpdatePass::parseMulticall($values);
        $ran = array();
        strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) use (&$ran) { $ran[] = $hash; });
        rXMLRPCRequest::reset();
        RuTrackerUpdatePass::run($rows);
        strictAssertSame(array(str_repeat('A', 40)), $ran,
            'state ' . $settled . ': past the recheck clock the full chain runs again');
    }
});

// The sweep's own half-done activation must not read as a user's decision.
// Cycle N atomically issues d.open+d.start, measures (0,1), and keeps the keys.
// Cycle N+1 used to see "open" and retire the keys as somebody
// else's doing -- leaving the replacement paused with nothing coming back
// for it, which is the exact failure this sweep exists to prevent.
// Older versions of the two activation paths labelled a half-done attempt
// differently. A rule keyed on the label therefore
// rescued one path and abandoned the other -- so the resume must not care
// which label it finds.
upTest($suite, 'an unfinished activation is resumed whatever label it carries', function () {
    foreach (array(
        'the sweep\'s own STE_ERROR' => (string) ruTrackerChecker::STE_ERROR,
        'the staging\'s STE_UPDATED, which metafetch never overwrites' => (string) ruTrackerChecker::STE_UPDATED,
    ) as $label => $chkState) {
        rXMLRPCRequest::reset();
        $hash = str_repeat('A', 40);
        sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
        // Opened but not started, with the counters its own d.open produced.
        sweepDetail(0, 1, 42, 1024, 1, '', '1000', $chkState);
        rXMLRPCRequest::queue('d.hash', true, true, array());                       // predecessor gone
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));

        RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

        $act = sweepBranchRequestsForHash($hash);
        strictAssertSame(1, count($act), $label . ': the activation is retried once atomically');
        strictAssertTrue(strpos($act[0]['commands'][0]->params[1], 'value=0') !== false
            && strpos($act[0]['commands'][0]->params[1], 'value=1') !== false,
            $label . ': the exact observed state/open projection guards the retry');
        sweepAssertNoStandaloneOwnershipMutation($label);
    }
});

// A label added after a torrent settled must take effect on the next cycle,
// not when the week-long recheck clock happens to run out.
upTest($suite, 'a freshly ignored label beats the settled-verdict gate', function () {
    $GLOBALS['ignoreLabels'] = array('tv-sonarr');
    try {
        $values = upRow(str_repeat('A', 40), 6, 'bt.t-ru.org', (string) ruTrackerChecker::STE_DELETED,
            '', 'tv-sonarr', '', '', (string) time());
        $rows = RuTrackerUpdatePass::parseMulticall($values);
        strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) { throw new RuntimeException('must not run'); });
        rXMLRPCRequest::reset();
        upQueueUnchanged($rows);
        RuTrackerUpdatePass::run($rows);

        $writes = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom');
        strictAssertSame(1, count($writes), 'the ignored label is written through');
        strictAssertSame(array(str_repeat('A', 40), 'chk-state', (string) ruTrackerChecker::STE_IGNORED),
            $writes[0]['commands'][0]->params, 'as STE_IGNORED, not left at DELETED for a week');
    } finally {
        unset($GLOBALS['ignoreLabels']);
    }
});

// A settled DELETED row keeps answering 'candidate' by design -- its announce
// is meant to fail. Counting those into the fuse let a handful of dead topics
// hold their host tripped for good, and a tripped host is one where no REAL
// candidate is ever investigated.
upTest($suite, 'settled verdicts are not evidence of an outage: the fuse ignores them', function () {
    $values = array();
    // Three dead topics, long settled, plus one genuine live candidate.
    for ($i = 0; $i < 3; $i++)
        $values = array_merge($values, upRow(str_repeat(chr(65 + $i), 40), 6, 'bt.t-ru.org',
            (string) ruTrackerChecker::STE_DELETED, '', '', '', '', (string) time()));
    $values = array_merge($values, upRow(str_repeat('Z', 40), 6));
    $rows = RuTrackerUpdatePass::parseMulticall($values);

    $ran = array();
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) use (&$ran) { $ran[] = $hash; });
    rXMLRPCRequest::reset();
    $result = RuTrackerUpdatePass::run($rows);

    strictAssertSame(array(), $result['fused'],
        'three dead topics must not trip the host they are dead on');
    strictAssertSame(array(str_repeat('Z', 40)), $ran,
        'and the live candidate is still investigated');
});

// The settled gate is about RuTracker's own verdicts. Other handlers write the
// same state numbers, and their torrents reach this pass too -- they must keep
// getting their own handler every cycle.
upTest($suite, 'the settled gate does not freeze another tracker\'s torrent', function () {
    $values = upRow(str_repeat('A', 40), 6, 'tracker.nnmclub.to',
        (string) ruTrackerChecker::STE_DELETED, '', '', '', '', (string) time());
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    $ran = array();
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) use (&$ran) { $ran[] = $hash; });
    rXMLRPCRequest::reset();
    RuTrackerUpdatePass::run($rows);

    strictAssertSame(array(str_repeat('A', 40)), $ran,
        'a non-RuTracker torrent goes to its own handler, settled state or not');
});

upTest($suite, 'the settled gate rests foreign tracker torrent when superseded', function () {
    $values = upRow(str_repeat('A', 40), 6, 'tracker.nnmclub.to',
        (string) ruTrackerChecker::STE_NOT_NEED, '', '', '',
        ruTrackerChecker::CHKMSG_SUPERSEDED . '|' . str_repeat('B', 40), (string) time());
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    $ran = array();
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) use (&$ran) { $ran[] = $hash; });
    rXMLRPCRequest::reset();
    RuTrackerUpdatePass::run($rows);

    strictAssertSame(array(), $ran,
        'a superseded foreign tracker torrent rests instead of repeating requests hourly');
});

upTest($suite, 'an ignored label clears the sentence the previous verdict left behind', function () {
    $GLOBALS['ignoreLabels'] = array('tv-sonarr');
    try {
        $values = upRow(str_repeat('A', 40), 6, 'bt.t-ru.org', '3', '', 'tv-sonarr', '', 'deleting|2/3');
        $rows = RuTrackerUpdatePass::parseMulticall($values);
        strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) { throw new RuntimeException('must not run'); });
        rXMLRPCRequest::reset();
        upQueueUnchanged($rows);
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom', 'd.set_custom'), true, false, array());
        RuTrackerUpdatePass::run($rows);

        $state = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom|d.set_custom');
        strictAssertSame(1, count($state), 'state, time and message are written once');
        strictAssertSame(array(str_repeat('A', 40), 'chk-state', (string) ruTrackerChecker::STE_IGNORED),
            $state[0]['commands'][0]->params, 'as STE_IGNORED');
        strictAssertSame(array(str_repeat('A', 40), 'chk-msg', ''), $state[0]['commands'][2]->params,
            'the same bundle clears the stale "deleting|2/3" sentence');
    } finally {
        unset($GLOBALS['ignoreLabels']);
    }
});

// Committing the feed's ETag before the feed has been APPLIED means a cycle
// that dies in between answers every later 304 with "unchanged" while never
// having used the feed -- the map it carried is lost until the tracker's own
// ETag moves, which for this feed is hours.
upTest($suite, 'the feed ETag is committed only once the feed has been applied', function () {
    Snoopy::reset();
    rXMLRPCRequest::reset();

    // The feed arrives and parses, but the fleet cannot be read, so
    // nothing was applied.
    Snoopy::queue(200, upFeed(), array('ETag: "v9"'));
    rXMLRPCRequest::queue('d.multicall', false, false, array());
    RuTrackerUpdatePass::pollFeed();
    strictAssertTrue(!isset(RuTrackerState::load('updatepass')['feed_etag']),
        'an unapplied feed leaves no ETag behind');

    // The same feed applied: now the ETag may stand for it.
    Snoopy::queue(200, upFeed(), array('ETag: "v9"'));
    rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), '100', ''));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('100', ''));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    RuTrackerUpdatePass::pollFeed();
    strictAssertSame('v9', trim(RuTrackerState::load('updatepass')['feed_etag'], '"'),
        'an applied feed records its ETag');

    // A resolution the fleet accepted but could not store is not applied
    // either: the fleet read succeeds, the write does not. Committing the
    // ETag over it would answer every later 304 with "unchanged" for a
    // map that never landed.
    Snoopy::queue(200, upFeed(), array('ETag: "v10"'));
    rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), '100', ''));
    rXMLRPCRequest::queue('d.set_custom', false, false, array());
    RuTrackerUpdatePass::pollFeed();
    strictAssertSame('v9', trim(RuTrackerState::load('updatepass')['feed_etag'], '"'),
        'a lost write withholds the ETag, so the next cycle may redo it');

    // An empty feed, on the other hand, IS applied -- there was nothing
    // in it to write -- and must still commit, or the plugin refetches
    // the whole feed unconditionally forever.
    Snoopy::queue(200, '<feed xmlns="http://www.w3.org/2005/Atom"></feed>', array('ETag: "v11"'));
    RuTrackerUpdatePass::pollFeed();
    strictAssertSame('v11', trim(RuTrackerState::load('updatepass')['feed_etag'], '"'),
        'an empty feed still records its ETag');
});

// A settled verdict rests -- but an announce that is succeeding again is
// proof the verdict was wrong, costs no request to act on, and must not be
// made to wait out the week.
upTest($suite, 'a settled verdict is healed at once when the tracker answers again', function () {
    $values = upRow(str_repeat('A', 40), 0, 'bt.t-ru.org', (string) ruTrackerChecker::STE_DELETED,
        '', '', '3:1000', 'deleting|3/3', (string) time());   // failed=0 -> alive
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) { throw new RuntimeException('the free path needs no checker'); });
    rXMLRPCRequest::reset();
    upQueueUnchanged($rows);
    rXMLRPCRequest::queue(array_fill(0, 5, 'd.set_custom'), true, false, array());
    $result = RuTrackerUpdatePass::run($rows);

    strictAssertSame(1, $result['uptodate'], 'the row is healed on the spot');
    $writes = rXMLRPCRequest::requestsFor(
        'd.set_custom|d.set_custom|d.set_custom|d.set_custom|d.set_custom');
    strictAssertSame(1, count($writes), 'the state and both cleanup fields land in one verdict bundle');
    $keys = array();
    foreach ($writes as $w) foreach ($w['commands'] as $c) $keys[$c->params[1]] = $c->params[2];
    strictAssertSame('', $keys['chk-del'], 'and its deletion counter goes');
    strictAssertSame('', $keys['chk-msg'], 'along with the sentence that explained the old verdict');
});

// A topic its moderators closed (tor_status 1/4/5) is as final as a deleted
// one, but it is stored as the general-purpose STE_NOT_NEED -- so without a
// rule of its own it bought a paced probe and a dump fetch every hour for
// ever. A BARE NOT_NEED, which many transient paths write, keeps its checks.
upTest($suite, 'a closed topic rests, a bare NOT_NEED does not', function () {
    foreach (array(
        'closed by its moderators' => array(ruTrackerChecker::CHKMSG_TOPIC_STATUS . '|5', false),
        'superseded'               => array(ruTrackerChecker::CHKMSG_SUPERSEDED . '|' . str_repeat('B', 40), false),
        'a bare NOT_NEED'          => array('', true),
        'an unrelated token'       => array(ruTrackerChecker::CHKMSG_FUSE . '|bt.t-ru.org', true),
    ) as $label => $case) {
        list($msg, $expectChecked) = $case;
        $values = upRow(str_repeat('A', 40), 6, 'bt.t-ru.org', (string) ruTrackerChecker::STE_NOT_NEED,
            '', '', '', $msg, (string) time());
        $rows = RuTrackerUpdatePass::parseMulticall($values);
        $ran = array();
        strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($hash) use (&$ran) { $ran[] = $hash; });
        rXMLRPCRequest::reset();
        RuTrackerUpdatePass::run($rows);
        strictAssertSame($expectChecked ? array(str_repeat('A', 40)) : array(), $ran,
            $label . ': ' . ($expectChecked ? 'still checked' : 'rests'));
    }
});

upTest($suite, 'an unreadable feed withholds its ETag; a well-formed empty one does not', function () {
    // parseFeed() answered the same empty map for "nothing was received",
    // "SimpleXML is missing", "this is not XML" and "a valid feed with no
    // entries". pollFeed() committed the ETag for all four, so a feed it could
    // not read answered every later 304 with "unchanged" for a map nobody ever
    // parsed -- and the tracker-wide crawl redid work the feed had done.
    foreach (array(
        'not xml at all'          => 'this is not a feed',
        'a truncated document'    => '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><entry>',
        'nothing received'        => '',
    ) as $why => $body) {
        $unreadable = false;
        strictAssertSame(array(), RuTrackerForumIndex::parseFeed($body, $unreadable), $why . ': no map');
        strictAssertSame(true, $unreadable, $why . ': and the caller is told it could not be read');
    }

    // A valid feed with no entries is the feed ANSWERING "nothing new", and it
    // earns its ETag like any other answer.
    $unreadable = true;
    strictAssertSame(array(),
        RuTrackerForumIndex::parseFeed('<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"></feed>', $unreadable),
        'an empty feed maps nothing');
    strictAssertSame(false, $unreadable, 'but it was read perfectly well');
});

upTest($suite, 'flushVerdicts skips overwriting a row claimed by a concurrent worker', function () {
    try {
        $hash = str_repeat('A', 40);
        // Take claim as a manual worker
        $token = ruTrackerChecker::claimCheckForWorker($hash, time());
        strictAssertTrue($token !== false, 'claim taken by manual worker');

        $values = upRow($hash, 6, 'bt.t-ru.org', '3', 'Tracker: [Could not resolve hostname]'); // transport verdict
        $rows = RuTrackerUpdatePass::parseMulticall($values);
        $ran = array();
        strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($h) use (&$ran) { $ran[] = $h; });
        rXMLRPCRequest::reset();
        upQueueUnchanged($rows);

        $result = RuTrackerUpdatePass::run($rows);
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
            'the claimed row must not have its state overwritten by the fast-path');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
            'and no message write is issued');
    } finally {
        if (isset($token) && $token !== false) {
            ruTrackerChecker::releaseCheckForWorker($hash, $token);
        }
    }
});

upTest($suite, 'flushVerdicts never treats claim storage failure as an acquired token', function () {
    $blocked = sys_get_temp_dir() . '/chk-fast-blocked-' . bin2hex(random_bytes(4));
    file_put_contents($blocked, 'not a directory');
    strictSetPrivateStatic('RuTrackerState', 'dir', $blocked . '/rutracker_check');
    try {
        rXMLRPCRequest::reset();
        $deferred = array(array(
            'hash' => str_repeat('A', 40),
            'seenState' => 2,
            'seenTime' => 100,
            'state' => ruTrackerChecker::STE_UPTODATE,
            'msg' => null,
            'rawMsg' => '',
            'del' => '',
            'clearDeletion' => false,
            'counts' => true,
        ));

        strictAssertSame(0,
            strictInvoke('RuTrackerUpdatePass', 'flushVerdicts', array($deferred)),
            'a verdict without a durable claim is left for the next cycle');
        strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.multicall'),
            'the scheduler performs no fresh scan after claim storage failed');
    } finally {
        @unlink($blocked);
        strictSetPrivateStatic('RuTrackerState', 'dir', null);
    }
});

upTest($suite, 'flushVerdicts skips clearing stale deletion when row moved since snapshot', function () {
    $hash = str_repeat('A', 40);
    $values = upRow($hash, 0, 'bt.t-ru.org', '3', '', '', '2:100', 'deleting|2/3', '100'); // alive
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($h) { throw new RuntimeException('no checker'); });
    rXMLRPCRequest::reset();
    // Fresh scan reports a newer state and time (e.g. manual check wrote META_PENDING state 8, time 200)
    rXMLRPCRequest::queue('d.multicall', true, false,
        array($hash, '8', '200', '2:100', 'deleting|2/3'));

    $result = RuTrackerUpdatePass::run($rows);
    strictAssertSame(0, $result['uptodate'], 'row that moved is not counted');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
        'stale deletion counter and message must not be cleared when row moved');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
        'and state must not be overwritten');
});

upTest($suite, 'flushVerdicts does not count failed state write and skips message update', function () {
    $hash = str_repeat('A', 40);
    $values = upRow($hash, 6, 'bt.t-ru.org', '3', '', '', '', '', '100'); // candidate -> fuse -> transport/fuse message
    $rows = RuTrackerUpdatePass::parseMulticall($values);
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function ($h) { throw new RuntimeException('no checker'); });
    $GLOBALS['rutrackerFuseShare'] = 0.0;
    $GLOBALS['rutrackerFuseFloor'] = 1; // force fuse trip
    rXMLRPCRequest::reset();
    upQueueUnchanged($rows);
    // Queue state write failure
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), false, false, array());
    rXMLRPCRequest::queue('d.hash', true, false, array(0)); // torrentExists = true, so setState returns false

    $result = RuTrackerUpdatePass::run($rows);
    strictAssertSame(0, $result['uptodate'], 'failed write is not counted as applied');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
        'message write is skipped when state write fails');
});

upTest($suite, 'fast verdict holds the claim and writes one complete daemon bundle', function () {
    $hash = str_repeat('A', 40);
    $model = array('exists' => true, 'state' => '2', 'time' => '100', 'stime' => '50',
        'msg' => 'deleting|2/3', 'del' => '2:100');
    $rows = RuTrackerUpdatePass::parseMulticall(
        upRow($hash, 0, 'bt.t-ru.org', $model['state'], '', '', $model['del'], $model['msg'], $model['time']));
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function () {
        throw new RuntimeException('the alive fast path must not dispatch the checker');
    });
    rXMLRPCRequest::reset();
    $competing = array();
    upQueueProjection($rows, function () use (&$model, &$competing, $hash) {
        $competing[] = ruTrackerChecker::claimCheckForWorker($hash, time());
        return array($hash, $model['state'], $model['time'], $model['del'], $model['msg']);
    });
    rXMLRPCRequest::queue(array_fill(0, 5, 'd.set_custom'), true, false,
        function ($commands) use (&$model, &$competing, $hash) {
            $competing[] = ruTrackerChecker::claimCheckForWorker($hash, time());
            return upApplyVerdictCommands($model, $commands);
        });

    $result = RuTrackerUpdatePass::run($rows);

    strictAssertSame(array(false, false), $competing,
        'a cooperative competitor is rejected during both the fresh scan and the write');
    strictAssertSame(1, $result['uptodate'], 'the complete alive verdict is counted once');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom|d.set_custom|d.set_custom|d.set_custom');
    strictAssertSame(1, count($writes), 'all selected fields use one small multicall');
    $fields = array_map(function ($command) { return $command->params[1]; }, $writes[0]['commands']);
    strictAssertSame(array('chk-state', 'chk-time', 'chk-stime', 'chk-msg', 'chk-del'), $fields,
        'the one bundle carries the complete desired projection in deterministic order');
    strictAssertSame($writes[0]['commands'][1]->params[2], $writes[0]['commands'][2]->params[2],
        'chk-time and chk-stime share one captured timestamp');
    strictAssertSame((string) ruTrackerChecker::STE_UPTODATE, $model['state'], 'state landed');
    strictAssertSame('', $model['msg'], 'stale message cleared in the same bundle');
    strictAssertSame('', $model['del'], 'stale deletion counter cleared in the same bundle');
    $after = ruTrackerChecker::claimCheckForWorker($hash, time());
    strictAssertTrue($after !== false, 'the scheduler claim is released after the bundle');
    ruTrackerChecker::releaseCheckForWorker($hash, $after);
});

upTest($suite, 'fresh projection preserves a completed worker message and deletion generation', function () {
    $hash = str_repeat('A', 40);
    $rows = RuTrackerUpdatePass::parseMulticall(
        upRow($hash, 0, 'bt.t-ru.org', '2', '', '', '2:100', 'deleting|2/3', '100'));
    $model = array('state' => '2', 'time' => '100', 'stime' => '50',
        'msg' => 'deleting|3/3', 'del' => '3:200');
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function () {
        throw new RuntimeException('the alive fast path must not dispatch the checker');
    });
    rXMLRPCRequest::reset();
    upQueueProjection($rows, function () use (&$model, $hash) {
        return array($hash, $model['state'], $model['time'], $model['del'], $model['msg']);
    });

    $result = RuTrackerUpdatePass::run($rows);

    strictAssertSame(0, $result['uptodate'], 'a changed message/deletion projection is not counted');
    strictAssertSame('deleting|3/3', $model['msg'], 'the newer worker message survives');
    strictAssertSame('3:200', $model['del'], 'the newer deletion generation survives');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom|d.set_custom|d.set_custom|d.set_custom')),
        'no stale verdict bundle is sent');
});

upTest($suite, 'partial fast-verdict replies are read back, not counted, and converge on retry', function () {
    foreach (range(0, 4) as $prefix) {
        $hash = str_repeat('A', 40);
        $model = array('exists' => true, 'state' => '2', 'time' => '100', 'stime' => '50',
            'msg' => 'deleting|2/3', 'del' => '2:100');
        $makeRows = function () use (&$model, $hash) {
            return RuTrackerUpdatePass::parseMulticall(upRow($hash, 0, 'bt.t-ru.org',
                $model['state'], '', '', $model['del'], $model['msg'], $model['time']));
        };
        strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function () {
            throw new RuntimeException('the alive fast path must not dispatch the checker');
        });
        $GLOBALS['rutrackerCheckDebug'] = true;
        $logFile = tempnam(sys_get_temp_dir(), 'rut-fast-log-');
        $GLOBALS['log_file'] = $logFile;
        rXMLRPCRequest::reset();
        $rows = $makeRows();
        upQueueProjection($rows, function () use (&$model, $hash) {
            return array($hash, $model['state'], $model['time'], $model['del'], $model['msg']);
        });
        rXMLRPCRequest::queue(array_fill(0, 5, 'd.set_custom'), false, true,
            function ($commands) use (&$model, $prefix) {
                return upApplyVerdictCommands($model, $commands, $prefix);
            });
        rXMLRPCRequest::queue(array_fill(0, 5, 'd.get_custom'), true, false,
            function ($commands) use (&$model) { return upProjectionValues($model, $commands); });

        $first = RuTrackerUpdatePass::run($rows);
        strictAssertSame(0, $first['uptodate'], 'prefix ' . $prefix . ': incomplete projection is not counted');
        $diagnostics = preg_replace('/^\[[^\]]+\]\s*/m', '', (string) file_get_contents($logFile));
        $diagnostics = str_replace(array("\r", "\n"), ' ', trim($diagnostics));
        strictAssertEnglish($diagnostics,
            'prefix ' . $prefix . ': mismatches are diagnosed in English');

        rXMLRPCRequest::reset();
        $retryRows = $makeRows();
        upQueueProjection($retryRows, function () use (&$model, $hash) {
            return array($hash, $model['state'], $model['time'], $model['del'], $model['msg']);
        });
        $retryFields = 3 + ($model['msg'] !== '' ? 1 : 0) + ($model['del'] !== '' ? 1 : 0);
        rXMLRPCRequest::queue(array_fill(0, $retryFields, 'd.set_custom'), true, false,
            function ($commands) use (&$model) { return upApplyVerdictCommands($model, $commands); });
        $second = RuTrackerUpdatePass::run($retryRows);
        strictAssertSame(1, $second['uptodate'], 'prefix ' . $prefix . ': next-cycle re-derivation converges');
        strictAssertSame('', $model['msg'], 'prefix ' . $prefix . ': retry completes message cleanup');
        strictAssertSame('', $model['del'], 'prefix ' . $prefix . ': retry completes deletion cleanup');
        unset($GLOBALS['rutrackerCheckDebug']);
        unset($GLOBALS['log_file']);
        @unlink($logFile);
    }
});

upTest($suite, 'lost fast-verdict response is accepted only after complete readback', function () {
    $hash = str_repeat('A', 40);
    $model = array('state' => '2', 'time' => '100', 'stime' => '50', 'msg' => 'old', 'del' => '1:90');
    $rows = RuTrackerUpdatePass::parseMulticall(
        upRow($hash, 0, 'bt.t-ru.org', '2', '', '', '1:90', 'old', '100'));
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function () { throw new RuntimeException('no checker'); });
    rXMLRPCRequest::reset();
    upQueueProjection($rows, function () use (&$model, $hash) {
        return array($hash, $model['state'], $model['time'], $model['del'], $model['msg']);
    });
    rXMLRPCRequest::queue(array_fill(0, 5, 'd.set_custom'), false, true,
        function ($commands) use (&$model) { return upApplyVerdictCommands($model, $commands); });
    rXMLRPCRequest::queue(array_fill(0, 5, 'd.get_custom'), true, false,
        function ($commands) use (&$model) { return upProjectionValues($model, $commands); });

    $result = RuTrackerUpdatePass::run($rows);
    strictAssertSame(1, $result['uptodate'],
        'a lost reply after every selected field landed is truthfully accepted once');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom|d.set_custom|d.set_custom|d.set_custom')),
        'the ambiguous reply does not trigger a duplicate setter pipeline');
});

upTest($suite, 'short positive fast-verdict replies require exact projection readback', function () {
    foreach (array(
        'only a prefix landed' => array('prefix' => 2, 'expect' => 0),
        'the full projection landed but the reply was truncated' => array('prefix' => null, 'expect' => 1),
    ) as $label => $case) {
        $hash = str_repeat('A', 40);
        $model = array('state' => '2', 'time' => '100', 'stime' => '50', 'msg' => 'old', 'del' => '1:90');
        $rows = RuTrackerUpdatePass::parseMulticall(
            upRow($hash, 0, 'bt.t-ru.org', '2', '', '', '1:90', 'old', '100'));
        strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function () {
            throw new RuntimeException('the alive fast path must not dispatch the checker');
        });
        rXMLRPCRequest::reset();
        upQueueProjection($rows, function () use (&$model, $hash) {
            return array($hash, $model['state'], $model['time'], $model['del'], $model['msg']);
        });
        rXMLRPCRequest::queue(array_fill(0, 5, 'd.set_custom'), true, false,
            function ($commands) use (&$model, $case) {
                upApplyVerdictCommands($model, $commands, $case['prefix']);
                return array(0); // positive but shorter than the five-command request
            });
        rXMLRPCRequest::queue(array_fill(0, 5, 'd.get_custom'), true, false,
            function ($commands) use (&$model) { return upProjectionValues($model, $commands); });

        $result = RuTrackerUpdatePass::run($rows);

        strictAssertSame($case['expect'], $result['uptodate'],
            $label . ': applied count follows the measured complete projection');
        strictAssertSame(1, count(rXMLRPCRequest::requestsFor(
            'd.get_custom|d.get_custom|d.get_custom|d.get_custom|d.get_custom')),
            $label . ': a short positive write reply is always read back');
    }
});

upTest($suite, 'failed fast-verdict readback distinguishes confirmed absence from unknown presence', function () {
    foreach (array(
        'confirmed absent' => array('hashOk' => true, 'hashFault' => true, 'needle' => 'not found'),
        'confirmed present' => array('hashOk' => true, 'hashFault' => false, 'needle' => 'unknown'),
        'transport unknown' => array('hashOk' => false, 'hashFault' => false, 'needle' => 'unknown'),
    ) as $label => $case) {
        $hash = str_repeat('A', 40);
        $rows = RuTrackerUpdatePass::parseMulticall(upRow($hash, 0, 'bt.t-ru.org', '2', '', '', '', '', '100'));
        strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function () { throw new RuntimeException('no checker'); });
        $GLOBALS['rutrackerCheckDebug'] = true;
        $logFile = tempnam(sys_get_temp_dir(), 'rut-fast-log-');
        $GLOBALS['log_file'] = $logFile;
        rXMLRPCRequest::reset();
        upQueueProjection($rows);
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom', 'd.set_custom'), false, true, array());
        rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_custom'), false, true, array());
        rXMLRPCRequest::queue('d.hash', $case['hashOk'], $case['hashFault'], array());

        $result = RuTrackerUpdatePass::run($rows);
        strictAssertSame(0, $result['uptodate'], $label . ': failure is not counted');
        strictAssertTrue(stripos((string) file_get_contents($logFile), $case['needle']) !== false,
            $label . ': the diagnostic distinguishes the outcome');
        $after = ruTrackerChecker::claimCheckForWorker($hash, time());
        strictAssertTrue($after !== false, $label . ': claim is released even after failed verification');
        ruTrackerChecker::releaseCheckForWorker($hash, $after);
        unset($GLOBALS['rutrackerCheckDebug']);
        unset($GLOBALS['log_file']);
        @unlink($logFile);
    }
});

upTest($suite, 'verification scan failure releases every fast-verdict claim', function () {
    $hash = str_repeat('A', 40);
    $rows = RuTrackerUpdatePass::parseMulticall(upRow($hash, 0, 'bt.t-ru.org', '2', '', '', '', '', '100'));
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function () { throw new RuntimeException('no checker'); });
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', false, true, array());
    strictAssertSame(0, RuTrackerUpdatePass::run($rows)['uptodate'], 'nothing is written after an unreadable scan');
    $after = ruTrackerChecker::claimCheckForWorker($hash, time());
    strictAssertTrue($after !== false, 'the scan-failure finally path released the claim');
    ruTrackerChecker::releaseCheckForWorker($hash, $after);
});

upTest($suite, 'unknown orphan branch never falls back to standalone erase', function () {
    $hash = str_repeat('A', 40);
    $oldHash = str_repeat('B', 40);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($hash, $oldHash, '100'));
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom'), false, true, array()
    );
    rXMLRPCRequest::queue('branch', false, false, array());
    // Make the old fallback's final reread look unchanged and expired.
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom'), true, false, array($oldHash, '100')
    );
    rXMLRPCRequest::queue('d.erase', true, false, array(0));

    RuTrackerUpdatePass::reapOrphans(200);

    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('branch')),
        'the orphan generation gets one daemon-side ownership decision');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')),
        'an unknown result cannot authorize a separate erase after a reread');
});

upTest($suite, 'unknown generation clear never falls back to a standalone custom write', function () {
    $hash = str_repeat('A', 40);
    $expected = str_repeat('B', 40) . '-started-100';
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', false, false, array());
    rXMLRPCRequest::queue('d.get_custom', true, false, array($expected));
    rXMLRPCRequest::queue('d.set_custom', true, false, array(0));

    strictAssertSame(RuTrackerAtomicOwnership::UNKNOWN,
        strictInvoke('RuTrackerUpdatePass', 'clearReplacingGeneration', array($hash, $expected)),
        'unknown clear remains retryable');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('branch')),
        'the generation is evaluated once');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
        'no read-then-unconditional clear is issued');
});

upTest($suite, 'unknown revival never falls back to standalone open or start', function () {
    $stagedHash = str_repeat('A', 40);
    $oldHash = str_repeat('B', 40);
    $marker = str_repeat('c', 32);
    $rawRecord = $oldHash . '-started-100';
    $record = ruTrackerChecker::decodeInheritance($rawRecord);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', false, false, array());
    // The legacy fallback sees a stopped predecessor and would revive it.
    rXMLRPCRequest::queue(
        array('d.get_state', 'd.is_open', 'd.get_custom'), true, false, array(0, 0, '')
    );
    rXMLRPCRequest::queue(array('d.open', 'd.start'), true, false, array(0, 0));
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1));

    strictInvoke('RuTrackerUpdatePass', 'reviveStrandedPredecessor',
        array($stagedHash, $record, $marker, $rawRecord));

    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('branch')),
        'revival has one conditional mutation attempt');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
        'an unknown reply performs no blind predecessor revival');
});

upTest($suite, 'stopped-policy cleanup retains staged ownership when state changes before clear', function () {
    $stagedHash = str_repeat('A', 40);
    $oldHash = str_repeat('B', 40);
    $marker = str_repeat('c', 32);
    $rawRecord = $oldHash . '-stopped-100';
    $record = ruTrackerChecker::decodeInheritance($rawRecord);
    $expectedReplacing = $stagedHash . '-stopped-100';
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open', 'd.get_custom'), true, false,
        array(0, 0, $expectedReplacing));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED));
    // The exact generation remains: SKIPPED came from a state/open interleaving.
    rXMLRPCRequest::queue('d.get_custom', true, false, array($expectedReplacing));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));

    strictInvoke('RuTrackerUpdatePass', 'reviveStrandedPredecessor',
        array($stagedHash, $record, $marker, $rawRecord));

    strictAssertSame(1, count(sweepBranchRequestsForHash($oldHash)),
        'the stopped predecessor clear is attempted once under exact state/open');
    strictAssertSame(0, count(sweepBranchRequestsForHash($stagedHash)),
        'a state-change skip with the generation still present retains staged recovery');
    sweepAssertNoStandaloneOwnershipMutation('stopped-policy state interleaving');
});

upTest($suite, 'skipped revival with changed predecessor ownership cleans only the exact staged generation', function () {
    $stagedHash = str_repeat('A', 40);
    $oldHash = str_repeat('B', 40);
    $marker = str_repeat('c', 32);
    $rawRecord = $oldHash . '-started-100';
    $record = ruTrackerChecker::decodeInheritance($rawRecord);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED));
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open', 'd.get_custom', 'd.get_custom'), true, false,
        array(0, 0, '', str_repeat('C', 40) . '-started-200'));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));

    strictInvoke('RuTrackerUpdatePass', 'reviveStrandedPredecessor',
        array($stagedHash, $record, $marker, $rawRecord));

    strictAssertSame(1, count(sweepBranchRequestsForHash($oldHash)),
        'changed predecessor ownership is never cleared or revived by this generation');
    strictAssertSame(1, count(sweepBranchRequestsForHash($stagedHash)),
        'only the exact stale staged generation is discarded');
    sweepAssertNoStandaloneOwnershipMutation('changed predecessor ownership');
});

upTest($suite, 'unknown staged cleanup never falls back to standalone erase', function () {
    $hash = str_repeat('A', 40);
    $marker = str_repeat('b', 32);
    $record = str_repeat('C', 40) . '-started-100';
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', false, false, array());
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom'), true, false, array($marker, $record)
    );
    rXMLRPCRequest::queue('d.erase', true, false, array(0));

    strictInvoke('RuTrackerUpdatePass', 'discardStaged', array($hash, $marker, $record));

    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('branch')),
        'staged cleanup has one conditional erase attempt');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')),
        'unknown cleanup is retained for retry instead of erased blind');
});

upTest($suite, 'unknown stranded activation never falls back to standalone run commands', function () {
    $hash = str_repeat('A', 40);
    $oldHash = str_repeat('B', 40);
    $marker = str_repeat('c', 32);
    $rawRecord = $oldHash . '-started-100';
    $record = ruTrackerChecker::decodeInheritance($rawRecord);
    $projection = array(0, 0, 0, 0, 0, '', '0', (string) ruTrackerChecker::STE_UPDATED,
        $marker, $rawRecord);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', false, false, array());
    rXMLRPCRequest::queue(array('d.open', 'd.start'), true, false, array(0, 0));
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1));

    strictInvoke('RuTrackerUpdatePass', 'finishStrandedReplacement',
        array($hash, $record, $projection, false));

    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('branch')),
        'stranded activation has one conditional run attempt');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
        'unknown activation performs no standalone retry');
});

upTest($suite, 'a case may poison scheduler singletons and statics', function () {
    rTorrentSettings::get()->session = '/task-5-poisoned-session/';
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function () {
        throw new RuntimeException('poisoned checker must not survive its case');
    });
    strictSetPrivateStatic('RuTrackerUpdatePass', 'foreignAuthoritativeResolver', function () {
        return true;
    });
});

upTest($suite, 'the next case receives fresh scheduler singletons and statics', function () {
    strictAssertSame('/nonexistent/', rTorrentSettings::get()->session,
        'rTorrentSettings singleton state is isolated per case');

    foreach (array('checker', 'foreignAuthoritativeResolver') as $property) {
        $reflection = new ReflectionProperty('RuTrackerUpdatePass', $property);
        if (PHP_VERSION_ID < 80100) $reflection->setAccessible(true);
        strictAssertSame(null, $reflection->getValue(),
            'RuTrackerUpdatePass::$' . $property . ' is isolated per case');
    }
});

// --- Persisted chk-state / chk-time are canonical integers or nothing -------
//
// intval() used to turn every unreadable reading into 0 -- which is exactly
// the value that means "never checked", the state that buys a full
// destructive check. A row whose snapshot cannot be read is now dropped
// WHOLE, before classification, dispatch or any write.

upTest($suite, 'testParseMulticallDropsARowWhoseStoredStateOrTimeIsNotCanonical', function () {
    $hash = str_repeat('A', 40);
    $bad = array('leading zero' => '01', 'leading plus' => '+1', 'negative' => '-1',
        'minus zero' => '-0', 'padded' => ' 3', 'trailing space' => '3 ',
        'digits then letters' => '3oops', 'float string' => '3.0', 'float' => 3.0,
        'bool' => true, 'array' => array(3));
    foreach ($bad as $label => $value) {
        strictAssertSame(array(),
            RuTrackerUpdatePass::parseMulticall(upRow($hash, 0, 'bt.t-ru.org', $value)),
            $label . ': a malformed chk-state drops the whole row');
        strictAssertSame(array(),
            RuTrackerUpdatePass::parseMulticall(upRow($hash, 0, 'bt.t-ru.org', '3', '', '', '', '', $value)),
            $label . ': a malformed chk-time drops the whole row');
    }

    // ...while the two well-formed readings a real daemon produces are still
    // read exactly: an UNSET custom comes back as the empty string, and that
    // is the only spelling of "never checked".
    $never = RuTrackerUpdatePass::parseMulticall(upRow($hash, 0, 'bt.t-ru.org', '', '', '', '', '', ''));
    strictAssertSame(1, count($never), 'an unset chk-state/chk-time is still a readable row');
    strictAssertSame(0, $never[0]['state'], 'an unset chk-state reads as never checked');
    strictAssertSame(0, $never[0]['time'], 'an unset chk-time reads as never checked');
    $set = RuTrackerUpdatePass::parseMulticall(upRow($hash, 0, 'bt.t-ru.org', '3', '', '', '', '', '100'));
    strictAssertSame(3, $set[0]['state'], 'a canonical chk-state is read as the int it is');
    strictAssertSame(100, $set[0]['time'], 'a canonical chk-time is read as the int it is');
});

upTest($suite, 'testAMalformedSnapshotRowIsNeverDispatchedAndNeverWritten', function () {
    $hash = str_repeat('A', 40);
    // A 6-failure RuTracker row is the ordinary 'candidate' that WOULD be
    // dispatched; only the unreadable chk-state stops it.
    $rows = RuTrackerUpdatePass::parseMulticall(upRow($hash, 6, 'bt.t-ru.org', '01'));
    strictAssertSame(array(), $rows, 'the row never becomes a row at all');

    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function () {
        throw new RuntimeException('a row whose snapshot could not be read must never be dispatched');
    });
    rXMLRPCRequest::reset();
    $result = RuTrackerUpdatePass::run($rows);

    strictAssertSame(array(), $result['checked'], 'nothing is checked');
    strictAssertSame(0, $result['uptodate'], 'nothing is counted');
    strictAssertSame(array(), rXMLRPCRequest::$requests,
        'and not one XMLRPC request is made on its behalf');
});

upTest($suite, 'testTheFreshCasScanRefusesToCompareAgainstAnUnreadableRow', function () {
    foreach (array('leading zero state' => array('03', '100'),
                   'leading zero time' => array('3', '0100'),
                   'padded state' => array(' 3', '100'),
                   'letters in time' => array('3', '100x')) as $label => $live) {
        $hash = str_repeat('A', 40);
        // An 'alive' row: the free fast path that buffers an UPTODATE verdict
        // and flushes it behind one fresh scan.
        $rows = RuTrackerUpdatePass::parseMulticall(
            upRow($hash, 0, 'bt.t-ru.org', '3', '', '', '2:100', 'deleting|2/3', '100'));
        strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function () {
            throw new RuntimeException('the alive fast path must not dispatch the checker');
        });
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('d.multicall', true, false,
            array($hash, $live[0], $live[1], '2:100', 'deleting|2/3'));

        $result = RuTrackerUpdatePass::run($rows);

        strictAssertSame(0, $result['uptodate'], $label . ': an uncomparable row is not counted');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
            $label . ': and nothing is written over it');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
            $label . ': not even the state pair');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor(
            'd.set_custom|d.set_custom|d.set_custom|d.set_custom|d.set_custom')),
            $label . ': and no complete verdict bundle');
        // The claim the buffer took is still given back, so the next cycle can
        // try again rather than waiting out the lease.
        $after = ruTrackerChecker::claimCheckForWorker($hash, time());
        strictAssertTrue($after !== false, $label . ': the scheduler claim is released');
        ruTrackerChecker::releaseCheckForWorker($hash, $after);
    }

    // Control: the very same row with a canonical fresh reading does land.
    $hash = str_repeat('A', 40);
    $rows = RuTrackerUpdatePass::parseMulticall(
        upRow($hash, 0, 'bt.t-ru.org', '3', '', '', '2:100', 'deleting|2/3', '100'));
    strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', function () {
        throw new RuntimeException('the alive fast path must not dispatch the checker');
    });
    rXMLRPCRequest::reset();
    upQueueUnchanged($rows);
    rXMLRPCRequest::queue(array_fill(0, 5, 'd.set_custom'), true, false, array(0, 0, 0, 0, 0));
    strictAssertSame(1, RuTrackerUpdatePass::run($rows)['uptodate'],
        'a canonical fresh reading still lets the buffered verdict through');
});

// The staged copy's own progress counters are read over XMLRPC too, and they
// are what tells "nobody ever opened this" from "somebody did". A reading that
// will not parse authorises neither a blind start nor retiring the ownership
// keys: the exact generation is kept for a later cycle.
// The two progress counters with a genuine int32 domain. d.get_completed_bytes
// is NOT one of them -- see the wider-than-PHP_INT_MAX case above -- so it is
// read with intval() at its use site the way it always was, and the fail-closed
// guard rests on the two columns beside it, which report the same fact
// (somebody opened this copy) and can be parsed exactly.
upTest($suite, 'testMalformedStagedProgressCountersNeverStartOrRetireAReplacement', function () {
    foreach (array('hashed' => 2, 'complete' => 4) as $label => $slot) {
        rXMLRPCRequest::reset();
        $hash = str_repeat('A', 40);
        sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
        $detail = array(0, 0, 0, 0, 0);
        $detail[$slot] = '0oops';
        sweepDetail($detail[0], $detail[1], $detail[2], $detail[3], $detail[4], '', '1000');
        rXMLRPCRequest::queue('d.hash', true, true, array());   // the predecessor is provably gone

        RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

        strictAssertSame(0, count(sweepBranchRequestsForHash($hash)),
            $label . ': an unreadable counter authorises no atomic activation or key clear');
        sweepAssertNoStandaloneOwnershipMutation($label . ': malformed staged progress counters');
    }

    // And the column with no int32 bound is judged in the STRING domain.
    // intval() read every counter it could not parse as 0, which is the
    // fail-OPEN direction for a guard that asks "was this copy ever opened?"
    // -- a wrong "no" starts a download somebody deliberately left stopped.
    // Exactly canonical zero is the only reading that means "never opened",
    // and that also accepts an arbitrarily wide well-formed counter, which is
    // why this column is not funnelled through the int32 parser.
    //
    // It DEFERS on a reading it cannot make, exactly like the four columns
    // beside it, rather than retiring the transaction's ownership keys: that
    // retire is irreversible and it is the only durable handle the next cycle
    // has. A faulting multicall member injects its faultString into the flat
    // value list, so the same transient fault one slot earlier is simply
    // retried while this one used to give up automatic activation for good.
    foreach (array('trailing text' => '0oops', 'leading zero' => '00', 'empty' => '',
        'text' => 'lots', 'float' => 0.0, 'bool' => false, 'negative' => '-0',
        'padded' => ' 0',
        'fault string' => 'Method \'d.get_completed_bytes\' does not exist') as $label => $bytes) {
        rXMLRPCRequest::reset();
        $hash = str_repeat('A', 40);
        sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
        sweepDetail(0, 0, 0, $bytes, 0, '', '1000');
        rXMLRPCRequest::queue('d.hash', true, true, array());   // the predecessor is provably gone
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));

        RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);

        strictAssertSame(0, count(sweepBranchRequestsForHash($hash)),
            $label . ': a byte counter that does not read as a count is no evidence the copy was'
                . ' never opened, so nothing is started -- and the ownership keys the next cycle'
                . ' needs are kept rather than retired on that same unreadable byte');
        sweepAssertNoStandaloneOwnershipMutation($label . ': unreadable completed bytes');
    }

    // The refusal says which reading it could not make, rather than blaming
    // the operator for opening a torrent nobody opened.
    $log = upCapturedLog(function () {
        rXMLRPCRequest::reset();
        $hash = str_repeat('A', 40);
        sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
        sweepDetail(0, 0, 0, '0oops', 0, '', '1000');
        rXMLRPCRequest::queue('d.hash', true, true, array());
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
        RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);
    });
    strictAssertTrue(strpos($log, 'does not read as a count') !== false,
        'the unreadable byte counter is named rather than reported as a human decision');

    // Controls: canonical zero -- in either spelling rTorrent may answer with
    // -- still finishes the stranded replacement, and a well-formed counter
    // far past int32 is simply not zero and needs no int32 parser to say so.
    foreach (array('int zero' => 0, 'string zero' => '0') as $label => $bytes) {
        rXMLRPCRequest::reset();
        $hash = str_repeat('A', 40);
        sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
        sweepDetail(0, 0, 0, $bytes, 0, '', '1000');
        rXMLRPCRequest::queue('d.hash', true, true, array());
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
        RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);
        strictAssertSame(true, sweepBranchOpens($hash),
            'control ' . $label . ': canonical zero counters still finish the stranded replacement');
    }
    foreach (array('5 GiB' => '5368709120',
        'past PHP_INT_MAX' => '9223372036854775808') as $label => $bytes) {
        rXMLRPCRequest::reset();
        $hash = str_repeat('A', 40);
        sweepScan(array(array($hash, 'nonce', str_repeat('B', 40) . '-started-1000')));
        sweepDetail(0, 0, 0, $bytes, 0, '', '1000');
        rXMLRPCRequest::queue('d.hash', true, true, array());
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
        RuTrackerUpdatePass::sweepReplacements(1000 + ruTrackerChecker::MAX_LOCK_TIME + 1);
        strictAssertSame(false, sweepBranchOpens($hash),
            'control ' . $label . ': a well-formed counter wider than int32 is read, not refused');
    }
});

exit($suite->run());
