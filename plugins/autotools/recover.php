<?php
require_once dirname(__DIR__, 2).'/php/cli-only.php';
rutorrent_require_cli(__FILE__);


if (!chdir(dirname(__FILE__))) exit(1);
$scope = isset($argv[1]) ? $argv[1] : 'All';
if (isset($argv[2])) $_SERVER['REMOTE_USER'] = $argv[2];
require_once(dirname(__FILE__) . '/move_tx_lib.php');
require_once(dirname(__FILE__) . '/hook_lib.php');
function rtRecoverFileJobs($scope)
{
    // One pass at a time: a pass that waits for session saves can outlast the
    // 60 s schedule, and the next one would only queue behind its lock. The
    // pass, which runs as the daemon's user, also makes and attests the
    // journal, so a finished hook finds it before it can need it: in a
    // session directory that has turned unwritable the hook can still record
    // a deferral there (R4 fresh-journal gap). setHandlers() runs one pass at
    // once while the journal is missing.
    $journal = AutoToolsFileTransaction::journalRoot(true);
    $pass = $journal === false ? null : @fopen($journal . '/.recover.lock', 'c');
    if ($pass && !@flock($pass, LOCK_EX | LOCK_NB)) {
        fclose($pass);
        return;
    }
    // First the finished hooks move.php deferred, so that the x-dest of what
    // they publish is confirmed by the non-Move recovery of the same pass.
    if ($scope === 'All' || $scope === 'NonMove') AutoToolsFinishedHook::performDeferred();
    if ($scope === 'All' || $scope === 'Move') AutoToolsMoveTransaction::recoverAll();
    if ($scope === 'All' || $scope === 'NonMove') {
        $root = rtrim(rTorrentSettings::get()->session, '/')
            . '/' . AutoToolsMoveTransaction::JOURNAL_DIR;
        if (class_exists('AutoToolsFileTransaction')) AutoToolsFileTransaction::recoverAll($root);
        elseif ($scope === 'NonMove' || erasedataDirectoryMatches($root, '', '.nonmove.json'))
            FileUtil::toLog('autotools: nonmove recovery unavailable: transaction class missing');
    }
}
rtRecoverFileJobs($scope);
