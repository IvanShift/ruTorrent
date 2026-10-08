<?php

// Execute copied production init/worker bytes against a physical queue. Only
// RPC and Ratio's unrelated group configuration are replaced by recording peers.
class ErasedataQueueRefusalFixture
{
    public static function run($base, $route, $ready, $removeFails = false, $user = 'alice', $interval = 60)
    {
        $root = dirname(__DIR__, 3);
        $tree = $base.'/queue-refusal-'.bin2hex(random_bytes(5));
        foreach(array('plugins/erasedata', 'plugins/rutracker_check', 'plugins/ratio', 'php/utility', 'settings', 'session') as $name)
            mkdir($tree.'/'.$name, 0700, true);
        foreach(array('plugins/erasedata/init.php', 'plugins/erasedata/update.php',
            'plugins/erasedata/filesystem.php', 'plugins/erasedata/manifest.php',
            'plugins/erasedata/pending.php', 'plugins/erasedata/presence.php',
            'plugins/erasedata/removewithdata.php',
            'plugins/erasedata/collector.php', 'plugins/rutracker_check/init.php',
            'plugins/rutracker_check/done.php', 'plugins/ratio/init.php', 'php/utility/hashfault.php') as $name)
        {
            copy($root.'/'.$name, $tree.'/'.$name);
            if(hash_file('sha256', $root.'/'.$name) !== hash_file('sha256', $tree.'/'.$name))
                throw new RuntimeException('queue refusal mirror identity mismatch: '.$name);
        }
        if(!$ready)
        {
            mkdir($tree.'/settings/erasedata', 0700);
            file_put_contents($tree.'/settings/erasedata/old.list', 'durable deletion obligation');
            chmod($tree.'/settings', 0777);
        }
        file_put_contents($tree.'/plugins/ratio/ratio.php', '<?php class rRatio {'
            .'public static function load(){return new self();} public function obtain(){return true;}'
            .'public function get(){return "ratio-ready";}}');
        file_put_contents($tree.'/php/nativehelpers.php', '<?php class NativeHelpers {'
            .'public static function path($name){return PHP_BINARY;}}');
        file_put_contents($tree.'/php/xmlrpc.php', <<<'PHP'
<?php
class FileUtil {
 public static $log=array();
 public static function getSettingsPath(){return __DIR__.'/../settings';}
 public static function getProfilePath(){return dirname(__DIR__);}
 public static function getPluginConf($name){return $name==='erasedata'
  ? '$garbageCheckInterval=15;$enableForceDeletion=true;$replaceRemoveTorrent=true;'
  : '$updateInterval='.(int)getenv('CHECK_INTERVAL').';';}
 public static function toLog($message){self::$log[]=$message;}
 public static function addslash($path){return rtrim($path,'/').'/';}
 public static function makeDirectory($path,$mode,$preserve){return is_dir($path)||mkdir($path,$mode,true);}
}
class Utility { public static function getPHP(){return PHP_BINARY;} }
class User { public static function getUser(){return json_decode(getenv('QUEUE_USER_JSON'),true);} }
class rXMLRPCCommand {
 public $command;public $params;
 public function __construct($command,$params=null){$this->command=$command;$this->params=$params;}
}
class rXMLRPCRequest {
 public static $requests=array();public static $schedules=array();public $fault=false;public $important=true;
 private $commands;
 public function __construct($commands){$this->commands=is_array($commands)?$commands:array($commands);}
 public function run(){
  self::$requests[]=$this->commands;
  foreach($this->commands as $command){
   $params=is_array($command->params)?$command->params:array($command->params);
   if($command->command==='schedule_remove'){
    if(getenv('REMOVE_FAILS')==='1'){$this->fault=true;return false;}
    unset(self::$schedules[$params[0]]);
   }elseif($command->command==='schedule')self::$schedules[$params[0]]=$params;
  }
  return true;
 }
 public function success(){return $this->run()&&!$this->fault;}
}
class rTorrentSettings {
 public $session;public $registered=array();
 public function __construct(){$this->session=__DIR__.'/../session';}
 public static function get(){global $theSettings;return $theSettings;}
 public function getRemoveScheduleCommand($name){return new rXMLRPCCommand('schedule_remove',$name.User::getUser());}
 public function getScheduleCommand($name,$interval,$command){return new rXMLRPCCommand('schedule',array($name.User::getUser(),$interval,$command));}
 public function getAlignedScheduleCommand($name,$interval,$command){return $this->getScheduleCommand($name,$interval,$command);}
 public function registerPlugin($name,$perms){$this->registered[]=$name;}
 public function isPluginRegistered($name){return in_array($name,$this->registered,true);}
}
function getCmd($name){return $name;}
PHP
        );
        file_put_contents($tree.'/driver.php', <<<'PHP'
<?php
require __DIR__.'/php/xmlrpc.php';
$user=User::getUser();
rXMLRPCRequest::$schedules=array('erasedata'.$user=>'stale-own','erasedataother'=>'other-user',
 'erasedata-drain'.$user=>'durable-drain','rutracker_check'.$user=>'checker');
$theSettings=new rTorrentSettings();$plugin=array('name'=>getenv('QUEUE_ROUTE'));
$pInfo=array('perms'=>array());$jResult='';
if($plugin['name']==='worker'){
 require __DIR__.'/plugins/erasedata/update.php';
 erasedataCollectorMain(new ErasedataFilesystemOps());
}else{
 chdir(__DIR__.'/plugins/'.$plugin['name']);require 'init.php';
}
$queue=__DIR__.'/settings/erasedata';
echo json_encode(array('requests'=>rXMLRPCRequest::$requests,'schedules'=>rXMLRPCRequest::$schedules,
 'registered'=>$theSettings->registered,'javascript'=>$jResult,'log'=>FileUtil::$log,
 'queue_exists'=>is_dir($queue),'intent'=>is_file($queue.'/old.list')?file_get_contents($queue.'/old.list'):null));
PHP
        );
        $process=proc_open(array(PHP_BINARY,'-c',$root.'/tests/php-test.ini',$tree.'/driver.php'),
            array(0=>array('file','/dev/null','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,
            array_merge($_ENV,array('QUEUE_ROUTE'=>$route,'QUEUE_USER_JSON'=>json_encode($user),
                'REMOVE_FAILS'=>$removeFails?'1':'0','CHECK_INTERVAL'=>(string)$interval)));
        if(!is_resource($process))throw new RuntimeException('queue refusal child unavailable');
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
        $data=json_decode($out,true);
        if($code!==0||!is_array($data))throw new RuntimeException('queue refusal child failed: '.$err.$out);
        return $data;
    }
}
