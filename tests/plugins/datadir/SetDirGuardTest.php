<?php

require_once __DIR__ . '/../rutracker_check/TestLib.php';

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
    public static $claimCapability = 'available';

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
        self::$claimCapability = 'available';
        ErasedataFilesystemOps::$available = true;
        ErasedataFilesystemOps::$unsupported = null;
        ErasedataFilesystemOps::$probes = array();
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
}

class FileUtil
{
    public static function toLog($message) { DataDirTestState::$logs[] = $message; }
}
class ErasedataFilesystemOps
{
    public static $available = true;
    public static $unsupported = null;
    public static $probes = array();
    public static function canRenameNoReplace($directory = null) {
        if ($directory !== null) self::$probes[] = $directory;
        return self::$available && (self::$unsupported === null || $directory !== self::$unsupported);
    }
    public static function lastRenameRefusal() { return 'rename-noreplace-unsupported'; }
}
class DataDirMoveIntent { const MAX_FILES = 100000; public static function trustedDirectory($path) { return true; } }
class DataDirMoveJob
{
    public static function create($journal, $data, $rpc, $move)
    {
        DataDirTestState::$created[] = $data;
        return new self();
    }
    public function run() { return true; }
}

function rtAddTailSlash($path) { return rtrim($path, '/') . '/'; }
function rtDataDirClaimRpc($method, $hash, $args)
{
    DataDirTestState::$calls[] = $method;
    return 'absent';
}
function rtDataDirClaimCapability() { return DataDirTestState::$claimCapability; }
function rtDataDirJournal() { return sys_get_temp_dir(); }
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
        return (object) array('val' => array('file'));
    }
    throw new RuntimeException('unexpected command');
}

$source = getenv('DATADIR_TEST_SOURCE');
if (!$source) $source = testFindRepoRoot() . '/plugins/datadir/util_setdir.php';
foreach (array('rtDataDirOwnership', 'rtDataDirSnapshot', 'rtDataDirCollision', 'rtDataDirProbeRename', 'rtSetDataDir') as $name)
    eval(loadFunctionDefinition($source, $name));

$suite = new StrictTestSuite();
$hash = str_repeat('A', 40);

$suite->test('a closed torrent preflight refuses an unsupported new destination ancestor without opening it', function () use ($hash) {
    DataDirTestState::reset(false, false);
    $ancestor = realpath(sys_get_temp_dir());
    $destination = $ancestor . '/datadir-new-closed-' . bin2hex(random_bytes(8));
    ErasedataFilesystemOps::$unsupported = $ancestor;
    try { rtDataDirCollision($hash, $destination, false); throw new RuntimeException('unsupported destination was admitted'); }
    catch (RuntimeException $e) {
        strictAssertSame('rename-noreplace-unsupported: ' . $ancestor, $e->getMessage(), 'filesystem refusal is classified');
    }
    strictAssertSame(array($ancestor), ErasedataFilesystemOps::$probes, 'existing destination ancestor is probed');
    strictAssertSame(array(array('d.is_open', 'd.local_id', 'd.directory')), DataDirTestState::$calls,
        'closed torrent is never opened or claimed');
    strictAssertSame(false, file_exists($destination), 'preflight never creates the destination');
});

$suite->test('a closed torrent on a supported destination remains eligible for worker dispatch', function () use ($hash) {
    DataDirTestState::reset(false, false);
    $ancestor = realpath(sys_get_temp_dir());
    strictAssertSame('', rtDataDirCollision($hash, $ancestor, false), 'closed metadata does not invent a collision');
    strictAssertSame(array($ancestor), ErasedataFilesystemOps::$probes, 'destination support is checked despite closed metadata');
    strictAssertSame(array(array('d.is_open', 'd.local_id', 'd.directory')), DataDirTestState::$calls,
        'read-only admission leaves torrent state untouched');
});

$suite->test('service label refuses before claim and records the reason', function () use ($hash) {
    DataDirTestState::reset(true, true, '.chk-meta');
    strictAssertSame(false, rtSetDataDir($hash, sys_get_temp_dir(), true, false, true),
        'service item stays held');
    strictAssertSame(array(), DataDirTestState::$calls, 'no claim or stop was attempted');
    strictAssertTrue(strpos(implode(' ', DataDirTestState::$logs),
        'active-checker-transaction-or-service-label') !== false, 'reason is visible');
});

$suite->test('predecessor and staged markers refuse before claim', function () use ($hash) {
    foreach (array('chk-meta-new', 'chk-replacing') as $key) {
        DataDirTestState::reset(true, true, '', array($key => str_repeat('B', 40)));
        strictAssertSame(false, rtSetDataDir($hash, sys_get_temp_dir(), true, false, false),
            'active role is held');
        strictAssertSame(array(), DataDirTestState::$calls, 'no daemon action for ' . $key);
    }
});

$suite->test('unreadable ownership refuses before claim with a visible reason', function () use ($hash) {
    DataDirTestState::reset();
    rXMLRPCRequest::reset();
    strictAssertSame(false, rtSetDataDir($hash, sys_get_temp_dir(), true, false, true),
        'unreadable preflight is held');
    strictAssertSame(array(), DataDirTestState::$calls, 'no claim after failed preflight');
    strictAssertTrue(strpos(implode(' ', DataDirTestState::$logs),
        'unreadable-checker-ownership') !== false, 'unknown ownership is visible');
});

$suite->test('missing claim ABI refuses a direct worker request before job publication', function () use ($hash) {
    DataDirTestState::reset();
    DataDirTestState::$claimCapability = 'unsupported';
    strictAssertSame(false, rtSetDataDir($hash, sys_get_temp_dir(), true, true, false),
        'unsupported daemon cannot start a job');
    strictAssertSame(array(), DataDirTestState::$calls, 'no claim or daemon state read follows');
    strictAssertSame(array(), DataDirTestState::$created, 'no job is published');
    strictAssertTrue(strpos(implode(' ', DataDirTestState::$logs),
        'daemon-claim-unavailable') !== false, 'missing method is classified');
});

$suite->test('unknown claim capability is not diagnosed as missing in direct worker', function () use ($hash) {
    DataDirTestState::reset();
    DataDirTestState::$claimCapability = 'unknown';
    strictAssertSame(false, rtSetDataDir($hash, sys_get_temp_dir(), true, false, false),
        'unconfirmed capability cannot start a job');
    strictAssertSame(array(), DataDirTestState::$calls, 'no claim or daemon state read follows');
    strictAssertSame(array(), DataDirTestState::$created, 'no job is published');
    strictAssertTrue(strpos(implode(' ', DataDirTestState::$logs),
        'daemon-claim-unconfirmed') !== false, 'unknown RPC state is classified');
});

$suite->test('ordinary no-move request records a job before any stop or directory setter', function () use ($hash) {
    DataDirTestState::reset();
    strictAssertSame(true, rtSetDataDir($hash, sys_get_temp_dir(), true, false, true),
        'job handoff succeeds');
    strictAssertSame(1, count(DataDirTestState::$created), 'one job is recorded');
    strictAssertSame(false, DataDirTestState::$created[0]['move'], 'job is metadata-only');
    strictAssertSame('0123456789abcdef0123456789abcdef',
        DataDirTestState::$created[0]['local_id'], 'observed lifecycle is bound');
    strictAssertSame(true, DataDirTestState::$active, 'preclaim code did not stop torrent');
    strictAssertTrue(strpos(implode(' ', DataDirTestState::$logs),
        'fast resume disabled') !== false, 'legacy flag receives visible explanation');
});

$suite->test('missing no-replace helper refuses before a native claim', function () use ($hash) {
    DataDirTestState::reset();
    ErasedataFilesystemOps::$available = false;
    strictAssertSame(false, rtSetDataDir($hash, sys_get_temp_dir(), true, true, false),
        'move cannot publish a job without its primitive');
    strictAssertSame(array(), DataDirTestState::$calls, 'no claim or stop is attempted');
    strictAssertSame(array(), DataDirTestState::$created, 'no job is published');
    strictAssertTrue(strpos(implode(' ', DataDirTestState::$logs),
        'no-replace-helper-unavailable') !== false, 'missing helper is visible');
});

$suite->test('initially closed payload read returns to closed state before job', function () use ($hash) {
    DataDirTestState::reset(false, false);
    DataDirTestState::$crashAtFiles = true;
    strictAssertSame(false, rtSetDataDir($hash, sys_get_temp_dir(), true, true, false),
        'failed projection does not start a job');
    strictAssertSame(false, DataDirTestState::$open, 'closed state is preserved');
    strictAssertSame(array(), DataDirTestState::$created, 'no partial job is published');
});

$suite->test('ordinary checker customs are retained in the job handoff', function () use ($hash) {
    DataDirTestState::reset(true, true, '', array('chk-state' => '3',
        'chk-forum-version' => '1234567890123456'));
    strictAssertSame(true, rtSetDataDir($hash, sys_get_temp_dir(), false, false, false),
        'ordinary checker values permit job');
    strictAssertSame(1, count(DataDirTestState::$created), 'one job is created');
    strictAssertSame(false, DataDirTestState::$created[0]['add'],
        'directory-base branch remains selected');
});


$suite->test('directory-only change binds the daemon setter to a trusted physical path', function () use ($hash) {
    $root = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/datadir-no-move-' . bin2hex(random_bytes(6));
    mkdir($root);
    mkdir($root . '/physical');
    symlink($root . '/physical', $root . '/alias');
    try {
        DataDirTestState::reset();
        $snapshot = rtDataDirSnapshot($hash, $root . '/alias', false, false, false);
        strictAssertSame($root . '/physical/', $snapshot['directory'],
            'metadata-only setter receives the canonical destination');
    } finally {
        @unlink($root . '/alias');
        @rmdir($root . '/physical');
        @rmdir($root);
    }
});

$suite->test('physical move binds the daemon directory to the canonical destination', function () use ($hash) {
    $root = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/datadir-alias-' . bin2hex(random_bytes(6));
    mkdir($root);
    mkdir($root . '/from');
    mkdir($root . '/a');
    mkdir($root . '/b');
    file_put_contents($root . '/from/file', 'owned');
    symlink($root . '/a', $root . '/alias');
    try {
        DataDirTestState::reset();
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
    } finally {
        @unlink($root . '/alias');
        @unlink($root . '/from/file');
        @rmdir($root . '/from');
        @rmdir($root . '/a');
        @rmdir($root . '/b');
        @rmdir($root);
    }
});

$suite->test('unsupported move mount refuses before a job or daemon stop', function () use ($hash) {
    $root = sys_get_temp_dir().'/datadir-rename-probe-'.bin2hex(random_bytes(4));
    mkdir($root, 0700); mkdir($root.'/source', 0700); mkdir($root.'/destination', 0700);
    file_put_contents($root.'/source/file', 'source');
    try {
        DataDirTestState::reset();
        DataDirTestState::$basePath = $root.'/source/file';
        ErasedataFilesystemOps::$unsupported = $root.'/destination';
        strictAssertSame(false, rtSetDataDir($hash, $root.'/destination', false, true, false), 'Unsupported filesystem refuses');
        strictAssertSame(array(), DataDirTestState::$created, 'No move journal is published');
        strictAssertSame(true, DataDirTestState::$active, 'Preflight does not stop the daemon');
        strictAssertSame('source', file_get_contents($root.'/source/file'), 'Payload is unchanged');
        strictAssertTrue(strpos(implode(' ', DataDirTestState::$logs), 'rename-noreplace-unsupported') !== false, 'Filesystem reason is visible');
        strictAssertTrue(in_array($root.'/source', ErasedataFilesystemOps::$probes, true), 'Source mount is probed');
        strictAssertTrue(in_array($root.'/destination', ErasedataFilesystemOps::$probes, true), 'Destination mount is probed');
    } finally {
        unlink($root.'/source/file'); rmdir($root.'/source'); rmdir($root.'/destination'); rmdir($root);
    }
});

exit($suite->run());
