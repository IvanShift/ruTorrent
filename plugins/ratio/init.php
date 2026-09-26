<?php
require_once( dirname(__FILE__)."/ratio.php");

$rat = rRatio::load();
if(!$rat->obtain())
	$jResult.="plugin.disable(); noty('ratio: '+theUILang.pluginCantStart,'error');";
else
	$theSettings->registerPlugin($plugin["name"],$pInfo["perms"]);
// Jobs from a previous Ratio run remain durable even if this init failed.
// When erasedata is disabled, its earlier init did not re-arm their volatile
// schedule. The shared helper reports its own classified refusal.
if(!$theSettings->isPluginRegistered('erasedata'))
{
	$helper = dirname(__FILE__).'/../erasedata/removewithdata.php';
	if(!is_file($helper))
		FileUtil::toLog('ratio: erasedata-rearm-helper-unavailable');
	else
	{
		require_once($helper);
		if(!function_exists('erasedataRearmDrainSchedule'))
			FileUtil::toLog('ratio: erasedata-rearm-helper-unavailable');
		else
			erasedataRearmDrainSchedule();
	}
}
$jResult.=$rat->get();
