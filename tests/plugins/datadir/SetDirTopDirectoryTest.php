<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/PluginEntryDriver.php');

/**
 * "Set data directory" keeps a download inside $topDirectory once the
 * symbolic links along the new directory are followed, not only as text.
 *
 * correctDirectory() compares the path as written. setdir.php makes the
 * directory with mkdir and moves the data with rename and copy, and all of
 * them follow links, so a link inside the top directory that points out of
 * it had the download's files moved wherever it pointed.
 *
 * action.php refuses such a directory before it starts anything, and
 * setdir.php checks again before it makes the directory: it runs detached,
 * possibly after other moves, and a link made in the meantime must not be
 * followed either.
 *
 * The tree is real: a top directory, a directory outside it, a link out and
 * a dangling link.
 *
 * In this fork these are pins: action.php and setdir.php already refuse
 * through rtDataDirRequestRefusal(), which resolves the destination's links
 * (correctDirectory(..., true)) and asks DataDirMoveIntent::destinationReason().
 * FailureVisibilityTest drives the same refusals with a stubbed
 * correctDirectory(); these run the real one. The scripted daemon here does
 * not confirm the daemon's claim ABI, so the fork refuses every move in this
 * fixture, and "an error" proves nothing: each case pins the classified
 * reason of the top-directory refusal. Upstream's two acceptance cases are
 * not carried for the same reason; FailureVisibilityTest admits a
 * destination below the top directory.
 */
class SetDirTopDirectoryTest extends TestCase
{
	const HASH = '0123456789ABCDEF0123456789ABCDEF01234567';

	private $base;
	private $top;
	private $daemon;

	public function tearDown()
	{
		$this->reset();
	}

	private function reset()
	{
		if ($this->daemon !== null)
		{
			$this->daemon->stop();
			$this->daemon = null;
		}
		if ($this->base !== null)
			PluginEntryDriver::removeTree($this->base);
	}

	private function makeTree()
	{
		$this->reset();
		$this->base = sys_get_temp_dir() . '/rutorrent-setdir-top-' . uniqid('', true);
		@mkdir($this->base . '/top/src/mytorrent', 0777, true);
		@mkdir($this->base . '/outside', 0777, true);
		$this->base = realpath($this->base);
		$this->top = $this->base . '/top';
		foreach (array('1.bin', '2.bin') as $n)
			file_put_contents($this->top . '/src/mytorrent/' . $n, 'download ' . $n);
		symlink($this->base . '/outside', $this->top . '/out');
		symlink($this->base . '/nowhere', $this->top . '/dangle');
		$this->daemon = new FakeRtorrentDaemon(new FakeRtorrentMethodReplies(array()),
			$this->base . '/calls.log');
	}

	/** Runs a datadir script with this daemon and $topDirectory; $code runs just before it. */
	private function runScript($script, $code, array $options = array())
	{
		$options['before'] = '$topDirectory = ' . var_export($this->top . '/', true) . '; ' . $code;
		list($exit, $out, $err) = PluginEntryDriver::run($this->base, $this->daemon->port(), $script, $options);
		return $out . $err;
	}

	/**
	 * Every "set data directory" with the files moved that leaves the top
	 * directory through a link is refused at action.php, by its classified
	 * reason, and no move is started.
	 */
	public function testDirectoriesThroughALinkOutAreRefused()
	{
		$cases = array(
			'a link out of the top directory' => array('/out', 'destination-invalid'),
			'a directory that does not exist yet, under a link out' => array('/out/new', 'destination-invalid'),
			'a link that does not resolve' => array('/dangle/new', 'destination-root-unavailable'),
		);
		foreach ($cases as $what => list($datadir, $reason)) {
			$this->makeTree();
			$printed = $this->runScript('plugins/datadir/action.php', '$HTTP_RAW_POST_DATA = '
				. var_export('hash=' . self::HASH . '&datadir=' . rawurlencode($this->top . $datadir)
					. '&move_addpath=1&move_datafiles=1&move_fastresume=0', true) . ';',
				array('post' => array()));
			$this->assertEquals(array('errors' => array(array('desc' => 'theUILang.datadirSetDirFail', 'prm' => $reason))),
				json_decode($printed, true), $what . ' is refused as ' . $reason . ': ' . $printed);
			$this->assertTrue(!in_array('execute', $this->daemon->calls(), true),
				'and the move is not started: ' . json_encode($this->daemon->calls()));
		}
	}

	/**
	 * setdir.php runs detached, after action.php has answered and possibly
	 * after other moves, so the link can be made after action.php checked.
	 * Started with a directory under a link out, it makes nothing there and
	 * moves nothing. Unlike upstream, the fork's worker does talk to rTorrent
	 * here, to leave its refusal on the download, so what is pinned is the
	 * filesystem rather than the daemon's silence.
	 */
	public function testSetDirMakesNothingThroughALinkOut()
	{
		$this->makeTree();
		$printed = $this->runScript('plugins/datadir/setdir.php', '$argv = '
			. var_export(array('setdir.php', self::HASH, $this->top . '/out/new', '1', '1', '0'), true)
			. '; $argc = count($argv);');
		$this->assertTrue(!file_exists($this->base . '/outside/new'),
			'no directory is made outside the top directory: ' . $printed);
		$this->assertEquals(array('1.bin', '2.bin'), array_values(array_diff(scandir($this->top . '/src/mytorrent'), array('.', '..'))),
			'the data stays where it was');
		$this->assertEquals(array('.', '..'), scandir($this->base . '/outside'),
			'nothing is made or moved outside the top directory: ' . $printed);
		$log = (string)@file_get_contents($this->base . '/application.log');
		$this->assertTrue(strpos($log, 'datadir: worker refused hash=' . self::HASH . ' reason=destination-invalid') !== false,
			'because the destination is outside the top directory: ' . $log);
	}
}
