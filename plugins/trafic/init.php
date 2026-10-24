<?php
eval(FileUtil::getPluginConf($plugin["name"]));
require_once( dirname(__FILE__)."/ratios.php" );

$st = FileUtil::getSettingsPath();
FileUtil::makeDirectory( array( $st.'/trafic', $st.'/trafic/trackers', $st.'/trafic/torrents') );
$req = new rXMLRPCRequest( $theSettings->getScheduleCommand("trafic",$updateInterval,
	getCmd('execute').'={sh,-c,'.Utility::escapeShellArg(Utility::getPHP()).' '.Utility::escapeShellArg($rootPath.'/plugins/trafic/update.php').' '.Utility::escapeShellArg(User::getUser()).' & exit 0}' ) );
if($req->run() && !$req->fault)
       	$theSettings->registerPlugin($plugin["name"],$pInfo["perms"]);
else
       	$jResult .= "plugin.disable(); noty('trafic: '+theUILang.pluginCantStart,'error');";
$jResult .= "plugin.collectStatForTorrents = ".($collectStatForTorrents ? "true;" : "false;");
$jResult .= "plugin.updateInterval = ".$updateInterval.";";
$jResult .= "plugin.disableClearButton = ".($disableClearButton ? "true" : "false").";";
