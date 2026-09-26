<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../../plugins/ratio/ratio.php');

class EraseWithDataCommandTest extends TestCase
{
	private function removeTree($path)
	{
		if(is_link($path) || is_file($path))
		{
			@unlink($path);
			return;
		}
		if(!is_dir($path))
			return;
		foreach(array_diff(scandir($path), array('.', '..')) as $entry)
			$this->removeTree($path.'/'.$entry);
		@rmdir($path);
	}

	private function command($force)
	{
		$rat = new rRatio();
		return($rat->getEraseWithDataCommand($force));
	}

	public function testStopsAndClosesBeforeDeleting()
	{
		$this->assertEquals(0, strpos($this->command("1"), "d.stop=; d.close=; "),
			'the download is stopped and closed first');
	}

	public function testHandsTheEraseToTheErasedataHelper()
	{
		$cmd = $this->command("1");
		$this->assertTrue(strpos($cmd, "/erasedata/erase.php") !== false,
			'the erase runs through the erasedata helper, which records the file list first');
		$this->assertTrue(strpos($cmd, "d.erase") === false,
			'the group command no longer erases by itself, or the data would be gone with the download');
		$this->assertTrue(strpos($cmd, "custom5") === false,
			'the legacy custom5 marker is not written: nothing consumes it as a delete-data flag any more');
	}

	public function testHelperIsShipped()
	{
		$this->assertTrue(is_file(__DIR__ . '/../../../plugins/erasedata/erase.php'),
			'the helper the command points at is part of the tree');
	}

	public function testMissingErasedataHelperRefusesAndLogsOncePerAction()
	{
		$fixture = sys_get_temp_dir().'/ratio-missing-erase-'.bin2hex(random_bytes(4));
		mkdir($fixture.'/plugins/ratio', 0700, true);
		mkdir($fixture.'/php', 0700, true);
		copy(__DIR__.'/../../../plugins/ratio/ratio.php', $fixture.'/plugins/ratio/ratio.php');
		file_put_contents($fixture.'/php/xmlrpc.php', '<?php class FileUtil {'
			.'public static $logs = array(); public static function getPluginConf($name) { return ""; } '
			.'public static function toLog($message) { self::$logs[] = $message; }} '
			.'function getCmd($command) { return $command; }');
		file_put_contents($fixture.'/php/cache.php', '<?php');
		file_put_contents($fixture.'/php/settings.php', '<?php');
		file_put_contents($fixture.'/probe.php', '<?php require dirname(__FILE__)."/plugins/ratio/ratio.php"; '
			.'$ratio = new rRatio(); $out = array(); foreach (array("1", "2") as $force) {'
			.'FileUtil::$logs = array(); $out[$force] = array($ratio->getEraseWithDataCommand($force), FileUtil::$logs); } '
			.'echo json_encode($out);');
		try {
			$output = array();
			$status = 0;
			exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($fixture.'/probe.php').' 2>&1', $output, $status);
			$decoded = json_decode(implode("\n", $output), true);
			$this->assertEquals(0, $status, 'copied Ratio command runs without the erasedata helper');
			$this->assertEquals(array(
				'1' => array('cat=', array('ratio: erasedata-helper-unavailable')),
				'2' => array('cat=', array('ratio: erasedata-helper-unavailable')),
			), $decoded, 'both erase actions refuse with one classified log line each');
		} finally {
			$this->removeTree($fixture);
		}
	}

	public function testRatioInitRearmsTheQueueWhenErasedataIsNotRegistered()
	{
		$fixture = sys_get_temp_dir().'/ratio-rearm-'.bin2hex(random_bytes(4));
		mkdir($fixture.'/plugins/ratio', 0700, true);
		mkdir($fixture.'/plugins/erasedata', 0700, true);
		copy(__DIR__.'/../../../plugins/ratio/init.php', $fixture.'/plugins/ratio/init.php');
		file_put_contents($fixture.'/plugins/ratio/ratio.php', '<?php class rRatio {'
			.'public static function load() { return new self(); } '
			.'public function obtain() { return $GLOBALS["test_mode"] !== "ratio-disabled"; } '
			.'public function get() { return ""; }}');
		$helper = $fixture.'/plugins/erasedata/removewithdata.php';
		file_put_contents($helper, '<?php function erasedataRearmDrainSchedule() {'
			.'$GLOBALS["rearms"]++; if($GLOBALS["test_mode"] === "rearm-refused") {'
			.'FileUtil::toLog("erasedata: rearm-refused generation=1 members=1 consequence=obligations-retained"); return false; } return true; }');
		$probe = <<<'PHP'
<?php
class FileUtil {
    public static $log = array();
    public static function toLog($message) { self::$log[] = $message; }
}
class FakeSettings {
    private $plugins = array();
    public function registerPlugin($name, $data) { $this->plugins[$name] = $data; }
    public function isPluginRegistered($name) { return array_key_exists($name, $this->plugins); }
}
$GLOBALS['rearms'] = 0;
$GLOBALS['test_mode'] = $argv[1];
$theSettings = new FakeSettings();
if($argv[1] === 'registered') $theSettings->registerPlugin('erasedata', 0);
$plugin = array('name' => 'ratio');
$pInfo = array('perms' => 0);
$jResult = '';
require __DIR__.'/plugins/ratio/init.php';
echo json_encode(array('rearms' => $GLOBALS['rearms'], 'log' => FileUtil::$log));
PHP;
		file_put_contents($fixture.'/probe.php', $probe);
		try {
			foreach(array('missing-registration' => 1, 'registered' => 0, 'ratio-disabled' => 1) as $mode => $expected)
			{
				$output = array();
				$status = 0;
				exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($fixture.'/probe.php')
					.' '.escapeshellarg($mode).' 2>&1', $output, $status);
				$this->assertEquals(0, $status, 'copied ratio init exits normally: '.implode("\n", $output));
				$this->assertEquals(array('rearms' => $expected, 'log' => array()),
					json_decode(implode("\n", $output), true),
					'erasedata absent re-arms once; an already initialized erasedata is not re-registered');
			}
			$output = array();
			$status = 0;
			exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($fixture.'/probe.php')
				.' rearm-refused 2>&1', $output, $status);
			$this->assertEquals(0, $status, 'failed rearm is a visible refusal, not a fatal');
			$this->assertEquals(array('rearms' => 1,
				'log' => array('erasedata: rearm-refused generation=1 members=1 consequence=obligations-retained')),
				json_decode(implode("\n", $output), true),
				'the shared helper keeps its classified refusal without a duplicate generic log');
			unlink($helper);
			$output = array();
			$status = 0;
			exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($fixture.'/probe.php')
				.' missing-registration 2>&1', $output, $status);
			$this->assertEquals(0, $status, 'missing helper is a visible refusal, not a fatal');
			$this->assertEquals(array('rearms' => 0,
				'log' => array('ratio: erasedata-rearm-helper-unavailable')),
				json_decode(implode("\n", $output), true),
				'ratio names the missing recovery helper while leaving the queue untouched');
		} finally {
			$this->removeTree($fixture);
		}
	}

	public function testRunsInTheBackground()
	{
		$this->assertTrue(strpos($this->command("1"), "execute.nothrow.bg={") !== false,
			'a foreground execute would block rtorrent while the helper waits for rtorrent');
	}

	public function testPassesTheHashOfTheDownloadItRunsOn()
	{
		$this->assertTrue(strpos($this->command("1"), ',$' . getCmd("d.get_hash") . '=,') !== false,
			'the hash is substituted by rtorrent when the group command fires');
	}

	public function testForceFlagDistinguishesTheTwoActions()
	{
		$hash = '$' . getCmd("d.get_hash") . '=';
		$this->assertTrue(strpos($this->command("1"), $hash . ',1,') !== false,
			'Remove data asks for the download\'s own files');
		$this->assertTrue(strpos($this->command("2"), $hash . ',2,') !== false,
			'Remove data (All) asks for the whole base path');
	}

	public function testRejectsCoercedForceParameters()
	{
		$invalid = array(null, false, true, 0, 1, 2, "", "0", "01", "02", " 1", "1 ", "1; injected", array(1));
		foreach($invalid as $force)
		{
			$cmd = $this->command($force);
			$this->assertTrue(strpos($cmd, "/erasedata/erase.php") === false,
				'coerced force values must not reach the erasedata helper');
			$this->assertTrue(strpos($cmd, "d.erase") === false,
				'coerced force values must not fall back to raw erase');
			$this->assertTrue(strpos($cmd, "custom5") === false,
				'coerced force values must not be written to custom5 either');
		}
	}

	public function testEntrypointRejectsMissingForceArgument()
	{
		$fixture = sys_get_temp_dir().'/erasedata-entrypoint-missing-force-'.bin2hex(random_bytes(4));
		$entrypoint = $fixture.'/plugins/erasedata/erase.php';
		$callLog = $fixture.'/called.json';
		$hash = str_repeat('A', 40);
		mkdir($fixture.'/plugins/erasedata', 0777, true);
		mkdir($fixture.'/php', 0777, true);
		copy(__DIR__.'/../../../plugins/erasedata/erase.php', $entrypoint);
		copy(__DIR__.'/../../../plugins/erasedata/manifest.php', $fixture.'/plugins/erasedata/manifest.php');
		file_put_contents($fixture.'/php/xmlrpc.php', "<?php\n");
		file_put_contents($fixture.'/plugins/erasedata/removewithdata.php', '<?php function erasedataAdmitRemoval($hashes,$force){'
			.'file_put_contents(getenv("ERASEDATA_ENTRY_LOG"),json_encode(array($hashes,$force)));return array();}');
		putenv('ERASEDATA_ENTRY_LOG='.$callLog);
		try {
			$output = array();
			$status = 0;
			exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($entrypoint).' '.escapeshellarg($hash).' 2>&1', $output, $status);
			$this->assertEquals(1, $status, 'missing force must be rejected by the CLI entrypoint: '.implode("\n", $output));
			$this->assertTrue(!file_exists($callLog), 'missing force cannot invoke the shared producer');
		} finally {
			putenv('ERASEDATA_ENTRY_LOG');
			$this->removeTree($fixture);
		}
	}

	public function testPublishingRefusesInsteadOfDyingWhenItsNeighboursAreNotLoaded()
	{
		// manifest.php requires nothing, so erase.php can load it alone for its
		// force pre-check. Publishing a staged manifest borrows two symbols from
		// files manifest.php cannot name without making a require cycle. Today
		// every caller loads them. A future one that does not must get a refusal
		// it can read, not a fatal on an undefined symbol.
		$fixture = sys_get_temp_dir().'/erasedata-publish-isolated-'.bin2hex(random_bytes(4));
		$hash = str_repeat('A', 40);
		mkdir($fixture, 0777, true);
		copy(__DIR__.'/../../../plugins/erasedata/manifest.php', $fixture.'/manifest.php');
		$probe = implode("\n", array(
			'<?php',
			'class FileUtil { public static $log = array();',
			'    public static function toLog($m) { self::$log[] = $m; } }',
			'require_once(dirname(__FILE__)."/manifest.php");',
			'$tmp = dirname(__FILE__)."/'.$hash.'.tmp";',
			'file_put_contents($tmp, "staged");',
			'$ok = ErasedataManifestCodec::publishStaging($tmp, "'.$hash.'");',
			'echo json_encode(array("result" => $ok, "log" => FileUtil::$log,',
			'    "staged" => is_file($tmp),',
			'    "published" => file_exists(substr($tmp, 0, -4).".list")));',
		));
		file_put_contents($fixture.'/probe.php', $probe);
		try {
			$output = array();
			$status = 0;
			exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($fixture.'/probe.php').' 2>&1', $output, $status);
			$joined = implode("\n", $output);
			$this->assertEquals(0, $status, 'loading manifest.php on its own must not be fatal: '.$joined);
			$decoded = json_decode($joined, true);
			$this->assertTrue(is_array($decoded), 'the probe must report a result: '.$joined);
			if(!is_array($decoded))
				return;
			$this->assertEquals(false, $decoded['result'],
				'publishing refuses when the files it borrows from are not loaded');
			$this->assertTrue($decoded['staged'],
				'the refusal retains the staged manifest for a later pass');
			$this->assertTrue(!$decoded['published'],
				'the refusal publishes nothing');
			$this->assertTrue(count($decoded['log']) === 1
				&& strpos($decoded['log'][0], 'filesystem.php') !== false
				&& strpos($decoded['log'][0], $hash) !== false,
				'the refusal says why once, naming the missing dependency and the hash: '
					.json_encode($decoded['log']));
		} finally {
			$this->removeTree($fixture);
		}
	}

	public function testLegacyManifestDoesNotBlockAReaddedSameHash()
	{
		$fixture = sys_get_temp_dir().'/erasedata-entrypoint-'.bin2hex(random_bytes(4));
		$settings = $fixture.'/settings';
		$entrypoint = $fixture.'/plugins/erasedata/erase.php';
		$callLog = $fixture.'/called.json';
		$hash = str_repeat('A', 40);
		mkdir($fixture.'/plugins/erasedata', 0777, true);
		mkdir($fixture.'/php', 0777, true);
		mkdir($settings.'/erasedata', 0777, true);
		copy(__DIR__.'/../../../plugins/erasedata/erase.php', $entrypoint);
		copy(__DIR__.'/../../../plugins/erasedata/manifest.php', $fixture.'/plugins/erasedata/manifest.php');
		file_put_contents($fixture.'/php/xmlrpc.php', '<?php class FileUtil {'
			.'public static function getSettingsPath(){return getenv("ERASEDATA_ENTRY_SETTINGS");}}');
		file_put_contents($fixture.'/plugins/erasedata/removewithdata.php', '<?php function erasedataAdmitRemoval($hashes,$force){'
			.'file_put_contents(getenv("ERASEDATA_ENTRY_LOG"),json_encode(array($hashes,$force)));return array();}');
		file_put_contents($settings.'/erasedata/'.$hash.'.list', "legacy\nlegacy\n0\n1\n");
		putenv('ERASEDATA_ENTRY_SETTINGS='.$settings);
		putenv('ERASEDATA_ENTRY_LOG='.$callLog);
		try {
			$output = array();
			$status = 0;
			exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($entrypoint).' '.escapeshellarg($hash).' 1 2>&1', $output, $status);
			$this->assertEquals(0, $status, 'the copied production entrypoint exits successfully: '.implode("\n", $output));
			$this->assertTrue(is_file($callLog)
				&& json_decode(file_get_contents($callLog), true) === array(array($hash), 1),
				'a legacy pending generation cannot suppress erase of a re-added torrent with the same hash');
		} finally {
			putenv('ERASEDATA_ENTRY_SETTINGS');
			putenv('ERASEDATA_ENTRY_LOG');
			$this->removeTree($fixture);
		}
	}
}

// Direct invocation keeps its original output contract; php-test.sh uses the shared runner.
if(getenv('RUTORRENT_PHP_TEST_RUNNER') !== '1' && isset($_SERVER['SCRIPT_FILENAME'])
	&& realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__)
{
	$test = new EraseWithDataCommandTest();
	ob_start();
	$test->run();
	$output = ob_get_clean();
	echo($output);
	exit(preg_match('/^Failed:|failed with error|PHP (?:Fatal|Parse) error|Uncaught/m', $output) ? 1 : 0);
}
