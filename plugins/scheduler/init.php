<?php

require_once( dirname(__FILE__)."/scheduler.php" );

$schd = rScheduler::load();
$schd->apply();

$req = new rXMLRPCRequest( $theSettings->getAlignedScheduleCommand('scheduler',$updateInterval*60,
	getCmd('execute').'={sh,-c,'.Utility::escapeShellArg(Utility::getPHP()).' '.Utility::escapeShellArg($rootPath.'/plugins/scheduler/update.php').' '.Utility::escapeShellArg(User::getUser()).' & exit 0}' ) );
if($req->run() && !$req->fault)
{
	$theSettings->registerPlugin($plugin["name"],$pInfo["perms"]);
	$jResult.=$schd->get();
}
else
	$jResult.="plugin.disable(); noty('scheduler: '+theUILang.pluginCantStart,'error');";
