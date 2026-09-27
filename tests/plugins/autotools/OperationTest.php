<?php
require_once dirname(__DIR__, 2) . '/plugins/rutracker_check/TestLib.php';

function rtAddTailSlash($path) { return rtrim($path, '/') . '/'; }
function rtMkDir($path, $mode = 0777)
{
    return is_dir($path) || @mkdir($path, $mode, true) || is_dir($path);
}
function rtIsFile($path) { return is_file($path); }
eval(loadFunctionDefinition(dirname(__DIR__, 3) . '/plugins/autotools/util_rt.php', 'rtOpFiles'));

$suite = new StrictTestSuite();
$suite->test('HardLink reports the result of creating a link', function () {
    $parent = getenv('TMPDIR') ?: sys_get_temp_dir();
    $root = $parent . '/autotools-hardlink-' . bin2hex(random_bytes(8));
    if (!mkdir($root) || !mkdir($root . '/src') || !mkdir($root . '/dst')) {
        throw new RuntimeException('Cannot create HardLink fixture');
    }
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
        @unlink($root . '/dst/present');
        @unlink($root . '/src/present');
        rmdir($root . '/src');
        rmdir($root . '/dst');
        rmdir($root);
    }
});
exit($suite->run());
