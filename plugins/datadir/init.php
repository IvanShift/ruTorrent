<?php

require_once __DIR__ . '/../../php/xmlrpc.php';
require_once __DIR__ . '/../../php/rtorrent.php';
require_once __DIR__ . '/recoverycommand.php';

// One daemon-wide scan covers every protected profile journal.
$schedule = new rXMLRPCCommand('schedule', array('datadir-recover-all',
    rTorrentSettings::getAlignedStart('datadir-recover-all', 60) . '', '60',
    rtDataDirRecoveryCommand(Utility::getPHP(), __DIR__ . '/recover.php')));
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
