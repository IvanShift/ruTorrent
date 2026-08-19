<?php

define('TESTLIB_HANDLER_STUBS', 1);
require_once(__DIR__ . '/TestLib.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/forumindex.php');

$suite = new StrictTestSuite();

function fiFeed()
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        . '<feed xmlns="http://www.w3.org/2005/Atom">'
        . '<entry><title>[Обновлено] Сериал (2026) WEB-DL</title>'
        . '<link href="https://rutracker.org/forum/viewtopic.php?t=6880555"/>'
        . '<category term="f-1106" label="Аниме"/></entry>'
        . '<entry><title>Обычная раздача</title>'
        . '<link href="https://rutracker.org/forum/viewtopic.php?t=555"/>'
        . '<category term="f-42" label="Прочее"/></entry>'
        . '</feed>';
}

function fiDump($topicId, $status, $hash, $seeders = 7)
{
    return json_encode(array(
        'format' => array('topic_id' => array('tor_status', 'seeders', 'reg_time', 'tor_size_bytes',
            'keeping_priority', 'keepers', 'seeder_last_seen', 'info_hash', 'topic_poster', 'leechers')),
        'result' => array((string) $topicId => array($status, $seeders, 1, 2, 0, array(), 3, $hash, 4, 0)),
    ));
}

$suite->test('parseFeed maps topic to forum and flags updates', function () {
    $map = RuTrackerForumIndex::parseFeed(fiFeed());
    strictAssertSame(array('forum' => 1106, 'updated' => true), $map[6880555], 'updated entry');
    strictAssertSame(array('forum' => 42, 'updated' => false), $map[555], 'plain entry');
});

$suite->test('parseFeed survives garbage', function () {
    strictAssertSame(array(), RuTrackerForumIndex::parseFeed('<html>no</html>'), 'not a feed');
    strictAssertSame(array(), RuTrackerForumIndex::parseFeed(''), 'empty');
});

$suite->test('parseDump follows the declared column format', function () {
    $rows = RuTrackerForumIndex::parseDump(fiDump(6868321, 0, str_repeat('F', 40), 45));
    strictAssertSame(array('tor_status' => 0, 'info_hash' => str_repeat('F', 40), 'seeders' => 45),
        $rows[6868321], 'row');
    strictAssertSame(array(), RuTrackerForumIndex::parseDump('{"error":1}'), 'no result key');
    strictAssertSame(array(), RuTrackerForumIndex::parseDump('not json'), 'garbage');

    // The dump declares its own column order and nothing promises it stays
    // stable across the API's versions -- every other fixture in this tree
    // happens to use the production order, so only a deliberately permuted
    // one can tell "follows the format" apart from hardcoded indexes.
    $permuted = json_encode(array(
        'format' => array('topic_id' => array('info_hash', 'leechers', 'seeders', 'tor_status')),
        'result' => array('6868321' => array(str_repeat('F', 40), 9, 45, 2)),
    ));
    strictAssertSame(array('tor_status' => 2, 'info_hash' => str_repeat('F', 40), 'seeders' => 45),
        RuTrackerForumIndex::parseDump($permuted)[6868321],
        'a permuted format still yields the same logical row');

    // Every fixture in this tree happens to use uppercase hashes, but the
    // dumps serve lowercase ones and every comparison downstream (the local
    // d.get_hash, chk-meta-new) is uppercase.
    strictAssertSame(str_repeat('F', 40),
        RuTrackerForumIndex::parseDump(fiDump(6868321, 0, str_repeat('f', 40)))[6868321]['info_hash'],
        'a lowercase dump hash is normalised to uppercase');
});

$suite->test('fetchDump caches by ETag and serves 304 from state', function () {
    $tmp = sys_get_temp_dir() . '/chk-forumindex-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);
    Snoopy::reset();

    try {
        $url = RuTrackerForumIndex::DUMP_URL . '921';
        // Each call below stands in for a separate cycle (a separate PHP
        // process in production, see the memo tests below), so the
        // per-process memo is reset before each one -- otherwise the second
        // and third calls would just replay the first call's memoised
        // answer instead of exercising the ETag/304/error paths.
        strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
        Snoopy::queue($url, 200, fiDump(6868321, 0, str_repeat('F', 40), 45), array('ETag: "abc123"'));
        $rows = RuTrackerForumIndex::fetchDump(921);
        strictAssertSame(1, count($rows), 'first fetch parses one row');
        strictAssertSame(45, $rows[6868321]['seeders'], 'row seeders');
        strictAssertSame(array(), Snoopy::$rawheadersLog[0],
            'the first fetch has no ETag to condition on');
        strictAssertSame('"abc123"', RuTrackerState::load('forumindex')['etags'][921],
            'the answered ETag is persisted for the next cycle');
        strictAssertSame(1, count(RuTrackerState::load('forumdump-921')),
            'the rows live in their own per-forum document');
        strictAssertTrue(!isset(RuTrackerState::load('forumindex')['dumps']),
            'never inside forumindex.json, whose every small mutation would rewrite them');
        // Age the retention clock almost to expiry: only an actual touch on
        // the 304 below can explain it moving forward again.
        $aged = time() - 29 * 86400;
        RuTrackerState::update('forumindex', function ($state) use ($aged) {
            $state['dump_touched'][921] = $aged;
            return $state;
        });

        strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
        Snoopy::queue($url, 304, '');
        strictAssertSame('unchanged', RuTrackerForumIndex::fetchDump(921), '304 -> unchanged');
        strictAssertSame(array('If-None-Match' => '"abc123"'), Snoopy::$rawheadersLog[1],
            'the second fetch presents the persisted ETag, which is what makes the 304 possible');
        strictAssertSame(0, RuTrackerForumIndex::cachedDump(921)[6868321]['tor_status'], 'cache readable');
        strictAssertTrue(RuTrackerState::load('forumindex')['dump_touched'][921] > $aged,
            'a 304 still counts as use: the retention clock is touched, not left to expire the cache');

        strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
        Snoopy::queue($url, 500, '');
        strictAssertSame(null, RuTrackerForumIndex::fetchDump(921), 'error -> null');
        strictAssertSame(45, RuTrackerForumIndex::cachedDump(921)[6868321]['seeders'], 'cache survives a fetch error');
    } finally {
        strictRemoveTree($tmp);
        strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    }
});

$suite->test('fetchDump memoises per process: repeated calls for the same forum id trigger exactly one fetch', function () {
    $tmp = sys_get_temp_dir() . '/chk-forumindex-memo-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);
    strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    Snoopy::reset();

    try {
        $url = RuTrackerForumIndex::DUMP_URL . '921';
        Snoopy::queue($url, 200, fiDump(6868321, 0, str_repeat('F', 40), 45), array('ETag: "abc123"'));

        // Three candidate torrents sharing forum 921 in the same cycle.
        $first = RuTrackerForumIndex::fetchDump(921);
        $second = RuTrackerForumIndex::fetchDump(921);
        $third = RuTrackerForumIndex::fetchDump(921);

        strictAssertSame($first, $second, 'second candidate served from the memo, not a fresh fetch');
        strictAssertSame($first, $third, 'third candidate served from the memo too');
        strictAssertSame(1, count(Snoopy::$requests), 'exactly one GET no matter how many candidates share the forum');
    } finally {
        strictRemoveTree($tmp);
        strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    }
});

$suite->test('fetchDump memo does not serve a stale answer once cleared for a new cycle', function () {
    $tmp = sys_get_temp_dir() . '/chk-forumindex-memo-clear-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);
    strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    Snoopy::reset();

    try {
        $url = RuTrackerForumIndex::DUMP_URL . '921';
        Snoopy::queue($url, 200, fiDump(111, 0, str_repeat('A', 40)));
        RuTrackerForumIndex::fetchDump(921);
        strictAssertSame(1, count(Snoopy::$requests), 'first cycle: one fetch');

        // Next cycle: a fresh PHP process in production never carries the
        // previous process's memo, modelled here by clearing it explicitly.
        strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
        Snoopy::queue($url, 304, '');
        strictAssertSame('unchanged', RuTrackerForumIndex::fetchDump(921), 'next cycle: a real conditional GET runs again');
        strictAssertSame(2, count(Snoopy::$requests), 'the cleared memo does not suppress the new cycle\'s fetch');
    } finally {
        strictRemoveTree($tmp);
        strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    }
});

$suite->test('fetchDump prunes dump entries untouched for 30 days', function () {
    $tmp = sys_get_temp_dir() . '/chk-forumindex-prune-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);
    strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    Snoopy::reset();

    try {
        RuTrackerState::save('forumindex', array(
            'dumps' => array(999 => array(1 => array('tor_status' => 0, 'info_hash' => str_repeat('A', 40), 'seeders' => 1))),
            'etags' => array(999 => '"stale"'),
            'dump_touched' => array(999 => time() - 31 * 86400),
        ));

        $url = RuTrackerForumIndex::DUMP_URL . '921';
        Snoopy::queue($url, 200, fiDump(6868321, 0, str_repeat('F', 40), 45));
        RuTrackerForumIndex::fetchDump(921);

        strictAssertSame(null, RuTrackerForumIndex::cachedDump(999), 'dump untouched for 30+ days is pruned');
        strictAssertSame(45, RuTrackerForumIndex::cachedDump(921)[6868321]['seeders'], 'freshly fetched dump is kept');
    } finally {
        strictRemoveTree($tmp);
    }
});

// Stand-in for a caller-supplied Snoopy client (fetchDump()'s $client
// parameter): its fetchComplex() performs a concurrent write to the SAME
// 'forumindex' state file fetchDump() itself is about to update, simulating
// another writer (e.g. forumcrawl.php's markSweep()) finishing while this
// fetch is still in flight. Whether that write survives fetchDump()'s own
// save can only prove the state lock is not held across the fetch.
class ForumIndexConcurrentWriteClient
{
    public $status = 200;
    public $results = '';
    public $headers = array();
    public $rawheaders = array();

    public function fetchComplex($url, $method = 'GET', $contentType = '', $body = '')
    {
        RuTrackerState::update('forumindex', function ($state) {
            $state['last_sweep'] = 999999;
            return $state;
        });
        $this->results = fiDump(6868321, 0, str_repeat('F', 40), 45);
        $this->headers = array('ETag: "concurrent"');
        return true;
    }
}

$suite->test('fetchDump does not hold the state lock across the HTTP fetch: a concurrent write during fetchComplex() survives', function () {
    $tmp = sys_get_temp_dir() . '/chk-forumindex-concurrent-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
        $client = new ForumIndexConcurrentWriteClient();
        $rows = RuTrackerForumIndex::fetchDump(921, $client);
        strictAssertSame(1, count($rows), 'dump was parsed and returned');

        $state = RuTrackerState::load('forumindex');
        strictAssertSame(999999, $state['last_sweep'] ?? null,
            'the concurrent write that ran during fetchComplex() was not erased by fetchDump()\'s own write');
        strictAssertSame(45, RuTrackerState::load('forumdump-921')[6868321]['seeders'],
            'and fetchDump() still persisted its own dump on top of that');
    } finally {
        strictRemoveTree($tmp);
    }
});

$suite->test('sweep queue and cooldown', function () {
    $tmp = sys_get_temp_dir() . '/chk-forumindex-queue-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
        RuTrackerForumIndex::queueTopic(111);
        RuTrackerForumIndex::queueTopic(222);
        RuTrackerForumIndex::queueTopic(111); // dedup
        strictAssertSame(array(111, 222), RuTrackerForumIndex::takeQueue(), 'queue drained deduped');
        strictAssertSame(array(), RuTrackerForumIndex::takeQueue(), 'queue is emptied by take');

        strictAssertTrue(RuTrackerForumIndex::sweepAllowed(1000), 'first sweep allowed');
        RuTrackerForumIndex::markSweep(1000);
        strictAssertTrue(!RuTrackerForumIndex::sweepAllowed(1000 + 86000), 'cooldown holds');
        strictAssertTrue(RuTrackerForumIndex::sweepAllowed(1000 + 86401), 'cooldown expires');
    } finally {
        strictRemoveTree($tmp);
    }
});

$suite->test('sweep visits forums until all wanted topics resolve', function () {
    $bodies = array(
        RuTrackerForumIndex::TREE_URL => json_encode(array('result' => array(
            'f' => array('10' => 'A', '20' => 'B', '30' => 'C')))),
        RuTrackerForumIndex::DUMP_URL . '10' => fiDump(111, 0, str_repeat('A', 40)),
        RuTrackerForumIndex::DUMP_URL . '20' => fiDump(222, 2, str_repeat('B', 40)),
        RuTrackerForumIndex::DUMP_URL . '30' => fiDump(333, 0, str_repeat('C', 40)),
    );
    $visited = array();
    $fetcher = function ($url) use ($bodies, &$visited) {
        $visited[] = $url;
        return $bodies[$url] ?? null;
    };
    $map = RuTrackerForumIndex::sweep(array(111, 222), $fetcher);
    strictAssertSame(array('resolved' => array(111 => 10, 222 => 20), 'complete' => true), $map,
        'both resolved, every dump read');
    strictAssertTrue(!in_array(RuTrackerForumIndex::DUMP_URL . '30', $visited, true),
        'sweep stops early once every wanted topic is found');
});

$suite->test('sweep tolerates dead forums and reports partial results', function () {
    $fetcher = function ($url) {
        if ($url === RuTrackerForumIndex::TREE_URL)
            return json_encode(array('result' => array('f' => array('10' => 'A', '20' => 'B'))));
        if ($url === RuTrackerForumIndex::DUMP_URL . '20')
            return fiDump(222, 0, str_repeat('B', 40));
        return null; // forum 10 unreachable
    };
    strictAssertSame(array('resolved' => array(222 => 20), 'complete' => false),
        RuTrackerForumIndex::sweep(array(111, 222), $fetcher),
        'partial results are reported, and the unread forum makes the crawl incomplete');
});

$suite->test('sweep returns null, not empty array, when the forum tree itself cannot be fetched', function () {
    strictAssertSame(null, RuTrackerForumIndex::sweep(array(111), function ($url) { return null; }),
        'tree fetch failure');
    strictAssertSame(null, RuTrackerForumIndex::sweep(array(111), function ($url) { return 'not json'; }),
        'tree unparseable');
    strictAssertSame(null, RuTrackerForumIndex::sweep(array(111), function ($url) {
        return json_encode(array('result' => array())); // no 'f' key
    }), 'tree missing forum list');
});

$suite->test('queueTopic suppresses a recent miss, allows queueing again once the window elapses, and leaves resolved topics unaffected', function () {
    $tmp = sys_get_temp_dir() . '/chk-forumindex-miss-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
        $now = time();

        RuTrackerForumIndex::markMiss(111, $now);
        RuTrackerForumIndex::queueTopic(111);
        strictAssertSame(array(), RuTrackerForumIndex::takeQueue(), 'a miss inside the window is not queued');

        RuTrackerForumIndex::markMiss(222, $now - 86401);
        RuTrackerForumIndex::queueTopic(222);
        strictAssertSame(array(222), RuTrackerForumIndex::takeQueue(), 'a miss past the window is queueable again');

        RuTrackerForumIndex::queueTopic(333); // never missed
        strictAssertSame(array(333), RuTrackerForumIndex::takeQueue(), 'a topic without a miss record is unaffected');
    } finally {
        strictRemoveTree($tmp);
    }
});

$suite->test('markMiss prunes miss records older than the suppression window', function () {
    $tmp = sys_get_temp_dir() . '/chk-forumindex-miss-prune-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
        $now = time();
        RuTrackerForumIndex::markMiss(999, $now - 86401);
        RuTrackerForumIndex::markMiss(111, $now);

        $state = RuTrackerState::load('forumindex');
        strictAssertTrue(!isset($state['misses'][999]), 'stale miss is pruned on the next write');
        strictAssertSame(array('at' => $now, 'n' => 1), $state['misses'][111],
            'fresh miss is kept and counted as the first');
    } finally {
        strictRemoveTree($tmp);
    }
});


$suite->test('a repeated miss doubles its suppression window, so a deleted topic costs ever fewer crawls', function () {
    $tmp = sys_get_temp_dir() . '/chk-forumindex-miss-esc-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
        $now = time();

        // Two misses, the latest one cooldown ago: a first miss would be
        // queueable again by now, a second is suppressed for two cooldowns.
        RuTrackerForumIndex::markMiss(111, $now - 86401);
        RuTrackerForumIndex::markMiss(111, $now - 86401);
        RuTrackerForumIndex::queueTopic(111);
        strictAssertSame(array(), RuTrackerForumIndex::takeQueue(),
            'the second miss is still suppressed after one cooldown');

        // The same twice-missed topic, its record two cooldowns old.
        RuTrackerForumIndex::markMiss(222, $now - 172801);
        RuTrackerForumIndex::markMiss(222, $now - 172801);
        RuTrackerForumIndex::queueTopic(222);
        strictAssertSame(array(222), RuTrackerForumIndex::takeQueue(),
            'and queueable again once the doubled window elapses');

        $state = RuTrackerState::load('forumindex');
        strictAssertSame(2, $state['misses'][111]['n'], 'the record carries the miss count');
    } finally {
        strictRemoveTree($tmp);
    }
});

$suite->test('a miss recorded before the counter existed still suppresses for one window', function () {
    $tmp = sys_get_temp_dir() . '/chk-forumindex-miss-legacy-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);

    try {
        $now = time();
        // The pre-counter shape: a bare timestamp.
        RuTrackerState::save('forumindex', array('misses' => array(111 => $now, 222 => $now - 86401)));

        RuTrackerForumIndex::queueTopic(111);
        strictAssertSame(array(), RuTrackerForumIndex::takeQueue(), 'a fresh legacy miss suppresses');
        RuTrackerForumIndex::queueTopic(222);
        strictAssertSame(array(222), RuTrackerForumIndex::takeQueue(), 'an expired legacy miss does not');
    } finally {
        strictRemoveTree($tmp);
    }
});

$suite->test('a run of consecutive fetch failures aborts the sweep as transient instead of hammering on', function () {
    $tree = json_encode(array('result' => array('f' => array_fill_keys(range(1, 20), 'forum'))));

    // Every dump fetch fails: the crawl must stop at the abort threshold and
    // conclude nothing, not walk all 20 forums.
    $fetches = 0;
    $result = RuTrackerForumIndex::sweep(array(111), function ($url) use ($tree, &$fetches) {
        if (strpos($url, 'cat_forum_tree') !== false) return $tree;
        $fetches++;
        return null;
    });
    strictAssertSame(null, $result, 'a refusing tracker ends the crawl with nothing concluded');
    strictAssertSame(RuTrackerForumIndex::SWEEP_FAILURE_ABORT, $fetches,
        'the crawl stopped at the abort threshold, not at the end of the tree');

    // Scattered failures reset the run: one short of the threshold, then a
    // success, over and over -- the crawl must complete.
    $fetches = 0;
    $result = RuTrackerForumIndex::sweep(array(999999), function ($url) use ($tree, &$fetches) {
        if (strpos($url, 'cat_forum_tree') !== false) return $tree;
        $fetches++;
        if ($fetches % RuTrackerForumIndex::SWEEP_FAILURE_ABORT === 0)
            return json_encode(array('result' => array(), 'format' => array()));
        return null;
    });
    strictAssertSame(array('resolved' => array(), 'complete' => false), $result,
        'scattered failures do not abort the crawl -- but they DO mark it incomplete');
    strictAssertSame(20, $fetches, 'every forum was still visited');
});

// --- runCrawl(): the crawl transaction, testable end to end ------------------

function fiCrawlDir($suffix)
{
    $tmp = sys_get_temp_dir() . '/chk-forumindex-crawl-' . $suffix . '-' . getmypid();
    strictRemoveTree($tmp);
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);
    return $tmp;
}

$suite->test('runCrawl bails out before draining the queue when the fleet scan fails', function () {
    $tmp = fiCrawlDir('scanfail');
    try {
        RuTrackerForumIndex::queueTopic(555);
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('d.multicall', false, false, array()); // the scan itself fails

        strictAssertSame(null, RuTrackerForumIndex::runCrawl(time(), function ($wanted) {
            throw new RuntimeException('the sweep must never start');
        }), 'nothing to log');
        strictAssertSame(array(555), RuTrackerForumIndex::takeQueuePeek(),
            'the queue is left alone: a drained topic could not have been requeued');
        strictAssertTrue(RuTrackerForumIndex::sweepAllowed(time()),
            'the cooldown is not marked for a crawl that never ran');
    } finally {
        strictRemoveTree($tmp);
    }
});

$suite->test('runCrawl requeues the whole wanted set when the crawl fails, and records misses only when it completes', function () {
    $tmp = fiCrawlDir('branches');
    try {
        // Failed crawl (sweeper answers null): both the queued topic and the
        // fleet-scanned one must be requeued, and no miss recorded.
        RuTrackerForumIndex::queueTopic(555);
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('d.multicall', true, false,
            array(str_repeat('A', 40), '777', ''));   // hash, chk-topic, chk-forum unknown
        $line = RuTrackerForumIndex::runCrawl(time(), function ($wanted) { return null; });
        strictAssertSame('wanted 2, crawl failed', $line, 'the failure is reported for the log');
        $queued = RuTrackerForumIndex::takeQueuePeek();
        sort($queued);
        strictAssertSame(array(555, 777), $queued, 'every wanted topic is back in the queue');
        strictAssertSame(array(), RuTrackerState::load('forumindex')['misses'] ?? array(),
            'a failed crawl proves nothing, so nothing is marked missed');

        // A thrown sweeper is the same transient outcome, with the reason in
        // the log line.
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), '777', ''));
        $line = RuTrackerForumIndex::runCrawl(time(), function ($wanted) {
            throw new RuntimeException('boom');
        });
        strictAssertSame('wanted 2, crawl failed: boom', $line, 'the exception is named');

        // Completed crawl: 777 resolves and is written back to its hash, 555
        // (queued, no torrent carries it) is recorded as a miss, not requeued.
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), '777', ''));
        rXMLRPCRequest::queue('d.set_custom', true, false, array());
        $line = RuTrackerForumIndex::runCrawl(time(), function ($wanted) {
            sort($wanted);
            strictAssertSame(array(555, 777), $wanted, 'the sweeper sees the full wanted set');
            return array('resolved' => array(777 => 1106), 'complete' => true);
        });
        strictAssertSame('wanted 2, resolved 1', $line, 'the completed crawl is reported');
        strictAssertSame(array(), RuTrackerForumIndex::takeQueuePeek(), 'nothing is requeued');
        $writes = rXMLRPCRequest::requestsFor('d.set_custom');
        strictAssertSame(1, count($writes), 'one chk-forum write');
        strictAssertSame(array(str_repeat('A', 40), 'chk-forum', '1106'),
            $writes[0]['commands'][0]->params, 'the resolved forum lands on the torrent that wanted it');
        strictAssertTrue(isset(RuTrackerState::load('forumindex')['misses'][555]),
            'the topic the completed crawl proved absent is marked missed');
        strictAssertTrue(!isset(RuTrackerState::load('forumindex')['misses'][777]),
            'the resolved topic is not');
    } finally {
        strictRemoveTree($tmp);
    }
});

$suite->test('runCrawl treats an incomplete crawl as inconclusive: written back, but nothing marked missed', function () {
    $tmp = fiCrawlDir('incomplete');
    try {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('d.multicall', true, false, array(
            str_repeat('A', 40), '777', '',
            str_repeat('C', 40), '555', ''));
        rXMLRPCRequest::queue('d.set_custom', true, false, array());
        $line = RuTrackerForumIndex::runCrawl(time(), function ($wanted) {
            return array('resolved' => array(777 => 1106), 'complete' => false); // some dumps went unread
        });
        strictAssertSame('wanted 2, resolved 1, 1 requeued: some dumps went unread', $line,
            'the log says what was left open');
        strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.set_custom')),
            'what WAS resolved is still written back');
        strictAssertSame(array(555), RuTrackerForumIndex::takeQueuePeek(),
            'the unresolved topic is requeued for the next sweep');
        strictAssertSame(array(), RuTrackerState::load('forumindex')['misses'] ?? array(),
            'and no miss is recorded: an unread dump proves no absence');
    } finally {
        strictRemoveTree($tmp);
    }
});

$suite->test('runCrawl marks the cooldown before crawling, so a mid-crawl death is not retried every cycle', function () {
    $tmp = fiCrawlDir('cooldown');
    try {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), '777', ''));
        $when = time();
        RuTrackerForumIndex::runCrawl($when, function ($wanted) use ($when) {
            strictAssertTrue(!RuTrackerForumIndex::sweepAllowed($when),
                'the cooldown is already marked while the crawl is still running');
            return null;
        });
    } finally {
        strictRemoveTree($tmp);
    }
});

exit($suite->run());
