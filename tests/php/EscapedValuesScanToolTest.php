<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/FakeRtorrentDaemon.php');

/**
 * Escaping candidates are an inventory, not proof of corruption. Legitimate
 * names and unrelated directories remain ambiguous; no automatic repair is
 * possible. Warm and cold bootstrap both read the daemon without mutation.
 */
class EscapedValuesScanToolTest extends TestCase
{
	const A = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
	const B = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';
	const C = 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';
	const D = 'DDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD';
	const E = 'EEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEE';
	const F = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF';

	private $base;
	private $daemon;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-escaped-scan-' . getmypid();
		$this->removeTree($this->base);
		mkdir($this->base . '/profile/settings', 0700, true);
		mkdir($this->base . '/dl', 0700, true);
	}

	public function tearDown()
	{
		$this->stopDaemon();
		$this->removeTree($this->base);
	}

	// Every daemon is stopped when it is replaced and at tearDown: one left
	// running keeps its worker process until the 10 s accept timeout
	// (R5-REV-07).
	private function stopDaemon()
	{
		if ($this->daemon !== null) {
			$this->daemon->stop();
			$this->daemon = null;
		}
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

	private static function tool()
	{
		return realpath(__DIR__ . '/../..') . '/tools/escaped-values-scan.php';
	}

	private function same($expected, $actual, $what)
	{
		$this->assertSame($expected, $actual, $what
			. ($expected === $actual ? '' : ': got ' . var_export($actual, true)));
	}

	/**
	 * array(exit, stdout, stderr) of the tool run against a daemon holding $rows.
	 *
	 * Without $version the settings are seeded in the child, as on an instance
	 * whose cache is warm, so the tool's own d.multicall is the only call the
	 * daemon sees. With a version the child uses the profile's settings cache
	 * as production does, and the daemon answers ruTorrent's settings refresh
	 * as a daemon of that version.
	 */
	private function scan(array $rows, array $args = array(), $answer = null, $version = null)
	{
		$root = realpath(__DIR__ . '/../..');
		$flat = array();
		foreach ($rows as $row) {
			$flat = array_merge($flat, $row);
		}
		$reply = $answer === null ? $flat : $answer;
		$replies = array('d.multicall' => $reply, 'd.multicall2' => $reply);
		if ($version !== null) {
			$replies = array_merge($this->startupReplies($version), $replies);
		}
		$this->stopDaemon();
		$this->daemon = new FakeRtorrentDaemon(new FakeRtorrentMethodReplies($replies),
			$this->base . '/calls.log');
		$driver = $this->base . '/drive-scan.php';
		$seed = $version !== null ? '' : 'require_once(' . var_export($root . '/php/settings.php', true) . ");\n"
			. '$r = new ReflectionClass("rTorrentSettings"); $s = $r->newInstanceWithoutConstructor();'
			. ' $s->iVersion = 0x1019; $s->aliases = array(); $s->linkExist = true;'
			. ' $p = $r->getProperty("theSettings");'
			. ' if(PHP_VERSION_ID < 80100) $p->setAccessible(true); $p->setValue(null, $s);' . "\n";
		file_put_contents($driver, "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->base . '/profile', true) . ";\n"
			. 'require_once(' . var_export($root . '/conf/config.php', true) . ");\n"
			. $seed
			. '$scgi_host = "127.0.0.1";' . "\n"
			. '$scgi_port = ' . $this->daemon->port() . ";\n"
			. '$rpcTimeOut = 10;' . "\n"
			. '$log_file = ' . var_export($this->base . '/app.log', true) . ";\n"
			. '$argv = ' . var_export(array_merge(array(self::tool()), $args), true) . ";\n"
			. 'require(' . var_export(self::tool(), true) . ");\n");
		$process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', $driver),
			array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		fclose($pipes[0]);
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return array(proc_close($process), $out, $err);
	}

	/**
	 * What a daemon of $version answers to rTorrentSettings::obtain(), under
	 * both spellings of each startup read: the child loads the alias tables
	 * only after its first answer.
	 */
	private function startupReplies($version)
	{
		$dl = $this->base . '/dl';
		$session = $this->base . '/session/';
		$capture = array("1000\n1000\n" . $this->base . "/home\n");
		return array(
			'system.client_version' => array($version),
			'system.api_version' => array(version_compare($version, '0.16.0', '>=') ? 11 : 10),
			'system.sockets.available_alloc' => array(4096),
			'directory.default' => array($dl), 'get_directory' => array($dl),
			'session.path' => array($session), 'get_session' => array($session),
			'system.library_version' => array($version),
			'session.name' => array('scan'), 'get_name' => array('scan'),
			'network.port_range' => array('6881-6881'), 'get_port_range' => array('6881-6881'),
			'network.listen.port.range' => array('6881-6881'),
			'network.bind_address' => array('0.0.0.0'), 'get_bind' => array('0.0.0.0'),
			'network.local_address' => array('0.0.0.0'), 'get_ip' => array('0.0.0.0'),
			'network.listen.port' => array(6881),
			'execute.capture' => $capture, 'execute_capture' => $capture,
		);
	}

	private function settingsCache()
	{
		return $this->base . '/profile/settings/rtorrent.dat';
	}

	/** The request body the daemon received for $method, or ''. */
	private function bodyOf($method)
	{
		foreach ($this->daemon->bodies() as $body) {
			if (strpos($body, '<methodName>' . $method . '</methodName>') !== false) {
				return $body;
			}
		}
		return '';
	}

	// R5-REV-09: right after the round-5 upgrade the settings cache is in the
	// old format (or missing), and the tool's first run then also refreshes
	// ruTorrent's settings: these are the requests, in order, and the one
	// daemon setting among them. The next run reads only the downloads.
	public function testACold016CacheUsesOnlyReadsAndIsNotRewritten()
	{
		mkdir($this->base . '/dl/plain');
		$rows = array(array(self::A, 'plain', 'TV', $this->base . '/dl/plain', $this->base . '/dl/plain/f'));
		$refresh = array('system.client_version', 'system.api_version',
			'system.sockets.available_alloc', 'system.sockets.internal.max_size',
			'system.sockets.rpc.max_size', 'system.sockets.http.max_alloc.limit',
			'system.sockets.files.max_alloc.limit', 'system.sockets.files.min_alloc.limit',
			'convert.kb',
			'directory.default', 'session.path', 'system.library_version', 'session.name',
			'network.listen.port.range', 'network.bind_address', 'network.local_address',
			'network.listen.port');
		$this->assertTrue(!file_exists($this->settingsCache()), 'the profile starts without a settings cache');

		list($exit, $out, $err) = $this->scan($rows, array(), null, '0.16.25');
		$this->same(0, $exit, 'the cold run scans: ' . $err);
		$this->same("escaped-values-scan: 1 downloads read; 0 ambiguous candidates on 0 downloads; 0 empty neighboring directories; no corruption or duplicate payload proven; nothing was changed\n",
			$out, 'and reports the scan');
		$this->same(array_merge($refresh, array('d.multicall')), $this->daemon->calls(),
			'a cold cache: the settings refresh, then the scan\'s one read');
		$this->same('', $this->bodyOf('network.xmlrpc.size_limit.set'), 'a cold scanner never sends a daemon setter');
		$this->same('', $this->bodyOf('execute.capture'), 'a cold scanner never executes a helper');
		$this->assertTrue(!file_exists($this->settingsCache()), 'read-only bootstrap never stores a settings cache');
		$this->scan($rows, array(), null, '0.16.25');
		$this->same(array_merge($refresh, array('d.multicall')), $this->daemon->calls(),
			'a repeated cold scan still uses read-only bootstrap');
	}

	// The legacy daemon has no socket limits: its refresh skips those reads.
	public function testAColdLegacyCacheUsesReadOnlyBootstrapWithoutSocketReads()
	{
		mkdir($this->base . '/dl/plain');
		list($exit, , $err) = $this->scan(array(
			array(self::A, 'plain', 'TV', $this->base . '/dl/plain', $this->base . '/dl/plain/f')),
			array(), null, '0.9.8');
		$this->same(0, $exit, 'the cold run scans: ' . $err);
		$this->same(array('system.client_version', 'system.api_version', 'convert.kb',
			'directory.default', 'session.path',
			'system.library_version', 'session.name', 'network.port_range', 'network.bind_address',
			'network.local_address', 'network.listen.port', 'd.multicall2'),
			$this->daemon->calls(), 'a cold cache on 0.9.8: the settings refresh, then the scan\'s one read');
	}

	public function testItListsEachKindOfDamageAndOnlyThat()
	{
		$dl = $this->base . '/dl';
		mkdir($dl . '/aud "q" \\b');                 // B: the real directory
		mkdir($dl . '/succ "s"');                    // C: the real directory ...
		mkdir($dl . '/succ \\"s\\"');                // ... and the one a replacement created
		mkdir($dl . '/clean "ok" \\z');              // D: a raw name, no escaping
		mkdir($dl . '/twice \\\\\\"t\\\\\\"');       // E: only the stored, doubly escaped name
		mkdir($dl . '/twice "t"');                   //    ... whose two-level origin also exists
		$rows = array(
			array(self::A, 'Rock n Roll', 'Rock%20%5C%22n%5C%22%20Roll', $dl . '/a', $dl . '/a/x'),
			array(self::B, 'aud', '', $dl . '/aud \\"q\\" \\\\b', ''),
			array(self::C, 'succ', '', $dl . '/succ \\"s\\"', $dl . '/succ \\"s\\"'),
			array(self::D, 'clean', 'Movies', $dl . '/clean "ok" \\z', $dl . '/clean "ok" \\z/f'),
			array(self::E, 'twice', '', $dl . '/twice \\\\\\"t\\\\\\"', ''),
			array(self::F, "new\nline", '', $dl . '/gone \\"g\\"', ''),
		);
		list($exit, $out, $err) = $this->scan($rows, array('--fail-on-candidates'));
		$this->same(1, $exit, 'findings exit 1: ' . $err);
		$lines = explode("\n", rtrim($out, "\n"));
		$this->same(array(
			self::A . "\tclass=label-escaped\tfield=label\tlevels=1\tvalue=Rock \\\"n\\\" Roll\tunescaped=Rock \"n\" Roll\tname=Rock n Roll",
			self::B . "\tclass=escaped-path-missing\tfield=directory\tlevels=1\tvalue=" . $dl . '/aud \\"q\\" \\\\b'
				. "\tunescaped=" . $dl . "/aud \"q\" \\b\tname=aud",
			self::C . "\tclass=escaped-path-both-exist\tfield=directory\tlevels=1\tvalue=" . $dl . '/succ \\"s\\"'
				. "\tunescaped=" . $dl . "/succ \"s\"\tname=succ",
			self::E . "\tclass=escaped-path-both-exist\tfield=directory\tlevels=2\tvalue=" . $dl . '/twice \\\\\\"t\\\\\\"'
				. "\tunescaped=" . $dl . "/twice \"t\"\tname=twice",
			self::F . "\tclass=escaped-path-unresolved\tfield=directory\tlevels=1\tvalue=" . $dl . '/gone \\"g\\"'
				. "\tunescaped=" . $dl . "/gone \"g\"\tname=new%0Aline",
			'escaped-values-scan: 6 downloads read; 5 ambiguous candidates on 5 downloads; 0 empty neighboring directories; no corruption or duplicate payload proven; nothing was changed',
		), $lines, 'one classified line per finding, then the summary');
		$this->same(array('d.multicall'), array_values(array_unique($this->daemon->calls())),
			'the daemon is asked for the download list and nothing else');
		$this->assertTrue(is_dir($dl . '/succ \\"s\\"') && is_dir($dl . '/aud "q" \\b'),
			'nothing on disk is touched');
	}

	public function testACleanSessionExitsZero()
	{
		mkdir($this->base . '/dl/plain');
		list($exit, $out) = $this->scan(array(
			array(self::A, 'plain', 'TV', $this->base . '/dl/plain', $this->base . '/dl/plain/f')));
		$this->same(0, $exit, 'nothing found exits 0');
		$this->same("escaped-values-scan: 1 downloads read; 0 ambiguous candidates on 0 downloads; 0 empty neighboring directories; no corruption or duplicate payload proven; nothing was changed\n",
			$out, 'and says so');
	}

	public function testAnUnreadableAnswerIsRefusedVisibly()
	{
		list($exit, $out, $err) = $this->scan(array(), array(), array(self::A, 'x', ''));
		$this->same(2, $exit, 'a malformed list exits 2');
		$this->assertTrue(strpos($err, 'malformed; nothing was checked') !== false, 'and names why: ' . $err);
		list($exit, , $err) = $this->scan(array(), array('--apply'));
		$this->same(2, $exit, 'any argument but --user is a usage error: the tool has no write mode');
		$this->assertTrue(strpos($err, 'usage:') !== false, 'with the usage line');
	}

	public function testAnInvalidHashIsNotAReadableDownloadList()
	{
		list($exit, $out, $err) = $this->scan(array(), array(), array('not-a-hash', 'x', '', '/d', ''));
		$this->same(2, $exit, 'an invalid hash is refused as a malformed list');
		$this->same('', $out, 'a refused list emits no candidates');
	}

	public function testTheClassifierWalksEveryEscapingLevel()
	{
		define('ESCAPED_VALUES_SCAN_IMPORT_ONLY', true);
		$_ENV['RU_PROFILE_PATH'] = $this->base . '/profile';
		require_once(self::tool());
		$exists = function ($path) {
			return in_array($path, array('/d/x "y"', '/d/w \\"v\\"'), true);
		};
		$three = '/d/x \\\\\\\\\\\\\\"y\\\\\\\\\\\\\\"';
		$this->same(array(array('escaped-path-missing', 'directory', 3, $three, '/d/x "y"')),
			EscapedValuesScan::classify('', $three, '', $exists),
			'a value escaped by three replacements (1, 3, 7 backslashes) is traced back three levels');
		$this->same(array(array('escaped-path-legitimate-or-escaped', 'directory', 1, '/d/w \\"v\\"', '/d/w "v"')),
			EscapedValuesScan::classify('', '/d/w \\"v\\"', '', $exists),
			'a stored name that exists alone is reported, since a real name may hold \\"');
		$this->same(array(), EscapedValuesScan::classify('Movies%20%22HD%22', '/d/x "y"', '/d/x "y"/f', $exists),
			'raw quotes and backslash-free values are not damage');
		$this->same('a%25b%09c "d" \\e', EscapedValuesScan::printable("a%b\tc \"d\" \\e"),
			'printing encodes only percent and control bytes');
	}
	public function testLegitimateEscapedNamesAndEmptyNeighborsAreOnlyCandidates()
	{
		$directory = $this->base.'/dl/legal \\"name\\"';
		mkdir($directory);
		$neighbor = $this->base.'/dl/old \\"empty\\"';
		mkdir($neighbor);
		list($exit, $out) = $this->scan(array(array(self::A, 'legal', '', $directory, '')));
		$this->same(0, $exit, 'ambiguous legitimate names are not an error by default');
		$this->assertTrue(strpos($out, 'class=escaped-path-legitimate-or-escaped') !== false,
			'the stored-only path is explicitly ambiguous');
		$this->assertTrue(strpos($out, 'class=escaped-empty-directory') !== false && strpos($out, $neighbor) !== false,
			'an unreferenced empty neighboring directory is listed');
		$this->assertTrue(is_dir($directory) && is_dir($neighbor), 'the scan never repairs a candidate');
	}

	public function testAnEntirelyAbsentSettingsDirectoryIsNotCreatedByAReadOnlyScan()
	{
		rmdir($this->base.'/profile/settings');
		list($exit, $out, $err) = $this->scan(array(), array(), null, '0.16.25');
		$this->same(0, $exit, 'a cold scan with no settings directory still completes: '.$err);
		$this->assertTrue(!is_dir($this->base.'/profile/settings'), 'read-only cache lookup never creates the settings directory');
	}

	public function testActualColdScannerCreatesNeitherSharedNorPerUserProfile()
	{
		foreach(array(array(), array('--user=Cold Inspector')) as $args)
		{
			$this->removeTree($this->base.'/profile');
			list($exit, $out, $err) = $this->scan(array(), $args, null, '0.16.25');
			$this->same(0, $exit, 'a cold actual CLI scan completes without a precreated profile: '.$err);
			$this->assertTrue(!file_exists($this->base.'/profile'), 'all shared/per-user profile creation remains suppressed');
		}
	}

}
