<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');

/**
 * The dialog reports predictable move refusals before starting a detached
 * worker. It reads torrent metadata without opening a closed torrent and
 * probes rename support with temporary entries; the claimed worker rechecks.
 * These cases pin occupied names, case-sensitive names and same-directory
 * requests so the early checks do not suppress a valid move.
 */
class SetDirActionCollisionTest extends TestCase
{
	const HASH = '0123456789ABCDEF0123456789ABCDEF01234567';

	private $base;
	private $daemon;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-setdir-action-' . getmypid();
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
		foreach (glob($path . '/*') as $entry)
			$this->removeTree($entry);
		@rmdir($path);
	}

	private function repoRoot()
	{
		return realpath(__DIR__ . '/../../..');
	}

	/**
	 * Posts a "set data directory" to action.php and reports what came back
	 * and what rTorrent was asked for.
	 *
	 * $occupy are names already standing under the destination, $isOpen is the
	 * run state rTorrent reports, and $datadir defaults to a directory that is
	 * not where the data already is.
	 */
	private function setTheDataDirectory($occupy, $isOpen = 1, $datadir = null, $moveFiles = 1,
		$rawEquals = false, $malformed = false, $afterStart = array(0, 1))
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
		if ($datadir === null)
			$datadir = $this->base . '/dst';
		$encodedDir = rawurlencode($datadir);
		if ($rawEquals)
			$encodedDir = str_replace('%3D', '=', $encodedDir);

		// Native claim support is required before the UI may dispatch a worker.
		$methods = array('system.listMethods', 'd.stop_close_claim_state',
			'd.stop_close_claim', 'd.directory.set_if_stop_close_claim',
			'd.directory.base.set_if_stop_close_claim', 'd.start_if_stop_close_claim',
			'd.open_if_stop_close_claim', 'd.release_stop_close_claim',
			'd.replay_stop_close_claim', 'd.ack_stop_close_claim');
		$replies = array($methods);
		if ($moveFiles) {
			$replies[] = array($isOpen, 'local-1', $this->base . '/src');
			if ($isOpen) {
				$replies[] = array('mytorrent', $this->base . '/src/mytorrent',
					'mytorrent', 1);
				$replies[] = $names;
			}
		}
		if ($moveFiles) $replies[] = array(0); // detached payload worker
		else {
			// The request read the claim ABI above; the change does not read it
			// again. A restored start is followed by its hash check outcome.
			$receipt = 'token:1|1|1|1|local-1';
			$replies = array_merge($replies, array(array_fill(0, 9, ''),
				array('absent'), array($isOpen, 'local-1', $this->base . '/src'),
				array('absent'), array($receipt), array($this->base . '/src'),
				array(1), array(1), array('done|S|' . $receipt), array(1), $afterStart,
				// A stop is kept as the download's outcome: its directory, the
				// custom key with d.message, and a session save.
				array($this->base . '/dst'), array(0, 0), array(0)));
		}
		$this->daemon = new FakeRtorrentDaemon($replies, $this->base . '/calls.log');

		$driver = $this->base . '/drive-action.php';
		file_put_contents($driver, "<?php\n"
			. '$_SERVER[\'REMOTE_USER\'] = "tester"; $_ENV[\'RU_PROFILE_PATH\'] = '
				. var_export($this->base . '/profile', true) . ";\n"
			. 'require_once(' . var_export($this->repoRoot() . '/conf/config.php', true) . ");\n"
			. 'require_once(' . var_export($this->repoRoot() . '/php/settings.php', true) . ');' . "\n"
			. '$r = new ReflectionClass("rTorrentSettings"); $s = $r->newInstanceWithoutConstructor();'
			. ' $s->iVersion = 0x1018; $s->aliases = array(); $s->directory = "/";'
			. ' $s->linkExist = true; $p = $r->getProperty("theSettings");'
			. ' $p->setAccessible(true); $p->setValue(null, $s);' . "\n"
			. '$scgi_host = "127.0.0.1"; $scgi_port = ' . $this->daemon->port() . ';'
				. ' $rpcTimeOut = 10; $rpcLogCalls = false;' . "\n"
			. '$topDirectory = "/";' . "\n"
			. '$HTTP_RAW_POST_DATA = "hash=' . self::HASH . '&datadir='
				. $encodedDir . ($malformed ? '&datadir' : '')
				. '&move_addpath=1&move_datafiles=' . $moveFiles
				. '&move_fastresume=0";' . "\n"
			. 'chdir(' . var_export($this->repoRoot() . '/plugins/datadir', true) . ");\n"
			. 'require(' . var_export($this->repoRoot() . '/plugins/datadir/action.php', true) . ");\n");

		$out = array();
		exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 ' . escapeshellarg($driver)
			. ' 2>&1', $out);
		$printed = implode("\n", $out);
		$answer = json_decode($printed, true);
		if (!is_array($answer) || !array_key_exists('errors', $answer))
			throw new Exception('action.php printed: ' . $printed);

		return array(
			'printed' => $printed,
			'answer' => $answer,
			'errors' => $answer['errors'],
			'calls' => $this->daemon->calls(),
			'bodies' => $this->daemon->bodies(),
			'started' => in_array('execute', $this->daemon->calls(), true),
			'applied' => in_array('d.directory.set_if_stop_close_claim', $this->daemon->calls(), true),
		);
	}

	public function testRawEqualsInDirectoryReachesWorkerCommand()
	{
		$dir = $this->base . '/dst=chosen';
		$r = $this->setTheDataDirectory(array(), 1, $dir, 0, true);
		$this->assertSame(array(), $r['errors'], $r['printed']);
		$this->assertTrue($r['applied'], 'the valid destination is applied synchronously');
		$this->assertTrue(strpos(implode("\n", $r['bodies']), htmlspecialchars($dir, ENT_COMPAT, 'UTF-8')) !== false,
			'the complete directory reaches the guarded setter');
	}

	public function testMalformedTrailingFieldDoesNotOverwriteDestination()
	{
		$dir = $this->base . '/dst=chosen';
		$r = $this->setTheDataDirectory(array(), 1, $dir, 0, false, true);
		$this->assertSame(array(), $r['errors'], $r['printed']);
		$this->assertTrue($r['applied'], 'the valid destination is applied synchronously');
		$this->assertTrue(strpos(implode("\n", $r['bodies']), htmlspecialchars($dir, ENT_COMPAT, 'UTF-8')) !== false,
			'the malformed field does not replace the valid directory');
	}

	public function testANewDestinationIsAdmittedWithoutCreatingOrOpeningAnything()
	{
		$dir = $this->base . '/dst/new/nested';
		$r = $this->setTheDataDirectory(array(), 1, $dir, 1);
		$this->assertSame(array(), $r['errors'], 'A valid new destination is admitted: ' . $r['printed']);
		$this->assertTrue($r['started'], 'The worker receives the new destination');
		$this->assertTrue(!file_exists($dir), 'Preflight does not create the requested destination');
		$this->assertTrue(!in_array('d.open', $r['calls'], true)
			&& !in_array('d.stop', $r['calls'], true), 'Preflight reads metadata without changing torrent state');
	}

	/**
	 * The whole point: the dialog is told the move did not happen, and which
	 * name stopped it, rather than being told nothing at all.
	 */
	public function testATakenDestinationComesBackAsAnError()
	{
		$r = $this->setTheDataDirectory(array('2.bin'));

		$this->assertTrue(count($r['errors']) > 0,
			'a destination that is already taken is refused before anything is '
			. 'started; action.php answered: ' . $r['printed']);
		$this->assertTrue(!$r['started'],
			'and the detached move is not started at all; calls: '
			. implode(', ', $r['calls']));
		$this->assertTrue(strpos($r['printed'], '2.bin') !== false,
			'and the name that stopped it is in the answer, because it is the '
			. 'one thing the user can act on: ' . $r['printed']);
	}

	/**
	 * A name that only looks taken. On a case sensitive filesystem 2.BIN does
	 * not occupy 2.bin, the move would have made it, and the check must not
	 * refuse it: it is the same test the move applies, not a stricter one.
	 */
	public function testANameThatDiffersOnlyInCaseIsNotTreatedAsTaken()
	{
		// Whether 2.BIN occupies 2.bin is the filesystem's answer, not an
		// assumption, so it is asked rather than assumed.
		@mkdir($this->base, 0777, true);
		file_put_contents($this->base . '/CASE', 'x');
		$foldsCase = file_exists($this->base . '/case');
		@unlink($this->base . '/CASE');
		if ($foldsCase)
		{
			$this->assertTrue(true,
				'the filesystem under the test tree folds case, so 2.BIN does '
				. 'occupy 2.bin and there is nothing to tell apart here');
			return;
		}

		$r = $this->setTheDataDirectory(array('2.BIN'));

		$this->assertEquals(array(), $r['errors'],
			'a name differing only in case does not occupy the destination, so '
			. 'the move goes ahead; action.php answered: ' . $r['printed']);
		$this->assertTrue($r['started'],
			'and the move is started; calls: ' . implode(', ', $r['calls']));
	}

	/**
	 * A download the check cannot read. rTorrent reports no base path or file
	 * list for a closed download, and opening one to answer a question would
	 * be a change made by a check, so the collision is left to the move --
	 * which is where it was found before there was a check at all.
	 */
	public function testAClosedDownloadIsNotOpenedToAnswerTheQuestion()
	{
		$r = $this->setTheDataDirectory(array('2.bin'), 0);

		$this->assertTrue(!in_array('d.open', $r['calls'], true),
			'the check does not open a closed download; calls: '
			. implode(', ', $r['calls']));
		$this->assertTrue($r['started'],
			'and hands it to the move as before; calls: ' . implode(', ', $r['calls']));
	}

	/**
	 * A destination that is where the data already is. Every name is taken --
	 * by the download's own files -- and no move would happen, so refusing it
	 * would be refusing a request that cannot collide with anything.
	 */
	public function testMovingADownloadToWhereItAlreadyIsIsNotRefused()
	{
		$r = $this->setTheDataDirectory(array(), 1, $this->base . '/src');

		$this->assertEquals(array(), $r['errors'],
			'a download is not refused its own directory; action.php answered: '
			. $r['printed']);
		$this->assertTrue($r['started'],
			'calls: ' . implode(', ', $r['calls']));
	}

	/**
	 * And a request that does not move the files cannot collide with
	 * anything, so nothing is read for it.
	 */
	public function testARequestThatDoesNotMoveTheFilesIsNotChecked()
	{
		$r = $this->setTheDataDirectory(array('2.bin'), 1, null, 0);

		$this->assertEquals(array('errors' => array()), $r['answer'],
			'leaving the data where it is cannot collide; action.php answered: '
			. $r['printed']);
		$this->assertTrue(!in_array('f.multicall', $r['calls'], true),
			'so the file list is not read; calls: ' . implode(', ', $r['calls']));
		$this->assertTrue($r['applied'],
			'and the directory change is confirmed; calls: ' . implode(', ', $r['calls']));
		$this->assertSame(1, count(array_keys($r['calls'], 'system.listMethods', true)),
			'one request reads the claim ABI once; calls: ' . implode(', ', $r['calls']));
		$this->assertTrue(!in_array('d.message.set', $r['calls'], true),
			'a download that survives its hash check gets no warning');
	}

	/**
	 * The new directory does not hold the data: the claim restores the start,
	 * rTorrent's own hash check stops the download again, and the dialog and
	 * the torrent's message both say so instead of a silent success.
	 */
	public function testADownloadStoppedAfterTheChangeIsAVisibleWarning()
	{
		$r = $this->setTheDataDirectory(array(), 1, null, 0, false, false, array(0, 0));

		$this->assertEquals(array('errors' => array(), 'warnings' => array(array(
			'desc' => 'theUILang.datadirStoppedAfterCheck', 'prm' => 'stopped-after-hash-check'))),
			$r['answer'], 'the dialog sees the applied change and the stop: ' . $r['printed']);
		$this->assertTrue(in_array('d.message.set', $r['calls'], true),
			'the torrent message names the stop; calls: ' . implode(', ', $r['calls']));
		$written = preg_grep('/x-datadir-outcome/', $r['bodies']);
		$this->assertSame(1, count($written), 'the stop is kept on the download once (ACC-OWNB-01)');
		$message = (string)reset($written);
		$this->assertTrue(strpos($message, 'd.message.set') !== false && strpos($message,
			'datadir: stopped-after-hash-check directory=' . htmlspecialchars($this->base . '/dst', ENT_COMPAT, 'UTF-8')) !== false,
			'with the classified message naming the directory: ' . $message);
	}
}
