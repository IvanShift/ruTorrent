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

// Give every stateful case its own directory and process-local memo lifecycle.
function fiStateTest($suite, $name, $callback)
{
    $suite->test($name, function () use ($callback) {
        return strictWithStateDir('chk-forumindex', function ($tmp) use ($callback) {
            strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
            try {
                return $callback($tmp);
            } finally {
                strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
            }
        });
    });
}


$suite->test('parseFeed maps each topic to its forum', function () {
    $map = RuTrackerForumIndex::parseFeed(fiFeed());
    strictAssertSame(array('forum' => 1106), $map[6880555], 'updated entry');
    strictAssertSame(array('forum' => 42), $map[555], 'plain entry');
});

$suite->test('parseFeed accepts Atom elements written with an explicit namespace prefix', function () {
    $xml = '<atom:feed xmlns:atom="http://www.w3.org/2005/Atom">'
        . '<atom:entry><atom:link href="https://rutracker.org/forum/viewtopic.php?t=777"/>'
        . '<atom:category term="f-88"/></atom:entry></atom:feed>';
    $unreadable = true;

    strictAssertSame(array(777 => array('forum' => 88)),
        RuTrackerForumIndex::parseFeed($xml, $unreadable),
        'namespace spelling does not change the Atom schema');
    strictAssertSame(false, $unreadable, 'the prefixed Atom feed is readable');
});

$suite->test('parseFeed rejects HTML challenges and non-Atom XML schemas as unreadable', function () {
    foreach (array(
        'empty response'     => '',
        'html challenge'    => '<html><body>challenge</body></html>',
        'arbitrary xml'     => '<?xml version="1.0"?><catalog><book id="1"/></catalog>',
        'rss document'      => '<?xml version="1.0"?><rss version="2.0"><channel><title>test</title></channel></rss>',
        'wrong feed namespace' => '<?xml version="1.0"?><feed xmlns="https://example.invalid/not-atom"></feed>',
    ) as $label => $xml) {
        $unreadable = false;
        $map = RuTrackerForumIndex::parseFeed($xml, $unreadable);
        strictAssertSame(array(), $map, $label . ': empty map');
        strictAssertSame(true, $unreadable, $label . ': must be reported as unreadable');
    }
});

$suite->test('parseFeed rejects the whole Atom document when any entry lacks a canonical mapping', function () {
    $valid = '<entry><link href="https://rutracker.org/forum/viewtopic.php?t=555"/>'
        . '<category term="f-42"/></entry>';
    foreach (array(
        'missing topic' => '<entry><link href="https://rutracker.org/forum/viewtopic.php"/>'
            . '<category term="f-43"/></entry>',
        'non-decimal topic' => '<entry><link href="https://rutracker.org/forum/viewtopic.php?t=1e3"/>'
            . '<category term="f-43"/></entry>',
        'missing forum' => '<entry><link href="https://rutracker.org/forum/viewtopic.php?t=556"/></entry>',
        'non-decimal forum' => '<entry><link href="https://rutracker.org/forum/viewtopic.php?t=556"/>'
            . '<category term="f-1e3"/></entry>',
        'zero topic' => '<entry><link href="https://rutracker.org/forum/viewtopic.php?t=0"/>'
            . '<category term="f-42"/></entry>',
        'negative topic' => '<entry><link href="https://rutracker.org/forum/viewtopic.php?t=-1"/>'
            . '<category term="f-42"/></entry>',
        'leading zero topic' => '<entry><link href="https://rutracker.org/forum/viewtopic.php?t=0123"/>'
            . '<category term="f-42"/></entry>',
        'plus sign topic' => '<entry><link href="https://rutracker.org/forum/viewtopic.php?t=+123"/>'
            . '<category term="f-42"/></entry>',
        'overflow topic' => '<entry><link href="https://rutracker.org/forum/viewtopic.php?t=2147483648"/>'
            . '<category term="f-42"/></entry>',
        'zero forum' => '<entry><link href="https://rutracker.org/forum/viewtopic.php?t=556"/>'
            . '<category term="f-0"/></entry>',
        'negative forum' => '<entry><link href="https://rutracker.org/forum/viewtopic.php?t=556"/>'
            . '<category term="f--1"/></entry>',
        'overflow forum' => '<entry><link href="https://rutracker.org/forum/viewtopic.php?t=556"/>'
            . '<category term="f-2147483648"/></entry>',
        'duplicate topic' => '<entry><link href="https://rutracker.org/forum/viewtopic.php?t=555"/>'
            . '<category term="f-43"/></entry>',
    ) as $label => $invalid) {
        $unreadable = false;
        $map = RuTrackerForumIndex::parseFeed(
            '<feed xmlns="http://www.w3.org/2005/Atom">' . $valid . $invalid . '</feed>',
            $unreadable
        );
        strictAssertSame(array(), $map, $label . ': no partial map is published');
        strictAssertSame(true, $unreadable, $label . ': the whole response is unreadable');
    }
});

$suite->test('parseFeed accepts canonical int32 boundary values 1 and 2147483647', function () {
    $xml = '<feed xmlns="http://www.w3.org/2005/Atom">'
        . '<entry><link href="https://rutracker.org/forum/viewtopic.php?t=1"/><category term="f-1"/></entry>'
        . '<entry><link href="https://rutracker.org/forum/viewtopic.php?t=2147483647"/><category term="f-2147483647"/></entry>'
        . '</feed>';
    $unreadable = true;
    $map = RuTrackerForumIndex::parseFeed($xml, $unreadable);
    strictAssertSame(false, $unreadable, 'feed with boundary int32 IDs is readable');
    strictAssertSame(array('forum' => 1), $map[1], 'topic 1 mapped to forum 1');
    strictAssertSame(array('forum' => 2147483647), $map[2147483647], 'topic max int32 mapped to forum max int32');
});

$suite->test('parseDump rejects malformed tor_status and invalid info_hash schemas', function () {
    foreach (array(
        'non-numeric tor_status' => json_encode(array(
            'format' => array('topic_id' => array('tor_status', 'info_hash')),
            'result' => array('123' => array('not-a-number', str_repeat('A', 40))),
        )),
        'null tor_status' => json_encode(array(
            'format' => array('topic_id' => array('tor_status', 'info_hash')),
            'result' => array('123' => array(null, str_repeat('A', 40))),
        )),
        'short info_hash' => json_encode(array(
            'format' => array('topic_id' => array('tor_status', 'info_hash')),
            'result' => array('123' => array(0, 'short-hash')),
        )),
        'non-hex info_hash' => json_encode(array(
            'format' => array('topic_id' => array('tor_status', 'info_hash')),
            'result' => array('123' => array(0, str_repeat('Z', 40))),
        )),
        'info_hash with surrounding whitespace' => json_encode(array(
            'format' => array('topic_id' => array('tor_status', 'info_hash')),
            'result' => array('123' => array(0, ' ' . str_repeat('A', 40))),
        )),
    ) as $label => $json) {
        $malformed = false;
        $rows = RuTrackerForumIndex::parseDump($json, $malformed);
        strictAssertSame(array(), $rows, $label . ': returns empty rows');
        strictAssertSame(true, $malformed, $label . ': reported as malformed');
    }
});

$suite->test('parseDump rejects non-canonical required types and rows shorter than their format', function () {
    foreach (array(
        'scientific tor_status' => array(
            'format' => array('topic_id' => array('tor_status', 'info_hash')),
            'result' => array('123' => array('1e3', str_repeat('A', 40))),
        ),
        'non-decimal topic key' => array(
            'format' => array('topic_id' => array('tor_status', 'info_hash')),
            'result' => array('not-decimal' => array(0, str_repeat('A', 40))),
        ),
        'non-string info_hash' => array(
            'format' => array('topic_id' => array('tor_status', 'info_hash')),
            'result' => array('123' => array(0, array_fill(0, 40, 'A'))),
        ),
        'short declared row' => array(
            'format' => array('topic_id' => array('tor_status', 'seeders', 'info_hash', 'leechers')),
            'result' => array('123' => array(0, 7, str_repeat('A', 40))),
        ),
    ) as $label => $document) {
        $malformed = false;
        $rows = RuTrackerForumIndex::parseDump(json_encode($document), $malformed);
        strictAssertSame(array(), $rows, $label . ': no rows are published');
        strictAssertSame(true, $malformed, $label . ': the whole response is malformed');
    }
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

$suite->test('parseDump validates canonical int32 integer domains for topic, status, and seeders', function () {
    // Valid status boundaries
    foreach (array(-2147483648, -1, 0, 2147483647) as $status) {
        $malformed = true;
        $rows = RuTrackerForumIndex::parseDump(fiDump(1, $status, str_repeat('A', 40), 10), $malformed);
        strictAssertSame(false, $malformed, 'valid status ' . $status . ' is accepted');
        strictAssertSame($status, $rows[1]['tor_status'], 'tor_status equals expected');
    }

    // Valid topic boundaries
    foreach (array(1, 2147483647) as $topicId) {
        $malformed = true;
        $rows = RuTrackerForumIndex::parseDump(fiDump($topicId, 0, str_repeat('A', 40), 10), $malformed);
        strictAssertSame(false, $malformed, 'valid topic ' . $topicId . ' is accepted');
        strictAssertTrue(isset($rows[$topicId]), 'topic is present in rows');
    }

    // Valid seeders boundaries
    foreach (array(0, 2147483647) as $seeders) {
        $malformed = true;
        $rows = RuTrackerForumIndex::parseDump(fiDump(1, 0, str_repeat('A', 40), $seeders), $malformed);
        strictAssertSame(false, $malformed, 'valid seeders ' . $seeders . ' is accepted');
        strictAssertSame($seeders, $rows[1]['seeders'], 'seeders equals expected');
    }

    // Invalid topic IDs
    foreach (array('0', '-1', '0123', '+123', '2147483648', '99999999999999999999') as $badTopic) {
        $malformed = false;
        $json = json_encode(array(
            'format' => array('topic_id' => array('tor_status', 'info_hash')),
            'result' => array($badTopic => array(0, str_repeat('A', 40))),
        ));
        $rows = RuTrackerForumIndex::parseDump($json, $malformed);
        strictAssertSame(array(), $rows, 'bad topic ' . $badTopic . ' rejected');
        strictAssertSame(true, $malformed, 'bad topic ' . $badTopic . ' marked malformed');
    }

    // Invalid statuses
    foreach (array('-2147483649', '2147483648', '-0', '01', '+1', '1.5', true, null) as $badStatus) {
        $malformed = false;
        $json = json_encode(array(
            'format' => array('topic_id' => array('tor_status', 'info_hash')),
            'result' => array('1' => array($badStatus, str_repeat('A', 40))),
        ));
        $rows = RuTrackerForumIndex::parseDump($json, $malformed);
        strictAssertSame(array(), $rows, 'bad status ' . var_export($badStatus, true) . ' rejected');
        strictAssertSame(true, $malformed, 'bad status marked malformed');
    }

    // Invalid seeders
    foreach (array(-1, '-1', '01', '+1', '2147483648', '1.5', true, null) as $badSeeders) {
        $malformed = false;
        $json = json_encode(array(
            'format' => array('topic_id' => array('tor_status', 'info_hash', 'seeders')),
            'result' => array('1' => array(0, str_repeat('A', 40), $badSeeders)),
        ));
        $rows = RuTrackerForumIndex::parseDump($json, $malformed);
        strictAssertSame(array(), $rows, 'bad seeders ' . var_export($badSeeders, true) . ' rejected');
        strictAssertSame(true, $malformed, 'bad seeders marked malformed');
    }
});

fiStateTest($suite, 'fetchDump caches by ETag and serves 304 from state', function () {
    Snoopy::reset();

    $url = RuTrackerForumIndex::DUMP_URL . '921';
    // Each call below stands in for a separate cycle (a separate PHP
    // process in production, see the memo tests below), so the
    // per-process memo is reset before each one -- otherwise the second
    // and third calls would just replay the first call's memoised
    // answer instead of exercising the ETag/304/error paths.
    Snoopy::queue($url, 200, fiDump(6868321, 0, str_repeat('F', 40), 45), array('ETag: "abc123"'));
    $fetched = RuTrackerForumIndex::fetchDump(921);
    strictAssertSame(true, $fetched['fresh'], 'a 200 is a fresh reading');
    $rows = $fetched['rows'];
    strictAssertSame(1, count($rows), 'first fetch parses one row');
    strictAssertSame(45, $rows[6868321]['seeders'], 'row seeders');
    strictAssertSame(array(), Snoopy::$rawheadersLog[0],
        'the first fetch has no ETag to condition on');
    strictAssertSame('"abc123"', RuTrackerState::load('forumindex')['etags'][921],
        'the answered ETag is persisted for the next cycle');
    $doc = RuTrackerState::load('forumdump-921');
    strictAssertSame(1, count($doc['rows']),
        'the rows live in their own per-forum document');
    strictAssertSame('"abc123"', $doc['etag'],
        'and carry the ETag that names them, published by the same write');
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
    $cached = RuTrackerForumIndex::fetchDump(921);
    strictAssertSame(false, $cached['fresh'], '304 -> the cached rows, marked as not fresh');
    strictAssertSame(1, count($cached['rows']),
        'and the rows travel back in the answer, so the caller needs no second load');
    strictAssertSame(array('If-None-Match' => '"abc123"'), Snoopy::$rawheadersLog[1],
        'the second fetch presents the persisted ETag, which is what makes the 304 possible');
    strictAssertSame(0, RuTrackerForumIndex::cachedDump(921)[6868321]['tor_status'], 'cache readable');
    strictAssertTrue(RuTrackerState::load('forumindex')['dump_touched'][921] > $aged,
        'a 304 still counts as use: the retention clock is touched, not left to expire the cache');

    strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    Snoopy::queue($url, 500, '');
    strictAssertSame(null, RuTrackerForumIndex::fetchDump(921), 'error -> null');
    strictAssertSame(45, RuTrackerForumIndex::cachedDump(921)[6868321]['seeders'], 'cache survives a fetch error');
});

fiStateTest($suite, 'fetchDump publishes no ETag hint when the dump document did not land', function ($tmp) {
    // rename(file, directory) fails even for root, while forumindex.json can
    // still be written beside it. This isolates the dump write from the hint.
    mkdir($tmp . '/forumdump-921.json', 0777, true);
    Snoopy::reset();

    $url = RuTrackerForumIndex::DUMP_URL . '921';
    Snoopy::queue($url, 200, fiDump(6868321, 0, str_repeat('F', 40), 45),
        array('ETag: "cannot-land"'));

    $answer = RuTrackerForumIndex::fetchDump(921);

    strictAssertSame(45, $answer['rows'][6868321]['seeders'],
        'the current caller may still use the rows it read from the wire');
    strictAssertTrue(!isset(RuTrackerState::load('forumindex')['etags'][921]),
        'a conditional-GET hint is published only for a dump document that reached disk');
});

fiStateTest($suite, 'fetchDump memoises per process: repeated calls for the same forum id trigger exactly one fetch', function () {
    Snoopy::reset();

    $url = RuTrackerForumIndex::DUMP_URL . '921';
    Snoopy::queue($url, 200, fiDump(6868321, 0, str_repeat('F', 40), 45), array('ETag: "abc123"'));

    // Three candidate torrents sharing forum 921 in the same cycle.
    $first = RuTrackerForumIndex::fetchDump(921);
    $second = RuTrackerForumIndex::fetchDump(921);
    $third = RuTrackerForumIndex::fetchDump(921);

    strictAssertSame($first, $second, 'second candidate served from the memo, not a fresh fetch');
    strictAssertSame($first, $third, 'third candidate served from the memo too');
    strictAssertSame(1, count(Snoopy::$requests), 'exactly one GET no matter how many candidates share the forum');
});

fiStateTest($suite, 'fetchDump memo does not serve a stale answer once cleared for a new cycle', function () {
    Snoopy::reset();

    $url = RuTrackerForumIndex::DUMP_URL . '921';
    Snoopy::queue($url, 200, fiDump(111, 0, str_repeat('A', 40)));
    RuTrackerForumIndex::fetchDump(921);
    strictAssertSame(1, count(Snoopy::$requests), 'first cycle: one fetch');

    // Next cycle: a fresh PHP process in production never carries the
    // previous process's memo, modelled here by clearing it explicitly.
    strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    Snoopy::queue($url, 304, '');
    $again = RuTrackerForumIndex::fetchDump(921);
    strictAssertSame(false, $again['fresh'], 'next cycle: a real conditional GET runs again');
    strictAssertSame(2, count(Snoopy::$requests), 'the cleared memo does not suppress the new cycle\'s fetch');
});

fiStateTest($suite, 'fetchDump prunes dump entries untouched for 30 days', function () {
    Snoopy::reset();

    // The stale dump must be seeded where production actually keeps it --
    // its own forumdump-N document. Seeding the removed legacy field made
    // cachedDump(999) answer null before any pruning ran, so the
    // assertion below held however the code behaved.
    RuTrackerState::save('forumdump-999', array('etag' => '"stale"', 'rows' =>
        array(1 => array('tor_status' => 0, 'info_hash' => str_repeat('A', 40), 'seeders' => 1))));
    RuTrackerState::save('forumindex', array(
        'etags' => array(999 => '"stale"'),
        'dump_touched' => array(999 => time() - 31 * 86400),
    ));
    strictAssertTrue(RuTrackerForumIndex::cachedDump(999) !== null,
        'the stale dump really is there before the cleanup runs');

    $url = RuTrackerForumIndex::DUMP_URL . '921';
    Snoopy::queue($url, 200, fiDump(6868321, 0, str_repeat('F', 40), 45));
    RuTrackerForumIndex::fetchDump(921);

    strictAssertSame(null, RuTrackerForumIndex::cachedDump(999), 'dump untouched for 30+ days is pruned');
    strictAssertTrue(!isset(RuTrackerState::load('forumindex')['etags'][999]),
        'and its ETag goes with it, or a later 304 would be honoured against nothing');
    strictAssertSame(45, RuTrackerForumIndex::cachedDump(921)[6868321]['seeders'], 'freshly fetched dump is kept');
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
        // First, the property the test is named for: the state lock must be
        // FREE while the request is in flight. A non-blocking flock from a
        // second descriptor answers that directly -- and it has to be asked
        // here, because a lock genuinely held across the fetch would hang the
        // suite on the update() below rather than fail it.
        $fp = @fopen($GLOBALS['fiConcurrentDir'] . '/forumindex.lock', 'c');
        strictAssertTrue($fp !== false && flock($fp, LOCK_EX | LOCK_NB),
            'the state lock must not be held across the HTTP fetch');
        flock($fp, LOCK_UN);
        fclose($fp);

        RuTrackerState::update('forumindex', function ($state) {
            $state['last_sweep'] = 999999;
            return $state;
        });
        $this->results = fiDump(6868321, 0, str_repeat('F', 40), 45);
        $this->headers = array('ETag: "concurrent"');
        return true;
    }
}

// Models request A receiving a late 304 after request B has already replaced
// both the cached body and its cheap ETag hint with a newer generation.
class ForumIndexStale304Client
{
    public $status = 304;
    public $results = '';
    public $headers = array();
    public $rawheaders = array('If-None-Match' => '"genA"');

    public function fetchComplex($url, $method = 'GET', $contentType = '', $body = '')
    {
        RuTrackerState::save('forumdump-921', array('etag' => '"genB"', 'rows' =>
            array(6868321 => array('tor_status' => 0, 'info_hash' => str_repeat('B', 40), 'seeders' => 46))));
        RuTrackerState::update('forumindex', function ($state) {
            $state['etags'][921] = '"genB"';
            $state['dump_touched'][921] = time();
            return $state;
        });
        return true;
    }
}

fiStateTest($suite, 'fetchDump does not hold the state lock across the HTTP fetch: a concurrent write during fetchComplex() survives', function ($tmp) {
    // The client below now also PROVES the lock is free at fetch time, rather
    // than only that the post-fetch writes merge. Without that, a fetch
    // genuinely wrapped in update() would not fail this test -- it would
    // deadlock the suite, since the concurrent update() inside fetchComplex()
    // would block on the same lock for ever.

    $hadConcurrentDir = array_key_exists('fiConcurrentDir', $GLOBALS);
    $savedConcurrentDir = $hadConcurrentDir ? $GLOBALS['fiConcurrentDir'] : null;
    $GLOBALS['fiConcurrentDir'] = $tmp;

    try {
        $client = new ForumIndexConcurrentWriteClient();
        $rows = RuTrackerForumIndex::fetchDump(921, $client)['rows'];
        strictAssertSame(1, count($rows), 'dump was parsed and returned');

        $state = RuTrackerState::load('forumindex');
        strictAssertSame(999999, $state['last_sweep'] ?? null,
            'the concurrent write that ran during fetchComplex() was not erased by fetchDump()\'s own write');
        strictAssertSame(45, RuTrackerState::load('forumdump-921')['rows'][6868321]['seeders'],
            'and fetchDump() still persisted its own dump on top of that');
    } finally {
        if ($hadConcurrentDir) $GLOBALS['fiConcurrentDir'] = $savedConcurrentDir;
        else unset($GLOBALS['fiConcurrentDir']);
    }
});

fiStateTest($suite, 'sweep queue and cooldown', function () {
    RuTrackerForumIndex::queueTopic(111);
    RuTrackerForumIndex::queueTopic(222);
    RuTrackerForumIndex::queueTopic(111); // dedup
    strictAssertSame(array(111, 222), RuTrackerForumIndex::takeQueuePeek(), 'queue deduped');

    strictAssertTrue(RuTrackerForumIndex::sweepAllowed(1000), 'first sweep allowed');
    RuTrackerForumIndex::markSweep(1000);
    strictAssertTrue(!RuTrackerForumIndex::sweepAllowed(1000 + 86000), 'cooldown holds');
    strictAssertTrue(RuTrackerForumIndex::sweepAllowed(1000 + 86401), 'cooldown expires');
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
    // "Dead" means REFUSED -- the dump may exist and may hold a wanted topic,
    // so the forum is unknown territory and the crawl is incomplete. The flag
    // is what says so; a bare null is the other thing entirely (see below).
    $fetcher = function ($url, &$refused = null) {
        if ($url === RuTrackerForumIndex::TREE_URL)
            return json_encode(array('result' => array('f' => array('10' => 'A', '20' => 'B'))));
        if ($url === RuTrackerForumIndex::DUMP_URL . '20')
            return fiDump(222, 0, str_repeat('B', 40));
        $refused = true;   // forum 10 turned us away
        return null;
    };
    strictAssertSame(array('resolved' => array(222 => 20), 'complete' => false),
        RuTrackerForumIndex::sweep(array(111, 222), $fetcher),
        'partial results are reported, and the REFUSED forum makes the crawl incomplete');

    // The same shape with a plain "this forum has no dump" is a COMPLETE
    // crawl: the tracker answered, there is nothing there, and nothing was
    // missed. Counting it unread is what made every real crawl incomplete and
    // markMiss() unreachable.
    $noDump = function ($url, &$refused = null) {
        if ($url === RuTrackerForumIndex::TREE_URL)
            return json_encode(array('result' => array('f' => array('10' => 'A', '20' => 'B'))));
        if ($url === RuTrackerForumIndex::DUMP_URL . '20')
            return fiDump(222, 0, str_repeat('B', 40));
        $refused = false;
        return null;
    };
    strictAssertSame(array('resolved' => array(222 => 20), 'complete' => true),
        RuTrackerForumIndex::sweep(array(111, 222), $noDump),
        'a forum that simply has no dump leaves the crawl conclusive');

    // And a 200 that is not a dump at all -- an HTML interstitial -- is
    // unknown territory again, however cheerful its status line.
    $garbage = function ($url, &$refused = null) {
        if ($url === RuTrackerForumIndex::TREE_URL)
            return json_encode(array('result' => array('f' => array('10' => 'A', '20' => 'B'))));
        if ($url === RuTrackerForumIndex::DUMP_URL . '20')
            return fiDump(222, 0, str_repeat('B', 40));
        $refused = false;
        return '<html><body>Just a moment...</body></html>';
    };
    strictAssertSame(array('resolved' => array(222 => 20), 'complete' => false),
        RuTrackerForumIndex::sweep(array(111, 222), $garbage),
        'a body that is not a dump is not a read forum, whatever its status');

    // A dump that arrived and legitimately holds no rows IS a read forum.
    $emptyDump = function ($url, &$refused = null) {
        if ($url === RuTrackerForumIndex::TREE_URL)
            return json_encode(array('result' => array('f' => array('10' => 'A', '20' => 'B'))));
        if ($url === RuTrackerForumIndex::DUMP_URL . '20')
            return fiDump(222, 0, str_repeat('B', 40));
        $refused = false;
        return json_encode(array(
            'format' => array('topic_id' => array('tor_status', 'seeders', 'info_hash')),
            'result' => array(),
        ));
    };
    strictAssertSame(array('resolved' => array(222 => 20), 'complete' => true),
        RuTrackerForumIndex::sweep(array(111, 222), $emptyDump),
        'an empty but well-formed dump is fully known territory');
});

// The three sweep tests above all supply their own fetcher, so none of them
// ever ran the one production uses -- and the rule it applied had drifted from
// fetchDump()'s. This pair pins the rule and then runs the real fetcher over it.
$suite->test('a 200 carrying nothing is an unread forum, not an empty one', function () {
    foreach (array(
        'a truncated 200'      => array(200, ''),
        'a 200 with no body'   => array(200, null),
    ) as $label => $answer) {
        $refused = false;
        strictAssertSame(null, RuTrackerForumIndex::dumpAnswer($answer[0], $answer[1], $refused),
            $label . ': there is nothing to parse');
        strictAssertSame(true, $refused,
            $label . ': and the forum was NOT read, so no absence may be concluded from it');
    }

    // The answers that are genuinely conclusive still are.
    $refused = null;
    strictAssertSame('{}', RuTrackerForumIndex::dumpAnswer(200, '{}', $refused), 'a real body comes back');
    strictAssertSame(false, $refused, 'and its forum is read');
    $refused = null;
    strictAssertSame(null, RuTrackerForumIndex::dumpAnswer(404, '', $refused), '404 carries no body');
    strictAssertSame(false, $refused, 'and means this forum has no dump, which IS an answer');
    $refused = null;
    strictAssertSame(null, RuTrackerForumIndex::dumpAnswer(403, 'go away', $refused), '403 is not a dump');
    strictAssertSame(true, $refused, 'and leaves the forum unread');
});

$suite->test('the fetcher the sweep actually uses in production applies that rule', function () {
    Snoopy::reset();
    // One forum, so the pause the default fetcher pays is charged twice only.
    Snoopy::queue(RuTrackerForumIndex::TREE_URL, 200,
        json_encode(array('result' => array('f' => array('10' => 'A')))));
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '10', 200, '');   // answered, with nothing

    // No $fetcher: this is the closure sweep() builds for itself, which no
    // other test in this file reaches.
    strictAssertSame(array('resolved' => array(), 'complete' => false),
        RuTrackerForumIndex::sweep(array(111)),
        'an empty 200 leaves the forum unread, so the crawl cannot claim to be complete');
    strictAssertSame(2, count(Snoopy::$requests), 'the tree and the one forum were really fetched');
});

$suite->test('sweep returns null, not empty array, when the forum tree itself cannot be fetched', function () {
    strictAssertSame(null, RuTrackerForumIndex::sweep(array(111), function ($url) { return null; }),
        'tree fetch failure');
    strictAssertSame(null, RuTrackerForumIndex::sweep(array(111), function ($url) { return 'not json'; }),
        'tree unparseable');
    strictAssertSame(null, RuTrackerForumIndex::sweep(array(111), function ($url) {
        return json_encode(array('result' => array())); // no 'f' key
    }), 'tree missing forum list');
    strictAssertSame(null, RuTrackerForumIndex::sweep(array(111), function ($url) {
        return json_encode(array('result' => array('f' => array()))); // empty 'f' map
    }), 'empty forum tree returns transient null');
});

$suite->test('sweep fail-closed validates forum tree keys and never issues requests to invalid or f/0 URLs', function () {
    $badTreeKeys = array(
        'not-a-forum-id',
        '0',
        '-1',
        '0123',
        '+123',
        '2147483648',
        '99999999999999999999',
    );

    foreach ($badTreeKeys as $badKey) {
        $requestedUrls = array();
        $fetcher = function ($url) use ($badKey, &$requestedUrls) {
            $requestedUrls[] = $url;
            if ($url === RuTrackerForumIndex::TREE_URL) {
                return json_encode(array('result' => array('f' => array($badKey => 'Forum Name'))));
            }
            return null;
        };

        $result = RuTrackerForumIndex::sweep(array(111), $fetcher);
        strictAssertSame(null, $result, 'bad tree key ' . $badKey . ' returns null');
        strictAssertSame(array(RuTrackerForumIndex::TREE_URL), $requestedUrls,
            'bad tree key ' . $badKey . ' issues zero dump requests (never /f/0 or malformed)');
    }

    // Valid boundary tree keys 1 and 2147483647 are accepted and requested
    $requestedUrls = array();
    $fetcher = function ($url) use (&$requestedUrls) {
        $requestedUrls[] = $url;
        if ($url === RuTrackerForumIndex::TREE_URL) {
            return json_encode(array('result' => array('f' => array('1' => 'Forum 1', '2147483647' => 'Forum Max'))));
        }
        return null; // 404 (no dump)
    };
    $result = RuTrackerForumIndex::sweep(array(111), $fetcher);
    strictAssertSame(array('resolved' => array(), 'complete' => true), $result, 'sweep completed for boundary forums');
    strictAssertSame(array(
        RuTrackerForumIndex::TREE_URL,
        RuTrackerForumIndex::DUMP_URL . '1',
        RuTrackerForumIndex::DUMP_URL . '2147483647',
    ), $requestedUrls, 'valid boundary forum IDs are requested exactly');
});

fiStateTest($suite, 'queueTopic suppresses a recent miss, allows queueing again once the window elapses, and leaves resolved topics unaffected', function () {
    $now = time();

    RuTrackerForumIndex::markMiss(111, $now);
    RuTrackerForumIndex::queueTopic(111);
    strictAssertSame(array(), RuTrackerForumIndex::takeQueuePeek(), 'a miss inside the window is not queued');

    RuTrackerForumIndex::markMiss(222, $now - 86401);
    RuTrackerForumIndex::queueTopic(222);
    strictAssertSame(array(222), RuTrackerForumIndex::takeQueuePeek(), 'a miss past the window is queueable again');

    RuTrackerForumIndex::queueTopic(333); // never missed
    strictAssertSame(array(222, 333), RuTrackerForumIndex::takeQueuePeek(), 'a topic without a miss record is unaffected');
});

fiStateTest($suite, 'markMiss prunes miss records older than the suppression window', function () {
    $now = time();
    RuTrackerForumIndex::markMiss(999, $now - 86401);
    RuTrackerForumIndex::markMiss(111, $now);

    $state = RuTrackerState::load('forumindex');
    strictAssertTrue(!isset($state['misses'][999]), 'stale miss is pruned on the next write');
    strictAssertSame(array('at' => $now, 'n' => 1), $state['misses'][111],
        'fresh miss is kept and counted as the first');
});


fiStateTest($suite, 'a repeated miss doubles its suppression window, so a deleted topic costs ever fewer crawls', function () {
    $now = time();

        // Two misses, the latest one cooldown ago: a first miss would be
        // queueable again by now, a second is suppressed for two cooldowns.
        RuTrackerForumIndex::markMiss(111, $now - 86401);
        RuTrackerForumIndex::markMiss(111, $now - 86401);
        RuTrackerForumIndex::queueTopic(111);
        strictAssertSame(array(), RuTrackerForumIndex::takeQueuePeek(),
            'the second miss is still suppressed after one cooldown');

        // The same twice-missed topic, its record two cooldowns old.
        RuTrackerForumIndex::markMiss(222, $now - 172801);
        RuTrackerForumIndex::markMiss(222, $now - 172801);
        RuTrackerForumIndex::queueTopic(222);
        strictAssertSame(array(222), RuTrackerForumIndex::takeQueuePeek(),
            'and queueable again once the doubled window elapses');

    $state = RuTrackerState::load('forumindex');
    strictAssertSame(2, $state['misses'][111]['n'], 'the record carries the miss count');
});

fiStateTest($suite, 'an oversized persisted miss count is capped before the backoff shift', function () {
    RuTrackerState::save('forumindex', array('misses' => array(
        111 => array('at' => time(), 'n' => 64),
    )));

    RuTrackerForumIndex::queueTopic(111);
    strictAssertSame(array(), RuTrackerForumIndex::takeQueuePeek(),
        'a fresh miss at the cap stays suppressed instead of overflowing the shift');
});

fiStateTest($suite, 'a miss recorded before the counter existed still suppresses for one window', function () {
    $now = time();
    // The pre-counter shape: a bare timestamp.
    RuTrackerState::save('forumindex', array('misses' => array(111 => $now, 222 => $now - 86401)));

    RuTrackerForumIndex::queueTopic(111);
    strictAssertSame(array(), RuTrackerForumIndex::takeQueuePeek(), 'a fresh legacy miss suppresses');
    RuTrackerForumIndex::queueTopic(222);
    strictAssertSame(array(222), RuTrackerForumIndex::takeQueuePeek(), 'an expired legacy miss does not');
});

$suite->test('only 404 and 410 mean "this forum has no dump"; every other non-200 is the tracker turning us away', function () {
    // The classifier used to live inside the default fetcher, where no test
    // could reach it, and it named only transport failures, 429 and 5xx as
    // refusals. So 401, 403, 408 -- and any other 4xx -- read as "a forum that
    // simply carries no dump": fully known territory that does not count as
    // unread. A crawl could then finish with complete=true and record a miss
    // for every wanted topic, on forums it never actually read. The static API
    // is documented to answer 403 to a User-Agent it dislikes (design 2.9), so
    // one tightened filter on the tracker's side is all it takes.
    foreach (array(404, 410) as $status)
        strictAssertSame(false, RuTrackerForumIndex::dumpRefused($status),
            $status . ' is the ordinary answer for a forum with no dump');
    foreach (array(0, -1, 28, 400, 401, 403, 408, 429, 451, 500, 502, 503) as $status)
        strictAssertSame(true, RuTrackerForumIndex::dumpRefused($status),
            $status . ' is unknown territory and must count as a refusal');
    strictAssertSame(false, RuTrackerForumIndex::dumpRefused(200),
        'a served dump is not a refusal');
});

$suite->test('a forum answering "no dump" breaks a run of refusals, because the tracker did answer', function () {
    $tree = json_encode(array('result' => array('f' => array_fill_keys(range(1, 20), 'forum'))));

    // ABORT-1 refusals, then a 404, over and over. The counter's contract is
    // "N refusals IN A ROW", but the 404 branch used to `continue` without
    // resetting it -- only a parsed dump did -- so an interrupted run kept
    // accumulating and aborted a crawl the tracker was answering.
    $fetches = 0;
    $result = RuTrackerForumIndex::sweep(array(999999), function ($url, &$refused = null) use ($tree, &$fetches) {
        if (strpos($url, 'cat_forum_tree') !== false) return $tree;
        $fetches++;
        $refused = ($fetches % RuTrackerForumIndex::SWEEP_FAILURE_ABORT) !== 0;
        return null;
    });
    strictAssertTrue(is_array($result),
        'a 404 is an answer, so it resets the refusal run and the crawl is not aborted');
    strictAssertSame(20, $fetches, 'every forum in the tree was still visited');
});

$suite->test('a run of consecutive fetch failures aborts the sweep as transient instead of hammering on', function () {
    $tree = json_encode(array('result' => array('f' => array_fill_keys(range(1, 20), 'forum'))));

    // Every dump fetch is REFUSED: the crawl must stop at the abort threshold
    // and conclude nothing, not walk all 20 forums.
    $fetches = 0;
    $result = RuTrackerForumIndex::sweep(array(111), function ($url, &$refused = null) use ($tree, &$fetches) {
        if (strpos($url, 'cat_forum_tree') !== false) return $tree;
        $fetches++;
        $refused = true;
        return null;
    });
    strictAssertSame(null, $result, 'a refusing tracker ends the crawl with nothing concluded');
    strictAssertSame(RuTrackerForumIndex::SWEEP_FAILURE_ABORT, $fetches,
        'the crawl stopped at the abort threshold, not at the end of the tree');

    // Scattered refusals reset the run: one short of the threshold, then a
    // success, over and over -- the crawl must complete.
    $fetches = 0;
    $result = RuTrackerForumIndex::sweep(array(999999), function ($url, &$refused = null) use ($tree, &$fetches) {
        if (strpos($url, 'cat_forum_tree') !== false) return $tree;
        $fetches++;
        // A well-formed, empty dump: an ANSWER, which is what resets the run.
        // (An `format => []` envelope would not be one -- it parses to nothing
        // and now counts as unread, which is the point of the malformed guard.)
        if ($fetches % RuTrackerForumIndex::SWEEP_FAILURE_ABORT === 0)
            return json_encode(array(
                'format' => array('topic_id' => array('tor_status', 'seeders', 'info_hash')),
                'result' => array(),
            ));
        $refused = true;
        return null;
    });
    strictAssertSame(array('resolved' => array(), 'complete' => false), $result,
        'scattered refusals do not abort the crawl -- but they DO mark it incomplete');
    strictAssertSame(20, $fetches, 'every forum was still visited');

    // Most of RuTracker's ~1500-forum tree is categories and archives that
    // carry no torrent dump at all and answer 404. Counting those as
    // refusals aborted every crawl a handful of forums in, which is to say
    // the whole fallback resolution never ran.
    $fetches = 0;
    $result = RuTrackerForumIndex::sweep(array(111), function ($url, &$refused = null) use ($tree, &$fetches) {
        if (strpos($url, 'cat_forum_tree') !== false) return $tree;
        $fetches++;
        $refused = false;   // a plain 404: this forum simply has no dump
        return null;
    });
    strictAssertSame(20, $fetches, 'a tree full of dumpless forums is walked to the end');
    strictAssertSame(true, $result['complete'],
        'and the crawl is CONCLUSIVE: every forum answered, none of them has a dump, nothing was missed');
});

// --- runCrawl(): the crawl transaction, testable end to end ------------------

fiStateTest($suite, 'runCrawl leaves the queue intact when the fleet scan fails', function () {
    RuTrackerForumIndex::queueTopic(555);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', false, false, array()); // the scan itself fails

    strictAssertSame(null, RuTrackerForumIndex::runCrawl(time(), function ($wanted) {
        throw new RuntimeException('the sweep must never start');
    }), 'nothing to log');
    strictAssertSame(array(555), RuTrackerForumIndex::takeQueuePeek(),
        'the queue survives a failed scan without needing to be reconstructed');
    strictAssertTrue(RuTrackerForumIndex::sweepAllowed(time()),
        'the cooldown is not marked for a crawl that never ran');
});

fiStateTest($suite, 'runCrawl requeues the whole wanted set when the crawl fails, and records misses only when it completes', function () {
    // Each leg is a separate cycle: runCrawl() claims the crawl window as it
    // starts, so without moving past the cooldown the later legs would
    // (correctly) stand down instead of exercising their own branch.
    $cycle = 2 * 86400;
    $at = time();
    // Failed crawl (sweeper answers null): both the queued topic and the
    // fleet-scanned one must be requeued, and no miss recorded.
    RuTrackerForumIndex::queueTopic(555);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false,
        array(str_repeat('A', 40), '777', ''));   // hash, chk-topic, chk-forum unknown
    $line = RuTrackerForumIndex::runCrawl($at, function ($wanted) { return null; });
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
    $at += $cycle;
    $line = RuTrackerForumIndex::runCrawl($at, function ($wanted) {
        throw new RuntimeException('boom');
    });
    strictAssertSame('wanted 2, crawl failed: boom', $line, 'the exception is named');

    // Completed crawl: 777 resolves and is written back to its hash, 555
    // (queued, no torrent carries it) is recorded as a miss, not requeued.
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), '777', ''));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('777', ''));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    $at += $cycle;
    $line = RuTrackerForumIndex::runCrawl($at, function ($wanted) {
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
});

// spawnCrawl() is the composition BOTH entry points call -- update.php's hourly
// pass and batch_check.php behind a "check" click -- yet nothing exercised it:
// its two guards were tested one level down, in sweepAllowed() and
// crawlWanted(), and the function that combines them was not. Only the two
// stand-down branches are testable here; the third really forks a process.
fiStateTest($suite, 'spawnCrawl stands down when either of its two guards says no', function () {
    // The cooldown is fresh: no crawl, whatever the fleet wants.
    RuTrackerState::save('forumindex', array('last_sweep' => time()));
    RuTrackerForumIndex::queueTopic(555);
    rXMLRPCRequest::reset();
    strictAssertSame(false, RuTrackerForumIndex::spawnCrawl(),
        'a crawl inside the cooldown is not launched -- this is what stops one crawl per click');
    strictAssertSame(0, count(rXMLRPCRequest::$requests),
        'and the cooldown is judged before the fleet is even read');

    // Cooldown clear, but nothing wants resolving.
    RuTrackerState::save('forumindex', array());
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array());
    strictAssertSame(false, RuTrackerForumIndex::spawnCrawl(),
        'a tracker-wide crawl with nothing to look for is not launched either');

    // Cooldown clear and the fleet cannot be read: "nothing to do" is not
    // the same answer as "could not tell", and neither may spawn.
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', false, false, array());
    strictAssertSame(false, RuTrackerForumIndex::spawnCrawl(),
        'an unreadable fleet is not a reason to crawl the whole tracker');
});

fiStateTest($suite, 'spawnCrawl hands one exact detached command to the injected launcher', function () {
    $hadExternals = array_key_exists('pathToExternals', $GLOBALS);
    $savedExternals = $hadExternals ? $GLOBALS['pathToExternals'] : null;
    $hadForbid = array_key_exists('forbidUserSettings', $GLOBALS);
    $savedForbid = $hadForbid ? $GLOBALS['forbidUserSettings'] : null;
    $hadRemoteUser = array_key_exists('REMOTE_USER', $_SERVER);
    $savedRemoteUser = $hadRemoteUser ? $_SERVER['REMOTE_USER'] : null;
    try {
        RuTrackerState::save('forumindex', array());
        RuTrackerForumIndex::queueTopic(777);
        $php = "/opt/PHP tools/php's";
        $GLOBALS['pathToExternals'] = array('php' => $php);
        $GLOBALS['forbidUserSettings'] = false;
        $_SERVER['REMOTE_USER'] = 'Crawler User';
        strictSetPrivateStatic('User', 'userLoginInstance', null);

        $commands = array();
        $accepted = 'accepted-by-launcher';
        $result = RuTrackerForumIndex::spawnCrawl(function ($cmd) use (&$commands, $accepted) {
            $commands[] = $cmd;
            return $accepted;
        });

        strictAssertSame($accepted, $result, 'the launcher acceptance result is returned unchanged');
        strictAssertSame(1, count($commands), 'the launcher is invoked exactly once');
        $expected = escapeshellarg($php) . ' -f '
            . escapeshellarg(testFindRepoRoot() . '/plugins/rutracker_check/forumcrawl.php')
            . ' ' . escapeshellarg('crawler_user') . ' > /dev/null 2>&1 &';
        strictAssertSame($expected, $commands[0], 'binary, script, user and redirections are exact');
    } finally {
        if ($hadExternals) $GLOBALS['pathToExternals'] = $savedExternals;
        else unset($GLOBALS['pathToExternals']);
        if ($hadForbid) $GLOBALS['forbidUserSettings'] = $savedForbid;
        else unset($GLOBALS['forbidUserSettings']);
        if ($hadRemoteUser) $_SERVER['REMOTE_USER'] = $savedRemoteUser;
        else unset($_SERVER['REMOTE_USER']);
        strictSetPrivateStatic('User', 'userLoginInstance', null);
    }
});

fiStateTest($suite, 'spawnCrawl reports an injected launcher refusal', function () {
    ruTrackerChecker::reset();
    RuTrackerState::save('forumindex', array());
    RuTrackerForumIndex::queueTopic(778);
    $calls = 0;
    $result = RuTrackerForumIndex::spawnCrawl(function ($cmd) use (&$calls) {
        $calls++;
        return false;
    });

    strictAssertSame(false, $result, 'a refused detached command is not reported as launched');
    strictAssertSame(1, $calls, 'the refused command is attempted exactly once');
    strictAssertOneLogMatching(
        ruTrackerChecker::$logs,
        'detached crawl command was not accepted by the shell',
        'a refusal after both spawn guards is visible without misreporting ordinary no-work guard exits'
    );
});

// spawnCrawl()'s cooldown check runs in the PARENT and the window is recorded
// by the detached CHILD, seconds later, after PHP start-up and a fleet-wide
// multicall. Several manual "check" clicks all pass the parent's check inside
// that gap and each launches a full tracker-wide crawl -- exactly what the 24h
// cooldown exists to prevent. So the window is judged and taken in one locked
// write, and the loser stands down with its wanted set intact.
// What this proves is the OUTCOME of the race, not the race: the two crawls run
// one after the other in one process, so nothing here interleaves. That is the
// right division of labour rather than a gap -- markSweep()'s claim is taken
// through RuTrackerState::update(), whose mutual exclusion is proven by two
// real processes contending on a barrier in StateTest. This case pins what the
// LOSER does once the claim has gone the other way, which no amount of
// concurrency would show more clearly.
fiStateTest($suite, 'a crawl that finds the window already claimed stands down and gives its wanted set back', function () {
    $at = time();
    RuTrackerForumIndex::queueTopic(555);

    // The winner claims the window and crawls.
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), '777', ''));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('777', ''));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    $swept = 0;
    $line = RuTrackerForumIndex::runCrawl($at, function ($wanted) use (&$swept) {
        $swept++;
        return array('resolved' => array(777 => 1106), 'complete' => true);
    });
    strictAssertSame('wanted 2, resolved 1', $line, 'the first crawl runs');
    strictAssertSame(1, $swept, 'and sweeps exactly once');

    // The second child started before the first recorded anything, so its
    // parent's sweepAllowed() said yes. It must still not crawl.
    RuTrackerForumIndex::queueTopic(999);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('B', 40), '888', ''));
    $line = RuTrackerForumIndex::runCrawl($at + 1, function ($wanted) use (&$swept) {
        $swept++;
        return array('resolved' => array(), 'complete' => true);
    });
    strictAssertSame(1, $swept, 'the loser does not sweep the tracker a second time');
    strictAssertSame('wanted 2, another crawl already holds this window', $line,
        'and says so for the log');

    // Its wanted set is not lost while the first crawl owns the window.
    $queued = RuTrackerForumIndex::takeQueuePeek();
    sort($queued);
    strictAssertSame(array(888, 999), $queued, 'everything the loser took is back in the queue');
    // The winner did record a miss for the topic it looked for and did
    // not find (555). The loser must add none of its own: a crawl that
    // never ran proves nothing about any topic.
    $misses = RuTrackerState::load('forumindex')['misses'] ?? array();
    strictAssertSame(array(555), array_keys($misses),
        'only the crawl that actually ran may mark anything missed');
});

// A topic that MOVED forum is the one case chk-forum gets wrong, and the
// handler's own comment promises the crawl fixes it ("a sweep that finds the
// topic elsewhere overwrites chk-forum with the new one"). It could not:
// the fleet scan skipped every torrent whose chk-forum was already set, so the
// crawl resolved the topic, had no hash to write it to, booked it as resolved
// and recorded no miss -- leaving the stale id standing for ever while a full
// tracker crawl ran every cooldown to achieve nothing.
fiStateTest($suite, 'a queued topic may overwrite the stale forum id it was queued for', function () {
    $hash = str_repeat('A', 40);
    RuTrackerForumIndex::queueTopic(777);
    rXMLRPCRequest::reset();
    // The torrent already HAS a forum -- the wrong one, 1106.
    rXMLRPCRequest::queue('d.multicall', true, false, array($hash, '777', '1106'));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('777', '1106'));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());

    $line = RuTrackerForumIndex::runCrawl(time(), function ($wanted) {
        strictAssertSame(array(777), $wanted, 'the queued topic is looked for');
        return array('resolved' => array(777 => 2222), 'complete' => true);
    });

    strictAssertSame('wanted 1, resolved 1', $line, 'the crawl reports the resolution');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom');
    strictAssertSame(1, count($writes), 'and it is actually written somewhere');
    strictAssertSame(array($hash, 'chk-forum', '2222'), $writes[0]['commands'][0]->params,
        'the new forum replaces the stale one on the torrent that wanted it');

    // A topic nobody asked about keeps its cached id: the scan stays
    // bounded to what needs resolving.
    RuTrackerState::save('forumindex', array());
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($hash, '777', '2222'));
    strictAssertSame(array(), RuTrackerForumIndex::topicsAwaitingForum(),
        'an unqueried torrent with a known forum is not in the scan at all');
});

// End to end, with sweep()'s REAL answer rather than a hand-written stub: a
// tree shaped like RuTracker's, where most forums simply have no dump. Every
// other runCrawl test feeds `complete` in by hand, which is how markMiss()
// came to be dead code in production while the suite stayed green -- a real
// tree always came back incomplete, so the branch never ran.
fiStateTest($suite, 'a topic absent from a real-shaped tree is marked missed, not requeued for ever', function () {
    $forums = array();
    for ($i = 1; $i <= 12; $i++) $forums[(string) (100 + $i)] = 'forum ' . $i;
    $tree = json_encode(array('result' => array('f' => $forums)));

    rXMLRPCRequest::reset();
    // The fleet wants topic 777, and no forum carries it.
    rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), '777', ''));

    $line = RuTrackerForumIndex::runCrawl(time(), function ($wanted) use ($tree) {
        return RuTrackerForumIndex::sweep($wanted, function ($url, &$refused = null) use ($tree) {
            if (strpos($url, 'cat_forum_tree') !== false) return $tree;
            // One forum in twelve has a dump, and it does not hold 777 --
            // the rest are the categories and archives that answer 404.
            if (substr($url, -3) === '105')
                return fiDump(222, 0, str_repeat('B', 40));
            $refused = false;
            return null;
        });
    });

    strictAssertSame('wanted 1, resolved 0', $line, 'the crawl completed and resolved nothing');
    strictAssertTrue(isset(RuTrackerState::load('forumindex')['misses'][777]),
        'the topic no complete crawl could find is recorded as missed');
    strictAssertSame(array(), RuTrackerForumIndex::takeQueuePeek(),
        'and it is NOT requeued, which is what stopped it re-crawling the tree every cooldown');

    // The contrast: one REFUSED forum makes the same crawl inconclusive,
    // so the topic goes back in the queue and nothing is marked.
    RuTrackerState::save('forumindex', array());
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), '777', ''));
    $line = RuTrackerForumIndex::runCrawl(time(), function ($wanted) use ($tree) {
        return RuTrackerForumIndex::sweep($wanted, function ($url, &$refused = null) use ($tree) {
            if (strpos($url, 'cat_forum_tree') !== false) return $tree;
            $refused = (substr($url, -3) === '107');
            return null;
        });
    });

    strictAssertSame('wanted 1, resolved 0, 1 requeued: some dumps went unread', $line,
        'a refusal leaves it inconclusive');
    strictAssertSame(array(), RuTrackerState::load('forumindex')['misses'] ?? array(),
        'an inconclusive crawl proves nothing about the topic');
    strictAssertSame(array(777), RuTrackerForumIndex::takeQueuePeek(), 'so it is requeued instead');
});

fiStateTest($suite, 'runCrawl treats an incomplete crawl as inconclusive: written back, but nothing marked missed', function () {
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array(
        str_repeat('A', 40), '777', '',
        str_repeat('C', 40), '555', ''));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('777', ''));
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
});

fiStateTest($suite, 'runCrawl marks the cooldown before crawling, so a mid-crawl death is not retried every cycle', function () {
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), '777', ''));
    $when = time();
    // The reading is carried OUT of the sweeper, not asserted inside it:
    // runCrawl() catches every Throwable a sweeper raises and turns it
    // into a log line, so an assertion in there can never fail the test.
    $markedDuringCrawl = null;
    RuTrackerForumIndex::runCrawl($when, function ($wanted) use ($when, &$markedDuringCrawl) {
        $markedDuringCrawl = !RuTrackerForumIndex::sweepAllowed($when);
        return null;
    });
    strictAssertSame(true, $markedDuringCrawl,
        'the cooldown is already marked while the crawl is still running');
});

fiStateTest($suite, 'runCrawl keeps its explicit queue durable until the sweep finishes', function () {
    $hash = str_repeat('A', 40);
    RuTrackerForumIndex::queueTopic(777);
    rXMLRPCRequest::reset();
    // A moved topic already has a forum, so only the explicit queue can
    // make a later crawl look at it again after this process dies.
    rXMLRPCRequest::queue('d.multicall', true, false, array($hash, '777', '1106'));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('777', '1106'));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());

    $duringSweep = null;
    RuTrackerForumIndex::runCrawl(time(), function ($wanted) use (&$duringSweep) {
        $duringSweep = RuTrackerForumIndex::takeQueuePeek();
        return array('resolved' => array(777 => 2222), 'complete' => true);
    });

    strictAssertSame(array(777), $duringSweep,
        'a process killed inside the sweep leaves the only durable trigger intact');
    strictAssertSame(array(), RuTrackerForumIndex::takeQueuePeek(),
        'a normally completed sweep retires the queue item it handled');
});

fiStateTest($suite, 'runCrawl does not retire a same-topic request queued during its sweep', function () {
    $hash = str_repeat('A', 40);
    RuTrackerForumIndex::queueTopic(777);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($hash, '777', '1106'));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('777', '1106'));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());

    RuTrackerForumIndex::runCrawl(time(), function ($wanted) {
        // This represents another request discovering that the same
        // topic needs a fresh crawl while the current one is in flight.
        RuTrackerForumIndex::queueTopic(777);
        return array('resolved' => array(777 => 2222), 'complete' => true);
    });

    strictAssertSame(array(777), RuTrackerForumIndex::takeQueuePeek(),
        'completion removes only the queue generation it actually observed');
});

fiStateTest($suite, 'a stale crawl cannot overwrite a forum mapping corrected while it was sweeping', function () {
    $hash = str_repeat('A', 40);
    RuTrackerForumIndex::queueTopic(777);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($hash, '777', '1106'));
    // The feed wins while the detached crawl is away walking the tree.
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    // The crawl re-reads under the shared mapping lock after the feed.
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('777', '2222'));

    RuTrackerForumIndex::runCrawl(time(), function ($wanted) use ($hash) {
        $feed = new rXMLRPCRequest(new rXMLRPCCommand(
            getCmd('d.set_custom'), array($hash, 'chk-forum', '2222')));
        $feed->important = false;
        strictAssertTrue($feed->success(), 'the newer feed mapping lands during the crawl');
        return array('resolved' => array(777 => 1106), 'complete' => true);
    });

    $writes = rXMLRPCRequest::requestsFor('d.set_custom');
    strictAssertSame(1, count($writes),
        'the crawl observes that its snapshot lost the race and emits no stale write');
    strictAssertSame(array($hash, 'chk-forum', '2222'), $writes[0]['commands'][0]->params,
        'the newer authoritative mapping is the one left standing');
});

fiStateTest($suite, 'a partial multi-hash forum writeback keeps the topic queued', function () {
    $first = str_repeat('A', 40);
    $second = str_repeat('B', 40);
    RuTrackerForumIndex::queueTopic(777);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array(
        $first, '777', '',
        $second, '777', '',
    ));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('777', ''));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('777', ''));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    rXMLRPCRequest::queue('d.set_custom', false, false, array());

    $line = RuTrackerForumIndex::runCrawl(time(), function ($wanted) {
        return array('resolved' => array(777 => 2222), 'complete' => true);
    });

    strictAssertSame('wanted 1, resolved 0', $line,
        'a topic is not counted resolved while one torrent still lacks its forum');
    strictAssertSame(array(777), RuTrackerForumIndex::takeQueuePeek(),
        'the failed hash keeps the topic obligation durable for the next crawl');
});

fiStateTest($suite, 'a crawl that loses the cooldown claim does not bump an existing queue generation', function () {
    $at = time();
    $hash = str_repeat('A', 40);
    RuTrackerForumIndex::queueTopic(777);
    strictAssertTrue(RuTrackerForumIndex::markSweep($at), 'another crawler owns this window');
    $before = RuTrackerState::load('forumindex')['queue_versions'][777] ?? null;
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($hash, '777', '1106'));

    $line = RuTrackerForumIndex::runCrawl($at, function ($wanted) {
        throw new RuntimeException('the losing crawler must never sweep');
    });

    strictAssertSame('wanted 1, another crawl already holds this window', $line,
        'the second crawler stands down');
    strictAssertSame($before, RuTrackerState::load('forumindex')['queue_versions'][777] ?? null,
        'standing down preserves the generation the winning crawl is already handling');
});

// The fleet scan remains the fallback trigger for legacy or otherwise
// unqueued topics whose forum is still unknown.
fiStateTest($suite, 'crawlWanted sees unqueued work in the fleet', function () {
    // Empty queue, but the fleet still carries a topic with no forum.
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), '777', ''));
    strictAssertTrue(RuTrackerForumIndex::crawlWanted(), 'the fleet scan finds work absent from the queue');

    // Nothing queued and nothing outstanding: no crawl needed.
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), '777', '1106'));
    strictAssertTrue(!RuTrackerForumIndex::crawlWanted(), 'a fleet with every forum known wants nothing');

    // The queue alone is enough, without any fleet read.
    rXMLRPCRequest::reset();
    RuTrackerForumIndex::queueTopic(555);
    strictAssertTrue(RuTrackerForumIndex::crawlWanted(), 'a queued topic is work by itself');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.multicall')),
        'and costs no fleet scan to notice');

    // A failed fleet scan is not a claim that there is nothing to do.
    rXMLRPCRequest::reset();
    RuTrackerState::save('forumindex', array());
    rXMLRPCRequest::queue('d.multicall', false, false, array());
    strictAssertTrue(!RuTrackerForumIndex::crawlWanted(), 'an unreadable fleet answers "nothing to start"');
});

// A 304 is only an answer while the cached document is still there. If it
// went missing, honouring "unchanged" would keep the forum unusable for as
// long as the tracker's ETag holds -- and nothing would ever ask again.
fiStateTest($suite, 'a 304 whose cached dump has vanished drops the ETag instead of trusting it', function () {
    Snoopy::reset();

    // An ETag remembered for a forum whose document is gone.
    RuTrackerState::save('forumindex', array(
        'etags' => array(921 => '"abc123"'),
        'dump_touched' => array(921 => time()),
    ));
    $url = RuTrackerForumIndex::DUMP_URL . '921';
    Snoopy::queue($url, 304, '');

    strictAssertSame(null, RuTrackerForumIndex::fetchDump(921),
        'a 304 with nothing cached is a miss, not "unchanged"');
    strictAssertTrue(!isset(RuTrackerState::load('forumindex')['etags'][921]),
        'and the ETag goes, so the next cycle asks unconditionally');

    // Which it then does: a full GET, and the forum works again.
    strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    Snoopy::queue($url, 200, fiDump(6868321, 0, str_repeat('F', 40), 45), array('ETag: "def456"'));
    strictAssertSame(1, count(RuTrackerForumIndex::fetchDump(921)['rows']), 'the refetch repopulates the cache');
    strictAssertSame(array(), Snoopy::$rawheadersLog[1], 'and it carried no If-None-Match');
});

// The rows and the ETag hint in forumindex.json are two separate writes, so a
// batch_check.php a click spawned and the hourly cycle -- neither of which
// excludes the other -- can interleave and leave the newer ETag standing over
// the older body. Confirmed by a 304, that pairing would serve the wrong
// generation for as long as the tracker's ETag holds, and the retention touch
// would keep it from ever being pruned.
fiStateTest($suite, 'a 304 whose cached dump is not the generation the ETag names is refused', function () {
    Snoopy::reset();

    // What the interleaving leaves behind: body from generation A, hint
    // from generation B.
    RuTrackerState::save('forumdump-921', array('etag' => '"genA"', 'rows' =>
        array(6868321 => array('tor_status' => 0, 'info_hash' => str_repeat('A', 40), 'seeders' => 45))));
    RuTrackerState::save('forumindex', array(
        'etags' => array(921 => '"genB"'),
        'dump_touched' => array(921 => time()),
    ));
    $url = RuTrackerForumIndex::DUMP_URL . '921';
    Snoopy::queue($url, 304, '');

    strictAssertSame(null, RuTrackerForumIndex::fetchDump(921),
        'a 304 against an ETag the cached rows do not carry is a miss, not "unchanged"');
    strictAssertTrue(!isset(RuTrackerState::load('forumindex')['etags'][921]),
        'and the disagreeing hint goes, so the next cycle asks unconditionally');

    // The refetch heals it, and body and ETag now agree.
    strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    Snoopy::queue($url, 200, fiDump(6868321, 0, str_repeat('F', 40), 45), array('ETag: "genC"'));
    strictAssertSame(1, count(RuTrackerForumIndex::fetchDump(921)['rows']), 'the refetch repopulates the cache');
    $doc = RuTrackerState::load('forumdump-921');
    strictAssertSame('"genC"', $doc['etag'], 'the rows carry the ETag that names them');
    strictAssertSame('"genC"', RuTrackerState::load('forumindex')['etags'][921], 'and the hint agrees again');

    // An agreeing pair is still served from cache, as it must be --
    // otherwise the conditional GET would buy nothing.
    strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    Snoopy::queue($url, 304, '');
    strictAssertSame(false, RuTrackerForumIndex::fetchDump(921)['fresh'],
        'a 304 whose ETag the cached rows do carry is honoured');
});

fiStateTest($suite, 'a late 304 may drop only the ETag it sent, never a newer concurrent hint', function () {
    RuTrackerState::save('forumdump-921', array('etag' => '"genA"', 'rows' =>
        array(6868321 => array('tor_status' => 0, 'info_hash' => str_repeat('A', 40), 'seeders' => 45))));
    RuTrackerState::save('forumindex', array(
        'etags' => array(921 => '"genA"'),
        'dump_touched' => array(921 => time()),
    ));

    strictAssertSame(null, RuTrackerForumIndex::fetchDump(921, new ForumIndexStale304Client()),
        'request A cannot serve request B\'s body as the answer to A\'s 304');
    strictAssertSame('"genB"', RuTrackerState::load('forumindex')['etags'][921] ?? null,
        'request A removes its own stale hint only when that same hint still stands');
    strictAssertSame(46, RuTrackerState::load('forumdump-921')['rows'][6868321]['seeders'],
        'the newer cached document survives alongside its hint');
});

// A topic no completed crawl can ever find -- a deleted one, exactly what the
// backoff exists for -- was re-added from the fleet scan every time, so every
// sweep became a full crawl of the tracker for ever.
fiStateTest($suite, 'the fleet half of the wanted set honours the miss backoff', function () {
    $now = time();
    RuTrackerForumIndex::markMiss(777, $now);   // a completed crawl just failed to find it
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), '777', ''));

    strictAssertSame(null, RuTrackerForumIndex::runCrawl($now, function ($wanted) {
        throw new RuntimeException('a suppressed topic must not start a crawl');
    }), 'nothing wanted, so no crawl');

    // Past its window it is wanted again, so a moved topic still gets
    // another look.
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), '777', ''));
    $seen = null;
    RuTrackerForumIndex::runCrawl($now + 86401, function ($wanted) use (&$seen) {
        $seen = $wanted;
        return array('resolved' => array(), 'complete' => true);
    });
    strictAssertSame(array(777), $seen, 'once the window elapses the topic is crawled again');
});

// A resolution nobody could write down is not a resolution: the crawl that
// produced it would be spent for nothing and the topic left in limbo.
fiStateTest($suite, 'a resolution whose write-back fails is requeued, not counted', function () {
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), '777', ''));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('777', ''));
    rXMLRPCRequest::queue('d.set_custom', false, false, array());   // the write-back fails

    $line = RuTrackerForumIndex::runCrawl(time(), function ($wanted) {
        return array('resolved' => array(777 => 1106), 'complete' => true);
    });
    strictAssertSame('wanted 1, resolved 0', $line, 'it does not count as resolved');
    strictAssertSame(array(777), RuTrackerForumIndex::takeQueuePeek(),
        'and it goes back in the queue instead of being marked missed');
    strictAssertSame(array(), RuTrackerState::load('forumindex')['misses'] ?? array(),
        'a failed write is not evidence the topic is absent');
});

fiStateTest($suite, 'a resolved topic forgets an old miss only after its forum write-back lands', function () {
    $now = time();
    RuTrackerState::save('forumindex', array(
        'queue' => array(777, 888),
        'misses' => array(
            777 => array('at' => $now - 86401, 'n' => 1),
            888 => array('at' => $now - 86401, 'n' => 1),
        ),
    ));
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array(
        str_repeat('A', 40), '777', '',
        str_repeat('B', 40), '888', '',
    ));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('777', ''));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('888', ''));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    rXMLRPCRequest::queue('d.set_custom', false, false, array());

    RuTrackerForumIndex::runCrawl($now, function ($wanted) {
        return array('resolved' => array(777 => 1106, 888 => 1107), 'complete' => true);
    });

    $state = RuTrackerState::load('forumindex');
    strictAssertTrue(!isset($state['misses'][777]),
        'a successful write-back proves the old miss obsolete');
    strictAssertSame(array('at' => $now - 86401, 'n' => 1), $state['misses'][888] ?? null,
        'a failed write-back preserves the old miss record');
    strictAssertSame(array(888), RuTrackerForumIndex::takeQueuePeek(),
        'the topic whose forum did not land is still requeued');
});

fiStateTest($suite, 'an empty forum dump is an answer, not a failure -- and it is cached like any other', function () {
    // A forum whose dump lists nothing is a FACT about that forum: it is what
    // proves a topic is not in it. Folding zero rows into "unavailable" meant
    // the answer could never be cached, never confirm an absence, and cost a
    // full unconditional refetch every single cycle.
    $empty = json_encode(array(
        'format' => array('topic_id' => array('tor_status', 'seeders', 'info_hash')),
        'result' => array(),
    ));
    Snoopy::reset();
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '921', 200, $empty, array('ETag: "e0"'));

    $answer = RuTrackerForumIndex::fetchDump(921);
    strictAssertTrue(is_array($answer), 'an empty dump is an answer');
    strictAssertSame(array(), $answer['rows'], 'and the answer is: this forum lists nothing');
    strictAssertSame(true, $answer['fresh'], 'read off the wire');
    strictAssertSame(array(), RuTrackerForumIndex::cachedDump(921),
        'it is cached, so the next conditional GET has a generation to confirm');

    // And the ETag it was stored under is what the next fetch presents.
    Snoopy::reset();
    strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '921', 304, '');
    $cached = RuTrackerForumIndex::fetchDump(921);
    strictAssertSame(array('If-None-Match' => '"e0"'), Snoopy::$rawheadersLog[0],
        'the empty dump was cached under its own ETag');
    strictAssertSame(array(), $cached['rows'], '304 serves the empty answer back');
    strictAssertSame(false, $cached['fresh'], 'from the cache, not the wire');
});

fiStateTest($suite, 'a value under result that is not a row makes the whole document malformed', function () {
    // The envelope declares that every value under 'result' is a row in the
    // column order 'format' names. One that is not means the document is not
    // the shape it claims -- skipping it silently let a half-understood dump
    // pass as a complete reading, and a sweep then recorded "no forum has this
    // topic" from it.
    $bent = json_encode(array(
        'format' => array('topic_id' => array('tor_status', 'seeders', 'info_hash')),
        'result' => array('100' => array(0, 7, str_repeat('A', 40)), '200' => 'not a row'),
    ));
    $malformed = false;
    strictAssertSame(array(), RuTrackerForumIndex::parseDump($bent, $malformed),
        'nothing is taken from a document that is not the declared shape');
    strictAssertSame(true, $malformed, 'and it is reported as malformed, not as an empty forum');

    Snoopy::reset();
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '921', 200, $bent);
    strictAssertSame(null, RuTrackerForumIndex::fetchDump(921),
        'so the fetch concludes nothing rather than caching a partial reading');
});

$suite->test('a dump missing the column nobody reads is still a dump', function () {
    // tor_status and info_hash are what every layer-3 verdict is derived from.
    // 'seeders' was in the same mandatory set although no production code has
    // ever read the value, so one column dropped from the tracker's schema
    // would have made every forum unreadable and every layer-3 verdict
    // impossible -- for a field the plugin does not use.
    $withoutSeeders = json_encode(array(
        'format' => array('topic_id' => array('tor_status', 'info_hash')),
        'result' => array('6868321' => array(0, str_repeat('F', 40))),
    ));
    $malformed = true;
    $rows = RuTrackerForumIndex::parseDump($withoutSeeders, $malformed);
    strictAssertSame(false, $malformed, 'the document is readable without the unused column');
    strictAssertSame(str_repeat('F', 40), $rows[6868321]['info_hash'], 'and the verdict fields are there');
    strictAssertSame(0, $rows[6868321]['seeders'], 'the unread field defaults rather than failing the parse');

    // The two that ARE read stay mandatory.
    foreach (array('tor_status', 'info_hash') as $required) {
        $columns = array_values(array_diff(array('tor_status', 'seeders', 'info_hash'), array($required)));
        $malformed = false;
        RuTrackerForumIndex::parseDump(json_encode(array(
            'format' => array('topic_id' => $columns),
            'result' => array('1' => array_fill(0, count($columns), 0)),
        )), $malformed);
        strictAssertSame(true, $malformed, 'a dump without ' . $required . ' cannot be read');
    }
});

exit($suite->run());
