<?php

$_ENV['RU_PROFILE_PATH'] = sys_get_temp_dir() . '/rutorrent-settings-raw-' . getmypid();

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/settings.php');
require_once(__DIR__ . '/FakeRtorrentDaemon.php');

/**
 * rTorrentSettings keeps the daemon's default directory, session path, home
 * and session name, and caches them in share/settings/rtorrent.dat for every
 * later request (AUD-ESC-12).
 *
 * While rXMLRPCRequest escaped every quote and backslash it read, those were
 * held escaped: the default directory of an addition was escaped again by
 * quoteCommandArg(), `mkdir -p` created a directory named with the
 * backslashes, relative directories resolved under it and the session files
 * were looked for where they are not. The values are now the daemon's bytes,
 * and a cache written before the change -- it carries no value format -- is
 * read from the daemon once more instead of being used, keeping what plugins
 * registered in it.
 */
class SettingsRawValuesTest extends TestCase
{
	const HOME = '/home/u "h" \\x';
	const DIRECTORY = '~/dl "q" \\b \'a\' &c';
	const SESSION = '/srv/se\\"ss\\\\ion/';
	const SERVER = 'name "n" \\s';
	const LIBRARY = '0.16.25 "x" \\y \'</script>';

	private $daemon;
	private $previousSettings;

	public function setUp()
	{
		$this->removeTree($_ENV['RU_PROFILE_PATH']);
		FileUtil::makeDirectory(FileUtil::getSettingsPath());
		$GLOBALS['scgi_host'] = '127.0.0.1';
		$GLOBALS['rpcTimeOut'] = 10;
		$GLOBALS['rpcLogCalls'] = false;
		$GLOBALS['rpcLogFaults'] = false;
		$GLOBALS['log_file'] = $_ENV['RU_PROFILE_PATH'] . '/app.log';
		$GLOBALS['topDirectory'] = '/';
		$property = new ReflectionProperty('rTorrentSettings', 'theSettings');
		self::makeAccessible($property);
		$this->previousSettings = $property->getValue();
		$this->resetSingleton();
	}

	public function tearDown()
	{
		$this->stopDaemon();
		$property = new ReflectionProperty('rTorrentSettings', 'theSettings');
		self::makeAccessible($property);
		$property->setValue(null, $this->previousSettings);
		$this->removeTree($_ENV['RU_PROFILE_PATH']);
	}

	private function removeTree($path)
	{
		if (!is_dir($path) || is_link($path)) {
			@unlink($path);
			return;
		}
		foreach (array_diff(scandir($path), array('.', '..')) as $entry) {
			$this->removeTree($path . '/' . $entry);
		}
		@rmdir($path);
	}

	private function resetSingleton()
	{
		$property = new ReflectionProperty('rTorrentSettings', 'theSettings');
		self::makeAccessible($property);
		$property->setValue(null, null);
	}

	private function stopDaemon()
	{
		if ($this->daemon !== null) {
			$this->daemon->stop();
			$this->daemon = null;
		}
	}

	/**
	 * A 0.16.25 daemon whose strings carry quotes and backslashes. Each value
	 * answers under both spellings: obtain() loads the alias tables with
	 * require_once, so a second obtain() in one test process sends the old
	 * names (production runs one per process).
	 */
	private function startDaemon()
	{
		$this->stopDaemon();
		$capture = array("1000\n1000 1001\n" . self::HOME . "\n");
		$this->daemon = new FakeRtorrentDaemon(new FakeRtorrentMethodReplies(array(
			'system.client_version' => array('0.16.25'),
			'system.api_version' => array(11),
			'system.sockets.available_alloc' => array(4096),
			'directory.default' => array(self::DIRECTORY), 'get_directory' => array(self::DIRECTORY),
			'session.path' => array(self::SESSION), 'get_session' => array(self::SESSION),
			'system.library_version' => array(self::LIBRARY),
			'session.name' => array(self::SERVER), 'get_name' => array(self::SERVER),
			'network.port_range' => array('6881-6881'), 'get_port_range' => array('6881-6881'),
			'network.listen.port.range' => array('6881-6881'),
			'network.bind_address' => array('0.0.0.0'), 'get_bind' => array('0.0.0.0'),
			'network.local_address' => array('0.0.0.0'), 'get_ip' => array('0.0.0.0'),
			'network.listen.port' => array(6881),
			'execute.capture' => $capture, 'execute_capture' => $capture,
		)), $_ENV['RU_PROFILE_PATH'] . '/calls.log');
		$GLOBALS['scgi_port'] = $this->daemon->port();
	}

	private function expandedDirectory()
	{
		return self::HOME . substr(self::DIRECTORY, 1);
	}

	private function same($expected, $actual, $what)
	{
		$this->assertSame($expected, $actual, $what
			. ($expected === $actual ? '' : ': got ' . var_export($actual, true)));
	}

	private function cached()
	{
		$cached = (new ReflectionClass('rTorrentSettings'))->newInstanceWithoutConstructor();
		$this->assertTrue((new rCache())->get($cached), 'the settings cache is readable');
		return $cached;
	}

	public function testObtainHoldsTheDaemonsOwnBytes()
	{
		$this->startDaemon();
		$settings = rTorrentSettings::get(true);
		$this->assertTrue($settings->linkExist, 'the fake daemon answered the probe');
		$this->same(self::HOME, $settings->home, 'the home directory is the daemon user\'s own bytes');
		$this->same($this->expandedDirectory(), $settings->directory,
			'the default directory is the daemon\'s bytes, ~ expanded');
		$this->same(self::SESSION, $settings->session, 'the session path is the daemon\'s bytes');
		$this->same(self::SERVER, $settings->server, 'the session name is the daemon\'s bytes');
		$cached = $this->cached();
		$this->same($this->expandedDirectory(), $cached->directory, 'the cache keeps the same bytes');
		$this->same(self::SESSION, $cached->session, 'the cached session path is the same');
	}

	public function testARelativeDirectoryResolvesUnderTheRawDefault()
	{
		$this->startDaemon();
		$settings = rTorrentSettings::get(true);
		$directory = 'sub "s"';
		$this->assertTrue($settings->correctDirectory($directory), 'a relative directory is allowed');
		$this->same($this->expandedDirectory() . '/sub "s"', $directory,
			'it resolves under the directory the daemon reported');
	}

	/** Serialize $settings as a cache written before the value format existed. */
	private function storeWithoutValueFormat($settings)
	{
		$serialized = serialize($settings);
		$member = '/s:11:"valueFormat";i:-?[0-9]+;/';
		if (preg_match($member, $serialized) === 1) {
			$serialized = preg_replace($member, '', $serialized, 1);
			$serialized = preg_replace_callback('/^O:16:"rTorrentSettings":([0-9]+):/', function ($m) {
				return 'O:16:"rTorrentSettings":' . ((int)$m[1] - 1) . ':';
			}, $serialized, 1);
		}
		$this->assertTrue(strpos($serialized, 'valueFormat') === false,
			'the stale fixture carries no value format, like a cache from before the change');
		file_put_contents(FileUtil::getSettingsPath() . '/rtorrent.dat', $serialized);
	}

	public function testACacheFromBeforeTheChangeIsReadOnceMore()
	{
		$stale = (new ReflectionClass('rTorrentSettings'))->newInstanceWithoutConstructor();
		$stale->linkExist = true;
		$stale->version = '0.16.25';
		$stale->iVersion = 0x1019;
		$stale->directory = '/old/dl \\"q\\" \\\\b';
		$stale->session = '/srv/se\\"ss\\\\ion/';
		$stale->plugins = array('throttle' => array('perm' => 1));
		$stale->hooks = array('LabelChanged' => array(array('name' => 'extratio', 'level' => 10)));
		$this->storeWithoutValueFormat($stale);

		$this->startDaemon();
		$settings = rTorrentSettings::get();
		$this->same($this->expandedDirectory(), $settings->directory,
			'an escaped cache is not used: the directory is read from the daemon again');
		$this->same(self::SESSION, $settings->session, 'and so is the session path');
		$this->assertTrue($this->daemon->received('system.client_version'),
			'the daemon was asked once more');
		$this->assertTrue($settings->isPluginRegistered('throttle'),
			'what a plugin registered in the old cache is kept');
		$this->same(array('LabelChanged' => array(array('name' => 'extratio', 'level' => 10))),
			$settings->hooks, 'and so are the event hooks');
		$cached = $this->cached();
		$this->same($this->expandedDirectory(), $cached->directory, 'the cache is rewritten in the daemon\'s bytes');
		$this->assertTrue($cached->isPluginRegistered('throttle'), 'with the registrations');

		// The rewritten cache is used as it is: no second read.
		$this->stopDaemon();
		$this->startDaemon();
		$this->resetSingleton();
		$again = rTorrentSettings::get();
		$this->same($this->expandedDirectory(), $again->directory, 'the next request reads the rewritten cache');
		$this->same(array(), $this->daemon->calls(), 'without asking the daemon');
	}

	public function testACurrentCacheIsUsedWithoutAskingTheDaemon()
	{
		$this->startDaemon();
		rTorrentSettings::get(true);
		$this->startDaemon();
		$this->resetSingleton();
		$settings = rTorrentSettings::get();
		$this->same(self::SESSION, $settings->session, 'the cached session path is used');
		$this->same(array(), $this->daemon->calls(), 'a cache in the current format costs no daemon read');
	}

	/** The literal assigned to $key in the systemInfo statement, decoded, or a marker. */
	private function scriptLiteral($script, $key)
	{
		if (preg_match('/[{,] ' . $key . ' : ("(?:[^"\\\\]|\\\\.)*")[,}\s]/', $script, $match) !== 1) {
			return 'NOT-ONE-LITERAL';
		}
		$value = json_decode($match[1], true);
		return is_string($value) ? $value : 'NOT-ONE-LITERAL';
	}

	// getplugins.php hands the WebUI the two daemon versions inside a script.
	// Quoted by hand in single quotes, a raw quote or backslash would end the
	// literal and with it the whole bootstrap script.
	public function testTheVersionsReachTheWebUIAsOneLiteralEach()
	{
		$this->startDaemon();
		$settings = rTorrentSettings::get(true);
		$this->assertTrue(method_exists($settings, 'systemInfoScript'),
			'the systemInfo statement is built in one place');
		$script = method_exists($settings, 'systemInfoScript') ? $settings->systemInfoScript() : '';
		$this->same(self::LIBRARY, $this->scriptLiteral($script, 'libVersion'),
			'the library version decodes back to the daemon\'s bytes');
		$this->same('0.16.25', $this->scriptLiteral($script, 'version'), 'and so does the client version');
		$this->assertTrue(stripos($script, '</script') === false && strpos($script, "'") === false,
			'no byte of it can close the script or a single-quoted literal: ' . $script);
	}
}
