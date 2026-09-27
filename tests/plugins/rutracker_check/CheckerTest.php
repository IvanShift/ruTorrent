<?php

/**
 * Focused regression tests for plugins/rutracker_check/check.php.
 *
 * The runner, the assertions and the XMLRPC test double live in TestLib.php;
 * this file keeps only the checker-specific fakes: the real ruTrackerChecker
 * (evaled out of check.php), a fixture-based Torrent and a recording rTorrent.
 *
 * Two rollback tests deliberately exhaust the waitForLoad poll budget; the
 * class uses a declared 1 ms test delay, while production defaults to 50 ms,
 * so they cost milliseconds here where production would spend two seconds.
 */

require __DIR__ . '/TestLib.php';
// The metadata pump takes its claim through the flock-backed state store --
// the only compare-and-swap this plugin has -- so the real class is loaded and
// pointed at a temp directory per test (resetFakes()).
require_once(testFindRepoRoot() . '/plugins/rutracker_check/state.php');

class FileUtil
{
	public static $log = array();

	// state.php creates its directory through this; the real one wraps mkdir
	// in umask(0) so the requested mode takes effect for the second OS user
	// of a split scheduler/web-server install (see StateTest, which loads the
	// genuine class to test exactly that). Here only the directory matters.
	public static function makeDirectory($dir, $mode = 0777)
	{
		$saved = umask(0);
		if(!is_dir($dir)) @mkdir($dir, $mode, true);
		umask($saved);
		return is_dir($dir);
	}

	public static function addslash($path)
	{
		return rtrim($path, '/') . '/';
	}

	public static function toLog($message)
	{
		self::$log[] = $message;
	}
}

class Torrent
{
	public static $fixtures = array();
	// Every construction is counted. "The bytes are decoded once" is a claim
	// about what runs, and only a counter can settle it; reading the source
	// cannot. A test that hands createTorrent() an already parsed object and
	// finds this unchanged has proved there is no second decode.
	public static $constructions = 0;
	public $info = array();
	// The real Torrent records a filename when it was constructed from a path,
	// and rTorrent::sendTorrent() reads that as "a file this plugin owns".
	protected $filename = null;
	private $hash = '';
	private $hasErrors = false;
	private $announceUrl = '';
	private $announceList = array();
	private $commentUrl = '';

	public function __construct($source)
	{
		self::$constructions++;
		$fixture = is_array($source) ? $source : (self::$fixtures[$source] ?? array('errors' => true));
		$this->hash = $fixture['hash'] ?? '';
		$this->info = $fixture['info'] ?? array();
		$this->hasErrors = !empty($fixture['errors']);
		$this->announceUrl = $fixture['announce'] ?? '';
		$this->announceList = $fixture['announce_list'] ?? array();
		$this->commentUrl = $fixture['comment'] ?? '';
	}

	public function errors()
	{
		return $this->hasErrors;
	}

	public function getFileName()
	{
		return $this->filename;
	}

	// Mirrors the real Torrent::name(), which reads $info['name'] and answers
	// null when the key is absent. parseMetainfo() refuses an empty name
	// because rTorrent 0.16.20 aborts on one, so the double has to be able to
	// carry a name for the accepted cases to stay accepted.
	public function name()
	{
		return isset($this->info['name']) ? $this->info['name'] : null;
	}

	// Stands in for constructing the real Torrent from a path.
	public function backedByFile($filename)
	{
		$this->filename = $filename;
		return $this;
	}

	public function hash_info()
	{
		return $this->hash;
	}

	public function announce()
	{
		return $this->announceUrl;
	}

	public function announce_list()
	{
		return $this->announceList;
	}

	public function comment()
	{
		return $this->commentUrl;
	}
}

// The replacement boundary takes an already parsed Torrent -- exactly the
// object a production caller receives from parseMetainfo(). Tests name a
// fixture and resolve it here, at the call site, so the decode is visible.
function checkerParsed($name)
{
	return(new Torrent($name));
}

require_once(testFindRepoRoot() . '/php/xmlrpc_path.php');

if(!defined('ERASEDATA_CLEANUP_NONE')) define('ERASEDATA_CLEANUP_NONE', 'none');
if(!defined('ERASEDATA_CLEANUP_READY')) define('ERASEDATA_CLEANUP_READY', 'ready');
if(!defined('ERASEDATA_CLEANUP_RETRY')) define('ERASEDATA_CLEANUP_RETRY', 'retry');

// The checker owns transaction ordering, while erasedata owns durable queue
// mutation and collection. This fake keeps that boundary explicit and records
// the exact producer calls without copying queue or deletion behavior here.
class ErasedataFake
{
	public static $calls = array();
	public static $prepareResult = true;
	public static $armResult = true;
	public static $publishResult = true;
	public static $cancelResult = true;
	public static $recoverResult = ERASEDATA_CLEANUP_NONE;
	public static $generationCancelResult = ERASEDATA_CLEANUP_NONE;
	public static $kickResult = true;
	public static $claimPaths = array();
	public static $scanUnknown = false;

	public static function reset()
	{
		self::$calls = array();
		self::$prepareResult = true;
		self::$armResult = true;
		self::$publishResult = true;
		self::$cancelResult = true;
		self::$recoverResult = ERASEDATA_CLEANUP_NONE;
		self::$generationCancelResult = ERASEDATA_CLEANUP_NONE;
		self::$kickResult = true;
		self::$claimPaths = array();
		self::$scanUnknown = false;
	}

	public static function record($name, $arguments)
	{
		self::$calls[] = array(
			'name' => $name,
			'arguments' => $arguments,
			'request_count' => count(rXMLRPCRequest::$requests),
		);
	}
}

function erasedataPathContains($parent, $path)
{
	$parent = $parent === '/' ? '/' : rtrim((string) $parent, '/');
	$path = $path === '/' ? '/' : rtrim((string) $path, '/');
	return($parent !== '' && $path !== ''
		&& ($parent === $path || ($parent !== '/' && strpos($path, $parent . '/') === 0)));
}

function erasedataPathsOverlap($left, $right)
{
	if(erasedataPathContains($left, $right) || erasedataPathContains($right, $left))
		return(true);
	$leftIdentity = XMLRPCPathResolver::filesystemIdentity($left);
	$rightIdentity = XMLRPCPathResolver::filesystemIdentity($right);
	if($leftIdentity === false || $rightIdentity === false)
		return(true);
	if(!empty($leftIdentity['exists']) && !empty($rightIdentity['exists'])
		&& $leftIdentity['stat']['dev'] === $rightIdentity['stat']['dev']
		&& $leftIdentity['stat']['ino'] === $rightIdentity['stat']['ino'])
		return(true);
	return(erasedataPathContains($leftIdentity['path'], $rightIdentity['path'])
		|| erasedataPathContains($rightIdentity['path'], $leftIdentity['path']));
}

function erasedataCleanupOtherOwnerSnapshot($oldHash, $newHash, $marker, $record)
{
	ErasedataFake::record(__FUNCTION__, func_get_args());
	return(ErasedataFake::$scanUnknown ? false
		: array('paths' => ErasedataFake::$claimPaths, 'inodes' => array()));
}

function erasedataCleanupOtherOwnerState($snapshot, $path, $stat)
{
	return(isset($snapshot['paths']["p\0".$path]) ? 'claimed' : 'unclaimed');
}

function erasedataPrepareObsoleteCleanup($oldHash, $newHash, $marker, $record, $base, array $entries)
{
	ErasedataFake::record(__FUNCTION__, func_get_args());
	if(ErasedataFake::$prepareResult !== true)
		return(ErasedataFake::$prepareResult);
	return(array(
		'old_hash' => strtoupper($oldHash),
		'new_hash' => strtoupper($newHash),
		'marker' => $marker,
		'replacement_record' => $record,
		'base' => $base,
		'entries' => $entries,
	));
}

function erasedataArmObsoleteCleanup($job)
{
	ErasedataFake::record(__FUNCTION__, func_get_args());
	return(ErasedataFake::$armResult);
}

function erasedataPublishObsoleteCleanup(&$job)
{
	ErasedataFake::record(__FUNCTION__, array($job));
	return(ErasedataFake::$publishResult);
}

function erasedataCancelObsoleteCleanup(&$job)
{
	ErasedataFake::record(__FUNCTION__, array($job));
	return(ErasedataFake::$cancelResult);
}

function erasedataRecoverObsoleteCleanup($oldHash, $newHash, $marker, $record)
{
	ErasedataFake::record(__FUNCTION__, func_get_args());
	return(ErasedataFake::$recoverResult);
}

function erasedataCancelObsoleteCleanupGeneration($oldHash, $newHash, $marker, $record)
{
	ErasedataFake::record(__FUNCTION__, func_get_args());
	return(ErasedataFake::$generationCancelResult);
}

function erasedataKickCollector($oldHash)
{
	ErasedataFake::record(__FUNCTION__, func_get_args());
	return(ErasedataFake::$kickResult);
}

class rTorrent
{
	public static $source = false;
	public static $sourceQueue = array();
	public static $sourceReads = 0;
	public static $sendResult = false;
	public static $lastSend = null;
	public static $sends = array();

	public static function getSource($hash)
	{
		self::$sourceReads++;
		if(count(self::$sourceQueue)) return array_shift(self::$sourceQueue);
		if(self::$source !== false) return self::$source;
		$fname = rTorrentSettings::get()->session . $hash . '.torrent';
		if(!is_readable($fname)) return false;
		$torrent = new Torrent($fname);
		return $torrent->errors() ? false : $torrent;
	}

	public static function sendTorrent($torrent, $isStart, $isAddPath, $directory, $label, $saveTorrent, $isFast, $isNew = true, $addition = null)
	{
		self::$lastSend = compact('torrent', 'isStart', 'isAddPath', 'directory', 'label', 'saveTorrent', 'isFast', 'isNew', 'addition');
		self::$sends[] = self::$lastSend;
		return self::$sendResult;
	}
}

// Fake collaborator for run()'s STE_META_PENDING short-circuit. pump()'s own
// ordered-harvest logic is exercised in full by MetaFetchTest.php; this
// double only has to prove run() actually hands off to it (instead of
// falling into the normal INPROGRESS transition) and persists whatever it
// returns. It still issues one real XMLRPC read, so a test can tell "pump
// ran" from "pump was a no-op stub".
class RuTrackerMetaFetch
{
	public static $calls = array();
	public static $result = null;

	public static function pump($hash, $now)
	{
		self::$calls[] = array('hash' => $hash, 'now' => $now);
		$probe = new rXMLRPCRequest(new rXMLRPCCommand(getCmd('d.get_custom'), array($hash, 'chk-meta-new')));
		$probe->important = false;
		$probe->success();
		return self::$result;
	}
}

// The rollback cases exhaust waitForLoad's poll count, not the courtesy
// between polls. Declare a short test delay before loading the shipped class.
if (!defined('RUTRACKER_CHECK_LOAD_WAIT_DELAY_US'))
	define('RUTRACKER_CHECK_LOAD_WAIT_DELAY_US', 1000);
$checkerDefinition = loadClassDefinition(
	__DIR__ . '/../../../plugins/rutracker_check/check.php',
	'ruTrackerChecker'
);
// Keep elapsed-lease tests independent of the host's uptime. This affects
// only the evaled test class; the shipped checker retains its 900-second lease.
$checkerDefinition = preg_replace('/(const MAX_LOCK_TIME\s*=\s*)900;/', '${1}2;',
	$checkerDefinition, 1, $leaseReplacementCount);
strictAssertSame(1, $leaseReplacementCount, 'the test lease constant was shortened exactly once');
eval($checkerDefinition);
unset($checkerDefinition);

function checkerObservedMono($ageSeconds)
{
	$age = $ageSeconds * 1000000000;
	while (($now = hrtime(true)) <= $age) usleep(10000);
	return $now - $age;
}

class CheckerProbe extends ruTrackerChecker
{
	public static function setStateForTest($hash, $state)
	{
		return parent::setState($hash, $state);
	}


	public static function getStateForTest($hash, &$state, &$time, &$label, &$localId = null)
	{
		return parent::getState($hash, $state, $time, $label, $localId);
	}
}

// Minimal double for makeClient(): the status, and the body the download
// guard has to classify.
class Snoopy
{
	const RESPONSE_BODY_FAILED = -101;
	public static $nextStatus = 200;
	public static $nextResults = '';
	public static $nextError = '';

	public $status = 0;
	public $results = '';
	public $read_timeout = 0;
	public $_fp_timeout = 0;
	public $agent = '';
	// Mirrors the real Snoopy (php/Snoopy.class.inc declares var $error): it
	// is the only field that separates the several conditions which all
	// arrive as status 0, and makeClient() now logs it.
	public $error = '';

	public function fetchComplex($url, $method = 'GET', $contentType = '', $body = '')
	{
		$this->status = self::$nextStatus;
		$this->results = self::$nextResults;
		$this->error = self::$nextError;
		return true;
	}
}

class CheckerStringableMarker
{
	private $value;

	public function __construct($value)
	{
		$this->value = $value;
	}

	public function __toString()
	{
		return($this->value);
	}
}

class CheckerTest
{
	// The last two carry the predecessor's topic and forum across the
	// replacement: the successor is the same topic, so they stay true of it,
	// and reading them here costs nothing the request was not already making.
	const SNAPSHOT_KEY = 'd.get_directory_base|d.get_custom1|d.get_throttle_name|d.get_connection_seed|d.get_custom|d.get_custom';
	const SNAPSHOT_KEY_COMMANDS = array('d.get_directory_base', 'd.get_custom1', 'd.get_throttle_name', 'd.get_connection_seed', 'd.get_custom', 'd.get_custom');
	const STOP_KEY = 'branch';
	const STOP_KEY_COMMANDS = array('branch');
	const GETSTATE_KEY = 'd.get_custom|d.get_custom|d.get_custom1|d.get_local_id|d.get_custom';
	const GETSTATE_KEY_COMMANDS = array('d.get_custom', 'd.get_custom', 'd.get_custom1', 'd.get_local_id', 'd.get_custom');
	const LOCAL_ID = '1111111111111111111111111111111111111111';
	const PREFLIGHT_KEY = 'd.get_custom|d.get_state|d.is_open|d.get_custom';
	const PREFLIGHT_KEY_COMMANDS = array('d.get_custom', 'd.get_state', 'd.is_open', 'd.get_custom');
	const PLUGIN_MARKER = '0123456789abcdef0123456789abcdef';
	const OLD_HASH = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
	const NEW_HASH = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';
	const COMMIT_SNAPSHOT_KEY = 'd.get_directory_base|d.get_custom1|d.get_throttle_name|d.get_connection_seed|d.get_custom|d.get_custom';
	const COMMIT_SNAPSHOT_KEY_COMMANDS = array('d.get_directory_base', 'd.get_custom1', 'd.get_throttle_name',
		'd.get_connection_seed', 'd.get_custom', 'd.get_custom');

	private static function queueStateRead($ok, $fault, $values = array())
	{
		// Existing fixtures describe three custom fields; complete the live
		// identity and message slots without changing each independent case.
		if($values instanceof Closure)
		{
			$answer = $values;
			$values = function($commands) use ($answer) {
				$row = $answer($commands);
				if(count($row) === 3) $row[] = self::LOCAL_ID;
				if(count($row) === 4) $row[] = '';
				return $row;
			};
		}
		else {
			if(count($values) === 3) $values[] = self::LOCAL_ID;
			if(count($values) === 4) $values[] = '';
		}
		rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, $ok, $fault, $values);
	}

	private static function branchGuardsEmptyMeta($condition, $state)
	{
		return strpos($condition, 'chk-state,cat=' . $state) !== false
			&& strpos($condition, 'chk-meta-new,cat="') !== false
			&& strpos($condition, 'chk-meta-until,cat="') !== false
			&& strpos($condition, 'd.get_local_id=') !== false
			&& strpos($condition, self::LOCAL_ID) !== false;
	}

	private function resetFakes()
	{
		Torrent::$fixtures = array();
		Torrent::$constructions = 0;
		Snoopy::$nextStatus = 200;
		Snoopy::$nextResults = '';
		rTorrent::$source = false;
		rTorrent::$sourceQueue = array();
		rTorrent::$sourceReads = 0;
		rTorrent::$sendResult = false;
		rTorrent::$lastSend = null;
		rTorrent::$sends = array();
		rXMLRPCRequest::reset();
		rXMLRPCRequest::$defaultLocalProjectionResponse = RuTrackerAtomicOwnership::SENTINEL_ACTED;
		FileUtil::$log = array();
		RuTrackerMetaFetch::$calls = array();
		RuTrackerMetaFetch::$result = null;
		ErasedataFake::reset();
		strictSetPrivateStatic('ruTrackerChecker', 'TRACKERS', array());
		strictSetPrivateStatic('ruTrackerChecker', 'claimStoreFailureLogged', false);
		// A fresh claim store per test: the meta-pump claim is keyed by hash
		// and several tests reuse the same one.
		if($this->stateDir !== null) strictRemoveTree($this->stateDir);
		$this->stateDir = sys_get_temp_dir() . '/rut-check-state-' . getmypid();
		strictRemoveTree($this->stateDir);
		strictSetPrivateStatic('RuTrackerState', 'dir', $this->stateDir);
	}

	private $stateDir = null;

	private function realisticInfo($info)
	{
		if(is_array($info) && array_key_exists('name', $info)
			&& !array_key_exists('length', $info) && !array_key_exists('files', $info))
			$info['length'] = 1;
		return($info);
	}

	// Fixtures for a replacement of hash OLD by hash NEW.
	private function stageTorrents($oldInfo = array(), $newInfo = array())
	{
		if($oldInfo === array() && $newInfo === array())
			$oldInfo = $newInfo = array('name' => 'unchanged.mkv');
		$oldInfo = $this->realisticInfo($oldInfo);
		$newInfo = $this->realisticInfo($newInfo);
		Torrent::$fixtures['new-torrent'] = array('hash' => self::NEW_HASH, 'info' => $newInfo);
		rTorrent::$source = new Torrent(array('hash' => self::OLD_HASH, 'info' => $oldInfo));
		rTorrent::$sendResult = self::NEW_HASH;
	}

	// $views is what the OLD torrent belongs to (d.views); $existing is what
	// rTorrent currently has (view.list, aliased "view_list"). They are two
	// different reads: views are runtime state, so a rat_N the old torrent
	// belonged to before a restart can be missing from the live list, and a
	// view.set_visible for it would throw input_error inside the load command
	// list. By default every view the old torrent belongs to still exists.
	private function queueViews($views = null, $existing = null)
	{
		$views = $views === null ? array('main', 'rat_2', 'rat_7', 'rat_9', 'rat_bad', 'rat_2_extra') : $views;
		rXMLRPCRequest::queue('d.views', true, false, $views);
		if($existing !== false)
			rXMLRPCRequest::queue('view_list', true, false,
				$existing === null ? array_merge(array('main', 'default', 'seeding'), $views) : $existing);
	}

	private function queueSnapshot($baseDir, $state = 1, $open = 1, $topic = '6879823', $forum = '1106')
	{
		rXMLRPCRequest::queue(
			self::SNAPSHOT_KEY_COMMANDS,
			true,
			false,
			array($baseDir, 'label', 'slow', 'seed-value', $topic, $forum)
		);
		rXMLRPCRequest::queue('branch', true, false, function($commands) use ($state, $open) {
			$matches = array();
			if($state)
				preg_match_all('/' . self::NEW_HASH . '-(?:started|open|stopped)-\d+/', $commands[0]->params[2], $matches);
			else
				preg_match_all('/' . self::NEW_HASH . '-(?:started|open|stopped)-\d+/', $commands[0]->params[3], $matches);
			if(!count($matches[0])) throw new RuntimeException('No marker in replacement branch');
			return array($state || !$open ? end($matches[0]) : $matches[0][0]);
		});
	}

	// Preflight (NEW hash absent), ratio views, then the snapshot-and-stop multicall.
	private function queueTransactionStart($baseDir, $state = 1, $open = 1, $topic = '6879823', $forum = '1106')
	{
		rXMLRPCRequest::queue('d.hash', true, true, array());
		$this->queueViews();
		$this->queueSnapshot($baseDir, $state, $open, $topic, $forum);
	}

	private function currentReplacementMarker()
	{
		if(!is_array(rTorrent::$lastSend) || !is_array(rTorrent::$lastSend['addition']))
			return '';
		foreach(rTorrent::$lastSend['addition'] as $addition)
			if(strpos($addition, 'd.set_custom=chk-replacement,') === 0)
				return substr($addition, strlen('d.set_custom=chk-replacement,'));
		return '';
	}

	// One waitForLoad poll answer; the marker is resolved lazily because the
	// production code generates it randomly right before sendTorrent().
	private function queueLoadConfirmed($marker = null)
	{
		rXMLRPCRequest::queue('d.get_custom', true, false, function() use ($marker) {
			return array($marker === null ? $this->currentReplacementMarker() : $marker);
		});
	}

	private function queueAtomic($sentinel, $ok = true, $fault = false)
	{
		rXMLRPCRequest::queue('branch', $ok, $fault,
			$ok && !$fault ? array($sentinel) : array());
	}

	// Queue the post-F08 contract directly: non-run metadata is snapshotted,
	// then one daemon-side branch chooses the live run state, records it and
	// stops/closes the predecessor without another PHP round trip.
	private function queueDaemonSelectedStart($baseDir, $selectedRun, $topic = '6879823', $forum = '1106')
	{
		rXMLRPCRequest::queue('d.hash', true, true, array());
		$this->queueViews();
		rXMLRPCRequest::queue(self::COMMIT_SNAPSHOT_KEY_COMMANDS, true, false,
			array($baseDir, 'label', 'slow', 'seed-value', $topic, $forum));
		rXMLRPCRequest::queue('branch', true, false, function($commands) use ($selectedRun) {
			strictAssertSame(1, count($commands), 'run-state capture is one daemon command');
			$matches = array();
			preg_match_all('/' . self::NEW_HASH . '-(?:started|open|stopped)-\d+/', implode('|', $commands[0]->params), $matches);
			$markers = array_values(array_unique($matches[0]));
			foreach($markers as $candidate)
				if(strpos($candidate, '-' . $selectedRun . '-') !== false)
					return array($candidate);
			throw new RuntimeException('No ' . $selectedRun . ' marker in replacement branch');
		});
	}

	// Everything a committed replacement needs except the activation commands.
	private function stageHappyReplacement($baseDir, $state = 1, $open = 1, $oldInfo = array(), $newInfo = array(),
		$topic = '6879823', $forum = '1106')
	{
		$this->stageTorrents($oldInfo, $newInfo);
		$this->queueTransactionStart($baseDir, $state, $open, $topic, $forum);
		$this->queueLoadConfirmed();
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ERASED);
	}

	private function requestIndexes($key, $params = null)
	{
		$indexes = array();
		foreach(rXMLRPCRequest::$requests as $index => $request)
			if($request['key'] === $key && ($params === null || $request['commands'][0]->params === $params))
				$indexes[] = $index;
		return $indexes;
	}

	private function assertNoRequestKeyContains($needle, $message)
	{
		foreach(rXMLRPCRequest::$requests as $request)
			strictAssertTrue(strpos($request['key'], $needle) === false, $message . ' (saw ' . $request['key'] . ')');
	}

	private function branchRequestsContaining($needle)
	{
		$matches = array();
		foreach(rXMLRPCRequest::requestsFor('branch') as $request)
		{
			$params = isset($request['commands'][0]->params)
				? $request['commands'][0]->params : array();
			if(strpos(implode('|', array_map('strval', $params)), $needle) !== false)
				$matches[] = $request;
		}
		return($matches);
	}

	// Both spellings a ratio membership can take in the load command list:
	// view.set_visible for a confirmed view, d.views.push_back_unique for an
	// attribute-only forward of an unconfirmed one.
	private function membershipCommands($addition)
	{
		return array_values(array_filter($addition, function($command) {
			return strpos($command, 'view.set_visible=') === 0
				|| strpos($command, 'd.views.push_back_unique=') === 0;
		}));
	}

	private function withDebugLog($body)
	{
		$savedDebug = isset($GLOBALS['rutrackerCheckDebug']) ? $GLOBALS['rutrackerCheckDebug'] : null;
		$GLOBALS['rutrackerCheckDebug'] = true;
		try
		{
			$body();
		}
		finally
		{
			if($savedDebug === null)
				unset($GLOBALS['rutrackerCheckDebug']);
			else
				$GLOBALS['rutrackerCheckDebug'] = $savedDebug;
		}
	}

	// The other half of withDebugLog(), and the only setting under which the
	// two log channels are distinguishable: conf.php's shipped
	// $rutrackerCheckDebug = false. logDebug() writes nothing here, so
	// anything that still reaches FileUtil::toLog() came out the ungated
	// channel -- which is what "an operator is told" means in production.
	private function withoutDebugLog($body)
	{
		$savedDebug = isset($GLOBALS['rutrackerCheckDebug']) ? $GLOBALS['rutrackerCheckDebug'] : null;
		$GLOBALS['rutrackerCheckDebug'] = false;
		try
		{
			$body();
		}
		finally
		{
			if($savedDebug === null)
				unset($GLOBALS['rutrackerCheckDebug']);
			else
				$GLOBALS['rutrackerCheckDebug'] = $savedDebug;
		}
	}

	private function cleanupEntries($oldInfo, $newInfo, $base)
	{
		$oldInfo = $this->realisticInfo($oldInfo);
		$newInfo = $this->realisticInfo($newInfo);
		$old = new Torrent(array('hash' => self::OLD_HASH, 'info' => $oldInfo));
		$new = new Torrent(array('hash' => self::NEW_HASH, 'info' => $newInfo));
		return(strictInvoke('ruTrackerChecker', 'buildObsoleteCleanupFiles', array($old, $new, $base,
			self::OLD_HASH, self::NEW_HASH, self::PLUGIN_MARKER, self::OLD_HASH.'-started-1787587200')));
	}

	private function erasedataCalls($name)
	{
		return(array_values(array_filter(ErasedataFake::$calls, function($call) use ($name) {
			return($call['name'] === $name);
		})));
	}

	private function queueExistingGeneration($state, $open, $oldPresence)
	{
		Torrent::$fixtures['new-torrent'] = array('hash' => self::NEW_HASH,
			'info' => array('name' => 'new.mkv', 'length' => 1));
		rTorrent::$source = new Torrent(array('hash' => self::OLD_HASH,
			'info' => array('name' => 'old.mkv', 'length' => 1)));
		rXMLRPCRequest::queue('d.hash', true, false, array(self::NEW_HASH));
		rXMLRPCRequest::queue(self::PREFLIGHT_KEY_COMMANDS, true, false, array(
			self::PLUGIN_MARKER, $state, $open, self::OLD_HASH . '-started-1787587200'));
		if($oldPresence === true)
			rXMLRPCRequest::queue('d.hash', true, false, array(self::OLD_HASH));
		elseif($oldPresence === false)
			rXMLRPCRequest::queue('d.hash', true, true, array());
	}

	public function testObsoleteDiffOwnsOnlyOldFileInSharedDirectory()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-owned-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old-film.mkv', 'old');
		file_put_contents($base . '/new-film.mkv', 'new');
		file_put_contents($base . '/another-film.mkv', 'neighbor');
		file_put_contents($base . '/personal.txt', 'personal');
		try
		{
			$entries = $this->cleanupEntries(array('name' => 'old-film.mkv'), array('name' => 'new-film.mkv'), $base);
			strictAssertSame(1, count($entries), 'the shared directory contributes exactly one owned obsolete file');
			strictAssertSame(realpath($base . '/old-film.mkv'), $entries[0]['path'],
				'the job owns only the exact old metainfo path');
			strictAssertTrue($entries[0]['path'] !== $base && is_file($base . '/another-film.mkv')
				&& is_file($base . '/personal.txt'), 'the shared base and unrelated files are never ownership entries');
		}
		finally { strictRemoveTree($base); }
	}

	public function testObsoleteDiffProtectsThirdTorrentClaimAndKeepsOrphanObligation()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-cross-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		$shared = $base.'/shared.bin';
		$orphan = $base.'/orphan.bin';
		file_put_contents($shared, 'shared');
		file_put_contents($orphan, 'orphan');
		ErasedataFake::$claimPaths["p\0".$shared] = true;
		try
		{
			$entries = $this->cleanupEntries(
				array('files' => array(array('path' => array('shared.bin')),
					array('path' => array('orphan.bin')))),
				array('name' => 'replacement.bin'), $base);
			strictAssertSame(1, count($entries),
				'a third-torrent path is protected while a genuine orphan stays in the job');
			strictAssertSame($orphan, $entries[0]['path'],
				'the orphan alone remains the cleanup obligation');
		}
		finally { strictRemoveTree($base); }
	}

	public function testObsoleteDiffIsEmptyForUnchangedPaths()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-unchanged-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/same.mkv', 'same');
		try
		{
			strictAssertSame(null, $this->cleanupEntries(array('name' => 'same.mkv'), array('name' => 'same.mkv'), $base),
				'an unchanged exact path creates no cleanup obligation');
		}
		finally { strictRemoveTree($base); }
	}

	public function testObsoleteDiffRejectsUnsafeMetainfoComponents()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-components-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		try
		{
			foreach(array('', '.', '..', "bad\0name", 'bad/name', 'bad\\name') as $component)
				strictAssertSame(false, $this->cleanupEntries(
					array('files' => array(array('path' => array('nested', $component)))),
					array('files' => array(array('path' => array('nested', 'new.mkv')))), $base),
					'unsafe metainfo components abort the complete cleanup build');
		}
		finally { strictRemoveTree($base); }
	}

	public function testObsoleteDiffRequiresExactlyOneMetainfoFileDiscriminator()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-discriminator-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		try
		{
			$ambiguous = new Torrent(array('info' => array(
				'name' => 'ambiguous.mkv', 'length' => 1,
				'files' => array(array('path' => array('ambiguous.mkv'))),
			)));
			$missing = new Torrent(array('info' => array('name' => 'missing-discriminator.mkv')));
			strictAssertSame(null, strictInvoke('ruTrackerChecker', 'collectTorrentPaths', array($ambiguous)),
				'metainfo with both length and files is unsafe');
			strictAssertSame(null, strictInvoke('ruTrackerChecker', 'collectTorrentPaths', array($missing)),
				'metainfo with neither length nor files is unsafe');
		}
		finally { strictRemoveTree($base); }
	}

	private function assertAmbiguousReplacementAborts($oldInfo, $newInfo, $label)
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-ambiguous-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'old');
		$this->stageTorrents($oldInfo, $newInfo);
		$this->queueTransactionStart($base);
		$this->queueLoadConfirmed();
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ERASED);
		try
		{
			strictAssertSame(ruTrackerChecker::STE_ERROR,
				ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH), $label . ' aborts replacement');
			strictAssertSame(0, count($this->erasedataCalls('erasedataPrepareObsoleteCleanup')),
				$label . ' creates no cleanup entry');
			$erases = $this->branchRequestsContaining('$d.erase=');
			strictAssertSame(1, count($erases), $label . ' never reaches predecessor commit');
			strictAssertSame(self::NEW_HASH, $erases[0]['commands'][0]->params[0],
				$label . ' only discards the staged successor');
		}
		finally { strictRemoveTree($base); }
	}

	public function testAmbiguousOldMetainfoCreatesNoCleanupEntryOrCommit()
	{
		$this->assertAmbiguousReplacementAborts(
			array('name' => 'old.mkv', 'length' => 3,
				'files' => array(array('path' => array('old.mkv')))),
			array('name' => 'new.mkv'), 'ambiguous OLD metainfo');
	}

	public function testAmbiguousNewMetainfoCreatesNoCleanupEntryOrCommit()
	{
		$this->assertAmbiguousReplacementAborts(
			array('name' => 'old.mkv'),
			array('name' => 'new.mkv', 'length' => 3,
				'files' => array(array('path' => array('new.mkv')))),
			'ambiguous NEW metainfo');
	}

	public function testPaddingEntriesAreExcludedFromPhysicalOwnership()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-padding-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/padding.bin', 'neighbor');
		file_put_contents($base . '/new.bin', 'new');
		try
		{
			strictAssertSame(null, $this->cleanupEntries(
				array('files' => array(array('path' => array('padding.bin'), 'attr' => 'px'))),
				array('files' => array(array('path' => array('new.bin'), 'attr' => 'x'))), $base),
				'an OLD padding entry never owns an existing neighboring file');
			$entries = $this->cleanupEntries(
				array('files' => array(array('path' => array('padding.bin'), 'attr' => 'x'))),
				array('files' => array(
					array('path' => array('padding.bin'), 'attr' => 'p'),
					array('path' => array('new.bin'), 'attr' => ''),
				)), $base);
			strictAssertSame(1, count($entries), 'a NEW padding name does not protect an ordinary OLD file');
			strictAssertSame($base . '/padding.bin', $entries[0]['path'],
				'valid non-padding attr strings remain physical ownership');
			strictAssertSame(false, $this->cleanupEntries(
				array('files' => array(array('path' => array('padding.bin'), 'attr' => 1))),
				array('files' => array(array('path' => array('new.bin')))), $base),
				'a non-string attr fails closed');
		}
		finally { strictRemoveTree($base); }
	}

	public function testObsoleteDiffRejectsUnusableBaseOrSymlinkEscape()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-base-' . bin2hex(random_bytes(5));
		$outside = sys_get_temp_dir() . '/rut-check-outside-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		mkdir($outside, 0777, true);
		file_put_contents($outside . '/old.mkv', 'outside');
		symlink($outside, $base . '/escape');
		try
		{
			strictAssertSame(false, $this->cleanupEntries(array('name' => 'old.mkv'), array('name' => 'new.mkv'), $base . '/missing'),
				'a missing base is unusable');
			strictAssertSame(false, $this->cleanupEntries(array('name' => 'old.mkv'), array('name' => 'new.mkv'), '/'),
				'the filesystem root is never a cleanup boundary');
			strictAssertSame(false, $this->cleanupEntries(
				array('files' => array(array('path' => array('escape', 'old.mkv')))),
				array('files' => array(array('path' => array('safe', 'new.mkv')))), $base),
				'an existing old target through a symlinked parent aborts the build');
		}
		finally
		{
			strictRemoveTree($base);
			strictRemoveTree($outside);
		}
	}

	public function testObsoleteDiffRejectsUnsafeSuccessorCandidates()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-new-candidates-' . bin2hex(random_bytes(5));
		$outside = sys_get_temp_dir() . '/rut-check-new-outside-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		mkdir($outside, 0777, true);
		file_put_contents($base . '/old.mkv', 'old');
		file_put_contents($outside . '/existing.mkv', 'outside');
		symlink($outside, $base . '/escape');
		symlink($base . '/missing-target.mkv', $base . '/dangling.mkv');
		try
		{
			foreach(array('existing.mkv', 'missing.mkv') as $leaf)
				strictAssertSame(false, $this->cleanupEntries(
					array('name' => 'old.mkv'),
					array('files' => array(array('path' => array('escape', $leaf)))), $base),
					'a successor through an external symlink parent is unsafe: ' . $leaf);
			strictAssertSame(false, $this->cleanupEntries(array('name' => 'old.mkv'),
				array('name' => 'dangling.mkv'), $base), 'a dangling exact successor is unsafe');

			$this->stageTorrents(array('name' => 'old.mkv'),
				array('files' => array(array('path' => array('escape', 'missing.mkv')))));
			$this->queueTransactionStart($base);
			$this->queueLoadConfirmed();
			$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
			$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ERASED);
			strictAssertSame(ruTrackerChecker::STE_ERROR,
				ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
				'an unsafe successor candidate aborts the staged replacement');
			strictAssertSame(0, count($this->erasedataCalls('erasedataPrepareObsoleteCleanup')),
				'an unsafe successor creates no cleanup generation');
			$erases = $this->branchRequestsContaining('$d.erase=');
			strictAssertSame(1, count($erases), 'an unsafe successor never reaches predecessor commit');
			strictAssertSame(self::NEW_HASH, $erases[0]['commands'][0]->params[0],
				'only the unsafe staged successor is discarded');
		}
		finally
		{
			strictRemoveTree($base);
			strictRemoveTree($outside);
		}
	}

	public function testObsoleteDiffDistinguishesStructuralPathsFromExactAliases()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-structural-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/node', 'old blocker');
		try
		{
			$entries = $this->cleanupEntries(array('name' => 'node'),
				array('files' => array(array('path' => array('node', 'new.bin')))), $base);
			strictAssertSame(1, count($entries),
				'an OLD regular file that blocks a missing NEW descendant remains an exact deletion obligation');
			strictAssertSame($base . '/node', $entries[0]['path'],
				'lexical containment is not an exact-file alias');

			unlink($base . '/node');
			mkdir($base . '/node');
			file_put_contents($base . '/node/old.bin', 'old child');
			strictAssertSame(false, $this->cleanupEntries(
				array('files' => array(array('path' => array('node', 'old.bin')))),
				array('name' => 'node'), $base),
				'an existing NEW exact target that is currently a directory fails closed');
		}
		finally { strictRemoveTree($base); }
	}

	public function testObsoleteDiffSkipsMissingOldTarget()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-missing-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/new.mkv', 'new');
		try
		{
			strictAssertSame(null, $this->cleanupEntries(array('name' => 'already-gone.mkv'), array('name' => 'new.mkv'), $base),
				'a missing obsolete object creates no durable obligation');
		}
		finally { strictRemoveTree($base); }
	}

	public function testObsoleteDiffRejectsDirectoryAndSpecialFileTargets()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-types-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		mkdir($base . '/directory');
		try
		{
			strictAssertSame(false, $this->cleanupEntries(array('name' => 'directory'), array('name' => 'new.mkv'), $base),
				'an obsolete directory is not an owned regular file');
			if(function_exists('posix_mkfifo') && @posix_mkfifo($base . '/pipe', 0600))
				strictAssertSame(false, $this->cleanupEntries(array('name' => 'pipe'), array('name' => 'new.mkv'), $base),
					'an obsolete FIFO is not an owned regular file');
		}
		finally { strictRemoveTree($base); }
	}

	public function testObsoleteDiffProtectsCaseSymlinkAndHardlinkAliases()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-aliases-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old-case.mkv', 'case');
		file_put_contents($base . '/old-link.mkv', 'link');
		link($base . '/old-case.mkv', $base . '/OLD-CASE.mkv');
		symlink($base . '/old-link.mkv', $base . '/new-link.mkv');
		try
		{
			$oldInfo = array('files' => array(
				array('path' => array('old-case.mkv')),
				array('path' => array('old-link.mkv')),
			));
			$newInfo = array('files' => array(
				array('path' => array('OLD-CASE.mkv')),
				array('path' => array('new-link.mkv')),
			));
			strictAssertSame(null, $this->cleanupEntries($oldInfo, $newInfo, $base),
				'case-different hardlinks and symlink successor aliases protect both old objects');
		}
		finally { strictRemoveTree($base); }
	}

	public function testObsoleteDiffCapturesPersistentIdentity()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-identity-' . bin2hex(random_bytes(5));
		mkdir($base . '/nested', 0777, true);
		file_put_contents($base . '/nested/old.bin', 'persistent bytes');
		file_put_contents($base . '/nested/keep.bin', 'keep');
		file_put_contents($base . '/nested/new.bin', 'new');
		try
		{
			$entries = $this->cleanupEntries(
				array('files' => array(array('path' => array('nested', 'old.bin')), array('path' => array('nested', 'keep.bin')))),
				array('files' => array(array('path' => array('nested', 'new.bin')), array('path' => array('nested', 'keep.bin')))), $base);
			$stat = stat($base . '/nested/old.bin');
			$lstat = lstat($base . '/nested/old.bin');
			strictAssertSame(array(
				'canonical' => realpath($base . '/nested/old.bin'),
				'lstat' => array('dev' => $lstat['dev'], 'ino' => $lstat['ino']),
				'stat' => array('dev' => $stat['dev'], 'ino' => $stat['ino']),
				'size' => $stat['size'],
				'mtime' => $stat['mtime'],
			), $entries[0]['identity'], 'the prepared entry captures the complete persistent identity contract');
		}
		finally { strictRemoveTree($base); }
	}

	public function testCleanupPrepareOccursAfterOwnedLoadBeforePredecessorErase()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-prepare-order-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'old');
		$this->stageHappyReplacement($base, 1, 1, array('name' => 'old.mkv'), array('name' => 'new.mkv'));
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		try
		{
			strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH), 'the cleanup-backed replacement commits');
			$prepare = $this->erasedataCalls('erasedataPrepareObsoleteCleanup');
			strictAssertSame(1, count($prepare), 'one exact durable cleanup generation is prepared');
			$loadRead = $this->requestIndexes('d.get_custom', array(self::NEW_HASH, ruTrackerChecker::REPLACEMENT_MARKER_KEY));
			$erase = $this->branchRequestsContaining('$d.erase=');
			strictAssertTrue($loadRead[0] < $prepare[0]['request_count'], 'preparation follows confirmed ownership of the loaded successor');
			strictAssertTrue($prepare[0]['request_count'] <= array_search($erase[0], rXMLRPCRequest::$requests, true),
				'preparation completes before the predecessor erase request');
			strictAssertSame($base . '/old.mkv', $prepare[0]['arguments'][5][0]['path'],
				'the durable producer receives the exact obsolete entry');
		}
		finally { strictRemoveTree($base); }
	}

	public function testMissingDrainAcknowledgementKeepsPredecessorBeforeErase()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-arm-no-ack-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'old');
		$this->stageTorrents(array('name' => 'old.mkv'), array('name' => 'new.mkv'));
		$this->queueTransactionStart($base);
		$this->queueLoadConfirmed();
		ErasedataFake::$armResult = false;
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ERASED);
		try
		{
			strictAssertSame(ruTrackerChecker::STE_ERROR,
				ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
				'a missing guarded-child acknowledgement keeps the replacement retryable');
			strictAssertSame(array('erasedataCleanupOtherOwnerSnapshot',
				'erasedataPrepareObsoleteCleanup', 'erasedataArmObsoleteCleanup',
				'erasedataCancelObsoleteCleanup'), array_column(ErasedataFake::$calls, 'name'),
				'the exact prepared cleanup job is cancelled after refused arm');
			$erases = $this->branchRequestsContaining('$d.erase=');
			strictAssertSame(1, count($erases), 'only the staged successor may be discarded');
			strictAssertSame(self::NEW_HASH, $erases[0]['commands'][0]->params[0],
				'the predecessor is never erased without drain acknowledgement');
		}
		finally { strictRemoveTree($base); }
	}

	public function testCleanupPrepareFailureAbortsBeforeCommit()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-prepare-fail-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'old');
		$this->stageTorrents(array('name' => 'old.mkv'), array('name' => 'new.mkv'));
		$this->queueTransactionStart($base);
		$this->queueLoadConfirmed();
		ErasedataFake::$prepareResult = false;
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ERASED);
		try
		{
			strictAssertSame(ruTrackerChecker::STE_ERROR, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
				'a failed durable prepare aborts the replacement');
			$erases = $this->branchRequestsContaining('$d.erase=');
			strictAssertSame(1, count($erases), 'only the staged successor is discarded after predecessor restore');
			strictAssertSame(self::NEW_HASH, $erases[0]['commands'][0]->params[0], 'the predecessor is never erased after prepare failure');
		}
		finally { strictRemoveTree($base); }
	}

	public function testUnknownThirdOwnerScanAbortsBeforePredecessorErase()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-fleet-unknown-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'old');
		$this->stageTorrents(array('name' => 'old.mkv'), array('name' => 'new.mkv'));
		$this->queueTransactionStart($base);
		$this->queueLoadConfirmed();
		ErasedataFake::$scanUnknown = true;
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ERASED);
		try
		{
			strictAssertSame(ruTrackerChecker::STE_ERROR,
				ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
				'an unknown fleet scan must abort replacement');
			$erases = $this->branchRequestsContaining('$d.erase=');
			strictAssertSame(1, count($erases),
				'only the staged successor may be erased after ownership becomes unknown');
			strictAssertSame(self::NEW_HASH, $erases[0]['commands'][0]->params[0],
				'the predecessor remains present when fleet ownership is unknown');
		}
		finally { strictRemoveTree($base); }
	}

	public function testFailedCommitCancelsCleanupBeforeRollback()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-cancel-order-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'old');
		$this->stageTorrents(array('name' => 'old.mkv'), array('name' => 'new.mkv'));
		$this->queueTransactionStart($base);
		$this->queueLoadConfirmed();
		$this->queueAtomic('', false, true);
		rXMLRPCRequest::queue('d.hash', true, false, array(self::OLD_HASH));
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ERASED);
		try
		{
			ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH);
			$cancel = $this->erasedataCalls('erasedataCancelObsoleteCleanup');
			$restores = $this->branchRequestsContaining('$d.start=');
			strictAssertSame(1, count($cancel), 'the exact prepared job is cancelled once');
			strictAssertTrue($cancel[0]['request_count'] <= array_search($restores[0], rXMLRPCRequest::$requests, true),
				'cleanup cancellation precedes rollback state restoration');
		}
		finally { strictRemoveTree($base); }
	}

	public function testFailedCleanupCancelLeavesBothGenerationsRecoverable()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-cancel-fail-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'old');
		$this->stageTorrents(array('name' => 'old.mkv'), array('name' => 'new.mkv'));
		$this->queueTransactionStart($base);
		$this->queueLoadConfirmed();
		$this->queueAtomic('', false, true);
		rXMLRPCRequest::queue('d.hash', true, false, array(self::OLD_HASH));
		ErasedataFake::$cancelResult = false;
		try
		{
			strictAssertSame(ruTrackerChecker::STE_ERROR, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
				'uncertain cleanup cancellation remains retryable');
			strictAssertSame(0, count($this->branchRequestsContaining('$d.start=')), 'the predecessor recovery key is not cleared after cancel failure');
			strictAssertSame(1, count($this->branchRequestsContaining('$d.erase=')), 'the marked successor is retained after cancel failure');
		}
		finally { strictRemoveTree($base); }
	}

	public function testUnknownCommitOutcomeRetainsPreparedTmpAndMarkers()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-commit-unknown-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'old');
		$this->stageTorrents(array('name' => 'old.mkv'), array('name' => 'new.mkv'));
		$this->queueTransactionStart($base);
		$this->queueLoadConfirmed();
		$this->queueAtomic('', false, true);
		try
		{
			ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH);
			strictAssertSame(0, count($this->erasedataCalls('erasedataCancelObsoleteCleanup')), 'unknown OLD presence does not cancel the tmp');
			strictAssertSame(0, count($this->erasedataCalls('erasedataPublishObsoleteCleanup')), 'unknown OLD presence does not publish the tmp');
			strictAssertSame(1, count($this->branchRequestsContaining('$d.erase=')), 'neither generation marker is discarded after uncertainty');
		}
		finally { strictRemoveTree($base); }
	}

	public function testTorrentExistsMapsOnlyKnownMissingHashFaultToAbsent()
	{
		$this->resetFakes();
		rXMLRPCRequest::queue('d.hash', true, true, array(), 'Method not found');
		strictAssertSame(null, ruTrackerChecker::torrentExists(self::OLD_HASH),
			'a generic parsed fault is unknown, not proof of absence');
		rXMLRPCRequest::queue('d.hash', true, true, array());
		strictAssertSame(false, ruTrackerChecker::torrentExists(self::OLD_HASH),
			'the realistic missing-info-hash fault is absent');
		rXMLRPCRequest::queue('d.hash', true, false, array(self::OLD_HASH));
		strictAssertSame(true, ruTrackerChecker::torrentExists(self::OLD_HASH),
			'an exact returned hash is present');
	}

	public function testGenericCommitFaultRetainsPreparedTmpAndMarkers()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-commit-fault-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'old');
		$this->stageTorrents(array('name' => 'old.mkv'), array('name' => 'new.mkv'));
		$this->queueTransactionStart($base);
		$this->queueLoadConfirmed();
		$this->queueAtomic('', false, true);
		rXMLRPCRequest::queue('d.hash', true, true, array(), 'Permission denied');
		try
		{
			strictAssertSame(ruTrackerChecker::STE_ERROR,
				ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
				'a generic OLD presence fault keeps the commit boundary retryable');
			strictAssertSame(0, count($this->erasedataCalls('erasedataCancelObsoleteCleanup')),
				'generic uncertainty never cancels the prepared tmp');
			strictAssertSame(0, count($this->erasedataCalls('erasedataPublishObsoleteCleanup')),
				'generic uncertainty never publishes the prepared tmp');
			strictAssertSame(1, count($this->branchRequestsContaining('$d.erase=')),
				'generic uncertainty erases neither retained generation');
			strictAssertSame(0, count($this->branchRequestsContaining('$d.set_custom=chk-replacement,')),
				'generic uncertainty clears no replacement marker');
		}
		finally { strictRemoveTree($base); }
	}

	public function testCleanupPublishOccursBeforeActivation()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-publish-order-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'old');
		$this->stageHappyReplacement($base, 1, 1, array('name' => 'old.mkv'), array('name' => 'new.mkv'));
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		try
		{
			ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH);
			$publish = $this->erasedataCalls('erasedataPublishObsoleteCleanup');
			$activation = $this->branchRequestsContaining('$d.start=');
			strictAssertSame(1, count($publish), 'the exact prepared generation is published once');
			strictAssertTrue($publish[0]['request_count'] <= array_search($activation[0], rXMLRPCRequest::$requests, true),
				'publication precedes successor activation');
			strictAssertSame(array('erasedataCleanupOtherOwnerSnapshot', 'erasedataPrepareObsoleteCleanup',
				'erasedataArmObsoleteCleanup', 'erasedataPublishObsoleteCleanup', 'erasedataKickCollector'),
				array_column(ErasedataFake::$calls, 'name'), 'the producer lifecycle has one prepare, publish and post-activation kick');
		}
		finally { strictRemoveTree($base); }
	}

	public function testFailedCleanupPublishActivatesWithoutClosingTransaction()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-publish-fail-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'old');
		$this->stageHappyReplacement($base, 1, 1, array('name' => 'old.mkv'), array('name' => 'new.mkv'));
		ErasedataFake::$publishResult = false;
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		try
		{
			strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
				'publish failure does not undo a committed replacement');
			$activation = $this->branchRequestsContaining('$d.start=');
			strictAssertSame(1, count($activation), 'run state is still restored after publish failure');
			strictAssertTrue(strpos(implode('|', $activation[0]['commands'][0]->params), '$d.set_custom=chk-replacement,') === false,
				'marker-retaining activation never clears the replacement marker');
			strictAssertSame(0, count($this->erasedataCalls('erasedataKickCollector')), 'an unpublished job is never kicked');
		}
		finally { strictRemoveTree($base); }
	}

	// A refused activation used to emit two lines for one status: "Skipped
	// activation ... due to ownership or state change" and, immediately after
	// it, "Could not confirm activation ...". That reads in the log as a
	// contradiction while adding no fact -- SKIPPED is a guard that refused on
	// purpose, UNCONFIRMED is an attempt whose result was not readable, and
	// they are different things to go and look at.
	public function testARefusedActivationIsReportedOnceAndSaysWhichOutcomeItWas()
	{
		foreach(array(
			array('label' => 'skipped', 'sentinel' => RuTrackerAtomicOwnership::SENTINEL_SKIPPED,
				'says' => 'was skipped', 'not' => 'could not be confirmed'),
			array('label' => 'unconfirmed', 'sentinel' => RuTrackerAtomicOwnership::SENTINEL_UNCONFIRMED,
				'says' => 'could not be confirmed', 'not' => 'was skipped'),
		) as $case)
		{
			$this->resetFakes();
			$this->stageHappyReplacement(sys_get_temp_dir(), 1, 1);
			$this->queueAtomic($case['sentinel']);
			$this->withDebugLog(function() use ($case) {
				strictAssertSame(null,
					ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
					$case['label'] . ': a refused activation is still a committed replacement');
			});
			$lines = array_values(array_filter(FileUtil::$log, function($line) {
				return strpos($line, 'rutracker_check: activateReplacement: ') === 0;
			}));
			strictAssertSame(1, count($lines),
				$case['label'] . ': one activation outcome is one line: ' . implode(' // ', $lines));
			strictAssertTrue(strpos($lines[0], $case['says']) !== false,
				$case['label'] . ': the line must say what happened: ' . $lines[0]);
			strictAssertTrue(strpos($lines[0], $case['not']) === false,
				$case['label'] . ': and must not also claim the other outcome: ' . $lines[0]);
		}
	}

	public function testPublishedCleanupSurvivesActivationFailure()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-activation-published-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'old');
		$this->stageHappyReplacement($base, 1, 1, array('name' => 'old.mkv'), array('name' => 'new.mkv'));
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_UNCONFIRMED);
		try
		{
			strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH), 'activation uncertainty remains post-commit success');
			strictAssertSame(1, count($this->erasedataCalls('erasedataPublishObsoleteCleanup')), 'the durable job remains published');
			strictAssertSame(1, count($this->erasedataCalls('erasedataKickCollector')), 'published cleanup is kicked despite activation uncertainty');
			strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')), 'activation failure retains both transaction keys');
		}
		finally { strictRemoveTree($base); }
	}

	public function testCleanupKickFailureDoesNotRollbackCommittedReplacement()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-kick-fail-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'old');
		$this->stageHappyReplacement($base, 1, 1, array('name' => 'old.mkv'), array('name' => 'new.mkv'));
		ErasedataFake::$kickResult = false;
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		try
		{
			strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH), 'a failed immediate kick leaves the committed replacement successful');
			strictAssertSame(1, count($this->erasedataCalls('erasedataKickCollector')), 'the targeted kick is attempted once');
			strictAssertSame(1, count($this->branchRequestsContaining('$d.erase=')), 'no rollback erase follows the committed predecessor erase');
		}
		finally { strictRemoveTree($base); }
	}

	public function testExistingStagedGenerationCancelsPreparedCleanupBeforeDiscard()
	{
		$this->resetFakes();
		$this->queueExistingGeneration(0, 0, true);
		ErasedataFake::$generationCancelResult = ERASEDATA_CLEANUP_READY;
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ERASED);
		$this->queueViews();
		$this->queueSnapshot(sys_get_temp_dir(), 1, 1);
		$this->queueLoadConfirmed();
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ERASED);
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH);
		$cancel = $this->erasedataCalls('erasedataCancelObsoleteCleanupGeneration');
		$erases = $this->branchRequestsContaining('$d.erase=');
		strictAssertSame(1, count($cancel), 'the exact abandoned generation is cancelled');
		strictAssertTrue($cancel[0]['request_count'] <= array_search($erases[0], rXMLRPCRequest::$requests, true),
			'generation cancellation precedes stopped-successor discard');
	}

	public function testExistingStoppedCommittedGenerationRecoversCleanupBeforeFinish()
	{
		$this->resetFakes();
		$this->queueExistingGeneration(0, 0, false);
		ErasedataFake::$recoverResult = ERASEDATA_CLEANUP_READY;
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH), 'an already committed stopped generation is finished in place');
		strictAssertSame(array('erasedataRecoverObsoleteCleanup', 'erasedataKickCollector'),
			array_column(ErasedataFake::$calls, 'name'), 'cleanup is recovered and kicked before transaction finish');
		strictAssertSame(null, rTorrent::$lastSend, 'the committed successor is not discarded or loaded again');
	}

	public function testExistingLiveCommittedGenerationRecoversCleanupBeforeKeyClear()
	{
		$this->resetFakes();
		$this->queueExistingGeneration(1, 1, false);
		ErasedataFake::$recoverResult = ERASEDATA_CLEANUP_READY;
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_CLEARED);
		ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH);
		$recover = $this->erasedataCalls('erasedataRecoverObsoleteCleanup');
		$clear = $this->branchRequestsContaining('$d.set_custom=chk-replacement,');
		strictAssertSame(1, count($recover), 'cleanup recovery is attempted for a live successor too');
		strictAssertTrue($recover[0]['request_count'] <= array_search($clear[0], rXMLRPCRequest::$requests, true),
			'recovery precedes transaction-key clear');
		strictAssertSame(1, count($this->erasedataCalls('erasedataKickCollector')), 'a recovered durable job is kicked');
	}

	public function testExistingGenerationWithUnknownPredecessorPresenceRetainsEverything()
	{
		foreach(array('unknown' => null, 'recovery retry' => false, 'cancellation retry' => true) as $label => $oldPresence)
		{
			$this->resetFakes();
			$this->queueExistingGeneration(0, 0, $oldPresence);
			if($oldPresence === false)
				ErasedataFake::$recoverResult = ERASEDATA_CLEANUP_RETRY;
			elseif($oldPresence === true)
				ErasedataFake::$generationCancelResult = ERASEDATA_CLEANUP_RETRY;
			strictAssertSame(ruTrackerChecker::STE_ERROR, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
				$label . ' retains the transaction for retry');
			strictAssertSame(0, count($this->branchRequestsContaining('$d.erase=')), $label . ' does not discard either generation');
			strictAssertSame(0, count($this->branchRequestsContaining('$d.set_custom=chk-replacement,')), $label . ' does not clear successor keys');
		}
	}

	public function testExistingGenerationGenericPredecessorFaultRetainsEverything()
	{
		$this->resetFakes();
		$this->queueExistingGeneration(1, 1, null);
		rXMLRPCRequest::queue('d.hash', true, true, array(), 'Internal XMLRPC fault');
		strictAssertSame(ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
			'a pre-existing generation cannot infer OLD absence from a generic fault');
		strictAssertSame(array(), ErasedataFake::$calls,
			'unknown OLD presence neither recovers nor cancels cleanup');
		strictAssertSame(0, count($this->branchRequestsContaining('$d.erase=')),
			'unknown OLD presence retains both generations');
		strictAssertSame(0, count($this->branchRequestsContaining('$d.set_custom=chk-replacement,')),
			'unknown OLD presence retains exact generation keys');
	}

	public function testCleanupPreparationLogsCountsWithoutPathList()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-log-counts-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'old');
		$this->stageHappyReplacement($base, 1, 1, array('name' => 'old.mkv'), array('name' => 'new.mkv'));
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		try
		{
			$this->withDebugLog(function() { ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH); });
			$line = strictAssertOneLogMatching(FileUtil::$log, 'cleanup prepare', 'one preparation summary is logged');
			strictAssertTrue(strpos($line, 'old=1 new=1 obsolete=1 missing=0') !== false, 'the summary reports only bounded counts');
			strictAssertTrue(strpos($line, $base . '/old.mkv') === false, 'the summary never logs the full cleanup path list');
		}
		finally { strictRemoveTree($base); }
	}

	public function testCleanupPreparationFailureIsVisible()
	{
		$this->resetFakes();
		$GLOBALS['rutrackerCheckDebug'] = false;
		$base = sys_get_temp_dir() . '/rut-check-log-failure-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'old');
		$this->stageTorrents(array('name' => 'old.mkv'), array('name' => 'new.mkv'));
		$this->queueTransactionStart($base);
		$this->queueLoadConfirmed();
		ErasedataFake::$prepareResult = false;
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ERASED);
		try
		{
			ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH);
			strictAssertSame(1, count(strictLogsMatching(FileUtil::$log, 'durable cleanup preparation failed')),
				'a commit-blocking prepare failure reaches the shared log with optional debug disabled');
		}
		finally { strictRemoveTree($base); }
	}

	// A staged copy at the successor hash may belong to somebody else's
	// stranded transaction. It is the ONLY handle sweepReplacements has on it,
	// and that predecessor is already stopped and closed by its own dead run,
	// so erasing the copy puts it outside every scan the plugin makes, for
	// good. Defer instead -- the same deferral metafetch's begin() performs.
	public function testAStagedCopyOfAnotherTransactionIsNeverErased()
	{
		$this->resetFakes();
		$savedDebug = isset($GLOBALS['rutrackerCheckDebug']) ? $GLOBALS['rutrackerCheckDebug'] : null;
		$GLOBALS['rutrackerCheckDebug'] = true;
		try
		{
			$stranger = str_repeat('E', 40);
			$this->stageTorrents();
			rXMLRPCRequest::queue('d.hash', true, false, array(self::NEW_HASH));
			// Marked, stopped and closed -- but its record names a different
			// predecessor.
			rXMLRPCRequest::queue(self::PREFLIGHT_KEY_COMMANDS, true, false,
				array(self::PLUGIN_MARKER, 0, 0,
					ruTrackerChecker::encodeInheritance($stranger, true, true, 1000)));

			strictAssertSame(ruTrackerChecker::STE_ERROR,
				ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
				'the replacement stands down rather than stepping on another transaction');
			strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')),
				'the other transaction keeps its only recovery marker');
			strictAssertSame(null, rTorrent::$lastSend, 'and nothing is staged on top of it');
			strictAssertTrue(strpos(implode("\n", FileUtil::$log), 'same-hash-collision') !== false
				&& strpos(implode("\n", FileUtil::$log), 'kind=different-predecessor') !== false,
				'the foreign transaction is classified without touching its recovery keys');
		}
		finally
		{
			if($savedDebug === null) unset($GLOBALS['rutrackerCheckDebug']);
			else $GLOBALS['rutrackerCheckDebug'] = $savedDebug;
		}
	}

	public function testCorePendingLoadCanBeConfirmedByCheckersOwnMarker()
	{
		$this->resetFakes();
		$this->stageHappyReplacement(sys_get_temp_dir());
		rTorrent::$sendResult = null;
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		strictAssertSame(null,
			ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
			'the plugin-owned staged marker confirms a core-pending load');
		strictAssertSame(1, count($this->branchRequestsContaining('$d.erase=')),
			'the confirmed staged replacement reaches predecessor commit');
	}

	public function testStartedReplacementSucceeds()
	{
		$this->resetFakes();
		$this->stageHappyReplacement(sys_get_temp_dir());
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

		strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH), 'a started replacement should succeed');
		strictAssertSame(false, rTorrent::$lastSend['isStart'], 'the replacement must be staged stopped');
		strictAssertSame(sys_get_temp_dir(), rTorrent::$lastSend['directory'], 'the staged copy must reuse the old base directory');
		strictAssertSame('label', rTorrent::$lastSend['label'], 'the staged copy must reuse the old label');
		$addition = rTorrent::$lastSend['addition'];
		strictAssertTrue(strpos($addition[0], 'd.set_custom=chk-replacement,') === 0, 'the ownership marker must be the first load command');
		strictAssertTrue(in_array('d.set_connection_seed=seed-value', $addition, true), 'the connection seed must be forwarded');
		strictAssertTrue(in_array('d.set_throttle_name=slow', $addition, true), 'the throttle must be forwarded');
		strictAssertSame(
			array('view.set_visible=rat_2', 'view.set_visible=rat_7', 'view.set_visible=rat_9'),
			$this->membershipCommands($addition),
			'exactly the rat_N view memberships must be forwarded, all visible when all are confirmed'
		);
		$prefix = 'd.set_custom=chk-replaces,' . self::OLD_HASH . '-started-';
		strictAssertTrue(strpos($addition[1], $prefix) === 0,
			'the inheritance record must follow the marker, before any command that can abort the list');
		$stamp = substr($addition[1], strlen($prefix));
		strictAssertTrue(ctype_digit($stamp) && abs(intval($stamp) - time()) <= 5,
			'the record must carry the staging time, so a sweep can tell a crashed transaction from a running one');
		$value = substr($addition[1], strlen('d.set_custom=chk-replaces,'));
		strictAssertSame(1, preg_match('/^[A-Za-z0-9-]+$/', $value),
			'the record must be comma-free by construction');

		$commit = $this->branchRequestsContaining('$d.erase=');
		strictAssertSame(1, count($commit), 'the old hash is erased in exactly one ownership branch');
		strictAssertSame(self::OLD_HASH, $commit[0]['commands'][0]->params[0],
			'the commit branch targets the predecessor');
		$activation = $this->branchRequestsContaining('$d.start=');
		strictAssertSame(1, count($activation), 'the replacement is started in one ownership branch');
		$activationDsl = implode('|', $activation[0]['commands'][0]->params);
		strictAssertTrue(strpos($activationDsl, 'chk-replaces,') !== false
			&& strpos($activationDsl, 'chk-replacement,') !== false,
			'the same activation branch clears both transaction keys after success');
		strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')),
			'no ownership-sensitive erase is issued standalone');
	}

	// rTorrent runs a load command list inside ONE try block: the first
	// input_error aborts every command after it, plus rTorrent's own
	// d.state.set and event.download.inserted_new. view.set_visible throws
	// that error for a view that does not exist, and views are runtime state:
	// every rat_N is gone after a restart until ruTorrent's ratio plugin
	// recreates it. An unconfirmed membership must therefore ride along as
	// d.views.push_back_unique -- the attribute write never throws -- both to
	// keep the list abort-free and because an empty d.views would let the
	// ratio plugin's default-group insert hook re-home the replacement into
	// the default ratio group, whose action can be erase-data.
	public function testMissingRatioViewIsForwardedAsAttributeOnly()
	{
		$this->resetFakes();
		$this->withDebugLog(function() {
			$this->stageTorrents();
			rXMLRPCRequest::queue('d.hash', true, true, array());
			$this->queueViews(
				array('main', 'rat_2', 'rat_7', 'rat_9'),
				array('main', 'default', 'seeding', 'rat_2', 'rat_9')  // rat_7 did not survive the restart
			);
			$this->queueSnapshot(sys_get_temp_dir(), 1, 1);
			$this->queueLoadConfirmed();
			$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ERASED);
			$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

			strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
				'a missing view must cost nothing but the visible membership');
			strictAssertSame(
				array(
					'view.set_visible=rat_2',
					'd.views.push_back_unique=rat_7',
					'view.set_visible=rat_9',
				),
				$this->membershipCommands(rTorrent::$lastSend['addition']),
				'a confirmed membership stays visible; the missing one becomes the d.views attribute only'
			);
			$viewList = $this->requestIndexes('view_list');
			$snapshots = $this->requestIndexes(self::SNAPSHOT_KEY);
			strictAssertSame(1, count($viewList),
				'the live view list is read exactly once per replacement');
			strictAssertTrue(count($snapshots) === 1 && $viewList[0] < $snapshots[0],
				'the view list must be read while the old torrent still runs, before the stop/close');
			$line = strictAssertOneLogMatching(FileUtil::$log, 'rat_7',
				'an attribute-only membership is never silent');
			strictAssertEnglish($line, 'the attribute-only line');
		});
	}

	public function testUnreadableViewListForwardsEveryRatioViewAsAttributeOnly()
	{
		$this->resetFakes();
		$this->withDebugLog(function() {
			$this->stageTorrents();
			rXMLRPCRequest::queue('d.hash', true, true, array());
			$this->queueViews(array('main', 'rat_2', 'rat_9'), false); // nothing queued: view.list faults
			$this->queueSnapshot(sys_get_temp_dir(), 1, 1);
			$this->queueLoadConfirmed();
			$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ERASED);
			$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

			strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
				'an unreadable view list must not abort the replacement');
			strictAssertSame(
				array('d.views.push_back_unique=rat_2', 'd.views.push_back_unique=rat_9'),
				$this->membershipCommands(rTorrent::$lastSend['addition']),
				'every unconfirmed membership becomes the d.views attribute, none stays view.set_visible'
			);
			$line = strictAssertOneLogMatching(FileUtil::$log, 'rat_2,rat_9',
				'the attribute-only memberships are named');
			strictAssertEnglish($line, 'the unreadable-view-list line');
		});
	}

	// Most replacements belong to no ratio group at all: d.views comes back
	// with nothing matching rat_\d+, so there is no membership to confirm and
	// the view.list round trip would buy nothing. createTorrent() skips it --
	// a lookup whose answer nothing uses is a round trip wasted between the
	// tracker check and the load.
	public function testNoRatioViewsSkipsTheViewListLookup()
	{
		$this->resetFakes();
		$this->stageTorrents();
		rXMLRPCRequest::queue('d.hash', true, true, array());
		// rat_bad and rat_2_extra do not match rat_\d+. view_list is NOT
		// queued, so asking for it anyway would surface as a fault.
		$this->queueViews(array('main', 'rat_bad', 'rat_2_extra'), false);
		$this->queueSnapshot(sys_get_temp_dir(), 1, 1);
		$this->queueLoadConfirmed();
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ERASED);
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

		strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
			'a replacement without ratio groups must succeed without a view list read');
		$keys = array_map(function($request) { return $request['key']; }, rXMLRPCRequest::$requests);
		strictAssertTrue(!in_array('view_list', $keys, true),
			'no ratio views were collected, so view.list must never be asked for: saw ' . implode(',', $keys));
		strictAssertSame(array(), $this->membershipCommands(rTorrent::$lastSend['addition']),
			'and no membership command can be emitted either');
	}

	// The version rides along with the cycle counts so a log can be read
	// against the rTorrent that produced it. rTorrentSettings::get() answers
	// from the cached rtorrent.dat -- only a browser-driven get(true) ever
	// refreshes it -- so after an upgrade and a restart it can report the old
	// version for days, which is the one answer this diagnostic must not give.
	public function testVersionLabelAsksTheDaemonAndSurvivesAFailedQuery()
	{
		$this->resetFakes();
		$this->withDebugLog(function() {
			rXMLRPCRequest::queue('system.client_version|system.api_version', true, false, array('0.16.20', '11'));
			strictAssertSame('client=0.16.20 api=11', ruTrackerChecker::liveVersionLabel(),
				'the live daemon answers, not the cached settings singleton');
			$asked = rXMLRPCRequest::requestsFor('system.client_version|system.api_version');
			strictAssertSame(1, count($asked), 'one request per logged cycle');
			strictAssertSame(false, $asked[0]['important'],
				'a diagnostic may never sink the cycle it is describing');

			// A failed query must still leave a readable line.
			rXMLRPCRequest::reset();
			strictAssertSame('client=? api=?', ruTrackerChecker::liveVersionLabel(),
				'a failed version query degrades to a placeholder');
		});

		// And with the debug log off nothing is asked at all.
		rXMLRPCRequest::reset();
		strictAssertSame('client=? api=?', ruTrackerChecker::liveVersionLabel(),
			'no version is claimed when no line will be written');
		strictAssertSame(0, count(rXMLRPCRequest::$requests),
			'the cost of the diagnostic stays behind the debug flag');
	}

	public function testStoppedOpenReplacementUsesOpen()
	{
		$this->resetFakes();
		$this->stageHappyReplacement(sys_get_temp_dir(), 0, 1);
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

		strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH), 'a stopped-but-open replacement should succeed');
		strictAssertSame(1, count($this->branchRequestsContaining('$d.open=')),
			'a stopped-but-open torrent is reopened in one ownership branch');
		strictAssertSame(0, count($this->branchRequestsContaining('$d.start=')),
			'a stopped torrent is never started');
		strictAssertTrue(strpos(rTorrent::$lastSend['addition'][1],
			'd.set_custom=chk-replaces,' . self::OLD_HASH . '-open-') === 0,
			'a paused predecessor must be recorded as open, never as started');
	}

	public function testFullyStoppedReplacementSkipsActivation()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-stopped-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'old');
		$this->stageHappyReplacement($base, 0, 0, array('name' => 'old.mkv'), array('name' => 'new.mkv'));
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_CLEARED);

		try
		{
			strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH), 'a fully stopped replacement should still commit');
			$this->assertNoRequestKeyContains('d.start', 'a fully stopped torrent must not be started');
			$this->assertNoRequestKeyContains('d.open', 'a fully stopped torrent must not be opened');
			$prepare = $this->erasedataCalls('erasedataPrepareObsoleteCleanup');
			strictAssertSame($base . '/old.mkv', $prepare[0]['arguments'][5][0]['path'],
				'a stopped replacement prepares the exact obsolete path');
			strictAssertSame(1, count($this->erasedataCalls('erasedataPublishObsoleteCleanup')),
				'the stopped replacement publishes its cleanup obligation');
			strictAssertSame(1, count($this->erasedataCalls('erasedataKickCollector')),
				'the stopped replacement kicks its published cleanup');
			$clears = $this->branchRequestsContaining('chk-replacement');
			strictAssertTrue(count($clears) >= 1,
				'a deliberately unstarted replacement closes both keys in an ownership branch');
			strictAssertTrue(strpos(rTorrent::$lastSend['addition'][1],
				'd.set_custom=chk-replaces,' . self::OLD_HASH . '-stopped-') === 0,
				'a stopped predecessor must be recorded as stopped');
		}
		finally
		{
			strictRemoveTree($base);
		}
	}

	public function testStartedReplacementMayRemainClosedWhileWaitingForSchedulerSlot()
	{
		$this->resetFakes();
		$this->stageHappyReplacement(sys_get_temp_dir(), 1, 1);
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

		strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH), 'a started-but-closed replacement is an activation success');
		$activation = $this->branchRequestsContaining('$d.start=');
		strictAssertSame(1, count($activation), 'a scheduler-queued start is one atomic attempt');
		strictAssertTrue(strpos(implode('|', $activation[0]['commands'][0]->params),
			'branch=d.get_state=') !== false,
			'the started policy verifies state, so a scheduler-delayed open is not retried');
	}

	public function testPreExistingForeignHashLeavesOldTorrentUntouched()
	{
		$this->resetFakes();
		Torrent::$fixtures['new-torrent'] = array('hash' => self::NEW_HASH, 'info' => array('name' => 'new.mkv'));
		rXMLRPCRequest::queue('d.hash', true, false, array(self::NEW_HASH));
		rXMLRPCRequest::queue(self::PREFLIGHT_KEY_COMMANDS, true, false, array('', 0, 0, ''));

		strictAssertSame(
			ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
			'an unmarked target hash is a retryable conflict, not proof of supersession'
		);
		strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
			'no false superseded token is written to the predecessor');
		$probes = rXMLRPCRequest::requestsFor('d.hash');
		strictAssertSame(1, count($probes), 'the preflight must issue exactly one hash probe');
		strictAssertSame(self::NEW_HASH, $probes[0]['commands'][0]->params, 'the preflight probe must target the new hash, not the old one');
		$markerReads = rXMLRPCRequest::requestsFor(self::PREFLIGHT_KEY);
		strictAssertSame(1, count($markerReads), 'the pre-existing hash must be inspected exactly once');
		strictAssertSame(array(self::NEW_HASH, 'chk-replacement'), $markerReads[0]['commands'][0]->params, 'the marker read must target the new hash');
		$this->assertNoRequestKeyContains('d.stop', 'a preflight conflict must not stop anything');
		$this->assertNoRequestKeyContains('d.erase', 'a foreign target hash must never be erased');
		strictAssertSame(null, rTorrent::$lastSend, 'a preflight conflict must not enqueue a load');
	}

	public function testRunningUnmarkedSameHashOccupantIsNeverAdopted()
	{
		$this->resetFakes();
		Torrent::$fixtures['new-torrent'] = array('hash' => self::NEW_HASH,
			'info' => array('name' => 'new.mkv'));
		rXMLRPCRequest::queue('d.hash', true, false, array(self::NEW_HASH));
		rXMLRPCRequest::queue(self::PREFLIGHT_KEY_COMMANDS, true, false, array('', 1, 1, ''));
		strictAssertSame(ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
			"even a running occupant is not proved to be this predecessor's successor");
		strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
			'no terminal successor token is written');
		$this->assertNoRequestKeyContains('d.erase', 'the running occupant is retained');
		$this->assertNoRequestKeyContains('d.stop', 'the predecessor is untouched');
		strictAssertSame(null, rTorrent::$lastSend, 'no replacement is loaded');
	}

	public function testH11ForeignHashCollisionKeepsTerminalVerdictAndLogsReason()
	{
		$past = time() - 700000;
		$this->withVerdictSession('h11-conflict', ruTrackerChecker::STE_DELETED, $past,
			function($url, $hash) {
				return ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), $hash);
			},
			function() use ($past) {
				Torrent::$fixtures['new-torrent'] = array('hash' => self::NEW_HASH,
					'info' => array('name' => 'new.mkv'));
				rXMLRPCRequest::queue('d.set_custom', true, false, array()); // terminal preflight
				rXMLRPCRequest::queue('d.hash', true, false, array(self::NEW_HASH));
				rXMLRPCRequest::queue(self::PREFLIGHT_KEY_COMMANDS, true, false, array('', 0, 0, ''));
				$performed = null;
				$this->withoutDebugLog(function() use (&$performed, $past) {
					strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH,
						ruTrackerChecker::STE_DELETED, $past, '', $performed),
						'a collision is a retryable handler result');
				});
				$log = implode("\n", FileUtil::$log);
				strictAssertSame(false, $performed, 'a collision consumed no handler correction');
				strictAssertSame(array((string) ruTrackerChecker::STE_DELETED),
					$this->customWritesFor('chk-state'), 'a truthful terminal verdict remains');
				strictAssertSame(array(), $this->customWritesFor('chk-time'),
					'the original terminal clock is not refreshed');
				strictAssertSame(array(), $this->customWritesFor('chk-msg'),
					'the old terminal explanation is retained');
				strictAssertTrue(strpos($log, 'same-hash-collision') !== false
					&& strpos($log, self::OLD_HASH) !== false
					&& strpos($log, self::NEW_HASH) !== false,
					'the classified collision is visible with debug disabled');
				$this->assertNoRequestKeyContains('d.erase', 'neither torrent is erased');
			});
	}

	public function testPreExistingForeignHashRemainsRetryableOnNextAttempt()
	{
		$this->resetFakes();
		Torrent::$fixtures['new-torrent'] = array('hash' => self::NEW_HASH, 'info' => array('name' => 'new.mkv'));
		for($attempt = 0; $attempt < 2; $attempt++)
		{
			rXMLRPCRequest::queue('d.hash', true, false, array(self::NEW_HASH));
			rXMLRPCRequest::queue(self::PREFLIGHT_KEY_COMMANDS, true, false, array('', 0, 0, ''));
			strictAssertSame(ruTrackerChecker::STE_ERROR,
				ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
				'unmarked occupancy is still retryable on attempt ' . ($attempt + 1));
		}
		strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
			'neither attempt persists an unproven successor token');
	}

	public function testOnlyAPluginNonceAndTrustworthyRecordAuthorizeExistingTargetRecovery()
	{
		$oldHash = str_repeat('A', 40);
		$validRecord = $oldHash . '-started-1786899620';
		$foreignRecord = str_repeat('B', 40) . '-started-1786899620';
		$validMarker = '0123456789abcdef0123456789abcdef';
		foreach(array(
			'arbitrary marker with no record' => array('marker' => 'not-a-plugin-marker', 'state' => 0, 'open' => 0, 'record' => ''),
			'arbitrary marker with malformed record' => array('marker' => 'not-a-plugin-marker', 'state' => 1, 'open' => 1, 'record' => 'garbage'),
			'arbitrary marker with otherwise valid record' => array('marker' => 'not-a-plugin-marker', 'state' => 0, 'open' => 0, 'record' => $validRecord),
			'plugin marker with no record on a stopped target' => array('marker' => $validMarker, 'state' => 0, 'open' => 0, 'record' => ''),
			'plugin marker with no record on a live target' => array('marker' => $validMarker, 'state' => 1, 'open' => 1, 'record' => ''),
			'plugin marker with malformed record' => array('marker' => $validMarker, 'state' => 0, 'open' => 0, 'record' => 'garbage'),
			'plugin marker with a different predecessor' => array('marker' => $validMarker, 'state' => 0, 'open' => 0,
				'record' => $foreignRecord),
			'plugin marker with unknown run token' => array('marker' => $validMarker, 'state' => 0, 'open' => 0,
				'record' => $oldHash . '-mystery-1786899620'),
		) as $label => $case)
		{
			$this->resetFakes();
			$this->stageTorrents();
			rXMLRPCRequest::queue('d.hash', true, false, array(self::NEW_HASH));
			rXMLRPCRequest::queue(self::PREFLIGHT_KEY_COMMANDS, true, false,
				array($case['marker'], $case['state'], $case['open'], $case['record']));
			// Make every formerly-authorized destructive branch executable. The
			// assertions below must be what stops it, not a missing fake reply.
			rXMLRPCRequest::queue('d.erase', true, false, array(0));
			rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array(0, 0));

			strictAssertSame(ruTrackerChecker::STE_ERROR,
				ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), $oldHash),
				$label . ': ownership is refused retryably');
			strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')),
				$label . ': the existing target is never erased');
			strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
				$label . ': foreign or malformed recovery keys are never cleared');
			strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
				$label . ': no run-state command is issued');
			strictAssertSame(null, rTorrent::$lastSend, $label . ': no replacement is staged on top');
		}
	}

	public function testLiveTorrentWithStaleMarkerIsNotAdopted()
	{
		$this->resetFakes();
		$oldHash = str_repeat('A', 40);
		Torrent::$fixtures['new-torrent'] = array('hash' => self::NEW_HASH, 'info' => array('name' => 'new.mkv'));
		rXMLRPCRequest::queue('d.hash', true, false, array(self::NEW_HASH));
		// A committed replacement whose final marker clear was lost: running,
		// with both halves of the ownership proof still naming this predecessor.
		rXMLRPCRequest::queue(self::PREFLIGHT_KEY_COMMANDS, true, false,
			array(self::PLUGIN_MARKER, 1, 1, $oldHash . '-started-1786899620'));
		rXMLRPCRequest::queue('d.hash', true, true, array());
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_CLEARED);

		strictAssertSame(
			ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), $oldHash),
			'a live torrent with a leftover marker must not be adopted'
		);
		$this->assertNoRequestKeyContains('d.erase', 'a live marked torrent must never be erased');
		$clears = $this->branchRequestsContaining('chk-replacement');
		strictAssertSame(1, count($clears), 'the stale transaction must be repaired atomically');
		strictAssertTrue(strpos(implode('|', $clears[0]['commands'][0]->params), 'chk-replaces') !== false,
			'the same repair branch clears marker and record together');
		strictAssertSame(null, rTorrent::$lastSend, 'no load may be enqueued');
	}

	// The pause button produces exactly this: state 0, open 1. Deleting the
	// is_open half of the guard would erase a torrent the user merely paused.
	public function testPausedTorrentWithStaleMarkerIsNotAdoptedEither()
	{
		$this->resetFakes();
		$oldHash = str_repeat('A', 40);
		Torrent::$fixtures['new-torrent'] = array('hash' => self::NEW_HASH, 'info' => array('name' => 'new.mkv'));
		rXMLRPCRequest::queue('d.hash', true, false, array(self::NEW_HASH));
		rXMLRPCRequest::queue(self::PREFLIGHT_KEY_COMMANDS, true, false,
			array(self::PLUGIN_MARKER, 0, 1, $oldHash . '-open-1786899620'));
		rXMLRPCRequest::queue('d.hash', true, true, array());
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_CLEARED);

		strictAssertSame(
			ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), $oldHash),
			'an open marked torrent must not be adopted, started or not'
		);
		$this->assertNoRequestKeyContains('d.erase', 'a paused marked torrent must never be erased');
		$clears = $this->branchRequestsContaining('chk-replacement');
		strictAssertSame(1, count($clears), 'the stale transaction is repaired atomically');
		strictAssertSame(null, rTorrent::$lastSend, 'no load may be enqueued');
	}

	public function testOrphanedStagedCopyIsAdoptedAndReplaced()
	{
		$this->resetFakes();
		$oldHash = str_repeat('A', 40);
		$this->stageTorrents();
		rTorrent::$source = new Torrent(array('hash' => $oldHash,
			'info' => array('name' => 'unchanged.mkv', 'length' => 1)));
		rXMLRPCRequest::queue('d.hash', true, false, array(self::NEW_HASH));
		rXMLRPCRequest::queue(self::PREFLIGHT_KEY_COMMANDS, true, false,
			array(self::PLUGIN_MARKER, 0, 0, $oldHash . '-started-1786899620'));
		rXMLRPCRequest::queue('d.hash', true, false, array($oldHash));
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ERASED);
		$this->queueViews();
		$this->queueSnapshot(sys_get_temp_dir(), 1, 1);
		$this->queueLoadConfirmed();
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ERASED);
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

		strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), $oldHash),
			'an orphaned marked staged copy must be discarded and replaced');
		$erases = $this->branchRequestsContaining('$d.erase=');
		strictAssertSame(2, count($erases), 'the orphan and predecessor are each erased once atomically');
		strictAssertSame(self::NEW_HASH, $erases[0]['commands'][0]->params[0],
			'the orphaned staged copy is erased first');
		strictAssertSame($oldHash, $erases[1]['commands'][0]->params[0],
			'the predecessor is erased at commit');
	}

	// The dead run's own stop/close is what left the predecessor stopped and
	// closed, so on the redo the live snapshot reports the crash, not the
	// user. The staged copy's record is the one truthful account -- encoding
	// the measured (0,0) forward instead would hand the replacement a stopped
	// state nobody chose, which is precisely the strand this transaction's
	// record exists to prevent.
	public function testAdoptionInheritsTheRecordedRunStateOverTheDeadRunsOwnStop()
	{
		$this->resetFakes();
		$this->withDebugLog(function() {
			$oldHash = str_repeat('A', 40);
			$this->stageTorrents();
			rTorrent::$source = new Torrent(array('hash' => $oldHash,
				'info' => array('name' => 'unchanged.mkv', 'length' => 1)));
			rXMLRPCRequest::queue('d.hash', true, false, array(self::NEW_HASH));
			// The staged copy carries the dead run's record: staged while STARTED.
			rXMLRPCRequest::queue(self::PREFLIGHT_KEY_COMMANDS, true, false,
				array(self::PLUGIN_MARKER, 0, 0, $oldHash . '-started-1786899620'));
			rXMLRPCRequest::queue('d.hash', true, false, array($oldHash));
			$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ERASED);
			$this->queueViews();
			$this->queueSnapshot(sys_get_temp_dir(), 0, 0);	// the predecessor measures stopped+closed
			$this->queueLoadConfirmed();
			$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ERASED);
			$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

			strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), $oldHash),
				'the redo of a crashed transaction must succeed');
			strictAssertSame(1, count($this->branchRequestsContaining('$d.start=')),
				'the replacement is started, as the record says -- not left in the measured stop');
			$addition = implode(' ', rTorrent::$lastSend['addition']);
			strictAssertTrue(strpos($addition, '-started-') !== false,
				'the re-staged record carries the recorded state forward: ' . $addition);
			strictAssertTrue(strpos(implode("\n", FileUtil::$log), 'inheriting the recorded state') !== false,
				'the override is logged with its reason');

			// The recovery marker on the PREDECESSOR, which no test asserted
			// until now. It is the only account of the run state that survives
			// a crash between here and the erase, and the sweep reads it to
			// decide whether to put the torrent back: a marker saying "stopped"
			// makes the sweep conclude the user stopped it on purpose, clear
			// the key and restore nothing -- a seeding torrent left stopped for
			// good, with the evidence deleted by the recovery code itself.
				$stops = $this->branchRequestsContaining('$d.stop=');
				strictAssertSame(1, count($stops), 'the predecessor is marked and stopped exactly once');
				$command = $stops[0]['commands'][0];
				strictAssertSame($oldHash, $command->params[0],
					'the daemon-side branch targets the predecessor');
				strictAssertTrue(strpos($command->params[3], 'chk-replacing,' . self::NEW_HASH . '-started-') !== false,
					'the stopped branch must carry the RECORDED run state, not the dead run\'s own stop');
		});
	}

	// A faulting member of the snapshot multicall contributes BOTH faultCode
	// and faultString to the flat value list, so every index after it shifts.
	// Restoring the run state from val[4]/val[5] there meant acting on
	// whatever happened to land in those slots -- up to and including
	// starting a torrent the user had deliberately stopped.
	public function testFaultedSnapshotRestoresNothingRatherThanGuessing()
	{
		$this->resetFakes();
		$this->stageTorrents();
		rXMLRPCRequest::queue('d.hash', true, true, array());
		$this->queueViews();
		// The snapshot faults: the answer carries a fault pair, not the state.
		rXMLRPCRequest::queue(self::SNAPSHOT_KEY_COMMANDS, false, true,
			array('/data', 'label', '', '', '-501', 'Method not found', '', ''));

		strictAssertSame(ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH), 'a faulted snapshot aborts');
		$this->assertNoRequestKeyContains('d.start', 'nothing is started from shifted values');
		$this->assertNoRequestKeyContains('d.open', 'and nothing is opened either');
		strictAssertSame(null, rTorrent::$lastSend, 'no load is enqueued');
	}

	// Erasing the staged copy also destroys the marker and record the sweep
	// scans for. Doing that before the predecessor is known to be back left a
	// stopped, closed torrent nothing in the plugin could find again.
	public function testFailedRestoreKeepsTheStagedCopyForTheSweep()
	{
		$this->resetFakes();
		$this->withDebugLog(function() {
			// Both ways an atomic restore can fail: an unknown transport outcome,
			// or a command that ran but whose postcondition was not reached.
			$modes = array(
				'the atomic outcome is unknown' => RuTrackerAtomicOwnership::UNKNOWN,
				'the commands ran but the torrent stays put' => RuTrackerAtomicOwnership::UNCONFIRMED,
			);
			foreach($modes as $label => $status)
			{
				$this->resetFakes();
				FileUtil::$log = array();
				$this->stageTorrents();
				// The load lands and IS confirmed as ours, but under a different
				// hash than the metainfo says -- the abort path with $owner ===
				// 'ours', i.e. the one that used to erase the staged copy.
				rTorrent::$sendResult = 'OTHER';
				$this->queueTransactionStart(sys_get_temp_dir(), 1, 1);
				$this->queueLoadConfirmed();
				if($status === RuTrackerAtomicOwnership::UNKNOWN)
					$this->queueAtomic('', false, false);
				else
					$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_UNCONFIRMED);

				strictAssertSame(ruTrackerChecker::STE_ERROR,
					ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH), $label . ': the replacement aborts');
				strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')),
					$label . ': the staged copy is KEPT -- it carries the only marker the sweep can find');
				strictAssertTrue(strpos(implode("\n", FileUtil::$log), 'so the sweep can finish') !== false,
					$label . ': and the reason is logged');
			}
		});
	}

	// The two rollback cases below exhaust waitForLoad's budget on purpose:
	// LOAD_WAIT_ATTEMPTS polls, LOAD_WAIT_DELAY_US apart. What they prove is
	// the count of polls and what happens after the last one; the delay
	// between polls is production's courtesy to rTorrent, not theirs, so the
	// suite declares it short. This case pins that the test declaration
	// reached the wait: at the shipped 50 ms the exhaustion
	// alone costs two seconds.
	public function testAnExhaustedLoadWaitCostsTheDeclaredDelayNotTheShippedOne()
	{
		$this->resetFakes();
		$this->stageTorrents();
		$this->queueTransactionStart(sys_get_temp_dir(), 1, 1);
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		$began = hrtime(true);
		ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH);
		$took = (hrtime(true) - $began) / 1e9;
		strictAssertSame(
			ruTrackerChecker::LOAD_WAIT_ATTEMPTS,
			count(rXMLRPCRequest::requestsFor('d.get_custom')),
			'the budget is still exhausted poll by poll'
		);
		strictAssertTrue($took < 0.5,
			'and the exhaustion costs the declared delay, not 39 pauses of 50 ms (' . round($took, 2) . 's)');
	}

	public function testRollbackRestoresOldTorrentEvenWhenStagedStatusUnknown()
	{
		$this->resetFakes();
		$this->stageTorrents();
		$this->queueTransactionStart(sys_get_temp_dir(), 1, 1);
		// Nothing queued for d.get_custom: every waitForLoad poll fails.
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

		strictAssertSame(
			ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
			'an unconfirmed staged copy must abort the replacement'
		);
		strictAssertSame(
			ruTrackerChecker::LOAD_WAIT_ATTEMPTS,
			count(rXMLRPCRequest::requestsFor('d.get_custom')),
			'the staged copy must be polled until the wait budget is exhausted'
		);
		strictAssertSame(1, count($this->branchRequestsContaining('$d.start=')),
			'the predecessor is restored atomically even when staged status is unknown');
		strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')), 'a hash of unknown ownership must not be erased blindly');
	}

	public function testFailedStagingLogsClassifiedOwnerLoadAndRestoreAtDefaultDebug()
	{
		$this->withoutDebugLog(function () {
			$this->resetFakes();
			$this->stageTorrents();
			rTorrent::$sendResult = false;
			$this->queueTransactionStart(sys_get_temp_dir(), 1, 1);
			// sendTorrent() returns false, and the marker never appears.
			$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
			strictAssertSame(ruTrackerChecker::STE_ERROR,
				ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
				'the replacement is refused');
			$log = implode("\n", FileUtil::$log);
			strictAssertTrue(strpos($log,
				'load=dispatch-failed owner=missing restore=confirmed') !== false,
				'the ungated log names the three facts that decide the refusal');
		});
	}

	public function testCommitEraseWithUnknownOldStateLeavesStagedCopy()
	{
		$this->resetFakes();
		$this->stageTorrents();
		$this->queueTransactionStart(sys_get_temp_dir(), 1, 1);
		$this->queueLoadConfirmed();
		$this->queueAtomic('', false, true);
		// Nothing queued for the follow-up d.hash probe: the old torrent's fate
		// is unknowable, so the marked staged copy must be left for adoption.

		strictAssertSame(
			ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
			'an unknowable commit outcome must abort the replacement'
		);
		$erases = $this->branchRequestsContaining('$d.erase=');
		strictAssertSame(1, count($erases), 'only the predecessor ownership branch may attempt an erase');
		strictAssertSame(self::OLD_HASH, $erases[0]['commands'][0]->params[0],
			'the staged copy is never erased while the predecessor fate is unknown');
		// By substring, not by exact pipeline key: the only restore path builds
		// 'd.open' or 'd.open|d.start', so neither bare key could ever match
		// and both assertions were true whatever the code did.
		$this->assertNoRequestKeyContains('d.start', 'nothing may be restarted while both fates are unknown');
		$this->assertNoRequestKeyContains('d.open', 'nothing may be reopened while both fates are unknown');
	}

	// The production makeClient(), not the suite double. A mutation check
	// proved this was needed: reverting the body to an unconditional
	// `$client->agent = self::USER_AGENT;` left the ENTIRE php suite green,
	// because every existing agent assertion runs against TestLib's own copy
	// of makeClient and this is the only suite that loads the real one. That
	// one line is what revived layer 2 -- Cloudflare answers 403 to browser
	// agents on the announce hosts, which is how the layer stayed silently
	// dead for at least six days of log -- so it must not be revertible in
	// silence.
	public function testMakeClientSendsTheAgentItWasGivenAndOtherwiseTheBrowser()
	{
		$this->resetFakes();

		$client = ruTrackerChecker::makeClient('http://bt4.t-ru.org/ann', 'GET', '', '', 'probe-agent-token');
		strictAssertSame('probe-agent-token', $client->agent,
			'an explicit agent reaches the client that fetches');

		$client = ruTrackerChecker::makeClient('https://rutracker.org/forum/viewtopic.php?t=1');
		strictAssertSame(ruTrackerChecker::USER_AGENT, $client->agent,
			'and every caller that asks for nothing still gets the browser default');
		strictAssertTrue(strpos($client->agent, 'Mozilla') === 0,
			'which is still a browser agent, as the forum and the API need');
	}

	public function testCurlExitCodeStatusIsLoggedAsTransportFailure()
	{
		$this->resetFakes();
		$this->withDebugLog(function() {
			try
			{
				// The https path stores curl's exit code (6 = DNS failure) as status.
				Snoopy::$nextStatus = 6;
				ruTrackerChecker::makeClient('https://tracker.test/scrape');
				strictAssertSame(1, count(FileUtil::$log), 'a curl exit-code status must be logged as a failed fetch');
				strictAssertTrue(
					strpos(FileUtil::$log[0], 'Snoopy fetch failed: host=tracker.test transport=curl-exit code=6 reason=dns') !== false,
					'the transport-failure log line must carry the host and safe cURL category'
				);

				// Status 0 is NOT a curl exit code -- zero is curl's code for
				// success -- and it is not one condition either: Snoopy leaves
				// it for a response with no parseable status line, for a socket
				// failure whose errno is 0 (what PHP reports for a DNS
				// failure), and for a refusal to send at all. Sixteen lines of
				// a live log said "curl-exit code=0 reason=curl" and diagnosed
				// none of them. The line now states only what is certain and
				// carries a classified token for Snoopy's own sentence, which
				// is what tells them apart.
				FileUtil::$log = array();
				Snoopy::$nextStatus = 0;
				Snoopy::$nextError = 'connection failed (0)';
				ruTrackerChecker::makeClient('http://bt4.t-ru.org/ann');
				strictAssertSame(1, count(FileUtil::$log), 'an absent status must still be logged as a failed fetch');
				strictAssertTrue(
					strpos(FileUtil::$log[0], 'transport=no-status reason=unset') !== false,
					'an absent status must not be reported as a curl exit code: ' . FileUtil::$log[0]
				);
				strictAssertTrue(
					strpos(FileUtil::$log[0], ' error=connect-errno') !== false,
					'and must carry the classified token for the condition it was: ' . FileUtil::$log[0]
				);
				strictAssertTrue(
					strpos(FileUtil::$log[0], 'curl-exit') === false,
					'the misleading curl wording is gone: ' . FileUtil::$log[0]
				);

				// A passkey can never ride out in the error text, because no
				// remote text does: the field is one token from this plugin's
				// own vocabulary. Snoopy is a vendored file, so the sentence a
				// later merge teaches it to write cannot be known here --
				// whatever it says, an unrecognised message classifies rather
				// than prints. The probe URL spells the user's passkey, which
				// is why buildUrl() strips it for the same reason.
				FileUtil::$log = array();
				Snoopy::$nextError = 'Error fetching http://bt.t-ru.org/ann?pk=deadbeefcafe: refused';
				ruTrackerChecker::makeClient('http://bt4.t-ru.org/ann');
				strictAssertTrue(
					strpos(FileUtil::$log[0], 'deadbeefcafe') === false,
					'no passkey survives into the log: ' . FileUtil::$log[0]
				);
				strictAssertTrue(
					strpos(FileUtil::$log[0], ' error=unclassified') !== false,
					'and an unrecognised message is reported as unclassified, not quoted: '
						. FileUtil::$log[0]
				);

				// A transport that failed without a message logs the status
				// alone rather than an empty error="".
				FileUtil::$log = array();
				Snoopy::$nextError = '';
				ruTrackerChecker::makeClient('http://bt4.t-ru.org/ann');
				strictAssertSame(1, count(FileUtil::$log), 'still logged');
				strictAssertTrue(
					strpos(FileUtil::$log[0], 'error=') === false,
					'no empty error fragment is appended: ' . FileUtil::$log[0]
				);

				FileUtil::$log = array();
				Snoopy::$nextStatus = 200;
				ruTrackerChecker::makeClient('https://tracker.test/scrape');
				strictAssertSame(0, count(FileUtil::$log), 'a successful fetch must not be logged as a failure');
			}
			finally
			{
				Snoopy::$nextStatus = 200;
				Snoopy::$nextError = '';
			}
		});
	}

	// AGENTS.md's diagnostics rule: a routine plugin log carries a classified
	// reason, never third-party text. Snoopy's message was quoted verbatim
	// here, which cost two things at once. It was not greppable -- the same
	// Snoopy field is rendered as `error=<token>` by the NNMClub guest path,
	// so no single grep found every transport failure -- and quoting a
	// vendored file's string put the burden on a redaction that has to be
	// re-audited every time that file is merged.
	//
	// The rows are spelled out here rather than read out of
	// fetchErrorParityCases() because of the third column: each carries a
	// fragment of the remote message -- a scheme, a host, an IP, an errno, a
	// word of a sentence Snoopy does not write today -- that must NOT appear
	// in the line. That column is the only assertion in the suite for "no
	// fragment of the remote message reaches the log", as distinct from "the
	// error field holds the right token": strictAssertLogsClean(), the leak
	// guard the parity test below uses, checks plain ASCII plus one fixed
	// passkey literal and lets a leaked host or errno straight through.
	//
	// So the corpus rows with nothing to leak are not repeated here.
	// testSharedSnoopyCorpusClassifiesTheSameWayThroughMakeClient() below
	// drives the whole shared table through this same makeClient() -- the
	// whitespace shapes included -- and pins each token with the same regex.
	public function testSnoopyFetchErrorIsLoggedAsOneClassifiedToken()
	{
		$this->resetFakes();
		$this->withDebugLog(function() {
			try
			{
				Snoopy::$nextStatus = 0;
				foreach(array(
					array('Invalid protocol "gopher"\n', 'invalid-protocol', 'gopher'),
					array('Refusing to fetch: cannot resolve host "nx.invalid".',
						'refused-unresolvable-host', 'nx.invalid'),
					array('Refusing to fetch: host "a.invalid" resolves to the non-public address 127.0.0.1.',
						'refused-non-public-address', '127.0.0.1'),
					array('connection failed (111)', 'connect-errno', '111'),
					array('something php/Snoopy.class.inc does not say today', 'unclassified', 'something'),
				) as $case)
				{
					list($raw, $token, $absent) = $case;
					FileUtil::$log = array();
					Snoopy::$nextError = $raw;
					ruTrackerChecker::makeClient('http://bt4.t-ru.org/ann');
					strictAssertSame(1, count(FileUtil::$log), $token . ': the failed fetch is logged');
					$line = FileUtil::$log[0];
					$field = array();
					strictAssertSame(1, preg_match('/ error=([^\s]*)$/', $line, $field),
						$token . ': the field is one unquoted value at the end of one record: ' . $line);
					strictAssertSame($token, $field[1],
						$token . ': the message is classified, not echoed: ' . $line);
					strictAssertTrue(strpos($line, $absent) === false,
						$token . ': no fragment of the remote message reaches the log: ' . $line);
				}
			}
			finally
			{
				Snoopy::$nextStatus = 200;
				Snoopy::$nextError = '';
			}
		});
	}

	// S1. The other half of the parity gate. The same corpus of Snoopy
	// sentences is driven through NNMClubCheckImpl::guestFetch() by
	// NNMClubHandlerTest; here it goes through makeClient(). The two callers
	// each used to normalise the message themselves, and differently, so a
	// re-spaced or wrapped sentence meant two different tokens depending on
	// which path happened to log it. One table asserted from both sides is
	// what makes that a test rather than a convention.
	public function testResponseBodyFailureHasAClassifiedReasonDespiteHttp200()
	{
		$this->resetFakes();
		$this->withDebugLog(function() {
			try
			{
				Snoopy::$nextStatus = Snoopy::RESPONSE_BODY_FAILED;
				Snoopy::$nextError = 'invalid-or-oversized-gzip';
				$client = ruTrackerChecker::makeClient('https://bt4.t-ru.org/ann');
				strictAssertSame(Snoopy::RESPONSE_BODY_FAILED, $client->status,
					'failed body cannot retain HTTP 200 as its verdict');
				strictAssertSame(1, count(FileUtil::$log), 'body failure is logged once');
				strictAssertTrue(strpos(FileUtil::$log[0],
					'transport=response-body status=-101 reason=invalid error=invalid-or-oversized-gzip') !== false,
					'the log gives the classified body reason, not remote bytes');
			}
			finally
			{
				Snoopy::$nextStatus = 200;
				Snoopy::$nextError = '';
			}
		});
	}

	public function testSharedSnoopyCorpusClassifiesTheSameWayThroughMakeClient()
	{
		$this->resetFakes();
		$this->withDebugLog(function() {
			try
			{
				Snoopy::$nextStatus = 0;
				foreach(fetchErrorParityCases() as $case)
				{
					list($message, $expected) = $case;
					FileUtil::$log = array();
					Snoopy::$nextError = $message;
					ruTrackerChecker::makeClient('http://bt4.t-ru.org/ann');
					strictAssertSame(1, count(FileUtil::$log),
						'the failed fetch is logged once for ' . var_export($message, true));
					$line = FileUtil::$log[0];
					$field = array();
					strictAssertSame(1, preg_match('/ error=([^\s]*)$/', $line, $field),
						'the field is one unquoted value at the end of one record: ' . $line);
					strictAssertSame($expected, $field[1],
						'shared corpus token for ' . var_export($message, true) . ': ' . $line);
					strictAssertLogsClean(FileUtil::$log, 'AbCdEf0123456789AbCdEf0123456789',
						'makeClient');
				}
			}
			finally
			{
				Snoopy::$nextStatus = 200;
				Snoopy::$nextError = '';
			}
		});
	}

	public function testPluginDiagnosticsStayOffUnlessExplicitlyEnabled()
	{
		$this->resetFakes();
		$GLOBALS['rutrackerCheckDebug'] = false;
		try
		{
			ruTrackerChecker::logDebug('diagnostic marker');
			strictAssertSame(array(), FileUtil::$log,
				'diagnostics do not enter the shared application log by default');
			$GLOBALS['rutrackerCheckDebug'] = true;
			ruTrackerChecker::logDebug('diagnostic marker');
			strictAssertSame(1, count(FileUtil::$log),
				'an explicit opt-in writes to the configured shared sink');
		}
		finally
		{
			unset($GLOBALS['rutrackerCheckDebug']);
		}
	}

	public function testActivationEarlyReturnIsLogged()
	{
		$this->resetFakes();
		$this->withDebugLog(function() {
			strictAssertSame(
				true,
				strictInvoke('ruTrackerChecker', 'activateReplacement', array(self::NEW_HASH, false, false)),
				'a replacement whose predecessor was neither open nor started is still a success'
			);
			strictAssertSame(0, count(rXMLRPCRequest::$requests), 'the early return issues no command at all');
			$line = strictAssertOneLogMatching(FileUtil::$log, 'activateReplacement',
				'the branch that used to be silent now says it was taken');
			strictAssertEnglish($line, 'the skipped-activation line');
			strictAssertTrue(strpos($line, self::NEW_HASH) !== false, 'the line names the replacement: ' . $line);
			strictAssertTrue(strpos($line, 'neither open nor started') !== false,
				'the line says why activation was skipped: ' . $line);
		});
	}

	public function testCommitPointRunStateIsLogged()
	{
		$this->resetFakes();
		$this->withDebugLog(function() {
			$this->stageHappyReplacement(sys_get_temp_dir(), 0, 0);

			strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
				'a fully stopped replacement still commits');
				$line = strictAssertOneLogMatching(FileUtil::$log, 'daemon-selected run state at stop',
					'the commit-boundary input to activation is recorded');
			strictAssertEnglish($line, 'the commit-point run-state line');
			strictAssertTrue(strpos($line, 'started=0 open=0') !== false,
				'the exact pair of values the decision was made on: ' . $line);
			strictAssertTrue(strpos($line, self::OLD_HASH) !== false, 'the line names the old torrent: ' . $line);
			// And the pair really is what silenced activation.
			strictAssertSame(1, count(strictLogsMatching(FileUtil::$log, 'neither open nor started')),
				'the two lines together explain a stopped replacement without guesswork');
		});
	}

	public function testForeignMarkerAfterLoadIsNeverErased()
	{
		$this->resetFakes();
		$this->stageTorrents();
		$this->queueTransactionStart(sys_get_temp_dir(), 1, 1);
		$this->queueLoadConfirmed('another-workers-marker');
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

		strictAssertSame(
			ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
			'a staged hash owned by another worker must abort the replacement'
		);
		strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.get_custom')), 'a foreign marker must be recognised on the first poll');
		strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')), 'a foreign staged copy must never be erased');
		strictAssertSame(1, count($this->branchRequestsContaining('$d.start=')),
			'the predecessor is restored atomically after a foreign takeover');
	}

	public function testSynchronousLoadFailureRestoresOldTorrent()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-send-fail-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'keep');
		$this->stageTorrents(array('name' => 'old.mkv'), array('name' => 'new.mkv'));
		rTorrent::$sendResult = false;
		$this->queueTransactionStart($base, 1, 1);
		// Nothing queued for d.get_custom: the load never happened.
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

		try
		{
			strictAssertSame(
				ruTrackerChecker::STE_ERROR,
				ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
				'a synchronous load failure must abort the replacement'
			);
			strictAssertTrue(is_file($base . '/old.mkv'), 'a failed load must not clean up any files');
			strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')), 'no hash may be erased when enqueueing the new torrent fails');
			strictAssertSame(1, count($this->branchRequestsContaining('$d.start=')),
				'the predecessor is restored atomically after a failed load');
		}
		finally
		{
			strictRemoveTree($base);
		}
	}

	public function testEraseRaceStillCompletesReplacement()
	{
		$this->resetFakes();
		$this->stageTorrents();
		$this->queueTransactionStart(sys_get_temp_dir(), 1, 1);
		$this->queueLoadConfirmed();
		$this->queueAtomic('', false, true);
		rXMLRPCRequest::queue('d.hash', true, true, array());
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

		strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH), 'an already-gone old hash means the replacement is committed');
		$erases = $this->branchRequestsContaining('$d.erase=');
		strictAssertSame(1, count($erases), 'only the raced commit ownership branch may erase');
		strictAssertSame(self::OLD_HASH, $erases[0]['commands'][0]->params[0], 'the commit branch targets the old hash');
		$probes = rXMLRPCRequest::requestsFor('d.hash');
		strictAssertSame(2, count($probes), 'a failed commit erase needs exactly one follow-up probe');
		strictAssertSame(self::OLD_HASH, $probes[1]['commands'][0]->params, 'the post-erase probe must recheck the old hash');
	}

	public function testEraseFailureRestoresOldTorrentWithoutCleanup()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-erase-fail-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'keep');
		$this->stageTorrents(array('name' => 'old.mkv'), array('name' => 'new.mkv'));
		$this->queueTransactionStart($base, 1, 1);
		$this->queueLoadConfirmed();
		$this->queueAtomic('', false, true);
		rXMLRPCRequest::queue('d.hash', true, false, array(self::OLD_HASH));
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ERASED);

		try
		{
			strictAssertSame(
				ruTrackerChecker::STE_ERROR,
				ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
				'a failed commit erase with the old hash still present must roll back'
			);
			strictAssertTrue(is_file($base . '/old.mkv'), 'an aborted commit must not clean up any files');
			$erases = $this->branchRequestsContaining('$d.erase=');
			strictAssertSame(2, count($erases), 'rollback discards the staged copy only after confirmed restore');
			strictAssertSame(self::OLD_HASH, $erases[0]['commands'][0]->params[0], 'the commit branch targets the old hash');
			strictAssertSame(self::NEW_HASH, $erases[1]['commands'][0]->params[0], 'rollback branch targets the staged copy');
			strictAssertSame(1, count($this->branchRequestsContaining('$d.start=')),
				'the old started torrent returns atomically through the scheduler');
			// Named, not counted: a bare d.set_custom count catches whatever
			// else the transaction happens to write (it now clears the
			// predecessor's own recovery marker), so it proved nothing about
			// the staged copy either way.
			foreach(rXMLRPCRequest::requestsFor('d.set_custom') as $write)
				strictAssertTrue($write['commands'][0]->params[1] !== ruTrackerChecker::REPLACEMENT_MARKER_KEY,
					'the marker of a discarded staged copy needs no clearing');
		}
		finally
		{
			strictRemoveTree($base);
		}
	}

	// The other rollback site, and the one that used to be worse: after a failed
	// commit erase the staged copy was discarded BEFORE the predecessor was
	// even asked to come back, and the answer was thrown away. So an
	// unrestorable predecessor lost the only marker the sweep could have found
	// it by -- while the sibling site above already knew to keep it.
	public function testEraseFailureKeepsTheStagedCopyWhenTheOldTorrentStaysDown()
	{
		$this->resetFakes();
		$this->withDebugLog(function() {
		$base = sys_get_temp_dir() . '/rut-check-erase-down-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		try
		{
			$this->stageTorrents(array('name' => 'old.mkv'), array('name' => 'new.mkv'));
			$this->queueTransactionStart($base, 1, 1);
			$this->queueLoadConfirmed();
			$this->queueAtomic('', false, true);                            // the commit outcome is unknown
			rXMLRPCRequest::queue('d.hash', true, false, array(self::OLD_HASH));     // ...and the old torrent is still there
			$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_UNCONFIRMED);

			strictAssertSame(
				ruTrackerChecker::STE_ERROR,
				ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
				'a failed commit erase with an unrestorable predecessor must roll back'
			);
			$erases = $this->branchRequestsContaining('$d.erase=');
			strictAssertSame(1, count($erases),
				'only the uncertain commit branch may have run: the staged copy keeps the sweep marker');
			strictAssertSame(self::OLD_HASH, $erases[0]['commands'][0]->params[0], 'and it targeted the old hash');
			strictAssertTrue(strpos(implode("\n", FileUtil::$log), 'so the sweep can finish') !== false,
				'the reason the staged copy was kept is logged');
		}
		finally
		{
			strictRemoveTree($base);
		}
		});
	}

	public function testActivationFailureAfterCommitStillFinishes()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-activation-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'remove');
		$this->stageHappyReplacement($base, 1, 1, array('name' => 'old.mkv'), array('name' => 'new.mkv'));
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_UNCONFIRMED);

		try
		{
			strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH), 'activation trouble after commit must not fail the check');
			strictAssertSame(1, count($this->branchRequestsContaining('$d.start=')),
				'an unconfirmed activation is attempted exactly once and deferred');
			$prepare = $this->erasedataCalls('erasedataPrepareObsoleteCleanup');
			strictAssertSame($base . '/old.mkv', $prepare[0]['arguments'][5][0]['path'],
				'activation uncertainty keeps the exact published cleanup obligation');
			strictAssertSame(1, count($this->erasedataCalls('erasedataPublishObsoleteCleanup')),
				'cleanup publication survives activation uncertainty');
			strictAssertSame(1, count($this->erasedataCalls('erasedataKickCollector')),
				'the published cleanup is still kicked after activation uncertainty');
			strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
				'an unconfirmed activation must keep both keys: they are the next cycle\'s only handle on this row');
			strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.open|d.start')),
				'no standalone activation is attempted after the atomic postcondition fails');
		}
		finally
		{
			strictRemoveTree($base);
		}
	}

	public function testMissingOldMetainfoAbortsBeforeStoppingAnything()
	{
		$this->resetFakes();
		Torrent::$fixtures['new-torrent'] = array('hash' => self::NEW_HASH, 'info' => array('name' => 'new.mkv'));
		rXMLRPCRequest::queue('d.hash', true, true, array());

		strictAssertSame(
			ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
			'replacement needs the old metainfo for a safe post-commit recovery'
		);
		strictAssertSame(1, count(rXMLRPCRequest::$requests), 'missing old metainfo must abort right after the preflight probe');
		strictAssertSame('d.hash', rXMLRPCRequest::$requests[0]['key'], 'only the preflight probe may run without the old metainfo');
		strictAssertSame(null, rTorrent::$lastSend, 'missing old metainfo must not enqueue a replacement');
	}

	public function testCreateTorrentUsesOnlyAValidatedProvidedPredecessor()
	{
		$this->resetFakes();
		$this->stageHappyReplacement(sys_get_temp_dir());
		$provided = rTorrent::$source;
		rTorrent::$source = false;
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

		strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH, $provided),
			'a parsed predecessor with the expected hash removes the second source read');
		strictAssertSame(0, rTorrent::$sourceReads,
			'the validated caller-owned Torrent is used directly');

		// A caller cannot substitute a manifest from another info-hash: reject
		// that object and retain the legacy source lookup as the safe fallback.
		$this->resetFakes();
		$this->stageHappyReplacement(sys_get_temp_dir());
		$wrong = new Torrent(array('hash' => 'SOME-OTHER-HASH', 'info' => array('name' => 'wrong.mkv')));
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

		strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH, $wrong),
			'a mismatched optional object falls back to the daemon-owned predecessor');
		strictAssertSame(1, rTorrent::$sourceReads,
			'a hash mismatch is never trusted for post-replacement cleanup');
	}

	public function testNonRunSnapshotPrecedesOneDaemonSelectedMarkerAndStopCommand()
	{
		$this->resetFakes();
		$this->stageHappyReplacement(sys_get_temp_dir());
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

		strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH), 'the happy path should succeed');
		$snapshots = rXMLRPCRequest::requestsFor(self::SNAPSHOT_KEY);
		strictAssertSame(1, count($snapshots), 'exactly one non-run metadata snapshot request');
		$stops = $this->branchRequestsContaining('$d.stop=');
		strictAssertSame(1, count($stops), 'exactly one daemon-side marker/stop/close command');
		strictAssertSame(1, count($stops[0]['commands']), 'the boundary is not a top-level multicall');
		strictAssertSame('branch', $stops[0]['commands'][0]->command, 'the one command selects live state inside rTorrent');
		strictAssertSame(self::OLD_HASH, $stops[0]['commands'][0]->params[0], 'the branch target is OLD');
		$erases = $this->branchRequestsContaining('$d.erase=');
		strictAssertSame(1, count($erases), 'commit erases the predecessor through one conditional branch');
		$eraseParams = $erases[0]['commands'][0]->params;
		strictAssertSame(self::OLD_HASH, $eraseParams[0], 'the commit erase targets the predecessor');
		strictAssertTrue(strpos($eraseParams[1], 'equal=d.get_custom=chk-replacing,cat=') !== false,
			'the commit erase compares its replacing generation, not a foreign custom field');
		foreach($snapshots[0]['commands'] as $command)
			strictAssertTrue($command->command !== 'd.get_state' && $command->command !== 'd.is_open',
				'no stale PHP-side run-state snapshot may drive activation');

		$snapshotIndexes = $this->requestIndexes(self::SNAPSHOT_KEY);
		$stopIndexes = $this->requestIndexes(self::STOP_KEY);
		strictAssertTrue($snapshotIndexes[0] < $stopIndexes[0], 'non-run snapshot must precede the daemon commit command');
	}

	public function testReplacementStopBuilderUsesOneMarkerFirstDaemonBranch()
	{
		$command = strictInvoke('ruTrackerChecker', 'replacementStopCommand',
			array(self::OLD_HASH, self::NEW_HASH, 1700000000));
		$started = self::NEW_HASH . '-started-1700000000';
		$open = self::NEW_HASH . '-open-1700000000';
		$stopped = self::NEW_HASH . '-stopped-1700000000';
		strictAssertSame('branch', $command->command, 'the commit is one top-level branch command');
		strictAssertSame(array(
			self::OLD_HASH,
			'd.get_state=',
			'cat="$d.set_custom=chk-replacing,' . $started . '",$d.stop=,$d.close=,' . $started,
			'branch=d.is_open=,"cat=\"$d.set_custom=chk-replacing,' . $open
				. '\",$d.stop=,$d.close=,' . $open . '","cat=\"$d.set_custom=chk-replacing,'
				. $stopped . '\",$d.stop=,$d.close=,' . $stopped . '"',
		), $command->params,
			'the selected branch writes its exact marker before stop/close and returns that marker');
	}

	public function testReplacementStopRefusesAReaddedPredecessor()
	{
		$localId = str_repeat('1', 40);
		$command = strictInvoke('ruTrackerChecker', 'replacementStopCommand',
			array(self::OLD_HASH, self::NEW_HASH, 1700000000, null, $localId));
		strictAssertSame('branch', $command->command, 'the stop remains one daemon command');
		strictAssertTrue(strpos($command->params[1], 'd.get_local_id=') !== false
			&& strpos($command->params[1], $localId) !== false,
			'the branch compares the identity captured before the replacement');
		strictAssertTrue(strpos($command->params[2], 'd.stop=') !== false,
			'the matching generation still reaches the daemon-selected stop');
		strictAssertTrue(strpos($command->params[3], 'd.stop=') === false,
			'a new same-hash generation cannot be stopped');
	}

	public function testRunCarriesOriginalLocalIdIntoReplacementStop()
	{
		$this->withVerdictSession('replacement-local-id', 0, '',
			function($url, $hash, $old) {
				return ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), $hash, $old);
			},
			function() {
				$this->stageTorrents();
				rTorrent::$source = new Torrent(array('hash' => self::OLD_HASH,
					'info' => array('name' => 'unchanged.mkv', 'length' => 1),
					'comment' => 'http://topic.replacement-local-id-test.invalid/1'));
				$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED); // INPROGRESS
				rXMLRPCRequest::queue('d.hash', true, true, array()); // successor absent
				$this->queueViews(array(), false);
				rXMLRPCRequest::queue(self::SNAPSHOT_KEY_COMMANDS, true, false,
					array(sys_get_temp_dir(), 'label', '', '', '6879823', '1106'));
				rXMLRPCRequest::queue('branch', true, false, array('stale-generation'));
				$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_SKIPPED); // final verdict
				$performed = null;
				strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH, 0, 0, '', $performed),
					'the stale replacement returns a retryable outcome');
				$stops = $this->branchRequestsContaining('$d.stop=');
				strictAssertSame(1, count($stops), 'one daemon-side stop was attempted');
				$condition = $stops[0]['commands'][0]->params[1];
				strictAssertTrue(strpos($condition, 'd.get_local_id=') !== false
					&& strpos($condition, self::LOCAL_ID) !== false,
					'the stop compares the identity captured by run before its handler');
				strictAssertSame(null, rTorrent::$lastSend, 'the stale stop cannot stage a successor');
			});
	}

	public function testDaemonSelectedRunStateWinsAtReplacementCommit()
	{
		foreach(array(
			'fresh UI stop wins over an earlier started observation' => array('selected' => 'stopped', 'issue' => null),
			'fresh UI start wins over an earlier stopped observation' => array('selected' => 'started', 'issue' => 'd.open|d.start'),
			'fresh UI pause is inherited as open, not started' => array('selected' => 'open', 'issue' => 'd.open'),
		) as $label => $case)
		{
			$this->resetFakes();
			$this->stageTorrents();
			$this->queueDaemonSelectedStart(sys_get_temp_dir(), $case['selected']);
			$this->queueLoadConfirmed();
			$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ERASED);
			if($case['issue'] !== null)
				$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
			else
				$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_CLEARED);

			strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH), $label);
			$addition = rTorrent::$lastSend['addition'];
			strictAssertTrue(strpos($addition[1], 'd.set_custom=chk-replaces,' . self::OLD_HASH
				. '-' . $case['selected'] . '-') === 0,
				$label . ': successor record carries the daemon-selected state');
			strictAssertSame($case['issue'] === null ? 0 : 1,
				count($this->branchRequestsContaining($case['selected'] === 'open' ? '$d.open=' : '$d.start=')),
				$label . ': atomic activation follows only that selected state');
			if($case['selected'] === 'open')
				strictAssertSame(0, count($this->branchRequestsContaining('$d.start=')),
					'a paused predecessor must never be escalated to started');
		}
	}

	public function testUntrustworthyReplacementBranchResponsesFailClosed()
	{
		foreach(array(
			'daemon fault' => array(false, true, array()),
			'missing success payload' => array(true, false, array()),
			'unexpected success payload' => array(true, false,
				array(self::NEW_HASH . '-unknown-1700000000')),
		) as $label => $response)
		{
			$this->resetFakes();
			$this->stageTorrents();
			rXMLRPCRequest::queue('d.hash', true, true, array());
			$this->queueViews();
			rXMLRPCRequest::queue(self::COMMIT_SNAPSHOT_KEY_COMMANDS, true, false,
				array(sys_get_temp_dir(), 'label', 'slow', 'seed-value', '6879823', '1106'));
			rXMLRPCRequest::queue('branch', $response[0], $response[1], $response[2]);
			$this->withDebugLog(function() use ($label) {
				strictAssertSame(ruTrackerChecker::STE_ERROR,
					ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
					$label . ': the marker/stop boundary fails closed');
			});
			strictAssertSame(null, rTorrent::$lastSend,
				$label . ': no replacement is loaded after an uncertain daemon outcome');
			foreach(array('d.erase', 'd.open', 'd.open|d.start', 'd.set_custom|d.set_custom') as $key)
				strictAssertSame(0, count(rXMLRPCRequest::requestsFor($key)),
					$label . ': no irreversible follow-up after the boundary: ' . $key);
			strictAssertTrue(strpos(implode("\n", FileUtil::$log), 'nothing was changed') === false,
				$label . ': a partial daemon-side command is never reported as changing nothing');
		}
	}

	public function testReplacementStopRequiresExactlyOneStringMarker()
	{
		foreach(array('extra scalar', 'stringable object') as $mode)
		{
			$this->resetFakes();
			$this->stageTorrents();
			rXMLRPCRequest::queue('d.hash', true, true, array());
			$this->queueViews();
			rXMLRPCRequest::queue(self::COMMIT_SNAPSHOT_KEY_COMMANDS, true, false,
				array(sys_get_temp_dir(), 'label', 'slow', 'seed-value', '6879823', '1106'));
			rXMLRPCRequest::queue('branch', true, false, function($commands) use ($mode) {
				$matches = array();
				preg_match('/' . self::NEW_HASH . '-started-\d+/',
					implode('|', $commands[0]->params), $matches);
				if(!isset($matches[0])) throw new RuntimeException('No started marker in stop branch');
				return $mode === 'extra scalar'
					? array($matches[0], 'unexpected-extra')
					: array(new CheckerStringableMarker($matches[0]));
			});

			strictAssertSame(ruTrackerChecker::STE_ERROR,
				ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
				$mode . ': malformed positive stop reply fails closed');
			strictAssertSame(null, rTorrent::$lastSend,
				$mode . ': no replacement is staged from an untrustworthy reply shape');
		}
	}

	// Metainfo that does not parse is a bad payload and nothing more. It is not
	// the tracker saying the topic is gone: a login wall, a challenge page and a
	// truncated download all look exactly like this, and reading any of them as
	// a deletion retires a live torrent. The verdict is therefore an error the
	// next cycle retries, and no XMLRPC command is sent on the way to it.
	public function testUnparseableMetainfoIsARetryableErrorNotADeletion()
	{
		$this->resetFakes();
		Torrent::$fixtures['not-a-torrent'] = array('errors' => true);

		strictAssertSame(
			ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent(checkerParsed('not-a-torrent'), self::OLD_HASH),
			'malformed metainfo is an error to retry, never evidence that a topic was removed'
		);
		strictAssertSame(0, count(rXMLRPCRequest::$requests), 'a parse failure must not touch rTorrent');
	}

	// The boundary takes a parsed Torrent, so anything else is a caller bug and
	// is refused with the same retryable verdict, before a single command.
	public function testCreateTorrentRefusesAnythingThatIsNotParsedMetainfo()
	{
		foreach(array('raw bytes' => 'd8:announce', 'null' => null, 'an array' => array()) as $label => $payload)
		{
			$this->resetFakes();

			strictAssertSame(ruTrackerChecker::STE_ERROR,
				ruTrackerChecker::createTorrent($payload, self::OLD_HASH),
				$label . ' is not parsed metainfo and must fail as a retryable error');
			strictAssertSame(0, count(rXMLRPCRequest::$requests),
				$label . ' must not touch rTorrent');
		}
	}

	// A replacement harvested through rTorrent::getSource() arrives backed by
	// rTorrent's own session file -- or, when getSource() falls back to
	// d.get_tied_to_file, by a .torrent that belongs to the user. sendTorrent()
	// reads a non-null filename as "mine to delete and reuse": it unlinks it
	// when $saveUploadedTorrents is off, reuses its path for metainfo too large
	// for one packet, and advertises it as x-filename. None of that may happen
	// to a file this plugin did not create, and none of it did before metainfo
	// was parsed once, because the replacement used to be rebuilt from bytes.
	public function testTheReplacementHandedToRTorrentClaimsNoFileOnDisk()
	{
		$this->resetFakes();
		$this->stageHappyReplacement(sys_get_temp_dir());
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		$session = sys_get_temp_dir().'/session/'.self::OLD_HASH.'.torrent';
		$parsed = checkerParsed('new-torrent')->backedByFile($session);
		strictAssertSame($session, $parsed->getFileName(),
			'the fixture starts out backed by a file, the way getSource() hands it over');
		Torrent::$constructions = 0;

		strictAssertSame(null, ruTrackerChecker::createTorrent($parsed, self::OLD_HASH),
			'a file-backed replacement still commits');
		strictAssertSame(0, Torrent::$constructions,
			'disowning the file must not decode the metainfo a second time');
		strictAssertTrue(rTorrent::$lastSend !== null, 'the replacement was staged');
		strictAssertSame(null, rTorrent::$lastSend['torrent']->getFileName(),
			'the object handed to sendTorrent claims no file, so sendTorrent cannot unlink or reuse one');
	}

	// One decode, at one boundary. Counted, not read out of the source:
	// createTorrent() gets the object its caller already parsed and must not
	// build a second one from the same bytes.
	public function testCreateTorrentNeverDecodesTheMetainfoASecondTime()
	{
		$this->resetFakes();
		$this->stageHappyReplacement(sys_get_temp_dir());
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		$parsed = checkerParsed('new-torrent');
		Torrent::$constructions = 0;

		strictAssertSame(null, ruTrackerChecker::createTorrent($parsed, self::OLD_HASH),
			'the already parsed replacement commits');
		strictAssertSame(0, Torrent::$constructions,
			'createTorrent must not decode the replacement metainfo a second time');
		strictAssertTrue(rTorrent::$lastSend !== null, 'the replacement was staged');
		strictAssertSame($parsed, rTorrent::$lastSend['torrent'],
			'the very object the caller parsed is the one staged in the client');
	}

	// End to end through the shared download guard: the response body is
	// decoded exactly once, by parseMetainfo(), and the object travels on.
	public function testDownloadGuardDecodesTheResponseBodyExactlyOnce()
	{
		$this->resetFakes();
		$this->stageHappyReplacement(sys_get_temp_dir());
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		Snoopy::$nextStatus = 200;
		Snoopy::$nextResults = 'new-torrent';
		$client = new Snoopy();
		$client->fetchComplex('https://tracker.test/download.php?id=1');
		Torrent::$constructions = 0;

		strictAssertSame(null, ruTrackerChecker::createTorrentFromDownload($client, self::OLD_HASH),
			'a valid downloaded body commits the replacement');
		strictAssertSame(1, Torrent::$constructions,
			'the downloaded bytes are decoded exactly once, at the single parse boundary');
	}

	// And the answers that are not metainfo: retryable, decided before anything
	// reaches rTorrent, and never a deletion.
	public function testDownloadGuardClassifiesNon200AndMalformedBodiesAsUnreachable()
	{
		foreach(array(
			'a non-200 answer' => array(503, 'new-torrent'),
			'an HTTP-200 login wall' => array(200, 'not-a-torrent'),
			'an empty HTTP-200 body' => array(200, ''),
		) as $label => $fixture)
		{
			$this->resetFakes();
			Torrent::$fixtures['new-torrent'] = array('hash' => self::NEW_HASH, 'info' => array('name' => 'new.mkv'));
			Torrent::$fixtures['not-a-torrent'] = array('errors' => true);
			Snoopy::$nextStatus = $fixture[0];
			Snoopy::$nextResults = $fixture[1];
			$client = new Snoopy();
			$client->fetchComplex('https://tracker.test/download.php?id=1');

			strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
				ruTrackerChecker::createTorrentFromDownload($client, self::OLD_HASH),
				$label . ' proves nothing about the topic and stays retryable');
			strictAssertSame(0, count(rXMLRPCRequest::$requests),
				$label . ' is classified before any XMLRPC read or mutation');
			strictAssertSame(null, rTorrent::$lastSend, $label . ' cannot stage a replacement');
		}
	}

	// parseMetainfo() is the only decode: it answers with the Torrent or with
	// null, and null is the whole vocabulary for "these bytes are not metainfo".
	public function testParseMetainfoReturnsTheTorrentOrNullAndDecodesOnce()
	{
		$this->resetFakes();
		Torrent::$fixtures['new-torrent'] = array('hash' => self::NEW_HASH, 'info' => array('name' => 'new.mkv'));
		Torrent::$fixtures['not-a-torrent'] = array('errors' => true);
		Torrent::$fixtures['no-info-hash-torrent'] = array('errors' => false, 'hash' => null);
		Torrent::$fixtures['short-hash-torrent'] = array('errors' => false, 'hash' => 'ABCD');
		Torrent::$fixtures['non-hex-hash-torrent'] = array('errors' => false, 'hash' => str_repeat('Z', 40));
		// The shape every other fixture here misses, and the one the errors()
		// check exists for: reported errors BESIDE a perfectly good info hash.
		// The real Torrent produces it. Torrent::notify_err() RETURNS rather
		// than throwing, so a non-canonical integer anywhere outside the info
		// dict -- 'creation date' => i0123456789e is enough -- records an error
		// and still finishes decoding, leaving hash_info() valid and the info
		// dict byte-identical to a clean torrent.
		//
		// Nothing else could catch it: this suite's other fixtures never pair
		// the two, and the integration fixtures are built by Torrent::encode(),
		// whose encode_integer() is structurally incapable of emitting a
		// non-canonical integer. Without this row, deleting the errors() check
		// leaves the whole harness green while NNMClub starts answering
		// STE_UPTODATE -- a terminal "this torrent is fine" -- for a body that
		// is currently refused as retryable.
		Torrent::$fixtures['errors-beside-a-valid-hash'] = array('errors' => true, 'hash' => self::NEW_HASH);

		Torrent::$constructions = 0;
		$parsed = ruTrackerChecker::parseMetainfo('new-torrent');
		strictAssertTrue($parsed instanceof Torrent, 'valid metainfo comes back as the parsed Torrent');
		strictAssertSame(self::NEW_HASH, $parsed->hash_info(), 'and it is the torrent those bytes describe');
		strictAssertSame(1, Torrent::$constructions, 'valid metainfo is decoded exactly once');

		foreach(array('not-a-torrent', 'no-info-hash-torrent', 'short-hash-torrent', 'non-hex-hash-torrent',
			'errors-beside-a-valid-hash', '', null, array()) as $rejected)
		{
			strictAssertSame(null, ruTrackerChecker::parseMetainfo($rejected),
				var_export($rejected, true) . ' is not metainfo and must answer null');
		}
		strictAssertSame(0, count(rXMLRPCRequest::$requests), 'classifying bytes never touches rTorrent');
	}

	public function testMalformedMetainfoWithoutInfoHashReturnsErrorWithoutMutatingDaemon()
	{
		$this->resetFakes();
		Torrent::$fixtures['no-info-hash-torrent'] = array('errors' => false, 'hash' => null);

		strictAssertSame(
			ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent(checkerParsed('no-info-hash-torrent'), self::OLD_HASH),
			'metainfo without info hash must fail with STE_ERROR'
		);
		strictAssertSame(0, count(rXMLRPCRequest::$requests), 'malformed info hash must not touch rTorrent');
	}

	public function testSameHashReturnsUptodateWithoutDaemonCalls()
	{
		$this->resetFakes();
		Torrent::$fixtures['same-hash-torrent'] = array('hash' => self::OLD_HASH,
			'info' => array('name' => 'same.mkv'));
		strictAssertSame(ruTrackerChecker::STE_UPTODATE,
			ruTrackerChecker::createTorrent(checkerParsed('same-hash-torrent'), self::OLD_HASH),
			'the replacement boundary itself rejects self-replacement');
		strictAssertSame(0, count(rXMLRPCRequest::$requests), 'equal hashes need no daemon calls');
	}

	public function testDifferentNumericLookingInfoHashesAreNotTreatedAsEqual()
	{
		$this->resetFakes();
		$newHash = '1E' . str_repeat('0', 38);
		$oldHash = str_repeat('0', 39) . '1';
		Torrent::$fixtures['numeric-hash-torrent'] = array('hash' => $newHash, 'info' => array('name' => 'new.mkv'));
		// Stop immediately after the equality gate: reaching this probe proves
		// that the distinct successor was not dismissed as already up to date.
		rXMLRPCRequest::queue('d.hash', false, false, array());

		strictAssertSame(ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent(checkerParsed('numeric-hash-torrent'), $oldHash),
			'different 40-hex hashes stay different even when PHP parses both as the number one');
		strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.hash')),
			'a distinct successor reaches the normal preflight');
	}

	// A missing hash still resolves to STE_NOT_NEED -- but it is now the FAILED
	// read that reveals it, not a probe spent ahead of every read. rTorrent
	// faults a custom read against a hash it does not know, so the probe is
	// only needed to tell that fault apart from a daemon that did not answer,
	// and only the failing case pays for it.
	public function testMissingHashIsResolvedByTheFailedReadNotByAProbeBeforeIt()
	{
		$this->resetFakes();
		self::queueStateRead( true, true, array()); // the read faults
		rXMLRPCRequest::queue('d.hash', true, true, array());                    // and the probe confirms it is gone
		$state = null;
		$time = null;
		$label = null;

		strictAssertSame(false, CheckerProbe::getStateForTest('MISSING', $state, $time, $label), 'a missing hash must fail the state read');
		strictAssertSame(ruTrackerChecker::STE_NOT_NEED, $state, 'a missing hash must resolve to STE_NOT_NEED');
		strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.hash')),
			'exactly one existence probe, and only because the read failed');

		// The happy path is what this reordering is for: one request, not two.
		$this->resetFakes();
		self::queueStateRead( true, false,
			array((string) ruTrackerChecker::STE_UPTODATE, '1700', 'lbl'));
		strictAssertSame(true, CheckerProbe::getStateForTest(self::OLD_HASH, $state, $time, $label), 'a readable state is read');
		strictAssertSame(ruTrackerChecker::STE_UPTODATE, $state, 'and returned');
		strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.hash'),
			'a successful read is itself proof the torrent is there: no probe is spent');
		strictAssertSame(1, count(rXMLRPCRequest::$requests), 'one round trip for a state read that works');

		$this->resetFakes();
		rXMLRPCRequest::queue('d.hash', true, true, array());
		$performed = null;
		strictAssertSame(true, ruTrackerChecker::run('MISSING', null, null, null, $performed),
			'a stale worker must be a successful no-op');
		strictAssertSame(false, $performed,
			'a vanished row does not acknowledge a durable scheduler obligation as checked');
		// Two: the read that fails and the probe that explains why. This is the
		// side of the trade that got one request MORE expensive, and it is the
		// rare one -- a worker whose torrent vanished under it. Every ordinary
		// manual check got one cheaper.
		strictAssertSame(2, count(rXMLRPCRequest::$requests),
			'the stale worker pays a probe only after the read has already failed');
		strictAssertSame(self::GETSTATE_KEY, rXMLRPCRequest::$requests[0]['key'],
			'the scheduler snapshot is not trusted before the live state read');
		strictAssertSame('d.hash', rXMLRPCRequest::$requests[1]['key'],
			'the failed live read is resolved by a hash probe');
	}

	public function testTruncatedSuccessfulStateReadDefersInsteadOfInventingDefaults()
	{
		$this->resetFakes();
		// A non-empty SCGI response with no complete XMLRPC values can make the
		// legacy transport report success with an empty val array. The torrent is
		// still present, so this is an unreadable state, not state zero.
		self::queueStateRead( true, false, array());
		rXMLRPCRequest::queue('d.hash', true, false, array(self::OLD_HASH));
		$state = null;
		$time = null;
		$label = null;

		strictAssertSame(false, CheckerProbe::getStateForTest(self::OLD_HASH, $state, $time, $label),
			'an incomplete successful response must defer the check');
		strictAssertSame(ruTrackerChecker::STE_INPROGRESS, $state,
			'no missing field may be coerced into an invented state zero');
		strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.hash')),
			'the fallback probe confirms that the torrent is present rather than gone');
	}

	// A truncated read (above) heals: the next cycle asks again and the daemon
	// answers. A chk-state that will not PARSE does not. Nothing in the plugin
	// ever rewrites it -- run() returns before setState(), parseMulticall()
	// drops the row from the snapshot, and flushVerdicts() leaves it out of
	// the fresh scan -- so every one of the three readers refuses the same
	// bytes for ever and the torrent is never checked again. Reported through
	// logDebug(), gated on conf.php's shipped $rutrackerCheckDebug = false,
	// that permanent wedge said nothing at all, on any of the three.
	//
	// Asserted with the flag EXPLICITLY false: with it on, the ungated channel
	// and the gated one are indistinguishable.
	public function testAnUnparseableStoredStateIsReportedWithDebuggingAtItsShippedDefault()
	{
		foreach(array(
			'state with a leading zero' => array('01', '1700'),
			'state that is not a number' => array('never', '1700'),
			'negative state'             => array('-1', '1700'),
			'time with a leading zero'   => array('2', '01700'),
			'time that is not a number'  => array('2', 'yesterday'),
		) as $label => $stored)
		{
			$this->resetFakes();
			$this->withoutDebugLog(function() use ($label, $stored) {
				self::queueStateRead( true, false,
					array($stored[0], $stored[1], 'lbl'));
				FileUtil::$log = array();
				$state = null;
				$time = null;
				$label2 = null;

				strictAssertSame(false,
					CheckerProbe::getStateForTest(self::OLD_HASH, $state, $time, $label2),
					$label . ': the refusal itself is unchanged');
				strictAssertSame(ruTrackerChecker::STE_INPROGRESS, $state,
					$label . ': and no unreadable field is coerced into an invented state zero');

				$line = strictAssertOneLogMatching(FileUtil::$log, 'malformed chk-state',
					$label . ': and an operator is told, at the shipped $rutrackerCheckDebug = false');
				strictAssertTrue(strpos($line, self::OLD_HASH) !== false,
					$label . ': the line names the torrent that can never be checked again');
			});
		}

		// Control: a well-formed pair still reads, and says nothing. The
		// legacy on-disk spelling of "never checked" is an UNSET custom, which
		// reads back as '' -- that must keep parsing, not join the wedge.
		foreach(array(
			'a checked torrent'          => array('2', '1700'),
			'the unset legacy shape'     => array('', ''),
		) as $label => $stored)
		{
			$this->resetFakes();
			$this->withoutDebugLog(function() use ($label, $stored) {
				self::queueStateRead( true, false,
					array($stored[0], $stored[1], 'lbl'));
				FileUtil::$log = array();
				$state = null;
				$time = null;
				$label2 = null;

				strictAssertSame(true,
					CheckerProbe::getStateForTest(self::OLD_HASH, $state, $time, $label2),
					$label . ': well-formed input must not fail');
				strictAssertSame(array(), FileUtil::$log,
					$label . ': and a read that worked is nobody\'s problem');
			});
		}
	}

	public function testInvalidLocalIdentityDefersWithVisibleReason()
	{
		$this->resetFakes();
		$this->withoutDebugLog(function() {
			self::queueStateRead(true, false, array('3', '1700', '', 'not-a-local-id'));
			$state = $time = $label = $localId = null;
			strictAssertSame(false, CheckerProbe::getStateForTest(
				self::OLD_HASH, $state, $time, $label, $localId),
				'an invalid daemon identity cannot authorize any write');
			strictAssertOneLogMatching(FileUtil::$log, 'invalid d.local_id',
				'the refusal is visible with debugging disabled');
			strictAssertSame(array(), rXMLRPCRequest::requestsFor('branch'),
				'no guarded write is attempted without a valid identity');
		});
	}

	public function testStateWriteRaceReportsMissingHashWithoutAnError()
	{
		$this->resetFakes();
		rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, true, array());
		rXMLRPCRequest::queue('d.get_custom|d.get_custom', false, true, array());
		rXMLRPCRequest::queue('d.hash', true, true, array());

		strictAssertSame(null, CheckerProbe::setStateForTest(self::OLD_HASH, ruTrackerChecker::STE_UPDATED), 'setState must report that its target disappeared');
		strictAssertSame(3, count(rXMLRPCRequest::$requests),
			'a failed state write first attempts exact readback, then one existence probe');
		strictAssertSame('d.set_custom|d.set_custom', rXMLRPCRequest::$requests[0]['key'], 'the state write must be issued before any probe');
		strictAssertSame(false, rXMLRPCRequest::$requests[0]['important'], 'the racy state write must be non-important');
		strictAssertSame('d.get_custom|d.get_custom', rXMLRPCRequest::$requests[1]['key'],
			'the desired projection is read before absence is considered');
		strictAssertSame('d.hash', rXMLRPCRequest::$requests[2]['key'], 'the miss is confirmed only after failed readback');

		// run() maps the vanished target to a successful no-op.
		rXMLRPCRequest::reset();
		self::queueStateRead( true, false,
			array((string) ruTrackerChecker::STE_UPTODATE, (string) time(), ''));
		rXMLRPCRequest::queue('branch', false, false, array());
		rXMLRPCRequest::queue('d.get_custom|d.get_custom|d.get_local_id', false, false, array());
		rXMLRPCRequest::queue('d.hash', true, true, array());
		strictAssertSame(
			true,
			ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_UPTODATE, time(), ''),
			'a state-write race must not be reported as a check failure'
		);
		strictAssertSame(4, count(rXMLRPCRequest::$requests), 'the raced run must stop right after readback and confirming probe');
	}

	private function queueExactStateProjectionReadback()
	{
		rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false,
			function($commands) {
				$writes = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom');
				return array($writes[0]['commands'][0]->params[2], $writes[0]['commands'][1]->params[2]);
			});
	}

	public function testStateWriteNeedsACompleteReplyOrExactProjectionReadback()
	{
		$this->resetFakes();
		// The daemon acknowledged only one member of the two-command state
		// write. A complete readback proves the other field did not land.
		rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array(0));
		rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false,
			array((string) ruTrackerChecker::STE_UPDATED, '0'));
		strictAssertSame(false, ruTrackerChecker::setState(self::OLD_HASH, ruTrackerChecker::STE_UPDATED),
			'a short positive reply plus a mismatched projection is not durable success');
		strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.get_custom|d.get_custom')),
			'the incomplete positive reply is measured by exact projection readback');

		$this->resetFakes();
		// Same truncated reply, but this time both setters really landed and the
		// response alone was lost. The readback must recognize that success.
		rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array(0));
		$this->queueExactStateProjectionReadback();
		strictAssertSame(true, ruTrackerChecker::setState(self::OLD_HASH, ruTrackerChecker::STE_UPDATED),
			'a short reply is accepted only after the complete desired projection is observed');
		strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.get_custom|d.get_custom')),
			'the lost-response case is still measured rather than trusted');
	}

	public function testRejectedStateWriteIsDecidedByReadableProjection()
	{
		foreach (array(
			'faulted write' => array(true, true),
			'no write answer' => array(false, false),
		) as $reason => $outcome) {
			$this->resetFakes();
			rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'),
				$outcome[0], $outcome[1], array());
			$this->queueExactStateProjectionReadback();
			strictAssertSame(true, ruTrackerChecker::setState(self::OLD_HASH, ruTrackerChecker::STE_UPDATED),
				$reason . ': complete readback proves the desired state despite write refusal');
			strictAssertSame(2, count(rXMLRPCRequest::$requests),
				$reason . ': one write and one projection read, without an existence probe');
		}

		$this->resetFakes();
		rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, true, array());
		rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false,
			array((string) ruTrackerChecker::STE_UPDATED, '0'));
		strictAssertSame(false, ruTrackerChecker::setState(self::OLD_HASH, ruTrackerChecker::STE_UPDATED),
			'a readable but mismatched projection cannot bless a faulted state write');
		strictAssertSame(2, count(rXMLRPCRequest::$requests),
			'a readable mismatch needs no existence probe');
	}

	public function testUpToDateStateWritesTheExactSuccessTimeField()
	{
		$this->resetFakes();
		rXMLRPCRequest::queue('d.set_custom|d.set_custom|d.set_custom', true, false, array(0, 0, 0));
		strictAssertSame(true, ruTrackerChecker::setState(self::OLD_HASH, ruTrackerChecker::STE_UPTODATE),
			'a complete up-to-date projection is accepted');
		$writes = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom|d.set_custom');
		strictAssertSame(1, count($writes), 'one three-field state projection was sent');
		$commands = $writes[0]['commands'];
		strictAssertSame(array(self::OLD_HASH, 'chk-state', (string) ruTrackerChecker::STE_UPTODATE),
			$commands[0]->params, 'the verdict field stays first');
		strictAssertSame('chk-time', $commands[1]->params[1], 'the check clock stays second');
		strictAssertSame('chk-stime', $commands[2]->params[1], 'the success clock uses chk-stime');
		strictAssertSame($commands[1]->params[2], $commands[2]->params[2],
			'the two clocks share one captured time');
	}

	public function testNewStatusConstantsAreAppendedWithoutRenumbering()
	{
		strictAssertSame(9, ruTrackerChecker::STE_META_PENDING, 'META_PENDING value');
		strictAssertSame(10, ruTrackerChecker::STE_ABSORBED, 'ABSORBED value');
		strictAssertSame(4, ruTrackerChecker::STE_DELETED, 'existing values untouched');
	}

	// run()'s META_PENDING short-circuit (check.php ~603): a torrent parked
	// mid-metadata-fetch must be handed to RuTrackerMetaFetch::pump(),
	// never re-classified through the normal INPROGRESS transition.
	// pump()'s own ordered harvest is covered exhaustively by
	// MetaFetchTest.php; this only proves the wiring in run().
	// The chk-state write above reads like a claim but is not one: d.set_custom
	// has no compare-and-swap, so two processes that both read META_PENDING
	// both write INPROGRESS and both reach pump() -- which erases a stub and
	// hands its bytes to createTorrent(). batch_check.php takes no cycle lock,
	// so a "check" click during the hourly pass lands exactly in that gap. The
	// real claim goes through the flock-backed state store.
	// The ORDINARY dispatch, not just the metadata pump. Its STE_INPROGRESS
	// write is a plain d.set_custom, and the scheduler dispatches on a
	// chk-state captured by update.php's cycle-start multicall -- so a click
	// that started first stays invisible to that pass for the rest of the
	// cycle, and both would go on to stop, erase and reload the same torrent.
	public function testOrdinaryDispatchIsClaimedToo()
	{
		$this->resetFakes();
		ruTrackerChecker::registerTracker('/topic\.claim-test\.invalid/', '/tracker\.claim-test\.invalid/',
			function($url) { return ruTrackerChecker::STE_UPTODATE; });

		$dir = sys_get_temp_dir() . '/rut-claim-' . bin2hex(random_bytes(5)) . '/';
		mkdir($dir, 0777, true);
		file_put_contents($dir . self::OLD_HASH . '.torrent', 'x');
		rTorrentSettings::get()->session = $dir;
		Torrent::$fixtures[$dir . self::OLD_HASH . '.torrent'] = array(
			'comment' => 'http://topic.claim-test.invalid/1',
			'announce' => 'http://tracker.claim-test.invalid/announce',
		);

		try
		{
			// Another process is already inside this hash's check. Seeded by
			// hand: what this case pins is what the SECOND worker does when
			// the claim is already held, not the acquisition race itself.
			// That race is the state store's, and StateTest proves it with two
			// real processes released together on a barrier; re-staging it
			// here would prove the same thing twice and more slowly.
			strictInvoke('ruTrackerChecker', 'claimCheck', array(self::OLD_HASH, time()));

			strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_UPTODATE, time(), ''),
				'standing down is not a failed check');
			strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
				'the INPROGRESS lock is not written over the holder');

			// Released, the next caller runs normally and leaves no claim.
			strictInvoke('ruTrackerChecker', 'releaseCheck', array(self::OLD_HASH));
			rXMLRPCRequest::reset();
			self::queueStateRead( true, false,
				array((string) ruTrackerChecker::STE_UPTODATE, (string) time(), ''));
			rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array());
			rXMLRPCRequest::queue('d.set_custom|d.set_custom|d.set_custom', true, false, array());

			$performed = null;
			strictAssertSame(true, ruTrackerChecker::run(
				self::OLD_HASH, ruTrackerChecker::STE_UPTODATE, time(), '', $performed),
				'the next caller checks it');
			strictAssertSame(true, $performed,
				'an ordinary handler run acknowledges a durable scheduler obligation');
			strictAssertTrue(in_array((string) ruTrackerChecker::STE_INPROGRESS,
				$this->customWritesFor('chk-state'), true), 'and does write the lock');
			strictAssertSame(array(), RuTrackerState::load('meta-claims'),
				'a finished check leaves no claim behind');
		}
		finally
		{
			strictRemoveTree($dir);
		}
	}

	public function testSchedulerSnapshotIsRefreshedUnderTheClaimBeforeDispatch()
	{
		$this->resetFakes();
		$saved = isset($GLOBALS['ignoreLabels']) ? $GLOBALS['ignoreLabels'] : null;
		$GLOBALS['ignoreLabels'] = array('snapshot-ignore');
		RuTrackerMetaFetch::$result = ruTrackerChecker::STE_META_PENDING;
		try
		{
			// The scheduler captured an ordinary state and an ignored label. A
			// completed manual check subsequently moved the live row to
			// META_PENDING and removed that label before releasing its claim.
			self::queueStateRead( true, false, function() {
				$claims = RuTrackerState::load('meta-claims');
				strictAssertTrue(isset($claims[self::OLD_HASH]),
					'the live state is read only after the per-hash claim is held');
				return array((string) ruTrackerChecker::STE_META_PENDING, (string) time(), 'live-label');
			});
			rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array());
			rXMLRPCRequest::queue('d.get_custom', true, false, array(self::NEW_HASH));
			rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array());

			strictAssertSame(true,
				ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_UPTODATE, time(), 'snapshot-ignore'),
				'a stale scheduler row is still a successful dispatch');
			strictAssertSame(1, count(RuTrackerMetaFetch::$calls),
				'the live META_PENDING state chooses the pump, not the stale ordinary state or label');
			strictAssertSame(1, count(rXMLRPCRequest::requestsFor(self::GETSTATE_KEY)),
				'the state, timestamp and label are refreshed together');
			strictAssertSame(array(), RuTrackerState::load('meta-claims'),
				'the single dispatch claim is released after the refreshed branch completes');
		}
		finally
		{
			if($saved === null) unset($GLOBALS['ignoreLabels']);
			else $GLOBALS['ignoreLabels'] = $saved;
		}
	}

	public function testHeldClaimRejectsConcurrentCallerAndStaleClaimExpires()
	{
		$this->resetFakes();
		RuTrackerMetaFetch::$result = ruTrackerChecker::STE_META_PENDING;

		// Worker A: takes the claim, pumps, and releases it on the way out.
		self::queueStateRead( true, false,
			array((string) ruTrackerChecker::STE_META_PENDING, (string) time(), ''));
		rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());
		rXMLRPCRequest::queue('d.get_custom', true, false, array(''));
		rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());
		$performed = null;
		strictAssertSame(true, ruTrackerChecker::run(
			self::OLD_HASH, ruTrackerChecker::STE_META_PENDING, time(), '', $performed),
			'the first worker runs normally');
		strictAssertSame(1, count(RuTrackerMetaFetch::$calls), 'the first worker pumps');
		strictAssertSame(false, $performed,
			'a metadata pump does not acknowledge a forum-aware scheduler obligation');

		// Worker B arriving while A still holds it. The claim is re-taken by
		// hand because a completed run() always releases: what is under test
		// is that a HELD claim turns the second worker away.
		strictInvoke('ruTrackerChecker', 'claimCheck', array(self::OLD_HASH, time()));
		RuTrackerMetaFetch::$calls = array();
		rXMLRPCRequest::reset();

		strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_META_PENDING, time(), ''),
			'standing down is not a failed check');
		strictAssertSame(0, count(RuTrackerMetaFetch::$calls),
			'the second worker must not pump a fetch another process is already pumping');
		strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
			'and it must not write the INPROGRESS lock over the holder either');

		// The old owner is now beyond a full observed monotonic lease. The
		// existing claim stays tokenized; only its observed clock is aged.
		$held = RuTrackerState::load('meta-claims')[self::OLD_HASH];
		strictAssertTrue(isset($held['lease_observed']), 'the contending run observed the owner');
		$held['lease_observed']['mono'] = checkerObservedMono(ruTrackerChecker::MAX_LOCK_TIME + 1);
		RuTrackerState::save('meta-claims', array(self::OLD_HASH => $held));
		RuTrackerMetaFetch::$calls = array();
		rXMLRPCRequest::reset();
		self::queueStateRead( true, false,
			array((string) ruTrackerChecker::STE_META_PENDING, (string) time(), ''));
		rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());
		rXMLRPCRequest::queue('d.get_custom', true, false, array(''));
		rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());
		strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_META_PENDING, time(), ''),
			'the third worker runs');
		strictAssertSame(1, count(RuTrackerMetaFetch::$calls),
			'an abandoned claim expires with the same allowance the chk-state lock gets');
		strictAssertSame(array(), RuTrackerState::load('meta-claims'),
			'and a finished pump leaves no claim behind');
	}

	// A claim has to say WHO holds it, not just when it was taken. With only a
	// timestamp, a worker that overran MAX_LOCK_TIME released whatever entry it
	// found on its way out -- including the one a second worker had just taken
	// over the expired slot -- and a third worker then walked into the same
	// destructive replacement alongside the second. The lease was added to fix
	// "there is no claim at all"; this is the hole the fix itself opened.
	public function testAnOverrunWorkerCannotReleaseTheClaimThatSupersededItsOwn()
	{
		$this->resetFakes();
		RuTrackerState::save('meta-claims', array());

		$first = strictInvoke('ruTrackerChecker', 'claimCheck', array(self::OLD_HASH, 1000));
		strictAssertTrue($first !== false, 'the first worker takes the claim');

		// A wall timestamp cannot expire it. The first contender starts a
		// monotonic observation, then an aged observation permits takeover.
		strictAssertSame(false, strictInvoke('ruTrackerChecker', 'claimCheck',
			array(self::OLD_HASH, 1000 + ruTrackerChecker::MAX_LOCK_TIME + 1)),
			'wall age alone cannot steal the owner');
		$held = RuTrackerState::load('meta-claims')[self::OLD_HASH];
		$held['lease_observed']['mono'] = checkerObservedMono(ruTrackerChecker::MAX_LOCK_TIME + 1);
		RuTrackerState::save('meta-claims', array(self::OLD_HASH => $held));
		$second = strictInvoke('ruTrackerChecker', 'claimCheck',
			array(self::OLD_HASH, 1000 + ruTrackerChecker::MAX_LOCK_TIME + 1));
		strictAssertTrue(is_string($second), 'an observed expired claim is taken over');
		strictAssertTrue($first !== $second, 'the two holders are told apart by their own tokens');

		// The overrun worker finally finishes and lets go of ITS claim.
		strictInvoke('ruTrackerChecker', 'releaseCheck', array(self::OLD_HASH, $first));

		strictAssertSame(false,
			strictInvoke('ruTrackerChecker', 'claimCheck',
				array(self::OLD_HASH, 1000 + ruTrackerChecker::MAX_LOCK_TIME + 2)),
			'the second worker still holds it, so a third must be refused');
	}

	// The releasing worker is the holder here, which is the ordinary case: the
	// claim must actually go away, or the torrent is wedged until the lease
	// expires.
	public function testTheHolderReleasesItsOwnClaim()
	{
		$this->resetFakes();
		RuTrackerState::save('meta-claims', array());

		$token = strictInvoke('ruTrackerChecker', 'claimCheck', array(self::OLD_HASH, 1000));
		strictInvoke('ruTrackerChecker', 'releaseCheck', array(self::OLD_HASH, $token));
		strictAssertSame(array(), RuTrackerState::load('meta-claims'),
			'the holder releasing its own claim leaves nothing behind');
	}

	public function testWorkerReleaseRefusesAnythingThatIsNotAnOwnerToken()
	{
		$this->resetFakes();
		$owner = ruTrackerChecker::claimCheckForWorker(self::OLD_HASH, 1000);
		strictAssertTrue(is_string($owner), 'the control worker owns a real token');

		strictAssertSame(false,
			ruTrackerChecker::releaseCheckForWorker(self::OLD_HASH, null),
			'a storage-failure result cannot become an untokened release');
		strictAssertSame(false,
			ruTrackerChecker::claimCheckForWorker(self::OLD_HASH, 1001),
			'the original owner remains protected after the refused release');

		strictAssertSame(true,
			ruTrackerChecker::releaseCheckForWorker(self::OLD_HASH, $owner),
			'the actual owner token still releases normally');
	}

	// The wait happens inside the per-hash claim, whose lease is MAX_LOCK_TIME,
	// so an unbounded value lets a worker outlive its own claim -- which is
	// exactly the ABA the owner token above had to be added for. conf.php
	// promises clamping; this one had a floor only.
	public function testTheMetadataWaitIsBoundedAtBothEnds()
	{
		$this->resetFakes();
		$previous = isset($GLOBALS['rutrackerMetaWait']) ? $GLOBALS['rutrackerMetaWait'] : null;
		try
		{
			strictAssertSame(ruTrackerChecker::METADATA_WAIT_MAX,
				ruTrackerChecker::metadataWaitSeconds(100000),
				'a mistyped wait must not outlive the claim it runs inside');
			strictAssertSame(0, ruTrackerChecker::metadataWaitSeconds(-30), 'and never goes negative');
			$GLOBALS['rutrackerMetaWait'] = 99999;
			strictAssertSame(ruTrackerChecker::METADATA_WAIT_MAX, ruTrackerChecker::metadataWaitSeconds(),
				'the configured value is clamped the same way as an override');
			unset($GLOBALS['rutrackerMetaWait']);
			strictAssertSame(ruTrackerChecker::METADATA_WAIT_DEFAULT, ruTrackerChecker::metadataWaitSeconds(),
				'with nothing configured the documented default applies');
		}
		finally
		{
			if($previous === null) unset($GLOBALS['rutrackerMetaWait']);
			else $GLOBALS['rutrackerMetaWait'] = $previous;
		}
	}

	// STE_NOT_NEED is terminal: either no registered handler claims the torrent
	// or the owning handler established that no check is needed. A session copy
	// that does not parse establishes neither fact. It used to share that return,
	// so a corrupt session file settled the torrent and was never retried.
	public function testATorrentAHandlerOwnsButCannotReadIsRetryableNotDismissed()
	{
		$this->resetFakes();
		$this->withDebugLog(function() {
			// A handler that owns the announce but cannot make a topic out of
			// it -- which is every handler, since run_ex hands the announce URL
			// to gates that parse topic URLs.
			ruTrackerChecker::registerTracker('/topic\.owned-test\.invalid/', '/tracker\.owned-test\.invalid/',
				function($url) { return ruTrackerChecker::STE_DECLINED; });
			Torrent::$fixtures['owned'] = array(
				'hash' => self::OLD_HASH,
				'comment' => '',                                              // magnet-added: nothing to go on
				'announce' => 'http://tracker.owned-test.invalid/announce',
			);

			strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
				ruTrackerChecker::run_ex(self::OLD_HASH, 'owned'),
				'the announce says whose it is, so "could not read it" is not "nothing to do"');
			strictAssertOneLogMatching(FileUtil::$log, 'no handler could',
				'and the reason is named instead of being silent');

			// A torrent no registered tracker claims at all is the case
			// STE_NOT_NEED exists for, and it stays that.
			Torrent::$fixtures['stranger'] = array(
				'hash' => self::OLD_HASH,
				'comment' => 'http://some.other.site.invalid/1',
				'announce' => 'http://some.other.site.invalid/announce',
			);
			strictAssertSame(ruTrackerChecker::STE_NOT_NEED,
				ruTrackerChecker::run_ex(self::OLD_HASH, 'stranger'),
				'nobody claims it, so it really is nobody\'s business');
		});
	}

	// A comment filter is a substring test over free text, so a torrent whose
	// description merely MENTIONS another tracker matched that tracker's filter
	// first -- and the loop returned its answer unconditionally, so the handler
	// that actually owns the torrent never got a look.
	public function testAHandlerThatDeclinesPassesTheTorrentOnInsteadOfEndingTheSearch()
	{
		$this->resetFakes();
		$ran = array();
		ruTrackerChecker::registerTracker('/mentioned\.invalid/', '/mentioned\.invalid/',
			function($url) use (&$ran) { $ran[] = 'mentioned'; return ruTrackerChecker::STE_DECLINED; });
		ruTrackerChecker::registerTracker('/realowner\.invalid/', '/realowner\.invalid/',
			function($url) use (&$ran) { $ran[] = 'realowner'; return ruTrackerChecker::STE_UPTODATE; });
		Torrent::$fixtures['prose'] = array(
			'hash' => self::OLD_HASH,
			// Both filters match this one string; the mentioned one is
			// registered first, exactly as rutracker.php is required before
			// kinozal.php in check.php.
			'comment' => 'https://realowner.invalid/topic/7 -- ранее на mentioned.invalid',
			'announce' => 'http://realowner.invalid/announce',
		);

		strictAssertSame(ruTrackerChecker::STE_UPTODATE, ruTrackerChecker::run_ex(self::OLD_HASH, 'prose'),
			'the handler that can actually read the topic decides');
		strictAssertSame(array('mentioned', 'realowner'), $ran,
			'the first match is tried and, having declined, hands the torrent on');
	}

	// Five of the seven handlers register the SAME pattern as their comment
	// filter and their announce filter (anidub, tapochek, toloka...). Letting a
	// declining handler fall through to the announce loop therefore ran it a
	// second time on a URL it cannot read -- and turned its deliberate "leave
	// this one alone" (anidub's untagged release) into "could not read it",
	// which puts the torrent back in the queue every cycle for ever.
	public function testAHandlerThatReadTheCommentAndSaidNoHasSettledIt()
	{
		$this->resetFakes();
		$calls = 0;
		ruTrackerChecker::registerTracker('/settled-test\.invalid/', '/settled-test\.invalid/',
			function($url) use (&$calls) { $calls++; return ruTrackerChecker::STE_NOT_NEED; });
		Torrent::$fixtures['settled'] = array(
			'hash' => self::OLD_HASH,
			'comment' => 'http://settled-test.invalid/topic/9',
			'announce' => 'http://settled-test.invalid/announce',
		);

		strictAssertSame(ruTrackerChecker::STE_NOT_NEED,
			ruTrackerChecker::run_ex(self::OLD_HASH, 'settled'),
			'the handler was handed the topic URL it is written to read, so its answer stands');
		strictAssertSame(1, $calls, 'and it is asked once, not once per filter it registered');
	}

	// A terminal answer from the topic URL belongs to that topic. A second
	// tracker in the announce-list may describe a legitimate cross-seed, but it
	// cannot reopen or overwrite the verdict the comment owner just reached.
	public function testATerminalCommentVerdictIsNotOverwrittenByACrossSeedAnnounce()
	{
		$this->resetFakes();
		$ran = array();
		ruTrackerChecker::registerTracker('/topic-owner\.invalid/', '/topic-owner\.invalid/',
			function($url) use (&$ran) { $ran[] = 'topic-owner'; return ruTrackerChecker::STE_NOT_NEED; });
		ruTrackerChecker::registerTracker('/cross-seed\.invalid/', '/cross-seed\.invalid/',
			function($url) use (&$ran) { $ran[] = 'cross-seed'; return ruTrackerChecker::STE_UPTODATE; });
		Torrent::$fixtures['terminal-cross-seed'] = array(
			'hash' => self::OLD_HASH,
			'comment' => 'http://topic-owner.invalid/topic/9',
			'announce' => 'http://cross-seed.invalid/announce',
		);

		strictAssertSame(ruTrackerChecker::STE_NOT_NEED,
			ruTrackerChecker::run_ex(self::OLD_HASH, 'terminal-cross-seed'),
			'the topic owner\'s terminal answer is final');
		strictAssertSame(array('topic-owner'), $ran,
			'the unrelated announce handler is never allowed to overwrite it');
	}

	public function testRuTrackerMentionDoesNotHideARegisteredForeignCommentOwner()
	{
		$this->resetFakes();
		ruTrackerChecker::registerTracker('/rutracker\./', '/t-ru\.org/',
			'RuTrackerCheckImpl::download_torrent');
		ruTrackerChecker::registerTracker('/kinozal\./', '/kinozal\./',
			'KinozalCheckImpl::download_torrent');

		strictAssertSame(true,
			ruTrackerChecker::isForeignComment(
				'Originally published at rutracker.org; owner: https://kinozal.tv/details.php?id=12345'),
			'a RuTracker mention must not hide the registered foreign comment owner');
	}

	// The mixed case, which is what decides the ORDER of the two answers: one
	// A comment mention may be outside that handler's jurisdiction, while an
	// announce identifies another handler that also cannot parse a topic. With
	// no real verdict from either one, the result stays retryable.
	public function testAnUnreadableAnnounceLeavesAllDeclinesInconclusive()
	{
		$this->resetFakes();
		$this->withDebugLog(function() {
				ruTrackerChecker::registerTracker('/reader-test\.invalid/', '/reader-test\.invalid/',
					function($url) { return ruTrackerChecker::STE_DECLINED; });
				ruTrackerChecker::registerTracker('/other-test\.invalid/', '/othertracker-test\.invalid/',
					function($url) { return ruTrackerChecker::STE_DECLINED; });
			Torrent::$fixtures['mixed'] = array(
				'hash' => self::OLD_HASH,
				'comment' => 'http://reader-test.invalid/topic/9',
				'announce' => 'http://othertracker-test.invalid/announce',
			);

			strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
				ruTrackerChecker::run_ex(self::OLD_HASH, 'mixed'),
				'the announce owner could not read what it was handed, so nothing is settled');
			strictAssertOneLogMatching(FileUtil::$log, 'no handler could', 'and it says so');
		});
	}

	// A RuTracker torrent normally carries several announce rows (bt, bt2,
	// bt3). Continuing past a decline made the announce loop hand the handler
	// EVERY matching row in turn -- and a handler that gets past its gate
	// spends HTTP requests, so one torrent could pay for three of them.
	public function testAHandlerIsAskedOncePerTorrentNotOncePerMatchingAnnounce()
	{
		$this->resetFakes();
		$seen = array();
		ruTrackerChecker::registerTracker('/comment-only-test\.invalid/', '/mirror-test\.invalid/',
			function($url) use (&$seen) { $seen[] = $url; return ruTrackerChecker::STE_DECLINED; });
		Torrent::$fixtures['mirrors'] = array(
			'hash' => self::OLD_HASH,
			'comment' => '',
			'announce' => 'http://bt.mirror-test.invalid/announce',
			'announce_list' => array(
				array('http://bt2.mirror-test.invalid/announce'),
				array('http://bt3.mirror-test.invalid/announce'),
			),
		);

		strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
			ruTrackerChecker::run_ex(self::OLD_HASH, 'mirrors'),
			'still inconclusive, as before');
		strictAssertSame(1, count($seen),
			'the handler answered once and is not asked again for a sibling row: ' . implode(', ', $seen));
	}

	public function testAnUnparseableSessionCopyIsNotTheSameAsNoHandler()
	{
		$this->resetFakes();
		$this->withDebugLog(function() {
			// The Torrent double reports errors for a source it has no fixture
			// for, which is what an unparseable session copy looks like.
			Torrent::$fixtures = array();
			strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
				ruTrackerChecker::run_ex(self::OLD_HASH, 'corrupt-session-copy'),
				'bytes nobody could read conclude nothing, and stay retryable');
			strictAssertOneLogMatching(FileUtil::$log, 'does not parse',
				'and the reason is named in the log');

			// A torrent that parses perfectly well and belongs to no registered
			// tracker is the case STE_NOT_NEED is for.
			Torrent::$fixtures['foreign'] = array(
				'hash' => self::OLD_HASH,
				'comment' => 'http://some.other.tracker.invalid/topic/1',
				'announce' => 'http://some.other.tracker.invalid/announce',
			);
			strictAssertSame(ruTrackerChecker::STE_NOT_NEED,
				ruTrackerChecker::run_ex(self::OLD_HASH, 'foreign'),
				'a readable torrent no handler claims is genuinely not our business');
		});
	}

	public function testMetadataOnlySourceWaitsForTrackerIdentityInsteadOfSettling()
	{
		$this->resetFakes();
		$this->withDebugLog(function() {
			// A raw BEP-9 info dictionary has the name and file tree, but its
			// announce/comment live outside that dictionary. getSource() can use
			// it for replacement harvest, while ordinary dispatch still has no
			// evidence that no registered tracker owns it.
			Torrent::$fixtures['metadata-only'] = array(
				'hash' => self::OLD_HASH,
				'info' => array('name' => 'metadata-only.bin', 'length' => 1),
				'comment' => '',
				'announce' => '',
				'announce_list' => array(),
			);

			strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
				ruTrackerChecker::run_ex(self::OLD_HASH, 'metadata-only'),
				'a metadata-only source remains retryable until tracker identity is readable');
			strictAssertOneLogMatching(FileUtil::$log, 'no tracker identity',
				'the missing envelope fields are classified explicitly');
		});
	}

	public function testRunUsesTheDaemonSourceWhileTheSessionTorrentIsStillPending()
	{
		$this->resetFakes();
		$calls = 0;
		ruTrackerChecker::registerTracker('/topic\.fresh-source\.invalid/',
			'/tracker\.fresh-source\.invalid/',
			function($url, $hash, $torrent) use (&$calls) {
				$calls++;
				strictAssertSame(CheckerTest::OLD_HASH, $hash,
					'the daemon source is dispatched for the requested hash');
				strictAssertSame('fresh.bin', $torrent->name(),
					'the handler receives the already parsed daemon source');
				return ruTrackerChecker::STE_UPTODATE;
			});
		rTorrent::$source = new Torrent(array(
			'hash' => self::OLD_HASH,
			'info' => array('name' => 'fresh.bin', 'length' => 1),
			'comment' => 'http://topic.fresh-source.invalid/42',
			'announce' => 'http://tracker.fresh-source.invalid/announce',
		));
		// There is intentionally no <session>/<hash>.torrent. load_raw is
		// asynchronous and the daemon can expose its tied source first.
		rTorrentSettings::get()->session = '/session-copy-not-written-yet/';
		self::queueStateRead( true, false,
			array((string) ruTrackerChecker::STE_UPTODATE, (string) time(), ''));
		rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array());
		rXMLRPCRequest::queue('d.set_custom|d.set_custom|d.set_custom', true, false, array());

		$performed = null;
		strictAssertSame(true,
			ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_UPTODATE,
				time(), '', $performed),
			'the checker does not wait an hour for the session file to appear');
		strictAssertSame(1, rTorrent::$sourceReads,
			'the checker asks the daemon-aware source resolver exactly once');
		strictAssertSame(1, $calls,
			'the owning tracker handler runs in the same check');
		strictAssertSame(true, $performed,
			'the completed handler is acknowledged after its verdict is stored');
	}

	// The chk-state INPROGRESS lock: both halves of it -- honouring a fresh one
	// and expiring a stale one after MAX_LOCK_TIME -- were executed by no test
	// at all. It is what stops two workers running the destructive check over
	// one torrent, and what stops a process that died mid-check wedging that
	// torrent for ever.
	// The replacement arrives carrying its own verdict: chk-state UPDATED and
	// both timestamps, stamped in the load command list so they land with the
	// torrent rather than in a follow-up write that can be lost. All three
	// could be deleted outright and every suite stayed green -- the UI would
	// then show a freshly replaced torrent as never checked, and the scheduler
	// would treat it as a cold row.
	// A replacement is the same TOPIC with new metadata, so the topic id and the
	// forum it lives in are still true of the successor. They were simply
	// dropped, and the next check then had to resolve the forum from scratch --
	// which, whenever the feed does not happen to know that topic, is a walk of
	// the whole tracker for a fact the predecessor already had.
	public function testTheReplacementInheritsTheTopicAndForumItsPredecessorKnew()
	{
		$this->resetFakes();
		$this->stageHappyReplacement(sys_get_temp_dir(), 1, 1);
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

		strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH), 'the replacement commits');
		$addition = implode("\n", rTorrent::$lastSend['addition']);

		strictAssertTrue(strpos($addition, 'chk-topic,6879823') !== false,
			'the successor is loaded already knowing its topic: ' . $addition);
		strictAssertTrue(strpos($addition, 'chk-forum,1106') !== false,
			'and the forum that topic lives in, so layer 3 needs no fresh resolution');
	}

	// But only what the predecessor actually had: an empty value written as a
	// custom reads back as "set to nothing", which resolveForum() and
	// rememberTopic() would take for a decision somebody made.
	public function testAPredecessorWithNoTopicOrForumPassesNothingOn()
	{
		$this->resetFakes();
		$this->stageHappyReplacement(sys_get_temp_dir(), 1, 1, array(), array(), '', '');
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

		strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH), 'the replacement commits');
		$addition = implode("\n", rTorrent::$lastSend['addition']);
		strictAssertTrue(strpos($addition, 'chk-topic') === false,
			'nothing is invented for a predecessor that had no topic recorded');
		strictAssertTrue(strpos($addition, 'chk-forum') === false, 'nor a forum');
	}

	// Those two customs are the only values in the load command list whose
	// bytes come from a field a third party writes freely: the marker is hex
	// from random_bytes, the inheritance record is documented comma-free, the
	// rest are ints or enums, and the label is rawurlencode'd. d.custom.set
	// splits its own arguments on commas, so a comma in either makes it a
	// three-argument call -- torrent::input_error -- which by the semantics
	// documented at buildReplacementAddition() aborts the tail of the list.
	// "007" needs no comma to be wrong: no reader accepts that spelling.
	public function testAnUncanonicalTopicOrForumIsNotCopiedIntoTheLoadCommandList()
	{
		$this->resetFakes();
		$this->stageHappyReplacement(sys_get_temp_dir(), 1, 1, array(), array(), '123,456', '007');
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

		strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
			'the replacement commits');
		$addition = rTorrent::$lastSend['addition'];
		$joined = implode("\n", $addition);
		strictAssertTrue(strpos($joined, 'chk-topic') === false,
			'a comma-carrying topic never reaches the load command list: ' . $joined);
		strictAssertTrue(strpos($joined, 'chk-forum') === false,
			'nor a forum id in a spelling no reader accepts: ' . $joined);
		foreach($addition as $command)
			strictAssertTrue(substr_count($command, ',') <= 1,
				'no load command may carry a third comma-separated argument: ' . $command);
	}

	// Canonicalising the copy is not the same as rejecting it: transport
	// whitespace is not the question, the spelling of the id is, which is how
	// resolveForum() already reads chk-forum back.
	public function testAWhitespacePaddedTopicAndForumAreForwardedInTheirCanonicalSpelling()
	{
		$this->resetFakes();
		$this->stageHappyReplacement(sys_get_temp_dir(), 1, 1, array(), array(), " 6879823\n", ' 1106 ');
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

		strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
			'the replacement commits');
		$addition = rTorrent::$lastSend['addition'];
		strictAssertTrue(in_array('d.set_custom=chk-topic,6879823', $addition, true),
			'the successor is told its topic in the one spelling that names it: ' . implode("\n", $addition));
		strictAssertTrue(in_array('d.set_custom=chk-forum,1106', $addition, true),
			'and its forum likewise: ' . implode("\n", $addition));
	}

	public function testTheReplacementIsLoadedCarryingItsOwnVerdictAndTimestamps()
	{
		$this->resetFakes();
		$this->stageHappyReplacement(sys_get_temp_dir(), 1, 1);
		$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

		$before = time();
		strictAssertSame(null, ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH), 'the replacement commits');
		$addition = rTorrent::$lastSend['addition'];

		$state = null;
		$stamps = array();
		foreach ($addition as $command) {
			if (preg_match('/chk-state,(\d+)$/', $command, $m)) $state = (int) $m[1];
			if (preg_match('/chk-(time|stime),(\d+)$/', $command, $m)) $stamps[$m[1]] = (int) $m[2];
		}
		strictAssertSame(ruTrackerChecker::STE_UPDATED, $state,
			'a replacement is loaded already marked as updated, not as never checked');
		strictAssertSame(array('time', 'stime'), array_keys($stamps),
			'and carries both timestamps, in the load list where they cannot be lost');
		foreach ($stamps as $which => $at)
			strictAssertTrue($at >= $before && $at <= time() + 1,
				'chk-' . $which . ' is stamped now, not left at zero');
	}

	public function testExpiredPPreHandlerCorrectionRunsUnderClaimBeforeStateWrite()
	{
		$this->resetFakes();
		$seen = false;
		self::queueStateRead(true, false, array((string) ruTrackerChecker::STE_INPROGRESS,
			(string) (time() - ruTrackerChecker::MAX_LOCK_TIME - 2), ''));
		rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_local_id'), true, false, array('', '', self::LOCAL_ID));
		$prepare = function ($hash) use (&$seen) {
			$seen = true;
			strictAssertSame(self::OLD_HASH, $hash, 'correction uses the claimed hash');
			strictAssertSame(array(), $this->customWritesFor('chk-state'),
				'correction is installed before ordinary checker prewrite');
			return false; // An unknown correction must stop the handler and keep the durable obligation.
		};
		strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH,
			ruTrackerChecker::STE_INPROGRESS, time() - ruTrackerChecker::MAX_LOCK_TIME - 2,
			'', $performed, $prepare), 'unknown correction defers ordinary handler');
		strictAssertSame(true, $seen, 'expired P without marks reaches correction under claim');
		strictAssertSame(array(), $this->customWritesFor('chk-state'),
			'no ordinary state prewrite follows an unknown correction');
	}

	public function testExpiredPForumCorrectionPrecedesTheRealHandler()
	{
		$this->resetFakes();
		$prepared = false;
		$handlerCalls = 0;
		ruTrackerChecker::registerTracker('/topic\.correction-order\.invalid/',
			'/tracker\.correction-order\.invalid/',
			function ($url) use (&$prepared, &$handlerCalls) {
				$handlerCalls++;
				strictAssertSame(true, $prepared,
					'the handler cannot judge the old forum before its correction');
				return ruTrackerChecker::STE_UPTODATE;
			});
		$dir = sys_get_temp_dir() . '/rut-p-forum-' . bin2hex(random_bytes(4)) . '/';
		mkdir($dir, 0777, true);
		file_put_contents($dir . self::OLD_HASH . '.torrent', 'x');
		rTorrentSettings::get()->session = $dir;
		Torrent::$fixtures[$dir . self::OLD_HASH . '.torrent'] = array(
			'comment' => 'http://topic.correction-order.invalid/1',
			'announce' => 'http://tracker.correction-order.invalid/announce',
		);
		try
		{
			self::queueStateRead(true, false, array((string) ruTrackerChecker::STE_INPROGRESS,
				(string) (time() - ruTrackerChecker::MAX_LOCK_TIME - 2), ''));
			rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_local_id'), true, false, array('', '', self::LOCAL_ID));
			rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array());
			rXMLRPCRequest::queue('d.set_custom|d.set_custom|d.set_custom', true, false, array());
			$prepare = function ($hash) use (&$prepared) {
				strictAssertSame(self::OLD_HASH, $hash, 'preparation uses the claimed hash');
				$prepared = true;
				return true;
			};
			$performed = false;
			strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH,
				ruTrackerChecker::STE_INPROGRESS, time() - ruTrackerChecker::MAX_LOCK_TIME - 2,
				'', $performed, $prepare), 'reclaimed P reaches the real handler');
			strictAssertSame(true, $prepared, 'the correction was prepared');
			strictAssertSame(1, $handlerCalls, 'one handler judged the corrected topic');
			strictAssertSame(true, $performed, 'the committed verdict may acknowledge the correction');
		}
		finally
		{
			strictRemoveTree($dir);
		}
	}

	public function testFreshPDoesNotPrepareTheForumCorrection()
	{
		$this->resetFakes();
		$called = 0;
		self::queueStateRead(true, false, array((string) ruTrackerChecker::STE_INPROGRESS,
			(string) time(), ''));
		rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_local_id'), true, false, array('', '', self::LOCAL_ID));
		$prepare = function () use (&$called) { $called++; return true; };
		$performed = false;
		strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH,
			ruTrackerChecker::STE_INPROGRESS, time(), '', $performed, $prepare),
			'a fresh P remains with its worker');
		strictAssertSame(0, $called, 'no correction is installed while the worker is protected');
		strictAssertSame(array(), $this->customWritesFor('chk-state'), 'no state write crosses fresh P');
	}

	public function testHeldClaimNeverPreparesForumCorrection()
	{
		$this->resetFakes();
		$owner = ruTrackerChecker::claimCheckForWorker(self::OLD_HASH, time());
		strictAssertTrue(is_string($owner), 'fixture holds the per-hash claim');
		$called = 0;
		$prepare = function () use (&$called) { $called++; return true; };
		try
		{
			$performed = false;
			strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH,
				ruTrackerChecker::STE_INPROGRESS, time() - ruTrackerChecker::MAX_LOCK_TIME - 2,
				'', $performed, $prepare), 'the claimed worker owns the P row');
			strictAssertSame(0, $called, 'no correction runs outside the holder claim');
			strictAssertSame(array(), rXMLRPCRequest::$requests, 'the contender sends no daemon request');
		}
		finally
		{
			ruTrackerChecker::releaseCheckForWorker(self::OLD_HASH, $owner);
		}
	}

	public function testExpiredPWithMetadataMarksDefersForumCorrection()
	{
		$this->resetFakes();
		$called = 0;
		self::queueStateRead(true, false, array((string) ruTrackerChecker::STE_INPROGRESS,
			(string) (time() - ruTrackerChecker::MAX_LOCK_TIME - 2), ''));
		rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_local_id'), true, false,
			array(self::NEW_HASH, (string) (time() + 3600), self::LOCAL_ID));
		$prepare = function () use (&$called) { $called++; return true; };
		$performed = false;
		strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH,
			ruTrackerChecker::STE_INPROGRESS, time() - ruTrackerChecker::MAX_LOCK_TIME - 2,
			'', $performed, $prepare), 'owned metadata marks keep the P protected');
		strictAssertSame(0, $called, 'forum correction cannot race the owned metadata generation');
		strictAssertSame(array((string) ruTrackerChecker::STE_META_PENDING), $this->customWritesFor('chk-state'), 'owned marks are promoted to M without an ordinary verdict');
	}

	public function testUnreadablePMetadataMarksRefuseForumCorrectionVisibly()
	{
		$this->resetFakes();
		$called = 0;
		self::queueStateRead(true, false, array((string) ruTrackerChecker::STE_INPROGRESS,
			(string) (time() - ruTrackerChecker::MAX_LOCK_TIME - 2), ''));
		rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_local_id'), false, false, array());
		$prepare = function () use (&$called) { $called++; return true; };
		$performed = false;
		strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH,
			ruTrackerChecker::STE_INPROGRESS, time() - ruTrackerChecker::MAX_LOCK_TIME - 2,
			'', $performed, $prepare), 'unknown mark read cannot authorize the old forum');
		strictAssertSame(0, $called, 'forum correction did not run under uncertain ownership');
		strictAssertSame(array(), $this->customWritesFor('chk-state'), 'the stored P remains retryable');
		strictAssertOneLogMatching(FileUtil::$log, 'chk-meta-new/chk-meta-until generation read unconfirmed',
			'the refusal is operator visible');
	}

	public function testTheInProgressLockIsHonouredWhileFreshAndExpiresWhenStale()
	{
		$this->resetFakes();
		RuTrackerState::save('meta-claims', array());
		self::queueStateRead( true, false,
			array((string) ruTrackerChecker::STE_INPROGRESS, (string) time(), ''));
		rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_local_id'),
			true, false, array('', '', self::LOCAL_ID));

		// Fresh: another worker is inside this check, and nothing is written.
		strictAssertSame(true,
			ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_INPROGRESS, time(), ''),
			'standing down for a live lock is not a failed check');
		strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom'),
			'and the holder\'s state is not written over');

		// Stale: the holder died, so the lock stops meaning "in progress".
		$this->resetFakes();
		RuTrackerState::save('meta-claims', array());
		$dir = sys_get_temp_dir() . '/chk-lockexpiry-' . bin2hex(random_bytes(4)) . '/';
		mkdir($dir, 0777, true);
		rTorrentSettings::get()->session = $dir;
		Torrent::$fixtures[$dir . self::OLD_HASH . '.torrent'] = array(
			'comment' => 'http://topic.lock-test.invalid/1',
			'announce' => 'http://tracker.lock-test.invalid/announce',
		);
		try
		{
			self::queueStateRead( true, false,
				array((string) ruTrackerChecker::STE_INPROGRESS,
					(string) (time() - ruTrackerChecker::MAX_LOCK_TIME - 1), ''));
			rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_local_id'),
				true, false, array('', '', self::LOCAL_ID));
			rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array());
			rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array());

			strictAssertSame(true,
				ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_INPROGRESS,
					time() - ruTrackerChecker::MAX_LOCK_TIME - 1, ''),
				'an expired lock does not wedge the torrent');
			strictAssertTrue(in_array((string) ruTrackerChecker::STE_INPROGRESS,
				$this->customWritesFor('chk-state'), true), 'the check runs and writes its own state');
		}
		finally
		{
			strictRemoveTree($dir);
		}
	}

	// "A claim nobody could write down is not a claim": if the state store
	// cannot persist the claim, the next process reads the same free slot and
	// both go on to the destructive check.
	//
	// The refusal is not contention: no other worker exists, and reporting one
	// made a whole failed scheduler cycle look like normal cooperative locking.
	// A plain file where the state directory belongs makes openShared() fail
	// under root as well, so this branch is deterministic in every test image.
	public function testAClaimThatCouldNotBeWrittenIsRefused()
	{
		$this->resetFakes();
		// A state directory that cannot be created: dir() is a plain file.
		$blocked = sys_get_temp_dir() . '/chk-blocked-' . bin2hex(random_bytes(4));
		file_put_contents($blocked, 'not a directory');
		strictSetPrivateStatic('RuTrackerState', 'dir', $blocked . '/rutracker_check');
		try
		{
			strictAssertSame(null, strictInvoke('ruTrackerChecker', 'claimCheck', array(self::OLD_HASH, 1000)),
				'a storage failure is distinct from a live competing claim');
		}
		finally
		{
			@unlink($blocked);
		}
	}

	public function testRunRetriesAClaimStorageFailureWithoutCallingItContention()
	{
		$this->resetFakes();
		$blocked = sys_get_temp_dir() . '/chk-run-blocked-' . bin2hex(random_bytes(4));
		file_put_contents($blocked, 'not a directory');
		strictSetPrivateStatic('RuTrackerState', 'dir', $blocked . '/rutracker_check');
		try
		{
			$this->withoutDebugLog(function() {
				FileUtil::$log = array();
				strictAssertSame(false,
					ruTrackerChecker::run(self::OLD_HASH, 0, 0, ''),
					'a check whose claim cannot be stored is deferred for retry');
				strictAssertSame(false,
					ruTrackerChecker::run(str_repeat('C', 40), 0, 0, ''),
					'later hashes in the failed cycle are deferred too');
				strictAssertSame(array(), strictLogsMatching(FileUtil::$log, 'already being checked'),
					'a storage failure is never reported as another live worker');
				strictAssertOneLogMatching(FileUtil::$log, 'claim storage is unavailable',
					'the outage is classified once and remains visible with debugging disabled');
			});
		}
		finally
		{
			@unlink($blocked);
		}
	}

	public function testMetaPendingStateCallsPumpInsteadOfInProgress()
	{
		$this->resetFakes();
		RuTrackerMetaFetch::$result = ruTrackerChecker::STE_META_PENDING;
		self::queueStateRead( true, false,
			array((string) ruTrackerChecker::STE_META_PENDING, (string) time(), ''));
		rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array()); // the claim
		rXMLRPCRequest::queue('d.get_custom', true, false, array(''));
		rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array()); // the verdict

		$result = ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_META_PENDING, time(), '');

		strictAssertSame(true, $result, 'a still-pending pump keeps the check successful');
		strictAssertSame(1, count(RuTrackerMetaFetch::$calls), 'run must hand the meta-pending state to pump exactly once');
		strictAssertSame(self::OLD_HASH, RuTrackerMetaFetch::$calls[0]['hash'], 'pump must be called with the torrent hash');
		strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.get_custom')), 'pump must reach the XMLRPC layer, not a no-op stub');
		// The durable META_PENDING state survives a worker crash during pump().
		// The per-hash claim already excludes concurrent workers.
		$stateWrites = $this->customWritesFor('chk-state');
		strictAssertSame(array((string) ruTrackerChecker::STE_META_PENDING), $stateWrites,
			'a failed or interrupted pump keeps the durable generation retryable');
	}

	public function testIgnoredMetaPendingRetriesBeforeApplyingIgnore()
	{
		$this->resetFakes();
		$saved = isset($GLOBALS['ignoreLabels']) ? $GLOBALS['ignoreLabels'] : null;
		$GLOBALS['ignoreLabels'] = array('tv-sonarr');
		try
		{
			// First pump could not confirm clearMarks(). A killed worker must
			// leave META_PENDING, so the next invocation still reaches pump().
			RuTrackerMetaFetch::$result = ruTrackerChecker::STE_META_PENDING;
			self::queueStateRead(true, false,
				array((string) ruTrackerChecker::STE_META_PENDING, (string) time(), 'tv-sonarr'));
			rXMLRPCRequest::queue('d.get_custom', true, false, array(self::NEW_HASH));
			strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH),
				'unconfirmed clear keeps the check retryable');
			strictAssertSame(1, count(RuTrackerMetaFetch::$calls), 'ignored pending generation was pumped');
			strictAssertSame(array((string) ruTrackerChecker::STE_META_PENDING),
				$this->customWritesFor('chk-state'), 'no ignored or in-progress gap can strand the generation');
			strictAssertSame(array(), RuTrackerState::load('meta-claims'), 'claim released for retry');

			rXMLRPCRequest::reset();
			RuTrackerMetaFetch::$result = ruTrackerChecker::STE_ERROR;
			self::queueStateRead(true, false,
				array((string) ruTrackerChecker::STE_META_PENDING, (string) time(), 'tv-sonarr'));
			rXMLRPCRequest::queue('d.get_custom', true, false, array(''));
			strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH),
				'after the exact generation retires, ignore applies');
			strictAssertSame(2, count(RuTrackerMetaFetch::$calls), 'retry pumps before ignore');
			strictAssertSame(array((string) ruTrackerChecker::STE_IGNORED),
				$this->customWritesFor('chk-state'), 'only the final ignored verdict is written');
			strictAssertSame(array(''), $this->customWritesFor('chk-msg'), 'old message is cleared');
		}
		finally
		{
			if($saved === null) unset($GLOBALS['ignoreLabels']);
			else $GLOBALS['ignoreLabels'] = $saved;
		}
	}

	public function testLateMetadataMarkCallbackCannotLoseFinalVerdictRace()
	{
		$this->resetFakes();
		RuTrackerMetaFetch::$result = ruTrackerChecker::STE_ERROR;
		self::queueStateRead(true, false,
			array((string) ruTrackerChecker::STE_META_PENDING, (string) time(), ''));
		rXMLRPCRequest::queue('d.get_custom', true, false, array(''));
		$daemon = array('state' => ruTrackerChecker::STE_META_PENDING,
			'new' => '', 'until' => '');
		rXMLRPCRequest::queue('branch', true, false, function($commands) use (&$daemon) {
			// The earlier mark callback lands after pump saw empty marks, just
			// before the final verdict reaches rTorrent's one-command branch.
			$daemon['new'] = self::NEW_HASH;
			$daemon['until'] = '9999999999';
			$daemon['state'] = ruTrackerChecker::STE_META_PENDING;
			$condition = $commands[0]->params[1];
			$guarded = self::branchGuardsEmptyMeta($condition,
				ruTrackerChecker::STE_META_PENDING);
			if($guarded) return array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED);
			$daemon['state'] = ruTrackerChecker::STE_ERROR;
			return array(RuTrackerAtomicOwnership::SENTINEL_ACTED);
		});
		strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH),
			'a late callback makes the final verdict defer to the durable M generation');
		strictAssertSame(ruTrackerChecker::STE_META_PENDING, $daemon['state'],
			'the final verdict cannot overwrite marks published by the callback');
		strictAssertSame(self::NEW_HASH, $daemon['new'], 'the callback retains its service hash');
		strictAssertSame('9999999999', $daemon['until'], 'the callback retains its deadline');
	}

	public function testExpiredInProgressEmptyMarksCannotOverwriteLateMetadataClaim()
	{
		foreach(array('before prewrite', 'before final') as $cut)
		{
			$this->resetFakes();
			$daemon = array('state' => ruTrackerChecker::STE_INPROGRESS,
				'new' => '', 'until' => '');
			self::queueStateRead(true, false,
				array((string) ruTrackerChecker::STE_INPROGRESS,
					(string) (time() - ruTrackerChecker::MAX_LOCK_TIME - 1), ''));
			rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_local_id'),
				true, false, array('', '', self::LOCAL_ID));
			if($cut === 'before final')
				rXMLRPCRequest::queue('branch', true, false,
					array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
			rXMLRPCRequest::queue('branch', true, false, function($commands) use (&$daemon, $cut) {
				$daemon['state'] = ruTrackerChecker::STE_META_PENDING;
				$daemon['new'] = self::NEW_HASH;
				$daemon['until'] = '9999999999';
				$condition = $commands[0]->params[1];
				$guarded = self::branchGuardsEmptyMeta($condition,
					ruTrackerChecker::STE_INPROGRESS);
				if($guarded) return array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED);
				$daemon['state'] = $cut === 'before prewrite'
					? ruTrackerChecker::STE_INPROGRESS : ruTrackerChecker::STE_ERROR;
				return array(RuTrackerAtomicOwnership::SENTINEL_ACTED);
			});
			strictAssertSame($cut === 'before prewrite' ? false : true,
				ruTrackerChecker::run(self::OLD_HASH),
				$cut . ': the recovered P run must not overwrite the late callback');
			strictAssertSame(ruTrackerChecker::STE_META_PENDING, $daemon['state'],
				$cut . ': the M state survives the guarded write');
			strictAssertSame(self::NEW_HASH, $daemon['new'], $cut . ': owned hash survives');
			strictAssertSame('9999999999', $daemon['until'], $cut . ': owned deadline survives');
		}
	}

	public function testIgnoredInProgressEmptyMarksCannotOverwriteLateMetadataClaim()
	{
		$this->resetFakes();
		$saved = isset($GLOBALS['ignoreLabels']) ? $GLOBALS['ignoreLabels'] : null;
		$GLOBALS['ignoreLabels'] = array('tv-sonarr');
		try
		{
			$daemonState = ruTrackerChecker::STE_INPROGRESS;
			self::queueStateRead(true, false,
				array((string) ruTrackerChecker::STE_INPROGRESS, (string) time(), 'tv-sonarr'));
			rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_local_id'),
				true, false, array('', '', self::LOCAL_ID));
			rXMLRPCRequest::queue('branch', true, false, function($commands) use (&$daemonState) {
				$daemonState = ruTrackerChecker::STE_META_PENDING;
				$condition = $commands[0]->params[1];
				if(self::branchGuardsEmptyMeta($condition,
					ruTrackerChecker::STE_INPROGRESS))
					return array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED);
				$daemonState = ruTrackerChecker::STE_IGNORED;
				return array(RuTrackerAtomicOwnership::SENTINEL_ACTED);
			});
			strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH),
				'ignore defers when an earlier worker publishes M during its final branch');
			strictAssertSame(ruTrackerChecker::STE_META_PENDING, $daemonState,
				'ignore cannot strand the late metadata claim');
		}
		finally
		{
			if($saved === null) unset($GLOBALS['ignoreLabels']);
			else $GLOBALS['ignoreLabels'] = $saved;
		}
	}

	public function testFreshInProgressWithOwnedMarksDoesNotPumpEvenWithoutClaim()
	{
		$this->resetFakes();
		$saved = isset($GLOBALS['ignoreLabels']) ? $GLOBALS['ignoreLabels'] : null;
		$GLOBALS['ignoreLabels'] = array('tv-sonarr');
		try
		{
			self::queueStateRead(true, false,
				array((string) ruTrackerChecker::STE_INPROGRESS, (string) time(), 'tv-sonarr'));
			rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_local_id'),
				true, false, array(self::NEW_HASH, '9999999999', self::LOCAL_ID));
			strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH),
				'a fresh P is still owned by its worker even if its claim record has gone');
			strictAssertSame(0, count(RuTrackerMetaFetch::$calls),
				'a second worker never pumps fresh owned marks');
			strictAssertSame(array(), $this->customWritesFor('chk-state'),
				'neither M nor IGNORED overwrites the fresh owner');
		}
		finally
		{
			if($saved === null) unset($GLOBALS['ignoreLabels']);
			else $GLOBALS['ignoreLabels'] = $saved;
		}
	}

	public function testLegacyInProgressWithOwnedMarksPumpsBeforeIgnore()
	{
		$this->resetFakes();
		$saved = isset($GLOBALS['ignoreLabels']) ? $GLOBALS['ignoreLabels'] : null;
		$GLOBALS['ignoreLabels'] = array('tv-sonarr');
		try
		{
			RuTrackerMetaFetch::$result = ruTrackerChecker::STE_META_PENDING;
			self::queueStateRead(true, false,
				array((string) ruTrackerChecker::STE_INPROGRESS,
					(string) (time() - ruTrackerChecker::MAX_LOCK_TIME - 1), 'tv-sonarr'));
			rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_local_id'),
				true, false, array(self::NEW_HASH, '9999999999', self::LOCAL_ID));
			rXMLRPCRequest::queue('d.get_custom', true, false, array(self::NEW_HASH));
			strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH),
				'legacy P with owned marks stays retryable under its claim');
			strictAssertSame(1, count(RuTrackerMetaFetch::$calls),
				'owned marks are pumped before the ignored verdict can erase the predecessor state');
			strictAssertSame(array((string) ruTrackerChecker::STE_META_PENDING),
				$this->customWritesFor('chk-state'), 'only M is persisted for this generation');
		}
		finally
		{
			if($saved === null) unset($GLOBALS['ignoreLabels']);
			else $GLOBALS['ignoreLabels'] = $saved;
		}
	}

	public function testOrdinaryInProgressWithoutMarksStillHonoursIgnore()
	{
		$this->resetFakes();
		$saved = isset($GLOBALS['ignoreLabels']) ? $GLOBALS['ignoreLabels'] : null;
		$GLOBALS['ignoreLabels'] = array('tv-sonarr');
		try
		{
			self::queueStateRead(true, false,
				array((string) ruTrackerChecker::STE_INPROGRESS, (string) time(), 'tv-sonarr'));
			rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_local_id'),
				true, false, array('', '', self::LOCAL_ID));
			strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH),
				'ordinary P with no generation still applies T02 ignore');
			strictAssertSame(0, count(RuTrackerMetaFetch::$calls), 'no metadata pump without marks');
			strictAssertSame(array((string) ruTrackerChecker::STE_IGNORED),
				$this->customWritesFor('chk-state'), 'the ignored verdict is confirmed');
		}
		finally
		{
			if($saved === null) unset($GLOBALS['ignoreLabels']);
			else $GLOBALS['ignoreLabels'] = $saved;
		}
	}

	public function testUnprovedInProgressMarksCannotBecomeIgnored()
	{
		foreach(array(
			'unreadable' => array(false, false, array()),
			'foreign-generation' => array(true, false,
				array(self::NEW_HASH, '9999999999', str_repeat('2', 40))),
		) as $case => $reply)
		{
			$this->resetFakes();
			$saved = isset($GLOBALS['ignoreLabels']) ? $GLOBALS['ignoreLabels'] : null;
			$GLOBALS['ignoreLabels'] = array('tv-sonarr');
			try
			{
				self::queueStateRead(true, false,
					array((string) ruTrackerChecker::STE_INPROGRESS, (string) time(), 'tv-sonarr'));
				rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_local_id'),
					$reply[0], $reply[1], $reply[2]);
				strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH),
					$case . ': unknown or foreign marks defer the ignored verdict');
				strictAssertSame(0, count(RuTrackerMetaFetch::$calls),
					$case . ': no unsafe pump');
				strictAssertSame(array(), $this->customWritesFor('chk-state'),
					$case . ': no state change on unproved generation');
				strictAssertOneLogMatching(FileUtil::$log,
					'chk-meta-new/chk-meta-until generation read unconfirmed',
					$case . ': refusal is visible with debugging disabled');
			}
			finally
			{
				if($saved === null) unset($GLOBALS['ignoreLabels']);
				else $GLOBALS['ignoreLabels'] = $saved;
			}
		}
	}

	public function testRetiredMetaMarksRetryWhenFinalVerdictWriteIsUnconfirmed()
	{
		$saved = isset($GLOBALS['ignoreLabels']) ? $GLOBALS['ignoreLabels'] : null;
		$GLOBALS['ignoreLabels'] = array('tv-sonarr');
		try
		{
			foreach(array('ordinary' => '', 'ignored' => 'tv-sonarr') as $case => $label)
			{
				$this->resetFakes();
				RuTrackerMetaFetch::$result = ruTrackerChecker::STE_ERROR; // exact marks retired
				self::queueStateRead(true, false,
					array((string) ruTrackerChecker::STE_META_PENDING, (string) time(), $label));
				rXMLRPCRequest::queue('d.get_custom', true, false, array(''));
				rXMLRPCRequest::queue('branch', true, false,
					array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED));
				strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH),
					$case . ': unconfirmed final write is a failed check');
				strictAssertSame(array(), $this->customWritesFor('chk-state'),
					$case . ': no final state was confirmed');
				strictAssertOneLogMatching(FileUtil::$log, 'metafetch-final-verdict-unconfirmed',
					$case . ': the refused final write is visible without debug');
				strictAssertSame(array(), RuTrackerState::load('meta-claims'),
					$case . ': claim released for recovery');

				// After worker death the daemon may still hold M with both marks
				// empty. MetaFetchTest proves that exact projection returns ERROR.
				rXMLRPCRequest::reset();
				self::queueStateRead(true, false,
					array((string) ruTrackerChecker::STE_META_PENDING, (string) time(), $label));
				rXMLRPCRequest::queue('d.get_custom', true, false, array(''));
				strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH),
					$case . ': retry leaves the retired generation behind');
				strictAssertSame(array((string) ($label === ''
					? ruTrackerChecker::STE_ERROR : ruTrackerChecker::STE_IGNORED)),
					$this->customWritesFor('chk-state'),
					$case . ': final verdict is confirmed on retry');
			}
		}
		finally
		{
			if($saved === null) unset($GLOBALS['ignoreLabels']);
			else $GLOBALS['ignoreLabels'] = $saved;
		}
	}

	public function testMetaPendingCompletedReplacementSkipsStateWrite()
	{
		$this->resetFakes();
		RuTrackerMetaFetch::$result = null; // createTorrent success: state already set by its own load additions
		self::queueStateRead( true, false,
			array((string) ruTrackerChecker::STE_META_PENDING, (string) time(), ''));
		rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array()); // the claim
		rXMLRPCRequest::queue('d.get_custom', true, false, array(''));

		$result = ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_META_PENDING, time(), '');

		strictAssertSame(true, $result, 'a completed replacement is a successful check');
		strictAssertSame(array(), $this->customWritesFor('chk-state'),
			'after a successful replacement the old hash is gone, so no verdict follows it');
	}

	public function testSetMessageWritesChkMsgCustom()
	{
		$this->resetFakes();
		rXMLRPCRequest::queue('d.set_custom', true, false, array());

		$token = ruTrackerChecker::CHKMSG_DELETING . '|2/3';
		strictAssertTrue(
			ruTrackerChecker::setMessage(str_repeat('A', 40), $token),
			'setMessage should succeed'
		);
		$requests = rXMLRPCRequest::requestsFor('d.set_custom');
		strictAssertSame(1, count($requests), 'one write request');
		strictAssertSame(
			array(str_repeat('A', 40), 'chk-msg', $token),
			$requests[0]['commands'][0]->params,
			'params'
		);
	}

	// chk-msg is a token, never prose: the sentence is localised in the
	// browser (init.js + theUILang.chkMessages), so the vocabulary itself is
	// part of check.php's contract with every writer.
	public function testChkMessageTokensAreDistinctBareIdentifiers()
	{
		$tokens = array(
			ruTrackerChecker::CHKMSG_SUPERSEDED,
			ruTrackerChecker::CHKMSG_DELETING,
			ruTrackerChecker::CHKMSG_TOPIC_STATUS,
			ruTrackerChecker::CHKMSG_FUSE,
			ruTrackerChecker::CHKMSG_ABSORBED,
		);
		strictAssertSame(count($tokens), count(array_unique($tokens)), 'every token is distinct');
		foreach($tokens as $token)
			strictAssertTrue(
				preg_match('/^[a-z][a-z-]*$/', $token) === 1,
				'a token carries no separator and no prose: ' . $token
			);
	}

	public function testDispatchPrefersCommentMatchesAndFallsBackToAnnounce()
	{
		$rows = array(
			'comment match' => array(
				'fixture' => array(
					'hash' => self::OLD_HASH,
					'info' => array('name' => 'file.mkv'),
					'announce' => 'http://unrelated.invalid/announce',
					'comment' => 'https://topic.comment-test.invalid/view?id=42',
				),
				'trackers' => array(
					array('/topic\.comment-test\.invalid/', '/tracker\.comment-test\.invalid/', 'comment-test'),
				),
				'expect' => array('comment-test', 'https://topic.comment-test.invalid/view?id=42'),
			),
			'announce fallback' => array(
				'fixture' => array(
					'hash' => self::OLD_HASH,
					'info' => array('name' => 'file.mkv'),
					'announce' => 'http://tracker.announce-test.invalid/announce',
					'comment' => 'no topic URL here',
				),
				'trackers' => array(
					array('/topic\.announce-test\.invalid/', '/tracker\.announce-test\.invalid/', 'announce-test'),
				),
				'expect' => array('announce-test', 'http://tracker.announce-test.invalid/announce'),
			),
			'comment priority across handlers' => array(
				'fixture' => array(
					'hash' => self::OLD_HASH,
					'info' => array('name' => 'file.mkv'),
					'announce' => 'http://tracker.first-priority.invalid/announce',
					'comment' => 'https://topic.second-priority.invalid/view?id=42',
				),
				'trackers' => array(
					array('/topic\.first-priority\.invalid/', '/tracker\.first-priority\.invalid/', 'first'),
					array('/topic\.second-priority\.invalid/', '/tracker\.second-priority\.invalid/', 'second'),
				),
				'expect' => array('second', 'https://topic.second-priority.invalid/view?id=42'),
			),
			'announce-list flattening' => array(
				'fixture' => array(
					'hash' => self::OLD_HASH,
					'info' => array('name' => 'file.mkv'),
					'announce' => 'http://unrelated.invalid/announce',
					'announce_list' => array(
						array('http://unrelated-two.invalid/announce'),
						array('http://tracker.list-test.invalid/announce'),
					),
					'comment' => 'no topic URL here',
				),
				'trackers' => array(
					array('/topic\.list-test\.invalid/', '/tracker\.list-test\.invalid/', 'list-test'),
				),
				'expect' => array('list-test', 'http://tracker.list-test.invalid/announce'),
			),
		);

		foreach($rows as $label => $row)
		{
			$this->resetFakes();
			Torrent::$fixtures['dispatch'] = $row['fixture'];
			$calls = array();
			foreach($row['trackers'] as $tracker)
			{
				list($commentFilter, $announceFilter, $id) = $tracker;
				ruTrackerChecker::registerTracker($commentFilter, $announceFilter, function($url) use (&$calls, $id) {
					$calls[] = array($id, $url);
					return ruTrackerChecker::STE_UPTODATE;
				});
				strictAssertTrue(
					in_array($announceFilter, ruTrackerChecker::supportedTrackers(), true),
					$label . ': supportedTrackers must expose the registered announce filter'
				);
			}

			strictAssertSame(
				ruTrackerChecker::STE_UPTODATE,
				ruTrackerChecker::run_ex(self::OLD_HASH, 'dispatch'),
				$label . ': the matching handler result must be returned'
			);
			strictAssertSame(
				array(array($row['expect'][0], $row['expect'][1])),
				$calls,
				$label . ': exactly the expected handler must run with the matched URL'
			);
		}
	}

	public function testProductionLoadWaitBudgetIsExplicitDespiteTheLocalDelayOverride()
	{
		$source = file_get_contents(testFindRepoRoot() . '/plugins/rutracker_check/check.php');
		strictAssertSame(1, preg_match('/const\s+LOAD_WAIT_ATTEMPTS\s*=\s*40\s*;/', $source),
			'the shipped load wait attempts remain 40, regardless of the short test delay');
		strictAssertSame(1, preg_match('/if\s*\(!defined\(\x27RUTRACKER_CHECK_LOAD_WAIT_DELAY_US\x27\)\)\s*define\(\x27RUTRACKER_CHECK_LOAD_WAIT_DELAY_US\x27,\s*50000\);/', $source),
			'the shipped poll delay defaults to 50 ms while allowing a test override');
		strictAssertSame(1, preg_match('/usleep\(RUTRACKER_CHECK_LOAD_WAIT_DELAY_US\)/', $source),
			'waitForLoad uses the configured interval, not a hard-coded delay');
	}

	private function withVerdictSession($slug, $previous, $checkedAt, $handler, $callback, $storedMessage = '')
	{
		$this->resetFakes();
		$dir = sys_get_temp_dir() . '/rut-' . $slug . '-' . bin2hex(random_bytes(5)) . '/';
		if (!mkdir($dir, 0777, true))
			throw new RuntimeException('Unable to create checker session directory: ' . $dir);
		try
		{
			$file = $dir . self::OLD_HASH . '.torrent';
			file_put_contents($file, 'x');
			rTorrentSettings::get()->session = $dir;
			$host = $slug . '-test.invalid';
			Torrent::$fixtures[$file] = array(
				'comment' => 'http://topic.' . $host . '/1',
				'announce' => 'http://tracker.' . $host . '/announce',
			);
			ruTrackerChecker::registerTracker('/topic\.' . preg_quote($host, '/') . '/',
				'/tracker\.' . preg_quote($host, '/') . '/', $handler);
			$initialState = $previous === 0 && $checkedAt === '' ? '' : (string) $previous;
			self::queueStateRead( true, false,
				array($initialState, (string) $checkedAt, '', self::LOCAL_ID, $storedMessage));
			return $callback();
		}
		finally
		{
			strictRemoveTree($dir);
		}
	}

	private function customWritesFor($field)
	{
		$writes = array();
		foreach (rXMLRPCRequest::$requests as $request)
			foreach ($request['commands'] as $command)
			{
				if ($command->command === getCmd('d.set_custom') && $command->params[1] === $field)
					$writes[] = $command->params[2];
				if ($command->command !== 'branch'
					|| ($request['values'][0] ?? null) !== RuTrackerAtomicOwnership::SENTINEL_ACTED)
					continue;
				// Decode the simple state/message projections this suite asserts.
				// A skipped daemon branch has no writes to count.
				$body = str_replace('\\"', '"', $command->params[2]);
				if (preg_match_all('~\$' . preg_quote(getCmd('d.set_custom='), '~')
					. '(chk-[a-z]+),"([^"]*)"~', $body, $matches, PREG_SET_ORDER))
					foreach ($matches as $match)
						if ($match[1] === $field) $writes[] = $match[2];
			}
		return $writes;
	}

	// A manual worker can keep running after the browser erases and reloads the
	// same info hash. Its final verdict belongs to the original daemon object.
	public function testManualVerdictDoesNotWriteAReaddedTorrentAfterTheHandler()
	{
		$originalId = str_repeat('1', 40);
		$currentId = $originalId;
		$this->withVerdictSession('manual-readd', ruTrackerChecker::STE_UPTODATE, 123,
			function() use (&$currentId) {
				$currentId = str_repeat('2', 40); // erase + load.raw_start during run_ex()
				return ruTrackerChecker::STE_UNCHANGED;
			},
			function() use (&$currentId, $originalId) {
				// The old implementation writes both projections directly by hash.
				rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array());
				rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array());
				rXMLRPCRequest::queue('branch', true, false, function() use (&$currentId, $originalId) {
					return array($currentId === $originalId
						? RuTrackerAtomicOwnership::SENTINEL_ACTED
						: RuTrackerAtomicOwnership::SENTINEL_SKIPPED);
				});
				rXMLRPCRequest::queue('branch', true, false, function() use (&$currentId, $originalId) {
					return array($currentId === $originalId
						? RuTrackerAtomicOwnership::SENTINEL_ACTED
						: RuTrackerAtomicOwnership::SENTINEL_SKIPPED);
				});
				$performed = null;
				strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH,
					ruTrackerChecker::STE_UPTODATE, 123, '', $performed),
					'the manual checker returns after a stale unchanged answer');
				$branches = rXMLRPCRequest::requestsFor('branch');
				strictAssertSame(2, count($branches),
					'the preflight and final projection must be conditional on the original local id');
				foreach($branches as $branch)
				{
					$condition = $branch['commands'][0]->params[1];
					strictAssertTrue(strpos($condition, getCmd('d.get_local_id=')) !== false
						&& strpos($condition, $originalId) !== false,
						'each daemon branch compares the original local id before writing');
				}
				strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
					'no old verdict may be sent directly by hash after the re-add');
				strictAssertSame(false, $performed, 'a skipped final write consumes no correction');
			});
	}

	public function testManualHandlerMessageDoesNotTouchAReaddedTorrent()
	{
		$currentId = self::LOCAL_ID;
		$messageResult = null;
		$this->withVerdictSession('manual-message', ruTrackerChecker::STE_UPTODATE, 123,
			function() use (&$currentId, &$messageResult) {
				$currentId = str_repeat('2', 40);
				$messageResult = ruTrackerChecker::setMessage(self::OLD_HASH,
					ruTrackerChecker::CHKMSG_FUSE . '|old');
				return ruTrackerChecker::STE_UNCHANGED;
			},
			function() use (&$messageResult, &$currentId) {
				rXMLRPCRequest::queue('branch', true, false,
					array(RuTrackerAtomicOwnership::SENTINEL_ACTED)); // preflight
				rXMLRPCRequest::queue('branch', true, false, function() use (&$currentId) {
					return array($currentId === self::LOCAL_ID
						? RuTrackerAtomicOwnership::SENTINEL_ACTED
						: RuTrackerAtomicOwnership::SENTINEL_SKIPPED);
				});
				rXMLRPCRequest::queue('branch', true, false,
					array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED)); // final restore
				rXMLRPCRequest::queue('d.set_custom', true, false, array()); // old path
				ruTrackerChecker::run(self::OLD_HASH);
				strictAssertSame(false, $messageResult,
					'a handler message is refused when its daemon generation has changed');
				strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.set_custom'),
					'the old message is never sent directly by hash');
			});
	}

	public function testManualHandlerTopicAndDeletionWritesDoNotTouchAReaddedTorrent()
	{
		foreach(array('chk-topic' => '42', 'chk-del' => '1') as $field => $value)
		{
			$currentId = self::LOCAL_ID;
			$writeResult = null;
			$this->withVerdictSession('manual-' . $field, ruTrackerChecker::STE_UPTODATE, 123,
				function() use (&$currentId, &$writeResult, $field, $value) {
					$currentId = str_repeat('2', 40);
					$writeResult = ruTrackerChecker::writeHandlerCustom(self::OLD_HASH, $field, $value);
					return ruTrackerChecker::STE_UNCHANGED;
				},
				function() use (&$writeResult, &$currentId, $field) {
					rXMLRPCRequest::queue('branch', true, false,
						array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
					rXMLRPCRequest::queue('branch', true, false, function() use (&$currentId) {
						return array($currentId === self::LOCAL_ID
							? RuTrackerAtomicOwnership::SENTINEL_ACTED
							: RuTrackerAtomicOwnership::SENTINEL_SKIPPED);
					});
					rXMLRPCRequest::queue('branch', true, false,
						array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED));
					rXMLRPCRequest::queue('d.set_custom', true, false, array());
					ruTrackerChecker::run(self::OLD_HASH);
					strictAssertSame(false, $writeResult, $field . ' write is refused for the new local id');
					strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.set_custom'),
						$field . ' is never written directly by hash');
					strictAssertSame(3, count(rXMLRPCRequest::requestsFor('branch')),
						'preflight, handler custom and final state each use a guarded branch');
				});
		}
	}

	// A handler that answers STE_UNCHANGED has no data to judge by -- layer 1
	// calls that 'cold', which is the normal answer for a stopped torrent whose
	// tracker counters are still at zero. run() must then put back the verdict
	// the torrent already carried instead of publishing the STE_INPROGRESS lock
	// it wrote before dispatching, and instead of the error that lock decays to.
	public function testUnchangedVerdictRestoresThePreviousState()
	{
		$rows = array(
			'a stored verdict is put back' => array(
				'previous' => ruTrackerChecker::STE_UPTODATE,
				'checkedAt' => time() - 3600,
				'expect'   => (string) ruTrackerChecker::STE_UPTODATE,
			),
			'a torrent that was never checked stays unchecked' => array(
				'previous' => 0,
				'checkedAt' => '',
				'expect'   => '0',
			),
		);

		foreach($rows as $label => $row)
		{
			$priorTime = $row['checkedAt'];
			$this->withVerdictSession('cold', $row['previous'], $priorTime,
				function($url) { return ruTrackerChecker::STE_UNCHANGED; },
				function() use ($row, $label, $priorTime) {
					rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array()); // the INPROGRESS lock
					rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array()); // the restore
					rXMLRPCRequest::queue('d.set_custom|d.set_custom|d.set_custom', true, false, array()); // if it restores UPTODATE

					$performed = null;
					$result = ruTrackerChecker::run(self::OLD_HASH, $row['previous'], time(), '', $performed);

					strictAssertSame(true, $result, $label . ': an unchanged verdict is not a failed check');
					strictAssertSame(false, $performed,
						$label . ': an unchanged handler answer did not durably consume correction work');
					$writes = $this->customWritesFor('chk-state');
					strictAssertSame(
						array((string) ruTrackerChecker::STE_INPROGRESS, $row['expect']),
						$writes,
						$label . ': the lock is written, then the previous verdict is put back'
					);
					$timeWrites = $this->customWritesFor('chk-time');
					$successTimeWrites = $this->customWritesFor('chk-stime');
					strictAssertSame($priorTime > 0 ? (string) $priorTime : '', end($timeWrites),
						$label . ': no-answer restores the original check clock');
					strictAssertSame(array(), $successTimeWrites,
						$label . ': no-answer cannot stamp successful-check time');
			});
		}
	}

	public function testH05NoEnabledRowRetainsTerminalStateClockAndMessageInRealRun()
	{
		// The handler class is real; skip announce.php's application bootstrap,
		// which this request-free layer-1 branch never reaches.
		if(!class_exists('RuTrackerCheckImpl', false))
		{
			require_once(testFindRepoRoot() . '/plugins/rutracker_check/detector.php');
			eval(loadClassDefinition(testFindRepoRoot()
				. '/plugins/rutracker_check/trackers/rutracker.php', 'RuTrackerCheckImpl'));
		}
		$past = time() - 700000;
		$oldMessage = ruTrackerChecker::CHKMSG_TOPIC_STATUS . '|4';
		foreach (array('missing' => array(0, ''),
			'disabled' => array(1, 'http://bt.t-ru.org/ann?pk=x', 0, 6, 0, '')) as $label => $reply)
		{
			$this->withVerdictSession('h05-' . $label, ruTrackerChecker::STE_DELETED, $past,
				function($url, $hash, $torrent) {
					return RuTrackerCheckImpl::download_torrent(
						'https://rutracker.org/forum/viewtopic.php?t=42', $hash, $torrent);
				},
				function() use ($reply, $past, $oldMessage, $label) {
					rXMLRPCRequest::queue('d.get_custom', true, false, array($oldMessage));
					rXMLRPCRequest::queue('d.get_custom', true, false, array('42'));
					rXMLRPCRequest::queue(
						'd.get_tracker_size|t.multicall|d.get_message', true, false, $reply);
					$performed = null;
					strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH,
						ruTrackerChecker::STE_DELETED, $past, '', $performed),
						$label . ': no signal completes without a new verdict');
					strictAssertSame(false, $performed, $label . ': no correction was consumed');
					strictAssertSame(array((string) ruTrackerChecker::STE_DELETED),
						$this->customWritesFor('chk-state'), $label . ': terminal verdict stays');
					strictAssertSame(array(), $this->customWritesFor('chk-time'),
						$label . ': terminal clock stays');
					strictAssertSame(array(), $this->customWritesFor('chk-msg'),
						$label . ': terminal explanation stays');
				});
		}
	}

	public function testH07MissingRetryCannotOverwriteLateMetadataClaim()
	{
		$past = time() - ruTrackerChecker::MAX_LOCK_TIME - 1;
		$marker = ruTrackerChecker::CHKMSG_SUCCESSOR_MISSING . '|' . self::NEW_HASH;
		$daemon = array('state' => ruTrackerChecker::STE_INPROGRESS,
			'new' => '', 'until' => '');
		$this->withVerdictSession('h07-late-meta', ruTrackerChecker::STE_INPROGRESS,
			$past, function() {
				ruTrackerChecker::setMessage(self::OLD_HASH, '');
				return ruTrackerChecker::STE_CANT_REACH_TRACKER;
			}, function() use (&$daemon, $marker) {
				rXMLRPCRequest::queue('d.get_custom|d.get_custom|d.get_local_id',
					true, false, array('', '', self::LOCAL_ID));
				rXMLRPCRequest::queue('d.get_custom|d.get_local_id', true, false,
					array($marker, self::LOCAL_ID));
				rXMLRPCRequest::queue('branch', true, false,
					array(RuTrackerAtomicOwnership::SENTINEL_ACTED)); // guarded preflight
				rXMLRPCRequest::queue('branch', true, false,
					function($commands) use (&$daemon) {
						$daemon['state'] = ruTrackerChecker::STE_META_PENDING;
						$daemon['new'] = self::NEW_HASH;
						$daemon['until'] = '9999999999';
						if(self::branchGuardsEmptyMeta($commands[0]->params[1],
							ruTrackerChecker::STE_INPROGRESS))
							return array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED);
						$daemon['state'] = ruTrackerChecker::STE_ERROR;
						return array(RuTrackerAtomicOwnership::SENTINEL_ACTED);
					});
				strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH),
					'the retry stays pending after a late metadata claim');
				strictAssertSame(ruTrackerChecker::STE_META_PENDING, $daemon['state'],
					'the H07 ERROR projection must obey the empty-P metadata guard');
				strictAssertSame(self::NEW_HASH, $daemon['new'],
					'the late callback keeps its metadata service hash');
			}, $marker);
	}

	public function testH07PersistedMarkerKeepsOldClockWhenHandlerClearsMessageOnTransportFailure()
	{
		$past = time() - 700000;
		$marker = ruTrackerChecker::CHKMSG_SUCCESSOR_MISSING . '|' . self::NEW_HASH;
		$this->withVerdictSession('h07-persisted-transport', ruTrackerChecker::STE_ERROR,
			$past, function() {
				ruTrackerChecker::setMessage(self::OLD_HASH, '');
				return ruTrackerChecker::STE_CANT_REACH_TRACKER;
			}, function() use ($past, $marker) {
				rXMLRPCRequest::queue('d.get_custom|d.get_local_id', true, false,
					array($marker, self::LOCAL_ID));
				strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH),
					'the transport failure remains retryable');
				$timeWrites = $this->customWritesFor('chk-time');
				strictAssertSame((string) $past, end($timeWrites),
					'the persisted marker preserves the original retry clock');
				$messageWrites = $this->customWritesFor('chk-msg');
				strictAssertSame($marker, end($messageWrites),
					'the transient handler clear cannot erase the persisted marker');
			}, $marker);
	}

	public function testH07FailedPreflightReleasesPersistedMarkerMessageBuffer()
	{
		$marker = ruTrackerChecker::CHKMSG_SUCCESSOR_MISSING . '|' . self::NEW_HASH;
		$this->withVerdictSession('h07-preflight-cleanup', ruTrackerChecker::STE_ERROR,
			time() - 700000, function() {
				throw new RuntimeException('failed preflight must stop before handler');
			}, function() use ($marker) {
				rXMLRPCRequest::queue('d.get_custom|d.get_local_id', true, false,
					array($marker, self::LOCAL_ID));
				rXMLRPCRequest::queue('branch', false, false, array());
				rXMLRPCRequest::queue('d.get_custom|d.get_custom|d.get_local_id',
					false, false, array());
				rXMLRPCRequest::queue('d.hash', true, false, array(self::OLD_HASH));
				strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH),
					'unconfirmed preflight defers the check');
				strictAssertSame(null, strictGetPrivateStatic('ruTrackerChecker', 'terminalMessageHash'),
					'the failed preflight releases its process-local message buffer');
				rXMLRPCRequest::queue('d.set_custom', true, false, array());
				strictAssertSame(true, ruTrackerChecker::setMessage(self::OLD_HASH, 'later'),
					'a later independent message write reaches the daemon');
				strictAssertSame(array('later'), $this->customWritesFor('chk-msg'),
					'the failed preflight cannot absorb a later write');
			}, $marker);
	}

	public function testH07EarlyReadFailureReleasesPersistedMarkerMessageBuffer()
	{
		$marker = ruTrackerChecker::CHKMSG_SUCCESSOR_MISSING . '|' . self::NEW_HASH;
		$this->withVerdictSession('h07-buffer-cleanup', ruTrackerChecker::STE_NOT_NEED,
			time() - 700000, function() {
				throw new RuntimeException('failed read must stop before handler');
			}, function() use ($marker) {
				rXMLRPCRequest::queue('d.get_custom|d.get_local_id', true, false,
					array($marker, self::LOCAL_ID));
				rXMLRPCRequest::queue('d.get_custom', false, false, array());
				strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH),
					'unreadable settled message defers the check');
				strictAssertSame(null, strictGetPrivateStatic('ruTrackerChecker', 'terminalMessageHash'),
					'the failed run releases its process-local message buffer');
				rXMLRPCRequest::queue('d.set_custom', true, false, array());
				strictAssertSame(true, ruTrackerChecker::setMessage(self::OLD_HASH, 'later'),
					'a later independent message write must use the daemon');
				strictAssertSame(array('later'), $this->customWritesFor('chk-msg'),
					'the old run cannot silently absorb a later write');
			}, $marker);
	}

	public function testH07MalformedPersistedMarkerDefersWithoutMutationAndLogsReason()
	{
		$marker = ruTrackerChecker::CHKMSG_SUCCESSOR_MISSING . '|invalid';
		$this->withVerdictSession('h07-bad-marker', ruTrackerChecker::STE_ERROR,
			time() - 700000, function() {
				throw new RuntimeException('malformed marker must stop before handler');
			}, function() {
				strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH),
					'malformed persisted marker defers the check');
				strictAssertSame(array(), $this->customWritesFor('chk-state'),
					'no lock or verdict can erase the malformed evidence');
				strictAssertOneLogMatching(FileUtil::$log, 'malformed chk-msg successor-missing marker',
					'the exact stored key and refusal are visible');
			}, $marker);
	}

	public function testH07UnconfirmedPersistedMarkerReadbackDefersWithoutMutation()
	{
		$marker = ruTrackerChecker::CHKMSG_SUCCESSOR_MISSING . '|' . self::NEW_HASH;
		$this->withVerdictSession('h07-bad-readback', ruTrackerChecker::STE_ERROR,
			time() - 700000, function() {
				throw new RuntimeException('unconfirmed marker must stop before handler');
			}, function() {
				rXMLRPCRequest::queue('d.get_custom|d.get_local_id', false, false, array());
				strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH),
					'unconfirmed persisted marker defers the check');
				strictAssertSame(array(), $this->customWritesFor('chk-state'),
					'no lock or verdict is written before marker confirmation');
				strictAssertOneLogMatching(FileUtil::$log, 'chk-msg marker readback unconfirmed',
					'the failed revalidation is visible');
			}, $marker);
	}

	public function testH07ConclusiveAnswerClearsPersistedMarkerFromPriorError()
	{
		$past = time() - 700000;
		$marker = ruTrackerChecker::CHKMSG_SUCCESSOR_MISSING . '|' . self::NEW_HASH;
		$this->withVerdictSession('h07-persisted-conclusive', ruTrackerChecker::STE_ERROR,
			$past, function() {
				ruTrackerChecker::setMessage(self::OLD_HASH, '');
				return ruTrackerChecker::STE_UPTODATE;
			}, function() use ($marker) {
				rXMLRPCRequest::queue('d.get_custom|d.get_local_id', true, false,
					array($marker, self::LOCAL_ID));
				strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH),
					'the new answer settles the pending check');
				$messages = $this->customWritesFor('chk-msg');
				strictAssertSame('', end($messages),
					'a conclusive answer retires the durable missing-successor marker');
				$states = $this->customWritesFor('chk-state');
				strictAssertSame((string) ruTrackerChecker::STE_UPTODATE, end($states),
					'the marker clear and new verdict belong to one projection');
			}, $marker);
	}

	public function testH07UnreadableHandlerRecordCannotErasePersistedMarker()
	{
		if(!class_exists('RuTrackerCheckImpl', false))
		{
			require_once(testFindRepoRoot() . '/plugins/rutracker_check/detector.php');
			eval(loadClassDefinition(testFindRepoRoot()
				. '/plugins/rutracker_check/trackers/rutracker.php', 'RuTrackerCheckImpl'));
		}
		$past = time() - 700000;
		$marker = ruTrackerChecker::CHKMSG_SUCCESSOR_MISSING . '|' . self::NEW_HASH;
		$this->withVerdictSession('h07-unreadable-record', ruTrackerChecker::STE_ERROR,
			$past, function($url, $hash, $torrent) {
				return RuTrackerCheckImpl::download_torrent(
					'https://rutracker.org/forum/viewtopic.php?t=42', $hash, $torrent);
			}, function() use ($past, $marker) {
				rXMLRPCRequest::queue('d.get_custom|d.get_local_id', true, false,
					array($marker, self::LOCAL_ID));
				rXMLRPCRequest::queue('d.get_custom|d.get_custom', false, false, array());
				rXMLRPCRequest::queue('d.get_custom', true, false, array('42'));
				rXMLRPCRequest::queue('d.get_tracker_size|t.multicall|d.get_message',
					false, false, array());
				strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH),
					'unreadable handler record remains retryable');
				strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.get_custom|d.get_custom')),
					'the handler record read really failed');
				$times = $this->customWritesFor('chk-time');
				strictAssertSame((string) $past, end($times),
					'the original retry clock survives the transient read failure');
				$messages = $this->customWritesFor('chk-msg');
				strictAssertSame($marker, end($messages),
					'the marker remains after an inconclusive later layer');
			}, $marker);
	}

	public function testH07PersistedMarkerKeepsOldClockWhenSourceIsUnreadable()
	{
		$past = time() - 700000;
		$marker = ruTrackerChecker::CHKMSG_SUCCESSOR_MISSING . '|' . self::NEW_HASH;
		$this->withVerdictSession('h07-persisted-source', ruTrackerChecker::STE_ERROR,
			$past, function() {
				throw new RuntimeException('source is unreadable; handler must not run');
			}, function() use ($past, $marker) {
				rTorrent::$sourceQueue[] = false;
				rXMLRPCRequest::queue('d.get_custom|d.get_local_id', true, false,
					array($marker, self::LOCAL_ID));
				strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH),
					'the missing source leaves the retry pending');
				$timeWrites = $this->customWritesFor('chk-time');
				strictAssertSame((string) $past, end($timeWrites),
					'the unreadable source cannot reset the original retry clock');
				$messageWrites = $this->customWritesFor('chk-msg');
				strictAssertSame($marker, end($messageWrites),
					'the durable marker remains after the source read failure');
			}, $marker);
	}

	public function testH07MissingSuccessorPersistsRetryableVerdictAndOriginalClock()
	{
		$past = time() - 700000;
		$marker = ruTrackerChecker::CHKMSG_SUCCESSOR_MISSING . '|' . self::NEW_HASH;
		$this->withVerdictSession('h07-missing', ruTrackerChecker::STE_NOT_NEED, $past,
			function() {
				strictAssertSame(true, ruTrackerChecker::recordMissingSuccessor(
					self::OLD_HASH, self::NEW_HASH), 'the absent successor is recorded');
				ruTrackerChecker::setMessage(self::OLD_HASH, '');
				return ruTrackerChecker::STE_CANT_REACH_TRACKER;
			},
			function() use ($past, $marker) {
				rXMLRPCRequest::queue('d.get_custom', true, false,
					array(ruTrackerChecker::CHKMSG_SUPERSEDED . '|' . self::NEW_HASH));
			rXMLRPCRequest::queue('d.get_custom|d.get_local_id', true, false,
					array($marker, self::LOCAL_ID));
				$performed = null;
				$this->withoutDebugLog(function() use ($past, &$performed) {
					strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH,
						ruTrackerChecker::STE_NOT_NEED, $past, '', $performed),
						'the failed recheck remains retryable');
				});
				strictAssertOneLogMatching(FileUtil::$log,
					'recordMissingSuccessor: ' . self::OLD_HASH . ' successor-missing=' . self::NEW_HASH,
					'the successful durable marker has a classified routine log');
				strictAssertSame(false, $performed, 'no correction was consumed');
				strictAssertSame(array((string) ruTrackerChecker::STE_NOT_NEED,
					(string) ruTrackerChecker::STE_ERROR), $this->customWritesFor('chk-state'),
					'the settled state becomes a retryable error after marker proof');
				strictAssertSame(array((string) $past), $this->customWritesFor('chk-time'),
					'the original recheck clock is retained');
				strictAssertSame(array($marker, $marker), $this->customWritesFor('chk-msg'),
					'the marker survives the later inconclusive message clear and is republished with ERROR');
			});
	}

	public function testH07PersistedMarkerSurvivesAnotherInconclusiveCycle()
	{
		$past = time() - 700000;
		$marker = ruTrackerChecker::CHKMSG_SUCCESSOR_MISSING . '|' . self::NEW_HASH;
		foreach(array(ruTrackerChecker::STE_NOT_NEED, ruTrackerChecker::STE_ERROR) as $previous)
		{
			$this->withVerdictSession('h07-resume-' . $previous, $previous, $past,
				function() {
					strictAssertSame(true, ruTrackerChecker::retainMissingSuccessor(
						self::OLD_HASH, self::NEW_HASH), 'the existing marker is confirmed');
					ruTrackerChecker::setMessage(self::OLD_HASH, '');
					return ruTrackerChecker::STE_UNCHANGED;
				},
				function() use ($past, $marker, $previous) {
					if($previous === ruTrackerChecker::STE_NOT_NEED)
						rXMLRPCRequest::queue('d.get_custom', true, false, array($marker));
					rXMLRPCRequest::queue('d.get_custom|d.get_local_id', true, false,
						array($marker, self::LOCAL_ID));
					$performed = null;
					strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH,
						$previous, $past, '', $performed), 'the retry remains pending');
					strictAssertSame(false, $performed, 'the cold answer consumed no work');
					$states = $this->customWritesFor('chk-state');
					$times = $this->customWritesFor('chk-time');
					strictAssertSame((string) ruTrackerChecker::STE_ERROR,
						end($states), 'ERROR stays retryable');
					strictAssertSame((string) $past, end($times),
						'the original clock survives a later inconclusive check');
					strictAssertSame(array($marker), $this->customWritesFor('chk-msg'),
						'the marker is republished with the error rather than cleared');
				});
		}
	}

	public function testH07CrashAfterMarkerLeavesSchedulableEvidence()
	{
		$past = time() - 700000;
		$marker = ruTrackerChecker::CHKMSG_SUCCESSOR_MISSING . '|' . self::NEW_HASH;
		$this->withVerdictSession('h07-crash', ruTrackerChecker::STE_NOT_NEED, $past,
			function() {
				strictAssertSame(true, ruTrackerChecker::recordMissingSuccessor(
					self::OLD_HASH, self::NEW_HASH), 'the marker landed before the crash');
				throw new RuntimeException('simulated interruption');
			},
			function() use ($past, $marker) {
				rXMLRPCRequest::queue('d.get_custom', true, false,
					array(ruTrackerChecker::CHKMSG_SUPERSEDED . '|' . self::NEW_HASH));
			rXMLRPCRequest::queue('d.get_custom|d.get_local_id', true, false,
					array($marker, self::LOCAL_ID));
				try {
					ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_NOT_NEED,
						$past, '');
					throw new RuntimeException('handler did not interrupt');
				} catch(RuntimeException $error) {
					strictAssertSame('simulated interruption', $error->getMessage(),
						'the interruption reached the caller');
				}
				strictAssertSame(array((string) ruTrackerChecker::STE_NOT_NEED),
					$this->customWritesFor('chk-state'), 'the old state can remain until recovery');
				strictAssertSame(array(), $this->customWritesFor('chk-time'),
					'the original clock was not changed before the crash');
				strictAssertSame(array($marker), $this->customWritesFor('chk-msg'),
					'the distinct marker survives the interrupted worker');
				strictAssertSame(null, strictGetPrivateStatic('ruTrackerChecker', 'missingSuccessorMarker'),
					'process-local state is cleared after the interruption');
			});
	}

	public function testH07UnconfirmedErrorProjectionKeepsMarkerAndLogsRefusal()
	{
		$past = time() - 700000;
		$marker = ruTrackerChecker::CHKMSG_SUCCESSOR_MISSING . '|' . self::NEW_HASH;
		$this->withVerdictSession('h07-partial', ruTrackerChecker::STE_NOT_NEED, $past,
			function() {
				strictAssertSame(true, ruTrackerChecker::recordMissingSuccessor(
						self::OLD_HASH, self::NEW_HASH), 'the marker landed');
				return ruTrackerChecker::STE_CANT_REACH_TRACKER;
			},
			function() use ($past, $marker) {
				rXMLRPCRequest::queue('d.get_custom', true, false,
					array(ruTrackerChecker::CHKMSG_SUPERSEDED . '|' . self::NEW_HASH));
			rXMLRPCRequest::queue('branch', true, false,
					array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
			rXMLRPCRequest::queue('branch', true, false,
					array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
			rXMLRPCRequest::queue('d.get_custom|d.get_local_id', true, false,
					array($marker, self::LOCAL_ID));
			rXMLRPCRequest::queue('branch', true, false,
					array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED));
			$performed = null;
			strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH,
					ruTrackerChecker::STE_NOT_NEED, $past, '', $performed),
					'the unconfirmed final projection remains retryable');
			strictAssertSame(array($marker), $this->customWritesFor('chk-msg'),
					'the already confirmed marker survives a refused final write');
			strictAssertSame(array(), $this->customWritesFor('chk-time'),
					'the original clock is intact');
			strictAssertOneLogMatching(FileUtil::$log,
				'chk-state/chk-time/chk-msg successor-missing verdict unconfirmed',
					'the exact failed projection is visible without debug logging');
			});
	}

	public function testH07UncertainMarkerWriteIsVisibleAndStopsTheTransition()
	{
		$past = time() - 700000;
		$accepted = null;
		$this->withVerdictSession('h07-marker-unknown', ruTrackerChecker::STE_NOT_NEED, $past,
			function() use (&$accepted) {
				$accepted = ruTrackerChecker::recordMissingSuccessor(
					self::OLD_HASH, self::NEW_HASH);
				return ruTrackerChecker::STE_ERROR;
			},
			function() use ($past, &$accepted) {
				rXMLRPCRequest::queue('d.get_custom', true, false,
					array(ruTrackerChecker::CHKMSG_SUPERSEDED . '|' . self::NEW_HASH));
			rXMLRPCRequest::queue('branch', true, false,
					array(RuTrackerAtomicOwnership::SENTINEL_ACTED)); // preflight
			rXMLRPCRequest::queue('branch', true, false, array('unreadable-branch')); // marker
			rXMLRPCRequest::queue('d.get_custom|d.get_local_id', false, false, array());
			rXMLRPCRequest::queue('d.hash', true, false, array(self::OLD_HASH));
			ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_NOT_NEED, $past, '');
			strictAssertSame(false, $accepted, 'the unknown marker write is not accepted');
			strictAssertSame(array((string) ruTrackerChecker::STE_NOT_NEED),
					$this->customWritesFor('chk-state'), 'no unsupported final verdict is emitted');
			strictAssertSame(array(), $this->customWritesFor('chk-time'),
					'the prior clock is not advanced');
			strictAssertOneLogMatching(FileUtil::$log, 'chk-msg marker write unconfirmed',
					'the unknown marker write is visible without debug logging');
			});
	}

	public function testH07ConclusiveAnswerReplacesMarkerAndVerdictTogether()
	{
		$past = time() - 700000;
		$marker = ruTrackerChecker::CHKMSG_SUCCESSOR_MISSING . '|' . self::NEW_HASH;
		$this->withVerdictSession('h07-conclusive', ruTrackerChecker::STE_NOT_NEED, $past,
			function() {
				strictAssertSame(true, ruTrackerChecker::recordMissingSuccessor(
					self::OLD_HASH, self::NEW_HASH), 'the absent successor was recorded');
				ruTrackerChecker::setMessage(self::OLD_HASH, '');
				return ruTrackerChecker::STE_UPTODATE;
			},
			function() use ($past, $marker) {
				rXMLRPCRequest::queue('d.get_custom', true, false,
					array(ruTrackerChecker::CHKMSG_SUPERSEDED . '|' . self::NEW_HASH));
			rXMLRPCRequest::queue('d.get_custom|d.get_local_id', true, false,
					array($marker, self::LOCAL_ID));
			$performed = null;
			strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH,
				ruTrackerChecker::STE_NOT_NEED, $past, '', $performed),
					'the new answer is conclusive');
			strictAssertSame(true, $performed, 'the final answer consumes checker work');
			strictAssertSame(array((string) ruTrackerChecker::STE_NOT_NEED,
					(string) ruTrackerChecker::STE_UPTODATE), $this->customWritesFor('chk-state'),
					'the authoritative result replaces the old state');
			strictAssertSame(array($marker, ''), $this->customWritesFor('chk-msg'),
					'the marker is cleared in the final verdict projection');
			});
	}

	public function testTransientFailurePreservesTerminalVerdict()
	{
		$rows = array();
		foreach (array(ruTrackerChecker::STE_DELETED, ruTrackerChecker::STE_ABSORBED) as $previous)
			foreach (array(ruTrackerChecker::STE_CANT_REACH_TRACKER, ruTrackerChecker::STE_ERROR,
				ruTrackerChecker::STE_UNCHANGED, ruTrackerChecker::STE_UPTODATE, null) as $failure)
			{
				$row = array('previous' => $previous, 'failure' => $failure);
				if($failure === ruTrackerChecker::STE_UPTODATE)
					$row['expect'] = (string) $failure;
				$rows[$previous . '-' . $failure] = $row;
			}

		foreach($rows as $label => $row)
		{
			$this->withVerdictSession('terminal', $row['previous'], time(),
				function($url) use ($row) {
					if ($row['failure'] === null) throw new RuntimeException('interrupted handler');
					return $row['failure'];
				},
				function() use ($row, $label) {
					rXMLRPCRequest::queue('d.set_custom', true, false, array()); // same-value state projection
					rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array()); // a new verdict
					rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array());
					rXMLRPCRequest::queue('d.set_custom|d.set_custom|d.set_custom', true, false, array());
					$performed = null;
					$interrupted = null;
					try {
						$result = ruTrackerChecker::run(self::OLD_HASH, $row['previous'], time(), '', $performed);
					} catch (RuntimeException $error) { $interrupted = $error->getMessage(); }
					if ($row['failure'] === null) {
						strictAssertSame('interrupted handler', $interrupted, 'the real handler interruption propagated');
						strictAssertSame(false, $performed, 'an interrupted handler consumed no work');
					} else {
						strictAssertSame(null, $interrupted, 'the handler completed');
						strictAssertSame($row['failure'] !== ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
							'the returned transport outcome is independent of the displayed verdict');
						strictAssertSame($row['failure'] === ruTrackerChecker::STE_UPTODATE, $performed,
							'only a new authoritative answer durably consumed correction work');
					}

					$stateWrites = $this->customWritesFor('chk-state');
					$timeWrites = $this->customWritesFor('chk-time');
					strictAssertSame($row['failure'] === ruTrackerChecker::STE_UPTODATE
						? array((string) $row['previous'], $row['expect'])
						: array((string) $row['previous']), $stateWrites,
						$label . ': preflight preserves the terminal verdict and only a new verdict replaces it');
					strictAssertSame($row['failure'] === ruTrackerChecker::STE_UPTODATE ? 1 : 0,
						count($timeWrites), $label . ': a failed check cannot refresh the settled rest clock');
			});
		}
	}

	public function testUnreadableSettledMessageDefersWithoutDispatchOrWrites()
	{
		$calls = 0;
		$this->withVerdictSession('unreadable-message', ruTrackerChecker::STE_NOT_NEED,
			time() - 700000, function() use (&$calls) {
				$calls++;
				return ruTrackerChecker::STE_UPTODATE;
			}, function() use (&$calls) {
				rXMLRPCRequest::queue('d.get_custom', false, false, array());
				$performed = null;
				strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH,
					ruTrackerChecker::STE_NOT_NEED, time(), '', $performed),
					'an unreadable settled message leaves the check retryable');
				strictAssertSame(false, $performed, 'the failed read consumed no scheduler work');
				strictAssertSame(0, $calls, 'no tracker handler ran without the settled token');
				strictAssertSame(0, rTorrent::$sourceReads, 'the session torrent was not opened');
				strictAssertSame(array(), $this->customWritesFor('chk-state'),
					'no lock or verdict was written after the failed message read');
				strictAssertSame(array(), $this->customWritesFor('chk-msg'),
					'the unreadable token was not overwritten');
				strictAssertSame(2, count(rXMLRPCRequest::$requests),
					'only the live state and settled message were read');
			});
	}

	public function testSettledVerdictDefersMessageClearOnRetryableFailure()
	{
		foreach (array(
			ruTrackerChecker::STE_NOT_NEED => ruTrackerChecker::CHKMSG_TOPIC_STATUS . '|4',
			ruTrackerChecker::STE_ABSORBED => ruTrackerChecker::CHKMSG_ABSORBED . '|42',
			ruTrackerChecker::STE_DELETED => ruTrackerChecker::CHKMSG_DELETING . '|3/3',
		) as $previous => $message)
		{
			$past = time() - 700000;
			$this->withVerdictSession('message-retention', $previous, $past,
				function() {
					strictAssertSame(true, ruTrackerChecker::setMessage(self::OLD_HASH, ''),
						'the handler accepted a message clear before returning failure');
					strictAssertSame(array(), $this->customWritesFor('chk-msg'),
						'a retained terminal token must not be erased while the handler is still running');
					return ruTrackerChecker::STE_CANT_REACH_TRACKER;
				},
				function() use ($previous, $message) {
					if($previous === ruTrackerChecker::STE_NOT_NEED)
						rXMLRPCRequest::queue('d.get_custom', true, false, array($message));
					rXMLRPCRequest::queue('d.set_custom', true, false, array()); // preflight
					$performed = null;
					strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH,
						$previous, time(), '', $performed), 'the transport outcome remains retryable');
					strictAssertSame(array(), $this->customWritesFor('chk-msg'),
						'a retryable handler never erases the settled token');
					strictAssertSame(array(), $this->customWritesFor('chk-time'),
						'the original rest deadline is unchanged');
				});
		}
	}

	public function testTerminalMessageClearShipsWithANewVerdict()
	{
		$message = ruTrackerChecker::CHKMSG_TOPIC_STATUS . '|4';
		$this->withVerdictSession('terminal-new', ruTrackerChecker::STE_NOT_NEED, time() - 700000,
			function() {
				ruTrackerChecker::setMessage(self::OLD_HASH, '');
				strictAssertSame(array(), $this->customWritesFor('chk-msg'),
					'the old terminal token remains during the handler');
				return ruTrackerChecker::STE_UPTODATE;
			},
			function() use ($message) {
				rXMLRPCRequest::queue('d.get_custom', true, false, array($message));
				rXMLRPCRequest::queue('d.set_custom', true, false, array()); // preflight
				rXMLRPCRequest::queue('d.set_custom|d.set_custom|d.set_custom|d.set_custom',
					true, false, array()); // state, clock, success clock, message
				$performed = null;
				strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH,
					ruTrackerChecker::STE_NOT_NEED, time(), '', $performed),
					'a new authoritative answer is accepted');
				strictAssertSame(true, $performed, 'the new verdict consumed correction work');
				strictAssertSame(array(''), $this->customWritesFor('chk-msg'),
					'the stale token is cleared with the new verdict');
				$writes = rXMLRPCRequest::requestsFor('branch');
				strictAssertSame(2, count($writes), 'preflight and definitive projection each use one guarded request');
			});
	}

	public function testTerminalMessageChangeIsDiscardedAfterRetryableFailure()
	{
		$message = ruTrackerChecker::CHKMSG_TOPIC_STATUS . '|4';
		$this->withVerdictSession('terminal-changed', ruTrackerChecker::STE_NOT_NEED, time() - 700000,
			function() {
				strictAssertSame(true, ruTrackerChecker::setMessage(self::OLD_HASH,
					ruTrackerChecker::CHKMSG_FUSE . '|tracker.test'),
					'a nonblank handler message was staged');
				strictAssertSame(array(), $this->customWritesFor('chk-msg'),
					'the old terminal token remains while the handler is running');
				return ruTrackerChecker::STE_CANT_REACH_TRACKER;
			},
			function() use ($message) {
				rXMLRPCRequest::queue('d.get_custom', true, false, array($message));
				rXMLRPCRequest::queue('d.set_custom', true, false, array()); // preflight
				$performed = null;
				strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH,
					ruTrackerChecker::STE_NOT_NEED, time(), '', $performed),
					'a retryable answer stays retryable');
				strictAssertSame(array(), $this->customWritesFor('chk-msg'),
					'the earlier settled token was never overwritten');
			});
	}

	public function testTerminalNewTokenShipsWithItsVerdict()
	{
		$message = ruTrackerChecker::CHKMSG_TOPIC_STATUS . '|4';
		$newMessage = ruTrackerChecker::CHKMSG_ABSORBED . '|42';
		$this->withVerdictSession('terminal-token', ruTrackerChecker::STE_NOT_NEED, time() - 700000,
			function() use ($newMessage) {
				strictAssertSame(true, ruTrackerChecker::setMessage(self::OLD_HASH, $newMessage),
					'the new token was staged');
				strictAssertSame(array(), $this->customWritesFor('chk-msg'),
					'the new token is invisible until the verdict is ready');
				return ruTrackerChecker::STE_ABSORBED;
			},
			function() use ($message, $newMessage) {
				rXMLRPCRequest::queue('d.get_custom', true, false, array($message));
				rXMLRPCRequest::queue('d.set_custom', true, false, array()); // preflight
				rXMLRPCRequest::queue('d.set_custom|d.set_custom|d.set_custom',
					true, false, array()); // state, clock, message
				$performed = null;
				strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH,
					ruTrackerChecker::STE_NOT_NEED, time(), '', $performed),
					'the new verdict is accepted');
				strictAssertSame(array($newMessage), $this->customWritesFor('chk-msg'),
					'the new token is written with the definitive verdict');
				strictAssertSame(2, count(rXMLRPCRequest::requestsFor('branch')),
					'preflight and definitive projection each use one guarded request');
			});
	}

	public function testTerminalMessageIsDiscardedWhenTheHandlerThrows()
	{
		$message = ruTrackerChecker::CHKMSG_TOPIC_STATUS . '|4';
		$this->withVerdictSession('terminal-throw', ruTrackerChecker::STE_NOT_NEED, time() - 700000,
			function() {
				ruTrackerChecker::setMessage(self::OLD_HASH, ruTrackerChecker::CHKMSG_FUSE . '|tracker.test');
				throw new RuntimeException('handler interrupted');
			},
			function() use ($message) {
				rXMLRPCRequest::queue('d.get_custom', true, false, array($message));
				rXMLRPCRequest::queue('d.set_custom', true, false, array()); // preflight
				$performed = null;
				try {
					ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_NOT_NEED,
						time(), '', $performed);
					throw new RuntimeException('handler did not throw');
				} catch (RuntimeException $error) {
					strictAssertSame('handler interrupted', $error->getMessage(), 'the handler error propagated');
				}
				strictAssertSame(array(), $this->customWritesFor('chk-msg'),
					'an interrupted handler did not publish a new token');
				strictAssertSame(null, strictGetPrivateStatic('ruTrackerChecker', 'terminalMessageHash'),
					'the message guard is cleared after an exception');
			});
	}

	public function testSettledNotNeedKeepsItsVerdictAndRestClockOnRetryableFailure()
	{
		foreach (array(ruTrackerChecker::CHKMSG_TOPIC_STATUS . '|4',
			ruTrackerChecker::CHKMSG_SUPERSEDED . '|' . self::NEW_HASH) as $message)
		{
			$past = time() - 700000;
			$this->withVerdictSession('settled', ruTrackerChecker::STE_NOT_NEED, $past,
				function() { return ruTrackerChecker::STE_CANT_REACH_TRACKER; },
				function() use ($message, $past) {
					rXMLRPCRequest::queue('d.get_custom', true, false, array($message));
					rXMLRPCRequest::queue('d.set_custom', true, false, array());
					$performed = null;
					strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH,
						ruTrackerChecker::STE_NOT_NEED, $past, '', $performed),
						$message . ': transport failure is still reported');
					strictAssertSame(false, $performed, 'a retryable answer consumes no correction');
					$stateWrites = $this->customWritesFor('chk-state');
					$timeWrites = $this->customWritesFor('chk-time');
					strictAssertSame(array((string) ruTrackerChecker::STE_NOT_NEED), $stateWrites,
						$message . ': only the same-value preflight write is allowed');
					strictAssertSame(array(), $timeWrites,
						$message . ': retryable failure preserves the settled rest clock');
			});
		}
	}

	public function testUnsettledNotNeedCanRecordRetryableFailure()
	{
		foreach (array('', ruTrackerChecker::CHKMSG_DELETING . '|2/3') as $message)
		{
			$past = time() - 700000;
			$this->withVerdictSession('unsettled', ruTrackerChecker::STE_NOT_NEED, $past,
				function() { return ruTrackerChecker::STE_CANT_REACH_TRACKER; },
				function() use ($message, $past) {
					rXMLRPCRequest::queue('d.get_custom', true, false, array($message));
					rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array()); // claim
					rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array()); // retryable verdict
					$performed = null;
					strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH,
						ruTrackerChecker::STE_NOT_NEED, $past, '', $performed),
						$message . ': transport remains retryable');
					strictAssertSame(false, $performed, 'a retryable answer consumed no correction');
					strictAssertSame(array((string) ruTrackerChecker::STE_INPROGRESS,
						(string) ruTrackerChecker::STE_CANT_REACH_TRACKER), $this->customWritesFor('chk-state'),
						$message . ': an unsettled row records the attempted check and its verdict');
					$times = $this->customWritesFor('chk-time');
					strictAssertSame(2, count($times), 'both state writes carry a clock');
					strictAssertSame(true, (int) end($times) > $past, 'the retry clock advances');
				});
		}
	}

	public function testTerminalPreflightStopsOnMissingHashOrUnprovedWrite()
	{
		foreach(array('vanished' => true, 'write unconfirmed' => false) as $label => $missing)
		{
			$handlerCalls = 0;
			$this->withVerdictSession('preflight', ruTrackerChecker::STE_DELETED, time(),
				function($url) use (&$handlerCalls) {
					$handlerCalls++;
					return ruTrackerChecker::STE_UPTODATE;
				},
				function() use ($missing, $label, &$handlerCalls) {
					rXMLRPCRequest::queue('branch', false, false, array());
					rXMLRPCRequest::queue('d.get_custom|d.get_local_id', false, false, array());
					rXMLRPCRequest::queue('d.hash', true, $missing,
						$missing ? array() : array(self::OLD_HASH),
						$missing ? 'info-hash not found' : '');
					$performed = null;
					strictAssertSame($missing, ruTrackerChecker::run(self::OLD_HASH,
						ruTrackerChecker::STE_DELETED, time(), '', $performed),
						$label . ': absent is a no-op; unproved write defers');
					strictAssertSame(0, $handlerCalls, $label . ': no tracker request follows unconfirmed preflight');
					strictAssertSame(false, $performed, $label . ': no correction was consumed');
					strictAssertSame(array(), $this->customWritesFor('chk-time'),
						$label . ': neither an INPROGRESS lock nor a timestamped verdict is written');
			});
		}
	}

	public function testPerformedRequiresANonUnchangedVerdictAndSuccessfulFinalWrite()
	{
		foreach(array(
			'final write failed while the torrent remained present' => array('write' => false, 'fault' => false, 'expect' => false),
			'final write found that the torrent vanished' => array('write' => false, 'fault' => true, 'expect' => false),
			'accepted verdict was durably saved' => array('write' => true, 'fault' => false, 'expect' => true),
		) as $label => $case)
		{
			$this->withVerdictSession('performed', ruTrackerChecker::STE_UPTODATE, 100,
				function() { return ruTrackerChecker::STE_UPDATED; },
				function() use ($case, $label) {
					rXMLRPCRequest::queue('branch', true, false,
						array(RuTrackerAtomicOwnership::SENTINEL_ACTED)); // preflight
					rXMLRPCRequest::queue('branch', $case['write'], false,
						$case['write'] ? array(RuTrackerAtomicOwnership::SENTINEL_ACTED) : array());
					if(!$case['write'])
					{
						rXMLRPCRequest::queue('d.get_custom|d.get_custom|d.get_local_id',
							!$case['fault'], false,
							$case['fault'] ? array() : array('1', '0', self::LOCAL_ID));
						rXMLRPCRequest::queue('d.hash', true, $case['fault'],
							$case['fault'] ? array() : array(self::OLD_HASH));
					}

					$performed = null;
					strictAssertSame(true,
						ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_UPTODATE, 100, '', $performed),
						$label . ': checker invocation itself completed');
					strictAssertSame($case['expect'], $performed,
						$label . ': durable correction acknowledgement follows the final write result');
			});
		}
	}

	public function testRetryableHandlerVerdictNeverConsumesForumCorrection()
	{
		foreach(array(ruTrackerChecker::STE_CANT_REACH_TRACKER, ruTrackerChecker::STE_ERROR) as $verdict)
		{
			$this->withVerdictSession('retryable', ruTrackerChecker::STE_UPTODATE, 100,
				function() use ($verdict) { return $verdict; },
				function() use ($verdict) {
					rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());
					rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());
					$performed = null;
					ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_UPTODATE, 100, '', $performed);
					strictAssertSame(false, $performed, 'retryable verdict ' . $verdict . ' cannot consume correction');
			});
		}
	}

	public function testPerformedDoesNotAcknowledgeATruncatedUnprovedFinalWrite()
	{
		$this->withVerdictSession('performed-short', ruTrackerChecker::STE_UPTODATE, 100,
			function() { return ruTrackerChecker::STE_UPDATED; },
			function() {
				// The INPROGRESS projection is acknowledged. The final branch
				// returns a truncated reply, then readback proves no final verdict.
				rXMLRPCRequest::queue('branch', true, false,
					array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
				rXMLRPCRequest::queue('branch', true, false, array());
				rXMLRPCRequest::queue('d.get_custom|d.get_custom|d.get_local_id',
					true, false, array('1', '0', self::LOCAL_ID));

				$performed = null;
				strictAssertSame(true,
					ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_UPTODATE, 100, '', $performed),
					'the checker invocation itself completes');
				strictAssertSame(false, $performed,
					'forum correction work is not acknowledged without a measured final verdict');
				strictAssertSame(1, count(rXMLRPCRequest::requestsFor(
					'd.get_custom|d.get_custom|d.get_local_id')),
					'the final short reply is verified against the same local id');
		});
	}

	public function testInheritanceRecordRoundTrips()
	{
		$hash = str_repeat('A', 40);
		foreach(array(
			array(true, true, 'started', true, true),
			array(false, true, 'open', false, true),
			array(false, false, 'stopped', false, false),
		) as $case)
		{
			$encoded = ruTrackerChecker::encodeInheritance($hash, $case[0], $case[1], 1786899620);
			strictAssertSame($hash . '-' . $case[2] . '-1786899620', $encoded, 'the record grammar is hash-token-epoch');
			$decoded = ruTrackerChecker::decodeInheritance($encoded);
			strictAssertSame($hash, $decoded['old'], 'the predecessor hash must survive the round trip');
			strictAssertSame($case[3], $decoded['run']['started'], 'the started flag must survive the round trip');
			strictAssertSame($case[4], $decoded['run']['open'], 'the open flag must survive the round trip');
			strictAssertSame(1786899620, $decoded['staged'], 'the staging time must survive the round trip');
		}
	}

	// null is the legacy signal: a row that predates the record, or lost it
	// because its load command list aborted. Every caller must route it to the
	// branch that starts nothing -- guessing is what this whole record exists
	// to avoid.
	public function testMalformedInheritanceRecordDecodesToNull()
	{
		foreach(array(
			'' => 'an absent record',
			'not-a-record' => 'a value that is not the grammar',
			'ZZZ-started-1786899620' => 'a predecessor that is not a hash',
			'AAAA-started-1786899620' => 'a hash of the wrong length',
			'0123456789012345678901234567890123456789-started' => 'a record missing the timestamp',
			'0123456789012345678901234567890123456789-started-1786899620-extra' => 'a record with trailing extra fields',
			'0123456789012345678901234567890123456789-started-x' => 'a timestamp that is not a number',
			'0123456789012345678901234567890123456789-started-0' => 'a timestamp of zero',
			'0123456789012345678901234567890123456789-mystery-1786899620' => 'an unknown run token',
		) as $value => $label)
			strictAssertSame(null, ruTrackerChecker::decodeInheritance($value), $label . ' must decode to null');
	}

	// Mirrors RuTrackerMetaFetch::decodeRunState: an unknown token is the
	// safest of the three answers, never the one that resurrects a download.
	// The REAL awaitMetadata(). MetaFetchTest drives a double of it (TestLib's
	// stub suite replaces ruTrackerChecker wholesale), so nothing there would
	// notice if this command name, the d.is_meta reading or the budget broke.
	// This file loads the genuine class, so it is where those belong.
	// d.custom1 holds the label percent-encoded on every path that goes
	// through rTorrent::sendTorrent(), so an $ignoreLabels entry containing a
	// space or Cyrillic never matched the raw value getState() reads.
	public function testIgnoredLabelMatchesThePercentEncodedFormToo()
	{
		$saved = isset($GLOBALS['ignoreLabels']) ? $GLOBALS['ignoreLabels'] : null;
		$GLOBALS['ignoreLabels'] = array('TV Shows', 'Кино');
		try
		{
			strictAssertSame(true, ruTrackerChecker::isIgnoredLabel('TV Shows'), 'the plain form still matches');
			strictAssertSame(true, ruTrackerChecker::isIgnoredLabel('TV%20Shows'), 'and so does the stored form');
			strictAssertSame(true, ruTrackerChecker::isIgnoredLabel(rawurlencode('Кино')), 'including non-ASCII');
			strictAssertSame(false, ruTrackerChecker::isIgnoredLabel('TV Movies'), 'an unlisted label is not ignored');
			strictAssertSame(false, ruTrackerChecker::isIgnoredLabel(''), 'and neither is no label at all');
		}
		finally
		{
			if($saved === null) unset($GLOBALS['ignoreLabels']);
			else $GLOBALS['ignoreLabels'] = $saved;
		}
	}

	// init.js appends chk-msg under whatever state is current, so the sentence
	// has to go with the state it explained. The scheduler's fast pass clears
	// it on its own STE_IGNORED write; the path a "check" click takes did not,
	// so a torrent that picked up an ignored label after a verdict displayed
	// "Ignored -- ... confirmation cycle 2/3" -- the opposite of what IGNORED
	// means, which is that nobody looked.
	public function testIgnoredLabelClearsTheSentenceOfThePreviousVerdict()
	{
		$this->resetFakes();
		$saved = isset($GLOBALS['ignoreLabels']) ? $GLOBALS['ignoreLabels'] : null;
		$GLOBALS['ignoreLabels'] = array('tv-sonarr');
		try
		{
			self::queueStateRead( true, false,
				array((string) ruTrackerChecker::STE_DELETED, (string) time(), 'tv-sonarr'));
			rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array()); // the IGNORED state
			rXMLRPCRequest::queue('d.set_custom', true, false, array());               // the message clear

			$performed = null;
			$result = ruTrackerChecker::run(
				self::OLD_HASH, ruTrackerChecker::STE_DELETED, time(), 'tv-sonarr', $performed);
			strictAssertSame(true, $result, 'an ignored label is not a failed check');
			strictAssertSame(false, $performed,
				'an ignored-label decision is not a forum-aware tracker check');

			strictAssertSame(array((string) ruTrackerChecker::STE_IGNORED),
				$this->customWritesFor('chk-state'), 'the state becomes IGNORED');
			strictAssertSame(array(''), $this->customWritesFor('chk-msg'),
				'and the previous verdict\'s sentence is cleared with it');
		}
		finally
		{
			if($saved === null) unset($GLOBALS['ignoreLabels']);
			else $GLOBALS['ignoreLabels'] = $saved;
		}
	}

	// Durable forum-correction work is retired only after a tracker handler
	// actually accepts one of the torrent's URLs. Entering run_ex() is not that
	// proof: the session bytes may fail to parse, or a perfectly readable
	// torrent may belong to no registered handler at all.
	public function testAHandlerMustAcceptTheTorrentBeforeTheCheckIsAcknowledged()
	{
		$cases = array(
			'unparseable session bytes' => null,
			'no registered handler claims it' => array(
				'hash' => self::OLD_HASH,
				'comment' => 'http://some.other.tracker.invalid/topic/1',
				'announce' => 'http://some.other.tracker.invalid/announce',
			),
		);

		foreach($cases as $label => $fixture)
		{
			$this->resetFakes();
			$dir = sys_get_temp_dir() . '/rut-not-acknowledged-' . bin2hex(random_bytes(5)) . '/';
			mkdir($dir, 0777, true);
			$fname = $dir . self::OLD_HASH . '.torrent';
			file_put_contents($fname, 'x');
			rTorrentSettings::get()->session = $dir;
			try
			{
				if($fixture !== null) Torrent::$fixtures[$fname] = $fixture;

				self::queueStateRead( true, false,
					array((string) ruTrackerChecker::STE_UPTODATE, (string) time(), ''));
				rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array()); // INPROGRESS
				rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array()); // final verdict

				$performed = null;
				ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_UPTODATE, time(), '', $performed);

				strictAssertSame(false, $performed,
					$label . ': no tracker handler consumed the forum-aware obligation');
			}
			finally
			{
				rTorrentSettings::get()->session = '/nonexistent/';
				strictRemoveTree($dir);
			}
		}
	}

	// getState() leaves its own STE_INPROGRESS default in place when the read
	// fails, and the dispatch reads that as "another process holds the lock" --
	// so the check was silently skipped and reported as SUCCESSFUL. A read
	// that failed is not a lock.
	public function testUnreadableStateDefersInsteadOfLookingLocked()
	{
		$this->resetFakes();
		// The existence probe itself cannot be answered: unknowable, not gone.
		rXMLRPCRequest::queue('d.hash', false, false, array());

		strictAssertSame(false, ruTrackerChecker::run(self::OLD_HASH),
			'an unreadable state defers the check instead of claiming success');
		strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
			'and writes nothing on a torrent it could not read');

		// A torrent that is genuinely gone is still a successful no-op.
		$this->resetFakes();
		rXMLRPCRequest::queue('d.hash', true, true, array());
		strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH), 'a vanished torrent is nobody\'s problem');
	}

	// An unreadable session copy fell through to STE_ERROR with no word about
	// why -- and a missing session directory is the one failure an operator
	// can actually fix.
	public function testUnreadableSessionCopySaysSo()
	{
		$this->resetFakes();
		$this->withDebugLog(function() {
			self::queueStateRead( true, false, array('3', '1000', '900'));
			rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array()); // the lock
			rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array()); // the verdict

			ruTrackerChecker::run(self::OLD_HASH);
			$log = implode("\n", FileUtil::$log);
			strictAssertTrue(strpos($log, 'no readable session copy') !== false,
				'the missing session copy is named: ' . $log);
		});
	}

	public function testAwaitMetadataRequiresLiveStateAndMatchingSessionHash()
	{
		$this->resetFakes();
		$GLOBALS['rutrackerMetaWait'] = 0;   // poll once, never sleep
		try
		{
			rTorrent::$source = new Torrent(array('hash' => self::NEW_HASH));
			rXMLRPCRequest::queue('d.is_meta', true, false, array('0'));
			strictAssertSame(true, ruTrackerChecker::awaitMetadata(self::NEW_HASH),
				'is_meta 0 plus matching session metainfo means the successor is durable');
			$polls = rXMLRPCRequest::requestsFor('d.is_meta');
			strictAssertSame(1, count($polls), 'exactly one poll when the answer arrives at once');
			strictAssertSame(self::NEW_HASH, $polls[0]['commands'][0]->params, 'and it asks about the stub');
			strictAssertSame(1, rTorrent::$sourceReads, 'readiness also reads the session source once');

			// The daemon can flip is_meta before the session file replacement is
			// durable. That transition is pending, not permission to harvest.
			$this->resetFakes();
			$GLOBALS['rutrackerMetaWait'] = 0;
			rTorrent::$source = new Torrent(array('hash' => self::OLD_HASH));
			rXMLRPCRequest::queue('d.is_meta', true, false, array('0'));
			strictAssertSame(false, ruTrackerChecker::awaitMetadata(self::NEW_HASH),
				'is_meta 0 with stale session bytes is not ready');
			strictAssertSame(1, rTorrent::$sourceReads, 'the stale decision is based on one source read');

			// Still a stub: with no budget left the answer is "not yet".
			$this->resetFakes();
			$GLOBALS['rutrackerMetaWait'] = 0;
			rXMLRPCRequest::queue('d.is_meta', true, false, array('1'));
			strictAssertSame(false, ruTrackerChecker::awaitMetadata(self::NEW_HASH), 'is_meta 1 is not metadata');
			strictAssertSame(0, rTorrent::$sourceReads, 'a live metadata stub has no session-read obligation yet');

			// A failed read must not be mistaken for metadata.
			$this->resetFakes();
			$GLOBALS['rutrackerMetaWait'] = 0;
			rXMLRPCRequest::queue('d.is_meta', false, false, array());
			strictAssertSame(false, ruTrackerChecker::awaitMetadata(self::NEW_HASH), 'an unreadable answer is not "arrived"');

			// A fault carrying a value must not be either.
			$this->resetFakes();
			$GLOBALS['rutrackerMetaWait'] = 0;
			rXMLRPCRequest::queue('d.is_meta', true, true, array('0'));
			strictAssertSame(false, ruTrackerChecker::awaitMetadata(self::NEW_HASH), 'a faulted 0 is not "arrived"');
		}
		finally
		{
			unset($GLOBALS['rutrackerMetaWait']);
		}
	}

	public function testUnknownOwnedStagedEraseNeverFallsBackToStandaloneErase()
	{
		$this->resetFakes();
		$hash = str_repeat('A', 40);
		$marker = str_repeat('b', 32);
		$record = str_repeat('C', 40) . '-started-1786899620';
		rXMLRPCRequest::queue('branch', false, false, array());

		strictAssertSame(false,
			strictInvoke('ruTrackerChecker', 'eraseStaged', array($hash, $marker, $record)),
			'an unknown conditional erase is not reported as completed');
		strictAssertSame(1, count(rXMLRPCRequest::requestsFor('branch')),
			'exact ownership is evaluated once at the daemon boundary');
		strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')),
			'an unknown conditional erase never authorizes a blind second erase');
	}

	public function testUnknownOwnedClearNeverFallsBackToUnconditionalCustomWrites()
	{
		$hash = str_repeat('A', 40);
		$marker = str_repeat('b', 32);
		$record = str_repeat('C', 40) . '-started-1786899620';
		$this->resetFakes();
		rXMLRPCRequest::queue('branch', false, false, array());
		strictAssertSame(false,
			strictInvoke('ruTrackerChecker', 'clearReplacementRecord', array($hash, $marker, $record)),
			'successor marker and record clear remains unconfirmed');
		strictAssertSame(1, count(rXMLRPCRequest::requestsFor('branch')),
			'successor clear has one atomic attempt');
		strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
			'successor clear issues no standalone write');
		strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
			'successor clear issues no standalone multicall clear');
	}

	public function testUnknownOwnedRunNeverFallsBackToStandaloneOpenOrStart()
	{
		$hash = str_repeat('A', 40);
		$marker = str_repeat('b', 32);
		$record = str_repeat('C', 40) . '-started-1786899620';
		$replacing = str_repeat('D', 40) . '-started-1786899620';
		$cases = array(
			'predecessor restore' => array('restoreExistingTorrent', array($hash, true, true, $replacing)),
			'successor activation' => array('activateReplacement', array($hash, true, true, $marker, $record)),
		);
		foreach($cases as $label => $case)
		{
			$this->resetFakes();
			rXMLRPCRequest::queue('branch', false, false, array());
			strictAssertSame(false, strictInvoke('ruTrackerChecker', $case[0], $case[1]),
				$label . ' remains unconfirmed');
			strictAssertSame(1, count(rXMLRPCRequest::requestsFor('branch')),
				$label . ' has one atomic attempt');
			$this->assertNoRequestKeyContains('d.open', $label . ' issues no standalone open');
			$this->assertNoRequestKeyContains('d.start', $label . ' issues no standalone start');
		}
	}


	public function testPredecessorRollbackClearsOnlyItsMatchingReplacingGeneration()
	{
		$marker = self::NEW_HASH . '-started-1786899620';
		foreach(array(
			'stopped' => array(false, false, RuTrackerAtomicOwnership::SENTINEL_CLEARED),
			'started' => array(true, true, RuTrackerAtomicOwnership::SENTINEL_ACTED),
		) as $label => $wasRunning)
		{
			$this->resetFakes();
			rXMLRPCRequest::queue('branch', true, false, array($wasRunning[2]));
			strictAssertSame(true, strictInvoke('ruTrackerChecker', 'restoreExistingTorrent',
				array(self::OLD_HASH, $wasRunning[0], $wasRunning[1], $marker)),
				$label . ': rollback succeeds for its own generation');
			$branches = rXMLRPCRequest::requestsFor('branch');
			strictAssertSame(1, count($branches), $label . ': one atomic rollback branch');
			$params = $branches[0]['commands'][0]->params;
			strictAssertTrue(strpos($params[1], 'equal=d.get_custom=chk-replacing,cat=' . $marker) !== false,
				$label . ': rollback compares the exact replacing marker');
			$clearToken = '$d.set_custom=chk-replacing,';
			$clearAt = strpos($params[2], $clearToken);
			strictAssertTrue($clearAt !== false,
				$label . ': rollback writes the replacing field');
			$tail = substr($params[2], $clearAt + strlen($clearToken));
			$quoteAt = strpos($tail, '"');
			// runState nests this command and escapes its closing quote; the
			// bytes before it must be only escaping, never a field value.
			strictAssertTrue($quoteAt !== false && strspn($tail, chr(92)) === $quoteAt,
				$label . ': rollback clears chk-replacing to an empty value');
		}
	}

	// --- Persisted/RPC integers at the checker's own boundaries -------------
	//
	// intval() answers 0 for everything it cannot read, and at all three of
	// these boundaries 0 is the DANGEROUS reading: state 0 is "never checked"
	// and buys a full destructive check, a claim taken at the epoch is always
	// expired, and a staged copy reported stopped-and-closed is the one that
	// may be erased.

	public function testMalformedLiveStateOrTimeDefersInsteadOfBeingReadAsZero()
	{
		foreach(array(
			'leading zero state'   => array('03', '1700'),
			'padded state'         => array(' 3', '1700'),
			'plus-signed state'    => array('+3', '1700'),
			'state with letters'   => array('3oops', '1700'),
			'leading zero time'    => array('3', '01700'),
			'negative time'        => array('3', '-1'),
			'float time'           => array('3', '1700.0'),
		) as $label => $reply)
		{
			$this->resetFakes();
			self::queueStateRead( true, false,
				array($reply[0], $reply[1], 'lbl'));
			$state = null;
			$time = null;
			$label2 = null;
			strictAssertSame(false,
				CheckerProbe::getStateForTest(self::OLD_HASH, $state, $time, $label2),
				$label . ': an unreadable live reading must defer the check');
			strictAssertSame(ruTrackerChecker::STE_INPROGRESS, $state,
				$label . ': and must never be coerced into state zero');
			strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.hash'),
				$label . ': a read that answered proves presence; no probe is spent');
		}

		// ...and run() takes its existing "could not be read" branch: no
		// handler, no lock write, nothing at all beyond the one read.
		$this->resetFakes();
		ruTrackerChecker::registerTracker('/topic\.rpcint\.invalid/', '/tracker\.rpcint\.invalid/',
			function() { throw new RuntimeException('an unreadable state must never dispatch a handler'); });
		self::queueStateRead( true, false, array('03', '1700', ''));
		$performed = null;
		strictAssertSame(false,
			ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_UPTODATE, 1700, '', $performed),
			'the check is reported as deferred, not as a successful no-op');
		strictAssertSame(false, $performed, 'and nothing durable was consumed');
		strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
			'no INPROGRESS lock is written over a state nobody could read');
		strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')),
			'and no single custom write either');

		// Control: the same shape with canonical values still runs.
		$this->resetFakes();
		self::queueStateRead( true, false,
			array((string) ruTrackerChecker::STE_UPTODATE, '1700', 'lbl'));
		$state = null;
		$time = null;
		$label2 = null;
		strictAssertSame(true, CheckerProbe::getStateForTest(self::OLD_HASH, $state, $time, $label2),
			'a canonical reading is still read');
		strictAssertSame(ruTrackerChecker::STE_UPTODATE, $state, 'as the int it is');
		strictAssertSame(1700, $time, 'and so is chk-time');
		strictAssertSame('lbl', $label2, 'the label is untouched by any of this');

		// An UNSET custom comes back as the empty string. That, and only that,
		// is the never-checked reading.
		$this->resetFakes();
		self::queueStateRead( true, false, array('', '', ''));
		$state = null;
		$time = null;
		$label2 = null;
		strictAssertSame(true, CheckerProbe::getStateForTest(self::OLD_HASH, $state, $time, $label2),
			'a torrent that was never checked is readable, not malformed');
		strictAssertSame(0, $state, 'an unset chk-state reads as never checked');
		strictAssertSame(0, $time, 'and an unset chk-time as never stamped');
	}

	// A claim whose timestamp cannot be read is not an expired claim. Read as
	// zero it was worse than no claim at all: it was pruned on sight by every
	// worker that walked past it, so the process really holding the hash --
	// possibly mid-replacement -- kept being joined by another.
	public function testAMalformedClaimTimestampIsRetainedAndBlocksACompetingWorker()
	{
		foreach(array(
			'legacy bare leading zero' => '01',
			'legacy bare float'        => 1.5,
			'legacy bare text'         => 'held-since-forever',
			'legacy bare negative'     => '-1',
			'owned entry leading zero' => array('since' => '01', 'token' => 'aabbccdd'),
			'owned entry float'        => array('since' => 1.5, 'token' => 'aabbccdd'),
			'owned entry text'         => array('since' => 'held-since-forever', 'token' => 'aabbccdd'),
			'owned entry unstamped'    => array('token' => 'aabbccdd'),
		) as $label => $entry)
		{
			$this->resetFakes();
			RuTrackerState::save('meta-claims', array(self::OLD_HASH => $entry));

			// Far past any wall lease: an unreadable stamp still cannot identify an owner.
			$now = 1000 + ruTrackerChecker::MAX_LOCK_TIME * 10;
			strictAssertSame(false,
				strictInvoke('ruTrackerChecker', 'claimCheck', array(self::OLD_HASH, $now)),
				$label . ': a claim nobody can date is not a claim nobody holds');
			$claims = RuTrackerState::load('meta-claims');
			strictAssertTrue(isset($claims[self::OLD_HASH]),
				$label . ': and the entry is retained exactly as it was found');
			strictAssertSame(array(self::OLD_HASH), array_keys($claims),
				$label . ': nothing else is invented in its place');
		}

		// The diagnostic names the hash and never the unreadable value itself.
		$this->resetFakes();
		$this->withDebugLog(function() {
			RuTrackerState::save('meta-claims',
				array(self::OLD_HASH => array('since' => 'zzsecretzz', 'token' => 'aabbccdd')));
			FileUtil::$log = array();
			strictInvoke('ruTrackerChecker', 'claimCheck',
				array(self::OLD_HASH, 1000 + ruTrackerChecker::MAX_LOCK_TIME * 10));
			$line = strictAssertOneLogMatching(FileUtil::$log, 'unreadable timestamp',
				'the retained claim is reported once');
			strictAssertTrue(strpos($line, self::OLD_HASH) !== false,
				'the diagnostic names the hash it is about');
			strictAssertTrue(strpos($line, 'zzsecretzz') === false,
				'and never echoes the unreadable stored value back into the log');
		});

		// A readable legacy stamp still lacks a generation token: neither a
		// large wall age nor an arbitrary number proves its owner is gone.
		$this->resetFakes();
		RuTrackerState::save('meta-claims', array(self::OLD_HASH => 1000));
		strictAssertSame(false,
			strictInvoke('ruTrackerChecker', 'claimCheck',
				array(self::OLD_HASH, 1000 + ruTrackerChecker::MAX_LOCK_TIME + 1)),
			'a legacy claim stays held across a wall jump');

		$this->resetFakes();
		RuTrackerState::save('meta-claims', array(self::OLD_HASH => array('since' => 1000, 'token' => 'aabbccddeeff0011')));
		strictAssertSame(false,
			strictInvoke('ruTrackerChecker', 'claimCheck', array(self::OLD_HASH, 1001)),
			'and a readable live claim still blocks');
	}

	// And it is retained indefinitely. An unreadable timestamp supplies no
	// owner generation or lease to observe, so a contender cannot reclaim it.
	// A token holder or an explicit untokened release can clear it. Reported
	// through logDebug(), gated on conf.php's
	// shipped $rutrackerCheckDebug = false, that permanent wedge said nothing
	// at all: the fault two earlier rounds of this work were rejected for.
	//
	// The flag is set EXPLICITLY false here rather than left unset, because
	// "the shipped default" is the claim being made, and because with it ON
	// the two channels are indistinguishable -- which is how the sibling test
	// above passed while production stayed silent.
	public function testTheRetainedClaimIsReportedWithDebuggingAtItsShippedDefault()
	{
		$this->resetFakes();
		$this->withoutDebugLog(function() {
			RuTrackerState::save('meta-claims',
				array(self::OLD_HASH => array('since' => 'zzsecretzz', 'token' => 'aabbccdd')));
			FileUtil::$log = array();

			// The control first, and in this very block: the gate really is
			// shut, so nothing below arrives by accident.
			ruTrackerChecker::logDebug('a self-healing refusal says this');
			strictAssertSame(array(), FileUtil::$log,
				'a refusal that recovers on its own is silent at the shipped default, as it should be');

			strictAssertSame(false,
				strictInvoke('ruTrackerChecker', 'claimCheck',
					array(self::OLD_HASH, 1000 + ruTrackerChecker::MAX_LOCK_TIME * 10)),
				'the refusal itself is unchanged');

			$line = strictAssertOneLogMatching(FileUtil::$log, 'unreadable timestamp',
				'but the permanently blocking claim reaches the application log anyway');
			strictAssertTrue(strpos($line, self::OLD_HASH) !== false,
				'and the line names the hash it is about, so an operator can clear it');
			strictAssertTrue(strpos($line, 'zzsecretzz') === false,
				'and still never echoes the unreadable stored value back into the log');
		});

		// A corrupt claim on SOMEBODY ELSE'S hash is not this caller's problem,
		// and must not be reported to it. flushVerdicts() calls claimCheck() once
		// per deferred verdict -- so reporting every corrupt entry it reads
		// turns one wedged hash into a flood of identical lines per cycle.
		// Silence and noise are both ways of not being read.
		$this->resetFakes();
		$this->withoutDebugLog(function() {
			RuTrackerState::save('meta-claims', array(
				self::OLD_HASH => array('since' => 'zzsecretzz', 'token' => 'aabbccdd'),
			));
			FileUtil::$log = array();
			$other = str_repeat('C', 40);
			strictInvoke('ruTrackerChecker', 'claimCheck',
				array($other, 1000 + ruTrackerChecker::MAX_LOCK_TIME * 10));
			strictAssertSame(array(), FileUtil::$log,
				'a claim wedged on another hash is reported to the caller it blocks, not to every passer-by');
		});

		// Control: an ordinary readable claim writes nothing at the shipped default.
		$this->resetFakes();
		$this->withoutDebugLog(function() {
			RuTrackerState::save('meta-claims',
				array(self::OLD_HASH => array('since' => 1000, 'token' => 'aabbccddeeff0011')));
			FileUtil::$log = array();
			strictInvoke('ruTrackerChecker', 'claimCheck', array(self::OLD_HASH, 1001));
			strictAssertSame(array(), FileUtil::$log,
				'an ordinary contended claim is not an operator\'s problem and says nothing');
		});
	}

	// A forward wall-clock step must not turn a live owner into an expired
	// claim. The real two-process version of this RED is in the GAP8 lab note.
	public function testLiveClaimSurvivesAForwardClockJump()
	{
		$this->resetFakes();
		$now = time();
		$owner = ruTrackerChecker::claimCheckForWorker(self::OLD_HASH, $now);
		strictAssertTrue(is_string($owner), 'the original worker receives a token');
		strictAssertSame(false, ruTrackerChecker::claimCheckForWorker(
			self::OLD_HASH, $now + 25 * 3600),
			'a wall-clock jump alone cannot grant a second worker the live hash');
		strictAssertSame($owner, RuTrackerState::load('meta-claims')[self::OLD_HASH]['token'],
			'the original claim remains the owner');
		strictAssertSame(true, ruTrackerChecker::releaseCheckForWorker(self::OLD_HASH, $owner),
			'the original owner can still release the claim');
		strictAssertSame(array(), RuTrackerState::load('meta-claims'),
			'its token-checked release removes the owned entry');
	}

	public function testForeignClaimsRequireAnObservedLeaseAndAbsentHashBeforeReaping()
	{
		$this->resetFakes();
		$now = time();
		$absent = self::OLD_HASH;
		$live = self::NEW_HASH;
		$absentToken = str_repeat('a', 16);
		$liveToken = str_repeat('b', 16);
		RuTrackerState::save('meta-claims', array(
			$absent => array('since' => $now + 25 * 3600, 'token' => $absentToken),
			$live => array('since' => $now, 'token' => $liveToken),
		));
		strictAssertTrue(method_exists('ruTrackerChecker', 'sweepOrphanClaims'),
			'the scheduler has a separate sweep for hashes with no future check call');
		ruTrackerChecker::sweepOrphanClaims();
		$claims = RuTrackerState::load('meta-claims');
		strictAssertTrue(isset($claims[$absent]['orphan_observed'])
			&& isset($claims[$live]['orphan_observed']),
			'both exact owner generations receive a durable monotonic observation');
		strictAssertSame($absentToken, $claims[$absent]['token'],
			'a future wall stamp cannot expire an orphan on the first sweep');
		$claims[$absent]['orphan_observed']['mono'] = checkerObservedMono(
			ruTrackerChecker::MAX_LOCK_TIME + 1);
		$claims[$live]['orphan_observed']['mono'] = checkerObservedMono(
			ruTrackerChecker::MAX_LOCK_TIME + 1);
		RuTrackerState::save('meta-claims', $claims);
		rXMLRPCRequest::queue('d.hash', true, true, array());
		ruTrackerChecker::sweepOrphanClaims();
		$claims = RuTrackerState::load('meta-claims');
		strictAssertTrue(!isset($claims[$absent]),
			'only an exact missing-hash answer retires the aged orphan');
		strictAssertSame($liveToken, $claims[$live]['token'],
			'the other published generation remains held');
		rXMLRPCRequest::queue('d.hash', true, false, array($live));
		ruTrackerChecker::sweepOrphanClaims();
		strictAssertSame($liveToken, RuTrackerState::load('meta-claims')[$live]['token'],
			'an aged but present owner is never reaped');
	}

	public function testOrphanSweepRequeuesPresentAndUnknownHashBehindAnotherCandidate()
	{
		foreach(array('unknown', 'present') as $answer)
		{
			$this->resetFakes();
			$now = time();
			$first = self::OLD_HASH;
			$second = self::NEW_HASH;
			RuTrackerState::save('meta-claims', array(
				$first => array('since' => $now, 'token' => str_repeat('a', 16)),
				$second => array('since' => $now, 'token' => str_repeat('b', 16)),
			));
			ruTrackerChecker::sweepOrphanClaims();
			$claims = RuTrackerState::load('meta-claims');
			foreach(array($first, $second) as $hash)
				$claims[$hash]['orphan_observed']['mono'] = checkerObservedMono(
					ruTrackerChecker::MAX_LOCK_TIME + 1);
			RuTrackerState::save('meta-claims', $claims);
			if($answer === 'unknown')
				rXMLRPCRequest::queue('d.hash', true, true, array(), 'Permission denied');
			else
				rXMLRPCRequest::queue('d.hash', true, false, array($first));
			rXMLRPCRequest::queue('d.hash', true, true, array());
			ruTrackerChecker::sweepOrphanClaims();
			$afterFirst = RuTrackerState::load('meta-claims');
			strictAssertSame($claims[$first]['token'], $afterFirst[$first]['token'],
				$answer . ' presence cannot authorize deletion');
			strictAssertTrue($afterFirst[$first]['orphan_observed']['mono']
				> $claims[$first]['orphan_observed']['mono'],
				$answer . ' candidate is requeued behind another aged hash');
			$afterSecond = RuTrackerState::load('meta-claims');
			strictAssertTrue(!isset($afterSecond[$second]),
				'a fast ' . $answer . ' response does not starve another aged hash');
			strictAssertSame($claims[$first]['token'], $afterSecond[$first]['token'],
				'the ' . $answer . ' owner remains published');
		}
	}

	public function testOrphanSweepProcessesABoundedBatchAndDrainsTheRestNextCycle()
	{
		$this->resetFakes();
		$claims = array();
		for($i = 1; $i <= ruTrackerChecker::ORPHAN_SWEEP_BATCH + 1; $i++)
			$claims[sprintf('%040x', $i)] = array('since' => time(), 'token' => str_repeat('a', 16));
		RuTrackerState::save('meta-claims', $claims);
		ruTrackerChecker::sweepOrphanClaims();
		$claims = RuTrackerState::load('meta-claims');
		foreach($claims as &$claim)
			$claim['orphan_observed']['mono'] = checkerObservedMono(ruTrackerChecker::MAX_LOCK_TIME + 1);
		unset($claim);
		RuTrackerState::save('meta-claims', $claims);
		for($i = 0; $i < ruTrackerChecker::ORPHAN_SWEEP_BATCH; $i++)
			rXMLRPCRequest::queue('d.hash', true, true, array());
		ruTrackerChecker::sweepOrphanClaims();
		strictAssertSame(1, count(RuTrackerState::load('meta-claims')),
			'a cycle retires multiple absent claims but caps direct daemon probes');
		rXMLRPCRequest::queue('d.hash', true, true, array());
		ruTrackerChecker::sweepOrphanClaims();
		strictAssertSame(array(), RuTrackerState::load('meta-claims'),
			'the next cycle drains the finite remainder');
	}

	public function testOrphanSweepStopsAfterASlowProbeBeforeTheNextRequest()
	{
		$this->resetFakes();
		RuTrackerState::save('meta-claims', array(
			self::OLD_HASH => array('since' => time(), 'token' => str_repeat('a', 16)),
			self::NEW_HASH => array('since' => time(), 'token' => str_repeat('b', 16)),
		));
		ruTrackerChecker::sweepOrphanClaims();
		$claims = RuTrackerState::load('meta-claims');
		$claims[self::OLD_HASH]['orphan_observed']['mono'] = checkerObservedMono(
			ruTrackerChecker::MAX_LOCK_TIME + 2);
		$claims[self::NEW_HASH]['orphan_observed']['mono'] = checkerObservedMono(
			ruTrackerChecker::MAX_LOCK_TIME + 1);
		RuTrackerState::save('meta-claims', $claims);
		rXMLRPCRequest::queue('d.hash', true, true, function() {
			usleep(2100000);
			return(array());
		});
		ruTrackerChecker::sweepOrphanClaims();
		$remaining = RuTrackerState::load('meta-claims');
		strictAssertTrue(!isset($remaining[self::OLD_HASH]) && isset($remaining[self::NEW_HASH]),
			'a slow absence probe is settled, but no second RPC delays the checker cycle');
		rXMLRPCRequest::queue('d.hash', true, true, array());
		ruTrackerChecker::sweepOrphanClaims();
		strictAssertSame(array(), RuTrackerState::load('meta-claims'),
			'the deferred claim is eligible in the next cycle');
	}

	public function testOrphanSweepCannotDeleteAChangedOwnerAfterThePresenceProbe()
	{
		$this->resetFakes();
		$now = time();
		$oldToken = str_repeat('a', 16);
		$newToken = str_repeat('b', 16);
		RuTrackerState::save('meta-claims', array(self::OLD_HASH => array(
			'since' => $now, 'token' => $oldToken)));
		ruTrackerChecker::sweepOrphanClaims();
		$claims = RuTrackerState::load('meta-claims');
		$claims[self::OLD_HASH]['orphan_observed']['mono'] = checkerObservedMono(
			ruTrackerChecker::MAX_LOCK_TIME + 1);
		RuTrackerState::save('meta-claims', $claims);
		rXMLRPCRequest::queue('d.hash', true, true, function() use ($newToken) {
			RuTrackerState::update('meta-claims', function($current) use ($newToken) {
				$current[self::OLD_HASH]['token'] = $newToken;
				return($current);
			});
			return(array());
		});
		ruTrackerChecker::sweepOrphanClaims();
		$after = RuTrackerState::load('meta-claims');
		strictAssertSame($newToken, $after[self::OLD_HASH]['token'],
			'an absence answer for the old generation cannot delete a newly published owner');
		ruTrackerChecker::sweepOrphanClaims();
		$after = RuTrackerState::load('meta-claims');
		strictAssertSame($newToken, $after[self::OLD_HASH]['orphan_observed']['token'],
			'a new owner starts its own complete monotonic observation');
	}

	public function testExpiredClaimAlsoRecoversFutureInProgressState()
	{
		$future = time() + 25 * 3600;
		$handled = 0;
		$this->withVerdictSession('future-inprogress-recovery',
			ruTrackerChecker::STE_INPROGRESS, (string) $future,
			function() use (&$handled) {
				$handled++;
				return ruTrackerChecker::STE_UPTODATE;
			},
			function() use ($future, &$handled) {
				$owner = str_repeat('a', 16);
				RuTrackerState::save('meta-claims', array(self::OLD_HASH => array(
					'since' => $future, 'token' => $owner)));
				$performed = null;
				strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH,
					ruTrackerChecker::STE_INPROGRESS, $future, '', $performed),
					'a live or unexpired owner keeps the second worker out');
				strictAssertSame(0, $handled, 'the live owner was not bypassed');
				$entry = RuTrackerState::load('meta-claims')[self::OLD_HASH];
				strictAssertSame($owner, $entry['token'], 'the original owner remains');
				strictAssertTrue(isset($entry['lease_observed']), 'the exact owner was observed');
				$entry['lease_observed']['mono'] = checkerObservedMono(ruTrackerChecker::MAX_LOCK_TIME + 1);
				RuTrackerState::save('meta-claims', array(self::OLD_HASH => $entry));
				rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom', 'd.get_local_id'),
					true, false, array('', '', self::LOCAL_ID));
				$performed = null;
				strictAssertSame(true, ruTrackerChecker::run(self::OLD_HASH,
					ruTrackerChecker::STE_INPROGRESS, $future, '', $performed),
					'the new claim holder can inspect the future in-progress state');
				strictAssertSame(1, $handled,
					'an expired owner cannot leave future chk-time wedged after takeover');
				strictAssertSame(true, $performed, 'the recovered handler verdict is durable');
				strictAssertSame(array(), RuTrackerState::load('meta-claims'),
					'the recovered run released its own claim');
			});
	}

	public function testFutureClaimRecoversOnlyAfterAFullObservedMonotonicLease()
	{
		$this->resetFakes();
		$now = time();
		$owner = str_repeat('a', 16);
		RuTrackerState::save('meta-claims', array(self::OLD_HASH => array(
			'since' => $now + 25 * 3600, 'token' => $owner)));
		strictAssertSame(false, ruTrackerChecker::claimCheckForWorker(self::OLD_HASH, $now),
			'the first observer cannot expire a claim it just saw');
		$entry = RuTrackerState::load('meta-claims')[self::OLD_HASH];
		strictAssertTrue(isset($entry['lease_observed']) && is_array($entry['lease_observed']),
			'the exact owner generation has a durable observation');
		strictAssertSame($owner, $entry['lease_observed']['token'],
			'the observation names the owner it saw');
		strictAssertSame($entry['since'], $entry['lease_observed']['since'],
			'the observation names the claim timestamp it saw');
		strictAssertTrue(is_int($entry['lease_observed']['mono']),
			'the monotonic sample survives JSON as an integer');
		$before = $entry['lease_observed'];
		$entry['lease_observed']['mono'] = checkerObservedMono(ruTrackerChecker::MAX_LOCK_TIME - 1);
		RuTrackerState::save('meta-claims', array(self::OLD_HASH => $entry));
		strictAssertSame(false, ruTrackerChecker::claimCheckForWorker(self::OLD_HASH, $now),
			'an observation inside the lease cannot expire it');
		$entry = RuTrackerState::load('meta-claims')[self::OLD_HASH];
		strictAssertSame($before['clock'], $entry['lease_observed']['clock'],
			'the comparable clock identity remains attached to the owner');
		$entry['lease_observed']['mono'] = checkerObservedMono(ruTrackerChecker::MAX_LOCK_TIME + 1);
		RuTrackerState::save('meta-claims', array(self::OLD_HASH => $entry));
		$successor = ruTrackerChecker::claimCheckForWorker(self::OLD_HASH, $now);
		strictAssertTrue(is_string($successor) && $successor !== $owner,
			'a full observed monotonic lease allows exactly one successor');
		ruTrackerChecker::releaseCheckForWorker(self::OLD_HASH, $owner);
		strictAssertSame($successor, RuTrackerState::load('meta-claims')[self::OLD_HASH]['token'],
			'an overrun old worker cannot release its successor');
	}

	public function testChangedOwnerGenerationAndBadObservationCannotExpireAClaim()
	{
		$this->resetFakes();
		$now = time();
		$owner = str_repeat('a', 16);
		$next = str_repeat('b', 16);
		RuTrackerState::save('meta-claims', array(self::OLD_HASH => array(
			'since' => $now + 25 * 3600, 'token' => $owner)));
		strictAssertSame(false, ruTrackerChecker::claimCheckForWorker(self::OLD_HASH, $now),
			'the first contender observes the published owner');
		$entry = RuTrackerState::load('meta-claims')[self::OLD_HASH];
		$entry['lease_observed']['mono'] = checkerObservedMono(ruTrackerChecker::MAX_LOCK_TIME + 1);
		$entry['token'] = $next;
		RuTrackerState::save('meta-claims', array(self::OLD_HASH => $entry));
		strictAssertSame(false, ruTrackerChecker::claimCheckForWorker(self::OLD_HASH, $now),
			'a new token with the same since cannot inherit the previous owner\'s elapsed lease');
		$entry = RuTrackerState::load('meta-claims')[self::OLD_HASH];
		strictAssertSame($next, $entry['lease_observed']['token'],
			'the observation is reset for the exact new generation');
		strictAssertSame($next, $entry['future_notice_token'],
			'the classified future notice is rebound to that generation');

		foreach(array(-1, 1.5) as $badMono)
		{
			$entry['lease_observed']['mono'] = $badMono;
			RuTrackerState::save('meta-claims', array(self::OLD_HASH => $entry));
			strictAssertSame(false, ruTrackerChecker::claimCheckForWorker(self::OLD_HASH, $now),
				'a malformed monotonic sample cannot authorize takeover');
			$entry = RuTrackerState::load('meta-claims')[self::OLD_HASH];
			strictAssertSame($next, $entry['token'], 'the original generation remains held');
			strictAssertTrue(is_int($entry['lease_observed']['mono'])
				&& $entry['lease_observed']['mono'] >= 0,
				'the bad sample is replaced with a fresh local observation');
		}
	}

	public function testLegacyAndForeignClockClaimsRemainClosed()
	{
		$this->resetFakes();
		$now = time();
		RuTrackerState::save('meta-claims', array(self::OLD_HASH => $now));
		strictAssertSame(false, ruTrackerChecker::claimCheckForWorker(
			self::OLD_HASH, $now + 25 * 3600),
			'a legacy integer has no stable generation to expire after a wall jump');
		$legacy = RuTrackerState::load('meta-claims')[self::OLD_HASH];
		strictAssertSame($now, is_array($legacy) ? $legacy['since'] : $legacy,
			'the legacy timestamp is retained');
		strictAssertTrue(!is_array($legacy) || !isset($legacy['token']),
			'the legacy entry does not invent an owner token');

		$this->resetFakes();
		$owner = str_repeat('b', 16);
		RuTrackerState::save('meta-claims', array(self::OLD_HASH => array(
			'since' => $now + 25 * 3600, 'token' => $owner)));
		strictAssertSame(false, ruTrackerChecker::claimCheckForWorker(self::OLD_HASH, $now),
			'the first contender starts an observation');
		$entry = RuTrackerState::load('meta-claims')[self::OLD_HASH];
		strictAssertTrue(isset($entry['lease_observed']), 'the first clock was recorded');
		$entry['lease_observed']['clock'] = 'another-boot-and-namespace';
		$entry['lease_observed']['mono'] = 1;
		RuTrackerState::save('meta-claims', array(self::OLD_HASH => $entry));
		strictAssertSame(false, ruTrackerChecker::claimCheckForWorker(self::OLD_HASH, $now),
			'foreign monotonic time cannot authorize takeover');
		$after = RuTrackerState::load('meta-claims')[self::OLD_HASH];
		strictAssertSame($owner, $after['token'], 'the original owner stays held');
		strictAssertTrue($after['lease_observed']['clock'] !== 'another-boot-and-namespace',
			'the new local clock starts a full fresh observation');
	}

	// A backward clock step leaves the possible owner in place and writes one
	// classified diagnostic while a monotonic observation matures.
	public function testAFutureDatedClaimIsRetainedAndVisibleWithDebuggingOff()
	{
		$this->resetFakes();
		$this->withoutDebugLog(function() {
			$now = time();
			$entry = array('since' => $now + 25 * 3600, 'token' => 'aabbccddeeff0011');
			RuTrackerState::save('meta-claims', array(self::OLD_HASH => $entry));
			FileUtil::$log = array();

			strictAssertSame(false,
				strictInvoke('ruTrackerChecker', 'claimCheck', array(self::OLD_HASH, $now)),
				'a future-dated claim still protects its possibly active owner');
			$stored = RuTrackerState::load('meta-claims')[self::OLD_HASH];
			strictAssertSame($entry['since'], $stored['since'],
				'the future-dated claim keeps its timestamp');
			strictAssertSame($entry['token'], $stored['token'],
				'the future-dated claim keeps its owner token');
			strictAssertSame($entry['since'], $stored['future_notice'],
				'the notice is stored with this generation for the next CLI process');
			$line = strictAssertOneLogMatching(FileUtil::$log, 'future timestamp',
				'a long clock rollback is visible at the shipped debug setting');
			strictAssertTrue(strpos($line, self::OLD_HASH) !== false,
				'the refusal names the blocked torrent');
			strictAssertTrue(strpos($line, 'aabbccddeeff0011') === false,
				'the owner token is never logged');
			FileUtil::$log = array();
			strictAssertSame(false,
				strictInvoke('ruTrackerChecker', 'claimCheck', array(self::OLD_HASH, $now)),
				'a repeated caller still cannot steal the future-dated claim');
			strictAssertSame(array(), FileUtil::$log,
				'the same durable claim is reported once, not on every check attempt');
			strictAssertSame(true,
				ruTrackerChecker::releaseCheckForWorker(self::OLD_HASH, 'aabbccddeeff0011'),
				'the original owner can still release the marked claim');
			strictAssertSame(array(), RuTrackerState::load('meta-claims'),
				'the diagnostic marker does not trap the original owner');
		});

		$this->resetFakes();
		$this->withoutDebugLog(function() {
			$now = time();
			RuTrackerState::save('meta-claims', array(self::OLD_HASH =>
				array('since' => $now + 1, 'token' => '0011223344556677')));
			FileUtil::$log = array();
			strictAssertSame(false,
				strictInvoke('ruTrackerChecker', 'claimCheck', array(self::OLD_HASH, $now)),
				'a near-current claim remains held');
			strictAssertSame(array(), FileUtil::$log,
				'a one-second clock difference is not reported as a stalled claim');
		});
	}

	// d.get_state and d.is_open are 0/1 and nothing else. Anything else used
	// to intval() to 0, which reads as "stopped and closed" -- the single
	// reading that authorises erasing the occupant of the successor hash.
	public function testAStagedSuccessorWhoseRunStateIsUnreadableIsRetainedWhole()
	{
		foreach(array(
			'leading zero state' => array('01', 0),
			'leading zero open'  => array(0, '01'),
			'state out of range' => array(2, 0),
			'open out of range'  => array(0, 2),
			'state with letters' => array('0oops', 0),
			'float open'         => array(0, 1.0),
			'negative state'     => array('-1', 0),
			'padded open'        => array(0, ' 1'),
		) as $label => $runState)
		{
			$this->resetFakes();
			$oldHash = str_repeat('A', 40);
			Torrent::$fixtures['new-torrent'] = array('hash' => self::NEW_HASH, 'info' => array('name' => 'new.mkv'));
			rXMLRPCRequest::queue('d.hash', true, false, array(self::NEW_HASH));
			rXMLRPCRequest::queue(self::PREFLIGHT_KEY_COMMANDS, true, false,
				array(self::PLUGIN_MARKER, $runState[0], $runState[1],
					$oldHash . '-started-1786899620'));
			// Everything the coercive reading WOULD have gone on to consume is
			// queued and waiting, so a run state read as 0/1 by intval() really
			// does reach the atomic clear/activate below. Reaching it is the
			// failure this case exists to prevent.
			rXMLRPCRequest::queue('d.hash', true, true, array());
			$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_CLEARED);
			$this->queueAtomic(RuTrackerAtomicOwnership::SENTINEL_ACTED);

			strictAssertSame(
				ruTrackerChecker::STE_ERROR,
				ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), $oldHash),
				$label . ': an unreadable run state abandons the replacement with nothing changed'
			);
			$this->assertNoRequestKeyContains('d.erase',
				$label . ': the occupant of the successor hash is never erased on it');
			strictAssertSame(0, count(rXMLRPCRequest::requestsFor('branch')),
				$label . ': and no atomic clear, revive or activation is attempted either');
			strictAssertSame(null, rTorrent::$lastSend, $label . ': no load may be enqueued');
		}
	}

}

$suite = new StrictTestSuite();
$suite->addFromObject(new CheckerTest());
exit($suite->run());
