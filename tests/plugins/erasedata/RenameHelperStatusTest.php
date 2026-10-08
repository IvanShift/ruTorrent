<?php

// The rename helper's exit statuses are an ABI between a C binary, the PHP
// process runner, the helper's own shell test and the operator documentation.
// ErasedataFilesystemOps::RENAME_HELPER_STATUS is the one table; this file
// checks every other spelling of it in this repository against that table,
// and drives the real process runner with each status. docker-rutorrent's
// startup probe report lives in another repository and reads the same values.

require_once __DIR__ . '/../rutracker_check/TestLib.php';

class FileUtil
{
    public static $log = array();
    public static function toLog($line) { self::$log[] = $line; }
}

require_once testFindRepoRoot() . '/plugins/erasedata/filesystem.php';

// The real runner and status table behind a scripted helper executable.
class RenameHelperStatusScript extends ErasedataFilesystemOps
{
    public static $helper = null;
    public static function renameHelperPath() { return self::$helper; }
}

function renameStatusRoot()
{
    $root = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/rename-status-' . bin2hex(random_bytes(6));
    if (!mkdir($root, 0700))
        throw new RuntimeException('fixture directory was not created');
    return $root;
}

function renameStatusHelper($root, $status, $stderr = '')
{
    $path = $root . '/helper-' . $status;
    file_put_contents($path, "#!/bin/sh\n"
        . ($stderr === '' ? '' : 'printf \'%s\\n\' ' . escapeshellarg($stderr) . " >&2\n")
        . 'exit ' . (int) $status . "\n");
    chmod($path, 0700);
    return $path;
}

// STATUS_<NAME> as the C enum and the shell constants spell a table reason.
function renameStatusName($reason)
{
    $name = $reason === 'ok' ? 'ok' : substr($reason, strlen('rename-noreplace-'));
    return 'STATUS_' . strtoupper(str_replace('-', '_', $name));
}

$suite = new StrictTestSuite();

$suite->test('the status table is the one definition its C, shell and document spellings follow', function () {
    $root = testFindRepoRoot();
    $table = ErasedataFilesystemOps::RENAME_HELPER_STATUS;
    strictAssertSame(array(0, 1, 2, 3, 4, 5), array_keys($table), 'the helper ABI has six statuses');
    $expected = array();
    foreach ($table as $status => $entry)
        $expected[renameStatusName($entry[0])] = $status;

    $c = file_get_contents($root . '/plugins/erasedata/rename-noreplace.c');
    strictAssertTrue(preg_match('/enum rename_status\s*\{(.*?)\};/s', $c, $enum) === 1,
        'the C helper names its statuses in one enum');
    preg_match_all('/\b(STATUS_[A-Z_]+)\s*=\s*([0-9]+)\s*,/', $enum[1], $pairs, PREG_SET_ORDER);
    $named = array();
    foreach ($pairs as $pair) $named[$pair[1]] = (int) $pair[2];
    strictAssertSame($expected, $named, 'the C enum spells the PHP table');
    strictAssertTrue(preg_match('/\nint main\(int argc, char \*\*argv\)\n\{(.*)\n\}\n*$/s', $c, $main) === 1,
        'the C entry point is found');
    strictAssertSame(0, preg_match('/\breturn\s+[0-9]+\s*;/', $main[1])
        + preg_match('/\bstatus\s*=\s*[0-9]+\s*;/', $c),
        'the C helper exits and classifies only through named statuses');

    // Every refusal reason the helper prints is paired with its table status.
    preg_match_all('/reason = "(rename-noreplace-[a-z-]+)";\s*(?:int )?status = (STATUS_[A-Z_]+);/',
        $c, $printed, PREG_SET_ORDER);
    strictAssertTrue(preg_match('/"(rename-noreplace-invalid-arguments)\\\\n"\);\s*return (STATUS_[A-Z_]+);/',
        $c, $invalid) === 1, 'the argument refusal prints its reason and returns a named status');
    $printed[] = $invalid;
    $refusals = array();
    foreach ($printed as $pair) $refusals[$pair[1]] = $named[$pair[2]];
    ksort($refusals);
    $tableRefusals = array();
    foreach ($table as $status => $entry)
        if ($entry[1] === 'refusal') $tableRefusals[$entry[0]] = $status;
    ksort($tableRefusals);
    strictAssertSame($tableRefusals, $refusals, 'each printed reason carries its table status');
    strictAssertTrue(strpos($c, '(fallback_used ? STATUS_FALLBACK : STATUS_OK)') !== false,
        'only a successful probe reports the fallback status');

    $shell = file_get_contents($root . '/tests/plugins/erasedata/rename-noreplace-test.sh');
    strictAssertTrue(preg_match('/^readonly (STATUS_[A-Z_]+=[0-9]+(?: STATUS_[A-Z_]+=[0-9]+)*)$/m',
        $shell, $line) === 1, 'the shell test declares the statuses once');
    $constants = array();
    foreach (explode(' ', $line[1]) as $assignment) {
        list($name, $value) = explode('=', $assignment);
        $constants[$name] = (int) $value;
    }
    strictAssertSame($expected, $constants, 'the shell test spells the PHP table');
    strictAssertSame(0, preg_match('/\$\{?[a-z_]*status\}?"?\s*==\s*"?[0-9]/', $shell),
        'the shell test compares statuses only through the named constants');

    $document = file_get_contents($root . '/plugins/erasedata/QUEUE-ATTESTATION.md');
    preg_match_all('/^\| `([0-9]+)` \| `([a-z-]+)` \|/m', $document, $rows, PREG_SET_ORDER);
    $documented = array();
    foreach ($rows as $row) $documented[(int) $row[1]] = $row[2];
    $reasons = array();
    foreach ($table as $status => $entry) $reasons[$status] = $entry[0];
    strictAssertSame($reasons, $documented, 'the operator document lists the PHP table');
});

$suite->test('each status keeps its meaning through the shared process runner', function () {
    $root = renameStatusRoot();
    try {
        $files = new RenameHelperStatusScript();
        $expected = array(
            0 => array(true, true, ''),
            1 => array(false, false, 'rename-noreplace-collision'),
            2 => array(false, false, 'rename-noreplace-invalid-arguments'),
            3 => array(false, false, 'rename-noreplace-unsupported'),
            4 => array(false, false, 'rename-noreplace-error'),
            5 => array(true, false, 'rename-noreplace-process-exit-5'),
            6 => array(false, false, 'rename-noreplace-process-exit-6'),
        );
        foreach ($expected as $status => $outcome) {
            RenameHelperStatusScript::$helper = renameStatusHelper($root, $status,
                $status === 4 ? 'rename-noreplace-error: errno=13 Permission denied' : '');
            strictAssertSame($outcome[0], $files->probeRenameParent($root),
                'a probe answering ' . $status . ' has the table meaning');
            if (!$outcome[0])
                strictAssertSame($outcome[2], ErasedataFilesystemOps::lastRenameRefusal(),
                    'a refused probe ' . $status . ' keeps its classified reason');
            strictAssertSame($outcome[1], $files->renameNoReplace($root . '/from', $root . '/to'),
                'a rename answering ' . $status . ' has the table meaning');
            strictAssertSame($outcome[2], ErasedataFilesystemOps::lastRenameRefusal(),
                'a rename answering ' . $status . ' keeps its classified reason');
        }
        strictAssertTrue(strpos(ErasedataFilesystemOps::lastRenameRefusalContext(), ' destination=') !== false,
            'a rename refusal names both entries');
        RenameHelperStatusScript::$helper = renameStatusHelper($root, 4,
            'rename-noreplace-error: errno=13 Permission denied');
        $files->renameNoReplace($root . '/from', $root . '/to');
        strictAssertTrue(substr(ErasedataFilesystemOps::lastRenameRefusalContext(), -9) === ' errno=13',
            'the native errno reaches the classified context');
    } finally { strictRemoveTree($root); }
});

$suite->test('status 3 advice describes the refusal that remains after the fallback', function () {
    $root = renameStatusRoot();
    try {
        $files = new RenameHelperStatusScript();
        RenameHelperStatusScript::$helper = renameStatusHelper($root, 3,
            'rename-noreplace-unsupported: errno=22 Invalid argument');
        strictAssertSame(false, $files->probeRenameParent($root), 'a probe refusal is refused');
        $probe = ErasedataFilesystemOps::renameRefusalReport();
        strictAssertTrue(strpos($probe, 'rename-noreplace-unsupported path=') === 0
            && strpos($probe, 'probe self-check') !== false, 'a probe 3 names the self-check or a refused rename: ' . $probe);
        strictAssertSame(false, $files->renameNoReplace($root . '/dir', $root . '/dir/child'), 'a rename refusal is refused');
        $rename = ErasedataFilesystemOps::renameRefusalReport();
        strictAssertTrue(strpos($rename, 'its own subtree') !== false
            && strpos($rename, 'errno=22') !== false, 'a rename 3 names the plain rename refusal: ' . $rename);
        foreach (array($probe, $rename) as $report)
            strictAssertTrue(strpos($report, 'supports atomic no-replace') === false,
                'no advice asks for the atomic flag the fallback made optional');
    } finally { strictRemoveTree($root); }
});

$suite->test('a consumer reports the refusal under its own name and the runner logs nothing', function () {
    $root = renameStatusRoot();
    try {
        FileUtil::$log = array();
        $files = new RenameHelperStatusScript();
        RenameHelperStatusScript::$helper = renameStatusHelper($root, 1);
        strictAssertSame(false, $files->renameNoReplace($root . '/from', $root . '/to'), 'a collision refuses');
        strictAssertSame(array(), FileUtil::$log, 'the shared runner leaves reporting to its caller');
        ErasedataFilesystemOps::reportRenameRefusal('datadir');
        strictAssertSame(1, count(FileUtil::$log), 'one report line is written');
        strictAssertTrue(strpos(FileUtil::$log[0], 'datadir: rename-noreplace-collision path=') === 0
            && strpos(FileUtil::$log[0], 'preserve both entries') !== false, 'the line names its consumer: ' . FileUtil::$log[0]);
        ErasedataFilesystemOps::clearRenameRefusal();
        ErasedataFilesystemOps::reportRenameRefusal();
        strictAssertSame(1, count(FileUtil::$log), 'no pending refusal writes no line');
    } finally { strictRemoveTree($root); }
});

exit($suite->run());
