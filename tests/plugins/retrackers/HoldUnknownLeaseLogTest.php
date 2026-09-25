<?php

// The production park is intentionally indefinite: an unknown daemon receipt
// must retain the lease. A direct PHP child proves the log appears once while
// that child is still parked, then the test terminates only its own process.
$root = sys_get_temp_dir() . '/rutorrent-retrackers-park-' . getmypid();
if (!@mkdir($root, 0700)) {
    throw new RuntimeException('Unable to create private park test directory');
}
$script = $root . '/park.php';
$log = $root . '/app.log';
$stdout = $root . '/stdout';
$stderr = $root . '/stderr';
$update = realpath(__DIR__ . '/../../../plugins/retrackers/update.php');
$source = '<?php' . "\n"
    . 'define("RETRACKERS_IMPORT_ONLY", true);' . "\n"
    . 'class FileUtil { public static function toLog($line) {' . "\n"
    . '    file_put_contents($GLOBALS["park_log"], $line . "\\n", FILE_APPEND);' . "\n"
    . '} }' . "\n"
    . '$GLOBALS["park_log"] = $argv[1];' . "\n"
    . 'require ' . var_export($update, true) . ';' . "\n"
    . '$method = new ReflectionMethod("RetrackersRecoveryCoordinator", "holdUnknownLease");' . "\n"
    . '$method->setAccessible(true);' . "\n"
    . '$method->invoke(null);' . "\n";
file_put_contents($script, $source);
$descriptors = array(
    0 => array('file', '/dev/null', 'r'),
    1 => array('file', $stdout, 'w'),
    2 => array('file', $stderr, 'w'),
);
$process = @proc_open(array(PHP_BINARY, $script, $log), $descriptors, $pipes);
$failure = null;
try {
    if (!is_resource($process)) {
        throw new RuntimeException('Direct PHP park child could not start');
    }
    $expected = 'retrackers-recovery: unknown-lease-held; daemon restart required';
    $saw = false;
    for ($attempt = 0; $attempt < 20; $attempt++) {
        clearstatcache(true, $log);
        if (is_file($log) && strpos((string) file_get_contents($log), $expected) !== false) {
            $saw = true;
            break;
        }
        if (!proc_get_status($process)['running']) break;
        usleep(100000);
    }
    if (!$saw) {
        throw new RuntimeException('The parked worker wrote no visible lease outcome: '
            . (string) @file_get_contents($stderr));
    }
    usleep(600000);
    $content = (string) file_get_contents($log);
    if (substr_count($content, $expected) !== 1 ||
        count(array_filter(explode("\n", trim($content)))) !== 1) {
        throw new RuntimeException('The indefinite park repeated or polluted its classified log line');
    }
    if (!proc_get_status($process)['running']) {
        throw new RuntimeException('The worker exited instead of retaining the unknown lease');
    }
    echo "ok - unknown lease is visibly parked once and remains held\n";
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        usleep(50000);
        if (proc_get_status($process)['running']) proc_terminate($process, 9);
        proc_close($process);
    }
    foreach (array($script, $log, $stdout, $stderr) as $path) @unlink($path);
    @rmdir($root);
}
if ($failure !== null) {
    echo 'not ok - ' . $failure->getMessage() . "\n";
    echo "1 test, 1 failure\n";
    exit(1);
}
echo "1 test, 0 failures\n";
