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
// POST-1011-01: run() renames the journal into place, then deliverNotice()
// reads it back. On drvfs over 9p that new name answered ENOENT once; read as
// absent, the worker exited without its notice or native finish.
require_once __DIR__.'/SettlingRenameFiles.php';
$suite->test('a journal whose new name answers late still delivers its notice once',function(){
    $state=moveWorkerFixture();
    try{
        $job=moveWorkerSeed($state,'notice-pending','absent',true);
        $root=$state['root'].'/session/.autotools-file-jobs';rAutoTools::$root=$root;rAutoTools::$fail=false;rAutoTools::$calls=0;
        $path=$root.'/'.$state['hash'].'.move.json';$files=new SettlingRenameFiles('/^$/D',3);
        strictAssertSame(true,$files->hide($path),'the renamed journal answers ENOENT');
        strictSetPrivateStatic('AutoToolsMoveJob','files',$files);
        rXMLRPCRequest::queue('d.get_directory',true,false,array($job['daemon_dir']));
        rXMLRPCRequest::queue('d.autotools.move_finish',true,false,array('finished'));
        AutoToolsMoveJob::deliverNotice($root,$state['hash']);
        strictAssertSame(1,rAutoTools::$calls,'the completion mail is sent once');
        strictAssertSame(array($path=>3),$files->lookups,'the journal is confirmed on its third lookup');
        strictAssertSame(array(),glob($root.'/*.move.json'),'the completed journal retires');
        strictAssertSame(array(),glob($root.'/.settling-*'),'nothing is left hidden');
    }finally{strictSetPrivateStatic('AutoToolsMoveJob','files',null);strictRemoveTree($state['root']);rXMLRPCRequest::reset();}
});
exit($suite->run());
