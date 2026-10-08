<?php

require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');

/**
 * Runs PHP code in a child process that loads the real conf/config.php,
 * php/settings.php and php/xmlrpc.php against a FakeRtorrentDaemon, so every
 * string the daemon answers reaches the code under test through the real
 * parser, which hands it out in the daemon's own bytes (php/xmlrpc.php run()).
 * The child logs to its own file.
 */
final class ErasedataRealParserDriver
{
	private $base;
	private $daemon = null;
	private $logFile = null;

	public function __construct($base)
	{
		$this->base = $base;
	}

	public function daemon()
	{
		return $this->daemon;
	}

	// Answers $replies in order -- one list of values per request -- and
	// returns what $body printed, decoded from JSON.
	public function drive(array $replies, $body)
	{
		$this->stop();
		$this->daemon = new FakeRtorrentDaemon($replies, $this->base . '/calls-' . uniqid() . '.log');
		$this->logFile = $this->base . '/app-' . uniqid() . '.log';
		$root = realpath(__DIR__ . '/../../..');
		$driver = $this->base . '/drive-' . uniqid() . '.php';
		file_put_contents($driver, "<?php\n"
			. '$_SERVER[\'REMOTE_USER\'] = "tester"; $_ENV[\'RU_PROFILE_PATH\'] = '
				. var_export($this->base . '/profile', true) . ";\n"
			. 'require_once(' . var_export($root . '/conf/config.php', true) . ");\n"
			. 'require_once(' . var_export($root . '/php/settings.php', true) . ");\n"
			. '$r = new ReflectionClass("rTorrentSettings"); $s = $r->newInstanceWithoutConstructor();'
			. ' $s->iVersion = 0x1018; $s->aliases = array(); $s->directory = "/";'
			. ' $s->linkExist = true; $p = $r->getProperty("theSettings");'
			. ' $p->setAccessible(true); $p->setValue(null, $s);' . "\n"
			. '$scgi_host = "127.0.0.1"; $scgi_port = ' . $this->daemon->port() . ';'
				. ' $rpcTimeOut = 10; $rpcLogCalls = false;'
				. ' $log_file = ' . var_export($this->logFile, true) . ";\n"
			. 'require_once(' . var_export($root . '/php/xmlrpc.php', true) . ");\n"
			. $body);
		$out = array();
		exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 ' . escapeshellarg($driver) . ' 2>&1', $out);
		$printed = implode("\n", $out);
		$answer = json_decode($printed, true);
		if (!is_array($answer))
			throw new Exception('the driver printed: ' . $printed);
		return $answer;
	}

	// What the last child logged through FileUtil::toLog(), one entry per
	// line, without the timestamp.
	public function logLines()
	{
		$content = $this->logFile === null || !is_file($this->logFile)
			? '' : rtrim(file_get_contents($this->logFile), "\n");
		if ($content === '')
			return array();
		$lines = array();
		foreach (explode("\n", $content) as $line)
			$lines[] = preg_replace('/^\[[^\]]*\] /', '', $line);
		return $lines;
	}

	public function stop()
	{
		if ($this->daemon !== null)
			$this->daemon->stop();
		$this->daemon = null;
	}
}
