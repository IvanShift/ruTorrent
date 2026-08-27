<?php

require_once(__DIR__ . '/../../php/TestCase.php');
// One shared collector environment: FileUtil/RPC stubs, the replaceable
// metainfo source and the scripted ErasedataFilesystemOps subclass.
require_once(__DIR__ . '/CollectorFixture.php');

class ErasedataScheduleSettingsFake
{
	public $calls = array();

	public function getAlignedScheduleCommand($name, $interval, $command)
	{
		$this->calls[] = array($name, $interval, $command);
		return(new rXMLRPCCommand('schedule', array($name.User::getUser(), 'aligned', (string)$interval, $command)));
	}
}

$profileMask = 0777;
require_once(__DIR__ . '/../../../plugins/erasedata/manifest.php');
require_once(__DIR__ . '/../../../plugins/erasedata/removewithdata.php');
require_once(__DIR__ . '/../../../plugins/erasedata/collector.php');
require_once(__DIR__ . '/../../../plugins/erasedata/update.php');

// A payload far larger than the manifest ceiling that counts exactly how many
// bytes a reader consumes, so "bounded while reading" is an observable fact
// rather than a claim about the source.
if(!class_exists('ErasedataOversizeStream'))
{
	class ErasedataOversizeStream
	{
		const TOTAL_BYTES = 134217728; // 2 x ErasedataManifestCodec::MAX_MANIFEST_BYTES
		public static $served = 0;
		public static $total = self::TOTAL_BYTES;
		public $context;
		private $offset = 0;

		public static function register($total = self::TOTAL_BYTES)
		{
			self::$served = 0;
			self::$total = $total;
			if(!in_array('erasedataoversize', stream_get_wrappers(), true))
				stream_wrapper_register('erasedataoversize', 'ErasedataOversizeStream');
		}
		public function stream_open($path, $mode, $options, &$openedPath) { return(true); }
		public function stream_read($count)
		{
			$remaining = self::$total - $this->offset;
			if($remaining <= 0)
				return('');
			$size = $count < $remaining ? $count : $remaining;
			$this->offset += $size;
			self::$served += $size;
			return(str_repeat('x', $size));
		}
		public function stream_eof() { return($this->offset >= self::$total); }
		public function stream_stat() { return(array()); }
		public function stream_close() {}
		public function url_stat($path, $flags) { return(array()); }
	}
}

// A queue-directory wrapper that writes part of the first chunk and then
// stalls, so a genuinely partial staged manifest reaches the production writer.
if(!class_exists('ErasedataPartialWriteStream'))
{
	class ErasedataPartialWriteStream
	{
		const SCHEME = 'erasedatapartial';
		public $context;
		private $handle = null;
		private $wrote = false;

		public static function register()
		{
			if(!in_array(self::SCHEME, stream_get_wrappers(), true))
				stream_wrapper_register(self::SCHEME, 'ErasedataPartialWriteStream');
		}
		public static function real($path)
		{
			return(substr($path, strlen(self::SCHEME.'://')));
		}
		public function stream_open($path, $mode, $options, &$openedPath)
		{
			$this->handle = @fopen(self::real($path), $mode);
			return($this->handle !== false);
		}
		public function stream_write($data)
		{
			if($this->wrote || !is_resource($this->handle))
				return(0);
			$this->wrote = true;
			$half = strlen($data) > 1 ? intdiv(strlen($data), 2) : 1;
			$written = @fwrite($this->handle, substr($data, 0, $half));
			return($written === false ? 0 : $written);
		}
		public function stream_flush() { return(true); }
		public function stream_eof() { return(true); }
		public function stream_stat() { return(@fstat($this->handle)); }
		public function stream_close()
		{
			if(is_resource($this->handle))
				@fclose($this->handle);
		}
		public function url_stat($path, $flags)
		{
			$real = self::real($path);
			return(($flags & STREAM_URL_STAT_LINK) ? @lstat($real) : @stat($real));
		}
		public function unlink($path) { return(@unlink(self::real($path))); }
	}
}

// A removal seam that takes a directory even when it still holds bytes. The
// production seam is rmdir(), which refuses one, so only a greedy seam can show
// that the emptiness check in erasedataResumeCapturedEntries() is a decision of
// its own rather than rmdir()'s refusal restated.
if(!class_exists('ErasedataGreedyRemovalFilesystem'))
{
	class ErasedataGreedyRemovalFilesystem extends ErasedataFilesystemOps
	{
		public function removeDirectory($path)
		{
			$entries = @scandir($path);
			if(is_array($entries))
				foreach(array_diff($entries, array('.', '..')) as $entry)
				{
					$child = $path.'/'.$entry;
					if(is_dir($child) && !is_link($child))
						$this->removeDirectory($child);
					else
						@unlink($child);
				}
			return(@rmdir($path));
		}
	}
}

class RemoveWithDataTest extends TestCase
{
	private $dir;

	public function setUp()
	{
		$this->dir = sys_get_temp_dir().'/erasedata-test-'.getmypid();
		@mkdir($this->dir, 0777, true);
		FileUtil::$settingsPath = $this->dir;
	}

	// setUp() runs once per class, so each test starts from a clean slate here.
	private function reset()
	{
		global $profileMask;
		$profileMask = 0777;
		ErasedataCollectorTestState::$source = false;
		ErasedataCollectorTestState::$indexCountFile = null;
		ErasedataCollectorTestState::$indexBuilds = 0;
		foreach(array_diff(scandir($this->dir), array('.', '..', 'erasedata')) as $entry)
			$this->removePath($this->dir.'/'.$entry);
		@mkdir($this->dir.'/erasedata', 0777, true);
		foreach(array_diff(scandir($this->dir.'/erasedata'), array('.', '..')) as $entry)
			$this->removePath($this->dir.'/erasedata/'.$entry);
		FileUtil::$log = array();
		rXMLRPCRequest::$responses = array();
		rXMLRPCRequest::$requested = array();
		rXMLRPCRequest::$erased = array();
		rXMLRPCRequest::$commandCalls = array();
	}

	public function tearDown()
	{
		foreach(array_diff(scandir($this->dir), array('.', '..', 'erasedata')) as $entry)
			$this->removePath($this->dir.'/'.$entry);
		foreach(array_diff(scandir($this->dir.'/erasedata'), array('.', '..')) as $entry)
			$this->removePath($this->dir.'/erasedata/'.$entry);
		@rmdir($this->dir.'/erasedata');
		@rmdir($this->dir);
	}

	// -- helpers ------------------------------------------------------------

	private function frozen($ok, $val) { rXMLRPCRequest::$responses["d.get_base_path"] = array("ok"=>$ok, "val"=>$val); }
	private function stored($ok, $val) { rXMLRPCRequest::$responses["d.get_directory"] = array("ok"=>$ok, "val"=>$val); }
	private function eraseOk($callback = null)
	{
		rXMLRPCRequest::$responses["d.set_custom5"] = array(
			"ok"=>true, "val"=>array("","",""), "callback"=>$callback);
	}
	private function eraseFail() { rXMLRPCRequest::$responses["d.set_custom5"] = array("ok"=>false, "val"=>array()); }
	private function probe($runResult, $fault, $val, $faultString = '', $faultCode = 0)
	{
		rXMLRPCRequest::$responses["d.hash"] = array(
			"runResult" => $runResult,
			"fault" => $fault,
			"val" => $val,
			"faultString" => $faultString,
			"faultCode" => $faultCode
		);
	}
	private function hash($character = 'A') { return(str_repeat($character, 40)); }
	private function modeOf($path) { clearstatcache(true, $path); return(fileperms($path) & 0777); }
	private function cleanupIdentity($path)
	{
		$canonical = realpath($path);
		$lstat = lstat($path);
		$stat = stat($path);
		return(array(
			'canonical' => $canonical,
			'lstat' => array('dev' => $lstat['dev'], 'ino' => $lstat['ino']),
			'stat' => array('dev' => $stat['dev'], 'ino' => $stat['ino']),
			'size' => $stat['size'],
			'mtime' => $stat['mtime'],
		));
	}

	private function cleanupEntry($path)
	{
		return(array('path' => $path, 'identity' => $this->cleanupIdentity($path)));
	}

	private function parseFaultThroughProductionXMLRPC($faultString)
	{
		$fixture = $this->dir.'/xmlrpc-fault-fixture';
		@mkdir($fixture, 0777, true);
		copy(__DIR__.'/../../../php/xmlrpc.php', $fixture.'/xmlrpc.php');
		file_put_contents($fixture.'/util.php', '<?php '
			.'class FileUtil{public static function toLog($message){}}');
		file_put_contents($fixture.'/settings.php', '<?php '
			.'class rTorrentSettings{public static function get(){static $instance;'
			.'if(!$instance)$instance=new self();return $instance;}'
			.'public function patchDeprecatedCommand($command,$name){} '
			.'public function patchDeprecatedRequest($commands){} '
			.'public function getCommand($command){return $command;} '
			.'public function maxContentSize(){return 1048576;}}');
		file_put_contents($fixture.'/scgitransport.php', '<?php '
			.'class rSCGITransport{public static $raw="";'
			.'public static function send($host,$port,$data,$trusted,$timeout,&$error)'
			.'{return array("raw"=>self::$raw);}}');
		// The production XMLRPC parser needs its own include tree, so it runs in
		// a real script that receives one absolute JSON scenario filename.
		$scenario = $fixture.'/scenario.json';
		file_put_contents($scenario, json_encode(array(
			'fixture' => $fixture, 'fault' => $faultString)));
		file_put_contents($fixture.'/run.php', "<?php\n"
			.'$scenario=json_decode(@file_get_contents($argv[1]),true);'
			.'if(!is_array($scenario)||!isset($scenario["fixture"],$scenario["fault"])'
			.'||!is_string($scenario["fixture"])||!is_string($scenario["fault"]))exit(2);'
			.'set_include_path($scenario["fixture"]);'
			.'require($scenario["fixture"]."/xmlrpc.php");'
			.'$escaped=htmlspecialchars($scenario["fault"],ENT_NOQUOTES,"UTF-8");'
			.'rSCGITransport::$raw="<methodResponse><fault><value><struct>".'
			.'"<member><name>faultCode</name><value><i4>-501</i4></value></member>".'
			.'"<member><name>faultString</name><value><string>".$escaped."</string></value></member>".'
			.'"</struct></value></fault></methodResponse>";'
			.'$rpcLogCalls=false;$rpcLogFaults=false;$rpcTimeOut=1;$scgi_host="";$scgi_port=0;'
			.'$request=new rXMLRPCRequest(new rXMLRPCCommand("d.hash",str_repeat("A",40)));'
			.'$run=$request->run();echo json_encode(array("run"=>$run,"fault"=>$request->fault,'
			.'"faultString"=>$request->faultString,"rawFaultString"=>property_exists($request,"rawFaultString")'
			.'?$request->rawFaultString:null));');
		$output = array();
		$status = 0;
		exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -f '.escapeshellarg($fixture.'/run.php')
			.' -- '.escapeshellarg($scenario).' 2>&1', $output, $status);
		return(array($status, implode("\n", $output), json_decode(implode("\n", $output), true)));
	}

	private function removePath($path)
	{
		if(is_link($path) || is_file($path))
		{
			@unlink($path);
			return;
		}
		if(!is_dir($path))
			return;
		foreach(array_diff(scandir($path), array('.', '..')) as $entry)
			$this->removePath($path.'/'.$entry);
		@rmdir($path);
	}

	private function reservationDataPath($reservation)
	{
		$data = $reservation.'/directory';
		return(file_exists($data) || is_link($data) ? $data : $reservation);
	}

	private function manifestFiles($hash, $type = 'list')
	{
		$files = array();
		$legacy = $this->dir.'/erasedata/'.$hash.'.'.$type;
		if(is_file($legacy))
			$files[] = $legacy;
		foreach(glob($this->dir.'/erasedata/'.$hash.'.*.'.$type) as $file)
			if(is_file($file))
				$files[] = $file;
		sort($files, SORT_STRING);
		return(array_values(array_unique($files)));
	}

	private function onlyManifest($hash, $type = 'list')
	{
		$files = $this->manifestFiles($hash, $type);
		return(count($files) === 1 ? $files[0] : false);
	}

	private function manifestRecordFor($hash, $type = 'list')
	{
		$f = $this->onlyManifest($hash, $type);
		if(!is_file($f))
			return(false);
		$content = file_get_contents($f);
		return(ErasedataManifestCodec::decodeBytes($content, $hash));
	}

	private function listFor($hash)
	{
		$f = $this->onlyManifest($hash);
		if(!is_file($f))
			return(false);
		$content = file_get_contents($f);
		$record = ErasedataManifestCodec::decodeBytes($content, $hash);
		if(is_array($record))
		{
			$lines = $record['files'];
			$lines[] = $record['base'];
			$lines[] = $record['multi'] ? "1" : "0";
			$lines[] = (string)$record['force'];
			return($lines);
		}
		return(file($f, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
	}

	private function writeManifest($name, $dataPath)
	{
		$this->writeManifestLines($name, array($dataPath), $dataPath, 0, 1);
	}

	private function writeManifestLines($name, $files, $base, $multi, $force)
	{
		$hash = preg_match('/^([0-9A-Fa-f]{40})/D', $name, $m) ? $m[1] : str_repeat('A', 40);
		$content = ErasedataManifestCodec::encode($hash, array('files' => $files, 'base' => $base, 'multi' => (bool)$multi), $force);
		if($content === false)
		{
			$lines = array_merge($files, array($base, (string)$multi, (string)$force));
			$content = implode("\n", $lines)."\n";
		}
		file_put_contents($this->dir.'/erasedata/'.$name, $content);
	}

	private function writeLegacyManifestLines($name, $files, $base, $multi, $force)
	{
		$lines = array_merge($files, array($base, (string)$multi, (string)$force));
		file_put_contents($this->dir.'/erasedata/'.$name, implode("\n", $lines)."\n");
	}

	private function runCopiedAction($source, $rawBody, $withErasedataHelper)
	{
		$fixture = $this->dir.'/action-fixture-'.bin2hex(random_bytes(4));
		$plugin = strpos($source, '/httprpc/') !== false ? 'httprpc' : 'erasedata';
		$actionDir = $fixture.'/plugins/'.$plugin;
		$commandLog = $fixture.'/commands.json';
		@mkdir($actionDir, 0777, true);
		@mkdir($fixture.'/php', 0777, true);
		copy($source, $actionDir.'/action.php');
		file_put_contents($fixture.'/php/xmlrpc.php', '<?php '
			.'class FileUtil { public static function toLog($message) {} public static function getPluginConf($plugin) { return ""; } } '
			.'class rXMLRPCCommand { public $command; public $params; public function __construct($command,$params=null) {'
			.'$this->command=$command;$this->params=$params;} } '
			.'class rXMLRPCRequest { public static $commands=array(); public $val=array(); public $fault=false; public $faultString=""; '
			.'private $items=array(); public function __construct($items=null) { if(is_array($items))$this->items=$items;'
			.'else if($items!==null)$this->items=array($items); } public function addCommand($item) {$this->items[]=$item;} '
			.'public function success($trusted=true) { foreach($this->items as $item)self::$commands[]=$item->command; return true; } } '
			.'function getCmd($command) { return $command; } '
			.'class CachedEcho { public static function send($content,$type) {} } '
			.'class JSON { public static function safeEncode($value) { return json_encode($value); } }');
		file_put_contents($fixture.'/php/xmlrpc_proxy.php', "<?php\n");
		file_put_contents($fixture.'/php/xmlrpc_path.php', "<?php\n");
		if($plugin === 'httprpc')
			file_put_contents($actionDir.'/rpccache.php', "<?php\n");
		if($withErasedataHelper)
		{
			$erasedataDir = $fixture.'/plugins/erasedata';
			@mkdir($erasedataDir, 0777, true);
			copy(__DIR__.'/../../../plugins/erasedata/manifest.php', $erasedataDir.'/manifest.php');
			file_put_contents($erasedataDir.'/removewithdata.php', '<?php require_once(dirname(__FILE__)."/manifest.php"); '
				.'function erasedataRemoveWithData($hashes,$force) { rXMLRPCRequest::$commands[]="helper:".'
				.'(is_string($force)?$force:gettype($force)); return array(); }');
		}
		// The copied production action needs its own include tree, so it runs in
		// a real script that receives one absolute JSON scenario filename.
		$scenario = $fixture.'/scenario.json';
		file_put_contents($scenario, json_encode(array(
			'body' => $rawBody, 'action' => $actionDir.'/action.php', 'log' => $commandLog)));
		file_put_contents($fixture.'/run.php', "<?php\n"
			.'$scenario=json_decode(@file_get_contents($argv[1]),true);'
			.'if(!is_array($scenario)||!isset($scenario["body"],$scenario["action"],$scenario["log"])'
			.'||!is_string($scenario["body"])||!is_string($scenario["action"])'
			.'||!is_string($scenario["log"]))exit(2);'
			.'$HTTP_RAW_POST_DATA=$scenario["body"];chdir(dirname($scenario["action"]));'
			.'require($scenario["action"]);'
			.'file_put_contents($scenario["log"],json_encode(rXMLRPCRequest::$commands));');
		$output = array();
		$status = 0;
		exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -f '.escapeshellarg($fixture.'/run.php')
			.' -- '.escapeshellarg($scenario).' 2>&1', $output, $status);
		$commands = is_file($commandLog) ? json_decode(file_get_contents($commandLog), true) : null;
		return(array($status, implode("\n", $output), $commands));
	}

	private function cleanupSuccessorFixture($val, $owned, $override)
	{
		$fixture = null;
		if(is_array($owned) && isset($owned['base'], $owned['multi'], $owned['files'])
			&& is_array($owned['files']) && count($owned['files']))
		{
			$hash = is_array($val) && count($val) === 1 ? $val[0] : '';
			$multi = $owned['multi'] ? 1 : 0;
			if($multi)
			{
				$directory = rtrim($owned['base'], '/');
				$stored = array();
				$metainfoFiles = array();
				foreach($owned['files'] as $file)
				{
					$prefix = $directory.'/';
					if(strpos($file, $prefix) !== 0)
						throw new RuntimeException('Multi-file successor fixture escapes its directory');
					$relative = substr($file, strlen($prefix));
					$stored[] = $relative;
					$metainfoFiles[] = array('path' => explode('/', $relative), 'length' => 1);
				}
				$info = array('name' => basename($directory), 'files' => $metainfoFiles);
			}
			else
			{
				$directory = dirname($owned['files'][0]);
				$stored = array(basename($owned['files'][0]));
				$info = array('name' => $stored[0], 'length' => 1);
			}
			$fixture = array(
				'source' => array('hash' => $hash, 'info' => $info),
				'frozen' => array('ok' => true, 'fault' => false,
					'val' => array_merge(array($owned['base'], $multi), $owned['files'])),
				'stored' => array('ok' => true, 'fault' => false,
					'val' => array_merge(array($directory, $multi), $stored)),
			);
		}
		if(is_array($override))
		{
			if(!is_array($fixture)) $fixture = array();
			foreach($override as $key => $value)
				$fixture[$key] = $value;
		}
		return($fixture);
	}

	// -- collector harness --------------------------------------------------

	// Every collector run is described by one structured scenario array. The
	// named seam options below are translated into scripted operations of the
	// ErasedataCollectorFixture, which is the only injection point.
	private function collectorDefaults()
	{
		return(array(
			'ok' => true, 'fault' => false, 'val' => array(''), 'faultString' => '',
			'swap' => null, 'owned' => null, 'generation' => null,
			'successorOverride' => null, 'onlyHash' => null, 'debug' => false,
			'captureLogs' => false, 'indexCountFile' => null,
			'publicCollectorHash' => null, 'filesystem' => array(),
			'rmdirFail' => null, 'rmdirSwap' => null, 'rmdirCrash' => null,
			'restoreCollision' => null, 'reservedSwap' => null,
			'forceTargetSwap' => null, 'forceTargetRecreate' => null,
			'forceTraverseSwap' => null, 'cleanupCrash' => null,
			'forceCaptureCollision' => null, 'reservationInitCrash' => null,
			'forceCaptureInitCrash' => null, 'containerRemovalFail' => null,
			'cleanupUnlinkFail' => null, 'commitTokenUnlinkFail' => null,
			'artifactReadCountFile' => null, 'successorTransition' => null,
			'successorObservationCountFile' => null,
		));
	}

	private function collectorInode($path)
	{
		clearstatcache(true, $path);
		$stat = @lstat($path);
		return(is_array($stat)
			? array('dev' => (string)$stat['dev'], 'ino' => (string)$stat['ino'])
			: array('dev' => 'absent', 'ino' => 'absent'));
	}

	// The visible recovery link points at the private backing directory the
	// force branch works on.
	private function collectorRecoveryTarget($path)
	{
		$target = @readlink($path);
		return(is_string($target) ? $target : $path);
	}

	private function collectorScriptedOperations(array $options)
	{
		$scenario = $options['filesystem'];
		if($options['rmdirFail'] !== null)
			$scenario['removeDirectory:*'] = array(
				'inode' => $this->collectorInode($options['rmdirFail']), 'result' => false);
		if($options['rmdirSwap'] !== null)
			$scenario['rename:*'] = array(
				'inode' => $this->collectorInode($options['rmdirSwap']), 'action' => 'swap-source');
		if($options['rmdirCrash'] !== null)
			$scenario['removeDirectory:*'] = array(
				'inode' => $this->collectorInode($options['rmdirCrash']), 'action' => 'exit',
				'content' => array('name' => 'crash-data.bin', 'bytes' => 'reserved-bytes'));
		if($options['restoreCollision'] !== null)
			$scenario['makeSymlink:*'] = array(
				'path' => $options['restoreCollision'], 'action' => 'collide');
		if($options['reservedSwap'] !== null)
			$scenario['rename:*'] = array(
				'inode' => $this->collectorInode($options['reservedSwap']),
				'action' => 'swap-destination', 'at' => 'after');
		if($options['forceTargetSwap'] !== null)
			$scenario['rename:*'] = array(
				'path' => $this->collectorRecoveryTarget($options['forceTargetSwap']),
				'action' => 'swap-source',
				'content' => array('name' => 'replacement.bin', 'bytes' => 'replacement'));
		if($options['forceTargetRecreate'] !== null)
			$scenario['removeDirectory:*'] = array(
				'inode' => $this->collectorInode(
					$this->collectorRecoveryTarget($options['forceTargetRecreate'])),
				'action' => 'recreate', 'at' => 'after',
				'content' => array('name' => 'recreated.bin', 'bytes' => 'recreated'));
		if($options['forceTraverseSwap'] !== null)
		{
			$traverse = array(
				'inode' => $this->collectorInode(
					$this->collectorRecoveryTarget($options['forceTraverseSwap'])),
				'action' => 'swap-source', 'at' => 'after', 'record_inode' => true);
			if($options['forceTraverseSwap'] !== 'empty')
				$traverse['content'] = array('name' => 'replacement.bin', 'bytes' => 'replacement');
			$scenario['openDirectoryReference:*'] = $traverse;
		}
		if($options['cleanupCrash'] === 'tombstone')
			$scenario['unlinkCapturedEntry:*'] = array('basename' => 'directory',
				'contains' => '.force-', 'action' => 'exit', 'at' => 'after');
		if($options['cleanupCrash'] === 'bridge')
			$scenario['unlinkCapturedEntry:*'] = array('basename' => 'directory',
				'not_contains' => '.force-', 'action' => 'exit', 'at' => 'after');
		if($options['cleanupCrash'] === 'container')
			$scenario['removePrivateContainer:*'] = array(
				'basename_prefix' => '.erasedata-rmdir-', 'action' => 'exit', 'at' => 'after');
		if($options['forceCaptureCollision'] !== null)
			$scenario['makeDirectory:*'] = array('contains' => '.force-', 'action' => 'collide');
		if($options['reservationInitCrash'] === 'created')
			$scenario['makeDirectory:*'] = array('basename_prefix' => '.erasedata-rmdir-',
				'action' => 'exit', 'at' => 'after');
		if($options['reservationInitCrash'] === 'initialized')
			$scenario['rename:*'] = array('to_contains' => '/.erasedata-rmdir-', 'action' => 'exit');
		if($options['forceCaptureInitCrash'] === 'created')
			$scenario['makeDirectory:*'] = array('contains' => '.force-',
				'action' => 'exit', 'at' => 'after');
		if($options['forceCaptureInitCrash'] === 'initialized')
			$scenario['rename:*'] = array('to_contains' => '.force-', 'action' => 'exit');
		if($options['containerRemovalFail'] !== null)
			$scenario['removePrivateContainer:*'] = array('result' => false);
		if($options['cleanupUnlinkFail'] !== null)
			$scenario['unlinkCapturedEntry:*'] = array(
				'path' => $options['cleanupUnlinkFail'], 'result' => false);
		if($options['commitTokenUnlinkFail'] !== null)
			$scenario['unlink:*'] = array(
				'path' => $options['commitTokenUnlinkFail'], 'result' => false);
		if($options['artifactReadCountFile'] !== null)
			$scenario['entryIdentity:*'] = array('contains' => '.cleanup.',
				'count_file' => $options['artifactReadCountFile']);
		if($options['successorObservationCountFile'] !== null)
			$scenario['entryIdentity:*'] = array(
				'paths' => is_array($options['owned']) && isset($options['owned']['files'])
					? array_values($options['owned']['files']) : array(),
				'count_file' => $options['successorObservationCountFile']);
		// The second observation of the successor name is the batch revalidation
		// that runs after every obsolete-file seam and before the first unlink.
		if(is_array($options['successorTransition']))
			$scenario['entryIdentity:2'] = array(
				'path' => $options['successorTransition']['new'],
				'action' => 'transition',
				'kind' => $options['successorTransition']['kind'],
				'old' => $options['successorTransition']['old'],
				'new' => $options['successorTransition']['new']);
		return($scenario);
	}

	private function collectorCrashes(array $scenario)
	{
		foreach($scenario as $entry)
			if(is_array($entry) && isset($entry['action']) && $entry['action'] === 'exit')
				return(true);
		return(false);
	}

	private function runCollector(array $scenario)
	{
		$defaults = $this->collectorDefaults();
		$unknown = array_diff_key($scenario, $defaults);
		if(count($unknown))
			throw new InvalidArgumentException(
				'Unknown collector scenario keys: '.implode(', ', array_keys($unknown)));
		$options = $scenario + $defaults;
		$options = $this->collectorResponse($options['ok'], $options['fault'],
			$options['val'], $options['faultString']) + $options;
		$options['filesystem'] = $this->collectorScriptedOperations($options);
		$successor = $this->cleanupSuccessorFixture(
			$options['val'], $options['owned'], $options['successorOverride']);
		$responses = array(
			'd.hash' => array('ok'=>$options['ok'], 'fault'=>$options['fault'],
				'val'=>$options['val'], 'swap'=>$options['swap'],
				'faultString'=>$options['faultString'], 'byHash'=>$options['generation']),
			'd.get_base_path' => is_array($successor) && isset($successor['frozen'])
				? $successor['frozen'] + array('swap'=>null)
				: array('ok'=>false, 'fault'=>false, 'val'=>array(), 'swap'=>null),
			'd.get_directory' => is_array($successor) && isset($successor['stored'])
				? $successor['stored'] + array('swap'=>null)
				: array('ok'=>false, 'fault'=>false, 'val'=>array(), 'swap'=>null),
		);
		$source = is_array($successor) && array_key_exists('source', $successor)
			? $successor['source'] : false;
		return($this->collectorCrashes($options['filesystem'])
			? $this->runCollectorSubprocess($options, $responses, $source)
			: $this->runCollectorInProcess($options, $responses, $source));
	}

	// Normal cases drive the extracted service directly.
	private function runCollectorInProcess(array $options, array $responses, $source)
	{
		global $erasedebug_enabled, $argv;
		$saved = array(
			'responses' => rXMLRPCRequest::$responses,
			'requested' => rXMLRPCRequest::$requested,
			'erased' => rXMLRPCRequest::$erased,
			'calls' => rXMLRPCRequest::$commandCalls,
			'source' => ErasedataCollectorTestState::$source,
			'countFile' => ErasedataCollectorTestState::$indexCountFile,
			'debug' => isset($erasedebug_enabled) ? $erasedebug_enabled : false,
			'argv' => $argv,
			'log' => count(FileUtil::$log),
		);
		rXMLRPCRequest::$responses = $responses;
		ErasedataCollectorTestState::$source = $source;
		ErasedataCollectorTestState::$indexCountFile = $options['indexCountFile'];
		$erasedebug_enabled = (bool)$options['debug'];
		$argv = array('update.php', 'rutorrent');
		if($options['onlyHash'] !== null)
			$argv[] = $options['onlyHash'];
		$output = '';
		ob_start();
		try {
			if($options['publicCollectorHash'] !== null)
				erasedataRunCollector(FileUtil::getSettingsPath().'/erasedata',
					$options['publicCollectorHash']);
			else
				erasedataCollectorMain(new ErasedataCollectorFixture($options['filesystem']));
		} catch(Throwable $e) {
			echo 'Uncaught '.get_class($e).': '.$e->getMessage()."\n";
		} finally {
			$output = ob_get_clean();
		}
		$logs = array_slice(FileUtil::$log, $saved['log']);
		rXMLRPCRequest::$responses = $saved['responses'];
		rXMLRPCRequest::$requested = $saved['requested'];
		rXMLRPCRequest::$erased = $saved['erased'];
		rXMLRPCRequest::$commandCalls = $saved['calls'];
		ErasedataCollectorTestState::$source = $saved['source'];
		ErasedataCollectorTestState::$indexCountFile = $saved['countFile'];
		$erasedebug_enabled = $saved['debug'];
		$argv = $saved['argv'];
		clearstatcache();
		$status = preg_match('/(Fatal|Parse) error|Uncaught/', $output) === 1 ? 255 : 0;
		if($options['debug'] || $options['captureLogs'])
			$output .= '__ERASEDATA_LOG__'.json_encode($logs);
		return(array($status, $output));
	}

	// Only genuinely crash-only cases need their own process. The runner takes
	// exactly one argument: an absolute JSON scenario filename it validates.
	private function runCollectorSubprocess(array $options, array $responses, $source)
	{
		global $profileMask;
		$token = bin2hex(random_bytes(6));
		$scenarioFile = sys_get_temp_dir().'/erasedata-scenario-'.$token.'.json';
		$logFile = sys_get_temp_dir().'/erasedata-log-'.$token.'.json';
		$payload = array(
			'mode' => 'collect',
			'settings' => $this->dir,
			'profileMask' => isset($profileMask) ? (int)$profileMask : 0777,
			'debug' => (bool)$options['debug'],
			'onlyHash' => $options['onlyHash'],
			'publicCollectorHash' => $options['publicCollectorHash'],
			'indexCountFile' => $options['indexCountFile'],
			'source' => $source,
			'responses' => $responses,
			'scenario' => $options['filesystem'],
			'logFile' => $logFile,
		);
		$encoded = json_encode($payload);
		$this->assertTrue(is_string($encoded), 'the crash scenario must be JSON encodable');
		file_put_contents($scenarioFile, $encoded);
		$runner = realpath(__DIR__.'/CollectorFixture.php');
		$output = array();
		$status = 0;
		exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -f '.escapeshellarg($runner)
			.' -- '.escapeshellarg($scenarioFile).' 2>&1', $output, $status);
		clearstatcache();
		$text = implode("\n", $output);
		if($options['debug'] || $options['captureLogs'])
			$text .= '__ERASEDATA_LOG__'.(is_file($logFile) ? file_get_contents($logFile) : '[]');
		@unlink($scenarioFile);
		@unlink($logFile);
		return(array($status, $text));
	}

	// Existing scenarios use ok/no-fault/array('') as semantic absence. The
	// single place that turns it into the fault rTorrent actually answers with,
	// so that runCollector() and a hand-written generation response cannot drift
	// apart.
	private function collectorResponse($ok, $fault, $val, $faultString = '')
	{
		if($ok === true && $fault === false && $val === array('') && $faultString === '')
			return(array('ok' => true, 'fault' => true, 'val' => array(),
				'faultString' => 'invalid parameters: info-hash not found'));
		return(array('ok' => $ok, 'fault' => $fault, 'val' => $val, 'faultString' => $faultString));
	}

	private function cleanupGenerationResponses($oldHash, $newHash, $oldPresence, $newGeneration, $newPresence = null)
	{
		return(array(
			$oldHash => array('presence' => $oldPresence),
			$newHash => array(
				'presence' => $newPresence === null
					? $this->collectorResponse(true, false, array($newHash)) : $newPresence,
				'generation' => $newGeneration,
			),
		));
	}

	private function collectorLogs($output)
	{
		$marker = '__ERASEDATA_LOG__';
		$offset = strpos($output, $marker);
		if($offset === false)
			return(array());
		$logs = json_decode(substr($output, $offset + strlen($marker)), true);
		return(is_array($logs) ? $logs : array());
	}

	// -- frozen paths available (an opened download) ------------------------

	public function testFrozenPathsUsedForMultiFile()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("/d/name", 1, "/d/name/a.bin", "/d/name/sub/b.bin"));
		$this->eraseOk();
		$result = erasedataRemoveWithData(array($hash), "1");
		$this->assertEquals(array("/d/name/a.bin", "/d/name/sub/b.bin", "/d/name", "1", "1"), $this->listFor($hash), 'multi-file list from frozen paths');
		$this->assertEquals(array("d.get_base_path", "d.set_custom5"), rXMLRPCRequest::$requested, 'no fallback request when frozen paths exist');
	}

	// -- stored paths available (a download not yet opened) -----------------

	public function testFrozenPathsUsedForSingleFile()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("/d/a.bin", 0, "/d/a.bin"));
		$this->eraseOk();
		$result = erasedataRemoveWithData(array($hash), "1");
		$this->assertEquals(array("/d/a.bin", "/d/a.bin", "0", "1"), $this->listFor($hash), 'single-file list from frozen paths');
	}

	public function testFallsBackToStoredPathsForMultiFile()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("", 1, "", ""));
		$this->stored(true, array("/d/name", 1, "a.bin", "sub/b.bin"));
		$this->eraseOk();
		$result = erasedataRemoveWithData(array($hash), "1");
		$this->assertEquals(array("/d/name/a.bin", "/d/name/sub/b.bin", "/d/name", "1", "1"), $this->listFor($hash), 'multi-file list rebuilt from d.directory + f.path');
		$this->assertEquals(array("d.get_base_path", "d.get_directory", "d.set_custom5"), rXMLRPCRequest::$requested, 'fallback request issued');
	}

	public function testFallsBackToStoredPathsForSingleFile()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("", 0, ""));
		$this->stored(true, array("/d", 0, "movie.mkv"));
		$this->eraseOk();
		$result = erasedataRemoveWithData(array($hash), "1");
		$this->assertEquals(array("/d/movie.mkv", "/d/movie.mkv", "0", "1"), $this->listFor($hash), 'single-file base path is the file, not its directory');
	}

	public function testFallbackNormalisesTrailingSlash()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("", 1, ""));
		$this->stored(true, array("/d/name/", 1, "a.bin"));
		$this->eraseOk();
		$result = erasedataRemoveWithData(array($hash), "1");
		$this->assertEquals(array("/d/name/a.bin", "/d/name", "1", "1"), $this->listFor($hash), 'no doubled separator from a trailing slash');
	}

	public function testFallbackUsedWhenFrozenRequestFails()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(false, array());
		$this->stored(true, array("/d/name", 1, "a.bin"));
		$this->eraseOk();
		$result = erasedataRemoveWithData(array($hash), "1");
		$this->assertEquals(array("/d/name/a.bin", "/d/name", "1", "1"), $this->listFor($hash), 'a failed frozen request also falls back');
	}

	// -- force-delete flag --------------------------------------------------

	public function testForceDeleteFlagRecorded()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("/d/name", 1, "/d/name/a.bin"));
		$this->eraseOk();
		$result = erasedataRemoveWithData(array($hash), "2");
		$lines = $this->listFor($hash);
		$this->assertEquals("2", end($lines), 'delete-path mode recorded as the last line');
	}

	// -- unresolvable download ----------------------------------------------

	public function testTorrentNotErasedWhenNoPathsResolve()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("", 1, "", ""));
		$this->stored(true, array("", 1, "", ""));
		$this->eraseOk();
		$result = erasedataRemoveWithData(array($hash), "1");
		$this->assertTrue($this->listFor($hash) === false, 'no list written when no path resolves');
		$this->assertEquals(array(), rXMLRPCRequest::$erased, 'torrent must not be erased when its files are unknown');
		$this->assertTrue($result === false, 'caller is told the removal did not happen');
		$this->assertTrue(count(FileUtil::$log) > 0, 'the refusal is logged');
	}

	public function testResolvableHashesStillErasedInAMixedBatch()
	{
		$this->reset();
		$hashA = $this->hash('A');
		$hashB = $this->hash('B');
		// The scripted RPC layer answers per command, so both hashes see the
		// same empty frozen reply; only the stored reply resolves.
		$this->frozen(true, array("", 1, ""));
		$this->stored(true, array("/d/name", 1, "a.bin"));
		$this->eraseOk();
		erasedataRemoveWithData(array($hashA, $hashB), "1");
		$this->assertEquals(array($hashA, $hashB), rXMLRPCRequest::$erased, 'every resolvable hash is erased');
	}

	public function testTorrentNotErasedWhenManifestWriteFails()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("/d/name", 1, "/d/name/a.bin"));
		$this->eraseOk();
		@chmod($this->dir.'/erasedata', 0555);
		try {
			$result = erasedataRemoveWithData(array($hash), "1");
			$this->assertTrue($result === false, 'removal must return false when manifest cannot be written');
			$this->assertEquals(array(), rXMLRPCRequest::$erased, 'torrent must not be erased when manifest write fails');
			$this->assertTrue(count(FileUtil::$log) > 0, 'the manifest write failure is logged');
		} finally {
			@chmod($this->dir.'/erasedata', 0777);
		}
	}

	public function testFailedEraseWithPresentHashRetainsStagingForOwnedPathReconciliation()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("/d/name", 1, "/d/name/a.bin"));
		$this->eraseFail();
		rXMLRPCRequest::$responses["d.hash"] = array("ok"=>true, "val"=>array($hash));
		$result = erasedataRemoveWithData(array($hash), "1");
		$this->assertTrue($result === false, 'removal must return false when RPC erase fails');
		$this->assertEquals(array(), $this->manifestFiles($hash), 'list manifest must not exist when torrent still exists in rTorrent');
		$tmpFiles = glob($this->dir.'/erasedata/'.$hash.'.*.tmp');
		$this->assertEquals(1, count($tmpFiles),
			'staging remains until the collector compares it with the live generation owned paths');
		$this->assertTrue(count(FileUtil::$log) > 0, 'the retained obligation is logged');
	}

	public function testManifestPublishedWhenRPCEraseUnconfirmedButTorrentIsGone()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("/d/name", 1, "/d/name/a.bin"));
		$this->eraseFail();
		$this->probe(true, true, array(), 'invalid parameters: info-hash not found');
		$result = erasedataRemoveWithData(array($hash), "1");
		$this->assertTrue($result === false, 'removal returns false on RPC failure');
		$this->assertEquals(1, count($this->manifestFiles($hash)),
			'one list manifest must be published after exact missing-hash confirmation');
		$tmpFiles = glob($this->dir.'/erasedata/'.$hash.'.*.tmp');
		$this->assertTrue(empty($tmpFiles), 'tmp manifest must be moved to list file');
	}

	public function testCleanEmptyProbeRetainsStagingAfterUnconfirmedErase()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("/d/name", 1, "/d/name/a.bin"));
		$this->eraseFail();
		$this->probe(true, false, array(""));
		$result = erasedataRemoveWithData(array($hash), "1");
		$this->assertTrue($result === false, 'removal returns false on RPC failure');
		$this->assertEquals(0, count($this->manifestFiles($hash)),
			'clean empty uncertainty must not publish a deletion manifest');
		$tmpFiles = glob($this->dir.'/erasedata/'.$hash.'.*.tmp');
		$this->assertEquals(1, count($tmpFiles),
			'clean empty uncertainty retains staging for a later conclusive probe');
	}

	public function testFailedProbeNeverPublishesManifest()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("/d/name", 1, "/d/name/a.bin"));
		$this->eraseFail();
		$this->probe(false, false, array());
		erasedataRemoveWithData(array($hash), "1");
		$this->assertTrue($this->listFor($hash) === false, 'transport failure must not be treated as confirmed absence');
		$this->assertEquals(1, count(glob($this->dir.'/erasedata/'.$hash.'.*.tmp')), 'unknown result retains staging for recovery');
	}

	public function testFaultedProbeNeverPublishesManifest()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("/d/name", 1, "/d/name/a.bin"));
		$this->eraseFail();
		$this->probe(false, true, array());
		erasedataRemoveWithData(array($hash), "1");
		$this->assertTrue($this->listFor($hash) === false, 'daemon fault must not be treated as confirmed absence');
		$this->assertEquals(1, count(glob($this->dir.'/erasedata/'.$hash.'.*.tmp')), 'faulted probe retains staging for recovery');
	}

	public function testMalformedProbeRepliesAreUnknown()
	{
		$hash = $this->hash();
		$cases = array(array(), array("", ""), array(1), array(null), array($this->hash('B')));
		foreach($cases as $val)
		{
			$this->probe(true, false, $val);
			$presence = function_exists('erasedataTorrentPresence') ? erasedataTorrentPresence($hash) : null;
			$this->assertEquals(-1, $presence, 'malformed, multi-value, and non-string replies are unknown');
		}
	}

	public function testFailedRenameRetainsStagingWithoutPublishingPartialList()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("/d/name", 1, "/d/name/a.bin"));
		$this->eraseOk(function () use ($hash) {
			$tmp = glob($this->dir.'/erasedata/'.$hash.'.*.tmp');
			if(count($tmp) === 1)
				@mkdir(substr($tmp[0], 0, -4).'.list');
			@mkdir($this->dir.'/erasedata/'.$hash.'.list');
		});
		$result = erasedataRemoveWithData(array($hash), "1");
		$this->assertTrue($result === false, 'publication failure is reported to the caller');
		$this->assertEquals(array(), $this->manifestFiles($hash), 'failed rename does not publish a partial list file');
		$this->assertEquals(1, count(glob($this->dir.'/erasedata/'.$hash.'.*.tmp')), 'failed rename retains complete staging for recovery');
	}

	public function testRejectsInvalidTraversalHashBeforeFilesystemOrRPCWork()
	{
		$this->reset();
		@rmdir($this->dir.'/erasedata');
		$result = erasedataRemoveWithData(array($this->hash(), '../outside'), "1");
		$this->assertTrue($result === false, 'the shared producer rejects a non-SHA-1 hash');
		$this->assertTrue(!file_exists($this->dir.'/erasedata'), 'invalid input cannot create the manifest directory');
		$this->assertTrue(!file_exists($this->dir.'/OUTSIDE.lock'), 'path traversal cannot create an artifact outside the manifest directory');
		$this->assertEquals(array(), rXMLRPCRequest::$requested, 'the complete batch is validated before any path lookup or erase RPC');
		$this->assertEquals(array(), rXMLRPCRequest::$erased, 'invalid input cannot reach d.erase');
	}

	public function testAcceptedLowercaseHashIsCanonicalEverywhere()
	{
		$this->reset();
		$lower = $this->hash('a');
		$upper = strtoupper($lower);
		$this->frozen(true, array("/d/name", 1, "/d/name/a.bin"));
		$this->eraseOk();
		erasedataRemoveWithData(array($lower), "1");
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$upper.'.lock'), 'the lock uses the canonical uppercase hash');
		$this->assertEquals(1, count($this->manifestFiles($upper)), 'the live manifest uses the canonical uppercase hash');
		$this->assertEquals(array($upper), rXMLRPCRequest::$erased, 'RPC commands receive the canonical uppercase hash');
	}

	public function testPublishedManifestAndHashLockUseAndRepairProfileMode()
	{
		global $profileMask;
		$this->reset();
		$profileMask = 0671;
		$hash = $this->hash();
		$lock = $this->dir.'/erasedata/'.$hash.'.lock';
		file_put_contents($lock, '');
		chmod($lock, 0600);
		$this->frozen(true, array("/d/name", 1, "/d/name/a.bin"));
		$this->eraseOk();
		erasedataRemoveWithData(array($hash), "1");
		$this->assertEquals(0660, $this->modeOf($lock), 'an existing persistent hash lock is repaired to the shared profile mode');
		$this->assertEquals(0660, $this->modeOf($this->onlyManifest($hash)), 'the published manifest has the shared profile mode');
	}

	public function testRetainedCompleteStagingUsesProfileMode()
	{
		global $profileMask;
		$this->reset();
		$profileMask = 0671;
		$hash = $this->hash();
		$this->frozen(true, array("/d/name", 1, "/d/name/a.bin"));
		$this->eraseFail();
		$this->probe(false, false, array());
		erasedataRemoveWithData(array($hash), "1");
		$tmp = glob($this->dir.'/erasedata/'.$hash.'.*.tmp');
		$this->assertEquals(1, count($tmp), 'the unknown erase result retains one complete staging manifest');
		$this->assertEquals(0660, $this->modeOf($tmp[0]), 'completed staging has the shared profile mode');
		$reader = @fopen($tmp[0], 'r');
		$this->assertTrue($reader !== false, 'the retained manifest can be opened for a later reader');
		if($reader !== false)
			fclose($reader);
	}

	public function testMissingProfileMaskUsesCompatibleFallbackMode()
	{
		global $profileMask;
		$this->reset();
		unset($profileMask);
		$hash = $this->hash();
		$this->frozen(true, array("/d/name", 1, "/d/name/a.bin"));
		$this->eraseOk();
		try {
			erasedataRemoveWithData(array($hash), "1");
			$this->assertEquals(0666, $this->modeOf($this->dir.'/erasedata/'.$hash.'.lock'), 'the lock fallback also strips execute bits');
			$this->assertEquals(0666, $this->modeOf($this->onlyManifest($hash)), 'focused harnesses without profileMask retain the historical permissive fallback');
		} finally {
			$profileMask = 0777;
		}
	}

	public function testCollectorRepairsPersistentLockModes()
	{
		global $profileMask;
		$this->reset();
		$profileMask = 0671;
		$hash = $this->hash('B');
		$data = $this->dir.'/mode-data.bin';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		$hashLock = $this->dir.'/erasedata/'.$hash.'.lock';
		$schedulerLock = $this->dir.'/erasedata/scheduler.lock';
		file_put_contents($data, 'mode-data');
		$this->writeManifest($hash.'.list', $data);
		file_put_contents($hashLock, '');
		file_put_contents($schedulerLock, '');
		chmod($list, 0600);
		chmod($hashLock, 0600);
		chmod($schedulerLock, 0600);
		list($status, $output) = $this->runCollector(array('ok' => false, 'val' => array()));
		$this->assertEquals(0, $status, 'collector exits normally while repairing shared modes: '.$output);
		$this->assertEquals(0660, $this->modeOf($schedulerLock), 'the persistent scheduler lock is repaired to the shared profile mode');
		$this->assertEquals(0660, $this->modeOf($hashLock), 'the persistent hash lock is repaired to the shared profile mode');
		$this->assertEquals(0600, $this->modeOf($list), 'an unknown manifest is otherwise left untouched');
		$this->assertTrue(is_file($data), 'mode repair cannot authorize deletion while presence is unknown');
	}

	public function testCollectorRetainsExactManifestUntilFailedDeletionSucceeds()
	{
		$this->reset();
		$hash = $this->hash('2');
		$target = $this->dir.'/fail-first-target';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		mkdir($target);
		$this->writeManifest($hash.'.list', $target);
		$exact = file_get_contents($list);

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'failed first deletion pass exits normally: '.$output);
		$this->assertTrue(is_dir($target), 'a failed unlink leaves the required target in place');
		$this->assertTrue(is_file($list), 'the deletion obligation survives the failed pass');
		$this->assertEquals($exact, is_file($list) ? file_get_contents($list) : null,
			'the exact manifest is retained for retry');

		rmdir($target);
		file_put_contents($target, 'retry');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'successful retry exits normally: '.$output);
		$this->assertTrue(!file_exists($target), 'the next collector pass retries and deletes the target');
		$this->assertTrue(!file_exists($list), 'the manifest is consumed only after required deletion completes');
	}

	// collectHash() is the plan's single-hash entry point, and it had no caller
	// anywhere -- production or tests -- so nothing pinned it and a regression in
	// it would have been invisible. It must collect exactly the hash it is
	// handed, building that hash's own index, and leave every other queued hash
	// and its data untouched.
	public function testCollectHashCollectsOnlyTheHashItIsHanded()
	{
		$this->reset();
		$target = $this->hash('A');
		$other = $this->hash('B');
		$targetData = $this->dir.'/collect-hash-target.bin';
		$otherData = $this->dir.'/collect-hash-other.bin';
		file_put_contents($targetData, 'target');
		file_put_contents($otherData, 'other');
		$this->writeManifest($target.'.list', $targetData);
		$this->writeManifest($other.'.list', $otherData);
		// Both hashes are confirmed gone from rTorrent, so both are collectable;
		// only the argument may decide which one actually is.
		$this->probe(true, true, array(), 'invalid parameters: info-hash not found');

		$service = erasedataCollectorService(new ErasedataFilesystemOps());
		$service->collectHash($this->dir.'/erasedata', $target);

		clearstatcache();
		$this->assertTrue(!file_exists($this->dir.'/erasedata/'.$target.'.list'),
			'collectHash consumes the manifest of the hash it was handed');
		$this->assertTrue(!file_exists($targetData),
			'collectHash deletes the data of the hash it was handed');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$other.'.list'),
			'collectHash leaves every other queued manifest alone');
		$this->assertEquals('other', is_file($otherData) ? file_get_contents($otherData) : null,
			'collectHash deletes no byte belonging to another queued hash');
	}

	public function testCollectorTreatsMissingTargetAsComplete()
	{
		$this->reset();
		$hash = $this->hash('3');
		$missing = $this->dir.'/already-missing.bin';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		$this->writeManifest($hash.'.list', $missing);

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'missing-target collection exits normally: '.$output);
		$this->assertTrue(!file_exists($list), 'an already-missing required target completes its obligation');
	}

	public function testCollectorAllowsNonForcedNonEmptyBaseAfterListedFilesAreGone()
	{
		$this->reset();
		$hash = $this->hash('4');
		$base = $this->dir.'/shared-base';
		$listed = $base.'/listed.bin';
		$unrelated = $base.'/unrelated.bin';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		mkdir($base);
		file_put_contents($listed, 'listed');
		file_put_contents($unrelated, 'unrelated');
		$this->writeManifestLines($hash.'.list', array($listed), $base, 1, 1);

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'non-force collection exits normally: '.$output);
		$this->assertTrue(!file_exists($listed), 'every listed file is removed');
		$this->assertTrue(is_file($unrelated), 'unlisted data keeps the non-empty base directory alive');
		$this->assertTrue(!file_exists($list), 'a non-force rmdir failure is complete once listed files are gone');
	}

	public function testCollectorRetriesAnEmptyBaseAfterTransientRmdirFailure()
	{
		$this->reset();
		$hash = $this->hash('9');
		$base = $this->dir.'/empty-retry-base';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		mkdir($base);
		$this->writeManifestLines($hash.'.list', array($base.'/already-gone.bin'), $base, 1, 1);
		$exact = file_get_contents($list);

		list($status, $output) = $this->runCollector(array('rmdirFail' => $base));
		$this->assertEquals(0, $status, 'fail-first collector exits normally: '.$output);
		$this->assertTrue(is_dir($base), 'the injected empty-directory failure leaves the base in place');
		$this->assertTrue(is_file($list), 'the exact retry obligation survives the transient rmdir failure');
		$this->assertEquals($exact, is_file($list) ? file_get_contents($list) : null,
			'the retained manifest bytes are unchanged');

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'succeed-next collector exits normally: '.$output);
		$this->assertTrue(!file_exists($base), 'the next pass retries and removes the empty base');
		$this->assertTrue(!file_exists($list), 'the manifest is consumed only after successful retry');
	}

	public function testCollectorRetriesAnEmptyNestedDirectoryAfterTransientRmdirFailure()
	{
		$this->reset();
		$hash = $this->hash('0');
		$base = $this->dir.'/nested-retry-base';
		$nested = $base.'/one/two';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		mkdir($nested, 0777, true);
		$this->writeManifestLines($hash.'.list', array($nested.'/already-gone.bin'), $base, 1, 1);

		list($status, $output) = $this->runCollector(array('rmdirFail' => $nested));
		$this->assertEquals(0, $status, 'nested fail-first collector exits normally: '.$output);
		$this->assertTrue(is_dir($nested), 'the injected nested-directory failure leaves it in place');
		$this->assertTrue(is_file($list), 'the nested retry obligation survives');

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'nested succeed-next collector exits normally: '.$output);
		$this->assertTrue(!file_exists($base), 'retry removes nested directories and their base');
		$this->assertTrue(!file_exists($list), 'nested manifest is consumed after retry');
	}

	public function testCollectorRejectsDirectoryIdentitySwapBeforeRmdir()
	{
		$this->reset();
		$hash = $this->hash('2');
		$base = $this->dir.'/identity-swap-base';
		$moved = $base.'.checked';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		mkdir($base);
		$this->writeManifestLines($hash.'.list', array($base.'/already-gone.bin'), $base, 1, 1);
		$exact = file_get_contents($list);

		list($status, $output) = $this->runCollector(array('rmdirSwap' => $base));
		$this->assertEquals(0, $status, 'identity-swap collector exits normally: '.$output);
		$this->assertTrue(is_dir($base), 'a replacement directory is not removed after the identity swap');
		$this->assertTrue(is_dir($moved), 'the originally checked directory remains outside the swapped name');
		$this->assertTrue(is_file($list), 'the exact obligation survives a directory identity change');
		$this->assertEquals($exact, is_file($list) ? file_get_contents($list) : null,
			'the identity-swap retry manifest bytes are unchanged');

		if(is_link($base))
			unlink($base);
		else if(is_dir($base))
			rmdir($base);
		foreach(glob($this->dir.'/.erasedata-rmdir-*') as $reserved)
			$this->removePath($reserved);
		if(is_dir($moved))
			rename($moved, $base);
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'identity-swap retry exits normally: '.$output);
		$this->assertTrue(!file_exists($base), 'retry removes the restored original directory');
		$this->assertTrue(!file_exists($list), 'retry consumes the obligation only after the original directory is removed');
	}

	public function testCollectorRecoversDataBearingReservationAfterWorkerExit()
	{
		$this->reset();
		$hash = $this->hash('4');
		$base = $this->dir.'/crash-reservation-base';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		mkdir($base);
		$this->writeManifestLines($hash.'.list', array($base.'/already-gone.bin'), $base, 1, 1);
		$exact = file_get_contents($list);

		list($status, $output) = $this->runCollector(array('rmdirCrash' => $base));
		$this->assertEquals(0, $status, 'reservation-crash worker exits at the deterministic seam: '.$output);
		$reservations = glob($this->dir.'/.erasedata-rmdir-*');
		$this->assertTrue(!file_exists($base), 'the interrupted worker exits after moving the checked directory');
		$this->assertEquals(1, is_array($reservations) ? count($reservations) : 0,
			'exactly one recoverable reservation remains');
		$reserved = is_array($reservations) && count($reservations) ? $reservations[0] : '';
		$reservedData = $this->reservationDataPath($reserved);
		$this->assertEquals('reserved-bytes', is_file($reservedData.'/crash-data.bin')
			? file_get_contents($reservedData.'/crash-data.bin') : null,
			'the interrupted reservation retains its data bytes');
		$this->assertEquals($exact, is_file($list) ? file_get_contents($list) : null,
			'the exact manifest survives the interrupted pass');

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'reservation recovery pass exits normally: '.$output);
		$this->assertEquals('reserved-bytes', is_file($base.'/crash-data.bin')
			? file_get_contents($base.'/crash-data.bin') : null,
			'unrelated data is restored to the original visible path');
		$recovered = glob($this->dir.'/.erasedata-rmdir-*');
		$this->assertEquals(1, is_array($recovered) ? count($recovered) : 0,
			'the recovered data keeps one durable backing directory');
		$recoveredPath = is_array($recovered) && count($recovered) === 1
			? $this->reservationDataPath($recovered[0]) : '';
		$this->assertTrue(is_link($base) && @readlink($base) === $recoveredPath,
			'the original visible path points to the exact recovered backing directory');
		$this->assertTrue(!file_exists($list),
			'the manifest completes only after the data-bearing reservation is restored');
	}

	public function testCollectorRecoversReservationInitializationCrash()
	{
		$this->assertCollectorRecoversReservationInitializationCrash('created', '1');
	}

	public function testCollectorRecoversReservationMarkerCrash()
	{
		$this->assertCollectorRecoversReservationInitializationCrash('initialized', '5');
	}

	private function assertCollectorRecoversReservationInitializationCrash($phase, $character)
	{
		$this->reset();
		$hash = $this->hash($character);
		$base = $this->dir.'/reservation-'.$phase.'-crash-base';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		mkdir($base);
		$this->writeManifestLines($hash.'.list', array($base.'/already-gone.bin'), $base, 1, 1);

		list($status, $output) = $this->runCollector(array('reservationInitCrash' => $phase));
		$this->assertEquals(0, $status, $phase.' reservation crash exits at the deterministic seam: '.$output);
		$this->assertTrue(is_dir($base), $phase.' crash happens before the checked directory is renamed');
		$this->assertEquals(1, count(glob($this->dir.'/.erasedata-rmdir-*')),
			$phase.' crash leaves one discoverable private reservation');
		$this->assertTrue(is_file($list), $phase.' crash retains the exact manifest');

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, $phase.' reservation retry exits normally: '.$output);
		$this->assertTrue(!file_exists($base), 'retry removes the original empty directory');
		$this->assertEquals(array(), glob($this->dir.'/.erasedata-rmdir-*'),
			'retry removes the abandoned initialization root');
		$this->assertTrue(!file_exists($list), 'retry completes the retained manifest');
	}

	// A crash inside erasedataCreateCapturedEntryRoot(), in the window between
	// makeDirectory() and the .name/.initialized writes, leaves ONE empty
	// directory behind in the QUEUE directory. It used to make
	// erasedataResumeCapturedEntries() refuse, and run() returns on that refusal
	// before it reads a single hash: for EVERY torrent, payload undeleted and
	// manifest retained, for ever, and not one log line at any
	// $erasedebug_enabled setting. rmdir() is the whole heal -- it is atomic and
	// it refuses a directory that holds anything -- so the residue the collector
	// can prove empty is swept and the queue behind it runs.
	public function testCollectorSweepsEmptyCrashResidueInsteadOfStoppingEveryHash()
	{
		$this->reset();
		$hash = $this->hash('6');
		$base = $this->dir.'/residue-empty-base';
		$payload = $base.'/payload.bin';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		mkdir($base);
		file_put_contents($payload, 'payload');
		$this->writeManifestLines($hash.'.list', array($payload), $base, 1, 1);
		$residue = erasedataCapturedEntryPrefix($list, 'manifest-consumption')
			.'1-2-f-'.str_repeat('a', 32);
		mkdir($residue, 0700);

		list($status, $output) = $this->runCollector(array('captureLogs' => true));
		$this->assertEquals(0, $status, 'an empty crash-residue root must not crash the collector: '.$output);
		$this->assertTrue(!file_exists($residue), 'the empty crash residue is swept');
		$this->assertTrue(!file_exists($payload), 'and every hash behind it runs');
		$this->assertTrue(!file_exists($list), 'the manifest it blocked is retired');
		$this->assertEquals(array(), $this->collectorLogs($output),
			'a residue the collector can prove empty needs no operator');
	}

	// The sweep above at its own level, on the three shapes the collector can
	// meet and on the one it names when it cannot even look. rmdir() refusing a
	// non-empty directory is not the guard -- removeDirectory() is a seam, and
	// the guard has to hold against a seam that would take the directory
	// anyway. This also pins the $blocker contract: a refusal names the one
	// leftover the caller could not get past, which is the only reason run()
	// has something to print.
	public function testResumeSweepsOnlyProvablyEmptyRootsAndNamesTheRest()
	{
		$this->reset();
		$queue = $this->dir.'/erasedata';
		$greedy = new ErasedataGreedyRemovalFilesystem();

		$empty = $queue.'/.erasedata-entry-'.str_repeat('c', 64).'-1-2-f-'.str_repeat('c', 32);
		mkdir($empty, 0700);
		$blocker = null;
		$this->assertTrue(erasedataResumeCapturedEntries(
			$queue, 'manifest-consumption', $greedy, $blocker),
			'the crash-window residue is provably empty, so it is swept');
		$this->assertTrue(!file_exists($empty), 'and the directory is gone');
		$this->assertEquals(null, $blocker, 'a swept root blocks nobody and names nothing');

		$occupied = $queue.'/.erasedata-entry-'.str_repeat('d', 64).'-1-2-f-'.str_repeat('d', 32);
		mkdir($occupied, 0700);
		file_put_contents($occupied.'/entry', 'bytes nobody may guess at');
		$blocker = null;
		$this->assertTrue(!erasedataResumeCapturedEntries(
			$queue, 'manifest-consumption', $greedy, $blocker),
			'a root that still holds bytes is refused by a seam that would remove it');
		$this->assertEquals('bytes nobody may guess at', is_file($occupied.'/entry')
			? file_get_contents($occupied.'/entry') : null, 'and its bytes are untouched');
		$this->assertEquals($occupied, $blocker, 'the refusal names exactly what to remove');
		$this->removePath($occupied);

		// TWO roots in one parent, the healable one sorting FIRST. Every case
		// above has a single root, so the sweep's `continue` could be a
		// `return(true)` and nothing would notice -- and that mutant is worse
		// than useless: it heals the first root and then silently skips the
		// shell behind it, suppressing the very diagnostic this sweep exists
		// to emit. 'a' sorts before 'b', so the order is the one that matters.
		$firstEmpty = $queue.'/.erasedata-entry-'.str_repeat('a', 64).'-1-2-f-'.str_repeat('a', 32);
		$secondHeld = $queue.'/.erasedata-entry-'.str_repeat('b', 64).'-1-2-f-'.str_repeat('b', 32);
		mkdir($firstEmpty, 0700);
		mkdir($secondHeld, 0700);
		file_put_contents($secondHeld.'/entry', 'the shell behind the healed one');
		$blocker = null;
		$this->assertTrue(!erasedataResumeCapturedEntries(
			$queue, 'manifest-consumption', $greedy, $blocker),
			'healing one root does not end the sweep: the one behind it is still refused');
		$this->assertTrue(!file_exists($firstEmpty), 'the empty root ahead of it is still swept');
		$this->assertEquals($secondHeld, $blocker,
			'and the refusal names the root behind it, which a sweep that stopped early would never see');
		$this->assertEquals('the shell behind the healed one', is_file($secondHeld.'/entry')
			? file_get_contents($secondHeld.'/entry') : null, 'with its bytes untouched');
		$this->removePath($secondHeld);

		// A root that reads back a name but whose entry cannot be resumed: the
		// suffix is not the dev-ino-type-token shape, so it is invisible to
		// erasedataCapturedEntryRoots() and the visible manifest is still there.
		$hash = $this->hash('8');
		$list = $queue.'/'.$hash.'.list';
		$this->writeManifestLines($hash.'.list', array($this->dir.'/unresumable.bin'),
			$this->dir.'/unresumable.bin', 0, 1);
		$unresumable = erasedataCapturedEntryPrefix($list, 'manifest-consumption').'not-the-shape';
		mkdir($unresumable, 0700);
		file_put_contents($unresumable.'/.name', base64_encode($hash.'.list'));
		$this->assertTrue(erasedataCreatePrivateMarker($unresumable),
			'the unresumable root is a well-formed private container');
		$blocker = null;
		$this->assertTrue(!erasedataResumeCapturedEntries(
			$queue, 'manifest-consumption', $greedy, $blocker),
			'a named root that cannot be resumed is refused');
		$this->assertEquals($unresumable, $blocker,
			'and that refusal names the root too, not the manifest behind it');
		$this->removePath($unresumable);

		$blocker = null;
		$missing = $this->dir.'/queue-that-is-not-there';
		$this->assertTrue(!erasedataResumeCapturedEntries(
			$missing, 'manifest-consumption', $greedy, $blocker),
			'an unreadable queue directory is a refusal of the whole pass');
		$this->assertEquals($missing, $blocker,
			'and it names the directory it could not read');
	}

	// The same refusal on a leftover the collector CANNOT prove empty: it still
	// stops every hash, deliberately, because sweeping a directory that still
	// holds bytes would be guessing. What changed is that it now says so, once,
	// through the unconditional channel the shipped $erasedebug_enabled = false
	// cannot silence, and it names the exact directory to remove.
	public function testCollectorNamesTheCrashResidueThatStopsEveryHash()
	{
		$this->reset();
		$hash = $this->hash('7');
		$base = $this->dir.'/residue-blocked-base';
		$payload = $base.'/payload.bin';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		mkdir($base);
		file_put_contents($payload, 'payload');
		$this->writeManifestLines($hash.'.list', array($payload), $base, 1, 1);
		$residue = erasedataCapturedEntryPrefix($list, 'manifest-consumption')
			.'1-2-f-'.str_repeat('b', 32);
		mkdir($residue, 0700);
		file_put_contents($residue.'/entry', 'bytes the collector may not guess at');

		list($status, $output) = $this->runCollector(array('captureLogs' => true));
		$this->assertEquals(0, $status, 'an unreadable entry root must not crash the collector: '.$output);
		$this->assertTrue(is_file($payload) && is_file($list),
			'one unreadable entry root really does stop every job in the queue');
		$logs = $this->collectorLogs($output);
		$named = array();
		foreach($logs as $line)
			if(strpos($line, $residue) !== false)
				$named[] = $line;
		$this->assertEquals(1, count($named),
			'the run that did nothing names the directory to remove, once: '.json_encode($logs));
		$this->assertTrue(count($named) === 1
			&& strpos($named[0], 'no manifest was collected for any torrent') !== false,
			'and says the whole run was refused, not merely that one path was skipped: '
			.json_encode($named));
	}

	public function testCollectorRetriesAfterTransientReservationContainerFailure()
	{
		$this->reset();
		$hash = $this->hash('2');
		$base = $this->dir.'/reservation-container-retry-base';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		mkdir($base);
		$this->writeManifestLines($hash.'.list', array($base.'/already-gone.bin'), $base, 1, 1);

		list($status, $output) = $this->runCollector(array('containerRemovalFail' => true));
		$this->assertEquals(0, $status, 'transient container failure exits normally: '.$output);
		$this->assertTrue(!file_exists($base), 'the checked empty directory was already removed');
		$this->assertEquals(1, count(glob($this->dir.'/.erasedata-rmdir-*')),
			'the empty private container remains discoverable for retry');
		$this->assertTrue(is_file($list), 'container cleanup failure retains the exact manifest');

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'transient container retry exits normally: '.$output);
		$this->assertEquals(array(), glob($this->dir.'/.erasedata-rmdir-*'),
			'retry removes the empty private container');
		$this->assertTrue(!file_exists($list), 'retry completes the retained manifest');
	}

	public function testRecoveryDoesNotReplaceConcurrentEmptyDirectory()
	{
		$this->reset();
		$hash = $this->hash('6');
		$base = $this->dir.'/recovery-collision-base';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		mkdir($base);
		$this->writeManifestLines($hash.'.list', array($base.'/already-gone.bin'), $base, 1, 1);
		$exact = file_get_contents($list);

		list($status, $output) = $this->runCollector(array('rmdirCrash' => $base));
		$this->assertEquals(0, $status, 'collision setup exits after reserving the checked directory: '.$output);
		$reservations = glob($this->dir.'/.erasedata-rmdir-*');
		$this->assertEquals(1, is_array($reservations) ? count($reservations) : 0,
			'collision setup leaves one data-bearing reservation');
		$reserved = is_array($reservations) && count($reservations) ? $reservations[0] : '';
		$reservedData = $this->reservationDataPath($reserved);

		list($status, $output) = $this->runCollector(array('restoreCollision' => $base));
		$this->assertEquals(0, $status, 'colliding recovery pass exits normally: '.$output);
		$collisionInode = is_file($base.'.collision-inode')
			? trim(file_get_contents($base.'.collision-inode')) : '';
		$current = @lstat($base);
		$this->assertTrue(is_array($current) && (string)$current['ino'] === $collisionInode,
			'recovery never replaces the concurrently created empty directory');
		$this->assertEquals('reserved-bytes', is_file($reservedData.'/crash-data.bin')
			? file_get_contents($reservedData.'/crash-data.bin') : null,
			'the colliding recovery retains every reserved data byte');
		$this->assertEquals($exact, is_file($list) ? file_get_contents($list) : null,
			'the colliding recovery retains the exact manifest for a later pass');
	}

	public function testPostReservationIdentityMismatchDoesNotReplaceCollision()
	{
		$this->reset();
		$hash = $this->hash('7');
		$base = $this->dir.'/reserved-identity-collision-base';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		mkdir($base);
		$this->writeManifestLines($hash.'.list', array($base.'/already-gone.bin'), $base, 1, 1);
		$exact = file_get_contents($list);

		list($status, $output) = $this->runCollector(array('restoreCollision' => $base, 'reservedSwap' => $base));
		$this->assertEquals(0, $status, 'post-reservation identity collision exits normally: '.$output);
		$collisionInode = is_file($base.'.collision-inode')
			? trim(file_get_contents($base.'.collision-inode')) : '';
		$current = @lstat($base);
		$this->assertTrue(is_array($current) && (string)$current['ino'] === $collisionInode,
			'an untrusted reserved inode never replaces the concurrent directory');
		$reservations = array_filter(glob($this->dir.'/.erasedata-rmdir-*'), function($path) {
			return((bool)preg_match('/-[0-9]+-[0-9]+-[a-f0-9]{32}$/D', $path));
		});
		$this->assertEquals(1, count($reservations),
			'the replacement reservation remains isolated after identity mismatch');
		$reservation = count($reservations) === 1 ? array_values($reservations)[0] : '';
		$this->assertTrue($reservation !== '' && is_dir($reservation.'/directory.checked'),
			'the originally reserved inode remains isolated after identity mismatch');
		$this->assertEquals($exact, is_file($list) ? file_get_contents($list) : null,
			'the identity-mismatch collision retains the exact manifest');
	}

	public function testSecondGenerationForceRemovalDeletesRecoveredBackingData()
	{
		$this->reset();
		$firstHash = $this->hash('8');
		$secondHash = $this->hash('9');
		$base = $this->dir.'/force-recovered-backing-base';
		$firstList = $this->dir.'/erasedata/'.$firstHash.'.list';
		$secondList = $this->dir.'/erasedata/'.$secondHash.'.list';
		mkdir($base);
		$this->writeManifestLines(
			$firstHash.'.list', array($base.'/already-gone.bin'), $base, 1, 1);

		list($status, $output) = $this->runCollector(array('rmdirCrash' => $base));
		$this->assertEquals(0, $status, 'force-recovery setup exits at the reservation seam: '.$output);
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'force-recovery publication exits normally: '.$output);
		$recovery = glob($this->dir.'/.erasedata-rmdir-*');
		$target = is_link($base) ? @readlink($base) : '';
		$this->assertTrue(is_link($base) && @readlink($base) === $target,
			'the first generation exposes its recovered backing at the original path');
		$this->assertTrue(!file_exists($firstList),
			'the first generation completes after visible recovery');

		$this->writeManifestLines(
			$secondHash.'.list', array($base.'/crash-data.bin'), $base, 1, 2);
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'second-generation force collection exits normally: '.$output);
		$this->assertTrue(!file_exists($base) && !is_link($base),
			'force removal deletes the internal recovery link');
		$this->assertTrue($target !== '' && !file_exists($target),
			'force removal deletes every recovered backing byte');
		$this->assertTrue(!file_exists($secondList),
			'the force manifest completes only after its recovery backing is removed');
	}

	public function testForceRecoveryRejectsBackingIdentitySwapBeforeTraversal()
	{
		$this->reset();
		$firstHash = $this->hash('A');
		$secondHash = $this->hash('B');
		$base = $this->dir.'/force-recovery-swap-base';
		$secondList = $this->dir.'/erasedata/'.$secondHash.'.list';
		mkdir($base);
		$this->writeManifestLines(
			$firstHash.'.list', array($base.'/already-gone.bin'), $base, 1, 1);
		$this->runCollector(array('rmdirCrash' => $base));
		$this->runCollector(array());
		$recovery = glob($this->dir.'/.erasedata-rmdir-*');
		$target = is_link($base) ? @readlink($base) : '';
		$this->writeManifestLines(
			$secondHash.'.list', array($base.'/crash-data.bin'), $base, 1, 2);
		$exact = file_get_contents($secondList);

		list($status, $output) = $this->runCollector(array('forceTargetSwap' => $base));
		$this->assertEquals(0, $status, 'force backing-swap pass exits normally: '.$output);
		$this->assertEquals('replacement', is_file($target.'/replacement.bin')
			? file_get_contents($target.'/replacement.bin') : null,
			'force traversal never deletes a replacement backing directory');
		$this->assertEquals('reserved-bytes', is_file($target.'.checked/crash-data.bin')
			? file_get_contents($target.'.checked/crash-data.bin') : null,
			'force traversal never deletes the originally validated bytes after a swap');
		$this->assertEquals($exact, is_file($secondList) ? file_get_contents($secondList) : null,
			'backing identity uncertainty retains the exact force manifest');
	}

	public function testForceRecoveryRetainsPostDeleteRecreation()
	{
		$this->reset();
		$firstHash = $this->hash('C');
		$secondHash = $this->hash('D');
		$base = $this->dir.'/force-recovery-recreate-base';
		$secondList = $this->dir.'/erasedata/'.$secondHash.'.list';
		mkdir($base);
		$this->writeManifestLines(
			$firstHash.'.list', array($base.'/already-gone.bin'), $base, 1, 1);
		$this->runCollector(array('rmdirCrash' => $base));
		$this->runCollector(array());
		$this->writeManifestLines(
			$secondHash.'.list', array($base.'/crash-data.bin'), $base, 1, 2);
		$exact = file_get_contents($secondList);

		list($status, $output) = $this->runCollector(array('forceTargetRecreate' => $base));
		$this->assertEquals(0, $status, 'force backing-recreation pass exits normally: '.$output);
		$this->assertEquals('recreated', is_file($base.'/recreated.bin')
			? file_get_contents($base.'/recreated.bin') : null,
			'post-delete recreation remains visible at the original path');
		$this->assertEquals($exact, is_file($secondList) ? file_get_contents($secondList) : null,
			'post-delete recreation retains the exact force manifest');
	}

	public function testForceRecoveryTraversalStaysBoundAfterValidation()
	{
		$this->reset();
		$firstHash = $this->hash('E');
		$secondHash = $this->hash('F');
		$base = $this->dir.'/force-recovery-traversal-base';
		$secondList = $this->dir.'/erasedata/'.$secondHash.'.list';
		mkdir($base);
		$this->writeManifestLines(
			$firstHash.'.list', array($base.'/already-gone.bin'), $base, 1, 1);
		$this->runCollector(array('rmdirCrash' => $base));
		$this->runCollector(array());
		$this->writeManifestLines(
			$secondHash.'.list', array($base.'/crash-data.bin'), $base, 1, 2);
		$exact = file_get_contents($secondList);

		list($status, $output) = $this->runCollector(array('forceTraverseSwap' => $base));
		$this->assertEquals(0, $status, 'post-validation traversal swap exits normally: '.$output);
		$this->assertEquals('replacement', is_file($base.'/replacement.bin')
			? file_get_contents($base.'/replacement.bin') : null,
			'identity-bound traversal never deletes the post-validation replacement');
		$this->assertEquals($exact, is_file($secondList) ? file_get_contents($secondList) : null,
			'post-validation replacement retains the exact force manifest');
	}

	public function testForceRecoveryRetriesAfterTombstoneCleanupCrash()
	{
		$this->assertForceRecoveryCleanupCrashRetries('tombstone', '1');
	}

	public function testForceRecoveryRetriesAfterBridgeCleanupCrash()
	{
		$this->assertForceRecoveryCleanupCrashRetries('bridge', '2');
	}

	public function testForceRecoveryRetriesAfterContainerCleanupCrash()
	{
		$this->assertForceRecoveryCleanupCrashRetries('container', '0');
	}

	private function assertForceRecoveryCleanupCrashRetries($phase, $character)
	{
		$this->reset();
		$firstHash = $this->hash($character);
		$secondHash = $this->hash($character === '1' ? '3'
			: ($character === '2' ? '4' : '9'));
		$base = $this->dir.'/force-cleanup-'.$phase.'-base';
		$secondList = $this->dir.'/erasedata/'.$secondHash.'.list';
		mkdir($base);
		$this->writeManifestLines(
			$firstHash.'.list', array($base.'/already-gone.bin'), $base, 1, 1);
		$this->runCollector(array('rmdirCrash' => $base));
		$this->runCollector(array());
		$this->writeManifestLines(
			$secondHash.'.list', array($base.'/crash-data.bin'), $base, 1, 2);
		$exact = file_get_contents($secondList);

		list($status, $output) = $this->runCollector(array('cleanupCrash' => $phase));
		$this->assertEquals(0, $status, $phase.' cleanup worker exits at the deterministic seam: '.$output);
		$this->assertTrue(is_link($base),
			$phase.' cleanup crash keeps the visible recovery link discoverable');
		$this->assertEquals($exact, is_file($secondList) ? file_get_contents($secondList) : null,
			$phase.' cleanup crash retains the exact force manifest');

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, $phase.' cleanup retry exits normally: '.$output);
		$this->assertTrue(!file_exists($base) && !is_link($base),
			$phase.' cleanup retry removes the visible recovery link last');
		$this->assertEquals(array(), glob($this->dir.'/.erasedata-rmdir-*'),
			$phase.' cleanup retry removes every hidden recovery artifact');
		$this->assertTrue(!file_exists($secondList),
			$phase.' cleanup retry completes the force manifest');
	}

	public function testForceRecoveryCaptureNeverOverwritesCollisionDirectory()
	{
		$this->reset();
		$firstHash = $this->hash('5');
		$secondHash = $this->hash('6');
		$base = $this->dir.'/force-capture-collision-base';
		$secondList = $this->dir.'/erasedata/'.$secondHash.'.list';
		mkdir($base);
		$this->writeManifestLines(
			$firstHash.'.list', array($base.'/already-gone.bin'), $base, 1, 1);
		$this->runCollector(array('rmdirCrash' => $base));
		$this->runCollector(array());
		$recovery = glob($this->dir.'/.erasedata-rmdir-*');
		$target = is_link($base) ? @readlink($base) : '';
		$this->writeManifestLines(
			$secondHash.'.list', array($base.'/crash-data.bin'), $base, 1, 2);
		$exact = file_get_contents($secondList);

		list($status, $output) = $this->runCollector(array('forceCaptureCollision' => $base));
		$this->assertEquals(0, $status, 'force capture-collision pass exits normally: '.$output);
		$collisionFiles = glob($target.'.force-*.collision-inode');
		$collisionFile = is_array($collisionFiles) && count($collisionFiles) === 1
			? $collisionFiles[0] : '';
		$captureRoot = $collisionFile === ''
			? '' : substr($collisionFile, 0, -strlen('.collision-inode'));
		$collisionInode = is_file($collisionFile)
			? trim(file_get_contents($collisionFile)) : '';
		$current = @lstat($captureRoot);
		$this->assertTrue(is_array($current) && (string)$current['ino'] === $collisionInode,
			'capture creation never replaces the concurrent private directory');
		$this->assertEquals('reserved-bytes', is_file($base.'/crash-data.bin')
			? file_get_contents($base.'/crash-data.bin') : null,
			'capture collision leaves recovered bytes visible and untouched');
		$this->assertEquals($exact, is_file($secondList) ? file_get_contents($secondList) : null,
			'capture collision retains the exact force manifest');
		@unlink($collisionFile);
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'capture collision retry exits normally: '.$output);
		$this->assertTrue(!file_exists($base) && !is_link($base),
			'capture collision retry removes the visible recovery link');
		$this->assertEquals(array(), glob($this->dir.'/.erasedata-rmdir-*'),
			'capture collision retry removes the unmarked collision and recovery roots');
		$this->assertTrue(!file_exists($secondList),
			'capture collision retry completes the retained force manifest');
	}

	public function testForceRecoveryRetriesAfterCaptureInitializationCrash()
	{
		$this->assertForceRecoveryRetriesAfterCaptureInitializationCrash('created', '3', '4');
	}

	public function testForceRecoveryRetriesAfterCaptureMarkerCrash()
	{
		$this->assertForceRecoveryRetriesAfterCaptureInitializationCrash('initialized', '5', '6');
	}

	private function assertForceRecoveryRetriesAfterCaptureInitializationCrash(
		$phase, $firstCharacter, $secondCharacter)
	{
		$this->reset();
		$firstHash = $this->hash($firstCharacter);
		$secondHash = $this->hash($secondCharacter);
		$base = $this->dir.'/force-capture-'.$phase.'-crash-base';
		$secondList = $this->dir.'/erasedata/'.$secondHash.'.list';
		mkdir($base);
		$this->writeManifestLines(
			$firstHash.'.list', array($base.'/already-gone.bin'), $base, 1, 1);
		$this->runCollector(array('rmdirCrash' => $base));
		$this->runCollector(array());
		$this->writeManifestLines(
			$secondHash.'.list', array($base.'/crash-data.bin'), $base, 1, 2);

		list($status, $output) = $this->runCollector(array('forceCaptureInitCrash' => $phase));
		$this->assertEquals(0, $status, $phase.' capture crash exits at the deterministic seam: '.$output);
		$this->assertTrue(is_link($base), $phase.' capture crash keeps recovered data visible');
		$this->assertTrue(is_file($secondList), $phase.' capture crash retains the exact force manifest');

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, $phase.' capture retry exits normally: '.$output);
		$this->assertTrue(!file_exists($base) && !is_link($base),
			'capture initialization retry removes the visible recovery link');
		$this->assertEquals(array(), glob($this->dir.'/.erasedata-rmdir-*'),
			'capture initialization retry removes every private recovery artifact');
		$this->assertTrue(!file_exists($secondList), 'capture initialization retry completes the force manifest');
	}

	public function testNonForceRecoveryDoesNotRmdirPostValidationReplacement()
	{
		$this->reset();
		$firstHash = $this->hash('7');
		$secondHash = $this->hash('8');
		$base = $this->dir.'/nonforce-recovery-rmdir-swap-base';
		$list = $this->dir.'/erasedata/'.$secondHash.'.list';
		mkdir($base);
		$this->writeManifestLines(
			$firstHash.'.list', array($base.'/already-gone.bin'), $base, 1, 1);
		$this->runCollector(array('rmdirCrash' => $base));
		$this->runCollector(array());
		$recovery = glob($this->dir.'/.erasedata-rmdir-*');
		$target = is_link($base) ? @readlink($base) : '';
		@unlink($base.'/crash-data.bin');
		$replacement = $this->dir.'/nonforce-recovery-rmdir-replacement';
		$backup = $target.'.checked';
		$marker = $this->dir.'/nonforce-recovery-rmdir-swap.triggered';
		mkdir($replacement);
		$replacementStat = lstat($replacement);
		$this->writeManifestLines(
			$secondHash.'.list', array($base.'/already-gone-again.bin'), $base, 1, 1);
		$exact = file_get_contents($list);

		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'removeDirectory:*' => array('path' => $target, 'action' => 'replace-entry',
				'backup' => $backup, 'replacement' => $replacement, 'marker' => $marker),
			'removeDirectory:1' => array('basename' => 'entry',
				'contains' => '/.erasedata-entry-', 'action' => 'replace-public',
				'public_path' => $target, 'replacement' => $replacement, 'marker' => $marker),
		)));
		$this->assertEquals(0, $status, 'non-force post-validation swap exits normally: '.$output);
		$current = @lstat($target);
		$this->assertTrue(is_file($marker),
			'the scripted recovery swap reaches the final comparison-to-rmdir boundary');
		$this->assertTrue(is_array($current) && $current['ino'] === $replacementStat['ino'],
			'non-force recovery never removes the replacement installed at the public target');
		$this->assertEquals($exact, is_file($list) ? file_get_contents($list) : null,
			'non-force replacement retains the exact retry manifest');
	}

	public function testCollectorRetainsForcedManifestWhenRecursiveTargetRemains()
	{
		$this->reset();
		$hash = $this->hash('5');
		$base = $this->dir.'/forced-target-that-is-not-a-directory';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		file_put_contents($base, 'still here');
		$this->writeManifestLines($hash.'.list', array($base.'/child.bin'), $base, 1, 2);
		$exact = file_get_contents($list);

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'failed forced collection exits normally: '.$output);
		$this->assertTrue(is_file($base), 'failed recursive deletion leaves its target in place');
		$this->assertTrue(is_file($list), 'the forced deletion obligation survives while its target remains');
		$this->assertEquals($exact, is_file($list) ? file_get_contents($list) : null,
			'forced failure also retains the exact manifest');
	}

	public function testForcedDeletionDoesNotFollowNestedSymlinkIntoActiveData()
	{
		$this->reset();
		$hash = $this->hash('3');
		$oldBase = $this->dir.'/forced-old-root';
		$activeBase = $this->dir.'/forced-active-root';
		$activeFile = $activeBase.'/active.bin';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		mkdir($oldBase);
		mkdir($activeBase);
		file_put_contents($activeFile, 'active-bytes');
		symlink($activeBase, $oldBase.'/jump');
		$this->writeManifestLines($hash.'.list', array($oldBase.'/jump/active.bin'), $oldBase, 1, 2);
		$owned = array('base'=>$activeBase, 'multi'=>1, 'files'=>array($activeFile));

		list($status, $output) = $this->runCollector(array('val' => array($hash), 'owned' => $owned));
		$this->assertEquals(0, $status, 'forced symlink collector exits normally: '.$output);
		$this->assertEquals('active-bytes', is_file($activeFile) ? file_get_contents($activeFile) : null,
			'forced recursion never follows a nested symlink into active data');
		$this->assertTrue(!file_exists($oldBase), 'the disjoint old force root is removed without following its alias');
		$this->assertTrue(!file_exists($list), 'the completed disjoint force obligation is consumed');
	}

	public function testSameHashDifferentDirectoryCanCollectBeforeSecondErase()
	{
		$this->reset();
		$hash = $this->hash('6');
		$oldBase = $this->dir.'/old-generation';
		$newBase = $this->dir.'/active-generation';
		$oldFile = $oldBase.'/old.bin';
		$newFile = $newBase.'/active.bin';
		mkdir($oldBase);
		mkdir($newBase);
		file_put_contents($oldFile, 'old');
		file_put_contents($newFile, 'active');

		$this->frozen(true, array($oldBase, 1, $oldFile));
		$this->eraseOk();
		erasedataRemoveWithData(array($hash), '1');
		$first = $this->onlyManifest($hash);
		$this->assertTrue(is_string($first) && basename($first) !== $hash.'.list',
			'a produced obligation has staging-derived generation identity');

		$owned = array('base'=>$newBase, 'multi'=>1, 'files'=>array($newFile));
		list($status, $output) = $this->runCollector(array('val' => array($hash), 'owned' => $owned));
		$this->assertEquals(0, $status, 'present-generation reconciliation exits normally: '.$output);
		$this->assertTrue(!file_exists($oldFile), 'old non-overlapping data is collected while the hash is present again');
		$this->assertTrue(is_file($newFile), 'the active generation data is untouched');
		$this->assertEquals(array(), $this->manifestFiles($hash), 'the non-overlapping old obligation is complete');

		$this->frozen(true, array($newBase, 1, $newFile));
		$this->eraseOk();
		erasedataRemoveWithData(array($hash), '1');
		$this->assertEquals(1, count($this->manifestFiles($hash)), 'the second erase publishes its own generation');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'post-second-erase collection exits normally: '.$output);
		$this->assertTrue(!file_exists($newFile), 'the second generation is collected after confirmed absence');
		$this->assertEquals(array(), $this->manifestFiles($hash), 'the second obligation is consumed');
	}

	public function testSameHashDifferentDirectoryKeepsBothGenerationsUntilAbsent()
	{
		$this->reset();
		$hash = $this->hash('7');
		$oldBase = $this->dir.'/pending-old';
		$newBase = $this->dir.'/pending-new';
		$oldFile = $oldBase.'/old.bin';
		$newFile = $newBase.'/new.bin';
		mkdir($oldBase);
		mkdir($newBase);
		file_put_contents($oldFile, 'old');
		file_put_contents($newFile, 'new');

		$this->frozen(true, array($oldBase, 1, $oldFile));
		$this->eraseOk();
		erasedataRemoveWithData(array($hash), '1');
		$this->frozen(true, array($newBase, 1, $newFile));
		$this->eraseOk();
		erasedataRemoveWithData(array($hash), '1');

		$this->assertEquals(2, count($this->manifestFiles($hash)), 'a second erase never overwrites an older pending generation');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'multi-generation absent collection exits normally: '.$output);
		$this->assertTrue(!file_exists($oldFile), 'the older generation is collected after absence');
		$this->assertTrue(!file_exists($newFile), 'the newer generation is collected after absence');
		$this->assertEquals(array(), $this->manifestFiles($hash), 'all completed generations are consumed');
	}

	public function testSameHashOverlappingPathRetainsOldObligationWhileActive()
	{
		$this->reset();
		$hash = $this->hash('8');
		$base = $this->dir.'/overlap';
		$file = $base.'/same.bin';
		mkdir($base);
		file_put_contents($file, 'active');

		$this->frozen(true, array($base, 1, $file));
		$this->eraseOk();
		erasedataRemoveWithData(array($hash), '1');
		$first = $this->onlyManifest($hash);
		$exact = is_file($first) ? file_get_contents($first) : null;

		$owned = array('base'=>$base, 'multi'=>1, 'files'=>array($file));
		list($status, $output) = $this->runCollector(array('val' => array($hash), 'owned' => $owned));
		$this->assertEquals(0, $status, 'overlapping present-generation reconciliation exits normally: '.$output);
		$this->assertTrue(is_file($file), 'an overlapping active path is never deleted');
		$this->assertTrue(is_file($first), 'the overlapping deletion obligation remains pending');
		$this->assertEquals($exact, is_file($first) ? file_get_contents($first) : null,
			'the overlapping manifest remains exact while active');

		$this->frozen(true, array($base, 1, $file));
		$this->eraseOk();
		erasedataRemoveWithData(array($hash), '1');
		$this->assertEquals(2, count($this->manifestFiles($hash)), 'the overlapping second erase has a distinct generation');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'overlapping generations collect after absence: '.$output);
		$this->assertTrue(!file_exists($file), 'the overlapping data is deleted after the live generation is absent');
		$this->assertEquals(array(), $this->manifestFiles($hash), 'both overlapping obligations complete after absence');
	}

	public function testPresentReconciliationProtectsRealManifestPathThroughActiveAlias()
	{
		$this->reset();
		$hash = $this->hash('B');
		$real = $this->dir.'/real-generation-a';
		$alias = $this->dir.'/active-alias-a';
		$file = $real.'/same.bin';
		mkdir($real);
		file_put_contents($file, 'active');
		symlink($real, $alias);
		$this->writeManifestLines($hash.'.list', array($file), $real, 1, 1);
		$list = $this->onlyManifest($hash);
		$owned = array('base'=>$alias, 'multi'=>1, 'files'=>array($alias.'/same.bin'));

		list($status, $output) = $this->runCollector(array('val' => array($hash), 'owned' => $owned));
		$this->assertEquals(0, $status, 'real-to-alias reconciliation exits normally: '.$output);
		$this->assertEquals('active', is_file($file) ? file_get_contents($file) : null,
			'the active physical file bytes survive through the real name');
		$this->assertEquals('active', is_readable($alias.'/same.bin') ? file_get_contents($alias.'/same.bin') : null,
			'the active physical file survives through both names');
		$this->assertTrue(is_file($list), 'the overlapping real-path obligation stays pending');

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'post-active real-path collection exits normally: '.$output);
		$this->assertTrue(!file_exists($file), 'the old file is deleted after confirmed active absence');
		$this->assertTrue(!file_exists($list), 'the old obligation then completes');
	}

	public function testPresentReconciliationProtectsAliasManifestPathThroughActiveRealPath()
	{
		$this->reset();
		$hash = $this->hash('C');
		$real = $this->dir.'/real-generation-b';
		$alias = $this->dir.'/manifest-alias-b';
		$file = $real.'/same.bin';
		mkdir($real);
		file_put_contents($file, 'active');
		symlink($real, $alias);
		$this->writeManifestLines($hash.'.list', array($alias.'/same.bin'), $alias, 1, 1);
		$list = $this->onlyManifest($hash);
		$owned = array('base'=>$real, 'multi'=>1, 'files'=>array($file));

		list($status, $output) = $this->runCollector(array('val' => array($hash), 'owned' => $owned));
		$this->assertEquals(0, $status, 'alias-to-real reconciliation exits normally: '.$output);
		$this->assertEquals('active', is_file($file) ? file_get_contents($file) : null,
			'the active physical file bytes survive through the real name');
		$this->assertEquals('active', is_readable($alias.'/same.bin') ? file_get_contents($alias.'/same.bin') : null,
			'the alias cannot authorize deletion of the active real file');
		$this->assertTrue(is_file($list), 'the overlapping alias obligation stays pending');

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'post-active alias collection exits normally: '.$output);
		$this->assertTrue(!file_exists($file), 'the aliased old file is deleted after confirmed absence');
		$this->assertTrue(!file_exists($list), 'the alias obligation completes without looping on the symlink');
	}

	public function testActiveDirectoryContainingManifestFileRetainsTheObligation()
	{
		$this->reset();
		$hash = $this->hash('D');
		$activeBase = $this->dir.'/active-parent';
		$oldFile = $activeBase.'/old-unlisted.bin';
		$activeFile = $activeBase.'/active.bin';
		mkdir($activeBase);
		file_put_contents($oldFile, 'old');
		file_put_contents($activeFile, 'active');
		$this->writeManifest($hash.'.list', $oldFile);
		$list = $this->onlyManifest($hash);
		$owned = array('base'=>$activeBase, 'multi'=>1, 'files'=>array($activeFile));

		list($status, $output) = $this->runCollector(array('val' => array($hash), 'owned' => $owned));
		$this->assertEquals(0, $status, 'active-parent reconciliation exits normally: '.$output);
		$this->assertTrue(is_file($oldFile), 'active directory ownership protects a manifest file below it');
		$this->assertTrue(is_file($list), 'the parent overlap retains the obligation');
	}

	public function testManifestDirectoryContainingActiveFileRetainsUntilActiveAbsence()
	{
		$this->reset();
		$hash = $this->hash('E');
		$oldBase = $this->dir.'/manifest-parent';
		$oldFile = $oldBase.'/old.bin';
		$activeBase = $oldBase.'/active-child';
		$activeFile = $activeBase.'/active.bin';
		mkdir($activeBase, 0777, true);
		file_put_contents($oldFile, 'old');
		file_put_contents($activeFile, 'active');
		$this->writeManifestLines($hash.'.list', array($oldFile), $oldBase, 1, 1);
		$list = $this->onlyManifest($hash);
		$owned = array('base'=>$activeBase, 'multi'=>1, 'files'=>array($activeFile));

		list($status, $output) = $this->runCollector(array('val' => array($hash), 'owned' => $owned));
		$this->assertEquals(0, $status, 'manifest-parent reconciliation exits normally: '.$output);
		$this->assertTrue(is_file($activeFile), 'the active child is untouched');
		$this->assertTrue(is_file($list), 'the parent directory overlap keeps the obligation pending');

		unlink($activeFile);
		rmdir($activeBase);
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'post-active parent collection exits normally: '.$output);
		$this->assertTrue(!file_exists($oldBase), 'the old parent completes after active data disappears');
		$this->assertTrue(!file_exists($list), 'the retained parent obligation is consumed');
	}

	public function testMissingOldPathCompletesEvenWhenActivePathSharesItsName()
	{
		$this->reset();
		$hash = $this->hash('F');
		$missing = $this->dir.'/missing-generation/same.bin';
		$this->writeManifest($hash.'.list', $missing);
		$list = $this->onlyManifest($hash);
		$owned = array('base'=>dirname($missing), 'multi'=>1, 'files'=>array($missing));

		list($status, $output) = $this->runCollector(array('val' => array($hash), 'owned' => $owned));
		$this->assertEquals(0, $status, 'missing-path reconciliation exits normally: '.$output);
		$this->assertTrue(!file_exists($list), 'an already absent old file creates no permanent obligation');
	}

	public function testUnresolvableExistingPathFailsClosed()
	{
		$this->reset();
		$hash = $this->hash('1');
		$dangling = $this->dir.'/dangling-old.bin';
		symlink($this->dir.'/missing-target.bin', $dangling);
		$this->writeManifest($hash.'.list', $dangling);
		$list = $this->onlyManifest($hash);
		$ownedFile = $this->dir.'/active-disjoint.bin';
		file_put_contents($ownedFile, 'active');
		$owned = array('base'=>$ownedFile, 'multi'=>0, 'files'=>array($ownedFile));

		list($status, $output) = $this->runCollector(array('val' => array($hash), 'owned' => $owned));
		$this->assertEquals(0, $status, 'unresolvable-path reconciliation exits normally: '.$output);
		$this->assertTrue(is_link($dangling), 'an existing path with unresolvable identity is not deleted');
		$this->assertTrue(is_file($list), 'fail-closed resolution retains the exact obligation');
	}

	public function testCollectorRetainsOverlappingLegacyFilesForPresentTorrent()
	{
		$this->reset();
		$hash = str_repeat('A', 40);
		$data = $this->dir.'/active.bin';
		file_put_contents($data, 'active');
		$this->writeManifest($hash.'.list', $data);
		$this->writeManifest($hash.'.123.stranded.tmp', $data);
		$owned = array('base'=>$data, 'multi'=>0, 'files'=>array($data));
		list($status, $output) = $this->runCollector(array('val' => array($hash), 'owned' => $owned));
		$this->assertEquals(0, $status, 'collector exits normally for a present torrent: '.$output);
		$this->assertTrue(is_file($data), 'collector never deletes active torrent data');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$hash.'.list'), 'an overlapping legacy list remains an obligation');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$hash.'.123.stranded.list'),
			'an overlapping staging generation is promoted and retained rather than dropped wholesale');
	}

	public function testCollectorLeavesManifestAndDataUntouchedWhenProbeIsUnknown()
	{
		$this->reset();
		$hash = str_repeat('B', 40);
		$data = $this->dir.'/unknown.bin';
		file_put_contents($data, 'unknown');
		$this->writeManifest($hash.'.list', $data);
		list($status, $output) = $this->runCollector(array('ok' => false, 'val' => array()));
		$this->assertEquals(0, $status, 'collector exits normally when rTorrent is unreachable: '.$output);
		$this->assertTrue(is_file($data), 'unknown presence leaves every data byte untouched');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$hash.'.list'), 'unknown presence retains the manifest for a later pass');
	}

	public function testCollectorRecoversStrandedStagingOnlyAfterConfirmedAbsence()
	{
		$this->reset();
		$hash = str_repeat('C', 40);
		$data = $this->dir.'/stranded.bin';
		file_put_contents($data, 'stranded');
		$tmp = $hash.'.123.stranded.tmp';
		$this->writeManifest($tmp, $data);
		list($status, $output) = $this->runCollector(array('val' => array("")));
		$this->assertEquals(0, $status, 'collector exits normally for confirmed absence: '.$output);
		$this->assertTrue(!file_exists($data), 'confirmed absence permits deletion from recovered staging');
		$this->assertTrue(!file_exists($this->dir.'/erasedata/'.$tmp), 'consumed staging path is removed');
		$this->assertTrue(!file_exists($this->dir.'/erasedata/'.$hash.'.list'), 'promoted live manifest is removed after consumption');
	}

	public function testCollectorDoesNotConsumeAHashLockedByAnotherProcess()
	{
		$this->reset();
		$hash = str_repeat('D', 40);
		$data = $this->dir.'/locked.bin';
		file_put_contents($data, 'locked');
		$this->writeManifest($hash.'.list', $data);
		$lock = fopen($this->dir.'/erasedata/'.$hash.'.lock', 'c');
		$this->assertTrue($lock !== false && flock($lock, LOCK_EX | LOCK_NB), 'test holds the stable hash lock');
		list($status, $output) = $this->runCollector(array('val' => array("")));
		$this->assertEquals(0, $status, 'locked collector pass exits normally: '.$output);
		$this->assertTrue(is_file($data), 'locked hash data is not consumed by another process');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$hash.'.list'), 'locked hash manifest remains for a later pass');
		flock($lock, LOCK_UN);
		fclose($lock);
	}

	public function testCanonicalHashLockSerializesLifecycle()
	{
		$this->reset();
		$hash = $this->hash();
		$producerLock = erasedataAcquireHashLock($this->dir.'/erasedata', $hash, true);
		$collectorLock = @fopen($this->dir.'/erasedata/'.$hash.'.lock', 'c');
		$collectorAcquired = $collectorLock !== false && @flock($collectorLock, LOCK_EX | LOCK_NB);
		$this->assertTrue($producerLock !== false, 'producer acquires the canonical hash lock');
		$this->assertTrue(!$collectorAcquired, 'producer and collector cannot enter one hash lifecycle concurrently');
		if($collectorAcquired)
			flock($collectorLock, LOCK_UN);
		if($collectorLock !== false)
			fclose($collectorLock);
		erasedataReleaseHashLock($producerLock);
	}

	public function testCollectorIgnoresNonCanonicalManifestNames()
	{
		$this->reset();
		$data = $this->dir.'/noncanonical.bin';
		file_put_contents($data, 'noncanonical');
		$this->writeManifest('not-a-hash.list', $data);
		list($status, $output) = $this->runCollector(array('val' => array("")));
		$this->assertEquals(0, $status, 'collector exits normally with an unrelated file: '.$output);
		$this->assertTrue(is_file($data), 'non-canonical filenames can never authorize data deletion');
		$this->assertTrue(is_file($this->dir.'/erasedata/not-a-hash.list'), 'non-canonical manifest is ignored');
	}

	public function testCollectorNeverConsumesASymlinkedManifest()
	{
		$this->reset();
		$hash = str_repeat('E', 40);
		$data = $this->dir.'/symlink-target-data.bin';
		$manifest = $this->dir.'/external-manifest';
		file_put_contents($data, 'symlink-target-data');
		file_put_contents($manifest, $data."\n".$data."\n0\n1\n");
		symlink($manifest, $this->dir.'/erasedata/'.$hash.'.list');
		list($status, $output) = $this->runCollector(array('val' => array("")));
		$this->assertEquals(0, $status, 'collector exits normally with a symlinked candidate: '.$output);
		$this->assertTrue(is_file($data), 'a symlink cannot supply paths that authorize data deletion');
		$this->assertTrue(is_link($this->dir.'/erasedata/'.$hash.'.list'), 'symlinked candidate is ignored');
	}

	public function testCollectorRejectsManifestSwappedToSymlinkAfterProbeStarts()
	{
		$this->reset();
		$hash = str_repeat('F', 40);
		$data = $this->dir.'/swap-target-data.bin';
		$external = $this->dir.'/swap-external-manifest';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		file_put_contents($data, 'swap-target-data');
		file_put_contents($external, $data."\n".$data."\n0\n1\n");
		file_put_contents($list, "ignored\nignored\n0\n1\n");
		list($status, $output) = $this->runCollector(array('val' => array(""), 'swap' => array($list, $external)));
		$this->assertEquals(0, $status, 'collector exits normally after a manifest inode swap: '.$output);
		$this->assertTrue(is_file($data), 'post-scan symlink swap cannot authorize data deletion');
		$this->assertTrue(is_link($list), 'swapped manifest is retained rather than consumed as the scanned inode');
	}

	public function testCollectorRejectsManifestSwappedToDifferentRegularInode()
	{
		$this->reset();
		$hash = str_repeat('1', 40);
		$data = $this->dir.'/regular-swap-target-data.bin';
		$replacement = $this->dir.'/regular-swap-manifest';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		file_put_contents($data, 'regular-swap-target-data');
		file_put_contents($replacement, $data."\n".$data."\n0\n1\n");
		file_put_contents($list, "ignored\nignored\n0\n1\n");
		list($status, $output) = $this->runCollector(array(
			'val' => array(""), 'swap' => array($list, $replacement, 'rename')));
		$this->assertEquals(0, $status, 'collector exits normally after a regular manifest inode swap: '.$output);
		$this->assertTrue(is_file($data), 'post-scan regular inode swap cannot authorize data deletion');
		$this->assertTrue(is_file($list), 'replacement inode is retained for a later freshly-probed pass');
	}

	public function testInvalidForceIsRejectedBeforeStagingAndErase()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("/d/name", 1, "/d/name/a.bin"));
		$this->eraseOk();
		$invalidForces = array(null, false, true, 0, 1, 2, "", "0", "01", "02", " 1", "1 ", "2\n1\n2", array(1), (object)array('force' => 1));
		foreach($invalidForces as $inv)
		{
			rXMLRPCRequest::$erased = array();
			$res = erasedataRemoveWithData(array($hash), $inv);
			$this->assertTrue($res === false, 'invalid force parameter must be rejected before staging/erase');
			$this->assertEquals(array(), rXMLRPCRequest::$erased, 'd.erase must not be called for invalid force');
			$this->assertEquals(array(), $this->manifestFiles($hash, 'tmp'), 'staging must not be published for invalid force');
			$this->assertEquals(array(), $this->manifestFiles($hash, 'list'), 'manifest must not be published for invalid force');
		}
	}

	public function testHttprpcRemovalFailsClosedWithoutErasedataHelper()
	{
		$this->reset();
		$hash = $this->hash();
		list($status, $output, $commands) = $this->runCopiedAction(
			__DIR__.'/../../../plugins/httprpc/action.php',
			'mode=removewithdata&hash='.$hash.'&v=1', false);
		$this->assertEquals(0, $status, 'copied httprpc action exits normally: '.$output);
		$this->assertEquals(array(), $commands,
			'missing erasedata helper must fail closed without issuing raw d.erase');
	}

	public function testDirectActionRejectsMissingForceBeforeCallingProducer()
	{
		$this->reset();
		$hash = $this->hash();
		list($status, $output, $commands) = $this->runCopiedAction(
			__DIR__.'/../../../plugins/erasedata/action.php',
			'mode=removewithdata&hash='.$hash, true);
		$this->assertEquals(0, $status, 'copied erasedata action exits normally: '.$output);
		$this->assertEquals(array(), $commands,
			'missing force must be rejected before the shared producer is called');
	}

	public function testV2ManifestRoundTripsNewlineCarriageReturnAndNonUTF8PathBytes()
	{
		$this->reset();
		$hash = $this->hash();
		$specialFile = "/d/name/a\nb\r-\xFF.bin";
		$base = "/d/name";
		$this->frozen(true, array($base, 1, $specialFile));
		$this->eraseOk();
		$res = erasedataRemoveWithData(array($hash), "1");
		$this->assertTrue($res !== false, 'valid request with byte-opaque path must succeed');
		$record = $this->manifestRecordFor($hash);
		$this->assertTrue(is_array($record), 'v2 manifest record must decode properly');
		$this->assertEquals(2, $record['version'], 'version must be 2');
		$this->assertEquals($hash, $record['hash'], 'hash must match');
		$this->assertEquals(array($specialFile), $record['files'], 'path bytes with newline, CR, and non-UTF8 must be strictly preserved');
		$this->assertEquals($base, $record['base'], 'base path must be strictly preserved');
		$this->assertTrue($record['multi'], 'multi flag must be boolean true');
		$this->assertEquals(1, $record['force'], 'force must be normalized int 1');
	}

	public function testEncoderRejectsFileCountBeforeEncodingPaths()
	{
		$this->reset();
		$hash = $this->hash();
		$content = ErasedataManifestCodec::encode($hash, array(
			'base' => '/d',
			'multi' => true,
			'files' => array('/d/one', '/d/two'),
		), "1", array('max_files' => 1));
		$this->assertTrue($content === false,
			'file-count limit must reject before any path encoding loop');
	}

	public function testEncoderRejectsAggregateEncodedSizeIncrementally()
	{
		$this->reset();
		$hash = $this->hash();
		$paths = array(
			'base' => '/d',
			'multi' => true,
			'files' => array('/d/aaaa', '/d/bbbb'),
		);
		$content = ErasedataManifestCodec::encode(
			$hash, $paths, "1", array('max_manifest_bytes' => 165));
		$this->assertTrue($content === false,
			'aggregate encoded-size limit must reject before building complete JSON');
		$boundary = ErasedataManifestCodec::encode(
			$hash, $paths, "1", array('max_manifest_bytes' => 166));
		$this->assertTrue(is_string($boundary) && strlen($boundary) === 166,
			'exact aggregate accounting still admits a manifest at its byte limit');
	}

	public function testV2RejectsEverySlashOnlyRootAlias()
	{
		$this->reset();
		$hash = $this->hash();
		foreach(array('//', '///') as $root)
		{
			$this->assertTrue(ErasedataManifestCodec::encode($hash, array(
				'base' => $root,
				'multi' => false,
				'files' => array($root),
			), "1") === false, 'v2 producer rejects slash-only root alias '.$root);
			$bytes = json_encode(array(
				'version' => 2,
				'hash' => $hash,
				'path_encoding' => 'base64',
				'files' => array(base64_encode($root)),
				'base' => base64_encode($root),
				'multi' => false,
				'force' => 1,
			), JSON_UNESCAPED_SLASHES)."\n";
			$this->assertTrue(ErasedataManifestCodec::decodeBytes($bytes, $hash) === false,
				'v2 decoder rejects slash-only root alias '.$root);
		}
	}

	public function testLegacyRejectsEverySlashOnlyRootAlias()
	{
		$this->reset();
		$hash = $this->hash();
		foreach(array('//', '///') as $root)
		{
			$bytes = $root."\n".$root."\n0\n1\n";
			$this->assertTrue(ErasedataManifestCodec::decodeBytes($bytes, $hash) === false,
				'legacy decoder rejects slash-only root alias '.$root);
		}
	}

	// -- S01 single-source characterization ---------------------------------

	private function codecHandleFor($bytes)
	{
		$handle = fopen('php://memory', 'r+b');
		fwrite($handle, $bytes);
		rewind($handle);
		return($handle);
	}

	public function testEveryManifestGenerationDecodesToOneNormalizedRecordShape()
	{
		$this->reset();
		$hash = $this->hash();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/normalized-base';
		$file = $base.'/payload.bin';
		@mkdir($base, 0777, true);
		file_put_contents($file, 'payload');

		$v2 = ErasedataManifestCodec::encode($hash,
			array('files' => array($file), 'base' => $file, 'multi' => false), "1");
		$this->assertTrue(is_string($v2), 'the codec must produce v2 bytes for a valid single-file payload');
		$cleanup = ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $newHash,
			'0123456789abcdef0123456789abcdef', $oldHash.'-started-1787587200', $base,
			array($this->cleanupEntry($file)));
		$this->assertTrue(is_string($cleanup), 'the codec must produce v3 cleanup bytes');

		$generations = array(
			'legacy v1' => array($file."\n".$file."\n0\n1\n", $hash, 1, 'remove_payload', true),
			'v2' => array($v2, $hash, 2, 'remove_payload', false),
			'v3 cleanup' => array($cleanup, $oldHash, 3, 'cleanup_obsolete', false),
		);
		foreach($generations as $label => $case)
		{
			list($bytes, $expectedHash, $version, $operation, $legacy) = $case;
			$record = ErasedataManifestCodec::decodeBytes($bytes, $expectedHash);
			$this->assertTrue(is_array($record), $label.' must decode through the one codec');
			// Every consumer reads these normalized keys, so no consumer has to
			// know which physical generation produced the bytes.
			foreach(array('version', 'operation', 'hash', 'files', 'base', 'multi',
				'force', 'keep_base', 'legacy') as $key)
				$this->assertTrue(array_key_exists($key, $record),
					$label.' record must expose the normalized key '.$key);
			$this->assertEquals($version, $record['version'], $label.' must report its exact version');
			$this->assertEquals($operation, $record['operation'], $label.' must report its exact operation');
			$this->assertEquals($legacy, $record['legacy'], $label.' must report its exact legacy flag');

			$handle = $this->codecHandleFor($bytes);
			$streamed = ErasedataManifestCodec::decodeStream($handle, $expectedHash);
			fclose($handle);
			$this->assertEquals($record, $streamed,
				$label.' must decode identically through the byte and stream entrypoints');
		}
	}

	public function testEveryCodecEntrypointSharesOneRejectionPolicy()
	{
		$this->reset();
		$hash = $this->hash();
		$file = $this->dir.'/rejection.bin';
		$valid = ErasedataManifestCodec::encode($hash,
			array('files' => array($file), 'base' => $file, 'multi' => false), "1");
		$this->assertTrue(is_string($valid), 'the rejection matrix needs a valid v2 baseline');

		$rejected = array(
			'empty bytes' => '',
			'bare open brace' => '{',
			'truncated v2 JSON' => substr($valid, 0, strlen($valid) - 8),
			'v2 with a non-canonical base64 path' => str_replace(
				base64_encode($file), rtrim(base64_encode($file), '=').'=====', $valid),
			'legacy with a missing force line' => $file."\n".$file."\n0\n",
			'legacy with a non-canonical force token' => $file."\n".$file."\n0\n01\n",
			'legacy ambiguity: a JSON body without a version' => "{\"files\":[]}\n",
			'oversize declared through the byte limit' => str_repeat('x',
				ErasedataManifestCodec::MAX_PATH_BYTES + 1),
		);
		foreach($rejected as $label => $bytes)
		{
			$this->assertTrue(ErasedataManifestCodec::decodeBytes($bytes, $hash) === false,
				'decodeBytes must reject '.$label);
			$handle = $this->codecHandleFor($bytes);
			$streamed = ErasedataManifestCodec::decodeStream($handle, $hash);
			fclose($handle);
			$this->assertTrue($streamed === false, 'decodeStream must reject '.$label);
		}

		// One hash policy, not one per entrypoint.
		foreach(array('', 'not-a-hash', str_repeat('A', 39), str_repeat('G', 40)) as $badHash)
		{
			$this->assertTrue(ErasedataManifestCodec::decodeBytes($valid, $badHash) === false,
				'decodeBytes must reject the expected hash "'.$badHash.'"');
			$handle = $this->codecHandleFor($valid);
			$streamed = ErasedataManifestCodec::decodeStream($handle, $badHash);
			fclose($handle);
			$this->assertTrue($streamed === false,
				'decodeStream must reject the expected hash "'.$badHash.'"');
		}
	}

	public function testEveryManifestReadBoundaryStopsAtTheByteCeiling()
	{
		$this->reset();
		$hash = $this->hash();
		$ceiling = ErasedataManifestCodec::MAX_MANIFEST_BYTES
			+ ErasedataManifestCodec::READ_CHUNK_BYTES;

		// The production ceiling is 64 MiB, so exercising it buffers 64 MiB and
		// PHP needs headroom to grow that string. A stock 128M limit is not
		// enough, and a fatal here would fail the whole harness, so raise the
		// limit for this one test and restore whatever the environment had.
		$previousLimit = ini_get('memory_limit');
		$raised = false;
		if(is_string($previousLimit) && $previousLimit !== '' && $previousLimit !== '-1')
			$raised = (ini_set('memory_limit', '512M') !== false);

		try
		{
			ErasedataOversizeStream::register();
			$handle = fopen('erasedataoversize://payload', 'rb');
			$this->assertTrue(is_resource($handle), 'the oversize fixture stream must open');
			$bytes = ErasedataManifestCodec::readBoundedHandle($handle);
			fclose($handle);
			$this->assertTrue($bytes === false, 'a handle past the ceiling must be refused');
			$this->assertTrue(ErasedataOversizeStream::$served <= $ceiling,
				'readBoundedHandle must stop at the ceiling, but consumed '
					.ErasedataOversizeStream::$served.' of '.ErasedataOversizeStream::$total.' bytes');
			unset($bytes);

			ErasedataOversizeStream::register();
			$this->assertTrue(ErasedataManifestCodec::readBoundedFile('erasedataoversize://payload') === false,
				'a file past the ceiling must be refused');
			$this->assertTrue(ErasedataOversizeStream::$served <= $ceiling,
				'readBoundedFile must stop at the ceiling, but consumed '
					.ErasedataOversizeStream::$served.' bytes');

			ErasedataOversizeStream::register();
			$handle = fopen('erasedataoversize://payload', 'rb');
			$this->assertTrue(ErasedataManifestCodec::decodeStream($handle, $hash) === false,
				'decodeStream must refuse a payload past the ceiling');
			fclose($handle);
			$this->assertTrue(ErasedataOversizeStream::$served <= $ceiling,
				'decodeStream must stop at the ceiling, but consumed '
					.ErasedataOversizeStream::$served.' bytes');

			// A payload at the exact ceiling is still read in full, so the bound
			// is a ceiling and not an off-by-one truncation.
			ErasedataOversizeStream::register(ErasedataManifestCodec::MAX_MANIFEST_BYTES);
			$exact = fopen('erasedataoversize://payload', 'rb');
			$atLimit = ErasedataManifestCodec::readBoundedHandle($exact);
			fclose($exact);
			$this->assertTrue(is_string($atLimit)
				&& strlen($atLimit) === ErasedataManifestCodec::MAX_MANIFEST_BYTES,
				'a payload of exactly the ceiling must still be read in full');
			unset($atLimit);
		}
		catch(Exception $e)
		{
			if($raised)
				ini_set('memory_limit', $previousLimit);
			throw $e;
		}
		if($raised)
			ini_set('memory_limit', $previousLimit);
	}

	public function testCleanupObsoleteManifestRoundTripsStrictIdentity()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/cleanup-base';
		$file = $base.'/obsolete.bin';
		@mkdir($base, 0777, true);
		file_put_contents($file, 'obsolete');
		$marker = '0123456789abcdef0123456789abcdef';
		$record = $oldHash.'-started-1787587200';
		$identity = $this->cleanupIdentity($file);
		$manifest = ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $newHash, $marker,
			$record, $base, array(array('path' => $file, 'identity' => $identity)));
		$this->assertTrue(is_string($manifest), 'valid obsolete cleanup input must encode');
		$decoded = ErasedataManifestCodec::decodeBytes($manifest, $oldHash);
		$this->assertTrue(is_array($decoded), 'valid obsolete cleanup manifest must decode');
		$this->assertEquals(3, $decoded['version'], 'cleanup manifest must decode as version 3');
		$this->assertEquals('cleanup_obsolete', $decoded['operation'], 'cleanup manifest must retain its operation');
		$this->assertEquals($oldHash, $decoded['hash'], 'cleanup manifest must retain the old hash');
		$this->assertEquals($newHash, $decoded['new_hash'], 'cleanup manifest must retain the new hash');
		$this->assertEquals($marker, $decoded['marker'], 'cleanup manifest must retain the generation marker');
		$this->assertEquals($record, $decoded['replacement_record'], 'cleanup manifest must retain the replacement record');
		$this->assertEquals($base, $decoded['base'], 'cleanup manifest must retain its base path');
		$this->assertEquals(array($file), $decoded['files'], 'cleanup manifest must retain the exact obsolete target');
		$this->assertEquals($identity, $decoded['identities'][$file], 'cleanup manifest must retain the original filesystem identity');
		$this->assertTrue($decoded['multi'], 'cleanup manifest must synthesize multi-file handling');
		$this->assertEquals(1, $decoded['force'], 'cleanup manifest must synthesize non-force deletion');
		$this->assertTrue($decoded['keep_base'], 'cleanup manifest must always retain its base directory');
	}

	public function testCleanupObsoleteManifestPreservesNonUtf8PathBytes()
	{
		$this->reset();
		$oldHash = $this->hash('C');
		$newHash = $this->hash('D');
		$base = $this->dir.'/cleanup-bytes';
		$file = $base.'/obsolete-\xFF.bin';
		@mkdir($base, 0777, true);
		file_put_contents($file, 'obsolete');
		$manifest = ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $newHash,
			'fedcba9876543210fedcba9876543210', $oldHash.'-open-1787587201', $base,
			array($this->cleanupEntry($file)));
		$this->assertTrue(is_string($manifest), 'non-UTF8 path bytes must encode through base64');
		$decoded = ErasedataManifestCodec::decodeBytes($manifest, $oldHash);
		$this->assertTrue(is_array($decoded), 'non-UTF8 cleanup manifest must decode');
		$this->assertEquals(array($file), $decoded['files'], 'non-UTF8 path bytes must round trip exactly');
		$this->assertEquals($this->cleanupIdentity($file), $decoded['identities'][$file],
			'non-UTF8 identity paths must round trip exactly');
	}

	public function testCleanupObsoleteManifestRejectsWrongTopLevelShape()
	{
		$this->reset();
		$oldHash = $this->hash('E');
		$newHash = $this->hash('F');
		$base = $this->dir.'/cleanup-shape';
		$file = $base.'/obsolete.bin';
		@mkdir($base, 0777, true);
		file_put_contents($file, 'obsolete');
		$manifest = ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $newHash,
			'00112233445566778899aabbccddeeff', $oldHash.'-stopped-1787587202', $base,
			array($this->cleanupEntry($file)));
		$fields = json_decode($manifest, true);
		unset($fields['marker']);
		$this->assertEquals(false, ErasedataManifestCodec::decodeBytes(json_encode($fields), $oldHash),
			'cleanup manifest missing a required top-level field must be rejected');
		$fields = json_decode($manifest, true);
		$fields['unexpected'] = true;
		$this->assertEquals(false, ErasedataManifestCodec::decodeBytes(json_encode($fields), $oldHash),
			'cleanup manifest with an extra top-level field must be rejected');
	}

	public function testCleanupObsoleteManifestRejectsDuplicateSerializedMembers()
	{
		$this->reset();
		$oldHash = $this->hash('E');
		$newHash = $this->hash('F');
		$base = $this->dir.'/cleanup-duplicates';
		$file = $base.'/obsolete.bin';
		@mkdir($base, 0777, true);
		file_put_contents($file, 'obsolete');
		$manifest = ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $newHash,
			'00112233445566778899aabbccddeeff', $oldHash.'-stopped-1787587202', $base,
			array($this->cleanupEntry($file)));
		$duplicateOperation = str_replace('"operation":"cleanup_obsolete",',
			'"operation":"cleanup_obsolete","operation":"cleanup_obsolete",', $manifest);
		$this->assertEquals(false, ErasedataManifestCodec::decodeBytes($duplicateOperation, $oldHash),
			'duplicate serialized cleanup operation members must be rejected');
		$escapedDuplicateOperation = str_replace('"operation":"cleanup_obsolete",',
			'"operation":"cleanup_obsolete","\\u006fperation":"cleanup_obsolete",', $manifest);
		$this->assertEquals(false, ErasedataManifestCodec::decodeBytes($escapedDuplicateOperation, $oldHash),
			'escaped duplicate cleanup operation members must be rejected');
		$identity = $this->cleanupIdentity($file);
		$duplicateIno = str_replace('"ino":'.$identity['lstat']['ino'].'},"stat"',
			'"ino":'.$identity['lstat']['ino'].',"ino":'.$identity['lstat']['ino'].'},"stat"', $manifest);
		$this->assertEquals(false, ErasedataManifestCodec::decodeBytes($duplicateIno, $oldHash),
			'duplicate serialized cleanup identity members must be rejected');
	}

	// The deliberate mirror of the test above: the serialized-member rule
	// guards the cleanup version only. A v2 payload manifest has one writer --
	// encode(), which serializes a PHP array and so cannot emit a repeated key
	// -- and one reader, the json_decode inside decodeBytes(), which takes the
	// last value. No second reader of those bytes exists to disagree with it,
	// so there is nothing for the rule to prevent. This pins that reading, and
	// pins that the manifest a real installation has on disk still decodes, so
	// a later change to decodeBytes() has to argue with the decision rather
	// than drift into it.
	public function testPayloadManifestTakesTheLastValueForDuplicateSerializedMembers()
	{
		$this->reset();
		$hash = $this->hash('D');
		$base = $this->dir.'/payload-duplicates';
		$file = $base.'/payload.bin';
		$manifest = ErasedataManifestCodec::encode($hash,
			array('files' => array($file), 'base' => $base, 'multi' => true), 1);
		$this->assertTrue(is_string($manifest) && strpos($manifest, '"force":1') !== false,
			'the v2 manifest this pins is the one encode() actually produces');
		$decoded = ErasedataManifestCodec::decodeBytes($manifest, $hash);
		$this->assertEquals(1, is_array($decoded) ? $decoded['force'] : null,
			'the well-formed v2 manifest decodes, unchanged');
		$duplicateForce = str_replace('"force":1', '"force":1,"force":2', $manifest);
		$decoded = ErasedataManifestCodec::decodeBytes($duplicateForce, $hash);
		$this->assertEquals(2, is_array($decoded) ? $decoded['force'] : null,
			'a repeated v2 member is not refused: json_decode takes the last value');
	}

	public function testCleanupObsoleteManifestRejectsWrongGeneration()
	{
		$this->reset();
		$oldHash = $this->hash('1');
		$newHash = $this->hash('2');
		$base = $this->dir.'/cleanup-generation';
		$file = $base.'/obsolete.bin';
		@mkdir($base, 0777, true);
		file_put_contents($file, 'obsolete');
		$manifest = ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $newHash,
			'11112222333344445555666677778888', $oldHash.'-started-1787587203', $base,
			array($this->cleanupEntry($file)));
		$fields = json_decode($manifest, true);
		$fields['version'] = 2;
		$this->assertEquals(false, ErasedataManifestCodec::decodeBytes(json_encode($fields), $oldHash),
			'cleanup manifest with the wrong version must be rejected');
		$fields = json_decode($manifest, true);
		$fields['operation'] = 'remove_payload';
		$this->assertEquals(false, ErasedataManifestCodec::decodeBytes(json_encode($fields), $oldHash),
			'cleanup manifest with the wrong operation must be rejected');
		$fields = json_decode($manifest, true);
		$fields['new_hash'] = $oldHash;
		$this->assertEquals(false, ErasedataManifestCodec::decodeBytes(json_encode($fields), $oldHash),
			'cleanup manifest must reject a successor hash equal to the predecessor');
	}

	public function testCleanupObsoleteManifestRejectsUnsafeOrDuplicateTargets()
	{
		$this->reset();
		$oldHash = $this->hash('3');
		$newHash = $this->hash('4');
		$base = $this->dir.'/cleanup-targets';
		$file = $base.'/obsolete.bin';
		@mkdir($base, 0777, true);
		file_put_contents($file, 'obsolete');
		$entry = $this->cleanupEntry($file);
		$this->assertEquals(false, ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $newHash,
			'22223333444455556666777788889999', $oldHash.'-open-1787587204', $base,
			array($entry, $entry)), 'duplicate cleanup targets must not encode');
		$this->assertEquals(false, ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $newHash,
			'22223333444455556666777788889999', $oldHash.'-open-1787587204', $base,
			array(array('path' => $base, 'identity' => $this->cleanupIdentity($base)))),
			'cleanup base must never encode as a deletion target');
		$manifest = ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $newHash,
			'22223333444455556666777788889999', $oldHash.'-open-1787587204', $base, array($entry));
		$fields = json_decode($manifest, true);
		$fields['files'][] = $fields['files'][0];
		$this->assertEquals(false, ErasedataManifestCodec::decodeBytes(json_encode($fields), $oldHash),
			'duplicate cleanup targets in persisted bytes must be rejected');
		$fields = json_decode($manifest, true);
		$fields['files'][0]['path'] = base64_encode($base);
		$this->assertEquals(false, ErasedataManifestCodec::decodeBytes(json_encode($fields), $oldHash),
			'persisted cleanup target equal to base must be rejected');
	}

	public function testCleanupObsoleteManifestRejectsBasePathAliases()
	{
		$this->reset();
		$oldHash = $this->hash('7');
		$newHash = $this->hash('8');
		$base = $this->dir.'/cleanup-base-alias';
		$file = $base.'/obsolete.bin';
		@mkdir($base, 0777, true);
		file_put_contents($file, 'obsolete');
		$marker = '444455556666777788889999aaaabbbb';
		$record = $oldHash.'-open-1787587206';
		$this->assertEquals(false, ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $newHash,
			$marker, $record, $base.'/', array($this->cleanupEntry($file))),
			'cleanup bases with a trailing separator must be rejected as non-canonical');
		$this->assertEquals(false, ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $newHash,
			$marker, $record, $base.'/', array(array('path' => $base, 'identity' => $this->cleanupIdentity($base)))),
			'a cleanup target spelling the trailing-slash base without its separator must be rejected');
		$this->assertEquals(false, ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $newHash,
			$marker, $record, $base, array(array('path' => $base.'//', 'identity' => $this->cleanupIdentity($base)))),
			'a doubled-separator cleanup target aliasing base must be rejected');
		$manifest = ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $newHash,
			$marker, $record, $base, array($this->cleanupEntry($file)));
		$aliasedBase = str_replace('"base":"'.base64_encode($base).'"',
			'"base":"'.base64_encode($base.'/').'"', $manifest);
		$this->assertEquals(false, ErasedataManifestCodec::decodeBytes($aliasedBase, $oldHash),
			'persisted cleanup manifests must reject a trailing-separator base alias');
		$aliasedTarget = str_replace('"path":"'.base64_encode($file).'"',
			'"path":"'.base64_encode($base.'//').'"', $manifest);
		$this->assertEquals(false, ErasedataManifestCodec::decodeBytes($aliasedTarget, $oldHash),
			'persisted cleanup manifests must reject a doubled-separator target alias');
	}

	public function testCleanupObsoleteManifestRejectsMalformedIdentity()
	{
		$this->reset();
		$oldHash = $this->hash('5');
		$newHash = $this->hash('6');
		$base = $this->dir.'/cleanup-identity';
		$file = $base.'/obsolete.bin';
		@mkdir($base, 0777, true);
		file_put_contents($file, 'obsolete');
		$entry = $this->cleanupEntry($file);
		$entry['identity']['lstat']['ino'] = -1;
		$this->assertEquals(false, ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $newHash,
			'3333444455556666777788889999aaaa', $oldHash.'-stopped-1787587205', $base, array($entry)),
			'negative identity fields must not encode');
		$manifest = ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $newHash,
			'3333444455556666777788889999aaaa', $oldHash.'-stopped-1787587205', $base,
			array($this->cleanupEntry($file)));
		$fields = json_decode($manifest, true);
		$fields['files'][0]['stat']['dev'] = '1';
		$this->assertEquals(false, ErasedataManifestCodec::decodeBytes(json_encode($fields), $oldHash),
			'non-integer persisted identity fields must be rejected');
		$fields = json_decode($manifest, true);
		unset($fields['files'][0]['mtime']);
		$this->assertEquals(false, ErasedataManifestCodec::decodeBytes(json_encode($fields), $oldHash),
			'persisted identity missing a required field must be rejected');
	}

	public function testCleanupObsoleteManifestEncodingIsByteDeterministic()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$entry = array(
			'path' => '/d/base/old.bin',
			'identity' => array(
				'canonical' => '/d/base/old.bin',
				'lstat' => array('dev' => 1, 'ino' => 2),
				'stat' => array('dev' => 1, 'ino' => 2),
				'size' => 3,
				'mtime' => 4,
			),
		);
		$expected = '{"version":3,"operation":"cleanup_obsolete","hash":"'.$oldHash.'","new_hash":"'.$newHash.'",'
			.'"marker":"0123456789abcdef0123456789abcdef","replacement_record":"'.$oldHash.'-started-1787587200",'
			.'"path_encoding":"base64","base":"L2QvYmFzZQ==","files":[{"path":"L2QvYmFzZS9vbGQuYmlu",'
			.'"canonical":"L2QvYmFzZS9vbGQuYmlu","lstat":{"dev":1,"ino":2},"stat":{"dev":1,"ino":2},'
			.'"size":3,"mtime":4}]}' . "\n";
		$this->assertEquals($expected, ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $newHash,
			'0123456789abcdef0123456789abcdef', $oldHash.'-started-1787587200', '/d/base', array($entry)),
			'cleanup manifest encoding must preserve the locked key order and exact bytes');
	}

	public function testCleanupObsoleteManifestRejectsMalformedGenerationFields()
	{
		$this->reset();
		$oldHash = $this->hash('9');
		$newHash = $this->hash('A');
		$base = $this->dir.'/cleanup-generation-fields';
		$file = $base.'/obsolete.bin';
		@mkdir($base, 0777, true);
		file_put_contents($file, 'obsolete');
		$entry = $this->cleanupEntry($file);
		$cases = array(
			array('marker' => 'short', 'record' => $oldHash.'-started-1787587207', 'reason' => 'malformed marker'),
			array('marker' => '55556666777788889999aaaabbbbcccc', 'record' => $newHash.'-started-1787587207', 'reason' => 'wrong old hash'),
			array('marker' => '55556666777788889999aaaabbbbcccc', 'record' => $oldHash.'-running-1787587207', 'reason' => 'invalid run state'),
			array('marker' => '55556666777788889999aaaabbbbcccc', 'record' => $oldHash.'-started-0', 'reason' => 'zero epoch'),
		);
		foreach($cases as $case)
			$this->assertEquals(false, ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $newHash,
				$case['marker'], $case['record'], $base, array($entry)),
				'cleanup manifest must reject '.$case['reason'].' generation data');
	}

	private function cleanupWriterContents($oldHash, $newHash = null)
	{
		if($newHash === null)
			$newHash = $this->hash('B');
		$base = $this->dir.'/writer-base';
		$file = $base.'/obsolete.bin';
		@mkdir($base, 0777, true);
		file_put_contents($file, 'obsolete');
		return(ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $newHash,
			'0123456789abcdef0123456789abcdef', $oldHash.'-started-1787587200', $base,
			array($this->cleanupEntry($file))));
	}

	public function testScannerRecognizesOnlyValidCleanupTaggedNames()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$valid = $oldHash.'.cleanup.123.safeToken.tmp';
		file_put_contents($this->dir.'/erasedata/'.$valid, $this->cleanupWriterContents($oldHash));
		foreach(array(
			$oldHash.'.cleanup.bad.safeToken.tmp',
			$oldHash.'.cleanup.123.bad-token.tmp',
			$oldHash.'.cleanup.123..tmp',
		) as $name)
			file_put_contents($this->dir.'/erasedata/'.$name, 'ignored');
		$legacy = $oldHash.'.123.safeToken.tmp';
		file_put_contents($this->dir.'/erasedata/'.$legacy, 'legacy');
		$legacyList = $oldHash.'.list';
		file_put_contents($this->dir.'/erasedata/'.$legacyList, 'legacy-list');

		$candidate = erasedataParseCollectorCandidate($this->dir.'/erasedata', $valid);
		$this->assertTrue(is_array($candidate), 'a canonical cleanup name must be recognized');
		$this->assertEquals('cleanup_obsolete', $candidate['operation'], 'the cleanup tag must select the cleanup operation');
		$this->assertEquals('tmp', $candidate['type'], 'the canonical suffix must be preserved');
		$legacyCandidate = erasedataParseCollectorCandidate($this->dir.'/erasedata', $legacy);
		$this->assertEquals('remove_payload', $legacyCandidate['operation'], 'the standard name must retain the remove-payload operation');
		$this->assertEquals('tmp', $legacyCandidate['type'],
			'the standard remove-payload filename must retain its original tmp suffix');
		$legacyListCandidate = erasedataParseCollectorCandidate($this->dir.'/erasedata', $legacyList);
		$this->assertEquals('list', $legacyListCandidate['type'],
			'the standard remove-payload filename must retain its original list suffix');
		foreach(array_slice(scandir($this->dir.'/erasedata'), 2) as $name)
			if($name !== $valid && $name !== $legacy && $name !== $legacyList)
				$this->assertEquals(false, erasedataParseCollectorCandidate($this->dir.'/erasedata', $name),
					'malformed or legacy names must not be parsed as cleanup jobs');
	}

	private function writeCleanupCollectorManifest($oldHash, $newHash, $base, array $files, $type = 'list',
		$pid = '123', $unique = 'safeToken')
	{
		$entries = array();
		foreach($files as $file)
			$entries[] = $this->cleanupEntry($file);
		$contents = ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $newHash,
			'0123456789abcdef0123456789abcdef', $oldHash.'-started-1787587200', $base, $entries);
		$tmp = $this->dir.'/erasedata/'.$oldHash.'.cleanup.'.$pid.'.'.$unique.'.tmp';
		file_put_contents($tmp, $contents);
		if($type === 'tmp')
			return($tmp);
		$list = substr($tmp, 0, -4).'.list';
		$handle = fopen($list, 'x');
		$this->assertTrue(is_resource($handle), 'the committed cleanup fixture must create its token exclusively');
		if(is_resource($handle))
			fclose($handle);
		return($tmp);
	}

	public function testCleanupDeletesMatchingOriginalFile()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/Films';
		@mkdir($base, 0777, true);
		$old = $base.'/old-film.mkv';
		$new = $base.'/new-film.mkv';
		$neighbor = $base.'/another-film.mkv';
		$personal = $base.'/personal.txt';
		foreach(array($old => 'old', $new => 'new', $neighbor => 'neighbor', $personal => 'personal') as $path => $bytes)
			file_put_contents($path, $bytes);
		$this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'the cleanup collector must finish without a PHP error');
		$this->assertTrue(!file_exists($old), 'a matching persisted obsolete object must be deleted');
		$this->assertTrue(is_file($new) && is_file($neighbor) && is_file($personal) && is_dir($base),
			'a cleanup job must preserve the shared base and unrelated files');
		$this->assertEquals(false, $this->onlyManifest($oldHash), 'the completed cleanup list must be consumed');
	}

	public function testCleanupMissingTargetCompletes()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/missing-base';
		@mkdir($base, 0777, true);
		$file = $base.'/old.bin';
		file_put_contents($file, 'old');
		$this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($file));
		unlink($file);
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'missing cleanup targets must not crash the collector');
		$this->assertEquals(false, $this->onlyManifest($oldHash), 'a missing obsolete object completes its durable obligation');
		$this->assertTrue(is_dir($base), 'the cleanup collector must not remove a shared empty base');
	}

	public function testCleanupRetryRecoversCapturedObsoleteFileBeforeConsumingJob()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/captured-cleanup-retry';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		$neighbor = $base.'/neighbor.bin';
		$marker = $this->dir.'/captured-cleanup-retry.triggered';
		file_put_contents($old, 'old');
		file_put_contents($neighbor, 'neighbor');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$token = substr($tmp, 0, -4).'.list';

		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'rename:1' => array('path' => $old, 'action' => 'exit',
				'at' => 'after', 'marker' => $marker),
		)));
		$this->assertEquals(0, $status, 'the scripted cleanup capture crash must exit at its boundary: '.$output);
		$this->assertTrue(is_file($marker) && !file_exists($old),
			'the first pass must stop only after moving the obsolete file out of its public name');
		$this->assertEquals(1, count(glob($base.'/.erasedata-entry-*')),
			'the interrupted pass must leave one discoverable captured obsolete file');
		$this->assertTrue(is_file($tmp) && is_file($token) && is_file($neighbor),
			'the interrupted pass must retain its exact job and unrelated neighbor');

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'the captured cleanup retry must exit normally: '.$output);
		$this->assertEquals(array(), glob($base.'/.erasedata-entry-*'),
			'the retry must reconcile the exact payload-side capture instead of stranding hidden data');
		$this->assertTrue(!file_exists($tmp) && !file_exists($token),
			'the retry may consume its job only after captured obsolete data is reconciled');
		$this->assertEquals('neighbor', is_file($neighbor) ? file_get_contents($neighbor) : null,
			'capture recovery must preserve unrelated files in the shared base');
	}

	public function testCleanupRetryProtectsSuccessorAliasToCapturedObsoleteFile()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/captured-successor-alias';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		$new = $base.'/new.bin';
		$neighbor = $base.'/neighbor.bin';
		$marker = $this->dir.'/captured-successor-alias.triggered';
		file_put_contents($old, 'old');
		file_put_contents($neighbor, 'neighbor');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$token = substr($tmp, 0, -4).'.list';

		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'rename:1' => array('path' => $old, 'action' => 'exit',
				'at' => 'after', 'marker' => $marker),
		)));
		$this->assertEquals(0, $status, 'the alias fixture must stop after OLD capture: '.$output);
		$captures = glob($base.'/.erasedata-entry-*');
		$this->assertEquals(1, count($captures), 'the interrupted cleanup must expose one exact capture for the retry fixture');
		$captureEntry = count($captures) === 1 ? $captures[0].'/entry' : '';
		$this->assertTrue($captureEntry !== '' && @symlink($captureEntry, $new),
			'the NEW successor fixture must physically alias the captured OLD entry');
		$owned = array('base' => $new, 'multi' => 0, 'files' => array($new));

		list($status, $output) = $this->runCollector(array(
			'val' => array($newHash),
			'owned' => $owned,
		));
		$this->assertEquals(0, $status, 'a captured successor alias retry must exit normally: '.$output);
		$this->assertEquals('old', is_file($new) ? file_get_contents($new) : null,
			'the retry must keep the successor alias usable while it protects the captured backing object');
		$this->assertEquals(1, count(glob($base.'/.erasedata-entry-*')),
			'the retry must retain the exact capture while NEW physically aliases it');
		$this->assertTrue(is_file($tmp) && is_file($token) && is_file($neighbor),
			'an aliased capture must retain its job and unrelated neighbor without partial consumption');

		$this->assertTrue(@unlink($new), 'the fixture must remove the successor alias before convergence');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'a retry after successor alias removal must exit normally: '.$output);
		$this->assertEquals(array(), glob($base.'/.erasedata-entry-*'),
			'the later retry must safely reconcile the no-longer-aliased capture');
		$this->assertTrue(!file_exists($tmp) && !file_exists($token),
			'the later retry may consume the job after the successor alias disappears');
		$this->assertEquals('neighbor', is_file($neighbor) ? file_get_contents($neighbor) : null,
			'alias protection and convergence must preserve the shared base neighbor');
	}

	public function testCleanupDifferentReplacementObjectSurvives()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/replacement-base';
		@mkdir($base, 0777, true);
		$file = $base.'/old.bin';
		file_put_contents($file, 'old');
		$this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($file));
		unlink($file);
		file_put_contents($file, 'replacement');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'replacement-object cleanup must not crash the collector');
		$this->assertEquals('replacement', file_get_contents($file), 'a replacement object at the obsolete name must survive');
		$this->assertEquals(false, $this->onlyManifest($oldHash), 'a confirmed replacement object completes the old obligation');
	}

	public function testCleanupCollectorReportsRecoveryReasonCategories()
	{
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$marker = '0123456789abcdef0123456789abcdef';
		$record = $oldHash.'-started-1787587200';
		$absent = $this->collectorResponse(true, false, array(''));
		$matching = $this->collectorResponse(true, false, array($newHash, $marker, $record));
		$cases = array(
			array('old RPC unknown', $this->collectorResponse(false, false, array()), $matching, 'rpc-unknown'),
			array('new RPC unknown', $absent, $this->collectorResponse(false, false, array()), 'rpc-unknown'),
			array('NEW missing', $absent, $this->collectorResponse(true, false, array('', $marker, $record)), 'generation-mismatch'),
			array('marker mismatch', $absent, $this->collectorResponse(true, false, array($newHash, str_repeat('f', 32), $record)), 'generation-mismatch'),
			array('replacement-record mismatch', $absent,
				$this->collectorResponse(true, false, array($newHash, $marker, $oldHash.'-stopped-1787587200')), 'generation-mismatch'),
		);
		foreach($cases as $case)
		{
			$this->reset();
			$base = $this->dir.'/generation-'.$case[0];
			@mkdir($base, 0777, true);
			$old = $base.'/old.bin';
			file_put_contents($old, 'old');
			$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old), 'tmp');
			$generation = $this->cleanupGenerationResponses($oldHash, $newHash, $case[1], $case[2]);
			list($status, $output) = $this->runCollector(array(
				'generation' => $generation, 'debug' => true));
			$this->assertEquals(0, $status, 'the collector must exit normally for '.$case[0].': '.$output);
			$this->assertTrue(is_file($tmp) && is_file($old),
				'a retryable '.$case[0].' must retain both the tmp and obsolete target');
			$logs = $this->collectorLogs($output);
			$matches = array_filter($logs, function($line) use ($oldHash, $case) {
				return($line === 'erasedata: cleanup retained '.$oldHash.' '.$case[3]);
			});
			$this->assertEquals(1, count($matches),
				'a '.$case[0].' retry must retain its actual '.$case[3].' reason exactly once');
		}
	}

	public function testCleanupUnlinkFailureRetries()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/unlink-retry';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$token = substr($tmp, 0, -4).'.list';
		list($status, $output) = $this->runCollector(array(
			'cleanupUnlinkFail' => $old, 'debug' => true));
		$this->assertEquals(0, $status, 'an injected cleanup unlink failure must not crash the collector: '.$output);
		$this->assertTrue(is_file($old) && is_file($tmp) && is_file($token),
			'a cleanup unlink failure must retain the target, manifest, and commit token');
		$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' unlink-failure', $this->collectorLogs($output), true),
			'a cleanup unlink failure must retain the unlink-failure reason');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'the retry after a cleanup unlink failure must not crash: '.$output);
		$this->assertTrue(!file_exists($old) && !file_exists($tmp) && !file_exists($token),
			'a successful retry must consume the manifest first and then its exact token');
	}

	public function testCleanupRetainedLogReportsOneReasonPerJobPerRun()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/two-jobs';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$first = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$second = dirname($first).'/'.$oldHash.'.cleanup.124.otherToken.tmp';
		copy($first, $second);
		$secondToken = substr($second, 0, -4).'.list';
		$handle = fopen($secondToken, 'x');
		$this->assertTrue(is_resource($handle), 'the duplicate generation fixture must create a second token exclusively');
		if(is_resource($handle)) fclose($handle);
		list($status, $output) = $this->runCollector(array('debug' => true));
		$this->assertEquals(0, $status, 'two ambiguous cleanup jobs must not crash the collector: '.$output);
		$expected = 'erasedata: cleanup retained '.$oldHash.' generation-mismatch';
		$this->assertEquals(2, count(array_filter($this->collectorLogs($output), function($line) use ($expected) {
			return($line === $expected);
		})), 'two exact cleanup jobs for one hash must each retain one observable reason');
		$this->assertTrue(is_file($first) && is_file($second) && is_file($secondToken) && is_file($old),
			'ambiguous jobs must retain both manifests, tokens, and the obsolete target');

		$this->reset();
		$base = $this->dir.'/one-job';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array(
			'cleanupUnlinkFail' => $old, 'debug' => true));
		$this->assertEquals(0, $status, 'one retained committed job must not crash the collector: '.$output);
		$expected = 'erasedata: cleanup retained '.$oldHash.' unlink-failure';
		$this->assertEquals(1, count(array_filter($this->collectorLogs($output), function($line) use ($expected) {
			return($line === $expected);
		})), 'one exact cleanup job must retain at most one reason in a collector run');
	}

	public function testCleanupCollectorRetainsUnreadableSuccessorPathsAndProtectsPhysicalAlias()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/successor-unreadable';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$list = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));

		list($status, $output) = $this->runCollector(array('val' => array($newHash), 'debug' => true));
		$this->assertEquals(0, $status, 'a present successor with unreadable paths must not crash the collector: '.$output);
		$this->assertTrue(is_file($old) && is_file($list),
			'a present successor with unreadable path metadata must retain the cleanup obligation');
		$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' rpc-unknown', $this->collectorLogs($output), true),
			'unreadable present-successor paths must retain the rpc-unknown category');

		$this->reset();
		$base = $this->dir.'/successor-alias';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		$new = $base.'/new.bin';
		file_put_contents($old, 'shared-generation-object');
		$this->assertTrue(@link($old, $new), 'the physical-alias fixture needs a hard link');
		$list = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$owned = array('base' => $new, 'multi' => 0, 'files' => array($new));

		list($status, $output) = $this->runCollector(array('val' => array($newHash), 'owned' => $owned));
		$this->assertEquals(0, $status, 'a physical successor alias must not crash the collector: '.$output);
		$this->assertEquals('shared-generation-object', is_file($old) ? file_get_contents($old) : null,
			'a successor hard-link alias must protect the obsolete path from unlink');
		$this->assertEquals(false, $this->onlyManifest($oldHash),
			'a successor-owned alias can complete the old obligation without deleting shared data');
	}

	public function testCleanupCollectorUsesExactAliasesForStructuralPaths()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/successor-descendant';
		@mkdir($base, 0777, true);
		$old = $base.'/node';
		$new = $base.'/node/new.bin';
		file_put_contents($old, 'old blocker');
		$this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$owned = array('base' => $base, 'multi' => 1, 'files' => array($new));

		list($status, $output) = $this->runCollector(array('val' => array($newHash), 'owned' => $owned));
		$this->assertEquals(0, $status, 'a missing successor descendant must not crash the collector: '.$output);
		$this->assertTrue(!file_exists($old),
			'an OLD regular file that blocks a missing NEW descendant is deleted, not mistaken for an alias');
		$this->assertEquals(false, $this->onlyManifest($oldHash),
			'the exact obsolete obligation completes only after the blocker is removed');

		$this->reset();
		$base = $this->dir.'/successor-parent';
		@mkdir($base.'/node', 0777, true);
		$old = $base.'/node/old.bin';
		$new = $base.'/node';
		file_put_contents($old, 'old child');
		$list = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$owned = array('base' => $new, 'multi' => 0, 'files' => array($new));

		list($status, $output) = $this->runCollector(array(
			'val' => array($newHash),
			'owned' => $owned, 'debug' => true));
		$this->assertEquals(0, $status, 'an existing successor directory must not crash the collector: '.$output);
		$this->assertTrue(is_file($old) && is_file($list),
			'an existing directory cannot prove the NEW exact-file target and retains the obligation');
		$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' unsafe-path',
			$this->collectorLogs($output), true), 'a special successor target is retained as unsafe');
	}

	public function testCleanupCollectorRetainsDanglingSuccessorAndProtectsFileAliases()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/successor-dangling';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		$new = $base.'/new.bin';
		file_put_contents($old, 'old');
		symlink($base.'/missing.bin', $new);
		$list = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$owned = array('base' => $new, 'multi' => 0, 'files' => array($new));

		list($status, $output) = $this->runCollector(array(
			'val' => array($newHash),
			'owned' => $owned, 'debug' => true));
		$this->assertEquals(0, $status, 'a dangling successor alias must not crash the collector: '.$output);
		$this->assertTrue(is_file($old) && is_file($list),
			'an unresolved successor identity retains both data and the exact cleanup job');
		$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' unsafe-path',
			$this->collectorLogs($output), true), 'unresolved successor identity is visibly unsafe');

		foreach(array('symlink', 'case-hardlink') as $kind)
		{
			$this->reset();
			$base = $this->dir.'/successor-'.$kind;
			@mkdir($base, 0777, true);
			$old = $base.'/old.bin';
			$new = $kind === 'case-hardlink' ? $base.'/OLD.BIN' : $base.'/new.bin';
			file_put_contents($old, 'shared alias');
			if($kind === 'symlink')
				$this->assertTrue(@symlink($old, $new), 'the successor symlink fixture must be created');
			else
				$this->assertTrue(@link($old, $new), 'the case-different hard-link fixture must be created');
			$this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
			$owned = array('base' => $new, 'multi' => 0, 'files' => array($new));

			list($status, $output) = $this->runCollector(array('val' => array($newHash), 'owned' => $owned));
			$this->assertEquals(0, $status, $kind.' successor alias must not crash the collector: '.$output);
			$this->assertEquals('shared alias', is_file($old) ? file_get_contents($old) : null,
				$kind.' resolves to the same exact ordinary file and protects it');
			$this->assertEquals(false, $this->onlyManifest($oldHash),
				$kind.' exact alias safely completes the old obligation');
		}
	}

	public function testCleanupCollectorFiltersStoppedMixedPaddingPathsFromMetainfo()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/stopped-mixed-padding';
		@mkdir($base, 0777, true);
		$old = $base.'/padding.bin';
		$real = $base.'/real.bin';
		file_put_contents($old, 'stale-real-file');
		file_put_contents($real, 'successor-real-file');
		$this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$owned = array('base' => $base, 'multi' => 1, 'files' => array($old, $real));
		$successor = array(
			'source' => array('hash' => $newHash, 'info' => array(
				'name' => 'stopped-mixed-padding',
				'files' => array(
					array('path' => array('padding.bin'), 'length' => 1, 'attr' => 'p'),
					array('path' => array('real.bin'), 'length' => 1),
				),
			)),
			'frozen' => array('ok' => true, 'fault' => false, 'val' => array('', 1, '', '')),
			'stored' => array('ok' => true, 'fault' => false,
				'val' => array($base, 1, 'padding.bin', 'real.bin')),
		);

		list($status, $output) = $this->runCollector(array(
			'val' => array($newHash),
			'owned' => $owned, 'successorOverride' => $successor));
		$this->assertEquals(0, $status, 'stopped mixed padding cleanup must not crash: '.$output);
		$this->assertTrue(!file_exists($old),
			'a stopped successor padding name cannot protect an ordinary OLD physical file');
		$this->assertEquals('successor-real-file', is_file($real) ? file_get_contents($real) : null,
			'the stopped successor ordinary file remains owned and untouched');
		$this->assertEquals(false, $this->onlyManifest($oldHash, 'tmp'),
			'the mixed-padding obligation completes after deleting OLD');
		$this->assertEquals(false, $this->onlyManifest($oldHash, 'list'),
			'the mixed-padding commit token is consumed with its manifest');
	}

	public function testCleanupCollectorTreatsAllPaddingSuccessorAsNoPhysicalFiles()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/stopped-all-padding';
		@mkdir($base, 0777, true);
		$old = $base.'/padding.bin';
		file_put_contents($old, 'stale-real-file');
		$this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$owned = array('base' => $base, 'multi' => 1, 'files' => array($old));
		$successor = array(
			'source' => array('hash' => $newHash, 'info' => array(
				'name' => 'stopped-all-padding',
				'files' => array(array('path' => array('padding.bin'), 'length' => 1, 'attr' => 'p')),
			)),
			'frozen' => array('ok' => true, 'fault' => false, 'val' => array('', 1, '')),
			'stored' => array('ok' => true, 'fault' => false, 'val' => array($base, 1, 'padding.bin')),
		);

		list($status, $output) = $this->runCollector(array(
			'val' => array($newHash),
			'owned' => $owned, 'successorOverride' => $successor));
		$this->assertEquals(0, $status, 'all-padding cleanup must not crash: '.$output);
		$this->assertTrue(!file_exists($old),
			'an all-padding successor owns no physical file that can protect OLD');
		$this->assertEquals(false, $this->onlyManifest($oldHash, 'tmp'),
			'the all-padding obligation completes after deleting OLD');
		$this->assertEquals(false, $this->onlyManifest($oldHash, 'list'),
			'the all-padding token is consumed with its manifest');
	}

	public function testCleanupCollectorKeepsDuplicatePaddingMaskRowsOrderIndependent()
	{
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		foreach(array('padding-first', 'ordinary-first') as $order)
		{
			$this->reset();
			$base = $this->dir.'/duplicate-mask-'.$order;
			@mkdir($base, 0777, true);
			$shared = $base.'/shared.bin';
			file_put_contents($shared, 'successor-owned');
			$this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($shared));
			$padding = array('path' => array('shared.bin'), 'length' => 1, 'attr' => 'p');
			$ordinary = array('path' => array('shared.bin'), 'length' => 1);
			$rows = $order === 'padding-first'
				? array($padding, $ordinary) : array($ordinary, $padding);
			$owned = array('base' => $base, 'multi' => 1, 'files' => array($shared));
			$successor = array(
				'source' => array('hash' => $newHash,
					'info' => array('name' => basename($base), 'files' => $rows)),
				'frozen' => array('ok' => true, 'fault' => false, 'val' => array('', 1, '', '')),
				'stored' => array('ok' => true, 'fault' => false,
					'val' => array($base, 1, 'shared.bin', 'shared.bin')),
			);

			list($status, $output) = $this->runCollector(array(
				'val' => array($newHash),
				'owned' => $owned, 'debug' => true, 'successorOverride' => $successor));
			$this->assertEquals(0, $status, $order.' duplicate mask rows must not crash: '.$output);
			$this->assertEquals('successor-owned', is_file($shared) ? file_get_contents($shared) : null,
				$order.' keeps a path owned when any aligned row is ordinary');
			$this->assertEquals(false, $this->onlyManifest($oldHash, 'tmp'),
				$order.' duplicate rows still complete the exact obsolete obligation');
			$this->assertEquals(false, $this->onlyManifest($oldHash, 'list'),
				$order.' duplicate rows consume the matching token');
		}
	}

	public function testCleanupCollectorRejectsUnprovedSuccessorMetainfoAlignment()
	{
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$cases = array(
			'hash mismatch' => array('source' => array('hash' => $this->hash('C'), 'info' => array(
				'name' => 'bundle', 'files' => array(array('path' => array('new.bin'), 'length' => 1))))),
			'unavailable source' => array('source' => false),
			'file count mismatch' => array('source' => array('hash' => $newHash, 'info' => array(
				'name' => 'bundle', 'files' => array(
					array('path' => array('new.bin'), 'length' => 1),
					array('path' => array('extra.bin'), 'length' => 1),
				)))),
			'multi discriminator mismatch' => array('source' => array('hash' => $newHash,
				'info' => array('name' => 'new.bin', 'length' => 1))),
			'file order path mismatch' => array('source' => array('hash' => $newHash, 'info' => array(
				'name' => 'bundle', 'files' => array(array('path' => array('other.bin'), 'length' => 1))))),
		);
		foreach($cases as $label => $override)
		{
			$this->reset();
			$base = $this->dir.'/metainfo-mismatch-'.str_replace(' ', '-', $label);
			@mkdir($base, 0777, true);
			$old = $base.'/old.bin';
			$new = $base.'/new.bin';
			file_put_contents($old, 'old');
			file_put_contents($new, 'new');
			$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
			$token = substr($tmp, 0, -4).'.list';
			$owned = array('base' => $base, 'multi' => 1, 'files' => array($new));

			list($status, $output) = $this->runCollector(array(
				'val' => array($newHash),
				'owned' => $owned, 'debug' => true, 'successorOverride' => $override));
			$this->assertEquals(0, $status, $label.' must not crash the collector: '.$output);
			$this->assertTrue(is_file($old) && is_file($tmp) && is_file($token),
				$label.' retains OLD, manifest, and token when physical ownership cannot be proved');
			$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' rpc-unknown',
				$this->collectorLogs($output), true), $label.' is retained as successor uncertainty');
		}
	}

	public function testCleanupCollectorRetainsWhenMissingSuccessorBecomesAliasAtFinalGuard()
	{
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		foreach(array('missing-to-symlink', 'missing-to-hardlink') as $kind)
		{
			$this->reset();
			$base = $this->dir.'/'.$kind;
			@mkdir($base, 0777, true);
			$old = $base.'/old.bin';
			$new = $base.'/new.bin';
			file_put_contents($old, 'old');
			$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
			$token = substr($tmp, 0, -4).'.list';
			$owned = array('base' => $new, 'multi' => 0, 'files' => array($new));

			list($status, $output) = $this->runCollector(array(
				'val' => array($newHash),
				'owned' => $owned, 'debug' => true,
				'successorTransition' => array('kind' => $kind, 'old' => $old, 'new' => $new)));
			$this->assertEquals(0, $status, $kind.' must not crash the collector: '.$output);
			$this->assertTrue(is_file($old) && is_file($tmp) && is_file($token),
				$kind.' retains OLD and both artifacts after the successor observation changes');
			$this->assertTrue(is_link($new) || is_file($new),
				$kind.' transition executes at the final unlink seam');
			$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' unsafe-path',
				$this->collectorLogs($output), true), $kind.' is retained as changed successor identity');
		}
	}

	public function testCleanupCollectorRunsEveryRaceSeamBeforeDeletingOldFiles()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/batched-final-guard';
		@mkdir($base, 0777, true);
		$first = $base.'/first-old.bin';
		$second = $base.'/second-old.bin';
		$new = $base.'/new.bin';
		file_put_contents($first, 'first');
		file_put_contents($second, 'second');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($first, $second));
		$token = substr($tmp, 0, -4).'.list';
		$owned = array('base' => $new, 'multi' => 0, 'files' => array($new));

		list($status, $output) = $this->runCollector(array(
			'val' => array($newHash),
			'owned' => $owned, 'debug' => true, 'successorTransition' => array(
				'kind' => 'missing-to-hardlink', 'old' => $second, 'new' => $new, 'trigger' => $second)));
		$this->assertEquals(0, $status, 'a transition at the second cleanup seam must not crash: '.$output);
		$this->assertTrue(is_file($first) && is_file($second) && is_file($tmp) && is_file($token),
			'all OLD files and both artifacts remain when a later seam invalidates the successor snapshot');
		$this->assertTrue(is_file($new), 'the second cleanup seam executes before any OLD unlink');
		$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' unsafe-path',
			$this->collectorLogs($output), true), 'the batched final guard reports changed successor identity');
	}

	public function testCleanupCollectorRevalidatesSuccessorSnapshotOncePerBatch()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/linear-final-guard';
		@mkdir($base, 0777, true);
		$oldFiles = array();
		for($index = 0; $index < 4; $index++)
		{
			$oldFiles[] = $base.'/old-'.$index.'.bin';
			file_put_contents($oldFiles[$index], 'old-'.$index);
		}
		$newFiles = array();
		for($index = 0; $index < 3; $index++)
		{
			$newFiles[] = $base.'/new-'.$index.'.bin';
			file_put_contents($newFiles[$index], 'new-'.$index);
		}
		$this->writeCleanupCollectorManifest($oldHash, $newHash, $base, $oldFiles);
		$owned = array('base' => $base, 'multi' => 1, 'files' => $newFiles);
		$countFile = $this->dir.'/successor-observations.count';

		list($status, $output) = $this->runCollector(array(
			'val' => array($newHash),
			'owned' => $owned, 'successorObservationCountFile' => $countFile));
		$this->assertEquals(0, $status, 'linear successor revalidation must not crash: '.$output);
		$this->assertEquals(6, is_file($countFile) ? count(file($countFile)) : 0,
			'three successor paths are observed once initially and once after all four OLD seams');
		foreach($oldFiles as $old)
			$this->assertTrue(!file_exists($old), 'the stable non-alias OLD file is deleted after the batch guard');
		$this->assertEquals(false, $this->onlyManifest($oldHash, 'tmp'),
			'the stable multi-obsolete batch consumes its manifest');
		$this->assertEquals(false, $this->onlyManifest($oldHash, 'list'),
			'the stable multi-obsolete batch consumes its token');
	}

	public function testCleanupCollectorRetainsWhenAliasChangesBeforeCompletion()
	{
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		foreach(array('alias-to-distinct', 'alias-to-missing') as $kind)
		{
			$this->reset();
			$base = $this->dir.'/'.$kind;
			@mkdir($base, 0777, true);
			$old = $base.'/old.bin';
			$new = $base.'/new.bin';
			file_put_contents($old, 'old');
			$this->assertTrue(@link($old, $new), $kind.' starts from one physical successor alias');
			$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
			$token = substr($tmp, 0, -4).'.list';
			$owned = array('base' => $new, 'multi' => 0, 'files' => array($new));

			list($status, $output) = $this->runCollector(array(
				'val' => array($newHash),
				'owned' => $owned, 'debug' => true,
				'successorTransition' => array('kind' => $kind, 'old' => $old, 'new' => $new)));
			$this->assertEquals(0, $status, $kind.' must not crash the collector: '.$output);
			$this->assertTrue(is_file($old) && is_file($tmp) && is_file($token),
				$kind.' retains OLD and both artifacts instead of completing from a stale alias index');
			$this->assertTrue($kind === 'alias-to-distinct' ? is_file($new) && file_get_contents($new) === 'distinct'
				: !file_exists($new) && !is_link($new), $kind.' transition executes before alias completion');
			$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' unsafe-path',
				$this->collectorLogs($output), true), $kind.' is retained as changed successor identity');
		}
	}

	public function testCleanupCollectorRetainsUnresolvedIdentityAndRetriesNestedParents()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/unresolved-identity';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$list = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		unlink($old);
		symlink($base.'/missing-target.bin', $old);

		list($status, $output) = $this->runCollector(array('debug' => true));
		$this->assertEquals(0, $status, 'an unresolved cleanup identity must not crash the collector: '.$output);
		$this->assertTrue(is_link($old) && is_file($list),
			'an unresolved existing cleanup target must remain together with its manifest');
		$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' unsafe-path', $this->collectorLogs($output), true),
			'an unresolved cleanup identity must retain the unsafe-path reason');

		$this->reset();
		$base = $this->dir.'/nested-cleanup';
		$nested = $base.'/one/two';
		@mkdir($nested, 0777, true);
		$old = $nested.'/old.bin';
		file_put_contents($old, 'old');
		$list = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));

		list($status, $output) = $this->runCollector(array(
			'rmdirFail' => $nested, 'debug' => true));
		$this->assertEquals(0, $status, 'an injected cleanup rmdir failure must not crash the collector: '.$output);
		$this->assertTrue(!file_exists($old) && is_file($list),
			'a cleanup rmdir failure must retain the durable list after deleting the exact target');
		$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' rmdir-failure', $this->collectorLogs($output), true),
			'a cleanup rmdir failure must retain the rmdir-failure reason');

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'the cleanup nested-parent retry must exit normally: '.$output);
		$this->assertTrue(!file_exists($nested) && !file_exists($base.'/one') && is_dir($base),
			'a retry must remove empty target-derived parents deepest-first and never remove the shared base');
		$this->assertEquals(false, $this->onlyManifest($oldHash),
			'a cleanup list is consumed only after all target-derived parents complete');
		$this->assertEquals(array(), glob($this->dir.'/.erasedata-rmdir-*'),
			'the cleanup branch must not leave a reservation for the shared base');
	}

	public function testCleanupCollectorIsolatesMalformedArtifactAndRejectsInvalidTarget()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/malformed-cleanup';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$list = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$malformed = $this->dir.'/erasedata/'.$oldHash.'.cleanup.bad.safeToken.tmp';
		file_put_contents($malformed, 'foreign');

		list($status, $output) = $this->runCollector(array('debug' => true));
		$this->assertEquals(0, $status, 'a malformed same-hash cleanup artifact must not crash the collector: '.$output);
		$this->assertTrue(!file_exists($old) && !file_exists($list) && is_file($malformed),
			'a malformed cleanup artifact on another stem must not block a valid committed generation');
		$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' generation-mismatch', $this->collectorLogs($output), true),
			'a malformed same-hash cleanup artifact must retain its own generation-mismatch reason');

		$this->reset();
		$otherHash = $this->hash('C');
		$base = $this->dir.'/invalid-target';
		@mkdir($base, 0777, true);
		$first = $base.'/first.bin';
		$second = $base.'/second.bin';
		file_put_contents($first, 'first');
		file_put_contents($second, 'second');
		$firstList = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($first));
		$secondList = $this->writeCleanupCollectorManifest($otherHash, $newHash, $base, array($second));

		list($status, $output) = $this->runCollector(array(
			'onlyHash' => 'not-a-valid-hash', 'debug' => true));
		$this->assertEquals(0, $status, 'an invalid targeted hash must exit without a PHP error: '.$output);
		$this->assertTrue(is_file($first) && is_file($second) && is_file($firstList) && is_file($secondList),
			'an invalid targeted hash must reject before any broad cleanup scan');
	}

	public function testCleanupSameStemMalformedArtifactRetainsOnlyThatGeneration()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/same-stem-malformed';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$token = substr($tmp, 0, -4).'.list';
		$malformed = substr($tmp, 0, -4).'.unknown';
		file_put_contents($malformed, 'foreign');

		list($status, $output) = $this->runCollector(array('debug' => true));
		$this->assertEquals(0, $status, 'a same-stem malformed artifact must not crash collection: '.$output);
		$this->assertTrue(is_file($tmp) && is_file($token) && is_file($malformed) && is_file($old),
			'a malformed artifact bound to the exact stem must retain only that generation');
		$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' generation-mismatch', $this->collectorLogs($output), true),
			'a same-stem malformed artifact must report the scoped generation-mismatch reason');
	}

	public function testCollectorDoesNotPromoteV3UnderAnUntaggedTmpName()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/untagged-v3';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$tagged = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old), 'tmp');
		$untagged = $this->dir.'/erasedata/'.$oldHash.'.123.safeToken.tmp';
		rename($tagged, $untagged);

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'a v3 manifest under an untagged tmp name must not crash collection: '.$output);
		$this->assertTrue(is_file($untagged) && !file_exists(substr($untagged, 0, -4).'.list') && is_file($old),
			'a v3 manifest under an untagged tmp name must remain untouched instead of entering legacy promotion');
	}

	public function testCleanupNeverReservesOrRemovesSharedBase()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/shared-base-only';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$list = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));

		list($status, $output) = $this->runCollector(array('rmdirFail' => $base));
		$this->assertEquals(0, $status, 'a shared-base rmdir seam must not crash cleanup collection: '.$output);
		$this->assertTrue(!file_exists($old) && !file_exists($list) && is_dir($base),
			'cleanup must finish despite a base rmdir failure seam because base is never a cleanup target');
		$this->assertEquals(array(), glob($this->dir.'/.erasedata-rmdir-*'),
			'cleanup must never create a reservation for the shared base');
	}

	public function testCleanupCompletionWaitsForExactManifestConsumption()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/consume-race';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$token = substr($tmp, 0, -4).'.list';
		$foreign = $this->dir.'/foreign-list';
		file_put_contents($foreign, 'foreign-list');

		list($status, $output) = $this->runCollector(array(
			'swap' => array($tmp, $foreign, 'rename'), 'debug' => true));
		$this->assertEquals(0, $status, 'a manifest swap during cleanup consumption must not crash the collector: '.$output);
		$this->assertEquals('foreign-list', is_file($tmp) ? file_get_contents($tmp) : null,
			'a replacement cleanup manifest must survive the final exact-consumption check');
		$this->assertTrue(is_file($token), 'a tmp swap must retain the paired commit token');
		$this->assertTrue(!in_array('erasedata: cleanup complete '.$oldHash, $this->collectorLogs($output), true),
			'cleanup completion must not be logged before exact manifest consumption succeeds');
		$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' unreadable-manifest', $this->collectorLogs($output), true),
			'a failed final manifest consumption must retain one stable unreadable-manifest reason');
	}

	public function testCleanupCompletionRejectsSameInodeManifestRewrite()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/consume-rewrite';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$token = substr($tmp, 0, -4).'.list';
		$before = lstat($tmp);
		$foreign = $this->dir.'/same-inode-foreign-list';
		file_put_contents($foreign, 'foreign-list-bytes');

		list($status, $output) = $this->runCollector(array(
			'swap' => array($tmp, $foreign, 'rewrite'), 'debug' => true));
		$this->assertEquals(0, $status, 'a same-inode manifest rewrite must not crash the collector: '.$output);
		$after = @lstat($tmp);
		$this->assertTrue(is_array($after) && $before['dev'] === $after['dev'] && $before['ino'] === $after['ino']
			&& file_get_contents($tmp) === 'foreign-list-bytes',
			'a same-inode tmp rewrite must remain durable instead of being unlinked as the original job');
		$this->assertTrue(is_file($token), 'a same-inode tmp rewrite must retain the paired token');
		$this->assertTrue(is_file($old),
			'a same-inode manifest rewrite must block obsolete-target deletion before a fresh manifest read');
		$this->assertTrue(!in_array('erasedata: cleanup complete '.$oldHash, $this->collectorLogs($output), true),
			'a same-inode manifest rewrite must not produce a premature completion log');
		$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' unreadable-manifest', $this->collectorLogs($output), true),
			'a same-inode manifest rewrite must retain the unreadable-manifest reason');
	}

	public function testTaggedCleanupTmpRetainsOnOldPresentOrRpcUnknown()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$input = $this->cleanupJobInput($oldHash);
		$job = erasedataPrepareObsoleteCleanup($oldHash, $input['new_hash'], $input['marker'], $input['replacement_record'], $input['base'], $input['entries']);
		$tmp = $job['tmp_path'];
		erasedataReleaseObsoleteCleanupJob($job);
		rXMLRPCRequest::$responses['d.hash'] = array('run' => true, 'fault' => false, 'val' => array($oldHash));
		$this->assertEquals(ERASEDATA_CLEANUP_RETRY, erasedataRecoverObsoleteCleanup($oldHash, $input['new_hash'], $input['marker'], $input['replacement_record']),
			'a present predecessor must retain a cleanup tmp');
		$this->assertTrue(is_file($tmp), 'a present predecessor must not promote or delete the staged job');
		rXMLRPCRequest::$responses['d.hash'] = array('run' => false, 'fault' => false, 'val' => array());
		$this->assertEquals(ERASEDATA_CLEANUP_RETRY, erasedataRecoverObsoleteCleanup($oldHash, $input['new_hash'], $input['marker'], $input['replacement_record']),
			'an uncertain predecessor probe must retain a cleanup tmp');
	}

	public function testDowngradeTaggedNamesNeverDispatchTheWrongManifestOperation()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/downgrade-base';
		@mkdir($base, 0777, true);
		$v3File = $base.'/v3.bin';
		$v2File = $base.'/v2.bin';
		file_put_contents($v3File, 'v3');
		file_put_contents($v2File, 'v2');
		$v3 = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($v3File));
		$untagged = $this->dir.'/erasedata/'.$oldHash.'.123.safeToken.list';
		rename($v3, $untagged);
		$this->writeManifestLines($oldHash.'.cleanup.124.safeToken.list', array($v2File), $v2File, 0, 1);
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'operation-mismatched manifests must not crash the collector');
		$this->assertTrue(is_file($v3File) && is_file($v2File), 'v3 under an untagged name and v2 under a tagged name must not be consumed');
		$this->assertTrue(is_file($untagged) && is_file($this->dir.'/erasedata/'.$oldHash.'.cleanup.124.safeToken.list'),
			'operation-mismatched artifacts must remain durable for a compatible collector');
	}

	public function testMatchingCleanupListIsDurableCommitProof()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$input = $this->cleanupJobInput($oldHash);
		$job = erasedataPrepareObsoleteCleanup($oldHash, $input['new_hash'], $input['marker'], $input['replacement_record'], $input['base'], $input['entries']);
		$tmp = $job['tmp_path'];
		$list = $job['list_path'];
		$this->assertTrue(erasedataPublishObsoleteCleanup($job), 'the durable token fixture must publish');
		@chmod($list, 0600);
		$this->assertEquals(ERASEDATA_CLEANUP_READY, erasedataRecoverObsoleteCleanup($oldHash, $input['new_hash'], $input['marker'], $input['replacement_record']),
			'a matching zero-byte token must prove commit without successor markers');
		$this->assertEquals(0666, $this->modeOf($list),
			'recovery must repair the shared mode on an existing exact token');
		$this->assertEquals(ERASEDATA_CLEANUP_READY, erasedataRecoverObsoleteCleanup($oldHash, $input['new_hash'], $input['marker'], $input['replacement_record']),
			'repeated recovery of a committed tmp-plus-token state must remain READY');
		$this->assertTrue(is_file($tmp) && is_file($list) && filesize($list) === 0,
			'a committed cleanup generation must retain the strict tmp and its zero-byte token');
	}

	public function testCleanupGenerationIsolatesMalformedAndOperationMismatchedArtifacts()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$input = $this->cleanupJobInput($oldHash);
		$contents = $this->cleanupWriterContents($oldHash, $input['new_hash']);
		file_put_contents($this->dir.'/erasedata/'.$oldHash.'.cleanup.bad.safeToken.tmp', $contents);
		$this->writeManifestLines($oldHash.'.cleanup.123.safeToken.tmp', array($input['entries'][0]['path']),
			$input['entries'][0]['path'], 0, 1);
		$this->assertEquals(ERASEDATA_CLEANUP_NONE, erasedataRecoverObsoleteCleanup($oldHash, $input['new_hash'],
			$input['marker'], $input['replacement_record']),
			'a malformed or operation-mismatched artifact on another stem must not become this generation');
		$this->assertEquals(ERASEDATA_CLEANUP_NONE, erasedataCancelObsoleteCleanupGeneration($oldHash, $input['new_hash'],
			$input['marker'], $input['replacement_record']),
			'a malformed or operation-mismatched artifact on another stem must not block cancellation');
	}

	public function testCleanupGenerationIsolatesValidMismatchedTransaction()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$input = $this->cleanupJobInput($oldHash);
		$path = $this->dir.'/erasedata/'.$oldHash.'.cleanup.123.mismatchToken.tmp';
		file_put_contents($path, $this->cleanupWriterContents($oldHash, $this->hash('C')));
		$own = $this->dir.'/erasedata/'.$oldHash.'.cleanup.124.ownToken.tmp';
		file_put_contents($own, $this->cleanupWriterContents($oldHash, $input['new_hash']));
		$this->configureMatchingCleanupRecovery($oldHash, $input);
		$this->assertEquals(ERASEDATA_CLEANUP_READY, erasedataRecoverObsoleteCleanup($oldHash, $input['new_hash'],
			$input['marker'], $input['replacement_record']),
			'a valid tagged cleanup artifact for another successor must not block this transaction');
		$this->assertTrue(is_file($path), 'a mismatched valid cleanup transaction must remain untouched');
		$this->assertTrue(is_file(substr($own, 0, -4).'.list'), 'the matching transaction must receive its own token');
	}

	public function testCleanupGenerationLeavesUntaggedV3OutsideTaggedTransactions()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$input = $this->cleanupJobInput($oldHash);
		file_put_contents($this->dir.'/erasedata/'.$oldHash.'.123.safeToken.tmp',
			$this->cleanupWriterContents($oldHash, $input['new_hash']));
		$this->assertEquals(ERASEDATA_CLEANUP_NONE, erasedataRecoverObsoleteCleanup($oldHash, $input['new_hash'],
			$input['marker'], $input['replacement_record']), 'an untagged v3 name must not become another tagged generation');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$oldHash.'.123.safeToken.tmp'),
			'an untagged v3 artifact must remain untouched for downgrade safety');
	}

	public function testCleanupDottedStemMalformedSuffixScopesOnlyItsExactGeneration()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/dotted-same-stem';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$unique = '69cfed.12345678';
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old), 'list', '345', $unique);
		$token = substr($tmp, 0, -4).'.list';
		$malformed = dirname($tmp).'/'.$oldHash.'.cleanup.345.'.$unique.'.unknown';
		file_put_contents($malformed, 'foreign suffix');

		list($status, $output) = $this->runCollector(array('debug' => true));
		$this->assertEquals(0, $status, 'a production-shaped dotted malformed suffix must not crash collection: '.$output);
		$this->assertTrue(is_file($old) && is_file($tmp) && is_file($token) && is_file($malformed),
			'a malformed final suffix on the exact dotted stem must retain only that durable generation');

		$this->reset();
		$base = $this->dir.'/dotted-other-stem';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old), 'list', '345', $unique);
		$token = substr($tmp, 0, -4).'.list';
		$malformed = dirname($tmp).'/'.$oldHash.'.cleanup.345.69cfed.87654321.unknown';
		file_put_contents($malformed, 'foreign suffix');

		list($status, $output) = $this->runCollector(array('debug' => true));
		$this->assertEquals(0, $status, 'a different dotted malformed stem must not crash collection: '.$output);
		$this->assertTrue(!file_exists($old) && !file_exists($tmp) && !file_exists($token) && is_file($malformed),
			'a malformed dotted sibling must not block a valid exact generation');
	}

	public function testCleanupCollectorAnalyzesPreparedGenerationsLinearly()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$generation = array(
			$oldHash => array('presence' => $this->collectorResponse(true, false, array($oldHash))),
		);
		$count = 5;
		$staged = array();
		for($i = 0; $i < $count; $i++)
		{
			$newHash = $this->hash(chr(ord('B') + $i));
			$base = $this->dir.'/linear-'.$i;
			@mkdir($base, 0777, true);
			$old = $base.'/old.bin';
			file_put_contents($old, 'old-'.$i);
			$staged[] = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old), 'tmp',
				(string)(400 + $i), '69cfed'.($i + 1).'.12345678');
		}
		$counter = $this->dir.'/cleanup-artifact-reads';
		list($status, $output) = $this->runCollector(array(
			'generation' => $generation, 'artifactReadCountFile' => $counter, 'debug' => true));
		$reads = is_file($counter) ? count(file($counter, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
		$this->assertEquals(0, $status, 'several prepared generations under one old hash must not crash: '.$output);
		$this->assertTrue($reads >= $count && $reads <= $count * 4,
			'exact artifact reads and decodes for prepared same-hash generations must remain linearly bounded');
		$this->assertEquals($count, count(array_filter($staged, 'is_file')),
			'an old-present recovery probe must retain every independently indexed prepared generation');
	}

	public function testCommittedCleanupRetainsOnSuccessorPresenceRpcUnknown()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/committed-successor-unknown';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$token = substr($tmp, 0, -4).'.list';
		$generation = array($newHash => array('presence' => $this->collectorResponse(false, false, array())));

		list($status, $output) = $this->runCollector(array(
			'generation' => $generation, 'debug' => true));
		$this->assertEquals(0, $status, 'a committed successor RPC uncertainty must not crash the collector: '.$output);
		$this->assertTrue(is_file($old) && is_file($tmp) && is_file($token),
			'a committed cleanup job must retain its target, strict manifest, and token while successor presence is unknown');
		$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' rpc-unknown', $this->collectorLogs($output), true),
			'a committed successor presence RPC uncertainty must keep its rpc-unknown reason');

		list($status, $output) = $this->runCollector(array('debug' => true));
		$this->assertEquals(0, $status, 'the successful successor retry must not crash the collector: '.$output);
		$this->assertTrue(!file_exists($old) && !file_exists($tmp) && !file_exists($token),
			'a successful successor retry must converge the previously committed cleanup job');
	}

	public function testCleanupCancellationReturnsNoneForAbsentAndRetryForPublishedList()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$input = $this->cleanupJobInput($oldHash);
		$this->assertEquals(ERASEDATA_CLEANUP_NONE, erasedataCancelObsoleteCleanupGeneration($oldHash, $input['new_hash'],
			$input['marker'], $input['replacement_record']), 'cancellation must be NONE when no exact generation exists');
		$job = erasedataPrepareObsoleteCleanup($oldHash, $input['new_hash'], $input['marker'], $input['replacement_record'], $input['base'], $input['entries']);
		$this->assertTrue(erasedataPublishObsoleteCleanup($job), 'the published-list cancellation fixture must commit');
		$this->assertEquals(ERASEDATA_CLEANUP_RETRY, erasedataCancelObsoleteCleanupGeneration($oldHash, $input['new_hash'],
			$input['marker'], $input['replacement_record']), 'cancellation must retain a durable published list');
	}

	public function testCleanupCancellationRetainsExactDuplicatePreparedGenerations()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$input = $this->cleanupJobInput($oldHash);
		$job = erasedataPrepareObsoleteCleanup($oldHash, $input['new_hash'], $input['marker'],
			$input['replacement_record'], $input['base'], $input['entries']);
		$first = $job['tmp_path'];
		erasedataReleaseObsoleteCleanupJob($job);
		$second = dirname($first).'/'.$oldHash.'.cleanup.124.duplicateToken.tmp';
		copy($first, $second);
		$this->assertEquals(ERASEDATA_CLEANUP_RETRY, erasedataCancelObsoleteCleanupGeneration($oldHash,
			$input['new_hash'], $input['marker'], $input['replacement_record']),
			'cancellation must retain exact duplicate prepared generations as scoped ambiguity');
		$this->assertTrue(is_file($first) && is_file($second),
			'cancellation must not remove either exact duplicate prepared generation');
	}

	public function testCleanupCancelRetainsTmpSwappedImmediatelyBeforeUnlink()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$job = $this->prepareCleanupJob($oldHash);
		$tmp = $job['tmp_path'];
		$replacement = $this->dir.'/replacement-tmp';
		$marker = $this->dir.'/cancel-tmp-swap.triggered';
		file_put_contents($replacement, 'replacement');
		// The sixth identity read of the staged manifest is the last one before
		// the unlink, so the swap lands inside the comparison-to-unlink window.
		$fixture = new ErasedataCollectorFixture(array(
			'entryIdentity:6' => array('path' => $tmp, 'action' => 'replace-entry',
				'backup' => $tmp.'.original', 'replacement' => $replacement,
				'marker' => $marker),
		));
		$this->assertEquals(false, erasedataCancelObsoleteCleanup($job, $fixture),
			'a tmp swapped after validation must not be unlinked');
		$this->assertTrue(is_file($marker),
			'the scripted swap reaches the final comparison-to-unlink boundary');
		$this->assertEquals('replacement', file_get_contents($tmp),
			'the replacement tmp must survive the cancellation race');
	}

	public function testCleanupCancellationRetainsUnreadablePreparedTmp()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$job = $this->prepareCleanupJob($oldHash);
		$tmp = $job['tmp_path'];
		file_put_contents($tmp, 'not a cleanup manifest');

		$this->assertEquals(false, erasedataCancelObsoleteCleanup($job),
			'cancellation must reject an unreadable prepared tmp instead of treating it as absent');
		$this->assertTrue(is_file($tmp),
			'cancellation must retain an unreadable prepared tmp for manual recovery or a compatible collector');
	}

	public function testTargetedCollectorTouchesOnlyRequestedHash()
	{
		$this->reset();
		$oldA = $this->hash('A');
		$oldC = $this->hash('C');
		$newHash = $this->hash('B');
		$base = $this->dir.'/targeted';
		@mkdir($base, 0777, true);
		$a = $base.'/a.bin';
		$c = $base.'/c.bin';
		file_put_contents($a, 'a');
		file_put_contents($c, 'c');
		$this->writeCleanupCollectorManifest($oldA, $newHash, $base, array($a));
		$this->writeCleanupCollectorManifest($oldC, $newHash, $base, array($c));
		list($status, $output) = $this->runCollector(array('onlyHash' => $oldA));
		$this->assertEquals(0, $status, 'targeted collector execution must succeed');
		$this->assertTrue(!file_exists($a) && is_file($c), 'a targeted collector must not touch another old hash');
		$this->assertEquals(false, $this->onlyManifest($oldA), 'the requested hash must be collected');
		$this->assertTrue(is_file($this->onlyManifest($oldC)), 'the non-requested hash must retain its manifest');
	}

	public function testPublicCollectorCallableTargetsOnlyItsSecondArgumentHash()
	{
		$this->reset();
		$oldA = $this->hash('A');
		$oldC = $this->hash('C');
		$newHash = $this->hash('B');
		$base = $this->dir.'/public-targeted';
		@mkdir($base, 0777, true);
		$a = $base.'/a.bin';
		$c = $base.'/c.bin';
		file_put_contents($a, 'a');
		file_put_contents($c, 'c');
		$this->writeCleanupCollectorManifest($oldA, $newHash, $base, array($a));
		$this->writeCleanupCollectorManifest($oldC, $newHash, $base, array($c));

		list($status, $output) = $this->runCollector(array(
			'publicCollectorHash' => $oldA,
		));
		$this->assertEquals(0, $status,
			'the approved public collector callable must accept a target hash as its second argument: '.$output);
		$this->assertTrue(!file_exists($a) && is_file($c),
			'the public collector callable must process only its requested hash');
		$this->assertEquals(false, $this->onlyManifest($oldA),
			'the public callable must consume the requested cleanup job');
		$this->assertTrue(is_file($this->onlyManifest($oldC)),
			'the public callable must retain every non-requested cleanup job');
	}

	public function testTargetedKickInvokesCollectorWithCanonicalHash()
	{
		$this->reset();
		$hash = $this->hash('A');
		rXMLRPCRequest::$responses['execute.nothrow'] = array('ok' => true, 'val' => array());
		$this->assertTrue(erasedataKickCollector(strtolower($hash)), 'a valid targeted kick must be confirmed by execute.nothrow');
		$this->assertEquals(array('execute.nothrow'), rXMLRPCRequest::$requested, 'the kick must make one detached RPC request');
		$command = rXMLRPCRequest::$commandCalls[0][0];
		$this->assertEquals(array('', 'sh', '-c', erasedataCollectorCommand('rutorrent', $hash).' </dev/null >/dev/null 2>&1 &'),
			$command->params, 'the kick must make the exact escaped detached collector request');
	}

	public function testCollectorScheduleCommandUsesSharedEscapedEntrypoint()
	{
		$this->reset();
		$settings = new ErasedataScheduleSettingsFake();
		$command = erasedataCollectorScheduleCommand($settings, 15, 'User Name');
		$script = realpath(__DIR__.'/../../../plugins/erasedata/update.php');
		$entrypoint = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg('User Name');
		$scheduled = getCmd('execute').'={sh,-c,'.$entrypoint.' &}';

		$this->assertEquals(array(array('erasedata', 15, $scheduled)), $settings->calls,
			'the shared schedule uses key erasedata, the supplied interval, and the escaped no-hash collector entrypoint');
		$this->assertEquals('schedule', $command->command, 'the settings schedule command is returned unchanged');
		$this->assertEquals(array('erasedatarutorrent', 'aligned', '15', $scheduled), $command->params,
			'the returned schedule preserves its key, interval, script path, and explicit user argument');
	}

	public function testCollectorCommandCanonicalizesOptionalHash()
	{
		$this->reset();
		$hash = $this->hash('A');
		$script = realpath(__DIR__.'/../../../plugins/erasedata/update.php');
		$current = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg('rutorrent');

		$this->assertEquals($current, erasedataCollectorCommand(),
			'the shared builder uses the current user when no explicit user is supplied');
		$this->assertEquals($current.' '.escapeshellarg($hash),
			erasedataCollectorCommand('rutorrent', strtolower($hash)),
			'the optional targeted hash is canonicalized and appended to the same collector entrypoint');
	}

	public function testCollectorSchedulePreservesEmptyCurrentUserArgument()
	{
		$this->reset();
		$settings = new ErasedataScheduleSettingsFake();
		$script = realpath(__DIR__.'/../../../plugins/erasedata/update.php');
		$entrypoint = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg('');
		$scheduled = getCmd('execute').'={sh,-c,'.$entrypoint.' &}';

		$command = erasedataCollectorScheduleCommand($settings, 15, '');

		$this->assertTrue($command instanceof rXMLRPCCommand,
			'the single-user empty login still produces a collector schedule command');
		$this->assertEquals(array(array('erasedata', 15, $scheduled)), $settings->calls,
			'the empty login is preserved as an explicit empty argv value for update.php');
	}

	public function testCleanupRecoveryWithoutArtifactCreatesMissingQueueAndReturnsNone()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$marker = str_repeat('c', 32);
		$record = $oldHash.'-started-1000';
		$this->removePath($this->dir.'/erasedata');

		$this->assertEquals(ERASEDATA_CLEANUP_NONE,
			erasedataRecoverObsoleteCleanup($oldHash, $newHash, $marker, $record),
			'absent queue and absent cleanup artifact are NONE, not a permanent retry');
		$this->assertTrue(is_dir($this->dir.'/erasedata'),
			'recovery creates the backend queue even when the erasedata UI plugin never initialized it');

		$this->removePath($this->dir.'/erasedata');
		$this->assertEquals(ERASEDATA_CLEANUP_NONE,
			erasedataCancelObsoleteCleanupGeneration($oldHash, $newHash, $marker, $record),
			'rollback cancellation has the same absent-queue NONE contract');
	}

	public function testMatchingCleanupTmpPromotesToList()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$input = $this->cleanupJobInput($oldHash);
		$job = erasedataPrepareObsoleteCleanup($oldHash, $input['new_hash'], $input['marker'],
			$input['replacement_record'], $input['base'], $input['entries']);
		$this->assertTrue(is_array($job), 'the matching cleanup fixture must stage a tmp job');
		$tmp = $job['tmp_path'];
		$list = $job['list_path'];
		erasedataReleaseObsoleteCleanupJob($job);
		rXMLRPCRequest::$responses['d.hash'] = array('byHash' => array(
			$oldHash => array('run' => true, 'fault' => true, 'val' => array(),
				'faultString' => 'invalid parameters: info-hash not found'),
			$input['new_hash'] => array('run' => true, 'fault' => false, 'val' => array($input['new_hash'], $input['marker'], $input['replacement_record'])),
		));
		$this->assertEquals(ERASEDATA_CLEANUP_READY, erasedataRecoverObsoleteCleanup($oldHash, $input['new_hash'],
			$input['marker'], $input['replacement_record']), 'an absent predecessor and exact successor generation must publish the staged tmp');
		$this->assertTrue(is_file($tmp) && is_file($list) && filesize($list) === 0,
			'recovery must retain the exact strict tmp and create only its zero-byte commit token');
	}

	public function testCleanupTokenPublicationKeepsTheStrictTmpAndRepairsSharedMode()
	{
		global $profileMask;
		$this->reset();
		$profileMask = 0671;
		$oldHash = $this->hash('A');
		$job = $this->prepareCleanupJob($oldHash);
		$tmp = $job['tmp_path'];
		$list = $job['list_path'];
		$this->assertTrue(erasedataPublishObsoleteCleanup($job),
			'publication must create the token without copying or renaming the strict manifest');
		$tmpStat = is_file($tmp) ? lstat($tmp) : false;
		$listStat = is_file($list) ? lstat($list) : false;
		$this->assertTrue(is_file($tmp) && is_file($list) && filesize($list) === 0
			&& is_array($tmpStat) && is_array($listStat) && $tmpStat['ino'] !== $listStat['ino'],
			'a committed generation must retain a distinct strict tmp and a zero-byte list token');
		$this->assertEquals(0660, $this->modeOf($list),
			'a newly-created cleanup token must receive the shared file mode');
	}

	public function testCleanupTokenPublicationLeavesEveryCollisionUntouched()
	{
		foreach(array('nonzero-file', 'directory', 'symlink', 'dangling-symlink') as $kind)
		{
			$this->reset();
			$oldHash = $this->hash('A');
			$job = $this->prepareCleanupJob($oldHash);
			$tmp = $job['tmp_path'];
			$list = $job['list_path'];
			$target = $this->dir.'/token-collision-'.$kind;
			if($kind === 'nonzero-file')
				file_put_contents($list, 'foreign-token');
			else if($kind === 'directory')
				mkdir($list);
			else
			{
				if($kind === 'symlink') file_put_contents($target, 'foreign-target');
				else $target .= '-missing';
				symlink($target, $list);
			}
			$this->assertEquals(false, erasedataPublishObsoleteCleanup($job),
				'a '.$kind.' list collision must never become a cleanup commit token');
			$survives = $kind === 'nonzero-file' ? @file_get_contents($list) === 'foreign-token'
				: ($kind === 'directory' ? is_dir($list) : (is_link($list) && @readlink($list) === $target));
			$this->assertTrue(is_file($tmp) && $survives,
				'a '.$kind.' collision must survive while the strict tmp remains retryable');
		}

		$this->reset();
		$oldHash = $this->hash('A');
		$job = $this->prepareCleanupJob($oldHash);
		$tmp = $job['tmp_path'];
		$list = $job['list_path'];
		$handle = fopen($list, 'x');
		$this->assertTrue(is_resource($handle), 'an existing zero-byte token fixture must be exclusive');
		if(is_resource($handle)) fclose($handle);
		$this->assertTrue(erasedataPublishObsoleteCleanup($job),
			'an existing exact zero-byte token must converge as a prior committed state');
		$this->assertTrue(is_file($tmp) && is_file($list) && filesize($list) === 0,
			'a prior zero-byte token must leave the strict tmp available for the collector');
	}

	public function testCleanupTokenPublicationRetainsSwappedTmpAndToken()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$job = $this->prepareCleanupJob($oldHash);
		$tmp = $job['tmp_path'];
		$list = $job['list_path'];
		$foreignTmp = $this->dir.'/foreign-publication-tmp';
		$marker = $this->dir.'/publication-tmp-swap.triggered';
		file_put_contents($foreignTmp, 'foreign-tmp');
		// The first token identity read happens immediately before the O_EXCL
		// token creation, so the staged manifest is swapped inside that window.
		$fixture = new ErasedataCollectorFixture(array(
			'entryIdentity:1' => array('path' => $list, 'action' => 'replace-entry',
				'target' => $tmp, 'backup' => $tmp.'.owned', 'replacement' => $foreignTmp,
				'marker' => $marker),
		));
		$this->assertEquals(false, erasedataPublishObsoleteCleanup($job, $fixture),
			'a tmp swapped before O_EXCL token creation must keep publication retryable');
		$this->assertTrue(is_file($marker),
			'the scripted tmp swap reaches the pre-token publication window');
		$this->assertEquals('foreign-tmp', file_get_contents($tmp),
			'a tmp replacement must survive publication validation');
		$this->assertTrue(!file_exists($list),
			'a swapped tmp must not create a token for a foreign transaction');

		$this->reset();
		$job = $this->prepareCleanupJob($oldHash);
		$tmp = $job['tmp_path'];
		$list = $job['list_path'];
		$foreignToken = $this->dir.'/foreign-publication-token';
		$marker = $this->dir.'/publication-token-swap.triggered';
		file_put_contents($foreignToken, 'foreign-token');
		// The second token identity read validates the token O_EXCL just
		// created, so the swap lands immediately after creation.
		$fixture = new ErasedataCollectorFixture(array(
			'entryIdentity:2' => array('path' => $list, 'action' => 'replace-entry',
				'backup' => $list.'.owned', 'replacement' => $foreignToken,
				'marker' => $marker),
		));
		$this->assertEquals(false, erasedataPublishObsoleteCleanup($job, $fixture),
			'a token swapped after O_EXCL creation must keep publication retryable');
		$this->assertTrue(is_file($marker),
			'the scripted token swap reaches the post-token publication window');
		$this->assertEquals('foreign-token', file_get_contents($list),
			'a token replacement must survive publication validation');
		$this->assertTrue(is_file($tmp),
			'a swapped token must retain the strict tmp for a later safe retry');
	}

	public function testCleanupTokenStateMachineConvergesAcrossInterruptedFinalization()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$job = $this->prepareCleanupJob($oldHash);
		$tmp = $job['tmp_path'];
		$list = $job['list_path'];
		// An occupied token name is observed before creation, so publication
		// stops while the queue still holds only the prepared manifest.
		$fixture = new ErasedataCollectorFixture(array(
			'entryIdentity:1' => array('path' => $list, 'result' => array('exists' => true)),
		));
		$this->assertEquals(false, erasedataPublishObsoleteCleanup($job, $fixture),
			'an interruption before token creation must retain only the prepared strict tmp');
		$this->assertTrue(is_file($tmp) && !file_exists($list),
			'a PREPARED cleanup state must not expose any partial commit token');

		$this->reset();
		$oldHash = $this->hash('A');
		$input = $this->cleanupJobInput($oldHash);
		$job = erasedataPrepareObsoleteCleanup($oldHash, $input['new_hash'], $input['marker'],
			$input['replacement_record'], $input['base'], $input['entries']);
		$tmp = $job['tmp_path'];
		$list = $job['list_path'];
		// The post-creation token validation cannot read the token it just
		// created, so publication stops with the committed pair on disk.
		$fixture = new ErasedataCollectorFixture(array(
			'entryIdentity:2' => array('path' => $list, 'result' => false),
		));
		$this->assertEquals(false, erasedataPublishObsoleteCleanup($job, $fixture),
			'an interruption immediately after token creation must retain the committed pair');
		$this->assertTrue(is_file($tmp) && is_file($list) && filesize($list) === 0,
			'the interrupted commit must have no partial-content publication artifact');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'a committed tmp-plus-token retry must not crash: '.$output);
		$this->assertTrue(!file_exists($tmp) && !file_exists($list),
			'a successful collector must unlink the manifest before its token');

		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/finalizing';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$list = substr($tmp, 0, -4).'.list';
		unlink($tmp);
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'a token-only finalization retry must not crash: '.$output);
		$this->assertTrue(!file_exists($list) && is_file($old),
			'a token-only FINALIZING state must converge without deleting an unproven target');
	}

	public function testCleanupTokenFinalizesAfterTmpUnlinkBeforeTokenUnlink()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/between-unlinks';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$token = substr($tmp, 0, -4).'.list';
		list($status, $output) = $this->runCollector(array(
			'commitTokenUnlinkFail' => $token, 'debug' => true));
		$this->assertEquals(0, $status, 'a token-unlink interruption must not crash collection: '.$output);
		$this->assertTrue(!file_exists($old) && !file_exists($tmp) && is_file($token),
			'the post-tmp-unlink crash window must retain only the exact FINALIZING token');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'a FINALIZING token retry must not crash: '.$output);
		$this->assertTrue(!file_exists($token),
			'a repeated collector run must converge the token-only finalization state');
	}

	public function testCleanupTokenOnlyFinalizationEmitsCompletionOnce()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/token-only-completion';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$token = substr($tmp, 0, -4).'.list';
		unlink($tmp);

		list($status, $output) = $this->runCollector(array('debug' => true));
		$this->assertEquals(0, $status, 'a token-only finalization must not crash the collector: '.$output);
		$this->assertTrue(!file_exists($token) && is_file($old),
			'a token-only finalization must consume only its exact commit token');
		$this->assertEquals(1, count(array_filter($this->collectorLogs($output), function($line) use ($oldHash) {
			return($line === 'erasedata: cleanup complete '.$oldHash);
		})), 'a successful token-only finalization must emit exactly one cleanup completion event');
	}

	public function testCleanupTokenSwapAndSameInodeTmpRewriteRetainTheJob()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/token-swap';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$list = substr($tmp, 0, -4).'.list';
		$foreign = $this->dir.'/foreign-token';
		file_put_contents($foreign, 'foreign-token');
		list($status, $output) = $this->runCollector(array(
			'swap' => array($list, $foreign, 'rename'), 'debug' => true));
		$this->assertEquals(0, $status, 'a token swap during cleanup must not crash: '.$output);
		$this->assertEquals('foreign-token', file_get_contents($list),
			'a swapped token must survive instead of being unlinked as the original commit proof');
		$this->assertTrue(is_file($tmp) && is_file($old),
			'a swapped token must retain the strict tmp and obsolete target for retry');
	}

	public function testCleanupRetainedReasonIsVisibleWithoutDebugAndQueueIndexesOnce()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/visible-retry';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old), 'tmp');
		$unknown = $this->collectorResponse(false, false, array());
		$generation = $this->cleanupGenerationResponses($oldHash, $newHash, $unknown,
			$this->collectorResponse(true, false, array($newHash, '0123456789abcdef0123456789abcdef', $oldHash.'-started-1787587200')));
		$otherOldHash = $this->hash('C');
		$otherNewHash = $this->hash('D');
		$otherBase = $this->dir.'/visible-retry-second';
		@mkdir($otherBase, 0777, true);
		$otherOld = $otherBase.'/old.bin';
		file_put_contents($otherOld, 'old');
		$otherTmp = $this->writeCleanupCollectorManifest($otherOldHash, $otherNewHash, $otherBase, array($otherOld), 'tmp');
		$generation[$otherOldHash] = array('presence' => $unknown);
		$indexCount = $this->dir.'/index-count';
		list($status, $output) = $this->runCollector(array(
			'generation' => $generation, 'captureLogs' => true, 'indexCountFile' => $indexCount));
		$this->assertEquals(0, $status, 'a default-visible retained cleanup job must not crash: '.$output);
		$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' rpc-unknown', $this->collectorLogs($output), true),
			'a retained cleanup reason must remain visible with shipped debug logging disabled');
		// Two enumerations of the queue directory per pass and no more: one to
		// resume captured entries, one to build the index. Rebuilding the index
		// per job -- the regression this pins -- would add one line per job.
		$this->assertEquals(array('1', '1'), is_file($indexCount) ? file($indexCount, FILE_IGNORE_NEW_LINES) : array(),
			'one collector invocation enumerates the queue directory exactly twice instead of rescanning it per job');
		$this->assertTrue(is_file($tmp) && is_file($otherTmp),
			'every rpc-unknown prepared generation must remain durable after the single indexed scan');
	}

	public function testCleanupLeavesNonemptyNestedParentWhileCompleting()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/nested-neighbor';
		$nested = $base.'/season';
		@mkdir($nested, 0777, true);
		$old = $nested.'/old.bin';
		$neighbor = $nested.'/personal.txt';
		file_put_contents($old, 'old');
		file_put_contents($neighbor, 'keep');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$list = substr($tmp, 0, -4).'.list';
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'a nonempty cleanup parent must not crash completion: '.$output);
		$this->assertTrue(!file_exists($old) && is_file($neighbor) && is_dir($nested)
			&& !file_exists($tmp) && !file_exists($list),
			'a nonempty target-derived parent must survive while its cleanup job completes');
	}

	public function testSharedWriterKeepsLegacyRemovePayloadName()
	{
		$this->reset();
		$hash = $this->hash('A');
		$contents = ErasedataManifestCodec::encode($hash, array(
			'base' => '/d/movie.bin', 'multi' => false, 'files' => array('/d/movie.bin'),
		), 1);
		$this->assertTrue(function_exists('erasedataWriteStagedManifest'),
			'the shared staged writer must be available to the legacy producer');
		if(!function_exists('erasedataWriteStagedManifest'))
			return;
		$staged = erasedataWriteStagedManifest($this->dir.'/erasedata', strtolower($hash), $contents);
		$this->assertTrue(is_array($staged), 'a valid legacy manifest must stage successfully');
		$this->assertTrue(preg_match('/^'.preg_quote($hash, '/').'\\.[0-9]+\\.[A-Za-z0-9.]+\\.tmp$/D', basename($staged['path'])) === 1,
			'legacy remove-payload staging must keep its historical filename grammar');
	}

	public function testSharedWriterUsesDowngradeSafeCleanupTag()
	{
		$this->reset();
		$hash = $this->hash('A');
		$this->assertTrue(function_exists('erasedataWriteStagedManifest'),
			'the shared staged writer must be available for cleanup jobs');
		if(!function_exists('erasedataWriteStagedManifest'))
			return;
		$staged = erasedataWriteStagedManifest($this->dir.'/erasedata', $hash, $this->cleanupWriterContents($hash), 'cleanup');
		$this->assertTrue(is_array($staged), 'a cleanup manifest must stage successfully');
		$this->assertTrue(preg_match('/^'.preg_quote($hash, '/').'\\.cleanup\\.[0-9]+\\.[A-Za-z0-9.]+\\.tmp$/D', basename($staged['path'])) === 1,
			'cleanup staging must use a downgrade-safe filename tag');
	}

	public function testSharedWriterPreservesBytesAndSharedMode()
	{
		global $profileMask;
		$this->reset();
		$profileMask = 0671;
		$hash = $this->hash('A');
		$contents = $this->cleanupWriterContents($hash);
		$this->assertTrue(function_exists('erasedataWriteStagedManifest'),
			'the shared staged writer must preserve complete manifest bytes');
		if(!function_exists('erasedataWriteStagedManifest'))
			return;
		$staged = erasedataWriteStagedManifest($this->dir.'/erasedata', $hash, $contents, 'cleanup');
		$this->assertTrue(is_array($staged), 'a complete cleanup manifest must stage');
		$this->assertEquals($contents, file_get_contents($staged['path']), 'the shared writer must preserve encoded manifest bytes exactly');
		$this->assertEquals(0660, $this->modeOf($staged['path']), 'the staged manifest must use the shared profile mode');
	}

	public function testSharedWriterRejectsTagOperationMismatch()
	{
		$this->reset();
		$hash = $this->hash('A');
		$this->assertTrue(function_exists('erasedataWriteStagedManifest'),
			'the shared staged writer must validate the manifest operation');
		if(!function_exists('erasedataWriteStagedManifest'))
			return;
		$legacy = ErasedataManifestCodec::encode($hash, array(
			'base' => '/d/movie.bin', 'multi' => false, 'files' => array('/d/movie.bin'),
		), 1);
		$this->assertEquals(false, erasedataWriteStagedManifest($this->dir.'/erasedata', $hash, $legacy, 'cleanup'),
			'a cleanup filename tag must reject a remove-payload manifest');
		$this->assertEquals(false, erasedataWriteStagedManifest($this->dir.'/erasedata', $hash, $this->cleanupWriterContents($hash)),
			'a legacy filename must reject a cleanup manifest');
		$this->assertEquals(array(), glob($this->dir.'/erasedata/'.$hash.'.*.tmp'),
			'operation mismatches must not leave staged artifacts');
	}

	public function testSharedWriterRemovesPartialWriteArtifact()
	{
		$this->reset();
		$hash = $this->hash('A');
		$contents = $this->cleanupWriterContents($hash);
		$this->assertTrue(function_exists('erasedataWriteStagedManifest'),
			'the shared staged writer must clean up incomplete writes');
		if(!function_exists('erasedataWriteStagedManifest'))
			return;
		// The stalling queue wrapper leaves a real partial artifact behind.
		ErasedataPartialWriteStream::register();
		$stalling = ErasedataPartialWriteStream::SCHEME.'://'.$this->dir.'/erasedata';
		$this->assertEquals(false, erasedataWriteStagedManifest($stalling, $hash, $contents, 'cleanup'),
			'a short write must fail staging');
		$this->assertEquals(array(), glob($this->dir.'/erasedata/'.$hash.'.cleanup.*.tmp'),
			'a failed short write must remove only its partial artifact');
	}

	private function cleanupJobInput($oldHash, $newHash = null)
	{
		if($newHash === null)
			$newHash = $this->hash('B');
		$base = $this->dir.'/lifecycle-base';
		$file = $base.'/obsolete.bin';
		@mkdir($base, 0777, true);
		file_put_contents($file, 'obsolete');
		return(array(
			'new_hash' => $newHash,
			'marker' => '0123456789abcdef0123456789abcdef',
			'replacement_record' => strtoupper($oldHash).'-started-1787587200',
			'base' => $base,
			'entries' => array($this->cleanupEntry($file)),
		));
	}

	private function prepareCleanupJob($oldHash, $newHash = null)
	{
		$input = $this->cleanupJobInput($oldHash, $newHash);
		return(erasedataPrepareObsoleteCleanup($oldHash, $input['new_hash'], $input['marker'],
			$input['replacement_record'], $input['base'], $input['entries']));
	}

	private function configureMatchingCleanupRecovery($oldHash, $input)
	{
		rXMLRPCRequest::$responses['d.hash'] = array('byHash' => array(
			$oldHash => array('run' => true, 'fault' => true, 'val' => array(),
				'faultString' => 'invalid parameters: info-hash not found'),
			$input['new_hash'] => array('run' => true, 'fault' => false,
				'val' => array($input['new_hash'], $input['marker'], $input['replacement_record'])),
		));
	}

	public function testCleanupPrepareHoldsLockUntilPublish()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$this->assertTrue(function_exists('erasedataPrepareObsoleteCleanup'),
			'cleanup preparation must create an exact staged job');
		if(!function_exists('erasedataPrepareObsoleteCleanup'))
			return;
		$job = $this->prepareCleanupJob($oldHash);
		$this->assertTrue(is_array($job), 'valid cleanup input must prepare a job');
		$contender = erasedataAcquireHashLock($this->dir.'/erasedata', $oldHash, true);
		$this->assertEquals(false, $contender, 'the prepared cleanup job must retain its old-hash lock before publish');
		$this->assertTrue(erasedataPublishObsoleteCleanup($job), 'the exact prepared job must publish');
		$contender = erasedataAcquireHashLock($this->dir.'/erasedata', $oldHash, true);
		$this->assertTrue(is_resource($contender), 'publishing must release the held old-hash lock');
		if(is_resource($contender))
			erasedataReleaseHashLock($contender);
	}

	public function testCleanupPublishCreatesTokenForOnlyTheExactGeneration()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$this->assertTrue(function_exists('erasedataPublishObsoleteCleanup'),
			'cleanup publication must be available after staging');
		if(!function_exists('erasedataPublishObsoleteCleanup'))
			return;
		$job = $this->prepareCleanupJob($oldHash);
		$tmp = $job['tmp_path'];
		$list = $job['list_path'];
		$other = dirname($tmp).'/'.$this->hash('C').'.cleanup.'.getmypid().'.unrelated.tmp';
		file_put_contents($other, 'unrelated');
		$this->assertTrue(erasedataPublishObsoleteCleanup($job), 'the prepared generation must publish');
		$this->assertTrue(is_file($tmp) && is_file($list) && filesize($list) === 0,
			'publication must retain the exact strict tmp and create only its zero-byte token');
		$this->assertTrue(is_file($other), 'publication must not change an unrelated cleanup generation');
	}

	public function testCleanupPublishCollisionRetainsTmpAndReleasesLock()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$this->assertTrue(function_exists('erasedataPublishObsoleteCleanup'),
			'cleanup publication must reject a list-path collision');
		if(!function_exists('erasedataPublishObsoleteCleanup'))
			return;
		$job = $this->prepareCleanupJob($oldHash);
		$tmp = $job['tmp_path'];
		@mkdir($job['list_path']);
		$this->assertEquals(false, erasedataPublishObsoleteCleanup($job), 'an existing list collision must fail publication');
		$this->assertTrue(is_file($tmp), 'a failed publication must retain the complete tmp artifact');
		$contender = erasedataAcquireHashLock($this->dir.'/erasedata', $oldHash, true);
		$this->assertTrue(is_resource($contender), 'failed publication must release the held old-hash lock');
		if(is_resource($contender))
			erasedataReleaseHashLock($contender);
	}

	public function testCleanupCancelRemovesOnlyOwnedTmp()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$this->assertTrue(function_exists('erasedataCancelObsoleteCleanup'),
			'cleanup cancellation must be available for prepared jobs');
		if(!function_exists('erasedataCancelObsoleteCleanup'))
			return;
		$job = $this->prepareCleanupJob($oldHash);
		$tmp = $job['tmp_path'];
		$other = dirname($tmp).'/'.$this->hash('C').'.cleanup.'.getmypid().'.unrelated.tmp';
		file_put_contents($other, 'unrelated');
		$this->assertTrue(erasedataCancelObsoleteCleanup($job), 'the exact prepared generation must cancel');
		$this->assertTrue(!file_exists($tmp), 'cancellation must remove the owned prepared tmp');
		$this->assertTrue(is_file($other), 'cancellation must preserve unrelated cleanup artifacts');
	}

	public function testCleanupCancelRejectsInodeSwap()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$this->assertTrue(function_exists('erasedataCancelObsoleteCleanup'),
			'cleanup cancellation must verify the staged inode');
		if(!function_exists('erasedataCancelObsoleteCleanup'))
			return;
		$job = $this->prepareCleanupJob($oldHash);
		$tmp = $job['tmp_path'];
		$contents = file_get_contents($tmp);
		@rename($tmp, $tmp.'.original');
		file_put_contents($tmp, $contents);
		$this->assertEquals(false, erasedataCancelObsoleteCleanup($job), 'an inode-swapped tmp must not be cancelled');
		$this->assertTrue(is_file($tmp), 'the replacement tmp must survive a rejected cancellation');
		$contender = erasedataAcquireHashLock($this->dir.'/erasedata', $oldHash, true);
		$this->assertTrue(is_resource($contender), 'rejected cancellation must release the held old-hash lock');
		if(is_resource($contender))
			erasedataReleaseHashLock($contender);
	}

	public function testCleanupLifecycleRejectsChangedTransactionFields()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$this->assertTrue(function_exists('erasedataPublishObsoleteCleanup'),
			'cleanup lifecycle must validate its transaction fields');
		if(!function_exists('erasedataPublishObsoleteCleanup'))
			return;
		$job = $this->prepareCleanupJob($oldHash);
		$tmp = $job['tmp_path'];
		file_put_contents($tmp, str_replace('0123456789abcdef0123456789abcdef', 'fedcba9876543210fedcba9876543210', file_get_contents($tmp)));
		$this->assertEquals(false, erasedataPublishObsoleteCleanup($job), 'a changed manifest marker must reject publication');
		$this->assertTrue(is_file($tmp), 'a marker mismatch must retain the staged job');
		$job = $this->prepareCleanupJob($oldHash);
		$tmp = $job['tmp_path'];
		file_put_contents($tmp, str_replace($oldHash.'-started-1787587200', $oldHash.'-stopped-1787587200', file_get_contents($tmp)));
		$this->assertEquals(false, erasedataCancelObsoleteCleanup($job), 'a changed manifest replacement record must reject cancellation');
		$this->assertTrue(is_file($tmp), 'a record mismatch must retain the staged job');
	}

	private function rewriteCleanupTmpInPlace($tmp, $contents)
	{
		$before = lstat($tmp);
		file_put_contents($tmp, $contents);
		clearstatcache(true, $tmp);
		$after = lstat($tmp);
		$this->assertEquals($before['dev'], $after['dev'], 'the manifest rewrite must retain its device');
		$this->assertEquals($before['ino'], $after['ino'], 'the manifest rewrite must retain its inode');
	}

	public function testCleanupPublishRejectsSameInodeBaseRewrite()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$this->assertTrue(function_exists('erasedataPublishObsoleteCleanup'),
			'cleanup publication must bind the prepared base transaction field');
		if(!function_exists('erasedataPublishObsoleteCleanup'))
			return;
		$input = $this->cleanupJobInput($oldHash);
		$job = erasedataPrepareObsoleteCleanup($oldHash, $input['new_hash'], $input['marker'],
			$input['replacement_record'], $input['base'], $input['entries']);
		$tmp = $job['tmp_path'];
		$rewrittenBase = $this->dir.'/rewritten-base';
		$rewrittenFile = $rewrittenBase.'/obsolete.bin';
		@mkdir($rewrittenBase, 0777, true);
		file_put_contents($rewrittenFile, 'rewritten');
		$rewritten = ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $input['new_hash'], $input['marker'],
			$input['replacement_record'], $rewrittenBase, array($this->cleanupEntry($rewrittenFile)));
		$this->assertTrue(is_string($rewritten), 'the rewritten base fixture must remain a valid v3 manifest');
		$this->rewriteCleanupTmpInPlace($tmp, $rewritten);
		$this->assertEquals(false, erasedataPublishObsoleteCleanup($job),
			'a same-inode rewrite with another base must not publish the prepared job');
		$this->assertTrue(is_file($tmp), 'a rejected base rewrite must retain the tmp artifact');
		$contender = erasedataAcquireHashLock($this->dir.'/erasedata', $oldHash, true);
		$this->assertTrue(is_resource($contender), 'a rejected base rewrite must release the held old-hash lock');
		if(is_resource($contender))
			erasedataReleaseHashLock($contender);
	}

	public function testCleanupCancelRejectsSameInodeFileIdentityRewrite()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$this->assertTrue(function_exists('erasedataCancelObsoleteCleanup'),
			'cleanup cancellation must bind the prepared file transaction fields');
		if(!function_exists('erasedataCancelObsoleteCleanup'))
			return;
		$input = $this->cleanupJobInput($oldHash);
		$job = erasedataPrepareObsoleteCleanup($oldHash, $input['new_hash'], $input['marker'],
			$input['replacement_record'], $input['base'], $input['entries']);
		$tmp = $job['tmp_path'];
		$rewrittenFile = $input['base'].'/replacement.bin';
		file_put_contents($rewrittenFile, 'replacement');
		$rewritten = ErasedataManifestCodec::encodeCleanupObsolete($oldHash, $input['new_hash'], $input['marker'],
			$input['replacement_record'], $input['base'], array($this->cleanupEntry($rewrittenFile)));
		$this->assertTrue(is_string($rewritten), 'the rewritten file fixture must remain a valid v3 manifest');
		$this->rewriteCleanupTmpInPlace($tmp, $rewritten);
		$this->assertEquals(false, erasedataCancelObsoleteCleanup($job),
			'a same-inode rewrite with another file identity must not cancel the prepared job');
		$this->assertTrue(is_file($tmp), 'a rejected file rewrite must retain the tmp artifact');
		$contender = erasedataAcquireHashLock($this->dir.'/erasedata', $oldHash, true);
		$this->assertTrue(is_resource($contender), 'a rejected file rewrite must release the held old-hash lock');
		if(is_resource($contender))
			erasedataReleaseHashLock($contender);
	}

	public function testCleanupPrepareFailureReleasesLockWithoutArtifact()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$this->assertTrue(function_exists('erasedataPrepareObsoleteCleanup'),
			'cleanup preparation must release its lock after a staging failure');
		if(!function_exists('erasedataPrepareObsoleteCleanup'))
			return;
		// A read-only queue directory refuses the staged write while the
		// pre-created persistent hash lock still opens.
		file_put_contents($this->dir.'/erasedata/'.$oldHash.'.lock', '');
		@chmod($this->dir.'/erasedata', 0555);
		try {
			$this->assertEquals(false, $this->prepareCleanupJob($oldHash), 'a failed staged write must fail preparation');
			$this->assertEquals(array(), glob($this->dir.'/erasedata/'.$oldHash.'.cleanup.*.tmp'), 'failed preparation must not retain a partial cleanup artifact');
			@chmod($this->dir.'/erasedata', 0777);
			$contender = erasedataAcquireHashLock($this->dir.'/erasedata', $oldHash, true);
			$this->assertTrue(is_resource($contender), 'failed preparation must release the old-hash lock');
			if(is_resource($contender))
				erasedataReleaseHashLock($contender);
		} finally {
			@chmod($this->dir.'/erasedata', 0777);
		}
	}

	public function testCleanupLifecycleCanonicalizesHashes()
	{
		$this->reset();
		$oldHash = strtolower($this->hash('A'));
		$newHash = strtolower($this->hash('B'));
		$this->assertTrue(function_exists('erasedataPrepareObsoleteCleanup'),
			'cleanup preparation must canonicalize lifecycle hashes');
		if(!function_exists('erasedataPrepareObsoleteCleanup'))
			return;
		$job = $this->prepareCleanupJob($oldHash, $newHash);
		$this->assertTrue(is_array($job), 'lowercase lifecycle hashes must prepare successfully');
		$this->assertEquals(strtoupper($oldHash), $job['old_hash'], 'the staged job must retain a canonical old hash');
		$this->assertEquals(strtoupper($newHash), $job['new_hash'], 'the staged job must retain a canonical new hash');
		$this->assertTrue(erasedataCancelObsoleteCleanup($job), 'a canonicalized cleanup job must still cancel exactly');
	}

	public function testRemovePayloadManifestEncodingRemainsByteCompatible()
	{
		$this->reset();
		$hash = $this->hash('a');
		$expected = '{"version":2,"hash":"'.strtoupper($hash).'","path_encoding":"base64",'
			.'"files":["L2Qvc2luZ2xlLmJpbg=="],"base":"L2Qvc2luZ2xlLmJpbg==",'
			.'"multi":false,"force":1}' . "\n";
		$actual = ErasedataManifestCodec::encode($hash, array(
			'base' => '/d/single.bin',
			'multi' => false,
			'files' => array('/d/single.bin'),
		), '1');
		$this->assertEquals($expected, $actual, 'remove-payload v2 encoding must remain byte-compatible');
	}

	public function testLegacyAndVersionTwoDecodeAsRemovePayload()
	{
		$this->reset();
		$hash = $this->hash('b');
		$legacy = "/d/single.bin\n/d/single.bin\n0\n1\n";
		$legacyRecord = ErasedataManifestCodec::decodeBytes($legacy, $hash);
		$this->assertEquals('remove_payload', $legacyRecord['operation'], 'legacy manifests must decode as remove-payload');
		$this->assertTrue(!$legacyRecord['keep_base'], 'legacy manifests must permit their historical base handling');
		$v2 = ErasedataManifestCodec::encode($hash, array(
			'base' => '/d/single.bin',
			'multi' => false,
			'files' => array('/d/single.bin'),
		), 1);
		$v2Record = ErasedataManifestCodec::decodeBytes($v2, $hash);
		$this->assertEquals('remove_payload', $v2Record['operation'], 'version 2 manifests must decode as remove-payload');
		$this->assertTrue(!$v2Record['keep_base'], 'version 2 manifests must permit their historical base handling');
	}

	public function testForceFooterInjectionCannotSelectAnUnrelatedVictimRoot()
	{
		$this->reset();
		$hash = $this->hash();
		$victim = $this->dir.'/victim';
		@mkdir($victim, 0777, true);
		file_put_contents($victim.'/sentinel.txt', 'do-not-delete');
		$this->frozen(true, array("/d/name", 1, "/d/name/a.bin"));
		$this->eraseOk();
		$injectedForce = $victim."\n1\n2";
		$res = erasedataRemoveWithData(array($hash), $injectedForce);
		$this->assertTrue($res === false, 'injected force parameter must be rejected');
		$this->assertEquals(array(), rXMLRPCRequest::$erased, 'erase must not be called on injection attempt');
		$this->assertTrue(file_exists($victim.'/sentinel.txt'), 'victim sentinel must survive');
	}

	public function testSafeLegacyForceOneRemainsConsumable()
	{
		$this->reset();
		$hash = $this->hash();
		$base = $this->dir.'/legacy_multi';
		$file = $base.'/file.bin';
		@mkdir($base, 0777, true);
		file_put_contents($file, 'data');
		$this->writeLegacyManifestLines($hash.'.list', array($file), $base, 1, 1);
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'collector must exit 0: '.$output);
		$this->assertTrue(!file_exists($file), 'listed file must be deleted');
		$this->assertEquals(false, $this->onlyManifest($hash), 'legacy manifest must be consumed');
	}

	public function testLegacyForceTwoNeverRecursivelyDeletesUnlistedContent()
	{
		$this->reset();
		$hash = $this->hash();
		$base = $this->dir.'/legacy_force_multi';
		$listed = $base.'/listed.bin';
		$unlisted = $base.'/unlisted_victim.bin';
		@mkdir($base, 0777, true);
		file_put_contents($listed, 'data');
		file_put_contents($unlisted, 'unlisted-data');
		$this->writeLegacyManifestLines($hash.'.list', array($listed), $base, 1, 2);
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'collector must exit 0: '.$output);
		$this->assertTrue(!file_exists($listed), 'listed file must be deleted');
		$this->assertTrue(file_exists($unlisted), 'unlisted file under legacy force 2 must survive (no whole-tree recursion)');
		$this->assertTrue(is_file($this->onlyManifest($hash)), 'manifest must be retained because unlisted directory contents remain');
	}

	public function testMalformedJSONIsNotReinterpretedAsLegacy()
	{
		$this->reset();
		$hash = $this->hash();
		$data = $this->dir.'/data.bin';
		file_put_contents($data, 'keep-me');
		$badJson = "{\n/victim\n1\n2\n";
		file_put_contents($this->dir.'/erasedata/'.$hash.'.list', $badJson);
		list($status, $output) = $this->runCollector(array('val' => array($hash)));
		$this->assertEquals(0, $status, 'collector must exit 0: '.$output);
		$this->assertTrue(file_exists($data), 'data must not be deleted by malformed JSON');
		$this->assertEquals($badJson, file_get_contents($this->dir.'/erasedata/'.$hash.'.list'), 'malformed manifest must be preserved byte-for-byte');
	}

	public function testNoncanonicalBase64IsRejected()
	{
		$this->reset();
		$hash = $this->hash();
		$badManifest = json_encode(array(
			'version' => 2,
			'hash' => $hash,
			'path_encoding' => 'base64',
			'files' => array('L2E==='),
			'base' => 'L2E=',
			'multi' => false,
			'force' => 1
		))."\n";
		file_put_contents($this->dir.'/erasedata/'.$hash.'.list', $badManifest);
		list($status, $output) = $this->runCollector(array('val' => array($hash)));
		$this->assertEquals(0, $status, 'collector exits 0: '.$output);
		$this->assertEquals($badManifest, file_get_contents($this->dir.'/erasedata/'.$hash.'.list'), 'non-canonical base64 manifest retained byte-for-byte');
	}

	public function testHashMismatchIsRejected()
	{
		$this->reset();
		$hash = $this->hash('A');
		$wrongHash = $this->hash('B');
		$manifest = ErasedataManifestCodec::encode($wrongHash, array(
			'base' => '/d/single.bin',
			'multi' => false,
			'files' => array('/d/single.bin'),
		), "1");
		file_put_contents($this->dir.'/erasedata/'.$hash.'.list', $manifest);
		list($status, $output) = $this->runCollector(array('val' => array($hash)));
		$this->assertEquals(0, $status, 'collector exits 0: '.$output);
		$this->assertEquals($manifest, file_get_contents($this->dir.'/erasedata/'.$hash.'.list'), 'hash mismatch manifest retained byte-for-byte');
	}

	public function testLowercaseManifestHashIsRejectedAsNoncanonical()
	{
		$this->reset();
		$hash = $this->hash('A');
		$data = $this->dir.'/lowercase-hash-victim.bin';
		file_put_contents($data, 'keep-me');
		$manifest = ErasedataManifestCodec::encode($hash, array(
			'base' => $data,
			'multi' => false,
			'files' => array($data),
		), "1");
		$decoded = json_decode($manifest, true);
		$decoded['hash'] = strtolower($hash);
		$noncanonical = json_encode($decoded, JSON_UNESCAPED_SLASHES)."\n";
		file_put_contents($this->dir.'/erasedata/'.$hash.'.list', $noncanonical);

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'collector exits normally for noncanonical hash: '.$output);
		$this->assertEquals('keep-me', is_file($data) ? file_get_contents($data) : null,
			'lowercase manifest hash cannot authorize deletion');
		$this->assertEquals($noncanonical,
			file_get_contents($this->dir.'/erasedata/'.$hash.'.list'),
			'noncanonical manifest remains byte-for-byte for diagnosis and retry');
	}

	public function testOversizeManifestFileCountAndPathLimitsRetainExactBytes()
	{
		$this->reset();
		$hash = $this->hash();
		$hugePath = '/'.str_repeat('a', 1048577);
		$hugeManifest = json_encode(array(
			'version' => 2,
			'hash' => $hash,
			'path_encoding' => 'base64',
			'files' => array(base64_encode($hugePath)),
			'base' => base64_encode($hugePath),
			'multi' => false,
			'force' => 1
		))."\n";
		file_put_contents($this->dir.'/erasedata/'.$hash.'.list', $hugeManifest);
		list($status, $output) = $this->runCollector(array('val' => array($hash)));
		$this->assertEquals(0, $status, 'collector exits 0: '.$output);
		$this->assertEquals($hugeManifest, file_get_contents($this->dir.'/erasedata/'.$hash.'.list'), 'oversize manifest retained byte-for-byte');
	}

	private function runCollectorWithFault($ok, $fault, $val, $faultString = '')
	{
		return($this->runCollector(array(
			'ok' => $ok, 'fault' => $fault, 'val' => $val, 'faultString' => $faultString)));
	}

	public function testConfirmedMissingHashFaultIsAbsentAndPermitsCollection()
	{
		$this->reset();
		$hash = $this->hash('C');
		$data = $this->dir.'/data-absent-fault.bin';
		file_put_contents($data, 'delete-me');
		$this->writeManifest($hash.'.list', $data);
		list($status, $output) = $this->runCollectorWithFault(true, true, array(), 'Could not find info-hash.');
		$this->assertEquals(0, $status, 'collector exits 0 on missing-hash fault: '.$output);
		$this->assertTrue(!file_exists($data), 'data must be deleted when missing-hash fault is returned');
		$this->assertEquals(false, $this->onlyManifest($hash), 'manifest must be consumed on missing-hash fault');
	}

	public function testTransportFailureIsUnknownAndRetainsManifestAndData()
	{
		$this->reset();
		$hash = $this->hash('D');
		$data = $this->dir.'/data-transport-fail.bin';
		file_put_contents($data, 'keep-me');
		$this->writeManifest($hash.'.list', $data);
		list($status, $output) = $this->runCollector(array('ok' => false, 'val' => array()));
		$this->assertEquals(0, $status, 'collector exits 0 on transport failure: '.$output);
		$this->assertTrue(file_exists($data), 'data must be retained on transport failure');
		$this->assertTrue(is_file($this->onlyManifest($hash)), 'manifest must be retained on transport failure');
	}

	public function testUnrelatedFaultIsUnknownAndRetainsManifestAndData()
	{
		$this->reset();
		$hash = $this->hash('E');
		$data = $this->dir.'/data-unrelated-fault.bin';
		file_put_contents($data, 'keep-me');
		$this->writeManifest($hash.'.list', $data);
		list($status, $output) = $this->runCollectorWithFault(true, true, array(), 'Permission denied');
		$this->assertEquals(0, $status, 'collector exits 0 on unrelated fault: '.$output);
		$this->assertTrue(file_exists($data), 'data must be retained on unrelated fault');
		$this->assertTrue(is_file($this->onlyManifest($hash)), 'manifest must be retained on unrelated fault');
	}

	public function testMixedPermissionAndMissingHashFaultIsUnknownAndRetainsManifestAndData()
	{
		$this->reset();
		$hash = $this->hash('E');
		$data = $this->dir.'/data-unrelated-fault.bin';
		file_put_contents($data, 'keep-me');
		$this->writeManifest($hash.'.list', $data);
		list($status, $output) = $this->runCollectorWithFault(
			true, true, array(), 'Permission denied while info-hash not found in protected view');
		$this->assertEquals(0, $status, 'collector exits 0 on unrelated fault: '.$output);
		$this->assertTrue(file_exists($data), 'data must be retained on unrelated fault');
		$this->assertTrue(is_file($this->onlyManifest($hash)), 'manifest must be retained on unrelated fault');
	}

	public function testMalformedCleanResponseIsUnknown()
	{
		$this->reset();
		$hash = $this->hash('F');
		$data = $this->dir.'/data-malformed-clean.bin';
		file_put_contents($data, 'keep-me');
		$this->writeManifest($hash.'.list', $data);
		list($status, $output) = $this->runCollector(array('val' => array($hash, 'EXTRA_CARDINALITY')));
		$this->assertEquals(0, $status, 'collector exits 0 on malformed cardinality: '.$output);
		$this->assertTrue(file_exists($data), 'data must be retained on malformed clean response');
		$this->assertTrue(is_file($this->onlyManifest($hash)), 'manifest must be retained on malformed clean response');
	}

	public function testWrongCleanHashIsUnknown()
	{
		$this->reset();
		$hash = $this->hash('7');
		$wrong = $this->hash('8');
		$data = $this->dir.'/data-wrong-clean.bin';
		file_put_contents($data, 'keep-me');
		$this->writeManifest($hash.'.list', $data);
		list($status, $output) = $this->runCollector(array('val' => array($wrong)));
		$this->assertEquals(0, $status, 'collector exits 0 on wrong clean hash: '.$output);
		$this->assertTrue(file_exists($data), 'data must be retained on wrong clean hash');
		$this->assertTrue(is_file($this->onlyManifest($hash)), 'manifest must be retained on wrong clean hash');
	}

	public function testRealXMLRPCFaultParsingPreservesRawBoundariesForPresence()
	{
		$hash = $this->hash('6');
		$cases = array(
			'info-hash not found' => ERASEDATA_TORRENT_ABSENT,
			'Could not find info-hash.' => ERASEDATA_TORRENT_ABSENT,
			'invalid parameters: info-hash not found' => ERASEDATA_TORRENT_ABSENT,
			' info-hash not found' => ERASEDATA_TORRENT_UNKNOWN,
			'info-hash not found ' => ERASEDATA_TORRENT_UNKNOWN,
			"\tinfo-hash not found" => ERASEDATA_TORRENT_UNKNOWN,
			"info-hash not found\t" => ERASEDATA_TORRENT_UNKNOWN,
			"\ninfo-hash not found" => ERASEDATA_TORRENT_UNKNOWN,
			"info-hash not found\n" => ERASEDATA_TORRENT_UNKNOWN,
		);
		foreach($cases as $raw => $expected)
		{
			list($status, $output, $parsed) = $this->parseFaultThroughProductionXMLRPC($raw);
			$this->assertEquals(0, $status, 'real XMLRPC parser exits normally for raw fault boundary case: '.$output);
			$this->assertTrue(is_array($parsed) && $parsed['run'] === true && $parsed['fault'] === true,
				'real XMLRPC parser exposes a parsed fault response');
			$this->assertEquals(trim($raw), isset($parsed['faultString']) ? $parsed['faultString'] : null,
				'public faultString remains normalized for existing consumers');
			$this->assertEquals($raw, isset($parsed['rawFaultString']) ? $parsed['rawFaultString'] : null,
				'exact decoded fault text remains available before boundary trimming');
			rXMLRPCRequest::$responses['d.hash'] = array(
				'runResult' => true,
				'fault' => true,
				'faultString' => $parsed['faultString'],
				'rawFaultString' => isset($parsed['rawFaultString']) ? $parsed['rawFaultString'] : null,
				'val' => array(),
			);
			$this->assertEquals($expected, erasedataTorrentPresence($hash),
				'presence classification uses the exact raw fault boundary');
		}
	}

	public function testPresenceTriStateDirectMatrix()
	{
		$hash = $this->hash('9');
		// Clean matching -> PRESENT
		$this->probe(true, false, array($hash));
		$this->assertEquals(ERASEDATA_TORRENT_PRESENT, erasedataTorrentPresence($hash), 'matching clean response is PRESENT');

		// Clean empty -> UNKNOWN
		$this->probe(true, false, array(''));
		$this->assertEquals(ERASEDATA_TORRENT_UNKNOWN, erasedataTorrentPresence($hash),
			'clean empty string response is UNKNOWN');

		// Complete known missing info-hash faults -> ABSENT
		$missingFaults = array(
			'Could not find info-hash.',
			'Could not find info-hash',
			'COULD NOT FIND INFO-HASH.',
			'info-hash not found',
			'Info-hash not found.',
			'INFO-HASH NOT FOUND.',
			'invalid parameters: info-hash not found',
			'INVALID PARAMETERS: INFO-HASH NOT FOUND'
		);
		foreach($missingFaults as $mf)
		{
			$this->probe(true, true, array(), $mf);
			$this->assertEquals(ERASEDATA_TORRENT_ABSENT, erasedataTorrentPresence($hash), 'missing-hash fault "'.$mf.'" is ABSENT');
		}

		// Transport failure -> UNKNOWN
		$this->probe(false, false, array());
		$this->assertEquals(ERASEDATA_TORRENT_UNKNOWN, erasedataTorrentPresence($hash), 'transport failure is UNKNOWN');

		// Unrelated faults -> UNKNOWN
		$unrelatedFaults = array(
			'Permission denied',
			'Access denied',
			'Internal server error',
			'Unknown method',
			'XMLRPC error',
			'Permission denied while info-hash not found in protected view',
			'Access denied: could not find info-hash.',
			'prefix info-hash not found',
			'info-hash not found in torrent map',
			'info-hash not found suffix',
			"info-hash not found\nPermission denied",
			"info-hash\tnot found",
			"info-hash\nnot found",
			'info-hash  not found',
			'Could  not find info-hash',
			'Could not  find info-hash',
			'Could not find  info-hash',
			"invalid parameters:\tinfo-hash not found",
			"invalid parameters: info-hash\nnot found",
			'invalid parameters:  info-hash not found',
			'invalid parameters: info-hash  not found',
			'Permission denied: invalid parameters: info-hash not found',
			'invalid parameters: info-hash not found in protected view',
			'invalid parameters: info-hash not found.',
			' info-hash not found',
			'info-hash not found ',
			'Could not find info-hash!'
		);
		foreach($unrelatedFaults as $uf)
		{
			$this->probe(true, true, array(), $uf);
			$this->assertEquals(ERASEDATA_TORRENT_UNKNOWN, erasedataTorrentPresence($hash), 'unrelated fault "'.$uf.'" is UNKNOWN');
		}

		// Malformed clean responses -> UNKNOWN
		$this->probe(true, false, array($hash, 'extra'));
		$this->assertEquals(ERASEDATA_TORRENT_UNKNOWN, erasedataTorrentPresence($hash), 'extra cardinality is UNKNOWN');
		$this->probe(true, false, array(12345));
		$this->assertEquals(ERASEDATA_TORRENT_UNKNOWN, erasedataTorrentPresence($hash), 'non-string type is UNKNOWN');
		$this->probe(true, false, array($this->hash('0')));
		$this->assertEquals(ERASEDATA_TORRENT_UNKNOWN, erasedataTorrentPresence($hash), 'different hash is UNKNOWN');
	}

	public function testRegularFileReplacementBeforeMutationSurvives()
	{
		$this->reset();
		$hash = $this->hash('0');
		$path = $this->dir.'/regular-race.bin';
		$replacement = $this->dir.'/regular-replacement.bin';
		$backup = $this->dir.'/regular-original.checked';
		$marker = $this->dir.'/regular-race.triggered';
		file_put_contents($path, 'original-bytes');
		file_put_contents($replacement, 'replacement-bytes');
		$this->writeManifest($hash.'.list', $path);

		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'rename:1' => array('path' => $path, 'action' => 'replace-entry',
				'backup' => $backup, 'replacement' => $replacement, 'marker' => $marker),
			'unlink:1' => array('path' => $path, 'action' => 'replace-entry',
				'backup' => $backup, 'replacement' => $replacement, 'marker' => $marker),
		)));

		$this->assertEquals(0, $status, 'regular-file race collector exits normally: '.$output);
		$this->assertTrue(is_file($marker), 'the scripted regular-file swap reached the production mutation boundary');
		$this->assertEquals('replacement-bytes', is_file($path) ? file_get_contents($path) : null,
			'a replacement installed before mutation survives at the public path');
		$this->assertEquals('original-bytes', is_file($backup) ? file_get_contents($backup) : null,
			'the originally checked inode remains outside the swapped public name');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$hash.'.list'),
			'identity uncertainty retains the regular-file manifest');
	}

	public function testManifestReplacementBeforeMutationSurvives()
	{
		$this->reset();
		$hash = $this->hash('A');
		$data = $this->dir.'/manifest-race-data.bin';
		$manifest = $this->dir.'/erasedata/'.$hash.'.list';
		$replacement = $this->dir.'/manifest-race-replacement.list';
		$backup = $this->dir.'/manifest-race-original.checked';
		$marker = $this->dir.'/manifest-race.triggered';
		file_put_contents($data, 'manifest-race-data');
		$this->writeManifest($hash.'.list', $data);
		$original = file_get_contents($manifest);
		file_put_contents($replacement, 'replacement-obligation');

		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'rename:1' => array('path' => $manifest, 'action' => 'replace-entry',
				'backup' => $backup, 'replacement' => $replacement, 'marker' => $marker),
			'unlink:1' => array('path' => $manifest, 'action' => 'replace-entry',
				'backup' => $backup, 'replacement' => $replacement, 'marker' => $marker),
		)));

		$this->assertEquals(0, $status, 'manifest race collector exits normally: '.$output);
		$this->assertTrue(is_file($marker),
			'the scripted manifest swap reaches the final comparison-to-unlink boundary');
		$this->assertEquals('replacement-obligation',
			file_exists($manifest) ? file_get_contents($manifest) : null,
			'a replacement manifest installed at the public path survives');
		$this->assertEquals($original, is_file($backup) ? file_get_contents($backup) : null,
			'the manifest opened and parsed by the collector remains isolated');
	}

	public function testForceRootSwapBeforeCaptureSurvives()
	{
		$this->reset();
		$hash = $this->hash('1');
		$base = $this->dir.'/forced_swap_root';
		$replacement = $this->dir.'/forced_victim_root';
		$backup = $this->dir.'/forced_original_root.checked';
		$marker = $this->dir.'/forced-root-race.triggered';
		@mkdir($base, 0777, true);
		file_put_contents($base.'/orig.bin', 'orig');
		@mkdir($replacement, 0777, true);
		file_put_contents($replacement.'/victim.bin', 'victim-data');

		$this->writeManifestLines($hash.'.list', array($base.'/orig.bin'), $base, 1, 2);

		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'rename:1' => array('path' => $base, 'action' => 'replace-entry',
				'backup' => $backup, 'replacement' => $replacement, 'marker' => $marker),
			'unlink:1' => array('path' => $base, 'action' => 'replace-entry',
				'backup' => $backup, 'replacement' => $replacement, 'marker' => $marker),
		)));

		$this->assertEquals(0, $status, 'forced-root race collector exits normally: '.$output);
		$this->assertTrue(is_file($marker), 'the scripted root swap reached the production capture boundary');
		$this->assertEquals('victim-data', is_file($base.'/victim.bin')
			? file_get_contents($base.'/victim.bin') : null,
			'victim data survives a forced root swap');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$hash.'.list'), 'manifest must be retained on root swap');
	}

	public function testForceRootSymlinkSwapCannotReachExternalSentinel()
	{
		$this->reset();
		$hash = $this->hash('2');
		$base = $this->dir.'/forced_symlink_root';
		$external = $this->dir.'/external_target';
		$backup = $this->dir.'/forced_symlink_original.checked';
		$marker = $this->dir.'/forced-symlink-race.triggered';
		@mkdir($base, 0777, true);
		file_put_contents($base.'/orig.bin', 'orig');
		@mkdir($external, 0777, true);
		file_put_contents($external.'/sentinel.txt', 'sentinel-keep');

		$this->writeManifestLines($hash.'.list', array($base.'/orig.bin'), $base, 1, 2);

		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'rename:1' => array('path' => $base, 'action' => 'replace-entry',
				'backup' => $backup, 'symlink_target' => $external, 'marker' => $marker),
		)));
		$this->assertEquals(0, $status, 'forced symlink race collector exits normally: '.$output);
		$this->assertTrue(is_file($marker), 'the scripted symlink swap reached the production capture boundary');
		$this->assertTrue(file_exists($external.'/sentinel.txt'), 'external sentinel must survive symlinked forced root');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$hash.'.list'),
			'symlink identity uncertainty retains the force manifest');
	}

	public function testDanglingSymlinkIsCapturedAndUnlinkedWithoutResolvingItsTarget()
	{
		$this->reset();
		$hash = $this->hash('3');
		$dangling = $this->dir.'/dangling_link.bin';
		$nonexistent = $this->dir.'/nonexistent_target.bin';
		@symlink($nonexistent, $dangling);
		$this->assertTrue(is_link($dangling), 'dangling symlink created');
		$this->assertTrue(!file_exists($dangling), 'target is nonexistent');
		$identity = (new ErasedataFilesystemOps())->entryIdentity($dangling);
		$this->assertTrue(is_array($identity) && !array_key_exists('stat', $identity),
			'entry identity is lstat-only and never resolves a dangling target');

		$this->writeManifest($hash.'.list', $dangling);

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'collector exits 0: '.$output);
		$this->assertTrue(!is_link($dangling), 'dangling symlink entry must be unlinked');
		$this->assertEquals(false, $this->onlyManifest($hash), 'manifest must be consumed after unlinking dangling symlink');
	}

	public function testNestedChildSwapAfterParentScanSurvives()
	{
		$this->reset();
		$hash = $this->hash('4');
		$base = $this->dir.'/nested-race-root';
		$child = $base.'/child.bin';
		$replacement = $this->dir.'/nested-replacement.bin';
		$backup = $this->dir.'/nested-original.checked';
		$marker = $this->dir.'/nested-race.triggered';
		mkdir($base);
		file_put_contents($child, 'original-child');
		file_put_contents($replacement, 'replacement-child');
		$this->writeManifestLines($hash.'.list', array($child), $base, 1, 2);

		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'rename:1' => array('basename' => basename($child),
				'after_scan' => basename($child), 'action' => 'replace-entry',
				'backup' => $backup, 'replacement' => $replacement, 'marker' => $marker),
			'unlink:1' => array('basename' => basename($child),
				'after_scan' => basename($child), 'action' => 'replace-entry',
				'backup' => $backup, 'replacement' => $replacement, 'marker' => $marker),
		)));

		$this->assertEquals(0, $status, 'nested-child race collector exits normally: '.$output);
		$this->assertTrue(is_file($marker), 'the scripted child swap happened after its parent scan');
		$this->assertEquals('replacement-child', is_file($base.'/child.bin')
			? file_get_contents($base.'/child.bin') : null,
			'a nested replacement survives the mutation boundary');
		$this->assertEquals('original-child', is_file($backup) ? file_get_contents($backup) : null,
			'the child observed during the parent scan remains isolated');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$hash.'.list'),
			'nested identity uncertainty retains the manifest');
	}

	public function testPublicPathRecreationAfterCaptureSurvives()
	{
		$this->reset();
		$hash = $this->hash('5');
		$base = $this->dir.'/public-recreation-root';
		$marker = $this->dir.'/public-recreation.triggered';
		mkdir($base);
		file_put_contents($base.'/original.bin', 'original');
		$this->writeManifestLines($hash.'.list', array($base.'/original.bin'), $base, 1, 2);

		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'rename:1' => array('path' => $base, 'action' => 'recreate', 'at' => 'after',
				'content' => array('name' => 'sentinel.bin', 'bytes' => 'recreated-public'),
				'marker' => $marker),
		)));

		$this->assertEquals(0, $status, 'public-recreation collector exits normally: '.$output);
		$this->assertTrue(is_file($marker), 'the scripted recreation happened after atomic root capture');
		$this->assertEquals('recreated-public', is_file($base.'/sentinel.bin')
			? file_get_contents($base.'/sentinel.bin') : null,
			'a public path recreated after capture is never overwritten or deleted');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$hash.'.list'),
			'public-path collision retains the manifest');
	}

	public function testCaptureIdentityMismatchRetainsManifestAndReservation()
	{
		$this->reset();
		$hash = $this->hash('6');
		$base = $this->dir.'/capture-mismatch-root';
		$replacement = $this->dir.'/capture-mismatch-replacement';
		$backup = $this->dir.'/capture-mismatch-original.checked';
		mkdir($base);
		file_put_contents($base.'/original.bin', 'original');
		mkdir($replacement);
		file_put_contents($replacement.'/replacement.bin', 'replacement');
		$this->writeManifestLines($hash.'.list', array($base.'/original.bin'), $base, 1, 2);

		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'rename:1' => array('path' => $base, 'action' => 'replace-entry',
				'backup' => $backup, 'replacement' => $replacement),
			'unlink:1' => array('path' => $base, 'action' => 'replace-entry',
				'backup' => $backup, 'replacement' => $replacement),
		)));

		$this->assertEquals(0, $status, 'capture-mismatch collector exits normally: '.$output);
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$hash.'.list'),
			'capture identity mismatch retains the exact manifest');
		$this->assertEquals(1, count(glob($this->dir.'/.erasedata-rmdir-*')),
			'capture identity mismatch retains one discoverable reservation');
		$this->assertEquals('replacement', is_file($base.'/replacement.bin')
			? file_get_contents($base.'/replacement.bin') : null,
			'the mismatched captured entry remains recoverable');
	}

	public function testCrashAfterCaptureResumesIdempotently()
	{
		$this->reset();
		$hash = $this->hash('7');
		$base = $this->dir.'/crash-after-capture-root';
		$marker = $this->dir.'/crash-after-capture.triggered';
		mkdir($base);
		file_put_contents($base.'/data.bin', 'captured-data');
		$this->writeManifestLines($hash.'.list', array($base.'/data.bin'), $base, 1, 2);

		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'rename:1' => array('path' => $base, 'action' => 'exit',
				'at' => 'after', 'marker' => $marker),
		)));
		$this->assertEquals(0, $status, 'capture-crash worker exits at the scripted boundary: '.$output);
		$this->assertTrue(is_file($marker), 'the worker exited only after atomic capture completed');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$hash.'.list'),
			'capture crash retains the manifest for recovery');
		$this->assertEquals(1, count(glob($this->dir.'/.erasedata-rmdir-*')),
			'capture crash leaves one discoverable reservation');

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'capture-crash retry exits normally: '.$output);
		$this->assertEquals(array(), glob($this->dir.'/.erasedata-rmdir-*'),
			'retry removes the exact captured tree and private reservation');
		$this->assertTrue(!file_exists($base) && !is_link($base),
			'retry completes without recreating the deleted public root');
		$this->assertTrue(!is_file($this->dir.'/erasedata/'.$hash.'.list'),
			'manifest is removed only after captured recovery cleanup completes');
	}

	public function testSafeDirectoryReferenceUnavailableRetainsManifest()
	{
		$this->reset();
		$hash = $this->hash('8');
		$base = $this->dir.'/unavailable-reference-root';
		$marker = $this->dir.'/unavailable-reference.triggered';
		mkdir($base);
		file_put_contents($base.'/data.bin', 'reference-data');
		$this->writeManifestLines($hash.'.list', array($base.'/data.bin'), $base, 1, 2);

		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'openDirectoryReference:*' => array('result' => false, 'marker' => $marker),
		)));

		$this->assertEquals(0, $status, 'unavailable-reference collector exits normally: '.$output);
		$this->assertTrue(is_file($marker), 'the scripted safe-reference refusal reached production traversal');
		$this->assertEquals('reference-data', is_file($base.'/data.bin')
			? file_get_contents($base.'/data.bin') : null,
			'no data is deleted without an identity-bound directory reference');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$hash.'.list'),
			'safe-reference uncertainty retains the manifest');
	}
	// -- S02 characterization: import safety and scripted operations --------

	// Requiring collector.php must define symbols and nothing else, so the
	// probe runs in a fresh process and reports every observable effect.
	public function testContainmentRefusesEveryPathThatCouldClimbOutOfItsBase()
	{
		// isUnderBase() compares STRINGS. It never resolves the path, so its
		// whole soundness rests on isValidAbsolutePath() having already refused
		// any component that could climb -- and that refusal was covered by
		// nothing: deleting the '..' half left the entire harness green.
		//
		// The escape it lets through is not subtle. A manifest naming
		// base=/data/torrents/movie with a file of
		// /data/torrents/movie/../../../etc/shadow passes a lexical prefix test
		// exactly, and the collector is then handed a path outside the base it
		// was told to stay inside.
		$base = '/data/torrents/movie';
		$escape = $base.'/../../../etc/shadow';
		$this->assertTrue(!ErasedataManifestCodec::isValidAbsolutePath($escape),
			'a path with a climbing component is not a valid absolute path');
		$this->assertTrue(!ErasedataManifestCodec::isUnderBase($escape, $base),
			'and containment refuses it, even though its string prefix matches');

		foreach(array(
			'climb at the end' => $base.'/..',
			'climb in the middle' => $base.'/../movie/file.mkv',
			'climb first' => '/../etc/shadow',
			'self-reference' => $base.'/./file.mkv',
			'self-reference alone' => $base.'/.',
			'double climb' => $base.'/../../file.mkv',
		) as $label => $path)
			$this->assertTrue(!ErasedataManifestCodec::isUnderBase($path, $base),
				'containment refuses '.$label.': '.$path);

		// The other half of the pair: ordinary paths must still be admitted, or
		// a guard that refuses everything would pass the rows above and delete
		// nothing for anybody.
		foreach(array(
			'a file directly under the base' => $base.'/file.mkv',
			'a file in a subdirectory' => $base.'/season 1/file.mkv',
			'a name that merely contains dots' => $base.'/file..mkv',
			'a name that is three dots' => $base.'/.../file.mkv',
			'a hidden file' => $base.'/.nfo',
			'the base itself' => $base,
		) as $label => $path)
			$this->assertTrue(ErasedataManifestCodec::isUnderBase($path, $base),
				'containment admits '.$label.': '.$path);

		// And a sibling whose name merely starts with the base is not under it.
		$this->assertTrue(!ErasedataManifestCodec::isUnderBase($base.'-other/file.mkv', $base),
			'a sibling directory sharing the base as a name prefix is not contained');

		// Admitted, and correctly so: an empty component and a trailing slash
		// both resolve to the same file, so neither escapes anything. Written
		// down because the obvious guess is that they are refused -- I made it
		// myself, and the test corrected me rather than the code.
		foreach(array(
			'an empty component' => $base.'//file.mkv',
			'a trailing slash' => $base.'/file.mkv/',
		) as $label => $path)
			$this->assertTrue(ErasedataManifestCodec::isUnderBase($path, $base),
				'containment admits '.$label.', which resolves to the same file: '.$path);
	}

	public function testOnlyACliInvocationOfTheEntryPointItselfMayStartTheCollector()
	{
		// The SAPI half of this guard is the headline of the commit that added
		// it -- plugins live under the document root, so an unauthenticated
		// request for /plugins/erasedata/update.php satisfies the path test
		// exactly -- and no test could reach it while it was an inline
		// condition, because the suite has no non-CLI SAPI to run under.
		// Measured: deleting `PHP_SAPI === 'cli' &&` left the whole harness
		// green.
		$file = realpath(__DIR__.'/../../../plugins/erasedata/update.php');
		$this->assertTrue(is_string($file) && $file !== '',
			'the entry point this predicate defends must exist to be defended');

		$this->assertTrue(erasedataMayStartCollector('cli', $file, $file),
			'the scheduler, which is CLI and names this file, is admitted');

		foreach(array('cli-server', 'fpm-fcgi', 'cgi-fcgi', 'apache2handler',
			'litespeed', 'phpdbg', '', 'CLI') as $sapi)
			$this->assertTrue(!erasedataMayStartCollector($sapi, $file, $file),
				'the '.($sapi === '' ? 'empty' : $sapi)
					.' SAPI may not start the collector even naming this file');

		$other = realpath(__DIR__.'/../../../plugins/erasedata/removewithdata.php');
		$this->assertTrue(!erasedataMayStartCollector('cli', $other, $file),
			'a CLI script that merely requires this file does not start the collector');
		foreach(array(null, '', false, 0, array(), $file.'.missing') as $script)
			$this->assertTrue(!erasedataMayStartCollector('cli', $script, $file),
				'an absent or unresolvable SCRIPT_FILENAME starts nothing');
	}

	private function collectorImportProbe()
	{
		$token = bin2hex(random_bytes(6));
		$scenarioFile = sys_get_temp_dir().'/erasedata-import-'.$token.'.json';
		$logFile = sys_get_temp_dir().'/erasedata-import-log-'.$token.'.json';
		file_put_contents($scenarioFile, json_encode(array(
			'mode' => 'import',
			'settings' => $this->dir,
			'profileMask' => 0777,
			'debug' => true,
			'onlyHash' => null,
			'publicCollectorHash' => null,
			'indexCountFile' => null,
			'source' => false,
			'responses' => array(),
			'scenario' => array(),
			'logFile' => $logFile,
		)));
		$runner = realpath(__DIR__.'/CollectorFixture.php');
		$output = array();
		$status = 0;
		exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -f '.escapeshellarg($runner)
			.' -- '.escapeshellarg($scenarioFile).' 2>&1', $output, $status);
		$observed = is_file($logFile) ? json_decode(file_get_contents($logFile), true) : null;
		@unlink($scenarioFile);
		@unlink($logFile);
		clearstatcache();
		return(array('status' => $status, 'output' => implode("\n", $output),
			'observed' => $observed));
	}

	public function testRequiringTheCollectorSourcePerformsNoWork()
	{
		$this->reset();
		$hash = $this->hash('A');
		$data = $this->dir.'/import-safety.bin';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		file_put_contents($data, 'import-safety');
		$this->writeManifest($hash.'.list', $data);
		$this->probe(true, true, array(), 'invalid parameters: info-hash not found');

		$probe = $this->collectorImportProbe();
		$observed = $probe['observed'];

		$this->assertEquals(0, $probe['status'],
			'requiring the collector source must not fail: '.$probe['output']);
		$this->assertTrue(is_array($observed),
			'the import probe must report observations: '.$probe['output']);
		if(!is_array($observed))
			return;
		$this->assertTrue(!empty($observed['collector']),
			'requiring collector.php defines the importable ErasedataCollector service');
		$this->assertEquals(array(), $observed['rpc'],
			'requiring collector.php performs zero XMLRPC work');
		$this->assertEquals(array(), $observed['erased'],
			'requiring collector.php erases nothing');
		$this->assertEquals(array(), $observed['log'],
			'requiring collector.php writes no log line even with debug logging enabled');
		$this->assertTrue($observed['lock'] === false,
			'requiring collector.php acquires no scheduler lock');
		$this->assertEquals($observed['before'], $observed['after'],
			'requiring collector.php mutates no queue entry');
		$this->assertEquals('import-safety', is_file($data) ? file_get_contents($data) : null,
			'requiring collector.php deletes no payload byte');
		$this->assertTrue(is_file($list), 'requiring collector.php consumes no manifest');
	}

	public function testScriptedOperationOrdinalAndForcedResultAreExact()
	{
		$this->reset();
		$first = $this->dir.'/ordinal-first.bin';
		$second = $this->dir.'/ordinal-second.bin';
		file_put_contents($first, 'first');
		file_put_contents($second, 'second');

		$fixture = new ErasedataCollectorFixture(array(
			'unlink:2' => array('result' => false),
		));
		$this->assertTrue($fixture->unlink($first) === true,
			'the first scripted unlink ordinal reaches the real filesystem');
		$this->assertTrue($fixture->unlink($second) === false,
			'the second scripted unlink ordinal returns its forced result');
		$this->assertTrue(!file_exists($first) && is_file($second),
			'only the unscripted ordinal deletes, so the scenario is exact per ordinal');
	}

	public function testScriptedOperationsReproduceTheIdentitySwapRefusal()
	{
		$this->reset();
		$hash = $this->hash('B');
		$path = $this->dir.'/scripted-race.bin';
		$replacement = $this->dir.'/scripted-replacement.bin';
		$backup = $this->dir.'/scripted-original.checked';
		$marker = $this->dir.'/scripted-race.triggered';
		file_put_contents($path, 'original-bytes');
		file_put_contents($replacement, 'replacement-bytes');
		$this->writeManifest($hash.'.list', $path);

		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'rename:1' => array('path' => $path, 'action' => 'replace-entry',
				'backup' => $backup, 'replacement' => $replacement, 'marker' => $marker),
			'unlink:1' => array('path' => $path, 'action' => 'replace-entry',
				'backup' => $backup, 'replacement' => $replacement, 'marker' => $marker),
		)));

		$this->assertEquals(0, $status, 'the scripted operation run exits normally: '.$output);
		$this->assertTrue(is_file($marker),
			'the scripted swap reaches the production mutation boundary');
		$this->assertEquals('replacement-bytes', is_file($path) ? file_get_contents($path) : null,
			'identity-bound deletion refuses the replacement installed at the public name');
		$this->assertEquals('original-bytes', is_file($backup) ? file_get_contents($backup) : null,
			'the captured inode stays isolated outside the public name');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$hash.'.list'),
			'the scripted identity swap retains the exact manifest');
	}

	// -- S03 equivalence matrices -------------------------------------------
	//
	// Each matrix asserts the observable answer of one primitive family on a
	// fixed table of rows: the return value plus what survives on disk. No row
	// names the function that produced the answer, so a consolidated
	// implementation is accepted only when it reproduces the whole table.

	// Owned-path overlap.
	private function ownershipAnswers($path, $ownedPaths)
	{
		return(array(
			'erasedataPathTouchesOwnedPaths'
				=> erasedataPathTouchesOwnedPaths($path, $ownedPaths),
		));
	}

	private function assertOwnershipRow($label, $path, $ownedPaths, $expected)
	{
		foreach($this->ownershipAnswers($path, $ownedPaths) as $name => $answer)
			$this->assertTrue($answer === $expected, $label.': '.$name.' answers '
				.($expected ? 'touches an owned path' : 'touches no owned path'));
	}

	private function ownedSet($files, $base)
	{
		return(array('files' => $files, 'base' => $base));
	}

	public function testOwnedPathOverlapMatrix()
	{
		$this->reset();
		$root = $this->dir.'/ownership';
		mkdir($root.'/tree/nested', 0777, true);
		mkdir($root.'/name');
		mkdir($root.'/name2');
		$file = $root.'/tree/nested/payload.bin';
		file_put_contents($file, 'payload');
		file_put_contents($root.'/name/keep.bin', 'keep');
		file_put_contents($root.'/name2/other.bin', 'other');
		symlink($root.'/tree', $root.'/tree-alias');
		symlink($root.'/absent-target', $root.'/dangling-alias');

		$this->assertOwnershipRow('file/file', $file,
			$this->ownedSet(array($file), $root.'/name2'), true);
		$this->assertOwnershipRow('directory contains file', $file,
			$this->ownedSet(array(), $root.'/tree'), true);
		$this->assertOwnershipRow('file under directory', $root.'/tree',
			$this->ownedSet(array($file), $root.'/name2'), true);
		$this->assertOwnershipRow('component prefix collision', $root.'/name2/other.bin',
			$this->ownedSet(array(), $root.'/name'), false);
		$this->assertOwnershipRow('component prefix collision, directories',
			$root.'/name2', $this->ownedSet(array($root.'/name/keep.bin'), $root.'/name'),
			false);
		$this->assertOwnershipRow('symlink path aliases an owned real directory',
			$root.'/tree-alias', $this->ownedSet(array(), $root.'/tree'), true);
		$this->assertOwnershipRow('real path aliases an owned symlink',
			$root.'/tree', $this->ownedSet(array($root.'/tree-alias'), $root.'/name2'), true);
		$this->assertOwnershipRow('unresolved existing path is fail-closed',
			$root.'/dangling-alias', $this->ownedSet(array(), $root.'/name'), true);
		$this->assertOwnershipRow('no owned candidate', $file, array(), false);
		$this->assertOwnershipRow('non-string base is not a candidate', $file,
			$this->ownedSet(array(), null), false);
		$this->assertOwnershipRow('unrelated tree', $root.'/tree',
			$this->ownedSet(array(), $root.'/name2'), false);
	}

	// Identity-bound deletion of one captured entry.
	private function unlinkImplementations($withUnknownIdentity)
	{
		return(array(
			'unlinkCapturedEntry' => function($path, $identity, $filesystem) {
				return($filesystem->unlinkCapturedEntry(
					$path, $identity, 'manifest-consumption'));
			},
		));
	}

	private function assertUnlinkRow($label, $build, $identityOf, $expected,
		$survivingBytes, $retainedRoots = 0)
	{
		$ordinal = 0;
		foreach($this->unlinkImplementations($identityOf === null) as $name => $unlink)
		{
			$ordinal++;
			$root = $this->dir.'/unlink-row-'.$ordinal;
			$this->removePath($root);
			mkdir($root, 0777, true);
			$filesystem = new ErasedataCollectorFixture(array());
			$path = $build($root, $filesystem);
			$identity = $identityOf === null ? null : $identityOf($path, $filesystem);
			$result = $unlink($path, $identity, $filesystem);
			$this->assertTrue($result === $expected, $label.': '.$name.' returns '
				.($expected ? 'true' : 'false'));
			$this->assertEquals($survivingBytes,
				is_file($path) ? file_get_contents($path) : null,
				$label.': '.$name.' leaves exactly the expected bytes at the public name');
			$roots = glob($root.'/.erasedata-entry-*');
			$this->assertEquals($retainedRoots, is_array($roots) ? count($roots) : 0,
				$label.': '.$name.' leaves exactly '.$retainedRoots
				.' private capture root(s) behind');
			$this->removePath($root);
		}
	}

	public function testCapturedEntryUnlinkMatrix()
	{
		$captured = function($path, $filesystem) {
			return($filesystem->entryIdentity($path));
		};
		$this->reset();

		$this->assertUnlinkRow('captured identity', function($root, $filesystem) {
			$path = $root.'/payload.bin';
			file_put_contents($path, 'payload');
			return($path);
		}, $captured, true, null);

		$this->assertUnlinkRow('replaced inode', function($root, $filesystem) {
			$path = $root.'/replaced.bin';
			file_put_contents($path, 'original');
			return($path);
		}, function($path, $filesystem) {
			$stale = $filesystem->entryIdentity($path);
			// Allocate the impostor WHILE the victim is still alive, then move
			// it over the name. Unlinking first and recreating frees the inode,
			// and an idle filesystem hands the same number straight back -- the
			// container image does exactly that on /config, /data and /tmp,
			// which made this row pass on a busy host and fail everywhere else.
			// Production never has that ambiguity: the capture protocol renames
			// the entry into its private root, so the captured inode stays
			// alive and a replacement cannot collide with it.
			$impostor = $path.'.impostor';
			file_put_contents($impostor, 'replacement');
			@unlink($path);
			rename($impostor, $path);
			$fresh = $filesystem->entryIdentity($path);
			$this->assertTrue(is_array($fresh) && is_array($stale)
				&& erasedataEntryIdentityParts($fresh) !== erasedataEntryIdentityParts($stale),
				'replaced inode: the fixture really did install a different inode');
			return($stale);
		}, false, 'replacement', 1);

		$this->assertUnlinkRow('unknown identity, absent name',
			function($root, $filesystem) {
				return($root.'/absent.bin');
			}, null, true, null);

		$this->assertUnlinkRow('unknown identity, existing name',
			function($root, $filesystem) {
				$path = $root.'/present.bin';
				file_put_contents($path, 'present');
				return($path);
			}, null, false, 'present');
	}

	// Private-container cleanup.
	private function cleanupRemovers()
	{
		return(array(
			'removePrivateContainer' => function($root, $allowed, $filesystem) {
				return($filesystem->removePrivateContainer($root, $allowed));
			},
			'recovery capture container' => function($root, $allowed, $filesystem) {
				return($this->removeRecoveryCaptureContainer($root, $filesystem));
			},
			'recovery reservation container' => function($root, $allowed, $filesystem) {
				return($this->removeRecoveryReservationContainer($root, $filesystem));
			},
		));
	}

	// Builds one private protocol container. $entries maps a name to 'file',
	// 'directory' or a symlink target.
	private function makePrivateContainer($name, $marker = 'file', array $entries = array())
	{
		$root = $this->dir.'/'.$name;
		$this->removePath($root);
		mkdir($root, 0700);
		if($marker === 'file')
			file_put_contents($root.'/.initialized', '');
		else if($marker === 'symlink')
			symlink($this->dir.'/marker-victim.bin', $root.'/.initialized');
		foreach($entries as $entry => $kind)
		{
			if($kind === 'file')
				file_put_contents($root.'/'.$entry, 'unexpected');
			else if($kind === 'directory')
				mkdir($root.'/'.$entry);
			else
				symlink($kind, $root.'/'.$entry);
		}
		return($root);
	}

	private function containerEntries($root)
	{
		clearstatcache();
		$entries = @scandir($root);
		if(!is_array($entries))
			return(false);
		$entries = array_values(array_diff($entries, array('.', '..')));
		sort($entries, SORT_STRING);
		return($entries);
	}

	// $survivors is null when the row must remove the container outright.
	private function assertCleanupRow($label, $marker, array $entries, $expected,
		$survivors, array $scenario = array())
	{
		$ordinal = 0;
		foreach($this->cleanupRemovers() as $name => $remover)
		{
			$ordinal++;
			$root = $this->makePrivateContainer('cleanup-row-'.$ordinal, $marker, $entries);
			$filesystem = new ErasedataCollectorFixture($scenario);
			$result = $remover($root, array('.', '..', '.initialized'), $filesystem);
			$this->assertTrue($result === $expected, $label.': '.$name.' returns '
				.($expected ? 'true' : 'false'));
			$this->assertTrue(erasedataPathExists($root) === ($survivors !== null),
				$label.': '.$name.' '.($survivors === null ? 'removes' : 'retains')
				.' the container');
			if($survivors !== null)
			{
				$expectedEntries = $survivors;
				sort($expectedEntries, SORT_STRING);
				$this->assertEquals($expectedEntries, $this->containerEntries($root),
					$label.': '.$name.' leaves exactly the entries it may not delete');
			}
			$this->removePath($root);
		}
	}

	public function testPrivateContainerCleanupMatrix()
	{
		$this->reset();
		$victim = $this->dir.'/marker-victim.bin';
		file_put_contents($victim, 'victim-bytes');

		$this->assertCleanupRow('marker only', 'file', array(), true, null);
		$this->assertCleanupRow('unknown entry', 'file',
			array('unexpected.bin' => 'file'), false,
			array('.initialized', 'unexpected.bin'));
		$this->assertCleanupRow('unknown directory entry', 'file',
			array('payload' => 'directory'), false,
			array('.initialized', 'payload'));
		$this->assertCleanupRow('marker collision', 'symlink', array(), false,
			array('.initialized'));
		$this->assertCleanupRow('removal failure', 'file', array(), false, array(),
			array('removeDirectory:*' => array('result' => false)));
		$this->assertCleanupRow('marker unlink failure', 'file', array(), false,
			array('.initialized'), array('unlink:*' => array('result' => false)));

		$this->assertEquals('victim-bytes', is_file($victim) ? file_get_contents($victim) : null,
			'private cleanup never follows a planted marker symlink');
	}

	public function testPrivateContainerAllowlistNeverAuthorizesDeletion()
	{
		$this->reset();
		$filesystem = new ErasedataCollectorFixture(array());

		$tombstoneRoot = $this->makePrivateContainer('cleanup-tombstone', 'file',
			array('.bridge-1-2' => $this->dir.'/bridge-target'));
		$this->assertTrue($filesystem->removePrivateContainer($tombstoneRoot,
			array('.', '..', '.initialized', '.bridge-1-2')) === false,
			'allowed tombstone: an allowlisted entry still blocks the container removal');
		$this->assertEquals(array('.bridge-1-2'), $this->containerEntries($tombstoneRoot),
			'allowed tombstone: the allowlisted entry is never unlinked');

		$bridgeRoot = $this->makePrivateContainer('cleanup-bridge', 'file',
			array('directory' => 'directory'));
		$this->assertTrue($filesystem->removePrivateContainer($bridgeRoot,
			array('.', '..', '.initialized', 'directory')) === false,
			'allowed bridge: an allowlisted data entry still blocks the container removal');
		$this->assertEquals(array('directory'), $this->containerEntries($bridgeRoot),
			'allowed bridge: the allowlisted data entry is never removed');
	}

	public function testAbsentPrivateContainerCleanupIsIdempotentForRecovery()
	{
		$this->reset();
		$filesystem = new ErasedataCollectorFixture(array());
		$absent = $this->dir.'/cleanup-absent';

		// The owner itself stays fail-closed: it cannot enumerate a name that is
		// not there. The recovery layout treats an absent shell as finished.
		$this->assertTrue($filesystem->removePrivateContainer(
			$absent, array('.', '..', '.initialized')) === false,
			'the container owner refuses a name it cannot enumerate');
		$this->assertTrue($this->removeRecoveryCaptureContainer($absent, $filesystem) === true,
			'an absent capture root completes the recovery cleanup');
		$this->assertTrue($this->removeRecoveryReservationContainer($absent, $filesystem) === true,
			'an absent reservation root completes the recovery cleanup');
		$this->assertTrue($this->removeRecoveryReservationContainer(null, $filesystem) === true,
			'a recovery layout without a reservation root completes the cleanup');
		$this->assertTrue(!erasedataPathExists($absent),
			'the absent container is never created by the cleanup');
	}

	private function removeRecoveryCaptureContainer($root, $filesystem)
	{
		return(erasedataRemoveRecoveryContainers(
			array('captureRoot' => $root, 'reservationRoot' => null), $filesystem));
	}

	private function removeRecoveryReservationContainer($root, $filesystem)
	{
		return(erasedataRemoveRecoveryContainers(array(
			'captureRoot' => $this->dir.'/recovery-capture-absent',
			'reservationRoot' => $root), $filesystem));
	}

	// The captured-entry name record: bounded, canonical base64 only, and never
	// a path.
	private function captureNameRoot($name, $encoded)
	{
		$root = $this->dir.'/'.$name;
		$this->removePath($root);
		mkdir($root, 0700);
		file_put_contents($root.'/.initialized', '');
		file_put_contents($root.'/.name', $encoded);
		return($root);
	}

	public function testCapturedEntryNameRecordMatrix()
	{
		$this->reset();
		$rows = array(
			'canonical name' => array(base64_encode('payload.bin'), 'payload.bin'),
			'exact ceiling' => array(
				base64_encode(str_repeat('a', 3072)), str_repeat('a', 3072)),
			'over the ceiling' => array(
				base64_encode(str_repeat('a', 4096)), false),
			'non-canonical base64' => array(base64_encode('payload.bin')."\n", false),
			'not base64 at all' => array('payload.bin', false),
			'empty record' => array('', false),
			'separator in the name' => array(base64_encode('a/b'), false),
			'nul in the name' => array(base64_encode("a\0b"), false),
			'dot name' => array(base64_encode('.'), false),
			'dotdot name' => array(base64_encode('..'), false),
		);
		$ordinal = 0;
		foreach($rows as $label => $row)
		{
			$ordinal++;
			$root = $this->captureNameRoot('capture-name-'.$ordinal, $row[0]);
			$this->assertTrue(erasedataCapturedEntryName($root) === $row[1],
				'captured name record, '.$label.': the decoded name is exact');
			$this->removePath($root);
		}

		$root = $this->captureNameRoot('capture-name-unmarked', base64_encode('payload.bin'));
		@unlink($root.'/.initialized');
		$this->assertTrue(erasedataCapturedEntryName($root) === false,
			'captured name record: an unmarked root has no readable name');
		$this->removePath($root);
	}

	// Staging publication: one canonical .tmp -> .list promotion.
	private function publishStagingUnderTest($tmpPath, $hash)
	{
		return(ErasedataManifestCodec::publishStaging($tmpPath, $hash));
	}

	private function stagedManifest($hash, $suffix, $contents)
	{
		$path = $this->dir.'/erasedata/'.$hash.'.'.$suffix;
		file_put_contents($path, $contents);
		@chmod($path, 0600);
		return($path);
	}

	public function testStagingPublicationMatrix()
	{
		global $profileMask;
		$this->reset();
		$profileMask = 0777;
		$hash = $this->hash('C');
		$queue = $this->dir.'/erasedata';

		$tmp = $this->stagedManifest($hash, '1.aaaa.tmp', 'canonical-bytes');
		$this->assertTrue($this->publishStagingUnderTest($tmp, $hash) === true,
			'canonical tmp: the staged manifest is published');
		$list = $queue.'/'.$hash.'.1.aaaa.list';
		$this->assertTrue(!file_exists($tmp) && is_file($list),
			'canonical tmp: the staging name is consumed and the list name appears');
		$this->assertEquals('canonical-bytes', file_get_contents($list),
			'canonical tmp: the published bytes are the staged bytes');
		$this->assertEquals(0666, $this->modeOf($list),
			'canonical tmp: publication repairs the shared file mode');
		@unlink($list);

		$tmp = $this->stagedManifest($hash, '2.bbbb.tmp', 'staged-bytes');
		$list = $this->stagedManifest($hash, '2.bbbb.list', 'published-bytes');
		$this->assertTrue($this->publishStagingUnderTest($tmp, $hash) === false,
			'existing list: publication refuses to replace a published generation');
		$this->assertEquals('staged-bytes', is_file($tmp) ? file_get_contents($tmp) : null,
			'existing list: the staged bytes are retained');
		$this->assertEquals('published-bytes', is_file($list) ? file_get_contents($list) : null,
			'existing list: the published bytes are untouched');
		@unlink($tmp);
		@unlink($list);

		$victim = $this->dir.'/publish-victim.bin';
		file_put_contents($victim, 'victim-bytes');
		$tmp = $this->stagedManifest($hash, '3.cccc.tmp', 'staged-bytes');
		$list = $queue.'/'.$hash.'.3.cccc.list';
		symlink($victim, $list);
		$this->assertTrue($this->publishStagingUnderTest($tmp, $hash) === false,
			'symlink list: publication refuses a planted list symlink');
		$this->assertEquals('staged-bytes', is_file($tmp) ? file_get_contents($tmp) : null,
			'symlink list: the staged bytes are retained');
		$this->assertTrue(is_link($list),
			'symlink list: the planted link is neither followed nor replaced');
		$this->assertEquals('victim-bytes', file_get_contents($victim),
			'symlink list: the link target keeps its exact bytes');
		@unlink($list);
		@unlink($tmp);

		$tmp = $this->stagedManifest($hash, '4.dddd.tmp', 'staged-bytes');
		@chmod($queue, 0500);
		$published = $this->publishStagingUnderTest($tmp, $hash);
		@chmod($queue, 0777);
		$this->assertTrue($published === false,
			'rename failure: an unpublishable staging file reports failure');
		$this->assertEquals('staged-bytes', is_file($tmp) ? file_get_contents($tmp) : null,
			'rename failure: the staged bytes are retained for retry');
		$this->assertTrue(!file_exists($queue.'/'.$hash.'.4.dddd.list'),
			'rename failure: no list name is created');
		@unlink($tmp);

		$first = $this->stagedManifest($hash, '5.eeee.tmp', 'first-generation');
		$second = $this->stagedManifest($hash, '6.ffff.tmp', 'second-generation');
		$this->assertTrue($this->publishStagingUnderTest($first, $hash) === true
			&& $this->publishStagingUnderTest($second, $hash) === true,
			'same-hash generations: every generation publishes under its own name');
		$this->assertEquals('first-generation',
			file_get_contents($queue.'/'.$hash.'.5.eeee.list'),
			'same-hash generations: the first generation keeps its own bytes');
		$this->assertEquals('second-generation',
			file_get_contents($queue.'/'.$hash.'.6.ffff.list'),
			'same-hash generations: the second generation keeps its own bytes');
		@unlink($queue.'/'.$hash.'.5.eeee.list');
		@unlink($queue.'/'.$hash.'.6.ffff.list');

		$list = $this->stagedManifest($hash, '7.gggg.list', 'published-bytes');
		$this->assertTrue($this->publishStagingUnderTest($list, $hash) === false,
			'non-staging name: only a .tmp name can be promoted');
		$this->assertEquals('published-bytes', file_get_contents($list),
			'non-staging name: the inspected file is untouched');
		@unlink($list);

		$absent = $queue.'/'.$hash.'.8.hhhh.tmp';
		$this->assertTrue($this->publishStagingUnderTest($absent, $hash) === false,
			'absent staging file: publication reports failure');
		$this->assertTrue(!file_exists($queue.'/'.$hash.'.8.hhhh.list'),
			'absent staging file: no list name is created');
	}
}
