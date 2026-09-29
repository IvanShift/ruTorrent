<?php

/**
 * Local SCGI peer for sendTorrent() tests. It replies to load before the
 * scripted download becomes visible, just as rTorrent's deferred load does.
 */
class RtorrentLoadConfirmationFixture
{
	private $root;
	private $process;
	private $port;

	public static function start($mode)
	{
		$fixture = new self();
		$fixture->root = sys_get_temp_dir() . '/rutorrent-load-' . bin2hex(random_bytes(6));
		if (!mkdir($fixture->root, 0700, true))
			throw new RuntimeException('could not create load fixture directory');
		$command = 'exec ' . escapeshellarg(PHP_BINARY) . ' '
			. escapeshellarg(__FILE__) . ' serve '
			. escapeshellarg($fixture->root) . ' ' . escapeshellarg($mode);
		$fixture->process = proc_open($command, array(
			0 => array('file', '/dev/null', 'r'),
			1 => array('file', $fixture->root . '/stdout', 'w'),
			2 => array('file', $fixture->root . '/stderr', 'w'),
		), $pipes);
		if (!is_resource($fixture->process))
			throw new RuntimeException('could not start load fixture peer');
		for ($attempt = 0; $attempt < 200; $attempt++) {
			if (is_file($fixture->root . '/port')) {
				$fixture->port = (int)file_get_contents($fixture->root . '/port');
				return $fixture;
			}
			usleep(10000);
		}
		$error = @file_get_contents($fixture->root . '/stderr');
		$fixture->close();
		throw new RuntimeException('load fixture peer did not start: ' . $error);
	}

	public function port()
	{
		return $this->port;
	}

	public function requests()
	{
		$rows = @file($this->root . '/requests', FILE_IGNORE_NEW_LINES);
		return $rows === false ? array() : array_map('base64_decode', $rows);
	}

	public function close()
	{
		if (is_resource($this->process)) {
			@proc_terminate($this->process);
			@proc_close($this->process);
			$this->process = null;
		}
		if (is_dir($this->root)) {
			foreach (scandir($this->root) as $entry)
				if ($entry !== '.' && $entry !== '..')
					@unlink($this->root . '/' . $entry);
			@rmdir($this->root);
		}
	}

	private static function readBytes($socket, $length)
	{
		$value = '';
		while (strlen($value) < $length) {
			$part = fread($socket, $length - strlen($value));
			if ($part === false || $part === '')
				return null;
			$value .= $part;
		}
		return $value;
	}

	private static function request($socket)
	{
		$digits = '';
		while (strlen($digits) < 12) {
			$byte = fread($socket, 1);
			if ($byte === ':') break;
			if ($byte === false || $byte === '' || $byte < '0' || $byte > '9')
				return null;
			$digits .= $byte;
		}
		if ($digits === '' || strlen($digits) >= 12)
			return null;
		$header = self::readBytes($socket, (int)$digits);
		if ($header === null || self::readBytes($socket, 1) !== ',')
			return null;
		$fields = explode("\0", $header);
		$length = null;
		for ($i = 0; $i + 1 < count($fields); $i += 2)
			if ($fields[$i] === 'CONTENT_LENGTH')
				$length = (int)$fields[$i + 1];
		return $length === null ? null : self::readBytes($socket, $length);
	}

	private static function response($value, $fault = false)
	{
		if ($fault === 'unparsed-response')
			$body = '<?xml version="1.0"?><methodResponse><params><param><value>'
				. '<boolean>0</boolean></value></param></params></methodResponse>';
		else if ($fault)
		{
			$code = $fault === 'missing-legacy' ? -501 : -500;
			$message = $fault === 'missing-current'
				? 'invalid parameters: info-hash not found'
				: ($fault === 'missing-legacy' ? 'Could not find info-hash.' : 'info-hash not found');
			$body = '<?xml version="1.0"?><methodResponse><fault><value><struct>'
				. '<member><name>faultCode</name><value><i8>' . $code . '</i8></value></member>'
				. '<member><name>faultString</name><value><string>' . $message . '</string></value></member>'
				. '</struct></value></fault></methodResponse>';
		}
		else
			$body = '<?xml version="1.0"?><methodResponse><params><param><value>'
				. (is_int($value) ? '<i4>' . $value . '</i4>' : '<string>' . htmlspecialchars($value, ENT_NOQUOTES, 'UTF-8') . '</string>')
				. '</value></param></params></methodResponse>';
		return "Status: 200 OK\r\nContent-Type: text/xml\r\nContent-Length: "
			. strlen($body) . "\r\n\r\n" . $body;
	}

	public static function serve($root, $mode)
	{
		$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
		if ($server === false)
			throw new RuntimeException('load fixture server: ' . $error);
		$address = stream_socket_get_name($server, false);
		file_put_contents($root . '/port', substr($address, strrpos($address, ':') + 1));
		$markerKey = null;
		$queries = 0;
		while ($socket = @stream_socket_accept($server, 10)) {
			stream_set_timeout($socket, 3);
			$request = self::request($socket);
			if ($request !== null)
				file_put_contents($root . '/requests', base64_encode($request) . "\n", FILE_APPEND);
			$method = '';
			if ($request !== null && preg_match('~<methodName>([^<]+)</methodName>~', $request, $match))
				$method = $match[1];
			if (strpos($method, 'load.') === 0 || strpos($method, 'load_') === 0) {
				if (preg_match('~ru-load-proof-[0-9a-f]{32}~', (string)$request, $match))
					$markerKey = $match[0];
				$answer = self::response(0, $mode === 'load-fault');
			} elseif ($method === 'd.custom' || $method === 'd.get_custom') {
				$queries++;
				$matchesProof = $markerKey !== null
					&& strpos((string)$request, '<string>' . $markerKey . '</string>') !== false;
				if ($matchesProof && ($mode === 'own' || ($mode === 'delayed' && $queries >= 3)
				|| ($mode === 'transient-foreign' && $queries >= 3)))
					$answer = self::response('1');
				elseif ($mode === 'foreign' || $mode === 'transient-foreign')
					$answer = self::response('other');
				else
					$answer = self::response('', in_array($mode,
						array('missing-current', 'missing-legacy', 'unparsed-response'), true) ? $mode : true);
			} else {
				$answer = self::response('', true);
			}
			fwrite($socket, $answer);
			fclose($socket);
		}
	}
}

if (isset($argv[1]) && $argv[1] === 'serve') {
	RtorrentLoadConfirmationFixture::serve($argv[2], $argv[3]);
	exit;
}
