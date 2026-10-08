<?php

require_once(__DIR__ . '/RetrackersTestStubs.php');
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_RECEIPT_POLL', 0.005);
define('RETRACKERS_PENDING_REPORT_AFTER', 0.2);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');

/**
 * A successful worker writes no recovery line (L3-N5): a pending receipt is
 * recorded quietly, and only a wait that outlives RETRACKERS_PENDING_REPORT_AFTER
 * is logged, once, without hiding the worker's later outcome.
 */
class PendingWaitLogTest extends TestCase
{
	private function await($adapter, $polls, $final = true)
	{
		$method = new ReflectionMethod('RetrackersRecoveryCoordinator', 'awaitRead');
		if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
		$reads = 0;
		$failure = null;
		$result = $method->invokeArgs(null, array(function (&$failure) use (&$reads, $polls, $final) {
			if (++$reads <= $polls) {
				$failure = 'handoff-release-pending';
				return(false);
			}
			$failure = $final === true ? null : $final;
			return($final === true ? true : false);
		}, $adapter, true, array('handoff-release-pending'), &$failure));
		return(array($result, $failure, $reads));
	}

	public function testABriefPendingWaitThatSucceedsLogsNothing()
	{
		FileUtil::$messages = array();
		$adapter = new RetrackersWorkerRpcAdapter(str_repeat('A', 40), 'alice');
		list($result, $failure, $reads) = $this->await($adapter, 3);
		$this->assertTrue($result === true && $reads === 4 && FileUtil::$messages === array() &&
			$adapter->recoveryFailure() === 'handoff-release-pending',
			'a delayed receipt that lands is recorded quietly and writes no recovery line');
	}

	public function testALongWaitIsLoggedOnceAndALaterOutcomeStillIs()
	{
		FileUtil::$messages = array();
		$adapter = new RetrackersWorkerRpcAdapter(str_repeat('A', 40), 'alice');
		list($result, $failure) = $this->await($adapter, 80, 'receipt-ledger-corrupt');
		$this->assertTrue($result === false && $failure === 'receipt-ledger-corrupt' && count(FileUtil::$messages) === 1 &&
			strpos(FileUtil::$messages[0], 'retrackers-recovery: ' . str_repeat('A', 40) . ' handoff-release-pending; still waiting after') === 0,
			'a wait beyond the threshold is visible exactly once');
		$adapter->recordRecoveryFailure($failure);
		$this->assertTrue(count(FileUtil::$messages) === 2 &&
			FileUtil::$messages[1] === 'retrackers-recovery: ' . str_repeat('A', 40) . ' receipt-ledger-corrupt',
			'the wait report does not use up the line of the worker outcome');
	}

	public function testOutcomeLinesNamedPendingKeepTheirOwnMeaning()
	{
		FileUtil::$messages = array();
		$adapter = new RetrackersWorkerRpcAdapter(str_repeat('A', 40), 'alice');
		$adapter->recordRecoveryFailure('native-capture-retry-pending');
		$this->assertTrue(FileUtil::$messages === array('retrackers-recovery: ' . str_repeat('A', 40) .
			' native-capture-retry-pending'), 'a deferral outcome is never phrased as a wait');
	}
}
