<?php

require_once(__DIR__ . '/../../php/TestCase.php');

// Stub the dependencies the production callers (httprpc/action.php,
// plugins/erasedata/action.php) load before invoking the helper. The RPC layer
// is scripted per command so the helper's own logic is what gets exercised.
if(!class_exists('FileUtil'))
{
	class FileUtil
	{
		public static $settingsPath = null;
		public static $log = array();
		public static function getSettingsPath() { return self::$settingsPath; }
		public static function makeDirectory($dir) { return @mkdir($dir, 0777, true); }
		public static function toLog($msg) { self::$log[] = $msg; }
		public static function getPluginConf($plugin) { return('$enableForceDeletion = true; $erasedebug_enabled = false;'); }
	}
}
if(!class_exists('rXMLRPCCommand'))
{
	class rXMLRPCCommand
	{
		public $command;
		public $params;
		public function __construct($command, $params = null)
		{
			$this->command = $command;
			$this->params = $params;
		}
	}
}
if(!class_exists('rXMLRPCRequest'))
{
	class rXMLRPCRequest
	{
		public static $responses = array();	// first command name => array(runResult, fault, val, faultString, faultCode)
		public static $requested = array();	// first command name of each request, in order
		public static $erased = array();	// hashes passed to d.erase
		public static $commandCalls = array();

		public $val = array();
		public $fault = false;
		public $faultString = '';
		public $rawFaultString = null;
		public $faultCode = 0;
		public $important = true;
		private $commands = array();

		public function __construct($commands = null)
		{
			if(is_array($commands))
				$this->commands = $commands;
			else if(!is_null($commands))
				$this->commands = array($commands);
		}
		public function addCommand($command)
		{
			$this->commands[] = $command;
		}
		public function run($trusted = true)
		{
			if(!count($this->commands))
				return(false);
			$first = $this->commands[0]->command;
			self::$requested[] = $first;
			self::$commandCalls[] = $this->commands;
			foreach($this->commands as $c)
				if($c->command == "d.erase")
					self::$erased[] = $c->params;
			if(!array_key_exists($first, self::$responses))
				return(false);
			$response = self::$responses[$first];
			if(isset($response['byHash']) && isset($this->commands[0]->params)
				&& array_key_exists((string)$this->commands[0]->params, $response['byHash']))
				$response = $response['byHash'][(string)$this->commands[0]->params];
			if(isset($response["callback"]) && is_callable($response["callback"]))
				call_user_func($response["callback"], $this->commands);
			$this->val = isset($response["val"]) ? $response["val"] : array();
			$this->fault = isset($response["fault"]) ? $response["fault"] : false;
			$this->faultString = isset($response["faultString"]) ? $response["faultString"] : '';
			$this->rawFaultString = array_key_exists("rawFaultString", $response)
				? $response["rawFaultString"] : null;
			$this->faultCode = isset($response["faultCode"]) ? $response["faultCode"] : 0;
			if(isset($response["runResult"]))
				return($response["runResult"]);
			return(isset($response["ok"]) ? $response["ok"] : true);
		}
		public function success($trusted = true)
		{
			return($this->run($trusted) && !$this->fault);
		}
	}
}
if(!function_exists('getCmd'))
{
	function getCmd($cmd) { return($cmd); }
}
if(!class_exists('Utility'))
{
	class Utility { public static function getPHP() { return(PHP_BINARY); } }
}
if(!class_exists('User'))
{
	class User { public static function getUser() { return('rutorrent'); } }
}

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
require_once(__DIR__ . '/../../../plugins/erasedata/filesystem.php');
require_once(__DIR__ . '/../../../plugins/erasedata/manifest.php');
require_once(__DIR__ . '/../../../plugins/erasedata/removewithdata.php');

class RemoveWithDataTest extends TestCase
{
	private $dir;
	private $filesystemScenario = array();

	public function setUp()
	{
		$this->dir = sys_get_temp_dir().'/erasedata-test-'.getmypid();
		@mkdir($this->dir, 0777, true);
		FileUtil::$settingsPath = $this->dir;
	}

	// setUp() runs once per class, so each test starts from a clean slate here.
	private function reset()
	{
		global $profileMask, $erasedataCleanupPublicationPhaseOverride,
			$erasedataBeforeUnlinkExactStagedFileOverride;
		$profileMask = 0777;
		$erasedataCleanupPublicationPhaseOverride = null;
		$erasedataBeforeUnlinkExactStagedFileOverride = null;
		foreach(array_diff(scandir($this->dir), array('.', '..', 'erasedata')) as $entry)
			$this->removePath($this->dir.'/'.$entry);
		@mkdir($this->dir.'/erasedata', 0777, true);
		foreach(array_diff(scandir($this->dir.'/erasedata'), array('.', '..')) as $entry)
			$this->removePath($this->dir.'/erasedata/'.$entry);
		FileUtil::$log = array();
		rXMLRPCRequest::$responses = array();
		rXMLRPCRequest::$requested = array();
		rXMLRPCRequest::$erased = array();
		$this->filesystemScenario = array();
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
		$code = 'set_include_path('.var_export($fixture, true).');'
			.'require '.var_export($fixture.'/xmlrpc.php', true).';'
			.'$fault=base64_decode('.var_export(base64_encode($faultString), true).');'
			.'$escaped=htmlspecialchars($fault,ENT_NOQUOTES,"UTF-8");'
			.'rSCGITransport::$raw="<methodResponse><fault><value><struct>".'
			.'"<member><name>faultCode</name><value><i4>-501</i4></value></member>".'
			.'"<member><name>faultString</name><value><string>".$escaped."</string></value></member>".'
			.'"</struct></value></fault></methodResponse>";'
			.'$rpcLogCalls=false;$rpcLogFaults=false;$rpcTimeOut=1;$scgi_host="";$scgi_port=0;'
			.'$request=new rXMLRPCRequest(new rXMLRPCCommand("d.hash",str_repeat("A",40)));'
			.'$run=$request->run();echo json_encode(array("run"=>$run,"fault"=>$request->fault,'
			.'"faultString"=>$request->faultString,"rawFaultString"=>property_exists($request,"rawFaultString")'
			.'?$request->rawFaultString:null));';
		$output = array();
		$status = 0;
		exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -r '.escapeshellarg($code).' 2>&1', $output, $status);
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
		$code = '$HTTP_RAW_POST_DATA='.var_export($rawBody, true).';chdir('.var_export($actionDir, true).');'
			.'require '.var_export($actionDir.'/action.php', true).';file_put_contents('
			.var_export($commandLog, true).',json_encode(rXMLRPCRequest::$commands));';
		$output = array();
		$status = 0;
		exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -r '.escapeshellarg($code).' 2>&1', $output, $status);
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

	private function runCollector($ok, $fault, $val, $swap = null, $owned = null,
		$rmdirFail = null, $rmdirSwap = null, $rmdirCrash = null, $restoreCollision = null,
		$reservedSwap = null, $forceTargetSwap = null, $forceTargetRecreate = null,
		$forceTraverseSwap = null, $cleanupCrash = null, $forceCaptureCollision = null,
		$reservationInitCrash = null, $forceCaptureInitCrash = null,
		$containerRemovalFail = null, $faultString = '', $generation = null, $onlyHash = null,
		$debug = false, $cleanupUnlinkFail = null, $cleanupPublicationPhase = null,
		$captureLogs = false, $indexCountFile = null, $commitTokenUnlinkFail = null,
		$artifactReadCountFile = null, $successorOverride = null, $successorTransition = null,
		$successorObservationCountFile = null, $publicCollectorHash = null)
	{
		global $profileMask;
		// Existing filesystem scenarios use this tuple as semantic absence.
		// Exercise it through the production-observed fault, never clean empty.
		if($ok === true && $fault === false && $val === array('') && $faultString === '')
		{
			$fault = true;
			$val = array();
			$faultString = 'invalid parameters: info-hash not found';
		}
		$successor = $this->cleanupSuccessorFixture($val, $owned, $successorOverride);
		$state = base64_encode(serialize(array(
			'settings' => $this->dir,
			'profileMask' => $profileMask,
			'filesystemScenario' => $this->filesystemScenario,
			'debug' => $debug,
			'source' => is_array($successor) && array_key_exists('source', $successor)
				? $successor['source'] : false,
			'responses' => array(
				'd.hash' => array('ok'=>$ok, 'fault'=>$fault, 'val'=>$val, 'swap'=>$swap, 'faultString'=>$faultString, 'byHash'=>$generation),
				'd.get_base_path' => is_array($successor) && isset($successor['frozen'])
					? $successor['frozen'] + array('swap'=>null)
					: array('ok'=>false, 'fault'=>false, 'val'=>array(), 'swap'=>null),
				'd.get_directory' => is_array($successor) && isset($successor['stored'])
					? $successor['stored'] + array('swap'=>null)
					: array('ok'=>false, 'fault'=>false, 'val'=>array(), 'swap'=>null),
			),
		)));
		$update = realpath(__DIR__.'/../../../plugins/erasedata/update.php');
		$code = '$state=unserialize(base64_decode('.var_export($state, true).'));'.
			'$cleanupPublicationPhase='.var_export($cleanupPublicationPhase, true).';'.
			'$successorTransition='.var_export($successorTransition, true).';'.
			'$successorObservationCountFile='.var_export($successorObservationCountFile, true).';'.
			'$erasedataCleanupSuccessorObservationOverride='.
				var_export($successorObservationCountFile === null
					? null : 'erasedataCleanupSuccessorObservationRecorded', true).';'.
			'function erasedataCleanupSuccessorObservationRecorded($path){global $successorObservationCountFile;'.
				'if($successorObservationCountFile!==null)@file_put_contents($successorObservationCountFile,"1\n",FILE_APPEND);}'.
			'$indexCountFile='.var_export($indexCountFile, true).';'.
			'$artifactReadCountFile='.var_export($artifactReadCountFile, true).';'.
			'$erasedataBeforeReadExactCleanupFile='.var_export(
				$artifactReadCountFile === null ? null : 'erasedataBeforeReadExactCleanupFile', true).';'.
			'function erasedataCleanupPublicationPhase($phase,$context){global $cleanupPublicationPhase;'.
				'if($cleanupPublicationPhase!==null&&$phase===$cleanupPublicationPhase)exit(0);return true;}'.
			'function erasedataCollectorIndexBuilt($path){global $indexCountFile;'.
				'if($indexCountFile!==null)@file_put_contents($indexCountFile,"1\\n",FILE_APPEND);return true;}'.
			'function erasedataBeforeReadExactCleanupFile($candidate){global $artifactReadCountFile;'.
				'if($artifactReadCountFile!==null)@file_put_contents($artifactReadCountFile,"1\\n",FILE_APPEND);return true;}'.
			($rmdirFail !== null || $rmdirSwap !== null || $rmdirCrash !== null
				|| $restoreCollision !== null || $reservedSwap !== null
				|| $forceTargetSwap !== null || $forceTargetRecreate !== null
				|| $forceTraverseSwap !== null || $cleanupCrash !== null
				|| $forceCaptureCollision !== null || $reservationInitCrash !== null
				|| $forceCaptureInitCrash !== null || $containerRemovalFail !== null || $cleanupUnlinkFail !== null
				|| $successorTransition !== null
				|| $commitTokenUnlinkFail !== null
				? '$rmdirFail='.var_export($rmdirFail, true).';$rmdirSwap='.var_export($rmdirSwap, true).';'.
					'$rmdirCrash='.var_export($rmdirCrash, true).';$restoreCollision='.var_export($restoreCollision, true).';'.
					'$reservedSwap='.var_export($reservedSwap, true).';'.
					'$forceTargetSwap='.var_export($forceTargetSwap, true).';'.
					'$forceTargetRecreate='.var_export($forceTargetRecreate, true).';'.
					'$forceTraverseSwap='.var_export($forceTraverseSwap, true).';'.
					'$cleanupCrash='.var_export($cleanupCrash, true).';'.
					'$forceCaptureCollision='.var_export($forceCaptureCollision, true).';'.
					'$reservationInitCrash='.var_export($reservationInitCrash, true).';'.
					'$forceCaptureInitCrash='.var_export($forceCaptureInitCrash, true).';'.
					'$containerRemovalFail='.var_export($containerRemovalFail, true).';'.
					'$cleanupUnlinkFail='.var_export($cleanupUnlinkFail, true).';'.
					'$commitTokenUnlinkFail='.var_export($commitTokenUnlinkFail, true).';'.
					'function erasedataDirectoryRemovalOverride($path,$reserved){global $rmdirFail,$rmdirCrash,$reservedSwap;'.
					'if($path===$rmdirCrash){@file_put_contents($reserved."/crash-data.bin","reserved-bytes");exit(0);}'.
					'if($path===$reservedSwap){@rename($reserved,$reserved.".checked");@mkdir($reserved);}'.
					'return $path===$rmdirFail?false:null;}'.
					'function erasedataBeforeReserveDirectory($path){global $rmdirSwap;'.
					'if($rmdirSwap!==null&&$path===$rmdirSwap){@rename($path,$path.".checked");@mkdir($path);}return true;}'.
					'function erasedataBeforeRestoreDirectory($reserved,$path){global $restoreCollision;'.
					'if($restoreCollision!==null&&$path===$restoreCollision){@mkdir($path);$stat=@lstat($path);'.
					'if(is_array($stat))@file_put_contents($path.".collision-inode",(string)$stat["ino"]);}return true;}'.
					'function erasedataBeforeDeleteRecoveryDirectory($path){global $forceTargetSwap;'.
					'if($forceTargetSwap!==null){@rename($path,$path.".checked");@mkdir($path);'.
					'@file_put_contents($path."/replacement.bin","replacement");}return true;}'.
					'function erasedataAfterDeleteRecoveryDirectory($path){global $forceTargetRecreate;'.
					'if($forceTargetRecreate!==null){@mkdir($path);'.
					'@file_put_contents($path."/recreated.bin","recreated");}return true;}'.
					'function erasedataBeforeTraverseCapturedRecoveryDirectory($path){global $forceTraverseSwap;'.
					'if($forceTraverseSwap!==null){@rename($path,$path.".checked");@mkdir($path);'.
					'if($forceTraverseSwap!=="empty")@file_put_contents($path."/replacement.bin","replacement");'.
					'$stat=@lstat($path);if(is_array($stat))@file_put_contents($path.".collision-inode",(string)$stat["ino"]);}return true;}'.
					'function erasedataBeforeCreateRecoveryCapture($path){global $forceCaptureCollision;'.
					'if($forceCaptureCollision!==null){@mkdir($path);$stat=@lstat($path);'.
					'if(is_array($stat))@file_put_contents($path.".collision-inode",(string)$stat["ino"]);}return true;}'.
					'function erasedataAfterCreateDirectoryReservation($path){global $reservationInitCrash;'.
					'if($reservationInitCrash==="created")exit(0);return true;}'.
					'function erasedataAfterInitializeDirectoryReservation($path){global $reservationInitCrash;'.
					'if($reservationInitCrash==="initialized")exit(0);return true;}'.
					'function erasedataAfterCreateRecoveryCapture($path){global $forceCaptureInitCrash;'.
					'if($forceCaptureInitCrash==="created")exit(0);return true;}'.
					'function erasedataAfterInitializeRecoveryCapture($path){global $forceCaptureInitCrash;'.
					'if($forceCaptureInitCrash==="initialized")exit(0);return true;}'.
					'function erasedataContainerRemovalOverride($path){global $containerRemovalFail;'.
					'return $containerRemovalFail!==null?false:null;}'.
					'function erasedataAfterUnlinkRecoveryTombstone($path){global $cleanupCrash;'.
					'if($cleanupCrash==="tombstone")exit(0);return true;}'.
					'function erasedataAfterUnlinkRecoveryBridge($path){global $cleanupCrash;'.
					'if($cleanupCrash==="bridge")exit(0);return true;}'.
					'function erasedataAfterRemoveRecoveryContainer($path){global $cleanupCrash;'.
					'if($cleanupCrash==="container")exit(0);return true;}'.
					'function erasedataBeforeCleanupUnlink($path,$expected){global $cleanupUnlinkFail,$successorTransition;'.
						'if(is_array($successorTransition)&&isset($successorTransition["kind"],$successorTransition["new"],$successorTransition["old"])'.
						'&&(!isset($successorTransition["trigger"])||$successorTransition["trigger"]===$path)){' .
						'$new=$successorTransition["new"];$old=$successorTransition["old"];'.
						'if($successorTransition["kind"]==="missing-to-symlink")@symlink($old,$new);'.
						'else if($successorTransition["kind"]==="missing-to-hardlink")@link($old,$new);'.
						'else if($successorTransition["kind"]==="alias-to-distinct"){@unlink($new);@file_put_contents($new,"distinct");}'.
						'else if($successorTransition["kind"]==="alias-to-missing")@unlink($new);'.
						'$successorTransition=null;}'.
						'return $path===$cleanupUnlinkFail?false:true;}'.
					'function erasedataBeforeUnlinkExactStagedFile($path,$expected){global $commitTokenUnlinkFail;'.
						'return $path===$commitTokenUnlinkFail?false:true;}'
				: '').
			'class FileUtil { public static $settingsPath; public static $log=array();'.
			'public static function getSettingsPath(){return self::$settingsPath;}'.
			'public static function getProfilePath(){return dirname(self::$settingsPath);}'.
			'public static function getConfFile($name){return false;}'.
			'public static function getPluginConf($plugin){return '.var_export('$enableForceDeletion=true;$erasedebug_enabled='
				.($debug ? 'true' : 'false').';', true).';}'.
			'public static function makeDirectory($dir){return @mkdir($dir,0777,true);}'.
			'public static function toLog($msg){self::$log[]=$msg;}}'.
			'class rXMLRPCCommand { public $command; public $params;'.
			'public function __construct($command,$params=null){$this->command=$command;$this->params=$params;}}'.
			'class rXMLRPCRequest { public static $responses; public $val=array(); public $fault=false; public $faultString=""; public $important=true; private $commands=array();'.
			'public function __construct($commands=null){if(is_array($commands))$this->commands=$commands;else if($commands!==null)$this->commands=array($commands);}'.
			'public function run($trusted=true){$first=count($this->commands)?$this->commands[0]->command:"";'.
			'$response=isset(self::$responses[$first])?self::$responses[$first]:array("ok"=>false,"fault"=>false,"val"=>array(),"swap"=>null,"faultString"=>"");'.
			'if($first==="d.hash"&&isset($response["byHash"])&&isset($this->commands[0]->params)&&array_key_exists((string)$this->commands[0]->params,$response["byHash"])){'.
				'$entry=$response["byHash"][(string)$this->commands[0]->params];'.
				'if(isset($entry["presence"])||isset($entry["generation"])){$generationRequest=count($this->commands)>1&&$this->commands[1]->command==="d.get_custom";'.
				'$response=$generationRequest?(isset($entry["generation"])?$entry["generation"]:$response):(isset($entry["presence"])?$entry["presence"]:$response);}'.
				'else $response=$entry;}'.
			'if($first==="d.hash"&&isset($response["swap"])&&$response["swap"]!==null){'.
			'if(isset($response["swap"][2])&&$response["swap"][2]==="rewrite"){@file_put_contents($response["swap"][0],@file_get_contents($response["swap"][1]));}'.
			'else{@unlink($response["swap"][0]);if(isset($response["swap"][2])&&$response["swap"][2]==="rename"){@rename($response["swap"][1],$response["swap"][0]);}'.
			'else{@symlink($response["swap"][1],$response["swap"][0]);}}}'.
			'$this->val=$response["val"];$this->fault=$response["fault"];$this->faultString=isset($response["faultString"])?$response["faultString"]:"";return $response["ok"];}'.
			'public function success($trusted=true){return $this->run($trusted) && !$this->fault;}}'.
			'class ErasedataCleanupTestSource { public $info; private $hash;'.
			'public function __construct($source){$this->info=$source["info"];$this->hash=$source["hash"];}public function hash_info(){return $this->hash;}}'.
			'function erasedataLoadTorrentSource($hash){global $state;return is_array($state["source"])?new ErasedataCleanupTestSource($state["source"]):false;}'.
			'function getCmd($cmd){return $cmd;}'.
			'$profileMask=$state["profileMask"];FileUtil::$settingsPath=$state["settings"];rXMLRPCRequest::$responses=$state["responses"];'.
			($onlyHash === null ? '' : '$argv=array("update.php","rutorrent",'.var_export($onlyHash, true).');').
			'require '.var_export($update, true).';'.
			'class ScriptedErasedataFilesystemOps extends ErasedataFilesystemOps {' .
			'private $scenario;private $triggered=false;private $scanned=false;'.
			'public function __construct($scenario){$this->scenario=is_array($scenario)?$scenario:array();}'.
			'private function action(){return isset($this->scenario["action"])?$this->scenario["action"]:"";}'.
			'private function pathMatches($path){if(isset($this->scenario["path"])&&$path===$this->scenario["path"])return true;'.
			'return $this->scanned&&isset($this->scenario["child"])&&basename($path)===$this->scenario["child"];}'.
			'private function injectSwap($path){if($this->triggered)return;'.
			'$backup=isset($this->scenario["backup"])?$this->scenario["backup"]:"";'.
			'if($backup===""||!parent::rename($path,$backup))return;'.
			'$ok=false;if(isset($this->scenario["symlinkTarget"]))'.
			'$ok=parent::symlink($this->scenario["symlinkTarget"],$path);'.
			'else if(isset($this->scenario["replacement"]))'.
			'$ok=parent::rename($this->scenario["replacement"],$path);'.
			'if(!$ok){parent::rename($backup,$path);return;}$this->triggered=true;'.
			'if(isset($this->scenario["marker"]))@file_put_contents($this->scenario["marker"],"triggered");}'.
			'private function injectPublicReplacement(){if($this->triggered'.
			'||!isset($this->scenario["path"])||!isset($this->scenario["replacement"]))return;'.
			'$path=$this->scenario["path"];$replacement=$this->scenario["replacement"];'.
			'if(is_link($path)&&!parent::unlink($path))return;'.
			'if(file_exists($path)||is_link($path)||!parent::rename($replacement,$path))return;'.
			'$this->triggered=true;if(isset($this->scenario["marker"]))'.
			'@file_put_contents($this->scenario["marker"],"triggered");}'.
			'public function scanDirectory($path){$entries=parent::scanDirectory($path);'.
			'if(is_array($entries)&&isset($this->scenario["child"])&&in_array($this->scenario["child"],$entries,true))'.
			'$this->scanned=true;return $entries;}'.
			'public function rename($from,$to){$action=$this->action();'.
			'if(in_array($action,array("swap_before_capture","swap_to_symlink_before_capture","nested_swap_after_scan",'.
			'"swap_manifest_before_mutation"),true)'.
			'&&$this->pathMatches($from))$this->injectSwap($from);'.
			'$result=parent::rename($from,$to);'.
			'if($result&&$action==="recreate_public_after_capture"&&$this->pathMatches($from)){'.
			'@mkdir($from,0777,true);if(isset($this->scenario["sentinel"]))'.
			'@file_put_contents($from."/sentinel.bin",$this->scenario["sentinel"]);'.
			'if(isset($this->scenario["marker"]))@file_put_contents($this->scenario["marker"],"triggered");}'.
			'if($result&&$action==="crash_after_capture"&&$this->pathMatches($from)){'.
			'if(isset($this->scenario["marker"]))@file_put_contents($this->scenario["marker"],"triggered");exit(0);}return $result;}'.
			'public function unlink($path){$action=$this->action();'.
			'if(in_array($action,array("swap_before_capture","nested_swap_after_scan",'.
			'"swap_manifest_before_mutation"),true)'.
			'&&$this->pathMatches($path))$this->injectSwap($path);return parent::unlink($path);}'.
			'public function rmdir($path){if($this->action()==="swap_recovery_before_rmdir")'.
			'{if($this->pathMatches($path))$this->injectSwap($path);'.
			'else if(basename($path)==="entry"&&strpos(dirname($path),"/.erasedata-entry-")!==false)'.
			'$this->injectPublicReplacement();}return parent::rmdir($path);}'.
			'public function openDirectoryReference($path,$expectedIdentity){'.
			'if($this->action()==="directory_reference_unavailable")'.
			'{if(isset($this->scenario["marker"]))@file_put_contents($this->scenario["marker"],"triggered");return false;}'.
			'return parent::openDirectoryReference($path,$expectedIdentity);}'.
			'}'.
			($publicCollectorHash === null
				? 'erasedataCollectorMain(new ScriptedErasedataFilesystemOps($state["filesystemScenario"]));'
				: 'erasedataRunCollector(FileUtil::getSettingsPath()."/erasedata",'.
					var_export($publicCollectorHash, true).');').
			($debug || $captureLogs ? 'echo "__ERASEDATA_LOG__".json_encode(FileUtil::$log);' : '');
		$output = array();
		$status = 0;
		exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -r '.escapeshellarg($code).' 2>&1', $output, $status);
		clearstatcache();
		return(array($status, implode("\n", $output)));
	}

	private function runCollectorWithFilesystem(array $scenario, $ok = true,
		$fault = false, $val = array(''))
	{
		$this->filesystemScenario = $scenario;
		try {
			return($this->runCollector($ok, $fault, $val));
		} finally {
			$this->filesystemScenario = array();
		}
	}

	private function runCollectorWithOptions($ok, $fault, $val, $options)
	{
		$defaults = array(
			'swap' => null,
			'owned' => null,
			'rmdirFail' => null,
			'rmdirSwap' => null,
			'rmdirCrash' => null,
			'restoreCollision' => null,
			'reservedSwap' => null,
			'forceTargetSwap' => null,
			'forceTargetRecreate' => null,
			'forceTraverseSwap' => null,
			'cleanupCrash' => null,
			'forceCaptureCollision' => null,
			'reservationInitCrash' => null,
			'forceCaptureInitCrash' => null,
			'containerRemovalFail' => null,
			'faultString' => '',
			'generation' => null,
			'onlyHash' => null,
			'debug' => false,
			'cleanupUnlinkFail' => null,
			'cleanupPublicationPhase' => null,
			'captureLogs' => false,
			'indexCountFile' => null,
			'commitTokenUnlinkFail' => null,
			'artifactReadCountFile' => null,
			'successorOverride' => null,
			'successorTransition' => null,
			'successorObservationCountFile' => null,
			'publicCollectorHash' => null,
		);
		$unknown = array_diff_key($options, $defaults);
		if(count($unknown))
			throw new InvalidArgumentException('Unknown collector options: '.implode(', ', array_keys($unknown)));
		$options += $defaults;
		return($this->runCollector($ok, $fault, $val, $options['swap'], $options['owned'],
			$options['rmdirFail'], $options['rmdirSwap'], $options['rmdirCrash'],
			$options['restoreCollision'], $options['reservedSwap'], $options['forceTargetSwap'],
			$options['forceTargetRecreate'], $options['forceTraverseSwap'], $options['cleanupCrash'],
			$options['forceCaptureCollision'], $options['reservationInitCrash'],
			$options['forceCaptureInitCrash'], $options['containerRemovalFail'], $options['faultString'],
			$options['generation'], $options['onlyHash'], $options['debug'], $options['cleanupUnlinkFail'],
			$options['cleanupPublicationPhase'], $options['captureLogs'], $options['indexCountFile'],
			$options['commitTokenUnlinkFail'], $options['artifactReadCountFile'],
			$options['successorOverride'], $options['successorTransition'],
			$options['successorObservationCountFile'], $options['publicCollectorHash']));
	}

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
		list($status, $output) = $this->runCollector(false, false, array());
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

		list($status, $output) = $this->runCollector(true, false, array(''));
		$this->assertEquals(0, $status, 'failed first deletion pass exits normally: '.$output);
		$this->assertTrue(is_dir($target), 'a failed unlink leaves the required target in place');
		$this->assertTrue(is_file($list), 'the deletion obligation survives the failed pass');
		$this->assertEquals($exact, is_file($list) ? file_get_contents($list) : null,
			'the exact manifest is retained for retry');

		rmdir($target);
		file_put_contents($target, 'retry');
		list($status, $output) = $this->runCollector(true, false, array(''));
		$this->assertEquals(0, $status, 'successful retry exits normally: '.$output);
		$this->assertTrue(!file_exists($target), 'the next collector pass retries and deletes the target');
		$this->assertTrue(!file_exists($list), 'the manifest is consumed only after required deletion completes');
	}

	public function testCollectorTreatsMissingTargetAsComplete()
	{
		$this->reset();
		$hash = $this->hash('3');
		$missing = $this->dir.'/already-missing.bin';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		$this->writeManifest($hash.'.list', $missing);

		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollector(true, false, array(''), null, null, $base);
		$this->assertEquals(0, $status, 'fail-first collector exits normally: '.$output);
		$this->assertTrue(is_dir($base), 'the injected empty-directory failure leaves the base in place');
		$this->assertTrue(is_file($list), 'the exact retry obligation survives the transient rmdir failure');
		$this->assertEquals($exact, is_file($list) ? file_get_contents($list) : null,
			'the retained manifest bytes are unchanged');

		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollector(true, false, array(''), null, null, $nested);
		$this->assertEquals(0, $status, 'nested fail-first collector exits normally: '.$output);
		$this->assertTrue(is_dir($nested), 'the injected nested-directory failure leaves it in place');
		$this->assertTrue(is_file($list), 'the nested retry obligation survives');

		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollector(true, false, array(''), null, null, null, $base);
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
		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollector(true, false, array(''), null, null, null, null, $base);
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

		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollector(
			true, false, array(''), null, null, null, null, null, null, null,
			null, null, null, null, null, $phase);
		$this->assertEquals(0, $status, $phase.' reservation crash exits at the deterministic seam: '.$output);
		$this->assertTrue(is_dir($base), $phase.' crash happens before the checked directory is renamed');
		$this->assertEquals(1, count(glob($this->dir.'/.erasedata-rmdir-*')),
			$phase.' crash leaves one discoverable private reservation');
		$this->assertTrue(is_file($list), $phase.' crash retains the exact manifest');

		list($status, $output) = $this->runCollector(true, false, array(''));
		$this->assertEquals(0, $status, $phase.' reservation retry exits normally: '.$output);
		$this->assertTrue(!file_exists($base), 'retry removes the original empty directory');
		$this->assertEquals(array(), glob($this->dir.'/.erasedata-rmdir-*'),
			'retry removes the abandoned initialization root');
		$this->assertTrue(!file_exists($list), 'retry completes the retained manifest');
	}

	public function testCollectorRetriesAfterTransientReservationContainerFailure()
	{
		$this->reset();
		$hash = $this->hash('2');
		$base = $this->dir.'/reservation-container-retry-base';
		$list = $this->dir.'/erasedata/'.$hash.'.list';
		mkdir($base);
		$this->writeManifestLines($hash.'.list', array($base.'/already-gone.bin'), $base, 1, 1);

		list($status, $output) = $this->runCollector(
			true, false, array(''), null, null, null, null, null, null, null,
			null, null, null, null, null, null, null, true);
		$this->assertEquals(0, $status, 'transient container failure exits normally: '.$output);
		$this->assertTrue(!file_exists($base), 'the checked empty directory was already removed');
		$this->assertEquals(1, count(glob($this->dir.'/.erasedata-rmdir-*')),
			'the empty private container remains discoverable for retry');
		$this->assertTrue(is_file($list), 'container cleanup failure retains the exact manifest');

		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollector(
			true, false, array(''), null, null, null, null, $base);
		$this->assertEquals(0, $status, 'collision setup exits after reserving the checked directory: '.$output);
		$reservations = glob($this->dir.'/.erasedata-rmdir-*');
		$this->assertEquals(1, is_array($reservations) ? count($reservations) : 0,
			'collision setup leaves one data-bearing reservation');
		$reserved = is_array($reservations) && count($reservations) ? $reservations[0] : '';
		$reservedData = $this->reservationDataPath($reserved);

		list($status, $output) = $this->runCollector(
			true, false, array(''), null, null, null, null, null, $base);
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

		list($status, $output) = $this->runCollector(
			true, false, array(''), null, null, null, null, null, $base, $base);
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

		list($status, $output) = $this->runCollector(
			true, false, array(''), null, null, null, null, $base);
		$this->assertEquals(0, $status, 'force-recovery setup exits at the reservation seam: '.$output);
		list($status, $output) = $this->runCollector(true, false, array(''));
		$this->assertEquals(0, $status, 'force-recovery publication exits normally: '.$output);
		$recovery = glob($this->dir.'/.erasedata-rmdir-*');
		$target = is_link($base) ? @readlink($base) : '';
		$this->assertTrue(is_link($base) && @readlink($base) === $target,
			'the first generation exposes its recovered backing at the original path');
		$this->assertTrue(!file_exists($firstList),
			'the first generation completes after visible recovery');

		$this->writeManifestLines(
			$secondHash.'.list', array($base.'/crash-data.bin'), $base, 1, 2);
		list($status, $output) = $this->runCollector(true, false, array(''));
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
		$this->runCollector(true, false, array(''), null, null, null, null, $base);
		$this->runCollector(true, false, array(''));
		$recovery = glob($this->dir.'/.erasedata-rmdir-*');
		$target = is_link($base) ? @readlink($base) : '';
		$this->writeManifestLines(
			$secondHash.'.list', array($base.'/crash-data.bin'), $base, 1, 2);
		$exact = file_get_contents($secondList);

		list($status, $output) = $this->runCollector(
			true, false, array(''), null, null, null, null, null, null, null, $base);
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
		$this->runCollector(true, false, array(''), null, null, null, null, $base);
		$this->runCollector(true, false, array(''));
		$this->writeManifestLines(
			$secondHash.'.list', array($base.'/crash-data.bin'), $base, 1, 2);
		$exact = file_get_contents($secondList);

		list($status, $output) = $this->runCollector(
			true, false, array(''), null, null, null, null, null, null, null, null, $base);
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
		$this->runCollector(true, false, array(''), null, null, null, null, $base);
		$this->runCollector(true, false, array(''));
		$this->writeManifestLines(
			$secondHash.'.list', array($base.'/crash-data.bin'), $base, 1, 2);
		$exact = file_get_contents($secondList);

		list($status, $output) = $this->runCollector(
			true, false, array(''), null, null, null, null, null, null, null, null, null, $base);
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
		$this->runCollector(true, false, array(''), null, null, null, null, $base);
		$this->runCollector(true, false, array(''));
		$this->writeManifestLines(
			$secondHash.'.list', array($base.'/crash-data.bin'), $base, 1, 2);
		$exact = file_get_contents($secondList);

		list($status, $output) = $this->runCollector(
			true, false, array(''), null, null, null, null, null, null, null, null, null, null, $phase);
		$this->assertEquals(0, $status, $phase.' cleanup worker exits at the deterministic seam: '.$output);
		$this->assertTrue(is_link($base),
			$phase.' cleanup crash keeps the visible recovery link discoverable');
		$this->assertEquals($exact, is_file($secondList) ? file_get_contents($secondList) : null,
			$phase.' cleanup crash retains the exact force manifest');

		list($status, $output) = $this->runCollector(true, false, array(''));
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
		$this->runCollector(true, false, array(''), null, null, null, null, $base);
		$this->runCollector(true, false, array(''));
		$recovery = glob($this->dir.'/.erasedata-rmdir-*');
		$target = is_link($base) ? @readlink($base) : '';
		$this->writeManifestLines(
			$secondHash.'.list', array($base.'/crash-data.bin'), $base, 1, 2);
		$exact = file_get_contents($secondList);

		list($status, $output) = $this->runCollector(
			true, false, array(''), null, null, null, null, null, null, null, null, null, null, null, $base);
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
		list($status, $output) = $this->runCollector(true, false, array(''));
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
		$this->runCollector(true, false, array(''), null, null, null, null, $base);
		$this->runCollector(true, false, array(''));
		$this->writeManifestLines(
			$secondHash.'.list', array($base.'/crash-data.bin'), $base, 1, 2);

		list($status, $output) = $this->runCollector(
			true, false, array(''), null, null, null, null, null, null, null,
			null, null, null, null, null, null, $phase);
		$this->assertEquals(0, $status, $phase.' capture crash exits at the deterministic seam: '.$output);
		$this->assertTrue(is_link($base), $phase.' capture crash keeps recovered data visible');
		$this->assertTrue(is_file($secondList), $phase.' capture crash retains the exact force manifest');

		list($status, $output) = $this->runCollector(true, false, array(''));
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
		$this->runCollector(true, false, array(''), null, null, null, null, $base);
		$this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollectorWithFilesystem(array(
			'action' => 'swap_recovery_before_rmdir',
			'path' => $target,
			'replacement' => $replacement,
			'backup' => $backup,
			'marker' => $marker,
		));
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

		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollector(true, false, array($hash), null, $owned);
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
		list($status, $output) = $this->runCollector(true, false, array($hash), null, $owned);
		$this->assertEquals(0, $status, 'present-generation reconciliation exits normally: '.$output);
		$this->assertTrue(!file_exists($oldFile), 'old non-overlapping data is collected while the hash is present again');
		$this->assertTrue(is_file($newFile), 'the active generation data is untouched');
		$this->assertEquals(array(), $this->manifestFiles($hash), 'the non-overlapping old obligation is complete');

		$this->frozen(true, array($newBase, 1, $newFile));
		$this->eraseOk();
		erasedataRemoveWithData(array($hash), '1');
		$this->assertEquals(1, count($this->manifestFiles($hash)), 'the second erase publishes its own generation');
		list($status, $output) = $this->runCollector(true, false, array(''));
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
		list($status, $output) = $this->runCollector(true, false, array(''));
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
		list($status, $output) = $this->runCollector(true, false, array($hash), null, $owned);
		$this->assertEquals(0, $status, 'overlapping present-generation reconciliation exits normally: '.$output);
		$this->assertTrue(is_file($file), 'an overlapping active path is never deleted');
		$this->assertTrue(is_file($first), 'the overlapping deletion obligation remains pending');
		$this->assertEquals($exact, is_file($first) ? file_get_contents($first) : null,
			'the overlapping manifest remains exact while active');

		$this->frozen(true, array($base, 1, $file));
		$this->eraseOk();
		erasedataRemoveWithData(array($hash), '1');
		$this->assertEquals(2, count($this->manifestFiles($hash)), 'the overlapping second erase has a distinct generation');
		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollector(true, false, array($hash), null, $owned);
		$this->assertEquals(0, $status, 'real-to-alias reconciliation exits normally: '.$output);
		$this->assertEquals('active', is_file($file) ? file_get_contents($file) : null,
			'the active physical file bytes survive through the real name');
		$this->assertEquals('active', is_readable($alias.'/same.bin') ? file_get_contents($alias.'/same.bin') : null,
			'the active physical file survives through both names');
		$this->assertTrue(is_file($list), 'the overlapping real-path obligation stays pending');

		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollector(true, false, array($hash), null, $owned);
		$this->assertEquals(0, $status, 'alias-to-real reconciliation exits normally: '.$output);
		$this->assertEquals('active', is_file($file) ? file_get_contents($file) : null,
			'the active physical file bytes survive through the real name');
		$this->assertEquals('active', is_readable($alias.'/same.bin') ? file_get_contents($alias.'/same.bin') : null,
			'the alias cannot authorize deletion of the active real file');
		$this->assertTrue(is_file($list), 'the overlapping alias obligation stays pending');

		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollector(true, false, array($hash), null, $owned);
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

		list($status, $output) = $this->runCollector(true, false, array($hash), null, $owned);
		$this->assertEquals(0, $status, 'manifest-parent reconciliation exits normally: '.$output);
		$this->assertTrue(is_file($activeFile), 'the active child is untouched');
		$this->assertTrue(is_file($list), 'the parent directory overlap keeps the obligation pending');

		unlink($activeFile);
		rmdir($activeBase);
		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollector(true, false, array($hash), null, $owned);
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

		list($status, $output) = $this->runCollector(true, false, array($hash), null, $owned);
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
		list($status, $output) = $this->runCollector(true, false, array($hash), null, $owned);
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
		list($status, $output) = $this->runCollector(false, false, array());
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
		list($status, $output) = $this->runCollector(true, false, array(""));
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
		list($status, $output) = $this->runCollector(true, false, array(""));
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
		list($status, $output) = $this->runCollector(true, false, array(""));
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
		list($status, $output) = $this->runCollector(true, false, array(""));
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
		list($status, $output) = $this->runCollector(true, false, array(""), array($list, $external));
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
		list($status, $output) = $this->runCollector(true, false, array(""), array($list, $replacement, 'rename'));
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
		list($status, $output) = $this->runCollector(true, false, array(''));
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
		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollectorWithFilesystem(array(
			'action' => 'crash_after_capture',
			'path' => $old,
			'marker' => $marker,
		));
		$this->assertEquals(0, $status, 'the scripted cleanup capture crash must exit at its boundary: '.$output);
		$this->assertTrue(is_file($marker) && !file_exists($old),
			'the first pass must stop only after moving the obsolete file out of its public name');
		$this->assertEquals(1, count(glob($base.'/.erasedata-entry-*')),
			'the interrupted pass must leave one discoverable captured obsolete file');
		$this->assertTrue(is_file($tmp) && is_file($token) && is_file($neighbor),
			'the interrupted pass must retain its exact job and unrelated neighbor');

		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollectorWithFilesystem(array(
			'action' => 'crash_after_capture',
			'path' => $old,
			'marker' => $marker,
		));
		$this->assertEquals(0, $status, 'the alias fixture must stop after OLD capture: '.$output);
		$captures = glob($base.'/.erasedata-entry-*');
		$this->assertEquals(1, count($captures), 'the interrupted cleanup must expose one exact capture for the retry fixture');
		$captureEntry = count($captures) === 1 ? $captures[0].'/entry' : '';
		$this->assertTrue($captureEntry !== '' && @symlink($captureEntry, $new),
			'the NEW successor fixture must physically alias the captured OLD entry');
		$owned = array('base' => $new, 'multi' => 0, 'files' => array($new));

		list($status, $output) = $this->runCollectorWithOptions(true, false, array($newHash), array(
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
		list($status, $output) = $this->runCollector(true, false, array(''));
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
		list($status, $output) = $this->runCollector(true, false, array(''));
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
			list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array(
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
		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array(
			'cleanupUnlinkFail' => $old, 'debug' => true));
		$this->assertEquals(0, $status, 'an injected cleanup unlink failure must not crash the collector: '.$output);
		$this->assertTrue(is_file($old) && is_file($tmp) && is_file($token),
			'a cleanup unlink failure must retain the target, manifest, and commit token');
		$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' unlink-failure', $this->collectorLogs($output), true),
			'a cleanup unlink failure must retain the unlink-failure reason');
		list($status, $output) = $this->runCollector(true, false, array(''));
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
		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array('debug' => true));
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
		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array(
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array($newHash), array('debug' => true));
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

		list($status, $output) = $this->runCollector(true, false, array($newHash), null, $owned);
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

		list($status, $output) = $this->runCollector(true, false, array($newHash), null, $owned);
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array($newHash), array(
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array($newHash), array(
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

			list($status, $output) = $this->runCollector(true, false, array($newHash), null, $owned);
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array($newHash), array(
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array($newHash), array(
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

			list($status, $output) = $this->runCollectorWithOptions(true, false, array($newHash), array(
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

			list($status, $output) = $this->runCollectorWithOptions(true, false, array($newHash), array(
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

			list($status, $output) = $this->runCollectorWithOptions(true, false, array($newHash), array(
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array($newHash), array(
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array($newHash), array(
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

			list($status, $output) = $this->runCollectorWithOptions(true, false, array($newHash), array(
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array('debug' => true));
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array(
			'rmdirFail' => $nested, 'debug' => true));
		$this->assertEquals(0, $status, 'an injected cleanup rmdir failure must not crash the collector: '.$output);
		$this->assertTrue(!file_exists($old) && is_file($list),
			'a cleanup rmdir failure must retain the durable list after deleting the exact target');
		$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' rmdir-failure', $this->collectorLogs($output), true),
			'a cleanup rmdir failure must retain the rmdir-failure reason');

		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array('debug' => true));
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array(
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array('debug' => true));
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

		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array('rmdirFail' => $base));
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array(
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array(
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
		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array('debug' => true));
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array('debug' => true));
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
		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array(
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array(
			'generation' => $generation, 'debug' => true));
		$this->assertEquals(0, $status, 'a committed successor RPC uncertainty must not crash the collector: '.$output);
		$this->assertTrue(is_file($old) && is_file($tmp) && is_file($token),
			'a committed cleanup job must retain its target, strict manifest, and token while successor presence is unknown');
		$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' rpc-unknown', $this->collectorLogs($output), true),
			'a committed successor presence RPC uncertainty must keep its rpc-unknown reason');

		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array('debug' => true));
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
		global $erasedataBeforeUnlinkExactStagedFileOverride;
		$this->reset();
		$oldHash = $this->hash('A');
		$job = $this->prepareCleanupJob($oldHash);
		$tmp = $job['tmp_path'];
		$replacement = $this->dir.'/replacement-tmp';
		file_put_contents($replacement, 'replacement');
		$erasedataBeforeUnlinkExactStagedFileOverride = function($path) use ($replacement) {
			@rename($path, $path.'.original');
			@rename($replacement, $path);
			return(true);
		};
		try {
			$this->assertEquals(false, erasedataCancelObsoleteCleanup($job), 'a tmp swapped after validation must not be unlinked');
			$this->assertEquals('replacement', file_get_contents($tmp), 'the replacement tmp must survive the cancellation race');
		} finally {
			$erasedataBeforeUnlinkExactStagedFileOverride = null;
		}
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
		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array('onlyHash' => $oldA));
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array(
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
		global $erasedataCleanupPublicationPhaseOverride;
		$this->reset();
		$oldHash = $this->hash('A');
		$job = $this->prepareCleanupJob($oldHash);
		$tmp = $job['tmp_path'];
		$list = $job['list_path'];
		$foreignTmp = $this->dir.'/foreign-publication-tmp';
		file_put_contents($foreignTmp, 'foreign-tmp');
		$erasedataCleanupPublicationPhaseOverride = function($phase) use ($tmp, $foreignTmp) {
			if($phase === 'before-token')
			{
				@rename($tmp, $tmp.'.owned');
				@rename($foreignTmp, $tmp);
			}
			return(true);
		};
		try {
			$this->assertEquals(false, erasedataPublishObsoleteCleanup($job),
				'a tmp swapped before O_EXCL token creation must keep publication retryable');
			$this->assertEquals('foreign-tmp', file_get_contents($tmp),
				'a tmp replacement must survive publication validation');
			$this->assertTrue(!file_exists($list),
				'a swapped tmp must not create a token for a foreign transaction');
		} finally {
			$erasedataCleanupPublicationPhaseOverride = null;
		}

		$this->reset();
		$job = $this->prepareCleanupJob($oldHash);
		$tmp = $job['tmp_path'];
		$list = $job['list_path'];
		$foreignToken = $this->dir.'/foreign-publication-token';
		file_put_contents($foreignToken, 'foreign-token');
		$erasedataCleanupPublicationPhaseOverride = function($phase) use ($list, $foreignToken) {
			if($phase === 'after-token')
			{
				@rename($list, $list.'.owned');
				@rename($foreignToken, $list);
			}
			return(true);
		};
		try {
			$this->assertEquals(false, erasedataPublishObsoleteCleanup($job),
				'a token swapped after O_EXCL creation must keep publication retryable');
			$this->assertEquals('foreign-token', file_get_contents($list),
				'a token replacement must survive publication validation');
			$this->assertTrue(is_file($tmp),
				'a swapped token must retain the strict tmp for a later safe retry');
		} finally {
			$erasedataCleanupPublicationPhaseOverride = null;
		}
	}

	public function testCleanupTokenStateMachineConvergesAcrossInterruptedFinalization()
	{
		global $erasedataCleanupPublicationPhaseOverride;
		$this->reset();
		$oldHash = $this->hash('A');
		$job = $this->prepareCleanupJob($oldHash);
		$tmp = $job['tmp_path'];
		$list = $job['list_path'];
		$erasedataCleanupPublicationPhaseOverride = function($phase) {
			return($phase !== 'before-token');
		};
		try {
			$this->assertEquals(false, erasedataPublishObsoleteCleanup($job),
				'an interruption before token creation must retain only the prepared strict tmp');
			$this->assertTrue(is_file($tmp) && !file_exists($list),
				'a PREPARED cleanup state must not expose any partial commit token');
		} finally {
			$erasedataCleanupPublicationPhaseOverride = null;
		}

		$this->reset();
		$oldHash = $this->hash('A');
		$input = $this->cleanupJobInput($oldHash);
		$job = erasedataPrepareObsoleteCleanup($oldHash, $input['new_hash'], $input['marker'],
			$input['replacement_record'], $input['base'], $input['entries']);
		$tmp = $job['tmp_path'];
		$list = $job['list_path'];
		$erasedataCleanupPublicationPhaseOverride = function($phase) {
			return($phase !== 'after-token');
		};
		try {
			$this->assertEquals(false, erasedataPublishObsoleteCleanup($job),
				'an interruption immediately after token creation must retain the committed pair');
			$this->assertTrue(is_file($tmp) && is_file($list) && filesize($list) === 0,
				'the interrupted commit must have no partial-content publication artifact');
		} finally {
			$erasedataCleanupPublicationPhaseOverride = null;
		}
		list($status, $output) = $this->runCollector(true, false, array(''));
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
		list($status, $output) = $this->runCollector(true, false, array(''));
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
		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array(
			'commitTokenUnlinkFail' => $token, 'debug' => true));
		$this->assertEquals(0, $status, 'a token-unlink interruption must not crash collection: '.$output);
		$this->assertTrue(!file_exists($old) && !file_exists($tmp) && is_file($token),
			'the post-tmp-unlink crash window must retain only the exact FINALIZING token');
		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array('debug' => true));
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
		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array(
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
		list($status, $output) = $this->runCollectorWithOptions(true, false, array(''), array(
			'generation' => $generation, 'captureLogs' => true, 'indexCountFile' => $indexCount));
		$this->assertEquals(0, $status, 'a default-visible retained cleanup job must not crash: '.$output);
		$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' rpc-unknown', $this->collectorLogs($output), true),
			'a retained cleanup reason must remain visible with shipped debug logging disabled');
		$this->assertEquals(array('1'), is_file($indexCount) ? file($indexCount, FILE_IGNORE_NEW_LINES) : array(),
			'one collector invocation must build exactly one queue index instead of rescanning two jobs');
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
		list($status, $output) = $this->runCollector(true, false, array(''));
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
		global $erasedataManifestWriteOverride;
		$this->reset();
		$hash = $this->hash('A');
		$contents = $this->cleanupWriterContents($hash);
		$this->assertTrue(function_exists('erasedataWriteStagedManifest'),
			'the shared staged writer must clean up incomplete writes');
		if(!function_exists('erasedataWriteStagedManifest'))
			return;
		$erasedataManifestWriteOverride = function($path, $bytes) {
			file_put_contents($path, $bytes);
			return(strlen($bytes) - 1);
		};
		try {
			$this->assertEquals(false, erasedataWriteStagedManifest($this->dir.'/erasedata', $hash, $contents, 'cleanup'),
				'a short write must fail staging');
			$this->assertEquals(array(), glob($this->dir.'/erasedata/'.$hash.'.cleanup.*.tmp'),
				'a failed short write must remove only its partial artifact');
		} finally {
			$erasedataManifestWriteOverride = null;
		}
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
		global $erasedataManifestWriteOverride;
		$this->reset();
		$oldHash = $this->hash('A');
		$this->assertTrue(function_exists('erasedataPrepareObsoleteCleanup'),
			'cleanup preparation must release its lock after a staging failure');
		if(!function_exists('erasedataPrepareObsoleteCleanup'))
			return;
		$erasedataManifestWriteOverride = function($path, $bytes) {
			file_put_contents($path, $bytes);
			return(0);
		};
		try {
			$this->assertEquals(false, $this->prepareCleanupJob($oldHash), 'a failed staged write must fail preparation');
			$this->assertEquals(array(), glob($this->dir.'/erasedata/'.$oldHash.'.cleanup.*.tmp'), 'failed preparation must not retain a partial cleanup artifact');
			$contender = erasedataAcquireHashLock($this->dir.'/erasedata', $oldHash, true);
			$this->assertTrue(is_resource($contender), 'failed preparation must release the old-hash lock');
			if(is_resource($contender))
				erasedataReleaseHashLock($contender);
		} finally {
			$erasedataManifestWriteOverride = null;
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
		list($status, $output) = $this->runCollector(true, false, array(''), null, null);
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
		list($status, $output) = $this->runCollector(true, false, array(''), null, null);
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
		list($status, $output) = $this->runCollector(true, false, array($hash), null, null);
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
		list($status, $output) = $this->runCollector(true, false, array($hash), null, null);
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
		list($status, $output) = $this->runCollector(true, false, array($hash), null, null);
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

		list($status, $output) = $this->runCollector(true, false, array(''));
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
		list($status, $output) = $this->runCollector(true, false, array($hash), null, null);
		$this->assertEquals(0, $status, 'collector exits 0: '.$output);
		$this->assertEquals($hugeManifest, file_get_contents($this->dir.'/erasedata/'.$hash.'.list'), 'oversize manifest retained byte-for-byte');
	}

	private function runCollectorWithFault($ok, $fault, $val, $faultString = '')
	{
		return($this->runCollector(
			$ok, $fault, $val, null, null, null, null, null, null,
			null, null, null, null, null, null, null, null, null, $faultString));
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
		list($status, $output) = $this->runCollector(false, false, array());
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
		list($status, $output) = $this->runCollector(true, false, array($hash, 'EXTRA_CARDINALITY'));
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
		list($status, $output) = $this->runCollector(true, false, array($wrong));
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

		list($status, $output) = $this->runCollectorWithFilesystem(array(
			'action' => 'swap_before_capture',
			'path' => $path,
			'replacement' => $replacement,
			'backup' => $backup,
			'marker' => $marker,
		));

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

		list($status, $output) = $this->runCollectorWithFilesystem(array(
			'action' => 'swap_manifest_before_mutation',
			'path' => $manifest,
			'replacement' => $replacement,
			'backup' => $backup,
			'marker' => $marker,
		));

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

		list($status, $output) = $this->runCollectorWithFilesystem(array(
			'action' => 'swap_before_capture',
			'path' => $base,
			'replacement' => $replacement,
			'backup' => $backup,
			'marker' => $marker,
		));

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

		list($status, $output) = $this->runCollectorWithFilesystem(array(
			'action' => 'swap_to_symlink_before_capture',
			'path' => $base,
			'symlinkTarget' => $external,
			'backup' => $backup,
			'marker' => $marker,
		));
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

		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollectorWithFilesystem(array(
			'action' => 'nested_swap_after_scan',
			'child' => basename($child),
			'replacement' => $replacement,
			'backup' => $backup,
			'marker' => $marker,
		));

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

		list($status, $output) = $this->runCollectorWithFilesystem(array(
			'action' => 'recreate_public_after_capture',
			'path' => $base,
			'sentinel' => 'recreated-public',
			'marker' => $marker,
		));

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

		list($status, $output) = $this->runCollectorWithFilesystem(array(
			'action' => 'swap_before_capture',
			'path' => $base,
			'replacement' => $replacement,
			'backup' => $backup,
		));

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

		list($status, $output) = $this->runCollectorWithFilesystem(array(
			'action' => 'crash_after_capture',
			'path' => $base,
			'marker' => $marker,
		));
		$this->assertEquals(0, $status, 'capture-crash worker exits at the scripted boundary: '.$output);
		$this->assertTrue(is_file($marker), 'the worker exited only after atomic capture completed');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$hash.'.list'),
			'capture crash retains the manifest for recovery');
		$this->assertEquals(1, count(glob($this->dir.'/.erasedata-rmdir-*')),
			'capture crash leaves one discoverable reservation');

		list($status, $output) = $this->runCollector(true, false, array(''));
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

		list($status, $output) = $this->runCollectorWithFilesystem(array(
			'action' => 'directory_reference_unavailable',
			'marker' => $marker,
		));

		$this->assertEquals(0, $status, 'unavailable-reference collector exits normally: '.$output);
		$this->assertTrue(is_file($marker), 'the scripted safe-reference refusal reached production traversal');
		$this->assertEquals('reference-data', is_file($base.'/data.bin')
			? file_get_contents($base.'/data.bin') : null,
			'no data is deleted without an identity-bound directory reference');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$hash.'.list'),
			'safe-reference uncertainty retains the manifest');
	}
}
