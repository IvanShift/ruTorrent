<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/PermissionsBiteFixture.php');
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

// Shared filesystem forwarding for two write-failure fixtures. Keeping rename()
// here matters: a production writer that ignores the scripted failure must be
// able to publish, so the named tests fail for the intended reason.
if(!class_exists('ErasedataWriteFailureStream'))
{
	abstract class ErasedataWriteFailureStream
	{
		public $context;
		protected $handle = null;

		public static function register()
		{
			if(!in_array(static::SCHEME, stream_get_wrappers(), true))
				stream_wrapper_register(static::SCHEME, get_called_class());
		}
		public static function real($path)
		{
			return(substr($path, strlen(static::SCHEME.'://')));
		}
		public function stream_open($path, $mode, $options, &$openedPath)
		{
			$this->handle = @fopen(static::real($path), $mode);
			return($this->handle !== false);
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
			$real = static::real($path);
			return(($flags & STREAM_URL_STAT_LINK) ? @lstat($real) : @stat($real));
		}
		public function unlink($path) { return(@unlink(static::real($path))); }
		public function rename($from, $to)
		{
			return(@rename(static::real($from), static::real($to)));
		}
		abstract public function stream_write($data);
	}
}

// A partial first write leaves genuinely incomplete staged bytes.
if(!class_exists('ErasedataPartialWriteStream'))
{
	class ErasedataPartialWriteStream extends ErasedataWriteFailureStream
	{
		const SCHEME = 'erasedatapartial';
		private $wrote = false;

		public function stream_write($data)
		{
			if($this->wrote || !is_resource($this->handle))
				return(0);
			$this->wrote = true;
			$half = strlen($data) > 1 ? intdiv(strlen($data), 2) : 1;
			$written = @fwrite($this->handle, substr($data, 0, $half));
			return($written === false ? 0 : $written);
		}
	}
}

// Every byte is accepted, but the flush fails as a buffered filesystem can.
if(!class_exists('ErasedataFlushFailureStream'))
{
	class ErasedataFlushFailureStream extends ErasedataWriteFailureStream
	{
		const SCHEME = 'erasedatanoflush';

		public function stream_write($data)
		{
			$written = @fwrite($this->handle, $data);
			return($written === false ? 0 : $written);
		}
		public function stream_flush() { return(false); }
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
	private $schedulerTicks = 0;

	private function scriptLegacyDaemonMarker($version = '0.9.8')
	{
		$marker = erasedataLegacyDrainMarkerName('rutorrent');
		$missing = "Method '".$marker."' not defined";
		$absent = array('ok' => true, 'fault' => true,
			'rawFaultString' => $missing, 'faultString' => $missing,
			'val' => array('-506', $missing));
		rXMLRPCRequest::$responses['system.client_version'] = array('ok' => true,
			'val' => array($version));
		rXMLRPCRequest::$responses[$marker] = $absent;
		rXMLRPCRequest::$responses['system.method.insert'] = array('ok' => true,
			'val' => array(0), 'callback' => function($commands) use ($marker)
			{
				rXMLRPCRequest::$responses[$marker] = array('ok' => true,
					'val' => array(1));
			});
		return(array($marker, $absent));
	}

	public function testDrainStateAndPassLocksKeepModeContentionAndReleaseContract()
	{
		global $profileMask;
		$this->reset();
		$profileMask = 0671;
		$queue = $this->queuePath();
		$locks = array(
			'state' => array(
				$queue.'/'.ERASEDATA_DRAIN_STATE_LOCK_NAME,
				function($nonBlocking) use ($queue) {
					return(erasedataAcquireDrainStateLock($queue, $nonBlocking));
				},
				'erasedataReleaseDrainStateLock'),
			'pass' => array(
				$queue.'/scheduler.lock',
				function($nonBlocking) use ($queue) {
					return(erasedataAcquireDrainPassLock($queue, 'scheduler.lock', $nonBlocking));
				},
				'erasedataReleaseDrainPassLock'));
		foreach($locks as $kind => $api)
		{
			$held = $api[1](false);
			$this->assertTrue(is_resource($held), $kind.' lock opens');
			if(!is_resource($held))
				continue;
			try
			{
				$this->assertEquals(0660, $this->modeOf($api[0]),
					$kind.' lock has the shared profile mode');
				$this->assertEquals(false, $api[1](true),
					$kind.' nonblocking contender cannot enter');
			}
			finally
			{
				$this->assertTrue($api[2]($held), $kind.' lock releases');
			}
			$again = $api[1](true);
			$this->assertTrue(is_resource($again), $kind.' lock can be reacquired');
			if(is_resource($again))
				$this->assertTrue($api[2]($again), $kind.' reacquired lock releases');
			$this->assertEquals(false, $api[2](false),
				$kind.' release rejects a non-handle');
		}
		$this->assertEquals(false, erasedataAcquireDrainPassLock($queue, '', true),
			'a pass lock requires a nonempty name');
	}

	public function testDrainCollectsPayloadAndRetiresBeforeReleasingPassLocks()
	{
		$this->reset();
		$hash = $this->hash('A');
		$generation = '0000000000000001';
		$queue = $this->queuePath();
		$payload = $this->dir.'/drain-payload.bin';
		file_put_contents($payload, 'payload waiting for its drain worker');
		$bytes = ErasedataManifestCodec::encode($hash,
			array('base' => $payload, 'multi' => '0', 'files' => array($payload)), 1);
		$staged = erasedataStageAdmittedManifest($queue, $hash, $generation, $bytes);
		$this->assertTrue(is_array($staged), 'the real durable staging writer succeeds');
		if(!is_array($staged))
			return;
		erasedataQueueRequest($queue, $hash, 1, $generation);
		$this->armQueue($queue, $generation, array($hash => $staged['path']));
		$this->probe(true, true, array(), 'info-hash not found');
		$heldAtRemoval = array();
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true,
			'val' => array(0), 'callback' => function() use ($queue, &$heldAtRemoval) {
				foreach(array('scheduler.lock', '.drain-worker.lock') as $name)
				{
					$handle = fopen($queue.'/'.$name, 'c');
					$heldAtRemoval[$name] = !flock($handle, LOCK_EX | LOCK_NB);
					fclose($handle);
				}
			});
		$tick = erasedataDrainWorkerRun($this->dependencies());
		$this->assertTrue(!file_exists($payload), 'drain itself deletes the payload without a periodic pass');
		$this->assertEquals(array(), glob($queue.'/*.list'), 'drain consumes its final manifest');
		$this->assertTrue(is_array($tick) && $tick['retired'] === true,
			'the same completed drain tick retires');
		$this->assertEquals(array('scheduler.lock' => true, '.drain-worker.lock' => true),
			$heldAtRemoval, 'retirement keeps both pass locks through schedule removal');
	}

	public function testUnjournaledDrainStagingKeepsItsMarkerAndCannotBeRebound()
	{
		foreach(array('present', 'absent') as $presence)
		{
			$this->reset();
			$hash = $this->hash('A');
			$generation = '0000000000000001';
			$queue = $this->queuePath();
			$payload = $this->dir.'/orphan-payload.bin';
			file_put_contents($payload, 'payload owned by a crashed producer');
			$bytes = ErasedataManifestCodec::encode($hash,
				array('base' => $payload, 'multi' => '0', 'files' => array($payload)), 1);
			$staged = erasedataStageAdmittedManifest($queue, $hash, $generation, $bytes);
			$this->assertTrue(is_array($staged), $presence.': complete pre-journal staging exists');
			if(!is_array($staged))
				continue;
			erasedataQueueRequest($queue, $hash, 1, $generation);
			$marker = erasedataPendingMarkerPath($queue, $hash, $generation);
			$markerBytes = file_get_contents($marker);
			$this->armQueue($queue, $generation, array());
			$this->probe(true, $presence === 'absent',
				$presence === 'absent' ? array() : array($hash), 'info-hash not found');
			$this->frozen(true, array($payload, 0, $payload));
			$this->eraseOk();
			$tick = erasedataDrainWorkerRun($this->dependencies());
			$this->assertEquals($markerBytes, @file_get_contents($marker),
				$presence.': an existing unjournaled staging keeps its exact pending obligation');
			$this->assertEquals(array(), rXMLRPCRequest::$erased,
				$presence.': recovery cannot replace an unbound manifest and erase under it');
			$this->assertEquals($bytes, file_get_contents($staged['path']),
				$presence.': original staging remains byte-exact');
			$this->assertEquals(array($staged['path']), glob($queue.'/*.tmp'),
				$presence.': recovery creates no second staging identity');
			$this->assertEquals(array(), glob($queue.'/*.list'),
				$presence.': unjournaled staging authorizes no publication');
			$this->assertTrue(is_array($tick) && $tick['retained'] === 1
				&& $tick['unrecoverable'] === 0 && !$tick['retired'],
				$presence.': the recoverable physical candidate is retained, never abandoned');
			$this->assertTrue(strpos(implode("\n", FileUtil::$log), 'staging-unbound') !== false,
				$presence.': the missing journal identity binding is visible');
		}
	}

	// One crashed producer freezes ONE obligation, never the whole generation.
	//
	// The unbound-staging refusal is generation-wide the moment it returns out
	// of the pass: a single physical staging object the journal does not bind
	// then freezes every sibling of the same admission -- nothing published, no
	// marker discharged, retirement impossible, and no diagnostic naming the
	// members that were stranded. The refusal itself is unchanged here: the
	// unbound candidate keeps its marker and its exact bytes, is never adopted
	// by filename and authorizes no publication. Only its blast radius is.
	public function testUnboundStagingFreezesOnlyItsOwnMemberOfTheGeneration()
	{
		$this->reset();
		$generation = '0000000000000001';
		$queue = $this->queuePath();
		$orphan = $this->hash('A');
		$siblings = array($this->hash('B'), $this->hash('C'));
		$payloads = array();
		$staged = array();
		foreach(array_merge(array($orphan), $siblings) as $hash)
		{
			$label = substr($hash, 0, 1);
			$payload = $this->dir.'/member-'.$label.'.bin';
			file_put_contents($payload, 'payload of batch member '.$label);
			$payloads[$hash] = $payload;
			$bytes = ErasedataManifestCodec::encode($hash,
				array('base' => $payload, 'multi' => '0', 'files' => array($payload)), 1);
			$object = erasedataStageAdmittedManifest($queue, $hash, $generation, $bytes);
			$this->assertTrue(is_array($object),
				'batch member '.$label.' has a complete staging object');
			if(!is_array($object))
				return;
			$staged[$hash] = $object['path'];
			erasedataQueueRequest($queue, $hash, 1, $generation);
		}
		$orphanBytes = file_get_contents($staged[$orphan]);
		$orphanMarker = erasedataPendingMarkerPath($queue, $orphan, $generation);
		$orphanMarkerBytes = file_get_contents($orphanMarker);
		// The journal binds the two siblings and knows nothing of the third,
		// which is exactly what a producer that died between its staging write
		// and its journal write leaves behind.
		$bound = array();
		foreach($siblings as $hash)
			$bound[$hash] = $staged[$hash];
		$this->armQueue($queue, $generation, $bound);
		$this->probe(true, true, array(), 'info-hash not found');
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true, 'val' => array(0));
		$tick = erasedataDrainWorkerRun($this->dependencies());
		foreach($siblings as $hash)
		{
			$label = substr($hash, 0, 1);
			$this->assertTrue(!file_exists($payloads[$hash]),
				'sibling '.$label.' is published and collected on the first tick');
			$this->assertTrue(!file_exists(erasedataPendingMarkerPath($queue, $hash, $generation)),
				'sibling '.$label.' has its pending marker discharged');
			$this->assertTrue(!file_exists($staged[$hash]),
				'sibling '.$label.' leaves no staging object behind');
		}
		// The refusal itself, undiminished.
		$this->assertTrue(is_file($payloads[$orphan]),
			'the unbound member erases nothing');
		$this->assertEquals(array(), rXMLRPCRequest::$erased,
			'no member is erased under a binding the journal never recorded');
		$this->assertEquals($orphanBytes, @file_get_contents($staged[$orphan]),
			'the unbound staging object survives byte-exact');
		$this->assertEquals($orphanMarkerBytes, @file_get_contents($orphanMarker),
			'the unbound member keeps its exact pending obligation');
		$this->assertEquals(array($staged[$orphan]), glob($queue.'/*.tmp'),
			'the unbound member is never rebound and no second identity appears');
		$this->assertEquals(array(), glob($queue.'/*.list'),
			'the pass consumes every manifest it published and invents none');
		$this->assertTrue(is_array($tick) && $tick['published'] === 2
			&& $tick['retained'] === 1 && $tick['unrecoverable'] === 0,
			'two siblings resolve while one unbound member is retained');
		$this->assertTrue(is_array($tick) && $tick['retired'] === false,
			'the queue is not retirable while the unbound obligation stands');
		// A stranded obligation is never invisible: the hash that owns the
		// refusal is named, and no sibling is left retained without one.
		$log = implode("\n", FileUtil::$log);
		$this->assertTrue(strpos($log, 'staging-unbound') !== false
			&& strpos($log, 'hash='.$orphan) !== false,
			'the retained obligation is diagnosed by its own hash');
		foreach($siblings as $hash)
			$this->assertTrue(strpos($log, 'hash='.$hash) === false,
				'a sibling that resolved is not reported as a refusal');
	}

	public function testDrainCollectorWaitsForHashWhilePeriodicCollectorSkipsIt()
	{
		$this->reset();
		$hash = $this->hash('A');
		$generation = '0000000000000001';
		$queue = $this->queuePath();
		$payload = $this->dir.'/locked-final-payload.bin';
		file_put_contents($payload, 'collector must wait for the hash owner');
		$bytes = ErasedataManifestCodec::encode($hash,
			array('base' => $payload, 'multi' => '0', 'files' => array($payload)), 1);
		$final = $queue.'/'.$hash.'.'.$generation.'.1.list';
		file_put_contents($final, $bytes);
		$this->armQueue($queue, $generation, array());
		$this->probe(true, true, array(), 'info-hash not found');
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true, 'val' => array(0));
		$holder = ErasedataTestProcess::start(erasedataTestLockHolderCommand($queue.'/'.$hash.'.lock', 2.0));
		try
		{
			for($attempt = 0; $attempt < 100 && strpos($holder->out, 'held') === false; $attempt++)
			{
				$holder->pump();
				usleep(10000);
			}
			$this->assertTrue(strpos($holder->out, 'held') !== false,
				'the independent child actually holds the payload hash lock');
			$started = microtime(true);
			erasedataRunCollector($queue);
			$this->assertTrue(microtime(true) - $started < 1.0 && is_file($payload),
				'the periodic collector retains its nonblocking hash behavior');
			$started = microtime(true);
			$tick = erasedataDrainWorkerRun($this->dependencies());
			$this->assertTrue(microtime(true) - $started > 0.2,
				'the drain collector waits for the held hash rather than skipping it');
			$this->assertTrue(!file_exists($payload) && !file_exists($final),
				'the same drain invocation collects after the owner unlocks');
			$this->assertTrue(is_array($tick) && $tick['retired'], 'the completed drain can then retire');
		}
		finally
		{
			$holder->reap();
		}
	}

	public function setUpClass()
	{
		$this->dir = sys_get_temp_dir().'/erasedata-test-'.getmypid();
		@mkdir($this->dir, 0777, true);
		FileUtil::$settingsPath = $this->dir;
	}

	// setUpClass() runs once; each method resets its own mutable state here.
	private function reset()
	{
		global $profileMask;
		$profileMask = 0777;
		@chmod($this->dir, 0700);
		ErasedataCollectorTestState::$source = false;
		ErasedataCollectorTestState::$indexCountFile = null;
		ErasedataCollectorTestState::$indexBuilds = 0;
		foreach(array_diff(scandir($this->dir), array('.', '..', 'erasedata')) as $entry)
			$this->removePath($this->dir.'/'.$entry);
		@mkdir($this->dir.'/erasedata', 0777, true);
		foreach(array_diff(scandir($this->dir.'/erasedata'), array('.', '..')) as $entry)
			$this->removePath($this->dir.'/erasedata/'.$entry);
		@chmod($this->dir.'/erasedata', 0700);
		FileUtil::$log = array();
		FileUtil::$denyDirectoryRepair = false;
		rXMLRPCRequest::$responses = array('system.client_version' =>
			array('ok' => true, 'val' => array('0.16.24')));
		rXMLRPCRequest::$requested = array();
		rXMLRPCRequest::$erased = array();
		rXMLRPCRequest::$commandCalls = array();
		rXMLRPCRequest::$scheduledCommands = array();
	}

	public function tearDownClass()
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
	// A queue really ARMED at $generation, whose journal really binds the
	// staging files named, in the phase given.
	//
	// A worker case that queues generation 0000000000000001 against the default
	// state -- which carries 0000000000000000 -- returns at the
	// `generation-unarmed` guard before it probes, stages, erases or publishes
	// anything, so every assertion after the tick is satisfied by that one
	// refusal and would hold for any implementation whatsoever of the thing the
	// case is named for. This is what such a case needs written first.
	private function armQueue($queue, $generation, array $staging,
		$phase = 'erase-started', $force = 1, $user = 'rutorrent')
	{
		$hashes = array_keys($staging);
		sort($hashes, SORT_STRING);
		$journal = array();
		if(count($hashes))
		{
			$bound = array();
			foreach($hashes as $hash)
			{
				$stat = @stat($staging[$hash]);
				$bound[$hash] = array(
					'path' => $staging[$hash],
					'dev' => is_array($stat) ? (string)$stat['dev'] : '0',
					'ino' => is_array($stat) ? (string)$stat['ino'] : '0');
			}
			$journal[$generation] = array('phase' => $phase, 'force' => $force,
				'hashes' => $hashes, 'staging' => $bound);
		}
		$state = array(
			'version' => 1, 'user' => $user,
			'generation' => $generation, 'acknowledged' => $generation,
			'phase' => 'armed', 'journal' => $journal, 'diagnostics' => array());
		$this->assertTrue(erasedataWriteDrainState($queue, $state) === true,
			'the durable state really arms '.$generation.' with a '.$phase
				.' journal record binding '.count($hashes).' staging object(s)');
		return($state);
	}
	private function modeOf($path)
	{
		if(!is_string($path) || $path === '' || !file_exists($path))
			return(false);
		clearstatcache(true, $path);
		return(fileperms($path) & 0777);
	}
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
			.'class rSCGITransport{const RESPONSE_RAW="raw";public static $raw="";'
			.'public static function send($host,$port,$data,$trusted,$timeout,&$error,'
			.'$transferTimeout=null,$maxResponseBytes=null,$responseMode=self::RESPONSE_RAW)'
			.'{return self::$raw;}}');
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

	private function runCopiedAction($source, $rawBody, $withErasedataHelper, $admissionFailure = false)
	{
		$fixture = $this->dir.'/action-fixture-'.bin2hex(random_bytes(4));
		$plugin = strpos($source, '/httprpc/') !== false ? 'httprpc' : 'erasedata';
		$actionDir = $fixture.'/plugins/'.$plugin;
		$commandLog = $fixture.'/commands.json';
		@mkdir($actionDir, 0777, true);
		@mkdir($fixture.'/php', 0777, true);
		copy($source, $actionDir.'/action.php');
		if($plugin === 'httprpc')
			copy(__DIR__.'/../../../plugins/httprpc/settingspolicy.php', $actionDir.'/settingspolicy.php');
		file_put_contents($fixture.'/php/xmlrpc.php', '<?php '
			.'class FileUtil { public static function toLog($message) { echo "LOG:".$message."\n"; } public static function getPluginConf($plugin) { return ""; } } '
			.'class rXMLRPCCommand { public $command; public $params; public function __construct($command,$params=null) {'
			.'$this->command=$command;$this->params=$params;} } '
			.'class rXMLRPCRequest { public static $commands=array(); public $val=array(); public $fault=false; public $faultString=""; '
			.'private $items=array(); public function __construct($items=null) { if(is_array($items))$this->items=$items;'
			.'else if($items!==null)$this->items=array($items); } public function addCommand($item) {$this->items[]=$item;} '
			.'public function success($trusted=true) { foreach($this->items as $item)self::$commands[]=$item->command; return true; } } '
			.'function getCmd($command) { return $command; } '
			.'class CachedEcho { public static function send($content,$type) { echo "BODY:".$content."\n"; } } '
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
				.'(is_string($force)?$force:gettype($force)); return array(); }'
				// The shared admission door both public doors now enter. It
				// records the exact PHP type it was handed, so a door that
				// forwarded the wire spelling instead of the integer force is
				// visible here rather than deep inside the producer.
				.'function erasedataReportPartialRemovalRefusal($result) {} '
				.'function erasedataAdmissionRefusalReason() { return isset($GLOBALS["fixtureRemovalReason"]) '
				.'? $GLOBALS["fixtureRemovalReason"] : "hash-lock"; } '
				.'function erasedataRemovalRefusalMessage($reason) { return "Torrent busy; try again."; } '
				.'function erasedataAdmitRemoval($hashes,$force) { '
				.'$normalized=ErasedataManifestCodec::normalizeForce($force); '
				.'if($normalized===null) { $GLOBALS["fixtureRemovalReason"]="invalid-force"; return false; } '
				.'rXMLRPCRequest::$commands[]="admit:".$normalized; '
				.($admissionFailure ? 'return false;' : 'return array();').'}');
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
			.'echo "HTTP_STATUS:".http_response_code()."\\n";'
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
			'captureLogs' => false, 'retentionSink' => null, 'indexCountFile' => null,
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
			'fleetRows' => array(), 'fleetFault' => false,
			'fleetSources' => array(), 'fleetReplies' => array(),
		));
	}

	private function writeLegacyCapturedDirectoryIntent($path)
	{
		$record = json_decode(@file_get_contents($path), true);
		if(!is_array($record)) return(false);
		$record['version'] = 1;
		$record['phase'] = 'captured';
		return(file_put_contents($path,
			json_encode($record, JSON_UNESCAPED_SLASHES)) !== false);
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
				'basename' => basename($options['restoreCollision']), 'action' => 'collide');
		if($options['reservedSwap'] !== null)
			$scenario['rename:*'] = array(
				'inode' => $this->collectorInode($options['reservedSwap']),
				'action' => 'swap-destination', 'at' => 'after');
		if($options['forceTargetSwap'] !== null)
			$scenario['rename:*'] = array(
				'realpath' => $this->collectorRecoveryTarget($options['forceTargetSwap']),
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
			$scenario['unlink:*'] = array('basename' => 'entry',
				'contains' => '/.erasedata-entry-', 'result' => false);
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
		$responses['d.multicall'] = array('ok'=>true, 'fault'=>$options['fleetFault'],
			'val'=>$options['fleetRows'], 'swap'=>null);
		foreach($options['fleetReplies'] as $hash => $reply)
		{
			foreach(array('frozen' => 'd.get_base_path', 'stored' => 'd.get_directory') as $key => $command)
				if(isset($reply[$key]))
				{
					if(!isset($responses[$command]['byHash']))
						$responses[$command]['byHash'] = array();
					$responses[$command]['byHash'][$hash] = $reply[$key] + array('swap'=>null);
				}
		}
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
			'fleetSources' => ErasedataCollectorTestState::$fleetSources,
			'countFile' => ErasedataCollectorTestState::$indexCountFile,
			'debug' => isset($erasedebug_enabled) ? $erasedebug_enabled : false,
			'argv' => $argv,
			'log' => count(FileUtil::$log),
		);
		rXMLRPCRequest::$responses = $responses;
		ErasedataCollectorTestState::$source = $source;
		ErasedataCollectorTestState::$fleetSources = $options['fleetSources'];
		ErasedataCollectorTestState::$indexCountFile = $options['indexCountFile'];
		$erasedebug_enabled = (bool)$options['debug'];
		$argv = array('update.php', 'rutorrent');
		if($options['onlyHash'] !== null)
			$argv[] = $options['onlyHash'];
		$output = '';
		ob_start();
		try {
			if($options['retentionSink'] !== null)
			{
				$collector = erasedataCollectorService(
					new ErasedataCollectorFixture($options['filesystem']));
				$collector->reportRetentionsTo($options['retentionSink']);
				$collector->run(FileUtil::getSettingsPath().'/erasedata',
					$options['onlyHash']);
			}
			else if($options['publicCollectorHash'] !== null)
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
		ErasedataCollectorTestState::$fleetSources = $saved['fleetSources'];
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
		$result = erasedataRemoveWithData(array($hash), 1);
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
		$result = erasedataRemoveWithData(array($hash), 1);
		$this->assertEquals(array("/d/a.bin", "/d/a.bin", "0", "1"), $this->listFor($hash), 'single-file list from frozen paths');
	}

	public function testFallsBackToStoredPathsForMultiFile()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("", 1, "", ""));
		$this->stored(true, array("/d/name", 1, "a.bin", "sub/b.bin"));
		$this->eraseOk();
		$result = erasedataRemoveWithData(array($hash), 1);
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
		$result = erasedataRemoveWithData(array($hash), 1);
		$this->assertEquals(array("/d/movie.mkv", "/d/movie.mkv", "0", "1"), $this->listFor($hash), 'single-file base path is the file, not its directory');
	}

	public function testFallbackNormalisesTrailingSlash()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("", 1, ""));
		$this->stored(true, array("/d/name/", 1, "a.bin"));
		$this->eraseOk();
		$result = erasedataRemoveWithData(array($hash), 1);
		$this->assertEquals(array("/d/name/a.bin", "/d/name", "1", "1"), $this->listFor($hash), 'no doubled separator from a trailing slash');
	}

	public function testFallbackUsedWhenFrozenRequestFails()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(false, array());
		$this->stored(true, array("/d/name", 1, "a.bin"));
		$this->eraseOk();
		$result = erasedataRemoveWithData(array($hash), 1);
		$this->assertEquals(array("/d/name/a.bin", "/d/name", "1", "1"), $this->listFor($hash), 'a failed frozen request also falls back');
	}

	// -- force-delete flag --------------------------------------------------

	public function testEmptyShareCreatesDefaultSettingsSafely()
	{
		$this->reset();
		chmod($this->dir, 0700);
		$share = $this->dir.'/empty-share';
		mkdir($share, 0755);
		$settings = $share.'/settings';
		$queue = $settings.'/erasedata';
		$this->assertTrue(!file_exists($settings), 'the default settings directory is initially absent');
		$this->assertTrue(erasedataEnsureQueueDirectory($queue),
			'the default profile creates settings below a protected share');
		$this->assertEquals(01777, fileperms($settings) & 01777,
			'the new settings directory protects the queue name');
		$this->assertEquals(0700, $this->modeOf($queue),
			'the default queue is private');
	}

	public function testFreshDockerProfileUsesProtectedQueueForRemoval()
	{
		$this->reset();
		$share = $this->dir.'/share';
		$settings = $share.'/settings';
		$protected = $this->dir.'/erasedata-jobs';
		mkdir($share, 0775);
		mkdir($settings, 0775);
		mkdir($protected, 0700);
		file_put_contents($this->dir.'/.erasedata-first-use', '');
		chmod($this->dir.'/.erasedata-first-use', 0400);
		chmod($share, 0775);
		chmod($settings, 0775);
		FileUtil::$settingsPath = $settings;
		try
		{
			$hash = $this->hash('D');
			$payload = $this->dir.'/fresh-docker-payload.bin';
			file_put_contents($payload, 'payload');
			$this->frozen(true, array($payload, 0, $payload));
			$this->eraseOk();
			$this->probe(true, false, array($hash));
			$queue = $protected.'/default';
			if(erasedataEffectiveUid() !== 0)
			{
				$this->assertTrue(erasedataAdmitRemoval(array($hash), 1) === false,
					'a service-owned preplant is not a root first-use witness');
				$this->assertEquals(array(), rXMLRPCRequest::$erased,
					'no erase follows the forged witness');
				return;
			}
			$this->acknowledgeOnRegistration($queue);
			$this->assertTrue(erasedataAdmitRemoval(array($hash), 1) !== false,
				'a root-provisioned stock Docker profile admits the public deletion');
			$this->assertTrue(count(rXMLRPCRequest::$erased) === 1,
				'the admitted request reaches the erase RPC');
			$this->assertEquals(0700, $this->modeOf($queue),
				'the new queue is outside group-writable share and private');
			$this->assertTrue(count(glob($queue.'/*.list')) === 1,
				'the removal has a durable manifest in that queue');
			$this->assertTrue(!file_exists($settings.'/erasedata'),
				'no second queue appears under unsafe settings');
			$this->probe(true, true, array(), 'info-hash not found');
			erasedataRunCollector($queue);
			$this->assertTrue(!file_exists($payload) && count(glob($queue.'/*.list')) === 0,
				'the same protected queue serves a later collector recovery');
		}
		finally
		{
			FileUtil::$settingsPath = $this->dir;
			$this->removePath($share);
			@unlink($this->dir.'/.erasedata-first-use');
			$this->removePath($protected);
		}
	}

	public function testVanishedLegacyQueueWithoutProtectedRootStaysHeld()
	{
		$this->reset();
		$share = $this->dir.'/share';
		$settings = $share.'/settings';
		mkdir($share, 0775);
		mkdir($settings, 0775);
		chmod($share, 0775);
		chmod($settings, 0775);
		mkdir($settings.'/erasedata', 0700);
		file_put_contents($settings.'/erasedata/old.list', 'unattested intent');
		$this->assertTrue(rename($settings.'/erasedata', $settings.'/erasedata.hidden'),
			'an old queue can vanish under an unsafe settings directory');
		FileUtil::$settingsPath = $settings;
		try
		{
			$hash = $this->hash('D');
			$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
			$this->eraseOk();
			$this->assertTrue(erasedataRemoveWithData(array($hash), 1) === false,
				'absence of the legacy queue does not attest its history');
			$this->assertEquals(array(), rXMLRPCRequest::$erased,
				'no erase follows an unproved first-use claim');
			$this->assertEquals('unattested intent',
				file_get_contents($settings.'/erasedata.hidden/old.list'),
				'the lost old intent remains outside the new queue');
		}
		finally
		{
			FileUtil::$settingsPath = $this->dir;
			$this->removePath($share);
		}
	}

	public function testProtectedRootNeverHidesUnattestedLegacyQueue()
	{
		$this->reset();
		$share = $this->dir.'/share';
		$settings = $share.'/settings';
		$protected = $this->dir.'/erasedata-jobs';
		mkdir($share, 0775);
		mkdir($settings, 0775);
		mkdir($settings.'/erasedata', 0700);
		mkdir($protected, 0700);
		file_put_contents($this->dir.'/.erasedata-first-use', '');
		chmod($this->dir.'/.erasedata-first-use', 0400);
		file_put_contents($settings.'/erasedata/old.list', 'old intent');
		chmod($share, 0775);
		chmod($settings, 0775);
		FileUtil::$settingsPath = $settings;
		try
		{
			$hash = $this->hash('D');
			$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
			$this->eraseOk();
			$this->assertTrue(erasedataRemoveWithData(array($hash), 1) === false,
				'the new root does not shadow a visible legacy obligation');
			$this->assertEquals(array(), rXMLRPCRequest::$erased,
				'no destructive RPC follows a split legacy state');
			$this->assertEquals('old intent',
				file_get_contents($settings.'/erasedata/old.list'),
				'the legacy bytes remain for operator attestation');
		}
		finally
		{
			FileUtil::$settingsPath = $this->dir;
			$this->removePath($share);
			@unlink($this->dir.'/.erasedata-first-use');
			$this->removePath($protected);
		}
	}

	public function testNamedDockerProfileHasSeparateProtectedQueue()
	{
		$this->reset();
		$share = $this->dir.'/share';
		$settings = $share.'/users/alice/settings';
		$protected = $this->dir.'/erasedata-jobs';
		mkdir($settings, 0775, true);
		mkdir($protected, 0700);
		file_put_contents($this->dir.'/.erasedata-first-use', '');
		chmod($this->dir.'/.erasedata-first-use', 0400);
		foreach(array($share, $share.'/users', $share.'/users/alice', $settings) as $dir)
			chmod($dir, 0775);
		FileUtil::$settingsPath = $settings;
		try
		{
			$queue = erasedataQueuePath();
			if(erasedataEffectiveUid() !== 0)
			{
				$this->assertTrue($queue === false,
					'a named profile cannot borrow a service-owned preplant');
				return;
			}
			$this->assertEquals($protected.'/user-'.hash('sha256', 'alice'), $queue,
				'a named profile maps to its own stable leaf');
			$alias = $this->dir.'/share-alias';
			symlink($share, $alias);
			FileUtil::$settingsPath = $alias.'/users/alice/settings';
			$this->assertEquals($queue, erasedataQueuePath(),
				'web and CLI aliases resolve to one canonical named queue');
			$this->assertTrue(erasedataEnsureQueueDirectory($queue),
				'the protected root admits a named profile');
			$this->assertEquals(0700, $this->modeOf($queue),
				'the named queue is owner-only');
		}
		finally
		{
			FileUtil::$settingsPath = $this->dir;
			@unlink($this->dir.'/share-alias');
			$this->removePath($share);
			@unlink($this->dir.'/.erasedata-first-use');
			$this->removePath($protected);
		}
	}

	public function testCustomProfileDoesNotBorrowDockerFirstUseRoot()
	{
		$this->reset();
		$settings = $this->dir.'/custom-profile/settings';
		$protected = $this->dir.'/erasedata-jobs';
		mkdir($settings, 0775, true);
		mkdir($protected, 0700);
		chmod(dirname($settings), 0775);
		chmod($settings, 0775);
		FileUtil::$settingsPath = $settings;
		try
		{
			$this->assertEquals($settings.'/erasedata', erasedataQueuePath(),
				'a custom profile has no Docker first-use witness');
			$this->assertTrue(!erasedataEnsureQueueDirectory(erasedataQueuePath()),
				'the unsafe custom profile remains held');
			$this->assertTrue(!file_exists($protected.'/default'),
				'no unrelated protected queue is selected');
		}
		finally
		{
			FileUtil::$settingsPath = $this->dir;
			$this->removePath(dirname($settings));
			$this->removePath($protected);
		}
	}

	public function testOfflineSealedLegacyQueueResumesItsAbsolutePendingJournal()
	{
		$this->reset();
		$share = $this->dir.'/share';
		$settings = $share.'/settings';
		$queue = $settings.'/erasedata';
		mkdir($queue, 0700, true);
		chmod($share, 0775);
		chmod($settings, 0775);
		chmod($queue, 0700);
		FileUtil::$settingsPath = $settings;
		try
		{
			$hash = $this->hash('D');
			$generation = '0000000000000001';
			$payload = $this->dir.'/legacy-payload.bin';
			file_put_contents($payload, 'pending old data');
			$bytes = ErasedataManifestCodec::encode($hash,
				array('base' => $payload, 'multi' => '0', 'files' => array($payload)), 1);
			$staged = erasedataStageAdmittedManifest($queue, $hash, $generation, $bytes);
			$this->assertTrue(is_array($staged), 'the old queue has a real staging inode');
			if(!is_array($staged)) return;
			$this->assertTrue(erasedataQueueRequest($queue, $hash, 1, $generation),
				'the old queue has a pending marker');
			$this->armQueue($queue, $generation, array($hash => $staged['path']));
			$this->probe(true, true, array(), 'info-hash not found');
			rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true, 'val' => array(0));
			$this->assertTrue(!erasedataDrainWorkerMain(User::getUser()),
				'unsafe old parents hold the pending journal before attestation');
			$this->assertEquals('pending old data', file_get_contents($payload),
				'the refused pass preserves the payload');
			chmod($share, 01775);
			chmod($settings, 01775);
			$this->assertTrue(erasedataEnsureQueueDirectory($queue),
				'offline sealing makes the original absolute queue safe');
			$this->assertTrue(erasedataDrainWorkerMain(User::getUser()),
				'the worker resumes the old journal at its original absolute path');
			$this->assertTrue(!file_exists($payload),
				'the resumed obligation removes its exact payload');
			$this->assertTrue(!file_exists($staged['path'])
				&& !erasedataPendingMarkerStands($queue, $hash, $generation),
				'the original staging and marker are retired');
		}
		finally
		{
			FileUtil::$settingsPath = $this->dir;
			$this->removePath($share);
		}
	}

	public function testQueueAdmissionAcceptsOnlyAProvablyVanishedListedEntry()
	{
		$this->reset();
		$queue = $this->queuePath();
		$entry = $queue.'/finished.tmp';
		file_put_contents($entry, 'staging');
		$listed = scandir($queue);
		$this->assertTrue(in_array('finished.tmp', $listed, true)
			&& unlink($entry), 'the worker finished a name in the prior listing');
		$this->assertTrue(erasedataQueueEntriesOwned($queue, $listed,
			erasedataEffectiveUid(), $queue),
			'a proven vanished staging name does not refuse the next admission');
		$this->assertTrue(erasedataEnsureQueueDirectory($queue),
			'the real queue boundary remains available after that completion');
		file_put_contents($entry, 'still present');
		$listed = scandir($queue);
		chmod($queue, 0600);
		FileUtil::$log = array();
		$refused = !erasedataQueueEntriesOwned($queue, $listed,
			erasedataEffectiveUid(), $queue);
		chmod($queue, 0700);
		$this->assertTrue($refused,
			'a name still in the queue refuses when its inode is unreadable');
		$this->assertTrue(strpos(implode("\n", FileUtil::$log),
			'foreign or unreadable entry') !== false,
			'the still-present unreadable entry keeps the classified refusal');
	}

	public function testChangedQueueParentRefusalIsLogged()
	{
		$this->reset();
		$queue = $this->dir.'/erasedata';
		$this->assertTrue(!erasedataQueueParentUnchanged($queue, $this->dir.'/old-parent'),
			'a changed resolved parent is refused');
		$this->assertTrue((bool)array_filter(FileUtil::$log, function($line) {
			return strpos($line, 'queue parent changed during admission') !== false;
		}), 'a changed resolved parent gives a visible classified reason');
	}

	public function testBrokenSettingsSymlinkRefusalIsLogged()
	{
		$this->reset();
		chmod($this->dir, 0700);
		$share = $this->dir.'/symlink-share';
		mkdir($share, 0755);
		symlink($share.'/absent', $share.'/settings');
		$this->assertTrue(!erasedataEnsureQueueDirectory($share.'/settings/erasedata'),
			'a broken settings link is refused');
		$this->assertTrue((bool)array_filter(FileUtil::$log, function($line) {
			return strpos($line, 'settings path is not a directory') !== false;
		}), 'a broken settings link gives a visible classified reason');
	}

	public function testMissingSettingsParentRefusalIsLogged()
	{
		$this->reset();
		chmod($this->dir, 0700);
		$queue = $this->dir.'/absent-parent/settings/erasedata';
		$this->assertTrue(!erasedataEnsureQueueDirectory($queue),
			'a missing settings parent is refused');
		$this->assertTrue((bool)array_filter(FileUtil::$log, function($line) {
			return strpos($line, 'settings parent is unresolved') !== false;
		}), 'an unresolved settings parent gives a visible classified reason');
	}

	public function testLegacyNamedProfileRequiresAttestationEvenWhenQueueIsEmpty()
	{
		$this->reset();
		chmod($this->dir, 0700);
		$share = $this->dir.'/named-share';
		$users = $share.'/users';
		$profile = $users.'/alice';
		$settings = $profile.'/settings';
		$queue = $settings.'/erasedata';
		mkdir($queue, 0777, true);
		chmod($share, 0755);
		foreach(array($users, $profile, $settings, $queue) as $dir)
			chmod($dir, 0777);
		$this->assertTrue(!erasedataEnsureQueueDirectory($queue),
			'a previously writable named chain cannot prove an empty queue was always empty');
		$this->assertEquals(0777, fileperms($settings) & 07777,
			'the hold leaves the provenance gap visible');
		$this->assertEquals(0777, $this->modeOf($queue),
			'the untrusted queue is not silently adopted');
	}

	public function testWritableParentTaintsExistingPrivateQueueBeforeRepair()
	{
		$this->reset();
		$queue = $this->dir.'/erasedata';
		file_put_contents($queue.'/old.list', 'unattested');
		chmod($this->dir, 0777);
		chmod($queue, 0700);
		$hash = $this->hash();
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		for($pass = 1; $pass <= 2; $pass++)
		{
			$this->assertTrue(erasedataRemoveWithData(array($hash), 1) === false,
				'an old private queue under a writable parent is held on pass '.$pass);
			$this->assertEquals(array(), rXMLRPCRequest::$erased,
				'no erase follows a parent-name provenance gap');
		}
		$this->assertEquals(0777, fileperms($this->dir) & 0777,
			'the refused migration leaves parent taint observable on retry');
	}

	public function testLegacyWritableNonemptyQueueIsHeldBeforeErase()
	{
		$this->reset();
		$queue = $this->dir.'/erasedata';
		$legacy = $queue.'/legacy.list';
		file_put_contents($legacy, 'legacy bytes');
		chmod($queue, 0777);
		$hash = $this->hash();
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		$this->assertTrue(erasedataRemoveWithData(array($hash), 1) === false,
			'a previously writable queue with old contents must be held');
		$this->assertEquals(array(), rXMLRPCRequest::$erased,
			'no torrent is erased while old queue contents lack provenance');
		$this->assertEquals('legacy bytes', file_get_contents($legacy),
			'the old entry is preserved for attestation');
		$this->assertTrue((bool)array_filter(FileUtil::$log, function($line) {
			return strpos($line, 'legacy writable path needs operator attestation') !== false;
		}), 'the hold is visible in the log');
	}

	public function testLegacyProducerHoldsWritableQueueUntilOperatorAttests()
	{
		$this->reset();
		$queue = $this->dir.'/erasedata';
		chmod($this->dir, 0777);
		file_put_contents($queue.'/.drain-state.lock', '');
		file_put_contents($queue.'/scheduler.lock', '');
		chmod($queue, 0777);
		$hash = $this->hash();
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		$this->assertTrue(erasedataRemoveWithData(array($hash), 1) === false,
			'a writable legacy chain holds even when it contains only inert locks');
		$this->assertEquals(0777, fileperms($this->dir) & 07777,
			'the parent stays visibly unsealed for operator review');
		$this->assertEquals(0777, $this->modeOf($queue),
			'the old queue is not adopted by changing its mode');
		$this->assertEquals(array(), rXMLRPCRequest::$erased,
			'no destructive RPC follows an unattested legacy queue');
		chmod($this->dir, 01777);
		chmod($queue, 0700);
		$this->assertTrue(erasedataEnsureQueueDirectory($queue),
			'operator-sealed legacy paths can be admitted on a later attempt');
	}

	public function testForceDeleteFlagRecorded()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("/d/name", 1, "/d/name/a.bin"));
		$this->eraseOk();
		$result = erasedataRemoveWithData(array($hash), 2);
		$lines = $this->listFor($hash);
		$this->assertTrue(is_array($lines) && count($lines) > 0,
			'integer force 2 is admitted and publishes a manifest');
		$this->assertEquals("2", is_array($lines) && count($lines) ? end($lines) : null,
			'delete-path mode recorded as the last line');
	}

	// -- unresolvable download ----------------------------------------------

	public function testTorrentNotErasedWhenNoPathsResolve()
	{
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("", 1, "", ""));
		$this->stored(true, array("", 1, "", ""));
		$this->eraseOk();
		$result = erasedataRemoveWithData(array($hash), 1);
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
		erasedataRemoveWithData(array($hashA, $hashB), 1);
		$this->assertEquals(array($hashA, $hashB), rXMLRPCRequest::$erased, 'every resolvable hash is erased');
	}

	public function testTorrentNotErasedWhenManifestWriteFails()
	{
		if(testSkipUnlessPermissionsBite('that a manifest which cannot be written'
			.' stops the erase instead of letting it proceed unrecorded'))
			return;
		$this->reset();
		$hash = $this->hash();
		$this->frozen(true, array("/d/name", 1, "/d/name/a.bin"));
		$this->eraseOk();
		@chmod($this->dir.'/erasedata', 0555);
		FileUtil::$denyDirectoryRepair = true;
		try {
			$result = erasedataRemoveWithData(array($hash), 1);
			$this->assertTrue($result === false, 'removal must return false when manifest cannot be written');
			$this->assertEquals(array(), rXMLRPCRequest::$erased, 'torrent must not be erased when manifest write fails');
			$this->assertTrue(count(FileUtil::$log) > 0, 'the manifest write failure is logged');
		} finally {
			FileUtil::$denyDirectoryRepair = false;
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
		$result = erasedataRemoveWithData(array($hash), 1);
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
		$result = erasedataRemoveWithData(array($hash), 1);
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
		$result = erasedataRemoveWithData(array($hash), 1);
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
		erasedataRemoveWithData(array($hash), 1);
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
		erasedataRemoveWithData(array($hash), 1);
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
		$result = erasedataRemoveWithData(array($hash), 1);
		$this->assertTrue($result === false, 'publication failure is reported to the caller');
		$this->assertEquals(array(), $this->manifestFiles($hash), 'failed rename does not publish a partial list file');
		$this->assertEquals(1, count(glob($this->dir.'/erasedata/'.$hash.'.*.tmp')), 'failed rename retains complete staging for recovery');
	}

	public function testRejectsInvalidTraversalHashBeforeFilesystemOrRPCWork()
	{
		$this->reset();
		@rmdir($this->dir.'/erasedata');
		$result = erasedataRemoveWithData(array($this->hash(), '../outside'), 1);
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
		erasedataRemoveWithData(array($lower), 1);
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
		erasedataRemoveWithData(array($hash), 1);
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
		erasedataRemoveWithData(array($hash), 1);
		$tmp = glob($this->dir.'/erasedata/'.$hash.'.*.tmp');
		$this->assertEquals(1, count($tmp), 'the unknown erase result retains one complete staging manifest');
		$this->assertEquals(0660, count($tmp) ? $this->modeOf($tmp[0]) : null, 'completed staging has the shared profile mode');
		$reader = count($tmp) ? @fopen($tmp[0], 'r') : false;
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
			erasedataRemoveWithData(array($hash), 1);
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
		// The test manually discards the checked shell. The prepared intent
		// cannot infer safety from a persisted inode number alone after that.
		$intents = glob($this->queuePath().'/.erasedata-rmdir-intent-*');
		$this->assertTrue(count($intents) === 1,
			'the uncertain capture keeps its durable intent for manual recovery');
		if(count($intents) === 1)
			unlink($intents[0]);
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

	// Invariant 12 on the PAYLOAD side of the collector.
	//
	// Retention there used to go out through eLog(), which the shipped
	// $erasedebug_enabled = false silences, so a queue that retained every job
	// for ever produced not one line an operator could ever see -- the only way
	// to find out was to switch a debug flag on and wait for it to happen
	// again. The cleanup side of the same collector already reports its
	// retentions through the channel debug cannot silence; this pins that the
	// payload side does too, that it is bounded and classified per physical job
	// and generation, and that it leaks neither a raw path nor a payload byte.
	public function testTheOrdinaryCollectorRetainsTheJobAndLeaksNothingSayingSo()
	{
		$this->reset();
		$hash = $this->hash('9');
		$generation = '000000000000001a';
		$base = $this->dir.'/unconditional-retention-base';
		$payload = $base.'/payload.bin';
		$name = $hash.'.'.$generation.'.7.list';
		mkdir($base);
		file_put_contents($payload, 'payload-bytes-nobody-may-log');
		$this->writeManifestLines($name, array($payload), $base, 1, 1);
		// rTorrent answers nothing this collector can read, so the whole job is
		// retained -- unknown is not absence -- and debug stays off throughout.
		list($status, $output) = $this->runCollector(array('ok' => false,
			'captureLogs' => true));
		$this->assertEquals(0, $status,
			'an unreadable probe must not crash the collector: '.$output);
		$this->assertTrue(is_file($payload), 'the payload really is retained');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$name),
			'and so is the manifest that describes it');
		$logs = $this->collectorLogs($output);
		$named = array();
		foreach($logs as $line)
			if(strpos($line, $hash) !== false && strpos($line, $generation) !== false)
				$named[] = $line;
		// This case USED to require the line here, and that was the defect: the
		// ordinary schedule is registered unconditionally and fires every
		// $garbageCheckInterval in a fresh process, so a condition that lasts was
		// reported for the life of the installation -- measured at three of three
		// alternating cycles, roughly 5760 lines a day for one retained job.
		//
		// The report moved to the schedule that exists BECAUSE of the condition.
		// A published manifest classifies as 'final', keeps the retirement scan
		// non-empty and therefore keeps the drain armed for exactly as long as
		// there is something to say, so nothing is lost by this pass staying
		// quiet: testTheOrdinaryCollectorLeavesPayloadRetentionToTheDrain pins
		// both halves together, and this case keeps proving that the silence is
		// real silence and leaks nothing.
		$this->assertEquals(0, count($named),
			'the ordinary pass reports no payload retention: '.json_encode($logs));
		foreach($logs as $line)
		{
			$this->assertTrue(strpos($line, $this->dir) === false,
				'no collector diagnostic leaks the settings root or a raw path: '.$line);
			$this->assertTrue(strpos($line, 'payload-bytes-nobody-may-log') === false,
				'no collector diagnostic leaks payload bytes: '.$line);
		}
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
		erasedataRemoveWithData(array($hash), 1);
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
		erasedataRemoveWithData(array($hash), 1);
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
		erasedataRemoveWithData(array($hash), 1);
		$this->frozen(true, array($newBase, 1, $newFile));
		$this->eraseOk();
		erasedataRemoveWithData(array($hash), 1);

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
		erasedataRemoveWithData(array($hash), 1);
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
		erasedataRemoveWithData(array($hash), 1);
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
		$bytes = ErasedataManifestCodec::encode($hash,
			array('base' => $data, 'multi' => false, 'files' => array($data)), 1);
		file_put_contents($external, $bytes);
		file_put_contents($list, $bytes);
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
		$bytes = ErasedataManifestCodec::encode($hash,
			array('base' => $data, 'multi' => false, 'files' => array($data)), 1);
		file_put_contents($replacement, $bytes);
		file_put_contents($list, $bytes);
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
		// Invariant 13: an INVALID force is REFUSED, never coerced to "delete
		// the download's own files".
		//
		// What counts as invalid is the point. The exact integers 1 and 2 and
		// the exact decimal spellings "1" and "2" are the whole accepted
		// domain -- the spellings because an HTTP parameter and an argv word
		// are strings and cannot be anything else -- so they belong in the
		// case below this one, not in this list. Everything here is somebody's
		// mistake or somebody's injection, and guessing at one of them means
		// guessing at a deletion.
		$invalidForces = array(null, false, true, 0, 3, 9, -1, 1.0, 2.0,
			"", "0", "3", "9", "x", "one", "01", "02", "+1", " 1", "1 ", "1\n",
			"2\n1\n2", array(), array(1), (object)array('force' => 1));
		foreach($invalidForces as $inv)
		{
			rXMLRPCRequest::$erased = array();
			foreach(array_merge($this->manifestFiles($hash, 'tmp'),
				$this->manifestFiles($hash, 'list')) as $residue)
				@unlink($residue);
			$res = erasedataRemoveWithData(array($hash), $inv);
			$this->assertTrue($res === false, 'invalid force parameter must be rejected before staging/erase');
			$this->assertEquals(array(), rXMLRPCRequest::$erased, 'd.erase must not be called for invalid force');
			$this->assertEquals(array(), $this->manifestFiles($hash, 'tmp'), 'staging must not be published for invalid force');
			$this->assertEquals(array(), $this->manifestFiles($hash, 'list'), 'manifest must not be published for invalid force');
			// And nothing durable at all: no obligation marker, no journal.
			$this->assertEquals(array(), glob($this->queuePath().'/*.pending'),
				'no pending marker is written for invalid force');
			$this->assertTrue(!file_exists($this->queuePath().'/.drain-state'),
				'no drain journal is written for invalid force');
		}
		$this->assertEquals(array(), rXMLRPCRequest::$requested,
			'a refused force reaches no RPC at all');
	}

	// The shared admission API and retained compatibility producer both accept
	// the exact wire spellings. The HTTP RPC and direct doors now enter the
	// admission API, which normalizes force before writing the marker, journal
	// and staging record. This case also pins the producer's legacy contract.
	public function testWireForceSpellingsAreAcceptedAtThePublicDoorsAsIntegers()
	{
		$invariant = 'the exact wire spellings "1" and "2" are accepted at the'
			.' public doors and normalized once into the integer force';
		// A pair list, never an array key: PHP casts a decimal-string key to
		// an integer, so array("1" => 1) would hand this loop the very
		// integer the case exists to stop testing.
		foreach(array(array("1", 1), array("2", 2)) as $pair)
		{
			list($wire, $expected) = $pair;
			$this->assertTrue($wire === (string)$expected,
				'the case really hands the doors the STRING "'.$expected.'"');
			// (a) The retained compatibility producer.
			$this->reset();
			$hash = $this->hash();
			$this->frozen(true, array("/d/name", 1, "/d/name/a.bin"));
			$this->eraseOk();
			$result = erasedataRemoveWithData(array($hash), $wire);
			$this->assertTrue($result !== false,
				'the producer accepts the wire force "'.$wire.'" rather than failing closed');
			$this->assertEquals(array($hash), rXMLRPCRequest::$erased,
				'and really erases the requested download for wire force "'.$wire.'"');
			$record = $this->manifestRecordFor($hash);
			$this->assertTrue(is_array($record) && array_key_exists('force', $record)
				&& $record['force'] === $expected,
				'the published manifest carries the integer force '.$expected
					.' for wire force "'.$wire.'"');
		}
		if(!$this->requireApi(array('erasedataAdmitRemoval()',
			'erasedataReadDrainState()', 'erasedataDecodePendingMarker()'), $invariant))
			return;
		foreach(array(array("1", 1), array("2", 2)) as $pair)
		{
			list($wire, $expected) = $pair;
			$this->assertTrue($wire === (string)$expected,
				'the case really hands the doors the STRING "'.$expected.'"');
			// (b) The shared admission door, whose internal runner below it
			// takes the integer and nothing else.
			$this->reset();
			$hash = $this->hash();
			$queue = $this->queuePath();
			// A force-2 admission proves a real directory capability before it
			// stages anything (erasedataAcquireRemovalCapability, added by the
			// producer repair), so this door needs a base that really is a
			// directory on disk; a synthetic "/d/name" is refused with
			// descriptor-unavailable before the generation is armed. The
			// subject of the case is untouched: what is handed to the door is
			// still the raw wire STRING "$wire". reset() wipes everything under
			// $this->dir at the top of each iteration, so this needs no
			// teardown, and the per-wire name keeps the two iterations
			// independent.
			$base = $this->dir.'/wire-force-'.$wire;
			mkdir($base);
			file_put_contents($base.'/a.bin', 'payload');
			$this->frozen(true, array($base, 1, $base.'/a.bin'));
			// The marker is removed as part of discharging the obligation, so
			// it is captured at the instant of the erase RPC, which is after
			// it is durable and before it is discharged.
			$markers = array();
			$this->eraseOk(function($commands) use ($queue, &$markers)
			{
				foreach(glob($queue.'/*.pending') as $marker)
					$markers[basename($marker)] = @file_get_contents($marker);
			});
			$this->acknowledgeOnRegistration($queue);
			$outcome = erasedataAdmitRemoval(array($hash), $wire);
			$this->assertTrue(is_array($outcome) && isset($outcome['force'])
				&& $outcome['force'] === $expected,
				'the public admission door accepts wire force "'.$wire
					.'" and reports the integer '.$expected);
			$generation = is_array($outcome) && isset($outcome['generation'])
				? $outcome['generation'] : null;
			$state = erasedataReadDrainState($queue);
			$entry = is_array($state) && is_string($generation)
				&& isset($state['journal'][$generation])
				? $state['journal'][$generation] : array();
			$this->assertTrue(isset($entry['force']) && $entry['force'] === $expected,
				'the journal record binds the integer force '.$expected
					.' for wire force "'.$wire.'"');
			$this->assertTrue(isset($entry['staging'][$hash]['path'])
				&& is_string($generation)
				&& strpos((string)$entry['staging'][$hash]['path'], $generation) !== false,
				'the staging record of this generation exists for wire force "'.$wire.'"');
			$this->assertEquals(1, count($markers),
				'exactly one obligation marker was durable for wire force "'.$wire.'"');
			$marker = count($markers) ? erasedataDecodePendingMarker(
				current($markers)) : false;
			$this->assertTrue(is_array($marker) && array_key_exists('force', $marker)
				&& $marker['force'] === $expected,
				'the marker parses back to the integer '.$expected
					.' for wire force "'.$wire.'"');
			$this->assertTrue(is_array($marker) && isset($marker['generation'])
				&& $marker['generation'] === $generation,
				'and carries the generation the admission bound for wire force "'.$wire.'"');
			$staged = $this->manifestRecordFor($hash);
			$this->assertTrue(is_array($staged) && array_key_exists('force', $staged)
				&& $staged['force'] === $expected,
				'the manifest the admission published carries the integer force '
					.$expected.' for wire force "'.$wire.'"');
		}
		// Both callable boundaries accept the wire spelling themselves.
		$producer = $this->productionFunctionBody('removewithdata.php',
			'function erasedataRemoveWithData($hashes, $forceDelete)');
		$this->assertTrue(is_string($producer)
			&& strpos($producer, 'ErasedataManifestCodec::normalizeForce($forceDelete)') !== false,
			'the producer normalizes its own force argument');
		$this->assertTrue(is_string($producer)
			&& strpos($producer, '$forceDelete !== 1 && $forceDelete !== 2') === false,
			'and carries no integer-only type guard in front of that normalization');
		// The door is not the last function in the file, and every function in
		// it is nested inside an if(!function_exists()) guard, so a body scan
		// would run to the end of the file. These two exact lines are read off
		// the whole source instead, which is precise either way.
		$this->sourceHas('removewithdata.php',
			"\t\t\$normalizedForce = ErasedataManifestCodec::normalizeForce(\$force);\n",
			'the shared admission door normalizes its own force argument');
		$this->sourceHas('removewithdata.php', '), $hashes, $normalizedForce));',
			'and hands the internal runner the normalized integer, not the raw value');
	}

	public function testBothRemovalDoorsExplainAndLogMissingForce()
	{
		$this->reset();
		$hash = $this->hash();
		foreach(array('httprpc', 'erasedata') as $plugin)
		{
			list($status, $output, $commands) = $this->runCopiedAction(
				__DIR__.'/../../../plugins/'.$plugin.'/action.php',
				'mode=removewithdata&hash='.$hash, true);
			$this->assertEquals(0, $status, $plugin.' door exits normally: '.$output);
			$this->assertEquals(array(), $commands, $plugin.' door admits no deletion');
			$this->assertTrue(strpos($output, 'HTTP_STATUS:400') !== false,
				$plugin.' door returns bad-request status');
			$this->assertTrue(strpos($output, 'LOG:erasedata: removewithdata refused: missing or invalid v') !== false,
				$plugin.' door logs its exact refusal');
			$this->assertTrue(strpos($output, 'BODY:Invalid deletion request: missing or invalid v.') !== false,
				$plugin.' door tells the client what to fix');
		}
	}

	public function testBothRemovalDoorsRejectNoncanonicalForceThroughSharedAdmission()
	{
		$this->reset();
		$hash = $this->hash();
		foreach(array('httprpc', 'erasedata') as $plugin)
		{
			list($status, $output, $commands) = $this->runCopiedAction(
				__DIR__.'/../../../plugins/'.$plugin.'/action.php',
				'mode=removewithdata&hash='.$hash.'&v=01', true);
			$this->assertEquals(0, $status, $plugin.' door exits normally: '.$output);
			$this->assertEquals(array(), $commands, $plugin.' door never reaches erase');
			$this->assertTrue(strpos($output, 'LOG:erasedata: removewithdata refused: missing or invalid v') !== false,
				$plugin.' door logs a noncanonical force refusal');
			$this->assertTrue(strpos($output, 'BODY:Invalid deletion request: missing or invalid v.') !== false,
				$plugin.' door explains the invalid force');
		}
	}

	public function testHttprpcRemovalExplainsMissingHelper()
	{
		$this->reset();
		list($status, $output, $commands) = $this->runCopiedAction(
			__DIR__.'/../../../plugins/httprpc/action.php',
			'mode=removewithdata&hash='.$this->hash().'&v=1', false);
		$this->assertEquals(0, $status, 'httprpc door exits normally: '.$output);
		$this->assertEquals(array(), $commands, 'no raw erase without the helper');
		$this->assertTrue(strpos($output, 'HTTP_STATUS:409') !== false,
			'missing helper returns a refusal status');
		$this->assertTrue(strpos($output, 'LOG:erasedata: removewithdata refused: helper unavailable') !== false,
			'missing helper is visible in the server log');
		$this->assertTrue(strpos($output, 'BODY:Deletion handler unavailable.') !== false,
			'missing helper is named in the HTTP response');
	}

	public function testBothRemovalDoorsShowClassifiedBusyAdmissionRefusal()
	{
		$this->reset();
		$hash = $this->hash();
		foreach(array('httprpc', 'erasedata') as $plugin)
		{
			list($status, $output, $commands) = $this->runCopiedAction(
				__DIR__.'/../../../plugins/'.$plugin.'/action.php',
				'mode=removewithdata&hash='.$hash.'&v=1', true, true);
			$this->assertEquals(0, $status, $plugin.' door exits normally: '.$output);
			$this->assertEquals(array('admit:1'), $commands,
				$plugin.' door attempts exactly one admission');
			$this->assertTrue(strpos($output, 'HTTP_STATUS:409') !== false,
				$plugin.' door returns conflict status for a busy torrent');
			$this->assertTrue(strpos($output, 'LOG:erasedata: removewithdata refused: hash-lock') !== false,
				$plugin.' door records the classified cause');
			$this->assertTrue(strpos($output, 'BODY:Torrent busy; try again.') !== false,
				$plugin.' door sends the classified cause to its client');
			$this->assertTrue(strpos($output, 'Could not reach rTorrent') === false,
				$plugin.' door does not mislabel a busy torrent as disconnected rTorrent');
		}
		erasedataAdmissionRefusalReason('admission-refused');
		erasedataDrainDiagnostic(array('FileUtil', 'toLog'), 'hash-lock', null, 1,
			'admission-refused');
		$this->assertEquals('hash-lock', erasedataAdmissionRefusalReason(),
			'the production diagnostic records the reason exposed by the door');
		$this->assertEquals('Torrent busy; try again.',
			erasedataRemovalRefusalMessage(erasedataAdmissionRefusalReason()),
			'the production response explains an occupied torrent');
	}

	public function testPartialAdmissionRefusalHasOneBoundedSummaryLog()
	{
		$this->reset();
		erasedataReportPartialRemovalRefusal(array('refused' => array('invalid-hash:string',
			'invalid-hash:string', $this->hash())));
		$this->assertEquals(array('erasedata: removewithdata partially refused: reason=invalid-hash members=2',
			'erasedata: removewithdata partially refused: reason=descriptor-unavailable members=1'),
			FileUtil::$log, 'partial refusal reports a count without the submitted values');
	}

	public function testHttprpcRemovalEntersAdmissionWithValidWireForceOnly()
	{
		$this->reset();
		$hash = $this->hash();
		$door = __DIR__.'/../../../plugins/httprpc/action.php';
		foreach(array('1', '2') as $force)
		{
			list($status, $output, $commands) = $this->runCopiedAction($door,
				'mode=removewithdata&hash='.$hash.'&v='.$force, true);
			$this->assertEquals(0, $status,
				'copied httprpc action exits normally for force '.$force.': '.$output);
			$this->assertEquals(array('admit:'.$force), $commands,
				'httprpc uses the shared admission door with integer force '.$force);
		}
		foreach(array('', '01', '1=2') as $force)
		{
			$body = 'mode=removewithdata&hash='.$hash;
			if($force !== '')
				$body .= '&v='.$force;
			list($status, $output, $commands) = $this->runCopiedAction($door,
				$body, true);
			$this->assertEquals(0, $status,
				'copied httprpc action exits normally for invalid force: '.$output);
			$this->assertEquals(array(), $commands,
				'invalid force never reaches either deletion producer');
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
		$res = erasedataRemoveWithData(array($hash), 1);
		$this->assertTrue($res !== false, 'valid request with byte-opaque path must succeed');
		$record = $this->manifestRecordFor($hash);
		$this->assertTrue(is_array($record), 'v2 manifest record must decode properly');
		$record = is_array($record) ? $record : array();
		$this->assertEquals(2, isset($record['version']) ? $record['version'] : null, 'version must be 2');
		$this->assertEquals($hash, isset($record['hash']) ? $record['hash'] : null, 'hash must match');
		$this->assertEquals(array($specialFile), isset($record['files']) ? $record['files'] : null, 'path bytes with newline, CR, and non-UTF8 must be strictly preserved');
		$this->assertEquals($base, isset($record['base']) ? $record['base'] : null, 'base path must be strictly preserved');
		$this->assertTrue(isset($record['multi']) && $record['multi'], 'multi flag must be boolean true');
		$this->assertEquals(1, isset($record['force']) ? $record['force'] : null, 'force must be normalized int 1');
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

	public function testEverySupportedManifestGenerationDecodesToOneNormalizedRecordShape()
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
			'v2' => array($v2, $hash, 2, 'remove_payload', false),
			'v3 cleanup' => array($cleanup, $oldHash, 3, 'cleanup_obsolete', false),
		);
		$this->assertEquals(false, ErasedataManifestCodec::decodeBytes(
			$file."\n".$file."\n0\n1\n", $hash),
			'unverified plaintext has no normalized record');
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
			'legacy plaintext with valid-looking footer' => $file."\n".$file."\n0\n1\n",
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

	public function testCleanupRechecksOwnerAfterCaptureWithoutPublishingBridge()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$thirdHash = $this->hash('C');
		$base = $this->dir.'/late-owner';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'late owned bytes');
		$original = lstat($old);
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array(
			'fleetReplies' => array($thirdHash => array('stored' => array(
				'ok' => true, 'fault' => false, 'val' => array($base, 0, 'old.bin')))),
			'filesystem' => array('rename:1' => array('path' => $old,
				'action' => 'fleet-change', 'at' => 'after',
				'rows' => array($thirdHash, '', '', ''),
				'sources' => array($thirdHash => array('hash' => $thirdHash,
					'info' => array('name' => 'old.bin', 'length' => 16))))),
			'captureLogs' => true,
		));
		$this->assertEquals(0, $status, 'a late third-owner claim must not crash cleanup: '.$output);
		$roots = glob($base.'/.erasedata-entry-*');
		$restored = @lstat($old);
		$this->assertEquals('late owned bytes', @file_get_contents($old),
			'a late owner keeps the exact obsolete bytes');
		$this->assertTrue(is_array($restored) && $restored['dev'] === $original['dev']
			&& $restored['ino'] === $original['ino'] && !is_link($old),
			'the captured inode must be restored at the public name without a bridge');
		$this->assertTrue(count($roots) === 0 && !is_file($tmp),
			'a proved late claim ends this obsolete-file obligation without a private hardlink');
		list($status, $output) = $this->runCollector(array(
			'fleetRows' => array($thirdHash, '', '', ''),
			'fleetSources' => array($thirdHash => array('hash' => $thirdHash,
				'info' => array('name' => 'old.bin', 'length' => 16))),
			'fleetReplies' => array($thirdHash => array('stored' => array(
				'ok' => true, 'fault' => false, 'val' => array($base, 0, 'old.bin')))),
			'captureLogs' => true,
		));
		$this->assertEquals(0, $status, 'claimed capture retry must run: '.$output);
		$again = @lstat($old);
		$this->assertTrue(is_array($again) && $again['ino'] === $original['ino']
			&& count(glob($base.'/.erasedata-entry-*')) === 0,
			'a later collector pass cannot delete a file now owned by the third torrent');
	}


	public function testCleanupUnknownAfterCaptureRetainsBytesAndJob()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/late-unknown';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'unknown bytes');
		$original = lstat($old);
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array(
			'filesystem' => array('rename:1' => array('path' => $old,
				'action' => 'fleet-change', 'at' => 'after', 'fault' => true)),
			'captureLogs' => true,
		));
		$this->assertEquals(0, $status, 'a failed post-capture scan must not crash: '.$output);
		$roots = glob($base.'/.erasedata-entry-*');
		$restored = @lstat($old);
		$this->assertEquals('unknown bytes', @file_get_contents($old),
			'unknown post-capture ownership must restore the original bytes');
		$this->assertTrue(is_array($restored) && $restored['dev'] === $original['dev']
			&& $restored['ino'] === $original['ino'] && !is_link($old)
			&& count($roots) === 0 && is_file($tmp)
			&& strpos($output, 'rpc-unknown') !== false,
			'unknown ownership restores the exact inode but retains the job visibly');
		list($retryStatus, $retryOutput) = $this->runCollector(array('captureLogs' => true));
		$this->assertEquals(0, $retryStatus, 'the later readable retry runs: '.$retryOutput);
		$this->assertTrue(!erasedataPathExists($old) && !is_file($tmp),
			'a later unclaimed retry completes the retained obligation');
	}



	public function testCleanupRestoreNeverReplacesAConcurrentPublicOccupant()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$thirdHash = $this->hash('C');
		$base = $this->dir.'/restore-occupied';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		$replacement = $base.'/new-owner.bin';
		file_put_contents($old, 'captured obsolete bytes');
		file_put_contents($replacement, 'concurrent new bytes');
		$original = lstat($old);
		$newcomer = lstat($replacement);
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array(
			'fleetReplies' => array($thirdHash => array('stored' => array(
				'ok' => true, 'fault' => false, 'val' => array($base, 0, 'old.bin')))),
			'filesystem' => array(
				'rename:1' => array('path' => $old, 'action' => 'fleet-change',
					'at' => 'after', 'rows' => array($thirdHash, '', '', ''),
					'sources' => array($thirdHash => array('hash' => $thirdHash,
						'info' => array('name' => 'old.bin', 'length' => 23)))),
				'renameNoReplace:1' => array('path' => $old,
					'action' => 'replace-public', 'at' => 'before',
					'public_path' => $old, 'replacement' => $replacement),
			),
			'captureLogs' => true,
		));
		$this->assertEquals(0, $status, 'an occupied restore must not crash: '.$output);
		$roots = glob($base.'/.erasedata-entry-*');
		$visible = @lstat($old);
		$private = count($roots) === 1 ? @lstat($roots[0].'/entry') : false;
		$this->assertTrue(is_array($visible) && $visible['dev'] === $newcomer['dev']
			&& $visible['ino'] === $newcomer['ino']
			&& @file_get_contents($old) === 'concurrent new bytes',
			'the no-replace restore must never clobber a concurrent public object');
		$this->assertTrue(is_array($private) && $private['dev'] === $original['dev']
			&& $private['ino'] === $original['ino']
			&& @file_get_contents($roots[0].'/entry') === 'captured obsolete bytes'
			&& is_file($tmp) && strpos($output, 'restore-occupied') !== false,
			'the original inode and its obligation remain visible in private quarantine');
	}

	public function testCleanupRequiresNoPublicBridgeForUnclaimedDeletion()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/no-bridge';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'obsolete');
		$this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$bridgeCalls = $this->dir.'/cleanup-bridge-calls';
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'makeSymlink:*' => array('path' => $old, 'result' => false,
				'count_file' => $bridgeCalls))));
		$this->assertEquals(0, $status, 'the no-bridge collector must run: '.$output);
		$this->assertTrue(!file_exists($old) && !is_link($old)
			&& $this->onlyManifest($oldHash) === false
			&& !is_file($bridgeCalls),
			'an unclaimed obsolete file must complete without attempting a public bridge');
	}


	public function testCleanupRestoresRelativeSymlinkClaimedAfterCapture()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$thirdHash = $this->hash('C');
		$base = $this->dir.'/relative-link';
		@mkdir($base, 0777, true);
		$target = $base.'/shared.bin';
		$old = $base.'/old-link';
		file_put_contents($target, 'shared bytes');
		$this->assertTrue(symlink('shared.bin', $old), 'fixture creates a relative obsolete symlink');
		$original = lstat($old);
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array(
			'fleetReplies' => array($thirdHash => array('stored' => array(
				'ok' => true, 'fault' => false, 'val' => array($base, 0, 'old-link')))),
			'filesystem' => array('rename:1' => array('path' => $old,
				'action' => 'fleet-change', 'at' => 'after',
				'rows' => array($thirdHash, '', '', ''),
				'sources' => array($thirdHash => array('hash' => $thirdHash,
					'info' => array('name' => 'old-link', 'length' => 12))))),
			'captureLogs' => true,
		));
		$restored = @lstat($old);
		$this->assertEquals(0, $status, 'relative symlink retry must run: '.$output);
		$this->assertTrue(is_array($restored) && $restored['dev'] === $original['dev']
			&& $restored['ino'] === $original['ino'] && is_link($old)
			&& readlink($old) === 'shared.bin' && file_get_contents($old) === 'shared bytes'
			&& file_get_contents($target) === 'shared bytes',
			'a late claim restores the exact relative link and original target');
		$this->assertTrue(!is_file($tmp) && count(glob($base.'/.erasedata-entry-*')) === 0,
			'claimed relative symlink leaves no private capture');
	}

	public function testCleanupRechecksPhysicalHardlinkClaimAfterCapture()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$thirdHash = $this->hash('C');
		$base = $this->dir.'/late-hardlink';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		$alias = $base.'/third.bin';
		file_put_contents($old, 'same bytes');
		$this->assertTrue(link($old, $alias), 'fixture creates a physical alias');
		$original = lstat($old);
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array(
			'fleetReplies' => array($thirdHash => array('stored' => array(
				'ok' => true, 'fault' => false, 'val' => array($base, 0, 'third.bin')))),
			'filesystem' => array('rename:1' => array('path' => $old,
				'action' => 'fleet-change', 'at' => 'after',
				'rows' => array($thirdHash, '', '', ''),
				'sources' => array($thirdHash => array('hash' => $thirdHash,
					'info' => array('name' => 'third.bin', 'length' => 10))))),
		));
		$restored = @lstat($old);
		$this->assertEquals(0, $status, 'late hardlink claimant must not crash: '.$output);
		$this->assertTrue(is_array($restored) && $restored['dev'] === $original['dev']
			&& $restored['ino'] === $original['ino']
			&& file_get_contents($alias) === 'same bytes'
			&& file_get_contents($old) === 'same bytes',
			'post-capture physical ownership restores the exact hardlinked inode');
		$this->assertTrue(!is_file($tmp) && count(glob($base.'/.erasedata-entry-*')) === 0,
			'a proved hardlink claim completes without private residue');
	}

	public function testCleanupRestoresParentClaimedAfterCapture()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$thirdHash = $this->hash('C');
		$base = $this->dir.'/late-parent';
		$nested = $base.'/season';
		@mkdir($nested, 0777, true);
		$old = $nested.'/old.bin';
		file_put_contents($old, 'obsolete');
		$original = lstat($nested);
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array(
			'fleetReplies' => array($thirdHash => array('stored' => array(
				'ok' => true, 'fault' => false,
				'val' => array($base, 1, 'season/missing.bin')))),
			'filesystem' => array('rename:*' => array('path' => $nested,
				'action' => 'fleet-change', 'at' => 'after',
				'rows' => array($thirdHash, '', '', ''),
				'sources' => array($thirdHash => array('hash' => $thirdHash,
					'info' => array('name' => basename($base), 'files' => array(
						array('path' => array('season', 'missing.bin'), 'length' => 1))))))),
			'captureLogs' => true,
		));
		$restored = @lstat($nested);
		$this->assertEquals(0, $status, 'late parent owner must not crash: '.$output);
		$this->assertTrue(is_array($restored) && $restored['dev'] === $original['dev']
			&& $restored['ino'] === $original['ino'] && is_dir($nested)
			&& !is_link($nested) && !erasedataPathExists($old),
			'a missing descendant claim restores the exact empty parent inode');
		$this->assertTrue(!is_file($tmp) && count(glob($base.'/.erasedata-rmdir-*')) === 0,
			'a proved parent claim ends the reservation without a bridge: job='.(is_file($tmp) ? 'yes' : 'no').' roots='.count(glob($base.'/.erasedata-rmdir-*')));
	}

	public function testCleanupParentRestoreNeverClobbersConcurrentDirectory()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$thirdHash = $this->hash('C');
		$base = $this->dir.'/occupied-parent';
		$nested = $base.'/season';
		$replacement = $base.'/other-season';
		@mkdir($nested, 0777, true);
		@mkdir($replacement, 0777, true);
		$old = $nested.'/old.bin';
		file_put_contents($old, 'obsolete');
		file_put_contents($replacement.'/active.bin', 'active bytes');
		$original = lstat($nested);
		$newcomer = lstat($replacement);
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array(
			'fleetReplies' => array($thirdHash => array('stored' => array(
				'ok' => true, 'fault' => false,
				'val' => array($base, 1, 'season/missing.bin')))),
			'filesystem' => array(
				'rename:*' => array('path' => $nested, 'action' => 'fleet-change',
					'at' => 'after', 'rows' => array($thirdHash, '', '', ''),
					'sources' => array($thirdHash => array('hash' => $thirdHash,
						'info' => array('name' => basename($base), 'files' => array(
						array('path' => array('season', 'missing.bin'), 'length' => 1)))))),
				'renameNoReplace:1' => array('path' => $nested,
					'action' => 'replace-public', 'at' => 'before',
					'public_path' => $nested, 'replacement' => $replacement)),
			'captureLogs' => true,
		));
		$visible = @lstat($nested);
		$roots = glob($base.'/.erasedata-rmdir-*');
		$private = count($roots) === 1 ? @lstat($roots[0].'/directory') : false;
		$this->assertEquals(0, $status, 'occupied parent restore must not crash: '.$output);
		$this->assertTrue(is_array($visible) && $visible['ino'] === $newcomer['ino']
			&& file_get_contents($nested.'/active.bin') === 'active bytes',
			'the no-replace restore does not overwrite a concurrent directory');
		$this->assertTrue(is_array($private) && $private['ino'] === $original['ino']
			&& is_file($tmp) && strpos($output, 'restore-occupied') !== false,
			'the captured empty parent and exact job remain visible for recovery');
	}

	public function testCleanupRetryRestoresParentCapturedBeforeCrash()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$thirdHash = $this->hash('C');
		$base = $this->dir.'/parent-capture-crash';
		$nested = $base.'/season';
		@mkdir($nested, 0777, true);
		$old = $nested.'/old.bin';
		file_put_contents($old, 'obsolete');
		$original = lstat($nested);
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'rename:*' => array('path' => $nested, 'action' => 'exit', 'at' => 'after'))));
		$this->assertEquals(0, $status, 'capture crash fixture must exit: '.$output);
		$roots = glob($base.'/.erasedata-rmdir-*');
		$this->assertTrue(!erasedataPathExists($nested) && count($roots) === 1
			&& is_file($tmp) && is_dir($roots[0].'/directory'),
			'crash retains the exact hidden directory and cleanup obligation');
		list($status, $output) = $this->runCollector(array(
			'fleetRows' => array($thirdHash, '', '', ''),
			'fleetSources' => array($thirdHash => array('hash' => $thirdHash,
				'info' => array('name' => basename($base), 'files' => array(
					array('path' => array('season', 'missing.bin'), 'length' => 1))))),
			'fleetReplies' => array($thirdHash => array('stored' => array(
				'ok' => true, 'fault' => false,
				'val' => array($base, 1, 'season/missing.bin')))),
		));
		$restored = @lstat($nested);
		$this->assertEquals(0, $status, 'captured parent retry must run: '.$output);
		$this->assertTrue(is_array($restored) && $restored['ino'] === $original['ino']
			&& !is_link($nested) && count(glob($base.'/.erasedata-rmdir-*')) === 0
			&& !is_file($tmp),
			'claim on retry restores original directory before any deletion');
	}

	public function testCleanupCrashAfterFileRestoreNeverReDeletesClaimedInode()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$thirdHash = $this->hash('C');
		$base = $this->dir.'/file-restore-crash';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'original bytes');
		$original = lstat($old);
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$third = array('hash' => $thirdHash,
			'info' => array('name' => 'old.bin', 'length' => 14));
		$reply = array('stored' => array('ok' => true, 'fault' => false,
			'val' => array($base, 0, 'old.bin')));
		list($status, $output) = $this->runCollector(array(
			'fleetReplies' => array($thirdHash => $reply),
			'filesystem' => array(
				'rename:1' => array('path' => $old, 'action' => 'fleet-change',
					'at' => 'after', 'rows' => array($thirdHash, '', '', ''),
					'sources' => array($thirdHash => $third)),
				'renameNoReplace:1' => array('path' => $old,
					'action' => 'exit', 'at' => 'after')),
		));
		$this->assertEquals(0, $status, 'restore crash fixture must exit: '.$output);
		$this->assertTrue(is_file($tmp) && is_file($old)
			&& count(glob($base.'/.erasedata-entry-*')) === 1,
			'crash after restore leaves the exact job and an empty private shell');
		list($status, $output) = $this->runCollector(array(
			'fleetRows' => array($thirdHash, '', '', ''),
			'fleetSources' => array($thirdHash => $third),
			'fleetReplies' => array($thirdHash => $reply),
		));
		$restored = @lstat($old);
		$this->assertEquals(0, $status, 'restored file retry must run: '.$output);
		$this->assertTrue(is_array($restored) && $restored['ino'] === $original['ino']
			&& file_get_contents($old) === 'original bytes'
			&& count(glob($base.'/.erasedata-entry-*')) === 0 && !is_file($tmp),
			'an empty shell cannot authorize deleting the restored claimed inode');
	}

	public function testCleanupCrashAfterParentRestoreNeverDeletesClaimedDirectory()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$thirdHash = $this->hash('C');
		$base = $this->dir.'/parent-restore-crash';
		$nested = $base.'/season';
		@mkdir($nested, 0777, true);
		$old = $nested.'/old.bin';
		file_put_contents($old, 'obsolete');
		$original = lstat($nested);
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$third = array('hash' => $thirdHash, 'info' => array(
			'name' => basename($base), 'files' => array(
				array('path' => array('season', 'missing.bin'), 'length' => 1))));
		$reply = array('stored' => array('ok' => true, 'fault' => false,
			'val' => array($base, 1, 'season/missing.bin')));
		list($status, $output) = $this->runCollector(array(
			'fleetReplies' => array($thirdHash => $reply),
			'filesystem' => array(
				'rename:*' => array('path' => $nested, 'action' => 'fleet-change',
					'at' => 'after', 'rows' => array($thirdHash, '', '', ''),
					'sources' => array($thirdHash => $third)),
				'renameNoReplace:1' => array('path' => $nested,
					'action' => 'exit', 'at' => 'after')),
		));
		$this->assertEquals(0, $status, 'parent restore crash fixture must exit: '.$output);
		$this->assertTrue(is_dir($nested) && is_file($tmp)
			&& count(glob($base.'/.erasedata-rmdir-*')) === 1,
			'crash leaves the restored directory and an empty private shell');
		list($status, $output) = $this->runCollector(array(
			'fleetRows' => array($thirdHash, '', '', ''),
			'fleetSources' => array($thirdHash => $third),
			'fleetReplies' => array($thirdHash => $reply),
			'captureLogs' => true,
		));
		$restored = @lstat($nested);
		$this->assertEquals(0, $status, 'restored parent retry must run: '.$output);
		$this->assertTrue(is_array($restored) && $restored['ino'] === $original['ino']
			&& !is_link($nested) && count(glob($base.'/.erasedata-rmdir-*')) === 1
			&& is_file($tmp),
			'crash after restore keeps the claimed directory, shell and cleanup obligation');
		$this->assertTrue(strpos($output, 'restore-uncertain') !== false
			&& strpos($output, 'parent-key=') !== false
			&& strpos($output, 'job='.basename($tmp)) !== false,
			'uncertain restored parent is visible with its parent key and exact job');
	}

	public function testCleanupRetryRetainsDirectOwnerOfPrivateCapture()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$thirdHash = $this->hash('C');
		$base = $this->dir.'/private-alias';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'obsolete');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'rename:*' => array('path' => $old, 'action' => 'exit', 'at' => 'after'))));
		$roots = glob($base.'/.erasedata-entry-*');
		$this->assertEquals(0, $status, 'file capture crash fixture must exit: '.$output);
		$this->assertTrue(count($roots) === 1 && !erasedataPathExists($old)
			&& is_file($roots[0].'/entry'),
			'crash leaves the old file under its private capture name');
		$private = $roots[0].'/entry';
		list($status, $output) = $this->runCollector(array(
			'fleetRows' => array($thirdHash, '', '', ''),
			'fleetSources' => array($thirdHash => array('hash' => $thirdHash,
				'info' => array('name' => 'entry', 'length' => 8))),
			'fleetReplies' => array($thirdHash => array('stored' => array(
				'ok' => true, 'fault' => false,
				'val' => array(dirname($private), 0, 'entry')))),
			'captureLogs' => true));
		$this->assertEquals(0, $status, 'private alias retry must run: '.$output);
		$this->assertTrue(is_file($private) && file_get_contents($private) === 'obsolete'
			&& !erasedataPathExists($old) && is_file($tmp),
			'a direct third-owner binding to the private entry prevents restoration and retirement');
		$this->assertTrue(strpos($output, 'capture-aliased') !== false,
			'the retained job explains the direct private alias');
	}

	public function testCleanupRetryAfterParentRestoreWithoutClaimRetainsForDiagnosis()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$thirdHash = $this->hash('C');
		$base = $this->dir.'/parent-restore-no-claim';
		$nested = $base.'/season';
		@mkdir($nested, 0777, true);
		$old = $nested.'/old.bin';
		file_put_contents($old, 'obsolete');
		$original = lstat($nested);
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$third = array('hash' => $thirdHash, 'info' => array(
			'name' => basename($base), 'files' => array(
				array('path' => array('season', 'missing.bin'), 'length' => 1))));
		$reply = array('stored' => array('ok' => true, 'fault' => false,
			'val' => array($base, 1, 'season/missing.bin')));
		list($status, $output) = $this->runCollector(array(
			'fleetReplies' => array($thirdHash => $reply),
			'filesystem' => array(
				'rename:*' => array('path' => $nested, 'action' => 'fleet-change',
					'at' => 'after', 'rows' => array($thirdHash, '', '', ''),
					'sources' => array($thirdHash => $third)),
				'renameNoReplace:1' => array('path' => $nested,
					'action' => 'exit', 'at' => 'after')),
		));
		$this->assertEquals(0, $status, 'parent restore crash fixture must exit: '.$output);
		$restored = @lstat($nested);
		$this->assertTrue(is_array($restored) && $restored['ino'] === $original['ino']
			&& is_file($tmp) && count(glob($base.'/.erasedata-rmdir-*')) === 1,
			'crash leaves exact original parent and an empty reservation shell');
		list($status, $output) = $this->runCollector(array('captureLogs' => true));
		$this->assertEquals(0, $status, 'retry without claim must run: '.$output);
		$visible = @lstat($nested);
		$this->assertTrue(is_array($visible) && $visible['ino'] === $original['ino']
			&& is_file($tmp) && count(glob($base.'/.erasedata-rmdir-*')) === 1,
			'the restored original parent and obligation remain for manual diagnosis');
		$this->assertTrue(strpos($output, 'restore-uncertain') !== false,
			'the crash-after-restore window fails closed and explains its uncertainty');
	}

	public function testCleanupCrashAfterPrivateFileUnlinkCompletesWithoutPublicDelete()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/file-unlink-crash';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'obsolete');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'unlink:*' => array('basename' => 'entry',
				'contains' => '/.erasedata-entry-', 'action' => 'exit', 'at' => 'after'))));
		$this->assertEquals(0, $status, 'private unlink crash fixture must exit: '.$output);
		$this->assertTrue(!erasedataPathExists($old) && is_file($tmp)
			&& count(glob($base.'/.erasedata-entry-*')) === 1,
			'crash after unlink retains only the private protocol shell');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'private unlink retry must run: '.$output);
		$this->assertTrue(!erasedataPathExists($old) && !is_file($tmp)
			&& count(glob($base.'/.erasedata-entry-*')) === 0,
			'empty shell retry completes without touching a public payload');
	}

	public function testCleanupCrashAfterPrivateParentRmdirCompletesWithoutPublicDelete()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/parent-rmdir-crash';
		$nested = $base.'/season';
		@mkdir($nested, 0777, true);
		$old = $nested.'/old.bin';
		file_put_contents($old, 'obsolete');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'removeDirectory:*' => array('basename' => 'directory',
				'action' => 'exit', 'at' => 'after'))));
		$this->assertEquals(0, $status, 'private parent rmdir crash fixture must exit: '.$output);
		$this->assertTrue(!erasedataPathExists($nested) && is_file($tmp)
			&& count(glob($base.'/.erasedata-rmdir-*')) === 1,
			'crash after rmdir retains only the private reservation shell');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'private parent rmdir retry must run: '.$output);
		$this->assertTrue(!erasedataPathExists($nested) && !is_file($tmp)
			&& count(glob($base.'/.erasedata-rmdir-*')) === 0,
			'empty reservation retry completes without touching a public directory');
	}

	public function testCleanupRetryPreservesNewParentEvenIfInodeIsReused()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/parent-reused-inode';
		$nested = $base.'/season';
		@mkdir($nested, 0777, true);
		$old = $nested.'/old.bin';
		file_put_contents($old, 'obsolete');
		$original = lstat($nested);
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'removeDirectory:*' => array('basename' => 'directory',
				'action' => 'exit', 'at' => 'after'))));
		$this->assertEquals(0, $status, 'private rmdir crash fixture must exit: '.$output);
		$roots = glob($base.'/.erasedata-rmdir-*');
		$this->assertTrue(!erasedataPathExists($nested) && is_file($tmp)
			&& count($roots) === 1,
			'the old directory is gone but its reservation shell remains');
		$this->assertTrue(is_file($roots[0].'/.cleanup-delete-intent'),
			'the recorded delete intent distinguishes a later occupant even on filesystems without reuse');
		$replacement = false;
		for($attempt = 0; $attempt < 128; $attempt++)
		{
			$this->assertTrue(mkdir($nested), 'a new empty directory occupies the public name');
			$replacement = lstat($nested);
			if($replacement['dev'] === $original['dev']
				&& $replacement['ino'] === $original['ino'])
				break;
			$this->assertTrue(rmdir($nested), 'the allocator can retry for the recycled inode');
		}
		// The last unsuccessful attempt was removed too; keep a real new
		// occupant for the retry on filesystems that never reuse this inode.
		clearstatcache(true, $nested);
		if(!is_dir($nested))
		{
			$this->assertTrue(mkdir($nested), 'a final new directory occupies the public name');
			$replacement = lstat($nested);
		}
		$reused = is_array($replacement)
			&& $replacement['dev'] === $original['dev']
			&& $replacement['ino'] === $original['ino'];
		// Allocator reuse is observed locally but cannot be required of every FS.
		list($status, $output) = $this->runCollector(array());
		$visible = @lstat($nested);
		$this->assertEquals(0, $status, 'reused-inode retry must run: '.$output);
		$this->assertTrue(is_array($visible) && $visible['ino'] === $replacement['ino']
			&& is_dir($nested) && !is_file($tmp)
			&& count(glob($base.'/.erasedata-rmdir-*')) === 0,
			$reused ? 'the new directory survives despite a matching dev:ino'
				: 'the new directory survives on a filesystem that did not recycle the inode');
	}

	public function testCleanupRetryRetainsShellWhenPhaseWasLostBeforeRemoval()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/parent-phase-lost';
		$nested = $base.'/season';
		@mkdir($nested, 0777, true);
		$old = $nested.'/old.bin';
		file_put_contents($old, 'obsolete');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'removeDirectory:*' => array('basename' => 'directory',
				'action' => 'exit', 'at' => 'after'))));
		$roots = glob($base.'/.erasedata-rmdir-*');
		$this->assertEquals(0, $status, 'private rmdir crash fixture must exit: '.$output);
		$this->assertTrue(count($roots) === 1
			&& is_file($roots[0].'/.cleanup-delete-intent'),
			'the reservation has a recorded delete phase before simulated marker loss');
		$this->assertTrue(unlink($roots[0].'/.cleanup-delete-intent'),
			'the fixture models a crash during phase cleanup before shell rmdir');
		$this->assertTrue(mkdir($nested), 'a new empty parent occupies the old name');
		$replacement = lstat($nested);
		list($status, $output) = $this->runCollector(array('captureLogs' => true));
		$visible = @lstat($nested);
		$this->assertEquals(0, $status, 'ambiguous phase retry must run: '.$output);
		$this->assertTrue(is_array($visible) && $visible['ino'] === $replacement['ino']
			&& is_file($tmp) && is_dir($roots[0]),
			'the unknown phase preserves the public directory, shell and exact job');
		$this->assertTrue(strpos($output, 'phase-unknown') !== false
			&& strpos($output, 'parent-key=') !== false
			&& strpos($output, 'job='.basename($tmp)) !== false,
			'the retained unknown phase names the job and parent key for diagnosis');
	}

	public function testCleanupRetryAfterPrivateParentRmdirPreservesNewOccupant()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/parent-new-occupant';
		$nested = $base.'/season';
		$replacement = $base.'/ready';
		@mkdir($nested, 0777, true);
		@mkdir($replacement, 0777, true);
		$old = $nested.'/old.bin';
		file_put_contents($old, 'obsolete');
		file_put_contents($replacement.'/active.bin', 'active bytes');
		$original = lstat($nested);
		$newcomer = lstat($replacement);
		$this->assertTrue($original['ino'] !== $newcomer['ino'],
			'the future occupant is allocated while the original inode is alive');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'removeDirectory:*' => array('basename' => 'directory',
				'action' => 'exit', 'at' => 'after'))));
		$this->assertEquals(0, $status, 'private parent rmdir crash fixture must exit: '.$output);
		$this->assertTrue(!erasedataPathExists($nested) && is_file($tmp)
			&& count(glob($base.'/.erasedata-rmdir-*')) === 1,
			'crash leaves an empty reservation shell after deleting the old parent');
		$this->assertTrue(rename($replacement, $nested),
			'a separately allocated directory occupies the public name');
		list($status, $output) = $this->runCollector(array());
		$visible = @lstat($nested);
		$this->assertEquals(0, $status, 'new occupant retry must run: '.$output);
		$this->assertTrue(is_array($visible) && $visible['ino'] === $newcomer['ino']
			&& file_get_contents($nested.'/active.bin') === 'active bytes'
			&& !is_file($tmp) && count(glob($base.'/.erasedata-rmdir-*')) === 0,
			'the new directory is preserved while the old parent obligation retires');
	}

	public function testCleanupUnclaimedRelativeSymlinkLeavesItsTarget()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/unclaimed-relative-link';
		@mkdir($base, 0777, true);
		$target = $base.'/shared.bin';
		$old = $base.'/old-link';
		file_put_contents($target, 'shared bytes');
		$this->assertTrue(symlink('shared.bin', $old), 'fixture creates a relative link');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$bridges = $this->dir.'/relative-link-bridge-calls';
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'makeSymlink:*' => array('path' => $old, 'result' => false,
				'count_file' => $bridges))));
		$this->assertEquals(0, $status, 'unclaimed symlink cleanup must run: '.$output);
		$this->assertTrue(!erasedataPathExists($old) && !is_file($tmp)
			&& file_get_contents($target) === 'shared bytes'
			&& !is_file($bridges) && count(glob($base.'/.erasedata-entry-*')) === 0,
			'only the obsolete link is removed and its target remains intact');
	}

	public function testCleanupSiblingOwnerDoesNotProtectEmptyParent()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$thirdHash = $this->hash('C');
		$base = $this->dir.'/sibling-parent';
		$nested = $base.'/season';
		@mkdir($nested, 0777, true);
		$old = $nested.'/old.bin';
		file_put_contents($old, 'obsolete');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array(
			'fleetRows' => array($thirdHash, '', '', ''),
			'fleetSources' => array($thirdHash => array('hash' => $thirdHash,
				'info' => array('name' => basename($base), 'files' => array(
					array('path' => array('season2', 'other.bin'), 'length' => 1))))),
			'fleetReplies' => array($thirdHash => array('stored' => array(
				'ok' => true, 'fault' => false,
				'val' => array($base, 1, 'season2/other.bin')))),
		));
		$this->assertEquals(0, $status, 'sibling owner cleanup must run: '.$output);
		$this->assertTrue(!erasedataPathExists($old) && !erasedataPathExists($nested)
			&& is_dir($base) && !is_file($tmp),
			'a sibling component does not claim the empty obsolete parent');
	}

	public function testCleanupUnknownParentAfterCaptureRestoresAndRetains()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/unknown-parent';
		$nested = $base.'/season';
		@mkdir($nested, 0777, true);
		$old = $nested.'/old.bin';
		file_put_contents($old, 'obsolete');
		$original = lstat($nested);
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array(
			'filesystem' => array('rename:*' => array('path' => $nested,
				'action' => 'fleet-change', 'at' => 'after', 'fault' => true)),
			'captureLogs' => true,
		));
		$restored = @lstat($nested);
		$this->assertEquals(0, $status, 'unknown parent scan must not crash: '.$output);
		$this->assertTrue(is_array($restored) && $restored['ino'] === $original['ino']
			&& !is_link($nested) && is_file($tmp)
			&& count(glob($base.'/.erasedata-rmdir-*')) === 0
			&& strpos($output, 'rpc-unknown') !== false,
			'unknown ownership restores the exact parent and retains the job visibly');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'readable parent retry must run: '.$output);
		$this->assertTrue(!erasedataPathExists($nested) && !is_file($tmp),
			'a later unclaimed retry completes the retained parent obligation');
	}

	public function testCleanupLegacyCapturedFileBridgeRestoresWithoutNewBridge()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$thirdHash = $this->hash('C');
		$base = $this->dir.'/legacy-file-bridge';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'obsolete bytes');
		$original = lstat($old);
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'rename:1' => array('path' => $old, 'action' => 'exit', 'at' => 'after'))));
		$roots = glob($base.'/.erasedata-entry-*');
		$this->assertTrue($status === 0 && count($roots) === 1,
			'fixture leaves one authentic captured obsolete inode');
		$this->assertTrue(symlink(basename($roots[0]).'/entry', $old),
			'fixture publishes the historical relative public bridge');
		$bridges = $this->dir.'/legacy-file-new-bridges';
		list($status, $output) = $this->runCollector(array(
			'fleetRows' => array($thirdHash, '', '', ''),
			'fleetSources' => array($thirdHash => array('hash' => $thirdHash,
				'info' => array('name' => 'old.bin', 'length' => 14))),
			'fleetReplies' => array($thirdHash => array('stored' => array(
				'ok' => true, 'fault' => false, 'val' => array($base, 0, 'old.bin')))),
			'filesystem' => array('makeSymlink:*' => array('path' => $old,
				'result' => false, 'count_file' => $bridges)),
	));
		$restored = @lstat($old);
		$this->assertEquals(0, $status, 'legacy file bridge retry must run: '.$output);
		$this->assertTrue(is_array($restored) && $restored['ino'] === $original['ino']
			&& !is_link($old) && file_get_contents($old) === 'obsolete bytes'
			&& !is_file($tmp) && !is_file($bridges)
			&& count(glob($base.'/.erasedata-entry-*')) === 0,
			'legacy bridge disappears and the exact claimed inode is restored');
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

	public function testCleanupDeletesEmptyParentWithoutPublishingBridge()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/safe-parent';
		$nested = $base.'/season';
		@mkdir($nested, 0777, true);
		$old = $nested.'/old.bin';
		file_put_contents($old, 'obsolete');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$token = substr($tmp, 0, -4).'.list';
		$captures = $this->dir.'/parent-capture-calls';
		$bridges = $this->dir.'/parent-bridge-calls';
		list($status, $output) = $this->runCollector(array(
			'filesystem' => array(
				'rename:*' => array('path' => $nested, 'count_file' => $captures),
				'makeSymlink:*' => array('path' => $nested, 'result' => false,
					'count_file' => $bridges)),
			'captureLogs' => true,
		));
		$this->assertEquals(0, $status, 'empty parent cleanup must exit normally: '.$output);
		$this->assertTrue(!erasedataPathExists($old) && !erasedataPathExists($nested)
			&& is_dir($base) && is_file($captures) && !is_file($bridges),
			'an unclaimed empty parent is captured and removed without a public bridge');
		$this->assertTrue(!is_file($tmp) && !is_file($token)
			&& count(glob($base.'/.erasedata-rmdir-*')) === 0,
			'the parent reservation and exact job retire together');
	}

	public function testCleanupRestoresRacingParentReplacementWithoutDeletingIt()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/parent-race';
		$nested = $base.'/season';
		@mkdir($nested, 0777, true);
		$old = $nested.'/old.bin';
		file_put_contents($old, 'obsolete');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$token = substr($tmp, 0, -4).'.list';
		$replacement = $base.'/active-replacement';
		@mkdir($replacement, 0777, true);
		file_put_contents($replacement.'/active.bin', 'active bytes');
		$captures = $this->dir.'/racing-parent-captures';
		list($status, $output) = $this->runCollector(array(
			'filesystem' => array('rename:*' => array('path' => $nested,
				'action' => 'replace-entry', 'backup' => $base.'/old-parent',
				'replacement' => $replacement, 'count_file' => $captures)),
			'captureLogs' => true,
		));
		$this->assertEquals(0, $status, 'racing parent fixture must run: '.$output);
		$this->assertTrue(is_file($captures) && is_dir($nested)
			&& !file_exists($old) && is_dir($base.'/old-parent')
			&& is_file($nested.'/active.bin')
			&& file_get_contents($nested.'/active.bin') === 'active bytes'
			&& count(glob($base.'/.erasedata-rmdir-*')) === 0,
			'a racing replacement is restored intact and never reaches rmdir');
		$this->assertTrue(!is_file($tmp) && !is_file($token),
			'a restored foreign parent completes this old cleanup obligation');
	}

	public function testCleanupRestoresParentWhenChildAppearsBeforePrivateRmdir()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/parent-child-race';
		$nested = $base.'/season';
		@mkdir($nested, 0777, true);
		$old = $nested.'/old.bin';
		file_put_contents($old, 'obsolete');
		$original = lstat($nested);
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'removeDirectory:*' => array('basename' => 'directory',
				'action' => 'recreate', 'content' => array(
					'name' => 'active.bin', 'bytes' => 'active bytes'))),
			'captureLogs' => true));
		$restored = @lstat($nested);
		$this->assertEquals(0, $status, 'late child fixture must run: '.$output);
		$this->assertTrue(is_array($restored) && $restored['ino'] === $original['ino']
			&& file_get_contents($nested.'/active.bin') === 'active bytes'
			&& is_file($tmp) && count(glob($base.'/.erasedata-rmdir-*')) === 0,
			'a child inserted before private rmdir restores the exact directory and retains the job');
		$this->assertTrue(strpos($output, 'rmdir-failure') !== false,
			'the retained cleanup names the failed private rmdir');
	}

	public function testCleanupRestoreUnverifiedNamesTheRetainedJob()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/parent-restore-shell-refusal';
		$nested = $base.'/season';
		@mkdir($nested, 0777, true);
		$old = $nested.'/old.bin';
		file_put_contents($old, 'obsolete');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'removeDirectory:*' => array('basename' => 'directory',
				'action' => 'recreate', 'content' => array(
					'name' => 'active.bin', 'bytes' => 'active bytes')),
			'removeDirectory:1' => array('basename_prefix' => '.erasedata-rmdir-',
				'result' => false)),
			'captureLogs' => true));
		$this->assertEquals(0, $status, 'collector child must finish: '.$output);
		$this->assertTrue(is_file($tmp) && is_file($nested.'/active.bin')
			&& file_get_contents($nested.'/active.bin') === 'active bytes',
			'failed shell cleanup must retain the job and restore the exact parent data');
		$this->assertTrue(strpos($output, 'restore-unverified') !== false,
			'the log must classify a restored parent with a retained private shell');
		$this->assertTrue(strpos($output, 'job='.basename($tmp)) !== false,
			'the permanent restore refusal must name its exact cleanup job');
	}

	public function testCleanupReportsRestoreFailureWhenCapturedFileUnlinkAlsoFails()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/file-restore-refusal';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'obsolete');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'unlink:*' => array('basename' => 'entry', 'result' => false),
			'renameNoReplace:*' => array('result' => false)),
			'captureLogs' => true));
		$this->assertEquals(0, $status, 'collector child must finish: '.$output);
		$this->assertTrue(is_file($tmp), 'failed restore must retain the exact cleanup manifest');
		$this->assertTrue(strpos($output,
			'erasedata: cleanup retained '.$oldHash.' restore-failed') !== false,
			'the refusal must name the failed file restore, not only the preceding unlink failure');
	}

	public function testCleanupReportsRestoreFailureWhenPrivateRmdirAlsoFails()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/parent-restore-refusal';
		$nested = $base.'/season';
		@mkdir($nested, 0777, true);
		$old = $nested.'/old.bin';
		file_put_contents($old, 'obsolete');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'removeDirectory:*' => array('basename' => 'directory',
				'action' => 'recreate', 'content' => array(
					'name' => 'active.bin', 'bytes' => 'active bytes')),
			'renameNoReplace:*' => array('result' => false)),
			'captureLogs' => true));
		$this->assertEquals(0, $status, 'collector child must finish: '.$output);
		$this->assertTrue(is_file($tmp), 'failed restore must retain the exact cleanup manifest');
		$this->assertTrue(strpos($output,
			'erasedata: cleanup retained '.$oldHash.' restore-failed') !== false,
			'the refusal must name the failed restore, not only the preceding rmdir failure');
	}

	public function testCleanupRestoresLegacyCapturedParentWithoutBridge()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/legacy-parent-capture';
		$nested = $base.'/season';
		@mkdir($nested, 0777, true);
		$old = $nested.'/old.bin';
		file_put_contents($old, 'obsolete');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$token = substr($tmp, 0, -4).'.list';
		$this->assertTrue(unlink($old), 'the old file was already cleaned before the parent capture');
		$filesystem = new ErasedataCollectorFixture(array(
			'removeDirectory:*' => array('basename' => 'directory', 'result' => false)));
		$this->assertTrue(!erasedataCompleteNonForceDirectory($nested, $tmp, $filesystem),
			'the previous implementation must leave a real failed directory reservation');
		$roots = glob($base.'/.erasedata-rmdir-*');
		$this->assertTrue(count($roots) === 1 && is_link($nested)
			&& is_dir($roots[0].'/directory'),
			'the upgrade fixture must contain an authentic reservation and public bridge');
		file_put_contents($nested.'/active.bin', 'active bytes');
		$reservedIdentity = lstat($roots[0].'/directory');
		list($status, $output) = $this->runCollector(array('captureLogs' => true));
		$this->assertEquals(0, $status, 'legacy captured-parent retry must not crash: '.$output);
		$restored = @lstat($nested);
		$this->assertTrue(is_array($restored) && $restored['dev'] === $reservedIdentity['dev']
			&& $restored['ino'] === $reservedIdentity['ino'] && !is_link($nested)
			&& count(glob($base.'/.erasedata-rmdir-*')) === 0
			&& file_get_contents($nested.'/active.bin') === 'active bytes',
			'the legacy captured parent returns with its exact inode and active child');
		$this->assertTrue(!is_file($tmp) && !is_file($token),
			'a restored nonempty parent completes the old cleanup obligation');
		list($status, $output) = $this->runCollector(array('captureLogs' => true));
		$this->assertEquals(0, $status, 'later collector pass must run: '.$output);
		$this->assertTrue(is_dir($nested) && !is_link($nested)
			&& file_get_contents($nested.'/active.bin') === 'active bytes',
			'a later pass leaves the recovered active directory intact');
	}

	public function testCleanupKeepsFileClaimedByThirdTorrent()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$thirdHash = $this->hash('C');
		$base = $this->dir.'/cross-seed';
		@mkdir($base, 0777, true);
		$shared = $base.'/shared.bin';
		file_put_contents($shared, 'third torrent still owns these bytes');
		$this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($shared));
		list($status, $output) = $this->runCollector(array(
			'fleetRows' => array($thirdHash, '', '', ''),
			'fleetSources' => array($thirdHash => array(
				'hash' => $thirdHash,
				'info' => array('name' => 'shared.bin', 'length' => 36))),
			'fleetReplies' => array($thirdHash => array('stored' => array(
				'ok' => true, 'fault' => false,
				'val' => array($base, 0, 'shared.bin')))),
		));
		$this->assertEquals(0, $status, 'a third-torrent claim must not crash cleanup');
		$this->assertEquals('third torrent still owns these bytes', @file_get_contents($shared),
			'a file claimed by a third torrent must survive cleanup');
	}

	public function testCleanupKeepsHardlinkedThirdTorrentObject()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$thirdHash = $this->hash('C');
		$base = $this->dir.'/cross-hardlink';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		$alias = $base.'/third.bin';
		file_put_contents($old, 'shared inode');
		$this->assertTrue(link($old, $alias), 'the fixture must create a real hardlink');
		$this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array(
			'fleetRows' => array($thirdHash, '', '', ''),
			'fleetSources' => array($thirdHash => array(
				'hash' => $thirdHash,
				'info' => array('name' => 'third.bin', 'length' => 12))),
			'fleetReplies' => array($thirdHash => array('stored' => array(
				'ok' => true, 'fault' => false,
				'val' => array($base, 0, 'third.bin')))),
		));
		$this->assertEquals(0, $status, 'a third-torrent hardlink claim must not crash cleanup');
		$this->assertEquals('shared inode', @file_get_contents($old),
			'an old path hardlinked to a third torrent must survive');
	}

	public function testCleanupUnknownFleetRetainsObligationAndBytes()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/cross-unknown';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'retain');
		$this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array(
			'fleetRows' => array($this->hash('C')),
			'captureLogs' => true,
		));
		$this->assertEquals(0, $status, 'an incomplete fleet scan must not crash cleanup');
		$this->assertEquals('retain', @file_get_contents($old),
			'unknown ownership must not authorize deletion');
		$this->assertTrue(is_string($this->onlyManifest($oldHash)),
			'unknown ownership must retain the exact cleanup obligation');
		$this->assertTrue(strpos($output, 'cleanup retained') !== false
			&& strpos($output, 'rpc-unknown') !== false,
			'the refusal must be visible as a classified cleanup retention');

		$this->reset();
		@mkdir($base, 0777, true);
		file_put_contents($old, 'fault retain');
		$this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array('fleetFault' => true));
		$this->assertEquals(0, $status, 'a fleet RPC fault must not crash cleanup');
		$this->assertEquals('fault retain', @file_get_contents($old),
			'a fleet RPC fault must not be treated as an empty owner set');
		$this->assertTrue(is_string($this->onlyManifest($oldHash)),
			'a fleet RPC fault retains the exact obligation');
	}

	public function testCleanupExcludesOnlyExactTransactionRowsFromOtherOwners()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/cross-generation';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'orphan');
		$this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array('fleetRows' => array(
			$oldHash, '', '', $newHash.'-started-1787587200',
			$newHash, '0123456789abcdef0123456789abcdef',
				$oldHash.'-started-1787587200', '',
		)));
		$this->assertEquals(0, $status, 'the matching transaction fleet rows must not crash cleanup');
		$this->assertTrue(!file_exists($old),
			'the exact predecessor and successor rows must not disguise an orphan as shared');
		$this->assertEquals(false, $this->onlyManifest($oldHash),
			'the exact transaction orphan obligation must complete');

		$this->reset();
		@mkdir($base, 0777, true);
		file_put_contents($old, 'foreign takeover');
		$this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		list($status, $output) = $this->runCollector(array(
			'fleetRows' => array($oldHash, '', '', 'foreign-generation'),
			'fleetSources' => array($oldHash => array(
				'hash' => $oldHash,
				'info' => array('name' => 'old.bin', 'length' => 16))),
			'fleetReplies' => array($oldHash => array('stored' => array(
				'ok' => true, 'fault' => false,
				'val' => array($base, 0, 'old.bin')))),
		));
		$this->assertEquals(0, $status, 'a foreign same-hash occupant must not crash cleanup');
		$this->assertEquals('foreign takeover', @file_get_contents($old),
			'a foreign occupant on the old hash is still another owner');
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

	public function testCleanupRetryRestoresCaptureClaimedByThirdTorrent()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$thirdHash = $this->hash('C');
		$base = $this->dir.'/captured-third-owner';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		$alias = $base.'/third.bin';
		file_put_contents($old, 'captured bytes');
		$original = lstat($old);
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old));
		$token = substr($tmp, 0, -4).'.list';
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'rename:1' => array('path' => $old, 'action' => 'exit', 'at' => 'after'),
		)));
		$roots = glob($base.'/.erasedata-entry-*');
		$this->assertEquals(1, count($roots), 'the crash fixture must capture exactly one obsolete entry');
		$entry = count($roots) === 1 ? $roots[0].'/entry' : '';
		$this->assertTrue($entry !== '' && link($entry, $alias),
			'the third torrent must acquire the captured inode through a hardlink');
		list($status, $output) = $this->runCollector(array(
			'fleetRows' => array($thirdHash, '', '', ''),
			'fleetSources' => array($thirdHash => array(
				'hash' => $thirdHash,
				'info' => array('name' => 'third.bin', 'length' => 14))),
			'fleetReplies' => array($thirdHash => array('stored' => array(
				'ok' => true, 'fault' => false,
				'val' => array($base, 0, 'third.bin')))),
		));
		$this->assertEquals(0, $status, 'claimed captured-entry retry must not crash');
		$restored = @lstat($old);
		$this->assertTrue(is_array($restored) && $restored['dev'] === $original['dev']
			&& $restored['ino'] === $original['ino'] && !is_link($old)
			&& file_get_contents($old) === 'captured bytes'
			&& file_get_contents($alias) === 'captured bytes',
			'a hardlink claim restores the exact public inode and preserves the alias');
		$this->assertTrue(!is_file($tmp) && !is_file($token)
			&& count(glob($base.'/.erasedata-entry-*')) === 0,
			'a proved claim completes without keeping an unnecessary private link');
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

	public function testCleanupCollectorRecoveryUsesItsFilesystemForTheSelectedGeneration()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$base = $this->dir.'/collector-recovery-seam';
		@mkdir($base, 0777, true);
		$old = $base.'/old.bin';
		file_put_contents($old, 'old');
		$tmp = $this->writeCleanupCollectorManifest($oldHash, $newHash, $base, array($old), 'tmp');
		$list = substr($tmp, 0, -4).'.list';
		$generation = $this->cleanupGenerationResponses($oldHash, $newHash,
			$this->collectorResponse(true, false, array('')),
			$this->collectorResponse(true, false, array($newHash,
				'0123456789abcdef0123456789abcdef', $oldHash.'-started-1787587200')));
		list($status, $output) = $this->runCollector(array(
			'generation' => $generation,
			'filesystem' => array('entryIdentity:5' => array('path' => $tmp, 'result' => false)),
			'debug' => true,
		));
		$this->assertEquals(0, $status, 'a selected generation identity race does not crash the collector: '.$output);
		$this->assertTrue(is_file($tmp) && is_file($old) && !file_exists($list),
			'collector recovery must not publish a token after its fifth identity read is refused');
		$this->assertTrue(in_array('erasedata: cleanup retained '.$oldHash.' unreadable-manifest',
			$this->collectorLogs($output), true),
			'the collector leaves a classified retry reason for the identity race');
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

	public function testCleanupCollectorRevalidatesSuccessorSnapshotForEachCapture()
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
		$expectedReads = count($newFiles) * (2 + 2 * count($oldFiles));
		$this->assertEquals($expectedReads, is_file($countFile) ? count(file($countFile)) : 0,
			'each captured file gets one fresh successor observation and one final revalidation');
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

	public function testCleanupCollectorRetainsUnresolvedIdentityAndRemovesNestedEmptyParents()
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

		list($status, $output) = $this->runCollector(array('captureLogs' => true));
		$this->assertEquals(0, $status, 'nested parent cleanup must not crash the collector: '.$output);
		$token = substr($list, 0, -4).'.list';
		$this->assertTrue(!erasedataPathExists($old) && !is_file($list) && !is_file($token)
			&& !erasedataPathExists($nested) && !erasedataPathExists($base.'/one')
			&& is_dir($base),
			'cleanup removes both empty nested parents and retires their exact job');
		$this->assertTrue(count(glob($base.'/.erasedata-rmdir-*')) === 0,
			'no private parent reservation remains after completion');
		list($status, $output) = $this->runCollector(array('captureLogs' => true));
		$this->assertEquals(0, $status, 'completed nested parent retry must exit normally: '.$output);
		$this->assertTrue(!is_file($list) && !is_file($token) && is_dir($base),
			'a later pass preserves the shared base and completed job state');
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
		// The index, collector and recovery each re-read the selected artifact through
		// the same filesystem seam; each read checks identity before and after.
		$this->assertTrue($reads >= $count && $reads <= $count * 6,
			'exact artifact reads and decodes for prepared same-hash generations must remain linearly bounded (observed '.$reads.' for '.$count.' jobs)');
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

	public function testCleanupRecoveryUsesFilesystemSeamForQueueAndSelectedArtifact()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$input = $this->cleanupJobInput($oldHash);
		$job = erasedataPrepareObsoleteCleanup($oldHash, $input['new_hash'], $input['marker'],
			$input['replacement_record'], $input['base'], $input['entries']);
		$this->assertTrue(is_array($job), 'the selected cleanup generation is staged');
		$tmp = $job['tmp_path'];
		$list = $job['list_path'];
		erasedataReleaseObsoleteCleanupJob($job);
		$this->configureMatchingCleanupRecovery($oldHash, $input);
		$queue = $this->queuePath();
		$index = erasedataBuildCollectorIndex($queue, $oldHash);
		$filesystem = new ErasedataCollectorFixture(array(
			'entryIdentity:*' => array('path' => $tmp, 'result' => false),
		));
		$reason = null;
		$this->assertEquals(ERASEDATA_CLEANUP_RETRY,
			erasedataRecoverObsoleteCleanupLocked($queue, $oldHash, $input['new_hash'],
				$input['marker'], $input['replacement_record'], $reason, $index, $filesystem),
			'a selected artifact that the supplied filesystem cannot identify refuses recovery');
		$this->assertEquals('unreadable-manifest', $reason,
			'the refusal reports the selected artifact rather than pretending it is absent');
		$this->assertTrue(is_file($tmp) && !file_exists($list),
			'the refused recovery keeps the staged artifact and does not publish a token');
	}

	public function testCleanupRecoveryUsesFilesystemSeamForQueueEnumeration()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$input = $this->cleanupJobInput($oldHash);
		$filesystem = new ErasedataCollectorFixture(array(
			'scanDirectory:*' => array('result' => false),
		));
		$reason = null;
		$this->assertEquals(ERASEDATA_CLEANUP_RETRY,
			erasedataRecoverObsoleteCleanup($oldHash, $input['new_hash'],
				$input['marker'], $input['replacement_record'], $reason, $filesystem),
			'an unreadable queue from the supplied filesystem must not look empty');
		$this->assertEquals('unreadable-manifest', $reason,
			'the queue enumeration failure remains classified');
	}

	public function testCleanupPublicationRevalidatesThroughTheSuppliedFilesystem()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$job = $this->prepareCleanupJob($oldHash);
		$tmp = $job['tmp_path'];
		$list = $job['list_path'];
		$filesystem = new class extends ErasedataFilesystemOps {
			public $deniedSelectedRead = false;
			public function entryIdentity($path)
			{
				if(substr($path, -4) === '.tmp')
					foreach(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame)
						if($frame['function'] === 'erasedataCleanupGenerationArtifacts')
						{
							$this->deniedSelectedRead = true;
							return(false);
						}
				return(parent::entryIdentity($path));
			}
		};
		$this->assertEquals(false, erasedataPublishObsoleteCleanup($job, $filesystem),
			'publication refuses when the supplied filesystem loses the selected generation after indexing');
		$this->assertTrue($filesystem->deniedSelectedRead,
			'the selected generation is re-read through the caller filesystem');
		$this->assertTrue(is_file($tmp) && !file_exists($list),
			'failed revalidation leaves only the staged manifest');
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

	public function testCleanupTokenPublicationRechecksPairAfterLastTokenRead()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$job = $this->prepareCleanupJob($oldHash);
		$tmp = $job['tmp_path'];
		$list = $job['list_path'];
		$foreignToken = $this->dir.'/late-foreign-token';
		$backup = $list.'.owned';
		$marker = $this->dir.'/late-token-swap.triggered';
		file_put_contents($foreignToken, '');
		$foreign = lstat($foreignToken);
		// The sixth token identity read is the last read of the first
		// committed-pair check. Swap only after it has returned the old inode.
		$fixture = new ErasedataCollectorFixture(array(
			'entryIdentity:6' => array('path' => $list,
				'action' => 'replace-entry', 'at' => 'after',
				'backup' => $backup, 'replacement' => $foreignToken,
				'marker' => $marker),
		));
		$this->assertEquals(false, erasedataPublishObsoleteCleanup($job, $fixture),
			'a token changed after the first committed-pair read must not be reported as published');
		$this->assertTrue(is_file($marker) && is_file($backup)
			&& is_file($list) && lstat($list)['ino'] === $foreign['ino']
			&& is_file($tmp),
			'the scripted swap reached the exact gap between the two pair checks');
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

	public function testPreparedCleanupCannotArmWithoutGuardedChildAcknowledgement()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$job = $this->prepareCleanupJob($oldHash);
		$this->assertTrue(is_array($job), 'the cleanup obligation is staged before arm');
		if(!is_array($job))
			return;
		$queue = $this->queuePath();
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0));
		$this->assertTrue(!erasedataArmObsoleteCleanupRun($this->dependencies(array(
			'ackTimeout' => 0.02, 'ackPoll' => 0.005)), $job),
			'a successful schedule RPC without a real child acknowledgement refuses the commit');
		$state = erasedataReadDrainState($queue);
		$this->assertEquals('0000000000000001', $state['generation'],
			'the wake generation is durable before the wait');
		$this->assertEquals('0000000000000000', $state['acknowledged'],
			'registration cannot forge a child acknowledgement');
		$this->assertEquals(1, count($this->scheduleRecords('schedule')),
			'the existing aligned drain schedule is registered once');
		$this->assertTrue(is_file($job['tmp_path']), 'the prepared job remains byte-addressable on refusal');
		$log = implode("\n", FileUtil::$log);
		$this->assertTrue(strpos($log, 'cleanup-drain-no-ack') !== false
			&& strpos($log, $job['base']) === false,
			'the refusal is classified and does not disclose the payload path');
		$this->assertTrue(erasedataCancelObsoleteCleanup($job),
			'the checker can cancel the exact prepared job after refusal');
	}

	public function testPreparedCleanupReportsScheduleRefusalBeforeAcknowledgementWait()
	{
		$this->reset();
		$job = $this->prepareCleanupJob($this->hash('A'));
		$this->assertTrue(is_array($job), 'the exact cleanup job is staged');
		if(!is_array($job))
			return;
		rXMLRPCRequest::$responses['schedule'] = array('ok' => false, 'val' => array());
		$this->assertTrue(!erasedataArmObsoleteCleanupRun($this->dependencies(array(
			'ackTimeout' => 0.02, 'ackPoll' => 0.005)), $job),
			'a failed schedule registration refuses pre-erase arm');
		$log = implode("\n", FileUtil::$log);
		$this->assertTrue(strpos($log, 'cleanup-arm-rearm') !== false
			&& strpos($log, 'cleanup-drain-no-ack') === false,
			'the log names registration failure instead of claiming an ack timeout');
		$this->assertTrue(is_file($job['tmp_path']) && erasedataCancelObsoleteCleanup($job),
			'the exact prepared job remains cancellable after registration failure');
	}

	public function testPreparedCleanupArmIsRetainedAcrossRestartUntilCollection()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$job = $this->prepareCleanupJob($oldHash);
		$this->assertTrue(is_array($job), 'the cleanup obligation is staged before arm');
		if(!is_array($job))
			return;
		$queue = $this->queuePath();
		$child = null;
		$script = 'require '.var_export(__FILE__, true).'; '
			.'FileUtil::$settingsPath = '.var_export($this->dir, true).'; '
			.'usleep(10000); '
			.'exit(is_array(erasedataAcknowledgeDrainGeneration('
			.var_export($queue, true).', '.var_export(User::getUser(), true)
			.', array("FileUtil", "toLog"))) ? 0 : 1);';
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0),
			'callback' => function() use (&$child, $script) {
				$child = ErasedataTestProcess::start('exec '.escapeshellarg(PHP_BINARY)
					.' -r '.escapeshellarg($script));
			});
		try
		{
			$this->assertTrue(erasedataArmObsoleteCleanupRun($this->dependencies(), $job),
				'an independently started PHP child acknowledges the durable generation');
		}
		finally
		{
			$this->assertTrue($child instanceof ErasedataTestProcess && $child->wait(2)
					&& $child->reap() === 0, 'the acknowledgement child exited cleanly');
		}
		$scan = erasedataRetirementScan($this->dependencies());
		$this->assertTrue($scan['classes']['cleanup'] === 1 && !$scan['empty'],
			'the prepared cleanup file prevents drain retirement before predecessor erase');
		$this->assertTrue(erasedataPublishObsoleteCleanup($job),
			'the exact cleanup job publishes after the simulated commit');
		rXMLRPCRequest::$scheduledCommands = array();
		$this->assertTrue(erasedataRearmDrainScheduleRun($this->dependencies()),
			'a restart re-arms the pending cleanup job from durable state');
		$this->assertEquals(1, count($this->scheduleRecords('schedule')),
			'one repeating drain can collect a published cleanup job without the ordinary schedule');
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
		if(testSkipUnlessPermissionsBite('that a cleanup preparation which cannot'
			.' stage its file fails, leaves no partial artifact and still releases'
			.' the old-hash lock'))
			return;
		file_put_contents($this->dir.'/erasedata/'.$oldHash.'.lock', '');
		@chmod($this->dir.'/erasedata', 0555);
		FileUtil::$denyDirectoryRepair = true;
		try {
			$this->assertEquals(false, $this->prepareCleanupJob($oldHash), 'a failed staged write must fail preparation');
			$this->assertEquals(array(), glob($this->dir.'/erasedata/'.$oldHash.'.cleanup.*.tmp'), 'failed preparation must not retain a partial cleanup artifact');
			@chmod($this->dir.'/erasedata', 0777);
			$contender = erasedataAcquireHashLock($this->dir.'/erasedata', $oldHash, true);
			$this->assertTrue(is_resource($contender), 'failed preparation must release the old-hash lock');
			if(is_resource($contender))
				erasedataReleaseHashLock($contender);
		} finally {
			FileUtil::$denyDirectoryRepair = false;
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

	public function testPlaintextLegacyIsRejectedButVersionTwoDecodesAsRemovePayload()
	{
		$this->reset();
		$hash = $this->hash('b');
		$legacy = "/d/single.bin\n/d/single.bin\n0\n1\n";
		$this->assertEquals(false, ErasedataManifestCodec::decodeBytes($legacy, $hash),
			'plaintext legacy manifests cannot prove their base and must be refused');
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

	public function testBarePlaintextListCannotDeleteClaimedPayload()
	{
		$this->reset();
		$hash = $this->hash();
		$base = $this->dir.'/unverified_plaintext';
		$file = $base.'/keep.bin';
		@mkdir($base, 0777, true);
		file_put_contents($file, 'keep');
		$source = $this->dir.'/erasedata/'.$hash.'.list';
		$this->writeLegacyManifestLines($hash.'.list', array($file), $base, 1, 1);
		$bytes = file_get_contents($source);
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'collector exits normally: '.$output);
		$this->assertEquals('keep', @file_get_contents($file),
			'plaintext queue bytes cannot authorize deleting their claimed file');
		$quarantined = glob($source.'.*.unverified');
		$this->assertTrue(count($quarantined) === 1 && file_get_contents($quarantined[0]) === $bytes,
			'the exact source bytes move to a private quarantine name');
		$this->assertTrue(strpos(implode("\n", FileUtil::$log), 'unverified-legacy-list') !== false,
			'the refusal is visible without debug logging');
	}

	public function testPlaintextListIsQuarantinedEvenWhenTorrentPresenceIsUnknown()
	{
		$this->reset();
		$hash = $this->hash();
		$base = $this->dir.'/unverified_unknown_presence';
		@mkdir($base, 0777, true);
		$file = $base.'/keep.bin';
		file_put_contents($file, 'keep');
		$source = $this->dir.'/erasedata/'.$hash.'.list';
		$this->writeLegacyManifestLines($hash.'.list', array($file), $base, 1, 1);
		$bytes = file_get_contents($source);
		list($status, $output) = $this->runCollector(array('ok' => false));
		$this->assertEquals(0, $status, 'collector exits normally: '.$output);
		$quarantined = glob($source.'.*.unverified');
		$this->assertTrue(is_file($file) && count($quarantined) === 1
			&& file_get_contents($quarantined[0]) === $bytes,
			'unverified plaintext is quarantined independent of an RPC verdict');
		$this->assertTrue(strpos(implode("\n", FileUtil::$log),
			'unverified-legacy-list') !== false,
			'the quarantine is visible when rTorrent cannot answer');
	}

	public function testFailedPlaintextQuarantineRetainsOriginalAndLogs()
	{
		$this->reset();
		$hash = $this->hash();
		$base = $this->dir.'/legacy_failed_quarantine';
		@mkdir($base, 0777, true);
		$file = $base.'/keep.bin';
		file_put_contents($file, 'keep');
		$source = $this->dir.'/erasedata/'.$hash.'.list';
		$this->writeLegacyManifestLines($hash.'.list', array($file), $base, 1, 1);
		$bytes = file_get_contents($source);
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'renameNoReplace:*' => array('result' => false))));
		$this->assertEquals(0, $status, 'collector exits normally: '.$output);
		$this->assertTrue(is_file($file) && file_get_contents($source) === $bytes,
			'failed quarantine keeps both payload and original bytes');
		$this->assertTrue(strpos(implode("\n", FileUtil::$log),
			'could not be quarantined') !== false,
			'the refusal remains visible when the no-replace helper is unavailable');
	}

	public function testPlaintextQuarantineNeverReplacesEarlierBytes()
	{
		$this->reset();
		$hash = $this->hash();
		$base = $this->dir.'/legacy_collision';
		@mkdir($base, 0777, true);
		$first = $base.'/first.bin';
		$second = $base.'/second.bin';
		file_put_contents($first, 'first');
		file_put_contents($second, 'second');
		$source = $this->dir.'/erasedata/'.$hash.'.list';
		$this->writeLegacyManifestLines($hash.'.list', array($first), $base, 1, 1);
		$firstBytes = file_get_contents($source);
		$this->runCollector(array());
		$this->writeLegacyManifestLines($hash.'.list', array($second), $base, 1, 1);
		$secondBytes = file_get_contents($source);
		$this->runCollector(array());
		$quarantined = glob($source.'.*.unverified');
		$kept = array_map('file_get_contents', $quarantined);
		$this->assertTrue(count($quarantined) === 2
			&& in_array($firstBytes, $kept, true)
			&& in_array($secondBytes, $kept, true),
			'each plaintext list is kept under a separate quarantine name');
		$this->assertTrue(is_file($first) && is_file($second),
			'no plaintext list deletes its claimed payload');
	}

	public function testPlaintextForceTwoIsQuarantinedWithoutDeletingContent()
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
		$this->assertTrue(file_exists($listed), 'the unverified plaintext list deletes no file');
		$this->assertTrue(file_exists($unlisted), 'unlisted file under legacy force 2 must survive');
		$this->assertTrue(count(glob($this->dir.'/erasedata/'.$hash.'.list.*.unverified')) === 1,
			'legacy force-2 list is quarantined rather than interpreted');
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
			'rename:1' => array('basename' => basename($path), 'target' => $path,
				'action' => 'replace-entry',
				'backup' => $backup, 'replacement' => $replacement, 'marker' => $marker),
			'unlink:1' => array('basename' => basename($path), 'target' => $path,
				'action' => 'replace-entry',
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

	public function testBoundRecoveryLinksUsePersistentLogicalTargets()
	{
		$this->reset();
		$hash = $this->hash('D');
		$base = $this->dir.'/bound-recovery-link-base';
		mkdir($base);
		$this->writeManifestLines($hash.'.list', array($base.'/absent.bin'),
			$base, 1, 1);
		$this->runCollector(array('rmdirCrash' => $base));
		$this->runCollector(array());
		$this->assertTrue(is_link($base), 'the first pass leaves a recovery link');
		$filesystem = new ErasedataFilesystemOps();
		$parent = dirname($base);
		$entry = $filesystem->entryIdentity($parent);
		$reference = $filesystem->openDirectoryReference($parent, $entry);
		$this->assertTrue(is_array($reference), 'the parent has a bound reference');
		if(!is_array($reference))
			return;
		$recovery = erasedataRecoveryLinkTarget(
			$reference['path'].'/'.basename($base), $filesystem, $base);
		$this->assertTrue(is_array($recovery) && !empty($recovery['safe']),
			'the existing recovery layout is valid from the bound path');
		if(is_array($recovery) && !empty($recovery['safe']))
		{
			$this->assertEquals(dirname($recovery['linkTarget']).'/'
				.basename($recovery['captureRoot']).'/directory',
				isset($recovery['linkCapture']) ? $recovery['linkCapture'] : null,
				'the published capture link never contains the process-local descriptor');
		}
		$filesystem->closeDirectoryReference($reference);
	}

	public function testForcedDirectoryParentSwapCannotRemoveDifferentDirectory()
	{
		$this->reset();
		$hash = $this->hash('E');
		$root = $this->dir.'/forced-parent-root';
		$parent = $root.'/inside';
		$base = $parent.'/payload';
		$outside = $this->dir.'/forced-parent-outside';
		$backup = $root.'/inside.checked';
		$marker = $this->dir.'/forced-parent-swapped';
		mkdir($base, 0777, true);
		file_put_contents($base.'/original.bin', 'original');
		mkdir($outside.'/payload', 0777, true);
		file_put_contents($outside.'/payload/sentinel.bin', 'outside-sentinel');
		$this->writeManifestLines($hash.'.list', array($base.'/original.bin'), $base, 1, 2);
		$manifestPath = $this->queuePath().'/'.$hash.'.list';
		$manifest = file_get_contents($manifestPath);

		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'scanDirectory:*' => array('realpath' => $parent, 'action' => 'replace-entry',
				'at' => 'after', 'target' => $parent, 'backup' => $backup,
				'symlink_target' => $outside, 'marker' => $marker),
		)));
		$this->assertEquals(0, $status, 'forced directory collector exits: '.$output);
		$this->assertTrue(is_file($marker), 'the parent changed after its reservation scan');
		$this->assertEquals('outside-sentinel',
			@file_get_contents($outside.'/payload/sentinel.bin'),
			'a changed parent cannot redirect forced directory removal');
		$this->assertEquals($manifest, @file_get_contents($manifestPath),
			'the exact manifest remains until its original directory is handled');
	}

	public function testForcedParentSwapAfterPreparedIntentRetainsOriginalManifest()
	{
		$this->reset();
		$hash = $this->hash('F');
		$root = $this->dir.'/forced-replay-parent-root';
		$parent = $root.'/inside';
		$base = $parent.'/payload';
		$backup = $root.'/inside.checked';
		$outside = $this->dir.'/forced-replay-outside';
		$marker = $this->dir.'/forced-replay-prepared';
		mkdir($base, 0777, true);
		file_put_contents($base.'/original.bin', 'original');
		mkdir($outside.'/payload', 0777, true);
		file_put_contents($outside.'/payload/sentinel.bin', 'outside-sentinel');
		$this->writeManifestLines($hash.'.list', array($base.'/original.bin'),
			$base, 1, 2);
		$manifestPath = $this->queuePath().'/'.$hash.'.list';
		$manifest = file_get_contents($manifestPath);
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'makeDirectory:*' => array('basename_prefix' => '.erasedata-rmdir-',
				'action' => 'exit', 'marker' => $marker),
		)));
		$this->assertEquals(0, $status, 'prepared forced collector exits: '.$output);
		$this->assertTrue(is_file($marker), 'intent was written before reservation setup');
		$intents = glob($this->queuePath().'/.erasedata-rmdir-intent-*');
		$this->assertEquals(1, count($intents), 'the parent identity remains durable');
		if(count($intents) !== 1)
			return;
		$this->assertTrue(rename($parent, $backup), 'the original parent remains held aside');
		$this->assertTrue(symlink($outside, $parent), 'a different parent takes its public name');
		list($status, $output) = $this->runCollector(array('captureLogs' => true));
		$this->assertEquals(0, $status, 'changed-parent replay exits: '.$output);
		$this->assertEquals('outside-sentinel',
			@file_get_contents($outside.'/payload/sentinel.bin'),
			'replay never removes data through the changed parent');
		$this->assertEquals($manifest, @file_get_contents($manifestPath),
			'parent mismatch keeps the exact manifest');
		$this->assertTrue(strpos($output, 'directory-intent-unresolved') !== false,
			'the refusal is visible without debug logging');
		$this->assertEquals(1,
			count(glob($this->queuePath().'/.erasedata-rmdir-intent-*')),
			'the recorded original parent remains available for recovery');
		$this->assertTrue(unlink($parent), 'the changed public parent is removed');
		$this->assertTrue(rename($backup, $parent), 'the original parent returns');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'restored-parent retry exits: '.$output);
		$this->assertTrue(!file_exists($base) && !is_link($base),
			'the original forced directory is removed after restoration');
		$this->assertTrue(!is_file($manifestPath),
			'the proved original-parent operation consumes the manifest');
		$this->assertEquals(array(),
			glob($this->queuePath().'/.erasedata-rmdir-intent-*'),
			'the completed forced operation clears its intent');
		$this->assertEquals('outside-sentinel',
			@file_get_contents($outside.'/payload/sentinel.bin'),
			'the external data remains after recovery');
	}

	public function testForcedParentSwapDuringRecoveryTraversalKeepsOutsideData()
	{
		$this->reset();
		$hash = $this->hash('C');
		$root = $this->dir.'/forced-deep-race-root';
		$parent = $root.'/inside';
		$base = $parent.'/payload';
		$backup = $root.'/inside.checked';
		$outside = $this->dir.'/forced-deep-race-outside';
		$marker = $this->dir.'/forced-deep-race-triggered';
		mkdir($base, 0777, true);
		file_put_contents($base.'/original.bin', 'original');
		mkdir($outside.'/payload', 0777, true);
		file_put_contents($outside.'/payload/sentinel.bin', 'outside-sentinel');
		$this->writeManifestLines($hash.'.list', array($base.'/original.bin'),
			$base, 1, 2);
		$manifestPath = $this->queuePath().'/'.$hash.'.list';
		$manifest = file_get_contents($manifestPath);
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'openDirectoryReference:*' => array('contains' => '.force-',
				'action' => 'replace-entry', 'at' => 'after',
				'target' => $parent, 'backup' => $backup,
				'symlink_target' => $outside, 'marker' => $marker),
		)));
		$this->assertEquals(0, $status, 'forced deep-race collector exits: '.$output);
		$this->assertTrue(is_file($marker),
			'the parent changed after the private traversal reference opened');
		$this->assertEquals('outside-sentinel',
			@file_get_contents($outside.'/payload/sentinel.bin'),
			'bound traversal never removes data in the new public parent');
		$this->assertEquals($manifest, @file_get_contents($manifestPath),
			'a parent change during traversal retains the exact manifest');
		$intents = glob($this->queuePath().'/.erasedata-rmdir-intent-*');
		$this->assertEquals(1, count($intents),
			'the interrupted operation retains its durable parent intent');
		if(count($intents) !== 1)
			return;
		$record = json_decode(file_get_contents($intents[0]), true);
		$this->assertEquals('completed', isset($record['phase']) ? $record['phase'] : null,
			'bound completion is recorded even when the public parent changed');
		$this->assertTrue(unlink($parent), 'the changed public parent is removed');
		$this->assertTrue(rename($backup, $parent), 'the original parent returns');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'restored deep-race retry exits: '.$output);
		$this->assertTrue(!is_file($manifestPath),
			'retry clears the exact manifest after restoring the parent');
		$this->assertEquals(array(), glob($this->queuePath().'/.erasedata-rmdir-intent-*'),
			'retry clears the completed forced intent');
		$this->assertEquals('outside-sentinel',
			@file_get_contents($outside.'/payload/sentinel.bin'),
			'external data remains untouched after recovery');
	}

	public function testForcedCompletedIntentReplaysAfterExitBeforeClear()
	{
		$this->reset();
		$hash = $this->hash('A');
		$base = $this->dir.'/forced-completed-intent-base';
		mkdir($base);
		file_put_contents($base.'/payload.bin', 'payload');
		$this->writeManifestLines($hash.'.list', array($base.'/payload.bin'),
			$base, 1, 2);
		$manifestPath = $this->queuePath().'/'.$hash.'.list';
		$manifest = file_get_contents($manifestPath);
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'unlink:*' => array('basename_prefix' => '.erasedata-rmdir-intent-',
				'action' => 'exit'),
		)));
		$this->assertEquals(0, $status, 'forced collector exits before intent clear: '.$output);
		$this->assertTrue(!file_exists($base), 'the forced directory was removed');
		$this->assertEquals($manifest, @file_get_contents($manifestPath),
			'the exact manifest remains before intent clear');
		$intents = glob($this->queuePath().'/.erasedata-rmdir-intent-*');
		$this->assertEquals(1, count($intents), 'the completed forced intent remains');
		if(count($intents) !== 1)
			return;
		$record = json_decode(file_get_contents($intents[0]), true);
		$this->assertEquals('completed', isset($record['phase']) ? $record['phase'] : null,
			'successful forced deletion is recorded before intent clear');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'forced replay exits: '.$output);
		$this->assertTrue(!is_file($manifestPath),
			'forced replay consumes the completed manifest');
		$this->assertEquals(array(), glob($this->queuePath().'/.erasedata-rmdir-intent-*'),
			'forced replay clears the completed intent');
	}

	public function testForcedMovedPrivateDirectoryKeepsItsManifest()
	{
		$this->reset();
		$hash = $this->hash('B');
		$base = $this->dir.'/forced-moved-private-base';
		mkdir($base);
		file_put_contents($base.'/payload.bin', 'held-data');
		$this->writeManifestLines($hash.'.list', array($base.'/payload.bin'),
			$base, 1, 2);
		$manifestPath = $this->queuePath().'/'.$hash.'.list';
		$manifest = file_get_contents($manifestPath);
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'rename:*' => array('to_contains' => '/.erasedata-rmdir-',
				'action' => 'exit', 'at' => 'after'),
		)));
		$this->assertEquals(0, $status, 'forced collector exits after capture: '.$output);
		$reserved = glob($this->dir.'/.erasedata-rmdir-*/directory');
		$this->assertEquals(1, count($reserved), 'one private directory was captured');
		if(count($reserved) !== 1)
			return;
		$moved = $this->dir.'/forced-moved-private-data';
		$this->assertTrue(rename($reserved[0], $moved),
			'another actor moves the captured directory with data');
		list($status, $output) = $this->runCollector(array('captureLogs' => true));
		$this->assertEquals(0, $status, 'forced replay exits: '.$output);
		$this->assertEquals('held-data', @file_get_contents($moved.'/payload.bin'),
			'moved data remains unchanged');
		$this->assertEquals($manifest, @file_get_contents($manifestPath),
			'missing private directory cannot settle the forced manifest');
		$this->assertTrue(strpos($output, 'directory-intent-unresolved') !== false,
			'the ambiguous capture is visible');
		$this->assertEquals(1, count(glob($this->queuePath().'/.erasedata-rmdir-intent-*')),
			'the ambiguous capture keeps its intent');
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
			'rename:1' => array('realpath' => $base, 'action' => 'replace-entry',
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
			'rename:1' => array('realpath' => $base, 'action' => 'replace-entry',
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

	public function testNonForceManifestCannotUnlinkThroughAnIntermediateSymlink()
	{
		$this->reset();
		$hash = $this->hash('C');
		$base = $this->dir.'/linked-child-root';
		$outside = $this->dir.'/linked-child-outside';
		mkdir($base);
		mkdir($outside);
		file_put_contents($base.'/own.txt', 'own');
		file_put_contents($outside.'/secret.txt', 'outside');
		symlink($outside, $base.'/away');
		$this->writeManifestLines($hash.'.list', array(
			$base.'/own.txt', $base.'/away/secret.txt'), $base, 1, 1);

		$notes = array();
		list($status, $output) = $this->runCollector(array('captureLogs' => true,
			'retentionSink' => function($kind, $reportedHash, $generation, $reason)
				use (&$notes) {
				$notes[] = array($kind, $reportedHash, $generation, $reason);
			}));
		$this->assertEquals(0, $status, 'non-force symlink collector exits: '.$output);
		$this->assertEquals('outside', file_get_contents($outside.'/secret.txt'),
			'an intermediate symlink never authorizes deleting an outside file');
		$this->assertTrue(!is_file($base.'/own.txt'),
			'the independent owned file is still collected');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$hash.'.list'),
			'the unsafe manifest remains retryable');
		$this->assertTrue(in_array(array('manifest', $hash, 'none', 'unsafe-path'),
			$notes, true), 'the drain sink receives the classified refusal');
		$this->assertTrue(strpos($output, 'erasedata: unsafe-path') === false,
			'the ordinary pass does not repeat a direct unsafe-path line');
	}

	public function testOrdinaryUnsafePathRefusalIsVisibleAndBoundedWithoutDrain()
	{
		$this->reset();
		$hash = $this->hash('8');
		$base = $this->dir.'/ordinary-unsafe-root';
		$outside = $this->dir.'/ordinary-unsafe-outside';
		mkdir($base);
		mkdir($outside);
		file_put_contents($outside.'/secret.txt', 'outside');
		symlink($outside, $base.'/away');
		$listed = $base.'/away/secret.txt';
		$this->writeManifestLines($hash.'.list', array($listed), $listed, 0, 1);

		list($status, $first) = $this->runCollector(array('captureLogs' => true));
		$this->assertEquals(0, $status, 'first ordinary refusal exits: '.$first);
		$this->assertTrue(strpos($first, 'erasedata: unsafe-path '.$hash.'.list retained') !== false,
			'the first refusal is visible without an armed drain or debug flag');
		$markers = glob($this->dir.'/.erasedata-unsafe-*.notice');
		$this->assertEquals(1, count($markers), 'one durable report marker is outside the obligation queue');

		list($status, $second) = $this->runCollector(array('captureLogs' => true));
		$this->assertEquals(0, $status, 'repeated ordinary refusal exits: '.$second);
		$this->assertTrue(strpos($second, 'erasedata: unsafe-path') === false,
			'an unchanged refusal does not fill the log every 15 seconds');
		$this->assertEquals('outside', file_get_contents($outside.'/secret.txt'),
			'the repeated refusal never deletes through the symlink');

		unlink($base.'/away');
		mkdir($base.'/away');
		file_put_contents($listed, 'now-owned');
		list($status, $completed) = $this->runCollector(array('captureLogs' => true));
		$this->assertEquals(0, $status, 'repaired ordinary job exits: '.$completed);
		$this->assertTrue(!file_exists($listed), 'the repaired path is collected');
		$this->assertTrue(!is_file($this->dir.'/erasedata/'.$hash.'.list'),
			'the repaired manifest is consumed');
		$this->assertEquals(array(), glob($this->dir.'/.erasedata-unsafe-*.notice'),
			'completion clears its durable report marker');
	}

	public function testSingleFileManifestDeletesARegularFile()
	{
		$this->reset();
		$hash = $this->hash('9');
		$base = $this->dir.'/single-regular-root';
		mkdir($base);
		$file = $base.'/payload.bin';
		file_put_contents($file, 'owned');
		$this->writeManifestLines($hash.'.list', array($file), $file, 0, 1);

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'single-file collector exits: '.$output);
		$this->assertTrue(!file_exists($file), 'an ordinary single file is removed');
		$this->assertTrue(!is_file($this->dir.'/erasedata/'.$hash.'.list'),
			'the completed single-file manifest is consumed');
	}

	public function testSingleFileManifestCannotUnlinkThroughAnIntermediateSymlink()
	{
		$this->reset();
		$hash = $this->hash('A');
		$base = $this->dir.'/single-linked-root';
		$outside = $this->dir.'/single-linked-outside';
		mkdir($base);
		mkdir($outside);
		file_put_contents($outside.'/secret.txt', 'outside');
		symlink($outside, $base.'/away');
		$this->writeManifestLines($hash.'.list',
			array($base.'/away/secret.txt'), $base.'/away/secret.txt', 0, 1);

		list($status, $output) = $this->runCollector(array('captureLogs' => true));
		$this->assertEquals(0, $status, 'single-file symlink collector exits: '.$output);
		$this->assertEquals('outside', file_get_contents($outside.'/secret.txt'),
			'an intermediate symlink never authorizes deleting an outside single file');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$hash.'.list'),
			'the unsafe single-file manifest remains retryable');
	}

	public function testNonForceManifestCannotRemoveEmptyOutsideDirectoryThroughSymlink()
	{
		$this->reset();
		$hash = $this->hash('B');
		$base = $this->dir.'/linked-dir-root';
		$outside = $this->dir.'/linked-dir-outside';
		mkdir($base);
		mkdir($outside.'/nested', 0777, true);
		symlink($outside, $base.'/away');
		$this->writeManifestLines($hash.'.list',
			array($base.'/away/nested/gone.txt'), $base, 1, 1);

		list($status, $output) = $this->runCollector(array('captureLogs' => true));
		$this->assertEquals(0, $status, 'nested symlink collector exits: '.$output);
		$this->assertTrue(is_dir($outside.'/nested'),
			'an empty directory beyond the symlink is not removed');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$hash.'.list'),
			'the unsafe directory keeps the manifest');
	}

	public function testNonForceParentSwapAfterPreflightCannotUnlinkOutsideFile()
	{
		$this->reset();
		$hash = $this->hash('D');
		$base = $this->dir.'/parent-swap-root';
		$parent = $base.'/inside';
		$outside = $this->dir.'/parent-swap-outside';
		$backup = $base.'/inside.checked';
		$marker = $this->dir.'/parent-swap.triggered';
		mkdir($parent, 0777, true);
		mkdir($outside);
		file_put_contents($parent.'/secret.txt', 'old');
		file_put_contents($outside.'/secret.txt', 'outside');
		$this->writeManifestLines($hash.'.list', array($parent.'/secret.txt'), $base, 1, 1);

		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'entryIdentity:1' => array('basename' => 'secret.txt',
				'action' => 'replace-entry', 'target' => $parent,
				'backup' => $backup, 'symlink_target' => $outside,
				'marker' => $marker),
		)));
		$this->assertEquals(0, $status, 'parent-swap collector exits: '.$output);
		$this->assertTrue(is_file($marker), 'the swap reached the leaf read after preflight');
		$this->assertEquals('outside', file_get_contents($outside.'/secret.txt'),
			'the replacement parent does not redirect deletion outside the base');
		$this->assertTrue(is_file($this->dir.'/erasedata/'.$hash.'.list'),
			'the uncertain parent retains the manifest');
	}

	public function testNonForceDirectoryParentSwapAfterGuardCannotMoveOutsideDirectory()
	{
		$this->reset();
		$hash = $this->hash('F');
		$base = $this->dir.'/directory-parent-swap-root';
		$parent = $base.'/inside';
		$nested = $parent.'/empty';
		$outside = $this->dir.'/directory-parent-swap-outside';
		$backup = $base.'/inside.checked';
		$marker = $this->dir.'/directory-parent-swap.triggered';
		mkdir($nested, 0777, true);
		mkdir($outside.'/empty', 0777, true);
		$this->writeManifestLines($hash.'.list',
			array($nested.'/already-gone.txt'), $base, 1, 1);
		$manifest = file_get_contents($this->dir.'/erasedata/'.$hash.'.list');

		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'entryIdentity:1' => array('path' => $nested,
				'action' => 'replace-entry', 'at' => 'after',
				'target' => $parent, 'backup' => $backup,
				'symlink_target' => $outside, 'marker' => $marker),
		)));
		$this->assertEquals(0, $status, 'directory parent-swap collector exits: '.$output);
		$this->assertTrue(is_file($marker), 'the parent swap reached the directory guard');
		$this->assertTrue(is_dir($outside.'/empty'),
			'the swapped parent cannot redirect a directory reservation outside');
		$this->assertEquals($manifest,
			is_file($this->dir.'/erasedata/'.$hash.'.list')
				? file_get_contents($this->dir.'/erasedata/'.$hash.'.list') : null,
			'the changed public parent retains the exact manifest');
	}

	public function testNonForceDirectoryParentSwapDuringBoundRenameRetainsIntent()
	{
		$this->reset();
		$hash = $this->hash('7');
		$base = $this->dir.'/directory-rename-swap-root';
		$parent = $base.'/inside';
		$nested = $parent.'/empty';
		$outside = $this->dir.'/directory-rename-swap-outside';
		$backup = $base.'/inside.checked';
		$marker = $this->dir.'/directory-rename-swap.triggered';
		mkdir($nested, 0777, true);
		mkdir($outside.'/empty', 0777, true);
		$this->writeManifestLines($hash.'.list',
			array($nested.'/already-gone.bin'), $base, 1, 1);
		$manifestPath = $this->queuePath().'/'.$hash.'.list';
		$manifest = file_get_contents($manifestPath);
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'rename:*' => array('to_contains' => '/.erasedata-rmdir-',
				'action' => 'replace-entry', 'at' => 'after',
				'target' => $parent, 'backup' => $backup,
				'symlink_target' => $outside, 'marker' => $marker),
		), 'captureLogs' => true));
		$this->assertEquals(0, $status, 'post-rename parent swap exits: '.$output);
		$this->assertTrue(is_file($marker), 'the swap follows the bound rename');
		$this->assertTrue(is_dir($outside.'/empty'),
			'the external directory is untouched after the parent changes');
		$this->assertEquals($manifest, @file_get_contents($manifestPath),
			'the exact manifest survives a parent change during capture');
		$this->assertTrue(count(glob($this->queuePath().'/.erasedata-rmdir-intent-*')) === 1,
			'the captured directory keeps a durable replay intent');
	}

	public function testNonForceDirectoryCaptureCrashAndMissingParentKeepsIntent()
	{
		$this->reset();
		$hash = $this->hash('D');
		$base = $this->dir.'/directory-intent-root';
		$parent = $base.'/inside';
		$nested = $parent.'/empty';
		$outside = $this->dir.'/directory-intent-outside';
		$backup = $base.'/inside.checked';
		mkdir($nested, 0777, true);
		mkdir($outside.'/empty', 0777, true);
		$this->writeManifestLines($hash.'.list',
			array($nested.'/already-gone.txt'), $base, 1, 1);
		$manifestPath = $this->dir.'/erasedata/'.$hash.'.list';
		$manifest = file_get_contents($manifestPath);
		list($status) = $this->runCollector(array('filesystem' => array(
			'rename:*' => array('to_contains' => '/.erasedata-rmdir-',
				'action' => 'exit', 'at' => 'after'),
		)));
		$this->assertEquals(0, $status, 'the scripted child exits after capture');
		$this->assertTrue(count(glob($parent.'/.erasedata-rmdir-*/directory')) === 1,
			'the captured directory survives the process exit');
		$this->assertTrue(rename($parent, $backup), 'another actor moves the parent');
		$this->assertTrue(symlink($outside, $parent), 'the public parent points outside');
		unlink($parent);
		list($status, $output) = $this->runCollector(array('captureLogs' => true));
		$this->assertEquals(0, $status, 'replay exits: '.$output);
		$this->assertTrue(strpos($output,
			'erasedata: directory-intent-unresolved '.$hash.'.list retained') !== false,
			'the lost parent and durable intent are visibly classified');
		$this->assertEquals($manifest, @file_get_contents($manifestPath),
			'a missing public parent cannot consume a manifest with unresolved capture intent');
		$this->assertTrue(is_dir($outside.'/empty'),
			'replay never removes the external directory');
		$this->assertTrue(count(glob($this->queuePath().'/.erasedata-rmdir-intent-*')) === 1,
			'the durable intent remains while its parent is missing');
		$this->assertTrue(rename($backup, $parent), 'the original parent is restored');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'restored-parent replay exits: '.$output);
		$this->assertTrue(!is_file($manifestPath),
			'the restored parent lets the exact captured directory finish');
		$this->assertEquals(array(), glob($this->queuePath().'/.erasedata-rmdir-intent-*'),
			'the completed operation clears its durable intent');
		$this->assertTrue(is_dir($outside.'/empty'),
			'recovery still never removes the external directory');
	}

	public function testCapturedIntentDoesNotSettleWhenPrivateShellMovesAway()
	{
		$this->reset();
		$hash = $this->hash('9');
		$base = $this->dir.'/captured-shell-base';
		mkdir($base);
		$this->writeManifestLines($hash.'.list', array($base.'/already-gone.bin'),
			$base, 1, 1);
		$manifestPath = $this->queuePath().'/'.$hash.'.list';
		$manifest = file_get_contents($manifestPath);
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'removeDirectory:*' => array('basename' => 'directory', 'action' => 'exit'),
		)));
		$this->assertEquals(0, $status, 'collector exits after the capture: '.$output);
		$shells = glob($this->dir.'/.erasedata-rmdir-*');
		$this->assertEquals(1, count($shells), 'the private shell stands after capture');
		if(count($shells) !== 1)
			return;
		$intents = glob($this->queuePath().'/.erasedata-rmdir-intent-*');
		$this->assertTrue(count($intents) === 1
			&& $this->writeLegacyCapturedDirectoryIntent($intents[0]),
			'the old captured intent is restored before the private shell moves');
		$payload = $shells[0].'/directory/secret.bin';
		$this->assertTrue(file_put_contents($payload, 'held data') !== false,
			'the reserved directory still contains data');
		$moved = $this->dir.'/moved-private-shell';
		$this->assertTrue(rename($shells[0], $moved),
			'another actor moves the private shell without removing its data');
		list($status, $output) = $this->runCollector(array('captureLogs' => true));
		$this->assertEquals(0, $status, 'replay exits: '.$output);
		$this->assertEquals($manifest, @file_get_contents($manifestPath),
			'a missing private shell cannot settle the manifest');
		$this->assertEquals(1,
			count(glob($this->queuePath().'/.erasedata-rmdir-intent-*')),
			'the unresolved capture keeps its durable intent');
		$this->assertEquals('held data', @file_get_contents($moved.'/directory/secret.bin'),
			'moved private data remains unchanged');
	}

	public function testMissingPrivateDirectoryBetweenIntentCheckAndRecoveryRemainsPending()
	{
		$this->reset();
		$hash = $this->hash('8');
		$base = $this->dir.'/missing-reserved-directory-base';
		mkdir($base);
		$this->writeManifestLines($hash.'.list', array($base.'/absent.bin'),
			$base, 1, 1);
		$manifestPath = $this->queuePath().'/'.$hash.'.list';
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'removeDirectory:*' => array('basename' => 'directory', 'action' => 'exit'),
		)));
		$this->assertEquals(0, $status, 'capture exits: '.$output);
		$shells = glob($this->dir.'/.erasedata-rmdir-*');
		$this->assertEquals(1, count($shells), 'capture leaves one private shell');
		if(count($shells) !== 1)
			return;
		$moved = $this->dir.'/moved-reserved-directory';
		$this->assertTrue(rename($shells[0].'/directory', $moved),
			'another actor moves the private directory but leaves its shell');
		file_put_contents($moved.'/payload.bin', 'held');
		$markPhase = function($reservation, $phase) {
			return($phase !== 'verify-missing');
		};
		$finished = erasedataRecoverNonForceDirectory($base, $manifestPath,
			array($shells[0]), new ErasedataFilesystemOps(), $base,
			$markPhase, function() { return(true); });
		$this->assertTrue(!$finished,
			'a captured intent without its private directory cannot settle');
		$this->assertEquals('held', file_get_contents($moved.'/payload.bin'),
			'the moved data remains untouched');
		$this->assertTrue(is_dir($shells[0]),
			'the unresolved private shell remains for inspection');
	}

	public function testNonForceLegacyReservationReplaysWithoutIntent()
	{
		$this->reset();
		$hash = $this->hash('C');
		$base = $this->dir.'/legacy-reservation-base';
		mkdir($base);
		$this->writeManifestLines($hash.'.list', array($base.'/absent.bin'),
			$base, 1, 1);
		$manifestPath = $this->queuePath().'/'.$hash.'.list';
		list($status) = $this->runCollector(array('filesystem' => array(
			'rename:*' => array('to_contains' => '/.erasedata-rmdir-',
				'action' => 'exit', 'at' => 'after'),
		)));
		$this->assertEquals(0, $status, 'the original reservation survives a crash');
		$reservations = glob(dirname($base).'/.erasedata-rmdir-*/directory');
		$this->assertTrue(count($reservations) === 1,
			'the exact old-style directory reservation exists');
		$intents = glob($this->queuePath().'/.erasedata-rmdir-intent-*');
		$this->assertTrue(count($intents) === 1, 'the new collector wrote its intent');
		if(count($intents) === 1)
			unlink($intents[0]); // Model a reservation published by the old collector.
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'legacy reservation replay exits: '.$output);
		$this->assertTrue(!is_file($manifestPath),
			'an old reservation with the logical SHA is replayed to completion');
		$this->assertEquals(array(), glob($this->queuePath().'/.erasedata-rmdir-intent-*'),
			'replay leaves no new intent behind');
	}

	public function testPreparedIntentWithoutShellCannotAuthorizeRestartedDeletion()
	{
		$this->reset();
		$hash = $this->hash('A');
		$base = $this->dir.'/prepared-intent-base';
		$moved = $base.'.moved';
		mkdir($base);
		$this->writeManifestLines($hash.'.list', array($base.'/absent.bin'),
			$base, 1, 1);
		$manifestPath = $this->queuePath().'/'.$hash.'.list';
		$manifest = file_get_contents($manifestPath);
		list($status) = $this->runCollector(array('filesystem' => array(
			'makeDirectory:*' => array('basename_prefix' => '.erasedata-rmdir-',
				'action' => 'exit'),
		)));
		$this->assertEquals(0, $status, 'the child exits before creating the shell');
		$this->assertTrue(count(glob($this->queuePath().'/.erasedata-rmdir-intent-*')) === 1,
			'the prepared intent precedes the first shell mutation');
		$this->assertEquals(array(), glob($this->dir.'/.erasedata-rmdir-*'),
			'no private shell was created');
		$this->assertTrue(rename($base, $moved), 'the original inode stays allocated');
		$this->assertTrue(mkdir($base), 'a replacement occupies the public name');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'prepared-intent replay exits: '.$output);
		$this->assertEquals($manifest, @file_get_contents($manifestPath),
			'a pre-capture intent cannot authorize deletion of a new occupant');
		$this->assertTrue(is_dir($base) && is_dir($moved),
			'both the replacement and original directories survive');
	}

	public function testNonForceDirectoryIntentClearFailureRetainsManifest()
	{
		$this->reset();
		$hash = $this->hash('B');
		$base = $this->dir.'/intent-clear-base';
		mkdir($base);
		$this->writeManifestLines($hash.'.list', array($base.'/absent.bin'),
			$base, 1, 1);
		$manifestPath = $this->queuePath().'/'.$hash.'.list';
		$manifest = file_get_contents($manifestPath);
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'unlink:*' => array('basename_prefix' => '.erasedata-rmdir-intent-',
				'result' => false),
		)));
		$this->assertEquals(0, $status, 'intent-clear refusal exits: '.$output);
		$this->assertEquals($manifest, @file_get_contents($manifestPath),
			'failed intent clear cannot consume the manifest');
		$this->assertTrue(count(glob($this->queuePath().'/.erasedata-rmdir-intent-*')) === 1,
			'the unresolved intent remains durable');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'retry after the intent-clear failure exits: '.$output);
		$this->assertTrue(!is_file($manifestPath),
			'a proved completion retries intent clear and consumes the manifest');
		$this->assertEquals(array(), glob($this->queuePath().'/.erasedata-rmdir-intent-*'),
			'the retry clears a completed intent');
	}

	public function testCrashAfterPrivateRmdirBeforeCompletedPhaseRetainsIntent()
	{
		foreach(array(1, 2) as $force)
		{
			$this->reset();
			$hash = $this->hash((string)$force);
			$base = $this->dir.'/post-rmdir-before-phase-'.$force;
			mkdir($base);
			$file = $base.'/payload.bin';
			if($force === 2)
				file_put_contents($file, 'payload');
			$this->writeManifestLines($hash.'.list', array($file),
				$base, 1, $force);
			$manifestPath = $this->queuePath().'/'.$hash.'.list';
			$manifest = file_get_contents($manifestPath);
			list($status, $output) = $this->runCollector(array('filesystem' => array(
				'removeDirectory:*' => array('basename' => 'directory',
					'action' => 'exit', 'at' => 'after'))));
			$this->assertEquals(0, $status,
				'collector exits just after private rmdir, force='.$force.': '.$output);
			$private = glob($this->dir.'/.erasedata-rmdir-*/directory');
			$capturedGone = !is_dir($base);
			foreach($private as $candidate)
				$capturedGone = $capturedGone && !is_dir($candidate);
			$this->assertTrue($capturedGone,
				'the captured directory is gone before the phase write, force='.$force);
			$intents = glob($this->queuePath().'/.erasedata-rmdir-intent-*');
			$this->assertEquals(1, count($intents),
				'one durable intent survives, force='.$force);
			if(count($intents) !== 1)
				continue;
			$record = json_decode(file_get_contents($intents[0]), true);
			$this->assertEquals('deleting', isset($record['phase']) ? $record['phase'] : null,
				'the new worker records its pre-rmdir phase, force='.$force);
			// Emulate a v1 process that exited after rmdir, before its first
			// completion write. Its captured phase cannot prove the removal.
			$this->assertTrue($this->writeLegacyCapturedDirectoryIntent($intents[0]),
				'the legacy captured intent is restored, force='.$force);
			list($status, $output) = $this->runCollector(array('captureLogs' => true));
			$this->assertEquals(0, $status, 'replay exits, force='.$force.': '.$output);
			$this->assertEquals($manifest, @file_get_contents($manifestPath),
				'ambiguous replay retains the exact manifest, force='.$force);
			$this->assertTrue(strpos($output, 'directory-intent-unresolved') !== false,
				'the unresolved intent is visible in the journal, force='.$force);
			$this->assertEquals(1, count(glob(
				$this->queuePath().'/.erasedata-rmdir-intent-*')),
				'replay keeps its recovery evidence, force='.$force);
		}
	}

	public function testPreparedPrivateRmdirReplaysAfterProcessExit()
	{
		foreach(array(1, 2) as $force)
		{
			$this->reset();
			$hash = $this->hash((string)$force);
			$base = $this->dir.'/prepared-rmdir-'.$force;
			mkdir($base);
			$file = $base.'/payload.bin';
			if($force === 2) file_put_contents($file, 'payload');
			$this->writeManifestLines($hash.'.list', array($file), $base, 1, $force);
			$manifestPath = $this->queuePath().'/'.$hash.'.list';
			$manifest = file_get_contents($manifestPath);
			list($status, $output) = $this->runCollector(array('filesystem' => array(
				'removeDirectory:*' => array('basename' => 'directory',
					'action' => 'exit', 'at' => 'after'))));
			$this->assertEquals(0, $status, 'worker exits after private rmdir, force='.$force.': '.$output);
			$intents = glob($this->queuePath().'/.erasedata-rmdir-intent-*');
			$this->assertEquals(1, count($intents), 'the durable intent survives, force='.$force);
			if(count($intents) !== 1) continue;
			$record = json_decode(file_get_contents($intents[0]), true);
			$this->assertEquals('deleting', isset($record['phase']) ? $record['phase'] : null,
				'the pre-rmdir phase survives the crash, force='.$force);
			$this->assertEquals($manifest, @file_get_contents($manifestPath),
				'the exact manifest survives the crash, force='.$force);
			list($status, $output) = $this->runCollector(array('captureLogs' => true));
			$this->assertEquals(0, $status, 'replay exits, force='.$force.': '.$output);
			$this->assertTrue(!file_exists($manifestPath),
				'replay retires the exact obligation, force='.$force);
			$this->assertEquals(array(), glob($this->queuePath().'/.erasedata-rmdir-intent-*'),
				'replay clears its intent, force='.$force);
			$this->assertEquals(array(), glob($this->dir.'/.erasedata-rmdir-*'),
				'replay removes private recovery shells, force='.$force);
			$this->assertTrue(!erasedataPathExists($base),
				'replay removes the public recovery link, force='.$force);
		}
	}

	public function testPreparedPrivateRmdirRetriesBeforeSyscallAndAfterRefusal()
	{
		foreach(array(1, 2) as $force)
			foreach(array('before', 'failure') as $cut)
			{
				$this->reset();
				$hash = $this->hash((string)$force);
				$base = $this->dir.'/prepared-rmdir-'.$force.'-'.$cut;
				mkdir($base);
				$file = $base.'/payload.bin';
				if($force === 2) file_put_contents($file, 'payload');
				$this->writeManifestLines($hash.'.list', array($file), $base, 1, $force);
				$manifestPath = $this->queuePath().'/'.$hash.'.list';
				$manifest = file_get_contents($manifestPath);
				$script = array('basename' => 'directory');
				if($cut === 'before')
					$script += array('action' => 'exit', 'at' => 'before');
				else $script['result'] = false;
				list($status, $output) = $this->runCollector(array('filesystem' => array(
					'removeDirectory:*' => $script)));
				$this->assertEquals(0, $status, 'interrupted worker exits, force='.$force.' cut='.$cut.': '.$output);
				$intents = glob($this->queuePath().'/.erasedata-rmdir-intent-*');
				$this->assertEquals(1, count($intents), 'the intent survives the uncommitted rmdir');
				if(count($intents) !== 1) continue;
				$record = json_decode(file_get_contents($intents[0]), true);
				$this->assertEquals('deleting', isset($record['phase']) ? $record['phase'] : null,
					'the write-ahead phase precedes the rmdir');
				$this->assertEquals($manifest, @file_get_contents($manifestPath),
					'the exact obligation survives the uncommitted rmdir');
				list($status, $output) = $this->runCollector(array());
				$this->assertEquals(0, $status, 'retry exits, force='.$force.' cut='.$cut.': '.$output);
				$this->assertTrue(!erasedataPathExists($base) && !is_file($manifestPath),
					'retry performs and retires the deletion only after rmdir succeeds');
				$this->assertEquals(array(), glob($this->queuePath().'/.erasedata-rmdir-intent-*'),
					'retry clears the durable intent');
			}
	}

	public function testRecoveredBackingRmdirReplaysAfterProcessExit()
	{
		$this->reset();
		$firstHash = $this->hash('A');
		$secondHash = $this->hash('B');
		$base = $this->dir.'/recovered-backing-rmdir-exit';
		mkdir($base);
		$this->writeManifestLines($firstHash.'.list', array($base.'/absent.bin'),
			$base, 1, 1);
		$this->runCollector(array('rmdirCrash' => $base));
		$this->runCollector(array());
		$this->assertTrue(is_link($base), 'the first generation leaves a live recovery link');
		file_put_contents($base.'/payload.bin', 'payload');
		$this->writeManifestLines($secondHash.'.list', array($base.'/payload.bin'),
			$base, 1, 2);
		$manifestPath = $this->queuePath().'/'.$secondHash.'.list';
		$manifest = file_get_contents($manifestPath);
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'removeDirectory:*' => array('basename' => 'directory',
				'action' => 'exit', 'at' => 'after'))));
		$this->assertEquals(0, $status, 'the force worker exits after recovered backing rmdir: '.$output);
		$intents = glob($this->queuePath().'/.erasedata-rmdir-intent-*');
		$this->assertEquals(1, count($intents), 'the second generation retains its intent');
		if(count($intents) !== 1) return;
		$record = json_decode(file_get_contents($intents[0]), true);
		$this->assertEquals('deleting', isset($record['phase']) ? $record['phase'] : null,
			'the recovered backing has a write-ahead deletion phase');
		$this->assertEquals($manifest, @file_get_contents($manifestPath),
			'the exact second-generation obligation remains');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'the recovered backing replay exits: '.$output);
		$this->assertTrue(!erasedataPathExists($base) && !is_file($manifestPath),
			'the recovered backing replay retires the force obligation');
		$this->assertEquals(array(), glob($this->queuePath().'/.erasedata-rmdir-intent-*'),
			'the recovered backing replay clears its intent');
	}

	public function testFreshForceDirectoryCleanupCrashReplays()
	{
		foreach(array('tombstone', 'bridge', 'container') as $cut)
		{
			$this->reset();
			$hash = $this->hash($cut === 'tombstone' ? 'A'
				: ($cut === 'bridge' ? 'B' : 'C'));
			$base = $this->dir.'/fresh-force-cleanup-'.$cut;
			mkdir($base);
			$file = $base.'/payload.bin';
			file_put_contents($file, 'payload');
			$this->writeManifestLines($hash.'.list', array($file), $base, 1, 2);
			$manifestPath = $this->queuePath().'/'.$hash.'.list';
			$manifest = file_get_contents($manifestPath);
			list($status, $output) = $this->runCollector(array('cleanupCrash' => $cut));
			$this->assertEquals(0, $status, 'worker exits at '.$cut.' cleanup: '.$output);
			$this->assertEquals($manifest, @file_get_contents($manifestPath),
				'the exact obligation survives '.$cut.' cleanup');
			$intents = glob($this->queuePath().'/.erasedata-rmdir-intent-*');
			$this->assertEquals(1, count($intents), 'the intent survives '.$cut.' cleanup');
			if(count($intents) !== 1) continue;
			$record = json_decode(file_get_contents($intents[0]), true);
			$this->assertEquals('deleting', isset($record['phase']) ? $record['phase'] : null,
				'the pre-rmdir phase remains during '.$cut.' cleanup');
			list($status, $output) = $this->runCollector(array('captureLogs' => true));
			$this->assertEquals(0, $status, $cut.' replay exits: '.$output);
			$this->assertTrue(!erasedataPathExists($base) && !is_file($manifestPath),
				$cut.' replay retires the exact force obligation');
			$this->assertEquals(array(), glob($this->dir.'/.erasedata-rmdir-*'),
				$cut.' replay clears the private reservation');
			$this->assertEquals(array(), glob($this->queuePath().'/.erasedata-rmdir-intent-*'),
				$cut.' replay clears the intent');
		}
	}

	public function testPostRmdirAndExternallyMovedPrivateDirectoryShareCapturedEvidence()
	{
		foreach(array(1, 2) as $force)
		{
			$this->reset();
			$hash = $this->hash((string)$force);
			$base = $this->dir.'/ambiguous-'.$force;
			mkdir($base);
			$file = $base.'/payload.bin';
			if($force === 2) file_put_contents($file, 'payload');
			$this->writeManifestLines($hash.'.list', array($file), $base, 1, $force);
			$manifestPath = $this->queuePath().'/'.$hash.'.list';
			$manifest = file_get_contents($manifestPath);
			list($status, $output) = $this->runCollector(array('filesystem' => array(
				'removeDirectory:*' => array('basename' => 'directory',
					'action' => 'exit', 'at' => 'before'))));
			$this->assertEquals(0, $status, 'the child stops immediately before private rmdir: '.$output);
			$outer = glob($this->dir.'/.erasedata-rmdir-*');
			$this->assertEquals(1, count($outer), 'the exact private shell remains');
			if(count($outer) !== 1) continue;
			$private = $force === 2
				? glob($outer[0].'/directory.force-*/directory')
				: array($outer[0].'/directory');
			$this->assertEquals(1, count($private), 'the checked directory is in its private slot');
			if(count($private) !== 1) continue;
			$private = $private[0];
			$intents = glob($this->queuePath().'/.erasedata-rmdir-intent-*');
			$this->assertEquals(1, count($intents), 'the exact v1 intent survives');
			if(count($intents) !== 1) continue;
			$intentPath = $intents[0];
			$record = json_decode(file_get_contents($intentPath), true);
			$this->assertEquals('deleting', $record['phase'],
				'the current worker has reached its new pre-rmdir phase');
			$this->assertTrue($this->writeLegacyCapturedDirectoryIntent($intentPath),
				'the legacy captured intent is restored before the paired histories');
			$record = json_decode(file_get_contents($intentPath), true);
			$this->assertEquals('captured', $record['phase'],
				'both legacy histories start from the same captured phase');
			$this->assertEquals(basename($outer[0]), $record['reservation'],
				'the durable reservation names the observed shell');
			$this->assertTrue(is_string($record['targetDev']) && is_string($record['targetIno']),
				'the durable record carries the original checked identity');
			$recorded = array('dev' => $record['targetDev'], 'ino' => $record['targetIno']);
			$this->assertEquals($recorded, $this->collectorInode($private),
				'the private directory is the inode bound by the durable intent');
			$known = array_unique(array($base, $outer[0], $outer[0].'/directory',
				dirname($private), $private));
			$observation = function() use ($intentPath, $manifestPath, $known) {
				$paths = array();
				foreach($known as $path)
				{
					clearstatcache(true, $path);
					$stat = @lstat($path);
					if(!is_array($stat)) $paths[$path] = 'absent';
					else if(is_link($path)) $paths[$path] = 'link:'.readlink($path);
					else if(is_dir($path))
					{
						$names = array_values(array_diff(scandir($path), array('.', '..')));
						sort($names);
						$paths[$path] = 'directory:'.implode(',', $names);
					}
					else $paths[$path] = 'other';
				}
				return(array('intent' => file_get_contents($intentPath),
					'manifest' => file_get_contents($manifestPath), 'paths' => $paths));
			};
			$moved = $this->dir.'/external-private-'.$force;
			$this->assertTrue(rename($private, $moved), 'another actor moves the checked inode');
			$this->assertEquals($recorded, $this->collectorInode($moved),
				'the moved directory keeps the recorded inode');
			file_put_contents($moved.'/held.bin', 'held');
			$afterMove = $observation();
			list($status, $output) = $this->runCollector(array('captureLogs' => true));
			$this->assertEquals(0, $status, 'moved-directory replay exits: '.$output);
			$this->assertEquals($manifest, @file_get_contents($manifestPath),
				'moved-directory replay retains the exact manifest');
			$this->assertEquals($afterMove['intent'], @file_get_contents($intentPath),
				'moved-directory replay retains the exact captured intent');
			$this->assertTrue(is_dir(dirname($private)),
				'moved-directory replay retains the private capture shell');
			$this->assertTrue(strpos($output, 'directory-intent-unresolved') !== false,
				'moved-directory replay reports its refusal');
			$this->assertEquals('held', @file_get_contents($moved.'/held.bin'),
				'the moved payload remains untouched');
			if(!is_file($manifestPath) || !is_file($intentPath)
				|| !is_dir(dirname($private)))
				continue;
			$this->assertTrue(unlink($moved.'/held.bin'), 'external payload can be removed before restoring');
			$this->assertTrue(rename($moved, $private), 'the same checked inode returns to its private slot');
			$this->assertEquals($recorded, $this->collectorInode($private),
				'the restored directory is still the recorded inode');
			$this->assertTrue(rmdir($private), 'rmdir succeeds on that checked inode');
			$afterRmdir = $observation();
			$this->assertEquals($afterMove, $afterRmdir,
				'the exact v1 intent and every known path match after a move and successful rmdir');
			list($status, $output) = $this->runCollector(array('captureLogs' => true));
			$this->assertEquals(0, $status, 'post-rmdir replay exits: '.$output);
			$this->assertEquals($manifest, @file_get_contents($manifestPath),
				'post-rmdir replay retains the same ambiguous manifest');
			$this->assertEquals($afterMove['intent'], @file_get_contents($intentPath),
				'post-rmdir replay retains the exact captured intent');
		}
	}

	public function testCompletedDirectoryIntentReplaysAfterProcessExitBeforeClear()
	{
		$this->reset();
		$hash = $this->hash('7');
		$base = $this->dir.'/completed-intent-exit-base';
		mkdir($base);
		$this->writeManifestLines($hash.'.list', array($base.'/absent.bin'),
			$base, 1, 1);
		$manifestPath = $this->queuePath().'/'.$hash.'.list';
		$manifest = file_get_contents($manifestPath);
		list($status, $output) = $this->runCollector(array('filesystem' => array(
			'unlink:*' => array('basename_prefix' => '.erasedata-rmdir-intent-',
				'action' => 'exit'),
		)));
		$this->assertEquals(0, $status, 'collector exits before intent clear: '.$output);
		$this->assertTrue(!file_exists($base), 'the checked directory was removed');
		$this->assertEquals($manifest, @file_get_contents($manifestPath),
			'the process exit leaves the exact manifest');
		$intents = glob($this->queuePath().'/.erasedata-rmdir-intent-*');
		$this->assertEquals(1, count($intents), 'the durable intent remains');
		if(count($intents) !== 1)
			return;
		$record = json_decode(file_get_contents($intents[0]), true);
		$this->assertEquals('completed', isset($record['phase']) ? $record['phase'] : null,
			'the intent records the successful rmdir before the process exit');
		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'the next collector pass exits: '.$output);
		$this->assertTrue(!is_file($manifestPath),
			'the replay consumes the completed manifest');
		$this->assertEquals(array(), glob($this->queuePath().'/.erasedata-rmdir-intent-*'),
			'the replay clears the completed intent');
	}

	public function testNonForceMissingParentReplayCompletesWithoutCreatingData()
	{
		$this->reset();
		$hash = $this->hash('E');
		$base = $this->dir.'/missing-parent-root';
		mkdir($base);
		$this->writeManifestLines($hash.'.list',
			array($base.'/absent/old.txt'), $base, 1, 1);

		list($status, $output) = $this->runCollector(array());
		$this->assertEquals(0, $status, 'missing-parent replay exits: '.$output);
		$this->assertTrue(!is_file($this->dir.'/erasedata/'.$hash.'.list'),
			'a proven absent parent lets the old no-op obligation complete');
		$this->assertTrue(!file_exists($base.'/absent'),
			'no directory or payload is created to complete the replay');
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
			'rename:1' => array('realpath' => $base, 'action' => 'recreate', 'at' => 'after',
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
			'rename:1' => array('realpath' => $base, 'action' => 'replace-entry',
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
			'rename:1' => array('realpath' => $base, 'action' => 'exit',
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
			'rename:1' => array('basename' => basename($path), 'target' => $path,
				'action' => 'replace-entry',
				'backup' => $backup, 'replacement' => $replacement, 'marker' => $marker),
			'unlink:1' => array('basename' => basename($path), 'target' => $path,
				'action' => 'replace-entry',
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

		if(!testSkipUnlessPermissionsBite('that a staging file which cannot be'
			.' renamed reports failure and keeps its bytes for a retry')) {
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
		}

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

	// =======================================================================
	// Package 6: "remove with data" as a generation-bound admission
	// transaction, a durable per-user rTorrent schedule that starts a real
	// guarded CLI worker, staged manifests, exact per-hash erase outcomes,
	// recovery and retirement.
	//
	// Everything below is RED-first: it is written against the exact base,
	// where none of the production symbols it names exist yet. Each case
	// PROBES for the symbols it needs and then asserts its invariant, so a
	// missing implementation reads as one "Failed: <invariant>" line rather
	// than a fatal that would hide every later case in this file.
	//
	// The contract the later slices must satisfy, by name:
	//
	//   filesystem.php
	//     ErasedataFilesystemOps::canonicalMissingPath($path)
	//     ErasedataFilesystemOps::acquireDirectoryCapability($path, $identity)
	//     ErasedataFilesystemOps::releaseDirectoryCapability($capability)
	//     erasedataDescriptorCandidates()
	//     erasedataWriteDurableFile($path, $bytes, $mode = null)
	//     erasedataGenerationIsValid($value)
	//     erasedataGenerationIncrement($generation)
	//     erasedataGenerationCompare($left, $right)
	//   pending.php
	//     erasedataEncodePendingMarker(array $record)
	//     erasedataDecodePendingMarker($bytes)
	//     erasedataQueueRequest($listPath, $hash, $force, $generation)
	//     erasedataPendingObligations($listPath)
	//     erasedataLockObligations($listPath, $hashes)
	//     erasedataUnlockObligations($locks)
	//   removewithdata.php
	//     erasedataDrainScheduleKey($user)
	//     erasedataReadDrainState($listPath)
	//     erasedataWriteDrainState($listPath, array $state)
	//     erasedataAdmissionPartition($hashes, $force)
	//     erasedataAdmitRemoval($hashes, $force)             [public door]
	//     erasedataRemovalAdmissionRun(array $dependencies, $hashes, $force)
	//     erasedataRemoveWithData($hashes, $force)              [public, 2 args]
	//     erasedataDrainWorkerRun(array $dependencies)
	//     erasedataDrainWorkerMain($user)                       [public]
	//     erasedataClassifyEraseOutcomes(array $hashes, $request)
	//     erasedataRetirementScan(array $dependencies)
	//     erasedataRetirementRun(array $dependencies, array &$notes)
	//     erasedataRearmDrainScheduleRun(array $dependencies)
	//     erasedataRearmDrainSchedule()                         [public, 0 args]
	//
	// The dependency array the internal runners take is exactly:
	//   listPath, user, filesystem, log, ackTimeout, ackPoll.
	// `forceEnabled` was in that list and was read by nobody. It is gone from
	// erasedataAdmitRemoval() rather than wired up, because the value cannot be
	// built truthfully at either public door: neither erase.php nor action.php
	// evaluates the plugin configuration, so $enableForceDeletion is unset in
	// every process that reaches the door and an admission-time refusal built
	// on it would refuse every force-2 removal, including the ones an operator
	// switched the setting on for. Coercing force 2 down to force 1 is
	// forbidden outright, so the policy stays where the exact base enforces it:
	// collector.php at deletion time, which runs under update.php and does have
	// the setting. The helpers below still pass the key; nothing reads it.
	// Test injection lives here, in test-owned adapters around those runners;
	// the public wrappers always build the real dependencies themselves.
	//
	// erasedataLockObligations()/erasedataUnlockObligations() were added to the
	// contract by the task 1 fix round: PendingQueueTest's paired-subprocess
	// case needs a real production entry point that decides the hash lock order
	// for a batch, and invariant 7 makes that order canonical, deduplicated and
	// sorted before the first lock is taken.
	// =======================================================================

	// -- package 6 harness --------------------------------------------------

	// Anchor the repository root to the test source, independent of the
	// caller's working directory.
	private function repositoryRoot()
	{
		return(__DIR__.'/../../..');
	}

	private function productionPath($relative)
	{
		return($this->repositoryRoot().'/plugins/erasedata/'.$relative);
	}

	// Production source bytes, or null. An unreadable source is an explicit
	// failure: a "does not contain" assertion must never be satisfied by a
	// read that returned false.
	private function productionSource($relative)
	{
		$bytes = @file_get_contents($this->productionPath($relative));
		$this->assertTrue(is_string($bytes) && $bytes !== '',
			'production source plugins/erasedata/'.$relative.' is readable');
		return(is_string($bytes) && $bytes !== '' ? $bytes : null);
	}

	private function sourceHas($relative, $needle, $message)
	{
		$bytes = $this->productionSource($relative);
		$this->assertTrue(is_string($bytes) && strpos($bytes, $needle) !== false, $message);
	}

	private function sourceLacks($relative, $needle, $message)
	{
		$bytes = $this->productionSource($relative);
		$this->assertTrue(is_string($bytes) && strpos($bytes, $needle) === false, $message);
	}

	// The body of one top-level production function, or null.
	//
	// A structural assertion has to be scoped to the function that owns the
	// property or it is worthless: "filesystem.php mentions fclose" is satisfied
	// by four unrelated call sites. The body runs from the signature to the next
	// top-level "function " in the file, and an empty or unfindable body is an
	// explicit failure so no assertion can pass on a read that found nothing.
	// Delimit on WHICHEVER boundary comes first: a column-zero "function " (which
	// is how filesystem.php separates its free functions) or the
	// if(!function_exists( guard that owns every function in removewithdata.php.
	// Using only the column-zero form silently ran a "body" to end-of-file in
	// removewithdata.php -- 0 lines there match it -- so a positive assertion
	// passed on a match anywhere later in the file and a negative one held only
	// by accident of function ordering. Three call sites were correct by luck.
	private function productionFunctionBody($relative, $signature)
	{
		$bytes = $this->productionSource($relative);
		$start = is_string($bytes) ? strpos($bytes, $signature) : false;
		if($start === false)
		{
			$this->assertTrue(false, 'plugins/erasedata/'.$relative.' declares '.$signature);
			return(null);
		}
		$rest = substr($bytes, $start + strlen($signature));
		$end = false;
		foreach(array("\nfunction ", "\nif(!function_exists(") as $delimiter)
		{
			$at = strpos($rest, $delimiter);
			if($at !== false && ($end === false || $at < $end))
				$end = $at;
		}
		$body = ($end === false) ? $rest : substr($rest, 0, $end);
		$this->assertTrue(is_string($body) && $body !== '',
			'the body of '.$signature.' is readable for inspection');
		return(is_string($body) && $body !== '' ? $body : null);
	}

	// One function's body, delimited by the if(!function_exists(...)) guard that
	// owns it rather than by the next column-zero "function ": every function in
	// removewithdata.php is indented inside such a guard, so the column-zero
	// delimiter never fires there and a "body" runs to the end of the file.
	private function guardedFunctionBody($relative, $signature)
	{
		$bytes = $this->productionSource($relative);
		$start = is_string($bytes) ? strpos($bytes, $signature) : false;
		if($start === false)
		{
			$this->assertTrue(false,
				'plugins/erasedata/'.$relative.' declares '.$signature);
			return(null);
		}
		$rest = substr($bytes, $start + strlen($signature));
		$end = strpos($rest, "\nif(!function_exists(");
		$body = ($end === false) ? $rest : substr($rest, 0, $end);
		$this->assertTrue(is_string($body) && $body !== '',
			'the body of '.$signature.' is readable for inspection');
		return(is_string($body) && $body !== '' ? $body : null);
	}

	// Probe first, assert second. "erasedataX()" is a function, "Class::method"
	// a method, a bare name a class.
	private function requireApi(array $symbols, $invariant)
	{
		$missing = array();
		foreach($symbols as $symbol)
		{
			if(strpos($symbol, '::') !== false)
			{
				$parts = explode('::', $symbol, 2);
				if(!class_exists($parts[0]) || !method_exists($parts[0], $parts[1]))
					$missing[] = $symbol;
			}
			else if(substr($symbol, -2) === '()')
			{
				if(!function_exists(substr($symbol, 0, -2)))
					$missing[] = $symbol;
			}
			else if(!class_exists($symbol))
				$missing[] = $symbol;
		}
		if(count($missing))
		{
			$this->assertTrue(false, $invariant
				.' [not implemented yet: '.implode(', ', $missing).']');
			return(false);
		}
		return(true);
	}

	private function queuePath()
	{
		return($this->dir.'/erasedata');
	}

	// The explicit dependencies the internal runners take. Test-owned: no
	// production code reads any of it.
	private function dependencies(array $overrides = array())
	{
		$defaults = array(
			'listPath' => $this->queuePath(),
			'user' => User::getUser(),
			'filesystem' => new ErasedataFilesystemOps(),
			'log' => array('FileUtil', 'toLog'),
			'forceEnabled' => true,
			'ackTimeout' => 0.30,
			'ackPoll' => 0.02,
		);
		foreach($overrides as $key => $value)
			$defaults[$key] = $value;
		return($defaults);
	}

	// A byte-verified mirror of the shipped plugin, with test-owned adapters
	// for everything below the plugin boundary.
	private function mirror($name = 'mirror', $user = 'rutorrent')
	{
		$mirror = ErasedataProductionMirror::build($this->dir.'/'.$name,
			$this->repositoryRoot(), $user);
		$this->assertTrue($mirror->isExact(),
			'the child runs the shipped production bytes ('.$mirror->describe().')');
		return($mirror);
	}

	// Every entry the queue directory holds, names only.
	private function queueEntries($queue = null)
	{
		$queue = is_null($queue) ? $this->queuePath() : $queue;
		$entries = @scandir($queue);
		if(!is_array($entries))
			return(array());
		$entries = array_values(array_diff($entries, array('.', '..')));
		sort($entries, SORT_STRING);
		return($entries);
	}

	private function scheduleRecords($family = null)
	{
		$ret = array();
		foreach(rXMLRPCRequest::$scheduledCommands as $record)
			if($family === null || $record['family'] === $family)
				$ret[] = $record;
		return($ret);
	}

	// Retirement, the way the one production caller reaches it.
	//
	// erasedataRetirementRun() takes its notes list by reference and reports
	// nothing itself: erasedataDrainWorkerRun() folds the notes into the tick's
	// own report memory. There is deliberately no self-reporting one-argument
	// wrapper any more -- production never called one, so it was a branch only
	// this file could reach.
	private function retire($dependencies = null)
	{
		$notes = array();
		return(erasedataRetirementRun(is_array($dependencies)
			? $dependencies : $this->dependencies(), $notes));
	}

	// Which shipped plugin files carry $needle. Used to pin that an internal
	// runner really has a PRODUCTION caller: a helper only the tests call is
	// not reachable code, however carefully it is written.
	private function productionCallers($needle)
	{
		$callers = array();
		$unreadable = array();
		foreach(array_merge(ErasedataProductionMirror::pluginFiles(),
			array('../httprpc/action.php')) as $file)
		{
			$bytes = @file_get_contents($this->repositoryRoot().'/plugins/erasedata/'.$file);
			if(!is_string($bytes) || $bytes === '')
			{
				$unreadable[] = $file;
				continue;
			}
			if(strpos($bytes, $needle) !== false)
				$callers[] = $file;
		}
		$this->assertTrue(count($unreadable) === 0,
			'every shipped plugin source is readable for inspection ('
				.implode(', ', $unreadable).')');
		return($callers);
	}

	// The test playing the scheduler, synchronously.
	//
	// rTorrent answers a schedule registration and, some ticks later, starts
	// the child. The recording-only stub never does either, so a case that has
	// to reach a POST-acknowledgement branch in process installs this callback
	// and raises `acknowledged` itself, at the moment the registration is
	// observed. It is legitimate here for the same reason the fixture's
	// register is recording-only everywhere else: it is the test, not the RPC
	// layer, that decides a child ran, and the acknowledgement invariant itself
	// (invariant 5: only a really started guarded child raises it) is carried
	// by testTheProducerNeverWritesTheAcknowledgement and by the real
	// update.php children of the paired-subprocess cases, none of which use
	// this helper.
	private function acknowledgeOnRegistration($queue, $extra = null)
	{
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0));
		rXMLRPCRequest::$responses['schedule']['callback'] =
			function($commands) use ($queue, $extra)
			{
				if(is_callable($extra))
					call_user_func($extra, $commands);
				$state = erasedataReadDrainState($queue);
				if(!is_array($state) || !isset($state['generation']))
					return;
				$state['acknowledged'] = $state['generation'];
				erasedataWriteDrainState($queue, $state);
			};
	}

	// -- durable primitives (task 2) ----------------------------------------

	public function testExactForceDomainIsRefusedBeforeStagingRpcAndMarkers()
	{
		$this->reset();
		$invariant = 'admission refuses every force outside the exact 1|2 domain'
			.' before any marker, staging or RPC, and never coerces it';
		if(!$this->requireApi(array('erasedataAdmissionPartition()'), $invariant))
			return;
		$hash = $this->hash('A');
		$rejected = array('0', 3, '1 ', ' 2', true, false, 1.0, '01', '', null,
			array(1), '2.0', '+1', "1\n", 'one');
		foreach($rejected as $force)
		{
			$outcome = erasedataAdmissionPartition(array($hash), $force);
			$this->assertTrue($outcome === false,
				'force '.json_encode($force).' is refused outright');
		}
		$this->assertEquals(array(), $this->queueEntries(),
			'a refused force leaves the queue directory empty');
		$this->assertEquals(0, count(rXMLRPCRequest::$requested),
			'a refused force reaches no RPC at all');
		foreach(array(1, 2) as $force)
		{
			$outcome = erasedataAdmissionPartition(array($hash), $force);
			$this->assertTrue(is_array($outcome) && isset($outcome['force'])
				&& $outcome['force'] === $force,
				'integer force '.$force.' is admitted and keeps its integer type');
		}
	}

	public function testForceTwoKeepsItsIntegerTypeThroughEveryDurableRecord()
	{
		$this->reset();
		$invariant = 'force is integer 1|2 in every PHP value and one decimal'
			.' character in every durable record, parsed back strictly';
		if(!$this->requireApi(array('erasedataEncodePendingMarker()',
			'erasedataDecodePendingMarker()'), $invariant))
			return;
		$hash = $this->hash('B');
		$generation = '000000000000000f';
		$bytes = erasedataEncodePendingMarker(array(
			'version' => 1, 'generation' => $generation, 'hash' => $hash, 'force' => 2));
		$this->assertTrue(is_string($bytes), 'a complete marker record encodes');
		$this->assertTrue(is_string($bytes)
			&& preg_match('/(^|\n)force=2(\n|$)/D', $bytes) === 1,
			'force serializes as the single decimal character 2');
		$decoded = is_string($bytes) ? erasedataDecodePendingMarker($bytes) : false;
		$this->assertTrue(is_array($decoded) && array_key_exists('force', $decoded)
			&& $decoded['force'] === 2,
			'the marker parses back to integer 2, not "2"');
		$this->assertTrue(is_array($decoded) && isset($decoded['generation'])
			&& $decoded['generation'] === $generation,
			'the marker carries the exact generation it was written with');
		$this->assertTrue(is_string($bytes)
			&& erasedataEncodePendingMarker($decoded) === $bytes,
			'encoding is byte deterministic, so a rewrite cannot drift');
		foreach(array('force=12', 'force=1 ', 'force=+1', 'force=01', 'force=',
			'force=3', 'force=x') as $broken)
		{
			$this->assertTrue(erasedataDecodePendingMarker(
				"version=1\ngeneration=".$generation."\nhash=".$hash."\n".$broken."\n") === false,
				'a marker carrying '.trim($broken).' is refused, never coerced to 1');
		}
		$this->assertEquals(2, ErasedataManifestCodec::normalizeForce(2),
			'the manifest codec already agrees that force 2 is the integer 2');
	}

	public function testGenerationArithmeticIsStringOnlyAndFailsClosedOnOverflow()
	{
		$this->reset();
		$invariant = 'a generation is 16 lowercase hex digits, incremented and'
			.' compared as strings, failing closed at ffffffffffffffff';
		if(!$this->requireApi(array('erasedataGenerationIsValid()',
			'erasedataGenerationIncrement()', 'erasedataGenerationCompare()'), $invariant))
			return;
		$this->assertTrue(erasedataGenerationIsValid('0000000000000000'),
			'the zero generation is valid');
		foreach(array('FFFFFFFFFFFFFFFF', '1', '00000000000000000', '000000000000000g',
			'0x00000000000000', 0, null, true, ' 0000000000000001') as $bad)
			$this->assertTrue(!erasedataGenerationIsValid($bad),
				json_encode($bad).' is not a generation');
		$this->assertEquals('0000000000000001', erasedataGenerationIncrement('0000000000000000'),
			'increment carries from zero');
		$this->assertEquals('0000000000000010', erasedataGenerationIncrement('000000000000000f'),
			'increment carries out of a nibble');
		$this->assertEquals('0000000100000000', erasedataGenerationIncrement('00000000ffffffff'),
			'increment carries across the 32-bit boundary');
		$this->assertEquals('ffffffffffffffff', erasedataGenerationIncrement('fffffffffffffffe'),
			'increment reaches the maximum exactly');
		$this->assertTrue(erasedataGenerationIncrement('ffffffffffffffff') === false,
			'overflow at the maximum fails closed rather than wrapping');
		$this->assertTrue(erasedataGenerationIncrement('FFFFFFFFFFFFFFFE') === false,
			'an uppercase generation is refused, not lowercased');
		$this->assertEquals(-1, erasedataGenerationCompare('0000000000000009', '0000000000000010'),
			'compare orders across a carry');
		$this->assertEquals(0, erasedataGenerationCompare('20000000000000ff', '20000000000000ff'),
			'compare recognises equality');
		$this->assertEquals(1, erasedataGenerationCompare('0000000100000000', '00000000ffffffff'),
			'compare orders across the 32-bit boundary');
		// Both operands are beyond double precision: a float or native-width
		// collapse makes these equal.
		$this->assertEquals(-1, erasedataGenerationCompare('ffffffffffffff00', 'ffffffffffffff01'),
			'values beyond float precision still compare exactly');
		$this->assertTrue(erasedataGenerationCompare('zzzzzzzzzzzzzzzz', '0000000000000001') === false,
			'a malformed operand fails closed instead of comparing');
		$this->sourceLacks('removewithdata.php', 'hexdec(',
			'no hexdec() anywhere in the generation path');
		$this->sourceLacks('filesystem.php', 'hexdec(',
			'no hexdec() in the filesystem primitives either');
	}

	public function testDurableWriterRequiresCompleteBytesFlushCloseAndAtomicRename()
	{
		$this->reset();
		$invariant = 'the durable writer publishes only after a complete byte'
			.' count, fflush, a checked fclose and an atomic rename';
		if(!$this->requireApi(array('erasedataWriteDurableFile()'), $invariant))
			return;
		$queue = $this->queuePath();
		$target = $queue.'/durable.state';
		$payload = str_repeat("abcdefghij\n", 512);
		$this->assertTrue(erasedataWriteDurableFile($target, $payload) === true,
			'a complete write publishes the file');
		$this->assertEquals($payload, @file_get_contents($target),
			'the published bytes are exactly what was handed in');
		$this->assertEquals(array('durable.state'), $this->queueEntries(),
			'publication leaves no staging residue beside the final name');
		// A stream that writes half of the first chunk and then stalls: the
		// writer must refuse and publish nothing.
		ErasedataPartialWriteStream::register();
		$partial = ErasedataPartialWriteStream::SCHEME.'://'.$queue.'/partial.state';
		$this->assertTrue(erasedataWriteDurableFile($partial, $payload) === false,
			'a short write is reported as failure, not as success');
		$this->assertTrue(!file_exists($queue.'/partial.state'),
			'a short write publishes nothing under the final name');
		// A stream that takes every byte and then fails its flush. The byte
		// count is satisfied, so this is reached only by CHECKING fflush().
		ErasedataFlushFailureStream::register();
		$unflushed = ErasedataFlushFailureStream::SCHEME.'://'.$queue.'/unflushed.state';
		$this->assertTrue(erasedataWriteDurableFile($unflushed, $payload) === false,
			'a complete write whose flush fails is reported as failure, not as success');
		$this->assertTrue(!file_exists($queue.'/unflushed.state'),
			'a failed flush publishes nothing under the final name');
		$residue = array();
		foreach($this->queueEntries() as $entry)
			if(substr($entry, -4) === '.tmp')
				$residue[] = $entry;
		$this->assertEquals(array(), $residue,
			'and a failed flush leaves no staging object behind either');
		// The close is CHECKED. PHP's fclose() returns true for every stream a
		// writer could legitimately have opened -- a userland wrapper's
		// stream_close() return value is discarded, and the NO_FCLOSE streams
		// that answer false are unreachable from a staging name -- so no
		// behavioural case can drive a failing close. The property is therefore
		// pinned structurally, scoped to the writer's own body so that an
		// unrelated fclose elsewhere in the file cannot satisfy it.
		$writer = $this->productionFunctionBody('filesystem.php',
			'function erasedataWriteDurableFile(');
		$this->assertTrue(is_string($writer) && preg_match(
			'/if\s*\(\s*@?\s*fclose\s*\(\s*\$handle\s*\)\s*(!==\s*true|===\s*false)\s*\)/', $writer) === 1,
			'the durable writer tests the return of its own fclose rather than firing and forgetting');
		$this->assertTrue(is_string($writer) && preg_match(
			'/@?\s*fflush\s*\(\s*\$handle\s*\)\s*===\s*false/', $writer) === 1,
			'and tests the return of its own fflush');
		$this->assertTrue(is_string($writer) && strpos($writer, 'rename(') !== false,
			'and publishes with a rename rather than by writing the final name');
		// The staging name's leading dot is the same kind of property: no
		// behavioural case can observe it, because the writer removes its own
		// staging on every failure it survives, and the fixtures never record
		// the path they were opened with. Without the dot the name would fall
		// inside the <hash>.<generation>.<...>.tmp grammar the drain worker's
		// unbound-staging scan matches, which strands the hash.
		$this->assertTrue(is_string($writer) && preg_match(
			'/\$staging\s*=\s*dirname\(\$path\)\s*\.\s*\'\/\.\'/', $writer) === 1,
			'and stages under a dot-prefixed name, outside the <hash>.<generation>.<...>.tmp grammar');
		$this->sourceHas('filesystem.php', 'erasedataWriteDurableFile',
			'the durable writer lives in the filesystem primitives');
	}

	public function testDurableWriteFailureIsTerminalForTheAttempt()
	{
		$this->reset();
		$invariant = 'an unwritable or colliding durable target fails the whole'
			.' attempt instead of leaving a partial or half-published record';
		if(!$this->requireApi(array('erasedataWriteDurableFile()'), $invariant))
			return;
		$queue = $this->queuePath();
		// The parent of the target is a regular file, so no uid can create it.
		@file_put_contents($queue.'/blocker', 'x');
		$this->assertTrue(erasedataWriteDurableFile($queue.'/blocker/state', 'x') === false,
			'a target whose parent is not a directory fails');
		// The final name is a directory, so the atomic rename cannot land.
		@mkdir($queue.'/collision', 0777, true);
		$this->assertTrue(erasedataWriteDurableFile($queue.'/collision', 'x') === false,
			'a final name already taken by a directory fails');
		$this->assertTrue(is_dir($queue.'/collision'),
			'and the colliding object is left exactly as it was');
		$residue = array();
		foreach($this->queueEntries() as $entry)
			if(substr($entry, -4) === '.tmp' || strpos($entry, 'state') === 0)
				$residue[] = $entry;
		$this->assertEquals(array(), $residue,
			'a failed durable write leaves no staging residue behind');
	}

	public function testMissingTailNormalizationRefusesDotComponents()
	{
		$this->reset();
		$invariant = 'an unresolved path tail is component-normalized and any'
			.' . or .. component is refused, so no name can climb out of its'
			.' canonical ancestor';
		if(!$this->requireApi(array('ErasedataFilesystemOps::canonicalMissingPath'), $invariant))
			return;
		$filesystem = new ErasedataFilesystemOps();
		$base = $this->dir.'/tails';
		@mkdir($base.'/existing', 0777, true);
		$real = realpath($base);
		$this->assertEquals($real.'/existing/leaf',
			$filesystem->canonicalMissingPath($base.'/existing/leaf'),
			'a clean missing tail resolves against its deepest existing ancestor');
		foreach(array('/existing/missing/../escape', '/existing/./leaf',
			'/existing/../../escape', '/existing/missing//leaf',
			'/existing/missing/.') as $tail)
		{
			$resolved = $filesystem->canonicalMissingPath($base.$tail);
			$this->assertTrue($resolved === false,
				'a missing tail containing '.$tail.' is refused, not reconstructed verbatim');
		}
		$this->assertTrue($filesystem->canonicalMissingPath('relative/leaf') === false,
			'a relative path is refused');
		$this->assertTrue($filesystem->canonicalMissingPath($base."/nul\0byte") === false,
			'a NUL byte is refused');
		$this->sourceHas('filesystem.php', 'canonicalMissingPath',
			'ErasedataFilesystemOps owns missing-tail normalization');
	}

	// Containment is only real when the tail is proven ABSENT.
	//
	// realpath() and lstat() answer "not there" the same way for a name that is
	// missing, for a dangling symlink and for an ancestor this uid may not
	// search, and the last two reconstruct a tail whose components are symlinks.
	// That divergence is ordinary here: the producer runs as the web user and
	// the eraser as whatever uid rTorrent uses, which is why
	// erasedataSharedFileMode() exists at all. A "canonical" name that satisfies
	// any lexical containment check against the intended root and then resolves
	// somewhere else entirely is the whole hazard, so absence is proven or the
	// answer is refused.
	public function testAnUnresolvableAncestorYieldsNoCanonicalNameAtAll()
	{
		$this->reset();
		$invariant = 'only a tail proven absent is reconstructed: a dangling'
			.' symlink and an ancestor this process cannot search both fail'
			.' closed instead of producing a lexically contained name';
		if(!$this->requireApi(array('ErasedataFilesystemOps::canonicalMissingPath'), $invariant))
			return;
		$filesystem = new ErasedataFilesystemOps();
		$lab = $this->dir.'/containment';
		$escape = $this->dir.'/containment-escape';
		@mkdir($lab.'/existing', 0777, true);
		$dangling = $lab.'/existing/dangling';
		$this->assertTrue(@symlink($escape, $dangling) === true,
			'a symlink whose target does not exist yet can be planted');
		$this->assertTrue(@lstat($dangling) !== false && @realpath($dangling) === false,
			'and it really is a dangling symlink: present as a name, unresolvable as a path');
		$this->assertTrue($filesystem->canonicalMissingPath($dangling.'/leaf') === false,
			'a tail below a dangling symlink is refused, not reconstructed verbatim');
		$this->assertTrue($filesystem->canonicalMissingPath($dangling) === false,
			'and so is the dangling name itself');
		// The delayed trigger: the link becomes live. The answer must be the
		// RESOLVED name, never the name that merely looks contained.
		@mkdir($escape, 0777, true);
		$resolved = $filesystem->canonicalMissingPath($dangling.'/leaf');
		$this->assertEquals(realpath($escape).'/leaf', $resolved,
			'once the link resolves, the name it yields is the resolved one');
		$this->assertTrue(is_string($resolved) && strpos($resolved, realpath($lab).'/') !== 0,
			'and it never claims to live under the ancestor it was handed');
		// An ancestor this process cannot search. No race at all: the name is
		// lexically inside $private, and 'link' points straight out of it.
		$private = $lab.'/private';
		@mkdir($private, 0777, true);
		$this->assertTrue(@symlink($escape, $private.'/link') === true,
			'a symlink out of the unsearchable directory can be planted');
		@chmod($private, 0000);
		clearstatcache(true, $private.'/link');
		$searchable = @lstat($private.'/link') !== false;
		if(!$searchable)
			$this->assertTrue($filesystem->canonicalMissingPath($private.'/link/payload') === false,
				'an ancestor this process cannot search yields no canonical name at all');
		else
			$this->assertEquals(realpath($escape).'/payload',
				$filesystem->canonicalMissingPath($private.'/link/payload'),
				'a process that CAN search it resolves the link rather than reconstructing it');
		@chmod($private, 0777);
		// A regular file is not an ancestor: nothing can ever be created below it.
		@file_put_contents($lab.'/plainfile', 'x');
		$this->assertTrue($filesystem->canonicalMissingPath($lab.'/plainfile/leaf') === false,
			'a tail below a regular file is refused rather than reconstructed');
		// The clean case still answers, so none of the above is a blanket refusal.
		$this->assertEquals(realpath($lab).'/existing/absent/leaf',
			$filesystem->canonicalMissingPath($lab.'/existing/absent/leaf'),
			'a genuinely absent tail below a searchable ancestor still resolves');
	}

	public function testForceTwoDescriptorCapabilityStaysOpenThroughTheEraseDecision()
	{
		$this->reset();
		$invariant = 'a force-2 traversal holds one open directory descriptor'
			.' whose identity is proven, with /proc/self/fd then /dev/fd as the'
			.' only candidates, and a preflight that closes is not a capability';
		if(!$this->requireApi(array('erasedataDescriptorCandidates()',
			'ErasedataFilesystemOps::acquireDirectoryCapability',
			'ErasedataFilesystemOps::releaseDirectoryCapability'), $invariant))
			return;
		$this->assertEquals(array('/proc/self/fd', '/dev/fd'), erasedataDescriptorCandidates(),
			'the descriptor candidates are a fixed list, /proc first and /dev/fd as fallback');
		$filesystem = new ErasedataFilesystemOps();
		$root = $this->dir.'/capability';
		@mkdir($root.'/payload', 0777, true);
		$identity = $filesystem->targetIdentity($root);
		$capability = $filesystem->acquireDirectoryCapability($root, $identity);
		$this->assertTrue(is_array($capability) && isset($capability['handle'])
			&& is_resource($capability['handle']),
			'the capability keeps a real open handle, not a remembered path');
		$descriptor = is_array($capability) && isset($capability['path'])
			? $capability['path'] : '';
		$this->assertTrue(is_string($descriptor)
			&& (strpos($descriptor, '/proc/self/fd/') === 0
				|| strpos($descriptor, '/dev/fd/') === 0),
			'the capability exposes a descriptor path under one of the candidates');
		$this->assertTrue(is_string($descriptor) && $descriptor !== ''
			&& erasedataSameEntryIdentity($identity, $filesystem->targetIdentity($descriptor)),
			'the descriptor really resolves to the captured directory identity');
		$mismatch = is_array($identity)
			? array('dev' => $identity['dev'], 'ino' => $identity['ino'] + 1) : false;
		$this->assertTrue($filesystem->acquireDirectoryCapability($root, $mismatch) === false,
			'a mismatched expected identity is refused, existence is not acceptance');
		if(is_array($capability))
			$filesystem->releaseDirectoryCapability($capability);
		$this->assertTrue(!is_string($descriptor) || $descriptor === ''
			|| !is_dir($descriptor),
			'releasing the capability really closes the descriptor');
		// The force-2 traversal that needs this capability is parseOneItem()'s
		// force branch in collector.php, and it reaches
		// ErasedataFilesystemOps::acquireDirectoryCapability() through the
		// openDirectoryReference() alias in filesystem.php, which forwards to it.
		// `removewithdata.php` never names acquireDirectoryCapability at all, and
		// its one openDirectoryReference() call is the producer's own admission
		// capability rather than this traversal -- so an assertion pointed at
		// that file was looking in the wrong place, and adding a call there to
		// satisfy the string match would be exactly the dead code this rebuild
		// exists to delete.
		$this->sourceHas('collector.php', 'openDirectoryReference',
			'the force-2 traversal opens a directory reference rather than probing once');
		$this->sourceHas('filesystem.php', 'acquireDirectoryCapability',
			'and that reference is the one capability implementation, kept open');
	}

	// The fixed candidate roots, driven one at a time.
	//
	// testForceTwoDescriptorCapabilityStaysOpenThroughTheEraseDecision pins the
	// list and the happy path; this pins what the list is FOR. Production always
	// gets erasedataDescriptorCandidates(); the injected list exists so the three
	// answers that are otherwise unreachable on a machine that has /proc -- a
	// candidate root that exists but names no descriptor of ours, the fall
	// through to the second root, and no usable root at all -- are decided here
	// rather than by whatever the host happens to mount.
	public function testDescriptorCandidateRootsAreDrivenThroughTheInjectedSeam()
	{
		$this->reset();
		$invariant = 'a candidate root is accepted only when a descriptor under'
			.' it proves the open handle\'s identity; existence of the root is'
			.' not the capability';
		if(!$this->requireApi(array('erasedataDescriptorCandidates()',
			'ErasedataFilesystemOps::acquireDirectoryCapability',
			'ErasedataFilesystemOps::releaseDirectoryCapability'), $invariant))
			return;
		$filesystem = new ErasedataFilesystemOps();
		$root = $this->dir.'/candidate-target';
		@mkdir($root, 0777, true);
		$identity = $filesystem->targetIdentity($root);
		$this->assertTrue(is_array($identity), 'the target directory has an identity to prove');
		// A real directory that holds no numeric descriptor at all: the root
		// exists, so a check that stopped at is_dir() would accept it.
		$decoy = $this->dir.'/candidate-decoy';
		@mkdir($decoy, 0777, true);
		$this->assertTrue(
			$filesystem->acquireDirectoryCapability($root, $identity, array($decoy)) === false,
			'a candidate root that exists but proves no identity yields no capability');
		$this->assertTrue(
			$filesystem->acquireDirectoryCapability($root, $identity,
				array($this->dir.'/candidate-absent-a', $this->dir.'/candidate-absent-b')) === false,
			'no usable candidate root at all yields no capability');
		$available = array();
		foreach(erasedataDescriptorCandidates() as $candidate)
			if(is_dir($candidate))
				$available[] = $candidate;
		$this->assertTrue(count($available) > 0,
			'at least one of the fixed descriptor roots exists on this host');
		if(!count($available))
			return;
		$capability = $filesystem->acquireDirectoryCapability(
			$root, $identity, array($decoy, $available[0]));
		$this->assertTrue(is_array($capability) && isset($capability['root'])
			&& $capability['root'] === $available[0],
			'an unusable first candidate falls through to the next one in the list');
		$this->assertTrue(is_array($capability) && isset($capability['handle'])
			&& is_resource($capability['handle']),
			'and the capability it hands back still carries an open handle');
		$this->assertTrue(is_array($capability)
			&& $filesystem->releaseDirectoryCapability($capability) === true,
			'releasing it reports the close it really performed');
		$this->assertTrue(is_array($capability)
			&& $filesystem->releaseDirectoryCapability($capability) === false,
			'and releasing an already released capability reports failure rather'
				.' than pretending to close a handle a second time');
		// A regular file is not a directory capability, however exactly its
		// identity matches: a force-2 traversal handed one would walk nothing
		// and conclude the payload was gone.
		$file = $this->dir.'/candidate-file';
		@file_put_contents($file, 'x');
		$this->assertTrue($filesystem->acquireDirectoryCapability(
			$file, $filesystem->targetIdentity($file)) === false,
			'a regular file never yields a directory capability');
	}

	// Every descriptor under $root that names $identity right now. The test's
	// own view of the same evidence acquireDirectoryCapability() reasons from.
	private function descriptorsNaming($root, $identity)
	{
		$naming = array();
		$expected = erasedataIdentityDeviceAndInode($identity);
		$entries = @scandir($root);
		if($expected === false || !is_array($entries))
			return($naming);
		foreach($entries as $entry)
		{
			if(!ctype_digit($entry))
				continue;
			clearstatcache(true, $root.'/'.$entry);
			if(erasedataIdentityDeviceAndInode(@stat($root.'/'.$entry)) === $expected)
				$naming[] = $entry;
		}
		return($naming);
	}

	// The descriptor a capability hands back must be the one IT opened.
	//
	// dev/ino agreement identifies the DIRECTORY, never the descriptor: any
	// other handle this process holds on the same directory matches exactly as
	// well. Taking the first match hands out a descriptor the capability does
	// not own, and when its real owner closes it the number is reused by the
	// next open(), so the "capability" then names an unrelated object --
	// through which erasedataDeleteDirectoryReferenceContents() deletes. The
	// case builds that exact situation: decoy handles that a first-match scan
	// would return (proven, not assumed: scandir() sorts numerically-named
	// entries as strings, so the decoys are grown until one really does sort
	// ahead of the number the capability's own open will get).
	public function testADirectoryCapabilityNamesTheDescriptorItOpenedItself()
	{
		$this->reset();
		$invariant = 'the descriptor a capability exposes is the one it opened'
			.' itself, not another handle of this process that happens to name'
			.' the same directory';
		if(!$this->requireApi(array('erasedataDescriptorCandidates()',
			'ErasedataFilesystemOps::acquireDirectoryCapability',
			'ErasedataFilesystemOps::releaseDirectoryCapability'), $invariant))
			return;
		$filesystem = new ErasedataFilesystemOps();
		$root = false;
		foreach(erasedataDescriptorCandidates() as $candidate)
			if(is_dir($candidate))
			{
				$root = $candidate;
				break;
			}
		$this->assertTrue(is_string($root),
			'at least one of the fixed descriptor roots exists on this host');
		if(!is_string($root))
			return;
		$target = $this->dir.'/owned-capability';
		@mkdir($target.'/payload', 0777, true);
		$identity = $filesystem->targetIdentity($target);
		$this->assertTrue(is_array($identity), 'the target directory has an identity to prove');
		// Decoys, plus the number the capability's own open will land on. A
		// probe handle takes that number and gives it straight back, so the
		// acquisition below reuses it.
		$decoys = array();
		$mine = false;
		for($attempt = 0; $attempt < 64; $attempt++)
		{
			$mine = false;
			$handle = @fopen($target, 'r');
			if($handle === false)
				break;
			$decoys[] = $handle;
			$known = $this->descriptorsNaming($root, $identity);
			$probe = @fopen($target, 'r');
			$predicted = array_values(array_diff(
				$this->descriptorsNaming($root, $identity), $known));
			if(is_resource($probe))
				@fclose($probe);
			if(count($predicted) !== 1)
				continue;
			$sorted = $known;
			$sorted[] = $predicted[0];
			sort($sorted, SORT_STRING);
			// A first-match scan would return $sorted[0]. Once that is a decoy,
			// the mutation this case exists for really is reachable.
			if($sorted[0] !== $predicted[0])
			{
				$mine = $predicted[0];
				break;
			}
		}
		$decoyNumbers = $this->descriptorsNaming($root, $identity);
		$this->assertTrue(is_string($mine) && count($decoyNumbers) > 0,
			'the decoy descriptors really sort ahead of the one the capability will open'
				.' (decoys='.count($decoyNumbers).')');
		$capability = $filesystem->acquireDirectoryCapability($target, $identity);
		$this->assertTrue(is_array($capability) && isset($capability['path'])
			&& is_string($capability['path']),
			'the capability is still granted with other handles open on the same directory');
		$descriptor = (is_array($capability) && isset($capability['path']))
			? basename($capability['path']) : '';
		$this->assertTrue($descriptor !== ''
			&& !in_array($descriptor, $decoyNumbers, true),
			'and the descriptor it exposes is none of the handles that were already open');
		// The proof that it matters: close every decoy and let regular files
		// take their numbers. A borrowed descriptor now names a file.
		foreach($decoys as $handle)
			if(is_resource($handle))
				@fclose($handle);
		$decoys = array();
		$filler = array();
		for($i = 0; $i < count($decoyNumbers) + 4; $i++)
		{
			$path = $this->dir.'/filler-'.$i;
			@file_put_contents($path, 'x');
			$handle = @fopen($path, 'r');
			if($handle !== false)
				$filler[] = $handle;
		}
		$this->assertTrue(is_array($capability) && isset($capability['path'])
			&& erasedataSameEntryIdentity($identity,
				$filesystem->targetIdentity($capability['path'])),
			'the capability still names the proven directory after every other'
				.' handle on it is closed and its number reused');
		$this->assertTrue(is_array($capability) && isset($capability['path'])
			&& is_dir($capability['path']),
			'and it is still a directory rather than whatever took the number');
		foreach($filler as $handle)
			if(is_resource($handle))
				@fclose($handle);
		if(is_array($capability))
			$filesystem->releaseDirectoryCapability($capability);
	}

	public function testDrainStateSchemaIsStrictBoundedAndFailsClosed()
	{
		$this->reset();
		$invariant = 'drain state has an exact bounded schema: unknown keys,'
			.' wrong types, bad generations and unknown phases all fail closed';
		if(!$this->requireApi(array('erasedataReadDrainState()',
			'erasedataWriteDrainState()'), $invariant))
			return;
		$queue = $this->queuePath();
		$initial = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($initial) && isset($initial['phase'])
			&& $initial['phase'] === 'disarmed',
			'an empty queue reads as a valid disarmed state');
		$this->assertTrue(is_array($initial) && isset($initial['generation'])
			&& $initial['generation'] === '0000000000000000',
			'and starts at the zero generation');
		$state = array(
			'version' => 1,
			'user' => 'rutorrent',
			'generation' => '0000000000000001',
			'acknowledged' => '0000000000000000',
			'phase' => 'armed',
			'journal' => array(),
			'diagnostics' => array(),
		);
		$this->assertTrue(erasedataWriteDrainState($queue, $state) === true,
			'a complete valid state is written durably');
		$readBack = erasedataReadDrainState($queue);
		$this->assertEquals($state, $readBack,
			'the state reads back exactly, field for field');
		// The EMPTY user is a legitimate owner, not a broken state: every
		// install without HTTP authentication and every install with
		// $forbidUserSettings = true has User::getUser() === ''. A schema that
		// refused it refused the whole protocol on a single-user install.
		$single = $state;
		$single['user'] = '';
		$this->assertTrue(erasedataWriteDrainState($queue, $single) === true,
			'a state owned by the single-user install\'s empty user is written durably');
		$this->assertEquals($single, erasedataReadDrainState($queue),
			'and reads back exactly, field for field');
		$this->assertTrue(erasedataWriteDrainState($queue, $state) === true,
			'the named-user state is restored');
		$broken = array(
			'unknown key' => array('surprise' => 1),
			'wrong version' => array('version' => 2),
			'a user carrying a NUL byte' => array('user' => "ruto\0rrent"),
			'an oversized user' => array('user' => str_repeat('u', 256)),
			'a user of the wrong type' => array('user' => array('rutorrent')),
			'non-hex generation' => array('generation' => 'not-a-generation!'),
			'oversized generation' => array('generation' => '10000000000000000'),
			'uppercase generation' => array('generation' => '000000000000000A'),
			'unknown phase' => array('phase' => 'running'),
			'phase of the wrong type' => array('phase' => 1),
			'journal of the wrong type' => array('journal' => 'none'),
			'ack ahead of generation' => array('acknowledged' => '0000000000000002'),
		);
		foreach($broken as $label => $overrides)
		{
			$candidate = $state;
			foreach($overrides as $key => $value)
				$candidate[$key] = $value;
			$this->assertTrue(erasedataWriteDrainState($queue, $candidate) === false,
				'a state with '.$label.' is refused by the writer');
			@file_put_contents($queue.'/.drain-state', json_encode($candidate));
			$this->assertTrue(erasedataReadDrainState($queue) === false,
				'a state with '.$label.' is refused by the reader');
		}
		@file_put_contents($queue.'/.drain-state', '{"version":1,');
		$this->assertTrue(erasedataReadDrainState($queue) === false,
			'a truncated state file fails closed rather than reading as empty');
	}

	// -- one generation-bound public admission transaction (task 3) ---------

	public function testAdmissionComputesTheExactDisjointPartitionBeforeSideEffects()
	{
		$this->reset();
		$invariant = 'admission partitions the canonical unique request set R'
			.' into A and F before any side effect, with |R| = |A| + |F|';
		if(!$this->requireApi(array('erasedataAdmissionPartition()'), $invariant))
			return;
		$good = array($this->hash('C'), strtolower($this->hash('A')), $this->hash('B'));
		$bad = array('', '../../etc/passwd', str_repeat('Z', 40), str_repeat('A', 39),
			str_repeat('A', 41), null, 12345, array($this->hash('D')));
		$request = array_merge($good, $bad, array($this->hash('C')));
		$outcome = erasedataAdmissionPartition($request, 1);
		$this->assertTrue(is_array($outcome) && isset($outcome['accepted'], $outcome['refused']),
			'the partition names both halves');
		$accepted = is_array($outcome) && isset($outcome['accepted'])
			? $outcome['accepted'] : array();
		$refused = is_array($outcome) && isset($outcome['refused'])
			? $outcome['refused'] : array();
		$this->assertEquals(array($this->hash('A'), $this->hash('B'), $this->hash('C')),
			array_values($accepted),
			'A is the canonical uppercase set, deduplicated and sorted');
		$this->assertEquals(count($bad), count($refused),
			'F holds every inadmissible member and nothing else');
		$this->assertEquals(0, count(array_intersect($accepted, $refused)),
			'A and F are disjoint');
		$this->assertEquals(3 + count($bad), count($accepted) + count($refused),
			'|R| = |A| + |F| over the canonical unique request set');
		$this->assertEquals(array(), $this->queueEntries(),
			'computing the partition mutates no filesystem state');
		$this->assertEquals(0, count(rXMLRPCRequest::$requested),
			'computing the partition performs no RPC');
	}

	public function testRefusedMembersGetNoMarkerJournalStagingRpcOrMutation()
	{
		$this->reset();
		$invariant = 'every member of F gets no marker, no journal entry, no'
			.' staging, no RPC and no filesystem mutation of any kind';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()'), $invariant))
			return;
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		$accepted = $this->hash('A');
		$refused = str_repeat('Z', 40);
		erasedataRemovalAdmissionRun($this->dependencies(),
			array($accepted, $refused, 'not-a-hash'), 1);
		foreach($this->queueEntries() as $entry)
		{
			$this->assertTrue(strpos($entry, $refused) !== 0,
				'no queue entry names the refused hash: '.$entry);
			$this->assertTrue(strpos($entry, 'not-a-hash') === false,
				'no queue entry names the malformed request: '.$entry);
		}
		$mentioned = false;
		foreach(rXMLRPCRequest::$commandCalls as $commands)
			foreach($commands as $command)
				if(json_encode($command->params) !== false
					&& strpos((string)json_encode($command->params), $refused) !== false)
					$mentioned = true;
		$this->assertTrue(!$mentioned, 'no RPC ever mentions a refused hash');
	}

	public function testAdmissionBindsOneExactGenerationToEveryAcceptedMember()
	{
		$this->reset();
		$invariant = 'pending request, journal record, staging identity, final'
			.' manifest and acknowledgement all carry one exact 16-hex generation';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()',
			'erasedataReadDrainState()', 'erasedataGenerationIsValid()'), $invariant))
			return;
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		// The registration and the acknowledgement rTorrent's scheduler would
		// produce. Without them the arm answers false, the run stops before it
		// stages anything, and there is no generation-bound journal record for
		// any of the assertions below to be about -- the case as first written
		// could not pass whatever the producer did.
		$this->acknowledgeOnRegistration($this->queuePath());
		$hashes = array($this->hash('A'), $this->hash('B'));
		$outcome = erasedataRemovalAdmissionRun($this->dependencies(), $hashes, 1);
		$generation = is_array($outcome) && isset($outcome['generation'])
			? $outcome['generation'] : null;
		$this->assertTrue(erasedataGenerationIsValid($generation),
			'admission reports the exact generation it bound');
		$state = erasedataReadDrainState($this->queuePath());
		$this->assertTrue(is_array($state) && isset($state['journal'][$generation]),
			'the journal is keyed by that same generation');
		$entry = is_array($state) && isset($state['journal'][$generation])
			? $state['journal'][$generation] : array();
		$this->assertEquals($hashes, isset($entry['hashes']) ? $entry['hashes'] : array(),
			'the journal record binds the exact sorted canonical hash set');
		$this->assertTrue(isset($entry['force']) && $entry['force'] === 1,
			'and the exact integer force');
		foreach($hashes as $hash)
		{
			$staging = isset($entry['staging'][$hash]) ? $entry['staging'][$hash] : null;
			$this->assertTrue(is_array($staging) && isset($staging['path'],
				$staging['dev'], $staging['ino']),
				'the journal binds a staging path and its captured dev/ino for '.$hash);
			$this->assertTrue(is_array($staging) && isset($staging['path'])
				&& strpos((string)$staging['path'], $generation) !== false,
				'the staging name carries the same generation for '.$hash);
		}
	}

	public function testABareHashListNeverAcknowledgesANewGenerationOfTheSameHash()
	{
		$this->reset();
		$invariant = 'a bare <hash>.list never acknowledges a new generation of'
			.' the same hash';
		if(!$this->requireApi(array('erasedataQueueRequest()',
			'erasedataPendingObligations()'), $invariant))
			return;
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		$generation = '0000000000000007';
		$this->assertTrue(erasedataQueueRequest($queue, $hash, 1, $generation) === true,
			'a generation-bound request is queued');
		@file_put_contents($queue.'/'.$hash.'.list', 'legacy manifest of an older torrent');
		$obligations = erasedataPendingObligations($queue);
		$this->assertTrue(is_array($obligations) && isset($obligations[$generation]),
			'the obligation survives an unrelated bare list of the same hash');
		@file_put_contents($queue.'/'.$hash.'.0000000000000006.99.list', 'older generation');
		$obligations = erasedataPendingObligations($queue);
		$this->assertTrue(is_array($obligations) && isset($obligations[$generation]),
			'and survives a published manifest of a different generation');
	}

	public function testArmPrecedesStagingAndTheFirstErase()
	{
		$this->reset();
		$invariant = 'the durable arm and its successful repeating schedule RPC'
			.' always precede staging and the first d.erase';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()',
			'erasedataReadDrainState()', 'erasedataWriteDrainState()'), $invariant))
			return;
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		$order = array();
		$queue = $this->queuePath();
		// The acknowledgement is played by the test, so the run really reaches
		// the erase: a producer that arms and then stops can no longer satisfy
		// this case by never erasing at all.
		$this->acknowledgeOnRegistration($queue, function($commands) use (&$order, $queue)
		{
			$order[] = 'schedule:'.count(glob($queue.'/*.tmp'));
		});
		erasedataRemovalAdmissionRun($this->dependencies(), array($this->hash('A')), 1);
		$scheduleIndex = false;
		$eraseIndex = false;
		foreach(rXMLRPCRequest::$commandCalls as $index => $commands)
			foreach($commands as $command)
			{
				if(rXMLRPCRequest::scheduleFamily($command->command) === 'schedule'
					&& $scheduleIndex === false)
					$scheduleIndex = $index;
				if($command->command === 'd.erase' && $eraseIndex === false)
					$eraseIndex = $index;
			}
		$this->assertTrue($scheduleIndex !== false,
			'the producer registers the repeating drain schedule');
		$this->assertTrue($eraseIndex !== false,
			'and an acknowledged generation really reaches d.erase');
		$this->assertTrue($scheduleIndex !== false && $eraseIndex !== false
			&& $scheduleIndex < $eraseIndex,
			'the schedule registration precedes every d.erase');
		$this->assertEquals(array('schedule:0'), $order,
			'nothing is staged before the arm returns successfully');
	}

	public function testScheduleFamiliesUseMappedLogicalNamesOnly()
	{
		$this->reset();
		$invariant = 'both scheduling families go through the mapped logical'
			.' names schedule and schedule_remove, never schedule2 or'
			.' schedule_remove2, which stock rTorrent 0.16 does not define';
		// The LOGICAL key has to reach the COMMAND CONSTRUCTOR, not merely
		// appear somewhere in the file. rXMLRPCCommand::__construct() calls
		// rTorrentSettings::patchDeprecatedCommand($this, $cmd) before it
		// appends a single argument, and that helper looks the alias up by the
		// name it was HANDED. Given the already-resolved name that
		// getCmd('schedule') returns, it finds no alias on rTorrent 0.9.x,
		// never adds the mandatory leading empty target, and the daemon answers
		// "Unsupported target type found." -- measured on a real 0.9.8 daemon,
		// alongside 0.16.21's "invalid parameters: invalid target" for the same
		// omission. So the constructor argument is what these pin, which is
		// strictly stronger than pinning that getCmd('schedule') occurs at all.
		$this->sourceHas('removewithdata.php', "new rXMLRPCCommand('schedule',",
			'the drain arm hands the mapped logical schedule name to the constructor');
		$this->sourceHas('removewithdata.php', "new rXMLRPCCommand('schedule_remove'",
			'retirement hands the mapped logical schedule_remove name to the constructor');
		$this->sourceLacks('removewithdata.php', "rXMLRPCCommand(getCmd('schedule')",
			'and never the resolved spelling, which loses the empty target on 0.9.x');
		$this->sourceLacks('removewithdata.php', "rXMLRPCCommand(getCmd('schedule_remove')",
			'nor the resolved removal spelling, for exactly the same reason');
		foreach(array('removewithdata.php', 'update.php', 'init.php', 'pending.php') as $file)
		{
			$this->sourceLacks($file, 'schedule2',
				'no deprecated schedule2 alias in '.$file);
			$this->sourceLacks($file, 'schedule_remove2',
				'no deprecated schedule_remove2 alias in '.$file);
		}
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()',
			'erasedataRetirementRun()'), $invariant))
			return;
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0));
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true, 'val' => array(0));
		erasedataRemovalAdmissionRun($this->dependencies(), array($this->hash('A')), 1);
		$this->retire();
		$commands = array();
		foreach(rXMLRPCRequest::$scheduledCommands as $record)
			$commands[$record['command']] = true;
		$this->assertTrue(isset($commands['schedule']),
			'the observed registration command is exactly "schedule"');
		$this->assertTrue(!isset($commands['schedule2']) && !isset($commands['schedule_remove2']),
			'no deprecated alias is ever emitted');
	}

	public function testTheDrainScheduleKeyIsPerUserAndNeverCollidesWithTheCollector()
	{
		$this->reset();
		$invariant = 'the schedule key is exactly erasedata-drain<User> with the'
			.' same user handed to the child, and never collides with the'
			.' ordinary erasedata<User> key that done.php removes';
		if(!$this->requireApi(array('erasedataDrainScheduleKey()'), $invariant))
			return;
		$this->assertEquals('erasedata-drainrutorrent', erasedataDrainScheduleKey('rutorrent'),
			'the key is the literal prefix followed by the user');
		// The single-user install. User::getUser() is '' there, so '' is a real
		// user with a real key of its own, and it is still not the collector's.
		$this->assertEquals('erasedata-drain', erasedataDrainScheduleKey(''),
			'the empty user of a single-user install has the bare prefix as its key');
		$this->assertTrue(erasedataDrainScheduleKey('') !== 'erasedata'.'',
			'which is still not the periodic collector key done.php removes');
		$this->assertTrue(erasedataDrainScheduleKey(null) === false,
			'a missing user has no drain schedule key');
		$this->assertTrue(erasedataDrainScheduleKey(array('rutorrent')) === false,
			'a non-string user has no drain schedule key');
		$this->assertTrue(erasedataDrainScheduleKey("ruto\0rrent") === false,
			'a user carrying a NUL byte has no drain schedule key');
		$this->assertTrue(erasedataDrainScheduleKey(str_repeat('u', 256)) === false,
			'an oversized user has no drain schedule key');
		$this->assertTrue(erasedataDrainScheduleKey('rutorrent') !== 'erasedata'.'rutorrent',
			'the drain key is not the periodic collector key');
		$doneSource = @file_get_contents($this->repositoryRoot().'/plugins/erasedata/done.php');
		$this->assertTrue(is_string($doneSource) && $doneSource !== '',
			'done.php is readable for inspection');
		$this->assertTrue(is_string($doneSource)
			&& strpos($doneSource, 'getRemoveScheduleCommand("erasedata")') !== false,
			'done.php still removes only the periodic collector key');
		$this->assertTrue(is_string($doneSource)
			&& strpos($doneSource, 'erasedata-drain') === false,
			'done.php never removes the drain key');
	}

	public function testScheduleRegistrationFaultOrFalseNeverStagesOrErases()
	{
		$this->reset();
		$invariant = 'a schedule RPC that faults or returns false leaves the'
			.' state unarmed and stages and erases nothing';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()',
			'erasedataReadDrainState()'), $invariant))
			return;
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		$cases = array(
			'a fault' => array('ok' => true, 'fault' => true, 'faultString' => 'refused'),
			'a false return' => array('ok' => false, 'val' => array()),
		);
		foreach($cases as $label => $response)
		{
			$this->reset();
			$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
			$this->eraseOk();
			rXMLRPCRequest::$responses['schedule'] = $response;
			$outcome = erasedataRemovalAdmissionRun($this->dependencies(),
				array($this->hash('A')), 1);
			$this->assertTrue($outcome === false,
				'the producer fails closed when the arm answers with '.$label);
			$this->assertEquals(0, count(rXMLRPCRequest::$erased),
				'nothing is erased when the arm answers with '.$label);
			$state = erasedataReadDrainState($this->queuePath());
			$this->assertTrue($state === false
				|| (isset($state['phase']) && $state['phase'] !== 'armed'),
				'the durable phase never reaches armed on '.$label);
			$this->assertEquals(array(), glob($this->queuePath().'/*.tmp'),
				'no staging survives '.$label);
		}
	}

	public function testTheProducerNeverWritesTheAcknowledgement()
	{
		$this->reset();
		$invariant = 'only a really started guarded child raises acknowledged;'
			.' registration acceptance is not acknowledgement and the producer'
			.' never writes it itself';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()',
			'erasedataReadDrainState()', 'erasedataGenerationIsValid()'), $invariant))
			return;
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0));
		erasedataRemovalAdmissionRun(
			$this->dependencies(array('ackTimeout' => 0.15)), array($this->hash('A')), 1);
		// A do-nothing producer must not satisfy this case: the arm has to have
		// really happened and the durable state has to really name a generation
		// before "acknowledged is still behind it" means anything at all.
		$this->assertEquals(1, count($this->scheduleRecords('schedule')),
			'the producer really registered the repeating drain schedule');
		$state = erasedataReadDrainState($this->queuePath());
		$this->assertTrue(is_array($state) && isset($state['generation'])
			&& erasedataGenerationIsValid($state['generation']),
			'and durably bound a real generation to it');
		$this->assertTrue(is_array($state) && array_key_exists('acknowledged', $state)
			&& erasedataGenerationIsValid($state['acknowledged']),
			'the durable state carries a readable acknowledged generation of its own');
		$this->assertTrue(is_array($state) && isset($state['generation'])
			&& array_key_exists('acknowledged', $state)
			&& $state['acknowledged'] !== $state['generation'],
			'an accepted registration with no child leaves acknowledged behind the generation');
		$this->assertEquals(0, count(rXMLRPCRequest::$erased),
			'and nothing is erased on the strength of the registration alone');
	}

	public function testAcceptedScheduleWithNoChildRetainsTheTorrentAndRollsBackOnlyItsOwn()
	{
		$this->reset();
		$invariant = 'an accepted schedule that never produces a child times out'
			.' bounded, retains the torrent, rolls back only its own prepared'
			.' staging identity, leaves a later generation untouched and writes'
			.' exactly one unconditional drain-no-ack diagnostic';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()'), $invariant))
			return;
		$queue = $this->queuePath();
		$foreign = $queue.'/'.$this->hash('Z').'.ffffffffffffffff.42.tmp';
		@file_put_contents($foreign, "a later generation's staging\n");
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0));
		$began = microtime(true);
		$outcome = erasedataRemovalAdmissionRun(
			$this->dependencies(array('ackTimeout' => 0.25)), array($this->hash('A')), 1);
		$took = microtime(true) - $began;
		$this->assertTrue($took < 5.0,
			'the wait for an acknowledgement is bounded: took '.round($took, 2).'s');
		$this->assertTrue($outcome === false, 'the producer reports failure');
		$this->assertEquals(0, count(rXMLRPCRequest::$erased),
			'the torrent is retained');
		$this->assertTrue(is_file($foreign),
			'a later generation staging file is never rolled back by this producer');
		$own = array();
		foreach(glob($queue.'/*.tmp') as $file)
			if($file !== $foreign)
				$own[] = basename($file);
		$this->assertEquals(array(), $own,
			'only this generation\'s own prepared staging is rolled back');
		$diagnostics = 0;
		foreach(FileUtil::$log as $line)
			if(strpos($line, 'drain-no-ack') !== false)
				$diagnostics++;
		$this->assertEquals(1, $diagnostics,
			'exactly one unconditional drain-no-ack diagnostic is written');
	}


	public function testHeadlessNoAckRearmsAnArmedQueueAfterVolatileScheduleLoss()
	{
		$this->reset();
		$queue = $this->queuePath();
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0));
		rXMLRPCRequest::$responses['schedule.if_absent'] = array('ok' => true, 'val' => array(0));
		$dependencies = $this->dependencies(array('ackTimeout' => 0.08, 'ackPoll' => 0.01));
		$this->assertTrue(erasedataRemovalAdmissionRun($dependencies,
			array($this->hash('A')), 1) === false,
			'the first unanswered admission retains its torrent and leaves an armed obligation');
		$before = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($before) && $before['phase'] === 'armed',
			'the persisted phase survives a daemon restart while its schedule does not');
		// rTorrent loses its volatile schedule on restart. This headless caller
		// never loads erasedata/init.php, so only its no-ack path can restore it.
		rXMLRPCRequest::$scheduledCommands = array();
		$this->assertTrue(erasedataRemovalAdmissionRun($dependencies,
			array($this->hash('B')), 1) === false,
			'a second unanswered admission remains safe and retryable');
		$records = $this->scheduleRecords('schedule.if_absent');
		$this->assertEquals(1, count($records),
			'the no-ack path reuses conservative queue rearm after the producer releases its locks');
		$this->assertEquals('erasedata-drainrutorrent',
			count($records) ? $records[0]['key'] : null,
			'headless recovery registers the exact per-user key');
		$this->assertEquals(0, count($this->scheduleRecords('schedule')),
			'a missed acknowledgement never sends the command that resets a live countdown');
		// Simulate an unexpected rejection on the modern fixture. The legacy
		// marker protocol is exercised in its own version-specific cases.
		rXMLRPCRequest::$responses['schedule.if_absent'] = array('ok' => true,
			'fault' => true, 'faultString' => 'method unavailable');
		rXMLRPCRequest::$scheduledCommands = array();
		$this->assertTrue(erasedataRemovalAdmissionRun($dependencies,
			array($this->hash('C')), 1) === false,
			'a refused idempotent command retains the torrent');
		$this->assertEquals(0, count($this->scheduleRecords('schedule')),
			'no compatibility fallback may reset a possibly live countdown');
		$visible = false;
		foreach(FileUtil::$log as $line)
			if(strpos($line, 'rearm-refused') !== false
				&& strpos($line, 'headless-recovery-refused') !== false)
				$visible = true;
		$this->assertTrue($visible,
			'the older daemon refusal is classified and visible');
		$this->assertEquals(0, count(rXMLRPCRequest::$erased),
			'none of the unanswered admissions erases a torrent');
	}


	public function testHeadlessNoAckRearmsLegacyDaemonOnlyAfterMarkerLoss()
	{
		$this->reset();
		$queue = $this->queuePath();
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0));
		list($marker, $absent) = $this->scriptLegacyDaemonMarker();
		$dependencies = $this->dependencies(array('ackTimeout' => 0.08, 'ackPoll' => 0.01));
		$this->assertTrue(erasedataRemovalAdmissionRun($dependencies,
			array($this->hash('A')), 1) === false,
			'the first unanswered admission retains its torrent');
		$first = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($first) && $first['phase'] === 'armed'
			&& isset($first['legacy_marker']) && $first['legacy_marker'] === true,
			'the initial arm durably records that its volatile marker exists');
		rXMLRPCRequest::$scheduledCommands = array();
		$this->assertTrue(erasedataRemovalAdmissionRun($dependencies,
			array($this->hash('B')), 1) === false,
			'a busy live scheduler still retains the second torrent');
		$this->assertEquals(0, count($this->scheduleRecords('schedule')),
			'a live marker prevents any replacement of its schedule key');
		$stillArmed = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($stillArmed) && $stillArmed['phase'] === 'armed'
			&& isset($stillArmed['legacy_marker']),
			'the live schedule remains durably armed');

		// A daemon restart loses both volatile objects. The durable state
		// remembers that this marker protocol previously armed the schedule.
		rXMLRPCRequest::$responses[$marker] = $absent;
		$generationAtRearm = null;
		rXMLRPCRequest::$responses['schedule']['callback'] = function($commands)
			use ($queue, &$generationAtRearm)
		{
			$state = erasedataReadDrainState($queue);
			$generationAtRearm = is_array($state) ? $state['generation'] : null;
		};
		rXMLRPCRequest::$scheduledCommands = array();
		$this->assertTrue(erasedataRemovalAdmissionRun($dependencies,
			array($this->hash('C')), 1) === false,
			'the first post-restart attempt leaves its torrent retryable');
		$this->assertEquals(1, count($this->scheduleRecords('schedule')),
			'the absent daemon marker permits exactly one legacy rearm');
		$this->assertEquals(0, count($this->scheduleRecords('schedule.if_absent')),
			'0.9.8 is not sent its unsupported if-absent command');
		$rearmed = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($rearmed) && $rearmed['phase'] === 'armed'
			&& isset($rearmed['legacy_marker'])
			&& $rearmed['generation'] === $generationAtRearm,
			'rearm restores the witness without changing generation or phase');

		rXMLRPCRequest::$responses[$marker] = $absent;
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'fault' => true,
			'faultString' => 'scheduler refused');
		FileUtil::$log = array();
		$this->assertTrue(erasedataRemovalAdmissionRun($dependencies,
			array($this->hash('D')), 1) === false,
			'a refused legacy schedule leaves the removal retryable');
		$failed = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($failed) && $failed['phase'] === 'armed'
			&& !isset($failed['legacy_marker']),
			'a failed rearm keeps obligations but revokes the unverified witness');
		$this->assertTrue(strpos(implode("\n", FileUtil::$log), 'rearm-refused') !== false,
			'the scheduler refusal is classified');
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true,
			'val' => array(0));
		rXMLRPCRequest::$scheduledCommands = array();
		$this->assertTrue(erasedataRemovalAdmissionRun($dependencies,
			array($this->hash('E')), 1) === false,
			'an unverified old state remains retryable');
		$recovery = $this->scheduleRecords('schedule');
		$this->assertEquals('erasedata-rescue-drainrutorrent',
			count($recovery) ? $recovery[0]['key'] : null,
			'an uncertain original key is never replaced by headless recovery');
		$bridged = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($bridged) && isset($bridged['legacy_rescue'])
			&& isset($bridged['legacy_marker']),
			'the separate rescue schedule and witness are durable');
		$this->assertEquals(0, count(rXMLRPCRequest::$erased),
			'no unanswered admission erases a torrent');
	}



	public function testSchedulerVersionBoundaryUsesLegacyMarkerBefore01621()
	{
		foreach(array('0.16.8' => true, '0.16.20' => true,
			'0.16.21' => false) as $version => $legacy)
		{
			$this->reset();
			$queue = $this->queuePath();
			$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
			$this->eraseOk();
			list($marker, $absent) = $this->scriptLegacyDaemonMarker($version);
			rXMLRPCRequest::$responses['schedule'] = array('ok' => true,
				'val' => array(0));
			rXMLRPCRequest::$responses['schedule.if_absent'] = array('ok' => true,
				'val' => array(0));
			$this->assertEquals($legacy, erasedataLegacyDrainVersion(),
				$version.' selects the measured scheduler capability boundary');
			$dependencies = $this->dependencies(array('ackTimeout' => 0.08,
				'ackPoll' => 0.01));
			$this->assertTrue(erasedataRemovalAdmissionRun($dependencies,
				array($this->hash('A')), 1) === false,
				$version.' first unanswered admission remains retryable');
			$armed = erasedataReadDrainState($queue);
			$this->assertTrue(is_array($armed) && $armed['phase'] === 'armed'
				&& isset($armed['legacy_marker']) === $legacy,
				$version.' publishes only the witness its schedule supports');
			if($legacy)
				rXMLRPCRequest::$responses[$marker] = $absent;
			rXMLRPCRequest::$scheduledCommands = array();
			$this->assertTrue(erasedataRemovalAdmissionRun($dependencies,
				array($this->hash('B')), 1) === false,
				$version.' post-restart admission remains retryable');
			$this->assertEquals($legacy ? 1 : 0,
				count($this->scheduleRecords('schedule')),
				$version.' uses legacy schedule only after proven marker loss');
			$this->assertEquals($legacy ? 0 : 1,
				count($this->scheduleRecords('schedule.if_absent')),
				$version.' uses the supported scheduler command');
			$this->assertEquals(0, count(rXMLRPCRequest::$erased),
				$version.' never erases an unanswered torrent');
		}
	}

	public function testUnknownDaemonVersionRefusesInitialArmUntilRetry()
	{
		$this->reset();
		$queue = $this->queuePath();
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		$this->scriptLegacyDaemonMarker();
		rXMLRPCRequest::$responses['system.client_version'] = array('ok' => false);
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0));
		$dependencies = $this->dependencies(array('ackTimeout' => 0.08, 'ackPoll' => 0.01));
		$this->assertTrue(erasedataRemovalAdmissionRun($dependencies,
			array($this->hash('A')), 1) === false,
			'a transient version failure refuses the initial admission');
		$this->assertEquals(0, count($this->scheduleRecords('schedule')),
			'unknown daemon version sends no unverified schedule');
		$failed = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($failed) && $failed['phase'] !== 'armed'
			&& !isset($failed['legacy_marker']),
			'failed version probe does not publish an armed witness');
		$this->assertTrue(strpos(implode("\n", FileUtil::$log),
			'arm-version-unknown') !== false,
			'the version refusal is classified');
		rXMLRPCRequest::$responses['system.client_version'] = array('ok' => true,
			'val' => array('0.9.8'));
		$this->assertTrue(erasedataRemovalAdmissionRun($dependencies,
			array($this->hash('A')), 1) === false,
			'the same torrent can retry after the version probe recovers');
		$this->assertEquals(1, count($this->scheduleRecords('schedule')),
			'the retry sends the legacy schedule exactly once');
		$armed = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($armed) && $armed['phase'] === 'armed'
			&& isset($armed['legacy_marker']),
			'the retry publishes the verified legacy arm');
		$this->assertEquals(0, count(rXMLRPCRequest::$erased),
			'no torrent is erased without acknowledgement');
	}

	public function testUnknownDaemonVersionRefusesHeadlessRearmUntilRetry()
	{
		$this->reset();
		$queue = $this->queuePath();
		$state = erasedataDefaultDrainState();
		$state['user'] = 'rutorrent';
		$state['generation'] = '0000000000000001';
		$state['phase'] = 'armed';
		$state['legacy_marker'] = true;
		$this->assertTrue(erasedataWriteDrainState($queue, $state),
			'a verified obligation is durable');
		$this->assertTrue(erasedataQueueRequest($queue, $this->hash('A'), 1,
			$state['generation']), 'a pending obligation remains');
		$this->scriptLegacyDaemonMarker();
		rXMLRPCRequest::$responses['system.client_version'] = array('ok' => false);
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0));
		$dependencies = $this->dependencies(array('context' => 'headless'));
		$this->assertTrue(erasedataRearmDrainScheduleRun($dependencies) === false,
			'headless recovery refuses an unknown daemon version');
		$this->assertEquals(0, count($this->scheduleRecords('schedule'))
			+ count($this->scheduleRecords('schedule.if_absent')),
			'no scheduler command is sent before identifying the daemon');
		$failed = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($failed) && $failed['phase'] === 'armed'
			&& isset($failed['legacy_marker']),
			'the obligation and its prior witness remain untouched');
		$this->assertTrue(strpos(implode("\n", FileUtil::$log),
			'rearm-version-unknown') !== false,
			'the rearm version refusal is classified');
		rXMLRPCRequest::$responses['system.client_version'] = array('ok' => true,
			'val' => array('0.9.8'));
		$this->assertTrue(erasedataRearmDrainScheduleRun($dependencies),
			'headless recovery retries successfully after the probe recovers');
		$this->assertEquals(1, count($this->scheduleRecords('schedule')),
			'the verified retry restores one schedule');
		$rearmed = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($rearmed) && $rearmed['phase'] === 'armed'
			&& isset($rearmed['legacy_marker']),
			'retry retains the durable obligation and witness');
	}

	public function testLegacyPreMarkerStateUsesDistinctRescueSchedule()
	{
		$this->reset();
		$queue = $this->queuePath();
		$state = erasedataDefaultDrainState();
		$state['user'] = 'rutorrent';
		$state['generation'] = '0000000000000001';
		$state['phase'] = 'armed';
		$this->assertTrue(erasedataWriteDrainState($queue, $state),
			'a pre-marker seven-field state remains readable');
		$this->assertTrue(erasedataQueueRequest($queue, $this->hash('A'), 1,
			$state['generation']), 'a pending obligation is present');
		$this->scriptLegacyDaemonMarker();
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0));
		$dependencies = $this->dependencies(array('context' => 'headless'));
		$this->assertTrue(erasedataRearmDrainScheduleRun($dependencies),
			'headless recovery bridges an old queue without replacing its key');
		$records = $this->scheduleRecords('schedule');
		$this->assertEquals(1, count($records),
			'one rescue schedule is registered');
		$this->assertEquals('erasedata-rescue-drainrutorrent',
			count($records) ? $records[0]['key'] : null,
			'the original schedule key is left untouched');
		$verified = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($verified) && $verified['phase'] === 'armed'
			&& isset($verified['legacy_marker'])
			&& isset($verified['legacy_rescue'])
			&& $verified['generation'] === $state['generation'],
			'the rescue intent and verified witness are durable');
		rXMLRPCRequest::$scheduledCommands = array();
		$this->assertTrue(erasedataRearmDrainScheduleRun($dependencies),
			'a live witness is accepted on the next headless pass');
		$this->assertEquals(0, count($this->scheduleRecords('schedule')),
			'neither scheduler key is replaced while the daemon is live');
	}

	public function testLegacyRescueIntentRetiresBothSchedulerKeys()
	{
		$this->reset();
		$queue = $this->queuePath();
		$state = erasedataDefaultDrainState();
		$state['user'] = 'rutorrent';
		$state['generation'] = '0000000000000001';
		$state['acknowledged'] = $state['generation'];
		$state['phase'] = 'armed';
		$state['legacy_marker'] = true;
		$state['legacy_rescue'] = true;
		$this->assertTrue(erasedataWriteDrainState($queue, $state),
			'a bridge intent survives until retirement');
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true,
			'val' => array(0));
		$this->assertTrue($this->retire(),
			'an empty bridged queue retires both scheduler keys');
		$removed = array();
		foreach($this->scheduleRecords('schedule_remove') as $record)
			$removed[] = $record['key'];
		$this->assertEquals(array('erasedata-drainrutorrent',
			'erasedata-rescue-drainrutorrent'), $removed,
			'the original key is removed before the rescue tick that can retry');
	}

	public function testLegacyRescueSurvivesOriginalKeyRemovalFailure()
	{
		$this->reset();
		$queue = $this->queuePath();
		$state = erasedataDefaultDrainState();
		$state['user'] = 'rutorrent';
		$state['generation'] = '0000000000000001';
		$state['acknowledged'] = $state['generation'];
		$state['phase'] = 'armed';
		$state['legacy_marker'] = true;
		$state['legacy_rescue'] = true;
		$this->assertTrue(erasedataWriteDrainState($queue, $state),
			'a bridged queue is ready for retirement');
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true,
			'fault' => true, 'faultString' => 'transient refusal');
		$this->assertTrue(!$this->retire(),
			'a failed original removal defers the transaction');
		$attempts = $this->scheduleRecords('schedule_remove');
		$this->assertEquals('erasedata-drainrutorrent',
			count($attempts) ? $attempts[0]['key'] : null,
			'the rescue schedule is retained to retry original removal');
		$this->assertEquals(1, count($attempts),
			'no rescue removal follows the original failure');
		$after = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($after) && isset($after['legacy_rescue']),
			'the durable rescue intent remains for a later retry');
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true,
			'val' => array(0));
		$this->assertTrue($this->retire(), 'a later tick finishes both removals');
	}

	public function testLegacyRescueRemovalFailureRetainsIntentForRetry()
	{
		$this->reset();
		$queue = $this->queuePath();
		$state = erasedataDefaultDrainState();
		$state['user'] = 'rutorrent';
		$state['generation'] = '0000000000000001';
		$state['acknowledged'] = $state['generation'];
		$state['phase'] = 'armed';
		$state['legacy_marker'] = true;
		$state['legacy_rescue'] = true;
		$this->assertTrue(erasedataWriteDrainState($queue, $state),
			'a bridged empty queue is ready for retirement');
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true,
			'val' => array(0), 'callback' => function() {
				rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true,
					'fault' => true, 'faultString' => 'transient rescue refusal');
			});
		$notes = array();
		$this->assertTrue(!erasedataRetirementRun($this->dependencies(), $notes),
			'a failed rescue removal leaves the transaction retryable');
		$this->assertEquals('retire-rescue-remove-refused',
			count($notes) ? $notes[0][0] : null,
			'the exact rescue failure is classified');
		$after = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($after) && isset($after['legacy_rescue']),
			'durable rescue intent survives the failed RPC');
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true,
			'val' => array(0));
		$this->assertTrue($this->retire(),
			'a later pass completes the two-key retirement');
	}

	public function testLegacyRescueIntentCrashRetriesThenRestartRetiresMissingKey()
	{
		$this->reset();
		$queue = $this->queuePath();
		$state = erasedataDefaultDrainState();
		$state['user'] = 'rutorrent';
		$state['generation'] = '0000000000000001';
		$state['phase'] = 'armed';
		$state['legacy_rescue'] = true;
		$this->assertTrue(erasedataWriteDrainState($queue, $state),
			'a rescue intent may precede schedule acceptance');
		$this->assertTrue(erasedataQueueRequest($queue, $this->hash('A'), 1,
			$state['generation']), 'the obligation remains');
		list($marker, $absent) = $this->scriptLegacyDaemonMarker();
		rXMLRPCRequest::$responses[$marker] = array('ok' => true,
			'val' => array(1));
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true,
			'val' => array(0));
		$dependencies = $this->dependencies(array('context' => 'headless'));
		$this->assertTrue(erasedataRearmDrainScheduleRun($dependencies),
			'a retry converges after interruption between intent and schedule RPC');
		$retry = $this->scheduleRecords('schedule');
		$this->assertEquals('erasedata-rescue-drainrutorrent',
			count($retry) ? $retry[0]['key'] : null,
			'only the separate rescue key is retried');
		$verified = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($verified)
			&& isset($verified['legacy_rescue']) && isset($verified['legacy_marker']),
			'the accepted retry records a verified marker witness');
		rXMLRPCRequest::$responses[$marker] = $absent;
		rXMLRPCRequest::$scheduledCommands = array();
		$this->assertTrue(erasedataRearmDrainScheduleRun($dependencies),
			'marker loss after durable rescue intent proves a daemon restart');
		$scheduled = $this->scheduleRecords('schedule');
		$this->assertEquals('erasedata-drainrutorrent',
			count($scheduled) ? $scheduled[0]['key'] : null,
			'only the original key is restored on the new daemon');
		@unlink(erasedataPendingMarkerPath($queue, $this->hash('A'),
			$state['generation']));
		$settled = erasedataReadDrainState($queue);
		$settled['acknowledged'] = $settled['generation'];
		$this->assertTrue(erasedataWriteDrainState($queue, $settled),
			'the simulated new daemon acknowledged and emptied the queue');
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true,
			'val' => array(0));
		rXMLRPCRequest::$scheduledCommands = array();
		$this->assertTrue($this->retire(),
			'a post-restart missing rescue key does not block retirement');
		$removed = array();
		foreach($this->scheduleRecords('schedule_remove') as $record)
			$removed[] = $record['key'];
		$this->assertEquals(array('erasedata-drainrutorrent',
			'erasedata-rescue-drainrutorrent'), $removed,
			'both keys are removed even though the rescue key vanished on restart');
	}

	public function testLegacyRearmRejectsRestartBetweenMarkerAndSchedule()
	{
		$this->reset();
		$queue = $this->queuePath();
		$state = erasedataDefaultDrainState();
		$state['user'] = 'rutorrent';
		$state['generation'] = '0000000000000001';
		$state['phase'] = 'armed';
		$state['legacy_marker'] = true;
		$this->assertTrue(erasedataWriteDrainState($queue, $state),
			'the prior daemon left a verified arm');
		$this->assertTrue(erasedataQueueRequest($queue, $this->hash('A'), 1,
			$state['generation']), 'an obligation survived its restart');
		list($marker, $absent) = $this->scriptLegacyDaemonMarker();
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true,
			'val' => array(0), 'callback' => function($commands) use ($marker, $absent)
			{
				// A second daemon restart lands after the schedule RPC accepted.
				rXMLRPCRequest::$responses[$marker] = $absent;
			});
		$dependencies = $this->dependencies(array('context' => 'headless'));
		$this->assertTrue(erasedataRearmDrainScheduleRun($dependencies) === false,
			'a lost marker after schedule acceptance prevents a verified arm');
		$after = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($after) && $after['phase'] === 'armed'
			&& !isset($after['legacy_marker']),
			'the durable obligation remains, without a false daemon witness');
		$this->assertTrue(strpos(implode("\n", FileUtil::$log),
			'rearm-legacy-marker') !== false,
			'the uncertain rearm is classified');
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true,
			'val' => array(0));
		rXMLRPCRequest::$scheduledCommands = array();
		$this->assertTrue(erasedataRearmDrainScheduleRun($dependencies),
			'a later headless pass uses an independent rescue key');
		$recovery = $this->scheduleRecords('schedule');
		$this->assertEquals('erasedata-rescue-drainrutorrent',
			count($recovery) ? $recovery[0]['key'] : null,
			'it cannot blindly replace a possibly live original schedule');
	}

	public function testAfterTheAckTheProducerRevalidatesTheCompletePreparedBinding()
	{
		$this->reset();
		$invariant = 'after the acknowledgement the producer re-takes hash then'
			.' state locks and revalidates generation, phase, sorted hashes,'
			.' force, cardinality and every staging path and dev/ino before'
			.' writing erase-started; any mismatch is a hard no-erase';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()',
			'erasedataReadDrainState()', 'erasedataWriteDrainState()'), $invariant))
			return;
		$queue = $this->queuePath();
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		// The acknowledgement is played at the registration, as everywhere else
		// in this file. The SWAP cannot be played there too: invariant 4 puts
		// the arm before the first staging file exists, so a swap driven from
		// the registration callback would find an empty queue and prove
		// nothing -- which is exactly what this case used to do.
		//
		// The path collection of the SECOND hash is the one moment that is
		// after a staging file has been published and before the binding is
		// revalidated, so the swap is driven from there.
		$this->acknowledgeOnRegistration($queue);
		$hashes = array($this->hash('A'), $this->hash('B'));
		$swapped = false;
		$collected = 0;
		rXMLRPCRequest::$responses['d.get_base_path'] = array(
			'ok' => true, 'val' => array('/d/name', 1, '/d/name/a.bin'),
			'callback' => function($commands) use ($queue, &$swapped, &$collected)
			{
				if(++$collected < 2)
					return;
				foreach(glob($queue.'/*.tmp') as $file)
				{
					@unlink($file);
					@file_put_contents($file, "a different object at the same name\n");
					$swapped = true;
				}
			});
		$outcome = erasedataRemovalAdmissionRun(
			$this->dependencies(array('ackTimeout' => 1.0)), $hashes, 1);
		$this->assertTrue($swapped, 'the acknowledgement really landed and the staging was swapped');
		$this->assertTrue($outcome === false,
			'a staging identity that no longer matches the journal is a hard no-erase');
		$this->assertEquals(0, count(rXMLRPCRequest::$erased),
			'and no d.erase is sent');
		$state = erasedataReadDrainState($queue);
		$stillPrepared = false;
		if(is_array($state) && isset($state['journal']) && is_array($state['journal']))
			foreach($state['journal'] as $entry)
				if(isset($entry['phase']) && $entry['phase'] === 'erase-started')
					$stillPrepared = true;
		$this->assertTrue(!$stillPrepared,
			'erase-started is never written for a binding that failed revalidation');
	}

	public function testStateWriteFailureRefusesBeforeArmAndRetainsAfterArm()
	{
		$this->reset();
		$invariant = 'an I/O failure on a durable state write is terminal for'
			.' the attempt: it refuses the obligation when admission was not yet'
			.' durable and retains it once it was';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()',
			'erasedataReadDrainState()'), $invariant))
			return;
		$queue = $this->queuePath();
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		// The state name is a directory, so no durable state write can land.
		@mkdir($queue.'/.drain-state', 0777, true);
		$outcome = erasedataRemovalAdmissionRun($this->dependencies(),
			array($this->hash('A')), 1);
		$this->assertTrue($outcome === false,
			'admission refuses when it cannot write its own durable state');
		$this->assertEquals(0, count(rXMLRPCRequest::$erased),
			'nothing is erased when the state write failed before the arm');
		$this->assertEquals(0, count($this->scheduleRecords('schedule')),
			'and no schedule is registered on the strength of an unwritten state');
		$diagnosed = false;
		foreach(FileUtil::$log as $line)
			if(strpos($line, 'state-write') !== false)
				$diagnosed = true;
		$this->assertTrue($diagnosed,
			'the failed durable state write is classified and reported');
	}

	public function testJournalCapacityExhaustionRefusesBeforeEraseAndNeverEvicts()
	{
		$this->reset();
		$invariant = 'journal capacity exhaustion refuses the new request before'
			.' any erase; it never evicts an active entry';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()',
			'erasedataReadDrainState()', 'erasedataWriteDrainState()',
			'erasedataGenerationIncrement()'), $invariant))
			return;
		$queue = $this->queuePath();
		$state = erasedataReadDrainState($queue);
		if(!is_array($state))
		{
			$this->assertTrue(false, $invariant.' [drain state unreadable]');
			return;
		}
		$generation = '0000000000000000';
		$journal = array();
		for($index = 0; $index < 4096; $index++)
		{
			$generation = erasedataGenerationIncrement($generation);
			if($generation === false)
				break;
			$journal[$generation] = array(
				'phase' => 'prepared',
				'force' => 1,
				'hashes' => array($this->hash('A')),
				'staging' => array($this->hash('A') => array(
					'path' => $queue.'/'.$this->hash('A').'.'.$generation.'.1.tmp',
					'dev' => 1, 'ino' => 1)),
			);
		}
		$state['journal'] = $journal;
		$state['generation'] = $generation;
		$state['phase'] = 'armed';
		$written = erasedataWriteDrainState($queue, $state);
		$this->assertTrue($written === false,
			'a journal beyond its capacity cap cannot be written at all');
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		$outcome = erasedataRemovalAdmissionRun($this->dependencies(),
			array($this->hash('B')), 1);
		$after = erasedataReadDrainState($queue);
		$survived = is_array($after) && isset($after['journal'])
			? count($after['journal']) : 0;
		$this->assertTrue($outcome === false || $survived > 0,
			'a refusal on capacity never silently discards the existing journal');
		$this->assertEquals(0, count(rXMLRPCRequest::$erased),
			'capacity exhaustion refuses before erase');
	}

	public function testAContinuousProducerStreamDoesNotReRegisterALiveSchedule()
	{
		$this->reset();
		$invariant = 'a continuous producer stream does not postpone the first'
			.' tick: an already-armed correct schedule is never re-registered';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()'), $invariant))
			return;
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0));
		$dependencies = $this->dependencies(array('ackTimeout' => 0.05));
		foreach(array('A', 'B', 'C', 'D', 'E') as $character)
			erasedataRemovalAdmissionRun($dependencies, array($this->hash($character)), 1);
		$registrations = 0;
		foreach($this->scheduleRecords('schedule') as $record)
			if(strpos((string)$record['key'], 'erasedata-drain') === 0)
				$registrations++;
		$this->assertEquals(1, $registrations,
			'five producers arm the drain schedule exactly once, not five times');
	}

	public function testPublicDoorsShareOneAdmissionApiAndExposeNoTestSeams()
	{
		$this->reset();
		$invariant = 'all three public doors go through the same admission API, and'
			.' the public production wrapper takes no injection parameters';
		$this->sourceHas('action.php', 'erasedataAdmitRemoval',
			'action.php calls the shared admission API');
		$this->sourceHas('erase.php', 'erasedataAdmitRemoval',
			'erase.php calls the shared admission API');
		$this->sourceLacks('action.php', 'erasedataRemoveWithData(',
			'action.php never calls the destructive producer directly');
		$this->sourceLacks('erase.php', 'erasedataRemoveWithData(',
			'erase.php never calls the destructive producer directly');
		foreach(array('action.php', 'erase.php', 'update.php', 'removewithdata.php',
			'pending.php', 'filesystem.php', 'manifest.php', 'collector.php',
			'init.php', 'conf.php') as $file)
		{
			$this->sourceLacks($file, 'getenv(',
				'no environment path override in '.$file);
			$this->sourceLacks($file, 'putenv(',
				'no environment path override written by '.$file);
		}
		foreach(array('erasedataSimulateNoDescriptorCapability', 'erasedataInjectedEraseCut',
			'erasedataAckTimeout', 'erasedataLastBatchOutcome', 'autoAcknowledgeDrain',
			'ERASEDATA_SETTINGS_PATH') as $seam)
			$this->sourceLacks('removewithdata.php', $seam,
				'no production test switch named '.$seam);
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()',
			'erasedataAdmitRemoval()'), $invariant))
			return;
		$public = new ReflectionFunction('erasedataRemoveWithData');
		$this->assertEquals(2, $public->getNumberOfParameters(),
			'erasedataRemoveWithData keeps exactly its two production parameters');
		$door = new ReflectionFunction('erasedataAdmitRemoval');
		$this->assertEquals(2, $door->getNumberOfParameters(),
			'the shared public door takes hashes and force and nothing else');
		$runner = new ReflectionFunction('erasedataRemovalAdmissionRun');
		$parameters = $runner->getParameters();
		$this->assertTrue(count($parameters) === 3
			&& $parameters[0]->getName() === 'dependencies',
			'the internal runner takes its dependencies explicitly, as its first argument');
	}

	// Invariant 4's durable `arming` phase, pinned where it can be observed.
	//
	// Nothing else in this file fails when a producer skips `arming` and jumps
	// straight to `armed`, or writes no state at all before the registration:
	// every other case reads the state through the production reader, which
	// answers with the disarmed default for a queue that has no state yet, so a
	// skipped arming write looks exactly like a queue nobody has armed. This
	// case reads the state FILE, with no production code between it and the
	// bytes, at the instant the registration RPC is made. Either the durable
	// arming write happened before it or this fails.
	public function testTheDurableArmingPhasePrecedesTheScheduleRegistration()
	{
		$this->reset();
		$invariant = 'a durable arming state carrying the new generation is'
			.' published before the repeating schedule is ever registered';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()',
			'erasedataReadDrainState()', 'erasedataGenerationIsValid()'), $invariant))
			return;
		$queue = $this->queuePath();
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		$observed = false;
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0),
			'callback' => function($commands) use ($queue, &$observed)
			{
				$raw = @file_get_contents($queue.'/.drain-state');
				$observed = is_string($raw) ? json_decode($raw, true) : false;
			});
		erasedataRemovalAdmissionRun($this->dependencies(array('ackTimeout' => 0.05)),
			array($this->hash('A')), 1);
		$this->assertTrue(is_array($observed),
			'a durable drain state file already exists when the registration is made');
		$this->assertTrue(is_array($observed) && isset($observed['phase'])
			&& $observed['phase'] === 'arming',
			'and it carries the arming phase, neither armed nor disarmed');
		$this->assertTrue(is_array($observed) && isset($observed['generation'])
			&& erasedataGenerationIsValid($observed['generation'])
			&& $observed['generation'] !== '0000000000000000',
			'and the new generation the arm is binding, not the zero generation');
		$this->assertTrue(is_array($observed) && isset($observed['user'])
			&& $observed['user'] === User::getUser(),
			'and the nonempty user the schedule key is built from');
		$after = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($after) && isset($after['phase'])
			&& $after['phase'] === 'armed',
			'and the arm is swapped to armed once the registration returns');
		$this->assertTrue(is_array($after) && is_array($observed)
			&& isset($after['generation'], $observed['generation'])
			&& $after['generation'] === $observed['generation'],
			'the compare-and-swap keeps the exact generation it armed under');
	}

	// The web door's own parsing, at the boundary where a browser body arrives.
	//
	// The upstream loop read $parts[1] after an unconstrained explode, so a body
	// carrying a bare "hash" raised an undefined-index notice before anything
	// could refuse it. error_reporting is -1 and display_errors is on in
	// php-test.ini, so the child's output is where such a notice would show.
	public function testMalformedFormEntriesAreRefusedWithoutNoticesOrSideEffects()
	{
		$this->reset();
		$hash = $this->hash();
		$door = __DIR__.'/../../../plugins/erasedata/action.php';
		$bodies = array(
			'a key with no value at all' => 'mode=removewithdata&hash&v=1',
			'an empty key' => 'mode=removewithdata&=orphan&hash='.$hash.'&v=1',
			'an empty entry' => 'mode=removewithdata&&hash='.$hash.'&v=1',
			'nothing but separators' => '&&&',
			'a body that is one bare key' => 'mode',
			'a trailing separator' => 'mode=removewithdata&hash='.$hash.'&v=1&',
		);
		foreach($bodies as $label => $body)
		{
			list($status, $output, $commands) = $this->runCopiedAction($door, $body, true);
			$this->assertEquals(0, $status,
				'the door exits normally on '.$label.': '.$output);
			$this->assertEquals(array(), $commands,
				'a malformed body is refused before any admission on '.$label);
			// The message deliberately never spells the signal out when the
			// case passes: tests/php-test.sh greps the WHOLE output of a file
			// for "Uncaught" and the PHP error words, so a passing assertion
			// that quoted one would mark this file failed for ever.
			$offending = '';
			foreach(array('Notice', 'Warning', 'Deprecated', 'Fatal error',
				'Parse error', 'Uncaught') as $signal)
				if(strpos($output, $signal) !== false)
					$offending = $signal.': '.trim(substr($output, 0, 200));
			$this->assertTrue($offending === '',
				'and refuses without emitting a PHP diagnostic of its own on '
					.$label.($offending === '' ? '' : ' -- '.$offending));
		}
		// Without this the assertions above are all satisfied by a door that
		// refuses everything it is ever handed.
		list($status, $output, $commands) = $this->runCopiedAction($door,
			'mode=removewithdata&hash='.$hash.'&v=1', true);
		$this->assertEquals(0, $status,
			'the door exits normally on a well-formed body: '.$output);
		$this->assertEquals(array('admit:1'), $commands,
			'a well-formed body enters the shared admission API with the integer force');
		// A multi-valued entry keeps everything after the first "=", rather
		// than silently losing it the way an unlimited explode did.
		list($status, $output, $commands) = $this->runCopiedAction($door,
			'mode=removewithdata&hash='.$hash.'&v=1=2', true);
		$this->assertEquals(array(), $commands,
			'a force carrying a second "=" is refused rather than truncated to a valid one');
	}

	// Dead code is not an implementation.
	//
	// Every primitive below has exactly one owner and, before this slice, no
	// production caller anywhere: the admission transaction is the caller they
	// were written for. A helper only the tests reach is unreachable code
	// however carefully it is written, so each is pinned to a shipped file
	// other than the one that declares it.
	public function testTheAdmissionTransactionHasProductionCallersForItsPrimitives()
	{
		$this->reset();
		$wired = array(
			'erasedataQueueRequest($' => 'removewithdata.php',
			'erasedataLockObligations($' => 'removewithdata.php',
			'erasedataUnlockObligations($' => 'removewithdata.php',
			'erasedataPendingMarkerPath($' => 'removewithdata.php',
			'erasedataWriteDurableFile($' => 'removewithdata.php',
			'erasedataGenerationIncrement($' => 'removewithdata.php',
			'erasedataGenerationCompare($' => 'removewithdata.php',
			'erasedataGenerationIsValid($' => 'removewithdata.php',
			'erasedataRemovalAdmissionRun(' => 'removewithdata.php',
			'erasedataPublishedGenerations($' => 'removewithdata.php',
			// The filesystem slice. Its primitives were the one part of this
			// package the gate above never covered, and canonicalMissingPath()
			// shipped with zero production callers and a header comment
			// claiming "every erasedata decision reads it" because of exactly
			// that gap. The needles are CALL spellings ('->name(' /
			// 'name($'), never the declaration, so a symbol whose only reader
			// is a test still fails here even when it is pinned to the file
			// that declares it: canonicalMissingPath() is read by
			// pathIdentity() and acquireDirectoryCapability() by
			// openDirectoryReference(), both in filesystem.php, and both of
			// those are in turn pinned to callers outside it.
			'->canonicalMissingPath(' => 'filesystem.php',
			'->pathIdentity(' => 'collector.php',
			'erasedataPathIdentity(' => 'removewithdata.php',
			'erasedataPathTouchesOwnedPaths(' => 'collector.php',
			'->entryIdentity(' => 'collector.php',
			'->targetIdentity(' => 'collector.php',
			'->acquireDirectoryCapability(' => 'filesystem.php',
			'erasedataWriteDurableFile($' => 'pending.php',
		);
		foreach($wired as $needle => $file)
		{
			$callers = $this->productionCallers($needle);
			$this->assertTrue(in_array($file, $callers, true),
				'a production caller of '.$needle.' exists in '.$file
					.' (found in: '.implode(', ', $callers).')');
		}
		$doors = $this->productionCallers('erasedataAdmitRemoval(');
		foreach(array('action.php', 'erase.php', '../httprpc/action.php') as $door)
			$this->assertTrue(in_array($door, $doors, true),
				$door.' enters the shared admission API (found in: '
					.implode(', ', $doors).')');
		// The marker codec is reachable only because the store that writes
		// through it is: a codec nothing records or reads with is dead however
		// many tests exercise it.
		$this->assertTrue(in_array('pending.php',
			$this->productionCallers('erasedataEncodePendingMarker(array('), true),
			'the marker encoder is reached by the store the admission records through');
	}

	// I6: this plugin owns the identity that authorises its own deletions.
	//
	// erasedataPathsOverlap() is the predicate behind every "may I delete this
	// payload?" decision the collector makes, and FALSE is the answer that
	// authorises the deletion. It used to ask XMLRPCPathResolver, a core class
	// shared with the standalone and session XMLRPC endpoints, which answers a
	// MISSING name by reconstructing its unresolved tail VERBATIM: realpath()
	// fails on every prefix that contains a missing component, so a '..' in the
	// tail survives into the "canonical" name. Two names that really are the
	// same object then compare as disjoint, and the collector deletes a file a
	// live download still owns.
	//
	// The whole hazard is reproduced below on real files, and the fix is
	// ownership: erasedataPathIdentity() -> ErasedataFilesystemOps::pathIdentity()
	// -> canonicalMissingPath(), which proves a missing name's location
	// component by component or refuses, and a refusal is read as overlap.
	public function testTheFilesystemSliceOwnsEveryIdentityDecision()
	{
		$this->reset();
		$invariant = 'the owned-path predicate that authorises a deletion reads'
			.' this plugin\'s own identity owner, and a missing name it cannot'
			.' canonicalise is overlap rather than permission to delete';
		if(!$this->requireApi(array('erasedataPathIdentity()',
			'erasedataPathsOverlap()', 'erasedataPathTouchesOwnedPaths()',
			'ErasedataFilesystemOps::pathIdentity',
			'ErasedataFilesystemOps::canonicalMissingPath'), $invariant))
			return;

		// The loop below quantifies over a hand-maintained list, so a plugin
		// file left off it would be silently exempt from the two assertions
		// inside. The non-empty check matters as much as the equality: glob()
		// answers array() for a mistyped root, and two empty sets compare equal.
		$shipped = array_map('basename',
			(array)glob($this->repositoryRoot().'/plugins/erasedata/*.php'));
		$listed = ErasedataProductionMirror::pluginFiles();
		sort($shipped, SORT_STRING);
		sort($listed, SORT_STRING);
		$this->assertTrue(count($shipped) > 0,
			'the shipped plugin directory really lists .php files');
		$this->assertEquals(json_encode($shipped), json_encode($listed),
			'the mirror enumerates every shipped plugin file, so a new one'
				.' cannot be exempt from the guard below');

		// (a) No erasedata production file depends on the excluded core owner
		// any more -- not by call, not by require.
		foreach(ErasedataProductionMirror::pluginFiles() as $file)
		{
			$bytes = $this->productionSource($file);
			$this->assertTrue(is_string($bytes) && $bytes !== '',
				'the shipped '.$file.' is readable for inspection');
			$this->assertTrue(is_string($bytes)
				&& strpos($bytes, 'XMLRPCPathResolver::') === false,
				$file.' calls no XMLRPCPathResolver method');
			$this->assertTrue(is_string($bytes)
				&& strpos($bytes, 'xmlrpc_path.php') === false,
				$file.' does not require the core resolver');
		}

		// (b) The hazard itself, on real files. The live download owns a name
		// carrying a '..' component; the obsolete manifest names the very same
		// object by its plain spelling. Lexical containment sees nothing, and
		// the missing name has no dev/ino to compare, so the ONLY thing between
		// this and deleting the live download's file is what identity answers
		// for the missing name.
		$lab = $this->dir.'/identity-owner';
		$this->assertTrue(@mkdir($lab.'/live', 0777, true), 'the lab needs a live directory');
		$secret = $lab.'/live/secret.bin';
		$this->assertTrue(file_put_contents($secret, 'payload the live download still owns') > 0,
			'and a file the live download still owns');
		$ownedSpelling = $lab.'/live/notyet/../secret.bin';
		$this->assertTrue(!file_exists($ownedSpelling),
			'the owned spelling does not resolve as a name: its parent is absent');
		$this->assertTrue(erasedataPathContains($ownedSpelling, $secret) === false
			&& erasedataPathContains($secret, $ownedSpelling) === false,
			'and lexical containment sees no relation between the two spellings');
		$this->assertTrue(erasedataPathIdentity($ownedSpelling) === false,
			'a missing name whose canonical location cannot be proven has no identity');
		$this->assertTrue(erasedataPathsOverlap($secret, $ownedSpelling) === true,
			'so the owned-path predicate answers OVERLAP and the payload is retained');
		// The base is deliberately elsewhere, so retention rests ENTIRELY on the
		// one owned file spelling and nothing lexical can satisfy it by accident.
		$this->assertTrue(erasedataPathTouchesOwnedPaths($secret,
			array('base' => $lab.'/unrelated', 'files' => array($ownedSpelling))) === true,
			'and the collector predicate built on it retains it too');
		$this->assertTrue(is_file($secret),
			'nothing in this case may have deleted the live download\'s file');

		// (c) It is not a blanket refusal: a clean missing name still gets a
		// canonical answer, and it is the RESOLVED one.
		$this->assertTrue(@mkdir($lab.'/real', 0777, true), 'a real directory');
		$this->assertTrue(@symlink($lab.'/real', $lab.'/alias') === true,
			'reachable through a directory symlink');
		$clean = erasedataPathIdentity($lab.'/alias/notyet.bin');
		$this->assertTrue(is_array($clean) && isset($clean['exists'], $clean['path'])
			&& $clean['exists'] === false,
			'a clean missing name is answered, not refused');
		$this->assertEquals(realpath($lab.'/real').'/notyet.bin',
			is_array($clean) && isset($clean['path']) ? $clean['path'] : null,
			'and the answer resolves the alias rather than repeating the spelling');
		$this->assertTrue(erasedataPathsOverlap($lab.'/real/notyet.bin',
			$lab.'/alias/notyet.bin') === true,
			'so two spellings of one missing name still overlap');
		$this->assertTrue(erasedataPathsOverlap($lab.'/real/notyet.bin',
			$lab.'/real/other.bin') === false,
			'while two genuinely different missing names do not, and stay deletable');

		// (d) An existing name is answered exactly as before: canonical path
		// plus both dev/ino pairs, so nothing that used to compare equal stops
		// comparing equal.
		$existing = erasedataPathIdentity($secret);
		$this->assertTrue(is_array($existing) && !empty($existing['exists'])
			&& isset($existing['path'], $existing['lstat']['dev'], $existing['lstat']['ino'],
				$existing['stat']['dev'], $existing['stat']['ino']),
			'an existing name carries its canonical path and both identity pairs');
		$this->assertEquals(realpath($secret),
			is_array($existing) && isset($existing['path']) ? $existing['path'] : null,
			'with the realpath spelling');
		$this->assertTrue(erasedataPathsOverlap($secret, $lab.'/alias/../real') === false,
			'and an unrelated existing name is still no overlap');
		$this->assertTrue(erasedataPathIdentity('relative/name') === false
			&& erasedataPathIdentity('') === false
			&& erasedataPathIdentity($secret."\0x") === false,
			'a relative, empty or NUL-carrying name has no identity at all');
	}

	// The journal cap is a refusal, and it sits at or below the queue's own
	// generation cap so the queue refuses first.
	//
	// testJournalCapacityExhaustionRefusesBeforeEraseAndNeverEvicts proves that
	// a journal beyond the cap cannot be written and that the refusal erases
	// nothing. It cannot show where the cap IS, because it never writes a state
	// that succeeds: an implementation whose cap was one record would satisfy
	// it just as well while refusing every real batch.
	public function testTheJournalCapIsARefusalAtTheQueueGenerationCap()
	{
		$this->reset();
		$invariant = 'the journal holds up to the queue generation cap and'
			.' refuses the record beyond it without evicting anything';
		if(!$this->requireApi(array('erasedataWriteDrainState()',
			'erasedataReadDrainState()', 'erasedataGenerationIncrement()'), $invariant))
			return;
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		$generation = '0000000000000000';
		$journal = array();
		for($index = 0; $index < ERASEDATA_PENDING_MAX_GENERATIONS; $index++)
		{
			$generation = erasedataGenerationIncrement($generation);
			if($generation === false)
				break;
			$journal[$generation] = array(
				'phase' => 'prepared', 'force' => 1, 'hashes' => array($hash),
				'staging' => array($hash => array(
					'path' => $queue.'/'.$hash.'.'.$generation.'.1.tmp',
					'dev' => 1, 'ino' => 1)));
		}
		$this->assertEquals(ERASEDATA_PENDING_MAX_GENERATIONS, count($journal),
			'the fixture really builds one record per queue generation');
		$state = array(
			'version' => 1, 'user' => 'rutorrent',
			'generation' => $generation, 'acknowledged' => '0000000000000000',
			'phase' => 'armed', 'journal' => $journal, 'diagnostics' => array());
		$this->assertTrue(erasedataWriteDrainState($queue, $state) === true,
			'a journal exactly at the cap is a state the producer can still write');
		$readBack = erasedataReadDrainState($queue);
		$this->assertEquals(count($journal),
			is_array($readBack) && isset($readBack['journal'])
				? count($readBack['journal']) : 0,
			'and reads back whole rather than truncated to fit');
		$beyond = erasedataGenerationIncrement($generation);
		$state['journal'][$beyond] = array(
			'phase' => 'prepared', 'force' => 1, 'hashes' => array($hash),
			'staging' => array($hash => array(
				'path' => $queue.'/'.$hash.'.'.$beyond.'.1.tmp',
				'dev' => 1, 'ino' => 1)));
		$state['generation'] = $beyond;
		$this->assertTrue(erasedataWriteDrainState($queue, $state) === false,
			'one record beyond the cap is refused rather than written after an eviction');
		$survivor = erasedataReadDrainState($queue);
		$this->assertEquals(count($journal),
			is_array($survivor) && isset($survivor['journal'])
				? count($survivor['journal']) : 0,
			'and the durable journal on disk is exactly what it was before the refusal');
	}

	// A member of F must not even reach the same schedule the accepted members
	// arm, and a batch that is nothing but F must make no durable mark at all.
	public function testAMixedBatchAdmitsOnlyTheExactSortedAcceptedSet()
	{
		$this->reset();
		$invariant = 'a mixed batch admits exactly A, sorted and generation'
			.' bound, and leaves every member of F entirely untouched';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()',
			'erasedataReadDrainState()'), $invariant))
			return;
		$queue = $this->queuePath();
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		$this->acknowledgeOnRegistration($queue);
		$outcome = erasedataRemovalAdmissionRun($this->dependencies(),
			array($this->hash('C'), 'not-a-hash', strtolower($this->hash('A')),
				str_repeat('Z', 40), $this->hash('C')), 1);
		$this->assertTrue(is_array($outcome) && isset($outcome['accepted']),
			'the mixed batch is admitted rather than refused whole');
		$this->assertEquals(array($this->hash('A'), $this->hash('C')),
			is_array($outcome) && isset($outcome['accepted'])
				? array_values($outcome['accepted']) : array(),
			'A is the canonical sorted deduplicated set');
		$state = erasedataReadDrainState($queue);
		$generation = is_array($outcome) && isset($outcome['generation'])
			? $outcome['generation'] : null;
		$this->assertTrue(is_array($state) && isset($state['journal'][$generation]['hashes'])
			&& $state['journal'][$generation]['hashes']
				=== array($this->hash('A'), $this->hash('C')),
			'and the journal record binds exactly that set, in that order');
		foreach($this->queueEntries() as $entry)
			$this->assertTrue(strpos($entry, str_repeat('Z', 40)) !== 0
				&& strpos($entry, 'not-a-hash') === false,
				'no queue entry belongs to a refused member: '.$entry);
		// A batch of nothing but F: no state, no marker, no staging, no RPC.
		$this->reset();
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		$this->assertTrue(erasedataRemovalAdmissionRun($this->dependencies(),
			array('not-a-hash', 42, null), 1) === false,
			'a batch with no admissible member is refused');
		$this->assertEquals(array(), $this->queueEntries(),
			'and leaves the queue directory exactly as empty as it found it');
		$this->assertEquals(0, count(rXMLRPCRequest::$requested),
			'and performs no RPC of any kind, not even the arm');
	}

	// Invariant 6: `erase-started` is DURABLE before the first destructive call.
	//
	// testAfterTheAckTheProducerRevalidatesTheCompletePreparedBinding proves
	// the record is never written when revalidation fails. Nothing proved the
	// other direction, so a producer that set the phase in memory and erased
	// without ever publishing it passed the whole suite -- and a crash between
	// the erase and the next state write would then leave a torrent erased with
	// a journal record still claiming nothing destructive had begun. The state
	// FILE is read here at the instant of the first destructive command, with
	// no production code between the assertion and the bytes.
	public function testTheFirstDestructiveCallFindsADurableEraseStartedRecord()
	{
		$this->reset();
		$invariant = 'the erase-started journal record is durable before the'
			.' first destructive command is sent';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()'), $invariant))
			return;
		$queue = $this->queuePath();
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->acknowledgeOnRegistration($queue);
		$observed = false;
		$this->eraseOk(function($commands) use ($queue, &$observed)
		{
			$raw = @file_get_contents($queue.'/.drain-state');
			$observed = is_string($raw) ? json_decode($raw, true) : false;
		});
		$outcome = erasedataRemovalAdmissionRun($this->dependencies(),
			array($this->hash('A')), 1);
		$generation = is_array($outcome) && isset($outcome['generation'])
			? $outcome['generation'] : null;
		$this->assertTrue(erasedataGenerationIsValid($generation),
			'the admission really completed and reported its generation');
		$this->assertEquals(1, count(rXMLRPCRequest::$erased),
			'and really reached the destructive request');
		$this->assertTrue(is_array($observed),
			'a durable drain state exists when the first destructive command is sent');
		$this->assertTrue(is_array($observed)
			&& isset($observed['journal'][$generation]['phase'])
			&& $observed['journal'][$generation]['phase'] === 'erase-started',
			'and its record for this exact generation already says erase-started');
	}

	// A refused arm never erases, even when an acknowledgement is sitting there.
	//
	// testScheduleRegistrationFaultOrFalseNeverStagesOrErases drives the same
	// two refusals, but with no acknowledgement at all: a producer that ignored
	// the registration result would still stop at the bounded ack wait, so its
	// "nothing is erased" assertion is satisfied by the wrong mechanism. Here a
	// previous generation's child is still running and raises the
	// acknowledgement while the registration is being refused, which is exactly
	// the state a restarted daemon leaves behind. Only the registration result
	// itself can stop the erase now.
	public function testARefusedArmErasesNothingEvenWhenAnAcknowledgementArrives()
	{
		$this->reset();
		$invariant = 'a registration that faults or returns false stages and'
			.' erases nothing even when the acknowledgement is already there';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()',
			'erasedataReadDrainState()', 'erasedataWriteDrainState()'), $invariant))
			return;
		$queue = $this->queuePath();
		$cases = array(
			'a fault' => array('ok' => true, 'fault' => true, 'faultString' => 'refused'),
			'a false return' => array('ok' => false, 'val' => array()),
		);
		foreach($cases as $label => $response)
		{
			$this->reset();
			$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
			$this->eraseOk();
			$acknowledged = false;
			$response['callback'] = function($commands) use ($queue, &$acknowledged)
			{
				$state = erasedataReadDrainState($queue);
				if(!is_array($state) || !isset($state['generation']))
					return;
				$state['acknowledged'] = $state['generation'];
				$acknowledged = erasedataWriteDrainState($queue, $state);
			};
			rXMLRPCRequest::$responses['schedule'] = $response;
			$outcome = erasedataRemovalAdmissionRun($this->dependencies(),
				array($this->hash('A')), 1);
			$this->assertTrue($acknowledged,
				'the acknowledgement really landed while the arm answered with '.$label);
			$this->assertTrue($outcome === false,
				'the producer still fails closed on '.$label);
			$this->assertEquals(0, count(rXMLRPCRequest::$erased),
				'and erases nothing on '.$label.', acknowledgement or not');
			$this->assertEquals(array(), glob($queue.'/*.tmp'),
				'and stages nothing on '.$label);
			$this->assertEquals(array(), glob($queue.'/*.pending'),
				'and records no obligation on '.$label.': the admission never became durable');
		}
	}

	// Identity is not enough on its own, and this is why.
	//
	// testAfterTheAckTheProducerRevalidatesTheCompletePreparedBinding swaps the
	// staging by unlinking and recreating it. Whether that changes the inode is
	// a property of the filesystem, not of the plugin: measured, it does on the
	// host and does NOT inside php:7.4-cli or php:8.1-cli, where the inode is
	// reused immediately and a dev/ino comparison sees no change at all. Here
	// the file is rewritten IN PLACE, so dev and ino are provably identical and
	// the only thing that can still refuse the erase is what the file now says.
	public function testAStagingRewrittenInPlaceIsRefusedThoughItsIdentityIsUnchanged()
	{
		$this->reset();
		$invariant = 'a staging object rewritten in place -- same dev, same ino,'
			.' different contents -- is a hard no-erase';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()',
			'erasedataReadDrainState()'), $invariant))
			return;
		$queue = $this->queuePath();
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		$this->acknowledgeOnRegistration($queue);
		$hashes = array($this->hash('A'), $this->hash('B'));
		$identical = false;
		$rewritten = false;
		$collected = 0;
		rXMLRPCRequest::$responses['d.get_base_path'] = array(
			'ok' => true, 'val' => array('/d/name', 1, '/d/name/a.bin'),
			'callback' => function($commands) use ($queue, &$identical, &$rewritten, &$collected)
			{
				if(++$collected < 2)
					return;
				foreach(glob($queue.'/*.tmp') as $file)
				{
					$before = @stat($file);
					// No unlink: the inode has to survive, or this case is
					// proving the same thing the swap case already proves.
					@file_put_contents($file, "not a manifest at all\n");
					clearstatcache(true, $file);
					$after = @stat($file);
					$rewritten = true;
					$identical = is_array($before) && is_array($after)
						&& $before['dev'] === $after['dev']
						&& $before['ino'] === $after['ino'];
				}
			});
		$outcome = erasedataRemovalAdmissionRun(
			$this->dependencies(array('ackTimeout' => 1.0)), $hashes, 1);
		$this->assertTrue($rewritten,
			'the staging really was rewritten while the batch was still preparing');
		$this->assertTrue($identical,
			'and its dev/ino really are unchanged, so identity alone cannot refuse it');
		$this->assertTrue($outcome === false,
			'a staging that no longer holds this generation\'s manifest is a hard no-erase');
		$this->assertEquals(0, count(rXMLRPCRequest::$erased),
			'and no d.erase is sent');
		$state = erasedataReadDrainState($queue);
		$started = false;
		if(is_array($state) && isset($state['journal']) && is_array($state['journal']))
			foreach($state['journal'] as $entry)
				if(isset($entry['phase']) && $entry['phase'] === 'erase-started')
					$started = true;
		$this->assertTrue(!$started,
			'erase-started is never written for a binding that failed revalidation');
	}

	// Publication is not the whole discharge: the marker has to go too.
	//
	// Invariant 10 retires a schedule only on a scan that proves there is no
	// *.pending left, so a marker the producer could not remove -- or anything
	// else that ends up sitting at that name -- is an obligation it still owes,
	// however completely the manifest was published. A producer that reported
	// success anyway would leave that queue unable to retire for ever, and
	// nothing would say why.
	public function testAMarkerThatSurvivesPublicationKeepsTheObligationOutstanding()
	{
		$this->reset();
		$invariant = 'a generation whose marker is still there after publication'
			.' is reported as unresolved rather than as a clean success';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()',
			'erasedataReadDrainState()', 'erasedataPendingMarkerPath()'), $invariant))
			return;
		$queue = $this->queuePath();
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		// Three commands per hash, every one individually answered. Without
		// this the two-hash batch below is a TRUNCATED reply list -- the shared
		// eraseOk() default answers one hash -- so the second member would be
		// UNKNOWN and retained, and this case would be measuring a partial
		// execution instead of what happens to a marker after a publication
		// that really succeeded. testPartialRpcExecutionYieldsExactPerHashOutcomeSets
		// owns the truncated case.
		rXMLRPCRequest::$responses['d.set_custom5']['val'] = array_fill(0, 6, '');
		$this->acknowledgeOnRegistration($queue);
		$blocked = $this->hash('A');
		$clean = $this->hash('B');
		$replaced = false;
		$collected = 0;
		rXMLRPCRequest::$responses['d.get_base_path'] = array(
			'ok' => true, 'val' => array('/d/name', 1, '/d/name/a.bin'),
			'callback' => function($commands) use ($queue, $blocked, &$replaced, &$collected)
			{
				// Both markers are already recorded by now; the staging of the
				// first hash is done and the second is being collected.
				if(++$collected < 2)
					return;
				foreach(glob($queue.'/'.$blocked.'.*.pending') as $marker)
				{
					@unlink($marker);
					$replaced = @mkdir($marker, 0777, true);
				}
			});
		$outcome = erasedataRemovalAdmissionRun($this->dependencies(),
			array($blocked, $clean), 1);
		$this->assertTrue($replaced,
			'something really took the marker name of the first hash');
		$this->assertTrue($outcome === false,
			'the admission reports failure rather than a clean success');
		$state = erasedataReadDrainState($queue);
		$retained = false;
		if(is_array($state) && isset($state['journal']) && is_array($state['journal']))
			foreach($state['journal'] as $entry)
				if(isset($entry['phase']) && $entry['phase'] === 'retained')
					$retained = true;
		$this->assertTrue($retained,
			'and the journal record stays retained, so a later tick retries it');
		$this->assertEquals(1, count(glob($queue.'/'.$clean.'.*.list')),
			'the hash whose marker really went is still published exactly once');
		$this->assertEquals(0, count(glob($queue.'/'.$clean.'.*.pending')),
			'and its marker is gone');
		$this->assertEquals(1, count(glob($queue.'/'.$blocked.'.*.pending')),
			'while the blocked name is left exactly as it was found');
	}

	// -- one real production worker and exact outcomes (task 4) -------------

	public function testTheWorkerIsReachableOnlyThroughTheRealGuardedUpdateChild()
	{
		$this->reset();
		$invariant = 'update.php <nonempty-user> drain is the only scheduled'
			.' production entry point into the worker, it is really reachable,'
			.' and its includes are unconditional';
		$update = $this->productionSource('update.php');
		$this->assertTrue(is_string($update) && strpos($update, "'drain'") !== false,
			'update.php recognises the drain mode argument');
		$this->assertTrue(is_string($update) && strpos($update, 'erasedataDrainWorkerMain') !== false,
			'update.php really calls the worker entry point');
		$this->assertTrue(is_string($update)
			&& preg_match('/^require_once\(.*pending\.php.*\);/m', $update) === 1,
			'update.php loads the queue implementation unconditionally, at file scope');
		$callers = array();
		foreach(ErasedataProductionMirror::pluginFiles() as $file)
		{
			$bytes = @file_get_contents($this->repositoryRoot().'/plugins/erasedata/'.$file);
			if(is_string($bytes) && strpos($bytes, 'erasedataDrainWorkerMain(') !== false)
				$callers[] = $file;
		}
		$this->assertTrue(in_array('update.php', $callers, true),
			'a production caller of the worker exists in update.php, not only in tests');
		$mirror = $this->mirror('worker-reach');
		$mirror->scriptRpc(array());
		$child = ErasedataTestProcess::start($mirror->drainCommand('drain'));
		$this->assertTrue($child->started(), 'the guarded drain child really starts');
		$finished = $child->wait(20);
		$code = $child->reap();
		$this->assertTrue($finished, 'the guarded drain child finishes inside its budget');
		$this->assertEquals(0, $code, 'the guarded drain child exits cleanly');
		$state = @file_get_contents($mirror->listPath.'/.drain-state');
		$this->assertTrue(is_string($state) && $state !== '',
			'the real child wrote durable drain state of its own');
		// The queue above is now claimed by 'rutorrent'. A child started for a
		// DIFFERENT user must refuse it, and the empty user is a different user
		// here rather than a wildcard -- on a single-user install '' owns its
		// own queue and drains it, which
		// testTheEmptyUserOfASingleUserInstallIsAdmittedAndDrainedEndToEnd pins.
		$empty = ErasedataTestProcess::start($mirror->php($mirror->pluginDir.'/update.php',
			array('', 'drain')));
		$empty->wait(20);
		$emptyCode = $empty->reap();
		$emptyOutput = $empty->out.$empty->err;
		// A refusal, not a crash: an uncaught fatal also exits nonzero and
		// would otherwise read as "the guard worked".
		$this->assertTrue($emptyCode !== 0 && $emptyCode < 128,
			'a child started for a user this queue does not belong to refuses (exit '
				.$emptyCode.') instead of admitting worker work');
		$this->assertTrue(strpos($emptyOutput, 'Fatal error') === false
			&& strpos($emptyOutput, 'Uncaught') === false
			&& strpos($emptyOutput, 'Parse error') === false,
			'and refuses deliberately rather than dying: '.trim(substr($emptyOutput, 0, 200)));
	}

	public function testPartialRpcExecutionYieldsExactPerHashOutcomeSets()
	{
		$this->reset();
		$invariant = 'per-hash outcomes are derived from individual known replies:'
			.' E accepted, P published, T1 = E \ P, T0 = A \ E, P + T1 + T0 = A';
		if(!$this->requireApi(array('erasedataClassifyEraseOutcomes()'), $invariant))
			return;
		$hashes = array($this->hash('A'), $this->hash('B'), $this->hash('C'));
		$request = new rXMLRPCRequest();
		// Three commands per hash; a truncated reply list means the tail never ran.
		$request->val = array('', '', '', '', '', '');
		$request->fault = false;
		$outcome = erasedataClassifyEraseOutcomes($hashes, $request);
		$this->assertTrue(is_array($outcome), 'the classifier returns a per-hash map');
		$outcome = is_array($outcome) ? $outcome : array();
		$this->assertEquals('accepted', isset($outcome[$hashes[0]]) ? $outcome[$hashes[0]] : null,
			'the first hash has an individual known reply');
		$this->assertEquals('accepted', isset($outcome[$hashes[1]]) ? $outcome[$hashes[1]] : null,
			'so does the second');
		$this->assertEquals('unknown', isset($outcome[$hashes[2]]) ? $outcome[$hashes[2]] : null,
			'the dropped last hash is UNKNOWN, never absent and never accepted');
		$dropped = new rXMLRPCRequest();
		$dropped->val = array('', '', '');
		$outcome = erasedataClassifyEraseOutcomes($hashes, $dropped);
		$outcome = is_array($outcome) ? $outcome : array();
		$this->assertEquals(array('accepted', 'unknown', 'unknown'),
			array(isset($outcome[$hashes[0]]) ? $outcome[$hashes[0]] : null,
				isset($outcome[$hashes[1]]) ? $outcome[$hashes[1]] : null,
				isset($outcome[$hashes[2]]) ? $outcome[$hashes[2]] : null),
			'a dropped middle hash cannot be reported as accepted');
		$aggregate = new rXMLRPCRequest();
		$aggregate->val = array();
		$outcome = erasedataClassifyEraseOutcomes($hashes, $aggregate);
		$outcome = is_array($outcome) ? $outcome : array();
		$unknown = 0;
		foreach($hashes as $hash)
			if(isset($outcome[$hash]) && $outcome[$hash] === 'unknown')
				$unknown++;
		$this->assertEquals(3, $unknown,
			'an aggregate request that returned nothing accepts nothing');
		$faulted = new rXMLRPCRequest();
		$faulted->val = array('', '', '', '', '', '', '', '', '');
		$faulted->fault = true;
		$outcome = erasedataClassifyEraseOutcomes($hashes, $faulted);
		$outcome = is_array($outcome) ? $outcome : array();
		$known = 0;
		foreach($hashes as $hash)
			if(isset($outcome[$hash]) && $outcome[$hash] !== 'unknown')
				$known++;
		$this->assertEquals(0, $known,
			'a faulted aggregate reply leaves every hash UNKNOWN, not successful');
		$duplicate = new rXMLRPCRequest();
		$duplicate->val = array('', '', '', '', '', '', '', '', '');
		$outcome = erasedataClassifyEraseOutcomes(
			array($hashes[0], $hashes[0], $hashes[1]), $duplicate);
		$this->assertTrue(is_array($outcome) && count($outcome) === 2,
			'a duplicated request hash yields one outcome, not two');
	}

	// The exact per-hash outcome sets of one admission run, as they are
	// observable after it. E is derived the way invariant 2 requires -- through
	// the classifier, from the number of individual replies the batch really
	// got, never assigned before the aggregate returned -- and P from the
	// durable manifests the run published for that exact generation.
	private function outcomeSets(array $accepted, $generation, $replies)
	{
		$request = new rXMLRPCRequest();
		$request->val = $replies > 0 ? array_fill(0, $replies, '') : array();
		$request->fault = false;
		$classified = erasedataClassifyEraseOutcomes($accepted, $request);
		$erased = array();
		foreach(is_array($classified) ? $classified : array() as $hash => $class)
			if($class === 'accepted')
				$erased[] = $hash;
		sort($erased, SORT_STRING);
		$published = array();
		foreach($accepted as $hash)
			if(is_string($generation) && count(glob($this->queuePath().'/'.$hash
				.'.'.$generation.'.*.list')))
				$published[] = $hash;
		sort($published, SORT_STRING);
		$set = $accepted;
		sort($set, SORT_STRING);
		return(array(
			'A' => $set,
			'E' => $erased,
			'P' => $published,
			'T1' => array_values(array_diff($erased, $published)),
			'T0' => array_values(array_diff($set, $erased)),
		));
	}

	// Every algebraic identity invariant 2 states, over one run's sets.
	private function assertOutcomeAlgebra(array $sets, $label)
	{
		$this->assertEquals(0, count(array_diff($sets['E'], $sets['A'])),
			$label.': E is a subset of A');
		$this->assertEquals(0, count(array_diff($sets['P'], $sets['E'])),
			$label.': P is a subset of E -- nothing is published that was not'
				.' individually known-accepted');
		$this->assertEquals($sets['T1'], array_values(array_diff($sets['E'], $sets['P'])),
			$label.': T1 = E \\ P');
		$this->assertEquals($sets['T0'], array_values(array_diff($sets['A'], $sets['E'])),
			$label.': T0 = A \\ E');
		$this->assertEquals(0, count(array_intersect($sets['P'], $sets['T1'])),
			$label.': P and T1 are disjoint');
		$this->assertEquals(0, count(array_intersect($sets['P'], $sets['T0'])),
			$label.': P and T0 are disjoint');
		$this->assertEquals(0, count(array_intersect($sets['T1'], $sets['T0'])),
			$label.': T1 and T0 are disjoint');
		$union = array_merge($sets['P'], $sets['T1'], $sets['T0']);
		sort($union, SORT_STRING);
		$this->assertEquals($sets['A'], $union, $label.': P + T1 + T0 = A');
		$this->assertEquals(count($sets['A']),
			count($sets['P']) + count($sets['T1']) + count($sets['T0']),
			$label.': |P| + |T1| + |T0| = |A| = '.count($sets['A']));
	}

	public function testTheAcceptedSetPartitionsExactlyIntoPublishedRetainedAndUnattempted()
	{
		$this->reset();
		$invariant = 'the accepted set partitions exactly: E subset of A, P'
			.' subset of E, T1 = E \\ P, T0 = A \\ E and P + T1 + T0 = A, with'
			.' exact membership and cardinality, on all success and under an'
			.' injected cut';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()',
			'erasedataReadDrainState()', 'erasedataWriteDrainState()',
			'erasedataClassifyEraseOutcomes()', 'erasedataPendingObligations()'), $invariant))
			return;
		$hashes = array($this->hash('A'), $this->hash('B'), $this->hash('C'));
		// -- all success: E = P = A, T1 = T0 = empty ------------------------
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		// Three commands per hash, every one individually answered.
		rXMLRPCRequest::$responses['d.set_custom5']['val'] = array_fill(0, 9, '');
		$this->acknowledgeOnRegistration($this->queuePath());
		$outcome = erasedataRemovalAdmissionRun($this->dependencies(), $hashes, 1);
		$generation = is_array($outcome) && isset($outcome['generation'])
			? $outcome['generation'] : null;
		$this->assertTrue(is_string($generation),
			'the all-success run reports the generation it bound');
		$sets = $this->outcomeSets($hashes, $generation, 9);
		$this->assertOutcomeAlgebra($sets, 'all success');
		$this->assertEquals($hashes, $sets['E'],
			'all success: every accepted member is individually known-accepted');
		$this->assertEquals($hashes, $sets['P'],
			'all success: E = P = A, so every member is durably published');
		$this->assertEquals(array(), $sets['T1'], 'all success: T1 is empty');
		$this->assertEquals(array(), $sets['T0'], 'all success: T0 is empty');
		$this->assertEquals(array(), glob($this->queuePath().'/*.tmp'),
			'all success: no staging survives');
		$this->assertEquals(array(), glob($this->queuePath().'/*.pending'),
			'all success: no obligation survives');
		// -- injected cut: the reply list stops after the second hash --------
		$this->reset();
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		rXMLRPCRequest::$responses['d.set_custom5']['val'] = array_fill(0, 6, '');
		$this->acknowledgeOnRegistration($this->queuePath());
		$outcome = erasedataRemovalAdmissionRun($this->dependencies(), $hashes, 1);
		$generation = is_array($outcome) && isset($outcome['generation'])
			? $outcome['generation'] : null;
		$this->assertTrue(is_string($generation),
			'the cut run reports the generation it bound');
		$sets = $this->outcomeSets($hashes, $generation, 6);
		$this->assertOutcomeAlgebra($sets, 'injected cut');
		$this->assertEquals(array($hashes[0], $hashes[1]), $sets['E'],
			'injected cut: only the two individually answered hashes are in E');
		$this->assertEquals(array($hashes[2]), $sets['T0'],
			'injected cut: the dropped tail is exactly T0 = A \\ E');
		$this->assertEquals(0, count(array_intersect(array($hashes[2]), $sets['P'])),
			'injected cut: the hash whose reply never came is never published');
		$retained = array_merge(
			glob($this->queuePath().'/'.$hashes[2].'.'.$generation.'.*.tmp'),
			glob($this->queuePath().'/'.$hashes[2].'.'.$generation.'.pending'));
		$this->assertTrue(count($retained) > 0,
			'injected cut: every T0 member keeps a self-describing retryable obligation');
		$obligations = erasedataPendingObligations($this->queuePath());
		$this->assertTrue(is_array($obligations) && count($obligations) > 0,
			'injected cut: the run leaves the unresolved remainder queued');
	}

	public function testTransportOrParseUncertaintyIsUnknownNeverAbsenceOrSuccess()
	{
		$this->reset();
		$invariant = 'a failed or unknown live-hash probe retains the affected'
			.' obligations and can trigger neither publish nor unlink';
		if(!$this->requireApi(array('erasedataDrainWorkerRun()',
			'erasedataQueueRequest()', 'erasedataPendingObligations()'), $invariant))
			return;
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		$generation = '0000000000000001';
		erasedataQueueRequest($queue, $hash, 1, $generation);
		$staging = $queue.'/'.$hash.'.'.$generation.'.1.tmp';
		@file_put_contents($staging, "staged manifest\n");
		// ARMED, and the staging really bound by an erase-started record. Without
		// this the pass stops at `generation-unarmed` before the probe result can
		// reach a publish or an unlink at all, and every assertion below would
		// hold for any handling of UNKNOWN whatsoever.
		$this->armQueue($queue, $generation, array($hash => $staging));
		$staged = @file_get_contents($staging);
		$this->probe(false, false, array());
		rXMLRPCRequest::$requested = array();
		FileUtil::$log = array();
		erasedataDrainWorkerRun($this->dependencies());
		// A worker that does nothing at all retains everything trivially, so
		// the run has to be shown to have really happened first.
		$this->assertTrue(count(rXMLRPCRequest::$requested) > 0,
			'the worker really asked rTorrent about the obligation it holds');
		$this->assertTrue(is_string($staged) && $staged === @file_get_contents($staging),
			'a transport failure retains the staged manifest, byte for byte');
		$this->assertEquals(0, count(rXMLRPCRequest::$erased),
			'and erases nothing');
		$obligations = erasedataPendingObligations($queue);
		$this->assertTrue(is_array($obligations) && isset($obligations[$generation]),
			'and the obligation is retained rather than resolved on a failed probe');
		$this->probe(true, true, array(), 'Method not defined');
		erasedataDrainWorkerRun($this->dependencies());
		$this->assertTrue(is_string($staged) && $staged === @file_get_contents($staging),
			'an unrelated fault is UNKNOWN and still retains the staged manifest');
		$classified = false;
		foreach(FileUtil::$log as $line)
			if(strpos($line, 'unknown') !== false && strpos($line, $hash) !== false)
				$classified = true;
		$this->assertTrue($classified,
			'the uncertainty is classified as unknown against its canonical hash'
				.' rather than silently dropped');
	}

	public function testPublishFailureKeepsTheJournalActiveAndRetainsExactBytes()
	{
		$this->reset();
		$invariant = 'a mixed batch with a publish failure keeps the successful'
			.' final manifests self-describing and keeps the retained staging'
			.' bound to an active erase-started journal entry';
		if(!$this->requireApi(array('erasedataDrainWorkerRun()',
			'erasedataReadDrainState()', 'erasedataWriteDrainState()'), $invariant))
			return;
		$queue = $this->queuePath();
		$generation = '0000000000000003';
		$first = $this->hash('A');
		$second = $this->hash('B');
		$paths = array();
		foreach(array($first, $second) as $hash)
		{
			$paths[$hash] = $queue.'/'.$hash.'.'.$generation.'.1.tmp';
			@file_put_contents($paths[$hash], "{manifest of ".$hash."\n");
		}
		// The second final name is already a directory, so its publish cannot land.
		@mkdir($queue.'/'.$second.'.'.$generation.'.1.list', 0777, true);
		$state = array(
			'version' => 1, 'user' => 'rutorrent',
			'generation' => $generation, 'acknowledged' => $generation,
			'phase' => 'armed', 'diagnostics' => array(),
			'journal' => array($generation => array(
				'phase' => 'erase-started', 'force' => 1,
				'hashes' => array($first, $second),
				'staging' => array(),
			)),
		);
		foreach(array($first, $second) as $hash)
		{
			$stat = @stat($paths[$hash]);
			$state['journal'][$generation]['staging'][$hash] = array(
				'path' => $paths[$hash],
				'dev' => is_array($stat) ? $stat['dev'] : 0,
				'ino' => is_array($stat) ? $stat['ino'] : 0);
		}
		$this->assertTrue(erasedataWriteDrainState($queue, $state) === true,
			'the erase-started journal is durable before the worker runs');
		$before = @file_get_contents($paths[$second]);
		$this->assertTrue(is_string($before) && $before !== '',
			'the staging whose publication will fail is readable before the run');
		$this->eraseOk();
		$this->probe(true, true, array(), 'invalid parameters: info-hash not found');
		erasedataDrainWorkerRun($this->dependencies());
		$this->assertEquals($before, @file_get_contents($paths[$second]),
			'the staging that could not be published retains its exact bytes');
		$after = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($after) && isset($after['journal'][$generation]),
			'the journal entry stays active while a bound staging survives');
		$this->assertTrue(is_array($after) && isset($after['journal'][$generation]['phase'])
			&& $after['journal'][$generation]['phase'] !== 'completed',
			'a premature completion is never written while a bound staging survives');
		$refusals = array_filter(FileUtil::$log, function($line) use ($second) {
			return(strpos($line, 'erasedata: publish-refused ') === 0
				&& strpos($line, $second) !== false);
		});
		$this->assertEquals(1, count($refusals),
			'the generation pass reports the first publication refusal');
		for($tick = 2; $tick <= 4; $tick++)
		{
			FileUtil::$log = array();
			$result = erasedataDrainWorkerRun($this->dependencies());
			$this->assertTrue($result['admitted'] && !$result['retired'],
				'the bound staging remains retryable on tick '.$tick);
			$this->assertEquals(array(), FileUtil::$log,
				'the generation pass does not repeat an unchanged publication refusal');
			$this->assertEquals($before, file_get_contents($paths[$second]),
				'repeated publication refusals preserve the bound staging bytes');
		}
	}

	// "Publish only a staging file whose exact recorded identity still matches",
	// pinned where nothing else pins it.
	//
	// testPublishFailureKeepsTheJournalActiveAndRetainsExactBytes runs with an
	// identity that DOES match, so a worker that published on the name alone
	// passed it. A name is not an identity: an unlink immediately followed by a
	// create at the same name is routine, and promoting whatever holds the name
	// would publish a manifest this generation never staged -- and the
	// collector deletes what a published manifest describes.
	public function testTheWorkerPublishesOnlyStagingWhoseRecordedIdentityStillMatches()
	{
		$this->reset();
		$invariant = 'a staging object whose exact recorded physical identity no'
			.' longer matches is never published and never unlinked';
		if(!$this->requireApi(array('erasedataDrainWorkerRun()',
			'erasedataReadDrainState()', 'erasedataWriteDrainState()'), $invariant))
			return;
		$queue = $this->queuePath();
		$generation = '0000000000000009';
		$hash = $this->hash('A');
		$path = $queue.'/'.$hash.'.'.$generation.'.1.tmp';
		@file_put_contents($path, "the bytes of some other object\n");
		$stat = @stat($path);
		$this->assertTrue(is_array($stat), 'the object at the staging name exists');
		// The journal captured a DIFFERENT inode at this exact name, which is
		// what an unlink-and-recreate leaves behind. Recording the mismatch
		// rather than racing for one keeps the case deterministic on a
		// filesystem that reuses inodes and on one that does not.
		$state = array(
			'version' => 1, 'user' => 'rutorrent',
			'generation' => $generation, 'acknowledged' => $generation,
			'phase' => 'armed', 'diagnostics' => array(),
			'journal' => array($generation => array(
				'phase' => 'erase-started', 'force' => 1,
				'hashes' => array($hash),
				'staging' => array($hash => array(
					'path' => $path,
					'dev' => is_array($stat) ? (string)$stat['dev'] : '0',
					'ino' => is_array($stat) ? (string)($stat['ino'] + 1) : '0')))));
		$this->assertTrue(erasedataWriteDrainState($queue, $state) === true,
			'the erase-started journal is durable before the worker runs');
		$before = @file_get_contents($path);
		$this->eraseOk();
		// rTorrent is certain the download is gone, so the recorded identity is
		// the only thing between this tick and a publication.
		$this->probe(true, true, array(), 'invalid parameters: info-hash not found');
		FileUtil::$log = array();
		erasedataDrainWorkerRun($this->dependencies());
		$this->assertEquals(array(), glob($queue.'/'.$hash.'.'.$generation.'.*.list'),
			'nothing is published from an object the journal never captured');
		$this->assertEquals($before, @file_get_contents($path),
			'and the object at that name keeps its exact bytes');
		$after = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($after) && isset($after['journal'][$generation]),
			'the journal record stays active for a later tick');
		$classified = false;
		foreach(FileUtil::$log as $line)
			if(strpos($line, $hash) !== false && strpos($line, $generation) !== false)
				$classified = true;
		$this->assertTrue($classified,
			'and the refusal is classified against its hash and its generation');
	}

	public function testEveryUnresolvedOutcomeIsRetriedWithNoTerminalCap()
	{
		$this->reset();
		$invariant = 'every T0, T1, partial publish, RPC unknown or lock'
			.' uncertainty leaves a retryable obligation; there is no terminal'
			.' retry cap and no unreadable ad-hoc pending staging';
		$this->sourceLacks('pending.php', 'giving up on',
			'the queue no longer abandons an unresolved obligation');
		$this->sourceLacks('pending.php', '-pending-staging',
			'no unreadable ad-hoc *-pending-staging name survives');
		$conf = $this->productionSource('conf.php');
		$this->assertTrue(is_string($conf) && strpos($conf, 'erasePendingMaxAttempts') === false,
			'the finite attempt cap is gone from the shipped configuration');
		if(!$this->requireApi(array('erasedataDrainWorkerRun()',
			'erasedataQueueRequest()', 'erasedataPendingObligations()'), $invariant))
			return;
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		$generation = '0000000000000001';
		erasedataQueueRequest($queue, $hash, 1, $generation);
		$staging = $queue.'/'.$hash.'.'.$generation.'.1.tmp';
		@file_put_contents($staging, "staged manifest\n");
		$bytes = @file_get_contents($staging);
		// The retry has to be evidenced over a really ARMED generation with a
		// really bound staging object. Twenty-five ticks that all stop at the
		// `generation-unarmed` refusal evidence the refusal, not the absence of a
		// cap on the T0 path this case is named for.
		$this->armQueue($queue, $generation, array($hash => $staging));
		$this->probe(false, false, array());
		for($tick = 0; $tick < 25; $tick++)
			erasedataDrainWorkerRun($this->dependencies());
		$obligations = erasedataPendingObligations($queue);
		$this->assertTrue(is_array($obligations) && count($obligations) === 1,
			'twenty-five failed ticks still leave the obligation queued');
		$this->assertEquals(0, count(rXMLRPCRequest::$erased),
			'and no torrent was erased to make the obligation go away');
		$this->assertEquals($bytes, @file_get_contents($staging),
			'the bound staging object survives every one of them, byte for byte');
		$after = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($after)
			&& isset($after['journal'][$generation]['staging'][$hash]),
			'and its journal record still binds that object, tick after tick');
	}

	/**
	 * A PUBLISHED manifest the collector really walks, over repeated ticks.
	 *
	 * The sibling case above repeats the worker, but its job is a generation-bound
	 * `.tmp` staging, which the collector deliberately ignores -- so it exercises
	 * the worker's own diagnostic memory and never reaches the collector's. This
	 * one publishes the manifest first, which is what puts the job in front of the
	 * collector on every tick.
	 *
	 * Why it matters: retention is a CONDITION, not an event. A presence probe
	 * that cannot answer keeps the job retained for as long as it stays
	 * unreadable, the drain rebuilds a collector every ERASEDATA_DRAIN_INTERVAL
	 * seconds, and the retries have no terminal limit by design. Reported per tick
	 * that is roughly 17k identical lines a day per retained job -- measured on
	 * this code before the fix: ticks 1, 2 and 3 each logged the same line.
	 */
	public function testAnUnchangedRetentionOfAPublishedManifestIsReportedOncePerCondition()
	{
		$this->reset();
		$invariant = 'a published manifest retained for an unchanged reason is'
			.' reported once, not once per tick, and a CHANGED reason is reported'
			.' at once rather than inheriting the silence';
		if(!$this->requireApi(array('erasedataDrainWorkerRun()',
			'erasedataQueueRequest()'), $invariant))
			return;
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		$generation = '0000000000000001';
		$payload = $this->dir.'/retained-payload.bin';
		file_put_contents($payload, 'payload-bytes-nobody-may-log');
		$bytes = ErasedataManifestCodec::encode($hash,
			array('files' => array($payload), 'base' => $payload, 'multi' => false), 1);
		$staged = erasedataStageAdmittedManifest($queue, $hash, $generation, $bytes);
		$this->assertTrue(is_array($staged), 'the manifest stages');
		$this->armQueue($queue, $generation, array($hash => $staged['path']),
			'published');
		$this->assertTrue(
			ErasedataManifestCodec::publishStaging($staged['path'], $hash),
			'and publishes, so the collector walks it on every tick');

		// (1) Nothing readable comes back, so the whole job is retained.
		$this->probe(true, false, array());
		FileUtil::$log = array();
		$tick = erasedataDrainWorkerRun($this->dependencies());
		$this->assertTrue(is_array($tick) && $tick['admitted'] === true,
			'the first tick really ran: '.json_encode($tick));
		$named = array();
		foreach(FileUtil::$log as $line)
			if(strpos($line, $hash) !== false && strpos($line, 'rpc-unknown') !== false)
				$named[] = $line;
		$this->assertEquals(1, count($named),
			'the first tick classifies the retention: '.json_encode(FileUtil::$log));
		$this->assertTrue(is_file($payload), 'and the payload is retained, not collected');
		foreach(FileUtil::$log as $line)
		{
			$this->assertTrue(strpos($line, $queue) === false,
				'no diagnostic leaks the settings root or a raw path');
			$this->assertTrue(strpos($line, 'payload-bytes-nobody-may-log') === false,
				'no diagnostic leaks payload bytes');
		}

		// (2) The same condition, twice more. This is the whole point: the
		// schedule fires for ever and the condition has not changed.
		foreach(array('second', 'third') as $which)
		{
			FileUtil::$log = array();
			$tick = erasedataDrainWorkerRun($this->dependencies());
			// Without this the silence below could be the silence of a tick that
			// was refused admission and never reached the collector at all.
			$this->assertTrue(is_array($tick) && $tick['admitted'] === true,
				'the '.$which.' tick really ran: '.json_encode($tick));
			$repeated = array();
			foreach(FileUtil::$log as $line)
				if(strpos($line, 'rpc-unknown') !== false)
					$repeated[] = $line;
			$this->assertEquals(0, count($repeated),
				'the '.$which.' tick repeats nothing about an unchanged retention: '
					.json_encode(FileUtil::$log));
			$this->assertTrue(is_file($payload),
				'and the '.$which.' tick still retains the payload');
		}

		// (3) A DIFFERENT reason is a different state and must be heard at once.
		// The torrent is now present, but its owned paths cannot be read, which
		// is 'owned-paths-unknown' rather than 'rpc-unknown'.
		$this->probe(true, false, array($hash));
		rXMLRPCRequest::$responses['f.multicall'] = array('runResult' => false,
			'fault' => false, 'val' => array());
		FileUtil::$log = array();
		$tick = erasedataDrainWorkerRun($this->dependencies());
		$this->assertTrue(is_array($tick) && $tick['admitted'] === true,
			'the reason-change tick really ran: '.json_encode($tick));
		$changed = array();
		foreach(FileUtil::$log as $line)
			if(strpos($line, $hash) !== false
				&& strpos($line, 'owned-paths-unknown') !== false)
				$changed[] = $line;
		$this->assertEquals(1, count($changed),
			'a changed reason is reported immediately rather than inheriting the'
				.' silence of the one before it: '.json_encode(FileUtil::$log));
		$this->assertTrue(is_file($payload),
			'and the payload is still retained under the new reason');
	}

	/**
	 * The cleanup half of the same rule, over repeated ticks.
	 *
	 * manifestLog()'s own comment calls the payload and cleanup retentions one
	 * rule; the case above covers the payload half. This covers the other, and it
	 * is the worse of the two to leave repeating: 'unreadable-manifest' is healed
	 * by no retry and pruned by nothing, so the line stood for the life of the
	 * daemon, and before this it was not even classified.
	 */
	public function testAnUnchangedCleanupRetentionIsAlsoReportedOncePerCondition()
	{
		$this->reset();
		$invariant = 'a cleanup job retained for an unchanged reason is reported'
			.' once, not once per tick, and is classified when it is';
		if(!$this->requireApi(array('erasedataDrainWorkerRun()'), $invariant))
			return;
		$queue = $this->queuePath();
		$hash = $this->hash('B');
		// A cleanup artifact nothing can read. The name is the one
		// erasedataParseCollectorCandidate() accepts: <hash>.cleanup.<digits>.<token>.
		file_put_contents($queue.'/'.$hash.'.cleanup.1.abcdef.list', "garbage\n");

		$seen = array();
		foreach(array('first', 'second', 'third') as $which)
		{
			FileUtil::$log = array();
			$tick = erasedataDrainWorkerRun($this->dependencies());
			$this->assertTrue(is_array($tick),
				'the '.$which.' tick really ran: '.json_encode($tick));
			$lines = array();
			foreach(FileUtil::$log as $line)
				if(strpos($line, 'cleanup-retained') !== false)
					$lines[] = $line;
			$seen[$which] = $lines;
		}
		$this->assertEquals(1, count($seen['first']),
			'the first tick classifies the cleanup retention exactly once: '
				.json_encode($seen['first']));
		$this->assertTrue(count($seen['first']) === 1
			&& strpos($seen['first'][0], $hash) !== false
			&& strpos($seen['first'][0], 'consequence=') !== false,
			'and names its hash and its consequence rather than merely happening: '
				.json_encode($seen['first']));
		$this->assertEquals(0, count($seen['second']),
			'the second tick repeats nothing: '.json_encode($seen['second']));
		$this->assertEquals(0, count($seen['third']),
			'nor does the third: '.json_encode($seen['third']));
		foreach($seen['first'] as $line)
			$this->assertTrue(strpos($line, $queue) === false,
				'and no cleanup diagnostic leaks the settings root');
	}

	/**
	 * The ordinary schedule does not report payload retention -- the drain does.
	 *
	 * Two schedules run the same collector with opposite lifetimes. The ordinary
	 * one is registered unconditionally at plugin init and fires every
	 * $garbageCheckInterval for ever, in a fresh process each time; the drain is
	 * armed only while an obligation exists and retired when it does not. A
	 * retained published manifest classifies as 'final' and keeps the retirement
	 * scan non-empty, so it keeps the drain alive exactly as long as there is
	 * something to say. Reporting from the ordinary pass therefore cannot be
	 * bounded by anything -- measured before this: the same line on every one of
	 * three alternating cycles -- while reporting from the drain is bounded by
	 * the condition itself.
	 */
	public function testTheOrdinaryCollectorLeavesPayloadRetentionToTheDrain()
	{
		$this->reset();
		$invariant = 'the always-on collector schedule reports no payload'
			.' retention; the drain, which exists because the obligation does,'
			.' reports it once per condition';
		if(!$this->requireApi(array('erasedataDrainWorkerRun()'), $invariant))
			return;
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		$generation = '0000000000000001';
		$payload = $this->dir.'/ordinary-retained.bin';
		file_put_contents($payload, 'payload-bytes-nobody-may-log');
		$bytes = ErasedataManifestCodec::encode($hash,
			array('files' => array($payload), 'base' => $payload, 'multi' => false), 1);
		$staged = erasedataStageAdmittedManifest($queue, $hash, $generation, $bytes);
		$this->assertTrue(is_array($staged), 'the manifest stages');
		$this->armQueue($queue, $generation, array($hash => $staged['path']),
			'published');
		$this->assertTrue(
			ErasedataManifestCodec::publishStaging($staged['path'], $hash),
			'and publishes, so both schedules walk it');
		$this->probe(true, false, array());

		// Three cycles, each running the ordinary collector and then the drain,
		// exactly as the two schedules do beside each other.
		$ordinary = array();
		$drain = array();
		foreach(array(1, 2, 3) as $cycle)
		{
			FileUtil::$log = array();
			// The production wiring of the ordinary schedule, from
			// plugins/erasedata/update.php: no sink, no drain state, the live
			// seam. Anything it says, it says on every one of these cycles.
			$service = erasedataCollectorService(new ErasedataFilesystemOps());
			$service->run($queue);
			$ordinary[$cycle] = FileUtil::$log;
			FileUtil::$log = array();
			erasedataDrainWorkerRun($this->dependencies());
			$lines = array();
			foreach(FileUtil::$log as $line)
				if(strpos($line, 'rpc-unknown') !== false)
					$lines[] = $line;
			$drain[$cycle] = $lines;
		}
		foreach(array(1, 2, 3) as $cycle)
			$this->assertEquals(0, count($ordinary[$cycle]),
				'the ordinary pass says nothing about retention on cycle '.$cycle
					.': '.json_encode($ordinary[$cycle]));
		$this->assertEquals(1, count($drain[1]),
			'the drain classifies it once: '.json_encode($drain[1]));
		$this->assertEquals(0, count($drain[2]),
			'and not again on cycle 2: '.json_encode($drain[2]));
		$this->assertEquals(0, count($drain[3]),
			'nor on cycle 3: '.json_encode($drain[3]));
		$this->assertTrue(is_file($payload),
			'and the payload is retained throughout, which is why it kept saying so');
	}

	/**
	 * The diagnostic memory covers the whole queue the journal admits.
	 *
	 * The memory is what keeps an unchanged condition from being reported again
	 * on the next tick, and the drain fires every ERASEDATA_DRAIN_INTERVAL for as
	 * long as an obligation is outstanding. A generation whose digest does not
	 * fit is therefore a generation reported for ever. The cap used to be 32
	 * against a journal of ERASEDATA_DRAIN_MAX_JOURNAL, so the bound held for the
	 * first 32 generations and silently stopped holding for the rest -- measured
	 * at 33 admitted generations, the 33rd repeated verbatim on every tick.
	 *
	 * This pins the relationship, not the number: whatever the journal admits,
	 * the memory must be able to describe, and the named groups must survive a
	 * truncation because a generation key sorts before every letter.
	 */
	public function testTheDiagnosticMemoryCoversEveryGenerationTheJournalAdmits()
	{
		$this->reset();
		// The UNION, not either set. A tick's tasks come from the pending
		// projection and from the carried journal, and the two are capped
		// separately: a published final manifest leaves the pending projection
		// while its unfinished journal record still yields a task, so the two
		// sets are disjoint in the worst case and the bound is their sum.
		// Measured before this: 10 journal generations and 4095 marker-only ones
		// gave 4105 tasks against a cap of 4103, and the four that did not fit
		// repeated verbatim on every tick.
		$union = ERASEDATA_PENDING_MAX_GENERATIONS + ERASEDATA_DRAIN_MAX_JOURNAL;
		$this->assertTrue(ERASEDATA_DRAIN_MAX_DIAGNOSTICS > $union,
			'the memory describes the whole union of admissible tasks: '
				.ERASEDATA_DRAIN_MAX_DIAGNOSTICS.' vs '.$union);

		// One digest per task the union can hold, plus the named groups, against
		// the state ceiling. 57 bytes is a 16-hex key, a space and a sha1.
		$worst = ($union + 8) * 57;
		$this->assertTrue($worst < ERASEDATA_DRAIN_STATE_MAX_BYTES,
			'and a full memory still fits the state: '.$worst.' vs '
				.ERASEDATA_DRAIN_STATE_MAX_BYTES);

		// A memory deeper than the old cap survives whole, and the named groups
		// survive with it. A generation key is 16 lowercase hex and sorts before
		// every letter, so a plain sort put the named groups last and truncation
		// took exactly the ones that describe the tick as a whole.
		$memory = array();
		for($index = 1; $index <= 64; $index++)
			$memory[sprintf('%016x', $index)] = sha1('generation-'.$index);
		foreach(array('tick', 'retire', 'manifest', 'cleanup') as $named)
			$memory[$named] = sha1('group-'.$named);
		$lines = erasedataDrainReportLines($memory);
		$this->assertEquals(68, count($lines),
			'every entry is kept, not the first 32: '.count($lines));
		$kept = array();
		foreach($lines as $line)
			$kept[substr($line, 0, strpos($line, ' '))] = true;
		foreach(array('tick', 'retire', 'manifest', 'cleanup') as $named)
			$this->assertTrue(isset($kept[$named]),
				'the named group '.$named.' survives: '.json_encode(array_keys($kept)));

		// And when a truncation does happen, it takes generations rather than the
		// groups that describe the tick.
		$overflow = array();
		for($index = 1; $index <= ERASEDATA_DRAIN_MAX_DIAGNOSTICS + 16; $index++)
			$overflow[sprintf('%016x', $index)] = sha1('overflow-'.$index);
		$overflow['tick'] = sha1('group-tick');
		$overflow['retire'] = sha1('group-retire');
		$truncated = erasedataDrainReportLines($overflow);
		$this->assertEquals(ERASEDATA_DRAIN_MAX_DIAGNOSTICS, count($truncated),
			'the cap is still a cap: '.count($truncated));
		$keptNames = array();
		foreach($truncated as $line)
			$keptNames[substr($line, 0, strpos($line, ' '))] = true;
		$this->assertTrue(isset($keptNames['tick']) && isset($keptNames['retire']),
			'and the named groups are what it keeps, not what it drops');
	}

	// Keep named-runner arguments out of the scheduled collector CLI boundary.
	private function runOrdinaryCollector()
	{
		global $argv;
		$saved = $argv;
		$argv = array('update.php', 'rutorrent');
		try
		{
			erasedataCollectorMain(new ErasedataFilesystemOps());
		}
		finally
		{
			$argv = $saved;
		}
	}

	public function testOrdinaryCollectorReportsLegacyPublicationFailureWithoutDrain()
	{
		foreach(array('occupied-final', 'readonly-queue') as $obstacle)
		{
			$this->reset();
			if($obstacle === 'readonly-queue' && testSkipUnlessPermissionsBite(
				'ordinary collector reports a publication refused by queue permissions'))
				continue;
			$hash = $this->hash('B');
			$queue = $this->queuePath();
			$payload = $this->dir.'/legacy-payload.bin';
			file_put_contents($payload, 'retained legacy payload');
			$bytes = ErasedataManifestCodec::encode($hash,
				array('base' => $payload, 'multi' => false, 'files' => array($payload)), 1);
			$staged = erasedataWriteStagedManifest($queue, $hash, $bytes);
			$this->assertTrue(is_array($staged), 'the legacy producer staging really exists');
			if(!is_array($staged))
				return;
			$final = substr($staged['path'], 0, -4).'.list';
			if($obstacle === 'occupied-final')
				mkdir($final);
			else
			{
				// Existing lock files remain openable when directory writes fail.
				file_put_contents($queue.'/scheduler.lock', '');
				file_put_contents($queue.'/'.$hash.'.lock', '');
				chmod($queue, 0500);
				FileUtil::$denyDirectoryRepair = true;
			}
			try
			{
				$this->probe(true, true, array(), 'Could not find info-hash.', -501);
				for($tick = 1; $tick <= 3; $tick++)
				{
					FileUtil::$log = array();
					$this->runOrdinaryCollector();
					$this->assertEquals(1, count(FileUtil::$log),
						$obstacle.': the ordinary collector reports its refusal on tick '.$tick);
					$this->assertTrue(count(FileUtil::$log) === 1
						&& strpos(FileUtil::$log[0], $obstacle === 'readonly-queue'
							? 'cannot seal queue' : 'failed to publish manifest for '.$hash) !== false,
						'the report identifies the refusal and its queue or hash');
					$this->assertEquals($bytes, file_get_contents($staged['path']),
						'the refused publication retains the exact staging bytes');
					$this->assertEquals('retained legacy payload', file_get_contents($payload),
						'the refusal leaves the payload untouched');
				}
				$this->assertTrue(!file_exists($queue.'/.drain-state'),
					'no drain exists to report this ordinary collector refusal');
			}
			finally
			{
				if($obstacle === 'readonly-queue')
				{
					FileUtil::$denyDirectoryRepair = false;
					chmod($queue, 0700);
				}
				else
					rmdir($final);
			}
			FileUtil::$log = array();
			$this->runOrdinaryCollector();
			$this->assertTrue(!file_exists($staged['path']) && !file_exists($final)
				&& !file_exists($payload), 'a later ordinary tick finishes after the obstacle is removed');
			$this->assertEquals(array(), FileUtil::$log, 'successful recovery emits no stale refusal');
		}
	}

	public function testDrainCollectorDeduplicatesLegacyPublicationFailure()
	{
		$this->reset();
		$hash = $this->hash('B');
		$queue = $this->queuePath();
		$payload = $this->dir.'/drain-legacy-payload.bin';
		file_put_contents($payload, 'retained legacy payload');
		$bytes = ErasedataManifestCodec::encode($hash,
			array('base' => $payload, 'multi' => false, 'files' => array($payload)), 1);
		$staged = erasedataWriteStagedManifest($queue, $hash, $bytes);
		$this->assertTrue(is_array($staged), 'the collector receives real legacy staging');
		if(!is_array($staged))
			return;
		$final = substr($staged['path'], 0, -4).'.list';
		mkdir($final);
		// An already armed drain also walks legacy staging through its collector.
		$this->armQueue($queue, '0000000000000001', array());
		$this->probe(true, true, array(), 'Could not find info-hash.', -501);
		for($tick = 1; $tick <= 3; $tick++)
		{
			FileUtil::$log = array();
			$result = erasedataDrainWorkerRun($this->dependencies());
			$this->assertTrue($result['admitted'] && !$result['retired'],
				'the actual drain runs its collector and retains the blocked job');
			$refusals = array();
			foreach(FileUtil::$log as $line)
			{
				$this->assertTrue(strpos($line, 'failed to publish manifest for') === false,
					'the codec does not bypass the drain diagnostic memory');
				if(strpos($line, 'publish-refused') !== false && strpos($line, $hash) !== false)
					$refusals[] = $line;
			}
			$this->assertEquals($tick === 1 ? 1 : 0, count($refusals),
				'the drain classifies the collector publication refusal only on its first tick');
			if($tick > 1)
				$this->assertEquals(array(), FileUtil::$log, 'an unchanged drain tick is quiet');
			$this->assertEquals($bytes, file_get_contents($staged['path']),
				'the drain retains the exact staging bytes');
			$this->assertEquals('retained legacy payload', file_get_contents($payload),
				'the drain retains the payload while publication is refused');
		}
	}

	/**
	 * A publication refusal is reported by whoever classifies it, once.
	 *
	 * ErasedataManifestCodec::publishStaging() writes its own unclassified line
	 * on failure and has no memory of any kind, while the drain retries a
	 * retained staging on every tick -- so that line stood on every one of them,
	 * about 17k a day for a single obstacle, beside the classified note that was
	 * already deduplicated. The codec now stays quiet for callers that classify
	 * the refusal themselves, and says it for callers that would otherwise say
	 * nothing.
	 */
	public function testAPublicationRefusalIsReportedByOneVoiceNotTwo()
	{
		$this->reset();
		$hash = $this->hash('B');
		$queue = $this->queuePath();
		$staging = $queue.'/'.$hash.'.0000000000000001.7.tmp';
		file_put_contents($staging, 'staged-bytes');
		// A directory standing on the final name refuses the rename for every
		// user, root included, so this case does not depend on permissions.
		@mkdir($queue.'/'.$hash.'.0000000000000001.7.list');

		FileUtil::$log = array();
		$this->assertEquals(false,
			ErasedataManifestCodec::publishStaging($staging, $hash, null, false),
			'the publication really is refused');
		$this->assertEquals(0, count(FileUtil::$log),
			'and a caller that classifies it hears nothing from the codec: '
				.json_encode(FileUtil::$log));

		FileUtil::$log = array();
		$this->assertEquals(false,
			ErasedataManifestCodec::publishStaging($staging, $hash),
			'the same refusal, for a caller that says nothing of its own');
		$this->assertEquals(1, count(FileUtil::$log),
			'is still reported, because silence there would lose it entirely: '
				.json_encode(FileUtil::$log));
		$this->assertTrue(strpos(FileUtil::$log[0], $hash) !== false,
			'and the line names the hash it is about: '.FileUtil::$log[0]);
		$this->assertTrue(is_file($staging),
			'the staged bytes are retained either way');
	}

	public function testDiagnosticsAreUnconditionalBoundedClassifiedAndLeakNothing()
	{
		$this->reset();
		$invariant = 'diagnostics are unconditional, bounded and classified per'
			.' physical job and generation: canonical hash plus reason plus'
			.' consequence, and never a raw path, settings root, manifest byte,'
			.' hash list or remote text';
		if(!$this->requireApi(array('erasedataDrainWorkerRun()',
			'erasedataQueueRequest()'), $invariant))
			return;
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		$generation = '0000000000000001';
		erasedataQueueRequest($queue, $hash, 1, $generation);
		$staging = $queue.'/'.$hash.'.'.$generation.'.1.tmp';
		@file_put_contents($staging,
			"secret-manifest-bytes-should-never-be-logged\n");
		// Armed and bound, so the lines below are the ones the pass really
		// produces rather than the two a `generation-unarmed` refusal produces.
		$this->armQueue($queue, $generation, array($hash => $staging));
		$this->probe(true, true, array(), 'rTorrent said something private');
		FileUtil::$log = array();
		erasedataDrainWorkerRun($this->dependencies());
		$this->assertTrue(count(FileUtil::$log) > 0,
			'the worker reports its outcome without needing debug to be on');
		$this->assertTrue(count(FileUtil::$log) < 64,
			'diagnostics stay bounded: '.count(FileUtil::$log).' lines');
		foreach(FileUtil::$log as $line)
		{
			$this->assertTrue(strpos($line, $queue) === false,
				'no diagnostic leaks the settings root or a raw path');
			$this->assertTrue(strpos($line, 'secret-manifest-bytes') === false,
				'no diagnostic leaks manifest bytes');
			$this->assertTrue(strpos($line, 'rTorrent said something private') === false,
				'no diagnostic leaks remote text');
		}
		$classified = false;
		foreach(FileUtil::$log as $line)
			if(strpos($line, $hash) !== false && strpos($line, '0000000000000001') !== false)
				$classified = true;
		$this->assertTrue($classified,
			'each diagnostic names its canonical hash and its generation');
		// Bounded over TIME, not only per tick. The drain schedule fires every
		// ERASEDATA_DRAIN_INTERVAL seconds and by design never gives up, so one
		// obligation nothing can discharge would otherwise write these same lines
		// into the ruTorrent log for the life of the installation.
		FileUtil::$log = array();
		erasedataDrainWorkerRun($this->dependencies());
		$this->assertEquals(0, count(FileUtil::$log),
			'a repeat of the same unchanged unresolvable job is not reported again');
		// And nothing is ever suppressed that is not identical: the moment the
		// classification changes it is reported at once, on the same tick.
		FileUtil::$log = array();
		$this->probe(true, false, array($hash));
		erasedataDrainWorkerRun($this->dependencies());
		$this->assertTrue(count(FileUtil::$log) > 0,
			'while a CHANGED classification is reported at once, never suppressed');
		$changed = false;
		foreach(FileUtil::$log as $line)
			if(strpos($line, $hash) !== false && strpos($line, $generation) !== false)
				$changed = true;
		$this->assertTrue($changed,
			'and the changed report still names its hash and its generation');
	}

	// The producer/worker split, which nothing else in this file pinned.
	//
	// A `prepared` journal record is the PRODUCER's own transaction: after its
	// acknowledgement it re-takes the locks, revalidates the complete binding
	// and only then writes `erase-started` and erases. The guarded worker can
	// only reach a `prepared` record with that generation's hash locks taken,
	// and a producer that can still reach its own erase holds those from
	// admission to the end -- so reaching one proves the producer is gone. The
	// worker then CANCELS it, exactly and identity bound, and erases nothing:
	// completing somebody else's prepared obligation into a deletion is a
	// deletion the producer's own revalidation never approved.
	public function testTheWorkerCancelsAnOrphanedPreparedGenerationAndNeverErasesIt()
	{
		$this->reset();
		$invariant = 'the worker never completes a prepared obligation into an'
			.' erase: an orphaned prepared generation is cancelled exactly,'
			.' identity bound, and a later generation is never touched';
		if(!$this->requireApi(array('erasedataDrainWorkerRun()',
			'erasedataQueueRequest()', 'erasedataWriteDrainState()',
			'erasedataReadDrainState()', 'erasedataPendingObligations()',
			'erasedataCollectPaths()'), $invariant))
			return;
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		$generation = '0000000000000001';
		erasedataQueueRequest($queue, $hash, 1, $generation);
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		// The orphan holds the real manifest its producer staged, so a worker
		// that treated `prepared` as its own would pass every gate below the
		// phase -- identity, decoded hash and decoded force -- and really erase.
		$paths = erasedataCollectPaths($hash);
		$content = ErasedataManifestCodec::encode($hash, $paths, 1);
		$this->assertTrue(is_string($content) && $content !== '',
			'the orphan really holds the manifest its producer staged');
		$staging = $queue.'/'.$hash.'.'.$generation.'.1.tmp';
		@file_put_contents($staging, is_string($content) ? $content : '');
		// Residue this cancellation does not own, under the same hash.
		$foreign = $queue.'/'.$hash.'.ffffffffffffffff.42.tmp';
		@file_put_contents($foreign, "a later generation's staging\n");
		$unrelated = $queue.'/not-a-candidate-at-all';
		@file_put_contents($unrelated, "a file nobody parses\n");
		$this->armQueue($queue, $generation, array($hash => $staging), 'prepared');
		$this->eraseOk();
		// rTorrent still holds it and answers every command, so the PHASE is
		// the only thing between this tick and a destructive call.
		$this->probe(true, false, array($hash));
		FileUtil::$log = array();
		$outcome = erasedataDrainWorkerRun($this->dependencies());
		$this->assertEquals(0, count(rXMLRPCRequest::$erased),
			'a prepared obligation is never completed into an erase by the worker');
		$this->assertEquals(array(), glob($queue.'/'.$hash.'.'.$generation.'.*.list'),
			'and nothing is published under it');
		$this->assertTrue(!file_exists($staging),
			'the orphaned prepared staging is cancelled, not left to rot');
		$this->assertEquals(array(), glob($queue.'/'.$hash.'.'.$generation.'.pending'),
			'and so is the marker that recorded the obligation');
		$this->assertTrue(is_file($foreign) && is_file($unrelated),
			'while a later generation and an unrelated file are untouched');
		$after = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($after) && !isset($after['journal'][$generation]),
			'the cancelled generation leaves no journal record behind');
		$obligations = erasedataPendingObligations($queue);
		$this->assertTrue(is_array($obligations) && count($obligations) === 0,
			'and the queue really is drained rather than left carrying it');
		$this->assertTrue(is_array($outcome) && isset($outcome['cancelled'])
			&& $outcome['cancelled'] === 1,
			'the tick reports exactly the one cancellation it made');
		$classified = false;
		foreach(FileUtil::$log as $line)
			if(strpos($line, 'prepared-cancelled') !== false
				&& strpos($line, $hash) !== false
				&& strpos($line, $generation) !== false)
				$classified = true;
		$this->assertTrue($classified,
			'and says so against its canonical hash and its exact generation');
	}

	// A published manifest is a licence to delete a payload, so it must never
	// become final for a download that was not erased.
	//
	// The drain protocol stages as <hash>.<generation>.<token>.tmp, and the
	// generation-aware candidate alternative made exactly that name an ordinary
	// legacy remove-payload candidate. An ordinary periodic collector pass
	// therefore renamed a LIVE retained T0 staging object to a final
	// <hash>.<generation>.<token>.list -- while rTorrent still held the
	// download. From that moment erasedataPublishedGenerations() reported the
	// generation as published, which hid the hash from BOTH obligation readers:
	// the download was never erased, its marker was orphaned for ever, and a
	// payload manifest stood for something still live.
	public function testThePeriodicCollectorNeverPromotesABoundDrainStaging()
	{
		$this->reset();
		$invariant = 'a retained staging object is never promoted to a final'
			.' manifest by the periodic collector, and its hash stays visible to'
			.' both obligation readers until it is genuinely discharged';
		if(!$this->requireApi(array('erasedataParseCollectorCandidate()',
			'erasedataBuildCollectorIndex()', 'erasedataDrainWorkerJobs()',
			'erasedataPendingObligations()', 'erasedataQueueRequest()'), $invariant))
			return;
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		$generation = '0000000000000001';
		erasedataQueueRequest($queue, $hash, 1, $generation);
		$staging = $queue.'/'.$hash.'.'.$generation.'.4242.abc123.tmp';
		@file_put_contents($staging, "a retained T0 staging object\n");
		$this->assertEquals(false,
			erasedataParseCollectorCandidate($queue, basename($staging)),
			'a generation-bound staging object is not a collector candidate at all');
		// The shape the legacy alternative exists for is untouched: an
		// ungenerationed staging object still IS the collector's to promote.
		$legacy = $queue.'/'.$hash.'.4242.abc123.tmp';
		@file_put_contents($legacy, "a legacy staging object\n");
		$candidate = erasedataParseCollectorCandidate($queue, basename($legacy));
		$this->assertTrue(is_array($candidate) && isset($candidate['operation'])
			&& $candidate['operation'] === ErasedataManifestCodec::OPERATION_REMOVE_PAYLOAD,
			'while an ungenerationed legacy staging object still is one');
		// And the PUBLISHED form of a generation-bound job stays a candidate:
		// deleting the payload of something really erased is the collector's
		// own work, and refusing that would strand every drained manifest.
		$other = $this->hash('B');
		$final = $queue.'/'.$other.'.'.$generation.'.4242.abc123.list';
		@file_put_contents($final, "a published manifest\n");
		$published = erasedataParseCollectorCandidate($queue, basename($final));
		$this->assertTrue(is_array($published) && isset($published['operation'])
			&& $published['operation'] === ErasedataManifestCodec::OPERATION_REMOVE_PAYLOAD,
			'and the final manifest of a real erase still is a candidate');
		// The index the periodic pass actually walks is what promotes, so the
		// exclusion has to hold there and not only in the parser.
		$index = erasedataBuildCollectorIndex($queue, $hash);
		$names = array();
		if(is_array($index) && isset($index[$hash]['legacy'])
			&& is_array($index[$hash]['legacy']))
			foreach($index[$hash]['legacy'] as $item)
				$names[] = basename($item['path']);
		$this->assertTrue(!in_array(basename($staging), $names, true),
			'the collector index never offers a bound staging object for promotion');
		$this->assertTrue(in_array(basename($legacy), $names, true),
			'while the legacy object the alternative exists for is still indexed');
		// Both obligation readers still see the hash, which is the half of this
		// the promotion used to destroy.
		$obligations = erasedataPendingObligations($queue);
		$this->assertTrue(is_array($obligations) && isset($obligations[$generation])
			&& in_array($hash, $obligations[$generation]['hashes'], true),
			'the marker half of the obligation is still visible');
		$state = $this->armQueue($queue, $generation, array($hash => $staging));
		$jobs = erasedataDrainWorkerJobs($queue, $state,
			is_array($obligations) ? $obligations : array());
		$this->assertTrue(isset($jobs[$generation]['hashes'])
			&& in_array($hash, $jobs[$generation]['hashes'], true),
			'and so is the journal half');
		// Even with a final manifest of that exact generation standing beside a
		// record that is not `published`, the journal half stays visible: the
		// record's PHASE decides whether it is finished, never a `.list`.
		@file_put_contents($queue.'/'.$hash.'.'.$generation.'.4242.abc123.list',
			"a manifest somebody else published\n");
		$jobs = erasedataDrainWorkerJobs($queue, $state, array());
		$this->assertTrue(isset($jobs[$generation]['hashes'])
			&& in_array($hash, $jobs[$generation]['hashes'], true),
			'a final manifest never hides an unfinished record from the worker');
	}

	// Invariant 13 at the worker's door: markers that disagree among themselves
	// are corruption, and a journal record may never resolve the disagreement.
	//
	// erasedataPendingObligations() signals exactly this by setting the group
	// force to `false`, and the guard used to skip its own refusal in that one
	// case, so a force-1 request was erased and published carrying `"force":2`.
	// Force 2 is whole-base-path deletion: the $force_delete branch of
	// parseOneItem() in collector.php removes the base directory through
	// erasedataCompleteForcedDirectory(). So a member whose marker asked only
	// for its own files could have its base directory deleted. A disagreement
	// must never resolve, least of all upward.
	public function testDisagreeingMarkerForcesAreRefusedEvenWithAJournalRecord()
	{
		$this->reset();
		$invariant = 'a generation whose markers disagree on force is refused'
			.' whether or not a journal record exists: nothing is erased,'
			.' nothing is published and no force is resolved upward';
		if(!$this->requireApi(array('erasedataDrainWorkerRun()',
			'erasedataQueueRequest()', 'erasedataWriteDrainState()',
			'erasedataPendingObligations()', 'erasedataCollectPaths()'), $invariant))
			return;
		$queue = $this->queuePath();
		$first = $this->hash('A');
		$second = $this->hash('B');
		$generation = '0000000000000001';
		erasedataQueueRequest($queue, $first, 1, $generation);
		erasedataQueueRequest($queue, $second, 2, $generation);
		$obligations = erasedataPendingObligations($queue);
		$this->assertTrue(is_array($obligations) && isset($obligations[$generation])
			&& $obligations[$generation]['force'] === false,
			'the queue really reports the disagreement as a false group force');
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$staging = array();
		foreach(array($first, $second) as $hash)
		{
			// Staged as the JOURNAL claims -- force 2 -- so every gate below
			// the force guard passes and only the guard itself can refuse.
			$content = ErasedataManifestCodec::encode($hash,
				erasedataCollectPaths($hash), 2);
			$staging[$hash] = $queue.'/'.$hash.'.'.$generation.'.1.tmp';
			@file_put_contents($staging[$hash], is_string($content) ? $content : '');
		}
		$markers = array();
		foreach(array($first, $second) as $hash)
			$markers[$hash] = @file_get_contents(
				$queue.'/'.$hash.'.'.$generation.'.pending');
		$this->armQueue($queue, $generation, $staging, 'erase-started', 2);
		$this->eraseOk();
		// rTorrent still holds the force-1 member and answers for it, so the
		// disagreement is the only thing between this tick and deleting a whole
		// base path under a force nobody asked for.
		$this->probe(true, false, array($first));
		FileUtil::$log = array();
		erasedataDrainWorkerRun($this->dependencies());
		$this->assertEquals(0, count(rXMLRPCRequest::$erased),
			'a force nobody agreed on erases nothing');
		$this->assertEquals(array(), glob($queue.'/*.list'),
			'and publishes nothing under it');
		foreach(array($first, $second) as $hash)
			$this->assertEquals($markers[$hash],
				@file_get_contents($queue.'/'.$hash.'.'.$generation.'.pending'),
				'every marker is retained byte for byte, including the force-1 one');
		$classified = false;
		foreach(FileUtil::$log as $line)
			if(strpos($line, 'force-disagreement') !== false
				&& strpos($line, $generation) !== false)
				$classified = true;
		$this->assertTrue($classified,
			'and the refusal is classified against the generation it refused');
	}

	public function testAStaleWorkerOwnerIsClassifiedWithoutBreakingItsLock()
	{
		$this->reset();
		$invariant = 'a hung worker owner yields a bounded classified stale-worker'
			.' consequence; it never force-breaks the flock and never consumes'
			.' the jobs the owner holds';
		if(!$this->requireApi(array('erasedataDrainWorkerRun()',
			'erasedataQueueRequest()'), $invariant))
			return;
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		erasedataQueueRequest($queue, $hash, 1, '0000000000000001');
		$staging = $queue.'/'.$hash.'.0000000000000001.1.tmp';
		@file_put_contents($staging, "staged\n");
		$holder = ErasedataTestProcess::start(
			erasedataTestLockHolderCommand($queue.'/.drain-worker.lock', 4.0));
		$this->assertTrue($holder->started(), 'the hung owner really starts');
		$acquired = '';
		for($wait = 0; $wait < 200 && $acquired === ''; $wait++)
		{
			$holder->pump();
			if(strpos($holder->out, 'held') !== false)
				$acquired = 'held';
			else
				usleep(20000);
		}
		$this->assertEquals('held', $acquired, 'and really holds the worker lock');
		FileUtil::$log = array();
		$began = microtime(true);
		erasedataDrainWorkerRun($this->dependencies());
		$took = microtime(true) - $began;
		$holder->reap();
		$this->assertTrue($took < 3.0,
			'worker admission is nonblocking: took '.round($took, 2).'s');
		$this->assertTrue(is_file($staging),
			'the hung owner\'s job is not consumed by the competitor');
		$this->assertEquals(0, count(rXMLRPCRequest::$erased),
			'and nothing is erased behind the owner\'s back');
		$classified = false;
		foreach(FileUtil::$log as $line)
			if(strpos($line, 'worker-busy') !== false || strpos($line, 'stale-worker') !== false)
				$classified = true;
		$this->assertTrue($classified,
			'the refusal is a bounded classified consequence rather than silence');
	}

	// Invariant 5 at the child's own door, and invariant 3's exactness with it.
	//
	// Nothing else in this file fails when a child acknowledges a generation it
	// was not started for, or acknowledges on another user's queue: every other
	// worker case runs on a queue this user owns, so a child that ignored the
	// user binding altogether passed all of them.
	public function testTheDrainChildRefusesAWrongUserAndAcknowledgesItsExactGeneration()
	{
		$this->reset();
		$invariant = 'a drain child refuses a queue armed for another user --'
			.' including when its own user is the empty one -- and otherwise'
			.' acknowledges the EXACT generation the durable state carries,'
			.' never a computed or remembered one';
		if(!$this->requireApi(array('erasedataDrainWorkerRun()',
			'erasedataReadDrainState()', 'erasedataWriteDrainState()',
			'erasedataQueueRequest()'), $invariant))
			return;
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		$generation = '000000000000002a';
		$state = array(
			'version' => 1, 'user' => 'somebody-else',
			'generation' => $generation, 'acknowledged' => '0000000000000000',
			'phase' => 'armed', 'journal' => array(), 'diagnostics' => array());
		$this->assertTrue(erasedataWriteDrainState($queue, $state) === true,
			'the queue really is armed for another user');
		erasedataQueueRequest($queue, $hash, 1, $generation);
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		// rTorrent still holds it, so nothing but the user binding stands
		// between these ticks and a destructive call.
		$this->probe(true, false, array($hash));
		FileUtil::$log = array();
		// (a) The empty user is a real user with a real key, and this queue is
		// not its queue. It refuses for the mismatch, not for emptiness.
		$this->assertTrue(erasedataDrainWorkerRun(
			$this->dependencies(array('user' => ''))) === false,
			'the empty user refuses a whole tick over somebody else\'s queue');
		$observed = erasedataReadDrainState($queue);
		$this->assertEquals('0000000000000000',
			is_array($observed) && isset($observed['acknowledged'])
				? $observed['acknowledged'] : null,
			'and acknowledges nothing');
		// (b) The wrong user: this queue belongs to somebody else.
		$this->assertTrue(erasedataDrainWorkerRun($this->dependencies()) === false,
			'a queue armed for another user refuses the tick');
		$observed = erasedataReadDrainState($queue);
		$this->assertEquals('somebody-else',
			is_array($observed) && isset($observed['user']) ? $observed['user'] : null,
			'and the other user keeps their queue');
		$this->assertEquals('0000000000000000',
			is_array($observed) && isset($observed['acknowledged'])
				? $observed['acknowledged'] : null,
			'with no acknowledgement written on their behalf');
		$this->assertEquals(0, count(rXMLRPCRequest::$erased),
			'neither refusal admits a single destructive call');
		$this->assertEquals(1, count(glob($queue.'/'.$hash.'.*.pending')),
			'and the obligation is retained exactly as it was found');
		// (c) The right user: the acknowledgement is the exact durable
		// generation, not the zero it started from and not the next one.
		$state['user'] = 'rutorrent';
		$this->assertTrue(erasedataWriteDrainState($queue, $state) === true,
			'the queue is re-armed for this child\'s own user');
		erasedataDrainWorkerRun($this->dependencies());
		$observed = erasedataReadDrainState($queue);
		$this->assertEquals($generation,
			is_array($observed) && isset($observed['acknowledged'])
				? $observed['acknowledged'] : null,
			'the child acknowledged exactly the generation the state carried');
	}

	// Invariant 15 at the acknowledgement: an I/O failure there is terminal for
	// the tick. The acknowledgement is what a blocked producer is waiting for
	// and it is written BEFORE worker admission, so a child that went on
	// without it would take the worker lock, walk the queue and erase on a
	// binding no producer had been released for.
	public function testAnUnreadableOrUnwritableAcknowledgementAdmitsNoWorkerWork()
	{
		$this->reset();
		$invariant = 'a drain child whose acknowledgement cannot be read back or'
			.' made durable refuses before worker admission: nothing is erased,'
			.' nothing is published and every obligation is retained';
		if(!$this->requireApi(array('erasedataDrainWorkerRun()',
			'erasedataQueueRequest()', 'erasedataPendingObligations()'), $invariant))
			return;
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		$generation = '0000000000000001';
		erasedataQueueRequest($queue, $hash, 1, $generation);
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		$this->probe(true, false, array($hash));
		// (a) Present but unreadable is NOT the "never armed" default: a child
		// that took it for one would arm a second schedule over an obligation
		// it could not see.
		@mkdir($queue.'/'.ERASEDATA_DRAIN_STATE_NAME, 0777, true);
		FileUtil::$log = array();
		$this->assertTrue(erasedataDrainWorkerRun($this->dependencies()) === false,
			'an unreadable durable state refuses the tick');
		$this->assertEquals(0, count(rXMLRPCRequest::$erased),
			'and erases nothing');
		$this->assertEquals(array(), glob($queue.'/'.$hash.'.*.list'),
			'and publishes nothing');
		$this->assertTrue(count(FileUtil::$log) > 0,
			'and says so rather than failing silently');
		@rmdir($queue.'/'.ERASEDATA_DRAIN_STATE_NAME);
		// (b) A readable state that cannot be republished. The first tick
		// creates the state and both pass locks, so what the read-only queue
		// directory takes away below is exactly the ability to WRITE the
		// acknowledgement, not the ability to open anything.
		erasedataDrainWorkerRun($this->dependencies());
		$before = @file_get_contents($queue.'/'.ERASEDATA_DRAIN_STATE_NAME);
		$this->assertTrue(is_string($before) && $before !== '',
			'the first tick left a readable durable state to fail on');
		if(testSkipUnlessPermissionsBite('that an acknowledgement which cannot be'
			.' made durable refuses the tick, admitting no destructive call and'
			.' retaining the obligation'))
			return;
		@chmod($queue, 0555);
		FileUtil::$denyDirectoryRepair = true;
		FileUtil::$log = array();
		$refused = erasedataDrainWorkerRun($this->dependencies());
		FileUtil::$denyDirectoryRepair = false;
		@chmod($queue, 0777);
		$this->assertTrue($refused === false,
			'an acknowledgement that cannot be made durable refuses the tick');
		$this->assertEquals($before,
			@file_get_contents($queue.'/'.ERASEDATA_DRAIN_STATE_NAME),
			'and leaves the durable state exactly as it found it');
		$this->assertEquals(0, count(rXMLRPCRequest::$erased),
			'no destructive call is admitted on a failed acknowledgement');
		$obligations = erasedataPendingObligations($queue);
		$this->assertTrue(is_array($obligations) && isset($obligations[$generation]),
			'and the obligation is retained for a later tick');
	}

	// Invariant 3 from the worker's side: the generation is the binding, and a
	// marker naming one this queue never armed binds nothing at all. A worker
	// that trusted the marker's own generation would erase on a schedule that
	// belongs to a state file somebody lost or replaced.
	public function testAStaleScheduleGenerationNeverAdmitsDestructiveWorkerWork()
	{
		$this->reset();
		$invariant = 'a marker naming a generation the durable state never armed'
			.' is retained and classified: nothing is staged, nothing is erased'
			.' and nothing is deleted on its behalf';
		if(!$this->requireApi(array('erasedataDrainWorkerRun()',
			'erasedataQueueRequest()', 'erasedataWriteDrainState()',
			'erasedataPendingObligations()'), $invariant))
			return;
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		$stale = '00000000000000ff';
		$this->assertTrue(erasedataWriteDrainState($queue, array(
			'version' => 1, 'user' => 'rutorrent',
			'generation' => '0000000000000004', 'acknowledged' => '0000000000000004',
			'phase' => 'armed', 'journal' => array(),
			'diagnostics' => array())) === true,
			'the queue carries a durable generation of its own');
		erasedataQueueRequest($queue, $hash, 1, $stale);
		$marker = @file_get_contents($queue.'/'.$hash.'.'.$stale.'.pending');
		$this->assertTrue(is_string($marker) && $marker !== '',
			'the stale obligation is really recorded');
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		// rTorrent still holds it, so the generation is the only thing between
		// this tick and a destructive call.
		$this->probe(true, false, array($hash));
		FileUtil::$log = array();
		erasedataDrainWorkerRun($this->dependencies());
		$this->assertEquals(0, count(rXMLRPCRequest::$erased),
			'a generation the state never armed erases nothing');
		$this->assertEquals(array(), glob($queue.'/'.$hash.'.'.$stale.'.*.tmp'),
			'and stages nothing under it');
		$this->assertEquals($marker,
			@file_get_contents($queue.'/'.$hash.'.'.$stale.'.pending'),
			'the marker is left byte for byte where it was found');
		$obligations = erasedataPendingObligations($queue);
		$this->assertTrue(is_array($obligations) && isset($obligations[$stale]),
			'the obligation is retained rather than resolved');
		$classified = false;
		foreach(FileUtil::$log as $line)
			if(strpos($line, 'generation-unarmed') !== false
				&& strpos($line, $stale) !== false)
				$classified = true;
		$this->assertTrue($classified,
			'and the refusal names the generation it could not account for');
	}

	// Invariant 7's second half, which nothing else in this file could catch.
	//
	// Every other case has exactly one actor that wants the state lock, so an
	// implementation that took the state lock FIRST and then blocked on a hash
	// lock while still holding it passed all of them: the deadlock it creates
	// needs a second actor to become visible. Here a real competitor owns the
	// hash lock for four seconds, the real guarded child blocks on it, and a
	// third real process then asks for the state lock. If the worker were
	// holding it, that process would wait out the whole hash-lock hold -- and
	// in production the process waiting would be the producer, which takes the
	// hash locks first, so the two would deadlock outright rather than merely
	// stall.
	public function testTheDrainWorkerNeverHoldsTheStateLockWhileWaitingForAHashLock()
	{
		$this->reset();
		$invariant = 'while the drain worker blocks on a hash lock it holds no'
			.' state lock, so another actor takes the state lock at once';
		$mirror = $this->mirror('state-lock-order');
		$mirror->scriptRpc($this->drainScript());
		$mirror->shortenAcknowledgementWait();
		$producer = $this->actionDoor($mirror, array($this->hash('A')), 1);
		$producer->wait(30);
		$producer->reap();
		$queued = glob($mirror->listPath.'/*.pending');
		$this->assertTrue(count($queued) > 0,
			'the producer left an obligation for the worker to block on');
		$hold = 4.0;
		$hashHolder = ErasedataTestProcess::start(erasedataTestLockHolderCommand(
			$mirror->listPath.'/'.$this->hash('A').'.lock', $hold));
		$this->assertTrue($hashHolder->started(), 'the hash-lock competitor starts');
		$this->waitForLock($hashHolder, 'held hash lock');
		$heldAt = microtime(true);
		$worker = ErasedataTestProcess::start($mirror->drainCommand('drain'));
		// Long enough for the child to acknowledge and reach the hash lock, and
		// far short of the hold, so the observation below really lands inside
		// the window where the worker is waiting.
		usleep(800000);
		$began = microtime(true);
		$stateHolder = ErasedataTestProcess::start(erasedataTestLockHolderCommand(
			$mirror->listPath.'/'.ERASEDATA_DRAIN_STATE_LOCK_NAME, 0.2));
		$took = null;
		for($wait = 0; $wait < 200 && $took === null; $wait++)
		{
			$stateHolder->pump();
			if(strpos($stateHolder->out, 'held') !== false)
				$took = microtime(true) - $began;
			else
				usleep(20000);
		}
		$stillWaiting = (microtime(true) - $heldAt) < $hold;
		$state = $this->mirrorState($mirror);
		$this->runChildren(array('hash' => $hashHolder, 'worker' => $worker,
			'state' => $stateHolder), 40, $invariant);
		$this->assertTrue($stillWaiting,
			'the observation really happened while the hash lock was still held');
		$this->assertTrue($took !== null && $took < 1.5,
			'the state lock was free while the worker waited for the hash lock ('
				.($took === null ? 'never taken' : round($took, 2).'s').')');
		$this->assertTrue($this->stateAcknowledgesItsGeneration($state),
			'the worker acknowledged while the hash lock remained held');
	}

	// -- conservative restart, rearm and retirement (task 5) ----------------

	public function testRetirementRequiresAStableGenerationAndAProvenEmptyScan()
	{
		$this->reset();
		$invariant = 'retirement requires a stable generation plus a fresh'
			.' proven-empty scan of pending, staging, final, journal, malformed,'
			.' residue and unknown candidates';
		// Reachability, the same pin the worker carries: an internal runner
		// nothing in the shipped plugin calls is dead code, however completely
		// it is implemented and however thoroughly the tests call it directly.
		$callers = $this->productionCallers('erasedataRetirementScan($');
		$this->assertTrue(count($callers) > 0,
			'a production caller of erasedataRetirementScan() exists in the'
				.' shipped plugin, not only in the tests');
		if(!$this->requireApi(array('erasedataRetirementScan()'), $invariant))
			return;
		$queue = $this->queuePath();
		$scan = erasedataRetirementScan($this->dependencies());
		$this->assertTrue(is_array($scan) && isset($scan['empty']) && $scan['empty'] === true,
			'an empty queue scans as proven empty');
		$candidates = array(
			'pending' => $this->hash('A').'.0000000000000001.pending',
			'staging' => $this->hash('B').'.0000000000000001.1.tmp',
			'final' => $this->hash('C').'.0000000000000001.1.list',
			'malformed' => $this->hash('D').'.not-a-generation.list',
			'unknown' => 'something-nobody-parses',
			// Staging of an interrupted durable write: erasedataWriteDurableFile()
			// names it .<final name>.<token>.tmp, so it is dot-prefixed and
			// outside the manifest grammar entirely.
			'residue' => '.'.$this->hash('E').'.0000000000000001.1.list.abc123.tmp',
		);
		foreach($candidates as $class => $name)
		{
			// scandir, not glob: glob() does not match a dot-prefixed name, so
			// a glob cleanup would leave the residue entry standing for every
			// case after it and this loop would stop resetting its own fixture.
			foreach($this->queueEntries() as $stale)
				@unlink($queue.'/'.$stale);
			@file_put_contents($queue.'/'.$name, "x\n");
			$scan = erasedataRetirementScan($this->dependencies());
			$this->assertTrue(is_array($scan) && isset($scan['empty'])
				&& $scan['empty'] === false,
				'a surviving '.$class.' candidate refuses to scan as empty');
			$this->assertTrue(is_array($scan) && isset($scan['classes'][$class])
				&& $scan['classes'][$class] >= 1,
				'and is recognised as the '.$class.' class');
		}
		foreach($this->queueEntries() as $stale)
			@unlink($queue.'/'.$stale);
		@mkdir($queue.'/unreadable', 0700, true);
		@file_put_contents($queue.'/unreadable/'.$this->hash('E').'.0000000000000001.pending', 'x');
		@chmod($queue.'/unreadable', 0500);
		$scan = erasedataRetirementScan($this->dependencies());
		$this->assertTrue(is_array($scan) && isset($scan['empty']) && $scan['empty'] === false,
			'read or parse uncertainty means retain and diagnose, not declare empty');
		@chmod($queue.'/unreadable', 0700);
	}

	// The other two halves of invariant 10, which the scan-only case above
	// leaves to an implementation's discretion.
	//
	// "Provably empty" and "stable generation" are INDEPENDENT licences and
	// each one alone makes a retirement wrong. A queue whose newest admission
	// no started child has picked up yet holds no file and is about to; a queue
	// nobody could read is not empty, it is unknown. Nothing else in this file
	// fails when either guard is deleted: every other retirement case is
	// already stable, readable and non-empty.
	public function testRetirementRefusesEveryUncertaintyAndEveryUnacknowledgedGeneration()
	{
		$this->reset();
		$invariant = 'retirement refuses a generation no started child has'
			.' acknowledged, a registration still in flight, a queue directory'
			.' it could not read and a durable state it could not parse, and'
			.' sends no schedule_remove for any of them';
		if(!$this->requireApi(array('erasedataRetirementRun()',
			'erasedataRetirementScan()', 'erasedataWriteDrainState()',
			'erasedataReadDrainState()'), $invariant))
			return;
		$queue = $this->queuePath();
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true, 'val' => array(0));
		// (a) A queue nobody ever armed. It is empty, its default state is
		// stable and disarmed, and every other guard in this function is
		// satisfied by it -- so without the one that asks whether a generation
		// was ever admitted, a tick that starts a hair before a producer would
		// take away the schedule that producer is about to make. The paired
		// subprocess race cannot evidence this: the drain child never wins the
		// start by enough for it to show, which is exactly why it is pinned
		// here in process instead.
		$this->assertEquals(array(), $this->queueEntries(),
			'the queue was never armed and holds nothing at all');
		$this->assertTrue($this->retire() === false,
			'a queue no generation was ever admitted on retires nothing');
		$this->assertEquals(0, count($this->scheduleRecords('schedule_remove')),
			'and sends no schedule_remove for a schedule nobody ever registered');
		$state = array(
			'version' => 1, 'user' => 'rutorrent',
			'generation' => '0000000000000005', 'acknowledged' => '0000000000000004',
			'phase' => 'armed', 'journal' => array(), 'diagnostics' => array());
		// (b) The queue holds no candidate at all, so the acknowledgement is
		// the ONLY thing between this call and a removal.
		$this->assertTrue(erasedataWriteDrainState($queue, $state) === true,
			'the queue carries an admission no child has acknowledged yet');
		$this->assertTrue($this->retire() === false,
			'a generation no started guarded child has acknowledged never retires');
		$this->assertEquals(0, count($this->scheduleRecords('schedule_remove')),
			'and no schedule_remove is sent for it');
		$after = erasedataReadDrainState($queue);
		$this->assertEquals('armed',
			is_array($after) && isset($after['phase']) ? $after['phase'] : null,
			'and the durable arm is left exactly as it was found');
		// (c) A registration still in flight is the same admission one step
		// earlier: the producer has not even been told whether it has a
		// schedule yet.
		$state['acknowledged'] = '0000000000000005';
		$state['phase'] = 'arming';
		$this->assertTrue(erasedataWriteDrainState($queue, $state) === true,
			'the queue carries a registration still in flight');
		$this->assertTrue($this->retire() === false,
			'an arm still in flight never retires');
		$this->assertEquals(0, count($this->scheduleRecords('schedule_remove')),
			'and sends no schedule_remove either');
		// (d) A durable state nobody can parse. The markers are not the state,
		// and an unreadable state is not an empty one.
		$state['phase'] = 'armed';
		$this->assertTrue(erasedataWriteDrainState($queue, $state) === true,
			'the queue is stable and drained again');
		$scan = erasedataRetirementScan($this->dependencies());
		$this->assertTrue(is_array($scan) && isset($scan['empty'])
			&& $scan['empty'] === true,
			'the control files of a drained queue are not candidates');
		@file_put_contents($queue.'/'.ERASEDATA_DRAIN_STATE_NAME, '{"version":1,"phase":');
		$scan = erasedataRetirementScan($this->dependencies());
		$this->assertTrue(is_array($scan) && isset($scan['empty'], $scan['unreadable'])
			&& $scan['empty'] === false && $scan['unreadable'] === true,
			'a durable state nobody can parse scans as unknown, never as empty');
		$this->assertTrue($this->retire() === false,
			'and retirement refuses on it');
		$this->assertEquals(0, count($this->scheduleRecords('schedule_remove')),
			'with no schedule_remove sent on a state it could not read');
		// (e) A queue directory this process is not allowed to read at all.
		if(testSkipUnlessPermissionsBite('that a queue directory which cannot be'
			.' read scans as unknown rather than empty, and that retirement'
			.' refuses on it instead of sending schedule_remove'))
			return;
		$this->assertTrue(erasedataWriteDrainState($queue, $state) === true,
			'the durable state is restored');
		@chmod($queue, 0000);
		$scan = erasedataRetirementScan($this->dependencies());
		$refused = $this->retire();
		@chmod($queue, 0777);
		$this->assertTrue(is_array($scan) && isset($scan['empty'], $scan['unreadable'])
			&& $scan['empty'] === false && $scan['unreadable'] === true,
			'a queue directory that cannot be read scans as unknown, never as empty');
		$this->assertTrue($refused === false,
			'and retirement refuses on a permission failure rather than declaring it empty');
		$this->assertEquals(0, count($this->scheduleRecords('schedule_remove')),
			'with no schedule_remove sent on a queue nothing could read');
	}

	public function testRetirementWritesSettledThenDisarmedBeforeMappedScheduleRemove()
	{
		$this->reset();
		$invariant = 'durable settled then disarmed are written FIRST; only'
			.' after both writes succeed is mapped schedule_remove called';
		$callers = $this->productionCallers('erasedataRetirementRun($');
		$this->assertTrue(count($callers) > 0,
			'a production caller of erasedataRetirementRun() exists in the'
				.' shipped plugin, not only in the tests');
		if(!$this->requireApi(array('erasedataRetirementRun()',
			'erasedataReadDrainState()', 'erasedataWriteDrainState()'), $invariant))
			return;
		$queue = $this->queuePath();
		$state = array(
			'version' => 1, 'user' => 'rutorrent',
			'generation' => '0000000000000004', 'acknowledged' => '0000000000000004',
			'phase' => 'armed', 'journal' => array(), 'diagnostics' => array());
		erasedataWriteDrainState($queue, $state);
		$phaseAtRemoval = null;
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true, 'val' => array(0),
			'callback' => function($commands) use ($queue, &$phaseAtRemoval)
			{
				$observed = erasedataReadDrainState($queue);
				$phaseAtRemoval = is_array($observed) && isset($observed['phase'])
					? $observed['phase'] : null;
			});
		$this->assertTrue($this->retire() === true,
			'a stable empty generation retires');
		$this->assertEquals('disarmed', $phaseAtRemoval,
			'the durable disarmed write happened before schedule_remove was sent');
		$removals = $this->scheduleRecords('schedule_remove');
		$this->assertEquals(1, count($removals),
			'exactly one mapped schedule_remove is sent');
		$this->assertEquals('erasedata-drainrutorrent',
			count($removals) ? $removals[0]['key'] : null,
			'and it names the exact drain key, never the periodic collector key');
	}

	public function testRetirementRefusalIsVisibleRetryableAndSelfHealing()
	{
		$this->reset();
		$invariant = 'a retained, changed or unknown candidate refuses'
			.' retirement visibly and retryably, and a schedule_remove fault'
			.' leaves a state every restart converges from';
		if(!$this->requireApi(array('erasedataRetirementRun()',
			'erasedataReadDrainState()', 'erasedataWriteDrainState()',
			'erasedataQueueRequest()'), $invariant))
			return;
		$queue = $this->queuePath();
		$state = array(
			'version' => 1, 'user' => 'rutorrent',
			'generation' => '0000000000000004', 'acknowledged' => '0000000000000004',
			'phase' => 'armed', 'journal' => array(), 'diagnostics' => array());
		erasedataWriteDrainState($queue, $state);
		erasedataQueueRequest($queue, $this->hash('A'), 1, '0000000000000005');
		FileUtil::$log = array();
		$this->assertTrue($this->retire() === false,
			'a pending obligation refuses retirement');
		$this->assertEquals(0, count($this->scheduleRecords('schedule_remove')),
			'and no schedule_remove is sent');
		// Visibility is asserted on the route production really takes.
		// erasedataRetirementRun() reports nothing itself, by design: the one
		// production caller, erasedataDrainWorkerRun(), hands its notes to
		// erasedataDrainReportGroup() under the 'retire' group so a refusal
		// that has not changed since the last tick stays silent. Reading the
		// log after calling retirement alone would pin a self-reporting wrapper
		// that no production path ever took.
		FileUtil::$log = array();
		$this->assertTrue(erasedataDrainWorkerRun($this->dependencies()) !== false,
			'a production tick over the same refusing queue runs to a decision');
		$visible = false;
		foreach(FileUtil::$log as $line)
			if(strpos($line, 'retire-refused') !== false)
				$visible = true;
		$this->assertTrue($visible, 'the refusal is visible rather than silent');
		$this->assertEquals(0, count($this->scheduleRecords('schedule_remove')),
			'and the tick still sends no schedule_remove');
		foreach(glob($queue.'/*.pending') as $stale)
			@unlink($stale);
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true, 'fault' => true,
			'faultString' => 'refused');
		$this->assertTrue($this->retire() === false,
			'a schedule_remove fault is reported as failure');
		$after = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($after) && isset($after['phase'])
			&& $after['phase'] === 'disarmed',
			'the durable settled/disarmed writes stand, so a later tick can retry the removal');
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true, 'val' => array(0));
		$this->assertTrue($this->retire() === true,
			'and the next attempt self-heals');
	}

	public function testStartupRecoveryRearmsFromDurableStateNotFromMarkersAlone()
	{
		$this->reset();
		$invariant = 'startup recovery inspects the same durable state and all'
			.' candidate classes as the worker; it never infers safety from'
			.' *.pending alone, and corrupt state fails closed';
		$this->sourceHas('init.php', 'erasedataRearmDrainSchedule',
			'init.php re-arms the drain schedule at startup');
		if(!$this->requireApi(array('erasedataRearmDrainScheduleRun()',
			'erasedataWriteDrainState()'), $invariant))
			return;
		$queue = $this->queuePath();
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0));
		erasedataWriteDrainState($queue, array(
			'version' => 1, 'user' => 'rutorrent',
			'generation' => '0000000000000004', 'acknowledged' => '0000000000000003',
			'phase' => 'armed', 'diagnostics' => array(),
			'journal' => array('0000000000000004' => array(
				'phase' => 'prepared', 'force' => 1,
				'hashes' => array($this->hash('A')),
				'staging' => array($this->hash('A') => array(
					'path' => $queue.'/'.$this->hash('A').'.0000000000000004.1.tmp',
					'dev' => 1, 'ino' => 1))))));
		$this->assertTrue(erasedataRearmDrainScheduleRun($this->dependencies()) === true,
			'an unfinished durable obligation re-arms the exact per-user schedule');
		$registrations = $this->scheduleRecords('schedule');
		$this->assertEquals(1, count($registrations), 'exactly one registration');
		$this->assertEquals('erasedata-drainrutorrent',
			count($registrations) ? $registrations[0]['key'] : null,
			'with the exact per-user drain key');
		rXMLRPCRequest::$scheduledCommands = array();
		@file_put_contents($queue.'/.drain-state', '{"version":1,"phase":');
		@file_put_contents($queue.'/'.$this->hash('B').'.0000000000000009.pending', "x\n");
		FileUtil::$log = array();
		$this->assertTrue(erasedataRearmDrainScheduleRun($this->dependencies()) === false,
			'a corrupt or partial state fails closed rather than trusting a marker');
		$this->assertEquals(0, count($this->scheduleRecords('schedule')),
			'and re-arms nothing on the strength of a *.pending file alone');
	}

	// The one schedule guard the real-daemon evidence names outright.
	//
	// Re-registering an existing key succeeds SILENTLY on rTorrent 0.16.21 and
	// restarts its countdown (CommandScheduler replaces the entry), and no
	// return value anywhere reveals that it did. The aligned start resolves most
	// re-registrations of one key to the same absolute instant, but one made
	// during the fire second itself resolves a whole slot later, so a producer
	// stream that re-registered on every request could keep pushing the tick
	// past the acknowledgement a public door is blocked on, with every call
	// reporting success.
	public function testALiveDrainScheduleIsNeverReRegisteredOrPostponed()
	{
		$this->reset();
		$invariant = 'an already armed drain schedule is left exactly as it is:'
			.' neither a second producer nor startup recovery re-registers it,'
			.' because a re-registration restarts rTorrent\'s countdown and'
			.' reports success either way';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()',
			'erasedataRearmDrainScheduleRun()', 'erasedataWriteDrainState()'),
			$invariant))
			return;
		$queue = $this->queuePath();
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		$this->probe(true, false, array($this->hash('A')));
		$this->acknowledgeOnRegistration($queue);
		// (a) The first admission really arms, so what follows is measured
		// against a schedule that exists rather than against one nobody made.
		erasedataRemovalAdmissionRun($this->dependencies(), array($this->hash('A')), 1);
		$this->assertEquals(1, count($this->scheduleRecords('schedule')),
			'the first admission arms the drain schedule exactly once');
		// (b) A second producer over the same live schedule.
		rXMLRPCRequest::$scheduledCommands = array();
		erasedataRemovalAdmissionRun($this->dependencies(), array($this->hash('B')), 1);
		$this->assertEquals(0, count($this->scheduleRecords('schedule')),
			'a second admission on a live schedule re-registers nothing');
		// (c) Startup recovery over a queue that owes nothing at all. There is
		// no schedule to lose, so arming one would only restart a countdown.
		foreach(glob($queue.'/*') as $entry)
			if(is_file($entry))
				@unlink($entry);
		$this->assertTrue(erasedataWriteDrainState($queue, array(
			'version' => 1, 'user' => 'rutorrent',
			'generation' => '0000000000000004', 'acknowledged' => '0000000000000004',
			'phase' => 'armed', 'journal' => array(),
			'diagnostics' => array())) === true,
			'the queue carries a stable armed generation with nothing owed');
		rXMLRPCRequest::$scheduledCommands = array();
		$this->assertTrue(erasedataRearmDrainScheduleRun($this->dependencies()) === true,
			'startup recovery over a queue that owes nothing succeeds');
		$this->assertEquals(0, count($this->scheduleRecords('schedule')),
			'and arms nothing, so no live countdown anywhere is restarted');
	}

	public function testDoneRemovesThePeriodicKeyAndLeavesTheDrainKeyAlone()
	{
		$this->reset();
		$invariant = 'done.php removes only the periodic erasedata<User> key and'
			.' never the erasedata-drain<User> key';
		$mirror = $this->mirror('done-key');
		$mirror->scriptRpc(array(
			'schedule' => array('ok' => true, 'val' => array(0)),
			'schedule_remove' => array('ok' => true, 'val' => array(0)),
			'd.get_base_path' => array('val' => array('/d/name', 1, '/d/name/a.bin')),
			'd.set_custom5' => array('val' => array('', '', '')),
		));
		$mirror->shortenAcknowledgementWait();
		$producer = ErasedataTestProcess::start($mirror->eraseCommand($this->hash('A'), '1'));
		$producer->wait(20);
		$producer->reap();
		$armed = array();
		foreach($mirror->scheduleLog() as $record)
			if($record['family'] === 'schedule')
				$armed[] = $record['key'];
		$this->assertTrue(in_array('erasedata-drainrutorrent', $armed, true),
			'the public door armed the drain key before done.php runs');
		$runner = $mirror->writeRunner('done-runner',
			"require(".var_export($mirror->pluginDir.'/done.php', true).");\n");
		$child = ErasedataTestProcess::start($mirror->php($runner));
		$this->assertTrue($child->wait(20), 'the real done.php runs to completion');
		$child->reap();
		$removals = $mirror->scheduleLog();
		$keys = array();
		foreach($removals as $record)
			if($record['family'] === 'schedule_remove')
				$keys[] = $record['key'];
		$this->assertEquals(array('erasedatarutorrent'), $keys,
			'done.php removes exactly the periodic collector key');
		$this->assertTrue(!in_array('erasedata-drainrutorrent', $keys, true),
			'and never the drain key');
	}

	// -- real paired subprocess cases ---------------------------------------
	//
	// Each case below starts REAL child processes with a bounded budget, keeps
	// a direct handle on every one of them and reaps every one of them however
	// the case ends. No child is ever launched from inside a registration RPC:
	// the schedule fake records, and the test plays the scheduler itself.

	private function actionDoor($mirror, array $hashes, $force, $name = 'action-door')
	{
		return(ErasedataTestProcess::start(
			$mirror->actionCommand($hashes, $force, $name), $mirror->pluginDir));
	}

	// A producer nobody answers waits ERASEDATA_DRAIN_ACK_TIMEOUT, 11 s of
	// production's choosing, before it gives up and leaves its obligation
	// queued. Cases whose subject is what happens AFTER that -- a held lock,
	// a racing worker, a restart -- would otherwise pay those 11 s as setup.
	// The mirror can hand its children a shorter wait: only its length changes,
	// never what the producer does at either end of it.
	public function testAShortenedWaitReachesAChildUnderARootWithIniMetacharacters()
	{
		$this->reset();
		// The prepend option must also survive INI metacharacters in its path.
		$mirror = $this->mirror('double"quote${HOME}');
		$mirror->scriptRpc($this->drainScript());
		$mirror->shortenAcknowledgementWait(1.0);
		// This fixture injects no clock step. Measure with the wall clock used
		// by the scheduler-aligned side of the child's acknowledgement wait.
		$began = microtime(true);
		$producer = $this->actionDoor($mirror, array($this->hash('A')), 1);
		$finished = $producer->wait(20);
		$took = microtime(true) - $began;
		$code = $producer->reap();
		$this->assertTrue($finished, 'the unanswered producer finishes');
		// The HTTP door answers its caller and exits 0 whether or not anyone
		// acknowledged; a child that waited, queued and then died would leave
		// the same files behind, so the exit code is part of the verdict.
		$this->assertTrue($code === 0,
			'and finishes the way action.php finishes, exit 0, not by dying (exit '
				.var_export($code, true).', stderr: '.trim((string)$producer->err).')');
		// Both bounds in one assertion: the child really waited (it did not
		// skip the acknowledgement) and really stopped at the shortened wait
		// rather than at production's 11 s.
		$this->assertTrue($took >= 1.0 && $took < 4.0,
			'the producer waited the shortened 1 s and not the 11 s default ('
				.round($took, 2).'s)');
		$this->assertTrue(count(glob($mirror->listPath.'/*.pending')) > 0,
			'and it left its obligation queued exactly as an unanswered producer does');
		// The protocol state survives the shortened wait exactly as it survives
		// the full one: a readable document, the armed generation, and the zero
		// acknowledgement that says nobody answered.
		$state = $this->mirrorState($mirror);
		$this->assertTrue(is_array($state) && isset($state['generation'], $state['acknowledged'])
			&& $state['generation'] !== '0000000000000000'
			&& $state['acknowledged'] === '0000000000000000',
			'the generation is armed and nothing acknowledged it: the wait was shortened, not answered');
	}

	// The helper rejects an apostrophe in the mirror root and reports the
	// full path; this is the helper's restriction, not an INI limitation.
	public function testAShortenedWaitRefusesASingleQuoteInTheMirrorRoot()
	{
		$this->reset();
		$mirror = $this->mirror("apos'trophe");
		$refused = null;
		try
		{
			$mirror->shortenAcknowledgementWait();
		}
		catch(RuntimeException $e)
		{
			$refused = $e->getMessage();
		}
		$this->assertTrue($refused !== null && strpos($refused, $mirror->root) !== false
			&& strpos($refused, 'INI') !== false,
			'a mirror root with a single quote is refused, naming the root and the reason: '
				.var_export($refused, true));
	}

	// -- the test playing rTorrent's scheduler ------------------------------
	//
	// The mirror's RPC adapter RECORDS a schedule registration and does nothing
	// else, deliberately: a fake that started a child from inside the
	// registration RPC would be the RPC layer standing in for the scheduler,
	// and invariant 5 -- only a really started guarded child raises the
	// acknowledgement -- would then be carried by the fixture instead of by the
	// product. So the test reads the registration back out of the mirror's own
	// log and starts the REAL `update.php <user> drain` child itself, here, in
	// processes of its own that it holds a handle on and reaps.
	//
	// Cases that play the scheduler through runChildrenScheduled() or
	// acknowledgeOnce() keep the shipped 11 s acknowledgement timeout: their
	// subject is the real child answering inside that window. Other cases in
	// this section shorten an unanswered producer's wait as setup for a batch
	// race, held lock, retirement or rollback observation. The crash-after-arm
	// case exits before reaching the wait.

	// Is the per-user drain key registered right now? The LAST scheduling
	// record for the key decides, exactly as rTorrent's own table would: a
	// schedule_remove takes it away and a later registration brings it back.
	private function scheduleIsLive($mirror)
	{
		$live = false;
		foreach($mirror->scheduleLog() as $record)
		{
			if((string)$record['key'] !== 'erasedata-drain'.$mirror->user)
				continue;
			if($record['family'] === 'schedule_remove')
			{
				$live = false;
				continue;
			}
			// The registration has to carry a runnable update.php command, or
			// "the schedule fired" would be the test's own invention rather
			// than something the product asked rTorrent for.
			$live = ErasedataProductionMirror::scheduledChildCommand($record) !== false;
		}
		return($live);
	}

	// One scheduler tick: reap whatever finished, and start one real guarded
	// child when the drain key is live and fewer than $parallel are running.
	private function schedulerTick($mirror, array &$children, &$startedAt,
		$interval = 0.25, $parallel = 2)
	{
		foreach($children as $key => $child)
		{
			$child->pump();
			if(!$child->running())
			{
				$child->reap();
				unset($children[$key]);
			}
		}
		$now = microtime(true);
		if(count($children) >= $parallel
			|| ($startedAt !== null && ($now - $startedAt) < $interval)
			|| !$this->scheduleIsLive($mirror))
			return;
		$startedAt = $now;
		$children['tick-'.$this->schedulerTicks++] =
			ErasedataTestProcess::start($mirror->drainCommand('drain'));
	}

	// Run $processes to completion inside ONE budget while the test plays the
	// scheduler for the whole of it, and reap every child of either kind.
	private function runChildrenScheduled($mirror, array $processes, $budget, $label)
	{
		$ticks = array();
		$startedAt = null;
		$deadline = microtime(true) + $budget;
		$pending = $processes;
		while(count($pending) && microtime(true) < $deadline)
		{
			foreach($pending as $key => $process)
			{
				$process->pump();
				if(!$process->running())
					unset($pending[$key]);
			}
			if(!count($pending))
				break;
			$this->schedulerTick($mirror, $ticks, $startedAt);
			usleep(20000);
		}
		$finished = !count($pending);
		ErasedataTestProcess::reapAll($ticks);
		$codes = ErasedataTestProcess::reapAll($processes);
		$this->assertTrue($finished,
			$label.': every child finished inside the '.$budget.'s budget');
		return($codes);
	}

	// The scheduler, but only until the producer's generation really has been
	// acknowledged.
	//
	// A guarded child writes the acknowledgement BEFORE it tries the worker
	// lock, and then blocks on the per-hash lock the producer holds unbroken
	// from admission to the end. So every tick still alive at that instant is
	// parked behind a lock a LIVE producer owns and has changed nothing at all,
	// and reaping them there is what keeps the producer's own crash the subject
	// of the case rather than handing its generation to a recovery that would
	// legitimately cancel it.
	//
	// The acknowledgement it accepts is the EXACT generation the producer armed
	// -- read out of the same durable state -- rather than merely "not the zero
	// generation". Both are correct at the one call site, which carries a
	// single generation, but only the exact one stays correct if the helper is
	// ever reused for a case with two: a stale acknowledgement of an earlier
	// admission would otherwise read as this producer's.
	private function acknowledgeOnce($mirror, $producer, $budget)
	{
		$ticks = array();
		$startedAt = null;
		$deadline = microtime(true) + $budget;
		$acknowledged = false;
		$running = true;
		while(microtime(true) < $deadline)
		{
			$producer->pump();
			$running = $producer->running();
			// The acknowledgement is DURABLE and it is written by the child,
			// not by the producer, so it outlives the producer. Read the state
			// on this pass even when the producer has just exited: breaking
			// first would report "never acknowledged" for an ack that is
			// sitting on disk, whenever the write and the exit fall between
			// two iterations. That window is not load-dependent -- load only
			// makes landing in it likely.
			$state = $this->mirrorState($mirror);
			if($this->stateAcknowledgesItsGeneration($state))
			{
				$acknowledged = true;
				break;
			}
			if(!$running)
				break;
			$this->schedulerTick($mirror, $ticks, $startedAt);
			usleep(20000);
		}
		ErasedataTestProcess::reapAll($ticks);
		return($acknowledged);
	}

	private function runChildren(array $processes, $budget, $label)
	{
		$finished = ErasedataTestProcess::waitAll($processes, $budget);
		$codes = ErasedataTestProcess::reapAll($processes);
		$this->assertTrue($finished,
			$label.': every child finished inside the '.$budget.'s budget');
		return($codes);
	}

	private function waitForLock($holder, $label)
	{
		for($wait = 0; $wait < 250; $wait++)
		{
			$holder->pump();
			if(strpos($holder->out, 'held') !== false)
				return(true);
			usleep(20000);
		}
		$this->assertTrue(false, $label.': the competitor never took the lock');
		return(false);
	}

	private function mirrorState($mirror)
	{
		$raw = @file_get_contents($mirror->listPath.'/.drain-state');
		if(!is_string($raw) || $raw === '')
			return(null);
		$decoded = json_decode($raw, true);
		return(is_array($decoded) ? $decoded : null);
	}

	private function stateAcknowledgesItsGeneration($state)
	{
		return(is_array($state) && isset($state['generation'], $state['acknowledged'])
			&& $state['generation'] !== '0000000000000000'
			&& $state['acknowledged'] === $state['generation']);
	}

	private function mirrorErased($mirror)
	{
		$erased = array();
		foreach($mirror->rpcLog() as $request)
			foreach($request as $command)
				if(isset($command['command']) && $command['command'] === 'd.erase')
					$erased[] = is_array($command['params'])
						? implode(',', $command['params']) : (string)$command['params'];
		return($erased);
	}

	private function drainScript()
	{
		return(array(
			'schedule' => array('ok' => true, 'val' => array(0)),
			'schedule_remove' => array('ok' => true, 'val' => array(0)),
			'd.get_base_path' => array('val' => array('/d/name', 1, '/d/name/a.bin')),
			'd.set_custom5' => array('val' => array('', '', '')),
			'd.multicall' => array('val' => array()),
			'd.hash' => array('ok' => true, 'fault' => true,
				'faultString' => 'invalid parameters: info-hash not found'),
		));
	}

	public function testInverseBatchesLeaveCanonicalJournalAndNeverEraseWithoutAck()
	{
		$this->reset();
		$invariant = 'two public-door batches over the same hashes in inverse'
			.' order both complete on the no-ack path, leave canonical'
			.' sorted journal entries, and erase nothing without acknowledgement';
		$mirror = $this->mirror('inverse-batch');
		$mirror->scriptRpc($this->drainScript());
		$mirror->shortenAcknowledgementWait();
		$hashes = array($this->hash('A'), $this->hash('B'), $this->hash('C'));
		$children = array(
			'forward' => $this->actionDoor($mirror, $hashes, 1, 'door-forward'),
			'inverse' => $this->actionDoor($mirror, array_reverse($hashes), 1, 'door-inverse'),
		);
		$codes = $this->runChildren($children, 30, $invariant);
		$outcomes = array();
		foreach($children as $name => $child)
		{
			$this->assertTrue(isset($codes[$name]) && $codes[$name] === 0
				&& trim((string)$child->err) === '',
				$name.' door exits normally without stderr');
			$outcomes[] = trim((string)$child->out);
		}
		sort($outcomes, SORT_STRING);
		$expectedOutcomes = array('Deletion queue did not acknowledge the request.',
			'Deletion refused (rearm-refused). Check the server log.');
		sort($expectedOutcomes, SORT_STRING);
		$this->assertEquals($expectedOutcomes, $outcomes,
			'both doors report their admitted no-ack or rearm-refused outcome, never queue-unavailable');
		$state = $this->mirrorState($mirror);
		$this->assertTrue(is_array($state) && isset($state['journal'])
			&& is_array($state['journal']) && count($state['journal']) === 2,
			'both inverse batches leave their generation-bound journal entries');
		$sorted = true;
		if(is_array($state) && isset($state['journal']) && is_array($state['journal']))
			foreach($state['journal'] as $entry)
			{
				$recorded = isset($entry['hashes']) && is_array($entry['hashes'])
					? $entry['hashes'] : array();
				$expected = $recorded;
				sort($expected, SORT_STRING);
				if($recorded !== $expected || count($recorded) !== count(array_unique($recorded)))
					$sorted = false;
			}
		$this->assertTrue($sorted,
			'every journal record holds the canonical sorted unique hash set');
		$erased = $this->mirrorErased($mirror);
		$this->assertEquals(array(), $erased,
			'neither batch erases any hash without an acknowledgement');
	}

	public function testProducerAndWorkerRaceKeepOneFixedScheduleKey()
	{
		$this->reset();
		$invariant = 'a producer and the guarded worker running at the same time'
			.' keep one fixed schedule key: there is no'
			.' per-hash or per-invocation schedule fan-out; worker admission is'
			.' covered by testGlobalLockBypassWouldShowOverlapInSharedRecovery';
		$mirror = $this->mirror('producer-worker');
		$mirror->scriptRpc($this->drainScript());
		$children = array(
			'producer' => $this->actionDoor($mirror,
				array($this->hash('A'), $this->hash('B')), 1),
			'worker' => ErasedataTestProcess::start($mirror->drainCommand('drain')),
		);
		$this->runChildrenScheduled($mirror, $children, 30, $invariant);
		$keys = array();
		foreach($mirror->scheduleLog() as $record)
			if($record['family'] === 'schedule')
				$keys[] = $record['key'];
		$drain = array();
		foreach($keys as $key)
			if(strpos((string)$key, 'erasedata-drain') === 0)
				$drain[] = $key;
		$this->assertTrue(count($drain) > 0,
			'the producer really armed the drain schedule');
		$this->assertEquals(array('erasedata-drainrutorrent'), array_values(array_unique($drain)),
			'and only ever the one fixed per-user key');
		$this->assertTrue(count($drain) <= 2,
			'two hashes in one batch do not fan out into a schedule per hash: '
				.count($drain).' registrations');
		$state = $this->mirrorState($mirror);
		$this->assertTrue($this->stateAcknowledgesItsGeneration($state),
			'the real guarded child wrote a durable acknowledgement');
	}

	public function testWorkerAndRetirementRaceNeverRetireAJustAdmittedGeneration()
	{
		$this->reset();
		$invariant = 'retirement and a live producer in either order never'
			.' retire a generation whose obligation is still admitted';
		$mirror = $this->mirror('worker-retire');
		$mirror->scriptRpc($this->drainScript());
		// Which of the two reaches the queue first is settled in the first few
		// milliseconds, long before the producer's wait matters; the wait's
		// length only decides whether the worker-first order costs 11 s or 1 s.
		$mirror->shortenAcknowledgementWait();
		$children = array(
			'retirement' => ErasedataTestProcess::start($mirror->drainCommand('drain')),
			'producer' => $this->actionDoor($mirror, array($this->hash('A')), 1),
		);
		$this->runChildren($children, 30, $invariant);
		$removed = false;
		foreach($mirror->scheduleLog() as $record)
			if($record['family'] === 'schedule_remove'
				&& strpos((string)$record['key'], 'erasedata-drain') === 0)
				$removed = true;
		$state = $this->mirrorState($mirror);
		$outstanding = 0;
		if(is_array($state) && isset($state['journal']) && is_array($state['journal']))
			$outstanding = count($state['journal']);
		$pending = glob($mirror->listPath.'/*.pending');
		$published = glob($mirror->listPath.'/*.list');
		$this->assertTrue(is_array($state),
			'the race leaves readable durable state behind');
		// Without this the two guards below are satisfied by an implementation
		// that admits nothing and retires nothing.
		$this->assertTrue($outstanding > 0 || count($pending) > 0
			|| count($published) > 0 || count($this->mirrorErased($mirror)) > 0,
			'the producer really admitted its generation during the race');
		$this->assertTrue(!$removed || $outstanding === 0,
			'the drain schedule is only removed once no obligation is outstanding');
		$this->assertTrue(!$removed || count($pending) === 0,
			'and never while a pending marker survives');
		// The other half: once the queue really is empty and the generation
		// really is stable, a REAL guarded child must retire the schedule. An
		// implementation that defines retirement and never wires it into the
		// production tick fails here rather than passing every guard above by
		// never removing anything.
		foreach(glob($mirror->listPath.'/*') as $entry)
			if(basename($entry) !== '.drain-state' && is_file($entry))
				@unlink($entry);
		// A drained queue carrying a stable armed generation is the state
		// retirement exists for. The test authors it here for the same reason
		// testRetirementWritesSettledThenDisarmedBeforeMappedScheduleRemove
		// authors it in process: what is under test is what the child does
		// NEXT, not how the queue became empty.
		$settled = $this->mirrorState($mirror);
		if(is_array($settled) && isset($settled['generation']))
		{
			$settled['journal'] = array();
			$settled['diagnostics'] = array();
			$settled['acknowledged'] = $settled['generation'];
			$settled['phase'] = 'armed';
			@file_put_contents($mirror->listPath.'/.drain-state', json_encode($settled));
		}
		$retired = false;
		for($tick = 0; $tick < 3 && !$retired; $tick++)
		{
			$child = ErasedataTestProcess::start($mirror->drainCommand('drain'));
			$this->runChildren(array('retire-tick-'.$tick => $child), 30, $invariant);
			foreach($mirror->scheduleLog() as $record)
				if($record['family'] === 'schedule_remove'
					&& $record['key'] === 'erasedata-drain'.$mirror->user)
					$retired = true;
		}
		$this->assertTrue($retired,
			'a proven-empty queue with a stable generation really produces a'
				.' mapped schedule_remove on the exact drain key, from a real child');
	}

	public function testHeldSchedulerLockStillLetsTheChildAcknowledgeFirst()
	{
		$this->reset();
		$invariant = 'the drain tick acknowledges under the state lock before it'
			.' tries worker admission, so a held scheduler lock delays the work'
			.' but never the acknowledgement';
		$mirror = $this->mirror('held-scheduler');
		$mirror->scriptRpc($this->drainScript());
		$mirror->shortenAcknowledgementWait();
		$producer = $this->actionDoor($mirror, array($this->hash('A')), 1);
		$producer->wait(20);
		$producer->reap();
		$hold = 2.0;
		$holder = ErasedataTestProcess::start(
			erasedataTestLockHolderCommand($mirror->listPath.'/scheduler.lock', $hold));
		$this->waitForLock($holder, 'held scheduler lock');
		// The competitor releases at $heldAt + $hold. An acknowledgement seen
		// after that proves nothing, so the observation is timestamped and
		// compared with the release rather than with a poll budget that happens
		// to be the same length as the hold.
		$heldAt = microtime(true);
		$worker = ErasedataTestProcess::start($mirror->drainCommand('drain'));
		$acknowledgedAt = null;
		while($acknowledgedAt === null && microtime(true) < $heldAt + $hold)
		{
			$state = $this->mirrorState($mirror);
			if(is_array($state) && isset($state['acknowledged'])
				&& $state['acknowledged'] !== '0000000000000000')
				$acknowledgedAt = microtime(true);
			else
				usleep(20000);
		}
		$this->runChildren(array('holder' => $holder, 'worker' => $worker), 30,
			$invariant);
		$this->assertTrue($acknowledgedAt !== null
			&& ($acknowledgedAt - $heldAt) < $hold,
			'the child acknowledged its generation while the scheduler lock was'
				.' still held, '.($acknowledgedAt === null ? 'never'
					: round($acknowledgedAt - $heldAt, 2).'s')
				.' into a '.$hold.'s hold');
	}

	public function testHeldHashLockBlocksTheDrainAndTheBroadPassExitsImmediately()
	{
		$this->reset();
		$invariant = 'a held hash lock makes the drain worker wait blocking and'
			.' consume after the unlock, while the periodic broad pass keeps its'
			.' locks nonblocking and exits at once';
		$mirror = $this->mirror('held-hash');
		$mirror->scriptRpc($this->drainScript());
		$mirror->shortenAcknowledgementWait();
		$producer = $this->actionDoor($mirror, array($this->hash('A')), 1);
		$producer->wait(20);
		$producer->reap();
		// A broad pass that exits at once because it has nothing to skip is not
		// evidence of anything, so the obligation it must skip is pinned first.
		$queued = array_merge(glob($mirror->listPath.'/*.pending'),
			glob($mirror->listPath.'/*.tmp'));
		sort($queued, SORT_STRING);
		$this->assertTrue(count($queued) > 0,
			'the producer left an obligation for the broad pass to walk past');
		$holder = ErasedataTestProcess::start(
			erasedataTestLockHolderCommand(
				$mirror->listPath.'/'.$this->hash('A').'.lock', 2.0));
		$this->waitForLock($holder, 'held hash lock');
		$began = microtime(true);
		$broad = ErasedataTestProcess::start($mirror->drainCommand(''));
		$broadFinished = $broad->wait(20);
		$broadTook = microtime(true) - $began;
		$broad->reap();
		$after = array_merge(glob($mirror->listPath.'/*.pending'),
			glob($mirror->listPath.'/*.tmp'));
		sort($after, SORT_STRING);
		$this->assertTrue($broadFinished, 'the periodic broad pass finishes');
		$this->assertEquals($queued, $after,
			'the periodic broad pass consumed nothing behind the held hash lock');
		$began = microtime(true);
		$drain = ErasedataTestProcess::start($mirror->drainCommand('drain'));
		$drainFinished = $drain->wait(30);
		$drainTook = microtime(true) - $began;
		$this->runChildren(array('holder' => $holder, 'drain' => $drain), 30,
			$invariant);
		$this->assertTrue($drainFinished, 'the drain worker finishes');
		// One assertion, so neither half can be satisfied on its own by an
		// implementation where both passes simply do nothing.
		$this->assertTrue($broadTook < 1.5 && $drainTook >= 0.5,
			'the periodic broad pass keeps LOCK_NB and exits at once ('
				.round($broadTook, 2).'s) while the drain worker waits blocking'
				.' for the same lock ('.round($drainTook, 2).'s)');
		$state = $this->mirrorState($mirror);
		$this->assertTrue($this->stateAcknowledgesItsGeneration($state),
			'the drain worker acknowledged the armed generation');
		$this->assertEquals(array(), glob($mirror->listPath.'/*.pending'),
			'and it consumed its work after the unlock rather than giving up');
	}

	public function testAContinuousProducerDuringRetirementRetainsTheSchedule()
	{
		$this->reset();
		$invariant = 'a producer admitted during retirement is either included'
			.' durably or forces retirement to retain the schedule';
		$mirror = $this->mirror('continuous-producer');
		$mirror->scriptRpc($this->drainScript());
		$hashes = array();
		foreach(array('A', 'B', 'C', 'D', 'E', 'F') as $character)
			$hashes[] = $this->hash($character);
		$loop = $mirror->writeRunner('producer-loop',
			'$hashes = '.var_export($hashes, true).";\n"
			.'foreach($hashes as $hash)'."\n"
			.'{'."\n"
			."\t".'$output = array(); $code = 0;'."\n"
			."\t".'exec('.var_export(escapeshellarg(PHP_BINARY).' -d display_errors=0 -f '
				.escapeshellarg($mirror->pluginDir.'/erase.php').' -- ', true)
				.'.escapeshellarg($hash).\' 1 \'.escapeshellarg('
				.var_export($mirror->user, true).').\' 2>/dev/null\', $output, $code);'."\n"
			."\t".'usleep(120000);'."\n"
			.'}'."\n");
		$children = array(
			'producers' => ErasedataTestProcess::start($mirror->php($loop)),
			'retirement' => ErasedataTestProcess::start($mirror->drainCommand('drain')),
		);
		// The mirror has no scheduler of its own, and six serial erase.php
		// calls with nothing to acknowledge them wait out
		// ERASEDATA_DRAIN_ACK_TIMEOUT five times over -- 55s of honest waiting
		// against a 60s budget, before anything else in this case happens. The
		// answer is a scheduler, not a shorter timeout: the waiting is what is
		// under test.
		$this->runChildrenScheduled($mirror, $children, 60, $invariant);
		$state = $this->mirrorState($mirror);
		$this->assertTrue(is_array($state),
			'the continuous stream leaves readable durable state');
		$outstanding = is_array($state) && isset($state['journal'])
			&& is_array($state['journal']) ? count($state['journal']) : 0;
		$outstanding += count(glob($mirror->listPath.'/*.pending'));
		$removed = 0;
		$rearmed = 0;
		foreach($mirror->scheduleLog() as $record)
		{
			if(strpos((string)$record['key'], 'erasedata-drain') !== 0)
				continue;
			if($record['family'] === 'schedule_remove')
				$removed++;
			else
				$rearmed++;
		}
		$this->assertTrue($rearmed > 0,
			'the producers armed the drain schedule at least once');
		$this->assertTrue($outstanding === 0 || $removed === 0
			|| $rearmed > $removed,
			'an obligation admitted during retirement keeps or regains the schedule');
	}

	public function testCrashAfterArmLeavesADurableWakeAndARecoverableJob()
	{
		$this->reset();
		$invariant = 'a producer that dies after the arm and before staging'
			.' leaves a durable wake and a recoverable exact job, and erases'
			.' nothing';
		$mirror = $this->mirror('crash-after-arm');
		$script = $this->drainScript();
		$script['d.get_base_path'] = array('exit' => 9);
		$mirror->scriptRpc($script);
		$crashing = $this->actionDoor($mirror, array($this->hash('A')), 1);
		$crashing->wait(20);
		$crashCode = $crashing->reap();
		$this->assertTrue($crashCode !== 0,
			'the producer really died at the staging boundary (exit '.$crashCode.')');
		$this->assertEquals(array(), $this->mirrorErased($mirror),
			'a crash before staging erases nothing');
		$state = $this->mirrorState($mirror);
		$this->assertTrue(is_array($state) && isset($state['phase'])
			&& in_array($state['phase'], array('arming', 'armed'), true),
			'the durable wake survives the crash');
		$mirror->scriptRpc($this->drainScript());
		$recovery = ErasedataTestProcess::start($mirror->drainCommand('drain'));
		$this->runChildren(array('recovery' => $recovery), 30, $invariant);
		$after = $this->mirrorState($mirror);
		$this->assertTrue(is_array($after),
			'a real recovery child converges from the crashed state');
		$this->assertEquals(array(), $this->mirrorErased($mirror),
			'and still erases nothing it cannot account for');
	}

	public function testCrashAfterStagingLeavesExactRecoverableStagingAndNoErase()
	{
		$this->reset();
		$invariant = 'a producer that dies after staging and before the first'
			.' erase leaves its exact staging recoverable and no torrent erased';
		$mirror = $this->mirror('crash-after-staging');
		$script = $this->drainScript();
		// The death is OBSERVED, not merely scheduled. The producer announces
		// that it has reached the cut and waits there; the case reaps every
		// scheduler child it started for the acknowledgement first, and only
		// then lets the process die. Without that gate the producer dies at an
		// unknown moment just after the acknowledgement, the hash lock it held
		// is released, and a tick that was blocked on it runs a complete lawful
		// recovery -- clearing the very journal record this case then asks
		// about. That is what this case really failed on, 5 runs in 8 on a
		// loaded host, on the base commit as well as here.
		$script['d.set_custom5'] = array('exit' => 9, 'await' => 'crash-gate');
		$mirror->scriptRpc($script);
		// Residue and blockers this job does not own: neither the crash nor the
		// recovery that follows it may touch them (matrix row 8).
		$unrelated = array(
			$mirror->listPath.'/'.$this->hash('Z').'.ffffffffffffffff.42.tmp'
				=> "a later generation's staging\n",
			$mirror->listPath.'/'.$this->hash('Y').'.list'
				=> "a legacy manifest awaiting the collector\n",
			$mirror->listPath.'/not-a-candidate-at-all'
				=> "a file nobody parses\n",
		);
		foreach($unrelated as $path => $bytes)
			@file_put_contents($path, $bytes);
		$crashing = $this->actionDoor($mirror, array($this->hash('A')), 1);
		// The crash cut sits on d.set_custom5, which the producer only reaches
		// after a really started guarded child has acknowledged its generation.
		// With nothing playing the scheduler the producer waits out the ack
		// timeout and exits cleanly, and the case can never reach the boundary
		// it is named for.
		$this->assertTrue($this->acknowledgeOnce($mirror, $crashing, 20),
			'a real guarded child acknowledged the generation the producer armed');
		// acknowledgeOnce() has reaped every scheduler child by now, and the
		// producer is still alive and still holding its hash lock, so nothing
		// can consume the generation between its death and the reading below.
		$this->assertTrue($mirror->crashGateReached('crash-gate', 25),
			'the producer reached the erase boundary and is waiting there');
		$mirror->releaseCrashGate('crash-gate');
		$crashing->wait(20);
		$crashCode = $crashing->reap();
		$this->assertTrue(!$mirror->crashGateExpired('crash-gate'),
			'and it died because it was released, not because a wait ran out');
		$this->assertTrue($crashCode !== 0,
			'the producer really died at the erase boundary (exit '.$crashCode.')');
		$this->assertEquals(array(), $this->mirrorErased($mirror),
			'a crash before the first erase erases nothing');
		$state = $this->mirrorState($mirror);
		$bound = false;
		if(is_array($state) && isset($state['journal']) && is_array($state['journal']))
			foreach($state['journal'] as $entry)
				if(isset($entry['staging']) && is_array($entry['staging'])
					&& count($entry['staging']))
					$bound = true;
		$this->assertTrue($bound,
			'the journal binds the staging the crashed producer had already written');
		$mirror->scriptRpc($this->drainScript());
		$recovery = ErasedataTestProcess::start($mirror->drainCommand('drain'));
		$this->runChildren(array('recovery' => $recovery), 30, $invariant);
		$survivors = array_merge(glob($mirror->listPath.'/*.tmp'),
			glob($mirror->listPath.'/*.pending'), glob($mirror->listPath.'/*.list'));
		$this->assertTrue(count($survivors) > 0,
			'the obligation is still there for a later tick, not quietly dropped');
		foreach($unrelated as $path => $bytes)
			$this->assertTrue(@file_get_contents($path) === $bytes,
				'neither the crash nor the recovery touched the unrelated '
					.basename($path));
	}

	// RENAMED, from testCrashAfterPartialEraseKeepsEveryRemainingObligation.
	//
	// The old name claimed a producer that died during a partly executed
	// aggregate erase, and this case never drove one: nothing plays the
	// scheduler for its producer, so the producer waits out
	// ERASEDATA_DRAIN_ACK_TIMEOUT (shortened by the mirror: the rollback is
	// the same code at any length), takes the no-ack rollback
	// (`drain-no-ack ... consequence=torrents-retained-own-staging-rolled-back`)
	// and reaches no erase at all. What it really pins -- and pins well, which
	// is why it is kept rather than deleted -- is the disposition of every
	// member of a batch its producer left wholly owed, and a recovery that has
	// to honour each of them against its own payload.
	//
	// The boundary the old name promised is driven, with a real daemon on the
	// other side of the wire, by
	// testProducerDeathAfterFirstAggregateEraseKeepsEveryObligation() and
	// testDaemonDeathAfterFirstAggregateEraseRecoversRemainingMembers() below.
	public function testNoAckRollbackKeepsEveryRemainingObligationOfTheBatch()
	{
		$this->reset();
		$invariant = 'every hash of a batch its producer left owed ends in'
			.' exactly one lawful disposition after the recovery -- still owed'
			.' under a generation-bound record, or demonstrably carried out'
			.' against its OWN payload -- and nothing at all is erased, because'
			.' the daemon already answers that every member is gone';
		$mirror = $this->mirror('crash-partial-erase');
		$mirror->shortenAcknowledgementWait();
		// A REAL payload PER MEMBER, so that "this obligation was carried out"
		// is provable for one hash without any other hash's work licensing it.
		//
		// One SHARED payload directory is what this fixture used to build, and
		// it defeated the whole case: any single member's collection deleted the
		// one directory, so `discharged` degenerated to "this hash has no
		// .pending marker left" for the other two. A silent drop of one member
		// -- its marker discharged, nothing staged, published, journaled or
		// erased for it -- was then INVISIBLE: the method stayed 6 Passed / 0
		// Failed and the end state was byte-identical to a clean run, because
		// its siblings had removed the directory that was supposed to convict
		// it. The mirror's 'byParam' entry answers d.get_base_path per hash, so
		// each member now owns a directory only its own manifest can name.
		//
		// The default answer names a decoy nothing may ever collect: a manifest
		// built for a hash outside this batch would delete it, and the assertion
		// below says so.
		$payload = $this->dir.'/crash-payload';
		$hashes = array($this->hash('A'), $this->hash('B'), $this->hash('C'));
		$mine = array();
		$byHash = array();
		foreach($hashes as $hash)
		{
			$mine[$hash] = $payload.'/'.$hash.'/name';
			mkdir($mine[$hash], 0777, true);
			file_put_contents($mine[$hash].'/a.bin', 'payload of '.$hash);
			$byHash[$hash] = array('val' => array($mine[$hash], 1,
				$mine[$hash].'/a.bin'));
		}
		$decoy = $payload.'/unclaimed/name';
		mkdir($decoy, 0777, true);
		file_put_contents($decoy.'/a.bin', 'no member of this batch owns this');
		$base = array('val' => array($decoy, 1, $decoy.'/a.bin'),
			'byParam' => $byHash);
		$script = $this->drainScript();
		$script['d.get_base_path'] = $base;
		// The `cut` directive that used to sit here is GONE, with the "known
		// fixture gap" note that admitted it never fired. It could not: a cut
		// counts REQUESTS and fires before the request is written, while
		// erasedataEraseRequest() sends the whole batch as ONE aggregate, so a
		// cut of 2 needed a second erase request this flow never makes. Leaving
		// an inert directive in a case reads as coverage of a boundary nothing
		// reaches. The boundary itself is now driven where it belongs, by the
		// aggregate cases further down.
		$mirror->scriptRpc($script);
		$crashing = $this->actionDoor($mirror, $hashes, 1);
		$crashing->wait(20);
		$crashing->reap();
		$recoveryScript = $this->drainScript();
		$recoveryScript['d.get_base_path'] = $base;
		$mirror->scriptRpc($recoveryScript);
		$recovery = ErasedataTestProcess::start($mirror->drainCommand('drain'));
		$this->runChildren(array('recovery' => $recovery), 30, $invariant);
		$state = $this->mirrorState($mirror);
		$this->assertTrue(is_array($state),
			'the batch its producer left owed leaves readable durable state');
		// SUPERSEDED SHAPE. This used to demand that EVERY hash still be
		// OUTSTANDING after the recovery -- a <hash>.<16hex>.* artefact or a
		// journal entry naming it. That held only because the pre-repair drain
		// never collected: the manifests it published stayed on disk for the
		// ordinary periodic collector, retirement was refused with
		// `obligations-outstanding`, and the assertion was reading that stall.
		// The worker repair (P02, step (5) of erasedataDrainWorkerRun) completes
		// the lifecycle inside one tick -- publish, collect, discharge the
		// marker, retire -- so a fulfilled obligation legitimately leaves
		// nothing outstanding behind, and the journal record for it is pruned.
		//
		// What must remain true is the disposition, per hash: still owed under a
		// generation-bound record, or demonstrably carried out. The third state
		// -- marker, manifest, journal entry AND payload all gone -- is the real
		// defect this case exists to catch, and only a real payload can tell it
		// apart from a completed discharge.
		//
		// "Bound" still has to mean a GENERATION-BOUND record. Any leftover file
		// whose name happens to start with the hash -- a bare <hash>.list from an
		// older torrent, say -- accounts for nothing.
		$accounted = array();
		$carriedOut = array();
		foreach($hashes as $hash)
		{
			$bound = false;
			foreach(glob($mirror->listPath.'/'.$hash.'.*') as $candidate)
				if(preg_match('/^'.$hash.'\.[0-9a-f]{16}\./D', basename($candidate)) === 1)
					$bound = true;
			if(!$bound && is_array($state) && isset($state['journal'])
				&& is_array($state['journal']))
				foreach($state['journal'] as $generation => $entry)
					if(preg_match('/^[0-9a-f]{16}$/D', (string)$generation) === 1
						&& isset($entry['hashes']) && is_array($entry['hashes'])
						&& in_array($hash, $entry['hashes'], true))
						$bound = true;
			// Carried out: this hash owes no marker any more AND the payload
			// ITS OWN manifest named is really gone, file and base directory
			// both. Reading a shared directory here was what let one member's
			// collection discharge every member on paper.
			$discharged = !count(glob($mirror->listPath.'/'.$hash.'.*.pending'))
				&& !file_exists($mine[$hash].'/a.bin') && !is_dir($mine[$hash]);
			$this->assertTrue(($bound && !$discharged) || (!$bound && $discharged),
				'after the producer went away and the recovery ran, '.$hash.' is'
					.' in exactly one of the two lawful states -- still owed under a'
					.' generation-bound record, or demonstrably carried out with'
					.' its marker discharged and its payload deleted (bound='
					.($bound ? 'yes' : 'no').' discharged='
					.($discharged ? 'yes' : 'no').')');
			if($bound || $discharged)
				$accounted[] = $hash;
			if($discharged)
				$carriedOut[] = $hash;
		}
		$this->assertEquals($hashes, $accounted,
			'every hash of the batch is accounted for after the recovery, none of'
				.' them silently dropped');
		// The disposition above is satisfied by a recovery that did NOTHING --
		// three members still owed under the record their producer left is a
		// lawful end state, just not the one this fixture drives. So say what
		// this fixture really produces: every member's obligation is carried
		// out, its own payload deleted under its own manifest.
		$this->assertEquals($hashes, $carriedOut,
			'the recovery really carried out every obligation the producer left'
				.' owed, one payload per member, rather than satisfying the'
				.' disposition by leaving the whole batch retained');
		$this->assertTrue(file_get_contents($decoy.'/a.bin')
				=== 'no member of this batch owns this',
			'and it collected nothing outside the batch: the decoy payload the'
				.' unkeyed script answer names is untouched');
		// NOT a per-hash guard on the erase transcript. This fixture never
		// reaches a destructive call at all -- the recovery finds every member
		// ABSENT, so d.erase is never sent -- and a foreach over an empty erase
		// list is a test-shaped no-op that reads as coverage while it can never
		// fail. The observable fact is therefore asserted directly, and it is
		// the stronger one here: a d.erase in this transcript would be a
		// destructive call under a download rTorrent had already said was gone.
		// "Nothing is erased before its durable erase-started record" is pinned
		// where it can actually be observed, by
		// testTheFirstDestructiveCallFindsADurableEraseStartedRecord().
		$this->assertEquals(array(), $this->mirrorErased($mirror),
			'the recovery erased nothing: every member was already gone, so the'
				.' destructive call was never sent');
	}

	// -- observable death INSIDE one aggregate erase -------------------------
	//
	// erasedataEraseRequest() sends a whole batch as ONE aggregate request:
	// d.set_custom5 + d.delete_tied + d.erase per member, nine commands for
	// three hashes. Every boundary this harness could express before was a
	// boundary between REQUESTS, and the mirror's `cut` fires before the
	// request is even written, so neither "the producer died between two
	// executed erases" nor "the daemon stopped between two executed erases"
	// could be reached at all -- the case named for the first of them in fact
	// drove a no-ack rollback, and says so above.
	//
	// The three cases below drive those boundaries with a REAL second process
	// on the daemon side (AggregateEraseFixture.php) that applies the commands
	// of one request one at a time against a presence table of its own. Two
	// things they are careful to keep apart, because a review found the harness
	// conflating them:
	//
	//   * a dead CLIENT is not a stopped daemon. A batch rTorrent has accepted
	//     goes on executing with nobody left to read the answer, so the first
	//     case kills the producer at the barrier and then lets the daemon
	//     finish;
	//   * a lost TRANSPORT is not a partial success. When the daemon stops
	//     mid-batch the producer gets no answer at all -- production's own
	//     stack refuses a truncated body (rSCGITransport::readResponse()
	//     'truncated-body' -> null -> rXMLRPCRequest::send() false), so there
	//     is no prefix of successful replies anywhere on that path -- and every
	//     member is UNKNOWN, including the one that really was erased.
	//
	// Each member owns a payload only its own manifest can name, and a fourth
	// download the daemon holds belongs to no manifest and no obligation at
	// all: one shared payload made a lost obligation invisible here once
	// already.

	// One payload directory per hash: base/<hash>/name/a.bin.
	private function aggregatePayloads(array $hashes)
	{
		$root = $this->dir.'/aggregate-payload';
		$payloads = array();
		foreach($hashes as $hash)
		{
			$base = $root.'/'.$hash.'/name';
			@mkdir($base, 0777, true);
			@file_put_contents($base.'/a.bin', 'payload of '.$hash);
			$payloads[$hash] = $base;
		}
		return($payloads);
	}

	// The daemon's starting presence table: every one of these downloads exists
	// and owns exactly the files its own manifest will name.
	private function aggregateHashTable(array $payloads)
	{
		$table = array();
		foreach($payloads as $hash => $base)
			$table[$hash] = array('present' => true, 'base' => $base,
				'multi' => 1, 'files' => array($base.'/a.bin'));
		return($table);
	}

	// The state of one member as independent facts rather than one summary:
	// what the daemon says about the download, whether its own payload is still
	// there, whether it still owes a marker, whether a manifest of its own
	// stands, and whether anything generation-bound still names it.
	private function memberState($mirror, array $payloads, $hash)
	{
		$state = $this->mirrorState($mirror);
		$bound = false;
		foreach(glob($mirror->listPath.'/'.$hash.'.*') as $candidate)
			if(preg_match('/^'.$hash.'\.[0-9a-f]{16}\./D', basename($candidate)) === 1)
				$bound = true;
		if(is_array($state) && isset($state['journal']) && is_array($state['journal']))
			foreach($state['journal'] as $generation => $entry)
				if(preg_match('/^[0-9a-f]{16}$/D', (string)$generation) === 1
					&& isset($entry['hashes']) && is_array($entry['hashes'])
					&& in_array($hash, $entry['hashes'], true))
					$bound = true;
		clearstatcache();
		return(array(
			'daemon' => $mirror->daemonHolds($hash) ? 'present' : 'absent',
			'payload' => file_exists($payloads[$hash].'/a.bin') ? 'kept' : 'deleted',
			'base' => is_dir($payloads[$hash]) ? 'kept' : 'deleted',
			'marker' => count(glob($mirror->listPath.'/'.$hash.'.*.pending')) > 0
				? 'owed' : 'discharged',
			'manifest' => count(glob($mirror->listPath.'/'.$hash.'.*.list')) > 0
				? 'published' : 'none',
			'bound' => $bound ? 'bound' : 'unbound',
		));
	}

	// Every request a client really SENT that carried a d.erase, as
	// commands/erases pairs. What the daemon EXECUTED is a different count and
	// comes from the daemon's own transcript.
	private function aggregateSends($mirror)
	{
		$ret = array();
		foreach($mirror->rpcLog() as $request)
		{
			$erases = 0;
			foreach($request as $command)
				if(isset($command['command']) && $command['command'] === 'd.erase')
					$erases++;
			if($erases)
				$ret[] = count($request).'/'.$erases;
		}
		return($ret);
	}

	// Every destructive batch as the DAEMON saw it: how many of its commands
	// were really applied, how many d.erase commands really ran, and whether
	// the daemon ever answered it at all. This is the other half of
	// aggregateSends(): what was sent and what was executed are two counts, and
	// the whole point of these cases is that they can differ.
	private function aggregateExecutions($mirror)
	{
		$batches = array();
		$order = array();
		foreach($mirror->daemonEvents() as $record)
		{
			if(!isset($record['event'], $record['request']))
				continue;
			$request = $record['request'];
			if($record['event'] === 'command-applied')
			{
				if(!isset($batches[$request]))
				{
					$batches[$request] = array('applied' => 0, 'erased' => 0,
						'answered' => false, 'destructive' => false);
					$order[] = $request;
				}
				$batches[$request]['applied']++;
				if($record['command'] === 'd.erase' && $record['fault'] === null)
				{
					$batches[$request]['erased']++;
					$batches[$request]['destructive'] = true;
				}
			}
			else if($record['event'] === 'reply-delivered' && isset($batches[$request]))
				$batches[$request]['answered'] = true;
		}
		$ret = array();
		foreach($order as $request)
			if($batches[$request]['destructive'])
				$ret[] = $batches[$request]['applied'].'/'.$batches[$request]['erased']
					.'/'.($batches[$request]['answered'] ? 'answered' : 'unanswered');
		return($ret);
	}

	// A REAL second process against one real lock. Returns 'busy', 'free' or
	// 'unopenable'.
	private function lockProbe($path, $label)
	{
		$probe = ErasedataTestProcess::start(erasedataTestLockProbeCommand($path));
		$finished = $probe->wait(15);
		$probe->reap();
		$this->assertTrue($finished, $label.': the real lock probe finished');
		return(trim($probe->out));
	}

	// The commands the daemon applied for one request, in order.
	private function appliedFor($mirror, $request)
	{
		$ret = array();
		foreach($mirror->daemonEventsOf('command-applied') as $record)
			if(isset($record['request']) && $record['request'] === $request)
				$ret[] = $record;
		return($ret);
	}

	// The request the daemon raised its barrier inside.
	private function barrierRecord($mirror)
	{
		$events = $mirror->daemonEventsOf('barrier-reached');
		return(count($events) ? $events[count($events) - 1] : null);
	}

	private function startAggregateDaemon($mirror, $label)
	{
		$mirror->daemonClearControls();
		$daemon = ErasedataTestProcess::start($mirror->daemonCommand());
		$this->assertTrue($daemon->started() && $mirror->daemonWaitFile('alive', 20),
			$label.': the separate daemon fixture is up and holding the transport');
		return($daemon);
	}

	private function stopAggregateDaemon($mirror, $daemon, $label)
	{
		$mirror->daemonAskStop();
		$finished = $daemon->wait(20);
		$code = $daemon->reap();
		$this->assertTrue($finished && $code === 0,
			$label.': the daemon fixture exited on its own (exit '
				.var_export($code, true).')');
		$this->assertTrue(!$mirror->daemonAlive(),
			$label.': and left no fixture process holding the transport');
	}

	private function assertAggregateScheduleState($mirror, $retired)
	{
		$state = $this->mirrorState($mirror);
		$this->assertEquals($retired ? 'disarmed' : 'armed',
			is_array($state) && isset($state['phase']) ? $state['phase'] : null,
			$retired ? 'aggregate recovery durably disarms the drained queue'
				: 'the crashed aggregate keeps its drain armed while obligations remain');
		$this->assertEquals('0000000000000001',
			is_array($state) && isset($state['generation']) ? $state['generation'] : null,
			'recovery preserves the exact generation admitted by this batch');
		$removedKeys = array();
		foreach($mirror->scheduleLog() as $record)
			if($record['family'] === 'schedule_remove')
				$removedKeys[] = $record['key'];
		$this->assertEquals($retired ? array('erasedata-drainrutorrent') : array(),
			$removedKeys, $retired
				? 'the recovery child removes exactly its own drain schedule once'
				: 'no schedule removal is sent before aggregate obligations are discharged');
		if($retired)
			$this->assertEquals(array(),
				is_array($state) && isset($state['journal']) ? $state['journal'] : null,
				'no aggregate journal record survives retirement');
	}

	public function testProducerDeathAfterFirstAggregateEraseKeepsEveryObligation()
	{
		$this->assertProducerDeathAfterFirstAggregateErase(1);
	}

	public function testForceTwoProducerDeathKeepsEveryAggregateObligation()
	{
		$this->assertProducerDeathAfterFirstAggregateErase(2);
	}

	private function assertProducerDeathAfterFirstAggregateErase($force)
	{
		$this->reset();
		$invariant = 'a producer killed between the first and second executed'
			.' d.erase of ONE aggregate batch discharges nothing: the daemon it'
			.' had already reached finishes the batch with no live client, and'
			.' the recovery carries out every obligation exactly once against'
			.' each member\'s own payload';
		if(!$this->requireApi(array('ErasedataProductionMirror::scriptAggregate',
			'ErasedataProductionMirror::daemonCommand', 'ErasedataTestProcess::kill',
			'erasedataTestLockProbeCommand()',
			'erasedataTestDescriptorsNaming()'), $invariant))
			return;
		$mirror = $this->mirror('aggregate-producer-death-'.$force);
		$hashes = array($this->hash('A'), $this->hash('B'), $this->hash('C'));
		$decoy = $this->hash('D');
		$payloads = $this->aggregatePayloads(array_merge($hashes, array($decoy)));
		$decoyBytes = file_get_contents($payloads[$decoy].'/a.bin');
		$mirror->scriptRpc($this->drainScript());
		$this->assertTrue($mirror->scriptAggregate(array(
			// The barrier is a number of EXECUTED d.erase commands inside one
			// batch, not a number of requests: the whole gap this case closes
			// is that a request-level cut can never land between two of them.
			'aggregate' => array('after_erase' => 1, 'mode' => 'pause-after-erase',
				'barrier' => 'first-erase-applied'),
			'hashes' => $this->aggregateHashTable($payloads),
		)), 'the daemon fixture is configured to stop at the first executed erase');
		$daemon = $this->startAggregateDaemon($mirror, 'producer-death');
		$producer = $this->actionDoor($mirror, $hashes, $force);
		// The generation-bound acknowledgement of a REAL guarded child, first:
		// without one the producer waits out ERASEDATA_DRAIN_ACK_TIMEOUT and
		// takes the no-ack rollback, and no erase is ever built at all. The
		// helper reaps its scheduler children the moment the ack is durable, so
		// nothing but this producer is alive at the barrier below.
		$this->assertTrue($this->acknowledgeOnce($mirror, $producer, 25),
			'a real guarded child acknowledged the exact generation the producer armed');
		$this->assertTrue($mirror->daemonWaitFile('barrier', 25),
			'the daemon really reached the first executed d.erase INSIDE the batch');
		$barrier = $this->barrierRecord($mirror);
		$this->assertTrue(is_array($barrier) && $barrier['ordinal'] === 3
			&& $barrier['erased'] === 1 && $barrier['hash'] === $hashes[0],
			'the barrier is three commands into the aggregate, on the first'
				.' member\'s own d.erase ('.json_encode($barrier).')');
		$request = is_array($barrier) ? $barrier['request'] : '';
		$this->assertEquals(array($hashes[0]), $mirror->daemonExecutedErases(),
			'exactly one d.erase has been EXECUTED, and it is the first member\'s');
		$this->assertEquals(3, count($this->appliedFor($mirror, $request)),
			'three of the nine commands of the aggregate have been applied');
		$this->assertEquals(array('absent', 'present', 'present', 'present'),
			array($mirror->daemonHolds($hashes[0]) ? 'present' : 'absent',
				$mirror->daemonHolds($hashes[1]) ? 'present' : 'absent',
				$mirror->daemonHolds($hashes[2]) ? 'present' : 'absent',
				$mirror->daemonHolds($decoy) ? 'present' : 'absent'),
			'the daemon holds B, C and the decoy and no longer holds A');
		// Contention, proved by real second processes rather than asserted from
		// inside the holder: the producer is stopped inside its erase, and it
		// holds every hash lock of the batch and the drain state lock while it
		// is there. A hash nobody admitted is the control that shows the probe
		// can also say 'free'.
		foreach($hashes as $hash)
			$this->assertEquals('busy',
				$this->lockProbe($mirror->listPath.'/'.$hash.'.lock', 'producer-death'),
				'a real second process finds '.$hash.'\'s lock held by the'
					.' producer stopped inside the aggregate erase');
		$this->assertEquals('busy',
			$this->lockProbe($mirror->listPath.'/.drain-state.lock', 'producer-death'),
			'and finds the drain state lock held there too');
		$this->assertEquals('free',
			$this->lockProbe($mirror->listPath.'/'.$decoy.'.lock', 'producer-death'),
			'while a hash this batch never admitted is not locked at all');
		$state = $this->mirrorState($mirror);
		$generation = is_array($state) && isset($state['generation'])
			? $state['generation'] : null;
		$this->assertTrue(is_string($generation) && isset($state['journal'][$generation])
			&& $state['journal'][$generation]['phase'] === 'erase-started',
			'the durable record says erase-started before anything destructive ran');
		foreach($hashes as $hash)
			$this->assertEquals(array('daemon' => $hash === $hashes[0] ? 'absent' : 'present',
				'payload' => 'kept', 'base' => 'kept', 'marker' => 'owed',
				'manifest' => 'none', 'bound' => 'bound'),
				$this->memberState($mirror, $payloads, $hash),
				'at the barrier '.$hash.' still owes its marker, has published no'
					.' manifest and has lost no payload');
		// The producer dies WHERE IT STANDS: inside the aggregate erase, with
		// the batch half applied and no reply written for it.
		$producerPid = $producer->pid();
		$this->assertTrue(is_int($producerPid) && $producerPid > 0,
			'the producer child has a pid of its own to kill');
		if($force === 2)
		{
			foreach($hashes as $hash)
			{
				$naming = erasedataTestDescriptorsNaming($producerPid, $payloads[$hash]);
				$this->assertTrue(is_array($naming) && count($naming) >= 1,
					'the force-2 producer holds an open base-directory descriptor for '
						.$hash.' before it dies ('.json_encode($naming).')');
			}
			$this->assertEquals(array(),
				erasedataTestDescriptorsNaming($producerPid, $payloads[$decoy]),
				'the force-2 producer holds no descriptor on the unrelated download');
		}
		$this->assertTrue($producer->kill(9),
			'the producer is signalled inside the half-applied aggregate erase');
		$this->assertTrue($producer->wait(20), 'and really died there');
		$producerCode = $producer->reap();
		$this->assertTrue($producerCode !== 0,
			'the producer left no orderly exit behind (exit '
				.var_export($producerCode, true).')');
		// Now let the daemon finish the batch it had already accepted. A client
		// that is gone does not cancel work rTorrent took on.
		$mirror->daemonRelease();
		$this->assertTrue(
			$mirror->daemonWaitRequestEvent('reply-delivered', $request, 25),
			'the daemon finished the aggregate it had accepted');
		$applied = $this->appliedFor($mirror, $request);
		$this->assertEquals(9, count($applied),
			'all nine commands of the one aggregate were executed');
		$this->assertEquals($hashes, $mirror->daemonExecutedErases(),
			'and all three members were erased, in the order the batch carried them');
		$this->assertEquals(array('9/3'), $this->aggregateSends($mirror),
			'the producer had SENT exactly one destructive request, of nine'
				.' commands carrying three erases');
		$this->assertEquals(array('9/3/answered'), $this->aggregateExecutions($mirror),
			'and the daemon EXECUTED all nine of them and answered the batch,'
				.' after its client was already gone');
		$afterBarrier = array();
		foreach($applied as $record)
			if($record['ordinal'] > 3)
				$afterBarrier[] = $record['client_alive'];
		$this->assertEquals(array(false, false, false, false, false, false),
			$afterBarrier,
			'every command after the barrier was executed with the client'
				.' already dead: a disconnect stops nothing rTorrent accepted');
		$answered = null;
		foreach($mirror->daemonEventsOf('reply-delivered') as $record)
			if($record['request'] === $request)
				$answered = $record;
		$this->assertTrue(is_array($answered) && $answered['client_alive'] === false
			&& in_array($request, $mirror->daemonReplies(), true),
			'and its reply was written for a client that was no longer there to'
				.' read it ('.json_encode($answered).')');
		foreach($hashes as $hash)
			$this->assertEquals(array('daemon' => 'absent', 'payload' => 'kept',
				'base' => 'kept', 'marker' => 'owed', 'manifest' => 'none',
				'bound' => 'bound'),
				$this->memberState($mirror, $payloads, $hash),
				'the dead producer published nothing and discharged nothing for '
					.$hash.', so every obligation survives it');
		// Recovery: the real guarded worker, restarted exactly as the scheduler
		// starts it.
		$this->assertAggregateScheduleState($mirror, false);
		$recovery = ErasedataTestProcess::start($mirror->drainCommand('drain'));
		$this->runChildren(array('recovery' => $recovery), 40, $invariant);
		$this->assertAggregateScheduleState($mirror, true);
		foreach($hashes as $hash)
			$this->assertEquals(array('daemon' => 'absent', 'payload' => 'deleted',
				'base' => 'deleted', 'marker' => 'discharged', 'manifest' => 'none',
				'bound' => 'unbound'),
				$this->memberState($mirror, $payloads, $hash),
				'the recovery carried '.$hash.'\'s obligation out against its OWN'
					.' payload and retired the record that held it');
		$this->assertEquals($hashes, $mirror->daemonExecutedErases(),
			'the recovery erased nothing twice: the daemon still records exactly'
				.' the three erases its own batch executed');
		$this->assertEquals('present', $mirror->daemonHolds($decoy) ? 'present' : 'absent',
			'the download no manifest of this batch names was never touched');
		$this->assertTrue(file_get_contents($payloads[$decoy].'/a.bin') === $decoyBytes,
			'and its payload is byte-for-byte what it was');
		$this->assertEquals(1, count($mirror->daemonEventsOf('barrier-reached')),
			'the barrier fired exactly once, inside the producer\'s own batch');
		$this->assertEquals(array(), $mirror->daemonEventsOf('barrier-timeout'),
			'and no barrier was reached by waiting one out');
		$this->stopAggregateDaemon($mirror, $daemon, 'producer-death');
	}

	public function testDaemonDeathAfterFirstAggregateEraseRecoversRemainingMembers()
	{
		$this->reset();
		$invariant = 'a daemon that stops between the first and second executed'
			.' d.erase of ONE aggregate batch leaves the producer with no answer'
			.' at all: nothing is published for the member that really was'
			.' erased either, every obligation is retained, and a later worker'
			.' reconciles each member against the daemon\'s own presence';
		if(!$this->requireApi(array('ErasedataProductionMirror::scriptAggregate',
			'ErasedataProductionMirror::daemonCommand',
			'erasedataTestDescriptorsNaming()'), $invariant))
			return;
		$mirror = $this->mirror('aggregate-daemon-death');
		$hashes = array($this->hash('A'), $this->hash('B'), $this->hash('C'));
		$decoy = $this->hash('D');
		$payloads = $this->aggregatePayloads(array_merge($hashes, array($decoy)));
		$decoyBytes = file_get_contents($payloads[$decoy].'/a.bin');
		$table = $this->aggregateHashTable($payloads);
		$mirror->scriptRpc($this->drainScript());
		$this->assertTrue($mirror->scriptAggregate(array(
			'aggregate' => array('after_erase' => 1, 'mode' => 'pause-after-erase',
				'barrier' => 'first-erase-applied'),
			'hashes' => $table,
		)), 'the daemon fixture is configured to stop at the first executed erase');
		$daemon = $this->startAggregateDaemon($mirror, 'daemon-death');
		// force=2, so the admission really has to take a directory capability
		// per member and hold it through the erase.
		$producer = $this->actionDoor($mirror, $hashes, 2);
		$this->assertTrue($this->acknowledgeOnce($mirror, $producer, 25),
			'a real guarded child acknowledged the exact generation the producer armed');
		$this->assertTrue($mirror->daemonWaitFile('barrier', 25),
			'the daemon really reached the first executed d.erase INSIDE the batch');
		$barrier = $this->barrierRecord($mirror);
		$request = is_array($barrier) ? $barrier['request'] : '';
		$this->assertTrue(is_array($barrier) && $barrier['ordinal'] === 3
			&& $barrier['erased'] === 1 && $barrier['hash'] === $hashes[0],
			'the barrier is three commands into the aggregate, on the first'
				.' member\'s own d.erase ('.json_encode($barrier).')');
		// The force-2 capability, observed rather than assumed: the stopped
		// producer really holds an open descriptor naming each member's base
		// directory. Reading it by dev/ino through /proc is exactly how
		// ErasedataFilesystemOps::descriptorsNamingIdentity() recognises its
		// own, and /proc is the same runtime the capability itself needs
		// (erasedataDescriptorCandidates()).
		$producerPid = $producer->pid();
		foreach($hashes as $hash)
		{
			$naming = erasedataTestDescriptorsNaming($producerPid, $payloads[$hash]);
			$this->assertTrue(is_array($naming) && count($naming) >= 1,
				'the force-2 producer stopped inside the erase still holds an open'
					.' descriptor on '.$hash.'\'s base directory ('
					.json_encode($naming).')');
		}
		$this->assertEquals(array(),
			erasedataTestDescriptorsNaming($producerPid, $payloads[$decoy]),
			'and holds no descriptor at all on the base of a download it never admitted');
		foreach($hashes as $hash)
			$this->assertEquals('busy',
				$this->lockProbe($mirror->listPath.'/'.$hash.'.lock', 'daemon-death'),
				'a real second process finds '.$hash.'\'s lock held by the'
					.' producer stopped inside the aggregate erase');
		// Now the DAEMON dies, not the client. The transport closes with no
		// reply of any kind, which is what production would see: rTorrent that
		// stops mid-multicall sends nothing that the SCGI reader will hand on.
		$mirror->daemonAskStop();
		$this->assertTrue($daemon->wait(25), 'the daemon fixture really stopped');
		$daemonCode = $daemon->reap();
		$this->assertTrue($daemonCode === 0,
			'it closed its transport and exited on its own (exit '
				.var_export($daemonCode, true).')');
		$stopped = $mirror->daemonEventsOf('daemon-stopped');
		$this->assertTrue(count($stopped) === 1
			&& $stopped[0]['reason'] === 'stopped-at-barrier',
			'and it recorded that it stopped at the barrier, mid-batch');
		$this->assertTrue(!in_array($request, $mirror->daemonReplies(), true),
			'no reply of any kind was written for the half-executed batch: a lost'
				.' transport is never a successful array of the first replies');
		// The PRODUCER survives its daemon and finishes on its own terms.
		$this->assertTrue($producer->wait(30),
			'the producer, which was never killed, finished by itself');
		$producerCode = $producer->reap();
		$this->assertTrue($producerCode === 0,
			'the surviving producer exited normally (exit '
				.var_export($producerCode, true).')');
		$this->assertEquals(array($hashes[0]), $mirror->daemonExecutedErases(),
			'exactly one member was really erased, and the other two never were');
		$this->assertEquals(3, count($this->appliedFor($mirror, $request)),
			'three of the nine commands the producer sent were executed');
		$this->assertEquals(array('9/3'), $this->aggregateSends($mirror),
			'while the producer had SENT all nine of them in one request');
		foreach($hashes as $hash)
			$this->assertEquals(array('daemon' => $hash === $hashes[0] ? 'absent' : 'present',
				'payload' => 'kept', 'base' => 'kept', 'marker' => 'owed',
				'manifest' => 'none', 'bound' => 'bound'),
				$this->memberState($mirror, $payloads, $hash),
				'with its answer lost the producer published nothing for '.$hash
					.', not even for the member that really was erased');
		$unresolved = 0;
		foreach($mirror->log() as $line)
			if(strpos($line, 'erasedata: erase-unresolved ') === 0)
				$unresolved++;
		$this->assertTrue($unresolved >= 1,
			'and it said so: the members it could not account for are reported'
				.' as unresolved, not as erased');
		// The daemon comes back with exactly what it had executed. A restart
		// resumes no lost batch: B and C are still there because nothing ever
		// ran their commands.
		$this->assertTrue($mirror->scriptAggregate(array(
			'aggregate' => array('after_erase' => 0, 'mode' => 'none',
				'barrier' => 'none'),
			'hashes' => $table,
		)), 'the daemon is reconfigured with no barrier for the recovery phase');
		$daemon = $this->startAggregateDaemon($mirror, 'daemon-death-restart');
		$this->assertEquals(array($hashes[0]), $mirror->daemonExecutedErases(),
			'the restarted daemon kept exactly the one erase it had executed');
		$this->assertAggregateScheduleState($mirror, false);
		$recovery = ErasedataTestProcess::start($mirror->drainCommand('drain'));
		$this->runChildren(array('recovery' => $recovery), 40, $invariant);
		$this->assertAggregateScheduleState($mirror, true);
		foreach($hashes as $hash)
			$this->assertEquals(array('daemon' => 'absent', 'payload' => 'deleted',
				'base' => 'deleted', 'marker' => 'discharged', 'manifest' => 'none',
				'bound' => 'unbound'),
				$this->memberState($mirror, $payloads, $hash),
				'the recovery reconciled '.$hash.' against the daemon\'s own'
					.' presence and carried its obligation out against its own payload');
		$this->assertEquals($hashes, $mirror->daemonExecutedErases(),
			'B and C were erased once each by the recovery, and A was not erased twice');
		// The recovery's own aggregate is the happy path: one unbroken request
		// for the two members that were still there, answered in full.
		$this->assertEquals(array('9/3', '6/2'), $this->aggregateSends($mirror),
			'the recovery sent one aggregate of six commands carrying two erases');
		$this->assertEquals(array('3/1/unanswered', '6/2/answered'),
			$this->aggregateExecutions($mirror),
			'the producer\'s nine commands were executed three deep and never'
				.' answered, and the recovery\'s six ran whole and were answered'
				.' in full: the happy path, with no break');
		$this->assertEquals('present', $mirror->daemonHolds($decoy) ? 'present' : 'absent',
			'the download no manifest of this batch names was never touched');
		$this->assertTrue(file_get_contents($payloads[$decoy].'/a.bin') === $decoyBytes,
			'and its payload is byte-for-byte what it was');
		$this->stopAggregateDaemon($mirror, $daemon, 'daemon-death-restart');
	}

	public function testDaemonDeathAfterSecondAggregateEraseRetainsOnlyTheLastMember()
	{
		$this->reset();
		$invariant = 'the same boundary one command further in: with two of the'
			.' three erases of a batch executed and the transport then lost, the'
			.' third member is still held by the daemon, every obligation is'
			.' retained, and the recovery erases exactly the one member that'
			.' remains';
		if(!$this->requireApi(array('ErasedataProductionMirror::scriptAggregate',
			'ErasedataProductionMirror::daemonCommand'), $invariant))
			return;
		$mirror = $this->mirror('aggregate-second-erase');
		$hashes = array($this->hash('A'), $this->hash('B'), $this->hash('C'));
		$decoy = $this->hash('D');
		$payloads = $this->aggregatePayloads(array_merge($hashes, array($decoy)));
		$decoyBytes = file_get_contents($payloads[$decoy].'/a.bin');
		$table = $this->aggregateHashTable($payloads);
		$mirror->scriptRpc($this->drainScript());
		$this->assertTrue($mirror->scriptAggregate(array(
			'aggregate' => array('after_erase' => 2, 'mode' => 'stop-after-erase',
				'barrier' => 'second-erase-applied'),
			'hashes' => $table,
		)), 'the daemon fixture is configured to stop at the SECOND executed erase');
		$daemon = $this->startAggregateDaemon($mirror, 'second-erase');
		$producer = $this->actionDoor($mirror, $hashes, 1);
		$this->assertTrue($this->acknowledgeOnce($mirror, $producer, 25),
			'a real guarded child acknowledged the exact generation the producer armed');
		$this->assertTrue($daemon->wait(30),
			'the daemon stopped itself at the second executed erase');
		$daemonCode = $daemon->reap();
		$this->assertTrue($daemonCode === 0,
			'the fixture daemon stopped at its configured second-erase boundary (exit '
				.var_export($daemonCode, true).')');
		$barrier = $this->barrierRecord($mirror);
		$this->assertTrue(is_array($barrier) && $barrier['ordinal'] === 6
			&& $barrier['erased'] === 2 && $barrier['hash'] === $hashes[1],
			'the barrier is six commands into the aggregate, on the second'
				.' member\'s own d.erase ('.json_encode($barrier).')');
		$this->assertEquals(array($hashes[0], $hashes[1]),
			$mirror->daemonExecutedErases(),
			'A and B were executed and C was not');
		$request = is_array($barrier) ? $barrier['request'] : '';
		$this->assertTrue(!in_array($request, $mirror->daemonReplies(), true),
			'and the half-executed batch was answered with nothing at all');
		$this->assertTrue($producer->wait(30),
			'the producer, which was never killed, finished by itself');
		$producerCode = $producer->reap();
		$this->assertTrue($producerCode === 0,
			'the producer survived the lost daemon transport and exited normally (exit '
				.var_export($producerCode, true).')');
		foreach($hashes as $hash)
			$this->assertEquals(array(
				'daemon' => $hash === $hashes[2] ? 'present' : 'absent',
				'payload' => 'kept', 'base' => 'kept', 'marker' => 'owed',
				'manifest' => 'none', 'bound' => 'bound'),
				$this->memberState($mirror, $payloads, $hash),
				'nothing was published or discharged for '.$hash.' on an answer'
					.' that never arrived');
		$this->assertTrue($mirror->scriptAggregate(array(
			'aggregate' => array('after_erase' => 0, 'mode' => 'none',
				'barrier' => 'none'),
			'hashes' => $table,
		)), 'the daemon is reconfigured with no barrier for the recovery phase');
		$daemon = $this->startAggregateDaemon($mirror, 'second-erase-restart');
		$this->assertAggregateScheduleState($mirror, false);
		$recovery = ErasedataTestProcess::start($mirror->drainCommand('drain'));
		$this->runChildren(array('recovery' => $recovery), 40, $invariant);
		$this->assertAggregateScheduleState($mirror, true);
		$this->assertEquals($hashes, $mirror->daemonExecutedErases(),
			'the recovery erased exactly the one member that was still there,'
				.' and neither of the two that had already gone');
		$this->assertEquals(array('9/3', '3/1'), $this->aggregateSends($mirror),
			'and it sent one aggregate of three commands carrying one erase');
		$this->assertEquals(array('6/2/unanswered', '3/1/answered'),
			$this->aggregateExecutions($mirror),
			'the producer\'s nine commands were executed six deep and never'
				.' answered, and the recovery\'s three ran whole and were'
				.' answered in full');
		foreach($hashes as $hash)
			$this->assertEquals(array('daemon' => 'absent', 'payload' => 'deleted',
				'base' => 'deleted', 'marker' => 'discharged', 'manifest' => 'none',
				'bound' => 'unbound'),
				$this->memberState($mirror, $payloads, $hash),
				'and every member ends with its own payload deleted and its'
					.' obligation discharged, including '.$hash);
		$this->assertEquals('present', $mirror->daemonHolds($decoy) ? 'present' : 'absent',
			'the download no manifest of this batch names was never touched');
		$this->assertTrue(file_get_contents($payloads[$decoy].'/a.bin') === $decoyBytes,
			'and its payload is byte-for-byte what it was');
		$this->stopAggregateDaemon($mirror, $daemon, 'second-erase-restart');
	}

	public function testAggregateDaemonContinuesAfterMissingFirstHash()
	{
		$this->reset();
		$mirror = $this->mirror('aggregate-missing-first');
		$hashes = array($this->hash('A'), $this->hash('B'), $this->hash('C'));
		$payloads = $this->aggregatePayloads($hashes);
		$table = $this->aggregateHashTable($payloads);
		$table[$hashes[0]]['present'] = false;
		$this->assertTrue($mirror->scriptAggregate(array(
			'aggregate' => array('after_erase' => 0, 'mode' => 'none',
				'barrier' => 'none'),
			'hashes' => $table,
		)), 'the daemon starts with the first hash absent and the next two present');
		$daemon = $this->startAggregateDaemon($mirror, 'missing-first');
		$report = $mirror->settings.'/missing-first-result.json';
		$runner = $mirror->writeRunner('missing-first-client',
			'require_once('.var_export($mirror->pluginDir.'/removewithdata.php', true).");\n"
			.'$hashes = '.var_export($hashes, true).";\n"
			.'$request = erasedataEraseRequest($hashes);'."\n"
			.'$ran = $request->run();'."\n"
			.'$report = array("ran" => $ran, "fault" => $request->fault,'."\n"
			.'    "outcomes" => erasedataClassifyEraseOutcomes($hashes, $request));'."\n"
			.'file_put_contents('.var_export($report, true).', json_encode($report));'."\n");
		$client = ErasedataTestProcess::start($mirror->php($runner));
		$this->assertTrue($client->wait(30), 'the production aggregate request received a reply');
		$clientCode = $client->reap();
		$this->assertTrue($clientCode === 0,
			'the client finished after the faulted aggregate reply (exit '
				.var_export($clientCode, true).', stderr: '.trim($client->err).')');
		$answer = json_decode((string)@file_get_contents($report), true);
		$this->assertEquals(array('ran' => true, 'fault' => true,
			'outcomes' => array_fill_keys($hashes, 'unknown')), $answer,
			'the faulted aggregate is answered, but no member is accepted as erased');
		$this->assertEquals(array('9/3'), $this->aggregateSends($mirror),
			'the client sent all three members in one nine-command request');
		$this->assertEquals(array('9/2/answered'), $this->aggregateExecutions($mirror),
			'the daemon answered after all nine commands and erased both later members');
		$this->assertEquals(array($hashes[1], $hashes[2]),
			$mirror->daemonExecutedErases(),
			'the first missing-hash fault did not prevent the later erases');
		$applied = $mirror->daemonEventsOf('command-applied');
		$this->assertEquals(array(1, 2, 3), array_map(function($event) {
			return($event['ordinal']);
		}, array_slice($applied, 0, 3)),
			'the missing member faulted in the first three positions');
		foreach(array_slice($applied, 0, 3) as $event)
			$this->assertEquals('invalid parameters: info-hash not found',
				$event['fault'], 'the first member returned the daemon missing-hash fault');
		foreach($hashes as $hash)
			$this->assertTrue(file_exists($payloads[$hash].'/a.bin'),
				'the aggregate request alone did not delete '.$hash.' payload');
		$this->stopAggregateDaemon($mirror, $daemon, 'missing-first');
	}

	public function testRestartAfterVolatileScheduleLossRearmsTheExactGeneration()
	{
		$this->reset();
		$invariant = 'a daemon restart that loses the volatile schedule re-arms'
			.' the exact per-user drain key from durable state alone';
		$mirror = $this->mirror('restart-rearm');
		$mirror->scriptRpc($this->drainScript());
		$mirror->shortenAcknowledgementWait();
		$producer = $this->actionDoor($mirror, array($this->hash('A')), 1);
		$producer->wait(20);
		$producer->reap();
		$before = $this->mirrorState($mirror);
		$this->assertTrue(is_array($before) && isset($before['generation']),
			'the producer left a durable generation to recover');
		// The restart: rTorrent forgot every schedule, and the plugin loader
		// runs the real init.php again with its own globals.
		@unlink($mirror->settings.'/rpc.log');
		$startup = $mirror->writeRunner('startup',
			'$theSettings = rTorrentSettings::get();'."\n"
			.'$plugin = array("name" => "erasedata");'."\n"
			.'$pInfo = array("perms" => 0);'."\n"
			.'$jResult = "";'."\n"
			.'require('.var_export($mirror->pluginDir.'/init.php', true).");\n");
		// After volatile schedule loss, startup must rearm before a scheduler
		// can start its worker. A concurrent worker could drain the queue first,
		// correctly leaving startup with nothing to rearm.
		$children = array(
			'startup' => ErasedataTestProcess::start($mirror->php($startup)),
		);
		$this->runChildren($children, 30, $invariant);
		$drain = array();
		$periodic = 0;
		foreach($mirror->scheduleLog() as $record)
		{
			if($record['family'] !== 'schedule')
				continue;
			if(strpos((string)$record['key'], 'erasedata-drain') === 0)
				$drain[] = $record['key'];
			else if(strpos((string)$record['key'], 'erasedata') === 0)
				$periodic++;
		}
		$this->assertTrue($periodic >= 1,
			'startup still arms the ordinary periodic collector schedule');
		$this->assertEquals(array('erasedata-drainrutorrent'),
			array_values(array_unique($drain)),
			'and re-arms the exact per-user drain key after the restart');
		$worker = ErasedataTestProcess::start($mirror->drainCommand('drain'));
		$this->runChildren(array('worker' => $worker), 30, $invariant);
		$after = $this->mirrorState($mirror);
		$this->assertTrue(is_array($after) && isset($after['generation'])
			&& isset($before['generation'])
			&& $after['generation'] === $before['generation'],
			'the recovered generation is the one that was durable before the restart');
	}

	public function testGlobalLockBypassWouldShowOverlapInSharedRecovery()
	{
		$this->reset();
		$invariant = 'two guarded workers started at the same time overlap in no'
			.' shared recovery work: exactly one consumes each obligation';
		$mirror = $this->mirror('global-lock');
		$mirror->scriptRpc($this->drainScript());
		$mirror->shortenAcknowledgementWait();
		$hashes = array($this->hash('A'), $this->hash('B'));
		$producer = $this->actionDoor($mirror, $hashes, 1);
		$producer->wait(20);
		$producer->reap();
		// The producer stages its own manifests, so it issues its own
		// d.get_base_path calls; it is fully reaped before either worker starts,
		// so this index cuts the transcript into a producer-only half and a
		// worker-only half.
		$beforeWorkers = count($mirror->rpcLog());
		// This case is named for a lock and used to be decided by nothing but
		// the END STATE, which is the same whether a lock did the separating or
		// luck did: it passed unchanged with EVERY flock acquisition refusal
		// deleted from removewithdata.php -- the two children then ran complete
		// ticks side by side, collided in their collectors, and still left one
		// staging per hash and an empty queue behind. (The weakness is older
		// than the package 6 repairs: the pre-rewrite shape passes the same
		// mutation against pre-repair production, so it was inherited rather
		// than introduced. It is fixed here rather than recorded as debt.)
		//
		// What actually separates them is asserted below instead. Two things are
		// needed for that to mean anything: the children must really be inside
		// the same window, and the loser must be the LOCK's doing.
		//
		// The window is widened deliberately, and only for the workers, so the
		// producer above still runs at full speed: each d.get_base_path of a
		// recovery holds its caller for 0.6s. The winner therefore stays inside
		// its pass, holding the nonblocking worker admission, for well over a
		// second -- three orders of magnitude longer than the skew between two
		// proc_open() starts. Without it the two children still overlap on this
		// machine, measured, but only by the ~20ms a whole tick takes, and a
		// case whose premise depends on a 20ms window on a shared box is one
		// scheduling hiccup away from passing vacuously.
		$slow = $this->drainScript();
		$slow['d.get_base_path']['delay'] = 600000;
		$mirror->scriptRpc($slow);
		$children = array(
			'first' => ErasedataTestProcess::start($mirror->drainCommand('drain')),
			'second' => ErasedataTestProcess::start($mirror->drainCommand('drain')),
		);
		$this->runChildren($children, 30, $invariant);
		// The overlap itself, stated rather than assumed, and the one assertion
		// here that the locks carry: exactly one of the two children got worker
		// admission, and the other said so by its own classified reason. Delete
		// the flock refusals and this line is what goes missing -- both children
		// are admitted, both run a whole tick, and every count below still
		// reads exactly as it does on a healthy queue. Either child may be the
		// loser: whichever reaches the nonblocking admission second reports.
		$busy = 0;
		foreach($mirror->log() as $line)
			if(strpos($line, 'erasedata: worker-busy ') === 0)
				$busy++;
		$this->assertEquals(1, $busy,
			'the two workers really were inside the same window, and exactly one'
				.' of them was refused worker admission and consumed nothing');
		$erased = $this->mirrorErased($mirror);
		$this->assertEquals(count($erased), count(array_unique($erased)),
			'no hash is erased twice by two overlapping workers');
		// SUPERSEDED SHAPE. This used to tally the <HASH>.<gen>.*.list files left
		// in the queue after both workers exited. The worker repair (P02, step
		// (5) of erasedataDrainWorkerRun) makes a complete tick collect what it
		// published and retire under the same pass locks, so a final manifest is
		// consumed inside the tick that wrote it and the queue is legitimately
		// empty by the time the children are reaped -- the old tally measured a
		// transient the repair deliberately removed, and its vacuity guard then
		// fired on a queue that had in fact been fully drained.
		//
		// The witness that survives collection is the RPC transcript. A drain
		// worker can only publish a hash by first rebuilding its manifest through
		// erasedataCollectPaths(), whose first call is d.get_base_path <hash>, so
		// one entry per hash means exactly one worker did that work and two
		// entries for one hash means the two workers overlapped on it. Zero
		// entries means no recovery happened at all, which is what keeps the
		// per-hash equality below non-vacuous.
		// (testDrainCollectsPayloadAndRetiresBeforeReleasingPassLocks is the case
		// that pins the collect-and-retire behaviour itself.)
		$staged = array();
		foreach(array_slice($mirror->rpcLog(), $beforeWorkers) as $request)
			foreach($request as $command)
			{
				if(!isset($command['command'])
					|| $command['command'] !== 'd.get_base_path')
					continue;
				$argv = isset($command['params']) && is_array($command['params'])
					? $command['params']
					: array(isset($command['params']) ? $command['params'] : null);
				$hash = array_key_exists(0, $argv) ? (string)$argv[0] : '';
				$staged[$hash] = isset($staged[$hash]) ? $staged[$hash] + 1 : 1;
			}
		ksort($staged, SORT_STRING);
		$this->assertEquals($hashes, array_keys($staged),
			'the shared recovery really rebuilt and published every member of the batch');
		foreach($staged as $hash => $count)
			$this->assertEquals(1, $count,
				'exactly one worker staged and published '.$hash);
		$this->assertEquals(array(), glob($mirror->listPath.'/*.pending'),
			'every obligation of the batch is discharged');
		$this->assertEquals(array(), glob($mirror->listPath.'/*.tmp'),
			'and no staging object of the batch is left behind');
		$state = $this->mirrorState($mirror);
		$this->assertTrue($this->stateAcknowledgesItsGeneration($state),
			'a real guarded child acknowledged the exact generation the producer armed');
	}

	// -- the seven defects the real-daemon lab and the slice review found ----

	// D1. The empty user is a REAL user, not an absent one.
	//
	// User::getUser() answers '' on every install without HTTP authentication
	// and on every install with $forbidUserSettings = true -- which is the
	// shipped image's own generated config -- and erase.php has documented that
	// since the exact base. A protocol that refused '' refused every removal on
	// a single-user install: measured against a real rTorrent 0.16.21, the HTTP
	// door answered false, wrote nothing, sent no RPC and left the payload on
	// disk, where the exact base erased the download and deleted the data.
	//
	// What must still be refused is a MISMATCH between the user a producer
	// admitted under and the user a child, a retirement or a re-arm carries.
	// Emptiness itself is not a mismatch, and '' is not a wildcard either.
	public function testTheEmptyUserOfASingleUserInstallIsAdmittedAndDrainedEndToEnd()
	{
		$this->reset();
		$invariant = 'the empty User::getUser() of a single-user install is a'
			.' real user: admitted, armed, acknowledged, drained and retired end'
			.' to end on its own erasedata-drain key, while a user mismatch in'
			.' either direction is still refused';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()',
			'erasedataDrainWorkerRun()', 'erasedataRetirementRun()',
			'erasedataDrainWorkerCommand()', 'erasedataDrainScheduleKey()',
			'erasedataReadDrainState()', 'erasedataWriteDrainState()'), $invariant))
			return;
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		$single = $this->dependencies(array('user' => ''));
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		$this->probe(true, false, array($hash));
		$this->acknowledgeOnRegistration($queue);
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true, 'val' => array(0));
		$outcome = erasedataRemovalAdmissionRun($single, array($hash), 1);
		$this->assertTrue(is_array($outcome) && isset($outcome['published'])
			&& $outcome['published'] === array($hash),
			'the single-user install admits the removal and publishes its manifest');
		$this->assertEquals(array($hash), rXMLRPCRequest::$erased,
			'and really erases the download it was asked to');
		$this->assertEquals(1, count($this->manifestFiles($hash)),
			'exactly one final manifest stands for the collector');
		$this->assertEquals(array(), glob($queue.'/*.pending'),
			'and the obligation marker is discharged');
		$registrations = $this->scheduleRecords('schedule');
		$this->assertEquals(1, count($registrations),
			'the drain schedule is armed exactly once');
		$this->assertEquals('erasedata-drain',
			count($registrations) ? $registrations[0]['key'] : null,
			'on the bare per-user key the empty user owns');
		$state = erasedataReadDrainState($queue);
		$this->assertEquals('', is_array($state) && isset($state['user'])
			? $state['user'] : null,
			'and the durable state records the empty user as the owner');
		$generation = is_array($state) && isset($state['generation'])
			? $state['generation'] : null;
		$this->assertTrue($generation !== null
			&& $generation !== '0000000000000000',
			'on a real generation, not the zero one');
		// The child the scheduler would start carries the SAME empty user.
		$command = erasedataDrainWorkerCommand('');
		$this->assertTrue(is_string($command)
			&& strpos($command, ' '.escapeshellarg('').' '.escapeshellarg('drain')) !== false,
			'the scheduled child receives the same empty user as its own argv word');
		// A real internal tick for the empty user acknowledges its generation.
		$state['acknowledged'] = '0000000000000000';
		$this->assertTrue(erasedataWriteDrainState($queue, $state) === true,
			'the acknowledgement is wound back so the tick has something to do');
		$this->assertTrue(erasedataDrainWorkerRun($single) !== false,
			'a drain tick started for the empty user runs to a decision');
		$after = erasedataReadDrainState($queue);
		$this->assertEquals($generation, is_array($after) && isset($after['acknowledged'])
			? $after['acknowledged'] : null,
			'and the empty user acknowledges exactly its own durable generation');
		// And what it armed, it can retire.
		foreach($this->manifestFiles($hash) as $collected)
			@unlink($collected);
		rXMLRPCRequest::$scheduledCommands = array();
		$this->assertTrue($this->retire($single) === true,
			'a drained single-user queue really retires its own schedule');
		$removals = $this->scheduleRecords('schedule_remove');
		$this->assertEquals(1, count($removals),
			'with exactly one mapped schedule_remove');
		$this->assertEquals('erasedata-drain',
			count($removals) ? $removals[0]['key'] : null,
			'on the exact bare drain key, never the collector key');

		// The other half: '' binds exactly, it does not match anything.
		$this->reset();
		$queue = $this->queuePath();
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true, 'val' => array(0));
		$owned = array('version' => 1, 'user' => 'somebody-else',
			'generation' => '0000000000000003', 'acknowledged' => '0000000000000003',
			'phase' => 'armed', 'journal' => array(), 'diagnostics' => array());
		$this->assertTrue(erasedataWriteDrainState($queue, $owned) === true,
			'the queue is armed for a named user');
		$this->assertTrue($this->retire(
			$this->dependencies(array('user' => ''))) === false,
			'the empty user never retires a schedule another user armed');
		$this->assertEquals(0, count($this->scheduleRecords('schedule_remove')),
			'and sends no schedule_remove on their behalf');
		$this->assertTrue(erasedataDrainWorkerRun(
			$this->dependencies(array('user' => ''))) === false,
			'nor acknowledges on their queue');
		$observed = erasedataReadDrainState($queue);
		$this->assertEquals('somebody-else', is_array($observed) && isset($observed['user'])
			? $observed['user'] : null,
			'which keeps its owner exactly as it was found');
		$owned['user'] = '';
		$this->assertTrue(erasedataWriteDrainState($queue, $owned) === true,
			'and now the queue belongs to the empty user instead');
		$this->assertTrue(erasedataDrainWorkerRun(
			$this->dependencies(array('user' => 'rutorrent'))) === false,
			'a named user never acknowledges on the empty user\'s queue either');
		$observed = erasedataReadDrainState($queue);
		$this->assertEquals('0000000000000003',
			is_array($observed) && isset($observed['acknowledged'])
				? $observed['acknowledged'] : null,
			'and writes nothing into it');

		// The lab reproduction itself: the REAL shipped HTTP door, in a real
		// child process, on a mirror whose User::getUser() is the empty string.
		$this->reset();
		$mirror = $this->mirror('single-user', '');
		$payload = $mirror->root.'/single-user-payload.bin';
		file_put_contents($payload, 'the real child must collect this payload');
		$script = $this->drainScript();
		$script['d.get_base_path']['val'] = array($payload, 0, $payload);
		$mirror->scriptRpc($script);
		$door = $this->actionDoor($mirror, array($this->hash('C')), 1, 'single-user-door');
		$this->runChildrenScheduled($mirror, array('door' => $door), 40, $invariant);
		// The producer can finish before its acknowledging child collects, so
		// allow a successor tick to finish any interrupted collection.
		$drain = ErasedataTestProcess::start($mirror->drainCommand());
		$this->assertTrue($drain->wait(5.0), 'the real successor drain finishes');
		$this->assertEquals(0, $drain->reap(), 'the real successor drain exits successfully');
		clearstatcache();
		$this->assertTrue(!file_exists($payload), 'the real single-user drain deletes the payload');
		$this->assertEquals(0, count(glob($mirror->listPath.'/'.$this->hash('C').'.*.list')),
			'the real single-user drain consumes the published final manifest');
		$this->assertTrue(strpos($door->out, 'false') === false,
			'and answers the user with an outcome rather than false: '
				.trim(substr($door->out, 0, 200)));
		$mirrorState = $this->mirrorState($mirror);
		$this->assertEquals('', is_array($mirrorState) && isset($mirrorState['user'])
			? $mirrorState['user'] : null,
			'the real child and the real door agree the empty user owns this queue');
		$armed = array();
		foreach($mirror->scheduleLog() as $record)
			if($record['family'] === 'schedule'
				&& strpos((string)$record['key'], 'erasedata-drain') === 0)
				$armed[(string)$record['key']] = true;
		$this->assertEquals(array('erasedata-drain'), array_keys($armed),
			'on the exact bare drain key');
	}

	// D2, first half. A finished generation must stop blocking its own
	// retirement.
	//
	// The journal is half of invariant 10's scan, and before this the only
	// pruner in the plugin ran on the ADMISSION path. So after the LAST removal
	// on a queue at least one record always stood, the scan was never empty,
	// and the durable settled/disarmed writes and the mapped schedule_remove
	// were unreachable for ever -- measured on a real 0.16.21 against a queue
	// holding nothing but control files: a forced tick exited 0 and left
	// phase:armed, while the five-second child kept re-probing erased hashes at
	// about 1.6 kB/s of faulting-RPC error log.
	public function testAJournalRecordThatBindsNothingNeverBlocksRetirementForEver()
	{
		$this->reset();
		$invariant = 'a journal record that binds no staging, no marker and no'
			.' uncollected manifest is pruned durably and stops blocking'
			.' retirement, in every phase -- while one that still binds'
			.' something is kept and still refuses';
		if(!$this->requireApi(array('erasedataRetirementRun()',
			'erasedataPruneResolvedJournalRecords()', 'erasedataReadDrainState()',
			'erasedataQueueRequest()'), $invariant))
			return;
		$hash = $this->hash('A');
		$generation = '0000000000000004';
		foreach(array('published', 'retained', 'erase-started') as $phase)
		{
			$this->reset();
			$queue = $this->queuePath();
			rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true, 'val' => array(0));
			$this->armQueue($queue, $generation,
				array($hash => $queue.'/'.$hash.'.'.$generation.'.1.tmp'), $phase);
			$this->assertEquals(array(), glob($queue.'/*.tmp'),
				'the '.$phase.' record binds a staging object that is not there');
			$this->assertEquals(array(), glob($queue.'/*.pending'),
				'and no marker of its own');
			$this->assertEquals(array(), $this->manifestFiles($hash),
				'and no manifest the collector still owes anything for');
			$this->assertTrue($this->retire() === true,
				'a '.$phase.' record that binds nothing no longer blocks retirement');
			$after = erasedataReadDrainState($queue);
			$this->assertEquals(0, is_array($after) && isset($after['journal'])
				? count($after['journal']) : -1,
				'the '.$phase.' record is pruned durably rather than merely ignored');
			$this->assertEquals('disarmed', is_array($after) && isset($after['phase'])
				? $after['phase'] : null,
				'the durable disarm stands after the '.$phase.' record went');
			$removals = $this->scheduleRecords('schedule_remove');
			$this->assertEquals(1, count($removals),
				'exactly one mapped schedule_remove follows a '.$phase.' record');
			$this->assertEquals('erasedata-drainrutorrent',
				count($removals) ? $removals[0]['key'] : null,
				'on the exact drain key');
		}
		// The conservative half, unweakened: a record that still binds ANY of
		// its three halves is kept and refuses.
		$bindings = array(
			'a surviving marker' => 'marker',
			'a surviving staging object' => 'staging',
			'an uncollected manifest of its own generation' => 'manifest',
		);
		foreach($bindings as $label => $kind)
		{
			$this->reset();
			$queue = $this->queuePath();
			rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true, 'val' => array(0));
			$staging = $queue.'/'.$hash.'.'.$generation.'.1.tmp';
			if($kind === 'staging')
				@file_put_contents($staging, "x\n");
			$this->armQueue($queue, $generation, array($hash => $staging), 'retained');
			if($kind === 'marker')
				erasedataQueueRequest($queue, $hash, 1, $generation);
			if($kind === 'manifest')
				@file_put_contents($queue.'/'.$hash.'.'.$generation.'.1.abc.list', "x\n");
			$this->assertTrue($this->retire() === false,
				'a record still bound by '.$label.' refuses retirement');
			$this->assertEquals(0, count($this->scheduleRecords('schedule_remove')),
				'and sends no schedule_remove for it');
			$after = erasedataReadDrainState($queue);
			$this->assertTrue(is_array($after) && isset($after['journal'][$generation]),
				'and the record itself is kept, not pruned, on '.$label);
		}
	}

	// D2, second half. An orphan marker a refused admission left behind must
	// not wedge the scan for ever.
	//
	// The losing batch of the lab's inverse-concurrency probe was refused AFTER
	// its markers were written, and the winning batch had already erased both
	// downloads and deleted their payloads. Those markers survived a container
	// restart and were still being retried: rTorrent answers "info-hash not
	// found" for the download, so no file list can ever be read off it and no
	// manifest can ever be staged -- yet they are `pending`-class candidates, so
	// they pinned retirement open for ever while every tick paid a faulting RPC.
	public function testAnOrphanMarkerNothingCanDischargeIsSettledOnceAndReported()
	{
		$this->reset();
		$invariant = 'a marker whose download rTorrent individually says is gone,'
			.' with no manifest of its generation and no staging object left, is'
			.' discharged ONCE with a classified line instead of being retried'
			.' for ever, and the queue can then retire';
		if(!$this->requireApi(array('erasedataDrainWorkerRun()',
			'erasedataQueueRequest()', 'erasedataWriteDrainState()'), $invariant))
			return;
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		$generation = '0000000000000005';
		$this->assertTrue(erasedataWriteDrainState($queue, array('version' => 1,
			'user' => 'rutorrent', 'generation' => $generation,
			'acknowledged' => $generation, 'phase' => 'armed',
			'journal' => array(), 'diagnostics' => array())) === true,
			'the queue is armed and stable, with no journal record for the marker');
		$this->assertTrue(erasedataQueueRequest($queue, $hash, 1, $generation) === true,
			'and carries the marker a refused admission left behind');
		// Exactly what a real daemon answers for an erased download.
		$this->probe(true, true, array(), 'invalid parameters: info-hash not found');
		$this->frozen(false, array());
		$this->stored(false, array());
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true, 'val' => array(0));
		FileUtil::$log = array();
		$tick = erasedataDrainWorkerRun($this->dependencies());
		$this->assertTrue(is_array($tick), 'the guarded tick runs to a decision');
		$this->assertEquals(array(), glob($queue.'/*.pending'),
			'and discharges a marker nothing can ever turn into a manifest');
		$this->assertEquals(0, count(rXMLRPCRequest::$erased),
			'without erasing anything to do it');
		$this->assertEquals(array(), $this->manifestFiles($hash),
			'and without inventing a manifest for a payload it cannot describe');
		$reported = false;
		foreach(FileUtil::$log as $line)
			if(strpos($line, 'obligation-unrecoverable') !== false
				&& strpos($line, $hash) !== false)
				$reported = true;
		$this->assertTrue($reported,
			'the loss is stated, with its canonical hash, rather than swallowed');
		$retainedAfterDischarge = array();
		foreach(FileUtil::$log as $line)
			if(strpos($line, 'erasedata: paths-unknown ') !== false
				&& strpos($line, 'hash='.$hash) !== false
				&& strpos($line, 'consequence=obligation-retained') !== false)
				$retainedAfterDischarge[] = $line;
		$this->assertEquals(array(), $retainedAfterDischarge,
			'a discharged marker is not also reported as an obligation retained by an unknown path');
		$this->assertTrue(is_array($tick) && isset($tick['retired'])
			&& $tick['retired'] === true,
			'and the same tick can finally retire the schedule it unwedged');
		$removals = $this->scheduleRecords('schedule_remove');
		$this->assertEquals(1, count($removals),
			'with one mapped schedule_remove');
		$this->assertEquals('erasedata-drainrutorrent',
			count($removals) ? $removals[0]['key'] : null,
			'on the exact drain key');
		// Uncertainty is still uncertainty: a probe that answers nothing
		// definite retains the marker exactly as it found it.
		$this->reset();
		$queue = $this->queuePath();
		$this->assertTrue(erasedataWriteDrainState($queue, array('version' => 1,
			'user' => 'rutorrent', 'generation' => $generation,
			'acknowledged' => $generation, 'phase' => 'armed',
			'journal' => array(), 'diagnostics' => array())) === true,
			'the same queue, armed again');
		erasedataQueueRequest($queue, $hash, 1, $generation);
		$this->probe(false, false, array());
		$this->frozen(false, array());
		$this->stored(false, array());
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true, 'val' => array(0));
		erasedataDrainWorkerRun($this->dependencies());
		$this->assertEquals(1, count(glob($queue.'/*.pending')),
			'an unknown probe retains the obligation rather than discharging it');
		$this->assertEquals(0, count($this->scheduleRecords('schedule_remove')),
			'and retires nothing on the strength of an answer nobody got');
	}

	public function testPresentMemberWithUnknownPathsKeepsItsMarkerAndReason()
	{
		$this->reset();
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		$generation = '0000000000000005';
		$this->armQueue($queue, $generation, array());
		$this->assertTrue(erasedataQueueRequest($queue, $hash, 1, $generation),
			'the present member has a durable obligation');
		$this->probe(true, false, array($hash));
		$this->frozen(false, array());
		$this->stored(false, array());
		$notes = array();
		$outcome = erasedataDrainGenerationPass($queue, User::getUser(),
			$generation, array('hashes' => array($hash), 'force' => 1),
			new ErasedataFilesystemOps(), $notes);
		$this->assertTrue(is_array($outcome) && $outcome['retained'] === 1,
			'the present member remains owed when its paths cannot be read');
		$this->assertTrue(erasedataPendingMarkerStands($queue, $hash, $generation),
			'its pending marker is retained');
		$this->assertEquals(array(), rXMLRPCRequest::$erased,
			'no erase is sent without a file list');
		$this->assertNote($notes, 'paths-unknown', $generation, $hash,
			'obligation-retained', 'the surviving obligation keeps its accurate reason');
	}

	public function testDefaultAcknowledgementClockPairsSchedulerWallAndElapsedTime()
	{
		$this->reset();
		$body = $this->guardedFunctionBody('removewithdata.php',
			'function erasedataWaitForDrainAcknowledgement(');
		$factory = '/if\s*\(\s*\$clock\s*===\s*null\s*\)\s*'
			.'\$clock\s*=\s*function\s*\(\s*\)\s*\{\s*'
			.'return\s*\(\s*array\s*\(\s*microtime\s*\(\s*true\s*\)\s*,\s*'
			.'hrtime\s*\(\s*true\s*\)\s*\/\s*1000000000\s*\)\s*\)\s*;\s*\}\s*;/';
		$this->assertTrue(is_string($body) && preg_match($factory, $body) === 1,
			'the default wait clock pairs scheduler wall time with monotonic elapsed time');
	}

	public function testDrainAcknowledgementWaitSurvivesClockStepsInBothDirections()
	{
		$this->reset();
		$queue = $this->dir.'/no-such-queue';
		$cases = array(
			'forward' => array(array(100.0, 100.0), array(200.0, 100.01),
				array(200.0, 100.06)),
			'backward' => array(array(100.0, 100.0), array(90.0, 100.06),
				array(100.06, 100.06)),
		);
		foreach($cases as $direction => $times)
		{
			$calls = 0;
			$clock = function() use ($times, &$calls) {
				if($calls >= count($times))
					throw new RuntimeException('the wait did not finish on both deadlines');
				return($times[$calls++]);
			};
			$timedOut = erasedataWaitForDrainAcknowledgement($queue,
				'0000000000000001', 0.05, 0, $clock);
			$this->assertTrue($timedOut === false && $calls === 3,
				'a '.$direction.' wall-clock step alone cannot end the wait; both'
					.' deadlines must expire (clock reads: '.$calls.')');
		}
	}

	// D3. One acknowledgement releases every producer it is at or ahead of.
	//
	// A tick raises `acknowledged` to whatever generation the durable state
	// carries when it runs, and two producers over DISJOINT hash sets are
	// serialised by no hash lock at all -- one "Remove and delete data" click is
	// one request, so two tabs are enough. With an exact-equality wait the
	// earlier producer's generation became permanently unreachable: measured
	// twice out of two against a real 0.16.21, one door answered false after
	// exactly ERASEDATA_DRAIN_ACK_TIMEOUT while the other published.
	public function testAnAcknowledgementReleasesEveryProducerItIsAtOrAheadOf()
	{
		$this->reset();
		$invariant = 'a child\'s acknowledgement satisfies every producer whose'
			.' generation is at or below the acknowledged one, so two concurrent'
			.' admissions over disjoint hashes never starve each other';
		if(!$this->requireApi(array('erasedataWaitForDrainAcknowledgement()',
			'erasedataWriteDrainState()'), $invariant))
			return;
		$queue = $this->queuePath();
		$state = array('version' => 1, 'user' => 'rutorrent',
			'generation' => '000000000000000e', 'acknowledged' => '000000000000000e',
			'phase' => 'armed', 'journal' => array(), 'diagnostics' => array());
		$this->assertTrue(erasedataWriteDrainState($queue, $state) === true,
			'a later producer has already pushed the state past an earlier one');
		$started = microtime(true);
		$this->assertTrue(erasedataWaitForDrainAcknowledgement($queue,
			'000000000000000d', 2.0, 0.02) === true,
			'the overtaken producer is released by the acknowledgement that passed it');
		$this->assertTrue((microtime(true) - $started) < 1.0,
			'at once, rather than after the whole timeout');
		$this->assertTrue(erasedataWaitForDrainAcknowledgement($queue,
			'000000000000000e', 2.0, 0.02) === true,
			'and so is the producer whose generation matches exactly');
		// The guard is still a guard.
		$state['acknowledged'] = '0000000000000001';
		$this->assertTrue(erasedataWriteDrainState($queue, $state) === true,
			'an acknowledgement that is BEHIND the waited-for generation');
		$started = microtime(true);
		$this->assertTrue(erasedataWaitForDrainAcknowledgement($queue,
			'000000000000000e', 0.30, 0.02) === false,
			'releases nobody');
		$this->assertTrue((microtime(true) - $started) >= 0.25,
			'and the wait really is bounded by its own timeout');
		$this->assertTrue(erasedataWaitForDrainAcknowledgement($this->dir.'/no-such-queue',
			'0000000000000001', 0.10, 0.02) === false,
			'a state nobody can read releases nobody either');

		// The lab reproduction, with two REAL doors over disjoint real hashes.
		//
		// Two things are sequenced deliberately, and neither shortens what is
		// under test. The second door starts only once the first is past its arm
		// and into its acknowledgement wait, because starting both in the same
		// instant sometimes produces the unrelated arm compare-and-swap
		// collision instead of the acknowledgement race this case is about. And
		// the scheduler is held back until BOTH producers have staged, which is
		// what makes the overtake certain rather than lucky: the first tick then
		// acknowledges the SECOND generation while the first producer is still
		// waiting for its own. Everything else is the shipped product --
		// ERASEDATA_DRAIN_ACK_TIMEOUT is still 11.0s and the first door still
		// waits the whole of it if nothing releases it.
		$this->reset();
		$mirror = $this->mirror('disjoint-producers');
		$mirror->scriptRpc($this->drainScript());
		$first = $this->actionDoor($mirror, array($this->hash('A')), 1, 'door-a');
		$armed = false;
		$armedBy = microtime(true) + 20;
		while(microtime(true) < $armedBy)
		{
			$first->pump();
			$observed = $this->mirrorState($mirror);
			if(is_array($observed) && isset($observed['phase'], $observed['journal'])
				&& $observed['phase'] === 'armed' && is_array($observed['journal'])
				&& count($observed['journal']) === 1)
			{
				$armed = true;
				break;
			}
			if(!$first->running())
				break;
			usleep(10000);
		}
		$this->assertTrue($armed,
			'the first producer armed and staged its own generation, and is now'
				.' waiting for an acknowledgement of it');
		$doors = array(
			'first' => $first,
			'second' => $this->actionDoor($mirror, array($this->hash('B')), 1, 'door-b'),
		);
		$ticks = array();
		$startedAt = null;
		$both = false;
		$pending = $doors;
		$deadline = microtime(true) + 60;
		while(count($pending) && microtime(true) < $deadline)
		{
			foreach($pending as $key => $process)
			{
				$process->pump();
				if(!$process->running())
					unset($pending[$key]);
			}
			if(!count($pending))
				break;
			if(!$both)
			{
				$observed = $this->mirrorState($mirror);
				$both = is_array($observed) && isset($observed['journal'])
					&& is_array($observed['journal']) && count($observed['journal']) >= 2;
			}
			if($both)
				$this->schedulerTick($mirror, $ticks, $startedAt);
			usleep(20000);
		}
		ErasedataTestProcess::reapAll($ticks);
		ErasedataTestProcess::reapAll($doors);
		$this->assertTrue(!count($pending),
			$invariant.': both doors finished inside the budget');
		$this->assertTrue($both,
			'both producers really armed disjoint generations at the same time');
		// SUPERSEDED SHAPE. This used to count one <HASH>.*.list per producer in
		// the queue after both doors exited. The worker repair (P02, step (5) of
		// erasedataDrainWorkerRun) makes every drain tick run the real collector
		// over the whole queue directory, so a manifest published by a producer
		// is legitimately consumed by the next tick this loop plays -- and this
		// loop keeps playing the scheduler until BOTH doors have exited, so the
		// residue is gone before the assertion looks. That is the same contract
		// 'drain consumes its final manifest' pins above; the two expectations
		// are contradictory in shape and this is the one that was written
		// against a worker that collected nothing.
		//
		// The stronger replacement is the producer's OWN answer, which is also
		// better attributed: a hash reaches `published` only after THAT producer
		// renamed its own staging into the final manifest and discharged its own
		// pending marker, whereas a .list on disk would equally have been
		// satisfied by a drain-side recovery publishing on the producer's behalf.
		$answers = array();
		foreach($doors as $name => $door)
		{
			$answer = json_decode(trim($door->out), true);
			$this->assertTrue(is_array($answer),
				'the '.$name.' door answered a decodable outcome');
			$answers[$name] = is_array($answer) ? $answer : array();
		}
		foreach(array('A' => 'first', 'B' => 'second') as $character => $door)
		{
			$hash = $this->hash($character);
			$this->assertEquals(array($hash),
				isset($answers[$door]['published']) ? $answers[$door]['published'] : null,
				'the '.$door.' disjoint producer published its own manifest');
			$this->assertEquals(array(),
				isset($answers[$door]['retained']) ? $answers[$door]['retained'] : null,
				'and retained nothing of its own obligation');
			$this->assertEquals(array(),
				isset($answers[$door]['refused']) ? $answers[$door]['refused'] : null,
				'and had nothing of it refused');
			$this->assertEquals(0,
				count(glob($mirror->listPath.'/'.$hash.'.*.pending'))
					+ count(glob($mirror->listPath.'/'.$hash.'.*.tmp')),
				'and left neither an obligation marker nor staging behind');
		}
		$this->assertTrue(isset($answers['first']['generation'],
				$answers['second']['generation'])
			&& $answers['first']['generation'] !== $answers['second']['generation'],
			'each producer completed on its own distinct generation');
		$erased = $this->mirrorErased($mirror);
		sort($erased, SORT_STRING);
		$this->assertEquals(array($this->hash('A'), $this->hash('B')), $erased,
			'and each disjoint hash was erased exactly once');
		foreach($doors as $name => $door)
			$this->assertTrue(strpos($door->out, 'false') === false,
				'and the '.$name.' door answered its user an outcome, not false: '
					.trim(substr($door->out, 0, 160)));
	}

	public function testHistoricalFinalIsCollectedAfterRestartWithoutDrain()
	{
		$this->reset();
		$queue = $this->queuePath();
		$hash = $this->hash();
		$payload = $this->dir.'/historical-final-payload.bin';
		file_put_contents($payload, 'old published payload');
		$bytes = ErasedataManifestCodec::encode($hash,
			array('base' => $payload, 'multi' => '0', 'files' => array($payload)), 1);
		$final = $queue.'/'.$hash.'.0000000000000001.12345678.list';
		file_put_contents($final, $bytes);
		$this->probe(true, true, array(), 'info-hash not found');
		$this->assertTrue(erasedataRearmDrainScheduleRun($this->dependencies()),
			'startup recovery accepts a historical final without inventing a drain obligation');
		$this->assertEquals(0, count($this->scheduleRecords('schedule')),
			'no generation drain is armed for a final outside the admission journal');
		erasedataRunCollector($queue);
		$this->assertTrue(!is_file($payload) && !is_file($final),
			'the ordinary collector discharges the historical final after restart');
	}

	// F1. Startup recovery must never arm a schedule retirement could not take
	// away.
	//
	// The conservative scan is written for RETIREMENT, which must refuse on
	// anything it cannot account for, so it also counts `final`, `malformed`,
	// `residue` and `unknown` entries. Arming on those made the arm predicate
	// and the retire predicate disagree, and the disagreement is reachable in
	// legacy releases: the httprpc producer wrote a
	// <HASH>.<pid>.<uniqid>.list that lives about a collector interval,
	// classifies as `malformed` and never touches the drain state. One full UI
	// load in that window armed erasedata-drain<User> on the ZERO generation --
	// which retirement refuses for ever, silently, for the life of the daemon.
	public function testStartupRecoveryNeverArmsAScheduleRetirementCouldNotTakeAway()
	{
		$this->reset();
		$invariant = 'startup recovery arms only what the drain protocol itself'
			.' owes, and only on a generation retirement could later retire: a'
			.' legacy manifest never arms it, a zero generation never arms it,'
			.' and whatever it does arm can be retired again';
		if(!$this->requireApi(array('erasedataRearmDrainScheduleRun()',
			'erasedataRetirementRun()', 'erasedataRetirementScan()',
			'erasedataReadDrainState()', 'erasedataWriteDrainState()',
			'erasedataQueueRequest()'), $invariant))
			return;
		$queue = $this->queuePath();
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0));
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true, 'val' => array(0));
		// (a) A legacy manifest from before the HTTP RPC admission route;
		// the ordinary collector has not taken it yet.
		$legacy = $queue.'/'.$this->hash('A').'.31337.65f0a1b2c3d4e5.12345678.list';
		@file_put_contents($legacy, "x\n");
		$scan = erasedataRetirementScan($this->dependencies());
		$this->assertTrue(is_array($scan) && $scan['empty'] === false
			&& $scan['classes']['malformed'] >= 1,
			'the legacy manifest really is a candidate the retirement scan refuses on');
		$this->assertTrue(erasedataRearmDrainScheduleRun($this->dependencies()) === true,
			'startup recovery over a queue holding only a legacy manifest succeeds');
		$this->assertEquals(0, count($this->scheduleRecords('schedule')),
			'and arms no drain schedule at all, because the drain protocol owes nothing');
		$state = erasedataReadDrainState($queue);
		$this->assertEquals('0000000000000000', is_array($state)
			&& isset($state['generation']) ? $state['generation'] : null,
			'leaving the zero generation exactly as it found it');
		// (b) A marker whose generation the durable state no longer knows.
		// Arming here would make a schedule retirement refuses for ever, and a
		// worker that refused every job it found with generation-unarmed.
		@unlink($legacy);
		erasedataQueueRequest($queue, $this->hash('B'), 1, '0000000000000009');
		rXMLRPCRequest::$scheduledCommands = array();
		FileUtil::$log = array();
		$this->assertTrue(erasedataRearmDrainScheduleRun($this->dependencies()) === false,
			'an obligation with no admitted generation behind it fails closed');
		$this->assertEquals(0, count($this->scheduleRecords('schedule')),
			'and arms nothing on the zero generation');
		$visible = false;
		foreach(FileUtil::$log as $line)
			if(strpos($line, 'rearm-unarmed') !== false)
				$visible = true;
		$this->assertTrue($visible,
			'and says why, rather than refusing in silence');
		// (c) The property the two above exist for: whatever startup recovery
		// DOES arm, retirement can take away again.
		$this->assertTrue(erasedataWriteDrainState($queue, array('version' => 1,
			'user' => 'rutorrent', 'generation' => '0000000000000009',
			'acknowledged' => '0000000000000009', 'phase' => 'disarmed',
			'journal' => array(), 'diagnostics' => array())) === true,
			'the queue carries the generation that obligation was admitted on');
		rXMLRPCRequest::$scheduledCommands = array();
		$this->assertTrue(erasedataRearmDrainScheduleRun($this->dependencies()) === true,
			'a real outstanding obligation on an admitted generation re-arms');
		$registrations = $this->scheduleRecords('schedule');
		$this->assertEquals(1, count($registrations), 'exactly once');
		$this->assertEquals('erasedata-drainrutorrent',
			count($registrations) ? $registrations[0]['key'] : null,
			'on the exact per-user drain key');
		foreach(glob($queue.'/*.pending') as $marker)
			@unlink($marker);
		$this->assertTrue($this->retire() === true,
			'and the schedule that arm created really can be retired again');
		$this->assertEquals(1, count($this->scheduleRecords('schedule_remove')),
			'with one mapped schedule_remove');
		$this->assertEquals('erasedata-drainrutorrent',
			count($this->scheduleRecords('schedule_remove'))
				? $this->scheduleRecords('schedule_remove')[0]['key'] : null,
			'on the same key it armed');
	}

	// F2. Re-registering the drain key must not postpone its own countdown.
	//
	// php/getplugins.php re-runs every enabled plugin's init.php on EVERY full
	// load of the web interface, and rTorrent replaces the entry for a reused
	// key and restarts its countdown at now+start. With start == interval, two
	// page reloads inside the 11 s acknowledgement window pushed the first tick
	// past a blocked producer's timeout -- and reloading is exactly what a user
	// does when a deletion looks stuck. php/settings.php:450-484 documents
	// getAlignedStart() as the fix for this exact bug and the sibling periodic
	// collector schedule already uses it.
	public function testEveryDrainRegistrationResolvesToTheSameAlignedFireInstant()
	{
		$this->reset();
		$invariant = 'the drain registration takes its start from'
			.' rTorrentSettings::getAlignedStart, so every re-registration of the'
			.' key resolves to the same absolute fire instant and no number of'
			.' page loads can postpone a live countdown';
		$body = $this->productionFunctionBody('removewithdata.php',
			'function erasedataDrainScheduleCommand($user, $interval, $rescue = false)');
		$this->assertTrue(is_string($body)
			&& strpos($body, 'rTorrentSettings::getAlignedStart(') !== false,
			'erasedataDrainScheduleCommand() builds its start with the aligned helper');
		if(!$this->requireApi(array('erasedataDrainScheduleCommand()',
			'erasedataRearmDrainScheduleRun()', 'erasedataWriteDrainState()',
			'erasedataQueueRequest()', 'rTorrentSettings::getAlignedStart'), $invariant))
			return;
		$key = 'erasedata-drainrutorrent';
		$interval = ERASEDATA_DRAIN_INTERVAL;
		// The property itself, over a whole span of registration instants: one
		// key, one absolute fire slot, and never an immediate firing.
		$slots = array();
		for($now = 1700000000; $now < 1700000000 + 4 * $interval; $now++)
		{
			$start = rTorrentSettings::getAlignedStart($key, $interval, $now);
			$this->assertTrue(is_int($start) && $start >= 1 && $start <= $interval,
				'an aligned start stays inside the interval at '.$now.': '.$start);
			$slots[($now + $start) % $interval] = true;
		}
		$this->assertEquals(1, count($slots),
			'every registration instant resolves to the one absolute fire slot');
		// And the shipped command really carries that start, not now+interval.
		$expected = null;
		$argv = array();
		for($attempt = 0; $attempt < 5; $attempt++)
		{
			$at = time();
			$command = erasedataDrainScheduleCommand('rutorrent', $interval);
			$expected = rTorrentSettings::getAlignedStart($key, $interval, $at);
			$argv = ($command instanceof rXMLRPCCommand) && is_array($command->params)
				? $command->params : array();
			if($at === time())
				break;
		}
		$this->assertEquals($key, isset($argv[0]) ? $argv[0] : null,
			'the registration names the exact per-user drain key');
		$this->assertEquals((string)$expected, isset($argv[1]) ? $argv[1] : null,
			'and starts at the aligned instant rather than a fresh countdown');
		$this->assertEquals((string)$interval, isset($argv[2]) ? $argv[2] : null,
			'while the repeat interval is unchanged');
		// Behaviourally: three plugin inits over one owed obligation really do
		// re-register three times -- getplugins.php gives no choice about that
		// -- and every one of them names the aligned start for the moment it
		// was made, so none of them moves the fire instant.
		$queue = $this->queuePath();
		$stamps = array();
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0),
			'callback' => function($commands) use (&$stamps) { $stamps[] = time(); });
		$this->assertTrue(erasedataWriteDrainState($queue, array('version' => 1,
			'user' => 'rutorrent', 'generation' => '0000000000000007',
			'acknowledged' => '0000000000000007', 'phase' => 'disarmed',
			'journal' => array(), 'diagnostics' => array())) === true,
			'the queue carries an admitted generation');
		erasedataQueueRequest($queue, $this->hash('A'), 1, '0000000000000007');
		for($init = 0; $init < 3; $init++)
			erasedataRearmDrainScheduleRun($this->dependencies());
		$registrations = $this->scheduleRecords('schedule');
		$this->assertEquals(3, count($registrations),
			'three plugin inits really do re-register the key three times');
		foreach($registrations as $index => $record)
		{
			$start = isset($record['argv'][1]) ? (string)$record['argv'][1] : 'none';
			$stamp = isset($stamps[$index]) ? $stamps[$index] : null;
			$aligned = $stamp === null ? array() : array(
				(string)rTorrentSettings::getAlignedStart($key, $interval, $stamp),
				(string)rTorrentSettings::getAlignedStart($key, $interval, $stamp - 1));
			$this->assertTrue(in_array($start, $aligned, true),
				'registration '.$index.' carries the aligned start for the moment it'
					.' was made, not a fresh interval: '.$start);
		}
	}

	// F3. An empty scan must not leave a stale `armed` claim standing.
	//
	// rTorrent's schedule table is volatile and plugin init is the one moment
	// the loss is plausible. Writing nothing there left the durable phase saying
	// `armed` over a table the restart had just emptied; the producer's own
	// live-schedule guard then trusted that claim, registered NO schedule,
	// waited the whole acknowledgement timeout and refused -- leaving a marker
	// with nothing scheduled to drain it.
	public function testStartupRecoveryClearsAStaleArmedClaimWhenNothingIsOwed()
	{
		$this->reset();
		$invariant = 'a plugin init over a queue that owes nothing writes'
			.' disarmed, so a daemon restart cannot leave an armed claim that'
			.' makes the next producer register nothing and time out';
		if(!$this->requireApi(array('erasedataRearmDrainScheduleRun()',
			'erasedataRemovalAdmissionRun()', 'erasedataReadDrainState()',
			'erasedataWriteDrainState()'), $invariant))
			return;
		$queue = $this->queuePath();
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0));
		$this->assertTrue(erasedataWriteDrainState($queue, array('version' => 1,
			'user' => 'rutorrent', 'generation' => '0000000000000004',
			'acknowledged' => '0000000000000004', 'phase' => 'armed',
			'journal' => array(), 'diagnostics' => array())) === true,
			'the queue carries the armed claim a completed admission left behind');
		$this->assertTrue(erasedataRearmDrainScheduleRun($this->dependencies()) === true,
			'startup recovery over a queue that owes nothing succeeds');
		$this->assertEquals(0, count($this->scheduleRecords('schedule')),
			'and arms nothing, so no live countdown anywhere is restarted');
		$state = erasedataReadDrainState($queue);
		$this->assertEquals('disarmed', is_array($state) && isset($state['phase'])
			? $state['phase'] : null,
			'but it does correct the claim the restart invalidated');
		$this->assertEquals('0000000000000004', is_array($state)
			&& isset($state['generation']) ? $state['generation'] : null,
			'without inventing or losing a generation to do it');
		// The consequence, measured: the first removal after the restart really
		// registers again, and really completes.
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		$this->probe(true, false, array($this->hash('A')));
		$this->acknowledgeOnRegistration($queue);
		$outcome = erasedataRemovalAdmissionRun($this->dependencies(),
			array($this->hash('A')), 1);
		$this->assertTrue(is_array($outcome) && isset($outcome['published'])
			&& $outcome['published'] === array($this->hash('A')),
			'the first removal after the restart is admitted and published');
		$this->assertEquals(1, count($this->scheduleRecords('schedule')),
			'because it registered the drain schedule the restart had lost');
		// The other side of the same correction: a registration still IN FLIGHT
		// is left exactly as it is. The producer releases this very lock across
		// its schedule RPC, so a page load that rewrote the phase on nothing but
		// the phase itself would break a perfectly good admission -- it keeps
		// its HASH locks across that RPC, and a held one is the proof somebody
		// is still inside the window.
		// testAnAbandonedArmingIsCorrectedOnlyWhenNoProducerHoldsTheQueue owns
		// both directions of that decision; this is the half that belongs to the
		// startup-recovery guard itself.
		$this->reset();
		$queue = $this->queuePath();
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0));
		$this->assertTrue(erasedataWriteDrainState($queue, array('version' => 1,
			'user' => 'rutorrent', 'generation' => '0000000000000005',
			'acknowledged' => '0000000000000004', 'phase' => 'arming',
			'journal' => array(), 'diagnostics' => array())) === true,
			'the queue carries a registration a producer has in flight right now');
		$inFlight = erasedataAcquireHashLock($queue, $this->hash('A'), true);
		$this->assertTrue(is_resource($inFlight),
			'and the producer really holds the hash lock it took before it armed');
		$this->assertTrue(erasedataRearmDrainScheduleRun($this->dependencies()) === true,
			'startup recovery over it still succeeds');
		$state = erasedataReadDrainState($queue);
		$this->assertEquals('arming', is_array($state) && isset($state['phase'])
			? $state['phase'] : null,
			'and leaves the arm in flight exactly as it found it');
		$this->assertEquals(0, count($this->scheduleRecords('schedule')),
			'without registering a second schedule over it');
		erasedataReleaseHashLock($inFlight);
	}

	// A comment that contradicts the code is worse than no comment, and a
	// dependency key nothing reads is worse than no key.
	//
	// Every statement pinned here was TRUE once and was reversed by a later
	// fix without the sentence being reversed with it. Each of them cost a
	// reviewer real time: the empty user "has no schedule key" after the fix
	// that made '' a real user; a duplicate erasedataSharedFileMode() in
	// removewithdata.php that does not exist; a 'forceEnabled' dependency the
	// same file explicitly says it does not have; a 'filesystem' key built on
	// every full web-interface load and thrown away unread; and a self-reporting
	// retirement wrapper only the tests could ever reach.
	public function testTheShippedSourceCarriesNoStatementItHasSinceReversed()
	{
		$this->reset();
		// m3: the empty user IS a real user, in the comment as well as the code.
		$this->sourceLacks('removewithdata.php',
			'An empty or oversized user has no schedule key',
			'the worker guard no longer claims the empty user has no schedule key');
		$this->sourceLacks('removewithdata.php',
			'with the nonempty user the schedule key was built from',
			'and neither does the command the scheduler runs');
		// A2: one owner for the shared file mode, stated once.
		$this->sourceLacks('filesystem.php',
			'the same guarded definition for the load orders',
			'filesystem.php no longer claims a duplicate erasedataSharedFileMode()');
		$this->assertEquals(1, substr_count((string)$this->productionSource('filesystem.php')
			.(string)$this->productionSource('removewithdata.php')
			.(string)$this->productionSource('pending.php'),
			'function erasedataSharedFileMode('),
			'because there really is exactly one definition of it');
		// A2: the admission dependency list, as the runner really reads it.
		$this->sourceLacks('removewithdata.php',
			'filesystem, log, forceEnabled',
			'the admission runner no longer lists a forceEnabled dependency');
		$this->sourceLacks('removewithdata.php', "'forceEnabled' =>",
			'and nothing in the shipped plugin passes one');
		// A1: the re-arm builds exactly what its runner reads.
		// Delimited by the guard, and scoped by hand to these two: each is the
		// only function inside its own if(!function_exists(...)) guard. Nothing
		// in that file is declared at column zero, so a body taken to the next
		// column-zero "function " would run to the end of it and be satisfied by
		// any other function's keys. A few guards there do hold more than one
		// function, so this extractor is only ever pointed at one that does not.
		$body = $this->guardedFunctionBody('removewithdata.php',
			'function erasedataRearmDrainSchedule()');
		$this->assertTrue(is_string($body) && strpos($body, "'listPath' =>") !== false
			&& strpos($body, "'user' =>") !== false
			&& strpos($body, "'log' =>") !== false,
			'the re-arm wrapper builds listPath, user and log');
		$this->assertTrue(is_string($body) && strpos($body, "'filesystem' =>") === false,
			'and no filesystem key, which erasedataRearmDrainScheduleRun() never reads');
		$run = $this->guardedFunctionBody('removewithdata.php',
			'function erasedataRearmDrainScheduleRun(array $dependencies)');
		$this->assertTrue(is_string($run) && strpos($run, "['filesystem']") === false,
			'and the runner really does not read one');
		// B1: retirement has no self-reporting wrapper for callers production
		// never had. Its notes are required, and the tick owns the reporting.
		$this->sourceLacks('removewithdata.php', 'erasedataRetireDrainSchedule',
			'the reporting wrapper only the tests could reach is gone');
		$this->sourceHas('removewithdata.php',
			'function erasedataRetirementRun(array $dependencies, array &$notes)',
			'and retirement requires the notes list its one production caller reports');
		$callers = $this->productionCallers('erasedataRetirementRun($');
		$this->assertTrue(in_array('removewithdata.php', $callers, true),
			'which really is called in production (found in: '.implode(', ', $callers).')');
	}

	// N2. A member that FINISHED must not be reported as a loss.
	//
	// The settle step's last resort discharges a member rTorrent says is gone
	// when no manifest of its generation survives and no staging object does
	// either. Entirely different members satisfy that. One completed perfectly
	// -- its manifest was published, the collector consumed it and deleted the
	// payload, and its marker was discharged by whoever finished it, so its
	// manifest is absent BECAUSE collection worked. Another is a real loss: a
	// marker still standing for a download that no longer exists, which no
	// later tick can ever construct a file list for.
	//
	// Reported as one reason, the log tells an operator that removals which in
	// fact completed had lost their payload. Invariant 12 asks for the
	// consequence to be TRUE, not merely present, so the completed member is
	// separated out by the marker -- the obligation record itself. This case
	// pins that separation only; a member served under ANOTHER generation is
	// indistinguishable here once the winning manifest has been collected, and
	// is still reported as unrecoverable (see the settle step's comment).
	public function testACompletedMemberIsNotReportedAsAnUnrecoverableLoss()
	{
		$this->reset();
		$invariant = 'a member whose obligation is already discharged is'
			.' classified as complete, and only a marker nothing can ever'
			.' discharge is classified as an unrecoverable loss';
		if(!$this->requireApi(array('erasedataDrainWorkerRun()',
			'erasedataPendingMarkerStands()', 'erasedataQueueRequest()',
			'erasedataPendingMarkerPath()'), $invariant))
			return;
		$queue = $this->queuePath();
		$generation = '0000000000000005';
		$finished = $this->hash('A');
		$lost = $this->hash('B');
		// Both members were staged under this generation and neither staging
		// object survives: one was renamed into its final manifest and then
		// collected, the other never got that far.
		$this->armQueue($queue, $generation, array(
			$finished => $queue.'/'.$finished.'.'.$generation.'.1.tmp',
			$lost => $queue.'/'.$lost.'.'.$generation.'.1.tmp',
		), 'erase-started');
		// The obligation record is what separates them. The finished member's
		// marker was discharged the instant its producer completed; the lost
		// member's is still standing.
		$this->assertTrue(erasedataQueueRequest($queue, $lost, 1, $generation) === true,
			'the member nothing can recover still carries its obligation marker');
		$this->assertTrue(!file_exists(erasedataPendingMarkerPath($queue, $finished, $generation)),
			'while the member that completed carries none');
		$this->assertTrue(erasedataPendingMarkerStands($queue, $lost, $generation) === true
			&& erasedataPendingMarkerStands($queue, $finished, $generation) === false,
			'and the marker probe tells the two apart');
		// rTorrent answers individually that both downloads are gone.
		$this->probe(true, true, array(), 'invalid parameters: info-hash not found');
		FileUtil::$log = array();
		$tick = erasedataDrainWorkerRun($this->dependencies());
		$this->assertTrue(is_array($tick), 'the production tick runs to a decision');

		$unrecoverable = array();
		$complete = array();
		foreach(FileUtil::$log as $line)
		{
			if(strpos($line, 'obligation-unrecoverable') !== false)
				$unrecoverable[] = $line;
			if(strpos($line, 'obligation-complete') !== false)
				$complete[] = $line;
		}
		$this->assertEquals(1, count($unrecoverable),
			'exactly one member is reported as unrecoverable');
		$this->assertTrue(count($unrecoverable) === 1
			&& strpos($unrecoverable[0], 'hash='.$lost) !== false,
			'and it is the one whose marker nothing could ever discharge');
		$this->assertTrue(count($unrecoverable) === 1
			&& strpos($unrecoverable[0], 'download-gone-no-manifest-marker-discharged') !== false,
			'stated with the consequence that really happened to it');
		$this->assertEquals(1, count($complete),
			'and exactly one is reported as already complete');
		$this->assertTrue(count($complete) === 1
			&& strpos($complete[0], 'hash='.$finished) !== false,
			'which is the member that had nothing left to owe');
		$this->assertTrue(count($complete) === 1
			&& strpos($complete[0], 'download-gone-obligation-already-discharged') !== false,
			'and says so rather than claiming a loss that did not happen');
		foreach($unrecoverable as $line)
			$this->assertTrue(strpos($line, 'hash='.$finished) === false,
				'no unrecoverable line names the member that completed');

		// The action taken is the same one that was always taken, and it is
		// still right: the standing marker is discharged, the tick counts one
		// loss and one completion, and nothing is left owed.
		$this->assertTrue(!file_exists(erasedataPendingMarkerPath($queue, $lost, $generation)),
			'the marker nothing could recover is discharged');
		$this->assertEquals(1, is_array($tick) && isset($tick['unrecoverable'])
			? $tick['unrecoverable'] : null,
			'the tick counts exactly one unrecoverable obligation');
		$this->assertEquals(0, is_array($tick) && isset($tick['cancelled'])
			? $tick['cancelled'] : null,
			'and none of it is counted as a cancellation: they are not the same outcome');
		$this->assertEquals(1, is_array($tick) && isset($tick['published'])
			? $tick['published'] : null,
			'the completed member is counted as discharged, not as a loss');
		$this->assertEquals(0, is_array($tick) && isset($tick['retained'])
			? $tick['retained'] : null,
			'and nothing is left owed by either of them');
		$state = erasedataReadDrainState($queue);
		$this->assertEquals(array(), is_array($state) && isset($state['journal'])
			? $state['journal'] : null,
			'and the record that bound them both is closed');
	}

	// Invariant 2's arithmetic has ONE builder.
	//
	// erasedataClassifyEraseOutcomes() partitions the reply list by
	// ERASEDATA_ERASE_COMMANDS_PER_HASH: member i is individually accepted only
	// when the list is long enough to hold that member's own third reply. The
	// file used to build that request in three separate places -- the producer,
	// the drain pass and the legacy httprpc producer -- while the constant's
	// own comment demanded they stay in step. A builder that ever gained or
	// lost a command would mis-partition `E` SILENTLY and publish a final
	// manifest, a licence to delete a payload, for a download rTorrent still
	// holds.
	public function testTheEraseRequestHasOneBuilderThatCannotDriftFromItsConstant()
	{
		$this->reset();
		$invariant = 'every destructive erase request is built by one function'
			.' whose length per member is exactly the constant'
			.' erasedataClassifyEraseOutcomes() partitions by';
		if(!$this->requireApi(array('erasedataEraseRequest()',
			'erasedataEraseCommandsForHash()',
			'erasedataClassifyEraseOutcomes()'), $invariant))
			return;
		// The builder and the constant agree, measured on the commands a real
		// request really carried rather than read off the source.
		$this->eraseOk();
		foreach(array(array($this->hash('A')),
			array($this->hash('A'), $this->hash('B')),
			array($this->hash('A'), $this->hash('B'), $this->hash('C'))) as $batch)
		{
			$request = erasedataEraseRequest($batch);
			$this->assertTrue($request instanceof rXMLRPCRequest,
				'a batch of '.count($batch).' builds a request');
			rXMLRPCRequest::$commandCalls = array();
			if($request instanceof rXMLRPCRequest)
				$request->run();
			$sent = count(rXMLRPCRequest::$commandCalls)
				? rXMLRPCRequest::$commandCalls[0] : array();
			$this->assertEquals(count($batch) * ERASEDATA_ERASE_COMMANDS_PER_HASH,
				count($sent),
				'carrying exactly ERASEDATA_ERASE_COMMANDS_PER_HASH commands per member');
		}
		$this->assertEquals(ERASEDATA_ERASE_COMMANDS_PER_HASH,
			count(erasedataEraseCommandsForHash($this->hash('A'))),
			'and the per-member builder itself is exactly that long');
		// The order is part of the contract: the classifier counts positions.
		$names = array();
		foreach(erasedataEraseCommandsForHash($this->hash('A')) as $command)
			$names[] = $command->command;
		$this->assertEquals(array('d.set_custom5', 'd.delete_tied', 'd.erase'),
			$names, 'in the exact order the classifier partitions by');

		// The drift guard. Every destructive call site in the shipped plugin
		// goes through the one builder, so no two of them can disagree: the
		// erase commands are written down in exactly one place.
		$callers = $this->productionCallers('erasedataEraseRequest(');
		$this->assertTrue(in_array('removewithdata.php', $callers, true),
			'the builder has a production caller (found in: '.implode(', ', $callers).')');
		$bytes = $this->productionSource('removewithdata.php');
		$this->assertTrue(is_string($bytes) && $bytes !== '',
			'the shipped producer is readable for inspection');
		foreach(array('d.erase', 'd.delete_tied', 'd.set_custom5') as $command)
		{
			$written = 0;
			foreach(array("getCmd('".$command."')", 'getCmd("'.$command.'")') as $spelling)
			{
				$offset = 0;
				while(is_string($bytes)
					&& ($at = strpos($bytes, $spelling, $offset)) !== false)
				{
					$written++;
					$offset = $at + 1;
				}
			}
			$this->assertEquals(1, $written,
				$command.' is written in exactly one place in the shipped plugin,'
					.' so no second builder can drift from the constant');
		}
		// And the classifier really does read the constant rather than a
		// hardcoded three of its own.
		$this->sourceHas('removewithdata.php',
			'* ERASEDATA_ERASE_COMMANDS_PER_HASH',
			'the classifier derives its per-member stride from the constant');
	}

	// N1. Two removals started together must both happen.
	//
	// The arm is a compare-and-swap over the durable state, and the state lock
	// is released across the registration RPC, so two producers that start from
	// a queue AT REST both write their own `arming` and the one that wrote
	// first no longer finds it when it comes back. Before retirement worked,
	// `armed` was the resting state and a second producer never entered the
	// window at all; now `disarmed` is the resting state and the collision is
	// the COMMON case -- 6 of 6 on a real daemon, where "select two torrents,
	// remove and delete data" left one of them silently untouched.
	//
	// The loser has staged nothing, written no marker and erased nothing, so it
	// re-enters the arm instead of refusing the user's removal.
	public function testTheArmCompareAndSwapLoserRetriesInsteadOfRefusing()
	{
		$this->reset();
		$invariant = 'a producer that loses the arm compare-and-swap re-enters'
			.' the arm with a new generation and completes the removal, rather'
			.' than refusing an admission that had staged nothing';
		if(!$this->requireApi(array('erasedataRemovalAdmissionRun()',
			'erasedataReadDrainState()', 'erasedataWriteDrainState()'), $invariant))
			return;
		if(!defined('ERASEDATA_ADMISSION_ARM_ATTEMPTS'))
		{
			$this->assertTrue(false, $invariant
				.' [not implemented yet: ERASEDATA_ADMISSION_ARM_ATTEMPTS]');
			return;
		}
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		$this->probe(true, false, array($hash));
		// Another producer wins the swap: at the instant this one registers, the
		// durable state carries somebody else's completed arm. That is exactly
		// what the loser observes on a real daemon.
		$stolen = '00000000000000aa';
		$registrations = 0;
		// The winner is itself still `arming` when this producer comes back --
		// the exact shape of the collision, since both producers wrote `arming`
		// from the same resting state.
		$this->acknowledgeOnRegistration($queue,
			function($commands) use ($queue, $stolen, &$registrations)
			{
				$registrations++;
				if($registrations > 1)
					return;
				erasedataWriteDrainState($queue, array('version' => 1,
					'user' => 'rutorrent', 'generation' => $stolen,
					'acknowledged' => '0000000000000000', 'phase' => 'arming',
					'journal' => array(), 'diagnostics' => array()));
			});
		FileUtil::$log = array();
		$outcome = erasedataRemovalAdmissionRun($this->dependencies(),
			array($hash), 1);
		$this->assertTrue(is_array($outcome) && isset($outcome['published'])
			&& $outcome['published'] === array($hash),
			'the removal the loser was asked for really is published');
		$this->assertEquals(2, $registrations,
			'because the loser re-entered the arm exactly once');
		$lost = 0;
		foreach(FileUtil::$log as $line)
			if(strpos($line, 'arm-lost') !== false)
				$lost++;
		$this->assertEquals(0, $lost,
			'so nothing is reported as a lost arm at all');
		$state = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($state) && isset($state['phase'])
			&& $state['phase'] === 'armed',
			'and the queue is left armed on the generation the removal used');
		$this->assertTrue(is_array($state) && isset($state['generation'])
			&& erasedataGenerationCompare($state['generation'], $stolen) === 1,
			'which is strictly past the generation the winner had claimed');

		// The other shape: the winner's arm has already LANDED. The retry does
		// not take it on trust -- it re-reads the durable phase under the state
		// lock, exactly as any second removal onto a live queue does -- and then
		// stages under its own new generation instead of refusing. Nothing
		// acknowledges it in this process, so the admission still ends at the
		// acknowledgement wait; what matters is that it got there at all.
		$this->reset();
		$queue = $this->queuePath();
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		$this->probe(true, false, array($hash));
		$registrations = 0;
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0),
			'callback' => function($commands) use ($queue, $stolen, &$registrations)
			{
				$registrations++;
				erasedataWriteDrainState($queue, array('version' => 1,
					'user' => 'rutorrent', 'generation' => $stolen,
					'acknowledged' => $stolen, 'phase' => 'armed',
					'journal' => array(), 'diagnostics' => array()));
			});
		FileUtil::$log = array();
		$onLiveArm = erasedataRemovalAdmissionRun($this->dependencies(),
			array($hash), 1);
		$this->assertTrue($onLiveArm === false,
			'with nothing to acknowledge it, the admission still ends in a refusal');
		$this->assertEquals(1, $registrations,
			'but the retry rode the arm the winner had already landed');
		$reasons = array();
		foreach(FileUtil::$log as $line)
		{
			if(strpos($line, 'arm-lost') !== false)
				$reasons[] = 'arm-lost';
			if(strpos($line, 'drain-no-ack') !== false)
				$reasons[] = 'drain-no-ack';
		}
		$this->assertEquals(array('drain-no-ack'), $reasons,
			'and it failed at the acknowledgement, never at the arm it had verified itself');

		// The bound. A queue where every registration loses the swap refuses
		// after ERASEDATA_ADMISSION_ARM_ATTEMPTS passes, and refuses the way it
		// always did: nothing staged, no marker, no erase.
		$this->reset();
		$queue = $this->queuePath();
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		$this->probe(true, false, array($hash));
		$registrations = 0;
		rXMLRPCRequest::$responses['schedule'] = array('ok' => true, 'val' => array(0),
			'callback' => function($commands) use ($queue, $stolen, &$registrations)
			{
				$registrations++;
				erasedataWriteDrainState($queue, array('version' => 1,
					'user' => 'rutorrent', 'generation' => $stolen,
					'acknowledged' => '0000000000000000', 'phase' => 'arming',
					'journal' => array(), 'diagnostics' => array()));
			});
		FileUtil::$log = array();
		$refused = erasedataRemovalAdmissionRun($this->dependencies(),
			array($hash), 1);
		$this->assertTrue($refused === false,
			'an arm that never lands refuses the admission');
		$this->assertEquals(ERASEDATA_ADMISSION_ARM_ATTEMPTS, $registrations,
			'after exactly the bounded number of passes, never more');
		$lost = 0;
		foreach(FileUtil::$log as $line)
			if(strpos($line, 'arm-lost') !== false)
				$lost++;
		$this->assertEquals(1, $lost,
			'and says so once, classified, when it finally gives up');
		$this->assertEquals(array(), $this->queueEntries($queue) === array()
			? array() : array_values(array_filter($this->queueEntries($queue),
				function($e) { return(substr($e, 0, 1) !== '.'
					&& substr($e, -5) !== '.lock'); })),
			'with no marker, staging or manifest left behind by any of them');
	}

	// N1/F1's other door: an `arming` phase nothing will ever correct.
	//
	// A producer that dies -- or simply fails -- between its successful
	// registration and its compare-and-swap leaves the durable phase `arming`
	// over a schedule rTorrent really is running. Retirement refuses on
	// `arming` for ever (`retire-unstable`), so `update.php <User> drain` is
	// then spawned every ERASEDATA_DRAIN_INTERVAL seconds for the life of the
	// daemon and nothing in the plugin can take it away.
	//
	// The correction has to be a PROOF, not a guess: the producer releases the
	// state lock across its registration, so rewriting the phase on nothing but
	// the phase itself would break a healthy admission. It keeps its HASH locks
	// across that RPC, and that is the evidence -- a queue whose every hash lock
	// is free holds no producer at all.
	public function testAnAbandonedArmingIsCorrectedOnlyWhenNoProducerHoldsTheQueue()
	{
		$this->reset();
		$invariant = 'a durable arming phase no producer holds is corrected to'
			.' disarmed so retirement can converge, while one a producer really'
			.' is inside is left exactly as it was found';
		if(!$this->requireApi(array('erasedataRearmDrainScheduleRun()',
			'erasedataNoAdmissionHoldsThisQueue()', 'erasedataRetirementRun()',
			'erasedataAcquireHashLock()', 'erasedataReleaseHashLock()'), $invariant))
			return;
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		$wedged = array('version' => 1, 'user' => 'rutorrent',
			'generation' => '0000000000000005', 'acknowledged' => '0000000000000005',
			'phase' => 'arming', 'journal' => array(), 'diagnostics' => array());

		// (a) A producer really is inside the arm window: it holds the hash lock
		// it took before it wrote `arming`. Nothing may touch the phase.
		$this->assertTrue(erasedataWriteDrainState($queue, $wedged) === true,
			'the queue carries a registration a producer has in flight right now');
		$held = erasedataAcquireHashLock($queue, $hash, true);
		$this->assertTrue(is_resource($held),
			'and that producer really holds the hash lock it took before it armed');
		$this->assertTrue(erasedataNoAdmissionHoldsThisQueue($queue) === false,
			'so the queue is not provably free of admissions');
		$this->assertTrue(erasedataRearmDrainScheduleRun($this->dependencies()) === true,
			'startup recovery over it still succeeds');
		$state = erasedataReadDrainState($queue);
		$this->assertEquals('arming', is_array($state) && isset($state['phase'])
			? $state['phase'] : null,
			'and leaves the arm in flight exactly as it found it');
		$this->assertEquals(0, count($this->scheduleRecords('schedule')),
			'without registering a second schedule over it');
		rXMLRPCRequest::$responses['schedule_remove'] = array('ok' => true, 'val' => array(0));
		$this->assertTrue($this->retire() === false,
			'and retirement keeps refusing it, as it must');
		$this->assertEquals(0, count($this->scheduleRecords('schedule_remove')),
			'with no schedule_remove sent on an admission in flight');

		// (b) The producer is gone. Its lock is free, nothing is owed, and the
		// phase it left is corrected once and reported.
		erasedataReleaseHashLock($held);
		$this->assertTrue(erasedataNoAdmissionHoldsThisQueue($queue) === true,
			'a queue whose every hash lock is free holds no admission');
		FileUtil::$log = array();
		$this->assertTrue(erasedataRearmDrainScheduleRun($this->dependencies()) === true,
			'startup recovery succeeds over the queue the producer abandoned');
		$state = erasedataReadDrainState($queue);
		$this->assertEquals('disarmed', is_array($state) && isset($state['phase'])
			? $state['phase'] : null,
			'and corrects the arming phase nothing else would ever have corrected');
		$this->assertEquals('0000000000000005', is_array($state)
			&& isset($state['generation']) ? $state['generation'] : null,
			'without inventing or losing a generation to do it');
		$this->assertEquals(0, count($this->scheduleRecords('schedule')),
			'and without registering anything: the queue owes nothing');
		$visible = false;
		foreach(FileUtil::$log as $line)
			if(strpos($line, 'rearm-arming-abandoned') !== false)
				$visible = true;
		$this->assertTrue($visible,
			'the correction is classified and said, not made in silence');
		// The consequence, and the whole reason the correction exists: the
		// schedule that fired every interval for ever can finally be retired.
		$this->assertTrue($this->retire() === true,
			'retirement now converges on the queue that was wedged');
		$removals = $this->scheduleRecords('schedule_remove');
		$this->assertEquals(1, count($removals),
			'with exactly one mapped schedule_remove');
		$this->assertEquals('erasedata-drainrutorrent',
			count($removals) ? $removals[0]['key'] : null,
			'on the exact per-user drain key');

		// (c) The evidence itself. The proof above is only worth anything
		// because a producer really does hold its hash locks THROUGH the
		// registration RPC -- that release was the one moment in a whole
		// admission when it held none.
		$this->reset();
		$queue = $this->queuePath();
		$this->frozen(true, array('/d/name', 1, '/d/name/a.bin'));
		$this->eraseOk();
		$this->probe(true, false, array($hash));
		$freeAtRegistration = null;
		$this->acknowledgeOnRegistration($queue,
			function($commands) use ($queue, $hash, &$freeAtRegistration)
			{
				$probe = erasedataAcquireHashLock($queue, $hash, true);
				$freeAtRegistration = is_resource($probe);
				erasedataReleaseHashLock($probe);
			});
		$outcome = erasedataRemovalAdmissionRun($this->dependencies(),
			array($hash), 1);
		$this->assertTrue(is_array($outcome) && isset($outcome['published'])
			&& $outcome['published'] === array($hash),
			'a healthy admission still completes end to end');
		$this->assertTrue($freeAtRegistration === false,
			'and its hash lock is held THROUGH the registration RPC, never released across it');
	}

	// F4. The plugin-init re-arm must not put a page load behind the state lock.
	//
	// erasedataRearmDrainScheduleRun() runs on php/getplugins.php. A producer
	// holds the same lock across one d.get_base_path per accepted hash plus
	// every marker and staging write, and retirement holds it across the removal
	// RPC, so a BLOCKING acquisition here stalls a full UI load for as long as
	// either of those takes. A re-arm that cannot get the lock has learned
	// something real -- another actor is already managing this state -- and the
	// next page load retries at no cost.
	//
	// Pinned on the function body rather than behaviourally on purpose: a
	// behavioural case would have to hold the lock and then call the re-arm, and
	// under a regression that call blocks for ever, which hangs the whole suite
	// instead of failing one assertion.
	public function testForceTwoAdmissionRefusesBeforeStagingWhenDescriptorsAreUnavailable()
	{
		$this->reset();
		$base = $this->dir.'/no-descriptor-base';
		mkdir($base);
		file_put_contents($base.'/payload', 'preserve');
		$hash = $this->hash('A');
		$this->frozen(true, array($base, 1, $base.'/payload'));
		$this->eraseOk();
		$this->acknowledgeOnRegistration($this->queuePath());
		$filesystem = new ErasedataCollectorFixture(array(
			'openDirectoryReference:*' => array('result' => false),
		));
		$outcome = erasedataRemovalAdmissionRun($this->dependencies(array(
			'filesystem' => $filesystem,
		)), array($hash), 2);
		$this->assertTrue($outcome === false || (is_array($outcome)
			&& isset($outcome['accepted']) && $outcome['accepted'] === array()),
			'an unavailable force-2 directory capability refuses admission');
		$this->assertEquals(array(), rXMLRPCRequest::$erased,
			'capability refusal happens before the destructive download RPC');
		$obligations = array_filter($this->queueEntries(), function($name) use ($hash) {
			return strpos($name, $hash.'.') === 0 && substr($name, -5) !== '.lock';
		});
		$this->assertEquals(array(), array_values($obligations),
			'a refused member has no pending marker, staging or published manifest');
		$this->assertEquals('preserve', file_get_contents($base.'/payload'),
			'the refused payload is untouched');
	}

	public function testForceTwoRecoveryAlsoRefusesAnUnavailableDirectoryCapability()
	{
		$this->reset();
		$base = $this->dir.'/recovery-no-descriptor';
		mkdir($base);
		file_put_contents($base.'/payload', 'preserve');
		$hash = $this->hash('A');
		$generation = '0000000000000001';
		$state = erasedataDefaultDrainState();
		$state['user'] = User::getUser();
		$state['generation'] = $generation;
		$state['acknowledged'] = $generation;
		$state['phase'] = 'armed';
		$this->assertTrue(erasedataWriteDrainState($this->queuePath(), $state),
			'the recovery fixture has an acknowledged durable arm');
		$this->assertTrue(erasedataQueueRequest($this->queuePath(), $hash, 2, $generation),
			'the recovery fixture has a real retained marker');
		$this->frozen(true, array($base, 1, $base.'/payload'));
		$this->probe(true, false, array($hash));
		$this->eraseOk();
		$notes = array();
		$filesystem = new ErasedataCollectorFixture(array(
			'openDirectoryReference:*' => array('result' => false),
		));
		$outcome = erasedataDrainGenerationPass($this->queuePath(), User::getUser(),
			$generation, array('hashes' => array($hash), 'force' => 2), $filesystem, $notes);
		$this->assertEquals(array(), rXMLRPCRequest::$erased,
			'recovery cannot bypass the descriptor preflight before d.erase');
		$this->assertTrue($outcome['retained'] === 1 && $outcome['published'] === 0,
			'an unavailable capability keeps the pending obligation retryable');
		$this->assertTrue(erasedataPendingMarkerStands($this->queuePath(), $hash, $generation),
			'no failed preflight discharges the marker');
		$this->assertEquals('preserve', file_get_contents($base.'/payload'),
			'recovery left the refused payload untouched');
		// "It stages NOTHING" is a deviation this pass makes deliberately, and
		// the four assertions above cannot see it. With the preflight deleted,
		// the prepare step writes a real .tmp staging object for the refused
		// member and the LATER re-check still stops the erase, so all four stay
		// green over a queue that has grown an object no proven capability
		// admitted. The exact surviving entry set is what pins it: the marker of
		// this generation, and nothing else of this hash.
		$entries = array();
		foreach($this->queueEntries() as $name)
			if(strpos($name, $hash.'.') === 0 && substr($name, -5) !== '.lock')
				$entries[] = $name;
		$this->assertEquals(array($hash.'.'.$generation.'.pending'), $entries,
			'a refused force-2 recovery member stages nothing: no .tmp beside its'
				.' marker, and no published manifest either ('
				.implode(',', $this->queueEntries()).')');
	}

	// A queue directory at mode 0400 is READABLE but not SEARCHABLE. scandir()
	// lists it while every child stat(), is_file() and file_get_contents()
	// fails with EACCES, and PHP reports each of those failures as plain false
	// -- byte for byte the answer a genuinely missing file gives. Concluding
	// absence from that is fail-OPEN: an armed queue holding a live obligation
	// reads back as pristine and never armed, its staging object reads as gone
	// and its journal record reads as resolved.
	//
	// Durable loss through this was NOT demonstrated -- under the same mode a
	// write usually fails too, and the transient-permission race was never
	// reproduced. What was measured, and what this pins, is that the three
	// readers answer confidently and wrongly. Absence may only be concluded
	// when the lookup itself demonstrably succeeded.
	public function testAnUnsearchableQueueDirectoryIsUncertaintyAndNeverAbsence()
	{
		$this->reset();
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		$generation = '0000000000000001';
		$payload = $this->dir.'/unsearchable-payload.bin';
		file_put_contents($payload, 'payload of a live obligation');
		$bytes = ErasedataManifestCodec::encode($hash,
			array('base' => $payload, 'multi' => '0', 'files' => array($payload)), 1);
		$staged = erasedataStageAdmittedManifest($queue, $hash, $generation, $bytes);
		$this->assertTrue(is_array($staged), 'the fixture has a real staging object on disk');
		if(!is_array($staged))
			return;
		$state = $this->armQueue($queue, $generation, array($hash => $staged['path']));
		$staging = $state['journal'][$generation]['staging'];
		$this->assertTrue(chmod($queue, 0400), 'the fixture can restrict the queue directory');
		try
		{
			clearstatcache();
			// chmod cannot restrict root, so under root this scenario cannot be
			// staged at all: the premise is checked rather than assumed, and the
			// case is skipped when it does not hold. Root is not a runner this
			// project supports -- CI and the documented image invocation both
			// run the suite as a non-root user.
			if(is_readable($staged['path']))
			{
				$this->assertTrue(true, 'skipped: this process still searches a 0400 directory (running as root)');
				return;
			}
			$this->assertTrue(erasedataReadDrainState($queue) === false,
				'a state file this process may not look at is uncertainty, never a never-armed queue');
			$this->assertTrue(erasedataStagingObjectIsGone($queue, $staging, $hash) === false,
				'a staging object this process may not look at is not a staging object that is gone');
			$abandoned = null;
			$kept = erasedataPruneResolvedJournalRecords($queue, $state, $abandoned);
			$this->assertTrue(is_array($kept) && isset($kept['journal'][$generation]),
				'a journal record whose staging cannot be looked at is not a resolved record');
			$this->assertEquals(array(), $abandoned,
				'and a lookup that never answered abandons nothing');
		}
		finally
		{
			chmod($queue, 0755);
			clearstatcache();
		}
		$restored = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($restored) && $restored['generation'] === $generation
			&& count($restored['journal']) === 1,
			'the queue held an armed generation and one journal record the whole time');
	}

	public function testForceTwoKeepsTheOwnedDescriptorAliveUntilTheEraseReply()
	{
		$this->reset();
		$base = $this->dir.'/held-admission-descriptor';
		mkdir($base);
		file_put_contents($base.'/payload', 'preserve');
		$hash = $this->hash('A');
		$filesystem = new class extends ErasedataFilesystemOps {
			public $references = array();
			public function openDirectoryReference($path, $identity)
			{
				$result = parent::openDirectoryReference($path, $identity);
				if(is_array($result)) $this->references[] = $result;
				return($result);
			}
		};
		$this->frozen(true, array($base, 1, $base.'/payload'));
		$heldAtErase = false;
		$this->eraseOk(function() use ($filesystem, &$heldAtErase) {
			$heldAtErase = count($filesystem->references) === 1
				&& is_resource($filesystem->references[0]['handle'])
				&& is_dir($filesystem->references[0]['path']);
		});
		$this->acknowledgeOnRegistration($this->queuePath());
		$outcome = erasedataRemovalAdmissionRun($this->dependencies(array(
			'filesystem' => $filesystem,
		)), array($hash), 2);
		$this->assertTrue(is_array($outcome) && $outcome['published'] === array($hash),
			'a usable force-2 capability admits and publishes the removal');
		$this->assertTrue($heldAtErase,
			'the admission descriptor stays owned and open through the erase RPC');
		$this->assertTrue(count($filesystem->references) === 1
			&& !is_resource($filesystem->references[0]['handle']),
			'the producer releases its descriptor when the attempt finishes');
	}

	public function testForceTwoPreflightPartitionsAMixedBatchWithoutQueuingTheRefusal()
	{
		$this->reset();
		$allowed = $this->hash('A');
		$refused = $this->hash('B');
		$bases = array($allowed => $this->dir.'/allowed', $refused => $this->dir.'/refused');
		$responses = array();
		foreach($bases as $hash => $base)
		{
			mkdir($base);
			file_put_contents($base.'/payload', $hash);
			$responses[$hash] = array('ok' => true, 'val' => array($base, 1, $base.'/payload'));
		}
		rXMLRPCRequest::$responses['d.get_base_path'] = array('byHash' => $responses);
		$this->eraseOk();
		$this->acknowledgeOnRegistration($this->queuePath());
		$filesystem = new ErasedataCollectorFixture(array(
			'openDirectoryReference:*' => array('path' => $bases[$refused], 'result' => false),
		));
		$outcome = erasedataRemovalAdmissionRun($this->dependencies(array('filesystem' => $filesystem)),
			array($refused, $allowed), 2);
		$this->assertTrue(is_array($outcome) && $outcome['accepted'] === array($allowed)
			&& $outcome['refused'] === array($refused) && $outcome['published'] === array($allowed),
			'the physical preflight keeps the exact accepted/refused partition');
		$this->assertEquals(array($allowed), rXMLRPCRequest::$erased,
			'only the descriptor-capable member reaches the destructive RPC');
		$refusedEntries = array_filter($this->queueEntries(), function($name) use ($refused) {
			return strpos($name, $refused.'.') === 0 && substr($name, -5) !== '.lock';
		});
		$this->assertEquals(array(), array_values($refusedEntries),
			'the refused member acquires no obligation or manifest');
	}

	public function testForceTwoLosingItsDescriptorBeforeStagingErasesNothing()
	{
		$this->reset();
		$base = $this->dir.'/lost-admission-descriptor';
		mkdir($base);
		file_put_contents($base.'/payload', 'preserve');
		$filesystem = new class extends ErasedataFilesystemOps {
			public $reference = null;
			public function openDirectoryReference($path, $identity)
			{
				$this->reference = parent::openDirectoryReference($path, $identity);
				return($this->reference);
			}
		};
		$this->frozen(true, array($base, 1, $base.'/payload'));
		$this->eraseOk();
		$hadCapability = false;
		$this->acknowledgeOnRegistration($this->queuePath(),
			function() use ($filesystem, &$hadCapability) {
				$hadCapability = is_array($filesystem->reference)
					&& is_resource($filesystem->reference['handle']);
				if($hadCapability) fclose($filesystem->reference['handle']);
			});
		$outcome = erasedataRemovalAdmissionRun($this->dependencies(array('filesystem' => $filesystem)),
			array($this->hash('A')), 2);
		$this->assertTrue($hadCapability, 'the scenario invalidates a real previously acquired descriptor');
		$this->assertTrue($outcome === false, 'loss of the held descriptor refuses the attempt');
		$this->assertEquals(array(), rXMLRPCRequest::$erased, 'capability loss never falls through to d.erase');
		$this->assertEquals('preserve', file_get_contents($base.'/payload'), 'the payload survives capability loss');
	}

	public function testThePluginInitRearmTakesTheStateLockNonBlocking()
	{
		$this->reset();
		$body = $this->productionFunctionBody('removewithdata.php',
			'function erasedataRearmDrainScheduleRun(array $dependencies)');
		$this->assertTrue(is_string($body)
			&& strpos($body, 'erasedataAcquireDrainStateLock($listPath, true)') !== false,
			'the plugin-init re-arm takes the drain state lock NONBLOCKING');
		$this->assertTrue(is_string($body)
			&& strpos($body, 'erasedataAcquireDrainStateLock($listPath)') === false,
			'and never blocks a web page load on it');
		$this->sourceHas('init.php', 'erasedataRearmDrainSchedule',
			'and that is the function php/getplugins.php reaches through init.php');
	}

	// One classified note out of a pass, by reason and by hash.
	//
	// The drain pass reports through $notes and its caller folds them into the
	// tick's bounded report memory, so a refusal that emits nothing is
	// indistinguishable from one that emits a line nobody can read. These
	// helpers make the line itself an assertable object.
	// A note is (reason, generation, members, consequence, hash), with one
	// optional sixth element: the name of the staging object a `staging-unbound`
	// refusal strands. The arity is still asserted, so a malformed note is not
	// silently matched.
	private function noteFor(array $notes, $reason, $hash = null)
	{
		foreach($notes as $note)
			if(is_array($note) && (count($note) === 5 || count($note) === 6)
				&& $note[0] === $reason
				&& ($hash === null || $note[4] === $hash))
				return($note);
		return(false);
	}

	private function assertNote(array $notes, $reason, $generation, $hash,
		$consequence, $message)
	{
		$note = $this->noteFor($notes, $reason, $hash);
		$this->assertTrue(is_array($note) && $note[1] === $generation
			&& $note[3] === $consequence, $message
				.' ('.$reason.' '.json_encode($note).')');
	}

	// The force-2 capability the RECOVERY path re-proves immediately before
	// d.erase, and the classified line it writes when that proof fails.
	//
	// The producer opens a descriptor on the base path, holds it across its own
	// erase and re-checks it right before the destructive call; recovery owes
	// the identical re-check because a descriptor that no longer names the base
	// path it was opened on proves nothing any more. The preflight succeeding
	// is not the same event: this case lets the preflight succeed, stages a real
	// manifest under it, and only then invalidates the base-path lookup the
	// re-check makes, so the refusal can only have come from the second proof.
	public function testForceTwoRecoveryReProvesItsCapabilityImmediatelyBeforeErase()
	{
		$this->reset();
		$base = $this->dir.'/recovery-recheck-base';
		mkdir($base);
		file_put_contents($base.'/payload', 'preserve');
		$hash = $this->hash('A');
		$generation = '0000000000000001';
		$queue = $this->queuePath();
		$state = erasedataDefaultDrainState();
		$state['user'] = User::getUser();
		$state['generation'] = $generation;
		$state['acknowledged'] = $generation;
		$state['phase'] = 'armed';
		$this->assertTrue(erasedataWriteDrainState($queue, $state),
			'the recovery fixture has an acknowledged durable arm');
		$this->assertTrue(erasedataQueueRequest($queue, $hash, 2, $generation),
			'the recovery fixture has a real retained marker');
		$this->frozen(true, array($base, 1, $base.'/payload'));
		$this->probe(true, false, array($hash));
		$this->eraseOk();
		// Three base-path identity lookups reach the seam in this scenario: two
		// while the capability is acquired, and the last one inside the re-check
		// the erase decision is gated on. Refusing exactly the third leaves the
		// preflight intact and fails only the second proof. The count is
		// asserted below, so a change in the sequence cannot make this pass
		// vacuously by refusing the preflight instead.
		$calls = $this->dir.'/recheck-base-lookups';
		$filesystem = new ErasedataCollectorFixture(array(
			'targetIdentity:3' => array('path' => $base, 'result' => false),
			'targetIdentity:*' => array('path' => $base, 'count_file' => $calls),
		));
		$notes = array();
		$outcome = erasedataDrainGenerationPass($queue, User::getUser(), $generation,
			array('hashes' => array($hash), 'force' => 2), $filesystem, $notes);
		$this->assertEquals(3, substr_count(@file_get_contents($calls), "\n"),
			'the refusal really is the pre-erase re-check, not the preflight');
		$this->assertEquals(array(), rXMLRPCRequest::$erased,
			'a capability that no longer names its base path never reaches d.erase');
		$this->assertEquals('preserve', @file_get_contents($base.'/payload'),
			'and the payload it would have deleted is untouched');
		$this->assertTrue(is_array($outcome) && $outcome['retained'] === 1
			&& $outcome['published'] === 0,
			'the obligation is retained for the next tick, never resolved');
		$this->assertTrue(erasedataPendingMarkerStands($queue, $hash, $generation),
			'and its pending marker still stands');
		// The preflight demonstrably ran and bound a real staging object, which
		// is what separates this refusal from the preflight refusal above.
		$this->assertEquals(1, count(glob($queue.'/'.$hash.'.'.$generation.'.*.tmp')),
			'the pass had already staged a manifest under the proven capability');
		$this->assertEquals(array(), glob($queue.'/*.list'),
			'and published none of it');
		$this->assertNote($notes, 'descriptor-unavailable', $generation, $hash,
			'obligation-retained-nothing-erased',
			'the pre-erase capability refusal is classified against its own hash');
	}

	// The force-2 preflight refusals are visible, not a silent stall.
	//
	// A refused member keeps its marker and is retained indefinitely, so the
	// classified line naming it is the whole difference between a conservative
	// retention and a queue that never finishes and never says why. Both
	// refusals the preflight can reach are pinned here, kept apart: the daemon
	// could not say what the download owns, or it could and the base path would
	// not yield a descriptor.
	public function testForceTwoRecoveryRefusalsAreClassifiedAgainstTheirOwnHash()
	{
		foreach(array('descriptor-unavailable', 'paths-unknown') as $reason)
		{
			$this->reset();
			$base = $this->dir.'/recovery-refusal-base';
			mkdir($base);
			file_put_contents($base.'/payload', 'preserve');
			$hash = $this->hash('A');
			$generation = '0000000000000001';
			$queue = $this->queuePath();
			$state = erasedataDefaultDrainState();
			$state['user'] = User::getUser();
			$state['generation'] = $generation;
			$state['acknowledged'] = $generation;
			$state['phase'] = 'armed';
			$this->assertTrue(erasedataWriteDrainState($queue, $state),
				$reason.': the recovery fixture has an acknowledged durable arm');
			$this->assertTrue(erasedataQueueRequest($queue, $hash, 2, $generation),
				$reason.': the recovery fixture has a real retained marker');
			// paths-unknown is the daemon refusing to say what the download
			// owns; descriptor-unavailable is a complete answer whose base path
			// yields no descriptor.
			if($reason === 'paths-unknown')
				$this->frozen(false, array());
			else
				$this->frozen(true, array($base, 1, $base.'/payload'));
			$this->probe(true, false, array($hash));
			$this->eraseOk();
			$filesystem = new ErasedataCollectorFixture(array(
				'openDirectoryReference:*' => array('result' => false),
			));
			$notes = array();
			$outcome = erasedataDrainGenerationPass($queue, User::getUser(),
				$generation, array('hashes' => array($hash), 'force' => 2),
				$filesystem, $notes);
			$this->assertEquals(array(), rXMLRPCRequest::$erased,
				$reason.': an unproven capability never reaches d.erase');
			$this->assertTrue(is_array($outcome) && $outcome['retained'] === 1,
				$reason.': the obligation is retained');
			$this->assertNote($notes, $reason, $generation, $hash,
				'obligation-retained-nothing-erased',
				$reason.': the refusal names its hash, its generation and its'
					.' consequence');
			$this->assertTrue($this->noteFor($notes, $reason === 'paths-unknown'
				? 'descriptor-unavailable' : 'paths-unknown') === false,
				$reason.': and the two refusals are not reported as each other');
		}
	}

	// A queue directory that cannot be LISTED is uncertainty about what is
	// already final, never an empty set of published manifests.
	//
	// The pass reads scandir() once, under the state lock, for two things it
	// then makes destructive decisions with: which members already have a final
	// manifest of this generation, and which physical staging objects the
	// journal does not bind. Treating a failed scan as "nothing is final and
	// nothing is unbound" is fail-OPEN in both directions at once, so the scan
	// failing has to retain the whole generation and say so.
	//
	// A directory at mode 0300 is writable and searchable but not listable:
	// every known path inside it still opens, so the pass gets as far as the
	// scan and no further, which is exactly the state this pins.
	public function testAnUnlistableQueueRetainsRatherThanInventingAnEmptyFinalSet()
	{
		$this->reset();
		$queue = $this->queuePath();
		$hash = $this->hash('A');
		$generation = '0000000000000001';
		$payload = $this->dir.'/unlistable-payload.bin';
		file_put_contents($payload, 'payload of an obligation nobody could scan for');
		$bytes = ErasedataManifestCodec::encode($hash,
			array('base' => $payload, 'multi' => '0', 'files' => array($payload)), 1);
		$staged = erasedataStageAdmittedManifest($queue, $hash, $generation, $bytes);
		$this->assertTrue(is_array($staged), 'the fixture has a real staging object on disk');
		if(!is_array($staged))
			return;
		$this->assertTrue(erasedataQueueRequest($queue, $hash, 1, $generation),
			'and a real pending marker over it');
		$marker = erasedataPendingMarkerPath($queue, $hash, $generation);
		$markerBytes = file_get_contents($marker);
		$this->armQueue($queue, $generation, array($hash => $staged['path']));
		// Absent, with a bound and identity-matching staging object: without the
		// refusal this is the pass that publishes the manifest and discharges
		// the marker on a scan that never answered.
		$this->probe(true, true, array(), 'info-hash not found');
		$this->eraseOk();
		$notes = array();
		$outcome = null;
		$this->assertTrue(chmod($queue, 0300),
			'the fixture can make the queue directory unlistable');
		try
		{
			clearstatcache();
			// chmod cannot restrict root, so under root the scenario cannot be
			// staged at all: the premise is checked rather than assumed, and the
			// case is skipped when it does not hold. Root is not a runner this
			// project supports -- CI and the documented image invocation both
			// run the suite as a non-root user.
			if(is_array(@scandir($queue)))
			{
				$this->assertTrue(true,
					'skipped: this process still lists a 0300 directory (running as root)');
				return;
			}
			$outcome = erasedataDrainGenerationPass($queue, User::getUser(),
				$generation, array('hashes' => array($hash), 'force' => 1),
				new ErasedataFilesystemOps(), $notes);
		}
		finally
		{
			chmod($queue, 0755);
			clearstatcache();
		}
		$this->assertTrue(is_array($outcome) && $outcome['retained'] === 1
			&& $outcome['published'] === 0 && $outcome['unrecoverable'] === 0
			&& $outcome['cancelled'] === 0,
			'a scan that never answered retains the whole generation');
		$this->assertEquals(array(), rXMLRPCRequest::$erased,
			'and erases nothing under it');
		$this->assertEquals(array(), glob($queue.'/*.list'),
			'a manifest is never published on an invented empty final set');
		$this->assertEquals($bytes, @file_get_contents($staged['path']),
			'the staging object survives byte-exact');
		$this->assertEquals($markerBytes, @file_get_contents($marker),
			'and the pending obligation is kept exactly as it was found');
		$this->assertNote($notes, 'queue-unreadable', $generation, null,
			'obligations-retained-nothing-erased',
			'the queue nobody could list is reported, not silently treated as empty');
	}

	// Step (5) owes step (3)'s staging rollback.
	//
	// Step (3) releases the staging it wrote when its own journal write fails,
	// because staging bound by a record that never became durable is bound by
	// nothing. The last journal write of the pass had no such rollback, so a
	// failure there left behind exactly the physical object the pass refuses to
	// resolve: an unjournaled staging candidate, which is retained
	// conservatively and for ever until a human recovers its binding. No such
	// failure has been observed in production. This case reaches it through the
	// one bound the durable state really enforces -- a journal already holding
	// ERASEDATA_DRAIN_MAX_JOURNAL records cannot accept one more.
	public function testAFailedFinalJournalWriteReleasesTheStagingItWouldOrphan()
	{
		$this->reset();
		$queue = $this->queuePath();
		$base = $this->dir.'/orphan-rollback-base';
		mkdir($base);
		file_put_contents($base.'/payload', 'preserve');
		$hash = $this->hash('A');
		$generation = '0000000000001000';
		$filler = $this->hash('B');
		$journal = array();
		for($index = 1; $index < ERASEDATA_DRAIN_MAX_JOURNAL + 1; $index++)
		{
			$key = sprintf('%016x', $index);
			$journal[$key] = array('phase' => 'published', 'force' => 1,
				'hashes' => array($filler),
				'staging' => array($filler => array(
					'path' => $queue.'/'.$filler.'.'.$key.'.1.tmp',
					'dev' => '1', 'ino' => (string)$index)));
		}
		$this->assertEquals(ERASEDATA_DRAIN_MAX_JOURNAL, count($journal),
			'the fixture builds a journal exactly at its bound');
		$state = array('version' => 1, 'user' => User::getUser(),
			'generation' => $generation, 'acknowledged' => $generation,
			'phase' => 'armed', 'journal' => $journal, 'diagnostics' => array());
		$this->assertTrue(erasedataWriteDrainState($queue, $state) === true,
			'a journal at its bound is still a durable state that writes');
		$this->assertTrue(erasedataQueueRequest($queue, $hash, 2, $generation),
			'the member has a real pending obligation of its own');
		$this->frozen(true, array($base, 1, $base.'/payload'));
		$this->probe(true, false, array($hash));
		$this->eraseOk();
		// The capability is proven, a manifest really is staged under it, and
		// the pre-erase re-check then refuses -- so nothing is erasable, no
		// `erase-started` record is written, and the ONLY journal write of the
		// pass is the last one. That write cannot succeed: this generation has
		// no record yet, so completing it would be record 4096.
		$filesystem = new ErasedataCollectorFixture(array(
			'targetIdentity:3' => array('path' => $base, 'result' => false),
		));
		$notes = array();
		$outcome = erasedataDrainGenerationPass($queue, User::getUser(), $generation,
			array('hashes' => array($hash), 'force' => 2), $filesystem, $notes);
		$this->assertTrue(is_array($this->noteFor($notes, 'journal-write')),
			'the fixture really reaches a failing final journal write');
		$this->assertEquals(array(), glob($queue.'/*.tmp'),
			'staging no durable record binds is released, never left unjournaled');
		$this->assertTrue(erasedataPendingMarkerStands($queue, $hash, $generation),
			'the obligation itself survives and is re-admittable on the next tick');
		$this->assertEquals(array(), rXMLRPCRequest::$erased,
			'nothing was erased under the staging that was released');
		$this->assertEquals('preserve', @file_get_contents($base.'/payload'),
			'and the payload is untouched');
		$this->assertTrue(is_array($outcome) && $outcome['retained'] === 1
			&& $outcome['published'] === 0,
			'the member is retained, not resolved');
		$restored = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($restored)
			&& count($restored['journal']) === ERASEDATA_DRAIN_MAX_JOURNAL
			&& !isset($restored['journal'][$generation]),
			'the durable journal is exactly what it was before the failed write');
	}

	public function testARealFinalJournalWriteFailureReleasesUnboundStaging()
	{
		if(!function_exists('posix_setrlimit') || !function_exists('posix_getrlimit')
			|| !function_exists('pcntl_signal')
			|| !function_exists('pcntl_signal_dispatch')
			|| !function_exists('pcntl_signal_get_handler')
			|| !defined('POSIX_RLIMIT_FSIZE')
			|| !defined('POSIX_RLIMIT_INFINITY') || !defined('SIGXFSZ'))
		{
			$this->assertTrue(true, 'skipped: process file-size limits are unavailable');
			return;
		}
		$this->reset();
		$queue = $this->queuePath();
		$base = $this->dir.'/real-io-rollback-base';
		mkdir($base);
		file_put_contents($base.'/payload', 'preserve');
		$hash = $this->hash('A');
		$generation = '0000000000001000';
		$this->assertTrue(erasedataQueueRequest($queue, $hash, 2, $generation),
			'the member has a durable pending obligation');
		$marker = erasedataPendingMarkerPath($queue, $hash, $generation);
		$markerBytes = file_get_contents($marker);
		$this->armQueue($queue, $generation, array(), 'erase-started', 2,
			User::getUser());
		$statePath = erasedataDrainStatePath($queue);
		$stateBytes = file_get_contents($statePath);
		$this->frozen(true, array($base, 1, $base.'/payload'));
		$this->probe(true, false, array($hash));
		$this->eraseOk();
		$limits = posix_getrlimit();
		$soft = $limits['soft filesize'] === 'unlimited'
			? POSIX_RLIMIT_INFINITY : $limits['soft filesize'];
		$hard = $limits['hard filesize'] === 'unlimited'
			? POSIX_RLIMIT_INFINITY : $limits['hard filesize'];
		$priorSignal = pcntl_signal_get_handler(SIGXFSZ);
		$sizeFaults = 0;
		$this->assertTrue(pcntl_signal(SIGXFSZ, function() use (&$sizeFaults) {
			$sizeFaults++;
		}), 'a failed write cannot terminate the test process');
		// The third descriptor recheck runs after staging. Refusing it keeps
		// $durable false, so the final state write is the pass's only journal write.
		$filesystem = new class($base, $hard) extends ErasedataFilesystemOps {
			private $base;
			private $hard;
			public $calls = 0;
			public $limitApplied = false;
			public function __construct($base, $hard)
			{
				$this->base = $base;
				$this->hard = $hard;
			}
			public function targetIdentity($path)
			{
				if($path === $this->base && ++$this->calls === 3)
				{
					$this->limitApplied = posix_setrlimit(POSIX_RLIMIT_FSIZE,
						0, $this->hard);
					return(false);
				}
				return(parent::targetIdentity($path));
			}
		};
		$notes = array();
		$outcome = null;
		try
		{
			$outcome = erasedataDrainGenerationPass($queue, User::getUser(),
				$generation, array('hashes' => array($hash), 'force' => 2),
				$filesystem, $notes);
		}
		finally
		{
			posix_setrlimit(POSIX_RLIMIT_FSIZE, $soft, $hard);
			pcntl_signal_dispatch();
			pcntl_signal(SIGXFSZ, $priorSignal);
		}
		$this->assertTrue($sizeFaults > 0,
			'the kernel delivered SIGXFSZ for an actual file write');
		$this->assertTrue($filesystem->limitApplied,
			'the real file-size limit was set after staging and before the final write');
		$this->assertTrue(is_array($this->noteFor($notes, 'journal-write')),
			'the final durable writer reports a real filesystem write failure');
		$this->assertEquals(array(), glob($queue.'/*.tmp'),
			'rollback removes staging that the failed journal cannot bind');
		$this->assertEquals($stateBytes, file_get_contents($statePath),
			'the previous durable state survives byte-exact');
		$this->assertEquals($markerBytes, file_get_contents($marker),
			'the pending obligation survives byte-exact');
		$this->assertEquals(array(), rXMLRPCRequest::$erased,
			'no erase occurs before a durable journal record');
		$this->assertEquals('preserve', file_get_contents($base.'/payload'),
			'payload data is untouched');
		$this->assertTrue(is_array($outcome) && $outcome['retained'] === 1
			&& $outcome['published'] === 0,
			'the failed pass leaves the member for a later retry');
		$retryNotes = array();
		$retry = erasedataDrainGenerationPass($queue, User::getUser(),
			$generation, array('hashes' => array($hash), 'force' => 2),
			new ErasedataFilesystemOps(), $retryNotes);
		$this->assertTrue(is_array($retry) && $retry['published'] === 1
			&& $retry['retained'] === 0,
			'the next pass can finish the same obligation after I/O is restored');
		$this->assertTrue(!erasedataPendingMarkerStands($queue, $hash, $generation)
			&& count(glob($queue.'/*.list')) === 1,
			'the retry publishes a final manifest and discharges its marker');
	}

	// A `prepared` record whose producer is gone cancels its own bindings and
	// nothing else.
	//
	// Cancelling a member releases its staging and DISCHARGES its marker, which
	// is the whole obligation. A member the record does not bind, but which has
	// a physical staging object of this generation on disk, satisfies "no
	// binding to release" trivially -- so it would be cancelled on the strength
	// of having nothing the record knows about, its marker discharged and its
	// real staging candidate left in the queue under no identity at all. The
	// unbound member is retained instead, and its innocent siblings are still
	// cancelled on the same pass.
	public function testAPreparedRecordNeverCancelsAMemberItDoesNotBind()
	{
		$this->reset();
		$queue = $this->queuePath();
		$generation = '0000000000000001';
		$orphan = $this->hash('A');
		$member = $this->hash('B');
		$staged = array();
		foreach(array($orphan, $member) as $hash)
		{
			$payload = $this->dir.'/prepared-'.substr($hash, 0, 1).'.bin';
			file_put_contents($payload, 'payload of prepared member '.substr($hash, 0, 1));
			$bytes = ErasedataManifestCodec::encode($hash,
				array('base' => $payload, 'multi' => '0', 'files' => array($payload)), 1);
			$object = erasedataStageAdmittedManifest($queue, $hash, $generation, $bytes);
			$this->assertTrue(is_array($object),
				'prepared member '.substr($hash, 0, 1).' has a staging object');
			if(!is_array($object))
				return;
			$staged[$hash] = $object['path'];
			$this->assertTrue(erasedataQueueRequest($queue, $hash, 1, $generation),
				'prepared member '.substr($hash, 0, 1).' has a pending marker');
		}
		$orphanBytes = file_get_contents($staged[$orphan]);
		$orphanMarker = erasedataPendingMarkerPath($queue, $orphan, $generation);
		$orphanMarkerBytes = file_get_contents($orphanMarker);
		// The record is `prepared` and binds the second member only, which is
		// what a producer that died between two staging writes leaves behind.
		$this->armQueue($queue, $generation, array($member => $staged[$member]),
			'prepared');
		$this->probe(true, false, array($orphan, $member));
		$this->eraseOk();
		$notes = array();
		$outcome = erasedataDrainGenerationPass($queue, User::getUser(), $generation,
			array('hashes' => array($orphan, $member), 'force' => 1),
			new ErasedataFilesystemOps(), $notes);
		$this->assertEquals(array(), rXMLRPCRequest::$erased,
			'a prepared record erases nothing at all');
		$this->assertTrue(is_array($outcome) && $outcome['cancelled'] === 1
			&& $outcome['retained'] === 1 && $outcome['unrecoverable'] === 0,
			'the bound member is cancelled and the unbound one is retained');
		$this->assertTrue(!file_exists($staged[$member]),
			'the cancelled member releases the staging the record bound');
		$this->assertTrue(!erasedataPendingMarkerStands($queue, $member, $generation),
			'and its obligation is cancelled, ready to be admitted again');
		$this->assertEquals($orphanMarkerBytes, @file_get_contents($orphanMarker),
			'the unbound member keeps its exact pending obligation');
		$this->assertEquals($orphanBytes, @file_get_contents($staged[$orphan]),
			'and its staging candidate survives byte-exact');
		$this->assertNote($notes, 'staging-unbound', $generation, $orphan,
			'physical-staging-retained-journal-binding-recovery-required',
			'the retained member is named, so the stranded obligation is visible');
		$this->assertNote($notes, 'prepared-cancelled', $generation, $member,
			'nothing-erased-obligation-cancelled',
			'and the sibling it would once have frozen is reported as cancelled');
		$state = erasedataReadDrainState($queue);
		$this->assertTrue(is_array($state) && isset($state['journal'][$generation]),
			'a partial cancellation keeps its record for the next tick');
	}

	// P6-1: the line only a human can act on must name the file they have to
	// remove.
	//
	// `staging-unbound` is repairable by hand and by nothing else: adopting a
	// staging object by filename is forbidden, so the object and the obligation
	// it strands survive every tick until a person deletes that object. Reason,
	// generation, members, hash and consequence do not tell that person WHICH
	// file -- the name carries a pid and a uniqid that cannot be derived from
	// any of them -- so the note carries the name the queue scan matched and the
	// rendered line prints it. The queue directory itself stays out of the line,
	// which is why the name is asserted verbatim against an object that really
	// is on disk: a name nobody can find is no better than no name.
	public function testTheUnboundStagingDiagnosticNamesTheRetainedObject()
	{
		$this->reset();
		$queue = $this->queuePath();
		$generation = '0000000000000001';
		$orphan = $this->hash('A');
		$payload = $this->dir.'/unbound-named.bin';
		file_put_contents($payload, 'payload of the unbound member');
		$bytes = ErasedataManifestCodec::encode($orphan,
			array('base' => $payload, 'multi' => '0', 'files' => array($payload)), 1);
		$object = erasedataStageAdmittedManifest($queue, $orphan, $generation, $bytes);
		$this->assertTrue(is_array($object),
			'the crashed producer left a complete staging object behind');
		if(!is_array($object))
			return;
		$name = basename($object['path']);
		$this->assertTrue(erasedataQueueRequest($queue, $orphan, 1, $generation),
			'and the pending marker it wrote before it died');
		// Armed, with a journal that binds nothing: the producer died between
		// its staging write and the record that would have bound it.
		$this->armQueue($queue, $generation, array());
		$this->probe(true, false, array($orphan));
		$notes = array();
		erasedataDrainGenerationPass($queue, User::getUser(), $generation,
			array('hashes' => array($orphan), 'force' => 1),
			new ErasedataFilesystemOps(), $notes);
		$note = $this->noteFor($notes, 'staging-unbound', $orphan);
		$this->assertTrue(is_array($note) && isset($note[5]) && $note[5] === $name,
			'the note carries the name the queue scan matched: '.json_encode($note));
		// The rendered line is the only thing an operator ever sees.
		FileUtil::$log = array();
		$fresh = array();
		erasedataDrainReportGroup(null, $generation, $notes, array(), $fresh);
		$named = array();
		foreach(FileUtil::$log as $line)
			if(strpos($line, 'staging-unbound') !== false)
				$named[] = $line;
		$this->assertEquals(1, count($named),
			'the stranded obligation is reported exactly once: '
				.json_encode(FileUtil::$log));
		$line = count($named) ? $named[0] : '';
		$this->assertTrue(strpos($line, ' file='.$name.' ') !== false,
			'and the line names the file a human has to remove: '.$line);
		$this->assertTrue(is_file($queue.'/'.$name),
			'a file that really is in the queue, not a reconstruction of one');
		$this->assertTrue(strpos($line, $queue) === false,
			'without leaking the queue directory or the settings root: '.$line);
		$status = erasedataRetirementScan(array('listPath' => $queue));
		$this->assertTrue(!$status['empty'] && !$status['unreadable']
			&& $status['classes']['pending'] === 1
			&& $status['classes']['staging'] === 1,
			'the read-only UI status scan still sees the stranded queue after diagnostics quiet');
	}

	// The preview is only an optimization. A sibling's RPC can race the human
	// who removes an unbound object, so a skipped hash gets UNKNOWN for that
	// tick and is probed on the next one rather than discharged on stale proof.
	public function testUnboundProbePreviewCannotDischargeAfterTheObjectChanges()
	{
		$this->reset();
		$queue = $this->queuePath();
		$generation = '0000000000000001';
		$orphan = $this->hash('A');
		$sibling = $this->hash('B');
		$payload = $this->dir.'/unbound-preview.bin';
		file_put_contents($payload, 'retained payload');
		$bytes = ErasedataManifestCodec::encode($orphan,
			array('base' => $payload, 'multi' => '0', 'files' => array($payload)), 1);
		$object = erasedataStageAdmittedManifest($queue, $orphan, $generation, $bytes);
		$this->assertTrue(is_array($object), 'the preview sees a complete unbound object');
		if(!is_array($object))
			return;
		foreach(array($orphan, $sibling) as $hash)
			$this->assertTrue(erasedataQueueRequest($queue, $hash, 1, $generation),
				'the generation owes '.$hash);
		$this->armQueue($queue, $generation, array());
		$this->probe(true, false, array($sibling));
		rXMLRPCRequest::$responses['d.hash']['callback'] = function($commands) use ($sibling, $object) {
			if($commands[0]->params === $sibling)
				@unlink($object['path']);
		};
		erasedataDrainWorkerRun($this->dependencies());
		$probed = array();
		foreach(rXMLRPCRequest::$commandCalls as $calls)
			if($calls[0]->command === 'd.hash')
				$probed[] = $calls[0]->params;
		$this->assertEquals(array($sibling), $probed,
			'only the sibling is probed after the preview');
		$this->assertTrue(erasedataPendingMarkerStands($queue, $orphan, $generation),
			'the stale preview cannot discharge the unbound member');
		$this->assertTrue(strpos(implode("\n", FileUtil::$log), 'probe-deferred') !== false,
			'the one-tick uncertainty is visible');
		$this->assertTrue(file_exists($payload),
			'the payload is untouched after the staging identity changes');
		rXMLRPCRequest::$commandCalls = array();
		erasedataDrainWorkerRun($this->dependencies());
		$next = array();
		foreach(rXMLRPCRequest::$commandCalls as $calls)
			if($calls[0]->command === 'd.hash')
				$next[] = $calls[0]->params;
		$this->assertTrue(in_array($orphan, $next, true),
			'the next tick probes the member whose unbound object was removed');
	}

	// P6-2: an unbound object makes its presence probe unnecessary until repaired.
	//
	// A queue holding one unbound staging object never retires, so the drain
	// child runs every ERASEDATA_DRAIN_INTERVAL seconds. It still scans the
	// physical object and retains the marker, but a hash probe cannot alter
	// that decision while the object stands. Once a human removes it, the next
	// tick must resume the probe; a skipped probe must never become a permanent
	// cached absence or quietly discharge an obligation.
	public function testUnboundStagingSkipsProbesUntilThePhysicalObjectIsRemoved()
	{
		$this->reset();
		$queue = $this->queuePath();
		$generation = '0000000000000001';
		$orphan = $this->hash('A');
		$payload = $this->dir.'/unbound-cost.bin';
		file_put_contents($payload, 'payload of the stranded member');
		$bytes = ErasedataManifestCodec::encode($orphan,
			array('base' => $payload, 'multi' => '0', 'files' => array($payload)), 1);
		$object = erasedataStageAdmittedManifest($queue, $orphan, $generation, $bytes);
		$this->assertTrue(is_array($object),
			'the crashed producer left a complete staging object behind');
		if(!is_array($object))
			return;
		$staged = file_get_contents($object['path']);
		$this->assertTrue(erasedataQueueRequest($queue, $orphan, 1, $generation),
			'and the pending marker it wrote before it died');
		$this->armQueue($queue, $generation, array());
		$this->probe(true, false, array($orphan));
		$lines = array();
		$named = array();
		$probes = array();
		$captured = array();
		for($tick = 0; $tick < 10; $tick++)
		{
			FileUtil::$log = array();
			rXMLRPCRequest::$requested = array();
			erasedataDrainWorkerRun($this->dependencies());
			$captured[$tick] = FileUtil::$log;
			$lines[] = count(FileUtil::$log);
			$hit = 0;
			foreach(FileUtil::$log as $line)
				if(strpos($line, 'staging-unbound') !== false
					&& strpos($line, basename($object['path'])) !== false)
					$hit++;
			$named[] = $hit;
			$probes[] = count(rXMLRPCRequest::$requested);
		}
		$this->assertEquals(1, $named[0],
			'the first tick names the stranded file exactly once: '
				.json_encode($named));
		$this->assertEquals(array_fill(0, 9, 0), array_slice($named, 1),
			'and no later tick repeats it: '.json_encode($named));
		$this->assertEquals(array_fill(0, 9, 0), array_slice($lines, 1),
			'the report converges to complete silence after tick one: '
				.json_encode($lines));
		$this->assertEquals(array_fill(0, 10, 0), $probes,
			'unbound staging requires no repeated hash probe: '.json_encode($probes));
		// And why it is paid for ever: the schedule cannot retire while the
		// obligation stands, which the first tick says in its own line.
		$this->assertTrue(strpos(implode("\n", $captured[0]),
			'retire-refused') !== false,
			'the queue refuses to retire while the obligation stands: '
				.json_encode($captured[0]));
		// Silence is not resolution.
		$this->assertEquals($staged, @file_get_contents($object['path']),
			'the stranded object is still there, byte for byte, after ten ticks');
		$this->assertTrue(erasedataPendingMarkerStands($queue, $orphan, $generation),
			'and so is the obligation it strands');
		$this->assertEquals(array(), rXMLRPCRequest::$erased,
			'nothing was erased across any of those ticks');
		$this->assertTrue(@unlink($object['path']),
			'a human removes the exact unbound object while the marker stands');
		rXMLRPCRequest::$requested = array();
		erasedataDrainWorkerRun($this->dependencies());
		$this->assertTrue(in_array('d.hash', rXMLRPCRequest::$requested, true),
			'the very next tick resumes the live presence probe');
	}
	public function testLegacyUnsignedPrivateRootWithDataRemainsAnExactObligation()
	{
		$this->reset();
		$hash = $this->hash('A');
		$public = $this->dir.'/payload';
		mkdir($public, 0700);
		$base = $public.'/unsigned-old-base';
		mkdir($base);
		$this->writeManifestLines($hash.'.list', array($base.'/gone.bin'), $base, 1, 1);
		$item = $this->dir.'/erasedata/'.$hash.'.list';
		$exact = file_get_contents($item);
		$identity = erasedataPathIdentity($base);
		$root = erasedataDirectoryReservationPath($base, $item, $identity);
		$this->assertTrue(is_string($root) && mkdir($root, 0700),
			'old reservation shell is present');
		file_put_contents($root.'/.initialized', '');
		$this->assertTrue(rename($base, $root.'/directory'),
			'old private data is present without an authority signature');
		chmod($public, 0777);
		list($status, $output) = $this->runCollector(array('captureLogs' => true));
		$this->assertEquals(0, $status, 'legacy replay exits without a fatal');
		$this->assertEquals($exact, file_get_contents($item),
			'unsigned old root cannot retire its exact manifest after parent seal');
		$this->assertTrue(is_dir($root.'/directory'),
			'unsigned old private data remains available for inspection');
		$this->assertTrue(strpos($output, 'private root retained: unsigned or changed') !== false,
			'legacy hold names the failed authority check');
	}


	public function testMissingPrivateKeyDoesNotRegenerateAcrossPendingRoots()
	{
		$this->reset();
		$oldHash = $this->hash('A');
		$newHash = $this->hash('B');
		$public = $this->dir.'/payload';
		mkdir($public, 0700);
		$base = $public.'/old-base';
		mkdir($base);
		file_put_contents($base.'/old.bin', 'old');
		$this->writeManifestLines($oldHash.'.list', array($base.'/old.bin'), $base, 1, 1);
		$oldManifest = $this->dir.'/erasedata/'.$oldHash.'.list';
		$exact = file_get_contents($oldManifest);
		$root = erasedataDirectoryReservationPath($base, $oldManifest,
			erasedataPathIdentity($base));
		$this->assertTrue(is_string($root) && erasedataProtectPrivateParent($root)
			&& mkdir($root, 0700) && erasedataCreatePrivateMarker($root, $oldManifest)
			&& rename($base, $root.'/directory'),
			'an existing signed private root contains the first pending payload');
		$key = $this->queuePath().'/.private-root-key';
		$originalKey = file_get_contents($key);
		$this->assertTrue(is_file($key) && unlink($key),
			'the durable key is missing before a second capture begins');
		$this->writeManifestLines($newHash.'.list', array($public.'/new.bin'),
			$public.'/new.bin', 0, 1);
		$newManifest = $this->dir.'/erasedata/'.$newHash.'.list';
		FileUtil::$log = array();
		$this->assertTrue(erasedataPrivateRootKey($newManifest, true) === false
			&& !file_exists($key),
			'new capture cannot mint a replacement key over a pending signed root');
		$this->assertTrue(strpos(implode("\n", FileUtil::$log), 'private root key') !== false,
			'key loss is a visible refusal');
		$this->assertEquals($exact, file_get_contents($oldManifest),
			'the old manifest is retained byte for byte');
		$this->assertTrue(is_file($root.'/directory/old.bin'),
			'the old private payload remains available for key restoration');
		file_put_contents($key, $originalKey);
		chmod($key, 0600);
		$this->assertTrue(erasedataPrivateMarkerIsValid($root, $oldManifest)
			&& erasedataPrivateRootKey($newManifest, true) === $originalKey,
			'restoring the original key reopens both obligations without re-signing');
	}


	public function testPrivateRootKeyRejectsLinkAndCorruptControlFiles()
	{
		$this->reset();
		$manifest = $this->queuePath().'/'. $this->hash('A').'.list';
		$root = $this->dir.'/.erasedata-rmdir-key-validation';
		$this->assertTrue(mkdir($root, 0700)
			&& erasedataCreatePrivateMarker($root, $manifest),
			'a first signed root creates a durable key and first-use state');
		$key = $this->queuePath().'/.private-root-key';
		$state = $this->queuePath().'/.private-root-key-state';
		$this->assertEquals(0600, fileperms($key) & 0777,
			'new key is private to the service UID');
		$this->assertEquals(0600, fileperms($state) & 0777,
			'first-use state is private to the service UID');
		$original = file_get_contents($key);
		$saved = $this->dir.'/saved-private-key';
		$this->assertTrue(rename($key, $saved) && symlink($saved, $key),
			'a symlink can occupy the key name during a restore');
		$this->assertTrue(erasedataPrivateRootKey($manifest, false) === false,
			'a symlinked key is never accepted');
		unlink($key);
		$this->assertTrue(link($saved, $key)
			&& erasedataPrivateRootKey($manifest, false) === false,
			'a multiply linked key is never accepted');
		unlink($key);
		$this->assertTrue(rename($saved, $key)
			&& erasedataPrivateMarkerIsValid($root, $manifest),
			'restoring the original inode restores signature verification');
		file_put_contents($key, 'bad');
		$this->assertTrue(erasedataPrivateRootKey($manifest, false) === false,
			'a truncated key is held');
		file_put_contents($key, $original);
		$this->assertTrue(erasedataPrivateMarkerIsValid($root, $manifest),
			'full key bytes restore the existing signature');
		file_put_contents($state, 'x');
		$this->assertTrue(erasedataPrivateRootKey($manifest, false) === false,
			'a truncated first-use record is held');
		file_put_contents($state, 'v1');
		$this->assertTrue(erasedataPrivateMarkerIsValid($root, $manifest),
			'restoring first-use state reopens the signed root');
	}

	public function testPrivateRootSignatureDoesNotTransferToAnotherInode()
	{
		$this->reset();
		$key = $this->dir.'/erasedata/'.$this->hash('B').'.list';
		$a = $this->dir.'/.erasedata-rmdir-authority-a';
		$b = $this->dir.'/.erasedata-rmdir-authority-b';
		$this->assertTrue(erasedataProtectPrivateParent($a)
			&& mkdir($a, 0700) && mkdir($b, 0700),
			'both root names are under the protected parent');
		$this->assertTrue(erasedataCreatePrivateMarker($a, $key)
			&& erasedataPrivateMarkerIsValid($a, $key),
			'the new root has a valid durable authority marker');
		copy($a.'/.initialized', $b.'/.initialized');
		$this->assertTrue(!erasedataPrivateMarkerIsValid($b, $key),
			'a copied marker cannot authenticate a different root inode');
	}


}
