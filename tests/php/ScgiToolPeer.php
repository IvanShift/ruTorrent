<?php

/**
 * Run a standalone CLI tool against an SCGI peer that this test process plays.
 *
 * The tool is a real child process; the peer accepts its connections one at
 * a time, records each request and answers what $respond returns, or closes
 * without an answer when it returns null. RepairToolRefusalTest and
 * ClearMarkersToolTest both drive tools/retrackers-clear-markers.php this way.
 */
final class ScgiToolPeer
{
	/**
	 * Stage the shipped clear-markers tool under $dir, its config pointed at
	 * $socket. With $withCliGuard the repository layout is kept (tools/ beside
	 * php/cli-only.php); without it the tool runs as the documented standalone
	 * /tmp copy.
	 */
	public static function stageClearMarkersTool($dir, $socket, $withCliGuard)
	{
		$root = dirname(__DIR__, 2);
		$config = $dir . '/rutorrent/app/conf/config.php';
		$tool = $withCliGuard ? $dir . '/tools/retrackers-clear-markers.php' : $dir . '/tool.php';
		foreach (array(dirname($config), dirname($tool)) as $directory)
			if (!is_dir($directory)) mkdir($directory, 0700, true);
		if ($withCliGuard) {
			if (!is_dir($dir . '/php')) mkdir($dir . '/php', 0700);
			if (!copy($root . '/php/cli-only.php', $dir . '/php/cli-only.php'))
				throw new RuntimeException('cannot stage php/cli-only.php');
		}
		file_put_contents($config, "<?php\n\$scgi_host = 'unix://" . $socket . "';\n\$scgi_port = 0;\n");
		$source = file_get_contents($root . '/tools/retrackers-clear-markers.php');
		if (!is_string($source) || substr_count($source, '/rutorrent/app/conf/config.php') < 1)
			throw new RuntimeException('the clear-markers tool no longer reads /rutorrent/app/conf/config.php');
		file_put_contents($tool, str_replace('/rutorrent/app/conf/config.php', $config, $source));
		return($tool);
	}

	/** Files and directories stageClearMarkersTool() created under $dir, deepest first. */
	public static function stagedEntries()
	{
		return(array('/rutorrent/app/conf/config.php', '/rutorrent/app/conf', '/rutorrent/app', '/rutorrent',
			'/tools/retrackers-clear-markers.php', '/tools', '/tool.php', '/php/cli-only.php', '/php'));
	}

	/**
	 * array(exit code, stdout, stderr, requests, method names). The peer gives
	 * up after 20 seconds; the tool's own timeouts end it sooner. $env adds to
	 * the inherited environment. A $framed answer is the netstring the
	 * clear-markers tool reads; otherwise $respond returns the raw SCGI answer
	 * that rSCGITransport reads, rTorrent's own "Status: 200 OK" form.
	 */
	public static function run($socket, $script, array $args, callable $respond, array $env = array(), $framed = true)
	{
		// A UNIX socket leaves its node behind, and a second bind on the same
		// path fails with "Address already in use".
		@unlink($socket);
		$server = @stream_socket_server('unix://' . $socket, $errno, $error);
		if (!is_resource($server)) throw new RuntimeException('the fake SCGI peer cannot listen: ' . $error);
		// exec: proc_open's /bin/sh must not stay between us and the tool.
		$process = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) .
			($args ? ' ' . implode(' ', array_map('escapeshellarg', $args)) : ''),
			array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null,
			$env ? array_merge(getenv(), $env) : null);
		if (!is_resource($process)) {
			fclose($server);
			throw new RuntimeException('the tool does not start');
		}
		$requests = array();
		$exited = null;
		$deadline = time() + 20;
		while (time() < $deadline) {
			$client = @stream_socket_accept($server, 1);
			if (!is_resource($client)) {
				// Before PHP 8.3 only the call that first sees the exit carries its code.
				$status = proc_get_status($process);
				if (!$status['running']) {
					$exited = $status['exitcode'];
					break;
				}
				continue;
			}
			stream_set_timeout($client, 2);
			$request = '';
			while (strpos($request, '</methodCall>') === false && !feof($client)) {
				$chunk = @fread($client, 65536);
				if ($chunk === false || ($chunk === '' && stream_get_meta_data($client)['timed_out'])) break;
				$request .= $chunk;
			}
			$body = $respond($request, count($requests));
			$requests[] = $request;
			if (is_string($body)) @fwrite($client, $framed ? strlen($body) . ':' . $body : $body);
			@fclose($client);
		}
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($process);
		if ($code === -1 && $exited !== null) $code = $exited;
		fclose($server);
		@unlink($socket);
		return(array($code, $out, $err, $requests, array_map(array(__CLASS__, 'method'), $requests)));
	}

	/** rTorrent 0.16's raw SCGI answer around one TinyXML2 result value. */
	public static function scgiAnswer($value)
	{
		$body = self::response($value);
		return("Status: 200 OK\r\nContent-Type: text/xml\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
	}

	/** The string parameters of one request, in order. */
	public static function params($request)
	{
		preg_match_all('~<param><value><string>(.*?)</string></value></param>~s', $request, $params);
		return(array_map(function ($value) {
			return(html_entity_decode($value, ENT_NOQUOTES, 'UTF-8'));
		}, $params[1]));
	}

	public static function method($request)
	{
		return(preg_match('~<methodName>([^<]+)</methodName>~', $request, $method) === 1 ? $method[1] : '');
	}

	public static function response($value)
	{
		return('<?xml version="1.0"?><methodResponse><params><param><value>' . $value .
			'</value></param></params></methodResponse>');
	}

	/** A d.multicall2 answer: one array of string cells per row. */
	public static function rows(array $rows)
	{
		$cells = '';
		foreach ($rows as $row) {
			$cells .= '<value><array><data>';
			foreach ($row as $cell) $cells .= '<value><string>' . htmlspecialchars($cell, ENT_XML1) . '</string></value>';
			$cells .= '</data></array></value>';
		}
		return(self::response('<array><data>' . $cells . '</data></array>'));
	}
}
