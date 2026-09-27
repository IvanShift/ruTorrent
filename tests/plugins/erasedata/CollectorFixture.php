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
		// Recording-only schedule register: every scheduling RPC this stub sees,
		// as family/command/key/interval/argv. It never launches, acknowledges or
		// otherwise stands in for a child -- rTorrent's scheduler is simulated by
		// the test starting a real process of its own, separately.
		public static $scheduledCommands = array();

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
			foreach($this->commands as $scheduled)
			{
				$family = self::scheduleFamily($scheduled->command);
				if($family === false)
					continue;
				$argv = is_array($scheduled->params)
					? $scheduled->params : array($scheduled->params);
				self::$scheduledCommands[] = array(
					'family' => $family,
					'command' => $scheduled->command,
					'key' => array_key_exists(0, $argv) ? $argv[0] : null,
					'interval' => ($family === 'schedule' && array_key_exists(2, $argv))
						? $argv[2] : null,
					'argv' => $argv,
				);
			}
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
		// The logical rTorrent scheduling families, by every spelling a request
		// can carry. The deprecated schedule2/schedule_remove2 aliases are
		// recognised only so a test can prove production never emits them; they
		// are not supported production names.
		public static function scheduleFamily($command)
		{
			$command = (string)$command;
			if($command === 'schedule' || $command === 'schedule2')
				return('schedule');
			if($command === 'schedule.if_absent')
				return('schedule.if_absent');
			if($command === 'schedule_remove' || $command === 'schedule.remove'
				|| $command === 'schedule_remove2')
				return('schedule_remove');
			return(false);
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
if(!class_exists('rTorrentSettings'))
{
	// The one core method the plugin's schedule builders reach for. In
	// production php/xmlrpc.php requires php/settings.php, so the real class is
	// present wherever rXMLRPCCommand is; here the arithmetic is copied from
	// rTorrentSettings::getAlignedStart() in php/settings.php (reflowed, but
	// identical arithmetic), so a test measures the alignment production really
	// gets rather than a convenient approximation of it.
	class rTorrentSettings
	{
		static public function getAlignedStart($name, $interval, $now = null)
		{
			global $schedule_rand;
			if(!isset($schedule_rand))
				$schedule_rand = 10;
			if($interval < 1)
				return(0);
			if(is_null($now))
				$now = time();
			$offset = ($schedule_rand > 0)
				? (abs(crc32($name.User::getUser())) % ($schedule_rand + 1)) : 0;
			$startAt = (($offset - $now) % $interval + $interval) % $interval;
			return($startAt < 1 ? $interval : $startAt);
		}
	}
}

// Replaceable metainfo lookup: the collector harness scripts successor and
// other-torrent sources instead of reaching rTorrent.
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
	public static $fleetSources = array();
	public static $indexBuilds = 0;
	public static $indexCountFile = null;
}

if(!function_exists('erasedataLoadTorrentSource'))
{
	function erasedataLoadTorrentSource($hash)
	{
		$source = isset(ErasedataCollectorTestState::$fleetSources[$hash])
			? ErasedataCollectorTestState::$fleetSources[$hash]
			: ErasedataCollectorTestState::$source;
		return(is_array($source)
			? new ErasedataCollectorTestSource($source) : false);
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
		if(isset($entry['realpath']) && @realpath($path) !== $entry['realpath'])
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
			case 'fleet-change':
				rXMLRPCRequest::$responses['d.multicall'] = array(
					'ok' => true, 'fault' => !empty($entry['fault']),
					'val' => isset($entry['rows']) ? $entry['rows'] : array());
				if(isset($entry['sources']))
					ErasedataCollectorTestState::$fleetSources = $entry['sources'];
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

	// Model the helper's no-clobber contract without requiring a C compiler in
	// each PHP matrix container; the compiled helper has its own syscall test.
	public function renameNoReplace($from, $to)
	{
		$entry = $this->directive('renameNoReplace', $to);
		if($entry !== false)
		{
			$this->act($entry, $to, 'before');
			if($this->forced($entry)) return($entry['result']);
		}
		if(erasedataPathExists($to)) return(false);
		$result = parent::rename($from, $to);
		if($result && $entry !== false) $this->act($entry, $to, 'after');
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
		if($entry !== false)
			$this->act($entry, $path, 'after');
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
// Package 6 harness: real child processes and a byte-verified mirror of the
// production plugin tree.
//
// Nothing below is reachable from production code. The mirror writes its own
// adapters for everything BELOW the plugin boundary (php/util.php,
// php/xmlrpc.php) and copies the production plugin files byte for byte, so
// a child really executes the shipped action.php / erase.php / update.php /
// init.php bytes, with the settings root baked into the generated adapter
// rather than injected through the environment.
// ---------------------------------------------------------------------------

// One child process with a bounded lifetime, a direct handle and a reap that
// happens whatever the test does -- including when the test method returns
// early or throws.
class ErasedataTestProcess
{
	private $handle = null;
	private $pipes = array();
	public $command = '';
	public $out = '';
	public $err = '';
	public $exitCode = null;
	public $startFailed = false;

	public static function start($command, $cwd = null, $stdin = null)
	{
		$self = new self();
		$self->command = $command;
		$descriptors = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$pipes = array();
		$handle = @proc_open($command, $descriptors, $pipes, $cwd, null);
		if(!is_resource($handle))
		{
			$self->startFailed = true;
			return($self);
		}
		$self->handle = $handle;
		if(is_string($stdin) && isset($pipes[0]) && is_resource($pipes[0]))
			@fwrite($pipes[0], $stdin);
		if(isset($pipes[0]) && is_resource($pipes[0]))
			@fclose($pipes[0]);
		unset($pipes[0]);
		foreach($pipes as $pipe)
			if(is_resource($pipe))
				@stream_set_blocking($pipe, false);
		$self->pipes = $pipes;
		return($self);
	}

	public function started() { return(!$this->startFailed); }

	// The child's own pid, or false once it has been reaped.
	public function pid()
	{
		if(!is_resource($this->handle))
			return(false);
		$status = @proc_get_status($this->handle);
		return(is_array($status) && isset($status['pid']) ? (int)$status['pid'] : false);
	}

	// Kill the child WHERE IT STANDS, for a case that has to observe a process
	// that died in the middle of something rather than one that was allowed to
	// finish. proc_open() runs the command through sh, so this reaches the php
	// process only because every command this harness builds is 'exec'-prefixed
	// -- see ErasedataProductionMirror::php() for why that prefix is there.
	// The handle is kept: the caller still waits for and reaps the corpse.
	public function kill($signal = 9)
	{
		if(!is_resource($this->handle))
			return(false);
		return(@proc_terminate($this->handle, $signal));
	}

	// Drains both pipes without blocking, so a chatty child cannot fill a pipe
	// buffer and wedge itself while the test waits for it to exit.
	public function pump()
	{
		foreach(array(1 => 'out', 2 => 'err') as $fd => $field)
		{
			if(!isset($this->pipes[$fd]) || !is_resource($this->pipes[$fd]))
				continue;
			while(true)
			{
				$chunk = @fread($this->pipes[$fd], 65536);
				if($chunk === false || $chunk === '')
					break;
				$this->$field .= $chunk;
			}
		}
	}

	public function running()
	{
		if(!is_resource($this->handle))
			return(false);
		$status = @proc_get_status($this->handle);
		if(!is_array($status))
			return(false);
		if(!$status['running'] && $this->exitCode === null)
			$this->exitCode = $status['exitcode'];
		return((bool)$status['running']);
	}

	// True when the child finished inside the budget.
	public function wait($seconds)
	{
		$deadline = microtime(true) + $seconds;
		while(microtime(true) < $deadline)
		{
			$this->pump();
			if(!$this->running())
				return(true);
			usleep(20000);
		}
		$this->pump();
		return(!$this->running());
	}

	public function reap()
	{
		if(!is_resource($this->handle))
			return($this->exitCode);
		$this->pump();
		$status = @proc_get_status($this->handle);
		if(is_array($status) && $status['running'])
		{
			@proc_terminate($this->handle, 9);
			for($i = 0; $i < 300; $i++)
			{
				$status = @proc_get_status($this->handle);
				if(!is_array($status) || !$status['running'])
					break;
				usleep(10000);
			}
		}
		if(is_array($status) && !$status['running'] && $this->exitCode === null)
			$this->exitCode = $status['exitcode'];
		foreach($this->pipes as $pipe)
			if(is_resource($pipe))
				@fclose($pipe);
		$this->pipes = array();
		$closed = @proc_close($this->handle);
		$this->handle = null;
		if($this->exitCode === null)
			$this->exitCode = $closed;
		return($this->exitCode);
	}

	public function __destruct() { $this->reap(); }

	// Waits for every child inside ONE shared budget and returns true only when
	// all of them finished on their own.
	public static function waitAll(array $processes, $seconds)
	{
		$deadline = microtime(true) + $seconds;
		$pending = $processes;
		while(count($pending) && microtime(true) < $deadline)
		{
			foreach($pending as $key => $process)
			{
				$process->pump();
				if(!$process->running())
					unset($pending[$key]);
			}
			if(count($pending))
				usleep(20000);
		}
		foreach($processes as $process)
			$process->pump();
		return(!count($pending));
	}

	public static function reapAll(array $processes)
	{
		$codes = array();
		foreach($processes as $key => $process)
			$codes[$key] = $process->reap();
		return($codes);
	}
}

// A byte-verified copy of plugins/erasedata plus test-owned adapters for the
// two core files the plugin includes. $sourceRoot must be the repository root,
// handed in from the test's __DIR__ so it does not depend on the caller's cwd.
class ErasedataProductionMirror
{
	public $root;
	public $settings;
	public $listPath;
	public $pluginDir;
	public $user;
	// The transport directory of the separate daemon fixture. It exists for
	// every mirror and is inert until a case configures the aggregate mode.
	public $daemonDir;
	private $prepend = null;
	public $copyFailures = array();
	public $unreadable = array();

	public static function pluginFiles()
	{
		return(array('action.php', 'collector.php', 'conf.php', 'done.php',
			'erase.php', 'filesystem.php', 'init.php', 'manifest.php',
			'pending.php', 'removewithdata.php', 'status.php', 'update.php'));
	}

	public static function build($root, $sourceRoot, $user = 'rutorrent')
	{
		$self = new self();
		$self->root = $root;
		$self->user = $user;
		$self->pluginDir = $root.'/plugins/erasedata';
		$self->settings = $root.'/settings';
		$self->listPath = $self->settings.'/erasedata';
		$self->daemonDir = $self->settings.'/daemon';
		@mkdir($self->pluginDir, 0777, true);
		@mkdir($root.'/php', 0777, true);
		@mkdir($self->listPath, 0777, true);
		@mkdir($self->daemonDir.'/req', 0777, true);
		@mkdir($self->daemonDir.'/rep', 0777, true);
		$copies = array();
		foreach(self::pluginFiles() as $name)
			$copies['plugins/erasedata/'.$name] = 'plugins/erasedata/'.$name;
		// A core file the plugin requires by name. It is out of this package's
		// scope, so the mirror carries its real bytes rather than an adapter.
		$copies['php/xmlrpc_path.php'] = 'php/xmlrpc_path.php';
		foreach($copies as $relative => $target)
		{
			$bytes = @file_get_contents($sourceRoot.'/'.$relative);
			if(!is_string($bytes))
			{
				$self->unreadable[] = $relative;
				continue;
			}
			$to = $root.'/'.$target;
			@file_put_contents($to, $bytes);
			$written = @file_get_contents($to);
			if(!is_string($written) || $written !== $bytes)
				$self->copyFailures[] = $relative;
		}
		$self->writeAdapters();
		return($self);
	}

	// True only when every production file was readable and copied byte for
	// byte: a mirror that silently lost a file must never look like a pass.
	public function isExact()
	{
		return(!count($this->unreadable) && !count($this->copyFailures));
	}

	public function describe()
	{
		return('unreadable='.implode(',', $this->unreadable)
			.' mismatched='.implode(',', $this->copyFailures));
	}

	// Scripted replies, keyed by the FIRST command name of a request. Each entry
	// may carry val/fault/faultString/faultCode/ok, an 'exit' code that kills
	// the child process at that exact RPC boundary, and 'after' to switch to a
	// different entry once this one has fired.
	//
	// Two more, both for cases about several actors at once:
	//   'byParam' => array(<first parameter> => array(<overriding fields>))
	//     answers per hash instead of once for the whole batch, so each member
	//     of a batch can own a payload only its own manifest names;
	//   'delay' => <microseconds>
	//     holds the caller inside the request after it has been recorded, which
	//     is how a case makes two real children overlap in time on purpose.
	public function scriptRpc(array $script)
	{
		@file_put_contents($this->settings.'/rpc-script.json', json_encode($script));
	}

	// -- the aggregate mode: a real second actor on the daemon side ---------
	//
	// The scripted table above answers a REQUEST. That is enough for every case
	// whose boundary is a whole request, and it is structurally unable to
	// express a boundary INSIDE one: erasedataEraseRequest() sends d.set_custom5
	// + d.delete_tied + d.erase per member in a single aggregate, so "the first
	// erase ran and the second did not" is a point three commands into one
	// request, and the `cut` above fires BEFORE the request is even written.
	//
	// Configuring the aggregate mode hands every request whose commands are all
	// answerable from a daemon's presence table to a SEPARATE process --
	// AggregateEraseFixture.php -- which applies them one at a time against a
	// table of its own and can stop at an exact executed d.erase. $config is
	// the daemon's whole configuration:
	//
	//	array(
	//		'aggregate' => array(
	//			'after_erase' => 1,
	//			'mode' => 'pause-after-erase',
	//			'barrier' => 'first-erase-applied',
	//		),
	//		'hashes' => array($hash => array('present' => true,
	//			'base' => '/p/name', 'multi' => 1,
	//			'files' => array('/p/name/a.bin'))),
	//	)
	//
	// 'after_erase' counts EXECUTED d.erase commands inside one batch, not
	// requests. The presence table is durable and belongs to the daemon: a
	// restart of it keeps exactly what it had already executed.
	public function scriptAggregate(array $config)
	{
		$config['dir'] = $this->daemonDir;
		if(!isset($config['idle']))
			$config['idle'] = 90;
		@mkdir($this->daemonDir.'/req', 0777, true);
		@mkdir($this->daemonDir.'/rep', 0777, true);
		return(@file_put_contents($this->daemonDir.'/config.json',
			json_encode($config)) !== false);
	}

	// The daemon fixture's own command line. It is started by the TEST, held on
	// a handle of its own and reaped by it, exactly like every other child here.
	public function daemonCommand()
	{
		return($this->php(dirname(__FILE__).'/AggregateEraseFixture.php',
			array($this->daemonDir.'/config.json')));
	}

	public function daemonEvents()
	{
		$raw = @file_get_contents($this->daemonDir.'/events.jsonl');
		if(!is_string($raw) || $raw === '')
			return(array());
		$ret = array();
		foreach(explode("\n", $raw) as $line)
		{
			if($line === '')
				continue;
			$decoded = json_decode($line, true);
			if(is_array($decoded))
				$ret[] = $decoded;
		}
		return($ret);
	}

	// Every event of one kind, in the order the daemon recorded them.
	public function daemonEventsOf($event)
	{
		$ret = array();
		foreach($this->daemonEvents() as $record)
			if(isset($record['event']) && $record['event'] === $event)
				$ret[] = $record;
		return($ret);
	}

	// The daemon's own presence table, which is the only thing that says what
	// really happened to a download.
	public function daemonState()
	{
		$raw = @file_get_contents($this->daemonDir.'/state.json');
		$decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
		return(is_array($decoded) ? $decoded : null);
	}

	public function daemonHolds($hash)
	{
		$state = $this->daemonState();
		return(is_array($state) && isset($state['hashes'][$hash])
			&& !empty($state['hashes'][$hash]['present']));
	}

	// The d.erase commands the daemon really APPLIED, in order. Not the ones a
	// client sent: those are in rpcLog().
	public function daemonExecutedErases()
	{
		$state = $this->daemonState();
		return(is_array($state) && isset($state['erased']) && is_array($state['erased'])
			? array_values($state['erased']) : array());
	}

	// The control files a previous phase left behind. A daemon started while an
	// old 'stop' still sits there would exit at once, which is a silent way to
	// run a recovery phase with no daemon at all.
	public function daemonClearControls()
	{
		foreach(array('stop', 'release', 'barrier') as $name)
			@unlink($this->daemonDir.'/'.$name);
		clearstatcache();
	}

	// The 'await' gate of a scripted crash cut: wait for the client to announce
	// it has reached the cut, then let it die. A timeout returns false and is a
	// FAILURE for the caller to report, never a way of carrying on.
	public function crashGateReached($name, $seconds)
	{
		$deadline = microtime(true) + $seconds;
		while(microtime(true) < $deadline)
		{
			clearstatcache(true, $this->settings.'/'.$name.'.ready');
			if(@file_exists($this->settings.'/'.$name.'.ready'))
				return(true);
			usleep(5000);
		}
		return(false);
	}

	public function crashGateExpired($name)
	{
		clearstatcache(true, $this->settings.'/'.$name.'.expired');
		return(@file_exists($this->settings.'/'.$name.'.expired'));
	}

	public function releaseCrashGate($name)
	{
		@file_put_contents($this->settings.'/'.$name, 'go');
	}

	public function daemonRelease() { @file_put_contents($this->daemonDir.'/release', 'go'); }
	public function daemonAskStop() { @file_put_contents($this->daemonDir.'/stop', 'stop'); }
	public function daemonAlive()
	{
		clearstatcache(true, $this->daemonDir.'/alive');
		return(@file_exists($this->daemonDir.'/alive'));
	}

	// Wait for one named file in the daemon's transport directory. Returns
	// false on timeout, and a timeout is a FAILURE for the caller to report --
	// never an alternative way of carrying on.
	public function daemonWaitFile($name, $seconds)
	{
		$deadline = microtime(true) + $seconds;
		while(microtime(true) < $deadline)
		{
			clearstatcache(true, $this->daemonDir.'/'.$name);
			if(@file_exists($this->daemonDir.'/'.$name))
				return(true);
			usleep(5000);
		}
		return(false);
	}

	// Wait until the daemon has recorded one named event for ONE request. The
	// unqualified count is not enough where a case has to wait for the answer
	// to a PARTICULAR batch: earlier requests have already delivered replies of
	// their own, and waiting on the total returns at once.
	public function daemonWaitRequestEvent($event, $request, $seconds)
	{
		$deadline = microtime(true) + $seconds;
		while(microtime(true) < $deadline)
		{
			foreach($this->daemonEventsOf($event) as $record)
				if(isset($record['request']) && $record['request'] === $request)
					return(true);
			usleep(5000);
		}
		return(false);
	}

	// The request ids this daemon has written a reply for. A reply file is
	// never removed, so this says what was ANSWERED and never what was read.
	public function daemonReplies()
	{
		$ret = array();
		foreach((array)@glob($this->daemonDir.'/rep/*.json') as $path)
			$ret[] = basename($path, '.json');
		sort($ret, SORT_STRING);
		return($ret);
	}

	public function rpcLog()
	{
		$raw = @file_get_contents($this->settings.'/rpc.log');
		if(!is_string($raw) || $raw === '')
			return(array());
		$ret = array();
		foreach(explode("\n", $raw) as $line)
		{
			if($line === '')
				continue;
			$decoded = json_decode($line, true);
			if(is_array($decoded))
				$ret[] = $decoded;
		}
		return($ret);
	}

	public function scheduleLog()
	{
		$ret = array();
		foreach($this->rpcLog() as $request)
			foreach($request as $command)
			{
				if(!isset($command['command']))
					continue;
				$family = rXMLRPCRequest::scheduleFamily($command['command']);
				if($family === false)
					continue;
				$argv = isset($command['params']) && is_array($command['params'])
					? $command['params'] : array(isset($command['params']) ? $command['params'] : null);
				$ret[] = array(
					'family' => $family,
					'command' => $command['command'],
					'key' => array_key_exists(0, $argv) ? $argv[0] : null,
					'interval' => ($family === 'schedule' && array_key_exists(2, $argv))
						? $argv[2] : null,
					'argv' => $argv,
				);
			}
		return($ret);
	}

	public function log()
	{
		$raw = @file_get_contents($this->settings.'/plugin.log');
		return(is_string($raw) ? explode("\n", $raw) : array());
	}

	// The command a scheduled rTorrent execute= argument would really run. The
	// test plays the scheduler: it reads the registered argv and starts the
	// child itself, in a process separate from the registration.
	public static function scheduledChildCommand($record)
	{
		if(!isset($record['argv']) || !is_array($record['argv']))
			return(false);
		foreach($record['argv'] as $argument)
		{
			if(!is_string($argument) || strpos($argument, 'update.php') === false)
				continue;
			$start = strpos($argument, '{sh,-c,');
			if($start === false)
				return(trim($argument));
			$inner = substr($argument, $start + 7);
			$end = strrpos($inner, '}');
			return(trim($end === false ? $inner : substr($inner, 0, $end)));
		}
		return(false);
	}

	// A test-owned script inside the mirror. It stands in for the plugin
	// loader (init.php, done.php) or for a producer loop, and never for any
	// part of the plugin itself.
	public function writeRunner($name, $body)
	{
		$path = $this->root.'/'.$name.'.php';
		@file_put_contents($path, "<?php\n"
			."require_once(".var_export($this->root.'/php/xmlrpc.php', true).");\n"
			.$body);
		return($path);
	}

	public function php($script, array $arguments = array())
	{
		// proc_open() runs this string through /bin/sh -c, and sh FORKS the
		// interpreter for a plain command instead of exec'ing it. Without the
		// leading 'exec ' the reaper's proc_terminate() kills only the sh
		// wrapper and the php grandchild survives, reparented: a paired-process
		// case then races an unkillable drain worker that goes on mutating
		// durable state after the case believes it has stopped it.
		// erasedataTestLockHolderCommand() below already does this for the same
		// reason. The prefix belongs here and NOT in ErasedataTestProcess::
		// start(), because that command already carries its own 'exec ' and
		// dash refuses 'exec exec <cmd>'.
		$command = 'exec '.escapeshellarg(PHP_BINARY).' -d display_errors=0'
			.($this->prepend !== null
				? ' -d '.escapeshellarg("auto_prepend_file='".$this->prepend."'") : '')
			.' -f '.escapeshellarg($script);
		if(count($arguments))
		{
			$command .= ' --';
			foreach($arguments as $argument)
				$command .= ' '.escapeshellarg($argument);
		}
		return($command);
	}

	// Hand the children this mirror starts through php() -- the producers,
	// workers and runners a case launches itself -- a shorter
	// ERASEDATA_DRAIN_ACK_TIMEOUT. The plugin guards its constants with
	// if(!defined()), and those children are the real entry points, which
	// define nothing before the plugin loads, so a prepended define is the one
	// seam that reaches them without a change to production bytes (isExact()
	// stays true). The command the plugin records for rTorrent's scheduler is
	// not built here and does not carry it. Only the length of the wait
	// changes: an unanswered producer still arms, still waits, still leaves
	// its obligation queued. Use it where the producer is SETUP for what the
	// case is about. Cases that answer the producer inside its own wait
	// window keep the shipped 11 s ERASEDATA_DRAIN_ACK_TIMEOUT.
	public function shortenAcknowledgementWait($seconds = 1.0)
	{
		$path = $this->root.'/defines.php';
		// php() uses an INI single-quoted value inside the shell argument so
		// double quotes and ${NAME} remain literal. This helper does not support
		// apostrophes, line breaks or NUL in the mirror root.
		if(strpbrk($path, "'\r\n\0") !== false)
			throw new RuntimeException('the mirror root '.$this->root
				.' is unsupported: a single quote breaks the mirror INI quoting; '
				.'line breaks and NUL are rejected by this helper');
		if(@file_put_contents($path, "<?php\n"
			."define('ERASEDATA_DRAIN_ACK_TIMEOUT', ".var_export((float)$seconds, true).");\n") === false)
			throw new RuntimeException('could not write '.$path);
		$this->prepend = $path;
	}

	// The real guarded worker entry point, exactly as the scheduler starts it.
	public function drainCommand($mode = 'drain')
	{
		$arguments = array($this->user);
		if($mode !== '' && $mode !== null)
			$arguments[] = $mode;
		return($this->php($this->pluginDir.'/update.php', $arguments));
	}

	public function eraseCommand($hash, $force)
	{
		return($this->php($this->pluginDir.'/erase.php',
			array($hash, (string)$force, $this->user)));
	}

	// The real HTTP door. A test-owned runner supplies $HTTP_RAW_POST_DATA the
	// way the web SAPI used to and then requires the shipped action.php from
	// the plugin directory, so its relative require of ../../php/xmlrpc.php
	// resolves as it does under the web server. The body cannot arrive on
	// stdin: php://input does not read the pipe under the CLI SAPI.
	public function actionCommand(array $hashes, $force, $name = 'action-door')
	{
		$path = $this->root.'/'.$name.'.php';
		@file_put_contents($path, "<?php\n"
			.'chdir('.var_export($this->pluginDir, true).");\n"
			.'$HTTP_RAW_POST_DATA = '
				.var_export($this->actionBody($hashes, $force), true).";\n"
			.'require('.var_export($this->pluginDir.'/action.php', true).");\n");
		return($this->php($path));
	}

	public function actionBody(array $hashes, $force)
	{
		$body = 'mode=removewithdata';
		foreach($hashes as $hash)
			$body .= '&hash='.$hash;
		$body .= '&v='.rawurlencode((string)$force);
		return($body);
	}

	private function writeAdapters()
	{
		$settings = var_export($this->settings, true);
		$user = var_export($this->user, true);
		$conf = var_export($this->pluginDir.'/conf.php', true);
		$util = "<?php\n"
			."// Test-owned adapter: everything below the plugin boundary.\n"
			."class FileUtil\n"
			."{\n"
			."\tpublic static function getSettingsPath() { return(".$settings."); }\n"
			."\tpublic static function getProfilePath() { return(dirname(".$settings.")); }\n"
			."\tpublic static function getConfFile(\$name) { return(false); }\n"
			."\tpublic static function makeDirectory(\$dir) { return(@mkdir(\$dir, 0777, true)); }\n"
			."\tpublic static function toLog(\$message)\n"
			."\t{\n"
			."\t\t@file_put_contents(".$settings.".'/plugin.log', \$message.\"\\n\",\n"
			."\t\t\tFILE_APPEND | LOCK_EX);\n"
			."\t}\n"
			."\tpublic static function getPluginConf(\$plugin)\n"
			."\t{\n"
			."\t\t\$bytes = @file_get_contents(".$conf.");\n"
			."\t\tif(!is_string(\$bytes))\n"
			."\t\t\treturn('');\n"
			."\t\treturn(preg_replace('/^\\s*<\\?php/', '', \$bytes));\n"
			."\t}\n"
			."}\n";
		@file_put_contents($this->root.'/php/util.php', $util);

		$xmlrpc = "<?php\n"
			."// Test-owned adapter: records every request and answers from a\n"
			."// scripted reply table. It never starts, acknowledges or stands in\n"
			."// for a child process of any kind.\n"
			."require_once(dirname(dirname(__FILE__)).'/php/util.php');\n"
			."class rXMLRPCCommand\n"
			."{\n"
			."\tpublic \$command; public \$params;\n"
			."\tpublic function __construct(\$command, \$params = null)\n"
			."\t{ \$this->command = \$command; \$this->params = \$params; }\n"
			."\tpublic function addParameter(\$value) { \$this->params[] = \$value; }\n"
			."}\n"
			."class rXMLRPCRequest\n"
			."{\n"
			."\tpublic \$val = array(); public \$fault = false;\n"
			."\tpublic \$faultString = ''; public \$rawFaultString = null;\n"
			."\tpublic \$faultCode = 0; public \$important = true;\n"
			."\tprivate \$commands = array();\n"
			."\tprivate static \$sequence = 0;\n"
			."\tpublic function __construct(\$commands = null)\n"
			."\t{\n"
			."\t\tif(is_array(\$commands)) \$this->commands = \$commands;\n"
			."\t\telse if(!is_null(\$commands)) \$this->commands = array(\$commands);\n"
			."\t}\n"
			."\tpublic function addCommand(\$command) { \$this->commands[] = \$command; }\n"
			."\tpublic function run(\$trusted = true)\n"
			."\t{\n"
			."\t\tif(!count(\$this->commands)) return(false);\n"
			// The aggregate mode is decided FIRST, before the scripted lookup
			// below. That order is the whole point: d.set_custom5 is the first
			// command of the aggregate erase and it has a scripted entry of its
			// own, so consulting the script first would answer the destructive
			// batch from the table and hide the daemon-side mode completely.
			."\t\tif(erasedataMirrorDaemonRoutes(\$this->commands))\n"
			."\t\t\treturn(\$this->runThroughDaemon());\n"
			."\t\t\$script = json_decode((string)@file_get_contents(\n"
			."\t\t\t".$settings.".'/rpc-script.json'), true);\n"
			."\t\t\$first = \$this->commands[0]->command;\n"
			."\t\t// The first scripted command anywhere in the request decides, so a\n"
			."\t\t// crash cut can be placed on d.erase inside an aggregate request.\n"
			."\t\tif(!is_array(\$script)) \$script = array();\n"
			."\t\tforeach(\$this->commands as \$candidate)\n"
			."\t\t\tif(array_key_exists(\$candidate->command, \$script))\n"
			."\t\t\t\t{ \$first = \$candidate->command; break; }\n"
			."\t\tif(!is_array(\$script) || !array_key_exists(\$first, \$script))\n"
			."\t\t{\n"
			."\t\t\t\$record = array();\n"
			."\t\t\tforeach(\$this->commands as \$command)\n"
			."\t\t\t\t\$record[] = array('command' => \$command->command,\n"
			."\t\t\t\t\t'params' => \$command->params);\n"
			."\t\t\t@file_put_contents(".$settings.".'/rpc.log',\n"
			."\t\t\t\tjson_encode(\$record).\"\\n\", FILE_APPEND | LOCK_EX);\n"
			."\t\t\treturn(false);\n"
			."\t\t}\n"
			."\t\t\$reply = \$script[\$first];\n"
			."\t\t// One scripted entry, many answers: 'byParam' maps the FIRST\n"
			."\t\t// PARAMETER of the matched command -- the hash, for every d.* and\n"
			."\t\t// f.* command the plugin sends -- to fields that override the\n"
			."\t\t// entry's own. Without it a batch shares one scripted base path,\n"
			."\t\t// and one member's payload deletion then stands in for every\n"
			."\t\t// member's, which makes a dropped obligation unobservable.\n"
			."\t\tif(isset(\$reply['byParam']) && is_array(\$reply['byParam']))\n"
			."\t\t{\n"
			."\t\t\t\$key = null;\n"
			."\t\t\tforeach(\$this->commands as \$candidate)\n"
			."\t\t\t\tif(\$candidate->command === \$first)\n"
			."\t\t\t\t{\n"
			."\t\t\t\t\t\$argv = is_array(\$candidate->params)\n"
			."\t\t\t\t\t\t? \$candidate->params : array(\$candidate->params);\n"
			."\t\t\t\t\t\$key = array_key_exists(0, \$argv) ? (string)\$argv[0] : null;\n"
			."\t\t\t\t\tbreak;\n"
			."\t\t\t\t}\n"
			."\t\t\t\$byParam = \$reply['byParam'];\n"
			."\t\t\tunset(\$reply['byParam']);\n"
			."\t\t\tif(\$key !== null && isset(\$byParam[\$key])\n"
			."\t\t\t\t&& is_array(\$byParam[\$key]))\n"
			."\t\t\t\t\$reply = \$byParam[\$key] + \$reply;\n"
			."\t\t}\n"
			."\t\tif(isset(\$reply['cut']) && is_int(\$reply['cut']))\n"
			."\t\t{\n"
			."\t\t\t\$seen = (int)@file_get_contents(".$settings.".'/cut-'.\$first);\n"
			."\t\t\t@file_put_contents(".$settings.".'/cut-'.\$first, (string)(\$seen + 1));\n"
			."\t\t\tif(\$seen + 1 >= \$reply['cut']) { \$reply['exit'] = 9; }\n"
			."\t\t}\n"
			."\t\t// The cut kills the client BEFORE the request is recorded: a\n"
			."\t\t// process that dies here never reached rTorrent with it.\n"
			// 'await' turns that death into an OBSERVED one. The client
			// announces that it has reached the cut and then waits to be
			// released, so a case can finish everything that must happen while
			// the producer is still alive -- reaping its scheduler children,
			// above all -- before the process actually dies. Without it the
			// death lands at an unknown moment after the acknowledgement, and a
			// tick that was blocked on a hash lock the producer held is freed
			// the instant it dies and can complete a whole lawful recovery
			// before the case gets to look. On one loaded host this test
			// failed in 5 of 8 runs; another run reproduced it in 1 of 12.
			// In each failure an unreaped tick had already cleared the journal.
			// The wait is bounded and its expiry is recorded, so a release
			// nobody sends is visible rather than silent.
			."\t\tif(isset(\$reply['exit']) && isset(\$reply['await'])\n"
			."\t\t\t&& is_string(\$reply['await']) && \$reply['await'] !== '')\n"
			."\t\t{\n"
			."\t\t\t\$gate = ".$settings.".'/'.\$reply['await'];\n"
			."\t\t\t@file_put_contents(\$gate.'.ready', \$first.\"\\n\",\n"
			."\t\t\t\tFILE_APPEND | LOCK_EX);\n"
			."\t\t\t\$until = microtime(true) + 60.0;\n"
			."\t\t\twhile(microtime(true) < \$until)\n"
			."\t\t\t{\n"
			."\t\t\t\tclearstatcache(true, \$gate);\n"
			."\t\t\t\tif(@file_exists(\$gate)) break;\n"
			."\t\t\t\tusleep(5000);\n"
			."\t\t\t}\n"
			."\t\t\tclearstatcache(true, \$gate);\n"
			."\t\t\tif(!@file_exists(\$gate))\n"
			."\t\t\t\t@file_put_contents(\$gate.'.expired', \$first.\"\\n\",\n"
			."\t\t\t\t\tFILE_APPEND | LOCK_EX);\n"
			."\t\t}\n"
			."\t\tif(isset(\$reply['exit']))\n"
			."\t\t{\n"
			."\t\t\t@file_put_contents(".$settings.".'/crashed', \$first.\"\\n\",\n"
			."\t\t\t\tFILE_APPEND | LOCK_EX);\n"
			."\t\t\texit((int)\$reply['exit']);\n"
			."\t\t}\n"
			."\t\t\$record = array();\n"
			."\t\tforeach(\$this->commands as \$command)\n"
			."\t\t\t\$record[] = array('command' => \$command->command,\n"
			."\t\t\t\t'params' => \$command->params);\n"
			."\t\t@file_put_contents(".$settings.".'/rpc.log',\n"
			."\t\t\tjson_encode(\$record).\"\\n\", FILE_APPEND | LOCK_EX);\n"
			."\t\t// 'delay' holds the CALLER inside this request for that many\n"
			."\t\t// microseconds, after the request is recorded and before the reply\n"
			."\t\t// is handed back. It is how a case makes two real children overlap\n"
			."\t\t// in time on purpose: without it a whole guarded pass is a handful\n"
			."\t\t// of local file operations and the second child usually finds the\n"
			."\t\t// first already finished, which proves nothing about the locks.\n"
			."\t\tif(isset(\$reply['delay']) && (int)\$reply['delay'] > 0)\n"
			."\t\t\tusleep((int)\$reply['delay']);\n"
			."\t\t\$this->val = isset(\$reply['val']) ? \$reply['val'] : array();\n"
			."\t\t\$this->fault = isset(\$reply['fault']) ? \$reply['fault'] : false;\n"
			."\t\t\$this->faultString = isset(\$reply['faultString'])\n"
			."\t\t\t? \$reply['faultString'] : '';\n"
			."\t\t\$this->faultCode = isset(\$reply['faultCode']) ? \$reply['faultCode'] : 0;\n"
			."\t\treturn(isset(\$reply['ok']) ? (bool)\$reply['ok'] : true);\n"
			."\t}\n"
			."\tpublic function success(\$trusted = true)\n"
			."\t{ return(\$this->run(\$trusted) && !\$this->fault); }\n"
			// One request handed to the separate daemon fixture, and the caller
			// held here until that process answers or the transport closes.
			//
			// The transport is modelled on what the production stack really
			// does, not on what is convenient: a daemon that stops mid-batch
			// writes NO reply, this returns false, and rXMLRPCRequest::run()
			// false is what erasedataClassifyEraseOutcomes() reads as "no
			// per-command position at all -- every member UNKNOWN". Production
			// agrees on that composite: rSCGITransport::readResponse() answers
			// null for a body that never finished arriving, and
			// rXMLRPCRequest::send() turns null into false, so the truncated
			// answer never reaches the parser as a prefix of successful values.
			."\tprivate function runThroughDaemon()\n"
			."\t{\n"
			."\t\t\$dir = erasedataMirrorDaemonDir();\n"
			."\t\t\$record = array();\n"
			."\t\tforeach(\$this->commands as \$command)\n"
			."\t\t\t\$record[] = array('command' => \$command->command,\n"
			."\t\t\t\t'params' => \$command->params);\n"
			// A routed request stays in the ordinary transcript: what a client
			// SENT and what the daemon EXECUTED are two different counts, and a
			// case has to be able to compare them.
			."\t\t@file_put_contents(".$settings.".'/rpc.log',\n"
			."\t\t\tjson_encode(\$record).\"\\n\", FILE_APPEND | LOCK_EX);\n"
			."\t\tself::\$sequence++;\n"
			."\t\t\$id = getmypid().'-'.self::\$sequence;\n"
			."\t\t\$state = json_decode((string)@file_get_contents(\n"
			."\t\t\t".$settings.".'/erasedata/.drain-state'), true);\n"
			."\t\t\$envelope = array('id' => \$id, 'client_pid' => getmypid(),\n"
			."\t\t\t'generation' => is_array(\$state) && isset(\$state['generation'])\n"
			."\t\t\t\t? \$state['generation'] : null, 'commands' => \$record);\n"
			."\t\t\$tmp = \$dir.'/req/'.\$id.'.json.tmp';\n"
			."\t\tif(@file_put_contents(\$tmp, json_encode(\$envelope)) === false\n"
			."\t\t\t|| !@rename(\$tmp, \$dir.'/req/'.\$id.'.json'))\n"
			."\t\t\treturn(false);\n"
			."\t\t\$reply = null;\n"
			."\t\t\$deadline = microtime(true) + 25.0;\n"
			."\t\twhile(microtime(true) < \$deadline)\n"
			."\t\t{\n"
			."\t\t\t\$reply = self::readReply(\$dir, \$id);\n"
			."\t\t\tif(\$reply !== null) break;\n"
			// The answer is looked for BEFORE the peer, every pass: a daemon
			// that wrote its reply and then exited has answered.
			."\t\t\tclearstatcache(true, \$dir.'/alive');\n"
			."\t\t\tif(!@file_exists(\$dir.'/alive'))\n"
			."\t\t\t\t{ \$reply = self::readReply(\$dir, \$id); break; }\n"
			."\t\t\tusleep(5000);\n"
			."\t\t}\n"
			."\t\tif(!is_array(\$reply)) return(false);\n"
			."\t\t\$this->val = isset(\$reply['val']) && is_array(\$reply['val'])\n"
			."\t\t\t? \$reply['val'] : array();\n"
			."\t\t\$this->fault = isset(\$reply['fault']) ? (bool)\$reply['fault'] : false;\n"
			."\t\t\$this->faultString = isset(\$reply['faultString'])\n"
			."\t\t\t? \$reply['faultString'] : '';\n"
			."\t\t\$this->rawFaultString = \$this->fault ? \$this->faultString : null;\n"
			."\t\t\$this->faultCode = isset(\$reply['faultCode'])\n"
			."\t\t\t? (int)\$reply['faultCode'] : 0;\n"
			."\t\treturn(isset(\$reply['ok']) ? (bool)\$reply['ok'] : true);\n"
			."\t}\n"
			."\tprivate static function readReply(\$dir, \$id)\n"
			."\t{\n"
			."\t\tclearstatcache(true, \$dir.'/rep/'.\$id.'.json');\n"
			."\t\t\$raw = @file_get_contents(\$dir.'/rep/'.\$id.'.json');\n"
			."\t\tif(!is_string(\$raw) || \$raw === '') return(null);\n"
			."\t\t\$decoded = json_decode(\$raw, true);\n"
			."\t\treturn(is_array(\$decoded) ? \$decoded : null);\n"
			."\t}\n"
			."}\n"
			."function erasedataMirrorDaemonDir() { return(".$settings.".'/daemon'); }\n"
			// The commands a daemon presence table can answer, and the whole
			// routing rule: a request is the daemon's only when EVERY command in
			// it is one of these. Everything else -- schedule, schedule_remove,
			// the collector's d.multicall -- stays with the scripted table.
			."function erasedataMirrorDaemonRoutes(array \$commands)\n"
			."{\n"
			."\t\$owned = array('d.hash' => true, 'd.get_base_path' => true,\n"
			."\t\t'd.is_multi_file' => true, 'f.multicall' => true,\n"
			."\t\t'd.get_directory' => true, 'd.set_custom5' => true,\n"
			."\t\t'd.delete_tied' => true, 'd.erase' => true);\n"
			."\tif(!count(\$commands)) return(false);\n"
			."\tforeach(\$commands as \$command)\n"
			."\t\tif(!isset(\$owned[(string)\$command->command])) return(false);\n"
			."\treturn(@is_file(erasedataMirrorDaemonDir().'/config.json'));\n"
			."}\n"
			."function getCmd(\$command) { return(\$command); }\n"
			."class Utility { public static function getPHP() { return(PHP_BINARY); } }\n"
			."class User { public static function getUser() { return(".$user."); } }\n"
			."class CachedEcho\n"
			."{\n"
			."\tpublic static function send(\$content, \$type = 'text/html') { echo \$content; }\n"
			."}\n"
			."class JSON\n"
			."{\n"
			."\tpublic static function safeEncode(\$value) { return(json_encode(\$value)); }\n"
			."}\n"
			."class rTorrentSettings\n"
			."{\n"
			."\tprivate static \$instance = null;\n"
			."\tpublic static function get()\n"
			."\t{\n"
			."\t\tif(self::\$instance === null) self::\$instance = new self();\n"
			."\t\treturn(self::\$instance);\n"
			."\t}\n"
			."\tpublic function getCommand(\$command) { return(\$command); }\n"
			."\tpublic function registerPlugin(\$name, \$perms) {}\n"
			."\tpublic function getAlignedScheduleCommand(\$name, \$interval, \$command)\n"
			."\t{\n"
			."\t\treturn(new rXMLRPCCommand(getCmd('schedule'), array(\$name.User::getUser(),\n"
			."\t\t\t'0', (string)\$interval, \$command)));\n"
			."\t}\n"
			."\tpublic function getRemoveScheduleCommand(\$name)\n"
			."\t{\n"
			."\t\treturn(new rXMLRPCCommand(getCmd('schedule_remove'), \$name.User::getUser()));\n"
			."\t}\n"
			// Copied from rTorrentSettings::getAlignedStart() in
			// php/settings.php (reflowed onto fewer lines, but identical
			// arithmetic). The child runs the shipped plugin bytes, so it
			// needs the core method those bytes call -- and it needs the REAL
			// arithmetic, or a case about alignment would be measuring the
			// adapter instead of the plugin.
			."\tstatic public function getAlignedStart(\$name, \$interval, \$now = null)\n"
			."\t{\n"
			."\t\tglobal \$schedule_rand;\n"
			."\t\tif(!isset(\$schedule_rand)) \$schedule_rand = 10;\n"
			."\t\tif(\$interval < 1) return(0);\n"
			."\t\tif(is_null(\$now)) \$now = time();\n"
			."\t\t\$offset = (\$schedule_rand > 0)\n"
			."\t\t\t? (abs(crc32(\$name.User::getUser())) % (\$schedule_rand + 1)) : 0;\n"
			."\t\t\$startAt = ((\$offset - \$now) % \$interval + \$interval) % \$interval;\n"
			."\t\treturn(\$startAt < 1 ? \$interval : \$startAt);\n"
			."\t}\n"
			."}\n";
		@file_put_contents($this->root.'/php/xmlrpc.php', $xmlrpc);

		// Metainfo lookup is out of this package's scope; the plugin only
		// requires the file when a collector job needs a successor torrent.
		@file_put_contents($this->root.'/php/rtorrent.php', "<?php\n"
			."class rTorrent { public static function getSource(\$hash) { return(false); } }\n");
	}
}

// A process that holds one flock for a bounded time and says so on stdout, so a
// test can prove what a real competitor does to a real lock.
function erasedataTestLockHolderCommand($path, $seconds, $exclusive = true)
{
	$mode = $exclusive ? 'LOCK_EX' : 'LOCK_SH';
	return('exec '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg(
		'$f = @fopen('.var_export($path, true).', "c");'
		.' if($f === false) { echo "nolock\n"; exit(1); }'
		.' if(!flock($f, '.$mode.')) { echo "nolock\n"; exit(1); }'
		.' echo "held\n"; @flush();'
		.' usleep((int)('.((float)$seconds).' * 1000000));'
		.' flock($f, LOCK_UN); fclose($f);'));
}

// A process that TRIES one flock without blocking and reports what it found, so
// a case can prove contention with a REAL second process instead of asserting
// from inside the holder that it is holding something. It prints exactly one of
// 'busy', 'free' or 'unopenable' and exits.
function erasedataTestLockProbeCommand($path)
{
	return('exec '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg(
		'$f = @fopen('.var_export($path, true).', "c");'
		.' if($f === false) { echo "unopenable\n"; exit(1); }'
		.' if(@flock($f, LOCK_EX | LOCK_NB)) { echo "free\n"; @flock($f, LOCK_UN); }'
		.' else echo "busy\n";'
		.' @fclose($f);'));
}

// Every open descriptor of $pid that names the same device and inode as $path,
// read the way ErasedataFilesystemOps::descriptorsNamingIdentity() reads its
// own: by stat of the /proc entry, not by its symlink text. It is how a parent
// proves a child really HOLDS a directory capability rather than merely having
// been passed a force value. /proc is not an extra requirement: production's
// erasedataDescriptorCandidates() already needs /proc/self/fd (or /dev/fd) for
// a force-2 capability to be acquirable at all.
function erasedataTestDescriptorsNaming($pid, $path)
{
	$root = '/proc/'.(int)$pid.'/fd';
	clearstatcache(true, $path);
	$target = @stat($path);
	if(!is_array($target) || !@is_dir($root))
		return(false);
	$entries = @scandir($root);
	if(!is_array($entries))
		return(false);
	$naming = array();
	foreach($entries as $entry)
	{
		if(!ctype_digit($entry))
			continue;
		clearstatcache(true, $root.'/'.$entry);
		$stat = @stat($root.'/'.$entry);
		if(is_array($stat) && (string)$stat['dev'] === (string)$target['dev']
			&& (string)$stat['ino'] === (string)$target['ino'])
			$naming[] = $entry;
	}
	return($naming);
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
