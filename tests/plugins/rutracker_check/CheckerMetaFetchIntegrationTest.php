<?php

/**
 * Composition contract for the real ruTrackerChecker and RuTrackerMetaFetch.
 *
 * The focused suites replace opposite collaborators, so this file runs in its
 * own process and composes both production classes behind transport recorders.
 */

require __DIR__ . '/TestLib.php';

$checkerMetaFetchRoot = testFindRepoRoot();
$checkerMetaFetchPreviousCwd = getcwd();
chdir($checkerMetaFetchRoot . '/php');
require_once($checkerMetaFetchRoot . '/php/Torrent.php');
chdir($checkerMetaFetchPreviousCwd);

class CheckerMetaFetchTorrentEncoder extends Torrent
{
    public static function raw($value)
    {
        return self::encode($value);
    }
}

class rTorrent
{
    public static $magnets = array();
    public static $sources = array();
    public static $sends = array();
    public static $replacementMarker = '';
    public static $sourceReads = array();

    public static function reset()
    {
        self::$magnets = array();
        self::$sources = array();
        self::$sends = array();
        self::$replacementMarker = '';
        self::$sourceReads = array();
    }

    public static function sendMagnet($magnet, $isStart, $isAddPath, $directory, $label, $addition = null)
    {
        self::$magnets[] = compact(
            'magnet', 'isStart', 'isAddPath', 'directory', 'label', 'addition'
        );
        $matches = array();
        if (preg_match('/btih:([0-9A-Fa-f]{40})/', (string) $magnet, $matches) !== 1)
            return false;
        return strtoupper($matches[1]);
    }

    public static function getSource($hash)
    {
        self::$sourceReads[] = array(
            'hash' => (string) $hash,
            'requestCount' => count(rXMLRPCRequest::$requests),
        );
        // The real implementation reads the session file. Once d.erase has
        // run that file is gone, so a reordered harvest must fail loudly.
        foreach (rXMLRPCRequest::$requests as $request)
            foreach ($request['commands'] as $command)
                if (self::commandErasesHash($command, $hash))
                    return false;
        return array_key_exists($hash, self::$sources) ? self::$sources[$hash] : false;
    }

    private static function commandErasesHash($command, $hash)
    {
        if ($command->command === getCmd('d.erase'))
            return (string) $command->params === (string) $hash;
        if ($command->command !== getCmd('branch')
            || !is_array($command->params)
            || !isset($command->params[0], $command->params[2])
            || (string) $command->params[0] !== (string) $hash)
            return false;
        return strpos((string) $command->params[2], '$' . getCmd('d.erase=')) !== false;
    }

    public static function sendTorrent($torrent, $isStart, $isAddPath, $directory, $label,
        $saveTorrent, $isFast, $isNew = true, $addition = null)
    {
        self::$sends[] = compact(
            'torrent', 'isStart', 'isAddPath', 'directory', 'label',
            'saveTorrent', 'isFast', 'isNew', 'addition'
        );
        $prefix = getCmd('d.set_custom') . '=chk-replacement,';
        foreach ((array) $addition as $command)
            if (strpos((string) $command, $prefix) === 0) {
                self::$replacementMarker = substr((string) $command, strlen($prefix));
                break;
            }
        return strtoupper((string) $torrent->hash_info());
    }
}

require_once($checkerMetaFetchRoot . '/plugins/rutracker_check/detector.php');
require_once($checkerMetaFetchRoot . '/plugins/rutracker_check/metafetch.php');
eval(loadClassDefinition(
    $checkerMetaFetchRoot . '/plugins/rutracker_check/check.php',
    'ruTrackerChecker'
));

function cmfTorrentRaw($name, $announce, $comment = '')
{
    $torrent = array(
        'announce' => $announce,
        'info' => array(
            'length' => 1,
            'name' => $name,
            'piece length' => 16384,
            'pieces' => str_repeat("\0", 20),
        ),
    );
    if ($comment !== '') $torrent['comment'] = $comment;
    return CheckerMetaFetchTorrentEncoder::raw($torrent);
}

function cmfTorrent($name, $announce, $comment = '')
{
    // PHP 7.4 may probe binary metainfo as a path before decoding it.
    return @new Torrent(cmfTorrentRaw($name, $announce, $comment));
}

function cmfReset()
{
    rXMLRPCRequest::reset();
    rTorrent::reset();
    rTorrentSettings::get()->directory = '/data';
    $GLOBALS['topDirectory'] = '/data/';
    $GLOBALS['rutrackerMetaWait'] = 0;
    $GLOBALS['rutrackerMetaDeadline'] = 86400;
    $GLOBALS['saveUploadedTorrents'] = false;
    $GLOBALS['rutrackerCheckDebug'] = false;
}

function cmfRequestKeys()
{
    return array_map(function ($request) {
        return $request['key'];
    }, rXMLRPCRequest::$requests);
}

function cmfAssertNoQueuedResponses()
{
    $pending = array();
    foreach (rXMLRPCRequest::$responses as $key => $responses)
        if (count($responses)) $pending[$key] = count($responses);
    strictAssertSame(array(), $pending, 'every configured transport response was consumed');
}

function cmfQueueBegin($oldHash, $topicId = 42, $deadline = 87400)
{
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true, false, array($oldHash, (string) (int) $topicId, (string) $deadline, 1)
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
    rXMLRPCRequest::queue(
        array('d.set_custom', 'd.set_custom'), true, false, array()
    );
}

$suite = new StrictTestSuite();

$suite->test('the source guard recognizes an erase nested inside an atomic branch', function () {
    cmfReset();
    $hash = str_repeat('A', 40);
    rTorrent::$sources[$hash] = new stdClass();
    rXMLRPCRequest::queue('branch', true, false,
        array(RuTrackerAtomicOwnership::SENTINEL_ERASED));

    strictAssertSame(RuTrackerAtomicOwnership::ACTED,
        RuTrackerAtomicOwnership::erase(
            $hash,
            array('chk-meta-old' => str_repeat('B', 40))
        ),
        'the fixture records a successful conditional erase');
    strictAssertSame(false, rTorrent::getSource($hash),
        'a source read after nested d.erase is rejected like the real missing session file');
});

$suite->test('real UPTODATE projection writes state time and same stime', function () {
    cmfReset();
    $hash = str_repeat('A', 40);
    rXMLRPCRequest::queue(
        array('d.set_custom', 'd.set_custom', 'd.set_custom'), true, false, array()
    );

    $before = time();
    $result = ruTrackerChecker::setState($hash, ruTrackerChecker::STE_UPTODATE);
    $after = time();

    strictAssertSame(true, $result, 'the real projection reports the successful write');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom|d.set_custom');
    strictAssertSame(1, count($writes), 'UPTODATE is one three-field transport request');
    $commands = $writes[0]['commands'];
    strictAssertSame(array($hash, 'chk-state', (string) ruTrackerChecker::STE_UPTODATE),
        $commands[0]->params, 'state field and value');
    strictAssertSame($hash, $commands[1]->params[0], 'time write targets the same torrent');
    strictAssertSame('chk-time', $commands[1]->params[1], 'time field');
    strictAssertSame($hash, $commands[2]->params[0], 'stime write targets the same torrent');
    strictAssertSame('chk-stime', $commands[2]->params[1], 'stime field');
    $timestamp = $commands[1]->params[2];
    strictAssertTrue(is_string($timestamp) && ctype_digit($timestamp),
        'the projected timestamp is a decimal epoch');
    strictAssertSame($timestamp, $commands[2]->params[2],
        'chk-time and chk-stime use one captured clock value');
    strictAssertTrue((int) $timestamp >= $before && (int) $timestamp <= $after,
        'the projected clock lies inside the call interval');
    strictAssertSame(array('d.set_custom|d.set_custom|d.set_custom'), cmfRequestKeys(),
        'no readback or extra mutation is hidden behind a successful full response');
    cmfAssertNoQueuedResponses();
});

$suite->test('real begin bounds a zero-wait metadata fetch as pending', function () {
    cmfReset();
    $oldHash = str_repeat('A', 40);
    $newHash = str_repeat('B', 40);
    cmfQueueBegin($oldHash, 42, 87400);
    rXMLRPCRequest::queue('d.is_meta', true, false, array(1));

    $started = microtime(true);
    $result = RuTrackerMetaFetch::begin(
        $oldHash, $newHash, 42, 'http://bt.t-ru.org/ann?pk=integration-secret', 1000
    );
    $elapsed = microtime(true) - $started;

    strictAssertSame(ruTrackerChecker::STE_META_PENDING, $result,
        'a still-metadata stub returns the real checker pending status');
    strictAssertTrue($elapsed < 2, 'a configured zero wait polls once and does not sleep');
    strictAssertSame(1, count(rTorrent::$magnets), 'the real begin loads one magnet');
    strictAssertSame(0, count(rTorrent::$sends), 'pending metadata cannot stage a torrent');
    $polls = rXMLRPCRequest::requestsFor('d.is_meta');
    strictAssertSame(1, count($polls), 'zero wait performs exactly one metadata poll');
    strictAssertSame($newHash, $polls[0]['commands'][0]->params,
        'the bounded poll targets the successor hash');
    strictAssertSame(array(
        'd.hash',
        'd.get_custom|d.get_custom|d.get_custom|d.is_meta',
        'branch',
        'd.set_custom|d.set_custom',
        'd.is_meta',
    ), cmfRequestKeys(), 'the pending path uses only the expected real-class requests');
    cmfAssertNoQueuedResponses();
});

$suite->test('immediate metadata is harvested and committed by both real classes', function () {
    cmfReset();
    $announce = 'http://bt.t-ru.org/ann?pk=integration-secret';
    $oldTorrent = cmfTorrent('integration-old.bin', $announce,
        'https://rutracker.org/forum/viewtopic.php?t=42');
    $newTorrent = cmfTorrent('integration-new.bin', $announce);
    $oldHash = strtoupper((string) $oldTorrent->hash_info());
    $newHash = strtoupper((string) $newTorrent->hash_info());
    strictAssertTrue($oldHash !== $newHash, 'the fixture describes an actual replacement');
    rTorrent::$sources[$oldHash] = $oldTorrent;
    rTorrent::$sources[$newHash] = $newTorrent;

    cmfQueueBegin($oldHash, 42, 87400);
    rXMLRPCRequest::queue('d.is_meta', true, false, array(0));
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true, false, array($oldHash, '', '', '42', '87400', 0)
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));

    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue('d.views', true, false, array());
    rXMLRPCRequest::queue(array(
        'd.get_directory_base', 'd.get_custom1', 'd.get_throttle_name',
        'd.get_connection_seed', 'd.get_custom', 'd.get_custom',
    ), true, false, array('/data', 'integration-label', '', 'seed', '42', '1106'));
    rXMLRPCRequest::queue('branch', true, false, function ($commands) use ($oldHash, $newHash) {
        strictAssertSame(1, count($commands), 'run state is selected in one daemon command');
        strictAssertSame($oldHash, $commands[0]->params[0],
            'the commit-time state branch targets the predecessor');
        $matches = array();
        $pattern = '/' . preg_quote($newHash, '/') . '-started-\d+/';
        if (preg_match($pattern, (string) $commands[0]->params[2], $matches) !== 1)
            throw new RuntimeException('the started branch did not carry its exact successor marker');
        return array($matches[0]);
    });
    rXMLRPCRequest::queue('d.get_custom', true, false, function () {
        strictAssertTrue(rTorrent::$replacementMarker !== '',
            'sendTorrent recorded a replacement marker before the load poll');
        return array(rTorrent::$replacementMarker);
    });
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_state', 'd.is_open'),
        true, false, array('', '', 0, 0)
    );

    $result = RuTrackerMetaFetch::begin($oldHash, $newHash, 42, $announce, 1000);

    strictAssertSame(null, $result,
        'real createTorrent success and real MetaFetch success agree on null');
    strictAssertSame(1, count(rTorrent::$magnets), 'the fetch loads exactly one magnet');
    strictAssertSame(1, count(rTorrent::$sends), 'the harvest stages exactly one real Torrent');
    strictAssertSame($newHash,
        strtoupper((string) rTorrent::$sends[0]['torrent']->hash_info()),
        'the staged Torrent is the harvested successor');
    strictAssertTrue(preg_match('/^[0-9a-f]{32}$/', rTorrent::$replacementMarker) === 1,
        'the real transaction generated and sent one ownership nonce');

    $sourceReadAt = null;
    foreach (rTorrent::$sourceReads as $read)
        if ($read['hash'] === $newHash) {
            $sourceReadAt = $read['requestCount'];
            break;
        }
    $stubEraseAt = null;
    foreach (rXMLRPCRequest::$requests as $index => $request)
        foreach ($request['commands'] as $command)
            if ($command->command === getCmd('branch')
                && isset($command->params[0], $command->params[2])
                && $command->params[0] === $newHash
                && strpos((string) $command->params[2], '$' . getCmd('d.erase=')) !== false) {
                $stubEraseAt = $index;
                break 2;
            }
    strictAssertTrue($sourceReadAt !== null, 'harvest reads the service source');
    strictAssertTrue($stubEraseAt !== null, 'harvest later issues a conditional service erase');
    strictAssertTrue($sourceReadAt <= $stubEraseAt,
        'the service session source is harvested before its nested atomic erase is sent');

    $polls = rXMLRPCRequest::requestsFor('d.is_meta');
    strictAssertSame(1, count($polls),
        'arrival is observed by begin in the wait loop');
    foreach ($polls as $poll)
        strictAssertSame($newHash, $poll['commands'][0]->params,
            'metadata poll targets the successor');

    strictAssertSame(array(
        'd.hash',
        'd.get_custom|d.get_custom|d.get_custom|d.is_meta',
        'branch',
        'd.set_custom|d.set_custom',
        'd.is_meta',
        'd.get_custom|d.get_custom|d.get_custom|d.get_custom|d.get_custom|d.is_meta',
        'branch',
        'd.hash',
        'd.views',
        'd.get_directory_base|d.get_custom1|d.get_throttle_name|d.get_connection_seed|d.get_custom|d.get_custom',
        'branch',
        'd.get_custom',
        'branch',
        'branch',
        'd.get_custom|d.get_custom|d.get_state|d.is_open',
    ), cmfRequestKeys(), 'the full composition completes without an unexpected fallback branch');
    cmfAssertNoQueuedResponses();
});

$suite->test('real download guards reject non-200 and malformed bodies before mutation', function () {
    $announce = 'http://bt.t-ru.org/ann?pk=integration-secret';
    $oldTorrent = cmfTorrent('guard-old.bin', $announce);
    $oldHash = strtoupper((string) $oldTorrent->hash_info());
    $validPayload = cmfTorrentRaw('guard-new.bin', $announce);
    $cases = array(
        'non-200 response' => array(503, $validPayload),
        'HTTP-200 malformed body' => array(200, '<html>not metainfo</html>'),
    );

    foreach ($cases as $label => $fixture) {
        cmfReset();
        $client = new stdClass();
        $client->status = $fixture[0];
        $client->results = $fixture[1];

        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
            ruTrackerChecker::createTorrentFromDownload($client, $oldHash, $oldTorrent),
            $label . ' is rejected by the real checker guard');
        strictAssertSame(array(), cmfRequestKeys(),
            $label . ' causes no XMLRPC read or mutation');
        strictAssertSame(0, count(rTorrent::$magnets),
            $label . ' cannot load a magnet');
        strictAssertSame(0, count(rTorrent::$sends),
            $label . ' cannot stage a replacement');
        cmfAssertNoQueuedResponses();
    }
});

exit($suite->run());
