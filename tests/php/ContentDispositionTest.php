<?php

require_once(__DIR__ . '/TestCase.php');

/**
 * SendFile::send() and Torrent::send() offer a download under a name that is
 * often a daemon string: a file of a torrent (data, unpack) or the torrent's
 * own name (source, dump, create). They pasted it between the quotes of
 * `Content-Disposition: attachment; filename="..."`.
 *
 * rXMLRPCRequest used to escape every '"' and '\' it read, which happened to
 * keep that quoted-string whole; it hands out the daemon's own bytes now
 * (AUD-ESC), so a name carrying a quote ended the quoted-string and the file
 * was saved under a truncated or mangled name. Both now build the value with
 * ContentDisposition::attachment(): an RFC 8187 filename* with the exact
 * bytes, and an ASCII quoted-string fallback with '"' and '\' escaped.
 *
 * The two senders are run under PHP's built-in web server, so the header
 * read here is the one a browser receives.
 */
class ContentDispositionTest extends TestCase
{
	const NAME = 'aud "q" \\b \'a\' &c';

	private $base;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-content-disposition-' . getmypid();
		$this->removeTree($this->base);
		mkdir($this->base . '/profile/settings', 0700, true);
		mkdir($this->base . '/www', 0700, true);
	}

	public function tearDown()
	{
		$this->removeTree($this->base);
	}

	private function removeTree($path)
	{
		if (!is_dir($path) || is_link($path)) {
			@unlink($path);
			return;
		}
		foreach (array_diff(scandir($path), array('.', '..')) as $entry) {
			$this->removeTree($path . '/' . $entry);
		}
		@rmdir($path);
	}

	/** The Content-Disposition values a browser receives for each request path. */
	private function served(array $paths)
	{
		$root = realpath(__DIR__ . '/../..');
		$file = $this->base . '/' . self::NAME . '.bin';
		file_put_contents($file, 'data');
		$name = self::NAME;
		$info = 'd6:lengthi1e4:name' . strlen($name) . ':' . $name
			. '12:piece lengthi16384e6:pieces20:' . str_repeat("\x01", 20) . 'e';
		$torrent = $this->base . '/source.torrent';
		file_put_contents($torrent, 'd8:announce28:http://example.test/announce4:info' . $info . 'e');
		file_put_contents($this->base . '/www/router.php', "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->base . '/profile', true) . ";\n"
			. '$_ENV[\'RU_LOG_FILE\'] = ' . var_export($this->base . '/app.log', true) . ";\n"
			. 'if ($_SERVER[\'SCRIPT_NAME\'] === \'/file\') {' . "\n"
			. '  require ' . var_export($root . '/php/utility/sendfile.php', true) . ";\n"
			. '  SendFile::send(' . var_export($file, true) . ");\n"
			. '} else {' . "\n"
			. '  require ' . var_export($root . '/php/Torrent.php', true) . ";\n"
			. '  (new Torrent(' . var_export($torrent, true) . '))->send();' . "\n"
			. "}\n");
		$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
		if ($listener === false) {
			throw new RuntimeException('no port for the web server: ' . $error);
		}
		$address = stream_socket_get_name($listener, false);
		fclose($listener);
		$log = $this->base . '/server.log';
		// An argument array: no shell sits between the test and the server.
		$process = proc_open(array(PHP_BINARY, '-d', 'display_errors=0', '-S', $address,
			$this->base . '/www/router.php'), array(0 => array('pipe', 'r'),
			1 => array('file', $log, 'a'), 2 => array('file', $log, 'a')), $pipes, $this->base . '/www');
		if (!is_resource($process)) {
			throw new RuntimeException('the web server did not start');
		}
		fclose($pipes[0]);
		$values = array();
		try {
			$ready = false;
			for ($i = 0; $i < 50 && !$ready; ++$i) {
				$socket = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
				if ($socket !== false) {
					fclose($socket);
					$ready = true;
				} else {
					usleep(100000);
				}
			}
			if (!$ready) {
				throw new RuntimeException('the web server is unavailable: ' . @file_get_contents($log));
			}
			foreach ($paths as $path) {
				$socket = stream_socket_client('tcp://' . $address, $errno, $error, 5);
				stream_set_timeout($socket, 5);
				fwrite($socket, 'GET ' . $path . " HTTP/1.0\r\nHost: localhost\r\nConnection: close\r\n\r\n");
				$response = stream_get_contents($socket);
				fclose($socket);
				$head = explode("\r\n\r\n", $response, 2)[0];
				$values[$path] = preg_match('/^Content-Disposition: (.*)$/mi', $head, $match) === 1
					? rtrim($match[1], "\r") : null;
			}
		} finally {
			proc_terminate($process);
			proc_close($process);
		}
		return $values;
	}

	/** The filename a browser takes: filename* when it decodes, the quoted-string otherwise. */
	private static function chosenName($value)
	{
		if (!is_string($value)) {
			return null;
		}
		if (preg_match("/;\\s*filename\\*=UTF-8''([^;\\s]*)/i", $value, $match) === 1) {
			return rawurldecode($match[1]);
		}
		if (preg_match('/;\s*filename="((?:[^"\\\\]|\\\\.)*)"\s*(?:;|$)/', $value, $match) === 1) {
			return preg_replace('/\\\\(.)/s', '$1', $match[1]);
		}
		return null;
	}

	/** Whether the value is one well-formed disposition: type, then name=value parameters. */
	private static function wellFormed($value)
	{
		$token = "[!#$%&'*+.^_`|~0-9A-Za-z-]+";
		$quoted = '"(?:[^"\\\\\x00-\x1F\x7F]|\\\\[\x20-\x7E])*"';
		return is_string($value) && preg_match('/^attachment(?:;\s*' . $token . '\*?=(?:' . $token
			. '|' . $quoted . "|UTF-8''[!#$&+.^_`|~0-9A-Za-z%-]*))*$/D", $value) === 1;
	}

	private function same($expected, $actual, $what)
	{
		$this->assertSame($expected, $actual, $what
			. ($expected === $actual ? '' : ': got ' . var_export($actual, true)));
	}

	public function testBothSendersOfferTheExactNameInAWellFormedHeader()
	{
		$values = $this->served(array('/file', '/torrent'));
		foreach (array('/file' => self::NAME . '.bin', '/torrent' => self::NAME . '.torrent') as $path => $name) {
			$value = $values[$path] ?? null;
			$this->assertTrue(self::wellFormed($value), $path . ' sends one well-formed disposition: '
				. var_export($value, true));
			$this->same($name, self::chosenName($value), $path . ' offers the name with its quote and backslash');
			$this->assertTrue(is_string($value) && strpos($value, 'filename="aud \\"q\\" \\\\b') !== false,
				$path . ' keeps an ASCII fallback with the quote and backslash escaped');
		}
	}

	public function testTheHelperKeepsEveryNameExactAndItsFallbackPrintable()
	{
		$path = __DIR__ . '/../../php/utility/contentdisposition.php';
		$this->assertTrue(is_file($path), 'the shared helper exists');
		if (!is_file($path)) {
			return;
		}
		require_once($path);
		$names = array('plain.torrent', self::NAME, "Фильм \"x\".mkv", "a\r\nX-Injected: 1", 'pct%41 ;=,.bin');
		foreach ($names as $name) {
			$value = ContentDisposition::attachment($name);
			$this->assertTrue(self::wellFormed($value), 'well-formed for ' . json_encode($name) . ': ' . $value);
			$this->same($name, self::chosenName($value), 'exact for ' . json_encode($name));
		}
		$this->same('attachment; filename="plain.torrent"; filename*=UTF-8\'\'plain.torrent',
			ContentDisposition::attachment('plain.torrent'), 'a plain name keeps its familiar header');
		$value = ContentDisposition::attachment("cp1251 \xD4\xE8\xEB\xFC\xEC.avi");
		$this->same('attachment; filename="cp1251 _____.avi"', $value,
			'a name that is not UTF-8 gets no filename* that would claim it is');
		$this->same('attachment; filename="a%22b"; filename*=UTF-8\'\'a%22b',
			ContentDisposition::attachment('a"b', 'Mozilla/4.0 (compatible; MSIE 8.0)'),
			'an old MSIE still gets the percent-encoded filename it decodes');
	}
}
