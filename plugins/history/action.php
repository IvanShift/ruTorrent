<?php
require_once( 'history.php' );

$cmd = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['cmd'] ?? null) : ($_GET['cmd'] ?? null);
if($cmd !== null)
{
	switch($cmd)
	{
		case "set":
		{
			Requests::requirePost();
			$up = rHistory::load();
			$up->set();
			CachedEcho::send($up->get(),"application/javascript");
			break;
		}
		case "get":
		{
			$up = rHistoryData::load();
			CachedEcho::send(JSON::safeEncode($up->get($_REQUEST['mark'])),"application/json");
  	                break;
		}
		case "delete":
		{
			Requests::requirePost();
			$up = rHistoryData::load();
			$hashes = array();
			if(!isset($HTTP_RAW_POST_DATA))
				$HTTP_RAW_POST_DATA = file_get_contents("php://input");
			$vars = explode('&', $HTTP_RAW_POST_DATA);
			foreach($vars as $var)
			{
				$parts = explode("=",$var);
				$hashes[] = $parts[1];
  	                	}
			$up->delete( $hashes );
			CachedEcho::send(JSON::safeEncode($up->get(0)),"application/json");
  	                break;
		}
	}
}
