<?php

require_once __DIR__ . '/../rutracker_check/TestLib.php';

function rtDataDirJournal() { return FileUtil::$settings; }
class FileUtil
{
    public static $settings;
}

$source = __DIR__ . '/../../../plugins/datadir/util_setdir.php';
foreach (array('rtDataDirLock', 'rtDataDirUnlock') as $name)
    eval(loadFunctionDefinition($source, $name));

$suite = new StrictTestSuite();
$suite->test('profile flock serializes recovery with a user worker without sysvsem', function () {
    $root = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/datadir-lock-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    FileUtil::$settings = $root;
    try {
        $first = rtDataDirLock(false);
        strictAssertTrue(is_resource($first), 'worker holds an actual lock');
        strictAssertSame(false, rtDataDirLock(true), 'scheduler skips while worker owns it');
        rtDataDirUnlock($first);
        $next = rtDataDirLock(true);
        strictAssertTrue(is_resource($next), 'scheduler can recover after worker exits');
        rtDataDirUnlock($next);
    } finally {
        @unlink($root . '/.lock');
        @rmdir($root);
    }
});
exit($suite->run());
