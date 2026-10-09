<?php

require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/../../../plugins/erasedata/manifest.php');

class ErasedataNativeIntentTest extends TestCase
{
	public function testBoundedFileReaderRejectsDirectoriesBeforeReading()
	{
		$dir = sys_get_temp_dir().'/ed-reader-'.bin2hex(random_bytes(6));
		mkdir($dir, 0700);
		$diagnostics = array();
		set_error_handler(function($severity, $message) use (&$diagnostics) {
			$diagnostics[] = $message;
			return(true);
		});
		try
		{
			$this->assertEquals(false, ErasedataManifestCodec::readBoundedFile($dir),
				'a directory descriptor is refused as a manifest file');
			$this->assertEquals(array(), $diagnostics,
				'the file reader refuses a non-regular descriptor before fread');
			file_put_contents($dir.'/manifest', 'regular bytes');
			$this->assertEquals('regular bytes', ErasedataManifestCodec::readBoundedFile($dir.'/manifest'),
				'the file reader still accepts a regular descriptor');
			$stream = fopen('php://memory', 'r+');
			fwrite($stream, 'stream bytes');
			rewind($stream);
			$this->assertEquals('stream bytes', ErasedataManifestCodec::readBoundedHandle($stream),
				'the generic bounded reader retains its stream contract');
			fclose($stream);
		}
		finally
		{
			restore_error_handler();
			if(is_file($dir.'/manifest')) unlink($dir.'/manifest');
			rmdir($dir);
		}
	}

	private function paths()
	{
		return(array('base' => '/data/kept', 'multi' => true,
			'files' => array('/data/kept/one.bin', "/data/kept/line\nnon-utf8-\xff.bin")));
	}

	private function intent($payload)
	{
		return(array('incarnation' => str_repeat('a', 32),
			'nonce' => str_repeat('b', 32), 'generation' => '0000000000000001',
			'user' => '', 'digest' => hash('sha256', $payload)));
	}

	public function testDeletionHelperRetainsTheSameLeaseAfterItsPHPParentDies()
	{
		$dir = sys_get_temp_dir().'/ed-lease-'.bin2hex(random_bytes(6));
		mkdir($dir, 0700);
		$lockPath = $dir.'/lease';
		$ready = $dir.'/ready';
		$release = $dir.'/release';
		$helper = $dir.'/helper.php';
		$actor = $dir.'/actor.php';
		file_put_contents($helper, '<?php file_put_contents($argv[1], (string)getmypid());'
			.'$end=microtime(true)+5; while(!file_exists($argv[2]) && microtime(true)<$end) usleep(10000);');
		file_put_contents($actor, '<?php require '.var_export(
			realpath(__DIR__.'/../../../plugins/erasedata/filesystem.php'), true).';'
			.'$lease=fopen('.var_export($lockPath, true).',"r+e");flock($lease,LOCK_EX);'
			.'if(method_exists("ErasedataFilesystemOps","holdDeletionLease"))'
			.' ErasedataFilesystemOps::holdDeletionLease($lease);'
			.'$method=new ReflectionMethod("ErasedataFilesystemOps","runRenameHelper");'
			.'@$method->setAccessible(true);'
			.'$method->invoke(null,array(PHP_BINARY,$argv[1],$argv[2],$argv[3]),$argv[2]);');
		file_put_contents($lockPath, '');
		$descriptors = array(0 => array('file', '/dev/null', 'r'),
			1 => array('file', '/dev/null', 'w'), 2 => array('file', $dir.'/stderr', 'w'));
		$child = proc_open(array(PHP_BINARY, $actor, $helper, $ready, $release), $descriptors, $pipes);
		$contender = fopen($lockPath, 'r+');
		try
		{
			$end = microtime(true) + 3;
			while(!is_file($ready) && microtime(true) < $end) usleep(10000);
			$this->assertTrue(is_file($ready), 'the real helper starts before its PHP parent is killed');
			proc_terminate($child, 9);
			proc_close($child);
			$child = null;
			$acquired = flock($contender, LOCK_EX | LOCK_NB);
			$this->assertEquals(false, $acquired,
				'a surviving filesystem helper still holds the exact inherited deletion lease');
			if($acquired) flock($contender, LOCK_UN);
			file_put_contents($release, 'release');
			$end = microtime(true) + 3;
			$acquired = false;
			while(!($acquired = flock($contender, LOCK_EX | LOCK_NB)) && microtime(true) < $end)
				usleep(10000);
			$this->assertTrue($acquired, 'the lease becomes available after the last helper exits');
			if($acquired) flock($contender, LOCK_UN);
		}
		finally
		{
			file_put_contents($release, 'release');
			if(is_resource($child)) { proc_terminate($child, 9); proc_close($child); }
			fclose($contender);
			foreach(glob($dir.'/*') as $file) unlink($file);
			rmdir($dir);
		}
	}

	public function testNativePayloadIntentRoundTripsExactAuthorityAndPathBytes()
	{
		$this->assertTrue(method_exists('ErasedataManifestCodec', 'encodeBound'),
			'the native-bound payload writer is available');
		if(!method_exists('ErasedataManifestCodec', 'encodeBound')) return;
		$hash = str_repeat('A', 40);
		$paths = $this->paths();
		$payload = ErasedataManifestCodec::encode($hash, $paths, 1);
		$intent = $this->intent($payload);
		$bytes = ErasedataManifestCodec::encodeBound($hash, $paths, 1, $intent);
		$this->assertTrue(is_string($bytes), 'an exact native intent is encodable');
		$record = ErasedataManifestCodec::decodeBytes($bytes, $hash);
		$this->assertEquals(4, $record['version'], 'native authority has a distinct version');
		$this->assertEquals('remove_payload', $record['operation'], 'cleanup authority stays separate');
		$this->assertEquals($intent, $record['native_intent'], 'all native authority fields survive decoding');
		$this->assertEquals($paths['files'], $record['files'], 'arbitrary admitted path bytes survive decoding');
		$this->assertEquals($bytes, ErasedataManifestCodec::encodeBound($hash, $paths, 1, $intent),
			'the native-bound writer is byte deterministic');
	}

	public function testNativePayloadIntentRejectsChangedPayloadAndMalformedAuthority()
	{
		$this->assertTrue(method_exists('ErasedataManifestCodec', 'encodeBound'),
			'the native-bound payload writer is available');
		if(!method_exists('ErasedataManifestCodec', 'encodeBound')) return;
		$hash = str_repeat('A', 40);
		$paths = $this->paths();
		$payload = ErasedataManifestCodec::encode($hash, $paths, 1);
		$intent = $this->intent($payload);
		$bytes = ErasedataManifestCodec::encodeBound($hash, $paths, 1, $intent);
		$json = json_decode($bytes, true);
		foreach(array('incarnation' => str_repeat('A', 32), 'nonce' => str_repeat('b', 31),
			'generation' => '0000000000000000', 'user' => '../foreign',
			'digest' => str_repeat('c', 64), 'extra' => 'unowned') as $key => $value)
		{
			$bad = $intent;
			$bad[$key] = $value;
			$this->assertEquals(false, ErasedataManifestCodec::encodeBound($hash, $paths, 1, $bad),
				'malformed authority is refused by the writer: '.$key);
			$changed = $json;
			$changed['native_intent'] = $bad;
			$this->assertEquals(false, ErasedataManifestCodec::decodeBytes(json_encode($changed), $hash),
				'malformed authority is refused by the reader: '.$key);
		}
		$json['files'][0] = base64_encode('/data/kept/another.bin');
		$this->assertEquals(false, ErasedataManifestCodec::decodeBytes(json_encode($json), $hash),
			'changed payload bytes cannot reuse the original native proof');
		$this->assertEquals(false, ErasedataManifestCodec::decodeBytes(
			str_replace('"version":4', '"version":2,"version":4', $bytes), $hash),
			'duplicate serialized members cannot manufacture native authority');
	}
}
