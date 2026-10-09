<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/FakeRtorrentDaemon.php');
require_once(__DIR__ . '/../../php/rtorrent.php');

/**
 * rTorrent::magnetShown() is the one check that a magnet sendMagnet() handed
 * to rTorrent became a download (O-1, R4-NF-1): addtorrent.php, bulk_magnet,
 * extsearch and rss call it before they report a magnet as added.
 *
 * It reads d.hash until the stub shows, within the settle window of a
 * .torrent load. Only a d.hash naming the hash is presence: rTorrent's
 * missing-hash answer and any other fault leave the magnet unconfirmed, and an
 * unconfirmed magnet is named in the log under the caller's name.
 */
class MagnetShownTest extends TestCase
{
	const PRESENT = '5555555555555555555555555555555555555555';
	const MISSING = '6666666666666666666666666666666666666666';
	const UNKNOWN = '7777777777777777777777777777777777777777';

	private $daemon;
	private $base;
	private $settingsProperty;
	private $settingsBefore;
	private $globalsBefore = array();

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-magnet-shown-' . getmypid();
		@mkdir($this->base, 0700, true);
		$this->settingsProperty = self::makeAccessible(
			new ReflectionProperty('rTorrentSettings', 'theSettings'));
		$this->settingsBefore = $this->settingsProperty->getValue();
		$settings = (new ReflectionClass('rTorrentSettings'))->newInstanceWithoutConstructor();
		$settings->iVersion = 0x1018;
		$settings->aliases = array();
		$this->settingsProperty->setValue(null, $settings);
		foreach (array('scgi_host', 'scgi_port', 'log_file', 'rpcTimeOut') as $name)
			$this->globalsBefore[$name] = array_key_exists($name, $GLOBALS) ? $GLOBALS[$name] : null;
		$GLOBALS['log_file'] = $this->base . '/app.log';
		$GLOBALS['rpcTimeOut'] = 10;
		$this->daemon = new FakeRtorrentDaemon(
			new FakeRtorrentAddTorrentReplies(array(self::PRESENT), array(), false,
				array('unknown' => array(self::UNKNOWN))),
			$this->base . '/calls.log');
		$GLOBALS['scgi_host'] = '127.0.0.1';
		$GLOBALS['scgi_port'] = $this->daemon->port();
	}

	public function tearDown()
	{
		$this->daemon->stop();
		foreach ($this->globalsBefore as $name => $value) {
			if ($value === null) unset($GLOBALS[$name]);
			else $GLOBALS[$name] = $value;
		}
		foreach (glob($this->base . '/*') ?: array() as $entry)
			@unlink($entry);
		@rmdir($this->base);
		$this->settingsProperty->setValue(null, $this->settingsBefore);
	}

	private function reads($hash)
	{
		$reads = 0;
		foreach ($this->daemon->bodies() as $body)
			if (strpos($body, 'd.hash') !== false && strpos($body, $hash) !== false)
				$reads++;
		return $reads;
	}

	private function logged()
	{
		return (string)@file_get_contents($this->base . '/app.log');
	}

	public function testAStubThatIsThereIsShownAfterOneRead()
	{
		$this->assertTrue(rTorrent::magnetShown(strtolower(self::PRESENT), 'probe') === true,
			'a magnet whose stub d.hash names is shown');
		$this->assertEquals(1, $this->reads(self::PRESENT), 'one d.hash read, upper case');
		$this->assertEquals('', $this->logged(), 'nothing is logged');
	}

	public function testAMissingStubIsUnconfirmedAfterTheSettleWindowAndLogged()
	{
		$started = microtime(true);
		$this->assertTrue(rTorrent::magnetShown(self::MISSING, 'probe') === false,
			'rTorrent\'s missing-hash answer for the whole window is not a download');
		$elapsed = microtime(true) - $started;
		$window = rTorrent::LOAD_WAIT_ATTEMPTS * rTorrent::LOAD_WAIT_DELAY_US / 1000000;
		$this->assertTrue($elapsed >= $window * 0.9 && $elapsed < $window + 2,
			'it waits for the settle window and no longer: ' . round($elapsed, 2) . ' s');
		$this->assertTrue($this->reads(self::MISSING) > 1, 'the stub was looked for more than once');
		$this->assertEquals(1, substr_count($this->logged(), 'probe: magnet unconfirmed: ' . self::MISSING),
			'one line names the caller and the magnet: ' . $this->logged());
	}

	public function testAnUnknownAnswerIsNotPresence()
	{
		$this->assertTrue(rTorrent::magnetShown(self::UNKNOWN, 'probe') === false,
			'a fault that is not the missing-hash answer proves nothing');
		$this->assertTrue(strpos($this->logged(), 'probe: magnet unconfirmed: ' . self::UNKNOWN) !== false,
			'and the magnet is unconfirmed');
	}
	public function testMultipleXtSelectsTheLastHashAsTheDaemonDoes()
	{
		$this->assertSame(self::MISSING, rTorrent::magnetHash('magnet:?xt=urn:btih:'.self::PRESENT
			.'&dn=ignored+name&xt=urn:btih:'.strtolower(self::MISSING)),
			'the final xt replaces the prior hash, matching libtorrent DownloadConstructor::parse_magnet_uri');
		$this->assertSame(str_repeat('0', 40), rTorrent::magnetHash('magnet:?xt=urn:btih:'.str_repeat('A', 32)),
			'a base32 hash is decoded once');
		$this->assertSame(str_repeat('AB', 20), rTorrent::magnetHash('magnet:?xt=urn:btih:'.str_repeat('%AB', 20)),
			'a percent-encoded binary hash keeps its bytes');
		$this->assertSame(false, rTorrent::magnetHash('magnet:?dn=xt=urn:btih:'.self::PRESENT),
			'a hash mentioned in another parameter is not an xt');
		$this->assertSame(false, rTorrent::magnetHash('magnet:?xt=urn:btih:'.self::PRESENT.'&xt=urn:btih:bad'),
			'a malformed later xt is refused instead of waiting for the earlier hash');
	}

}
