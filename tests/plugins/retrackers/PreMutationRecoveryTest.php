<?php

require_once(__DIR__ . '/RetrackersTestStubs.php');
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_PREFLIGHT_TIMEOUT', 0.02);
define('RETRACKERS_DEFER_READ_TIMEOUT', 0.04);
define('RETRACKERS_HASH_WAIT_TIMEOUT', 0.02);
define('RETRACKERS_PREFLIGHT_POLL', 0.001);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');
require_once(__DIR__ . '/RetrackersWireFake.php');

class PreMutationRecoveryAdapter extends RetrackersWorkerRpcAdapter
{
    public $listCalls = 0;
    public $transientCalls = 0;
    public $finishCalls = 0;
    public $receiptFailures = 0;
    public $ownerFailures = 0;
    public $incarnationFails = false;
    public $reason = null;
    public $wire;
    public function __construct() {
        $this->family = 2;
        // The queued original these cases run: H=A, L=B, T=c, I=e, Q=f.
        $this->wire = new RetrackersWireFake();
        $this->wire->addExact(str_repeat('A', 40), str_repeat('B', 40), str_repeat('c', 32),
            str_repeat('e', 32), str_repeat('f', 32));
    }
    public function claimCalls() { return count(array_keys($this->wire->calls, 'system.retrackers.original_claim')); }
    public function cancelCalls() { return count(array_keys($this->wire->calls, 'd.retrackers.defer_original')); }
    public function queuePending() { return $this->wire->torrents[str_repeat('A', 40)]['queued'] !== ''; }
    protected function send($payload, $plan, &$failure = null, $historical = false) {
        $failure = null;
        if (strpos($payload, '<methodName>system.listMethods</methodName>') !== false) {
            $this->listCalls++;
            if ($this->listCalls <= $this->transientCalls) {
                $failure = 'closed-before-headers';
                return false;
            }
            return array('ok' => true, 'family' => 2, 'value' => array(
                'd.retrackers.begin_original', 'd.retrackers.finish_original',
                'd.incarnation', 'system.retrackers.finalized'));
        }
        if (strpos($payload, '<methodName>d.incarnation</methodName>') !== false) {
            if ($this->incarnationFails) { $failure = 'connect-failed'; return false; }
            return array('ok' => true, 'family' => 2, 'value' => str_repeat('e', 32));
        }
        if (strpos($payload, '<methodName>d.retrackers.finish_original</methodName>') !== false) {
            $this->finishCalls++;
            return array('ok' => true, 'family' => 2, 'value' => 'finalized');
        }
        if (strpos($payload, '<methodName>d.incarnation</methodName>') === false &&
            strpos($payload, '<methodName>system.retrackers.finalized</methodName>') === false) {
            $answer = $this->wire->answer($payload, $failure);
            if ($answer !== null) return $answer;
        }
        if (strpos($payload, '<methodName>system.retrackers.finalized</methodName>') !== false) {
            if ($this->finishCalls && $this->receiptFailures-- > 0) {
                $failure = 'closed-before-headers'; return false;
            }
            return array('ok' => true, 'family' => 2, 'value' => $this->finishCalls ?
                'finalized:' . str_repeat('c', 32) . ':' . str_repeat('e', 32) : 'none');
        }
        throw new RuntimeException('Unexpected pre-mutation RPC');
    }
    public function sourceScalarSnapshot($hash, &$failure = null) {
        if ($this->ownerFailures-- > 0) { $failure='initial-transport'; return false; }
        return array('values' => array('recovery_marker' => '', 'recovery_ack' => ''));
    }
    public function getLedgerKeys(&$failure = null) { return array(); }
    public function executeLifecycleCallback(RetrackersLifecycleCallbacks $callback, &$failure = null) {
        return 'ACQUIRED';
    }
    public function recordRecoveryFailure($reason) {
        $this->reason = $reason;
        throw new RuntimeException('observed refusal');
    }
}

// A daemon that records waits (system.retrackers.retry_waits answers 1).
class PreMutationWaitsAdapter extends PreMutationRecoveryAdapter
{
    public function __construct() {
        parent::__construct();
        $this->wire->waits = true;
    }
    protected function send($payload, $plan, &$failure = null, $historical = false) {
        $answer = parent::send($payload, $plan, $failure, $historical);
        if (strpos($payload, '<methodName>system.listMethods</methodName>') !== false && is_array($answer))
            $answer['value'][] = 'system.retrackers.retry_waits';
        return $answer;
    }
}

class PreMutationRecoveryTest extends TestCase
{
    private function runWorker($adapter, $snapshot) {
        $authority = new class {
            public function prepare($path, $hash, $additions, $deletions, $begin, $snapshot) {
                return array('ok' => true, 'changed' => false);
            }
        };
        $id = str_repeat('B', 40);
        $marker = 'v2:original:0:' . $id . ':' . hash('sha256', 'alice') . ':' . str_repeat('c', 32);
        try {
            return RetrackersRecoveryCoordinator::run(str_repeat('A', 40), 'alice',
                $marker, '0', $id, $adapter, $authority, $snapshot);
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'observed refusal') throw $error;
            return false;
        }
    }
    private function snapshot($failures, $reason = 'initial-hashing-pending') {
        return new class($failures, $reason) {
            public $reads = 0;
            private $failures;
            private $reason;
            public function __construct($failures, $reason) { $this->failures=$failures; $this->reason=$reason; }
            public function capture($hash, $handoff) {
                $this->reads++;
                if ($this->reads <= $this->failures)
                    return array('ok' => false, 'failure' => $this->reason);
                return array('ok' => true, 'snapshot' => array('scalar' => array(
                    'is_private' => '0', 'name' => 'fixture'),
                    'source' => array('path' => '/synthetic/source')));
            }
        };
    }
    public function testCapabilityRetainsTransientTransportClassification() {
        $adapter = new PreMutationRecoveryAdapter(); $adapter->transientCalls=1; $failure=null;
        $this->assertTrue($adapter->nativeOriginalCapability($failure) === false &&
            $failure === 'closed-before-headers', 'read-only capability failure retains the SCGI classification');
    }
    public function testTransientCapabilityAndHashingRecoverBeforeAnyFinish() {
        $adapter = new PreMutationRecoveryAdapter(); $adapter->transientCalls=2;
        $snapshot = $this->snapshot(2);
        // The fourth list asks whether this daemon records waits; it does not.
        $this->assertTrue($this->runWorker($adapter,$snapshot) === true &&
            $adapter->listCalls === 4 && $snapshot->reads === 3 && $adapter->finishCalls === 1,
            'bounded pre-mutation retries reach one exact finish after hashing settles');
    }
    public function testPersistentPreMutationRefusalReleasesExactOriginalInsteadOfParking() {
        $adapter = new PreMutationRecoveryAdapter(); $snapshot=$this->snapshot(PHP_INT_MAX,'ownership-mismatch');
        $this->assertTrue($this->runWorker($adapter,$snapshot) === false &&
            $snapshot->reads > 1 && $adapter->finishCalls === 0 && $adapter->claimCalls()===1 && $adapter->cancelCalls()===1 && $adapter->queuePending() && $adapter->reason === 'ownership-mismatch',
            'persistent read-only failure sends one attested pending-only cancel without acknowledging unapplied Q');
    }
    public function testUnavailableIncarnationDoesNotSendAMutationOrPark() {
        $adapter = new PreMutationRecoveryAdapter(); $adapter->incarnationFails=true;
        $this->assertTrue($this->runWorker($adapter,$this->snapshot(0)) === false &&
            $adapter->finishCalls === 0 && $adapter->reason === 'connect-failed',
            'without a proven incarnation no mutation is sent and the retained claim remains visible');
    }
    public function testLostReceiptReadbackDoesNotResendNativeFinish() {
        $adapter = new PreMutationRecoveryAdapter(); $adapter->receiptFailures=2; $adapter->ownerFailures=2;
        $this->assertTrue($this->runWorker($adapter,$this->snapshot(0)) === true && $adapter->finishCalls === 1,
            'transient receipt and owner reads reconcile one native finish without resending it');
    }
    public function testSlotRefusalCancelsTheUnstartedExactOriginal() {
        $adapter=new PreMutationRecoveryAdapter(); $id=str_repeat('B',40);
        $marker='v2:original:0:'.$id.':'.hash('sha256','alice').':'.str_repeat('c',32);
        try {
            RetrackersRecoveryCoordinator::cancelBeforeStart(str_repeat('A',40),$marker,$id,
                $adapter,'worker-slots-unavailable');
        } catch(RuntimeException $error) {
            if ($error->getMessage() !== 'observed refusal') throw $error;
        }
        $this->assertTrue($adapter->finishCalls===0 && $adapter->claimCalls()===1 && $adapter->cancelCalls()===1 && $adapter->queuePending() && $adapter->reason==='worker-slots-unavailable',
            'a worker without an RPC slot retires only its exact uncaptured observation while Q remains pending');
    }
    public function testUnknownPreMutationClaimOrCancelNeverFallsBackToCompletion() {
        foreach(array('claim','cancel') as $cut) {
            $adapter=new PreMutationRecoveryAdapter();
            if($cut==='claim')$adapter->wire->torrents[str_repeat('A',40)]['held']=true;
            if($cut==='cancel')$adapter->wire->fail['d.retrackers.defer_original']=array('closed-before-headers');
            $this->assertTrue($this->runWorker($adapter,$this->snapshot(PHP_INT_MAX,'ownership-mismatch'))===false &&
                $adapter->claimCalls()===($cut==='cancel'?2:1) && $adapter->cancelCalls()===($cut==='cancel'?1:0) && $adapter->finishCalls===0 && $adapter->queuePending(),
                'unknown '.$cut.' outcome retains Q and never sends a finish/completion fallback');
        }
    }
    // R4-RT-06: rTorrent checks one torrent at a time, so a worker that waits
    // inside its slot for a hash check delays every other queued job. A daemon
    // that records waits takes the job back at once for the hashing interval.
    public function testHashingWithNativeWaitsReleasesTheWorkerAtOnce() {
        $adapter = new PreMutationWaitsAdapter(); $snapshot = $this->snapshot(PHP_INT_MAX);
        $started = time();
        $this->assertTrue($this->runWorker($adapter, $snapshot) === false && $snapshot->reads === 1 &&
            $adapter->reason === 'initial-hashing-pending' && $adapter->cancelCalls() === 1 && $adapter->queuePending(),
            'one capture read, then one defer: the worker does not wait for the hash check');
        $retry = $adapter->wire->torrents[str_repeat('A', 40)]['retry'];
        $this->assertTrue(preg_match('/^r1:f{32}:1:([0-9]+):source-not-ready$/D', $retry, $due) === 1 &&
            (int)$due[1] >= $started + RETRACKERS_RETRY_WAIT_HASHING && (int)$due[1] <= time() + RETRACKERS_RETRY_WAIT_HASHING,
            'the job waits the hashing interval with its count unchanged: ' . $retry);
        $adapter = new PreMutationRecoveryAdapter(); $snapshot = $this->snapshot(PHP_INT_MAX);
        $this->assertTrue($this->runWorker($adapter, $snapshot) === false && $snapshot->reads > 1 &&
            $adapter->reason === 'initial-hashing-pending',
            'without native waits a defer spends an attempt, so the worker keeps its bounded hashing settle first');
    }
}
