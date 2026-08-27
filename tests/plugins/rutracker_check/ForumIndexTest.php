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
    $dumpState = RuTrackerState::load('forumindex');
    $doc = RuTrackerState::load($dumpState['dump_documents'][921]);
    strictAssertSame(1, count($doc['rows']),
        'the rows live in their own per-forum document');
    strictAssertSame('"abc123"', $doc['etag'],
        'and carry the ETag that names them, published by the same write');
    strictAssertSame(1, $doc['generation'],
        'the document carries the durable generation that names it');
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
    $hadMask = array_key_exists('profileMask', $GLOBALS);
    $savedMask = $hadMask ? $GLOBALS['profileMask'] : null;
    $client = new ForumIndexScripted200Client(45, 'F', '"cannot-land"', '', function () {
        $GLOBALS['profileMask'] = 0555;
    });
    try {
        $answer = RuTrackerForumIndex::fetchDump(921, $client);
    } finally {
        if ($hadMask) $GLOBALS['profileMask'] = $savedMask;
        else unset($GLOBALS['profileMask']);
        @chmod($tmp, 0777);
    }

    strictAssertSame(null, $answer,
        'wire rows are not returned when no dump document reached durable storage');
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
    RuTrackerState::save('forumdump-999-4', array('generation' => 4, 'etag' => '"stale"', 'rows' =>
        array(1 => array('tor_status' => 0, 'info_hash' => str_repeat('A', 40), 'seeders' => 1))));
    RuTrackerState::save('forumindex', array(
        'etags' => array(999 => '"stale"'),
        'dump_documents' => array(999 => 'forumdump-999-4'),
        'dump_generations' => array(999 => 4),
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
        RuTrackerForumIndex::fetchDump(921,
            new ForumIndexScripted200Client(46, 'B', '"genB"', '"genA"'));
        return true;
    }
}

// Models request A receiving a late HTTP 200 after request B has already replaced
// both the cached body and its cheap ETag hint with a newer generation.
class ForumIndexStale200Client
{
    public $status = 200;
    public $results = '';
    public $headers = array('ETag: "genA_new"');
    public $rawheaders = array('If-None-Match' => '"genA"');

    public function __construct()
    {
        $this->results = fiDump(6868321, 0, str_repeat('A', 40), 40);
    }

    public function fetchComplex($url, $method = 'GET', $contentType = '', $body = '')
    {
        RuTrackerForumIndex::fetchDump(921,
            new ForumIndexScripted200Client(50, 'B', '"genB"', '"genA"'));
        return true;
    }
}

// Models request A receiving a late HTTP 200 without ETags after request B has already bumped
// the generation and stored newer rows without ETags.
class ForumIndexStale200NoEtagClient
{
    public $status = 200;
    public $results = '';
    public $headers = array();
    public $rawheaders = array();

    public function __construct()
    {
        $this->results = fiDump(6868321, 0, str_repeat('A', 40), 40);
    }

    public function fetchComplex($url, $method = 'GET', $contentType = '', $body = '')
    {
        RuTrackerForumIndex::fetchDump(921, new ForumIndexScripted200Client(50, 'B'));
        return true;
    }
}

// A real fetchDump() boundary double: the HTTP answer is literal, while the
// callback models another process or a filesystem fault occurring only after
// this request has durably reserved its generation and released the state
// lock. Assertions inspect persisted state, not this double's internals.
class ForumIndexScripted200Client
{
    public $status = 200;
    public $results;
    public $headers = array();
    public $rawheaders = array();
    public $fetches = 0;
    private $duringFetch;

    public function __construct($seeders, $hashChar = 'A', $etag = '', $sent = '', $duringFetch = null)
    {
        $this->results = fiDump(6868321, 0, str_repeat($hashChar, 40), (int) $seeders);
        if ($etag !== '') $this->headers = array('ETag: ' . $etag);
        if ($sent !== '') $this->rawheaders = array('If-None-Match' => $sent);
        $this->duringFetch = $duringFetch;
    }

    public function fetchComplex($url, $method = 'GET', $contentType = '', $body = '')
    {
        $this->fetches++;
        if ($this->duringFetch !== null) call_user_func($this->duringFetch);
        return true;
    }
}

function fiPoisonForumIndexForReplaceFailure($tmp)
{
    $path = $tmp . '/forumindex.json';
    $json = file_get_contents($path);
    strictAssertTrue(is_string($json) && substr($json, -1) === '}',
        'the durable forumindex document exists before failure injection');
    // PHP accepts this JSON as INF, but json_encode() cannot write INF back.
    // update() therefore runs its mutator and then fails in replace(), which
    // deterministically models the post-promotion persistence boundary.
    file_put_contents($path, substr($json, 0, -1) . ',"fi_replace_failure":1e400}');
    return $json;
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
        $state = RuTrackerState::load('forumindex');
        strictAssertSame(45, RuTrackerState::load($state['dump_documents'][921])['rows'][6868321]['seeders'],
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

$suite->test('tree transport and malformed responses expose distinct safe crawl reasons', function () {
    $reason = null;
    strictAssertSame(null, RuTrackerForumIndex::sweep(array(111), function ($url) {
        return null;
    }, $reason), 'transport failure remains transient');
    strictAssertSame('tree-transport', $reason,
        'a missing tree response is classified without exposing a URL or body');

    $reason = null;
    strictAssertSame(null, RuTrackerForumIndex::sweep(array(111), function ($url) {
        return '<html>credential=do-not-log</html>';
    }, $reason), 'malformed tree remains transient');
    strictAssertSame('tree-malformed', $reason,
        'a non-tree body is classified without reflecting its content');

    Snoopy::reset();
    Snoopy::queue(RuTrackerForumIndex::TREE_URL, 6, '');
    $reason = null;
    strictAssertSame(null, RuTrackerForumIndex::sweep(array(111), null, $reason),
        'the production fetcher reports cURL transport failure');
    strictAssertSame('tree-transport statuses=transport=curl-exit code=6 reason=dns', $reason,
        'known transport status is aggregated as a safe category');
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
    $reason = null;
    $result = RuTrackerForumIndex::sweep(array(111), function ($url, &$refused = null) use ($tree, &$fetches) {
        if (strpos($url, 'cat_forum_tree') !== false) return $tree;
        $fetches++;
        $refused = true;
        return null;
    }, $reason);
    strictAssertSame(null, $result, 'a refusing tracker ends the crawl with nothing concluded');
    strictAssertSame('dump-refused', $reason,
        'the abort names the safe dump-stage reason');
    strictAssertSame(RuTrackerForumIndex::SWEEP_FAILURE_ABORT, $fetches,
        'the crawl stopped at the abort threshold, not at the end of the tree');

    // Scattered refusals reset the run: one short of the threshold, then a
    // success, over and over -- the crawl must complete.
    $fetches = 0;
    $reason = null;
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
    }, $reason);
    strictAssertSame(array('resolved' => array(), 'complete' => false), $result,
        'scattered refusals do not abort the crawl -- but they DO mark it incomplete');
    strictAssertSame('dump-refused', $reason,
        'the incomplete crawl carries the same safe stage reason');
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
    strictAssertSame('wanted 2, crawl failed reason=crawl-exception', $line,
        'the exception class is named without reflecting arbitrary exception text');

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

fiStateTest($suite, 'production crawl failure line carries the safe tree transport reason', function () {
    RuTrackerForumIndex::queueTopic(555);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array());
    Snoopy::reset();
    Snoopy::queue(RuTrackerForumIndex::TREE_URL, 6, 'credential=do-not-log');

    $line = RuTrackerForumIndex::runCrawl(time());

    strictAssertSame(
        'wanted 1, crawl failed reason=tree-transport statuses=transport=curl-exit code=6 reason=dns',
        $line,
        'the driver receives a stage and transport category, not a URL or response body'
    );
    strictAssertSame(array(555), RuTrackerForumIndex::takeQueuePeek(),
        'the transient failure preserves the explicit queue');
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
    strictAssertSame(array(), RuTrackerState::load('forumindex')['misses'] ?? array(),
        'resolved topic does not become a miss');
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
    strictAssertSame(array(), RuTrackerState::load('forumindex')['misses'] ?? array(),
        'a partial failed write is not evidence the topic is absent');
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
    // from generation B. Seeded the way a publication actually reaches disk --
    // a document the state NAMES, under the generation it published -- so the
    // ETag comparison below is what refuses it and not the cross-check that
    // guards a forum with no published generation at all.
    RuTrackerState::save('forumdump-921-1', array('generation' => 1, 'etag' => '"genA"', 'rows' =>
        array(6868321 => array('tor_status' => 0, 'info_hash' => str_repeat('A', 40), 'seeders' => 45))));
    RuTrackerState::save('forumindex', array(
        'dump_reservations' => array(921 => 1),
        'dump_generations' => array(921 => 1),
        'dump_documents' => array(921 => 'forumdump-921-1'),
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
    $state = RuTrackerState::load('forumindex');
    $doc = RuTrackerState::load($state['dump_documents'][921]);
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
    $state = RuTrackerState::load('forumindex');
    strictAssertSame(46, RuTrackerState::load($state['dump_documents'][921])['rows'][6868321]['seeders'],
        'the newer cached document survives alongside its hint');
});

fiStateTest($suite, 'a late HTTP 200 may not overwrite a newer concurrent ETag and dump document', function () {
    RuTrackerState::save('forumdump-921', array('etag' => '"genA"', 'rows' =>
        array(6868321 => array('tor_status' => 0, 'info_hash' => str_repeat('A', 40), 'seeders' => 45))));
    RuTrackerState::save('forumindex', array(
        'etags' => array(921 => '"genA"'),
        'dump_touched' => array(921 => time()),
    ));

    $result = RuTrackerForumIndex::fetchDump(921, new ForumIndexStale200Client());
    strictAssertSame(true, is_array($result), 'request A returns parsed rows');
    strictAssertSame(50, $result['rows'][6868321]['seeders'],
        'request A discards its stale rows (45) and returns request B\'s newer rows (50)');
    strictAssertSame('"genB"', RuTrackerState::load('forumindex')['etags'][921] ?? null,
        'request A does not overwrite request B\'s newer ETag hint');
    $state = RuTrackerState::load('forumindex');
    strictAssertSame(50, RuTrackerState::load($state['dump_documents'][921])['rows'][6868321]['seeders'],
        'the newer cached document survives on disk without being clobbered');
});

fiStateTest($suite, 'two concurrent HTTP 200 responses without ETag use generation counter to prevent stale clobbering', function () {
    RuTrackerState::save('forumdump-921', array('etag' => '', 'rows' =>
        array(6868321 => array('tor_status' => 0, 'info_hash' => str_repeat('A', 40), 'seeders' => 45))));
    RuTrackerState::save('forumindex', array(
        'dump_gen' => array(921 => 1),
        'dump_touched' => array(921 => time()),
    ));

    $result = RuTrackerForumIndex::fetchDump(921, new ForumIndexStale200NoEtagClient());
    strictAssertSame(true, is_array($result), 'request A returns parsed rows');
    strictAssertSame(50, $result['rows'][6868321]['seeders'],
        'request A discards its stale rows (40) and returns request B\'s newer rows (50)');
    $state = RuTrackerState::load('forumindex');
    strictAssertSame(50, RuTrackerState::load($state['dump_documents'][921])['rows'][6868321]['seeders'],
        'the newer cached document survives on disk without being clobbered');
});

fiStateTest($suite, 'two no-ETag requests reserve distinct durable generations before either fetch', function () {
    $seen = array();
    $inner = new ForumIndexScripted200Client(50, 'B', '', '', function () use (&$seen) {
        $state = RuTrackerState::load('forumindex');
        $seen[] = $state['dump_reservations'][921] ?? null;
    });
    $outer = new ForumIndexScripted200Client(40, 'A', '', '', function () use (&$seen, $inner) {
        $state = RuTrackerState::load('forumindex');
        $seen[] = $state['dump_reservations'][921] ?? null;
        RuTrackerForumIndex::fetchDump(921, $inner);
    });

    $answer = RuTrackerForumIndex::fetchDump(921, $outer);
    $state = RuTrackerState::load('forumindex');

    strictAssertSame(array(1, 2), $seen,
        'request A owns generation 1 before I/O and nested request B owns generation 2 before its I/O');
    strictAssertSame(50, $answer['rows'][6868321]['seeders'],
        'late request A returns the durable winner, never its own stale wire rows');
    strictAssertSame(false, $answer['fresh'], 'a superseded requester did not publish a fresh answer');
    strictAssertSame(2, $state['dump_generations'][921] ?? null, 'generation 2 is the durable winner');
    strictAssertSame('forumdump-921-2', $state['dump_documents'][921] ?? null,
        'durable state names the winner\'s immutable versioned document');
});

fiStateTest($suite, 'a late HTTP 200 loses even when both generations carry the identical ETag', function () {
    RuTrackerState::save('forumdump-921-5', array(
        'generation' => 5,
        'etag' => '"same"',
        'rows' => array(6868321 => array(
            'tor_status' => 0, 'info_hash' => str_repeat('F', 40), 'seeders' => 30)),
    ));
    RuTrackerState::save('forumindex', array(
        'dump_reservations' => array(921 => 5),
        'dump_generations' => array(921 => 5),
        'dump_documents' => array(921 => 'forumdump-921-5'),
        'etags' => array(921 => '"same"'),
        'dump_touched' => array(921 => time()),
    ));

    $seen = array();
    $inner = new ForumIndexScripted200Client(50, 'B', '"same"', '"same"', function () use (&$seen) {
        $state = RuTrackerState::load('forumindex');
        $seen[] = $state['dump_reservations'][921] ?? null;
    });
    $outer = new ForumIndexScripted200Client(40, 'A', '"same"', '"same"', function () use (&$seen, $inner) {
        $state = RuTrackerState::load('forumindex');
        $seen[] = $state['dump_reservations'][921] ?? null;
        RuTrackerForumIndex::fetchDump(921, $inner);
    });

    $answer = RuTrackerForumIndex::fetchDump(921, $outer);
    strictAssertSame(array(6, 7), $seen, 'equal ETags do not collapse two reserved generations');
    strictAssertSame(50, $answer['rows'][6868321]['seeders'],
        'the later generation wins independently of the repeated ETag text');
    strictAssertSame(false, $answer['fresh'], 'request A returned durable rows, not a fresh publication');
});

fiStateTest($suite, 'a reservation that cannot open or replace state issues no HTTP request', function ($tmp) {
    // Open failure: a directory at the stable lock pathname cannot be opened
    // as the cross-process lock file.
    mkdir($tmp . '/forumindex.lock', 0777, true);
    $openFailure = new ForumIndexScripted200Client(40, 'A');
    strictAssertSame(null, RuTrackerForumIndex::fetchDump(921, $openFailure),
        'an unrecorded reservation has no network side effect');
    strictAssertSame(0, $openFailure->fetches, 'open failure stops before fetchComplex()');

    strictRemoveTree($tmp . '/forumindex.lock');
    // Replace failure: json_decode accepts 1e400 as INF, while json_encode
    // cannot persist it. The reservation mutator runs, but its replacement
    // fails, so that captured token is not authority to make a request.
    file_put_contents($tmp . '/forumindex.json', '{"fi_replace_failure":1e400}');
    $replaceFailure = new ForumIndexScripted200Client(41, 'B');
    strictAssertSame(null, RuTrackerForumIndex::fetchDump(921, $replaceFailure),
        'a reservation whose state replacement failed is not used');
    strictAssertSame(0, $replaceFailure->fetches, 'replace failure also stops before fetchComplex()');
});

fiStateTest($suite, 'staging failure after another winner returns only the durable winner', function ($tmp) {
    $hadMask = array_key_exists('profileMask', $GLOBALS);
    $savedMask = $hadMask ? $GLOBALS['profileMask'] : null;
    $inner = new ForumIndexScripted200Client(50, 'B');
    $outer = new ForumIndexScripted200Client(40, 'A', '', '', function () use ($inner) {
        RuTrackerForumIndex::fetchDump(921, $inner);
        // RuTrackerState::dir() reapplies this mask immediately before the
        // outer request tries to create its staged document.
        $GLOBALS['profileMask'] = 0555;
    });

    try {
        $answer = RuTrackerForumIndex::fetchDump(921, $outer);
    } finally {
        if ($hadMask) $GLOBALS['profileMask'] = $savedMask;
        else unset($GLOBALS['profileMask']);
        @chmod($tmp, 0777);
    }

    strictAssertSame(50, $answer['rows'][6868321]['seeders'],
        'failed staging cannot leak request A\'s wire rows over request B');
    strictAssertSame(false, $answer['fresh'], 'only durable winner B is returned');
});

fiStateTest($suite, 'state-lock open failure after another winner returns only the durable winner', function ($tmp) {
    $inner = new ForumIndexScripted200Client(50, 'B');
    $outer = new ForumIndexScripted200Client(40, 'A', '', '', function () use ($inner, $tmp) {
        RuTrackerForumIndex::fetchDump(921, $inner);
        @unlink($tmp . '/forumindex.lock');
        mkdir($tmp . '/forumindex.lock', 0777, true);
    });

    $answer = RuTrackerForumIndex::fetchDump(921, $outer);
    strictAssertSame(50, $answer['rows'][6868321]['seeders'],
        'a publication update that cannot open its lock returns B, not wire rows A');
    strictAssertSame(false, $answer['fresh'], 'the returned winner is durable, not fresh from A');
});

fiStateTest($suite, 'state replace failure after another winner returns only the durable winner', function ($tmp) {
    $inner = new ForumIndexScripted200Client(50, 'B');
    $outer = new ForumIndexScripted200Client(40, 'A', '', '', function () use ($inner, $tmp) {
        RuTrackerForumIndex::fetchDump(921, $inner);
        fiPoisonForumIndexForReplaceFailure($tmp);
    });

    $answer = RuTrackerForumIndex::fetchDump(921, $outer);
    strictAssertSame(50, $answer['rows'][6868321]['seeders'],
        'failed state replacement cannot make requester A authoritative');
    strictAssertSame(false, $answer['fresh'], 'request A returns B as a durable cached answer');
});

fiStateTest($suite, 'failed state persistence after promotion preserves the winner and consumes the reservation', function ($tmp) {
    $winner = array(
        'generation' => 5,
        'etag' => '"winner"',
        'rows' => array(6868321 => array(
            'tor_status' => 0, 'info_hash' => str_repeat('B', 40), 'seeders' => 50)),
    );
    RuTrackerState::save('forumdump-921-5', $winner);
    // Legacy mirror makes the pre-fix implementation exercise its destructive
    // shared-name promotion too; the desired implementation never writes it.
    RuTrackerState::save('forumdump-921', $winner);
    RuTrackerState::save('forumindex', array(
        'dump_reservations' => array(921 => 5),
        'dump_generations' => array(921 => 5),
        'dump_documents' => array(921 => 'forumdump-921-5'),
        'etags' => array(921 => '"winner"'),
        'dump_touched' => array(921 => time()),
    ));

    $reservedJson = null;
    $failed = new ForumIndexScripted200Client(60, 'C', '"failed"', '"winner"',
        function () use ($tmp, &$reservedJson) {
            $reservedJson = fiPoisonForumIndexForReplaceFailure($tmp);
        });
    $answer = RuTrackerForumIndex::fetchDump(921, $failed);

    strictAssertSame(50, $answer['rows'][6868321]['seeders'],
        'a promoted document whose state pointer did not persist cannot replace the durable winner');
    strictAssertSame(false, $answer['fresh'], 'failed publication returns only the durable winner');
    strictAssertSame(50, RuTrackerState::load('forumdump-921-5')['rows'][6868321]['seeders'],
        'the immutable winner document was never overwritten');
    strictAssertSame(array(), RuTrackerState::load('forumdump-921-6'),
        'the failed requester\'s unreferenced promoted document is removed');

    // Repair only the injected fault. The first reservation remains durable,
    // so a later request must take generation 7 rather than reuse generation 6.
    file_put_contents($tmp . '/forumindex.json', $reservedJson);
    $later = RuTrackerForumIndex::fetchDump(921, new ForumIndexScripted200Client(70, 'D', '"later"', '"winner"'));
    $state = RuTrackerState::load('forumindex');
    strictAssertSame(70, $later['rows'][6868321]['seeders'], 'the later request publishes normally');
    strictAssertSame(7, $state['dump_reservations'][921] ?? null,
        'the failed publication consumed generation 6 before its HTTP request');
    strictAssertSame(7, $state['dump_generations'][921] ?? null,
        'generation 7 is the later durable winner');
    strictAssertSame('forumdump-921-7', $state['dump_documents'][921] ?? null,
        'state points only at the later immutable document');
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

fiStateTest($suite, 'a misses book that is not an array leaves the fleet half of the wanted set alone', function () {
    // The crawl reads the miss book TWICE, at two different times, and each
    // read has its own guard. This pins the first one: the unlocked snapshot
    // runCrawl() takes before it decides what to want.
    //
    // Without it, indexing a string book one yields a single character, which
    // missSuppresses() reads as a miss whose stamp will not parse -- so the
    // topic is dropped from the wanted set, a bogus record is proposed for it,
    // and a topic nobody is looking for is a topic that is never checked
    // again. Measured on the parent of this work: the same shape fatals with
    // "Array to string conversion" once the repair is written back.
    //
    // The second read, inside the update() callback, is guarded separately and
    // deliberately not pinned: update() re-reads the document under its lock,
    // so only another PROCESS can corrupt the book in that window, and no
    // single-process test can produce one.
    $now = time();
    $corrupt = 'abcdefghijklmnopqrst';
    RuTrackerState::save('forumindex', array('misses' => $corrupt));
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), '5', ''));

    $seen = null;
    RuTrackerForumIndex::runCrawl($now, function ($wanted) use (&$seen) {
        $seen = $wanted;
        return array('resolved' => array(), 'complete' => true);
    });

    strictAssertSame(array(5), $seen,
        'a book that holds no record suppresses nothing, so the topic is still wanted');
    strictAssertSame(0, count(array_filter(ruTrackerChecker::$logs, function ($line) {
        return strpos($line, 'carries no readable stamp') !== false;
    })), 'and no character out of a string is reported as an undatable miss');
    // The book does get repaired, but by the completing crawl's own write-back
    // through the same normalising read -- not by indexing a character out of
    // it. So the corrupt string is DISCARDED and topic 5 is recorded as the
    // miss it actually is, with a stamp that will parse next cycle.
    $after = RuTrackerState::load('forumindex')['misses'] ?? null;
    strictAssertTrue(is_array($after), 'the completing crawl leaves a book, not a string');
    strictAssertTrue(is_int($after[5]['at'] ?? null),
        'and topic 5 is stamped as a real miss, so its backoff can expire');
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

// tor_status and seeders are read through RuTrackerRpcValue's signed and
// nonnegative int32 domains. The exact edges of those two domains are what
// keeps a 64-bit PHP from accepting a dump value a 32-bit daemon could never
// carry, and what keeps a negative status readable at all.
$suite->test('parseDump reads tor_status and seeders at the exact int32 boundaries', function () {
    $accepted = array(
        'status INT32_MIN' => array('-2147483648', -2147483648),
        'status INT32_MAX' => array('2147483647', 2147483647),
        'status negative'  => array('-7', -7),
        'status int form'  => array(-7, -7),
        'status zero'      => array('0', 0),
    );
    foreach ($accepted as $label => $case) {
        $malformed = true;
        $rows = RuTrackerForumIndex::parseDump(json_encode(array(
            'format' => array('topic_id' => array('tor_status', 'info_hash')),
            'result' => array('1' => array($case[0], str_repeat('A', 40))),
        )), $malformed);
        strictAssertSame(false, $malformed, $label . ': a canonical status is readable');
        strictAssertSame($case[1], $rows[1]['tor_status'], $label . ': and read exactly');
    }

    foreach (array('status past INT32_MAX' => '2147483648',
                   'status past INT32_MIN' => '-2147483649',
                   'status PHP_INT_MAX' => (string) PHP_INT_MAX,
                   'status padded' => ' 0',
                   'status trailing space' => '0 ') as $label => $bad) {
        $malformed = false;
        $rows = RuTrackerForumIndex::parseDump(json_encode(array(
            'format' => array('topic_id' => array('tor_status', 'info_hash')),
            'result' => array('1' => array($bad, str_repeat('A', 40))),
        )), $malformed);
        strictAssertSame(array(), $rows, $label . ': rejected');
        strictAssertSame(true, $malformed, $label . ': and the whole dump is marked malformed');
    }

    // Seeders are the NONNEGATIVE int32 domain: no negatives at all, and the
    // same ceiling.
    foreach (array('seeders zero' => array('0', 0),
                   'seeders int32 max' => array('2147483647', 2147483647),
                   'seeders int form' => array(12, 12)) as $label => $case) {
        $malformed = true;
        $rows = RuTrackerForumIndex::parseDump(json_encode(array(
            'format' => array('topic_id' => array('tor_status', 'info_hash', 'seeders')),
            'result' => array('1' => array(0, str_repeat('A', 40), $case[0])),
        )), $malformed);
        strictAssertSame(false, $malformed, $label . ': a canonical seeder count is readable');
        strictAssertSame($case[1], $rows[1]['seeders'], $label . ': and read exactly');
    }

    foreach (array('seeders INT32_MIN' => '-2147483648',
                   'seeders PHP_INT_MAX' => (string) PHP_INT_MAX,
                   'seeders padded' => ' 1',
                   'seeders minus zero' => '-0') as $label => $bad) {
        $malformed = false;
        $rows = RuTrackerForumIndex::parseDump(json_encode(array(
            'format' => array('topic_id' => array('tor_status', 'info_hash', 'seeders')),
            'result' => array('1' => array(0, str_repeat('A', 40), $bad)),
        )), $malformed);
        strictAssertSame(array(), $rows, $label . ': rejected');
        strictAssertSame(true, $malformed, $label . ': and the whole dump is marked malformed');
    }
});

// --- Persisted and RPC integers at the forum index's own boundaries ---------
//
// Every counter below steers something irreversible: whether a tracker-wide
// crawl runs at all, which generation of a multi-megabyte body is current,
// which queued request a completing crawl may retire, and which chk-forum
// value is written onto a torrent. A bare (int) answered 0 for every spelling
// it could not read, and 0 was the dangerous reading at all four.

// The spellings that are NOT a canonical nonnegative integer, in the shapes a
// hand-edited or half-written JSON document actually produces.
function fiCorruptCounters()
{
    return array('leading zero' => '05', 'plus sign' => '+5', 'negative' => '-5',
        'minus zero' => '-0', 'padded' => ' 5', 'trailing text' => '5abc',
        'float' => 5.5, 'bool' => true, 'text' => 'five', 'null' => null,
        'array' => array(5));
}

// The same matrix one level UP. fiCorruptCounters() corrupts the ENTRY inside
// a per-forum book; these corrupt the BOOK ITSELF, which nothing above ever
// did -- and that gap is exactly what let a book that is not an array
// through. Every READ in forumindex.php goes through storedCount(), which
// answers null for one; a WRITE has no such refusal. On the php85 runtime
// production uses, `$state[$book][$id] = ...` into a scalar book is an
// uncaught Error; on the PHP 7.4 target a STRING book swallows the first byte
// of the value and pads itself to length in complete silence; and unset() on
// either shape is an Error on both.
//
// ABSENT and NULL are deliberately NOT here. Both are the clean empty book --
// every reader already reads them as empty and PHP vivifies both on write --
// so repairing either would be the mistake in the other direction. They are
// asserted as controls instead.
function fiCorruptContainers()
{
    return array('scalar' => 7, 'zero' => 0, 'float' => 5.5, 'string' => 'corrupt',
        'empty string' => '', 'true' => true, 'false' => false);
}

// Every per-forum book fetchDump() and touchDump() read an entry out of or
// write an entry into.
function fiForumBooks()
{
    return array('dump_reservations', 'dump_generations', 'dump_gen', 'dump_tokens',
        'dump_documents', 'dump_touched', 'etags');
}

fiStateTest($suite, 'a sweep stamp that will not parse is repaired instead of wedging the crawl for ever', function () {
    // markSweep() is last_sweep's ONLY writer, and it was gated behind the
    // same read, so refusing an unreadable stamp disabled the tracker-wide
    // forum crawl for the WHOLE installation, silently and permanently:
    // sweepAllowed() false for ever, markSweep() false for ever, and nothing
    // able to put either back. last_sweep is a pure cooldown STAMP, not an
    // audit value, so writing $now over one nobody can read is strictly
    // conservative -- it hands out at most the single crawl below and it
    // heals the document on the way.
    foreach (fiCorruptCounters() as $label => $stamp) {
        ruTrackerChecker::reset();
        RuTrackerState::save('forumindex', array('last_sweep' => $stamp, 'queue' => array(777)));

        strictAssertSame(true, RuTrackerForumIndex::sweepAllowed(2000000),
            $label . ': one unreadable byte does not disable the crawl for the whole installation');
        strictAssertSame(true, RuTrackerForumIndex::markSweep(2000000),
            $label . ': the window is claimed exactly once');
        strictAssertSame(2000000, RuTrackerState::load('forumindex')['last_sweep'] ?? null,
            $label . ': and the stamp is repaired to a value every later reader can use');
        strictAssertSame(array(777), RuTrackerState::load('forumindex')['queue'] ?? null,
            $label . ': nothing else in the document is touched');
        strictAssertSame(false, RuTrackerForumIndex::sweepAllowed(2000001),
            $label . ': so the repair costs at most the one crawl it just granted');
        strictAssertSame(false, RuTrackerForumIndex::markSweep(2000001),
            $label . ': and a second claim is refused by the repaired stamp');
        strictAssertOneLogMatching(ruTrackerChecker::$logs, 'one crawl is allowed through to restamp it',
            $label . ': the cooldown that cannot be judged is visible to an operator');
        strictAssertOneLogMatching(ruTrackerChecker::$logs, 'it is restamped to now',
            $label . ': and so is the repair itself');
    }

    // The control: a canonical stamp still holds the cooldown and still lapses.
    RuTrackerState::save('forumindex', array('last_sweep' => 1000));
    strictAssertSame(false, RuTrackerForumIndex::sweepAllowed(1000 + 86400), 'the cooldown holds');
    strictAssertSame(true, RuTrackerForumIndex::sweepAllowed(1000 + 86401), 'and still expires on time');
    strictAssertSame(true, RuTrackerForumIndex::markSweep(1000 + 86401), 'and the window is claimable');
});

fiStateTest($suite, 'a generation counter that will not parse fetches nothing this time and is retired, not wedged', function () {
    // Every writer of these counters is gated behind the same read, so an
    // unreadable entry meant that forum's dump could never be fetched again
    // -- and cachedDocument() refuses the cached body for the same reason, so
    // the forum went permanently and silently dark. The counters are pure
    // publication bookkeeping for a CACHE, not an audit trail: this fetch is
    // still refused (nothing may be counted from a number nobody can read),
    // but the forum's corrupt bookkeeping and the body it named are retired
    // so the NEXT fetch starts from a clean, absent state.
    Snoopy::reset();
    foreach (array('dump_reservations', 'dump_generations', 'dump_gen') as $field) {
        foreach (fiCorruptCounters() as $label => $value) {
            $where = $field . '/' . $label;
            ruTrackerChecker::reset();
            RuTrackerState::save('forumindex', array(
                $field => array(921 => $value, 42 => 3),
                'dump_touched' => array(42 => 1234567890),
            ));
            strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
            Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '921', 200,
                fiDump(6868321, 0, str_repeat('F', 40), 45));

            strictAssertSame(null, RuTrackerForumIndex::fetchDump(921),
                $where . ': a reservation that cannot be counted from buys no request');
            strictAssertSame(0, count(Snoopy::$requests),
                $where . ': and no dump is fetched at all');
            $after = RuTrackerState::load('forumindex');
            strictAssertSame(false, array_key_exists(921, $after[$field] ?? array()),
                $where . ': the unreadable entry is retired rather than left to wedge the forum');
            strictAssertSame(3, $after[$field][42] ?? null,
                $where . ': and no other forum is touched by the repair');
            strictAssertOneLogMatching(ruTrackerChecker::$logs, $field,
                $where . ': and the refusal names the document and the key');

            // The self-heal: the very next call reserves generation 1 and
            // publishes, which the wedged version could never do again.
            strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
            Snoopy::reset();
            Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '921', 200,
                fiDump(6868321, 0, str_repeat('F', 40), 45));
            $fetched = RuTrackerForumIndex::fetchDump(921);
            strictAssertSame(true, is_array($fetched) && !empty($fetched['fresh']),
                $where . ': the next fetch succeeds instead of refusing for ever');
            strictAssertSame(1, RuTrackerState::load('forumindex')['dump_generations'][921] ?? null,
                $where . ': and publishes under a generation nothing older can claim');
            Snoopy::reset();
        }
    }

    // The control: a canonical counter still reserves the next generation.
    RuTrackerState::save('forumindex', array('dump_generations' => array(921 => 5)));
    strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '921', 200, fiDump(6868321, 0, str_repeat('F', 40), 45));
    $fetched = RuTrackerForumIndex::fetchDump(921);
    strictAssertSame(true, $fetched['fresh'], 'a canonical generation still fetches');
    strictAssertSame(6, RuTrackerState::load('forumindex')['dump_generations'][921],
        'and publishes as the next generation after it');
});

// The reservation counter's own contract is that a number is handed out once
// (see storedCount()'s comment). Retiring a forum's corrupt counters
// necessarily FREES the numbers those counters were holding, so an integer
// alone stopped being evidence of anything -- and both publish gates compared
// only that integer. This is the interleaving that opened, run through the
// real fetchDump() inside the lock-free 30s window makeDumpClient() sizes:
// A reserves and goes in flight, one byte of a counter goes bad, B refuses
// and retires, C starts clean and publishes a NEWER body, and only then does
// A's own response arrive holding a reservation the retirement made reusable.
// This dump is what decides STE_DELETED.
fiStateTest($suite, 'a request in flight across a counter retirement cannot republish its older body', function () {
    // book that goes bad => the generation C is handed once the retirement ran.
    foreach (array(
        // The reservation book ITSELF is the unreadable one, so the number A
        // is holding cannot be recovered from anything on disk and C is handed
        // exactly the same integer again. Only the reservation's IDENTITY can
        // separate the two requests here.
        'dump_reservations' => 1,
        // These leave A's reservation readable, so the retirement seeds the
        // counter above it and the number is never reused at all.
        'dump_generations' => 2,
        'dump_gen' => 2,
    ) as $book => $expected) {
        strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
        RuTrackerState::save('forumindex', array());
        foreach (array('forumdump-921', 'forumdump-921-1', 'forumdump-921-2') as $document)
            RuTrackerState::drop($document);

        $interleaved = function () use ($book) {
            // One byte of the book goes bad while A is in flight.
            RuTrackerState::update('forumindex', function ($state) use ($book) {
                $state[$book][921] = '01';
                return $state;
            });
            // B finds it, refuses, and retires the forum's bookkeeping --
            // including the entry A's live reservation was recorded in.
            $refused = new ForumIndexScripted200Client(0, 'X');
            strictAssertSame(null, RuTrackerForumIndex::fetchDump(921, $refused),
                $book . ': nothing may be counted from a number nobody can read');
            strictAssertSame(0, $refused->fetches,
                $book . ': so that request is not even made');
            // C then starts from the clean state and publishes a newer body.
            $newer = RuTrackerForumIndex::fetchDump(921, new ForumIndexScripted200Client(222, 'C'));
            strictAssertSame(true, $newer['fresh'],
                $book . ': the next request starts clean and publishes, rather than being wedged');
        };

        $answer = RuTrackerForumIndex::fetchDump(921,
            new ForumIndexScripted200Client(111, 'A', '', '', $interleaved));

        $state = RuTrackerState::load('forumindex');
        strictAssertSame(222, RuTrackerForumIndex::cachedDump(921)[6868321]['seeders'],
            $book . ': the older in-flight body cannot republish over the newer one');
        strictAssertSame(222, RuTrackerState::load($state['dump_documents'][921])['rows'][6868321]['seeders'],
            $book . ': and the document the state names still holds the newer body');
        strictAssertSame(false, $answer['fresh'],
            $book . ': the request that crossed the retirement publishes nothing');
        strictAssertSame(222, $answer['rows'][6868321]['seeders'],
            $book . ': it answers with the durable winner instead of its own stale wire rows');
        strictAssertSame($expected, $state['dump_generations'][921] ?? null,
            $book . ': and no generation still readable on disk is handed out a second time');
        strictAssertSame('forumdump-921-' . $expected, $state['dump_documents'][921] ?? null,
            $book . ': so two bodies can never share one document name');
    }

    // Every witness the retirement counts from, one at a time. The floor is
    // only a floor if it saw all of them.
    foreach (array(
        // dump_gen -- the migration path from the first incomplete generation
        // attempt -- is scanned LAST, so a floor taken from the prefix before
        // the unreadable entry would miss the only number there is.
        'the last book scanned' => array(array(
            'dump_reservations' => array(921 => '05'),
            'dump_gen' => array(921 => 5),
        ), 6),
        // Both counter books are unreadable; the document the state published
        // is then the only thing left that still names a generation, and
        // versionedDumpDocument() is the only writer of that name.
        'the published document name' => array(array(
            'dump_reservations' => array(921 => '05'),
            'dump_generations' => array(921 => ' 5'),
            'dump_documents' => array(921 => 'forumdump-921-5'),
        ), 6),
    ) as $label => $case) {
        list($seed, $expected) = $case;
        strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
        foreach (array('forumdump-921', 'forumdump-921-1', 'forumdump-921-2',
            'forumdump-921-5', 'forumdump-921-6') as $document)
            RuTrackerState::drop($document);
        RuTrackerState::save('forumdump-921-5', array('generation' => 5, 'etag' => '"g5"', 'rows' =>
            array(6868321 => array('tor_status' => 0, 'info_hash' => str_repeat('A', 40), 'seeders' => 5))));
        RuTrackerState::save('forumindex', $seed + array('dump_touched' => array(921 => time())));

        $refused = new ForumIndexScripted200Client(0, 'X');
        strictAssertSame(null, RuTrackerForumIndex::fetchDump(921, $refused),
            $label . ': the unreadable counter still buys no request');
        strictAssertSame(0, $refused->fetches, $label . ': and no dump is fetched at all');
        $after = RuTrackerForumIndex::fetchDump(921, new ForumIndexScripted200Client(66, 'F'));
        strictAssertSame(true, $after['fresh'], $label . ': and the forum is not left wedged');
        strictAssertSame($expected, RuTrackerState::load('forumindex')['dump_generations'][921] ?? null,
            $label . ': the next reservation is above the generation that witness proves');
    }

    // The control: with nothing corrupt anywhere a reservation still
    // publishes, and the next one still supersedes it.
    strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    RuTrackerState::save('forumindex', array());
    foreach (array('forumdump-921', 'forumdump-921-1', 'forumdump-921-2') as $document)
        RuTrackerState::drop($document);
    $first = RuTrackerForumIndex::fetchDump(921, new ForumIndexScripted200Client(333, 'D'));
    strictAssertSame(true, $first['fresh'], 'control: a normal reservation still publishes');
    strictAssertSame(333, RuTrackerForumIndex::cachedDump(921)[6868321]['seeders'],
        'control: and its body becomes the cache');
    $second = RuTrackerForumIndex::fetchDump(921, new ForumIndexScripted200Client(444, 'E'));
    strictAssertSame(true, $second['fresh'], 'control: the next reservation still supersedes it');
    strictAssertSame(444, RuTrackerForumIndex::cachedDump(921)[6868321]['seeders'],
        'control: with its own newer body');
});

// cachedDocument() ran its generation cross-check only while
// dump_documents[$forumId] was there, and fell back to the legacy unversioned
// name when it was not. The retirement erases that pointer, so a body left
// under the legacy name -- written before dumps were versioned, or by a drop
// that could not remove it -- became "the cache" for a forum whose whole
// bookkeeping had just been retired, with nothing checking which generation it
// was. Layer 3 answers "this topic is not in this forum" from that body.
fiStateTest($suite, 'a retired forum serves no cached body, not even one left under the legacy name', function () {
    RuTrackerState::save('forumdump-921', array('etag' => '"legacy"', 'rows' =>
        array(6868321 => array('tor_status' => 0, 'info_hash' => str_repeat('A', 40), 'seeders' => 111))));
    RuTrackerState::save('forumdump-921-3', array('generation' => 3, 'etag' => '"g3"', 'rows' =>
        array(6868321 => array('tor_status' => 0, 'info_hash' => str_repeat('B', 40), 'seeders' => 33))));
    RuTrackerState::save('forumindex', array(
        'dump_reservations' => array(921 => 3),
        'dump_generations' => array(921 => 3),
        'dump_gen' => array(921 => '03'),
        'dump_documents' => array(921 => 'forumdump-921-3'),
        'etags' => array(921 => '"g3"'),
        'dump_touched' => array(921 => time()),
    ));

    $refused = new ForumIndexScripted200Client(0, 'X');
    strictAssertSame(null, RuTrackerForumIndex::fetchDump(921, $refused),
        'the unreadable counter retires the forum');
    strictAssertSame(0, $refused->fetches, 'and buys no request');

    strictAssertSame(null, RuTrackerForumIndex::cachedDump(921),
        'a retired forum has no cached body, so no absence can be confirmed from one');

    // The control: the very next fetch republishes, and THAT body is served.
    strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    $published = RuTrackerForumIndex::fetchDump(921, new ForumIndexScripted200Client(55, 'C'));
    strictAssertSame(true, $published['fresh'], 'control: the next fetch publishes normally');
    strictAssertSame(55, RuTrackerForumIndex::cachedDump(921)[6868321]['seeders'],
        'control: and the body it published is the one served');
});

fiStateTest($suite, 'a cached document whose generation is not canonical is not the current body', function () {
    // The state names generation 5; the document spells it "05". Read through
    // a bare (int) the two agreed, and a body nothing published as current was
    // served -- and confirmed by every later 304.
    foreach (array('leading zero' => '05', 'padded' => ' 5', 'float' => 5.5,
        'text' => 'five', 'bool' => true) as $label => $spelling) {
        RuTrackerState::save('forumdump-921-5', array(
            'generation' => $spelling, 'etag' => '"e"',
            'rows' => array(1 => array('tor_status' => 0, 'info_hash' => str_repeat('A', 40), 'seeders' => 1)),
        ));
        RuTrackerState::save('forumindex', array(
            'dump_documents' => array(921 => 'forumdump-921-5'),
            'dump_generations' => array(921 => 5),
            'dump_touched' => array(921 => time()),
        ));
        strictAssertSame(null, RuTrackerForumIndex::cachedDump(921),
            $label . ': a generation that will not parse is not the one the state names');
    }

    // The control: the canonical spelling of the same generation IS current.
    RuTrackerState::save('forumdump-921-5', array(
        'generation' => 5, 'etag' => '"e"',
        'rows' => array(1 => array('tor_status' => 0, 'info_hash' => str_repeat('A', 40), 'seeders' => 1)),
    ));
    strictAssertSame(1, count(RuTrackerForumIndex::cachedDump(921)),
        'the canonical generation still serves the cached body');
});

fiStateTest($suite, 'a retention stamp that will not parse drops no cached dump', function () {
    Snoopy::reset();
    RuTrackerState::save('forumdump-999-4', array('generation' => 4, 'etag' => '"stale"', 'rows' =>
        array(1 => array('tor_status' => 0, 'info_hash' => str_repeat('A', 40), 'seeders' => 1))));
    RuTrackerState::save('forumindex', array(
        'etags' => array(999 => '"stale"'),
        'dump_documents' => array(999 => 'forumdump-999-4'),
        'dump_generations' => array(999 => 4),
        'dump_touched' => array(999 => 'whenever'),
    ));

    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '921', 200, fiDump(6868321, 0, str_repeat('F', 40), 45));
    RuTrackerForumIndex::fetchDump(921);

    strictAssertTrue(RuTrackerForumIndex::cachedDump(999) !== null,
        'a stamp nobody can read is not evidence the body is 30 days stale');
    strictAssertSame('"stale"', RuTrackerState::load('forumindex')['etags'][999] ?? null,
        'and its conditional-GET hint survives with it');
});

// Restamping an undatable miss record is right; throwing away the part of it
// that DID read is not. The repair wrote the cap over a count that read
// cleanly as 1, so a topic on its first miss came back at eight cooldowns
// instead of one -- seven cooldowns in which no crawl looks for it, its
// chk-forum cannot be corrected, and layer 3 keeps reading the wrong forum.
fiStateTest($suite, 'a miss record with an unreadable stamp keeps the count that did read', function () {
    $cooldown = 86400;
    foreach (array(
        // stored record => the count the repair must leave behind.
        'readable first miss' => array(array('at' => 'whenever', 'n' => 1), 1),
        'readable third miss' => array(array('at' => 'whenever', 'n' => 3), 3),
        // The legacy shape is a bare stamp with no count at all, which
        // missCount() has always read as a first miss.
        'legacy bare stamp' => array('whenever', 1),
        // Only a count nobody can read takes the cap.
        'unreadable count' => array(array('at' => 'whenever', 'n' => '01'), RuTrackerForumIndex::MISS_WINDOW_CAP),
    ) as $label => $case) {
        list($stored, $expected) = $case;
        ruTrackerChecker::reset();
        RuTrackerState::save('forumindex', array('misses' => array(777 => $stored)));

        RuTrackerForumIndex::queueTopic(777);

        strictAssertSame(array(), RuTrackerForumIndex::takeQueuePeek(),
            $label . ': the undatable record still suppresses this attempt, so no crawl is granted');
        $repaired = RuTrackerState::load('forumindex')['misses'][777] ?? null;
        strictAssertSame($expected, $repaired['n'] ?? null,
            $label . ': and the repair keeps the count it could read rather than raising it');

        // The window that count earns, and not a wider one: age the repaired
        // stamp past its own window and the topic must be queueable again.
        $window = $cooldown * min(RuTrackerForumIndex::MISS_WINDOW_CAP, 1 << ($expected - 1));
        RuTrackerState::update('forumindex', function ($state) use ($window) {
            $state['misses'][777]['at'] = (int) $state['misses'][777]['at'] - $window - 1;
            return $state;
        });
        RuTrackerForumIndex::queueTopic(777);
        strictAssertSame(array(777), RuTrackerForumIndex::takeQueuePeek(),
            $label . ': one window after the repair the topic is crawled for again');

        // And not before that window is out.
        RuTrackerState::save('forumindex', array('misses' => array(888 => $stored)));
        RuTrackerForumIndex::queueTopic(888);
        RuTrackerState::update('forumindex', function ($state) use ($window) {
            $state['misses'][888]['at'] = (int) $state['misses'][888]['at'] - $window + 1;
            return $state;
        });
        RuTrackerForumIndex::queueTopic(888);
        strictAssertSame(array(), RuTrackerForumIndex::takeQueuePeek(),
            $label . ': and the window it earned is held right up to its end');
    }
});

fiStateTest($suite, 'a queue serial that will not parse is reseeded above every live generation, not wedged', function () {
    // Refusing this outright wedged the EXPLICIT half of the crawl for the
    // whole installation, permanently: storeQueuedTopic() is queue_serial's
    // only writer and is gated behind this same read, and a MOVED topic (one
    // that already carries a chk-forum) reaches a crawl through nothing but
    // this queue. So its chk-forum could never be corrected again, layer 3
    // kept reading the wrong forum's dump, and with layer 2 confirming
    // "unregistered" for the re-uploaded topic that path ends in DELETED.
    // The guard is not the number, it is "no generation a running crawl has
    // already observed may be handed out again" -- and queue_versions holds
    // every generation any crawl can be holding, so seeding above the highest
    // READABLE one restores exactly that guarantee.
    foreach (fiCorruptCounters() as $label => $serial) {
        ruTrackerChecker::reset();
        RuTrackerState::save('forumindex', array(
            'queue_serial' => $serial,
            'queue' => array(555, 666),
            'queue_versions' => array(555 => 9, 666 => '07'),
        ));

        RuTrackerForumIndex::queueTopic(777);

        $state = RuTrackerState::load('forumindex');
        strictAssertSame(array(555, 666, 777), RuTrackerForumIndex::takeQueuePeek(),
            $label . ': the topic is queued, so a moved topic can still be re-resolved');
        strictAssertSame(10, $state['queue_serial'] ?? null,
            $label . ': under a serial above every generation a running crawl can be holding');
        strictAssertSame(10, $state['queue_versions'][777] ?? null,
            $label . ': which is the generation this request carries');
        strictAssertSame('07', $state['queue_versions'][666] ?? null,
            $label . ': a generation nobody can read is left exactly as it is, never counted from');
        strictAssertOneLogMatching(ruTrackerChecker::$logs, 'forumindex.json queue_serial',
            $label . ': and the repair names the document and the key');
    }

    // The control: a canonical serial still queues and still advances.
    RuTrackerState::save('forumindex', array('queue_serial' => 5));
    RuTrackerForumIndex::queueTopic(777);
    strictAssertSame(array(777), RuTrackerForumIndex::takeQueuePeek(), 'a canonical serial still queues');
    strictAssertSame(6, RuTrackerState::load('forumindex')['queue_serial'], 'and still advances');
});

// The reseed above is only safe while it stays ABOVE what a crawl already
// observed. Restarting at one -- which is what (int) would have done -- hands
// a request made DURING a sweep the very generation that sweep is holding, and
// settleQueue() then retires a request nobody made, which is the ordering
// failure the serial exists to prevent.
fiStateTest($suite, 'a reseeded queue serial cannot hand a running crawl its own observed generation', function () {
    $hash = str_repeat('A', 40);
    ruTrackerChecker::reset();
    RuTrackerState::save('forumindex', array(
        'queue' => array(777),
        'queue_versions' => array(777 => 1),
        'queue_serial' => '01',
    ));
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($hash, '777', '1106'));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('777', '1106'));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());

    RuTrackerForumIndex::runCrawl(time(), function ($wanted) {
        // Another request discovers that the same topic needs a fresh crawl
        // while this one is in flight -- and finds the serial unreadable.
        RuTrackerForumIndex::queueTopic(777);
        return array('resolved' => array(777 => 2222), 'complete' => true);
    });

    strictAssertSame(array(777), RuTrackerForumIndex::takeQueuePeek(),
        'the request made during the sweep survives it: completion retires only what it observed');
    strictAssertSame(2, RuTrackerState::load('forumindex')['queue_serial'] ?? null,
        'because the repaired serial was seeded above the generation the crawl was holding');
});

fiStateTest($suite, 'a queue generation that will not parse is never retired by a completing crawl', function () {
    foreach (array('leading zero' => '01', 'padded' => ' 1', 'float' => 1.5,
        'text' => 'first', 'bool' => true, 'negative' => -1) as $label => $version) {
        RuTrackerState::save('forumindex', array(
            'queue' => array(777),
            'queue_versions' => array(777 => $version),
        ));
        rXMLRPCRequest::reset();
        // Nothing is awaiting a forum, so the crawl has only the queue.
        rXMLRPCRequest::queue('d.multicall', true, false, array());

        RuTrackerForumIndex::runCrawl(time(), function ($wanted) {
            return array('resolved' => array(), 'complete' => true);
        });

        strictAssertSame(array(777), RuTrackerState::load('forumindex')['queue'],
            $label . ': a request whose generation will not read is kept, not retired');
    }
});

fiStateTest($suite, 'a miss stamp that will not parse keeps suppressing and is restamped, not left for ever', function () {
    // The miss record is a per-topic SUPPRESSION cooldown and markMiss() is
    // its only writer -- and markMiss() only ever runs for a topic that was
    // crawled for, which an undatable record can never be again. So the topic
    // was silently and permanently un-crawlable. Restamping to now grants NO
    // crawl and leaves a record the reader can age out; the undatable one
    // could never expire or even be pruned. The window it is restamped at is
    // the one its own count earns -- see the count case beside this one.
    foreach (array('leading zero' => '01000', 'padded' => ' 1000', 'text' => 'never',
        'float' => 1000.5, 'bool' => true, 'null' => null) as $label => $at) {
        ruTrackerChecker::reset();
        $now = time();
        RuTrackerState::save('forumindex', array('misses' => array(777 => array('at' => $at, 'n' => 1))));

        RuTrackerForumIndex::queueTopic(777);
        strictAssertSame(array(), RuTrackerForumIndex::takeQueuePeek(),
            $label . ': a miss nobody can date is not a lapsed suppression window');
        strictAssertSame(array('at' => $now, 'n' => 1),
            RuTrackerState::load('forumindex')['misses'][777] ?? null,
            $label . ': and it is restamped, under the count it could read, instead of'
                . ' suppressing for ever');
        strictAssertOneLogMatching(ruTrackerChecker::$logs, '777',
            $label . ': and the repair is visible to an operator');

        // markMiss() prunes on every write; a fresh restamp must survive it.
        RuTrackerForumIndex::markMiss(888, $now);
        strictAssertTrue(isset(RuTrackerState::load('forumindex')['misses'][777]),
            $label . ': and the record itself survives the prune-on-write');
    }

    // The repaired shape is one the reader can actually age out, which is the
    // recovery path the undatable record never had.
    $aged = time() - 86400 * RuTrackerForumIndex::MISS_WINDOW_CAP - 1;
    RuTrackerState::save('forumindex', array('misses' => array(
        777 => array('at' => $aged, 'n' => RuTrackerForumIndex::MISS_WINDOW_CAP))));
    RuTrackerForumIndex::queueTopic(777);
    strictAssertSame(array(777), RuTrackerForumIndex::takeQueuePeek(),
        'the widest window still lapses, so the repair is a delay and not a wedge');

    // The control: a canonical, genuinely stale miss still lets the topic queue.
    RuTrackerState::save('forumindex', array('misses' => array(777 => array('at' => 1000, 'n' => 1))));
    RuTrackerForumIndex::queueTopic(777);
    strictAssertSame(array(777), RuTrackerForumIndex::takeQueuePeek(),
        'a canonical stale miss still lets the topic be queued again');
});

fiStateTest($suite, 'markMiss counts up from an unreadable count to the widest window, not the narrowest', function () {
    // missCount() answers null for a count nobody can read, and null + 1 is 1
    // in PHP -- the NARROWEST window, written immediately after missWindow()
    // deliberately chose the widest for the very same record.
    foreach (array('leading zero' => '05', 'text' => 'many', 'float' => 1.5,
        'bool' => true, 'negative' => -1, 'padded' => ' 5') as $label => $count) {
        $now = time();
        RuTrackerState::save('forumindex', array(
            'misses' => array(777 => array('at' => $now - 10, 'n' => $count)),
        ));

        RuTrackerForumIndex::markMiss(777, $now);

        strictAssertSame(RuTrackerForumIndex::MISS_WINDOW_CAP,
            RuTrackerState::load('forumindex')['misses'][777]['n'] ?? null,
            $label . ': a count nobody can read counts up to the widest window, not down to the narrowest');
    }

    // The controls: a readable count still counts up by exactly one, a first
    // miss is still one, and the legacy bare-timestamp shape is still a first.
    $now = time();
    RuTrackerState::save('forumindex', array('misses' => array(777 => array('at' => $now - 10, 'n' => 4))));
    RuTrackerForumIndex::markMiss(777, $now);
    strictAssertSame(5, RuTrackerState::load('forumindex')['misses'][777]['n'] ?? null,
        'control: a canonical 4 still becomes 5');
    RuTrackerState::save('forumindex', array());
    RuTrackerForumIndex::markMiss(777, $now);
    strictAssertSame(1, RuTrackerState::load('forumindex')['misses'][777]['n'] ?? null,
        'control: a first miss is still one');
    RuTrackerState::save('forumindex', array('misses' => array(777 => $now - 10)));
    RuTrackerForumIndex::markMiss(777, $now);
    strictAssertSame(2, RuTrackerState::load('forumindex')['misses'][777]['n'] ?? null,
        'control: the legacy bare-timestamp record is still read as a first miss');
});

fiStateTest($suite, 'a miss count that will not parse suppresses for the longest window, not the shortest', function () {
    // A count of "01" read as 1 gave the SHORTEST window (one cooldown), so a
    // topic that has missed eight sweeps could be re-queued after one -- a
    // full tracker-wide crawl bought by a number nobody could read. The stamp
    // below sits between the narrowest window (86400) and the widest
    // (86400 * MISS_WINDOW_CAP), so the two readings disagree about it.
    $between = time() - 200000;
    foreach (array('leading zero' => '01', 'text' => 'many', 'float' => 1.5,
        'bool' => true, 'negative' => -1) as $label => $count) {
        RuTrackerState::save('forumindex', array(
            'misses' => array(777 => array('at' => $between, 'n' => $count)),
        ));
        RuTrackerForumIndex::queueTopic(777);
        strictAssertSame(array(), RuTrackerForumIndex::takeQueuePeek(),
            $label . ': the widest suppression window applies, not the narrowest');
    }

    // The control: the canonical spelling of the shortest count still lets the
    // same stamp through, so this is a rejection of the bytes, not of the path.
    RuTrackerState::save('forumindex', array(
        'misses' => array(777 => array('at' => $between, 'n' => 1)),
    ));
    RuTrackerForumIndex::queueTopic(777);
    strictAssertSame(array(777), RuTrackerForumIndex::takeQueuePeek(),
        'a canonical count of one still lapses after one cooldown');
});

// The third reader of the same miss record, and the one that decides whether a
// tracker-wide crawl is worth running at all: the FLEET half of the wanted
// set. Read through (int), an undatable miss was "long ago" here too, so a
// topic no completed crawl can ever resolve bought a full walk every cooldown.
fiStateTest($suite, 'a fleet topic whose miss cannot be dated is not crawled for, and its record is repaired', function () {
    foreach (array('text' => 'never', 'leading zero' => '01000', 'float' => 1000.5,
        'bool' => true, 'no stamp at all' => null) as $label => $at) {
        RuTrackerState::save('forumindex', array(
            'misses' => array(777 => array('at' => $at, 'n' => 1)),
        ));
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), '777', ''));

        $swept = null;
        $now = time();
        $line = RuTrackerForumIndex::runCrawl($now, function ($wanted) use (&$swept) {
            $swept = $wanted;
            return array('resolved' => array(), 'complete' => true);
        });

        strictAssertSame(null, $line, $label . ': nothing is wanted, so nothing is reported');
        strictAssertSame(null, $swept, $label . ': and no tracker-wide sweep is run for it');
        strictAssertSame(null, RuTrackerState::load('forumindex')['last_sweep'] ?? null,
            $label . ': the cooldown window is not even claimed');
        // ...but the record is left in a shape that can expire, so this topic
        // is not un-crawlable for the rest of the installation's life.
        strictAssertSame(array('at' => $now, 'n' => 1),
            RuTrackerState::load('forumindex')['misses'][777] ?? null,
            $label . ': the undatable record is restamped under the count it could read');
    }

    // The control: a canonical, genuinely stale miss still puts the fleet
    // topic back in the wanted set.
    RuTrackerState::save('forumindex', array(
        'misses' => array(777 => array('at' => 1000, 'n' => 1)),
    ));
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), '777', ''));
    $swept = null;
    RuTrackerForumIndex::runCrawl(time(), function ($wanted) use (&$swept) {
        $swept = $wanted;
        return array('resolved' => array(), 'complete' => true);
    });
    strictAssertSame(array(777), $swept, 'a canonical stale miss is still crawled for');
});

fiStateTest($suite, 'a chk-topic the fleet scan cannot parse resolves no torrent', function () {
    foreach (array('leading zero' => '007', 'padded' => ' 7', 'trailing text' => '7abc',
        'plus sign' => '+7', 'negative' => '-7', 'zero' => '0',
        'overflow' => '2147483648', 'float' => '7.0') as $label => $stored) {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), $stored, ''));

        strictAssertSame(array(), RuTrackerForumIndex::topicsAwaitingForum(),
            $label . ': ' . $stored . ' names no topic, so no crawl resolution can land on it');
    }

    // The control: a canonical id is still scanned, and still carries its hash.
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array(str_repeat('A', 40), '7', ''));
    strictAssertSame(array(7 => array(str_repeat('A', 40))), RuTrackerForumIndex::topicsAwaitingForum(),
        'a canonical chk-topic is still resolved for');
});

fiStateTest($suite, 'a chk-topic that will not parse authorises no chk-forum write', function () {
    // The read sits between a scoped lock and a d.set_custom. (int) made "007"
    // equal to the topic 7 the crawl resolved, so the mapping was written onto
    // a row whose chk-topic never said 7.
    foreach (array('leading zero' => '007', 'trailing text' => '7abc', 'padded' => ' 7',
        'plus sign' => '+7', 'empty' => '', 'text' => 'seven') as $label => $stored) {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($stored, ''));

        strictAssertSame(RuTrackerForumIndex::FORUM_WRITE_OBSOLETE,
            RuTrackerForumIndex::writeForumMapping(str_repeat('A', 40), 7, 2222),
            $label . ': the row does not provably carry the topic this mapping is about');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
            $label . ': and nothing is written to it');
    }

    // The control: the canonical spelling still writes.
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('7', ''));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    strictAssertSame(RuTrackerForumIndex::FORUM_WRITE_WRITTEN,
        RuTrackerForumIndex::writeForumMapping(str_repeat('A', 40), 7, 2222),
        'a canonical chk-topic still authorises the mapping');
    strictAssertSame(array(str_repeat('A', 40), 'chk-forum', '2222'),
        rXMLRPCRequest::requestsFor('d.set_custom')[0]['commands'][0]->params,
        'and writes exactly the resolved forum');
});

fiStateTest($suite, 'a forum id that will not parse authorises no chk-forum write', function () {
    // The value this function WRITES was the one value it never checked: it
    // canonicalises the row's chk-topic and the caller's topic, and then wrote
    // $forumId through as it came. Every caller had already coerced its own
    // copy, so the guard compared a coerced value against itself. chk-forum is
    // what layer 3 fetches a dump BY, and resolveForum() accepts only a
    // canonical positive int32 -- so '0' and '-22' installed a mapping the
    // reader can never accept, this function then answered FORUM_WRITE_CURRENT
    // for it for ever, and the obligation behind it was never cleared.
    foreach (array('leading zero' => '022', 'trailing text' => '22abc', 'padded' => ' 22',
        'plus sign' => '+22', 'float' => 22.9, 'bool' => true, 'hex' => '0x16',
        'negative' => '-22', 'zero' => '0', 'zero string' => 0, 'empty' => '',
        'array' => array(22), 'null' => null,
        'overflow' => '2147483648') as $label => $forum) {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('7', '55'));

        strictAssertSame(RuTrackerForumIndex::FORUM_WRITE_OBSOLETE,
            RuTrackerForumIndex::writeForumMapping(str_repeat('A', 40), 7, $forum),
            $label . ': a forum id no reader can accept is an impossible obligation, not a stale one');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
            $label . ': and no chk-forum is written for it');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.get_custom|d.get_custom')),
            $label . ': the row is not even read for a write that can never land');
    }

    // And the wedge it left behind: a live chk-forum of "0" -- what the old
    // path installed -- is never answered CURRENT for a "0" the caller asks
    // for again, so forumCorrectionReady() cannot keep saying "ready".
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('7', '0'));
    strictAssertSame(RuTrackerForumIndex::FORUM_WRITE_OBSOLETE,
        RuTrackerForumIndex::writeForumMapping(str_repeat('A', 40), 7, '0'),
        'the installed-but-unreadable mapping is retired instead of confirmed for ever');

    // Controls: both canonical spellings of the same id still write, and an
    // id already current is still CURRENT.
    foreach (array('int' => 2222, 'canonical string' => '2222',
        'widest positive int32' => 2147483647) as $label => $forum) {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('7', ''));
        rXMLRPCRequest::queue('d.set_custom', true, false, array());
        strictAssertSame(RuTrackerForumIndex::FORUM_WRITE_WRITTEN,
            RuTrackerForumIndex::writeForumMapping(str_repeat('A', 40), 7, $forum),
            'control ' . $label . ': a canonical forum id still writes');
        strictAssertSame(array(str_repeat('A', 40), 'chk-forum', (string) (int) $forum),
            rXMLRPCRequest::requestsFor('d.set_custom')[0]['commands'][0]->params,
            'control ' . $label . ': and writes exactly that id');
    }
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('7', '2222'));
    strictAssertSame(RuTrackerForumIndex::FORUM_WRITE_CURRENT,
        RuTrackerForumIndex::writeForumMapping(str_repeat('A', 40), 7, '2222'),
        'control: an id already installed is still recognised as current');
});

fiStateTest($suite, 'a retention key that does not name a forum retires only itself', function () {
    // The prune read the persisted map KEY through (int). json_decode() keeps
    // a non-canonical key like "0921" the string it was written as, and
    // (int) "0921" is 921 -- so the prune deleted forum 921's cached document
    // while unsetting the "0921" bookkeeping, and 921 was then left naming a
    // file that is gone.
    $rows = array(1 => array('tor_status' => 0, 'info_hash' => str_repeat('A', 40), 'seeders' => 1));
    foreach (array('leading zero' => '0921', 'trailing text' => '921abc',
        'padded' => ' 921', 'plus sign' => '+921', 'text' => 'nine') as $label => $key) {
        Snoopy::reset();
        strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
        RuTrackerState::save('forumdump-921-1', array('generation' => 1, 'etag' => '"e"', 'rows' => $rows));
        RuTrackerState::save('forumindex', array(
            'dump_documents' => array(921 => 'forumdump-921-1'),
            'dump_generations' => array(921 => 1),
            'dump_reservations' => array(921 => 1),
            'dump_touched' => array(921 => time(), $key => 1),
        ));

        Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '42', 200,
            fiDump(6868321, 0, str_repeat('F', 40), 45));
        RuTrackerForumIndex::fetchDump(42);

        strictAssertTrue(RuTrackerForumIndex::cachedDump(921) !== null,
            $label . ': the prune of a key that names no forum keeps forum 921 cached');
        $after = RuTrackerState::load('forumindex');
        strictAssertSame('forumdump-921-1', $after['dump_documents'][921] ?? null,
            $label . ': and leaves the bookkeeping that names the surviving body alone');
        strictAssertSame(false, array_key_exists($key, $after['dump_touched'] ?? array()),
            $label . ': while the stale key that named nothing is retired');
    }

    // The control: a CANONICAL stale key still drops the body it names.
    Snoopy::reset();
    strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    RuTrackerState::save('forumdump-921-1', array('generation' => 1, 'etag' => '"e"', 'rows' => $rows));
    RuTrackerState::save('forumindex', array(
        'dump_documents' => array(921 => 'forumdump-921-1'),
        'dump_generations' => array(921 => 1),
        'dump_reservations' => array(921 => 1),
        'dump_touched' => array(921 => 1),
    ));
    Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '42', 200,
        fiDump(6868321, 0, str_repeat('F', 40), 45));
    RuTrackerForumIndex::fetchDump(42);
    strictAssertSame(null, RuTrackerForumIndex::cachedDump(921),
        'a canonical key 30 days stale still drops the document it names');
});

// --- Per-forum BOOKS, not the entries inside them ---------------------------

fiStateTest($suite, 'a per-forum book that is not an array is discarded and started again, never a fatal and never a dark forum', function () {
    // Measured at 3bea1aa5 before this test existed, driving fetchDump()
    // exactly as production does. dump_reservations = 7 and dump_tokens = 7
    // each ended in an uncaught Error ("Cannot use a scalar value as an
    // array") on php85 -- the runtime production runs; dump_documents,
    // dump_touched and etags did the same on both commits. dump_tokens =
    // "corrupt" was worse than a fatal: on PHP 7.4 the token wrote its first
    // BYTE into the string, the publish gate read that byte back, and the
    // measured cycle was httpFetches=1 answer=null cachedDump=null
    // pluginLogLines=0, for ever. A whole multi-megabyte dump fetched and
    // thrown away every cycle, layer 3 answering STE_CANT_REACH_TRACKER for
    // every torrent in that forum, and not one line saying so.
    //
    // Nothing below may raise, either cycle: the suite's error handler makes
    // a PHP warning a failure, and an uncaught Error is a failure by itself.
    foreach (fiForumBooks() as $book) {
        foreach (fiCorruptContainers() as $label => $container) {
            $where = $book . '/' . $label;
            ruTrackerChecker::reset();
            strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
            foreach (array('forumdump-921', 'forumdump-921-1', 'forumdump-921-2') as $document)
                RuTrackerState::drop($document);
            RuTrackerState::save('forumindex', array($book => $container));

            // Two cycles, because a book the reservation preamble READS --
            // the three counters -- legitimately spends the first one
            // retiring the forum, exactly as an unreadable entry does.
            $first = RuTrackerForumIndex::fetchDump(921, new ForumIndexScripted200Client(11, 'A'));
            if ($first === null)
                strictAssertTrue(count(ruTrackerChecker::$logs) > 0,
                    $where . ': a cycle that answers nothing at all says why');

            strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
            $second = RuTrackerForumIndex::fetchDump(921, new ForumIndexScripted200Client(22, 'B'));
            strictAssertSame(true, is_array($second) && !empty($second['fresh']),
                $where . ': the forum publishes rather than staying dark for ever');
            strictAssertSame(22, RuTrackerForumIndex::cachedDump(921)[6868321]['seeders'],
                $where . ': and the body it published is the one the cache serves');

            $after = RuTrackerState::load('forumindex');
            strictAssertSame(true, is_array($after[$book] ?? null),
                $where . ': the book itself is left in a shape an entry can be written into');
            strictAssertOneLogMatching(ruTrackerChecker::$logs, $book . ' is not an array',
                $where . ': and the repair names the document, the key and the consequence');
        }
    }

    // The controls, and they are the half this task has already shipped
    // wrong twice: WELL-FORMED input must not fail. An absent book and a null
    // one are the clean empty book, and a book that IS an array holding a
    // wrong-shaped ENTRY is not a container problem at all -- the entry-level
    // matrix owns that, and the book must be left standing either way.
    $wrongEntry = array(921 => array('deep' => 1));
    foreach (array(
        'absent' => array(),
        'null books' => array('dump_reservations' => null, 'dump_generations' => null,
            'dump_gen' => null, 'dump_tokens' => null, 'dump_documents' => null,
            'dump_touched' => null, 'etags' => null),
        'array books holding a wrong-shaped entry' => array('dump_tokens' => $wrongEntry,
            'dump_documents' => $wrongEntry, 'dump_touched' => $wrongEntry,
            'etags' => $wrongEntry),
    ) as $label => $seed) {
        ruTrackerChecker::reset();
        strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
        foreach (array('forumdump-921', 'forumdump-921-1', 'forumdump-921-2') as $document)
            RuTrackerState::drop($document);
        RuTrackerState::save('forumindex', $seed);

        $answer = RuTrackerForumIndex::fetchDump(921, new ForumIndexScripted200Client(33, 'C'));
        strictAssertSame(true, is_array($answer) && !empty($answer['fresh']),
            $label . ': publishes on the FIRST cycle, with nothing to repair');
        strictAssertSame(0, count(strictLogsMatching(ruTrackerChecker::$logs, 'is not an array')),
            $label . ': and no book is reported as repaired, because none was corrupt');
    }
});

fiStateTest($suite, 'a dump_tokens book that is not an array costs one repaired document, not a forum that refetches for ever and says nothing', function () {
    // The blocking shape on its own, asserted as the HAZARD rather than as a
    // return value: the first cycle must publish, the cache must be able to
    // answer, the book must hold a real token afterwards, and the repair must
    // be visible. Before the fix the scalar book was an uncaught Error on
    // php85 at forumindex.php:319 and the string book was silent on 7.4.
    foreach (array('string' => 'corrupt', 'scalar' => 7) as $label => $container) {
        ruTrackerChecker::reset();
        strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
        foreach (array('forumdump-921', 'forumdump-921-1') as $document)
            RuTrackerState::drop($document);
        RuTrackerState::save('forumindex', array('dump_tokens' => $container));

        $answer = RuTrackerForumIndex::fetchDump(921, new ForumIndexScripted200Client(45, 'F'));
        strictAssertSame(true, is_array($answer) && !empty($answer['fresh']),
            $label . ': the very first cycle publishes instead of discarding its own dump');
        strictAssertSame(45, RuTrackerForumIndex::cachedDump(921)[6868321]['seeders'],
            $label . ': so layer 3 has a cached body to answer absence from');
        $after = RuTrackerState::load('forumindex');
        strictAssertSame(true, is_array($after['dump_tokens'] ?? null),
            $label . ': the book holds tokens rather than one byte of one');
        strictAssertSame(true, is_string($after['dump_tokens'][921] ?? null)
            && strlen($after['dump_tokens'][921]) > 1,
            $label . ': including this request\'s own, whole');
        strictAssertOneLogMatching(ruTrackerChecker::$logs, 'dump_tokens is not an array',
            $label . ': and the repair names the document, the key and what it would have cost');
    }
});

fiStateTest($suite, 'the reseeded floor reaches a reservations book that is not an array, and the number space still only goes up', function () {
    // The exact measured input, {"dump_reservations":7,"dump_generations":{"921":3}}:
    // 011c24e3 logged a refusal and stayed recoverable, 8fa4f56f raised an
    // uncaught Error at forumindex.php:312 on php85 -- the floor seed writes
    // into the very book storedCount() had just answered null for, two lines
    // after a loop that guards its own unset with is_array().
    ruTrackerChecker::reset();
    RuTrackerState::save('forumindex', array(
        'dump_reservations' => 7,
        'dump_generations' => array(921 => 3),
    ));

    $refused = new ForumIndexScripted200Client(0, 'X');
    strictAssertSame(null, RuTrackerForumIndex::fetchDump(921, $refused),
        'a counter that cannot be counted from still buys no request');
    strictAssertSame(0, $refused->fetches, 'and no dump is fetched at all');
    strictAssertSame(3, RuTrackerState::load('forumindex')['dump_reservations'][921] ?? null,
        'the floor the readable books prove still lands, in a book that can now take it');
    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'dump_reservations is not an array',
        'and discarding the book is named, not merely survived');

    strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    $published = RuTrackerForumIndex::fetchDump(921, new ForumIndexScripted200Client(88, 'D'));
    strictAssertSame(true, $published['fresh'], 'the next request publishes rather than being wedged');
    strictAssertSame(4, RuTrackerState::load('forumindex')['dump_generations'][921] ?? null,
        'above every generation still readable on disk, so no two bodies can share one name');
});

fiStateTest($suite, 'a retirement leaves every per-forum book in a shape the next reservation can be written into', function () {
    // The retirement's whole promise is that the next call starts clean, and
    // its unset was skipped for precisely the books that most needed it: one
    // that is not an array cannot be unset (an Error on both target runtimes)
    // and cannot take the reseeded floor either. dump_tokens is the one that
    // matters most, because an erased token is what stops a request already
    // inside the lock-free fetch window from republishing across the
    // retirement.
    ruTrackerChecker::reset();
    RuTrackerState::save('forumindex', array(
        'dump_reservations' => array(921 => '05'),
        'dump_generations' => 7,
        'dump_gen' => 'g',
        'dump_documents' => 5.5,
        'dump_touched' => true,
        'etags' => 'x',
        'dump_tokens' => 'corrupt',
    ));

    $refused = new ForumIndexScripted200Client(0, 'X');
    strictAssertSame(null, RuTrackerForumIndex::fetchDump(921, $refused),
        'the unreadable counter still buys no request');
    strictAssertSame(0, $refused->fetches, 'and no dump is fetched at all');

    $after = RuTrackerState::load('forumindex');
    foreach (fiForumBooks() as $book) {
        strictAssertSame(true, is_array($after[$book] ?? null),
            $book . ': the retirement leaves a book the next request can write into');
        strictAssertSame(false, array_key_exists(921, $after[$book]),
            $book . ': holding nothing at all for the retired forum');
    }
    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'dump_tokens is not an array',
        'and the token book being started again is named, since that is what a'
        . ' request in flight loses the forum on');

    strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    $published = RuTrackerForumIndex::fetchDump(921, new ForumIndexScripted200Client(66, 'C'));
    strictAssertSame(true, $published['fresh'], 'and the very next request publishes');
    strictAssertSame(66, RuTrackerForumIndex::cachedDump(921)[6868321]['seeders'],
        'with a body the cache can serve');
});

$suite->test('the publish gate compares the token for identity and the number for the reservation the book still records', function () {
    $pid = getmypid();
    $token = $pid . '-6a8f0a7af2b551.05220170';
    $gate = function ($state, $reservation, $held) {
        return strictInvoke('RuTrackerForumIndex', 'holdsReservation',
            array($state, 921, $reservation, $held));
    };

    strictAssertSame(true, $gate(array('dump_reservations' => array(921 => 3),
        'dump_tokens' => array(921 => $token)), 3, $token),
        'the pair this request actually reserved still licenses its publish');

    // The NUMBER half, which no test reached before: the token says WHICH
    // request this is, the number says which generation its body is published
    // AS and which document name it takes. A book that no longer records that
    // number -- replaced underneath the request, hand-edited, or an entry one
    // byte of which stopped reading -- cannot license naming a document after
    // it, and the token cannot see any of those.
    strictAssertSame(false, $gate(array('dump_reservations' => array(921 => 4),
        'dump_tokens' => array(921 => $token)), 3, $token),
        'a matching token does not publish under a number the book no longer holds');
    strictAssertSame(false, $gate(array('dump_reservations' => array(921 => '03'),
        'dump_tokens' => array(921 => $token)), 3, $token),
        'nor under an entry nobody can read');
    strictAssertSame(false, $gate(array('dump_reservations' => 7,
        'dump_tokens' => array(921 => $token)), 3, $token),
        'nor out of a book that is not a book');
    strictAssertSame(false, $gate(array('dump_tokens' => array(921 => $token)), 3, $token),
        'nor with no reservation recorded at all');

    // The TOKEN half, and it is IDENTITY. == accepts every one of these. On
    // the PHP 7.4 target a stored INTEGER equal to getmypid() is == to a token
    // beginning "<pid>-", because PHP 7 casts the non-numeric string to an int
    // for that comparison and gets the pid back; true == any non-empty string
    // on every version. Either would hand a request the forum on a value that
    // is not its token.
    foreach (array(
        'the pid the token begins with' => $pid,
        'that pid as a float' => (float) $pid,
        'true' => true,
        'the token inside an array' => array($token),
        'the one byte a corrupt book left behind' => substr($token, 0, 1),
        'nothing recorded' => null,
    ) as $label => $stored) {
        $state = array('dump_reservations' => array(921 => 3));
        if ($stored !== null) $state['dump_tokens'] = array(921 => $stored);
        strictAssertSame(false, $gate($state, 3, $token),
            $label . ': only the token itself licenses a publish, never a value merely equal to it');
    }
});

// Captures what reaches the SHARED APPLICATION LOG with $rutrackerCheckDebug
// deliberately FALSE -- the shipped default. TestLib's stub for
// ruTrackerChecker::logDebug is ungated and records into a static array, so a
// test that asserts on that array cannot tell the two channels apart, which is
// exactly how a permanent wedge stayed silent at default settings while its
// test passed. Only the file can tell them apart.
// TestLib's capture, at the shipped default. Four suites now read this same
// channel back and there is one capture between them, not one per file.
function fiCapturedAppLog($body)
{
    return testCapturedAppLog($body, false);
}

fiStateTest($suite, 'a forum already at the highest generation this platform can count to says so, instead of stalling in silence for ever', function () {
    // Measured before the fix: dump_reservations[921] = PHP_INT_MAX gave
    // answer=null fetches=0 logs=0, every cycle, for ever. The guard predates
    // this work but 8fa4f56f widened its reach -- a hand-edited document NAME
    // now seeds the floor from the same ceiling, and that shape published
    // normally at 011c24e3.
    //
    // It does not recover, deliberately -- and not because recovery is
    // impossible. One exists and keeps monotonicity: the retirement the
    // unreadable-counter branch runs, without the floor reseed. It is not
    // taken because PHP_INT_MAX is unreachable by counting (one fetch per
    // forum per cycle needs about 10^12 years), so the only way in is a hand
    // edit, and repairing that automatically would paper over a document
    // somebody edited by hand instead of telling them.
    //
    // Which is why this test asserts on the APPLICATION LOG with the debug
    // flag off, not on ruTrackerChecker::$logs. This is the one refusal in the
    // file that cannot heal itself, so it is the one that has to be visible at
    // the shipped default. Asserting the stubbed debug channel passed happily
    // while production said nothing at all.
    foreach (array(
        'the counter' => array('dump_reservations' => array(921 => PHP_INT_MAX)),
        'a hand-edited document name' => array(
            'dump_documents' => array(921 => 'forumdump-921-' . PHP_INT_MAX)),
    ) as $label => $seed) {
        ruTrackerChecker::reset();
        strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
        RuTrackerState::save('forumindex', $seed);

        $refused = new ForumIndexScripted200Client(1, 'A');
        $answer = 'unset';
        $written = fiCapturedAppLog(function () use ($refused, &$answer) {
            $answer = RuTrackerForumIndex::fetchDump(921, $refused);
        });
        strictAssertSame(null, $answer,
            $label . ': nothing can be counted past the ceiling, so no dump is fetched');
        strictAssertSame(0, $refused->fetches, $label . ': and no request is made at all');
        strictAssertSame(1, substr_count($written, 'highest generation'),
            $label . ': and an operator is told exactly once, in the application log,'
            . ' with $rutrackerCheckDebug at its shipped default of false');
        foreach (array('921', 'dump_reservations', 'forumdump-921-') as $needle)
            strictAssertTrue(strpos($written, $needle) !== false,
                $label . ': the line names ' . $needle . ', so the operator knows what to clear');
    }

    // The control: one below the ceiling still reserves and still publishes.
    ruTrackerChecker::reset();
    strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
    RuTrackerState::save('forumindex', array('dump_reservations' => array(921 => PHP_INT_MAX - 1)));
    $published = RuTrackerForumIndex::fetchDump(921, new ForumIndexScripted200Client(9, 'B'));
    strictAssertSame(true, $published['fresh'], 'one below the ceiling still publishes');
    strictAssertSame(PHP_INT_MAX, RuTrackerState::load('forumindex')['dump_generations'][921] ?? null,
        'as the last generation the counter can name');
});

fiStateTest($suite, 'a misses book that is not an array does not wedge the queue and does not fatal a completing crawl', function () {
    foreach (fiCorruptContainers() as $label => $container) {
        ruTrackerChecker::reset();
        RuTrackerState::save('forumindex', array('misses' => $container));

        RuTrackerForumIndex::queueTopic(777);

        strictAssertSame(array(777), RuTrackerForumIndex::takeQueuePeek(),
            $label . ': a book nobody can read suppresses no topic');
        strictAssertSame(true, is_array(RuTrackerState::load('forumindex')['misses'] ?? null),
            $label . ': and it is left able to hold a real miss record');
        strictAssertOneLogMatching(ruTrackerChecker::$logs, 'misses is not an array',
            $label . ': naming the document and the key');
    }

    // And the crawl's own write site. Forgetting a miss for a topic the crawl
    // just resolved is an unset(), which is an Error on BOTH target runtimes
    // when the book is a string -- taken after the tracker-wide sweep has
    // already been spent.
    ruTrackerChecker::reset();
    $hash = str_repeat('A', 40);
    RuTrackerState::save('forumindex', array('queue' => array(777), 'misses' => 'corrupt'));
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('d.multicall', true, false, array($hash, '777', '1106'));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('777', '1106'));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());

    $line = RuTrackerForumIndex::runCrawl(time(), function ($wanted) {
        return array('resolved' => array(777 => 2222), 'complete' => true);
    });

    strictAssertSame('wanted 1, resolved 1', $line,
        'the crawl completes and reports its resolution instead of dying on the book');
    strictAssertSame(array($hash, 'chk-forum', '2222'),
        rXMLRPCRequest::requestsFor('d.set_custom')[0]['commands'][0]->params,
        'and the forum it resolved still reaches the torrent that wanted it');
    strictAssertSame(true, is_array(RuTrackerState::load('forumindex')['misses'] ?? null),
        'with the book it had to clear a record out of left a book');
});

fiStateTest($suite, 'a queue_versions book that is not an array is started again rather than counted from', function () {
    // queue_versions is the one per-forum-shaped book already read through
    // isset() && is_array() at every site, so it neither fatals nor wedges:
    // it is discarded and rebuilt. This pins that, so a later change cannot
    // quietly make it the same shape as the books above.
    foreach (fiCorruptContainers() as $label => $container) {
        ruTrackerChecker::reset();
        RuTrackerState::save('forumindex', array('queue_versions' => $container));

        RuTrackerForumIndex::queueTopic(777);

        $state = RuTrackerState::load('forumindex');
        strictAssertSame(array(777), RuTrackerForumIndex::takeQueuePeek(),
            $label . ': the topic is queued, so a moved topic can still be re-resolved');
        strictAssertSame(true, is_array($state['queue_versions'] ?? null),
            $label . ': and the generations book is a book again');
        strictAssertSame(1, $state['queue_versions'][777] ?? null,
            $label . ': carrying this request\'s own generation, counted from nothing readable');
    }
});

exit($suite->run());
