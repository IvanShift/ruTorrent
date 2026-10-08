<?php
require_once(__DIR__ . '/RetrackersTestStubs.php');
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_DEFER_READ_TIMEOUT', 0.04);
define('RETRACKERS_GENERATION_READ_TIMEOUT', 0.04);
define('RETRACKERS_PREFLIGHT_POLL', 0.001);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');
require_once(__DIR__ . '/RetrackersWireFake.php');
class QueueRetryTest extends TestCase {
    private function finishPreFence($reason,$wire,$hash,$adapter) {
        $method=new ReflectionMethod('RetrackersRecoveryCoordinator','finishPreFenceFailure');
        if(PHP_VERSION_ID<80100)$method->setAccessible(true);
        $claim=$wire->torrents[$hash]['claim'];
        return $method->invoke(null,$reason,$claim['tx'],$hash,$wire->marker($hash),$claim['local_id'],$adapter);
    }
    private function job($waits=false) {
        $wire=new RetrackersWireFake();$wire->waits=$waits;$hash=str_repeat('A',40);
        $wire->add($hash,str_repeat('C',40));FileUtil::$messages=array();
        return array($wire,$hash,new RetrackersWireWorkerAdapter($wire,$hash,'alice'));
    }
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
        list($wire,$hash,$adapter)=$this->job();$claim=$wire->torrents[$hash]['claim'];
        $wire->fail['system.retrackers.original_claim']=array('closed-before-headers','closed-before-headers','closed-before-headers');
        $failure=null;$ok=$adapter->nativeDeferUncaptured($hash,$claim['tx'],$claim['local_id'],$wire->marker($hash),null,'lifecycle-unsupported',$failure);
        $this->assertTrue($ok && count(array_keys($wire->calls,'system.retrackers.original_claim'))===4 && count(array_keys($wire->calls,'d.retrackers.defer_original'))===1, 'a transient cancellation query outage gets bounded read retries before one exact defer');
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
        foreach(array(false,true) as $lost) {
            list($wire,$hash,$adapter)=$this->job();$claim=$wire->torrents[$hash]['claim'];
            if($lost)$wire->lose['d.retrackers.defer_original']=1;
            $failure=null;$outcome=null;
            $ok=$adapter->nativeDeferUncaptured($hash,$claim['tx'],$claim['local_id'],$wire->marker($hash),null,'native-capture-retry-pending',$failure,$outcome);
            $writes=$wire->payloads['d.retrackers.defer_original'];
            $this->assertTrue($ok && $outcome==='deferred' && count($writes)===1 && strpos($writes[0],'capture-refused')!==false &&
                $wire->torrents[$hash]['retry']==='r1:'.$claim['queue_id'].':1:'.($wire->now+2).':capture-refused' && $wire->claimWire($hash)==='none',
                'retry CAS is durable with cancellation and a lost reply never resends the mutation');
        }
    }
    public function testProductionDeferSendsOnlyTheFiveArgumentCasWithAttestedQ() {
        list($wire,$hash,$adapter)=$this->job();$claim=$wire->torrents[$hash]['claim'];
        $this->assertTrue($this->finishPreFence('procfd-preflight-failed',$wire,$hash,$adapter)===false &&
            !isset($wire->payloads['d.retrackers.cancel_original']) && !in_array('d.retrackers.finish_original',$wire->calls,true),
            'a failed pre-fence attempt never sends the plain cancel or a completion');
        $wire2=RetrackersWireFake::params($wire->payloads['d.retrackers.defer_original'][0]);
        $this->assertTrue($wire2===array($hash,$claim['tx'],$claim['incarnation'],$claim['queue_id'],'','r1:'.$claim['queue_id'].':1:'.($wire->now+2).':preflight-refused'),
            'the defer CAS carries exact H/T/I/Q, the observed prior state and one advanced count');
        $this->assertTrue($wire->torrents[$hash]['queued']!=='' && FileUtil::$messages===array('retrackers-recovery: '.$hash.' procfd-preflight-failed'),
            'Q stays owed and one classified line names the failed attempt');
    }
    public function testRetryReadOutageHoldsTheClaimInsteadOfReportingCorruption() {
        list($wire,$hash,$adapter)=$this->job();
        $wire->fail['d.custom']=array_fill(0,500,'connect-failed');
        $this->assertTrue($this->finishPreFence('lifecycle-unsupported',$wire,$hash,$adapter)===false &&
            !isset($wire->payloads['d.retrackers.defer_original']) && $wire->claimWire($hash)!=='none' && $wire->torrents[$hash]['retry']==='',
            'an unanswered retry read sends no CAS; native claim, retry and Q stay for the next worker');
        $this->assertTrue(count(FileUtil::$messages)===1 && strpos(FileUtil::$messages[0],' queue-preflight-retry-held; native original claim and Q retained')!==false &&
            strpos(FileUtil::$messages[0],'retries stopped')===false && strpos(FileUtil::$messages[0],'state-invalid')===false,
            'a transport failure is unconfirmed, not a corrupt state, and never claims that retries stopped');
        list($wire,$hash,$adapter)=$this->job();$wire->torrents[$hash]['retry']='r1:broken';
        $this->finishPreFence('lifecycle-unsupported',$wire,$hash,$adapter);
        $this->assertTrue(count(FileUtil::$messages)===1 && strpos(FileUtil::$messages[0],' queue-retry-state-invalid; inspect d.custom=retrackers-retry')!==false,
            'only a value that fails the grammar is reported as invalid');
    }
    public function testRemovedTorrentIsReportedAbsentNotRetained() {
        list($wire,$hash,$adapter)=$this->job();
        // A user Remove: native cancels the uncaptured claim, then erases the torrent and Q.
        unset($wire->torrents[$hash]);
        $claim=array('tx'=>str_repeat('0',31).'3','local_id'=>str_repeat('C',40));
        $method=new ReflectionMethod('RetrackersRecoveryCoordinator','finishPreFenceFailure');if(PHP_VERSION_ID<80100)$method->setAccessible(true);
        $method->invoke(null,'initial-absent',$claim['tx'],$hash,'v2:original:0:'.$claim['local_id'].':'.hash('sha256','alice').':'.$claim['tx'],$claim['local_id'],$adapter);
        $this->assertTrue(count(FileUtil::$messages)===1 && strpos(FileUtil::$messages[0],$hash.' queue-target-absent; the torrent was removed')!==false &&
            !isset($wire->payloads['d.retrackers.defer_original']),
            'after a Remove the worker reports the torrent gone instead of a retained claim and Q');
        list($wire,$hash,$adapter)=$this->job();$wire->torrents[$hash]['claim']=null;
        $this->finishPreFence2($wire,$hash,$adapter);
        $this->assertTrue(count(FileUtil::$messages)===1 && strpos(FileUtil::$messages[0],' queue-claim-retired; another actor retired the native claim')!==false &&
            !isset($wire->payloads['d.retrackers.defer_original']),
            'a retired claim on a present torrent is neither a removal nor a retained claim');
    }
    private function finishPreFence2($wire,$hash,$adapter) {
        $method=new ReflectionMethod('RetrackersRecoveryCoordinator','finishPreFenceFailure');if(PHP_VERSION_ID<80100)$method->setAccessible(true);
        $t=$wire->torrents[$hash];$tx=str_repeat('0',31).'3';
        return $method->invoke(null,'lifecycle-unsupported',$tx,$hash,'v2:original:0:'.$t['local_id'].':'.hash('sha256','alice').':'.$tx,$t['local_id'],$adapter);
    }
    public function testTerminalDeferLogsExhaustionWithTheResetCommand() {
        list($wire,$hash,$adapter)=$this->job();$q=$wire->torrents[$hash]['claim']['queue_id'];
        $wire->torrents[$hash]['retry']='r1:'.$q.':5:'.($wire->now-1).':capture-refused';
        $this->finishPreFence('procfd-preflight-failed',$wire,$hash,$adapter);
        $this->assertTrue($wire->torrents[$hash]['retry']==='r1:'.$q.':6:0:preflight-refused' && $wire->claimWire($hash)==='none',
            'the sixth counted attempt records the terminal state with the same exact CAS');
        $this->assertTrue(count(FileUtil::$messages)===1 && strpos(FileUtil::$messages[0],$hash.' queue-retry-exhausted; durable Q retained; automatic retries stopped')!==false &&
            strpos(FileUtil::$messages[0],RetrackersWorkerRpcAdapter::retryResetCommand('alice',$hash).' (add --apply)')!==false,
            'the terminal attempt logs exhaustion once and names the exact reset command');
    }
    public function testNativeRetryContractMatchesThePhpGrammar() {
        // RetrackersWireFake::retryValid ports retrackers_retry_parse from rTorrent
        // src/core/retrackers_queue_receipts.h (rr_retry_valid in d883db88). A PHP-only
        // change of MAX_ATTEMPTS or REASONS must fail here.
        $q=str_repeat('a',32);
        $this->assertTrue(RetrackersQueueRetry::MAX_ATTEMPTS===RetrackersWireFake::MAX_COUNT && RetrackersQueueRetry::REASONS===RetrackersWireFake::REASONS,
            'the PHP attempt limit and reason list equal the native validator contract');
        $corpus=array('');
        foreach(array('0','1','5','6','7','9','10','12') as $count) foreach(array('0','1000','01000','12345678901') as $due)
            foreach(array_merge(RetrackersQueueRetry::REASONS,array('remote-payload','')) as $reason)
                $corpus[]='r1:'.$q.':'.$count.':'.$due.':'.$reason;
        $corpus[]='r1:'.str_repeat('b',32).':1:1000:capture-refused';
        foreach($corpus as $wire)
            $this->assertTrue(($wire===''||RetrackersWireFake::retryValid($wire,$q))===(RetrackersQueueRetry::parse($wire,$q)!==false),
                'PHP parse and the native validator agree on '.$wire);
    }
    public function testWaitsKeepTheSpentBudgetOnlyForWaitingCategories() {
        $q=str_repeat('a',32);$now=1000;
        $this->assertTrue(RetrackersQueueRetry::next('',$q,'source-not-ready',$now,300)==='r1:'.$q.':1:1300:source-not-ready',
            'a first wait records count 1 because the grammar has no count 0');
        $wire='r1:'.$q.':3:900:capture-refused';
        $this->assertTrue(RetrackersQueueRetry::next($wire,$q,'transport-unavailable',$now,30)==='r1:'.$q.':3:1030:transport-unavailable',
            'a wait keeps the spent count and only moves due');
        $this->assertTrue(RetrackersQueueRetry::next('r1:'.$q.':5:900:source-not-ready',$q,'source-not-ready',$now,60)==='r1:'.$q.':5:1060:source-not-ready',
            'a wait at the last counted attempt never becomes terminal');
        $this->assertTrue(RetrackersQueueRetry::next($wire,$q,'capture-refused',$now,60)===false && RetrackersQueueRetry::next($wire,$q,'preflight-refused',$now,60)===false,
            'counted categories can never be recorded as waits');
        $this->assertTrue(RetrackersQueueRetry::waitSeconds('initial-hashing-pending')===RETRACKERS_RETRY_WAIT_HASHING &&
            RetrackersQueueRetry::waitSeconds('connect-failed')===RETRACKERS_RETRY_WAIT_TRANSPORT &&
            RetrackersQueueRetry::waitSeconds('lifecycle-unsupported')===RETRACKERS_RETRY_WAIT_NOT_READY,
            'the detailed reason chooses the wait interval');
        foreach(array('write-timeout','truncated-body','initial-transport') as $reason)
            $this->assertTrue(RetrackersQueueRetry::category($reason)==='transport-unavailable','an unanswered '.$reason.' read is a transport wait');
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
            $failure=null;$result=$method->invokeArgs(null,array($adapter,$callback,'alice','cat=','init-unknown',&$failure,array('changed_failure'=>'lifecycle-busy','reconcile_init_peer'=>true)));
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
            $failure=null;$result=$method->invokeArgs(null,array($adapter,$callback,'alice','cat=','init-unknown',&$failure,array('changed_failure'=>'lifecycle-busy','reconcile_init_peer'=>true)));
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
