<?php
require_once dirname(__DIR__, 2) . '/plugins/rutracker_check/TestLib.php';
class FileUtil { public static $messages = array(); public static function toLog($message) { self::$messages[] = $message; } }

function rtAddTailSlash($path) { return rtrim($path, '/') . '/'; }
function rtMkDir($path, $mode = 0777)
{
    return is_dir($path) || @mkdir($path, $mode, true) || is_dir($path);
}
function rtIsFile($path) { return is_file($path); }
class LFS { public static function stat($path) { return @stat($path); } }
$util = dirname(__DIR__, 3) . '/plugins/autotools/util_rt.php';
if (strpos(file_get_contents($util), 'function rtMoveFile(') !== false)
    eval(loadFunctionDefinition($util, 'rtMoveFile'));
eval(loadClassDefinition(dirname(__DIR__, 3) . '/plugins/autotools/util_rt.php', 'AutoToolsFileTransaction'));
eval(loadFunctionDefinition(dirname(__DIR__, 3) . '/plugins/autotools/util_rt.php', 'rtOpFiles'));

function removeAutoToolsFixture($path)
{
    if (!is_dir($path) || is_link($path)) return @unlink($path);
    foreach (scandir($path) as $name)
        if ($name !== '.' && $name !== '..') removeAutoToolsFixture($path . '/' . $name);
    return rmdir($path);
}

$suite = new StrictTestSuite();
$suite->test('HardLink reports the result of creating a link', function () {
    $parent = getenv('TMPDIR') ?: sys_get_temp_dir();
    $root = $parent . '/autotools-hardlink-' . bin2hex(random_bytes(8));
    if (!mkdir($root, 0700) || !mkdir($root . '/src', 0700) ||
        !mkdir($root . '/dst', 0700) || !mkdir($root . '/session', 0700)) {
        throw new RuntimeException('Cannot create HardLink fixture');
    }
    rTorrentSettings::get()->session = $root . '/session';
    try {
        file_put_contents($root . '/src/present', 'payload');
        strictAssertSame(true, rtOpFiles(['present'], $root . '/src', $root . '/dst', 'HardLink'),
            'a valid hard link must succeed');
        strictAssertSame(fileinode($root . '/src/present'), fileinode($root . '/dst/present'),
            'the destination shares the source inode');
        $result = @rtOpFiles(['missing'], $root . '/src', $root . '/dst', 'HardLink');
        strictAssertSame(false, $result, 'a missing source must fail the operation');
        strictAssertSame(false, file_exists($root . '/dst/missing'), 'no link may be published');
    } finally {
        removeAutoToolsFixture($root);
    }
});
$suite->test('legacy Move and unknown modes cannot use the unsafe file loop', function () {
    $parent = getenv('TMPDIR') ?: sys_get_temp_dir();
    $root = $parent . '/autotools-legacy-move-' . bin2hex(random_bytes(8));
    if (!mkdir($root, 0700) || !mkdir($root . '/src', 0700) ||
        !mkdir($root . '/dst', 0700) || !mkdir($root . '/session', 0700)) {
        throw new RuntimeException('Cannot create legacy Move fixture');
    }
    rTorrentSettings::get()->session = $root . '/session';
    try {
        foreach (array('Move', 'Unexpected') as $mode) {
            file_put_contents($root . '/src/present', 'source');
            file_put_contents($root . '/dst/present', 'foreign');
            FileUtil::$messages = array();
            strictAssertSame(false, rtOpFiles(array('present'), $root . '/src', $root . '/dst', $mode),
                $mode . ' must not enter the legacy rename loop');
            strictAssertSame('source', @file_get_contents($root . '/src/present'),
                $mode . ' preserves the source');
            strictAssertSame('foreign', @file_get_contents($root . '/dst/present'),
                $mode . ' preserves the foreign destination');
            strictAssertSame(true, count(FileUtil::$messages) > 0,
                $mode . ' refusal is visible in the app log');
        }
    } finally {
        removeAutoToolsFixture($root);
    }
});
exit($suite->run());
