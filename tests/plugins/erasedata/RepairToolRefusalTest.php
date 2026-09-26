<?php

require_once(__DIR__ . '/../../php/TestCase.php');

/**
 * The retrackers marker-clearing tool must refuse, not report success, when the
 * daemon does not answer.
 *
 * tasks/retrackers-clear-markers.php is run by hand against a live rTorrent to
 * retire recovery markers no code can clear any more. Its output is the only
 * thing an operator has to go on, so an answer it never received must never read
 * as "nothing to do" or as "cleared". It used to: the transport check looked for
 * faultString and nothing else, an empty body decoded to zero cells, the
 * multiple-of-four shape check accepted that, and the script printed
 * "0 torrents, 0 carry a recovery marker" -- or, in apply mode, "session saved"
 * and "cleared 1, remaining 0" -- and exited 0.
 *
 * This drives the real script against a real UNIX socket whose peer accepts the
 * request and closes without replying.
 */
class RepairToolRefusalTest extends TestCase
{
	private $dir = null;
	private $socketPath = null;
	private $server = null;

	public function setUp()
	{
		$this->dir = sys_get_temp_dir() . '/erasedata-repair-tool-'
			. getmypid() . '-' . uniqid('', true);
		@mkdir($this->dir . '/rutorrent/app/conf', 0700, true);
		// A UNIX socket path is bounded at about 108 bytes, so it lives beside
		// the temporary directory rather than inside its longer path.
		$this->socketPath = sys_get_temp_dir() . '/erp-' . getmypid() . '-'
			. substr(sha1($this->dir), 0, 8) . '.sock';
		@unlink($this->socketPath);
	}

	public function tearDown()
	{
		if (is_resource($this->server)) @fclose($this->server);
		@unlink($this->socketPath);
		foreach (array('/rutorrent/app/conf/config.php', '/rutorrent/app/conf',
			'/rutorrent/app', '/rutorrent') as $entry)
			@is_dir($this->dir . $entry) ? @rmdir($this->dir . $entry)
				: @unlink($this->dir . $entry);
		@unlink($this->dir . '/tool.php');
		@rmdir($this->dir);
	}

	// The shipped tool, pointed at this test's socket and its own fake app root.
	private function stagedTool()
	{
		$source = dirname(dirname(dirname(__DIR__)))
			. '/tasks/retrackers-clear-markers.php';
		$this->assertTrue(is_file($source), 'the shipped tool is where it is documented');
		file_put_contents($this->dir . '/rutorrent/app/conf/config.php',
			"<?php\n\$scgi_host = 'unix://" . $this->socketPath . "';\n\$scgi_port = 0;\n");
		$staged = str_replace('/rutorrent/app/conf/config.php',
			$this->dir . '/rutorrent/app/conf/config.php',
			file_get_contents($source));
		file_put_contents($this->dir . '/tool.php', $staged);
		return($this->dir . '/tool.php');
	}

	// Run the tool while this process plays a peer that answers $replies
	// requests and then accepts-and-closes for ever.
	private function runAgainstPeer($argument, $replies, $marker = null)
	{
		$tool = $this->stagedTool();
		if ($marker === null) $marker = 'v1:original:0:' . str_repeat('B', 40);
		// A UNIX socket leaves its node behind, and a second bind on the same
		// path fails with "Address already in use" -- which would read as a
		// broken test rather than as the leftover it is.
		@unlink($this->socketPath);
		$this->server = @stream_socket_server('unix://' . $this->socketPath, $errno, $errstr);
		$this->assertTrue(is_resource($this->server),
			'the fake peer listens: ' . $errstr);
		$descriptors = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
		// 'exec ' so proc_open's /bin/sh does not fork and leave the real child
		// behind a shell that proc_terminate would signal instead.
		$process = proc_open('exec ' . PHP_BINARY . ' ' . escapeshellarg($tool)
			. ($argument === '' ? '' : ' ' . escapeshellarg($argument)),
			$descriptors, $pipes);
		$this->assertTrue(is_resource($process), 'the tool starts');
		$answered = 0;
		$observedExitCode = null;
		$deadline = time() + 20;
		while (time() < $deadline) {
			$client = @stream_socket_accept($this->server, 1);
			if (!is_resource($client)) {
				$status = proc_get_status($process);
				if (!$status['running']) {
					$observedExitCode = $status['exitcode'];
					break;
				}
				continue;
			}
			stream_set_timeout($client, 1);
			@fread($client, 65536);
			if ($answered < $replies) {
				$body = '<?xml version="1.0"?><methodResponse><params><param><value>'
					. '<array><data><value><array><data>'
					. '<value><string>' . str_repeat('A', 40) . '</string></value>'
					. '<value><string>probe.bin</string></value>'
					. '<value><string>' . htmlspecialchars($marker, ENT_XML1) . '</string></value>'
					. '<value><string></string></value>'
					. '</data></array></value></data></array></value></param></params>'
					. '</methodResponse>';
				@fwrite($client, strlen($body) . ':' . $body);
				$answered++;
			}
			@fclose($client);
		}
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		foreach ($pipes as $pipe) @fclose($pipe);
		$code = proc_close($process);
		// PHP before 8.3 may return -1 after proc_get_status reaps an exited child.
		if ($code === -1 && $observedExitCode !== null) $code = $observedExitCode;
		@fclose($this->server);
		$this->server = null;
		return(array($code, $out, $err));
	}

	public function testAnUnansweredReadIsNotReportedAsNothingToDo()
	{
		list($code, $out, $err) = $this->runAgainstPeer('', 0);
		$this->assertTrue($code !== 0,
			'a peer that never answers is not a successful run: exit ' . $code
				. ' out=' . $out . ' err=' . $err);
		$this->assertTrue(strpos($out, 'carry a recovery marker') === false,
			'and no count is reported from an answer that never arrived: ' . $out);
		$this->assertTrue(strpos($err, 'no complete answer') !== false,
			'and it says which call went unanswered: ' . $err);
	}

	public function testReportIdentifiesCandidateClaimWithoutPrintingTransactionId()
	{
		$transaction = str_repeat('a', 32);
		list($code, $out, $err) = $this->runAgainstPeer('', 1,
			'v1:candidate-claim:' . $transaction);
		$this->assertTrue($code === 0, 'the read-only report succeeds: ' . $err);
		$this->assertTrue(strpos($out, '[candidate-claim; ack empty]') !== false,
			'the operator can distinguish an incomplete replacement claim');
		$this->assertTrue(strpos($out, $transaction) === false,
			'the transaction identifier is not printed in a routine report');
	}

	public function testAnUnansweredClearIsNotReportedAsCleared()
	{
		// The first read succeeds, so the tool has a real marker to act on; every
		// call after it is accepted and dropped. Before the fix this printed
		// "session saved" and "cleared 1, remaining 0" and exited 0.
		list($code, $out, $err) = $this->runAgainstPeer('apply', 1);
		$this->assertTrue($code !== 0,
			'an unanswered clear is not a successful run: exit ' . $code
				. ' out=' . $out);
		$this->assertTrue(strpos($out, 'cleared') === false,
			'nothing claims the markers were cleared: ' . $out);
		$this->assertTrue(strpos($out, 'session saved') === false,
			'nor that the session was saved: ' . $out);
		$this->assertTrue(strpos($err, 'no complete answer') !== false,
			'and it says which call went unanswered: ' . $err);
	}
}
