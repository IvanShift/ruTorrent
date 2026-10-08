<?php

// The payload directory trust rule shared by datadir and AutoTools
// (R3-OWN-01): relaxed for group/other write at and below the configured
// top, strict above it, owner and physical-path checks everywhere.

require_once __DIR__ . '/../rutracker_check/TestLib.php';

class FileUtil
{
    public static function toLog($line) {}
}

require_once testFindRepoRoot() . '/plugins/erasedata/filesystem.php';

function payloadTrustRoot()
{
    $root = realpath(getenv('TMPDIR') ?: sys_get_temp_dir()) . '/payload-trust-' . bin2hex(random_bytes(6));
    foreach (array('', '/top', '/top/win', '/top/win/sub', '/outside') as $directory)
        if (!mkdir($root . $directory, 0755))
            throw new RuntimeException('fixture directory was not created');
    chmod($root, 0700);
    return $root;
}

function payloadTrustClean($root)
{
    unset($GLOBALS['topDirectory']);
    strictRemoveTree($root);
}

$suite = new StrictTestSuite();

$suite->test('below the top an Explorer-like group/other-writable tree is trusted', function () {
    $root = payloadTrustRoot();
    try {
        chmod($root . '/top/win', 0777);
        chmod($root . '/top/win/sub', 0777);
        strictAssertSame(true, ErasedataFilesystemOps::payloadDirectoryTrusted($root . '/top/win/sub', $root . '/top/'),
            'the daemon already depends on the permissions of the top subtree');
        chmod($root . '/top', 0777);
        strictAssertSame(true, ErasedataFilesystemOps::payloadDirectoryTrusted($root . '/top/win', $root . '/top'),
            'a 0777 top, like a drvfs bind-mount root, is itself inside the boundary');
    } finally { payloadTrustClean($root); }
});

$suite->test('above the top the strict rule stays', function () {
    $root = payloadTrustRoot();
    try {
        chmod($root . '/outside', 0777);
        strictAssertSame(false, ErasedataFilesystemOps::payloadDirectoryTrusted($root . '/outside', $root . '/top'),
            'a writable directory outside the top refuses');
        chmod($root, 0777);
        strictAssertSame(false, ErasedataFilesystemOps::payloadDirectoryTrusted($root . '/top/win', $root . '/top'),
            'a writable ancestor above the top refuses every path below it');
        chmod($root, 01777);
        strictAssertSame(true, ErasedataFilesystemOps::payloadDirectoryTrusted($root . '/top/win', $root . '/top'),
            'a sticky ancestor keeps its 2026-09-28 admission');
    } finally { chmod($root, 0700); payloadTrustClean($root); }
});

$suite->test('without a resolvable top every component is strict', function () {
    $root = payloadTrustRoot();
    try {
        chmod($root . '/top/win', 0777);
        foreach (array(null, '', 'relative/top', $root . '/absent') as $top) {
            unset($GLOBALS['topDirectory']);
            strictAssertSame(false, ErasedataFilesystemOps::payloadDirectoryTrusted($root . '/top/win', $top),
                'no relaxed region for top ' . var_export($top, true));
        }
    } finally { payloadTrustClean($root); }
});

// '/' is the conf/config.php default outside Docker. The daemon does not
// write every download below it, so it is no boundary at all (NEW-AUD-TOP).
$suite->test('a top of / is no boundary: every component stays strict', function () {
    $root = payloadTrustRoot();
    try {
        chmod($root . '/top/win', 0777);
        file_put_contents($root . '/top/win/payload', 'x');
        chmod($root . '/top/win/payload', 0666);
        symlink('/', $root . '/root-alias');
        foreach (array('/', '/.', '//', $root . '/root-alias') as $top) {
            strictAssertSame(false, ErasedataFilesystemOps::payloadDirectoryTrusted($root . '/top/win', $top),
                'a writable directory refuses with top ' . var_export($top, true));
            strictAssertSame(false, ErasedataFilesystemOps::payloadFileTrusted($root . '/top/win/payload', $top),
                'a writable file refuses with top ' . var_export($top, true));
        }
        $GLOBALS['topDirectory'] = '/';
        strictAssertSame(false, ErasedataFilesystemOps::payloadDirectoryTrusted($root . '/top/win'),
            'the configured default top relaxes nothing');
        strictAssertSame(true, ErasedataFilesystemOps::payloadDirectoryTrusted($root . '/top/win', $root . '/top'),
            'a real top still relaxes its own subtree');
    } finally { payloadTrustClean($root); }
});

$suite->test('the configured top directory is the default boundary', function () {
    $root = payloadTrustRoot();
    try {
        chmod($root . '/top/win', 0777);
        $GLOBALS['topDirectory'] = $root . '/top/';
        strictAssertSame(true, ErasedataFilesystemOps::payloadDirectoryTrusted($root . '/top/win'),
            '$topDirectory names the boundary');
        $GLOBALS['topDirectory'] = $root . '/outside/';
        strictAssertSame(false, ErasedataFilesystemOps::payloadDirectoryTrusted($root . '/top/win'),
            'a path outside the configured top stays strict');
    } finally { payloadTrustClean($root); }
});

$suite->test('a symlink component, a non-physical path or a non-directory refuses inside the top', function () {
    $root = payloadTrustRoot();
    try {
        symlink($root . '/outside', $root . '/top/escape');
        symlink($root . '/top/win', $root . '/top/alias');
        file_put_contents($root . '/top/file', 'x');
        foreach (array($root . '/top/escape', $root . '/top/alias', $root . '/top/alias/sub',
            $root . '/top/win/../win', $root . '/top/file', 'top/win', '') as $path)
            strictAssertSame(false, ErasedataFilesystemOps::payloadDirectoryTrusted($path, $root . '/top'),
                'refused: ' . $path);
        chmod($root . '/outside', 0777);
        strictAssertSame(false, ErasedataFilesystemOps::payloadDirectoryTrusted(realpath($root . '/top/escape'), $root . '/top'),
            'an escape target outside the top keeps the strict rule');
    } finally { payloadTrustClean($root); }
});

$suite->test('payload files follow the same boundary', function () {
    $root = payloadTrustRoot();
    try {
        file_put_contents($root . '/top/win/payload', 'x');
        chmod($root . '/top/win/payload', 0666);
        file_put_contents($root . '/outside/payload', 'x');
        chmod($root . '/outside/payload', 0666);
        strictAssertSame(true, ErasedataFilesystemOps::payloadFileTrusted($root . '/top/win/payload', $root . '/top'),
            'a drvfs-like 0666 file below the top is trusted');
        strictAssertSame(false, ErasedataFilesystemOps::payloadFileTrusted($root . '/outside/payload', $root . '/top'),
            'the same file outside the top is not');
        strictAssertSame(false, ErasedataFilesystemOps::payloadFileTrusted($root . '/top/win', $root . '/top'),
            'a directory is not a payload file');
        symlink($root . '/top/win/payload', $root . '/top/link');
        strictAssertSame(false, ErasedataFilesystemOps::payloadFileTrusted($root . '/top/link', $root . '/top'),
            'a symlink is not a payload file');
    } finally { payloadTrustClean($root); }
});

// R4-FS-03: a path as a configuration spells it is checked along every
// directory and link the spelling passes, not only at its physical end.
$suite->test('a spelled path through a link is checked where the spelling goes', function () {
    $root = payloadTrustRoot();
    try {
        $top = $root . '/top';
        mkdir($root . '/shared', 0755);
        symlink($top . '/win', $root . '/shared/fin');
        symlink('win/sub', $top . '/relative');
        symlink('../shared/fin', $top . '/via-shared');
        strictAssertSame(true, ErasedataFilesystemOps::payloadPathTrusted($root . '/shared/fin/sub', $top),
            'a protected spelling through an own link is trusted');
        strictAssertSame(true, ErasedataFilesystemOps::payloadPathTrusted($top . '/relative', $top),
            'a relative link target is resolved from the directory of the link');
        strictAssertSame(true, ErasedataFilesystemOps::payloadPathTrusted($top . '/via-shared/sub', $top),
            'a chain of own links through protected directories is trusted');
        chmod($root . '/shared', 0777);
        strictAssertSame(false, ErasedataFilesystemOps::payloadPathTrusted($root . '/shared/fin/sub', $top),
            'a writable directory above the top in the spelling refuses though the link leads below the top');
        strictAssertSame(true, ErasedataFilesystemOps::payloadDirectoryTrusted(realpath($root . '/shared/fin/sub'), $top),
            'while the physical path alone is trusted');
        strictAssertSame(false, ErasedataFilesystemOps::payloadPathTrusted($top . '/via-shared/sub', $top),
            'a link target that passes the writable directory refuses as well');
        foreach (array($root . '/shared/../top/win', $root . '/./top/win', 'top/win', '', "$top/win\0") as $path)
            strictAssertSame(false, ErasedataFilesystemOps::payloadPathTrusted($path, $top), 'refused: ' . $path);
    } finally { chmod($root . '/shared', 0700); payloadTrustClean($root); }
});

$suite->test('a missing name is accepted only as the tail the caller creates', function () {
    $root = payloadTrustRoot();
    try {
        $top = $root . '/top';
        symlink($root . '/absent', $top . '/dangling');
        symlink('loop', $top . '/loop');
        strictAssertSame(false, ErasedataFilesystemOps::payloadPathTrusted($top . '/win/new/deeper', $top),
            'a missing directory is not trusted by default');
        strictAssertSame(true, ErasedataFilesystemOps::payloadPathTrusted($top . '/win/new/deeper', $top, true),
            'a missing tail under a trusted prefix is');
        foreach (array($top . '/dangling', $top . '/dangling/new', $top . '/loop', $top . '/loop/new') as $path)
            strictAssertSame(false, ErasedataFilesystemOps::payloadPathTrusted($path, $top, true),
                'a name a link leads to must exist: ' . $path);
        chmod($root . '/outside', 0777);
        strictAssertSame(false, ErasedataFilesystemOps::payloadPathTrusted($root . '/outside/new', $top, true),
            'the existing prefix of a missing tail is still checked');
    } finally { payloadTrustClean($root); }
});

// R4-FS-09: the strict form, for datadir's own state, has no relaxed region.
$suite->test('the strict state rule refuses a writable component even below the top', function () {
    $root = payloadTrustRoot();
    try {
        $GLOBALS['topDirectory'] = $root . '/top/';
        strictAssertSame(true, ErasedataFilesystemOps::stateDirectoryTrusted($root . '/top/win'), 'a private tree');
        chmod($root . '/top/win', 0777);
        strictAssertSame(true, ErasedataFilesystemOps::payloadDirectoryTrusted($root . '/top/win'),
            'the payload form accepts a writable directory below the top');
        strictAssertSame(false, ErasedataFilesystemOps::stateDirectoryTrusted($root . '/top/win'), 'the state form does not');
        chmod($root . '/top/win', 01777);
        strictAssertSame(true, ErasedataFilesystemOps::stateDirectoryTrusted($root . '/top/win'), 'a sticky one is trusted');
    } finally { payloadTrustClean($root); }
});

// Only root can create a component of another owner; run this file as root
// (php:8.1-cli in Docker) to execute the case, as the L4 evidence does.
$suite->test('a link of another owner refuses even inside the top', function () {
    if (posix_geteuid() !== 0) {
        echo "scope - the foreign-link case needs root and did not run\n";
        return;
    }
    $root = payloadTrustRoot();
    try {
        chmod($root . '/top/win', 0777);
        symlink($root . '/outside', $root . '/top/win/link');
        strictAssertSame(true, ErasedataFilesystemOps::payloadPathTrusted($root . '/top/win/link', $root . '/top'),
            'a root link in a writable directory below the top is followed');
        lchown($root . '/top/win/link', 12345);
        strictAssertSame(false, ErasedataFilesystemOps::payloadPathTrusted($root . '/top/win/link', $root . '/top'),
            'the same link of another owner refuses');
        strictAssertSame(false, ErasedataFilesystemOps::payloadPathTrusted($root . '/top/win/link/new', $root . '/top', true),
            'and nothing may be created through it');
    } finally { payloadTrustClean($root); }
});

$suite->test('a component of another owner refuses even inside the top', function () {
    if (posix_geteuid() !== 0) {
        echo "scope - the foreign-owner case needs root and did not run\n";
        return;
    }
    $root = payloadTrustRoot();
    try {
        chown($root . '/top/win', 12345);
        strictAssertSame(false, ErasedataFilesystemOps::payloadDirectoryTrusted($root . '/top/win/sub', $root . '/top'),
            'a foreign-owned component refuses whatever its mode');
        chown($root . '/top/win', 0);
        chmod($root . '/top/win', 0777);
        strictAssertSame(true, ErasedataFilesystemOps::payloadDirectoryTrusted($root . '/top/win/sub', $root . '/top'),
            'a root:root 0777 Explorer-like folder is trusted inside the top');
    } finally { payloadTrustClean($root); }
});

exit($suite->run());
