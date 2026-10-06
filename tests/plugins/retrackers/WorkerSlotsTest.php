<?php
require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../../plugins/retrackers/workers.php');

class WorkerSlotsTest extends TestCase
{
    private $root;
    public function setUp() {
        $this->root = sys_get_temp_dir() . '/rr-slots-' . getmypid();
        mkdir($this->root, 0700);
    }
    public function tearDown() {
        foreach (glob($this->root . '/*') as $pool) {
            if (is_file($pool) || is_link($pool)) { unlink($pool); continue; }
            foreach (glob($pool . '/*') as $file) unlink($file);
            rmdir($pool);
        }
        rmdir($this->root);
    }
    public function testOnlyConfiguredSlotsCanRunAndStableFilesAreReused() {
        $failure=null; $first=RetrackersWorkerSlots::acquire($this->root,'daemon',$failure,2,0);
        $second=RetrackersWorkerSlots::acquire($this->root,'daemon',$failure,2,0);
        $third=RetrackersWorkerSlots::acquire($this->root,'daemon',$failure,2,0);
        $this->assertTrue($first instanceof RetrackersWorkerSlots && $second instanceof RetrackersWorkerSlots &&
            $third === false && $failure === 'worker-slots-timeout', 'exactly two independent handles own two slots');
        $files=glob($this->root.'/*/*.lock'); $identity=array_map('fileinode',$files);
        if ($first) $first->release();
        $third=RetrackersWorkerSlots::acquire($this->root,'daemon',$failure,2,0);
        $this->assertTrue($third instanceof RetrackersWorkerSlots && array_map('fileinode',$files)===$identity,
            'a released slot is reused without replacing its stable lock inode');
        if ($second) $second->release(); if ($third) $third->release();
    }
    public function testUnsafePoolAndSymlinkLeafRefuseWithoutTouchingTarget() {
        $failure=null; $lease=RetrackersWorkerSlots::acquire($this->root,'daemon',$failure,1,0);
        $this->assertTrue($lease instanceof RetrackersWorkerSlots,'the safe private pool initializes');
        if (!$lease) return;
        $lease->release(); $pool=glob($this->root.'/*')[0]; $path=$pool.'/0.lock';
        unlink($path); file_put_contents($pool.'/target','preserved'); chmod($pool.'/target',0600);
        symlink($pool.'/target',$path);
        $this->assertTrue(RetrackersWorkerSlots::acquire($this->root,'daemon',$failure,1,0)===false &&
            file_get_contents($pool.'/target')==='preserved','a symlink slot cannot redirect lock ownership or truncate its target');
        unlink($path); chmod($pool,0777);
        $this->assertTrue(RetrackersWorkerSlots::acquire($this->root,'daemon',$failure,1,0)===false,
            'an unsafe pool is refused without chmod repair');
        chmod($pool,0700);
    }
    public function testConcurrentPhpWorkersNeverExceedTheSlotCount() {
        $script=$this->root.'/worker.php'; $events=$this->root.'/events';
        file_put_contents($script,'<?php require '.var_export(realpath(__DIR__.'/../../../plugins/retrackers/workers.php'),true).';'.
            '$failure=null; $lease=RetrackersWorkerSlots::acquire($argv[1],"multiprocess",$failure,2,3);'.
            'if (!$lease) exit(2); file_put_contents($argv[2],microtime(true)." +1\\n",FILE_APPEND|LOCK_EX);'.
            'usleep(100000); file_put_contents($argv[2],microtime(true)." -1\\n",FILE_APPEND|LOCK_EX);');
        $children=array();
        for($n=0;$n<8;$n++) {
            $children[]=proc_open(array(PHP_BINARY,$script,$this->root,$events),
                array(0=>array('file','/dev/null','r'),1=>array('file','/dev/null','w'),2=>array('file','/dev/null','w')),$pipes);
        }
        foreach($children as $child) $this->assertTrue(is_resource($child) && proc_close($child)===0,
            'each direct PHP worker finishes its own bounded slot wait');
        $rows=array_map(function($line){return explode(' ',trim($line));},file($events));
        usort($rows,function($a,$b){return (float)$a[0] <=> (float)$b[0];});
        $active=0; $peak=0;
        foreach($rows as $row){$active+=(int)$row[1];$peak=max($peak,$active);}
        $this->assertTrue(count($rows)===16 && $active===0 && $peak===2,
            'eight independent PHP processes share exactly two concurrent RPC slots');
    }
}
