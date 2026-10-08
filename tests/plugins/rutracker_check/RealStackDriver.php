<?php

require_once(__DIR__ . '/MiniRtorrent.php');

/**
 * Runs a scenario in a child PHP that loads the shipped application: the real
 * conf/config.php, php/settings.php, php/xmlrpc.php, php/rtorrent.php and the
 * whole plugins/rutracker_check/check.php with everything it requires, talking
 * SCGI to a MiniRtorrent. Only the erasedata cleanup queue is stubbed: those
 * functions are function_exists()-guarded in plugins/erasedata/
 * removewithdata.php, the child records their arguments instead of running
 * the collector, and their queue is not what these cases are about.
 *
 * The child has its own profile, temporary directory and application log, and
 * runs every PHP warning as a failure: the result is the JSON the scenario
 * printed, or an exception naming what went wrong in the child.
 */
final class RutrackerRealStackDriver
{
	private $base;
	private $served = null;
	public $daemon = null;
	public $appLog;
	public $erasedataLog;

	public function __construct($base)
	{
		$this->base = $base;
		$this->appLog = $base . '/app.log';
		$this->erasedataLog = $base . '/erasedata-calls.json';
	}

	public function startDaemon(MiniRtorrent $daemon)
	{
		$this->stop();
		$this->daemon = $daemon;
		$this->served = $daemon->start($this->base . '/daemon-' . uniqid() . '.log');
		return $this->served['port'];
	}

	public function stop()
	{
		if ($this->served !== null)
			MiniRtorrent::stop($this->served);
		$this->served = null;
	}

	/**
	 * Run $body (PHP source, top level, after check.php is loaded) and return
	 * the JSON value it printed with rsDone().
	 */
	public function run($body, $settings = array())
	{
		if ($this->served === null)
			throw new RuntimeException('start a daemon first');
		$root = realpath(__DIR__ . '/../../..');
		$settings = array_merge(array(
			'directory' => $this->daemon->directory,
			'session' => $this->daemon->session,
		), $settings);
		$driver = $this->base . '/drive-' . uniqid() . '.php';
		file_put_contents($driver, "<?php\n"
			. '$_SERVER["REMOTE_USER"] = "tester";' . "\n"
			. '$_ENV["RU_PROFILE_PATH"] = ' . var_export($this->base . '/profile', true) . ";\n"
			. '$rsErasedataLog = ' . var_export($this->erasedataLog, true) . ";\n"
			. 'set_error_handler(function($no, $message, $file, $line) {'
			. ' if(!(error_reporting() & $no)) return false;'
			. ' throw new ErrorException($message, 0, $no, $file, $line); });' . "\n"
			. 'function rsDone($value) { echo "\n--RS-RESULT--\n" . json_encode($value,'
			. ' JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE); exit(0); }' . "\n"
			. 'try {' . "\n"
			. 'require_once(' . var_export($root . '/conf/config.php', true) . ");\n"
			. 'require_once(' . var_export($root . '/php/settings.php', true) . ");\n"
			. '$rsReflection = new ReflectionClass("rTorrentSettings");'
			. ' $rsSettings = $rsReflection->newInstanceWithoutConstructor();'
			. ' $rsSettings->iVersion = 0x1019; $rsSettings->apiVersion = 11;'
			. ' $rsSettings->aliases = array(); $rsSettings->linkExist = true;'
			. ' $rsSettings->directory = ' . var_export($settings['directory'], true) . ';'
			. ' $rsSettings->session = ' . var_export($settings['session'], true) . ';'
			. ' $rsProperty = $rsReflection->getProperty("theSettings");'
			. ' if(PHP_VERSION_ID < 80100) $rsProperty->setAccessible(true);'
			. ' $rsProperty->setValue(null, $rsSettings);' . "\n"
			. '$scgi_host = "127.0.0.1"; $scgi_port = ' . (int) $this->served['port'] . ';'
			. ' $rpcTimeOut = 10; $rpcLogCalls = false; $rpcLogFaults = false;'
			. ' $topDirectory = "/"; $saveUploadedTorrents = false;'
			. ' $log_file = ' . var_export($this->appLog, true) . ";\n"
			. 'require_once(' . var_export($root . '/php/xmlrpc.php', true) . ");\n"
			. 'require_once(' . var_export($root . '/php/rtorrent.php', true) . ");\n"
			. 'require_once(' . var_export(__DIR__ . '/RealStackErasedataStubs.php', true) . ");\n"
			. 'chdir(' . var_export($root . '/plugins/rutracker_check', true) . ");\n"
			. 'require_once("check.php");' . "\n"
			. '$rutrackerCheckDebug = true;' . "\n"
			. $body . "\n"
			. 'rsDone(null);' . "\n"
			. '} catch(Throwable $rsFailure) {'
			. ' rsDone(array("child-failure" => get_class($rsFailure) . ": " . $rsFailure->getMessage()'
			. ' . " at " . $rsFailure->getFile() . ":" . $rsFailure->getLine())); }' . "\n");
		$output = array();
		$status = 0;
		exec('TMPDIR=' . escapeshellarg($this->base) . ' ' . escapeshellarg(PHP_BINARY)
			. ' -d display_errors=stderr -d error_reporting=-1 ' . escapeshellarg($driver) . ' 2>&1',
			$output, $status);
		$printed = implode("\n", $output);
		$at = strrpos($printed, "\n--RS-RESULT--\n");
		if ($at === false)
			throw new RuntimeException('the child printed no result (status ' . $status . '): ' . $printed);
		$noise = trim(substr($printed, 0, $at));
		$answer = json_decode(substr($printed, $at + strlen("\n--RS-RESULT--\n")), true);
		if (is_array($answer) && isset($answer['child-failure']))
			throw new RuntimeException('the child failed: ' . $answer['child-failure']
				. ($noise !== '' ? ' -- output: ' . $noise : ''));
		if ($noise !== '')
			throw new RuntimeException('the child printed before its result: ' . $noise);
		return $answer;
	}

	/** Every erasedata cleanup call the last children made, in order. */
	public function erasedataCalls()
	{
		$calls = is_file($this->erasedataLog) ? json_decode(file_get_contents($this->erasedataLog), true) : array();
		return is_array($calls) ? $calls : array();
	}

	public function appLogLines()
	{
		$content = is_file($this->appLog) ? rtrim(file_get_contents($this->appLog), "\n") : '';
		if ($content === '')
			return array();
		return array_map(function ($line) {
			return preg_replace('/^\[[^\]]*\] /', '', $line);
		}, explode("\n", $content));
	}
}
