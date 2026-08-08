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
        self::$magnets[] = compact('magnet', 'isStart', 'directory', 'addition');
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

    // A seeding old torrent -> "1".
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1));
    $commands = $marks();
    strictAssertSame(array($oldHash, 'chk-meta-new', $newHash), $commands[0]->params, 'chk-meta-new unchanged');
    strictAssertSame(array($oldHash, 'chk-meta-until', (string) (1000 + 86400)), $commands[1]->params,
        'chk-meta-until unchanged');
    strictAssertSame(array($oldHash, 'chk-meta-run', '1'), $commands[2]->params,
        'a running old torrent is recorded as running');
    strictAssertTrue(strpos(implode(' ', ruTrackerChecker::$logs), 'old run state=running') !== false,
        'the layer-4 start line names the recorded run state');

    // Stopped and closed -> "0": the user stopped it on purpose, and the
    // replacement must inherit exactly that.
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(0, 0));
    strictAssertSame(array($oldHash, 'chk-meta-run', '0'), $marks()[2]->params,
        'a stopped old torrent is recorded as stopped');

    // Open but not started still counts as running: that is the same pair of
    // values createTorrent()'s own activation branch treats as "restore me".
    ruTrackerChecker::reset();
    rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(0, 1));
    strictAssertSame(array($oldHash, 'chk-meta-run', '1'), $marks()[2]->params,
        'a stopped-but-open old torrent is recorded as running');

    // An unreadable state may never be guessed into "running".
    ruTrackerChecker::reset();
    strictAssertSame(array($oldHash, 'chk-meta-run', '0'), $marks()[2]->params,
        'a failed state read is recorded as not running');
    strictAssertTrue(strpos(implode(' ', ruTrackerChecker::$logs), 'old run state=unreadable') !== false,
        'an unreadable state is logged as such, never as "stopped"');
});

// Shared harvest staging for the two directions below: metadata has arrived,
// the stub validates, the stub erase is confirmed, and createTorrent() reports
// success. $runMark is what begin() recorded on the old torrent.
function mfQueueHarvest($oldHash, $runMark)
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
    rXMLRPCRequest::queue('d.get_custom', true, false, array($runMark));   // chk-meta-run (old torrent)
    rXMLRPCRequest::queue('d.erase', true, false, array());
    rXMLRPCRequest::queue('d.hash', true, true, array());                  // the stub is gone
    return $newHash;
}

$suite->test('harvest starts the replacement when the marker says the old torrent was running', function () use ($oldHash) {
    $newHash = mfQueueHarvest($oldHash, '1');
    rXMLRPCRequest::queue('d.get_state', true, false, array(0)); // the replacement came up stopped
    rXMLRPCRequest::queue('d.start', true, false, array());

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is committed');
    $starts = rXMLRPCRequest::requestsFor('d.start');
    strictAssertSame(1, count($starts), 'the replacement is started exactly once');
    strictAssertSame($newHash, $starts[0]['commands'][0]->params, 'the NEW hash is started, not the old one');
    // The read must have happened while the old torrent still existed, i.e.
    // before createTorrent() erased it -- the only place the marker can be
    // read from at all.
    $keys = array_map(function ($request) { return $request['key']; }, rXMLRPCRequest::$requests);
    strictAssertTrue(array_search('d.start', $keys) > array_search('d.erase', $keys),
        'the marker is read and acted on around the replacement, not before the harvest');
});

$suite->test('harvest leaves the replacement stopped when the marker says the old torrent was stopped', function () use ($oldHash) {
    mfQueueHarvest($oldHash, '0');

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is committed');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.start')),
        'a torrent the user had stopped must not be resurrected by its replacement');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.get_state')),
        'a stopped marker is not even worth probing the replacement for');
});

$suite->test('harvest does not re-start a replacement that came up running by itself', function () use ($oldHash) {
    mfQueueHarvest($oldHash, '1');
    rXMLRPCRequest::queue('d.get_state', true, false, array(1)); // already started

    strictAssertSame(null, RuTrackerMetaFetch::pump($oldHash, 1000), 'the replacement is committed');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.start')), 'no redundant d.start');
    strictAssertTrue(strpos(implode(' ', ruTrackerChecker::$logs), 'already running') !== false,
        'the log says why no start was issued');
});

$suite->test('harvest logs the byte count, the hash match, the erase and what createTorrent returned', function () use ($oldHash) {
    $newHash = mfQueueHarvest($oldHash, '0');

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
    mfQueueHarvest($oldHash, '1');
    ruTrackerChecker::$createResult = ruTrackerChecker::STE_ERROR;

    strictAssertSame(ruTrackerChecker::STE_ERROR, RuTrackerMetaFetch::pump($oldHash, 1000),
        'createTorrent\'s own answer is passed through');
    strictAssertTrue(strpos(implode(' ', ruTrackerChecker::$logs), 'returned STE_ERROR') !== false,
        'the refusal is logged by name, not as a bare number');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.start')),
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
