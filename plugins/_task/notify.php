<?php

$dir = $argv[2] ?? null;
if(!is_string($dir) || !is_dir($dir))
    exit(2);
ini_set('error_log', $dir.'/errors');
register_shutdown_function(function() use ($dir) {
    $error = error_get_last();
    if($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR,
        E_COMPILE_ERROR, E_USER_ERROR), true) && is_file($dir.'/supervisor.notify-pending'))
    {
        @file_put_contents($dir.'/supervisor.notify-failed', "php-fatal\n");
        error_log('rtask: notifier: PHP fatal; pending work retained');
    }
});

try
{
    $path = dirname(realpath($argv[0]));
    if(!chdir($path) || count($argv)<=3)
        throw new RuntimeException('invalid invocation');
    $_SERVER['REMOTE_USER'] = $argv[3];
    require_once('task.php');
    if(!rTask::notify($dir, $argv[1] ? 'TaskFail' : 'TaskSuccess'))
        throw new RuntimeException('invalid task parameters');
    if(!@unlink($dir.'/supervisor.notify-pending'))
        throw new RuntimeException('pending marker removal failed');
}
catch(Throwable $error)
{
    @file_put_contents($dir.'/supervisor.notify-failed', "event-failed\n");
    $reason = $error->getMessage();
    if(!in_array($reason, array('invalid invocation', 'invalid task parameters',
        'pending marker removal failed'), true))
        $reason = 'event handler failed';
    error_log('rtask: notifier: '.$reason.'; pending work retained');
    exit(1);
}
