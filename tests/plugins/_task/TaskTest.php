<?php

// Each file runs in its own PHP process; keep task fixtures off the checkout.
$_ENV['RU_PROFILE_PATH'] = sys_get_temp_dir().'/rutorrent-task-test-'.getmypid().'-'.bin2hex(random_bytes(4));

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__.'/TaskNativeHelperFixture.php');
define('RTASK_KILL_HELPER', taskNativeHelperFixture());
require_once(__DIR__ . '/../../../plugins/_task/task.php');

// A payload class for the params test: unserialize() must not construct it, so
// its __wakeup() must never run.
class TaskPayload
{
	public static $woken = false;
	public function __wakeup()
	{
		self::$woken = true;
	}
}

class TaskTest extends TestCase
{
	protected $tasks = null;
	protected $marker = null;
	protected $outside = null;

	public function setUp()
	{
		$this->tasks = FileUtil::getSettingsPath().'/tasks';
		$this->marker = FileUtil::getSettingsPath().'/task-test-marker';
		$this->outside = FileUtil::getSettingsPath().'/task-test-outside';
		@mkdir($this->tasks, 0777, true);
		@unlink($this->marker);
		TaskPayload::$woken = false;
	}

	public function tearDown()
	{
		@unlink($this->marker);
		$this->removeDir($this->outside);
		$profile = $_ENV['RU_PROFILE_PATH'];
		if (is_dir($profile)) {
			FileUtil::deleteDirectory($profile);
		}
	}

	protected function removeDir($dir)
	{
		foreach (array('pid', 'pid.identity', 'status', 'flags', 'params', 'errors') as $file) {
			@unlink($dir.'/'.$file);
		}
		@rmdir($dir);
	}

	// A task the plugin itself could have produced: a real id, and the flags
	// value that makes rTask::run() use the local shell rather than rtorrent.
	protected function makeTask($pid, $params = null)
	{
		$id = uniqid(time(), true);
		$dir = rTask::formatPath($id);
		mkdir($dir, 0777, true);
		file_put_contents($dir.'/pid', $pid);
		file_put_contents($dir.'/flags', rTask::FLG_RUN_AS_WEB);
		if (!is_null($params)) {
			file_put_contents($dir.'/params', $params);
		}
		return array($id, $dir);
	}

	public function testFixturesUseAnIsolatedProfile()
	{
		$this->assertEquals($_ENV['RU_PROFILE_PATH'].'/settings', FileUtil::getSettingsPath(),
			'Task fixtures stay under TMPDIR rather than the checkout');
	}

	public function testKillRunsNothingFromANonNumericPidFile()
	{
		list($id, $dir) = $this->makeTask('not-a-pid$(touch '.$this->marker.')');
		$result = rTask::kill($id);
		$this->assertEquals(false, file_exists($this->marker),
			'The contents of a pid file cannot reach the shell');
		$this->assertEquals(false, $result, 'A malformed PID refuses the kill');
		$this->assertEquals(true, is_dir($dir), 'The refused task stays available for diagnosis');
	}

	public function testKillStopsTheProcessThePidFileNames()
	{
		$process = proc_open(array('sleep', '30'), array(
			0 => array('file', '/dev/null', 'r'),
			1 => array('file', '/dev/null', 'w'),
			2 => array('file', '/dev/null', 'w'),
		), $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('Could not start the isolated process witness');
		}
		try {
			$pid = proc_get_status($process)['pid'];
			if ($pid <= 1 || $pid === getmypid()) {
				throw new RuntimeException('Unsafe process witness PID');
			}
			list($id, $dir) = $this->makeTask($pid);
			file_put_contents($dir.'/pid.identity',
				file_get_contents('/proc/sys/kernel/random/boot_id').
				file_get_contents('/proc/'.$pid.'/stat'));
			$result = rTask::kill($id);
			$this->assertEquals(true, $result, 'A matching PID identity permits the kill');
			for ($attempt = 0; $attempt < 20 && proc_get_status($process)['running']; $attempt++) {
				usleep(50000);
			}
			$this->assertEquals(false, proc_get_status($process)['running'], 'The named process is gone');
		} finally {
			if (proc_get_status($process)['running']) {
				proc_terminate($process);
			}
			proc_close($process);
		}
	}

	public function testCheckDoesNotConstructClassesNamedByTheParamsFile()
	{
		list($id, $dir) = $this->makeTask('999999', serialize(new TaskPayload()));
		$ret = rTask::check($id);
		$this->assertEquals(false, TaskPayload::$woken,
			'No class from the params file is woken');
		$this->assertEquals(true, $ret['params'] instanceof __PHP_Incomplete_Class,
			'The object arrives inert');
		$this->removeDir($dir);
	}

	public function testCheckOfAPathOutsideTheTasksDirectoryFindsNoTask()
	{
		mkdir($this->outside, 0777, true);
		file_put_contents($this->outside.'/pid', '424242');
		file_put_contents($this->outside.'/params', serialize(array('leaked' => 'data')));
		$ret = rTask::check('../'.basename($this->outside));
		$this->assertEquals(0, $ret['pid'], 'No pid is read from outside the tasks directory');
		$this->assertEquals(array(), $ret['params'], 'No params are read from there either');
		$this->assertEquals(true, is_file($this->outside.'/pid'), 'And nothing there was touched');
	}
}
