<?php

// Run inside the shipped image as the torrent user after its native helper is installed.
$_ENV['RU_PROFILE_PATH'] = sys_get_temp_dir().'/rutorrent-task-image-'.getmypid().'-'.bin2hex(random_bytes(4));
require_once(__DIR__.'/../../../plugins/_task/task.php');

if (!is_executable(RTASK_KILL_HELPER)) {
    throw new RuntimeException('The shipped image lacks the native task killer');
}
$process = proc_open(array('sleep', '30'), array(
    0 => array('file', '/dev/null', 'r'),
    1 => array('file', '/dev/null', 'w'),
    2 => array('file', '/dev/null', 'w'),
), $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Could not create the isolated image smoke witness');
}
$profile = $_ENV['RU_PROFILE_PATH'];
try {
    $pid = proc_get_status($process)['pid'];
    if ($pid <= 1 || $pid === getmypid()) {
        throw new RuntimeException('Unsafe image smoke witness PID');
    }
    $id = uniqid(time(), true);
    $dir = rTask::formatPath($id);
    if (!mkdir($dir, 0700, true)) {
        throw new RuntimeException('Could not create the isolated task record');
    }
    file_put_contents($dir.'/pid', (string)$pid);
    file_put_contents($dir.'/pid.identity',
        file_get_contents('/proc/sys/kernel/random/boot_id').
        file_get_contents('/proc/'.$pid.'/stat'));
    file_put_contents($dir.'/flags', (string)rTask::FLG_RUN_AS_WEB);
    if (!rTask::kill($id)) {
        throw new RuntimeException('The image native helper did not kill its witness');
    }
    for ($attempt = 0; $attempt < 20 && proc_get_status($process)['running']; ++$attempt) {
        usleep(50000);
    }
    if (proc_get_status($process)['running'] || is_dir($dir)) {
        throw new RuntimeException('The image task process or record remained after kill');
    }
    echo "ok - shipped image pidfd task killer\n";
} finally {
    if (proc_get_status($process)['running']) proc_terminate($process);
    proc_close($process);
    if (is_dir($profile)) FileUtil::deleteDirectory($profile);
}
