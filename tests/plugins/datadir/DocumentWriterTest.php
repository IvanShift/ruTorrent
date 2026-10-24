<?php

// QUAL-RU-DEAD-7: datadir's job journal and its payload move receipt are
// written by one writer, and a document whose private mode cannot be set is
// never published. A preloaded chmod() that refuses stands for a filesystem
// whose chmod fails; the real writers run in a PHP process of their own.

require_once __DIR__ . '/../rutracker_check/TestLib.php';

// A shared library whose chmod() always fails with EPERM, or null when this
// runtime has no C compiler.
function documentWriterShim($dir)
{
    $compiler = trim((string) shell_exec('command -v cc 2>/dev/null'));
    if ($compiler === '') return null;
    file_put_contents($dir . '/nochmod.c', "#include <errno.h>\n#include <sys/types.h>\n"
        . "int chmod(const char *path, mode_t mode) { (void) path; (void) mode; errno = EPERM; return -1; }\n");
    exec(escapeshellarg($compiler) . ' -shared -fPIC -o ' . escapeshellarg($dir . '/nochmod.so') . ' '
        . escapeshellarg($dir . '/nochmod.c') . ' 2>&1', $output, $status);
    if ($status !== 0) throw new RuntimeException('the chmod shim did not compile: ' . implode("\n", $output));
    return $dir . '/nochmod.so';
}

// One single-file payload move and one directory-only job, built here with
// explicit modes so that only the writers run under the shim.
function documentWriterFixture()
{
    $dir = realpath(getenv('TMPDIR') ?: sys_get_temp_dir()) . '/datadir-document-' . bin2hex(random_bytes(5));
    foreach (array('', '/from', '/to', '/receipts', '/jobs', '/shim') as $directory)
        if (!mkdir($dir . $directory, 0700))
            throw new RuntimeException('fixture directory was not created');
    foreach (array('/from/a', '/probe') as $file) {
        file_put_contents($dir . $file, 'A');
        chmod($dir . $file, 0600);
    }
    return $dir;
}

function documentWriterRun($dir, $library)
{
    $script = <<<'SCRIPT'
class FileUtil { public static function toLog($message) {} }
require 'plugins/datadir/movejob.php';
$dir = getenv('DOCUMENT_DIR');
$result = array('chmod_refused' => !@chmod($dir . '/probe', 0600));
try {
    DataDirMoveIntent::prepare($dir . '/receipts/' . str_repeat('B', 40) . '.json', str_repeat('b', 32),
        str_repeat('B', 40), $dir . '/from', $dir . '/to', array('a'), 'rename');
    $result['receipt'] = 'published';
} catch (RuntimeException $e) { $result['receipt'] = $e->getMessage(); }
try {
    DataDirMoveJob::create($dir . '/jobs', array('hash' => str_repeat('C', 40), 'directory' => $dir . '/to/',
        'local_id' => 'local-1', 'files' => array(), 'source' => $dir . '/from', 'destination' => $dir . '/to',
        'add' => false, 'move' => false, 'previous_directory' => $dir . '/from/'), 'strlen', 'strlen', 'strlen');
    $result['job'] = 'published';
} catch (RuntimeException $e) { $result['job'] = $e->getMessage(); }
$result['receipts'] = array_values(array_diff(scandir($dir . '/receipts'), array('.', '..')));
$result['jobs'] = array_values(array_diff(scandir($dir . '/jobs'), array('.', '..')));
echo 'DOCUMENT_RESULT:' . json_encode($result);
SCRIPT;
    $environment = array_merge($_ENV, array('DOCUMENT_DIR' => $dir));
    if ($library !== null) $environment['LD_PRELOAD'] = $library;
    $process = proc_open(array(PHP_BINARY, '-r', $script),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes, testFindRepoRoot(), $environment);
    if (!is_resource($process)) throw new RuntimeException('cannot start the document writer probe');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strpos($output, 'DOCUMENT_RESULT:');
    if ($exit !== 0 || $at === false)
        throw new RuntimeException('document writer probe failed: ' . $output . ' ' . $errors);
    return json_decode(substr($output, $at + strlen('DOCUMENT_RESULT:')), true);
}

$suite = new StrictTestSuite();

$suite->test('both datadir documents are published when their mode can be set', function () {
    $dir = documentWriterFixture();
    try {
        $answer = documentWriterRun($dir, null);
        strictAssertSame(false, $answer['chmod_refused'], 'the control run has a working chmod');
        strictAssertSame('published', $answer['receipt'], 'the move receipt is published');
        strictAssertSame(array(str_repeat('B', 40) . '.json'), $answer['receipts'], 'only the receipt is left');
        strictAssertSame('published', $answer['job'], 'the job is published');
        strictAssertSame(array(str_repeat('C', 40) . '.json'), $answer['jobs'], 'only the job is left');
    } finally { strictRemoveTree($dir); }
});

$suite->test('a datadir document whose private mode cannot be set is never published', function () {
    $dir = documentWriterFixture();
    try {
        $library = documentWriterShim($dir . '/shim');
        if ($library === null) {
            echo "scope - the chmod shim needs a C compiler and did not run\n";
            return;
        }
        $answer = documentWriterRun($dir, $library);
        strictAssertSame(true, $answer['chmod_refused'], 'the preloaded chmod refuses in the probe');
        strictAssertSame('receipt-publish-failed', $answer['receipt'], 'the move receipt refuses publication');
        strictAssertSame(array(), $answer['receipts'], 'no receipt and no staging file is left');
        strictAssertSame('job-publish-failed', $answer['job'], 'the job refuses publication');
        strictAssertSame(array(), $answer['jobs'], 'no job and no staging file is left');
    } finally { strictRemoveTree($dir); }
});

// R4-FS-08: tempnam() falls back to the system temporary directory when the
// target directory takes no new file. A rename from there that crosses
// filesystems is a copy over the live document, not an atomic replace, so
// the writer refuses before it writes anything. Only a non-root UID is
// refused a new file by the directory mode.
$suite->test('a document directory that takes no new file refuses before the live document changes', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        echo "scope - root creates files in a read-only directory; the case did not run\n";
        return;
    }
    $dir = documentWriterFixture();
    $other = null;
    try {
        // A temporary directory on another filesystem makes PHP's rename() a
        // copy, which is the harm; without one the refusal is still checked.
        $shm = '/dev/shm/datadir-document-' . bin2hex(random_bytes(5));
        if (@mkdir($shm, 0700) && @stat($shm)['dev'] !== @stat($dir)['dev']) $other = $shm;
        elseif (is_dir($shm)) rmdir($shm);
        $temp = $other !== null ? $other : $dir . '/jobs';
        mkdir($dir . '/locked', 0700);
        file_put_contents($dir . '/locked/doc.json', '{"old":true}');
        chmod($dir . '/locked/doc.json', 0600);
        chmod($dir . '/locked', 0500);
        $script = <<<'SCRIPT'
class FileUtil { public static function toLog($message) {} }
require 'plugins/datadir/movejob.php';
$path = getenv('DOCUMENT_DIR') . '/locked/doc.json';
try { DataDirDocument::publish($path, array('new' => true), false, 'receipt'); $result = 'published'; }
catch (RuntimeException $e) { $result = $e->getMessage(); }
echo 'DOCUMENT_RESULT:' . json_encode(array('result' => $result, 'temp' => sys_get_temp_dir()));
SCRIPT;
        $process = proc_open(array(PHP_BINARY, '-r', $script),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes, testFindRepoRoot(), array_merge($_ENV, array('DOCUMENT_DIR' => $dir, 'TMPDIR' => $temp)));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $at = strpos($output, 'DOCUMENT_RESULT:');
        if ($at === false) throw new RuntimeException('document writer probe failed: ' . $output . ' ' . $errors);
        $answer = json_decode(substr($output, $at + strlen('DOCUMENT_RESULT:')), true);
        strictAssertSame(realpath($temp), realpath($answer['temp']), 'the probe falls back to the chosen directory');
        strictAssertSame('receipt-staging-failed', $answer['result'], 'the writer refuses the fallback staging file');
        strictAssertSame('{"old":true}', file_get_contents($dir . '/locked/doc.json'), 'the live document is unchanged');
        strictAssertSame(array(), preg_grep('/^\.datadir-intent-/', scandir($temp)), 'no staging file is left behind');
        if ($other === null) echo "scope - no second filesystem for the temporary directory; the copy case did not run\n";
    } finally {
        @chmod($dir . '/locked', 0700);
        if ($other !== null) strictRemoveTree($other);
        strictRemoveTree($dir);
    }
});


$suite->test('the historical large document still fits and oversized byte encoding refuses before staging', function () {
    $dir = documentWriterFixture();
    try {
        $script = <<<'SCRIPT'
require 'plugins/datadir/moveintent.php';
$root = getenv('DOCUMENT_DIR'); $result = array();
foreach (array('valid', 'raw') as $mode) {
    $document = array('version' => 2, 'files' => array());
    for ($i = 0; $i < 100000; $i++) $document['files'][] = array(
        'path' => str_repeat('x', 80) . $i . ($mode === 'raw' ? "\xFF" : 'a'), 'identity' => '12345678:123456789');
    $path = $root . '/jobs/' . $mode . '.json';
    try { DataDirDocument::publish($path, $document, true, 'job'); $result[$mode] = 'published'; }
    catch (RuntimeException $error) { $result[$mode] = $error->getMessage(); }
    unset($document);
}
$result['files'] = array_values(array_diff(scandir($root . '/jobs'), array('.', '..')));
echo json_encode($result);
SCRIPT;
        $process = proc_open(array(PHP_BINARY, '-d', 'memory_limit=128M', '-r', $script),
            array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes, testFindRepoRoot(), array_merge($_ENV, array('DOCUMENT_DIR' => $dir)));
        if (!is_resource($process)) throw new RuntimeException('large document child unavailable');
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($process);
        strictAssertSame(0, $status, 'the existing 128 MiB worker finishes without an allocation failure: ' . $errors);
        strictAssertSame('', $errors, 'the bounded refusal emits no PHP failure');
        strictAssertSame(array('valid' => 'published', 'raw' => 'job-too-large', 'files' => array('valid.json')),
            json_decode($output, true), 'old valid workload passes while the 16 MiB byte document limit refuses before staging');
    } finally { strictRemoveTree($dir); }
});

exit($suite->run());
