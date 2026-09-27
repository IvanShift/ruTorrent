<?php

/**
 * Tests for plugins/rutracker_check/metafetch.php.
 *
 * rTorrent is replaced wholesale by a recording double (defined before
 * requiring metafetch.php, exactly like TestLib swaps out Snoopy) since
 * RuTrackerMetaFetch::begin() only needs to observe what it hands to
 * sendMagnet(), never the real load-command machinery.
 */

define('TESTLIB_HANDLER_STUBS', 1);
require_once(__DIR__ . '/TestLib.php');
require_once(testFindRepoRoot() . '/tests/php/UpstreamStampingTorrentFixture.php');

class rTorrent
{
    public static $magnets = array();
    public static $sendResult = null;
    public static $source = null;
    // Per-hash override for the harvest tests, which need distinct old/new
    // metainfo; unset for a hash and getSource() falls back to $source, same
    // as every begin()-only test above expects.
    public static $sourcesByHash = array();
    public static $sourceSequencesByHash = array();

    public static function sendMagnet($magnet, $isStart, $isAddPath, $directory, $label, $addition = null)
    {
        self::$magnets[] = compact('magnet', 'isStart', 'directory', 'label', 'addition');
        return self::$sendResult;
    }

    public static function getSource($hash)
    {
        // Mirrors the real getSource(): d.erase deletes the session file, so
        // once an erase for this hash has actually gone through the XMLRPC
        // double, the bytes are gone too -- a reordered harvest that reads
        // after erasing must see that, not a fixture answering from memory.
        if (self::erased($hash)) return false;
        if (isset(self::$sourceSequencesByHash[$hash])
            && count(self::$sourceSequencesByHash[$hash]))
            return array_shift(self::$sourceSequencesByHash[$hash]);
        return array_key_exists($hash, self::$sourcesByHash) ? self::$sourcesByHash[$hash] : self::$source;
    }

    private static function erased($hash)
    {
        foreach (rXMLRPCRequest::$requests as $request) {
            foreach ($request['commands'] as $command) {
                if ($command->command === getCmd('d.erase') && (string) $command->params === (string) $hash) {
                    return true;
                }
                if ($command->command === 'branch' && isset($command->params[0])
                    && (string) $command->params[0] === (string) $hash
                    && isset($command->params[2])
                    && strpos($command->params[2], 'd.erase') !== false) {
                    return true;
                }
            }
        }
        return false;
    }
}

$GLOBALS['topDirectory'] = '/data/';
// metafetch now consults the detector's tracker pattern to tell the magnet's
// own tracker row from the dht:// one rTorrent adds beside it.
require_once(testFindRepoRoot() . '/plugins/rutracker_check/detector.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/metafetch.php');

$suite = new StrictTestSuite();
$oldHash = str_repeat('A', 40);
$newHash = str_repeat('B', 40);

function mfQueueCollisionOwner($stubOwner, $replacementMarker = '', $replacementRecord = '')
{
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_custom'), true, false,
        array($stubOwner, $replacementMarker, $replacementRecord));
}

function mfQueueCollisionOwnerFailure()
{
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_custom'), false, false, array());
}

function mfMessages()
{
    return ruTrackerChecker::callsFor('setMessage');
}

function mfCreates()
{
    return ruTrackerChecker::callsFor('createTorrent');
}

// The harvest already holds the successor's decoded metainfo -- it patched the
// announce and comment on it -- so that object is what crosses the replacement
// boundary. Serialising it and decoding the bytes again would parse the same
// metainfo twice, so this insists on the object itself.
function mfHandedOverTorrent($create)
{
    strictAssertTrue($create['arguments'][0] instanceof Torrent,
        'the harvested Torrent is handed over, not re-serialised bytes');
    return $create['arguments'][0];
}

function mfPluginMarker()
{
    return '0123456789abcdef0123456789abcdef';
}

function mfRequestsForErase($targetHash = null)
{
    $matched = array();
    foreach (rXMLRPCRequest::$requests as $request) {
        if ($request['key'] === 'd.erase') {
            if ($targetHash === null || (isset($request['commands'][0]->params) && (string) $request['commands'][0]->params === (string) $targetHash)) {
                $matched[] = $request;
            }
        } elseif ($request['key'] === 'branch') {
            $cmd = $request['commands'][0];
            if (isset($cmd->params[2]) && strpos($cmd->params[2], 'd.erase') !== false) {
                if ($targetHash === null || (isset($cmd->params[0]) && (string) $cmd->params[0] === (string) $targetHash)) {
                    $matched[] = $request;
                }
            }
        }
    }
    return $matched;
}

function mfRequestsForRunState($targetHash = null)
{
    $matched = array();
    foreach (rXMLRPCRequest::$requests as $request) {
        if ($request['key'] === 'd.open|d.start' || $request['key'] === 'd.open') {
            if ($targetHash === null || (isset($request['commands'][0]->params) && (string) $request['commands'][0]->params === (string) $targetHash)) {
                $matched[] = $request;
            }
        } elseif ($request['key'] === 'branch') {
            $cmd = $request['commands'][0];
            if (isset($cmd->params[2]) && (strpos($cmd->params[2], 'd.open') !== false || strpos($cmd->params[2], 'd.start') !== false)) {
                if ($targetHash === null || (isset($cmd->params[0]) && (string) $cmd->params[0] === (string) $targetHash)) {
                    $matched[] = $request;
                }
            }
        }
    }
    return $matched;
}

function mfRequestsForClear($targetHash = null)
{
    $matched = array();
    foreach (rXMLRPCRequest::$requests as $request) {
        if ($request['key'] === 'd.set_custom|d.set_custom' || $request['key'] === 'd.set_custom') {
            $matched[] = $request;
        } elseif ($request['key'] === 'branch') {
            $cmd = $request['commands'][0];
            if (isset($cmd->params[2]) && strpos($cmd->params[2], 'd.set_custom') !== false) {
                $matched[] = $request;
            }
        }
    }
    return $matched;
}

// Queue helper for pump() reading owner on newHash
function mfQueueStubOwner($newHash, $stubOwner, $topic = '6879823', $until = '999999', $isMeta = 0, $marker = '', $record = '')
{
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true,
        false,
        array($stubOwner, $marker, $record, (string) $topic, (string) $until, $isMeta)
    );
}

function mfQueueActivation($marker, $record, $state = 0, $open = 0, $ok = true, $fault = false)
{
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_state', 'd.is_open'),
        $ok,
        $fault,
        $ok ? array($marker, $record, $state, $open) : array()
    );
}

// The queue prefix shared by every test that walks pump() up to a stub whose
// metadata has arrived: the old torrent's markers, the 6-custom owner read,
// atomic erase of stub and settled fallback activation check.
function mfQueueArrived($newHash, $topic = '6879823', $until = '999999')
{
    global $oldHash;
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false,
        array($newHash, (string) $until)); // chk-meta-new, chk-meta-until (old torrent)
    mfQueueStubOwner($newHash, $oldHash, $topic, $until, 0); // metadata arrived (is_meta=0)
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED)); // erase stub
    mfQueueActivation('', ''); // restoreReplacement settled
}

// The magnet's tr= is a decision to SEND, so the strict host test belongs here
// too.
$suite->test('begin refuses to build a magnet around a foreign announce host', function () use ($oldHash, $newHash) {
    foreach (array(
        'a lookalike domain'      => 'http://rutracker.evil.example/announce',
        'an internal address'     => 'http://10.0.0.5:8080/announce?x=rutracker.',
        'a subdomain of a fake'   => 'http://bt.t-ru.org.evil.example/ann',
        'no host at all'          => 'not a url',
    ) as $label => $announce) {
        ruTrackerChecker::reset();
        rTorrent::$magnets = array();
        rXMLRPCRequest::reset();

        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
            RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, $announce, 1000),
            $label . ': the fetch is refused, retryably');
        strictAssertSame(0, count(rTorrent::$magnets),
            $label . ': and no magnet is built around it');
        strictAssertSame(0, count(rXMLRPCRequest::$requests),
            $label . ': the host is judged before anything is asked of rTorrent');
    }

    foreach (array('http://bt.t-ru.org/ann?pk=s3cr3t', 'http://bt4.t-ru.org/ann',
                   'http://bt.t-ru.org./ann') as $announce) {
        ruTrackerChecker::reset();
        rTorrent::$magnets = array();
        ruTrackerChecker::queueResult('torrentExists', true);
        mfQueueCollisionOwner('');
        strictAssertSame(ruTrackerChecker::STE_NOT_NEED,
            RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, $announce, 1000),
            $announce . ' reaches the ordinary flow');
    }
});

$suite->test('begin refuses when the new hash already exists', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::queueResult('torrentExists', true);
    mfQueueCollisionOwner(''); // neither our stub nor our staged copy
    $state = RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);
    strictAssertSame(ruTrackerChecker::STE_NOT_NEED, $state, 'the topic\'s current version is already present');
    strictAssertSame(array($newHash), ruTrackerChecker::callsFor('torrentExists')[0]['arguments'],
        'begin delegates its collision check to the checker seam');
    strictAssertSame(0, count(rTorrent::$magnets), 'no magnet loaded');
    strictAssertSame(1, count(mfMessages()), 'exactly one chk-msg write');
    strictAssertSame(ruTrackerChecker::CHKMSG_SUPERSEDED . '|' . $newHash,
        mfMessages()[0]['arguments'][1], 'superseded token carries the successor hash');
    strictAssertSame(array($oldHash, ruTrackerChecker::CHKMSG_SUPERSEDED . '|' . $newHash),
        ruTrackerChecker::callsFor('setMessage')[0]['arguments'],
        'the terminal token is handed to the observational message seam');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
        'the checker double does not copy the production message write');

    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'nothing left to fetch',
        'the already-present successor says so');
    strictAssertLogsClean(ruTrackerChecker::$logs, 's3cr3t', 'begin');
});

$suite->test('begin loads a stopped magnet with inline markers and starts the stub', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = $newHash;
    ruTrackerChecker::queueResult('awaitMetadata', false);
    ruTrackerChecker::queueResult('torrentExists', false);                // collision check: missing
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true,
        false,
        array($oldHash, '6879823', (string) (1000 + 86400), 1)
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array()); // old torrent marks

    $state = RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);
    strictAssertSame(ruTrackerChecker::STE_META_PENDING, $state, 'pending state');
    strictAssertSame(array($newHash), ruTrackerChecker::callsFor('awaitMetadata')[0]['arguments'],
        'begin delegates the successor hash to the checker wait seam');
    strictAssertSame(1, count(rTorrent::$magnets), 'one magnet');
    $sent = rTorrent::$magnets[0];
    strictAssertSame(false, $sent['isStart'], 'loaded stopped (plain load, not load.start)');
    strictAssertSame('/data/.chk-meta', $sent['directory'], 'service directory under topDirectory');
    strictAssertTrue(strpos($sent['magnet'], 'magnet:?xt=urn:btih:' . $newHash) === 0, 'magnet target');
    strictAssertTrue(strpos($sent['magnet'], rawurlencode('http://bt.t-ru.org/ann?pk=s3cr3t')) !== false,
        'tr= keeps the old announce with passkey');
    $additions = implode(' ', $sent['addition']);
    foreach (array('chk-meta-old,' . $oldHash, 'chk-meta-topic,6879823', 'chk-meta-until,' . (1000 + 86400)) as $mark)
        strictAssertTrue(strpos($additions, $mark) !== false, "inline marker {$mark}");
    strictAssertSame(RuTrackerMetaFetch::SERVICE_LABEL, $sent['label'],
        'the stub carries the service label, and keeps it after metadata arrives');
    strictAssertSame('.', substr(RuTrackerMetaFetch::SERVICE_LABEL, 0, 1),
        'a leading dot is the convention that marks a label as service-only');

    $loaded = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'loaded the metadata stub',
        'the successful load says so');
    strictAssertTrue(strpos($loaded, (string) (1000 + 86400)) !== false,
        'and names the deadline it will wait until: ' . $loaded);
    strictAssertLogsClean(ruTrackerChecker::$logs, 's3cr3t', 'begin');
});

$suite->test('begin floors a negative metadata deadline at the current time', function () use ($oldHash, $newHash) {
    $hadDeadline = array_key_exists('rutrackerMetaDeadline', $GLOBALS);
    $originalDeadline = $hadDeadline ? $GLOBALS['rutrackerMetaDeadline'] : null;
    try {
        foreach (array(0, -5) as $configuredDeadline) {
            ruTrackerChecker::reset();
            $GLOBALS['rutrackerMetaDeadline'] = $configuredDeadline;
            rTorrent::$magnets = array();
            rTorrent::$sendResult = $newHash;
            ruTrackerChecker::queueResult('awaitMetadata', false);
            ruTrackerChecker::queueResult('torrentExists', false);
            rXMLRPCRequest::queue(
                array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
                true, false, array($oldHash, '6879823', '1000', 1)
            );
            rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
            rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

            strictAssertSame(ruTrackerChecker::STE_META_PENDING,
                RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823,
                    'http://bt.t-ru.org/ann?pk=s3cr3t', 1000),
                'a nonpositive setting remains a valid pending fetch');
            strictAssertSame(1, count(rTorrent::$magnets), 'one service magnet is loaded');
            $additions = implode(' ', rTorrent::$magnets[0]['addition']);
            strictAssertTrue(strpos($additions, 'chk-meta-until,1000') !== false,
                'a nonpositive setting cannot make the service deadline precede the load');
        }
    } finally {
        if ($hadDeadline) $GLOBALS['rutrackerMetaDeadline'] = $originalDeadline;
        else unset($GLOBALS['rutrackerMetaDeadline']);
    }
});

$suite->test('begin fails closed when the load never materialises', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = $newHash;
    ruTrackerChecker::queueResult('torrentExists', false);  // collision: missing
    $state = RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'retryable failure');

    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'never appeared',
        'an expired wait says so');
    strictAssertLogsClean(ruTrackerChecker::$logs, 's3cr3t', 'begin');
});

$suite->test('begin returns STE_ERROR when the collision check itself fails', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = $newHash;
    ruTrackerChecker::queueResult('torrentExists', null);   // transport failure
    $state = RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);
    strictAssertSame(ruTrackerChecker::STE_ERROR, $state, 'transport failure surfaces as error, not retried as a load');
    strictAssertSame(0, count(rTorrent::$magnets), 'no magnet loaded');

    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'already in the client',
        'an unreadable collision probe says so');
    strictAssertLogsClean(ruTrackerChecker::$logs, 's3cr3t', 'begin');
});

$suite->test('begin returns STE_ERROR when sendMagnet fails', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = false;
    ruTrackerChecker::queueResult('torrentExists', false);
    $state = RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);
    strictAssertSame(ruTrackerChecker::STE_ERROR, $state, 'a rejected load surfaces as error');

    $refused = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'refused the service load',
        'a refused load names the service directory it was refused for');
    strictAssertTrue(strpos($refused, '/data/.chk-meta') !== false,
        'the one thing worth checking is in the line: ' . $refused);
    strictAssertLogsClean(ruTrackerChecker::$logs, 's3cr3t', 'begin');
});

$suite->test('begin does not start, stamp or erase a foreign item at the same hash', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = $newHash;
    $foreignOldHash = str_repeat('C', 40);
    ruTrackerChecker::queueResult('torrentExists', false);
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true,
        false,
        array($foreignOldHash, '6879823', (string) (1000 + 86400), 1)
    );

    $state = RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'foreign item is retryable, not fatal');
    strictAssertSame(0, count(mfRequestsForRunState()), 'foreign item not started');
    strictAssertSame(0, count(mfRequestsForErase()), 'foreign item not erased');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')), 'old torrent not stamped');

    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'belongs to someone else',
        'a foreign item at the same hash says so');
    strictAssertLogsClean(ruTrackerChecker::$logs, 's3cr3t', 'begin');
});

$suite->test('begin erases the stub and skips stamping when d.start fails', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = $newHash;
    ruTrackerChecker::queueResult('torrentExists', false);
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true,
        false,
        array($oldHash, '6879823', (string) (1000 + 86400), 1)
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_UNCONFIRMED));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));

    $state = RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'failed start is retryable, not a stuck orphan');
    $erased = mfRequestsForErase();
    strictAssertSame(1, count($erased), 'stub erased after failed start');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')), 'old torrent not stamped');

    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'could not confirm the metadata stub',
        'an unstartable stub says so');
    strictAssertLogsClean(ruTrackerChecker::$logs, 's3cr3t', 'begin');
});

$suite->test('pump harvests in the mandated order once metadata arrived', function () use ($oldHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::queueResult('createTorrent', null); // success contract
    rTorrent::$sourcesByHash = array();
    $fixture = @new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    $newHash = strtoupper($fixture->hash_info());
    rTorrent::$source = $fixture;

    mfQueueArrived($newHash);

    $state = RuTrackerMetaFetch::pump($oldHash, 1000);
    strictAssertSame(null, $state, 'createTorrent success passthrough');
    $creates = mfCreates();
    strictAssertSame(1, count($creates), 'createTorrent called once');
    $payload = mfHandedOverTorrent($creates[0]);
    strictAssertSame($fixture, $payload,
        'the very object the harvest patched is the one handed to createTorrent');
    strictAssertSame('https://rutracker.org/forum/viewtopic.php?t=6879823', $payload->comment(), 'comment restored');
    strictAssertSame($newHash, strtoupper($payload->hash_info()), 'comment patch does not change info_hash');
    strictAssertSame($oldHash, $creates[0]['arguments'][1], 'replacement of the old torrent');
    strictAssertSame(1, count(mfRequestsForErase($newHash)), 'atomic erase happened');
});

$suite->test('harvest backfills an empty announce from the old torrent before erasing the stub', function () use ($oldHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::queueResult('createTorrent', null);
    rTorrent::$source = null;
    $newFixture = @new Torrent(strictTorrentRaw('Youjo Senki II', ''));
    $newHash = strtoupper($newFixture->hash_info());
    rTorrent::$sourcesByHash = array(
        $newHash => $newFixture,
        $oldHash => @new Torrent(strictTorrentRaw('Youjo Senki', 'http://bt.t-ru.org/ann?pk=s3cr3t')),
    );

    mfQueueArrived($newHash);

    $state = RuTrackerMetaFetch::pump($oldHash, 1000);
    strictAssertSame(null, $state, 'harvest still succeeds with a backfilled announce');
    $payload = mfHandedOverTorrent(mfCreates()[0]);
    strictAssertSame('http://bt.t-ru.org/ann?pk=s3cr3t', $payload->announce(), 'announce backfilled from the old torrent');
    strictAssertSame('https://rutracker.org/forum/viewtopic.php?t=6879823', $payload->comment(), 'comment still restored');
});

$suite->test('a session copy that could not be read defers the harvest instead of dropping the stub', function () use ($oldHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::queueResult('awaitMetadata', false);
    rTorrent::$source = null;
    rTorrent::$sourcesByHash = array();   // getSource answers false for every hash
    $newHash = str_repeat('D', 40);

    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 0);

    strictAssertSame(ruTrackerChecker::STE_META_PENDING, RuTrackerMetaFetch::pump($oldHash, 1000),
        'the fetch is left alone for the next cycle');
    strictAssertSame(0, count(mfRequestsForErase()), 'and the stub is NOT erased');
    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'reason=session-unreadable',
        'the reason is named, and distinguished from failed validation');
});

$suite->test('a replacement is never committed without the announce it could not read', function () use ($oldHash) {
    ruTrackerChecker::reset();
    rTorrent::$source = null;
    $newFixture = @new Torrent(strictTorrentRaw('Youjo Senki II', ''));
    $newHash = strtoupper($newFixture->hash_info());
    rTorrent::$sourcesByHash = array($newHash => $newFixture);

    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 0);

    strictAssertSame(ruTrackerChecker::STE_META_PENDING, RuTrackerMetaFetch::pump($oldHash, 1000),
        'the fetch waits for a cycle that can read the predecessor');
    strictAssertSame(0, count(mfCreates()),
        'nothing is committed: a trackerless replacement is worse than no replacement');
    strictAssertSame(0, count(mfRequestsForErase()), 'and the stub is kept');
    strictAssertEnglish(strictAssertOneLogMatching(ruTrackerChecker::$logs, 'no announce',
        'the reason is named'), 'the missing-announce line');
});

$suite->test('an unreadable service session is retained even after the deadline', function () use ($oldHash) {
    ruTrackerChecker::reset();
    rTorrent::$source = null;
    rTorrent::$sourcesByHash = array();
    rTorrent::$sourceSequencesByHash = array();
    $newHash = str_repeat('D', 40);

    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '500'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '500', 0);
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        RuTrackerMetaFetch::pump($oldHash, 1000),
        'the expired generation returns to the retry queue without deleting an unreadable item');
    strictAssertSame(0, count(mfRequestsForErase($newHash)),
        'unreadable session bytes are not sufficient authority to erase the torrent');
    strictAssertOneLogMatching(ruTrackerChecker::$logs,
        'outcome=deadline-timeout reason=session-unreadable',
        'the safe deadline reason distinguishes unreadable bytes from stale bytes');
});

$suite->test('owned harvest deferrals expire at the deadline', function () use ($oldHash) {
    $newFixture = @new Torrent(strictTorrentRaw('Youjo Senki II', ''));
    $emptyAnnounce = strtoupper($newFixture->hash_info());
    $completeFixture = @new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    $eraseSticks = strtoupper($completeFixture->hash_info());

    foreach (array(
        'the predecessor\'s copy cannot be read'  => array('sources' => array($emptyAnnounce => $newFixture),
                                                          'hash' => $emptyAnnounce, 'erase_acted' => true,
                                                          'reason' => 'could never be read'),
        'the service item cannot be erased'       => array('sources' => array($eraseSticks => $completeFixture),
                                                          'hash' => $eraseSticks, 'erase_acted' => false,
                                                          'expected_erases' => 2,
                                                          'reason' => 'could never be erased'),
    ) as $label => $case) {
        ruTrackerChecker::reset();
        rTorrent::$sourcesByHash = $case['sources'];
        rTorrent::$sourceSequencesByHash = array();
        rTorrent::$source = null;
        $h = $case['hash'];

        rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($h, '500')); // expired
        mfQueueStubOwner($h, $oldHash, '6879823', '500', 0);
        if ($case['erase_acted']) {
            rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));
            rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));
            strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
                RuTrackerMetaFetch::pump($oldHash, 1000),
                $label . ': expired deferral returns to the queue');
            strictAssertSame(1, count(mfRequestsForErase($h)),
                $label . ': and the stub is erased');
        } else {
            rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED));
            strictAssertSame(ruTrackerChecker::STE_META_PENDING,
                RuTrackerMetaFetch::pump($oldHash, 1000),
                $label . ': unerasable stub stays pending');
            strictAssertSame(isset($case['expected_erases']) ? $case['expected_erases'] : 1, count(mfRequestsForErase($h)),
                $label . ': erase was attempted');
        }
    }
});

$suite->test('harvest carries the old announce-list across, not just the single announce', function () use ($oldHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::queueResult('createTorrent', null);
    rTorrent::$source = null;
    $newFixture = @new Torrent(strictTorrentRaw('Youjo Senki II', ''));
    $newHash = strtoupper($newFixture->hash_info());
    $oldFixture = @new Torrent(strictTorrentRaw('Youjo Senki', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    $oldFixture->announce_list(array(
        array('http://bt.t-ru.org/ann?pk=s3cr3t', 'http://bt2.t-ru.org/ann'),
        array('http://bt3.t-ru.org/ann'),
    ));
    rTorrent::$sourcesByHash = array($newHash => $newFixture, $oldHash => $oldFixture);

    mfQueueArrived($newHash);

    $state = RuTrackerMetaFetch::pump($oldHash, 1000);
    strictAssertSame(null, $state, 'harvest still succeeds');
    $payload = mfHandedOverTorrent(mfCreates()[0]);
    strictAssertSame('http://bt.t-ru.org/ann?pk=s3cr3t', $payload->announce(), 'announce preserved');
    strictAssertSame(array(
        array('http://bt.t-ru.org/ann?pk=s3cr3t', 'http://bt2.t-ru.org/ann'),
        array('http://bt3.t-ru.org/ann'),
    ), $payload->announce_list(), 'all tiers and backup URLs carried across');
});

$suite->test('a stale session hash before the deadline remains pending without erasing the stub', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$source = @new Torrent(strictTorrentRaw('Youjo Senki', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    rTorrent::$sourceSequencesByHash = array();
    ruTrackerChecker::queueResult('awaitMetadata', false);

    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 0);

    $state = RuTrackerMetaFetch::pump($oldHash, 1000);
    strictAssertSame(ruTrackerChecker::STE_META_PENDING, $state,
        'the asynchronous session-file replacement gets another bounded chance');
    strictAssertSame(0, count(mfRequestsForErase($newHash)), 'the stale stub is not erased before deadline');
    strictAssertSame(0, count(mfCreates()), 'and never handed to createTorrent');
    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'reason=session-hash-stale',
        'the transient mismatch has a stable safe reason code');
    strictAssertSame(array($newHash), ruTrackerChecker::callsFor('awaitMetadata')[0]['arguments'],
        'harvest asks the shared bounded readiness check to re-read the transition');
});

$suite->test('a session hash that turns valid is harvested in the same pump', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::queueResult('awaitMetadata', true);
    ruTrackerChecker::queueResult('createTorrent', null);
    rTorrent::$source = null;
    $stale = @new Torrent(strictTorrentRaw('stale bytes', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    $valid = @new Torrent(strictTorrentRaw('successor bytes', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    $validHash = strtoupper($valid->hash_info());
    rTorrent::$sourcesByHash = array($validHash => $valid);
    rTorrent::$sourceSequencesByHash = array($validHash => array($stale, $valid));

    mfQueueArrived($validHash);

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000),
        'the pump re-reads and commits after readiness becomes durable');
    strictAssertSame(1, count(mfRequestsForErase($validHash)),
        'only the now-valid owned service torrent is erased');
    strictAssertSame(1, count(mfCreates()), 'the durable successor is handed to createTorrent once');
});

$suite->test('an owned stale session is erased only after its deadline', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$source = @new Torrent(strictTorrentRaw('Youjo Senki', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    rTorrent::$sourceSequencesByHash = array();

    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '500'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '500', 0);
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        RuTrackerMetaFetch::pump($oldHash, 1000), 'expired owned mismatch is retryable');
    strictAssertSame(1, count(mfRequestsForErase($newHash)),
        'the exact owned service item may be erased after deadline');
    strictAssertSame(0, count(mfCreates()), 'stale bytes are never handed to createTorrent');
    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'session-hash-timeout',
        'the final timeout is distinct from the transient stale state');
});

$suite->test('dropStub preserves the durable claim when erase cannot be confirmed', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 1);
    rXMLRPCRequest::queue(
        array('d.get_tracker_size', 't.multicall', 'd.get_message'),
        true,
        false,
        array(1, 'http://bt.t-ru.org/ann?pk=x', 4, 1, 'Tracker: [Failure reason "torrent not registered"]')
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED)); // erase skipped

    $state = RuTrackerMetaFetch::pump($oldHash, 1000);
    strictAssertSame(ruTrackerChecker::STE_META_PENDING, $state, 'unconfirmed erase stays pending');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')), 'markers preserved');
});

$suite->test('harvest stays pending when the erased stub is still present', function () use ($oldHash) {
    ruTrackerChecker::reset();
    $fixture = @new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    $newHash = strtoupper($fixture->hash_info());
    rTorrent::$source = $fixture;

    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 0);
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED)); // erase skipped

    $state = RuTrackerMetaFetch::pump($oldHash, 1000);
    strictAssertSame(ruTrackerChecker::STE_META_PENDING, $state, 'stay pending when erase did not act');
    strictAssertSame(0, count(mfCreates()), 'never hand to createTorrent when stub still present');
});

$suite->test('pump aborts early when the tracker rejects the new hash', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 1);
    rXMLRPCRequest::queue(
        array('d.get_tracker_size', 't.multicall', 'd.get_message'),
        true,
        false,
        array(1, 'http://bt.t-ru.org/ann?pk=x', 4, 1, 'Tracker: [Failure reason "torrent not registered"]')
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

    $state = RuTrackerMetaFetch::pump($oldHash, 1000);
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'rejection aborts the fetch');
    strictAssertSame(1, count(mfRequestsForErase($newHash)), 'the rejected stub is erased');
});

$suite->test('pump enforces the deadline', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '500')); // deadline 500 < now 1000
    mfQueueStubOwner($newHash, $oldHash, '6879823', '500', 1);
    rXMLRPCRequest::queue(
        array('d.get_tracker_size', 't.multicall', 'd.get_message'),
        true,
        false,
        array(1, 'http://bt.t-ru.org/ann?pk=x', 0, 1, '')
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

    $state = RuTrackerMetaFetch::pump($oldHash, 1000);
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'expired fetch returns to the queue');
    strictAssertSame(1, count(mfRequestsForErase($newHash)), 'the expired stub is erased');
    strictAssertSame(1, count(mfMessages()), 'the expired stub clears the message once');
    strictAssertSame(array($oldHash, ''), mfMessages()[0]['arguments'],
        'dropping the expired stub clears its predecessor message');
});

$suite->test('pump keeps waiting while the stub is healthy', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 1);
    rXMLRPCRequest::queue(
        array('d.get_tracker_size', 't.multicall', 'd.get_message'),
        true,
        false,
        array(1, 'http://bt.t-ru.org/ann?pk=x', 0, 1, '')
    );

    strictAssertSame(ruTrackerChecker::STE_META_PENDING, RuTrackerMetaFetch::pump($oldHash, 1000), 'still pending');
    strictAssertSame(0, count(mfRequestsForErase()), 'nothing is erased');
});

$suite->test('pump clears state when the stub vanished', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    // 6-custom read fails:
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        false,
        false,
        array()
    );
    ruTrackerChecker::queueResult('torrentExists', false); // stub vanished
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, RuTrackerMetaFetch::pump($oldHash, 1000),
        'retryable: stub gone');
});

$suite->test('pump leaves a malformed durable generation retryable and untouched', function () use ($oldHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('NOT-A-HASH', '999999'));

    strictAssertSame(ruTrackerChecker::STE_META_PENDING, RuTrackerMetaFetch::pump($oldHash, 1000),
        'malformed durable ownership remains retryable');
    strictAssertSame(1, count(rXMLRPCRequest::$requests),
        'pump stops after reading the malformed generation');
    strictAssertSame(0, count(mfRequestsForClear()),
        'malformed bytes never authorize an ownerless clear');
});

// The refusal above is the one in this file that CANNOT heal. Every other
// pump() refusal is bounded by the deadline it just read -- past it,
// stillPending() hands over to clearMarks() and the old torrent is released --
// but this one refuses BECAUSE the generation will not read, so the deadline
// that ends every other wait is the very thing missing. reapOrphans() reaps
// the stub and never touches the predecessor's own chk-meta-new/chk-meta-until,
// so nothing else rewrites them either: the old torrent stays META_PENDING,
// re-reading the same bytes, for ever. logDebug() is gated on
// $rutrackerCheckDebug, which conf.php ships as false, so "keeping it
// untouched for diagnosis" produced no diagnosis at all.
//
// Asserted on the APPLICATION LOG with the flag EXPLICITLY false, and against
// ruTrackerChecker::$logs staying empty of it: TestLib stubs logDebug()
// UNGATED into that array, so it is the only pair of assertions that can tell
// the two channels apart.
$suite->test('the malformed durable generation reaches the application log at the shipped default', function () use ($oldHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('NOT-A-HASH', '999999'));

    $written = testCapturedAppLog(function () use ($oldHash) {
        strictAssertSame(ruTrackerChecker::STE_META_PENDING, RuTrackerMetaFetch::pump($oldHash, 1000),
            'the refusal itself is unchanged');
    });

    strictAssertSame(1, substr_count($written, 'malformed durable fetch generation'),
        'an operator is told exactly once, with $rutrackerCheckDebug at its shipped default of false');
    strictAssertTrue(strpos($written, $oldHash) !== false,
        'and the line names the torrent whose fetch is wedged, so it can be cleared');
    strictAssertSame(0, count(strictLogsMatching(ruTrackerChecker::$logs, 'malformed durable fetch generation')),
        'and it no longer goes out the debug-gated channel, which says nothing in production');

    // The control that proves the capture is not simply catching everything:
    // the sibling refusals in this file DO heal -- the deadline they read ends
    // them -- so they stay gated, and the same capture sees nothing from one.
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array(str_repeat('A', 40), '999999'));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        false, true, array());
    ruTrackerChecker::queueResult('torrentExists', true); // the stub is there, the read just failed
    $quiet = testCapturedAppLog(function () use ($oldHash) {
        strictAssertSame(ruTrackerChecker::STE_META_PENDING, RuTrackerMetaFetch::pump($oldHash, 1000),
            'an unreadable owner is retryable, and heals as soon as the read succeeds');
    });
    strictAssertSame('', $quiet,
        'a refusal that recovers on its own stays on the gated channel and writes nothing here');
});

$suite->test('harvest gives a paused old torrent a paused replacement', function () use ($oldHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::queueResult('createTorrent', null);
    $fixture = @new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    $newHash = strtoupper($fixture->hash_info());
    rTorrent::$source = $fixture;

    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 0);
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));
    mfQueueActivation(mfPluginMarker(), $oldHash . '-open-1000');
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED)); // runState open

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is committed');
    strictAssertSame(1, count(mfRequestsForRunState($newHash)), 'runState open was issued for replacement');
});

$suite->test('harvest leaves the replacement stopped when the old torrent was stopped', function () use ($oldHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::queueResult('createTorrent', null);
    $fixture = @new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    $newHash = strtoupper($fixture->hash_info());
    rTorrent::$source = $fixture;

    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 0);
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));
    mfQueueActivation(mfPluginMarker(), $oldHash . '-stopped-1000');
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED)); // clearCustoms for stopped

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is committed');
    strictAssertSame(0, count(mfRequestsForRunState($newHash)), 'no runState issued for stopped replacement');
});

$suite->test('an accepted but ineffective start is logged as what it is', function () use ($oldHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::queueResult('createTorrent', null);
    $fixture = @new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    $newHash = strtoupper($fixture->hash_info());
    rTorrent::$source = $fixture;

    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 0);
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));
    mfQueueActivation(mfPluginMarker(), $oldHash . '-started-1000');
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_UNCONFIRMED));

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is still committed');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'was unconfirmed', 'the ineffective start is logged');
    strictAssertEnglish($line, 'ineffective start log line');
});

$suite->test('harvest logs the byte count, the hash match, the erase and what createTorrent returned', function () use ($oldHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::queueResult('createTorrent', null);
    $fixture = @new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    $newHash = strtoupper($fixture->hash_info());
    rTorrent::$source = $fixture;

    mfQueueArrived($newHash);

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is committed');
    $lineMatch = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'hash matched=yes', 'the harvest hash match log is present');
    $lineBytes = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'bytes=', 'the harvest byte count log is present');
    $lineErase = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'service item erased=yes', 'the harvest erase log is present');
    $lineResult = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'returned success', 'the harvest return value log is present');
    strictAssertEnglish($lineMatch, 'the hash match line');
    strictAssertEnglish($lineBytes, 'the byte count line');
    strictAssertEnglish($lineErase, 'the erase line');
    strictAssertEnglish($lineResult, 'the return value line');
});

$suite->test('harvest names the STE_* code when createTorrent refuses the replacement', function () use ($oldHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_ERROR);
    $fixture = @new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    $newHash = strtoupper($fixture->hash_info());
    rTorrent::$source = $fixture;

    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 0);
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));

    strictAssertSame(ruTrackerChecker::STE_ERROR, RuTrackerMetaFetch::pump($oldHash, 1000), 'refusal propagated');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'returned STE_ERROR', 'refusal is logged with code');
    strictAssertEnglish($line, 'refusal log line');
});

$suite->test('a healthy pending stub logs that it is still a stub, with the reason it keeps waiting', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 1);
    rXMLRPCRequest::queue(
        array('d.get_tracker_size', 't.multicall', 'd.get_message'),
        true,
        false,
        array(1, 'http://bt.t-ru.org/ann?pk=x', 0, 1, '')
    );

    strictAssertSame(ruTrackerChecker::STE_META_PENDING, RuTrackerMetaFetch::pump($oldHash, 1000), 'still pending');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'still a metadata stub',
        'a waiting stub says so exactly once per cycle');
    strictAssertEnglish($line, 'the still-a-stub line');
});

$suite->test('begin adopts its own stub left by an earlier cycle', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    ruTrackerChecker::queueResult('torrentExists', true);
    mfQueueCollisionOwner($oldHash); // carries OUR stub marker
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true,
        false,
        array($oldHash, '6879823', '900', 1)
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED)); // runState
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array()); // markOldTorrent

    $state = RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);
    strictAssertSame(ruTrackerChecker::STE_META_PENDING, $state, 'the fetch resumes instead of aborting');
    strictAssertSame(0, count(rTorrent::$magnets), 'no second magnet is loaded');
    strictAssertSame(0, count(mfMessages()), 'no superseded token for our own stub');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'adopted its own stub',
        'the adoption is logged');
    strictAssertEnglish($line, 'the adoption line');
});

// The sibling branch: the stub adopted here is no longer a stub, its metadata
// landed while nobody was watching. Adoption still only RECORDS that -- it
// refreshes the deadline, claims the stub on the old torrent and returns
// STE_META_PENDING, and the harvest happens a cycle later at the next pump.
// The line used to announce a harvest from the session copy that this branch
// does not perform, which is a diagnostic pointing at the wrong function.
// Its replacement then promised one instead ('it is harvested at the next
// pump'), which adoption cannot guarantee either: the next pump re-reads the
// state and may clear the fetch because the stub vanished, or retire it
// because the item at that hash is no longer this transaction's. So the line
// has to name where the harvest is ATTEMPTED, as a handoff, and that is what
// the third assertion pins.
$suite->test('an adopted stub whose metadata already arrived says only that, and harvests at the next pump',
    function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    ruTrackerChecker::queueResult('torrentExists', true);
    mfQueueCollisionOwner($oldHash); // carries OUR stub marker
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true,
        false,
        array($oldHash, '6879823', '900', 0)   // is_meta=0: the metadata is already in
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED)); // setCustoms
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array()); // markOldTorrent

    $state = RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);
    strictAssertSame(ruTrackerChecker::STE_META_PENDING, $state, 'adoption defers to the next pump');
    strictAssertSame(0, count(mfCreates()), 'and hands nothing to createTorrent in this cycle');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'adopted its own stub',
        'the adoption is logged');
    strictAssertTrue(strpos($line, 'harvesting') === false,
        'the line may not say it is harvesting now; this branch does not: ' . $line);
    strictAssertTrue(strpos($line, 'next pump') !== false,
        'it names where the harvest is attempted: ' . $line);
    strictAssertTrue(strpos($line, 'left to the next pump') !== false,
        'and hands the harvest over rather than promising it will happen: ' . $line);
    strictAssertEnglish($line, 'the arrived-adoption line');
});

$suite->test('pump leaves a foreign item at the successor hash alone and retires the fetch', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false,
        array($newHash, '999999'));
    mfQueueStubOwner($newHash, str_repeat('C', 40)); // somebody else's
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

    $state = RuTrackerMetaFetch::pump($oldHash, 1000);
    strictAssertSame(ruTrackerChecker::STE_NOT_NEED, $state, 'a taken-over hash is a terminal outcome, not an error');
    strictAssertSame(0, count(mfRequestsForErase()), 'the foreign item is never erased');
    strictAssertSame(0, count(mfCreates()), 'and never harvested');
    strictAssertSame(1, count(mfMessages()), 'exactly one chk-msg write');
    strictAssertSame(ruTrackerChecker::CHKMSG_SUPERSEDED . '|' . $newHash,
        mfMessages()[0]['arguments'][1], 'the superseded token carries the successor hash');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'belongs to someone else',
        'the hand-off is logged');
    strictAssertEnglish($line, 'the hand-off line');
});

$suite->test('the service download goes under rTorrent\'s own download directory', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = $newHash;
    rTorrentSettings::get()->directory = '/downloads';
    $GLOBALS['topDirectory'] = '/';
    try {
        ruTrackerChecker::queueResult('awaitMetadata', false);
        ruTrackerChecker::queueResult('torrentExists', false);
        rXMLRPCRequest::queue(
            array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
            true,
            false,
            array($oldHash, '6879823', (string) (1000 + 86400), 1)
        );
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

        RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);
        strictAssertSame('/downloads/.chk-meta', rTorrent::$magnets[0]['directory'],
            'never /.chk-meta, which no non-root daemon can create');

        ruTrackerChecker::reset();
        rTorrent::$magnets = array();
        rTorrentSettings::get()->directory = '';
        $GLOBALS['topDirectory'] = '/data/';
        ruTrackerChecker::queueResult('awaitMetadata', false);
        ruTrackerChecker::queueResult('torrentExists', false);
        rXMLRPCRequest::queue(
            array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
            true,
            false,
            array($oldHash, '6879823', (string) (1000 + 86400), 1)
        );
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

        RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);
        strictAssertSame('/data/.chk-meta', rTorrent::$magnets[0]['directory'],
            'topDirectory remains the fallback');
    } finally {
        rTorrentSettings::get()->directory = '';
        $GLOBALS['topDirectory'] = '/data/';
    }
});

$suite->test('an unconfirmed stub start is not claimed and only a measured stopped stub is erased', function () use ($oldHash, $newHash) {
    foreach (array(
        'the commands are refused' => array('branch_status' => RuTrackerAtomicOwnership::SENTINEL_UNCONFIRMED, 'erased' => 1),
        'the reading itself fails' => array('branch_status' => RuTrackerAtomicOwnership::SENTINEL_SKIPPED, 'erased' => 0),
    ) as $label => $case) {
        ruTrackerChecker::reset();
        rTorrent::$magnets = array();
        rTorrent::$sendResult = $newHash;
        ruTrackerChecker::queueResult('torrentExists', false);
        rXMLRPCRequest::queue(
            array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
            true,
            false,
            array($oldHash, '6879823', (string) (1000 + 86400), 1)
        );
        rXMLRPCRequest::queue('branch', true, false, array($case['branch_status']));
        if ($case['erased']) {
            rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));
        }

        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
            RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000),
            $label . ': the fetch is not reported as under way');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
            $label . ': and the old torrent is not claimed for a stub that is not running');
        strictAssertSame($case['erased'], count(mfRequestsForErase()),
            $label . ': an erase needs a measured stopped stub; an unreadable one is left for adoption');
    }
});

$suite->test('a stub whose metadata already arrived is claimed, never started', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    ruTrackerChecker::queueResult('torrentExists', true);
    mfQueueCollisionOwner($oldHash);
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true,
        false,
        array($oldHash, '6879823', '900', 0) // is_meta=0 (arrived)
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED)); // setCustoms deadline
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    strictAssertSame(ruTrackerChecker::STE_META_PENDING,
        RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000),
        'the fetch is claimed and handed on to the harvest');
    strictAssertSame(0, count(mfRequestsForRunState()),
        'nothing is started: starting a finished download fetches the release, not metadata');
    strictAssertEnglish(strictAssertOneLogMatching(ruTrackerChecker::$logs, 'metadata already in',
        'the shortcut is logged'), 'the already-in line');
});

$suite->test('a stub whose d.is_meta cannot be read is left alone, not started', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    ruTrackerChecker::queueResult('torrentExists', true);
    mfQueueCollisionOwner($oldHash);
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true,
        true,
        array()
    );

    $state = RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'retryable: nothing was established');
    strictAssertSame(0, count(mfRequestsForRunState()),
        'nothing is started on an answer that established nothing');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
        'and the stub is left unclaimed, so the next cycle asks again');
    strictAssertEnglish(strictAssertOneLogMatching(ruTrackerChecker::$logs, 'could not read whether its stub',
        'the unreadable answer is logged'), 'the unreadable-stub line');
});

$suite->test('a stub that will not start is left unclaimed for the next cycle', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    ruTrackerChecker::queueResult('torrentExists', true);
    mfQueueCollisionOwner($oldHash);
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true,
        false,
        array($oldHash, '6879823', '900', 1)
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_UNCONFIRMED));

    $state = RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'retryable, not pending');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
        'the old torrent is NOT claimed');
    strictAssertSame(0, count(mfMessages()), 'and no verdict is written');
    strictAssertEnglish(strictAssertOneLogMatching(ruTrackerChecker::$logs, 'could not start its own stub',
        'the refusal is logged'), 'the unclaimed-stub line');
});

$suite->test('a confirmed replacement is not re-activated from this fetch\'s older reading', function () use ($oldHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::queueResult('createTorrent', null);
    $fixture = @new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    $newHash = strtoupper($fixture->hash_info());
    rTorrent::$source = $fixture;

    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 0);
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));
    mfQueueActivation('', ''); // settled

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is committed');
    strictAssertSame(0, count(mfRequestsForRunState($newHash)),
        'the settled replacement is left exactly as the replacement itself decided');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'already settled',
        'standing down is logged');
    strictAssertEnglish($line, 'the stand-down line');
});

$suite->test('an unconfirmed replacement still gets this fetch\'s fallback activation', function () use ($oldHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::queueResult('createTorrent', null);
    $fixture = @new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    $newHash = strtoupper($fixture->hash_info());
    rTorrent::$source = $fixture;

    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 0);
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));
    mfQueueActivation(mfPluginMarker(), $oldHash . '-started-1000');
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is committed');
    strictAssertSame(1, count(mfRequestsForRunState($newHash)), 'the fallback runs when nobody confirmed the activation');
});

$suite->test('fallback activation requires a plugin nonce and a strict shared inheritance record', function () use ($oldHash) {
    foreach (array(
        'arbitrary marker with otherwise valid record' => array(
            'marker' => 'not-a-plugin-marker', 'record' => $oldHash . '-started-1000'),
        'plugin marker with unknown run token' => array(
            'marker' => '0123456789abcdef0123456789abcdef', 'record' => $oldHash . '-mystery-1000'),
    ) as $label => $case) {
        ruTrackerChecker::reset();
        ruTrackerChecker::queueResult('createTorrent', null);
        $fixture = @new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
        $newHash = strtoupper($fixture->hash_info());
        rTorrent::$source = $fixture;

        rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
        mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 0);
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));
        mfQueueActivation($case['marker'], $case['record']);

        strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000),
            $label . ': the replacement remains committed');
        strictAssertSame(0, count(mfRequestsForRunState($newHash)),
            $label . ': unproved ownership authorizes no fallback activation');
    }
});

$suite->test('an unreadable replacement marker refuses fallback activation and leaves replacement alone', function () use ($oldHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::queueResult('createTorrent', null);
    $fixture = @new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    $newHash = strtoupper($fixture->hash_info());
    rTorrent::$source = $fixture;

    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 0);
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));
    mfQueueActivation('', '', 0, 0, false);

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is committed');
    strictAssertSame(0, count(mfRequestsForRunState($newHash)),
        'an unreadable marker must not trigger fallback activation');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'unreadable replacement marker',
        'the refusal to activate on unreadable marker is logged');
    strictAssertEnglish($line, 'the stand-down line');
});


$suite->test('malformed or foreign successor inheritance fails closed', function () use ($oldHash) {
    foreach (array(
        'malformed' => 'garbage',
        'foreign predecessor' => str_repeat('C', 40) . '-started-1000',
    ) as $label => $record) {
        ruTrackerChecker::reset();
        ruTrackerChecker::queueResult('createTorrent', null);
        $fixture = @new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
        $newHash = strtoupper($fixture->hash_info());
        rTorrent::$source = $fixture;

        rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
        mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 0);
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));
        mfQueueActivation(mfPluginMarker(), $record);

        strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), $label . ': replacement remains committed');
        strictAssertSame(0, count(mfRequestsForRunState($newHash)),
            $label . ': no activation without a valid fresh ownership record');
    }
});

$suite->test('the transaction\'s own staged replacement is not mistaken for a foreign takeover', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, '', '6879823', '999999', 0, mfPluginMarker(), $oldHash . '-started-1000');
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, RuTrackerMetaFetch::pump($oldHash, 1000),
        'retryable, so the predecessor keeps being checked');
    strictAssertSame(0, count(mfMessages()), 'no superseded token is written');
    strictAssertSame(0, count(mfRequestsForErase()), 'and the staged copy is left to the sweep');
    strictAssertEnglish(strictAssertOneLogMatching(ruTrackerChecker::$logs, 'staged replacement',
        'the hand-off to the sweep is logged'), 'the hand-off line');

    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    ruTrackerChecker::queueResult('torrentExists', true);
    mfQueueCollisionOwner('', mfPluginMarker(), $oldHash . '-started-1000');

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000),
        'retryable here too');
    strictAssertSame(0, count(mfMessages()), 'and still no terminal verdict');
    strictAssertSame(0, count(rTorrent::$magnets), 'nothing is loaded on top of it');
});

$suite->test('arbitrary replacement markers are foreign in begin and pump ownership decisions', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    ruTrackerChecker::queueResult('torrentExists', true);
    mfQueueCollisionOwner('', 'not-a-plugin-marker', $oldHash . '-started-1000');
    strictAssertSame(ruTrackerChecker::STE_NOT_NEED,
        RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000),
        'begin treats an untrusted marker as an ordinary foreign occupant');
    strictAssertSame(1, count(mfMessages()),
        'begin records the ordinary superseded outcome rather than staged ownership');
    strictAssertSame(0, count(rTorrent::$magnets), 'begin never loads over the occupant');

    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false,
        array($newHash, '999999'));
    mfQueueStubOwner($newHash, '', '6879823', '999999', 0, 'not-a-plugin-marker', $oldHash . '-started-1000');
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));
    strictAssertSame(ruTrackerChecker::STE_NOT_NEED, RuTrackerMetaFetch::pump($oldHash, 1000),
        'pump also treats an untrusted marker as a foreign occupant');
    strictAssertSame(1, count(mfMessages()),
        'pump records the ordinary superseded outcome');
    strictAssertSame(0, count(mfRequestsForErase()),
        'pump never erases the foreign occupant');
});

$suite->test('a plugin marker without a strict matching record stays retryable in begin and pump collisions', function () use ($oldHash, $newHash) {
    foreach (array(
        'empty record' => '',
        'malformed record' => 'garbage',
        'different predecessor' => str_repeat('C', 40) . '-started-1000',
    ) as $label => $record) {
        ruTrackerChecker::reset();
        rTorrent::$magnets = array();
        ruTrackerChecker::queueResult('torrentExists', true);
        mfQueueCollisionOwner('', mfPluginMarker(), $record);

        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
            RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823,
                'http://bt.t-ru.org/ann?pk=s3cr3t', 1000),
            $label . ': begin defers an unproved plugin transaction');
        strictAssertSame(1, count(rXMLRPCRequest::requestsFor(
            'd.get_custom|d.get_custom|d.get_custom')),
            $label . ': begin reads stub marker, replacement marker and record coherently');
        strictAssertSame(0, count(mfMessages()),
            $label . ': begin writes no terminal verdict for an unproved plugin transaction');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
            $label . ': begin writes no custom state for an unproved plugin transaction');
        strictAssertSame(0, count(rTorrent::$magnets),
            $label . ': begin never loads over the unproved occupant');

        ruTrackerChecker::reset();
        rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false,
            array($newHash, '999999'));
        mfQueueStubOwner($newHash, '', '6879823', '999999', 0, mfPluginMarker(), $record);

        strictAssertSame(ruTrackerChecker::STE_META_PENDING,
            RuTrackerMetaFetch::pump($oldHash, 1000),
            $label . ': pump keeps an unproved plugin transaction pending before its deadline');
        strictAssertSame(0, count(mfMessages()),
            $label . ': pump writes no terminal verdict for an unproved plugin transaction');
        strictAssertSame(0, count(mfRequestsForErase()),
            $label . ': pump never erases the unproved occupant');
    }
});

$suite->test('an expired unproved plugin collision retires only the predecessor retry claim', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false,
        array($newHash, '500')); // expired before now=1000
    mfQueueStubOwner($newHash, '', '6879823', '500', 0, mfPluginMarker(), 'garbage');
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        RuTrackerMetaFetch::pump($oldHash, 1000),
        'the predecessor claim is bounded even when plugin ownership never becomes provable');
    strictAssertSame(0, count(mfRequestsForErase()),
        'expiry never erases the unproved occupant');
    strictAssertSame(1, count(mfMessages()),
        'expiry clears only the predecessor diagnostic message');
    strictAssertSame('', mfMessages()[0]['arguments'][1],
        'no superseded token is invented for an unproved occupant');
});

$suite->test('an unreadable marker defers instead of declaring the successor foreign', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    ruTrackerChecker::queueResult('torrentExists', true);
    mfQueueCollisionOwnerFailure();

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000),
        'a failed read is not evidence of anything');
    strictAssertSame(0, count(mfMessages()),
        'and must not leave a terminal verdict behind on nothing but a transport failure');
    strictAssertEnglish(strictAssertOneLogMatching(ruTrackerChecker::$logs, 'could not read the marker',
        'the deferral is logged'), 'the deferral line');
});

$suite->test('a failing dht:// row does not count as the tracker rejecting the hash', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 1);
    rXMLRPCRequest::queue(
        array('d.get_tracker_size', 't.multicall', 'd.get_message'),
        true,
        false,
        array(2, 'dht://', 9, 1, 'http://bt.t-ru.org/ann?pk=x', 0, 1, '')
    );

    strictAssertSame(ruTrackerChecker::STE_META_PENDING, RuTrackerMetaFetch::pump($oldHash, 1000),
        'the fetch keeps waiting: only the magnet\'s own tracker may condemn it');
    strictAssertSame(0, count(mfRequestsForErase()), 'and nothing is dropped');

    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 1);
    rXMLRPCRequest::queue(
        array('d.get_tracker_size', 't.multicall', 'd.get_message'),
        true,
        false,
        array(2, 'dht://', 0, 1, 'http://bt.t-ru.org/ann?pk=x', 4, 1,
              'Tracker: [Failure reason "torrent not registered with this tracker"]')
    );

    strictAssertSame(ruTrackerChecker::STE_META_PENDING, RuTrackerMetaFetch::pump($oldHash, 1000),
        'with somebody else on the list the message speaks for nobody in particular');
    strictAssertSame(0, count(mfRequestsForErase()), 'so the stub is kept');

    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 1);
    rXMLRPCRequest::queue(
        array('d.get_tracker_size', 't.multicall', 'd.get_message'),
        true,
        false,
        array(2, 'dht://', 0, 0, 'http://bt.t-ru.org/ann?pk=x', 4, 1,
              'Tracker: [Failure reason "torrent not registered with this tracker"]')
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, RuTrackerMetaFetch::pump($oldHash, 1000),
        'a rejection from the magnet\'s own tracker, and only it, still aborts');
});

$suite->test('an expired fetch is dropped even when the stub can no longer be read', function () use ($oldHash, $newHash) {
    foreach (array(
        'the existence probe fails' => array(
            'stage' => function () use ($newHash) {
                rXMLRPCRequest::queue(
                    array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
                    false,
                    false,
                    array()
                );
                ruTrackerChecker::queueResult('torrentExists', null);
            },
            'erased' => 0,
            'why' => 'nothing is erased on an existence nobody could establish',
        ),
        'the ownership read fails' => array(
            'stage' => function () use ($newHash) {
                rXMLRPCRequest::queue(
                    array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
                    false,
                    false,
                    array()
                );
                ruTrackerChecker::queueResult('torrentExists', true);
            },
            'erased' => 0,
            'why' => 'the read that would have proved it ours is the one that failed, so it is left alone',
        ),
        'the is_meta read fails' => array(
            'stage' => function () use ($newHash, $oldHash) {
                rXMLRPCRequest::queue(
                    array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
                    true,
                    false,
                    array($oldHash, '', '', '6879823', '500', 'garbage')
                );
            },
            'erased' => 0,
            'why' => 'unreadable is_meta leaves item alone rather than erasing unproved ownership',
        ),
    ) as $label => $case) {
        ruTrackerChecker::reset();
        rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '500'));
        call_user_func($case['stage']);
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

        $state = RuTrackerMetaFetch::pump($oldHash, 1000);
        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state,
            $label . ': the fetch returns to the queue');
        strictAssertSame($case['erased'], count(mfRequestsForErase()),
            $label . ': ' . $case['why']);
    }

    ruTrackerChecker::reset();
    ruTrackerChecker::queueResult('awaitMetadata', false);
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 0);

    strictAssertSame(ruTrackerChecker::STE_META_PENDING, RuTrackerMetaFetch::pump($oldHash, 1000),
        'an unreadable probe keeps waiting while the deadline has not passed');
});

$suite->test('a failing counter is only a rejection when rTorrent does not blame the transport', function () use ($oldHash, $newHash) {
    foreach (array(
        'rTorrent blames the network' => array(
            'message' => 'Tracker: [Could not connect to server]',
            'state'   => ruTrackerChecker::STE_META_PENDING, 'erased' => 0,
            'why'     => 'the fetch keeps its remaining allowance'),
        'rTorrent cannot resolve the host' => array(
            'message' => 'Tracker: [Could not resolve hostname]',
            'state'   => ruTrackerChecker::STE_META_PENDING, 'erased' => 0,
            'why'     => 'a name that will not resolve is not a refusal either'),
        'the tracker itself refuses' => array(
            'message' => 'Tracker: [Failure reason "torrent not registered"]',
            'state'   => ruTrackerChecker::STE_CANT_REACH_TRACKER, 'erased' => 1,
            'why'     => "the tracker's own answer still condemns the fetch"),
        'a refusal behind a timeout' => array(
            'message' => 'Tracker: [Could not connect to server /// Failure reason "not registered"]',
            'state'   => ruTrackerChecker::STE_CANT_REACH_TRACKER, 'erased' => 1,
            'why'     => 'a refusal is still a refusal when something else failed first'),
        'two rows, both of them network' => array(
            'message' => 'Tracker: [Could not connect to server /// Could not resolve hostname]',
            'state'   => ruTrackerChecker::STE_META_PENDING, 'erased' => 0,
            'why'     => 'the real live shape: nothing here is the tracker refusing'),
        'rTorrent says nothing at all' => array(
            'message' => '',
            'state'   => ruTrackerChecker::STE_META_PENDING, 'erased' => 0,
            'why'     => 'a counter with no message behind it says only that an announce failed'),
    ) as $label => $case) {
        ruTrackerChecker::reset();
        rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
        mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 1);
        rXMLRPCRequest::queue(
            array('d.get_tracker_size', 't.multicall', 'd.get_message'),
            true,
            false,
            array(1, 'http://bt.t-ru.org/ann?pk=x', 4, 1, $case['message'])
        );
        if ($case['erased']) {
            rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));
            rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));
        }

        strictAssertSame($case['state'], RuTrackerMetaFetch::pump($oldHash, 1000),
            $label . ': ' . $case['why']);
        strictAssertSame($case['erased'], count(mfRequestsForErase()),
            $label . ': the stub is erased only when the fetch is really condemned');
    }
});

$suite->test('a claim that does not land is not reported as a pending fetch', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = $newHash;
    ruTrackerChecker::queueResult('torrentExists', false);
    ruTrackerChecker::queueResult('torrentExists', true);
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true,
        false,
        array($oldHash, '6879823', (string) (1000 + 86400), 1)
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), false, false, array()); // the claim fails

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000),
        'retryable: nothing would ever advance that stub');
    strictAssertEnglish(strictAssertOneLogMatching(ruTrackerChecker::$logs, 'the claim did not land',
        'the failure is logged'), 'the unclaimed line');
});

$suite->test('begin leaves a readded predecessor unmarked after its stub starts', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::$runLocalId = str_repeat('1', 40);
    rTorrent::$magnets = array();
    rTorrent::$sendResult = $newHash;
    ruTrackerChecker::queueResult('torrentExists', false);
    ruTrackerChecker::queueResult('awaitMetadata', false);
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true, false, array($oldHash, '6879823', '87400', 1));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED));
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array(0, 0));

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823,
            'http://bt.t-ru.org/ann?pk=s3cr3t', 1000),
        'a metadata stub cannot claim a different predecessor generation');
    strictAssertSame(1, count(rTorrent::$magnets), 'the service stub was already loaded');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
        'no old marker may be sent directly by hash');
    $branches = rXMLRPCRequest::requestsFor('branch');
    strictAssertSame(2, count($branches), 'stub start and predecessor claim are conditional');
    strictAssertTrue(strpos($branches[1]['commands'][0]->params[1], str_repeat('1', 40)) !== false,
        'the claim compares the predecessor identity captured before the fetch');
    ruTrackerChecker::$runLocalId = null;
});

$suite->test('a truncated predecessor-marker reply cannot claim a partial two-field bundle', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    $model = array('chk-meta-new' => '', 'chk-meta-until' => '');
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false,
        function ($commands) use (&$model) {
            $model[$commands[0]->params[1]] = (string) $commands[0]->params[2];
            return array(0); // positive reply, but only the first setter landed
        });
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false,
        function ($commands) use (&$model) {
            return array($model['chk-meta-new'], $model['chk-meta-until']);
        });

    strictAssertSame(false, strictInvoke('RuTrackerMetaFetch', 'markOldTorrent',
        array($oldHash, $newHash, 999999)),
        'a partial marker bundle is not a durable retry handle');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.get_custom|d.get_custom')),
        'all predecessor fields are measured after a short positive reply');
});

$suite->test('a truncated predecessor-marker reply accepts a fully measured lost response', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    $model = array('chk-meta-new' => '', 'chk-meta-until' => '');
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false,
        function ($commands) use (&$model) {
            foreach ($commands as $command) $model[$command->params[1]] = (string) $command->params[2];
            return array(0); // response is short although the full write landed
        });
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false,
        function ($commands) use (&$model) {
            return array($model['chk-meta-new'], $model['chk-meta-until']);
        });

    strictAssertSame(true, strictInvoke('RuTrackerMetaFetch', 'markOldTorrent',
        array($oldHash, $newHash, 999999)),
        'a lost response is accepted only after every desired predecessor marker is observed');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.get_custom|d.get_custom')),
        'the full lost-response case is still measured');
});

$suite->test('adopting a stub refreshes the deadline on the stub itself, not only on the old torrent', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::queueResult('torrentExists', true);
    mfQueueCollisionOwner($oldHash);
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true,
        false,
        array($oldHash, '6879823', '900', 1)
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    strictAssertSame(ruTrackerChecker::STE_META_PENDING,
        RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000),
        'the fetch resumes');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom');
    strictAssertSame(1, count($writes), 'markOldTorrent issues one atomic custom write');
    strictAssertSame(array($oldHash, 'chk-meta-until', (string) (1000 + 86400)),
        $writes[0]['commands'][1]->params, 'deadline is refreshed on old torrent');
});

$suite->test('adopted stub keeps its durable predecessor marker and converges on the next begin', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::queueResult('torrentExists', true);
    mfQueueCollisionOwner($oldHash);
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true,
        false,
        array($oldHash, '6879823', '900', 1)
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_UNCONFIRMED));

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000),
        'retryable: a stub whose start could not be confirmed is not left pending');

    rXMLRPCRequest::reset();
    ruTrackerChecker::queueResult('torrentExists', true);
    mfQueueCollisionOwner($oldHash);
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true,
        false,
        array($oldHash, '6879823', '900', 1)
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    strictAssertSame(ruTrackerChecker::STE_META_PENDING,
        RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 2000),
        'the same durable marker drives a successful retry on the next begin');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom');
    strictAssertSame(1, count($writes), 'markOldTorrent issues one atomic custom write');
    strictAssertSame(array($oldHash, 'chk-meta-until', (string) (2000 + 86400)),
        $writes[0]['commands'][1]->params, 'deadline is refreshed for the new timestamp');
});

$suite->test('MetaFetch tracker projection truncated prefixes 0..7 and malformed projections return STE_META_PENDING with zero erase and zero clear', function () use ($oldHash, $newHash) {
    $twoRowVector = array(
        2,
        'http://bt.t-ru.org/ann?pk=x', '4', '1',
        'http://foreign.example/announce', '0', '1',
        'Tracker: [Failure reason "torrent not registered with this tracker"]'
    );

    for ($len = 0; $len < 8; $len++) {
        ruTrackerChecker::reset();
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
        mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 1);
        $prefix = array_slice($twoRowVector, 0, $len);
        rXMLRPCRequest::queue(array('d.get_tracker_size', 't.multicall', 'd.get_message'), true, false, $prefix);

        $state = RuTrackerMetaFetch::pump($oldHash, 1000);
        strictAssertSame(ruTrackerChecker::STE_META_PENDING, $state, "prefix length {$len} returns STE_META_PENDING");
        strictAssertSame(0, count(mfRequestsForErase()), "prefix length {$len} issues zero d.erase");
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')), "prefix length {$len} issues zero clear-marker writes");
        strictAssertSame(0, count(mfCreates()), "prefix length {$len} issues no createTorrent");
    }

    $extraVector = array_merge($twoRowVector, array('extra-scalar'));
    ruTrackerChecker::reset();
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 1);
    rXMLRPCRequest::queue(array('d.get_tracker_size', 't.multicall', 'd.get_message'), true, false, $extraVector);

    $state = RuTrackerMetaFetch::pump($oldHash, 1000);
    strictAssertSame(ruTrackerChecker::STE_META_PENDING, $state, "extra scalar returns STE_META_PENDING");
    strictAssertSame(0, count(mfRequestsForErase()), "extra scalar issues zero d.erase");
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')), "extra scalar issues zero clear-marker writes");
    strictAssertSame(0, count(mfCreates()), "extra scalar issues no createTorrent");

    $badProjections = array(
        'count -1' => array(-1, 'http://bt.t-ru.org/ann?pk=x', '4', '1', ''),
        'count 01' => array('01', 'http://bt.t-ru.org/ann?pk=x', '4', '1', ''),
        'count +1' => array('+1', 'http://bt.t-ru.org/ann?pk=x', '4', '1', ''),
        'count float' => array(1.0, 'http://bt.t-ru.org/ann?pk=x', '4', '1', ''),
        'count bool' => array(true, 'http://bt.t-ru.org/ann?pk=x', '4', '1', ''),
        'count overflow' => array('9999999999999999999999999999999999999999', 'http://bt.t-ru.org/ann?pk=x', '4', '1', ''),
        'non-string url' => array(1, 123, '4', '1', ''),
        'failed negative' => array(1, 'http://bt.t-ru.org/ann?pk=x', -1, '1', ''),
        'failed leading zero' => array(1, 'http://bt.t-ru.org/ann?pk=x', '04', '1', ''),
        'failed float' => array(1, 'http://bt.t-ru.org/ann?pk=x', 4.0, '1', ''),
        'failed bool' => array(1, 'http://bt.t-ru.org/ann?pk=x', true, '1', ''),
        'enabled negative' => array(1, 'http://bt.t-ru.org/ann?pk=x', '4', -1, ''),
        'enabled leading zero' => array(1, 'http://bt.t-ru.org/ann?pk=x', '4', '01', ''),
        'enabled float' => array(1, 'http://bt.t-ru.org/ann?pk=x', '4', 1.0, ''),
        'enabled bool' => array(1, 'http://bt.t-ru.org/ann?pk=x', '4', true, ''),
        'non-string message' => array(1, 'http://bt.t-ru.org/ann?pk=x', '4', '1', 123),
        'message null' => array(1, 'http://bt.t-ru.org/ann?pk=x', '4', '1', null),
    );

    foreach ($badProjections as $label => $raw) {
        ruTrackerChecker::reset();
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
        mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 1);
        rXMLRPCRequest::queue(array('d.get_tracker_size', 't.multicall', 'd.get_message'), true, false, $raw);

        $state = RuTrackerMetaFetch::pump($oldHash, 1000);
        strictAssertSame(ruTrackerChecker::STE_META_PENDING, $state, "{$label} returns STE_META_PENDING");
        strictAssertSame(0, count(mfRequestsForErase()), "{$label} issues zero d.erase");
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')), "{$label} issues zero clear-marker writes");
        strictAssertSame(0, count(mfCreates()), "{$label} issues no createTorrent");
    }

    ruTrackerChecker::reset();
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '999999', 1);
    rXMLRPCRequest::queue(array('d.get_tracker_size', 't.multicall', 'd.get_message'), true, true, array());

    $state = RuTrackerMetaFetch::pump($oldHash, 1000);
    strictAssertSame(ruTrackerChecker::STE_META_PENDING, $state, "request fault returns STE_META_PENDING");
    strictAssertSame(0, count(mfRequestsForErase()), "request fault issues zero d.erase");
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')), "request fault issues zero clear-marker writes");

    ruTrackerChecker::reset();
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '500'));
    mfQueueStubOwner($newHash, $oldHash, '6879823', '500', 1);
    rXMLRPCRequest::queue(array('d.get_tracker_size', 't.multicall', 'd.get_message'), true, false, array_slice($twoRowVector, 0, 4));

    $state = RuTrackerMetaFetch::pump($oldHash, 1000);
    strictAssertSame(ruTrackerChecker::STE_META_PENDING, $state, "expired malformed projection returns STE_META_PENDING");
    strictAssertSame(0, count(mfRequestsForErase()), "expired malformed projection issues zero d.erase");
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')), "expired malformed projection preserves claim");
});

$suite->test('pump preserves the exact raw predecessor claim in its conditional clear', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::reset();
    $rawNewHash = strtolower($newHash);
    $rawDeadline = '999999';
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom'), true, false, array($rawNewHash, $rawDeadline)
    );
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true, false, array(str_repeat('C', 40), '', '', '6879823', $rawDeadline, 1)
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

    strictAssertSame(ruTrackerChecker::STE_NOT_NEED,
        RuTrackerMetaFetch::pump($oldHash, 1000),
        'a foreign occupant retires only the predecessor claim read by this pump');
    $branches = rXMLRPCRequest::requestsFor('branch');
    strictAssertSame(1, count($branches), 'the predecessor claim is cleared conditionally once');
    $condition = $branches[0]['commands'][0]->params[1];
    strictAssertTrue(strpos($condition, 'equal=d.get_custom=chk-meta-new,cat=' . $rawNewHash) !== false,
        'hash comparison keeps the exact raw bytes instead of uppercasing them');
    strictAssertTrue(strpos($condition, 'equal=d.get_custom=chk-meta-until,cat=' . $rawDeadline) !== false,
        'deadline comparison keeps the exact canonical raw bytes instead of intval normalization');
    $clear = $branches[0]['commands'][0]->params[2];
    strictAssertTrue(strpos($clear, '$d.set_custom=chk-meta-until,') !== false
        && strpos($clear, '$d.set_custom=chk-meta-new,') !== false,
        'retiring the exact predecessor generation clears both metadata marks');
});

$suite->test('malformed predecessor fetch generation fails closed without clearing or probing a normalized target', function () use ($oldHash, $newHash) {
    $cases = array(
        'hash with whitespace' => array(' ' . $newHash, '999999'),
        'deadline with a leading zero' => array($newHash, '0999999'),
    );
    foreach ($cases as $label => $generation) {
        ruTrackerChecker::reset();
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(
            array('d.get_custom', 'd.get_custom'), true, false, $generation
        );
        rXMLRPCRequest::queue(
            array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
            true, false, array(str_repeat('C', 40), '', '', '6879823', '999999', 1)
        );
        rXMLRPCRequest::queue('branch', true, false,
            array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

        strictAssertSame(ruTrackerChecker::STE_META_PENDING,
            RuTrackerMetaFetch::pump($oldHash, 1000),
            $label . ' remains retryable without mutating ownership');
        strictAssertSame(1, count(rXMLRPCRequest::$requests),
            $label . ' stops after reading the malformed durable generation');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('branch')),
            $label . ' cannot reach a conditional or ownerless clear');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
            $label . ' cannot clear predecessor customs blindly');
    }
});

$suite->test('an owner projection with an extra scalar is unreadable and triggers no downstream action', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999')
    );
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true,
        false,
        array($oldHash, '', '', '6879823', '999999', 1, 'unexpected')
    );
    ruTrackerChecker::queueResult('torrentExists', true);

    strictAssertSame(ruTrackerChecker::STE_META_PENDING,
        RuTrackerMetaFetch::pump($oldHash, 1000),
        'a malformed positive owner reply stays pending');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.get_tracker_size|t.multicall|d.get_message')),
        'the extra scalar cannot be parsed as a valid owner projection prefix');
    strictAssertSame(0, count(mfRequestsForErase()),
        'the malformed owner reply authorizes no erase');
    strictAssertSame(0, count(mfRequestsForClear()),
        'the malformed owner reply authorizes no clear');
});

$suite->test('a skipped or unknown conditional mark clear remains retryable', function () use ($oldHash, $newHash) {
    foreach (array(
        'ownership changed' => array(true, false, array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED)),
        'reply lost' => array(false, false, array()),
    ) as $label => $response) {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('branch', $response[0], $response[1], $response[2]);

        strictAssertSame(ruTrackerChecker::STE_META_PENDING,
            strictInvoke('RuTrackerMetaFetch', 'clearMarks',
                array($oldHash, ruTrackerChecker::STE_NOT_NEED, $newHash, '999999')),
            $label . ' keeps the predecessor claim retryable');
        strictAssertSame(1, count(rXMLRPCRequest::requestsFor('branch')),
            $label . ' gets exactly one generation-conditional clear attempt');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
            $label . ' never falls back to an ownerless write');
    }
});

$suite->test('replacement activation rechecks the exact observed state and open values', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::reset();
    $marker = mfPluginMarker();
    $record = $oldHash . '-started-1786899620';
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_state', 'd.is_open'),
        true,
        false,
        array($marker, $record, 0, 0)
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));

    strictInvoke('RuTrackerMetaFetch', 'restoreReplacement', array($oldHash, $newHash));

    $branches = rXMLRPCRequest::requestsFor('branch');
    strictAssertSame(1, count($branches),
        'the measured generation gets one activation branch');
    $condition = $branches[0]['commands'][0]->params[1];
    strictAssertTrue(strpos($condition, 'equal=d.get_state=,value=0') !== false,
        'activation condition retains the exact observed state');
    strictAssertTrue(strpos($condition, 'equal=d.is_open=,value=0') !== false,
        'activation condition retains the exact observed open value');
});

$suite->test('unknown replacement activation reply is never retried blind', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::reset();
    $marker = str_repeat('b', 32);
    $record = $oldHash . '-started-1786899620';
    mfQueueActivation($marker, $record);
    rXMLRPCRequest::queue('branch', false, false, array());

    strictInvoke('RuTrackerMetaFetch', 'restoreReplacement', array($oldHash, $newHash));

    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('branch')),
        'unknown side effects preserve the generation for a later cycle rather than issuing a second run');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
        'replacement activation never falls back to standalone commands');
});

$suite->test('no command in begin, adopt or harvest reads or writes chk-meta-run', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = $newHash;
    ruTrackerChecker::queueResult('awaitMetadata', false);
    ruTrackerChecker::queueResult('torrentExists', false);
    rXMLRPCRequest::queue(
        array('d.get_custom', 'd.get_custom', 'd.get_custom', 'd.is_meta'),
        true,
        false,
        array($oldHash, '6879823', (string) (1000 + 86400), 1)
    );
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);

    foreach (rXMLRPCRequest::$requests as $req) {
        foreach ($req['commands'] as $cmd) {
            foreach ((array) $cmd->params as $param) {
                strictAssertTrue(strpos((string) $param, 'chk-meta-run') === false,
                    'no command in begin reads or writes chk-meta-run');
            }
        }
    }
});

// A replacement is assembled by calling Torrent's setters. Where touch()
// stamps a decoded torrent -- as this class did before its own fix, and as
// upstream's Torrent still does -- that writes 'creation date' = time() and
// 'created by' = the PHP class's own name into a torrent whose real author is
// someone else, and both then read out as fact in the "Created On" column and
// in the history plugin. Measured on the live fleet before the fix: eleven
// torrents dated 8 to 15 hours after the tracker actually registered them,
// every one landing on the top of an hour because that is when the cycle runs.
// What this test pins is the replacement rule: the date comes from the
// tracker's own dump, never from this host's clock.
$suite->test('a harvested replacement is dated from the dump, never from this host\'s clock',
    function () use ($oldHash) {
    strictWithStateDir('rut-metafetch-regtime', function () use ($oldHash) {
        ruTrackerChecker::reset();
        ruTrackerChecker::queueResult('createTorrent', null);
        rTorrent::$sourcesByHash = array();
        $fixture = @new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
        $newHash = strtoupper($fixture->hash_info());
        rTorrent::$source = $fixture;

        // Publish forum 1106's dump through the ordinary fetch path, so the
        // reg_time read below comes out of the same cache layer 3 fills.
        // The forum index memoizes fetched rows in a static that outlives a
        // test. Without this reset the second publication of the same forum
        // short-circuits and never writes into THIS test's state directory,
        // so cachedDump() reads an empty one and answers null.
        strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
        Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106',
            200, fiDumpAt(6879823, 2, str_repeat('A', 40), 7, 1788342804));
        strictAssertTrue(RuTrackerForumIndex::fetchDump(1106) !== null, 'the dump is published');

        mfQueueArrived($newHash);
        // The predecessor's chk-forum: the successor is not in the daemon's
        // list under its own hash yet, so only the old row can name the forum.
        rXMLRPCRequest::queue('d.get_custom', true, false, array('1106'));

        strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the harvest commits');
        $payload = mfHandedOverTorrent(mfCreates()[0]);
        strictAssertSame(1788342804, $payload->meta('creation date'),
            'the date is the tracker\'s own reg_time for the topic');
        strictAssertSame(null, $payload->meta('created by'),
            'and no authorship is claimed over it');
    });
});

$suite->test('an undatable replacement carries no date at all rather than an invented one',
    function () use ($oldHash) {
    strictWithStateDir('rut-metafetch-noregtime', function () use ($oldHash) {
        // No dump published and no forum resolved: nothing here knows when the
        // release was made. An empty "Created On" says exactly that. A clock
        // reading would say something false and would be indistinguishable
        // from a real date -- which is the whole defect being fixed.
        ruTrackerChecker::reset();
        ruTrackerChecker::queueResult('createTorrent', null);
        rTorrent::$sourcesByHash = array();
        $fixture = @new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
        $newHash = strtoupper($fixture->hash_info());
        rTorrent::$source = $fixture;

        mfQueueArrived($newHash);
        rXMLRPCRequest::queue('d.get_custom', true, false, array(''));

        strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the harvest still commits');
        $payload = mfHandedOverTorrent(mfCreates()[0]);
        strictAssertSame(null, $payload->meta('creation date'),
            'an unknown creation date is left unset');
        strictAssertSame(null, $payload->meta('created by'),
            'and still no invented authorship');
        // The replacement itself must not depend on any of this.
        strictAssertSame('https://rutracker.org/forum/viewtopic.php?t=6879823', $payload->comment(),
            'the comment patch is unaffected');
        strictAssertSame($newHash, strtoupper($payload->hash_info()),
            'and none of it can move the info hash');
    });
});

// Mutation checks proved each of the three behaviours below could be deleted
// outright with the whole suite still green.

$suite->test('a replacement keeps the creation date and creator its own bytes carried',
    function () use ($oldHash) {
    strictWithStateDir('rut-metafetch-authored', function () use ($oldHash) {
        // The restore path. A harvest that only ever cleared the two keys
        // would be the mirror image of the bug being fixed: a caller
        // destroying a real author's fields because it assumed none could be
        // there. A BEP 9 stub carries neither key today, which is why this
        // needs a fixture that does -- and why the branch survived a mutation
        // that discarded the captured values entirely.
        ruTrackerChecker::reset();
        ruTrackerChecker::queueResult('createTorrent', null);
        rTorrent::$sourcesByHash = array();
        $fixture = @new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t',
            '', null, array('created by' => 'mktorrent 1.1', 'creation date' => 1300000000)));
        $newHash = strtoupper($fixture->hash_info());
        rTorrent::$source = $fixture;

        // A dump IS published and DOES list the topic, so reg_time is
        // available -- and must still lose to what the torrent itself said.
        // The forum index memoizes fetched rows in a static that outlives a
        // test. Without this reset the second publication of the same forum
        // short-circuits and never writes into THIS test's state directory,
        // so cachedDump() reads an empty one and answers null.
        strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
        Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106',
            200, fiDumpAt(6879823, 2, str_repeat('A', 40), 7, 1788342804));
        strictAssertTrue(RuTrackerForumIndex::fetchDump(1106) !== null, 'the dump is published');

        mfQueueArrived($newHash);
        rXMLRPCRequest::queue('d.get_custom', true, false, array('1106'));

        strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the harvest commits');
        $payload = mfHandedOverTorrent(mfCreates()[0]);
        // A float, because Torrent::decode_integer() runs every bencoded
        // integer through floatval() -- so this is byte-for-byte what the
        // fixture carried, not a value this code chose.
        strictAssertSame(1300000000.0, $payload->meta('creation date'),
            "the author's own date is restored, not replaced by the tracker's reg_time");
        strictAssertTrue(strpos((string) $payload, '13:creation datei1300000000e') !== false,
            'and it re-encodes as the same bencoded integer the author wrote');
        strictAssertSame('mktorrent 1.1', $payload->meta('created by'),
            "and the author's own name survives the patch");
    });
});

// The same restore, held against a setter that stamps.
//
// The case above passes with metafetch's 'created by' restore deleted: this
// fork's Torrent::touch() writes nothing on a decoded torrent, so nothing ever
// overwrites the harvested name and putting it back is a no-op. Only the DATE
// half is pinned there, and only indirectly -- discard the snapshot and the
// date falls through to the tracker's reg_time, which differs.
//
// plugins/rutracker_check ships to upstream Novik/ruTorrent separately from
// php/Torrent.php (AGENTS.md, "Upstream PR Handoff"), where every setter still
// stamps both keys. Driving the same harvest through a Torrent of that shape
// is what makes the whole snapshot load-bearing: delete either half and this
// case fails while the rest of the file stays green.
$suite->test('the replacement keeps its own creator and date over a setter that stamps',
    function () use ($oldHash) {
    strictWithStateDir('rut-metafetch-authored-stamping', function () use ($oldHash) {
        ruTrackerChecker::reset();
        ruTrackerChecker::queueResult('createTorrent', null);
        rTorrent::$sourcesByHash = array();
        $raw = strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t',
            '', null, array('created by' => 'mktorrent 1.1', 'creation date' => 1300000000));
        $fixture = @new UpstreamStampingTorrent($raw);
        $newHash = strtoupper($fixture->hash_info());
        rTorrent::$source = $fixture;

        // Vacuity guard: without a stand-in that really stamps, every
        // assertion below would hold with no restore in metafetch.php at all.
        $control = @new UpstreamStampingTorrent($raw);
        $control->comment('anything');
        strictAssertSame('ruTorrent (PHP Class - Adrien Gibrat)', $control->meta('created by'),
            'the stand-in stamps its own name on a bare setter, as upstream does');
        strictAssertSame(UpstreamStampingTorrent::STAMP_DATE, $control->meta('creation date'),
            'and stamps its own date there too');

        strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
        Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106',
            200, fiDumpAt(6879823, 2, str_repeat('A', 40), 7, 1788342804));
        strictAssertTrue(RuTrackerForumIndex::fetchDump(1106) !== null, 'the dump is published');

        mfQueueArrived($newHash);
        rXMLRPCRequest::queue('d.get_custom', true, false, array('1106'));

        strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the harvest commits');
        $payload = mfHandedOverTorrent(mfCreates()[0]);
        strictAssertTrue($payload instanceof UpstreamStampingTorrent,
            'the harvest patched the stamping Torrent, not a quietly rebuilt plain one');
        strictAssertSame('mktorrent 1.1', $payload->meta('created by'),
            "the author's own name is put back over the stamp the setters wrote");
        strictAssertSame(1300000000.0, $payload->meta('creation date'),
            "and the author's own date is put back over it too");
    });
});

$suite->test('a chk-forum stored with transport whitespace still dates the replacement',
    function () use ($oldHash) {
    strictWithStateDir('rut-metafetch-trim', function () use ($oldHash) {
        // resolveForum() trims the same custom, so without trim() here the two
        // readers of one stored value disagree: layer 3 resolves the forum and
        // caches the dump, while the harvest reports that the cache had no
        // reg_time for the topic -- a false diagnosis of a cache that had it.
        ruTrackerChecker::reset();
        ruTrackerChecker::queueResult('createTorrent', null);
        rTorrent::$sourcesByHash = array();
        $fixture = @new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
        $newHash = strtoupper($fixture->hash_info());
        rTorrent::$source = $fixture;

        // The forum index memoizes fetched rows in a static that outlives a
        // test. Without this reset the second publication of the same forum
        // short-circuits and never writes into THIS test's state directory,
        // so cachedDump() reads an empty one and answers null.
        strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
        Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '1106',
            200, fiDumpAt(6879823, 2, str_repeat('A', 40), 7, 1788342804));
        strictAssertTrue(RuTrackerForumIndex::fetchDump(1106) !== null, 'the dump is published');

        mfQueueArrived($newHash);
        rXMLRPCRequest::queue('d.get_custom', true, false, array("  1106\n"));

        strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the harvest commits');
        strictAssertSame(1788342804, mfHandedOverTorrent(mfCreates()[0])->meta('creation date'),
            'the padded forum id resolved and the dump answered');
    });
});

$suite->test('a non-canonical chk-forum names no forum, and the dump its digits would name is never read',
    function () use ($oldHash) {
    // The other half of the shared predicate, and the reason it is shared:
    // "007" must not be read as forum 7. Each value below is one intval()
    // would answer 7 for, the dump for forum 7 IS published, and it carries
    // this very topic's reg_time -- so a reader that stopped calling
    // RuTrackerRpcValue::canonicalForumId() has an answer waiting and stamps a
    // date from a forum the stored value never named. Refusing the id is what
    // leaves the creation date unset.
    foreach (array('007', '+7', '7abc') as $index => $stored) {
        strictWithStateDir('rut-metafetch-noncanonical-' . $index,
            function () use ($oldHash, $stored) {
            ruTrackerChecker::reset();
            ruTrackerChecker::queueResult('createTorrent', null);
            rTorrent::$sourcesByHash = array();
            $fixture = @new Torrent(strictTorrentRaw('Youjo Senki II',
                'http://bt.t-ru.org/ann?pk=s3cr3t'));
            $newHash = strtoupper($fixture->hash_info());
            rTorrent::$source = $fixture;

            // The forum index memoizes fetched rows in a static that outlives
            // a test; without this reset the publication short-circuits and
            // never reaches THIS test's state directory.
            strictSetPrivateStatic('RuTrackerForumIndex', 'memo', array());
            Snoopy::queue(RuTrackerForumIndex::DUMP_URL . '7',
                200, fiDumpAt(6879823, 2, str_repeat('A', 40), 7, 1788342804));
            strictAssertTrue(RuTrackerForumIndex::fetchDump(7) !== null,
                'the forum 7 dump is published for ' . var_export($stored, true));

            mfQueueArrived($newHash);
            rXMLRPCRequest::queue('d.get_custom', true, false, array($stored));

            strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000),
                'the harvest still commits for ' . var_export($stored, true));
            strictAssertSame(null, mfHandedOverTorrent(mfCreates()[0])->meta('creation date'),
                'a chk-forum of ' . var_export($stored, true)
                    . ' resolves no forum, so nothing dates the replacement');
        });
    }
});

$suite->test('only a positive integer is accepted as a registration time', function () {
    // Guards the cache boundary rather than the parser: parseDump() sanitises
    // what it writes, but a hand-edited or legacy state file is still read
    // through here. The int32 ceiling is deliberately NOT applied -- a Unix
    // epoch is not an int32-domain value, and under that ceiling every
    // registration from 2038-01-19 on would silently read as unknown.
    foreach (array(
        'an epoch' => array(1788342804, 1788342804),
        'a canonical decimal string' => array('1788342804', 1788342804),
        'past the int32 ceiling' => array(2147483648, 2147483648),
        'zero is not a date' => array(0, null),
        'negative' => array(-1, null),
        'a float' => array(1788342804.5, null),
        'a padded string' => array(' 1788342804', null),
        'a leading zero' => array('01788342804', null),
        'not a number' => array('yesterday', null),
        'absent' => array(null, null),
        'an array' => array(array(1788342804), null),
    ) as $label => $case) {
        list($value, $expected) = $case;
        strictAssertSame($expected,
            strictInvoke('RuTrackerMetaFetch', 'canonicalRegistrationTime', array($value)), $label);
    }
});

exit($suite->run());
