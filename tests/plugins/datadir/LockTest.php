<?php

require_once __DIR__ . '/../rutracker_check/TestLib.php';

function rtDataDirJournal() { return FileUtil::$settings; }
class FileUtil
{
    public static $settings;
    public static $logs = array();
    public static function toLog($line) { self::$logs[] = $line; }
}

// What the shared transaction runs under the lock, recorded.
class LockTestState
{
    public static $recovered = array();
    public static $applied = 0;
}
function rtDataDirRecover($payloadMoves = true) { LockTestState::$recovered[] = $payloadMoves; return true; }
function rtDataDirApply($hash, $destPath, $addPath, $moveFiles, $fastResume, $dbg = false,
    &$failure = null, &$warning = null)
{
    LockTestState::$applied++;
    return true;
}

$source = __DIR__ . '/../../../plugins/datadir/util_setdir.php';
foreach (array('rtDataDirLock', 'rtDataDirUnlock') as $name)
    eval(loadFunctionDefinition($source, $name));
eval(loadFunctionDefinition(__DIR__ . '/../../../plugins/datadir/apply.php', 'rtDataDirApplyLocked'));

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
$suite->test('a web change answers datadir-busy at once behind a held profile lock', function () {
    $root = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/datadir-lock-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    FileUtil::$settings = $root;
    $hash = str_repeat('A', 40);
    try {
        $held = rtDataDirLock(false);
        $started = microtime(true);
        strictAssertSame(false, rtDataDirApplyLocked($hash, '/data/new', true, false, false, false, true,
            $failure, $warning), 'a busy profile refuses the web change');
        strictAssertTrue(microtime(true) - $started < 1, 'the web request does not wait for the lock');
        strictAssertSame('datadir-busy', $failure, 'the dialog receives a classified busy profile');
        strictAssertSame(array('datadir: change refused hash=' . $hash . ' reason=datadir-busy'), FileUtil::$logs,
            'the busy refusal is visible once');
        strictAssertSame(array(), LockTestState::$recovered, 'nothing is replayed without the lock');
        strictAssertSame(0, LockTestState::$applied, 'nothing is applied without the lock');
        rtDataDirUnlock($held);
        strictAssertSame(true, rtDataDirApplyLocked($hash, '/data/new', true, false, false, false, true,
            $failure, $warning), 'a free profile applies the web change');
        strictAssertSame(true, rtDataDirApplyLocked($hash, '/data/new', true, true, false, false, false,
            $failure, $warning), 'the CLI worker applies its change');
        strictAssertSame(array(false, true), LockTestState::$recovered,
            'the web request replays directory-only jobs, the worker every job');
        strictAssertSame(2, LockTestState::$applied, 'each change is applied once under the lock');
        $next = rtDataDirLock(true);
        strictAssertTrue(is_resource($next), 'both calls released the lock');
        rtDataDirUnlock($next);
    } finally {
        @unlink($root . '/.lock');
        @rmdir($root);
    }
});
exit($suite->run());
