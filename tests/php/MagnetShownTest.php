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
		foreach (array('scgi_host', 'scgi_port', 'log_file', 'rpcTimeOut', 'topDirectory') as $name)
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
		$this->assertSame('0842108421084210842108421084210842108421', rTorrent::magnetHash('magnet:?xt=urn:btih:'.str_repeat('B', 32)),
			'a base32 hash is decoded once');
		$this->assertSame(str_repeat('AB', 20), rTorrent::magnetHash('magnet:?xt=urn:btih:'.str_repeat('%AB', 20)),
			'a percent-encoded binary hash keeps its bytes');
		$this->assertSame(false, rTorrent::magnetHash('magnet:?dn=xt=urn:btih:'.self::PRESENT),
			'a hash mentioned in another parameter is not an xt');
		$this->assertSame(false, rTorrent::magnetHash('magnet:?xt=urn:btih:'.self::PRESENT.'&xt=urn:btih:bad'),
			'a malformed later xt is refused instead of waiting for the earlier hash');
	}

	public function testHybridExactTopicsKeepTheLastApplicableV1Hash()
	{
		// Captured on libtorrent 8f042ad / rTorrent 0.16.25: btmh is ignored.
		$v1 = 'xt=urn:btih:'.self::PRESENT;
		$v2 = 'xt=urn:btmh:1220'.str_repeat('ab', 32);
		$this->assertSame(self::PRESENT, rTorrent::magnetHash('magnet:?'.$v1.'&'.$v2),
			'a trailing v2 exact topic does not reject the v1 hash');
		$this->assertSame(self::PRESENT, rTorrent::magnetHash('magnet:?'.$v2.'&'.$v1),
			'a leading v2 exact topic does not reject the v1 hash');
		$this->assertSame(self::MISSING, rTorrent::magnetHash('magnet:?'.$v1.'&'.$v2
			.'&xt=urn:btih:'.self::MISSING.'&xt=urn:sha1:ignored'),
			'the final applicable btih wins while other exact topics are ignored');
		$this->assertSame(false, rTorrent::magnetHash('magnet:?'.$v2),
			'an unsupported v2-only topic cannot supply a v1 hash');
		$this->assertSame(false, rTorrent::sendMagnet('magnet:?'.$v2, false, false, null, null),
			'an unsupported v2-only topic is refused before RPC');
		$this->assertSame(array(), $this->daemon->bodies(), 'a v2-only topic sends no load call');
		$this->assertSame(false, rTorrent::magnetHash('magnet:?'.$v1.'&'.$v2.'&xt=urn:btih:bad'),
			'a hybrid still refuses a malformed applicable hash');
	}

	public function testHybridSendForwardsTheOriginalUriAndChecksItsV1Stub()
	{
		rTorrentSettings::get()->aliases['load'] = array('name' => 'load.normal', 'prm' => 1);
		rTorrentSettings::get()->directory = '';
		$magnet = 'magnet:?xt=urn:btih:'.self::MISSING
			.'&xt=urn:btmh:1220'.str_repeat('ab', 32).'&dn=hybrid+name';
		$this->assertSame(self::MISSING, rTorrent::sendMagnet($magnet, false, false, null, null),
			'a hybrid is submitted under its v1 hash');
		$this->assertTrue(strpos(implode('', $this->daemon->bodies()),
			htmlspecialchars($magnet, ENT_COMPAT, 'UTF-8')) !== false,
			'the daemon receives the original hybrid URI without stripping exact topics');
		$this->assertSame(true, rTorrent::magnetShown(self::MISSING, 'hybrid'),
			'the submitted hybrid is shown only after its v1 stub is present');
		$this->assertSame(1, $this->reads(self::MISSING), 'presence is checked once');
	}

	public function testIgnoredExactTopicsStillRequireValidPercentEncoding()
	{
		$prefix = 'magnet:?xt=urn:btih:'.self::PRESENT.'&xt=urn:btmh:';
		foreach(array('%', '%0', '%GG') as $invalid)
			$this->assertSame(false, rTorrent::magnetHash($prefix.$invalid),
				'an ignored exact topic cannot hide a malformed percent escape');
		$this->assertSame(false, rTorrent::magnetHash('magnet:?xt=urn:btih:12345678901234567%GG'),
			'a malformed escape cannot be mistaken for a twenty-byte binary hash');
		$this->assertSame(str_repeat('25', 20), rTorrent::magnetHash(
			'magnet:?xt=urn:btih:'.str_repeat('%25', 20).'&xt=urn:btmh:ignored%26topic'),
			'valid percent escapes preserve raw hash bytes and ignored topic boundaries');
		$this->assertSame(false, rTorrent::magnetHash(
			'magnet:?xt=urn:btmh:ignored%26xt=urn:btih:'.self::PRESENT),
			'a percent-encoded separator does not introduce an applicable exact topic');
	}

	public function testZeroInfoHashesAreRefusedBeforeAnyLoadOrMkdirRpc()
	{
		// Captured on native c8b228b: load replies succeed while zero-info-hash is refused.
		rTorrentSettings::get()->aliases['load'] = array('name' => 'load.normal', 'prm' => 1);
		rTorrentSettings::get()->directory = '';
		$GLOBALS['topDirectory'] = $this->base;
		$zeroForms = array(str_repeat('0', 40), str_repeat('A', 32), str_repeat('a', 32),
			str_repeat('%00', 20), str_repeat('%30', 40));
		foreach($zeroForms as $text)
			foreach(array('', '&xt=urn:btmh:1220'.str_repeat('ab', 32)) as $suffix)
			{
				$magnet = 'magnet:?xt=urn:btih:'.$text.$suffix;
				$this->assertSame(false, rTorrent::magnetHash($magnet),
					'the final decoded zero info hash is not a loadable identity');
				$this->assertSame(false, rTorrent::sendMagnet($magnet, false, false,
					$this->base.'/requested', null), 'zero info hashes refuse before submission');
			}
		$this->assertSame(false, rTorrent::magnetHash('magnet:?xt=urn:btih:'.str_repeat("\0", 20)),
			'a raw twenty-byte zero info hash is refused too');
		$this->assertSame(array(), $this->daemon->bodies(),
			'zero info hashes send neither load nor directory-creation commands');
	}

	public function testOnlyTheFinalApplicableInfoHashMustBeNonzero()
	{
		$zero = 'xt=urn:btih:'.str_repeat('0', 40);
		$valid = 'xt=urn:btih:'.self::PRESENT;
		$v2 = 'xt=urn:btmh:1220'.str_repeat('ab', 32);
		$this->assertSame(self::PRESENT, rTorrent::magnetHash('magnet:?'.$zero.'&'.$v2.'&'.$valid),
			'a later valid btih replaces an earlier zero before identity admission');
		$this->assertSame(false, rTorrent::magnetHash('magnet:?'.$valid.'&'.$v2.'&'.$zero),
			'a final zero btih cannot fall back to the earlier valid hash');
	}

	public function testNonzeroDecodedIdentitiesAndMalformedLengthsKeepTheirContracts()
	{
		$this->assertSame(str_repeat('30', 20), rTorrent::magnetHash('magnet:?xt=urn:btih:'.str_repeat('0', 20)),
			'twenty ASCII zero characters represent nonzero raw hash bytes');
		$this->assertSame(str_repeat('0', 39).'1', rTorrent::magnetHash('magnet:?xt=urn:btih:'.str_repeat('0', 39).'1'),
			'a nearly zero hexadecimal hash is still valid');
		$this->assertSame(str_repeat('0', 39).'1', rTorrent::magnetHash('magnet:?xt=urn:btih:'.str_repeat('%00', 19).'%01'),
			'nonzero percent-encoded binary hashes preserve all zero-valued bytes');
		foreach(array(str_repeat('B', 31), str_repeat('B', 33), str_repeat('B', 31).'1',
			str_repeat('Q', 19), str_repeat('Q', 21), str_repeat('0', 39).'g') as $invalid)
			$this->assertSame(false, rTorrent::magnetHash('magnet:?xt=urn:btih:'.$invalid),
				'invalid identity lengths and alphabets remain refused');
	}

}
