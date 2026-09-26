<?php

function taskNativeSupervisorFixture($testHook = false)
{
    static $binaries = array();
    $key = $testHook ? 'hook' : 'normal';
    if (isset($binaries[$key])) return $binaries[$key];
    $source = __DIR__.'/../../../plugins/_task/supervise.c';
    $directory = sys_get_temp_dir().'/rutorrent-task-supervisor-'.getmypid().'-'.bin2hex(random_bytes(4));
    if (!mkdir($directory, 0700) || !is_file($source)) {
        throw new RuntimeException('Could not prepare native supervisor fixture');
    }
    $binary = $directory.'/supervise';
    $command = array('cc', '-std=c11', '-O2', '-Wall', '-Wextra', '-Werror');
    if ($testHook) $command[] = '-DRTASK_SUPERVISOR_TEST_HOOK=1';
    array_push($command, '-o', $binary, $source);
    $process = proc_open($command, array(0=>array('file','/dev/null','r'),
            1=>array('file','/dev/null','w'), 2=>array('pipe','w')), $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not compile supervisor');
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Supervisor compile: '.$error);
    register_shutdown_function(function() use ($binary, $directory) {
        @unlink($binary); @rmdir($directory);
    });
    $binaries[$key] = $binary;
    return $binary;
}
