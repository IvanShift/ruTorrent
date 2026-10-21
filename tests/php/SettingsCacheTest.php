<?php

$_ENV['RU_PROFILE_PATH'] = sys_get_temp_dir() . '/rutorrent-settings-cache-test-' . getmypid();

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/settings.php');

/**
 * rTorrentSettings caches itself into share/settings/rtorrent.dat, and
 * php/xmlrpc.php builds its singleton from that file on the first
 * rXMLRPCCommand of every later request. Only obtain() can set linkExist.
 * A cache miss probes the daemon before resolving command aliases, while a
 * failed probe cannot replace a previously stored good settings object.
 */
class SettingsCacheTest extends TestCase
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

	// The constructor is private and get() is a per-process singleton, so build
	// instances the way RtorrentCompatibilityTest does.
	private function makeSettings($linkExist)
	{
		$reflection = new ReflectionClass('rTorrentSettings');
		$settings = $reflection->newInstanceWithoutConstructor();
		$settings->linkExist = $linkExist;
		if ($linkExist) {
			$settings->version = '0.16.20';
			$settings->iVersion = 0x1014;
			$settings->aliases = array(
				'set_download_rate' => array('name' => 'throttle.global_down.max_rate.set', 'prm' => 1),
			);
		}
		return $settings;
	}

	private function cacheFile()
	{
		return FileUtil::getSettingsPath() . '/rtorrent.dat';
	}

	private function readCached()
	{
		$cached = new ReflectionClass('rTorrentSettings');
		$cached = $cached->newInstanceWithoutConstructor();
		(new rCache())->get($cached);
		return $cached;
	}

	public function testCacheMissProbesDaemonBeforeResolvingAliases()
	{
		$fixture = $this->profilePath.'/miss-fixture';
		if (!mkdir($fixture, 0700, true)) {
			throw new Exception('Could not create settings cache miss fixture');
		}
		foreach (array('settings.php', 'xmlrpc_path.php', 'commandargs.php', 'methods-0.9.4.php') as $name) {
			if (!copy(__DIR__.'/../../php/'.$name, $fixture.'/'.$name)) {
				throw new Exception('Could not copy production '.$name);
			}
		}
		file_put_contents($fixture.'/cache.php', <<<'PHP'
<?php
class rCache
{
	public function get(&$settings) { return false; }
}
PHP
);
		file_put_contents($fixture.'/xmlrpc.php', <<<'PHP'
<?php
class rXMLRPCCommand
{
	public $command;
	public function __construct($name) { $this->command = $name; }
}
class rXMLRPCRequest
{
	public static $calls = 0;
	public $val = array();
	private $command;
	public function __construct($command) { $this->command = $command->command; }
	public function run()
	{
		self::$calls++;
		if ($this->command === 'system.client_version') {
			$this->val = array('0.9.8');
			return true;
		}
		return false;
	}
	public function success() { return false; }
}
PHP
);
		file_put_contents($fixture.'/run.php', <<<'PHP'
<?php
chdir(__DIR__);
require __DIR__.'/settings.php';
$settings = rTorrentSettings::get(false);
echo json_encode(array(
	'calls' => rXMLRPCRequest::$calls,
	'version' => $settings->iVersion,
	'mapped' => $settings->getCommand('d.get_name'),
));
PHP
);
		$process = proc_open(array(PHP_BINARY, $fixture.'/run.php'),
			array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $fixture);
		if (!is_resource($process)) {
			throw new Exception('Could not start settings cache miss fixture');
		}
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit = proc_close($process);
		$this->assertEquals(0, $exit, 'The production settings fixture exits cleanly: '.$error);
		$actual = json_decode($output, true);
		$this->assertTrue(is_array($actual), 'The production settings fixture returned JSON');
		$this->assertTrue($actual['calls'] > 0, 'A missing cache triggers a live version probe');
		$this->assertEquals(0x0908, $actual['version'], 'The supported 0.9.8 version is known after the probe');
		$this->assertEquals('d.name', $actual['mapped'], 'The renamed getter is resolved before returning settings');
	}

	public function testFailedProbeIsNotCached()
	{
		$this->makeSettings(false)->store();
		$this->assertTrue(
			!is_file($this->cacheFile()),
			'A settings object whose probe failed must not be written to the cache'
		);
	}

	public function testFailedProbeReportsThatItStoredNothing()
	{
		$this->assertEquals(
			false,
			$this->makeSettings(false)->store(),
			'store() reports false when it declines to cache a failed probe'
		);
	}

	public function testSuccessfulProbeIsCached()
	{
		$this->assertTrue(
			$this->makeSettings(true)->store() !== false,
			'store() reports success for a settings object with a live link'
		);
		$this->assertTrue(
			is_file($this->cacheFile()),
			'A settings object with a live link is written to the cache'
		);
		$cached = $this->readCached();
		$this->assertTrue($cached->linkExist, 'The cached object carries linkExist');
		$this->assertEquals('0.16.20', $cached->version, 'The cached object carries the probed version');
	}

	// The defect: an outage window, or doneplugins.php finding no cache file at
	// all, replaces a good cache with a failed one, and every request after it
	// inherits the verdict.
	public function testFailedProbeDoesNotOverwriteAGoodCache()
	{
		$this->makeSettings(true)->store();
		$this->makeSettings(false)->store();

		$cached = $this->readCached();
		$this->assertTrue($cached->linkExist, 'A failed probe leaves an existing good cache alone');
		$this->assertEquals(
			'throttle.global_down.max_rate.set',
			$cached->getCommand('set_download_rate'),
			'The cached alias map survives a failed probe, so renamed commands keep resolving'
		);
	}

	public function testUnregisteringStoredHookSubjectsPreservesOtherPluginsAndSparseEntries()
	{
		$settings = $this->makeSettings(false);
		$settings->registerEventHook('rssurlrewrite', array('RSSFetched', 'URLFetched'), 10);
		$settings->registerEventHook('_cloudflare', 'URLFetched', 20);
		$settings->unregisterEventHook('rssurlrewrite', array('RSSFetched', 'URLFetched'), false);
		$this->assertTrue(!array_key_exists('RSSFetched', $settings->hooks),
			'The last subject retires its event key');
		$this->assertEquals(array(array('name' => '_cloudflare', 'level' => 20)),
			array_values($settings->hooks['URLFetched']), 'Only the named plugin is removed');
		$remaining = $settings->hooks;
		$settings->unregisterEventHook('_cloudflare', 'missing', false);
		$this->assertEquals($remaining, $settings->hooks, 'An absent event preserves the remaining hooks');
		$settings->unregisterEventHook('missing-plugin', 'URLFetched', false);
		$this->assertEquals($remaining, $settings->hooks, 'An absent plugin preserves the remaining subjects');
		$settings->unregisterEventHook('_cloudflare', 'URLFetched', false);
		$this->assertEquals(array(), $settings->hooks, 'A remaining sparse subject can also be removed');
	}

	// What the tenant meets when the map is lost: the status bar's rate control
	// sends a name rtorrent has not answered to since 0.9.x, and the daemon
	// faults instead of throttling.
	public function testAnEmptyAliasMapSendsLegacyCommandNames()
	{
		$this->assertEquals(
			'set_download_rate',
			$this->makeSettings(false)->getCommand('set_download_rate'),
			'With no alias map getCommand() falls back to the legacy spelling'
		);
	}

	// The entry registerEventHook() stores for a hook, which is what
	// unregisterEventHookPrim() compares against when removing it.
	private function hookSubject($plugin, $level = 10)
	{
		return array('name' => $plugin, 'level' => $level);
	}

	/**
	 * A hook is registered when a plugin is enabled and removed by its
	 * done.php when it is disabled, and neither process is the one that runs
	 * the hook: pushEvent() reads the list out of this cache on some later
	 * request. So the $save argument, which unregisterEventHook() defaults to
	 * true, is the whole of what a registration or a removal means.
	 */
	public function testRegisteringAHookWithSaveWritesItToTheCache()
	{
		$settings = $this->makeSettings(true);
		$settings->registerEventHook('extratio', 'LabelChanged', 10, true);

		$cached = $this->readCached();
		$this->assertTrue(
			$cached->hooks === array('LabelChanged' => array($this->hookSubject('extratio'))),
			'A hook registered with $save is in the cache a later request reads: '
			. json_encode($cached->hooks)
		);
	}

	public function testUnregisteringAHookPersistsTheRemoval()
	{
		$settings = $this->makeSettings(true);
		$settings->registerEventHook('extratio', 'LabelChanged', 10, true);
		$this->assertTrue(
			$this->readCached()->hooks !== array(),
			'The hook is in the cache before it is removed'
		);

		$settings->unregisterEventHook('extratio', 'LabelChanged');

		$this->assertTrue($settings->hooks === array(), 'The removal happens in the object');
		$cached = $this->readCached();
		$this->assertTrue(
			$cached->hooks === array(),
			'and reaches the cache, so a later request no longer runs the hook: '
			. json_encode($cached->hooks)
		);
	}

	// A removal is not written for a settings object the cache may not hold,
	// on the same terms as any other store(): the cache keeps the last object
	// a live probe produced rather than taking a failed one's word.
	public function testAFailedProbeDoesNotWriteItsHookRemoval()
	{
		$good = $this->makeSettings(true);
		$good->registerEventHook('extratio', 'LabelChanged', 10, true);

		$failed = $this->makeSettings(false);
		$failed->registerEventHook('extratio', 'LabelChanged', 10, false);
		$failed->unregisterEventHook('extratio', 'LabelChanged');

		$cached = $this->readCached();
		$this->assertTrue(
			$cached->hooks === array('LabelChanged' => array($this->hookSubject('extratio'))),
			'An unprobed object does not remove a hook from the cache: ' . json_encode($cached->hooks)
		);
	}
	// The format before 63f15c6c stored a bare plugin name where a subject
	// array sits now, and a cache written by that version can still be the one
	// on disk, so the name has to be read out of either shape.
	public function testUnregisteringReadsAPlainNameSubject()
	{
		$settings = $this->makeSettings(false);
		$settings->hooks = array(
			"RSSFetched" => array("rssurlrewrite"),
			"URLFetched" => array("rssurlrewrite", $this->hookSubject("_cloudflare", 20)),
		);

		$settings->unregisterEventHook("rssurlrewrite", array("RSSFetched", "URLFetched"), false);

		$this->assertTrue(
			!array_key_exists("RSSFetched", $settings->hooks),
			"A plain-name subject is removed and retires its event key"
		);
		$this->assertEquals(
			array($this->hookSubject("_cloudflare", 20)),
			array_values($settings->hooks["URLFetched"]),
			"A plain-name subject is removed from beside a subject array"
		);
	}
}
