<?php

/**
 * Focused regression tests for plugins/rutracker_check/check.php.
 *
 * The runner, the assertions and the XMLRPC test double live in TestLib.php;
 * this file keeps only the checker-specific fakes: the real ruTrackerChecker
 * (evaled out of check.php), a fixture-based Torrent and a recording rTorrent.
 *
 * Two rollback tests deliberately exhaust the waitForLoad poll budget and each
 * spend about two seconds in usleep; every other test completes immediately.
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
	public static $publishResult = true;
	public static $cancelResult = true;
	public static $recoverResult = ERASEDATA_CLEANUP_NONE;
	public static $generationCancelResult = ERASEDATA_CLEANUP_NONE;
	public static $kickResult = true;

	public static function reset()
	{
		self::$calls = array();
		self::$prepareResult = true;
		self::$publishResult = true;
		self::$cancelResult = true;
		self::$recoverResult = ERASEDATA_CLEANUP_NONE;
		self::$generationCancelResult = ERASEDATA_CLEANUP_NONE;
		self::$kickResult = true;
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
		return self::$source;
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

eval(loadClassDefinition(
	__DIR__ . '/../../../plugins/rutracker_check/check.php',
	'ruTrackerChecker'
));

class CheckerProbe extends ruTrackerChecker
{
	public static function setStateForTest($hash, $state)
	{
		return parent::setState($hash, $state);
	}


	public static function getStateForTest($hash, &$state, &$time, &$label)
	{
		return parent::getState($hash, $state, $time, $label);
	}
}

// Minimal double for makeClient(): the status, and the body the download
// guard has to classify.
class Snoopy
{
	public static $nextStatus = 200;
	public static $nextResults = '';

	public $status = 0;
	public $results = '';
	public $read_timeout = 0;
	public $_fp_timeout = 0;
	public $agent = '';

	public function fetchComplex($url, $method = 'GET', $contentType = '', $body = '')
	{
		$this->status = self::$nextStatus;
		$this->results = self::$nextResults;
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
	const GETSTATE_KEY = 'd.get_custom|d.get_custom|d.get_custom1';
	const GETSTATE_KEY_COMMANDS = array('d.get_custom', 'd.get_custom', 'd.get_custom1');
	const PREFLIGHT_KEY = 'd.get_custom|d.get_state|d.is_open|d.get_custom';
	const PREFLIGHT_KEY_COMMANDS = array('d.get_custom', 'd.get_state', 'd.is_open', 'd.get_custom');
	const PLUGIN_MARKER = '0123456789abcdef0123456789abcdef';
	const OLD_HASH = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
	const NEW_HASH = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';
	const COMMIT_SNAPSHOT_KEY = 'd.get_directory_base|d.get_custom1|d.get_throttle_name|d.get_connection_seed|d.get_custom|d.get_custom';
	const COMMIT_SNAPSHOT_KEY_COMMANDS = array('d.get_directory_base', 'd.get_custom1', 'd.get_throttle_name',
		'd.get_connection_seed', 'd.get_custom', 'd.get_custom');

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
		FileUtil::$log = array();
		RuTrackerMetaFetch::$calls = array();
		RuTrackerMetaFetch::$result = null;
		ErasedataFake::reset();
		strictSetPrivateStatic('ruTrackerChecker', 'TRACKERS', array());
		strictSetPrivateStatic('ruTrackerChecker', 'ANNOUNCES', array());
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
		return(strictInvoke('ruTrackerChecker', 'buildObsoleteCleanupFiles', array($old, $new, $base)));
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
			strictAssertSame(array('erasedataPrepareObsoleteCleanup', 'erasedataPublishObsoleteCleanup', 'erasedataKickCollector'),
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
			strictAssertTrue(strpos(implode("\n", FileUtil::$log), 'leaving that transaction to the sweep') !== false,
				'the deferral is logged with the predecessor it belongs to');
		}
		finally
		{
			if($savedDebug === null) unset($GLOBALS['rutrackerCheckDebug']);
			else $GLOBALS['rutrackerCheckDebug'] = $savedDebug;
		}
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
		rXMLRPCRequest::queue('d.set_custom', true, false, array());

		strictAssertSame(
			ruTrackerChecker::STE_NOT_NEED,
			ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
			'an unmarked pre-existing target hash must mark old torrent superseded without touching either torrent'
		);
		$customWrites = rXMLRPCRequest::requestsFor('d.set_custom');
		strictAssertTrue(count($customWrites) >= 1, 'setMessage must issue d.set_custom write');
		strictAssertSame(
			array(self::OLD_HASH, 'chk-msg', ruTrackerChecker::CHKMSG_SUPERSEDED . '|' . self::NEW_HASH),
			$customWrites[0]['commands'][0]->params,
			'setMessage must record superseded|<newHash> on the predecessor hash'
		);
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

	public function testPreExistingForeignHashReturnsErrorWhenSetMessageFails()
	{
		$this->resetFakes();
		Torrent::$fixtures['new-torrent'] = array('hash' => self::NEW_HASH, 'info' => array('name' => 'new.mkv'));
		rXMLRPCRequest::queue('d.hash', true, false, array(self::NEW_HASH));
		rXMLRPCRequest::queue(self::PREFLIGHT_KEY_COMMANDS, true, false, array('', 0, 0, ''));
		rXMLRPCRequest::queue('d.set_custom', false, false, array());

		strictAssertSame(
			ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent(checkerParsed('new-torrent'), self::OLD_HASH),
			'when setMessage fails to record superseded token, createTorrent must return STE_ERROR to allow later retry'
		);
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

				FileUtil::$log = array();
				Snoopy::$nextStatus = 200;
				ruTrackerChecker::makeClient('https://tracker.test/scrape');
				strictAssertSame(0, count(FileUtil::$log), 'a successful fetch must not be logged as a failure');
			}
			finally
			{
				Snoopy::$nextStatus = 200;
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
		rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, true, array()); // the read faults
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
		rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false,
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
		rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false, array());
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
				rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false,
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
				rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false,
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
		rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false,
			array((string) ruTrackerChecker::STE_UPTODATE, (string) time(), ''));
		rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, true, array());
		rXMLRPCRequest::queue('d.get_custom|d.get_custom', false, true, array());
		rXMLRPCRequest::queue('d.hash', true, true, array());
		strictAssertSame(
			true,
			ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_UPTODATE, time(), ''),
			'a state-write race must not be reported as a check failure'
		);
		strictAssertSame(4, count(rXMLRPCRequest::$requests), 'the raced run must stop right after readback and confirming probe');
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
		rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false,
			function($commands) {
				$writes = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom');
				return array($writes[0]['commands'][0]->params[2], $writes[0]['commands'][1]->params[2]);
			});
		strictAssertSame(true, ruTrackerChecker::setState(self::OLD_HASH, ruTrackerChecker::STE_UPDATED),
			'a short reply is accepted only after the complete desired projection is observed');
		strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.get_custom|d.get_custom')),
			'the lost-response case is still measured rather than trusted');
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
			rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false,
				array((string) ruTrackerChecker::STE_UPTODATE, (string) time(), ''));
			rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array());
			rXMLRPCRequest::queue('d.set_custom|d.set_custom|d.set_custom', true, false, array());

			$performed = null;
			strictAssertSame(true, ruTrackerChecker::run(
				self::OLD_HASH, ruTrackerChecker::STE_UPTODATE, time(), '', $performed),
				'the next caller checks it');
			strictAssertSame(true, $performed,
				'an ordinary handler run acknowledges a durable scheduler obligation');
			strictAssertTrue(count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')) > 0,
				'and does write the lock');
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
			rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false, function() {
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
		rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false,
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

		// A claim whose holder died is not held forever. The claim is stamped
		// with the wall clock, not with run()'s $time argument (that is the
		// row's chk-time), so the abandoned holder is staged directly.
		RuTrackerState::save('meta-claims',
			array(self::OLD_HASH => time() - ruTrackerChecker::MAX_LOCK_TIME - 1));
		RuTrackerMetaFetch::$calls = array();
		rXMLRPCRequest::reset();
		rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false,
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

		// It overruns; the entry expires and a second worker takes it over.
		$second = strictInvoke('ruTrackerChecker', 'claimCheck',
			array(self::OLD_HASH, 1000 + ruTrackerChecker::MAX_LOCK_TIME + 1));
		strictAssertTrue($second !== false, 'an expired claim is taken over');
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

	public function testTheInProgressLockIsHonouredWhileFreshAndExpiresWhenStale()
	{
		$this->resetFakes();
		RuTrackerState::save('meta-claims', array());
		rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false,
			array((string) ruTrackerChecker::STE_INPROGRESS, (string) time(), ''));

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
			rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false,
				array((string) ruTrackerChecker::STE_INPROGRESS,
					(string) (time() - ruTrackerChecker::MAX_LOCK_TIME - 1), ''));
			rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array());
			rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array());

			strictAssertSame(true,
				ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_INPROGRESS,
					time() - ruTrackerChecker::MAX_LOCK_TIME - 1, ''),
				'an expired lock does not wedge the torrent');
			strictAssertTrue(count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')) > 0,
				'the check runs and writes its own state');
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
	// What this pins is the OUTCOME -- an unusable store yields no claim -- and
	// not which of the two guards produced it. claimCheck() refuses both when
	// the update never ran and when it ran but could not be written, and only
	// the first is reachable from a test: isolating the second needs a
	// filesystem that permits reading and flock while denying create and
	// rename, and the container the 8.1 matrix runs in is root, where
	// permissions do not deny anything at all. Defence in depth, pinned at the
	// depth a test can reach.
	public function testAClaimThatCouldNotBeWrittenIsRefused()
	{
		$this->resetFakes();
		// A state directory that cannot be created: dir() is a plain file.
		$blocked = sys_get_temp_dir() . '/chk-blocked-' . bin2hex(random_bytes(4));
		file_put_contents($blocked, 'not a directory');
		strictSetPrivateStatic('RuTrackerState', 'dir', $blocked . '/rutracker_check');
		try
		{
			strictAssertSame(false, strictInvoke('ruTrackerChecker', 'claimCheck', array(self::OLD_HASH, 1000)),
				'a claim that could not be recorded is refused, not granted');
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
		rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false,
			array((string) ruTrackerChecker::STE_META_PENDING, (string) time(), ''));
		rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array()); // the claim
		rXMLRPCRequest::queue('d.get_custom', true, false, array(''));
		rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array()); // the verdict

		$result = ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_META_PENDING, time(), '');

		strictAssertSame(true, $result, 'a still-pending pump keeps the check successful');
		strictAssertSame(1, count(RuTrackerMetaFetch::$calls), 'run must hand the meta-pending state to pump exactly once');
		strictAssertSame(self::OLD_HASH, RuTrackerMetaFetch::$calls[0]['hash'], 'pump must be called with the torrent hash');
		strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.get_custom')), 'pump must reach the XMLRPC layer, not a no-op stub');
		// Two writes: the claim that stops a concurrent cycle from pumping the
		// same fetch, and then the verdict pump() reached.
		$stateWrites = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom');
		strictAssertSame(2, count($stateWrites), 'the row is claimed, then the pump result is persisted');
		strictAssertSame(
			array(self::OLD_HASH, 'chk-state', (string) ruTrackerChecker::STE_INPROGRESS),
			$stateWrites[0]['commands'][0]->params,
			'pump() erases stubs and can commit a replacement, so the row is claimed first'
		);
		strictAssertSame(
			array(self::OLD_HASH, 'chk-state', (string) ruTrackerChecker::STE_META_PENDING),
			$stateWrites[1]['commands'][0]->params,
			'and what is left standing is the pump result, never the claim'
		);
	}

	public function testMetaPendingCompletedReplacementSkipsStateWrite()
	{
		$this->resetFakes();
		RuTrackerMetaFetch::$result = null; // createTorrent success: state already set by its own load additions
		rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false,
			array((string) ruTrackerChecker::STE_META_PENDING, (string) time(), ''));
		rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array()); // the claim
		rXMLRPCRequest::queue('d.get_custom', true, false, array(''));

		$result = ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_META_PENDING, time(), '');

		strictAssertSame(true, $result, 'a completed replacement is a successful check');
		strictAssertSame(
			1,
			count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
			'only the claim is written: after a successful replacement the old hash is gone, so no verdict follows it'
		);
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
				'expect'   => (string) ruTrackerChecker::STE_UPTODATE,
			),
			'a torrent that was never checked stays unchecked' => array(
				'previous' => 0,
				'expect'   => '0',
			),
		);

		foreach($rows as $label => $row)
		{
			$this->resetFakes();
			$dir = sys_get_temp_dir() . '/rut-cold-' . bin2hex(random_bytes(5)) . '/';
			mkdir($dir, 0777, true);
			$fname = $dir . self::OLD_HASH . '.torrent';
			file_put_contents($fname, 'x');
			rTorrentSettings::get()->session = $dir;

			Torrent::$fixtures[$fname] = array(
				'comment' => 'http://topic.cold-test.invalid/1',
				'announce' => 'http://tracker.cold-test.invalid/announce',
			);
			ruTrackerChecker::registerTracker('/topic\.cold-test\.invalid/', '/tracker\.cold-test\.invalid/',
				function($url) { return ruTrackerChecker::STE_UNCHANGED; });

			rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false,
				array((string) $row['previous'], (string) time(), ''));
			rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array()); // the INPROGRESS lock
			rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array()); // the restore
			rXMLRPCRequest::queue('d.set_custom|d.set_custom|d.set_custom', true, false, array()); // if it restores UPTODATE

				$performed = null;
				$result = ruTrackerChecker::run(self::OLD_HASH, $row['previous'], time(), '', $performed);

				strictAssertSame(true, $result, $label . ': an unchanged verdict is not a failed check');
				strictAssertSame(false, $performed,
					$label . ': an unchanged handler answer did not durably consume correction work');
			$writes = array();
			foreach(rXMLRPCRequest::$requests as $request)
				foreach($request['commands'] as $command)
					if(($command->command === getCmd('d.set_custom')) && ($command->params[1] === 'chk-state'))
						$writes[] = $command->params[2];
			strictAssertSame(
				array((string) ruTrackerChecker::STE_INPROGRESS, $row['expect']),
				$writes,
				$label . ': the lock is written, then the previous verdict is put back'
			);
			strictRemoveTree($dir);
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
			$this->resetFakes();
			$dir = sys_get_temp_dir() . '/rut-performed-' . bin2hex(random_bytes(5)) . '/';
			mkdir($dir, 0777, true);
			$fname = $dir . self::OLD_HASH . '.torrent';
			file_put_contents($fname, 'x');
			rTorrentSettings::get()->session = $dir;
			Torrent::$fixtures[$fname] = array(
				'comment' => 'http://topic.performed-test.invalid/1',
				'announce' => 'http://tracker.performed-test.invalid/announce',
			);
			ruTrackerChecker::registerTracker('/topic\.performed-test\.invalid/',
				'/tracker\.performed-test\.invalid/', function() {
					return ruTrackerChecker::STE_UPDATED;
				});
			rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false,
				array((string) ruTrackerChecker::STE_UPTODATE, '100', ''));
			rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array());
			rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), $case['write'], false, array());
			if(!$case['write'])
				rXMLRPCRequest::queue('d.hash', true, $case['fault'],
					$case['fault'] ? array() : array(self::OLD_HASH));

			try
			{
				$performed = null;
				strictAssertSame(true,
					ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_UPTODATE, 100, '', $performed),
					$label . ': checker invocation itself completed');
				strictAssertSame($case['expect'], $performed,
					$label . ': durable correction acknowledgement follows the final write result');
			}
			finally
			{
				strictRemoveTree($dir);
			}
		}
	}

	public function testPerformedDoesNotAcknowledgeATruncatedUnprovedFinalWrite()
	{
		$this->resetFakes();
		$dir = sys_get_temp_dir() . '/rut-performed-short-' . bin2hex(random_bytes(5)) . '/';
		mkdir($dir, 0777, true);
		$fname = $dir . self::OLD_HASH . '.torrent';
		file_put_contents($fname, 'x');
		rTorrentSettings::get()->session = $dir;
		Torrent::$fixtures[$fname] = array(
			'comment' => 'http://topic.performed-short.invalid/1',
			'announce' => 'http://tracker.performed-short.invalid/announce',
		);
		ruTrackerChecker::registerTracker('/topic\.performed-short\.invalid/',
			'/tracker\.performed-short\.invalid/', function() { return ruTrackerChecker::STE_UPDATED; });
		rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false,
			array((string) ruTrackerChecker::STE_UPTODATE, '100', ''));
		// The INPROGRESS claim is fully acknowledged.
		rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array(0, 0));
		// The final verdict has a truncated positive reply and a mismatching readback.
		rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array(0));
		rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false, array('1', '0'));

		try
		{
			$performed = null;
			strictAssertSame(true,
				ruTrackerChecker::run(self::OLD_HASH, ruTrackerChecker::STE_UPTODATE, 100, '', $performed),
				'the checker invocation itself completes');
			strictAssertSame(false, $performed,
				'forum correction work is not acknowledged without a measured final verdict');
			strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.get_custom|d.get_custom')),
				'the final short reply is verified exactly once');
		}
		finally
		{
			strictRemoveTree($dir);
		}
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
			rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false,
				array((string) ruTrackerChecker::STE_DELETED, (string) time(), 'tv-sonarr'));
			rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array()); // the IGNORED state
			rXMLRPCRequest::queue('d.set_custom', true, false, array());               // the message clear

			$performed = null;
			$result = ruTrackerChecker::run(
				self::OLD_HASH, ruTrackerChecker::STE_DELETED, time(), 'tv-sonarr', $performed);
			strictAssertSame(true, $result, 'an ignored label is not a failed check');
			strictAssertSame(false, $performed,
				'an ignored-label decision is not a forum-aware tracker check');

			$writes = array();
			foreach(rXMLRPCRequest::$requests as $request)
				foreach($request['commands'] as $command)
					if($command->command === getCmd('d.set_custom'))
						$writes[$command->params[1]] = $command->params[2];

			strictAssertSame((string) ruTrackerChecker::STE_IGNORED, $writes['chk-state'] ?? null,
				'the state becomes IGNORED');
			strictAssertSame('', $writes['chk-msg'] ?? null,
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

				rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false,
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
			rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false, array('3', '1000', '900', ''));
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
			rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false,
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
		rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false, array('03', '1700', ''));
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
		rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false,
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
		rXMLRPCRequest::queue(self::GETSTATE_KEY_COMMANDS, true, false, array('', '', ''));
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

			// Far past any lease: a readable stamp of this age WOULD be pruned.
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

		// Control: a readable stamp of the same age still expires, and a
		// readable fresh one still blocks -- neither behaviour moved.
		$this->resetFakes();
		RuTrackerState::save('meta-claims', array(self::OLD_HASH => 1000));
		strictAssertTrue(
			strictInvoke('ruTrackerChecker', 'claimCheck',
				array(self::OLD_HASH, 1000 + ruTrackerChecker::MAX_LOCK_TIME + 1)) !== false,
			'a readable expired claim is still taken over');

		$this->resetFakes();
		RuTrackerState::save('meta-claims', array(self::OLD_HASH => array('since' => 1000, 'token' => 'aabbccdd')));
		strictAssertSame(false,
			strictInvoke('ruTrackerChecker', 'claimCheck', array(self::OLD_HASH, 1001)),
			'and a readable live claim still blocks');
	}

	// And it is retained FOR EVER. A readable claim ages out of the loop above
	// after MAX_LOCK_TIME; an unreadable one has no age to measure, and the
	// only other way an entry leaves meta-claims is releaseCheck(), which runs
	// solely for a worker holding the claim's own token -- a token no worker
	// can ever be granted, because claimCheck() refuses this hash before
	// issuing one. So the hash it names is never checked again and nothing
	// repairs the entry. Reported through logDebug(), gated on conf.php's
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
		// and must not be reported to it. The loop visits every claim in the
		// document on every call, and flushVerdicts() calls claimCheck() once
		// per deferred verdict -- so reporting each corrupt entry it walks past
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

		// Control: a readable claim -- live or expired -- writes nothing at
		// the shipped default either way.
		$this->resetFakes();
		$this->withoutDebugLog(function() {
			RuTrackerState::save('meta-claims',
				array(self::OLD_HASH => array('since' => 1000, 'token' => 'aabbccdd')));
			FileUtil::$log = array();
			strictInvoke('ruTrackerChecker', 'claimCheck', array(self::OLD_HASH, 1001));
			strictAssertSame(array(), FileUtil::$log,
				'an ordinary contended claim is not an operator\'s problem and says nothing');
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
