<?php

if (!chdir(dirname(__FILE__))) exit(1);
$scope = isset($argv[1]) ? $argv[1] : 'All';
if (isset($argv[2])) $_SERVER['REMOTE_USER'] = $argv[2];
require_once(dirname(__FILE__) . '/move_tx_lib.php');
function rtRecoverFileJobs($scope)
{
    if ($scope === 'All' || $scope === 'Move') AutoToolsMoveTransaction::recoverAll();
    if ($scope === 'All' || $scope === 'NonMove') {
        $root = rtrim(rTorrentSettings::get()->session, '/')
            . '/' . AutoToolsMoveTransaction::JOURNAL_DIR;
        if (class_exists('AutoToolsFileTransaction')) AutoToolsFileTransaction::recoverAll($root);
        elseif ($scope === 'NonMove' || glob($root . '/*.nonmove.json'))
            FileUtil::toLog('autotools: nonmove recovery unavailable: transaction class missing');
    }
}
rtRecoverFileJobs($scope);
