<?php

require_once(__DIR__ . '/../../php/TestCase.php');

class InitLockoutTest extends TestCase
{
    private $fixture;

    public function setUp()
    {
        $this->fixture = sys_get_temp_dir() . '/rutorrent-rt-lockout-'
            . bin2hex(random_bytes(8));
        mkdir($this->fixture . '/php', 0700, true);
        mkdir($this->fixture . '/plugins/retrackers', 0700, true);
        $root = realpath(__DIR__ . '/../../..');
        foreach (array('init.php', 'done.php') as $file)
            copy($root . '/plugins/retrackers/' . $file,
                $this->fixture . '/plugins/retrackers/' . $file);
        copy($root . '/php/doneplugins.php', $this->fixture . '/php/doneplugins.php');
        file_put_contents($this->fixture . '/php/rtorrent.php', '<?php');
        file_put_contents($this->fixture . '/php/xmlrpc.php', '<?php');
        file_put_contents($this->fixture . '/plugins/retrackers/retrackers.php',
            '<?php class rRetrackers { public static function load() { return new self(); } public function get() { return ""; } }');
        file_put_contents($this->fixture . '/plugins/retrackers/update.php', <<<'STUB'
<?php
function retrackersRunLifecycleInit($user, $script, $php, &$js, &$failure)
{
    if (getenv('RT_LOCKOUT_INIT_OK') === '1') return true;
    $failure = 'receipt-ledger-corrupt';
    $js .= "noty('retrackers: receipt-ledger-corrupt','error');";
    return false;
}
function retrackersRunLifecycleDone($user, &$js, &$failure)
{
    $GLOBALS['doneCalls']++;
    $failure = 'hook-teardown-pending';
    $js .= "noty('retrackers: hook-teardown-pending','error');";
    return false;
}
STUB
        );
        file_put_contents($this->fixture . '/php/settings.php', <<<'STUB'
<?php
class rTorrentSettings
{
    private static $instance;
    private $plugins = array();
    public function __construct()
    {
        $saved = @json_decode(@file_get_contents(getenv('RT_LOCKOUT_STATE')), true);
        if(is_array($saved)) $this->plugins = $saved;
    }
    public static function get()
    {
        if(self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    public function registerPlugin($name, $perms) { $this->plugins[$name] = $perms; }
    public function unregisterPlugin($name) { unset($this->plugins[$name]); }
    public function getPluginData($name)
    {
        return array_key_exists($name, $this->plugins) ? $this->plugins[$name] : null;
    }
    public function isPluginRegistered($name) { return array_key_exists($name, $this->plugins); }
    public function store()
    {
        file_put_contents(getenv('RT_LOCKOUT_STATE'), json_encode($this->plugins));
    }
}
STUB
        );
        file_put_contents($this->fixture . '/php/run-init.php', <<<'RUNNER'
<?php
chdir(__DIR__);
require_once('settings.php');
class Utility { public static function getPHP() { return PHP_BINARY; } }
class User { public static function getUser() { return 'alice'; } }
$rootPath = dirname(__DIR__);
$theSettings = rTorrentSettings::get();
$plugin = array('name' => 'retrackers');
$pInfo = array('perms' => 0x0100);
$jResult = '';
require($rootPath . '/plugins/retrackers/init.php');
$theSettings->store();
echo json_encode(array(
    'registered_before' => $theSettings->isPluginRegistered('retrackers'),
    'init_output' => $jResult,
));
RUNNER
        );
        file_put_contents($this->fixture . '/php/run-done.php', <<<'RUNNER'
<?php
chdir(__DIR__);
require_once('settings.php');
class User { public static function getUser() { return 'alice'; } }
class rCache { public function get(&$data) {} public function set($data) {} }
class CachedEcho {
    public static function send($body, $type) { $GLOBALS['doneOutput'] = $body; }
}
$GLOBALS['doneCalls'] = 0;
$_REQUEST['cmd'] = 'done';
$HTTP_RAW_POST_DATA = 'plg=retrackers';
require('doneplugins.php');
echo json_encode(array(
    'registered_after' => rTorrentSettings::get()->isPluginRegistered('retrackers'),
    'done_calls' => $GLOBALS['doneCalls'],
    'done_output' => isset($GLOBALS['doneOutput']) ? $GLOBALS['doneOutput'] : null,
));
RUNNER
        );
    }

    public function tearDown()
    {
        if (!is_dir($this->fixture)) return;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->fixture,
                FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
            else unlink($file->getPathname());
        }
        rmdir($this->fixture);
    }

    private function runFixture($name, $options = array())
    {
        $process = proc_open(array(PHP_BINARY, '-f', $this->fixture . '/php/' . $name),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'),
                2 => array('pipe', 'w')), $pipes, $this->fixture . '/php',
            array_merge($_ENV, array('RT_LOCKOUT_STATE' => $this->fixture . '/state.json'), $options));
        $this->assertTrue(is_resource($process), 'the shipped ' . $name . ' entrypoint starts');
        if (!is_resource($process)) return false;
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $result = json_decode($output, true);
        $this->assertTrue($status === 0 && $error === '' && is_array($result),
            $name . ' returns a clean inspectable result: ' . $error);
        return $result;
    }

    public function testSuccessfulInitKeepsShutdownAvailableAfterLateRemoteFailure()
    {
        $init = $this->runFixture('run-init.php', array('RT_LOCKOUT_INIT_OK' => '1'));
        if ($init === false) return;
        $this->assertTrue($init['registered_before'] === true &&
            strpos($init['init_output'], 'plugin.shutdownWhileDisabled = true') !== false &&
            strpos($init['init_output'], 'plugin.disable()') === false,
            'successful init prepares shutdown before a later remote check can disable the plugin');

        $info = file_get_contents(__DIR__ . '/../../../plugins/retrackers/plugin.info');
        $this->assertTrue(strpos($info, 'rtorrent.external.error: php') !== false,
            'retrackers requires the daemon PHP probe that can run after init');
        $source = file_get_contents(__DIR__ . '/../../../php/getplugins.php');
        $initAt = strpos($source, 'require_once( $plugin["php"] );');
        $remoteAt = strpos($source, '$jResult.=testRemoteRequests($remoteRequests);');
        $this->assertTrue($initAt !== false && $remoteAt !== false && $initAt < $remoteAt &&
            strpos($source, '$remoteStr.="thePlugins.get(') !== false,
            'getplugins can append a remote-check disable after successful PHP init');

        $done = $this->runFixture('run-done.php');
        if ($done === false) return;
        $this->assertTrue($done['done_calls'] === 1 && $done['registered_after'] === true &&
            strpos($done['done_output'], 'hook-teardown-pending') !== false &&
            strpos($done['done_output'], '.remove()') === false,
            'late-disabled plugin can retry done and keeps the path on refusal');
    }

    public function testFailedInitKeepsAReachableDoneAttemptAndItsRefusalRetryable()
    {
        $init = $this->runFixture('run-init.php');
        if ($init === false) return;
        $this->assertTrue($init['registered_before'] === true &&
            strpos($init['init_output'], 'plugin.disable()') !== false &&
            strpos($init['init_output'], 'plugin.shutdownWhileDisabled = true') !== false,
            'failed init registers a disabled plugin and exposes its shutdown retry');

        $done = $this->runFixture('run-done.php');
        if ($done === false) return;
        $this->assertEquals(1, $done['done_calls'],
            'doneplugins invokes retrackers done after failed init');
        $this->assertTrue($done['registered_after'] === true &&
            strpos($done['done_output'], 'hook-teardown-pending') !== false &&
            strpos($done['done_output'], '.remove()') === false,
            'a refused cancellation preserves registration and a visible retry path');
    }
}
