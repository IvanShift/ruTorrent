<?php

require_once(__DIR__ . '/TestCase.php');

/**
 * AutoWatch skips unreadable directories and child directory symlinks without
 * losing regular torrents in its selected tree. The probe runs a byte-for-byte
 * copy of the shipped AutoTools entrypoint with warnings visible.
 *
 * Directory permissions must apply to the test user; root bypasses them.
 */
class UnreadableDirectoryTest extends TestCase
{
	private $tree;

	public function setUp()
	{
		$this->tree = sys_get_temp_dir() . '/rutorrent-unreadable-' . getmypid();
		$this->wipe();
	}

	public function tearDown()
	{
		$this->wipe();
	}

	private function wipe()
	{
		if (!is_dir($this->tree))
			return;
		exec('chmod -R u+rwx ' . escapeshellarg($this->tree) . ' 2>/dev/null');
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->tree, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($items as $item)
			$item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		@rmdir($this->tree);
	}

	private function repoRoot()
	{
		return dirname(dirname(__DIR__));
	}

	/** Every file in the tree that defines rtScanFiles(), relative to the root. */
	private function sources()
	{
		$found = array();
		foreach (array('php', 'plugins') as $dir)
		{
			$items = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($this->repoRoot() . '/' . $dir, FilesystemIterator::SKIP_DOTS));
			foreach ($items as $item)
			{
				if (!$item->isFile() || substr($item->getFilename(), -4) !== '.php')
					continue;
				if (preg_match('/function\s+rtScanFiles\s*\(/i', file_get_contents($item->getPathname())))
					$found[] = substr($item->getPathname(), strlen($this->repoRoot()) + 1);
			}
		}
		sort($found);
		return $found;
	}

	/**
	 * Runs one copy in its own process. Returns array(results, anything else
	 * the process printed); results is null when the process never got as far
	 * as reporting them.
	 */
	private function drive($source)
	{
		$this->wipe();
		$holder = $this->tree . '/' . dirname($source);
		@mkdir($holder, 0777, true);
		@mkdir($this->tree . '/php/utility', 0777, true);
		copy($this->repoRoot() . '/php/utility/rtpluginutil.php',
			$this->tree . '/php/utility/rtpluginutil.php');

		$from = $this->repoRoot() . '/' . $source;
		$to = $holder . '/' . basename($source);
		copy($from, $to);
		if (hash_file('sha256', $from) !== hash_file('sha256', $to))
			throw new Exception('could not copy ' . $source);

		file_put_contents($this->tree . '/php/xmlrpc.php', '<?php
			class LFS
			{
				public static function is_file($p) { return(is_file($p)); }
			}
		');
		file_put_contents($this->tree . '/php/Torrent.php', '<?php');
		file_put_contents($this->tree . '/php/rtorrent.php', '<?php');

		$probe = $this->tree . '/probe.php';
		file_put_contents($probe, '<?php
			chdir(' . var_export($holder, true) . ');
			require_once(' . var_export($to, true) . ');
			$root = ' . var_export($this->tree . '/work', true) . ';
			$locked = array();
			function put($p, $c) { @mkdir(dirname($p), 0777, true); file_put_contents($p, $c); }
			function lock($p) { global $locked; chmod($p, 0); $locked[] = $p; }
			register_shutdown_function(function () {
				global $locked;
				foreach ($locked as $p)
					@chmod($p, 0755);
			});
			$out = array();

			// A watch directory with an unreadable subdirectory in it.
			put($root . "/scan/a.torrent", "a");
			put($root . "/scan/sub/c.torrent", "c");
			put($root . "/scan/locked/b.torrent", "b");
			lock($root . "/scan/locked");
			$out["precondition"] = (@opendir($root . "/scan/locked") === false);
			echo "--begin--\n";
			$files = rtScanFiles($root . "/scan", "/\\\\.torrent\$/i");
			sort($files);
			$out["scan"] = $files;

			// And a watch directory that cannot be opened at all.
			put($root . "/scantop/a.torrent", "a");
			lock($root . "/scantop");
			$out["scantop"] = rtScanFiles($root . "/scantop", "/\\\\.torrent\$/i");

			// A child directory link must not take the watcher outside its root.
			put($root . "/watch/regular.torrent", "regular");
			put($root . "/watch/sub/nested.torrent", "nested");
			put($root . "/outside/leak.torrent", "outside");
			symlink($root . "/outside", $root . "/watch/escape");
			symlink($root . "/watch", $root . "/watch/loop");
			$out["links"] = rtScanFiles($root . "/watch", "/\\.torrent$/i");
			sort($out["links"]);

			echo "--end--\n" . json_encode($out);
		');

		$output = array();
		exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=-1 -d display_errors=1 -d log_errors=0 -d html_errors=0 -d xdebug.mode=off '
			. escapeshellarg($probe) . ' 2>&1', $output);
		$printed = implode("\n", $output);
		$begin = strpos($printed, "--begin--\n");
		$end = strrpos($printed, "--end--\n");
		if ($begin === false || $end === false)
			return array(null, $printed);
		$result = json_decode(substr($printed, $end + strlen("--end--\n")), true);
		$noise = trim(substr($printed, $begin + strlen("--begin--\n"), $end - $begin - strlen("--begin--\n")));
		return array($result, $noise);
	}

	public function testTheScanFindsTheCopiesThatShip()
	{
		$sources = $this->sources();
		$this->assertEquals(array('plugins/autotools/util_rt.php'), $sources,
			'the traversal entry point remains in AutoTools');
	}

	public function testUnreadableWatchDirectoryIsSkipped()
	{
		foreach ($this->sources() as $source)
		{
			list($r, $noise) = $this->drive($source);
			$this->assertTrue(is_array($r), $source . ': the walk runs to the end; it printed: ' . $noise);
			if (!is_array($r))
				continue;
			$this->assertTrue($r['precondition'] === true,
				$source . ': the watch directory really cannot be opened (not running as root)');
			$this->assertEquals('', $noise, $source . ': and nothing is printed on the way');

			$this->assertEquals(array('a.torrent', 'sub/c.torrent'), $r['scan'],
				$source . ': a scan returns what it can read and skips what it cannot');
			$this->assertEquals(array(), $r['scantop'],
				$source . ': a scan of a directory it cannot open finds nothing');
		}
	}
	public function testChildDirectoryLinksStayOutsideTheWatchWalk()
	{
		foreach ($this->sources() as $source)
		{
			list($r, $noise) = $this->drive($source);
			$this->assertEquals(array('regular.torrent', 'sub/nested.torrent'), $r['links'],
				$source . ': regular files are found without entering an outside directory link');
		}
	}

}
