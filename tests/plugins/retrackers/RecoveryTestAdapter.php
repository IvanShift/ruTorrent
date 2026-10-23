<?php

class RetrackersRecoveryTestAdapter extends RetrackersWorkerRpcAdapter
{
	public $ledgerKeys = array();
	public $scalarSequence = array();
	public $mutations = array();
	public $multicallHistory = array();
	public $adoptCallbacks = array();
	public $ledgerReadUnknown = false;
	public $preflightCalls = array();
	public $preflightResult = true;
	public $preflightFailure = null;
	public $candidateCapability = null;
	public $originalCapability = null;
	public $capabilityReads = array();
	public $loadResult = true;
	public $phaseArmCalls = array();
	public $phaseArmResult = 'RETRACKERS_PHASE_ARMED';
	public $phaseArmResults = array();
	public $loadCallbacks = array();
	public $loadCallbackResults = array();
	public $loadPhaseReceiptSequence = array();
	public $loadPhaseReceiptReads = 0;
	public $cleanupCallbacks = array();
	public $cleanupCallbackResults = array();
	public $cleanupReceiptSequence = array();
	public $cleanupReceiptReads = 0;
	public $oldCommitCalls = array();
	public $oldCommitResult = 'RETRACKERS_ERASED';
	public $oldCommitFailure = null;
	public $oldCommitLedgerKeys = null;
	public $oldCommitReceiptReads = 0;
	public $oldCommitReceiptFailure = null;
	public $oldCommitReceiptSequence = array();
	public $candidateFenceChecks = 0;
	public $candidateFenceReady = false;
	public $candidateFenceObligation = null;
	public $daemonCap = 16777216;
	public $maxContentSizeCalls = 0;
	public $scalarReads = 0;
	public $releaseCallbacks = array();
	public $releaseResults = array();
	public $terminalCleanupCallbacks = array();
	public $terminalCleanupResults = array();
	public $terminalCleanupApply = array();
	public $terminalCleanupResidualPrefixes = array();
	public $terminalCleanupOrder = array();
	public $ledgerReadSequence = array();
	public $ledgerReadCount = 0;
	public $ledgerReadFailureAt = null;
	public $recoveryEvents = array();

	public function __construct($ledgerKeys, $scalarSequence)
	{
		$this->ledgerKeys = $ledgerKeys;
		$this->scalarSequence = $scalarSequence;
		// The recovery fixture exercises the legacy v1 worker on family one.
		$this->family = 1;
	}

	public function getLedgerKeys(&$failure = null)
	{
		$this->ledgerReadCount++;
		if ($this->ledgerReadFailureAt === $this->ledgerReadCount) {
			$failure = 'transport-failed';
			return(false);
		}
		if (count($this->ledgerReadSequence) > 0) {
			$next = array_shift($this->ledgerReadSequence);
			if ($next === false) {
				$failure = 'transport-failed';
				return(false);
			}
			$failure = null;
			return($next);
		}
		if ($this->ledgerReadUnknown) {
			$failure = 'transport-failed';
			return(false);
		}
		$failure = null;
		return($this->ledgerKeys);
	}

	public function maxContentSize(&$failure = null)
	{
		$this->maxContentSizeCalls++;
		if (!is_int($this->daemonCap) || $this->daemonCap < 1) {
			$failure = 'commit-request-limit-invalid';
			return(false);
		}
		$failure = null;
		return($this->daemonCap);
	}

	public function preflightStage(RetrackersAnonymousStage $stage, $php, &$failure = null)
	{
		$this->preflightCalls[] = array($stage->candidate(), $stage->original(), $php);
		if (!$this->preflightResult) {
			$failure = $this->preflightFailure === null ? 'procfd-preflight-failed' : $this->preflightFailure;
			return(false);
		}
		$failure = null;
		return(true);
	}

	public function nativePreflightStage($hash, $tx, $incarnation,
		RetrackersAnonymousStage $stage, &$failure = null)
	{
		return($this->preflightStage($stage, null, $failure));
	}

	public function sourceScalarSnapshot($hash, &$failure = null)
	{
		$this->scalarReads++;
		if (count($this->scalarSequence) === 0) {
			$failure = 'target-absent';
			return(false);
		}
		$current = array_shift($this->scalarSequence);
		if ($current === false) {
			$failure = 'transport-failed';
			return(false);
		}
		$failure = null;
		return(array('family' => 2, 'values' => $current));
	}

	public function executeDirectMutation($method, array $params, &$failure = null)
	{
		$this->mutations[] = array('method' => $method, 'params' => $params);
		if (in_array($method, array('load.normal', 'load.start'), true) && isset($params[1])) {
			$kind = strpos(implode("\n", $params), 'v1:rollback-claim:') !== false ?
				'rollback-dispatch' : 'candidate-dispatch';
			$this->capabilityReads[$kind] = retrackersTestReadCapability($params[1]);
			if (!$this->loadResult) {
				$failure = 'transport-failed';
				return(false);
			}
		}
		if ($method === 'method.set_key') {
			$key = $params[1];
			if (isset($params[2])) {
				if (!in_array($key, $this->ledgerKeys, true)) {
					$this->ledgerKeys[] = $key;
				}
			} else {
				$idx = array_search($key, $this->ledgerKeys, true);
				if ($idx !== false) {
					array_splice($this->ledgerKeys, $idx, 1);
				}
			}
		}
		$failure = null;
		return(true);
	}

	public function executeMulticall(array $calls, &$failure = null)
	{
		$this->multicallHistory[] = $calls;
		foreach ($calls as $c) {
			$this->executeDirectMutation($c[0], $c[1], $failure);
		}
		$failure = null;
		return(true);
	}

	public function executeWorkerAdoptCallback(RetrackersWorkerAdoptCallback $callback,
		&$failure = null)
	{
		$this->adoptCallbacks[] = $callback;
		$body = implode("\n", $callback->params());
		preg_match('/wa:([0-9a-f]{32})/D', $body, $txMatch);
		preg_match('/wp:([0-9A-F]{40})/D', $body, $localMatch);
		$wa = 'wa:' . $txMatch[1];
		$wp = 'wp:' . $localMatch[1];
		$hasWa = in_array($wa, $this->ledgerKeys, true);
		$hasWp = in_array($wp, $this->ledgerKeys, true);
		if ($hasWa && !$hasWp) {
			$failure = null;
			return('ADOPTED');
		}
		if (!$hasWa && $hasWp && !in_array('ma:1', $this->ledgerKeys, true)) {
			$this->executeDirectMutation('method.set_key', array('rr.receipts.v1', $wa, '1'));
			$this->executeDirectMutation('method.set_key', array('rr.receipts.v1', $wp));
			$failure = null;
			return('ADOPTED');
		}
		$failure = null;
		return($hasWp && in_array('ma:1', $this->ledgerKeys, true) ? 'DENIED' : 'CHANGED');
	}

	public function executeHandoffRelease(RetrackersHandoffReleaseCallback $callback,
		&$failure = null)
	{
		$this->releaseCallbacks[] = $callback;
		$this->recoveryEvents[] = 'release';
		$result = count($this->releaseResults) > 0 ?
			array_shift($this->releaseResults) : 'RETRACKERS_HANDOFF_RELEASED';
		if ($result === false) {
			$failure = 'transport-failed';
			return(false);
		}
		$failure = null;
		return($result);
	}

	public function executeTerminalCleanup(RetrackersTerminalCleanupCallback $callback,
		&$failure = null)
	{
		$this->terminalCleanupCallbacks[] = $callback;
		$this->recoveryEvents[] = 'cleanup';
		$grammar = implode("\n", $callback->params());
		$positions = array();
		foreach ($this->ledgerKeys as $key) {
			$position = strpos($grammar, $key);
			if ($position !== false) {
				$positions[$key] = $position;
			}
		}
		asort($positions, SORT_NUMERIC);
		foreach (array_keys($positions) as $key) {
			$this->terminalCleanupOrder[] = $key;
		}
		$apply = count($this->terminalCleanupApply) > 0 ?
			array_shift($this->terminalCleanupApply) : true;
		if ($apply) {
			$this->ledgerKeys = array_values(array_filter($this->ledgerKeys,
				function ($key) use ($grammar) { return(strpos($grammar, $key) === false); }));
		}
		$residual = count($this->terminalCleanupResidualPrefixes) > 0 ?
			array_shift($this->terminalCleanupResidualPrefixes) : array();
		if (is_array($residual) && preg_match('/wa:([0-9a-f]{32})/', $grammar, $match) === 1) {
			foreach ($residual as $prefix) {
				$this->ledgerKeys[] = $prefix . ':' . $match[1];
			}
		}
		$result = count($this->terminalCleanupResults) > 0 ?
			array_shift($this->terminalCleanupResults) : 'RETRACKERS_TERMINAL_CLEANED';
		if ($result === false) {
			$failure = 'transport-failed';
			return(false);
		}
		$failure = null;
		return($result);
	}

	public function armPhase($phase, $tx, &$failure = null)
	{
		$this->phaseArmCalls[] = array($phase, $tx);
		$result = count($this->phaseArmResults) > 0 ?
			array_shift($this->phaseArmResults) : $this->phaseArmResult;
		if ($result === false) {
			$failure = 'transport-failed';
			return(false);
		}
		if ($result === 'RETRACKERS_PHASE_ARMED') {
			$this->ledgerKeys[] = $phase . ':' . $tx;
			if ($phase === 'la') {
				$this->ledgerKeys[] = 'v1:candidate-ready:' . $tx;
			} elseif ($phase === 'ra') {
				$this->ledgerKeys[] = 'v1:rollback-ready:' . $tx;
			}
		}
		$failure = null;
		return($result);
	}

	public function executeLoadDispatch(RetrackersLoadDispatchCallback $callback,
		&$failure = null)
	{
		$this->loadCallbacks[] = $callback;
		$result = count($this->loadCallbackResults) > 0 ?
			array_shift($this->loadCallbackResults) : 'RETRACKERS_LOAD_DISPATCHED';
		if ($result === false) {
			$failure = 'transport-failed';
			return(false);
		}
		$failure = null;
		return($result);
	}

	public function loadPhaseReceipts($phase, $tx, &$failure = null)
	{
		$this->loadPhaseReceiptReads++;
		if (count($this->loadPhaseReceiptSequence) > 0) {
			$result = array_shift($this->loadPhaseReceiptSequence);
			if ($result === false) {
				$failure = 'transport-failed';
				return(false);
			}
			$failure = null;
			return($result);
		}
		$map = $phase === 'la' ? array('lb', 'lf') : array('rb', 'rf');
		$failure = null;
		return(array(
			'begin' => in_array($map[0] . ':' . $tx, $this->ledgerKeys, true) ? '1' : '0',
			'fence' => in_array($map[1] . ':' . $tx, $this->ledgerKeys, true) ? '1' : '0',
		));
	}

	public function executeCandidateCleanup(RetrackersCandidateCleanupCallback $callback,
		&$failure = null)
	{
		$this->cleanupCallbacks[] = $callback;
		$result = count($this->cleanupCallbackResults) > 0 ?
			array_shift($this->cleanupCallbackResults) : 'RETRACKERS_CANDIDATE_ERASED';
		if ($result === false) {
			$failure = 'transport-failed';
			return(false);
		}
		$failure = null;
		return($result);
	}

	public function cleanupPhaseReceipts($tx, &$failure = null)
	{
		$this->cleanupReceiptReads++;
		if (count($this->cleanupReceiptSequence) > 0) {
			$result = array_shift($this->cleanupReceiptSequence);
			if ($result === false) {
				$failure = 'transport-failed';
				return(false);
			}
			$failure = null;
			return($result);
		}
		$failure = null;
		return(array(
			'begin' => in_array('cb:' . $tx, $this->ledgerKeys, true) ? '1' : '0',
			'done' => in_array('cd:' . $tx, $this->ledgerKeys, true) ? '1' : '0',
			'exit' => in_array('cx:' . $tx, $this->ledgerKeys, true) ? '1' : '0',
		));
	}

	public function executeOldGenerationCommit($callback, &$failure = null)
	{
		$this->oldCommitCalls[] = $callback;
		if (is_array($this->oldCommitLedgerKeys)) {
			$this->ledgerKeys = $this->oldCommitLedgerKeys;
		}
		if ($this->oldCommitResult === false) {
			$failure = $this->oldCommitFailure === null ? 'transport-failed' : $this->oldCommitFailure;
			return(false);
		}
		$failure = null;
		return($this->oldCommitResult);
	}

	public function oldGenerationCommitReceipts($tx, &$failure = null)
	{
		$this->oldCommitReceiptReads++;
		if (count($this->oldCommitReceiptSequence) > 0) {
			$result = array_shift($this->oldCommitReceiptSequence);
			$failure = $result === false ? 'transport-failed' : null;
			return($result);
		}
		if ($this->oldCommitReceiptFailure !== null) {
			$failure = $this->oldCommitReceiptFailure;
			return(false);
		}
		$failure = null;
		return(array(
			'begin' => in_array('eb:' . $tx, $this->ledgerKeys, true) ? '1' : '0',
			'done' => in_array('ed:' . $tx, $this->ledgerKeys, true) ? '1' : '0',
			'exit' => in_array('ex:' . $tx, $this->ledgerKeys, true) ? '1' : '0',
		));
	}

	public function hasDurableCandidateFenceObligation($tx, &$failure = null,
		$obligation = null)
	{
		$this->candidateFenceChecks++;
		$this->candidateFenceObligation = $obligation;
		if (!$this->candidateFenceReady) {
			$failure = 'candidate-fence-not-implemented';
			return(false);
		}
		return(parent::hasDurableCandidateFenceObligation($tx, $failure, $obligation));
	}
}
