<?php

require_once(__DIR__ . '/../../php/TestCase.php');
define('RETRACKERS_IMPORT_ONLY', true);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');

if (!class_exists('rTorrent', false)) {
	class rTorrent
	{
		public static function quoteCommandArg($value)
		{
			return('"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $value) . '"');
		}
	}
}

class RetrackersDedupRpcTestAdapter extends RetrackersWorkerRpcAdapter
{
	public $calls = array();
	public $next = false;
	public $nextFailure = null;

	protected function send($payload, $plan, &$failure = null, $historical = false)
	{
		$this->calls[] = array($payload, $plan);
		$failure = $this->nextFailure;
		return($this->next);
	}
}

class RetrackersUpdateDedupTest extends TestCase
{
	private function sealedCallback($class, $wire)
	{
		$reflection = new ReflectionClass($class);
		$callback = $reflection->newInstanceWithoutConstructor();
		$property = $reflection->getProperty('wire');
		if (PHP_VERSION_ID < 80100) {
			$property->setAccessible(true);
		}
		$property->setValue($callback, $wire);
		return($callback);
	}

	public function testSharedCasFragmentsKeepExactEscapingAndDryLimit()
	{
		$this->assertTrue(trait_exists('RetrackersCasCommandBuilder'),
			'the two CAS callbacks use one fragment builder');
		if (!trait_exists('RetrackersCasCommandBuilder')) {
			return;
		}
		foreach (array('RetrackersOldGenerationCommitCallback',
			'RetrackersCandidateCleanupCallback') as $callbackClass) {
			$this->assertTrue(in_array('RetrackersCasCommandBuilder',
				class_uses($callbackClass), true), $callbackClass . ' uses shared CAS fragments');
			$integerMethod = new ReflectionMethod($callbackClass, 'integerEquals');
			$stringMethod = new ReflectionMethod($callbackClass, 'stringEquals');
			if (PHP_VERSION_ID < 80100) {
				$integerMethod->setAccessible(true);
				$stringMethod->setAccessible(true);
			}
			foreach (array(false, true) as $materialize) {
				$integer = $integerMethod->invoke(null,
					't.group=', '12', $materialize, 128);
				$string = $stringMethod->invoke(null,
					't.url=', 'A&<"\\', $materialize, 128);
				$this->assertTrue($integer instanceof RetrackersCommandFragment &&
					$string instanceof RetrackersCommandFragment,
					'CAS fragments build in dry and materialized passes');
				if (!($integer instanceof RetrackersCommandFragment) ||
					!($string instanceof RetrackersCommandFragment)) {
					continue;
				}
				$this->assertSame(strlen('equal="t.group=","value=12"'), $integer->length(),
					'integer predicate length is exact');
				$this->assertSame(36,
					$string->length(), 'escaped string predicate length is exact');
				if ($materialize) {
					$this->assertSame('equal="t.group=","value=12"', $integer->text(),
					'integer predicate wire is unchanged');
					$this->assertSame(
						'657175616c3d22742e75726c3d222c226361743d5c2241263c5c5c5c225c5c5c5c5c2222',
						bin2hex($string->text()), 'string predicate wire is unchanged');
				} else {
					$this->assertSame(null, $integer->text(), 'dry pass never materializes');
				}
			}
			$this->assertSame(false, $integerMethod->invoke(null,
				't.group=', '12', true, 8),
				'materialized fragment refuses too-small cap');
		}
	}

	public function testThreePhaseReceiptsKeepKeysWireAndFailureReasons()
	{
		$adapter = new RetrackersDedupRpcTestAdapter();
		$tx = str_repeat('a', 32);
		foreach (array(
			array('cleanupPhaseReceipts', array('cb', 'cd', 'cx')),
			array('oldGenerationCommitReceipts', array('eb', 'ed', 'ex')),
		) as $case) {
			$keys = array();
			foreach ($case[1] as $prefix) {
				$keys[] = $prefix . ':' . $tx;
			}
			$calls = array(
				array('method.list_keys', array('', 'rr.receipts.v1')),
				array('method.get', array('', 'rr.receipts.v1')),
			);
			foreach ($keys as $key) {
				$calls[] = array('method.has_key', array('', 'rr.receipts.v1', $key));
			}
			$adapter->next = array('ok' => true, 'value' => array(
				array('ok' => true, 'value' => ''),
				array('ok' => true, 'value' => ''),
				array('ok' => true, 'value' => '1'),
				array('ok' => true, 'value' => '0'),
				array('ok' => true, 'value' => '1'),
			));
			$failure = 'stale';
			$result = $adapter->{$case[0]}($tx, $failure);
			$this->assertSame(array('begin' => '1', 'done' => '0', 'exit' => '1'),
				$result, $case[0] . ' maps the three slots');
			$this->assertSame(null, $failure, $case[0] . ' clears stale failure');
			$this->assertSame(array(retrackersBuildSystemMulticallRequest($calls),
				retrackersRestrictedPlanLedger($keys)), end($adapter->calls),
				$case[0] . ' keeps exact five-call wire and plan');
			$adapter->next['value'][4]['value'] = '2';
			$this->assertSame(false, $adapter->{$case[0]}($tx, $failure),
				$case[0] . ' refuses a nonboolean receipt');
			$this->assertSame('receipt-ledger-corrupt', $failure,
				$case[0] . ' classifies corrupt receipts');
			$adapter->next['value'][4]['value'] = '';
			$this->assertSame(false, $adapter->{$case[0]}($tx, $failure),
				$case[0] . ' refuses empty receipt value');
			$this->assertSame('receipt-ledger-corrupt', $failure,
				$case[0] . ' classifies empty receipt value');
			$adapter->next = array('ok' => false);
			$adapter->nextFailure = 'rpc-fault';
			$this->assertSame(false, $adapter->{$case[0]}($tx, $failure),
				$case[0] . ' refuses RPC fault');
			$this->assertSame('rpc-fault', $failure,
				$case[0] . ' preserves classified send fault');
			$adapter->nextFailure = null;
			$before = count($adapter->calls);
			$this->assertSame(false, $adapter->{$case[0]}(strtoupper($tx), $failure),
				$case[0] . ' rejects noncanonical tx');
			$this->assertSame('invalid-request', $failure,
				$case[0] . ' classifies malformed tx');
			$this->assertSame($before, count($adapter->calls),
				$case[0] . ' does not send malformed tx');
		}
	}

	public function testTypedCallbacksSendSealedWireAndDecodeStringFailures()
	{
		$adapter = new RetrackersDedupRpcTestAdapter();
		$adapter->setFamily(2);
		foreach (array(
			array('executeOldGenerationCommit', 'RetrackersOldGenerationCommitCallback'),
			array('executeLoadDispatch', 'RetrackersLoadDispatchCallback'),
			array('executeCandidateCleanup', 'RetrackersCandidateCleanupCallback'),
		) as $case) {
			$wire = 'sealed:' . $case[0];
			$callback = $this->sealedCallback($case[1], $wire);
			$adapter->next = array('ok' => true, 'value' => 'DONE');
			$failure = 'stale';
			$this->assertSame('DONE', $adapter->{$case[0]}($callback, $failure),
				$case[0] . ' returns direct string');
			$this->assertSame(null, $failure, $case[0] . ' clears stale failure');
			$this->assertSame(array($wire, $callback->responsePlan(2)),
				end($adapter->calls), $case[0] . ' sends checked wire, not rebuilt params');
			$adapter->next = false;
			$adapter->nextFailure = 'transport-closed';
			$this->assertSame(false, $adapter->{$case[0]}($callback, $failure),
				$case[0] . ' refuses transport failure');
			$this->assertSame('transport-closed', $failure,
				$case[0] . ' preserves transport failure');
			$adapter->next = array('ok' => true, 'value' => 0);
			$adapter->nextFailure = 'decode-failed';
			$failure = 'decode-failed';
			$this->assertSame(false, $adapter->{$case[0]}($callback, $failure),
				$case[0] . ' refuses wrong scalar type');
			$this->assertSame('decode-failed', $failure,
				$case[0] . ' retains transport decode failure');
			$adapter->nextFailure = null;
			$adapter->next = array('ok' => false);
			$this->assertSame(false, $adapter->{$case[0]}($callback, $failure),
				$case[0] . ' refuses RPC fault');
			$this->assertSame('rpc-fault', $failure, $case[0] . ' classifies RPC fault');
		}
	}
}
