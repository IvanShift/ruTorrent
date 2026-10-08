<?php
require_once __DIR__ . '/../rutracker_check/TestLib.php';

class FileUtil
{
    public static $messages = array();
    public static function toLog($message) { self::$messages[] = $message; }
    public static function addslash($path) { return rtrim($path, '/') . '/'; }
}

require_once testFindRepoRoot() . '/plugins/erasedata/filesystem.php';
require_once testFindRepoRoot() . '/plugins/erasedata/outcome.php';

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
eval(loadClassDefinition(testFindRepoRoot() . '/plugins/autotools/move_tx_lib.php', 'AutoToolsMoveTransaction'));

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
    strictSetPrivateStatic('AutoToolsMoveTransaction', 'files', new AutoToolsScriptedRenameHelper());
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

function autoToolsPrepare()
{
    rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(str_repeat('c', 32)));
    return strictInvoke('AutoToolsMoveTransaction', 'prepare', array(autoToolsMoveArgs()));
}

function autoToolsRequests($root)
{
    return glob($root . '/session/.autotools-file-jobs/*.move-request.json') ?: array();
}

// The daemon side of a recorded outcome (plugins/erasedata/outcome.php).
function autoToolsQueueOutcome($root)
{
    rXMLRPCRequest::queue(getCmd('d.get_directory'), true, false, array($root . '/dl'));
    rXMLRPCRequest::queue(getCmd('d.set_custom') . '|' . getCmd('d.message.set'), true, false, array('0', '0'));
    rXMLRPCRequest::queue(getCmd('d.save_full_session'), true, false, array('0'));
}

// The outcome kept on the download, decoded, and the message shown with it.
function autoToolsRecordedOutcome()
{
    $set = rXMLRPCRequest::requestsFor(getCmd('d.set_custom') . '|' . getCmd('d.message.set'));
    if (count($set) !== 1) return null;
    list($custom, $message) = $set[0]['commands'];
    return array('key' => $custom->params[1], 'outcome' => json_decode(rawurldecode($custom->params[2]), true),
        'message' => $message->params[1], 'hash' => $message->params[0]);
}

// rTorrent's answer while retrackers erases and re-adds the download.
function autoToolsQueueAbsent()
{
    rXMLRPCRequest::queue(getCmd('d.get_custom'), true, true, array('-500'), 'invalid parameters: info-hash not found');
    rXMLRPCRequest::queue(getCmd('d.hash'), true, true, array('-500'));
}

function autoToolsCleanup($root)
{
    strictSetPrivateStatic('AutoToolsMoveTransaction', 'files', null);
    rXMLRPCRequest::reset();
    strictRemoveTree($root);
}

$suite = new StrictTestSuite();

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

$suite->test('a refused parent probe is logged once, with the job hash and its classified errno', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 4, 0, 'rename-noreplace-error: errno=13 Permission denied');
        rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(str_repeat('c', 32)));
        autoToolsQueueOutcome($root);
        AutoToolsMoveTransaction::run(autoToolsMoveArgs());
        strictAssertSame(array('autotools: move ' . str_repeat('A', 40) . ' refused: rename-noreplace-error path='
            . erasedataLogPath($root . '/dl') . ' errno=13; mutation refused; check the native helper and'
            . ' the named parent permissions; torrent and payload retained'), FileUtil::$messages,
            'one classified line names the job and the native errno');
        strictAssertSame(array(), glob($root . '/session/.autotools-file-jobs/*.move.json'),
            'a refused probe publishes no Move journal');
        strictAssertSame(array(), autoToolsRequests($root), 'and keeps no request: the refusal is final');
        strictAssertSame('payload bytes', file_get_contents($root . '/dl/payload.bin'), 'the payload stays');
    } finally { autoToolsCleanup($root); }
});

$suite->test('a refused publication keeps its classified collision in the one abort line', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 1, '', 'rename-noreplace-collision: errno=17 File exists');
        $job = autoToolsPrepare();
        $hash = str_repeat('A', 40);
        $token = str_repeat('d', 32) . ':' . str_repeat('0', 15) . '1';
        $journal = $root . '/session/.autotools-file-jobs';
        mkdir($journal, 0700);
        $job['phase'] = 'claimed';
        $job['token'] = $token;
        file_put_contents($journal . '/' . $hash . '.move.json', json_encode($job, JSON_UNESCAPED_SLASHES));
        $receipt = $token . '|1|1|1|' . $hash;
        rXMLRPCRequest::queue('d.stop_close_claim_state', true, false, array('active|' . $receipt));
        rXMLRPCRequest::queue('d.stop_close_claim', true, false, array($receipt));
        rXMLRPCRequest::queue('d.stop_close_claim_state', true, false, array('active|' . $receipt));
        rXMLRPCRequest::queue('d.start_if_stop_close_claim', true, false, array('1'));
        rXMLRPCRequest::queue('d.stop_close_claim_state', true, false, array('done|S|' . $receipt));
        rXMLRPCRequest::queue('d.ack_stop_close_claim', true, false, array('1'));
        autoToolsQueueOutcome($root);
        FileUtil::$messages = array();
        strictAssertSame(false, strictInvoke('AutoToolsMoveTransaction', 'reconcile', array($journal, $job)),
            'a refused publication aborts the Move');
        strictAssertSame(array('autotools: move ' . $hash . ' refused: publication refused: '
            . 'rename-noreplace-collision path=' . erasedataLogPath($root . '/dl/payload.bin')
            . ' destination=' . erasedataLogPath($root . '/fin/new/Named/payload.bin')
            . ' errno=17; mutation refused; preserve both entries; inspect the occupied destination'
            . ' before retrying; physical move did not publish; original run intent reconciled'),
            FileUtil::$messages, 'the collision, not a generic failure, is visible once');
        strictAssertSame('payload bytes', file_get_contents($root . '/dl/payload.bin'), 'the payload stays at source');
        strictAssertSame(false, file_exists($root . '/fin/new/Named/payload.bin'), 'nothing is published');
        strictAssertSame(array(), glob($journal . '/*.move.json'), 'the reconciled abort retires its journal');
        $recorded = autoToolsRecordedOutcome();
        strictAssertSame(0, strpos($recorded['message'], 'autotools: move refused: publication refused: rename-noreplace-collision'),
            'the abort is kept on the download (ACC-OWNB-01): ' . $recorded['message']);
    } finally { autoToolsCleanup($root); }
});

// R3-OWN-01: the payload rule shared with datadir guards both Move parents.
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
            strictAssertSame('untrusted-directory: ' . $root . '/shared/fin/new/Named', $error->getMessage(),
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
            strictInvoke('AutoToolsMoveTransaction', 'prepare', array($args));
            throw new LogicException('a writable spelled source ancestor above the top was admitted');
        } catch (RuntimeException $error) {
            strictAssertSame('untrusted-directory: ' . $root . '/shared/dl', $error->getMessage(),
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
$suite->test('a session copy that never arrives defers the Move, then refuses it on the torrent', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        unlink($root . '/session/' . str_repeat('A', 40) . '.torrent');
        rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(str_repeat('c', 32)));
        rXMLRPCRequest::queue('d.save_full_session', true, false, array('0'));
        rXMLRPCRequest::queue(getCmd('d.message.set'), true, false, array('0'));
        AutoToolsMoveTransaction::run(autoToolsMoveArgs());
        strictAssertSame(array('autotools: move ' . str_repeat('A', 40) . ' deferred: session-copy-unreadable;'
            . ' recover.php plans the Move again every 60 s'), FileUtil::$messages, 'the deferral is said once');
        $hint = rXMLRPCRequest::requestsFor(getCmd('d.message.set'));
        strictAssertSame(1, count($hint), 'the torrent shows that the move waits');
        strictAssertSame(0, strpos($hint[0]['commands'][0]->params[1], 'autotools: move waiting: session-copy-unreadable;'),
            'with its reason: ' . $hint[0]['commands'][0]->params[1]);
        $requests = autoToolsRequests($root);
        strictAssertSame(1, count($requests), 'the hook is kept as a request');
        $request = json_decode(file_get_contents($requests[0]), true);
        strictAssertSame(array(1, 'session-copy-unreadable', autoToolsMoveArgs()),
            array($request['attempts'], $request['reason'], $request['args']), 'with its arguments');
        strictAssertSame(array(), glob($root . '/session/.autotools-file-jobs/*.move.json'), 'no job is journaled');
        // A pass inside the window plans again and keeps it, silently.
        FileUtil::$messages = array();
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(str_repeat('c', 32)));
        rXMLRPCRequest::queue('d.save_full_session', true, false, array('0'));
        AutoToolsMoveTransaction::recoverAll();
        strictAssertSame(array(), FileUtil::$messages, 'a repeated reason is not logged again');
        strictAssertSame(2, json_decode(file_get_contents($requests[0]), true)['attempts'], 'the pass is counted');
        // Past the window the same state is a refusal.
        $request = json_decode(file_get_contents($requests[0]), true);
        $request['first_at'] = time() - 1801;
        file_put_contents($requests[0], json_encode($request, JSON_UNESCAPED_SLASHES));
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(str_repeat('c', 32)));
        rXMLRPCRequest::queue('d.save_full_session', true, false, array('0'));
        autoToolsQueueOutcome($root);
        AutoToolsMoveTransaction::recoverAll();
        strictAssertSame(array('autotools: move ' . str_repeat('A', 40) . ' refused: session-copy-unreadable'
            . ' unresolved for 30 min'), FileUtil::$messages, 'the refusal is logged once with its job hash');
        $recorded = autoToolsRecordedOutcome();
        strictAssertSame(array(str_repeat('A', 40), 'x-autotools-outcome', 'session-copy-unreadable', $root . '/dl'),
            array($recorded['hash'], $recorded['key'], $recorded['outcome']['reason'], $recorded['outcome']['directory']),
            'the refusal is kept on the download (ACC-OWNB-01)');
        strictAssertTrue(strpos($recorded['message'], 'autotools: move refused: session-copy-unreadable unresolved for 30 min;') === 0
            && strpos($recorded['message'], 'data stays in place') !== false && strpos($recorded['message'], 'Set directory') !== false,
            'the message is classified and names what to do: ' . $recorded['message']);
        strictAssertSame($recorded['message'], $recorded['outcome']['message'], 'one text in both places');
        strictAssertSame(array(), autoToolsRequests($root), 'the request retires');
        strictAssertSame(array(), glob($root . '/session/.autotools-file-jobs/*.move.json'), 'no job is journaled');
        strictAssertSame(array(), autoToolsHelperCalls($root), 'nothing is probed or moved');
    } finally { autoToolsCleanup($root); }
});

// ACC-OWNB-02: retrackers re-adds a public download (erase, then load) right
// when a small one finishes, so the worker's first RPC meets
// "info-hash not found". The retrackers claim tells that from a removal.
$suite->test('a download retrackers is re-adding is planned again by the recovery pass, not refused', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        autoToolsQueueAbsent();
        rXMLRPCRequest::queue('system.retrackers.original_claim', true, false, array('held'));
        AutoToolsMoveTransaction::run(autoToolsMoveArgs());
        strictAssertSame(array('autotools: move ' . str_repeat('A', 40) . ' deferred: download-reloading;'
            . ' recover.php plans the Move again every 60 s'), FileUtil::$messages, 'the deferral is visible');
        $claim = rXMLRPCRequest::requestsFor('system.retrackers.original_claim');
        strictAssertSame(array('', str_repeat('A', 40)), $claim[0]['commands'][0]->params, 'the claim of this download is read');
        strictAssertSame(1, count(autoToolsRequests($root)), 'the hook is kept as a request');
        strictAssertSame(null, autoToolsRecordedOutcome(), 'nothing is refused');
        // The download is back: the next pass plans the Move and hands it to its job.
        FileUtil::$messages = array();
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(str_repeat('c', 32)));
        AutoToolsMoveTransaction::recoverAll();
        strictAssertSame(array(), autoToolsRequests($root), 'the request is spent');
        $jobs = glob($root . '/session/.autotools-file-jobs/*.move.json');
        strictAssertSame(1, count($jobs), 'the Move is journaled');
        strictAssertSame(array('intent', $root . '/fin/new/Named/payload.bin'),
            array(json_decode(file_get_contents($jobs[0]), true)['phase'], json_decode(file_get_contents($jobs[0]), true)['dst']),
            'as the job the worker would have planned');
        strictAssertSame(array('autotools: move ' . str_repeat('A', 40) . ' recovery hold: rpc-d.stop_close_claim_state-unconfirmed'),
            FileUtil::$messages, 'its first daemon step is the claim, retried by the job as before');
    } finally { autoToolsCleanup($root); }
});

$suite->test('a download that is gone is refused once in the log, without a claim', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        autoToolsQueueAbsent();
        rXMLRPCRequest::queue('system.retrackers.original_claim', true, false, array('none'));
        rXMLRPCRequest::queue(getCmd('d.hash'), true, true, array('-500'));
        AutoToolsMoveTransaction::run(autoToolsMoveArgs());
        strictAssertSame(array('autotools: move ' . str_repeat('A', 40) . ' refused: download-absent'),
            FileUtil::$messages, 'the refusal is final');
        strictAssertSame(array(), autoToolsRequests($root), 'and keeps no request');
        strictAssertSame(array(), rXMLRPCRequest::requestsFor(getCmd('d.get_directory')), 'nothing is shown on a gone download');
    } finally { autoToolsCleanup($root); }
});

$suite->test('an absent download whose claim cannot be read is retried within the window', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        autoToolsQueueAbsent();
        AutoToolsMoveTransaction::run(autoToolsMoveArgs());
        strictAssertSame(array('autotools: move ' . str_repeat('A', 40) . ' deferred: download-absent-unconfirmed;'
            . ' recover.php plans the Move again every 60 s'), FileUtil::$messages, 'a daemon without the claim answer defers');
        strictAssertSame(1, count(autoToolsRequests($root)), 'the hook is kept as a request');
    } finally { autoToolsCleanup($root); }
});

// ACC-OWNB-02, second part: the claim of a planned Move meets a download
// that is not open and active while retrackers re-adds it (3 of 40 public
// Moves on 9p were refused for good there). Such a job is kept and claimed
// again; a download its user stopped is refused at once, visibly.
function autoToolsJournaledJob($root)
{
    $job = autoToolsPrepare();
    $journal = $root . '/session/.autotools-file-jobs';
    if (!is_dir($journal)) mkdir($journal, 0700);
    file_put_contents($journal . '/' . str_repeat('A', 40) . '.move.json', json_encode($job, JSON_UNESCAPED_SLASHES));
    return array($journal, $job);
}

// The claim is due but skipped: the download is not open and active.
function autoToolsQueueSkippedClaim($basePath = '')
{
    rXMLRPCRequest::queue('d.stop_close_claim_state', true, false, array('absent'));
    rXMLRPCRequest::queue('branch', true, false, array('SKIP'));
    rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(str_repeat('c', 32)));
    rXMLRPCRequest::queue('d.get_base_path', true, false, array($basePath));
    rXMLRPCRequest::queue('d.is_active', true, false, array('0'));
}

$suite->test('a Move whose download retrackers is re-adding is kept and claimed once it runs', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        list($journal, $job) = autoToolsJournaledJob($root);
        $path = $journal . '/' . str_repeat('A', 40) . '.move.json';
        autoToolsQueueSkippedClaim();
        rXMLRPCRequest::queue('system.retrackers.original_claim', true, false, array('held'));
        FileUtil::$messages = array();
        strictAssertSame(false, strictInvoke('AutoToolsMoveTransaction', 'reconcile', array($journal, $job)),
            'the claim waits');
        strictAssertSame(array('autotools: move ' . str_repeat('A', 40) . ' deferred: download-not-running'
            . ' (retrackers-claim); recover.php claims it again every 60 s'), FileUtil::$messages, 'said once');
        $kept = json_decode(file_get_contents($path), true);
        strictAssertTrue(is_int($kept['waiting_since']) && $kept['phase'] === 'intent', 'the job is kept');
        strictAssertSame(null, autoToolsRecordedOutcome(), 'nothing is refused');
        // A pass inside the window while the download still does not run: silent.
        FileUtil::$messages = array();
        rXMLRPCRequest::reset();
        autoToolsQueueSkippedClaim();
        rXMLRPCRequest::queue('system.retrackers.original_claim', true, false, array('none'));
        rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array('rq1:queued'));
        strictInvoke('AutoToolsMoveTransaction', 'reconcile', array($journal, $kept));
        strictAssertSame(array(), FileUtil::$messages, 'a later pass in the window is silent');
        strictAssertTrue(is_file($path), 'and keeps the job (retrackers-queued)');
        // The download runs again: the claim is taken and the Move goes on.
        rXMLRPCRequest::reset();
        $receipt = str_repeat('d', 32) . ':' . str_repeat('0', 15) . '1|1|1|1|' . str_repeat('A', 40);
        rXMLRPCRequest::queue('d.stop_close_claim_state', true, false, array('absent'));
        rXMLRPCRequest::queue('branch', true, false, array($receipt));
        try { strictInvoke('AutoToolsMoveTransaction', 'reconcile', array($journal, $kept)); }
        catch (RuntimeException $e) { /* the next claim step is not scripted here */ }
        strictAssertSame('claimed', json_decode(file_get_contents($path), true)['phase'], 'the job holds the claim');
    } finally { autoToolsCleanup($root); }
});

$suite->test('a Move whose download its user stopped is refused at once and visibly', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        list($journal, $job) = autoToolsJournaledJob($root);
        autoToolsQueueSkippedClaim();
        rXMLRPCRequest::queue('system.retrackers.original_claim', true, false, array('none'));
        rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(''));
        rXMLRPCRequest::queue('d.state', true, false, array('0'));
        autoToolsQueueOutcome($root);
        FileUtil::$messages = array();
        strictAssertSame(false, strictInvoke('AutoToolsMoveTransaction', 'reconcile', array($journal, $job)),
            'the Move is refused');
        strictAssertSame(array('autotools: move ' . str_repeat('A', 40) . ' refused: download-stopped'),
            FileUtil::$messages, 'once in the log');
        strictAssertSame('download-stopped', autoToolsRecordedOutcome()['outcome']['reason'], 'and on the download');
        strictAssertSame(array(), glob($journal . '/*.move.json'), 'the job retires');
        strictAssertSame('payload bytes', file_get_contents($root . '/dl/payload.bin'), 'the data stays');
    } finally { autoToolsCleanup($root); }
});

$suite->test('a Move whose download never runs again is refused visibly after the window', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        list($journal, $job) = autoToolsJournaledJob($root);
        $job['waiting_since'] = time() - 1801;
        autoToolsQueueSkippedClaim();
        rXMLRPCRequest::queue('system.retrackers.original_claim', true, false, array('none'));
        rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(''));
        rXMLRPCRequest::queue('d.state', true, false, array('1'));
        autoToolsQueueOutcome($root);
        FileUtil::$messages = array();
        strictInvoke('AutoToolsMoveTransaction', 'reconcile', array($journal, $job));
        strictAssertSame(array('autotools: move ' . str_repeat('A', 40) . ' refused: download-not-running'
            . ' unresolved for 30 min'), FileUtil::$messages, 'the refusal is logged once');
        strictAssertSame('download-not-running', autoToolsRecordedOutcome()['outcome']['reason'], 'and kept');
        strictAssertSame(array(), glob($journal . '/*.move.json'), 'the job retires');
    } finally { autoToolsCleanup($root); }
});

$suite->test('a Move whose source path the daemon reports elsewhere is refused at once', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        list($journal, $job) = autoToolsJournaledJob($root);
        autoToolsQueueSkippedClaim('/elsewhere/payload.bin');
        FileUtil::$messages = array();
        strictInvoke('AutoToolsMoveTransaction', 'reconcile', array($journal, $job));
        strictAssertSame(array('autotools: move ' . str_repeat('A', 40) . ' refused: daemon source path changed'),
            FileUtil::$messages, 'the user moved it: refused');
        strictAssertSame(array(), rXMLRPCRequest::requestsFor('system.retrackers.original_claim'), 'without a wait');
        strictAssertSame(array(), glob($journal . '/*.move.json'), 'the job retires');
    } finally { autoToolsCleanup($root); }
});

// A planned Move that waits for its download holds no claim and has not
// touched the payload. If the download is removed meanwhile, its claim read
// fails for good: the job is retired once the download is proven gone, not
// held and logged every 60 s for ever; a re-add keeps it waiting.
$suite->test('a waiting Move whose download was removed is retired, not held for ever', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        list($journal, $job) = autoToolsJournaledJob($root);
        $path = $journal . '/' . str_repeat('A', 40) . '.move.json';
        $job['waiting_since'] = time();
        file_put_contents($path, json_encode($job, JSON_UNESCAPED_SLASHES));
        $gone = function () {
            rXMLRPCRequest::queue('d.stop_close_claim_state', true, true, array('-500'), 'invalid parameters: info-hash not found');
            rXMLRPCRequest::queue(getCmd('d.hash'), true, true, array('-500'));
            rXMLRPCRequest::queue('system.retrackers.original_claim', true, false, array('none'));
            rXMLRPCRequest::queue(getCmd('d.hash'), true, true, array('-500'));
        };
        // A first sight may be an erase and reload without a retrackers claim.
        $gone();
        FileUtil::$messages = array();
        AutoToolsMoveTransaction::recoverAll();
        strictAssertSame(array('autotools: move ' . str_repeat('A', 40) . ' recovery hold: rpc-d.stop_close_claim_state-unconfirmed'),
            FileUtil::$messages, 'the first pass holds the job');
        $kept = json_decode(file_get_contents($path), true);
        strictAssertTrue(is_int($kept['absent_since']), 'and notes when it saw the download gone');
        // A pass one schedule later that still finds it gone retires it.
        $kept['absent_since'] = time() - 61;
        file_put_contents($path, json_encode($kept, JSON_UNESCAPED_SLASHES));
        rXMLRPCRequest::reset();
        $gone();
        FileUtil::$messages = array();
        AutoToolsMoveTransaction::recoverAll();
        strictAssertSame(array('autotools: move ' . str_repeat('A', 40) . ' refused: download-absent'),
            FileUtil::$messages, 'the job is refused once');
        strictAssertSame(array(), glob($journal . '/*.move.json'), 'and retired');
        strictAssertSame(array(), rXMLRPCRequest::requestsFor(getCmd('d.get_directory')), 'nothing is shown on a gone download');
        strictAssertSame('payload bytes', file_get_contents($root . '/dl/payload.bin'), 'the data stays');
    } finally { autoToolsCleanup($root); }
});

$suite->test('a waiting Move whose download is briefly absent while retrackers re-adds it keeps waiting', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        list($journal, $job) = autoToolsJournaledJob($root);
        rXMLRPCRequest::queue('d.stop_close_claim_state', true, true, array('-500'), 'invalid parameters: info-hash not found');
        rXMLRPCRequest::queue(getCmd('d.hash'), true, true, array('-500'));
        rXMLRPCRequest::queue('system.retrackers.original_claim', true, false, array('held'));
        FileUtil::$messages = array();
        AutoToolsMoveTransaction::recoverAll();
        strictAssertSame(array('autotools: move ' . str_repeat('A', 40) . ' recovery hold: rpc-d.stop_close_claim_state-unconfirmed'),
            FileUtil::$messages, 'the pass holds the job');
        strictAssertSame(1, count(glob($journal . '/*.move.json')), 'and keeps it for the next pass');
        strictAssertSame(false, array_key_exists('absent_since', json_decode(file_get_contents($journal . '/'
            . str_repeat('A', 40) . '.move.json'), true)), 'a held claim is no sight of a gone download');
    } finally { autoToolsCleanup($root); }
});

// ACC-OWNB-02, the re-add window again (L4b lab, 1 of 40 public Moves on the
// candidate): retrackers re-adds a public download from the original it
// captured at insert, so one that finished in that window comes back without
// the job marker its finished hook set, and the Move was refused as
// 'job-marker-mismatch'. An empty marker is put back for the job, only while
// it is still empty; another job's marker is left alone.
$suite->test('a Move whose download came back without its job marker gets it back and is planned', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(''));
        rXMLRPCRequest::queue('d.complete', true, false, array('1'));
        rXMLRPCRequest::queue('branch', true, false, array('0'));
        rXMLRPCRequest::queue(getCmd('d.save_full_session'), true, false, array('0'));
        rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(str_repeat('c', 32)));
        $job = strictInvoke('AutoToolsMoveTransaction', 'prepare', array(autoToolsMoveArgs()));
        strictAssertSame($root . '/fin/new/Named/payload.bin', $job['dst'], 'the Move is planned');
        $branch = rXMLRPCRequest::requestsFor('branch');
        strictAssertSame(1, count($branch), 'the marker is put back in one daemon branch');
        list($hash, $condition, $then) = $branch[0]['commands'][0]->params;
        strictAssertSame(array(str_repeat('A', 40), 'equal=' . getCmd('d.get_custom=') . 'x-autotools-move-job,cat=',
            getCmd('d.set_custom') . '=x-autotools-move-job,' . str_repeat('c', 32)), array($hash, $condition, $then),
            'only while it is empty, and for this job');
        strictAssertSame(1, count(rXMLRPCRequest::requestsFor(getCmd('d.save_full_session'))), 'and kept by a session save');
        strictAssertSame(array('autotools: move ' . str_repeat('A', 40) . ' job marker restored after the download was'
            . ' re-added'), FileUtil::$messages, 'it is said once');
    } finally { autoToolsCleanup($root); }
});

$suite->test('a Move whose download carries another job marker is not taken over', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(''));
        rXMLRPCRequest::queue('d.complete', true, false, array('1'));
        rXMLRPCRequest::queue('branch', true, false, array('SKIP'));
        rXMLRPCRequest::queue(getCmd('d.save_full_session'), true, false, array('0'));
        rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(str_repeat('d', 32)));
        try {
            strictInvoke('AutoToolsMoveTransaction', 'prepare', array(autoToolsMoveArgs()));
            throw new LogicException('a Move was planned under another job marker');
        } catch (RuntimeException $error) {
            strictAssertSame('job-marker-mismatch', $error->getMessage(), 'a newer hook owns the download');
        }
        strictAssertSame(array(), FileUtil::$messages, 'nothing is restored');
    } finally { autoToolsCleanup($root); }
});

$suite->test('a planned Move whose download came back without its job marker gets it back and waits', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        list($journal, $job) = autoToolsJournaledJob($root);
        rXMLRPCRequest::queue('d.stop_close_claim_state', true, false, array('absent'));
        rXMLRPCRequest::queue('branch', true, false, array('SKIP'));
        rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(''));
        rXMLRPCRequest::queue('d.complete', true, false, array('1'));
        rXMLRPCRequest::queue('branch', true, false, array('0'));
        rXMLRPCRequest::queue(getCmd('d.save_full_session'), true, false, array('0'));
        rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(str_repeat('c', 32)));
        FileUtil::$messages = array();
        strictAssertSame(false, strictInvoke('AutoToolsMoveTransaction', 'reconcile', array($journal, $job)),
            'the claim waits for the next pass');
        strictAssertSame(array('autotools: move ' . str_repeat('A', 40) . ' deferred: job marker restored after the'
            . ' download was re-added; recover.php claims it again every 60 s'), FileUtil::$messages, 'said once');
        strictAssertSame(1, count(glob($journal . '/*.move.json')), 'the job is kept');
        $restore = rXMLRPCRequest::requestsFor('branch');
        strictAssertSame(getCmd('d.set_custom') . '=x-autotools-move-job,' . str_repeat('c', 32),
            $restore[1]['commands'][0]->params[2], 'the marker is put back for this job');
    } finally { autoToolsCleanup($root); }
});

// A request still pending when the user removed the download and added it
// again: the new download is not finished, and its own hook moves it.
$suite->test('a stale Move request does not take over a download added again and not finished', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(''));
        rXMLRPCRequest::queue('d.complete', true, false, array('0'));
        rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(''));
        try {
            strictInvoke('AutoToolsMoveTransaction', 'prepare', array(autoToolsMoveArgs()));
            throw new LogicException('a stale request was planned for an unfinished download');
        } catch (RuntimeException $error) {
            strictAssertSame('job-marker-mismatch', $error->getMessage(), 'the stale request is refused');
        }
        strictAssertSame(array(), rXMLRPCRequest::requestsFor('branch'), 'and the marker is not touched');
    } finally { autoToolsCleanup($root); }
});

// Only answers decide that a download was stopped (reviewer D4): an
// unanswered claim waits like a held one, an unanswered queue read holds.
$suite->test('a Move whose retrackers claim does not answer waits instead of being refused', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        list($journal, $job) = autoToolsJournaledJob($root);
        autoToolsQueueSkippedClaim();
        rXMLRPCRequest::queue('system.retrackers.original_claim', false, false);
        FileUtil::$messages = array();
        strictAssertSame(false, strictInvoke('AutoToolsMoveTransaction', 'reconcile', array($journal, $job)),
            'the claim waits');
        strictAssertSame(array('autotools: move ' . str_repeat('A', 40) . ' deferred: download-not-running'
            . ' (retrackers-claim-unconfirmed); recover.php claims it again every 60 s'), FileUtil::$messages, 'said once');
        strictAssertSame(null, autoToolsRecordedOutcome(), 'nothing is refused');
        // An unanswered queue read holds the job for the next pass.
        rXMLRPCRequest::reset();
        $kept = json_decode(file_get_contents($journal . '/' . str_repeat('A', 40) . '.move.json'), true);
        autoToolsQueueSkippedClaim();
        rXMLRPCRequest::queue('system.retrackers.original_claim', true, false, array('none'));
        try {
            strictInvoke('AutoToolsMoveTransaction', 'reconcile', array($journal, $kept));
            throw new LogicException('an unanswered queue read decided the stop');
        } catch (RuntimeException $error) {
            strictAssertSame('rpc-' . getCmd('d.get_custom') . '-unconfirmed', $error->getMessage(), 'the pass holds');
        }
        strictAssertSame(1, count(glob($journal . '/*.move.json')), 'the job is kept');
        strictAssertSame(null, autoToolsRecordedOutcome(), 'and nothing is refused');
    } finally { autoToolsCleanup($root); }
});

// A wait ends with the claim (reviewer D5): a later wait has its own window.
$suite->test('a claimed Move forgets its earlier wait', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        list($journal, $job) = autoToolsJournaledJob($root);
        $job['waiting_since'] = time() - 1801;
        $receipt = str_repeat('d', 32) . ':' . str_repeat('0', 15) . '1|1|1|1|' . str_repeat('A', 40);
        rXMLRPCRequest::queue('branch', true, false, array($receipt));
        strictAssertSame(true, strictInvoke('AutoToolsMoveTransaction', 'claimIfDue', array($journal, &$job)),
            'the claim is taken');
        $kept = json_decode(file_get_contents($journal . '/' . str_repeat('A', 40) . '.move.json'), true);
        strictAssertSame(array('claimed', false), array($kept['phase'], array_key_exists('waiting_since', $kept)),
            'and the job keeps no old wait');
    } finally { autoToolsCleanup($root); }
});

// R4 fresh-journal gap, Move side: without .autotools-file-jobs in a session
// directory that turned unwritable the worker once logged 'hold:
// journal-unavailable' and kept nothing, so nothing retried the Move. A hook
// that nothing durable holds is refused in the log and on the download.
$suite->test('a Move whose journal cannot be made is refused visibly, not held silently', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        echo "scope - root creates the journal in a read-only directory; the case did not run\n";
        return;
    }
    $root = autoToolsMoveFixture();
    chmod($root . '/session', 0500);
    try {
        autoToolsHelper($root, 0, 0);
        autoToolsQueueOutcome($root);
        AutoToolsMoveTransaction::run(autoToolsMoveArgs());
        strictAssertSame(array('autotools: move ' . str_repeat('A', 40) . ' refused: journal-unavailable;'
            . ' the Move request could not be recorded'), FileUtil::$messages, 'the log says why, once');
        $recorded = autoToolsRecordedOutcome();
        strictAssertSame(array(str_repeat('A', 40), 'journal-unavailable'),
            array($recorded['hash'], $recorded['outcome']['reason']), 'the refusal is kept on the download');
        strictAssertTrue(strpos($recorded['message'], 'session directory') !== false
            && strpos($recorded['message'], 'Set directory') !== false, 'and says what to do: ' . $recorded['message']);
        strictAssertSame(false, file_exists($root . '/session/.autotools-file-jobs'), 'no journal was made');
        strictAssertSame('payload bytes', file_get_contents($root . '/dl/payload.bin'), 'the data stays');
    } finally { chmod($root . '/session', 0700); autoToolsCleanup($root); }
});

$suite->test('a Move whose request cannot be written is refused visibly, not held silently', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        echo "scope - root writes into a read-only journal; the case did not run\n";
        return;
    }
    $root = autoToolsMoveFixture();
    $journal = $root . '/session/.autotools-file-jobs';
    mkdir($journal, 0700);
    touch($journal . '/.lock');
    chmod($journal, 0500);
    try {
        autoToolsHelper($root, 0, 0);
        autoToolsQueueOutcome($root);
        AutoToolsMoveTransaction::run(autoToolsMoveArgs());
        strictAssertSame(array('autotools: move ' . str_repeat('A', 40) . ' refused: journal-temp-failed;'
            . ' the Move request could not be recorded'), FileUtil::$messages, 'the log says why, once');
        strictAssertSame('journal-temp-failed', autoToolsRecordedOutcome()['outcome']['reason'],
            'the refusal is kept on the download');
        strictAssertSame(array('.', '..', '.lock'), scandir($journal), 'nothing else is left in the journal');
    } finally { chmod($journal, 0700); autoToolsCleanup($root); }
});

$suite->test('AutoTools worker preparation checks the shared helper before daemon state reads', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        chmod($root . '/helper', 0600);
        try {
            strictInvoke('AutoToolsMoveTransaction', 'prepare', array(autoToolsMoveArgs()));
            throw new LogicException('an unavailable helper was accepted');
        } catch (RuntimeException $error) {
            strictAssertSame(0, strpos($error->getMessage(), 'rename-noreplace-helper-unavailable path='
                . erasedataLogPath($root . '/helper') . '; mutation refused; check the native helper'),
                'the refusal names the helper: ' . $error->getMessage());
        }
        strictAssertSame(array(), rXMLRPCRequest::$requests, 'no daemon state is read before the helper check');
        strictAssertSame(array(), autoToolsHelperCalls($root), 'an unavailable helper is never run');
    } finally { autoToolsCleanup($root); }
});

$suite->test('AutoTools publication uses the shared no-replace operation', function () {
    $root = autoToolsMoveFixture();
    try {
        autoToolsHelper($root, 0, 0);
        strictAssertSame(true, strictInvoke('AutoToolsMoveTransaction', 'renameNoReplace',
            array($root . '/source', $root . '/destination')), 'shared publication succeeds');
        strictAssertSame(array($root . '/source ' . $root . '/destination'), autoToolsHelperCalls($root),
            'AutoTools has no separate native-helper process contract');
    } finally { autoToolsCleanup($root); }
});

exit($suite->run());
