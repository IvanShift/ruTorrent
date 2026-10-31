<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/PhpProcess.php');
require_once(__DIR__ . '/../../php/PluginEntryDriver.php');
require_once(__DIR__ . '/../../php/TestHttpServer.php');
require_once(__DIR__ . '/UnpackArchiveFixture.php');

/**
 * plugins/unpack/place.php moves what one extractor run left in its private
 * directory into the output directory. It drops every symbolic link the
 * archive brought, never writes into or through a symbolic link already in
 * the output, and reports each refusal by name in the task's output and,
 * classified and without a name, in the application log.
 *
 * The private directory here is built by hand; the real unzip and unrar are
 * run on real archives in UnpackArchiveLinksImageSmoke.php.
 */
class UnpackPlaceTest extends TestCase
{
	const HASH = UnpackArchiveFixture::HASH;

	private $base;
	private $shm;
	private $user;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-unpack-place-' . getmypid() . '-' . bin2hex(random_bytes(3));
		foreach (array('profile', 'stage', 'out', 'outside') as $directory)
			mkdir($this->base . '/' . $directory, 0700, true);
		$this->base = realpath($this->base);
		file_put_contents($this->base . '/outside/target', 'OLD');
		$this->shm = null;
		$this->user = null;
	}

	public function tearDown()
	{
		exec('chmod -R u+rwx ' . escapeshellarg($this->base));
		PluginEntryDriver::removeTree($this->base);
		if ($this->shm !== null)
			PluginEntryDriver::removeTree($this->shm);
		if ($this->user !== null)
			UnpackArchiveFixture::removeUser($this->user);
	}

	/** place.php in $mode with $arguments after the context; the user is $this->user. */
	private function placePhp($mode, array $arguments, array $ini = array(), $hash = self::HASH)
	{
		$run = PhpProcess::run(array_merge(PhpProcess::sameIni(), $ini, array(
			dirname(__DIR__, 3) . '/plugins/unpack/place.php', 'automatic', $hash, (string)$this->user, $mode),
			$arguments), array('env' => array('RU_PROFILE_PATH' => $this->base . '/profile',
				'RU_LOG_FILE' => $this->base . '/errors.log')));
		$log = $this->base . '/errors.log';
		$run['log'] = is_file($log) ? file_get_contents($log) : '';
		return $run;
	}

	private function place($stage = null, array $ini = array())
	{
		return $this->placePhp('place', array($stage ?? $this->base . '/stage', $this->base . '/out/', '0'), $ini);
	}

	private function tree($directory)
	{
		return UnpackArchiveFixture::tree($directory);
	}

	private function assertRefused(array $run, $reason, $entries, array $names, $verb = 'refused')
	{
		$this->assertSame(1, $run['code'], 'a refusal fails the placement: ' . json_encode($run));
		foreach ($names as $name)
			$this->assertTrue(strpos($run['err'], 'unpack: ' . $verb . ' ' . $reason . ': ' . $name . "\n") !== false,
				'the task output names ' . $name . ': ' . $run['err']);
		$this->assertSame('unpack: automatic unpack ' . $verb . ' hash=' . self::HASH . ' reason=' . $reason
			. ' entries=' . $entries, preg_replace('/^\[[^\]]*\] /', '', trim($run['log'])),
			'the application log has one classified line and no name');
	}

	private function assertOutsideUntouched(array $run)
	{
		$this->assertSame(array('target'), $this->tree($this->base . '/outside'),
			'nothing is created outside the output: ' . json_encode($run));
		$this->assertSame('OLD', file_get_contents($this->base . '/outside/target'),
			'nothing outside is written: ' . json_encode($run));
	}

	public function testEveryLinkTheArchiveBroughtIsDropped()
	{
		$stage = $this->base . '/stage';
		symlink($this->base . '/outside', $stage . '/absolute');
		symlink('../outside/target', $stage . '/up');
		symlink('kept.txt', $stage . '/inner');
		file_put_contents($stage . '/kept.txt', 'kept');
		// A directory the output lacks is moved whole, with what is in it.
		mkdir($stage . '/new');
		symlink($this->base . '/outside', $stage . '/new/nested');
		file_put_contents($stage . '/new/f', 'f');
		$run = $this->place();
		$this->assertSame(array('kept.txt', 'new', 'new/f'), $this->tree($this->base . '/out'),
			'only the files are placed, even a link that stays inside is not: ' . json_encode($run));
		$this->assertRefused($run, 'archive-symbolic-link', 4, array('absolute', 'up', 'inner', 'new/nested'));
	}

	public function testLinksTheExtractorSkippedCountAsRefused()
	{
		file_put_contents($this->base . '/stage/f', 'f');
		$run = $this->placePhp('place', array($this->base . '/stage', $this->base . '/out/', '2'));
		$this->assertSame(array('f'), $this->tree($this->base . '/out'), 'the rest is placed');
		$this->assertSame('', $run['err'], 'the script named them already');
		$this->assertSame(1, $run['code'], 'the placement fails');
		$this->assertSame('unpack: automatic unpack refused hash=' . self::HASH . ' reason=archive-symbolic-link entries=2',
			preg_replace('/^\[[^\]]*\] /', '', trim($run['log'])), 'and they are logged');
	}

	public function testANameIsShownEscaped()
	{
		symlink($this->base . '/outside', $this->base . "/stage/a\nb");
		$run = $this->place();
		$this->assertRefused($run, 'archive-symbolic-link', 1, array('a\\nb'));
	}

	public function testALinkThatCannotBeDroppedStopsThePlacement()
	{
		mkdir($this->base . '/stage/locked');
		symlink($this->base . '/outside', $this->base . '/stage/locked/link');
		file_put_contents($this->base . '/stage/f', 'NEW');
		chmod($this->base . '/stage/locked', 0500);
		$this->assertTrue(!is_writable($this->base . '/stage/locked'), 'precondition: not run as root');
		$run = $this->place();
		$this->assertOutsideUntouched($run);
		$this->assertSame(array(), $this->tree($this->base . '/out'), 'nothing is placed: ' . json_encode($run));
		$this->assertRefused($run, 'archive-symbolic-link', 1, array('locked/link'));
	}

	public function testADirectoryThatCannotBeEnteredIsNotMoved()
	{
		// It can be listed but not entered, so its entries cannot be told
		// links or not: read as "not a link" it would be moved whole.
		mkdir($this->base . '/stage/d');
		symlink('/', $this->base . '/stage/d/l');
		file_put_contents($this->base . '/stage/d/f', 'f');
		chmod($this->base . '/stage/d', 0644);
		$this->assertTrue(@lstat($this->base . '/stage/d/f') === false, 'precondition: not run as root');
		$run = $this->place();
		$this->assertSame(array(), $this->tree($this->base . '/out'), 'nothing is placed: ' . json_encode($run));
		$this->assertRefused($run, 'move-failed', 2, array('d/f', 'd/l'));
	}

	public function testADirectoryIsNotMergedIntoALinkInTheOutput()
	{
		symlink($this->base . '/outside', $this->base . '/out/d');
		mkdir($this->base . '/stage/d');
		file_put_contents($this->base . '/stage/d/f', 'NEW');
		$run = $this->place();
		$this->assertOutsideUntouched($run);
		$this->assertSame(array('d -> ' . $this->base . '/outside'), $this->tree($this->base . '/out'),
			'the link in the output is left as it was');
		$this->assertRefused($run, 'output-symbolic-link', 1, array('d'));
	}

	public function testAFileReplacesALinkInTheOutputInsteadOfWritingThroughIt()
	{
		symlink($this->base . '/outside/target', $this->base . '/out/f');
		symlink($this->base . '/outside', $this->base . '/out/g');
		file_put_contents($this->base . '/stage/f', 'NEW');
		file_put_contents($this->base . '/stage/g', 'NEW');
		$run = $this->place();
		$this->assertSame(0, $run['code'], 'a file may take a link\'s place: ' . json_encode($run));
		$this->assertOutsideUntouched($run);
		foreach (array('f', 'g') as $name)
			$this->assertTrue(!is_link($this->base . '/out/' . $name)
				&& file_get_contents($this->base . '/out/' . $name) === 'NEW',
				'the file replaces the link to a ' . (($name === 'f') ? 'file' : 'directory'));
	}

	public function testFromAnotherFilesystemNothingIsWrittenThroughTheOutputAndTimesAreKept()
	{
		$this->shm = '/dev/shm/rutorrent-unpack-place-' . getmypid() . '-' . bin2hex(random_bytes(3));
		$this->assertTrue(@mkdir($this->shm . '/new', 0700, true)
			&& stat($this->shm)['dev'] !== stat($this->base)['dev'],
			'precondition: /dev/shm is writable and is another filesystem than ' . $this->base);
		$old = 978307200;
		foreach (array('f', 'g', 'new/h') as $name)
		{
			file_put_contents($this->shm . '/' . $name, 'NEW');
			touch($this->shm . '/' . $name, $old);
		}
		touch($this->shm . '/new', $old);
		// A link to nothing yet, which the copy would create, and a second
		// name of a file outside, which the copy would write into.
		symlink($this->base . '/outside/created', $this->base . '/out/f');
		link($this->base . '/outside/target', $this->base . '/out/g');
		$run = $this->place($this->shm);
		$this->assertSame(0, $run['code'], 'a copy across filesystems is a placement: ' . json_encode($run));
		$this->assertOutsideUntouched($run);
		clearstatcache();
		foreach (array('f', 'g', 'new/h') as $name)
		{
			$this->assertTrue(!is_link($this->base . '/out/' . $name)
				&& @file_get_contents($this->base . '/out/' . $name) === 'NEW', $name . ' is placed as a new file');
			$this->assertSame($old, @filemtime($this->base . '/out/' . $name), $name . ' keeps its time');
		}
		$this->assertSame($old, @filemtime($this->base . '/out/new'),
			'a directory rename() cannot carry across is rebuilt and keeps its time');
	}

	public function testExistingDirectoriesAreMergedAndNamesKeptVerbatim()
	{
		$names = array('a b', "line\nbreak", '-dash');
		mkdir($this->base . '/out/kept');
		file_put_contents($this->base . '/out/kept/old.txt', 'old');
		mkdir($this->base . '/stage/kept/sub', 0700, true);
		file_put_contents($this->base . '/stage/kept/' . $names[0], '1');
		file_put_contents($this->base . '/stage/kept/sub/deep', '2');
		file_put_contents($this->base . '/stage/' . $names[1], '3');
		file_put_contents($this->base . '/stage/' . $names[2], '4');
		$run = $this->place();
		$this->assertSame(0, $run['code'], 'nothing is refused: ' . json_encode($run));
		$this->assertSame(array($names[2], 'kept', 'kept/' . $names[0], 'kept/old.txt', 'kept/sub', 'kept/sub/deep',
			$names[1]), $this->tree($this->base . '/out'), 'every entry lands under its own name');
		$this->assertSame('', $run['err'] . $run['log'], 'and nothing is reported');
	}

	public function testTypeConflictsAreRefusedAndLeftAlone()
	{
		file_put_contents($this->base . '/out/x', 'X');
		mkdir($this->base . '/out/y');
		mkdir($this->base . '/stage/x');
		file_put_contents($this->base . '/stage/x/inner', 'I');
		file_put_contents($this->base . '/stage/y', 'Y');
		$run = $this->place();
		$this->assertSame(array('x', 'y'), $this->tree($this->base . '/out'), 'both stay as they were');
		$this->assertSame('X', file_get_contents($this->base . '/out/x'), 'the file keeps its content');
		$this->assertRefused($run, 'output-type-conflict', 2, array('x', 'y'));
	}

	public function testADirectoryThatCannotBeReadIsReported()
	{
		$run = $this->place($this->base . '/missing');
		$this->assertRefused($run, 'move-failed', 1, array('.'));
	}

	public function testWithoutAnyOfItsFunctionsNothingIsMoved()
	{
		foreach (array('scandir', 'lstat', 'is_link', 'is_dir', 'file_exists', 'unlink', 'rename', 'mkdir',
			'filemtime', 'touch') as $function)
		{
			@unlink($this->base . '/errors.log');
			symlink($this->base . '/outside', $this->base . '/stage/d');
			file_put_contents($this->base . '/stage/f', 'NEW');
			$run = $this->place(null, array('-d', 'disable_functions=' . $function));
			$this->assertSame(array(), $this->tree($this->base . '/out'), $function . ': nothing is placed');
			$this->assertTrue($run['code'] !== 0, $function . ': the placement fails');
			// The child's own diagnostics are not echoed: they name the
			// disabled function as a PHP warning or fatal error.
			$this->assertTrue(strpos($run['err'], "unpack: refused check-unavailable: $function\n") !== false,
				$function . ': the task output names it');
			// FileUtil::toLog() needs these two itself, and PHP 8 stops it.
			if (!in_array($function, array('file_exists', 'touch'), true) || PHP_VERSION_ID < 80000)
				$this->assertSame('unpack: automatic unpack refused hash=' . self::HASH
					. ' reason=check-unavailable entries=1', preg_replace('/^\[[^\]]*\] /', '', trim($run['log'])),
					$function . ': the application log has the refusal');
			unlink($this->base . '/stage/d');
			unlink($this->base . '/stage/f');
		}
	}

	public function testTheScriptsReportsAreNamedAndLogged()
	{
		$run = $this->placePhp('refuse', array('private-directory-unavailable', $this->base . '/out/'));
		$this->assertRefused($run, 'private-directory-unavailable', 1, array($this->base . '/out/'));
		unlink($this->base . '/errors.log');
		$run = $this->placePhp('found', array('stale-private-directory', 'x'));
		$this->assertRefused($run, 'stale-private-directory', 1, array('x'), 'found');
	}

	public function testAnIncompleteCommandIsRefused()
	{
		foreach (array(array('place', $this->base . '/stage', $this->base . '/out/'), array('refuse', 'reason')) as $short)
		{
			$run = PhpProcess::run(array_merge(PhpProcess::sameIni(), array(
				dirname(__DIR__, 3) . '/plugins/unpack/place.php', 'automatic', self::HASH, ''), $short));
			$this->assertSame(2, $run['code'], 'a short command is a usage error: ' . json_encode($run));
			$this->assertSame(0, strpos($run['err'], 'usage: place.php '), 'which says how to call it');
		}
	}

	public function testWhatCannotBeMovedIsRefused()
	{
		mkdir($this->base . '/stage/d');
		file_put_contents($this->base . '/stage/f', 'f');
		chmod($this->base . '/out', 0500);
		$this->assertTrue(!is_writable($this->base . '/out'), 'precondition: not run as root');
		$run = $this->place();
		chmod($this->base . '/out', 0700);
		$this->assertSame(array(), $this->tree($this->base . '/out'), 'nothing is placed: ' . json_encode($run));
		$this->assertRefused($run, 'move-failed', 2, array('d', 'f'));
	}

	public function testAMalformedHashIsNotCopiedIntoTheLog()
	{
		// A manual unpack's hash comes from the request.
		$hashes = array("x\nforged", self::HASH . "\n", substr(self::HASH, 1), strtolower(self::HASH));
		foreach ($hashes as $hash)
			$run = $this->placePhp('refuse', array('reason', 'name'), array(), $hash);
		$line = 'unpack: automatic unpack refused hash=%s reason=reason entries=1';
		$lines = array_values(array_filter(array_map(function ($entry) {
			return preg_replace('/^\[[^\]]*\] /', '', $entry);
		}, explode("\n", $run['log'])), 'strlen'));
		$this->assertSame(array(sprintf($line, 'invalid'), sprintf($line, 'invalid'), sprintf($line, 'invalid'),
			sprintf($line, self::HASH)), $lines, 'only 40 hex digits and nothing after them are logged, in upper case');
	}

	/**
	 * With $pathToExternals['php'] naming php-cgi, place.php refuses, and the
	 * refusal fails: the unpack script then keeps the archives. A request in
	 * the environment takes the guard's web branch, which has to fail the same
	 * way.
	 */
	public function testARefusalOutsideTheCommandLineFails()
	{
		$cgi = dirname(PHP_BINARY) . '/php-cgi';
		if (!is_executable($cgi)) {
			echo "NOT CHECKED: no php-cgi beside " . PHP_BINARY . "\n";
			return;
		}
		$place = dirname(__DIR__, 3) . '/plugins/unpack/place.php';
		file_put_contents($this->base . '/stage/f', 'f');
		$arguments = implode(' ', array_map('escapeshellarg', array_merge(array($cgi, '-q'), PhpProcess::sameIni(),
			array('-d', 'cgi.force_redirect=0', '-f', $place, 'automatic', self::HASH, '', 'place',
			$this->base . '/stage', $this->base . '/out/', '0'))));
		foreach (array('no request' => array('', 'CLI binary'),
			'a request in the environment' => array('REQUEST_METHOD=GET SCRIPT_FILENAME=' . escapeshellarg($place) . ' ', 'Forbidden')) as $what => $case) {
			$output = array();
			exec($case[0] . $arguments . ' 2>&1', $output, $code);
			$this->assertSame(1, $code, 'with ' . $what . ' it fails: ' . json_encode($output));
			$this->assertTrue(strpos(implode("\n", $output), $case[1]) !== false, 'and says why: ' . json_encode($output));
			$this->assertSame(array('f'), $this->tree($this->base . '/stage'), 'with ' . $what . ' nothing is moved');
			$this->assertSame(array(), $this->tree($this->base . '/out'), 'and nothing is placed');
		}
	}

	/** It moves what it is told to, so a web request must not run it. */
	public function testAWebRequestIsRefused()
	{
		mkdir($this->base . '/web');
		file_put_contents($this->base . '/stage/f', 'f');
		$server = TestHttpServer::start($this->base . '/web', array(), array(),
			dirname(__DIR__, 3) . '/plugins/unpack/place.php');
		try {
			$response = TestHttpServer::rawGet($server->port(), '/place.php?automatic+' . self::HASH . '++place+'
				. rawurlencode($this->base . '/stage') . '+' . rawurlencode($this->base . '/out/') . '+0');
		} finally {
			$server->close();
		}
		$this->assertTrue(strpos($response['status'], ' 403 ') !== false, 'it is forbidden: ' . json_encode($response));
		$this->assertSame(array('f'), $this->tree($this->base . '/stage'), 'and nothing is moved');
		$this->assertSame(array(), $this->tree($this->base . '/out'), 'or placed');
	}

	public function testTheUsersOwnLogGetsTheLine()
	{
		$this->user = UnpackArchiveFixture::userWithLog($this->base . '/user.log');
		symlink($this->base . '/outside', $this->base . '/stage/l');
		$run = $this->place();
		$this->assertSame(1, $run['code'], 'the link is refused: ' . json_encode($run));
		$this->assertSame('', $run['log'], 'not in the log of no user');
		$this->assertTrue(strpos((string)@file_get_contents($this->base . '/user.log'),
			'reason=archive-symbolic-link entries=1') !== false, 'but in the user\'s own');
	}
}
