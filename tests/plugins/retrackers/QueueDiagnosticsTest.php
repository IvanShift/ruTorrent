<?php
require_once(__DIR__ . '/RetrackersTestStubs.php');
define('RETRACKERS_IMPORT_ONLY',true);
define('RETRACKERS_DISPATCH_IMPORT_ONLY',true);
define('RETRACKERS_GENERATION_READ_TIMEOUT', 0.04);
define('RETRACKERS_PREFLIGHT_POLL', 0.001);
require_once(__DIR__ . '/../../../plugins/retrackers/dispatch.php');
class QueueDiagnosticsTest extends TestCase {
    public function testFirstErrorAndClassificationChangeAreImmediateWithBoundedRepeats() {
        if(!class_exists('RetrackersQueueDiagnostics')){$this->assertTrue(false,'a persistent queue refusal must log immediately without landing on a wall-clock boundary');return;}
        $cache=new RetrackersMemoryCache();FileUtil::$messages=array();
        $error=array(array('hash'=>str_repeat('A',40),'reason'=>'queue-retry-state-invalid'));
        RetrackersQueueDiagnostics::emit($error,$cache,1001);
        $this->assertTrue(count(FileUtil::$messages)===1&&strpos(FileUtil::$messages[0],'d.custom=retrackers-retry')!==false&&strpos(FileUtil::$messages[0],'queued work remains pending')!==false,'the first malformed persisted retry names its session key and stalled work immediately');
        $error[0]['hash']=str_repeat('B',40);
        RetrackersQueueDiagnostics::emit($error,$cache,1042);
        RetrackersQueueDiagnostics::emit(array(),$cache,1043);
        RetrackersQueueDiagnostics::emit($error,$cache,1044);
        $this->assertTrue(count(FileUtil::$messages)===1,'rotating cursor hashes and healthy partial passes do not reset the per-reason log bound');
        RetrackersQueueDiagnostics::emit(array(array('hash'=>'','reason'=>'queue-read-unconfirmed')),$cache,1045);
        $this->assertTrue(count(FileUtil::$messages)===2,'a newly observed failure classification logs before the old classification repeat deadline');
        RetrackersQueueDiagnostics::emit($error,$cache,1061);
        $this->assertTrue(count(FileUtil::$messages)===3,'a continuing refusal repeats after sixty seconds even when no dispatcher visits a minute boundary');
    }
    public function testWorkerGenerationRefusalsShareTheQueueLimiter() {
        if(!function_exists('retrackersQueuedWorkerAdmitted')){$this->assertTrue(false,'every repeated worker refusal shares one limiter');return;}
        $adapter=new class {
            public $value=false;
            public function nativeOriginalIncarnation($hash,&$failure=null){if($this->value===false){$failure='connect-failed';return false;}$failure=null;return $this->value;}
        };
        $cache=new RetrackersMemoryCache();FileUtil::$messages=array();
        for($worker=0;$worker<8;$worker++)
            $this->assertTrue(!retrackersQueuedWorkerAdmitted(array('hash'=>str_repeat(chr(65+$worker),40),'incarnation'=>str_repeat('a',32)),$adapter,$cache,1000+$worker),'an unconfirmed generation refuses the worker');
        $this->assertTrue(count(FileUtil::$messages)===1 && strpos(FileUtil::$messages[0],'retrackers-queue: queue-worker-generation-unconfirmed; affected-in-pass=1; example-hash=AAAA')===0 &&
            strpos(FileUtil::$messages[0],'SCGI answer unconfirmed')!==false,
            'eight workers refused by one outage write one bounded line');
        $adapter->value=str_repeat('b',32);
        $this->assertTrue(!retrackersQueuedWorkerAdmitted(array('hash'=>str_repeat('A',40),'incarnation'=>str_repeat('a',32)),$adapter,$cache,1010) &&
            count(FileUtil::$messages)===2 && strpos(FileUtil::$messages[1],'queue-worker-generation-changed')!==false &&
            strpos(FileUtil::$messages[1],'immutable claim remains pending without cancellation')!==false,
            'a proven changed generation is a separate, immediately visible classification');
        $adapter->value=str_repeat('a',32);
        $this->assertTrue(retrackersQueuedWorkerAdmitted(array('hash'=>str_repeat('A',40),'incarnation'=>str_repeat('a',32)),$adapter,$cache,1011) && count(FileUtil::$messages)===2,
            'the current generation admits the worker silently');
    }
    public function testColdProcessAndClockRollbackKeepDiagnosticsVisible() {
        if(!class_exists('RetrackersQueueDiagnostics')){$this->assertTrue(false,'queue diagnostics need persisted bounded state');return;}
        $cache=new RetrackersMemoryCache();FileUtil::$messages=array();
        $error=array(array('hash'=>str_repeat('A',40),'reason'=>'queue-retry-state-invalid'));
        RetrackersQueueDiagnostics::emit($error,$cache,1001);
        $cache2=new RetrackersMemoryCache();$cache2->state=clone $cache->state;
        RetrackersQueueDiagnostics::emit($error,$cache2,1002);
        RetrackersQueueDiagnostics::emit($error,$cache2,999);
        $this->assertTrue(count(FileUtil::$messages)===2,'a new dispatcher process shares the repeat bound and a clock rollback cannot suppress errors forever');
    }
}
