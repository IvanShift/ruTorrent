<?php

require_once( "task.php" );

$ret = array();

$input = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $_POST : $_GET;
switch($input['cmd'] ?? null)
{
	case "kill":
	{
		Requests::requirePost();
	        $ret = rTask::kill($input['no']);
		break;
	}
	case "check":
	{
	        $ret = rTask::check($input['no']);
		break;
	}
	case "list":
	{
		set_time_limit(0);
		$ret = rTaskManager::obtain();
		break;
	}
	case "remove":
	{
		Requests::requirePost();
		$list = array();
		if(!isset($HTTP_RAW_POST_DATA))
			$HTTP_RAW_POST_DATA = file_get_contents("php://input");
		$vars = explode('&', $HTTP_RAW_POST_DATA);
		foreach($vars as $var)
		{
			$parts = explode("=",$var);
			if($parts[0]=="no")
			{
				$value = trim(rawurldecode($parts[1]));
				if(strlen($value))
					$list[] = $value;
			}
		}
		$ret = rTaskManager::remove($list);
		break;
	}
}
CachedEcho::send(JSON::safeEncode($ret),"application/json");
