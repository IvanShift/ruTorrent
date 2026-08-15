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

class rTorrent
{
    public static $magnets = array();
    public static $sendResult = null;
    public static $source = null;
    // Per-hash override for the harvest tests, which need distinct old/new
    // metainfo; unset for a hash and getSource() falls back to $source, same
    // as every begin()-only test above expects.
    public static $sourcesByHash = array();

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
        return array_key_exists($hash, self::$sourcesByHash) ? self::$sourcesByHash[$hash] : self::$source;
    }

    private static function erased($hash)
    {
        foreach (rXMLRPCRequest::$requests as $request)
            foreach ($request['commands'] as $command)
                if ($command->command === getCmd('d.erase') && (string) $command->params === (string) $hash)
                    return true;
        return false;
    }
}

$GLOBALS['topDirectory'] = '/data/';
require_once(testFindRepoRoot() . '/plugins/rutracker_check/metafetch.php');

$suite = new StrictTestSuite();
$oldHash = str_repeat('A', 40);
$newHash = str_repeat('B', 40);

$suite->test('begin refuses when the new hash already exists', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.hash', true, false, array($newHash)); // torrentExists -> true
    rXMLRPCRequest::queue('d.set_custom', true, false, array());   // chk-msg write
    $state = RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);
    strictAssertSame(ruTrackerChecker::STE_NOT_NEED, $state, 'the topic\'s current version is already present');
    strictAssertSame(0, count(rTorrent::$magnets), 'no magnet loaded');
    // Who put it there is unknowable (the user, another automation, or an
    // earlier incomplete run of this plugin), so the token records only the
    // successor hash and the sentence says only that it is present.
    strictAssertSame(1, count(ruTrackerChecker::$messages), 'exactly one chk-msg write');
    strictAssertSame(ruTrackerChecker::CHKMSG_SUPERSEDED . '|' . $newHash,
        ruTrackerChecker::$messages[0]['message'], 'superseded token carries the successor hash');
});

$suite->test('begin loads a stopped magnet with inline markers and starts the stub', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = $newHash;
    rXMLRPCRequest::queue('d.hash', true, true, array());                 // collision check: missing
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1)); // old torrent: seeding
    rXMLRPCRequest::queue('d.get_custom', true, false, array($oldHash));  // wait poll: ours
    rXMLRPCRequest::queue('d.start', true, false, array());
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom', 'd.set_custom'), true, false, array()); // old torrent marks

    $state = RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);
    strictAssertSame(ruTrackerChecker::STE_META_PENDING, $state, 'pending state');
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
    // The stub is a service download, and the label is what says so to
    // anything watching rTorrent's events -- the history plugin above all,
    // which otherwise logs the stub's arrival and removal as if the user had
    // added and deleted a torrent. The inline chk-meta-* markers cannot serve
    // that purpose: they are custom fields the event handlers never read.
    strictAssertSame(RuTrackerMetaFetch::SERVICE_LABEL, $sent['label'],
        'the stub carries the service label, and keeps it after metadata arrives');
    strictAssertSame('.', substr(RuTrackerMetaFetch::SERVICE_LABEL, 0, 1),
        'a leading dot is the convention that marks a label as service-only');
});

$suite->test('begin fails closed when the load never materialises', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = $newHash;
    rXMLRPCRequest::queue('d.hash', true, true, array());   // collision: missing
    // wait poll: always missing (queue empty -> the double answers with a fault)
    $state = RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'retryable failure');
});

$suite->test('begin returns STE_ERROR when the collision check itself fails', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = $newHash;
    rXMLRPCRequest::queue('d.hash', false, false, array()); // torrentExists -> null (transport failure)
    $state = RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);
    strictAssertSame(ruTrackerChecker::STE_ERROR, $state, 'transport failure surfaces as error, not retried as a load');
    strictAssertSame(0, count(rTorrent::$magnets), 'no magnet loaded');
});

$suite->test('begin returns STE_ERROR when sendMagnet fails', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = false; // e.g. service directory rejected
    rXMLRPCRequest::queue('d.hash', true, true, array()); // collision check: missing
    $state = RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);
    strictAssertSame(ruTrackerChecker::STE_ERROR, $state, 'a rejected load surfaces as error');
});

$suite->test('begin does not start, stamp or erase a foreign item at the same hash', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = $newHash;
    $foreignOldHash = str_repeat('C', 40);
    rXMLRPCRequest::queue('d.hash', true, true, array());                         // collision check: missing
    rXMLRPCRequest::queue('d.get_custom', true, false, array($foreignOldHash));   // wait poll: someone else's item

    $state = RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'foreign item is retryable, not fatal');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.start')), 'foreign item not started');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')), 'foreign item not erased');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom|d.set_custom')),
        'old torrent not stamped');
});

$suite->test('begin erases the stub and skips stamping when d.start fails', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = $newHash;
    rXMLRPCRequest::queue('d.hash', true, true, array());                 // collision check: missing
    rXMLRPCRequest::queue('d.get_custom', true, false, array($oldHash));  // wait poll: ours
    rXMLRPCRequest::queue('d.start', false, false, array());              // d.start fails
    rXMLRPCRequest::queue('d.erase', true, false, array());

    $state = RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000);
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'failed start is retryable, not a stuck orphan');
    $erased = rXMLRPCRequest::requestsFor('d.erase');
    strictAssertSame(1, count($erased), 'stub erased after failed start');
    strictAssertSame($newHash, $erased[0]['commands'][0]->params, 'erased the stub, not the old torrent');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom|d.set_custom')),
        'old torrent not stamped');
});

// pump()/harvest(): begin() only stamps the old download with chk-meta-new
// and chk-meta-until (markOldTorrent()); chk-meta-topic rides only on the
// stub, per the load addition in begin() and the design doc's "the stub
// carries mirror labels" (4.4 point 2). So pump reads two fields off the old
// hash, and -- only once metadata has actually arrived -- one more off the
// stub itself.

$suite->test('pump harvests in the mandated order once metadata arrived', function () use ($oldHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::$createResult = null; // createTorrent success contract
    rTorrent::$sourcesByHash = array();
    // hash_info() hashes only the info dict (Torrent.php:600), so the
    // fixture's real hash -- not the file-level $newHash placeholder used by
    // the begin() tests above -- is what harvest() must see and validate.
    $fixture = new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    $newHash = strtoupper($fixture->hash_info());
    rTorrent::$source = $fixture;

    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false,
        array($newHash, '999999'));                                       // chk-meta-new, chk-meta-until (old torrent)
    rXMLRPCRequest::queue('d.hash', true, false, array($newHash));        // stub exists
    rXMLRPCRequest::queue('d.is_meta', true, false, array('0'));          // metadata arrived
    rXMLRPCRequest::queue('d.get_custom', true, false, array('6879823')); // chk-meta-topic (stub)
    rXMLRPCRequest::queue('d.erase', true, false, array());
    rXMLRPCRequest::queue('d.hash', true, true, array());                 // verify gone

    $state = RuTrackerMetaFetch::pump($oldHash, 1000);
    strictAssertSame(null, $state, 'createTorrent success passthrough');
    strictAssertSame(1, count(ruTrackerChecker::$created), 'createTorrent called once');
    $payload = new Torrent(ruTrackerChecker::$created[0]['payload']);
    strictAssertSame('https://rutracker.org/forum/viewtopic.php?t=6879823', $payload->comment(), 'comment restored');
    // design doc 4.4-5в: comment lives outside the info dict, so patching it
    // must not change the hash createTorrent() is asked to replace with.
    strictAssertSame($newHash, strtoupper($payload->hash_info()), 'comment patch does not change info_hash');
    strictAssertSame($oldHash, ruTrackerChecker::$created[0]['old_hash'], 'replacement of the old torrent');
    // Order 1 (read before erase): rTorrent::getSource() goes dark (returns
    // false, like the real session-file-is-gone case) once a d.erase for
    // $newHash has actually been recorded. A harvest that erased first would
    // have hit that and taken the dropStub() branch instead of createTorrent
    // -- so reaching a populated, correctly-parsed $created here is itself
    // proof the bytes were read first.
    // Order 2 (erase before createTorrent): the fake createTorrent() records
    // whether the erase for the new hash had already landed at call time.
    strictAssertSame(true, ruTrackerChecker::$created[0]['erased_first'], 'erase preceded the replacement');
    $keys = array_map(function ($r) { return $r['key']; }, rXMLRPCRequest::$requests);
    strictAssertTrue(array_search('d.erase', $keys) !== false, 'erase happened');
});

$suite->test('harvest backfills an empty announce from the old torrent before erasing the stub', function () use ($oldHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::$createResult = null;
    rTorrent::$source = null;
    $newFixture = new Torrent(strictTorrentRaw('Youjo Senki II', ''));
    $newHash = strtoupper($newFixture->hash_info());
    rTorrent::$sourcesByHash = array(
        $newHash => $newFixture,
        $oldHash => new Torrent(strictTorrentRaw('Youjo Senki', 'http://bt.t-ru.org/ann?pk=s3cr3t')),
    );

    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    rXMLRPCRequest::queue('d.hash', true, false, array($newHash));
    rXMLRPCRequest::queue('d.is_meta', true, false, array('0'));
    rXMLRPCRequest::queue('d.get_custom', true, false, array('6879823'));
    rXMLRPCRequest::queue('d.erase', true, false, array());
    rXMLRPCRequest::queue('d.hash', true, true, array());

    $state = RuTrackerMetaFetch::pump($oldHash, 1000);
    strictAssertSame(null, $state, 'harvest still succeeds with a backfilled announce');
    $payload = new Torrent(ruTrackerChecker::$created[0]['payload']);
    strictAssertSame('http://bt.t-ru.org/ann?pk=s3cr3t', $payload->announce(), 'announce backfilled from the old torrent');
    strictAssertSame('https://rutracker.org/forum/viewtopic.php?t=6879823', $payload->comment(), 'comment still restored');
});

// design doc 4.4-5б: hash_info() of the harvested bytes must match the
// recorded chk-meta-new before they are ever handed to createTorrent(). A
// mismatch (corrupt/foreign metadata at the same session slot) must drop the
// stub and retry, never fall through to createTorrent()'s own legacy
// "unparseable == deleted topic" contract.
$suite->test('harvest drops the stub and retries when the fetched bytes hash to something other than chk-meta-new', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rTorrent::$sourcesByHash = array();
    // A real, parseable torrent -- but its own hash_info() is not $newHash,
    // the value begin() actually recorded on the old torrent.
    $mismatched = new Torrent(strictTorrentRaw('Wrong Metadata', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    rTorrent::$source = $mismatched;

    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    rXMLRPCRequest::queue('d.hash', true, false, array($newHash));        // stub exists
    rXMLRPCRequest::queue('d.is_meta', true, false, array('0'));          // metadata arrived
    rXMLRPCRequest::queue('d.get_custom', true, false, array('6879823')); // chk-meta-topic
    rXMLRPCRequest::queue('d.erase', true, false, array());
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array()); // clearMarks

    $state = RuTrackerMetaFetch::pump($oldHash, 1000);
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'mismatch retries, never reported as deleted');
    strictAssertSame(0, count(ruTrackerChecker::$created), 'createTorrent never sees unvalidated bytes');
    $erased = rXMLRPCRequest::requestsFor('d.erase');
    strictAssertSame(1, count($erased), 'the mismatched stub is erased');
    strictAssertSame($newHash, $erased[0]['commands'][0]->params, 'erased the stub, not the old torrent');
    // An abort reason is diagnostics, not a verdict worth a sentence in the
    // UI: chk-msg is cleared so no stale token survives, and the reason goes
    // to the debug log.
    strictAssertSame(1, count(ruTrackerChecker::$messages), 'chk-msg is written once');
    strictAssertSame('', ruTrackerChecker::$messages[0]['message'], 'cleared, not filled with prose');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'dropped stub',
        'the reason is logged instead');
    strictAssertTrue(strpos($line, $oldHash) !== false, 'the log line names the torrent');
});

$suite->test('harvest stays pending when the erased stub is still present', function () use ($oldHash) {
    ruTrackerChecker::reset();
    ruTrackerChecker::$createResult = null;
    rTorrent::$sourcesByHash = array();
    $fixture = new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    $newHash = strtoupper($fixture->hash_info());
    rTorrent::$source = $fixture;

    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    rXMLRPCRequest::queue('d.hash', true, false, array($newHash));        // stub exists
    rXMLRPCRequest::queue('d.is_meta', true, false, array('0'));          // metadata arrived
    rXMLRPCRequest::queue('d.get_custom', true, false, array('6879823')); // chk-meta-topic
    rXMLRPCRequest::queue('d.erase', true, false, array());
    rXMLRPCRequest::queue('d.hash', true, false, array($newHash));        // still present after erase

    $state = RuTrackerMetaFetch::pump($oldHash, 1000);
    strictAssertSame(ruTrackerChecker::STE_META_PENDING, $state, 'a stuck erase retries instead of orphaning the stub');
    strictAssertSame(0, count(ruTrackerChecker::$created), 'createTorrent must not run while the stub still exists');
});

$suite->test('pump aborts early when the tracker rejects the new hash', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    rXMLRPCRequest::queue('d.hash', true, false, array($newHash));
    rXMLRPCRequest::queue('d.is_meta', true, false, array('1'));     // still a stub
    rXMLRPCRequest::queue('t.multicall', true, false, array('3')); // flat: one row, failed_counter=3
    rXMLRPCRequest::queue('d.erase', true, false, array());
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array()); // clear marks

    $state = RuTrackerMetaFetch::pump($oldHash, 1000);
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $state, 'early abort is retryable');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.erase')), 'the rejected stub is erased, not left behind');
    strictAssertSame('', ruTrackerChecker::$messages[0]['message'], 'chk-msg cleared, no stale token left behind');
    strictAssertSame(1, count(ruTrackerChecker::$logs), 'the tracker rejection is logged');
});

$suite->test('pump enforces the deadline', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false,
        array($newHash, '500'));          // deadline 500 < now 1000
    rXMLRPCRequest::queue('d.hash', true, false, array($newHash));
    rXMLRPCRequest::queue('d.is_meta', true, false, array('1'));
    rXMLRPCRequest::queue('t.multicall', true, false, array('0')); // flat: one row, failed_counter=0
    rXMLRPCRequest::queue('d.erase', true, false, array());
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        RuTrackerMetaFetch::pump($oldHash, 1000), 'deadline expiry rolls back');
    strictAssertSame('', ruTrackerChecker::$messages[0]['message'], 'chk-msg cleared, not filled with prose');
    strictAssertSame(1, count(ruTrackerChecker::$logs), 'the expired deadline is logged');
});

$suite->test('pump keeps waiting while the stub is healthy', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false,
        array($newHash, '999999'));
    rXMLRPCRequest::queue('d.hash', true, false, array($newHash));
    rXMLRPCRequest::queue('d.is_meta', true, false, array('1'));
    rXMLRPCRequest::queue('t.multicall', true, false, array('0')); // flat: one row, failed_counter=0
    strictAssertSame(ruTrackerChecker::STE_META_PENDING,
        RuTrackerMetaFetch::pump($oldHash, 1000), 'still pending');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')), 'a healthy wait touches nothing');
});

$suite->test('pump clears state when the stub vanished', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false,
        array($newHash, '999999'));
    rXMLRPCRequest::queue('d.hash', true, true, array());            // element missing
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        RuTrackerMetaFetch::pump($oldHash, 1000), 'vanished stub returns to the queue');
});

$suite->test('pump hard-errors and clears markers when the recorded new hash is corrupt', function () use ($oldHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('not-a-hash', '999999'));
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());

    strictAssertSame(ruTrackerChecker::STE_ERROR,
        RuTrackerMetaFetch::pump($oldHash, 1000), 'a corrupt marker cannot be pursued further');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.hash')), 'no existence probe is issued against a bad hash');
});

// --- Every abort reason reaches the log in English --------------------------
//
// These three strings are the only prose the metadata fetch produces, and they
// go to the debug log rather than to chk-msg (which carries a token the browser
// localises). The log is the maintainer's, not the torrent owner's, so it is
// English -- asserted on the recorded text, since a translated log line would
// otherwise sail through every other test in this file unnoticed.

$suite->test('the three metadata-fetch abort reasons are logged in English', function () use ($oldHash, $newHash) {
    // 1. The tracker rejected the new hash (failed_counter > 0).
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    rXMLRPCRequest::queue('d.hash', true, false, array($newHash));
    rXMLRPCRequest::queue('d.is_meta', true, false, array('1'));
    rXMLRPCRequest::queue('t.multicall', true, false, array('3'));
    rXMLRPCRequest::queue('d.erase', true, false, array());
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());
    RuTrackerMetaFetch::pump($oldHash, 1000);
    $rejected = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'dropped stub',
        'the tracker rejection is logged');
    strictAssertEnglish($rejected, 'the tracker-rejection reason');
    strictAssertTrue(strpos($rejected, 'the tracker rejected the new hash') !== false,
        'the rejection reason still says what happened: ' . $rejected);

    // 2. The deadline expired.
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '500'));
    rXMLRPCRequest::queue('d.hash', true, false, array($newHash));
    rXMLRPCRequest::queue('d.is_meta', true, false, array('1'));
    rXMLRPCRequest::queue('t.multicall', true, false, array('0'));
    rXMLRPCRequest::queue('d.erase', true, false, array());
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());
    RuTrackerMetaFetch::pump($oldHash, 1000);
    $expired = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'dropped stub',
        'the expired deadline is logged');
    strictAssertEnglish($expired, 'the expired-deadline reason');
    strictAssertTrue(strpos($expired, 'deadline') !== false,
        'the deadline reason still says what happened: ' . $expired);

    // 3. The fetched metadata failed validation.
    ruTrackerChecker::reset();
    rTorrent::$sourcesByHash = array();
    rTorrent::$source = new Torrent(strictTorrentRaw('Wrong Metadata', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    rXMLRPCRequest::queue('d.hash', true, false, array($newHash));
    rXMLRPCRequest::queue('d.is_meta', true, false, array('0'));
    rXMLRPCRequest::queue('d.get_custom', true, false, array('6879823'));
    rXMLRPCRequest::queue('d.erase', true, false, array());
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());
    RuTrackerMetaFetch::pump($oldHash, 1000);
    $invalid = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'dropped stub',
        'the failed validation is logged');
    strictAssertEnglish($invalid, 'the failed-validation reason');
    strictAssertTrue(strpos($invalid, 'validation') !== false,
        'the validation reason still says what happened: ' . $invalid);

    // Nothing else the fetch logs may slip back into another language either.
    foreach (ruTrackerChecker::$logs as $line)
        strictAssertEnglish($line, 'every metadata-fetch log line');
});

// --- The run-state marker (chk-meta-run) ------------------------------------
//
// createTorrent() reads the old torrent's started/open state at its own commit
// point and leaves the replacement stopped when it reads "neither". In this
// flow that read happens one or more cycles -- hours -- after begin() picked
// the candidate out of the seeding view, so anything that stops the old
// torrent in between silently turns into "leave the replacement stopped".
// begin() records the state at decision time; harvest() honours it.

$suite->test('begin records the old torrent run state next to chk-meta-new and chk-meta-until', function () use ($oldHash, $newHash) {
    $marks = function () use ($oldHash, $newHash) {
        rTorrent::$magnets = array();
        rTorrent::$sendResult = $newHash;
        rXMLRPCRequest::queue('d.hash', true, true, array());                // collision check: missing
        rXMLRPCRequest::queue('d.get_custom', true, false, array($oldHash)); // wait poll: ours
        rXMLRPCRequest::queue('d.start', true, false, array());
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom', 'd.set_custom'), true, false, array());
        strictAssertSame(ruTrackerChecker::STE_META_PENDING,
            RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, 'http://bt.t-ru.org/ann?pk=s3cr3t', 1000),
            'the fetch begins');
        $stamps = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom|d.set_custom');
        strictAssertSame(1, count($stamps), 'the old torrent is stamped in exactly one multicall');
        return $stamps[0]['commands'];
    };

    // A seeding old torrent -> "started".
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1));
    $commands = $marks();
    strictAssertSame(array($oldHash, 'chk-meta-new', $newHash), $commands[0]->params, 'chk-meta-new unchanged');
    strictAssertSame(array($oldHash, 'chk-meta-until', (string) (1000 + 86400)), $commands[1]->params,
        'chk-meta-until unchanged');
    strictAssertSame(array($oldHash, 'chk-meta-run', 'started'), $commands[2]->params,
        'a started old torrent is recorded as started');
    strictAssertTrue(strpos(implode(' ', ruTrackerChecker::$logs), 'old run state=started') !== false,
        'the layer-4 start line names the recorded run state');

    // Stopped and closed -> "stopped": the user stopped it on purpose, and the
    // replacement must inherit exactly that.
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(0, 0));
    strictAssertSame(array($oldHash, 'chk-meta-run', 'stopped'), $marks()[2]->params,
        'a stopped old torrent is recorded as stopped');

    // Open but not started is ruTorrent's pause: a state of its own, because
    // createTorrent()'s restoreExistingTorrent() answers it with d.open, not
    // with d.start. One bit could not tell the two apart.
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(0, 1));
    strictAssertSame(array($oldHash, 'chk-meta-run', 'open'), $marks()[2]->params,
        'a paused old torrent is recorded as paused, not as started');
    strictAssertTrue(strpos(implode(' ', ruTrackerChecker::$logs), 'old run state=open') !== false,
        'the paused state is named in the log too');

    // An unreadable state may never be guessed into "started".
    ruTrackerChecker::reset();
    strictAssertSame(array($oldHash, 'chk-meta-run', 'stopped'), $marks()[2]->params,
        'a failed state read is recorded as not running');
    strictAssertTrue(strpos(implode(' ', ruTrackerChecker::$logs), 'old run state=unreadable') !== false,
        'an unreadable state is logged as such, never as "stopped"');
});

// --- begin() names the outcome of every exit ---------------------------------
//
// The "begin" line is written before anything is loaded, so on its own it says
// only that a fetch was attempted. A misconfigured service directory used to
// produce that one identical line an hour, forever, with nothing to say why.
// Every way out of begin() now names what happened.

$suite->test('every begin exit logs its outcome', function () use ($oldHash, $newHash) {
    $announce = 'http://bt.t-ru.org/ann?pk=s3cr3t';

    // 1. The collision probe itself failed.
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rXMLRPCRequest::queue('d.hash', false, false, array());
    strictAssertSame(ruTrackerChecker::STE_ERROR,
        RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, $announce, 1000), 'transport failure');
    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'already in the client',
        'an unreadable collision probe says so');

    // 2. The successor is already present.
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue('d.hash', true, false, array($newHash));
    rXMLRPCRequest::queue('d.set_custom', true, false, array());
    strictAssertSame(ruTrackerChecker::STE_NOT_NEED,
        RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, $announce, 1000), 'already superseded');
    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'nothing left to fetch',
        'the already-present successor says so');

    // 3. The service load was refused -- the misconfigured-directory case.
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = false;
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1));
    strictAssertSame(ruTrackerChecker::STE_ERROR,
        RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, $announce, 1000), 'refused load');
    $refused = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'refused the service load',
        'a refused load names the service directory it was refused for');
    strictAssertTrue(strpos($refused, '/data/.chk-meta') !== false,
        'the one thing worth checking is in the line: ' . $refused);

    // 4. Someone else's item sits at that hash.
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = $newHash;
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1));
    rXMLRPCRequest::queue('d.get_custom', true, false, array(str_repeat('C', 40)));
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, $announce, 1000), 'foreign item');
    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'belongs to someone else',
        'a foreign item at the same hash says so');

    // 5. The stub would not start.
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = $newHash;
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1));
    rXMLRPCRequest::queue('d.get_custom', true, false, array($oldHash));
    rXMLRPCRequest::queue('d.start', false, false, array());
    rXMLRPCRequest::queue('d.erase', true, false, array());
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, $announce, 1000), 'unstartable stub');
    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'could not start the metadata stub',
        'an unstartable stub says so');

    // 6. The load never materialised (the poll budget ran out).
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = $newHash;
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1));
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, $announce, 1000), 'stub never appeared');
    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'never appeared',
        'an expired wait says so');

    // 7. The success path names the deadline it will wait until.
    ruTrackerChecker::reset();
    rTorrent::$magnets = array();
    rTorrent::$sendResult = $newHash;
    rXMLRPCRequest::queue('d.hash', true, true, array());
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1));
    rXMLRPCRequest::queue('d.get_custom', true, false, array($oldHash));
    rXMLRPCRequest::queue('d.start', true, false, array());
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom', 'd.set_custom'), true, false, array());
    strictAssertSame(ruTrackerChecker::STE_META_PENDING,
        RuTrackerMetaFetch::begin($oldHash, $newHash, 6879823, $announce, 1000), 'the fetch begins');
    $loaded = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'loaded the metadata stub',
        'the successful load says so');
    strictAssertTrue(strpos($loaded, (string) (1000 + 86400)) !== false,
        'and names the deadline it will wait until: ' . $loaded);

    // Not one of those lines may leak the passkey the magnet carries.
    foreach (ruTrackerChecker::$logs as $line) {
        strictAssertEnglish($line, 'every begin log line');
        strictAssertTrue(strpos($line, 's3cr3t') === false, 'no log line carries the passkey: ' . $line);
    }
});

// Shared harvest staging for the directions below: metadata has arrived, the
// stub validates, the stub erase is confirmed, and createTorrent() reports
// success.
//
// $live is what a d.get_state/d.is_open read of the old torrent answers at
// commit time -- array(state, is_open), or null when that read fails. $runMark
// is what begin() recorded in chk-meta-run; it is only ever consulted when the
// live read failed, so a test that expects it to be read must pass it.
function mfQueueHarvest($oldHash, $live, $runMark = null)
{
    ruTrackerChecker::reset();
    ruTrackerChecker::$createResult = null; // createTorrent()'s success contract
    rTorrent::$sourcesByHash = array();
    $fixture = new Torrent(strictTorrentRaw('Youjo Senki II', 'http://bt.t-ru.org/ann?pk=s3cr3t'));
    $newHash = strtoupper($fixture->hash_info());
    rTorrent::$source = $fixture;

    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    rXMLRPCRequest::queue('d.hash', true, false, array($newHash));         // stub exists
    rXMLRPCRequest::queue('d.is_meta', true, false, array('0'));           // metadata arrived
    rXMLRPCRequest::queue('d.get_custom', true, false, array('6879823'));  // chk-meta-topic (stub)
    rXMLRPCRequest::queue('d.erase', true, false, array());
    rXMLRPCRequest::queue('d.hash', true, true, array());                  // the stub is gone
    // The commit-point read of the old torrent's run state; the first
    // d.get_state|d.is_open of the harvest is always this one.
    if ($live === null)
        rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), false, false, array());
    else
        rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, $live);
    if ($runMark !== null)
        rXMLRPCRequest::queue('d.get_custom', true, false, array($runMark)); // chk-meta-run (old torrent)
    return $newHash;
}

$suite->test('harvest restores the replacement when the old torrent was started at commit time', function () use ($oldHash) {
    $newHash = mfQueueHarvest($oldHash, array(1, 1));
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(0, 0)); // came up closed and stopped
    rXMLRPCRequest::queue(array('d.open', 'd.start'), true, false, array());
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1)); // and now really runs

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is committed');
    $starts = rXMLRPCRequest::requestsFor('d.open|d.start');
    strictAssertSame(1, count($starts), 'the replacement is started exactly once');
    // ruTorrent's own UI opens before it starts (plugins/httprpc/action.php,
    // case "start"); a d.start alone can leave a closed download closed.
    strictAssertSame($newHash, $starts[0]['commands'][0]->params, 'd.open targets the NEW hash');
    strictAssertSame($newHash, $starts[0]['commands'][1]->params, 'and d.start after it, on the same hash');
    // The state must be read from the old torrent while it still exists, i.e.
    // before createTorrent() erased it.
    $keys = array_map(function ($request) { return $request['key']; }, rXMLRPCRequest::$requests);
    strictAssertTrue(array_search('d.open|d.start', $keys) > array_search('d.erase', $keys),
        'the run state is acted on around the replacement, not before the harvest');
    // Measured, never assumed: the line reports the state that was read back.
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'd.open+d.start accepted',
        'the verified start is logged exactly once');
    strictAssertTrue(strpos($line, 'now state=1 open=1') !== false,
        'the line reports the state that was measured afterwards: ' . $line);
});

$suite->test('harvest gives a paused old torrent a paused replacement', function () use ($oldHash) {
    $newHash = mfQueueHarvest($oldHash, array(0, 1)); // ruTorrent's pause: d.stop alone, still open
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(0, 0));
    rXMLRPCRequest::queue('d.open', true, false, array());
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(0, 1));

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is committed');
    $opens = rXMLRPCRequest::requestsFor('d.open');
    strictAssertSame(1, count($opens), 'a paused predecessor is answered with d.open');
    strictAssertSame($newHash, $opens[0]['commands'][0]->params, 'on the new hash');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
        'a paused torrent must never come back seeding');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.start')), 'and never through a bare d.start either');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'd.open accepted',
        'the verified reopen is logged exactly once');
    strictAssertTrue(strpos($line, 'now state=0 open=1') !== false,
        'the line reports the measured pair: ' . $line);
});

// The defect this pins: a marker written up to $rutrackerMetaDeadline (86400s)
// ago must never outrank a run state that can still be read right now. The
// stopped read is the same one createTorrent() takes at its own commit point,
// and it is what decides whether the replacement comes up running.
$suite->test('a readable stopped state at commit time beats a was-running marker', function () use ($oldHash) {
    mfQueueHarvest($oldHash, array(0, 0), 'started'); // the user stopped it after the fetch began

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is committed');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
        'a torrent the user had stopped must not be resurrected by its replacement');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.start')), 'not by a bare start either');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open')), 'and not even reopened');
    // chk-meta-topic is the one d.get_custom of a harvest; a second one would
    // be the marker, which a readable state must not even bother to consult.
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.get_custom')),
        'the marker is not consulted at all while the live read answers');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'run state before the replacement',
        'the state the decision was made on is recorded');
    strictAssertTrue(strpos($line, 'started=0 open=0') !== false, 'with the values themselves: ' . $line);
    strictAssertTrue(strpos($line, 'live read') !== false, 'and where they came from: ' . $line);
});

$suite->test('the marker is the fallback when the old torrent can no longer be read', function () use ($oldHash) {
    mfQueueHarvest($oldHash, null, 'started');
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1)); // already running

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is committed');
    strictAssertSame(2, count(rXMLRPCRequest::requestsFor('d.get_custom')),
        'the marker is read only after the live read failed');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'run state before the replacement',
        'the state the decision was made on is recorded');
    strictAssertTrue(strpos($line, 'chk-meta-run') !== false, 'named as the marker, not as a live read: ' . $line);
    strictAssertTrue(strpos($line, 'started=1') !== false, 'and carrying what the marker said: ' . $line);
});

// decodeRunState()'s own branches, reached only through the marker fallback
// above ('started' is already pinned by the test just above). Backward
// compatibility: the first version of this marker wrote plain '1'/'0'
// instead of the current started/open/stopped words.
$suite->test('the legacy "1" marker restores the replacement exactly like "started"', function () use ($oldHash) {
    $newHash = mfQueueHarvest($oldHash, null, '1');
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(0, 0)); // came up closed and stopped
    rXMLRPCRequest::queue(array('d.open', 'd.start'), true, false, array());
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1)); // and now really runs

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is committed');
    $starts = rXMLRPCRequest::requestsFor('d.open|d.start');
    strictAssertSame(1, count($starts), 'the legacy "1" marker is honoured exactly like "started"');
    strictAssertSame($newHash, $starts[0]['commands'][0]->params, 'd.open targets the new hash');
    strictAssertSame($newHash, $starts[0]['commands'][1]->params, 'and d.start after it, on the same hash');
});

$suite->test('the "open" marker restores the replacement as paused, never as started', function () use ($oldHash) {
    $newHash = mfQueueHarvest($oldHash, null, 'open');
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(0, 0)); // came up closed and stopped
    rXMLRPCRequest::queue('d.open', true, false, array());
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(0, 1)); // and now reopened

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is committed');
    $opens = rXMLRPCRequest::requestsFor('d.open');
    strictAssertSame(1, count($opens), 'the "open" marker reopens the replacement');
    strictAssertSame($newHash, $opens[0]['commands'][0]->params, 'on the new hash');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
        'but never starts it: the marker recorded a pause, not a run');
});

// A marker nobody wrote -- an absent read, a genuinely empty custom, the
// legacy '0', or plain garbage -- must decode the SAFE way: as stopped, so a
// replacement is never started on the strength of a value nobody actually
// recorded.
$suite->test('an absent, empty, legacy-stopped or unrecognised marker decodes to stopped', function () use ($oldHash) {
    foreach (array(null, '', '0', 'bogus') as $marker) {
        mfQueueHarvest($oldHash, null, $marker);

        strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000),
            'the replacement is committed for marker ' . var_export($marker, true));
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
            'marker ' . var_export($marker, true) . ' must never start the replacement');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open')),
            'marker ' . var_export($marker, true) . ' must never even reopen the replacement');
        strictAssertOneLogMatching(ruTrackerChecker::$logs, 'neither open nor started',
            'marker ' . var_export($marker, true) . ' is treated as neither open nor started');
    }
});

$suite->test('harvest leaves the replacement stopped when the old torrent was stopped', function () use ($oldHash) {
    mfQueueHarvest($oldHash, array(0, 0));

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is committed');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
        'a torrent the user had stopped must not be resurrected by its replacement');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.get_state|d.is_open')),
        'a stopped predecessor is not even worth probing the replacement for');
    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'neither open nor started',
        'the deliberate do-nothing says so, exactly like createTorrent() does');
});

$suite->test('harvest does not re-start a replacement that came up running by itself', function () use ($oldHash) {
    mfQueueHarvest($oldHash, array(1, 1));
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1)); // already started

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is committed');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')), 'no redundant start');
    strictAssertTrue(strpos(implode(' ', ruTrackerChecker::$logs), 'already running') !== false,
        'the log says why no start was issued');
});

// The failure this whole layer-4 work was chasing: a replacement sitting at
// state 0, closed, with correct metainfo. An XMLRPC ack alone must never be
// logged as a success.
$suite->test('an accepted but ineffective start is logged as what it is', function () use ($oldHash) {
    mfQueueHarvest($oldHash, array(1, 1));
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(0, 0));
    rXMLRPCRequest::queue(array('d.open', 'd.start'), true, false, array()); // the daemon says yes
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(0, 0)); // and nothing happened
    rXMLRPCRequest::queue(array('d.open', 'd.start'), true, false, array());
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(0, 0));

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is still committed');
    strictAssertSame(2, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
        'the start is retried once, like activateReplacement() does');
    strictAssertSame(0, count(strictLogsMatching(ruTrackerChecker::$logs, 'now state=')),
        'no success line may be written for a start that did nothing');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'still state=0 open=0',
        'the measured, unchanged state is what gets logged');
    strictAssertTrue(strpos($line, 'accepted') !== false,
        'together with the fact that the daemon accepted the command: ' . $line);
    foreach (ruTrackerChecker::$logs as $log) strictAssertEnglish($log, 'every activation log line');
});

$suite->test('harvest logs the byte count, the hash match, the erase and what createTorrent returned', function () use ($oldHash) {
    $newHash = mfQueueHarvest($oldHash, array(0, 0));

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is committed');
    $joined = implode("\n", ruTrackerChecker::$logs);
    strictAssertTrue(strpos($joined, 'metadata arrived for ' . $newHash) !== false, 'the arrival is logged');
    strictAssertTrue(strpos($joined, 'hash matched=yes') !== false, 'the hash match is logged');
    strictAssertTrue(preg_match('/bytes=[1-9]\d*/', $joined) === 1, 'the byte count is logged');
    strictAssertTrue(strpos($joined, 'service item erased=yes') !== false, 'the confirmed erase is logged');
    strictAssertTrue(strpos($joined, 'returned success') !== false, 'the createTorrent answer is logged');
    foreach (ruTrackerChecker::$logs as $line) strictAssertEnglish($line, 'every harvest log line');
});

$suite->test('harvest names the STE_* code when createTorrent refuses the replacement', function () use ($oldHash) {
    mfQueueHarvest($oldHash, array(1, 1));
    ruTrackerChecker::$createResult = ruTrackerChecker::STE_ERROR;

    strictAssertSame(ruTrackerChecker::STE_ERROR, RuTrackerMetaFetch::pump($oldHash, 1000),
        'createTorrent\'s own answer is passed through');
    strictAssertTrue(strpos(implode(' ', ruTrackerChecker::$logs), 'returned STE_ERROR') !== false,
        'the refusal is logged by name, not as a bare number');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
        'a failed replacement must never be started');
});

$suite->test('a healthy pending stub logs that it is still a stub, with the reason it keeps waiting', function () use ($oldHash, $newHash) {
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array($newHash, '999999'));
    rXMLRPCRequest::queue('d.hash', true, false, array($newHash));
    rXMLRPCRequest::queue('d.is_meta', true, false, array('1'));
    rXMLRPCRequest::queue('t.multicall', true, false, array('0'));

    strictAssertSame(ruTrackerChecker::STE_META_PENDING, RuTrackerMetaFetch::pump($oldHash, 1000), 'still pending');
    $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'still a metadata stub',
        'a waiting stub says so exactly once per cycle');
    strictAssertEnglish($line, 'the still-a-stub line');
});

exit($suite->run());
