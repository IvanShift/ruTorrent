<?php
define('MOVE_WORKER_TEST_LIBRARY',true);
require_once __DIR__.'/MoveWorkerTest.php';
require_once testFindRepoRoot().'/plugins/erasedata/filesystem.php';
class FileUtil { public static function toLog($message) {} }
class FileOperationOutcome { public static function clear($plugin,$hash) {} }
class rAutoTools {
    public static $root;
    public static $calls=0;
    public static $fail=false;
    public static function completionMailFile($destination,$finished) { return '/dummy/.mailto'; }
    public static function notifyCompletedFileTransfer($destination,$finished,$name) {
        self::$calls++;
        $files=glob(self::$root.'/*.move.json');$job=json_decode(file_get_contents($files[0]),true);
        strictAssertSame('notice-attempted',$job['phase'],'attempt durable before external mail');
        if(self::$fail)throw new RuntimeException('simulated process death during mail');
    }
}
require_once testFindRepoRoot().'/plugins/autotools/move_queue.php';
eval(loadClassDefinition(testFindRepoRoot().'/plugins/autotools/move_job_lib.php','AutoToolsMoveJob'));
$suite=new StrictTestSuite();
$suite->test('mail death retains attempted phase and retry never sends twice',function(){
    $state=moveWorkerFixture();
    try{
        $job=moveWorkerSeed($state,'notice-pending','absent',true);
        $root=$state['root'].'/session/.autotools-file-jobs';rAutoTools::$root=$root;rAutoTools::$fail=true;rAutoTools::$calls=0;
        rXMLRPCRequest::queue('d.get_directory',true,false,array($job['daemon_dir']));
        try{AutoToolsMoveJob::deliverNotice($root,$state['hash']);}catch(RuntimeException $e){strictAssertSame('simulated process death during mail',$e->getMessage(),'cut reached');}
        rAutoTools::$fail=false;
        rXMLRPCRequest::queue('d.get_directory',true,false,array($job['daemon_dir']));
        rXMLRPCRequest::queue('d.autotools.move_finish',true,false,function()use($root){
            $files=glob($root.'/*.move.json');$saved=json_decode(file_get_contents($files[0]),true);
            strictAssertSame('completed',$saved['phase'],'completion durable before native finish');
            return array('finished');
        });
        AutoToolsMoveJob::deliverNotice($root,$state['hash']);
        strictAssertSame(1,rAutoTools::$calls,'mail is never repeated');
        strictAssertSame(array(),glob($root.'/*.move.json'),'completed journal retired');
    }finally{strictRemoveTree($state['root']);rXMLRPCRequest::reset();}
});
exit($suite->run());
