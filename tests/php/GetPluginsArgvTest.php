<?php

require_once(__DIR__ . '/TestCase.php');

/**
 * php/getplugins.php warned phpParameterUnavailable when its own SAPI had
 * register_argc_argv off. The plugins that need PHP run it as a CLI process,
 * which receives $argv whatever php.ini says, and PHP 8.5 deprecates the
 * directive for web SAPIs: turning it on to silence the warning makes every
 * PHP-FPM request raise E_DEPRECATED (round 3, NF-2).
 *
 * getplugins.php is run as in PluginOrderTest: in a process of its own, as a
 * same-origin request, against a temporary profile and an rTorrent socket
 * that does not exist.
 */
final class GetPluginsArgvTest extends TestCase
{
	private $tree;

	public function setUp()
	{
		$this->tree = sys_get_temp_dir() . '/rutorrent-getplugins-argv-' . getmypid();
		$this->wipe();
	}

	public function tearDown()
	{
		$this->wipe();
	}

	private function wipe()
	{
		if (!is_dir($this->tree))
			return;
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->tree, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($items as $item)
			$item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		@rmdir($this->tree);
	}

	/** What getplugins.php answers when its SAPI has register_argc_argv at $value. */
	private function bootstrap($value)
	{
		$this->wipe();
		@mkdir($this->tree . '/profile/settings', 0700, true);
		@mkdir($this->tree . '/profile/torrents', 0700, true);
		@mkdir($this->tree . '/tmp', 0700, true);
		$probe = $this->tree . '/probe.php';
		file_put_contents($probe, "<?php\n"
			. '$_SERVER["REQUEST_METHOD"] = "GET";' . "\n"
			. '$_SERVER["HTTP_HOST"] = "localhost";' . "\n"
			. '$_SERVER["HTTP_X_REQUESTED_WITH"] = "XMLHttpRequest";' . "\n"
			. '$_SERVER["HTTP_SEC_FETCH_SITE"] = "same-origin";' . "\n"
			. '$_SERVER["HTTP_ORIGIN"] = "http://localhost";' . "\n"
			. '$_ENV["RU_PROFILE_PATH"] = ' . var_export($this->tree . '/profile', true) . ";\n"
			. '$_ENV["RU_TEMP_DIRECTORY"] = ' . var_export($this->tree . '/tmp/', true) . ";\n"
			. '$_ENV["RU_LOG_FILE"] = ' . var_export($this->tree . '/errors.log', true) . ";\n"
			. '$_ENV["RU_SCGI_PORT"] = 0;' . "\n"
			. '$_ENV["RU_SCGI_HOST"] = ' . var_export('unix://' . $this->tree . '/none.sock', true) . ";\n"
			. 'chdir(' . var_export(dirname(__DIR__, 2) . '/php', true) . ");\n"
			. 'require("getplugins.php");' . "\n");
		$output = array();
		exec(escapeshellarg(PHP_BINARY) . ' -d register_argc_argv=' . escapeshellarg($value)
			. ' -d error_reporting=0 -d display_errors=0 ' . escapeshellarg($probe) . ' 2>&1', $output);
		return implode("\n", $output);
	}

	public function testAWebSapiWithoutArgvDoesNotWarnAboutThePluginWorkers()
	{
		$js = $this->bootstrap('0');
		$this->assertTrue((bool)preg_match("/new\\s+rPlugin\\(\\s*'_task'/", $js),
			'getplugins.php registered _task, which runs the PHP CLI: ' . substr($js, 0, 300));
		$this->assertTrue(strpos($js, 'phpParameterUnavailable') === false,
			'the web SAPI register_argc_argv setting is not reported as a plugin problem');
	}
}
