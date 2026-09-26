<?php

require_once(__DIR__ . '/../../php/TestCase.php');

class RetrackersDonePluginsTest extends TestCase
{
	private function removeTree($path)
	{
		if (!is_dir($path)) {
			return;
		}
		foreach (array_diff(scandir($path), array('.', '..')) as $entry) {
			$child = $path . '/' . $entry;
			if (is_dir($child)) {
				$this->removeTree($child);
			} else {
				unlink($child);
			}
		}
		rmdir($path);
	}

	private function runActualShutdown($command, $success)
	{
		$root = sys_get_temp_dir() . '/rutorrent-retrackers-doneplugins-' .
			getmypid() . '-' . bin2hex(random_bytes(4));
		mkdir($root . '/php', 0700, true);
		mkdir($root . '/plugins/retrackers', 0700, true);
		mkdir($root . '/plugins/ordinary', 0700, true);
		copy(__DIR__ . '/../../../plugins/retrackers/done.php',
			$root . '/plugins/retrackers/done.php');
		file_put_contents($root . '/plugins/retrackers/update.php', <<<'PHP'
<?php
function retrackersRunLifecycleDone($user, &$jResult, &$failure) {
	$failure = 'hook-teardown-pending';
	if (!$GLOBALS['doneSucceeds']) {
		$jResult .= "noty('retrackers: hook-teardown-pending','error');";
		return false;
	}
	return true;
}
PHP
		);
		file_put_contents($root . '/plugins/ordinary/done.php',
			'<?php $GLOBALS["ordinaryDoneCalls"]++;');
		file_put_contents($root . '/php/xmlrpc.php', '<?php');
		file_put_contents($root . '/php/settings.php', '<?php');
		$source = realpath(__DIR__ . '/../../../php/doneplugins.php');
		$fixture = <<<'PHP'
<?php
class rTorrentSettings {
	public static $instance;
	public $plugins = array('retrackers' => 0x0101, 'ordinary' => 0x0101);
	public $unregistered = array();
	public $stored = 0;
	public static function get() {
		if (!self::$instance) self::$instance = new self();
		return self::$instance;
	}
	public function getPluginData($name) {
		return isset($this->plugins[$name]) ? $this->plugins[$name] : null;
	}
	public function unregisterPlugin($name) {
		$this->unregistered[] = $name;
		unset($this->plugins[$name]);
	}
	public function store() { $this->stored++; }
}
class rCache {
	public static $permissions = null;
	public function get(&$value) { return true; }
	public function set($value) { self::$permissions = $value; }
}
class User { public static function getUser() { return 'alice'; } }
class CachedEcho {
	public static function send($body, $type) { echo "J:" . $body; }
}
$GLOBALS['ordinaryDoneCalls'] = 0;
$GLOBALS['doneSucceeds'] = DONE_SUCCEEDS;
$HTTP_RAW_POST_DATA = 'plg=retrackers&plg=retrackers&plg=ordinary';
$_REQUEST['cmd'] = COMMAND;
include SOURCE_PATH;
echo "\nS:", json_encode(array(
	'plugins' => rTorrentSettings::get()->plugins,
	'unregistered' => rTorrentSettings::get()->unregistered,
	'stored' => rTorrentSettings::get()->stored,
	'ordinary_calls' => $GLOBALS['ordinaryDoneCalls'],
	'permissions' => rCache::$permissions,
));
PHP;
		$fixture = str_replace(array('DONE_SUCCEEDS', 'COMMAND', 'SOURCE_PATH'),
			array($success ? 'true' : 'false', var_export($command, true),
				var_export($source, true)), $fixture);
		file_put_contents($root . '/php/run.php', $fixture);
		try {
			$process = proc_open(array(PHP_BINARY, $root . '/php/run.php'),
				array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'),
					2 => array('pipe', 'w')), $pipes, $root . '/php');
			if (!is_resource($process)) {
				return(false);
			}
			fclose($pipes[0]);
			$out = stream_get_contents($pipes[1]);
			fclose($pipes[1]);
			$err = stream_get_contents($pipes[2]);
			fclose($pipes[2]);
			$exit = proc_close($process);
			if ($exit !== 0 || $err !== '' ||
				!preg_match('/^J:(.*)\nS:(\{.*\})$/sD', $out, $match)) {
				return(false);
			}
			$state = json_decode($match[2], true);
			return(is_array($state) ? array('js' => $match[1], 'state' => $state) : false);
		} finally {
			$this->removeTree($root);
		}
	}

	public function testActualDonepluginsKeepsAVetoedPluginAndAllowsOtherShutdown()
	{
		$result = $this->runActualShutdown('done', false);
		$this->assertTrue(is_array($result) &&
			strpos($result['js'], 'retrackers: hook-teardown-pending') !== false &&
			strpos($result['js'], "thePlugins.get('retrackers').remove()") === false &&
			strpos($result['js'], "thePlugins.get('ordinary').remove()") !== false &&
			isset($result['state']['plugins']['retrackers']) &&
			!isset($result['state']['plugins']['ordinary']) &&
			$result['state']['unregistered'] === array('ordinary') &&
			$result['state']['ordinary_calls'] === 1,
			'the actual doneplugins path honors the retrackers veto and still shuts down another plugin');
	}

	public function testActualUnlaunchLeavesAVetoedPluginRetryable()
	{
		$result = $this->runActualShutdown('unlaunch', false);
		$this->assertTrue(is_array($result) &&
			strpos($result['js'], "thePlugins.get('retrackers').unlaunch()") === false &&
			strpos($result['js'], "thePlugins.get('retrackers').remove()") === false &&
			isset($result['state']['plugins']['retrackers']) &&
			!isset($result['state']['permissions']['retrackers']),
			'a refused unlaunch keeps retrackers visible and registered for retry');
	}

	public function testActualUnlaunchStillCompletesAfterSuccessfulShutdown()
	{
		$result = $this->runActualShutdown('unlaunch', true);
		$this->assertTrue(is_array($result) &&
			$result['state']['unregistered'] === array('retrackers', 'ordinary') &&
			$result['state']['permissions']['retrackers'] === false &&
			strpos($result['js'], "thePlugins.get('retrackers').unlaunch()") !== false &&
			strpos($result['js'], "thePlugins.get('retrackers').remove()") !== false,
			'a completed shutdown keeps the ordinary unlaunch and removal actions');
	}

	public function testActualDonepluginsStillRemovesSuccessfulPlugin()
	{
		$result = $this->runActualShutdown('done', true);
		$this->assertTrue(is_array($result) &&
			$result['state']['unregistered'] === array('retrackers', 'ordinary') &&
			strpos($result['js'], "thePlugins.get('retrackers').remove()") !== false &&
			$result['state']['ordinary_calls'] === 1,
			'successful retrackers and ordinary shutdowns retain the existing completion route');
	}
}
