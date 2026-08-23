<?php

require_once( "../../php/util.php" );
require_once( __DIR__ . "/launcher.php" );

if(!isset($HTTP_RAW_POST_DATA))
	$HTTP_RAW_POST_DATA = file_get_contents("php://input");

if(isset($HTTP_RAW_POST_DATA))
{
	$error = null;
	$ret = RuTrackerBatchRequest::parseHashes($HTTP_RAW_POST_DATA, $error);
	if($error !== null)
	{
		FileUtil::toLog('rutracker_check: manual batch request rejected: ' . $error);
	}
	elseif(count($ret))
	{
		RuTrackerBatchDispatch::dispatch(
			$ret,
			FileUtil::getTempDirectory(),
			Utility::getPHP(),
			dirname(__FILE__) . "/batch_check.php",
			User::getUser(),
			null,
			array('FileUtil', 'toLog')
		);
	}
}

CachedEcho::send('{}',"application/json");
