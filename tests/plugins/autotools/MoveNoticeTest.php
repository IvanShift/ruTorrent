<?php
require_once(__DIR__.'/../../../php/utility/json.php');
// Extracted classes omit the production bootstrap which supplies Utility.
require_once(__DIR__.'/../../../php/utility/utility.php');
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

$suite->test('large byte receipts are released before notice and next-job readback',function(){
    $root=sys_get_temp_dir().'/move-large-'.bin2hex(random_bytes(5));mkdir($root,0700);
    try {
        $script=<<<'SCRIPT'
require 'tests/plugins/rutracker_check/TestLib.php';
require 'php/utility/json.php';
require 'plugins/erasedata/filesystem.php';
class FileUtil { public static $log=array(); public static function toLog($line){self::$log[]=$line;} }
class AutoToolsFileTransaction {public static function journalRoot($required=false){return getenv('JOURNAL_ROOT');}}
class AutoToolsMoveQueue {
    public static function state($hash){return array('phase'=>'leased');}
    public static function owns($state,$qid,$user){return true;}
}
eval(loadClassDefinition(testFindRepoRoot().'/plugins/autotools/move_job_lib.php','AutoToolsMoveJob'));
$root=getenv('JOURNAL_ROOT');$hash=str_repeat('A',40);
$job=array('version'=>2,'qid'=>str_repeat('a',32),'kind'=>'Move','hash'=>$hash,
    'phase'=>'notice-pending','src'=>'/source','dst'=>'/destination','identity'=>'1:2','daemon_dir'=>'/destination',
    'files'=>array(),'receipt'=>array('files'=>array(),'dirs'=>array()));
for($i=0;$i<60000;$i++) {
    $path=str_repeat('x',80).$i."\xFF";$job['files'][]=$path;
    $job['receipt']['files'][$path]=array('id'=>'12345678:123456789','hash'=>str_repeat('a',64),'size'=>100000);
}
AutoToolsMoveJob::writeJob($root,$job);
$job['hash']=str_repeat('B',40);AutoToolsMoveJob::writeJob($root,$job);unset($job);
rXMLRPCRequest::queue('d.get_directory',true,false,array('/changed'));
$status=AutoToolsMoveJob::run($hash,str_repeat('a',32),'tester',str_repeat('c',32));
AutoToolsMoveJob::recoverCompleted();
echo json_encode(array('status'=>$status,'classified'=>count(FileUtil::$log)===1
    &&strpos(FileUtil::$log[0],'completed-state-changed')!==false,'journals'=>count(glob($root.'/*.move.json'))));
SCRIPT;
        $process=proc_open(array(PHP_BINARY,'-d','memory_limit=128M','-r',$script),
            array(0=>array('file','/dev/null','r'),1=>array('pipe','w'),2=>array('pipe','w')),
            $pipes,testFindRepoRoot(),array_merge($_ENV,array('JOURNAL_ROOT'=>$root)));
        if(!is_resource($process))throw new RuntimeException('large receipt child unavailable');
        $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
        strictAssertSame(0,$status,'large readback reaches the owner without exhausting 128 MiB: '.$errors);
        strictAssertSame('', $errors,'no PHP memory or decode failure');
        strictAssertSame(array('status'=>1,'classified'=>true,'journals'=>2),json_decode($output,true),
            'notice validates the current directory and recovery retains both unresolved jobs');
    } finally {strictRemoveTree($root);}
});

exit($suite->run());
