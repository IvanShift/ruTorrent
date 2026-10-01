<?php

// Run inside the shipped image as the torrent user after the task kill and supervisor helpers are installed.
$_ENV['RU_PROFILE_PATH'] = sys_get_temp_dir().'/rutorrent-task-image-'.getmypid().'-'.bin2hex(random_bytes(4));
require_once(__DIR__.'/../../../plugins/_task/task.php');

if (!is_executable(RTASK_KILL_HELPER) || !is_executable(RTASK_SUPERVISOR_HELPER)) {
    throw new RuntimeException('The shipped image lacks a native task helper');
}
// A controlled child must map to the file we executed. QEMU user-mode or an
// explicit loader changes /proc/exe; process-identity guarantees stay failclosed.
$control = proc_open(array(PHP_BINARY, '-r', 'fwrite(STDOUT, "ready\n"); sleep(10);'),
    array(0=>array('file','/dev/null','r'), 1=>array('pipe','w'), 2=>array('file','/dev/null','w')), $controlPipes);
if (!is_resource($control)) throw new RuntimeException('Could not start the native-execution control');
$emulatedExecutable = null;
try {
    stream_set_timeout($controlPipes[1], 2);
    if (fgets($controlPipes[1]) !== "ready\n") throw new RuntimeException('Native-execution control did not become ready');
    $controlState = proc_get_status($control);
    $actualPath = '/proc/'.$controlState['pid'].'/exe';
    $actual = @stat($actualPath);
    $expected = @stat(PHP_BINARY);
    if (!is_array($actual) || !is_array($expected)) throw new RuntimeException('Native-execution identity is unreadable');
    if ($actual['dev'] !== $expected['dev'] || $actual['ino'] !== $expected['ino'])
        $emulatedExecutable = @readlink($actualPath) ?: '<unreadable>';
} finally {
    fclose($controlPipes[1]);
    proc_terminate($control);
    proc_close($control);
}
if ($emulatedExecutable !== null) {
    fwrite(STDOUT, 'skip - native pidfd cancellation requires native process identity; /proc/exe='.$emulatedExecutable."\n");
    exit(77);
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
    if ($grandchild<=1 || $result['status'] !== -1 || !is_file($dir.'/supervisor.version')
        || !is_file($dir.'/pid.identity')) {
        throw new RuntimeException('The image did not start a supervised process tree: '.json_encode($result['errors']));
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
