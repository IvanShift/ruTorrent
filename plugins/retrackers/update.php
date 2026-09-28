<?php

// Lifecycle draining and terminal wp retirement share this timeout. Expiry
// keeps the lease visible; the shipped default is five seconds. Recovery
// receipt polling uses the separate interval below. Both constants remain
// overridable before import for focused tests.
if (!defined('RETRACKERS_TEARDOWN_TIMEOUT'))
	define('RETRACKERS_TEARDOWN_TIMEOUT', 5.0);
if (!defined('RETRACKERS_RECEIPT_POLL'))
	define('RETRACKERS_RECEIPT_POLL', 0.25);

function clearTracker($addition,$tracker)
{
	foreach( $addition as $kg=>$group )
	{
		foreach( $group as $kt=>$trk )
		{
			if($trk==$tracker)
				unset($addition[$kg][$kt]);
		}
		if(!count($addition[$kg]))
			unset($addition[$kg]);
	}
	return($addition);
}

function deleteTrackers(&$lst,$todelete)
{
	$ret = false;
	foreach( $lst as $kg=>$group )
	{
		foreach( $group as $kt=>$trk )
		{
			foreach ( $todelete as $kd )
			{
				if(stristr($trk,$kd))
				{
					unset($lst[$kg][$kt]);
					if(!count($lst[$kg]))
						unset($lst[$kg]);
					$ret = true;
					break;
				}
			}
		}
	}
	return($ret);
}

function retrackersBuildRecoveryInsertGrammar()
{
	$q = function ($value) {
		return(rTorrent::quoteCommandArg($value));
	};
	$noop = 'cat=';
	$copyLiveMarker = 'd.custom.set=retrackers-recovery-ack,$d.custom=retrackers-recovery';
	$capabilityAck = 'branch=' . $q('method.has_key=rr.receipts.v1,$d.custom=retrackers-recovery') . ',' .
		$q($copyLiveMarker) . ',' . $q($noop);
	$emptyAckOrNoop = 'branch=' . $q('equal=d.custom=retrackers-recovery-ack,cat=') . ',' .
		$q($capabilityAck) . ',' . $q($noop);
	$idempotentOrCapabilityAck = 'branch=' .
		$q('equal=d.custom=retrackers-recovery-ack,d.custom=retrackers-recovery') . ',' .
		$q($noop) . ',' . $q($emptyAckOrNoop);
	$setDirty = '$method.set_key=rr.receipts.v1,dq:1,1';
	$deferredKey = '$cat=di:,$d.local_id=';
	$setDeferred = '$method.set_key=rr.receipts.v1,' . $q($deferredKey) . ',1';
	return(array(
		'idempotent' => $idempotentOrCapabilityAck,
		'defer' => 'cat=' . $q($setDirty) . ',' . $q($setDeferred),
	));
}

function retrackersBuildSafetyOnlyInsertAction()
{
	$q = function ($value) {
		return(rTorrent::quoteCommandArg($value));
	};
	$grammar = retrackersBuildRecoveryInsertGrammar();
	$legacyOrNoop = 'branch=' . $q('$equal=d.custom3=,cat=1') . ',' .
		$q('d.custom3.set=') . ',' . $q('cat=');
	return('branch=' . $q('d.custom=retrackers-recovery') . ',' .
		$q($grammar['idempotent']) . ',' . $q($legacyOrNoop));
}

function retrackersBuildDeferOnlyInsertAction()
{
	$q = function ($value) {
		return(rTorrent::quoteCommandArg($value));
	};
	$grammar = retrackersBuildRecoveryInsertGrammar();
	$legacyOrDefer = 'branch=' . $q('$equal=d.custom3=,cat=1') . ',' .
		$q('d.custom3.set=') . ',' . $q($grammar['defer']);
	return('branch=' . $q('d.custom=retrackers-recovery') . ',' .
		$q($grammar['idempotent']) . ',' . $q($legacyOrDefer));
}

/**
 * The functional insert action, installed under tadd_trackers1<user>.
 *
 * The native path records hook activity, asks the daemon to durably claim
 * and publish the original owner, then records wp and launches the worker.
 * The 0.9.8 path retains its v1 marker/ack sequence. In both paths the
 * hook-active receipt is deleted last; a failed begin or launch keeps it
 * visible and blocks hook erase until the daemon restarts.
 *
 * execute.throw.bg takes an argv list, not a shell string, so no shell escaping
 * happens here: every configured value goes through one rTorrent quoting layer
 * and reaches the script as its own argument. A path containing a space, comma,
 * quote or backslash therefore cannot change the argv shape.
 *
 * The launch return is deliberately not treated as an acknowledgement: on 0.9.8
 * the double fork confirms only the first fork and cannot report a late
 * grandchild execvp failure. Safety comes from the action containing no d.stop,
 * d.close or d.erase in any branch.
 */
function retrackersNativeBeginExpression($userHash)
{
	$q = function ($value) {
		return(rTorrent::quoteCommandArg($value));
	};
	return('$d.retrackers.begin_original={$d.local_id=,' . $q($userHash) . ',$cat=$d.state=}');
}

function retrackersBuildInsertAction($script, $php, $user, $nativeOriginal = false)
{
	$q = function ($value) {
		return(rTorrent::quoteCommandArg($value));
	};
	$grammar = retrackersBuildRecoveryInsertGrammar();
	// The handoff binds to the canonical argv user without carrying the username
	// itself into a daemon-visible marker.
	$userToken = hash('sha256', (string) $user);
	$handoff = '$cat=v1:original:,$d.state=,:,$d.local_id=,:,' . $q($userToken);
	// The dynamic keys go through an inner quote: without it the comma after
	// "$cat=wh:" splits method.set_key's arguments and both daemon families
	// fault instead of creating the full local-id key.
	$activeHookKey = '$cat=wh:,$d.local_id=';
	$pendingKey = '$cat=wp:,$d.local_id=';
	$setActiveHook = '$method.set_key=rr.receipts.v1,' . $q($activeHookKey) . ',1';
	$clearActiveHook = '$method.set_key=rr.receipts.v1,' . $q($activeHookKey);
	$setPending = '$method.set_key=rr.receipts.v1,' . $q($pendingKey) . ',1';
	$setAck = '$d.custom.set=retrackers-recovery-ack,' . $q($handoff);
	$setMarker = '$d.custom.set=retrackers-recovery,' . $q($handoff);
	$launch = '$execute.throw.bg={sh,' . $q($script) . ',' . $q($php) . ',$d.hash=,' .
		$q($user) . ',$d.custom=retrackers-recovery}';
	$handoffCommands = $nativeOriginal ? array(retrackersNativeBeginExpression($userToken),
		$setPending) : array($setPending, $setAck, $setMarker);
	$ordinary = 'cat=' . implode(',', array_map($q, array_merge(
		array($setActiveHook), $handoffCommands, array($launch, $clearActiveHook))));
	$deferOrOrdinary = 'branch=' . $q('method.has_key=rr.receipts.v1,ta:1') . ',' .
		$q($grammar['defer']) . ',' . $q($ordinary);
	$ownerOrNoop = 'branch=' . $q('method.has_key=rr.receipts.v1,ma:1') . ',' .
		$q('cat=') . ',' . $q($deferOrOrdinary);
	// The checker sets chk-meta-old before inserted_new runs. Guard both the
	// ordinary worker and ta:1 deferral; a user may own the same UI label.
	$serviceGuard = 'branch=' . $q('d.custom=chk-meta-old') . ',' .
		$q('cat=') . ',' . $q($ownerOrNoop);
	$legacyOrOrdinary = 'branch=' . $q('$equal=d.custom3=,cat=1') . ',' .
		$q('d.custom3.set=') . ',' . $q($serviceGuard);
	return('branch=' . $q('d.custom=retrackers-recovery') . ',' .
		$q($nativeOriginal ? 'cat=' : $grammar['idempotent']) . ',' . $q($legacyOrOrdinary));
}

abstract class RetrackersLifecycleCallback
{
	private $name;
	private $method;
	private $params;
	private $expected;

	protected function __construct($name, $method, array $params, array $expected)
	{
		$this->name = $name;
		$this->method = $method;
		$this->params = $params;
		$this->expected = $expected;
	}

	public function name()
	{
		return($this->name);
	}

	public function method()
	{
		return($this->method);
	}

	public function params()
	{
		return($this->params);
	}

	public function successSentinel()
	{
		return('ACQUIRED');
	}

	public function changedSentinel()
	{
		return('CHANGED');
	}

	public function allowedSentinels()
	{
		return(array($this->successSentinel(), $this->changedSentinel()));
	}

	public function responsePlan($family)
	{
		return(retrackersRestrictedPlanDirectScalar('string'));
	}

	public function expectedState()
	{
		return($this->expected);
	}
}

/**
 * Closed builders for lifecycle-owned daemon callbacks.
 *
 * Every returned object represents one direct branch invocation. The branch
 * performs its ownership check and all writes under one daemon lock, then
 * returns ACQUIRED only after the ordered true body reaches its tail.
 */
final class RetrackersLifecycleCallbacks extends RetrackersLifecycleCallback
{
	private static function q($value)
	{
		return(rTorrent::quoteCommandArg($value));
	}

	private static function keyPresent($key)
	{
		return('method.has_key=rr.receipts.v1,' . $key);
	}

	private static function keyAbsent($key)
	{
		// not does not execute a quoted command string; it needs a command object.
		return('not=(method.has_key,rr.receipts.v1,' . $key . ')');
	}

	private static function actionPresent($key)
	{
		return('method.has_key=event.download.inserted_new,' . $key);
	}

	private static function actionAbsent($key)
	{
		return('not=(method.has_key,event.download.inserted_new,' . $key . ')');
	}

	private static function downloadEquals($getter, $value)
	{
		return('equal=' . self::q($getter) . ',' . self::q('cat=' . self::q($value)));
	}

	private static function downloadIntegerEquals($getter, $value)
	{
		return('equal=' . self::q($getter) . ',' . self::q('value=' . $value));
	}

	private static function all(array $conditions)
	{
		if (count($conditions) === 0) {
			return('cat=1');
		}
		$condition = array_shift($conditions);
		foreach ($conditions as $next) {
			$condition = 'and=' . self::q($condition) . ',' . self::q($next);
		}
		return($condition);
	}

	private static function body(array $commands)
	{
		$quoted = array();
		foreach ($commands as $command) {
			$quoted[] = self::q($command);
		}
		return('cat=' . implode(',', $quoted) . ',ACQUIRED');
	}

	private static function callback($name, $target, array $conditions, array $commands,
		array $expected)
	{
		return(new self($name, 'branch', array(
			$target,
			self::all($conditions),
			self::body($commands),
			'cat=CHANGED',
		), $expected));
	}

	private static function ownerConditions($epoch, $token, $mode, $userHash)
	{
		$owner = 'to:' . $token . ':' . $mode . ':' . $userHash;
		$conditions = array(
			self::keyPresent('ta:1'),
			self::keyPresent('pv:' . $epoch),
			self::keyPresent($owner),
		);
		$conditions[] = $mode === 'c' ? self::keyPresent('ma:1') : self::keyAbsent('ma:1');
		return($conditions);
	}

	private static function state($phase, $epoch, $token = null, $mode = null,
		$userHash = null, array $requiredReceipts = array(), array $absentReceipts = array())
	{
		return(array(
			'phase' => $phase,
			'epoch' => $epoch,
			'owner' => $token === null ? null : array(
				'token' => $token, 'mode' => $mode, 'user_hash' => $userHash),
			'required_receipts' => $requiredReceipts,
			'absent_receipts' => $absentReceipts,
		));
	}

	public static function bootstrapAcquire($token, $epoch, $userHash, $claimHash,
		$key1, $key2, $deferAction)
	{
		$owner = 'to:' . $token . ':i:' . $userHash;
		$claim = 'pf:' . $userHash . ':' . $claimHash;
		return(self::callback('bootstrap-acquire', '', array(
			'not=(method.list_keys,rr.receipts.v1)',
			self::keyAbsent('ma:1'), self::keyAbsent('ta:1'),
			self::keyAbsent('pv:' . $epoch), self::keyAbsent($owner), self::keyAbsent($claim),
			self::actionAbsent($key1), self::actionAbsent($key2),
		), array(
			'$method.set_key=rr.receipts.v1,ta:1,1',
			'$method.set_key=rr.receipts.v1,pv:' . $epoch . ',1',
			'$method.set_key=rr.receipts.v1,' . $owner . ',1',
			'$method.set_key=rr.receipts.v1,' . $claim . ',1',
			'$method.set_key=event.download.inserted_new,' . $key1 . ',' . self::q($deferAction),
			'$method.set_key=event.download.inserted_new,' . $key2 . ',' . self::q($deferAction),
		), self::state('INIT_OWNER', $epoch, $token, 'i', $userHash)));
	}

	public static function currentInitAcquire($oldEpoch, $newEpoch, $token, $userHash,
		$oldClaimHash, $newClaimHash, $key1, $key2, $deferAction)
	{
		$owner = 'to:' . $token . ':i:' . $userHash;
		$newClaim = 'pf:' . $userHash . ':' . $newClaimHash;
		$conditions = array(
			self::keyPresent('pv:' . $oldEpoch), self::keyAbsent('ma:1'), self::keyAbsent('ta:1'),
			self::keyAbsent('pv:' . $newEpoch), self::keyAbsent($owner),
		);
		if ($oldClaimHash === null) {
			$conditions[] = self::keyAbsent($newClaim);
			$conditions[] = self::actionAbsent($key1);
			$conditions[] = self::actionAbsent($key2);
		} else {
			$conditions[] = self::keyPresent('pf:' . $userHash . ':' . $oldClaimHash);
			if (!hash_equals($oldClaimHash, $newClaimHash)) {
				$conditions[] = self::keyAbsent($newClaim);
			}
		}
		$commands = array(
			'$method.set_key=rr.receipts.v1,ta:1,1',
			'$method.set_key=rr.receipts.v1,pv:' . $oldEpoch,
			'$method.set_key=rr.receipts.v1,pv:' . $newEpoch . ',1',
			'$method.set_key=rr.receipts.v1,' . $owner . ',1',
			'$method.set_key=rr.receipts.v1,' . $newClaim . ',1',
		);
		if ($oldClaimHash !== null && !hash_equals($oldClaimHash, $newClaimHash)) {
			$commands[] = '$method.set_key=rr.receipts.v1,pf:' . $userHash . ':' . $oldClaimHash;
		}
		$commands[] = '$method.set_key=event.download.inserted_new,' . $key1 . ',' . self::q($deferAction);
		$commands[] = '$method.set_key=event.download.inserted_new,' . $key2 . ',' . self::q($deferAction);
		return(self::callback('current-init-acquire', '', $conditions, $commands,
			self::state('INIT_OWNER', $newEpoch, $token, 'i', $userHash)));
	}

	public static function initFinalization($oldEpoch, $newEpoch, $token, $userHash,
		$key1, $key2, $functionalAction, $safetyAction)
	{
		$conditions = self::ownerConditions($oldEpoch, $token, 'i', $userHash);
		$conditions[] = self::keyAbsent('dq:1');
		return(self::callback('init-finalization', '', $conditions, array(
			'$method.set_key=rr.receipts.v1,pv:' . $oldEpoch,
			'$method.set_key=rr.receipts.v1,pv:' . $newEpoch . ',1',
			'$method.set_key=event.download.inserted_new,' . $key1 . ',' . self::q($functionalAction),
			'$method.set_key=event.download.inserted_new,' . $key2 . ',' . self::q($safetyAction),
			'$method.set_key=rr.receipts.v1,to:' . $token . ':i:' . $userHash,
			'$method.set_key=rr.receipts.v1,ta:1',
		), self::state('IDLE_CURRENT', $newEpoch)));
	}

	public static function contain($oldEpoch, $newEpoch, $token, $userHash,
		array $profiles, $safetyAction)
	{
		$conditions = self::ownerConditions($oldEpoch, $token, 'i', $userHash);
		$conditions[] = self::keyAbsent('ma:1');
		$commands = array(
			'$method.set_key=rr.receipts.v1,pv:' . $oldEpoch,
			'$method.set_key=rr.receipts.v1,pv:' . $newEpoch . ',1',
			'$method.set_key=rr.receipts.v1,ma:1,1',
			'$method.set_key=rr.receipts.v1,to:' . $token . ':i:' . $userHash,
			'$method.set_key=rr.receipts.v1,to:' . $token . ':c:' . $userHash . ',1',
		);
		foreach ($profiles as $profile) {
			$commands[] = '$method.set_key=event.download.inserted_new,tadd_trackers1' .
				$profile['user'] . ',' . self::q($safetyAction);
			$commands[] = '$method.set_key=event.download.inserted_new,tadd_trackers2' .
				$profile['user'] . ',' . self::q($safetyAction);
		}
		foreach ($profiles as $profile) {
			$commands[] = '$method.set_key=rr.receipts.v1,pf:' . $profile['user_hash'] . ':' .
				$profile['claim_hash'];
		}
		return(self::callback('containment', '', $conditions, $commands,
			self::state('CONTAIN_OWNER', $newEpoch, $token, 'c', $userHash)));
	}

	public static function containmentFinalization($oldEpoch, $newEpoch, $token, $userHash)
	{
		$conditions = self::ownerConditions($oldEpoch, $token, 'c', $userHash);
		$conditions[] = self::keyAbsent('dq:1');
		return(self::callback('containment-finalization', '', $conditions, array(
			'$method.set_key=rr.receipts.v1,pv:' . $oldEpoch,
			'$method.set_key=rr.receipts.v1,pv:' . $newEpoch . ',1',
			'$method.set_key=rr.receipts.v1,to:' . $token . ':c:' . $userHash,
			'$method.set_key=rr.receipts.v1,ta:1',
		), self::state('CONTAINED', $newEpoch)));
	}

	public static function doneAcquire($oldEpoch, $newEpoch, $token, $userHash,
		$claimHash, $key1, $key2, $functionalAction, $safetyAction)
	{
		$owner = 'to:' . $token . ':d:' . $userHash;
		$claim = 'pf:' . $userHash . ':' . $claimHash;
		$conditions = array(
			self::keyPresent('pv:' . $oldEpoch), self::keyAbsent('ma:1'), self::keyAbsent('ta:1'),
			self::keyPresent($claim), self::actionPresent($key2), self::keyAbsent($owner),
			self::keyAbsent('pv:' . $newEpoch),
		);
		$conditions[] = self::actionPresent($key1);
		return(self::callback('done-acquire', '', $conditions, array(
			'$method.set_key=rr.receipts.v1,ta:1,1',
			'$method.set_key=rr.receipts.v1,pv:' . $oldEpoch,
			'$method.set_key=rr.receipts.v1,pv:' . $newEpoch . ',1',
			'$method.set_key=rr.receipts.v1,' . $owner . ',1',
			'$method.set_key=event.download.inserted_new,' . $key1 . ',' . self::q($safetyAction),
			'$method.set_key=event.download.inserted_new,' . $key2 . ',' . self::q($safetyAction),
		), self::state('DONE_OWNER', $newEpoch, $token, 'd', $userHash)));
	}

	public static function doneFinalization($oldEpoch, $newEpoch, $token, $userHash,
		$claimHash, $key1, $key2)
	{
		$conditions = self::ownerConditions($oldEpoch, $token, 'd', $userHash);
		$conditions[] = self::keyPresent('pf:' . $userHash . ':' . $claimHash);
		$conditions[] = self::keyAbsent('dq:1');
		return(self::callback('done-finalization', '', $conditions, array(
			'$method.set_key=rr.receipts.v1,pv:' . $oldEpoch,
			'$method.set_key=rr.receipts.v1,pv:' . $newEpoch . ',1',
			'$method.set_key=event.download.inserted_new,' . $key1,
			'$method.set_key=event.download.inserted_new,' . $key2,
			'$method.set_key=rr.receipts.v1,pf:' . $userHash . ':' . $claimHash,
			'$method.set_key=rr.receipts.v1,to:' . $token . ':d:' . $userHash,
			'$method.set_key=rr.receipts.v1,ta:1',
		), self::state('IDLE_CURRENT', $newEpoch)));
	}

	public static function clearDeferredDirty($epoch, $token, $mode, $userHash)
	{
		$conditions = self::ownerConditions($epoch, $token, $mode, $userHash);
		$conditions[] = self::keyPresent('dq:1');
		return(self::callback('deferred-dirty-clear', '', $conditions, array(
			'$method.set_key=rr.receipts.v1,dq:1',
		), self::state(null, $epoch, $token, $mode, $userHash, array(), array('dq:1'))));
	}

	public static function deleteStaleDeferred($epoch, $token, $mode, $userHash, $localId)
	{
		$conditions = self::ownerConditions($epoch, $token, $mode, $userHash);
		$conditions[] = self::keyPresent('di:' . $localId);
		return(self::callback('deferred-stale-delete', '', $conditions, array(
			'$method.set_key=rr.receipts.v1,di:' . $localId,
		), self::state(null, $epoch, $token, $mode, $userHash, array(), array('di:' . $localId))));
	}

	public static function skipDeferredService($epoch, $token, $mode, $userHash,
		$hash, $localId)
	{
		$conditions = self::ownerConditions($epoch, $token, $mode, $userHash);
		$conditions[] = self::keyAbsent('dq:1');
		$conditions[] = self::keyPresent('di:' . $localId);
		$conditions[] = self::downloadEquals('d.local_id=', $localId);
		$conditions[] = 'd.custom=chk-meta-old';
		return(self::callback('deferred-service-skip', $hash, $conditions, array(
			'$method.set_key=rr.receipts.v1,di:' . $localId,
		), self::state(null, $epoch, $token, $mode, $userHash,
			array(), array('di:' . $localId))));
	}

	public static function replayDeferred($epoch, $token, $mode, $userHash, $hash,
		$localId, $state, $handoff, $script, $php, $user, $nativeOriginal = false)
	{
		$conditions = self::ownerConditions($epoch, $token, $mode, $userHash);
		$conditions[] = self::keyAbsent('dq:1');
		$conditions[] = self::keyPresent('di:' . $localId);
		$conditions[] = self::downloadEquals('d.local_id=', $localId);
		$conditions[] = self::downloadIntegerEquals('d.state=', $state);
		$conditions[] = self::downloadEquals('d.custom=retrackers-recovery', '');
		$conditions[] = self::downloadEquals('d.custom=retrackers-recovery-ack', '');
		$conditions[] = self::downloadEquals('d.custom=chk-meta-old', '');
		$conditions[] = 'not=(equal,' . self::q('d.custom3=') . ',' .
			self::q('cat=' . self::q('1')) . ')';
		$launch = '$execute.throw.bg={sh,' . self::q($script) . ',' . self::q($php) .
			',$d.hash=,' . self::q($user) . ',$d.custom=retrackers-recovery}';
		// execute.throw.bg returns numeric 0 on success. The outer cat callback
		// needs an empty value so its exact ACQUIRED sentinel is preserved.
		$quietLaunch = '$branch=' . self::q($launch) . ',' .
			self::q('cat=') . ',' . self::q('cat=');
		$handoffCommands = $nativeOriginal ?
			array(retrackersNativeBeginExpression($userHash),
				'$method.set_key=rr.receipts.v1,wp:' . $localId . ',1') : array(
				'$method.set_key=rr.receipts.v1,wp:' . $localId . ',1',
				'$d.custom.set=retrackers-recovery-ack,' . self::q($handoff),
				'$d.custom.set=retrackers-recovery,' . self::q($handoff),
			);
		return(self::callback('deferred-replay', $hash, $conditions, array_merge(
			array('$method.set_key=rr.receipts.v1,wh:' . $localId . ',1'),
			$handoffCommands,
			array('$method.set_key=rr.receipts.v1,di:' . $localId,
				$quietLaunch,
				'$method.set_key=rr.receipts.v1,wh:' . $localId,
			)), self::state(null, $epoch, $token, $mode, $userHash,
			array(), array('wh:' . $localId, 'di:' . $localId))));
	}

	public static function retireNativePending($hash, $localId, $tx, $incarnation,
		$epoch = null, $token = null, $mode = null, $userHash = null)
	{
		if (preg_match('/^[0-9A-F]{40}$/D', $hash) !== 1 ||
			preg_match('/^[0-9A-F]{40}$/D', $localId) !== 1 ||
			preg_match('/^[0-9a-f]{32}$/D', $tx) !== 1 ||
			preg_match('/^[0-9a-f]{32}$/D', $incarnation) !== 1) {
			return(false);
		}
		$conditions = $epoch === null ? array() :
			self::ownerConditions($epoch, $token, $mode, $userHash);
		$conditions[] = self::keyPresent('wp:' . $localId);
		$conditions[] = self::keyAbsent('wh:' . $localId);
		// L is the durable claim key; rTorrent changes d.local_id on cold restart.
		$conditions[] = self::downloadEquals('d.incarnation=', $incarnation);
		$conditions[] = self::downloadEquals('d.custom=retrackers-recovery', '');
		$conditions[] = self::downloadEquals('d.custom=retrackers-recovery-ack', '');
		$conditions[] = self::downloadEquals('system.retrackers.finalized=' . $hash,
			'finalized:' . $tx . ':' . $incarnation);
		return(self::callback('native-pending-retire', $hash, $conditions,
			array('$method.set_key=rr.receipts.v1,wp:' . $localId),
			self::state(null, $epoch, $token, $mode, $userHash,
				array(), array('wp:' . $localId))));
	}

	public static function deleteStalePending($epoch, $token, $mode, $userHash, $localId)
	{
		$conditions = self::ownerConditions($epoch, $token, $mode, $userHash);
		$conditions[] = self::keyPresent('wp:' . $localId);
		return(self::callback('pending-stale-delete', '', $conditions, array(
			'$method.set_key=rr.receipts.v1,wp:' . $localId,
		), self::state(null, $epoch, $token, $mode, $userHash,
			array(), array('wp:' . $localId))));
	}

	public static function cancelPending($epoch, $token, $userHash, $hash, $localId, $handoff,
		$mode = 'd')
	{
		$conditions = self::ownerConditions($epoch, $token, $mode, $userHash);
		$conditions[] = self::keyPresent('wp:' . $localId);
		$conditions[] = self::downloadEquals('d.local_id=', $localId);
		$conditions[] = self::downloadEquals('d.custom=retrackers-recovery', $handoff);
		$conditions[] = self::downloadEquals('d.custom=retrackers-recovery-ack', $handoff);
		return(self::callback('pending-cancel', $hash, $conditions, array(
			'$d.custom.set=retrackers-recovery-ack,',
			'$d.custom.set=retrackers-recovery,',
			'$method.set_key=rr.receipts.v1,wp:' . $localId,
		), self::state(null, $epoch, $token, $mode, $userHash,
			array(), array('wp:' . $localId))));
	}
}

class RetrackersWorkerAdoptCallback
{
	private $method;
	private $params;

	private function __construct($method, array $params)
	{
		$this->method = $method;
		$this->params = $params;
	}

	private static function q($value)
	{
		return(rTorrent::quoteCommandArg($value));
	}

	private static function all(array $conditions)
	{
		$condition = array_shift($conditions);
		foreach ($conditions as $next) {
			$condition = 'and=' . self::q($condition) . ',' . self::q($next);
		}
		return($condition);
	}

	public static function build($tx, $hash, $handoff, $localId)
	{
		$wa = 'wa:' . $tx;
		$wp = 'wp:' . $localId;
		$presentWa = 'method.has_key=rr.receipts.v1,' . $wa;
		$presentWp = 'method.has_key=rr.receipts.v1,' . $wp;
		$absentWa = 'not=(method.has_key,rr.receipts.v1,' . $wa . ')';
		$absentWp = 'not=(method.has_key,rr.receipts.v1,' . $wp . ')';
		$absentMa = 'not=(method.has_key,rr.receipts.v1,ma:1)';
		$ownership = self::all(array(
			'equal=' . self::q('d.local_id=') . ',' . self::q('cat=' . self::q($localId)),
			'equal=' . self::q('d.custom=retrackers-recovery') . ',' .
				self::q('cat=' . self::q($handoff)),
			'equal=' . self::q('d.custom=retrackers-recovery-ack') . ',' .
				self::q('cat=' . self::q($handoff)),
		));
		$already = self::all(array($presentWa, $absentWp));
		$adopt = self::all(array($absentWa, $presentWp, $absentMa));
		$adoptBody = 'cat=' . self::q('$method.set_key=rr.receipts.v1,' . $wa . ',1') . ',' .
			self::q('$method.set_key=rr.receipts.v1,' . $wp) . ',ADOPTED';
		$decide = 'branch=' . self::q($already) . ',' . self::q('cat=ADOPTED') . ',' .
			self::q('branch=' . self::q($adopt) . ',' . self::q($adoptBody) . ',' .
				self::q('branch=' . self::q($presentWp) . ',' . self::q('cat=DENIED') . ',' .
					self::q('cat=CHANGED')));
		return(new self('branch', array($hash, $ownership, $decide, 'cat=CHANGED')));
	}

	public function method()
	{
		return($this->method);
	}

	public function params()
	{
		return($this->params);
	}

	public function responsePlan($family)
	{
		return(retrackersRestrictedPlanDirectScalar('string'));
	}
}

/** One-shot targeted release of the still-owned source handoff. */
final class RetrackersHandoffReleaseCallback
{
	private $method;
	private $params;

	private function __construct($method, array $params)
	{
		$this->method = $method;
		$this->params = $params;
	}

	private static function q($value)
	{
		return(rTorrent::quoteCommandArg($value));
	}

	private static function all(array $conditions)
	{
		$condition = array_shift($conditions);
		foreach ($conditions as $next) {
			$condition = 'and=' . self::q($condition) . ',' . self::q($next);
		}
		return($condition);
	}

	public static function build($tx, $hash, $handoff, $localId)
	{
		if (!is_string($tx) || preg_match('/^[0-9a-f]{32}$/D', $tx) !== 1 ||
			!is_string($hash) || preg_match('/^[0-9A-F]{40}$/D', $hash) !== 1 ||
			!is_string($localId) || preg_match('/^[0-9A-F]{40}$/D', $localId) !== 1 ||
			!is_string($handoff)) {
			return(false);
		}
		$original = preg_match('/^v1:original:[01]:' . preg_quote($localId, '/') .
			':[0-9a-f]{64}$/D', $handoff) === 1;
		$loaded = in_array($handoff, array(
			'v1:candidate-ready:' . $tx,
			'v1:rollback-ready:' . $tx,
		), true);
		if (!$original && !$loaded) {
			return(false);
		}
		$conditions = array(
			'method.has_key=rr.receipts.v1,wa:' . $tx,
			'equal=' . self::q('d.local_id=') . ',' .
				self::q('cat=' . self::q($localId)),
			'equal=' . self::q('d.custom=retrackers-recovery') . ',' .
				self::q('cat=' . self::q($handoff)),
			'equal=' . self::q('d.custom=retrackers-recovery-ack') . ',' .
				self::q('cat=' . self::q($handoff)),
		);
		$body = 'cat=' . self::q('$d.custom.set=retrackers-recovery-ack,') . ',' .
			self::q('$d.custom.set=retrackers-recovery,') .
			',RETRACKERS_HANDOFF_RELEASED';
		return(new self('branch', array($hash, self::all($conditions), $body,
			'cat=RETRACKERS_HANDOFF_CHANGED')));
	}

	public function method()
	{
		return($this->method);
	}

	public function params()
	{
		return($this->params);
	}

	public function responsePlan($family)
	{
		return(retrackersRestrictedPlanDirectScalar('string'));
	}
}

/** One global ordered callback owns terminal receipt and schedule cleanup. */
final class RetrackersTerminalCleanupCallback
{
	private $method;
	private $params;

	private function __construct($method, array $params)
	{
		$this->method = $method;
		$this->params = $params;
	}

	private static function q($value)
	{
		return(rTorrent::quoteCommandArg($value));
	}

	private static function body(array $commands)
	{
		$body = 'cat=';
		foreach ($commands as $index => $command) {
			if ($index > 0) {
				$body .= ',';
			}
			$body .= self::q($command);
		}
		return($body . ',RETRACKERS_TERMINAL_CLEANED');
	}

	private static function validTx($tx)
	{
		return(is_string($tx) && preg_match('/^[0-9a-f]{32}$/D', $tx) === 1);
	}

	private static function deleteCommand($key)
	{
		return('$method.set_key=rr.receipts.v1,' . $key);
	}

	private static function callback($tx, array $keys)
	{
		$scheduleRemove = retrackersResolveSchedulerCommand('schedule_remove');
		if (!in_array($scheduleRemove, array('schedule_remove', 'schedule.remove'), true)) {
			return(false);
		}
		$commands = array();
		foreach ($keys as $key) {
			$commands[] = self::deleteCommand($key);
		}
		$capabilityCount = count($keys) >= 3 &&
			$keys[0] === 'v1:candidate-ready:' . $tx &&
			$keys[1] === 'v1:rollback-ready:' . $tx &&
			$keys[2] === 'wa:' . $tx ? 3 : 0;
		if ($capabilityCount === 3) {
			array_splice($commands, 3, 0, array(
				'$' . $scheduleRemove . '=rr-lf-' . $tx,
				'$' . $scheduleRemove . '=rr-rf-' . $tx,
			));
		} else {
			array_unshift($commands,
				'$' . $scheduleRemove . '=rr-rf-' . $tx);
			array_unshift($commands,
				'$' . $scheduleRemove . '=rr-lf-' . $tx);
		}
		return(new self('branch', array('', 'cat=1', self::body($commands),
			'cat=RETRACKERS_TERMINAL_CHANGED')));
	}

	public static function build($tx)
	{
		if (!self::validTx($tx)) {
			return(false);
		}
		$keys = array(
			'v1:candidate-ready:' . $tx,
			'v1:rollback-ready:' . $tx,
			'wa:' . $tx,
		);
		foreach (array('ea', 'eb', 'ed', 'ex', 'la', 'lb', 'lf',
			'ca', 'cb', 'cd', 'cx', 'ra', 'rb', 'rf') as $prefix) {
			$keys[] = $prefix . ':' . $tx;
		}
		return(self::callback($tx, $keys));
	}

	public static function buildSuffix($tx, array $remaining)
	{
		if (!self::validTx($tx)) {
			return(false);
		}
		$allowed = array('v1:candidate-ready:' . $tx,
			'v1:rollback-ready:' . $tx);
		foreach (array('ea', 'eb', 'ed', 'ex', 'la', 'lb', 'lf',
			'ca', 'cb', 'cd', 'cx', 'ra', 'rb', 'rf') as $prefix) {
			$allowed[] = $prefix . ':' . $tx;
		}
		$keys = array();
		foreach ($remaining as $key) {
			if (!is_string($key) || !in_array($key, $allowed, true)) {
				return(false);
			}
			if (!in_array($key, $keys, true)) {
				$keys[] = $key;
			}
		}
		return(self::callback($tx, $keys));
	}

	public function method()
	{
		return($this->method);
	}

	public function params()
	{
		return($this->params);
	}

	public function responsePlan($family)
	{
		return(retrackersRestrictedPlanDirectScalar('string'));
	}
}

/** One-shot global arm for the old-generation erase phase. */
final class RetrackersRecoveryPhaseArmCallback
{
	private $method;
	private $params;

	private function __construct($method, array $params)
	{
		$this->method = $method;
		$this->params = $params;
	}

	private static function q($value)
	{
		return(rTorrent::quoteCommandArg($value));
	}

	private static function all(array $conditions)
	{
		$condition = array_shift($conditions);
		foreach ($conditions as $next) {
			$condition = 'and=' . self::q($condition) . ',' . self::q($next);
		}
		return($condition);
	}

	public static function build($phase, $tx)
	{
		$phases = array(
			'ea' => array('ea', 'eb', 'ed', 'ex'),
			'la' => array('la', 'lb', 'lf'),
			'ca' => array('ca', 'cb', 'cd', 'cx'),
			'ra' => array('ra', 'rb', 'rf'),
		);
		if (!isset($phases[$phase]) || !is_string($tx) ||
			preg_match('/^[0-9a-f]{32}$/D', $tx) !== 1) {
			return(false);
		}
		$conditions = array('method.has_key=rr.receipts.v1,wa:' . $tx);
		foreach ($phases[$phase] as $prefix) {
			$conditions[] = 'not=(method.has_key,rr.receipts.v1,' .
				$prefix . ':' . $tx . ')';
		}
		$capability = null;
		if ($phase === 'la') {
			$capability = 'v1:candidate-ready:' . $tx;
		} elseif ($phase === 'ra') {
			$capability = 'v1:rollback-ready:' . $tx;
		}
		if ($capability !== null) {
			$conditions[] = 'not=(method.has_key,rr.receipts.v1,' . $capability . ')';
		}
		$commands = array('$method.set_key=rr.receipts.v1,' . $phase . ':' . $tx . ',1');
		if ($capability !== null) {
			$commands[] = '$method.set_key=rr.receipts.v1,' . $capability . ',1';
		}
		$quoted = array();
		foreach ($commands as $command) {
			$quoted[] = self::q($command);
		}
		$trueBody = 'cat=' . implode(',', $quoted) . ',RETRACKERS_PHASE_ARMED';
		return(new self('branch', array('', self::all($conditions), $trueBody,
			'cat=RETRACKERS_PHASE_CHANGED')));
	}

	public function method()
	{
		return($this->method);
	}

	public function params()
	{
		return($this->params);
	}

	public function responsePlan($family)
	{
		return(retrackersRestrictedPlanDirectScalar('string'));
	}
}

/** Exact dry/materialised command metrics, saturated at the request cap plus one. */
final class RetrackersCommandFragment
{
	private $length;
	private $backslashes;
	private $quotes;
	private $ampersands;
	private $lessThan;
	private $greaterThan;
	private $text;
	private $limit;

	private function __construct($length, $backslashes, $quotes, $ampersands,
		$lessThan, $greaterThan, $text, $limit)
	{
		$this->length = $length;
		$this->backslashes = $backslashes;
		$this->quotes = $quotes;
		$this->ampersands = $ampersands;
		$this->lessThan = $lessThan;
		$this->greaterThan = $greaterThan;
		$this->text = $text;
		$this->limit = $limit;
	}

	private static function ceiling($limit)
	{
		return($limit === PHP_INT_MAX ? PHP_INT_MAX : $limit + 1);
	}

	private static function add($left, $right, $limit)
	{
		$ceiling = self::ceiling($limit);
		return($left >= $ceiling || $right >= $ceiling - $left ?
			$ceiling : $left + $right);
	}

	private static function multiply($value, $factor, $limit)
	{
		$ceiling = self::ceiling($limit);
		if ($value === 0 || $factor === 0) {
			return(0);
		}
		return($value >= $ceiling || $factor > intdiv($ceiling - 1, $value) ?
			$ceiling : $value * $factor);
	}

	public static function literal($value, $materialize, $limit)
	{
		if (!is_string($value) || !is_bool($materialize) || !is_int($limit) || $limit < 1) {
			return(false);
		}
		$ceiling = self::ceiling($limit);
		return(new self(
			min(strlen($value), $ceiling),
			min(substr_count($value, '\\'), $ceiling),
			min(substr_count($value, '"'), $ceiling),
			min(substr_count($value, '&'), $ceiling),
			min(substr_count($value, '<'), $ceiling),
			min(substr_count($value, '>'), $ceiling),
			$materialize ? $value : null,
			$limit
		));
	}

	public static function concat(array $parts, $materialize, $limit)
	{
		if (!is_bool($materialize) || !is_int($limit) || $limit < 1) {
			return(false);
		}
		$metrics = array(0, 0, 0, 0, 0, 0);
		foreach ($parts as $part) {
			if (!($part instanceof self) || $part->limit !== $limit) {
				return(false);
			}
			foreach (array('length', 'backslashes', 'quotes', 'ampersands',
				'lessThan', 'greaterThan') as $index => $field) {
				$metrics[$index] = self::add($metrics[$index], $part->$field, $limit);
			}
		}
		if ($materialize && $metrics[0] > $limit) {
			return(false);
		}
		$text = null;
		if ($materialize) {
			$text = '';
			foreach ($parts as $part) {
				$next = $part->takeText();
				if (!is_string($next)) {
					return(false);
				}
				$text .= $next;
				unset($next);
			}
		}
		return(new self($metrics[0], $metrics[1], $metrics[2], $metrics[3],
			$metrics[4], $metrics[5], $text, $limit));
	}

	public function quoted($materialize)
	{
		if (!is_bool($materialize)) {
			return(false);
		}
		$length = self::add(self::add($this->length, $this->backslashes, $this->limit),
			self::add($this->quotes, 2, $this->limit), $this->limit);
		$backslashes = self::add(self::multiply($this->backslashes, 2, $this->limit),
			$this->quotes, $this->limit);
		$quotes = self::add($this->quotes, 2, $this->limit);
		if ($materialize && $length > $this->limit) {
			return(false);
		}
		$text = null;
		if ($materialize) {
			$source = $this->takeText();
			if (!is_string($source)) {
				return(false);
			}
			$text = rTorrent::quoteCommandArg($source);
			unset($source);
			if (!is_string($text) || strlen($text) !== $length) {
				return(false);
			}
		}
		return(new self($length, $backslashes, $quotes, $this->ampersands,
			$this->lessThan, $this->greaterThan, $text, $this->limit));
	}

	public function length()
	{
		return($this->length);
	}

	public function xmlLength()
	{
		$length = self::add($this->length,
			self::multiply($this->ampersands, 4, $this->limit), $this->limit);
		$length = self::add($length,
			self::multiply($this->lessThan, 3, $this->limit), $this->limit);
		return(self::add($length,
			self::multiply($this->greaterThan, 3, $this->limit), $this->limit));
	}

	public function text()
	{
		return($this->text);
	}

	public function takeText()
	{
		$text = $this->text;
		$this->text = null;
		return($text);
	}

	public static function directRequestLength($method, array $params, $limit)
	{
		if (!is_string($method) || !is_int($limit) || $limit < 1) {
			return(false);
		}
		$method = self::literal($method, false, $limit);
		if ($method === false) {
			return(false);
		}
		$length = strlen('<?xml version="1.0" encoding="UTF-8"?><methodCall><methodName>');
		$length = self::add($length, $method->xmlLength(), $limit);
		$length = self::add($length, strlen('</methodName><params>'), $limit);
		foreach ($params as $param) {
			if (!($param instanceof self)) {
				return(false);
			}
			$length = self::add($length, strlen('<param><value><string>'), $limit);
			$length = self::add($length, $param->xmlLength(), $limit);
			$length = self::add($length, strlen('</string></value></param>'), $limit);
		}
		return(self::add($length, strlen('</params></methodCall>'), $limit));
	}

	private static function memoryLimitBytes()
	{
		$value = ini_get('memory_limit');
		if ($value === '-1') {
			return(-1);
		}
		if (!is_string($value) || preg_match('/^(0|[1-9][0-9]*)([KMGkmg])?$/D',
			$value, $parts) !== 1) {
			return(false);
		}
		$bytes = 0;
		foreach (str_split($parts[1]) as $digit) {
			$bytes = self::add(self::multiply($bytes, 10, PHP_INT_MAX),
				(int)$digit, PHP_INT_MAX);
		}
		$suffix = isset($parts[2]) ? strtoupper($parts[2]) : '';
		$power = array_search($suffix, array('', 'K', 'M', 'G'), true);
		if ($power === false) {
			return(false);
		}
		for ($index = 0; $index < $power; $index++) {
			$bytes = self::multiply($bytes, 1024, PHP_INT_MAX);
		}
		return($bytes > 0 ? $bytes : false);
	}

	public static function hasMaterializationHeadroom($wireBytes)
	{
		if (!is_int($wireBytes) || $wireBytes < 1) {
			return(false);
		}
		$memoryLimit = self::memoryLimitBytes();
		if ($memoryLimit === false) {
			return(false);
		}
		if ($memoryLimit === -1) {
			return(true);
		}
		$required = self::add(
			self::multiply($wireBytes, 12, PHP_INT_MAX), 16 * 1024 * 1024, PHP_INT_MAX);
		$baseline = memory_get_usage(true);
		$available = $memoryLimit > $baseline ? $memoryLimit - $baseline : 0;
		return($required <= $available);
	}

	public static function hasSingleOwnerMaterializationHeadroom($wireBytes)
	{
		if (!is_int($wireBytes) || $wireBytes < 1) {
			return(false);
		}
		$memoryLimit = self::memoryLimitBytes();
		if ($memoryLimit === false) {
			return(false);
		}
		if ($memoryLimit === -1) {
			return(true);
		}
		// The consuming fragment reducer owns each source string exactly once.
		// Eight complete wires cover the live source, both str_replace passes,
		// quoted result, reducer peer, accumulated XML, its current escaped
		// parameter and one allocator copy. One further wire covers the sealed
		// original while the alternate local-id slot render is compiled.
		$required = self::add(
			self::multiply($wireBytes, 9, PHP_INT_MAX), 16 * 1024 * 1024, PHP_INT_MAX);
		$baseline = memory_get_usage(true);
		$available = $memoryLimit > $baseline ? $memoryLimit - $baseline : 0;
		return($required <= $available);
	}

	public static function hasSealedCallbackSetHeadroom(array $wireBytes)
	{
		if (count($wireBytes) === 0) {
			return(false);
		}
		$total = 0;
		$largest = 0;
		foreach ($wireBytes as $bytes) {
			if (!is_int($bytes) || $bytes < 1) {
				return(false);
			}
			$total = self::add($total, $bytes, PHP_INT_MAX);
			$largest = max($largest, $bytes);
		}
		$memoryLimit = self::memoryLimitBytes();
		if ($memoryLimit === false) {
			return(false);
		}
		if ($memoryLimit === -1) {
			return(true);
		}
		// Every exact XML wire remains owned by its callback. The largest
		// callback is materialized while all previously sealed wires are live;
		// nine wires cover its fragments, params, XML, allocator copy and the
		// alternate render that pins the two fixed-width local-id slots.
		$required = self::add($total,
			self::multiply($largest, 9, PHP_INT_MAX), PHP_INT_MAX);
		$required = self::add($required,
			self::multiply(count($wireBytes), 4096, PHP_INT_MAX), PHP_INT_MAX);
		$required = self::add($required, 16 * 1024 * 1024, PHP_INT_MAX);
		$baseline = memory_get_usage(true);
		$available = $memoryLimit > $baseline ? $memoryLimit - $baseline : 0;
		return($required <= $available);
	}

	public static function hasPrefixMaterializationHeadroom(
		$candidatePrefixes, $rollbackPrefixes)
	{
		if (!is_int($candidatePrefixes) || $candidatePrefixes < 1 ||
			!is_int($rollbackPrefixes) || $rollbackPrefixes < 1) {
			return(false);
		}
		$memoryLimit = self::memoryLimitBytes();
		if ($memoryLimit === false) {
			return(false);
		}
		if ($memoryLimit === -1) {
			return(true);
		}
		// Each successive valid prefix retains a larger PHP array. Budget the
		// cumulative buckets for both obligations before constructing either set.
		$candidateBuckets = self::multiply(
			$candidatePrefixes, $candidatePrefixes, PHP_INT_MAX);
		$rollbackBuckets = self::multiply(
			$rollbackPrefixes, $rollbackPrefixes, PHP_INT_MAX);
		$buckets = self::add($candidateBuckets, $rollbackBuckets, PHP_INT_MAX);
		$required = self::add(
			self::multiply($buckets, 64, PHP_INT_MAX),
			16 * 1024 * 1024, PHP_INT_MAX);
		$baseline = memory_get_usage(true);
		$available = $memoryLimit > $baseline ? $memoryLimit - $baseline : 0;
		return($required <= $available);
	}
}

/** Ordered binary-carry reducer for bounded balanced and=/or= command trees. */
final class RetrackersCommandFragmentReducer
{
	private $operator;
	private $materialize;
	private $limit;
	private $levels = array();

	public function __construct($operator, $materialize, $limit)
	{
		$this->operator = $operator;
		$this->materialize = $materialize;
		$this->limit = $limit;
	}

	private function pair($left, $right)
	{
		$prefix = RetrackersCommandFragment::literal(
			$this->operator, $this->materialize, $this->limit);
		$comma = RetrackersCommandFragment::literal(',', $this->materialize, $this->limit);
		$left = $left->quoted($this->materialize);
		$right = $right->quoted($this->materialize);
		if ($prefix === false || $comma === false || $left === false || $right === false) {
			return(false);
		}
		return(RetrackersCommandFragment::concat(
			array($prefix, $left, $comma, $right), $this->materialize, $this->limit));
	}

	public function push($fragment)
	{
		if (!($fragment instanceof RetrackersCommandFragment)) {
			return(false);
		}
		$level = 0;
		while (array_key_exists($level, $this->levels)) {
			$left = $this->levels[$level];
			unset($this->levels[$level]);
			$fragment = $this->pair($left, $fragment);
			if ($fragment === false) {
				return(false);
			}
			$level++;
		}
		$this->levels[$level] = $fragment;
		return(true);
	}

	public function finish()
	{
		if (count($this->levels) === 0) {
			return(false);
		}
		krsort($this->levels, SORT_NUMERIC);
		$result = null;
		foreach ($this->levels as $fragment) {
			$result = $result === null ? $fragment : $this->pair($result, $fragment);
			if ($result === false) {
				return(false);
			}
		}
		$this->levels = array();
		return($result);
	}
}

/**
 * One daemon callback owns both old-generation CAS layers and every mutation.
 * The builder consumes only the already stable, bounded source snapshot.
 */
final class RetrackersOldGenerationCommitCallback
{
	private $method;
	private $params;
	private $wire;
	private $estimatedWireBytes;

	private function __construct($method, array $params, $wire, $estimatedWireBytes)
	{
		$this->method = $method;
		$this->params = $params;
		$this->wire = $wire;
		$this->estimatedWireBytes = $estimatedWireBytes;
	}

	private static function literal($value, $materialize, $limit)
	{
		return(RetrackersCommandFragment::literal($value, $materialize, $limit));
	}

	private static function concat(array $parts, $materialize, $limit)
	{
		foreach ($parts as $part) {
			if ($part === false) {
				return(false);
			}
		}
		return(RetrackersCommandFragment::concat($parts, $materialize, $limit));
	}

	private static function q($value, $materialize, $limit)
	{
		$fragment = $value instanceof RetrackersCommandFragment ?
			$value : self::literal($value, $materialize, $limit);
		return($fragment === false ? false : $fragment->quoted($materialize));
	}

	private static function stringEquals($getter, $value, $materialize, $limit)
	{
		$value = self::concat(array(
			self::literal('cat=', $materialize, $limit),
			self::q($value, $materialize, $limit),
		), $materialize, $limit);
		return(self::concat(array(
			self::literal('equal=', $materialize, $limit),
			self::q($getter, $materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q($value, $materialize, $limit),
		), $materialize, $limit));
	}

	private static function integerEquals($getter, $value, $materialize, $limit)
	{
		return(self::concat(array(
			self::literal('equal=', $materialize, $limit),
			self::q($getter, $materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q('value=' . $value, $materialize, $limit),
		), $materialize, $limit));
	}

	private static function representable($value, $allowLf = false)
	{
		return(is_string($value) && ($value === '' || $value[0] !== '$') &&
			strpos($value, "\0") === false && strpos($value, "\r") === false &&
			($allowLf || strpos($value, "\n") === false));
	}

	private static function canonicalInteger($value)
	{
		return(is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1);
	}

	private static function pushGenericConditions($reducer, array $pairs, $materialize, $limit)
	{
		if (!$reducer->push(self::integerEquals(
			'math.cnt=(d.custom.keys)', (string)count($pairs), $materialize, $limit))) {
			return(false);
		}
		$seen = array();
		foreach ($pairs as $pair) {
			if (!is_array($pair) || array_keys($pair) !== array('name', 'value') ||
				!self::representable($pair['name'], true) ||
				!self::representable($pair['value'], true) ||
				isset($seen["\0" . $pair['name']])) {
				return(false);
			}
			$seen["\0" . $pair['name']] = true;
			$left = self::concat(array(
				self::literal('d.custom_throw=', $materialize, $limit),
				self::q($pair['name'], $materialize, $limit),
			), $materialize, $limit);
			if ($left === false || !$reducer->push(self::stringEquals(
				$left, $pair['value'], $materialize, $limit))) {
				return(false);
			}
		}
		unset($seen);
		return(true);
	}

	private static function enabledIndex(array $enabled, array $indices, $url)
	{
		$low = 0;
		$high = count($indices) - 1;
		while ($low <= $high) {
			$middle = $low + intdiv($high - $low, 2);
			$index = $indices[$middle];
			$comparison = strcmp($enabled[$index]['url'], $url);
			if ($comparison === 0) {
				return($index);
			}
			if ($comparison < 0) {
				$low = $middle + 1;
			} else {
				$high = $middle - 1;
			}
		}
		return(false);
	}

	private static function sameTrackerKey($left, $right)
	{
		return($left['url'] === $right['url'] && $left['group'] === $right['group'] &&
			$left['extra'] === $right['extra']);
	}

	private static function trackerMatch(array $row, $state, $materialize, $limit)
	{
		$reducer = new RetrackersCommandFragmentReducer('and=', $materialize, $limit);
		foreach (array(
			self::stringEquals('t.url=', $row['url'], $materialize, $limit),
			self::integerEquals('t.group=', $row['group'], $materialize, $limit),
			self::integerEquals('t.is_extra_tracker=', $row['extra'], $materialize, $limit),
		) as $fragment) {
			if (!$reducer->push($fragment)) {
				return(false);
			}
		}
		if ($state !== null && !$reducer->push(self::integerEquals(
			't.is_enabled=', $state, $materialize, $limit))) {
			return(false);
		}
		return($reducer->finish());
	}

	private static function pushTrackerConditions($reducer, array $topology, array $enabled,
		$materialize, $limit)
	{
		if (count($topology) === 0) {
			return($reducer->push(self::literal(
				'not=(t.multicall,,cat=1)', $materialize, $limit)));
		}
		$actual = array_fill(0, count($enabled), 0);
		foreach ($enabled as $entry) {
			if (!is_array($entry) || array_keys($entry) !== array('url', 'multiplicity', 'state') ||
				!self::representable($entry['url'], true) || !is_int($entry['multiplicity']) ||
				$entry['multiplicity'] < 1 || !in_array($entry['state'], array('0', '1'), true)) {
				return(false);
			}
		}
		if (count($enabled) === 0) {
			return(false);
		}
		// Sort only original integer positions: arbitrary tracker URLs stay in the snapshot.
		$enabledIndices = range(0, count($enabled) - 1);
		usort($enabledIndices, function ($left, $right) use (&$enabled) {
			$comparison = strcmp($enabled[$left]['url'], $enabled[$right]['url']);
			return($comparison !== 0 ? $comparison : $left <=> $right);
		});
		for ($position = 1; $position < count($enabledIndices); $position++) {
			if ($enabled[$enabledIndices[$position - 1]]['url'] ===
				$enabled[$enabledIndices[$position]]['url']) {
				return(false);
			}
		}
		$topologyEnabledIndices = array();
		foreach ($topology as $row) {
			if (!is_array($row) || array_keys($row) !== array('group', 'url', 'type', 'extra') ||
				!self::representable($row['url'], true) ||
				!self::canonicalInteger($row['group']) || !self::canonicalInteger($row['type']) ||
				!in_array($row['extra'], array('0', '1'), true)) {
				return(false);
			}
			$enabledIndex = self::enabledIndex($enabled, $enabledIndices, $row['url']);
			if ($enabledIndex === false) {
				return(false);
			}
			$topologyEnabledIndices[] = $enabledIndex;
			$actual[$enabledIndex]++;
		}
		foreach ($enabled as $index => $entry) {
			if ($actual[$index] !== $entry['multiplicity']) {
				return(false);
			}
		}
		unset($actual, $enabledIndices);

		$topologyIndices = range(0, count($topology) - 1);
		usort($topologyIndices, function ($left, $right) use (&$topology) {
			$comparison = strcmp($topology[$left]['url'], $topology[$right]['url']);
			if ($comparison !== 0) {
				return($comparison);
			}
			$comparison = strcmp($topology[$left]['group'], $topology[$right]['group']);
			if ($comparison !== 0) {
				return($comparison);
			}
			$comparison = strcmp($topology[$left]['extra'], $topology[$right]['extra']);
			if ($comparison !== 0) {
				return($comparison);
			}
			return($left <=> $right);
		});
		$groups = array();
		$first = 0;
		for ($position = 1; $position <= count($topologyIndices); $position++) {
			if ($position < count($topologyIndices) && self::sameTrackerKey(
				$topology[$topologyIndices[$first]], $topology[$topologyIndices[$position]])) {
				continue;
			}
			$groups[] = array($topologyIndices[$first], $position - $first);
			$first = $position;
		}
		unset($topologyIndices);
		// Group predicates must retain the old first-occurrence command order.
		usort($groups, function ($left, $right) {
			return($left[0] <=> $right[0]);
		});

		if (!$reducer->push(self::integerEquals(
			'math.cnt=(t.multicall,,cat=1)', (string)count($topology), $materialize, $limit))) {
			return(false);
		}
		foreach ($groups as $group) {
			$row = $topology[$group[0]];
			$count = $group[1];
			$matches = self::trackerMatch($row, null, $materialize, $limit);
			$project = self::concat(array(
				self::literal('branch=', $materialize, $limit),
				self::q($matches, $materialize, $limit),
				self::literal(',', $materialize, $limit),
				self::q('cat=1', $materialize, $limit),
				self::literal(',', $materialize, $limit),
				self::q('cat=0', $materialize, $limit),
			), $materialize, $limit);
			$sum = self::concat(array(
				self::literal('math.add=(t.multicall,,', $materialize, $limit),
				self::q($project, $materialize, $limit),
				self::literal(')', $materialize, $limit),
			), $materialize, $limit);
			if ($matches === false || $project === false || $sum === false ||
				!$reducer->push(self::integerEquals($sum, (string)$count,
					$materialize, $limit))) {
				return(false);
			}
		}
		unset($groups);

		$allowed = new RetrackersCommandFragmentReducer('or=', $materialize, $limit);
		$position = 0;
		foreach ($topology as $row) {
			$enabledIndex = $topologyEnabledIndices[$position++];
			if (!$allowed->push(self::trackerMatch(
				$row, $enabled[$enabledIndex]['state'], $materialize, $limit))) {
				return(false);
			}
		}
		unset($topologyEnabledIndices);
		$membership = self::concat(array(
			self::literal('branch=', $materialize, $limit),
			self::q($allowed->finish(), $materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q('cat=1', $materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q('cat=0', $materialize, $limit),
		), $materialize, $limit);
		$minimum = self::concat(array(
			self::literal('math.min=(t.multicall,,', $materialize, $limit),
			self::q($membership, $materialize, $limit),
			self::literal(')', $materialize, $limit),
		), $materialize, $limit);
		return($membership !== false && $minimum !== false && $reducer->push(
			self::integerEquals($minimum, '1', $materialize, $limit)));
	}

	private static function casConditions($tx, $handoff, array $snapshot, $quiesced,
		$materialize, $limit)
	{
		if (!isset($snapshot['scalar'], $snapshot['generic_map'], $snapshot['tracker_topology'],
			$snapshot['tracker_enabled']) || !is_array($snapshot['scalar']) ||
			!is_array($snapshot['generic_map']) || !is_array($snapshot['tracker_topology']) ||
			!is_array($snapshot['tracker_enabled'])) {
			return(false);
		}
		$scalar = $snapshot['scalar'];
		$stringFields = array(
			'tied_source' => 'd.tied_to_file=',
			'loaded_file' => 'd.loaded_file=',
			'directory_base' => 'd.directory_base=',
			'custom1' => 'd.custom1=',
			'custom2' => 'd.custom2=',
			'custom3' => 'd.custom3=',
			'custom4' => 'd.custom4=',
			'custom5' => 'd.custom5=',
			'throttle_name' => 'd.throttle_name=',
		);
		$integerFields = array('priority' => 'd.priority=');
		foreach (array_merge(array('local_id', 'recovery_marker', 'recovery_ack'),
			array_keys($stringFields), array_keys($integerFields),
			array('state', 'is_active', 'is_open', 'hashing', 'hashing_failed')) as $name) {
			if (!array_key_exists($name, $scalar) || !is_string($scalar[$name])) {
				return(false);
			}
		}
		if (!self::representable($handoff) ||
			preg_match('/^v1:original:([01]):([0-9A-F]{40}):[0-9a-f]{64}$/D',
				$handoff, $handoffParts) !== 1 ||
			$scalar['state'] !== $handoffParts[1] || $scalar['local_id'] !== $handoffParts[2] ||
			$scalar['recovery_marker'] !== $handoff || $scalar['recovery_ack'] !== $handoff ||
			!in_array($scalar['is_active'], array('0', '1'), true) ||
			!in_array($scalar['is_open'], array('0', '1'), true) ||
			$scalar['hashing'] !== '0' || $scalar['hashing_failed'] !== '0') {
			return(false);
		}
		foreach ($stringFields as $name => $unused) {
			if (!self::representable($scalar[$name])) {
				return(false);
			}
		}
		foreach ($integerFields as $name => $unused) {
			if (!self::canonicalInteger($scalar[$name])) {
				return(false);
			}
		}

		$reducer = new RetrackersCommandFragmentReducer('and=', $materialize, $limit);
		if (!$quiesced) {
			foreach (array('method.has_key=rr.receipts.v1,ea:' . $tx,
				'method.has_key=rr.receipts.v1,wa:' . $tx) as $condition) {
				if (!$reducer->push(self::literal($condition, $materialize, $limit))) {
					return(false);
				}
			}
		}
		foreach (array(
			self::stringEquals('d.custom=retrackers-recovery', $handoff, $materialize, $limit),
			self::stringEquals('d.custom=retrackers-recovery-ack', $handoff, $materialize, $limit),
		) as $condition) {
			if (!$reducer->push($condition)) {
				return(false);
			}
		}
		if (!self::pushGenericConditions($reducer, $snapshot['generic_map'], $materialize, $limit) ||
			!self::pushTrackerConditions($reducer, $snapshot['tracker_topology'],
				$snapshot['tracker_enabled'], $materialize, $limit) ||
			!$reducer->push(self::stringEquals(
				'd.local_id=', $scalar['local_id'], $materialize, $limit))) {
			return(false);
		}
		$lifecycle = $quiesced ? array('0', '0', '0', '0') : array(
			$scalar['state'], $scalar['is_active'], $scalar['is_open'], $scalar['hashing']);
		foreach (array('d.state=', 'd.is_active=', 'd.is_open=', 'd.hashing=') as $index => $getter) {
			if (!$reducer->push(self::integerEquals(
				$getter, $lifecycle[$index], $materialize, $limit))) {
				return(false);
			}
		}
		if (!$reducer->push(self::integerEquals(
			'd.hashing_failed=', '0', $materialize, $limit))) {
			return(false);
		}
		foreach ($stringFields as $name => $getter) {
			if (!$reducer->push(self::stringEquals(
				$getter, $scalar[$name], $materialize, $limit))) {
				return(false);
			}
		}
		foreach ($integerFields as $name => $getter) {
			if (!$reducer->push(self::integerEquals(
				$getter, $scalar[$name], $materialize, $limit))) {
				return(false);
			}
		}
		return($reducer->finish());
	}

	private static function callbackParts($tx, $hash, $handoff, array $snapshot,
		$materialize, $limit)
	{
		$inner = self::casConditions($tx, $handoff, $snapshot, true, $materialize, $limit);
		$success = self::concat(array(
			self::literal('cat=', $materialize, $limit),
			self::q('$d.erase=', $materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q('$method.set_key=rr.receipts.v1,ed:' . $tx . ',1', $materialize, $limit),
			self::literal(',RETRACKERS_ERASED', $materialize, $limit),
		), $materialize, $limit);
		$innerCall = self::concat(array(
			self::literal('$branch=', $materialize, $limit),
			self::q($inner, $materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q($success, $materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q('cat=RETRACKERS_QUIESCE_CHANGED', $materialize, $limit),
		), $materialize, $limit);
		if ($inner === false || $success === false || $innerCall === false) {
			return(false);
		}
		$commands = array(
			'$method.set_key=rr.receipts.v1,eb:' . $tx . ',1',
			'$method.set_key=rr.receipts.v1,ea:' . $tx,
		);
		if ($snapshot['scalar']['state'] === '1') {
			$commands[] = '$d.stop=';
		} elseif ($snapshot['scalar']['state'] !== '0') {
			return(false);
		}
		$commands[] = '$d.close=';
		$commands[] = $innerCall;
		$commands[] = '$method.set_key=rr.receipts.v1,ex:' . $tx . ',1';
		$bodyParts = array(self::literal('cat=', $materialize, $limit));
		foreach ($commands as $index => $command) {
			if ($index > 0) {
				$bodyParts[] = self::literal(',', $materialize, $limit);
			}
			$bodyParts[] = self::q($command, $materialize, $limit);
		}
		$body = self::concat($bodyParts, $materialize, $limit);
		unset($bodyParts, $commands, $innerCall);
		$outer = self::casConditions($tx, $handoff, $snapshot, false, $materialize, $limit);
		if ($body === false || $outer === false) {
			return(false);
		}
		return(array(
			self::literal($hash, $materialize, $limit),
			$outer,
			$body,
			self::literal('cat=RETRACKERS_SKIPPED', $materialize, $limit),
		));
	}

	private static function saturatedAdd($left, $right)
	{
		return($left >= PHP_INT_MAX || $right >= PHP_INT_MAX - $left ?
			PHP_INT_MAX : $left + $right);
	}

	private static function saturatedMultiply($value, $factor)
	{
		if ($value === 0 || $factor === 0) {
			return(0);
		}
		return($value >= PHP_INT_MAX || $factor > intdiv(PHP_INT_MAX, $value) ?
			PHP_INT_MAX : $value * $factor);
	}

	private static function completeWireEstimate($method, array $params, $limit)
	{
		$length = strlen('<?xml version="1.0" encoding="UTF-8"?><methodCall><methodName>');
		$method = self::literal($method, false, $limit);
		$length = self::saturatedAddAtLimit($length, $method->xmlLength(), $limit);
		$length = self::saturatedAddAtLimit(
			$length, strlen('</methodName><params>'), $limit);
		foreach ($params as $param) {
			$length = self::saturatedAddAtLimit(
				$length, strlen('<param><value><string>'), $limit);
			$length = self::saturatedAddAtLimit($length, $param->xmlLength(), $limit);
			$length = self::saturatedAddAtLimit(
				$length, strlen('</string></value></param>'), $limit);
		}
		return(self::saturatedAddAtLimit(
			$length, strlen('</params></methodCall>'), $limit));
	}

	private static function saturatedAddAtLimit($left, $right, $limit)
	{
		$ceiling = $limit === PHP_INT_MAX ? PHP_INT_MAX : $limit + 1;
		return($left >= $ceiling || $right >= $ceiling - $left ?
			$ceiling : $left + $right);
	}

	private static function memoryLimitBytes()
	{
		$value = ini_get('memory_limit');
		if ($value === '-1') {
			return(-1);
		}
		if (!is_string($value) || preg_match('/^(0|[1-9][0-9]*)([KMGkmg])?$/D',
			$value, $parts) !== 1) {
			return(false);
		}
		$bytes = 0;
		foreach (str_split($parts[1]) as $digit) {
			$bytes = self::saturatedAdd(self::saturatedMultiply($bytes, 10), (int)$digit);
		}
		$suffix = isset($parts[2]) ? strtoupper($parts[2]) : '';
		$power = array_search($suffix, array('', 'K', 'M', 'G'), true);
		if ($power === false) {
			return(false);
		}
		for ($index = 0; $index < $power; $index++) {
			$bytes = self::saturatedMultiply($bytes, 1024);
		}
		return($bytes > 0 ? $bytes : false);
	}

	public static function build($tx, $hash, $handoff, array $snapshot, $daemonCap,
		&$failure = null)
	{
		$failure = null;
		if (!is_int($daemonCap) || $daemonCap < 1) {
			$failure = 'commit-request-limit-invalid';
			return(false);
		}
		if (!is_string($tx) || preg_match('/^[0-9a-f]{32}$/D', $tx) !== 1 ||
			!is_string($hash) || preg_match('/^[0-9A-F]{40}$/D', $hash) !== 1) {
			$failure = 'runtime-value-unrepresentable';
			return(false);
		}
		$dry = self::callbackParts($tx, $hash, $handoff, $snapshot, false, $daemonCap);
		if ($dry === false) {
			$failure = 'runtime-value-unrepresentable';
			return(false);
		}
		$estimate = self::completeWireEstimate('branch', $dry, $daemonCap);
		unset($dry);
		if ($estimate > $daemonCap) {
			$failure = 'commit-request-too-large';
			return(false);
		}
		$memoryLimit = self::memoryLimitBytes();
		if ($memoryLimit === false) {
			$failure = 'commit-builder-headroom';
			return(false);
		}
		if ($memoryLimit !== -1) {
			$baseline = memory_get_usage(true);
			$required = self::saturatedAdd(
				self::saturatedMultiply($estimate, 12), 16 * 1024 * 1024);
			$available = $memoryLimit > $baseline ? $memoryLimit - $baseline : 0;
			if ($required > $available) {
				$failure = 'commit-builder-headroom';
				return(false);
			}
		}

		$materialized = self::callbackParts(
			$tx, $hash, $handoff, $snapshot, true, $daemonCap);
		if ($materialized === false) {
			$failure = 'commit-size-invariant';
			return(false);
		}
		$params = array();
		foreach ($materialized as $fragment) {
			$params[] = $fragment->takeText();
		}
		unset($materialized);
		foreach ($params as $param) {
			if (!is_string($param)) {
				$failure = 'commit-size-invariant';
				return(false);
			}
		}
		$wire = retrackersBuildDirectRequest('branch', $params);
		if (!is_string($wire) || strlen($wire) > $estimate || strlen($wire) > $daemonCap) {
			$failure = 'commit-size-invariant';
			return(false);
		}
		return(new self('branch', $params, $wire, $estimate));
	}

	public function method()
	{
		return($this->method);
	}

	public function params()
	{
		return($this->params);
	}

	public function wire()
	{
		return($this->wire);
	}

	public function estimatedWireBytes()
	{
		return($this->estimatedWireBytes);
	}

	public function responsePlan($family)
	{
		return(retrackersRestrictedPlanDirectScalar('string'));
	}
}

/** One sealed candidate/rollback wrapper; its checked bytes are sent unchanged. */
final class RetrackersLoadDispatchCallback
{
	private $method;
	private $params;
	private $wire;
	private $estimatedWireBytes;

	private function __construct(array $params, $wire)
	{
		$this->method = 'branch';
		$this->params = $params;
		$this->wire = $wire;
		$this->estimatedWireBytes = strlen($wire);
	}

	private static function literal($value, $materialize, $limit)
	{
		return(RetrackersCommandFragment::literal($value, $materialize, $limit));
	}

	private static function concat(array $parts, $materialize, $limit)
	{
		return(RetrackersCommandFragment::concat($parts, $materialize, $limit));
	}

	private static function q($value, $materialize, $limit)
	{
		$fragment = $value instanceof RetrackersCommandFragment ? $value :
			self::literal($value, $materialize, $limit);
		return($fragment === false ? false : $fragment->quoted($materialize));
	}

	private static function all(array $conditions, $materialize, $limit)
	{
		$condition = array_shift($conditions);
		foreach ($conditions as $next) {
			$condition = self::concat(array(
				self::literal('and=', $materialize, $limit),
				self::q($condition, $materialize, $limit),
				self::literal(',', $materialize, $limit),
				self::q($next, $materialize, $limit),
			), $materialize, $limit);
			if ($condition === false) {
				return(false);
			}
		}
		return($condition);
	}

	private static function callbackParts($tx, $phase, $capability, $loadMethod,
		array $creationCommands, $materialize, $limit)
	{
		$phases = array('la' => array('lb', 'lf'), 'ra' => array('rb', 'rf'));
		list($begin, $fence) = $phases[$phase];
		$conditionStrings = array(
			'method.has_key=rr.receipts.v1,wa:' . $tx,
			'method.has_key=rr.receipts.v1,' . $phase . ':' . $tx,
			'not=(method.has_key,rr.receipts.v1,' . $begin . ':' . $tx . ')',
			'not=(method.has_key,rr.receipts.v1,' . $fence . ':' . $tx . ')',
		);
		$conditions = array();
		foreach ($conditionStrings as $condition) {
			$conditions[] = self::literal($condition, $materialize, $limit);
		}
		$outer = self::all($conditions, $materialize, $limit);
		$fenceConditions = array();
		foreach (array(
			'method.has_key=rr.receipts.v1,wa:' . $tx,
			'method.has_key=rr.receipts.v1,' . $begin . ':' . $tx,
		) as $condition) {
			$fenceConditions[] = self::literal($condition, $materialize, $limit);
		}
		$fenceCondition = self::all($fenceConditions, $materialize, $limit);
		$fenceCallback = self::concat(array(
			self::literal('branch=', $materialize, $limit),
			self::q($fenceCondition, $materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q('method.set_key=rr.receipts.v1,' . $fence . ':' . $tx . ',1',
				$materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q('cat=', $materialize, $limit),
		), $materialize, $limit);
		$scheduleCommand = self::concat(array(
			self::literal('$schedule=', $materialize, $limit),
			self::q('rr-' . $fence . '-' . $tx, $materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q('1', $materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q('0', $materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q($fenceCallback, $materialize, $limit),
		), $materialize, $limit);
		$loadParts = array(
			self::literal('$' . $loadMethod . '=', $materialize, $limit),
			self::q($capability, $materialize, $limit),
		);
		foreach ($creationCommands as $command) {
			$loadParts[] = self::literal(',', $materialize, $limit);
			$loadParts[] = self::q($command, $materialize, $limit);
		}
		$loadCommand = self::concat($loadParts, $materialize, $limit);
		$body = self::concat(array(
			self::literal('cat=', $materialize, $limit),
			self::q($scheduleCommand, $materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q('$method.set_key=rr.receipts.v1,' . $begin . ':' . $tx . ',1',
				$materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q('$method.set_key=rr.receipts.v1,' . $phase . ':' . $tx,
				$materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q($loadCommand, $materialize, $limit),
			self::literal(',RETRACKERS_LOAD_DISPATCHED', $materialize, $limit),
		), $materialize, $limit);
		if ($outer === false || $fenceCallback === false || $scheduleCommand === false ||
			$loadCommand === false || $body === false) {
			return(false);
		}
		return(array(
			self::literal('', $materialize, $limit),
			$outer,
			$body,
			self::literal('cat=RETRACKERS_LOAD_CHANGED', $materialize, $limit),
		));
	}

	public static function build($tx, $phase, $capability, $loadMethod,
		array $creationCommands, $daemonCap, &$failure = null)
	{
		$failure = null;
		$phases = array(
			'la' => array('lb', 'lf'),
			'ra' => array('rb', 'rf'),
		);
		if (!isset($phases[$phase]) ||
			!is_string($tx) || preg_match('/^[0-9a-f]{32}$/D', $tx) !== 1 ||
			!is_string($capability) ||
			preg_match('#^/proc/[1-9][0-9]*/fd/[0-9]+$#D', $capability) !== 1 ||
			!in_array($loadMethod, array('load.normal', 'load.start'), true) ||
			!is_int($daemonCap) || $daemonCap < 1 || count($creationCommands) < 2) {
			$failure = 'runtime-value-unrepresentable';
			return(false);
		}
		if (retrackersResolveSchedulerCommand('schedule') !== 'schedule') {
			$failure = 'scheduler-command-unsupported';
			return(false);
		}
		foreach ($creationCommands as $command) {
			if (!is_string($command) || $command === '' || strpos($command, "\0") !== false ||
				strpos($command, "\r") !== false) {
				$failure = 'runtime-value-unrepresentable';
				return(false);
			}
		}
		$dry = self::callbackParts($tx, $phase, $capability, $loadMethod,
			$creationCommands, false, $daemonCap);
		if ($dry === false) {
			$failure = 'runtime-value-unrepresentable';
			return(false);
		}
		$estimate = RetrackersCommandFragment::directRequestLength(
			'branch', $dry, $daemonCap);
		unset($dry);
		if ($estimate === false || $estimate > $daemonCap) {
			$failure = 'load-request-too-large';
			return(false);
		}
		if (!RetrackersCommandFragment::hasMaterializationHeadroom($estimate)) {
			$failure = 'load-builder-headroom';
			return(false);
		}
		$materialized = self::callbackParts($tx, $phase, $capability, $loadMethod,
			$creationCommands, true, $daemonCap);
		if ($materialized === false) {
			$failure = 'load-size-invariant';
			return(false);
		}
		$params = array();
		foreach ($materialized as $fragment) {
			$params[] = $fragment->takeText();
		}
		unset($materialized);
		$wire = retrackersBuildDirectRequest('branch', $params);
		if (!is_string($wire) || strlen($wire) !== $estimate || strlen($wire) > $daemonCap) {
			$failure = 'load-size-invariant';
			return(false);
		}
		return(new self($params, $wire));
	}

	public function method()
	{
		return($this->method);
	}

	public function params()
	{
		return($this->params);
	}

	public function wire()
	{
		return($this->wire);
	}

	public function estimatedWireBytes()
	{
		return($this->estimatedWireBytes);
	}

	public function responsePlan($family)
	{
		return(retrackersRestrictedPlanDirectScalar('string'));
	}
}

/** Immutable creation half of one post-erase load obligation. */
final class RetrackersLoadObligation
{
	private $phase;
	private $capability;
	private $loadMethod;
	private $creationCommands;
	private $expectedTrackerState;
	private $validPrefixes;
	private $callback;
	private $cleanupPlans;

	public function __construct($phase, $capability, $loadMethod, array $creationCommands,
		array $expectedTrackerState, array $validPrefixes,
		RetrackersLoadDispatchCallback $callback, array $cleanupPlans = array())
	{
		$this->phase = $phase;
		$this->capability = $capability;
		$this->loadMethod = $loadMethod;
		$this->creationCommands = $creationCommands;
		$this->expectedTrackerState = $expectedTrackerState;
		$this->validPrefixes = $validPrefixes;
		$this->callback = $callback;
		$this->cleanupPlans = $cleanupPlans;
	}

	public function phase()
	{
		return($this->phase);
	}

	public function capability()
	{
		return($this->capability);
	}

	public function loadMethod()
	{
		return($this->loadMethod);
	}

	public function creationCommands()
	{
		return($this->creationCommands);
	}

	public function expectedTrackerState()
	{
		return($this->expectedTrackerState);
	}

	public function validPrefixes()
	{
		return($this->validPrefixes);
	}

	public function callback()
	{
		return($this->callback);
	}

	public function cleanupPlanForTuple(array $tuple)
	{
		foreach ($this->cleanupPlans as $plan) {
			if ($plan instanceof RetrackersCandidateCleanupPlan) {
				$bound = $plan->bindTuple($tuple);
				if ($bound !== false) {
					return($bound);
				}
			}
		}
		return(false);
	}

	public function hasCompleteCleanupPlans()
	{
		if (count($this->cleanupPlans) !== count($this->validPrefixes) ||
			count($this->cleanupPlans) === 0) {
			return(false);
		}
		foreach ($this->cleanupPlans as $index => $plan) {
			if (!($plan instanceof RetrackersCandidateCleanupPlan) ||
				!isset($this->validPrefixes[$index]) ||
				!$plan->matchesTuple($this->validPrefixes[$index]) ||
				!$plan->complete()) {
				return(false);
			}
		}
		return(true);
	}

	public function wire()
	{
		return($this->callback->wire());
	}

	public function estimatedWireBytes()
	{
		return($this->callback->estimatedWireBytes());
	}
}

/**
 * Frozen candidate and rollback creation plans. The transport wrappers are
 * sealed by the later Task 5 build gate before this object can authorize erase.
 */
final class RetrackersPostEraseObligation
{
	private $tx;
	private $hash;
	private $candidate;
	private $rollback;

	private function __construct($tx, $hash, RetrackersLoadObligation $candidate,
		RetrackersLoadObligation $rollback)
	{
		$this->tx = $tx;
		$this->hash = $hash;
		$this->candidate = $candidate;
		$this->rollback = $rollback;
	}

	private static function representable($value)
	{
		return(is_string($value) && ($value === '' || $value[0] !== '$') &&
			strpos($value, "\0") === false && strpos($value, "\r") === false);
	}

	private static function q($value)
	{
		return(self::representable($value) ? rTorrent::quoteCommandArg($value) : false);
	}

	private static function scalarCommands(array $scalar)
	{
		$strings = array(
			'directory_base' => 'd.directory_base.set=',
			'custom1' => 'd.custom1.set=',
			'custom2' => 'd.custom2.set=',
			'custom3' => 'd.custom3.set=',
			'custom4' => 'd.custom4.set=',
			'custom5' => 'd.custom5.set=',
			'throttle_name' => 'd.throttle_name.set=',
		);
		foreach (array_keys($strings) as $name) {
			if (!array_key_exists($name, $scalar) || !self::representable($scalar[$name])) {
				return(false);
			}
		}
		if (!isset($scalar['priority']) || !is_string($scalar['priority']) ||
			preg_match('/^(?:0|[1-9][0-9]*)$/D', $scalar['priority']) !== 1) {
			return(false);
		}
		$commands = array($strings['directory_base'] . self::q($scalar['directory_base']),
			'd.tied_to_file.set=' . self::q(''),
			'd.loaded_file.set=' . self::q(''));
		foreach (array('custom1', 'custom2', 'custom3', 'custom4', 'custom5') as $name) {
			$commands[] = $strings[$name] . self::q($scalar[$name]);
		}
		$commands[] = 'd.priority.set=' . self::q($scalar['priority']);
		$commands[] = $strings['throttle_name'] . self::q($scalar['throttle_name']);
		return($commands);
	}

	private static function sortedGenericPairs(array $pairs, array $scalar, &$failure)
	{
		if (!isset($scalar['recovery_marker'], $scalar['recovery_ack']) ||
			!is_string($scalar['recovery_marker']) || !is_string($scalar['recovery_ack'])) {
			$failure = 'runtime-value-unrepresentable';
			return(false);
		}
		$seen = array();
		$reservedFound = array();
		foreach ($pairs as $pair) {
			if (!is_array($pair) || array_keys($pair) !== array('name', 'value') ||
				!self::representable($pair['name']) || !self::representable($pair['value']) ||
				isset($seen["\0" . $pair['name']])) {
				$failure = 'runtime-value-unrepresentable';
				return(false);
			}
			$seen["\0" . $pair['name']] = true;
			if ($pair['name'] === 'retrackers-recovery' ||
				$pair['name'] === 'retrackers-recovery-ack') {
				$reservedFound[$pair['name']] = $pair['value'];
			}
		}
		if (count($reservedFound) !== 2 ||
			$reservedFound['retrackers-recovery'] !== $scalar['recovery_marker'] ||
			$reservedFound['retrackers-recovery-ack'] !== $scalar['recovery_ack']) {
			$failure = 'runtime-value-unrepresentable';
			return(false);
		}
		$copy = $pairs;
		usort($copy, function ($left, $right) {
			return(strcmp($left['name'], $right['name']));
		});
		return($copy);
	}

	private static function genericCommands(array $pairs, array $scalar, &$failure)
	{
		$copy = self::sortedGenericPairs($pairs, $scalar, $failure);
		if ($copy === false) {
			return(false);
		}
		$commands = array();
		foreach ($copy as $pair) {
			if ($pair['name'] !== 'retrackers-recovery' &&
				$pair['name'] !== 'retrackers-recovery-ack') {
				$commands[] = 'd.custom.set=' . self::q($pair['name']) . ',' .
					self::q($pair['value']);
			}
		}
		return($commands);
	}

	public static function nativeWorkerCommands(array $snapshot, &$failure)
	{
		$failure = 'runtime-value-unrepresentable';
		if (!isset($snapshot['scalar'], $snapshot['generic_map']) ||
			!is_array($snapshot['scalar']) || !is_array($snapshot['generic_map']) ||
			!isset($snapshot['scalar']['directory_base'])) {
			return(false);
		}
		$directory = self::q($snapshot['scalar']['directory_base']);
		$scalar = self::scalarCommands($snapshot['scalar']);
		$generic = self::genericCommands($snapshot['generic_map'],
			$snapshot['scalar'], $failure);
		if ($directory === false || $scalar === false || $generic === false) {
			return(false);
		}
		$failure = null;
		return(array_merge(array('d.directory.set=' . $directory), $scalar, $generic));
	}

	private static function trackerType($url)
	{
		if (preg_match('#^https?://#', $url) === 1) {
			return('1');
		}
		if (preg_match('#^udp://#', $url) === 1) {
			return('2');
		}
		return(false);
	}

	private static function sortTopology(array &$topology)
	{
		usort($topology, function ($left, $right) {
			$group = strlen($left['group']) - strlen($right['group']);
			if ($group !== 0) {
				return($group);
			}
			$group = strcmp($left['group'], $right['group']);
			if ($group !== 0) {
				return($group);
			}
			foreach (array('url', 'type', 'extra') as $field) {
				$comparison = strcmp($left[$field], $right[$field]);
				if ($comparison !== 0) {
					return($comparison);
				}
			}
			return(0);
		});
	}


	// The existing scanner supplies typed spans; eligibility never decodes the
	// full metainfo again or reserializes resume integers through PHP floats.
	private static function projectedMember($capture, array $entries, $name)
	{
		foreach ($entries as $entry) {
			if ($entry['key']['length'] === strlen($name) &&
				substr_compare($capture, $name, $entry['key']['offset'], strlen($name)) === 0) {
				return($entry['value']);
			}
		}
		return(null);
	}

	private static function projectedString($capture, $node)
	{
		return(is_array($node) && $node['type'] === 'string' &&
			$node['payload_length'] <= 1048576 ?
			substr($capture, $node['payload_offset'], $node['payload_length']) : false);
	}

	private static function metainfoTopology($announce, array $tiers, $strict)
	{
		if (array_keys($tiers) !== (count($tiers) ? range(0, count($tiers) - 1) : array())) {
			return(false);
		}
		$groups = count($tiers) ? $tiers : ($announce === null ? array() : array(array($announce)));
		$topology = array();
		$group = 0;
		foreach ($groups as $urls) {
			if (!is_array($urls) || array_keys($urls) !==
				(count($urls) ? range(0, count($urls) - 1) : array())) {
				return(false);
			}
			$before = count($topology);
			foreach ($urls as $url) {
				if (!is_string($url)) {
					return(false);
				}
				$normalized = trim($url, " \t\r\n\v\f");
				// Loader insert_url is case-sensitive. Unsupported source schemes
				// are ignored, but an ordinary DHT row has no reload-safe identity.
				$type = strpos($normalized, 'http://') === 0 ||
					strpos($normalized, 'https://') === 0 ? '1' :
					(strpos($normalized, 'udp://') === 0 ? '2' : false);
				if (strpos($normalized, 'dht://') === 0 ||
					($strict && ($type === false || $normalized !== $url)) ||
					!self::representable($normalized)) {
					return(false);
				}
				if ($type === false) {
					continue;
				}
				$topology[] = array('group' => (string)$group, 'url' => $normalized,
					'type' => $type, 'extra' => '0');
			}
			// Empty and completely filtered tiers do not advance size_group().
			if (count($topology) > $before) {
				$group++;
			}
		}
		self::sortTopology($topology);
		return($topology);
	}

	private static function sourceMetainfoTopology($capture, array $top)
	{
		$list = self::projectedMember($capture, $top, 'announce-list');
		$announceNode = self::projectedMember($capture, $top, 'announce');
		$announce = $announceNode === null ? null : self::projectedString($capture, $announceNode);
		$tiers = array();
		if ($list !== null && $list['type'] === 'list' &&
			isset($list['children']) && count($list['children'])) {
			$hasList = false;
			foreach ($list['children'] as $tier) {
				$hasList = $hasList || $tier['type'] === 'list';
			}
			if ($hasList) {
				foreach ($list['children'] as $tier) {
					if ($tier['type'] !== 'list' || !isset($tier['children'])) {
						return(false);
					}
					$urls = array();
					foreach ($tier['children'] as $entry) {
						$url = self::projectedString($capture, $entry);
						if ($url === false) {
							return(false);
						}
						$urls[] = $url;
					}
					$tiers[] = $urls;
				}
				// A valid announce-list is authoritative even if it has only
				// empty tiers; top-level announce is not an additional tracker.
				$announce = null;
			}
		}
		if ($announce === false) {
			return(false);
		}
		return(self::metainfoTopology($announce, $tiers, false));
	}

	private static function resumeTrackerEligibility($capture, array $top,
		array $source, array $candidate)
	{
		$resume = self::projectedMember($capture, $top, 'libtorrent_resume');
		if ($resume === null) {
			return(true);
		}
		if ($resume['type'] !== 'dictionary' || !isset($resume['children'])) {
			return(false);
		}
		$trackers = self::projectedMember($capture, $resume['children'], 'trackers');
		if ($trackers === null) {
			return(true);
		}
		if ($trackers['type'] !== 'dictionary' || !isset($trackers['children'])) {
			return(false);
		}
		$sourceUrls = array_column($source, 'url');
		$candidateUrls = array_column($candidate, 'url');
		foreach ($trackers['children'] as $entry) {
			$url = substr($capture, $entry['key']['offset'], $entry['key']['length']);
			if (!in_array($url, $sourceUrls, true) || !in_array($url, $candidateUrls, true) ||
				$entry['value']['type'] !== 'dictionary' || !isset($entry['value']['children'])) {
				return(false);
			}
			$values = array();
			foreach ($entry['value']['children'] as $field) {
				$name = substr($capture, $field['key']['offset'], $field['key']['length']);
				$node = $field['value'];
				if (!in_array($name, array('enabled', 'extra_tracker', 'group'), true) ||
					$node['type'] !== 'integer' || $node['length'] > 12) {
					return(false);
				}
				$value = substr($capture, $node['offset'] + 1, $node['length'] - 2);
				if ($name === 'group') {
					if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1 ||
						strlen($value) > 10 || (strlen($value) === 10 && strcmp($value, '2147483647') > 0)) {
						return(false);
					}
				} elseif (!in_array($value, array('0', '1'), true)) {
					return(false);
				}
				$values[$name] = $value;
			}
			if (isset($values['extra_tracker']) && $values['extra_tracker'] === '1' &&
				!isset($values['group'])) {
				return(false);
			}
		}
		return(true);
	}

	public static function sourceEligible(array $snapshot, $capture, array $scan,
		array $projection, &$failure = null)
	{
		$failure = 'runtime-tracker-topology-mismatch';
		if (!is_string($capture) || !isset($scan['ok'], $scan['top']) ||
			$scan['ok'] !== true || !is_array($scan['top']) ||
			!array_key_exists('announce', $projection) ||
			!array_key_exists('announce_list', $projection) ||
			!is_array($projection['announce_list'])) {
			return(false);
		}
		$readFailure = null;
		$runtime = self::sourceTrackerState($snapshot, $readFailure, true);
		$source = self::sourceMetainfoTopology($capture, $scan['top']);
		$candidate = self::metainfoTopology($projection['announce'], $projection['announce_list'], true);
		if ($runtime === false || $source === false || $candidate === false ||
			$source !== $runtime['topology'] ||
			!self::resumeTrackerEligibility($capture, $scan['top'], $source, $candidate)) {
			return(false);
		}
		$failure = null;
		return(true);
	}

	private static function sourceTrackerState(array $snapshot, &$failure, $initial = false)
	{
		if (!isset($snapshot['tracker_topology'], $snapshot['tracker_enabled']) ||
			!is_array($snapshot['tracker_topology']) || !is_array($snapshot['tracker_enabled'])) {
			$failure = 'candidate-tracker-projection-failed';
			return(false);
		}
		$states = array();
		foreach ($snapshot['tracker_enabled'] as $entry) {
			if (!is_array($entry) || array_keys($entry) !== array('url', 'multiplicity', 'state') ||
				!self::representable($entry['url']) || !is_int($entry['multiplicity']) ||
				$entry['multiplicity'] < 1 || !in_array($entry['state'], array('0', '1'), true) ||
				isset($states["\0" . $entry['url']])) {
				$failure = 'candidate-tracker-projection-failed';
				return(false);
			}
			$states["\0" . $entry['url']] = $entry;
		}
		$ordinary = array();
		$counts = array();
		$dht = false;
		foreach ($snapshot['tracker_topology'] as $row) {
			if (!is_array($row) || array_keys($row) !== array('group', 'url', 'type', 'extra') ||
				!self::representable($row['url']) ||
				preg_match('/^(?:0|[1-9][0-9]*)$/D', $row['group']) !== 1 ||
				preg_match('/^(?:0|[1-9][0-9]*)$/D', $row['type']) !== 1 ||
				!in_array($row['extra'], array('0', '1'), true)) {
				$failure = 'candidate-tracker-projection-failed';
				return(false);
			}
			$key = "\0" . $row['url'];
			$counts[$key] = isset($counts[$key]) ? $counts[$key] + 1 : 1;
			if ($row['url'] === 'dht://' && $initial) {
				if ($dht || $row['type'] !== '3' || $row['extra'] !== '0') {
					$failure = 'runtime-tracker-topology-mismatch';
					return(false);
				}
				$dht = true;
				continue;
			}
			$type = !$initial && strpos($row['url'], 'dht://') === 0 ? '3' :
				self::trackerType($row['url']);
			if ($type === false || $type !== $row['type'] || ($initial && $row['extra'] !== '0')) {
				$failure = 'candidate-tracker-projection-failed';
				return(false);
			}
			$ordinary[] = $row;
		}
		foreach ($states as $key => $entry) {
			if (!isset($counts[$key]) || $counts[$key] !== $entry['multiplicity']) {
				$failure = 'candidate-tracker-projection-failed';
				return(false);
			}
		}
		foreach ($counts as $key => $count) {
			if (!isset($states[$key]) || $states[$key]['multiplicity'] !== $count) {
				$failure = 'candidate-tracker-projection-failed';
				return(false);
			}
		}
		// Preserve runtime extras in post-event tuples. Only the single exact
		// synthetic DHT row is represented by the separate presence bit.
		if (!$initial && isset($counts["\0dht://"]) && $counts["\0dht://"] === 1) {
			foreach ($ordinary as $index => $row) {
				if ($row['url'] === 'dht://' && $row['type'] === '3' && $row['extra'] === '0') {
					$dht = true;
					unset($ordinary[$index]);
					$ordinary = array_values($ordinary);
					break;
				}
			}
		}
		self::sortTopology($ordinary);
		$enabled = array();
		foreach ($states as $entry) {
			if (!$dht || $entry['url'] !== 'dht://') {
				$enabled[] = $entry;
			}
		}
		usort($enabled, function ($left, $right) {
			return(strcmp($left['url'], $right['url']));
		});
		return(array('topology' => $ordinary, 'enabled' => $enabled,
			'dht_present' => $dht, 'state_by_url' => $states));
	}

	private static function candidateTrackerState(array $snapshot, array $projection,
		array $source, &$failure)
	{
		$groupCount = isset($projection['announce_list']) &&
			is_array($projection['announce_list']) ? count($projection['announce_list']) : -1;
		if (!array_key_exists('announce', $projection) ||
			!array_key_exists('announce_list', $projection) ||
			(!is_string($projection['announce']) && $projection['announce'] !== null) ||
			!is_array($projection['announce_list']) ||
			array_keys($projection['announce_list']) !==
				($groupCount > 0 ? range(0, $groupCount - 1) : array()) ||
			!isset($snapshot['family'], $snapshot['scalar']['trackers_use_udp']) ||
			!in_array($snapshot['family'], array(1, 2), true) ||
			!in_array($snapshot['scalar']['trackers_use_udp'], array('0', '1'), true)) {
			$failure = 'candidate-tracker-projection-failed';
			return(false);
		}
		$topology = self::metainfoTopology($projection['announce'],
			$projection['announce_list'], true);
		if ($topology === false) {
			$failure = 'candidate-tracker-projection-failed';
			return(false);
		}
		$counts = array();
		foreach ($topology as $row) {
			$key = "\0" . $row['url'];
			$counts[$key] = isset($counts[$key]) ? $counts[$key] + 1 : 1;
		}
		$enabled = array();
		foreach ($counts as $key => $multiplicity) {
			$url = substr($key, 1);
			if (isset($source['state_by_url'][$key])) {
				$state = $source['state_by_url'][$key]['state'];
			} elseif (self::trackerType($url) === '1') {
				$state = '1';
			} else {
				$state = $snapshot['family'] === 1 ?
					$snapshot['scalar']['trackers_use_udp'] : '1';
			}
			$enabled[] = array('url' => $url, 'multiplicity' => $multiplicity,
				'state' => $state);
		}
		self::sortTopology($topology);
		usort($enabled, function ($left, $right) {
			return(strcmp($left['url'], $right['url']));
		});
		return(array('topology' => $topology, 'enabled' => $enabled,
			'dht_present' => $source['dht_present']));
	}

	private static function trackerCommands(array $enabled, &$failure)
	{
		$copy = $enabled;
		usort($copy, function ($left, $right) {
			if (!is_array($left) || !isset($left['url']) ||
				!is_array($right) || !isset($right['url'])) {
				return(0);
			}
			return(strcmp($left['url'], $right['url']));
		});
		$commands = array();
		$last = null;
		foreach ($copy as $entry) {
			if (!is_array($entry) || array_keys($entry) !== array('url', 'multiplicity', 'state') ||
				!self::representable($entry['url']) || !is_int($entry['multiplicity']) ||
				$entry['multiplicity'] < 1 || !in_array($entry['state'], array('0', '1'), true) ||
				$last === $entry['url']) {
				$failure = 'candidate-tracker-projection-failed';
				return(false);
			}
			$last = $entry['url'];
			$match = 'equal=' . rTorrent::quoteCommandArg('t.url=') . ',' .
				rTorrent::quoteCommandArg('cat=' . self::q($entry['url']));
			$action = 'branch=' . rTorrent::quoteCommandArg($match) . ',' .
				rTorrent::quoteCommandArg($entry['state'] === '1' ? 't.enable=' : 't.disable=') . ',' .
				rTorrent::quoteCommandArg('cat=');
			$commands[] = 't.multicall=,' . rTorrent::quoteCommandArg($action);
		}
		return($commands);
	}

	private static function reduce($operator, array $conditions)
	{
		if (count($conditions) === 0) {
			return('cat=1');
		}
		while (count($conditions) > 1) {
			$next = array();
			$count = count($conditions);
			for ($index = 0; $index < $count; $index += 2) {
				if ($index + 1 === $count) {
					$next[] = $conditions[$index];
				} else {
					$next[] = $operator . self::q($conditions[$index]) . ',' .
						self::q($conditions[$index + 1]);
				}
			}
			$conditions = $next;
		}
		return($conditions[0]);
	}

	private static function stringEquals($getter, $value)
	{
		return('equal=' . self::q($getter) . ',' . self::q('cat=' . self::q($value)));
	}

	private static function integerEquals($getter, $value)
	{
		return('equal=' . self::q($getter) . ',' . self::q('value=' . $value));
	}

	private static function trackerMatch(array $row, $state = null)
	{
		$conditions = array(
			self::stringEquals('t.url=', $row['url']),
			self::integerEquals('t.group=', $row['group']),
			self::integerEquals('t.type=', $row['type']),
			self::integerEquals('t.is_extra_tracker=', $row['extra']),
		);
		if ($state !== null) {
			$conditions[] = self::integerEquals('t.is_enabled=', $state);
		}
		return(self::reduce('and=', $conditions));
	}

	private static function matchCount($match)
	{
		$project = 'branch=' . self::q($match) . ',' . self::q('cat=1') . ',' . self::q('cat=0');
		return('math.add=(t.multicall,,' . self::q($project) . ')');
	}

	private static function topologyAssertion(array $state)
	{
		$conditions = array();
		$total = count($state['topology']) + ($state['dht_present'] ? 1 : 0);
		// Empty t.multicall flattens to zero arithmetic arguments and faults.
		// The command-object emptiness check is the complete assertion here.
		if ($total === 0) {
			return('not=(t.multicall,,cat=1)');
		}
		$conditions[] = self::integerEquals(
			'math.cnt=(t.multicall,,cat=1)', (string)$total);
		$groups = array();
		foreach ($state['topology'] as $row) {
			$key = serialize($row);
			if (!isset($groups[$key])) {
				$groups[$key] = array('row' => $row, 'count' => 0);
			}
			$groups[$key]['count']++;
		}
		foreach ($groups as $group) {
			$conditions[] = self::integerEquals(
				self::matchCount(self::trackerMatch($group['row'])),
				(string)$group['count']);
		}
		$dhtMatch = self::stringEquals('t.url=', 'dht://');
		$conditions[] = self::integerEquals(self::matchCount($dhtMatch),
			$state['dht_present'] ? '1' : '0');
		$states = array();
		foreach ($state['enabled'] as $entry) {
			$states["\0" . $entry['url']] = $entry['state'];
		}
		$allowed = array();
		foreach ($state['topology'] as $row) {
			$allowed[] = self::trackerMatch($row, $states["\0" . $row['url']]);
		}
		if ($state['dht_present']) {
			$allowed[] = $dhtMatch;
		}
		$membershipBranch = 'branch=' . self::q(self::reduce('or=', $allowed)) . ',' .
			self::q('cat=1') . ',' . self::q('cat=0');
		$membership = self::integerEquals(
			'math.min=(t.multicall,,' . self::q($membershipBranch) . ')', '1');
		$conditions[] = $membership;
		return(self::reduce('and=', $conditions));
	}

	public static function trackerAssertionForTuple(array $tuple)
	{
		if (!isset($tuple['tracker_topology'], $tuple['tracker_enabled']) ||
			!array_key_exists('dht_present', $tuple) ||
			!is_array($tuple['tracker_topology']) ||
			!is_array($tuple['tracker_enabled']) || !is_bool($tuple['dht_present'])) {
			return(false);
		}
		return(self::topologyAssertion(array(
			'topology' => $tuple['tracker_topology'],
			'enabled' => $tuple['tracker_enabled'],
			'dht_present' => $tuple['dht_present'],
		)));
	}

	private static function creationCommands($tx, $kind, array $snapshot, array $trackerState,
		&$failure)
	{
		if (!isset($snapshot['scalar'], $snapshot['generic_map']) ||
			!is_array($snapshot['scalar']) || !is_array($snapshot['generic_map'])) {
			$failure = 'runtime-value-unrepresentable';
			return(false);
		}
		$generic = self::genericCommands(
			$snapshot['generic_map'], $snapshot['scalar'], $failure);
		$trackers = self::trackerCommands($trackerState['enabled'], $failure);
		$scalars = self::scalarCommands($snapshot['scalar']);
		if ($generic === false || $trackers === false || $scalars === false) {
			if ($failure === null) {
				$failure = 'runtime-value-unrepresentable';
			}
			return(false);
		}
		$claim = 'v1:' . $kind . '-claim:' . $tx;
		$ready = 'v1:' . $kind . '-ready:' . $tx;
		$commands = array(
			'd.custom.set=retrackers-recovery,' . self::q($claim),
			'd.custom.set=retrackers-recovery-ack,' . self::q(''),
		);
		foreach (array($generic, $trackers, $scalars) as $part) {
			foreach ($part as $command) {
				$commands[] = $command;
			}
		}
		$assertionKey = 'retrackers-recovery-assert-' . $tx;
		foreach ($snapshot['generic_map'] as $pair) {
			if (is_array($pair) && isset($pair['name']) && $pair['name'] === $assertionKey) {
				$failure = 'runtime-value-unrepresentable';
				return(false);
			}
		}
		$assertion = self::topologyAssertion($trackerState);
		$commands[] = 'branch=' . self::q($assertion) . ',' . self::q('cat=') . ',' .
			self::q('d.custom_throw=' . self::q($assertionKey));
		$commands[] = 'd.custom.set=retrackers-recovery,' . self::q($ready);
		return($commands);
	}

	private static function defaultTrackerEnabled(array $snapshot, array $trackerState,
		&$failure)
	{
		if (!isset($snapshot['family'], $snapshot['scalar']['trackers_use_udp']) ||
			!in_array($snapshot['family'], array(1, 2), true) ||
			!in_array($snapshot['scalar']['trackers_use_udp'], array('0', '1'), true)) {
			$failure = 'runtime-value-unrepresentable';
			return(false);
		}
		$enabled = $trackerState['enabled'];
		usort($enabled, function ($left, $right) {
			return(strcmp($left['url'], $right['url']));
		});
		foreach ($enabled as &$entry) {
			$type = self::trackerType($entry['url']);
			if ($type === false) {
				unset($entry);
				$failure = 'candidate-tracker-projection-failed';
				return(false);
			}
			$entry['state'] = $type === '1' || $snapshot['family'] === 2 ?
				'1' : $snapshot['scalar']['trackers_use_udp'];
		}
		unset($entry);
		return($enabled);
	}

	private static function appendGenericPair(array &$pairs, $name, $value)
	{
		$pairs[] = array('name' => $name, 'value' => $value);
		usort($pairs, function ($left, $right) {
			return(strcmp($left['name'], $right['name']));
		});
	}

	private static function creationPrefixes($tx, $hash, $kind, $capability,
		$loadMethod, array $snapshot, array $trackerState, array $commands, &$failure)
	{
		$required = array('directory_default', 'state', 'is_active', 'is_open');
		foreach ($required as $name) {
			if (!isset($snapshot['scalar'][$name]) ||
				!self::representable($snapshot['scalar'][$name])) {
				$failure = 'runtime-value-unrepresentable';
				return(false);
			}
		}
		if (!in_array($snapshot['scalar']['state'], array('0', '1'), true) ||
			!in_array($snapshot['scalar']['is_active'], array('0', '1'), true) ||
			!in_array($snapshot['scalar']['is_open'], array('0', '1'), true) ||
			($loadMethod === 'load.start') !== ($snapshot['scalar']['state'] === '1')) {
			$failure = 'runtime-value-unrepresentable';
			return(false);
		}
		$generic = self::sortedGenericPairs(
			$snapshot['generic_map'], $snapshot['scalar'], $failure);
		$enabled = self::defaultTrackerEnabled($snapshot, $trackerState, $failure);
		if ($generic === false || $enabled === false) {
			return(false);
		}
		$ordinaryGeneric = array_values(array_filter($generic, function ($pair) {
			return($pair['name'] !== 'retrackers-recovery' &&
				$pair['name'] !== 'retrackers-recovery-ack');
		}));
		$targetEnabled = $trackerState['enabled'];
		usort($targetEnabled, function ($left, $right) {
			return(strcmp($left['url'], $right['url']));
		});
		$claim = 'v1:' . $kind . '-claim:' . $tx;
		$tuple = array(
			'scalar' => array(
				// Fixed-width template slot; the daemon allocates the actual ID after load.
				'local_id' => $hash,
				'recovery_marker' => '',
				'recovery_ack' => '',
				'state' => $snapshot['scalar']['state'],
				// Both daemon families leave a creation-command failure closed
				// and inactive; it cannot inherit the old object's open handles.
				'is_active' => '0',
				'is_open' => '0',
				'hashing' => '0',
				'hashing_failed' => '1',
				'directory_base' => $snapshot['scalar']['directory_default'],
				'tied_source' => $capability,
				'loaded_file' => $capability,
				'custom1' => '', 'custom2' => '', 'custom3' => '',
				'custom4' => '', 'custom5' => '',
				'priority' => '2',
				'throttle_name' => '',
			),
			'generic_map' => array(),
			'tracker_topology' => $trackerState['topology'],
			'tracker_enabled' => $enabled,
			'dht_present' => $trackerState['dht_present'],
		);
		$prefixes = array();
		$tuple['scalar']['recovery_marker'] = $claim;
		self::appendGenericPair(
			$tuple['generic_map'], 'retrackers-recovery', $claim);
		$prefixes[] = $tuple;
		self::appendGenericPair(
			$tuple['generic_map'], 'retrackers-recovery-ack', '');
		$prefixes[] = $tuple;
		foreach ($ordinaryGeneric as $pair) {
			self::appendGenericPair($tuple['generic_map'], $pair['name'], $pair['value']);
			$prefixes[] = $tuple;
		}
		foreach ($targetEnabled as $target) {
			$found = false;
			foreach ($tuple['tracker_enabled'] as &$entry) {
				if ($entry['url'] === $target['url']) {
					$entry['state'] = $target['state'];
					$found = true;
					break;
				}
			}
			unset($entry);
			if (!$found) {
				$failure = 'candidate-tracker-projection-failed';
				return(false);
			}
			$prefixes[] = $tuple;
		}
		$scalarUpdates = array(
			'directory_base' => $snapshot['scalar']['directory_base'],
			'tied_source' => '',
			'loaded_file' => '',
			'custom1' => $snapshot['scalar']['custom1'],
			'custom2' => $snapshot['scalar']['custom2'],
			'custom3' => $snapshot['scalar']['custom3'],
			'custom4' => $snapshot['scalar']['custom4'],
			'custom5' => $snapshot['scalar']['custom5'],
			'priority' => $snapshot['scalar']['priority'],
			'throttle_name' => $snapshot['scalar']['throttle_name'],
		);
		foreach ($scalarUpdates as $name => $value) {
			$tuple['scalar'][$name] = $value;
			$prefixes[] = $tuple;
		}
		// The penultimate assertion has no successful-state mutation of its own.
		$prefixes[] = $tuple;
		if (count($prefixes) !== count($commands) - 1) {
			$failure = 'runtime-value-unrepresentable';
			return(false);
		}
		return($prefixes);
	}

	public static function capturedTuple(array $snapshot)
	{
		if (!isset($snapshot['scalar'], $snapshot['generic_map'],
			$snapshot['tracker_topology'], $snapshot['tracker_enabled']) ||
			!is_array($snapshot['scalar']) || !is_array($snapshot['generic_map']) ||
			!is_array($snapshot['tracker_topology']) ||
			!is_array($snapshot['tracker_enabled'])) {
			return(false);
		}
		$scalarNames = array(
			'local_id', 'recovery_marker', 'recovery_ack', 'state', 'is_active',
			'is_open', 'hashing', 'hashing_failed', 'directory_base', 'tied_source',
			'loaded_file', 'custom1', 'custom2', 'custom3', 'custom4', 'custom5',
			'priority', 'throttle_name',
		);
		$scalar = array();
		foreach ($scalarNames as $name) {
			if (!array_key_exists($name, $snapshot['scalar']) ||
				!is_string($snapshot['scalar'][$name])) {
				return(false);
			}
			$scalar[$name] = $snapshot['scalar'][$name];
		}
		if (preg_match('/^[0-9A-F]{40}$/D', $scalar['local_id']) !== 1 ||
			!in_array($scalar['state'], array('0', '1'), true) ||
			!in_array($scalar['is_active'], array('0', '1'), true) ||
			!in_array($scalar['is_open'], array('0', '1'), true) ||
			!in_array($scalar['hashing'], array('0', '1', '2', '3'), true) ||
			preg_match('/^(?:0|-[1-9][0-9]*|[1-9][0-9]*)$/D',
				$scalar['hashing_failed']) !== 1 ||
			!retrackersRestrictedIntegerInRange($scalar['hashing_failed'], 'i8') ||
			preg_match('/^(?:0|[1-9][0-9]*)$/D', $scalar['priority']) !== 1) {
			return(false);
		}
		$generic = array();
		$seen = array();
		foreach ($snapshot['generic_map'] as $pair) {
			if (!is_array($pair) || array_keys($pair) !== array('name', 'value') ||
				!is_string($pair['name']) || !is_string($pair['value']) ||
				isset($seen["\0" . $pair['name']])) {
				return(false);
			}
			$seen["\0" . $pair['name']] = true;
			$generic[] = $pair;
		}
		usort($generic, function ($left, $right) {
			return(strcmp($left['name'], $right['name']));
		});
		$trackerFailure = null;
		$state = self::sourceTrackerState($snapshot, $trackerFailure);
		if ($state === false) {
			return(false);
		}
		$dhtPresent = $state['dht_present'];
		if (array_key_exists('dht_present', $snapshot)) {
			if (!is_bool($snapshot['dht_present']) || $dhtPresent) {
				return(false);
			}
			$dhtPresent = $snapshot['dht_present'];
		}
		return(array(
			'scalar' => $scalar,
			'generic_map' => $generic,
			'tracker_topology' => $state['topology'],
			'tracker_enabled' => $state['enabled'],
			'dht_present' => $dhtPresent,
		));
	}

	public static function build($tx, $hash, RetrackersAnonymousStage $stage,
		array $snapshot, array $projection, $daemonCap, &$failure = null)
	{
		$failure = null;
		if (!is_string($tx) || preg_match('/^[0-9a-f]{32}$/D', $tx) !== 1 ||
			!is_string($hash) || preg_match('/^[0-9A-F]{40}$/D', $hash) !== 1 ||
			!is_int($daemonCap) || $daemonCap < 1 ||
			!isset($snapshot['scalar']['state'], $snapshot['tracker_enabled']) ||
			!in_array($snapshot['scalar']['state'], array('0', '1'), true) ||
			!is_array($snapshot['tracker_enabled']) ||
			!isset($projection['ok'], $projection['changed']) ||
			$projection['ok'] !== true || $projection['changed'] !== true) {
			$failure = 'runtime-value-unrepresentable';
			return(false);
		}
		$sourceTrackers = self::sourceTrackerState($snapshot, $failure, true);
		if ($sourceTrackers === false) {
			return(false);
		}
		$candidateTrackers = self::candidateTrackerState(
			$snapshot, $projection, $sourceTrackers, $failure);
		if ($candidateTrackers === false) {
			return(false);
		}
		$rollbackTrackers = $sourceTrackers;
		unset($rollbackTrackers['state_by_url']);
		$candidateProof = $stage->candidate();
		$rollbackProof = $stage->original();
		if (!is_array($candidateProof) || !is_array($rollbackProof) ||
			!isset($candidateProof['capability'], $rollbackProof['capability']) ||
			!is_string($candidateProof['capability']) ||
			!is_string($rollbackProof['capability']) ||
			$candidateProof['capability'] === $rollbackProof['capability']) {
			$failure = 'runtime-value-unrepresentable';
			return(false);
		}
		$candidateCommands = self::creationCommands(
			$tx, 'candidate', $snapshot, $candidateTrackers, $failure);
		$rollbackCommands = self::creationCommands(
			$tx, 'rollback', $snapshot, $rollbackTrackers, $failure);
		if ($candidateCommands === false || $rollbackCommands === false) {
			return(false);
		}
		$loadMethod = $snapshot['scalar']['state'] === '1' ? 'load.start' : 'load.normal';
		$candidateCallback = RetrackersLoadDispatchCallback::build(
			$tx, 'la', $candidateProof['capability'], $loadMethod,
			$candidateCommands, $daemonCap, $failure);
		if ($candidateCallback === false) {
			return(false);
		}
		$rollbackCallback = RetrackersLoadDispatchCallback::build(
			$tx, 'ra', $rollbackProof['capability'], $loadMethod,
			$rollbackCommands, $daemonCap, $failure);
		if ($rollbackCallback === false) {
			return(false);
		}
		if (!RetrackersCommandFragment::hasPrefixMaterializationHeadroom(
			count($candidateCommands) - 1, count($rollbackCommands) - 1)) {
			$failure = 'prefix-builder-headroom';
			return(false);
		}
		$candidatePrefixes = self::creationPrefixes($tx, $hash, 'candidate',
			$candidateProof['capability'], $loadMethod, $snapshot,
			$candidateTrackers, $candidateCommands, $failure);
		$rollbackPrefixes = self::creationPrefixes($tx, $hash, 'rollback',
			$rollbackProof['capability'], $loadMethod, $snapshot,
			$rollbackTrackers, $rollbackCommands, $failure);
		if ($candidatePrefixes === false || $rollbackPrefixes === false) {
			return(false);
		}
		$cleanupMeasurements = array();
		foreach ($candidatePrefixes as $prefix) {
			$estimate = RetrackersCandidateCleanupCallback::measure(
				$tx, $hash, $prefix, $daemonCap, $failure);
			if ($estimate === false) {
				return(false);
			}
			$cleanupMeasurements[] = $estimate;
		}
		// Every eligible prefix must retain its exact post-erase request. Reserve
		// all stored wires plus the largest construction transient before building
		// any of them, while the original generation is still untouched.
		if (!RetrackersCommandFragment::hasSealedCallbackSetHeadroom(
			$cleanupMeasurements)) {
			$failure = 'cleanup-builder-headroom';
			return(false);
		}
		$cleanupPlans = array();
		foreach ($candidatePrefixes as $index => $prefix) {
			$cleanupPlan = RetrackersCandidateCleanupPlan::sealMeasured(
				$tx, $hash, $prefix, $daemonCap,
				$cleanupMeasurements[$index], $failure);
			if ($cleanupPlan === false) {
				return(false);
			}
			$cleanupPlans[] = $cleanupPlan;
		}
		unset($cleanupMeasurements);
		return(new self($tx, $hash,
			new RetrackersLoadObligation('la', $candidateProof['capability'],
				$loadMethod, $candidateCommands, $candidateTrackers,
				$candidatePrefixes, $candidateCallback, $cleanupPlans),
			new RetrackersLoadObligation('ra', $rollbackProof['capability'],
				$loadMethod, $rollbackCommands, $rollbackTrackers,
				$rollbackPrefixes, $rollbackCallback)));
	}

	public function tx()
	{
		return($this->tx);
	}

	public function hash()
	{
		return($this->hash);
	}

	public function candidate()
	{
		return($this->candidate);
	}

	public function rollback()
	{
		return($this->rollback);
	}

	public function complete()
	{
		return($this->candidate->callback() instanceof RetrackersLoadDispatchCallback &&
			$this->rollback->callback() instanceof RetrackersLoadDispatchCallback &&
			$this->candidate->hasCompleteCleanupPlans() &&
			count($this->candidate->validPrefixes()) > 0 &&
			count($this->rollback->validPrefixes()) > 0 &&
			$this->candidate->wire() !== '' && $this->rollback->wire() !== '');
	}
}

/** Exact pre-erase callback ownership for one finite candidate-cleanup prefix. */
final class RetrackersCandidateCleanupPlan
{
	private $tx;
	private $hash;
	private $tuple;
	private $callback;

	private function __construct($tx, $hash, array $tuple,
		RetrackersCandidateCleanupCallback $callback)
	{
		$this->tx = $tx;
		$this->hash = $hash;
		$this->tuple = $tuple;
		$this->callback = $callback;
	}

	public static function seal($tx, $hash, array $tuple, $daemonCap, &$failure = null)
	{
		$estimate = RetrackersCandidateCleanupCallback::measure(
			$tx, $hash, $tuple, $daemonCap, $failure);
		if ($estimate === false) {
			return(false);
		}
		if (!RetrackersCommandFragment::hasSingleOwnerMaterializationHeadroom(
			$estimate)) {
			$failure = 'cleanup-builder-headroom';
			return(false);
		}
		return(self::sealMeasured(
			$tx, $hash, $tuple, $daemonCap, $estimate, $failure));
	}

	public static function sealMeasured($tx, $hash, array $tuple, $daemonCap,
		$estimate, &$failure = null)
	{
		$callback = RetrackersCandidateCleanupCallback::materializeSealed(
			$tx, $hash, $tuple, $daemonCap, $estimate, $failure);
		return($callback === false ? false :
			new self($tx, $hash, $tuple, $callback));
	}

	public function matches($tx, $hash, array $tuple)
	{
		return($this->tx === $tx && $this->hash === $hash && $this->tuple === $tuple);
	}

	public function matchesTuple(array $tuple)
	{
		return($this->tuple === $tuple);
	}

	public function bindTuple(array $tuple)
	{
		if (!isset($tuple['scalar']['local_id']) || !is_string($tuple['scalar']['local_id']) ||
			preg_match('/^[0-9A-F]{40}$/D', $tuple['scalar']['local_id']) !== 1) {
			return(false);
		}
		$normalized = $tuple;
		$normalized['scalar']['local_id'] = $this->tuple['scalar']['local_id'];
		if ($normalized !== $this->tuple) {
			return(false);
		}
		if ($tuple === $this->tuple) {
			return($this);
		}
		$callback = $this->callback->bindLocalId($tuple['scalar']['local_id']);
		return($callback === false ? false : new self($this->tx, $this->hash, $tuple, $callback));
	}

	public function estimatedWireBytes()
	{
		return($this->callback->estimatedWireBytes());
	}

	public function callback()
	{
		return($this->callback);
	}

	public function materialize(&$failure = null)
	{
		$failure = null;
		return($this->callback);
	}

	public function complete()
	{
		$wire = $this->callback->wire();
		return(is_string($wire) && $wire !== '' &&
			strlen($wire) === $this->callback->estimatedWireBytes());
	}
}

/** One-shot, exact-size CAS cleanup of one transaction-owned partial candidate. */
final class RetrackersCandidateCleanupCallback
{
	private $method;
	private $wire;
	private $estimatedWireBytes;
	private $localId;
	private $localIdOffsets;

	private function __construct($wire, $estimatedWireBytes, $localId, array $localIdOffsets)
	{
		$this->method = 'branch';
		$this->wire = $wire;
		$this->estimatedWireBytes = $estimatedWireBytes;
		$this->localId = $localId;
		$this->localIdOffsets = $localIdOffsets;
	}

	private static function literal($value, $materialize, $limit)
	{
		return(RetrackersCommandFragment::literal($value, $materialize, $limit));
	}

	private static function concat(array $parts, $materialize, $limit)
	{
		foreach ($parts as $part) {
			if ($part === false) {
				return(false);
			}
		}
		return(RetrackersCommandFragment::concat($parts, $materialize, $limit));
	}

	private static function q($value, $materialize, $limit)
	{
		$fragment = $value instanceof RetrackersCommandFragment ?
			$value : self::literal($value, $materialize, $limit);
		return($fragment === false ? false : $fragment->quoted($materialize));
	}

	private static function stringEquals($getter, $value, $materialize, $limit)
	{
		$value = self::concat(array(
			self::literal('cat=', $materialize, $limit),
			self::q($value, $materialize, $limit),
		), $materialize, $limit);
		return(self::concat(array(
			self::literal('equal=', $materialize, $limit),
			self::q($getter, $materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q($value, $materialize, $limit),
		), $materialize, $limit));
	}

	private static function integerEquals($getter, $value, $materialize, $limit)
	{
		$value = self::concat(array(
			self::literal('value=', $materialize, $limit),
			self::literal($value, $materialize, $limit),
		), $materialize, $limit);
		return(self::concat(array(
			self::literal('equal=', $materialize, $limit),
			self::q($getter, $materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q($value, $materialize, $limit),
		), $materialize, $limit));
	}

	private static function trackerMatch(array $row, $state, $materialize, $limit)
	{
		$reducer = new RetrackersCommandFragmentReducer('and=', $materialize, $limit);
		foreach (array(
			self::stringEquals('t.url=', $row['url'], $materialize, $limit),
			self::integerEquals('t.group=', $row['group'], $materialize, $limit),
			self::integerEquals('t.type=', $row['type'], $materialize, $limit),
			self::integerEquals('t.is_extra_tracker=', $row['extra'], $materialize, $limit),
		) as $fragment) {
			if (!$reducer->push($fragment)) {
				return(false);
			}
		}
		if ($state !== null && !$reducer->push(self::integerEquals(
			't.is_enabled=', $state, $materialize, $limit))) {
			return(false);
		}
		return($reducer->finish());
	}

	private static function matchCount($match, $materialize, $limit)
	{
		$project = self::concat(array(
			self::literal('branch=', $materialize, $limit),
			self::q($match, $materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q('cat=1', $materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q('cat=0', $materialize, $limit),
		), $materialize, $limit);
		return(self::concat(array(
			self::literal('math.add=(t.multicall,,', $materialize, $limit),
			self::q($project, $materialize, $limit),
			self::literal(')', $materialize, $limit),
		), $materialize, $limit));
	}

	private static function trackerAssertion(array $tuple, $materialize, $limit)
	{
		$reducer = new RetrackersCommandFragmentReducer('and=', $materialize, $limit);
		$total = count($tuple['tracker_topology']) + ($tuple['dht_present'] ? 1 : 0);
		if ($total === 0) {
			return(self::literal('not=(t.multicall,,cat=1)', $materialize, $limit));
		}
		if (!$reducer->push(self::integerEquals(
			'math.cnt=(t.multicall,,cat=1)', (string)$total, $materialize, $limit))) {
			return(false);
		}
		$states = array();
		foreach ($tuple['tracker_enabled'] as $entry) {
			$states["\0" . $entry['url']] = $entry['state'];
		}
		$groups = array();
		foreach ($tuple['tracker_topology'] as $row) {
			$key = serialize($row);
			if (!isset($groups[$key])) {
				$stateKey = "\0" . $row['url'];
				if (!isset($states[$stateKey])) {
					return(false);
				}
				$groups[$key] = array('row' => $row,
					'state' => $states[$stateKey], 'count' => 0);
			}
			$groups[$key]['count']++;
		}
		foreach ($groups as $group) {
			$count = self::matchCount(self::trackerMatch(
				$group['row'], $group['state'], $materialize, $limit),
				$materialize, $limit);
			if ($count === false || !$reducer->push(self::integerEquals(
				$count, (string)$group['count'], $materialize, $limit))) {
				return(false);
			}
		}
		unset($groups);
		$dht = self::stringEquals('t.url=', 'dht://', $materialize, $limit);
		$dhtCount = self::matchCount($dht, $materialize, $limit);
		if ($dht === false || $dhtCount === false || !$reducer->push(self::integerEquals(
			$dhtCount, $tuple['dht_present'] ? '1' : '0', $materialize, $limit))) {
			return(false);
		}
		return($reducer->finish());
	}

	private static function conditions($tx, array $tuple, $quiesced,
		$materialize, $limit)
	{
		$scalar = $tuple['scalar'];
		$reducer = new RetrackersCommandFragmentReducer('and=', $materialize, $limit);
		if (!$quiesced) {
			foreach (array('method.has_key=rr.receipts.v1,wa:' . $tx,
				'method.has_key=rr.receipts.v1,ca:' . $tx) as $condition) {
				if (!$reducer->push(self::literal($condition, $materialize, $limit))) {
					return(false);
				}
			}
		}
		$stringFields = array(
			'local_id' => 'd.local_id=',
			'recovery_marker' => 'd.custom=retrackers-recovery',
			'recovery_ack' => 'd.custom=retrackers-recovery-ack',
			'directory_base' => 'd.directory_base=',
			'tied_source' => 'd.tied_to_file=',
			'loaded_file' => 'd.loaded_file=',
			'custom1' => 'd.custom1=', 'custom2' => 'd.custom2=',
			'custom3' => 'd.custom3=', 'custom4' => 'd.custom4=',
			'custom5' => 'd.custom5=', 'throttle_name' => 'd.throttle_name=',
		);
		foreach ($stringFields as $name => $getter) {
			if (!$reducer->push(self::stringEquals(
				$getter, $scalar[$name], $materialize, $limit))) {
				return(false);
			}
		}
		$lifecycle = $quiesced ? array(
			'state' => '0', 'is_active' => '0', 'is_open' => '0', 'hashing' => '0') :
			array('state' => $scalar['state'], 'is_active' => $scalar['is_active'],
				'is_open' => $scalar['is_open'], 'hashing' => $scalar['hashing']);
		foreach ($lifecycle as $name => $value) {
			if (!$reducer->push(self::integerEquals(
				'd.' . $name . '=', $value, $materialize, $limit))) {
				return(false);
			}
		}
		foreach (array('hashing_failed', 'priority') as $name) {
			if (!$reducer->push(self::integerEquals(
				'd.' . $name . '=', $scalar[$name], $materialize, $limit))) {
				return(false);
			}
		}
		if (!$reducer->push(self::integerEquals('math.cnt=(d.custom.keys)',
			(string)count($tuple['generic_map']), $materialize, $limit))) {
			return(false);
		}
		foreach ($tuple['generic_map'] as $pair) {
			$getter = self::concat(array(
				self::literal('d.custom_throw=', $materialize, $limit),
				self::q($pair['name'], $materialize, $limit),
			), $materialize, $limit);
			if ($getter === false || !$reducer->push(self::stringEquals(
				$getter, $pair['value'], $materialize, $limit))) {
				return(false);
			}
		}
		$tracker = self::trackerAssertion($tuple, $materialize, $limit);
		return($tracker !== false && $reducer->push($tracker) ? $reducer->finish() : false);
	}

	private static function validInput($tx, $hash, array $tuple, $daemonCap)
	{
		$canonical = RetrackersPostEraseObligation::capturedTuple($tuple);
		return(is_string($tx) && preg_match('/^[0-9a-f]{32}$/D', $tx) === 1 &&
			is_string($hash) && preg_match('/^[0-9A-F]{40}$/D', $hash) === 1 &&
			is_int($daemonCap) && $daemonCap > 0 && $canonical !== false &&
			$canonical === $tuple &&
			$tuple['scalar']['recovery_marker'] === 'v1:candidate-claim:' . $tx &&
			$tuple['scalar']['recovery_ack'] === '' &&
			$tuple['scalar']['hashing'] === '0' &&
			$tuple['scalar']['hashing_failed'] === '1');
	}

	private static function callbackParts($tx, $hash, array $tuple,
		$materialize, $limit)
	{
		$outer = self::conditions($tx, $tuple, false, $materialize, $limit);
		$inner = self::conditions($tx, $tuple, true, $materialize, $limit);
		$success = self::concat(array(
			self::literal('cat=', $materialize, $limit),
			self::q('$d.erase=', $materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q('$method.set_key=rr.receipts.v1,cd:' . $tx . ',1',
				$materialize, $limit),
			self::literal(',RETRACKERS_CANDIDATE_ERASED', $materialize, $limit),
		), $materialize, $limit);
		$innerCall = self::concat(array(
			self::literal('$branch=', $materialize, $limit),
			self::q($inner, $materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q($success, $materialize, $limit),
			self::literal(',', $materialize, $limit),
			self::q('cat=RETRACKERS_CANDIDATE_QUIESCE_CHANGED',
				$materialize, $limit),
		), $materialize, $limit);
		$commands = array(
			self::literal('$method.set_key=rr.receipts.v1,cb:' . $tx . ',1',
				$materialize, $limit),
			self::literal('$method.set_key=rr.receipts.v1,ca:' . $tx,
				$materialize, $limit),
		);
		if ($tuple['scalar']['state'] === '1') {
			$commands[] = self::literal('$d.stop=', $materialize, $limit);
		}
		$commands[] = self::literal('$d.close=', $materialize, $limit);
		$commands[] = $innerCall;
		$commands[] = self::literal('$method.set_key=rr.receipts.v1,cx:' . $tx . ',1',
			$materialize, $limit);
		$bodyParts = array(self::literal('cat=', $materialize, $limit));
		foreach ($commands as $index => $command) {
			if ($index > 0) {
				$bodyParts[] = self::literal(',', $materialize, $limit);
			}
			$bodyParts[] = self::q($command, $materialize, $limit);
		}
		$body = self::concat($bodyParts, $materialize, $limit);
		if ($outer === false || $inner === false || $success === false ||
			$innerCall === false || $body === false) {
			return(false);
		}
		return(array(
			self::literal($hash, $materialize, $limit),
			$outer,
			$body,
			self::literal('cat=RETRACKERS_CANDIDATE_SKIPPED', $materialize, $limit),
		));
	}

	public static function measure($tx, $hash, array $tuple, $daemonCap, &$failure = null)
	{
		$failure = null;
		if (!self::validInput($tx, $hash, $tuple, $daemonCap)) {
			$failure = 'runtime-value-unrepresentable';
			return(false);
		}
		$parts = self::callbackParts($tx, $hash, $tuple, false, $daemonCap);
		if ($parts === false) {
			$failure = 'runtime-value-unrepresentable';
			return(false);
		}
		$estimate = RetrackersCommandFragment::directRequestLength(
			'branch', $parts, $daemonCap);
		unset($parts);
		if ($estimate === false || $estimate > $daemonCap) {
			$failure = 'cleanup-request-too-large';
			return(false);
		}
		return($estimate);
	}

	public static function materializeSealed($tx, $hash, array $tuple, $daemonCap,
		$estimate, &$failure = null)
	{
		$failure = null;
		if (!self::validInput($tx, $hash, $tuple, $daemonCap) ||
			!is_int($estimate) || $estimate < 1 || $estimate > $daemonCap) {
			$failure = 'cleanup-size-invariant';
			return(false);
		}
		$wire = self::wireForTuple($tx, $hash, $tuple, $daemonCap);
		if (!is_string($wire) || strlen($wire) !== $estimate) {
			$failure = 'cleanup-size-invariant';
			return(false);
		}
		// Compile the two fixed-width CAS slots before erase. A differential render
		// changes only local_id, so identical text in user metadata or the hash target
		// cannot accidentally become a substitution site. No render occurs after erase.
		$alternate = $tuple;
		$localId = $tuple['scalar']['local_id'];
		$alternate['scalar']['local_id'] = strtr($localId, '0123456789ABCDEF', '123456789ABCDEF0');
		$alternateWire = self::wireForTuple($tx, $hash, $alternate, $daemonCap);
		if (!is_string($alternateWire) || strlen($alternateWire) !== $estimate) {
			$failure = 'cleanup-size-invariant';
			return(false);
		}
		$offsets = array();
		for ($offset = 0; $offset < $estimate; $offset++) {
			if ($wire[$offset] === $alternateWire[$offset]) {
				continue;
			}
			if (substr($wire, $offset, 40) !== $localId ||
				substr($alternateWire, $offset, 40) !== $alternate['scalar']['local_id']) {
				$failure = 'cleanup-size-invariant';
				return(false);
			}
			$offsets[] = $offset;
			$offset += 39;
		}
		if (count($offsets) !== 2) {
			$failure = 'cleanup-size-invariant';
			return(false);
		}
		return(new self($wire, $estimate, $localId, $offsets));
	}

	private static function wireForTuple($tx, $hash, array $tuple, $daemonCap)
	{
		$parts = self::callbackParts($tx, $hash, $tuple, true, $daemonCap);
		if ($parts === false) {
			return(false);
		}
		$params = array();
		foreach ($parts as $fragment) {
			$params[] = $fragment->takeText();
		}
		unset($parts);
		$wire = retrackersBuildDirectRequest('branch', $params);
		return(is_string($wire) && strlen($wire) <= $daemonCap ? $wire : false);
	}

	public function bindLocalId($localId)
	{
		if (!is_string($localId) || preg_match('/^[0-9A-F]{40}$/D', $localId) !== 1 ||
			count($this->localIdOffsets) !== 2) {
			return(false);
		}
		$wire = $this->wire;
		foreach ($this->localIdOffsets as $offset) {
			if (substr($wire, $offset, 40) !== $this->localId) {
				return(false);
			}
			for ($index = 0; $index < 40; $index++) {
				$wire[$offset + $index] = $localId[$index];
			}
		}
		return(new self($wire, $this->estimatedWireBytes, $localId, $this->localIdOffsets));
	}

	public static function build($tx, $hash, array $tuple, $daemonCap, &$failure = null)
	{
		$estimate = self::measure($tx, $hash, $tuple, $daemonCap, $failure);
		if ($estimate === false) {
			return(false);
		}
		if (!RetrackersCommandFragment::hasSingleOwnerMaterializationHeadroom(
			$estimate)) {
			$failure = 'cleanup-builder-headroom';
			return(false);
		}
		return(self::materializeSealed(
			$tx, $hash, $tuple, $daemonCap, $estimate, $failure));
	}

	public function method()
	{
		return($this->method);
	}

	public function wire()
	{
		return($this->wire);
	}

	public function estimatedWireBytes()
	{
		return($this->estimatedWireBytes);
	}

	public function responsePlan($family)
	{
		return(retrackersRestrictedPlanDirectScalar('string'));
	}
}

class RetrackersHistoricalLedgerAttestation
{
	private $ledgerTrusted;
	private $writerTrusted;

	public function __construct($private, $multi, $modifiable, $exclusiveWriter)
	{
		$this->ledgerTrusted = is_bool($private) && is_bool($multi) && is_bool($modifiable) &&
			is_bool($exclusiveWriter) && $private && $multi && $modifiable;
		$this->writerTrusted = $this->ledgerTrusted && $exclusiveWriter;
	}

	public function failure()
	{
		if (!$this->ledgerTrusted) {
			return('receipt-ledger-corrupt');
		}
		return($this->writerTrusted ? null : 'profile-binding-writer-untrusted');
	}

	public function writerTrusted()
	{
		return($this->writerTrusted);
	}
}

class RetrackersRecoveryRows4Decisions
{
	const MAX_ROWS = 16384;
	const MAX_CELL_BYTES = 1048576;

	private static function readU32($packed, &$offset)
	{
		if ($offset < 0 || strlen($packed) - $offset < 4) {
			return(false);
		}
		$value = ord($packed[$offset]) * 16777216 + ord($packed[$offset + 1]) * 65536 +
			ord($packed[$offset + 2]) * 256 + ord($packed[$offset + 3]);
		$offset += 4;
		return($value);
	}

	private static function readCell($packed, &$offset)
	{
		$length = self::readU32($packed, $offset);
		if ($length === false || $length > self::MAX_CELL_BYTES ||
			$length > strlen($packed) - $offset) {
			return(false);
		}
		$value = substr($packed, $offset, $length);
		$offset += $length;
		return($value);
	}

	private static function markerDecision($localId, $marker, $ack)
	{
		$class = 'Q';
		$kind = 'q';
		$payload = '';
		if ($marker === '' && $ack === '') {
			return(array('C', 'c', ''));
		}
		if (preg_match('/^v1:original:([01]):([0-9A-F]{40}):([0-9a-f]{64})$/D',
			$marker, $matched) === 1 && $matched[2] === $localId) {
			$kind = 'o';
			$payload = $matched[1] . $matched[3];
			if ($ack === $marker) {
				$class = 'O';
			} elseif ($ack === '') {
				$class = 'P';
			} else {
				$kind = 'q';
				$payload = '';
			}
			return(array($class, $kind, $payload));
		}
		if (preg_match('/^v1:(candidate|rollback)-(claim|ready):([0-9a-f]{32})$/D',
			$marker, $matched) === 1) {
			$kind = $matched[1] === 'candidate' ? 'a' : 'b';
			$payload = $matched[3];
			if ($matched[2] === 'claim' && $ack === '') {
				$class = $matched[1] === 'candidate' ? 'A' : 'B';
			} elseif ($matched[2] === 'ready' && $ack === $marker) {
				$class = $matched[1] === 'candidate' ? 'R' : 'S';
			} elseif ($matched[2] === 'ready' && $ack === '') {
				$class = $matched[1] === 'candidate' ? 'I' : 'J';
			} else {
				$kind = 'q';
				$payload = '';
			}
		}
		return(array($class, $kind, $payload));
	}

	public static function project($packed, $expectedCount)
	{
		if (!is_string($packed) || !is_int($expectedCount) || $expectedCount < 0 ||
			$expectedCount > self::MAX_ROWS || strlen($packed) < 8 ||
			substr($packed, 0, 4) !== "RR4\0") {
			return(false);
		}
		$offset = 4;
		$count = self::readU32($packed, $offset);
		if ($count === false || $count !== $expectedCount || $count > self::MAX_ROWS) {
			return(false);
		}
		$decisions = "RD4\0" . pack('N', $count);
		$hashes = array();
		$localIds = array();
		$previousHash = null;
		$previousLocalId = null;
		$quarantineCount = 0;
		$markedCount = 0;
		for ($row = 0; $row < $count; $row++) {
			$cells = array();
			for ($cell = 0; $cell < 4; $cell++) {
				$value = self::readCell($packed, $offset);
				if ($value === false) {
					return(false);
				}
				$cells[] = $value;
			}
			if (preg_match('/^[0-9A-F]{40}$/D', $cells[0]) !== 1 ||
				preg_match('/^[0-9A-F]{40}$/D', $cells[1]) !== 1) {
				return(false);
			}
			$hashKey = "\0" . $cells[0];
			$localKey = "\0" . $cells[1];
			if (isset($hashes[$hashKey]) || isset($localIds[$localKey])) {
				return(false);
			}
			if ($previousHash !== null) {
				$order = strcmp($previousHash, $cells[0]);
				if ($order > 0 || ($order === 0 && strcmp($previousLocalId, $cells[1]) >= 0)) {
					return(false);
				}
			}
			$hashes[$hashKey] = true;
			$localIds[$localKey] = true;
			$previousHash = $cells[0];
			$previousLocalId = $cells[1];
			$decision = self::markerDecision($cells[1], $cells[2], $cells[3]);
			if ($decision[0] === 'Q') {
				$quarantineCount++;
			}
			// How many rows carry a marker at all. 'C' is the class of a row with
			// NEITHER custom set -- an ordinary download this plugin has never
			// touched -- and the request these rows come from is an unfiltered
			// d.multicall2 over the whole main view, so 'count' above is the
			// number of DOWNLOADS, not the number of recoveries in flight.
			// Anything that means to ask "is a recovery outstanding" has to ask
			// this counter; asking 'count' asks "does this install have torrents".
			if ($decision[0] !== 'C') {
				$markedCount++;
			}
			$decisions .= $cells[0] . $cells[1] . $decision[0] . $decision[1] .
				pack('N', strlen($decision[2])) . $decision[2] .
				pack('N', strlen($cells[2])) . hash('sha256', $cells[2], true) .
				pack('N', strlen($cells[3])) . hash('sha256', $cells[3], true);
		}
		if ($offset !== strlen($packed)) {
			return(false);
		}
		// Quarantine count is report-only; marked_count gates empty-ledger startup.
		return(array('ok' => true, 'count' => $count, 'quarantine_count' => $quarantineCount,
			'marked_count' => $markedCount, 'packed' => $decisions));
	}
}

class RetrackersHistoricalBindingClassifier
{
	private function isHex($value, $length)
	{
		return(is_string($value) && preg_match('/^[0-9a-f]{' . $length . '}$/D', $value) === 1);
	}

	private function malformed()
	{
		return(array('status' => 'malformed'));
	}

	private function ledgerCorrupt()
	{
		return(array('status' => 'ledger-corrupt'));
	}

	private function wire($sample)
	{
		if (!is_array($sample) || !isset($sample['ok'], $sample['family'], $sample['value']) ||
			$sample['ok'] !== true || !in_array($sample['family'], array(1, 2), true) ||
			!is_array($sample['value'])) {
			return(false);
		}
		$value = $sample['value'];
		$digests = array('digest', 'event_key_digest', 'event_map_digest', 'recovery_rows_digest',
			'ledger_key_digest', 'ledger_map_digest', 'ledger_digest');
		foreach ($digests as $name) {
			if (!isset($value[$name]) || !$this->isHex($value[$name], 64)) {
				return(false);
			}
		}
		if (!isset($value['event_keys'], $value['actions'], $value['recovery_rows'],
			$value['ledger_keys'], $value['counts'], $value['production_accepted']) ||
			!is_array($value['event_keys']) || !is_array($value['actions']) ||
			!is_string($value['recovery_rows']) || !is_array($value['ledger_keys']) ||
			!is_array($value['counts']) || $value['production_accepted'] !== false) {
			return(false);
		}
		$countNames = array('event_keys', 'actions', 'recovery_rows', 'ledger_keys');
		if (count($value['counts']) !== count($countNames)) {
			return(false);
		}
		foreach ($countNames as $name) {
			if (!array_key_exists($name, $value['counts']) || !is_int($value['counts'][$name]) ||
				$value['counts'][$name] < 0) {
				return(false);
			}
		}
		if ($value['counts']['event_keys'] !== count($value['event_keys']) ||
			$value['counts']['actions'] !== count($value['actions']) ||
			$value['counts']['ledger_keys'] !== count($value['ledger_keys']) ||
			$value['counts']['event_keys'] > 4096 || $value['counts']['actions'] > 4096 ||
			$value['counts']['ledger_keys'] > 4096 || $value['counts']['recovery_rows'] > 16384) {
			return(false);
		}
		$eventNames = array();
		$previous = null;
		$retainedNameBytes = 0;
		foreach ($value['event_keys'] as $name) {
			if (!is_string($name) || strlen($name) > 4096 ||
				($previous !== null && strcmp($previous, $name) >= 0)) {
				return(false);
			}
			$retainedNameBytes += strlen($name);
			$eventNames[] = $name;
			$previous = $name;
		}
		$actionNames = array();
		$previous = null;
		$decodedTextBytes = 0;
		foreach ($value['actions'] as $entry) {
			if (!is_array($entry) || !isset($entry['name'], $entry['type'], $entry['digest']) ||
				!is_string($entry['name']) || !is_string($entry['type']) ||
				!is_string($entry['digest']) || strlen($entry['digest']) !== 32 ||
				!in_array($entry['type'], array('string', 'array'), true) ||
				strlen($entry['name']) > 4096 ||
				($entry['type'] === 'string' && (!array_key_exists('value', $entry) ||
					!is_string($entry['value']) || strlen($entry['value']) > 1048576 ||
					!hash_equals($entry['digest'], retrackersRestrictedHashString($entry['value'])))) ||
				($previous !== null && strcmp($previous, $entry['name']) >= 0)) {
				return(false);
			}
			$retainedNameBytes += strlen($entry['name']);
			if ($entry['type'] === 'string') {
				$decodedTextBytes += strlen($entry['value']);
			}
			$actionNames[] = $entry['name'];
			$previous = $entry['name'];
		}
		$claimCount = 0;
		foreach ($value['ledger_keys'] as $name) {
			if (!is_string($name) || strlen($name) > 4096) {
				return(false);
			}
			$retainedNameBytes += strlen($name);
			if (strncmp($name, 'pf:', 3) === 0) {
				$claimCount++;
			}
		}
		if ($eventNames !== $actionNames || $claimCount > 2048 ||
			$retainedNameBytes > 2097152 || $decodedTextBytes > 8388608) {
			return(false);
		}
		return($value);
	}

	private function ledger($keys)
	{
		$state = array('ma' => false, 'ta' => false, 'pv' => array(), 'claims' => array(),
			'claim_count' => 0, 'claim_duplicate' => false, 'owners' => array(),
			'receipts' => array(), 'packed' => "HL4\0" . pack('N', count($keys)));
		$previous = null;
		foreach ($keys as $name) {
			if (!is_string($name) || !retrackersRestrictedLedgerKey($name) ||
				($previous !== null && strcmp($previous, $name) >= 0)) {
				return(false);
			}
			$state['packed'] .= pack('N', strlen($name)) . $name;
			$previous = $name;
			if ($name === 'ma:1') {
				$state['ma'] = true;
			} elseif ($name === 'ta:1') {
				$state['ta'] = true;
			} elseif (preg_match('/^pv:([0-9a-f]{32})$/D', $name, $matched) === 1) {
				$state['pv'][] = $matched[1];
			} elseif (preg_match('/^pf:([0-9a-f]{64}):([0-9a-f]{64})$/D', $name, $matched) === 1) {
				$state['claim_count']++;
				$key = "\0" . $matched[1];
				if (isset($state['claims'][$key])) {
					$state['claim_duplicate'] = true;
				} else {
					$state['claims'][$key] = $matched[2];
				}
			} elseif (preg_match('/^to:([0-9a-f]{32}):([idc]):([0-9a-f]{64})$/D',
				$name, $matched) === 1) {
				$state['owners'][] = array('token' => $matched[1], 'mode' => $matched[2],
					'user_hash' => $matched[3]);
			} else {
				$state['receipts'][] = $name;
			}
		}
		if (count($state['pv']) > 1 || count($state['owners']) > 1 ||
			$state['ta'] !== (count($state['owners']) === 1)) {
			return(false);
		}
		if (count($state['owners']) === 1) {
			$mode = $state['owners'][0]['mode'];
			if (($mode !== 'c' && $state['ma']) || ($mode === 'c' && !$state['ma'])) {
				return(false);
			}
		}
		if (count($state['pv']) === 0 && count($keys) > 0) {
			return(false);
		}
		return($state);
	}

	private function profiles($actions, $claims)
	{
		$profiles = array();
		$semanticValid = true;
		foreach ($actions as $entry) {
			$name = $entry['name'];
			if (strncmp($name, 'tadd_trackers', 13) !== 0) {
				continue;
			}
			if (preg_match('/^tadd_trackers([12])([a-z0-9_-]*)$/D', $name, $matched) !== 1) {
				$semanticValid = false;
				continue;
			}
			$user = $matched[2];
			$key = "\0" . $user;
			if (!isset($profiles[$key])) {
				$profiles[$key] = array('user' => $user, 'user_hash' => hash('sha256', $user),
					'parts' => array());
			}
			$part = (int)$matched[1];
			if (isset($profiles[$key]['parts'][$part]) || $entry['type'] !== 'string') {
				$semanticValid = false;
			} else {
				$profiles[$key]['parts'][$part] = $entry['value'];
			}
		}
		ksort($profiles, SORT_STRING);
		$hashUsers = array();
		$projected = array();
		$safety = retrackersBuildSafetyOnlyInsertAction();
		$defer = retrackersBuildDeferOnlyInsertAction();
		foreach ($profiles as $profile) {
			$hashKey = "\0" . $profile['user_hash'];
			if (isset($hashUsers[$hashKey])) {
				$semanticValid = false;
			}
			$hashUsers[$hashKey] = true;
			if (count($profile['parts']) !== 2 || !isset($profile['parts'][1], $profile['parts'][2])) {
				$semanticValid = false;
				$firstHash = isset($profile['parts'][1]) ? hash('sha256', $profile['parts'][1]) : null;
				$secondHash = isset($profile['parts'][2]) ? hash('sha256', $profile['parts'][2]) : null;
				$pair = 'invalid';
			} else {
				$firstHash = hash('sha256', $profile['parts'][1]);
				$secondHash = hash('sha256', $profile['parts'][2]);
				$claimKey = "\0" . $profile['user_hash'];
				if ($profile['parts'][1] === $defer && $profile['parts'][2] === $defer) {
					$pair = 'D/D';
				} elseif ($profile['parts'][1] === $safety && $profile['parts'][2] === $safety) {
					$pair = 'S/S';
				} elseif ($profile['parts'][2] === $safety && isset($claims[$claimKey]) &&
					hash_equals($claims[$claimKey], $firstHash)) {
					$pair = 'F/S';
				} else {
					$pair = 'invalid';
					$semanticValid = false;
				}
			}
			$claimKey = "\0" . $profile['user_hash'];
			$projected[$hashKey] = array('user' => $profile['user'],
				'user_hash' => $profile['user_hash'], 'pair' => $pair,
				'first_hash' => $firstHash, 'second_hash' => $secondHash,
				'claim_hash' => isset($claims[$claimKey]) ? $claims[$claimKey] : null);
		}
		foreach ($claims as $hashKey => $unused) {
			if (!isset($projected[$hashKey])) {
				$semanticValid = false;
			}
		}
		ksort($projected, SORT_STRING);
		$packed = "HP4\0" . pack('N', count($projected));
		foreach ($projected as $profile) {
			$packed .= pack('N', strlen($profile['user'])) . $profile['user'] .
				$profile['user_hash'] . pack('N', strlen($profile['pair'])) . $profile['pair'] .
				($profile['first_hash'] === null ? str_repeat('-', 64) : $profile['first_hash']) .
				($profile['second_hash'] === null ? str_repeat('-', 64) : $profile['second_hash']) .
				($profile['claim_hash'] === null ? str_repeat('-', 64) : $profile['claim_hash']);
		}
		return(array('valid' => $semanticValid, 'profiles' => $projected, 'packed' => $packed));
	}

	private function everyPair($profiles, $pair)
	{
		foreach ($profiles as $profile) {
			if ($profile['pair'] !== $pair) {
				return(false);
			}
		}
		return(true);
	}

	public function classify($sample, $canonicalUser, $expectedFunctionalAction = null)
	{
		if (!is_string($canonicalUser) || preg_match('/^[a-z0-9_-]*$/D', $canonicalUser) !== 1 ||
			(!is_null($expectedFunctionalAction) && !is_string($expectedFunctionalAction)) ||
			(is_string($expectedFunctionalAction) && strlen($expectedFunctionalAction) > 1048576)) {
			return($this->malformed());
		}
		$value = $this->wire($sample);
		if ($value === false) {
			return($this->malformed());
		}
		$recovery = RetrackersRecoveryRows4Decisions::project($value['recovery_rows'],
			$value['counts']['recovery_rows']);
		if ($recovery === false) {
			return($this->malformed());
		}
		$ledger = $this->ledger($value['ledger_keys']);
		if ($ledger === false) {
			return($this->ledgerCorrupt());
		}
		$profiles = $this->profiles($value['actions'], $ledger['claims']);
		// Ledger keys live only in daemon memory. A hook with no keys may be
		// historical, or a current hook whose claim was lost; neither origin
		// can be proved from this read. A persisted recovery marker with no
		// ledger is structural corruption and must retain its stronger refusal.
		// Use marked_count, not the count of ordinary torrents in the main view.
		$emptyLedger = count($value['ledger_keys']) === 0;
		if ($emptyLedger && $recovery['marked_count'] !== 0) {
			return($this->ledgerCorrupt());
		}
		$hookWithoutLedger = $emptyLedger && (!$profiles['valid'] ||
			count($profiles['profiles']) !== 0);
		$semanticValid = $profiles['valid'] && !$ledger['claim_duplicate'];
		$profileCount = count($profiles['profiles']);
		$claimCount = $ledger['claim_count'];
		$owner = count($ledger['owners']) === 1 ? $ledger['owners'][0] : null;
		$callerHash = hash('sha256', $canonicalUser);
		$callerKey = "\0" . $callerHash;
		$membership = $owner !== null && $owner['user_hash'] === $callerHash ? 'owner' :
			(isset($profiles['profiles'][$callerKey]) ? 'profile' : 'absent');
		$phase = null;
		$observation = null;
		$epoch = count($ledger['pv']) === 1 ? $ledger['pv'][0] : null;
		if ($epoch === null) {
			// marked_count for the same reason as the gate above: BOOTSTRAP is
			// "nothing of ours exists yet", not "this daemon holds no torrents".
			if (count($value['ledger_keys']) === 0 && $profileCount === 0 && $claimCount === 0 &&
				$recovery['marked_count'] === 0) {
				$phase = 'BOOTSTRAP';
				$observation = 'BOOTSTRAP';
			} else {
				$semanticValid = false;
			}
		} elseif (!$ledger['ma'] && !$ledger['ta'] && $owner === null) {
			if ($profileCount === 0 && $claimCount === 0) {
				$phase = 'IDLE_CURRENT';
				$observation = 'IDLE_EMPTY_CURRENT';
			} elseif ($profileCount === 1 && $claimCount === 1 &&
				$this->everyPair($profiles['profiles'], 'F/S')) {
				$phase = 'IDLE_CURRENT';
				$observation = 'IDLE_CURRENT';
			} else {
				$semanticValid = false;
			}
		} elseif ($owner !== null && $owner['mode'] === 'i') {
			$ownerKey = "\0" . $owner['user_hash'];
			$ownerProfile = isset($profiles['profiles'][$ownerKey]) ? $profiles['profiles'][$ownerKey] : null;
			$valid = ($profileCount === 1 || $profileCount === 2) && $claimCount === $profileCount &&
				$ownerProfile !== null && $ownerProfile['pair'] === 'D/D' &&
				$ownerProfile['claim_hash'] !== null;
			foreach ($profiles['profiles'] as $key => $profile) {
				if ($key !== $ownerKey && $profile['pair'] !== 'F/S') {
					$valid = false;
				}
			}
			if ($owner['user_hash'] === $callerHash && (!is_string($expectedFunctionalAction) ||
				!hash_equals($ownerProfile === null ? '' : $ownerProfile['claim_hash'],
					hash('sha256', $expectedFunctionalAction)))) {
				$valid = false;
			}
			if ($valid) {
				$phase = 'INIT_OWNER';
				$observation = $profileCount === 1 ? 'FIRST_INIT_OWNER' : 'SECOND_INIT_OWNER';
			} else {
				$semanticValid = false;
			}
		} elseif ($owner !== null && $owner['mode'] === 'd') {
			$ownerKey = "\0" . $owner['user_hash'];
			$ownerProfile = isset($profiles['profiles'][$ownerKey]) ? $profiles['profiles'][$ownerKey] : null;
			if ($profileCount === 1 && $claimCount === 1 && $ownerProfile !== null &&
				$ownerProfile['pair'] === 'S/S' && $ownerProfile['claim_hash'] !== null) {
				$phase = 'DONE_OWNER';
				$observation = 'DONE_OWNER';
			} else {
				$semanticValid = false;
			}
		} elseif ($owner !== null && $owner['mode'] === 'c') {
			if ($profileCount >= 2 && $claimCount === 0 &&
				$this->everyPair($profiles['profiles'], 'S/S')) {
				$phase = 'CONTAIN_OWNER';
				$observation = 'CONTAIN_OWNER';
			} else {
				$semanticValid = false;
			}
		} elseif ($ledger['ma'] && !$ledger['ta'] && $owner === null) {
			if ($profileCount >= 2 && $claimCount === 0 &&
				$this->everyPair($profiles['profiles'], 'S/S')) {
				$phase = 'CONTAINED';
				$observation = 'CONTAINED';
			} else {
				$semanticValid = false;
			}
		} else {
			$semanticValid = false;
		}
		if ($phase === null) {
			$semanticValid = false;
		}
		$profileDecision = array();
		foreach ($profiles['profiles'] as $profile) {
			$profileDecision[] = array('user' => $profile['user'], 'user_hash' => $profile['user_hash'],
				'pair' => $profile['pair'], 'functional_hash' => $profile['first_hash'],
				'claim_hash' => $profile['claim_hash']);
		}
		$decision = array(
			'production_accepted' => false,
			'phase' => $phase,
			'observation' => $observation,
			'persistent_epoch' => $epoch,
			'caller' => array('user_hash' => $callerHash, 'membership' => $membership,
				'is_owner' => $membership === 'owner'),
			'owner' => $owner,
			'profile_count' => $profileCount,
			'claim_count' => $claimCount,
			'profiles' => $profileDecision,
			'transaction_receipts' => $ledger['receipts'],
			'recovery_decisions' => $recovery['packed'],
			'recovery_quarantine_count' => $recovery['quarantine_count'],
			'binding' => array('family' => $sample['family'], 'digest' => $value['digest'],
				'counts' => $value['counts']),
		);
		if ($phase === 'IDLE_CURRENT' && $recovery['marked_count'] !== 0) {
			return(array('status' => 'recovery-pending'));
		}
		$status = $hookWithoutLedger ? 'hook-ledger-mismatch' :
			($semanticValid ? 'valid' : 'semantic-invalid');
		$stable = array(
			'status' => $status,
			'family' => $sample['family'],
			'digest' => $value['digest'],
			'counts' => $value['counts'],
			'profile_count' => $profileCount,
			'claim_count' => $claimCount,
			'caller_membership' => $membership,
			'phase' => $phase,
			'observation' => $observation,
			'owner' => $owner,
			'epoch' => $epoch,
			'profiles' => $profiles['packed'],
			'ledger' => $ledger['packed'],
			'recovery' => $recovery['packed'],
		);
		return(array('status' => $status,
			'phase' => $phase, 'observation' => $observation, 'stable' => $stable,
			'decision' => $decision));
	}
}

class RetrackersStableHistoricalBinding
{
	private $adapter;
	private $classifier;

	public function __construct($adapter = null)
	{
		$this->adapter = $adapter === null ? new RetrackersDirectRpcAdapter() : $adapter;
		$this->classifier = new RetrackersHistoricalBindingClassifier();
	}

	private function read(&$failure)
	{
		if (!($this->adapter instanceof RetrackersDirectRpcAdapter)) {
			$failure = 'malformed-response';
			return(false);
		}
		return($this->adapter->historicalBindingSample($failure));
	}

	public function consume($canonicalUser, $expectedFunctionalAction,
		RetrackersHistoricalLedgerAttestation $attestation, callable $consumer)
	{
		if (!is_string($canonicalUser) || preg_match('/^[a-z0-9_-]*$/D', $canonicalUser) !== 1 ||
			(!is_null($expectedFunctionalAction) && !is_string($expectedFunctionalAction)) ||
			(is_string($expectedFunctionalAction) && strlen($expectedFunctionalAction) > 1048576)) {
			return(array('ok' => false, 'failure' => 'historical-hook-restart-required'));
		}
		$failure = null;
		$firstSample = $this->read($failure);
		if ($firstSample === false) {
			return(array('ok' => false, 'failure' => $failure));
		}
		$first = $this->classifier->classify($firstSample, $canonicalUser, $expectedFunctionalAction);
		unset($firstSample);
		if ($first['status'] === 'malformed') {
			return(array('ok' => false, 'failure' => 'malformed-response'));
		}
		$firstStable = isset($first['stable']) ? $first['stable'] : null;
		$firstStatus = $first['status'];
		unset($first);

		$failure = null;
		$secondSample = $this->read($failure);
		if ($secondSample === false) {
			return(array('ok' => false, 'failure' => $failure));
		}
		$second = $this->classifier->classify($secondSample, $canonicalUser, $expectedFunctionalAction);
		unset($secondSample);
		if ($second['status'] === 'malformed') {
			return(array('ok' => false, 'failure' => 'malformed-response'));
		}
		if ($firstStatus === 'ledger-corrupt' || $second['status'] === 'ledger-corrupt') {
			return(array('ok' => false, 'failure' => 'receipt-ledger-corrupt'));
		}
		if ($firstStatus === 'recovery-pending' || $second['status'] === 'recovery-pending') {
			return(array('ok' => false, 'failure' => 'marked-recovery-pending'));
		}
		$attestationFailure = $attestation->failure();
		if ($attestationFailure !== null) {
			return(array('ok' => false, 'failure' => $attestationFailure));
		}
		if ($firstStable !== $second['stable']) {
			return(array('ok' => false, 'failure' => 'profile-binding-unstable'));
		}
		$decision = $second['decision'];
		if ($second['status'] === 'hook-ledger-mismatch') {
			return(array('ok' => false, 'failure' => 'hook-ledger-mismatch-restart-required'));
		}
		if ($second['status'] !== 'valid') {
			return(array('ok' => false, 'failure' => 'historical-hook-restart-required'));
		}
		if ($decision['owner'] !== null && !$decision['caller']['is_owner']) {
			return(array('ok' => false, 'failure' => 'lifecycle-busy'));
		}
		if ($decision['phase'] === 'CONTAINED') {
			return(array('ok' => false, 'failure' => 'shared-daemon-contained'));
		}
		$decision['production_accepted'] = true;
		$result = call_user_func($consumer, $decision);
		return(array('ok' => true, 'consumer_result' => $result));
	}
}

const RETRACKERS_METAINFO_CAP = 64 * 1024 * 1024;

class RetrackersBoundedFileReader
{
	const READ_BYTES = 65536;

	protected function openPath($path)
	{
		return(@fopen($path, 'rb'));
	}

	protected function openedMetadata($handle)
	{
		return(stream_get_meta_data($handle));
	}

	protected function openedStat($handle)
	{
		return(@fstat($handle));
	}

	protected function readOpened($handle, $length)
	{
		return(@fread($handle, $length));
	}

	protected function openedEof($handle)
	{
		return(feof($handle));
	}

	protected function closeOpened($handle)
	{
		return(@fclose($handle));
	}

	public function capture($path)
	{
		if (!is_string($path)) {
			return(array('ok' => false, 'reason' => 'source-unreadable'));
		}
		$handle = $this->openPath($path);
		if (!is_resource($handle)) {
			return(array('ok' => false, 'reason' => 'source-unreadable'));
		}
		try {
			$metadata = $this->openedMetadata($handle);
			$stat = $this->openedStat($handle);
			if (!is_array($metadata) || !isset($metadata['wrapper_type']) ||
				$metadata['wrapper_type'] !== 'plainfile' || !is_array($stat) ||
				!isset($stat['mode']) || !is_int($stat['mode']) ||
				(($stat['mode'] & 0170000) !== 0100000)) {
				return(array('ok' => false, 'reason' => 'source-not-regular'));
			}
			$buffer = @fopen('php://temp/maxmemory:1048576', 'w+b');
			if (!is_resource($buffer)) {
				return(array('ok' => false, 'reason' => 'source-read-error'));
			}
			$total = 0;
			try {
			while (true) {
				$request = min(self::READ_BYTES,
					(RETRACKERS_METAINFO_CAP + 1) - $total);
				$chunk = $this->readOpened($handle, $request);
				if ($chunk === false || !is_string($chunk) || strlen($chunk) > $request) {
					return(array('ok' => false, 'reason' => 'source-read-error'));
				}
				$length = strlen($chunk);
				if ($length === 0) {
					if (!$this->openedEof($handle)) {
						return(array('ok' => false, 'reason' => 'source-short-read'));
					}
					if (@rewind($buffer) !== true) {
						return(array('ok' => false, 'reason' => 'source-read-error'));
					}
					$capture = @stream_get_contents($buffer);
					return(is_string($capture) && strlen($capture) === $total ?
						array('ok' => true, 'bytes' => $capture) :
						array('ok' => false, 'reason' => 'source-read-error'));
				}
				$offset = 0;
				while ($offset < $length) {
					$written = @fwrite($buffer, substr($chunk, $offset));
					if (!is_int($written) || $written < 1 || $written > $length - $offset) {
						return(array('ok' => false, 'reason' => 'source-read-error'));
					}
					$offset += $written;
				}
				$total += $length;
				if ($total === RETRACKERS_METAINFO_CAP + 1) {
					return(array('ok' => false, 'reason' => 'source-too-large'));
				}
				if ($length < $request) {
					if (!$this->openedEof($handle)) {
						return(array('ok' => false, 'reason' => 'source-short-read'));
					}
					if (@rewind($buffer) !== true) {
						return(array('ok' => false, 'reason' => 'source-read-error'));
					}
					$capture = @stream_get_contents($buffer);
					return(is_string($capture) && strlen($capture) === $total ?
						array('ok' => true, 'bytes' => $capture) :
						array('ok' => false, 'reason' => 'source-read-error'));
				}
			}
			} finally {
				@fclose($buffer);
			}
		} finally {
			$this->closeOpened($handle);
		}
	}
}

/**
 * Owns the two unlink-before-use metainfo descriptors for one replacement.
 * Named files never escape this class; callers receive only procfd capabilities.
 */
class RetrackersAnonymousStage
{
	const WRITE_SLICE = 65536;

	private $candidate = null;
	private $original = null;

	final protected function __construct()
	{
	}

	protected function temporaryRoot()
	{
		return(FileUtil::getTempDirectory());
	}

	protected function changeUmask($mask)
	{
		return(umask($mask));
	}

	protected function makeDirectory($path)
	{
		return(@mkdir($path, 0700, false));
	}

	protected function openExclusiveFile($path)
	{
		return(@fopen($path, 'x+b'));
	}

	protected function writeFile($handle, $bytes)
	{
		return(@fwrite($handle, $bytes));
	}

	protected function flushFile($handle)
	{
		return(@fflush($handle));
	}

	protected function statHandle($handle)
	{
		return(@fstat($handle));
	}

	protected function lstatPath($path)
	{
		return(@lstat($path));
	}

	protected function unlinkFile($path)
	{
		return(@unlink($path));
	}

	protected function directoryEntries($path)
	{
		return(@scandir($path));
	}

	protected function removeDirectory($path)
	{
		return(@rmdir($path));
	}

	protected function procEntries()
	{
		return(@scandir('/proc/self/fd'));
	}

	protected function statProcEntry($path)
	{
		return(@stat($path));
	}

	protected function processId()
	{
		return(getmypid());
	}

	protected function closeFile($handle)
	{
		return(@fclose($handle));
	}

	private function regularFile($stat)
	{
		return(is_array($stat) && isset($stat['dev'], $stat['ino'], $stat['mode'],
			$stat['nlink'], $stat['uid'], $stat['size']) &&
			is_int($stat['dev']) && is_int($stat['ino']) && is_int($stat['mode']) &&
			is_int($stat['nlink']) && is_int($stat['uid']) && is_int($stat['size']) &&
			($stat['mode'] & 0170000) === 0100000);
	}

	private function privateDirectory($stat)
	{
		return(is_array($stat) && isset($stat['dev'], $stat['ino'], $stat['mode'], $stat['uid']) &&
			is_int($stat['dev']) && is_int($stat['ino']) && is_int($stat['mode']) &&
			is_int($stat['uid']) && ($stat['mode'] & 0170000) === 0040000 &&
			($stat['mode'] & 0777) === 0700);
	}

	private function sameDirectory($current, $expected)
	{
		return($this->privateDirectory($current) && $this->privateDirectory($expected) &&
			$current['dev'] === $expected['dev'] && $current['ino'] === $expected['ino'] &&
			$current['uid'] === $expected['uid'] && $current['mode'] === $expected['mode']);
	}

	private function matchingNamedFile($handleStat, $pathStat, $size, $directoryUid)
	{
		return($this->regularFile($handleStat) && $this->regularFile($pathStat) &&
			$handleStat['dev'] === $pathStat['dev'] && $handleStat['ino'] === $pathStat['ino'] &&
			$handleStat['size'] === $size && $pathStat['size'] === $size &&
			$handleStat['uid'] === $directoryUid && $pathStat['uid'] === $directoryUid &&
			$handleStat['mode'] === $pathStat['mode'] && ($handleStat['mode'] & 0777) === 0600 &&
			$handleStat['nlink'] === 1 && $pathStat['nlink'] === 1);
	}

	private function matchingUnlinkedFile($stat, $proof)
	{
		return($this->regularFile($stat) && $stat['dev'] === $proof['dev'] &&
			$stat['ino'] === $proof['ino'] && $stat['size'] === $proof['size'] &&
			$stat['uid'] === $proof['uid'] && $stat['mode'] === $proof['mode'] &&
			($stat['mode'] & 0777) === 0600 && $stat['nlink'] === 0);
	}

	private function writeComplete($handle, $bytes, &$hash)
	{
		$offset = 0;
		$length = strlen($bytes);
		$context = hash_init('sha256');
		while ($offset < $length) {
			$chunk = substr($bytes, $offset, min(self::WRITE_SLICE, $length - $offset));
			$written = $this->writeFile($handle, $chunk);
			if (!is_int($written) || $written <= 0 || $written > strlen($chunk)) {
				return(false);
			}
			hash_update($context, $written === strlen($chunk) ? $chunk : substr($chunk, 0, $written));
			$offset += $written;
		}
		if ($offset !== $length) {
			return(false);
		}
		$hash = hash_final($context);
		return(true);
	}

	private function initialProof($handle, $path, $bytes, $directoryProof, &$failure)
	{
		$handleStat = $this->statHandle($handle);
		$pathStat = $this->lstatPath($path);
		if (!$this->matchingNamedFile($handleStat, $pathStat, strlen($bytes), $directoryProof['uid'])) {
			$failure = 'stage-identity-failed';
			return(false);
		}
		return(array(
			'dev' => $handleStat['dev'],
			'ino' => $handleStat['ino'],
			'size' => $handleStat['size'],
			'uid' => $handleStat['uid'],
			'mode' => $handleStat['mode'],
		));
	}

	private function unlinkAndVerify($handle, $path, $proof, &$failure)
	{
		if (!$this->unlinkFile($path) ||
			!$this->matchingUnlinkedFile($this->statHandle($handle), $proof)) {
			$failure = 'stage-identity-failed';
			return(false);
		}
		return(true);
	}

	private function capabilityFor($proof)
	{
		$entries = $this->procEntries();
		$pid = $this->processId();
		if (!is_array($entries) || !is_int($pid) || $pid <= 0) {
			return(false);
		}
		$matches = array();
		foreach ($entries as $entry) {
			if (!is_string($entry) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $entry) !== 1) {
				continue;
			}
			$path = '/proc/self/fd/' . $entry;
			$stat = $this->statProcEntry($path);
			if ($this->regularFile($stat) && $stat['dev'] === $proof['dev'] &&
				$stat['ino'] === $proof['ino'] && $stat['size'] === $proof['size']) {
				$matches[] = $entry;
			}
		}
		return(count($matches) === 1 ? '/proc/' . $pid . '/fd/' . $matches[0] : false);
	}

	private function ownedNamedFile($handle, $path, $directoryProof)
	{
		$handleStat = is_resource($handle) ? $this->statHandle($handle) : false;
		$pathStat = $this->lstatPath($path);
		return($this->regularFile($handleStat) && $this->regularFile($pathStat) &&
			$handleStat['dev'] === $pathStat['dev'] && $handleStat['ino'] === $pathStat['ino'] &&
			$handleStat['uid'] === $directoryProof['uid'] && $pathStat['uid'] === $directoryProof['uid'] &&
			$handleStat['mode'] === $pathStat['mode'] && ($handleStat['mode'] & 0777) === 0600 &&
			$handleStat['nlink'] === 1 && $pathStat['nlink'] === 1);
	}

	private function safeFailureCleanup($directory, $directoryProof, array $files)
	{
		foreach ($files as $file) {
			if (is_array($directoryProof) && is_resource($file['handle']) &&
				$this->ownedNamedFile($file['handle'], $file['path'], $directoryProof)) {
				$this->unlinkFile($file['path']);
			}
			if (is_resource($file['handle'])) {
				$this->closeFile($file['handle']);
			}
		}
		if (is_array($directoryProof) &&
			$this->sameDirectory($this->lstatPath($directory), $directoryProof)) {
			$entries = $this->directoryEntries($directory);
			if (is_array($entries) && count($entries) === 2 &&
				in_array('.', $entries, true) && in_array('..', $entries, true)) {
				$this->removeDirectory($directory);
			}
		}
	}

	private function publicProof($proof, $capability, $sha256)
	{
		return(array(
			'capability' => $capability,
			'dev' => (string)$proof['dev'],
			'ino' => (string)$proof['ino'],
			'size' => (string)$proof['size'],
			'sha256' => $sha256,
		));
	}

	public static function create($candidateBytes, $originalBytes, &$failure = null)
	{
		$failure = null;
		if (!is_string($candidateBytes) || !is_string($originalBytes) ||
			strlen($candidateBytes) > RETRACKERS_METAINFO_CAP ||
			strlen($originalBytes) > RETRACKERS_METAINFO_CAP) {
			$failure = 'stage-input-invalid';
			return(false);
		}
		$stage = new static();
		$root = $stage->temporaryRoot();
		if (!is_string($root) || $root === '' || strpbrk($root, "\0\r\n") !== false) {
			$failure = 'stage-directory-failed';
			return(false);
		}
		$directory = rtrim($root, '/') . '/retrackers-stage-' . bin2hex(random_bytes(16));
		$directoryProof = false;
		$candidateHandle = false;
		$originalHandle = false;
		$candidatePath = $directory . '/candidate';
		$originalPath = $directory . '/original';
		$oldMask = $stage->changeUmask(0077);
		try {
			if (!$stage->makeDirectory($directory)) {
				$failure = 'stage-directory-failed';
				return(false);
			}
			$directoryProof = $stage->lstatPath($directory);
			if (!$stage->privateDirectory($directoryProof)) {
				$failure = 'stage-directory-failed';
				return(false);
			}

			$candidateHandle = $stage->openExclusiveFile($candidatePath);
			if (!is_resource($candidateHandle)) {
				$failure = 'stage-open-failed';
				return(false);
			}
			$candidateHash = null;
			if (!$stage->writeComplete($candidateHandle, $candidateBytes, $candidateHash)) {
				$failure = 'stage-write-failed';
				return(false);
			}
			if (!$stage->flushFile($candidateHandle)) {
				$failure = 'stage-flush-failed';
				return(false);
			}
			$candidateProof = $stage->initialProof(
				$candidateHandle, $candidatePath, $candidateBytes, $directoryProof, $failure);
			if ($candidateProof === false) {
				return(false);
			}

			$originalHandle = $stage->openExclusiveFile($originalPath);
			if (!is_resource($originalHandle)) {
				$failure = 'stage-open-failed';
				return(false);
			}
			$originalHash = null;
			if (!$stage->writeComplete($originalHandle, $originalBytes, $originalHash)) {
				$failure = 'stage-write-failed';
				return(false);
			}
			if (!$stage->flushFile($originalHandle)) {
				$failure = 'stage-flush-failed';
				return(false);
			}
			$originalProof = $stage->initialProof(
				$originalHandle, $originalPath, $originalBytes, $directoryProof, $failure);
			if ($originalProof === false) {
				return(false);
			}
			if ($candidateProof['dev'] === $originalProof['dev'] &&
				$candidateProof['ino'] === $originalProof['ino']) {
				$failure = 'stage-identity-failed';
				return(false);
			}

			if (!$stage->unlinkAndVerify($candidateHandle, $candidatePath, $candidateProof, $failure) ||
				!$stage->unlinkAndVerify($originalHandle, $originalPath, $originalProof, $failure)) {
				return(false);
			}
			if (!$stage->sameDirectory($stage->lstatPath($directory), $directoryProof)) {
				$failure = 'stage-directory-changed';
				return(false);
			}
			$entries = $stage->directoryEntries($directory);
			if (!is_array($entries) || count($entries) !== 2 ||
				!in_array('.', $entries, true) || !in_array('..', $entries, true) ||
				!$stage->removeDirectory($directory)) {
				$failure = 'stage-directory-changed';
				return(false);
			}

			$candidateCapability = $stage->capabilityFor($candidateProof);
			$originalCapability = $stage->capabilityFor($originalProof);
			if ($candidateCapability === false || $originalCapability === false ||
				$candidateCapability === $originalCapability) {
				$failure = 'stage-procfd-failed';
				return(false);
			}
			$stage->candidate = array(
				'handle' => $candidateHandle,
				'proof' => $stage->publicProof($candidateProof, $candidateCapability, $candidateHash),
			);
			$stage->original = array(
				'handle' => $originalHandle,
				'proof' => $stage->publicProof($originalProof, $originalCapability, $originalHash),
			);
			$candidateHandle = false;
			$originalHandle = false;
			return($stage);
		} finally {
			$stage->changeUmask($oldMask);
			if ($failure !== null) {
				$stage->safeFailureCleanup($directory, $directoryProof, array(
					array('handle' => $candidateHandle, 'path' => $candidatePath),
					array('handle' => $originalHandle, 'path' => $originalPath),
				));
			}
		}
	}

	public function candidate()
	{
		return($this->candidate['proof']);
	}

	public function original()
	{
		return($this->original['proof']);
	}

	private function closeOwned(&$slot)
	{
		if (is_array($slot) && isset($slot['handle']) && is_resource($slot['handle'])) {
			$this->closeFile($slot['handle']);
			$slot['handle'] = null;
		}
	}

	public function closeCandidateAfterFence()
	{
		$this->closeOwned($this->candidate);
	}

	public function closeOriginalAfterNoRollback()
	{
		$this->closeOwned($this->original);
	}

	public function closeOriginalAfterRollbackFence()
	{
		$this->closeOwned($this->original);
	}

	public function abortBeforeArm()
	{
		$this->closeOwned($this->candidate);
		$this->closeOwned($this->original);
	}
}

class RetrackersBencodeError extends Exception
{
}

class RetrackersBencodeKeySet
{
	const RECORD_BYTES = 16;

	private $capture;
	private $table;
	private $capacity = 8;
	private $count = 0;

	public function __construct($capture)
	{
		$this->capture = $capture;
		$this->table = str_repeat("\0", $this->capacity * self::RECORD_BYTES);
	}

	private function fingerprint($offset, $length)
	{
		$context = hash_init('sha256');
		while ($length > 0) {
			$take = min(RetrackersBoundedFileReader::READ_BYTES, $length);
			hash_update($context, substr($this->capture, $offset, $take));
			$offset += $take;
			$length -= $take;
		}
		return(substr(hash_final($context, true), 0, 8));
	}

	private function seed($fingerprint, $start)
	{
		return((ord($fingerprint[$start]) * 65536) +
			(ord($fingerprint[$start + 1]) * 256) + ord($fingerprint[$start + 2]));
	}

	private function probe($fingerprint, $capacity)
	{
		$mask = $capacity - 1;
		$step = ($this->seed($fingerprint, 3) | 1) & $mask;
		if ($step === 0) {
			$step = 1;
		}
		return(array($this->seed($fingerprint, 0) & $mask, $step, $mask));
	}

	private function uint32At($bytes, $offset)
	{
		$value = unpack('Nvalue', substr($bytes, $offset, 4));
		return($value['value']);
	}

	private function writeRecord(&$table, $position, $record)
	{
		for ($index = 0; $index < self::RECORD_BYTES; $index++) {
			$table[$position + $index] = $record[$index];
		}
	}

	private function sameBytes($leftOffset, $rightOffset, $length)
	{
		while ($length > 0) {
			$take = min(RetrackersBoundedFileReader::READ_BYTES, $length);
			$left = substr($this->capture, $leftOffset, $take);
			if (substr_compare($this->capture, $left, $rightOffset, $take) !== 0) {
				return(false);
			}
			$leftOffset += $take;
			$rightOffset += $take;
			$length -= $take;
		}
		return(true);
	}

	private function insertRecord(&$table, $capacity, $record)
	{
		$fingerprint = substr($record, 0, 8);
		$probe = $this->probe($fingerprint, $capacity);
		$slot = $probe[0];
		for ($attempt = 0; $attempt < $capacity; $attempt++) {
			$position = $slot * self::RECORD_BYTES;
			if ($this->uint32At($table, $position + 8) === 0) {
				$this->writeRecord($table, $position, $record);
				return;
			}
			$slot = ($slot + $probe[1]) & $probe[2];
		}
		throw new RetrackersBencodeError('source-too-complex');
	}

	private function grow()
	{
		$oldTable = $this->table;
		$oldCapacity = $this->capacity;
		$this->capacity *= 2;
		$this->table = str_repeat("\0", $this->capacity * self::RECORD_BYTES);
		for ($slot = 0; $slot < $oldCapacity; $slot++) {
			$position = $slot * self::RECORD_BYTES;
			if ($this->uint32At($oldTable, $position + 8) !== 0) {
				$this->insertRecord($this->table, $this->capacity,
					substr($oldTable, $position, self::RECORD_BYTES));
			}
		}
	}

	public function add($offset, $length)
	{
		if (($this->count + 1) * 2 > $this->capacity) {
			$this->grow();
		}
		$fingerprint = $this->fingerprint($offset, $length);
		$probe = $this->probe($fingerprint, $this->capacity);
		$slot = $probe[0];
		for ($attempt = 0; $attempt < $this->capacity; $attempt++) {
			$position = $slot * self::RECORD_BYTES;
			$storedOffset = $this->uint32At($this->table, $position + 8);
			if ($storedOffset === 0) {
				$record = $fingerprint . pack('N', $offset + 1) . pack('N', $length);
				$this->writeRecord($this->table, $position, $record);
				$this->count++;
				return;
			}
			if (substr_compare($this->table, $fingerprint, $position, 8) === 0 &&
				$this->uint32At($this->table, $position + 12) === $length &&
				$this->sameBytes($storedOffset - 1, $offset, $length)) {
				throw new RetrackersBencodeError('source-duplicate-key');
			}
			$slot = ($slot + $probe[1]) & $probe[2];
		}
		throw new RetrackersBencodeError('source-too-complex');
	}
}

class RetrackersBencodeScanner
{
	const MAX_DEPTH = 128;
	const MAX_VALUES_AND_KEYS = 1000000;
	const MAX_TRACKER_PROJECTION_NODES = 16384;

	private $capture;
	private $length;
	private $offset;
	private $complexity;
	private $stack;
	private $root;
	private $top;
	private $info;
	private $collectTrackers = false;
	private $trackerProjectionNodes = 0;

	private function projectionMode()
	{
		if (!$this->collectTrackers || !count($this->stack)) {
			return('none');
		}
		$parent = $this->stack[count($this->stack) - 1];
		if ($parent['projection'] === 'all') {
			return('all');
		}
		if ($parent['type'] !== 'dictionary' || !is_array($parent['key'])) {
			return('none');
		}
		if (count($this->stack) === 1) {
			if ($this->keyEquals($parent['key'], 'announce-list')) {
				return('all');
			}
			if ($this->keyEquals($parent['key'], 'libtorrent_resume')) {
				return('resume');
			}
		}
		return($parent['projection'] === 'resume' &&
			$this->keyEquals($parent['key'], 'trackers') ? 'all' : 'none');
	}

	private function retainTrackerNode($last, $value, $key = null)
	{
		$mode = $this->stack[$last]['projection'];
		if ($mode !== 'all' && !($mode === 'resume' && is_array($key) &&
			$this->keyEquals($key, 'trackers'))) {
			return;
		}
		if (++$this->trackerProjectionNodes > self::MAX_TRACKER_PROJECTION_NODES) {
			$this->fail('runtime-tracker-topology-mismatch');
		}
		$this->stack[$last]['children'][] = $key === null ? $value :
			array('key' => $key, 'value' => $value);
	}

	private function fail($reason = 'source-bencode-invalid')
	{
		throw new RetrackersBencodeError($reason);
	}

	private function countOne()
	{
		if ($this->complexity >= self::MAX_VALUES_AND_KEYS) {
			$this->fail('source-too-complex');
		}
		$this->complexity++;
	}

	private function parseStringToken()
	{
		$encodedOffset = $this->offset;
		if ($encodedOffset >= $this->length || $this->capture[$encodedOffset] < '0' ||
			$this->capture[$encodedOffset] > '9') {
			$this->fail();
		}
		$length = 0;
		if ($this->capture[$this->offset] === '0') {
			$this->offset++;
			if ($this->offset >= $this->length || $this->capture[$this->offset] !== ':') {
				$this->fail();
			}
		} else {
			while ($this->offset < $this->length && $this->capture[$this->offset] >= '0' &&
				$this->capture[$this->offset] <= '9') {
				$digit = ord($this->capture[$this->offset]) - 48;
				if ($length > intdiv(RETRACKERS_METAINFO_CAP - $digit, 10)) {
					$this->fail();
				}
				$length = ($length * 10) + $digit;
				$this->offset++;
			}
			if ($this->offset >= $this->length || $this->capture[$this->offset] !== ':') {
				$this->fail();
			}
		}
		$this->offset++;
		$payloadOffset = $this->offset;
		if ($length > $this->length - $payloadOffset) {
			$this->fail();
		}
		$this->offset += $length;
		return(array(
			'encoded_offset' => $encodedOffset,
			'encoded_length' => $this->offset - $encodedOffset,
			'offset' => $payloadOffset,
			'length' => $length,
		));
	}

	private function parseInteger()
	{
		$start = $this->offset;
		$this->offset++;
		$negative = false;
		if ($this->offset < $this->length && $this->capture[$this->offset] === '-') {
			$negative = true;
			$this->offset++;
		}
		if ($this->offset >= $this->length) {
			$this->fail();
		}
		if ($this->capture[$this->offset] === '0') {
			if ($negative) {
				$this->fail();
			}
			$this->offset++;
			if ($this->offset >= $this->length || $this->capture[$this->offset] !== 'e') {
				$this->fail();
			}
		} else {
			if ($this->capture[$this->offset] < '1' || $this->capture[$this->offset] > '9') {
				$this->fail();
			}
			while ($this->offset < $this->length && $this->capture[$this->offset] >= '0' &&
				$this->capture[$this->offset] <= '9') {
				$this->offset++;
			}
			if ($this->offset >= $this->length || $this->capture[$this->offset] !== 'e') {
				$this->fail();
			}
		}
		$this->offset++;
		return(array('offset' => $start, 'length' => $this->offset - $start, 'type' => 'integer'));
	}

	private function startValue()
	{
		$this->countOne();
		if ($this->offset >= $this->length) {
			$this->fail();
		}
		$start = $this->offset;
		$type = $this->capture[$this->offset];
		if ($type >= '0' && $type <= '9') {
			$string = $this->parseStringToken();
			return(array(
				'offset' => $string['encoded_offset'],
				'length' => $string['encoded_length'],
				'type' => 'string',
				'payload_offset' => $string['offset'],
				'payload_length' => $string['length'],
			));
		}
		if ($type === 'i') {
			return($this->parseInteger());
		}
		if ($type !== 'l' && $type !== 'd') {
			$this->fail();
		}
		if (count($this->stack) >= self::MAX_DEPTH) {
			$this->fail('source-too-deep');
		}
		$this->offset++;
		$projection = $this->projectionMode();
		if ($type === 'l') {
			$this->stack[] = array('type' => 'list', 'start' => $start,
				'projection' => $projection, 'children' => array());
		} else {
			$this->stack[] = array(
				'type' => 'dictionary',
				'start' => $start,
				'state' => 'key',
				'key' => null,
				'keys' => new RetrackersBencodeKeySet($this->capture),
				'projection' => $projection,
				'children' => array(),
			);
		}
		return(null);
	}

	private function keyEquals($descriptor, $literal)
	{
		return($descriptor['length'] === strlen($literal) &&
			substr_compare($this->capture, $literal, $descriptor['offset'], $descriptor['length']) === 0);
	}

	private function deliver($value)
	{
		if (!count($this->stack)) {
			if ($this->root !== null) {
				$this->fail();
			}
			$this->root = $value;
			return;
		}
		$last = count($this->stack) - 1;
		if ($this->stack[$last]['type'] === 'list') {
			$this->retainTrackerNode($last, $value);
			return;
		}
		if ($this->stack[$last]['state'] !== 'value' ||
			!is_array($this->stack[$last]['key'])) {
			$this->fail();
		}
		$key = $this->stack[$last]['key'];
		$this->retainTrackerNode($last, $value, $key);
		if ($last === 0) {
			$entry = array(
				'key' => $key,
				'value' => $value,
				'pair' => array(
					'offset' => $key['encoded_offset'],
					'length' => ($value['offset'] + $value['length']) - $key['encoded_offset'],
				),
			);
			$this->top[] = $entry;
			if ($this->keyEquals($key, 'info')) {
				$this->info = $value;
			}
		}
		$this->stack[$last]['key'] = null;
		$this->stack[$last]['state'] = 'key';
	}

	private function hashSpan($descriptor, $algorithm)
	{
		$context = hash_init($algorithm);
		$offset = $descriptor['offset'];
		$remaining = $descriptor['length'];
		while ($remaining > 0) {
			$take = min(RetrackersBoundedFileReader::READ_BYTES, $remaining);
			hash_update($context, substr($this->capture, $offset, $take));
			$offset += $take;
			$remaining -= $take;
		}
		return(strtoupper(hash_final($context)));
	}

	public function scan($capture, $expectedHash, $collectTrackers = false)
	{
		$this->collectTrackers = $collectTrackers;
		$this->trackerProjectionNodes = 0;
		if (!is_string($capture) || strlen($capture) > RETRACKERS_METAINFO_CAP) {
			return(array('ok' => false, 'reason' => 'source-too-large'));
		}
		$this->capture = $capture;
		$this->length = strlen($capture);
		$this->offset = 0;
		$this->complexity = 0;
		$this->stack = array();
		$this->root = null;
		$this->top = array();
		$this->info = null;
		$completed = null;
		try {
			while (true) {
				if ($completed !== null) {
					$this->deliver($completed);
					$completed = null;
					continue;
				}
				if (!count($this->stack)) {
					if ($this->root !== null) {
						if ($this->offset !== $this->length) {
							$this->fail();
						}
						break;
					}
					$completed = $this->startValue();
					continue;
				}

				$last = count($this->stack) - 1;
				if ($this->stack[$last]['type'] === 'list') {
					if ($this->offset >= $this->length) {
						$this->fail();
					}
					if ($this->capture[$this->offset] === 'e') {
						$start = $this->stack[$last]['start'];
						$this->offset++;
						$frame = array_pop($this->stack);
						$completed = array('offset' => $start,
							'length' => $this->offset - $start, 'type' => 'list');
						if ($frame['projection'] !== 'none') {
							$completed['children'] = $frame['children'];
						}
					} else {
						$completed = $this->startValue();
					}
					continue;
				}

				if ($this->stack[$last]['state'] === 'key') {
					if ($this->offset >= $this->length) {
						$this->fail();
					}
					if ($this->capture[$this->offset] === 'e') {
						$start = $this->stack[$last]['start'];
						$this->offset++;
						$frame = array_pop($this->stack);
						$completed = array('offset' => $start,
							'length' => $this->offset - $start, 'type' => 'dictionary');
						if ($frame['projection'] !== 'none') {
							$completed['children'] = $frame['children'];
						}
						continue;
					}
					$this->countOne();
					$key = $this->parseStringToken();
					$this->stack[$last]['keys']->add($key['offset'], $key['length']);
					$this->stack[$last]['key'] = $key;
					$this->stack[$last]['state'] = 'value';
					continue;
				}
				if ($this->offset >= $this->length || $this->capture[$this->offset] === 'e') {
					$this->fail();
				}
				$completed = $this->startValue();
			}

			if (!is_array($this->root) || $this->root['type'] !== 'dictionary' ||
				!is_array($this->info)) {
				$this->fail();
			}
			$infoHash = $this->hashSpan($this->info, 'sha1');
			if (!is_string($expectedHash) || preg_match('/^[0-9A-F]{40}$/D', $expectedHash) !== 1 ||
				!hash_equals($expectedHash, $infoHash)) {
				$this->fail('source-hash-mismatch');
			}
			return(array(
				'ok' => true,
				'root' => $this->root,
				'top' => $this->top,
				'info' => $this->info,
				'info_hash' => $infoHash,
				'complexity' => $this->complexity,
			));
		} catch (RetrackersBencodeError $error) {
			return(array('ok' => false, 'reason' => $error->getMessage()));
		}
	}
}

class RetrackersBoundedLength
{
	private $total = 0;

	public function add($part)
	{
		if (!is_int($part) || $part < 0 || $part > RETRACKERS_METAINFO_CAP ||
			$this->total > RETRACKERS_METAINFO_CAP - $part) {
			return(false);
		}
		$this->total += $part;
		return(true);
	}

	public function total()
	{
		return($this->total);
	}
}

class RetrackersBoundedAppender
{
	private $expectedLength;
	private $output = '';
	private $valid;
	private $maximumChunk = 0;

	public function __construct($expectedLength)
	{
		$this->valid = is_int($expectedLength) && $expectedLength >= 0 &&
			$expectedLength <= RETRACKERS_METAINFO_CAP;
		$this->expectedLength = $this->valid ? $expectedLength : 0;
	}

	private function appendChunk($chunk)
	{
		$length = strlen($chunk);
		$current = strlen($this->output);
		if (!$this->valid || $length > RetrackersBoundedFileReader::READ_BYTES ||
			$length > RETRACKERS_METAINFO_CAP - $current ||
			$length > $this->expectedLength - $current) {
			$this->valid = false;
			return(false);
		}
		$this->output .= $chunk;
		$this->maximumChunk = max($this->maximumChunk, $length);
		return(true);
	}

	public function appendBytes($bytes)
	{
		if (!is_string($bytes) || !$this->valid) {
			$this->valid = false;
			return(false);
		}
		$offset = 0;
		$remaining = strlen($bytes);
		while ($remaining > 0) {
			$take = min(RetrackersBoundedFileReader::READ_BYTES, $remaining);
			if (!$this->appendChunk(substr($bytes, $offset, $take))) {
				return(false);
			}
			$offset += $take;
			$remaining -= $take;
		}
		return(true);
	}

	public function appendSpan($source, $offset, $length)
	{
		if (!is_string($source) || !is_int($offset) || !is_int($length) ||
			$offset < 0 || $length < 0 || $offset > strlen($source) ||
			$length > strlen($source) - $offset || !$this->valid) {
			$this->valid = false;
			return(false);
		}
		while ($length > 0) {
			$take = min(RetrackersBoundedFileReader::READ_BYTES, $length);
			if (!$this->appendChunk(substr($source, $offset, $take))) {
				return(false);
			}
			$offset += $take;
			$length -= $take;
		}
		return(true);
	}

	public function bytes()
	{
		return($this->output);
	}

	public function finish()
	{
		return($this->valid && strlen($this->output) === $this->expectedLength ?
			$this->output : false);
	}

	public function maxChunkLength()
	{
		return($this->maximumChunk);
	}
}

class RetrackersTorrentProjector
{
	protected function constructTorrent($capture)
	{
		// Torrent normally treats any matching string as a pathname; this closed
		// subclass forces the already captured authority through its byte decoder.
		return(new class($capture) extends Torrent
		{
			protected function build($data, $pieceLength)
			{
				return(false);
			}

			public function decode($bytes)
			{
				$setData = Closure::bind(function ($value) {
					$this->data = $value;
				}, $this, 'Torrent');
				$setData($bytes);
				$this->pointer = 0;
				return($this->decode_data());
			}
		});
	}

	private function dense($value)
	{
		return(is_array($value) && array_keys($value) ===
			(count($value) ? range(0, count($value) - 1) : array()));
	}

	private function trackerList($value, $emptyGroups)
	{
		if (!$this->dense($value)) {
			return(false);
		}
		foreach ($value as $group) {
			if (!$this->dense($group) || (!$emptyGroups && !count($group))) {
				return(false);
			}
			foreach ($group as $tracker) {
				if (!is_string($tracker)) {
					return(false);
				}
			}
		}
		return(true);
	}

	private function deletionList($value)
	{
		if (!$this->dense($value)) {
			return(false);
		}
		foreach ($value as $tracker) {
			if (!is_string($tracker) || strlen($tracker) === 0) {
				return(false);
			}
		}
		return(true);
	}

	private function normalizeTrackerList($value)
	{
		$list = array();
		foreach ($value as $group) {
			$list[] = array_values($group);
		}
		return($list);
	}

	public function project($capture, $additions, $deletions, $addToBegin)
	{
		if (!is_string($capture) || !$this->trackerList($additions, false) ||
			!$this->deletionList($deletions) || !is_bool($addToBegin)) {
			return(array('ok' => false, 'reason' => 'candidate-tracker-projection-failed'));
		}
		try {
			$torrent = $this->constructTorrent($capture);
		} catch (Throwable $error) {
			return(array('ok' => false, 'reason' => 'source-decode-failed'));
		}
		if (!is_object($torrent) || !method_exists($torrent, 'errors') || $torrent->errors()) {
			return(array('ok' => false, 'reason' => 'source-decode-failed'));
		}
		if (!method_exists($torrent, 'announce') || !method_exists($torrent, 'announce_list')) {
			return(array('ok' => false, 'reason' => 'source-decode-failed'));
		}
		$announce = $torrent->announce();
		$announceList = $torrent->announce_list();
		if ((!is_null($announce) && !is_string($announce)) ||
			(!is_null($announceList) && !$this->trackerList($announceList, true))) {
			return(array('ok' => false, 'reason' => 'candidate-tracker-projection-failed'));
		}

		$wasAddition = true;
		$list = $announceList;
		if (!$list) {
			if (count($additions)) {
				if ($announce) {
					$torrent->announce_list($addToBegin ?
						array_merge($additions, array(array($announce))) :
						array_merge(array(array($announce)), $additions));
				} else {
					$torrent->announce($additions[0][0]);
					$torrent->announce_list($additions);
				}
			} else {
				$wasAddition = false;
			}
		} else {
			$addition = $additions;
			foreach ($list as $group) {
				foreach ($group as $tracker) {
					$addition = clearTracker($addition, $tracker);
				}
			}
			if (count($addition)) {
				$addition = $this->normalizeTrackerList($addition);
				$torrent->announce_list($addToBegin ?
					array_merge($addition, $list) : array_merge($list, $addition));
			} else {
				$wasAddition = false;
			}
		}

		$wasDeletion = false;
		$list = $torrent->announce_list();
		if ($list && count($deletions) && deleteTrackers($list, $deletions)) {
			$wasDeletion = true;
			$torrent->announce_list($this->normalizeTrackerList($list));
		}
		$announce = $torrent->announce();
		$announceList = $torrent->announce_list();
		if ((!is_null($announce) && !is_string($announce)) ||
			(!is_null($announceList) && !$this->trackerList($announceList, true))) {
			return(array('ok' => false, 'reason' => 'candidate-tracker-projection-failed'));
		}
		return(array(
			'ok' => true,
			'changed' => $wasAddition || $wasDeletion,
			'announce' => $announce,
			'announce_list' => $announceList,
		));
	}
}

class RetrackersCandidateBuilder
{
	private $scanner;

	public function __construct($scanner = null)
	{
		$this->scanner = $scanner === null ? new RetrackersBencodeScanner() : $scanner;
	}

	private function rawKeyEquals($capture, $entry, $literal)
	{
		$key = $entry['key'];
		return($key['length'] === strlen($literal) &&
			substr_compare($capture, $literal, $key['offset'], $key['length']) === 0);
	}

	private function keyLength($entry)
	{
		return($entry['kind'] === 'raw' ? $entry['source']['key']['length'] : strlen($entry['key']));
	}

	private function keyByte($capture, $entry, $offset)
	{
		return($entry['kind'] === 'raw' ?
			$capture[$entry['source']['key']['offset'] + $offset] : $entry['key'][$offset]);
	}

	private function compareKeys($capture, $left, $right)
	{
		$leftLength = $this->keyLength($left);
		$rightLength = $this->keyLength($right);
		$common = min($leftLength, $rightLength);
		for ($offset = 0; $offset < $common; $offset++) {
			$leftByte = ord($this->keyByte($capture, $left, $offset));
			$rightByte = ord($this->keyByte($capture, $right, $offset));
			if ($leftByte !== $rightByte) {
				return($leftByte < $rightByte ? -1 : 1);
			}
		}
		return($leftLength === $rightLength ? 0 : ($leftLength < $rightLength ? -1 : 1));
	}

	private function logicalPlan($capture, $sourceScan, $projection)
	{
		if (!is_array($sourceScan) || !isset($sourceScan['ok']) || $sourceScan['ok'] !== true ||
			!is_array($projection) || !isset($projection['ok'], $projection['changed']) ||
			$projection['ok'] !== true || $projection['changed'] !== true ||
			!array_key_exists('announce', $projection) ||
			!array_key_exists('announce_list', $projection)) {
			return(false);
		}
		$plan = array();
		foreach ($sourceScan['top'] as $entry) {
			if ($this->rawKeyEquals($capture, $entry, 'announce') ||
				$this->rawKeyEquals($capture, $entry, 'announce-list') ||
				$this->rawKeyEquals($capture, $entry, 'rtorrent')) {
				continue;
			}
			$plan[] = array('kind' => 'raw', 'source' => $entry);
		}
		if (is_string($projection['announce'])) {
			$plan[] = array('kind' => 'generated', 'key' => 'announce',
				'value_type' => 'string', 'value' => $projection['announce']);
		} elseif (!is_null($projection['announce'])) {
			return(false);
		}
		if (is_array($projection['announce_list'])) {
			$plan[] = array('kind' => 'generated', 'key' => 'announce-list',
				'value_type' => 'tracker-list', 'value' => $projection['announce_list']);
		} elseif (!is_null($projection['announce_list'])) {
			return(false);
		}
		usort($plan, function ($left, $right) use ($capture) {
			return($this->compareKeys($capture, $left, $right));
		});
		return($plan);
	}

	private function addStringLength($string, $length)
	{
		$bytes = strlen($string);
		return($bytes <= RETRACKERS_METAINFO_CAP &&
			$length->add(strlen((string)$bytes) + 1) && $length->add($bytes));
	}

	private function addTrackerValueLength($entry, $length)
	{
		if ($entry['value_type'] === 'string') {
			return($this->addStringLength($entry['value'], $length));
		}
		if (!$length->add(1)) {
			return(false);
		}
		foreach ($entry['value'] as $group) {
			if (!$length->add(1)) {
				return(false);
			}
			foreach ($group as $tracker) {
				if (!is_string($tracker) || !$this->addStringLength($tracker, $length)) {
					return(false);
				}
			}
			if (!$length->add(1)) {
				return(false);
			}
		}
		return($length->add(1));
	}

	private function measure($plan)
	{
		$length = new RetrackersBoundedLength();
		if (!$length->add(1)) {
			return(false);
		}
		foreach ($plan as $entry) {
			if ($entry['kind'] === 'raw') {
				if (!$length->add($entry['source']['pair']['length'])) {
					return(false);
				}
			} elseif (!$this->addStringLength($entry['key'], $length) ||
				!$this->addTrackerValueLength($entry, $length)) {
				return(false);
			}
		}
		return($length->add(1) ? $length->total() : false);
	}

	private function appendString($string, $appender)
	{
		return($appender->appendBytes(strlen($string) . ':') &&
			$appender->appendBytes($string));
	}

	private function appendTrackerValue($entry, $appender)
	{
		if ($entry['value_type'] === 'string') {
			return($this->appendString($entry['value'], $appender));
		}
		if (!$appender->appendBytes('l')) {
			return(false);
		}
		foreach ($entry['value'] as $group) {
			if (!$appender->appendBytes('l')) {
				return(false);
			}
			foreach ($group as $tracker) {
				if (!$this->appendString($tracker, $appender)) {
					return(false);
				}
			}
			if (!$appender->appendBytes('e')) {
				return(false);
			}
		}
		return($appender->appendBytes('e'));
	}

	private function sameSpan($left, $leftOffset, $right, $rightOffset, $length)
	{
		while ($length > 0) {
			$take = min(RetrackersBoundedFileReader::READ_BYTES, $length);
			$chunk = substr($left, $leftOffset, $take);
			if (substr_compare($right, $chunk, $rightOffset, $take) !== 0) {
				return(false);
			}
			$leftOffset += $take;
			$rightOffset += $take;
			$length -= $take;
		}
		return(true);
	}

	private function candidateKeyMatches($source, $expected, $candidate, $actual)
	{
		$length = $this->keyLength($expected);
		if ($actual['key']['length'] !== $length) {
			return(false);
		}
		for ($offset = 0; $offset < $length; $offset++) {
			if ($this->keyByte($source, $expected, $offset) !==
				$candidate[$actual['key']['offset'] + $offset]) {
				return(false);
			}
		}
		return(true);
	}

	private function matchLiteral($candidate, &$offset, $end, $literal)
	{
		$length = strlen($literal);
		if ($length > $end - $offset ||
			substr_compare($candidate, $literal, $offset, $length) !== 0) {
			return(false);
		}
		$offset += $length;
		return(true);
	}

	private function matchPayload($candidate, &$offset, $end, $payload)
	{
		$remaining = strlen($payload);
		$payloadOffset = 0;
		if ($remaining > $end - $offset) {
			return(false);
		}
		while ($remaining > 0) {
			$take = min(RetrackersBoundedFileReader::READ_BYTES, $remaining);
			$chunk = substr($payload, $payloadOffset, $take);
			if (substr_compare($candidate, $chunk, $offset, $take) !== 0) {
				return(false);
			}
			$offset += $take;
			$payloadOffset += $take;
			$remaining -= $take;
		}
		return(true);
	}

	private function matchString($candidate, &$offset, $end, $payload)
	{
		return($this->matchLiteral($candidate, $offset, $end, strlen($payload) . ':') &&
			$this->matchPayload($candidate, $offset, $end, $payload));
	}

	private function trackerValueMatches($candidate, $actual, $expected)
	{
		$offset = $actual['value']['offset'];
		$end = $offset + $actual['value']['length'];
		if ($expected['value_type'] === 'string') {
			return($this->matchString($candidate, $offset, $end, $expected['value']) &&
				$offset === $end);
		}
		if (!$this->matchLiteral($candidate, $offset, $end, 'l')) {
			return(false);
		}
		foreach ($expected['value'] as $group) {
			if (!$this->matchLiteral($candidate, $offset, $end, 'l')) {
				return(false);
			}
			foreach ($group as $tracker) {
				if (!$this->matchString($candidate, $offset, $end, $tracker)) {
					return(false);
				}
			}
			if (!$this->matchLiteral($candidate, $offset, $end, 'e')) {
				return(false);
			}
		}
		return($this->matchLiteral($candidate, $offset, $end, 'e') && $offset === $end);
	}

	public function validateCandidate($source, $sourceScan, $candidate, $projection, $expectedHash)
	{
		if (!is_string($source) || !is_string($candidate)) {
			return(array('ok' => false, 'reason' => 'candidate-hash-mismatch'));
		}
		$candidateScan = $this->scanner->scan($candidate, $expectedHash);
		if (!isset($candidateScan['ok']) || $candidateScan['ok'] !== true) {
			return(array('ok' => false, 'reason' => 'candidate-hash-mismatch'));
		}
		$plan = $this->logicalPlan($source, $sourceScan, $projection);
		if (!is_array($plan) || count($plan) !== count($candidateScan['top'])) {
			return(array('ok' => false, 'reason' => 'candidate-hash-mismatch'));
		}
		foreach ($plan as $index => $expected) {
			$actual = $candidateScan['top'][$index];
			if (!$this->candidateKeyMatches($source, $expected, $candidate, $actual)) {
				return(array('ok' => false, 'reason' => 'candidate-hash-mismatch'));
			}
			if ($expected['kind'] === 'raw') {
				if ($expected['source']['pair']['length'] !== $actual['pair']['length'] ||
					!$this->sameSpan($source, $expected['source']['pair']['offset'],
						$candidate, $actual['pair']['offset'], $actual['pair']['length'])) {
					return(array('ok' => false, 'reason' => 'candidate-hash-mismatch'));
				}
			} elseif (!$this->trackerValueMatches($candidate, $actual, $expected)) {
				return(array('ok' => false, 'reason' => 'candidate-tracker-projection-failed'));
			}
		}
		return(array('ok' => true, 'candidate_scan' => $candidateScan));
	}

	public function build($source, $sourceScan, $projection, $expectedHash)
	{
		$plan = $this->logicalPlan($source, $sourceScan, $projection);
		if (!is_array($plan)) {
			return(array('ok' => false, 'reason' => 'candidate-tracker-projection-failed'));
		}
		$measured = $this->measure($plan);
		if ($measured === false) {
			return(array('ok' => false, 'reason' => 'candidate-too-large'));
		}
		$appender = new RetrackersBoundedAppender($measured);
		if (!$appender->appendBytes('d')) {
			return(array('ok' => false, 'reason' => 'candidate-too-large'));
		}
		foreach ($plan as $entry) {
			if ($entry['kind'] === 'raw') {
				if (!$appender->appendSpan($source, $entry['source']['pair']['offset'],
					$entry['source']['pair']['length'])) {
					return(array('ok' => false, 'reason' => 'candidate-too-large'));
				}
			} elseif (!$this->appendString($entry['key'], $appender) ||
				!$this->appendTrackerValue($entry, $appender)) {
				return(array('ok' => false, 'reason' => 'candidate-too-large'));
			}
		}
		if (!$appender->appendBytes('e') || ($candidate = $appender->finish()) === false) {
			return(array('ok' => false, 'reason' => 'candidate-too-large'));
		}
		$validated = $this->validateCandidate($source, $sourceScan, $candidate,
			$projection, $expectedHash);
		if ($validated['ok'] !== true) {
			$candidate = '';
			return($validated);
		}
		return(array('ok' => true, 'candidate' => $candidate,
			'candidate_scan' => $validated['candidate_scan'], 'length' => $measured));
	}
}

class RetrackersMetainfoAuthority
{
	private $reader;
	private $scanner;
	private $projector;
	private $builder;

	public function __construct($reader = null, $scanner = null, $projector = null, $builder = null)
	{
		$this->reader = $reader === null ? new RetrackersBoundedFileReader() : $reader;
		$this->scanner = $scanner === null ? new RetrackersBencodeScanner() : $scanner;
		$this->projector = $projector === null ? new RetrackersTorrentProjector() : $projector;
		$this->builder = $builder === null ? new RetrackersCandidateBuilder() : $builder;
	}

	public function prepare($path, $expectedHash, $additions, $deletions, $addToBegin, $snapshot = null)
	{
		$read = $this->reader->capture($path);
		if (!isset($read['ok']) || $read['ok'] !== true) {
			return($read);
		}
		$capture = $read['bytes'];
		$sourceScan = $this->scanner->scan($capture, $expectedHash, $snapshot !== null);
		if (!isset($sourceScan['ok']) || $sourceScan['ok'] !== true) {
			return($sourceScan);
		}
		$projection = $this->projector->project($capture, $additions, $deletions, $addToBegin);
		if (!isset($projection['ok']) || $projection['ok'] !== true) {
			$projection['original'] = $capture;
			return($projection);
		}
		if (!$projection['changed']) {
			return(array('ok' => true, 'changed' => false, 'original' => $capture,
				'candidate' => null, 'source_scan' => $sourceScan));
		}
		$eligibilityFailure = null;
		if ($snapshot !== null && (!is_array($snapshot) ||
			!RetrackersPostEraseObligation::sourceEligible(
				$snapshot, $capture, $sourceScan, $projection, $eligibilityFailure))) {
			return(array('ok' => false, 'reason' => 'runtime-tracker-topology-mismatch'));
		}
		$built = $this->builder->build($capture, $sourceScan, $projection, $expectedHash);
		if (!isset($built['ok']) || $built['ok'] !== true) {
			$built['original'] = $capture;
			return($built);
		}
		return(array(
			'ok' => true,
			'changed' => true,
			'original' => $capture,
			'candidate' => $built['candidate'],
			'projection' => $projection,
			'source_scan' => $sourceScan,
			'candidate_scan' => $built['candidate_scan'],
		));
	}
}

class RetrackersRestrictedCodecError extends Exception
{
}

class RetrackersRestrictedLedgerError extends RetrackersRestrictedCodecError
{
}

class RetrackersRestrictedXmlCursor
{
	const MAX_VALUES = 262144;
	const MAX_OPAQUE_VALUES = 65536;
	const MAX_MEMBERS = 12288;
	const MAX_NAME_BYTES = 4096;
	const MAX_SCALAR_BYTES = 1048576;
	const MAX_TEXT_BYTES = 8388608;
	const MAX_RETAINED_NAME_BYTES = 2097152;
	const MAX_PROJECTED_BYTES = 8388608;

	private $raw;
	private $end;
	private $offset;
	private $family;
	private $values = 0;
	private $opaqueValues = 0;
	private $members = 0;
	private $textBytes = 0;
	private $retainedNameBytes = 0;
	private $projectedBytes = 0;

	public function __construct($raw, $offset, $end, $family)
	{
		$this->raw = $raw;
		$this->offset = $offset;
		$this->end = $end;
		$this->family = $family;
	}

	public function family()
	{
		return($this->family);
	}

	public function finished()
	{
		return($this->offset === $this->end);
	}

	public function at($literal)
	{
		$length = strlen($literal);
		return(($length <= $this->end - $this->offset) &&
			substr_compare($this->raw, $literal, $this->offset, $length) === 0);
	}

	public function take($literal)
	{
		if (!$this->at($literal)) {
			throw new RetrackersRestrictedCodecError('unexpected-xml');
		}
		$this->offset += strlen($literal);
	}

	public function line()
	{
		if ($this->family === 1) {
			$this->take("\r\n");
		}
	}

	private function addBounded(&$counter, $amount, $limit)
	{
		if (!is_int($amount) || $amount < 0 || $amount > $limit - $counter) {
			throw new RetrackersRestrictedCodecError('limit-exceeded');
		}
		$counter += $amount;
	}

	public function beginValue($opaque = false)
	{
		$this->addBounded($this->values, 1, self::MAX_VALUES);
		if ($opaque) {
			$this->addBounded($this->opaqueValues, 1, self::MAX_OPAQUE_VALUES);
		}
		$this->take('<value>');
	}

	public function beginMember()
	{
		$this->addBounded($this->members, 1, self::MAX_MEMBERS);
		$this->take('<member>');
	}

	public function chargeProjected($bytes)
	{
		$this->addBounded($this->projectedBytes, $bytes, self::MAX_PROJECTED_BYTES);
	}

	public function openArray()
	{
		if ($this->family === 2 && $this->at('<array><data/></array>')) {
			$this->take('<array><data/></array>');
			return(true);
		}
		$this->take('<array><data>');
		$this->line();
		if ($this->family === 2 && $this->at('</data></array>')) {
			throw new RetrackersRestrictedCodecError('noncanonical-empty-array');
		}
		return(false);
	}

	public function closeArray()
	{
		$this->take('</data></array>');
	}

	public function openStruct()
	{
		if ($this->family === 2 && $this->at('<struct/>')) {
			$this->take('<struct/>');
			return(true);
		}
		$this->take('<struct>');
		$this->line();
		if ($this->family === 2 && $this->at('</struct>')) {
			throw new RetrackersRestrictedCodecError('noncanonical-empty-struct');
		}
		return(false);
	}

	public function closeStruct()
	{
		$this->take('</struct>');
	}

	private function xmlCodePoint($semicolon)
	{
		if ($this->offset + 1 < $semicolon && $this->raw[$this->offset + 1] === '#') {
			$base = 10;
			$index = $this->offset + 2;
			if ($index < $semicolon && $this->raw[$index] === 'x') {
				$base = 16;
				$index++;
			}
			if ($index >= $semicolon) {
				throw new RetrackersRestrictedCodecError('invalid-reference');
			}
			$code = 0;
			for (; $index < $semicolon; $index++) {
				$byte = ord($this->raw[$index]);
				if ($byte >= 48 && $byte <= 57) {
					$digit = $byte - 48;
				} elseif ($base === 16 && $byte >= 65 && $byte <= 70) {
					$digit = $byte - 55;
				} elseif ($base === 16 && $byte >= 97 && $byte <= 102) {
					$digit = $byte - 87;
				} else {
					throw new RetrackersRestrictedCodecError('invalid-reference');
				}
				if ($code > intdiv(1114111 - $digit, $base)) {
					throw new RetrackersRestrictedCodecError('invalid-reference');
				}
				$code = $code * $base + $digit;
			}
			if (!(($code === 9) || ($code === 10) || ($code === 13) ||
				($code >= 32 && $code <= 55295) || ($code >= 57344 && $code <= 65533) ||
				($code >= 65536 && $code <= 1114111))) {
				throw new RetrackersRestrictedCodecError('invalid-reference');
			}
			if ($code === 13 || $code === 0) {
				throw new RetrackersRestrictedCodecError('forbidden-scalar-byte');
			}
			if ($code <= 127) {
				return(chr($code));
			}
			if ($code <= 2047) {
				return(chr(192 | ($code >> 6)) . chr(128 | ($code & 63)));
			}
			if ($code <= 65535) {
				return(chr(224 | ($code >> 12)) . chr(128 | (($code >> 6) & 63)) . chr(128 | ($code & 63)));
			}
			return(chr(240 | ($code >> 18)) . chr(128 | (($code >> 12) & 63)) .
				chr(128 | (($code >> 6) & 63)) . chr(128 | ($code & 63)));
		}
		if ($semicolon - $this->offset > 6) {
			throw new RetrackersRestrictedCodecError('invalid-entity');
		}
		$entity = substr($this->raw, $this->offset, $semicolon - $this->offset + 1);
		$predefined = array(
			'&amp;' => '&',
			'&lt;' => '<',
			'&gt;' => '>',
			'&apos;' => "'",
			'&quot;' => '"',
		);
		if (!array_key_exists($entity, $predefined)) {
			throw new RetrackersRestrictedCodecError('invalid-entity');
		}
		return($predefined[$entity]);
	}

	private function chargeText($bytes, $name, &$local)
	{
		$limit = $name ? self::MAX_NAME_BYTES : self::MAX_SCALAR_BYTES;
		$this->addBounded($local, $bytes, $limit);
		$this->addBounded($this->textBytes, $bytes, self::MAX_TEXT_BYTES);
		if ($name) {
			$this->addBounded($this->retainedNameBytes, $bytes, self::MAX_RETAINED_NAME_BYTES);
		}
	}

	public function text($tag, $name = false)
	{
		$this->take('<' . $tag . '>');
		$closing = '</' . $tag . '>';
		$decoded = '';
		$local = 0;
		while (!$this->at($closing)) {
			if ($this->offset >= $this->end) {
				throw new RetrackersRestrictedCodecError('truncated-text');
			}
			$byte = $this->raw[$this->offset];
			if ($byte === '<') {
				throw new RetrackersRestrictedCodecError('raw-markup-in-text');
			}
			if ($byte === '&') {
				$semicolon = strpos($this->raw, ';', $this->offset + 1);
				if ($semicolon === false || $semicolon >= $this->end) {
					throw new RetrackersRestrictedCodecError('invalid-entity');
				}
				$chunk = $this->xmlCodePoint($semicolon);
				$this->chargeText(strlen($chunk), $name, $local);
				$decoded .= $chunk;
				$this->offset = $semicolon + 1;
				continue;
			}
			if ($byte === "\r" || $byte === "\0") {
				throw new RetrackersRestrictedCodecError('forbidden-scalar-byte');
			}
			$code = ord($byte);
			if ($code < 32 && $byte !== "\t" && $byte !== "\n") {
				throw new RetrackersRestrictedCodecError('invalid-xml-character');
			}
			$length = strcspn($this->raw, "<&\r\0", $this->offset, $this->end - $this->offset);
			if ($length <= 0) {
				throw new RetrackersRestrictedCodecError('invalid-text');
			}
			$this->chargeText($length, $name, $local);
			$chunk = substr($this->raw, $this->offset, $length);
			if (strpos($chunk, ']]>') !== false) {
				throw new RetrackersRestrictedCodecError('invalid-text');
			}
			$decoded .= $chunk;
			$this->offset += $length;
		}
		$this->take($closing);
		if (preg_match('//u', $decoded) !== 1 || preg_match('/[\x{FFFE}\x{FFFF}]/u', $decoded) === 1) {
			throw new RetrackersRestrictedCodecError('invalid-utf8');
		}
		return($decoded);
	}

	public function integer($tag)
	{
		$this->take('<' . $tag . '>');
		$closing = '</' . $tag . '>';
		$end = strpos($this->raw, $closing, $this->offset);
		if ($end === false || $end >= $this->end) {
			throw new RetrackersRestrictedCodecError('truncated-integer');
		}
		$length = $end - $this->offset;
		$local = 0;
		$this->chargeText($length, false, $local);
		if ($length < 1 || $length > 20) {
			throw new RetrackersRestrictedCodecError('invalid-integer');
		}
		$lexeme = substr($this->raw, $this->offset, $length);
		if (preg_match('/^(?:0|-[1-9][0-9]*|[1-9][0-9]*)$/D', $lexeme) !== 1 ||
			!retrackersRestrictedIntegerInRange($lexeme, $tag)) {
			throw new RetrackersRestrictedCodecError('invalid-integer');
		}
		$this->offset = $end;
		$this->take($closing);
		return($lexeme);
	}
}

function retrackersRestrictedIntegerInRange($lexeme, $tag)
{
	$negative = isset($lexeme[0]) && $lexeme[0] === '-';
	$digits = $negative ? substr($lexeme, 1) : $lexeme;
	if ($tag === 'i4') {
		$maximum = $negative ? '2147483648' : '2147483647';
	} elseif ($tag === 'i8') {
		$maximum = $negative ? '9223372036854775808' : '9223372036854775807';
	} else {
		return(false);
	}
	return(strlen($digits) < strlen($maximum) ||
		(strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) <= 0));
}

function retrackersRestrictedPlanMethodNames()
{
	return(array('mode' => 'method-names'));
}

function retrackersRestrictedPlanDirectScalar($type)
{
	return(array('mode' => 'direct-scalar', 'type' => $type));
}

function retrackersRestrictedPlanFamilyMutationScalar()
{
	return(array('mode' => 'family-mutation-scalar'));
}

function retrackersRestrictedPlanLedgerHas()
{
	return(array('mode' => 'direct-ledger-has'));
}

function retrackersRestrictedPlanDownloadRows()
{
	return(array('mode' => 'download-local-id-rows'));
}

function retrackersRestrictedPlanTrackerRows()
{
	return(array('mode' => 'tracker-five-column-rows'));
}

function retrackersRestrictedPlanGenericMap()
{
	return(array('mode' => 'generic-string-map'));
}

function retrackersRestrictedPlanScalarBatch($types)
{
	return(array('mode' => 'scalar-batch', 'types' => $types));
}

function retrackersRestrictedPlanLedger($ownKeys)
{
	return(array('mode' => 'coherent-ledger', 'own_keys' => $ownKeys));
}

function retrackersRestrictedPlanHistoricalBindingV2()
{
	return(array('mode' => 'historical-binding-v2'));
}

function retrackersRestrictedLedgerKey($name)
{
	return(preg_match('/^(?:ma:1|ta:1|pv:[0-9a-f]{32}|pf:[0-9a-f]{64}:[0-9a-f]{64}|to:[0-9a-f]{32}:[idc]:[0-9a-f]{64}|dq:1|(?:wh|wp|di):[0-9A-F]{40}|(?:wa|ea|eb|ed|ex|la|lb|lf|ca|cb|cd|cx|ra|rb|rf):[0-9a-f]{32}|v1:(?:candidate|rollback)-ready:[0-9a-f]{32})$/D', $name) === 1);
}

function retrackersRestrictedSortedNames($names)
{
	usort($names, function ($left, $right) {
		return(strcmp($left, $right));
	});
	for ($index = 1, $count = count($names); $index < $count; $index++) {
		if ($names[$index - 1] === $names[$index]) {
			throw new RetrackersRestrictedCodecError('duplicate-name');
		}
	}
	return($names);
}

function retrackersRestrictedHashString($bytes)
{
	$context = hash_init('sha256');
	hash_update($context, "HS1-S\0");
	hash_update($context, pack('N', strlen($bytes)));
	hash_update($context, $bytes);
	return(hash_final($context, true));
}

function retrackersRestrictedHashInteger($tag, $lexeme)
{
	$context = hash_init('sha256');
	hash_update($context, $tag === 'i4' ? "HS1-4\0" : "HS1-8\0");
	hash_update($context, pack('N', strlen($lexeme)));
	hash_update($context, $lexeme);
	return(hash_final($context, true));
}

function retrackersRestrictedHashStruct($pairs)
{
	usort($pairs, function ($left, $right) {
		return(strcmp($left[0], $right[0]));
	});
	$childContext = hash_init('sha256');
	hash_update($childContext, "HS1-OC\0");
	foreach ($pairs as $pair) {
		hash_update($childContext, pack('N', strlen($pair[0])) . $pair[0] . $pair[1]);
	}
	$context = hash_init('sha256');
	hash_update($context, "HS1-O\0");
	hash_update($context, pack('N', count($pairs)));
	hash_update($context, hash_final($childContext, true));
	return(hash_final($context, true));
}

function retrackersRestrictedDigestNameList($prefix, $names)
{
	$names = retrackersRestrictedSortedNames($names);
	$context = hash_init('sha256');
	hash_update($context, $prefix);
	hash_update($context, pack('N', count($names)));
	foreach ($names as $name) {
		hash_update($context, pack('N', strlen($name)) . $name);
	}
	return(hash_final($context, true));
}

class RetrackersRestrictedRawCodec
{
	// These limits and layouts are the measured wire grammar, not a generic XMLRPC decoder.
	const HIST_MAX_EVENT_KEYS = 4096;
	const HIST_MAX_ACTIONS = 4096;
	const HIST_MAX_LEDGER_KEYS = 4096;
	const HIST_MAX_PROFILE_CLAIMS = 2048;
	const HIST_MAX_RECOVERY_ROWS = 16384;
	const HIST_MAX_DEPTH = 32;

	private $cursor;
	private $plan;
	private $historicalMalformedFault = false;
	private $historicalLedgerCorrupt = false;

	public function __construct($raw, $bodyOffset, $bodyEnd, $family, $plan)
	{
		$this->cursor = new RetrackersRestrictedXmlCursor($raw, $bodyOffset, $bodyEnd, $family);
		$this->plan = $plan;
	}

	public function historicalLedgerFailure()
	{
		return($this->historicalMalformedFault ? 'malformed-response' : 'receipt-ledger-corrupt');
	}

	private function ledgerSemanticFailure($reason)
	{
		if (isset($this->plan['mode']) && $this->plan['mode'] === 'historical-binding-v2') {
			$this->historicalLedgerCorrupt = true;
			return;
		}
		throw new RetrackersRestrictedLedgerError($reason);
	}

	private function scalar($type)
	{
		if (!in_array($type, array('string', 'i4', 'i8'), true)) {
			throw new RetrackersRestrictedCodecError('unknown-scalar-plan');
		}
		if ($type === 'string') {
			$value = $this->cursor->text('string');
			$this->cursor->chargeProjected(strlen($value));
			return($value);
		}
		$value = $this->cursor->integer($type);
		$this->cursor->chargeProjected(strlen($value));
		return($value);
	}

	private function arrayValues($parser, $maximum)
	{
		$values = array();
		$empty = $this->cursor->openArray();
		if (!$empty) {
			while (!$this->cursor->at('</data></array>')) {
				if (count($values) >= $maximum) {
					throw new RetrackersRestrictedCodecError('cardinality-exceeded');
				}
				$this->cursor->beginValue();
				$values[] = call_user_func($parser);
				$this->cursor->take('</value>');
				$this->cursor->line();
			}
			$this->cursor->closeArray();
		}
		return($values);
	}

	private function flatStrings($kind)
	{
		$maximum = ($kind === 'event') ? self::HIST_MAX_EVENT_KEYS : self::HIST_MAX_LEDGER_KEYS;
		$values = $this->arrayValues(function () {
			$value = $this->cursor->text('string', true);
			$this->cursor->chargeProjected(strlen($value));
			return($value);
		}, $maximum);
		foreach ($values as $value) {
			if ($kind === 'ledger' && !retrackersRestrictedLedgerKey($value)) {
				$this->ledgerSemanticFailure('invalid-ledger-key');
			}
		}
		try {
			$values = retrackersRestrictedSortedNames($values);
		} catch (RetrackersRestrictedCodecError $error) {
			if ($kind === 'ledger') {
				$this->ledgerSemanticFailure('duplicate-name');
				sort($values, SORT_STRING);
			} else {
				throw $error;
			}
		}
		if ($kind === 'ledger') {
			$this->validateProfileClaimCount($values);
		}
		return($values);
	}

	private function validateProfileClaimCount($names)
	{
		$count = 0;
		foreach ($names as $name) {
			if (strncmp($name, 'pf:', 3) === 0 && ++$count > self::HIST_MAX_PROFILE_CLAIMS) {
				throw new RetrackersRestrictedCodecError('profile-claim-cardinality');
			}
		}
	}

	private function downloadRows($width)
	{
		$maximum = 16384;
		$rows = $this->arrayValues(function () use ($width) {
			$values = $this->arrayValues(function () {
				return($this->scalar('string'));
			}, $width);
			if (count($values) !== $width) {
				throw new RetrackersRestrictedCodecError('wrong-row-width');
			}
			return($values);
		}, $maximum);
		$hashes = array();
		$localIds = array();
		foreach ($rows as $row) {
			if (preg_match('/^[0-9A-F]{40}$/D', $row[0]) !== 1 ||
				preg_match('/^[0-9A-F]{40}$/D', $row[1]) !== 1) {
				throw new RetrackersRestrictedCodecError('invalid-row-identity');
			}
			$hashes[] = $row[0];
			$localIds[] = $row[1];
		}
		retrackersRestrictedSortedNames($hashes);
		retrackersRestrictedSortedNames($localIds);
		usort($rows, function ($left, $right) {
			$hash = strcmp($left[0], $right[0]);
			return($hash !== 0 ? $hash : strcmp($left[1], $right[1]));
		});
		return($rows);
	}

	private function recoveryRows()
	{
		// Retained RR4 is one flat buffer: magic, U32 count, then four U32-length-prefixed cells per row.
		$entries = array();
		$hashes = array();
		$localIds = array();
		$this->cursor->chargeProjected(8);
		$empty = $this->cursor->openArray();
		if (!$empty) {
			while (!$this->cursor->at('</data></array>')) {
				if (count($entries) >= self::HIST_MAX_RECOVERY_ROWS) {
					throw new RetrackersRestrictedCodecError('cardinality-exceeded');
				}
				$this->cursor->beginValue();
				if ($this->cursor->openArray()) {
					throw new RetrackersRestrictedCodecError('wrong-row-width');
				}
				$row = array();
				for ($index = 0; $index < 4; $index++) {
					if ($this->cursor->at('</data></array>')) {
						throw new RetrackersRestrictedCodecError('wrong-row-width');
					}
					$this->cursor->beginValue();
					$row[] = $this->cursor->text('string');
					$this->cursor->take('</value>');
					$this->cursor->line();
				}
				if (!$this->cursor->at('</data></array>')) {
					throw new RetrackersRestrictedCodecError('wrong-row-width');
				}
				$this->cursor->closeArray();
				$this->cursor->take('</value>');
				$this->cursor->line();
				if (preg_match('/^[0-9A-F]{40}$/D', $row[0]) !== 1 ||
					preg_match('/^[0-9A-F]{40}$/D', $row[1]) !== 1) {
					throw new RetrackersRestrictedCodecError('invalid-row-identity');
				}
				$hashKey = "\0" . $row[0];
				$localKey = "\0" . $row[1];
				if (isset($hashes[$hashKey]) || isset($localIds[$localKey])) {
					throw new RetrackersRestrictedCodecError('duplicate-name');
				}
				$hashes[$hashKey] = true;
				$localIds[$localKey] = true;
				$recordLength = 16 + strlen($row[0]) + strlen($row[1]) + strlen($row[2]) + strlen($row[3]);
				$this->cursor->chargeProjected($recordLength);
				$record = '';
				$context = hash_init('sha256');
				hash_update($context, "HS1-R\0");
				foreach ($row as $cell) {
					$part = pack('N', strlen($cell)) . $cell;
					$record .= $part;
					hash_update($context, $part);
				}
				$entries[] = $record . hash_final($context, true);
			}
			$this->cursor->closeArray();
		}
		usort($entries, function ($left, $right) {
			$hash = strcmp(substr($left, 4, 40), substr($right, 4, 40));
			return($hash !== 0 ? $hash : strcmp(substr($left, 48, 40), substr($right, 48, 40)));
		});
		$packed = "RR4\0" . pack('N', count($entries));
		$context = hash_init('sha256');
		hash_update($context, "HS1-RS\0" . pack('N', count($entries)));
		foreach ($entries as $entry) {
			$recordLength = strlen($entry) - 32;
			$packed .= substr($entry, 0, $recordLength);
			hash_update($context, substr($entry, $recordLength, 32));
		}
		return(array('packed' => $packed, 'digest' => hash_final($context, true),
			'count' => count($entries)));
	}

	private function trackerRows()
	{
		return($this->arrayValues(function () {
			$empty = $this->cursor->openArray();
			if ($empty) {
				throw new RetrackersRestrictedCodecError('wrong-row-width');
			}
			$cells = array();
			$types = array('string', 'i8', 'i8', 'i8', 'i8');
			foreach ($types as $type) {
				if ($this->cursor->at('</data></array>')) {
					throw new RetrackersRestrictedCodecError('wrong-row-width');
				}
				$this->cursor->beginValue();
				$cells[] = $this->scalar($type);
				$this->cursor->take('</value>');
				$this->cursor->line();
			}
			if (!$this->cursor->at('</data></array>')) {
				throw new RetrackersRestrictedCodecError('wrong-row-width');
			}
			$this->cursor->closeArray();
			if ($cells[1][0] === '-' || $cells[2][0] === '-' ||
				!in_array($cells[3], array('0', '1'), true) || !in_array($cells[4], array('0', '1'), true)) {
				throw new RetrackersRestrictedCodecError('invalid-tracker-cell');
			}
			return($cells);
		}, 16384));
	}

	private function stringStruct($ledger = false)
	{
		$pairs = array();
		$names = array();
		$seen = array();
		$empty = $this->cursor->openStruct();
		if (!$empty) {
			while (!$this->cursor->at('</struct>')) {
				$maximum = $ledger ? self::HIST_MAX_LEDGER_KEYS : RetrackersRestrictedXmlCursor::MAX_MEMBERS;
				if (count($pairs) >= $maximum) {
					throw new RetrackersRestrictedCodecError('cardinality-exceeded');
				}
				$this->cursor->beginMember();
				$name = $this->cursor->text('name', true);
				$key = "\0" . $name;
				$duplicate = isset($seen[$key]);
				$seen[$key] = true;
				$this->cursor->line();
				$this->cursor->beginValue();
				if ($ledger && !$this->cursor->at('<string>')) {
					$this->opaqueValue(false, true);
					$value = '';
					$wrongType = true;
				} else {
					$value = $this->scalar('string');
					$wrongType = false;
				}
				$this->cursor->take('</value></member>');
				$this->cursor->line();
				if ($duplicate) {
					if ($ledger) {
						$this->ledgerSemanticFailure('duplicate-name');
					} else {
						throw new RetrackersRestrictedCodecError('duplicate-name');
					}
				}
				if ($ledger && ($wrongType || !retrackersRestrictedLedgerKey($name) || $value !== '1')) {
					$this->ledgerSemanticFailure($wrongType ? 'non-string-ledger-value' :
						'invalid-ledger-map');
				}
				$this->cursor->chargeProjected(strlen($name));
				$names[] = $name;
				$pairs[] = array('name' => $name, 'value' => $value);
			}
			$this->cursor->closeStruct();
		}
		if ($ledger && $this->historicalLedgerCorrupt) {
			sort($names, SORT_STRING);
		} else {
			$names = retrackersRestrictedSortedNames($names);
		}
		usort($pairs, function ($left, $right) {
			return(strcmp($left['name'], $right['name']));
		});
		if ($ledger) {
			$this->validateProfileClaimCount($names);
		}
		return(array('pairs' => $pairs, 'names' => $names));
	}

	private function opaqueScalar($top)
	{
		if ($this->cursor->at('<string>')) {
			$value = $this->cursor->text('string');
			return(array(retrackersRestrictedHashString($value), 'string', $top ? $value : null));
		}
		if (!$top && $this->cursor->at('<i4>') && $this->cursor->family() === 1) {
			$lexeme = $this->cursor->integer('i4');
			return(array(retrackersRestrictedHashInteger('i4', $lexeme), 'i4', null));
		}
		if (!$top && $this->cursor->at('<i8>')) {
			$lexeme = $this->cursor->integer('i8');
			return(array(retrackersRestrictedHashInteger('i8', $lexeme), 'i8', null));
		}
		return(false);
	}

	private function opaqueFrame($kind, $depth, $ledgerContext)
	{
		if ($depth > self::HIST_MAX_DEPTH) {
			throw new RetrackersRestrictedCodecError('opaque-depth');
		}
		if ($kind === 'array') {
			$context = hash_init('sha256');
			hash_update($context, "HS1-AC\0");
			return(array('kind' => 'array', 'depth' => $depth,
				'closed' => $this->cursor->openArray(), 'count' => 0, 'context' => $context));
		}
		if ($kind === 'struct' && ($this->cursor->family() === 2 || $ledgerContext)) {
			return(array('kind' => 'struct', 'depth' => $depth,
				'closed' => $this->cursor->openStruct(), 'pairs' => array(),
				'names' => array(), 'seen' => array(), 'pending' => null));
		}
		throw new RetrackersRestrictedCodecError('unknown-opaque-type');
	}

	private function opaqueArrayDigest($frame)
	{
		$context = hash_init('sha256');
		hash_update($context, "HS1-A\0");
		hash_update($context, pack('N', $frame['count']));
		hash_update($context, hash_final($frame['context'], true));
		return(hash_final($context, true));
	}

	private function appendOpaqueChild(&$frames, $digest)
	{
		$index = count($frames) - 1;
		if ($frames[$index]['kind'] === 'array') {
			$this->cursor->take('</value>');
			$this->cursor->line();
			hash_update($frames[$index]['context'], $digest);
			$frames[$index]['count']++;
			return;
		}
		$this->cursor->take('</value></member>');
		$this->cursor->line();
		$name = $frames[$index]['pending'];
		$frames[$index]['pending'] = null;
		$frames[$index]['names'][] = $name;
		$frames[$index]['pairs'][] = array($name, $digest);
	}

	private function pushOpaqueContainer(&$frames, $depth, $ledgerContext)
	{
		if ($this->cursor->at('<array>')) {
			$frames[] = $this->opaqueFrame('array', $depth, $ledgerContext);
			return;
		}
		if (($this->cursor->family() === 2 || $ledgerContext) && $this->cursor->at('<struct')) {
			$frames[] = $this->opaqueFrame('struct', $depth, $ledgerContext);
			return;
		}
		throw new RetrackersRestrictedCodecError('unknown-opaque-type');
	}

	private function opaqueValue($actionTop, $ledgerContext = false)
	{
		$scalar = $this->opaqueScalar($actionTop);
		if ($scalar !== false) {
			return($scalar);
		}
		if ($this->cursor->at('<array>')) {
			$topKind = 'array';
		} elseif (!$actionTop && ($this->cursor->family() === 2 || $ledgerContext) &&
			$this->cursor->at('<struct')) {
			$topKind = 'struct';
		} else {
			throw new RetrackersRestrictedCodecError('invalid-top-action');
		}
		$frames = array($this->opaqueFrame($topKind, 1, $ledgerContext));
		while (count($frames) > 0) {
			$index = count($frames) - 1;
			$frame = $frames[$index];
			if ($frame['kind'] === 'array' &&
				($frame['closed'] || $this->cursor->at('</data></array>'))) {
				if (!$frame['closed']) {
					$this->cursor->closeArray();
				}
				array_pop($frames);
				$digest = $this->opaqueArrayDigest($frame);
				if (count($frames) === 0) {
					return(array($digest, 'array', null));
				}
				$this->appendOpaqueChild($frames, $digest);
				continue;
			}
			if ($frame['kind'] === 'struct' &&
				($frame['closed'] || $this->cursor->at('</struct>'))) {
				if (!$frame['closed']) {
					$this->cursor->closeStruct();
				}
				retrackersRestrictedSortedNames($frame['names']);
				array_pop($frames);
				$digest = retrackersRestrictedHashStruct($frame['pairs']);
				if (count($frames) === 0) {
					return(array($digest, 'struct', null));
				}
				$this->appendOpaqueChild($frames, $digest);
				continue;
			}
			if ($frame['kind'] === 'struct') {
				$this->cursor->beginMember();
				$name = $this->cursor->text('name', true);
				$key = "\0" . $name;
				if (isset($frames[$index]['seen'][$key])) {
					throw new RetrackersRestrictedCodecError('duplicate-name');
				}
				$frames[$index]['seen'][$key] = true;
				$frames[$index]['pending'] = $name;
				$this->cursor->line();
				$this->cursor->beginValue(true);
			} else {
				$this->cursor->beginValue(true);
			}
			$scalar = $this->opaqueScalar(false);
			if ($scalar !== false) {
				$this->appendOpaqueChild($frames, $scalar[0]);
				continue;
			}
			$this->pushOpaqueContainer($frames, $frame['depth'] + 1, $ledgerContext);
		}
		throw new RetrackersRestrictedCodecError('incomplete-opaque-action');
	}

	private function opaqueAction()
	{
		return($this->opaqueValue(true));
	}

	private function eventMap()
	{
		// Foreign action trees are reduced to typed hashes while the cursor walks them.
		$entries = array();
		$digestPairs = array();
		$names = array();
		$seen = array();
		$empty = $this->cursor->openStruct();
		if (!$empty) {
			while (!$this->cursor->at('</struct>')) {
				if (count($entries) >= self::HIST_MAX_ACTIONS) {
					throw new RetrackersRestrictedCodecError('cardinality-exceeded');
				}
				$this->cursor->beginMember();
				$name = $this->cursor->text('name', true);
				$key = "\0" . $name;
				if (isset($seen[$key])) {
					throw new RetrackersRestrictedCodecError('duplicate-name');
				}
				$seen[$key] = true;
				$this->cursor->line();
				$this->cursor->beginValue(true);
				$action = $this->opaqueAction();
				$this->cursor->take('</value></member>');
				$this->cursor->line();
				$names[] = $name;
				$digestPairs[] = array($name, $action[0]);
				$this->cursor->chargeProjected(strlen($name) + 32 +
					($action[1] === 'string' ? strlen($action[2]) : 0));
				$entry = array('name' => $name, 'type' => $action[1], 'digest' => $action[0]);
				if ($action[1] === 'string') {
					$entry['value'] = $action[2];
				}
				$entries[] = $entry;
			}
			$this->cursor->closeStruct();
		}
		$names = retrackersRestrictedSortedNames($names);
		usort($entries, function ($left, $right) {
			return(strcmp($left['name'], $right['name']));
		});
		return(array('entries' => $entries, 'names' => $names,
			'digest' => retrackersRestrictedHashStruct($digestPairs)));
	}

	private function fault()
	{
		$empty = $this->cursor->openStruct();
		if ($empty) {
			throw new RetrackersRestrictedCodecError('empty-fault');
		}
		$fault = array();
		$names = array();
		while (!$this->cursor->at('</struct>')) {
			if (count($names) >= 2) {
				throw new RetrackersRestrictedCodecError('fault-width');
			}
			$this->cursor->beginMember();
			$name = $this->cursor->text('name', true);
			$this->cursor->line();
			$this->cursor->beginValue();
			if ($name === 'faultCode') {
				$tag = $this->cursor->family() === 1 ? 'i4' : 'i8';
				$fault['code'] = $this->cursor->integer($tag);
				$this->cursor->chargeProjected(strlen($fault['code']));
				$fault['code_type'] = $tag;
			} elseif ($name === 'faultString') {
				$fault['message'] = $this->cursor->text('string');
				$this->cursor->chargeProjected(strlen($fault['message']));
			} else {
				throw new RetrackersRestrictedCodecError('fault-member');
			}
			$this->cursor->take('</value></member>');
			$this->cursor->line();
			$names[] = $name;
		}
		$this->cursor->closeStruct();
		$names = retrackersRestrictedSortedNames($names);
		if ($names !== array('faultCode', 'faultString')) {
			throw new RetrackersRestrictedCodecError('fault-width');
		}
		return($fault);
	}

	private function node($node)
	{
		switch ($node['kind']) {
			case 'scalar':
				return($this->scalar($node['type']));
			case 'download-rows':
				return($this->downloadRows(2));
			case 'tracker-rows':
				return($this->trackerRows());
			case 'generic-map':
				return($this->stringStruct(false)['pairs']);
			case 'event-keys':
				return($this->flatStrings('event'));
			case 'event-map':
				return($this->eventMap());
			case 'recovery-rows':
				return($this->recoveryRows());
			case 'ledger-keys':
				return($this->flatStrings('ledger'));
			case 'ledger-map':
				return($this->stringStruct(true));
			case 'ledger-has':
				$value = $this->scalar('i8');
				if (!in_array($value, array('0', '1'), true)) {
					throw new RetrackersRestrictedCodecError('invalid-ledger-has');
				}
				return($value);
		}
		throw new RetrackersRestrictedCodecError('unknown-node-plan');
	}

	private function planNodes()
	{
		$mode = $this->plan['mode'];
		if ($mode === 'scalar-batch') {
			if (!isset($this->plan['types']) || !is_array($this->plan['types']) ||
				count($this->plan['types']) < 1 ||
				count($this->plan['types']) > RetrackersRestrictedXmlCursor::MAX_MEMBERS) {
				throw new RetrackersRestrictedCodecError('invalid-batch-plan');
			}
			$nodes = array();
			foreach ($this->plan['types'] as $type) {
				if (!in_array($type, array('string', 'i4', 'i8'), true)) {
					throw new RetrackersRestrictedCodecError('invalid-batch-plan');
				}
				$nodes[] = array('kind' => 'scalar', 'type' => $type);
			}
			return($nodes);
		}
		if ($mode === 'coherent-ledger') {
			if (!isset($this->plan['own_keys']) || !is_array($this->plan['own_keys'])) {
				throw new RetrackersRestrictedCodecError('invalid-ledger-plan');
			}
			$own = $this->plan['own_keys'];
			if (count($own) > self::HIST_MAX_LEDGER_KEYS) {
				throw new RetrackersRestrictedCodecError('invalid-ledger-plan');
			}
			foreach ($own as $key) {
				if (!is_string($key) || !retrackersRestrictedLedgerKey($key)) {
					throw new RetrackersRestrictedCodecError('invalid-ledger-plan');
				}
			}
			retrackersRestrictedSortedNames($own);
			$nodes = array(array('kind' => 'ledger-keys'), array('kind' => 'ledger-map'));
			foreach ($this->plan['own_keys'] as $unused) {
				$nodes[] = array('kind' => 'ledger-has');
			}
			return($nodes);
		}
		if ($mode === 'historical-binding-v2') {
			return(array(
				array('kind' => 'event-keys'),
				array('kind' => 'event-map'),
				array('kind' => 'recovery-rows'),
				array('kind' => 'ledger-keys'),
				array('kind' => 'ledger-map'),
			));
		}
		throw new RetrackersRestrictedCodecError('not-a-batch-plan');
	}

	private function batch()
	{
		$nodes = $this->planNodes();
		$empty = $this->cursor->openArray();
		if ($empty) {
			throw new RetrackersRestrictedCodecError('empty-multicall');
		}
		$results = array();
		$faultSlots = array();
		foreach ($nodes as $slot => $node) {
			if ($this->cursor->at('</data></array>')) {
				throw new RetrackersRestrictedCodecError('missing-multicall-slot');
			}
			$this->cursor->beginValue();
			if ($this->cursor->at('<struct')) {
				$results[] = array('ok' => false, 'fault' => $this->fault());
				$faultSlots[] = $slot;
				if (isset($this->plan['mode']) && $this->plan['mode'] === 'historical-binding-v2' &&
					$slot < 3) {
					$this->historicalMalformedFault = true;
				}
			} else {
				$wrapperEmpty = $this->cursor->openArray();
				if ($wrapperEmpty || $this->cursor->at('</data></array>')) {
					throw new RetrackersRestrictedCodecError('empty-success-wrapper');
				}
				$this->cursor->beginValue();
				$value = $this->node($node);
				$this->cursor->take('</value>');
				$this->cursor->line();
				if (!$this->cursor->at('</data></array>')) {
					throw new RetrackersRestrictedCodecError('wide-success-wrapper');
				}
				$this->cursor->closeArray();
				$results[] = array('ok' => true, 'value' => $value);
			}
			$this->cursor->take('</value>');
			$this->cursor->line();
		}
		if (!$this->cursor->at('</data></array>')) {
			throw new RetrackersRestrictedCodecError('extra-multicall-slot');
		}
		$this->cursor->closeArray();
		return(array($results, $faultSlots));
	}

	private function ledgerDigests($keys, $map)
	{
		$sorted = retrackersRestrictedSortedNames($keys);
		$keyDigest = retrackersRestrictedDigestNameList("HB2-LK\0", $sorted);
		$mapNames = retrackersRestrictedSortedNames($map['names']);
		$mapContext = hash_init('sha256');
		hash_update($mapContext, "HB2-LM\0");
		hash_update($mapContext, pack('N', count($mapNames)));
		foreach ($mapNames as $name) {
			hash_update($mapContext, pack('N', strlen($name)) . $name . "\x01");
		}
		$mapDigest = hash_final($mapContext, true);
		$context = hash_init('sha256');
		hash_update($context, "HB2-L\0" . $keyDigest . $mapDigest);
		return(array($keyDigest, $mapDigest, hash_final($context, true)));
	}

	private function validateHistoricalEventPair($results)
	{
		if (retrackersRestrictedSortedNames($results[0]['value']) !==
			retrackersRestrictedSortedNames($results[1]['value']['names'])) {
			throw new RetrackersRestrictedCodecError('event-list-map-mismatch');
		}
	}

	private function historicalProjection($results)
	{
		$eventKeys = $results[0]['value'];
		$eventMap = $results[1]['value'];
		$rows = $results[2]['value'];
		$ledgerKeys = $results[3]['value'];
		$ledgerMap = $results[4]['value'];
		$this->validateHistoricalEventPair($results);
		if (retrackersRestrictedSortedNames($ledgerKeys) !== retrackersRestrictedSortedNames($ledgerMap['names'])) {
			throw new RetrackersRestrictedLedgerError('ledger-list-map-mismatch');
		}
		$eventKeyDigest = retrackersRestrictedDigestNameList("HS1-K\0", $eventKeys);
		$eventMapDigest = $eventMap['digest'];
		$recoveryDigest = $rows['digest'];
		list($ledgerKeyDigest, $ledgerMapDigest, $ledgerDigest) = $this->ledgerDigests($ledgerKeys, $ledgerMap);
		$context = hash_init('sha256');
		hash_update($context, "HistoricalBindingSampleV2\0" . chr($this->cursor->family()) .
			$eventKeyDigest . $eventMapDigest . $recoveryDigest . $ledgerDigest);
		$digest = hash_final($context, true);
		$this->cursor->chargeProjected(448);
		return(array(
			'digest' => bin2hex($digest),
			'event_key_digest' => bin2hex($eventKeyDigest),
			'event_map_digest' => bin2hex($eventMapDigest),
			'recovery_rows_digest' => bin2hex($recoveryDigest),
			'ledger_key_digest' => bin2hex($ledgerKeyDigest),
			'ledger_map_digest' => bin2hex($ledgerMapDigest),
			'ledger_digest' => bin2hex($ledgerDigest),
			'event_keys' => $eventKeys,
			'actions' => $eventMap['entries'],
			'recovery_rows' => $rows['packed'],
			'ledger_keys' => $ledgerKeys,
			'counts' => array(
				'event_keys' => count($eventKeys),
				'actions' => count($eventMap['entries']),
				'recovery_rows' => $rows['count'],
				'ledger_keys' => count($ledgerKeys),
			),
			'production_accepted' => false,
		));
	}

	public function decode()
	{
		$family = $this->cursor->family();
		$this->cursor->take($family === 1 ? '<?xml version="1.0" encoding="UTF-8"?>' : '<?xml version="1.0"?>');
		$this->cursor->line();
		$this->cursor->take('<methodResponse>');
		$this->cursor->line();
		$mode = isset($this->plan['mode']) ? $this->plan['mode'] : null;
		$topLevelFaultModes = array('method-names', 'direct-scalar', 'family-mutation-scalar', 'direct-ledger-has',
			'download-local-id-rows', 'tracker-five-column-rows', 'generic-string-map',
			'scalar-batch');
		if (in_array($mode, $topLevelFaultModes, true) && $this->cursor->at('<fault>')) {
			$this->cursor->take('<fault>');
			$this->cursor->line();
			$this->cursor->beginValue();
			$fault = $this->fault();
			$this->cursor->take('</value>');
			$this->cursor->line();
			$this->cursor->take('</fault>');
			$this->cursor->line();
			$this->cursor->take('</methodResponse>');
			$this->cursor->line();
			if (!$this->cursor->finished()) {
				throw new RetrackersRestrictedCodecError('trailing-body');
			}
			return(array('ok' => false, 'family' => $family, 'fault' => $fault));
		}
		$this->cursor->take('<params>');
		$this->cursor->line();
		$this->cursor->take('<param>');
		$this->cursor->beginValue();
		if ($mode === 'method-names') {
			$value = $this->flatStrings('event');
		} elseif ($mode === 'direct-scalar') {
			$value = $this->scalar(isset($this->plan['type']) ? $this->plan['type'] : null);
		} elseif ($mode === 'family-mutation-scalar') {
			$value = $this->scalar($family === 1 ? 'i4' : 'i8');
		} elseif ($mode === 'direct-ledger-has') {
			$value = $this->scalar('i8');
			if (!in_array($value, array('0', '1'), true)) {
				throw new RetrackersRestrictedCodecError('invalid-ledger-has');
			}
		} elseif ($mode === 'download-local-id-rows') {
			$value = $this->downloadRows(2);
		} elseif ($mode === 'tracker-five-column-rows') {
			$value = $this->trackerRows();
		} elseif ($mode === 'generic-string-map') {
			$value = $this->stringStruct(false)['pairs'];
		} elseif (in_array($mode, array('scalar-batch', 'coherent-ledger', 'historical-binding-v2'), true)) {
			list($value, $faultSlots) = $this->batch();
		} else {
			throw new RetrackersRestrictedCodecError('unknown-plan');
		}
		$this->cursor->take('</value></param>');
		$this->cursor->line();
		$this->cursor->take('</params>');
		$this->cursor->line();
		$this->cursor->take('</methodResponse>');
		$this->cursor->line();
		if (!$this->cursor->finished()) {
			throw new RetrackersRestrictedCodecError('trailing-body');
		}
		if ($mode === 'coherent-ledger') {
			if (count($faultSlots) > 0) {
				return(false);
			}
			if (retrackersRestrictedSortedNames($value[0]['value']) !==
				retrackersRestrictedSortedNames($value[1]['value']['names'])) {
				return(false);
			}
			return(array('ok' => true, 'family' => $family, 'value' => $value));
		}
		if ($mode === 'historical-binding-v2') {
			if (count($faultSlots) > 0) {
				foreach ($faultSlots as $slot) {
					if ($slot < 3) {
						return(array('ok' => false, 'historical_failure' => 'malformed-response'));
					}
				}
			}
			$this->validateHistoricalEventPair($value);
			if (count($faultSlots) > 0) {
				return(array('ok' => false, 'historical_failure' => 'receipt-ledger-corrupt'));
			}
			if ($this->historicalLedgerCorrupt) {
				return(array('ok' => false, 'historical_failure' => 'receipt-ledger-corrupt'));
			}
			return(array('ok' => true, 'family' => $family,
				'value' => $this->historicalProjection($value)));
		}
		return(array('ok' => true, 'family' => $family, 'value' => $value));
	}
}

class RetrackersRestrictedRawResponseBoundary
{
	private static function decode($raw, $plan, &$failure)
	{
		$failure = 'malformed-response';
		$codec = null;
		if (!is_string($raw) || !is_array($plan)) {
			return(false);
		}
		$delimiter = strpos($raw, "\r\n\r\n");
		if ($delimiter === false || strpos($raw, "\r\n\r\n", $delimiter + 4) !== false ||
			$delimiter > 65536) {
			return(false);
		}
		$header = substr($raw, 0, $delimiter);
		if (preg_match('/^Status: 200 OK\r\nContent-Type: text\/xml\r\nContent-Length: (0|[1-9][0-9]*)$/D',
			$header, $matched) !== 1) {
			return(false);
		}
		$declared = $matched[1];
		$maximum = '67108864';
		if (strlen($declared) > strlen($maximum) ||
			(strlen($declared) === strlen($maximum) && strcmp($declared, $maximum) > 0)) {
			return(false);
		}
		$bodyOffset = $delimiter + 4;
		$bodyLength = strlen($raw) - $bodyOffset;
		if ((string)$bodyLength !== $declared) {
			return(false);
		}
		$oldDeclaration = '<?xml version="1.0" encoding="UTF-8"?>';
		$newDeclaration = '<?xml version="1.0"?>';
		if (substr_compare($raw, $oldDeclaration, $bodyOffset, strlen($oldDeclaration)) === 0) {
			$family = 1;
		} elseif (substr_compare($raw, $newDeclaration, $bodyOffset, strlen($newDeclaration)) === 0) {
			$family = 2;
		} else {
			return(false);
		}
		try {
			$codec = new RetrackersRestrictedRawCodec($raw, $bodyOffset, strlen($raw), $family, $plan);
			$result = $codec->decode();
			if (is_array($result) && isset($result['historical_failure'])) {
				$failure = $result['historical_failure'];
				return(false);
			}
			if ($result !== false) {
				$failure = null;
			}
			return($result);
		} catch (RetrackersRestrictedLedgerError $error) {
			$failure = ($codec instanceof RetrackersRestrictedRawCodec) ?
				$codec->historicalLedgerFailure() : 'receipt-ledger-corrupt';
			return(false);
		} catch (RetrackersRestrictedCodecError $error) {
			return(false);
		}
	}

	public static function ordinary($raw, $plan)
	{
		$failure = null;
		return(self::decode($raw, $plan, $failure));
	}

	public static function historical($raw)
	{
		$failure = null;
		$result = self::decode($raw, retrackersRestrictedPlanHistoricalBindingV2(), $failure);
		return($result === false ? array('ok' => false, 'failure' => $failure) :
			array('ok' => true, 'sample' => $result));
	}
}

function retrackersDecodeRestrictedRawResponse($raw, $plan)
{
	return(RetrackersRestrictedRawResponseBoundary::ordinary($raw, $plan));
}

function retrackersDecodeHistoricalBindingResponseV2($raw)
{
	return(RetrackersRestrictedRawResponseBoundary::historical($raw));
}

function retrackersHistoricalEmptyDigestVectors()
{
	$eventKeys = retrackersRestrictedDigestNameList("HS1-K\0", array());
	$eventMap = retrackersRestrictedHashStruct(array());
	$rowsContext = hash_init('sha256');
	hash_update($rowsContext, "HS1-RS\0" . pack('N', 0));
	$rows = hash_final($rowsContext, true);
	$ledgerKeys = retrackersRestrictedDigestNameList("HB2-LK\0", array());
	$ledgerMapContext = hash_init('sha256');
	hash_update($ledgerMapContext, "HB2-LM\0" . pack('N', 0));
	$ledgerMap = hash_final($ledgerMapContext, true);
	$ledgerContext = hash_init('sha256');
	hash_update($ledgerContext, "HB2-L\0" . $ledgerKeys . $ledgerMap);
	$ledger = hash_final($ledgerContext, true);
	$vectors = array(
		'EventKeyDigestV1' => bin2hex($eventKeys),
		'EventMapDigestV1' => bin2hex($eventMap),
		'RecoveryRowsDigestV1' => bin2hex($rows),
		'LedgerKeyDigestV1' => bin2hex($ledgerKeys),
		'LedgerMapDigestV1' => bin2hex($ledgerMap),
		'LedgerDigestV1' => bin2hex($ledger),
	);
	foreach (array(1, 2) as $family) {
		$context = hash_init('sha256');
		hash_update($context, "HistoricalBindingSampleV2\0" . chr($family) .
			$eventKeys . $eventMap . $rows . $ledger);
		$vectors['V2 family 0x0' . $family] = bin2hex(hash_final($context, true));
	}
	return($vectors);
}

function retrackersSourceScalarSchema($hash)
{
	if (!is_string($hash) || preg_match('/^[0-9A-F]{40}$/D', $hash) !== 1) {
		return(false);
	}
	return(array(
		array('name' => 'session_path', 'method' => 'session.path',
			'parameters' => array(), 'type' => 'string'),
		array('name' => 'tied_source', 'method' => 'd.tied_to_file',
			'parameters' => array($hash), 'type' => 'string'),
		array('name' => 'loaded_file', 'method' => 'd.loaded_file',
			'parameters' => array($hash), 'type' => 'string'),
		array('name' => 'directory_base', 'method' => 'd.directory_base',
			'parameters' => array($hash), 'type' => 'string'),
		array('name' => 'directory_default', 'method' => 'directory.default',
			'parameters' => array(), 'type' => 'string'),
		array('name' => 'custom1', 'method' => 'd.custom1',
			'parameters' => array($hash), 'type' => 'string'),
		array('name' => 'custom2', 'method' => 'd.custom2',
			'parameters' => array($hash), 'type' => 'string'),
		array('name' => 'custom3', 'method' => 'd.custom3',
			'parameters' => array($hash), 'type' => 'string'),
		array('name' => 'custom4', 'method' => 'd.custom4',
			'parameters' => array($hash), 'type' => 'string'),
		array('name' => 'custom5', 'method' => 'd.custom5',
			'parameters' => array($hash), 'type' => 'string'),
		array('name' => 'priority', 'method' => 'd.priority',
			'parameters' => array($hash), 'type' => 'i8'),
		array('name' => 'throttle_name', 'method' => 'd.throttle_name',
			'parameters' => array($hash), 'type' => 'string'),
		array('name' => 'recovery_marker', 'method' => 'd.custom',
			'parameters' => array($hash, 'retrackers-recovery'), 'type' => 'string'),
		array('name' => 'recovery_ack', 'method' => 'd.custom',
			'parameters' => array($hash, 'retrackers-recovery-ack'), 'type' => 'string'),
		array('name' => 'is_private', 'method' => 'd.is_private',
			'parameters' => array($hash), 'type' => 'i8'),
		array('name' => 'name', 'method' => 'd.name',
			'parameters' => array($hash), 'type' => 'string'),
		array('name' => 'state', 'method' => 'd.state',
			'parameters' => array($hash), 'type' => 'i8'),
		array('name' => 'is_active', 'method' => 'd.is_active',
			'parameters' => array($hash), 'type' => 'i8'),
		array('name' => 'is_open', 'method' => 'd.is_open',
			'parameters' => array($hash), 'type' => 'i8'),
		array('name' => 'hashing', 'method' => 'd.hashing',
			'parameters' => array($hash), 'type' => 'i8'),
		array('name' => 'hashing_failed', 'method' => 'd.hashing_failed',
			'parameters' => array($hash), 'type' => 'i8'),
		array('name' => 'local_id', 'method' => 'd.local_id',
			'parameters' => array($hash), 'type' => 'string'),
		array('name' => 'trackers_use_udp', 'method' => 'trackers.use_udp',
			'parameters' => array(), 'type' => 'i8'),
	));
}

function retrackersBuildSourceScalarRequest($hash)
{
	$schema = retrackersSourceScalarSchema($hash);
	if ($schema === false) {
		return(false);
	}
	$payload = '<?xml version="1.0" encoding="UTF-8"?><methodCall><methodName>' .
		'system.multicall</methodName><params><param><value><array><data>';
	foreach ($schema as $member) {
		$payload .= "\r\n<value><struct><member><name>methodName</name><value><string>" .
			htmlspecialchars($member['method'], ENT_NOQUOTES, 'UTF-8') .
			'</string></value></member><member><name>params</name><value><array><data>';
		foreach ($member['parameters'] as $parameter) {
			$payload .= "\r\n<value><string>" .
				htmlspecialchars($parameter, ENT_NOQUOTES, 'UTF-8') . '</string></value>';
		}
		$payload .= "\r\n</data></array></value></member></struct></value>";
	}
	return($payload . "\r\n</data></array></value></param></params></methodCall>");
}

function retrackersBuildSourceGenericMapRequest($hash)
{
	if (!is_string($hash) || preg_match('/^[0-9A-F]{40}$/D', $hash) !== 1) {
		return(false);
	}
	return('<?xml version="1.0" encoding="UTF-8"?><methodCall><methodName>' .
		'd.custom.items</methodName><params>\r\n<param><value><string>' . $hash .
		'</string></value></param>\r\n</params></methodCall>');
}

function retrackersBuildSourceTrackerRequest($hash)
{
	if (!is_string($hash) || preg_match('/^[0-9A-F]{40}$/D', $hash) !== 1) {
		return(false);
	}
	$parameters = array($hash, '', 't.url=', 't.group=', 't.type=',
		't.is_extra_tracker=', 't.is_enabled=');
	$payload = '<?xml version="1.0" encoding="UTF-8"?><methodCall><methodName>' .
		't.multicall</methodName><params>\r\n';
	foreach ($parameters as $parameter) {
		$payload .= '<param><value><string>' . $parameter .
			'</string></value></param>\r\n';
	}
	return($payload . '</params></methodCall>');
}

function retrackersBuildDirectRequest($methodName, array $params)
{
	$payload = '<?xml version="1.0" encoding="UTF-8"?><methodCall><methodName>' .
		htmlspecialchars($methodName, ENT_NOQUOTES, 'UTF-8') . '</methodName><params>';
	foreach ($params as $param) {
		$payload .= '<param><value><string>' . htmlspecialchars((string)$param, ENT_NOQUOTES, 'UTF-8') . '</string></value></param>';
	}
	return($payload . '</params></methodCall>');
}

function retrackersBuildSystemMulticallRequest(array $calls)
{
	$payload = '<?xml version="1.0" encoding="UTF-8"?><methodCall><methodName>system.multicall</methodName><params><param><value><array><data>';
	foreach ($calls as $call) {
		$method = $call[0];
		$params = $call[1];
		$payload .= "\r\n<value><struct><member><name>methodName</name><value><string>" .
			htmlspecialchars($method, ENT_NOQUOTES, 'UTF-8') .
			'</string></value></member><member><name>params</name><value><array><data>';
		foreach ($params as $param) {
			$payload .= "\r\n<value><string>" . htmlspecialchars((string)$param, ENT_NOQUOTES, 'UTF-8') . '</string></value>';
		}
		$payload .= "\r\n</data></array></value></member></struct></value>";
	}
	return($payload . "\r\n</data></array></value></param></params></methodCall>");
}

function retrackersResolveSchedulerCommand($alias)
{
	if (!in_array($alias, array('schedule', 'schedule_remove'), true) ||
		!function_exists('getCmd')) {
		return(false);
	}
	// Resolve the pair together so load and retirement cannot use different families.
	$schedule = getCmd('schedule');
	$remove = getCmd('schedule_remove');
	if (in_array($schedule, array('schedule', 'schedule2'), true) &&
		in_array($remove, array('schedule_remove', 'schedule_remove2'), true)) {
		return($alias === 'schedule' ? 'schedule' : 'schedule_remove');
	}
	if ($schedule === 'schedule' && $remove === 'schedule.remove') {
		return($alias === 'schedule' ? 'schedule' : 'schedule.remove');
	}
	return(false);
}

function retrackersProjectSourceScalarBatch($decoded)
{
	$schema = retrackersSourceScalarSchema(str_repeat('0', 40));
	if (!is_array($decoded) || array_keys($decoded) !== array('ok', 'family', 'value') ||
		$decoded['ok'] !== true || !in_array($decoded['family'], array(1, 2), true) ||
		!is_array($decoded['value']) || count($decoded['value']) !== count($schema)) {
		return(false);
	}
	$values = array();
	foreach ($schema as $index => $member) {
		$slot = $decoded['value'][$index];
		if (!is_array($slot) || array_keys($slot) !== array('ok', 'value') ||
			$slot['ok'] !== true || !is_string($slot['value'])) {
			return(false);
		}
		$values[$member['name']] = $slot['value'];
	}
	return(array('family' => $decoded['family'], 'values' => $values));
}

function retrackersDecodeSourceScalarResponse($raw)
{
	$schema = retrackersSourceScalarSchema(str_repeat('0', 40));
	$types = array();
	foreach ($schema as $member) {
		$types[] = $member['type'];
	}
	return(retrackersProjectSourceScalarBatch(retrackersDecodeRestrictedRawResponse(
		$raw, retrackersRestrictedPlanScalarBatch($types))));
}

function retrackersBuildHistoricalBindingRequestV2()
{
	$members = array(
		array('method.list_keys', array('', 'event.download.inserted_new')),
		array('method.get', array('', 'event.download.inserted_new')),
		array('d.multicall2', array('', 'main', 'd.hash=', 'd.local_id=',
			'd.custom=retrackers-recovery', 'd.custom=retrackers-recovery-ack')),
		array('method.list_keys', array('', 'rr.receipts.v1')),
		array('method.get', array('', 'rr.receipts.v1')),
	);
	$payload = '<?xml version="1.0" encoding="UTF-8"?><methodCall><methodName>system.multicall</methodName>' .
		'<params><param><value><array><data>' . "\r\n";
	foreach ($members as $member) {
		$payload .= '<value><struct><member><name>methodName</name><value><string>' . $member[0] .
			'</string></value></member><member><name>params</name><value><array><data>' . "\r\n";
		foreach ($member[1] as $parameter) {
			$payload .= '<value><string>' . $parameter . '</string></value>' . "\r\n";
		}
		$payload .= '</data></array></value></member></struct></value>' . "\r\n";
	}
	return($payload . '</data></array></value></param></params></methodCall>');
}

class RetrackersDirectRpcAdapter
{
	protected $family = null;

	protected function send($payload, $plan, &$failure = null, $historical = false)
	{
		if (!is_string($payload) || $payload === '' || !is_array($plan)) {
			$failure = 'invalid-request';
			return(false);
		}
		global $scgi_host, $scgi_port, $rpcTransferTimeOut, $rpcMaxResponseBytes;
		$failure = null;
		// The 0.25 second budget is connect-only; read timeout and response cap remain independent.
		$raw = rSCGITransport::send(
			$scgi_host,
			$scgi_port,
			$payload,
			true,
			0.25,
			$failure,
			isset($rpcTransferTimeOut) ? $rpcTransferTimeOut : null,
			isset($rpcMaxResponseBytes) ? $rpcMaxResponseBytes : null,
			rSCGITransport::RESPONSE_RAW
		);
		if ($raw === null) {
			return(false);
		}
		if ($historical) {
			$classified = retrackersDecodeHistoricalBindingResponseV2($raw);
			if (!$classified['ok']) {
				$failure = $classified['failure'];
				return(false);
			}
			return($classified['sample']);
		}
		$decoded = retrackersDecodeRestrictedRawResponse($raw, $plan);
		if ($decoded === false) {
			$failure = 'malformed-response';
			return(false);
		}
		return($decoded);
	}

	public function historicalBindingSample(&$failure = null)
	{
		return($this->send(retrackersBuildHistoricalBindingRequestV2(),
			retrackersRestrictedPlanHistoricalBindingV2(), $failure, true));
	}

	private function sourceTargetAbsentFault($family, $fault)
	{
		if (!is_array($fault) || count($fault) !== 3 ||
			!array_key_exists('code', $fault) || !array_key_exists('code_type', $fault) ||
			!array_key_exists('message', $fault)) {
			return(false);
		}
		if ($family === 1) {
			return($fault['code'] === '-501' && $fault['code_type'] === 'i4' &&
				$fault['message'] === 'Could not find info-hash.');
		}
		if ($family === 2) {
			return($fault['code'] === '-500' && $fault['code_type'] === 'i8' &&
				$fault['message'] === 'invalid parameters: info-hash not found');
		}
		return(false);
	}

	private function sourceScalarTargetAbsent($decoded, $schema, $hash)
	{
		if (!is_array($decoded) || array_keys($decoded) !== array('ok', 'family', 'value') ||
			$decoded['ok'] !== true || !in_array($decoded['family'], array(1, 2), true) ||
			!is_array($decoded['value']) || count($decoded['value']) !== count($schema)) {
			return(false);
		}
		$targetMembers = 0;
		foreach ($schema as $index => $member) {
			$slot = $decoded['value'][$index];
			$isTargetMember = isset($member['parameters'][0]) &&
				$member['parameters'][0] === $hash;
			if ($isTargetMember) {
				$targetMembers++;
				if (!is_array($slot) || array_keys($slot) !== array('ok', 'fault') ||
					$slot['ok'] !== false ||
					!$this->sourceTargetAbsentFault($decoded['family'], $slot['fault'])) {
					return(false);
				}
			} elseif (!is_array($slot) || array_keys($slot) !== array('ok', 'value') ||
				$slot['ok'] !== true || !is_string($slot['value'])) {
				return(false);
			}
		}
		return($targetMembers > 0);
	}

	public function sourceScalarSnapshot($hash, &$failure = null)
	{
		$schema = retrackersSourceScalarSchema($hash);
		$payload = retrackersBuildSourceScalarRequest($hash);
		if ($schema === false || $payload === false) {
			$failure = 'invalid-request';
			return(false);
		}
		$types = array();
		foreach ($schema as $member) {
			$types[] = $member['type'];
		}
		$decoded = $this->send($payload, retrackersRestrictedPlanScalarBatch($types), $failure);
		if ($decoded === false) {
			return(false);
		}
		if (isset($decoded['ok']) && $decoded['ok'] === false) {
			$failure = 'rpc-fault';
			return(false);
		}
		if ($this->sourceScalarTargetAbsent($decoded, $schema, $hash)) {
			$failure = 'target-absent';
			return(false);
		}
		if (isset($decoded['value']) && is_array($decoded['value'])) {
			foreach ($decoded['value'] as $slot) {
				if (is_array($slot) && isset($slot['ok']) && $slot['ok'] === false) {
					$failure = 'rpc-fault';
					return(false);
				}
			}
		}
		$projected = retrackersProjectSourceScalarBatch($decoded);
		if ($projected === false) {
			$failure = 'malformed-response';
			return(false);
		}
		$failure = null;
		return($projected);
	}

	public function sourceGenericMapSnapshot($hash, &$failure = null)
	{
		$payload = retrackersBuildSourceGenericMapRequest($hash);
		if ($payload === false) {
			$failure = 'invalid-request';
			return(false);
		}
		$decoded = $this->send($payload, retrackersRestrictedPlanGenericMap(), $failure);
		if ($decoded === false) {
			return(false);
		}
		if (isset($decoded['ok']) && $decoded['ok'] === false) {
			$failure = $this->sourceTargetAbsentFault($decoded['family'], $decoded['fault']) ?
				'target-absent' : 'rpc-fault';
			return(false);
		}
		if (array_keys($decoded) !== array('ok', 'family', 'value') ||
			$decoded['ok'] !== true || !in_array($decoded['family'], array(1, 2), true) ||
			!is_array($decoded['value'])) {
			$failure = 'malformed-response';
			return(false);
		}
		$failure = null;
		return(array('family' => $decoded['family'], 'pairs' => $decoded['value']));
	}

	public function sourceTrackerSnapshot($hash, &$failure = null)
	{
		$payload = retrackersBuildSourceTrackerRequest($hash);
		if ($payload === false) {
			$failure = 'invalid-request';
			return(false);
		}
		$decoded = $this->send($payload, retrackersRestrictedPlanTrackerRows(), $failure);
		if ($decoded === false) {
			return(false);
		}
		if (isset($decoded['ok']) && $decoded['ok'] === false) {
			$failure = $this->sourceTargetAbsentFault($decoded['family'], $decoded['fault']) ?
				'target-absent' : 'rpc-fault';
			return(false);
		}
		if (array_keys($decoded) !== array('ok', 'family', 'value') ||
			$decoded['ok'] !== true || !in_array($decoded['family'], array(1, 2), true) ||
			!is_array($decoded['value'])) {
			$failure = 'malformed-response';
			return(false);
		}
		$failure = null;
		return(array('family' => $decoded['family'], 'rows' => $decoded['value']));
	}
}

class RetrackersLifecycleRpcAdapter extends RetrackersDirectRpcAdapter
{
	public function checkerServiceMarker($hash, &$failure = null)
	{
		if (!is_string($hash) || preg_match('/^[0-9A-F]{40}$/D', $hash) !== 1) {
			$failure = 'invalid-request';
			return(false);
		}
		$payload = retrackersBuildDirectRequest('d.custom', array($hash, 'chk-meta-old'));
		$decoded = $this->send($payload, retrackersRestrictedPlanDirectScalar('string'), $failure);
		if ($decoded === false || !isset($decoded['ok']) || $decoded['ok'] !== true ||
			!isset($decoded['value']) || !is_string($decoded['value'])) {
			$failure = 'hook-deferred-replay-pending';
			return(false);
		}
		$failure = null;
		return($decoded['value']);
	}

	private function duplicateLedgerFault($family, $fault)
	{
		if (!is_array($fault) || count($fault) !== 3 ||
			!array_key_exists('code', $fault) || !array_key_exists('code_type', $fault) ||
			!array_key_exists('message', $fault)) {
			return(false);
		}
		if ($family === 1) {
			return($fault['code'] === '-503' && $fault['code_type'] === 'i4' &&
				$fault['message'] === 'Invalid key.');
		}
		if ($family === 2) {
			return($fault['code'] === '-500' && $fault['code_type'] === 'i8' &&
				$fault['message'] === 'Invalid key.');
		}
		return(false);
	}

	public function getFamily()
	{
		return($this->family !== null ? $this->family : 2);
	}

	public function setFamily($family)
	{
		$this->family = $family;
	}

	private function methodNames(&$failure)
	{
		$decoded = $this->send(retrackersBuildDirectRequest('system.listMethods', array()),
			retrackersRestrictedPlanMethodNames(), $failure);
		if (!is_array($decoded) || !isset($decoded['ok'], $decoded['family'], $decoded['value']) ||
			$decoded['ok'] !== true || !in_array($decoded['family'], array(1, 2), true) ||
			!is_array($decoded['value']) ||
			($this->family !== null && $this->family !== $decoded['family'])) {
			$failure = 'rpc-family-unconfirmed';
			return(false);
		}
		$this->family = $decoded['family'];
		$failure = null;
		return($decoded['value']);
	}

	public function probeFamily(&$failure = null)
	{
		if ($this->family === 1 || $this->family === 2) {
			$failure = null;
			return($this->family);
		}
		$methods = $this->methodNames($failure);
		return($methods === false ? false : $this->family);
	}

	private function nativeMethodsAvailable(array $required, $reason, &$failure)
	{
		if ($this->getFamily() !== 2) {
			$failure = null;
			return(false);
		}
		$methods = $this->methodNames($failure);
		if (!is_array($methods) || $this->family !== 2) {
			$failure = $reason;
			return(false);
		}
		foreach ($required as $method) {
			if (!in_array($method, $methods, true)) {
				$failure = $reason;
				return(false);
			}
		}
		$failure = null;
		return(true);
	}

	public function nativeOriginalCapability(&$failure = null)
	{
		return($this->nativeMethodsAvailable(array('d.retrackers.begin_original',
			'd.retrackers.finish_original', 'd.incarnation',
			'system.retrackers.finalized'), 'native-original-capability-unconfirmed',
			$failure));
	}

	public function nativeSameHashCapability(&$failure = null)
	{
		return($this->nativeMethodsAvailable(array('d.retrackers.begin_original',
			'd.retrackers.finish_original', 'd.incarnation',
			'system.retrackers.finalized', 'd.retrackers.capture_erase',
			'd.retrackers.apply', 'd.retrackers.commit',
			'd.retrackers.release_committed', 'system.retrackers.receipt',
			'system.retrackers.prepared', 'load.normal'),
			'native-samehash-capability-unconfirmed', $failure));
	}

	protected function nativeOriginalScalar($method, array $params, &$failure)
	{
		$decoded = $this->send(retrackersBuildDirectRequest($method, $params),
			retrackersRestrictedPlanDirectScalar('string'), $failure);
		if (!is_array($decoded) || !isset($decoded['ok'], $decoded['family'],
			$decoded['value']) || $decoded['ok'] !== true || $decoded['family'] !== 2 ||
			!is_string($decoded['value'])) {
			$failure = 'native-original-rpc-unconfirmed';
			return(false);
		}
		$failure = null;
		return($decoded['value']);
	}

	public function nativeOriginalIncarnation($hash, &$failure = null)
	{
		$value = $this->nativeOriginalScalar('d.incarnation', array($hash), $failure);
		if (!is_string($value) || preg_match('/^[0-9a-f]{32}$/D', $value) !== 1) {
			$failure = 'native-original-incarnation-unconfirmed';
			return(false);
		}
		return($value);
	}

	public function nativeFinalizedReceipt($hash, &$failure = null)
	{
		$receipt = $this->nativeOriginalScalar('system.retrackers.finalized',
			array('', $hash), $failure);
		if (!is_string($receipt) ||
			preg_match('/^finalized:([0-9a-f]{32}):([0-9a-f]{32})$/D', $receipt, $parts) !== 1) {
			$failure = 'native-original-finalize-pending';
			return(false);
		}
		$failure = null;
		return(array('tx' => $parts[1], 'incarnation' => $parts[2]));
	}

	public function ensureLedgerExists(&$failure = null)
	{
		$payload = retrackersBuildDirectRequest('method.insert', array('', 'rr.receipts.v1', 'multi|private'));
		$decoded = $this->send($payload, retrackersRestrictedPlanFamilyMutationScalar(), $failure);
		if (is_array($decoded) && isset($decoded['family']) &&
			in_array($decoded['family'], array(1, 2), true)) {
			$this->setFamily($decoded['family']);
		}
		if ($decoded !== false && isset($decoded['ok']) && $decoded['ok'] === true) {
			$failure = null;
			return(true);
		}
		if (is_array($decoded) && count($decoded) === 3 && isset($decoded['ok'], $decoded['family']) &&
			$decoded['ok'] === false && isset($decoded['fault']) &&
			$this->duplicateLedgerFault($decoded['family'], $decoded['fault'])) {
			$failure = null;
			return(true);
		}
		$failure = 'receipt-ledger-corrupt';
		return(false);
	}

	public function directPrivacyCheck(&$failure = null)
	{
		$payload = retrackersBuildDirectRequest('rr.receipts.v1', array(''));
		$intType = ($this->family === 1) ? 'i4' : 'i8';
		$decoded = $this->send($payload, retrackersRestrictedPlanDirectScalar($intType), $failure);
		if (is_array($decoded) && isset($decoded['family']) &&
			in_array($decoded['family'], array(1, 2), true)) {
			$this->setFamily($decoded['family']);
		}
		if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === false &&
			isset($decoded['fault']['code']) && (string)$decoded['fault']['code'] === '-506') {
			$failure = null;
			return(true);
		}
		$failure = 'receipt-ledger-corrupt';
		return(false);
	}

	public function ledgerHas($key, &$failure = null)
	{
		$payload = retrackersBuildDirectRequest('method.has_key', array('', 'rr.receipts.v1', $key));
		$decoded = $this->send($payload, retrackersRestrictedPlanLedgerHas(), $failure);
		if ($decoded === false || !isset($decoded['value'])) {
			return(false);
		}
		return($decoded['value']);
	}

	public function getLedgerKeys(&$failure = null)
	{
		$payload = retrackersBuildSystemMulticallRequest(array(
			array('method.list_keys', array('', 'rr.receipts.v1')),
			array('method.get', array('', 'rr.receipts.v1')),
		));
		$decoded = $this->send($payload, retrackersRestrictedPlanLedger(array()), $failure);
		if ($decoded === false || !isset($decoded['value'][0]['value'])) {
			return(false);
		}
		return($decoded['value'][0]['value']);
	}

	public function downloadLocalIdRows(&$failure = null)
	{
		$payload = retrackersBuildDirectRequest('d.multicall2', array('', 'main', 'd.hash=', 'd.local_id='));
		$decoded = $this->send($payload, retrackersRestrictedPlanDownloadRows(), $failure);
		if ($decoded === false || !isset($decoded['value'])) {
			return(false);
		}
		return($decoded['value']);
	}

	private function executeLedgerMutation($method, array $params, &$failure = null)
	{
		if ($method !== 'method.set_key') {
			$failure = 'invalid-request';
			return(false);
		}
		if (!isset($params[0]) || $params[0] !== '') {
			array_unshift($params, '');
		}
		$intType = ($this->family === 1) ? 'i4' : 'i8';
		$payload = retrackersBuildDirectRequest($method, $params);
		$decoded = $this->send($payload, retrackersRestrictedPlanDirectScalar($intType), $failure);
		if ($decoded === false || !isset($decoded['ok']) || $decoded['ok'] !== true) {
			if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === false) {
				$failure = 'rpc-fault';
			}
			return(false);
		}
		$failure = null;
		return(true);
	}

	public function executeLifecycleCallback(RetrackersLifecycleCallbacks $callback,
		&$failure = null)
	{
		$payload = retrackersBuildDirectRequest($callback->method(), $callback->params());
		$decoded = $this->send($payload, $callback->responsePlan($this->getFamily()), $failure);
		if ($decoded === false || !isset($decoded['ok']) || $decoded['ok'] !== true) {
			if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === false) {
				$failure = 'rpc-fault';
			}
			return(false);
		}
		if (!isset($decoded['value']) || !is_string($decoded['value'])) {
			$failure = 'malformed-response';
			return(false);
		}
		$failure = null;
		return($decoded['value']);
	}

	public function coherentLifecycleReadback($canonicalUser, $expectedFunctionalAction,
		&$failure = null)
	{
		$classifier = new RetrackersHistoricalBindingClassifier();
		$firstSample = $this->historicalBindingSample($failure);
		if ($firstSample === false) {
			return(false);
		}
		$first = $classifier->classify($firstSample, $canonicalUser, $expectedFunctionalAction);
		unset($firstSample);
		$secondSample = $this->historicalBindingSample($failure);
		if ($secondSample === false) {
			return(false);
		}
		$second = $classifier->classify($secondSample, $canonicalUser, $expectedFunctionalAction);
		unset($secondSample);
		if ($first['status'] === 'malformed' || $second['status'] === 'malformed') {
			$failure = 'malformed-response';
			return(false);
		}
		if ($first['status'] === 'ledger-corrupt' || $second['status'] === 'ledger-corrupt') {
			$failure = 'receipt-ledger-corrupt';
			return(false);
		}
		if ($first['stable'] === $second['stable'] &&
			$second['decision']['binding']['family'] === $this->getFamily() &&
			$second['status'] === 'hook-ledger-mismatch') {
			$failure = 'hook-ledger-mismatch-restart-required';
			return(false);
		}
		if ($first['status'] !== 'valid' || $second['status'] !== 'valid' ||
			$first['stable'] !== $second['stable'] ||
			$second['decision']['binding']['family'] !== $this->getFamily()) {
			$failure = 'profile-binding-unstable';
			return(false);
		}
		$failure = null;
		return($second['decision']);
	}

	public function attestLedger(&$failure = null)
	{
		$keys = $this->getLedgerKeys($failure);
		if ($keys === false) {
			$failure = 'receipt-ledger-corrupt';
			return(false);
		}
		if (!$this->directPrivacyCheck($failure)) {
			return(false);
		}
		$probe = bin2hex(random_bytes(16));
		$probeKey = 'eb:' . $probe;
		if ($this->ledgerHas($probeKey, $failure) !== '0') {
			$failure = 'receipt-ledger-corrupt';
			return(false);
		}
		if (!$this->executeLedgerMutation('method.set_key', array('rr.receipts.v1', $probeKey, '1'), $failure)) {
			$failure = 'receipt-ledger-corrupt';
			return(false);
		}
		if ($this->ledgerHas($probeKey, $failure) !== '1') {
			$failure = 'receipt-ledger-corrupt';
			return(false);
		}
		if (!$this->executeLedgerMutation('method.set_key', array('rr.receipts.v1', $probeKey), $failure)) {
			$failure = 'receipt-ledger-corrupt';
			return(false);
		}
		if ($this->ledgerHas($probeKey, $failure) !== '0') {
			$failure = 'receipt-ledger-corrupt';
			return(false);
		}
		// Reuse check
		if (!$this->executeLedgerMutation('method.set_key', array('rr.receipts.v1', $probeKey, '1'), $failure)) {
			$failure = 'receipt-ledger-corrupt';
			return(false);
		}
		if ($this->ledgerHas($probeKey, $failure) !== '1') {
			$failure = 'receipt-ledger-corrupt';
			return(false);
		}
		if (!$this->executeLedgerMutation('method.set_key', array('rr.receipts.v1', $probeKey), $failure)) {
			$failure = 'receipt-ledger-corrupt';
			return(false);
		}
		if ($this->ledgerHas($probeKey, $failure) !== '0') {
			$failure = 'receipt-ledger-corrupt';
			return(false);
		}
		return(new RetrackersHistoricalLedgerAttestation(true, true, true, true));
	}
}

/** Worker-only transport. Lifecycle ownership code never receives this generic surface. */
class RetrackersWorkerRpcAdapter extends RetrackersLifecycleRpcAdapter
{
	const PREFLIGHT_REQUEST_MAX = 65536;
	private $recoveryFailure = null;
	private $diagnosticHash = null;
	private $diagnosticRecorded = false;

	public function __construct($diagnosticHash = null)
	{
		if (is_string($diagnosticHash) &&
			preg_match('/^[0-9A-F]{40}$/D', $diagnosticHash) === 1) {
			$this->diagnosticHash = $diagnosticHash;
		}
	}

	public function recordRecoveryFailure($failure)
	{
		$this->recoveryFailure = retrackersBoundedFailureReason($failure);
		if ($this->diagnosticHash !== null && !$this->diagnosticRecorded &&
			class_exists('FileUtil') && method_exists('FileUtil', 'toLog')) {
			$this->diagnosticRecorded = true;
			FileUtil::toLog('retrackers-recovery: ' . $this->diagnosticHash . ' ' .
				$this->recoveryFailure);
		}
	}

	public function recoveryFailure()
	{
		return($this->recoveryFailure);
	}

	public function nativeFinishOriginal($hash, $tx, $incarnation, &$failure = null)
	{
		return($this->nativeOriginalScalar('d.retrackers.finish_original',
			array($hash, $tx, $incarnation), $failure));
	}

	public function nativeFinalizedOriginal($hash, $tx, $incarnation, &$failure = null)
	{
		$receipt = $this->nativeOriginalScalar('system.retrackers.finalized',
			array('', $hash), $failure);
		if ($receipt !== 'finalized:' . $tx . ':' . $incarnation) {
			$failure = 'native-original-finalize-pending';
			return(false);
		}
		return(true);
	}

	public function nativeCaptureOriginal($hash, $localId, $marker, $tx, $digest,
		&$failure = null)
	{
		$decoded = $this->send(retrackersBuildDirectRequest(
			'd.retrackers.capture_erase',
			array($hash, $localId, $marker, $hash, $tx, $digest)),
			retrackersRestrictedPlanDirectScalar('i8'), $failure);
		if (!is_array($decoded) || !isset($decoded['ok'], $decoded['family'],
			$decoded['value']) || $decoded['ok'] !== true ||
			$decoded['family'] !== 2 || $decoded['value'] !== '1') {
			$failure = 'native-capture-unconfirmed';
			return(false);
		}
		$failure = null;
		return(true);
	}

	public function nativeWorkerReceipt($hash, &$failure = null)
	{
		return($this->nativeOriginalScalar('system.retrackers.receipt',
			array('', $hash), $failure));
	}

	public function nativePreparedReceipt($hash, &$failure = null)
	{
		return($this->nativeOriginalScalar('system.retrackers.prepared',
			array('', $hash), $failure));
	}

	public function nativeCommitSameHash($hash, $localId, $tx, &$failure = null)
	{
		return($this->nativeOriginalScalar('d.retrackers.commit',
			array($hash, $hash, $localId, $tx), $failure));
	}

	public function nativeReleaseSameHash($hash, $tx, $incarnation, &$failure = null)
	{
		return($this->nativeOriginalScalar('d.retrackers.release_committed',
			array($hash, $tx, $incarnation), $failure));
	}

	public function maxContentSize(&$failure = null)
	{
		if (!class_exists('rTorrentSettings') ||
			!method_exists('rTorrentSettings', 'get')) {
			$failure = 'commit-request-limit-invalid';
			return(false);
		}
		$settings = rTorrentSettings::get();
		$limit = is_object($settings) && method_exists($settings, 'maxContentSize') ?
			$settings->maxContentSize() : false;
		if (!is_int($limit) || $limit < 1) {
			$failure = 'commit-request-limit-invalid';
			return(false);
		}
		// A single-command getter does not enter the multicall splitter that
		// consults rTorrentSettings::maxContentSize(). Read the daemon limit
		// before preparing the commit: it may be below the API ceiling.
		$read = new rXMLRPCRequest(new rXMLRPCCommand('get_xmlrpc_size_limit'));
		$read->important = false;
		if (!$read->success() || !isset($read->val[0])) {
			$failure = 'commit-request-limit-invalid';
			return(false);
		}
		$daemonLimit = $read->val[0];
		if (is_string($daemonLimit) && preg_match('/^[1-9][0-9]*$/D', $daemonLimit) === 1
			&& retrackersRestrictedIntegerInRange($daemonLimit, 'i8')
			&& strlen($daemonLimit) <= strlen((string)PHP_INT_MAX)
			&& (strlen($daemonLimit) < strlen((string)PHP_INT_MAX)
				|| strcmp($daemonLimit, (string)PHP_INT_MAX) <= 0))
			$daemonLimit = (int)$daemonLimit;
		if (!is_int($daemonLimit) || $daemonLimit < 1) {
			$failure = 'commit-request-limit-invalid';
			return(false);
		}
		$failure = null;
		return(min($limit, $daemonLimit));
	}

	protected function normalizeWorkerMethodAndParams(&$method, array &$params)
	{
		if ($method === 'system.method.set_key') {
			$method = 'method.set_key';
		}
		if ($method === 'method.set_key' && (!isset($params[0]) || $params[0] !== '')) {
			array_unshift($params, '');
		}
	}

	public function executeMulticall(array $calls, &$failure = null)
	{
		$normalizedCalls = array();
		foreach ($calls as $call) {
			$method = $call[0];
			$params = $call[1];
			$this->normalizeWorkerMethodAndParams($method, $params);
			$normalizedCalls[] = array($method, $params);
		}
		$intType = ($this->getFamily() === 1) ? 'i4' : 'i8';
		$payload = retrackersBuildSystemMulticallRequest($normalizedCalls);
		$decoded = $this->send($payload,
			retrackersRestrictedPlanScalarBatch(array_fill(0, count($normalizedCalls), $intType)),
			$failure);
		if ($decoded === false || !isset($decoded['ok']) || $decoded['ok'] !== true) {
			if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === false) {
				$failure = 'rpc-fault';
			}
			return(false);
		}
		foreach ($decoded['value'] as $slot) {
			if (!is_array($slot) || !isset($slot['ok']) || $slot['ok'] !== true) {
				$failure = 'rpc-fault';
				return(false);
			}
		}
		$failure = null;
		return(true);
	}

	protected function freshPreflightNonce()
	{
		return(bin2hex(random_bytes(16)));
	}

	private function preflightProbe()
	{
		return('$a=$argv;if(count($a)!==7||preg_match("#^/proc/[1-9][0-9]*/fd/[0-9]+$#D",$a[1])!==1||' .
			'preg_match("/^(?:0|[1-9][0-9]*)$/D",$a[2])!==1||preg_match("/^(?:0|[1-9][0-9]*)$/D",$a[3])!==1||' .
			'preg_match("/^(?:0|[1-9][0-9]*)$/D",$a[4])!==1||preg_match("/^[0-9a-f]{64}$/D",$a[5])!==1||' .
			'preg_match("/^[0-9a-f]{32}$/D",$a[6])!==1)exit(20);$n=(int)$a[4];if((string)$n!==$a[4]||$n>67108864)exit(21);' .
			'$s=@stat($a[1]);if(!is_array($s)||' .
			'($s["mode"]&0170000)!==0100000||($s["mode"]&0777)!==0600||$s["nlink"]!==0||' .
			'(string)$s["dev"]!==$a[2]||(string)$s["ino"]!==$a[3]||(string)$s["size"]!==$a[4])exit(23);' .
			'if(!function_exists("proc_open"))exit(24);$d=array(0=>array("pipe","r"),1=>array("pipe","w"),' .
			'2=>array("pipe","w"));$p=@proc_open(array("/bin/cat",$a[1]),$d,$pipes);' .
			'if(!is_resource($p)||!is_array($pipes)||array_keys($pipes)!==array(0,1,2))exit(25);fclose($pipes[0]);' .
			'$c=hash_init("sha256");$r=$n;$bad=false;while(!feof($pipes[1])){$b=@fread($pipes[1],65536);' .
			'if($b===false||($b===""&&!feof($pipes[1]))){$bad=true;break;}$l=strlen($b);' .
			'if($l>$r){$bad=true;break;}hash_update($c,$b);$r-=$l;}fclose($pipes[1]);' .
			'$err=@fread($pipes[2],1);fclose($pipes[2]);if($bad){@proc_terminate($p);@proc_close($p);exit(26);}' .
			'$x=@proc_close($p);if($err===false||$err!==""||$x!==0||$r!==0||' .
			'!hash_equals($a[5],hash_final($c)))exit(27);echo $a[6];');
	}

	private function validPreflightProof($proof)
	{
		return(is_array($proof) && array_keys($proof) ===
			array('capability', 'dev', 'ino', 'size', 'sha256') &&
			is_string($proof['capability']) &&
			preg_match('#^/proc/[1-9][0-9]*/fd/[0-9]+$#D', $proof['capability']) === 1 &&
			is_string($proof['dev']) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $proof['dev']) === 1 &&
			is_string($proof['ino']) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $proof['ino']) === 1 &&
			is_string($proof['size']) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $proof['size']) === 1 &&
			is_string($proof['sha256']) && preg_match('/^[0-9a-f]{64}$/D', $proof['sha256']) === 1);
	}

	/** One closed pre-arm boundary: two argv-only execute.capture probes in one request. */
	public function preflightStage(RetrackersAnonymousStage $stage, $php, &$failure = null)
	{
		$failure = 'procfd-preflight-failed';
		if (!is_string($php) || $php === '' || strlen($php) > 4096 ||
			strpbrk($php, "\0\r\n") !== false) {
			return(false);
		}
		$proofs = array($stage->candidate(), $stage->original());
		if (!$this->validPreflightProof($proofs[0]) || !$this->validPreflightProof($proofs[1])) {
			return(false);
		}
		$nonces = array($this->freshPreflightNonce(), $this->freshPreflightNonce());
		if (!is_string($nonces[0]) || !is_string($nonces[1]) || $nonces[0] === $nonces[1] ||
			preg_match('/^[0-9a-f]{32}$/D', $nonces[0]) !== 1 ||
			preg_match('/^[0-9a-f]{32}$/D', $nonces[1]) !== 1) {
			return(false);
		}
		$probe = $this->preflightProbe();
		$calls = array();
		foreach ($proofs as $index => $proof) {
			$calls[] = array('execute.capture', array(
				'', $php, '-r', $probe, $proof['capability'], $proof['dev'], $proof['ino'],
				$proof['size'], $proof['sha256'], $nonces[$index],
			));
		}
		$payload = retrackersBuildSystemMulticallRequest($calls);
		if (strlen($payload) > self::PREFLIGHT_REQUEST_MAX) {
			return(false);
		}
		$transportFailure = null;
		$decoded = $this->send($payload,
			retrackersRestrictedPlanScalarBatch(array('string', 'string')), $transportFailure);
		if ($decoded === false || array_keys($decoded) !== array('ok', 'family', 'value') ||
			$decoded['ok'] !== true || !is_array($decoded['value']) || count($decoded['value']) !== 2) {
			return(false);
		}
		foreach ($decoded['value'] as $index => $slot) {
			if (!is_array($slot) || array_keys($slot) !== array('ok', 'value') ||
				$slot['ok'] !== true || !is_string($slot['value']) ||
				!hash_equals($nonces[$index], $slot['value'])) {
				return(false);
			}
		}
		$failure = null;
		return(true);
	}

	public function executeDirectMutation($method, array $params, &$failure = null)
	{
		$this->normalizeWorkerMethodAndParams($method, $params);
		$intType = ($this->getFamily() === 1) ? 'i4' : 'i8';
		$decoded = $this->send(retrackersBuildDirectRequest($method, $params),
			retrackersRestrictedPlanDirectScalar($intType), $failure);
		if ($decoded === false || !isset($decoded['ok']) || $decoded['ok'] !== true) {
			if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === false) {
				$failure = 'rpc-fault';
			}
			return(false);
		}
		$failure = null;
		return(true);
	}

	private function executeWorkerStringCallback($callback, &$failure = null)
	{
		$decoded = $this->send(
			retrackersBuildDirectRequest($callback->method(), $callback->params()),
			$callback->responsePlan($this->getFamily()), $failure);
		if ($decoded === false || !isset($decoded['ok']) || $decoded['ok'] !== true ||
			!isset($decoded['value']) || !is_string($decoded['value'])) {
			if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === false) {
				$failure = 'rpc-fault';
			}
			return(false);
		}
		$failure = null;
		return($decoded['value']);
	}

	public function armPhase($phase, $tx, &$failure = null)
	{
		$callback = RetrackersRecoveryPhaseArmCallback::build($phase, $tx);
		if ($callback === false) {
			$failure = 'invalid-request';
			return(false);
		}
		return($this->executeWorkerStringCallback($callback, $failure));
	}

	public function executeHandoffRelease(RetrackersHandoffReleaseCallback $callback,
		&$failure = null)
	{
		return($this->executeWorkerStringCallback($callback, $failure));
	}

	public function executeTerminalCleanup(RetrackersTerminalCleanupCallback $callback,
		&$failure = null)
	{
		return($this->executeWorkerStringCallback($callback, $failure));
	}

	public function executeOldGenerationCommit(RetrackersOldGenerationCommitCallback $callback,
		&$failure = null)
	{
		$decoded = $this->send($callback->wire(),
			$callback->responsePlan($this->getFamily()), $failure);
		if ($decoded === false || !isset($decoded['ok']) || $decoded['ok'] !== true ||
			!isset($decoded['value']) || !is_string($decoded['value'])) {
			if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === false) {
				$failure = 'rpc-fault';
			}
			return(false);
		}
		$failure = null;
		return($decoded['value']);
	}

	public function executeLoadDispatch(RetrackersLoadDispatchCallback $callback,
		&$failure = null)
	{
		$decoded = $this->send($callback->wire(),
			$callback->responsePlan($this->getFamily()), $failure);
		if ($decoded === false || !isset($decoded['ok']) || $decoded['ok'] !== true ||
			!isset($decoded['value']) || !is_string($decoded['value'])) {
			if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === false) {
				$failure = 'rpc-fault';
			}
			return(false);
		}
		$failure = null;
		return($decoded['value']);
	}

	public function executeCandidateCleanup(RetrackersCandidateCleanupCallback $callback,
		&$failure = null)
	{
		$decoded = $this->send($callback->wire(),
			$callback->responsePlan($this->getFamily()), $failure);
		if ($decoded === false || !isset($decoded['ok']) || $decoded['ok'] !== true ||
			!isset($decoded['value']) || !is_string($decoded['value'])) {
			if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === false) {
				$failure = 'rpc-fault';
			}
			return(false);
		}
		$failure = null;
		return($decoded['value']);
	}

	public function loadPhaseReceipts($phase, $tx, &$failure = null)
	{
		$map = array('la' => array('lb', 'lf'), 'ra' => array('rb', 'rf'));
		if (!isset($map[$phase]) || !is_string($tx) ||
			preg_match('/^[0-9a-f]{32}$/D', $tx) !== 1) {
			$failure = 'invalid-request';
			return(false);
		}
		$keys = array($map[$phase][0] . ':' . $tx, $map[$phase][1] . ':' . $tx);
		$calls = array(
			array('method.list_keys', array('', 'rr.receipts.v1')),
			array('method.get', array('', 'rr.receipts.v1')),
			array('method.has_key', array('', 'rr.receipts.v1', $keys[0])),
			array('method.has_key', array('', 'rr.receipts.v1', $keys[1])),
		);
		$decoded = $this->send(retrackersBuildSystemMulticallRequest($calls),
			retrackersRestrictedPlanLedger($keys), $failure);
		if ($decoded === false || !isset($decoded['ok'], $decoded['value']) ||
			$decoded['ok'] !== true || !is_array($decoded['value']) ||
			count($decoded['value']) !== 4) {
			return(false);
		}
		$receipts = array();
		foreach (array('begin' => 2, 'fence' => 3) as $name => $index) {
			$slot = $decoded['value'][$index];
			if (!is_array($slot) || !isset($slot['ok'], $slot['value']) ||
				$slot['ok'] !== true || !in_array($slot['value'], array('0', '1'), true)) {
				$failure = 'receipt-ledger-corrupt';
				return(false);
			}
			$receipts[$name] = $slot['value'];
		}
		$failure = null;
		return($receipts);
	}

	public function cleanupPhaseReceipts($tx, &$failure = null)
	{
		if (!is_string($tx) || preg_match('/^[0-9a-f]{32}$/D', $tx) !== 1) {
			$failure = 'invalid-request';
			return(false);
		}
		$keys = array('cb:' . $tx, 'cd:' . $tx, 'cx:' . $tx);
		$calls = array(
			array('method.list_keys', array('', 'rr.receipts.v1')),
			array('method.get', array('', 'rr.receipts.v1')),
		);
		foreach ($keys as $key) {
			$calls[] = array('method.has_key', array('', 'rr.receipts.v1', $key));
		}
		$decoded = $this->send(retrackersBuildSystemMulticallRequest($calls),
			retrackersRestrictedPlanLedger($keys), $failure);
		if ($decoded === false || !isset($decoded['ok'], $decoded['value']) ||
			$decoded['ok'] !== true || !is_array($decoded['value']) ||
			count($decoded['value']) !== 5) {
			return(false);
		}
		$values = array();
		foreach (array('begin' => 2, 'done' => 3, 'exit' => 4) as $name => $index) {
			$slot = $decoded['value'][$index];
			if (!is_array($slot) || !isset($slot['ok'], $slot['value']) ||
				$slot['ok'] !== true || !in_array($slot['value'], array('0', '1'), true)) {
				$failure = 'receipt-ledger-corrupt';
				return(false);
			}
			$values[$name] = $slot['value'];
		}
		$failure = null;
		return($values);
	}

	public function oldGenerationCommitReceipts($tx, &$failure = null)
	{
		if (!is_string($tx) || preg_match('/^[0-9a-f]{32}$/D', $tx) !== 1) {
			$failure = 'invalid-request';
			return(false);
		}
		$keys = array('eb:' . $tx, 'ed:' . $tx, 'ex:' . $tx);
		$calls = array(
			array('method.list_keys', array('', 'rr.receipts.v1')),
			array('method.get', array('', 'rr.receipts.v1')),
		);
		foreach ($keys as $key) {
			$calls[] = array('method.has_key', array('', 'rr.receipts.v1', $key));
		}
		$decoded = $this->send(retrackersBuildSystemMulticallRequest($calls),
			retrackersRestrictedPlanLedger($keys), $failure);
		if ($decoded === false || !isset($decoded['ok'], $decoded['value']) ||
			$decoded['ok'] !== true || !is_array($decoded['value']) ||
			count($decoded['value']) !== 5) {
			return(false);
		}
		$values = array();
		foreach (array('begin' => 2, 'done' => 3, 'exit' => 4) as $name => $index) {
			$slot = $decoded['value'][$index];
			if (!is_array($slot) || !isset($slot['ok'], $slot['value']) ||
				$slot['ok'] !== true || !in_array($slot['value'], array('0', '1'), true)) {
				$failure = 'receipt-ledger-corrupt';
				return(false);
			}
			$values[$name] = $slot['value'];
		}
		$failure = null;
		return($values);
	}

	/** Pure pre-erase gate: validating a sealed obligation performs no RPC. */
	public function hasDurableCandidateFenceObligation($tx, &$failure = null,
		$obligation = null)
	{
		if (!($obligation instanceof RetrackersPostEraseObligation) ||
			$obligation->tx() !== $tx || !$obligation->complete()) {
			$failure = 'candidate-fence-not-implemented';
			return(false);
		}
		$failure = null;
		return(true);
	}

	public function executeWorkerAdoptCallback(RetrackersWorkerAdoptCallback $callback,
		&$failure = null)
	{
		$decoded = $this->send(
			retrackersBuildDirectRequest($callback->method(), $callback->params()),
			$callback->responsePlan($this->getFamily()), $failure);
		if ($decoded === false || !isset($decoded['ok']) || $decoded['ok'] !== true ||
			!isset($decoded['value']) || !is_string($decoded['value'])) {
			if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === false) {
				$failure = 'rpc-fault';
			}
			return(false);
		}
		$failure = null;
		return($decoded['value']);
	}
}

class RetrackersStableSourceSnapshot
{
	const MAX_ROUNDS = 3;
	const MAX_SCALAR_BYTES = 1048576;
	const MAX_TOTAL_TEXT_BYTES = 8388608;
	const MAX_MAP_MEMBERS = 12288;
	const MAX_TRACKER_ROWS = 16384;

	private $adapter;

	public function __construct($adapter = null)
	{
		$this->adapter = $adapter === null ? new RetrackersDirectRpcAdapter() : $adapter;
	}

	private function visibleReadFailure($failure)
	{
		if ($failure === 'target-absent') {
			return('initial-absent');
		}
		if ($failure === 'rpc-fault') {
			return('initial-fault');
		}
		if ($failure === 'malformed-response' || $failure === 'invalid-request') {
			return('initial-malformed');
		}
		return('initial-transport');
	}

	private function read($kind, $hash, &$failure)
	{
		if (!($this->adapter instanceof RetrackersDirectRpcAdapter)) {
			$failure = 'malformed-response';
			return(false);
		}
		if ($kind === 'scalar') {
			return($this->adapter->sourceScalarSnapshot($hash, $failure));
		}
		if ($kind === 'map') {
			return($this->adapter->sourceGenericMapSnapshot($hash, $failure));
		}
		if ($kind === 'tracker') {
			return($this->adapter->sourceTrackerSnapshot($hash, $failure));
		}
		$failure = 'malformed-response';
		return(false);
	}

	private function canonicalNonNegativeInteger($value)
	{
		return(is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1 &&
			retrackersRestrictedIntegerInRange($value, 'i8'));
	}

	private function canonicalSignedInteger($value)
	{
		return(is_string($value) &&
			preg_match('/^(?:0|-[1-9][0-9]*|[1-9][0-9]*)$/D', $value) === 1 &&
			retrackersRestrictedIntegerInRange($value, 'i8'));
	}

	private function scalar($sample)
	{
		$schema = retrackersSourceScalarSchema(str_repeat('0', 40));
		$names = array();
		foreach ($schema as $member) {
			$names[] = $member['name'];
		}
		if (!is_array($sample) || array_keys($sample) !== array('family', 'values') ||
			!in_array($sample['family'], array(1, 2), true) ||
			!is_array($sample['values']) || array_keys($sample['values']) !== $names) {
			return(false);
		}
		$total = 0;
		foreach ($sample['values'] as $value) {
			if (!is_string($value) || strlen($value) > self::MAX_SCALAR_BYTES) {
				return(false);
			}
			$total += strlen($value);
			if ($total > self::MAX_TOTAL_TEXT_BYTES) {
				return(false);
			}
		}
		$values = $sample['values'];
		foreach (array('priority') as $name) {
			if (!$this->canonicalNonNegativeInteger($values[$name])) {
				return(false);
			}
		}
		foreach (array('is_private', 'state', 'is_active', 'is_open',
			'trackers_use_udp') as $name) {
			if (!in_array($values[$name], array('0', '1'), true)) {
				return(false);
			}
		}
		if (!in_array($values['hashing'], array('0', '1', '2', '3'), true)) {
			return(false);
		}
		if (!$this->canonicalSignedInteger($values['hashing_failed'])) {
			return(false);
		}
		if ($sample['family'] === 2 && $values['trackers_use_udp'] !== '1') {
			return(false);
		}
		if (preg_match('/^[0-9A-F]{40}$/D', $values['local_id']) !== 1) {
			return(false);
		}
		return(array('family' => $sample['family'], 'values' => $values));
	}

	private function genericMap($sample)
	{
		if (!is_array($sample) || array_keys($sample) !== array('family', 'pairs') ||
			!in_array($sample['family'], array(1, 2), true) || !is_array($sample['pairs']) ||
			count($sample['pairs']) > self::MAX_MAP_MEMBERS) {
			return(false);
		}
		$pairs = array();
		$seen = array();
		$total = 0;
		foreach ($sample['pairs'] as $pair) {
			if (!is_array($pair) || array_keys($pair) !== array('name', 'value') ||
				!is_string($pair['name']) || !is_string($pair['value']) ||
				strlen($pair['name']) > self::MAX_SCALAR_BYTES ||
				strlen($pair['value']) > self::MAX_SCALAR_BYTES) {
				return(false);
			}
			$key = "\0" . $pair['name'];
			if (isset($seen[$key])) {
				return(false);
			}
			$seen[$key] = true;
			$total += strlen($pair['name']) + strlen($pair['value']);
			if ($total > self::MAX_TOTAL_TEXT_BYTES) {
				return(false);
			}
			$pairs[] = $pair;
		}
		usort($pairs, function ($left, $right) {
			return(strcmp($left['name'], $right['name']));
		});
		return(array('family' => $sample['family'], 'pairs' => $pairs));
	}

	private function compareDecimal($left, $right)
	{
		$length = strlen($left) - strlen($right);
		return($length !== 0 ? ($length < 0 ? -1 : 1) : strcmp($left, $right));
	}

	private function trackers($sample)
	{
		if (!is_array($sample) || array_keys($sample) !== array('family', 'rows') ||
			!in_array($sample['family'], array(1, 2), true) || !is_array($sample['rows']) ||
			count($sample['rows']) > self::MAX_TRACKER_ROWS) {
			return(false);
		}
		$topology = array();
		$enabled = array();
		$total = 0;
		foreach ($sample['rows'] as $row) {
			if (!is_array($row) || array_keys($row) !== array(0, 1, 2, 3, 4) ||
				!is_string($row[0]) || strlen($row[0]) > self::MAX_SCALAR_BYTES ||
				!$this->canonicalNonNegativeInteger($row[1]) ||
				!$this->canonicalNonNegativeInteger($row[2]) ||
				!in_array($row[3], array('0', '1'), true) ||
				!in_array($row[4], array('0', '1'), true)) {
				return(false);
			}
			$total += strlen($row[0]) + strlen($row[1]) + strlen($row[2]) + 2;
			if ($total > self::MAX_TOTAL_TEXT_BYTES) {
				return(false);
			}
			$topology[] = array('group' => $row[1], 'url' => $row[0],
				'type' => $row[2], 'extra' => $row[3]);
			$key = "\0" . $row[0];
			if (!isset($enabled[$key])) {
				$enabled[$key] = array('url' => $row[0], 'multiplicity' => 1,
					'state' => $row[4], 'ambiguous' => false);
			} else {
				$enabled[$key]['multiplicity']++;
				if ($enabled[$key]['state'] !== $row[4]) {
					$enabled[$key]['ambiguous'] = true;
					if (strcmp($row[4], $enabled[$key]['state']) < 0) {
						$enabled[$key]['state'] = $row[4];
					}
				}
			}
		}
		usort($topology, function ($left, $right) {
			$group = $this->compareDecimal($left['group'], $right['group']);
			if ($group !== 0) {
				return($group);
			}
			$url = strcmp($left['url'], $right['url']);
			if ($url !== 0) {
				return($url);
			}
			$type = $this->compareDecimal($left['type'], $right['type']);
			return($type !== 0 ? $type : strcmp($left['extra'], $right['extra']));
		});
		$ambiguous = false;
		$enabledRows = array_values($enabled);
		usort($enabledRows, function ($left, $right) {
			return(strcmp($left['url'], $right['url']));
		});
		foreach ($enabledRows as &$entry) {
			$ambiguous = $ambiguous || $entry['ambiguous'];
			unset($entry['ambiguous']);
		}
		unset($entry);
		return(array('family' => $sample['family'], 'topology' => $topology,
			'enabled' => $enabledRows, 'ambiguous' => $ambiguous));
	}

	private function mapValue($pairs, $name, &$present)
	{
		foreach ($pairs as $pair) {
			if ($pair['name'] === $name) {
				$present = true;
				return($pair['value']);
			}
		}
		$present = false;
		return(null);
	}

	private function accepted($hash, $handoff, $matched, $scalar, $map, $trackers)
	{
		$values = $scalar['values'];
		$markerPresent = false;
		$ackPresent = false;
		$mapMarker = $this->mapValue($map['pairs'], 'retrackers-recovery', $markerPresent);
		$mapAck = $this->mapValue($map['pairs'], 'retrackers-recovery-ack', $ackPresent);
		if (!$markerPresent || !$ackPresent || $values['recovery_marker'] !== $handoff ||
			$values['recovery_ack'] !== $handoff || $mapMarker !== $handoff ||
			$mapAck !== $handoff ||
			(strncmp($handoff, 'v2:', 3) !== 0 &&
				$values['local_id'] !== $matched[2]) ||
			$values['state'] !== $matched[1]) {
			return(array('ok' => false, 'failure' => 'ownership-mismatch'));
		}
		if ($values['hashing_failed'] !== '0') {
			return(array('ok' => false, 'failure' => 'initial-hashing-failed'));
		}
		$lifecycle = array($values['state'], $values['is_active'], $values['is_open']);
		$allowed = array(array('0', '0', '0'), array('0', '0', '1'), array('1', '1', '1'));
		if ($values['hashing'] !== '0' || !in_array($lifecycle, $allowed, true)) {
			return(array('ok' => false, 'failure' => 'lifecycle-unsupported'));
		}
		if ($values['session_path'] !== '') {
			$source = array('kind' => 'session',
				'path' => $values['session_path'] . $hash . '.torrent');
		} elseif ($values['tied_source'] !== '') {
			$source = array('kind' => 'tied', 'path' => $values['tied_source']);
		} else {
			return(array('ok' => false, 'failure' => 'source-unreadable'));
		}
		return(array('ok' => true, 'snapshot' => array(
			'family' => $scalar['family'],
			'scalar' => $values,
			'generic_map' => $map['pairs'],
			'tracker_topology' => $trackers['topology'],
			'tracker_enabled' => $trackers['enabled'],
			'source' => $source,
		)));
	}

	/**
	 * Read-only terminal observation for commit reconciliation. Unlike capture(),
	 * this accepts a quiesced or foreign generation so the caller can classify it.
	 */
	public function observe($hash)
	{
		if (!is_string($hash) || preg_match('/^[0-9A-F]{40}$/D', $hash) !== 1) {
			return(array('ok' => false, 'failure' => 'initial-malformed'));
		}
		$mapDrift = false;
		for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
			$samples = array();
			foreach (array('scalar', 'map', 'map', 'tracker', 'tracker', 'scalar') as $index => $kind) {
				$failure = null;
				$sample = $this->read($kind, $hash, $failure);
				if ($sample === false) {
					if ($index === 0 && $failure === 'target-absent') {
						return(array('ok' => true, 'presence' => 'absent'));
					}
					return(array('ok' => false,
						'failure' => $this->visibleReadFailure($failure)));
				}
				$samples[] = $sample;
			}
			$firstScalar = $this->scalar($samples[0]);
			$firstMap = $this->genericMap($samples[1]);
			$secondMap = $this->genericMap($samples[2]);
			$firstTrackers = $this->trackers($samples[3]);
			$secondTrackers = $this->trackers($samples[4]);
			$secondScalar = $this->scalar($samples[5]);
			unset($samples);
			if ($firstScalar === false || $firstMap === false || $secondMap === false ||
				$firstTrackers === false || $secondTrackers === false || $secondScalar === false) {
				return(array('ok' => false, 'failure' => 'initial-malformed'));
			}
			$mapStable = $firstScalar === $secondScalar && $firstMap === $secondMap &&
				$firstScalar['family'] === $firstMap['family'];
			$trackerStable = $firstTrackers === $secondTrackers &&
				$firstScalar['family'] === $firstTrackers['family'];
			if (!$mapStable || !$trackerStable) {
				$mapDrift = $mapDrift || !$mapStable;
				continue;
			}
			if ($secondTrackers['ambiguous']) {
				return(array('ok' => false, 'failure' => 'tracker-state-ambiguous'));
			}
			return(array('ok' => true, 'presence' => 'present', 'snapshot' => array(
				'family' => $secondScalar['family'],
				'scalar' => $secondScalar['values'],
				'generic_map' => $secondMap['pairs'],
				'tracker_topology' => $secondTrackers['topology'],
				'tracker_enabled' => $secondTrackers['enabled'],
			)));
		}
		return(array('ok' => false, 'failure' => $mapDrift ?
			'runtime-map-unstable' : 'tracker-state-ambiguous'));
	}

	public function capture($hash, $handoff)
	{
		if (!is_string($hash) || preg_match('/^[0-9A-F]{40}$/D', $hash) !== 1 ||
			!is_string($handoff) ||
			preg_match('/^v[12]:original:([01]):([0-9A-F]{40}):([0-9a-f]{64})(?::([0-9a-f]{32}))?$/D',
				$handoff, $matched) !== 1 ||
			(strncmp($handoff, 'v2:', 3) === 0) !== isset($matched[4])) {
			return(array('ok' => false, 'failure' => 'ownership-mismatch'));
		}
		$mapDrift = false;
		$trackerDrift = false;
		for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
			$samples = array();
			foreach (array('scalar', 'map', 'map', 'tracker', 'tracker', 'scalar') as $kind) {
				$failure = null;
				$sample = $this->read($kind, $hash, $failure);
				if ($sample === false) {
					return(array('ok' => false,
						'failure' => $this->visibleReadFailure($failure)));
				}
				$samples[] = $sample;
			}
			$firstScalar = $this->scalar($samples[0]);
			$firstMap = $this->genericMap($samples[1]);
			$secondMap = $this->genericMap($samples[2]);
			$firstTrackers = $this->trackers($samples[3]);
			$secondTrackers = $this->trackers($samples[4]);
			$secondScalar = $this->scalar($samples[5]);
			unset($samples);
			if ($firstScalar === false || $firstMap === false || $secondMap === false ||
				$firstTrackers === false || $secondTrackers === false || $secondScalar === false) {
				return(array('ok' => false, 'failure' => 'initial-malformed'));
			}
			$mapStable = $firstScalar === $secondScalar && $firstMap === $secondMap &&
				$firstScalar['family'] === $firstMap['family'];
			$trackerStable = $firstTrackers === $secondTrackers &&
				$firstScalar['family'] === $firstTrackers['family'];
			if (!$mapStable || !$trackerStable) {
				$mapDrift = $mapDrift || !$mapStable;
				$trackerDrift = $trackerDrift || !$trackerStable;
				continue;
			}
			if ($secondTrackers['ambiguous']) {
				return(array('ok' => false, 'failure' => 'tracker-state-ambiguous'));
			}
			return($this->accepted($hash, $handoff, $matched, $secondScalar,
				$secondMap, $secondTrackers));
		}
		return(array('ok' => false, 'failure' => $mapDrift ?
			'runtime-map-unstable' : 'tracker-state-ambiguous'));
	}
}

class RetrackersLifecycleCoordinator
{
	private static function freshToken($different = null)
	{
		do {
			$value = bin2hex(random_bytes(16));
		} while ($different !== null && hash_equals($different, $value));
		return($value);
	}

	private static function expectedReadback($decision, RetrackersLifecycleCallbacks $callback)
	{
		if (!is_array($decision) || !isset($decision['phase'], $decision['persistent_epoch'],
			$decision['transaction_receipts']) || !is_array($decision['transaction_receipts'])) {
			return(false);
		}
		$expected = $callback->expectedState();
		if ($expected['phase'] !== null && $decision['phase'] !== $expected['phase']) {
			return(false);
		}
		if ($decision['persistent_epoch'] !== $expected['epoch']) {
			return(false);
		}
		if ($expected['owner'] === null) {
			if ($expected['phase'] !== null && isset($decision['owner']) && $decision['owner'] !== null) {
				return(false);
			}
		} elseif (!isset($decision['owner']) || $decision['owner'] !== $expected['owner']) {
			return(false);
		}
		foreach ($expected['required_receipts'] as $key) {
			if (!in_array($key, $decision['transaction_receipts'], true)) {
				return(false);
			}
		}
		foreach ($expected['absent_receipts'] as $key) {
			if (in_array($key, $decision['transaction_receipts'], true)) {
				return(false);
			}
		}
		return(true);
	}

	private static function executeAndReadback($adapter, RetrackersLifecycleCallbacks $callback,
		$canonicalUser, $functionalAction, $unknownFailure, &$failure, $changedFailure = null,
		$readChanged = false)
	{
		$result = $adapter->executeLifecycleCallback($callback, $failure);
		if ($result === false) {
			$failure = $unknownFailure;
			return(false);
		}
		$known = is_string($result) && in_array($result, $callback->allowedSentinels(), true);
		$success = $known && hash_equals($callback->successSentinel(), $result);
		$changed = $known && hash_equals($callback->changedSentinel(), $result);
		if (!$known || (!$success && (!$readChanged || !$changed))) {
			$failure = $changedFailure === null ? $unknownFailure : $changedFailure;
			return(false);
		}
		$readback = $adapter->coherentLifecycleReadback(
			$canonicalUser, $functionalAction, $failure);
		if ($readback === false || !self::expectedReadback($readback, $callback)) {
			$failure = $unknownFailure;
			return(false);
		}
		return($readback);
	}

	private static function profileForUser($decision, $canonicalUser)
	{
		if (!isset($decision['profiles']) || !is_array($decision['profiles'])) {
			return(false);
		}
		foreach ($decision['profiles'] as $profile) {
			if (isset($profile['user']) && $profile['user'] === $canonicalUser) {
				return($profile);
			}
		}
		return(false);
	}

	private static function receiptLocalIds($decision, $prefix)
	{
		$ids = array();
		if (!isset($decision['transaction_receipts']) || !is_array($decision['transaction_receipts'])) {
			return(false);
		}
		foreach ($decision['transaction_receipts'] as $key) {
			if (strncmp($key, $prefix . ':', strlen($prefix) + 1) === 0) {
				$ids[] = substr($key, strlen($prefix) + 1);
			}
		}
		return($ids);
	}

	private static function hasActiveReceipt($decision)
	{
		if (!isset($decision['transaction_receipts']) || !is_array($decision['transaction_receipts'])) {
			return(true);
		}
		foreach ($decision['transaction_receipts'] as $key) {
			if ($key === 'dq:1' || preg_match(
				'/^(?:wh|wp|di):[0-9A-F]{40}$|^(?:wa|ea|eb|ed|ex|la|lb|lf|ca|cb|cd|cx|ra|rb|rf):[0-9a-f]{32}$|^v1:(?:candidate|rollback)-ready:[0-9a-f]{32}$/D',
				$key) === 1) {
				return(true);
			}
		}
		return(false);
	}

	private static function exactLocalIdMatches(array $rows, $localId)
	{
		$matches = array();
		foreach ($rows as $row) {
			if (is_array($row) && count($row) === 2 && isset($row[0], $row[1]) &&
				$row[1] === $localId) {
				$matches[] = $row[0];
			}
		}
		return($matches);
	}

	private static function replayDeferredInserts($adapter, $canonicalUser, $script, $php,
		$functionalAction, $nativeOriginal, &$decision, &$failure = null)
	{
		$owner = isset($decision['owner']) ? $decision['owner'] : null;
		if (!is_array($owner) || $owner['mode'] !== 'i') {
			$failure = 'hook-deferred-replay-pending';
			return(false);
		}
		$epoch = $decision['persistent_epoch'];
		if (in_array('dq:1', $decision['transaction_receipts'], true)) {
			$clear = RetrackersLifecycleCallbacks::clearDeferredDirty(
				$epoch, $owner['token'], 'i', $owner['user_hash']);
			$decision = self::executeAndReadback($adapter, $clear, $canonicalUser,
				$functionalAction, 'hook-deferred-replay-pending', $failure);
			if ($decision === false) {
				return(false);
			}
		}

		$deferred = self::receiptLocalIds($decision, 'di');
		if ($deferred === false) {
			$failure = 'hook-deferred-replay-pending';
			return(false);
		}
		$rows = array();
		if (count($deferred) > 0) {
			$rows = $adapter->downloadLocalIdRows($failure);
			if (!is_array($rows)) {
				$failure = 'hook-deferred-replay-pending';
				return(false);
			}
		}
		foreach ($deferred as $localId) {
			$matches = self::exactLocalIdMatches($rows, $localId);
			if (count($matches) > 1) {
				$failure = 'hook-deferred-replay-pending';
				return(false);
			}
			if (count($matches) === 0) {
				$stale = RetrackersLifecycleCallbacks::deleteStaleDeferred(
					$epoch, $owner['token'], 'i', $owner['user_hash'], $localId);
				$decision = self::executeAndReadback($adapter, $stale, $canonicalUser,
					$functionalAction, 'hook-deferred-replay-pending', $failure);
				if ($decision === false) {
					return(false);
				}
				continue;
			}
			$hash = $matches[0];
			$scalar = $adapter->sourceScalarSnapshot($hash, $failure);
			if (!is_array($scalar) || !isset($scalar['family'], $scalar['values']) ||
				$scalar['family'] !== $adapter->getFamily() || !is_array($scalar['values'])) {
				$failure = 'hook-deferred-replay-pending';
				return(false);
			}
			$values = $scalar['values'];
			if (!isset($values['local_id'], $values['state'], $values['recovery_marker'],
				$values['recovery_ack'], $values['custom3']) || $values['local_id'] !== $localId ||
				!in_array($values['state'], array('0', '1'), true) ||
				$values['recovery_marker'] !== '' || $values['recovery_ack'] !== '' ||
				$values['custom3'] === '1') {
				$failure = 'hook-deferred-replay-pending';
				return(false);
			}
			$serviceMarker = $adapter->checkerServiceMarker($hash, $failure);
			if (!is_string($serviceMarker)) {
				$failure = 'hook-deferred-replay-pending';
				return(false);
			}
			if ($serviceMarker !== '') {
				$skip = RetrackersLifecycleCallbacks::skipDeferredService(
					$epoch, $owner['token'], 'i', $owner['user_hash'], $hash, $localId);
				$decision = self::executeAndReadback($adapter, $skip, $canonicalUser,
					$functionalAction, 'hook-deferred-replay-pending', $failure);
				if ($decision === false) {
					return(false);
				}
				continue;
			}
			$handoff = 'v1:original:' . $values['state'] . ':' . $localId . ':' .
				$owner['user_hash'];
			$replay = RetrackersLifecycleCallbacks::replayDeferred(
				$epoch, $owner['token'], 'i', $owner['user_hash'], $hash, $localId,
				$values['state'], $handoff, $script, $php, $canonicalUser,
				$nativeOriginal);
			$decision = self::executeAndReadback($adapter, $replay, $canonicalUser,
				$functionalAction, 'hook-deferred-replay-pending', $failure);
			if ($decision === false) {
				return(false);
			}
		}
		if (self::hasActiveReceipt($decision)) {
			$failure = 'hook-deferred-replay-pending';
			return(false);
		}
		$failure = null;
		return(true);
	}

	private static function drainAndCancelPending($adapter, $canonicalUser, $functionalAction,
		$mode, &$decision, &$failure = null)
	{
		$owner = isset($decision['owner']) ? $decision['owner'] : null;
		if (!is_array($owner) || $owner['mode'] !== $mode) {
			$failure = 'hook-teardown-unconfirmed';
			return(false);
		}
		$epoch = $decision['persistent_epoch'];
		$deadline = self::teardownDeadline(RETRACKERS_TEARDOWN_TIMEOUT);
		while (true) {
			$wh = self::receiptLocalIds($decision, 'wh');
			if ($wh === false) {
				$failure = 'hook-teardown-unconfirmed';
				return(false);
			}
			if (count($wh) === 0) {
				break;
			}
			if (hrtime(true) >= $deadline) {
				$failure = 'hook-teardown-pending';
				return(false);
			}
			usleep(50000);
			$decision = $adapter->coherentLifecycleReadback(
				$canonicalUser, $functionalAction, $failure);
			if ($decision === false || !is_array($decision['owner']) ||
				$decision['owner'] !== $owner || $decision['persistent_epoch'] !== $epoch) {
				$failure = 'hook-teardown-unconfirmed';
				return(false);
			}
		}
		if (in_array('dq:1', $decision['transaction_receipts'], true)) {
			$clear = RetrackersLifecycleCallbacks::clearDeferredDirty(
				$epoch, $owner['token'], $mode, $owner['user_hash']);
			$decision = self::executeAndReadback($adapter, $clear, $canonicalUser,
				$functionalAction, 'hook-teardown-unconfirmed', $failure);
			if ($decision === false) {
				return(false);
			}
		}
		$deferred = self::receiptLocalIds($decision, 'di');
		if ($deferred === false) {
			$failure = 'hook-teardown-unconfirmed';
			return(false);
		}
		foreach ($deferred as $localId) {
			$delete = RetrackersLifecycleCallbacks::deleteStaleDeferred(
				$epoch, $owner['token'], $mode, $owner['user_hash'], $localId);
			$decision = self::executeAndReadback($adapter, $delete, $canonicalUser,
				$functionalAction, 'hook-teardown-unconfirmed', $failure);
			if ($decision === false) {
				return(false);
			}
		}

		$rows = null;
		while (true) {
			$pending = self::receiptLocalIds($decision, 'wp');
			if ($pending === false) {
				$failure = 'hook-teardown-unconfirmed';
				return(false);
			}
			if (count($pending) === 0) break;
			if ($rows === null) {
				$rows = $adapter->downloadLocalIdRows($failure);
				if (!is_array($rows)) {
					$failure = 'hook-teardown-unconfirmed';
					return(false);
				}
			}
			$localId = $pending[0];
			$matches = self::exactLocalIdMatches($rows, $localId);
			if (count($matches) > 1) {
				$failure = 'hook-teardown-unconfirmed';
				return(false);
			}
			$hash = count($matches) === 1 ? $matches[0] : null;
			$handoff = null;
			if ($hash !== null) {
				$scalar = $adapter->sourceScalarSnapshot($hash, $failure);
				if (!is_array($scalar) || !isset($scalar['family'], $scalar['values']) ||
					$scalar['family'] !== $adapter->getFamily() || !is_array($scalar['values'])) {
					$failure = 'hook-teardown-unconfirmed';
					return(false);
				}
				$values = $scalar['values'];
				// A shared daemon can hold a pending worker from another profile.
				// Cancel its exact marker and ack; never call that live object stale.
				if (!isset($values['local_id'], $values['recovery_marker'], $values['recovery_ack']) ||
					$values['local_id'] !== $localId ||
					!is_string($values['recovery_marker']) ||
					!is_string($values['recovery_ack'])) {
					$failure = 'hook-teardown-unconfirmed';
					return(false);
				}
				if ($adapter->getFamily() === 2 && $values['recovery_marker'] === '' &&
					$values['recovery_ack'] === '') {
					// A worker can die after native finish and before retiring wp:L.
					// The daemon callback below rechecks terminal T/I and live H/L/I
					// under one lock; it cannot erase a new worker's pending key.
					$receipt = $adapter->nativeFinalizedReceipt($hash, $failure);
					$incarnation = $adapter->nativeOriginalIncarnation($hash, $failure);
					if (!is_array($receipt) || $incarnation === false ||
						$receipt['incarnation'] !== $incarnation) {
						$failure = 'hook-teardown-unconfirmed';
						return(false);
					}
					$retire = RetrackersLifecycleCallbacks::retireNativePending(
						$hash, $localId, $receipt['tx'], $incarnation, $epoch,
						$owner['token'], $mode, $owner['user_hash']);
					$decision = self::executeAndReadback($adapter, $retire,
						$canonicalUser, $functionalAction, 'hook-teardown-unconfirmed',
						$failure, 'hook-teardown-unconfirmed', true);
					if ($decision === false) return(false);
					continue;
				}
				if (strncmp($values['recovery_marker'], 'v2:original:', 12) === 0 &&
					preg_match('/^v2:original:[01]:' . preg_quote($localId, '/') .
						':[0-9a-f]{64}:[0-9a-f]{32}$/D', $values['recovery_marker']) === 1 &&
					$values['recovery_ack'] === $values['recovery_marker']) {
					if (hrtime(true) >= $deadline) {
						$failure = 'hook-teardown-pending';
						return(false);
					}
					usleep(50000);
					$decision = $adapter->coherentLifecycleReadback(
						$canonicalUser, $functionalAction, $failure);
					if ($decision === false || !is_array($decision['owner']) ||
						$decision['owner'] !== $owner ||
						$decision['persistent_epoch'] !== $epoch) {
						$failure = 'hook-teardown-unconfirmed';
						return(false);
					}
					$rows = null;
					continue;
				}
				if (preg_match('/^v1:original:[01]:' . preg_quote($localId, '/') .
					':[0-9a-f]{64}$/D', $values['recovery_marker']) !== 1 ||
					$values['recovery_ack'] !== $values['recovery_marker']) {
					$failure = 'hook-teardown-unconfirmed';
					return(false);
				}
				$handoff = $values['recovery_marker'];
			}
			$callback = $handoff === null ?
				RetrackersLifecycleCallbacks::deleteStalePending(
					$epoch, $owner['token'], $mode, $owner['user_hash'], $localId) :
				RetrackersLifecycleCallbacks::cancelPending(
					$epoch, $owner['token'], $owner['user_hash'], $hash, $localId, $handoff, $mode);
			$decision = self::executeAndReadback($adapter, $callback, $canonicalUser,
				$functionalAction, 'hook-teardown-unconfirmed', $failure,
				'hook-teardown-unconfirmed', $callback->name() === 'pending-cancel');
			if ($decision === false) {
				return(false);
			}
		}

		while (self::hasActiveReceipt($decision)) {
			if (hrtime(true) >= $deadline) {
				$failure = 'hook-teardown-pending';
				return(false);
			}
			usleep(50000);
			$decision = $adapter->coherentLifecycleReadback(
				$canonicalUser, $functionalAction, $failure);
			if ($decision === false || !is_array($decision['owner']) ||
				$decision['owner'] !== $owner || $decision['persistent_epoch'] !== $epoch) {
				$failure = 'hook-teardown-unconfirmed';
				return(false);
			}
		}
		$failure = null;
		return(true);
	}

	// Keep nanosecond arithmetic in float: five seconds exceeds a 32-bit
	// integer, so narrowing it to int can expire the shared drain deadline early.
	private static function teardownDeadline($seconds)
	{
		return(hrtime(true) + ((float)$seconds) * 1000000000.0);
	}

	public static function init($canonicalUser, $script, $php, &$failure = null, $adapter = null)
	{
		$failure = null;
		if (!is_string($canonicalUser) || preg_match('/^[a-z0-9_-]*$/D', $canonicalUser) !== 1) {
			$failure = 'invalid-caller-input';
			return(false);
		}
		if (!is_string($script) || $script === '' || strpbrk($script, "\0\r\n") !== false || $script[0] === '$' ||
			!is_string($php) || $php === '' || strpbrk($php, "\0\r\n") !== false || $php[0] === '$') {
			$failure = 'invalid-caller-input';
			return(false);
		}
		if ($adapter === null) {
			$adapter = new RetrackersLifecycleRpcAdapter();
		}

		if (!$adapter->ensureLedgerExists($failure)) {
			return(false);
		}
		// Family 1 keeps the 0.9.8 v1 path. Family 2 must expose the
		// exact native methods before a hook may publish a v2 marker.
		$nativeOriginal = $adapter->getFamily() === 2;
		if ($nativeOriginal && !$adapter->nativeOriginalCapability($failure)) {
			return(false);
		}
		$functionalAction = retrackersBuildInsertAction($script, $php, $canonicalUser,
			$nativeOriginal);
		$safetyAction = retrackersBuildSafetyOnlyInsertAction();
		$deferAction = retrackersBuildDeferOnlyInsertAction();

		$attestation = $adapter->attestLedger($failure);
		if ($attestation === false || !($attestation instanceof RetrackersHistoricalLedgerAttestation)) {
			return(false);
		}

		$binding = new RetrackersStableHistoricalBinding($adapter);
		$consumerSuccess = false;
		$outcome = $binding->consume($canonicalUser, $functionalAction, $attestation,
			function ($decision) use (
				$adapter, $canonicalUser, $script, $php, $functionalAction, $safetyAction, $deferAction,
				$nativeOriginal,
				&$failure, &$consumerSuccess
			) {
				if ($adapter->getFamily() !== $decision['binding']['family']) {
					$failure = 'profile-binding-unstable';
					return(false);
				}
				$adapter->setFamily($decision['binding']['family']);
				$phase = $decision['phase'];
				$userHash = hash('sha256', $canonicalUser);
				$funcHash = hash('sha256', $functionalAction);
				$key1 = 'tadd_trackers1' . $canonicalUser;
				$key2 = 'tadd_trackers2' . $canonicalUser;

				$profileCount = $decision['profile_count'];
				if ($phase === 'IDLE_CURRENT' && $profileCount === 1 &&
					$decision['caller']['membership'] === 'profile') {
					$profile = self::profileForUser($decision, $canonicalUser);
					if ($profile !== false && $profile['claim_hash'] === $funcHash) {
						$consumerSuccess = true;
						return(true);
					}
				}

				if ($phase === 'BOOTSTRAP' || ($phase === 'IDLE_CURRENT' &&
					($profileCount === 0 || $decision['caller']['membership'] === 'profile' ||
						$decision['caller']['membership'] === 'absent'))) {
					$token = self::freshToken();
					$oldEpoch = $phase === 'BOOTSTRAP' ? null : $decision['persistent_epoch'];
					$epoch1 = self::freshToken($oldEpoch);
					$oldClaim = null;
					if ($decision['caller']['membership'] === 'profile') {
						$profile = self::profileForUser($decision, $canonicalUser);
						$oldClaim = $profile === false ? null : $profile['claim_hash'];
					}
					$acquire = $phase === 'BOOTSTRAP' ?
						RetrackersLifecycleCallbacks::bootstrapAcquire(
							$token, $epoch1, $userHash, $funcHash, $key1, $key2, $deferAction) :
						RetrackersLifecycleCallbacks::currentInitAcquire(
							$oldEpoch, $epoch1, $token, $userHash, $oldClaim, $funcHash,
							$key1, $key2, $deferAction);
					$owned = self::executeAndReadback($adapter, $acquire, $canonicalUser,
						$functionalAction, 'lifecycle-acquire-unconfirmed', $failure, 'lifecycle-busy');
					if ($owned === false) {
						return(false);
					}

					if ($phase === 'IDLE_CURRENT' && $profileCount === 1 &&
						$decision['caller']['membership'] === 'absent') {
						$epoch2 = self::freshToken($epoch1);
						$contain = RetrackersLifecycleCallbacks::contain(
							$epoch1, $epoch2, $token, $userHash, $owned['profiles'], $safetyAction);
						$contained = self::executeAndReadback($adapter, $contain, $canonicalUser,
							$functionalAction, 'shared-daemon-owner-ambiguous-uncontained', $failure,
							'shared-daemon-owner-ambiguous-uncontained');
						if ($contained === false || !self::drainAndCancelPending(
							$adapter, $canonicalUser, $functionalAction, 'c', $contained, $failure)) {
							$failure = 'shared-daemon-owner-ambiguous-uncontained';
							return(false);
						}
						$epoch3 = self::freshToken($epoch2);
						$final = RetrackersLifecycleCallbacks::containmentFinalization(
							$epoch2, $epoch3, $token, $userHash);
						if (self::executeAndReadback($adapter, $final, $canonicalUser,
							$functionalAction, 'shared-daemon-owner-ambiguous-uncontained', $failure,
							'shared-daemon-owner-ambiguous-uncontained') === false) {
							return(false);
						}
						$failure = 'shared-daemon-owner-ambiguous';
						return(false);
					}

					if (!self::replayDeferredInserts($adapter, $canonicalUser, $script, $php,
						$functionalAction, $nativeOriginal, $owned, $failure)) {
						return(false);
					}
					$epoch2 = self::freshToken($epoch1);
					$final = RetrackersLifecycleCallbacks::initFinalization(
						$epoch1, $epoch2, $token, $userHash, $key1, $key2,
						$functionalAction, $safetyAction);
					$installed = self::executeAndReadback($adapter, $final, $canonicalUser,
						$functionalAction, 'hook-install-unconfirmed', $failure,
						'hook-deferred-replay-pending');
					if ($installed === false) {
						return(false);
					}
					$profile = self::profileForUser($installed, $canonicalUser);
					if ($profile === false || $profile['pair'] !== 'F/S' ||
						$profile['claim_hash'] !== $funcHash) {
						$failure = 'hook-install-unconfirmed';
						return(false);
					}
					$consumerSuccess = true;
					return(true);
				}

				if (in_array($phase, array('INIT_OWNER', 'DONE_OWNER', 'CONTAIN_OWNER'), true)) {
					$failure = 'lifecycle-busy';
					return(false);
				}

				if ($phase === 'CONTAINED') {
					$failure = 'shared-daemon-contained';
					return(false);
				}

				$failure = 'historical-hook-restart-required';
				return(false);
			});

		if ($outcome['ok'] !== true) {
			$failure = $outcome['failure'];
			return(false);
		}
		return($consumerSuccess);
	}

	public static function done($canonicalUser, &$failure = null, $adapter = null)
	{
		$failure = null;
		if (!is_string($canonicalUser) || preg_match('/^[a-z0-9_-]*$/D', $canonicalUser) !== 1) {
			$failure = 'invalid-caller-input';
			return(false);
		}
		if ($adapter === null) {
			$adapter = new RetrackersLifecycleRpcAdapter();
		}
		$attestation = $adapter->attestLedger($failure);
		if ($attestation === false) {
			return(false);
		}
		$binding = new RetrackersStableHistoricalBinding($adapter);
		$consumerSuccess = false;
		$outcome = $binding->consume($canonicalUser, null, $attestation,
			function ($decision) use ($adapter, $canonicalUser, &$failure, &$consumerSuccess) {
				if ($adapter->getFamily() !== $decision['binding']['family']) {
					$failure = 'profile-binding-unstable';
					return(false);
				}
				$adapter->setFamily($decision['binding']['family']);
				$phase = $decision['phase'];
				$userHash = hash('sha256', $canonicalUser);
				$key1 = 'tadd_trackers1' . $canonicalUser;
				$key2 = 'tadd_trackers2' . $canonicalUser;

				if ($decision['caller']['membership'] === 'absent') {
					$consumerSuccess = true;
					return(true);
				}

				$resume = $phase === 'DONE_OWNER' &&
					$decision['caller']['membership'] === 'owner';
				if (!$resume && ($phase !== 'IDLE_CURRENT' ||
					$decision['caller']['membership'] !== 'profile')) {
					if ($phase === 'CONTAINED') {
						$failure = 'shared-daemon-contained';
						return(false);
					}
					if (in_array($phase, array('INIT_OWNER', 'DONE_OWNER', 'CONTAIN_OWNER'), true)) {
						$failure = 'lifecycle-busy';
						return(false);
					}
					$failure = 'historical-hook-restart-required';
					return(false);
				}

				$profile = self::profileForUser($decision, $canonicalUser);
				if ($profile === false ||
					$profile['pair'] !== ($resume ? 'S/S' : 'F/S') ||
					!is_string($profile['claim_hash'])) {
					$failure = 'historical-hook-restart-required';
					return(false);
				}
				if ($resume) {
					// A bounded wait may have ended while this same owner still holds D.
					$epoch1 = $decision['persistent_epoch'];
					$token = $decision['owner']['token'];
					$owned = $decision;
				} else {
					$epoch0 = $decision['persistent_epoch'];
					$token = self::freshToken();
					$epoch1 = self::freshToken($epoch0);
					$acquire = RetrackersLifecycleCallbacks::doneAcquire(
						$epoch0, $epoch1, $token, $userHash, $profile['claim_hash'],
						$key1, $key2, null, retrackersBuildSafetyOnlyInsertAction());
					$owned = self::executeAndReadback($adapter, $acquire, $canonicalUser,
						null, 'lifecycle-acquire-unconfirmed', $failure, 'lifecycle-busy');
				}
				if ($owned === false || !self::drainAndCancelPending(
					$adapter, $canonicalUser, null, 'd', $owned, $failure)) {
					return(false);
				}
				$epoch2 = self::freshToken($epoch1);
				$final = RetrackersLifecycleCallbacks::doneFinalization(
					$epoch1, $epoch2, $token, $userHash, $profile['claim_hash'], $key1, $key2);
				$released = self::executeAndReadback($adapter, $final, $canonicalUser,
					null, 'hook-teardown-unconfirmed', $failure, 'hook-teardown-pending');
				if ($released === false || $released['profile_count'] !== 0 ||
					$released['caller']['membership'] !== 'absent') {
					$failure = 'hook-teardown-unconfirmed';
					return(false);
				}
				$consumerSuccess = true;
				return(true);
			});

		if ($outcome['ok'] !== true) {
			$failure = $outcome['failure'];
			return(false);
		}
		return($consumerSuccess);
	}

}

function retrackersBoundedFailureReason($failure)
{
	static $allowed = array(
		'candidate-absent-ambiguous' => true,
		'candidate-cleanup-confirmed' => true,
		'candidate-cleanup-skipped' => true,
		'candidate-confirmed' => true,
		'candidate-fence-not-implemented' => true,
		'candidate-hash-mismatch' => true,
		'candidate-hook-incomplete' => true,
		'candidate-load-failed' => true,
		'candidate-partial' => true,
		'candidate-prefix-changed' => true,
		'candidate-too-large' => true,
		'candidate-tracker-projection-failed' => true,
		'candidate-unconfirmed' => true,
		'different-hash-unsupported' => true,
		'cleanup-arm-pending' => true,
		'cleanup-builder-headroom' => true,
		'cleanup-completion-pending' => true,
		'cleanup-dispatch-pending' => true,
		'cleanup-request-too-large' => true,
		'cleanup-size-invariant' => true,
		'commit-absent-ambiguous' => true,
		'commit-arm-pending' => true,
		'commit-builder-headroom' => true,
		'commit-completion-pending' => true,
		'commit-dispatch-pending' => true,
		'commit-not-sent' => true,
		'commit-request-limit-invalid' => true,
		'commit-request-too-large' => true,
		'commit-response-inconsistent' => true,
		'commit-size-invariant' => true,
		'commit-skipped' => true,
		'foreign-generation' => true,
		'handoff-release-changed' => true,
		'handoff-release-pending' => true,
		'marked-recovery-pending' => true,
		'durable-release-required' => true,
		'historical-hook-restart-required' => true,
		'hook-ledger-mismatch-restart-required' => true,
		'hook-ack-capability-missing' => true,
		'hook-active-unconfirmed' => true,
		'hook-deferred-replay-pending' => true,
		'hook-install-unconfirmed' => true,
		'hook-neutralization-unconfirmed' => true,
		'hook-teardown-pending' => true,
		'hook-teardown-unconfirmed' => true,
		'initial-absent' => true,
		'initial-fault' => true,
		'initial-hashing-failed' => true,
		'initial-malformed' => true,
		'initial-transport' => true,
		'invalid-caller-input' => true,
		'lifecycle-acquire-unconfirmed' => true,
		'lifecycle-busy' => true,
		'lifecycle-unsupported' => true,
		'load-arm-pending' => true,
		'load-builder-headroom' => true,
		'load-command-too-large' => true,
		'load-dispatch-pending' => true,
		'load-fence-pending' => true,
		'load-request-too-large' => true,
		'load-response-inconsistent' => true,
		'load-size-invariant' => true,
		'load-wrapper-changed' => true,
		'native-capture-unconfirmed' => true,
		'native-capture-readback-unconfirmed' => true,
		'native-candidate-unconfirmed' => true,
		'native-candidate-incarnation-unconfirmed' => true,
		'native-candidate-owner-readback-pending' => true,
		'native-commit-unconfirmed' => true,
		'native-release-unconfirmed' => true,
		'native-samehash-capability-unconfirmed' => true,
		'native-original-capability-unconfirmed' => true,
		'native-pending-retire-unconfirmed' => true,
		'native-original-finalize-pending' => true,
		'native-original-identity-invalid' => true,
		'native-original-finalized-before-worker' => true,
		'native-original-incarnation-unconfirmed' => true,
		'native-original-owner-readback-pending' => true,
		'native-original-rpc-unconfirmed' => true,
		'native-original-unsupported' => true,
		'ownership-mismatch' => true,
		'partial-quiesce-ambiguous' => true,
		'prefix-builder-headroom' => true,
		'procfd-preflight-failed' => true,
		'profile-binding-unstable' => true,
		'profile-binding-writer-untrusted' => true,
		'quiesce-changed' => true,
		'rpc-family-unconfirmed' => true,
		'receipt-ledger-corrupt' => true,
		'receipt-preflight-failed' => true,
		'rollback-confirmed' => true,
		'rollback-load-failed' => true,
		'rollback-partial' => true,
		'rollback-unconfirmed' => true,
		'runtime-map-invalid' => true,
		'runtime-map-unstable' => true,
		'runtime-tracker-topology-mismatch' => true,
		'runtime-value-unrepresentable' => true,
		'scheduler-command-unsupported' => true,
		'shared-daemon-contained' => true,
		'shared-daemon-no-owner' => true,
		'shared-daemon-owner-ambiguous' => true,
		'shared-daemon-owner-ambiguous-uncontained' => true,
		'shared-daemon-unowned-mutating' => true,
		'source-bencode-invalid' => true,
		'source-decode-failed' => true,
		'source-duplicate-key' => true,
		'source-hash-mismatch' => true,
		'source-not-regular' => true,
		'source-read-error' => true,
		'source-short-read' => true,
		'source-too-complex' => true,
		'source-too-deep' => true,
		'source-too-large' => true,
		'source-unreadable' => true,
		'stage-directory-changed' => true,
		'stage-directory-failed' => true,
		'stage-flush-failed' => true,
		'stage-identity-failed' => true,
		'stage-input-invalid' => true,
		'stage-open-failed' => true,
		'stage-procfd-failed' => true,
		'stage-write-failed' => true,
		'terminal-cleanup-pending' => true,
		'terminal-cleanup-unconfirmed' => true,
		'tracker-state-ambiguous' => true,
		'worker-adopt-changed' => true,
		'worker-adopt-denied' => true,
		'worker-adopt-unconfirmed' => true,
	);
	return(is_string($failure) && isset($allowed[$failure]) ?
		$failure : 'lifecycle-unsupported');
}

function retrackersLifecycleDiagnosticJavascript($context, $failure)
{
	$reason = retrackersBoundedFailureReason($failure);
	$message = $reason;
	if ($context === 'done' && $reason === 'hook-teardown-pending') {
		$message .= '; daemon restart required after active recovery finishes';
	} elseif ($reason === 'hook-ledger-mismatch-restart-required') {
		$message .= '; restart rTorrent and recheck';
	} elseif ($reason === 'marked-recovery-pending') {
		$message .= '; inspect the retained marker and native receipt';
	}
	return('noty(' . json_encode('retrackers: ' . $message) . ",'error');");
}

function retrackersRunLifecycleInit($canonicalUser, $script, $php, &$jResult,
	&$failure = null, $adapter = null)
{
	$ok = RetrackersLifecycleCoordinator::init(
		$canonicalUser, $script, $php, $failure, $adapter);
	if (!$ok) {
		$reason = retrackersBoundedFailureReason($failure);
		if (class_exists('FileUtil') && method_exists('FileUtil', 'toLog')) {
			FileUtil::toLog('retrackers-init: ' . $reason);
		}
		$jResult .= retrackersLifecycleDiagnosticJavascript('init', $reason);
	}
	return($ok);
}

function retrackersRunLifecycleDone($canonicalUser, &$jResult, &$failure = null, $adapter = null)
{
	$ok = RetrackersLifecycleCoordinator::done($canonicalUser, $failure, $adapter);
	if (!$ok) {
		$jResult .= retrackersLifecycleDiagnosticJavascript('done', $failure);
	}
	return($ok);
}

class RetrackersRecoveryCoordinator
{
	const OWN_KEY_PREFIXES = array(
		'wa', 'ea', 'eb', 'ed', 'ex',
		'la', 'lb', 'lf',
		'ca', 'cb', 'cd', 'cx',
		'ra', 'rb', 'rf',
	);
	const POST_ERASE_CLASSIFICATIONS = array(
		'candidate' => array(
			'candidate-confirmed', 'candidate-absent-ambiguous',
			'foreign-generation', 'candidate-partial',
			'candidate-hook-incomplete', 'candidate-unconfirmed',
			'candidate-load-failed', 'candidate-prefix-changed',
		),
		'rollback' => array(
			'rollback-confirmed', 'rollback-partial', 'rollback-unconfirmed',
			'rollback-load-failed', 'foreign-generation',
		),
		'cleanup' => array(
			'candidate-cleanup-confirmed', 'candidate-cleanup-skipped',
			'candidate-confirmed', 'candidate-absent-ambiguous',
			'foreign-generation', 'candidate-hook-incomplete',
			'candidate-unconfirmed', 'candidate-load-failed',
			'candidate-prefix-changed',
		),
	);

	public static function postEraseClassificationVocabulary()
	{
		return(self::POST_ERASE_CLASSIFICATIONS);
	}

	private static function closedPostEraseClassification($kind, $classification)
	{
		if (isset(self::POST_ERASE_CLASSIFICATIONS[$kind]) &&
			in_array($classification, self::POST_ERASE_CLASSIFICATIONS[$kind], true)) {
			return($classification);
		}
		return($kind === 'rollback' ? 'rollback-unconfirmed' : 'candidate-unconfirmed');
	}


	// A request is sent once. This loop owns only its read-back, so a delayed
	// receipt can complete without replaying an erase, load, release or cleanup.
	// Unknown arms deliberately never enter this loop: they require a restart.
	private static function awaitRead($read, $adapter, $wait, array $pending, &$failure)
	{
		$reported = null;
		while (true) {
			$result = $read($failure);
			if ($result !== false || !$wait || !in_array($failure, $pending, true)) {
				return($result);
			}
			if ($reported !== $failure) {
				$adapter->recordRecoveryFailure($failure);
				$reported = $failure;
			}
			usleep((int)(RETRACKERS_RECEIPT_POLL * 1000000));
		}
	}

	private static function holdUnknownLease()
	{
		if (class_exists('FileUtil') && method_exists('FileUtil', 'toLog')) {
			FileUtil::toLog('retrackers-recovery: unknown-lease-held; daemon restart required');
		}
		while (true) {
			usleep(250000);
		}
	}

	private static function holdUnknownStage(RetrackersAnonymousStage $stage)
	{
		// Retaining the stage keeps both descriptors alive while an accepted daemon
		// request may still consume one of them.
		return(self::holdUnknownLease());
	}

	public static function generateCleanTx($adapter, &$failure = null)
	{
		$keys = $adapter->getLedgerKeys($failure);
		if (!is_array($keys)) {
			$failure = 'receipt-ledger-corrupt';
			return(false);
		}
		for ($attempt = 0; $attempt < 5; $attempt++) {
			$tx = bin2hex(random_bytes(16));
			$collision = false;
			foreach (self::OWN_KEY_PREFIXES as $prefix) {
				if (in_array($prefix . ':' . $tx, $keys, true)) {
					$collision = true;
					break;
				}
			}
			if (in_array('v1:candidate-ready:' . $tx, $keys, true) ||
				in_array('v1:rollback-ready:' . $tx, $keys, true)) {
				$collision = true;
			}
			if (!$collision) {
				$failure = null;
				return($tx);
			}
		}
		$failure = 'receipt-preflight-failed';
		return(false);
	}

	public static function adoptWorker($tx, $hash, $handoff, $localId, $adapter, &$failure = null)
	{
		$callback = RetrackersWorkerAdoptCallback::build($tx, $hash, $handoff, $localId);
		$result = $adapter->executeWorkerAdoptCallback($callback, $failure);
		if ($result === false) {
			$failure = 'worker-adopt-unconfirmed';
			return(false);
		}
		if ($result !== 'ADOPTED') {
			$failure = $result === 'DENIED' ? 'worker-adopt-denied' : 'worker-adopt-changed';
			return(false);
		}
		$keys = $adapter->getLedgerKeys($failure);
		if (is_array($keys) && in_array('wa:' . $tx, $keys, true) &&
			!in_array('wp:' . $localId, $keys, true)) {
			$failure = null;
			return(true);
		}
		$failure = 'worker-adopt-unconfirmed';
		return(false);
	}

	public static function releaseSurvivingHandoff($tx, $hash, $handoff, $localId,
		$adapter, &$failure = null, $waitForCompletion = false)
	{
		$callback = RetrackersHandoffReleaseCallback::build(
			$tx, $hash, $handoff, $localId);
		if ($callback === false) {
			$failure = 'handoff-release-pending';
			return(false);
		}
		$replyFailure = null;
		$adapter->executeHandoffRelease($callback, $replyFailure);

		return(self::awaitRead(function (&$failure) use ($adapter, $hash, $localId, $handoff) {
			$readFailure = null;
			$observation = $adapter->sourceScalarSnapshot($hash, $readFailure);
			if ($observation === false) {
				if ($readFailure === 'target-absent') {
					$failure = null;
					return('quarantined');
				}
				$failure = 'handoff-release-pending';
				return(false);
			}
			if (!is_array($observation) || !isset($observation['values']) ||
				!is_array($observation['values'])) {
				$failure = 'handoff-release-pending';
				return(false);
			}
			$values = $observation['values'];
			if (!isset($values['local_id'], $values['recovery_marker'],
				$values['recovery_ack']) || !is_string($values['local_id']) ||
				!is_string($values['recovery_marker']) ||
				!is_string($values['recovery_ack'])) {
				$failure = 'handoff-release-pending';
				return(false);
			}
			$sameId = $values['local_id'] === $localId;
			$marker = $values['recovery_marker'];
			$ack = $values['recovery_ack'];
			if ($sameId && $marker === '' && $ack === '') {
				$failure = null;
				return('released');
			}
			if ($sameId && $marker === $handoff &&
				($ack === $handoff || $ack === '')) {
				$failure = 'handoff-release-pending';
				return(false);
			}
			if (!$sameId || $marker !== '' || $ack !== '') {
				$failure = null;
				return('quarantined');
			}
			$failure = 'handoff-release-pending';
			return(false);
		}, $adapter, $waitForCompletion, array('handoff-release-pending'), $failure));
	}
	private static function ownedLedgerKeys($tx)
	{
		$keys = array('v1:candidate-ready:' . $tx,
			'v1:rollback-ready:' . $tx);
		foreach (self::OWN_KEY_PREFIXES as $prefix) {
			$keys[] = $prefix . ':' . $tx;
		}
		return($keys);
	}

	private static function remainingOwnedLedgerKeys($tx, array $keys)
	{
		return(array_values(array_intersect(self::ownedLedgerKeys($tx), $keys)));
	}

	public static function isDeterministicBuilderFailure($failure)
	{
		return(in_array($failure, array(
			'runtime-value-unrepresentable',
			'commit-request-limit-invalid',
			'commit-request-too-large',
			'commit-builder-headroom',
			'commit-size-invariant',
		), true));
	}

	public static function disposeOriginalHandoff($tx, $hash, $handoff, $localId,
		$adapter, &$failure = null, $waitForCompletion = false)
	{
		if ($adapter->getFamily() !== 1) {
			$failure = 'durable-release-required';
			return('hold-release');
		}
		$releaseFailure = null;
		$release = self::releaseSurvivingHandoff(
			$tx, $hash, $handoff, $localId, $adapter, $releaseFailure, $waitForCompletion);
		if ($release === false) {
			$failure = $releaseFailure;
			return('hold-release');
		}
		$cleanupFailure = null;
		$cleanup = self::terminalCleanup($tx, $adapter, $cleanupFailure, $waitForCompletion);
		if ($cleanup === false) {
			$failure = $cleanupFailure;
			return('hold-cleanup');
		}
		if ($cleanup !== 'clean') {
			$failure = $cleanupFailure === null ?
				'terminal-cleanup-unconfirmed' : $cleanupFailure;
			return('terminal-unconfirmed');
		}
		$failure = null;
		return($release === 'released' ? 'released-clean' : 'quarantined-clean');
	}

	public static function preFenceFailureAction($failure)
	{
		return($failure === 'candidate-fence-not-implemented' ? 'dispose' : 'hold');
	}

	public static function releaseAndCleanupBeforeArm($tx, $hash, $handoff, $localId,
		RetrackersAnonymousStage $stage, $adapter, &$failure = null, $waitForCompletion = false)
	{
		$stage->abortBeforeArm();
		$decision = self::disposeOriginalHandoff(
			$tx, $hash, $handoff, $localId, $adapter, $failure, $waitForCompletion);
		if ($decision === 'hold-release' || $decision === 'hold-cleanup') {
			return(false);
		}
		if ($decision === 'terminal-unconfirmed') {
			return('unconfirmed');
		}
		return('clean');
	}

	public static function handleCommitFailureBeforeArm($commitFailure, $tx, $hash,
		$handoff, $localId, RetrackersAnonymousStage $stage, $adapter, &$failure = null, $waitForCompletion = false)
	{
		if (in_array($commitFailure, array('quiesce-changed',
			'partial-quiesce-ambiguous', 'foreign-generation', 'commit-absent-ambiguous'), true)) {
			// No load was dispatched and old commit is terminal. Quarantine the
			// object unchanged, close both unused descriptors and retire only this tx.
			$stage->abortBeforeArm();
			return(self::terminalCleanup($tx, $adapter, $failure, $waitForCompletion));
		}
		if (!in_array($commitFailure, array('commit-skipped', 'commit-not-sent'), true) &&
			!self::isDeterministicBuilderFailure($commitFailure)) {
			$failure = $commitFailure;
			return('not-deterministic');
		}
		return(self::releaseAndCleanupBeforeArm($tx, $hash, $handoff, $localId,
			$stage, $adapter, $failure, $waitForCompletion));
	}

	private static function commitReceipts($receipts)
	{
		if (!is_array($receipts) ||
			array_keys($receipts) !== array('begin', 'done', 'exit')) {
			return(false);
		}
		foreach ($receipts as $value) {
			if (!in_array($value, array('0', '1'), true)) {
				return(false);
			}
		}
		return($receipts);
	}

	private static function sameOwnedGeneration($observation, $localId, $handoff)
	{
		if (!is_array($observation) || !isset($observation['ok'], $observation['presence']) ||
			$observation['ok'] !== true || $observation['presence'] !== 'present' ||
			!isset($observation['snapshot']['scalar']) ||
			!is_array($observation['snapshot']['scalar'])) {
			return(false);
		}
		$scalar = $observation['snapshot']['scalar'];
		return(isset($scalar['local_id'], $scalar['recovery_marker'], $scalar['recovery_ack']) &&
			$scalar['local_id'] === $localId &&
			$scalar['recovery_marker'] === $handoff &&
			$scalar['recovery_ack'] === $handoff);
	}

	private static function sameCommitTuple($observation, $snapshot, $quiesced)
	{
		if (!is_array($observation) || !isset($observation['ok'], $observation['presence'],
			$observation['snapshot']) || $observation['ok'] !== true ||
			$observation['presence'] !== 'present' || !is_array($observation['snapshot']) ||
			!is_array($snapshot) || !isset($observation['snapshot']['scalar'],
			$observation['snapshot']['generic_map'], $observation['snapshot']['tracker_topology'],
			$observation['snapshot']['tracker_enabled'], $snapshot['scalar'],
			$snapshot['generic_map'], $snapshot['tracker_topology'], $snapshot['tracker_enabled'])) {
			return(false);
		}
		$expected = $snapshot['scalar'];
		if ($quiesced) {
			$expected['state'] = '0';
			$expected['is_active'] = '0';
			$expected['is_open'] = '0';
			$expected['hashing'] = '0';
		}
		$scalarNames = array(
			'local_id', 'recovery_marker', 'recovery_ack', 'state', 'is_active', 'is_open',
			'hashing', 'hashing_failed', 'directory_base', 'custom1', 'custom2', 'custom3',
			'custom4', 'custom5', 'priority', 'throttle_name', 'tied_source', 'loaded_file',
		);
		$actual = $observation['snapshot']['scalar'];
		foreach ($scalarNames as $name) {
			if (!array_key_exists($name, $actual) || !array_key_exists($name, $expected) ||
				$actual[$name] !== $expected[$name]) {
				return(false);
			}
		}
		return($observation['snapshot']['generic_map'] === $snapshot['generic_map'] &&
			$observation['snapshot']['tracker_topology'] === $snapshot['tracker_topology'] &&
			$observation['snapshot']['tracker_enabled'] === $snapshot['tracker_enabled']);
	}

	public static function commitOldGeneration($tx, $hash, $handoff, $state, $localId,
		$snapshot, $adapter, &$failure = null, $observer = null, $preparedCallback = null,
		$preparedDaemonCap = null, $waitForCompletion = false)
	{
		if (!is_string($state) || !in_array($state, array('0', '1'), true) ||
			!is_string($localId) || !is_string($handoff) ||
			preg_match('/^v1:original:([01]):([0-9A-F]{40}):[0-9a-f]{64}$/D',
				$handoff, $parts) !== 1 || $parts[1] !== $state || $parts[2] !== $localId ||
			!is_array($snapshot) || !isset($snapshot['scalar']['state'],
				$snapshot['scalar']['local_id']) ||
			$snapshot['scalar']['state'] !== $state ||
			$snapshot['scalar']['local_id'] !== $localId) {
			$failure = 'ownership-mismatch';
			return(false);
		}
		if ($preparedCallback === null) {
			$limitFailure = null;
			$daemonCap = $preparedDaemonCap === null ?
				$adapter->maxContentSize($limitFailure) : $preparedDaemonCap;
			if (!is_int($daemonCap) || $daemonCap < 1) {
				$failure = $limitFailure === null ? 'commit-request-limit-invalid' : $limitFailure;
				return(false);
			}
			$buildFailure = null;
			$callback = RetrackersOldGenerationCommitCallback::build(
				$tx, $hash, $handoff, $snapshot, $daemonCap, $buildFailure);
			if ($callback === false) {
				$failure = $buildFailure === null ? 'runtime-value-unrepresentable' : $buildFailure;
				return(false);
			}
		} elseif ($preparedCallback instanceof RetrackersOldGenerationCommitCallback) {
			$callback = $preparedCallback;
		} else {
			$failure = 'runtime-value-unrepresentable';
			return(false);
		}

		$armFailure = null;
		$arm = $adapter->armPhase('ea', $tx, $armFailure);
		if ($arm !== 'RETRACKERS_PHASE_ARMED') {
			$failure = 'commit-arm-pending';
			return(false);
		}

		$dispatchFailure = null;
		$reply = $adapter->executeOldGenerationCommit($callback, $dispatchFailure);
		return(self::awaitRead(function (&$failure) use ($adapter, $tx, $hash, $observer, $reply, $dispatchFailure, $localId, $handoff, $snapshot) {
			$receiptFailure = null;
			$receipts = $adapter->oldGenerationCommitReceipts($tx, $receiptFailure);
			if ($observer === null) {
				$observer = new RetrackersStableSourceSnapshot($adapter);
			}
			$observation = $observer->observe($hash);
			$receipts = self::commitReceipts($receipts);
			if ($receipts === false) {
				$failure = $receiptFailure === 'receipt-ledger-corrupt' ?
					'receipt-ledger-corrupt' :
					(in_array($reply, array('RETRACKERS_ERASED',
						'RETRACKERS_QUIESCE_CHANGED'), true) ?
						'commit-completion-pending' : 'commit-dispatch-pending');
				return(false);
			}
			if ($receipts['begin'] === '0') {
				if ($receipts['done'] !== '0' || $receipts['exit'] !== '0') {
					$failure = 'receipt-ledger-corrupt';
					return(false);
				}
				if ($reply !== false && $reply !== 'RETRACKERS_SKIPPED') {
					$failure = 'commit-response-inconsistent';
					return(false);
				}
				if ($reply === false) {
					// A failed connect precedes every request byte. Only an exact
					// untouched owner and empty commit receipts permit retirement.
					$failure = $dispatchFailure === 'connect-failed' &&
						self::sameOwnedGeneration($observation, $localId, $handoff) &&
						self::sameCommitTuple($observation, $snapshot, false) ?
						'commit-not-sent' : 'commit-dispatch-pending';
					return(false);
				}
				if (!is_array($observation) || !isset($observation['ok']) ||
					$observation['ok'] !== true) {
					$failure = 'commit-completion-pending';
					return(false);
				}
				if (isset($observation['presence']) && $observation['presence'] === 'absent') {
					$failure = 'commit-absent-ambiguous';
					return(false);
				}
				$failure = self::sameOwnedGeneration($observation, $localId, $handoff) ?
					'commit-skipped' : 'foreign-generation';
				return(false);
			}
			if ($receipts['exit'] !== '1') {
				$failure = 'commit-completion-pending';
				return(false);
			}
			$expectedReply = $receipts['done'] === '1' ?
				'RETRACKERS_ERASED' : 'RETRACKERS_QUIESCE_CHANGED';
			if ($reply !== false && $reply !== $expectedReply) {
				$failure = 'commit-response-inconsistent';
				return(false);
			}
			if (!is_array($observation) || !isset($observation['ok'], $observation['presence']) ||
				$observation['ok'] !== true) {
				$failure = 'commit-completion-pending';
				return(false);
			}
			if ($receipts['done'] === '1') {
				if ($observation['presence'] === 'absent') {
					$failure = null;
					return(true);
				}
				$failure = 'foreign-generation';
				return(false);
			}
			if ($observation['presence'] === 'absent') {
				$failure = 'commit-absent-ambiguous';
				return(false);
			}
			if (self::sameCommitTuple($observation, $snapshot, false)) {
				$failure = 'quiesce-changed';
				return(false);
			}
			$failure = self::sameCommitTuple($observation, $snapshot, true) ?
				'partial-quiesce-ambiguous' : 'quiesce-changed';
			return(false);
		}, $adapter, $waitForCompletion, array('receipt-ledger-corrupt', 'commit-completion-pending', 'commit-dispatch-pending', 'commit-response-inconsistent'), $failure));
	}
	public static function armPostErasePhase($phase, $tx, $adapter, &$failure = null)
	{
		$kind = $phase === 'ca' ? 'cleanup' : 'load';
		if (!in_array($phase, array('la', 'ca', 'ra'), true)) {
			$failure = $kind . '-arm-pending';
			return(false);
		}
		$replyFailure = null;
		$reply = $adapter->armPhase($phase, $tx, $replyFailure);
		if ($reply === 'RETRACKERS_PHASE_ARMED') {
			$failure = null;
			return(true);
		}
		// Later key presence cannot replace the exact one-shot arm reply.
		$failure = $kind . '-arm-pending';
		return(false);
	}

	private static function validLoadReceipts($receipts)
	{
		if (!is_array($receipts) || array_keys($receipts) !== array('begin', 'fence')) {
			return(false);
		}
		return(in_array($receipts['begin'], array('0', '1'), true) &&
			in_array($receipts['fence'], array('0', '1'), true));
	}

	public static function dispatchLoadObligation($tx, RetrackersLoadObligation $plan,
		$adapter, &$failure = null, $waitForCompletion = false)
	{
		if ($plan->phase() !== 'la' && $plan->phase() !== 'ra') {
			$failure = 'load-dispatch-pending';
			return(false);
		}
		if (!self::armPostErasePhase($plan->phase(), $tx, $adapter, $failure)) {
			return(false);
		}
		$dispatchFailure = null;
		$reply = $adapter->executeLoadDispatch($plan->callback(), $dispatchFailure);
		return(self::awaitRead(function (&$failure) use ($adapter, $plan, $tx, $reply) {
			$receiptFailure = null;
			$receipts = $adapter->loadPhaseReceipts($plan->phase(), $tx, $receiptFailure);
			if (!self::validLoadReceipts($receipts)) {
				$failure = $receiptFailure === 'receipt-ledger-corrupt' ?
					'receipt-ledger-corrupt' : 'load-fence-pending';
				return(false);
			}
			if ($receipts['begin'] === '0') {
				if ($receipts['fence'] !== '0') {
					$failure = 'receipt-ledger-corrupt';
					return(false);
				}
				if ($reply === 'RETRACKERS_LOAD_CHANGED') {
					$failure = 'load-wrapper-changed';
					return('changed');
				}
				$failure = 'load-dispatch-pending';
				return(false);
			}
			if ($receipts['fence'] !== '1') {
				$adapter->recordRecoveryFailure('load-fence-pending');
			}
			while ($receipts['fence'] !== '1') {
				// Begin is durable dispatch evidence. The one-shot wrapper must stay
				// alive and wait only on its scheduled fence, without a timeout or an
				// unrelated RPC being treated as a load barrier.
				usleep((int)(RETRACKERS_RECEIPT_POLL * 1000000));
				$receiptFailure = null;
				$next = $adapter->loadPhaseReceipts($plan->phase(), $tx, $receiptFailure);
				if (!self::validLoadReceipts($next)) {
					if ($receiptFailure === 'receipt-ledger-corrupt') {
						$failure = 'receipt-ledger-corrupt';
						return(false);
					}
					continue;
				}
				if ($next['begin'] !== '1') {
					$failure = 'receipt-ledger-corrupt';
					return(false);
				}
				$receipts = $next;
			}
			if ($reply === 'RETRACKERS_LOAD_CHANGED') {
				$failure = 'load-response-inconsistent';
				return(false);
			}
			$failure = null;
			return(true);
		}, $adapter, $waitForCompletion, array('receipt-ledger-corrupt', 'load-fence-pending', 'load-dispatch-pending', 'load-response-inconsistent'), $failure));
	}
	public static function dispatchCandidateObligation($tx,
		RetrackersPostEraseObligation $obligation, RetrackersAnonymousStage $stage,
		$adapter, &$failure = null, $waitForCompletion = false)
	{
		$result = $obligation->tx() === $tx ? self::dispatchLoadObligation(
			$tx, $obligation->candidate(), $adapter, $failure, $waitForCompletion) : false;
		if ($result !== true) {
			return(false);
		}
		$stage->closeCandidateAfterFence();
		$failure = null;
		return(true);
	}

	private static function genericValue(array $pairs, $name, &$present)
	{
		$present = false;
		foreach ($pairs as $pair) {
			if ($pair['name'] === $name) {
				$present = true;
				return($pair['value']);
			}
		}
		return('');
	}

	public static function classifyCandidateObservation($tx, $expectedHash,
		RetrackersLoadObligation $plan, $observation)
	{
		if (!is_string($tx) || preg_match('/^[0-9a-f]{32}$/D', $tx) !== 1 ||
			!is_string($expectedHash) ||
			preg_match('/^[0-9A-F]{40}$/D', $expectedHash) !== 1 ||
			!is_array($observation) || !isset($observation['ok'], $observation['presence']) ||
			$observation['ok'] !== true) {
			return(self::closedPostEraseClassification('candidate', 'candidate-unconfirmed'));
		}
		if ($observation['presence'] === 'absent') {
			return(self::closedPostEraseClassification(
				'candidate', 'candidate-absent-ambiguous'));
		}
		if ($observation['presence'] !== 'present' ||
			!isset($observation['snapshot']) || !is_array($observation['snapshot'])) {
			return(self::closedPostEraseClassification('candidate', 'candidate-unconfirmed'));
		}
		$tuple = RetrackersPostEraseObligation::capturedTuple($observation['snapshot']);
		if ($tuple === false) {
			return(self::closedPostEraseClassification('candidate', 'candidate-unconfirmed'));
		}
		$scalar = $tuple['scalar'];
		$markerPresent = false;
		$ackPresent = false;
		$mapMarker = self::genericValue(
			$tuple['generic_map'], 'retrackers-recovery', $markerPresent);
		$mapAck = self::genericValue(
			$tuple['generic_map'], 'retrackers-recovery-ack', $ackPresent);
		if (!$markerPresent || $mapMarker !== $scalar['recovery_marker'] ||
			($ackPresent && $mapAck !== $scalar['recovery_ack'])) {
			return(self::closedPostEraseClassification('candidate', 'candidate-unconfirmed'));
		}
		$ready = 'v1:candidate-ready:' . $tx;
		$claim = 'v1:candidate-claim:' . $tx;
		if ($scalar['recovery_marker'] === $ready) {
			if ($scalar['hashing_failed'] !== '0') {
				return(self::closedPostEraseClassification('candidate', 'candidate-load-failed'));
			}
			if ($scalar['recovery_ack'] === $ready && $ackPresent) {
				return(self::closedPostEraseClassification('candidate', 'candidate-confirmed'));
			}
			return(self::closedPostEraseClassification('candidate',
				$scalar['recovery_ack'] === '' ?
					'candidate-hook-incomplete' : 'candidate-unconfirmed'));
		}
		if ($scalar['recovery_marker'] === $claim) {
			if ($scalar['hashing_failed'] !== '1') {
				return(self::closedPostEraseClassification(
					'candidate', $scalar['hashing_failed'] === '0' ?
						'candidate-prefix-changed' : 'candidate-load-failed'));
			}
			foreach ($plan->validPrefixes() as $prefix) {
				$prefix['scalar']['local_id'] = $scalar['local_id'];
				if ($tuple === $prefix) {
					return(self::closedPostEraseClassification(
						'candidate', 'candidate-partial'));
				}
			}
			return(self::closedPostEraseClassification(
				'candidate', 'candidate-prefix-changed'));
		}
		if (preg_match('/^v1:(?:candidate|rollback)-(?:claim|ready):[0-9a-f]{32}$/D',
			$scalar['recovery_marker']) === 1 ||
			preg_match('/^v1:original:[01]:[0-9A-F]{40}:[0-9a-f]{64}$/D',
				$scalar['recovery_marker']) === 1) {
			return(self::closedPostEraseClassification('candidate', 'foreign-generation'));
		}
		return(self::closedPostEraseClassification('candidate', 'candidate-unconfirmed'));
	}

	public static function classifyRollbackObservation($tx, $expectedHash, $observation)
	{
		if (!is_string($tx) || preg_match('/^[0-9a-f]{32}$/D', $tx) !== 1 ||
			!is_string($expectedHash) ||
			preg_match('/^[0-9A-F]{40}$/D', $expectedHash) !== 1 ||
			!is_array($observation) || !isset($observation['ok'], $observation['presence']) ||
			$observation['ok'] !== true || $observation['presence'] === 'absent') {
			return(self::closedPostEraseClassification('rollback', 'rollback-unconfirmed'));
		}
		if ($observation['presence'] !== 'present' ||
			!isset($observation['snapshot']) || !is_array($observation['snapshot'])) {
			return(self::closedPostEraseClassification('rollback', 'rollback-unconfirmed'));
		}
		$tuple = RetrackersPostEraseObligation::capturedTuple($observation['snapshot']);
		if ($tuple === false) {
			return(self::closedPostEraseClassification('rollback', 'rollback-unconfirmed'));
		}
		$scalar = $tuple['scalar'];
		$markerPresent = false;
		$ackPresent = false;
		$mapMarker = self::genericValue(
			$tuple['generic_map'], 'retrackers-recovery', $markerPresent);
		$mapAck = self::genericValue(
			$tuple['generic_map'], 'retrackers-recovery-ack', $ackPresent);
		if (!$markerPresent || $mapMarker !== $scalar['recovery_marker'] ||
			($ackPresent && $mapAck !== $scalar['recovery_ack'])) {
			return(self::closedPostEraseClassification('rollback', 'rollback-unconfirmed'));
		}
		$ready = 'v1:rollback-ready:' . $tx;
		$claim = 'v1:rollback-claim:' . $tx;
		if ($scalar['recovery_marker'] === $ready) {
			if ($scalar['hashing_failed'] !== '0') {
				return(self::closedPostEraseClassification('rollback', 'rollback-load-failed'));
			}
			return(self::closedPostEraseClassification('rollback',
				$scalar['recovery_ack'] === $ready && $ackPresent ?
					'rollback-confirmed' : 'rollback-unconfirmed'));
		}
		if ($scalar['recovery_marker'] === $claim) {
			return(self::closedPostEraseClassification('rollback',
				$scalar['hashing_failed'] === '0' ?
					'rollback-partial' : 'rollback-load-failed'));
		}
		if (preg_match('/^v1:(?:candidate|rollback)-(?:claim|ready):[0-9a-f]{32}$/D',
			$scalar['recovery_marker']) === 1 ||
			preg_match('/^v1:original:[01]:[0-9A-F]{40}:[0-9a-f]{64}$/D',
				$scalar['recovery_marker']) === 1) {
			return(self::closedPostEraseClassification('rollback', 'foreign-generation'));
		}
		return(self::closedPostEraseClassification('rollback', 'rollback-unconfirmed'));
	}

	public static function rollbackOwnedGeneration($tx, $hash,
		RetrackersPostEraseObligation $obligation, RetrackersAnonymousStage $stage,
		$adapter, $observer, &$failure = null, &$confirmedLocalId = null, $waitForCompletion = false)
	{
		$confirmedLocalId = null;
		$result = $obligation->tx() === $tx ? self::dispatchLoadObligation(
			$tx, $obligation->rollback(), $adapter, $failure, $waitForCompletion) : false;
		if ($result !== true) {
			return(false);
		}
		$stage->closeOriginalAfterRollbackFence();
		$observation = is_object($observer) && method_exists($observer, 'observe') ?
			$observer->observe($hash) : array('ok' => false);
		$class = self::classifyRollbackObservation($tx, $hash, $observation);
		if ($class === 'rollback-confirmed') {
			$confirmedLocalId = $observation['snapshot']['scalar']['local_id'];
			$failure = null;
			return($class);
		}
		$failure = $class;
		return(false);
	}

	private static function validCleanupReceipts($receipts)
	{
		if (!is_array($receipts) ||
			array_keys($receipts) !== array('begin', 'done', 'exit')) {
			return(false);
		}
		foreach ($receipts as $value) {
			if (!in_array($value, array('0', '1'), true)) {
				return(false);
			}
		}
		return(true);
	}

	private static function classifyCleanupObservation($tx, $expectedLocalId, $observation)
	{
		if (!is_array($observation) || !isset($observation['ok'], $observation['presence']) ||
			$observation['ok'] !== true) {
			return(self::closedPostEraseClassification('cleanup', 'candidate-unconfirmed'));
		}
		if ($observation['presence'] === 'absent') {
			return(self::closedPostEraseClassification(
				'cleanup', 'candidate-absent-ambiguous'));
		}
		if ($observation['presence'] !== 'present' ||
			!isset($observation['snapshot']) || !is_array($observation['snapshot'])) {
			return(self::closedPostEraseClassification('cleanup', 'candidate-unconfirmed'));
		}
		$tuple = RetrackersPostEraseObligation::capturedTuple($observation['snapshot']);
		if ($tuple === false) {
			return(self::closedPostEraseClassification('cleanup', 'candidate-unconfirmed'));
		}
		$scalar = $tuple['scalar'];
		// Cleanup already captured this generation before its CAS dispatch. A
		// same-hash replacement must not inherit that earlier observation.
		if ($scalar['local_id'] !== $expectedLocalId) {
			return(self::closedPostEraseClassification('cleanup', 'foreign-generation'));
		}
		$markerPresent = false;
		$ackPresent = false;
		$mapMarker = self::genericValue(
			$tuple['generic_map'], 'retrackers-recovery', $markerPresent);
		$mapAck = self::genericValue(
			$tuple['generic_map'], 'retrackers-recovery-ack', $ackPresent);
		if (!$markerPresent || $mapMarker !== $scalar['recovery_marker'] ||
			($ackPresent && $mapAck !== $scalar['recovery_ack'])) {
			return(self::closedPostEraseClassification('cleanup', 'candidate-unconfirmed'));
		}
		$ready = 'v1:candidate-ready:' . $tx;
		$claim = 'v1:candidate-claim:' . $tx;
		if ($scalar['recovery_marker'] === $ready) {
			if ($scalar['hashing_failed'] !== '0') {
				return(self::closedPostEraseClassification('cleanup', 'candidate-load-failed'));
			}
			if ($scalar['recovery_ack'] === $ready && $ackPresent) {
				return(self::closedPostEraseClassification('cleanup', 'candidate-confirmed'));
			}
			return(self::closedPostEraseClassification('cleanup',
				$scalar['recovery_ack'] === '' ?
					'candidate-hook-incomplete' : 'candidate-unconfirmed'));
		}
		if ($scalar['recovery_marker'] === $claim) {
			return(self::closedPostEraseClassification('cleanup', 'candidate-prefix-changed'));
		}
		if (preg_match('/^v1:(?:candidate|rollback)-(?:claim|ready):[0-9a-f]{32}$/D',
			$scalar['recovery_marker']) === 1 ||
			preg_match('/^v1:original:[01]:[0-9A-F]{40}:[0-9a-f]{64}$/D',
				$scalar['recovery_marker']) === 1) {
			return(self::closedPostEraseClassification('cleanup', 'foreign-generation'));
		}
		return(self::closedPostEraseClassification('cleanup', 'candidate-unconfirmed'));
	}

	public static function cleanupOwnedCandidate($tx, $hash,
		RetrackersLoadObligation $candidate, array $tuple, $adapter,
		$observer, &$failure = null, &$confirmedLocalId = null, $waitForCompletion = false)
	{
		$confirmedLocalId = null;
		$cleanupPlan = $candidate->cleanupPlanForTuple($tuple);
		if (!($cleanupPlan instanceof RetrackersCandidateCleanupPlan) ||
			!$cleanupPlan->matches($tx, $hash, $tuple)) {
			$failure = 'cleanup-dispatch-pending';
			return(false);
		}
		$callback = $cleanupPlan->callback();
		if (!($callback instanceof RetrackersCandidateCleanupCallback) ||
			!$cleanupPlan->complete()) {
			// Every dispatchable callback was sealed before erase. Retain rollback
			// ownership if the immutable obligation is nevertheless unavailable.
			$failure = 'cleanup-dispatch-pending';
			return(false);
		}
		if (!self::armPostErasePhase('ca', $tx, $adapter, $failure)) {
			return(false);
		}
		$dispatchFailure = null;
		$reply = $adapter->executeCandidateCleanup($callback, $dispatchFailure);
		return(self::awaitRead(function (&$failure) use ($adapter, $tx, $hash,
			$observer, $reply, $tuple, &$confirmedLocalId, $waitForCompletion) {
			$receiptFailure = null;
			$receipts = $adapter->cleanupPhaseReceipts($tx, $receiptFailure);
			$observation = is_object($observer) && method_exists($observer, 'observe') ?
				$observer->observe($hash) : array('ok' => false);
			if (!self::validCleanupReceipts($receipts)) {
				$failure = $receiptFailure === 'receipt-ledger-corrupt' ?
					'receipt-ledger-corrupt' : 'cleanup-dispatch-pending';
				return(false);
			}
			if ($receipts['begin'] === '0') {
				if ($receipts['done'] !== '0' || $receipts['exit'] !== '0') {
					$failure = 'receipt-ledger-corrupt';
					return(false);
				}
				if ($reply === 'RETRACKERS_CANDIDATE_SKIPPED') {
					$failure = null;
					return('candidate-cleanup-skipped');
				}
				$failure = 'cleanup-dispatch-pending';
				return(false);
			}
			if ($receipts['exit'] !== '1') {
				$failure = 'cleanup-completion-pending';
				return(false);
			}
			if ($waitForCompletion && (!is_array($observation) ||
				!isset($observation['ok']) || $observation['ok'] !== true)) {
				// The erase tail is known, but rollback still needs fresh absence.
				$failure = 'cleanup-completion-pending';
				return(false);
			}
			if ($receipts['done'] === '1' && is_array($observation) &&
				isset($observation['ok'], $observation['presence']) &&
				$observation['ok'] === true && $observation['presence'] === 'absent') {
				$failure = null;
				return('candidate-cleanup-confirmed');
			}
			$failure = null;
			$class = self::classifyCleanupObservation($tx, $tuple['scalar']['local_id'], $observation);
			if ($class === 'candidate-confirmed') {
				$confirmedLocalId = $observation['snapshot']['scalar']['local_id'];
			}
			return($class);
		}, $adapter, $waitForCompletion, array('receipt-ledger-corrupt', 'cleanup-dispatch-pending', 'cleanup-completion-pending'), $failure));
	}
	public static function terminalCleanup($tx, $adapter, &$failure = null,
		$waitForCompletion = false, $sourceHash = null, $sourceIncarnation = null)
	{
		if ($adapter->getFamily() !== 1) {
			if (!is_string($sourceHash) ||
				preg_match('/^[0-9A-F]{40}$/D', $sourceHash) !== 1 ||
				!is_string($sourceIncarnation) ||
				preg_match('/^[0-9a-f]{32}$/D', $sourceIncarnation) !== 1 ||
				!$adapter->nativeFinalizedOriginal(
					$sourceHash, $tx, $sourceIncarnation, $failure)) {
				$failure = 'durable-release-required';
				return(false);
			}
			$currentI = $adapter->nativeOriginalIncarnation($sourceHash, $failure);
			$owner = $adapter->sourceScalarSnapshot($sourceHash, $failure);
			$values = is_array($owner) && isset($owner['family'], $owner['values']) &&
				$owner['family'] === 2 && is_array($owner['values']) ? $owner['values'] : null;
			if ($currentI !== $sourceIncarnation || !is_array($values) ||
				!isset($values['recovery_marker'], $values['recovery_ack']) ||
				$values['recovery_marker'] !== '' || $values['recovery_ack'] !== '') {
				$failure = 'durable-release-required';
				return(false);
			}
		}
		$callback = RetrackersTerminalCleanupCallback::build($tx);
		if ($callback === false) {
			$failure = 'terminal-cleanup-pending';
			return(false);
		}
		$replyFailure = null;
		$reply = $adapter->executeTerminalCleanup($callback, $replyFailure);
		$remaining = array();
		$decision = self::awaitRead(function (&$failure) use ($adapter, $tx, &$remaining) {
			$readFailure = null;
			$keys = $adapter->getLedgerKeys($readFailure);
			if (!is_array($keys)) {
				$failure = 'terminal-cleanup-pending';
				return(false);
			}
			$remaining = self::remainingOwnedLedgerKeys($tx, $keys);
			if (count($remaining) === 0) {
				$failure = null;
				return('clean');
			}
			if (in_array('wa:' . $tx, $remaining, true)) {
				$failure = 'terminal-cleanup-pending';
				return(false);
			}

			return('suffix');
		}, $adapter, $waitForCompletion, array('terminal-cleanup-pending'), $failure);
		if ($decision !== 'suffix') {
			return($decision);
		}

		$suffix = RetrackersTerminalCleanupCallback::buildSuffix($tx, $remaining);
		if ($suffix === false) {
			$failure = 'terminal-cleanup-unconfirmed';
			return('unconfirmed');
		}
		$suffixFailure = null;
		$adapter->executeTerminalCleanup($suffix, $suffixFailure);
		$finalReadFailure = null;
		$finalKeys = $adapter->getLedgerKeys($finalReadFailure);
		if (!is_array($finalKeys)) {
			$failure = 'terminal-cleanup-unconfirmed';
			return('unconfirmed');
		}
		$remaining = self::remainingOwnedLedgerKeys($tx, $finalKeys);
		if (count($remaining) === 0) {
			$failure = null;
			return('clean');
		}
		if (in_array('wa:' . $tx, $remaining, true)) {
			$failure = 'terminal-cleanup-pending';
			return(false);
		}
		$failure = 'terminal-cleanup-unconfirmed';
		return('unconfirmed');
	}

	private static function resultFailure($result, $field, $fallback)
	{
		$allowed = array(
			'failure' => array(
				'ownership-mismatch' => true,
				'initial-absent' => true,
				'initial-fault' => true,
				'initial-malformed' => true,
				'initial-transport' => true,
				'initial-hashing-failed' => true,
				'lifecycle-unsupported' => true,
				'source-unreadable' => true,
				'runtime-map-unstable' => true,
				'tracker-state-ambiguous' => true,
			),
			'reason' => array(
				'runtime-tracker-topology-mismatch' => true,
				'source-unreadable' => true,
				'source-not-regular' => true,
				'source-read-error' => true,
				'source-short-read' => true,
				'source-too-large' => true,
				'source-bencode-invalid' => true,
				'source-duplicate-key' => true,
				'source-too-complex' => true,
				'source-too-deep' => true,
				'source-hash-mismatch' => true,
				'source-decode-failed' => true,
				'candidate-tracker-projection-failed' => true,
				'candidate-hash-mismatch' => true,
				'candidate-too-large' => true,
			),
		);
		if (is_array($result) && isset($result[$field]) && is_string($result[$field]) &&
			isset($allowed[$field][$result[$field]])) {
			return($result[$field]);
		}
		return($fallback);
	}

	private static function finishPreFenceFailure($reason, $tx, $hash, $handoff, $localId,
		$adapter, $stage = null)
	{
		if ($stage instanceof RetrackersAnonymousStage) {
			$stage->abortBeforeArm();
		}
		if (strncmp($handoff, 'v2:original:', 12) === 0) {
			$adapter->recordRecoveryFailure($reason);
			return(self::holdUnknownLease());
		}
		$disposeFailure = null;
		$decision = self::disposeOriginalHandoff(
			$tx, $hash, $handoff, $localId, $adapter, $disposeFailure, true);
		if ($decision === 'hold-release' || $decision === 'hold-cleanup') {
			$adapter->recordRecoveryFailure($disposeFailure);
			return($stage instanceof RetrackersAnonymousStage ?
				self::holdUnknownStage($stage) : self::holdUnknownLease());
		}
		if ($decision === 'terminal-unconfirmed') {
			$adapter->recordRecoveryFailure($disposeFailure);
			return(false);
		}
		$adapter->recordRecoveryFailure($reason);
		return(false);
	}

	private static function retireNativePending($hash, $localId, $tx, $incarnation, $adapter)
	{
		$callback = RetrackersLifecycleCallbacks::retireNativePending(
			$hash, $localId, $tx, $incarnation);
		if ($callback === false) return(false);
		$failure = null;
		$wp = 'wp:' . $localId;
		$wh = 'wh:' . $localId;
		$adapter->executeLifecycleCallback($callback, $failure);
		$deadline = hrtime(true) + ((float)RETRACKERS_TEARDOWN_TIMEOUT) * 1000000000.0;
		while (true) {
			$keys = $adapter->getLedgerKeys($failure);
			if (!is_array($keys)) return(false);
			if (!in_array($wp, $keys, true)) return(true);
			if (!in_array($wh, $keys, true)) break;
			if (hrtime(true) >= $deadline) return(false);
			usleep(50000);
		}
		// The hook may clear wh after the first CAS. Retry only this exact
		// terminal callback; native finish must never be sent a second time.
		$adapter->executeLifecycleCallback($callback, $failure);
		$keys = $adapter->getLedgerKeys($failure);
		return(is_array($keys) && !in_array($wp, $keys, true));
	}

	private static function finishNoChange($tx, $hash, $handoff, $localId, $adapter)
	{
		if (strncmp($handoff, 'v2:original:', 12) === 0) {
			$failure = null;
			$incarnation = $adapter->nativeOriginalIncarnation($hash, $failure);
			if ($incarnation === false) {
				$adapter->recordRecoveryFailure($failure);
				return(self::holdUnknownLease());
			}
			// A lost finish reply is reconciled only by the exact durable T/I
			// receipt and marker-free owner readback; never send finish twice.
			$adapter->nativeFinishOriginal($hash, $tx, $incarnation, $failure);
			if (!$adapter->nativeFinalizedOriginal($hash, $tx, $incarnation, $failure)) {
				$adapter->recordRecoveryFailure($failure);
				return(self::holdUnknownLease());
			}
			$owner = $adapter->sourceScalarSnapshot($hash, $failure);
			$values = is_array($owner) && isset($owner['values']) ? $owner['values'] : null;
			$readIncarnation = $adapter->nativeOriginalIncarnation($hash, $failure);
			// L0 changes after daemon restart; the durable I and finalized T/I
			// receipt carry owner identity across that boundary.
			if (!is_array($values) || !isset($values['recovery_marker'],
				$values['recovery_ack']) || $values['recovery_marker'] !== '' ||
				$values['recovery_ack'] !== '' || $readIncarnation !== $incarnation) {
				$adapter->recordRecoveryFailure('native-original-owner-readback-pending');
				return(self::holdUnknownLease());
			}
			if (!self::retireNativePending($hash, $localId, $tx, $incarnation, $adapter)) {
				$adapter->recordRecoveryFailure('native-pending-retire-unconfirmed');
				return(self::holdUnknownLease());
			}
			return(true);
		}
		$disposeFailure = null;
		$decision = self::disposeOriginalHandoff(
			$tx, $hash, $handoff, $localId, $adapter, $disposeFailure, true);
		if ($decision === 'hold-release' || $decision === 'hold-cleanup') {
			$adapter->recordRecoveryFailure($disposeFailure);
			return(self::holdUnknownLease());
		}
		if ($decision === 'terminal-unconfirmed') {
			$adapter->recordRecoveryFailure($disposeFailure);
			return(false);
		}
		if ($decision === 'quarantined-clean') {
			$adapter->recordRecoveryFailure('handoff-release-changed');
			return(false);
		}
		return($decision === 'released-clean');
	}

	private static function finishKnownPostErase($reason, $tx,
		RetrackersAnonymousStage $stage, $adapter)
	{
		$stage->closeOriginalAfterNoRollback();
		$cleanupFailure = null;
		$cleanup = self::terminalCleanup($tx, $adapter, $cleanupFailure, true);
		if ($cleanup === false) {
			$adapter->recordRecoveryFailure($cleanupFailure);
			return(self::holdUnknownStage($stage));
		}
		if ($cleanup !== 'clean') {
			$adapter->recordRecoveryFailure($cleanupFailure === null ?
				'terminal-cleanup-unconfirmed' : $cleanupFailure);
			return(false);
		}
		$adapter->recordRecoveryFailure($reason);
		return(false);
	}

	private static function releaseLoadedGeneration($tx, $hash, $marker, $localId,
		RetrackersAnonymousStage $stage, $adapter)
	{
		$stage->closeOriginalAfterNoRollback();
		$releaseFailure = null;
		$release = self::releaseSurvivingHandoff(
			$tx, $hash, $marker, $localId, $adapter, $releaseFailure, true);
		if ($release === false) {
			$adapter->recordRecoveryFailure($releaseFailure);
			return(self::holdUnknownStage($stage));
		}
		$cleanupFailure = null;
		$cleanup = self::terminalCleanup($tx, $adapter, $cleanupFailure, true);
		if ($cleanup === false) {
			$adapter->recordRecoveryFailure($cleanupFailure);
			return(self::holdUnknownStage($stage));
		}
		if ($cleanup !== 'clean') {
			$adapter->recordRecoveryFailure($cleanupFailure === null ?
				'terminal-cleanup-unconfirmed' : $cleanupFailure);
			return(false);
		}
		if ($release !== 'released') {
			$adapter->recordRecoveryFailure('handoff-release-changed');
			return(false);
		}
		return(true);
	}

	private static function postErasePending($failure)
	{
		return(in_array($failure, array(
			'load-arm-pending', 'load-dispatch-pending', 'load-fence-pending',
			'receipt-ledger-corrupt', 'load-response-inconsistent',
			'cleanup-arm-pending', 'cleanup-dispatch-pending',
			'cleanup-completion-pending',
		), true));
	}

	private static function normalizeBinaryFlag($value, &$normalized)
	{
		if ($value === false || $value === 0 || $value === '0') {
			$normalized = false;
			return(true);
		}
		if ($value === true || $value === 1 || $value === '1') {
			$normalized = true;
			return(true);
		}
		$normalized = null;
		return(false);
	}

	private static function nativeCommitReceiptMatches($receipt, $hash, $tx,
		$sourceI, $candidateI, $localId, $marker, $digest)
	{
		$parts = is_string($receipt) ? explode('|', $receipt) : array();
		return(count($parts) === 11 && $parts[0] === 'v1' &&
			in_array($parts[1], array('committed', 'released'), true) &&
			$parts[2] === $hash && $parts[3] === $hash &&
			$parts[4] === $tx && $parts[5] === $sourceI &&
			$parts[6] === $candidateI && $parts[7] === $localId &&
			$parts[8] === $marker && $parts[9] === $digest &&
			preg_match('/^(?:0|[1-9][0-9]*)$/D', $parts[10]) === 1);
	}

	private static function nativeCandidate($observation, $tx)
	{
		if (!is_array($observation) || !isset($observation['ok'],
			$observation['presence'], $observation['snapshot']['scalar']) ||
			$observation['ok'] !== true || $observation['presence'] !== 'present') {
			return(false);
		}
		$scalar = $observation['snapshot']['scalar'];
		$ready = 'v2:candidate-ready:' . $tx;
		return(isset($scalar['recovery_marker'], $scalar['recovery_ack']) &&
			$scalar['recovery_marker'] === $ready &&
			$scalar['recovery_ack'] === $ready);
	}

	private static function nativeHold($reason, $adapter, RetrackersAnonymousStage $stage)
	{
		$adapter->recordRecoveryFailure($reason);
		return(self::holdUnknownStage($stage));
	}

	private static function runNativeSameHash($hash, $handoff, $localId, $tx,
		$snapshot, $prep, $adapter, $snapshotService, $php)
	{
		$failure = null;
		if (!$adapter->nativeSameHashCapability($failure)) {
			$adapter->recordRecoveryFailure($failure);
			return(self::holdUnknownLease());
		}
		$commands = RetrackersPostEraseObligation::nativeWorkerCommands(
			$snapshot, $failure);
		if ($commands === false) {
			$adapter->recordRecoveryFailure($failure);
			return(self::holdUnknownLease());
		}
		$sourceI = $adapter->nativeOriginalIncarnation($hash, $failure);
		if ($sourceI === false) {
			$adapter->recordRecoveryFailure($failure);
			return(self::holdUnknownLease());
		}
		$stage = RetrackersAnonymousStage::create(
			$prep['candidate'], $prep['original'], $failure);
		if ($stage === false) {
			$adapter->recordRecoveryFailure(is_string($failure) ?
				$failure : 'stage-identity-failed');
			return(self::holdUnknownLease());
		}
		if ($php === null && class_exists('Utility')) $php = Utility::getPHP();
		if (!$adapter->preflightStage($stage, $php, $failure)) {
			return(self::finishPreFenceFailure('procfd-preflight-failed',
				$tx, $hash, $handoff, $localId, $adapter, $stage));
		}
		$candidate = $stage->candidate();
		$claim = 'v2:candidate-claim:' . $tx;
		$ready = 'v2:candidate-ready:' . $tx;
		$creation = array_merge(array('', $candidate['capability']),
			array($commands[0],
				'd.custom.set=retrackers-recovery,' . $claim,
				'd.retrackers.apply=' . $hash . ',' . $localId . ',' . $tx),
			array_slice($commands, 1),
			array('d.custom.set=retrackers-recovery,' . $ready,
				'd.custom.set=retrackers-recovery-ack,' . $ready));
		$limit = $adapter->maxContentSize($failure);
		if ($limit === false ||
			strlen(retrackersBuildDirectRequest('load.normal', $creation)) > $limit) {
			return(self::finishPreFenceFailure('commit-request-limit-invalid',
				$tx, $hash, $handoff, $localId, $adapter, $stage));
		}
		if (!$adapter->nativeCaptureOriginal($hash, $localId, $handoff,
			$tx, $candidate['sha256'], $failure)) {
			return(self::nativeHold('native-capture-unconfirmed', $adapter, $stage));
		}
		$absent = $snapshotService->observe($hash);
		if (!is_array($absent) || !isset($absent['ok'], $absent['presence']) ||
			$absent['ok'] !== true || $absent['presence'] !== 'absent') {
			return(self::nativeHold('native-capture-readback-unconfirmed',
				$adapter, $stage));
		}
		// A lost load reply cannot authorize a second load. Reconcile the exact
		// candidate marker and incarnation after the one dispatch.
		$adapter->executeDirectMutation('load.normal', $creation, $failure);
		$observation = null;
		for ($attempt = 0; $attempt < 100; $attempt++) {
			$observation = $snapshotService->observe($hash);
			if (self::nativeCandidate($observation, $tx)) break;
			if (is_array($observation) && isset($observation['ok'],
				$observation['presence']) && $observation['ok'] === true &&
				$observation['presence'] === 'present') break;
			usleep(100000);
		}
		if (!self::nativeCandidate($observation, $tx)) {
			return(self::nativeHold('native-candidate-unconfirmed', $adapter, $stage));
		}
		$candidateI = $adapter->nativeOriginalIncarnation($hash, $failure);
		if ($candidateI === false || $candidateI === $sourceI) {
			return(self::nativeHold('native-candidate-incarnation-unconfirmed',
				$adapter, $stage));
		}
		$adapter->nativeCommitSameHash($hash, $localId, $tx, $failure);
		$receipt = $adapter->nativeWorkerReceipt($hash, $failure);
		if (!self::nativeCommitReceiptMatches($receipt, $hash, $tx, $sourceI,
			$candidateI, $localId, $handoff, $candidate['sha256'])) {
			return(self::nativeHold('native-commit-unconfirmed', $adapter, $stage));
		}
		$adapter->nativeReleaseSameHash($hash, $tx, $candidateI, $failure);
		if (!$adapter->nativeFinalizedOriginal($hash, $tx, $candidateI, $failure)) {
			return(self::nativeHold('native-release-unconfirmed', $adapter, $stage));
		}
		$owner = $adapter->sourceScalarSnapshot($hash, $failure);
		$values = is_array($owner) && isset($owner['values']) ?
			$owner['values'] : null;
		$readI = $adapter->nativeOriginalIncarnation($hash, $failure);
		if (!is_array($values) || !isset($values['recovery_marker'],
			$values['recovery_ack']) || $values['recovery_marker'] !== '' ||
			$values['recovery_ack'] !== '' || $readI !== $candidateI) {
			return(self::nativeHold('native-candidate-owner-readback-pending',
				$adapter, $stage));
		}
		if (!self::retireNativePending($hash, $localId, $tx, $candidateI, $adapter)) {
			return(self::nativeHold('native-pending-retire-unconfirmed',
				$adapter, $stage));
		}
		$stage->closeCandidateAfterFence();
		$stage->closeOriginalAfterNoRollback();
		return(true);
	}

	public static function run($hash, $user, $handoff, $state, $localId, $adapter = null,
		$authority = null, $snapshotService = null, $trks = null, $php = null)
	{
		if ($adapter === null) {
			$adapter = new RetrackersWorkerRpcAdapter();
		}
		if ($authority === null) {
			$authority = new RetrackersMetainfoAuthority();
		}
		if ($snapshotService === null) {
			$snapshotService = new RetrackersStableSourceSnapshot($adapter);
		}

		$failure = null;
		$nativeOriginal = preg_match(
			'/^v2:original:([01]):([0-9A-F]{40}):([0-9a-f]{64}):([0-9a-f]{32})$/D',
			$handoff, $nativeParts) === 1;
		if (strncmp($handoff, 'v2:', 3) === 0 &&
			(!$nativeOriginal || $nativeParts[1] !== $state ||
			$nativeParts[2] !== $localId ||
			!hash_equals(hash('sha256', $user), $nativeParts[3]))) {
			$adapter->recordRecoveryFailure('native-original-identity-invalid');
			return(self::holdUnknownLease());
		}
		if ($nativeOriginal) {
			$tx = $nativeParts[4];
			if (!$adapter->nativeOriginalCapability($failure)) {
				$adapter->recordRecoveryFailure($failure === null ?
					'native-original-capability-unconfirmed' : $failure);
				return(self::holdUnknownLease());
			}
			$terminal = $adapter->nativeFinalizedReceipt($hash, $failure);
			if (is_array($terminal) && $terminal['tx'] === $tx) {
				$currentI = $adapter->nativeOriginalIncarnation($hash, $failure);
				$owner = $adapter->sourceScalarSnapshot($hash, $failure);
				$values = is_array($owner) && isset($owner['values']) ?
					$owner['values'] : null;
				if ($currentI === $terminal['incarnation'] && is_array($values) &&
					isset($values['recovery_marker'], $values['recovery_ack']) &&
					$values['recovery_marker'] === '' && $values['recovery_ack'] === '') {
					// Cold pre-ingress can finalize the original claim before this
					// worker ran. A terminal receipt proves safety, not an update.
					$keys = $adapter->getLedgerKeys($failure);
					if (is_array($keys) && in_array('wp:' . $localId, $keys, true)) {
						self::retireNativePending($hash, $localId, $tx, $currentI, $adapter);
					}
					$adapter->recordRecoveryFailure('native-original-finalized-before-worker');
					return(false);
				}
			}
		} else {
			// A v1 marker has no durable T/I claim on the native daemon.
			$family = $adapter->probeFamily($failure);
			if ($family !== 1) {
				$adapter->recordRecoveryFailure($family === false ? $failure :
					'receipt-ledger-corrupt');
				return(false);
			}
			$tx = self::generateCleanTx($adapter, $failure);
			if ($tx === false) {
				$adapter->recordRecoveryFailure($failure);
				return(false);
			}
			if (!self::adoptWorker($tx, $hash, $handoff, $localId, $adapter, $failure)) {
				$adapter->recordRecoveryFailure($failure);
				return(false);
			}
		}

		$snapResult = $snapshotService->capture($hash, $handoff);
		if (!isset($snapResult['ok']) || $snapResult['ok'] !== true) {
			return(self::finishPreFenceFailure(
				self::resultFailure($snapResult, 'failure', 'initial-malformed'),
				$tx, $hash, $handoff, $localId, $adapter));
		}
		$snapshot = $snapResult['snapshot'];
		$rawAddToBegin = $trks !== null && is_object($trks) &&
			property_exists($trks, 'addToBegin') ? $trks->addToBegin : false;
		$rawDontAddPrivate = $trks !== null && is_object($trks) &&
			property_exists($trks, 'dontAddPrivate') ? $trks->dontAddPrivate : false;
		$addToBegin = null;
		$dontAddPrivate = null;
		if (!self::normalizeBinaryFlag($rawAddToBegin, $addToBegin) ||
			!self::normalizeBinaryFlag($rawDontAddPrivate, $dontAddPrivate)) {
			return(self::finishPreFenceFailure(
				'runtime-value-unrepresentable', $tx, $hash, $handoff,
				$localId, $adapter));
		}
		if (($snapshot['scalar']['is_private'] === '1' && $dontAddPrivate) ||
			$snapshot['scalar']['name'] === $hash . '.meta') {
			return(self::finishNoChange($tx, $hash, $handoff, $localId, $adapter));
		}

		$torrentPath = $snapshot['source']['path'];
		$additions = ($trks !== null && isset($trks->list)) ? $trks->list : array();
		$deletions = ($trks !== null && isset($trks->todelete)) ? $trks->todelete : array();

		$prep = $authority->prepare($torrentPath, $hash, $additions, $deletions, $addToBegin, $snapshot);
		if (!isset($prep['ok']) || $prep['ok'] !== true) {
			return(self::finishPreFenceFailure(
				self::resultFailure($prep, 'reason', 'candidate-tracker-projection-failed'),
				$tx, $hash, $handoff, $localId, $adapter));
		}
		if ($prep['changed'] === false) {
			return(self::finishNoChange($tx, $hash, $handoff, $localId, $adapter));
		}

		if ($nativeOriginal) {
			$scan = isset($prep['candidate_scan']) ? $prep['candidate_scan'] : null;
			if (!is_array($scan) || !isset($scan['info_hash']) ||
				!is_string($scan['info_hash']) ||
				preg_match('/^[0-9A-F]{40}$/D', $scan['info_hash']) !== 1 ||
				$scan['info_hash'] !== $hash) {
				$adapter->recordRecoveryFailure('different-hash-unsupported');
				return(self::holdUnknownLease());
			}
			return(self::runNativeSameHash($hash, $handoff, $localId, $tx,
				$snapshot, $prep, $adapter, $snapshotService, $php));
		}
		$projection = isset($prep['projection']) && is_array($prep['projection']) ?
			$prep['projection'] : array();
		$stage = RetrackersAnonymousStage::create($prep['candidate'], $prep['original'], $failure);
		if ($stage === false) {
			return(self::finishPreFenceFailure(
				is_string($failure) ? $failure : 'stage-identity-failed',
				$tx, $hash, $handoff, $localId, $adapter));
		}
		unset($prep['candidate'], $prep['original']);
		if ($php === null && class_exists('Utility')) {
			$php = Utility::getPHP();
		}
		if (!$adapter->preflightStage($stage, $php, $failure)) {
			return(self::finishPreFenceFailure(
				is_string($failure) ? $failure : 'procfd-preflight-failed',
				$tx, $hash, $handoff, $localId, $adapter, $stage));
		}
		$limitFailure = null;
		$daemonCap = $adapter->maxContentSize($limitFailure);
		if ($daemonCap === false) {
			return(self::finishPreFenceFailure(
				$limitFailure === null ? 'commit-request-limit-invalid' : $limitFailure,
				$tx, $hash, $handoff, $localId, $adapter, $stage));
		}
		$buildFailure = null;
		$oldCallback = RetrackersOldGenerationCommitCallback::build(
			$tx, $hash, $handoff, $snapshot, $daemonCap, $buildFailure);
		if ($oldCallback === false) {
			return(self::finishPreFenceFailure(
				$buildFailure === null ? 'runtime-value-unrepresentable' : $buildFailure,
				$tx, $hash, $handoff, $localId, $adapter, $stage));
		}
		$obligationFailure = null;
		$obligation = RetrackersPostEraseObligation::build(
			$tx, $hash, $stage, $snapshot, $projection, $daemonCap, $obligationFailure);
		if ($obligation === false) {
			return(self::finishPreFenceFailure(
				$obligationFailure === null ?
					'runtime-value-unrepresentable' : $obligationFailure,
				$tx, $hash, $handoff, $localId, $adapter, $stage));
		}
		if (!$adapter->hasDurableCandidateFenceObligation($tx, $failure, $obligation)) {
			if (self::preFenceFailureAction($failure) === 'dispose') {
				// Task 4 must remain non-destructive until Task 5 supplies the fence.
				return(self::finishPreFenceFailure($failure, $tx, $hash, $handoff,
					$localId, $adapter, $stage));
			}
			$adapter->recordRecoveryFailure($failure);
			return(self::holdUnknownStage($stage));
		}

		if (!self::commitOldGeneration($tx, $hash, $handoff, $state, $localId,
			$snapshot, $adapter, $failure, $snapshotService, $oldCallback, $daemonCap, true)) {
			$commitFailure = $failure;
			$disposeFailure = null;
			$disposed = self::handleCommitFailureBeforeArm($commitFailure, $tx, $hash,
				$handoff, $localId, $stage, $adapter, $disposeFailure, true);
			if ($disposed === false) {
				$adapter->recordRecoveryFailure($disposeFailure === null ?
					$commitFailure : $disposeFailure);
				return(self::holdUnknownStage($stage));
			}
			if ($disposed === 'not-deterministic') {
				$adapter->recordRecoveryFailure($commitFailure);
				return(self::holdUnknownStage($stage));
			}
			$adapter->recordRecoveryFailure($disposed === 'clean' ?
				$commitFailure : $disposeFailure);
			return(false);
		}

		if (!self::dispatchCandidateObligation(
			$tx, $obligation, $stage, $adapter, $failure, true)) {
			if ($failure === 'load-wrapper-changed') {
				return(self::finishKnownPostErase(
					$failure, $tx, $stage, $adapter));
			}
			$adapter->recordRecoveryFailure($failure);
			return(self::holdUnknownStage($stage));
		}
		$observation = $snapshotService->observe($hash);
		$candidateClass = self::classifyCandidateObservation(
			$tx, $hash, $obligation->candidate(), $observation);
		if ($candidateClass === 'candidate-confirmed') {
			return(self::releaseLoadedGeneration($tx, $hash,
				'v1:candidate-ready:' . $tx, $observation['snapshot']['scalar']['local_id'], $stage, $adapter));
		}
		if ($candidateClass !== 'candidate-partial') {
			return(self::finishKnownPostErase(
				$candidateClass, $tx, $stage, $adapter));
		}
		$tuple = isset($observation['snapshot']) && is_array($observation['snapshot']) ?
			RetrackersPostEraseObligation::capturedTuple($observation['snapshot']) : false;
		if ($tuple === false) {
			return(self::finishKnownPostErase(
				'candidate-unconfirmed', $tx, $stage, $adapter));
		}
		$confirmedLocalId = null;
		$cleanup = self::cleanupOwnedCandidate(
			$tx, $hash, $obligation->candidate(), $tuple,
			$adapter, $snapshotService, $failure, $confirmedLocalId, true);
		if ($cleanup !== 'candidate-cleanup-confirmed') {
			if ($cleanup === false && self::postErasePending($failure)) {
				$adapter->recordRecoveryFailure($failure);
				return(self::holdUnknownStage($stage));
			}
			if ($cleanup === 'candidate-confirmed') {
				return(self::releaseLoadedGeneration($tx, $hash,
					'v1:candidate-ready:' . $tx, $confirmedLocalId, $stage, $adapter));
			}
			return(self::finishKnownPostErase($cleanup === false ?
				$failure : $cleanup, $tx, $stage, $adapter));
		}
		$rollback = self::rollbackOwnedGeneration(
			$tx, $hash, $obligation, $stage, $adapter, $snapshotService, $failure, $confirmedLocalId, true);
		if ($rollback !== 'rollback-confirmed') {
			if (self::postErasePending($failure)) {
				$adapter->recordRecoveryFailure($failure);
				return(self::holdUnknownStage($stage));
			}
			return(self::finishKnownPostErase($failure, $tx, $stage, $adapter));
		}
		return(self::releaseLoadedGeneration($tx, $hash,
			'v1:rollback-ready:' . $tx, $confirmedLocalId, $stage, $adapter));
	}
}

function retrackersRunRecoveryWorker($hash, $user, $handoff, $state, $localId, $adapter = null,
	$authority = null, $snapshotService = null, $trks = null, $php = null)
{
	return(RetrackersRecoveryCoordinator::run($hash, $user, $handoff, $state, $localId,
		$adapter, $authority, $snapshotService, $trks, $php));
}

function retrackersParseCliArgv($argv)
{
	if (!is_array($argv) || array_keys($argv) !== array(0, 1, 2, 3))
		return(false);
	foreach (array(0, 1, 2, 3) as $key)
		if (!is_string($argv[$key]))
			return(false);
	if (preg_match('/^[0-9A-F]{40}$/D', $argv[1]) !== 1 ||
		preg_match('/^[a-z0-9_-]*$/D', $argv[2]) !== 1 ||
		preg_match('/^v([12]):original:([01]):([0-9A-F]{40}):([0-9a-f]{64})(?::([0-9a-f]{32}))?$/D',
			$argv[3], $handoff) !== 1 ||
		($handoff[1] === '1') === isset($handoff[5]) ||
		!hash_equals(hash('sha256', $argv[2]), $handoff[4]))
		return(false);
	return(array(
		'hash' => $argv[1],
		'user' => $argv[2],
		'state' => $handoff[2],
		'local_id' => $handoff[3],
		'handoff' => $argv[3],
	));
}

function retrackersCliMain($argv)
{
	$cli = retrackersParseCliArgv($argv);
	if ($cli === false)
		return(1);

	$_SERVER['REMOTE_USER'] = $cli['user'];
	require_once( dirname(__FILE__)."/retrackers.php" );
	require_once( dirname(__FILE__)."/../../php/xmlrpc.php" );
	require_once( dirname(__FILE__)."/../../php/rtorrent.php" );
	$trks = rRetrackers::load();
	$adapter = new RetrackersWorkerRpcAdapter($cli['hash']);
	$res = retrackersRunRecoveryWorker($cli['hash'], $cli['user'], $cli['handoff'], $cli['state'],
		$cli['local_id'], $adapter, null, null, $trks, Utility::getPHP());
	return($res ? 0 : 1);
}

if (!defined('RETRACKERS_IMPORT_ONLY'))
	exit(retrackersCliMain(isset($argv) ? $argv : null));
