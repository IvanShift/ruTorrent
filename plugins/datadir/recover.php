<?php

if (PHP_SAPI !== 'cli' || !isset($_SERVER['SCRIPT_FILENAME'])
    || realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__)
    exit(1);
if (!chdir(__DIR__))
    exit(1);
if (isset($argv[1]) && $argv[1] !== '--all')
    $_SERVER['REMOTE_USER'] = $argv[1];

require_once '../../php/xmlrpc.php';
require_once './util_setdir.php';

if (isset($argv[1]) && $argv[1] === '--all') {
    try {
        $profiles = rtDataDirRecoveryProfiles();
    } catch (Exception $e) {
        FileUtil::toLog('datadir: global recovery refused: ' . $e->getMessage());
        exit(1);
    }
    $ok = true;
    foreach ($profiles as $profile) {
        $descriptors = array(
            0 => array('file', '/dev/null', 'r'),
            1 => array('file', '/dev/null', 'w'),
            2 => array('file', '/dev/null', 'w'),
        );
        $child = @proc_open(array(Utility::getPHP(), __FILE__,
            $profile === '~default' ? '' : $profile), $descriptors, $pipes);
        if (!is_resource($child) || proc_close($child) !== 0) {
            FileUtil::toLog('datadir: global recovery held profile=' . $profile
                . ' reason=worker-failed');
            $ok = false;
        }
    }
    exit($ok ? 0 : 1);
}

try {
    $lock = rtDataDirLock(true);
} catch (Exception $e) {
    FileUtil::toLog('datadir: recovery refused: ' . $e->getMessage());
    exit(1);
}
if ($lock === false) {
    FileUtil::toLog('datadir: recovery deferred: worker-busy');
    exit(0);
}
$ok = rtDataDirRecover();
rtDataDirUnlock($lock);
exit($ok ? 0 : 1);
