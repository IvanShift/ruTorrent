<?php

require_once( dirname(__FILE__)."/../../php/xmlrpc.php" );
require_once(dirname(__FILE__).'/../erasedata/removewithdata.php');
require_once(__DIR__.'/../../php/rtorrent.php');
require_once(__DIR__.'/../../php/commandargs.php');
require_once(__DIR__.'/launcher.php');
eval( FileUtil::getPluginConf( $plugin["name"] ) );
$garbageCheckInterval = (function () {
	$garbageCheckInterval = 15;
	eval(FileUtil::getPluginConf('erasedata'));
	return($garbageCheckInterval);
})();

$session = rTorrentSettings::get()->session;
if( !strlen($session) || !@file_exists(FileUtil::addslash($session).'.') )
{
	// session.path in the daemon's own bytes, inside a script: an apostrophe
	// would end a quoted literal and a backslash start an escape -- an
	// invalid one stops the whole getplugins script -- so it goes in as a
	// JSON literal.
	$jResult .= "plugin.disable(); noty(".JSON::jsValue($plugin["name"].": ")
		."+theUILang.webBadSessionError+".JSON::jsValue(" (".$session.").").",'error');";
}
else
{
	$commands = array();
	$checkerScheduleReady = true;
	$queueReady = erasedataInitializationQueueReady();
	if($queueReady)
		$commands[] = erasedataCollectorScheduleCommand($theSettings, $garbageCheckInterval);
	if($updateInterval)
	{
		// getScheduleCommand, not getAbsScheduleCommand: this file runs on every
		// full load of the web interface, and rTorrent's scheduler replaces an
		// entry that reuses a key (CommandScheduler::insert deletes the old item
		// and re-enables the new one at now+start). The absolute form starts its
		// countdown at registration time, so each reload pushed the next check a
		// whole hour away and a user who kept refreshing never got one at all.
		// This form uses a stable interval slot derived from the scheduler
		// key instead of restarting a countdown on each reload. Same choice
		// as plugins/trafic; the interval is in minutes, not seconds.
		$capability = rpcMethodCapability(array('execute.raw.bg'));
		$checkerCommand = RuTrackerDetachedPhp::scheduleCommand(Utility::getPHP(),
			__DIR__.'/update.php', array(User::getUser()), $capability);
		$checkerScheduleReady = $checkerCommand !== false;
		if($checkerScheduleReady)
		{
			$checkerStart = null;
			$commands[] = $theSettings->getScheduleCommand('rutracker_check',$updateInterval,
				$checkerCommand,$checkerStart,null,true);
		}
		else
			FileUtil::toLog('rutracker_check: checker schedule refused reason='
				. ($capability === 'unconfirmed' ? 'rpc-unknown' : 'unrepresentable-argv'));
	}
	else
	{
		require( 'done.php' );
	}
	$req = count($commands) ? new rXMLRPCRequest($commands) : null;
	// The cleanup drain is independent of the ordinary collector and survives
	// a daemon restart through its durable state, even with erasedata disabled.
	if($queueReady)
		erasedataRearmDrainSchedule();
	$requestSucceeded = $req === null || $req->success();
	if($checkerScheduleReady && $requestSucceeded)
		$theSettings->registerPlugin($plugin["name"],$pInfo["perms"]);
	else
		$jResult .= "plugin.disable(); noty('rutracker_check: '+theUILang.pluginCantStart, 'error');";
}
