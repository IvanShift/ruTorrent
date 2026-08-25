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
	}

	public function testHelperIsShipped()
	{
		$this->assertTrue(is_file(__DIR__ . '/../../../plugins/erasedata/erase.php'),
			'the helper the command points at is part of the tree');
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
		file_put_contents($fixture.'/plugins/erasedata/removewithdata.php', '<?php function erasedataRemoveWithData($hashes,$force){'
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
		file_put_contents($fixture.'/plugins/erasedata/removewithdata.php', '<?php function erasedataRemoveWithData($hashes,$force){'
			.'file_put_contents(getenv("ERASEDATA_ENTRY_LOG"),json_encode(array($hashes,$force)));return array();}');
		file_put_contents($settings.'/erasedata/'.$hash.'.list', "legacy\nlegacy\n0\n1\n");
		putenv('ERASEDATA_ENTRY_SETTINGS='.$settings);
		putenv('ERASEDATA_ENTRY_LOG='.$callLog);
		try {
			$output = array();
			$status = 0;
			exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($entrypoint).' '.escapeshellarg($hash).' 1 2>&1', $output, $status);
			$this->assertEquals(0, $status, 'the copied production entrypoint exits successfully: '.implode("\n", $output));
			$this->assertEquals(array(array($hash), '1'),
				is_file($callLog) ? json_decode(file_get_contents($callLog), true) : null,
				'a legacy pending generation cannot suppress erase of a re-added torrent with the same hash');
		} finally {
			putenv('ERASEDATA_ENTRY_SETTINGS');
			putenv('ERASEDATA_ENTRY_LOG');
			$this->removeTree($fixture);
		}
	}
}

if(isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__)
{
	$test = new EraseWithDataCommandTest();
	$test->setUp();
	ob_start();
	$test->run();
	$output = ob_get_clean();
	$test->tearDown();
	echo($output);
	exit(preg_match('/^Failed:|failed with error|PHP (?:Fatal|Parse) error|Uncaught/m', $output) ? 1 : 0);
}
