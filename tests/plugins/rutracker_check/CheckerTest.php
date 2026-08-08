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

function loadClassDefinition($filename, $className)
{
	$source = file_get_contents($filename);
	$offset = strpos($source, 'class ' . $className);
	if($offset === false)
		throw new RuntimeException("Class {$className} was not found in {$filename}");
	// ruTrackerChecker is the final declaration in check.php.
	return substr($source, $offset);
}

function getCmd($command)
{
	return $command;
}

class FileUtil
{
	public static $log = array();

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
	public $info = array();
	private $hash = '';
	private $hasErrors = false;
	private $announceUrl = '';
	private $announceList = array();
	private $commentUrl = '';

	public function __construct($source)
	{
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

class rTorrent
{
	public static $source = false;
	public static $sendResult = false;
	public static $lastSend = null;
	public static $sends = array();

	public static function getSource($hash)
	{
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

	public static function getStateForTest($hash, &$state, &$time, &$successful_time, &$label)
	{
		return parent::getState($hash, $state, $time, $successful_time, $label);
	}
}

// Minimal double for makeClient(): only the status matters to the tests.
class Snoopy
{
	public static $nextStatus = 200;

	public $status = 0;
	public $results = '';
	public $read_timeout = 0;
	public $_fp_timeout = 0;
	public $agent = '';

	public function fetchComplex($url, $method = 'GET', $contentType = '', $body = '')
	{
		$this->status = self::$nextStatus;
		return true;
	}
}

class CheckerTest
{
	const SNAPSHOT_KEY = 'd.get_directory_base|d.get_custom1|d.get_throttle_name|d.get_connection_seed|d.get_state|d.is_open|d.stop|d.close';
	const GETSTATE_KEY = 'd.get_custom|d.get_custom|d.get_custom|d.get_custom1';
	const PREFLIGHT_KEY = 'd.get_custom|d.get_state|d.is_open';
	const PREFLIGHT_KEY_COMMANDS = array('d.get_custom', 'd.get_state', 'd.is_open');

	private function resetFakes()
	{
		Torrent::$fixtures = array();
		rTorrent::$source = false;
		rTorrent::$sendResult = false;
		rTorrent::$lastSend = null;
		rTorrent::$sends = array();
		rXMLRPCRequest::reset();
		FileUtil::$log = array();
		RuTrackerMetaFetch::$calls = array();
		RuTrackerMetaFetch::$result = null;
		strictSetPrivateStatic('ruTrackerChecker', 'TRACKERS', array());
		strictSetPrivateStatic('ruTrackerChecker', 'ANNOUNCES', array());
	}

	// Fixtures for a replacement of hash OLD by hash NEW.
	private function stageTorrents($oldInfo = array(), $newInfo = array())
	{
		Torrent::$fixtures['new-torrent'] = array('hash' => 'NEW', 'info' => $newInfo);
		rTorrent::$source = new Torrent(array('hash' => 'OLD', 'info' => $oldInfo));
		rTorrent::$sendResult = 'NEW';
	}

	private function queueViews($views = null)
	{
		rXMLRPCRequest::queue('d.views', true, false,
			$views === null ? array('main', 'rat_2', 'rat_7', 'rat_9', 'rat_bad', 'rat_2_extra') : $views);
	}

	private function queueSnapshot($baseDir, $state = 1, $open = 1)
	{
		rXMLRPCRequest::queue(
			array('d.get_directory_base', 'd.get_custom1', 'd.get_throttle_name', 'd.get_connection_seed', 'd.get_state', 'd.is_open', 'd.stop', 'd.close'),
			true,
			false,
			array($baseDir, 'label', 'slow', 'seed-value', $state, $open, 0, 0)
		);
	}

	// Preflight (NEW hash absent), ratio views, then the snapshot-and-stop multicall.
	private function queueTransactionStart($baseDir, $state = 1, $open = 1)
	{
		rXMLRPCRequest::queue('d.hash', true, true, array());
		$this->queueViews();
		$this->queueSnapshot($baseDir, $state, $open);
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

	// Everything a committed replacement needs except the activation commands.
	private function stageHappyReplacement($baseDir, $state = 1, $open = 1, $oldInfo = array(), $newInfo = array())
	{
		$this->stageTorrents($oldInfo, $newInfo);
		$this->queueTransactionStart($baseDir, $state, $open);
		$this->queueLoadConfirmed();
		rXMLRPCRequest::queue('d.erase', true, false, array(0));
		rXMLRPCRequest::queue('d.set_custom', true, false, array());
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

	private function invokeCleanup($oldTorrent, $newTorrent, $baseDir)
	{
		strictInvoke('ruTrackerChecker', 'cleanupObsoleteFiles', array($oldTorrent, $newTorrent, $baseDir));
	}

	public function testStartedReplacementSucceeds()
	{
		$this->resetFakes();
		$this->stageHappyReplacement(sys_get_temp_dir());
		rXMLRPCRequest::queue('d.start', true, false, array(0));
		rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1));

		strictAssertSame(null, ruTrackerChecker::createTorrent('new-torrent', 'OLD'), 'a started replacement should succeed');
		strictAssertSame(false, rTorrent::$lastSend['isStart'], 'the replacement must be staged stopped');
		strictAssertSame(sys_get_temp_dir(), rTorrent::$lastSend['directory'], 'the staged copy must reuse the old base directory');
		strictAssertSame('label', rTorrent::$lastSend['label'], 'the staged copy must reuse the old label');
		$addition = rTorrent::$lastSend['addition'];
		strictAssertTrue(strpos($addition[0], 'd.set_custom=chk-replacement,') === 0, 'the ownership marker must be the first load command');
		strictAssertTrue(in_array('d.set_connection_seed=seed-value', $addition, true), 'the connection seed must be forwarded');
		strictAssertTrue(in_array('d.set_throttle_name=slow', $addition, true), 'the throttle must be forwarded');
		$views = array_values(array_filter($addition, function($command) {
			return strpos($command, 'view.set_visible=') === 0;
		}));
		strictAssertSame(
			array('view.set_visible=rat_2', 'view.set_visible=rat_7', 'view.set_visible=rat_9'),
			$views,
			'exactly the rat_N view memberships must be forwarded'
		);

		$waits = $this->requestIndexes('d.get_custom');
		$oldErases = $this->requestIndexes('d.erase', 'OLD');
		strictAssertSame(1, count($oldErases), 'the old hash must be erased exactly once');
		strictAssertTrue(count($waits) === 1 && $oldErases[0] > $waits[0], 'the old hash may be erased only after the staged copy is confirmed');
		strictAssertSame(1, count($this->requestIndexes('d.start', 'NEW')), 'the replacement must be started after commit');

		$clears = rXMLRPCRequest::requestsFor('d.set_custom');
		strictAssertSame(1, count($clears), 'the replacement marker must be cleared exactly once');
		strictAssertSame(array('NEW', 'chk-replacement', ''), $clears[0]['commands'][0]->params, 'the marker clear must target the new hash');
	}

	public function testStoppedOpenReplacementUsesOpen()
	{
		$this->resetFakes();
		$this->stageHappyReplacement(sys_get_temp_dir(), 0, 1);
		rXMLRPCRequest::queue('d.open', true, false, array(0));
		rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(0, 1));

		strictAssertSame(null, ruTrackerChecker::createTorrent('new-torrent', 'OLD'), 'a stopped-but-open replacement should succeed');
		strictAssertSame(1, count($this->requestIndexes('d.open', 'NEW')), 'a stopped-but-open torrent must be reopened, not started');
		strictAssertSame(0, count($this->requestIndexes('d.start')), 'a stopped torrent must never be started');
	}

	public function testFullyStoppedReplacementSkipsActivation()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-stopped-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'old');
		$this->stageHappyReplacement($base, 0, 0, array('name' => 'old.mkv'), array('name' => 'new.mkv'));

		try
		{
			strictAssertSame(null, ruTrackerChecker::createTorrent('new-torrent', 'OLD'), 'a fully stopped replacement should still commit');
			strictAssertSame(0, count($this->requestIndexes('d.start')), 'a fully stopped torrent must not be started');
			strictAssertSame(0, count($this->requestIndexes('d.open')), 'a fully stopped torrent must not be opened');
			strictAssertTrue(!file_exists($base . '/old.mkv'), 'cleanup must still run for a stopped replacement');
			strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.set_custom')), 'the marker must still be cleared for a stopped replacement');
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
		rXMLRPCRequest::queue('d.start', true, false, array(0));
		// Started but still closed: the scheduler has not granted a slot yet.
		rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 0));

		strictAssertSame(null, ruTrackerChecker::createTorrent('new-torrent', 'OLD'), 'a started-but-closed replacement is an activation success');
		strictAssertSame(1, count($this->requestIndexes('d.start', 'NEW')), 'a scheduler-queued start must not be retried');
		strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.get_state|d.is_open')), 'activation must be confirmed on the first attempt');
	}

	public function testPreExistingForeignHashLeavesOldTorrentUntouched()
	{
		$this->resetFakes();
		Torrent::$fixtures['new-torrent'] = array('hash' => 'NEW', 'info' => array('name' => 'new.mkv'));
		rXMLRPCRequest::queue('d.hash', true, false, array('NEW'));
		rXMLRPCRequest::queue(self::PREFLIGHT_KEY_COMMANDS, true, false, array('', 0, 0));

		strictAssertSame(
			ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent('new-torrent', 'OLD'),
			'an unmarked pre-existing target hash must abort the replacement'
		);
		$probes = rXMLRPCRequest::requestsFor('d.hash');
		strictAssertSame(1, count($probes), 'the preflight must issue exactly one hash probe');
		strictAssertSame('NEW', $probes[0]['commands'][0]->params, 'the preflight probe must target the new hash, not the old one');
		$markerReads = rXMLRPCRequest::requestsFor(self::PREFLIGHT_KEY);
		strictAssertSame(1, count($markerReads), 'the pre-existing hash must be inspected exactly once');
		strictAssertSame(array('NEW', 'chk-replacement'), $markerReads[0]['commands'][0]->params, 'the marker read must target the new hash');
		$this->assertNoRequestKeyContains('d.stop', 'a preflight conflict must not stop anything');
		$this->assertNoRequestKeyContains('d.erase', 'a foreign target hash must never be erased');
		strictAssertSame(null, rTorrent::$lastSend, 'a preflight conflict must not enqueue a load');
	}

	public function testLiveTorrentWithStaleMarkerIsNotAdopted()
	{
		$this->resetFakes();
		Torrent::$fixtures['new-torrent'] = array('hash' => 'NEW', 'info' => array('name' => 'new.mkv'));
		rXMLRPCRequest::queue('d.hash', true, false, array('NEW'));
		// A committed replacement whose final marker clear was lost: running.
		rXMLRPCRequest::queue(self::PREFLIGHT_KEY_COMMANDS, true, false, array('stale-marker-from-dead-run', 1, 1));
		rXMLRPCRequest::queue('d.set_custom', true, false, array());

		strictAssertSame(
			ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent('new-torrent', 'OLD'),
			'a live torrent with a leftover marker must not be adopted'
		);
		$this->assertNoRequestKeyContains('d.erase', 'a live marked torrent must never be erased');
		$clears = rXMLRPCRequest::requestsFor('d.set_custom');
		strictAssertSame(1, count($clears), 'the stale marker must be repaired');
		strictAssertSame(array('NEW', 'chk-replacement', ''), $clears[0]['commands'][0]->params, 'the repair must clear the marker of the live torrent');
		strictAssertSame(null, rTorrent::$lastSend, 'no load may be enqueued');
	}

	public function testOrphanedStagedCopyIsAdoptedAndReplaced()
	{
		$this->resetFakes();
		$this->stageTorrents();
		rXMLRPCRequest::queue('d.hash', true, false, array('NEW'));
		rXMLRPCRequest::queue(self::PREFLIGHT_KEY_COMMANDS, true, false, array('stale-marker-from-dead-run', 0, 0));
		rXMLRPCRequest::queue('d.erase', true, false, array(0));
		$this->queueViews();
		$this->queueSnapshot(sys_get_temp_dir(), 1, 1);
		$this->queueLoadConfirmed();
		rXMLRPCRequest::queue('d.erase', true, false, array(0));
		rXMLRPCRequest::queue('d.start', true, false, array(0));
		rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1));

		strictAssertSame(null, ruTrackerChecker::createTorrent('new-torrent', 'OLD'), 'an orphaned marked staged copy must be discarded and replaced');
		$erases = rXMLRPCRequest::requestsFor('d.erase');
		strictAssertSame(2, count($erases), 'the orphan and the old hash must each be erased once');
		strictAssertSame('NEW', $erases[0]['commands'][0]->params, 'the orphaned staged copy must be erased first');
		strictAssertSame('OLD', $erases[1]['commands'][0]->params, 'the old hash must be erased at commit');
	}

	public function testRollbackRestoresOldTorrentEvenWhenStagedStatusUnknown()
	{
		$this->resetFakes();
		$this->stageTorrents();
		$this->queueTransactionStart(sys_get_temp_dir(), 1, 1);
		// Nothing queued for d.get_custom: every waitForLoad poll fails.
		rXMLRPCRequest::queue('d.start', true, false, array(0));

		strictAssertSame(
			ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent('new-torrent', 'OLD'),
			'an unconfirmed staged copy must abort the replacement'
		);
		strictAssertSame(
			ruTrackerChecker::LOAD_WAIT_ATTEMPTS,
			count(rXMLRPCRequest::requestsFor('d.get_custom')),
			'the staged copy must be polled until the wait budget is exhausted'
		);
		strictAssertSame(1, count($this->requestIndexes('d.start', 'OLD')), 'the old torrent must be restored even when the staged status is unknown');
		strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')), 'a hash of unknown ownership must not be erased blindly');
	}

	public function testCommitEraseWithUnknownOldStateLeavesStagedCopy()
	{
		$this->resetFakes();
		$this->stageTorrents();
		$this->queueTransactionStart(sys_get_temp_dir(), 1, 1);
		$this->queueLoadConfirmed();
		rXMLRPCRequest::queue('d.erase', false, true, array());
		// Nothing queued for the follow-up d.hash probe: the old torrent's fate
		// is unknowable, so the marked staged copy must be left for adoption.

		strictAssertSame(
			ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent('new-torrent', 'OLD'),
			'an unknowable commit outcome must abort the replacement'
		);
		$erases = rXMLRPCRequest::requestsFor('d.erase');
		strictAssertSame(1, count($erases), 'only the old-hash erase may be attempted');
		strictAssertSame('OLD', $erases[0]['commands'][0]->params, 'the staged copy must never be erased while the old fate is unknown');
		strictAssertSame(0, count($this->requestIndexes('d.start')), 'nothing may be restarted while both fates are unknown');
		strictAssertSame(0, count($this->requestIndexes('d.open')), 'nothing may be reopened while both fates are unknown');
	}

	public function testCurlExitCodeStatusIsLoggedAsTransportFailure()
	{
		$this->resetFakes();
		$savedDebug = isset($GLOBALS['rutrackerCheckDebug']) ? $GLOBALS['rutrackerCheckDebug'] : null;
		$GLOBALS['rutrackerCheckDebug'] = true;
		try
		{
			// The https path stores curl's exit code (6 = DNS failure) as status.
			Snoopy::$nextStatus = 6;
			ruTrackerChecker::makeClient('https://tracker.test/scrape');
			strictAssertSame(1, count(FileUtil::$log), 'a curl exit-code status must be logged as a failed fetch');
			strictAssertTrue(
				strpos(FileUtil::$log[0], 'Snoopy fetch failed: host=tracker.test status=6') !== false,
				'the transport-failure log line must carry the host and status'
			);

			FileUtil::$log = array();
			Snoopy::$nextStatus = 200;
			ruTrackerChecker::makeClient('https://tracker.test/scrape');
			strictAssertSame(0, count(FileUtil::$log), 'a successful fetch must not be logged as a failure');
		}
		finally
		{
			Snoopy::$nextStatus = 200;
			if($savedDebug === null)
				unset($GLOBALS['rutrackerCheckDebug']);
			else
				$GLOBALS['rutrackerCheckDebug'] = $savedDebug;
		}
	}

	// The five replacements observed sitting stopped on the owner's live system
	// could not be diagnosed because these two facts were nowhere in the log:
	// what createTorrent() read for the old torrent, and whether activation was
	// skipped because of it. Log-only, no behaviour change -- activation still
	// does exactly what it did.
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

	public function testActivationEarlyReturnIsLogged()
	{
		$this->resetFakes();
		$this->withDebugLog(function() {
			strictAssertSame(
				true,
				strictInvoke('ruTrackerChecker', 'activateReplacement', array('NEW', false, false)),
				'a replacement whose predecessor was neither open nor started is still a success'
			);
			strictAssertSame(0, count(rXMLRPCRequest::$requests), 'the early return issues no command at all');
			$line = strictAssertOneLogMatching(FileUtil::$log, 'activateReplacement',
				'the branch that used to be silent now says it was taken');
			strictAssertEnglish($line, 'the skipped-activation line');
			strictAssertTrue(strpos($line, 'NEW') !== false, 'the line names the replacement: ' . $line);
			strictAssertTrue(strpos($line, 'neither open nor started') !== false,
				'the line says why activation was skipped: ' . $line);
		});
	}

	public function testCommitPointRunStateIsLogged()
	{
		$this->resetFakes();
		$this->withDebugLog(function() {
			$this->stageHappyReplacement(sys_get_temp_dir(), 0, 0);

			strictAssertSame(null, ruTrackerChecker::createTorrent('new-torrent', 'OLD'),
				'a fully stopped replacement still commits');
			$line = strictAssertOneLogMatching(FileUtil::$log, 'old run state at commit',
				'the input to the activation decision is recorded');
			strictAssertEnglish($line, 'the commit-point run-state line');
			strictAssertTrue(strpos($line, 'started=0 open=0') !== false,
				'the exact pair of values the decision was made on: ' . $line);
			strictAssertTrue(strpos($line, 'OLD') !== false, 'the line names the old torrent: ' . $line);
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
		rXMLRPCRequest::queue('d.start', true, false, array(0));

		strictAssertSame(
			ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent('new-torrent', 'OLD'),
			'a staged hash owned by another worker must abort the replacement'
		);
		strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.get_custom')), 'a foreign marker must be recognised on the first poll');
		strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')), 'a foreign staged copy must never be erased');
		strictAssertSame(1, count($this->requestIndexes('d.start', 'OLD')), 'the old torrent must be restored after a foreign takeover');
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
		rXMLRPCRequest::queue('d.start', true, false, array(0));

		try
		{
			strictAssertSame(
				ruTrackerChecker::STE_ERROR,
				ruTrackerChecker::createTorrent('new-torrent', 'OLD'),
				'a synchronous load failure must abort the replacement'
			);
			strictAssertTrue(is_file($base . '/old.mkv'), 'a failed load must not clean up any files');
			strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.erase')), 'no hash may be erased when enqueueing the new torrent fails');
			strictAssertSame(1, count($this->requestIndexes('d.start', 'OLD')), 'the old torrent must be restored after a failed load');
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
		rXMLRPCRequest::queue('d.erase', true, true, array());
		rXMLRPCRequest::queue('d.hash', true, true, array());
		rXMLRPCRequest::queue('d.start', true, false, array(0));
		rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1));

		strictAssertSame(null, ruTrackerChecker::createTorrent('new-torrent', 'OLD'), 'an already-gone old hash means the replacement is committed');
		$erases = rXMLRPCRequest::requestsFor('d.erase');
		strictAssertSame(1, count($erases), 'only the raced commit erase may run');
		strictAssertSame('OLD', $erases[0]['commands'][0]->params, 'the commit erase must target the old hash');
		strictAssertSame(false, $erases[0]['important'], 'the commit erase must be non-important');
		$probes = rXMLRPCRequest::requestsFor('d.hash');
		strictAssertSame(2, count($probes), 'a failed commit erase needs exactly one follow-up probe');
		strictAssertSame('OLD', $probes[1]['commands'][0]->params, 'the post-erase probe must recheck the old hash');
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
		rXMLRPCRequest::queue('d.erase', true, true, array());
		rXMLRPCRequest::queue('d.hash', true, false, array('OLD'));
		rXMLRPCRequest::queue('d.erase', true, false, array(0));
		rXMLRPCRequest::queue('d.start', true, false, array(0));

		try
		{
			strictAssertSame(
				ruTrackerChecker::STE_ERROR,
				ruTrackerChecker::createTorrent('new-torrent', 'OLD'),
				'a failed commit erase with the old hash still present must roll back'
			);
			strictAssertTrue(is_file($base . '/old.mkv'), 'an aborted commit must not clean up any files');
			$erases = rXMLRPCRequest::requestsFor('d.erase');
			strictAssertSame(2, count($erases), 'rollback must discard the staged copy after the failed commit erase');
			strictAssertSame('OLD', $erases[0]['commands'][0]->params, 'the commit erase targets the old hash');
			strictAssertSame('NEW', $erases[1]['commands'][0]->params, 'rollback erases the staged copy');
			strictAssertSame(1, count($this->requestIndexes('d.start', 'OLD')), 'the old started torrent must return through the scheduler');
			strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.set_custom')), 'the marker of a discarded staged copy needs no clearing');
		}
		finally
		{
			strictRemoveTree($base);
		}
	}

	public function testActivationFailureAfterCommitStillFinishes()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-activation-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'remove');
		$this->stageHappyReplacement($base, 1, 1, array('name' => 'old.mkv'), array('name' => 'new.mkv'));
		// No activation responses queued: both start attempts and checks fail.

		try
		{
			strictAssertSame(null, ruTrackerChecker::createTorrent('new-torrent', 'OLD'), 'activation trouble after commit must not fail the check');
			strictAssertSame(2, count(rXMLRPCRequest::requestsFor('d.get_state|d.is_open')), 'activation must be attempted exactly twice');
			strictAssertTrue(!file_exists($base . '/old.mkv'), 'cleanup must run even when activation fails');
			$clears = rXMLRPCRequest::requestsFor('d.set_custom');
			strictAssertSame(1, count($clears), 'the marker must be cleared even when activation fails');
			strictAssertSame(array('NEW', 'chk-replacement', ''), $clears[0]['commands'][0]->params, 'the marker clear must target the new hash');
		}
		finally
		{
			strictRemoveTree($base);
		}
	}

	public function testMissingOldMetainfoAbortsBeforeStoppingAnything()
	{
		$this->resetFakes();
		Torrent::$fixtures['new-torrent'] = array('hash' => 'NEW', 'info' => array('name' => 'new.mkv'));
		rXMLRPCRequest::queue('d.hash', true, true, array());

		strictAssertSame(
			ruTrackerChecker::STE_ERROR,
			ruTrackerChecker::createTorrent('new-torrent', 'OLD'),
			'replacement needs the old metainfo for a safe post-commit recovery'
		);
		strictAssertSame(1, count(rXMLRPCRequest::$requests), 'missing old metainfo must abort right after the preflight probe');
		strictAssertSame('d.hash', rXMLRPCRequest::$requests[0]['key'], 'only the preflight probe may run without the old metainfo');
		strictAssertSame(null, rTorrent::$lastSend, 'missing old metainfo must not enqueue a replacement');
	}

	public function testStateSnapshotAndStopShareOneMulticall()
	{
		$this->resetFakes();
		$this->stageHappyReplacement(sys_get_temp_dir());
		rXMLRPCRequest::queue('d.start', true, false, array(0));
		rXMLRPCRequest::queue(array('d.get_state', 'd.is_open'), true, false, array(1, 1));

		strictAssertSame(null, ruTrackerChecker::createTorrent('new-torrent', 'OLD'), 'the happy path should succeed');
		strictAssertSame(1, count(rXMLRPCRequest::requestsFor(self::SNAPSHOT_KEY)), 'state snapshot and stop/close must share one multicall');
		strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.stop|d.close')), 'no standalone stop/close request may race the snapshot');
		strictAssertSame(0, count(rXMLRPCRequest::requestsFor('d.stop')), 'no lone stop command may race the snapshot');
	}

	public function testInvalidPayloadIsReportedAsDeletedTopic()
	{
		$this->resetFakes();
		Torrent::$fixtures['not-a-torrent'] = array('errors' => true);

		strictAssertSame(
			ruTrackerChecker::STE_DELETED,
			ruTrackerChecker::createTorrent('not-a-torrent', 'OLD'),
			'legacy handlers rely on an unparseable payload meaning a removed topic'
		);
		strictAssertSame(0, count(rXMLRPCRequest::$requests), 'a parse failure must not touch rTorrent');
	}

	public function testMissingHashSkipsStateRead()
	{
		$this->resetFakes();
		rXMLRPCRequest::queue('d.hash', true, true, array());
		$state = null;
		$time = null;
		$successful_time = null;
		$label = null;

		strictAssertSame(false, CheckerProbe::getStateForTest('MISSING', $state, $time, $successful_time, $label), 'a missing hash must fail the state read');
		strictAssertSame(ruTrackerChecker::STE_NOT_NEED, $state, 'a missing hash must resolve to STE_NOT_NEED');
		strictAssertSame(0, count(rXMLRPCRequest::requestsFor(self::GETSTATE_KEY)), 'a missing hash must not read custom state');

		rXMLRPCRequest::reset();
		rXMLRPCRequest::queue('d.hash', true, true, array());
		strictAssertSame(true, ruTrackerChecker::run('MISSING'), 'a stale worker must be a successful no-op');
		strictAssertSame(1, count(rXMLRPCRequest::$requests), 'the stale worker path should only probe the hash');
	}

	public function testStateWriteRaceReportsMissingHashWithoutAnError()
	{
		$this->resetFakes();
		rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, true, array());
		rXMLRPCRequest::queue('d.hash', true, true, array());

		strictAssertSame(null, CheckerProbe::setStateForTest('OLD', ruTrackerChecker::STE_UPDATED), 'setState must report that its target disappeared');
		strictAssertSame(2, count(rXMLRPCRequest::$requests), 'a failed state write needs exactly one follow-up probe');
		strictAssertSame('d.set_custom|d.set_custom', rXMLRPCRequest::$requests[0]['key'], 'the state write must be issued before any probe');
		strictAssertSame(false, rXMLRPCRequest::$requests[0]['important'], 'the racy state write must be non-important');
		strictAssertSame('d.hash', rXMLRPCRequest::$requests[1]['key'], 'the miss is confirmed by a hash probe after the failed write');

		// run() maps the vanished target to a successful no-op.
		rXMLRPCRequest::reset();
		rXMLRPCRequest::queue('d.hash', true, false, array('OLD'));
		rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, true, array());
		rXMLRPCRequest::queue('d.hash', true, true, array());
		strictAssertSame(
			true,
			ruTrackerChecker::run('OLD', ruTrackerChecker::STE_UPTODATE, time(), 0, ''),
			'a state-write race must not be reported as a check failure'
		);
		strictAssertSame(3, count(rXMLRPCRequest::$requests), 'the raced run must stop right after the confirming probe');
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
	public function testMetaPendingStateCallsPumpInsteadOfInProgress()
	{
		$this->resetFakes();
		RuTrackerMetaFetch::$result = ruTrackerChecker::STE_META_PENDING;
		rXMLRPCRequest::queue('d.hash', true, false, array('OLD'));
		rXMLRPCRequest::queue('d.get_custom', true, false, array(''));
		rXMLRPCRequest::queue('d.set_custom|d.set_custom', true, false, array());

		$result = ruTrackerChecker::run('OLD', ruTrackerChecker::STE_META_PENDING, time(), 0, '');

		strictAssertSame(true, $result, 'a still-pending pump keeps the check successful');
		strictAssertSame(1, count(RuTrackerMetaFetch::$calls), 'run must hand the meta-pending state to pump exactly once');
		strictAssertSame('OLD', RuTrackerMetaFetch::$calls[0]['hash'], 'pump must be called with the torrent hash');
		strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.get_custom')), 'pump must reach the XMLRPC layer, not a no-op stub');
		$stateWrites = rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom');
		strictAssertSame(1, count($stateWrites), 'run must persist the non-null pump result');
		strictAssertSame(
			array('OLD', 'chk-state', (string) ruTrackerChecker::STE_META_PENDING),
			$stateWrites[0]['commands'][0]->params,
			'the persisted state must be the pump result, never the INPROGRESS transition'
		);
	}

	public function testMetaPendingCompletedReplacementSkipsStateWrite()
	{
		$this->resetFakes();
		RuTrackerMetaFetch::$result = null; // createTorrent success: state already set by its own load additions
		rXMLRPCRequest::queue('d.hash', true, false, array('OLD'));
		rXMLRPCRequest::queue('d.get_custom', true, false, array(''));

		$result = ruTrackerChecker::run('OLD', ruTrackerChecker::STE_META_PENDING, time(), 0, '');

		strictAssertSame(true, $result, 'a completed replacement is a successful check');
		strictAssertSame(
			0,
			count(rXMLRPCRequest::requestsFor('d.set_custom|d.set_custom')),
			'run must not write state on the now-erased old hash after a successful replacement'
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

	public function testSchedulerStateStillSkipsMissingHash()
	{
		$this->resetFakes();
		rXMLRPCRequest::queue('d.hash', true, true, array());

		strictAssertSame(
			true,
			ruTrackerChecker::run('MISSING', ruTrackerChecker::STE_UPTODATE, time(), 0, ''),
			'a stale scheduler row must be a successful no-op'
		);
		strictAssertSame(1, count(rXMLRPCRequest::$requests), 'cached scheduler state must not bypass the missing-hash guard');
		strictAssertSame('d.hash', rXMLRPCRequest::$requests[0]['key'], 'the stale scheduler path should only probe the hash');
	}

	public function testDispatchPrefersCommentMatchesAndFallsBackToAnnounce()
	{
		$rows = array(
			'comment match' => array(
				'fixture' => array(
					'hash' => 'OLD',
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
					'hash' => 'OLD',
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
					'hash' => 'OLD',
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
					'hash' => 'OLD',
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
				ruTrackerChecker::run_ex('OLD', 'dispatch'),
				$label . ': the matching handler result must be returned'
			);
			strictAssertSame(
				array(array($row['expect'][0], $row['expect'][1])),
				$calls,
				$label . ': exactly the expected handler must run with the matched URL'
			);
		}
	}

	public function testCleanupDeletesOnlyRenamedOldFileAndKeepsBase()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-clean-' . bin2hex(random_bytes(5));
		mkdir($base . '/old', 0777, true);
		file_put_contents($base . '/old/video-old.mkv', 'old');
		file_put_contents($base . '/shared.nfo', 'shared');
		file_put_contents($base . '/unrelated.txt', 'keep');
		$old = new Torrent(array('hash' => 'OLD', 'info' => array('files' => array(
			array('path' => array('old', 'video-old.mkv'), 'length' => 3),
			array('path' => array('shared.nfo'), 'length' => 6),
		))));
		$new = new Torrent(array('hash' => 'NEW', 'info' => array('files' => array(
			array('path' => array('video-new.mkv'), 'length' => 3),
			array('path' => array('shared.nfo'), 'length' => 6),
		))));

		try
		{
			$this->invokeCleanup($old, $new, $base);
			strictAssertTrue(!file_exists($base . '/old/video-old.mkv'), 'the renamed old-only file should be deleted');
			strictAssertTrue(!is_dir($base . '/old'), 'an empty child directory should be removed');
			strictAssertTrue(is_file($base . '/shared.nfo'), 'a file present in both torrents must remain');
			strictAssertTrue(is_file($base . '/unrelated.txt'), 'unrelated files must remain');
			strictAssertTrue(is_dir($base), 'the torrent base directory must never be removed');
		}
		finally
		{
			strictRemoveTree($base);
		}
	}

	public function testCleanupDoesNotFollowDirectorySymlinkOutsideBase()
	{
		$this->resetFakes();
		$root = sys_get_temp_dir() . '/rut-check-link-' . bin2hex(random_bytes(5));
		$base = $root . '/base';
		$outside = $root . '/outside';
		mkdir($base, 0777, true);
		mkdir($outside, 0777, true);
		file_put_contents($outside . '/victim.mkv', 'keep');
		symlink($outside, $base . '/link');
		$old = new Torrent(array('hash' => 'OLD', 'info' => array('files' => array(
			array('path' => array('link', 'victim.mkv'), 'length' => 4),
		))));
		$new = new Torrent(array('hash' => 'NEW', 'info' => array('name' => 'replacement.mkv')));

		try
		{
			$this->invokeCleanup($old, $new, $base);
			strictAssertTrue(is_file($outside . '/victim.mkv'), 'cleanup must not follow a directory symlink outside the base');
		}
		finally
		{
			strictRemoveTree($root);
		}
	}

	public function testCleanupAbortsWhenNewManifestContainsUnsafePath()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-unsafe-' . bin2hex(random_bytes(5));
		mkdir($base . '/folder', 0777, true);
		file_put_contents($base . '/folder/file.mkv', 'keep');
		$old = new Torrent(array('hash' => 'OLD', 'info' => array('files' => array(
			array('path' => array('folder', 'file.mkv'), 'length' => 4),
		))));
		$new = new Torrent(array('hash' => 'NEW', 'info' => array('files' => array(
			array('path' => array('folder/file.mkv'), 'length' => 4),
		))));

		try
		{
			$this->invokeCleanup($old, $new, $base);
			strictAssertTrue(is_file($base . '/folder/file.mkv'), 'an unsafe new manifest must abort cleanup instead of hiding a live path');
		}
		finally
		{
			strictRemoveTree($base);
		}
	}

	public function testCleanupNeverRemovesBaseDirectory()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-base-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/old.mkv', 'old');
		$old = new Torrent(array('hash' => 'OLD', 'info' => array('name' => 'old.mkv', 'length' => 3)));
		$new = new Torrent(array('hash' => 'NEW', 'info' => array('name' => 'new.mkv', 'length' => 3)));

		try
		{
			$this->invokeCleanup($old, $new, $base);
			strictAssertTrue(is_dir($base), 'cleanup may remove an old file but never its base directory');
		}
		finally
		{
			strictRemoveTree($base);
		}
	}

	public function testCleanupRefusesFilesystemRootAsBase()
	{
		$this->resetFakes();
		$path = tempnam(sys_get_temp_dir(), 'rut-check-root-');
		file_put_contents($path, 'keep');
		$old = new Torrent(array('hash' => 'OLD', 'info' => array('files' => array(
			array('path' => explode('/', ltrim($path, '/')), 'length' => 4),
		))));
		$new = new Torrent(array('hash' => 'NEW', 'info' => array('name' => 'replacement.mkv')));

		try
		{
			$this->invokeCleanup($old, $new, DIRECTORY_SEPARATOR);
			strictAssertTrue(is_file($path), 'cleanup must refuse the filesystem root as its base directory');
		}
		finally
		{
			@unlink($path);
		}
	}

	public function testCleanupKeepsFileAliasedByNewTorrent()
	{
		$this->resetFakes();
		$base = sys_get_temp_dir() . '/rut-check-alias-' . bin2hex(random_bytes(5));
		mkdir($base, 0777, true);
		file_put_contents($base . '/a.mkv', 'payload');
		link($base . '/a.mkv', $base . '/b.mkv');
		$old = new Torrent(array('hash' => 'OLD', 'info' => array('name' => 'b.mkv')));
		$new = new Torrent(array('hash' => 'NEW', 'info' => array('name' => 'a.mkv')));

		try
		{
			$this->invokeCleanup($old, $new, $base);
			strictAssertTrue(is_file($base . '/b.mkv'), 'a path aliasing a file of the new torrent must be kept');
			strictAssertTrue(is_file($base . '/a.mkv'), 'the new torrent file itself must be kept');
		}
		finally
		{
			strictRemoveTree($base);
		}
	}
}

$suite = new StrictTestSuite();
$suite->addFromObject(new CheckerTest());
exit($suite->run());
