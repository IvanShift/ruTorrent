<?php

// QUAL-RU-15: datadir reaches the torrent presence probe through the small
// shared plugins/erasedata/presence.php and no longer loads the erasedata
// removal machinery in removewithdata.php. The real util_setdir.php is loaded
// in a PHP process of its own, from its own directory, as its entry points do.

require_once __DIR__ . '/../rutracker_check/TestLib.php';

function presenceSourceProbe()
{
    $script = <<<'SCRIPT'
require 'util_setdir.php';
$presence = new ReflectionFunction('erasedataTorrentPresence');
echo 'PRESENCE_RESULT:' . json_encode(array(
    'files' => array_map('realpath', get_included_files()),
    'presence' => realpath($presence->getFileName()),
    'constants' => array(ERASEDATA_TORRENT_PRESENT, ERASEDATA_TORRENT_ABSENT, ERASEDATA_TORRENT_UNKNOWN),
    'filesystem' => class_exists('ErasedataFilesystemOps', false),
));
SCRIPT;
    $process = proc_open(array(PHP_BINARY, '-r', $script),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes, testFindRepoRoot() . '/plugins/datadir', $_ENV);
    if (!is_resource($process)) throw new RuntimeException('cannot start the util_setdir.php probe');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strpos($output, 'PRESENCE_RESULT:');
    if ($exit !== 0 || $at === false)
        throw new RuntimeException('util_setdir.php probe failed: ' . $output . ' ' . $errors);
    $value = json_decode(substr($output, $at + strlen('PRESENCE_RESULT:')), true);
    if (!is_array($value)) throw new RuntimeException('invalid util_setdir.php probe answer');
    return $value;
}

$suite = new StrictTestSuite();

$suite->test('datadir loads the shared presence probe without removewithdata.php', function () {
    $root = testFindRepoRoot();
    $answer = presenceSourceProbe();
    strictAssertSame(realpath($root . '/plugins/erasedata/presence.php'), $answer['presence'],
        'erasedataTorrentPresence() comes from the small shared file');
    strictAssertSame(false, in_array(realpath($root . '/plugins/erasedata/removewithdata.php'), $answer['files'], true),
        'datadir does not load the erasedata removal machinery');
    strictAssertSame(array(1, 0, -1), $answer['constants'], 'the three presence answers keep their values');
    strictAssertSame(true, $answer['filesystem'], 'the shared no-replace owner is still loaded');
});

exit($suite->run());
