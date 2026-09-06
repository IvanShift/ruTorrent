<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/TorrentSequenceFixtures.php');

/*
 * UpstreamStampingTorrent -- a Torrent whose every setter stamps 'created by'
 * and 'creation date', as upstream Novik/ruTorrent's does. It lives in
 * tests/php/UpstreamStampingTorrentFixture.php because three separate places
 * carry the same snapshot-and-restore for the same reason; the file says why.
 * The case at the end of this file is what holds action.php's restore to it.
 */
require_once(__DIR__ . '/../../php/UpstreamStampingTorrentFixture.php');

/**
 * What plugins/edit/action.php does to a torrent.
 *
 * action.php is a top level script -- it reads $_REQUEST, asks rtorrent for
 * the session path and hands the result to rTorrent::sendTorrent() over SCGI --
 * so it cannot be included. What replay() below runs is therefore not a
 * transcription of it: editSequenceSource() cuts the script's own torrent
 * editing block out of the file on disk and eval()s it, the way the
 * rutracker_check suites eval() one class out of check.php. So an edit to the
 * script's torrent-editing block does fail these tests, which is the point --
 * the previous version of this file transcribed the sequence by hand and said
 * so, and a suite that pins a copy attests to the copy and to nothing else.
 *
 * What is guarded is exactly what the extraction covers: the statements
 * between the two anchors, plus the requirement that nothing between the end
 * anchor and the handover to rTorrent::sendTorrent() touches $torrent. The RPC
 * either side of the block, the request parsing and the error paths are not
 * exercised here. The bencoded bytes are asserted whole, because those bytes
 * are what is handed to rtorrent.
 */
class EditActionSequenceTest extends TestCase
{
	use TorrentSequenceFixtures;

	/**
	 * Everything action.php does to the Torrent object, taken from the file
	 * itself rather than copied.
	 *
	 * The cut runs from the body of `if( !$torrent->errors() )` down to and
	 * including the `unset($torrent->{'rtorrent'})` that ends the editing --
	 * two anchors that bracket exactly the statements which touch $torrent and
	 * nothing of the RPC either side of them. Both are required to be present
	 * and in that order; a file that no longer has them aborts loudly here
	 * instead of quietly testing a shorter sequence.
	 *
	 * The guards below are what makes a mis-cut visible. The block must not
	 * mention the RPC identifiers that live after the end anchor, and it must
	 * still gate on all three of the script's edit flags -- so a cut that ran
	 * on into `$eReq`/`sendTorrent()`, or one that stopped before the setters,
	 * fails as a broken extraction rather than as a mysterious assertion.
	 *
	 * One guard looks past the end anchor instead of inside the cut: the
	 * source from there to `rTorrent::sendTorrent(` must not name $torrent at
	 * all. That is what makes the cut the WHOLE edit rather than a prefix of
	 * it, and without it a setter added after the anchor passed unnoticed.
	 */
	private static function editSequenceSource()
	{
		static $block = null;
		if ($block !== null) {
			return $block;
		}
		$path = __DIR__ . '/../../../plugins/edit/action.php';
		$source = file_get_contents($path);
		if ($source === false) {
			throw new RuntimeException("plugins/edit/action.php could not be read");
		}
		$startAnchor = 'if( !$torrent->errors() )';
		$endAnchor = "unset(\$torrent->{'rtorrent'});";
		$start = strpos($source, $startAnchor);
		if ($start === false) {
			throw new RuntimeException(
				"plugins/edit/action.php no longer contains {$startAnchor}; the edit sequence cannot be located");
		}
		$brace = strpos($source, '{', $start + strlen($startAnchor));
		$end = strpos($source, $endAnchor, $start);
		if ($brace === false || $end === false) {
			throw new RuntimeException(
				"plugins/edit/action.php no longer contains the end of the edit sequence");
		}
		// Into a local, not straight into the memo: a guard below that fires
		// after $block was already filled would abort only the FIRST test and
		// let the other ten run on an extraction it had just rejected.
		$cut = substr($source, $brace + 1, ($end + strlen($endAnchor)) - ($brace + 1));
		// Nothing between the end anchor and the handover to rtorrent may
		// touch $torrent: only then is the extracted block the WHOLE edit.
		// A setter added there would run after the restore loop and undo it,
		// with every assertion in this file still green -- which is the data
		// loss these tests exist for. Demonstrated before this guard was
		// written: inserting $torrent->comment(...) on the line after the end
		// anchor left all eleven tests in this file passing.
		$tailStart = $end + strlen($endAnchor);
		$handover = strpos($source, 'rTorrent::sendTorrent(', $tailStart);
		if ($handover === false) {
			throw new RuntimeException(
				"plugins/edit/action.php no longer hands the edited torrent to rTorrent::sendTorrent();"
				. " the extent of the edit sequence cannot be established");
		}
		$tail = substr($source, $tailStart, $handover - $tailStart);
		if (strpos($tail, '$torrent') !== false) {
			throw new RuntimeException(
				"plugins/edit/action.php uses \$torrent after {$endAnchor} and before"
				. " rTorrent::sendTorrent(); the extracted sequence is no longer the whole edit");
		}
		foreach (array('$req', 'rXMLRPC', 'sendTorrent', '$errors') as $beyond) {
			if (strpos($cut, $beyond) !== false) {
				throw new RuntimeException(
					"the extracted edit sequence ran past its end anchor: it mentions {$beyond}");
			}
		}
		foreach (array('$setPrivate', '$setTrackers', '$setComment') as $flag) {
			if (strpos($cut, $flag) === false) {
				throw new RuntimeException(
					"the extracted edit sequence is incomplete: it never gates on {$flag}");
			}
		}
		$block = $cut;
		return $block;
	}

	/**
	 * Run that block against $torrent with the variables action.php has
	 * already assembled from the request in scope. $announce_list is what the
	 * script built out of the tracker textarea and $trackersCount is the
	 * number of non-empty lines in it; the three set_* flags are the tick
	 * boxes. eval() shares this method's scope, so the block reads and writes
	 * exactly these locals, as it does the script's globals in production.
	 */
	private function replay($torrent, $options)
	{
		$setPrivate    = isset($options['private']);
		$private       = $setPrivate ? $options['private'] : null;
		$setTrackers   = isset($options['announce_list']);
		$announce_list = $setTrackers ? $options['announce_list'] : array();
		$trackersCount = 0;
		foreach ($announce_list as $group) {
			$trackersCount += count($group);
		}
		$setComment    = array_key_exists('comment', $options);
		$comment       = $setComment ? $options['comment'] : null;

		eval(self::editSequenceSource());
	}

	// ---- trackers --------------------------------------------------------

	/**
	 * More than one tracker: announce holds the first and announce-list holds
	 * the groups. The list the torrent came with is cleared first, so what is
	 * written is the new list and nothing of the old one.
	 */
	public function testSettingSeveralTrackersReplacesBothKeys()
	{
		$torrent = new Torrent($this->announceListTorrent());
		$this->replay($torrent, array('announce_list' => array(
			array('http://new.test/announce'),
			array('udp://new.test/announce'),
		)));

		$written = (string)$torrent;
		$expected = $this->bdict(array(
			'announce'      => $this->bstr('http://new.test/announce'),
			'announce-list' => $this->bannounceList(array(
				array('http://new.test/announce'),
				array('udp://new.test/announce'),
			)),
			'created by'    => $this->bstr('uTorrent/3.5.5'),
			'creation date' => $this->bint(1234567890),
			'info'          => $this->singleFileInfo(),
		));
		$this->assertTrue($written === $expected, 'both tracker keys are replaced wholesale');

		$reread = new Torrent($written);
		$this->assertTrue($reread->announce() === 'http://new.test/announce', 'announce reads back');
		$this->assertTrue($reread->announce_list() === array(
				array('http://new.test/announce'), array('udp://new.test/announce')),
			'announce-list reads back');
	}

	/**
	 * A single tracker: the script sets announce and deliberately does not set
	 * announce-list, so a torrent that had one comes back without it.
	 */
	public function testASingleTrackerLeavesTheTorrentWithNoAnnounceList()
	{
		$torrent = new Torrent($this->announceListTorrent());
		$this->replay($torrent, array('announce_list' => array(array('http://only.test/announce'))));

		$written = (string)$torrent;
		$expected = $this->bdict(array(
			'announce'      => $this->bstr('http://only.test/announce'),
			'created by'    => $this->bstr('uTorrent/3.5.5'),
			'creation date' => $this->bint(1234567890),
			'info'          => $this->singleFileInfo(),
		));
		$this->assertTrue($written === $expected, 'the announce-list key is gone from the written torrent');

		$reread = new Torrent($written);
		$this->assertTrue($reread->announce_list() === null, 'and it reads back as unset');
	}

	/**
	 * An empty tracker box clears both keys and puts neither back. A torrent
	 * with no announce at all is what rtorrent is then handed.
	 */
	public function testAnEmptyTrackerListClearsBothKeys()
	{
		$torrent = new Torrent($this->announceListTorrent());
		$this->replay($torrent, array('announce_list' => array()));

		$written = (string)$torrent;
		$expected = $this->bdict(array(
			'created by'    => $this->bstr('uTorrent/3.5.5'),
			'creation date' => $this->bint(1234567890),
			'info'          => $this->singleFileInfo(),
		));
		$this->assertTrue($written === $expected, 'neither tracker key is written');
	}

	// ---- the private flag ------------------------------------------------

	/** Marking a public torrent private adds info/private, which changes its hash. */
	public function testMarkingATorrentPrivateWritesTheFlagIntoInfo()
	{
		$torrent = new Torrent($this->announceOnlyTorrent());
		$was = $torrent->hash_info();
		$this->assertTrue($torrent->is_private() === false, 'the fixture is public');

		$this->replay($torrent, array('private' => true));

		$written = (string)$torrent;
		$expected = $this->bdict(array(
			'announce'      => $this->bstr('http://one.test/announce'),
			'created by'    => $this->bstr('uTorrent/3.5.5'),
			'creation date' => $this->bint(1234567890),
			'info'          => $this->singleFileInfo(true),
		));
		$this->assertTrue($written === $expected, 'info/private is written as 1');
		$this->assertTrue($torrent->is_private() === true, 'and the getter agrees');
		$this->assertTrue($torrent->hash_info() !== $was, 'the info hash moved, as it must');
		$this->assertTrue($torrent->hash_info() === strtoupper(sha1($this->singleFileInfo(true))),
			'and it is the hash of the info dictionary that was written');
	}

	/**
	 * Clearing the flag writes info/private as 0 rather than dropping the key:
	 * the setter is `$private ? 1 : 0`, and the difference is a different info
	 * hash, so it has to stay exactly as it is.
	 */
	public function testUnmarkingATorrentWritesPrivateAsZeroRatherThanDroppingIt()
	{
		$torrent = new Torrent($this->privateMultiFileTorrent());
		$this->assertTrue($torrent->is_private() === true, 'the fixture is private');

		$this->replay($torrent, array('private' => false));

		$written = (string)$torrent;
		$expected = $this->bdict(array(
			'announce'      => $this->bstr('http://one.test/announce'),
			'comment'       => $this->bstr('from the tracker'),
			'created by'    => $this->bstr('uTorrent/3.5.5'),
			'creation date' => $this->bint(1234567890),
			'encoding'      => $this->bstr('UTF-8'),
			'info'          => $this->multiFileInfo(false),
		));
		$this->assertTrue($written === $expected, 'info/private is written as 0 and the files are untouched');
		$this->assertTrue($torrent->is_private() === false, 'the getter reads it as public');
	}

	// ---- the comment -----------------------------------------------------

	public function testSettingACommentReplacesTheOne()
	{
		$torrent = new Torrent($this->privateMultiFileTorrent());
		$this->replay($torrent, array('comment' => "  edited by the owner \n"));

		$written = (string)$torrent;
		$expected = $this->bdict(array(
			'announce'      => $this->bstr('http://one.test/announce'),
			'comment'       => $this->bstr('edited by the owner'),
			'created by'    => $this->bstr('uTorrent/3.5.5'),
			'creation date' => $this->bint(1234567890),
			'encoding'      => $this->bstr('UTF-8'),
			'info'          => $this->multiFileInfo(true),
		));
		$this->assertTrue($written === $expected, 'the comment is trimmed and written');
	}

	/** A comment box left blank drops the key. */
	public function testAnEmptyCommentDropsTheKey()
	{
		$torrent = new Torrent($this->privateMultiFileTorrent());
		$this->replay($torrent, array('comment' => "   "));

		$written = (string)$torrent;
		$expected = $this->bdict(array(
			'announce'      => $this->bstr('http://one.test/announce'),
			'created by'    => $this->bstr('uTorrent/3.5.5'),
			'creation date' => $this->bint(1234567890),
			'encoding'      => $this->bstr('UTF-8'),
			'info'          => $this->multiFileInfo(true),
		));
		$this->assertTrue($written === $expected, 'the comment key is not written');

		$reread = new Torrent($written);
		$this->assertTrue($reread->comment() === null, 'and it reads back as unset');
	}

	// ---- what rtorrent added ---------------------------------------------

	/**
	 * Every edit ends by dropping the 'rtorrent' key, so that rtorrent takes
	 * the reloaded torrent as a new one. The resume data is left alone: the
	 * script passes $isNew = false to sendTorrent(), which is what stops it
	 * being dropped as well.
	 */
	public function testAnEditOfASessionTorrentDropsRtorrentAndKeepsResumeData()
	{
		$torrent = new Torrent($this->sessionTorrent());
		$this->assertTrue(isset($torrent->rtorrent), 'the fixture carries the key rtorrent added');

		$this->replay($torrent, array('comment' => 'edited'));

		$written = (string)$torrent;
		$expected = $this->bdict(array(
			'announce'          => $this->bstr('http://one.test/announce'),
			'announce-list'     => $this->bannounceList(array(
				array('http://one.test/announce'),
				array('udp://two.test/announce'),
			)),
			'comment'           => $this->bstr('edited'),
			'created by'        => $this->bstr('uTorrent/3.5.5'),
			'creation date'     => $this->bint(1234567890),
			'info'              => $this->singleFileInfo(),
			'libtorrent_resume' => $this->resumeDictionary(),
		));
		$this->assertTrue($written === $expected, 'rtorrent is dropped, libtorrent_resume is kept');
	}

	/**
	 * All three edits at once, on the torrent rtorrent saved -- the sequence
	 * as the plugin actually runs it when every box on the form is ticked.
	 */
	public function testAllThreeEditsAtOnce()
	{
		$torrent = new Torrent($this->sessionTorrent());
		$this->replay($torrent, array(
			'private'       => true,
			'announce_list' => array(array('http://new.test/announce', 'http://new.test/announce2')),
			'comment'       => 'all three',
		));

		$written = (string)$torrent;
		$expected = $this->bdict(array(
			'announce'          => $this->bstr('http://new.test/announce'),
			'announce-list'     => $this->bannounceList(array(
				array('http://new.test/announce', 'http://new.test/announce2'),
			)),
			'comment'           => $this->bstr('all three'),
			'created by'        => $this->bstr('uTorrent/3.5.5'),
			'creation date'     => $this->bint(1234567890),
			'info'              => $this->singleFileInfo(true),
			'libtorrent_resume' => $this->resumeDictionary(),
		));
		$this->assertTrue($written === $expected, 'the three edits land together and nothing else moves');

		$reread = new Torrent($written);
		$this->assertTrue($reread->is_private() === true, 'private reads back');
		$this->assertTrue($reread->comment() === 'all three', 'the comment reads back');
		$this->assertTrue($reread->announce() === 'http://new.test/announce', 'announce reads back');
		$this->assertTrue(!isset($reread->rtorrent), 'and rtorrent is gone');
	}

	// ---- the file's own author and date ----------------------------------

	/**
	 * An edit changes trackers, a comment or the private flag. It does not
	 * make ruTorrent the author of the file, and it does not move the day the
	 * release was created -- both of which travel out to the "Created On"
	 * column and to the history plugin as fact.
	 *
	 * action.php reads both keys before the first setter and puts them back
	 * after the last. On this fork that restore is a no-op -- Torrent::touch()
	 * writes nothing on a torrent the class did not build -- and it is what
	 * keeps this assertion true against upstream Novik/ruTorrent's Torrent,
	 * where every setter stamps both keys. The byte-exact expectations above
	 * carry the same rule; this states it on its own so that a regression
	 * names itself instead of arriving as a wall of mismatched dictionaries.
	 */
	public function testAnEditKeepsTheTorrentsOwnAuthorAndCreationDate()
	{
		$torrent = new Torrent($this->announceListTorrent());
		$this->replay($torrent, array(
			'private'       => true,
			'announce_list' => array(array('http://new.test/announce')),
			'comment'       => 'edited',
		));

		$this->assertTrue($torrent->meta('created by') === 'uTorrent/3.5.5',
			'the author the file came with is still the author');
		$this->assertTrue($torrent->meta('creation date') == 1234567890,
			'and the date it came with is still the date');
	}

	/**
	 * The mirror image has to be avoided too. Restoring "absent" is restoring
	 * a value: a torrent that carried neither key must not come back with one
	 * filled in from this host's clock and this class's name.
	 */
	public function testAnEditInventsNeitherKeyForATorrentThatCarriedNone()
	{
		$torrent = new Torrent($this->bdict(array(
			'announce' => $this->bstr('http://one.test/announce'),
			'info'     => $this->singleFileInfo(),
		)));
		$this->assertTrue($torrent->errors() === false, 'the fixture parses');

		$this->replay($torrent, array('comment' => 'edited'));

		$expected = $this->bdict(array(
			'announce' => $this->bstr('http://one.test/announce'),
			'comment'  => $this->bstr('edited'),
			'info'     => $this->singleFileInfo(),
		));
		$this->assertTrue((string)$torrent === $expected, 'neither key is invented');
	}

	/**
	 * The restore is what survives a setter that stamps, as upstream's does.
	 *
	 * The two cases above pass on this fork whether or not action.php restores
	 * anything, because this fork's Torrent::touch() writes nothing on a
	 * torrent it did not build. Driving the same extracted block through a
	 * Torrent whose touch() stamps unconditionally -- upstream's shape, see
	 * UpstreamStampingTorrent above -- is what makes the snapshot and the
	 * restore loop load-bearing: delete either from plugins/edit/action.php and
	 * this case fails while the rest of the file stays green.
	 */
	public function testTheRestoreHoldsAgainstASetterThatStamps()
	{
		// Vacuity guard first: if the stand-in did not really stamp, the
		// assertions below would pass with no restore in action.php at all.
		$control = new UpstreamStampingTorrent($this->announceListTorrent());
		$control->comment('edited');
		$this->assertTrue($control->meta('created by') === 'ruTorrent (PHP Class - Adrien Gibrat)',
			'the stand-in stamps its own name on a bare setter, as upstream does');
		$this->assertTrue($control->meta('creation date') === UpstreamStampingTorrent::STAMP_DATE,
			'and stamps its own date there too');

		$torrent = new UpstreamStampingTorrent($this->announceListTorrent());
		$this->replay($torrent, array(
			'private'       => true,
			'announce_list' => array(array('http://new.test/announce')),
			'comment'       => 'edited',
		));

		$this->assertTrue($torrent->meta('created by') === $this->sourceCreator(),
			'the edit sequence puts the file\'s own author back over the stamp');
		// Loose, like the case above it: the decoder returns bencoded
		// integers as floats, so the restored date reads back as
		// float(1234567890) and never identical to the fixture's int.
		$this->assertTrue($torrent->meta('creation date') == $this->sourceDate(),
			'and the file\'s own creation date back over it');
		$this->assertTrue($torrent->comment() === 'edited', 'the edit itself still landed');
	}
}
