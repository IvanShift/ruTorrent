<?php

$_ENV['RU_PROFILE_PATH'] = sys_get_temp_dir().'/rutorrent-supervisor-'.getmypid().'-'.bin2hex(random_bytes(4));
require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/TaskNativeHelperFixture.php');
require_once(__DIR__.'/TaskNativeSupervisorFixture.php');
define('RTASK_KILL_HELPER', taskNativeHelperFixture());
define('RTASK_SUPERVISOR_HELPER', taskNativeSupervisorFixture());
require_once(__DIR__.'/../../../plugins/_task/task.php');

class TaskSupervisorTest extends TestCase
{
    private function waitFor($path)
    {
        for ($i=0; $i<200 && !is_file($path); ++$i) usleep(10000);
        return is_file($path);
    }

    private function stopWitness($pid, $identity, $directory)
    {
        if ($pid <= 1 || $identity === null || !is_dir('/proc/'.$pid)) return;
        $path = $directory.'/cleanup-'.$pid.'.identity';
        file_put_contents($path, $identity);
        $process = proc_open(array(RTASK_KILL_HELPER, (string)$pid, $path),
            array(0=>array('file','/dev/null','r'),1=>array('file','/dev/null','w'),
                2=>array('file','/dev/null','w')), $pipes);
        if (is_resource($process)) proc_close($process);
        @unlink($path);
    }

    public function testSupervisorReapsAnOrphanedGrandchildBeforePublishingCompletion()
    {
        $binary = taskNativeSupervisorFixture();
        $dir = sys_get_temp_dir().'/task-supervisor-tree-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $grandchild = $dir.'/grandchild';
        $script = $dir.'/start.sh';
        $inner = 'sleep 30 & printf "%s\\n" "$!" > '.escapeshellarg($grandchild).'; wait';
        file_put_contents($script, '#!/bin/sh'."\n".'sh -c '.escapeshellarg($inner).' & wait' . "\n");
        chmod($script, 0700);
        $process = proc_open(array($binary, $dir), array(0=>array('file','/dev/null','r'),
            1=>array('file','/dev/null','w'), 2=>array('file','/dev/null','w')), $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not launch supervisor');
        $childPid = null;
        $childIdentity = null;
        try {
            $this->assertEquals(true, $this->waitFor($dir.'/pid'), 'Supervisor publishes its PID');
            $this->assertEquals(true, $this->waitFor($dir.'/pid.identity'), 'Supervisor publishes identity');
            $this->assertEquals(true, $this->waitFor($grandchild), 'Three-generation worker started');
            $this->assertEquals(false, is_file($dir.'/supervisor.complete'), 'Live grandchild blocks completion');
            $childPid = (int)trim(file_get_contents($grandchild));
            $childIdentity = file_get_contents('/proc/sys/kernel/random/boot_id').
                file_get_contents('/proc/'.$childPid.'/stat');
            $pid = trim(file_get_contents($dir.'/pid'));
            $request = proc_open(array(RTASK_KILL_HELPER, $pid, $dir.'/pid.identity', '--supervisor-cancel', $binary),
                array(0=>array('file','/dev/null','r'),1=>array('file','/dev/null','w'),
                    2=>array('file','/dev/null','w')), $requestPipes);
            $this->assertEquals(0, proc_close($request), 'Identity-bound cancellation request reaches supervisor');
            $completed = $this->waitFor($dir.'/supervisor.complete');
            $this->assertEquals(true, $completed, 'Supervisor proves all descendants gone before completion');
            if (!$completed) return;
            $this->assertEquals(0, proc_close($process), 'Supervisor exits after cancellation');
            $process = null;
            $this->assertEquals(false, is_dir('/proc/'.$childPid), 'Grandchild was reaped');
        } finally {
            if (is_resource($process)) {
                $info = proc_get_status($process);
                if ($info['running'] && $info['pid'] > 1) proc_terminate($process, 9);
                proc_close($process);
            }
            if ($childPid !== null) $this->stopWitness($childPid, $childIdentity, $dir);
            foreach (array('start.sh','pid','pid.identity','supervisor.version','supervisor.complete',
                'status','grandchild','errors') as $name) @unlink($dir.'/'.$name);
            @rmdir($dir);
        }
    }

    public function testDoubleForkAcrossSetsidRemainsUnderSupervisor()
    {
        $binary = taskNativeSupervisorFixture();
        $dir = sys_get_temp_dir().'/task-supervisor-setsid-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $marker = $dir.'/orphan-pid';
        $leaf = 'sleep 30 & printf "%s\n" "$!" > '.escapeshellarg($marker);
        $middle = 'sh -c '.escapeshellarg($leaf).' & wait';
        file_put_contents($dir.'/start.sh', '#!/bin/sh'."\n".
            'setsid sh -c '.escapeshellarg($middle).' & wait'."\n");
        $process = proc_open(array($binary, $dir), array(0=>array('file','/dev/null','r'),
            1=>array('file','/dev/null','w'), 2=>array('file','/dev/null','w')), $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start setsid tree');
        $orphan = null;
        $identity = null;
        try {
            $this->assertEquals(true, $this->waitFor($dir.'/pid'), 'Supervisor published its PID');
            $this->assertEquals(true, $this->waitFor($dir.'/pid.identity'), 'Supervisor started');
            $this->assertEquals(true, $this->waitFor($marker), 'Double fork created detached worker');
            if (!is_file($marker)) return;
            $orphan = (int)trim(file_get_contents($marker));
            $identity = file_get_contents('/proc/sys/kernel/random/boot_id').
                file_get_contents('/proc/'.$orphan.'/stat');
            for ($i=0; $i<100; ++$i) {
                $stat = @file_get_contents('/proc/'.$orphan.'/stat');
                if ($stat !== false && preg_match('/^'.(int)$orphan.' \(.+\) [A-Z] (\d+) /', $stat, $m)
                    && (int)$m[1] === (int)trim(file_get_contents($dir.'/pid'))) break;
                usleep(10000);
            }
            $this->assertEquals((int)trim(file_get_contents($dir.'/pid')), isset($m[1]) ? (int)$m[1] : 0,
                'Subreaper adopted worker after double fork and setsid');
            $this->assertEquals(false, is_file($dir.'/supervisor.complete'),
                'Detached worker blocks normal completion');
            $request = proc_open(array(RTASK_KILL_HELPER, trim(file_get_contents($dir.'/pid')),
                $dir.'/pid.identity', '--supervisor-cancel', $binary),
                array(0=>array('file','/dev/null','r'),1=>array('file','/dev/null','w'),
                    2=>array('file','/dev/null','w')), $requestPipes);
            $this->assertEquals(0, proc_close($request), 'Cancellation reached detached tree');
            $completed = $this->waitFor($dir.'/supervisor.complete');
            $this->assertEquals(true, $completed, 'Detached worker is reaped before completion');
            if (!$completed) return;
            $this->assertEquals(0, proc_close($process), 'Supervisor exited after proof');
            $process = null;
            $this->assertEquals(false, is_dir('/proc/'.$orphan), 'Detached worker is gone');
        } finally {
            if (is_resource($process)) {
                $info = proc_get_status($process);
                if ($info['running'] && $info['pid'] > 1) proc_terminate($process, 9);
                proc_close($process);
            }
            if ($orphan !== null) $this->stopWitness($orphan, $identity, $dir);
            foreach (array('start.sh','pid','pid.identity','supervisor.version',
                'supervisor.complete','status','errors','orphan-pid') as $name)
                @unlink($dir.'/'.$name);
            @rmdir($dir);
        }
    }

    public function testLateChildAfterEnumerationIsReapedBeforeCompletion()
    {
        $binary = taskNativeSupervisorFixture(true);
        $dir = sys_get_temp_dir().'/task-supervisor-late-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $fifo = $dir.'/trigger';
        $ready = $dir.'/enumerated';
        $go = $dir.'/resume';
        $childMarker = $dir.'/late-pid';
        if (!posix_mkfifo($fifo, 0600)) throw new RuntimeException('Could not make trigger FIFO');
        $script = $dir.'/start.sh';
        file_put_contents($script, '#!/bin/sh'."\n".'read line < '.escapeshellarg($fifo)."\n"
            .'sleep 30 & printf "%s\n" "$!" > '.escapeshellarg($childMarker).'; wait'."\n");
        chmod($script, 0700);
        $environment = array_merge($_ENV, array('RTASK_SUPERVISOR_TEST_READY'=>$ready,
            'RTASK_SUPERVISOR_TEST_GO'=>$go));
        $process = proc_open(array($binary, $dir), array(0=>array('file','/dev/null','r'),
            1=>array('file','/dev/null','w'), 2=>array('file','/dev/null','w')),
            $pipes, null, $environment);
        if (!is_resource($process)) throw new RuntimeException('Could not launch late-fork supervisor');
        $latePid = null;
        try {
            $this->assertEquals(true, $this->waitFor($dir.'/pid'), 'Supervisor published its PID');
            $this->assertEquals(true, $this->waitFor($dir.'/pid.identity'), 'Supervisor started');
            $pid = trim(file_get_contents($dir.'/pid'));
            $childrenPath = '/proc/'.$pid.'/task/'.$pid.'/children';
            for ($attempt=0; $attempt<100 && trim((string)@file_get_contents($childrenPath))===''; ++$attempt)
                usleep(10000);
            $this->assertEquals(true, trim((string)@file_get_contents($childrenPath))!=='',
                'Paused task shell exists before cancellation');
            $request = proc_open(array(RTASK_KILL_HELPER, $pid, $dir.'/pid.identity',
                '--supervisor-cancel', $binary), array(0=>array('file','/dev/null','r'),
                1=>array('file','/dev/null','w'),2=>array('file','/dev/null','w')), $requestPipes);
            $this->assertEquals(0, proc_close($request), 'Cancellation request was delivered');
            $reached = $this->waitFor($ready);
            $this->assertEquals(true, $reached, 'Supervisor enumerated its children');
            if (!$reached) return;
            $children = trim((string)file_get_contents('/proc/'.$pid.'/task/'.$pid.'/children'));
            $direct = (int)strtok($children, " ");
            if ($direct <= 1) throw new RuntimeException('Could not identify direct child');
            $continue = proc_open(array('sh', '-c', 'kill -CONT "$1"', 'sh', (string)$direct),
                array(0=>array('file','/dev/null','r'),1=>array('file','/dev/null','w'),
                    2=>array('file','/dev/null','w')), $signalPipes);
            $this->assertEquals(0, proc_close($continue),
                'External SIGCONT can arrive while cancellation is paused');
            $writer = fopen($fifo, 'w');
            fwrite($writer, "go\n"); fclose($writer);
            $this->assertEquals(true, $this->waitFor($childMarker), 'Direct child forked after enumeration');
            $latePid = (int)trim(file_get_contents($childMarker));
            file_put_contents($go, "go\n");
            $completed = $this->waitFor($dir.'/supervisor.complete');
            $this->assertEquals(true, $completed, 'Supervisor waits for the late child too');
            if (!$completed) return;
            $this->assertEquals(0, proc_close($process), 'Supervisor exits with proof');
            $process = null;
            $this->assertEquals(false, is_dir('/proc/'.$latePid), 'Late child is reaped');
        } finally {
            @file_put_contents($go, "go\n");
            if (is_resource($process)) {
                $info = proc_get_status($process);
                if ($info['running'] && $info['pid'] > 1) proc_terminate($process, 9);
                proc_close($process);
            }
            if ($latePid !== null && is_dir('/proc/'.$latePid)) {
                $identity = $dir.'/late.identity';
                file_put_contents($identity, file_get_contents('/proc/sys/kernel/random/boot_id').
                    file_get_contents('/proc/'.$latePid.'/stat'));
                $cleanup = proc_open(array(RTASK_KILL_HELPER, (string)$latePid, $identity),
                    array(0=>array('file','/dev/null','r'),1=>array('file','/dev/null','w'),
                        2=>array('file','/dev/null','w')), $cleanupPipes);
                if (is_resource($cleanup)) proc_close($cleanup);
            }
            foreach (array('trigger','start.sh','pid','pid.identity','supervisor.version',
                'supervisor.complete','status','errors','enumerated','resume','late-pid','late.identity') as $file)
                @unlink($dir.'/'.$file);
            @rmdir($dir);
        }
    }

    public function testStartedTaskUsesSupervisorForThreeGenerationCancellation()
    {
        $profile = $_ENV['RU_PROFILE_PATH'];
        if (!is_dir($profile)) mkdir($profile, 0777, true);
        $marker = $profile.'/started-grandchild-pid';
        $inner = 'sleep 30 & printf "%s\n" "$!" > '.escapeshellarg($marker).'; wait';
        $task = new rTask(array('name'=>'supervised tree'));
        $task->start(array('sh -c '.escapeshellarg($inner)), rTask::FLG_RUN_AS_WEB);
        $dir = rTask::formatPath($task->id);
        $grandchild = null;
        $grandchildIdentity = null;
        try {
            $this->assertEquals(true, $this->waitFor($marker), 'Started task created a grandchild');
            $this->assertEquals(true, is_file($dir.'/supervisor.version'),
                'The task is explicitly marked as supervised');
            $this->assertEquals(-1, rTask::check($task->id)['status'],
                'Task remains active while its grandchild is alive');
            $grandchild = (int)trim(file_get_contents($marker));
            $grandchildIdentity = file_get_contents('/proc/sys/kernel/random/boot_id').
                file_get_contents('/proc/'.$grandchild.'/stat');
            $this->assertEquals(true, rTask::kill($task->id),
                'Cancellation is acknowledged after complete-tree proof');
            $this->assertEquals(false, is_dir($dir), 'Completed cancelled task record is removed');
            $this->assertEquals(false, is_dir('/proc/'.$grandchild), 'The grandchild is reaped');
        } finally {
            if ($grandchild !== null) $this->stopWitness($grandchild, $grandchildIdentity, $profile);
            if (is_dir($dir) && is_file($dir.'/pid') && is_file($dir.'/pid.identity')) {
                $pid = (int)trim(file_get_contents($dir.'/pid'));
                $this->stopWitness($pid, file_get_contents($dir.'/pid.identity'), $profile);
            }
            @unlink($marker);
        }
    }

    public function testUnavailableSupervisorRefusesStartWithVisibleReason()
    {
        $binary = RTASK_SUPERVISOR_HELPER;
        $mode = fileperms($binary) & 0777;
        chmod($binary, 0600);
        try {
            $task = new rTask(array('name'=>'unavailable supervisor'));
            $result = $task->start(array('true'), rTask::FLG_RUN_AS_WEB | rTask::FLG_WAIT);
            $dir = rTask::formatPath($task->id);
            $this->assertEquals(255, $result['status'], 'Task refuses to launch without supervisor');
            $this->assertEquals(true, strpos(implode(' ', $result['errors']), 'supervisor unavailable') !== false,
                'The launch response names the missing supervisor');
            $this->assertEquals(false, is_file($dir.'/pid'), 'No shell or task PID was started');
            $this->assertEquals(true, is_file($dir.'/errors'), 'The refusal is saved in the task log');
            $listed = rTask::check($task->id);
            $this->assertEquals(255, $listed['status'], 'Task list shows failed startup');
            $this->assertEquals(true, strpos(implode(' ', $listed['errors']), 'supervisor unavailable') !== false,
                'Task list carries the saved refusal');
        } finally {
            chmod($binary, $mode);
        }
    }

    public function testSupervisorDeathLeavesVisibleUnprovedTaskRecord()
    {
        $task = new rTask(array('name'=>'supervisor death'));
        $task->start(array('exec sleep 30'), rTask::FLG_RUN_AS_WEB);
        $dir = rTask::formatPath($task->id);
        $this->assertEquals(true, is_file($dir.'/pid.identity'), 'Supervisor identity was published');
        $pid = (int)trim(file_get_contents($dir.'/pid'));
        try {
            $stop = proc_open(array(RTASK_KILL_HELPER, (string)$pid, $dir.'/pid.identity'),
                array(0=>array('file','/dev/null','r'),1=>array('file','/dev/null','w'),
                    2=>array('file','/dev/null','w')), $pipes);
            if (!is_resource($stop)) throw new RuntimeException('Could not stop isolated supervisor');
            proc_close($stop);
            for ($i=0; $i<100 && is_file('/proc/'.$pid.'/stat'); ++$i) usleep(10000);
            $result = rTask::check($task->id);
            $this->assertEquals(-2, $result['status'],
                'A dead supervisor without completion is reported as uncertain');
            $this->assertEquals(true, strpos(implode(' ', $result['errors']), 'supervisor exited') !== false,
                'The uncertain state names the missing proof');
            $this->assertEquals(false, rTask::kill($task->id),
                'An unproved tree cannot report cancellation success');
            $this->assertEquals(true, is_dir($dir), 'Task record remains for diagnosis');
        } finally {
            if (is_file('/proc/'.$pid.'/stat')) {
                $stop = proc_open(array(RTASK_KILL_HELPER, (string)$pid, $dir.'/pid.identity'),
                    array(0=>array('file','/dev/null','r'),1=>array('file','/dev/null','w'),
                        2=>array('file','/dev/null','w')), $pipes);
                if (is_resource($stop)) proc_close($stop);
            }
        }
    }

    public function testCompletedTaskWaitsForItsNormalNotificationBeforeCleanup()
    {
        $id = uniqid(time(), true);
        $dir = rTask::formatPath($id);
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/pid', '999999');
        file_put_contents($dir.'/flags', (string)rTask::FLG_RUN_AS_WEB);
        file_put_contents($dir.'/status', "0\n");
        file_put_contents($dir.'/supervisor.version', "1\n");
        file_put_contents($dir.'/supervisor.complete', "1\n");
        file_put_contents($dir.'/supervisor.outcome', "normal\n");
        file_put_contents($dir.'/supervisor.notify-pending', "1\n");
        $this->assertEquals(false, rTask::kill($id),
            'Finished record remains until its detached notification completes');
        $this->assertEquals(true, is_dir($dir), 'Notification still has access to task parameters');
        $this->assertEquals(true, strpos(implode(' ', rTask::check($id)['errors']),
            'notification is still pending') !== false, 'Pending notification is visible');
        unlink($dir.'/supervisor.notify-pending');
        $this->assertEquals(true, rTask::kill($id), 'Finished record can be cleaned after notification');
        $this->assertEquals(false, is_dir($dir), 'Task record is then removed');
    }

    public function testMatchingPidBirthOfWrongExecutableIsNotSupervisorProof()
    {
        $process = proc_open(array('sleep','30'), array(0=>array('file','/dev/null','r'),
            1=>array('file','/dev/null','w'),2=>array('file','/dev/null','w')), $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start wrong-executable witness');
        try {
            $pid = proc_get_status($process)['pid'];
            if ($pid <= 1 || $pid === getmypid()) throw new RuntimeException('Unsafe witness PID');
            $id = uniqid(time(), true);
            $dir = rTask::formatPath($id);
            mkdir($dir, 0777, true);
            file_put_contents($dir.'/pid', (string)$pid);
            file_put_contents($dir.'/flags', (string)rTask::FLG_RUN_AS_WEB);
            file_put_contents($dir.'/pid.identity', file_get_contents('/proc/sys/kernel/random/boot_id').
                file_get_contents('/proc/'.$pid.'/stat'));
            file_put_contents($dir.'/supervisor.version', "1\n");
            $this->assertEquals(-2, rTask::check($id)['status'],
                'A reused PID running another executable is uncertain');
            $this->assertEquals(false, rTask::kill($id),
                'Wrong executable cannot receive supervisor cancellation signal');
            $this->assertEquals(true, proc_get_status($process)['running'],
                'Unrelated process remains alive');
        } finally {
            if (proc_get_status($process)['running']) proc_terminate($process);
            proc_close($process);
        }
    }

    public function testCorruptCompletionMarkerCannotAuthorizeCleanup()
    {
        $id = uniqid(time(), true);
        $dir = rTask::formatPath($id);
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/pid', '999999');
        file_put_contents($dir.'/flags', (string)rTask::FLG_RUN_AS_WEB);
        file_put_contents($dir.'/status', "0\n");
        file_put_contents($dir.'/supervisor.version', "1\n");
        file_put_contents($dir.'/supervisor.complete', "corrupt\n");
        $this->assertEquals(false, rTask::kill($id),
            'A corrupt completion marker cannot authorize cleanup');
        $this->assertEquals(true, is_dir($dir), 'Corrupt completion keeps task evidence');
        $this->assertEquals(true, strpos(implode(' ', rTask::check($id)['errors']),
            'supervisor.complete') !== false, 'The corrupt key is named to the user');
    }

    public function testLegacyTaskCannotClaimCompleteTreeCancellation()
    {
        $process = proc_open(array('sleep', '30'), array(0=>array('file','/dev/null','r'),
            1=>array('file','/dev/null','w'), 2=>array('file','/dev/null','w')), $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start witness');
        try {
            $pid = proc_get_status($process)['pid'];
            if ($pid <= 1 || $pid === getmypid()) throw new RuntimeException('Unsafe witness PID');
            $id = uniqid(time(), true);
            $dir = rTask::formatPath($id);
            mkdir($dir, 0777, true);
            file_put_contents($dir.'/pid', (string)$pid);
            file_put_contents($dir.'/flags', (string)rTask::FLG_RUN_AS_WEB);
            file_put_contents($dir.'/pid.identity', file_get_contents('/proc/sys/kernel/random/boot_id').
                file_get_contents('/proc/'.$pid.'/stat'));
            $this->assertEquals(false, rTask::kill($id), 'Unsupervised task cannot claim tree completion');
            $this->assertEquals(true, is_dir($dir), 'Legacy record remains for diagnosis');
            $this->assertEquals(true, proc_get_status($process)['running'], 'Legacy refusal sends no signal');
            $this->assertEquals(true, strpos(file_get_contents($dir.'/errors'), 'legacy') !== false,
                'The refusal identifies the legacy task');
            $this->assertEquals(true, strpos(implode(' ', rTask::check($id)['errors']), 'legacy task') !== false,
                'Polling makes the unproved legacy task visible');
        } finally {
            if (proc_get_status($process)['running']) proc_terminate($process);
            proc_close($process);
        }
    }
    public function testCancelAtNormalCompletionKeepsNormalOutcomeAndNotification()
    {
        $binary = taskNativeSupervisorFixture(true);
        $dir = sys_get_temp_dir().'/task-supervisor-terminal-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        file_put_contents($dir.'/start.sh', "#!/bin/sh\ntrue\n");
        $notify = $dir.'/notify.php';
        file_put_contents($notify, '<?php file_put_contents($argv[2]."/notified", "yes\n"); ' .
            'unlink($argv[2]."/supervisor.notify-pending");');
        $ready = $dir.'/commit-ready';
        $go = $dir.'/commit-go';
        $env = array_merge($_ENV, array('RTASK_SUPERVISOR_TEST_COMMIT_READY'=>$ready,
            'RTASK_SUPERVISOR_TEST_COMMIT_GO'=>$go));
        $process = proc_open(array($binary, $dir, PHP_BINARY, $notify, 'test'),
            array(0=>array('file','/dev/null','r'),1=>array('file','/dev/null','w'),
                2=>array('file','/dev/null','w')), $pipes, null, $env);
        if (!is_resource($process)) throw new RuntimeException('Could not start terminal-race witness');
        try {
            $this->assertEquals(true, $this->waitFor($ready), 'Normal completion reserved notification before commit');
            if (!is_file($ready)) return;
            $request = proc_open(array(RTASK_KILL_HELPER, trim(file_get_contents($dir.'/pid')),
                $dir.'/pid.identity', '--supervisor-cancel', $binary),
                array(0=>array('file','/dev/null','r'),1=>array('file','/dev/null','w'),
                    2=>array('file','/dev/null','w')), $requestPipes);
            $this->assertEquals(0, proc_close($request), 'Cancellation request arrived at terminal boundary');
            file_put_contents($go, "go\n");
            $this->assertEquals(true, $this->waitFor($dir.'/supervisor.complete'), 'Completion is committed');
            $this->assertEquals("normal\n", @file_get_contents($dir.'/supervisor.outcome'),
                'Normal outcome cannot become a false TaskKill');
            $this->assertEquals(true, $this->waitFor($dir.'/notified'),
                'The reserved normal notification runs');
            $this->assertEquals(0, proc_close($process), 'Supervisor exits cleanly');
            $process = null;
        } finally {
            @file_put_contents($go, "go\n");
            if (is_resource($process)) {
                $info = proc_get_status($process);
                if ($info['running'] && $info['pid'] > 1) proc_terminate($process, 9);
                proc_close($process);
            }
            foreach (array('start.sh','notify.php','commit-ready','commit-go','pid','pid.identity',
                'supervisor.version','supervisor.notify-pending','supervisor.outcome',
                'supervisor.complete','status','notified','errors') as $file) @unlink($dir.'/'.$file);
            @rmdir($dir);
        }
    }

    public function testFailedCompletionDirectorySyncDoesNotLeaveValidProof()
    {
        $binary = taskNativeSupervisorFixture(true);
        $dir = sys_get_temp_dir().'/task-supervisor-sync-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        file_put_contents($dir.'/start.sh', "#!/bin/sh\ntrue\n");
        $env = array_merge($_ENV, array('RTASK_SUPERVISOR_TEST_FAIL_COMPLETE_FSYNC'=>'1'));
        $process = proc_open(array($binary, $dir), array(0=>array('file','/dev/null','r'),
            1=>array('file','/dev/null','w'),2=>array('file','/dev/null','w')), $pipes, null, $env);
        if (!is_resource($process)) throw new RuntimeException('Could not start fsync witness');
        try {
            $this->assertEquals(4, proc_close($process), 'Directory sync failure withholds completion');
            $process = null;
            $this->assertEquals(false, is_file($dir.'/supervisor.complete'),
                'Failed durable write cannot leave an authorizing marker');
            $this->assertEquals(true, strpos((string)@file_get_contents($dir.'/errors'),
                'task retained') !== false, 'The refusal is visible');
        } finally {
            if (is_resource($process)) proc_close($process);
            foreach (array('start.sh','pid','pid.identity','supervisor.version',
                'supervisor.outcome','supervisor.complete','status','errors') as $file) @unlink($dir.'/'.$file);
            @rmdir($dir);
        }
    }

    public function testNotifierExecFailureIsVisibleAndRetainsPendingWork()
    {
        $binary = taskNativeSupervisorFixture();
        $dir = sys_get_temp_dir().'/task-supervisor-notify-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        file_put_contents($dir.'/start.sh', "#!/bin/sh\ntrue\n");
        $process = proc_open(array($binary, $dir, '/no/such/php', '/no/such/notify.php', 'test'),
            array(0=>array('file','/dev/null','r'),1=>array('file','/dev/null','w'),
                2=>array('file','/dev/null','w')), $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start notifier failure witness');
        try {
            $this->assertEquals(0, proc_close($process), 'Task completion is independent of notifier startup');
            $process = null;
            $this->assertEquals(true, $this->waitFor($dir.'/supervisor.notify-failed'),
                'Notifier exec failure creates a classified failure marker');
            $this->assertEquals("exec-failed\n", @file_get_contents($dir.'/supervisor.notify-failed'),
                'Notifier failure reason is explicit');
            $this->assertEquals(true, is_file($dir.'/supervisor.notify-pending'),
                'Unnotified task stays retained');
            $this->assertEquals(true, strpos((string)@file_get_contents($dir.'/errors'),
                'exec failed') !== false, 'Notifier failure is logged');
        } finally {
            if (is_resource($process)) proc_close($process);
            foreach (array('start.sh','pid','pid.identity','supervisor.version',
                'supervisor.notify-pending','supervisor.notify-failed','supervisor.outcome',
                'supervisor.complete','status','errors') as $file) @unlink($dir.'/'.$file);
            @rmdir($dir);
        }
    }

    public function testNotifierPhpFatalIsVisibleAndRetainsPendingWork()
    {
        $binary = taskNativeSupervisorFixture();
        $dir = sys_get_temp_dir().'/task-supervisor-fatal-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        file_put_contents($dir.'/start.sh', "#!/bin/sh\ntrue\n");
        $notify = $dir.'/fatal.php';
        file_put_contents($notify, '<?php undefined_rtask_notifier_function();');
        $process = proc_open(array($binary, $dir, PHP_BINARY, $notify, 'test'),
            array(0=>array('file','/dev/null','r'),1=>array('file','/dev/null','w'),
                2=>array('file','/dev/null','w')), $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start PHP-fatal witness');
        try {
            $this->assertEquals(0, proc_close($process), 'Task completion is independent of notifier exit');
            $process = null;
            $this->assertEquals(true, $this->waitFor($dir.'/supervisor.notify-failed'),
                'Nonzero notifier exit creates a failure marker');
            $this->assertEquals("exit-failed\n", @file_get_contents($dir.'/supervisor.notify-failed'),
                'The exit classification is explicit');
            $this->assertEquals(true, is_file($dir.'/supervisor.notify-pending'),
                'Failed delivery retains pending work');
            $this->assertEquals(true, strpos((string)@file_get_contents($dir.'/errors'),
                'Fatal error') !== false, 'The underlying PHP fatal reaches the task log');
        } finally {
            if (is_resource($process)) proc_close($process);
            foreach (array('start.sh','fatal.php','pid','pid.identity','supervisor.version',
                'supervisor.notify-pending','supervisor.notify-failed','supervisor.outcome',
                'supervisor.complete','status','errors') as $file) @unlink($dir.'/'.$file);
            @rmdir($dir);
        }
    }

    public function testNotifierRejectsMissingTaskParametersAndRetainsPendingWork()
    {
        $dir = sys_get_temp_dir().'/task-notify-params-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        file_put_contents($dir.'/supervisor.notify-pending', "1\n");
        $process = proc_open(array(PHP_BINARY, __DIR__.'/../../../plugins/_task/notify.php',
            '0', $dir, 'test'), array(0=>array('file','/dev/null','r'),
                1=>array('file','/dev/null','w'),2=>array('file','/dev/null','w')), $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start real notifier');
        try {
            $this->assertEquals(1, proc_close($process), 'Invalid task parameters refuse notification');
            $process = null;
            $this->assertEquals("event-failed\n", @file_get_contents($dir.'/supervisor.notify-failed'),
                'The failure classification is persisted');
            $this->assertEquals(true, is_file($dir.'/supervisor.notify-pending'),
                'Invalid parameters keep notification pending');
            $this->assertEquals(true, strpos((string)@file_get_contents($dir.'/errors'),
                'invalid task parameters') !== false, 'The refusal names the bad task document');
        } finally {
            if (is_resource($process)) proc_close($process);
            foreach (array('supervisor.notify-pending','supervisor.notify-failed','errors') as $file)
                @unlink($dir.'/'.$file);
            @rmdir($dir);
        }
    }
}
