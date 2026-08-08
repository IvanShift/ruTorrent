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
    rXMLRPCRequest::queue('d.get_custom', true, false, array($oldHash));  // wait poll: ours
    rXMLRPCRequest::queue('d.start', true, false, array());
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array()); // old torrent marks

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
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')), 'old torrent not stamped');
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
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')), 'old torrent not stamped');
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
    strictAssertSame(1, count(ruTrackerChecker::$logs), 'the reason is logged instead');
    strictAssertTrue(strpos(ruTrackerChecker::$logs[0], $oldHash) !== false, 'the log line names the torrent');
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

exit($suite->run());
