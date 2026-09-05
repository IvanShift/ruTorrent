<?php

define('RETRACKERS_IMPORT_ONLY', true);
require_once( dirname(__FILE__)."/update.php");
require_once( dirname(__FILE__)."/retrackers.php");

$script = $rootPath.'/plugins/retrackers/run.sh';
$php = Utility::getPHP();
$user = User::getUser();

$failure = null;
$ok = retrackersRunLifecycleInit($user, $script, $php, $jResult, $failure);
if($ok)
{
	$theSettings->registerPlugin($plugin["name"],$pInfo["perms"]);
	$trks = rRetrackers::load();
	$jResult.=$trks->get();
}
else
	$jResult .= "plugin.disable(); noty('retrackers: '+theUILang.pluginCantStart,'error');";
