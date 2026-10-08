<?php

require_once(__DIR__ . '/RetrackersTestStubs.php');
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_DISPATCH_IMPORT_ONLY', true);
define('RETRACKERS_DEFER_READ_TIMEOUT', 0.04);
define('RETRACKERS_RESET_READ_TIMEOUT', 0.04);
define('RETRACKERS_PREFLIGHT_POLL', 0.001);
require_once(__DIR__ . '/../../../plugins/retrackers/dispatch.php');
require_once(__DIR__ . '/../../../plugins/retrackers/retryreset.php');
require_once(__DIR__ . '/RetrackersWireFake.php');

/** The operator path that brings an exhausted queue job back without the WebUI raw door. */
class RetryResetToolTest extends TestCase
{
	// One terminal job of alice: six counted failures retired the claim.
	private function terminal($claim = 'none')
	{
		$wire = new RetrackersWireFake();
		$hash = str_repeat('A', 40);
		$wire->add($hash, str_repeat('C', 40), 'alice', '1', $claim !== 'none');
		$q = substr($wire->torrents[$hash]['queued'], 68);
		$wire->torrents[$hash]['retry'] = 'r1:' . $q . ':6:0:source-not-ready';
		$wire->ledger = array_merge($wire->ledger, array('pv:' . str_repeat('1', 32),
			'pf:' . hash('sha256', 'alice') . ':' . str_repeat('2', 64)));
		return(array($wire, $hash, new RetrackersWireQueueAdapter($wire)));
	}

	private function main($adapter, array $args)
	{
		$out = fopen('php://memory', 'w+');
		$err = fopen('php://memory', 'w+');
		$code = RetrackersRetryReset::main($args, $adapter, $out, $err);
		rewind($out);
		rewind($err);
		return(array($code, stream_get_contents($out), stream_get_contents($err)));
	}

	public function testOnlyTheCallersExactTerminalQIsResettable()
	{
		foreach (array('none' => true, 'original' => true, 'held' => 'claim-not-resettable',
			'finalized' => 'claim-not-resettable', 'foreign' => 'queue-absent', 'unqueued' => 'queue-absent',
			'counted' => 'retry-not-terminal', 'invalid' => 'retry-state-invalid',
			'outage' => 'transport-unconfirmed') as $case => $expected) {
			list($wire, $hash, $adapter) = $this->terminal($case === 'original' ? 'original' : 'none');
			$q = substr($wire->torrents[$hash]['queued'], 68);
			if ($case === 'held') { $wire->begin($hash); $wire->torrents[$hash]['held'] = true; }
			if ($case === 'finalized') { $wire->begin($hash); $wire->finish($hash); }
			if ($case === 'foreign') $wire->torrents[$hash]['queued'] = 'q1:' . hash('sha256', 'bob') . ':' . $q;
			if ($case === 'unqueued') $wire->torrents[$hash]['queued'] = '';
			if ($case === 'counted') $wire->torrents[$hash]['retry'] = 'r1:' . $q . ':5:' . ($wire->now + 9) . ':capture-refused';
			if ($case === 'invalid') $wire->torrents[$hash]['retry'] = 'r1:' . $q . ':7:0:capture-refused';
			if ($case === 'outage') $wire->fail['d.custom'] = array_fill(0, 500, 'connect-failed');
			$failure = null;
			$job = RetrackersRetryReset::inspect($adapter, 'alice', $hash, $failure);
			$this->assertTrue($expected === true ? is_array($job) && $job['retry'] === 'r1:' . $q . ':6:0:source-not-ready' :
				$job === false && $failure === $expected, $case . ' job: ' . var_export($failure, true));
		}
	}

	public function testResetIsOneExactCasThatTheDispatcherThenServes()
	{
		list($wire, $hash, $adapter) = $this->terminal();
		$q = substr($wire->torrents[$hash]['queued'], 68);
		$this->assertTrue(RetrackersBoundedDispatcher::run($adapter, 'alice', $wire->now) === array('errors' => array()) &&
			$wire->started === array(), 'a terminal job is never started again on its own');
		list($code, $out) = $this->main($adapter, array('--user=alice', strtolower($hash)));
		$this->assertTrue($code === 0 && strpos($out, 'would reset ' . $hash . ' r1:' . $q . ':6:0:source-not-ready claim=none') === 0 &&
			!isset($wire->payloads['branch']), 'the default run only reads and shows the exact state it would clear');
		list($code, $out) = $this->main($adapter, array('--user=alice', $hash, '--apply'));
		$this->assertTrue($code === 0 && strpos($out, 'reset ' . $hash . '; the dispatcher requeues Q ' . $q) === 0 &&
			count($wire->payloads['branch']) === 1 && $wire->saves === 1 && $wire->torrents[$hash]['retry'] === '' &&
			$wire->torrents[$hash]['queued'] === 'q1:' . hash('sha256', 'alice') . ':' . $q,
			'apply clears only the retry state of the same Q with one CAS and saves the session');
		RetrackersBoundedDispatcher::run($adapter, 'alice', $wire->now);
		$this->assertTrue($wire->started === array($hash) && strncmp($wire->claimWire($hash), 'original:', 9) === 0,
			'the next dispatcher pass requeues the job and starts one worker');
	}

	public function testChangedLostAndUnansweredCasNeverResend()
	{
		list($wire, $hash, $adapter) = $this->terminal();
		$failure = null;
		$job = RetrackersRetryReset::inspect($adapter, 'alice', $hash, $failure);
		$wire->torrents[$hash]['retry'] = '';
		$this->assertTrue(!RetrackersRetryReset::apply($adapter, $job, $failure) && $failure === 'changed' &&
			count($wire->payloads['branch']) === 1, 'a state that changed after review is refused by the CAS itself');
		list($wire, $hash, $adapter) = $this->terminal();
		$job = RetrackersRetryReset::inspect($adapter, 'alice', $hash, $failure);
		$wire->lose['branch'] = 1;
		$this->assertTrue(RetrackersRetryReset::apply($adapter, $job, $failure) && count($wire->payloads['branch']) === 1,
			'a lost reply is reconciled by reading the cleared state back, never by a second CAS');
		list($wire, $hash, $adapter) = $this->terminal();
		$job = RetrackersRetryReset::inspect($adapter, 'alice', $hash, $failure);
		$wire->fail['branch'] = array('connect-failed');
		$this->assertTrue(!RetrackersRetryReset::apply($adapter, $job, $failure) && $failure === 'not-applied' &&
			count($wire->payloads['branch']) === 1 && $wire->torrents[$hash]['retry'] !== '',
			'an unanswered CAS that did not apply is reported as such and not resent');
	}

	public function testTheResetCasNamesTheExactQueueAndTerminalState()
	{
		$hash = str_repeat('A', 40);
		$queued = 'q1:' . hash('sha256', 'alice') . ':' . str_repeat('d', 32);
		$terminal = 'r1:' . str_repeat('d', 32) . ':6:0:capture-refused';
		$params = RetrackersLifecycleCallbacks::retryReset($hash, $queued, $terminal)->params();
		$this->assertTrue($params[0] === $hash && strpos($params[1], 'd.custom=retrackers-queued') !== false &&
			strpos($params[1], $queued) !== false && strpos($params[1], 'd.custom=retrackers-retry') !== false &&
			strpos($params[1], $terminal) !== false && strpos($params[2], 'd.custom.set=retrackers-retry,') !== false &&
			strpos($params[2], 'd.save_full_session=') !== false && strpos($params[2], 'retrackers-queued') === false,
			'one daemon callback compares the exact Q and terminal state, then clears only the retry state and saves');
		foreach (array('', 'r1:' . str_repeat('d', 32) . ':5:900:capture-refused', 'r1:' . str_repeat('e', 32) . ':6:0:capture-refused') as $state)
			$this->assertTrue(RetrackersLifecycleCallbacks::retryReset($hash, $queued, $state) === false,
				'no reset callback exists for a non-terminal or foreign state: ' . $state);
	}

	public function testListingAndUsageNeverChangeAnything()
	{
		list($wire, $hash, $adapter) = $this->terminal();
		$wire->add(str_repeat('B', 40), str_repeat('D', 40));
		// A second terminal job whose claim is held right now is still listed.
		$held = str_repeat('C', 40);
		$wire->add($held, str_repeat('E', 40));
		$wire->torrents[$held]['retry'] = 'r1:' . substr($wire->torrents[$held]['queued'], 68) . ':6:0:capture-refused';
		$wire->torrents[$held]['held'] = true;
		list($code, $out) = $this->main($adapter, array('--user=alice'));
		$this->assertTrue($code === 0 && strpos($out, $hash . ' r1:') === 0 && strpos($out, str_repeat('B', 40)) === false &&
			strpos($out, $held . ' r1:') !== false && strpos($out, 'not resettable now: claim-not-resettable') !== false &&
			strpos($out, '2 terminal job(s)') !== false && !isset($wire->payloads['branch']),
			'the listing names every terminal job, says which cannot be reset now, and changes nothing');
		foreach (array(array('--apply'), array($hash, 'extra'), array('--user=Alice'), array(str_repeat('A', 39))) as $args) {
			list($code, , $err) = $this->main($adapter, $args);
			$this->assertTrue($code === 2 && strpos($err, 'usage:') === 0, 'usage errors are refused: ' . implode(' ', $args));
		}
		list($code, , $err) = $this->main($adapter, array('--user=bob', $hash, '--apply'));
		$this->assertTrue($code === 1 && strpos($err, 'refused: queue-absent; nothing was changed') !== false &&
			!isset($wire->payloads['branch']), 'another profile cannot reset this Q');
	}
}
