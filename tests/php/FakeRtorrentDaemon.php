<?php


/** One scalar XMLRPC string reply, for read-only daemon version probes. */
class FakeRtorrentDirectStringReply
{
	public $value;

	public function __construct($value)
	{
		$this->value = $value;
	}
}

/** Serializable replies for addtorrent's pre-load snapshot and deferred load proof. */
class FakeRtorrentAddTorrentReplies
{
	private $loaded;
	private $proofs;
	private $snapshotFails;
	private $loadIndex = -1;
	private $proofKey = null;

	public function __construct($loaded, $proofs, $snapshotFails)
	{
		$this->loaded = $loaded;
		$this->proofs = $proofs;
		$this->snapshotFails = $snapshotFails;
	}

	public function __invoke($request, $methods)
	{
		if(in_array('system.client_version', $methods, true)) return array('0.16.24');
		if(in_array('system.api_version', $methods, true)) return array(11);
		if(in_array('system.sockets.available_alloc', $methods, true))
			return array(4096, 128, 128, 1024, 2048, 128);
		if(in_array('download_list', $methods, true))
			return $this->snapshotFails ? false : $this->loaded;
		foreach($methods as $method)
			if(strpos($method, 'load_') === 0 || strpos($method, 'load.') === 0)
			{
				$this->loadIndex++;
				$this->proofKey = preg_match('/ru-load-proof-[a-f0-9]{32}/', $request, $match)
					? $match[0] : null;
				return array(0);
			}
		if(in_array('d.custom', $methods, true) || in_array('d.get_custom', $methods, true))
			return array($this->proofKey !== null && strpos($request, $this->proofKey) !== false
				&& !empty($this->proofs[$this->loadIndex]) ? '1' : 'other');
		return array(0);
	}
}

/**
 * An SCGI listener that answers like rtorrent, for tests that need to drive a
 * top level script rather than a function.
 *
 * plugins/edit/action.php reads php://input, talks to rtorrent over SCGI and
 * ends by printing json. Its d.erase and its reload are two separate calls, so
 * the only way to see that the erase happened and the reload did not is to be
 * the daemon on the other end of both.
 *
 * php/xmlrpc.php reads a reply by regex over <value><string> and <value><i8>.
 * The SCGI response carries Content-Length headers followed by that XMLRPC
 * body. The request is an SCGI netstring header followed by the XMLRPC body;
 * method names are taken out of the body -- both the single <methodName> form
 * and the members of a system.multicall.
 */
class FakeRtorrentDaemon
{
	private $socket;
	private $pid = 0;
	private $process;
	private $port;
	private $logFile;
	private $bodyLogFile;

	/**
	 * $replies is a list of values per request, or a callback accepting the
	 * request body and method names for flows with dynamic readback keys.
	 * A false reply emits an XMLRPC fault for failure-path tests.
	 */
	public function __construct($replies, $logFile, $worker = false)
	{
		$this->logFile = $logFile;
		// Keep bodies separate so calls() remains a plain method-name list.
		$this->bodyLogFile = $logFile . '.bodies';
		if($worker)
		{
			$this->listen();
			fwrite(STDOUT, $this->port . "\n");
			fflush(STDOUT);
			$this->serve($replies);
			return;
		}
		@unlink($this->logFile);
		@unlink($this->bodyLogFile);
		if($replies instanceof Closure)
		{
			if(!function_exists('pcntl_fork'))
				throw new RuntimeException('a closure reply requires pcntl; use a serializable reply plan');
			$this->listen();
			$this->pid = pcntl_fork();
			if($this->pid === -1)
				throw new RuntimeException('cannot fork a daemon');
			if($this->pid === 0)
			{
				$this->serve($replies);
				exit(0);
			}
			return;
		}
		$this->startProcess($replies);
	}

	private function listen()
	{
		$this->socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		if($this->socket === false)
			throw new RuntimeException('cannot listen: ' . $errstr);
		$name = stream_socket_get_name($this->socket, false);
		$this->port = (int)substr($name, strrpos($name, ':') + 1);
	}

	private function startProcess($replies)
	{
		$source = 'require ' . var_export(__FILE__, true) . '; FakeRtorrentDaemon::runWorker();';
		$command = 'exec ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($source);
		$this->process = proc_open($command, array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('file', $this->logFile . '.server.err', 'a'),
		), $pipes, dirname($this->logFile));
		if(!is_resource($this->process))
			throw new RuntimeException('cannot start a daemon process');
		$plan = serialize(array('replies' => $replies, 'logFile' => $this->logFile));
		$sent = 0;
		while($sent < strlen($plan))
		{
			$written = @fwrite($pipes[0], substr($plan, $sent));
			if($written === false || $written === 0) break;
			$sent += $written;
		}
		fclose($pipes[0]);
		stream_set_timeout($pipes[1], 10);
		$ready = ($sent === strlen($plan)) ? fgets($pipes[1]) : false;
		fclose($pipes[1]);
		if(!is_string($ready) || !preg_match('/^[1-9][0-9]*\n$/D', $ready))
		{
			$this->stop();
			throw new RuntimeException('fake daemon process did not announce a port: '
				. @file_get_contents($this->logFile . '.server.err'));
		}
		$this->port = (int)$ready;
	}

	public static function runWorker()
	{
		$plan = @unserialize(stream_get_contents(STDIN));
		if(!is_array($plan) || !array_key_exists('replies', $plan) || !isset($plan['logFile']))
			throw new RuntimeException('invalid fake daemon reply plan');
		new self($plan['replies'], $plan['logFile'], true);
	}

	public function port()
	{
		return $this->port;
	}

	/** The method names the daemon was asked for, in order. */
	public function calls()
	{
		$log = file_exists($this->logFile) ? trim(file_get_contents($this->logFile)) : '';
		return $log === '' ? array() : explode("\n", $log);
	}

	/**
	 * The full XMLRPC request bodies the daemon was sent, in order. Separated
	 * by a record marker rather than a newline because a body may contain one.
	 */
	public function bodies()
	{
		if(!file_exists($this->bodyLogFile))
			return array();
		$log = file_get_contents($this->bodyLogFile);
		if($log === '' || $log === false)
			return array();
		$records = explode("\x00--REQ--\x00", $log);
		array_pop($records);
		return $records;
	}

	public function received($method)
	{
		return in_array($method, $this->calls(), true);
	}

	public function stop()
	{
		if ($this->pid > 0) {
			posix_kill($this->pid, SIGTERM);
			pcntl_waitpid($this->pid, $status);
			$this->pid = 0;
		}
		if (is_resource($this->process)) {
			$status = proc_get_status($this->process);
			if($status['running']) @proc_terminate($this->process);
			@proc_close($this->process);
			$this->process = null;
		}
		if (is_resource($this->socket)) @fclose($this->socket);
	}

	private function serve($replies)
	{
		@fclose(STDOUT);
		$dynamic = is_callable($replies);
		for ($index = 0; $dynamic || $index < count($replies); $index++) {
			$client = @stream_socket_accept($this->socket, 10);
			if ($client === false) {
				break;
			}
			$body = $this->readRequest($client);
			file_put_contents($this->bodyLogFile, $body . "\x00--REQ--\x00", FILE_APPEND);
			$methods = $this->methodNames($body);
			foreach ($methods as $method) {
				file_put_contents($this->logFile, $method . "\n", FILE_APPEND);
			}
			$values = $dynamic ? $replies($body, $methods) : $replies[$index];
			@fwrite($client, $this->reply($values));
			@fclose($client);
		}
		@fclose($this->socket);
	}

	/** SCGI: "<len>:<headers>,<body>", with CONTENT_LENGTH in the headers. */
	private function readRequest($client)
	{
		stream_set_timeout($client, 10);
		$length = '';
		while (($char = fread($client, 1)) !== '' && $char !== false && $char !== ':') {
			$length .= $char;
		}
		$headers = '';
		$want = (int)$length;
		while (strlen($headers) < $want) {
			$chunk = fread($client, $want - strlen($headers));
			if ($chunk === '' || $chunk === false) {
				break;
			}
			$headers .= $chunk;
		}
		fread($client, 1); // the ',' that closes the netstring
		$fields = explode("\x00", $headers);
		$contentLength = 0;
		for ($i = 0; $i + 1 < count($fields); $i += 2) {
			if ($fields[$i] === 'CONTENT_LENGTH') {
				$contentLength = (int)$fields[$i + 1];
			}
		}
		$body = '';
		while (strlen($body) < $contentLength) {
			$chunk = fread($client, $contentLength - strlen($body));
			if ($chunk === '' || $chunk === false) {
				break;
			}
			$body .= $chunk;
		}
		return $body;
	}

	private function methodNames($body)
	{
		$names = array();
		if (preg_match('|<methodName>(.*)</methodName>|Us', $body, $outer)) {
			if ($outer[1] !== 'system.multicall') {
				return array($outer[1]);
			}
		}
		if (preg_match_all(
			'|<name>methodName</name><value><string>(.*)</string></value>|Us', $body, $inner)) {
			$names = $inner[1];
		}
		return $names;
	}

	/**
	 * Escape a value for XML text content the way rtorrent's XMLRPC layer does:
	 * '&', '<' and '>' and nothing else. It is byte-wise on purpose -- rtorrent
	 * answers with whatever bytes the filesystem gave it, including a path that
	 * is not valid UTF-8, and a quote or a backslash arrives literally rather
	 * than as an entity. htmlspecialchars() here would escape the quote and
	 * drop a non-UTF-8 value, and the reading side treats both of those
	 * differently from the bytes themselves.
	 */
	private static function escapeText($value)
	{
		return strtr($value, array('&' => '&amp;', '<' => '&lt;', '>' => '&gt;'));
	}

	private function reply($values)
	{
		if($values === false) {
			$body = '<?xml version="1.0"?><methodResponse><fault><value><struct>'
				. '<member><name>faultCode</name><value><i4>-501</i4></value></member>'
				. '<member><name>faultString</name><value><string>fixture failure</string></value></member>'
				. '</struct></value></fault></methodResponse>';
		} elseif($values instanceof FakeRtorrentDirectStringReply) {
			$body = '<?xml version="1.0"?><methodResponse><params><param><value><string>'
				. self::escapeText((string)$values->value)
				. '</string></value></param></params></methodResponse>';
		} else {
			$xml = '<?xml version="1.0" encoding="UTF-8"?><methodResponse><params><param>'
				. '<value><array><data>';
			foreach ($values as $value) {
				$xml .= is_int($value)
					? '<value><i8>' . $value . '</i8></value>'
					: '<value><string>' . self::escapeText((string)$value) . '</string></value>';
			}
			$body = $xml . '</data></array></value></param></params></methodResponse>';
		}
		return "Status: 200 OK\r\nContent-Type: text/xml\r\nContent-Length: "
			. strlen($body) . "\r\n\r\n" . $body;
	}
}
