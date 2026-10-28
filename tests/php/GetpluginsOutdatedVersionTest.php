<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/GetpluginsHarness.php');

/**
 * What php/getplugins.php says about a PHP or an rTorrent too old to be safe.
 *
 * getplugins.php runs once each time the interface loads, so each warning is
 * written once per load, and to the log only: it is advice, not a fault.
 */
class GetpluginsOutdatedVersionTest extends TestCase
{
	private $harness = null;

	public function tearDown()
	{
		$this->release();
	}

	private function release()
	{
		if($this->harness !== null)
		{
			$this->harness->release();
			$this->harness = null;
		}
	}

	private function runWithRtorrent($name, $version)
	{
		$this->release();
		$this->harness = new GetpluginsHarness($name);
		$this->harness->withRtorrent(4242, '4242', $version);
		$output = $this->harness->run(array('RU_TEMP_DIRECTORY' => $this->harness->directory('temp', 0700)));
		$this->assertTrue(GetpluginsHarness::ran($output),
			'getplugins.php ran against rTorrent ' . $version);
		// systemInfoScript() carries the version as a JSON::jsValue() literal.
		$this->assertTrue(preg_match('/\\bversion\\s*:\\s*"' . preg_quote($version, '/') . '"/', $output) === 1,
			'and was told rTorrent is ' . $version);
		return $output;
	}

	/**
	 * The text a log() call for $key carries after the message, decoded from
	 * the JavaScript string literal it is written as; null when there is no
	 * such call or its argument is not one string literal.
	 */
	private static function loggedSuffix($output, $key)
	{
		if(preg_match('/\\blog\\(theUILang\\.' . preg_quote($key, '/') . '\\+("(?:[^"\\\\]|\\\\.)*")\\);/', $output, $found) !== 1)
			return null;
		$suffix = json_decode($found[1]);
		return is_string($suffix) ? $suffix : null;
	}

	private static function times($output, $key)
	{
		return GetpluginsHarness::logged($output, $key);
	}

	public function testAnRtorrentOlderThanTheLatestReleaseIsReportedOnce()
	{
		foreach(array('0.9.8', '0.15.7', '0.16.25') as $version)
		{
			$output = $this->runWithRtorrent('old', $version);
			$this->assertEquals(1, self::times($output, 'vulnerableRTorrentVersion'),
				'rTorrent ' . $version . ' is reported once');
			$this->assertEquals(' (' . $version . ')', self::loggedSuffix($output, 'vulnerableRTorrentVersion'),
				'to the log, with the version it found');
			$this->assertTrue(!GetpluginsHarness::reported($output, 'vulnerableRTorrentVersion'),
				'and not as a popup');
		}
	}

	public function testTheLatestRtorrentReleaseAndLaterAreNotReported()
	{
		foreach(array('0.16.26', '0.16.27', '0.17.0') as $version)
		{
			$output = $this->runWithRtorrent('current', $version);
			$this->assertEquals(0, self::times($output, 'vulnerableRTorrentVersion'),
				'rTorrent ' . $version . ' is not reported');
		}
	}

	/**
	 * The version is the daemon's own string. It is written as a string
	 * literal, so a quote or a closing tag in it stays text in the log.
	 */
	public function testTheReportedVersionStaysOneStringLiteral()
	{
		$version = "0.9.8-x'y\"z</script>";
		$this->release();
		$this->harness = new GetpluginsHarness('quoted');
		$this->harness->withRtorrent(4242, '4242', $version);
		$output = $this->harness->run(array('RU_TEMP_DIRECTORY' => $this->harness->directory('temp', 0700)));
		$this->assertTrue(GetpluginsHarness::ran($output), 'getplugins.php ran against a quoted rTorrent version');
		$this->assertEquals(1, self::times($output, 'vulnerableRTorrentVersion'), 'the version is reported once');
		$this->assertEquals(' (' . $version . ')', self::loggedSuffix($output, 'vulnerableRTorrentVersion'),
			'and the log call carries it as one string literal');
		$this->assertTrue(strpos($output, "x'y") === false && strpos($output, '</script>') === false,
			'with no quote or closing tag of its own in the script');
	}

	public function testNoRtorrentIsNotReportedAsAnOldOne()
	{
		$this->release();
		$this->harness = new GetpluginsHarness('none');
		$output = $this->harness->run(array('RU_TEMP_DIRECTORY' => $this->harness->directory('temp', 0700)));
		$this->assertTrue(GetpluginsHarness::reported($output, 'badLinkTorTorrent'),
			'a run with no rTorrent says so');
		$this->assertEquals(0, self::times($output, 'vulnerableRTorrentVersion'),
			'and does not call the missing rTorrent an old one');
	}

	/**
	 * The suite runs on PHP 7 and on PHP 8, so each side of the check is
	 * exercised by the run on that side.
	 */
	public function testPhp7IsReportedOnceAndPhp8IsNot()
	{
		$output = $this->runWithRtorrent('php', '0.16.26');
		if(PHP_VERSION_ID < 80000)
		{
			$this->assertEquals(1, self::times($output, 'insecurePHPVersion'),
				'PHP ' . PHP_VERSION . ' is reported once');
			$this->assertEquals(' (' . PHP_VERSION . ')', self::loggedSuffix($output, 'insecurePHPVersion'),
				'to the log, with the version it runs on');
		}
		else
			$this->assertEquals(0, self::times($output, 'insecurePHPVersion'),
				'PHP ' . PHP_VERSION . ' is not reported');
	}
}
