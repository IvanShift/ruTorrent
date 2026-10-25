<?php
require_once(__DIR__.'/../../../php/utility/json.php');
// Extracted classes omit the production bootstrap which supplies Utility.
require_once(__DIR__.'/../../../php/utility/utility.php');
require_once __DIR__ . '/../rutracker_check/TestLib.php';

class FileUtil
{
    public static $messages = array();
    public static function toLog($message) { self::$messages[] = $message; }
    public static function addslash($path) { return rtrim($path, '/') . '/'; }
}

require_once testFindRepoRoot() . '/plugins/erasedata/filesystem.php';
require_once testFindRepoRoot() . '/plugins/erasedata/outcome.php';
require_once testFindRepoRoot() . '/plugins/autotools/file_paths.php';

// The real shared runner, status table and parent preflight behind a scripted
// helper executable: only the helper path differs from production.
class AutoToolsScriptedRenameHelper extends ErasedataFilesystemOps
{
    public static $helper = null;
    public static function renameHelperPath() { return self::$helper; }
}

// The plugin configuration and checks the transaction reads around itself.
class rAutoTools
{
    public static $config = array();
    public static function load() { return (object) self::$config; }
    public static function claimAbiStatus() { return 'available'; }
    public static function destinationWithinRoot($root, $destination)
    {
        return strpos(rtrim($destination, '/') . '/', rtrim($root, '/') . '/') === 0;
    }
}

// The session save wait is a courtesy to the daemon's save schedule.
define('AUTOTOOLS_SESSION_SAVE_WAIT', 0.5);

eval(loadClassDefinition(testFindRepoRoot() . '/php/Torrent.php', 'Torrent'));
foreach (array('rtAddTailSlash', 'rtMkDir') as $function)
    eval(loadFunctionDefinition(testFindRepoRoot() . '/php/utility/rtpluginutil.php', $function));
eval(loadFunctionDefinition(testFindRepoRoot() . '/plugins/autotools/util_rt.php', 'rtGetRelativePath'));
eval(loadFunctionDefinition(testFindRepoRoot() . '/plugins/autotools/util_rt.php', 'rtAwaitSessionSave'));
eval(loadFunctionDefinition(testFindRepoRoot() . '/plugins/autotools/util_rt.php', 'rtFileJobRetryWindow'));
eval(loadClassDefinition(testFindRepoRoot() . '/plugins/autotools/move_job_lib.php', 'AutoToolsMoveJob'));

// A helper script that records its arguments and answers with fixed statuses.
function autoToolsHelper($root, $probeStatus, $renameStatus, $probeError = '', $renameError = '')
{
    $path = $root . '/helper';
    $say = function ($line) {
        return $line === '' ? '' : 'printf \'%s\\n\' ' . escapeshellarg($line) . " >&2\n";
    };
    file_put_contents($path, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($root . '/helper.log') . "\n"
        . "if [ \"\$1\" = --probe ]; then\n" . $say($probeError) . 'exit ' . (int) $probeStatus . "\nfi\n"
        . $say($renameError) . 'exit ' . (int) $renameStatus . "\n");
    chmod($path, 0700);
    @unlink($root . '/helper.log');
    AutoToolsScriptedRenameHelper::$helper = $path;
    strictSetPrivateStatic('AutoToolsMoveJob', 'files', new AutoToolsScriptedRenameHelper());
    return $path;
}

function autoToolsHelperCalls($root)
{
    $log = @file($root . '/helper.log', FILE_IGNORE_NEW_LINES);
    return $log === false ? array() : $log;
}

// A finished single-file download in its downloads root, with Move enabled.
function autoToolsMoveFixture()
{
    $root = realpath(getenv('TMPDIR') ?: sys_get_temp_dir()) . '/autotools-rename-' . bin2hex(random_bytes(6));
    foreach (array('', '/session', '/dl') as $directory)
        mkdir($root . $directory, 0700);
    file_put_contents($root . '/dl/payload.bin', 'payload bytes');
    file_put_contents($root . '/session/' . str_repeat('A', 40) . '.torrent',
        'd4:infod6:lengthi13e4:name11:payload.bin12:piece lengthi16384e6:pieces20:'
        . str_repeat("\x01", 20) . 'ee');
    rTorrentSettings::get()->session = $root . '/session';
    rTorrentSettings::get()->directory = $root . '/dl';
    rAutoTools::$config = array('enable_move' => 1, 'fileop_type' => 'Move',
        'automove_filter' => '/.*/', 'skip_move_for_files' => '', 'addLabel' => 0, 'addName' => 1,
        'path_to_finished' => $root . '/fin/new');
    FileUtil::$messages = array();
    return $root;
}

function autoToolsMoveArgs()
{
    $root = dirname(rTorrentSettings::get()->session);
    return array(str_repeat('A', 40), $root . '/dl/payload.bin', 'payload.bin', '0', '',
        'Named', '', str_repeat('c', 32));
}

function autoToolsPrepare($args = null)
{
    if ($args === null) $args = autoToolsMoveArgs();
    foreach (array('d.get_directory'=> $args[3] === '1' ? $args[1] : dirname($args[1]),
        'd.is_multi_file'=>$args[3], 'd.get_base_filename'=>$args[2], 'd.get_custom1'=>$args[4],
        'd.get_name'=>$args[5], 'd.complete'=>'1') as $method => $value)
        rXMLRPCRequest::queue($method, true, false, array($value));
    return strictInvoke('AutoToolsMoveJob', 'prepare', array($args[0], $args[7]));
}

function autoToolsCleanup($root)
{
    strictSetPrivateStatic('AutoToolsMoveJob', 'files', null);
    rXMLRPCRequest::reset();
    strictRemoveTree($root);
}

$suite = new StrictTestSuite();

$suite->test('Move preparation and its journal preserve raw names while byte filters still apply', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        $name = "payload\xFF.rar"; $label = "label\xFE"; $title = "title\xFD";
        rename($root . '/dl/payload.bin', $root . '/dl/' . $name);
        file_put_contents($root . '/session/' . str_repeat('A', 40) . '.torrent',
            'd4:infod6:lengthi13e4:name' . strlen($name) . ':' . $name . '12:piece lengthi16384e6:pieces20:'
            . str_repeat("\x01", 20) . 'ee');
        $args = array(str_repeat('A', 40), $root . '/dl/' . $name, $name, '0', rawurlencode($label), $title, '', str_repeat('c', 32));
        rAutoTools::$config['addLabel'] = 1;
        rAutoTools::$config['skip_move_for_files'] = '/\\.rar$/';
        try { autoToolsPrepare($args); throw new LogicException('raw archive skip filter was ignored'); }
        catch (RuntimeException $error) { strictAssertSame('skip-filter-match', $error->getMessage(), 'the exclusion matches the original byte basename'); }
        strictAssertSame(array(), autoToolsHelperCalls($root), 'a filtered download performs no filesystem probe');
        rAutoTools::$config['skip_move_for_files'] = '';
        $job = autoToolsPrepare($args);
        strictAssertSame($root . '/dl/' . $name, $job['src'], 'raw source bytes survive preparation');
        strictAssertSame($root . '/fin/new/' . $label . '/' . $title . '/' . $name, $job['dst'], 'raw label and title keep their exact destination components');
        strictAssertSame(array($name), $job['files'], 'the source manifest keeps the original filename');
        mkdir($root . '/jobs', 0700);
        AutoToolsMoveJob::writeJob($root . '/jobs', $job);
        strictAssertSame($job, AutoToolsMoveJob::readJob($root . '/jobs', $job['hash']), 'the exact prepared job survives durable write/read');
        strictAssertSame('payload bytes', file_get_contents($root . '/dl/' . $name), 'planning did not consume the payload');
    } finally { autoToolsCleanup($root); }
});


$suite->test('AutoTools accepts a usable shared rename probe including fallback support', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 5, 0);
        $job = autoToolsPrepare();
        strictAssertSame($root . '/fin/new/Named/payload.bin', $job['dst'],
            'a probe that needs the locked fallback admits the Move');
        strictAssertSame(array('--probe ' . $root . '/dl', '--probe ' . $root . '/fin/new/Named'),
            autoToolsHelperCalls($root), 'both actual parents pass the shared helper probe');
        strictAssertSame(array(), FileUtil::$messages, 'a usable filesystem writes no refusal');
    } finally { autoToolsCleanup($root); }
});

$suite->test('Move preparation creates its destination directories at most 0755, never group or other writable', function () {
    $root = autoToolsMoveFixture();
    $umask = umask(0);
    try {
        autoToolsHelper($root, 0, 0);
        autoToolsPrepare();
        foreach (array('/fin', '/fin/new', '/fin/new/Named') as $directory) {
            clearstatcache(true, $root . $directory);
            strictAssertSame(0755, fileperms($root . $directory) & 0777,
                $directory . ' is not group or other writable');
        }
    } finally { umask($umask); autoToolsCleanup($root); }
});

$suite->test('Move admits a Windows-like 0777 destination below the configured top', function () {
    $root = autoToolsMoveFixture();
    try {
        $GLOBALS['topDirectory'] = $root . '/';
        foreach (array('/fin', '/fin/new') as $directory) { mkdir($root . $directory); chmod($root . $directory, 0777); }
        chmod($root . '/dl', 0777);
        autoToolsHelper($root, 0, 0);
        $job = autoToolsPrepare();
        strictAssertSame($root . '/fin/new/Named/payload.bin', $job['dst'], 'the Move is admitted');
    } finally { unset($GLOBALS['topDirectory']); autoToolsCleanup($root); }
});

$suite->test('Move refuses a writable parent outside the configured top before any probe', function () {
    foreach (array('destination' => '/fin', 'source' => '/dl') as $side => $writable) {
        $root = autoToolsMoveFixture();
        try {
            $GLOBALS['topDirectory'] = $root . ($side === 'destination' ? '/dl/' : '/fin/');
            mkdir($root . '/fin');
            chmod($root . $writable, 0777);
            autoToolsHelper($root, 0, 0);
            try {
                autoToolsPrepare();
                throw new LogicException('a writable ' . $side . ' parent outside the top was admitted');
            } catch (RuntimeException $error) {
                strictAssertSame(0, strpos($error->getMessage(), 'untrusted-directory: '),
                    'the ' . $side . ' parent is refused: ' . $error->getMessage());
            }
            strictAssertSame(array(), autoToolsHelperCalls($root), 'no probe runs for an untrusted ' . $side);
        } finally { unset($GLOBALS['topDirectory']); autoToolsCleanup($root); }
    }
});

// R4-FS-03: the destination is checked as spelled, before anything is
// created through it, not only at the physical path a link leads to.
$suite->test('Move refuses a writable directory above the top in the spelled destination', function () {
    $root = autoToolsMoveFixture();
    try {
        $GLOBALS['topDirectory'] = $root . '/top/';
        foreach (array('/top', '/top/fin', '/shared') as $directory) mkdir($root . $directory, 0700);
        symlink($root . '/top/fin', $root . '/shared/fin');
        chmod($root . '/shared', 0777);
        rAutoTools::$config['path_to_finished'] = $root . '/shared/fin/new';
        autoToolsHelper($root, 0, 0);
        try {
            autoToolsPrepare();
            throw new LogicException('a writable spelled ancestor above the top was admitted');
        } catch (RuntimeException $error) {
            strictAssertSame('untrusted-directory: ' . erasedataLogPath($root . '/shared'), $error->getMessage(),
                'the spelled destination is refused');
        }
        strictAssertSame(false, file_exists($root . '/top/fin/new'), 'nothing is created through the link');
        strictAssertSame(array(), autoToolsHelperCalls($root), 'no probe runs');
    } finally { chmod($root . '/shared', 0700); unset($GLOBALS['topDirectory']); autoToolsCleanup($root); }
});

// The daemon's own spelling of the source is checked the same way.
$suite->test('Move refuses a writable directory above the top in the spelled source', function () {
    $root = autoToolsMoveFixture();
    try {
        $GLOBALS['topDirectory'] = $root . '/top/';
        foreach (array('/top', '/shared') as $directory) mkdir($root . $directory, 0700);
        symlink($root . '/dl', $root . '/shared/dl');
        chmod($root . '/shared', 0777);
        rTorrentSettings::get()->directory = $root . '/shared/dl';
        autoToolsHelper($root, 0, 0);
        $args = autoToolsMoveArgs();
        $args[1] = $root . '/shared/dl/payload.bin';
        rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(str_repeat('c', 32)));
        try {
            autoToolsPrepare($args);
            throw new LogicException('a writable spelled source ancestor above the top was admitted');
        } catch (RuntimeException $error) {
            strictAssertSame('untrusted-directory: ' . erasedataLogPath($root . '/shared'), $error->getMessage(),
                'the spelled source is refused though its physical path is trusted');
        }
        strictAssertSame(array(), autoToolsHelperCalls($root), 'no probe runs');
        strictAssertSame('payload bytes', file_get_contents($root . '/dl/payload.bin'), 'the payload stays');
    } finally { chmod($root . '/shared', 0700); unset($GLOBALS['topDirectory']); autoToolsCleanup($root); }
});

// R3-OWN-02: rTorrent 0.16 publishes a new download's session copy seconds
// after the add, so a small download can finish first.
$suite->test('a fast finish asks for the session save and waits for the copy', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        $copy = $root . '/session/' . str_repeat('A', 40) . '.torrent';
        $bytes = file_get_contents($copy);
        unlink($copy);
        rXMLRPCRequest::queue('d.save_full_session', true, false, function () use ($copy, $bytes) {
            file_put_contents($copy, $bytes);
            return array('0');
        });
        $job = autoToolsPrepare();
        strictAssertSame($root . '/fin/new/Named/payload.bin', $job['dst'], 'the Move proceeds once the copy exists');
        $save = rXMLRPCRequest::requestsFor('d.save_full_session');
        strictAssertSame(1, count($save), 'one session save is requested');
        strictAssertSame(array(str_repeat('A', 40)), $save[0]['commands'][0]->params, 'for this download');
    } finally { autoToolsCleanup($root); }
});

$suite->test('a session copy that never arrives refuses after the bounded wait', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        unlink($root . '/session/' . str_repeat('A', 40) . '.torrent');
        rXMLRPCRequest::queue('d.save_full_session', true, false, array('0'));
        $started = microtime(true);
        try {
            autoToolsPrepare();
            throw new LogicException('a missing session copy was accepted');
        } catch (RuntimeException $error) {
            strictAssertSame('session-copy-unreadable', $error->getMessage(), 'the refusal stays classified');
        }
        $elapsed = microtime(true) - $started;
        strictAssertTrue($elapsed >= 0.4 && $elapsed < 3, 'the wait is bounded: ' . round($elapsed, 2));
        strictAssertSame(array(), autoToolsHelperCalls($root), 'nothing is probed or moved');
    } finally { autoToolsCleanup($root); }
});

// R3-OWN-02, ACC-OWNB-02: a copy that has not arrived defers the Move to
// the recovery pass; only a copy still missing after the retry window is a
// refusal, named on the torrent where tracker traffic cannot erase it.
$suite->test('AutoTools publication uses the shared no-replace operation', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        strictAssertSame(true, strictInvoke('AutoToolsMoveJob', 'renameNoReplace',
            array($root . '/source', $root . '/destination')), 'shared publication succeeds');
        strictAssertSame(array($root . '/source ' . $root . '/destination'), autoToolsHelperCalls($root),
            'AutoTools has no separate native-helper process contract');
    } finally { autoToolsCleanup($root); }
});

exit($suite->run());
