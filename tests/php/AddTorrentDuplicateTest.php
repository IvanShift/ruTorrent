<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/AddTorrentHttpHarness.php');

/**
 * php/addtorrent.php reports a torrent the daemon already holds as a
 * duplicate. rtorrent drops a load whose hash it already has before the
 * load's deferred proof can run, so a duplicate .torrent sent anyway could
 * only end Pending after the whole settle window, 2 s each (R3C-04, N6).
 * A hash from the download_list snapshot, or confirmed earlier in the
 * request, is answered without a load once one d.hash read shows it is still
 * there: the snapshot can be stale, and a magnet's hash proves nothing until
 * its stub shows (R4-CORE-01). A new hash still needs its deferred proof for
 * Success, and a magnet needs its stub (O-1).
 *
 * addtorrent.php answers with a redirect whose result[] values are the
 * outcome, so it is served by AddTorrentHttpHarness, in front of
 * FakeRtorrentDaemon, and the Location header is what is read.
 */
class AddTorrentDuplicateTest extends TestCase
{
	const LOADED = '1111111111111111111111111111111111111111';
	const FRESH = '2222222222222222222222222222222222222222';

	private $server = null;
	private $daemon = null;

	public function tearDown()
	{
		$this->stop();
	}

	private function stop()
	{
		if ($this->server !== null) {
			$diagnostics = $this->server->phpDiagnostics();
			$this->server->close();
			$this->assertSame(array(), $diagnostics,
				'the requests were served without a PHP diagnostic: ' . implode(' | ', $diagnostics));
		}
		$this->server = null;
		$this->daemon = null;
	}

	/** A single file torrent whose info dictionary hashes to the return value. */
	private function torrent(&$hash, $piece = "\x01")
	{
		$info = 'd6:lengthi1e4:name5:a.bin12:piece lengthi16384e6:pieces20:'
			. str_repeat($piece, 20) . 'e';
		$hash = strtoupper(sha1($info));
		$announce = 'http://127.0.0.1:1/announce';
		return 'd8:announce' . strlen($announce) . ':' . $announce . '4:info' . $info . 'e';
	}

	/**
	 * Sends one request to addtorrent.php. The daemon reports existing hashes
	 * and confirms only the loads selected by $proofs, in load order.
	 */
	private function add($loaded, $contentType, $body, $proofs = array(), $snapshotFails = false,
		$daemon = array())
	{
		$this->stop();
		$this->server = AddTorrentHttpHarness::forAddTorrent(
			new FakeRtorrentAddTorrentReplies($loaded, $proofs, $snapshotFails, $daemon),
			array('globals' => array('rpcTimeOut' => 10, 'rpcLogCalls' => false,
				'saveUploadedTorrents' => true)));
		$this->daemon = $this->server->daemon();
		$response = $this->server->request('POST', '/php/addtorrent.php', $body,
			array('Content-Type' => $contentType));
		$location = $response['headers']['location'] ?? null;
		if ($location === null || !preg_match('`^[^?]*\?(.*)$`', $location, $query)) {
			return array('no redirect: ' . $response['raw']);
		}
		parse_str($query[1], $fields);
		return isset($fields['result']) ? $fields['result'] : array();
	}

	private function loadCalls()
	{
		$calls = $this->daemon->calls();
		$listPos = array_search('download_list', $calls, true);
		$firstLoad = null;
		foreach($calls as $position=>$call)
			if(strpos($call, 'load_') === 0 || strpos($call, 'load.') === 0)
			{
				$firstLoad = $position;
				break;
			}
		$this->assertTrue($listPos !== false && ($firstLoad === null || $listPos < $firstLoad),
			'the loaded hashes are read before any load: ' . implode(', ', $calls));
		return count(array_filter($calls, function($call) {
			return strpos($call, 'load_') === 0 || strpos($call, 'load.') === 0;
		}));
	}

	/** The d.hash reads the daemon was asked for. */
	private function presenceReads()
	{
		return count(array_filter($this->daemon->calls(), function($call) {
			return $call === 'd.hash' || $call === 'd.get_hash';
		}));
	}

	/** The deferred proof reads the daemon was asked for. */
	private function proofReads()
	{
		return count(array_filter($this->daemon->calls(), function($call) {
			return $call === 'd.custom' || $call === 'd.get_custom';
		}));
	}

	public function testFailedSnapshotRefusesTheWholeAddBeforeAnyLoad()
	{
		$magnet = 'magnet:?xt=urn:btih:' . self::FRESH;
		$results = $this->add(array(), 'application/x-www-form-urlencoded',
			'url=' . rawurlencode($magnet), array(), true);
		$this->assertEquals(array('Failed'), $results,
			'failed download_list is visible as a failed add');
		$this->assertTrue(in_array('download_list', $this->daemon->calls(), true),
			'the snapshot was attempted');
		$this->assertTrue(count(array_filter($this->daemon->calls(), function($method) {
			return strpos($method, 'load_') === 0 || strpos($method, 'load.') === 0;
		})) === 0, 'no load is sent after the failed snapshot');
	}

	public function testAMagnetAlreadyLoadedIsADuplicateNotAnAddition()
	{
		$urls = 'magnet:?xt=urn:btih:' . self::LOADED . '&dn=loaded' . "\n"
			. 'magnet:?xt=urn:btih:' . self::FRESH . "\n"
			. 'magnet:?xt=urn:btih:' . strtolower(self::FRESH);
		$results = $this->add(array(self::LOADED), 'application/x-www-form-urlencoded',
			'url=' . rawurlencode($urls));
		$this->assertEquals(array('Duplicate', 'Success', 'Duplicate'), $results,
			'the loaded magnet and the second copy of the new one are duplicates: ' . json_encode($results));
		$this->assertEquals(3, $this->loadCalls(), 'each magnet was still sent');
	}

	/**
	 * Uploads one file per name, each with the torrent its name maps to in
	 * $torrents (name => torrent bytes), to a daemon holding $loaded.
	 */
	private function upload($loaded, $torrents, $names, $proofs, $daemon = array())
	{
		$parts = array();
		foreach ($names as $name)
			$parts = array_merge($parts, AddTorrentHttpHarness::torrentFiles(array($name), $torrents[$name]));
		list($contentType, $body) = AddTorrentHttpHarness::multipart($parts);
		return $this->add($loaded, $contentType, $body, $proofs, false, $daemon);
	}

	private function kept()
	{
		return array_values(array_diff(scandir($this->server->profile() . '/torrents'), array('.', '..')));
	}

	public function testAnUploadedTorrentAlreadyLoadedIsADuplicateWithoutALoad()
	{
		$torrent = $this->torrent($hash);
		$results = $this->upload(array(self::LOADED, $hash), array('one.torrent' => $torrent),
			array('one.torrent'), array());
		$this->assertEquals(array('Duplicate'), $results,
			'a torrent the daemon already holds is answered as a duplicate: ' . json_encode($results));
		$this->assertEquals(0, $this->loadCalls(),
			'no load is sent that rtorrent would drop before its proof could run');
		$this->assertEquals(0, $this->proofReads(), 'so no settle window is waited out');
		$this->assertEquals(1, $this->presenceReads(), 'one d.hash read confirmed the torrent is still there');
		$this->assertEquals(array(), $this->kept(), 'the uploaded copy of a duplicate is not kept');
	}

	/**
	 * The snapshot is read when the request starts; the torrent can leave
	 * the session before its upload is reached (R4-CORE-01 a).
	 */
	public function testASnapshotHashThatLeftTheSessionIsLoadedAgain()
	{
		$torrent = $this->torrent($hash);
		$results = $this->upload(array(self::LOADED, $hash), array('one.torrent' => $torrent),
			array('one.torrent'), array(true), array('absent' => array($hash)));
		$this->assertEquals(array('Success'), $results,
			'a torrent the snapshot listed but rTorrent no longer holds is added: ' . json_encode($results));
		$this->assertEquals(1, $this->loadCalls(), 'its load was sent');
		$this->assertEquals(array('one.torrent'), $this->kept(), 'and its confirmed source is kept');
	}

	public function testAnUnknownPresenceIsNotAnsweredAsADuplicate()
	{
		$torrent = $this->torrent($hash);
		$results = $this->upload(array($hash), array('one.torrent' => $torrent),
			array('one.torrent'), array(true), array('unknown' => array($hash)));
		$this->assertEquals(array('Success'), $results,
			'only a d.hash that names the torrent skips its load: ' . json_encode($results));
		$this->assertEquals(1, $this->loadCalls(), 'the load was sent and confirmed by its proof');
	}

	public function testTheSecondCopyInOneUploadIsADuplicate()
	{
		$torrent = $this->torrent($hash);
		$results = $this->upload(array(self::LOADED),
			array('one.torrent' => $torrent, 'two.torrent' => $torrent),
			array('one.torrent', 'two.torrent'), array(true));
		$this->assertEquals(array('Success', 'Duplicate'), $results,
			'the confirmed first copy makes the second a duplicate: ' . json_encode($results));
		$this->assertEquals(1, $this->loadCalls(), 'only the first copy was sent');
		$this->assertEquals(1, $this->proofReads(), 'and confirmed by its deferred proof');
		$this->assertEquals(array('one.torrent'), $this->kept(),
			'the confirmed source remains and the duplicate copy does not: ' . json_encode($this->kept()));
	}

	public function testANewTorrentNextToADuplicateStillNeedsItsProof()
	{
		$loaded = $this->torrent($loadedHash);
		$fresh = $this->torrent($freshHash, "\x02");
		$results = $this->upload(array(self::LOADED, $loadedHash),
			array('a.torrent' => $loaded, 'b.torrent' => $fresh),
			array('a.torrent', 'b.torrent'), array(false));
		$this->assertEquals(array('Duplicate', 'Pending'), $results,
			'a hash outside the snapshot without its proof stays Pending: ' . json_encode($results));
		$this->assertEquals(1, $this->loadCalls(), 'only the new torrent was sent');
		$this->assertTrue($this->proofReads() > 1,
			'the new load was given the whole settle window: ' . $this->proofReads());
		$this->assertEquals(array('b.torrent'), $this->kept(),
			'the unconfirmed new source remains for recovery: ' . json_encode($this->kept()));
	}

	/** A URL batch, sent like the URL box of the add dialog. */
	private function addUrls($loaded, $urls, $proofs, $daemon)
	{
		return $this->add($loaded, 'application/x-www-form-urlencoded',
			'url=' . rawurlencode(implode("\n", $urls)), $proofs, false, $daemon);
	}

	/** rTorrent drops some magnets without a stub; sendMagnet() answers their hash anyway (O-1). */
	public function testADroppedMagnetIsPendingNotSuccess()
	{
		$results = $this->addUrls(array(), array('magnet:?xt=urn:btih:' . self::FRESH . '&dn'),
			array(), array('dropMagnets' => array(self::FRESH)));
		$this->assertEquals(array('Pending'), $results,
			'a magnet whose stub never shows is not reported as added: ' . json_encode($results));
		$this->assertTrue($this->presenceReads() > 1, 'its stub was looked for through the settle window');
		$this->assertTrue(strpos($this->server->applicationLog(),
			'addtorrent: magnet unconfirmed: ' . self::FRESH) !== false,
			'the unconfirmed magnet is logged: ' . $this->server->applicationLog());
	}

	public function testAMagnetWhoseStubShowsLateIsStillASuccess()
	{
		$results = $this->addUrls(array(), array('magnet:?xt=urn:btih:' . self::FRESH),
			array(), array('stubAfter' => 3));
		$this->assertEquals(array('Success'), $results,
			'a stub that shows within the settle window confirms the magnet: ' . json_encode($results));
		$this->assertEquals(4, $this->presenceReads(), 'three misses, then the stub');
	}

	/**
	 * A dropped magnet's hash must not make the torrent of the same hash,
	 * later in the batch, a duplicate that is never loaded (R4-CORE-01 b).
	 */
	public function testADroppedMagnetDoesNotMakeItsTorrentADuplicate()
	{
		$torrent = $this->torrent($hash);
		$root = sys_get_temp_dir() . '/addtorrent-duplicate-source-' . getmypid();
		@mkdir($root, 0700, true);
		file_put_contents($root . '/same.torrent', $torrent);
		$source = AddTorrentHttpHarness::forDocumentRoot($root);
		try {
			$results = $this->addUrls(array(self::LOADED),
				array('magnet:?xt=urn:btih:' . $hash . '&dn', $source->url('/same.torrent')),
				array(false, true), array('dropMagnets' => array($hash)));
		} finally {
			$source->close();
			@unlink($root . '/same.torrent');
			@rmdir($root);
		}
		$this->assertEquals(array('Pending', 'Success'), $results,
			'the dropped magnet stays unconfirmed and its torrent is loaded: ' . json_encode($results));
		$this->assertEquals(2, $this->loadCalls(), 'both the magnet and the torrent were sent');
	}

	public function testTheResultPageShowsADuplicateAsAnAlert()
	{
		$this->server = AddTorrentHttpHarness::forAddTorrent(
			new FakeRtorrentAddTorrentReplies(array(), array(), false));
		$response = $this->server->request('GET',
			'/php/addtorrent.php?result[]=Duplicate&name[]=x.torrent');
		$this->assertEquals('noty("x.torrent - "+theUILang["addTorrent"+"Duplicate"],"alert");', $response['body'],
			'a duplicate is named with its own wording in the alert style');
	}
}
