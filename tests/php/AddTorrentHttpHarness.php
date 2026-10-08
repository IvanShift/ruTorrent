<?php

require_once(__DIR__ . '/FakeRtorrentDaemon.php');

/**
 * The HTTP fixture of the php/addtorrent.php tests: PHP's built-in server in
 * front of the checkout (or of a prepared document root), raw HTTP/1.1
 * requests to it, and the multipart bodies a browser sends.
 *
 * Waiting. The server, and the FakeRtorrentDaemon behind it, are processes
 * outside the test, and a loaded host can starve them for seconds. A fixed
 * five-second socket timeout once failed AddTorrentRedirectTest on PHP 7.4
 * with a truncated response that read like a wrong body. A read here
 * therefore polls until the response ends, bounded by a generous deadline,
 * and every way of not getting a whole response -- the deadline, an exited
 * server, a short body, a listener that is not this server -- throws an
 * error that says which, with the server's log, instead of handing a partial
 * response to an assertion.
 *
 * Processes. The server starts from an argv array, so no shell sits between
 * proc_open() and php: proc_terminate() stops the listener itself and none
 * outlives the test (the fixture leak trap in AGENTS.md).
 */
final class AddTorrentHttpHarness
{
	/** Courtesy bounds for processes outside the test, not timings it observes. */
	const START_SECONDS = 30;
	const RESPONSE_SECONDS = 120;
	const POLL_SECONDS = 1.0;
	const PORT_ATTEMPTS = 3;
	const READY_PATH = '/__addtorrent-http-harness-ready';

	private $base;
	private $process = null;
	private $address = null;
	private $token;
	private $daemon = null;
	private $pollSeconds = self::POLL_SECONDS;
	private $responseSeconds = self::RESPONSE_SECONDS;

	private function __construct()
	{
		$base = tempnam(sys_get_temp_dir(), 'addtorrent-http-');
		if ($base === false || !unlink($base) || !mkdir($base, 0700))
			throw new RuntimeException('could not create the addtorrent HTTP fixture directory');
		$this->base = $base;
		$this->token = bin2hex(random_bytes(16));
	}

	public function __destruct()
	{
		$this->close();
	}

	/**
	 * Serve the checkout's php/addtorrent.php against a FakeRtorrentDaemon
	 * answering with $replies.
	 *
	 * The router loads only conf/config.php before addtorrent.php, as the
	 * entry point itself would, so the request reaches the script with the
	 * state PHP's startup left (error_get_last() included).
	 *
	 * Options:
	 *  - 'globals': name => value, assigned after conf/config.php;
	 *  - 'phpSelf': the PHP_SELF a rewriting proxy would present;
	 *  - 'ini':     name => value for the server, over max_file_uploads=128;
	 *  - 'preamble': PHP code the router runs just before addtorrent.php, such
	 *               as a diagnostic standing in for one PHP-FPM raises at
	 *               startup and the built-in server does not.
	 */
	public static function forAddTorrent($replies, array $options = array())
	{
		$harness = new self();
		try {
			mkdir($harness->base . '/profile/torrents', 0700, true);
			mkdir($harness->base . '/profile/settings', 0700, true);
			$harness->daemon = new FakeRtorrentDaemon($replies, $harness->base . '/daemon.log');
			$root = realpath(__DIR__ . '/../..');
			$router = '<?php' . "\n"
				. $harness->readyCheck()
				. '$_ENV["RU_PROFILE_PATH"] = ' . var_export($harness->base . '/profile', true) . ";\n"
				. '$_ENV["RU_LOG_FILE"] = ' . var_export($harness->base . '/application.log', true) . ";\n"
				. '$_ENV["RU_SCGI_HOST"] = "127.0.0.1";' . "\n"
				. '$_ENV["RU_SCGI_PORT"] = ' . var_export((string)$harness->daemon->port(), true) . ";\n"
				. (isset($options['phpSelf'])
					? '$_SERVER["PHP_SELF"] = ' . var_export($options['phpSelf'], true) . ";\n" : '')
				. 'require_once ' . var_export($root . '/conf/config.php', true) . ";\n";
			foreach ($options['globals'] ?? array() as $name => $value) {
				if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name))
					throw new InvalidArgumentException('not a global variable name: ' . $name);
				$router .= '$GLOBALS[' . var_export($name, true) . '] = ' . var_export($value, true) . ";\n";
			}
			$router .= ($options['preamble'] ?? '') . "\n"
				. 'chdir(' . var_export($root . '/php', true) . ");\n"
				. 'require ' . var_export($root . '/php/addtorrent.php', true) . ";\n"
				. "return true;\n";
			file_put_contents($harness->base . '/router.php', $router);
			$harness->start(array_merge(array('max_file_uploads' => '128'),
				$options['ini'] ?? array()), null, null);
		} catch (Throwable $e) {
			$harness->close();
			throw $e;
		}
		return $harness;
	}

	/**
	 * Serve the files under $root as the built-in server does. The router
	 * answers only the readiness probe and hands every other request back.
	 * 'port' pins the port, so a self-test can offer one that is taken.
	 */
	public static function forDocumentRoot($root, array $ini = array(), $port = null)
	{
		$harness = new self();
		try {
			file_put_contents($harness->base . '/router.php', "<?php\n"
				. $harness->readyCheck() . "return false;\n");
			$harness->start($ini, $root, $port);
		} catch (Throwable $e) {
			$harness->close();
			throw $e;
		}
		return $harness;
	}

	/** Shorter limits, for the self-test of the waiting itself. */
	public function setReadLimits($pollSeconds, $responseSeconds)
	{
		$this->pollSeconds = (float)$pollSeconds;
		$this->responseSeconds = (float)$responseSeconds;
	}

	public function daemon()
	{
		return $this->daemon;
	}

	public function profile()
	{
		return $this->base . '/profile';
	}

	/** What the application wrote with FileUtil::toLog(). */
	public function applicationLog()
	{
		return (string)@file_get_contents($this->base . '/application.log');
	}

	/** The server's PHP error log: diagnostics and error_log() lines. */
	public function phpErrors()
	{
		return (string)@file_get_contents($this->base . '/php-errors.log');
	}

	/**
	 * The PHP diagnostics -- warnings, notices, deprecations and fatal
	 * errors -- the server raised, except lines matching one of the
	 * $expected patterns. error_log() lines are not diagnostics.
	 */
	public function phpDiagnostics(array $expected = array())
	{
		$found = array();
		foreach (explode("\n", $this->phpErrors()) as $line) {
			if (!preg_match('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)/', $line))
				continue;
			foreach ($expected as $pattern)
				if (preg_match($pattern, $line))
					continue 2;
			$found[] = $line;
		}
		return $found;
	}

	/**
	 * The router script, a path unique to this fixture: every process the
	 * fixture starts names it on its command line.
	 */
	public function routerPath()
	{
		return $this->base === null ? null : $this->base . '/router.php';
	}

	/** An http:// URL of $path on this server. */
	public function url($path)
	{
		return 'http://' . $this->address . $path;
	}

	/** The server's pid, while it runs. */
	public function pid()
	{
		if (!is_resource($this->process))
			return null;
		$status = proc_get_status($this->process);
		return $status['running'] ? $status['pid'] : null;
	}

	/**
	 * Send one request and read the whole response.
	 *
	 * @return array status line, code, headers (lower-case name => last
	 *         value), header lines, body and the raw bytes.
	 */
	public function request($method, $target, $body = '', array $headers = array())
	{
		if ($this->address === null)
			throw new RuntimeException('the PHP built-in server is not running');
		$socket = @stream_socket_client('tcp://' . $this->address, $errno, $error, 5);
		if ($socket === false)
			throw new RuntimeException('could not connect to the PHP built-in server at '
				. $this->address . ': ' . $error . $this->diagnostics());
		try {
			$head = $method . ' ' . $target . " HTTP/1.1\r\n";
			$headers = array_merge(array('Host' => '127.0.0.1'), $headers,
				array('Connection' => 'close'));
			if ($body !== '' || $method === 'POST')
				$headers['Content-Length'] = (string)strlen($body);
			foreach ($headers as $name => $value)
				$head .= $name . ': ' . $value . "\r\n";
			$this->writeAll($socket, $head . "\r\n" . $body);
			$raw = $this->readAll($socket, $method . ' ' . $target);
		} finally {
			fclose($socket);
		}
		return $this->parse($raw, $method . ' ' . $target);
	}

	/**
	 * A multipart/form-data body. Each part is array('name' => ..., 'content'
	 * => ...), plus 'filename' and 'type' for a file.
	 *
	 * @return array the Content-Type header value and the body.
	 */
	public static function multipart(array $parts)
	{
		$boundary = 'addtorrent-harness-' . bin2hex(random_bytes(12));
		$body = '';
		foreach ($parts as $part) {
			$body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\""
				. $part['name'] . '"';
			if (isset($part['filename']))
				$body .= '; filename="' . $part['filename'] . '"';
			$body .= "\r\n";
			if (isset($part['type']))
				$body .= 'Content-Type: ' . $part['type'] . "\r\n";
			$body .= "\r\n" . $part['content'] . "\r\n";
		}
		return array('multipart/form-data; boundary=' . $boundary, $body . '--' . $boundary . "--\r\n");
	}

	/** The parts of a torrent_file[] upload, one per name, all with $content. */
	public static function torrentFiles(array $names, $content)
	{
		$parts = array();
		foreach ($names as $name)
			$parts[] = array('name' => 'torrent_file[]', 'filename' => $name,
				'type' => 'application/x-bittorrent', 'content' => $content);
		return $parts;
	}

	/** Stop the server and the daemon, and remove everything this fixture wrote. */
	public function close()
	{
		$this->stopServer();
		if ($this->daemon !== null) {
			$this->daemon->stop();
			$this->daemon = null;
		}
		if ($this->base !== null && is_dir($this->base)) {
			$entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->base,
				FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
			foreach ($entries as $entry) {
				if ($entry->isDir() && !$entry->isLink()) @rmdir($entry->getPathname());
				else @unlink($entry->getPathname());
			}
			@rmdir($this->base);
		}
		$this->base = null;
	}

	private function readyCheck()
	{
		return 'if (($_SERVER["REQUEST_URI"] ?? "") === ' . var_export(self::READY_PATH, true) . ') {'
			. ' header("Content-Type: text/plain"); echo ' . var_export($this->token, true)
			. '; return true; }' . "\n";
	}

	private function start(array $ini, $documentRoot, $port)
	{
		$attempts = $port === null ? self::PORT_ATTEMPTS : 1;
		$tried = array();
		for ($attempt = 1; $attempt <= $attempts; $attempt++) {
			$this->address = '127.0.0.1:' . ($port === null ? self::freePort() : (int)$port);
			$tried[] = $this->address;
			$command = array(PHP_BINARY, '-c', __DIR__ . '/../php-test.ini',
				// As in production: a warning goes to the log, never into the
				// response, where it would also break the headers that follow.
				'-d', 'display_errors=0', '-d', 'log_errors=1',
				'-d', 'error_log=' . $this->base . '/php-errors.log');
			foreach ($ini as $name => $value) {
				$command[] = '-d';
				$command[] = $name . '=' . $value;
			}
			array_push($command, '-S', $this->address);
			if ($documentRoot !== null)
				array_push($command, '-t', $documentRoot);
			$command[] = $this->base . '/router.php';
			$this->process = proc_open($command, array(
				0 => array('file', '/dev/null', 'r'),
				1 => array('file', $this->base . '/server.log', 'a'),
				2 => array('file', $this->base . '/server.log', 'a'),
			), $pipes, $this->base);
			if (!is_resource($this->process))
				throw new RuntimeException('could not start the PHP built-in server');
			if ($this->waitUntilReady())
				return;
			// Another process took the port between its reservation and the
			// bind; the server exited, so try a fresh port.
			$this->stopServer();
		}
		throw new RuntimeException('the PHP built-in server did not start on '
			. implode(', ', $tried) . $this->diagnostics());
	}

	/** True once this server answers its own token; false when it exited. */
	private function waitUntilReady()
	{
		$deadline = microtime(true) + self::START_SECONDS;
		while (microtime(true) < $deadline) {
			if ($this->pid() === null)
				return false;
			$socket = @stream_socket_client('tcp://' . $this->address, $errno, $error, 1);
			if ($socket !== false) {
				stream_set_timeout($socket, 1);
				@fwrite($socket, 'GET ' . self::READY_PATH . " HTTP/1.1\r\nHost: 127.0.0.1\r\n"
					. "Connection: close\r\n\r\n");
				$answer = (string)stream_get_contents($socket);
				fclose($socket);
				$parts = explode("\r\n\r\n", $answer, 2);
				if (isset($parts[1]) && $parts[1] === $this->token)
					return true;
				if ($answer !== '') {
					// Another listener can answer before this server finds its
					// port taken and exits; this server answering is a broken router.
					for ($i = 0; $i < 50 && $this->pid() !== null; $i++)
						usleep(20000);
					if ($this->pid() === null)
						return false;
					throw new RuntimeException('the PHP built-in server answered its readiness probe without '
						. 'the fixture token: ' . json_encode(substr($answer, 0, 200)) . $this->diagnostics());
				}
			}
			usleep(20000);
		}
		throw new RuntimeException('the PHP built-in server did not answer within '
			. self::START_SECONDS . ' s' . $this->diagnostics());
	}

	private function writeAll($socket, $bytes)
	{
		$sent = 0;
		$deadline = microtime(true) + $this->responseSeconds;
		while ($sent < strlen($bytes)) {
			$written = @fwrite($socket, substr($bytes, $sent));
			if ($written === false || ($written === 0 && microtime(true) >= $deadline))
				throw new RuntimeException('could not send the request to the PHP built-in server'
					. $this->diagnostics());
			$sent += $written;
		}
	}

	private function readAll($socket, $what)
	{
		$deadline = microtime(true) + $this->responseSeconds;
		$seconds = (int)$this->pollSeconds;
		stream_set_timeout($socket, $seconds, (int)(($this->pollSeconds - $seconds) * 1000000));
		$raw = '';
		while (!feof($socket)) {
			$chunk = fread($socket, 65536);
			if ($chunk !== false && $chunk !== '') {
				$raw .= $chunk;
				continue;
			}
			// A poll that times out reads '' on PHP 7.4 and false from 8.0 on.
			$meta = stream_get_meta_data($socket);
			if (!$meta['timed_out']) {
				if ($chunk === false)
					throw new RuntimeException('reading the response to ' . $what . ' failed'
						. $this->diagnostics());
				continue;
			}
			// An idle poll is only a pause; the server is slow, not done.
			if (microtime(true) >= $deadline)
				throw new RuntimeException('no complete response to ' . $what . ' within '
					. $this->responseSeconds . ' s (' . strlen($raw) . ' bytes so far)'
					. $this->diagnostics());
			if ($this->pid() === null)
				throw new RuntimeException('the PHP built-in server exited while answering '
					. $what . $this->diagnostics());
		}
		return $raw;
	}

	private function parse($raw, $what)
	{
		$parts = explode("\r\n\r\n", $raw, 2);
		if (count($parts) !== 2 || !preg_match('/^HTTP\/1\.[01] ([0-9]{3})/', $parts[0], $status))
			throw new RuntimeException('no HTTP response to ' . $what . ': '
				. json_encode(substr($raw, 0, 200)) . $this->diagnostics());
		$lines = explode("\r\n", $parts[0]);
		$statusLine = array_shift($lines);
		$headers = array();
		foreach ($lines as $line) {
			$colon = strpos($line, ':');
			if ($colon !== false)
				$headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
		}
		if (isset($headers['content-length']) && strlen($parts[1]) !== (int)$headers['content-length'])
			throw new RuntimeException('the response to ' . $what . ' has ' . strlen($parts[1])
				. ' body bytes where its Content-Length says ' . $headers['content-length']
				. $this->diagnostics());
		return array('status' => $statusLine, 'code' => (int)$status[1], 'headers' => $headers,
			'lines' => $lines, 'body' => $parts[1], 'raw' => $raw);
	}

	private function diagnostics()
	{
		$log = $this->base !== null ? trim((string)@file_get_contents($this->base . '/server.log')) : '';
		$errors = $this->base !== null ? trim($this->phpErrors()) : '';
		return ($log === '' ? '' : '; server log: ' . substr($log, -2000))
			. ($errors === '' ? '' : '; PHP errors: ' . substr($errors, -2000));
	}

	private function stopServer()
	{
		if (!is_resource($this->process))
			return;
		@proc_terminate($this->process);
		for ($i = 0; $i < 250; $i++) {
			$status = proc_get_status($this->process);
			if (!$status['running'])
				break;
			usleep(20000);
		}
		if ($status['running'])
			@proc_terminate($this->process, 9);
		@proc_close($this->process);
		$this->process = null;
		$this->address = null;
	}

	private static function freePort()
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
		if ($socket === false)
			throw new RuntimeException('could not reserve a local port: ' . $error);
		$name = stream_socket_get_name($socket, false);
		fclose($socket);
		return (int)substr($name, strrpos($name, ':') + 1);
	}
}
