<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');

/**
 * datadir's "move files" through the real php/xmlrpc.php parser (AUD-ESC-08).
 *
 * A child PHP loads conf/config.php, php/settings.php and php/xmlrpc.php and
 * runs what plugins/datadir/setdir.php runs -- rtDataDirRequestRefusal(),
 * rtDataDirClaimRefusal(), rtDataDirApplyLocked() -- against a
 * FakeRtorrentDaemon that answers the native claim ABI by method name. Every
 * name, base path and file path rtDataDirSnapshot() reads therefore arrives
 * through the parser, which until 2026-10 put a backslash before every '"'
 * and '\' it read: the move then looked for the payload under that spelling,
 * refused, or took a neighbour that carried it (probe11, round 4).
 *
 * util_setdir.php is evaluated with one seam changed: rtDataDirFilesystem()
 * returns the shared ErasedataFilesystemOps runner pointed at a scripted
 * rename helper, because the native helper is an installation artifact the
 * bare host lacks. The runner, its status table and the parent preflight
 * are the shipped ones; the child refuses to run if the seam moved.
 */
class DataDirRealParserMoveTest extends TestCase
{
	const HASH = '0123456789ABCDEF0123456789ABCDEF01234567';
	const LOCAL_ID = 'fedcba9876543210fedcba9876543210';
	const TOKEN = '0123456789abcdef0123456789abcdef:0000000000000001';

	private $base;
	private $daemon = null;
	private $umask;

	public function setUp()
	{
		// The payload trust rule refuses a group-writable file.
		$this->umask = umask(0022);
		$parent = realpath(getenv('TMPDIR') ?: sys_get_temp_dir());
		$this->base = $parent . '/datadir-parser-' . getmypid() . '-' . bin2hex(random_bytes(4));
		// rtDataDirJournalRoot() keeps the journal in the profile's own
		// settings directory, which must exist already.
		foreach (array('', '/profile', '/profile/settings', '/data') as $dir)
			if (!mkdir($this->base . $dir, 0700))
				throw new RuntimeException('fixture mkdir failed');
	}

	public function tearDown()
	{
		if ($this->daemon !== null)
			$this->daemon->stop();
		$this->daemon = null;
		$this->remove($this->base);
		umask($this->umask);
	}

	private function remove($path)
	{
		if (is_dir($path) && !is_link($path)) {
			foreach (array_diff(scandir($path), array('.', '..')) as $entry)
				$this->remove($path . '/' . $entry);
			@rmdir($path);
		} else @unlink($path);
	}

	private function repoRoot()
	{
		return realpath(__DIR__ . '/../../..');
	}

	private function tree($path, $prefix = '')
	{
		$entries = array();
		foreach (array_diff(scandir($path), array('.', '..')) as $name) {
			$entries[] = $prefix . $name;
			if (is_dir($path . '/' . $name) && !is_link($path . '/' . $name))
				$entries = array_merge($entries, $this->tree($path . '/' . $name, $prefix . $name . '/'));
		}
		sort($entries, SORT_STRING);
		return $entries;
	}

	// The old parser's spelling of a daemon string.
	private static function escaped($value)
	{
		return str_replace(array('\\', '"'), array('\\\\', '\\"'), $value);
	}

	private function write($path, $bytes)
	{
		if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true))
			throw new RuntimeException('fixture mkdir failed');
		if (file_put_contents($path, $bytes) !== strlen($bytes))
			throw new RuntimeException('fixture write failed');
	}

	/**
	 * The native claim ABI and the reads rtSetDataDir() makes, answered by
	 * method name. d.stop_close_claim_state answers $states in order; the
	 * receipt restores a started, open and active download.
	 */
	private function replies($directory, $name, $basePath, $multi, $files, $states)
	{
		$receipt = self::TOKEN . '|1|1|1|' . self::LOCAL_ID;
		$claim = array('d.stop_close_claim_state', 'd.stop_close_claim',
			'd.directory.set_if_stop_close_claim', 'd.directory.base.set_if_stop_close_claim',
			'd.start_if_stop_close_claim', 'd.open_if_stop_close_claim', 'd.release_stop_close_claim',
			'd.replay_stop_close_claim', 'd.ack_stop_close_claim');
		return new FakeRtorrentMethodReplies(array(
			'system.listMethods' => array_merge(array('system.listMethods'), $claim),
			'd.get_custom1' => array(''),
			'd.get_custom' => array(''),
			'd.stop_close_claim_state' => new FakeRtorrentPerCall(array_map(function ($state) use ($receipt) {
				return array($state === 'absent' ? 'absent' : $state . '|S|' . $receipt);
			}, $states)),
			'd.stop_close_claim' => array($receipt),
			'd.is_open' => array(1),
			'd.local_id' => array(self::LOCAL_ID),
			'd.directory' => array($directory),
			'd.get_name' => array($name),
			'd.get_base_path' => array($basePath),
			'd.get_base_filename' => array($name),
			'd.is_multi_file' => array($multi ? 1 : 0),
			'f.multicall' => $files,
			'd.directory.set_if_stop_close_claim' => array(1),
			'd.directory.base.set_if_stop_close_claim' => array(1),
			'd.start_if_stop_close_claim' => array(1),
			'd.ack_stop_close_claim' => array(1),
			'd.hashing' => array(0),
			'd.state' => array(1),
		));
	}

	// Runs $body after the real core and the seamed util_setdir.php are
	// loaded, from the datadir plugin directory as setdir.php runs, and
	// returns what it printed as JSON.
	private function drive($replies, $body)
	{
		if ($this->daemon !== null)
			$this->daemon->stop();
		$this->daemon = new FakeRtorrentDaemon($replies, $this->base . '/calls-' . uniqid() . '.log');
		$helper = $this->base . '/rename-helper';
		file_put_contents($helper, "#!/bin/sh\n[ \"\$1\" = --probe ] && exit 0\n"
			. "{ [ -e \"\$2\" ] || [ -L \"\$2\" ]; } && exit 1\nexec mv -T -- \"\$1\" \"\$2\"\n");
		chmod($helper, 0700);
		$root = $this->repoRoot();
		$plugin = $root . '/plugins/datadir';
		$driver = $this->base . '/drive-' . uniqid() . '.php';
		file_put_contents($driver, "<?php\n"
			. 'umask(0022); $_SERVER[\'REMOTE_USER\'] = "tester";'
			. ' $_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->base . '/profile', true) . ';'
			. ' $_ENV[\'RU_LOG_FILE\'] = ' . var_export($this->base . '/errors.log', true) . ";\n"
			. 'chdir(' . var_export($plugin, true) . ");\n"
			. 'require_once(' . var_export($root . '/conf/config.php', true) . ");\n"
			. 'require_once(' . var_export($root . '/php/settings.php', true) . ");\n"
			. '$r = new ReflectionClass("rTorrentSettings"); $s = $r->newInstanceWithoutConstructor();'
			. ' $s->iVersion = 0x1018; $s->aliases = array(); $s->directory = "/"; $s->linkExist = true;'
			. ' $p = $r->getProperty("theSettings"); $p->setAccessible(true); $p->setValue(null, $s);' . "\n"
			. '$scgi_host = "127.0.0.1"; $scgi_port = ' . $this->daemon->port() . ';'
				. ' $rpcTimeOut = 10; $rpcLogCalls = false;' . "\n"
			. 'require_once(' . var_export($root . '/php/xmlrpc.php', true) . ");\n"
			. 'require_once(' . var_export($root . '/plugins/erasedata/filesystem.php', true) . ");\n"
			. 'class DataDirParserScriptedRename extends ErasedataFilesystemOps'
				. ' { public static function renameHelperPath() { return ' . var_export($helper, true) . '; } }' . "\n"
			. 'function rtDataDirFilesystem() { static $files = null;'
				. ' if ($files === null) $files = new DataDirParserScriptedRename(); return $files; }' . "\n"
			. '$source = file_get_contents(' . var_export($plugin . '/util_setdir.php', true) . ');' . "\n"
			. '$source = str_replace("function rtDataDirFilesystem()", "function rtDataDirFilesystemShipped()",'
				. ' $source, $seams);' . "\n"
			. '$source = str_replace("__DIR__", ' . var_export(var_export($plugin, true), true) . ', $source);' . "\n"
			. 'if ($seams !== 1 || strpos($source, "<?php") !== 0) { echo json_encode(array("error" => "seam-moved")); exit; }' . "\n"
			. 'eval(substr($source, 5));' . "\n"
			. $body);
		$out = array();
		exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 ' . escapeshellarg($driver) . ' 2>&1', $out);
		$printed = implode("\n", $out);
		$answer = json_decode($printed, true);
		if (!is_array($answer))
			throw new Exception('the driver printed: ' . $printed);
		return $answer;
	}

	// What setdir.php does with its arguments, for a payload move.
	private function worker($destination)
	{
		return '$hash = ' . var_export(self::HASH, true) . '; $datadir = ' . var_export($destination, true) . ";\n"
			. '$failure = null; $warning = null; $applied = false;' . "\n"
			. '$refusal = rtDataDirRequestRefusal($hash, $datadir);' . "\n"
			. 'if ($refusal === "") $refusal = rtDataDirClaimRefusal();' . "\n"
			. 'if ($refusal === "") $applied = rtDataDirApplyLocked($hash, $datadir, true, true, false, false,'
				. ' false, $failure, $warning);' . "\n"
			. 'echo json_encode(array("refusal" => $refusal, "applied" => $applied, "failure" => $failure,'
				. ' "warning" => $warning));';
	}

	private function logLines()
	{
		$log = @file($this->base . '/errors.log', FILE_IGNORE_NEW_LINES);
		return $log === false ? array() : array_map(function ($line) {
			return preg_replace('/^\[[^\]]*\] /', '', $line);
		}, $log);
	}

	// The string parameters of every request to $method, decoded.
	private function sentStrings($method)
	{
		$sent = array();
		foreach ($this->daemon->bodies() as $body) {
			$arguments = array();
			if (strpos($body, '<methodName>' . $method . '</methodName>') !== false)
				$arguments[] = $body;
			else {
				preg_match_all('~<name>methodName</name><value><string>' . preg_quote($method, '~')
					. '</string></value></member><member><name>params</name><value><array><data>'
					. '(.*?)</data></array></value></member>~s', $body, $calls);
				$arguments = $calls[1];
			}
			foreach ($arguments as $fragment) {
				preg_match_all('|<value><string>(.*?)</string></value>|s', $fragment, $match);
				$sent[] = array_map(function ($value) {
					return html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
				}, $match[1]);
			}
		}
		return $sent;
	}

	private function journal()
	{
		return $this->base . '/profile/settings/datadir-jobs/tester';
	}

	public function testMoveFilesOfAQuotedBackslashedMultiFileDownloadThroughTheRealParser()
	{
		$data = $this->base . '/data';
		$name = 'mv "q" \\z';
		$files = array('a "x".bin', 'c\\"d.bin', 'e\\\\f.bin', 'sub "s" \\t/h.bin');
		$source = $data . '/src/' . $name;
		$neighbour = $data . '/src/' . self::escaped($name);
		$destination = $data . '/dst "d" \\w';
		mkdir($destination, 0700);
		foreach ($files as $file) {
			$this->write($source . '/' . $file, 'payload ' . $file);
			// What the old reading named: the escaped spelling of every component.
			$this->write($neighbour . '/' . self::escaped($file), 'neighbour ' . $file);
		}
		$before = $this->tree($neighbour);
		$result = $this->drive($this->replies($source, $name, $source, true, $files,
			array('absent', 'absent', 'done')), $this->worker($destination));

		$this->assertSame(array('refusal' => '', 'applied' => true, 'failure' => null, 'warning' => ''), $result,
			'the worker applies the move: ' . json_encode($result));
		foreach ($files as $file)
			$this->assertSame('payload ' . $file, @file_get_contents($destination . '/' . $name . '/' . $file),
				'the payload file is at destination/<exact name>: ' . $file);
		$this->assertSame(array('sub "s" \\t'), $this->tree($source), 'no payload file is left at the source');
		$this->assertSame($before, $this->tree($neighbour), 'the escaped-spelling neighbour keeps its entries');
		foreach ($files as $file)
			$this->assertSame('neighbour ' . $file, @file_get_contents($neighbour . '/' . self::escaped($file)),
				'the neighbour keeps its bytes: ' . $file);
		$expected = array('dst "d" \\w', 'dst "d" \\w/' . $name, 'src', 'src/' . $name,
			'src/' . $name . '/sub "s" \\t', 'src/' . self::escaped($name));
		foreach ($files as $file) {
			$expected[] = 'dst "d" \\w/' . $name . '/' . $file;
			$expected[] = 'src/' . self::escaped($name) . '/' . self::escaped($file);
		}
		$expected[] = 'dst "d" \\w/' . $name . '/sub "s" \\t';
		$expected[] = 'src/' . self::escaped($name) . '/' . self::escaped('sub "s" \\t');
		sort($expected, SORT_STRING);
		$this->assertSame($expected, $this->tree($data),
			'nothing else exists, and no directory was created under an escaped spelling: '
			. json_encode($this->tree($data), JSON_UNESCAPED_SLASHES));
		$this->assertSame(array(array(self::HASH, self::TOKEN, $destination . '/')),
			$this->sentStrings('d.directory.set_if_stop_close_claim'),
			'the directory setter gets the raw destination once');
		$this->assertSame(1, count($this->sentStrings('d.ack_stop_close_claim')), 'the claim is acknowledged');
		$this->assertSame(array(), $this->logLines(), 'nothing is refused or held');
		$this->assertSame(array('.lock'), array_values(array_diff(scandir($this->journal()), array('.', '..'))),
			'the job and its receipt are retired');
	}

	public function testTrailingSpacesPreserveThePayloadRootAndItsNeighbour()
	{
		$data = $this->base . '/data';
		$name = 'foo ';
		$source = $data . '/src/' . $name;
		$neighbour = $data . '/src/foo';
		$destination = $data . '/dst';
		mkdir($destination, 0700);
		$this->write($source . '/a ', 'payload');
		$this->write($neighbour . '/a ', 'neighbour');
		$result = $this->drive($this->replies($source, $name, $source, true, array('a '),
			array('absent', 'absent', 'done')), $this->worker($destination));
		$this->assertSame(true, $result['applied'], 'the exact payload is moved');
		$this->assertSame('payload', @file_get_contents($destination . '/foo /a '), 'all path bytes survive');
		$this->assertSame('neighbour', @file_get_contents($neighbour . '/a '), 'the trimmed neighbour is untouched');
		$this->assertTrue(!file_exists($source . '/a '), 'only the actual source file moved');
	}

	public function testMoveFilesOfAQuotedBackslashedSingleFileDownloadThroughTheRealParser()
	{
		$data = $this->base . '/data';
		$name = 'one "f" \\g.bin';
		$parent = $data . '/src "p" \\y';
		$destination = $data . '/dst "d" \\w';
		mkdir($destination, 0700);
		$this->write($parent . '/' . $name, 'single payload');
		$result = $this->drive($this->replies($parent, $name, $parent . '/' . $name, false, array($name),
			array('absent', 'absent', 'done')), $this->worker($destination));

		$this->assertSame(array('refusal' => '', 'applied' => true, 'failure' => null, 'warning' => ''), $result,
			'the worker applies the move: ' . json_encode($result));
		$this->assertSame('single payload', @file_get_contents($destination . '/' . $name),
			'the file is at destination/<exact name>');
		$this->assertSame(array('dst "d" \\w', 'dst "d" \\w/' . $name, 'src "p" \\y'), $this->tree($data),
			'nothing is left at the source and no escaped spelling was created: '
			. json_encode($this->tree($data), JSON_UNESCAPED_SLASHES));
		$this->assertSame(array(array(self::HASH, self::TOKEN, $destination . '/')),
			$this->sentStrings('d.directory.set_if_stop_close_claim'),
			'the directory setter gets the raw destination once');
		$this->assertSame(array(), $this->logLines(), 'nothing is refused or held');
	}

	// A directory-only job interrupted before the switch: its previous
	// directory is the escaped spelling of the one the daemon now reports.
	private function version2Job($directory)
	{
		$job = array('version' => 2, 'phase' => 'new', 'job' => str_repeat('b', 32), 'hash' => self::HASH,
			'directory' => $directory . '/', 'add' => true, 'move' => false, 'source' => '',
			'destination' => '', 'files' => array(), 'local_id' => self::LOCAL_ID,
			'previous_directory' => self::escaped($this->base . '/data/src/mv "q" \\z'));
		return '$journal = rtDataDirJournal(); $job = $journal . "/" . ' . var_export(self::HASH, true) . ' . ".json";'
			. ' file_put_contents($job, ' . var_export(json_encode($job, JSON_UNESCAPED_SLASHES), true) . ');'
			. ' chmod($job, 0600);' . "\n";
	}

	// Recovery retires the safe cancellation before publishing its final
	// refusal, so a former held outcome no longer names a retained lease.
	public function testRecoveryRefusesAnAmbiguousVersion2JobAndLogsIt()
	{
		mkdir($this->base . '/data/old-target', 0700);
		$daemonDirectory = $this->base . '/data/src/mv "q" \\z';
		$result = $this->drive($this->replies($daemonDirectory, 'mv "q" \\z', $daemonDirectory, true, array(),
			array('absent', 'done')),
			$this->version2Job($this->base . '/data/old-target')
			. 'echo json_encode(array("ok" => rtDataDirRecover(true)));');

		$this->assertSame(array('ok' => true), $result, 'the recovery pass finishes');
		$this->assertSame(array('datadir: recovery refused hash=' . self::HASH . ' reason=job-paths-ambiguous'),
			$this->logLines(), 'the refusal is logged with its reason: ' . json_encode($this->logLines()));
		$this->assertSame(array('d.stop_close_claim_state', 'd.stop_close_claim', 'd.start_if_stop_close_claim',
			'd.stop_close_claim_state', 'd.ack_stop_close_claim',
			'd.get_directory', 'd.set_custom', 'd.message.set', 'd.save_full_session'), $this->daemon->calls(),
			'the claim restores and retires before the final refusal is published and saved: '
			. json_encode($this->daemon->calls()));
		$outcomes = $this->sentStrings('d.set_custom');
		$this->assertSame(1, count($outcomes), 'one final outcome is published');
		$this->assertSame(array(self::HASH, 'x-datadir-outcome'), array_slice($outcomes[0], 0, 2),
			'the outcome belongs to the exact torrent and datadir key');
		$outcome = json_decode(rawurldecode($outcomes[0][2]), true);
		$this->assertSame('job-paths-ambiguous', $outcome['reason'], 'the persisted outcome keeps the final refusal');
		$this->assertTrue(strpos($outcome['message'], 'claim-retained') === false,
			'the final outcome does not claim the retired lease is held');
		$this->assertSame(array(array(self::HASH, $outcome['message'])), $this->sentStrings('d.message.set'),
			'the immediate torrent message matches its persisted refusal');
		$this->assertSame(array(), array_values(array_diff(scandir($this->journal()), array('.', '..'))),
			'the refused job is retired');
	}

	// setdir.php's path: the worker replays the journal first, so the old job
	// is refused in that same line and no longer blocks the new request.
	public function testAWorkerRetiresTheAmbiguousJobAndAppliesItsOwnRequest()
	{
		mkdir($this->base . '/data/old-target', 0700);
		$destination = $this->base . '/data/new "n" \\e';
		mkdir($destination, 0700);
		$daemonDirectory = $this->base . '/data/src/mv "q" \\z';
		$result = $this->drive($this->replies($daemonDirectory, 'mv "q" \\z', $daemonDirectory, true, array(),
			array('absent', 'done', 'absent', 'absent', 'done')),
			$this->version2Job($this->base . '/data/old-target')
			. '$failure = null; $warning = null;'
			. ' $applied = rtDataDirApplyLocked(' . var_export(self::HASH, true) . ', ' . var_export($destination, true)
				. ', true, false, false, false, false, $failure, $warning);'
			. ' echo json_encode(array("applied" => $applied, "failure" => $failure, "warning" => $warning));');

		$this->assertSame(array('applied' => true, 'failure' => null, 'warning' => ''), $result,
			'the new request is applied: ' . json_encode($result));
		$this->assertSame(array('datadir: recovery refused hash=' . self::HASH . ' reason=job-paths-ambiguous'),
			$this->logLines(), 'the old job\'s refusal is logged with its reason: ' . json_encode($this->logLines()));
		$this->assertSame(array(array(self::HASH, self::TOKEN, $destination . '/')),
			$this->sentStrings('d.directory.set_if_stop_close_claim'),
			'only the new request sets a directory, the raw one it named');
		$this->assertSame(array('.lock'), array_values(array_diff(scandir($this->journal()), array('.', '..'))),
			'both jobs are retired');
	}
}
