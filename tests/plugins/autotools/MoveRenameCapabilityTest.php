<?php
require_once __DIR__ . '/../rutracker_check/TestLib.php';

class FileUtil
{
    public static $messages = array();
    public static function toLog($message) { self::$messages[] = $message; }
}

// The shared filesystem owner decides whether a probed parent is usable.
// Its native-helper suite pins both ordinary and fallback probe exit codes.
class ErasedataFilesystemOps
{
    public static $calls = array();
    public static $accepted = true;
    public static function canRenameNoReplace($directory = null, $report = true)
    {
        self::$calls[] = array('probe', $directory, $report);
        return self::$accepted;
    }
    public static function lastRenameRefusal() { return 'rename-noreplace-error'; }
    public function renameNoReplace($source, $destination)
    {
        self::$calls[] = array('rename', $source, $destination);
        return self::$accepted;
    }
}
// The pre-change duplicate helper receives a real fallback-success probe,
// so its RED is about refusing exit 5, not a missing executable or import.
class NativeHelpers
{
    public static $path;
    public static function path($name) { return self::$path; }
}
NativeHelpers::$path = tempnam(sys_get_temp_dir(), 'autotools-probe-');
file_put_contents(NativeHelpers::$path, "#!/bin/sh\nif [ \"$1\" = --probe ]; then exit 5; fi\nexit 0\n");
chmod(NativeHelpers::$path, 0700);
register_shutdown_function(function () { @unlink(NativeHelpers::$path); });
eval(loadClassDefinition(testFindRepoRoot() . '/plugins/autotools/move_tx_lib.php', 'AutoToolsMoveTransaction'));

$suite = new StrictTestSuite();
$suite->test('AutoTools accepts a usable shared rename probe including fallback support', function () {
    ErasedataFilesystemOps::$calls = array(); ErasedataFilesystemOps::$accepted = true;
    $probe = new ReflectionMethod('AutoToolsMoveTransaction', 'requireRenameCapability');
    if (PHP_VERSION_ID < 80100) $probe->setAccessible(true);
    $probe->invoke(null, '/actual/parent');
    strictAssertSame(array(array('probe', '/actual/parent', false)), ErasedataFilesystemOps::$calls,
        'AutoTools delegates actual-parent admission to the shared helper owner');
});
$suite->test('AutoTools preserves a real shared probe refusal before a rename', function () {
    ErasedataFilesystemOps::$calls = array(); ErasedataFilesystemOps::$accepted = false;
    $probe = new ReflectionMethod('AutoToolsMoveTransaction', 'requireRenameCapability');
    if (PHP_VERSION_ID < 80100) $probe->setAccessible(true);
    try { $probe->invoke(null, '/actual/parent'); throw new RuntimeException('refusal was accepted'); }
    catch (RuntimeException $error) {
        strictAssertSame('rename-noreplace-error: /actual/parent', $error->getMessage(), 'real refusal stays classified');
    }
    strictAssertSame(array(array('probe', '/actual/parent', false)), ErasedataFilesystemOps::$calls,
        'refusal never reaches the rename');
});
$suite->test('AutoTools publication uses the shared no-replace operation', function () {
    ErasedataFilesystemOps::$calls = array(); ErasedataFilesystemOps::$accepted = true;
    $rename = new ReflectionMethod('AutoToolsMoveTransaction', 'renameNoReplace');
    if (PHP_VERSION_ID < 80100) $rename->setAccessible(true);
    strictAssertSame(true, $rename->invoke(null, '/source', '/destination'), 'shared publication succeeds');
    strictAssertSame(array(array('rename', '/source', '/destination')), ErasedataFilesystemOps::$calls,
        'AutoTools has no separate native-helper process contract');
});
$suite->test('AutoTools worker preparation uses shared helper admission before daemon state reads', function () {
    ErasedataFilesystemOps::$calls = array(); ErasedataFilesystemOps::$accepted = true;
    $prepare = new ReflectionMethod('AutoToolsMoveTransaction', 'prepare');
    if (PHP_VERSION_ID < 80100) $prepare->setAccessible(true);
    try {
        $prepare->invoke(null, array('invalid', '', '', '', '', '', '', ''));
        throw new RuntimeException('worker arguments were accepted');
    }
    catch (Throwable $error) {
        strictAssertSame('hash-invalid', $error->getMessage(),
            'preparation reaches ordinary worker validation through the shared helper owner');
    }
    strictAssertSame(array(array('probe', null, false)), ErasedataFilesystemOps::$calls,
        'worker preparation has no stale private helper launcher');
});
$suite->test('AutoTools worker preparation preserves unavailable shared helper refusal', function () {
    ErasedataFilesystemOps::$calls = array(); ErasedataFilesystemOps::$accepted = false;
    $prepare = new ReflectionMethod('AutoToolsMoveTransaction', 'prepare');
    if (PHP_VERSION_ID < 80100) $prepare->setAccessible(true);
    try {
        $prepare->invoke(null, array('invalid', '', '', '', '', '', '', ''));
        throw new RuntimeException('worker arguments were accepted');
    }
    catch (Throwable $error) {
        strictAssertSame('rename-noreplace-error', $error->getMessage(),
            'helper admission refuses before reading daemon state');
    }
    strictAssertSame(array(array('probe', null, false)), ErasedataFilesystemOps::$calls,
        'an unavailable helper has no fallback launcher');
});
exit($suite->run());
