<?php
require_once( dirname(__FILE__)."/../../php/util.php" );
require_once( dirname(__FILE__)."/../../php/rtorrent.php" );
require_once( "engines.php" );

class ExtsearchHistoryProbeCursor
{
	const PROBES_PER_REQUEST = 4;
	const CACHE_KEY = 'extsearch_history_probe_cursor.dat';
	public $hash = self::CACHE_KEY;
	public $next = 0;
	public $last = array();
	public $selected = array();
	private $candidates = array();
	private $active = array();

	public function __sleep()
	{
		return array('hash', 'next', 'last');
	}

	public function reserve($candidates, $active)
	{
		$this->candidates = $candidates;
		$this->active = $active;
		return $this->select();
	}

	// Rechoose after rCache sees a concurrent reservation under its key lock.
	public function merge($newer, $unused = null)
	{
		$this->next = $newer->next;
		$this->last = $newer->last;
		return $this->select();
	}

	private function select()
	{
		$valid = $this->hash === self::CACHE_KEY && is_int($this->next)
			&& $this->next >= 0 && is_array($this->last);
		if($valid)
		{
			$this->last = array_intersect_key($this->last, $this->active);
			foreach($this->last as $sequence)
				if(!is_int($sequence) || $sequence < 1 || $sequence > $this->next)
					$valid = false;
		}
		if(!$valid)
		{
			FileUtil::toLog('extsearch: history probe cursor reset: key='
				.self::CACHE_KEY.'; invalid scheduling state; pending checks resumed');
			$this->hash = self::CACHE_KEY;
			$this->next = 0;
			$this->last = array();
		}
		if($this->next > PHP_INT_MAX - self::PROBES_PER_REQUEST)
		{
			$this->next = 0;
			$this->last = array();
		}
		$rank = array();
		foreach($this->candidates as $url)
			$rank[$url] = $this->last[$url] ?? 0;
		asort($rank, SORT_NUMERIC);
		$this->selected = array_slice(array_keys($rank), 0, self::PROBES_PER_REQUEST);
		foreach($this->selected as $url)
			$this->last[$url] = ++$this->next;
		return true;
	}
}

if(isset($_REQUEST['mode']) && in_array($_REQUEST['mode'], array('set', 'loadtorrents', 'history'), true))
	Requests::requirePost();
set_time_limit(0);
if(isset($_REQUEST['mode']) && $_REQUEST['mode']==='history')
{
	if(!isset($HTTP_RAW_POST_DATA))
		$HTTP_RAW_POST_DATA = file_get_contents('php://input');
	$history = engineManager::loadHistory();
	$answers = array();
	$pending = array();
	$maxUrls = max(1, (int)$searchHistoryMaxCount, count($history->lst));
	$truncated = false;
	foreach(Utility::legacyOrderedFormPairs($HTTP_RAW_POST_DATA) as $parts)
	{
		if(count($parts)!==2 || $parts[0]!=='url') continue;
		$url = $parts[1];
		if(array_key_exists($url, $answers)) continue;
		$isPending = $history->isPending($url);
		if(count($answers) >= $maxUrls && !$isPending)
		{
			if(!$truncated) FileUtil::toLog('extsearch: history query truncated: too many URLs');
			$truncated = true;
			continue;
		}
		$answers[$url] = $isPending ? null : $history->getHash($url);
		if($isPending) $pending[] = $url;
	}
	if(!empty($pending))
	{
		// Bound ordinary stalls; SCGI read timeouts reset after each received chunk.
		$rpcTimeOut = 0.5;
		$rpcTransferTimeOut = 1.0;
		$cursor = new ExtsearchHistoryProbeCursor();
		$cache = new rCache();
		$cache->get($cursor);
		$active = array();
		foreach($history->lst as $url=>$entry)
			if(is_array($entry) && array_key_exists('receipt', $entry))
				$active[$url] = true;
		$cursor->reserve($pending, $active);
		if(!$cache->set($cursor))
			FileUtil::toLog('extsearch: history probe cursor reservation failed; pending checks deferred');
		else
			foreach($cursor->selected as $url)
			{
				$history->reconcile($url);
				$answers[$url] = $history->isPending($url) ? null : $history->getHash($url);
			}
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
