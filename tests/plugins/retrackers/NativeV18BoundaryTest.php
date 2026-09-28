<?php

require_once(__DIR__ . '/../../php/TestCase.php');

$retrackersV18TestProfile = sys_get_temp_dir() . '/rutorrent-v18-red-' . getmypid();
@rmdir($retrackersV18TestProfile);
mkdir($retrackersV18TestProfile, 0700);
$_ENV['RU_PROFILE_PATH'] = $retrackersV18TestProfile;
if (!class_exists('rTorrent', false)) {
	class rTorrent
	{
		public static function quoteCommandArg($value)
		{
			return('"' . addcslashes((string)$value, "\\\"") . '"');
		}
	}
}

define('RETRACKERS_TEARDOWN_TIMEOUT', 0.2);
define('RETRACKERS_IMPORT_ONLY', true);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');
require_once(__DIR__ . '/../../../php/util.php');
$tempDirectory = $retrackersV18TestProfile . '/';
$tempDirectory_init_done = true;

class RetrackersV18BoundaryAdapter extends RetrackersWorkerRpcAdapter
{
	public $ledgerKeys = array();
	public $lastFailure = null;
	public $genericReleaseCalls = 0;
	public $genericCleanupCalls = 0;
	public $oldCommitCalls = 0;
	public $nativeFinishCalls = 0;
	public $nativeCaptureCalls = 0;
	public $preflightCalls = 0;
	public $events = array();
	public $loseFinishReply = false;
	public $wrongFinalizedReceipt = false;
	public $nativeMethodsPresent = true;
	public $sameHashMethodsPresent = true;
	public $methodListCalls = 0;
	public $preFinalized = false;
	public $currentIncarnation = null;
	public $retireCalls = 0;
	public $clearWhAfterFirstRetire = false;

	protected function send($payload, $plan, &$failure = null, $historical = false)
	{
		if (strpos($payload, '<methodName>system.listMethods</methodName>') !== false) {
			$this->methodListCalls++;
			$failure = null;
			$methods = array('d.incarnation', 'system.retrackers.finalized');
			if ($this->nativeMethodsPresent) {
				$methods[] = 'd.retrackers.begin_original';
				$methods[] = 'd.retrackers.finish_original';
				if ($this->sameHashMethodsPresent) $methods = array_merge($methods, array(
					'd.retrackers.capture_erase', 'd.retrackers.apply',
					'd.retrackers.commit', 'd.retrackers.release_committed',
					'system.retrackers.receipt', 'system.retrackers.prepared',
					'load.normal'));
			}
			return(array('ok' => true, 'family' => 2, 'value' => $methods));
		}
		if (strpos($payload, '<methodName>branch</methodName>') !== false &&
			strpos($payload, 'system.retrackers.finalized=') !== false) {
			$this->retireCalls++;
			$hasWh = count(array_filter($this->ledgerKeys, function ($key) {
				return(strncmp($key, 'wh:', 3) === 0);
			})) > 0;
			if ($hasWh) {
				if ($this->clearWhAfterFirstRetire) {
					$this->ledgerKeys = array_values(array_filter($this->ledgerKeys,
						function ($key) { return(strncmp($key, 'wh:', 3) !== 0); }));
				}
				$failure = null;
				return(array('ok' => true, 'family' => 2, 'value' => 'CHANGED'));
			}
			$this->ledgerKeys = array_values(array_filter($this->ledgerKeys,
				function ($key) { return(strncmp($key, 'wp:', 3) !== 0); }));
			$failure = null;
			return(array('ok' => true, 'family' => 2, 'value' => 'ACQUIRED'));
		}
		if (strpos($payload, '<methodName>d.incarnation</methodName>') !== false) {
			$failure = null;
			return(array('ok' => true, 'family' => 2, 'value' =>
				$this->currentIncarnation === null ? str_repeat('e', 32) :
				$this->currentIncarnation));
		}
		if (strpos($payload, '<methodName>d.retrackers.finish_original</methodName>') !== false) {
			$this->nativeFinishCalls++;
			$this->events[] = 'native-finish';
			if ($this->loseFinishReply) {
				$failure = 'rpc-timeout';
				return(false);
			}
			$failure = null;
			return(array('ok' => true, 'family' => 2, 'value' => 'finalized'));
		}
		if (strpos($payload, '<methodName>system.retrackers.finalized</methodName>') !== false) {
			if (strpos($payload, '<param><value><string></string></value></param>') === false) {
				throw new RuntimeException('native finalized read requires empty target');
			}
			$failure = null;
			return(array('ok' => true, 'family' => 2, 'value' =>
				$this->preFinalized || $this->nativeFinishCalls > 0 ?
				'finalized:' . str_repeat($this->wrongFinalizedReceipt ? 'd' : 'c', 32) .
				':' . str_repeat('e', 32) : 'none'));
		}
		if (strpos($payload, '<methodName>d.retrackers.capture_erase</methodName>') !== false) {
			$this->nativeCaptureCalls++;
			$this->events[] = 'native-capture';
			throw new RuntimeException('test cut: native destructive capture reached');
		}
		$failure = 'unexpected-native-rpc';
		return(false);
	}

	public function sourceScalarSnapshot($hash, &$failure = null)
	{
		$failure = null;
		return(array('values' => array('local_id' => str_repeat('B', 40),
			'recovery_marker' => '', 'recovery_ack' => '')));
	}

	public function getLedgerKeys(&$failure = null)
	{
		$failure = null;
		return($this->ledgerKeys);
	}

	public function executeWorkerAdoptCallback(RetrackersWorkerAdoptCallback $callback,
		&$failure = null)
	{
		$wire = implode("\n", $callback->params());
		if (preg_match('/wa:([0-9a-f]{32})/', $wire, $matched) !== 1) {
			throw new RuntimeException('test adapter could not read worker T');
		}
		$this->ledgerKeys[] = 'wa:' . $matched[1];
		$failure = null;
		return('ADOPTED');
	}

	public function executeHandoffRelease(RetrackersHandoffReleaseCallback $callback,
		&$failure = null)
	{
		$this->genericReleaseCalls++;
		$this->events[] = 'generic-release';
		$failure = null;
		return('RETRACKERS_HANDOFF_RELEASED');
	}

	public function executeTerminalCleanup(RetrackersTerminalCleanupCallback $callback,
		&$failure = null)
	{
		$this->genericCleanupCalls++;
		$this->events[] = 'generic-cleanup';
		$failure = null;
		return('RETRACKERS_CLEANED');
	}

	public function executeOldGenerationCommit(RetrackersOldGenerationCommitCallback $callback,
		&$failure = null)
	{
		$this->oldCommitCalls++;
		$this->events[] = 'old-commit';
		throw new RuntimeException('test cut: old destructive callback reached');
	}

	public function preflightStage(RetrackersAnonymousStage $stage, $php, &$failure = null)
	{
		$this->preflightCalls++;
		$failure = null;
		return(true);
	}

	public function maxContentSize(&$failure = null)
	{
		$failure = null;
		return(16777216);
	}

	public function recordRecoveryFailure($failure)
	{
		$this->lastFailure = $failure;
		// The production worker holds a lease forever here. End only this
		// synthetic case after the classified reason becomes observable.
		throw new RuntimeException('test cut: ' . $failure);
	}
}

class RetrackersNativeV18BoundaryTest extends TestCase
{
	private function runWithPreparedCandidate($candidate, $loseFinishReply = false,
		$wrongFinalizedReceipt = false, $ledgerKeys = array(),
		$clearWhAfterFirstRetire = false, $sameHashMethodsPresent = true)
	{
		$hash = str_repeat('A', 40);
		$localId = str_repeat('B', 40);
		$tx = str_repeat('c', 32);
		$handoff = 'v2:original:0:' . $localId . ':' .
			hash('sha256', 'alice') . ':' . $tx;
		$snapshot = array('scalar' => array(
			'is_private' => '0', 'name' => 'test', 'state' => '0',
			'local_id' => $localId, 'recovery_marker' => $handoff,
			'recovery_ack' => $handoff, 'directory_base' => '/synthetic',
			'custom1' => '', 'custom2' => '', 'custom3' => '',
			'custom4' => '', 'custom5' => '', 'priority' => '1',
			'throttle_name' => '',
		), 'generic_map' => array(
			array('name' => 'retrackers-recovery', 'value' => $handoff),
			array('name' => 'retrackers-recovery-ack', 'value' => $handoff),
		), 'source' => array('path' => '/synthetic/source.torrent'));
		$snapshotService = new class($snapshot) {
			private $snapshot;
			public function __construct($snapshot) { $this->snapshot = $snapshot; }
			public function capture($hash, $handoff) {
				return(array('ok' => true, 'snapshot' => $this->snapshot));
			}
		};
		$authority = new class($candidate) {
			private $candidate;
			public function __construct($candidate) { $this->candidate = $candidate; }
			public function prepare($path, $hash, $additions, $deletions, $addToBegin) {
				return($this->candidate);
			}
		};
		$adapter = new RetrackersV18BoundaryAdapter();
		$adapter->ledgerKeys = $ledgerKeys;
		$adapter->loseFinishReply = $loseFinishReply;
		$adapter->wrongFinalizedReceipt = $wrongFinalizedReceipt;
		$adapter->clearWhAfterFirstRetire = $clearWhAfterFirstRetire;
		$adapter->sameHashMethodsPresent = $sameHashMethodsPresent;
		$outcome = null;
		try {
			$outcome = RetrackersRecoveryCoordinator::run($hash, 'alice', $handoff,
				'0', $localId, $adapter, $authority, $snapshotService, null, '/usr/bin/php');
		} catch (RuntimeException $error) {
			if (strpos($error->getMessage(), 'test cut: ') !== 0) {
				throw $error;
			}
		}
		return(array($adapter, $outcome, $tx));
	}

	public function testNativeBeginPrecedesBothOrdinaryAndDeferredLaunches()
	{
		$ordinary = retrackersBuildInsertAction('/plugin/run.sh', '/usr/bin/php', 'alice', true);
		$start = strpos($ordinary, 'cat=wh:');
		$ordinary = $start === false ? $ordinary : substr($ordinary, $start);
		$localId = str_repeat('B', 40);
		$handoff = 'v1:original:0:' . $localId . ':' . hash('sha256', 'alice');
		$deferred = RetrackersLifecycleCallbacks::replayDeferred(
			str_repeat('0', 32), str_repeat('1', 32), 'i', hash('sha256', 'alice'),
			str_repeat('A', 40), $localId, '0', $handoff,
			'/plugin/run.sh', '/usr/bin/php', 'alice', true)->params()[2];
		foreach (array('ordinary' => $ordinary, 'deferred' => $deferred) as $kind => $body) {
			$begin = strpos($body, 'd.retrackers.begin_original');
			$wp = strpos($body, 'wp:');
			$launch = strpos($body, 'execute.throw.bg');
			$this->assertTrue($begin !== false && $wp !== false && $launch !== false &&
				$begin < $wp && $wp < $launch,
				$kind . ' must finish native begin before wp and worker launch');
			$this->assertTrue(strpos($body, 'v1:original') === false &&
				strpos($body, 'd.custom.set=retrackers-recovery,') === false,
				$kind . ' must let native begin publish the v2 marker');
		}
		$legacyOrdinary = retrackersBuildInsertAction('/plugin/run.sh', '/usr/bin/php',
			'alice', false);
		$legacyDeferred = RetrackersLifecycleCallbacks::replayDeferred(
			str_repeat('0', 32), str_repeat('1', 32), 'i', hash('sha256', 'alice'),
			str_repeat('A', 40), $localId, '0', $handoff,
			'/plugin/run.sh', '/usr/bin/php', 'alice', false)->params()[2];
		$this->assertTrue(strpos($legacyOrdinary, 'v1:original') !== false &&
			strpos($legacyOrdinary, 'd.retrackers.begin_original') === false &&
			strpos($legacyDeferred, 'd.retrackers.begin_original') === false,
			'0.9.8 family keeps the v1 ordinary and deferred handoff grammar');
	}

	public function testNoChangeAndProvenDifferentHashKeepNativeAuthority()
	{
		list($unchanged, $unchangedResult, $tx) = $this->runWithPreparedCandidate(
			array('ok' => true, 'changed' => false, 'candidate' => null,
				'original' => 'original'));
		$finishAt = array_search('native-finish', $unchanged->events, true);
		$cleanupAt = array_search('generic-cleanup', $unchanged->events, true);
		$this->assertTrue($unchangedResult === true &&
			$unchanged->nativeFinishCalls === 1 &&
			$unchanged->genericReleaseCalls === 0 &&
			($cleanupAt === false || ($finishAt !== false && $finishAt < $cleanupAt)) &&
			$unchanged->lastFailure === null,
			'no-change must prove native original-owner finish before marker or wa cleanup');

		$hash = str_repeat('A', 40);
		$other = str_repeat('D', 40);
		list($different, $differentResult) = $this->runWithPreparedCandidate(
			array('ok' => true, 'changed' => true,
				'candidate' => 'candidate', 'original' => 'original',
				'candidate_scan' => array('info_hash' => $other),
				'projection' => array('ok' => true, 'changed' => true)));
		list($lost, $lostResult) = $this->runWithPreparedCandidate(
			array('ok' => true, 'changed' => false, 'candidate' => null,
				'original' => 'original'), true);
		$this->assertTrue($lostResult === true && $lost->nativeFinishCalls === 1 &&
			$lost->genericReleaseCalls === 0 && $lost->genericCleanupCalls === 0 &&
			$lost->lastFailure === null,
			'lost finish reply must reconcile exact finalized T/I without resend');
		list($foreign, $foreignResult) = $this->runWithPreparedCandidate(
			array('ok' => true, 'changed' => false, 'candidate' => null,
				'original' => 'original'), false, true);
		$this->assertTrue($foreignResult !== true && $foreign->nativeFinishCalls === 1 &&
			$foreign->lastFailure === 'native-original-finalize-pending' &&
			$foreign->genericReleaseCalls === 0 && $foreign->genericCleanupCalls === 0,
			'foreign finalized T/I keeps the v2 marker and lease held');
		$this->assertTrue($differentResult !== true &&
			$different->lastFailure === 'different-hash-unsupported' &&
			$different->nativeCaptureCalls === 0 &&
			$different->oldCommitCalls === 0 &&
			$different->genericReleaseCalls === 0 &&
			$different->genericCleanupCalls === 0,
			'proven H != K must hold visibly before capture, erase, load or lease cleanup');
	}
	public function testSameHashChangedWorkerReachesNativeCaptureBeforeLegacyCommit()
	{
		$hash = str_repeat('A', 40);
		list($adapter, $outcome) = $this->runWithPreparedCandidate(array(
			'ok' => true, 'changed' => true, 'candidate' => 'candidate',
			'original' => 'original',
			'candidate_scan' => array('info_hash' => $hash),
			'projection' => array('ok' => true, 'changed' => true),
		));
		$this->assertTrue($outcome === null && $adapter->nativeCaptureCalls === 1 &&
			$adapter->oldCommitCalls === 0 && $adapter->genericReleaseCalls === 0 &&
			$adapter->genericCleanupCalls === 0 && $adapter->lastFailure === null,
			'same-hash worker must reach native capture before legacy erase or cleanup');
	}

	public function testSameHashWorkerRequiresExactNativeMethodsBeforeCapture()
	{
		$hash = str_repeat('A', 40);
		list($adapter, $outcome) = $this->runWithPreparedCandidate(array(
			'ok' => true, 'changed' => true, 'candidate' => 'candidate',
			'original' => 'original',
			'candidate_scan' => array('info_hash' => $hash),
			'projection' => array('ok' => true, 'changed' => true),
		), false, false, array(), false, false);
		$this->assertTrue($outcome !== true &&
			$adapter->lastFailure === 'native-samehash-capability-unconfirmed' &&
			$adapter->nativeCaptureCalls === 0 && $adapter->oldCommitCalls === 0,
			'a daemon with begin/finish but no capture ABI cannot enter destructive replacement');
	}

	public function testColdFinalizedOriginalRequiresExactIncarnationBeforeSnapshot()
	{
		$hash = str_repeat('A', 40);
		$localId = str_repeat('B', 40);
		$handoff = 'v2:original:0:' . $localId . ':' .
			hash('sha256', 'alice') . ':' . str_repeat('c', 32);
		foreach (array(false, true) as $foreign) {
			$adapter = new RetrackersV18BoundaryAdapter();
			$adapter->preFinalized = true;
			if ($foreign) $adapter->currentIncarnation = str_repeat('f', 32);
			$snapshot = new class {
				public $reads = 0;
				public function capture($hash, $handoff) {
					$this->reads++;
					return(array('ok' => false, 'failure' => 'ownership-mismatch'));
				}
			};
			try {
				$result = RetrackersRecoveryCoordinator::run($hash, 'alice', $handoff,
					'0', $localId, $adapter, null, $snapshot);
			} catch (RuntimeException $error) {
				if (strpos($error->getMessage(), 'test cut: ') !== 0) throw $error;
				$result = false;
			}
			$this->assertTrue($result === false &&
				$adapter->lastFailure === ($foreign ? 'ownership-mismatch' :
					'native-original-finalized-before-worker') &&
				$snapshot->reads === ($foreign ? 1 : 0),
				$foreign ? 'foreign same-hash I cannot claim old terminal T' :
					'cold terminal T/I is classified before stale L snapshot');
		}
	}

	public function testNativeWpRetirementWaitsForHookWhWithoutRefinishingOriginal()
	{
		$localId = str_repeat('B', 40);
		$wp = 'wp:' . $localId;
		$wh = 'wh:' . $localId;
		$candidate = array('ok' => true, 'changed' => false,
			'candidate' => null, 'original' => 'original');
		list($cleared, $result) = $this->runWithPreparedCandidate(
			$candidate, false, false, array($wp, $wh), true);
		$this->assertTrue($result === true && $cleared->nativeFinishCalls === 1 &&
			$cleared->retireCalls === 2 &&
			!in_array($wp, $cleared->ledgerKeys, true) &&
			!in_array($wh, $cleared->ledgerKeys, true),
			'only terminal wp CAS retries after the hook clears wh');
		list($held, $heldResult) = $this->runWithPreparedCandidate(
			$candidate, false, false, array($wp, $wh));
		$this->assertTrue($heldResult !== true &&
			$held->lastFailure === 'native-pending-retire-unconfirmed' &&
			$held->nativeFinishCalls === 1 && $held->retireCalls === 1 &&
			in_array($wp, $held->ledgerKeys, true) &&
			in_array($wh, $held->ledgerKeys, true),
			'a hook that never clears wh keeps wp and reports the bounded refusal');
	}

	public function testNativeFinishRetiresWpBeforeDoneCanInspectLedger()
	{
		$pending = 'wp:' . str_repeat('B', 40);
		list($adapter, $result) = $this->runWithPreparedCandidate(array(
			'ok' => true, 'changed' => false, 'candidate' => null,
			'original' => 'original',
		), false, false, array($pending));
		$this->assertTrue($result === true &&
			!in_array($pending, $adapter->ledgerKeys, true) &&
			$adapter->genericReleaseCalls === 0 && $adapter->genericCleanupCalls === 0,
			'native terminal receipt must retire wp before done or teardown sees it');
	}

	public function testFamilyTwoNeedsExactNativeMethodsWhileLegacyKeepsV1()
	{
		$unpatched = new RetrackersV18BoundaryAdapter();
		$unpatched->nativeMethodsPresent = false;
		$failure = null;
		$this->assertTrue($unpatched->nativeOriginalCapability($failure) === false &&
			$failure === 'native-original-capability-unconfirmed' &&
			$unpatched->methodListCalls === 1,
			'family two without native begin/finish is refused before hook installation');
		$patched = new RetrackersV18BoundaryAdapter();
		$failure = null;
		$this->assertTrue($patched->nativeOriginalCapability($failure) === true &&
			$failure === null && $patched->methodListCalls === 1,
			'exact method list permits the current-master native hook');
		$legacy = new RetrackersV18BoundaryAdapter();
		$legacy->setFamily(1);
		$failure = null;
		$this->assertTrue($legacy->nativeOriginalCapability($failure) === false &&
			$failure === null && $legacy->methodListCalls === 0,
			'0.9.8 family one does not need a v2 method-list probe');
	}

}
