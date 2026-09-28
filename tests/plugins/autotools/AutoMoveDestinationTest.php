<?php

require_once(__DIR__ . '/../../php/TestCase.php');

/**
 * An old persisted Move hook still calls check.php before move.php. The new
 * Move transaction uses move_tx.php instead. The old hook must keep rTorrent
 * pointed at the source and leave its files there, including on collision.
 */
class AutoMoveDestinationTest extends TestCase
{
	const HASH = '0123456789ABCDEF0123456789ABCDEF01234567';
	const USER = 'tester';

	private $base;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-automove-' . getmypid();
	}

	public function tearDown()
	{
		$this->removeTree($this->base);
	}

	private function removeTree($path)
	{
		if (is_link($path)) { @unlink($path); return; }
		if (!is_dir($path)) { @unlink($path); return; }
		foreach (glob($path . '/*') as $entry)
			$this->removeTree($entry);
		@rmdir($path);
	}

	private function repoRoot()
	{
		return realpath(__DIR__ . '/../../..');
	}

	private function bstr($s)
	{
		return strlen($s) . ':' . $s;
	}

	/** A two file torrent, built here so the fixture is its own expectation. */
	private function torrentBytes($names)
	{
		$files = '';
		foreach ($names as $n)
			$files .= 'd' . $this->bstr('length') . 'i12e'
				. $this->bstr('path') . 'l' . $this->bstr($n) . 'e' . 'e';
		$info = 'd'
			. $this->bstr('files') . 'l' . $files . 'e'
			. $this->bstr('name') . $this->bstr('mytorrent')
			. $this->bstr('piece length') . 'i32768e'
			. $this->bstr('pieces') . $this->bstr(str_repeat("\x01", 20))
			. 'e';
		return 'd' . $this->bstr('announce')
			. $this->bstr('http://tracker.invalid/announce')
			. $this->bstr('info') . $info . 'e';
	}

	/** The prelude every driver shares: one profile, one user. */
	private function prelude()
	{
		return '$_SERVER[\'REMOTE_USER\'] = ' . var_export(self::USER, true) . ';'
			. ' $_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->base . '/profile', true) . ";\n"
			. ' $_ENV[\'RU_LOG_FILE\'] = ' . var_export($this->base . '/errors.log', true) . ";\n"
			. 'require_once(' . var_export($this->repoRoot() . '/conf/config.php', true) . ");\n"
			. '$scgi_host = "127.0.0.1"; $scgi_port = 1; $rpcTimeOut = 1; $rpcLogCalls = false;' . "\n";
	}

	private function runPhp($file, $args = array())
	{
		$cmd = escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 ' . escapeshellarg($file);
		foreach ($args as $a)
			$cmd .= ' ' . escapeshellarg($a);
		$out = array();
		exec($cmd . ' 2>&1', $out, $code);
		return array('exit' => $code, 'printed' => implode("\n", $out));
	}

	/**
	 * Builds a finished download, runs check.php and then move.php exactly as
	 * rtorrent runs them, and reports what each said and what is on disk.
	 *
	 * $occupy is the list of names already standing in the destination
	 * directory when the download finishes.
	 */
	private function runTheFinishedHook($occupy)
	{
		$this->removeTree($this->base);
		$names = array('1.bin', '2.bin');
		@mkdir($this->base . '/profile/settings', 0700, true);
		@mkdir($this->base . '/session', 0700, true);
		@mkdir($this->base . '/dl/mytorrent', 0777, true);
		@mkdir($this->base . '/fin', 0777, true);
		foreach ($names as $n)
			file_put_contents($this->base . '/dl/mytorrent/' . $n, 'download ' . $n);
		foreach ($occupy as $n)
		{
			@mkdir(dirname($this->base . '/fin/mytorrent/' . $n), 0777, true);
			file_put_contents($this->base . '/fin/mytorrent/' . $n, 'already there');
		}
		file_put_contents($this->base . '/session/' . self::HASH . '.torrent',
			$this->torrentBytes($names));

		$seed = $this->base . '/seed.php';
		file_put_contents($seed, "<?php\n" . $this->prelude()
			. 'require_once(' . var_export($this->repoRoot() . '/php/settings.php', true) . ");\n"
			. 'require_once(' . var_export($this->repoRoot() . '/plugins/autotools/autotools.php', true) . ");\n"
			. '$s = rTorrentSettings::get();' . "\n"
			. '$s->directory = ' . var_export($this->base . '/dl', true) . ";\n"
			. '$s->session = ' . var_export($this->base . '/session', true) . ";\n"
			. '$s->linkExist = true; $s->iVersion = 0x1015; $s->store();' . "\n"
			. '$at = rAutoTools::load();' . "\n"
			. '$at->enable_move = 1; $at->fileop_type = "Move";' . "\n"
			. '$at->path_to_finished = ' . var_export($this->base . '/fin', true) . ";\n"
			. '$at->automove_filter = "/.*/"; $at->addLabel = 0; $at->addName = 0;' . "\n"
			. '$at->skip_move_for_files = ""; $at->store();' . "\n"
			. 'echo rAutoTools::load()->path_to_finished;' . "\n");
		$seeded = $this->runPhp($seed);
		if ($seeded['printed'] !== $this->base . '/fin')
			throw new Exception('the plugin settings did not take: ' . $seeded['printed']);

		// One driver per script, because each is a top level script that reads
		// $argv and chdir()s to where it lives.
		$driver = array();
		foreach (array('check', 'move') as $which)
		{
			$driver[$which] = $this->base . '/drive-' . $which . '.php';
			file_put_contents($driver[$which], "<?php\n" . $this->prelude()
				. 'require(' . var_export(
					$this->repoRoot() . '/plugins/autotools/' . $which . '.php', true) . ");\n");
		}

		$basePath = $this->base . '/dl/mytorrent';   // d.get_base_path
		// d.get_base_path, d.get_base_filename, d.is_multi_file, d.get_custom1, d.get_name
		$check = $this->runPhp($driver['check'],
			array($basePath, 'mytorrent', '1', '', 'mytorrent', self::USER));
		// and then, with the hash in front, the same five
		$move = $this->runPhp($driver['move'],
			array(self::HASH, $basePath, 'mytorrent', '1', '', 'mytorrent', self::USER));

		$named = rtrim($check['printed'], '/');
		return array(
			'check' => $check,
			'log' => @file_get_contents($this->base . '/errors.log'),
			'move' => $move,
			'named' => $named,
			// the download's files, at the directory check.php named
			'at_named' => array_map(function($n) use ($named) {
				return is_file($named . '/' . $n) && file_get_contents($named . '/' . $n) === 'download ' . $n;
			}, $names),
			'at_source' => array_map(function($n) {
				return is_file($this->base . '/dl/mytorrent/' . $n);
			}, $names),
			'occupants' => array_map(function($n) {
				return @file_get_contents($this->base . '/fin/mytorrent/' . $n);
			}, $occupy),
		);
	}

	/** The obsolete pair refuses even when the destination is free. */
	public function testLegacyHookLeavesFilesAtTheSourceItNames()
	{
		$r = $this->runTheFinishedHook(array());

		$this->assertEquals($this->base . '/dl/mytorrent', $r['named'],
			'check.php keeps the old hook pointed at the source');
		$this->assertEquals(array(true, true), $r['at_named'],
			'and both files remain where rTorrent is pointed: ' . $r['named']);
		$this->assertEquals(array(true, true), $r['at_source'],
			'and both files remain at the source');
		$this->assertTrue(strpos((string)$r['log'],
			'autotools: legacy Move check refused; refresh the finished hook') !== false,
			'the refusal is visible in the configured log');
	}

	/**
	 * A collision must not move files or replace an existing destination.
	 */
	public function testLegacyHookKeepsTheSourceOnDestinationCollision()
	{
		$r = $this->runTheFinishedHook(array('2.bin'));

		$this->assertEquals($this->base . '/dl/mytorrent', $r['named'],
			'check.php keeps the old hook at the source on collision');
		$this->assertEquals(array('already there'), $r['occupants'],
			'the file that was already at the destination survives');
		$this->assertEquals(array(true, true), $r['at_named'],
			'and the download is at the directory rtorrent was pointed at (' . $r['named']
			. '), rather than pointed at a directory its data never reached');
	}

}
