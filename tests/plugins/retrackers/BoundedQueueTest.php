<?php

require_once(__DIR__ . '/RetrackersTestStubs.php');
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_DEFER_READ_TIMEOUT', 0.04);
define('RETRACKERS_PREFLIGHT_POLL', 0.001);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');
if (is_file(__DIR__ . '/../../../plugins/retrackers/dispatch.php')) {
    define('RETRACKERS_DISPATCH_IMPORT_ONLY', true);
    require_once(__DIR__ . '/../../../plugins/retrackers/dispatch.php');
}
require_once(__DIR__ . '/RetrackersWireFake.php');

class BoundedQueueModel {
    public $rows = array();
    public $claims = array();
    public $starts = array();
    public $enqueues = array();
    public $dequeues = array();
    public $cursor = '';
    public $startResult = 'spawned';
    public $loseEnqueueReadback = false;
    public $active=true;
    public $retryStates=array();
    public $visits=0;
    public function queueRetryState($hash,&$failure=null) {
        if (isset($this->retryStates[$hash]) && $this->retryStates[$hash]===false) { $failure='connect-failed'; return false; }
        return isset($this->retryStates[$hash])?$this->retryStates[$hash]:'';
    }
    public function queueAdmissionActive($userHash,&$failure=null) { return $this->active; }
    public function queueRows(&$failure = null) { return $this->rows; }
    public function queueCursor($userHash, &$failure = null) { return $this->cursor; }
    public function setQueueCursor($userHash, $hash, &$failure = null) { $this->visits++; $this->cursor=$hash; return $hash; }
    public function queueClaim($hash, &$failure = null) {
        return isset($this->claims[$hash]) ? $this->claims[$hash] : 'none';
    }
    public function enqueueOriginal($hash,$id,$userHash,$state,&$failure = null) {
        $this->enqueues[]=array($hash,$id,$userHash,$state);
        foreach ($this->rows as $row) if ($row[0] === $hash) {
            $q=substr($row[2], -32);
            $this->claims[$hash]='original:'.str_repeat('a',32).':'.str_repeat('b',32).':'.$id.':'.$userHash.':'.$state.':'.$q;
        }
        if ($this->loseEnqueueReadback) { $failure='closed-before-headers'; return false; }
        return 'queued';
    }
    public function startQueueWorker($hash,$tx,$incarnation,$user,&$failure = null) {
        $this->starts[]=array($hash,$tx,$incarnation,$user); return $this->startResult;
    }
    public function dequeueOriginal($hash,$tx,$incarnation,$queueId,&$failure = null) {
        $this->dequeues[]=array($hash,$tx,$incarnation,$queueId); return 'dequeued';
    }
}

class BoundedQueueTest extends TestCase {
    public function testFailedNativePreFenceAttemptCancelsPendingQueueWithoutCompletingIt() {
        $adapter=new class {
            public $cancels=0;public $finishes=0;public $lastReason=null;
            public function nativeDeferUncaptured($hash,$tx,$localId,$handoff,$incarnation,$reason,&$failure=null,&$outcome=null) {$this->cancels++;$outcome='deferred';return true;}
            public function nativeOriginalIncarnation($hash,&$failure=null) {return str_repeat('c',32);}
            public function nativeFinishOriginal($hash,$tx,$incarnation,&$failure=null) {$this->finishes++;throw new RuntimeException('wrong completion of failed queued attempt');}
            public function recordRecoveryFailure($reason) {$this->lastReason=$reason;}
        };
        $method=new ReflectionMethod('RetrackersRecoveryCoordinator','finishPreFenceFailure');
        if(PHP_VERSION_ID<80100)$method->setAccessible(true);
        $tx=str_repeat('b',32);$local=str_repeat('D',40);$handoff='v2:original:0:'.$local.':'.hash('sha256','alice').':'.$tx;
        try {$result=$method->invoke(null,'procfd-preflight-failed',$tx,str_repeat('A',40),$handoff,$local,$adapter);}
        catch(RuntimeException $error){$result=null;}
        $this->assertTrue($result===false&&$adapter->cancels===1&&$adapter->finishes===0&&$adapter->lastReason==='procfd-preflight-failed',
            'a failed pre-fence queued attempt is cancelled once as pending and never completed as no-change');
    }
    public function testNativeUncapturedDeferRequiresOneExactClaimAndOneCas() {
        foreach(array('exact','held','none','foreign-tx') as $case) {
            $wire=new RetrackersWireFake();$h=str_repeat('A',40);$wire->add($h,str_repeat('D',40),'alice','1');
            $claim=$wire->torrents[$h]['claim'];$handoff=$wire->marker($h);
            if($case==='held')$wire->torrents[$h]['held']=true;
            if($case==='none')$wire->torrents[$h]['claim']=null;
            $tx=$case==='foreign-tx'?str_repeat('f',32):$claim['tx'];
            if($case==='foreign-tx')$handoff='v2:original:1:'.$claim['local_id'].':'.$claim['user_hash'].':'.$tx;
            $adapter=new RetrackersWireWorkerAdapter($wire);$failure=null;$outcome=null;
            $ok=$adapter->nativeDeferUncaptured($h,$tx,$claim['local_id'],$handoff,$claim['incarnation'],'procfd-preflight-failed',$failure,$outcome);
            $writes=isset($wire->payloads['d.retrackers.defer_original'])?$wire->payloads['d.retrackers.defer_original']:array();
            $this->assertTrue($ok===($case==='exact')&&count($writes)===($case==='exact'?1:0)&&!isset($wire->payloads['d.retrackers.cancel_original']),
                'only exact uncaptured T/I/L/U/S/Q grants one defer CAS after its readback ('.$case.')');
            if($case==='exact')$this->assertTrue(RetrackersWireFake::params($writes[0])===array($h,$claim['tx'],$claim['incarnation'],$claim['queue_id'],'','r1:'.$claim['queue_id'].':1:'.($wire->now+2).':preflight-refused')&&
                $wire->torrents[$h]['queued']!==''&&!in_array('d.retrackers.finish_original',$wire->calls,true),
                'the production defer CAS carries attested Q and no finish/completion command');
        }
        $wire=new RetrackersWireFake();$h=str_repeat('A',40);$wire->add($h,str_repeat('D',40),'alice','1');
        $claim=$wire->torrents[$h]['claim'];$wire->fail['d.retrackers.defer_original']=array('closed-before-headers');
        $adapter=new RetrackersWireWorkerAdapter($wire);$failure=null;
        $this->assertTrue(!$adapter->nativeDeferUncaptured($h,$claim['tx'],$claim['local_id'],$wire->marker($h),$claim['incarnation'],'procfd-preflight-failed',$failure)&&
            count($wire->payloads['d.retrackers.defer_original'])===1&&$failure==='native-uncaptured-cancel-unconfirmed'&&$wire->claimWire($h)!=='none',
            'an unanswered defer stays unknown without replay or completed-Q fallback');
    }
    public function testNativeStageProofUsesOnlyOwnedClaimAndDistinctNonceCalls() {
        if (!method_exists('RetrackersWorkerRpcAdapter','nativePreflightStage')) {
            $this->assertTrue(false,'modern queued preflight verifies owned native file descriptors without launching helpers');
            return;
        }
        $stage=(new ReflectionClass('BoundedQueueProofStage'))->newInstanceWithoutConstructor();
        $adapter=new BoundedQueueProofAdapter();$failure=null;
        $h=str_repeat('A',40);$t=str_repeat('b',32);$i=str_repeat('c',32);
        $this->assertTrue($adapter->nativePreflightStage($h,$t,$i,$stage,$failure) && $failure===null,
            'both exact native nonce proofs permit the pre-mutation boundary');
        $this->assertTrue(substr_count($adapter->wire,'<string>d.retrackers.preflight_stage</string>')===2 &&
            substr_count($adapter->wire,'<string>'.$h.'</string>')===2 &&
            substr_count($adapter->wire,'<string>'.$t.'</string>')===2 &&
            substr_count($adapter->wire,'<string>'.$i.'</string>')===2 &&
            strpos($adapter->wire,'execute.')===false && strpos($adapter->wire,'/bin/cat')===false,
            'two proofs carry exact H/T/I and stage identity without executable or helper arguments');
        $adapter=new BoundedQueueProofAdapter();$adapter->wrongNonce=true;
        $this->assertTrue(!$adapter->nativePreflightStage($h,$t,$i,$stage,$failure) && $failure==='procfd-preflight-failed',
            'one wrong native nonce cannot authorize capture');
        $adapter=new BoundedQueueProofAdapter();$adapter->nonces=array(str_repeat('1',32),str_repeat('1',32));
        $this->assertTrue(!$adapter->nativePreflightStage($h,$t,$i,$stage,$failure) && $adapter->wire==='',
            'duplicate challenge nonces are refused before sending any native request');
        $adapter=new BoundedQueueProofAdapter();
        $this->assertTrue(!$adapter->nativePreflightStage($h,str_repeat('B',32),$i,$stage,$failure) && $adapter->wire==='',
            'noncanonical claim authority is refused before any proof request');
    }
    public function testNativeQueueCapabilityRequiresHelperFreeStageProof() {
        $adapter=new class extends RetrackersLifecycleRpcAdapter {
            public $stageMethod=false;
            public $deferMethod=true;
            public function __construct() { $this->family=2; }
            protected function send($payload,$plan,&$failure=null,$historical=false) {
                $methods=array('d.retrackers.enqueue_original','d.retrackers.dequeue_original',
                    'system.retrackers.original_claim','system.retrackers.worker_start','system.retrackers.dispatcher_start',
                    'system.retrackers.queue_cursor','system.retrackers.queue_cursor.set','schedule.if_absent');
                if ($this->deferMethod) $methods[]='d.retrackers.defer_original';
                if ($this->stageMethod) $methods[]='d.retrackers.preflight_stage';
                return array('ok'=>true,'family'=>2,'value'=>$methods);
            }
        };
        $failure=null;
        $this->assertTrue(!$adapter->nativeQueueCapability($failure) && $failure==='native-queue-capability-unconfirmed',
            'init visibly refuses a queue daemon without the owned helper-free stage proof');
        $adapter->stageMethod=true;
        $this->assertTrue($adapter->nativeQueueCapability($failure),
            'the complete fixed-target queue and stage proof surface permits admission without the plain cancel PHP never sends');
        $adapter->deferMethod=false;
        $this->assertTrue(!$adapter->nativeQueueCapability($failure),
            'a daemon without the pending-only defer CAS cannot admit a queue that acknowledges failed attempts');
    }
    private function available() {
        $ok=class_exists('RetrackersBoundedDispatcher',false);
        $this->assertTrue($ok,'the durable queue has a bounded dispatcher implementation');
        return $ok;
    }
    private function row($number,$state='0') {
        return array(strtoupper(str_pad(dechex($number),40,'0',STR_PAD_LEFT)),
            str_repeat('D',39).strtoupper(dechex($number%16)),
            'q1:'.hash('sha256','alice').':'.str_pad(dechex($number),32,'0',STR_PAD_LEFT),'','',$state);
    }
    private function claim($row,$kind='original') {
        return $kind.':'.str_repeat('a',32).':'.str_repeat('b',32).':'.$row[1].':'.hash('sha256','alice').':'.$row[5].':'.substr($row[2],-32);
    }
    public function testNativeInsertEnqueuesWithoutLaunchingAPhpProcess() {
        $action=retrackersBuildInsertAction('/app/plugins/retrackers/run.sh','/usr/bin/php','alice',true);
        $this->assertTrue(strpos($action,'d.retrackers.enqueue_original=')!==false && strpos($action,'execute.throw.bg')===false,
            'the .25 insert hook persists queue ownership without one PHP process per insertion');
        $legacy=retrackersBuildInsertAction('/app/plugins/retrackers/run.sh','/usr/bin/php','alice',false);
        $this->assertTrue(strpos($legacy,'execute.throw.bg')!==false && strpos($legacy,'enqueue_original')===false,
            'the .9.8 path retains its application contract without a native queue shim');
    }
    public function testDispatcherCadenceUsesActualPublicScheduleAndFixedTargetAbi() {
        $adapter=new class extends RetrackersLifecycleRpcAdapter {
            public $wire='';
            public function __construct() { $this->family=2; }
            protected function send($payload,$plan,&$failure=null,$historical=false) {
                $this->wire=$payload;
                if (strpos($payload,'<methodName>schedule.if_absent</methodName>')===false) return false;
                return array('ok'=>true,'family'=>2,'value'=>'0');
            }
        };
        $failure=null;
        $this->assertTrue($adapter->armNativeQueueDispatcher('alice',$failure) &&
            strpos($adapter->wire,'system.retrackers.dispatcher_start="alice"')!==false &&
            strpos($adapter->wire,'execute.')===false && strpos($adapter->wire,'/usr/bin/')===false,
            'captured .25 public schedule.if_absent rearms only the fixed-target user without postponing an existing tick');
    }
    public function testFullPoolPreservesWorkAndAdvancesFairCursor() {
        if (!$this->available()) return;
        $model=new BoundedQueueModel(); $model->rows=array($this->row(1),$this->row(2)); $model->startResult='full';
        foreach ($model->rows as $row) $model->claims[$row[0]]=$this->claim($row);
        RetrackersBoundedDispatcher::run($model,'alice');$firstStarts=count($model->starts);
        RetrackersBoundedDispatcher::run($model,'alice');
        $this->assertTrue($firstStarts===1 && count($model->starts)===2 &&
            $model->starts[0][0]!==$model->starts[1][0] && count($model->dequeues)===0 && count($model->enqueues)===0,
            'full admission advances to the next durable job without cancelling or replaying a claim');
    }
    public function testHeldOldestDoesNotStarveOtherQueuedJobs() {
        if (!$this->available()) return;
        $model=new BoundedQueueModel(); $model->rows=array($this->row(1),$this->row(2));
        $model->claims[$model->rows[0][0]]='held'; $model->claims[$model->rows[1][0]]=$this->claim($model->rows[1]);
        $result=RetrackersBoundedDispatcher::run($model,'alice');
        $this->assertTrue($result===array('errors'=>array()) && $model->visits===2 && count($model->starts)===1 && $model->starts[0][0]===$model->rows[1][0],
            'an uncertain oldest claim stays held while the next proven original is dispatched');
    }
    public function testDispatcherBoundsOnePassAndResumesAfterCursor() {
        if (!$this->available()) return;
        $model=new BoundedQueueModel();
        for($n=1;$n<=40;$n++) { $row=$this->row($n); $model->rows[]=$row; $model->claims[$row[0]]='held'; }
        RetrackersBoundedDispatcher::run($model,'alice'); $cursor=$model->cursor; $first=$model->visits;
        RetrackersBoundedDispatcher::run($model,'alice');
        $this->assertTrue($first===32 && $model->visits===64 && $cursor===$model->rows[31][0] && $model->cursor!==$cursor,
            'a dispatcher invocation examines at most 32 jobs and the next invocation continues after its cursor');
    }
    public function testRestartReenqueueUsesCurrentStoppedStateAndSameDurableQueueId() {
        if (!$this->available()) return;
        $model=new BoundedQueueModel(); $model->rows=array($this->row(1,'0'));
        $result=RetrackersBoundedDispatcher::run($model,'alice');
        $this->assertTrue($result===array('errors'=>array()) && count($model->enqueues)===1 && $model->enqueues[0][3]==='0' && count($model->starts)===1,
            'restart recovery claims the current stopped generation and starts only the read-back exact T/I/Q');
    }
    public function testBackoffAndExhaustedQueueStayPendingWithoutReenqueueOrLaunch() {
        if (!$this->available()) return;
        foreach(array('deferred','terminal','malformed') as $kind) {
            $model=new BoundedQueueModel();$row=$this->row(1);$model->rows=array($row);$q=substr($row[2],-32);
            $model->retryStates[$row[0]]=$kind==='deferred'?'r1:'.$q.':1:'.(time()+300).':capture-refused':($kind==='terminal'?'r1:'.$q.':6:0:source-not-ready':'broken');
            $result=RetrackersBoundedDispatcher::run($model,'alice');
            $this->assertTrue(count($model->enqueues)===0&&count($model->starts)===0&&count($model->dequeues)===0, 'a '.$kind.' queue is retained without another claim, worker or completion');
        }
        $model=new BoundedQueueModel();$row=$this->row(1);$model->rows=array($row);
        $model->retryStates[$row[0]]='r1:'.substr($row[2],-32).':2:'.(time()-1).':source-not-ready';
        $result=RetrackersBoundedDispatcher::run($model,'alice');
        $this->assertTrue(count($model->enqueues)===1&&count($model->starts)===1, 'an exact due Q becomes eligible again after its persisted delay');
    }
    public function testUnansweredRetryReadIsUnconfirmedNotInvalid() {
        if (!$this->available()) return;
        $model=new BoundedQueueModel();$row=$this->row(1);$model->rows=array($row);$model->retryStates[$row[0]]=false;
        $result=RetrackersBoundedDispatcher::run($model,'alice');
        $this->assertTrue($result['errors']===array(array('hash'=>$row[0],'reason'=>'queue-retry-state-unconfirmed')) &&
            count($model->enqueues)===0 && count($model->starts)===0,
            'a transport failure on the retry read keeps the job pending and is not reported as an invalid state');
        $cache=new RetrackersMemoryCache();FileUtil::$messages=array();
        RetrackersQueueDiagnostics::emit($result['errors'],$cache,1000);
        $this->assertTrue(count(FileUtil::$messages)===1 && strpos(FileUtil::$messages[0],'SCGI answer unconfirmed')!==false &&
            strpos(FileUtil::$messages[0],'inspect torrent session')===false,
            'an unconfirmed read asks for no session inspection');
    }
    public function testCapabilityRefusalSharesTheLimiterAndNamesTransportFailures() {
        if (!function_exists('retrackersDispatchMain')) { $this->assertTrue(false,'the dispatcher entry point is testable'); return; }
        $adapter=new class extends RetrackersQueueRpcAdapter {
            public $answer=false;public $calls=0;
            public function __construct() { $this->family=2; }
            protected function send($payload,$plan,&$failure=null,$historical=false) {
                $this->calls++;
                if ($this->answer===false) { $failure='connect-failed'; return false; }
                return array('ok'=>true,'family'=>2,'value'=>$this->answer);
            }
        };
        $cache=new RetrackersMemoryCache();FileUtil::$messages=array();
        foreach(range(0,28) as $tick) $this->assertTrue(retrackersDispatchMain($adapter,'alice',$cache,1000+2*$tick)===1,'an outage refuses each pass');
        $this->assertTrue(count(FileUtil::$messages)===1 && strpos(FileUtil::$messages[0],'retrackers-queue: queue-transport-unavailable;')===0 &&
            strpos(FileUtil::$messages[0],'capability')===false,
            'an SCGI outage writes one rate-limited transport line instead of a missing-capability line per tick');
        $adapter->answer=array('schedule.if_absent');
        retrackersDispatchMain($adapter,'alice',$cache,1061);
        $this->assertTrue(count(FileUtil::$messages)===2 && strpos(FileUtil::$messages[1],'retrackers-queue: native-queue-capability-unconfirmed;')===0,
            'a daemon that answers without the queue methods is the only missing-capability case');
    }
    public function testLostEnqueueReplyDoesNotStartOrReplayInSamePass() {
        if (!$this->available()) return;
        $model=new BoundedQueueModel(); $model->rows=array($this->row(1)); $model->loseEnqueueReadback=true;
        $first=RetrackersBoundedDispatcher::run($model,'alice');
        $this->assertTrue(count($model->enqueues)===1 && count($model->starts)===0 && count($model->dequeues)===0 && $first['errors']!==array(),
            'a lost enqueue reply retains durable intent without replay or worker launch');
        $second=RetrackersBoundedDispatcher::run($model,'alice');
        $this->assertTrue(count($model->enqueues)===1 && count($model->starts)===1 && $second===array('errors'=>array()),
            'the next pass reads the exact existing claim instead of repeating enqueue');
    }
    public function testExactFinishedQueueIsDequeuedWithoutRedispatch() {
        if (!$this->available()) return;
        $model=new BoundedQueueModel(); $row=$this->row(1); $model->rows=array($row); $model->claims[$row[0]]=$this->claim($row,'finalized');
        $result=RetrackersBoundedDispatcher::run($model,'alice');
        $this->assertTrue(count($model->dequeues)===1 && $model->dequeues[0][3]===substr($row[2],-32) && count($model->starts)===0 && count($model->enqueues)===0,
            'a crash after durable finish is reconciled by exact Q/T/I dequeue without another worker');
    }
    public function testForeignOrChangedQueueClaimCannotLaunchOrDequeue() {
        if (!$this->available()) return;
        $model=new BoundedQueueModel(); $row=$this->row(1); $model->rows=array($row);
        $model->claims[$row[0]]=substr($this->claim($row),0,-32).str_repeat('f',32);
        $result=RetrackersBoundedDispatcher::run($model,'alice');
        $this->assertTrue(count($model->starts)===0 && count($model->dequeues)===0 && $result['errors']!==array(),
            'a readback for a different queue generation grants no worker or completion authority');
    }
    public function testNativeCliBindsImmutableIncarnationBeforeRecovery() {
        $h=str_repeat('A',40); $id=str_repeat('B',40); $i=str_repeat('c',32);
        $marker='v2:original:0:'.$id.':'.hash('sha256','alice').':'.str_repeat('d',32);
        $cli=retrackersParseCliArgv(array('update.php',$h,'alice',$marker,$i));
        $this->assertTrue(is_array($cli) && $cli['incarnation']===$i,
            'native exec carries the admitted incarnation as a strict immutable argv field');
        $adapter=new class($i) {
            public $value;
            public function __construct($value) { $this->value=$value; }
            public function nativeOriginalIncarnation($hash,&$failure=null) { return $this->value; }
        };
        $this->assertTrue(retrackersQueuedWorkerGenerationMatches($cli,$adapter),
            'the admitted current incarnation may enter recovery');
        $adapter->value=str_repeat('e',32);
        $this->assertTrue(!retrackersQueuedWorkerGenerationMatches($cli,$adapter),
            'a late child cannot enter recovery against a newer same-hash incarnation');
        $adapter->value=false;
        $this->assertTrue(!retrackersQueuedWorkerGenerationMatches($cli,$adapter),
            'an unavailable incarnation grants no cancellation or mutation authority');
        $this->assertTrue(retrackersParseCliArgv(array('update.php',$h,'alice',
            'v1:original:0:'.$id.':'.hash('sha256','alice'),$i))===false,
            'the legacy handoff cannot attach a native incarnation argument');
        foreach(array(null,1,str_repeat('A',32),'') as $invalid) {
            $this->assertTrue(retrackersParseCliArgv(array('update.php',$h,'alice',$marker,$invalid))===false,
                'every present native incarnation argument must be a canonical string');
        }
    }
    public function testInactiveOwnerKeepsOwedQueueWithoutStartingOrReclaiming() {
        if (!$this->available()) return;
        $model=new BoundedQueueModel();$model->active=false;$model->rows=array($this->row(1),$this->row(2));
        $model->claims[$model->rows[1][0]]=$this->claim($model->rows[1]);
        $result=RetrackersBoundedDispatcher::run($model,'alice');
        $this->assertTrue($result===array('errors'=>array()) && $model->visits===2 && count($model->enqueues)===0 && count($model->starts)===0 && count($model->dequeues)===0,
            'teardown and containment leave owed Q intact without admitting new workers');
    }
    public function testQueueAdmissionRequiresExactlyOneCallerProfileTotal() {
        $adapter=new class extends RetrackersQueueRpcAdapter {
            public $keys=array();
            public function __construct() {}
            public function getLedgerKeys(&$failure=null) { return $this->keys; }
        };
        $u=hash('sha256','alice');$pf='pf:'.$u.':'.str_repeat('a',64);$pv='pv:'.str_repeat('b',32);
        foreach(array(array($pf,$pv),array($pf,$pv,'pf:'.hash('sha256','bob').':'.str_repeat('c',64)),array($pf,$pv,'ta:1'),array($pf,$pv,'to:'.str_repeat('d',32).':i:'.$u),array($pv)) as $index=>$keys) {
            $adapter->keys=$keys;
            $this->assertTrue($adapter->queueAdmissionActive($u)===($index===0),
                'only a sole caller pf and one idle epoch can admit queued work');
        }
    }
    // rTorrent's worker-exit recovery (r3fix/native 68dab7c and 2b14cb83,
    // R4-RT-X1): native finishes or rolls back a dead worker's captured
    // transaction itself, and no pass resumes it. A finished job is dequeued
    // with its retry receipt cleared. A rollback spends one counted attempt,
    // so the job owes Q but waits 2^count seconds before a new transaction.
    public function testNativeWorkerExitRecoveryLeavesTheDispatcherOrdinaryWork() {
        $wire = new RetrackersWireFake();
        $wire->waits = true;
        $wire->ledger = array_merge($wire->ledger, array('pv:' . str_repeat('1', 32),
            'pf:' . hash('sha256', 'alice') . ':' . str_repeat('2', 64)));
        $rolled = str_repeat('A', 40);
        $done = str_repeat('B', 40);
        $wire->add($rolled, str_repeat('C', 40), 'alice', '1');
        $wire->add($done, str_repeat('D', 40), 'alice', '1');
        $q = substr($wire->torrents[$rolled]['queued'], 68);
        $wire->torrents[$rolled]['retry'] = 'r1:' . $q . ':2:' . ($wire->now - 1) . ':capture-refused';
        // The finished job went through a wait before its last attempt.
        $wire->torrents[$done]['retry'] = 'r1:' . substr($wire->torrents[$done]['queued'], 68) . ':1:' .
            ($wire->now + 60) . ':source-not-ready';
        $deadTx = $wire->torrents[$rolled]['claim']['tx'];
        // Both workers captured their originals, then died.
        $wire->torrents[$rolled]['held'] = $wire->torrents[$done]['held'] = true;
        $queue = new RetrackersWireQueueAdapter($wire);
        $this->assertTrue(RetrackersBoundedDispatcher::run($queue, 'alice', $wire->now) === array('errors' => array()) &&
            $wire->started === array(), 'a held capture is neither resumed nor replaced by a pass');
        $this->assertTrue($wire->workerExited($rolled, false) === 'rolled back; attempt 3 of 6' &&
            $wire->workerExited($done, true) === 'finished' &&
            $wire->nativeLog === array('RR worker exit recovery: ' . $rolled . ': rolled back; attempt 3 of 6',
                'RR worker exit recovery: ' . $done . ': finished'), 'native recovers and names each outcome');
        $this->assertTrue($wire->torrents[$rolled]['retry'] === 'r1:' . $q . ':3:' . ($wire->now + 8) . ':capture-refused',
            'the rollback spends one counted attempt with the application backoff: ' . $wire->torrents[$rolled]['retry']);
        $this->assertTrue(RetrackersBoundedDispatcher::run($queue, 'alice', $wire->now) === array('errors' => array()) &&
            $wire->started === array(), 'the next pass waits for the counted retry');
        $this->assertTrue($wire->torrents[$done]['queued'] === '' && $wire->torrents[$done]['retry'] === '',
            'the finished job is dequeued, spent no attempt and owes no retry receipt');
        $wire->now += 8;
        $this->assertTrue(RetrackersBoundedDispatcher::run($queue, 'alice', $wire->now) === array('errors' => array()),
            'the pass after the due time is clean');
        $claim = $wire->torrents[$rolled]['claim'];
        $this->assertTrue($wire->started === array($rolled) && is_array($claim) && $claim['tx'] !== $deadTx &&
            in_array('wp:' . str_repeat('C', 40), $wire->ledger, true),
            'then the rolled-back job runs again under a new transaction');
    }

    // A worker that always dies after capture: six rollbacks make the job
    // terminal (2b14cb83), so no seventh capture stops the torrent.
    public function testSixWorkerExitRollbacksEndTerminalWithoutFurtherDispatch() {
        $wire = new RetrackersWireFake();
        $wire->waits = true;
        $wire->ledger = array_merge($wire->ledger, array('pv:' . str_repeat('1', 32),
            'pf:' . hash('sha256', 'alice') . ':' . str_repeat('2', 64)));
        $hash = str_repeat('E', 40);
        $wire->add($hash, str_repeat('F', 40), 'alice', '1');
        $q = substr($wire->torrents[$hash]['queued'], 68);
        $queue = new RetrackersWireQueueAdapter($wire);
        $txs = array();
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->assertTrue(RetrackersBoundedDispatcher::run($queue, 'alice', $wire->now) === array('errors' => array()) &&
                count($wire->started) === $attempt, 'attempt ' . $attempt . ': a due pass starts one worker');
            $txs[] = $wire->torrents[$hash]['claim']['tx'];
            $wire->torrents[$hash]['held'] = true;
            $outcome = $wire->workerExited($hash, false);
            $this->assertTrue($outcome === 'rolled back; attempt ' . $attempt . ' of 6' . ($attempt === 6 ? ', retries exhausted' : ''),
                'attempt ' . $attempt . ': ' . $outcome);
            if ($attempt === 6) break;
            $retry = RetrackersQueueRetry::parse($wire->torrents[$hash]['retry'], $q);
            $this->assertTrue($retry['attempt'] === $attempt && $retry['due'] === (float)($wire->now + (1 << $attempt)) &&
                $retry['reason'] === 'capture-refused', 'attempt ' . $attempt . ': counted with the application backoff');
            $this->assertTrue(RetrackersBoundedDispatcher::run($queue, 'alice', $wire->now) === array('errors' => array()) &&
                count($wire->started) === $attempt, 'attempt ' . $attempt . ': no pass before the due time');
            $wire->now += 1 << $attempt;
        }
        $this->assertTrue($wire->torrents[$hash]['retry'] === 'r1:' . $q . ':6:0:capture-refused' &&
            $wire->torrents[$hash]['message'] === RetrackersWireFake::EXHAUSTED && count(array_unique($txs)) === 6,
            'six transactions, each rolled back once, end in the visible terminal state');
        for ($pass = 0; $pass < 3; $pass++) {
            $wire->now += 3600;
            $this->assertTrue(RetrackersBoundedDispatcher::run($queue, 'alice', $wire->now) === array('errors' => array()),
                'a pass over the terminal job is clean');
        }
        $this->assertTrue(count($wire->started) === 6 && $wire->torrents[$hash]['claim'] === null &&
            $wire->torrents[$hash]['queued'] !== '' &&
            RetrackersQueueRetry::isTerminal(RetrackersQueueRetry::parse($wire->torrents[$hash]['retry'], $q)),
            'no further worker starts; the terminal job keeps Q for the reset tool');
    }
}

class BoundedQueueProofStage extends RetrackersAnonymousStage {
    public function candidate() { return array('capability'=>'/proc/123/fd/3','dev'=>'4','ino'=>'5','size'=>'6','sha256'=>str_repeat('a',64)); }
    public function original() { return array('capability'=>'/proc/123/fd/4','dev'=>'4','ino'=>'7','size'=>'8','sha256'=>str_repeat('b',64)); }
}
class BoundedQueueProofAdapter extends RetrackersWorkerRpcAdapter {
    public $wire='';
    public $nonces=array('11111111111111111111111111111111','22222222222222222222222222222222');
    public $wrongNonce=false;
    public function __construct() { $this->family=2; }
    protected function freshPreflightNonce() { return array_shift($this->nonces); }
    protected function send($payload,$plan,&$failure=null,$historical=false) {
        $this->wire=$payload;
        return array('ok'=>true,'family'=>2,'value'=>array(
            array('ok'=>true,'value'=>str_repeat('1',32)),
            array('ok'=>true,'value'=>str_repeat($this->wrongNonce?'3':'2',32))));
    }

}
