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

        strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
        Snoopy::queue($url, 304, '');
        strictAssertSame('unchanged', RuTrackerForumIndex::fetchDump(921), '304 -> unchanged');
        strictAssertSame(0, RuTrackerForumIndex::cachedDump(921)[6868321]['tor_status'], 'cache readable');

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
        strictAssertSame(45, $state['dumps'][921][6868321]['seeders'],
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
    strictAssertSame(array(111 => 10, 222 => 20), $map, 'both resolved');
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
    strictAssertSame(array(222 => 20), RuTrackerForumIndex::sweep(array(111, 222), $fetcher), 'partial');
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
        strictAssertSame($now, $state['misses'][111], 'fresh miss is kept');
    } finally {
        strictRemoveTree($tmp);
    }
});

exit($suite->run());
