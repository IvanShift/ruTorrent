<?php

/**
 * The manual "check for update" route, exercised through its real entrypoints.
 *
 * The scheduler reaches ruTrackerChecker::run() through update.php; this is the
 * other door. A user picks torrents in the UI, the browser POSTs them to
 * action.php, and action.php hands them to a detached batch_check.php worker
 * through a file in the temp directory.
 *
 * Every case here copies the production entrypoint byte for byte and stubs only
 * what sits below it: the utility classes, the PHP binary and the checker. No
 * case reimplements the entrypoint, so a test can only pass because the shipped
 * file behaves, and a test that names a class the shipped file does not define
 * would fail rather than describe a boundary that is not there.
 *
 * The launched child is a recorder rather than a real worker, so these cases
 * observe the exact argument vector the shell accepted without running a check
 * against a tracker.
 */

require __DIR__ . '/TestLib.php';

// The dispatch helper, when the tree has one. Loading it conditionally keeps
// every other case in this file runnable against a tree that does not, so the
// wrong behaviour they describe stays reproducible; the one case that needs the
// helper asserts its presence and fails by name rather than by fatal.
$manualLauncher = testFindRepoRoot() . '/plugins/rutracker_check/launcher.php';
if(is_file($manualLauncher))
	require_once($manualLauncher);

class ManualEntrypointsTest
{
	private $root = null;

	public function __construct()
	{
		$this->root = testFindRepoRoot();
	}

	// ---------------------------------------------------------------- helpers

	private function makeTree()
	{
		$tree = sys_get_temp_dir() . '/rutorrent-manual-' . uniqid('', true);
		if(!mkdir($tree, 0700, true) && !is_dir($tree))
			throw new RuntimeException('could not create the fixture tree');
		foreach(array('plugins/rutracker_check', 'php', 'rec', 'tmp') as $sub)
			if(!mkdir($tree . '/' . $sub, 0700, true))
				throw new RuntimeException('could not create ' . $sub);

		// The production entrypoints, copied rather than described. launcher.php
		// is copied when the tree has one, so this fixture works both before and
		// after the entrypoints grow a shared helper.
		$copied = 0;
		foreach(array('action.php', 'batch_check.php', 'launcher.php') as $name)
		{
			$source = $this->root . '/plugins/rutracker_check/' . $name;
			if(!is_file($source))
				continue;
			if(!copy($source, $tree . '/plugins/rutracker_check/' . $name))
				throw new RuntimeException('could not copy ' . $name);
			$copied++;
		}
		if($copied < 2)
			throw new RuntimeException('the production manual entrypoints were not found');

		$this->writeUtilStub($tree);
		$this->writeRecorder($tree);
		return $tree;
	}

	/**
	 * Stand-ins for the utility classes action.php requires, below the boundary
	 * under test. CachedEcho keeps the real contract -- it writes the body and
	 * exits -- because the response bytes and the status line are the thing
	 * being measured.
	 *
	 * Generated source deliberately uses dirname(__FILE__): the suite runner
	 * rewrites the token __DIR__ in this file's text before executing it, and a
	 * generated file must not inherit that rewrite.
	 */
	private function writeUtilStub($tree)
	{
		$source = '<?php' . "\n"
			. 'class FileUtil' . "\n"
			. '{' . "\n"
			. '	public static function getTempDirectory()' . "\n"
			. '	{' . "\n"
			. '		return getenv("MANUAL_TEST_TEMPDIR");' . "\n"
			. '	}' . "\n"
			. '	public static function toLog($message)' . "\n"
			. '	{' . "\n"
			. '		file_put_contents(getenv("MANUAL_TEST_LOG"), $message . "\n", FILE_APPEND);' . "\n"
			. '	}' . "\n"
			. '	public static function getPluginConf($name)' . "\n"
			. '	{' . "\n"
			. '		return "";' . "\n"
			. '	}' . "\n"
			. '}' . "\n"
			. 'class Utility' . "\n"
			. '{' . "\n"
			. '	public static function getPHP()' . "\n"
			. '	{' . "\n"
			. '		return getenv("MANUAL_TEST_PHP");' . "\n"
			. '	}' . "\n"
			. '}' . "\n"
			. 'class User' . "\n"
			. '{' . "\n"
			. '	public static function getUser()' . "\n"
			. '	{' . "\n"
			. '		return getenv("MANUAL_TEST_USER");' . "\n"
			. '	}' . "\n"
			. '}' . "\n"
			. 'class CachedEcho' . "\n"
			. '{' . "\n"
			. '	public static function send($content, $type = null, $cacheable = false, $exit = true)' . "\n"
			. '	{' . "\n"
			. '		if(!is_null($type))' . "\n"
			. '			header("Content-Type: " . $type . "; charset=UTF-8");' . "\n"
			. '		if($exit)' . "\n"
			. '			exit($content);' . "\n"
			. '		echo($content);' . "\n"
			. '	}' . "\n"
			. '}' . "\n";
		if(file_put_contents($tree . '/php/util.php', $source) === false)
			throw new RuntimeException('could not write the utility stub');
	}

	/**
	 * The detached child. It records the exact argument vector it was handed and
	 * a copy of the handover file, which is what proves the launch was built and
	 * quoted correctly without running a real check.
	 */
	private function writeRecorder($tree)
	{
		// The argument vector is written last and renamed into place, so it is
		// the completion marker: a reader that sees argv.<id> is guaranteed to
		// see the handover copy that belongs to it.
		$script = "#!/bin/sh\n"
			. "rec=" . escapeshellarg($tree . '/rec') . "\n"
			. "id=$$\n"
			. "if [ -f \"\$3\" ]; then cp \"\$3\" \"\$rec/handover.\$id\"; fi\n"
			. "printf '%s\\n' \"\$@\" > \"\$rec/argv.\$id.part\"\n"
			. "mv \"\$rec/argv.\$id.part\" \"\$rec/argv.\$id\"\n"
			. "exit 0\n";
		$path = $tree . '/php-recorder';
		if(file_put_contents($path, $script) === false)
			throw new RuntimeException('could not write the recorder');
		chmod($path, 0700);
	}

	private function recordedLaunches($tree)
	{
		$found = array();
		foreach((array) glob($tree . '/rec/argv.*') as $file)
		{
			if(substr($file, -5) === '.part')
				continue;
			$lines = file($file, FILE_IGNORE_NEW_LINES);
			if($lines === false)
				continue;
			$id = substr($file, strrpos($file, '.') + 1);
			$handover = $tree . '/rec/handover.' . $id;
			$found[] = array(
				'argv' => $lines,
				'handover' => is_file($handover) ? file_get_contents($handover) : null,
			);
		}
		return $found;
	}

	private function logLines($tree)
	{
		$path = $tree . '/log.txt';
		if(!is_file($path))
			return array();
		$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		return ($lines === false) ? array() : $lines;
	}

	private function reservePort()
	{
		$socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		if($socket === false)
			throw new RuntimeException('could not reserve a loopback port: ' . $errstr);
		$name = stream_socket_get_name($socket, false);
		fclose($socket);
		return (int) substr($name, strrpos($name, ':') + 1);
	}

	/**
	 * POST a body to the copied action.php and return the status, the body, the
	 * classified log, and whatever the detached child recorded.
	 *
	 * $options: php (the binary the entrypoint launches), tempdir, expect_child.
	 */
	private function postToAction($tree, $body, $options = array())
	{
		$php = isset($options['php']) ? $options['php'] : ($tree . '/php-recorder');
		$tempdir = isset($options['tempdir']) ? $options['tempdir'] : ($tree . '/tmp');
		$expectChild = isset($options['expect_child']) ? $options['expect_child'] : true;

		$before = count($this->recordedLaunches($tree));
		$port = $this->reservePort();
		$environment = array_merge($_ENV, array(
			'MANUAL_TEST_TEMPDIR' => $tempdir,
			'MANUAL_TEST_LOG' => $tree . '/log.txt',
			'MANUAL_TEST_PHP' => $php,
			'MANUAL_TEST_USER' => 'tester',
			'PATH' => getenv('PATH'),
		));
		// exec, so the shell replaces itself with PHP instead of staying its
		// parent. proc_terminate() signals the process proc_open() started, and
		// without this that process is /bin/sh: the shell dies, the server it
		// spawned is reparented to init and keeps its port and its memory.
		$command = 'exec ' . escapeshellarg(PHP_BINARY)
			. ' -d display_errors=0 -d error_reporting=0'
			. ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($tree);
		$process = proc_open($command, array(
			0 => array('pipe', 'r'),
			1 => array('file', $tree . '/server.out', 'a'),
			2 => array('file', $tree . '/server.err', 'a'),
		), $pipes, $tree, $environment);
		if(!is_resource($process))
			throw new RuntimeException('could not start the copied entrypoint server');
		try
		{
			fclose($pipes[0]);
			$this->waitForServer($port);
			$response = $this->rawPost($port, '/plugins/rutracker_check/action.php', $body);
		}
		finally
		{
			@proc_terminate($process);
			@proc_close($process);
		}

		// The child is detached, so it lands after the response. Wait for it when
		// one is expected; when none is expected, give it the same window before
		// concluding that nothing was launched.
		$deadline = microtime(true) + ($expectChild ? 5.0 : 1.0);
		while(microtime(true) < $deadline)
		{
			if(count($this->recordedLaunches($tree)) > $before)
				break;
			usleep(20000);
		}

		$response['launches'] = $this->recordedLaunches($tree);
		$response['logs'] = $this->logLines($tree);
		return $response;
	}

	private function waitForServer($port)
	{
		$deadline = microtime(true) + 10.0;
		while(microtime(true) < $deadline)
		{
			$socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.2);
			if($socket !== false)
			{
				fclose($socket);
				return;
			}
			usleep(20000);
		}
		throw new RuntimeException('the copied entrypoint server did not accept connections');
	}

	private function rawPost($port, $path, $body)
	{
		$socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 10.0);
		if($socket === false)
			throw new RuntimeException('could not connect to the copied entrypoint: ' . $errstr);
		$request = "POST " . $path . " HTTP/1.0\r\n"
			. "Host: 127.0.0.1\r\n"
			. "Content-Type: application/x-www-form-urlencoded\r\n"
			. "Content-Length: " . strlen($body) . "\r\n"
			. "Connection: close\r\n\r\n" . $body;
		fwrite($socket, $request);
		$raw = '';
		stream_set_timeout($socket, 20);
		while(!feof($socket))
		{
			$chunk = fread($socket, 65536);
			if($chunk === false || $chunk === '')
				break;
			$raw .= $chunk;
		}
		fclose($socket);
		$split = strpos($raw, "\r\n\r\n");
		if($split === false)
			throw new RuntimeException('the copied entrypoint returned no header boundary');
		$head = substr($raw, 0, $split);
		$lines = explode("\r\n", $head);
		$status = 0;
		if(preg_match('`^HTTP/\d\.\d\s+(\d+)`', $lines[0], $matches))
			$status = (int) $matches[1];
		return array('status' => $status, 'head' => $head, 'body' => substr($raw, $split + 4));
	}

	/** Run the copied batch_check.php worker over a handover file. */
	private function runWorker($tree, $hashes, $throwOn = null)
	{
		$checker = '<?php' . "\n"
			. 'class ruTrackerChecker' . "\n"
			. '{' . "\n"
			. '	public static function run($hash)' . "\n"
			. '	{' . "\n"
			. '		file_put_contents(getenv("MANUAL_TEST_CHECKED"), $hash . "\n", FILE_APPEND);' . "\n"
			. '		if($hash === getenv("MANUAL_TEST_THROW"))' . "\n"
			. '			throw new RuntimeException("checker blew up on " . $hash);' . "\n"
			. '	}' . "\n"
			. '	public static function logDebug($message)' . "\n"
			. '	{' . "\n"
			. '		file_put_contents(getenv("MANUAL_TEST_LOG"), $message . "\n", FILE_APPEND);' . "\n"
			. '	}' . "\n"
			. '}' . "\n"
			// The worker may hand the crawl to a forum index owned by another
			// package. A stub keeps that seam from deciding this result.
			. 'class RuTrackerForumIndex' . "\n"
			. '{' . "\n"
			. '	public static function spawnCrawl()' . "\n"
			. '	{' . "\n"
			. '		return true;' . "\n"
			. '	}' . "\n"
			. '}' . "\n";
		if(file_put_contents($tree . '/plugins/rutracker_check/check.php', $checker) === false)
			throw new RuntimeException('could not write the checker stub');

		$handover = $tree . '/tmp/handover-' . uniqid('', true);
		if(file_put_contents($handover, serialize($hashes)) === false)
			throw new RuntimeException('could not write the handover');

		$checked = $tree . '/checked.txt';
		@unlink($checked);
		$environment = array_merge($_ENV, array(
			'MANUAL_TEST_CHECKED' => $checked,
			'MANUAL_TEST_THROW' => ($throwOn === null) ? '' : $throwOn,
			'MANUAL_TEST_LOG' => $tree . '/log.txt',
			'PATH' => getenv('PATH'),
		));
		$command = escapeshellarg(PHP_BINARY)
			. ' -d display_errors=0 -d error_reporting=0 -f '
			. escapeshellarg($tree . '/plugins/rutracker_check/batch_check.php')
			. ' ' . escapeshellarg($handover) . ' ' . escapeshellarg('tester');
		$process = proc_open($command, array(
			0 => array('pipe', 'r'),
			1 => array('file', $tree . '/worker.out', 'a'),
			2 => array('file', $tree . '/worker.err', 'a'),
		), $pipes, $tree, $environment);
		if(!is_resource($process))
			throw new RuntimeException('could not start the copied worker');
		fclose($pipes[0]);
		$status = proc_close($process);

		$lines = is_file($checked) ? file($checked, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
		return array(
			'exit' => $status,
			'checked' => ($lines === false) ? array() : $lines,
			'handover' => $handover,
			'handover_exists' => is_file($handover),
			'logs' => $this->logLines($tree),
			'stderr' => is_file($tree . '/worker.err') ? file_get_contents($tree . '/worker.err') : '',
		);
	}

	private function decode($response)
	{
		$decoded = json_decode($response['body'], true);
		strictAssertTrue(is_array($decoded),
			'the manual endpoint answers with a JSON object; got ' . var_export($response['body'], true));
		return $decoded;
	}

	private function hashBody($hashes)
	{
		$parts = array();
		foreach($hashes as $hash)
			$parts[] = 'hash=' . $hash;
		return 'cmd=check&' . implode('&', $parts);
	}

	private function hash($seed)
	{
		return strtoupper(substr(str_repeat(dechex($seed % 16), 40), 0, 40));
	}

	// ------------------------------------------------------------------ cases

	/**
	 * Two manual checks started in the same second must not share one handover
	 * file. A name built from the user and the wall clock second collides, and
	 * the second write lands on top of the first: one of the two batches is
	 * lost, and whichever child runs first deletes the file under the other.
	 */
	public function testTwoChecksInOneSecondGetSeparateHandovers()
	{
		$tree = $this->makeTree();
		try
		{
			$attempts = 0;
			while(true)
			{
				$attempts++;
				// Start just after a second boundary so both requests are served
				// inside one second; the precondition is asserted below rather
				// than assumed, so this can never pass vacuously.
				usleep((int) ((1.0 - (microtime(true) - floor(microtime(true)))) * 1000000) + 20000);
				$startSecond = (int) time();
				$first = $this->postToAction($tree, $this->hashBody(array($this->hash(1))));
				$second = $this->postToAction($tree, $this->hashBody(array($this->hash(2))));
				$endSecond = (int) time();
				if($startSecond === $endSecond)
					break;
				if($attempts >= 5)
					throw new RuntimeException('could not serve two manual checks inside one second');
				foreach((array) glob($tree . '/rec/*') as $stale)
					@unlink($stale);
			}

			strictAssertSame(1, count($first['launches']), 'the first manual check launches one worker');
			strictAssertSame(2, count($second['launches']), 'the second manual check launches a worker too');

			$paths = array();
			foreach($second['launches'] as $launch)
			{
				strictAssertTrue(count($launch['argv']) >= 3,
					'the launched worker is handed a handover path');
				$paths[] = $launch['argv'][2];
			}
			strictAssertSame(2, count(array_unique($paths)),
				'two manual checks started in the same second use two distinct handover files');
		}
		finally
		{
			strictRemoveTree($tree);
		}
	}

	/**
	 * A handover the endpoint could not write must not be reported as queued,
	 * and must not launch a worker that would find nothing to do.
	 */
	public function testAnUnwritableHandoverIsRefusedRatherThanQueued()
	{
		$tree = $this->makeTree();
		try
		{
			// A temp directory that is not a directory at all: nothing can be
			// created inside it, on any platform, without depending on modes.
			$blocked = $tree . '/not-a-directory';
			if(file_put_contents($blocked, 'x') === false)
				throw new RuntimeException('could not create the blocked temp path');

			$response = $this->postToAction($tree, $this->hashBody(array($this->hash(3))),
				array('tempdir' => $blocked, 'expect_child' => false));

			$decoded = $this->decode($response);
			strictAssertTrue(isset($decoded['status']), 'the refusal answer carries a status');
			strictAssertTrue($decoded['status'] !== 'queued',
				'a manual check whose handover could not be written is not reported as queued');
			strictAssertSame(0, count($response['launches']),
				'a manual check whose handover could not be written launches no worker');
			strictAssertTrue(count(strictLogsMatching($response['logs'], 'rutracker_check')) > 0,
				'a refused manual check writes a classified log line');
		}
		finally
		{
			strictRemoveTree($tree);
		}
	}

	/**
	 * The PHP binary is a configured string, not a literal. Interpolated into a
	 * shell command unquoted, a path containing a space is split into two words
	 * and the worker never starts -- while the caller is still told the batch
	 * was accepted.
	 */
	public function testAPhpPathContainingASpaceStillLaunchesTheWorker()
	{
		$tree = $this->makeTree();
		try
		{
			$directory = $tree . '/bin dir';
			if(!mkdir($directory, 0700, true))
				throw new RuntimeException('could not create the spaced directory');
			$spaced = $directory . '/php-recorder';
			if(!copy($tree . '/php-recorder', $spaced))
				throw new RuntimeException('could not place the spaced recorder');
			chmod($spaced, 0700);

			$response = $this->postToAction($tree, $this->hashBody(array($this->hash(4))),
				array('php' => $spaced));

			strictAssertSame(1, count($response['launches']),
				'a PHP path containing a space is quoted, so the worker still starts');
		}
		finally
		{
			strictRemoveTree($tree);
		}
	}

	/**
	 * A launch the shell did not accept is a handled refusal, not a success.
	 * Nothing below this endpoint reports it, so an unobserved launch leaves the
	 * user with a queued answer and no check.
	 */
	public function testALaunchTheShellRefusedIsNotReportedAsQueued()
	{
		$tree = $this->makeTree();
		try
		{
			$response = $this->postToAction($tree, $this->hashBody(array($this->hash(5))),
				array('php' => $tree . '/no-such-binary', 'expect_child' => false));

			$decoded = $this->decode($response);
			strictAssertTrue(isset($decoded['status']), 'the refusal answer carries a status');
			strictAssertTrue($decoded['status'] !== 'queued',
				'a manual check whose worker could not be launched is not reported as queued');
			strictAssertTrue(count(strictLogsMatching($response['logs'], 'rutracker_check')) > 0,
				'a launch refusal writes a classified log line');
		}
		finally
		{
			strictRemoveTree($tree);
		}
	}

	/** A body far larger than any real selection is refused without launching. */
	public function testAnOversizedBodyIsRefusedWithoutLaunchingAWorker()
	{
		$tree = $this->makeTree();
		try
		{
			$hashes = array();
			for($i = 0; $i < 40000; $i++)
				$hashes[] = $this->hash($i);
			$body = $this->hashBody($hashes);
			strictAssertTrue(strlen($body) > 1000000, 'the oversized body really is oversized');

			$response = $this->postToAction($tree, $body, array('expect_child' => false));

			$decoded = $this->decode($response);
			strictAssertTrue(isset($decoded['status']), 'the oversized answer carries a status');
			strictAssertTrue($decoded['status'] !== 'queued',
				'an oversized manual request is not reported as queued');
			strictAssertSame(0, count($response['launches']),
				'an oversized manual request launches no worker');
		}
		finally
		{
			strictRemoveTree($tree);
		}
	}

	/**
	 * Whatever follows hash= reaches the worker and is handed to the checker.
	 * A torrent hash is 40 hex characters; anything else is not a hash this
	 * route can act on and must not travel any further.
	 */
	public function testOnlyRealHashesReachTheWorker()
	{
		$tree = $this->makeTree();
		try
		{
			$valid = $this->hash(6);
			$body = 'cmd=check&hash=' . $valid
				. '&hash=not-a-hash'
				. '&hash=' . strtolower($valid)
				. '&hash=' . substr($valid, 0, 39)
				. '&hash=';

			$response = $this->postToAction($tree, $body);
			strictAssertSame(1, count($response['launches']), 'the valid hash still queues a worker');

			$handover = $response['launches'][0]['handover'];
			strictAssertTrue(is_string($handover), 'the handover the worker received was captured');
			$hashes = unserialize($handover, array('allowed_classes' => false));
			strictAssertTrue(is_array($hashes), 'the handover holds a list');

			foreach($hashes as $hash)
				strictAssertTrue(is_string($hash) && preg_match('`^[0-9A-Fa-f]{40}$`', $hash) === 1,
					'every hash handed to the worker is a 40 character hex hash; got '
					. var_export($hash, true));
			strictAssertSame(1, count($hashes),
				'the duplicate spelling of one hash is handed over once');
		}
		finally
		{
			strictRemoveTree($tree);
		}
	}

	/**
	 * One torrent that throws must not cancel the rest of the batch. The user
	 * selected all of them, and a single unreachable tracker is the ordinary
	 * case rather than a reason to abandon the others.
	 */
	public function testOneFailingHashDoesNotCancelTheRestOfTheBatch()
	{
		$tree = $this->makeTree();
		try
		{
			$hashes = array($this->hash(7), $this->hash(8), $this->hash(9));
			$result = $this->runWorker($tree, $hashes, $hashes[0]);

			strictAssertTrue(strpos($result['stderr'], 'Fatal error') === false,
				'the worker did not fatal: ' . $result['stderr']);
			strictAssertSame(3, count($result['checked']),
				'every selected torrent is still checked after one of them throws');
		}
		finally
		{
			strictRemoveTree($tree);
		}
	}

	/**
	 * The handover is the worker's own temporary file. Left behind, it
	 * accumulates in the temp directory for every failed batch, forever.
	 */
	public function testTheHandoverIsRemovedEvenWhenAHashThrows()
	{
		$tree = $this->makeTree();
		try
		{
			$hashes = array($this->hash(10), $this->hash(11));
			$result = $this->runWorker($tree, $hashes, $hashes[0]);

			strictAssertTrue(!$result['handover_exists'],
				'the handover file is removed even when a checked torrent throws');
		}
		finally
		{
			strictRemoveTree($tree);
		}
	}

	/**
	 * A refusal the user can act on has to say which refusal it was. The routine
	 * log carries a classified reason, and it stays plain English -- it must not
	 * carry the exception text a third party controls.
	 */
	public function testAWorkerFailureIsLoggedAsAClassifiedEnglishReason()
	{
		$tree = $this->makeTree();
		try
		{
			$hashes = array($this->hash(12));
			$result = $this->runWorker($tree, $hashes, $hashes[0]);

			$matching = strictLogsMatching($result['logs'], 'batch_check');
			strictAssertTrue(count($matching) > 0,
				'a torrent that throws is reported in the log');
			foreach($matching as $line)
			{
				strictAssertEnglish($line, 'the worker log line is classified English');
				strictAssertTrue(strpos($line, 'blew up on') === false,
					'the worker log carries a classified reason, not the raw exception text: ' . $line);
			}
		}
		finally
		{
			strictRemoveTree($tree);
		}
	}

	/**
	 * The browser cannot tell a queued batch from a refused one when both answer
	 * with the same body, so the user is told a check is running when none is.
	 */
	public function testAQueuedBatchAndARefusedBatchAnswerDifferently()
	{
		$tree = $this->makeTree();
		try
		{
			$queued = $this->postToAction($tree, $this->hashBody(array($this->hash(13))));
			$refused = $this->postToAction($tree, $this->hashBody(array($this->hash(14))),
				array('php' => $tree . '/no-such-binary', 'expect_child' => false));

			$queuedBody = $this->decode($queued);
			$refusedBody = $this->decode($refused);

			strictAssertSame('queued', isset($queuedBody['status']) ? $queuedBody['status'] : null,
				'an accepted manual check reports that it was queued');
			strictAssertTrue($queuedBody != $refusedBody,
				'a refused manual check does not answer exactly like an accepted one');
		}
		finally
		{
			strictRemoveTree($tree);
		}
	}

	/**
	 * A request with nothing to act on is answered, not queued. It is also not
	 * an rTorrent failure: the browser reaches this endpoint through
	 * getTorrents(), whose error callback marks rTorrent as stopped, so a
	 * non-2xx answer here reports the daemon as down over a request the daemon
	 * never saw.
	 */
	public function testAnEmptySelectionIsAnsweredWithoutClaimingRtorrentIsDown()
	{
		$tree = $this->makeTree();
		try
		{
			$response = $this->postToAction($tree, 'cmd=check', array('expect_child' => false));

			strictAssertSame(200, $response['status'],
				'the manual endpoint answers 2xx so the UI does not mark rTorrent stopped');
			$decoded = $this->decode($response);
			strictAssertTrue(isset($decoded['status']) && $decoded['status'] !== 'queued',
				'a selection with no usable hash is not reported as queued');
			strictAssertSame(0, count($response['launches']),
				'a selection with no usable hash launches no worker');
		}
		finally
		{
			strictRemoveTree($tree);
		}
	}

	/**
	 * A write that stored only part of the selection is not a write that
	 * succeeded. file_put_contents() and fwrite() both report the bytes they
	 * managed to store, and a truncated handover unserializes to false in the
	 * child, which then checks nothing and says nothing -- while the user was
	 * told the batch was queued.
	 *
	 * A filesystem that fills up mid-write cannot be arranged here without root,
	 * so the byte count is driven through the dispatch helper's own writer seam,
	 * the same seam the launcher, logger and deleter already use.
	 */
	public function testAnIncompleteHandoverWriteIsRefusedRatherThanQueued()
	{
		strictAssertTrue(class_exists('RuTrackerBatchDispatch'),
			'the manual entrypoints share a dispatch helper that owns the handover');

		$tree = $this->makeTree();
		try
		{
			$logs = array();
			$logger = function ($message) use (&$logs) { $logs[] = $message; };
			$launched = 0;
			$launcher = function ($command) use (&$launched) { $launched++; return true; };
			// Store one byte fewer than asked for, which is exactly what a full
			// filesystem does.
			$short = function ($handle, $payload) { return fwrite($handle, substr($payload, 0, -1)); };

			$dispatched = RuTrackerBatchDispatch::dispatch(
				array($this->hash(18)), $tree . '/tmp', PHP_BINARY,
				$tree . '/plugins/rutracker_check/batch_check.php', 'tester',
				$launcher, $logger, null, $short);

			strictAssertSame(false, $dispatched,
				'a handover that was only partly written is not reported as dispatched');
			strictAssertSame(0, $launched,
				'a handover that was only partly written launches no worker');
			strictAssertTrue(count(strictLogsMatching($logs, 'rutracker_check')) > 0,
				'an incomplete handover write is reported as a classified reason');
			foreach($logs as $line)
				strictAssertEnglish($line, 'the dispatch log line is classified English');
			strictAssertSame(array(), array_values((array) glob($tree . '/tmp/rutorrent-prm-*')),
				'the handover no worker will read is removed');
		}
		finally
		{
			strictRemoveTree($tree);
		}
	}

	/**
	 * Every handled outcome of this endpoint keeps a 2xx status, for the same
	 * reason: the shared error callback treats any failure status as the daemon
	 * being unreachable.
	 */
	public function testEveryHandledOutcomeKeepsA2xxStatus()
	{
		$tree = $this->makeTree();
		try
		{
			$blocked = $tree . '/blocked-path';
			if(file_put_contents($blocked, 'x') === false)
				throw new RuntimeException('could not create the blocked temp path');

			$cases = array(
				'a queued batch' => array($this->hashBody(array($this->hash(15))), array()),
				'a batch with no usable hash' => array('cmd=check&hash=nope',
					array('expect_child' => false)),
				'a batch whose handover cannot be written' => array(
					$this->hashBody(array($this->hash(16))),
					array('tempdir' => $blocked, 'expect_child' => false)),
				'a batch whose worker cannot be launched' => array(
					$this->hashBody(array($this->hash(17))),
					array('php' => $tree . '/no-such-binary', 'expect_child' => false)),
			);
			foreach($cases as $name => $case)
			{
				$response = $this->postToAction($tree, $case[0], $case[1]);
				strictAssertSame(200, $response['status'],
					$name . ' keeps a 2xx status so the UI does not mark rTorrent stopped');
				$this->decode($response);
			}
		}
		finally
		{
			strictRemoveTree($tree);
		}
	}
}

$suite = new StrictTestSuite();
$suite->addFromObject(new ManualEntrypointsTest());
exit($suite->run());
