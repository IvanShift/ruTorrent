<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/RtorrentLoadConfirmationFixture.php');
require_once(__DIR__ . '/../../php/rtorrent.php');
require_once(__DIR__ . '/../../plugins/rss/rss.php');

class RtorrentLoadConfirmationSettings extends rTorrentSettings
{
	public function loadLegacyAliases()
	{
		include(__DIR__ . '/../../php/methods-0.9.4.php');
	}

	public function correctDirectory(&$dir, $resolve_links = false)
	{
		return true;
	}
}

class RtorrentLoadConfirmationTest extends TestCase
{
	private $settingsBefore;
	private $torrentFile;
	private $hash;

	public function setUp()
	{
		$settings = (new ReflectionClass('RtorrentLoadConfirmationSettings'))
			->newInstanceWithoutConstructor();
		$settings->iVersion = 0x1016;
		$settings->aliases = array();
		$settings->loadLegacyAliases();
		$property = new ReflectionProperty('rTorrentSettings', 'theSettings');
		if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
		$this->settingsBefore = $property->getValue();
		$property->setValue(null, $settings);

		// PHP's Torrent parser accepts this real lab rejection case, while
		// rTorrent 0.16.22 replies success to load and then rejects info.name="".
		$info = 'd6:lengthi1e4:name0:12:piece lengthi16384e6:pieces20:'
			. str_repeat("\x02", 20) . 'e';
		$raw = 'd8:announce28:http://example.test/announce4:info' . $info . 'e';
		$this->torrentFile = tempnam(sys_get_temp_dir(), 'rtlc');
		rename($this->torrentFile, $this->torrentFile . '.torrent');
		$this->torrentFile .= '.torrent';
		file_put_contents($this->torrentFile, $raw);
		$torrent = new Torrent($this->torrentFile);
		$this->assertTrue(!$torrent->errors(), 'the lab rejection payload passes the PHP parser');
		$this->hash = $torrent->hash_info();
		$this->assertTrue($this->hash === '4215B8A00E6C948FC92C8985F207A87B929E1594',
			'the fixture is the empty-name payload observed in the local lab');
	}

	public function tearDown()
	{
		$property = new ReflectionProperty('rTorrentSettings', 'theSettings');
		if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
		$property->setValue(null, $this->settingsBefore);
		@unlink($this->torrentFile);
	}

	private function withPeer($mode, $callback)
	{
		$fixture = RtorrentLoadConfirmationFixture::start($mode);
		$oldHost = isset($GLOBALS['scgi_host']) ? $GLOBALS['scgi_host'] : null;
		$oldPort = isset($GLOBALS['scgi_port']) ? $GLOBALS['scgi_port'] : null;
		$GLOBALS['scgi_host'] = '127.0.0.1';
		$GLOBALS['scgi_port'] = $fixture->port();
		try {
			return $callback($fixture);
		} finally {
			if ($oldHost === null) unset($GLOBALS['scgi_host']);
			else $GLOBALS['scgi_host'] = $oldHost;
			if ($oldPort === null) unset($GLOBALS['scgi_port']);
			else $GLOBALS['scgi_port'] = $oldPort;
			$fixture->close();
		}
	}

	public function testLegacyAliasMapProvidesBothProofCommands()
	{
		$this->assertEquals('d.custom.set', getCmd('d.set_custom'),
			'the 0.9.4+ alias map supplies the deferred setter');
		$this->assertEquals('d.custom', getCmd('d.get_custom'),
			'the 0.9.4+ alias map supplies the exact receipt getter');
	}

	public function testAcceptedLoadWithNoDaemonDownloadIsPending()
	{
		$this->withPeer('absent', function ($fixture) {
			$result = rTorrent::sendTorrent($this->torrentFile, true, true,
				'', null, true, false);
			$this->assertTrue($result === null,
				'load RPC success without a daemon download is unknown, not a confirmed hash');
			$this->assertTrue(count($fixture->requests()) >= 2,
				'confirmation reads the daemon after dispatch');
		});
	}

	public function testExistingHashCannotConfirmASecondLoad()
	{
		$this->withPeer('foreign', function ($fixture) {
			$result = rTorrent::sendTorrent($this->torrentFile, true, true,
				'', null, true, false);
			$this->assertTrue($result === null,
				'an existing download with another marker is not proof of this load');
		});
	}

	public function testDelayedExactMarkerConfirmsTheHash()
	{
		$this->withPeer('delayed', function ($fixture) {
			$result = rTorrent::sendTorrent($this->torrentFile, true, true,
				'', null, true, false);
			$this->assertTrue($result === $this->hash,
				'a matching marker after deferred insertion confirms the computed hash');
			$requests = $fixture->requests();
			$this->assertTrue(count($requests) >= 4,
				'two misses and a matching marker were observed');
			$this->assertTrue((bool)preg_match('~ru-load-proof-[0-9a-f]{32},1~', $requests[0]),
				'the load includes a unique namespaced proof key');
		});
	}

	public function testHashMayAppearBeforeItsDeferredProofCommand()
	{
		$this->withPeer('transient-foreign', function ($fixture) {
			$result = rTorrent::sendTorrent($this->torrentFile, true, true,
				'', null, true, false);
			$this->assertTrue($result === $this->hash,
				'a temporary empty marker must not win over the later exact proof');
			$this->assertTrue(count($fixture->requests()) >= 4,
				'confirmation polls through the transient hash state');
		});
	}

	public function testUnconfirmedRawLoadKeepsItsInputFile()
	{
		$source = tempnam(sys_get_temp_dir(), 'rtlp');
		rename($source, $source . '.torrent');
		$source .= '.torrent';
		copy($this->torrentFile, $source);
		try {
			$this->withPeer('absent', function () use ($source) {
				$result = rTorrent::sendTorrent($source, true, true,
					'', null, false, false);
				$this->assertSame(null, $result,
					'the daemon did not confirm the raw load; got ' . var_export($result, true));
				$this->assertTrue(is_file($source),
					'the caller retains its input file while the load remains unknown');
			});
		} finally {
			@unlink($source);
		}
	}

	public function testConfirmedRawLoadCanRemoveItsInputFile()
	{
		$source = tempnam(sys_get_temp_dir(), 'rtlc');
		rename($source, $source . '.torrent');
		$source .= '.torrent';
		copy($this->torrentFile, $source);
		try {
			$this->withPeer('own', function () use ($source) {
				$result = rTorrent::sendTorrent($source, true, true,
					'', null, false, false);
				$this->assertTrue($result === $this->hash,
					'the fake daemon confirms the raw load');
				$this->assertTrue(!file_exists($source),
					'a confirmed raw load follows the configured source cleanup');
			});
		} finally {
			@unlink($source);
		}
	}

	public function testRssPersistsPendingReceiptWithoutClaimingLoaded()
	{
		$this->withPeer('absent', function () {
			$source = tempnam(sys_get_temp_dir(), 'rtlr');
			rename($source, $source . '.torrent');
			$source .= '.torrent';
			copy($this->torrentFile, $source);
			$previousSave = isset($GLOBALS['saveUploadedTorrents'])
				? $GLOBALS['saveUploadedTorrents'] : null;
			$GLOBALS['saveUploadedTorrents'] = false;
			try {
				$href = 'https://tracker.example/download?id=fb3';
				$feed = new class extends rRSS {
					public $source;
					public function getTorrent($href) { return $this->source; }
				};
				$feed->source = $source;
				$feed->items[$href] = array('timestamp' => 100, 'guid' => 'fb3-item');
				$manager = (new ReflectionClass(rRSSManager::class))->newInstanceWithoutConstructor();
				$manager->rssList = new rRSSMetaList();
				$manager->history = new rRSSHistory();
				$manager->getTorrents($feed, $href, true, true,
					'', '', '', '', false);
				$entry = $manager->history->lst[$href];
				$this->assertEquals('Pending', $entry['hash'],
					'RSS does not record an accepted but absent load as loaded');
				$this->assertEquals($this->hash, $entry['receipt']['hash'],
					'RSS retains the exact load receipt for later recheck');
				$this->assertTrue(!file_exists($source),
					'RSS removes its disposable download while the receipt remains');
			} finally {
				if($previousSave === null) unset($GLOBALS['saveUploadedTorrents']);
				else $GLOBALS['saveUploadedTorrents'] = $previousSave;
				@unlink($source);
			}
		});
	}

	public function testLoadRpcFaultIsAProvenDispatchFailure()
	{
		$this->withPeer('load-fault', function ($fixture) {
			$result = rTorrent::sendTorrent($this->torrentFile, true, true,
				'', null, true, false);
			$this->assertTrue($result === false,
				'an RPC fault remains a definitive dispatch failure');
			$this->assertTrue(count($fixture->requests()) === 1,
				'a rejected RPC is not polled as a deferred load');
		});
	}
}
