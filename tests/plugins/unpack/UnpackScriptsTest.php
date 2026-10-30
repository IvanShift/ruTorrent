<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/PluginEntryDriver.php');
require_once(__DIR__ . '/UnpackArchiveFixture.php');

/**
 * The unpack scripts and stage.sh under this runtime's own sh, with
 * extractors written here: what reaches place.php, the links unrar -ol-
 * skips, a private directory a cancelled task left and one that cannot be
 * made. The real unzip and unrar are run in UnpackArchiveLinksImageSmoke.php.
 */
class UnpackScriptsTest extends TestCase
{
	const HASH = UnpackArchiveFixture::HASH;
	// unrar 7.23's own "lt" lines for a RAR 5.0 archive with a Unix and a
	// Windows symbolic link and a junction (UnpackArchiveFixture::rar()).
	const LISTED_LINKS = "        Name: real\n        Type: File\n        Name: lib.so\n        Type: Unix symbolic link\n"
		. "      Target: real\n        Name: winlink\n        Type: Windows symbolic link\n      Target: real\n"
		. "        Name: junction\n        Type: NTFS junction point\n      Target: real\n"
		. "        Name: Type: Junction\n        Type: File\n";

	private $base;
	private $user;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-unpack-scripts-' . getmypid() . '-' . bin2hex(random_bytes(3));
		foreach (array('profile', 'in', 'out', 'outside') as $directory)
			mkdir($this->base . '/' . $directory, 0700, true);
		$this->base = realpath($this->base);
		$this->user = UnpackArchiveFixture::userWithLog($this->base . '/user.log');
	}

	public function tearDown()
	{
		exec('chmod -R u+rwx ' . escapeshellarg($this->base));
		PluginEntryDriver::removeTree($this->base);
		UnpackArchiveFixture::removeUser($this->user);
	}

	/**
	 * An extractor that records each call and runs $body with $dest, its
	 * last argument, the directory it was given, and $1 the command
	 * ("x" or "lt" for unrar).
	 */
	private function extractor($name, $body)
	{
		$path = $this->base . '/' . $name;
		file_put_contents($path, "#!/bin/sh\nfor dest; do :; done\necho \"\$1\" >> " . escapeshellarg($path . '.calls')
			. "\necho \"\$*\" >> " . escapeshellarg($path . '.args') . "\n" . $body . "\n");
		chmod($path, 0700);
		return $path;
	}

	private function calls($name)
	{
		$calls = $this->base . '/' . $name . '.calls';
		return is_file($calls) ? file($calls, FILE_IGNORE_NEW_LINES) : array();
	}

	/** Runs plugins/unpack/$script as unpack.php does, with $this->user. */
	private function script($script, array $arguments)
	{
		$arguments = array_pad($arguments, 6, '');
		array_push($arguments, PHP_BINARY, 'automatic', self::HASH, $this->user);
		$process = proc_open(array_merge(array(dirname(__DIR__, 3) . '/plugins/unpack/' . $script), $arguments),
			array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes,
			$this->base, array_merge(getenv(), array('RU_PROFILE_PATH' => $this->base . '/profile',
				'RU_LOG_FILE' => $this->base . '/errors.log')));
		if (!is_resource($process))
			throw new RuntimeException('cannot start ' . $script);
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($process);
		return array('code' => $code, 'out' => $out, 'err' => $err,
			'log' => (string)@file_get_contents($this->base . '/user.log'),
			'global' => (string)@file_get_contents($this->base . '/errors.log'));
	}

	/** The application log's lines without their times. */
	private static function lines($log)
	{
		return array_values(array_filter(array_map(function ($line) {
			return preg_replace('/^\[[^\]]*\] /', '', $line);
		}, explode("\n", $log)), 'strlen'));
	}

	public function testTheUsersContextReachesPlacePhp()
	{
		$unzip = $this->extractor('unzip', 'ln -s ' . escapeshellarg($this->base . '/outside') . ' "$dest/l"');
		file_put_contents($this->base . '/in/a.zip', 'zip');
		$run = $this->script('unzip_file.sh', array($unzip, $this->base . '/in/a.zip', $this->base . '/out/'));
		$this->assertSame(1, $run['code'], 'the link is refused: ' . json_encode($run));
		$this->assertSame(array('unpack: automatic unpack refused hash=' . self::HASH
			. ' reason=archive-symbolic-link entries=1'), self::lines($run['log']), 'in the user\'s own log');
		$this->assertSame('', $run['global'], 'not in the log of no user');
	}

	public function testUnallHandsBothScriptsTheirContext()
	{
		$link = 'ln -s ' . escapeshellarg($this->base . '/outside') . ' "$dest/l"';
		$unrar = $this->extractor('unrar', '[ "$1" = x ] && ' . $link);
		$unzip = $this->extractor('unzip', $link);
		file_put_contents($this->base . '/in/a.rar', 'rar');
		file_put_contents($this->base . '/in/a.zip', 'zip');
		$run = $this->script('unall_dir.sh', array($unrar, $this->base . '/in/', $this->base . '/out/', $unzip));
		$this->assertSame(array('lt', 'x'), $this->calls('unrar'), 'unrar lists, then extracts');
		$args = file($this->base . '/unrar.args', FILE_IGNORE_NEW_LINES);
		$this->assertTrue(isset($args[1]) && strpos(' ' . $args[1] . ' ', ' -ol- ') !== false,
			'unrar_dir.sh extracts without creating symbolic links: ' . json_encode($args));
		$this->assertSame(array('-o'), $this->calls('unzip'), 'unzip extracts');
		$line = 'unpack: automatic unpack refused hash=' . self::HASH . ' reason=archive-symbolic-link entries=1';
		$this->assertSame(array($line, $line), self::lines($run['log']), 'each script logs its refusal as the user');
	}

	public function testLinksUnrarSkipsAreRefusedAndTheArchiveKept()
	{
		// With -ol- unrar extracts none of them, and here nothing else.
		$unrar = $this->extractor('unrar', '[ "$1" = lt ] && printf %s ' . escapeshellarg(self::LISTED_LINKS));
		file_put_contents($this->base . '/in/a.rar', 'rar');
		$run = $this->script('unrar_file.sh', array($unrar, $this->base . '/in/a.rar', $this->base . '/out/', '',
			$this->base . '/in/a.rar'));
		$this->assertSame(1, $run['code'], 'the unpack fails: ' . json_encode($run));
		$args = file($this->base . '/unrar.args', FILE_IGNORE_NEW_LINES);
		$this->assertTrue(isset($args[1]) && strpos(' ' . $args[1] . ' ', ' -ol- ') !== false,
			'unrar extracts without creating symbolic links: ' . json_encode($args));
		$this->assertTrue(strpos($args[0], 'lt -v -p- -- ') === 0, 'every volume is listed, without a password prompt: ' . $args[0]);
		$this->assertTrue(strpos($run['err'], 'Type: Junction') === false, 'a file named like a type is not refused: ' . $run['err']);
		foreach (array('lib.so', 'winlink', 'junction') as $name)
			$this->assertTrue(strpos($run['err'], "unpack: refused archive-symbolic-link: $name\n") !== false,
				'the task output names ' . $name . ': ' . $run['err']);
		$this->assertSame(array('unpack: automatic unpack refused hash=' . self::HASH
			. ' reason=archive-symbolic-link entries=3'), self::lines($run['log']), 'the log counts them');
		$this->assertSame(array(), UnpackArchiveFixture::tree($this->base . '/out'), 'nothing is delivered');
		$this->assertTrue(is_file($this->base . '/in/a.rar'), 'and the archive is kept');
	}

	public function testAnArchiveTwoDirectoriesDeepIsUnpacked()
	{
		// unzip -d makes only the last directory of the path it is given.
		$unzip = $this->extractor('unzip', '{ mkdir "$dest" 2>/dev/null || [ -d "$dest" ]; } || exit 2; echo a > "$dest/a"');
		mkdir($this->base . '/in/Subs/eng', 0700, true);
		file_put_contents($this->base . '/in/Subs/eng/x.zip', 'zip');
		$run = $this->script('unzip_dir.sh', array($unzip, $this->base . '/in/', $this->base . '/out/'));
		$this->assertSame(0, $run['code'], 'the unpack succeeds: ' . json_encode($run));
		$this->assertSame(array('Subs', 'Subs/eng', 'Subs/eng/a'), UnpackArchiveFixture::tree($this->base . '/out'),
			'the nested subdirectory is made and filled');
	}

	public function testAStalePrivateDirectoryIsReportedOnceAndKept()
	{
		// No process has number 999999999; this test's own is alive.
		$stale = $this->base . '/out/.rutorrent-unpack.999999999.dead00';
		$live = $this->base . '/out/.rutorrent-unpack.' . getmypid() . '.live00';
		mkdir($stale);
		file_put_contents($stale . '/partial', 'p');
		mkdir($live);
		$unzip = $this->extractor('unzip', 'echo f > "$dest/$(basename "$2")"');
		file_put_contents($this->base . '/in/a.zip', 'zip');
		file_put_contents($this->base . '/in/b.zip', 'zip');
		$run = $this->script('unzip_dir.sh', array($unzip, $this->base . '/in/', $this->base . '/out/'));
		$this->assertSame(0, $run['code'], 'the unpack itself succeeds: ' . json_encode($run));
		$this->assertSame(1, substr_count($run['err'], 'unpack: found stale-private-directory: '),
			'one report for the run: ' . $run['err']);
		$this->assertTrue(strpos($run['err'], "unpack: found stale-private-directory: $stale\n") !== false,
			'naming the directory whose process is gone: ' . $run['err']);
		$this->assertSame(array('unpack: automatic unpack found hash=' . self::HASH
			. ' reason=stale-private-directory entries=1'), self::lines($run['log']), 'and it is logged');
		$this->assertTrue(is_file($stale . '/partial') && is_dir($live), 'neither directory is removed');
	}

	public function testAPrivateDirectoryThatCannotBeMadeIsRefusedAndLogged()
	{
		$unzip = $this->extractor('unzip', 'echo f > "$dest/f"');
		file_put_contents($this->base . '/in/a.zip', 'zip');
		chmod($this->base . '/out', 0500);
		$this->assertTrue(!is_writable($this->base . '/out'), 'precondition: not run as root');
		$run = $this->script('unzip_file.sh', array($unzip, $this->base . '/in/a.zip', $this->base . '/out/'));
		$this->assertSame(1, $run['code'], 'the unpack fails: ' . json_encode($run));
		$this->assertSame(array(), $this->calls('unzip'), 'without starting the extractor');
		$this->assertTrue(strpos($run['err'], 'unpack: refused private-directory-unavailable: '
			. $this->base . "/out/\n") !== false, 'the task output says why: ' . $run['err']);
		$this->assertSame(array('unpack: automatic unpack refused hash=' . self::HASH
			. ' reason=private-directory-unavailable entries=1'), self::lines($run['log']), 'and so does the log');
	}
}
