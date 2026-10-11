<?php

require_once __DIR__ . '/../../php/TestCase.php';

class DataDirFailureVisibilityTest extends TestCase
{
    private $tree;

    public function setUp()
    {
        $this->tree = sys_get_temp_dir() . '/rutorrent-datadir-log-' . uniqid('', true);
        foreach (array('', '/php', '/plugins', '/plugins/datadir', '/plugins/erasedata', '/allowed', '/outside') as $dir)
            if (!mkdir($this->tree . $dir, 0700) && !is_dir($this->tree . $dir))
                throw new RuntimeException('Could not create DataDir fixture directory');
        symlink($this->tree . '/outside', $this->tree . '/allowed/link');
        $root = dirname(__DIR__, 3);
        if (!copy($root . '/php/cli-only.php', $this->tree . '/php/cli-only.php'))
            throw new RuntimeException('Could not copy production CLI guard');
        foreach (array('action.php', 'setdir.php', 'setdircommand.php', 'moveintent.php', 'apply.php') as $name)
            if (!copy($root . '/plugins/datadir/' . $name, $this->tree . '/plugins/datadir/' . $name))
                throw new RuntimeException('Could not copy production ' . $name);
        // The shared payload trust rule lives with the erasedata filesystem owner.
        foreach (array('filesystem.php', 'manifest.php', 'pending.php') as $name)
            if (!copy($root . '/plugins/erasedata/' . $name, $this->tree . '/plugins/erasedata/' . $name))
                throw new RuntimeException('Could not copy production erasedata ' . $name);
        if (!mkdir($this->tree . '/php/utility', 0700)
            || !copy($root . '/php/utility/permission.php', $this->tree . '/php/utility/permission.php'))
            throw new RuntimeException('Could not copy shared process identity helper');
        file_put_contents($this->tree . '/php/xmlrpc.php', '<?php require_once __DIR__ . "/util.php";');
        file_put_contents($this->tree . '/php/util.php', <<<'STUB'
<?php
if (getenv('DATADIR_TEST_TOP_DIRECTORY'))
    $GLOBALS['topDirectory'] = getenv('DATADIR_TEST_TOP_DIRECTORY');
class FileUtil
{
    public static function getPluginConf($name) { return '$datadir_debug_enabled = false;'; }
    public static function toLog($line) { file_put_contents(getenv('DATADIR_TEST_LOG'), $line . "\n", FILE_APPEND); }
}
class rTorrentSettings
{
    public static function get() { return new self(); }
    public function correctDirectory(&$dir, $resolve_links = false)
    {
        $top = getenv('DATADIR_TEST_TOP');
        if (!$top) return strpos($dir, '/data/downloads/') === 0;
        if ($resolve_links) {
            $real = realpath($dir);
            $dir = $real === false ? realpath(dirname($dir)) . '/' . basename($dir) : $real;
        }
        return strpos($dir . '/', rtrim($top, '/') . '/') === 0;
    }
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
function rtDataDirPendingRefusal($hash) { return getenv('DATADIR_TEST_PENDING') ? 'prior-job-pending' : ''; }
function rtSemLock($key) {}
function rtSemUnlock($key) {}
function rtMkDir($path, $mode)
{
    $log = getenv('DATADIR_TEST_MKDIR_LOG');
    if ($log) file_put_contents($log, $path);
    return getenv('DATADIR_TEST_MKDIR') !== 'fail';
}
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
require_once __DIR__ . '/moveintent.php';
function datadirTestRecord($variable, $value)
{
    $log = getenv($variable);
    if ($log) file_put_contents($log, json_encode($value) . "\n", FILE_APPEND);
}
// A busy profile lock: a nonblocking caller is refused, a blocking one waits.
function rtDataDirLock($nonBlocking)
{
    datadirTestRecord('DATADIR_TEST_LOCK_LOG', $nonBlocking);
    return !($nonBlocking && getenv('DATADIR_TEST_LOCK') === 'busy');
}
function rtDataDirRecover($payloadMoves = true)
{
    datadirTestRecord('DATADIR_TEST_RECOVER_LOG', $payloadMoves);
}
function rtDataDirUnlock($lock) {}
// The production one keeps the refusal on the download (ACC-OWNB-01).
function rtDataDirWorkerRefusal($hash, $failure)
{
    datadirTestRecord('DATADIR_TEST_OUTCOME_LOG', array($hash, $failure));
}
function rtDataDirClaimCapability()
{
    datadirTestRecord('DATADIR_TEST_CAPABILITY_LOG', 'listMethods');
    $mode = getenv('DATADIR_TEST_CLAIM_CAPABILITY');
    return $mode ? $mode : 'available';
}
function rtDataDirCollision($hash, $path, $add, $debug)
{
    $reason = getenv('DATADIR_TEST_PREFLIGHT_REFUSAL');
    if ($reason) throw new RuntimeException($reason);
    return '';
}
// Production rtSetDataDir() writes its own one line for a refusal.
function rtSetDataDir($hash, $path, $add, $move, $resume, $debug, &$failure = null, &$warning = null)
{
    $result = getenv('DATADIR_TEST_RESULT');
    if ($result === 'fail') {
        $failure = 'daemon-claim-refused';
        FileUtil::toLog('datadir: change refused hash=' . $hash . ' reason=' . $failure);
        return false;
    }
    $warning = $result === 'stopped' ? 'stopped-after-hash-check' : '';
    return true;
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
        // Remove the whole fixture tree, whatever a case wrote into it, without
        // following the allowed/link symlink out of it.
        $remove = function ($path) use (&$remove) {
            if (is_link($path) || is_file($path)) return @unlink($path);
            if (!is_dir($path)) return;
            foreach (array_diff(scandir($path), array('.', '..')) as $name) $remove($path . '/' . $name);
            @rmdir($path);
        };
        $remove($this->tree);
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

    public function testWorkerRefusesSymlinkEscapeBeforeDirectoryCreation()
    {
        $args = array(str_repeat('A', 40), $this->tree . '/allowed/link', '1', '1', '0', 'test');
        list($code, $output, $stderr, $log) = $this->worker($args,
            array('DATADIR_TEST_TOP' => $this->tree . '/allowed'));
        $this->assertSame(0, $code, 'Worker responds after physical path refusal');
        $this->assertTrue(strpos($log, 'datadir: worker refused hash=' . str_repeat('A', 40)
            . ' reason=destination-invalid') !== false,
            'Worker logs physical destination refusal before creating or moving');
    }

    public function testWorkerRefusesMissingClaimBeforeDestinationCreation()
    {
        $mkdirLog = $this->tree . '/mkdir.log';
        list($code, $output, $stderr, $log) = $this->worker($this->validWorkerArgs(),
            array('DATADIR_TEST_CLAIM_CAPABILITY' => 'unsupported',
                'DATADIR_TEST_MKDIR_LOG' => $mkdirLog));
        $this->assertSame(0, $code, 'Worker responds to unsupported daemon');
        $this->assertSame('', $stderr, 'Missing claim produces no PHP fatal');
        $this->assertTrue(strpos($log, 'daemon-claim-unavailable') !== false,
            'Worker logs a classified missing-method reason');
        $this->assertTrue(!is_file($mkdirLog), 'Worker does not create a target before refusal');
    }

    public function testWorkerKeepsUnknownClaimDistinctBeforeDestinationCreation()
    {
        $mkdirLog = $this->tree . '/mkdir.log';
        list($code, $output, $stderr, $log) = $this->worker($this->validWorkerArgs(),
            array('DATADIR_TEST_CLAIM_CAPABILITY' => 'unknown',
                'DATADIR_TEST_MKDIR_LOG' => $mkdirLog));
        $this->assertSame(0, $code, 'Worker responds to unknown capability');
        $this->assertTrue(strpos($log, 'daemon-claim-unconfirmed') !== false,
            'Worker logs unconfirmed rather than unsupported capability');
        $this->assertTrue(strpos($log, 'daemon-claim-unavailable') === false,
            'Worker does not infer missing method from unknown transport');
        $this->assertTrue(!is_file($mkdirLog), 'Unknown capability creates no target');
    }

    public function testWorkerLogsDirectoryCreationFailureWithDebugDisabled()
    {
        list($code, $output, $stderr, $log) = $this->worker($this->validWorkerArgs(),
            array('DATADIR_TEST_MKDIR' => 'fail'));
        $this->assertSame(0, $code, 'Worker exits normally after directory refusal');
        $this->assertSame('', $stderr, 'Directory refusal does not cause a PHP fatal');
        $this->assertTrue(strpos($log, 'datadir: change refused hash=' . str_repeat('A', 40)
            . ' reason=destination-create-failed') !== false,
            'Worker logs classified directory refusal even with debug disabled');
        $this->assertTrue(strpos($log, '/data/downloads/new') === false,
            'Worker failure log does not expose the destination path');
    }

    public function testWorkerLogsARefusedChangeOnce()
    {
        list($code, $output, $stderr, $log) = $this->worker($this->validWorkerArgs(),
            array('DATADIR_TEST_RESULT' => 'fail'));
        $this->assertSame(0, $code, 'Worker exits normally after transfer refusal');
        $this->assertSame('', $stderr, 'Transfer refusal does not cause a PHP fatal');
        $this->assertSame('datadir: change refused hash=' . str_repeat('A', 40) . " reason=daemon-claim-refused\n",
            $log, 'The change logs its classified refusal once; the transaction adds no second line');
    }

    // ACC-OWNB-01: the dialog of a payload move saw only the launch, so a
    // refusal in its worker is kept on the download as well as logged.
    public function testWorkerKeepsItsRefusalOnTheDownload()
    {
        $outcomes = $this->tree . '/outcome.log';
        foreach (array('change' => array('DATADIR_TEST_RESULT' => 'fail'),
            'request' => array('DATADIR_TEST_CLAIM_CAPABILITY' => 'unsupported')) as $case => $env) {
            @unlink($outcomes);
            list($code, $output, $stderr, $log) = $this->worker($this->validWorkerArgs(),
                array_merge($env, array('DATADIR_TEST_OUTCOME_LOG' => $outcomes)));
            $this->assertSame(0, $code, 'Worker exits normally: ' . $case);
            $this->assertSame(json_encode(array(str_repeat('A', 40), $case === 'change'
                ? 'daemon-claim-refused' : 'daemon-claim-unavailable')) . "\n", @file_get_contents($outcomes),
                'the refusal is kept once on the download: ' . $case);
        }
        @unlink($outcomes);
        $this->worker($this->validWorkerArgs(), array('DATADIR_TEST_OUTCOME_LOG' => $outcomes));
        $this->assertTrue(!is_file($outcomes), 'a successful change keeps no outcome');
    }

    public function testWorkerLogsInvalidArguments()
    {
        list($code, $output, $stderr, $log) = $this->worker();
        $this->assertSame(0, $code, 'Worker exits normally on missing arguments');
        $this->assertTrue(strpos($log, 'datadir: worker refused: invalid-arguments') !== false,
            'Worker logs missing argument refusal');
    }

    public function testWorkerWaitsForTheLockAndReplaysPayloadJobs()
    {
        $lock = $this->tree . '/lock.log';
        $recover = $this->tree . '/recover.log';
        list($code, $output, $stderr, $log) = $this->worker($this->validWorkerArgs(),
            array('DATADIR_TEST_LOCK_LOG' => $lock, 'DATADIR_TEST_RECOVER_LOG' => $recover));
        $this->assertSame(0, $code, 'Worker completes');
        $this->assertSame("false\n", file_get_contents($lock), 'The CLI worker waits for its turn');
        $this->assertSame("true\n", file_get_contents($recover), 'The worker replays every interrupted job');
        $this->assertSame('', $log, 'A successful change logs nothing');
    }

    public function testActionLogsUnconfirmedDispatchAndReturnsError()
    {
        $body = 'hash=' . str_repeat('A', 40) . '&datadir=%2Fdata%2Fdownloads%2Fnew&move_datafiles=1';
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
        $this->assertSame(array(array('desc' => 'theUILang.datadirSetDirFail', 'prm' => 'invalid-arguments')),
            $decoded['errors'], 'The dialog names the classified refusal, not the request input');
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
        $this->assertSame(array(array('desc' => 'theUILang.datadirSetDirFail', 'prm' => 'destination-invalid')),
            $decoded['errors'], 'The dialog names the classified refusal, not an empty reason');
        $this->assertTrue(strpos($log, 'datadir: setdatadir refused hash=' . str_repeat('A', 40)
            . ' reason=destination-invalid') !== false, 'Action logs classified destination refusal with its hash');
        $this->assertTrue(strpos($log, '/outside') === false,
            'Action does not put destination path in log');
    }

    public function testActionRejectsSymlinkEscapeBeforeDispatch()
    {
        $body = 'hash=' . str_repeat('A', 40) . '&datadir=' . rawurlencode($this->tree . '/allowed/link');
        $dispatch = $this->tree . '/dispatch.json';
        list($code, $output, $stderr, $log) = $this->action($body,
            array('DATADIR_TEST_TOP' => $this->tree . '/allowed',
                'DATADIR_TEST_COMMAND_LOG' => $dispatch));
        $this->assertSame(0, $code, 'Action responds after physical path refusal');
        $decoded = json_decode($output, true);
        $this->assertTrue(isset($decoded['errors'][0]), 'Outside symlink target is refused');
        $this->assertTrue(!is_file($dispatch), 'Outside symlink target is not dispatched');
        $this->assertTrue(strpos($log, 'reason=destination-invalid') !== false,
            'Outside symlink refusal is visible');
    }

    public function testMoveActionSuccessOnlyAcknowledgesWorkerLaunch()
    {
        $body = 'hash=' . str_repeat('A', 40) . '&datadir=%2Fdata%2Fdownloads%2Fnew&move_datafiles=1';
        $dispatch = $this->tree . '/dispatch.json';
        list($code, $output, $stderr, $log) = $this->action($body,
            array('DATADIR_TEST_COMMAND_LOG' => $dispatch));
        $this->assertSame(0, $code, 'Action responds after worker launch');
        $this->assertSame(array('errors' => array()), json_decode($output, true),
            'HTTP success acknowledges launch only');
        $this->assertSame('', $log, 'Successful launch produces no refusal log');
        $args = json_decode(file_get_contents($dispatch), true);
        $this->assertTrue(is_array($args) && strpos($args[2], " '1' '1' '0' ") !== false,
            'UI request without the removed option dispatches the in-place worker path');
    }

    public function testSetDirectoryReturnsTheActualClaimRefusalToTheDialog()
    {
        $dispatch = $this->tree . '/dispatch.json';
        $capability = $this->tree . '/capability.log';
        $body = 'hash=' . str_repeat('A', 40) . '&datadir=%2Fdata%2Fdownloads%2Fnew&move_datafiles=0';
        list($code, $output, $stderr, $log) = $this->action($body,
            array('DATADIR_TEST_RESULT'=>'fail', 'DATADIR_TEST_COMMAND_LOG'=>$dispatch,
                'DATADIR_TEST_CAPABILITY_LOG'=>$capability));
        $this->assertSame(0, $code, 'Set directory returns an application response');
        $this->assertSame('', $stderr, 'Set directory has no PHP fatal');
        $decoded = json_decode($output, true);
        $this->assertSame('daemon-claim-refused', $decoded['errors'][0]['prm'] ?? null,
            'The dialog sees the actual failed change');
        $this->assertTrue(!is_file($dispatch), 'Set directory completes under the web request lock');
        $this->assertSame('datadir: change refused hash=' . str_repeat('A', 40) . " reason=daemon-claim-refused\n",
            $log, 'The refusal is logged once');
        $this->assertSame("\"listMethods\"\n", file_get_contents($capability),
            'One request reads the claim ABI once');
    }

    public function testSetDirectoryNeverWaitsBehindTheProfileLock()
    {
        $lock = $this->tree . '/lock.log';
        $recover = $this->tree . '/recover.log';
        $body = 'hash=' . str_repeat('A', 40) . '&datadir=%2Fdata%2Fdownloads%2Fnew&move_datafiles=0';
        list($code, $output, $stderr, $log) = $this->action($body,
            array('DATADIR_TEST_LOCK'=>'busy', 'DATADIR_TEST_LOCK_LOG'=>$lock,
                'DATADIR_TEST_RECOVER_LOG'=>$recover));
        $this->assertSame(0, $code, 'A busy profile still answers the dialog');
        $decoded = json_decode($output, true);
        $this->assertSame('datadir-busy', $decoded['errors'][0]['prm'] ?? null,
            'The dialog sees a classified busy profile');
        $this->assertSame("true\n", file_get_contents($lock), 'The web request asks for the lock without waiting');
        $this->assertTrue(!is_file($recover), 'Nothing is replayed without the lock');
        $this->assertSame('datadir: change refused hash=' . str_repeat('A', 40) . " reason=datadir-busy\n",
            $log, 'The busy refusal is visible once');
    }

    public function testSetDirectoryReplaysOnlyDirectoryOnlyJobs()
    {
        $recover = $this->tree . '/recover.log';
        $body = 'hash=' . str_repeat('A', 40) . '&datadir=%2Fdata%2Fdownloads%2Fnew&move_datafiles=0';
        list($code, $output, $stderr, $log) = $this->action($body,
            array('DATADIR_TEST_RECOVER_LOG'=>$recover));
        $this->assertSame(array('errors' => array()), json_decode($output, true), 'The change succeeds');
        $this->assertSame("false\n", file_get_contents($recover),
            'A payload move is never replayed inside the web request');
    }

    public function testSetDirectoryReportsAStoppedDownloadAsAWarning()
    {
        $body = 'hash=' . str_repeat('A', 40) . '&datadir=%2Fdata%2Fdownloads%2Fnew&move_datafiles=0';
        list($code, $output, $stderr, $log) = $this->action($body,
            array('DATADIR_TEST_RESULT'=>'stopped'));
        $this->assertSame(array('errors' => array(), 'warnings' => array(array(
            'desc' => 'theUILang.datadirStoppedAfterCheck', 'prm' => 'stopped-after-hash-check'))),
            json_decode($output, true), 'The applied change and the stop that followed are both visible');
    }

    public function testActionReportsMissingDaemonClaimBeforeDispatch()
    {
        $body = 'hash=' . str_repeat('A', 40)
            . '&datadir=%2Fdata%2Fdownloads%2Fnew&move_datafiles=1';
        $dispatch = $this->tree . '/dispatch.json';
        list($code, $output, $stderr, $log) = $this->action($body,
            array('DATADIR_TEST_CLAIM_CAPABILITY' => 'unsupported',
                'DATADIR_TEST_COMMAND_LOG' => $dispatch));
        $decoded = json_decode($output, true);
        $this->assertSame(0, $code, 'Action responds to an unsupported daemon');
        $this->assertSame('daemon-claim-unavailable', $decoded['errors'][0]['prm'],
            'UI receives the classified missing-method reason');
        $this->assertTrue(strpos($log, 'daemon-claim-unavailable') !== false,
            'App log records the unsupported daemon');
        $this->assertTrue(!is_file($dispatch), 'Unsupported Move has no worker dispatch');
    }

    public function testActionReportsUnknownClaimCapabilityWithoutCallingItUnsupported()
    {
        $body = 'hash=' . str_repeat('A', 40)
            . '&datadir=%2Fdata%2Fdownloads%2Fnew&move_datafiles=1';
        $dispatch = $this->tree . '/dispatch.json';
        list($code, $output, $stderr, $log) = $this->action($body,
            array('DATADIR_TEST_CLAIM_CAPABILITY' => 'unknown',
                'DATADIR_TEST_COMMAND_LOG' => $dispatch));
        $decoded = json_decode($output, true);
        $this->assertSame(0, $code, 'Action responds after an unknown RPC result');
        $this->assertSame('daemon-claim-unconfirmed', $decoded['errors'][0]['prm'],
            'UI distinguishes unknown transport from a missing method');
        $this->assertTrue(strpos($log, 'daemon-claim-unconfirmed') !== false,
            'App log records the unconfirmed capability');
        $this->assertTrue(strpos($log, 'daemon-claim-unavailable') === false,
            'Unknown transport is not diagnosed as a missing method');
        $this->assertTrue(!is_file($dispatch), 'Unknown capability has no worker dispatch');
    }

    public function testActionReturnsAnUntrustedDestinationReasonBeforeWorkerDispatch()
    {
        $path = $this->tree . '/allowed';
        chmod($path, 0777);
        $dispatch = $this->tree . '/dispatch.json';
        $body = 'hash=' . str_repeat('A', 40) . '&datadir=' . rawurlencode($path);
        list($code, $output, $stderr, $log) = $this->action($body,
            array('DATADIR_TEST_TOP'=>$path, 'DATADIR_TEST_COMMAND_LOG'=>$dispatch));
        $decoded = json_decode($output, true);
        $this->assertSame(0, $code, 'An unsafe destination produces a normal application response');
        $this->assertSame('destination-root-untrusted', $decoded['errors'][0]['prm'] ?? null,
            'The dialog receives the classified destination refusal');
        $this->assertTrue(!is_file($dispatch), 'An unsafe destination is not dispatched');
        $this->assertTrue(strpos($log, 'destination-root-untrusted') !== false, 'Application refusal is logged');
    }

    public function testWorkerLogsTheUntrustedDestinationBeforeCreatingOrClaiming()
    {
        $path = $this->tree . '/allowed';
        chmod($path, 0777);
        $mkdir = $this->tree . '/mkdir.log';
        list($code, $output, $stderr, $log) = $this->worker(array(str_repeat('A', 40), $path, '1', '1', '0', 'test'),
            array('DATADIR_TEST_TOP'=>$path, 'DATADIR_TEST_MKDIR_LOG'=>$mkdir));
        $this->assertSame(0, $code, 'Worker safely refuses an untrusted destination');
        $this->assertTrue(!is_file($mkdir), 'Worker never creates the refused destination');
        $this->assertTrue(strpos($log, 'destination-root-untrusted') !== false, 'Worker refusal has its actual cause');
    }

    // R3-OWN-01: a folder created from Windows below the configured top is
    // 0777 on drvfs; the shared payload rule admits it.
    public function testActionAdmitsAWindowsLikeFolderBelowTheConfiguredTop()
    {
        $path = $this->tree . '/allowed';
        mkdir($path . '/win', 0777);
        chmod($path . '/win', 0777);
        chmod($path, 0777);
        $dispatch = $this->tree . '/dispatch.json';
        $body = 'hash=' . str_repeat('A', 40) . '&datadir=' . rawurlencode($path . '/win') . '&move_datafiles=1';
        list($code, $output, $stderr, $log) = $this->action($body,
            array('DATADIR_TEST_TOP'=>$path, 'DATADIR_TEST_TOP_DIRECTORY'=>$path . '/',
                'DATADIR_TEST_COMMAND_LOG'=>$dispatch));
        $this->assertSame(array('errors' => array()), json_decode($output, true),
            'a Windows-like destination is admitted: ' . $output);
        $this->assertTrue(is_file($dispatch), 'the move worker is dispatched');
        $this->assertSame('', $log, 'no refusal is logged');
    }

    public function testActionReturnsTheClassifiedMovePreflightRefusal()
    {
        $path = $this->tree . '/allowed';
        $dispatch = $this->tree . '/dispatch.json';
        $body = 'hash=' . str_repeat('A', 40) . '&datadir=' . rawurlencode($path) . '&move_datafiles=1';
        list($code, $output, $stderr, $log) = $this->action($body,
            array('DATADIR_TEST_TOP'=>$path, 'DATADIR_TEST_COMMAND_LOG'=>$dispatch,
                'DATADIR_TEST_PREFLIGHT_REFUSAL'=>'rename-noreplace-unsupported'));
        $decoded = json_decode($output, true);
        $this->assertSame(0, $code, 'A preflight refusal produces a normal application response');
        $this->assertSame('rename-noreplace-unsupported', $decoded['errors'][0]['prm'] ?? null,
            'The dialog receives the filesystem refusal');
        $this->assertTrue(!is_file($dispatch), 'Refused Move is not dispatched');
        $this->assertTrue(strpos($log, 'rename-noreplace-unsupported') !== false, 'Application preserves the actual reason');
    }

    public function testActionReportsAHeldJobBeforeCapabilityOrDetachedDispatch()
    {
        $dispatch = $this->tree . '/dispatch.json';
        $capability = $this->tree . '/capability.json';
        $outcome = $this->tree . '/outcome.json';
        $body = 'hash=' . str_repeat('A', 40) . '&datadir=/data/downloads/new&move_datafiles=1';
        list($code, $output) = $this->action($body, array('DATADIR_TEST_PENDING'=>'1',
            'DATADIR_TEST_COMMAND_LOG'=>$dispatch, 'DATADIR_TEST_CAPABILITY_LOG'=>$capability,
            'DATADIR_TEST_OUTCOME_LOG'=>$outcome));
        $answer = json_decode($output, true);
        $this->assertSame(0, $code, 'a prior job is a normal classified refusal');
        $this->assertSame('prior-job-pending', $answer['errors'][0]['prm'] ?? null, 'the dialog reports the real owner');
        $this->assertTrue(!is_file($dispatch) && !is_file($capability), 'no detached worker or new claim path runs');
        $this->assertTrue(is_file($outcome), 'the existing held document gets a durable visible outcome');
    }
}
