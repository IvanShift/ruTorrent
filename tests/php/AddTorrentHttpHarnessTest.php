<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/AddTorrentHttpHarness.php');

/**
 * The waiting of AddTorrentHttpHarness, with the poll interval and deadline
 * shortened so that "slower than one poll" costs a second, not a minute.
 *
 * A slow server is a pause, never the end of a response; and each way of not
 * getting a whole response fails as itself, so a starved host cannot turn
 * into an assertion about a truncated body again.
 */
final class AddTorrentHttpHarnessTest extends TestCase
{
	private $root = null;
	private $server = null;
	private $listener = null;

	public function setUp()
	{
		$root = tempnam(sys_get_temp_dir(), 'addtorrent-harness-root-');
		unlink($root);
		mkdir($root, 0700);
		$this->root = $root;
		file_put_contents($root . '/slow.php',
			'<?php usleep((int)$_GET["us"]); echo "complete";');
		file_put_contents($root . '/die.php',
			'<?php shell_exec("kill -9 " . getmypid());');
	}

	public function tearDown()
	{
		if ($this->server !== null)
			$this->server->close();
		$this->server = null;
		if (is_resource($this->listener))
			fclose($this->listener);
		foreach (glob($this->root . '/*') as $path)
			unlink($path);
		rmdir($this->root);
	}

	private function failure(callable $request)
	{
		try {
			$request();
		} catch (RuntimeException $e) {
			return $e->getMessage();
		}
		return null;
	}

	public function testAnIdleServerIsAPauseNotTheEndOfTheResponse()
	{
		$this->server = AddTorrentHttpHarness::forDocumentRoot($this->root);
		$this->server->setReadLimits(0.2, 20);
		$response = $this->server->request('GET', '/slow.php?us=1000000');
		$this->assertSame(200, $response['code'], 'a response after five idle polls still arrives');
		$this->assertSame('complete', $response['body'], 'and it is read to its end');
	}

	public function testAResponseThatNeverEndsIsAnErrorNotAShortBody()
	{
		$this->server = AddTorrentHttpHarness::forDocumentRoot($this->root);
		$this->server->setReadLimits(0.2, 1);
		$message = $this->failure(function () {
			$this->server->request('GET', '/slow.php?us=3000000');
		});
		$this->assertTrue($message !== null && strpos($message, 'no complete response to GET /slow.php') === 0
			&& strpos($message, 'within 1 s') !== false,
			'the deadline names the request it gave up on: ' . var_export($message, true));
	}

	public function testAServerThatExitsIsReportedWithItsLog()
	{
		$this->server = AddTorrentHttpHarness::forDocumentRoot($this->root);
		$message = $this->failure(function () {
			$this->server->request('GET', '/die.php');
		});
		$this->assertTrue($message !== null && strpos($message, 'no HTTP response to GET /die.php') === 0
			&& strpos($message, 'server log: ') !== false,
			'a server that died answering is an error with its log: ' . var_export($message, true));
		$message = $this->failure(function () {
			$this->server->request('GET', '/slow.php?us=0');
		});
		$this->assertTrue($message !== null
			&& strpos($message, 'could not connect to the PHP built-in server') === 0,
			'a later request names the missing server: ' . var_export($message, true));
	}

	/** The argv of a running process, from /proc. */
	private static function argv($pid)
	{
		$cmdline = @file_get_contents('/proc/' . (int)$pid . '/cmdline');
		return $cmdline === false ? array() : explode("\0", rtrim($cmdline, "\0"));
	}

	/** The processes whose command line names $path. */
	private static function processesNaming($path)
	{
		$found = array();
		foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) as $dir) {
			$cmdline = @file_get_contents($dir . '/cmdline');
			if ($cmdline !== false && strpos($cmdline, $path) !== false && (int)basename($dir) !== getmypid())
				$found[] = (int)basename($dir);
		}
		return $found;
	}

	/**
	 * The process proc_open() starts is the server itself, so proc_terminate()
	 * reaches it and nothing outlives close(). Through /bin/sh, which forks
	 * rather than execs here, the shell would get the signal and the server
	 * would keep its port (the fixture leak trap in AGENTS.md; R4-CORE-04).
	 */
	public function testTheServerRunsWithoutAShellAndEndsWithTheFixture()
	{
		$this->assertTrue(is_dir('/proc/self'), 'this check reads the process table from /proc');
		$this->server = AddTorrentHttpHarness::forDocumentRoot($this->root);
		$router = $this->server->routerPath();
		$argv = self::argv($this->server->pid());
		$this->assertTrue(in_array('-S', $argv, true) && in_array($router, $argv, true),
			'the started process is the PHP server itself, not a shell: ' . json_encode($argv));
		$this->server->close();
		$this->server = null;
		$left = self::processesNaming($router);
		foreach ($left as $pid)
			exec('kill -9 ' . (int)$pid . ' 2>/dev/null');
		$this->assertSame(array(), $left, 'no process of the fixture outlives close()');
	}

	public function testAPortHeldByAnotherListenerIsNotMistakenForTheFixture()
	{
		$this->listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
		$name = stream_socket_get_name($this->listener, false);
		$port = (int)substr($name, strrpos($name, ':') + 1);
		$message = $this->failure(function () use ($port) {
			$this->server = AddTorrentHttpHarness::forDocumentRoot($this->root, array(), $port);
		});
		$this->assertTrue($message !== null
			&& strpos($message, 'the PHP built-in server did not start on 127.0.0.1:' . $port) === 0
			// Keep the bind diagnostic without depending on libc's wording.
			&& preg_match('/Failed to listen on 127\\.0\\.0\\.1:' . $port
				. ' \\(reason: [^\\r\\n)]+\\)/', $message) === 1,
			'a listener without the fixture token is refused with its bind cause: ' . var_export($message, true));
	}
}
