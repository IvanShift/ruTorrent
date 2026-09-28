<?php

$redactionTestRoot = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/loginmgr-redaction-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($redactionTestRoot . '/settings/accounts', 0700, true);
$_ENV['RU_PROFILE_PATH'] = $redactionTestRoot;
$_ENV['RU_LOG_FILE'] = $redactionTestRoot . '/app.log';

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../../plugins/loginmgr/accounts.php');

register_shutdown_function(function () use ($redactionTestRoot) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($redactionTestRoot,
        FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($redactionTestRoot);
});

class RedactionAccountManager extends accountManager
{
    public $storeResult = true;
    public $handlerCalls = 0;
    public function store() { return $this->storeResult; }
    public function setHandlers() { $this->handlerCalls++; }
}

function redactionManager($password = 'saved-private-password')
{
    $manager = new RedactionAccountManager();
    $manager->accounts = array('RUTracker' => array(
        'name' => 'RUTracker',
        'path' => __DIR__ . '/../../../plugins/loginmgr/accounts/RUTracker.php',
        'object' => 'ruTrackerAccount',
        'login' => 'saved-user',
        'password' => $password,
        'enabled' => 1,
        'auto' => 0,
    ));
    return $manager;
}

$tests = array(
    'failed action save returns an error without exposing credentials' => function () use ($redactionTestRoot) {
        $root = $redactionTestRoot . '/endpoint';
        mkdir($root . '/settings/accounts', 0700, true);
        $action = __DIR__ . '/../../../plugins/loginmgr/action.php';
        $script = <<<'PHP'
$root = $argv[1];
$action = $argv[2];
$_ENV['RU_PROFILE_PATH'] = $root;
$_ENV['RU_LOG_FILE'] = $root . '/app.log';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'localhost';
require_once dirname($action) . '/accounts.php';
$manager = new accountManager();
$manager->accounts = array('RUTracker' => array(
    'name' => 'RUTracker', 'path' => dirname($action) . '/accounts/RUTracker.php',
    'object' => 'ruTrackerAccount', 'login' => 'saved-user',
    'password' => 'saved-private-password', 'enabled' => 1, 'auto' => 0,
));
if (!$manager->store()) exit(91);
$lock = FileUtil::getSettingsPath() . '/loginmgr.dat.lock';
if (is_file($lock)) unlink($lock);
if (!mkdir($lock)) exit(92);
$_POST = array('RUTracker_password' => 'unsaved-new-password');
$_REQUEST = array('mode' => 'set');
http_response_code(200);
register_shutdown_function(function () use ($root) {
    file_put_contents($root . '/status', (string) http_response_code());
});
require $action;
PHP;
        $command = array(PHP_BINARY, '-c', __DIR__ . '/../../php-test.ini', '-r', $script, $root, $action);
        $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'),
            2 => array('pipe', 'w')), $pipes, dirname($action));
        testAssertTrue(is_resource($process), 'action child started');
        fclose($pipes[0]);
        $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $diagnostics = stream_get_contents($pipes[2]); fclose($pipes[2]);
        testAssertSame(0, proc_close($process), 'action child completed');
        testAssertSame('500', file_get_contents($root . '/status'), 'failed save returns HTTP 500');
        testAssertSame(false, strpos($body, 'saved-private-password') !== false, 'response hides saved password');
        testAssertSame(false, strpos($body, 'unsaved-new-password') !== false, 'response hides submitted password');
        $log = file_get_contents($root . '/app.log');
        testAssertSame(true, strpos($log, 'loginmgr: account-save-failed') !== false, 'classified failure logged');
        testAssertSame(false, strpos($log . $diagnostics, 'unsaved-new-password') !== false, 'diagnostics hide submitted password');
        testAssertSame(false, strpos($log . $diagnostics, 'saved-private-password') !== false, 'diagnostics hide stored password');
    },
    'bootstrap and info never include the stored password' => function () {
        $manager = redactionManager();
        $script = $manager->get();
        $info = $manager->getInfo();
        testAssertSame(false, strpos($script, 'saved-private-password') !== false, 'bootstrap hides password');
        testAssertSame(false, array_key_exists('password', $info[0]), 'info has no password field');
        testAssertSame(1, $info[0]['password_set'], 'info reports only whether a password is stored');
        testAssertSame(false, strpos(JSON::safeEncode($info), 'saved-private-password') !== false, 'info JSON hides password');
        testAssertSame('saved-user', $info[0]['login'], 'info retains editable login');
        testAssertSame(true, strpos($script, 'saved-user') !== false, 'bootstrap retains editable login');
    },
    'blank or missing password leaves an existing password intact' => function () {
        $manager = redactionManager();
        $_POST = array('RUTracker_login' => 'edited-user', 'RUTracker_password' => '');
        $manager->set();
        testAssertSame('saved-private-password', $manager->accounts['RUTracker']['password'], 'blank preserves password');
        testAssertSame('edited-user', $manager->accounts['RUTracker']['login'], 'login remains editable');
        $_POST = array('RUTracker_auto' => '86400');
        $manager->set();
        testAssertSame('saved-private-password', $manager->accounts['RUTracker']['password'], 'missing preserves password');
    },
    'failed persistence keeps the prior session and reports failure' => function () {
        $session = new privateData('RUTracker');
        $client = (object) array('cookies' => array('session-marker' => 'retained'), 'referer' => '');
        testAssertSame(true, $session->store($client), 'session fixture saved');
        $manager = redactionManager();
        $manager->storeResult = false;
        $_POST = array('RUTracker_password' => 'unsaved-new-password');
        testAssertSame(false, $manager->set(), 'failed account store is reported');
        testAssertSame(0, $manager->handlerCalls, 'failed store does not update handlers');
        $reloaded = (object) array('cookies' => array());
        testAssertSame(true, privateData::load('RUTracker', $reloaded)->loaded, 'prior session cache retained');
        testAssertSame('retained', $reloaded->cookies['session-marker'], 'prior session contents retained');
    },
    'new and explicit clear passwords update the stored value' => function () {
        $manager = redactionManager('');
        $_POST = array('RUTracker_password' => '********');
        $manager->set();
        testAssertSame('********', $manager->accounts['RUTracker']['password'], 'literal mask-looking password saves from empty account');
        testAssertSame(false, strpos($manager->get(), '********') !== false, 'set response also hides new password');
        $_POST = array('RUTracker_password' => '');
        $manager->set();
        testAssertSame('********', $manager->accounts['RUTracker']['password'], 'blank preserves literal mask-looking password');
        $_POST = array('RUTracker_clear_password' => '1');
        $manager->set();
        testAssertSame('', $manager->accounts['RUTracker']['password'], 'explicit clear removes password');
    },
);

exit(testRunCases($tests));
