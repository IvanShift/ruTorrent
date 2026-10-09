<?php

// AutoTools Copy, HardLink and SoftLink under the payload trust rule that
// datadir and AutoTools Move share (R3-OWN-01): a Windows-like 0777 tree at and
// below the configured top is accepted, the same tree outside the top refuses
// before a public file is written, and the file-job journal keeps the strict
// rule. Run as root (php:8.1-cli in Docker) to execute the foreign-owner case.

require_once dirname(__DIR__, 2) . '/plugins/rutracker_check/TestLib.php';

class FileUtil
{
    public static $messages = array();
    public static function toLog($message) { self::$messages[] = $message; }
}

require_once testFindRepoRoot() . '/plugins/erasedata/filesystem.php';
require_once testFindRepoRoot() . '/plugins/erasedata/outcome.php';
eval(loadFunctionDefinition(testFindRepoRoot() . '/php/utility/rtpluginutil.php', 'rtMkDir'));
eval(loadClassDefinition(testFindRepoRoot() . '/plugins/autotools/util_rt.php', 'AutoToolsFileTransaction'));
eval(loadFunctionDefinition(testFindRepoRoot() . '/plugins/autotools/util_rt.php', 'rtOpFiles'));

// A finished single-directory download with one file and a destination tree
// below the top, and the session directory that holds the file-job journal
// beside it, as /data/.session sits beside /data/downloads in the image; all
// owned by this UID.
function nmtFixture()
{
    $root = realpath(getenv('TMPDIR') ?: sys_get_temp_dir()) . '/nonmove-trust-' . bin2hex(random_bytes(5));
    foreach (array('', '/top', '/top/src', '/top/src/a', '/top/dst', '/top/dst/item',
        '/top/dst/item/a', '/session', '/outside') as $directory)
        if (!mkdir($root . $directory, 0700))
            throw new RuntimeException('fixture directory was not created');
    file_put_contents($root . '/top/src/a/f', 'new-alpha');
    rTorrentSettings::get()->session = $root . '/session';
    FileUtil::$messages = array();
    return $root;
}

function nmtClean($root)
{
    unset($GLOBALS['topDirectory']);
    strictRemoveTree($root);
}

// What each operation leaves at the destination name when it published.
function nmtAssertPublished($mode, $root)
{
    $source = $root . '/top/src/a/f';
    $dest = $root . '/top/dst/item/a/f';
    if ($mode === 'Copy')
        strictAssertSame('new-alpha', @file_get_contents($dest), 'Copy publishes the source bytes');
    elseif ($mode === 'HardLink')
        strictAssertSame(fileinode($source), @fileinode($dest), 'HardLink publishes the source inode');
    else
        strictAssertSame($source, @readlink($dest), 'SoftLink publishes a link to the source');
}

// The trust refusal names the path that failed, and keeps it for the finished
// hook to name on the download (hook_lib.php): it once said only 'nonmove new
// held: source or destination has an unsafe writable ancestor'.
function nmtAssertTrustRefusal($path)
{
    strictAssertSame(array('autotools: nonmove new refused: untrusted-directory path=' . erasedataLogPath($path)),
        FileUtil::$messages, 'the refusal is visible once, with the path that failed');
    strictAssertSame(array('reason' => 'untrusted-directory', 'detail' => $path), AutoToolsFileTransaction::lastRefusal(),
        'and kept for the finished hook to name on the download');
}

$suite = new StrictTestSuite();

foreach (array('Copy', 'HardLink', 'SoftLink') as $mode) {
    $suite->test($mode . ' accepts a Windows-like 0777 source and destination below the configured top',
        function () use ($mode) {
            $root = nmtFixture();
            try {
                $GLOBALS['topDirectory'] = $root . '/top/';
                foreach (array('/top', '/top/src', '/top/src/a', '/top/dst', '/top/dst/item', '/top/dst/item/a')
                    as $directory)
                    chmod($root . $directory, 0777);
                strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/top/src', $root . '/top/dst/item', $mode),
                    'the operation is admitted below the top: ' . json_encode(FileUtil::$messages));
                nmtAssertPublished($mode, $root);
                strictAssertSame(array(), FileUtil::$messages, 'nothing is refused or held');
            } finally { nmtClean($root); }
        });

    $suite->test($mode . ' refuses the same 0777 destination outside the configured top',
        function () use ($mode) {
            $root = nmtFixture();
            try {
                $GLOBALS['topDirectory'] = $root . '/outside/';
                chmod($root . '/top/dst/item', 0777);
                strictAssertSame(false, rtOpFiles(array('a/f'), $root . '/top/src', $root . '/top/dst/item', $mode),
                    'above the top the strict rule refuses');
                strictAssertSame(false, @lstat($root . '/top/dst/item/a/f'), 'no public file is written');
                nmtAssertTrustRefusal($root . '/top/dst/item');
            } finally { nmtClean($root); }
        });

    $suite->test($mode . ' refuses a 0777 source directory outside the configured top',
        function () use ($mode) {
            $root = nmtFixture();
            try {
                $GLOBALS['topDirectory'] = $root . '/top/dst/';
                chmod($root . '/top/src/a', 0777);
                strictAssertSame(false, rtOpFiles(array('f'), $root . '/top/src/a', $root . '/top/dst/item', $mode),
                    'the source side follows the same boundary');
                strictAssertSame(false, @lstat($root . '/top/dst/item/f'), 'no public file is written');
                nmtAssertTrustRefusal($root . '/top/src/a');
            } finally { nmtClean($root); }
        });
}

// A worker that stops right after its commit, as one killed there would.
class NmtInterruptedTransaction extends AutoToolsFileTransaction
{
    protected static function checkpoint($name)
    {
        if ($name === 'after-committed') throw new RuntimeException('interrupted after commit');
    }
}

$suite->test('recovery finishes an interrupted Copy into a Windows-like destination below the top', function () {
    $root = nmtFixture();
    try {
        $GLOBALS['topDirectory'] = $root . '/top/';
        foreach (array('/top/dst', '/top/dst/item', '/top/dst/item/a') as $directory)
            chmod($root . $directory, 0777);
        try {
            NmtInterruptedTransaction::run(array('a/f'), $root . '/top/src', $root . '/top/dst/item', 'Copy');
            throw new LogicException('the worker was not interrupted: ' . json_encode(FileUtil::$messages));
        } catch (RuntimeException $e) {
            strictAssertSame('interrupted after commit', $e->getMessage(), 'the worker stops after its commit');
        }
        $journal = $root . '/session/.autotools-file-jobs';
        strictAssertSame(1, count(glob($journal . '/*.nonmove.json')), 'a committed receipt is left for recovery');
        strictAssertSame(true, AutoToolsFileTransaction::recoverAll($journal),
            'recovery admits the destination below the top: ' . json_encode(FileUtil::$messages));
        strictAssertSame(array(), glob($journal . '/*.nonmove.json'), 'and retires the receipt');
        strictAssertSame('new-alpha', @file_get_contents($root . '/top/dst/item/a/f'), 'the published copy stays');
        strictAssertSame(array(), FileUtil::$messages, 'nothing is held');
    } finally { nmtClean($root); }
});

// R4-FS-03: the spelled destination is checked component by component, not
// only its physical path. A writable directory above the top in the spelling
// refuses even when a link in it leads below the top, or out of it into a
// directory of the service.
foreach (array('below the top' => '/top/dst/item', 'into a service directory' => '/svc') as $case => $target) {
    $suite->test('a writable directory above the top in the spelled destination refuses a link ' . $case,
        function () use ($target) {
            $root = nmtFixture();
            try {
                $GLOBALS['topDirectory'] = $root . '/top/';
                mkdir($root . '/shared', 0700);
                mkdir($root . '/svc', 0700);
                chmod($root . '/shared', 0777);
                symlink($root . $target, $root . '/shared/fin');
                strictAssertSame(false, rtOpFiles(array('a/f'), $root . '/top/src', $root . '/shared/fin/item', 'Copy'),
                    'the writable spelled ancestor above the top refuses');
                strictAssertSame(false, @lstat($root . $target . '/item'), 'nothing is created through the link');
                nmtAssertTrustRefusal($root . '/shared');
            } finally { chmod($root . '/shared', 0700); nmtClean($root); }
        });
}

$suite->test('a link through directories that are safe in the spelling still reaches its target', function () {
    $root = nmtFixture();
    try {
        $GLOBALS['topDirectory'] = $root . '/top/';
        mkdir($root . '/shared', 0700);
        symlink($root . '/top/dst/item', $root . '/shared/fin');
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/top/src', $root . '/shared/fin', 'Copy'),
            'a protected spelling through a link is admitted');
        strictAssertSame('new-alpha', @file_get_contents($root . '/top/dst/item/a/f'), 'the copy lands at the target');
    } finally { nmtClean($root); }
});

// Below the top a writable directory is accepted (drvfs), so the owner of a
// link in it decides: the service's own link is followed.
$suite->test('a link of the service UID in a writable directory below the top is followed', function () {
    $root = nmtFixture();
    try {
        $GLOBALS['topDirectory'] = $root . '/top/';
        mkdir($root . '/top/shared', 0700);
        chmod($root . '/top/shared', 0777);
        symlink($root . '/top/dst/item', $root . '/top/shared/fin');
        strictAssertSame(true, rtOpFiles(array('a/f'), $root . '/top/src', $root . '/top/shared/fin', 'Copy'),
            'the service link below the top is admitted');
        strictAssertSame('new-alpha', @file_get_contents($root . '/top/dst/item/a/f'), 'the copy lands at the target');
    } finally { nmtClean($root); }
});

// Only root can create a link of another owner.
$suite->test('a link of another owner in a writable directory below the top refuses', function () {
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        echo "scope - the foreign-link case needs root and did not run\n";
        return;
    }
    $root = nmtFixture();
    try {
        $GLOBALS['topDirectory'] = $root . '/top/';
        mkdir($root . '/top/shared', 0700);
        chmod($root . '/top/shared', 0777);
        mkdir($root . '/svc', 0700);
        symlink($root . '/svc', $root . '/top/shared/fin');
        lchown($root . '/top/shared/fin', 12345);
        strictAssertSame(false, rtOpFiles(array('a/f'), $root . '/top/src', $root . '/top/shared/fin/item', 'Copy'),
            'a link another UID planted below the top refuses');
        strictAssertSame(false, @lstat($root . '/svc/item'), 'nothing is created through the link');
        nmtAssertTrustRefusal($root . '/top/shared/fin');
    } finally { nmtClean($root); }
});

$suite->test('the file-job journal keeps the strict rule below the configured top', function () {
    $root = nmtFixture();
    try {
        $GLOBALS['topDirectory'] = $root . '/top/';
        mkdir($root . '/top/session', 0700);
        chmod($root . '/top/session', 0777);
        rTorrentSettings::get()->session = $root . '/top/session';
        strictAssertSame(false, rtOpFiles(array('a/f'), $root . '/top/src', $root . '/top/dst/item', 'Copy'),
            'a group/other-writable journal parent refuses even below the top');
        strictAssertSame(false, @lstat($root . '/top/dst/item/a/f'), 'no public file is written');
        strictAssertSame(array('autotools: nonmove new refused: journal-unavailable: the file-job journal'
            . ' directory is unavailable or unsafe'), FileUtil::$messages, 'the journal refusal is visible');
        strictAssertSame(array('reason' => 'journal-unavailable', 'detail' => ''), AutoToolsFileTransaction::lastRefusal(),
            'and kept for the finished hook to name on the download');
        strictAssertSame(false, @lstat($root . '/top/session/.autotools-file-jobs'),
            'no journal is made in a session directory the rule does not trust');
    } finally { nmtClean($root); }
});

$suite->test('without a configured top every payload component is strict', function () {
    $root = nmtFixture();
    try {
        chmod($root . '/top/dst/item', 0777);
        strictAssertSame(false, rtOpFiles(array('a/f'), $root . '/top/src', $root . '/top/dst/item', 'Copy'),
            'no relaxed region without a top');
        strictAssertSame(false, @lstat($root . '/top/dst/item/a/f'), 'no public file is written');
        // '/' is the conf/config.php default outside Docker and no boundary either.
        $GLOBALS['topDirectory'] = '/';
        strictAssertSame(false, rtOpFiles(array('a/f'), $root . '/top/src', $root . '/top/dst/item', 'Copy'),
            'a top of / relaxes nothing');
        strictAssertSame(false, @lstat($root . '/top/dst/item/a/f'), 'no public file is written with a top of /');
    } finally { nmtClean($root); }
});

// Only root can create a component of another owner.
$suite->test('a foreign-owned destination below the configured top refuses', function () {
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        echo "scope - the foreign-owner case needs root and did not run\n";
        return;
    }
    $root = nmtFixture();
    try {
        $GLOBALS['topDirectory'] = $root . '/top/';
        chown($root . '/top/dst/item', 12345);
        chmod($root . '/top/dst/item', 0755);
        strictAssertSame(false, rtOpFiles(array('a/f'), $root . '/top/src', $root . '/top/dst/item', 'Copy'),
            'a component of another owner refuses whatever its mode');
        strictAssertSame(false, @lstat($root . '/top/dst/item/a/f'), 'no public file is written');
    } finally { nmtClean($root); }
});

exit($suite->run());
