<?php

require_once( dirname(__FILE__)."/../../php/xmlrpc.php" );
require_once(dirname(__FILE__).'/removewithdata.php');
eval(FileUtil::getPluginConf($plugin["name"]));

$listPath = FileUtil::getSettingsPath()."/erasedata";
@FileUtil::makeDirectory($listPath);
// The ordinary collector handles published manifests, including those left by
// older releases. The generation drain has its own schedule and startup rearm.
$req = new rXMLRPCRequest( array(
	erasedataCollectorScheduleCommand($theSettings, $garbageCheckInterval)
	) );
if($req->success())
{
	// Startup recovery for the SEPARATE per-user drain schedule.
	//
	// rTorrent's schedule table is volatile: a daemon restart forgets every
	// entry, including the erasedata-drain<User> key a producer armed before a
	// removal it never finished. The obligation itself is durable -- markers,
	// staging and the drain journal all survive -- so without this the queue
	// would keep a complete record of work with nothing left to fire the worker
	// that serves it.
	//
	// This is the one moment that loss is plausible, and it is the only place
	// in the shipped plugin that re-arms. It re-arms only when the same
	// conservative scan the worker and retirement use proves something is still
	// owed, and it fails closed on a durable state it cannot parse rather than
	// trusting a *.pending name it cannot bind to a generation.
	erasedataRearmDrainSchedule();
	$theSettings->registerPlugin($plugin["name"],$pInfo["perms"]);
	$jResult.="plugin.enableForceDeletion = ".($enableForceDeletion ? 1 : 0).";";
	$jResult.="plugin.replaceRemoveTorrent = ".($replaceRemoveTorrent ? 1 : 0).";";
}
else
	$jResult.="plugin.disable(); noty('erasedata: '+theUILang.pluginCantStart,'error');";
