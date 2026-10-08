<?php

require_once(__DIR__ . '/RetrackersTestStubs.php');
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_PREFLIGHT_TIMEOUT', 0.05);
define('RETRACKERS_PREFLIGHT_POLL', 0.001);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');
require_once(__DIR__ . '/RetrackersBindingSample.php');

class InitInFlightSamples extends RetrackersLifecycleRpcAdapter
{
	public $samples;
	public $reads = 0;
	// What the worker probe answers: 'live', 'abandoned' or 'unknown'.
	public $workers = 'live';
	public $probed = array();

	public function __construct(array $samples)
	{
		$this->samples = $samples;
		$this->family = 2;
	}

	public function historicalBindingSample(&$failure = null)
	{
		$this->reads++;
		$failure = null;
		return(count($this->samples) > 1 ? array_shift($this->samples) : $this->samples[0]);
	}

	public function transactionWorkers(array $transactions)
	{
		$this->probed[] = array('after_reads' => $this->reads, 'transactions' => $transactions);
		return($this->workers);
	}
}

/**
 * A burst of queued jobs runs native same-hash transactions (R3-RT-02).
 *
 * Their v2 candidate markers are work in flight, not retained recovery: an
 * identical init changes nothing and passes, a lifecycle change waits a
 * bounded time and then refuses with an accurate classification.
 */
class InitInFlightTest extends TestCase
{
	const FUNCTIONAL = 'functional-alice-exact-bytes';

	private static function inFlight()
	{
		$ready = 'v2:candidate-ready:' . str_repeat('a', 32);
		$original = 'v2:original:1:' . str_repeat('E', 40) . ':' . hash('sha256', 'alice') . ':' . str_repeat('c', 32);
		return(RetrackersBindingSample::idle('alice', self::FUNCTIONAL, array(
			array(str_repeat('A', 40), str_repeat('B', 40), $ready, $ready),
			array(str_repeat('C', 40), str_repeat('D', 40), 'v2:candidate-claim:' . str_repeat('b', 32), ''),
			array(str_repeat('F', 40), str_repeat('E', 40), $original, $original))));
	}

	public function testNativeCandidateMarkersAreInFlightNotRetainedRecovery()
	{
		$classifier = new RetrackersHistoricalBindingClassifier();
		$result = $classifier->classify(self::inFlight(), 'alice', self::FUNCTIONAL);
		$this->assertTrue($result['status'] === 'valid' && $result['decision']['recovery_in_flight'] === true,
			'native candidate claim and ready markers beside a pending original are a transaction in flight');
		$clean = $classifier->classify(RetrackersBindingSample::idle('alice', self::FUNCTIONAL), 'alice', self::FUNCTIONAL);
		$this->assertTrue($clean['status'] === 'valid' && $clean['decision']['recovery_in_flight'] === false,
			'an unmarked idle profile has nothing in flight');
		$ready = 'v2:candidate-ready:' . str_repeat('a', 32);
		$claim = 'v2:candidate-claim:' . str_repeat('b', 32);
		// Native writes only claim/'' and ready/ready (R4-RT-08).
		foreach (array(array('v1:candidate-ready:' . str_repeat('a', 32), 'v1:candidate-ready:' . str_repeat('a', 32)),
			array($ready, 'v2:candidate-ready:' . str_repeat('f', 32)), array($ready, 'garbage'),
			array('v2:candidate-claim:' . str_repeat('A', 32), ''), array($ready, ''), array($claim, $claim)) as $markers) {
			$sample = RetrackersBindingSample::idle('alice', self::FUNCTIONAL,
				array(array(str_repeat('A', 40), str_repeat('B', 40), $markers[0], $markers[1])));
			$this->assertTrue($classifier->classify($sample, 'alice', self::FUNCTIONAL) === array('status' => 'recovery-pending'),
				'any other marker stays retained recovery: ' . $markers[0] . ' / ' . $markers[1]);
		}
	}

	public function testIdenticalInitPassesWhileTransactionsRun()
	{
		$adapter = new InitInFlightSamples(array(self::inFlight()));
		$calls = 0;
		$seen = null;
		$outcome = (new RetrackersStableHistoricalBinding($adapter))->consumePendingInit('alice', self::FUNCTIONAL,
			new RetrackersHistoricalLedgerAttestation(true, true, true, true),
			function ($decision) use (&$calls, &$seen) { $calls++; $seen = $decision; return(true); });
		$this->assertTrue($outcome === array('ok' => true, 'consumer_result' => true) && $calls === 1 &&
			$adapter->reads === 2 && $seen['recovery_in_flight'] === true &&
			RetrackersHistoricalBindingClassifier::identicalInstalledProfile($seen, 'alice', self::FUNCTIONAL),
			'an identical init reaches its no-mutation acceptance at once');
	}

	public function testLifecycleChangeWaitsBoundedThenRefusesAccurately()
	{
		$binding = function ($adapter) { return(new RetrackersStableHistoricalBinding($adapter)); };
		$attestation = new RetrackersHistoricalLedgerAttestation(true, true, true, true);
		$changed = 'functional-alice-changed-bytes';
		$clean = RetrackersBindingSample::idle('alice', self::FUNCTIONAL);
		$adapter = new InitInFlightSamples(array(self::inFlight(), self::inFlight(), $clean, $clean));
		$calls = 0;
		$outcome = $binding($adapter)->consumePendingInit('alice', $changed, $attestation,
			function ($decision) use (&$calls) { $calls++; return(true); });
		$this->assertTrue($outcome['ok'] === true && $calls === 1 && $adapter->reads === 4,
			'a settings change waits for the transaction and then runs its consumer once');
		$adapter = new InitInFlightSamples(array(self::inFlight()));
		$calls = 0;
		$outcome = $binding($adapter)->consumePendingInit('alice', $changed, $attestation,
			function ($decision) use (&$calls) { $calls++; return(true); });
		$this->assertTrue($outcome === array('ok' => false, 'failure' => 'recovery-in-flight') && $calls === 0 &&
			$adapter->reads > 2, 'a transaction that outlives the bounded wait refuses without running the consumer');
		$adapter = new InitInFlightSamples(array(self::inFlight()));
		$outcome = $binding($adapter)->consume('alice', null, $attestation, function ($decision) { return(true); });
		$this->assertTrue($outcome === array('ok' => false, 'failure' => 'recovery-in-flight') && $adapter->reads === 2,
			'teardown refuses while a transaction runs');
		$message = retrackersLifecycleDiagnosticMessage('init', 'recovery-in-flight');
		$this->assertTrue(strpos($message, 'recovery-in-flight; queued retrackers jobs are being applied') === 0 &&
			strpos($message, 'retained marker') === false, 'the refusal does not blame a retained marker');
	}

	public function testRetainedMarkerReadbackHasNoUndefinedDecision()
	{
		$retained = RetrackersBindingSample::idle('alice', self::FUNCTIONAL, array(array(str_repeat('A', 40),
			str_repeat('B', 40), 'v1:candidate-ready:' . str_repeat('a', 32), 'v1:candidate-ready:' . str_repeat('a', 32))));
		$adapter = new InitInFlightSamples(array($retained));
		$failure = null;
		$this->assertTrue($adapter->coherentLifecycleReadback('alice', self::FUNCTIONAL, $failure) === false &&
			$failure === 'profile-binding-unstable',
			'a readback of retained markers fails as unstable without reading an absent decision');
		$adapter = new InitInFlightSamples(array(self::inFlight()));
		$decision = $adapter->coherentLifecycleReadback('alice', self::FUNCTIONAL, $failure);
		$this->assertTrue(is_array($decision) && $decision['phase'] === 'IDLE_CURRENT' && $failure === null,
			'a readback during a native transaction still attests the exact lifecycle state');
	}

	private static function changedInit($adapter, $cache = null, $clock = null)
	{
		$calls = 0;
		$outcome = (new RetrackersStableHistoricalBinding($adapter, $cache, $clock))->consumePendingInit('alice',
			'functional-alice-changed-bytes', new RetrackersHistoricalLedgerAttestation(true, true, true, true),
			function ($decision) use (&$calls) { $calls++; return(true); });
		return(array($outcome, $calls));
	}

	// R4-RT-01: a worker killed after the candidate load leaves ready/ready and a
	// held claim until rTorrent's worker-exit recovery rolls the transaction
	// back (r3fix/native 68dab7c; a restart on older builds). Until then it is
	// retained recovery, not work in flight.
	public function testAnAbandonedTransactionIsRetainedRecoveryWithoutTheWait()
	{
		$adapter = new InitInFlightSamples(array(self::inFlight()));
		$adapter->workers = 'abandoned';
		list($outcome, $calls) = self::changedInit($adapter);
		$this->assertTrue($outcome === array('ok' => false, 'failure' => 'marked-recovery-pending') && $calls === 0 &&
			$adapter->reads === 2, 'a settings change refuses at once with the retained-marker classification');
		$this->assertTrue(count($adapter->probed) === 1 && $adapter->probed[0]['after_reads'] === 1 &&
			$adapter->probed[0]['transactions'] === array(
				array('hash' => str_repeat('A', 40), 'tx' => str_repeat('a', 32)),
				array('hash' => str_repeat('C', 40), 'tx' => str_repeat('b', 32))),
			'the probe runs between the two samples for exactly the native candidate transactions');
		$adapter = new InitInFlightSamples(array(self::inFlight()));
		$adapter->workers = 'abandoned';
		$calls = 0;
		$outcome = (new RetrackersStableHistoricalBinding($adapter))->consumePendingInit('alice', self::FUNCTIONAL,
			new RetrackersHistoricalLedgerAttestation(true, true, true, true),
			function ($decision) use (&$calls) { $calls++; return(true); });
		$this->assertTrue($outcome === array('ok' => false, 'failure' => 'marked-recovery-pending') && $calls === 0,
			'an identical init no longer treats an abandoned transaction as one that will finish');
		$adapter = new InitInFlightSamples(array(self::inFlight()));
		$adapter->workers = 'abandoned';
		$outcome = (new RetrackersStableHistoricalBinding($adapter))->consume('alice', null,
			new RetrackersHistoricalLedgerAttestation(true, true, true, true), function ($decision) { return(true); });
		$this->assertTrue($outcome === array('ok' => false, 'failure' => 'marked-recovery-pending'),
			'teardown names the retained recovery as well');
		$message = retrackersLifecycleDiagnosticMessage('init', 'marked-recovery-pending');
		$this->assertTrue(strpos($message, 'inspect the retained marker') !== false,
			'the log line points at the retained marker');
	}

	public function testALiveWorkerKeepsTheBoundedWait()
	{
		$adapter = new InitInFlightSamples(array(self::inFlight()));
		list($outcome, $calls) = self::changedInit($adapter);
		$this->assertTrue($outcome === array('ok' => false, 'failure' => 'recovery-in-flight') && $calls === 0 &&
			$adapter->reads > 2 && count($adapter->probed) * 2 === $adapter->reads,
			'with its worker alive the transaction is in flight; each attempt probes once');
	}

	public function testAWorkerThatFinishesAroundTheProbeIsNotAbandoned()
	{
		$clean = RetrackersBindingSample::idle('alice', self::FUNCTIONAL);
		$adapter = new InitInFlightSamples(array(self::inFlight(), $clean, $clean, $clean));
		$adapter->workers = 'abandoned';
		list($outcome, $calls) = self::changedInit($adapter);
		$this->assertTrue($outcome['ok'] === true && $calls === 1 && $adapter->reads === 4 &&
			count($adapter->probed) === 1,
			'markers gone by the second sample are unstable, then clean; nothing is called abandoned');
	}

	public function testUnseenWorkersFallBackToAStaleBound()
	{
		$cache = new RetrackersMemoryCache();
		$now = 1000000;
		$clock = function () use (&$now) { return($now); };
		$unknown = function () {
			$adapter = new InitInFlightSamples(array(self::inFlight()));
			$adapter->workers = 'unknown';
			return($adapter);
		};
		list($outcome) = self::changedInit($unknown(), $cache, $clock);
		$this->assertTrue($outcome === array('ok' => false, 'failure' => 'recovery-in-flight'),
			'a first observation without a process view is in flight');
		$now += (int)RETRACKERS_IN_FLIGHT_STALE - 1;
		list($outcome) = self::changedInit($unknown(), $cache, $clock);
		$this->assertTrue($outcome === array('ok' => false, 'failure' => 'recovery-in-flight'),
			'it stays in flight inside the bound');
		$now += 1;
		$adapter = $unknown();
		list($outcome) = self::changedInit($adapter, $cache, $clock);
		$this->assertTrue($outcome === array('ok' => false, 'failure' => 'marked-recovery-pending') &&
			$adapter->reads === 2, 'the same transactions observed for the whole bound are retained recovery at once');
		$fresh = 'v2:candidate-ready:' . str_repeat('9', 32);
		$adapter = new InitInFlightSamples(array(RetrackersBindingSample::idle('alice', self::FUNCTIONAL,
			array(array(str_repeat('A', 40), str_repeat('B', 40), $fresh, $fresh)))));
		$adapter->workers = 'unknown';
		list($outcome) = self::changedInit($adapter, $cache, $clock);
		$this->assertTrue($outcome === array('ok' => false, 'failure' => 'recovery-in-flight'),
			'a new transaction starts its own bound');
		list($outcome) = self::changedInit($unknown(), null, $clock);
		$this->assertTrue($outcome === array('ok' => false, 'failure' => 'recovery-in-flight'),
			'without a place to remember the observation the transaction keeps the bounded wait');
	}
}
