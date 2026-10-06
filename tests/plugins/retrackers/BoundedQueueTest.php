<?php

require_once(__DIR__ . '/../../php/TestCase.php');
function getCmd($command) { return $command; }
class rTorrent {
    public static function quoteCommandArg($value) {
        return '"' . addcslashes((string)$value, "\\\"") . '"';
    }
}
define('RETRACKERS_IMPORT_ONLY', true);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');
if (is_file(__DIR__ . '/../../../plugins/retrackers/dispatch.php')) {
    define('RETRACKERS_DISPATCH_IMPORT_ONLY', true);
    require_once(__DIR__ . '/../../../plugins/retrackers/dispatch.php');
}

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
    public function queueAdmissionActive($userHash,&$failure=null) { return $this->active; }
    public function queueRows(&$failure = null) { return $this->rows; }
    public function queueCursor($userHash, &$failure = null) { return $this->cursor; }
    public function setQueueCursor($userHash, $hash, &$failure = null) { $this->cursor=$hash; return $hash; }
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
            public function nativeCancelUncaptured($hash,$tx,$localId,$handoff,$incarnation=null,&$failure=null) {$this->cancels++;return true;}
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
    public function testNativeUncapturedCancelRequiresOneExactClaimAndOneCas() {
        if(!method_exists('RetrackersWorkerRpcAdapter','nativeCancelUncaptured')) {
            $this->assertTrue(false,'queued recovery exposes exact uncaptured cancellation without completing Q');return;
        }
        $h=str_repeat('A',40);$t=str_repeat('b',32);$i=str_repeat('c',32);$l=str_repeat('D',40);$u=hash('sha256','alice');$q=str_repeat('e',32);$handoff='v2:original:1:'.$l.':'.$u.':'.$t;
        foreach(array('original:'.$t.':'.$i.':'.$l.':'.$u.':1:'.$q,'held','none','original:'.str_repeat('f',32).':'.$i.':'.$l.':'.$u.':1:'.$q) as $index=>$claim) {
            $adapter=new BoundedQueueCancelAdapter();$adapter->claim=$claim;$failure=null;
            $ok=$adapter->nativeCancelUncaptured($h,$t,$l,$handoff,$i,$failure);
            $this->assertTrue($ok===($index===0)&&count($adapter->calls)===($index===0?2:1),
                'only exact uncaptured T/I/L/U/S/Q grants one cancellation after one readback');
            if($index===0)$this->assertTrue(strpos($adapter->calls[1],'<methodName>d.retrackers.cancel_original</methodName>')!==false&&strpos($adapter->calls[1],'<string>'.$q.'</string>')!==false,
                'the cancel CAS carries attested Q and no finish/completion command');
        }
        $adapter=new BoundedQueueCancelAdapter();$adapter->claim='original:'.$t.':'.$i.':'.$l.':'.$u.':1:'.$q;$adapter->loseCancel=true;$failure=null;
        $this->assertTrue(!$adapter->nativeCancelUncaptured($h,$t,$l,$handoff,$i,$failure)&&count($adapter->calls)===2,
            'a lost cancellation reply stays unknown without replay or completed-Q fallback');
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
            public $cancelMethod=true;
            public function __construct() { $this->family=2; }
            protected function send($payload,$plan,&$failure=null,$historical=false) {
                $methods=array('d.retrackers.enqueue_original','d.retrackers.dequeue_original',
                    'system.retrackers.original_claim','system.retrackers.worker_start','system.retrackers.dispatcher_start',
                    'system.retrackers.queue_cursor','system.retrackers.queue_cursor.set','schedule.if_absent');
                if ($this->cancelMethod) $methods[]='d.retrackers.cancel_original';
                if ($this->stageMethod) $methods[]='d.retrackers.preflight_stage';
                return array('ok'=>true,'family'=>2,'value'=>$methods);
            }
        };
        $failure=null;
        $this->assertTrue(!$adapter->nativeQueueCapability($failure) && $failure==='native-queue-capability-unconfirmed',
            'init visibly refuses a queue daemon without the owned helper-free stage proof');
        $adapter->stageMethod=true;
        $this->assertTrue($adapter->nativeQueueCapability($failure),
            'the complete fixed-target queue and stage proof surface permits admission');
        $adapter->cancelMethod=false;
        $this->assertTrue(!$adapter->nativeQueueCapability($failure),
            'a daemon without pending-only cancellation cannot admit a queue that acknowledges failed attempts');
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
        $first=RetrackersBoundedDispatcher::run($model,'alice');
        $second=RetrackersBoundedDispatcher::run($model,'alice');
        $this->assertTrue($first['full']===1 && $second['full']===1 && count($model->starts)===2 &&
            $model->starts[0][0]!==$model->starts[1][0] && count($model->dequeues)===0 && count($model->enqueues)===0,
            'full admission advances to the next durable job without cancelling or replaying a claim');
    }
    public function testHeldOldestDoesNotStarveOtherQueuedJobs() {
        if (!$this->available()) return;
        $model=new BoundedQueueModel(); $model->rows=array($this->row(1),$this->row(2));
        $model->claims[$model->rows[0][0]]='held'; $model->claims[$model->rows[1][0]]=$this->claim($model->rows[1]);
        $result=RetrackersBoundedDispatcher::run($model,'alice');
        $this->assertTrue($result['held']===1 && $result['spawned']===1 && count($model->starts)===1 && $model->starts[0][0]===$model->rows[1][0],
            'an uncertain oldest claim stays held while the next proven original is dispatched');
    }
    public function testDispatcherBoundsOnePassAndResumesAfterCursor() {
        if (!$this->available()) return;
        $model=new BoundedQueueModel();
        for($n=1;$n<=40;$n++) { $row=$this->row($n); $model->rows[]=$row; $model->claims[$row[0]]='held'; }
        $first=RetrackersBoundedDispatcher::run($model,'alice'); $cursor=$model->cursor;
        $second=RetrackersBoundedDispatcher::run($model,'alice');
        $this->assertTrue($first['examined']===32 && $second['examined']===32 && $cursor===$model->rows[31][0] && $model->cursor!==$cursor,
            'a dispatcher invocation examines at most 32 jobs and the next invocation continues after its cursor');
    }
    public function testRestartReenqueueUsesCurrentStoppedStateAndSameDurableQueueId() {
        if (!$this->available()) return;
        $model=new BoundedQueueModel(); $model->rows=array($this->row(1,'0'));
        $result=RetrackersBoundedDispatcher::run($model,'alice');
        $this->assertTrue($result['spawned']===1 && count($model->enqueues)===1 && $model->enqueues[0][3]==='0' && count($model->starts)===1,
            'restart recovery claims the current stopped generation and starts only the read-back exact T/I/Q');
    }
    public function testLostEnqueueReplyDoesNotStartOrReplayInSamePass() {
        if (!$this->available()) return;
        $model=new BoundedQueueModel(); $model->rows=array($this->row(1)); $model->loseEnqueueReadback=true;
        $first=RetrackersBoundedDispatcher::run($model,'alice');
        $this->assertTrue(count($model->enqueues)===1 && count($model->starts)===0 && count($model->dequeues)===0 && $first['errors']!==array(),
            'a lost enqueue reply retains durable intent without replay or worker launch');
        $second=RetrackersBoundedDispatcher::run($model,'alice');
        $this->assertTrue(count($model->enqueues)===1 && count($model->starts)===1 && $second['spawned']===1,
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
        $this->assertTrue($result['held']===2 && count($model->enqueues)===0 && count($model->starts)===0 && count($model->dequeues)===0,
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
class BoundedQueueCancelAdapter extends RetrackersWorkerRpcAdapter {
    public $claim='held';public $calls=array();public $loseCancel=false;
    public function __construct() {$this->family=2;}
    protected function send($payload,$plan,&$failure=null,$historical=false) {
        $this->calls[]=$payload;
        if(strpos($payload,'<methodName>system.retrackers.original_claim</methodName>')!==false)return array('ok'=>true,'family'=>2,'value'=>$this->claim);
        if($this->loseCancel){$failure='closed-before-headers';return false;}
        return array('ok'=>true,'family'=>2,'value'=>'cancelled');
    }
}
