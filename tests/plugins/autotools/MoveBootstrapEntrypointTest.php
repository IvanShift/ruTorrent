<?php

if (isset($argv[1]) && $argv[1] === '--getplugins-child') {
    $root = $argv[2];
    $user = $argv[3];
    $scratch = $argv[4];
    $_ENV['RU_PROFILE_PATH'] = $scratch . '/profile';
    $_ENV['RU_SCGI_HOST'] = 'unix://' . $scratch . '/absent.sock';
    $_ENV['RU_SCGI_PORT'] = '0';
    $_ENV['RU_LOG_FILE'] = $scratch . '/errors.log';
    $_SERVER['REMOTE_USER'] = $user;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_SCHEME'] = 'http';

    chdir($root . '/php');
    require_once('settings.php');
    // Reach the same enabled-plugin branch as a live daemon without contacting one.
    $reflection = new ReflectionClass('rTorrentSettings');
    $settings = $reflection->newInstanceWithoutConstructor();
    $settings->linkExist = true;
    $settings->version = '0.9.8';
    $settings->iVersion = 0x908;
    $settings->libVersion = '0.13.8';
    $settings->directory = $scratch . '/downloads';
    $settings->session = $scratch . '/session';
    $instance = $reflection->getProperty('theSettings');
    $instance->setAccessible(true);
    $instance->setValue(null, $settings);

    require_once('../plugins/autotools/autotools.php');
    $at = new rAutoTools();
    $at->enable_move = 1;
    $at->fileop_type = 'Move';
    $at->path_to_finished = $scratch . '/finished';
    if (!$at->store()) throw new RuntimeException('AutoTools fixture setting was not stored');
    require('getplugins.php');
    exit(0);
}

require_once dirname(__DIR__, 2) . '/plugins/rutracker_check/TestLib.php';

function removeMoveBootstrapFixture($path)
{
    if (!is_dir($path) || is_link($path)) return @unlink($path);
    foreach (scandir($path) as $name)
        if ($name !== '.' && $name !== '..') removeMoveBootstrapFixture($path . '/' . $name);
    return rmdir($path);
}

$suite = new StrictTestSuite();
$suite->test('getplugins boots configured AutoTools Move from php working directory', function () {
    $root = realpath(dirname(__DIR__, 3));
    $scratch = (getenv('TMPDIR') ?: sys_get_temp_dir())
        . '/autotools-getplugins-' . bin2hex(random_bytes(7));
    $user = 'autotoolsbootstrap' . bin2hex(random_bytes(6));
    $userDir = $root . '/conf/users/' . $user;
    if (!mkdir($scratch, 0700) || !mkdir($userDir, 0700))
        throw new RuntimeException('Cannot create private getplugins fixture');
    try {
        file_put_contents($userDir . '/plugins.ini',
            "[default]\nenabled = no\n[autotools]\nenabled = yes\n");
        $command = array(PHP_BINARY, '-d', 'variables_order=EGPCS', __FILE__,
            '--getplugins-child', $root, $user, $scratch);
        $process = proc_open($command, array(
            0 => array('file', '/dev/null', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        ), $pipes, $root . '/php');
        if (!is_resource($process)) throw new RuntimeException('Cannot start getplugins child');
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $status = proc_close($process);
        strictAssertSame(0, $status, 'configured getplugins bootstrap must not fatal: ' . $error);
        strictAssertSame(true, strpos($output, "rPlugin('autotools'") !== false,
            'the actual getplugins loader reached AutoTools init.php');
        strictAssertSame(true, strpos($output, 'AutoTools Move paused:') !== false,
            'getplugins explains an unconfirmed claim ABI even when handlers cannot register');
    } finally {
        removeMoveBootstrapFixture($userDir);
        removeMoveBootstrapFixture($scratch);
    }
});
exit($suite->run());
