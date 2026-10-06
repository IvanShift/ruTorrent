<?php

require_once(__DIR__ . '/../../php/TestCase.php');
function getCmd($command) { return $command; }
class rTorrent {
    public static function quoteCommandArg($value) {
        return('"' . addcslashes((string)$value, "\\\"") . '"');
    }
}
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_PREFLIGHT_TIMEOUT', 0.02);
define('RETRACKERS_HASH_WAIT_TIMEOUT', 0.02);
define('RETRACKERS_PREFLIGHT_POLL', 0.001);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');

class PreMutationRecoveryAdapter extends RetrackersWorkerRpcAdapter
{
    public $listCalls = 0;
    public $transientCalls = 0;
    public $finishCalls = 0;
    public $claimCalls = 0;
    public $cancelCalls = 0;
    public $unknownClaim = false;
    public $loseCancelReply = false;
    public $queuePending = true;
    public $receiptFailures = 0;
    public $ownerFailures = 0;
    public $incarnationFails = false;
    public $reason = null;
    public function __construct() { $this->family = 2; }
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
        if (strpos($payload, '<methodName>system.retrackers.original_claim</methodName>') !== false) {
            $this->claimCalls++;
            return array('ok'=>true,'family'=>2,'value'=>$this->unknownClaim?'held':
                'original:'.str_repeat('c',32).':'.str_repeat('e',32).':'.str_repeat('B',40).':'.hash('sha256','alice').':0:'.str_repeat('f',32));
        }
        if (strpos($payload, '<methodName>d.retrackers.cancel_original</methodName>') !== false) {
            $this->cancelCalls++;
            if (strpos($payload,'<string>'.str_repeat('f',32).'</string>')===false)
                throw new RuntimeException('cancel omitted exact attested Q');
            if ($this->loseCancelReply) {$failure='closed-before-headers';return false;}
            return array('ok'=>true,'family'=>2,'value'=>'cancelled');
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
        $this->assertTrue($this->runWorker($adapter,$snapshot) === true &&
            $adapter->listCalls === 3 && $snapshot->reads === 3 && $adapter->finishCalls === 1,
            'bounded pre-mutation retries reach one exact finish after hashing settles');
    }
    public function testPersistentPreMutationRefusalReleasesExactOriginalInsteadOfParking() {
        $adapter = new PreMutationRecoveryAdapter(); $snapshot=$this->snapshot(PHP_INT_MAX,'ownership-mismatch');
        $this->assertTrue($this->runWorker($adapter,$snapshot) === false &&
            $snapshot->reads > 1 && $adapter->finishCalls === 0 && $adapter->claimCalls===1 && $adapter->cancelCalls===1 && $adapter->queuePending && $adapter->reason === 'ownership-mismatch',
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
        $this->assertTrue($adapter->finishCalls===0 && $adapter->claimCalls===1 && $adapter->cancelCalls===1 && $adapter->queuePending && $adapter->reason==='worker-slots-unavailable',
            'a worker without an RPC slot retires only its exact uncaptured observation while Q remains pending');
    }
    public function testUnknownPreMutationClaimOrCancelNeverFallsBackToCompletion() {
        foreach(array('claim','cancel') as $cut) {
            $adapter=new PreMutationRecoveryAdapter();$adapter->unknownClaim=$cut==='claim';$adapter->loseCancelReply=$cut==='cancel';
            $this->assertTrue($this->runWorker($adapter,$this->snapshot(PHP_INT_MAX,'ownership-mismatch'))===false &&
                $adapter->claimCalls===1 && $adapter->cancelCalls===($cut==='cancel'?1:0) && $adapter->finishCalls===0 && $adapter->queuePending,
                'unknown '.$cut.' outcome retains Q and never sends a finish/completion fallback');
        }
    }
}
