<?php
require_once( dirname(__FILE__)."/../../php/cache.php" );
require_once(__DIR__."/trackerurl.php");

class rRetrackers
{
	public $hash = "retrackers.dat";
	public $modified = false;
	public $list = array();
	public $todelete = array();
	public $dontAddPrivate = 1;
	public $addToBegin = 0;

	static public function load($create = true)
	{
		$cache = new rCache('', $create);
		$rt = new rRetrackers();
		$cache->get($rt);
		if(!isset($rt->todelete))
			$rt->todelete = array();
		return($rt);
	}
	public function store()
	{
		$cache = new rCache();
		return($cache->set($this));
	}
	public function set($body = null, &$failure = null)
	{
		$failure = null;
		if ($body === null) $body = file_get_contents("php://input");
		if (!is_string($body)) {
			$failure = 'settings-body-unreadable';
			return(false);
		}
		$list = $deletions = $trackers = $flags = array();
		foreach (explode('&', $body) as $var) {
			// This ordered format uses repeated tracker fields and literal '+'.
			$parts = explode('=', $var, 2);
			$key = $parts[0];
			$value = isset($parts[1]) ? rawurldecode($parts[1]) : '';
			if ($key === 'dont_private' || $key === 'add_begin') {
				if ($value !== '0' && $value !== '1') $failure = $key.': invalid-boolean';
				else $flags[$key] = (int)$value;
			} else if ($key === 'todelete') {
				$value = trim($value);
				if ($value === '') $failure = 'todelete['.(count($deletions) + 1).']: empty-deletion-pattern';
				else $deletions[] = $value;
			} else if ($key === 'tracker') {
				$value = RetrackersTrackerURL::normalize($value);
				if ($value !== '') {
					$reason = RetrackersTrackerURL::additionFailure($value);
					if ($reason !== null) $failure = 'tracker['.(count($list) + 1).']['.(count($trackers) + 1).']: '.$reason;
					else $trackers[] = $value;
				} else if (count($trackers)) {
					$list[] = $trackers;
					$trackers = array();
				}
			}
			if ($failure !== null) return(false);
		}
		if (count($trackers)) $list[] = $trackers;
		// Do not publish a partial replacement or refresh lifecycle on refusal.
		$previous = array($this->list, $this->todelete, $this->dontAddPrivate, $this->addToBegin);
		$this->list = $list;
		$this->todelete = $deletions;
		$this->dontAddPrivate = $flags['dont_private'] ?? 0;
		$this->addToBegin = $flags['add_begin'] ?? $this->addToBegin;
		if (!$this->store()) {
			list($this->list, $this->todelete, $this->dontAddPrivate, $this->addToBegin) = $previous;
			$failure = 'settings-store-failed';
			return(false);
		}
		return(true);
	}
	public function get()
	{
		return("theWebUI.retrackers = ".JSON::safeEncode($this).";\n");
	}
}
