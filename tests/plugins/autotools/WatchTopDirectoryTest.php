<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/PluginEntryDriver.php');

/**
 * watch.php loads torrents from the AutoTools watch directory into
 * subdirectories of the download directory, and the scan, the touch, the
 * mkdir and the load follow any symbolic link on the way. A link can be made
 * at any time after the settings were saved, so the directories have to stay
 * inside $topDirectory once their links are followed when they are used, and
 * a refusal is logged, not left to a silent rescan every 300 s.
 *
 * Ported from upstream 2446f4827 (AutoToolsTopDirectoryTest, its watch
 * cases); the torrent that is itself a link to a file outside, and the log
 * lines, are the fork's. A directory link inside the watch directory is
 * tests/php/UnreadableDirectoryTest.php's. The tree is real: a top directory
 * holding the download directory, a directory outside it, and links out.
 * watch.php runs as its own process with the plugin's settings stored where
 * it reads them and rtorrent unreachable, so each torrent it takes up is
 * touched, fails to load and is renamed to .fail: a file left as it was is
 * one it did not take up.
 */
class WatchTopDirectoryTest extends TestCase
{
	private $base;
	private $top;

	public function tearDown()
	{
		if ($this->base !== null)
			PluginEntryDriver::removeTree($this->base);
		$this->base = null;
	}

	private function makeDir($dir)
	{
		if (!is_dir($dir) && !mkdir($dir, 0700, true))
			throw new RuntimeException('Could not create fixture directory ' . $dir);
	}

	/** A top directory with a download directory, and a directory outside it. */
	private function makeTree()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-autowatch-top-' . bin2hex(random_bytes(6));
		$this->makeDir($this->base . '/session');
		$this->makeDir($this->base . '/top/dl');
		$this->makeDir($this->base . '/outside');
		$this->base = realpath($this->base);
		$this->top = $this->base . '/top';
	}

	/** Stores the settings watch.php reads, then runs it once against port 1, where nothing answers. */
	private function watch($watched)
	{
		$run = PluginEntryDriver::run($this->base, 1, 'plugins/autotools/watch.php', array('before' =>
			'$topDirectory = ' . var_export($this->top . '/', true) . ';'
			. ' $s->directory = ' . var_export($this->top . '/dl', true) . ';'
			. ' $s->session = ' . var_export($this->base . '/session', true) . ';'
			. ' require_once ' . var_export(realpath(__DIR__ . '/../../..') . '/plugins/autotools/autotools.php', true) . ';'
			. ' $at = rAutoTools::load(); $at->enable_watch = 1; $at->watch_start = 0;'
			. ' $at->path_to_watch = ' . var_export($watched, true) . ';'
			. ' if (!$at->store()) throw new RuntimeException("the AutoTools settings were not stored");'));
		clearstatcache();
		return $run[2];
	}

	private function log()
	{
		return (string) @file_get_contents($this->base . '/application.log');
	}

	/** A torrent outside the top directory, with a time watch.php's touch would change. */
	private function outsideTorrent()
	{
		file_put_contents($this->base . '/outside/x.torrent', 'not ours');
		touch($this->base . '/outside/x.torrent', 1000000000);
		return $this->base . '/outside/x.torrent';
	}

	private function untouched($file)
	{
		return is_file($file) && filemtime($file) === 1000000000;
	}

	public function testAWatchDirectoryThatIsALinkOutIsNotScanned()
	{
		$this->makeTree();
		$outside = $this->outsideTorrent();
		symlink($this->base . '/outside', $this->top . '/watch');
		$errors = $this->watch($this->top . '/watch');
		$this->assertTrue($this->untouched($outside),
			'a torrent outside the top directory is neither touched nor renamed: ' . $errors);
		$this->assertEquals(1, substr_count($this->log(), 'autotools: watch refused: watch-directory-outside-top-directory;'),
			'the refusal is logged on the pass, not left to a silent rescan: ' . $this->log());
	}

	/**
	 * A torrent in a subdirectory of the watch directory is loaded into the
	 * same subdirectory of the download directory, which watch.php makes
	 * first; a link out there must not have it make directories outside.
	 */
	public function testADataDirectoryThroughALinkOutIsNotMade()
	{
		$this->makeTree();
		$this->makeDir($this->top . '/watch/sub/deeper');
		file_put_contents($this->top . '/watch/sub/deeper/y.torrent', 'ours');
		symlink($this->base . '/outside', $this->top . '/dl/sub');
		$errors = $this->watch($this->top . '/watch');
		$this->assertTrue(!file_exists($this->base . '/outside/deeper'),
			'no directory is made outside the top directory for its data: ' . $errors);
		$this->assertTrue(is_file($this->top . '/watch/sub/deeper/y.torrent.fail'),
			'the torrent is set aside, so the next scan does not take it up again');
		$this->assertEquals(1, substr_count($this->log(), 'autotools: watch refused: data-directory-outside-top-directory;'),
			'and the refusal is logged: ' . $this->log());
	}

	public function testATorrentThatLinksToAFileOutsideIsNotTouched()
	{
		$this->makeTree();
		$this->makeDir($this->top . '/watch');
		$outside = $this->outsideTorrent();
		symlink($outside, $this->top . '/watch/x.torrent');
		$errors = $this->watch($this->top . '/watch');
		$this->assertTrue($this->untouched($outside),
			'the file the link points at is neither touched nor renamed: ' . $errors);
		$this->assertTrue(is_link($this->top . '/watch/x.torrent.fail') && !file_exists($this->top . '/watch/x.torrent'),
			'the link itself is set aside, so the next scan does not take it up again');
		$this->assertEquals(1, substr_count($this->log(), 'autotools: watch refused: torrent-outside-top-directory;'),
			'and the refusal is logged: ' . $this->log());
	}

	/** One torrent is refused once, for the first reason found. */
	public function testATorrentAndItsDataDirectoryBothOutsideAreRefusedOnce()
	{
		$this->makeTree();
		$this->makeDir($this->top . '/watch/sub');
		$outside = $this->outsideTorrent();
		symlink($outside, $this->top . '/watch/sub/x.torrent');
		symlink($this->base . '/outside', $this->top . '/dl/sub');
		$errors = $this->watch($this->top . '/watch');
		$this->assertTrue($this->untouched($outside), 'the file the link points at is left alone: ' . $errors);
		$this->assertEquals(1, substr_count($this->log(), 'autotools: watch refused:'),
			'one refusal for one torrent: ' . $this->log());
		$this->assertEquals(1, substr_count($this->log(), 'autotools: watch refused: torrent-outside-top-directory;'),
			'naming the torrent: ' . $this->log());
	}

	public function testATorrentInsideTheTopDirectoryIsTakenUpWithoutARefusal()
	{
		$this->makeTree();
		$this->makeDir($this->top . '/watch');
		file_put_contents($this->top . '/watch/mine.torrent', 'ours');
		$errors = $this->watch($this->top . '/watch');
		$this->assertTrue(is_file($this->top . '/watch/mine.torrent.fail'),
			'it is taken up (and, with rtorrent unreachable, set aside): ' . $errors);
		$this->assertTrue(strpos($this->log(), 'autotools: watch refused') === false,
			'and nothing is refused: ' . $this->log());
	}
}
