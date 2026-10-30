<?php
// The unpack scripts with the image's own unzip and unrar, on archives from
// a download that bring symbolic and hard links. Nothing may be written
// outside the output directory, and each refusal is reported in the task's
// output and in the application log. Only the shipped image has both
// extractors: tools/matrix.sh runs this file in its prod-kinozal leg.
require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/PluginEntryDriver.php');
require_once(__DIR__ . '/UnpackArchiveFixture.php');

foreach(array('unzip', 'unrar') as $tool)
	if(trim((string)shell_exec('command -v ' . $tool)) === '')
		throw new RuntimeException('the image has no ' . $tool);

$base = sys_get_temp_dir() . '/rutorrent-unpack-links-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($base . '/profile', 0700, true);
$base = realpath($base);
putenv('RU_PROFILE_PATH=' . $base . '/profile');
putenv('RU_LOG_FILE=' . $base . '/errors.log');
$case = null;

/** A fresh case directory with in/, out/ and outside/, outside/secret holding "SECRET". */
function unpackCase($name)
{
	global $base, $case;
	$case = $base . '/' . $name;
	foreach(array('in', 'out', 'outside') as $directory)
		mkdir($case . '/' . $directory, 0700, true);
	file_put_contents($case . '/outside/secret', 'SECRET');
	@unlink($base . '/errors.log');
	return $case;
}

/** Runs plugins/unpack/$script with the arguments unpack.php passes; returns exit, stdout, stderr. */
function unpackRun($script, array $arguments, $php = PHP_BINARY)
{
	$arguments = array_pad($arguments, 6, '');
	array_push($arguments, $php, 'automatic', UnpackArchiveFixture::HASH, '');
	global $case;
	$process = proc_open(array_merge(array(dirname(__DIR__, 3) . '/plugins/unpack/' . $script), $arguments),
		array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $case);
	if(!is_resource($process))
		throw new RuntimeException('cannot start ' . $script);
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	return array(proc_close($process), $out, $err);
}

/**
 * A wrapper of $program that records its argument number $argument, one per
 * line, in $c/$name.calls: the command of an extractor, or the private
 * directory PHP is asked to place (see stage.sh).
 */
function unpackRecorder($c, $name, $program, $argument)
{
	file_put_contents("$c/$name", "#!/bin/sh\nprintf '%s\\n' \"\${" . $argument . "}\" >> "
		. escapeshellarg("$c/$name.calls") . "\nexec " . escapeshellarg($program) . ' "$@"' . "\n");
	chmod("$c/$name", 0700);
	return "$c/$name";
}

function unpackCalls($c, $name)
{
	return is_file("$c/$name.calls") ? file("$c/$name.calls", FILE_IGNORE_NEW_LINES) : array();
}

function unpackNothingOutside($run)
{
	global $case;
	testAssertSame(array('secret'), UnpackArchiveFixture::tree($case . '/outside'),
		'nothing is written outside the output: ' . json_encode($run));
	testAssertSame('SECRET', file_get_contents($case . '/outside/secret'),
		'and nothing outside is changed: ' . json_encode($run));
	$left = glob($case . '/out/.rutorrent-unpack.*');
	testAssertSame(array(), $left === false ? array() : $left, 'no private directory is left behind');
}

/** The run exited 1, named $reason in its output and logged it $entries times without a name. */
function unpackRefused($run, $reason, $entries)
{
	global $base;
	testAssertSame(1, $run[0], 'a refusal fails the unpack: ' . json_encode($run));
	testAssertTrue(strpos($run[2], 'unpack: refused ' . $reason . ': ') !== false,
		'the task output names the refusal: ' . json_encode($run));
	$log = is_file($base . '/errors.log') ? file_get_contents($base . '/errors.log') : '';
	testAssertTrue(strpos($log, 'unpack: automatic unpack refused hash=' . UnpackArchiveFixture::HASH . ' reason=' . $reason
		. ' entries=' . $entries . "\n") !== false, 'the application log has the classified refusal: ' . $log);
}

$tests = array(
	'zip: a link from one archive is not written through by the next' => function () {
		$c = unpackCase('zip-cross');
		UnpackArchiveFixture::zip("$c/in/a1.zip", array(array('l', 'sub/d', "$c/outside"), array('f', 'ok.txt', 'ok')));
		UnpackArchiveFixture::zip("$c/in/a2.zip", array(array('f', 'sub/d/pwn.txt', 'PWN')));
		$run = unpackRun('unzip_dir.sh', array('unzip', "$c/in/", "$c/out/"));
		unpackNothingOutside($run);
		testAssertSame(array('ok.txt', 'sub', 'sub/d', 'sub/d/pwn.txt'), UnpackArchiveFixture::tree("$c/out"),
			'the link is dropped, the rest delivered');
		unpackRefused($run, 'archive-symbolic-link', 1);
	},
	'zip: the same through the unpack temp directory' => function () {
		$c = unpackCase('zip-cross-temp');
		UnpackArchiveFixture::zip("$c/in/a1.zip", array(array('l', 'd', "$c/outside"), array('f', 'ok.txt', 'ok')));
		UnpackArchiveFixture::zip("$c/in/a2.zip", array(array('f', 'd/pwn.txt', 'PWN')));
		$run = unpackRun('unzip_dir.sh', array('unzip', "$c/in/", "$c/out/", '', '', "$c/temp/"));
		unpackNothingOutside($run);
		testAssertSame(array('d', 'd/pwn.txt', 'ok.txt'), UnpackArchiveFixture::tree("$c/out"), 'delivered from the temp directory');
	},
	'every script: the unpack temp directory holds the private directories and is removed' => function () {
		$c = unpackCase('temp');
		rmdir("$c/out");
		mkdir("$c/in/zip");
		mkdir("$c/in/rar");
		UnpackArchiveFixture::zip("$c/in/zip/a.zip", array(array('f', 'a.txt', 'a')));
		UnpackArchiveFixture::rar("$c/in/rar/a.rar", array(array('f', 'a.txt', 'a')));
		$php = unpackRecorder($c, 'php', PHP_BINARY, 6);
		foreach(array(array('unzip_file.sh', 'unzip', 'zip/a.zip'), array('unzip_dir.sh', 'unzip', 'zip/'),
			array('unrar_file.sh', 'unrar', 'rar/a.rar'), array('unrar_dir.sh', 'unrar', 'rar/')) as $call)
		{
			@unlink("$c/php.calls");
			$run = unpackRun($call[0], array($call[1], "$c/in/" . $call[2], "$c/out/", '', '', "$c/temp/"), $php);
			testAssertSame(0, $run[0], $call[0] . ' succeeds: ' . json_encode($run));
			testAssertSame(1, count(unpackCalls($c, 'php')), $call[0] . ' places once');
			foreach(unpackCalls($c, 'php') as $stage)
				testAssertSame(0, strpos($stage, "$c/temp/.rutorrent-unpack."), $call[0] . ' places from the temp directory: ' . $stage);
			testAssertTrue(!file_exists("$c/temp"), $call[0] . ' removes the temp directory');
			testAssertSame(array('a.txt'), UnpackArchiveFixture::tree("$c/out"), $call[0] . ' delivers the file into a new output');
			unlink("$c/out/a.txt");
			rmdir("$c/out");
		}
	},
	'zip: two manual unpacks into one directory' => function () {
		$c = unpackCase('zip-manual');
		UnpackArchiveFixture::zip("$c/in/a1.zip", array(array('l', 'd', "$c/outside")));
		UnpackArchiveFixture::zip("$c/in/a2.zip", array(array('f', 'd/pwn.txt', 'PWN')));
		unpackRun('unzip_file.sh', array('unzip', "$c/in/a1.zip", "$c/out/"));
		unpackNothingOutside(unpackRun('unzip_file.sh', array('unzip', "$c/in/a2.zip", "$c/out/")));
	},
	'zip: a link already in the output is neither followed nor removed' => function () {
		$c = unpackCase('zip-existing');
		symlink("$c/outside", "$c/out/d");
		UnpackArchiveFixture::zip("$c/in/a.zip", array(array('f', 'd/pwn.txt', 'PWN')));
		$run = unpackRun('unzip_file.sh', array('unzip', "$c/in/a.zip", "$c/out/"));
		unpackNothingOutside($run);
		testAssertSame(array("d -> $c/outside"), UnpackArchiveFixture::tree("$c/out"), 'the link stays as it was');
		unpackRefused($run, 'output-symbolic-link', 1);
	},
	'rar: a link already in the output is neither followed nor removed' => function () {
		$c = unpackCase('rar-existing');
		symlink("$c/outside", "$c/out/d");
		UnpackArchiveFixture::rar("$c/in/a.rar", array(array('f', 'd/pwn.txt', 'PWN')));
		$run = unpackRun('unrar_file.sh', array('unrar', "$c/in/a.rar", "$c/out/"));
		unpackNothingOutside($run);
		testAssertSame(array("d -> $c/outside"), UnpackArchiveFixture::tree("$c/out"), 'the link stays as it was');
		unpackRefused($run, 'output-symbolic-link', 1);
	},
	'zip: a directory that cannot be entered or read is not moved blind' => function () {
		$c = unpackCase('zip-modes');
		UnpackArchiveFixture::zip("$c/in/a.zip", array(array('d', 'd', '', 040644), array('l', 'd/l', '/'),
			array('f', 'd/f', 'f'), array('d', 'z', '', 040000), array('f', 'z/f', 'z'), array('d', 'w', '', 040300),
			array('f', 'w/g', 'g')));
		$run = unpackRun('unzip_file.sh', array('unzip', "$c/in/a.zip", "$c/out/"));
		unpackNothingOutside($run);
		testAssertSame(array('d', 'd/f', 'w', 'w/g', 'z', 'z/f'), UnpackArchiveFixture::tree("$c/out"),
			'every file is delivered and the link is not');
		unpackRefused($run, 'archive-symbolic-link', 1);
	},
	'rar: a link unrar skips is refused and its archive kept, RAR 5.0 and 2.9' => function () {
		$c = unpackCase('rar-links');
		UnpackArchiveFixture::rar("$c/in/a50.rar", array(array('f', 'lib.so.1', 'L'), array('l', 'lib.so', 'lib.so.1')));
		UnpackArchiveFixture::rar29("$c/in/a29.rar", array(array('f', 'old.so.1', 'O'), array('l', 'old.so', 'old.so.1')));
		$run = unpackRun('unrar_dir.sh', array('unrar', "$c/in/", "$c/out/", '', "$c/in/a50.rar;$c/in/a29.rar;"));
		unpackNothingOutside($run);
		testAssertSame(array('lib.so.1', 'old.so.1'), UnpackArchiveFixture::tree("$c/out"), 'the files are delivered');
		foreach(array('lib.so', 'old.so') as $name)
			testAssertTrue(strpos($run[2], "unpack: refused archive-symbolic-link: $name\n") !== false,
				'the task output names ' . $name . ': ' . json_encode($run));
		unpackRefused($run, 'archive-symbolic-link', 2);
		testAssertTrue(is_file("$c/in/a50.rar") && is_file("$c/in/a29.rar"), 'both archives are kept');
	},
	'zip: a subdirectory is not unpacked into a link an archive made' => function () {
		$c = unpackCase('zip-recursion');
		mkdir("$c/in/sub");
		UnpackArchiveFixture::zip("$c/in/a1.zip", array(array('l', 'sub', "$c/outside")));
		UnpackArchiveFixture::zip("$c/in/sub/a2.zip", array(array('f', 'pwn.txt', 'PWN')));
		$run = unpackRun('unzip_dir.sh', array('unzip', "$c/in/", "$c/out/"));
		unpackNothingOutside($run);
		testAssertSame(array('sub', 'sub/pwn.txt'), UnpackArchiveFixture::tree("$c/out"), 'the subdirectory is a real one');
	},
	'zip: an archive two directories deep is unpacked' => function () {
		// unzip -d makes only the last directory of the path it is given.
		$c = unpackCase('zip-nested');
		mkdir("$c/in/Subs/eng", 0700, true);
		UnpackArchiveFixture::zip("$c/in/Subs/eng/x.zip", array(array('f', 'a', 'A')));
		$run = unpackRun('unzip_dir.sh', array('unzip', "$c/in/", "$c/out/"));
		testAssertSame(0, $run[0], 'the unpack succeeds: ' . json_encode($run));
		testAssertSame(array('Subs', 'Subs/eng', 'Subs/eng/a'), UnpackArchiveFixture::tree("$c/out"), 'into its own subdirectory');
	},
	'rar: a subdirectory is not unpacked into a link already in the output' => function () {
		$c = unpackCase('rar-recursion');
		mkdir("$c/in/sub");
		symlink("$c/outside", "$c/out/sub");
		UnpackArchiveFixture::rar("$c/in/sub/a.rar", array(array('f', 'pwn.txt', 'PWN')));
		$run = unpackRun('unrar_dir.sh', array('unrar', "$c/in/", "$c/out/"));
		unpackNothingOutside($run);
		unpackRefused($run, 'output-symbolic-link', 1);
	},
	'rar: a chain of links unrar accepts one by one does not lead a subdirectory out' => function () {
		$c = unpackCase('rar-chain');
		mkdir("$c/in/x/y/c/outside", 0700, true);
		UnpackArchiveFixture::rar("$c/in/a1.rar", array(array('d', 'x', ''), array('l', 'x/b', '..'),
			array('d', 'x/y', ''), array('l', 'x/y/c', '../b/..')));
		UnpackArchiveFixture::rar("$c/in/x/y/c/outside/a2.rar", array(array('f', 'pwn.txt', 'PWN')));
		$run = unpackRun('unrar_dir.sh', array('unrar', "$c/in/", "$c/out/"));
		unpackNothingOutside($run);
		testAssertSame(array('x', 'x/y', 'x/y/c', 'x/y/c/outside', 'x/y/c/outside/pwn.txt'), UnpackArchiveFixture::tree("$c/out"),
			'no link is extracted and the subdirectory is a real one');
	},
	'rar: a hard link through such a chain does not overwrite a file outside' => function () {
		foreach(array('unrar_file.sh' => 'a.rar', 'unrar_dir.sh' => '') as $script => $archive)
		{
			$c = unpackCase('rar-hard-link-' . $script);
			// Each link climbs one level more than the last, through the one
			// before: x/y/c is one level above where unrar extracts and
			// x/y/z/d two. One of them is the case directory, whether unrar
			// extracts into the output or into a directory inside it.
			UnpackArchiveFixture::rar("$c/in/a.rar", array(array('d', 'x', ''), array('l', 'x/b', '..'),
				array('d', 'x/y', ''), array('l', 'x/y/c', '../b/..'), array('d', 'x/y/z', ''),
				array('l', 'x/y/z/d', '../c/..'), array('h', 'h2', 'x/y/c/outside/secret'), array('f', 'h2', 'EVIL'),
				array('h', 'h3', 'x/y/z/d/outside/secret'), array('f', 'h3', 'EVIL')));
			$run = unpackRun($script, array('unrar', "$c/in/$archive", "$c/out/"));
			unpackNothingOutside($run);
			clearstatcache();
			testAssertSame(1, stat("$c/outside/secret")['nlink'], $script . ': the file outside gains no second name');
		}
	},
	'rar: the output directory is made when something is placed' => function () {
		$c = unpackCase('rar-new-output');
		UnpackArchiveFixture::rar("$c/in/a.rar", array(array('f', 'a.txt', 'a')));
		$run = unpackRun('unrar_file.sh', array('unrar', "$c/in/a.rar", "$c/out/label/name/"));
		testAssertSame(0, $run[0], 'the unpack succeeds: ' . json_encode($run));
		testAssertSame(array('label', 'label/name', 'label/name/a.txt'), UnpackArchiveFixture::tree("$c/out"), 'into the new directory');
	},
	'every script: a private directory that cannot be made fails the unpack' => function () {
		$c = unpackCase('read-only');
		mkdir("$c/in/sub");
		UnpackArchiveFixture::zip("$c/in/a.zip", array(array('f', 'a.txt', 'a')));
		UnpackArchiveFixture::rar("$c/in/a.rar", array(array('f', 'a.txt', 'a')));
		chmod("$c/out", 0500);
		$unzip = unpackRecorder($c, 'unzip', trim(shell_exec('command -v unzip')), 1);
		$unrar = unpackRecorder($c, 'unrar', trim(shell_exec('command -v unrar')), 1);
		foreach(array(array('unzip_file.sh', $unzip, "$c/in/a.zip"), array('unzip_dir.sh', $unzip, "$c/in/"),
			array('unrar_file.sh', $unrar, "$c/in/a.rar"), array('unrar_dir.sh', $unrar, "$c/in/")) as $call)
		{
			$run = unpackRun($call[0], array($call[1], $call[2], "$c/out/"));
			testAssertTrue($run[0] !== 0, $call[0] . ' fails: ' . json_encode($run));
		}
		chmod("$c/out", 0700);
		testAssertSame(array(), array_values(array_diff(array_merge(unpackCalls($c, 'unzip'), unpackCalls($c, 'unrar')),
			array('lt'))), 'nothing is extracted without a private directory, only listed');
		testAssertSame(array(), UnpackArchiveFixture::tree("$c/out"), 'and nothing is extracted');
	},
	'rar and zip: an archive without links is delivered whole' => function () {
		$c = unpackCase('legitimate');
		mkdir("$c/out/kept", 0700, true);
		file_put_contents("$c/out/kept/old.txt", 'old');
		// unzip drops a newline from a member's name; a directory of the
		// download keeps it, and so must its place in the output.
		$newline = "sub\n";
		mkdir("$c/in/$newline");
		UnpackArchiveFixture::zip("$c/in/z.zip", array(array('d', 'kept', ''), array('f', 'kept/a b', 'z'),
			array('f', '-dash', 'z'), array('d', 'ro', '', 040555), array('f', 'ro/f.txt', 'f', 0100444)));
		UnpackArchiveFixture::zip("$c/in/$newline/inner.zip", array(array('f', 'inner.txt', 'i')));
		UnpackArchiveFixture::rar("$c/in/r.rar", array(array('f', 'real', 'R'), array('h', 'twin', 'real'),
			array('f', 'kept/r.txt', 'r')));
		$run = unpackRun('unall_dir.sh', array('unrar', "$c/in/", "$c/out/", 'unzip'));
		testAssertSame(0, $run[0], 'both archives unpack: ' . json_encode($run));
		testAssertSame(array('-dash', 'kept', 'kept/a b', 'kept/old.txt', 'kept/r.txt', 'real', 'ro', 'ro/f.txt', $newline,
			"$newline/inner.txt", 'twin'), UnpackArchiveFixture::tree("$c/out"), 'every entry lands under its own name, beside what was there');
		clearstatcache();
		testAssertSame(2, stat("$c/out/twin")['nlink'], 'a hard link inside the archive stays one');
		$log = dirname($c) . '/errors.log';
		testAssertTrue(!is_file($log) || file_get_contents($log) === '', 'nothing is logged');
		unpackNothingOutside($run);
	},
	'rar: a directory without archives starts no placement' => function () {
		$c = unpackCase('empty');
		mkdir("$c/in/a/b", 0700, true);
		$run = unpackRun('unrar_dir.sh', array('unrar', "$c/in/", "$c/out/"), unpackRecorder($c, 'php', PHP_BINARY, 6));
		testAssertSame(0, $run[0], 'nothing to extract is not a failure: ' . json_encode($run));
		testAssertSame(array(), unpackCalls($c, 'php'), 'place.php is not run for an empty extraction');
		testAssertSame(array(), UnpackArchiveFixture::tree("$c/out"), 'and nothing is created in the output');
	},
);
try {
	$status = testRunCases($tests);
} finally {
	// An archive's read-only directory may be left where a run failed.
	exec('chmod -R u+w ' . escapeshellarg($base));
	PluginEntryDriver::removeTree($base);
}
exit($status);
