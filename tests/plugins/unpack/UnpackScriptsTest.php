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
	 * An extractor that records each call, its arguments and the directory
	 * it was given, and runs $body with $dest, its last argument, that
	 * directory, $1 the command ("x" or "lt" for unrar) and $outside the
	 * directory beside the output that nothing may reach.
	 */
	private function extractor($name, $body)
	{
		$path = $this->base . '/' . $name;
		file_put_contents($path, "#!/bin/sh\nfor dest; do :; done\noutside=\"\$(dirname \"\$0\")/outside\"\n"
			. "echo \"\$1\" >> \"\$0.calls\"\necho \"\$*\" >> \"\$0.args\"\necho \"\$dest\" >> \"\$0.dest\"\n"
			. $body . "\n");
		chmod($path, 0700);
		return $path;
	}

	/** What extractor $name recorded of its calls: "calls", "args" or "dest". */
	private function calls($name, $what = 'calls')
	{
		$calls = $this->base . '/' . $name . '.' . $what;
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
		$link = 'ln -s "$outside" "$dest/l"';
		// unrar lists the link, once for each of two volumes, and creates it
		// only without -ol-.
		$unrar = $this->extractor('unrar', '[ "$1" = lt ] && printf "        Name: l\n        Type: Unix symbolic link\n%.0s" 1 2'
			. "\n" . '[ "$1" = x ] || exit 0; case " $* " in *" -ol- "*) ;; *) ' . $link . ' ;; esac');
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
		$this->assertSame(array($line, $line), self::lines($run['log']),
			'each script logs its refusal as the user, the link listed twice counted once');
		$this->assertSame(2, substr_count($run['err'], "unpack: refused archive-symbolic-link: l\n"),
			'and each names it once: ' . $run['err']);
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

	/**
	 * The first zip makes d -> outside; the second has d/pwn.txt, and unzip
	 * writes it through that link.
	 */
	private function zipsWithALinkThenAFileBelowIt()
	{
		file_put_contents($this->base . '/in/a.zip', 'zip');
		file_put_contents($this->base . '/in/b.zip', 'zip');
		return $this->extractor('unzip', 'case "$2" in'
			. ' *a.zip) ln -s "$outside" "$dest/d" ;;'
			. ' *b.zip) mkdir -p "$dest/d" && echo PWN > "$dest/d/pwn.txt" ;; esac');
	}

	/** $root holds the private directories, $output gets what is placed. */
	private function assertZipLinkRefused(array $run, $root, $output)
	{
		$private = $this->calls('unzip', 'dest');
		$this->assertTrue(count($private) === 2 && count(array_unique($private)) === 2 && count(array_filter($private,
			function ($dest) use ($root) { return strpos($dest, $root . '/.rutorrent-unpack.') === 0; })) === 2,
			'each zip is unpacked into a private directory of its own in ' . $root . ': ' . json_encode($private));
		$this->assertSame(array(), UnpackArchiveFixture::tree($this->base . '/outside'),
			'nothing is written outside: ' . json_encode($run));
		$this->assertSame(array('d', 'd/pwn.txt'), is_dir($output) ? UnpackArchiveFixture::tree($output) : null,
			'the second zip is unpacked into the output, the link of the first is not');
		$this->assertSame(1, $run['code'], 'the unpack fails');
		$this->assertTrue(strpos($run['err'], "unpack: refused archive-symbolic-link: d\n") !== false,
			'the task output names the link: ' . $run['err']);
		$this->assertSame(array('unpack: automatic unpack refused hash=' . self::HASH
			. ' reason=archive-symbolic-link entries=1'), self::lines($run['log']), 'the log has the refusal');
		$this->assertTrue(is_file($this->base . '/in/a.zip') && is_file($this->base . '/in/b.zip'),
			'and the archives are kept');
	}

	public function testALinkOneZipMadeIsNotWrittenThroughByTheNext()
	{
		$unzip = $this->zipsWithALinkThenAFileBelowIt();
		$run = $this->script('unzip_dir.sh', array($unzip, $this->base . '/in/', $this->base . '/out/', '',
			$this->base . '/in/a.zip;' . $this->base . '/in/b.zip;'));
		$this->assertZipLinkRefused($run, $this->base . '/out', $this->base . '/out');
	}

	public function testALinkOneZipMadeIsNotWrittenThroughByTheNextInTheTempDirectory()
	{
		$unzip = $this->zipsWithALinkThenAFileBelowIt();
		// An output that is not there yet is made.
		$run = $this->script('unzip_dir.sh', array($unzip, $this->base . '/in/', $this->base . '/out/new/', '',
			$this->base . '/in/a.zip;' . $this->base . '/in/b.zip;', $this->base . '/temp/'));
		$this->assertZipLinkRefused($run, $this->base . '/temp', $this->base . '/out/new');
		$this->assertTrue(!file_exists($this->base . '/temp'), 'the temp directory is removed');
	}

	public function testArchivesTwoDirectoriesDeepAreUnpacked()
	{
		// unzip -d makes only the last directory of the path it is given.
		$unzip = $this->extractor('unzip', '{ mkdir "$dest" 2>/dev/null || [ -d "$dest" ]; } || exit 2; echo z > "$dest/z"');
		// unrar is given each directory as "<directory>/.", rar or not, and
		// makes the whole path it extracts to.
		$unrar = $this->extractor('unrar', '[ "$1" = x ] || exit 0; for a; do case "$a" in *.) d=${a%.} ;; esac; done;'
			. ' for a in "$d"*.rar; do [ -f "$a" ] && mkdir -p "$dest" && echo r > "$dest/r"; done; exit 0');
		mkdir($this->base . '/in/Subs/eng', 0700, true);
		file_put_contents($this->base . '/in/Subs/eng/x.zip', 'zip');
		file_put_contents($this->base . '/in/Subs/eng/x.rar', 'rar');
		$run = $this->script('unall_dir.sh', array($unrar, $this->base . '/in/', $this->base . '/out/', $unzip));
		$this->assertSame(0, $run['code'], 'the unpack succeeds: ' . json_encode($run));
		$this->assertSame('', $run['err'], 'and reports nothing');
		$this->assertSame(array('Subs', 'Subs/eng', 'Subs/eng/r', 'Subs/eng/z'), UnpackArchiveFixture::tree($this->base . '/out'),
			'the nested subdirectory is made and filled');
	}

	public function testDirectoriesAnArchiveLockedArePlaced()
	{
		// A directory that cannot be written to or entered by its owner.
		$unzip = $this->extractor('unzip', 'mkdir -p "$dest/ro/sub" "$dest/shut" && echo f > "$dest/ro/sub/f"'
			. ' && echo g > "$dest/shut/g" && chmod 555 "$dest/ro/sub" "$dest/ro" && chmod 644 "$dest/shut"');
		file_put_contents($this->base . '/in/a.zip', 'zip');
		mkdir($this->base . '/out/ro');
		$run = $this->script('unzip_file.sh', array($unzip, $this->base . '/in/a.zip', $this->base . '/out/'));
		$this->assertSame(0, $run['code'], 'the unpack succeeds: ' . json_encode($run));
		$this->assertSame(array('ro', 'ro/sub', 'ro/sub/f', 'shut', 'shut/g'), UnpackArchiveFixture::tree($this->base . '/out'),
			'everything is placed');
		$this->assertTrue(!is_executable($this->base . '/out/ro/sub/f') && !is_executable($this->base . '/out/shut/g'),
			'and a file is not made executable');
	}

	public function testNamesWithSpacesNewlinesAndALeadingDashArePlaced()
	{
		$input = $this->base . "/in/-in put\nx/";
		$output = $this->base . "/out/-out put\nx/";
		mkdir($input . "-sub dir\ny", 0700, true);
		file_put_contents($input . "-a b\nc.zip", 'zip');
		file_put_contents($input . "-sub dir\ny/-d e\nf.zip", 'zip');
		// Each file is named after its archive.
		$unzip = $this->extractor('unzip', 'name=${2##*/}; echo z > "$dest/${name%.zip}"');
		$run = $this->script('unzip_dir.sh', array($unzip, $input, $output));
		$this->assertSame(0, $run['code'], 'the unpack succeeds: ' . json_encode($run));
		$this->assertSame(array("-out put\nx", "-out put\nx/-a b\nc", "-out put\nx/-sub dir\ny", "-out put\nx/-sub dir\ny/-d e\nf"),
			UnpackArchiveFixture::tree($this->base . '/out'), 'every name lands as it is');
		$this->assertSame('', $run['err'], 'and nothing is reported');
		$this->assertSame('', $run['log'], 'or logged');
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
