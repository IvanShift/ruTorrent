<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/PermissiveDirectorySettings.php');
require_once(__DIR__ . '/RtorrentCommandParser.php');
require_once(__DIR__ . '/../../php/rtorrent.php');
require_once(__DIR__ . '/FakeRtorrentDaemon.php');

class RtorrentRawStringCaptured extends Exception
{
}

/** Captures the commands a load built, after the real parser read the directory. */
class RtorrentRawStringCaptureSettings extends rTorrentSettings
{
	use PermissiveDirectorySettings;

	public $captured = array();

	public function patchDeprecatedRequest($commands)
	{
		$this->captured = array_merge($this->captured, $commands);
		// Directory creation is now confirmed in its own request before a new load.
		foreach($commands as $command)
			if(strpos($command->command, 'load') === 0)
				throw new RtorrentRawStringCaptured();
	}
}

/**
 * The core helpers that take a string the daemon reported and use it as a path
 * or put it back into a command (AUD-ESC-02, -06, -13), driven through the
 * real php/xmlrpc.php parser and a scripted SCGI peer.
 *
 * While run() escaped every '"' and '\', getFilePath() answered a path that did
 * not exist for any file whose name carried one, getSourcePath() missed a
 * session directory carrying one, and a directory read back from the daemon
 * reached quoteCommandArg() already escaped, so rtorrent was told to put a
 * download into a directory whose name had grown a backslash -- which the
 * typed `mkdir -p` beside it then created.
 */
class RtorrentRawStringConsumersTest extends TestCase
{
	const HASH = '0123456789ABCDEF0123456789ABCDEF01234567';
	// A backslash before a quote and a doubled backslash are the shapes that
	// an extra unescape (or escape) would change.
	const NAME = 'aud "q" \\"x\\" \\\\ \'a\' &c';

	private $previousSettings;
	private $base;
	private $daemon;

	public function setUp()
	{
		$this->useSettings(new ReflectionClass('rTorrentSettings'));
		$this->base = sys_get_temp_dir() . '/rutorrent-raw-consumers-' . getmypid();
		$this->removeTree($this->base);
		mkdir($this->base, 0700, true);
		$GLOBALS['scgi_host'] = '127.0.0.1';
		$GLOBALS['rpcTimeOut'] = 10;
		$GLOBALS['rpcLogCalls'] = false;
		$GLOBALS['rpcLogFaults'] = false;
		$GLOBALS['log_file'] = $this->base . '/app.log';
		$GLOBALS['topDirectory'] = '/';
	}

	public function tearDown()
	{
		$property = new ReflectionProperty('rTorrentSettings', 'theSettings');
		self::makeAccessible($property);
		$property->setValue(null, $this->previousSettings);
		$this->previousSettings = null;
		if ($this->daemon !== null) {
			$this->daemon->stop();
			$this->daemon = null;
		}
		$this->removeTree($this->base);
	}

	private function useSettings(ReflectionClass $class)
	{
		$settings = $class->newInstanceWithoutConstructor();
		$settings->iVersion = 0x904;
		$settings->apiVersion = 0;
		$settings->aliases = array();
		$property = new ReflectionProperty('rTorrentSettings', 'theSettings');
		self::makeAccessible($property);
		if ($this->previousSettings === null) {
			$this->previousSettings = $property->getValue();
		}
		$property->setValue(null, $settings);
		return $settings;
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

	private function daemon(array $replies)
	{
		if ($this->daemon !== null) {
			$this->daemon->stop();
		}
		$this->daemon = new FakeRtorrentDaemon(new FakeRtorrentMethodReplies($replies),
			$this->base . '/calls.log');
		$GLOBALS['scgi_port'] = $this->daemon->port();
	}

	private function same($expected, $actual, $what)
	{
		$this->assertSame($expected, $actual, $what
			. ($expected === $actual ? '' : ': got ' . var_export($actual, true)));
	}

	private function minimalTorrent()
	{
		$info = 'd6:lengthi1e4:name9:test.data12:piece lengthi16384e6:pieces20:'
			. str_repeat("\x01", 20) . 'e';
		return 'd8:announce28:http://example.test/announce4:info' . $info . 'e';
	}

	// AUD-ESC-06: mediainfo, screenshots and spectrogram open what this returns.
	public function testGetFilePathAnswersThePathTheFileHas()
	{
		$dir = $this->base . '/' . self::NAME;
		mkdir($dir);
		$file = $dir . '/file \\"y\\".bin';
		file_put_contents($file, 'data');
		$this->daemon(array('f.get_frozen_path' => array($file)));
		$path = rTorrent::getFilePath(self::HASH, 0);
		$this->same($file, $path, 'getFilePath() answers the frozen path in its own bytes');
		$this->assertTrue(is_string($path) && is_file($path), 'and that path is the file');
	}

	public function testGetFilePathFallbackAnswersThePathTheFileHas()
	{
		$file = $this->base . '/' . self::NAME . '.bin';
		file_put_contents($file, 'data');
		$this->daemon(array('f.get_frozen_path' => new FakeRtorrentPerCall(array(array(''), array($file)))));
		$this->same($file, rTorrent::getFilePath(self::HASH, 0),
			'the open/read/close fallback answers the same bytes');
	}

	// AUD-ESC-13: source and dump read the session copy or the tied file.
	public function testGetSourcePathFindsTheSessionCopyUnderARawSessionPath()
	{
		$session = $this->base . '/session \\"s\\" \\\\x/';
		mkdir($session);
		file_put_contents($session . self::HASH . '.torrent', $this->minimalTorrent());
		$this->daemon(array('get_session' => array($session),
			'd.get_tied_to_file' => array('')));
		$this->same($session . self::HASH . '.torrent', rTorrent::getSourcePath(self::HASH),
			'the session copy is found under a session path carrying a quote and a backslash');
	}

	public function testGetSourcePathFallsBackToARawTiedFile()
	{
		$tied = $this->base . '/' . self::NAME . '.torrent';
		file_put_contents($tied, $this->minimalTorrent());
		$this->daemon(array('get_session' => array($this->base . '/no-session/'),
			'd.get_tied_to_file' => array($tied)));
		$this->same($tied, rTorrent::getSourcePath(self::HASH),
			'the tied file is found by the name the daemon reported');
	}

	/**
	 * AUD-ESC-02: a directory read back from the daemon and handed to a load is
	 * quoted once, so rtorrent's own parser (src/rpc/parse.cc, transcribed in
	 * RtorrentCommandParser) reads back exactly the bytes it reported, and
	 * the typed mkdir argument names the same directory.
	 */
	public function testADirectoryReadFromTheDaemonIsQuotedExactlyOnce()
	{
		$directory = $this->base . '/' . self::NAME;
		$this->daemon(array('d.get_directory_base' => array($directory)));
		$read = new rXMLRPCRequest(new rXMLRPCCommand('d.get_directory_base', self::HASH));
		$this->assertTrue($read->success(), 'the directory is read from the daemon');
		$this->same($directory, $read->val[0], 'the reader is handed the daemon bytes');

		foreach (array(false, true) as $magnet) {
			$settings = $this->useSettings(new ReflectionClass('RtorrentRawStringCaptureSettings'));
			try {
				if ($magnet) {
					rTorrent::sendMagnet('magnet:?xt=urn:btih:' . self::HASH, true, false,
						$read->val[0], null);
				} else {
					$torrent = $this->base . '/load.torrent';
					file_put_contents($torrent, $this->minimalTorrent());
					rTorrent::sendTorrent($torrent, true, false, $read->val[0], null, true, false);
				}
			} catch (RtorrentRawStringCaptured $stop) {
				// Expected: mkdir was answered by the peer; stop before dispatching the load.
			}
			$what = $magnet ? 'sendMagnet()' : 'sendTorrent()';
			$set = null;
			$mkdir = null;
			foreach ($settings->captured as $command) {
				$values = array_map(function ($param) {
					return htmlspecialchars_decode((string)$param->value, ENT_NOQUOTES);
				}, $command->params);
				if ($command->command === 'execute') {
					$mkdir = end($values);
				}
				foreach ($values as $value) {
					if (strpos($value, 'd.set_directory_base=') === 0) {
						$set = $value;
					}
				}
			}
			$this->same($directory, $mkdir, $what . ' asks mkdir for the directory the daemon reported');
			$this->assertTrue($set !== null, $what . ' sets the base directory');
			$parsed = $set === null ? array() : RtorrentCommandParser::commands($set);
			$this->same(array(array('name' => 'd.set_directory_base', 'args' => array($directory))),
				$parsed, $what . ' hands rtorrent a command it parses back into those bytes');
		}
	}
}
