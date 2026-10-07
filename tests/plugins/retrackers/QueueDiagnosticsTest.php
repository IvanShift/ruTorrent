<?php
require_once(__DIR__ . '/../../php/TestCase.php');
function getCmd($command) { return $command; }
define('RETRACKERS_IMPORT_ONLY',true);
define('RETRACKERS_DISPATCH_IMPORT_ONLY',true);
require_once(__DIR__ . '/../../../plugins/retrackers/dispatch.php');
class FileUtil { public static $messages=array();public static function toLog($message){self::$messages[]=$message;} }
class QueueDiagnosticCache {
    public $state=null;
    public function get(&$state){if($this->state===null)return false;$state=clone $this->state;return true;}
    public function set($state){$this->state=clone $state;return true;}
}
class QueueDiagnosticsTest extends TestCase {
    public function testFirstErrorAndClassificationChangeAreImmediateWithBoundedRepeats() {
        if(!class_exists('RetrackersQueueDiagnostics')){$this->assertTrue(false,'a persistent queue refusal must log immediately without landing on a wall-clock boundary');return;}
        $cache=new QueueDiagnosticCache();FileUtil::$messages=array();
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
    public function testColdProcessAndClockRollbackKeepDiagnosticsVisible() {
        if(!class_exists('RetrackersQueueDiagnostics')){$this->assertTrue(false,'queue diagnostics need persisted bounded state');return;}
        $cache=new QueueDiagnosticCache();FileUtil::$messages=array();
        $error=array(array('hash'=>str_repeat('A',40),'reason'=>'queue-retry-state-invalid'));
        RetrackersQueueDiagnostics::emit($error,$cache,1001);
        $cache2=new QueueDiagnosticCache();$cache2->state=clone $cache->state;
        RetrackersQueueDiagnostics::emit($error,$cache2,1002);
        RetrackersQueueDiagnostics::emit($error,$cache2,999);
        $this->assertTrue(count(FileUtil::$messages)===2,'a new dispatcher process shares the repeat bound and a clock rollback cannot suppress errors forever');
    }
}
