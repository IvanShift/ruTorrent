<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');

/**
 * The worker entrypoint must refuse a daemon without the native claim ABI
 * before stopping a download or touching its data. ClaimedMoveTest covers
 * successful moves and cancellation after a real claim.
 */
class SetDirRefusedMoveTest extends TestCase
{
	const HASH = '0123456789ABCDEF0123456789ABCDEF01234567';

	private $base;
	private $daemon;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-setdir-refused-' . getmypid();
	}

	public function tearDown()
	{
		if ($this->daemon !== null)
		{
			$this->daemon->stop();
			$this->daemon = null;
		}
		$this->removeTree($this->base);
	}

	private function removeTree($path)
	{
		if (is_link($path)) { @unlink($path); return; }
		if (!is_dir($path)) { @unlink($path); return; }
		foreach (array_diff(scandir($path), array('.', '..')) as $entry)
			$this->removeTree($path . '/' . $entry);
		@rmdir($path);
	}

	private function repoRoot()
	{
		return realpath(__DIR__ . '/../../..');
	}

	/**
	 * Runs plugins/datadir/setdir.php against the daemon for a download that
	 * is open and active, and returns what the daemon was asked for plus what
	 * is on disk afterwards.
	 *
	 * $occupy is the list of names already standing in the destination, and
	 * $isOpen / $isActive are the run state rtorrent reports for the download
	 * before any of this starts -- which is the state it has to be given back.
	 */
	private function setTheDataDirectory($occupy, $isOpen = 1, $isActive = 1)
	{
		$this->removeTree($this->base);
		if ($this->daemon !== null)
			$this->daemon->stop();

		$names = array('1.bin', '2.bin');
		@mkdir($this->base . '/profile/settings', 0700, true);
		@mkdir($this->base . '/src/mytorrent', 0700, true);
		@mkdir($this->base . '/dst', 0700, true);
		foreach ($names as $n)
			file_put_contents($this->base . '/src/mytorrent/' . $n, 'download ' . $n);
		foreach ($occupy as $n)
		{
			@mkdir($this->base . '/dst/mytorrent', 0700, true);
			file_put_contents($this->base . '/dst/mytorrent/' . $n, 'already there');
		}

		// A legacy daemon advertises system.listMethods but lacks the
		// atomic stop/close claim methods. No mutation request may follow.
		$replies = array(array('system.listMethods'));
		$this->daemon = new FakeRtorrentDaemon($replies, $this->base . '/calls.log');

		$driver = $this->base . '/drive-setdir.php';
		file_put_contents($driver, "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->base . '/profile', true) . ";\n"
			. 'require_once(' . var_export($this->repoRoot() . '/conf/config.php', true) . ");\n"
			. 'require_once(' . var_export($this->repoRoot() . '/php/settings.php', true) . ');' . "\n"
			. '$r = new ReflectionClass("rTorrentSettings"); $s = $r->newInstanceWithoutConstructor();'
			. ' $s->iVersion = 0x1018; $s->aliases = array(); $s->directory = "/";'
			. ' $s->linkExist = true; $p = $r->getProperty("theSettings");'
			. ' $p->setAccessible(true); $p->setValue(null, $s);' . "\n"
			. '$scgi_host = "127.0.0.1";' . "\n"
			. '$scgi_port = ' . $this->daemon->port() . ";\n"
			. '$rpcTimeOut = 10; $rpcLogCalls = false;' . "\n"
			. 'require(' . var_export($this->repoRoot() . '/plugins/datadir/setdir.php', true) . ");\n");

		$cmd = escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 ' . escapeshellarg($driver)
			. ' ' . escapeshellarg(self::HASH)
			. ' ' . escapeshellarg($this->base . '/dst')
			. ' 1 1 0 tester';
		$out = array();
		exec($cmd . ' 2>&1', $out);

		return array(
			// Only what happened after the files were let go of: the run
			// opens a closed download at the start to read its paths, and that
			// is not the restart being asked about.
			'calls' => $this->daemon->calls(),
			'printed' => implode("\n", $out),
			'at_source' => array_map(function($n) {
				return is_file($this->base . '/src/mytorrent/' . $n);
			}, $names),
			'at_dest' => array_map(function($n) {
				return is_file($this->base . '/dst/mytorrent/' . $n)
					&& file_get_contents($this->base . '/dst/mytorrent/' . $n) === 'download ' . $n;
			}, $names),
			'occupants' => array_map(function($n) {
				return @file_get_contents($this->base . '/dst/mytorrent/' . $n);
			}, $occupy),
		);
	}

	private function called($calls, $method)
	{
		return in_array($method, $calls, true);
	}

	/** Any command that repoints the download, however the version spells it. */
	private function repointed($calls)
	{
		foreach ($calls as $call)
			if (strpos($call, 'd.set_directory') === 0 || strpos($call, 'd.directory.set') === 0)
				return true;
		return false;
	}

	public function testLegacyDaemonCannotStopOrMoveAnUnobstructedDownload()
	{
		$r = $this->setTheDataDirectory(array());
		$calls = $r['calls'];
		$this->assertTrue($this->called($calls, 'system.listMethods'),
			'worker checked the native claim ABI');
		$this->assertTrue(!$this->called($calls, 'd.stop')
			&& !$this->called($calls, 'd.stop_close_claim'),
			'unsupported daemon is never stopped or claimed: ' . implode(', ', $calls));
		$this->assertTrue(!$this->repointed($calls),
			'unsupported daemon is never repointed');
		$this->assertEquals(array(true, true), $r['at_source'],
			'both payload files remain at their source');
		$this->assertEquals(array(false, false), $r['at_dest'],
			'no payload was moved');
	}

	public function testLegacyDaemonPreservesPausedStateAndForeignDestination()
	{
		foreach (array(array(1, 1), array(0, 0)) as $state) {
			$r = $this->setTheDataDirectory(array('2.bin'), $state[0], $state[1]);
			$calls = $r['calls'];
			$this->assertTrue(!$this->called($calls, 'd.stop')
				&& !$this->called($calls, 'd.start')
				&& !$this->called($calls, 'd.stop_close_claim'),
				'unsupported daemon run state is unchanged: ' . implode(', ', $calls));
			$this->assertTrue(!$this->repointed($calls),
				'refused worker does not repoint data');
			$this->assertEquals(array(true, true), $r['at_source'],
				'payload remains whole at source');
			$this->assertEquals(array('already there'), $r['occupants'],
				'foreign destination bytes survive');
		}
	}
}
