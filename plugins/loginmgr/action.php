<?php
require_once( "accounts.php" );

$em = accountManager::load();
if($em===false)
{
	$em = new accountManager();
	$em->obtain("./accounts");
}

if(isset($_REQUEST['mode']))
{
	$cmd = $_REQUEST['mode'];
	switch($cmd)
	{
		case "set":
		{
			Requests::requirePost();
			if(!$em->set())
			{
				FileUtil::toLog('loginmgr: account-save-failed');
				http_response_code(500);
				exit('Account settings could not be saved.');
			}
			CachedEcho::send($em->get(),"application/javascript");
			break;
		}
		case "info":
		{
			CachedEcho::send(JSON::safeEncode($em->getInfo()),"application/json");
			break;
		}
	}
}
