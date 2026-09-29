<?php

require_once( "xmlrpc.php" );
require_once( 'settings.php' );

define('FLAG_CANT_SHUTDOWN',	0x0080);
define('FLAG_CAN_CHANGE_LAUNCH',0x0100);

$theSettings = rTorrentSettings::get();
$jResult = "";

$cmd = isset($_REQUEST["cmd"]) ? $_REQUEST["cmd"] : "done";

$userPermissions = array( "__hash__"=>"plugins.dat" );
$cache = new rCache();
$cache->get($userPermissions);

if(!isset($HTTP_RAW_POST_DATA))
	$HTTP_RAW_POST_DATA = file_get_contents("php://input");
$processedPlugins = array();
$vars = explode('&', $HTTP_RAW_POST_DATA);
foreach($vars as $var)
{
	$parts = explode("=", $var, 2);
	if($parts[0] == "plg" && isset($parts[1]) &&
		!isset($processedPlugins[$parts[1]]))
	{
		$pluginName = $parts[1];
		$processedPlugins[$pluginName] = true;
		$perms = $theSettings->getPluginData($pluginName);
		switch($cmd)
		{
			case "unlaunch":
			case "done":
			{
				$unlaunch = $cmd == "unlaunch" &&
					(is_null($perms) || ($perms & FLAG_CAN_CHANGE_LAUNCH));
				$canShutdown = !is_null($perms) && !($perms & FLAG_CANT_SHUTDOWN);
				// A done.php can veto removal by setting $pluginDone to false.
				$pluginDone = true;
				if($canShutdown)
				{
					$php = "../plugins/".$pluginName."/done.php";
					if(is_file($php) && is_readable($php))
						require_once($php);
				}
				if($pluginDone !== false)
				{
					if($unlaunch)
					{
						$userPermissions[$pluginName] = false;
						$jResult.="thePlugins.get('".$pluginName."').unlaunch();";
					}
					if($canShutdown)
					{
						$theSettings->unregisterPlugin($pluginName);
						$jResult.="thePlugins.get('".$pluginName."').remove();";
					}
				}
				break;
			}
			case "launch":
			{
				if(is_null($perms) || ($perms & FLAG_CAN_CHANGE_LAUNCH))
				{
					$userPermissions[$pluginName] = true;
					$jResult.="thePlugins.get('".$pluginName."').launch();";
				}
				break;
			}
		}
	}
}

if($cmd=="done")
	$theSettings->store();
else
	$cache->set($userPermissions);

CachedEcho::send($jResult,"application/javascript");
