<?php

// Keep process witnesses and task records out of the shared checkout.
$_ENV['RU_PROFILE_PATH'] = sys_get_temp_dir().'/rutorrent task kill-'.getmypid().'-'.bin2hex(random_bytes(4));
require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/TaskNativeHelperFixture.php');
define('RTASK_KILL_HELPER', taskNativeHelperFixture());
require_once(__DIR__.'/../../../plugins/_task/task.php');

class TaskKillIdentityTest extends TestCase
{
	public function tearDownClass()
	{
		$profile = $_ENV['RU_PROFILE_PATH'];
		if (is_dir($profile)) {
			FileUtil::deleteDirectory($profile);
		}
	}

	protected function withWitness($check)
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
			$check($process, $pid);
		} finally {
			if (proc_get_status($process)['running']) {
				proc_terminate($process);
			}
			proc_close($process);
		}
	}

	protected function makeTask($pid, $identity = null)
	{
		$id = uniqid(time(), true);
		$dir = rTask::formatPath($id);
		mkdir($dir, 0777, true);
		file_put_contents($dir.'/pid', (string)$pid);
		file_put_contents($dir.'/flags', (string)rTask::FLG_RUN_AS_WEB);
		if ($identity !== null) {
			file_put_contents($dir.'/pid.identity', $identity);
		}
		return array($id, $dir);
	}

	protected function identityFor($pid)
	{
		$boot = file_get_contents('/proc/sys/kernel/random/boot_id');
		$stat = file_get_contents('/proc/'.$pid.'/stat');
		if ($boot === false || $stat === false) {
			throw new RuntimeException('Could not read the process witness identity');
		}
		return $boot.$stat;
	}

	protected function staleIdentityFor($pid)
	{
		$identity = $this->identityFor($pid);
		$separator = strpos($identity, "\n");
		if ($separator===false) {
			throw new RuntimeException('Could not parse the process witness identity');
		}
		$stat = substr($identity, $separator+1);
		$endName = strrpos($stat, ') ');
		if ($endName===false) {
			throw new RuntimeException('Could not parse the process witness identity');
		}
		$fields = preg_split('/\s+/', trim(substr($stat, $endName+2)));
		if (!isset($fields[19]) || !ctype_digit($fields[19])) {
			throw new RuntimeException('Could not locate the process start tick');
		}
		$fields[19] = (string)((int)$fields[19]+1);
		return substr($identity, 0, $separator+1).substr($stat, 0, $endName+2).implode(' ', $fields)."\n";
	}

	protected function assertRefusalVisible($result, $dir)
	{
		$this->assertEquals(false, $result, 'Unverifiable identity refuses the kill');
		$this->assertEquals(true, is_dir($dir), 'The refused task stays available for diagnosis');
		$this->assertEquals(true,
			is_file($dir.'/errors') && strpos(file_get_contents($dir.'/errors'), 'pid identity') !== false,
			'The refusal explains the pid identity problem in the task errors');
	}

	public function testNativeHelperSourceIsAvailableForIdentityBoundSignals()
	{
		$source = __DIR__.'/../../../plugins/_task/kill-verified.c';
		$this->assertEquals(true, is_file($source),
			'The task killer has a native source for identity-bound pidfd signals');
	}

	public function testParentExitDuringChildPassKeepsOrphanAndTaskVisible()
	{
		$process = proc_open(array('sh', '-c', 'sleep 30 & wait'), array(
			0 => array('file', '/dev/null', 'r'),
			1 => array('file', '/dev/null', 'w'),
			2 => array('file', '/dev/null', 'w'),
		), $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('Could not start the isolated parent and child witnesses');
		}
		$parent = proc_get_status($process)['pid'];
		$child = null;
		$worker = null;
		$cleanupIdentity = $_ENV['RU_PROFILE_PATH'].'/orphan-identity';
		$go = null;
		try {
			if ($parent <= 1 || $parent === getmypid()) {
				throw new RuntimeException('Unsafe parent witness PID');
			}
			for ($attempt = 0; $attempt < 20; ++$attempt) {
				$children = trim((string)@file_get_contents('/proc/'.$parent.'/task/'.$parent.'/children'));
				if (preg_match('/^([0-9]+)(?:\s|$)/', $children, $match)) {
					$child = (int)$match[1];
					break;
				}
				usleep(50000);
			}
			if ($child === null || $child <= 1 || $child === $parent) {
				throw new RuntimeException('Could not identify the isolated task child');
			}
			list($id, $dir) = $this->makeTask($parent, $this->identityFor($parent));
			file_put_contents($cleanupIdentity, $this->identityFor($child));
			$ready = $dir.'/helper-ready';
			$go = $dir.'/helper-go';
			$binary = taskNativeHelperFixture(true);
			$code = '$_ENV["RU_PROFILE_PATH"] = $argv[2]; '.
				'define("RTASK_KILL_HELPER", $argv[3]); require $argv[4]; '.
				'exit(rTask::kill($argv[1]) ? 0 : 1);';
			$environment = array_merge($_ENV, array(
				'RTASK_KILL_TEST_READY' => $ready,
				'RTASK_KILL_TEST_GO' => $go,
			));
			$worker = proc_open(array(PHP_BINARY, '-c', __DIR__.'/../../php-test.ini',
				'-r', $code, '--', $id, $_ENV['RU_PROFILE_PATH'], $binary,
				__DIR__.'/../../../plugins/_task/task.php'), array(
				0 => array('file', '/dev/null', 'r'),
				1 => array('file', '/dev/null', 'w'),
				2 => array('file', '/dev/null', 'w'),
			), $pipes, null, $environment);
			if (!is_resource($worker)) {
				throw new RuntimeException('Could not start the isolated kill request');
			}
			for ($attempt = 0; $attempt < 100 && !is_file($ready); ++$attempt) {
				usleep(10000);
			}
			$this->assertEquals(true, is_file($ready),
				'The helper reached the verified parent before its child pass');
			if (!is_file($ready)) return;
			proc_terminate($process);
			for ($attempt = 0; $attempt < 100 && proc_get_status($process)['running']; ++$attempt) {
				usleep(10000);
			}
			$this->assertEquals(false, proc_get_status($process)['running'],
				'The parent exited while its child remains alive');
			$this->assertEquals(false, $this->processIsGoneOrZombie($child),
				'The orphaned child is still alive at the signal boundary');
			file_put_contents($go, 'go');
			$this->assertEquals(1, proc_close($worker),
				'A parent exit with an unverified child refuses the kill request');
			$worker = null;
			$this->assertEquals(true, is_dir($dir),
				'The task record remains available for the orphan diagnosis');
			$this->assertEquals(true,
					is_file($dir.'/errors') && strpos(file_get_contents($dir.'/errors'), 'signal state uncertain') !== false,
					'The task error reports the uncertain child signal state');
		} finally {
			if ($go !== null) @file_put_contents($go, 'go');
			if (is_resource($worker)) {
				if (proc_get_status($worker)['running']) proc_terminate($worker);
				proc_close($worker);
			}
			if (proc_get_status($process)['running']) proc_terminate($process);
			proc_close($process);
			if ($child !== null && is_file($cleanupIdentity) && !$this->processIsGoneOrZombie($child)) {
				$cleanup = proc_open(array(RTASK_KILL_HELPER, (string)$child, $cleanupIdentity),
					array(0 => array('file', '/dev/null', 'r'),
						1 => array('file', '/dev/null', 'w'),
						2 => array('file', '/dev/null', 'w')), $pipes);
				if (is_resource($cleanup)) proc_close($cleanup);
			}
			@unlink($cleanupIdentity);
		}
	}

	protected function assertExitedWitnessAtNativeBoundary($readyVariable, $goVariable, $boundary)
	{
		$this->withWitness(function($process, $pid) use ($readyVariable, $goVariable, $boundary) {
			$binary = taskNativeHelperFixture(true);
			list($id, $dir) = $this->makeTask($pid, $this->identityFor($pid));
			$ready = $dir.'/helper-ready';
			$go = $dir.'/helper-go';
			$environment = array_merge($_ENV, array(
				$readyVariable => $ready,
				$goVariable => $go,
			));
			$helper = proc_open(array($binary, (string)$pid, $dir.'/pid.identity'), array(
				0 => array('file', '/dev/null', 'r'),
				1 => array('file', '/dev/null', 'w'),
				2 => array('file', '/dev/null', 'w'),
			), $pipes, null, $environment);
			if (!is_resource($helper)) {
				throw new RuntimeException('Could not start the paused native task killer');
			}
			try {
				for ($attempt = 0; $attempt < 100 && !is_file($ready); ++$attempt) {
					usleep(10000);
				}
				$this->assertEquals(true, is_file($ready),
					'The native helper reached '.$boundary);
				if (!is_file($ready)) return;
				proc_terminate($process);
				for ($attempt = 0; $attempt < 100 && proc_get_status($process)['running']; ++$attempt) {
					usleep(10000);
				}
				$this->assertEquals(false, proc_get_status($process)['running'],
					'The witnessed process exited at '.$boundary);
				file_put_contents($go, 'go');
				$this->assertEquals(4, proc_close($helper),
					'An unobserved exit at '.$boundary.' refuses uncertain descendants');
				$helper = null;
			} finally {
				@file_put_contents($go, 'go');
				if (is_resource($helper)) {
					if (proc_get_status($helper)['running']) proc_terminate($helper);
					proc_close($helper);
				}
			}
		});
	}

	public function testExitedWitnessAfterVerificationRefusesUncertainDescendants()
	{
		$this->assertExitedWitnessAtNativeBoundary(
			'RTASK_KILL_TEST_READY', 'RTASK_KILL_TEST_GO', 'the identity check boundary');
	}

	public function testExitedWitnessAfterChildEnumerationRefusesUncertainDescendants()
	{
		$this->assertExitedWitnessAtNativeBoundary(
			'RTASK_KILL_TEST_AFTER_CHILDREN_READY', 'RTASK_KILL_TEST_AFTER_CHILDREN_GO',
			'the final parent signal boundary');
	}

	public function testMissingNativeHelperRefusesVisibly()
	{
		$this->withWitness(function($process, $pid) {
			list($id, $dir) = $this->makeTask($pid, $this->identityFor($pid));
			$held = RTASK_KILL_HELPER.'.held';
			if (!rename(RTASK_KILL_HELPER, $held)) {
				throw new RuntimeException('Could not isolate the native helper fixture');
			}
			try {
				$result = rTask::kill($id);
				$this->assertEquals(false, $result, 'A missing native helper refuses the kill');
				$this->assertEquals(true, proc_get_status($process)['running'],
					'The missing helper does not trigger a numeric-kill fallback');
				$this->assertEquals(true, is_dir($dir), 'The refused task stays for diagnosis');
				$this->assertEquals(true,
					strpos(file_get_contents($dir.'/errors'), 'pidfd helper unavailable') !== false,
					'The task error identifies the missing native helper');
			} finally {
				rename($held, RTASK_KILL_HELPER);
			}
		});
	}

	public function testReusedPidDoesNotKillAnUnrelatedProcess()
	{
		$this->withWitness(function($process, $pid) {
			// Model a persisted task whose PID was later assigned to this witness.
			// The recorded PID and boot match, but its start tick is stale.
			list($id, $dir) = $this->makeTask($pid, $this->staleIdentityFor($pid));
			$result = rTask::kill($id);
			usleep(100000);
			$this->assertEquals(true, proc_get_status($process)['running'],
				'A reused PID cannot kill an unrelated same-user process');
			$this->assertRefusalVisible($result, $dir);
		});
	}

	public function testLegacyPidWithoutIdentityCannotKillAWitness()
	{
		$this->withWitness(function($process, $pid) {
			list($id, $dir) = $this->makeTask($pid);
			$result = rTask::kill($id);
			usleep(100000);
			$this->assertEquals(true, proc_get_status($process)['running'],
				'A legacy PID alone cannot authorize a signal');
			$this->assertRefusalVisible($result, $dir);
		});
	}

	public function testMatchingProcessBirthCanBeKilled()
	{
		$this->withWitness(function($process, $pid) {
			list($id, $dir) = $this->makeTask($pid, $this->identityFor($pid));
			$result = rTask::kill($id);
			for ($attempt = 0; $attempt < 20 && proc_get_status($process)['running']; $attempt++) {
				usleep(50000);
			}
			$this->assertEquals(false, proc_get_status($process)['running'],
				'The verified task process stops');
			$this->assertEquals(true, $result, 'A verified kill succeeds');
			$this->assertEquals(false, is_dir($dir), 'The completed task record is removed');
		});
	}

	public function testVerifiedTaskWithChildStopsBothProcesses()
	{
		$process = proc_open(array('sh', '-c', 'sleep 3 & wait'), array(
			0 => array('file', '/dev/null', 'r'),
			1 => array('file', '/dev/null', 'w'),
			2 => array('file', '/dev/null', 'w'),
		), $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('Could not start the isolated task shell');
		}
		$pid = proc_get_status($process)['pid'];
		$child = null;
		try {
			if ($pid <= 1 || $pid === getmypid()) {
				throw new RuntimeException('Unsafe task shell PID');
			}
			for ($attempt = 0; $attempt < 20; $attempt++) {
				$children = trim((string)@file_get_contents('/proc/'.$pid.'/task/'.$pid.'/children'));
				if (preg_match('/^([0-9]+)(?:\s|$)/', $children, $match)) {
					$child = (int)$match[1];
					break;
				}
				usleep(50000);
			}
			if ($child === null || $child <= 1 || $child === $pid) {
				throw new RuntimeException('Could not identify the task-owned child');
			}
			list($id, $dir) = $this->makeTask($pid, $this->identityFor($pid));
			$result = rTask::kill($id);
			for ($attempt = 0; $attempt < 20 && proc_get_status($process)['running']; $attempt++) {
				usleep(50000);
			}
			for ($attempt = 0; $attempt < 20 && !$this->processIsGoneOrZombie($child); $attempt++) {
				usleep(50000);
			}
			$this->assertEquals(true, $result, 'The verified shell and its child are cancelled');
			$this->assertEquals(false, proc_get_status($process)['running'], 'The task shell stopped');
			$this->assertEquals(true, $this->processIsGoneOrZombie($child), 'The task child stopped');
		} finally {
			if (proc_get_status($process)['running']) {
				proc_terminate($process);
			}
			proc_close($process);
		}
	}

	protected function processIsGoneOrZombie($pid)
	{
		$stat = @file_get_contents('/proc/'.$pid.'/stat');
		return($stat===false || preg_match('/^'.(int)$pid.' \(.+\) Z /', $stat)===1);
	}

	public function testLocalRunPreservesShellQuotedArguments()
	{
		$marker = $_ENV['RU_PROFILE_PATH'].'/run-quoted-output';
		$value = 'literal $HOME " and a single quote '.chr(39);
		$cmd = 'printf %s '.escapeshellarg($value).' > '.escapeshellarg($marker);
		$result = rTask::run($cmd, rTask::FLG_RUN_AS_WEB | rTask::FLG_WAIT | rTask::FLG_RUN_AS_CMD);
		$this->assertEquals(0, $result, 'The local shell command succeeds');
		$this->assertEquals($value, file_get_contents($marker),
			'Quoted command arguments reach the task shell unchanged');
	}

	public function testLocalRunExecutesScriptPathWithSpaces()
	{
		$profile = $_ENV['RU_PROFILE_PATH'];
		if (!is_dir($profile)) {
			mkdir($profile, 0777, true);
		}
		$script = $profile.'/task start with spaces.sh';
		$marker = $profile.'/script-ran';
		file_put_contents($script, '#!/bin/sh'."\n".'printf done > '.escapeshellarg($marker)."\n");
		$result = rTask::run($script, rTask::FLG_RUN_AS_WEB | rTask::FLG_WAIT);
		$this->assertEquals(0, $result, 'A script path with spaces runs directly');
		$this->assertEquals('done', is_file($marker) ? file_get_contents($marker) : null,
			'The intended script ran without shell word splitting');
	}

	public function testStartedTaskRecordsItsProcessBirth()
	{
		$task = new rTask(array('name'=>'identity fixture'));
		$result = $task->start(array('true'), rTask::FLG_RUN_AS_WEB | rTask::FLG_WAIT);
		$dir = rTask::formatPath($task->id);
		$this->assertEquals(true, is_file($dir.'/pid'), 'The local task wrote its PID');
		$this->assertEquals(true, isset($result['status']) && $result['status']===0,
			'The harmless task completed successfully');
		$this->assertEquals(true, is_file($dir.'/pid.identity'),
			'The task recorded a verifiable process birth beside its PID');
		if (is_file($dir.'/pid.identity')) {
			$identity = file_get_contents($dir.'/pid.identity');
			$this->assertEquals(true, strpos($identity, trim(file_get_contents('/proc/sys/kernel/random/boot_id'))."\n")===0,
				'The identity includes the current boot ID');
		}
	}
}
