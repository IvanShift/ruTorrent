<?php

// Scripted collector environment for the erasedata tests.
//
// ErasedataCollectorFixture is an ErasedataFilesystemOps subclass whose single
// constructor argument is a structured scenario array keyed by operation and
// ordinal, for example:
//
//	array(
//		'rename:1' => array('result' => false),
//		'unlink:2' => array('action' => 'replace-entry'),
//	)
//
// The key is "<operation>:<ordinal>"; the ordinal is the 1-based index among the
// calls to that operation that also satisfy the entry's optional selector, and
// "*" matches every such call. Production code never reads this scenario: every
// race and failure injection below is an override of one seam method.
//
// Selectors: path, inode (array('dev'=>..,'ino'=>..)), basename, basename_prefix,
// contains, not_contains, to_contains (rename destination), after_scan.
// Directives: result (forced return value, real call skipped), action, at
// ('before' by default, 'after' runs only when the real call succeeded),
// marker, count_file and the per-action parameters documented inline.
//
// Running this file directly is the crash-only subprocess entry point. It takes
// exactly one argument: the absolute filename of a JSON scenario whose keys and
// types it validates before constructing the fixture.

require_once(dirname(__FILE__).'/../../../plugins/erasedata/filesystem.php');

if(!class_exists('FileUtil'))
{
	class FileUtil
	{
		public static $settingsPath = null;
		public static $log = array();
		public static $pluginConf = '$enableForceDeletion = true; $erasedebug_enabled = false;';
		public static function getSettingsPath() { return self::$settingsPath; }
		public static function getProfilePath() { return dirname(self::$settingsPath); }
		public static function getConfFile($name) { return false; }
		public static function makeDirectory($dir) { return @mkdir($dir, 0777, true); }
		public static function toLog($msg) { self::$log[] = $msg; }
		public static function getPluginConf($plugin) { return self::$pluginConf; }
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
		public static $responses = array();	// first command name => scripted reply
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
			{
				$entry = $response['byHash'][(string)$this->commands[0]->params];
				if(isset($entry['presence']) || isset($entry['generation']))
				{
					$generationRequest = count($this->commands) > 1
						&& $this->commands[1]->command === 'd.get_custom';
					$response = $generationRequest
						? (isset($entry['generation']) ? $entry['generation'] : $response)
						: (isset($entry['presence']) ? $entry['presence'] : $response);
				}
				else
					$response = $entry;
			}
			if(isset($response["callback"]) && is_callable($response["callback"]))
				call_user_func($response["callback"], $this->commands);
			if($first === 'd.hash' && isset($response['swap']) && $response['swap'] !== null)
				self::applySwap($response['swap']);
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
		// Scripted probe-time replacement of one manifest candidate.
		private static function applySwap($swap)
		{
			$mode = isset($swap[2]) ? $swap[2] : 'symlink';
			if($mode === 'rewrite')
			{
				@file_put_contents($swap[0], @file_get_contents($swap[1]));
				return;
			}
			@unlink($swap[0]);
			if($mode === 'rename')
				@rename($swap[1], $swap[0]);
			else
				@symlink($swap[1], $swap[0]);
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

// Replaceable metainfo lookup: the collector harness scripts the successor
// source instead of reaching rTorrent.
class ErasedataCollectorTestSource
{
	public $info;
	private $hash;
	public function __construct($source)
	{
		$this->info = $source['info'];
		$this->hash = $source['hash'];
	}
	public function hash_info() { return($this->hash); }
}

class ErasedataCollectorTestState
{
	public static $source = false;
	public static $indexBuilds = 0;
	public static $indexCountFile = null;
}

if(!function_exists('erasedataLoadTorrentSource'))
{
	function erasedataLoadTorrentSource($hash)
	{
		return(is_array(ErasedataCollectorTestState::$source)
			? new ErasedataCollectorTestSource(ErasedataCollectorTestState::$source) : false);
	}
}

class ErasedataCollectorFixture extends ErasedataFilesystemOps
{
	private $scenario;
	private $counters = array();
	private $swapped = false;
	private $scanned = array();

	public function __construct(array $scenario)
	{
		$this->scenario = $scenario;
	}

	// -- scenario plumbing --------------------------------------------------

	private function selects($entry, $path, $destination)
	{
		if(isset($entry['path']) && $path !== $entry['path'])
			return(false);
		if(isset($entry['basename']) && basename($path) !== $entry['basename'])
			return(false);
		if(isset($entry['basename_prefix'])
			&& strpos(basename($path), $entry['basename_prefix']) !== 0)
			return(false);
		if(isset($entry['contains']) && strpos($path, $entry['contains']) === false)
			return(false);
		if(isset($entry['not_contains']) && strpos($path, $entry['not_contains']) !== false)
			return(false);
		if(isset($entry['to_contains'])
			&& (!is_string($destination) || strpos($destination, $entry['to_contains']) === false))
			return(false);
		if(isset($entry['after_scan']) && empty($this->scanned[$entry['after_scan']]))
			return(false);
		if(isset($entry['inode']))
		{
			$current = parent::entryIdentity($path);
			if(!is_array($current)
				|| (string)$current['dev'] !== (string)$entry['inode']['dev']
				|| (string)$current['ino'] !== (string)$entry['inode']['ino'])
				return(false);
		}
		if(isset($entry['paths']) && !in_array($path, $entry['paths'], true))
			return(false);
		return(true);
	}

	// Returns the directive that fires for this call, or false.
	private function directive($operation, $path, $destination = null)
	{
		$ret = false;
		foreach($this->scenario as $key => $entry)
		{
			$parts = explode(':', $key, 2);
			if(count($parts) !== 2 || $parts[0] !== $operation || !is_array($entry))
				continue;
			if(!$this->selects($entry, $path, $destination))
				continue;
			$this->counters[$key] = isset($this->counters[$key]) ? $this->counters[$key] + 1 : 1;
			if(isset($entry['count_file']) && $entry['count_file'] !== null)
				@file_put_contents($entry['count_file'], $path."\n", FILE_APPEND);
			if($parts[1] !== '*' && (string)$this->counters[$key] !== (string)$parts[1])
				continue;
			if($ret === false)
				$ret = $entry;
		}
		return($ret);
	}

	private function mark($entry)
	{
		if(isset($entry['marker']))
			@file_put_contents($entry['marker'], 'triggered');
	}

	private function writeContent($directory, $entry)
	{
		if(isset($entry['content']))
			@file_put_contents($directory.'/'.$entry['content']['name'], $entry['content']['bytes']);
	}

	private function recordInode($path)
	{
		$stat = @lstat($path);
		if(is_array($stat))
			@file_put_contents($path.'.collision-inode', (string)$stat['ino']);
	}

	// Installs a replacement object at $path (or at the explicit action target)
	// and isolates the original.
	private function replaceEntry($path, $entry)
	{
		if($this->swapped)
			return;
		if(isset($entry['target']))
			$path = $entry['target'];
		$backup = isset($entry['backup']) ? $entry['backup'] : '';
		if($backup === '' || !parent::rename($path, $backup))
			return;
		$ok = false;
		if(isset($entry['symlink_target']))
			$ok = parent::makeSymlink($entry['symlink_target'], $path);
		else if(isset($entry['replacement']))
			$ok = parent::rename($entry['replacement'], $path);
		if(!$ok)
		{
			parent::rename($backup, $path);
			return;
		}
		$this->swapped = true;
		$this->mark($entry);
	}

	// Installs a replacement at an unrelated public name while another path is
	// being mutated. public_path is an action parameter, never a selector.
	private function replacePublicEntry($entry)
	{
		if($this->swapped || !isset($entry['public_path'], $entry['replacement']))
			return;
		$path = $entry['public_path'];
		if(is_link($path) && !parent::unlink($path))
			return;
		if(file_exists($path) || is_link($path)
			|| !parent::rename($entry['replacement'], $path))
			return;
		$this->swapped = true;
		$this->mark($entry);
	}

	// Moves the checked object aside and leaves a fresh directory in its place.
	private function swapSource($path, $entry)
	{
		if(!parent::rename($path, $path.'.checked'))
			return;
		parent::makeDirectory($path, 0777);
		$this->writeContent($path, $entry);
		if(!empty($entry['record_inode']))
			$this->recordInode($path);
		$this->mark($entry);
	}

	private function crash($path, $entry)
	{
		$this->writeContent($path, $entry);
		$this->mark($entry);
		exit(0);
	}

	private function transition($entry)
	{
		$new = $entry['new'];
		$old = $entry['old'];
		if($entry['kind'] === 'missing-to-symlink')
			@symlink($old, $new);
		else if($entry['kind'] === 'missing-to-hardlink')
			@link($old, $new);
		else if($entry['kind'] === 'alias-to-distinct')
		{
			@unlink($new);
			@file_put_contents($new, 'distinct');
		}
		else if($entry['kind'] === 'alias-to-missing')
			@unlink($new);
		$this->mark($entry);
	}

	private function act($entry, $path, $when)
	{
		$at = isset($entry['at']) ? $entry['at'] : 'before';
		if($at !== $when || !isset($entry['action']))
			return;
		switch($entry['action'])
		{
			case 'replace-entry':
				$this->replaceEntry($path, $entry);
				break;
			case 'replace-public':
				$this->replacePublicEntry($entry);
				break;
			case 'swap-source':
				$this->swapSource($path, $entry);
				break;
			case 'swap-destination':
				$this->swapSource($entry['destination'], $entry);
				break;
			case 'recreate':
				parent::makeDirectory($path, 0777);
				$this->writeContent($path, $entry);
				$this->mark($entry);
				break;
			case 'collide':
				parent::makeDirectory($path, 0777);
				$this->recordInode($path);
				$this->mark($entry);
				break;
			case 'transition':
				$this->transition($entry);
				break;
			case 'exit':
				$this->crash($path, $entry);
				break;
			default:
				break;
		}
	}

	private function forced($entry)
	{
		return(is_array($entry) && array_key_exists('result', $entry));
	}

	// -- scripted seam operations -------------------------------------------

	public function entryIdentity($path)
	{
		$entry = $this->directive('entryIdentity', $path);
		if($entry === false)
			return(parent::entryIdentity($path));
		$this->act($entry, $path, 'before');
		if($this->forced($entry))
		{
			$this->mark($entry);
			return($entry['result']);
		}
		$result = parent::entryIdentity($path);
		if($result !== false)
			$this->act($entry, $path, 'after');
		return($result);
	}

	public function targetIdentity($path)
	{
		$entry = $this->directive('targetIdentity', $path);
		if($entry === false)
			return(parent::targetIdentity($path));
		$this->act($entry, $path, 'before');
		if($this->forced($entry))
			return($entry['result']);
		return(parent::targetIdentity($path));
	}

	public function rename($from, $to)
	{
		$entry = $this->directive('rename', $from, $to);
		if($entry === false)
			return(parent::rename($from, $to));
		if(isset($entry['action']) && $entry['action'] === 'swap-destination')
			$entry['destination'] = $to;
		$this->act($entry, $from, 'before');
		if($this->forced($entry))
		{
			$this->mark($entry);
			return($entry['result']);
		}
		$result = parent::rename($from, $to);
		if($result)
			$this->act($entry, $from, 'after');
		return($result);
	}

	public function unlink($path)
	{
		$entry = $this->directive('unlink', $path);
		if($entry === false)
			return(parent::unlink($path));
		$this->act($entry, $path, 'before');
		if($this->forced($entry))
		{
			$this->mark($entry);
			return($entry['result']);
		}
		$result = parent::unlink($path);
		if($result)
			$this->act($entry, $path, 'after');
		return($result);
	}

	public function makeDirectory($path, $mode)
	{
		$entry = $this->directive('makeDirectory', $path);
		if($entry === false)
			return(parent::makeDirectory($path, $mode));
		$this->act($entry, $path, 'before');
		if($this->forced($entry))
			return($entry['result']);
		$result = parent::makeDirectory($path, $mode);
		if($result)
			$this->act($entry, $path, 'after');
		return($result);
	}

	public function removeDirectory($path)
	{
		$entry = $this->directive('removeDirectory', $path);
		if($entry === false)
			return(parent::removeDirectory($path));
		$this->act($entry, $path, 'before');
		if($this->forced($entry))
		{
			$this->mark($entry);
			return($entry['result']);
		}
		$result = parent::removeDirectory($path);
		if($result)
			$this->act($entry, $path, 'after');
		return($result);
	}

	public function makeSymlink($target, $path)
	{
		$entry = $this->directive('makeSymlink', $path);
		if($entry === false)
			return(parent::makeSymlink($target, $path));
		$this->act($entry, $path, 'before');
		if($this->forced($entry))
			return($entry['result']);
		$result = parent::makeSymlink($target, $path);
		if($result)
			$this->act($entry, $path, 'after');
		return($result);
	}

	public function readLink($path)
	{
		$entry = $this->directive('readLink', $path);
		if($entry !== false && $this->forced($entry))
			return($entry['result']);
		return(parent::readLink($path));
	}

	public function scanDirectory($path)
	{
		$entries = parent::scanDirectory($path);
		if(is_array($entries))
			foreach($entries as $name)
				$this->scanned[$name] = true;
		// Every enumeration of the queue directory, counted at the seam. One
		// collector pass resumes captured entries once and builds the index
		// once; rescanning per job shows up here as extra lines.
		if(ErasedataCollectorTestState::$indexCountFile !== null
			&& $path === FileUtil::getSettingsPath().'/erasedata')
		{
			ErasedataCollectorTestState::$indexBuilds++;
			@file_put_contents(ErasedataCollectorTestState::$indexCountFile,
				"1\n", FILE_APPEND);
		}
		$entry = $this->directive('scanDirectory', $path);
		if($entry !== false && $this->forced($entry))
			return($entry['result']);
		return($entries);
	}

	public function openDirectoryReference($path, $expectedIdentity)
	{
		$entry = $this->directive('openDirectoryReference', $path);
		if($entry === false)
			return(parent::openDirectoryReference($path, $expectedIdentity));
		$this->act($entry, $path, 'before');
		if($this->forced($entry))
		{
			$this->mark($entry);
			return($entry['result']);
		}
		$result = parent::openDirectoryReference($path, $expectedIdentity);
		if($result !== false)
			$this->act($entry, $path, 'after');
		return($result);
	}

	public function unlinkCapturedEntry($path, $expectedIdentity, $reservationKey,
		$emptyDirectoryOnly = false)
	{
		$entry = $this->directive('unlinkCapturedEntry', $path);
		if($entry === false)
			return(parent::unlinkCapturedEntry(
				$path, $expectedIdentity, $reservationKey, $emptyDirectoryOnly));
		$this->act($entry, $path, 'before');
		if($this->forced($entry))
		{
			$this->mark($entry);
			return($entry['result']);
		}
		$result = parent::unlinkCapturedEntry(
			$path, $expectedIdentity, $reservationKey, $emptyDirectoryOnly);
		if($result)
			$this->act($entry, $path, 'after');
		return($result);
	}

	public function removePrivateContainer($root, array $allowedEntries)
	{
		$entry = $this->directive('removePrivateContainer', $root);
		if($entry === false)
			return(parent::removePrivateContainer($root, $allowedEntries));
		$this->act($entry, $root, 'before');
		if($this->forced($entry))
		{
			$this->mark($entry);
			return($entry['result']);
		}
		$result = parent::removePrivateContainer($root, $allowedEntries);
		if($result)
			$this->act($entry, $root, 'after');
		return($result);
	}
}

// ---------------------------------------------------------------------------
// Crash-only subprocess entry point: exactly one argument, the absolute
// filename of a JSON scenario.
// ---------------------------------------------------------------------------

function erasedataCollectorFixtureFail($message)
{
	fwrite(STDERR, 'CollectorFixture: '.$message."\n");
	exit(2);
}

function erasedataCollectorFixtureScenario($file)
{
	if(!is_string($file) || $file === '' || $file[0] !== '/' || !is_file($file))
		erasedataCollectorFixtureFail('scenario argument must be one absolute filename');
	$raw = @file_get_contents($file);
	if(!is_string($raw))
		erasedataCollectorFixtureFail('scenario file is unreadable');
	$decoded = json_decode($raw, true);
	if(!is_array($decoded))
		erasedataCollectorFixtureFail('scenario file is not a JSON object');
	$required = array(
		'mode' => 'string',
		'settings' => 'string',
		'profileMask' => 'integer',
		'debug' => 'boolean',
		'onlyHash' => 'string_or_null',
		'publicCollectorHash' => 'string_or_null',
		'indexCountFile' => 'string_or_null',
		'source' => 'array_or_false',
		'responses' => 'array',
		'scenario' => 'array',
		'logFile' => 'string',
	);
	if(count(array_diff(array_keys($decoded), array_keys($required)))
		|| count(array_diff(array_keys($required), array_keys($decoded))))
		erasedataCollectorFixtureFail('scenario keys must be exactly: '
			.implode(', ', array_keys($required)));
	foreach($required as $key => $type)
	{
		$value = $decoded[$key];
		$ok = true;
		if($type === 'string')
			$ok = is_string($value);
		else if($type === 'integer')
			$ok = is_int($value);
		else if($type === 'boolean')
			$ok = is_bool($value);
		else if($type === 'string_or_null')
			$ok = is_null($value) || is_string($value);
		else if($type === 'array')
			$ok = is_array($value);
		else if($type === 'array_or_false')
			$ok = is_array($value) || $value === false;
		if(!$ok)
			erasedataCollectorFixtureFail('scenario key '.$key.' has the wrong type');
	}
	if(!is_dir($decoded['settings']))
		erasedataCollectorFixtureFail('scenario settings path is not a directory');
	if($decoded['mode'] !== 'collect' && $decoded['mode'] !== 'import')
		erasedataCollectorFixtureFail('scenario mode must be collect or import');
	return($decoded);
}

// Import-safety probe: require collector.php and report every observable effect.
function erasedataCollectorFixtureImport($scenario)
{
	global $erasedebug_enabled;
	$erasedebug_enabled = true;
	$listPath = $scenario['settings'].'/erasedata';
	$before = @scandir($listPath);
	require_once(dirname(__FILE__).'/../../../plugins/erasedata/collector.php');
	$after = @scandir($listPath);
	@file_put_contents($scenario['logFile'], json_encode(array(
		'rpc' => rXMLRPCRequest::$requested,
		'erased' => rXMLRPCRequest::$erased,
		'log' => FileUtil::$log,
		'before' => is_array($before) ? $before : array(),
		'after' => is_array($after) ? $after : array(),
		'lock' => file_exists($listPath.'/scheduler.lock'),
		'collector' => class_exists('ErasedataCollector', false),
	)));
}

function erasedataCollectorFixtureConfigure($scenario)
{
	global $profileMask;
	$profileMask = $scenario['profileMask'];
	FileUtil::$settingsPath = $scenario['settings'];
	FileUtil::$pluginConf = '$enableForceDeletion=true;$erasedebug_enabled='
		.($scenario['debug'] ? 'true' : 'false').';';
	rXMLRPCRequest::$responses = $scenario['responses'];
	ErasedataCollectorTestState::$source = $scenario['source'];
	ErasedataCollectorTestState::$indexCountFile = $scenario['indexCountFile'];
}

function erasedataCollectorFixtureRun($scenario)
{
	if($scenario['publicCollectorHash'] !== null)
		erasedataRunCollector(FileUtil::getSettingsPath().'/erasedata',
			$scenario['publicCollectorHash']);
	else
		erasedataCollectorMain(new ErasedataCollectorFixture($scenario['scenario']));
}

function erasedataCollectorFixtureFlush($logFile)
{
	@file_put_contents($logFile, json_encode(FileUtil::$log));
}

// update.php and collector.php are required at file scope on purpose: the
// plugin configuration must be evaluated into the global scope, exactly as it
// is for the scheduled production entry point.
if(PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
	&& realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__)
{
	if(!isset($argv) || count($argv) !== 2)
		erasedataCollectorFixtureFail('expects exactly one argument');
	$erasedataFixtureScenario = erasedataCollectorFixtureScenario($argv[1]);
	erasedataCollectorFixtureConfigure($erasedataFixtureScenario);
	if($erasedataFixtureScenario['mode'] === 'import')
		erasedataCollectorFixtureImport($erasedataFixtureScenario);
	else
	{
		$argv = array('update.php', 'rutorrent');
		if($erasedataFixtureScenario['onlyHash'] !== null)
			$argv[] = $erasedataFixtureScenario['onlyHash'];
		register_shutdown_function('erasedataCollectorFixtureFlush',
			$erasedataFixtureScenario['logFile']);
		require(dirname(__FILE__).'/../../../plugins/erasedata/update.php');
		erasedataCollectorFixtureRun($erasedataFixtureScenario);
	}
}
