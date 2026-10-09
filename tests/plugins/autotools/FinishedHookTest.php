<?php

// The AutoTools finished hook for Copy, HardLink and SoftLink (hook_lib.php):
// rTorrent waits for it in execute.capture, so it never waits for the journal
// lock (R4-FS-01) and never stays silent when the session copy is missing
// (R4-FS-04). What it cannot do at once, recover.php performs.

require_once dirname(__DIR__, 2) . '/plugins/rutracker_check/TestLib.php';

class FileUtil
{
    public static $messages = array();
    public static function toLog($message) { self::$messages[] = $message; }
    public static function addslash($path) { return rtrim($path, '/') . '/'; }
}

// The plugin configuration the hook reads, and the checks it calls.
class rAutoTools
{
    public static function moveMode() { return 'upstream'; }
    public static $config = array();
    public static function load() { return (object) self::$config; }
    public static function destinationWithinRoot($root, $destination)
    {
        return strpos(rtrim($destination, '/') . '/', rtrim($root, '/') . '/') === 0;
    }
    public static function notifyCompletedFileTransfer($destination, $finishedRoot, $torrentName) { return true; }
    public static function completionMailFile($destination, $finishedRoot) { return null; }
}

function rtDbg($scope, $line) {}

require_once testFindRepoRoot() . '/plugins/erasedata/filesystem.php';
require_once testFindRepoRoot() . '/plugins/erasedata/outcome.php';
eval(loadClassDefinition(testFindRepoRoot() . '/php/Torrent.php', 'Torrent'));
foreach (array('rtAddTailSlash', 'rtMkDir') as $function)
    eval(loadFunctionDefinition(testFindRepoRoot() . '/php/utility/rtpluginutil.php', $function));
$util = testFindRepoRoot() . '/plugins/autotools/util_rt.php';
foreach (array('rtRemoveTailSlash', 'rtRemoveLastToken', 'rtGetRelativePath', 'rtOpFiles', 'rtAwaitSessionSave',
    'rtFileJobRetryWindow') as $function)
    eval(loadFunctionDefinition($util, $function));
eval(loadClassDefinition($util, 'AutoToolsFileTransaction'));
eval(loadClassDefinition(testFindRepoRoot() . '/plugins/autotools/hook_lib.php', 'AutoToolsFinishedHook'));
// recover.php's pass, for its NonMove scope; the Move journal name is all it
// reads of the Move transaction there.
eval(loadFunctionDefinition(testFindRepoRoot() . '/plugins/autotools/recover.php', 'rtRecoverFileJobs'));
class AutoToolsMoveJob { const JOURNAL_DIR = '.autotools-file-jobs'; }

// The wait for a session save is a courtesy to the daemon's save schedule.
define('AUTOTOOLS_SESSION_SAVE_WAIT', 0.5);

const HOOK_HASH = 'ABCDEF0123456789ABCDEF0123456789ABCDEF01';
const HOOK_TOKEN = '0123456789abcdef0123456789abcdef';

// A finished single-file download in the default directory, Copy configured
// into a finished root, and the session copy rTorrent publishes.
function hookFixture($withCopy = true)
{
    $root = realpath(getenv('TMPDIR') ?: sys_get_temp_dir()) . '/finished-hook-' . bin2hex(random_bytes(5));
    foreach (array('', '/session', '/dl', '/fin') as $directory)
        if (!mkdir($root . $directory, 0700))
            throw new RuntimeException('fixture directory was not created');
    file_put_contents($root . '/dl/payload.bin', 'payload bytes');
    if ($withCopy) hookPublishCopy($root);
    rTorrentSettings::get()->session = $root . '/session';
    rTorrentSettings::get()->directory = $root . '/dl';
    rAutoTools::$config = array('enable_move' => 1, 'fileop_type' => 'Copy', 'automove_filter' => '/.*/',
        'skip_move_for_files' => '', 'addLabel' => 0, 'addName' => 0, 'path_to_finished' => $root . '/fin');
    FileUtil::$messages = array();
    rXMLRPCRequest::reset();
    return $root;
}

function hookPublishCopy($root, $session = null)
{
    file_put_contents(($session === null ? $root . '/session' : $session) . '/' . HOOK_HASH . '.torrent',
        'd4:infod6:lengthi13e4:name11:payload.bin12:piece lengthi16384e6:pieces20:'
        . str_repeat("\x01", 20) . 'ee');
}

// rTorrent's arguments: hash, base path, base name, multi, label, name, user, token.
function hookArgs($root)
{
    return array(HOOK_HASH, $root . '/dl/payload.bin', 'payload.bin', '0', '', 'payload.bin', '', HOOK_TOKEN);
}

function hookRequests($root)
{
    return glob($root . '/session/.autotools-file-jobs/*' . AutoToolsFinishedHook::REQUEST_SUFFIX) ?: array();
}

// Another owner of the journal lock, as recover.php or the Move worker is
// while it waits for the daemon: a separate process that holds it until its
// standard input closes.
function hookHoldJournal($root)
{
    @mkdir($root . '/session/.autotools-file-jobs', 0700);
    $process = proc_open(array(PHP_BINARY, '-r', '$h = fopen($argv[1], "c"); flock($h, LOCK_EX);'
        . ' echo "held\n"; fflush(STDOUT); fgets(STDIN);', $root . '/session/.autotools-file-jobs/.lock'),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
    if (!is_resource($process) || trim(fgets($pipes[1])) !== 'held')
        throw new RuntimeException('the lock holder did not start');
    return array($process, $pipes);
}

function hookReleaseJournal($holder)
{
    list($process, $pipes) = $holder;
    fclose($pipes[0]);
    fclose($pipes[1]);
    proc_close($process);
}

function hookClean($root)
{
    rXMLRPCRequest::reset();
    strictRemoveTree($root);
}

$suite = new StrictTestSuite();

$suite->test('a finished hook publishes at once when the journal is free', function () {
    $root = hookFixture();
    try {
        strictAssertSame($root . '/fin/', AutoToolsFinishedHook::hook(hookArgs($root)), 'x-dest names the destination');
        strictAssertSame('payload bytes', @file_get_contents($root . '/fin/payload.bin'), 'the Copy is published');
        strictAssertSame(array(), hookRequests($root), 'nothing is deferred');
        strictAssertSame(array(), rXMLRPCRequest::$requests, 'the hook never calls the daemon');
    } finally { hookClean($root); }
});

$suite->test('a finished hook never waits for a journal another owner holds; recover.php performs it', function () {
    $root = hookFixture();
    $holder = hookHoldJournal($root);
    try {
        $started = microtime(true);
        $destination = AutoToolsFinishedHook::hook(hookArgs($root));
        $elapsed = microtime(true) - $started;
        strictAssertTrue($elapsed < 1, 'the hook returns at once: ' . round($elapsed, 2));
        strictAssertSame('', $destination, 'x-dest stays empty for now');
        strictAssertSame(false, file_exists($root . '/fin/payload.bin'), 'nothing is published yet');
        strictAssertSame(1, count(hookRequests($root)), 'the hook is recorded for recover.php');
        strictAssertSame(array('autotools: nonmove ' . HOOK_HASH . ' deferred: journal-busy;'
            . ' recover.php performs the Copy within 60 s'), FileUtil::$messages, 'the deferral is visible');
        AutoToolsFinishedHook::performDeferred();
        strictAssertSame(1, count(hookRequests($root)), 'a pass that finds the journal still busy keeps the request');
        hookReleaseJournal($holder);
        $holder = null;
        AutoToolsFinishedHook::performDeferred();
        strictAssertSame('payload bytes', @file_get_contents($root . '/fin/payload.bin'), 'recover.php publishes the Copy');
        strictAssertSame(array(), hookRequests($root), 'and retires the request');
        strictAssertSame(1, count(glob($root . '/session/.autotools-file-jobs/*.nonmove.json')),
            'the committed receipt waits for the x-dest replay of the same pass');
    } finally {
        if ($holder !== null) hookReleaseJournal($holder);
        hookClean($root);
    }
});

$suite->test('a finished hook without the session copy is deferred visibly, and published once the copy appears', function () {
    $root = hookFixture(false);
    try {
        strictAssertSame('', AutoToolsFinishedHook::hook(hookArgs($root)), 'x-dest stays empty for now');
        strictAssertSame(array('autotools: nonmove ' . HOOK_HASH . ' deferred: session-copy-unreadable;'
            . ' recover.php performs the Copy within 60 s'), FileUtil::$messages, 'the hook is not silent');
        strictAssertSame(1, count(hookRequests($root)), 'the hook is recorded for recover.php');
        // rTorrent's session thread publishes the copy after the save request.
        rXMLRPCRequest::queue(getCmd('d.save_full_session'), true, false, function () use ($root) {
            hookPublishCopy($root);
            return array('0');
        });
        AutoToolsFinishedHook::performDeferred();
        strictAssertSame('payload bytes', @file_get_contents($root . '/fin/payload.bin'), 'recover.php publishes the Copy');
        strictAssertSame(array(), hookRequests($root), 'and retires the request');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor(getCmd('d.message.set'))), 'no refusal is shown');
    } finally { hookClean($root); }
});

$suite->test('a deferred hook whose session copy never appears is refused in the log and on the torrent', function () {
    $root = hookFixture(false);
    try {
        AutoToolsFinishedHook::hook(hookArgs($root));
        FileUtil::$messages = array();
        // Within the retry window a missing copy is waited for again.
        rXMLRPCRequest::queue(getCmd('d.save_full_session'), true, false, array('0'));
        AutoToolsFinishedHook::performDeferred();
        strictAssertSame(array(), FileUtil::$messages, 'a pass inside the window refuses nothing');
        strictAssertSame(1, count(hookRequests($root)), 'and keeps the request');
        $requests = hookRequests($root);
        $request = json_decode(file_get_contents($requests[0]), true);
        $request['at'] = time() - 1801;
        file_put_contents($requests[0], json_encode($request));
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(getCmd('d.save_full_session'), true, false, array('0'));
        rXMLRPCRequest::queue(getCmd('d.get_directory'), true, false, array($root . '/dl'));
        $write = getCmd('d.set_custom') . '|' . getCmd('d.message.set');
        rXMLRPCRequest::queue($write, true, false, array('0', '0'));
        rXMLRPCRequest::queue(getCmd('d.save_full_session'), true, false, array('0'));
        $started = microtime(true);
        AutoToolsFinishedHook::performDeferred();
        strictAssertTrue(microtime(true) - $started >= AUTOTOOLS_SESSION_SAVE_WAIT, 'recover.php waited for the copy');
        strictAssertSame(array('autotools: nonmove ' . HOOK_HASH . ' refused: session-copy-unreadable'),
            FileUtil::$messages, 'the refusal is logged once');
        $set = rXMLRPCRequest::requestsFor($write);
        strictAssertSame(1, count($set), 'and named on the torrent');
        list($custom, $message) = $set[0]['commands'];
        strictAssertSame(HOOK_HASH, $message->params[0], 'of the right download');
        strictAssertTrue(strpos($message->params[1], 'autotools: Copy refused: session-copy-unreadable;') === 0,
            'with a classified reason: ' . $message->params[1]);
        // Every pass of the 30 min window waited for the copy, not one 10 s wait.
        strictAssertTrue(strpos($message->params[1], 'within 30 min') !== false
            && strpos($message->params[1], ' s,') === false, 'and the window it waited: ' . $message->params[1]);
        // ACC-OWNB-01: kept where tracker traffic cannot erase it.
        strictAssertSame(array(HOOK_HASH, 'x-autotools-outcome'), array_slice($custom->params, 0, 2),
            'the outcome is kept in the download\'s custom key');
        $outcome = json_decode(rawurldecode($custom->params[2]), true);
        strictAssertSame(array('session-copy-unreadable', $message->params[1], $root . '/dl', false),
            array($outcome['reason'], $outcome['message'], $outcome['directory'], $outcome['while_stopped']),
            'with the reason, the message and the directory it is about');
        strictAssertSame(array(), hookRequests($root), 'the request retires');
        strictAssertSame(false, file_exists($root . '/fin/payload.bin'), 'nothing is published');
    } finally { hookClean($root); }
});

// The R4 fresh-journal gap: no journal yet, and a session directory that has
// turned unwritable, so not even the deferral can be recorded. The hook cannot
// call the daemon; it starts notify.php, which names the refusal on the
// torrent once the daemon is free. Only a non-root UID is refused the journal.
$suite->test('a deferral that cannot be recorded is still named on the torrent', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        echo "scope - root creates the journal in a read-only directory; the case did not run\n";
        return;
    }
    $root = hookFixture(false);
    $spawned = array();
    strictSetPrivateStatic('AutoToolsFinishedHook', 'spawn', function ($argv) use (&$spawned) { $spawned[] = $argv; });
    chmod($root . '/session', 0500);
    try {
        strictAssertSame('', AutoToolsFinishedHook::hook(hookArgs($root)), 'x-dest stays empty');
        strictAssertSame(array('autotools: nonmove ' . HOOK_HASH . ' refused: session-copy-unreadable;'
            . ' the deferred request could not be recorded'), FileUtil::$messages, 'the log says why');
        // The class is evaluated here, so only the script's name is compared.
        strictAssertSame(1, count($spawned), 'one notifier is started');
        strictAssertSame(array('notify.php', HOOK_HASH, 'Copy', 'session-copy-unreadable', '', ''),
            array_merge(array(basename($spawned[0][0])), array_slice($spawned[0], 1)), 'for this torrent');
        strictAssertSame(array(), rXMLRPCRequest::$requests, 'the hook itself never calls the daemon');
        // notify.php, once the daemon is free.
        rXMLRPCRequest::queue(getCmd('d.get_directory'), true, false, array($root . '/dl'));
        $write = getCmd('d.set_custom') . '|' . getCmd('d.message.set');
        rXMLRPCRequest::queue($write, true, false, array('0', '0'));
        rXMLRPCRequest::queue(getCmd('d.save_full_session'), true, false, array('0'));
        strictAssertSame(true, AutoToolsFinishedHook::notifyRefusal(HOOK_HASH, 'Copy', 'session-copy-unreadable'),
            'the refusal is shown');
        list($custom, $message) = rXMLRPCRequest::requestsFor($write)[0]['commands'];
        strictAssertSame(0, strpos($message->params[1], 'autotools: Copy refused: session-copy-unreadable;'),
            'with its reason: ' . $message->params[1]);
        strictAssertSame('session-copy-unreadable', json_decode(rawurldecode($custom->params[2]), true)['reason'],
            'and kept on the download');
        strictAssertSame(false, AutoToolsFinishedHook::notifyRefusal(HOOK_HASH, 'Move', 'session-copy-unreadable'),
            'only a non-Move refusal is shown this way');
    } finally {
        strictSetPrivateStatic('AutoToolsFinishedHook', 'spawn', null);
        chmod($root . '/session', 0700);
        hookClean($root);
    }
});

// R4 trust refusal: a Copy into a directory other users can write was once
// logged as 'nonmove new held: source or destination has an unsafe writable
// ancestor' and nothing else -- no hash, no d.message, no kept outcome. The
// hook names it in the log with its hash and has notify.php show it.
$suite->test('an untrusted destination is refused with the hash and named on the torrent', function () {
    $root = hookFixture();
    $spawned = array();
    strictSetPrivateStatic('AutoToolsFinishedHook', 'spawn', function ($argv) use (&$spawned) { $spawned[] = $argv; });
    chmod($root . '/fin', 0777);
    try {
        strictAssertSame('', AutoToolsFinishedHook::hook(hookArgs($root)), 'x-dest stays empty');
        strictAssertSame(array('autotools: nonmove ' . HOOK_HASH . ' refused: untrusted-directory path='
            . erasedataLogPath($root . '/fin')), FileUtil::$messages, 'the log names the download and the path');
        strictAssertSame(false, file_exists($root . '/fin/payload.bin'), 'nothing is published');
        strictAssertSame(array(), hookRequests($root), 'and nothing is deferred: the refusal is final');
        strictAssertSame(array(), rXMLRPCRequest::$requests, 'the hook itself never calls the daemon');
        strictAssertSame(1, count($spawned), 'one notifier is started');
        strictAssertSame(array('notify.php', HOOK_HASH, 'Copy', 'untrusted-directory', '', $root . '/fin'),
            array_merge(array(basename($spawned[0][0])), array_slice($spawned[0], 1)), 'for this refusal');
        // notify.php, once the daemon is free.
        rXMLRPCRequest::queue(getCmd('d.get_directory'), true, false, array($root . '/dl'));
        $write = getCmd('d.set_custom') . '|' . getCmd('d.message.set');
        rXMLRPCRequest::queue($write, true, false, array('0', '0'));
        rXMLRPCRequest::queue(getCmd('d.save_full_session'), true, false, array('0'));
        strictAssertSame(true, call_user_func_array(array('AutoToolsFinishedHook', 'notifyRefusal'),
            array_slice($spawned[0], 1, 3) + array(3 => $spawned[0][5])), 'the refusal is shown');
        list($custom, $message) = rXMLRPCRequest::requestsFor($write)[0]['commands'];
        strictAssertSame(0, strpos($message->params[1], 'autotools: Copy refused: untrusted-directory ' . $root . '/fin;'),
            'with its reason and path: ' . $message->params[1]);
        strictAssertSame('untrusted-directory', json_decode(rawurldecode($custom->params[2]), true)['reason'],
            'and kept on the download');
    } finally {
        strictSetPrivateStatic('AutoToolsFinishedHook', 'spawn', null);
        chmod($root . '/fin', 0700);
        hookClean($root);
    }
});

// recover.php performs a deferred hook in the background, where it may call
// the daemon: a refusal there is named on the torrent directly.
$suite->test('a deferred hook refused by recover.php is named on the torrent without a notifier', function () {
    $root = hookFixture(false);
    $spawned = array();
    strictSetPrivateStatic('AutoToolsFinishedHook', 'spawn', function ($argv) use (&$spawned) { $spawned[] = $argv; });
    try {
        AutoToolsFinishedHook::hook(hookArgs($root));
        strictAssertSame(1, count(hookRequests($root)), 'the hook is deferred');
        hookPublishCopy($root);
        chmod($root . '/fin', 0777);
        FileUtil::$messages = array();
        rXMLRPCRequest::queue(getCmd('d.get_directory'), true, false, array($root . '/dl'));
        $write = getCmd('d.set_custom') . '|' . getCmd('d.message.set');
        rXMLRPCRequest::queue($write, true, false, array('0', '0'));
        rXMLRPCRequest::queue(getCmd('d.save_full_session'), true, false, array('0'));
        AutoToolsFinishedHook::performDeferred();
        strictAssertSame(array('autotools: nonmove ' . HOOK_HASH . ' refused: untrusted-directory path='
            . erasedataLogPath($root . '/fin')), FileUtil::$messages, 'the refusal is logged once with its hash');
        $set = rXMLRPCRequest::requestsFor($write);
        strictAssertSame(1, count($set), 'and named on the torrent');
        strictAssertSame('untrusted-directory', json_decode(rawurldecode($set[0]['commands'][0]->params[2]), true)['reason'],
            'with its reason');
        strictAssertSame(array(), $spawned, 'without a detached notifier');
        strictAssertSame(array(), hookRequests($root), 'the request retires');
        strictAssertSame(false, file_exists($root . '/fin/payload.bin'), 'nothing is published');
    } finally {
        strictSetPrivateStatic('AutoToolsFinishedHook', 'spawn', null);
        chmod($root . '/fin', 0700);
        hookClean($root);
    }
});

// The R4 fresh-journal gap, first half: a fresh instance had no journal
// until a Move recovery happened to make one, so a hook in a session
// directory that turned unwritable could not record its deferral. Every
// recovery pass now makes and attests it, as the daemon's user, and holds the
// pass lock from the first pass on.
$suite->test('a recovery pass makes and attests the journal before a hook needs it', function () {
    $root = hookFixture();
    try {
        $journal = $root . '/session/.autotools-file-jobs';
        strictAssertSame(false, file_exists($journal), 'a fresh instance has no journal');
        rtRecoverFileJobs('NonMove');
        clearstatcache();
        strictAssertSame(true, is_dir($journal) && !is_link($journal), 'the pass makes the journal');
        strictAssertSame(0700, fileperms($journal) & 07777, 'private to the daemon\'s user');
        strictAssertSame(true, is_file($journal . '/.recover.lock'), 'and serializes its passes from the first one');
        strictAssertSame(array(), FileUtil::$messages, 'quietly');
    } finally { hookClean($root); }
});

// The R4 fresh-journal gap: once the journal exists -- recover.php makes it
// as the daemon's user, at once after setHandlers() while it is missing -- a
// hook in a session directory that turned unwritable still records its
// deferral, and the Copy is performed once the copy appears.
$suite->test('a journal made by a recovery pass keeps the deferral of an unwritable session directory', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        echo "scope - root writes into a read-only directory; the case did not run\n";
        return;
    }
    $root = hookFixture(false);
    try {
        strictAssertSame($root . '/session/.autotools-file-jobs', AutoToolsFileTransaction::journalRoot(true),
            'the pass makes and attests the journal');
        chmod($root . '/session', 0500);
        strictAssertSame('', AutoToolsFinishedHook::hook(hookArgs($root)), 'x-dest stays empty for now');
        strictAssertSame(array('autotools: nonmove ' . HOOK_HASH . ' deferred: session-copy-unreadable;'
            . ' recover.php performs the Copy within 60 s'), FileUtil::$messages, 'the deferral is recorded');
        chmod($root . '/session', 0700);
        rXMLRPCRequest::queue(getCmd('d.save_full_session'), true, false, function () use ($root) {
            hookPublishCopy($root);
            return array('0');
        });
        AutoToolsFinishedHook::performDeferred();
        strictAssertSame('payload bytes', @file_get_contents($root . '/fin/payload.bin'), 'recover.php publishes the Copy');
        strictAssertSame(array(), hookRequests($root), 'and retires the request');
    } finally {
        chmod($root . '/session', 0700);
        hookClean($root);
    }
});

$suite->test('an invalid deferred request is refused visibly and not performed', function () {
    $root = hookFixture();
    try {
        mkdir($root . '/session/.autotools-file-jobs', 0700);
        $name = str_repeat('a', 24) . AutoToolsFinishedHook::REQUEST_SUFFIX;
        file_put_contents($root . '/session/.autotools-file-jobs/' . $name, '{"version":1}');
        AutoToolsFinishedHook::performDeferred();
        strictAssertSame(array('autotools: nonmove request ' . $name
            . ' refused: invalid request; the hook it held is not performed'), FileUtil::$messages,
            'the refusal names the request');
        strictAssertSame(array(), hookRequests($root), 'and removes it');
        strictAssertSame(false, file_exists($root . '/fin/payload.bin'), 'nothing is published');
    } finally { hookClean($root); }
});

// R5-REV-05: the session path is the daemon's own bytes, and glob() reads a
// '\', '[...]', '*' or '?' in it as pattern syntax. A request deferred in such
// a session directory was recorded but never found again, and the pattern
// could match a sibling directory's requests instead: 'ses\sion' matches
// 'session', 'ses[x]sion' matches 'sesxsion', 'ses*sion' matches both and
// 'sesXsion'.
$suite->test('a deferred hook is performed in a session directory that glob() would read as a pattern', function () {
    foreach (array('ses\\sion', 'ses[x]sion', 'ses*sion') as $name) {
        $root = hookFixture(false);
        try {
            $session = $root . '/' . $name;
            rename($root . '/session', $session);
            rTorrentSettings::get()->session = $session;
            $foreign = array();
            foreach (array('session', 'sesxsion', 'sesXsion') as $sibling) {
                mkdir($root . '/' . $sibling . '/.autotools-file-jobs', 0700, true);
                $path = $root . '/' . $sibling . '/.autotools-file-jobs/' . str_repeat('b', 24)
                    . AutoToolsFinishedHook::REQUEST_SUFFIX;
                file_put_contents($path, '{"version":1}');
                $foreign[] = $path;
            }
            $journal = $session . '/.autotools-file-jobs';
            $requests = function () use ($journal) {
                return array_values(preg_grep('/\.nonmove-request\.json$/D', scandir($journal)));
            };
            strictAssertSame('', AutoToolsFinishedHook::hook(hookArgs($root)), 'x-dest stays empty for now: ' . $name);
            strictAssertSame(1, count($requests()), 'the hook is recorded for recover.php: ' . $name);
            rXMLRPCRequest::queue(getCmd('d.save_full_session'), true, false, function () use ($root, $session) {
                hookPublishCopy($root, $session);
                return array('0');
            });
            AutoToolsFinishedHook::performDeferred();
            strictAssertSame('payload bytes', @file_get_contents($root . '/fin/payload.bin'),
                'recover.php publishes the Copy: ' . $name);
            strictAssertSame(array(), $requests(), 'and retires the request: ' . $name);
            foreach ($foreign as $path)
                strictAssertSame('{"version":1}', @file_get_contents($path),
                    'a sibling directory\'s request is left alone: ' . $name . ' ' . $path);
            strictAssertSame(array('autotools: nonmove ' . HOOK_HASH . ' deferred: session-copy-unreadable;'
                . ' recover.php performs the Copy within 60 s'), FileUtil::$messages,
                'only the deferral is logged: ' . $name);
        } finally { hookClean($root); }
    }
});

exit($suite->run());
