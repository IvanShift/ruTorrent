<?php
require_once __DIR__ . '/../rutracker_check/TestLib.php';
require_once __DIR__ . '/../../../plugins/autotools/file_paths.php';

$suite = new StrictTestSuite();
$suite->test('a daemon single-file basename is exactly one unchanged component', function () {
    foreach (['read', 'forSynchronousHook'] as $method) {
        foreach (['dir/file', '../foreign', '/foreign', '', '.', '..', "bad\0name"] as $name) {
            $refused = false;
            try {
                if ($method === 'read') { AutoToolsFilePaths::read('hash', ['name'=>'metadata'], false, $name); }
                else { AutoToolsFilePaths::forSynchronousHook(['name'=>'metadata'], false, $name); }
            } catch (RuntimeException $error) { $refused = true; }
            strictAssertSame(true, $refused, 'unsafe single basename is refused: ' . bin2hex($name));
        }
        foreach (['selected серия.mkv', "selected\xFF.bin", 'quote " and \\ byte'] as $name) {
            $actual = $method === 'read' ? AutoToolsFilePaths::read('hash', ['name'=>'metadata'], false, $name)
                : AutoToolsFilePaths::forSynchronousHook(['name'=>'metadata'], false, $name);
            strictAssertSame([$name], $actual, 'the captured daemon basename remains unchanged');
        }
    }
});
$suite->test('a disabled component splitter cannot turn traversal into an accepted manifest', function () {
    $code = 'require ' . var_export(__DIR__ . '/../../../plugins/autotools/file_paths.php', true) . ';'
        . 'try { AutoToolsFilePaths::forSynchronousHook(array("files"=>array(array("path"=>array("..","foreign")))),true,"root");'
        . 'echo "accepted"; } catch(Throwable $error) { echo "refused"; }';
    $process = proc_open([PHP_BINARY, '-d', 'disable_functions=explode', '-r', $code],
        [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('cannot start disabled-function regression'); }
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    strictAssertSame(0, proc_close($process), 'the disabled dependency has a controlled outcome: ' . $error);
    strictAssertSame('refused', $output, 'missing splitting must refuse before a physical path is handed out');
});
$suite->test('an unavailable alias-key test cannot authorize a guessed synchronous path', function () {
    $code = 'require ' . var_export(__DIR__ . '/../../../plugins/autotools/file_paths.php', true) . ';'
        . 'try { AutoToolsFilePaths::forSynchronousHook(array("files"=>array(array("path"=>array("raw"),"path.utf-8"=>array("selected")))),true,"root");'
        . 'echo "accepted"; } catch(Throwable $error) { echo "refused"; }';
    $process = proc_open([PHP_BINARY, '-d', 'disable_functions=array_key_exists', '-r', $code],
        [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('cannot start disabled alias-key regression'); }
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    strictAssertSame(0, proc_close($process), 'the unavailable alias check has a controlled outcome: ' . $error);
    strictAssertSame('refused', $output, 'unknown alias presence refuses before filesystem operations');
});
exit($suite->run());
