<?php

require_once(__DIR__ . '/../rutracker_check/TestLib.php');

class DataDirTestState
{
    public static $active = true;
    public static $open = true;
    public static $label = '';
    public static $customs = array();
    public static $calls = array();
    public static $logs = array();
    public static $reloads = 0;
    public static $crashAtFiles = false;
    public static $source;

    public static function reset($active = true, $open = true, $label = '', $customs = array())
    {
        self::$active = $active;
        self::$open = $open;
        self::$label = $label;
        self::$customs = $customs;
        self::$calls = array();
        self::$logs = array();
        self::$reloads = 0;
        self::$crashAtFiles = false;
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
class Torrent
{
    public function __construct($source) { DataDirTestState::$source = $source; }
    public function errors() { return false; }
}
class rTorrent
{
    public static function sendTorrent($torrent, $isStart, $addPath, $dir, $label,
        $saveTorrent, $isFast, $isNew, $addition)
    {
        DataDirTestState::$reloads++;
        return str_repeat('A', 40);
    }
}
function rtDbg($name, $message) { }
function rtAddTailSlash($path) { return rtrim($path, '/') . '/'; }
function rtRemoveTailSlash($path) { return rtrim($path, '/'); }
function rtRemoveLastToken($path, $separator) { return dirname($path); }
function rtExec($commands, $hash, $debug = false)
{
    DataDirTestState::$calls[] = $commands;
    if ($commands === array('d.is_open', 'd.is_active'))
        return (object) array('val' => array((int) DataDirTestState::$open,
            (int) DataDirTestState::$active));
    if ($commands === array('d.get_name', 'd.get_base_path', 'd.get_base_filename',
        'd.is_multi_file'))
        return (object) array('val' => array('file', '/data/downloads/file', 'file', 0));
    if ($commands === array('d.open', 'd.get_name', 'd.get_base_path',
        'd.get_base_filename', 'd.is_multi_file', 'd.close')) {
        DataDirTestState::$open = true;
        $values = array(0, 'file', '/data/downloads/file', 'file', 0, 0);
        DataDirTestState::$open = false;
        return (object) array('val' => $values);
    }
    if ($commands === array('d.get_name', 'd.get_base_path', 'd.get_base_filename',
        'd.is_multi_file', 'd.get_complete'))
        return (object) array('val' => array('file', '/data/downloads/file', 'file', 0, 1));
    if ($commands === 'f.multicall') {
        if (DataDirTestState::$crashAtFiles) throw new RuntimeException('injected worker crash');
        return (object) array('val' => array(array('file')));
    }
    if ($commands === array('get_session', 'd.get_tied_to_file', 'd.get_custom1',
        'd.get_connection_seed', 'd.get_throttle_name'))
        return (object) array('val' => array('', DataDirTestState::$source,
            DataDirTestState::$label, '', ''));
    if ($commands === array('d.stop', 'd.close')) {
        DataDirTestState::$active = false;
        DataDirTestState::$open = false;
    } elseif ($commands === 'd.close') DataDirTestState::$open = false;
    elseif ($commands === array('d.open', 'd.start')) {
        DataDirTestState::$open = true;
        DataDirTestState::$active = true;
    } elseif ($commands === 'd.open') DataDirTestState::$open = true;
    return true;
}

$source = getenv('DATADIR_TEST_SOURCE');
if (!$source) $source = testFindRepoRoot() . '/plugins/datadir/util_setdir.php';
eval(loadFunctionDefinition($source, 'rtSetDataDir'));
$scratch = sys_get_temp_dir();
if (!is_dir($scratch)) mkdir($scratch, 0700, true);
DataDirTestState::$source = tempnam($scratch, 'datadir-test-');

$suite = new StrictTestSuite();
$hash = str_repeat('A', 40);

$suite->test('service label refuses DataDir before stop or move and records the reason', function () use ($hash) {
    DataDirTestState::reset(true, true, '.chk-meta');
    strictAssertSame(false, rtSetDataDir($hash, '/data/downloads', true, true, true), 'service item stays held');
    strictAssertSame(array(), DataDirTestState::$calls, 'no stop or move was attempted');
    strictAssertTrue(strpos(implode(' ', DataDirTestState::$logs), 'active checker transaction or service label') !== false,
        'refusal is recorded outside debug mode');
});

$suite->test('predecessor and staged markers refuse before stop even without fast resume', function () use ($hash) {
    foreach (array('chk-meta-new', 'chk-replacing') as $key) {
        DataDirTestState::reset(true, true, '', array($key => str_repeat('B', 40)));
        strictAssertSame(false, rtSetDataDir($hash, '/data/downloads', true, false, false), 'active role is held');
        strictAssertSame(array(), DataDirTestState::$calls, 'no stop for ' . $key);
    }
});

$suite->test('unreadable ownership refuses before stop and records the reason', function () use ($hash) {
    DataDirTestState::reset();
    rXMLRPCRequest::reset();
    strictAssertSame(false, rtSetDataDir($hash, '/data/downloads', true, false, true),
        'unreadable preflight is held');
    strictAssertSame(array(), DataDirTestState::$calls, 'no stop after failed preflight');
    strictAssertTrue(strpos(implode(' ', DataDirTestState::$logs), 'unreadable checker ownership') !== false,
        'unknown ownership is visible without debug mode');
});

$suite->test('revived-only and ordinary checker customs keep the in-place branch', function () use ($hash) {
    foreach (array(array('chk-revived' => '1234567890'),
        array('chk-state' => '3', 'chk-forum-version' => '1234567890123456')) as $customs) {
        DataDirTestState::reset(true, true, '', $customs);
        strictAssertSame(true, rtSetDataDir($hash, '/data/downloads', true, false, true),
            'checker state changes directory in place');
        strictAssertSame(0, DataDirTestState::$reloads, 'no erase/reload');
        strictAssertSame(true, DataDirTestState::$active, 'previous active state restored');
        strictAssertSame(array(), rXMLRPCRequest::requestsFor('branch'), 'no erase branch');
        strictAssertTrue(strpos(implode(' ', DataDirTestState::$logs), 'fast resume disabled') !== false,
            'fallback reason is recorded');
    }
});

$suite->test('physical-move request uses the in-place branch rather than erase', function () use ($hash) {
    DataDirTestState::reset();
    strictAssertSame(true, rtSetDataDir($hash, '/data/downloads', true, true, true),
        'moving files selects the existing in-place branch');
    strictAssertSame(0, DataDirTestState::$reloads, 'moving files never erases the download');
    strictAssertSame(array(), rXMLRPCRequest::requestsFor('branch'), 'no atomic erase on move');
    strictAssertTrue(strpos(implode(' ', DataDirTestState::$logs), 'fast resume disabled: changing directory in place') !== false,
        'fallback reason is recorded');
});

$suite->test('stopped torrent keeps the in-place branch and prior open state', function () use ($hash) {
    foreach (array(true, false) as $open) {
        DataDirTestState::reset(false, $open);
        strictAssertSame(true, rtSetDataDir($hash, '/data/downloads', true, false, true),
            'stopped item is changed in place');
        strictAssertSame(false, DataDirTestState::$active, 'stopped remains stopped');
        strictAssertSame($open, DataDirTestState::$open, 'open state is preserved');
        strictAssertSame(0, DataDirTestState::$reloads, 'stopped item was not erased');
    }
});

$suite->test('ordinary active request stays in place with no erase or reload', function () use ($hash) {
    DataDirTestState::reset();
    strictAssertSame(true, rtSetDataDir($hash, '/data/downloads', true, false, true),
        'ordinary directory change succeeds');
    strictAssertSame(true, DataDirTestState::$active, 'prior active state is restored');
    strictAssertSame(true, DataDirTestState::$open, 'prior open state is restored');
    strictAssertSame(0, DataDirTestState::$reloads, 'no new daemon generation is loaded');
    strictAssertSame(array(), rXMLRPCRequest::requestsFor('branch'), 'no destructive branch');
    strictAssertTrue(strpos(implode(' ', DataDirTestState::$logs), 'fast resume disabled') !== false,
        'legacy fast request receives a visible fallback reason');
});

$suite->test('closed no-move item does not reopen after changing directory', function () use ($hash) {
    DataDirTestState::reset(false, false);
    strictAssertSame(true, rtSetDataDir($hash, '/data/downloads/new-location', false, false, false),
        'closed directory setter succeeds');
    strictAssertSame(false, DataDirTestState::$open, 'closed state is preserved');
    strictAssertTrue(!in_array('d.open', DataDirTestState::$calls, true)
        && !in_array(array('d.open', 'd.close'), DataDirTestState::$calls, true),
        'worker must not reopen a closed item after set_directory');
});

$suite->test('closed item stays closed when PHP dies before file projection', function () use ($hash) {
    DataDirTestState::reset(false, false);
    DataDirTestState::$crashAtFiles = true;
    try {
        rtSetDataDir($hash, '/data/downloads', true, true, false);
        throw new RuntimeException('expected injected crash');
    } catch (RuntimeException $e) {
        strictAssertSame('injected worker crash', $e->getMessage(), 'injection reached file projection');
    }
    strictAssertSame(false, DataDirTestState::$open, 'closed item must remain closed after crash');
});

$status = $suite->run();
@unlink(DataDirTestState::$source);
exit($status);
