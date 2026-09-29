<?php

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
	private $limit = self::PROBES_PER_REQUEST;
	private static $remaining = self::PROBES_PER_REQUEST;
	private static $probed = array();

	public function __sleep()
	{
		return array('hash', 'next', 'last');
	}

	public function reserve($candidates, $active, $limit = self::PROBES_PER_REQUEST)
	{
		$this->candidates = $candidates;
		$this->active = $active;
		$this->limit = $limit;
		return $this->select();
	}

	// Rechoose after rCache sees a concurrent reservation under its key lock.
	public function merge($newer, $unused = null)
	{
		$this->next = $newer->next;
		$this->last = $newer->last;
		return $this->select();
	}

	public static function resetRequestBudget()
	{
		self::$remaining = self::PROBES_PER_REQUEST;
		self::$probed = array();
	}

	public static function reconcile($history, $urls)
	{
		if(self::$remaining <= 0) return;
		$pending = array();
		foreach($urls as $url)
			if($history->isPending($url) && !isset(self::$probed[$url]))
				$pending[$url] = true;
		if(empty($pending)) return;
		$active = array();
		foreach($history->lst as $url=>$entry)
			if(is_array($entry) && array_key_exists('receipt', $entry))
				$active[$url] = true;
		$cursor = new self();
		$cache = new rCache();
		$cache->get($cursor);
		$cursor->reserve(array_keys($pending), $active, self::$remaining);
		if(!$cache->set($cursor))
		{
			FileUtil::toLog('extsearch: history probe cursor reservation failed; pending checks deferred');
			return;
		}
		self::$remaining -= count($cursor->selected);
		foreach($cursor->selected as $url) self::$probed[$url] = true;
		global $rpcTimeOut, $rpcTransferTimeOut;
		$oldConnect = $rpcTimeOut ?? null;
		$oldReply = $rpcTransferTimeOut ?? null;
		// SCGI idle timeouts, not a wall-clock cap on a streaming daemon.
		$rpcTimeOut = 0.5;
		$rpcTransferTimeOut = 1.0;
		try
		{
			foreach($cursor->selected as $url)
				$history->reconcile($url);
		}
		finally
		{
			$rpcTimeOut = $oldConnect;
			$rpcTransferTimeOut = $oldReply;
		}
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
		if($this->next > PHP_INT_MAX - $this->limit)
		{
			$this->next = 0;
			$this->last = array();
		}
		$rank = array();
		foreach($this->candidates as $url)
			$rank[$url] = $this->last[$url] ?? 0;
		asort($rank, SORT_NUMERIC);
		$this->selected = array_slice(array_keys($rank), 0, $this->limit);
		foreach($this->selected as $url)
			$this->last[$url] = ++$this->next;
		return true;
	}
}
