<?php

require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/CollectorFixture.php');

// Only the capability answer is injected; the real rename implementation must
// refuse before its filesystem mutation when a parent lacks atomic support.
class ErasedataUnavailableRenameCapability extends ErasedataFilesystemOps
{
    protected function renameCapability($directory)
    {
        FileUtil::toLog('rename-noreplace-unsupported path='.$directory);
        return(false);
    }
}

// Admission executes against real queue/witness bytes. RPC is isolated below
// the actual copied init.php boundary and records the schedules it receives.
class QueueAdmissionDiagnosticsTest extends TestCase
{
    private $dir;

    public function setUp()
    {
        $this->dir = sys_get_temp_dir().'/eq-'.getmypid().'-'.uniqid();
        mkdir($this->dir, 0700);
        chmod($this->dir, 0700);
        FileUtil::$log = array();
    }

    public function tearDown()
    {
        $this->remove($this->dir);
    }

    private function remove($path)
    {
        if(is_dir($path) && !is_link($path))
        {
            foreach(array_diff(scandir($path), array('.', '..')) as $name)
                $this->remove($path.'/'.$name);
            rmdir($path);
        }
        else
            unlink($path);
    }

    public function testWritableQueueRefusalNamesPhysicalAncestorAndRecovery()
    {
        mkdir($this->dir.'/share', 0700);
        mkdir($this->dir.'/share/settings', 0777);
        chmod($this->dir.'/share/settings', 0777);
        mkdir($this->dir.'/share/settings/erasedata', 0700);
        file_put_contents($this->dir.'/share/settings/erasedata/old.list', 'unattested intent');
        symlink($this->dir.'/share', $this->dir.'/alias');
        $this->assertTrue(!erasedataEnsureQueueDirectory($this->dir.'/alias/settings/erasedata'),
            'unattested writable ancestry refuses the old queue');
        $log = implode("\n", FileUtil::$log);
        $this->assertTrue(strpos($log, $this->dir.'/share/settings') !== false,
            'refusal names the physical writable ancestor');
        $this->assertTrue(strpos($log, '0777') !== false && strpos($log, '0700') !== false,
            'refusal reports actual ancestor and required queue modes');
        $this->assertTrue(strpos($log, 'stop services') !== false && strpos($log, 'operator attestation') !== false,
            'refusal states the required offline operator action');
        $this->assertEquals('unattested intent', file_get_contents($this->dir.'/share/settings/erasedata/old.list'),
            'refusal preserves the old obligation');
        $this->assertEquals(0777, fileperms($this->dir.'/share/settings') & 07777,
            'admission never blesses hostile legacy ancestry');
    }

    private function protectedProfile()
    {
        mkdir($this->dir.'/share', 0700);
        mkdir($this->dir.'/share/settings', 0700);
        mkdir($this->dir.'/erasedata-jobs', 0700);
        file_put_contents($this->dir.'/.erasedata-first-use', '');
        chmod($this->dir.'/.erasedata-first-use', 0400);
        FileUtil::$settingsPath = $this->dir.'/share/settings';
    }

    public function testWitnessOwnerRefusalNamesTheWitnessAndExpectedIdentity()
    {
        $this->protectedProfile();
        if(erasedataEffectiveUid() === 0)
            chown($this->dir.'/.erasedata-first-use', 65534);
        $this->assertTrue(erasedataQueuePath() === false, 'service-owned witness cannot establish first use');
        $log = implode("\n", FileUtil::$log);
        $this->assertTrue(strpos($log, $this->dir.'/.erasedata-first-use') !== false,
            'witness refusal names the actual marker');
        $this->assertTrue(strpos($log, 'witness-owner') !== false && strpos($log, 'uid=0') !== false,
            'witness refusal identifies the owner contract');
    }

    public function testWitnessModeAndHardlinkRefusalsAreDistinct()
    {
        if(erasedataEffectiveUid() !== 0)
        {
            $this->assertTrue(true, 'root-only witness mode and link cases are exercised in the disposable image');
            return;
        }
        $this->protectedProfile();
        chmod($this->dir.'/.erasedata-first-use', 0644);
        $this->assertTrue(erasedataQueuePath() === false, 'writable witness refuses admission');
        $this->assertTrue(strpos(implode("\n", FileUtil::$log), 'witness-mode') !== false,
            'mode refusal has its own classified reason');
        FileUtil::$log = array();
        chmod($this->dir.'/.erasedata-first-use', 0400);
        link($this->dir.'/.erasedata-first-use', $this->dir.'/second-witness');
        $this->assertTrue(erasedataQueuePath() === false, 'multiply linked witness refuses admission');
        $this->assertTrue(strpos(implode("\n", FileUtil::$log), 'witness-links') !== false
            && strpos(implode("\n", FileUtil::$log), 'nlink=2') !== false,
            'link refusal reports actual link count');
    }

    public function testRenameCapabilityRefusalNamesTheActualParent()
    {
        $missing = $this->dir.'/missing-parent';
        $this->assertTrue(!ErasedataFilesystemOps::canRenameNoReplace($missing),
            'an unavailable actual parent refuses rename capability before capture');
        $hasReason = method_exists('ErasedataFilesystemOps', 'lastRenameRefusal');
        $this->assertTrue($hasReason, 'rename capability exposes a classified refusal');
        if($hasReason)
            $this->assertTrue(ErasedataFilesystemOps::lastRenameRefusal() !== '',
                'rename capability records an operator-visible reason');
        $this->assertTrue(strpos(implode("\n", FileUtil::$log), $missing) !== false,
            'capability refusal names the actual parent without inferring another mount');
    }

    public function testUnavailableCapabilityRefusesBeforeCapture()
    {
        $source=$this->dir.'/payload'; $target=$this->dir.'/captured';
        file_put_contents($source, 'original payload');
        $filesystem=new ErasedataUnavailableRenameCapability();
        $this->assertTrue(!$filesystem->rename($source,$target),
            'unsupported actual parent refuses capture before its rename');
        $this->assertTrue(is_file($source) && !file_exists($target),
            'refused capability leaves both names unchanged');
        if(is_file($source))$this->assertEquals('original payload',file_get_contents($source),
            'refused capture preserves exact source bytes');
        $this->assertTrue(strpos(implode("\n",FileUtil::$log),$this->dir)!==false,
            'pre-capture refusal names the actual parent');
    }

    public function testNativeProbeAndCollisionPreserveBothEntries()
    {
        if(!ErasedataFilesystemOps::canRenameNoReplace())
        {
            $this->assertTrue(ErasedataFilesystemOps::lastRenameRefusal() !== '',
                'optional native helper absence is explicit in a source-only installation');
            return;
        }
        $this->assertTrue(ErasedataFilesystemOps::canRenameNoReplace($this->dir),
            'installed native helper proves the actual parent filesystem');
        $source=$this->dir.'/source'; $target=$this->dir.'/occupied';
        file_put_contents($source,'source bytes');file_put_contents($target,'destination bytes');
        $filesystem=new ErasedataFilesystemOps();
        $this->assertTrue(!$filesystem->renameNoReplace($source,$target),
            'no-replace refuses an occupied destination');
        $this->assertEquals('source bytes',file_get_contents($source),'collision preserves source bytes');
        $this->assertEquals('destination bytes',file_get_contents($target),'collision preserves destination bytes');
        $this->assertEquals('rename-noreplace-collision',ErasedataFilesystemOps::lastRenameRefusal(),
            'native collision retains its classified reason');
        unlink($target);
        $this->assertTrue($filesystem->renameNoReplace($source,$target),
            'supported native filesystem performs the unoccupied rename');
        $this->assertTrue(!file_exists($source),'successful rename releases the old name');
        $this->assertEquals('source bytes',file_get_contents($target),'successful rename keeps exact source bytes');
        $this->assertEquals(array(),glob($this->dir.'/.rutorrent-rename-probe-*'),
            'all actual-parent capability probes remove their private artifacts');
    }

    public function testNativeRestoreUsesTheParentsHeldDirectoryDescriptor()
    {
        if(!ErasedataFilesystemOps::canRenameNoReplace())
        {
            $this->assertTrue(ErasedataFilesystemOps::lastRenameRefusal() !== '',
                'optional native helper absence is explicit in a source-only installation');
            return;
        }
        $filesystem=new ErasedataFilesystemOps();
        $capability=$filesystem->acquireDirectoryCapability($this->dir,$filesystem->targetIdentity($this->dir));
        $this->assertTrue(is_array($capability),'real Linux directory capability is acquired');
        if(!is_array($capability))return;
        try
        {
            file_put_contents($this->dir.'/captured','descriptor payload');
            $this->assertTrue($filesystem->renameNoReplace($capability['path'].'/captured',
                $capability['path'].'/restored'),
                'native child restores through the parent process held descriptor');
            $this->assertTrue(!file_exists($this->dir.'/captured'),'native descriptor rename releases the original name');
            $this->assertEquals('descriptor payload',file_get_contents($this->dir.'/restored'),
                'native descriptor restore preserves exact bytes');
            $this->assertEquals(array(),glob($this->dir.'/.rutorrent-rename-probe-*'),
                'descriptor-parent probes remove all private artifacts');
        }
        finally{$filesystem->releaseDirectoryCapability($capability);}
    }

    private function runInit($ready, $interval)
    {
        $root = dirname(__DIR__, 3);
        $tree = $this->dir.'/mirror';
        mkdir($tree.'/plugins/rutracker_check', 0700, true);
        mkdir($tree.'/plugins/erasedata', 0700, true);
        mkdir($tree.'/php', 0700);
        mkdir($tree.'/settings', 0700);
        mkdir($tree.'/session', 0700);
        if(!$ready)
        {
            chmod($tree.'/settings', 0777);
            mkdir($tree.'/settings/erasedata', 0700);
            file_put_contents($tree.'/settings/erasedata/old.list', 'unattested intent');
        }
        foreach(array('plugins/rutracker_check/init.php', 'plugins/rutracker_check/done.php',
            'plugins/erasedata/filesystem.php', 'plugins/erasedata/manifest.php',
            'plugins/erasedata/pending.php', 'plugins/erasedata/removewithdata.php') as $relative)
        {
            copy($root.'/'.$relative, $tree.'/'.$relative);
            $this->assertEquals(hash_file('sha256', $root.'/'.$relative), hash_file('sha256', $tree.'/'.$relative),
                'init admission mirror executes the shipped '.$relative.' bytes');
        }
        file_put_contents($tree.'/php/xmlrpc.php', <<<'PHP'
<?php
class FileUtil {
 public static $log=array();
 public static function getSettingsPath(){return getenv('QUEUE_SETTINGS');}
 public static function getPluginConf($name){return $name==='erasedata' ? '$garbageCheckInterval=15;' : '$updateInterval='.(int)getenv('CHECK_INTERVAL').';';}
 public static function toLog($message){self::$log[]=$message;}
 public static function addslash($path){return rtrim($path,'/').'/';}
 public static function makeDirectory($path,$mode,$preserve){return is_dir($path)||mkdir($path,$mode,true);}
}
class Utility { public static function getPHP(){return PHP_BINARY;} }
class User { public static function getUser(){return '';} }
class rXMLRPCCommand {
 public $command; public $params;
 public function __construct($command,$params=null){$this->command=$command;$this->params=$params;}
}
class rXMLRPCRequest {
 public static $requests=array(); private $commands;
 public function __construct($commands){$this->commands=is_array($commands)?$commands:array($commands);}
 public function run(){self::$requests[]=$this->commands; return count($this->commands)>0;}
 public function success(){return $this->run();}
}
class rTorrentSettings {
 public $session; public $registered=array();
 public function __construct(){$this->session=getenv('QUEUE_SESSION');}
 public static function get(){global $theSettings;return $theSettings;}
 public function getRemoveScheduleCommand($name){return new rXMLRPCCommand('schedule.remove',array($name));}
 public function getScheduleCommand($name,$interval,$command){return new rXMLRPCCommand('schedule',array($name,$interval,$command));}
 public function getAlignedScheduleCommand($name,$interval,$command){return $this->getScheduleCommand($name,$interval,$command);}
 public function registerPlugin($name,$perms){$this->registered[]=$name;}
}
function getCmd($name){return $name;}
PHP
        );
        file_put_contents($tree.'/driver.php', <<<'PHP'
<?php
require __DIR__.'/php/xmlrpc.php';
$theSettings=new rTorrentSettings(); $plugin=array('name'=>'rutracker_check');
$pInfo=array('perms'=>array());$jResult='';
chdir(__DIR__.'/plugins/rutracker_check');
require 'init.php';
echo json_encode(array('requests'=>rXMLRPCRequest::$requests,'registered'=>$theSettings->registered,
 'javascript'=>$jResult,'log'=>FileUtil::$log));
PHP
        );
        $process=proc_open(array(PHP_BINARY,'-c',$root.'/tests/php-test.ini',$tree.'/driver.php'),
            array(0=>array('file','/dev/null','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,
            array_merge($_ENV,array('QUEUE_SETTINGS'=>$tree.'/settings','QUEUE_SESSION'=>$tree.'/session','CHECK_INTERVAL'=>(string)$interval)));
        $this->assertTrue(is_resource($process), 'isolated real init child starts');
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
        $this->assertEquals(0,$code,'real init child completes: '.$err.$out);
        $data=json_decode($out,true);
        $this->assertTrue(is_array($data), 'real init reports its observable schedules');
        return $data;
    }

    public function testRejectedQueueAndZeroCheckerIntervalDoNotDisableChecker()
    {
        $data=$this->runInit(false,0);
        $this->assertTrue(is_array($data), 'init result remains inspectable');
        if(!is_array($data))return;
        $this->assertEquals(array('rutracker_check'),$data['registered'], 'refused cleanup does not disable an otherwise idle checker');
        $this->assertEquals('', $data['javascript'], 'idle checker emits no misleading failure');
        $this->assertEquals(1,count($data['requests']), 'only stale checker schedule removal is sent');
        $this->assertEquals('schedule.remove',$data['requests'][0][0]['command'], 'refused collector schedule is never sent');
        $this->assertEquals(1,count($data['log']), 'one admission refusal is logged per init');
    }

    public function testAcceptedQueueStillSchedulesCleanupWithZeroCheckerInterval()
    {
        $data=$this->runInit(true,0);
        if(!is_array($data))return;
        $this->assertEquals(array('rutracker_check'),$data['registered'], 'accepted queue keeps the checker registered');
        $names=array();
        foreach($data['requests'] as $request)foreach($request as $command)
            if($command['command']==='schedule')$names[]=$command['params'][0];
        $this->assertEquals(array('erasedata'),$names, 'accepted collector stays independent of the hourly checker interval');
    }
}
