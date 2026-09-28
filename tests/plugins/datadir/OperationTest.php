<?php
require_once dirname(__DIR__) . '/rutracker_check/TestLib.php';

function rtAddTailSlash($path) { return rtrim($path, '/') . '/'; }
function rtMkDir($path, $mode = 0777)
{
    return is_dir($path) || @mkdir($path, $mode, true) || is_dir($path);
}
function rtIsFile($path) { return is_file($path); }
function rtMoveFile($src, $dst, $dbg = false) { throw new RuntimeException('unexpected move'); }

$source = getenv('DATADIR_OPERATION_SOURCE') ?: testFindRepoRoot() . '/plugins/datadir/util_rt.php';
eval(loadFunctionDefinition($source, 'rtOpFiles'));

$suite = new StrictTestSuite();
$suite->test('HardLink refuses a failed link without copying through the destination', function () {
    $parent = getenv('TMPDIR') ?: sys_get_temp_dir();
    $root = $parent . '/datadir-hardlink-' . bin2hex(random_bytes(8));
    if (!mkdir($root) || !mkdir($root . '/src') || !mkdir($root . '/dst'))
        throw new RuntimeException('Cannot create HardLink fixture');
    try {
        file_put_contents($root . '/src/present', 'source bytes');
        file_put_contents($root . '/src/occupied', 'source bytes');
        strictAssertSame(true, rtOpFiles(['present'], $root . '/src', $root . '/dst', 'HardLink'),
            'a valid hard link succeeds');
        strictAssertSame(fileinode($root . '/src/present'), fileinode($root . '/dst/present'),
            'a valid hard link shares the source inode');

        $outside = $root . '/outside';
        symlink($outside, $root . '/dst/occupied');
        $result = @rtOpFiles(['occupied'], $root . '/src', $root . '/dst', 'HardLink');
        strictAssertSame(false, $result, 'a failed link refuses the operation');
        strictAssertSame(false, file_exists($outside), 'a failed link does not copy through the symlink');
        strictAssertSame('source bytes', file_get_contents($root . '/src/occupied'),
            'the source survives a failed link');
    } finally {
        @unlink($root . '/dst/present');
        @unlink($root . '/dst/occupied');
        @unlink($root . '/outside');
        @unlink($root . '/src/present');
        @unlink($root . '/src/occupied');
        @rmdir($root . '/src');
        @rmdir($root . '/dst');
        @rmdir($root);
    }
});
exit($suite->run());
