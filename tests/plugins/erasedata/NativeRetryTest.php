<?php
require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/CollectorFixture.php');
require_once(__DIR__.'/../../../plugins/erasedata/collector.php');

class ErasedataNativeRetryTest extends TestCase
{
	private $dir;
	public function setUp()
	{
		global $profileMask;$profileMask=0700;
		$this->dir=sys_get_temp_dir().'/ed-retry-'.bin2hex(random_bytes(6));mkdir($this->dir,0700);
		FileUtil::$log=array();
	}
	public function tearDown()
	{
		$this->removeTree($this->dir);
	}
	private function removeTree($path)
	{
		if(is_file($path)||is_link($path)){unlink($path);return;}
		if(!is_dir($path))return;
		foreach(array_diff(scandir($path),array('.','..')) as $name)$this->removeTree($path.'/'.$name);
		rmdir($path);
	}
	private function api()
	{
		$ready=function_exists('erasedataNativeRetryRecordLocked');
		$this->assertTrue($ready,'native retries share bounded advisory state');return $ready;
	}
	private function state()
	{
		$state=erasedataDefaultDrainState();$state['user']='rutorrent';
		$state['generation']=$state['acknowledged']='0000000000000001';$state['phase']='armed';
		erasedataWriteDrainState($this->dir,$state);return $state;
	}
	public function testRetryHintsDoNotChangeAuthorityAndResetOnReasonIdentityOrClockChange()
	{
		if(!$this->api())return;$original=$this->state();
		$key=str_repeat('a',64);$path=$this->dir.'/'.str_repeat('A',40).'.0000000000000001.actor.list';
		$lock=erasedataAcquireHashLock($this->dir,str_repeat('A',40));
		try
		{
			$this->assertTrue(erasedataNativeRetryRecordLocked($this->dir,'rutorrent',$key,$path,'unsafe-path',100),
				'the first classified failure records only an advisory hint');
			$state=erasedataReadDrainState($this->dir);$retry=$state['native_retry'];unset($state['native_retry']);
			$this->assertEquals($original,$state,'journal, user, phase, generation and acknowledgment are unchanged');
			$this->assertTrue(!erasedataNativeRetryDue($retry,$key,101),'unchanged failure backs off a fresh process');
			$this->assertTrue(erasedataNativeRetryDue($retry,$key,99),'backward wall clock cannot hold a job indefinitely');
			$this->assertTrue(erasedataNativeRetryDue($retry,str_repeat('b',64),101),'a changed exact identity cannot inherit suppression');
			$this->assertEquals(1,count(FileUtil::$log),'the first failure is visible');
			erasedataNativeRetryRecordLocked($this->dir,'rutorrent',$key,$path,'unsafe-path',106);
			$this->assertEquals(1,count(FileUtil::$log),'same failure is not logged again by a later worker');
			erasedataNativeRetryRecordLocked($this->dir,'rutorrent',$key,$path,'rename-noreplace-permission-denied',117);
			$this->assertEquals(2,count(FileUtil::$log),'a different classified reason is visible immediately');
			for($now=200;$now<1500;$now+=61)
				erasedataNativeRetryRecordLocked($this->dir,'rutorrent',$key,$path,'rename-noreplace-permission-denied',$now);
			$this->assertTrue(erasedataReadDrainState($this->dir)['native_retry']['jobs'][$key][1]<=60,
				'backoff always permits another attempt within sixty seconds');
		}
		finally {erasedataReleaseHashLock($lock);}
	}
	public function testRetryMetadataNeverClaimsMissingCorruptOrAnotherUsersState()
	{
		if(!$this->api())return;$path=$this->dir.'/intent.list';$key=str_repeat('a',64);
		$statePath=erasedataDrainStatePath($this->dir);
		$this->assertTrue(!erasedataNativeRetryRecordLocked($this->dir,'rutorrent',$key,$path,'unsafe-path',100)
			&& !file_exists($statePath),'an absent state is not created or claimed for a log hint');
		file_put_contents($statePath,'corrupt');chmod($statePath,0600);
		$this->assertTrue(!erasedataNativeRetryRecordLocked($this->dir,'rutorrent',$key,$path,'unsafe-path',100)
			&& file_get_contents($statePath)==='corrupt','corrupt authority bytes remain unchanged');
		$this->state();$before=file_get_contents($statePath);
		$this->assertTrue(!erasedataNativeRetryRecordLocked($this->dir,'other',$key,$path,'unsafe-path',100)
			&& file_get_contents($statePath)===$before,'another user cannot update hints or authority');
	}
	public function testOverflowEvictsOnlyHintsAndTheCursorGivesLaterJobsTheirTurn()
	{
		if(!$this->api())return;$original=$this->state();$state=$original;
		$state['native_retry']=array('jobs'=>array(),'cursor'=>'','overflow'=>false);
		for($i=0;$i<ERASEDATA_NATIVE_RETRY_MAX_HINTS;$i++)
			$state['native_retry']['jobs'][hash('sha256',(string)$i)]=array(100,60,1,hash('sha256','unsafe-path'));
		$this->assertTrue(erasedataWriteDrainState($this->dir,$state),'the bounded hint capacity is admitted');
		$key=hash('sha256','overflow');
		$this->assertTrue(erasedataNativeRetryRecordLocked($this->dir,'rutorrent',$key,$this->dir.'/last.list','unsafe-path',101),
			'overflow remains retryable without enlarging the authority ceiling');
		$after=erasedataReadDrainState($this->dir);$retry=$after['native_retry'];unset($after['native_retry']);
		$this->assertEquals($original,$after,'overflow evicts no authority or obligation');
		$this->assertTrue(count($retry['jobs'])===ERASEDATA_NATIVE_RETRY_MAX_HINTS && isset($retry['jobs'][$key]),
			'only an old advisory hint is evicted');
		$this->assertTrue(strpos(implode("\n",FileUtil::$log),'hint-capacity')!==false,'overflow is classified and visible');
		$hashes=array();for($i=0;$i<1033;$i++)$hashes[]=strtoupper(str_pad(dechex($i+1),40,'0',STR_PAD_LEFT));
		$seen=array();$cursor='';
		for($pass=0;$pass<130;$pass++)
		{
			$order=erasedataNativeRetryOrder($hashes,$cursor,100+$pass*5);
			foreach(array_slice($order,0,8) as $hash){$seen[$hash]=true;$cursor=$hash;}
		}
		$this->assertEquals(1033,count($seen),'more than the hint capacity receives bounded fair attempts');
		$fallback=array();
		for($pass=0;$pass<1033;$pass++)
			foreach(array_slice(erasedataNativeRetryOrder($hashes,'',100+$pass*5),0,8) as $hash)$fallback[$hash]=true;
		$this->assertEquals(1033,count($fallback),'missing or corrupt cursor state cannot permanently starve later jobs');
		global $garbageCheckInterval;
		foreach(array(60,120) as $interval)
		{
			$garbageCheckInterval=$interval;$seen=array();$small=array_slice($hashes,0,24);
			for($pass=0;$pass<24;$pass++)
				foreach(array_slice(erasedataNativeRetryOrder($small,'',$pass*$interval),0,8) as $hash)$seen[$hash]=true;
			$this->assertEquals(24,count($seen),'aligned configurable '.$interval.' second ticks do not skip a window');
		}
		unset($GLOBALS['garbageCheckInterval']);
	}



	public function testTerminalDiscoverySharesClassifiedRetryMemoryAcrossFreshCollectors()
	{
		if(!$this->api())return;
		foreach(array('mode','unknown','capability','capability-list') as $case)
		{
			$this->state();$hash=str_repeat('A',40);$g='0000000000000001';$payload=$this->dir.'/payload';file_put_contents($payload,'kept');
			$paths=array('base'=>$payload,'multi'=>false,'files'=>array($payload));
			$intent=array('incarnation'=>str_repeat('a',32),'nonce'=>str_repeat('b',32),'generation'=>$g,
				'user'=>'rutorrent','digest'=>hash('sha256',ErasedataManifestCodec::encode($hash,$paths,1)));
			$path=$this->dir.'/'.$hash.'.'.$g.'.actor.list'.($case==='capability-list'?'':'.completed');
			file_put_contents($path,ErasedataManifestCodec::encodeBound($hash,$paths,1,$intent));chmod($path,$case==='mode'?0644:0600);
			$script=$this->dir.'/terminal-child.php';
			file_put_contents($script,'<?php require '.var_export(__DIR__.'/CollectorFixture.php',true).';'
				.'require '.var_export(__DIR__.'/../../../plugins/erasedata/collector.php',true).';$profileMask=0700;'
				.'rXMLRPCRequest::$responses=array("fixture.nativeCapability"=>strpos($argv[2],"capability")===0?"unconfirmed":"available");'
				.'$service=new ErasedataCollector(new ErasedataFilesystemOps(),null,null,null,true);$service->run($argv[1]);'
				.'echo json_encode(array("log"=>FileUtil::$log,"calls"=>rXMLRPCRequest::$requested));');
			for($pass=0;$pass<2;$pass++)
			{
				$process=proc_open(array(PHP_BINARY,'-c',__DIR__.'/../../php-test.ini',$script,$this->dir,$case),
					array(0=>array('file','/dev/null','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
				if(!is_resource($process))throw new RuntimeException('terminal child unavailable');
				$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
				$data=json_decode($out,true);$this->assertTrue($exit===0&&$err===''&&is_array($data),$case.' terminal child exits cleanly');
				if(!is_array($data))throw new RuntimeException('terminal child reply invalid');
				$reason=$case==='mode'?'manifest-mode actual=0644 required=0600':($case==='unknown'?'rpc-unknown':'native-capability-unconfirmed');
				$this->assertTrue($pass===0 ? strpos(implode("\n",$data['log']),$reason)!==false : $data['log']===array(),
					$case.' terminal first refusal is precise and the next fresh pass stays quiet');
				$this->assertTrue(!in_array('system.erasedata.collect_start',$data['calls'],true),$case.' terminal refusal never launches or replays payload work');
			}
			$after=erasedataReadDrainState($this->dir);
			$this->assertTrue(is_file($path)&&is_file($payload)&&isset($after['native_retry'])&&count($after['native_retry']['jobs'])===1,
				$case.' terminal bytes remain inert with one advisory identity');
			unlink($path);unlink($payload);unlink($script);
		}
	}

	public function testFreshCollectorsCountEveryLaunchResultAndRotateAcrossListsAndCompletedRecords()
	{
		if(!$this->api())return;$original=$this->state();$g=$original['generation'];$hashes=array();
		for($i=1;$i<=24;$i++)
		{
			$hash=strtoupper(str_pad(dechex($i),40,'0',STR_PAD_LEFT));$hashes[]=$hash;
			$payload=$this->dir.'/payload-'.$i;file_put_contents($payload,'kept');
			$paths=array('base'=>$payload,'multi'=>false,'files'=>array($payload));
			$intent=array('incarnation'=>str_repeat('a',32),'nonce'=>str_repeat('b',32),'generation'=>$g,
				'user'=>'rutorrent','digest'=>hash('sha256',ErasedataManifestCodec::encode($hash,$paths,1)));
			$path=$this->dir.'/'.$hash.'.'.$g.'.actor.list'.($i%2 ? '' : '.completed');
			file_put_contents($path,ErasedataManifestCodec::encodeBound($hash,$paths,1,$intent));chmod($path,0600);
		}
		$script=$this->dir.'/quota-child.php';
		file_put_contents($script,'<?php require '.var_export(__DIR__.'/CollectorFixture.php',true).';'
			.'require '.var_export(__DIR__.'/../../../plugins/erasedata/collector.php',true).';$profileMask=0700;'
			.'rXMLRPCRequest::$responses=array("fixture.nativeCapability"=>"available",'
			.'"system.erasedata.state"=>array("responseTypes"=>array("struct"),"responseValues"=>array(array("phase"=>"committed","live_relation"=>"absent"))),'
			.'"system.erasedata.collect_start"=>array("val"=>array($argv[2])));'
			.'$service=new ErasedataCollector(new ErasedataFilesystemOps(),function(){throw new Exception("presence fallback");},'
			.'function(){throw new Exception("path fallback");},null,true);$service->run($argv[1]);$hashes=array();'
			.'foreach(rXMLRPCRequest::$commandCalls as $call)if($call[0]->command==="system.erasedata.collect_start")$hashes[]=$call[0]->params[1];'
			.'echo json_encode(array("hashes"=>$hashes,"log"=>FileUtil::$log));');
		foreach(array('active','full','unrecognized-result') as $answer)
		{
			erasedataWriteDrainState($this->dir,$original);$seen=array();
			for($pass=0;$pass<3;$pass++)
			{
				$process=proc_open(array(PHP_BINARY,'-c',__DIR__.'/../../php-test.ini',$script,$this->dir,$answer),
					array(0=>array('file','/dev/null','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
				if(!is_resource($process))throw new RuntimeException('quota child unavailable');
				$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
				$data=json_decode($out,true);
				$this->assertTrue($exit===0&&$err===''&&is_array($data),$answer.' fresh collector exits cleanly');
				if(!is_array($data))throw new RuntimeException('quota child reply invalid');
				$this->assertTrue(count($data['hashes'])===8,$answer.' counts all eight attempted launches against the pass budget');
				foreach($data['hashes'] as $hash)$seen[$hash]=true;
				if($answer==='unrecognized-result')
					$this->assertTrue(strpos(implode("\n",$data['log']),'rpc-unknown')!==false
						&&strpos(implode("\n",$data['log']),$answer)===false,'unknown results use a fixed classification without arbitrary reply text');
			}
			$this->assertEquals(24,count($seen),$answer.' fresh passes reach every list and completed hash');
			$after=erasedataReadDrainState($this->dir);unset($after['native_retry']);
			$this->assertEquals($original,$after,$answer.' cursor and hints never change authority');
		}
	}

	public function testWorkerPreservesTheFirstFilesystemRefusalBeforeLaterOperationsClearIt()
	{
		if(!$this->api())return;$this->state();$hash=str_repeat('A',40);$g='0000000000000001';
		$base=$this->dir.'/payload';mkdir($base,0700);$files=array($base.'/a',$base.'/b');
		foreach($files as $file)file_put_contents($file,'kept');
		$paths=array('base'=>$base,'multi'=>true,'files'=>$files);
		$intent=array('incarnation'=>str_repeat('a',32),'nonce'=>str_repeat('b',32),'generation'=>$g,
			'user'=>'rutorrent','digest'=>hash('sha256',ErasedataManifestCodec::encode($hash,$paths,1)));
		$path=$this->dir.'/'.$hash.'.'.$g.'.actor.list';
		file_put_contents($path,ErasedataManifestCodec::encodeBound($hash,$paths,1,$intent));chmod($path,0600);
		$helper=$this->dir.'/refusal-helper';file_put_contents($helper,"#!/bin/sh\nprintf 'errno=13\\n' >&2\nexit 4\n");chmod($helper,0700);
		$filesystem=new class($helper) extends ErasedataFilesystemOps {
			private static $helper;public function __construct($helper){self::$helper=$helper;}
			public static function renameHelperPath(){return self::$helper;}
			public function unlinkCapturedEntry($path,$expectedIdentity,$reservationKey,$emptyDirectoryOnly=false){
				if(basename($path)==='a')$this->renameNoReplace($path,$path.'.refused');
				else self::clearRenameRefusal();return false;
			}
		};
		$service=new ErasedataCollector($filesystem,null,null,null,true);
		$this->assertTrue($service->runNative($this->dir,$hash,$intent)===false,'the unresolved operation retains its exact manifest');
		$log=implode("\n",FileUtil::$log);
		$this->assertTrue(strpos($log,'rename-noreplace-error')!==false&&strpos($log,'errno=13')!==false,
			'the first actual helper refusal survives later independent context clearing');
		$this->assertTrue(is_file($path)&&is_file($files[0])&&is_file($files[1]),'classification grants no payload or manifest deletion');
	}

	public function testFreshCollectorServicesShareRetentionAndRecoverAfterParentReturns()
	{
		if(!$this->api())return;$state=$this->state();$hash=str_repeat('A',40);$g=$state['generation'];
		$base=$this->dir.'/missing/base';$payload=$base.'/payload';
		$paths=array('base'=>$base,'multi'=>true,'files'=>array($payload));
		$intent=array('incarnation'=>str_repeat('a',32),'nonce'=>str_repeat('b',32),'generation'=>$g,
			'user'=>'rutorrent','digest'=>hash('sha256',ErasedataManifestCodec::encode($hash,$paths,1)));
		$path=$this->dir.'/'.$hash.'.'.$g.'.actor.list';
		file_put_contents($path,ErasedataManifestCodec::encodeBound($hash,$paths,1,$intent));chmod($path,0600);$stat=lstat($path);
		$state['journal'][$g]=array('phase'=>'published','force'=>1,'hashes'=>array($hash),
			'staging'=>array($hash=>array('path'=>substr($path,0,-5).'.tmp','dev'=>(string)$stat['dev'],'ino'=>(string)$stat['ino'])));
		erasedataWriteDrainState($this->dir,$state);
		$script=$this->dir.'/child.php';
		file_put_contents($script,'<?php require '.var_export(__DIR__.'/CollectorFixture.php',true).';'
			.'require '.var_export(__DIR__.'/../../../plugins/erasedata/collector.php',true).';'
			.'$profileMask=0700;$queue=$argv[1];$mode=$argv[2];$hash=str_repeat("A",40);'
			.'$files=glob($queue."/*.list");$path=$files[0];$m=ErasedataManifestCodec::decodeBytes(file_get_contents($path),$hash);'
			.'rXMLRPCRequest::$responses=array("fixture.nativeCapability"=>"available",'
			.'"system.erasedata.state"=>array("ok"=>true,"responseTypes"=>array("struct"),'
			.'"responseValues"=>array(array("phase"=>"committed","live_relation"=>"absent"))),'
			.'"system.erasedata.collect_start"=>array("ok"=>true,"val"=>array("spawned")));'
			.'$service=new ErasedataCollector(new ErasedataFilesystemOps(),function(){throw new Exception("presence replay");},'
			.'function(){throw new Exception("paths replay");},null,true);'
			.'if($mode==="retention-sink")$service->reportRetentionsTo(function(){});'
			.'$done=false;if($mode==="worker"){$result=$service->runNative($queue,$hash,$m["native_intent"]);'
			.'if(is_array($result)){$done=erasedataNativeRetryForgetCompleted($result,"rutorrent")'
			.'&&erasedataNativeRemoveCompleted($result,new ErasedataFilesystemOps());}}else $service->run($queue);'
			.'echo json_encode(array("done"=>$done,"calls"=>rXMLRPCRequest::$requested,"log"=>FileUtil::$log));');
		$run=function($mode)use($script){
			$process=proc_open(array(PHP_BINARY,'-c',__DIR__.'/../../php-test.ini',$script,$this->dir,$mode),
				array(0=>array('file','/dev/null','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
			if(!is_resource($process))throw new RuntimeException('retry child unavailable');
			$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
			$this->assertTrue($exit===0&&$err==='', $mode.' actual collector child finishes cleanly');
			$data=json_decode($out,true);if(!is_array($data))throw new RuntimeException('retry child reply invalid: '.$out);return $data;
		};
		$first=$run('worker');
		$this->assertTrue(!$first['done']&&is_file($path),'missing parent retains the exact native-bound manifest');
		$this->assertTrue(strpos(implode("\n",$first['log']),basename($path))!==false
			&&strpos(implode("\n",$first['log']),'unsafe-path')!==false,'first actual path failure is classified and visible');
		$this->assertTrue(count(erasedataReadDrainState($this->dir)['native_retry']['jobs'])===1,
			'the worker persists the same exact identity ordinary and drain will read');
		foreach(array('ordinary','retention-sink') as $mode)
		{
			$tick=$run($mode);
			$this->assertTrue(!in_array('system.erasedata.collect_start',$tick['calls'],true),$mode.' fresh tick backs off worker launch');
			$this->assertEquals(array(),$tick['log'],$mode.' fresh tick does not repeat the unchanged refusal');
		}
		$again=$run('worker');$this->assertEquals(array(),$again['log'],'another fresh worker does not repeat the same failure');
		mkdir($this->dir.'/missing',0700);mkdir($base,0700);file_put_contents($payload,'restored original payload');
		$state=erasedataReadDrainState($this->dir);foreach($state['native_retry']['jobs'] as &$hint)$hint[0]=time()-61;unset($hint);
		erasedataWriteDrainState($this->dir,$state);
		$tick=$run('ordinary');$this->assertTrue(in_array('system.erasedata.collect_start',$tick['calls'],true),'restored parent becomes launchable after bounded retry');
		$done=$run('worker');$this->assertTrue($done['done']&&!file_exists($payload)&&!file_exists($path),
			'the exact retained job completes after restoration');
		$this->assertEquals(array(),erasedataReadDrainState($this->dir)['native_retry']['jobs'],'terminal completion prunes only its exact hint');
		// The real collector may retain the now-empty parent for a later directory
		// action; it is fixture-owned and contains no payload at this point.
		if(is_dir($base))rmdir($base);if(is_dir($this->dir.'/missing'))rmdir($this->dir.'/missing');
	}

	// One V4 job bound to 'rutorrent' and journaled as its producer leaves it.
	private function boundJob($label)
	{
		$state=$this->state();$hash=str_repeat('A',40);$g=$state['generation'];
		$payload=$this->dir.'/payload-'.$label;file_put_contents($payload,'consumed exactly once');
		$paths=array('base'=>$payload,'multi'=>false,'files'=>array($payload));
		$intent=array('incarnation'=>str_repeat('a',32),'nonce'=>str_repeat('b',32),'generation'=>$g,
			'user'=>'rutorrent','digest'=>hash('sha256',ErasedataManifestCodec::encode($hash,$paths,1)));
		$path=$this->dir.'/'.$hash.'.'.$g.'.actor.list';$bytes=ErasedataManifestCodec::encodeBound($hash,$paths,1,$intent);
		file_put_contents($path,$bytes);chmod($path,0600);$stat=lstat($path);
		$state['journal'][$g]=array('phase'=>'published','force'=>1,'hashes'=>array($hash),
			'staging'=>array($hash=>array('path'=>substr($path,0,-5).'.tmp','dev'=>(string)$stat['dev'],'ino'=>(string)$stat['ino'])));
		erasedataWriteDrainState($this->dir,$state);
		return(array($hash,$intent,$path,$stat,$bytes,$payload));
	}

	private function worker(ErasedataFilesystemOps $filesystem)
	{
		return(new ErasedataCollector($filesystem,function(){throw new Exception('presence replay');},
			function(){throw new Exception('paths replay');},null,true));
	}

	private function lookups(ErasedataSettlingRenameFixture $filesystem, $path)
	{
		return(isset($filesystem->lookups[$path]) ? $filesystem->lookups[$path] : 0);
	}

	// POST-1011-01: on the owner's drvfs/9p volume (cache=0x5) lstat of the
	// terminal name answered ENOENT once right after its successful rename. The
	// payload was already consumed, yet the first worker withheld native finish.
	public function testTerminalNameNegativeLookupSettlesInTheSameWorker()
	{
		if(!$this->api())return;
		list($hash,$intent,$path,$stat,,$payload)=$this->boundJob('transient');
		$terminal=$path.'.completed';
		$filesystem=new ErasedataSettlingRenameFixture('/\.list\.completed$/D',2);
		$result=$this->worker($filesystem)->runNative($this->dir,$hash,$intent);
		$this->assertTrue(is_array($result),'one negative answer for the just-renamed terminal name does not withhold finish');
		$this->assertSame(2,$this->lookups($filesystem,$terminal),'the terminal name is asked again after its negative answer');
		$this->assertTrue(is_array($result) && $result['path']===$terminal && erasedataSameStatIdentity($stat,lstat($terminal)),
			'the exact consumed inode is the published terminal record');
		$this->assertTrue(!file_exists($payload) && !file_exists($path),'payload and the consumable name are gone');
		$this->assertEquals(array(),FileUtil::$log,'nothing is reported as retained or refused');
		$after=erasedataReadDrainState($this->dir);
		$this->assertTrue(is_array($after) && !isset($after['native_retry']) && $after['journal']===array(),
			'no advisory hint is filed and the exact journal member retires');
	}

	public function testTerminalNameHoldingAnotherInodeIsRefusedAtItsFirstAnswer()
	{
		if(!$this->api())return;
		list($hash,$intent,$path,,,$payload)=$this->boundJob('foreign');
		$terminal=$path.'.completed';
		$filesystem=new ErasedataSettlingRenameFixture('/\.list\.completed$/D',1,true);
		$this->assertTrue($this->worker($filesystem)->runNative($this->dir,$hash,$intent)===false,
			'a different inode under the terminal name is not the consumed record');
		$this->assertSame(1,$this->lookups($filesystem,$terminal),'a present answer is compared at once and never settled');
		$this->assertTrue(strpos(implode("\n",FileUtil::$log),'reason=terminal-publication-unresolved')!==false,
			'the withheld finish carries its classified reason');
		$this->assertTrue(!file_exists($payload),'the refusal concerns only the terminal record');
	}

	public function testOccupiedTerminalDestinationNamesTheBlockedRetryAndPreservesBothRecords()
	{
		list($hash,$intent,$path,,$bytes,$payload)=$this->boundJob('occupied-terminal');
		$terminal=$path.'.completed';$foreign='unknown terminal document';
		file_put_contents($terminal,$foreign);chmod($terminal,0600);$terminalStat=lstat($terminal);
		$this->assertSame(false,$this->worker(new ErasedataCollectorFixture(array()))->runNative($this->dir,$hash,$intent),
			'an occupied terminal destination withholds native finish');
		$this->assertTrue(!file_exists($payload) && file_get_contents($path)===$bytes,
			'the payload is consumed but its original authority document is retained');
		$this->assertTrue(file_get_contents($terminal)===$foreign && erasedataSameStatIdentity($terminalStat,lstat($terminal)),
			'the unknown occupied destination is preserved byte for byte and inode for inode');
		$log=implode("\n",FileUtil::$log);
		$this->assertTrue(strpos($log,'reason=terminal-publication-destination-occupied')!==false
			&& strpos($log,'payload consumed; terminal destination occupied')!==false,
			'the classified refusal describes the consumed payload and occupied destination');
		$this->assertTrue(strpos($log,'inspect the occupied destination='.erasedataLogPath($terminal))!==false,
			'the log names the exact destination that needs inspection');
		$this->assertSame(false,strpos($log,'retried automatically'),
			'a retry that cannot clear an occupied name is not presented as self-healing');
	}

	public function testUnsettledTerminalNameIsReportedTruthfullyAndItsHintRetiresWithTheJob()
	{
		if(!$this->api())return;
		list($hash,$intent,$path,$stat,$bytes,$payload)=$this->boundJob('unsettled');
		$terminal=$path.'.completed';
		$filesystem=new ErasedataSettlingRenameFixture('/\.list\.completed$/D',PHP_INT_MAX);
		$started=microtime(true);
		$this->assertTrue($this->worker($filesystem)->runNative($this->dir,$hash,$intent)===false,
			'a terminal name that never answers withholds finish');
		$elapsed=microtime(true)-$started;
		$bound=defined('ERASEDATA_RENAME_SETTLE_ATTEMPTS') ? ERASEDATA_RENAME_SETTLE_ATTEMPTS : 10;
		$this->assertSame($bound,$this->lookups($filesystem,$terminal),'the settle stops at its attempt bound');
		$this->assertTrue($elapsed<2.0,'the bounded settle costs well under two seconds: '.round($elapsed,3));
		$log=implode("\n",FileUtil::$log);
		$this->assertTrue(strpos($log,'reason=terminal-publication-unresolved; payload consumed; terminal record publication unresolved;'
			.' native finish withheld; retried automatically')!==false,'the line says the payload was consumed and the record is retried');
		$this->assertTrue(strpos($log,'payload effects refused')===false,'consumed payload is never reported as refused');
		$this->assertTrue(strpos($log,'document='.erasedataLogPath($path).' key=')===false,
			'no line names the .list path the document was renamed away from: '.$log);
		$this->assertTrue(!file_exists($payload),'the payload was consumed before publication was withheld');
		$state=erasedataReadDrainState($this->dir);
		$key=erasedataNativeRetryKey($hash,$terminal,$stat,$bytes,$intent);
		$this->assertTrue(is_array($state) && isset($state['native_retry']['jobs'][$key]) && count($state['native_retry']['jobs'])===1,
			'the one hint is filed under the exact terminal record the next pass and the finished worker compute');
		// The production hint was filed from the vanished list name, without its
		// bytes; a staging-name hint predates publication. Both belong to this
		// record. Another job's hint does not.
		$hint=array(time(),5,1,hash('sha256','payload-or-terminal-metadata-unresolved'));
		$unrelated=hash('sha256','another job');
		$state['native_retry']['jobs'][erasedataNativeRetryKey($hash,$path,$stat,'',$intent)]=$hint;
		$state['native_retry']['jobs'][erasedataNativeRetryKey($hash,substr($path,0,-5).'.tmp',$stat,$bytes,$intent)]=$hint;
		$state['native_retry']['jobs'][$unrelated]=$hint;
		$this->assertTrue(erasedataWriteDrainState($this->dir,$state),'the earlier spellings of this record are admitted as hints');
		// The mount settles; a fresh worker finishes exactly as collect_worker.php does.
		$this->assertTrue($filesystem->settle($terminal),'the renamed inode appears under its terminal name');
		$completed=$this->worker(new ErasedataCollectorFixture(array()))->runNative($this->dir,$hash,$intent);
		$this->assertTrue(is_array($completed),'the retained terminal record completes on the next worker');
		$this->assertTrue(is_array($completed) && erasedataNativeRetryForgetCompleted($completed,'rutorrent')
			&& erasedataNativeRemoveCompleted($completed,new ErasedataCollectorFixture(array())),
			'finish retires only the exact terminal metadata');
		$after=erasedataReadDrainState($this->dir);
		$this->assertTrue(is_array($after) && isset($after['native_retry']) && array_keys($after['native_retry']['jobs'])===array($unrelated),
			'the finished job leaves none of its hints behind and keeps another job\'s');
		$this->assertTrue(is_array($after) && $after['journal']===array() && !file_exists($terminal),'journal and terminal record retired');
	}

	// The owner's queue after the retry finished: no record, empty journal,
	// disarmed, and one hint whose record has gone.
	public function testFullPassForgetsOnlyHintsWhoseRecordHasLeftTheQueue()
	{
		if(!$this->api())return;
		$state=erasedataDefaultDrainState();$state['user']='rutorrent';
		$stale=hash('sha256','finished job');
		$state['native_retry']=array('jobs'=>array($stale=>array(time()-30,5,1,hash('sha256','payload-or-terminal-metadata-unresolved'))),
			'cursor'=>str_repeat('B',40),'overflow'=>true);
		$this->assertTrue(erasedataWriteDrainState($this->dir,$state),'the owner-shaped state is admitted');
		$hash=str_repeat('C',40);$g='0000000000000001';$payload=$this->dir.'/payload';file_put_contents($payload,'kept');
		$paths=array('base'=>$payload,'multi'=>false,'files'=>array($payload));
		$intent=array('incarnation'=>str_repeat('a',32),'nonce'=>str_repeat('b',32),'generation'=>$g,
			'user'=>'rutorrent','digest'=>hash('sha256',ErasedataManifestCodec::encode($hash,$paths,1)));
		$live=$this->dir.'/'.$hash.'.'.$g.'.actor.list';
		file_put_contents($live,ErasedataManifestCodec::encodeBound($hash,$paths,1,$intent));chmod($live,0644);
		$collector=function(){return(new ErasedataCollector(new ErasedataFilesystemOps(),
			function(){throw new Exception('presence fallback');},function(){throw new Exception('path fallback');},null,true));};
		rXMLRPCRequest::$responses=array('fixture.nativeCapability'=>'available');
		try
		{
			$collector()->run($this->dir);
			$after=erasedataReadDrainState($this->dir);
			$this->assertTrue(is_array($after) && !isset($after['native_retry']['jobs'][$stale]),'a hint no queue record reproduces is forgotten');
			$this->assertTrue(is_array($after) && count($after['native_retry']['jobs'])===1,'the retained live record keeps its own hint');
			$expected=$state;$actual=$after;unset($expected['native_retry']['jobs'],$actual['native_retry']['jobs']);
			$this->assertEquals($expected,$actual,'cursor, overflow, phase, journal and every other key are unchanged');
			$this->assertTrue(strpos(implode("\n",FileUtil::$log),'manifest-mode')!==false,'the live refusal is visible once');
			FileUtil::$log=array();
			$collector()->run($this->dir);
			$this->assertEquals(array(),FileUtil::$log,'the kept hint keeps the next full pass quiet');
			$kept=erasedataReadDrainState($this->dir);
			$this->assertTrue(is_array($kept) && count($kept['native_retry']['jobs'])===1,'an unchanged queue keeps its live hint');
			unlink($live);
			foreach($kept['native_retry']['jobs'] as &$hint)$hint[0]=time()-61;unset($hint);
			erasedataWriteDrainState($this->dir,$kept);
			$collector()->run($this->dir);
			$final=erasedataReadDrainState($this->dir);
			$this->assertTrue(is_array($final) && $final['native_retry']['jobs']===array(),'the hint leaves with its record');
			$this->assertEquals(str_repeat('B',40),$final['native_retry']['cursor'],'the cursor is never pruned');
			// The queue is read before the state lock: a hint filed since then
			// may describe a record that listing could not see yet.
			if(!function_exists('erasedataNativeRetryPruneOrphans'))
			{
				$this->assertTrue(false,'one prune owner bounds what a scan may forget');
				return;
			}
			$now=time()+100;$fresh=hash('sha256','filed during the scan');$old=hash('sha256','filed before the scan');
			$final['native_retry']['jobs']=array($old=>array($now-1,5,1,hash('sha256','r')),$fresh=>array($now,5,1,hash('sha256','r')));
			erasedataWriteDrainState($this->dir,$final);
			$this->assertTrue(erasedataNativeRetryPruneOrphans($this->dir,'rutorrent',$now),'the prune completes');
			$this->assertEquals(array($fresh),array_keys(erasedataReadDrainState($this->dir)['native_retry']['jobs']),
				'only a hint older than the scan is forgotten');
		}
		finally {rXMLRPCRequest::$responses=array();}
	}

	// The listing still names a record whose lstat keeps failing, as drvfs over
	// 9p answered for a just-published name: which hints are live is unknown,
	// so the prune forgets none until that record answers.
	public function testPruneForgetsNothingWhileAListedRecordCannotBeInspected()
	{
		if(!$this->api())return;
		require_once(__DIR__.'/../../php/SourceDefinitionFixture.php');
		$namespace='ErasedataUninspectableListing';
		if(!function_exists($namespace.'\\erasedataNativeRetryPruneOrphans'))
		{
			$source=__DIR__.'/../../../plugins/erasedata/';
			eval('namespace '.$namespace.'; use \\ErasedataManifestCodec; class LstatCut { public static $hidden=null; }'
				.'function lstat($path) { return $path===LstatCut::$hidden ? false : \\lstat($path); }'
				.loadFunctionDefinition($source.'filesystem.php','erasedataListedEntryStat')
				.loadFunctionDefinition($source.'native.php','erasedataNativeRetryLiveKeys')
				.loadFunctionDefinition($source.'native.php','erasedataNativeRetryPruneOrphans'));
		}
		$cut=$namespace.'\\LstatCut';$prune=$namespace.'\\erasedataNativeRetryPruneOrphans';
		$now=time()+100;$orphan=hash('sha256','filed before the scan');
		$state=erasedataDefaultDrainState();$state['user']='rutorrent';
		$state['native_retry']=array('jobs'=>array($orphan=>array($now-1,5,1,hash('sha256','r'))),'cursor'=>'','overflow'=>false);
		$this->assertTrue(erasedataWriteDrainState($this->dir,$state),'the owner-shaped state is admitted');
		$listed=$this->dir.'/'.str_repeat('D',40).'.0000000000000001.actor.list.completed';
		file_put_contents($listed,'record');
		try
		{
			$cut::$hidden=$listed;
			$this->assertTrue($prune($this->dir,'rutorrent',$now)===false,'an uninspectable listed record refuses the prune');
			$this->assertEquals(array($orphan),array_keys(erasedataReadDrainState($this->dir)['native_retry']['jobs']),
				'no hint is forgotten while the listing cannot be read');
			$cut::$hidden=null;
			$this->assertTrue($prune($this->dir,'rutorrent',$now)===true,'the prune proceeds once the record answers');
			$this->assertEquals(array(),erasedataReadDrainState($this->dir)['native_retry']['jobs'],
				'the orphan hint is forgotten then');
		}
		finally {$cut::$hidden=null;}
	}

	// ERA-05: malformed advice must not make a valid ownership journal unreadable.
	// The actual worker may persist a reset only after taking the state lock.
	public function testMalformedOptionalRetryHintsDoNotBlockValidatedDrainAdmission()
	{
		$state=$this->state();$hash=str_repeat('A',40);$generation=$state['generation'];
		$state['journal'][$generation]=array('phase'=>'published','force'=>1,'hashes'=>array($hash),
			'staging'=>array($hash=>array('path'=>$this->dir.'/'.$hash.'.'.$generation.'.actor.tmp','dev'=>'1','ino'=>'2')));
		$state['legacy_marker']=$state['legacy_rescue']=true;
		$statePath=erasedataDrainStatePath($this->dir);
		$key=hash('sha256','malformed hint');$reason=hash('sha256','retention');
		$hint=array('jobs'=>array($key=>array(1,5,1,$reason)),'cursor'=>'','overflow'=>false);
		$malformed=array(null,false,'garbled',array(),array('jobs'=>array()),
			array_merge($hint,array('extra'=>true)),array_merge($hint,array('jobs'=>false)),
			array_merge($hint,array('cursor'=>'not-a-hash')),array_merge($hint,array('overflow'=>1)));
		foreach(array(array(-1,5,1,$reason),array(1,0,1,$reason),array(1,61,1,$reason),
			array(1,5,0,$reason),array(1,5,17,$reason),array(1,5,1,'not-a-digest')) as $entry)
			$malformed[]=array_merge($hint,array('jobs'=>array($key=>$entry)));
		foreach($malformed as $index=>$retry)
		{
			$garbled=$state;$garbled['native_retry']=$retry;$bytes=json_encode($garbled);
			file_put_contents($statePath,$bytes);chmod($statePath,0600);
			$this->assertSame(false,erasedataValidateDrainState($garbled,$this->dir),'the writer schema still refuses malformed advice '.$index);
			$this->assertSame($state,erasedataReadDrainState($this->dir),'read '.$index.' discards only malformed advice after validating the core');
			$this->assertSame($state,erasedataReadDrainState($this->dir),'a repeated read preserves the same complete core '.$index);
			$this->assertSame($bytes,file_get_contents($statePath),'read '.$index.' does not repair the file without its state lock');
			$ack=erasedataAcknowledgeDrainGeneration($this->dir,'rutorrent',null);
			$this->assertTrue(is_array($ack) && $ack['state']===$state && $ack['generation']===$generation,
				'actual worker admission '.$index.' acknowledges the exact generation and preserves its journal');
			$this->assertSame($state,json_decode(file_get_contents($statePath),true),'the guarded acknowledgement naturally persists the clean core '.$index);
		}
		$lines=array_values(array_filter(FileUtil::$log,function($line){return strpos($line,'reason=malformed-advisory')!==false;}));
		$this->assertSame(1,count($lines),'one process reports the advisory reset once for the exact state document');
		$this->assertTrue(isset($lines[0]) && strpos($lines[0],'document='.erasedataLogPath($statePath))!==false
			&& strpos($lines[0],'key=native_retry')!==false && strpos($lines[0],'hints reset; journal preserved')!==false,
			'the classified diagnostic names the document, key and preserved authority');
		$this->assertTrue(strpos(implode("\n",$lines),$key)===false && strpos(implode("\n",$lines),$reason)===false,
			'raw advisory values do not enter the diagnostic');
		$state['native_retry']=$hint;file_put_contents($statePath,json_encode($state));
		$this->assertSame($state,erasedataReadDrainState($this->dir),'valid advisory state remains exact');
	}

	public function testMalformedAdviceCannotRepairCoreStateOrClaimAnotherUsersQueue()
	{
		$state=$this->state();$hash=str_repeat('A',40);$generation=$state['generation'];
		$state['journal'][$generation]=array('phase'=>'published','force'=>1,'hashes'=>array($hash),
			'staging'=>array($hash=>array('path'=>$this->dir.'/'.$hash.'.'.$generation.'.actor.tmp','dev'=>'1','ino'=>'2')));
		$bad=array();
		foreach(array('extra'=>true,'user'=>false,'generation'=>'bad','acknowledged'=>'0000000000000002',
			'phase'=>'unknown','diagnostics'=>array(''),'legacy_marker'=>false) as $key=>$value)
		{
			$variant=$state;$variant[$key]=$value;$bad[]=$variant;
		}
		foreach(array('phase'=>'unknown','force'=>'1','hashes'=>array(str_repeat('B',40))) as $key=>$value)
		{
			$variant=$state;$variant['journal'][$generation][$key]=$value;$bad[]=$variant;
		}
		$variant=$state;$variant['journal'][$generation]['staging'][$hash]['path']=$this->dir.'/../unbound.tmp';$bad[]=$variant;
		$variant=$state;$variant['journal'][$generation]['staging'][$hash]['ino']=1.5;$bad[]=$variant;
		$statePath=erasedataDrainStatePath($this->dir);
		foreach($bad as $index=>$invalid)
		{
			$invalid['native_retry']='garbled';$bytes=json_encode($invalid);
			file_put_contents($statePath,$bytes);chmod($statePath,0600);
			$this->assertSame(false,erasedataReadDrainState($this->dir),'corrupt core field '.$index.' remains unreadable');
			$this->assertSame(false,erasedataAcknowledgeDrainGeneration($this->dir,'rutorrent',null),'corrupt core '.$index.' refuses actual worker admission');
			$this->assertSame($bytes,file_get_contents($statePath),'corrupt core '.$index.' is never rewritten');
		}
		$this->assertTrue(strpos(implode("\n",FileUtil::$log),'reason=malformed-advisory')===false,
			'a refused core is never reported as an advisory-only recovery');
		$state['user']='another-user';$state['native_retry']='garbled';$bytes=json_encode($state);
		file_put_contents($statePath,$bytes);chmod($statePath,0600);
		$this->assertSame(false,erasedataAcknowledgeDrainGeneration($this->dir,'rutorrent',null),'advisory recovery never grants another user ownership');
		$this->assertSame($bytes,file_get_contents($statePath),'another user cannot persist even an advisory reset');
		unlink($statePath);
		$this->assertSame(false,erasedataNativeRetryPruneOrphans($this->dir,'rutorrent',time()+60),'an absent state is neither created nor claimed by prune');
		$this->assertTrue(!file_exists($statePath),'prune leaves the absent state absent');
	}
}
