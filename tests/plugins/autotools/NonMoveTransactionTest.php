<?php
require_once(__DIR__.'/../../../php/utility/json.php');
// Extracted classes omit the production bootstrap which supplies Utility.
require_once(__DIR__.'/../../../php/utility/utility.php');
require_once dirname(__DIR__, 2) . '/plugins/rutracker_check/TestLib.php';
eval(loadClassDefinition(testFindRepoRoot() . '/php/rtorrent.php', 'rTorrent'));
class FileUtil { public static $messages = array(); public static function toLog($message) { self::$messages[] = $message; }
    public static function getFileName($path) { $parts = explode('/', $path); return end($parts); } }
// Use the production mail-file selection policy and spy only on delivery.
eval('class rAutoTools { public static $sent = array(); public static '
    . loadFunctionDefinition(testFindRepoRoot() . '/plugins/autotools/autotools.php', 'completionMailFile')
    . ' public static function notifyCompletedFileTransfer($destination, $finishedRoot, $torrentName) {'
    . ' self::$sent[] = array($destination, $finishedRoot, $torrentName); return true; } }');
// The payload trust rule the transaction shares with datadir and Move.
require_once dirname(__DIR__, 3) . '/plugins/erasedata/filesystem.php';
require_once dirname(__DIR__, 3) . '/plugins/erasedata/outcome.php';
eval(loadClassDefinition(dirname(__DIR__, 3) . '/php/Torrent.php', 'Torrent'));
function rtMkDir($path, $mode = 0777) { return is_dir($path) || @mkdir($path, $mode, true) || is_dir($path); }
$source = dirname(__DIR__, 3) . '/plugins/autotools/util_rt.php';
eval(loadClassDefinition($source, 'AutoToolsFileTransaction'));
eval(loadFunctionDefinition($source, 'rtOpFiles'));
// The wait for a session save is a courtesy to the daemon's save schedule.
define('AUTOTOOLS_SESSION_SAVE_WAIT', 0.5);
if (strpos(file_get_contents($source), 'function rtAwaitSessionSave(') !== false)
    eval(loadFunctionDefinition($source, 'rtAwaitSessionSave'));
class RaceNonMove extends AutoToolsFileTransaction {
    public static $dest;
    protected static function checkpoint($name) {
        if ($name === 'after-publish-0') {
            rename(self::$dest, self::$dest . '.other-backup');
            file_put_contents(self::$dest, 'foreign-new');
        }
    }
}
class BackupRaceNonMove extends AutoToolsFileTransaction {
    public static $dest;
    public static $killAfterMove = false;
    protected static function checkpoint($name) {
        if ($name === 'before-backup-0') {
            rename(self::$dest, self::$dest . '.other-backup');
            file_put_contents(self::$dest, 'foreign-new');
        }
        if ($name === 'after-backup-unverified-0' && self::$killAfterMove)
            exec('kill -9 ' . getmypid());
    }
}
class DeleteOldNonMove extends AutoToolsFileTransaction {
    public static $dest;
    protected static function checkpoint($name) {
        if ($name === 'before-backup-0') unlink(self::$dest);
    }
}
class RestoreCollisionNonMove extends AutoToolsFileTransaction {
    public static $dest;
    protected static function checkpoint($name) {
        if ($name === 'before-backup-0') {
            unlink(self::$dest);
            file_put_contents(self::$dest, 'foreign-moved');
        }
        if ($name === 'after-backup-unverified-0')
            file_put_contents(self::$dest, 'foreign-current');
    }
}
class SourceChangeNonMove extends AutoToolsFileTransaction {
    public static $source;
    protected static function checkpoint($name) {
        if ($name === 'after-stage-file-0') {
            $mtime = filemtime(self::$source);
            file_put_contents(self::$source, 'new-omega');
            touch(self::$source, $mtime);
        }
    }
}
class SourceSwapNonMove extends AutoToolsFileTransaction {
    public static $source;
    protected static function checkpoint($name) {
        if ($name === 'after-stage-file-0') {
            rename(self::$source, self::$source . '.original');
            file_put_contents(self::$source, 'new-alpha');
        }
    }
}
class EarlierSourceChangeNonMove extends AutoToolsFileTransaction {
    public static $source;
    protected static function checkpoint($name) {
        if ($name === 'after-stage-file-1') {
            $mtime = filemtime(self::$source);
            file_put_contents(self::$source, 'new-omega');
            touch(self::$source, $mtime);
        }
    }
}
class CrashNonMove extends AutoToolsFileTransaction {
    public static $cut;
    protected static function checkpoint($name) {
        if (self::$cut === $name) exec('kill -9 ' . getmypid());
    }
}
function txFixture() {
    $base = getenv('TMPDIR') ?: sys_get_temp_dir();
    $root = $base . '/nonmove-' . bin2hex(random_bytes(5));
    mkdir($root, 0700);
    foreach (array('src/a','src/b','dst/item/a','dst/item/b','session') as $dir) mkdir($root . '/' . $dir, 0700, true);
    file_put_contents($root . '/src/a/f', 'new-alpha');
    file_put_contents($root . '/src/b/f', 'new-beta');
    rTorrentSettings::get()->session = $root . '/session';
    return $root;
}
if (isset($argv[1]) && $argv[1] === '--crash-notice') {
    $root = $argv[2];
    rTorrentSettings::get()->session = $root . '/session';
    CrashNonMove::$cut = 'after-notice-attempt';
    CrashNonMove::recoverAll($root . '/session/.autotools-file-jobs');
    exit(42);
}
if (isset($argv[1]) && $argv[1] === '--crash-context') {
    $root = $argv[2]; $cut = $argv[3];
    rTorrentSettings::get()->session = $root . '/session';
    CrashNonMove::$cut = $cut;
    $context = array('hash' => str_repeat('A', 40), 'token' => str_repeat('b', 32),
        'sourceBase' => $root . '/src/a/f', 'destination' => $root . '/dst/item/');
    CrashNonMove::run(array('a/f','b/f'), $root . '/src', $root . '/dst/item',
        'Copy', false, $context);
    exit(42);
}
if (isset($argv[1]) && $argv[1] === '--crash') {
    $root = $argv[2]; $mode = $argv[3]; $cut = $argv[4];
    rTorrentSettings::get()->session = $root . '/session';
    if ($cut === 'raced-backup') {
        BackupRaceNonMove::$dest = $root . '/dst/item/a/f';
        BackupRaceNonMove::$killAfterMove = true;
        $result = BackupRaceNonMove::run(array('a/f'), $root . '/src', $root . '/dst/item', $mode);
        fwrite(STDERR, json_encode(array('result' => $result, 'messages' => FileUtil::$messages)) . PHP_EOL);
    } else {
        CrashNonMove::$cut = $cut;
        $result = CrashNonMove::run(array('a/f','b/f'), $root . '/src', $root . '/dst/item', $mode);
        fwrite(STDERR, json_encode(array('result' => $result, 'messages' => FileUtil::$messages)) . PHP_EOL);
    }
    exit(42);
}
function txContextCrash($root, $cut) {
    $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) .
        ' --crash-context ' . escapeshellarg($root) . ' ' . escapeshellarg($cut);
    $pipes = array();
    $process = proc_open($command, array(0 => array('file', '/dev/null', 'r'),
        1 => array('file', '/dev/null', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start context crash child');
    stream_get_contents($pipes[2]); fclose($pipes[2]);
    return proc_close($process);
}
function txCrash($root, $mode, $cut) {
    $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) .
        ' --crash ' . escapeshellarg($root) . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($cut);
    $pipes = array();
    $process = proc_open($command, array(0 => array('file', '/dev/null', 'r'),
        1 => array('file', '/dev/null', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start crash child');
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 9) fwrite(STDERR, $errors);
    return $status;
}
$suite = new StrictTestSuite();
foreach (array('Copy', 'HardLink', 'SoftLink') as $mode) {
    $suite->test($mode . ' preflights later blocker', function () use ($mode) {
        $root = txFixture();
        try {
            strictRemoveTree($root . '/dst/item/b');
            file_put_contents($root . '/dst/item/b', 'foreign');
            strictAssertSame(false, @rtOpFiles(array('a/f','b/f'), $root . '/src', $root . '/dst/item', $mode), 'blocked');
            strictAssertSame(false, @lstat($root . '/dst/item/a/f'), 'earlier file absent');
            strictAssertSame('foreign', file_get_contents($root . '/dst/item/b'), 'blocker preserved');
        } finally { strictRemoveTree($root); }
    });
    $suite->test($mode . ' merges occupied destination', function () use ($mode) {
        $root = txFixture();
        try {
            file_put_contents($root . '/dst/item/a/f', 'old-alpha');
            file_put_contents($root . '/dst/item/b/f', 'old-beta');
            file_put_contents($root . '/dst/item/keep', 'foreign');
            strictAssertSame(true, rtOpFiles(array('a/f','b/f'), $root . '/src', $root . '/dst/item', $mode), 'operation');
            strictAssertSame('new-alpha', file_get_contents($root . '/dst/item/a/f'), 'first');
            strictAssertSame('new-beta', file_get_contents($root . '/dst/item/b/f'), 'second');
            strictAssertSame('foreign', file_get_contents($root . '/dst/item/keep'), 'unrelated');
            if ($mode === 'HardLink') strictAssertSame(fileinode($root . '/src/a/f'), fileinode($root . '/dst/item/a/f'), 'inode');
            if ($mode === 'SoftLink') strictAssertSame(true, is_link($root . '/dst/item/a/f'), 'symlink');
        } finally { strictRemoveTree($root); }
    });
    $suite->test($mode . ' leaves an actor-deleted old name absent', function () use ($mode) {
        $root = txFixture();
        try {
            $dest = $root . '/dst/item/a/f';
            file_put_contents($dest, 'old-alpha');
            DeleteOldNonMove::$dest = $dest;
            strictAssertSame(false, DeleteOldNonMove::run(array('a/f'), $root . '/src', $root . '/dst/item', $mode), 'missing old refuses');
            strictAssertSame(false, @lstat($dest), 'does not resurrect old name');
            strictAssertSame(false, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'), 'recovery holds');
            strictAssertSame(false, @lstat($dest), 'still absent');
        } finally { strictRemoveTree($root); }
    });
    $suite->test($mode . ' retains both foreign occupants when restore name is occupied', function () use ($mode) {
        $root = txFixture();
        try {
            $dest = $root . '/dst/item/a/f';
            file_put_contents($dest, 'old-alpha');
            RestoreCollisionNonMove::$dest = $dest;
            strictAssertSame(false, RestoreCollisionNonMove::run(array('a/f'), $root . '/src', $root . '/dst/item', $mode), 'collision refuses');
            strictAssertSame('foreign-current', file_get_contents($dest), 'new public occupant preserved');
            $removed = glob($root . '/dst/item/a/.autotools-stage-*-0/removed');
            strictAssertSame(1, count($removed), 'moved foreign inode retained');
            strictAssertSame('foreign-moved', file_get_contents($removed[0]), 'moved foreign content preserved');
            strictAssertSame(false, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'), 'recovery holds');
        } finally { strictRemoveTree($root); }
    });
    $suite->test($mode . ' restores old symlink on later collision', function () use ($mode) {
        $root = txFixture();
        try {
            symlink('missing-old-target', $root . '/dst/item/a/f');
            file_put_contents($root . '/dst/item/b/f', 'old-beta');
            RaceNonMove::$dest = $root . '/dst/item/b/f';
            strictAssertSame(false, RaceNonMove::run(array('a/f','b/f'), $root . '/src', $root . '/dst/item', $mode), 'collision refuses');
            strictAssertSame(true, is_link($root . '/dst/item/a/f'), 'old link restored');
            strictAssertSame('missing-old-target', readlink($root . '/dst/item/a/f'), 'link target preserved');
        } finally { strictRemoveTree($root); }
    });
    $suite->test($mode . ' restores a raced foreign file after a backup identity mismatch', function () use ($mode) {
        $root = txFixture();
        try {
            file_put_contents($root . '/dst/item/a/f', 'old-alpha');
            BackupRaceNonMove::$dest = $root . '/dst/item/a/f';
            BackupRaceNonMove::$killAfterMove = false;
            strictAssertSame(false, BackupRaceNonMove::run(array('a/f'), $root . '/src', $root . '/dst/item', $mode), 'race refuses');
            strictAssertSame('foreign-new', file_get_contents($root . '/dst/item/a/f'), 'foreign file restored');
            strictAssertSame('old-alpha', file_get_contents($root . '/dst/item/a/f.other-backup'), 'original old file held by actor');
            strictAssertSame(false, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'), 'ambiguous record remains visible');
        } finally { strictRemoveTree($root); }
    });
    $suite->test($mode . ' crash during raced backup restores foreign file on recovery', function () use ($mode) {
        $root = txFixture();
        try {
            file_put_contents($root . '/dst/item/a/f', 'old-alpha');
            strictAssertSame(9, txCrash($root, $mode, 'raced-backup'), 'child killed');
            strictAssertSame(false, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'), 'collision remains held');
            strictAssertSame('foreign-new', file_get_contents($root . '/dst/item/a/f'), 'foreign file restored');
            strictAssertSame('old-alpha', file_get_contents($root . '/dst/item/a/f.other-backup'), 'old file preserved');
        } finally { strictRemoveTree($root); }
    });
    $suite->test($mode . ' preserves a foreign later collision and rolls back earlier publication', function () use ($mode) {
        $root = txFixture();
        try {
            file_put_contents($root . '/dst/item/a/f', 'old-alpha');
            file_put_contents($root . '/dst/item/b/f', 'old-beta');
            RaceNonMove::$dest = $root . '/dst/item/b/f';
            strictAssertSame(false, RaceNonMove::run(array('a/f','b/f'), $root . '/src', $root . '/dst/item', $mode), 'collision refuses');
            strictAssertSame('old-alpha', file_get_contents($root . '/dst/item/a/f'), 'earlier old file restored');
            strictAssertSame('foreign-new', file_get_contents($root . '/dst/item/b/f'), 'foreign file preserved');
            strictAssertSame('old-beta', file_get_contents($root . '/dst/item/b/f.other-backup'), 'old later file retained');
            strictAssertSame(false, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'), 'pending ambiguous collision visible');
        } finally { strictRemoveTree($root); }
    });
    $unclaimedCuts = array('after-journal', 'after-stage-0', 'after-stage-1',
        'after-stage-file-0', 'after-stage-file-1', 'after-witness-0', 'after-witness-1');
    foreach (array_merge($unclaimedCuts, array('after-backup-unverified-0', 'after-backup-0',
        'after-publish-0', 'after-publish-1', 'after-committed', 'after-stage-cleanup')) as $cut) {
        $suite->test($mode . ' recovers or holds ' . $cut, function () use ($mode, $cut, $unclaimedCuts) {
            $root = txFixture();
            try {
                file_put_contents($root . '/dst/item/a/f', 'old-alpha');
                file_put_contents($root . '/dst/item/b/f', 'old-beta');
                strictAssertSame(9, txCrash($root, $mode, $cut), 'child killed');
                if (in_array($cut, $unclaimedCuts, true)) {
                    strictAssertSame('old-alpha', file_get_contents($root . '/dst/item/a/f'), 'old first still public');
                    strictAssertSame('old-beta', file_get_contents($root . '/dst/item/b/f'), 'old second still public');
                    FileUtil::$messages = array();
                    strictAssertSame(false, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'), 'unclaimed intent held');
                    strictAssertSame(1, count(FileUtil::$messages), 'one visible hold');
                    strictAssertSame(true, strpos(FileUtil::$messages[0], 'unclaimed intent') !== false, 'classified reason');
                } else {
                    strictAssertSame(true, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'), 'recovery');
                    strictAssertSame('new-alpha', file_get_contents($root . '/dst/item/a/f'), 'first');
                    strictAssertSame('new-beta', file_get_contents($root . '/dst/item/b/f'), 'second');
                }
            } finally { strictRemoveTree($root); }
        });
    }
    $suite->test($mode . ' does not claim a foreign destination after pre-claim crash', function () use ($mode) {
        $root = txFixture();
        try {
            file_put_contents($root . '/dst/item/a/f', 'old-alpha');
            strictAssertSame(9, txCrash($root, $mode, 'after-journal'), 'child killed');
            rename($root . '/dst/item/a/f', $root . '/dst/item/a/actor-backup');
            file_put_contents($root . '/dst/item/a/f', 'foreign-after-crash');
            FileUtil::$messages = array();
            strictAssertSame(false, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'), 'unclaimed intent held');
            strictAssertSame('foreign-after-crash', file_get_contents($root . '/dst/item/a/f'), 'foreign occupant preserved');
            strictAssertSame('old-alpha', file_get_contents($root . '/dst/item/a/actor-backup'), 'old occupant preserved');
            strictAssertSame(1, count(FileUtil::$messages), 'visible hold');
        } finally { strictRemoveTree($root); }
    });
}
$suite->test('normal preclaim refusal retires its journal so a changed operation can retry', function () {
    $root = txFixture();
    try {
        $source = $root . '/src/a/f';
        $dest = $root . '/dst/item/a/f';
        unlink($source);
        file_put_contents($dest, 'old-alpha');
        FileUtil::$messages = array();
        strictAssertSame(false, AutoToolsFileTransaction::run(array('a/f'), $root . '/src', $root . '/dst/item', 'HardLink'), 'missing source refused');
        strictAssertSame('old-alpha', file_get_contents($dest), 'old occupant preserved');
        strictAssertSame(array(), glob($root . '/session/.autotools-file-jobs/*.nonmove.json'), 'finished refusal has no stale intent');
        strictAssertSame(1, count(FileUtil::$messages), 'one visible refusal');
        strictAssertSame(true, strpos(FileUtil::$messages[0], 'refused: source unavailable for HardLink') !== false,
            'normal refusal is not described as a durable hold');
        file_put_contents($source, 'new-alpha');
        strictAssertSame(true, AutoToolsFileTransaction::run(array('a/f'), $root . '/src', $root . '/dst/item', 'Copy'), 'retry with Copy succeeds');
        strictAssertSame('new-alpha', file_get_contents($dest), 'retry published');
    } finally { strictRemoveTree($root); }
});
$suite->test('Copy refuses a source rewritten during staging even with unchanged size and mtime', function () {
    $root = txFixture();
    try {
        $dest = $root . '/dst/item/a/f';
        file_put_contents($dest, 'old-alpha');
        SourceChangeNonMove::$source = $root . '/src/a/f';
        FileUtil::$messages = array();
        strictAssertSame(false, SourceChangeNonMove::run(array('a/f'), $root . '/src', $root . '/dst/item', 'Copy'), 'changed source refused');
        strictAssertSame('old-alpha', file_get_contents($dest), 'old destination preserved');
        strictAssertSame('new-omega', file_get_contents($root . '/src/a/f'), 'source changed in place');
        strictAssertSame(1, count(FileUtil::$messages), 'one visible refusal');
        strictAssertSame(true, strpos(FileUtil::$messages[0], 'source changed or unreadable during Copy') !== false,
            'classified source reason');
    } finally { strictRemoveTree($root); }
});
$suite->test('Copy refuses a replaced source inode even when contents match', function () {
    $root = txFixture();
    try {
        $source = $root . '/src/a/f';
        $dest = $root . '/dst/item/a/f';
        file_put_contents($dest, 'old-alpha');
        SourceSwapNonMove::$source = $source;
        strictAssertSame(false, SourceSwapNonMove::run(array('a/f'), $root . '/src', $root . '/dst/item', 'Copy'), 'source replacement refused');
        strictAssertSame('old-alpha', file_get_contents($dest), 'old destination preserved');
        strictAssertSame('new-alpha', file_get_contents($source), 'new source bytes equal');
        strictAssertSame('new-alpha', file_get_contents($source . '.original'), 'original inode retained by actor');
    } finally { strictRemoveTree($root); }
});
$suite->test('Copy refuses an earlier source rewritten while later files are staged', function () {
    $root = txFixture();
    try {
        file_put_contents($root . '/dst/item/a/f', 'old-alpha');
        EarlierSourceChangeNonMove::$source = $root . '/src/a/f';
        FileUtil::$messages = array();
        strictAssertSame(false, EarlierSourceChangeNonMove::run(array('a/f', 'b/f'), $root . '/src', $root . '/dst/item', 'Copy'), 'cross-file source change refused');
        strictAssertSame('old-alpha', file_get_contents($root . '/dst/item/a/f'), 'old destination preserved');
        strictAssertSame(false, file_exists($root . '/dst/item/b/f'), 'second destination absent');
        strictAssertSame(1, count(FileUtil::$messages), 'one visible refusal');
    } finally { strictRemoveTree($root); }
});
$suite->test('SoftLink can preserve a dangling source link', function () {
    $root = txFixture();
    try {
        strictAssertSame(true, rtOpFiles(array('a/missing'), $root . '/src', $root . '/dst/item', 'SoftLink'), 'link');
        strictAssertSame(true, is_link($root . '/dst/item/a/missing'), 'dangling link');
    } finally { strictRemoveTree($root); }
});
$suite->test('Copy accepts a configured symlink to a protected destination', function () {
    $root = txFixture();
    try {
        symlink($root . '/dst/item', $root . '/dst/shortcut');
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/shortcut', 'Copy'), 'safe target');
        strictAssertSame('new-alpha', file_get_contents($root . '/dst/item/a/f'), 'canonical destination');
    } finally { strictRemoveTree($root); }
});
$suite->test('Copy accepts a protected symlink in a configured destination ancestor', function () {
    $root = txFixture();
    try {
        symlink($root . '/dst', $root . '/alias');
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/alias/item', 'Copy'), 'safe ancestor');
        strictAssertSame('new-alpha', file_get_contents($root . '/dst/item/a/f'), 'canonical destination');
    } finally { strictRemoveTree($root); }
});
$suite->test('empty file list stays a no-op', function () {
    $root = txFixture();
    try { strictAssertSame(true, rtOpFiles(array(), $root . '/src', $root . '/dst/item', 'Copy'), 'no-op'); }
    finally { strictRemoveTree($root); }
});
$suite->test('unsafe destination refuses before a public file is written', function () {
    $root = txFixture();
    try {
        FileUtil::$messages = array();
        chmod($root . '/dst/item', 0777);
        strictAssertSame(false, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item', 'Copy'), 'refusal');
        strictAssertSame(false, @lstat($root . '/dst/item/a/f'), 'no public file');
        strictAssertSame(array('autotools: nonmove new refused: untrusted-directory path='
            . erasedataLogPath($root . '/dst/item')), FileUtil::$messages, 'this refusal names its exact unsafe path');
    } finally { strictRemoveTree($root); }
});
$suite->test('corrupt journal yields a visible hold', function () {
    $root = txFixture();
    try {
        $journal = $root . '/session/.autotools-file-jobs';
        mkdir($journal, 0700);
        file_put_contents($journal . '/abcdefabcdefabcdefabcdef.nonmove.json', '{}');
        FileUtil::$messages = array();
        strictAssertSame(false, AutoToolsFileTransaction::recoverAll($journal), 'hold');
        strictAssertSame(true, count(FileUtil::$messages) > 0, 'visible hold');
        strictAssertSame(true, file_exists($journal . '/abcdefabcdefabcdefabcdef.nonmove.json'), 'preserved record');
    } finally { strictRemoveTree($root); }
});
$suite->test('malformed journal indexes hold without evaluating a partial job', function () {
    $root = txFixture();
    try {
        $journal = $root . '/session/.autotools-file-jobs';
        mkdir($journal, 0700);
        $id = 'abcdefabcdefabcdefabcdef';
        file_put_contents($journal . '/' . $id . '.nonmove.json', json_encode(array(
            'id' => $id, 'phase' => 'prepared', 'src' => $root . '/src',
            'dst' => $root . '/dst/item', 'op' => 'Copy',
            'files' => array('bad' => 'a/f'), 'old' => array('bad' => null),
            'new' => array('bad' => array(1, 2, 0100000)))));
        FileUtil::$messages = array();
        strictAssertSame(false, AutoToolsFileTransaction::recoverAll($journal), 'malformed record held');
        strictAssertSame(1, count(FileUtil::$messages), 'one classified log');
        strictAssertSame(false, @lstat($root . '/dst/item/a/f'), 'no public mutation');
    } finally { strictRemoveTree($root); }
});
$suite->test('corrupt associative journal key cannot traverse stage into unrelated file', function () {
    $root = txFixture();
    try {
        $journal = $root . '/session/.autotools-file-jobs';
        mkdir($journal, 0700);
        $id = 'abcabcabcabcabcabcabcabc';
        file_put_contents($root . '/src/payload', 'new-payload');
        file_put_contents($root . '/dst/item/keep', 'unrelated-original');
        file_put_contents($journal . '/' . $id . '.nonmove.json', json_encode(array(
            'id' => $id, 'phase' => 'staging', 'src' => $root . '/src',
            'dst' => $root . '/dst/item', 'op' => 'Copy',
            'files' => array('../../keep' => 'payload'), 'old' => array(null),
            'new' => array())));
        FileUtil::$messages = array();
        strictAssertSame(false, AutoToolsFileTransaction::recoverAll($journal), 'corrupt key held');
        strictAssertSame('unrelated-original', file_get_contents($root . '/dst/item/keep'), 'unrelated file preserved');
        strictAssertSame(1, count(FileUtil::$messages), 'visible hold');
    } finally { strictRemoveTree($root); }
});
$suite->test('invalid relative path refusal is logged', function () {
    $root = txFixture();
    try {
        FileUtil::$messages = array();
        strictAssertSame(false, rtOpFiles(array('../escape'), $root . '/src', $root . '/dst/item', 'Copy'), 'invalid');
        strictAssertSame(1, count(FileUtil::$messages), 'classified log');
    } finally { strictRemoveTree($root); }
});
$suite->test('unreadable journal directory is a visible recovery refusal', function () {
    $root = txFixture();
    $journal = $root . '/session/.autotools-file-jobs';
    try {
        mkdir($journal, 0700);
        file_put_contents($journal . '/.lock', '');
        chmod($journal, 0300);
        FileUtil::$messages = array();
        strictAssertSame(false, AutoToolsFileTransaction::recoverAll($journal), 'unreadable journal refused');
        strictAssertSame(1, count(FileUtil::$messages), 'one visible refusal');
        strictAssertSame(true, strpos(FileUtil::$messages[0], 'cannot scan pending records') !== false,
            'classified unreadable scan reason');
    } finally {
        chmod($journal, 0700);
        strictRemoveTree($root);
    }
});
$suite->test('held destination does not block an unrelated destination', function () {
    $root = txFixture();
    try {
        $journal = $root . '/session/.autotools-file-jobs';
        mkdir($journal, 0700);
        $id = 'fedcbafedcbafedcbafedcba';
        file_put_contents($journal . '/' . $id . '.nonmove.json', json_encode(array(
            'id' => $id, 'phase' => 'prepared', 'src' => $root . '/src',
            'dst' => $root . '/dst/item', 'op' => 'Copy',
            'files' => array('a/f'), 'old' => array(null),
            'new' => array(array(1, 2, 0100000)))));
        mkdir($root . '/other/a', 0700, true);
        chmod($root . '/other', 0700);
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/other', 'Copy'), 'unrelated job proceeds');
        strictAssertSame('new-alpha', file_get_contents($root . '/other/a/f'), 'unrelated payload');
        strictAssertSame(false, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item', 'Copy'), 'same destination held');
        strictAssertSame(false, @lstat($root . '/dst/item/a/f'), 'held destination unchanged');
    } finally { strictRemoveTree($root); }
});
$suite->test('Copy preserves umask', function () {
    $root = txFixture(); $saved = umask(0077);
    try {
        chmod($root . '/src/a/f', 0644);
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item', 'Copy'), 'copy');
        clearstatcache(true, $root . '/dst/item/a/f');
        strictAssertSame(0600, fileperms($root . '/dst/item/a/f') & 0777, 'effective umask');
    } finally { umask($saved); strictRemoveTree($root); }
});
function txCallerContext($root) {
    return array('hash' => str_repeat('A', 40),
        'token' => str_repeat('b', 32),
        'sourceBase' => $root . '/src/a/f',
        'destination' => $root . '/dst/item/');
}
function txWriteSidecar($context, $xdest) {
    $torrent = Torrent::fromRawBytes('de');
    $torrent->setMeta('custom', array(
        'x-autotools-nonmove-job' => $context['token'],
        'x-autotools-nonmove-source' => $context['sourceBase'],
        'x-autotools-nonmove-target' => $context['destination'],
        'x-dest' => $xdest,
    ));
    file_put_contents(rTorrentSettings::get()->session . '/' . $context['hash'] . '.torrent.rtorrent',
        (string) $torrent);
}
foreach (array('HardLink', 'Copy') as $nextMode) {
    $suite->test('pending Copy receipt permits disjoint ' . $nextMode . ' in the same destination', function () use ($nextMode) {
        $root = txFixture();
        try {
            $first = txCallerContext($root);
            $second = $first;
            $second['hash'] = str_repeat('C', 40);
            $second['token'] = str_repeat('d', 32);
            $second['sourceBase'] = $root . '/src/b/f';
            strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
                'Copy', false, $first), 'first Copy publishes and awaits x-dest acknowledgement');
            strictAssertSame(1, count(glob($root . '/session/.autotools-file-jobs/*.nonmove.json')),
                'first receipt is still pending');
            FileUtil::$messages = array();
            rXMLRPCRequest::reset();
            strictAssertSame(true, rtOpFiles(array('b/f'), $root . '/src', $root . '/dst/item',
                $nextMode, false, $second), 'disjoint second job must not be lost');
            strictAssertSame(array(), rXMLRPCRequest::$requests,
                'pending acknowledgement must not trigger synchronous daemon RPC');
            strictAssertSame('new-alpha', file_get_contents($root . '/dst/item/a/f'), 'first file');
            strictAssertSame('new-beta', file_get_contents($root . '/dst/item/b/f'), 'second file');
            if ($nextMode === 'HardLink')
                strictAssertSame(fileinode($root . '/src/b/f'), fileinode($root . '/dst/item/b/f'),
                    'HardLink keeps source inode');
            strictAssertSame(2, count(glob($root . '/session/.autotools-file-jobs/*.nonmove.json')),
                'both caller receipts remain available for recovery');
        } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
    });
}
// Final names must not depend on the locale: the hook runs under rtorrent, in
// C when rtorrent runs without LANG, and there PHP 7.4's basename() named both
// a/Сериал and a/Фильм "a", so the second job waited for the first.
$suite->test('pending Copy receipt permits a disjoint non-ASCII name in the C locale', function () {
    $root = txFixture();
    $locale = setlocale(LC_CTYPE, '0');
    try {
        file_put_contents($root . '/src/a/Сериал', 'new-alpha');
        file_put_contents($root . '/src/a/Фильм', 'new-beta');
        $first = txCallerContext($root);
        $second = $first;
        $second['hash'] = str_repeat('C', 40);
        $second['token'] = str_repeat('d', 32);
        setlocale(LC_CTYPE, 'C');
        strictAssertSame(true, rtOpFiles(array('a/Сериал'), $root . '/src', $root . '/dst/item',
            'Copy', false, $first), 'first Copy');
        strictAssertSame(true, rtOpFiles(array('a/Фильм'), $root . '/src', $root . '/dst/item',
            'Copy', false, $second), 'a file of another name is not the same final name');
        strictAssertSame('new-alpha', file_get_contents($root . '/dst/item/a/Сериал'), 'first file');
        strictAssertSame('new-beta', file_get_contents($root . '/dst/item/a/Фильм'), 'second file');
    } finally { setlocale(LC_CTYPE, $locale); rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('pending Copy receipt permits a new nested destination parent', function () {
    $root = txFixture();
    try {
        strictRemoveTree($root . '/dst/item/b');
        $first = txCallerContext($root);
        $second = $first;
        $second['hash'] = str_repeat('C', 40);
        $second['token'] = str_repeat('d', 32);
        $second['sourceBase'] = $root . '/src/b/f';
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $first), 'first Copy');
        strictAssertSame(true, rtOpFiles(array('b/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $second), 'new disjoint parent is created before physical overlap check');
        strictAssertSame('new-alpha', file_get_contents($root . '/dst/item/a/f'), 'first file');
        strictAssertSame('new-beta', file_get_contents($root . '/dst/item/b/f'), 'nested second file');
    } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('pending Copy receipt sees a protected destination symlink alias', function () {
    $root = txFixture();
    try {
        strictRemoveTree($root . '/dst/item/b');
        symlink($root . '/dst/item/a', $root . '/dst/item/b');
        $first = txCallerContext($root);
        $second = $first;
        $second['hash'] = str_repeat('C', 40);
        $second['token'] = str_repeat('d', 32);
        $second['sourceBase'] = $root . '/src/b/f';
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $first), 'first Copy');
        FileUtil::$messages = array();
        strictAssertSame(false, rtOpFiles(array('b/f'), $root . '/src', $root . '/dst/item',
            'HardLink', false, $second), 'alias is deferred without changing the first public name');
        strictAssertSame('new-alpha', file_get_contents($root . '/dst/item/a/f'),
            'first published byte remains intact');
        strictAssertSame(2, count(glob($root . '/session/.autotools-file-jobs/*.nonmove.json')),
            'alias creates a durable deferred receipt');
    } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('pending Copy receipt permits replacing a separate hardlink name', function () {
    $root = txFixture();
    try {
        $first = txCallerContext($root);
        $second = $first;
        $second['hash'] = str_repeat('C', 40);
        $second['token'] = str_repeat('d', 32);
        $second['sourceBase'] = $root . '/src/b/f';
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $first), 'first Copy');
        link($root . '/dst/item/a/f', $root . '/dst/item/b/f');
        strictAssertSame(true, rtOpFiles(array('b/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $second), 'another hardlink name has its own publication slot');
        strictAssertSame('new-alpha', file_get_contents($root . '/dst/item/a/f'), 'first name remains');
        strictAssertSame('new-beta', file_get_contents($root . '/dst/item/b/f'), 'second name updated');
        strictAssertSame(2, count(glob($root . '/session/.autotools-file-jobs/*.nonmove.json')),
            'both jobs retain their independent receipts');
    } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('normal NonMove repeat sends one completion notice after durable ACK', function () {
    $root = txFixture();
    try {
        file_put_contents($root . '/dst/item/.mailto', 'TO: local-test\n');
        rAutoTools::$sent = array();
        $context = txCallerContext($root);
        $context['notice'] = array('noticeDestination' => $root . '/dst/item',
            'finishedRoot' => $root . '/dst', 'torrentName' => 'Synthetic A');
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $context), 'normal first hook publishes');
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $context), 'same committed hook returns its x-dest');
        strictAssertSame(array(), rAutoTools::$sent, 'hook does not send before disk ACK');
        txWriteSidecar($context, $context['destination']);
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array('0', '0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_SET'));
        rXMLRPCRequest::queue('d.save_full_session', true, false, array('0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_ALREADY'));
        strictAssertSame(true, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'),
            'disk ACK and notice retire receipt');
        strictAssertSame(array(array($root . '/dst/item', $root . '/dst', 'Synthetic A')),
            rAutoTools::$sent, 'existing helper is invoked exactly once with legacy mail path');
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $context), 'repeat after retirement keeps its x-dest');
        strictAssertSame(1, count(rAutoTools::$sent), 'retired repeat does not send again');
        strictAssertSame(array(), glob($root . '/session/.autotools-file-jobs/*.nonmove.json'),
            'repeat does not make a new journal');
    } finally { rAutoTools::$sent = array(); rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('same-final one-shot hook persists a deferred second job until predecessor ACK', function () {
    $root = txFixture();
    try {
        mkdir($root . '/src2/a', 0700, true);
        file_put_contents($root . '/src2/a/f', 'second-payload');
        $first = txCallerContext($root);
        $second = $first;
        $second['hash'] = str_repeat('C', 40);
        $second['token'] = str_repeat('d', 32);
        $second['sourceBase'] = $root . '/src2/a/f';
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $first), 'first file is published');
        rXMLRPCRequest::reset();
        strictAssertSame(false, rtOpFiles(array('a/f'), $root . '/src2', $root . '/dst/item',
            'HardLink', false, $second), 'second hook defers until first metadata ACK');
        strictAssertSame(array(), rXMLRPCRequest::$requests, 'hook does not call daemon RPC');
        strictAssertSame('new-alpha', file_get_contents($root . '/dst/item/a/f'),
            'predecessor stays public until its ACK');
        $records = array_map(function ($path) { return json_decode(file_get_contents($path), true); },
            glob($root . '/session/.autotools-file-jobs/*.nonmove.json'));
        strictAssertSame(2, count($records), 'second one-shot hook has a durable receipt');
        $queued = array_values(array_filter($records, function ($record) use ($second) {
            return $record['context'] === $second;
        }));
        strictAssertSame(1, count($queued), 'second receipt belongs to its daemon generation');
        strictAssertSame('prepared', $queued[0]['phase'], 'second source and old public file are claimed');
        strictAssertSame(1, count($queued[0]['waitFor']), 'second job awaits predecessor receipt');
        txWriteSidecar($first, $first['destination']);
        txWriteSidecar($second, $second['destination']);
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array('0', '0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_SET'));
        rXMLRPCRequest::queue('d.save_full_session', true, false, array('0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_ALREADY'));
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array('0', '0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_ALREADY'));
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array('0', '0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_SET'));
        rXMLRPCRequest::queue('d.save_full_session', true, false, array('0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_ALREADY'));
        for ($round = 0; $round < 3 && glob($root . '/session/.autotools-file-jobs/*.nonmove.json'); ++$round)
            AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs');
        strictAssertSame(array(), glob($root . '/session/.autotools-file-jobs/*.nonmove.json'),
            'both receipts retire after their own metadata ACK');
        strictAssertSame('second-payload', file_get_contents($root . '/dst/item/a/f'),
            'deferred second payload becomes public after predecessor ACK');
        strictAssertSame(fileinode($root . '/src2/a/f'), fileinode($root . '/dst/item/a/f'),
            'deferred HardLink keeps source inode');
    } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('crash after notice attempt never resends an uncertain mail', function () {
    $root = txFixture();
    try {
        file_put_contents($root . '/dst/item/.mailto', 'TO: local-test\n');
        rAutoTools::$sent = array();
        $context = txCallerContext($root);
        $context['notice'] = array('noticeDestination' => $root . '/dst/item',
            'finishedRoot' => $root . '/dst', 'torrentName' => 'Synthetic A');
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $context), 'published before notice cut');
        txWriteSidecar($context, $context['destination']);
        $path = glob($root . '/session/.autotools-file-jobs/*.nonmove.json')[0];
        $record = json_decode(file_get_contents($path), true);
        $record['phase'] = 'notice-pending';
        file_put_contents($path, json_encode($record));
        $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) .
            ' --crash-notice ' . escapeshellarg($root);
        $pipes = array();
        $process = proc_open($command, array(0 => array('file', '/dev/null', 'r'),
            1 => array('file', '/dev/null', 'w'), 2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start notice crash child');
        stream_get_contents($pipes[2]); fclose($pipes[2]);
        strictAssertSame(9, proc_close($process), 'child killed after durable notice-attempt record');
        strictAssertSame('notice-attempted', json_decode(file_get_contents($path), true)['phase'],
            'attempt receipt survives crash before transport');
        FileUtil::$messages = array();
        strictAssertSame(false, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'),
            'uncertain notice outcome holds for inspection');
        strictAssertSame(array(), rAutoTools::$sent, 'recovery never resends uncertain mail');
        strictAssertSame(true, strpos(implode('\n', FileUtil::$messages), 'outcome uncertain') !== false,
            'hold explains ambiguous mail outcome');
    } finally { rAutoTools::$sent = array(); rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('ambiguous notice keeps a later same-final hook durably queued', function () {
    $root = txFixture();
    try {
        mkdir($root . '/src2/a', 0700, true);
        file_put_contents($root . '/src2/a/f', 'second-payload');
        file_put_contents($root . '/dst/item/.mailto', 'TO: local-test\n');
        rAutoTools::$sent = array();
        $first = txCallerContext($root);
        $first['notice'] = array('noticeDestination' => $root . '/dst/item',
            'finishedRoot' => $root . '/dst', 'torrentName' => 'Synthetic A');
        $second = txCallerContext($root);
        $second['hash'] = str_repeat('C', 40);
        $second['token'] = str_repeat('d', 32);
        $second['sourceBase'] = $root . '/src2/a/f';
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $first), 'first publishes');
        txWriteSidecar($first, $first['destination']);
        $path = glob($root . '/session/.autotools-file-jobs/*.nonmove.json')[0];
        $record = json_decode(file_get_contents($path), true);
        $record['phase'] = 'notice-attempted';
        file_put_contents($path, json_encode($record));
        strictAssertSame(false, rtOpFiles(array('a/f'), $root . '/src2', $root . '/dst/item',
            'HardLink', false, $second), 'second remains physically deferred');
        $records = array_map(function ($item) { return json_decode(file_get_contents($item), true); },
            glob($root . '/session/.autotools-file-jobs/*.nonmove.json'));
        strictAssertSame(2, count($records), 'later one-shot hook has its own durable intent');
        $queued = array_values(array_filter($records, function ($item) use ($second) {
            return $item['context'] === $second;
        }));
        strictAssertSame(1, count($queued), 'successor generation identified');
        strictAssertSame($record['id'], $queued[0]['waitFor'][0]['id'],
            'later job waits for manual resolution of uncertain notice');
        strictAssertSame('new-alpha', file_get_contents($root . '/dst/item/a/f'),
            'ambiguous predecessor stays public');
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $first), 'repeat first hook preserves its existing x-dest');
        strictAssertSame(array(), rAutoTools::$sent, 'no uncertain notice is resent');
        strictAssertSame(2, count(glob($root . '/session/.autotools-file-jobs/*.nonmove.json')),
            'repeat did not add a third receipt');
    } finally { rAutoTools::$sent = array(); rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('deferred same-final job sends one notice after its own ACK', function () {
    $root = txFixture();
    try {
        mkdir($root . '/src2/a', 0700, true);
        file_put_contents($root . '/src2/a/f', 'second-payload');
        file_put_contents($root . '/dst/item/.mailto', 'TO: local-test\n');
        rAutoTools::$sent = array();
        $first = txCallerContext($root);
        $second = $first;
        $second['hash'] = str_repeat('C', 40);
        $second['token'] = str_repeat('d', 32);
        $second['sourceBase'] = $root . '/src2/a/f';
        $second['notice'] = array('noticeDestination' => $root . '/dst/item',
            'finishedRoot' => $root . '/dst', 'torrentName' => 'Synthetic B');
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $first), 'first publishes');
        strictAssertSame(false, rtOpFiles(array('a/f'), $root . '/src2', $root . '/dst/item',
            'HardLink', false, $second), 'second queues');
        strictAssertSame(array(), rAutoTools::$sent, 'no notice before physical publish');
        txWriteSidecar($first, $first['destination']);
        txWriteSidecar($second, $second['destination']);
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array('0', '0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_SET'));
        rXMLRPCRequest::queue('d.save_full_session', true, false, array('0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_ALREADY'));
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array('0', '0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_ALREADY'));
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array('0', '0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_SET'));
        rXMLRPCRequest::queue('d.save_full_session', true, false, array('0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_ALREADY'));
        for ($round = 0; $round < 3 && glob($root . '/session/.autotools-file-jobs/*.nonmove.json'); ++$round)
            AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs');
        strictAssertSame(array(array($root . '/dst/item', $root . '/dst', 'Synthetic B')),
            rAutoTools::$sent, 'only the deferred successor sends one notice');
        strictAssertSame(array(), glob($root . '/session/.autotools-file-jobs/*.nonmove.json'),
            'both receipts retire after ACK and notice');
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src2', $root . '/dst/item',
            'HardLink', false, $second), 'repeat after retirement is idempotent');
        strictAssertSame(1, count(rAutoTools::$sent), 'repeat does not resend notice');
    } finally { rAutoTools::$sent = array(); rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('queued successor with a lost persisted marker holds before publication', function () {
    $root = txFixture();
    try {
        mkdir($root . '/src2/a', 0700, true);
        file_put_contents($root . '/src2/a/f', 'second-payload');
        $first = txCallerContext($root);
        $second = $first;
        $second['hash'] = str_repeat('C', 40);
        $second['token'] = str_repeat('d', 32);
        $second['sourceBase'] = $root . '/src2/a/f';
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $first), 'first publishes');
        strictAssertSame(false, rtOpFiles(array('a/f'), $root . '/src2', $root . '/dst/item',
            'Copy', false, $second), 'second queues');
        txWriteSidecar($first, $first['destination']);
        $foreign = $second;
        $foreign['token'] = str_repeat('e', 32);
        txWriteSidecar($foreign, $second['destination']);
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array('0', '0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_SET'));
        rXMLRPCRequest::queue('d.save_full_session', true, false, array('0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_ALREADY'));
        AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs');
        FileUtil::$messages = array();
        strictAssertSame(false, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'),
            'lost B marker blocks delayed publication');
        strictAssertSame('new-alpha', file_get_contents($root . '/dst/item/a/f'),
            'old public file stays intact');
        strictAssertSame(1, count(glob($root . '/session/.autotools-file-jobs/*.nonmove.json')),
            'B receipt remains for visible recovery');
        strictAssertSame(true, strpos(implode('\n', FileUtil::$messages),
            'queued torrent generation or source changed') !== false, 'marker hold logged');
    } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('three same-final one-shot hooks form a durable ordered chain', function () {
    $root = txFixture();
    try {
        mkdir($root . '/src2/a', 0700, true);
        mkdir($root . '/src3/a', 0700, true);
        file_put_contents($root . '/src2/a/f', 'second-payload');
        file_put_contents($root . '/src3/a/f', 'third-payload');
        $first = txCallerContext($root);
        $second = $first;
        $second['hash'] = str_repeat('C', 40);
        $second['token'] = str_repeat('d', 32);
        $second['sourceBase'] = $root . '/src2/a/f';
        $third = $first;
        $third['hash'] = str_repeat('E', 40);
        $third['token'] = str_repeat('f', 32);
        $third['sourceBase'] = $root . '/src3/a/f';
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $first), 'first publishes');
        strictAssertSame(false, rtOpFiles(array('a/f'), $root . '/src2', $root . '/dst/item',
            'HardLink', false, $second), 'second is queued');
        strictAssertSame(false, rtOpFiles(array('a/f'), $root . '/src3', $root . '/dst/item',
            'Copy', false, $third), 'third is queued behind second');
        strictAssertSame('new-alpha', file_get_contents($root . '/dst/item/a/f'),
            'predecessor remains public');
        $records = array_map(function ($path) { return json_decode(file_get_contents($path), true); },
            glob($root . '/session/.autotools-file-jobs/*.nonmove.json'));
        strictAssertSame(3, count($records), 'all one-shot hooks have durable receipts');
        $ids = array();
        foreach ($records as $record) $ids[$record['context']['hash']] = $record;
        strictAssertSame($ids[$first['hash']]['id'], $ids[$second['hash']]['waitFor'][0]['id'],
            'second waits for first');
        strictAssertSame($ids[$second['hash']]['id'], $ids[$third['hash']]['waitFor'][0]['id'],
            'third waits for second, not the already superseded first');
        strictAssertSame($ids[$second['hash']]['new'][0], $ids[$third['hash']]['old'][0],
            'third holds a witness to the second staged output');
    } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('repeated queued hook keeps one receipt for its generation', function () {
    $root = txFixture();
    try {
        mkdir($root . '/src2/a', 0700, true);
        file_put_contents($root . '/src2/a/f', 'second-payload');
        $first = txCallerContext($root);
        $second = $first;
        $second['hash'] = str_repeat('C', 40);
        $second['token'] = str_repeat('d', 32);
        $second['sourceBase'] = $root . '/src2/a/f';
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $first), 'first publishes');
        strictAssertSame(false, rtOpFiles(array('a/f'), $root . '/src2', $root . '/dst/item',
            'HardLink', false, $second), 'second queues');
        // A queued receipt is published by recovery: nothing for the hook to
        // name on the download as a refusal.
        strictAssertSame(null, AutoToolsFileTransaction::lastRefusal(), 'a queued hook is no refusal');
        strictAssertSame(false, rtOpFiles(array('a/f'), $root . '/src2', $root . '/dst/item',
            'HardLink', false, $second), 'repeated queued hook still waits');
        strictAssertSame(null, AutoToolsFileTransaction::lastRefusal(), 'nor is its repetition');
        strictAssertSame(2, count(glob($root . '/session/.autotools-file-jobs/*.nonmove.json')),
            'one receipt per generation, not a duplicate chain node');
        strictAssertSame('new-alpha', file_get_contents($root . '/dst/item/a/f'),
            'repeat hook does not publish before predecessor ACK');
    } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('queued HardLink holds when source path is replaced before predecessor ACK', function () {
    $root = txFixture();
    try {
        mkdir($root . '/src2/a', 0700, true);
        file_put_contents($root . '/src2/a/f', 'second-payload');
        $first = txCallerContext($root);
        $second = $first;
        $second['hash'] = str_repeat('C', 40);
        $second['token'] = str_repeat('d', 32);
        $second['sourceBase'] = $root . '/src2/a/f';
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $first), 'first publishes');
        strictAssertSame(false, rtOpFiles(array('a/f'), $root . '/src2', $root . '/dst/item',
            'HardLink', false, $second), 'second stages before waiting');
        rename($root . '/src2/a/f', $root . '/src2/a/f.original');
        file_put_contents($root . '/src2/a/f', 'foreign-source');
        txWriteSidecar($first, $first['destination']);
        txWriteSidecar($second, $second['destination']);
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array('0', '0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_SET'));
        rXMLRPCRequest::queue('d.save_full_session', true, false, array('0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_ALREADY'));
        AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs');
        FileUtil::$messages = array();
        strictAssertSame(false, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'),
            'recovery holds the replaced HardLink source');
        strictAssertSame('new-alpha', file_get_contents($root . '/dst/item/a/f'),
            'stale staged inode was not published');
        strictAssertSame(1, count(glob($root . '/session/.autotools-file-jobs/*.nonmove.json')),
            'queued successor receipt remains inspectable');
        strictAssertSame(true, strpos(implode('\n', FileUtil::$messages),
            'queued HardLink source changed') !== false, 'classified hold is visible');
    } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('pending Copy receipt visibly refuses a different job for the same file', function () {
    $root = txFixture();
    try {
        $first = txCallerContext($root);
        $second = $first;
        $second['hash'] = str_repeat('C', 40);
        $second['token'] = str_repeat('d', 32);
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $first), 'first Copy');
        FileUtil::$messages = array();
        strictAssertSame(false, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'HardLink', false, $second), 'same public name remains held');
        strictAssertSame(2, count(glob($root . '/session/.autotools-file-jobs/*.nonmove.json')),
            'colliding second job waits with a durable receipt');
        strictAssertSame('new-alpha', file_get_contents($root . '/dst/item/a/f'), 'original byte preserved');
        strictAssertSame(true, strpos(implode('\n', FileUtil::$messages), $second['hash']) !== false,
            'refused torrent hash appears in classified log');
    } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('successful daemon save with stale session sidecar keeps the NonMove receipt', function () {
    $root = txFixture();
    try {
        $context = txCallerContext($root);
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $context), 'physical Copy');
        $sidecar = $root . '/session/' . $context['hash'] . '.torrent.rtorrent';
        file_put_contents($sidecar, 'd6:customd6:x-dest0:ee');
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array('0', '0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_SET'));
        rXMLRPCRequest::queue('d.save_full_session', true, false, array('0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_ALREADY'));
        FileUtil::$messages = array();
        strictAssertSame(false, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'),
            'daemon memory acknowledgement cannot retire an unsaved receipt');
        strictAssertSame(1, count(glob($root . '/session/.autotools-file-jobs/*.nonmove.json')),
            'journal remains available after daemon restart');
        strictAssertSame(true, count(FileUtil::$messages) > 0, 'refusal is visible');
    } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('matching sidecar x-dest from another generation keeps the NonMove receipt', function () {
    $root = txFixture();
    try {
        $context = txCallerContext($root);
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $context), 'physical Copy');
        $foreign = $context;
        $foreign['token'] = str_repeat('c', 32);
        txWriteSidecar($foreign, $context['destination']);
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array('0', '0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_SET'));
        rXMLRPCRequest::queue('d.save_full_session', true, false, array('0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_ALREADY'));
        strictAssertSame(false, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'),
            'different persisted token cannot acknowledge our journal');
        strictAssertSame(1, count(glob($root . '/session/.autotools-file-jobs/*.nonmove.json')),
            'original generation receipt remains');
    } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('NonMove keeps caller receipt until x-dest is durably acknowledged', function () {
    $root = txFixture();
    try {
        rXMLRPCRequest::reset();
        $context = txCallerContext($root);
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $context), 'physical Copy');
        strictAssertSame(array(), rXMLRPCRequest::$requests, 'finished-hook worker never re-enters daemon RPC');
        $paths = glob($root . '/session/.autotools-file-jobs/*.nonmove.json');
        strictAssertSame(1, count($paths), 'caller receipt retained before hook captures stdout');
        $record = json_decode(file_get_contents($paths[0]), true);
        strictAssertSame($context, $record['context'], 'durable caller identity and exact x-dest');
        txWriteSidecar($context, $context['destination']);
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array('0', '0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_SET'));
        rXMLRPCRequest::queue('d.save_full_session', true, false, array('0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_ALREADY'));
        strictAssertSame(true, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'),
            'metadata replay');
        strictAssertSame(array(), glob($root . '/session/.autotools-file-jobs/*.nonmove.json'),
            'journal cleared after durable daemon acknowledgement');
        $branches = rXMLRPCRequest::requestsFor('branch');
        strictAssertSame(2, count($branches), 'atomic set and final ownership readback');
        $q = array('rTorrent', 'quoteCommandArg');
        $guards = array(
            'equal=d.get_custom=x-autotools-nonmove-job,cat=' . $context['token'],
            'equal=d.get_base_path=,d.get_custom=x-autotools-nonmove-source',
        );
        foreach (array(0 => 'cat=', 1 => 'd.get_custom=x-autotools-nonmove-target') as $index => $expected) {
            $condition = 'and=' . implode(',', array_map($q, array_merge($guards,
                array('equal=d.get_custom=x-dest,' . $expected))));
            $body = $index === 0 ? 'cat=' . call_user_func($q,
                '$d.set_custom=x-dest,$d.get_custom=x-autotools-nonmove-target') . ',AUTOTOOLS_XDEST_SET'
                : 'cat=AUTOTOOLS_XDEST_ALREADY';
            strictAssertSame(array($context['hash'], $condition, $body, 'cat=AUTOTOOLS_XDEST_SKIP'),
                $branches[$index]['commands'][0]->params, 'exact atomic set or ownership-readback branch ' . $index);
        }
        $stages = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom');
        strictAssertSame(1, count($stages), 'one paired path staging request');
        strictAssertSame(array($context['hash'], 'x-autotools-nonmove-source', $context['sourceBase']),
            $stages[0]['commands'][0]->params, 'exact source staging target, key and bytes');
        strictAssertSame(array($context['hash'], 'x-autotools-nonmove-target', $context['destination']),
            $stages[0]['commands'][1]->params, 'exact destination staging target, key and bytes');
    } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('Copy creates nested destination without a group-writable transaction ancestor', function () {
    $root = txFixture(); $saved = umask(0002);
    try {
        $destination = $root . '/dst/newroot';
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $destination, 'Copy'),
            'new nested destination is supported under ordinary umask 0002');
        clearstatcache(true, $destination . '/a');
        strictAssertSame(0755, fileperms($destination . '/a') & 0777,
            'new transaction parent is safe from different-UID group rename');
        strictAssertSame('new-alpha', file_get_contents($destination . '/a/f'), 'payload published');
    } finally { umask($saved); strictRemoveTree($root); }
});
$suite->test('x-dest replay stages a configured path with comma and quote as exact XMLRPC data', function () {
    $root = txFixture();
    try {
        $destination = $root . '/dst/comm,a"q';
        mkdir($destination, 0700);
        $context = txCallerContext($root);
        $context['destination'] = $destination . '/';
        FileUtil::$messages = array();
        $result = rtOpFiles(array('a/f'), $root . '/src', $destination, 'Copy', false, $context);
        strictAssertSame(true, $result, 'physical Copy: ' . implode('; ', FileUtil::$messages));
        txWriteSidecar($context, $context['destination']);
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array('0', '0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_SET'));
        rXMLRPCRequest::queue('d.save_full_session', true, false, array('0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_ALREADY'));
        strictAssertSame(true, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'),
            'punctuation path replay');
        $stages = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom');
        strictAssertSame($context['destination'], $stages[0]['commands'][1]->params[2],
            'exact punctuation bytes staged through XMLRPC');
        foreach (rXMLRPCRequest::requestsFor('branch') as $branch)
            strictAssertSame(false, strpos($branch['commands'][0]->params[1], $context['destination']),
                'punctuation path is not embedded in rTorrent DSL');
    } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('repeat finished hook for the same receipt does not create a second job', function () {
    $root = txFixture();
    try {
        $context = txCallerContext($root);
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $context), 'first Copy');
        rXMLRPCRequest::reset();
        FileUtil::$messages = array();
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $context), 'same hook retry acknowledges already-published files');
        strictAssertSame(1, count(glob($root . '/session/.autotools-file-jobs/*.nonmove.json')),
            'one durable receipt');
        strictAssertSame(array(), rXMLRPCRequest::$requests, 'no synchronous daemon RPC inside hook');
        strictAssertSame('new-alpha', file_get_contents($root . '/dst/item/a/f'), 'same payload');
    } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('NonMove holds a conflicting daemon generation without changing x-dest', function () {
    $root = txFixture();
    try {
        $context = txCallerContext($root);
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $context), 'physical Copy');
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array('0', '0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_SKIP'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_SKIP'));
        FileUtil::$messages = array();
        strictAssertSame(false, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'),
            'foreign generation held');
        strictAssertSame(1, count(glob($root . '/session/.autotools-file-jobs/*.nonmove.json')),
            'receipt retained');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.save_full_session')),
            'no foreign daemon session save');
        strictAssertSame(true, count(FileUtil::$messages) > 0, 'visible hold');
    } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('NonMove replays physical publication and x-dest after worker crash', function () {
    $root = txFixture();
    try {
        file_put_contents($root . '/dst/item/a/f', 'old-alpha');
        file_put_contents($root . '/dst/item/b/f', 'old-beta');
        strictAssertSame(9, txContextCrash($root, 'after-publish-0'), 'worker killed');
        strictAssertSame('new-alpha', file_get_contents($root . '/dst/item/a/f'), 'first name published');
        strictAssertSame('old-beta', file_get_contents($root . '/dst/item/b/f'), 'second name still old');
        $paths = glob($root . '/session/.autotools-file-jobs/*.nonmove.json');
        strictAssertSame(1, count($paths), 'durable caller receipt survives crash');
        strictAssertSame(txCallerContext($root), json_decode(file_get_contents($paths[0]), true)['context'],
            'same torrent generation retained');
        txWriteSidecar(txCallerContext($root), txCallerContext($root)['destination']);
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array('0', '0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_SET'));
        rXMLRPCRequest::queue('d.save_full_session', true, false, array('0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_ALREADY'));
        strictAssertSame(true, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'),
            'physical and metadata replay');
        strictAssertSame('new-beta', file_get_contents($root . '/dst/item/b/f'), 'second name now published');
        strictAssertSame(array(), glob($root . '/session/.autotools-file-jobs/*.nonmove.json'),
            'journal cleaned only after x-dest proof');
    } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
$suite->test('NonMove recognises x-dest already captured by the normal hook', function () {
    $root = txFixture();
    try {
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, txCallerContext($root)), 'physical Copy');
        strictAssertSame(1, count(glob($root . '/session/.autotools-file-jobs/*.nonmove.json')),
            'normal hook receipt retained until scheduler confirms it');
        txWriteSidecar(txCallerContext($root), txCallerContext($root)['destination']);
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array('0', '0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_SKIP'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_ALREADY'));
        rXMLRPCRequest::queue('d.save_full_session', true, false, array('0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_ALREADY'));
        strictAssertSame(true, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'),
            'normal hook value is durable');
        strictAssertSame(array(), glob($root . '/session/.autotools-file-jobs/*.nonmove.json'),
            'receipt cleaned');
    } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
// rTorrent 0.16 publishes the .torrent.rtorrent sidecar from its session thread
// after d.save_full_session has answered. A child process stands for that
// thread: it publishes the sidecar $delay seconds later, by rename.
function txPublishSidecarLater($context, $xdest, $delay) {
    $torrent = Torrent::fromRawBytes('de');
    $torrent->setMeta('custom', array(
        'x-autotools-nonmove-job' => $context['token'],
        'x-autotools-nonmove-source' => $context['sourceBase'],
        'x-autotools-nonmove-target' => $context['destination'],
        'x-dest' => $xdest,
    ));
    $path = rTorrentSettings::get()->session . '/' . $context['hash'] . '.torrent.rtorrent';
    $environment = array_merge($_ENV, array('SIDECAR_PATH' => $path,
        'SIDECAR_BYTES' => base64_encode((string) $torrent), 'SIDECAR_DELAY' => (string) $delay));
    $process = proc_open(array(PHP_BINARY, '-r', 'usleep((int) (getenv("SIDECAR_DELAY") * 1000000));'
        . ' $path = getenv("SIDECAR_PATH"); file_put_contents($path . ".tmp", base64_decode(getenv("SIDECAR_BYTES")));'
        . ' rename($path . ".tmp", $path);'),
        array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'),
            2 => array('file', '/dev/null', 'w')), $pipes, null, $environment);
    if (!is_resource($process)) throw new RuntimeException('Cannot start the sidecar publisher');
    return $process;
}
// L4-N5: the x-dest confirmation waits for that publication within a bound
// instead of holding the receipt for another 60 s recovery cycle.
$suite->test('NonMove confirms an x-dest sidecar the daemon publishes after the save answers', function () {
    $root = txFixture();
    $publisher = null;
    try {
        $context = txCallerContext($root);
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $context), 'physical Copy');
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array('0', '0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_SET'));
        rXMLRPCRequest::queue('d.save_full_session', true, false, function () use ($context, &$publisher) {
            $publisher = txPublishSidecarLater($context, $context['destination'], 0.2);
            return array('0');
        });
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_ALREADY'));
        FileUtil::$messages = array();
        strictAssertSame(true, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'),
            'the receipt is confirmed in this recovery: ' . json_encode(FileUtil::$messages));
        strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.save_full_session')), 'one save is asked for');
        strictAssertSame(array(), glob($root . '/session/.autotools-file-jobs/*.nonmove.json'), 'receipt retired');
        strictAssertSame(array(), FileUtil::$messages, 'nothing is held');
    } finally {
        if (is_resource($publisher)) proc_close($publisher);
        rXMLRPCRequest::reset();
        strictRemoveTree($root);
    }
});
$suite->test('NonMove still holds an x-dest sidecar that never appears, after the bounded wait', function () {
    $root = txFixture();
    try {
        $context = txCallerContext($root);
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item',
            'Copy', false, $context), 'physical Copy');
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array('0', '0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_SET'));
        rXMLRPCRequest::queue('d.save_full_session', true, false, array('0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_ALREADY'));
        FileUtil::$messages = array();
        $started = microtime(true);
        strictAssertSame(false, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'),
            'an unconfirmed x-dest is held');
        $elapsed = microtime(true) - $started;
        strictAssertTrue($elapsed >= AUTOTOOLS_SESSION_SAVE_WAIT && $elapsed < 5,
            'the hold comes after the bounded wait: ' . round($elapsed, 2));
        strictAssertSame(1, count(glob($root . '/session/.autotools-file-jobs/*.nonmove.json')), 'receipt retained');
        strictAssertSame(1, count(array_filter(FileUtil::$messages, function ($line) {
            return strpos($line, 'held: x-dest session sidecar not persisted') !== false;
        })), 'the hold is visible once: ' . json_encode(FileUtil::$messages));
    } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
});
// R4-FS-01: the synchronous finished hook runs inside rTorrent's main thread,
// and the other owners of the journal lock wait for the daemon while they
// hold it. A job therefore never waits for the lock: it answers null at once
// and its caller defers the hook. The holder here gives the lock up after 2 s
// by itself, so a job that does wait still ends.
$suite->test('a job whose journal lock another owner holds answers null at once', function () {
    $root = txFixture();
    $holder = null;
    try {
        mkdir($root . '/session/.autotools-file-jobs', 0700);
        $holder = proc_open(array(PHP_BINARY, '-r', '$h = fopen($argv[1], "c"); flock($h, LOCK_EX);'
            . ' echo "held\n"; fflush(STDOUT); usleep(2000000);', $root . '/session/.autotools-file-jobs/.lock'),
            array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')),
            $pipes);
        strictAssertSame('held', trim(fgets($pipes[1])), 'the other owner holds the journal');
        FileUtil::$messages = array();
        $started = microtime(true);
        $result = rtOpFiles(array('a/f'), $root . '/src', $root . '/dst/item', 'Copy', false, txCallerContext($root));
        $elapsed = microtime(true) - $started;
        strictAssertTrue($elapsed < 1, 'the job does not wait for the lock: ' . round($elapsed, 2));
        strictAssertSame(null, $result, 'a busy journal is not a refusal');
        strictAssertSame(false, @lstat($root . '/dst/item/a/f'), 'nothing is published');
        strictAssertSame(array(), glob($root . '/session/.autotools-file-jobs/*.nonmove.json'), 'nothing is journaled');
        strictAssertSame(array(), FileUtil::$messages, 'the caller, not the job, reports the deferral');
    } finally {
        if (is_resource($holder)) { fclose($pipes[1]); proc_close($holder); }
        rXMLRPCRequest::reset();
        strictRemoveTree($root);
    }
});

$suite->test('FIN-CP-01 unwritable journal intent remains visible after existing lock opens',function(){
    if (posix_geteuid() === 0) { echo "scope - permission denial requires non-root worker\n"; return; }
    $root=txFixture();
    try {
        $journal=AutoToolsFileTransaction::journalRoot();touch($journal.'/.lock');chmod($journal,0500);
        strictAssertSame(false,rtOpFiles(array('a/f'),$root.'/src',$root.'/dst/item','Copy'),'intent denied');
        strictAssertSame('journal-write-failed',AutoToolsFileTransaction::lastRefusal()['reason'],'hook can notify');
        strictAssertSame(false,file_exists($root.'/dst/item/a/f'),'no publish');
        chmod($journal,0700);
    }finally{strictRemoveTree($root);}
});
$suite->test('FIN-CP-04 failed staging removes only new empty destination directories',function(){
    $root=txFixture();
    try {
        strictAssertSame(false,rtOpFiles(array('missing'),$root.'/src',$root.'/new/nested','Copy'),'missing source refuses');
        strictAssertSame(false,is_dir($root.'/new'),'new empty ancestors retired');
        strictAssertSame('file-operation-refused',AutoToolsFileTransaction::lastRefusal()['reason'],'visible refusal');
    }finally{strictRemoveTree($root);}
});
$suite->test('FIN-CP-02 cross-device HardLink failure is visible and leaves source bytes',function(){
    $root=txFixture();$destination='/dev/shm/autotools-link-'.bin2hex(random_bytes(6));
    if (!is_dir('/dev/shm') || stat('/dev/shm')['dev']===stat($root)['dev']) { strictRemoveTree($root);echo "scope - separate tmpfs unavailable\n";return; }
    try {
        strictAssertSame(false,rtOpFiles(array('a/f'),$root.'/src',$destination,'HardLink'),'EXDEV refuses');
        strictAssertSame('file-operation-refused',AutoToolsFileTransaction::lastRefusal()['reason'],'notifier receives reason');
        strictAssertSame(true,is_file($root.'/src/a/f'),'source retained');
        strictAssertSame(false,is_dir($destination),'empty destination retired');
    }finally{strictRemoveTree($root);if(is_dir($destination))strictRemoveTree($destination);}
});
// POST-1011-01: publish() renames the replaced destination into its stage and
// confirms the backup at once. On drvfs over 9p such a new name answered ENOENT
// once; read as missing, the backup failed the publication and the rollback,
// which cannot see it either, left the public name empty until recovery.
require_once __DIR__ . '/SettlingRenameFiles.php';
class SettleNonMove extends AutoToolsFileTransaction {
    public static $dest;
    public static $files;
    protected static function filesystem() { return self::$files; }
    protected static function checkpoint($name) {
        if ($name !== 'after-backup-unverified-0') return;
        $removed = glob(dirname(self::$dest) . '/.autotools-stage-*-0/removed');
        if (count($removed) !== 1 || !self::$files->hide($removed[0]))
            throw new RuntimeException('backup fixture unavailable');
    }
}
foreach (array('Copy', 'HardLink', 'SoftLink') as $mode)
    $suite->test($mode . ' confirms a replaced destination backup that answers late', function () use ($mode) {
        $root = txFixture();
        try {
            $dest = $root . '/dst/item/a/f';
            file_put_contents($dest, 'old-alpha');
            SettleNonMove::$dest = $dest;
            SettleNonMove::$files = new SettlingRenameFiles('/^$/D', 3);
            FileUtil::$messages = array();
            strictAssertSame(true, SettleNonMove::run(array('a/f'), $root . '/src', $root . '/dst/item', $mode),
                'the operation publishes: ' . implode(' | ', FileUtil::$messages));
            strictAssertSame(array(3), array_values(SettleNonMove::$files->lookups), 'the backup is confirmed on its third lookup');
            strictAssertSame('new-alpha', file_get_contents($dest), 'the new file is published');
            if ($mode === 'HardLink') strictAssertSame(fileinode($root . '/src/a/f'), fileinode($dest), 'inode');
            if ($mode === 'SoftLink') strictAssertSame(true, is_link($dest), 'symlink');
            strictAssertSame(array(), glob($root . '/dst/item/a/.autotools-stage-*'), 'the stage retires');
            strictAssertSame(true, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'), 'no receipt is held');
        } finally { strictRemoveTree($root); }
    });

$suite->test('legacy byte paths survive committed Copy receipt recovery without replaying payload', function () {
    $root = txFixture();
    try {
        $src = $root . "/source\xFF"; $dst = $root . "/destination\xFE";
        mkdir($src, 0700); mkdir($dst, 0700);
        $files = array("raw\xFD", 'raw\\xFD');
        file_put_contents($src . '/' . $files[0], 'binary source');
        file_put_contents($src . '/' . $files[1], 'literal source');
        $context = txCallerContext($root);
        $context['sourceBase'] = $src . '/' . $files[0];
        $context['destination'] = $dst . '/';
        strictAssertSame(true, rtOpFiles($files, $src, $dst, 'Copy', false, $context), 'raw paths publish');
        $paths = glob($root . '/session/.autotools-file-jobs/*.nonmove.json');
        strictAssertSame(1, count($paths), 'one exact caller receipt waits for acknowledgement');
        $record = JSON::decodeBytes(file_get_contents($paths[0]));
        strictAssertSame($context, $record['context'], 'caller paths retain original bytes');
        strictAssertSame($files, $record['files'], 'raw and literal escape filenames stay distinct');
        $inode = fileinode($dst . '/' . $files[0]);
        file_put_contents($src . '/' . $files[0], 'changed source');
        txWriteSidecar($context, $context['destination']);
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array('0', '0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_SET'));
        rXMLRPCRequest::queue('d.save_full_session', true, false, array('0'));
        rXMLRPCRequest::queue('branch', true, false, array('AUTOTOOLS_XDEST_ALREADY'));
        strictAssertSame(true, AutoToolsFileTransaction::recoverAll($root . '/session/.autotools-file-jobs'),
            'fresh recovery reads the byte document and acknowledges metadata');
        strictAssertSame('binary source', file_get_contents($dst . '/' . $files[0]), 'recovery does not repeat the Copy');
        strictAssertSame('literal source', file_get_contents($dst . '/' . $files[1]), 'literal neighbor remains separate');
        strictAssertSame($inode, fileinode($dst . '/' . $files[0]), 'the published inode remains unchanged');
        strictAssertSame(array(), glob($root . '/session/.autotools-file-jobs/*.nonmove.json'), 'acknowledged receipt retires');
        $stages = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom');
        strictAssertSame($context['sourceBase'], $stages[0]['commands'][0]->params[2], 'RPC source is the original raw path');
        strictAssertSame($context['destination'], $stages[0]['commands'][1]->params[2], 'RPC destination is the original raw path');
    } finally { rXMLRPCRequest::reset(); strictRemoveTree($root); }
});

exit($suite->run());
