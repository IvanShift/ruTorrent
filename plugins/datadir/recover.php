<?php
require_once dirname(__DIR__, 2).'/php/cli-only.php';
rutorrent_require_cli(__FILE__);


if (PHP_SAPI !== 'cli' || !isset($_SERVER['SCRIPT_FILENAME'])
    || realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__)
    exit(1);
if (!chdir(__DIR__))
    exit(1);
if (isset($argv[1]) && $argv[1] === '--retry') {
    if (count($argv) !== 4 || preg_match('/^[0-9A-Fa-f]{40}$/D', $argv[2]) !== 1
        || preg_match('/^[a-z0-9_-]*$/D', $argv[3]) !== 1) {
        fwrite(STDERR, "Usage: recover.php --retry HASH USER (empty USER for the default profile)\n");
        exit(2);
    }
    $_SERVER['REMOTE_USER'] = $argv[3];
} elseif (isset($argv[1]) && $argv[1] !== '--all')
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
    if (isset($argv[1]) && $argv[1] === '--retry') {
        fwrite(STDERR, "datadir: job retained reason=worker-busy; retry after the current worker finishes\n");
        exit(1);
    }
    exit(0);
}
if (isset($argv[1]) && $argv[1] === '--retry') {
    $hash = strtoupper($argv[2]);
    $path = rtDataDirJournal() . '/' . $hash . '.json';
    try {
        $job = DataDirMoveJob::load($path, 'rtDataDirClaimRpc',
            'rtDataDirMoveNoReplace', 'rtDataDirTorrentPresence');
        $job->run();
        FileOperationOutcome::clear('datadir', $hash);
        fwrite(STDOUT, "datadir: exact job recovered hash=" . $hash . "\n");
        $ok = true;
    } catch (DataDirMoveRefused $e) {
        rtDataDirWorkerRefusal($hash, $e->getMessage());
        fwrite(STDOUT, "datadir: exact job cancelled reason=" . $e->getMessage() . "\n");
        $ok = true;
    } catch (Exception $e) {
        rtDataDirHeld($hash, $e->getMessage(), $path);
        fwrite(STDERR, "datadir: job retained reason=" . $e->getMessage()
            . " document=" . rawurlencode($path) . " key=" . $hash
            . "; see plugins/datadir/RECOVERY.md\n");
        $ok = false;
    }
} else
    $ok = rtDataDirRecover();
rtDataDirUnlock($lock);
exit($ok ? 0 : 1);
