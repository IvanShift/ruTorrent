<?php
require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/CollectorFixture.php');
require_once(__DIR__.'/../../../plugins/erasedata/collector.php');

class ErasedataNativeCompletionTest extends TestCase
{
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
			foreach(array('reader','legacy-staging','bound-staging','bound-staging-sibling','completed','legacy-drain') as $case)
			{
				$queue = $dir.'/'.$case; mkdir($queue,0700);
				$path = $queue.'/'.$hash.'.'.$generation.'.actor.'
					.($case === 'completed' ? 'list.completed' :
						(in_array($case,array('bound-staging-sibling','legacy-drain'),true) ? 'list' : 'tmp'));
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
}
