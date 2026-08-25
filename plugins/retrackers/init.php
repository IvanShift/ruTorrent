<?php

if(!defined('RETRACKERS_TEST_MODE'))
	require_once( '../plugins/retrackers/retrackers.php');
require_once( dirname(__FILE__).'/guard.php');

$saveRunState = getCmd('d.set_custom4').'=$'.getCmd('cat').'=$'.getCmd('d.get_state=');
$processTorrent = getCmd('branch').'=$'.getCmd('not').'=$'.getCmd('d.get_custom3').'=,"'.getCmd('cat').'=$'.getCmd('d.stop').'=,\"$'.
	getCmd('execute').'={sh,'.$rootPath.'/plugins/retrackers/run.sh'.','.Utility::getPHP().',$'.getCmd('d.get_hash').'=,'.User::getUser().',$'.getCmd('d.get_custom4').'=}\"" ; '.getCmd('d.set_custom3=');

$req = new rXMLRPCRequest( array(
	$theSettings->getOnInsertCommand(array('tadd_trackers1'.User::getUser(), retrackersGuardInsertAction($saveRunState))),
	$theSettings->getOnInsertCommand(array('tadd_trackers2'.User::getUser(),
		retrackersGuardInsertAction($processTorrent)))));
if($req->run() && !$req->fault)
{
	$theSettings->registerPlugin($plugin["name"],$pInfo["perms"]);
	$trks = rRetrackers::load();
	$jResult.=$trks->get();
}
else
	$jResult .= "plugin.disable(); noty('retrackers: '+theUILang.pluginCantStart,'error');";
