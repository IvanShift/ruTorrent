<?php

function taskNativeHelperFixture($testHook = false)
{
    $source = __DIR__.'/../../../plugins/_task/kill-verified.c';
    $directory = sys_get_temp_dir().'/rutorrent-task-pidfd-'.getmypid().'-'.bin2hex(random_bytes(4));
    if (!mkdir($directory, 0700) || !is_file($source)) {
        throw new RuntimeException('Could not prepare the native task killer fixture');
    }
    $binary = $directory.'/kill-verified';
    $command = array('cc', '-std=c11', '-O2', '-Wall', '-Wextra', '-Werror');
    if ($testHook) $command[] = '-DRTASK_KILL_TEST_HOOK=1';
    array_push($command, '-o', $binary, $source);
    $process = proc_open($command, array(
        0 => array('file', '/dev/null', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    ), $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start C compiler for the task killer');
    }
    fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Could not compile the native task killer: '.$errors);
    }
    register_shutdown_function(function() use ($binary, $directory) {
        @unlink($binary);
        @rmdir($directory);
    });
    return $binary;
}
