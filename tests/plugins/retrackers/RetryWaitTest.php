<?php

require_once(__DIR__ . '/RetrackersTestStubs.php');
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_DISPATCH_IMPORT_ONLY', true);
define('RETRACKERS_DEFER_READ_TIMEOUT', 0.04);
define('RETRACKERS_PREFLIGHT_POLL', 0.001);
require_once(__DIR__ . '/../../../plugins/retrackers/dispatch.php');
require_once(__DIR__ . '/RetrackersWireFake.php');

/**
 * Temporary unreadiness is a wait, not a failed attempt (R3-RT-01).
 *
 * Each case drives the real dispatcher and the real pre-capture defer path over
 * one native queue model on a simulated clock: one dispatcher pass per second,
 * and each started worker either succeeds or fails with the scenario's reason.
 */
class RetryWaitTest extends TestCase
{
	/**
	 * $reasonAt maps elapsed seconds to the failure of an attempt started then,
	 * or null when the source is ready and the attempt succeeds.
	 */
	private function simulate($waits, callable $reasonAt, $limit, $waitReasons = null)
	{
		$wire = new RetrackersWireFake();
		$wire->waits = $waits;
		$hash = str_repeat('A', 40);
		$wire->add($hash, str_repeat('C', 40), 'alice', '1');
		$wire->ledger = array_merge($wire->ledger, array('pv:' . str_repeat('1', 32),
			'pf:' . hash('sha256', 'alice') . ':' . str_repeat('2', 64)));
		$queue = new RetrackersWireQueueAdapter($wire);
		$method = new ReflectionMethod('RetrackersRecoveryCoordinator', 'finishPreFenceFailure');
		if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
		FileUtil::$messages = array();
		$start = $wire->now;
		$attempts = array();
		$worst = 0;
		while ($wire->now - $start <= $limit && $wire->torrents[$hash]['queued'] !== '') {
			$errors = RetrackersBoundedDispatcher::run($queue, 'alice', $wire->now);
			$this->assertTrue($errors === array('errors' => array()), 'every simulated pass is clean');
			while ($wire->started) {
				array_shift($wire->started);
				$reason = $reasonAt($wire->now - $start);
				$attempts[] = array($wire->now - $start, $reason);
				if ($reason === null) {
					$wire->finish($hash);
					continue;
				}
				$claim = $wire->torrents[$hash]['claim'];
				$worker = new RetrackersWireWorkerAdapter($wire, $hash, 'alice');
				// Each worker is its own process; only this store outlives it.
				if ($waitReasons !== null) $worker->waitReasons = $waitReasons;
				$method->invoke(null, $reason, $claim['tx'], $hash, $wire->marker($hash), $claim['local_id'], $worker);
				$state = RetrackersQueueRetry::parse($wire->torrents[$hash]['retry'], substr($wire->torrents[$hash]['queued'], 68));
				$worst = max($worst, $state['attempt']);
			}
			$wire->now++;
		}
		return(array('done' => $wire->torrents[$hash]['queued'] === '', 'retry' => $wire->torrents[$hash]['retry'],
			'attempts' => $attempts, 'worst' => $worst, 'log' => FileUtil::$messages,
			'elapsed' => $wire->now - $start));
	}

	public function testPauseLongerThanTheOldBudgetWaitsAndCompletesAfterResume()
	{
		// The old budget ended about 98 s into a pause (lab-rec); this one lasts ten minutes.
		$result = $this->simulate(true, function ($elapsed) {
			return($elapsed < 600 ? 'lifecycle-unsupported' : null);
		}, 1200);
		$last = end($result['attempts']);
		$this->assertTrue($result['done'] && $result['worst'] === 1 && $last[1] === null && $last[0] >= 600 &&
			$last[0] < 600 + RETRACKERS_RETRY_WAIT_NOT_READY,
			'a paused source keeps count 1, never turns terminal and is served within one wait after resume');
		$this->assertTrue(count($result['attempts']) === 3,
			'a pause costs one attempt per wait interval, not one per dispatcher tick');
		$this->assertTrue(count($result['log']) === 1 && strpos($result['log'][0], ' lifecycle-unsupported') !== false,
			'one line marks the start of the wait; repeated identical waits stay quiet');
	}

	public function testHashCheckLongerThanFourMinutesCompletesAfterItEnds()
	{
		$result = $this->simulate(true, function ($elapsed) {
			return($elapsed < 300 ? 'initial-hashing-pending' : null);
		}, 900);
		$last = end($result['attempts']);
		$this->assertTrue($result['done'] && $result['worst'] === 1 && $last[0] >= 300 &&
			$last[0] < 300 + RETRACKERS_RETRY_WAIT_HASHING,
			'a hash check past the old ~256 s terminal point completes one hashing wait after it ends');
	}

	public function testWaitsDoNotSpendTheCountedBudget()
	{
		$result = $this->simulate(true, function ($elapsed) {
			return($elapsed < 900 ? 'lifecycle-unsupported' : 'procfd-preflight-failed');
		}, 2000);
		$counted = array_filter($result['attempts'], function ($attempt) {
			return($attempt[1] === 'procfd-preflight-failed');
		});
		$this->assertTrue(!$result['done'] && preg_match('/:6:0:preflight-refused$/D', $result['retry']) === 1 &&
			count($counted) === 5,
			'after a long wait the job still has its counted attempts and then terminates at the sixth count');
	}

	public function testCountedFailuresStillTerminateAtSix()
	{
		$result = $this->simulate(true, function ($elapsed) { return('procfd-preflight-failed'); }, 600);
		$this->assertTrue(!$result['done'] && count($result['attempts']) === 6 &&
			preg_match('/:6:0:preflight-refused$/D', $result['retry']) === 1 &&
			strpos(end($result['log']), 'queue-retry-exhausted') !== false,
			'six counted failures end in the visible terminal state');
	}

	public function testOldNativeKeepsTheCountedRule()
	{
		$result = $this->simulate(false, function ($elapsed) {
			return($elapsed < 600 ? 'lifecycle-unsupported' : null);
		}, 1200);
		$this->assertTrue(!$result['done'] && count($result['attempts']) === 6 &&
			preg_match('/:6:0:source-not-ready$/D', $result['retry']) === 1,
			'without system.retrackers.retry_waits every attempt advances the count, as before');
	}

	public function testAnUnconfirmedCapabilityListKeepsTheCountedRuleOnce()
	{
		$wire = new RetrackersWireFake();
		$wire->waits = true;
		$hash = str_repeat('A', 40);
		$wire->add($hash, str_repeat('C', 40));
		$wire->fail['system.listMethods'] = array_fill(0, 500, 'connect-failed');
		$adapter = new RetrackersWireWorkerAdapter($wire, $hash, 'alice');
		$claim = $wire->torrents[$hash]['claim'];
		$failure = null;
		$outcome = null;
		$this->assertTrue($adapter->nativeDeferUncaptured($hash, $claim['tx'], $claim['local_id'], $wire->marker($hash),
			null, 'lifecycle-unsupported', $failure, $outcome) && $outcome === 'deferred' &&
			$wire->torrents[$hash]['retry'] === 'r1:' . $claim['queue_id'] . ':1:' . ($wire->now + 2) . ':source-not-ready',
			'an unanswered method list never sends a same-count CAS that an old daemon would reject');
		$wire->fail['system.listMethods'] = array();
		$this->assertTrue($adapter->nativeRetryWaits(), 'an unconfirmed list is not cached as absent');
	}

	public function testOnlyAnAdvertisedOneEnablesWaits()
	{
		foreach (array('1' => true, '0' => false) as $value => $expected) {
			$wire = new RetrackersWireFake();
			$wire->waits = true;
			$wire->waitsValue = (string)$value;
			$adapter = new RetrackersWireWorkerAdapter($wire);
			$this->assertTrue($adapter->nativeRetryWaits() === $expected && $adapter->nativeRetryWaits() === $expected &&
				count(array_keys($wire->calls, 'system.retrackers.retry_waits')) === 1,
				'the listed capability enables waits only when it answers 1, and is read once (' . $value . ')');
		}
		$wire = new RetrackersWireFake();
		$wire->waits = true;
		$wire->fail['system.retrackers.retry_waits'] = array_fill(0, 500, 'read-timeout');
		$adapter = new RetrackersWireWorkerAdapter($wire);
		$this->assertTrue(!$adapter->nativeRetryWaits(), 'an unanswered capability value keeps the counted rule');
		$wire->fail['system.retrackers.retry_waits'] = array();
		$this->assertTrue($adapter->nativeRetryWaits(), 'and is read again on the next deferral');
	}

	// R4-RT-05: the retry state keeps only the category, so the worker
	// remembers which detailed reason it logged last for this job.
	public function testAChangedReasonInsideOneWaitIsLoggedOnce()
	{
		$result = $this->simulate(true, function ($elapsed) {
			if ($elapsed < 200) return('initial-hashing-pending');
			return($elapsed < 1000 ? 'lifecycle-unsupported' : null);
		}, 2000, new RetrackersMemoryCache());
		$lines = array_values(array_filter($result['log'], function ($line) {
			return(strpos($line, 'retrackers-recovery: ') === 0);
		}));
		$this->assertTrue($result['done'] && $result['worst'] === 1 && count($result['attempts']) >= 5,
			'the job waits through both states and completes');
		$this->assertTrue(count($lines) === 2 && strpos($lines[0], ' initial-hashing-pending') !== false &&
			strpos($lines[1], ' lifecycle-unsupported') !== false,
			'one line when the wait starts and one when its detailed reason changes: ' . json_encode($result['log']));
		$result = $this->simulate(true, function ($elapsed) {
			return($elapsed < 600 ? 'lifecycle-unsupported' : null);
		}, 1200, new RetrackersMemoryCache());
		$this->assertTrue(count($result['log']) === 1, 'the same reason again stays quiet with the store too');
	}

	// rCache merges a record that another worker stored meanwhile: that worker's
	// jobs stay, this worker's own entry wins, and the newest LIMIT are kept.
	public function testWaitReasonRecordsMergeConcurrentWorkers()
	{
		$mine = new RetrackersWaitReasons();
		$mine->last = array('A:1' => 'initial-hashing-pending', 'B:2' => 'old');
		$newer = new RetrackersWaitReasons();
		$newer->last = array('B:2' => 'lifecycle-unsupported', 'C:3' => 'ownership-mismatch', 'A:1' => 'stale');
		$this->assertTrue($mine->merge($newer, array('A:1')) === true && $mine->last === array(
			'B:2' => 'lifecycle-unsupported', 'C:3' => 'ownership-mismatch', 'A:1' => 'initial-hashing-pending'),
			'the other worker\'s entries survive and this job\'s reason is the one just logged');
		$many = new RetrackersWaitReasons();
		for ($i = 0; $i < RetrackersWaitReasons::LIMIT + 5; $i++) $many->last['H:' . $i] = 'x';
		$own = new RetrackersWaitReasons();
		$own->last = array('mine' => 'y');
		$own->merge($many, array('mine'));
		$this->assertTrue(count($own->last) === RetrackersWaitReasons::LIMIT && end($own->last) === 'y' &&
			!isset($own->last['H:0']), 'the merged record stays bounded and keeps the newest entries');
	}
}
