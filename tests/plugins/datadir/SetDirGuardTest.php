<?php

require_once __DIR__ . '/../rutracker_check/TestLib.php';

class FileUtil
{
    public static function toLog($message) { DataDirTestState::$logs[] = $message; }
}

require_once testFindRepoRoot() . '/plugins/erasedata/filesystem.php';
require_once testFindRepoRoot() . '/plugins/erasedata/outcome.php';

// The real shared runner, status table and parent preflight behind a scripted
// helper: only the executable path differs from production.
class DataDirScriptedFilesystem extends ErasedataFilesystemOps
{
    public static $helper = null;
    public static function renameHelperPath() { return self::$helper; }
}

class DataDirTestState
{
    public static $active = true;
    public static $open = true;
    public static $label = '';
    public static $customs = array();
    public static $calls = array();
    public static $logs = array();
    public static $created = array();
    public static $crashAtFiles = false;
    public static $basePath = '/data/downloads/file';
    public static $files = array('file');
    public static $runFailure = null;
    public static $restoredStart = false;
    public static $root = null;

    public static function reset($active = true, $open = true, $label = '', $customs = array())
    {
        self::$active = $active;
        self::$open = $open;
        self::$label = $label;
        self::$customs = $customs;
        self::$calls = array();
        self::$logs = array();
        self::$created = array();
        self::$crashAtFiles = false;
        self::$basePath = '/data/downloads/file';
        self::$files = array('file');
        self::$runFailure = null;
        self::$restoredStart = false;
        if (self::$root !== null) strictRemoveTree(self::$root);
        self::$root = realpath(getenv('TMPDIR') ?: sys_get_temp_dir()) . '/setdir-guard-' . bin2hex(random_bytes(6));
        mkdir(self::$root, 0700);
        mkdir(self::$root . '/jobs', 0700);
        self::helper(null);
        rXMLRPCRequest::reset();
        $keys = RuTrackerAtomicOwnership::ownershipKeys();
        $commands = array('d.get_custom1');
        $values = array($label);
        foreach ($keys as $key) {
            $commands[] = 'd.get_custom';
            $values[] = isset($customs[$key]) ? $customs[$key] : '';
        }
        rXMLRPCRequest::queue($commands, true, false, $values);
    }

    // The helper probe refuses only $refused, the way an unsupported mount does.
    public static function helper($refused, $executable = true)
    {
        $path = self::$root . '/helper';
        file_put_contents($path, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg(self::$root . '/helper.log') . "\n"
            . ($refused === null ? '' : "if [ \"\$1\" = --probe ] && [ \"\$2\" = " . escapeshellarg($refused) . " ]; then\n"
                . "printf 'rename-noreplace-unsupported: errno=95 Operation not supported\\n' >&2\nexit 3\nfi\n")
            . "exit 0\n");
        chmod($path, $executable ? 0700 : 0600);
        DataDirScriptedFilesystem::$helper = $path;
    }

    public static function probes()
    {
        $log = @file(self::$root . '/helper.log', FILE_IGNORE_NEW_LINES);
        $probes = array();
        foreach ($log === false ? array() : $log as $line)
            if (strpos($line, '--probe ') === 0) $probes[] = substr($line, 8);
        return $probes;
    }
}

register_shutdown_function(function () {
    if (DataDirTestState::$root !== null) strictRemoveTree(DataDirTestState::$root);
});

class DataDirMoveIntent { const MAX_FILES = 100000; public static function trustedDirectory($path) { return true; } }
class DataDirMoveJob
{
    public static function create($journal, $data, $rpc, $move, $presence)
    {
        DataDirTestState::$created[] = $data;
        // A real job is durable before it runs; a failed run keeps it pending.
        file_put_contents($journal . '/' . $data['hash'] . '.json', '{}');
        return new self();
    }
    public function run()
    {
        if (DataDirTestState::$runFailure !== null)
            throw new RuntimeException(DataDirTestState::$runFailure);
        @unlink(DataDirTestState::$root . '/jobs/' . str_repeat('A', 40) . '.json');
        return true;
    }
    public function restoredStart() { return DataDirTestState::$restoredStart; }
}

function rtAddTailSlash($path) { return rtrim($path, '/') . '/'; }
function rtDataDirClaimRpc($method, $hash, $args)
{
    DataDirTestState::$calls[] = $method;
    return 'absent';
}
function rtDataDirClaimCapability()
{
    DataDirTestState::$calls[] = 'capability';
    return isset($GLOBALS['dataDirCapability']) ? $GLOBALS['dataDirCapability'] : 'available';
}
function rtDataDirJournal() { return DataDirTestState::$root . '/jobs'; }
function rtDataDirFilesystem()
{
    static $files = null;
    if ($files === null) $files = new DataDirScriptedFilesystem();
    return $files;
}
function rtExec($commands, $hash, $debug = false)
{
    DataDirTestState::$calls[] = $commands;
    if ($commands === array('d.is_open', 'd.local_id', 'd.directory'))
        return (object) array('val' => array((int) DataDirTestState::$open,
            '0123456789abcdef0123456789abcdef', '/data/downloads'));
    if ($commands === array('d.get_name', 'd.get_base_path', 'd.get_base_filename',
        'd.is_multi_file'))
        return (object) array('val' => array('file', DataDirTestState::$basePath, 'file', 0));
    if ($commands === array('d.open', 'd.get_name', 'd.get_base_path',
        'd.get_base_filename', 'd.is_multi_file', 'd.close')) {
        $values = array(0, 'file', DataDirTestState::$basePath, 'file', 0, 0);
        return (object) array('val' => $values);
    }
    if ($commands === 'f.multicall') {
        if (DataDirTestState::$crashAtFiles)
            throw new RuntimeException('injected worker crash');
        return (object) array('val' => DataDirTestState::$files);
    }
    throw new RuntimeException('unexpected command');
}

// The start check's bound is a courtesy to the daemon's hash check; shorten it.
define('DATADIR_START_CHECK_SECONDS', 0.3);

$source = getenv('DATADIR_TEST_SOURCE');
if (!$source) $source = testFindRepoRoot() . '/plugins/datadir/util_setdir.php';
foreach (array('rtDataDirOwnership', 'rtDataDirSnapshot', 'rtDataDirCollision', 'rtDataDirProbeRename',
    'rtSetDataDir', 'rtDataDirStartOutcome', 'rtDataDirHeld', 'rtDataDirPendingRefusal',
    'rtDataDirWorkerRefusal') as $name)
    eval(loadFunctionDefinition($source, $name));
eval(loadFunctionDefinition(testFindRepoRoot() . '/plugins/datadir/apply.php', 'rtDataDirClaimRefusal'));

$suite = new StrictTestSuite();
$hash = str_repeat('A', 40);

$suite->test('a prior document refuses before claiming and stays visibly recoverable', function () use ($hash) {
    DataDirTestState::reset();
    $path = DataDirTestState::$root . '/jobs/' . $hash . '.json';
    file_put_contents($path, '{}');
    rXMLRPCRequest::queue('d.get_directory', true, false, array('/original'));
    rXMLRPCRequest::queue(array('d.set_custom', 'd.message.set'), true, false, array(0, 0));
    rXMLRPCRequest::queue('d.save_full_session', true, false, array(0));
    strictAssertSame('prior-job-pending', rtDataDirPendingRefusal($hash), 'web admission refuses the existing document');
    strictAssertSame(false, rtSetDataDir($hash, DataDirTestState::$root, true, true, false, false, $failure),
        'the worker refuses the existing document under its lock');
    strictAssertSame('prior-job-pending', $failure, 'the owning document is the actual refusal');
    strictAssertSame(array(), DataDirTestState::$calls, 'no new claim or payload probe runs');
    strictAssertSame('{}', file_get_contents($path), 'unknown document bytes remain untouched');
    $writes = rXMLRPCRequest::requestsFor('d.set_custom|d.message.set');
    strictAssertSame(1, count($writes), 'held refusal is saved in the durable outcome and immediate message');
    $message = $writes[0]['commands'][1]->params[1];
    strictAssertTrue(strpos($message, 'document=' . rawurlencode($path)) !== false
        && strpos($message, 'key=' . $hash) !== false
        && strpos($message, 'claim-retained-start-and-move-blocked') !== false, 'the visible reason identifies recovery authority');
});

$suite->test('a detached worker cannot hide its refusal behind an existing document', function () use ($hash) {
    DataDirTestState::reset();
    $path = DataDirTestState::$root . '/jobs/' . $hash . '.json';
    file_put_contents($path, '{}');
    rXMLRPCRequest::queue('d.get_directory', true, false, array('/original'));
    rXMLRPCRequest::queue(array('d.set_custom', 'd.message.set'), true, false, array(0, 0));
    rXMLRPCRequest::queue('d.save_full_session', true, false, array(0));
    rtDataDirWorkerRefusal($hash, 'job-paths-ambiguous-unmoved-unproven');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.set_custom|d.message.set')),
        'a held worker publishes its outcome');
    strictAssertSame('{}', file_get_contents($path), 'visibility grants no journal mutation');
    strictAssertTrue(strpos(DataDirTestState::$logs[0], 'job-paths-ambiguous-unmoved-unproven') !== false,
        'the original hold reason remains visible');
});

$suite->test('a closed torrent preflight refuses an unsupported new destination ancestor without opening it', function () use ($hash) {
    DataDirTestState::reset(false, false);
    $ancestor = DataDirTestState::$root;
    $destination = $ancestor . '/datadir-new-closed';
    DataDirTestState::helper($ancestor);
    try { rtDataDirCollision($hash, $destination, false); throw new LogicException('unsupported destination was admitted'); }
    catch (RuntimeException $e) {
        strictAssertSame('rename-noreplace-unsupported path=' . erasedataLogPath($ancestor) . ' errno=95',
            $e->getMessage(), 'the filesystem refusal is classified with its path and errno');
    }
    strictAssertSame(array($ancestor), DataDirTestState::probes(), 'existing destination ancestor is probed');
    strictAssertSame(array(array('d.is_open', 'd.local_id', 'd.directory')), DataDirTestState::$calls,
        'closed torrent is never opened or claimed');
    strictAssertSame(false, file_exists($destination), 'preflight never creates the destination');
    strictAssertSame(array(), DataDirTestState::$logs, 'the shared preflight writes no erasedata line');
});

$suite->test('a closed torrent on a supported destination remains eligible for worker dispatch', function () use ($hash) {
    DataDirTestState::reset(false, false);
    $ancestor = DataDirTestState::$root;
    strictAssertSame('', rtDataDirCollision($hash, $ancestor, false), 'closed metadata does not invent a collision');
    strictAssertSame(array($ancestor), DataDirTestState::probes(), 'destination support is checked despite closed metadata');
    strictAssertSame(array(array('d.is_open', 'd.local_id', 'd.directory')), DataDirTestState::$calls,
        'read-only admission leaves torrent state untouched');
});

$suite->test('service label refuses before claim and records the reason once', function () use ($hash) {
    DataDirTestState::reset(true, true, '.chk-meta');
    strictAssertSame(false, rtSetDataDir($hash, DataDirTestState::$root, true, false, true, false, $failure),
        'service item stays held');
    strictAssertSame(array(), DataDirTestState::$calls, 'no claim or stop was attempted');
    strictAssertSame('active-checker-transaction-or-service-label', $failure, 'the caller receives the reason');
    strictAssertSame(array('datadir: change refused hash=' . $hash
        . ' reason=active-checker-transaction-or-service-label'), DataDirTestState::$logs,
        'one classified line per refusal');
});

$suite->test('predecessor and staged markers refuse before claim', function () use ($hash) {
    foreach (array('chk-meta-new', 'chk-replacing') as $key) {
        DataDirTestState::reset(true, true, '', array($key => str_repeat('B', 40)));
        strictAssertSame(false, rtSetDataDir($hash, DataDirTestState::$root, true, false, false),
            'active role is held');
        strictAssertSame(array(), DataDirTestState::$calls, 'no daemon action for ' . $key);
    }
});

$suite->test('unreadable ownership refuses before claim with a visible reason', function () use ($hash) {
    DataDirTestState::reset();
    rXMLRPCRequest::reset();
    strictAssertSame(false, rtSetDataDir($hash, DataDirTestState::$root, true, false, true),
        'unreadable preflight is held');
    strictAssertSame(array(), DataDirTestState::$calls, 'no claim after failed preflight');
    strictAssertTrue(strpos(implode(' ', DataDirTestState::$logs),
        'unreadable-checker-ownership') !== false, 'unknown ownership is visible');
});

$suite->test('the claim ABI answer maps to one refusal vocabulary', function () {
    $map = array();
    foreach (array('available', 'unsupported', 'unknown') as $capability) {
        $GLOBALS['dataDirCapability'] = $capability;
        $map[$capability] = rtDataDirClaimRefusal();
    }
    unset($GLOBALS['dataDirCapability']);
    strictAssertSame(array('available' => '', 'unsupported' => 'daemon-claim-unavailable',
        'unknown' => 'daemon-claim-unconfirmed'), $map, 'a missing method is never inferred from an unknown answer');
});

$suite->test('a directory change does not read the claim ABI again', function () use ($hash) {
    DataDirTestState::reset();
    strictAssertSame(true, rtSetDataDir($hash, DataDirTestState::$root, true, false, false), 'job handoff succeeds');
    strictAssertSame(false, in_array('capability', DataDirTestState::$calls, true),
        'the entry point read it once for the request');
});

$suite->test('ordinary no-move request records a job before any stop or directory setter', function () use ($hash) {
    DataDirTestState::reset();
    strictAssertSame(true, rtSetDataDir($hash, DataDirTestState::$root, true, false, true),
        'job handoff succeeds');
    strictAssertSame(1, count(DataDirTestState::$created), 'one job is recorded');
    strictAssertSame(false, DataDirTestState::$created[0]['move'], 'job is metadata-only');
    strictAssertSame('0123456789abcdef0123456789abcdef',
        DataDirTestState::$created[0]['local_id'], 'observed lifecycle is bound');
    strictAssertSame(true, DataDirTestState::$active, 'preclaim code did not stop torrent');
    strictAssertTrue(strpos(implode(' ', DataDirTestState::$logs),
        'fast resume disabled') !== false, 'legacy flag receives visible explanation');
});

$suite->test('a held change says held once and keeps its specific reason', function () use ($hash) {
    DataDirTestState::reset();
    DataDirTestState::$runFailure = 'daemon-done-unconfirmed';
    strictAssertSame(false, rtSetDataDir($hash, DataDirTestState::$root, true, false, false, false, $failure),
        'an unconfirmed finish is not a success');
    strictAssertSame('daemon-done-unconfirmed', $failure, 'the caller receives the specific reason');
    strictAssertSame(1, count(DataDirTestState::$logs), 'a held job has one precise log line');
    strictAssertTrue(strpos(DataDirTestState::$logs[0], 'reason=daemon-done-unconfirmed document=') !== false
        && strpos(DataDirTestState::$logs[0], 'key=' . $hash . ' consequence=claim-retained-start-and-move-blocked') !== false,
        'the refusal names its document, key and consequence');
    strictAssertSame(true, is_file(DataDirTestState::$root . '/jobs/' . $hash . '.json'), 'the job stays for recovery');
});

$suite->test('missing no-replace helper refuses before a native claim', function () use ($hash) {
    DataDirTestState::reset();
    DataDirTestState::helper(null, false);
    strictAssertSame(false, rtSetDataDir($hash, DataDirTestState::$root, true, true, false),
        'move cannot publish a job without its primitive');
    strictAssertSame(array(), DataDirTestState::$calls, 'no claim or stop is attempted');
    strictAssertSame(array(), DataDirTestState::$created, 'no job is published');
    strictAssertTrue(strpos(implode(' ', DataDirTestState::$logs),
        'rename-noreplace-helper-unavailable') !== false, 'missing helper is visible');
});

$suite->test('initially closed payload read returns to closed state before job', function () use ($hash) {
    DataDirTestState::reset(false, false);
    DataDirTestState::$crashAtFiles = true;
    strictAssertSame(false, rtSetDataDir($hash, DataDirTestState::$root, true, true, false),
        'failed projection does not start a job');
    strictAssertSame(false, DataDirTestState::$open, 'closed state is preserved');
    strictAssertSame(array(), DataDirTestState::$created, 'no partial job is published');
});

$suite->test('ordinary checker customs are retained in the job handoff', function () use ($hash) {
    DataDirTestState::reset(true, true, '', array('chk-state' => '3',
        'chk-forum-version' => '1234567890123456'));
    strictAssertSame(true, rtSetDataDir($hash, DataDirTestState::$root, false, false, false),
        'ordinary checker values permit job');
    strictAssertSame(1, count(DataDirTestState::$created), 'one job is created');
    strictAssertSame(false, DataDirTestState::$created[0]['add'],
        'directory-base branch remains selected');
});


$suite->test('directory-only change binds the daemon setter to a trusted physical path', function () use ($hash) {
    DataDirTestState::reset();
    $root = DataDirTestState::$root;
    // Owner-only modes keep the real payload trust rule independent of umask.
    mkdir($root . '/physical', 0700);
    symlink($root . '/physical', $root . '/alias');
    $snapshot = rtDataDirSnapshot($hash, $root . '/alias', false, false, false);
    strictAssertSame($root . '/physical/', $snapshot['directory'],
        'metadata-only setter receives the canonical destination');
});

$suite->test('physical move binds the daemon directory to the canonical destination', function () use ($hash) {
    DataDirTestState::reset();
    $root = DataDirTestState::$root;
    mkdir($root . '/from', 0700);
    mkdir($root . '/a', 0700);
    mkdir($root . '/b', 0700);
    file_put_contents($root . '/from/file', 'owned');
    symlink($root . '/a', $root . '/alias');
    DataDirTestState::$basePath = $root . '/from/file';
    $snapshot = rtDataDirSnapshot($hash, $root . '/alias/', false, true, false);
    strictAssertSame($root . '/a', rtrim($snapshot['directory'], '/'),
        'daemon setter must use the physical destination even if alias later changes');
    strictAssertSame($root . '/a', $snapshot['destination'],
        'payload and setter must name the same physical destination');
    unlink($root . '/alias');
    symlink($root . '/b', $root . '/alias');
    strictAssertSame($root . '/a', rtrim($snapshot['directory'], '/'),
        'later alias swap cannot redirect the daemon');
});

$suite->test('unsupported move mount refuses before a job or daemon stop, once and under datadir', function () use ($hash) {
    DataDirTestState::reset();
    $root = DataDirTestState::$root;
    mkdir($root . '/source', 0700); mkdir($root . '/destination', 0700);
    file_put_contents($root . '/source/file', 'source');
    DataDirTestState::$basePath = $root . '/source/file';
    DataDirTestState::helper($root . '/destination');
    strictAssertSame(false, rtSetDataDir($hash, $root . '/destination', false, true, false), 'Unsupported filesystem refuses');
    strictAssertSame(array(), DataDirTestState::$created, 'No move journal is published');
    strictAssertSame(true, DataDirTestState::$active, 'Preflight does not stop the daemon');
    strictAssertSame('source', file_get_contents($root . '/source/file'), 'Payload is unchanged');
    strictAssertSame(array('datadir: change refused hash=' . $hash . ' reason=rename-noreplace-unsupported path='
        . erasedataLogPath($root . '/destination') . ' errno=95'), DataDirTestState::$logs,
        'one datadir line carries the classified reason and errno');
    strictAssertSame(array($root . '/source', $root . '/destination'), DataDirTestState::probes(),
        'source and destination parents are each probed once');
});

$suite->test('a link inside the payload cannot point the move preflight outside its root', function () use ($hash) {
    DataDirTestState::reset();
    $root = DataDirTestState::$root;
    foreach (array('/source', '/outside', '/destination') as $directory) mkdir($root . $directory, 0700);
    symlink($root . '/outside', $root . '/source/link');
    file_put_contents($root . '/source/file', 'source');
    DataDirTestState::$basePath = $root . '/source/file';
    DataDirTestState::$files = array('file', 'link/escaped');
    strictAssertSame(false, rtSetDataDir($hash, $root . '/destination', false, true, false, false, $failure),
        'a payload parent outside its root refuses');
    strictAssertSame(0, strpos($failure, 'rename-noreplace-parent-outside-root path='
        . erasedataLogPath($root . '/source/link')), 'the outside parent is classified: ' . $failure);
    strictAssertSame(false, in_array($root . '/outside', DataDirTestState::probes(), true),
        'the helper never probes the outside directory');
    strictAssertSame(array(), DataDirTestState::$created, 'no job is published');
});

// R4-FS-02: a source parent must exist; only destination parents may be
// created later. The dialog preflight names the missing parent before any
// claim, job or directory is made.
$suite->test('a missing source subdirectory refuses the move preflight with its classified reason', function () use ($hash) {
    DataDirTestState::reset();
    $root = DataDirTestState::$root;
    foreach (array('/source', '/destination') as $directory) mkdir($root . $directory, 0700);
    file_put_contents($root . '/source/file', 'source');
    DataDirTestState::$basePath = $root . '/source/file';
    DataDirTestState::$files = array('file', 'sub/missing');
    try {
        rtDataDirCollision($hash, $root . '/destination', false);
        throw new LogicException('a missing source parent was admitted');
    } catch (RuntimeException $e) {
        strictAssertSame('rename-noreplace-source-parent-unavailable path=' . erasedataLogPath($root . '/source/sub'),
            $e->getMessage(), 'the dialog receives the classified reason');
    }
    strictAssertSame(false, in_array($root . '/source', DataDirTestState::probes(), true),
        'nothing is probed before the source is known to exist');
    strictAssertSame(array(), DataDirTestState::$created, 'no job is published');
    strictAssertSame(false, file_exists($root . '/destination/source'), 'no destination directory is made');
});

$suite->test('a restored start that rTorrent stops after its hash check is a visible warning', function () use ($hash) {
    DataDirTestState::reset();
    mkdir(DataDirTestState::$root . '/new', 0700);
    DataDirTestState::$restoredStart = true;
    rXMLRPCRequest::queue(array('d.hashing', 'd.state'), true, false, array('1', '1'));
    rXMLRPCRequest::queue(array('d.hashing', 'd.state'), true, false, array('0', '0'));
    rXMLRPCRequest::queue(getCmd('d.get_directory'), true, false, array(DataDirTestState::$root . '/new'));
    $write = getCmd('d.set_custom') . '|' . getCmd('d.message.set');
    rXMLRPCRequest::queue($write, true, false, array('0', '0'));
    rXMLRPCRequest::queue(getCmd('d.save_full_session'), true, false, array('0'));
    strictAssertSame(true, rtSetDataDir($hash, DataDirTestState::$root . '/new', true, false, false, false, $failure, $warning),
        'the directory change itself succeeded');
    strictAssertSame('stopped-after-hash-check', $warning, 'the caller receives the classified stop');
    $set = rXMLRPCRequest::requestsFor($write);
    strictAssertSame(1, count($set), 'the torrent message names the stop');
    list($custom, $message) = $set[0]['commands'];
    strictAssertSame($hash, $message->params[0], 'the message belongs to this torrent');
    strictAssertSame(0, strpos($message->params[1], 'datadir: stopped-after-hash-check directory='
        . DataDirTestState::$root . '/new; '), 'the message is classified: ' . $message->params[1]);
    // ACC-OWNB-01: the next tracker answer clears d.message; the outcome stays.
    $outcome = json_decode(rawurldecode($custom->params[2]), true);
    strictAssertSame(array($hash, 'x-datadir-outcome', 'stopped-after-hash-check', $message->params[1], true),
        array($custom->params[0], $custom->params[1], $outcome['reason'], $outcome['message'], $outcome['while_stopped']),
        'and is kept on the download while it stays stopped');
    strictAssertSame(array('datadir: change applied hash=' . $hash . ' warning=stopped-after-hash-check directory='
        . DataDirTestState::$root . '/new'), DataDirTestState::$logs, 'one log line records the stop');
});

$suite->test('a start that survives the check, or a check still running, is no warning', function () use ($hash) {
    foreach (array('started' => array(array('0', '1')), 'still hashing' => array_fill(0, 20, array('1', '1')),
        'unanswered' => array()) as $case => $answers) {
        DataDirTestState::reset();
        DataDirTestState::$restoredStart = true;
        foreach ($answers as $answer) rXMLRPCRequest::queue(array('d.hashing', 'd.state'), true, false, $answer);
        $started = microtime(true);
        strictAssertSame(true, rtSetDataDir($hash, DataDirTestState::$root, true, false, false, false, $failure, $warning),
            'the change succeeds: ' . $case);
        strictAssertSame('', $warning, 'no warning: ' . $case);
        strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.message.set'), 'no message: ' . $case);
        strictAssertTrue(microtime(true) - $started < 2, 'the check is bounded: ' . $case);
    }
    DataDirTestState::reset();
    strictAssertSame(true, rtSetDataDir($hash, DataDirTestState::$root, true, false, false, false, $failure, $warning),
        'a change without a restored start succeeds');
    strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.hashing|d.state'), 'it reads no run state');
});

exit($suite->run());
