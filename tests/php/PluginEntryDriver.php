<?php

require_once(__DIR__ . '/FakeRtorrentDaemon.php');

/**
 * Runs a shipped plugin entry script in a PHP process of its own against a
 * FakeRtorrentDaemon, the way EditActionReloadTest drives
 * plugins/edit/action.php: conf/config.php, then a seeded rTorrentSettings so
 * that no settings probe reaches the daemon, then the script itself from its
 * own directory.
 *
 * rTask is the real one. RTASK_SUPERVISOR_HELPER is defined to a path that
 * does not exist, so rTask::start() writes the task's start.sh -- the exact
 * shell commands the plugin built -- and then refuses to launch it, keeping
 * the task directory. taskScripts() reads those commands back; nothing is
 * ever executed.
 *
 * $options:
 *   iVersion  daemon version the settings claim (default 0x1019)
 *   post      request values; REQUEST_METHOD becomes POST
 *   get       request values for a GET
 *   before    PHP run after the settings are seeded and before the script
 */
final class PluginEntryDriver
{
	public static function run($base, $port, $script, array $options = array())
	{
		$root = realpath(__DIR__ . '/../..');
		@mkdir($base . '/profile/settings', 0700, true);
		$method = isset($options['post']) ? 'POST' : 'GET';
		$request = $options['post'] ?? ($options['get'] ?? array());
		$driver = $base . '/drive-' . bin2hex(random_bytes(4)) . '.php';
		file_put_contents($driver, "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($base . '/profile', true) . ";\n"
			. 'define(\'RTASK_SUPERVISOR_HELPER\', ' . var_export($base . '/no-supervisor', true) . ");\n"
			. 'require_once(' . var_export($root . '/conf/config.php', true) . ");\n"
			. 'require_once(' . var_export($root . '/php/settings.php', true) . ");\n"
			. '$r = new ReflectionClass("rTorrentSettings"); $s = $r->newInstanceWithoutConstructor();'
			. ' $s->iVersion = ' . var_export($options['iVersion'] ?? 0x1019, true) . ';'
			. ' $s->aliases = array(); $s->directory = "/"; $s->linkExist = true;'
			. ' $p = $r->getProperty("theSettings");'
			. ' if(PHP_VERSION_ID < 80100) $p->setAccessible(true); $p->setValue(null, $s);' . "\n"
			. '$scgi_host = "127.0.0.1";' . "\n"
			. '$scgi_port = ' . (int)$port . ";\n"
			. '$rpcTimeOut = 10;' . "\n"
			. '$rpcLogCalls = false;' . "\n"
			. '$log_file = ' . var_export($base . '/application.log', true) . ";\n"
			. '$topDirectory = "/";' . "\n"
			. '$_SERVER[\'REQUEST_METHOD\'] = ' . var_export($method, true) . ";\n"
			. '$_REQUEST = ' . var_export($request, true) . ";\n"
			. ($method === 'POST' ? '$_POST = $_REQUEST;' : '$_GET = $_REQUEST;') . "\n"
			. ($options['before'] ?? '') . "\n"
			. 'chdir(' . var_export(dirname($root . '/' . $script), true) . ");\n"
			. 'require(' . var_export($root . '/' . $script, true) . ");\n");
		$process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', $driver),
			array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		if (!is_resource($process))
			throw new RuntimeException('plugin entry driver did not start');
		fclose($pipes[0]);
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return array(proc_close($process), $out, $err);
	}

	/** The start.sh text of every task the run tried to start. */
	public static function taskScripts($base)
	{
		$tasks = $base . '/profile/settings/tasks';
		$scripts = array();
		foreach (is_dir($tasks) ? scandir($tasks) : array() as $entry)
			if ($entry !== '.' && $entry !== '..' && is_file($tasks . '/' . $entry . '/start.sh'))
				$scripts[] = file_get_contents($tasks . '/' . $entry . '/start.sh');
		return $scripts;
	}

	/** Removes a tree with scandir: fixture names may hold glob syntax. */
	public static function removeTree($path)
	{
		if (is_link($path) || is_file($path)) {
			@unlink($path);
			return;
		}
		if (!is_dir($path))
			return;
		foreach (scandir($path) as $entry)
			if ($entry !== '.' && $entry !== '..')
				self::removeTree($path . '/' . $entry);
		@rmdir($path);
	}
}
