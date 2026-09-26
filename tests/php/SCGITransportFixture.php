<?php

/**
 * Disposable TCP or UNIX SCGI peer. It captures the parsed request, writes a
 * scripted reply, and can keep the connection open after the declared body.
 */
class SCGITransportFixture
{
	private $accepted;
	private $capture;
	private $dir;
	private $errorFile;
	private $host;
	private $port;
	private $process;
	private $release;

	/** Shared by XMLRPCProxyTest for dependency checks in an isolated PHP child. */
	public static function runPhpChild($arguments)
	{
		$command = array(escapeshellarg(PHP_BINARY));
		foreach($arguments as $argument)
			$command[] = escapeshellarg($argument);
		$process = proc_open(implode(' ', $command), array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		), $pipes);
		if(!is_resource($process))
			throw new RuntimeException('failed to start PHP child process');
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		return array(
			'exitCode' => proc_close($process),
			'stdout' => $stdout,
			'stderr' => $stderr,
		);
	}

	public static function start($response, $holdOpen = false)
	{
		return self::startChunks(array(array(0, $response)), $holdOpen, false);
	}

	public static function startUnix($response, $holdOpen = false)
	{
		return self::startChunks(array(array(0, $response)), $holdOpen, true);
	}

	/** Reply once, then read and close the next request without headers. */
	public static function startThenCloseSecond($response)
	{
		$fixture = new self();
		$fixture->boot(array(array(0, $response)), false, false, 0, true);
		return $fixture;
	}

	/** Answer a live-version probe before serving the request under test. */
	public static function startWithVersionProbe($chunks, $holdOpen = false, $unix = false)
	{
		$fixture = new self();
		$fixture->boot($chunks, $holdOpen, $unix, 0, false, true);
		return $fixture;
	}

	/** Answer the version probe, then make the next connection fail. */
	public static function startVersionProbeOnly()
	{
		$fixture = new self();
		$fixture->boot(array(), false, false, 0, false, true, true);
		return $fixture;
	}

	public static function startRepeatedBody($length, $unix = false)
	{
		$fixture = new self();
		$fixture->boot(array(array(0, "Content-Length: ".$length."\r\n\r\n")), false,
			$unix, (int)$length);
		return $fixture;
	}

	/** Each chunk is array(delay-in-seconds-before-write, bytes). */
	public static function startChunks($chunks, $holdOpen = false, $unix = false)
	{
		$fixture = new self();
		$fixture->boot($chunks, $holdOpen, $unix);
		return $fixture;
	}

	public function host()
	{
		return $this->host;
	}

	public function port()
	{
		return $this->port;
	}

	public function accepted()
	{
		return is_file($this->accepted);
	}

	public function request()
	{
		for($i = 0; $i < 200; $i++)
		{
			if(is_file($this->capture))
			{
				$value = json_decode(file_get_contents($this->capture), true);
				if(is_array($value) && isset($value['header']) && isset($value['payload']))
					return array(
						'header' => base64_decode($value['header']),
						'payload' => base64_decode($value['payload']),
					);
			}
			usleep(5000);
		}
		throw new Exception('SCGI fixture did not capture a complete request: '.$this->errors());
	}

	public function errors()
	{
		return is_file($this->errorFile) ? trim(file_get_contents($this->errorFile)) : '';
	}

	public function close()
	{
		if($this->release !== null)
			@touch($this->release);
		if(is_resource($this->process))
		{
			$status = proc_get_status($this->process);
			if(isset($status['running']) && $status['running'])
			{
				usleep(20000);
				$status = proc_get_status($this->process);
				if(isset($status['running']) && $status['running'])
					@proc_terminate($this->process);
			}
			@proc_close($this->process);
		}
		if($this->dir !== null && is_dir($this->dir))
		{
			foreach(scandir($this->dir) as $name)
				if(($name !== '.') && ($name !== '..'))
					@unlink($this->dir.'/'.$name);
			@rmdir($this->dir);
		}
	}

	private function boot($chunks, $holdOpen, $unix, $repeatBytes = 0, $closeSecond = false,
		$versionProbe = false, $probeOnly = false)
	{
		$this->dir = sys_get_temp_dir().'/rutorrent-scgi-'.uniqid('', true);
		if(!mkdir($this->dir, 0700, true))
			throw new Exception('could not create SCGI fixture directory');
		$endpointFile = $this->dir.'/endpoint';
		$this->accepted = $this->dir.'/accepted';
		$this->capture = $this->dir.'/capture.json';
		$this->errorFile = $this->dir.'/err';
		$this->release = $this->dir.'/release';
		$configFile = $this->dir.'/config.json';
		$listen = $unix ? 'unix://'.$this->dir.'/scgi.sock' : 'tcp://127.0.0.1:0';
		$configChunks = array();
		foreach($chunks as $chunk)
		{
			if(!is_array($chunk) || (count($chunk) !== 2))
				throw new Exception('SCGI fixture chunk must contain delay and bytes');
			$configChunks[] = array(
				'delay' => max(0, (int)round(((float)$chunk[0]) * 1000000)),
				'data' => base64_encode($chunk[1]),
			);
		}
		file_put_contents($configFile, json_encode(array(
			'listen' => $listen,
			'hold' => $holdOpen,
			'chunks' => $configChunks,
			'repeat' => $repeatBytes,
			'closeSecond' => $closeSecond,
			'versionProbe' => $versionProbe,
			'probeOnly' => $probeOnly,
		)));
		$script = <<<'PHP'
<?php
$config = json_decode(file_get_contents($argv[1]), true);
$server = @stream_socket_server($config['listen'], $errno, $error);
if($server === false)
{
	fwrite(STDERR, "server: $error\n");
	exit(2);
}
file_put_contents($argv[2], stream_socket_get_name($server, false));
function readRequest($client)
{
	$length = '';
	while(true)
	{
		$byte = fread($client, 1);
		if($byte === false || $byte === '')
			exit(4);
		if($byte === ':')
			break;
		if(($byte < '0') || ($byte > '9') || (strlen($length) >= 12))
			exit(5);
		$length .= $byte;
	}
	if($length === '')
		exit(6);
	$need = (int)$length + 1;
	$request = '';
	while(strlen($request) < $need)
	{
		$part = fread($client, $need - strlen($request));
		if($part === false || $part === '')
			exit(7);
		$request .= $part;
	}
	if(substr($request, -1) !== ',')
		exit(8);
	$header = substr($request, 0, -1);
	$parts = explode("\0", $header);
	if(array_pop($parts) !== '')
		exit(9);
	$fields = array();
	for($i = 0; $i < count($parts); $i += 2)
	{
		if(!isset($parts[$i + 1]))
			exit(10);
		$fields[$parts[$i]] = $parts[$i + 1];
	}
	if(!isset($fields['CONTENT_LENGTH']) || !preg_match('/^[0-9]+$/', $fields['CONTENT_LENGTH']))
		exit(11);
	$payload = '';
	$payloadLength = (int)$fields['CONTENT_LENGTH'];
	while(strlen($payload) < $payloadLength)
	{
		$part = fread($client, $payloadLength - strlen($payload));
		if($part === false || $part === '')
			exit(12);
		$payload .= $part;
	}
	return array('header' => base64_encode($header), 'payload' => base64_encode($payload));
}
if(!empty($config['versionProbe']))
{
	$probe = @stream_socket_accept($server, 5);
	if($probe === false)
		exit(18);
	touch($argv[3]);
	$record = readRequest($probe);
	if(base64_decode($record['payload']) !== '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params></params></methodCall>')
		exit(19);
	$body = '<?xml version="1.0"?><methodResponse><params><param><value><string>0.16.24</string></value></param></params></methodResponse>';
	$frame = 'Content-Length: '.strlen($body)."\r\n\r\n".$body;
	fwrite($probe, $frame);
	if(!empty($config['probeOnly']))
	{
		fclose($server);
		fclose($probe);
		exit(0);
	}
	fclose($probe);
}
$client = @stream_socket_accept($server, 5);
if($client === false)
	exit(3);
touch($argv[3]);
file_put_contents($argv[4], json_encode(readRequest($client)));
foreach($config['chunks'] as $chunk)
{
	if($chunk['delay'] > 0)
		usleep($chunk['delay']);
	$data = base64_decode($chunk['data']);
	$offset = 0;
	while($offset < strlen($data))
	{
		$written = fwrite($client, substr($data, $offset));
		if($written === false || $written === 0)
			exit(13);
		$offset += $written;
	}
}
if($config['repeat'] > 0)
{
	$block = str_repeat('x', 65536);
	$left = (int)$config['repeat'];
	while($left > 0)
	{
		$data = ($left >= strlen($block)) ? $block : substr($block, 0, $left);
		$offset = 0;
		while($offset < strlen($data))
		{
			$written = fwrite($client, substr($data, $offset));
			if($written === false || $written === 0)
				exit(14);
			$offset += $written;
			$left -= $written;
		}
	}
}
if($config['hold'])
	for($i = 0; $i < 500 && !is_file($argv[5]); $i++)
		usleep(10000);
fclose($client);
if(!empty($config['closeSecond']))
{
	$second = @stream_socket_accept($server, 5);
	if($second === false)
		exit(15);
	stream_set_timeout($second, 5);
	$tail = '';
	while(strpos($tail, '</methodCall>') === false)
	{
		$part = fread($second, 8192);
		if($part === false || $part === '')
			exit(16);
		$tail = substr($tail.$part, -32);
	}
	fclose($second);
}
fclose($server);
PHP;
		$scriptFile = $this->dir.'/peer.php';
		file_put_contents($scriptFile, $script);
		// 'exec ' so proc_terminate() in close() reaches the peer itself: proc_open()
		// runs a string command through /bin/sh, this shell forks rather than execs it,
		// and the signal would otherwise stop at the shell while the peer keeps its
		// socket open. Same reason as the copied-rpc2 server in SCGITransportTest.
		$command = 'exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($scriptFile).' '
			.escapeshellarg($configFile).' '.escapeshellarg($endpointFile).' '
			.escapeshellarg($this->accepted).' '.escapeshellarg($this->capture).' '
			.escapeshellarg($this->release);
		$this->process = proc_open($command, array(
			0 => array('pipe', 'r'),
			1 => array('file', $this->dir.'/out', 'a'),
			2 => array('file', $this->errorFile, 'a'),
		), $pipes, $this->dir);
		if(!is_resource($this->process))
			throw new Exception('could not start SCGI fixture peer');
		fclose($pipes[0]);
		for($i = 0; $i < 200; $i++)
		{
			if(is_file($endpointFile))
			{
				$endpoint = trim(file_get_contents($endpointFile));
				if($unix)
				{
					$this->host = $listen;
					$this->port = 0;
					return;
				}
				$position = strrpos($endpoint, ':');
				$this->host = '127.0.0.1';
				$this->port = ($position === false) ? 0 : intval(substr($endpoint, $position + 1));
				if($this->port > 0)
					return;
			}
			$status = proc_get_status($this->process);
			if(isset($status['running']) && !$status['running'])
				throw new Exception('SCGI fixture peer exited: '.$this->errors());
			usleep(5000);
		}
		throw new Exception('SCGI fixture peer did not publish an endpoint: '.$this->errors());
	}
}
