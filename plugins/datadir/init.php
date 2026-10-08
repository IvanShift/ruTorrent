<?php

require_once __DIR__ . '/../../php/xmlrpc.php';

$command = escapeshellarg(Utility::getPHP()) . ' '
    . escapeshellarg(__DIR__ . '/recover.php') . ' --all';
// One daemon-wide scan covers every protected profile journal.
$schedule = new rXMLRPCCommand('schedule', array('datadir-recover-all',
    rTorrentSettings::getAlignedStart('datadir-recover-all', 60) . '', '60',
    getCmd('execute') . '={sh,-c,' . $command . ' &}'));
$request = new rXMLRPCRequest($schedule);
$request->important = false;
if ($request->success()) {
    $theSettings->registerPlugin($plugin['name'], $pInfo['perms']);
    // Outcomes a tracker answer erased from d.message, once per session. A
    // missing file must not break the page every plugin is served from.
    if (is_file(__DIR__ . '/../erasedata/outcome.php')) {
        require_once __DIR__ . '/../erasedata/outcome.php';
        $jResult .= FileOperationOutcome::sessionNotice('datadir');
    }
} else {
    FileUtil::toLog('datadir: recovery schedule unavailable');
    $jResult .= "plugin.disable(); noty('datadir: '+theUILang.pluginCantStart,'error');";
}
