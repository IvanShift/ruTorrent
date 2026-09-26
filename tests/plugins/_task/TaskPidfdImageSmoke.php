<?php

// Run inside the shipped image as the torrent user after the task kill and supervisor helpers are installed.
$_ENV['RU_PROFILE_PATH'] = sys_get_temp_dir().'/rutorrent-task-image-'.getmypid().'-'.bin2hex(random_bytes(4));
require_once(__DIR__.'/../../../plugins/_task/task.php');

if (!is_executable(RTASK_KILL_HELPER) || !is_executable(RTASK_SUPERVISOR_HELPER)) {
    throw new RuntimeException('The shipped image lacks a native task helper');
}
$profile = $_ENV['RU_PROFILE_PATH'];
$marker = $profile.'/grandchild-pid';
$inner = 'sleep 30 & printf "%s\n" "$!" > '.escapeshellarg($marker).'; wait';
$middle = 'sh -c '.escapeshellarg($inner).' & wait';
$task = new rTask(array('name'=>'image supervisor smoke'));
$result = $task->start(array('setsid sh -c '.escapeshellarg($middle).' & wait'), rTask::FLG_RUN_AS_WEB);
$dir = rTask::formatPath($task->id);
$grandchild = 0;
try {
    for ($attempt=0; $attempt<200 && !is_file($marker); ++$attempt) usleep(10000);
    if (!is_file($marker)) throw new RuntimeException('The image did not start the detached grandchild');
    $grandchild = (int)trim(file_get_contents($marker));
    if ($grandchild<=1 || $result['status'] >= 0 || !is_file($dir.'/supervisor.version')
        || !is_file($dir.'/pid.identity')) {
        throw new RuntimeException('The image did not start a supervised process tree');
    }
    if (!rTask::kill($task->id) || is_dir($dir) || is_dir('/proc/'.$grandchild)) {
        throw new RuntimeException('The image did not prove detached-grandchild cancellation');
    }
    echo "ok - shipped image supervised setsid grandchild cancellation\n";
} finally {
    if (is_file($dir.'/pid') && is_file($dir.'/pid.identity')) {
        $pid = trim(file_get_contents($dir.'/pid'));
        if (ctype_digit($pid) && (int)$pid > 1) {
            $cleanup = proc_open(array(RTASK_KILL_HELPER, $pid, $dir.'/pid.identity'), array(
                0=>array('file','/dev/null','r'), 1=>array('file','/dev/null','w'),
                2=>array('file','/dev/null','w')), $pipes);
            if (is_resource($cleanup)) proc_close($cleanup);
        }
    }
    if (is_dir($profile)) FileUtil::deleteDirectory($profile);
}
