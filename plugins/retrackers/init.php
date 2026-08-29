<?php

if(!defined('RETRACKERS_TEST_MODE'))
	require_once( '../plugins/retrackers/retrackers.php');
if(!class_exists('rTorrent', false))
	require_once( dirname(__FILE__).'/../../php/rtorrent.php');
require_once( dirname(__FILE__).'/guard.php');

$insertAction = retrackersBuildInsertAction($rootPath, Utility::getPHP(), User::getUser());

$req = $insertAction === false ? null : new rXMLRPCRequest(
	$theSettings->getOnInsertCommand(array('tadd_trackers1'.User::getUser(), $insertAction)));
if($req !== null && $req->run() && !$req->fault)
{
	$theSettings->registerPlugin($plugin["name"],$pInfo["perms"]);
	$trks = rRetrackers::load();
	$jResult.=$trks->get();
}
else
	$jResult .= "plugin.disable(); noty('retrackers: '+theUILang.pluginCantStart,'error');";
