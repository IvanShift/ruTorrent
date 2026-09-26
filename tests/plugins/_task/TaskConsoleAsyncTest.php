<?php

// Run real task scripts in a private profile; the action flags are read from
// the two console-task callers so the test exercises their dispatch contract.
$_ENV['RU_PROFILE_PATH'] = sys_get_temp_dir().'/rutorrent-task-console-'.getmypid().'-'.bin2hex(random_bytes(4));

require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/TaskNativeSupervisorFixture.php');
if (!defined('RTASK_SUPERVISOR_HELPER'))
	define('RTASK_SUPERVISOR_HELPER', taskNativeSupervisorFixture());
require_once(__DIR__.'/../../../plugins/_task/task.php');

class TaskConsoleAsyncTest extends TestCase
{
	public function tearDownClass()
	{
		$profile = $_ENV['RU_PROFILE_PATH'];
		if (is_dir($profile)) FileUtil::deleteDirectory($profile);
	}

	private function callerFlags($plugin)
	{
		$path = __DIR__.'/../../../plugins/'.$plugin.'/action.php';
		$source = file_get_contents($path);
		if (!preg_match('/\$task->start\(\s*\$commands\s*,\s*([^\)]*)\)/', $source, $match)) {
			throw new RuntimeException('No console task start call in '.$path);
		}
		return eval('return '.$match[1].';');
	}

	public function testSlowDumpAndMediaInfoReturnBeforeTheirTaskCompletes()
	{
		foreach (array('dump', 'mediainfo') as $plugin) {
			$task = new rTask(array('requester'=>$plugin, 'name'=>$plugin));
			$start = microtime(true);
			$result = $task->start(array('sleep 4; printf complete'), $this->callerFlags($plugin));
			$elapsed = microtime(true) - $start;
			$this->assertEquals(true, $elapsed < 3.0,
				$plugin.' returns the task number before the slow command completes');
			$this->assertEquals(-1, $result['status'],
				$plugin.' is still running when its console request returns');

			$deadline = microtime(true) + 6.0;
			while ($result['status'] < 0 && microtime(true) < $deadline) {
				usleep(100000);
				$result = rTask::check($task->id);
			}
			$this->assertEquals(0, $result['status'],
				$plugin.' completes through the existing task check path');
			$this->assertEquals(array('complete'), $result['log'],
				$plugin.' keeps its completed console output');
		}
	}
}
