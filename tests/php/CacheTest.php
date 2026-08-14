<?php

$_ENV['RU_PROFILE_PATH'] = sys_get_temp_dir() . '/rutorrent-cache-test-' . getmypid();

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/cache.php');
require_once(__DIR__ . '/CacheMergePayloadFixture.php');

class CacheTestPayload
{
	public $hash = 'cache-test.dat';
	public $version = 1;
	public $modified = false;
	public $value = 'initial';
}

class CacheTestThrowingPayload
{
	public $hash = 'throwing-cache-test.dat';

	public function __wakeup()
	{
		throw new Exception('wakeup failed');
	}
}

class CacheTest extends TestCase
{
	private $profilePath;

	public function setUp()
	{
		$this->profilePath = $_ENV['RU_PROFILE_PATH'];
		if (is_dir($this->profilePath)) {
			$this->removeDir($this->profilePath);
		}
		FileUtil::makeDirectory(FileUtil::getSettingsPath());
	}

	public function tearDown()
	{
		if (is_dir($this->profilePath)) {
			$this->removeDir($this->profilePath);
		}
	}

	private function removeDir($dir)
	{
		foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
			$path = $dir . '/' . $entry;
			if (is_dir($path) && !is_link($path)) {
				$this->removeDir($path);
			} else {
				unlink($path);
			}
		}
		rmdir($dir);
	}

	public function testGetDoesNotEmitWarningsForCacheFilesWithExtraData()
	{
		$payload = new CacheTestPayload();
		$payload->value = 'cached';
		$cacheFile = FileUtil::getSettingsPath() . '/' . $payload->hash;
		file_put_contents($cacheFile, serialize($payload) . serialize($payload));

		$warnings = [];
		set_error_handler(function ($errno, $errstr) use (&$warnings) {
			$warnings[] = $errstr;
			return true;
		});

		$loaded = new CacheTestPayload();
		$loadedResult = (new rCache())->get($loaded);
		restore_error_handler();

		$this->assertEquals([], $warnings, 'Corrupt cache tail does not emit PHP warnings');
		$this->assertTrue($loadedResult, 'Cache with a readable first payload still loads');
		$this->assertEquals('cached', $loaded->value, 'Cache loads the first readable payload');
	}

	public function testSetAppliesProfileMaskToLockFile()
	{
		global $profileMask;

		$oldMask = umask(0077);
		$oldProfileMask = $profileMask;
		$profileMask = 0666;
		$payload = new CacheTestPayload();
		$payload->hash = 'masked-cache-test.dat';

		try {
			$stored = (new rCache())->set($payload);
		} finally {
			umask($oldMask);
			$profileMask = $oldProfileMask;
		}

		$lockFile = FileUtil::getSettingsPath() . '/' . $payload->hash . '.lock';
		clearstatcache(true, $lockFile);

		$this->assertTrue($stored, 'Cache set succeeds with a restrictive process umask');
		$this->assertEquals(0666, fileperms($lockFile) & 0666, 'Cache lock file follows the configured profile mask');
	}

	public function testRemoveAlsoRemovesLockFile()
	{
		$payload = new CacheTestPayload();
		$payload->hash = 'remove-cache-test.dat';
		$cache = new rCache();
		$cacheFile = FileUtil::getSettingsPath() . '/' . $payload->hash;
		$lockFile = $cacheFile . '.lock';

		$this->assertTrue($cache->set($payload), 'Cache set creates the cache file');
		$this->assertTrue(file_exists($lockFile), 'Cache set creates the lock file');

		$cache->remove($payload);

		clearstatcache(true, $cacheFile);
		clearstatcache(true, $lockFile);
		$this->assertEquals(false, file_exists($cacheFile), 'Cache remove deletes the cache file');
		$this->assertEquals(false, file_exists($lockFile), 'Cache remove deletes the lock file');
	}

	// Writes a helper that does what a second update.php does -- load the
	// cache, record one row, store it -- as a genuinely separate process. It
	// has to be a real process: rCache remembers the loaded file's stamp in a
	// static, so a second writer inside this one would share it.
	private function makeWriterScript()
	{
		$path = FileUtil::getSettingsPath() . '/concurrent-writer.php';
		$code = "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->profilePath, true) . ";\n"
			. 'require_once(' . var_export(realpath(__DIR__ . '/../../php/cache.php'), true) . ");\n"
			. 'require_once(' . var_export(realpath(__DIR__ . '/CacheMergePayloadFixture.php'), true) . ");\n"
			. "\$payload = new CacheMergePayload();\n"
			. "(new rCache())->get(\$payload);\n"
			. "exit(\$payload->addRow(\$argv[1]) ? 0 : 1);\n";
		file_put_contents($path, $code);
		return $path;
	}

	// The live failure this guards: replacing a torrent fires three update.php
	// processes inside one second, and filemtime only resolves to the second,
	// so a writer that reloaded after a same-second write saw an unchanged
	// timestamp, skipped merging and republished the file without the other
	// process's row. On a live fleet the row that vanished was the "added" one
	// of every replacement.
	public function testRowFromAnotherProcessSurvivesAWriteInTheSameSecond()
	{
		// Start near the top of a second so the seed, this process's load and
		// the other process's write all share one mtime.
		usleep((int) ((1 - fmod(microtime(true), 1)) * 1000000));

		$seed = new CacheMergePayload();
		$this->assertTrue($seed->addRow('seed'), 'the seeding write succeeds');

		// This process loads the file; rCache records how the file looked.
		$mine = new CacheMergePayload();
		(new rCache())->get($mine);

		// Another process records its own row in the very same second.
		$writer = $this->makeWriterScript();
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($writer) . ' other-process', $output, $code);
		$this->assertEquals(0, $code, 'the second writer process stores its row');

		// Only now does this process store its own row.
		$this->assertTrue($mine->addRow('mine'), 'this process stores its row');

		$check = new CacheMergePayload();
		(new rCache())->get($check);
		$this->assertTrue(isset($check->rows['mine']), 'this process keeps its own row');
		$this->assertTrue(isset($check->rows['other-process']),
			'the row another process wrote in the same second is not overwritten');
		$this->assertTrue(isset($check->rows['seed']), 'the pre-existing row is untouched');
	}

	public function testGetRestoresErrorHandlerWhenUnserializeThrows()
	{
		$payload = new CacheTestThrowingPayload();
		$cacheFile = FileUtil::getSettingsPath() . '/' . $payload->hash;
		file_put_contents($cacheFile, serialize($payload));

		$warnings = [];
		set_error_handler(function ($errno, $errstr) use (&$warnings) {
			$warnings[] = $errstr;
			return true;
		});

		try {
			(new rCache())->get($payload);
		} catch (Exception $e) {
			// Expected: this test only cares that rCache restores the handler.
		}

		trigger_error('after failed unserialize', E_USER_WARNING);
		$restored = count($warnings) > 0;
		restore_error_handler();
		if (!$restored) {
			restore_error_handler();
		}

		$this->assertEquals(array('after failed unserialize'), $warnings, 'Cache get restores the previous error handler after unserialize throws');
	}
}
