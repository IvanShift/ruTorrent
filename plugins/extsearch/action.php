<?php
require_once( dirname(__FILE__)."/../../php/util.php" );
require_once( dirname(__FILE__)."/../../php/rtorrent.php" );
require_once( "engines.php" );

if(isset($_REQUEST['mode']) && in_array($_REQUEST['mode'], array('set', 'loadtorrents', 'history'), true))
	Requests::requirePost();
set_time_limit(0);
if(isset($_REQUEST['mode']) && $_REQUEST['mode']==='history')
{
	if(!isset($HTTP_RAW_POST_DATA))
		$HTTP_RAW_POST_DATA = file_get_contents('php://input');
	$history = engineManager::loadHistory();
	$answers = array();
	$maxUrls = max(1, (int)$searchHistoryMaxCount);
	foreach(Utility::legacyOrderedFormPairs($HTTP_RAW_POST_DATA) as $parts)
	{
		if(count($parts)!==2 || $parts[0]!=='url') continue;
		$url = $parts[1];
		if(array_key_exists($url, $answers)) continue;
		if(count($answers) >= $maxUrls)
		{
			FileUtil::toLog('extsearch: history query truncated: too many URLs');
			break;
		}
		if($history->isPending($url)) $history->reconcile($url);
		$answers[$url] = $history->isPending($url) ? null : $history->getHash($url);
	}
	engineManager::saveHistory($history);
	CachedEcho::send(JSON::safeEncode($answers), 'application/json');
}
$em = engineManager::load();
if($em===false)
{
	$em = new engineManager();
	$em->obtain("./engines");
}

if(isset($_REQUEST['mode']))
{
	$cmd = $_REQUEST['mode'];
	switch($cmd)
	{
		case "set":
		{
			$em->set();
			CachedEcho::send($em->get(),"application/javascript");
			break;
		}
		case "get":
		{
			CachedEcho::send(JSON::safeEncode($em->action( $_REQUEST['eng'], $_REQUEST['what'], $_REQUEST['cat'] )),"application/json");
			break;
		}
		case "loadtorrents":
		{
			if(!isset($HTTP_RAW_POST_DATA))
				$HTTP_RAW_POST_DATA = file_get_contents("php://input");
			if(isset($HTTP_RAW_POST_DATA))
			{
				$vars = explode('&', $HTTP_RAW_POST_DATA);
				$lbl = null;
				$dir = null;
				$isStart = true;
				$isAddPath = true;
				$fast = false;
				$urls = array();
				$engs = array();
				$ndx = array();
				$teg = '';
				foreach($vars as $var)
				{
					$parts = explode("=",$var);
					if($parts[0]=="torrents_start_stopped")
						$isStart = false;
					else
					if($parts[0]=="not_add_path")
						$isAddPath = false;
					else
					if($parts[0]=="dir_edit")
						$dir = rawurldecode($parts[1]);
					else
					if($parts[0]=="label")
						$lbl = rawurldecode($parts[1]);
					else
					if($parts[0]=="fast_resume")
						$fast = $parts[1];
					else
					if($parts[0]=="teg")
						$teg = $parts[1];
					else
					if($parts[0]=="url")
						$urls[] = rawurldecode($parts[1]);
					else
					if($parts[0]=="eng")
						$engs[] = $parts[1];
					else
					if($parts[0]=="ndx")
						$ndx[] = $parts[1];
				}
				$status = $em->getTorrents( $engs, $urls, $isStart, $isAddPath, $dir, $lbl, $fast );
				$ret = array( "teg"=>$teg, "data"=>array() );
				for($i = 0; $i< count($status); $i++)
					$ret["data"][] = array( "hash"=>$status[$i], "ndx"=>$ndx[$i] );
				CachedEcho::send(JSON::safeEncode($ret),"application/json");
			}
			break;
		}
	}
}
