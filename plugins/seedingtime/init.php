<?php

// execute.capture includes date's trailing newline; printf keeps the stored epoch numeric.
$seedingtimeTimestamp = '"$'.getCmd('execute_capture').'={sh,-c,printf %s $(date +%s)}"';
$req = new rXMLRPCRequest( array(
	$theSettings->getOnFinishedCommand(array("seedingtime".User::getUser(),
		getCmd('d.set_custom').'=seedingtime,'.$seedingtimeTimestamp)),
	$theSettings->getOnInsertCommand(array("addtime".User::getUser(),
		getCmd('d.set_custom').'=addtime,'.$seedingtimeTimestamp)),

	$theSettings->getOnHashdoneCommand(array("seedingtimecheck".User::getUser(),
		getCmd('branch=').'$'.getCmd('not=').'$'.getCmd('d.get_complete=').',,'.
		getCmd('d.get_custom').'=seedingtime,,"'.getCmd('d.set_custom').'=seedingtime,$'.getCmd('d.get_custom').'=addtime'.'"')),
	));
if($req->success())
        $theSettings->registerPlugin($plugin["name"],$pInfo["perms"]);
else
        $jResult .= "plugin.disable(); noty('seedingtime: '+theUILang.pluginCantStart,'error');";
