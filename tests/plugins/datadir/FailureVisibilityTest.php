<?php

require_once __DIR__ . '/../../php/TestCase.php';

class DataDirFailureVisibilityTest extends TestCase
{
    private $tree;

    public function setUp()
    {
        $this->tree = sys_get_temp_dir() . '/rutorrent-datadir-log-' . uniqid('', true);
        foreach (array('', '/php', '/plugins', '/plugins/datadir') as $dir)
            if (!mkdir($this->tree . $dir, 0700) && !is_dir($this->tree . $dir))
                throw new RuntimeException('Could not create DataDir fixture directory');
        $root = dirname(__DIR__, 3);
        foreach (array('action.php', 'setdir.php') as $name)
            if (!copy($root . '/plugins/datadir/' . $name, $this->tree . '/plugins/datadir/' . $name))
                throw new RuntimeException('Could not copy production ' . $name);
        file_put_contents($this->tree . '/php/xmlrpc.php', '<?php require_once __DIR__ . "/util.php";');
        file_put_contents($this->tree . '/php/util.php', <<<'STUB'
<?php
class FileUtil
{
    public static function getPluginConf($name) { return '$datadir_debug_enabled = false;'; }
    public static function toLog($line) { file_put_contents(getenv('DATADIR_TEST_LOG'), $line . "\n", FILE_APPEND); }
}
class rTorrentSettings
{
    public static function get() { return new self(); }
    public function correctDirectory(&$dir) { return strpos($dir, '/data/downloads/') === 0; }
}
class Utility { public static function getPHP() { return PHP_BINARY; } }
class User { public static function getUser() { return 'test'; } }
class JSON { public static function safeEncode($value) { return json_encode($value); } }
class CachedEcho
{
    public static function send($body, $type) { echo $body; }
}
STUB
        );
        file_put_contents($this->tree . '/plugins/datadir/util_rt.php', <<<'STUB'
<?php
function rtSemGet($key) { return false; }
function rtSemLock($key) {}
function rtSemUnlock($key) {}
function rtMkDir($path, $mode) { return getenv('DATADIR_TEST_MKDIR') !== 'fail'; }
function rtAddTailSlash($path) { return rtrim($path, '/') . '/'; }
function rtExec($command, $args, $debug)
{
    $log = getenv('DATADIR_TEST_COMMAND_LOG');
    if ($command === 'execute' && $log) file_put_contents($log, json_encode($args));
    return getenv('DATADIR_TEST_EXEC') !== 'fail';
}
function rtDbg($scope, $line) { FileUtil::toLog($scope . ': ' . $line); }
STUB
        );
        file_put_contents($this->tree . '/plugins/datadir/util_setdir.php', <<<'STUB'
<?php
function rtSetDataDir($hash, $path, $add, $move, $resume, $debug)
{
    $result = getenv('DATADIR_TEST_RESULT');
    if ($result === 'pending') {
        FileUtil::toLog('datadir: torrent reload pending confirmation: ' . $hash);
        return null;
    }
    return $result !== 'fail';
}
STUB
        );
        file_put_contents($this->tree . '/plugins/datadir/action_driver.php', <<<'STUB'
<?php
$HTTP_RAW_POST_DATA = getenv('DATADIR_TEST_BODY');
require __DIR__ . '/action.php';
STUB
        );
    }

    public function tearDown()
    {
        foreach (array('app.log', 'dispatch.json', 'php/xmlrpc.php', 'php/util.php', 'plugins/datadir/action.php',
            'plugins/datadir/setdir.php', 'plugins/datadir/util_rt.php',
            'plugins/datadir/util_setdir.php', 'plugins/datadir/action_driver.php') as $file)
            @unlink($this->tree . '/' . $file);
        foreach (array('plugins/datadir', 'plugins', 'php', '') as $dir)
            @rmdir($this->tree . ($dir === '' ? '' : '/' . $dir));
    }

    private function runEntry($script, $args = array(), $env = array())
    {
        $log = $this->tree . '/app.log';
        $environment = array_merge($_ENV, array('DATADIR_TEST_LOG' => $log), $env);
        $command = array_merge(array(PHP_BINARY, $this->tree . '/plugins/datadir/' . $script), $args);
        $proc = proc_open($command, array(0 => array('pipe', 'r'),
            1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes,
            $this->tree . '/plugins/datadir', $environment);
        if (!is_resource($proc)) throw new RuntimeException('Could not start DataDir entrypoint');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return array($code, $output, $errors,
            is_file($log) ? file_get_contents($log) : '');
    }

    private function worker($args = array(), $env = array())
    {
        return $this->runEntry('setdir.php', $args, $env);
    }

    private function validWorkerArgs()
    {
        return array(str_repeat('A', 40), '/data/downloads/new', '1', '0', '0', 'test');
    }

    private function action($body, $env = array())
    {
        return $this->runEntry('action_driver.php', array(),
            array_merge(array('DATADIR_TEST_BODY' => $body), $env));
    }

    public function testWorkerLogsDirectoryCreationFailureWithDebugDisabled()
    {
        list($code, $output, $stderr, $log) = $this->worker($this->validWorkerArgs(),
            array('DATADIR_TEST_MKDIR' => 'fail'));
        $this->assertSame(0, $code, 'Worker exits normally after directory refusal');
        $this->assertSame('', $stderr, 'Directory refusal does not cause a PHP fatal');
        $this->assertTrue(strpos($log, 'datadir: destination-create-failed hash=') !== false,
            'Worker logs classified directory refusal even with debug disabled');
        $this->assertTrue(strpos($log, '/data/downloads/new') === false,
            'Worker failure log does not expose the destination path');
    }

    public function testWorkerLogsFailedTransferWithDebugDisabled()
    {
        list($code, $output, $stderr, $log) = $this->worker($this->validWorkerArgs(),
            array('DATADIR_TEST_RESULT' => 'fail'));
        $this->assertSame(0, $code, 'Worker exits normally after transfer refusal');
        $this->assertSame('', $stderr, 'Transfer refusal does not cause a PHP fatal');
        $this->assertTrue(strpos($log, 'datadir: transfer-failed hash=') !== false,
            'Worker logs classified transfer refusal even with debug disabled');
    }

    public function testWorkerLogsInvalidArguments()
    {
        list($code, $output, $stderr, $log) = $this->worker();
        $this->assertSame(0, $code, 'Worker exits normally on missing arguments');
        $this->assertTrue(strpos($log, 'datadir: worker refused: invalid-arguments') !== false,
            'Worker logs missing argument refusal');
    }

    public function testPendingReloadKeepsItsExistingSpecificLog()
    {
        list($code, $output, $stderr, $log) = $this->worker($this->validWorkerArgs(),
            array('DATADIR_TEST_RESULT' => 'pending'));
        $this->assertSame(0, $code, 'Pending reload is not misreported as a failure');
        $this->assertTrue(strpos($log, 'datadir: torrent reload pending confirmation:') !== false,
            'Specific pending confirmation remains visible');
        $this->assertTrue(strpos($log, 'transfer-failed') === false,
            'Pending confirmation does not get a false terminal failure log');
    }

    public function testActionLogsUnconfirmedDispatchAndReturnsError()
    {
        $body = 'hash=' . str_repeat('A', 40) . '&datadir=%2Fdata%2Fdownloads%2Fnew';
        list($code, $output, $stderr, $log) = $this->action($body,
            array('DATADIR_TEST_EXEC' => 'fail'));
        $this->assertSame(0, $code, 'Action responds after unconfirmed dispatch');
        $decoded = json_decode($output, true);
        $this->assertTrue(isset($decoded['errors'][0]), 'Action returns a visible launch error');
        $this->assertTrue(strpos($log, 'datadir: worker-dispatch-unconfirmed hash=') !== false,
            'Action logs classified unconfirmed worker dispatch');
    }

    public function testActionLogsInvalidHashWithoutLaunching()
    {
        list($code, $output, $stderr, $log) = $this->action('hash=bad!&datadir=%2Fdata%2Fdownloads%2Fnew');
        $this->assertSame(0, $code, 'Action responds after invalid hash');
        $decoded = json_decode($output, true);
        $this->assertTrue(isset($decoded['errors'][0]), 'Action returns invalid request error');
        $this->assertTrue(strpos($log, 'datadir: setdatadir refused: invalid hash') !== false,
            'Action logs classified invalid hash refusal');
    }

    public function testWorkerRejectsUnvalidatedHashWithoutLoggingInput()
    {
        $args = $this->validWorkerArgs();
        $args[0] = 'private-invalid-hash';
        list($code, $output, $stderr, $log) = $this->worker($args);
        $this->assertSame(0, $code, 'Worker responds after invalid hash');
        $this->assertTrue(strpos($log, 'datadir: worker refused: invalid-arguments') !== false,
            'Worker logs classified invalid hash');
        $this->assertTrue(strpos($log, 'private-invalid-hash') === false,
            'Worker does not copy malformed hash into log');
    }

    public function testActionRejectsShortHexHash()
    {
        list($code, $output, $stderr, $log) = $this->action(
            'hash=A&datadir=%2Fdata%2Fdownloads%2Fnew');
        $this->assertSame(0, $code, 'Action responds after short hash');
        $decoded = json_decode($output, true);
        $this->assertTrue(isset($decoded['errors'][0]), 'Short hash is not launched');
        $this->assertTrue(strpos($log, 'datadir: setdatadir refused: invalid hash') !== false,
            'Action logs short hash refusal');
    }

    public function testActionLogsInvalidDestination()
    {
        $body = 'hash=' . str_repeat('A', 40) . '&datadir=%2Foutside';
        list($code, $output, $stderr, $log) = $this->action($body);
        $this->assertSame(0, $code, 'Action responds after invalid destination');
        $decoded = json_decode($output, true);
        $this->assertTrue(isset($decoded['errors'][0]), 'Invalid destination is not launched');
        $this->assertTrue(strpos($log, 'datadir: setdatadir refused: invalid destination') !== false,
            'Action logs classified destination refusal');
        $this->assertTrue(strpos($log, '/outside') === false,
            'Action does not put destination path in log');
    }

    public function testActionSuccessOnlyAcknowledgesWorkerLaunch()
    {
        $body = 'hash=' . str_repeat('A', 40) . '&datadir=%2Fdata%2Fdownloads%2Fnew';
        $dispatch = $this->tree . '/dispatch.json';
        list($code, $output, $stderr, $log) = $this->action($body,
            array('DATADIR_TEST_COMMAND_LOG' => $dispatch));
        $this->assertSame(0, $code, 'Action responds after worker launch');
        $this->assertSame(array('errors' => array()), json_decode($output, true),
            'HTTP success acknowledges launch only');
        $this->assertSame('', $log, 'Successful launch produces no refusal log');
        $args = json_decode(file_get_contents($dispatch), true);
        $this->assertTrue(is_array($args) && strpos($args[2], ' 1 0 0 ') !== false,
            'UI request without the removed option dispatches the in-place worker path');
    }
}
