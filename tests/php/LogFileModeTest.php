<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/utility/fileutil.php');
require_once(__DIR__ . '/PhpProcess.php');

class LogFileModeStream
{
	public $context;
	public static $fileType;
	public static $exists;
	public static $metadataCalls;
	public static $openModes;
	public static $body;
	public static $refuseOpen;

	public static function reset($fileType, $exists = true)
	{
		clearstatcache(true, 'rutorrent-log-test://sink');
		self::$fileType = $fileType;
		self::$exists = $exists;
		self::$metadataCalls = array();
		self::$openModes = array();
		self::$body = '';
		self::$refuseOpen = false;
	}

	private static function statResult()
	{
		$mode = self::$fileType | 0600;
		$size = strlen(self::$body);
		return array(
			2 => $mode, 7 => $size,
			'mode' => $mode, 'size' => $size,
		);
	}

	public function url_stat($path, $flags)
	{
		return self::$exists ? self::statResult() : false;
	}

	public function stream_open($path, $mode, $options, &$openedPath)
	{
		self::$openModes[] = $mode;
		return !self::$refuseOpen;
	}

	public function stream_write($data)
	{
		self::$body .= $data;
		return strlen($data);
	}

	public function stream_flush()
	{
		return true;
	}

	public function stream_metadata($path, $option, $value)
	{
		self::$metadataCalls[] = $option;
		return true;
	}
}

/**
 * FileUtil::toLog() creates $log_file on first write. $profileMask is the only
 * knob an operator has for saying how permissive ruTorrent may be when it
 * creates a file that a second user may also have to write -- config.php
 * documents 0770 for "Webserver and rtorrent users are in the same group" --
 * and FileUtil::makeDirectory(), the method directly above toLog() in this same
 * class, already reads it. The log did not, so an operator who tightened the
 * mask got every profile artifact tightened and the log left at 0666.
 *
 * The mask is an upper bound, never a floor: at the 0777 default 0777 & 0666 is
 * 0666, so a default install is byte-for-byte unchanged and an rtorrent user
 * distinct from the web user keeps the write access the mode exists for.
 */
class LogFileModeTest extends TestCase
{
	// Logs one line with the plain-file wrapper replaced, keeping a thrown error
	// alive the way an error handler or a debugger may, and reports the wrapper's
	// calls, what was thrown and whether the lock is free while it is kept.
	const WRAPPED_CHILD = 'require $argv[1]; require $argv[2]; $GLOBALS["log_file"] = $argv[3];
LogFileWrapperFixture::install(json_decode($argv[4], true));
$caught = ""; $kept = null;
$started = microtime(true);
try { FileUtil::toLog("the line"); } catch (Throwable $e) { $kept = $e; $caught = get_class($e); }
$wall = microtime(true) - $started;
LogFileWrapperFixture::remove();
$probe = fopen($argv[3], "rb");
echo json_encode(array("calls" => LogFileWrapperFixture::$calls, "caught" => $caught, "wall" => $wall,
	"lockOperations" => LogFileWrapperFixture::$lockOperations,
	"lockFree" => function_exists("flock") && flock($probe, LOCK_EX | LOCK_NB)));';

	private $dir;

	public function setUp()
	{
		if (!in_array('rutorrent-log-test', stream_get_wrappers(), true)) {
			stream_wrapper_register('rutorrent-log-test', 'LogFileModeStream');
		}
		$this->dir = sys_get_temp_dir() . '/rutorrent-log-file-mode-test-' . getmypid();
		if (!is_dir($this->dir)) {
			mkdir($this->dir, 0777, true);
		}
		$this->clean();
	}

	public function tearDown()
	{
		$this->clean();
		if (is_dir($this->dir)) {
			rmdir($this->dir);
		}
		if (in_array('rutorrent-log-test', stream_get_wrappers(), true)) {
			stream_wrapper_unregister('rutorrent-log-test');
		}
	}

	private function clean()
	{
		foreach (glob($this->dir . '/*') as $path) {
			unlink($path);
		}
	}

	// toLog() only creates and chmods when the file is absent, so every case
	// starts from a path that does not exist yet.
	private function logAfterFirstWrite($mask, $name)
	{
		$this->clean();
		$GLOBALS['log_file'] = $this->dir . '/' . $name;
		if (is_null($mask)) {
			unset($GLOBALS['profileMask']);
		} else {
			$GLOBALS['profileMask'] = $mask;
		}
		FileUtil::toLog('probe');
		clearstatcache();
		return $GLOBALS['log_file'];
	}

	private function modeOf($file)
	{
		clearstatcache(true, $file);
		return fileperms($file) & 0777;
	}

	// Passes both before and after the change: the default must not move, or
	// every install where rtorrent runs as its own user loses its log.
	public function testDefaultMaskStillCreatesTheLogWorldWritable()
	{
		$log = $this->logAfterFirstWrite(0777, 'default.log');
		$this->assertTrue(is_file($log), 'toLog() creates the log file');
		$this->assertEquals(
			0666,
			$this->modeOf($log),
			'At the 0777 default the log is created 0666, exactly as before'
		);
	}

	// config.php's own worked example: web server and rtorrent share a group.
	public function testSharedGroupMaskDropsWorldAccess()
	{
		$log = $this->logAfterFirstWrite(0770, 'shared-group.log');
		$this->assertEquals(
			0660,
			$this->modeOf($log),
			'A 0770 mask creates the log 0660, so the group still writes it and the world does not'
		);
	}

	// One user for both roles: nothing outside it needs the log at all.
	public function testSingleUserMaskKeepsTheLogPrivate()
	{
		$log = $this->logAfterFirstWrite(0700, 'single-user.log');
		$this->assertEquals(
			0600,
			$this->modeOf($log),
			'A 0700 mask creates the log 0600'
		);
	}

	// The mask only ever removes bits. A mask with no write bits of its own
	// must not hand the log write bits back.
	public function testMaskIsAnUpperBoundNotAFloor()
	{
		// FileUtil::toLog() may warn when it opens the intentionally
		// read-only log under unprivileged PHP. Capture only that warning.
		set_error_handler(function($severity, $message) {
			return $severity === E_WARNING && strpos($message, 'read-only.log') !== false;
		});
		try {
			$log = $this->logAfterFirstWrite(0444, 'read-only.log');
		} finally {
			restore_error_handler();
		}
		$this->assertEquals(
			0444,
			$this->modeOf($log),
			'A 0444 mask cannot be widened to 0666'
		);
	}

	// toLog() may be reached before conf/config.php has been loaded, so an
	// unset mask must behave like the documented 0777 default rather than
	// chmod'ing the log to 0.
	public function testUnsetMaskFallsBackToTheDocumentedDefault()
	{
		$log = $this->logAfterFirstWrite(null, 'no-mask.log');
		$this->assertEquals(
			0666,
			$this->modeOf($log),
			'With no $profileMask set the log is created 0666'
		);
	}

	// Creation is the only moment toLog() sets a mode. An operator who chmods
	// an existing log keeps that mode.
	public function testAnExistingLogIsNotReChmodded()
	{
		$log = $this->logAfterFirstWrite(0777, 'existing.log');
		chmod($log, 0640);
		$GLOBALS['profileMask'] = 0700;
		FileUtil::toLog('second line');
		$this->assertEquals(
			0640,
			$this->modeOf($log),
			'An existing log keeps the mode it already had'
		);
	}

	// Whatever the mode, the log must still be a log.
	public function testTheLineIsStillWritten()
	{
		$log = $this->logAfterFirstWrite(0700, 'content.log');
		FileUtil::toLog('second line');
		$body = file_get_contents($log);
		$this->assertTrue(
			strpos($body, 'probe') !== false && strpos($body, 'second line') !== false,
			'Both lines are appended to the log'
		);
	}

	public function testAnOpenFailureStaysOutOfTheResponseButReachesThePhpErrorLog()
	{
		$errorLog = $this->dir . '/php-errors.log';
		$oldDisplayErrors = ini_get('display_errors');
		$oldLogErrors = ini_get('log_errors');
		$oldErrorLog = ini_get('error_log');
		ini_set('display_errors', false);
		ini_set('log_errors', true);
		ini_set('error_log', $errorLog);
		$GLOBALS['log_file'] = '/proc/version';
		ob_start();
		try {
			FileUtil::toLog('probe');
			$response = ob_get_contents();
		} finally {
			ob_end_clean();
			ini_set('display_errors', $oldDisplayErrors);
			ini_set('log_errors', $oldLogErrors);
			ini_set('error_log', $oldErrorLog);
		}
		$errors = is_file($errorLog) ? file_get_contents($errorLog) : '';
		$this->assertEquals('', $response, 'An unwritable log emits no response output');
		$this->assertTrue(
			strpos($errors, 'fopen(/proc/version)') !== false,
			'An unwritable log remains visible in the PHP error log'
		);
	}

	public function testAnExistingNonRegularLogIsNotTouchedOrReChmodded()
	{
		LogFileModeStream::reset(0010000); // FIFO
		$GLOBALS['log_file'] = 'rutorrent-log-test://sink';
		$GLOBALS['profileMask'] = 0777;
		FileUtil::toLog('probe');
		$this->assertEquals(
			array(),
			LogFileModeStream::$metadataCalls,
			'An existing non-regular log keeps its operator-assigned permissions'
		);
	}

	public function testAnExistingNonRegularFilesystemPathIsNotReChmodded()
	{
		$target = $this->dir . '/non-regular-target';
		mkdir($target, 0700);
		chmod($target, 0700);
		$GLOBALS['log_file'] = $target;
		$GLOBALS['profileMask'] = 0777;
		set_error_handler(function () { return true; });
		try {
			FileUtil::toLog('probe');
			$mode = $this->modeOf($target);
		} finally {
			restore_error_handler();
			chmod($target, 0700);
			rmdir($target);
		}
		$this->assertEquals(
			0700,
			$mode,
			'An existing non-regular filesystem target keeps its operator-assigned permissions'
		);
	}

	public function testARegularLogIsOpenedWriteOnly()
	{
		LogFileModeStream::reset(0100000); // Regular file
		$GLOBALS['log_file'] = 'rutorrent-log-test://sink';
		FileUtil::toLog('probe');
		$this->assertEquals(
			array('ab'),
			LogFileModeStream::$openModes,
			'A regular log does not require read permission just to append a line'
		);
	}

	// With fs.protected_regular (the default on Ubuntu and Debian), an open
	// that may create the file is refused on a file in a sticky world-writable
	// directory owned by someone else, whatever its mode. Here root opens a
	// file owned by nobody in a sticky directory owned by root, the same
	// shape as the web user and the rTorrent user sharing /tmp/errors.log.
	public function testAnotherUsersLogInAStickyDirectoryIsStillWritten()
	{
		$protected = @file_get_contents('/proc/sys/fs/protected_regular');
		if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || intval($protected) < 1) {
			echo "NOT CHECKED: needs root and fs.protected_regular\n";
			return;
		}
		$sticky = $this->dir . '/sticky';
		mkdir($sticky);
		chmod($sticky, 01777);
		$log = $sticky . '/shared.log';
		file_put_contents($log, "first\n");
		chmod($log, 0666);
		chown($log, 65534);
		$GLOBALS['log_file'] = $log;
		set_error_handler(function () { return true; });
		try {
			FileUtil::toLog('second user');
			$body = file_get_contents($log);
		} finally {
			restore_error_handler();
			unlink($log);
			rmdir($sticky);
		}
		$this->assertTrue(
			strpos($body, "first\n") === 0 && strpos($body, 'second user') !== false,
			'A log owned by another user in a sticky directory gets the line appended'
		);
	}

	// A log the writer may not read is still appended to.
	public function testAWriteOnlyLogIsStillWritten()
	{
		$log = $this->logAfterFirstWrite(0777, 'write-only.log');
		chmod($log, 0200);
		FileUtil::toLog('second line');
		chmod($log, 0600);
		$body = file_get_contents($log);
		$this->assertTrue(
			strpos($body, 'probe') !== false && strpos($body, 'second line') !== false,
			'A write-only log gets the line appended'
		);
	}

	// O_APPEND keeps each line whole and in place without a lock.
	public function testConcurrentWritersKeepEveryLine()
	{
		if (!function_exists('pcntl_fork')) {
			echo "NOT CHECKED: no pcntl_fork\n";
			return;
		}
		$writers = 4;
		$lines = 200;
		$log = $this->logAfterFirstWrite(0777, 'concurrent.log');
		$children = array();
		for ($i = 0; $i < $writers; $i++) {
			$pid = pcntl_fork();
			if ($pid === 0) {
				for ($n = 0; $n < $lines; $n++) {
					FileUtil::toLog("writer $i line $n");
				}
				posix_kill(getmypid(), SIGKILL);
			}
			$children[] = $pid;
		}
		foreach ($children as $pid) {
			pcntl_waitpid($pid, $status);
		}
		$written = file($log, FILE_IGNORE_NEW_LINES);
		$whole = preg_grep('/^\[[0-9-]+ [0-9:]+\] (probe|writer [0-9]+ line [0-9]+)$/', $written);
		$this->assertEquals(
			1 + $writers * $lines,
			count($whole),
			'Every line from concurrent writers is kept whole'
		);
	}

	private function seededLog($name)
	{
		$log = $this->dir . '/' . $name;
		file_put_contents($log, "seed\n");
		return $log;
	}

	// Runs WRAPPED_CHILD. A failure of the child itself is not a verdict on
	// toLog(), so it fails here, with what the child said.
	private function logThroughWrapper($log, $options, $ini = array())
	{
		$result = PhpProcess::run(array_merge(PhpProcess::sameIni(),
			array('-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=',
				'-d', 'zend.exception_ignore_args=0', '-d', 'xdebug.mode=off'), $ini,
			array('-r', self::WRAPPED_CHILD, realpath(__DIR__ . '/../../php/utility/fileutil.php'),
				__DIR__ . '/LogFileWrapperFixture.php', $log, json_encode((object)$options))),
			array('timeout' => 30));
		$report = json_decode($result['out'], true);
		$this->assertTrue(!$result['timedOut'] && $result['code'] === 0 && is_array($report),
			'The wrapped child reports: ' . json_encode($result));
		// Startup diagnostics ("in Unknown on line 0") belong to the PHP build.
		$report['err'] = implode("\n", preg_grep('/ in Unknown on line 0\s*$/',
			explode("\n", trim($result['err'])), PREG_GREP_INVERT));
		return $report;
	}

	private function assertLogHoldsTheLineAfter($before, $log, $label)
	{
		$body = file_get_contents($log);
		$this->assertTrue(preg_match('/^' . $before . '\[[0-9-]+ [0-9:]+\] the line\n$/', $body) === 1,
			$label . ': ' . json_encode($body));
	}

	// Between the open and the write another process appends a line. With
	// O_APPEND the write lands after it; a handle positioned by a seek would
	// write over it, whatever lock it holds, since error_log() takes none.
	public function testAnOrdinaryLogKeepsOAppend()
	{
		$log = $this->seededLog('o-append.log');
		$report = $this->logThroughWrapper($log, array('beforeWrite' => "other writer\n"));
		$this->assertEquals(array('open ab', 'write', 'close'), $report['calls'], 'An ordinary log is opened ab, once');
		$this->assertLogHoldsTheLineAfter("seed\nother writer\n", $log, 'A line written between the open and the write survives');
	}

	public function testAnOrdinaryLogDoesNotWaitForAHeldLock()
	{
		$log = $this->seededLog('held-ordinary.log');
		$holder = fopen($log, 'rb');
		flock($holder, LOCK_EX);
		try {
			$report = $this->logThroughWrapper($log, array());
		} finally {
			fclose($holder);
		}
		$this->assertEquals(array('open ab', 'write', 'close'), $report['calls'], 'An ordinary log asks for no lock');
		$this->assertLogHoldsTheLineAfter("seed\n", $log, 'The line is written past the held lock');
	}

	public function testARefusedAppendWritesAtTheEndUnderTheLock()
	{
		$log = $this->seededLog('refused.log');
		$report = $this->logThroughWrapper($log, array('refuse' => array('ab')));
		$this->assertEquals(array('open ab', 'open r+b', 'lock', 'seek to end', 'write', 'close'), $report['calls'],
			'A refused append is written at the end, under the lock');
		$this->assertEquals('', $report['err'], 'A log the fallback can write reports no failure');
		$this->assertLogHoldsTheLineAfter("seed\n", $log, 'The line follows what the log held');
	}

	public function testALogRefusedBothWaysFailsVisibly()
	{
		$log = $this->seededLog('refused-twice.log');
		$report = $this->logThroughWrapper($log, array('refuse' => array('ab', 'r+b')));
		$this->assertEquals(array('open ab', 'open r+b', 'open ab'), $report['calls'],
			'After both silent opens the original open runs');
		$this->assertEquals(1, substr_count($report['err'], 'fopen('),
			'The failure reaches the PHP error log once: ' . $report['err']);
		$this->assertEquals("seed\n", file_get_contents($log), 'The log is unchanged');
	}

	// A writer that stops while holding the lock must not hang every request
	// that logs: the fallback waits a bounded time and writes the line anyway.
	public function testAHeldLockDoesNotHangTheWriter()
	{
		$log = $this->seededLog('held-refused.log');
		$holder = fopen($log, 'rb');
		flock($holder, LOCK_EX);
		try {
			$report = $this->logThroughWrapper($log, array('refuse' => array('ab')));
		} finally {
			fclose($holder);
		}
		$counts = array_count_values($report['calls']);
		$this->assertEquals(100, $counts['lock'], 'The lock is asked for a bounded number of times');
		$this->assertEquals(array(LOCK_EX | LOCK_NB), array_values(array_unique($report['lockOperations'])),
			'Every attempt asks for the exclusive lock without blocking');
		// A sleep cannot run short, so a busy host cannot fail this lower bound.
		$this->assertTrue($report['wall'] >= 0.9, 'The writer waits between its attempts: ' . $report['wall']);
		$this->assertTrue(FileUtil::LOG_LOCK_ATTEMPTS * FileUtil::LOG_LOCK_WAIT_US <= 1000000,
			'A stopped holder costs a line at most a second');
		$this->assertLogHoldsTheLineAfter("seed\n", $log, 'The line is written after the bounded wait');
	}

	public function testALockThatCannotBeTakenIsNotWaitedFor()
	{
		$log = $this->seededLog('lock-fails.log');
		$report = $this->logThroughWrapper($log, array('refuse' => array('ab'), 'lockFails' => true));
		$this->assertEquals(array('open ab', 'open r+b', 'lock', 'seek to end', 'write', 'close'), $report['calls'],
			'Only another holder is worth waiting for');
		$this->assertLogHoldsTheLineAfter("seed\n", $log, 'The line is written without the lock');
	}

	// Each case holds the lock for real: only a contended lock reaches usleep().
	public function testAnOrdinaryLogNeedsNoFunctionOfTheLock()
	{
		foreach (array('flock', 'fseek', 'usleep') as $function) {
			$log = $this->seededLog('ordinary-without-' . $function . '.log');
			$holder = fopen($log, 'rb');
			flock($holder, LOCK_EX);
			try {
				$report = $this->logThroughWrapper($log, array(), array('-d', 'disable_functions=' . $function));
			} finally {
				fclose($holder);
			}
			$this->assertEquals('', $report['caught'], 'Without ' . $function . '() nothing is thrown');
			$this->assertEquals('', $report['err'], 'Without ' . $function . '() nothing is reported');
			$this->assertLogHoldsTheLineAfter("seed\n", $log, 'Without ' . $function . '() the line is appended');
		}
	}

	/**
	 * Without a function the lock needs the fallback is not taken, and the
	 * refusal stays what it was before the fallback existed: on PHP 8 a
	 * disabled function would throw, and on 7.4 a fseek() returning NULL would
	 * write the line over the start of the log.
	 */
	public function testARefusedAppendWithoutAFunctionOfTheLockFailsVisibly()
	{
		foreach (array('flock', 'fseek', 'usleep') as $function) {
			$log = $this->seededLog('refused-without-' . $function . '.log');
			$holder = fopen($log, 'rb');
			flock($holder, LOCK_EX);
			try {
				$report = $this->logThroughWrapper($log, array('refuse' => array('ab')),
					array('-d', 'disable_functions=' . $function));
			} finally {
				fclose($holder);
			}
			$this->assertEquals('', $report['caught'], 'Without ' . $function . '() nothing is thrown');
			$this->assertEquals(array('open ab', 'open ab'), $report['calls'], 'Without ' . $function . '() no r+b is opened');
			$this->assertTrue(strpos($report['err'], 'fopen(') !== false,
				'Without ' . $function . '() the failure reaches the PHP error log: ' . $report['err']);
			$this->assertEquals("seed\n", file_get_contents($log), 'Without ' . $function . '() the log is unchanged');
		}
	}

	// The thrown error's trace holds the handle as an argument of fputs(), so
	// only an explicit close releases the lock while that error is alive.
	public function testAThrowingWriteReleasesTheLock()
	{
		$log = $this->seededLog('throwing-write.log');
		$report = $this->logThroughWrapper($log, array('refuse' => array('ab'), 'throwOnWrite' => true));
		$this->assertEquals('RuntimeException', $report['caught'], 'The write threw');
		$this->assertTrue($report['lockFree'], 'The lock is released while the error is alive');
	}

	public function testAFifoOnTheFilesystemKeepsTheOldMode()
	{
		$log = $this->seededLog('fifo.log');
		$report = $this->logThroughWrapper($log, array('fifo' => true));
		$this->assertEquals(array('open ab+', 'write', 'close'), $report['calls'],
			'A write-only open of a FIFO would block until a reader appears');
	}

	// The fallback is for a local file's refused O_CREAT, not for a stream.
	public function testARefusedStreamIsNotOpenedAnotherWay()
	{
		LogFileModeStream::reset(0100000); // Regular file
		LogFileModeStream::$refuseOpen = true;
		$GLOBALS['log_file'] = 'rutorrent-log-test://sink';
		set_error_handler(function () { return true; });
		try {
			FileUtil::toLog('probe');
		} finally {
			restore_error_handler();
		}
		$this->assertEquals(array('ab'), LogFileModeStream::$openModes, 'A refused stream gets one open');
	}

	public function testANonRegularLogKeepsTheNonBlockingReadWriteMode()
	{
		LogFileModeStream::reset(0010000); // FIFO
		$GLOBALS['log_file'] = 'rutorrent-log-test://sink';
		FileUtil::toLog('probe');
		$this->assertEquals(
			array('ab+'),
			LogFileModeStream::$openModes,
			'A non-regular log keeps the mode that does not wait forever for a FIFO reader'
		);
	}

	public function testARelativeLogPathIsRejectedBeforeItCanSplitByWorkingDirectory()
	{
		$this->clean();
		$GLOBALS['log_file'] = 'relative.log';
		$oldDirectory = getcwd();
		$warning = '';
		chdir($this->dir);
		set_error_handler(function ($number, $message) use (&$warning) {
			$warning = $message;
			return true;
		});
		try {
			FileUtil::toLog('probe');
		} finally {
			restore_error_handler();
			chdir($oldDirectory);
		}
		$this->assertTrue(
			!file_exists($this->dir . '/relative.log'),
			'A relative log path cannot create different files from different entry-point directories'
		);
		$this->assertTrue(
			strpos($warning, '$log_file must be an absolute path') !== false,
			'An invalid $log_file names the configuration error and its consequence'
		);
	}

	public function testAnUnstatableStreamSkipsFilesystemMetadataAndOpensWriteOnly()
	{
		LogFileModeStream::reset(0100000, false);
		$GLOBALS['log_file'] = 'rutorrent-log-test://sink';
		FileUtil::toLog('probe');
		$this->assertEquals(
			array(),
			LogFileModeStream::$metadataCalls,
			'A stream URI is not touched or chmodded like a filesystem path'
		);
		$this->assertEquals(
			array('ab'),
			LogFileModeStream::$openModes,
			'A stream URI only needs write access to append a line'
		);
	}

	public function testBuiltinStreamDoesNotEmitFilesystemMetadataWarnings()
	{
		$GLOBALS['log_file'] = 'php://temp';
		$warning = '';
		set_error_handler(function ($number, $message) use (&$warning) {
			$warning .= $message;
			return true;
		});
		try {
			FileUtil::toLog('probe');
		} finally {
			restore_error_handler();
		}
		$this->assertEquals('', $warning, 'A built-in stream log emits no touch or chmod warning');
	}

	public function testFileStreamHonorsTheSameMaskAsAPlainPath()
	{
		$target = $this->dir . '/file-stream.log';
		$GLOBALS['log_file'] = 'file://' . $target;
		$GLOBALS['profileMask'] = 0700;
		$oldMask = umask(0);
		try {
			FileUtil::toLog('probe');
		} finally {
			umask($oldMask);
		}
		$this->assertTrue(is_file($target), 'A file URI creates its local log target');
		$this->assertEquals(
			0600,
			$this->modeOf($target),
			'A file URI obeys the same profile mask as a plain filesystem path'
		);
	}

	public function testLocalhostFileStreamUsesAnAbsoluteLocalTarget()
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			return;
		}
		$target = $this->dir . '/localhost-file-stream.log';
		$GLOBALS['log_file'] = 'file://localhost' . $target;
		$GLOBALS['profileMask'] = 0700;
		FileUtil::toLog('probe');
		$this->assertTrue(is_file($target), 'A localhost file URI writes to its absolute local target');
		$this->assertEquals(0600, $this->modeOf($target), 'A localhost file URI obeys the profile mask');
	}

	public function testRelativeFileStreamIsRejectedAtRuntime()
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			return;
		}
		$GLOBALS['log_file'] = 'file://relative/path';
		$warning = '';
		set_error_handler(function ($number, $message) use (&$warning) {
			$warning = $message;
			return true;
		});
		try {
			FileUtil::toLog('probe');
		} finally {
			restore_error_handler();
		}
		$this->assertTrue(
			strpos($warning, '$log_file must be an absolute path') !== false,
			'A relative file URI is visibly rejected by the runtime parser'
		);
	}

	public function testAWindowsPathIsRelativeOnUnix()
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			return;
		}
		$this->clean();
		$GLOBALS['log_file'] = 'C:\\logs\\rutorrent.log';
		$oldDirectory = getcwd();
		chdir($this->dir);
		set_error_handler(function () { return true; });
		try {
			FileUtil::toLog('probe');
		} finally {
			restore_error_handler();
			chdir($oldDirectory);
		}
		$this->assertTrue(
			!file_exists($this->dir . '/C:\\logs\\rutorrent.log'),
			'A Windows drive path cannot become a relative filename on Unix'
		);
	}
}
