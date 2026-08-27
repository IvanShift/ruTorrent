<?php

/**
 * Test support for the SCGI transport contract: the methodResponse builder,
 * the PHP child runner and the live TCP peer. All three used to be private
 * helpers of XMLRPCProxyTest; they moved out with the transport tests.
 *
 * This file loads no production code on purpose. The suite that uses it
 * decides what it loads, which is what keeps SCGITransportTest free of
 * XMLRPC proxy policy. XMLRPCProxyTest keeps one child-process test of its
 * own (env_check's SimpleXML requirement) and shares runPhpChild() from here
 * rather than keeping a second copy of it.
 */
class SCGITransportFixture
{
	public static function methodResponse($value = 'ok')
	{
		return '<?xml version="1.0"?><methodResponse><params><param><value><string>'
			. htmlspecialchars($value, ENT_NOQUOTES) . '</string></value></param></params></methodResponse>';
	}

	public static function runPhpChild($arguments)
	{
		$command = array(escapeshellarg(PHP_BINARY));
		foreach($arguments as $argument)
			$command[] = escapeshellarg($argument);
		$proc = proc_open(implode(' ', $command), array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		), $pipes);
		if(!is_resource($proc))
			throw new RuntimeException('failed to start PHP child process');
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		return array(
			'exitCode' => proc_close($proc),
			'stdout' => $stdout,
			'stderr' => $stderr,
		);
	}

	/**
	 * Run one live TCP SCGI peer. It deliberately reads the request as tiny
	 * fragments, so a successful response proves the complete netstring and
	 * XML payload arrived rather than merely the first socket-buffer write.
	 */
	public static function mockSCGIServer($behavior, $callback)
	{
		$encodedBehavior = base64_encode(serialize($behavior));
		$code = '$behavior = unserialize(base64_decode(' . var_export($encodedBehavior, true) . '));' . <<<'PHP'
function readExactFragmented($conn, $length, $chunkSize)
{
	$data = '';
	while(strlen($data) < $length)
	{
		$part = @fread($conn, min($chunkSize, $length - strlen($data)));
		if($part === false || $part === '')
			return null;
		$data .= $part;
	}
	return $data;
}

function readSCGIRequest($conn, $chunkSize)
{
	$lengthText = '';
	while(strlen($lengthText) <= 20)
	{
		$char = readExactFragmented($conn, 1, $chunkSize);
		if($char === null)
			return false;
		if($char === ':')
			break;
		if($char < '0' || $char > '9')
			return false;
		$lengthText .= $char;
	}
	if($lengthText === '' || strlen($lengthText) > 20)
		return false;
	$headerLength = (int) $lengthText;
	$headers = readExactFragmented($conn, $headerLength, $chunkSize);
	$comma = readExactFragmented($conn, 1, $chunkSize);
	if($headers === null || $comma !== ',')
		return false;
	$parts = explode(chr(0), $headers);
	$contentLength = null;
	for($i = 0; $i + 1 < count($parts); $i += 2)
	{
		if($parts[$i] === 'CONTENT_LENGTH' && ctype_digit($parts[$i + 1]))
			$contentLength = (int) $parts[$i + 1];
	}
	if($contentLength === null)
		return false;
	$payload = readExactFragmented($conn, $contentLength, $chunkSize);
	return $payload !== null && strlen($payload) === $contentLength;
}

function writeFragmented($conn, $data, $fragmentSize, $delayMicros)
{
	$offset = 0;
	while($offset < strlen($data))
	{
		$written = @fwrite($conn, substr($data, $offset, $fragmentSize));
		if($written === false || $written === 0)
			return;
		$offset += $written;
		if($delayMicros > 0)
			usleep($delayMicros);
	}
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if($server === false)
	exit(2);
echo stream_socket_get_name($server, false) . "\n";
flush();
$conn = @stream_socket_accept($server, 5);
if($conn === false)
	exit(3);
stream_set_timeout($conn, 2);
if(isset($behavior['requestReadDelayMicros']))
	usleep($behavior['requestReadDelayMicros']);
$complete = readSCGIRequest($conn, isset($behavior['requestChunk']) ? $behavior['requestChunk'] : 7);
if(isset($behavior['responseDelayMicros']))
	usleep($behavior['responseDelayMicros']);
if(!empty($behavior['reportRequestComplete']))
{
	$value = $complete ? 'complete' : 'incomplete';
	$body = '<?xml version="1.0"?><methodResponse><params><param><value><string>'
		. $value . '</string></value></param></params></methodResponse>';
	$response = "Status: 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
	writeFragmented($conn, $response, 11, 0);
}
elseif(isset($behavior['generatedBodyBytes']))
{
	writeFragmented($conn, "Status: 200 OK\r\nContent-Type: text/xml\r\n\r\n", 1024, 0);
	$remaining = $behavior['generatedBodyBytes'];
	$block = str_repeat('x', 1048576);
	while($remaining > 0)
	{
		$size = min($remaining, strlen($block));
		$written = @fwrite($conn, substr($block, 0, $size));
		if($written === false || $written === 0)
			break;
		$remaining -= $written;
	}
}
else
{
	writeFragmented($conn, $behavior['response'],
		isset($behavior['responseChunk']) ? $behavior['responseChunk'] : strlen($behavior['response']),
		isset($behavior['responseDelayBetweenChunks']) ? $behavior['responseDelayBetweenChunks'] : 0);
}
fclose($conn);
fclose($server);
PHP;
		$proc = proc_open(PHP_BINARY . ' -r ' . escapeshellarg($code), array(
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		), $pipes);
		if(!is_resource($proc))
			throw new RuntimeException('failed to start mock SCGI peer');
		$endpoint = trim((string) fgets($pipes[1]));
		if(!preg_match('/^([^:]+):(\d+)$/', $endpoint, $match))
			throw new RuntimeException('mock SCGI peer did not publish an endpoint');
		try {
			$callback($match[1], (int) $match[2]);
		} finally {
			$status = proc_get_status($proc);
			if($status['running'])
				proc_terminate($proc);
			fclose($pipes[1]);
			fclose($pipes[2]);
			proc_close($proc);
		}
	}
}
