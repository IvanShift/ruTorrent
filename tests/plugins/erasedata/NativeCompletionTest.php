<?php
require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/CollectorFixture.php');
require_once(__DIR__.'/../../../plugins/erasedata/collector.php');

class ErasedataNativeCompletionTest extends TestCase
{
	public function testQueueEntryReinspectionDistinguishesConcurrentReplacementFromUnreadableEntry()
	{
		require_once(__DIR__.'/../../php/SourceDefinitionFixture.php');
		$source=__DIR__.'/../../../plugins/erasedata/filesystem.php';
		$namespace='ErasedataQueueReinspectionProbe';
		if(!function_exists($namespace.'\\erasedataQueueEntriesOwned'))
		{
			$code='namespace '.$namespace.';'
				.'class Sequence {public static $stats; public static $calls=0;}'
				.'function lstat($path){Sequence::$calls++;return array_shift(Sequence::$stats);}'
				.'function scandir($path){return array(".","..","finished.tmp");}'
				.'function clearstatcache($all=false,$path=""){}'
				.'function erasedataRenameProbeName($name){return false;}'
				.'class FileUtil {public static function toLog($line){\\FileUtil::toLog($line);}}';
			if(function_exists('erasedataListedEntryStat'))$code.=loadFunctionDefinition($source,'erasedataListedEntryStat');
			$code.=loadFunctionDefinition($source,'erasedataQueueEntriesOwned');eval($code);
		}
		$state=$namespace.'\\Sequence';$call=$namespace.'\\erasedataQueueEntriesOwned';
		$uid=erasedataEffectiveUid();$stat=array('uid'=>$uid,'mode'=>0100600);
		$state::$stats=array(false,$stat);$state::$calls=0;
		$this->assertTrue($call('/queue',array('finished.tmp'),$uid,'/queue'),
			'a name re-created between first lstat and listing is freshly inspected');
		$this->assertTrue($state::$calls>=2,'the new inode is actually inspected rather than skipping the name');
		$state::$stats=array(false,array('uid'=>$uid+1,'mode'=>0100600));
		$this->assertTrue(!$call('/queue',array('finished.tmp'),$uid,'/queue'),
			'a re-created foreign inode still refuses queue admission');
		$state::$stats=array(false,false,false,false);
		$this->assertTrue(!$call('/queue',array('finished.tmp'),$uid,'/queue'),
			'a still-listed unreadable entry cannot be treated as vanished');
	}

	public function testCapturedRootTeardownRecoversOnlyAuthenticatedMetadataShells()
	{
		global $profileMask;
		$profileMask = 0700;
		$dir = sys_get_temp_dir().'/ed-teardown-'.bin2hex(random_bytes(6));
		mkdir($dir,0700);mkdir($dir.'/queue',0700);mkdir($dir.'/payload',0700);
		$filesystem = new ErasedataFilesystemOps();
		try
		{
			foreach(array('manifest-consumption', $dir.'/queue/reservation') as $reservation)
				foreach(array('complete','empty','extra','entry','bridge','symlink','hardlink','mode','late-mode','changed-root') as $case)
				{
					if($reservation === 'manifest-consumption' && $case === 'changed-root') continue;
					$parent = $reservation === 'manifest-consumption' ? $dir.'/queue' : $dir.'/payload';
					$path = $parent.'/'.bin2hex(random_bytes(6));file_put_contents($path,'public bytes');
					$root = erasedataCreateCapturedEntryRoot($path,$reservation,lstat($path),$filesystem);
					if(!is_string($root)) throw new RuntimeException('captured-root fixture refused');
					$marker=$root.'/.initialized';$stored=file_get_contents($marker);
					$failure=new ErasedataCollectorFixture(array('unlink:*'=>array('path'=>$marker,'result'=>false)));
					$this->assertTrue(!erasedataRemoveCapturedEntryRoot($root,$failure)
						&& !file_exists($root.'/.name') && is_file($marker),
						$case.' reaches the actual name-unlinked marker-retained teardown cut');
					if($case === 'extra') file_put_contents($root.'/unexpected','unknown');
					if($case === 'entry') file_put_contents($root.'/entry','captured bytes');
					if($case === 'bridge') { unlink($path); symlink(basename($root).'/entry',$path); }
					if($case === 'symlink') { unlink($marker); symlink($path,$marker); }
					if($case === 'hardlink') link($marker,$parent.'/marker-copy');
					if($case === 'mode') chmod($marker,0644);
					if($case === 'empty') unlink($marker);
					if($case === 'changed-root')
					{
						rename($root,$root.'.old');mkdir($root,0700);
						file_put_contents($marker,$stored);chmod($marker,0600);
					}
					$blocker=null;
					$reader=$case === 'late-mode' ? new class($parent,$marker) extends ErasedataFilesystemOps {
						private $parent;private $marker;private $calls=0;
						public function __construct($parent,$marker){$this->parent=$parent;$this->marker=$marker;}
						public function scanDirectory($path){
							if($path===$this->parent && ++$this->calls===2 && file_exists($this->marker))chmod($this->marker,0644);
							return parent::scanDirectory($path);
							}
						} : $filesystem;
					$result=erasedataResumeCapturedEntries($parent,$reservation,$reader,$blocker);
					if($case === 'complete' || $case === 'empty')
					{
						$this->assertTrue($result && !file_exists($root),'authenticated terminal shell heals without .name');
						$this->assertEquals('public bytes',file_get_contents($path),'terminal cleanup never deletes a public replacement');
					}
					else
						$this->assertTrue(!$result && is_dir($root),$case.' retains the unknown or changed workspace');
					$this->removeTree($root);$this->removeTree($root.'.old');
					if(is_link($path)||is_file($path))unlink($path);
					if(file_exists($parent.'/marker-copy'))unlink($parent.'/marker-copy');
				}
		}
		finally { $this->removeTree($dir); }
	}



	public function testKnownCaptureOwnershipRefusalUsesTheExistingFilesystemClassification()
	{
		require_once(__DIR__.'/../../php/SourceDefinitionFixture.php');
		$namespace='ErasedataCaptureOwnershipProbe';
		if(!class_exists($namespace.'\\Filesystem',false))
		{
			$method=loadFunctionDefinition(__DIR__.'/../../../plugins/erasedata/filesystem.php','rename');
			eval('namespace '.$namespace.';function erasedataCaptureSourceOwned($path){return false;}'
				.'function rename($from,$to){return false;}'
				.'class Filesystem {public static $reason=null;public static function clearRenameRefusal(){self::$reason=null;}'
				.'public static function renameDiagnostic($reason,$from,$to){self::$reason=$reason;return false;}'
				.'private function privateCaptureParent($path){return false;}'
				.'public function renameCapability($path){return true;}'.$method.'}');
		}
		$class=$namespace.'\\Filesystem';$filesystem=new $class();
		$this->assertTrue(!$filesystem->rename('/no-public-effect/source','/private/.erasedata-entry-guard/entry'),
			'the existing capture ownership validator still refuses the operation');
		$this->assertEquals('capture-source-owner',$class::$reason,
			'the known ownership refusal reaches the shared classified diagnostic owner');
		$this->assertTrue(!$filesystem->rename('/no-public-effect/source','/private/normal-destination'),
			'an unsuccessful rename stays refused without an errno guess');
		$this->assertEquals('rename-refused',$class::$reason,
			'the actual operation refusal carries its named paths through the same owner');
	}

	public function testFreshProcessRecoversTheExactNameUnlinkedTeardownCrash()
	{
		$dir=sys_get_temp_dir().'/ed-teardown-child-'.bin2hex(random_bytes(6));mkdir($dir,0700);
		$script=$dir.'/child.php';
		file_put_contents($script,'<?php require '.var_export(__DIR__.'/CollectorFixture.php',true).';'
			.'require '.var_export(__DIR__.'/../../../plugins/erasedata/collector.php',true).';'
			.'$profileMask=0700;$dir=$argv[1];$reservation=$argv[2];$parent=$dir."/payload";'
			.'if($argv[3]==="crash"){$path=$parent."/public";file_put_contents($path,"current public bytes");'
			.'$root=erasedataCreateCapturedEntryRoot($path,$reservation,lstat($path),new ErasedataFilesystemOps());'
			.'if(!is_string($root))exit(3);file_put_contents($dir."/root-name",$root);'
			.'$fs=new class extends ErasedataFilesystemOps {public function unlink($p){if(basename($p)===".initialized")exit(73);return parent::unlink($p);}};'
			.'erasedataRemoveCapturedEntryRoot($root,$fs);exit(4);}else{$blocker=null;'
			.'if(!erasedataResumeCapturedEntries($parent,$reservation,new ErasedataFilesystemOps(),$blocker))exit(5);}');
		try
		{
			foreach(array('manifest-consumption',$dir.'/queue/reservation') as $reservation)
			{
				mkdir($dir.'/queue',0700);mkdir($dir.'/payload',0700);
				foreach(array('crash','resume') as $phase)
				{
					$process=proc_open(array(PHP_BINARY,'-c',__DIR__.'/../../php-test.ini',$script,$dir,$reservation,$phase),
						array(0=>array('file','/dev/null','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
					if(!is_resource($process))throw new RuntimeException('teardown child unavailable');
					$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
					$this->assertTrue($exit===($phase==='crash'?73:0)&&$out===''&&$err==='',
						$phase.' child reaches the exact process boundary without a PHP diagnostic');
					$root=file_get_contents($dir.'/root-name');
					if($phase==='crash')$this->assertTrue(is_file($root.'/.initialized')&&!file_exists($root.'/.name')&&!file_exists($root.'/entry'),
						'the actual process dies after name unlink with only the authenticated marker remaining');
					else $this->assertTrue(!is_dir($root)&&file_get_contents($dir.'/payload/public')==='current public bytes',
						'a separate fresh process removes only terminal metadata and preserves the public replacement');
				}
				$this->removeTree($dir.'/queue');$this->removeTree($dir.'/payload');unlink($dir.'/root-name');
			}
		}
		finally {$this->removeTree($dir);}
	}

	public function testRevokedNativeIntentRetiresOnlyMetadataWithNoCapturedRecovery()
	{
		global $profileMask;$profileMask=0700;
		foreach(array('ordinary','drain','unlink-retry','captured','intent','bridge','unknown','mode','late-mode','hardlink','changed-bytes','journal-mismatch') as $case)
		{
			$dir=sys_get_temp_dir().'/ed-revoked-'.bin2hex(random_bytes(6));mkdir($dir,0700);mkdir($dir.'/queue',0700);
			$queue=$dir.'/queue';$hash=str_repeat('A',40);$g='0000000000000001';$payload=$dir.'/payload';
			file_put_contents($payload,'new incarnation bytes');$paths=array('base'=>$payload,'multi'=>false,'files'=>array($payload));
			$intent=array('incarnation'=>str_repeat('a',32),'nonce'=>str_repeat('b',32),'generation'=>$g,
				'user'=>User::getUser(),'digest'=>hash('sha256',ErasedataManifestCodec::encode($hash,$paths,1)));
			$path=$queue.'/'.$hash.'.'.$g.'.actor.list';$bytes=ErasedataManifestCodec::encodeBound($hash,$paths,1,$intent);
			erasedataQueueRequest($queue,$hash,1,$g);
			file_put_contents($path,$bytes);chmod($path,0600);$stat=lstat($path);
			$record=array('path'=>substr($path,0,-5).'.tmp','dev'=>(string)$stat['dev'],'ino'=>(string)$stat['ino']);
			$state=erasedataDefaultDrainState();$state['user']=User::getUser();$state['phase']='armed';
			$state['generation']=$state['acknowledged']=$g;
			$state['journal'][$g]=array('phase'=>'published','force'=>1,'hashes'=>array($hash),'staging'=>array($hash=>$record));
			erasedataWriteDrainState($queue,$state);
			if($case==='captured')mkdir(erasedataCapturedEntryPrefix($payload,$path).'unknown',0700);
			if($case==='intent')file_put_contents($queue.'/.erasedata-rmdir-intent-'.hash('sha256',$path),'unknown');
			if($case==='bridge')symlink(basename(erasedataCapturedEntryPrefix($payload,$path)).'unknown/entry',$dir.'/bridge');
			if($case==='mode')chmod($path,0644);
			if($case==='hardlink')link($path,$dir.'/manifest-link');
			if($case==='journal-mismatch'){$state['journal'][$g]['staging'][$hash]['ino']='999999999';erasedataWriteDrainState($queue,$state);}
			if($case==='changed-bytes')file_put_contents($path,$bytes."x");
			rXMLRPCRequest::$responses=array('fixture.nativeCapability'=>'available','system.erasedata.state'=>
				$case==='unknown' ? array('ok'=>false) : array('val'=>array(array('phase'=>'missing','live_relation'=>'different')),
				'responseTypes'=>array('struct')));
			rXMLRPCRequest::$requested=array();FileUtil::$log=array();
			try
			{
				if($case==='drain')
				{
					$notes=array();$result=erasedataNativeDrainGenerationPass($queue,User::getUser(),$g,
						array('hashes'=>array($hash),'force'=>1),new ErasedataFilesystemOps(),$notes);
				}
				else
				{
					$fs=$case==='unlink-retry' ? new ErasedataCollectorFixture(array('unlink:*'=>array('path'=>$path,'result'=>false))) : new ErasedataFilesystemOps();
					if($case==='late-mode')$fs=new class($queue,$path) extends ErasedataFilesystemOps {
						private $queue;private $path;
						public function __construct($queue,$path){$this->queue=$queue;$this->path=$path;}
						public function scanDirectory($parent){$result=parent::scanDirectory($parent);
							if($parent===$this->queue && strpos(implode('|',array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),'function')),'erasedataNativeRevokedRecoveryAbsent')!==false)chmod($this->path,0644);
							return $result;}
					};
					$service=new ErasedataCollector($fs,function(){throw new Exception('presence fallback');},
						function(){throw new Exception('path fallback');},null,true);$service->run($queue,$hash);
					if($case==='unlink-retry')
					{
						$cut=erasedataReadDrainState($queue);
						$this->assertTrue(is_file($path)&&!isset($cut['journal'][$g])&&!file_exists(erasedataPendingMarkerPath($queue,$hash,$g)),
							'a failed manifest unlink retains discoverable bytes after exact journal and pending retirement');
						$next=new ErasedataCollector(new ErasedataFilesystemOps(),null,null,null,true);$next->run($queue,$hash);
					}

				}
				$after=erasedataReadDrainState($queue);$clean=in_array($case,array('ordinary','drain','unlink-retry'),true);
				$this->assertEquals($clean,!file_exists($path),$case.' removes only positively revoked metadata without recovery artifacts');
				$this->assertEquals(!$clean,isset($after['journal'][$g]),$case.' preserves or retires the exact journal consistently');
				$this->assertEquals(!$clean,file_exists(erasedataPendingMarkerPath($queue,$hash,$g)),$case.' preserves or retires exact pending obligation');
				$this->assertEquals('new incarnation bytes',file_get_contents($payload),$case.' never touches current public payload');
				$this->assertTrue(!count(glob($path.'.*.unverified')),$case.' never hides revoked V4 behind legacy quarantine');
				$this->assertTrue(!in_array('system.erasedata.erase',rXMLRPCRequest::$requested,true)
					&&!in_array('system.erasedata.prepare',rXMLRPCRequest::$requested,true)
					&&!in_array('system.erasedata.collect_start',rXMLRPCRequest::$requested,true),$case.' cannot regain payload authority');
			}
			finally {$this->removeTree($dir);rXMLRPCRequest::$responses=array();}
		}
	}

	private function removeTree($path)
	{
		if(is_link($path)||is_file($path)){unlink($path);return;}
		if(!is_dir($path))return;
		foreach(array_diff(scandir($path),array('.','..')) as $name)$this->removeTree($path.'/'.$name);
		rmdir($path);
	}

	public function testModernMalformedAndWrongModeManifestsRemainNamedAndRetryable()
	{
		global $profileMask;$profileMask=0700;
		$dir=sys_get_temp_dir().'/ed-visible-'.bin2hex(random_bytes(6));mkdir($dir,0700);mkdir($dir.'/queue',0700);
		$queue=$dir.'/queue';$hash=str_repeat('A',40);$g='0000000000000001';$payload=$dir.'/payload';
		file_put_contents($payload,'kept');$paths=array('base'=>$payload,'multi'=>false,'files'=>array($payload));
		$intent=array('incarnation'=>str_repeat('a',32),'nonce'=>str_repeat('b',32),'generation'=>$g,
			'user'=>User::getUser(),'digest'=>hash('sha256',ErasedataManifestCodec::encode($hash,$paths,1)));
		$valid=ErasedataManifestCodec::encodeBound($hash,$paths,1,$intent);
		try
		{
			foreach(array('truncated'=>array('{"version":',0600,'unreadable-manifest'),
				'future'=>array('{"version":99}',0600,'unreadable-manifest'),
				'mode'=>array($valid,0644,'manifest-mode actual=0644 required=0600')) as $case=>$data)
			{
				$path=$queue.'/'.$hash.'.'.$g.'.'.$case.'.list';file_put_contents($path,$data[0]);chmod($path,$data[1]);
				$stat=lstat($path);$state=erasedataDefaultDrainState();$state['user']=User::getUser();
				$state['generation']=$state['acknowledged']=$g;$state['phase']='armed';erasedataWriteDrainState($queue,$state);
				rXMLRPCRequest::$responses=array('fixture.nativeCapability'=>'available');rXMLRPCRequest::$requested=array();FileUtil::$log=array();
				$service=new ErasedataCollector(new ErasedataFilesystemOps(),function(){throw new Exception('presence fallback');},
					function(){throw new Exception('path fallback');},null,true);
				$service->run($queue,$hash);$log=implode("\n",FileUtil::$log);
				$this->assertTrue(is_file($path)&&file_get_contents($path)===$data[0]
					&&erasedataSameStatIdentity($stat,lstat($path)),$case.' preserves exact malformed or unsafe manifest bytes and inode');
				$this->assertTrue(strpos($log,basename($path))!==false&&strpos($log,$data[2])!==false,
					$case.' names the exact document and classified reason');
				$this->assertTrue(is_file($payload)&&rXMLRPCRequest::$requested===array(),$case.' grants no payload RPC or native launch');
				unlink($path);
			}
		}
		finally {$this->removeTree($dir);rXMLRPCRequest::$responses=array();}
	}

	public function testUnconfirmedNativeCapabilityRetainsExactLegacyIntentForLaterRetry()
	{
		global $profileMask;
		$profileMask = 0700;
		foreach(array('unconfirmed','unsupported') as $capability)
		{
			$dir=sys_get_temp_dir().'/ed-capability-'.bin2hex(random_bytes(6));
			mkdir($dir,0700);mkdir($dir.'/queue',0700);
			$queue=$dir.'/queue';$hash=str_repeat('A',40);$payload=$dir.'/payload';
			file_put_contents($payload,'retained until capability recovers');
			$path=$queue.'/'.$hash.'.0000000000000001.actor.list';
			$bytes=ErasedataManifestCodec::encode($hash,array('base'=>$payload,
				'multi'=>false,'files'=>array($payload)),1);
			file_put_contents($path,$bytes);chmod($path,0600);$stat=lstat($path);
			rXMLRPCRequest::$responses=array('fixture.nativeCapability'=>$capability);
			FileUtil::$log=array();$probes=0;
			$service=new ErasedataCollector(new ErasedataFilesystemOps(),
				function()use(&$probes){$probes++;return ERASEDATA_TORRENT_ABSENT;},
				function(){throw new Exception('unconfirmed path collection');},null,true);
			try
			{
				$service->run($queue,$hash);
				$this->assertTrue(is_file($path),$capability.' preserves the original retry name');
				$this->assertTrue(is_file($path)&&file_get_contents($path)===$bytes,
					$capability.' preserves exact legacy intent bytes');
				$this->assertTrue(is_file($path)&&erasedataSameStatIdentity($stat,lstat($path)),
					$capability.' cannot quarantine or replace the original intent');
				$this->assertEquals(array(),glob($path.'.*.unverified'),
					$capability.' does not create permanent quarantine on uncertain capability');
				$this->assertEquals(0,$probes,$capability.' grants no payload/presence work');
				$this->assertTrue(is_file($payload),$capability.' preserves payload while authority is unknown');
				$log=implode("\n",FileUtil::$log);
				$this->assertTrue(strpos($log,basename($path))!==false
					&&strpos($log,'native-capability-'.$capability)!==false,
					$capability.' visibly names retained document and classified cause');
				rXMLRPCRequest::$responses['fixture.nativeCapability']='legacy';
				$service->run($queue,$hash);
				$this->assertTrue(!is_file($path)&&!is_file($payload),
					'a later confirmed stock tick completes the retained legacy intent');
			}
			finally
			{
				foreach(glob($queue.'/{*,.*}',GLOB_BRACE)as$f)
					if(is_file($f)||is_link($f))unlink($f);
				rmdir($queue);if(is_file($payload))unlink($payload);rmdir($dir);
				rXMLRPCRequest::$responses=array();
			}
		}
	}

	public function testNamedManifestProbesRefuseStaticFifosWithoutOpeningThem()
	{
		$dir = sys_get_temp_dir().'/ed-fifo-'.bin2hex(random_bytes(6));
		mkdir($dir,0700);
		$hash = str_repeat('A',40); $generation = '0000000000000001';
		$script = $dir.'/probe.php';
		file_put_contents($script, '<?php require '.var_export(__DIR__.'/CollectorFixture.php',true).';'
			.'require '.var_export(__DIR__.'/../../../plugins/erasedata/collector.php',true).';'
			.'$profileMask=0700;$case=$argv[1];$path=$argv[2];$queue=dirname($path);'
			.'$hash=str_repeat("A",40);$g="0000000000000001";$stat=lstat($path);'
			.'$record=array("path"=>$path,"dev"=>(string)$stat["dev"],"ino"=>(string)$stat["ino"]);'
			.'if($case==="bound-staging-sibling"||$case==="legacy-drain")$record["path"]=substr($path,0,-5).".tmp";'
			.'if($case==="reader")$result=ErasedataManifestCodec::readBoundedFile($path);'
			.'elseif($case==="state")$result=erasedataReadDrainState($queue);'
			.'elseif($case==="pending")$result=erasedataPendingObligations($queue);'
			.'elseif($case==="legacy-staging")$result=erasedataNativeLegacyStagingCandidates($queue,$hash);'
			.'elseif($case==="bound-staging"||$case==="bound-staging-sibling")$result=erasedataNativeReadBoundStaging($record,$hash);'
			.'elseif($case==="completed")$result=erasedataNativeCompletedCandidates($queue,$hash);'
			.'else{$payload=$queue."/payload";file_put_contents($payload,"legacy payload");'
			.'$bytes=ErasedataManifestCodec::encode($hash,array("base"=>$payload,"files"=>array($payload),"multi"=>false),1);'
			.'file_put_contents($record["path"],$bytes);chmod($record["path"],0600);$stat=lstat($record["path"]);'
			.'$record["dev"]=(string)$stat["dev"];$record["ino"]=(string)$stat["ino"];'
			.'$state=erasedataDefaultDrainState();$state["generation"]=$g;'
			.'$state["journal"][$g]=array("phase"=>"erase-started","force"=>1,"hashes"=>array($hash),'
			.'"staging"=>array($hash=>$record));if(!erasedataWriteDrainState($queue,$state))exit(2);'
			.'$notes=array();$result=erasedataNativeLegacyDrainGuard($queue,$g,array("hashes"=>array($hash)), $notes);}'
			.'echo json_encode(array("result"=>$result,"calls"=>rXMLRPCRequest::$requested));');
		try
		{
			foreach(array('reader','state','pending','legacy-staging','bound-staging','bound-staging-sibling','completed','legacy-drain') as $case)
			{
				$queue = $dir.'/'.$case; mkdir($queue,0700);
				$path = $queue.'/'.$hash.'.'.$generation.'.actor.'
					.($case === 'completed' ? 'list.completed' :
						(in_array($case,array('bound-staging-sibling','legacy-drain'),true) ? 'list' : 'tmp'));
				if($case==='state')$path=erasedataDrainStatePath($queue);
				if($case==='pending')$path=erasedataPendingMarkerPath($queue,$hash,$generation);
				if(!posix_mkfifo($path,0600)) throw new RuntimeException('FIFO fixture unavailable');
				$process = proc_open(array(PHP_BINARY,'-c',__DIR__.'/../../php-test.ini',$script,$case,$path),
					array(0=>array('file','/dev/null','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
				if(!is_resource($process)) throw new RuntimeException('FIFO probe child unavailable');
				$end = microtime(true)+2;
				do { $status=proc_get_status($process); if(!$status['running']) break; usleep(10000); }
				while(microtime(true)<$end);
				$finished = !$status['running'];
				if(!$finished) proc_terminate($process,9);
				$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
				fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
				$this->assertTrue($finished,$case.' refuses a static FIFO without blocking on open');
				$this->assertEquals('fifo',filetype($path),$case.' retains the unknown FIFO unchanged');
				if($finished)
				{
					$answer=json_decode($out,true);
					$this->assertTrue(is_array($answer)&&$err==='', $case.' finishes without a PHP diagnostic');
					$this->assertTrue(is_array($answer)&&($answer['result']===false||$answer['result']===array())
						&&$answer['calls']===array(),$case.' grants no manifest authority or RPC mutation');
				}
				foreach(glob($queue.'/{*,.*}',GLOB_BRACE) as $entry)
					if(basename($entry)!=='.'&&basename($entry)!=='..') unlink($entry);
				rmdir($queue);
			}
		}
		finally { unlink($script); rmdir($dir); }
	}

	public function testTerminalManifestSurvivesJournalRetirementAndNeverReplaysPayload()
	{
		global $profileMask;
		$profileMask = 0700;
		$dir = sys_get_temp_dir().'/ed-completion-'.bin2hex(random_bytes(6));
		mkdir($dir,0700); mkdir($dir.'/queue',0700);
		$queue = $dir.'/queue'; $hash = str_repeat('A',40); $g = '0000000000000001';
		$payload = $dir.'/kept.bin'; file_put_contents($payload,'new-public-bytes');
		$paths = array('base'=>$payload,'multi'=>false,'files'=>array($payload));
		$canonical = ErasedataManifestCodec::encode($hash,$paths,1);
		$intent = array('incarnation'=>str_repeat('a',32),'nonce'=>str_repeat('b',32),
			'generation'=>$g,'user'=>'','digest'=>hash('sha256',$canonical));
		$path = $queue.'/'.$hash.'.'.$g.'.actor.list.completed';
		$bytes = ErasedataManifestCodec::encodeBound($hash,$paths,1,$intent);
		file_put_contents($path,$bytes); chmod($path,0600);
		$staging = $queue.'/'.$hash.'.'.$g.'.actor.tmp';
		file_put_contents($staging,$bytes); chmod($staging,0600); $stagingStat=lstat($staging);
		$bound=erasedataNativeReadBoundStaging(array('path'=>$staging,
			'dev'=>(string)$stagingStat['dev'],'ino'=>(string)$stagingStat['ino']),$hash);
		$this->assertTrue(is_array($bound)&&$bound['path']===$staging,
			'the pre-open probe still accepts exact regular V4 staging');
		$this->assertTrue(is_array($bound)&&$bound['manifest']['native_intent']===$intent,
			'the regular-file control retains the exact native tuple');
		unlink($staging);
		$stat = lstat($path);
		$state = erasedataDefaultDrainState(); $state['generation']=$g; $state['acknowledged']=$g; $state['phase']='armed';
		$state['journal'][$g] = array('phase'=>'published','force'=>1,'hashes'=>array($hash),
			'staging'=>array($hash=>array('path'=>substr($path,0,-15).'.tmp','dev'=>(string)$stat['dev'],'ino'=>(string)$stat['ino'])));
		erasedataWriteDrainState($queue,$state);
		// Worker argv reconstructs the same fields in a different PHP key order.
		$argvIntent = array('incarnation'=>$intent['incarnation'],'generation'=>$g,'user'=>'',
			'nonce'=>$intent['nonce'],'digest'=>$intent['digest']);
		$service = new ErasedataCollector(new ErasedataFilesystemOps(),function(){throw new Exception('payload presence replay');},
			function(){throw new Exception('payload paths replay');},null,true);
		try
		{
			$result = $service->runNative($queue,$hash,$argvIntent);
			$this->assertTrue(is_array($result),'a new worker resumes an incapacitated manifest using canonical argv authority');
			$this->assertEquals('new-public-bytes',file_get_contents($payload),'completed metadata never becomes a payload input');
			$this->assertEquals(array(),erasedataReadDrainState($queue)['journal'],'exact journal is retired before native finish');
			$this->assertTrue(is_file($path),'the full exact V4 tuple survives a crash before native finish');
			$result = $service->runNative($queue,$hash,$argvIntent);
			$this->assertTrue(is_array($result),'another fresh invocation resumes after journal and pending have gone');
			$this->assertEquals('new-public-bytes',file_get_contents($payload),'repeated terminal reconciliation cannot replay deletion');
			$this->assertTrue(erasedataNativeRemoveCompleted($result,new ErasedataFilesystemOps()),'settled native finish removes only exact terminal metadata');
			$this->assertEquals('new-public-bytes',file_get_contents($payload),'metadata cleanup preserves public payload');
		}
		finally
		{
			foreach(glob($queue.'/{*,.*}',GLOB_BRACE) as $f) if(is_file($f)) unlink($f);
			rmdir($queue); unlink($payload); rmdir($dir);
		}
	}
	public function testNativeTupleUsesCanonicalOrderWithoutNumericStringCoercion()
	{
		$a=array('incarnation'=>str_repeat('1',32),'nonce'=>str_repeat('2',32),'generation'=>'0000000000000001','user'=>'','digest'=>str_repeat('3',64));
		$b=array_reverse($a,true);
		$this->assertEquals(ErasedataManifestCodec::canonicalNativeIntent($a),ErasedataManifestCodec::canonicalNativeIntent($b),'JSON object order does not alter authority');
		$b['incarnation']=substr($a['incarnation'],0,-1).'2';
		$this->assertTrue(ErasedataManifestCodec::canonicalNativeIntent($a)!==ErasedataManifestCodec::canonicalNativeIntent($b),'long decimal hex identities remain exact strings');
	}

	// POST-1011-01: on drvfs over 9p (cache=0x5) lstat of a name answered ENOENT
	// once right after its successful rename. One owner settles that answer.
	public function testRenamedEntryIdentitySettlesOnlyANegativeAnswerWithinItsBound()
	{
		if(!method_exists('ErasedataFilesystemOps','renamedEntryIdentity'))
		{
			$this->assertTrue(false,'a just-renamed name is confirmed through one bounded settle owner');
			return;
		}
		$filesystem=new class extends ErasedataFilesystemOps {
			public $answers=array();public $calls=0;
			public function entryIdentity($path){$this->calls++;return count($this->answers) ? array_shift($this->answers) : false;}
		};
		$renamed=array('dev'=>7,'ino'=>8,'lstat'=>array('dev'=>7,'ino'=>8));
		$filesystem->answers=array(false,false,$renamed);
		$this->assertSame($renamed,$filesystem->renamedEntryIdentity('/queue/name'),'negative answers are asked again until the name appears');
		$this->assertSame(3,$filesystem->calls,'the first present answer ends the settle');
		$other=array('dev'=>7,'ino'=>9,'lstat'=>array('dev'=>7,'ino'=>9));
		$filesystem->calls=0;$filesystem->answers=array($other,$renamed);
		$this->assertSame($other,$filesystem->renamedEntryIdentity('/queue/name'),'a present answer is returned as it is, even for another inode');
		$this->assertSame(1,$filesystem->calls,'a present answer is never asked again');
		$filesystem->calls=0;$filesystem->answers=array();$started=microtime(true);
		$this->assertSame(false,$filesystem->renamedEntryIdentity('/queue/name'),'a name that stays absent is reported absent');
		$elapsed=microtime(true)-$started;
		$this->assertSame(ERASEDATA_RENAME_SETTLE_ATTEMPTS,$filesystem->calls,'the settle stops at its attempt bound');
		$this->assertTrue(ERASEDATA_RENAME_SETTLE_ATTEMPTS*ERASEDATA_RENAME_SETTLE_PAUSE_US<=500000 && $elapsed<1.0,
			'the settle waits about half a second at most: '.round($elapsed,3));
		$real=sys_get_temp_dir().'/ed-renamed-'.bin2hex(random_bytes(6));file_put_contents($real,'present');
		try
		{
			$identity=(new ErasedataFilesystemOps())->renamedEntryIdentity($real);
			$this->assertTrue(is_array($identity) && erasedataSameStatIdentity(lstat($real),$identity['lstat']),
				'a present real name answers its own identity');
		}
		finally {unlink($real);}
	}

	// A capture renames a payload or a consumed manifest into its private root,
	// and its public bridge later moves to a tombstone there. A negative answer
	// for either new name just after its rename is not a capture or bridge that
	// was already consumed.
	public function testCapturedEntriesAndBridgeTombstonesThatAnswerLateAreConsumedInTheSamePass()
	{
		global $profileMask;$profileMask=0700;
		foreach(array('captured entry'=>'#/\.erasedata-entry-[^/]+/entry$#D',
			'bridge tombstone'=>'#/\.erasedata-entry-[^/]+/\.bridge-[0-9]+-[0-9]+$#D') as $case=>$pattern)
		{
			$dir=sys_get_temp_dir().'/ed-settle-capture-'.bin2hex(random_bytes(6));
			mkdir($dir,0700);mkdir($dir.'/queue',0700);mkdir($dir.'/data',0700);
			$queue=$dir.'/queue';$hash=str_repeat('A',40);$payload=$dir.'/data/payload';
			file_put_contents($payload,'legacy payload');
			$path=$queue.'/'.$hash.'.0000000000000001.actor.list';
			file_put_contents($path,ErasedataManifestCodec::encode($hash,array('base'=>$payload,'multi'=>false,'files'=>array($payload)),1));
			chmod($path,0600);
			rXMLRPCRequest::$responses=array('fixture.nativeCapability'=>'legacy');FileUtil::$log=array();
			$filesystem=new ErasedataSettlingRenameFixture($pattern,2);
			$service=new ErasedataCollector($filesystem,function(){return ERASEDATA_TORRENT_ABSENT;},
				function(){throw new Exception('an absent torrent needs no owned paths');},null,true);
			try
			{
				$service->run($queue,$hash);
				$this->assertEquals(2,count($filesystem->lookups),$case.': the payload and the manifest renames both answered late');
				foreach($filesystem->lookups as $entry=>$count)
					$this->assertSame(2,$count,$case.': the new name is confirmed on its second lookup');
				$this->assertTrue(!file_exists($payload) && !is_link($payload) && !file_exists($path) && !is_link($path),
					$case.': payload and manifest are consumed in the same pass');
				$roots=array_merge(glob($dir.'/data/.erasedata-entry-*') ?: array(),glob($queue.'/.erasedata-entry-*') ?: array());
				$this->assertEquals(array(),$roots,$case.': no private capture root is left behind');
			}
			finally {$this->removeTree($dir);rXMLRPCRequest::$responses=array();}
		}
	}

	public function testPlaintextListMovedAsideIsReportedMovedWhenItsNewNameAnswersLate()
	{
		global $profileMask;$profileMask=0700;
		$dir=sys_get_temp_dir().'/ed-settle-quarantine-'.bin2hex(random_bytes(6));
		mkdir($dir,0700);mkdir($dir.'/queue',0700);
		$queue=$dir.'/queue';$hash=str_repeat('A',40);
		$path=$queue.'/'.$hash.'.0000000000000001.actor.list';
		file_put_contents($path,"/data/legacy plaintext entry\n");chmod($path,0600);$stat=lstat($path);
		rXMLRPCRequest::$responses=array('fixture.nativeCapability'=>'available');FileUtil::$log=array();
		$filesystem=new ErasedataSettlingRenameFixture('/\.unverified$/D',2);
		$service=new ErasedataCollector($filesystem,function(){throw new Exception('presence');},
			function(){throw new Exception('paths');},null,true);
		try
		{
			$service->run($queue,$hash);
			$aside=glob($queue.'/'.basename($path).'.*.unverified') ?: array();
			$this->assertTrue(count($aside)===1 && !file_exists($path) && erasedataSameStatIdentity($stat,lstat($aside[0])),
				'the exact plaintext inode is moved aside');
			$this->assertSame(array(2),array_values($filesystem->lookups),'the aside name is confirmed on its second lookup');
			$log=implode("\n",FileUtil::$log);
			$this->assertTrue(strpos($log,'moved to')!==false && strpos($log,'uncertain quarantine state')===false,
				'the move is reported as done, not as uncertain');
		}
		finally {$this->removeTree($dir);rXMLRPCRequest::$responses=array();}
	}

	public function testCleanupRestoreConfirmsAPublicNameThatAnswersLate()
	{
		global $profileMask;$profileMask=0700;
		$dir=sys_get_temp_dir().'/ed-settle-restore-'.bin2hex(random_bytes(6));
		mkdir($dir,0700);mkdir($dir.'/data',0700);
		$public=$dir.'/data/public';$reservation=$dir.'/cleanup-job';
		file_put_contents($public,'restored bytes');$stat=lstat($public);
		$plain=new ErasedataFilesystemOps();
		try
		{
			$root=erasedataCreateCapturedEntryRoot($public,$reservation,$stat,$plain);
			if(!is_string($root) || !rename($public,erasedataCapturedEntryDataPath($root)))
				throw new RuntimeException('captured-file fixture refused');
			$info=erasedataCapturedEntryRootInfo($root,$public,$reservation,$plain);
			if(!is_array($info)) throw new RuntimeException('captured-file info refused');
			$filesystem=new ErasedataSettlingRenameFixture('#^'.preg_quote($public,'#').'$#D',2);$reason=null;
			$this->assertTrue(erasedataCleanupRestoreCapturedFile($public,$info,$filesystem,$reason),
				'a restored name that answers late is confirmed, not left restore-unverified: '.var_export($reason,true));
			$this->assertSame(2,$filesystem->lookups[$public] ?? 0,'the restored name is confirmed on its second lookup');
			$this->assertTrue(erasedataSameStatIdentity($stat,@lstat($public)) && is_file($public) && file_get_contents($public)==='restored bytes'
				&& !file_exists($root),'the exact inode is back under its public name and the root is gone');
		}
		finally {$this->removeTree($dir);}
	}

	// The cleanup reservation of a directory: its restore rename, and the move of
	// a legacy public link into a tombstone inside the reservation.
	public function testCleanupReservationRestoreConfirmsNamesThatAnswerLate()
	{
		global $profileMask;$profileMask=0700;
		foreach(array('restored directory','legacy link tombstone') as $case)
		{
			$dir=sys_get_temp_dir().'/ed-settle-reservation-'.bin2hex(random_bytes(6));
			mkdir($dir,0700);mkdir($dir.'/queue',0700);mkdir($dir.'/data',0700);
			$path=$dir.'/data/directory';$key=$dir.'/queue/cleanup-job';mkdir($path,0700);
			$plain=new ErasedataFilesystemOps();$identity=$plain->entryIdentity($path);
			try
			{
				$reservation=erasedataDirectoryReservationPath($path,$key,$identity);
				$reserved=erasedataReservationDataPath($reservation);
				if(!is_string($reservation) || !mkdir($reservation,0700) || !erasedataCreatePrivateMarker($reservation,$key)
					|| !rename($path,$reserved) || ($case==='legacy link tombstone' && !symlink($reserved,$path)))
					throw new RuntimeException('directory reservation fixture refused');
				$pattern=$case==='restored directory' ? '#^'.preg_quote($path,'#').'$#D'
					: '#^'.preg_quote($reservation,'#').'/\.bridge-[0-9]+-[0-9]+$#D';
				$filesystem=new ErasedataSettlingRenameFixture($pattern,2);$reason=null;$skip=false;
				$this->assertTrue(erasedataCleanupRestoreReservation($path,$reservation,$key,$filesystem,$reason,$skip),
					$case.': a new name that answers late is confirmed: '.var_export($reason,true));
				$this->assertSame(array(2),array_values($filesystem->lookups),$case.': confirmed on its second lookup');
				$this->assertTrue(is_dir($path) && !is_link($path) && erasedataSameEntryIdentity($identity,$plain->entryIdentity($path))
					&& !file_exists($reservation),$case.': the exact directory is back and the reservation is gone');
			}
			finally {$this->removeTree($dir);}
		}
	}

	// POST-1011-01: a completed record is published by a rename. On drvfs over
	// 9p (cache=0x5) lstat of such a new name answered ENOENT once while the
	// listing already held it; three immediate re-reads then named a
	// manifest-type refusal for a document whose type was never wrong.
	public function testCompletedRecordListedBeforeItsNameAnswersIsSettledAndNeverMisclassified()
	{
		global $profileMask;$profileMask=0700;
		require_once(__DIR__.'/../../php/SourceDefinitionFixture.php');
		$namespace='ErasedataCompletedSettleProbe';
		if(!function_exists($namespace.'\\erasedataNativeCompletedCandidates'))
		{
			// The listing is real; only the first lstat answers of the record's
			// name are missing, as on the mount.
			$code='namespace '.$namespace.';'
				.'class Sequence {public static $misses=0;}'
				.'function lstat($path){if(Sequence::$misses>0){Sequence::$misses--;return false;}return \lstat($path);}';
			$code.=loadFunctionDefinition(__DIR__.'/../../../plugins/erasedata/filesystem.php','erasedataListedEntryStat');
			if(function_exists('erasedataNativeCompletedEntryStat'))
				$code.=loadFunctionDefinition(__DIR__.'/../../../plugins/erasedata/native.php','erasedataNativeCompletedEntryStat');
			$code.=loadFunctionDefinition(__DIR__.'/../../../plugins/erasedata/native.php','erasedataNativeCompletedCandidates');
			foreach(array('ErasedataManifestCodec','ErasedataFilesystemOps') as $class)
				class_alias($class,$namespace.'\\'.$class);
			eval($code);
		}
		$sequence=$namespace.'\\Sequence';$candidates=$namespace.'\\erasedataNativeCompletedCandidates';
		$dir=sys_get_temp_dir().'/ed-settle-completed-'.bin2hex(random_bytes(6));mkdir($dir,0700);
		$hash=str_repeat('A',40);$g='0000000000000001';$payload=$dir.'/payload';
		$paths=array('base'=>$payload,'multi'=>false,'files'=>array($payload));
		$intent=array('incarnation'=>str_repeat('a',32),'nonce'=>str_repeat('b',32),'generation'=>$g,
			'user'=>User::getUser(),'digest'=>hash('sha256',ErasedataManifestCodec::encode($hash,$paths,1)));
		try
		{
			foreach(array('settled'=>2,'unreadable'=>PHP_INT_MAX,'consumed'=>-1) as $case=>$misses)
			{
				$queue=$dir.'/'.$case;mkdir($queue,0700);
				$path=$queue.'/'.$hash.'.'.$g.'.actor.list.completed';
				file_put_contents($path,ErasedataManifestCodec::encodeBound($hash,$paths,1,$intent));chmod($path,0600);
				$filesystem=new class($misses,$path) extends ErasedataFilesystemOps {
					public $calls=0;private $misses;private $path;
					public function __construct($misses,$path){$this->misses=$misses;$this->path=$path;}
					public function entryIdentity($path){
						$this->calls++;
						// Another worker consumes the record while it is settled.
						if($this->misses<0 && $this->calls===2) unlink($this->path);
						if($this->misses<0 || $this->calls<=$this->misses) return(false);
						return(parent::entryIdentity($path));
					}
				};
				$reports=array();
				$retention=function($report,$stat,$bytes,$reason)use(&$reports){$reports[]=array($report,is_array($stat),$reason);};
				$sequence::$misses=3;
				$result=$candidates($queue,$hash,null,$retention,$filesystem);
				$sequence::$misses=0;
				if($case==='settled')
				{
					$this->assertTrue(count($result)===1 && $result[0]['path']===$path
						&& erasedataSameStatIdentity(lstat($path),$result[0]['stat']),'the settled record is a completed candidate');
					$this->assertEquals(array(),$reports,'a settled record is never reported');
					$this->assertEquals(3,$filesystem->calls,'the record is confirmed on its third settled lookup');
				}
				elseif($case==='unreadable')
				{
					$this->assertEquals(array(),$result,'a record that never answers is no candidate');
					$this->assertEquals(array(array($path,false,'unreadable-manifest')),$reports,
						'it is named unreadable, never manifest-type');
					$this->assertEquals(ERASEDATA_RENAME_SETTLE_ATTEMPTS,$filesystem->calls,'the settle stops at its bound');
				}
				else
				{
					$this->assertEquals(array(),$result,'a consumed record is no candidate');
					$this->assertEquals(array(),$reports,'a record that left the listing is positively absent, not refused');
				}
			}
		}
		finally {$this->removeTree($dir);}
	}
}
