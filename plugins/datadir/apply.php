<?php

// Callers hold rtDataDirLock() so replay cannot race a new profile-local job.
// Set directory returns this result in HTTP; payload moves use the CLI worker.
function rtDataDirApply($hash, $destPath, $addPath, $moveFiles, $fastResume, $dbg = false, &$failure = null)
{
    $failure = null;
    if (!is_string($hash) || preg_match('/^[0-9A-Fa-f]{40}$/D', $hash) !== 1
        || !is_string($destPath) || $destPath === '') {
        $failure = 'invalid-arguments';
        FileUtil::toLog('datadir: worker refused: ' . $failure);
        return false;
    }
    if (!rTorrentSettings::get()->correctDirectory($destPath, true)) {
        $failure = 'destination-invalid';
        FileUtil::toLog('datadir: ' . $failure . ' hash=' . $hash);
        return false;
    }
    $capability = rtDataDirClaimCapability();
    if ($capability !== 'available')
        $failure = $capability === 'unsupported' ? 'daemon-claim-unavailable' : 'daemon-claim-unconfirmed';
    else $failure = DataDirMoveIntent::destinationReason($destPath);
    if ($failure !== '' && $failure !== null) {
        FileUtil::toLog('datadir: worker refused hash=' . $hash . ' reason=' . $failure);
        return false;
    }
    $failure = null;
    if (!rtMkDir($destPath, 0755)) $failure = 'destination-create-failed';
    elseif (!rTorrentSettings::get()->correctDirectory($destPath, true)) $failure = 'destination-invalid';
    if ($failure !== null) {
        FileUtil::toLog('datadir: ' . $failure . ' hash=' . $hash);
        return false;
    }
    $result = rtSetDataDir($hash, $destPath, $addPath, $moveFiles, $fastResume, $dbg, $failure);
    if ($result === false) FileUtil::toLog('datadir: transfer-failed hash=' . $hash);
    return $result;
}
