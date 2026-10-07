<?php
require_once(__DIR__ . '/../../php/TestCase.php');
function getCmd($command) { return $command; }
class rTorrent { public static function quoteCommandArg($value) { return '"' . addcslashes((string)$value, "\\\"") . '"'; } }
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_CANCEL_READ_TIMEOUT', 0.04);
define('RETRACKERS_PREFLIGHT_POLL', 0.001);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');
class QueueRetryTest extends TestCase {
    public function testExactQueueBackoffExhaustsAndRetainsVisibleTerminal() {
        if (!class_exists('RetrackersQueueRetry')) { $this->assertTrue(false, 'queue failures need a durable bounded retry policy'); return; }
        $q=str_repeat('a',32); $wire=''; $now=1000;
        foreach(array(2,4,8,16,32,0) as $index=>$delay) {
            $wire=RetrackersQueueRetry::next($wire,$q,'capture-refused',$now);
            $state=RetrackersQueueRetry::parse($wire,$q);
            $this->assertTrue(is_array($state) && $state['attempt']===$index+1 && $state['due']===(float)($delay ? $now+$delay:0), 'each exact Q receives exponential delay followed by a terminal marker');
            $now+=64;
        }
        $this->assertTrue(RetrackersQueueRetry::next($wire,$q,'capture-refused',$now)===false, 'an exhausted Q cannot create a seventh automatic attempt');
        $this->assertTrue(RetrackersQueueRetry::parse($wire,str_repeat('b',32))===false, 'retry authority cannot transfer to a newer queue generation');
    }
    public function testMalformedRetryStateCannotResetTheAttemptBudget() {
        if (!class_exists('RetrackersQueueRetry')) { $this->assertTrue(false, 'malformed retry state must refuse visibly'); return; }
        $q=str_repeat('a',32);
        foreach(array('r1:'.$q.':0:1000:capture-refused','r1:'.$q.':6:1000:capture-refused','r1:'.$q.':1:0:capture-refused','r1:'.$q.':1:01000:capture-refused','r1:'.$q.':1:1000:remote-payload') as $wire)
            $this->assertTrue(RetrackersQueueRetry::next($wire,$q,'capture-refused',1000)===false, 'invalid persisted retry authority is never treated as a fresh job');
    }
    public function testInsertDuringInitAcquireReconcilesTheSameOwnerWithoutAnotherWrite() {
        $callback=RetrackersLifecycleCallbacks::bootstrapAcquire(str_repeat('a',32),str_repeat('b',32),hash('sha256','alice'),str_repeat('c',64),'tadd_trackers1alice','tadd_trackers2alice','cat=');
        $adapter=new class($callback) {
            public $writes=0,$reads=0;private $callback;
            public function __construct($callback){$this->callback=$callback;}
            public function executeLifecycleCallback($callback,&$failure=null){$this->writes++;return $callback->successSentinel();}
            public function coherentLifecycleReadback($user,$action,&$failure=null){
                if(++$this->reads===1){$failure='profile-binding-unstable';return false;}
                $s=$this->callback->expectedState();$failure=null;
                return array('phase'=>$s['phase'],'persistent_epoch'=>$s['epoch'],'owner'=>$s['owner'],'transaction_receipts'=>$s['required_receipts']);
            }
        };
        $method=new ReflectionMethod('RetrackersLifecycleCoordinator','executeAndReadback');
        if(PHP_VERSION_ID<80100)$method->setAccessible(true);$failure=null;
        $result=$method->invokeArgs(null,array($adapter,$callback,'alice','cat=','lifecycle-acquire-unconfirmed',&$failure));
        $this->assertTrue(is_array($result)&&$failure===null&&$adapter->writes===1&&$adapter->reads===2, 'an insertion that drifts one acquire read retries only attestation and continues the same INIT_OWNER');
    }
    public function testUnavailablePreCaptureCancelExitsWithoutRetiringNativeClaim() {
        $source=realpath(__DIR__.'/../../../plugins/retrackers/update.php');
        $script='define("RETRACKERS_IMPORT_ONLY",true);require '.var_export($source,true).';'.
            '$adapter=new class {public $calls=0;public function nativeDeferUncaptured($h,$t,$l,$m,$i,$r,&$f=null){$this->calls++;$f="native-uncaptured-cancel-unconfirmed";return false;}public function recordRecoveryFailure($r){}};'.
            '$method=new ReflectionMethod("RetrackersRecoveryCoordinator","finishPreFenceFailure");if(PHP_VERSION_ID<80100)$method->setAccessible(true);'.
            '$result=$method->invoke(null,"initial-transport",str_repeat("a",32),str_repeat("B",40),"v2:original:fixture",str_repeat("C",40),$adapter);echo json_encode(array($result,$adapter->calls));';
        $process=proc_open(array(PHP_BINARY,'-r',$script),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
        fclose($pipes[0]);$deadline=microtime(true)+2.0;
        do {$state=proc_get_status($process);if(!$state['running'])break;usleep(10000);}while(microtime(true)<$deadline);
        if($state['running'])proc_terminate($process);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
        $this->assertTrue(!$state['running']&&$err===''&&json_decode($out,true)===array(false,1), 'a bounded pre-capture refusal exits without completion or ownership retirement; native Q/claim remains retryable');
    }
    public function testCancellationReadOutageRecoversBeforeOneMutation() {
        $adapter=new QueueRetryCancelAdapter();$adapter->claimFailures=3;$failure=null;
        $ok=$adapter->nativeCancelUncaptured($adapter->h,$adapter->t,$adapter->l,$adapter->marker,null,$failure);
        $this->assertTrue($ok && $adapter->claimReads===4 && $adapter->cancelWrites===1, 'a transient cancellation query outage gets bounded read retries before one exact cancel');
    }
    public function testGenerationReadOutageRetriesWithoutMisclassifyingAChangedIncarnation() {
        $adapter=new class {
            public $reads=0;
            public function nativeOriginalIncarnation($hash,&$failure=null) {
                if(++$this->reads<3){$failure='connect-failed';return false;}
                $failure=null;return str_repeat('a',32);
            }
        };
        $failure=null;$cli=array('hash'=>str_repeat('A',40),'incarnation'=>str_repeat('a',32));
        $this->assertTrue(retrackersQueuedWorkerGenerationMatches($cli,$adapter,$failure)&&$adapter->reads===3&&$failure===null, 'an outage before worker generation admission retries only the immutable incarnation read');
        $cli['incarnation']=str_repeat('b',32);
        $this->assertTrue(!retrackersQueuedWorkerGenerationMatches($cli,$adapter,$failure)&&$adapter->reads===4&&$failure===null, 'a proven changed generation refuses immediately and stays distinct from transport uncertainty');
    }
    public function testDeferredCancellationCarriesExactRetryCasAndLostReplyReconciles() {
        if (!method_exists('RetrackersWorkerRpcAdapter','nativeDeferUncaptured')) { $this->assertTrue(false, 'retry metadata must share the uncaptured cancel transaction'); return; }
        foreach(array(false,true) as $lost) {
            $adapter=new QueueRetryCancelAdapter();$adapter->loseReply=$lost;$failure=null;
            $ok=$adapter->nativeDeferUncaptured($adapter->h,$adapter->t,$adapter->l,$adapter->marker,null,'native-capture-retry-pending',$failure);
            $this->assertTrue($ok && $adapter->cancelWrites===1 && strpos($adapter->cancelWire,'capture-refused')!==false && $adapter->retry!=='', 'retry CAS is durable with cancellation and a lost reply never resends the mutation');
        }
    }
    public function testFinalizationDriftRetriesOnlyReadbackAndKeepsExactEpoch() {
        $callback=RetrackersLifecycleCallbacks::initFinalization(str_repeat('a',32),str_repeat('b',32),str_repeat('c',32),hash('sha256','alice'),'tadd_trackers1alice','tadd_trackers2alice','cat=','cat=');
        $method=new ReflectionMethod('RetrackersLifecycleCoordinator','executeAndReadback');if(PHP_VERSION_ID<80100)$method->setAccessible(true);
        foreach(array(false,true) as $wrongEpoch) {
            $adapter=new QueueRetryInitAdapter($callback);$adapter->drift=1;
            if($wrongEpoch)$adapter->decision['persistent_epoch']=str_repeat('d',32);
            $failure=null;$result=$method->invokeArgs(null,array($adapter,$callback,'alice','cat=','hook-install-unconfirmed',&$failure));
            $this->assertTrue(($result!==false)===!$wrongEpoch&&$adapter->writes===1&&$adapter->reads===2,'successful finalization reattests a transient read drift without replay and never adopts a different epoch');
        }
    }
    public function testKnownInitCasLoserWaitsForIdenticalNewerPeerWithoutReacquiring() {
        $method=new ReflectionMethod('RetrackersLifecycleCoordinator','executeAndReadback');if(PHP_VERSION_ID<80100)$method->setAccessible(true);
        foreach(array('acquire','final') as $kind) {
            $callback=$kind==='acquire'?RetrackersLifecycleCallbacks::currentInitAcquire(str_repeat('a',32),str_repeat('b',32),str_repeat('c',32),hash('sha256','alice'),hash('sha256','cat='),hash('sha256','cat='),'tadd_trackers1alice','tadd_trackers2alice','cat='):RetrackersLifecycleCallbacks::initFinalization(str_repeat('a',32),str_repeat('b',32),str_repeat('c',32),hash('sha256','alice'),'tadd_trackers1alice','tadd_trackers2alice','cat=','cat=');
            $adapter=new QueueRetryInitAdapter($callback);$adapter->reply='changed';$adapter->decision=QueueRetryInitAdapter::installed();$adapter->pending=1;
            $failure=null;$result=$method->invokeArgs(null,array($adapter,$callback,'alice','cat=','init-unknown',&$failure,'lifecycle-busy',false,true));
            $this->assertTrue(is_array($result)&&!empty($result['peer_completed'])&&$result['owner']===null&&$adapter->writes===1&&$adapter->reads===2&&$failure===null,'a known changed init CAS may wait through a newer same-user token and epoch until exact F/S and claim completion, without adopting or mutating that token');
        }
    }
    public function testPeerReconciliationRejectsForeignTeardownAndUnknownMutationReplies() {
        $callback=RetrackersLifecycleCallbacks::currentInitAcquire(str_repeat('a',32),str_repeat('b',32),str_repeat('c',32),hash('sha256','alice'),hash('sha256','cat='),hash('sha256','cat='),'tadd_trackers1alice','tadd_trackers2alice','cat=');
        $method=new ReflectionMethod('RetrackersLifecycleCoordinator','executeAndReadback');if(PHP_VERSION_ID<80100)$method->setAccessible(true);
        foreach(array('foreign-user','foreign-claim','foreign-owner','multi-profile','teardown','unknown','lost') as $kind) {
            $adapter=new QueueRetryInitAdapter($callback);$adapter->reply='changed';$adapter->decision=QueueRetryInitAdapter::installed();
            if($kind==='foreign-user'){$adapter->decision['profiles'][0]['user']='bob';$adapter->decision['caller']['membership']='absent';}
            if($kind==='foreign-claim')$adapter->decision['profiles'][0]['claim_hash']=str_repeat('f',64);
            if($kind==='foreign-owner'){$adapter->decision['phase']='INIT_OWNER';$adapter->decision['profiles'][0]['user']='bob';$adapter->decision['profiles'][0]['pair']='D/D';$adapter->decision['caller']['membership']='absent';$adapter->decision['owner']=array('mode'=>'i','user_hash'=>hash('sha256','bob'),'token'=>str_repeat('f',32));}
            if($kind==='multi-profile'){$adapter->decision['phase']='INIT_OWNER';$adapter->decision['profile_count']=2;$adapter->decision['profiles'][0]['pair']='D/D';$adapter->decision['caller']=array('membership'=>'owner','is_owner'=>true);$adapter->decision['owner']=array('mode'=>'i','user_hash'=>hash('sha256','alice'),'token'=>str_repeat('f',32));$adapter->decision['profiles'][]=array('user'=>'bob','pair'=>'F/S','claim_hash'=>hash('sha256','cat='));}
            if($kind==='teardown'){$adapter->decision['phase']='DONE_OWNER';$adapter->decision['profiles'][0]['pair']='S/S';$adapter->decision['caller']['is_owner']=true;$adapter->decision['owner']=array('mode'=>'d','user_hash'=>hash('sha256','alice'),'token'=>str_repeat('f',32));}
            if($kind==='unknown')$adapter->reply='opaque';
            if($kind==='lost')$adapter->reply=false;
            $failure=null;$result=$method->invokeArgs(null,array($adapter,$callback,'alice','cat=','init-unknown',&$failure,'lifecycle-busy',false,true));
            $this->assertTrue($result===false&&$adapter->writes===1&&$adapter->reads===(in_array($kind,array('unknown','lost'),true)?0:1),'peer reconciliation never adopts a different user, claim or teardown and never reads past an unconfirmed mutation reply');
            if(in_array($kind,array('unknown','lost'),true))$this->assertTrue($failure==='init-unknown','unknown and lost replies remain distinct from a proven CAS loser');
        }
    }
    public function testPendingInitRetriesPreConsumerSamplesButNeverReplaysConsumer() {
        $sample=array('ok'=>true,'family'=>2,'value'=>array('digest'=>str_repeat('a',64),'event_key_digest'=>str_repeat('a',64),'event_map_digest'=>str_repeat('a',64),'recovery_rows_digest'=>str_repeat('a',64),'ledger_key_digest'=>str_repeat('a',64),'ledger_map_digest'=>str_repeat('a',64),'ledger_digest'=>str_repeat('a',64),'event_keys'=>array(),'actions'=>array(),'recovery_rows'=>"RR4\0".pack('N',0),'ledger_keys'=>array(),'counts'=>array('event_keys'=>0,'actions'=>0,'recovery_rows'=>0,'ledger_keys'=>0),'production_accepted'=>false));
        $drift=$sample;$drift['value']['digest']=str_repeat('b',64);
        $adapter=new class(array($drift,$sample,$sample,$sample)) extends RetrackersDirectRpcAdapter {
            private $samples;public $reads=0;public function __construct($samples){$this->samples=$samples;}
            public function historicalBindingSample(&$failure=null){$failure=null;$this->reads++;return array_shift($this->samples);}
        };
        $calls=0;$outcome=(new RetrackersStableHistoricalBinding($adapter))->consumePendingInit('alice','cat=',new RetrackersHistoricalLedgerAttestation(true,true,true,true),function($decision)use(&$calls){$calls++;return false;});
        $this->assertTrue($outcome===array('ok'=>true,'consumer_result'=>false)&&$adapter->reads===4&&$calls===1,'pre-acquire drift retries only samples; even a failing consumer executes at most once');
    }
}
class QueueRetryInitAdapter {
    public $writes=0,$reads=0,$drift=0,$pending=0,$reply='success',$decision;
    public function __construct($callback){$s=$callback->expectedState();$this->decision=array('phase'=>$s['phase'],'persistent_epoch'=>$s['epoch'],'owner'=>$s['owner'],'transaction_receipts'=>$s['required_receipts']);}
    public static function installed(){return array('phase'=>'IDLE_CURRENT','persistent_epoch'=>str_repeat('d',32),'owner'=>null,'transaction_receipts'=>array(),'profile_count'=>1,'caller'=>array('membership'=>'profile','is_owner'=>false),'profiles'=>array(array('user'=>'alice','pair'=>'F/S','claim_hash'=>hash('sha256','cat='))),'binding'=>array('family'=>2));}
    public function getFamily(){return 2;}
    public function executeLifecycleCallback($callback,&$failure=null){$this->writes++;if($this->reply===false){$failure='closed-before-headers';return false;}$failure=null;return $this->reply==='success'?$callback->successSentinel():($this->reply==='changed'?$callback->changedSentinel():$this->reply);}
    public function coherentLifecycleReadback($user,$action,&$failure=null){
        $this->reads++;if($this->drift-->0){$failure='profile-binding-unstable';return false;}$failure=null;
        $d=$this->decision;if($this->pending-->0){$d['phase']='INIT_OWNER';$d['persistent_epoch']=str_repeat('e',32);$d['owner']=array('mode'=>'i','user_hash'=>hash('sha256','alice'),'token'=>str_repeat('e',32));$d['caller']=array('membership'=>'owner','is_owner'=>true);$d['profiles'][0]['pair']='D/D';}return $d;
    }
}
class QueueRetryCancelAdapter extends RetrackersWorkerRpcAdapter {
    public $h,$t,$l,$q,$marker; public $claimFailures=0,$claimReads=0,$cancelWrites=0;public $retry='',$cancelWire='';public $loseReply=false,$cancelled=false;
    public function __construct() { $this->family=2;$this->h=str_repeat('A',40);$this->t=str_repeat('b',32);$this->l=str_repeat('C',40);$this->q=str_repeat('d',32);$this->marker='v2:original:0:'.$this->l.':'.hash('sha256','alice').':'.$this->t; }
    protected function send($payload,$plan,&$failure=null,$historical=false) {
        $failure=null;$value=null;
        if(strpos($payload,'<methodName>system.retrackers.original_claim</methodName>')!==false) {
            $this->claimReads++;if($this->claimFailures-->0) {$failure='closed-before-headers';return false;}
            $value=$this->cancelled?'none':'original:'.$this->t.':'.str_repeat('e',32).':'.$this->l.':'.hash('sha256','alice').':0:'.$this->q;
        } elseif(strpos($payload,'<methodName>d.custom</methodName>')!==false) $value=$this->retry;
        elseif(strpos($payload,'<methodName>d.retrackers.cancel_original</methodName>')!==false||strpos($payload,'<methodName>d.retrackers.defer_original</methodName>')!==false) {
            $this->cancelWrites++;$this->cancelWire=$payload;$this->cancelled=true;
            preg_match('~<string>(r1:[^<]+)</string>~',$payload,$m);if(isset($m[1]))$this->retry=$m[1];
            if($this->loseReply){$failure='closed-before-headers';return false;}$value='cancelled';
        } else throw new RuntimeException('unexpected retry RPC');
        return array('ok'=>true,'family'=>2,'value'=>$value);
    }
}
