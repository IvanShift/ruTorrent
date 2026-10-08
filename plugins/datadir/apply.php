<?php

// The directory-change transaction shared by the web request (Set directory)
// and the CLI worker (payload moves). Each entry point validates its request
// with rtDataDirRequestRefusal() and reads the claim ABI once with
// rtDataDirClaimRefusal(); nothing below repeats either.

// The hash, the configured top (resolving the physical path in place) and the
// trust of the destination's existing ancestor. Returns '' or a refusal.
function rtDataDirRequestRefusal($hash, &$destPath)
{
    if (!is_string($hash) || preg_match('/^[0-9A-Fa-f]{40}$/D', $hash) !== 1)
        return 'invalid-arguments';
    if (!is_string($destPath) || $destPath === ''
        || !rTorrentSettings::get()->correctDirectory($destPath, true))
        return 'destination-invalid';
    return DataDirMoveIntent::destinationReason($destPath);
}

// The one mapping from the daemon's claim ABI to a request refusal.
function rtDataDirClaimRefusal()
{
    $capability = rtDataDirClaimCapability();
    return $capability === 'available' ? ''
        : ($capability === 'unsupported' ? 'daemon-claim-unavailable' : 'daemon-claim-unconfirmed');
}

// Creates the destination and re-validates the created physical path before
// the claimed change. At most 0755, since the umask only removes bits: never
// group/other-writable, which the shared payload trust rule refuses above the
// configured top.
function rtDataDirApply($hash, $destPath, $addPath, $moveFiles, $fastResume, $dbg = false,
    &$failure = null, &$warning = null)
{
    $failure = null;
    $warning = '';
    if (!rtMkDir($destPath, 0755)) $failure = 'destination-create-failed';
    elseif (!rTorrentSettings::get()->correctDirectory($destPath, true)) $failure = 'destination-invalid';
    if ($failure !== null) {
        FileUtil::toLog('datadir: change refused hash=' . strtoupper($hash) . ' reason=' . $failure);
        return false;
    }
    return rtSetDataDir($hash, $destPath, $addPath, $moveFiles, $fastResume, $dbg, $failure, $warning);
}

// Lock, replay this profile's interrupted jobs, apply, unlock. The web
// request ($web) never waits for the lock and replays only directory-only
// jobs: a running or interrupted payload move belongs to its CLI worker or to
// the scheduled recovery, and the dialog sees datadir-busy instead of an FPM
// worker blocked behind it. The CLI worker waits for its turn.
function rtDataDirApplyLocked($hash, $destPath, $addPath, $moveFiles, $fastResume, $dbg, $web,
    &$failure = null, &$warning = null)
{
    $failure = null;
    $warning = '';
    try {
        $lock = rtDataDirLock($web);
        if ($lock === false)
            $failure = 'datadir-busy';
    } catch (RuntimeException $e) {
        $failure = $e->getMessage();
    }
    if ($failure !== null) {
        FileUtil::toLog('datadir: change refused hash=' . strtoupper($hash) . ' reason=' . $failure);
        return false;
    }
    try {
        rtDataDirRecover(!$web);
        return rtDataDirApply($hash, $destPath, $addPath, $moveFiles, $fastResume, $dbg,
            $failure, $warning);
    } finally {
        rtDataDirUnlock($lock);
    }
}
