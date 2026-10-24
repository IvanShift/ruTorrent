<?php

// ErasedataManifestCodec owns the bounded reads and the canonical-base64 rule
// this file reuses for the captured-entry name record.
require_once(dirname(__FILE__)."/manifest.php");

// Bound of ErasedataFilesystemOps::renamedEntryIdentity(): ten lookups with
// nine 50 ms pauses, at most 0.45 s of waiting for one renamed name.
if(!defined('ERASEDATA_RENAME_SETTLE_ATTEMPTS'))
	define('ERASEDATA_RENAME_SETTLE_ATTEMPTS', 10);
if(!defined('ERASEDATA_RENAME_SETTLE_PAUSE_US'))
	define('ERASEDATA_RENAME_SETTLE_PAUSE_US', 50000);

// The seam for the filesystem mutations and identity reads the erasedata
// collector performs, and the one owner of the no-replace helper, its parent
// preflight and the payload trust rule that datadir and every AutoTools file
// operation (Move, Copy, HardLink, SoftLink) share.
// Only operations that must be scriptable in the destructive-race tests live
// here; pure validation and stable read-only PHP calls stay inline in the
// collector.
class ErasedataFilesystemOps
{
	private static $deletionLease = null;

	// The worker owns this already-attested open file description. Every native
	// helper must inherit it so PHP or daemon death cannot admit a new load while
	// a surviving helper can still rename, unlink or restore the old payload.
	public static function holdDeletionLease($lease)
	{
		if($lease === null)
		{
			self::$deletionLease = null;
			return(true);
		}
		$stat = is_resource($lease) ? @fstat($lease) : false;
		if(!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000)
			return(false);
		self::$deletionLease = $lease;
		return(true);
	}
	// Exit status ABI of rutorrent-erasedata-rename-noreplace, the helper that
	// tools/native-helpers.list declares. This is the table's one definition:
	// rename-noreplace.c names the same statuses, rename-noreplace-test.sh and
	// QUEUE-ATTESTATION.md use them, and RenameHelperStatusTest pins all three
	// to these values. 'probe-success' is success for --probe only: the
	// filesystem is usable through the helper's caller-locked fallback.
	const RENAME_HELPER_STATUS = array(
		0 => array('ok', 'success'),
		1 => array('rename-noreplace-collision', 'refusal'),
		2 => array('rename-noreplace-invalid-arguments', 'refusal'),
		3 => array('rename-noreplace-unsupported', 'refusal'),
		4 => array('rename-noreplace-error', 'refusal'),
		5 => array('rename-noreplace-fallback', 'probe-success'),
	);

	private static $renameRefusal = '';
	private static $renameContext = '';
	private static $renameProbe = false;
	private static $renameErrno = null;
	private static $payloadRefusalPath = '';

	public static function lastPayloadRefusalPath()
	{
		return(self::$payloadRefusalPath);
	}

	private static function payloadPathRefused($path)
	{
		self::$payloadRefusalPath = is_string($path) ? $path : '';
		return(false);
	}

	// EXDEV may occur between bind mounts with equal st_dev. Only the native
	// helper's classified result permits a consumer to switch to staged copy.
	public static function isCrossDeviceRenameRefusal()
	{
		return(self::$renameRefusal === 'rename-noreplace-error'
			&& !self::$renameProbe && self::$renameErrno === 18);
	}

	public static function lastRenameRefusal()
	{
		return(self::$renameRefusal);
	}

	public static function lastRenameRefusalContext()
	{
		return(self::$renameContext);
	}

	public static function clearRenameRefusal()
	{
		self::$renameRefusal = '';
		self::$renameContext = '';
		self::$renameProbe = false;
		self::$renameErrno = null;
	}

	// Callers resolve the helper through static:: so a test subclass can point
	// the real process runner at a scripted helper; production has one path.
	public static function renameHelperPath()
	{
		$metadata = dirname(__FILE__).'/../../php/nativehelpers.php';
		if(!is_file($metadata))
		{
			self::$renameRefusal = 'rename-noreplace-manifest-unavailable';
			return(false);
		}
		try
		{
			require_once($metadata);
			return(NativeHelpers::path('rutorrent-erasedata-rename-noreplace'));
		}
		catch(Throwable $error)
		{
			self::$renameRefusal = 'rename-noreplace-manifest-invalid';
			return(false);
		}
	}

	private static function renameDiagnostic($reason, $from, $to = null, $errno = null)
	{
		self::$renameRefusal = $reason;
		self::$renameErrno = $errno;
		// A context without a destination belongs to a directory probe.
		self::$renameProbe = $to === null;
		self::$renameContext = $reason.' path='.erasedataLogPath($from)
			.($to === null ? '' : ' destination='.erasedataLogPath($to))
			.($errno === null ? '' : ' errno='.$errno);
		// Callers own the durable report memory. A retrying filesystem seam must
		// not emit an unconditional line on every five-second drain tick.
		return(false);
	}

	// The pending classified refusal with its operator action, for the
	// caller's own log line under its own component name; '' when none.
	public static function renameRefusalReport()
	{
		if(self::$renameContext === '')
			return('');
		if(self::$renameRefusal === 'rename-noreplace-collision')
			$action = 'preserve both entries; inspect the occupied destination before retrying';
		// Since the locked fallback, status 3 no longer means a missing
		// RENAME_NOREPLACE flag. Plain renameat itself refused (EINVAL for a
		// directory moved into its own subtree, ENOSYS, EOPNOTSUPP), or the
		// probe's self-check saw a rename replace an existing entry.
		elseif(self::$renameRefusal === 'rename-noreplace-unsupported')
			$action = (self::$renameProbe
				? 'the filesystem refused rename or replaced an entry in the probe self-check; keep this path on a filesystem with working rename'
				: 'the filesystem refused this rename itself, for example a directory moved into its own subtree; inspect source and destination')
				.'; torrent and payload retained';
		else
			$action = 'check the native helper and the named parent permissions; torrent and payload retained';
		return(self::$renameContext.'; mutation refused; '.$action);
	}

	public static function reportRenameRefusal($component = 'erasedata')
	{
		$report = self::renameRefusalReport();
		if($report !== '' && method_exists('FileUtil', 'toLog'))
			FileUtil::toLog($component.': '.$report);
	}

	private static function runRenameHelper(array $arguments, $from, $to = null)
	{
		if(!function_exists('proc_open'))
			return(self::renameDiagnostic('rename-noreplace-process-unavailable', $from, $to));
		$descriptors = array(0 => array('file', '/dev/null', 'r'),
			1 => array('file', '/dev/null', 'w'), 2 => array('pipe', 'w'));
		if(self::$deletionLease !== null)
		{
			if(!is_resource(self::$deletionLease))
				return(self::renameDiagnostic('rename-noreplace-lease-unavailable', $from, $to));
			// proc_open duplicates the resource's OFD; reopening /proc/self/fd
			// would create a different description and lose the flock lifetime.
			$descriptors[198] = self::$deletionLease;
		}
		$held = array();
		try
		{
			foreach($arguments as $index => $argument)
			{
				if(!preg_match('~^(/proc/self/fd|/dev/fd)/([0-9]+)(/.*)?$~D', $argument, $match))
					continue;
				$number = $match[2];
				if(!isset($held[$number]))
				{
					// Open our own descriptor alias and explicitly inherit the
					// directory resource. Non-dumpable FPM workers cannot expose
					// their /proc/<parent-pid>/fd entries to an exec child.
					$alias = $match[1].'/'.$number;
					$handle = @fopen($alias, 'r');
					$stat = is_resource($handle) ? @fstat($handle) : false;
					if(!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000)
					{
						if(is_resource($handle)) fclose($handle);
						return(self::renameDiagnostic('rename-noreplace-descriptor-unavailable', $from, $to));
					}
					$childNumber = 3 + count($held);
					$held[$number] = array('handle' => $handle, 'child' => $childNumber);
					$descriptors[$childNumber] = $handle;
				}
				$arguments[$index] = '/proc/self/fd/'.$held[$number]['child']
					.(isset($match[3]) ? $match[3] : '');
			}
			$process = @proc_open($arguments, $descriptors, $pipes);
			if(!is_resource($process))
				return(self::renameDiagnostic('rename-noreplace-process-unavailable', $from, $to));
			// Status distinguishes the trusted helper's fixed refusal classes.
			$error = stream_get_contents($pipes[2], 1024);
			fclose($pipes[2]);
			$code = proc_close($process);
		}
		finally
		{
			foreach($held as $resource) fclose($resource['handle']);
		}
		$table = self::RENAME_HELPER_STATUS;
		$status = isset($table[$code]) ? $table[$code] : null;
		$probe = isset($arguments[1]) && $arguments[1] === '--probe';
		if($status !== null && ($status[1] === 'success' || ($status[1] === 'probe-success' && $probe)))
		{
			self::clearRenameRefusal();
			return(true);
		}
		$errno = preg_match('/\berrno=([0-9]{1,5})\b/', $error, $match) ? (int)$match[1] : null;
		return(self::renameDiagnostic($status !== null && $status[1] === 'refusal' ? $status[0]
			: 'rename-noreplace-process-exit-'.$code, $from, $to, $errno));
	}

	public static function canRenameNoReplace($directory = null, $reportRefusal = true)
	{
		$result = self::renameCapabilityQuery($directory);
		if(!$result && $reportRefusal) self::reportRenameRefusal();
		return($result);
	}

	private static function renameCapabilityQuery($directory)
	{
		$helper = static::renameHelperPath();
		// A presence query without a directory names the helper it checked.
		if($helper === false || !is_executable($helper))
			return(self::renameDiagnostic($helper === false ? self::$renameRefusal
				: 'rename-noreplace-helper-unavailable',
				$directory !== null ? $directory : ($helper === false ? '' : $helper)));
		// Presence is the compatibility query. Callers about to mutate must
		// probe each actual parent, not infer support from another mount.
		if($directory === null)
		{
			self::clearRenameRefusal();
			return(true);
		}
		if(!ErasedataManifestCodec::isValidAbsolutePath($directory))
			return(self::renameDiagnostic('rename-noreplace-invalid-parent', $directory));
		$physical = @realpath($directory);
		if($physical === false || !is_dir($physical))
			return(self::renameDiagnostic('rename-noreplace-parent-unresolved', $directory));
		// A final /. lets the native probe open the inherited directory itself,
		// while retaining O_NOFOLLOW on its final component.
		$probePath = preg_match('~^(/proc/self/fd|/dev/fd)/[0-9]+$~D', $directory)
			? $directory.'/.' : $physical;
		return(self::runRenameHelper(array($helper, '--probe', $probePath), $physical));
	}

	public function entryIdentity($path)
	{
		clearstatcache(true, $path);
		$stat = @lstat($path);
		if(!is_array($stat))
			return(false);
		$typeBits = $stat['mode'] & 0170000;
		$isLink = $typeBits === 0120000;
		$isDir = $typeBits === 0040000;
		$isFile = $typeBits === 0100000;
		return(array(
			'exists' => true,
			'type' => $isLink ? 'link' : ($isDir ? 'directory' : ($isFile ? 'file' : 'other')),
			'is_link' => $isLink,
			'is_dir' => $isDir,
			'is_file' => $isFile,
			'dev' => $stat['dev'],
			'ino' => $stat['ino'],
			'mode' => $stat['mode'],
			'nlink' => $stat['nlink'],
			'size' => $stat['size'],
			'mtime' => $stat['mtime'],
			'lstat' => $stat,
		));
	}

	// The identity of a name this process has just renamed into place. On
	// drvfs over 9p with cache=0x5 (the owner's Docker Desktop volumes,
	// 2026-10-11) lstat of the new name once answered ENOENT immediately after
	// a successful rename, although the entry existed and was unlinked a moment
	// later. Only a negative answer is asked again, within a fixed bound; the
	// first present answer is returned as it is, so the caller's exact
	// identity check still refuses a different inode at once. The rename itself
	// is never repeated. False after the bound means no lookup confirmed the
	// name; absence and an unreadable name remain indistinguishable here.
	public function renamedEntryIdentity($path)
	{
		for($attempt = 1; ; $attempt++)
		{
			$identity = $this->entryIdentity($path);
			if($identity !== false || $attempt >= ERASEDATA_RENAME_SETTLE_ATTEMPTS)
				return($identity);
			usleep(ERASEDATA_RENAME_SETTLE_PAUSE_US);
		}
	}

	// Directory captures also need their resolved target identity. Settle only
	// the new entry's absence first, then retain pathIdentity()'s existing
	// resolution checks and bind that answer to the first present entry.
	public function renamedPathIdentity($path)
	{
		$entry = $this->renamedEntryIdentity($path);
		if(!is_array($entry))
			return(false);
		$identity = $this->pathIdentity($path);
		return(is_array($identity) && !empty($identity['exists'])
			&& erasedataIdentityDeviceAndInode($entry) === erasedataIdentityDeviceAndInode($identity['lstat'])
			? $identity : false);
	}

	public function targetIdentity($path)
	{
		clearstatcache(true, $path);
		$stat = @stat($path);
		if(!is_array($stat))
			return(false);
		$typeBits = $stat['mode'] & 0170000;
		return(array(
			'exists' => true,
			'type' => $typeBits === 0040000 ? 'directory'
				: ($typeBits === 0100000 ? 'file' : 'other'),
			'is_link' => false,
			'is_dir' => $typeBits === 0040000,
			'is_file' => $typeBits === 0100000,
			'dev' => $stat['dev'],
			'ino' => $stat['ino'],
			'mode' => $stat['mode'],
			'nlink' => $stat['nlink'],
			'size' => $stat['size'],
			'mtime' => $stat['mtime'],
			'stat' => $stat,
		));
	}

	protected function renameCapability($directory)
	{
		return(self::canRenameNoReplace($directory, false));
	}

	public function probeRenameParent($directory)
	{
		return($this->renameCapability($directory));
	}

	// The one actual-parent preflight of every no-replace consumer: erasedata
	// admission, cleanup and drain, datadir and AutoTools. Each directory will
	// gain or lose an entry through renameNoReplace(). One that does not exist
	// yet is created later on its deepest existing ancestor, which is the
	// filesystem to probe; an existing non-directory in that walk refuses.
	// With $root, every resolved directory must lie within the root resolved
	// by the same rule, so a link inside a payload tree cannot point the probe
	// elsewhere. Bind aliases may carry different mount permissions, so a
	// probe answer is reused only for the same resolved path and physical
	// identity, through the caller's $probed within one operation. A refusal
	// leaves its classified reason in lastRenameRefusal() and
	// lastRenameRefusalContext() and logs nothing: the caller reports it.
	public function preflightParents(array $directories, array &$probed, $root = null)
	{
		self::clearRenameRefusal();
		$within = $root === null ? null : $this->actualParent($root);
		if($within === false)
			return(false);
		foreach($directories as $directory)
		{
			$physical = $this->actualParent($directory);
			if($physical === false)
				return(false);
			if($within !== null && $physical !== $within
				&& strpos($physical, rtrim($within, '/').'/') !== 0)
				return(self::renameDiagnostic('rename-noreplace-parent-outside-root', $directory));
			$key = erasedataIdentityDeviceAndInode($this->targetIdentity($physical));
			if($key === false)
				return(self::renameDiagnostic('rename-noreplace-parent-unresolved', $directory));
			$key = $physical.'|'.$key['dev'].':'.$key['ino'];
			if(isset($probed[$key]))
				continue;
			if(!$this->probeRenameParent($physical))
				return(false);
			$probed[$key] = true;
		}
		return(true);
	}

	// The one trust rule for the payload directories and files that datadir
	// and AutoTools (Move, Copy, HardLink, SoftLink) capture from or publish
	// into (R3-OWN-01, 2026-10-08).
	// Threat model: processes sharing the service UID are trusted (AGENTS.md),
	// and the daemon already writes every download below the configured top
	// directory, so that subtree's permissions are already a trust boundary
	// for all torrents. WSL2 drvfs and Docker Desktop mounts show their entries
	// with one uniform owner and mode 0777, and prod b79a16d5 moved into such
	// folders. At and below the top, every component must therefore be owned
	// by root or the service UID, and group/other write is accepted there.
	// Above the top the 2026-09-28 rule stays: no group/other write without a
	// sticky bit. What does not depend on modes holds everywhere: the path must
	// already be physical (no symlink, '.' or '..'), every component is read
	// with lstat, and a component of another owner refuses. Without a
	// resolvable top the strict rule applies to every component; a top that
	// resolves to / (the conf/config.php default outside Docker) counts as
	// none, because the daemon does not write every download below it.
	public static function payloadDirectoryTrusted($path, $top = null)
	{
		return(self::directoryChainTrusted($path, self::payloadTop($top)));
	}

	// The strict form of the same rule, without a relaxed region: every
	// component is owned by root or the service UID and is not group/other
	// writable unless sticky. datadir's own state (its profile and job
	// journal) uses it (R4-FS-09).
	public static function stateDirectoryTrusted($path)
	{
		return(self::directoryChainTrusted($path, false));
	}

	private static function directoryChainTrusted($path, $boundary)
	{
		if(!is_string($path) || $path === '' || $path[0] !== '/'
			|| @realpath($path) !== $path || !function_exists('posix_geteuid'))
			return(false);
		$current = '';
		foreach(array_merge(array(''), array_filter(explode('/', $path), 'strlen')) as $part)
		{
			$current = rtrim($current, '/').'/'.$part;
			if(!self::payloadEntryTrusted($current, 0040000, $boundary))
				return(false);
		}
		return(true);
	}

	// A payload file under the same rule; its directories are checked apart.
	public static function payloadFileTrusted($path, $top = null)
	{
		return(is_string($path) && $path !== '' && $path[0] === '/'
			&& function_exists('posix_geteuid')
			&& self::payloadEntryTrusted($path, 0100000, self::payloadTop($top)));
	}

	// The same rule for a directory as a configuration or the daemon spells
	// it, links included (R4-FS-03, 2026-10-08). The spelling is walked the way
	// the kernel resolves it: every directory it passes is checked at its
	// physical place, and every link in it, or in a link's own target, must be
	// owned by root or the service UID, like every other component. So a
	// writable directory above the top refuses even when a link in it leads
	// somewhere trusted, and below the top, where a writable directory is
	// accepted, a link of another owner cannot send the operation elsewhere.
	// '.' and '..' refuse in the spelling and are resolved in a link target.
	// With $missingTail the spelled path may end in names that do not exist
	// yet: the caller creates them under the checked prefix and then checks
	// the whole path again. A name a link leads to must exist.
	public static function payloadPathTrusted($path, $top = null, $missingTail = false)
	{
		self::$payloadRefusalPath = '';
		if(!is_string($path) || $path === '' || $path[0] !== '/' || strpos($path, "\0") !== false
			|| !function_exists('posix_geteuid'))
			return(self::payloadPathRefused($path));
		$pending = array();
		foreach(explode('/', $path) as $component)
		{
			if($component === '.' || $component === '..')
				return(self::payloadPathRefused($path));
			if($component !== '')
				$pending[] = array($component, false);
		}
		$boundary = self::payloadTop($top);
		if(!self::payloadEntryTrusted('/', 0040000, $boundary))
			return(self::payloadPathRefused('/'));
		$current = '/';
		$links = 0;
		while($pending)
		{
			list($component, $fromLink) = array_shift($pending);
			if($component === '.')
				continue;
			if($component === '..')
			{
				$current = dirname($current);
				continue;
			}
			$next = rtrim($current, '/').'/'.$component;
			clearstatcache(true, $next);
			$stat = @lstat($next);
			if(!is_array($stat))
				return($missingTail && !$fromLink ? true : self::payloadPathRefused($next));
			if(($stat['mode'] & 0170000) === 0120000)
			{
				$target = @readlink($next);
				// 40 is Linux's limit on links followed in one resolution.
				if(($stat['uid'] !== 0 && $stat['uid'] !== posix_geteuid())
					|| !is_string($target) || $target === '' || ++$links > 40)
					return(self::payloadPathRefused($next));
				if($target[0] === '/')
					$current = '/';
				$expansion = array();
				foreach(explode('/', $target) as $part)
					if($part !== '')
						$expansion[] = array($part, true);
				$pending = array_merge($expansion, $pending);
				continue;
			}
			if(!self::payloadEntryTrusted($next, 0040000, $boundary))
				return(self::payloadPathRefused($next));
			$current = $next;
		}
		return(true);
	}

	private static function payloadTop($top)
	{
		if($top === null)
			$top = isset($GLOBALS['topDirectory']) ? $GLOBALS['topDirectory'] : null;
		if(!is_string($top) || $top === '' || $top[0] !== '/')
			return(false);
		$physical = @realpath(rtrim($top, '/') === '' ? '/' : rtrim($top, '/'));
		return($physical !== false && $physical !== '/' && is_dir($physical) ? $physical : false);
	}

	private static function payloadEntryTrusted($path, $type, $boundary)
	{
		clearstatcache(true, $path);
		$stat = @lstat($path);
		if(!is_array($stat) || ($stat['mode'] & 0170000) !== $type
			|| ($stat['uid'] !== 0 && $stat['uid'] !== posix_geteuid()))
			return(false);
		$withinTop = $boundary !== false && ($path === $boundary
			|| strpos($path, rtrim($boundary, '/').'/') === 0);
		return($withinTop || ($stat['mode'] & 0022) === 0
			|| ($type === 0040000 && ($stat['mode'] & 01000) !== 0));
	}

	// The physical directory a rename in $directory happens on: the directory
	// itself or, for one created later, its deepest existing ancestor.
	private function actualParent($directory)
	{
		if(!ErasedataManifestCodec::isValidAbsolutePath($directory))
			return(self::renameDiagnostic('rename-noreplace-invalid-parent',
				is_string($directory) ? $directory : ''));
		while(!is_dir($directory))
		{
			if(erasedataPathExists($directory) || dirname($directory) === $directory)
				return(self::renameDiagnostic('rename-noreplace-parent-unresolved', $directory));
			$directory = dirname($directory);
		}
		$physical = @realpath($directory);
		return($physical === false
			? self::renameDiagnostic('rename-noreplace-parent-unresolved', $directory) : $physical);
	}

	private function privateCaptureParent($directory)
	{
		$uid = erasedataEffectiveUid();
		for($path = $directory; is_string($path) && $path !== dirname($path); $path = dirname($path))
		{
			if(!preg_match('/^\.erasedata-(?:entry|rmdir|recovery)-/', basename($path))) continue;
			$root = @lstat($path);
			$marker = @lstat(erasedataPrivateMarkerPath($path));
			if(is_array($root) && ($root['mode'] & 0170000) === 0040000
				&& ($root['mode'] & 07777) === 0700 && $root['uid'] === $uid
				&& is_array($marker) && ($marker['mode'] & 0170000) === 0100000
				&& ($marker['mode'] & 07777) === 0600 && $marker['uid'] === $uid && $marker['nlink'] === 1)
				return(true);
		}
		return(false);
	}

	public function rename($from, $to)
	{
		self::clearRenameRefusal();
		// Publication is inside an admitted private queue under the hash lock;
		// it has no public-name rollback and needs only ordinary rename.
		$publication = dirname($from) === dirname($to)
			&& substr($from, -4) === '.tmp' && $to === substr($from, 0, -4).'.list';
		$parent = @realpath(dirname($from));
		$privateCapture = $this->privateCaptureParent($parent);
		// A capture may need no-replace restore of its PUBLIC source name. Never
		// probe the private destination root: crash debris there blocks recovery.
		if(!$publication && !$privateCapture && !$this->renameCapability(dirname($from)))
			return(false);
		// In a sticky public parent, another UID may still rename its own
		// source. Do not capture such an entry into a private root.
		if((strpos($to, '/.erasedata-rmdir-') !== false
			|| strpos($to, '/.erasedata-entry-') !== false)
			&& !erasedataCaptureSourceOwned($from))
			return(self::renameDiagnostic('capture-source-owner', $from, $to));
		return(@rename($from, $to) || self::renameDiagnostic('rename-refused', $from, $to));
	}

	// PHP rename() may replace $to. This helper shares the no-replace syscall
	// and its caller-locked unsupported-flags fallback across every consumer.
	public function renameNoReplace($from, $to)
	{
		self::clearRenameRefusal();
		$helper = static::renameHelperPath();
		if($helper === false || !is_executable($helper))
			return(self::renameDiagnostic($helper === false ? self::$renameRefusal
				: 'rename-noreplace-helper-unavailable', $from, $to));
		return(self::runRenameHelper(array($helper, $from, $to), $from, $to));
	}

	public function unlink($path)
	{
		return(@unlink($path));
	}

	public function makeDirectory($path, $mode)
	{
		return(@mkdir($path, $mode));
	}

	public function removeDirectory($path)
	{
		return(@rmdir($path));
	}

	public function makeSymlink($target, $path)
	{
		return(@symlink($target, $path));
	}

	public function readLink($path)
	{
		return(@readlink($path));
	}

	public function scanDirectory($path)
	{
		return(@scandir($path));
	}

	// The canonical name a path that does not exist yet WOULD have.
	//
	// The core XMLRPCPathResolver answers the same question for the core
	// endpoints with its own deepestExistingAncestor(), but that one
	// reconstructs the missing tail verbatim, so
	// "<existing>/missing/../escape" comes back as a name that resolves outside
	// the ancestor it was canonicalised against. That resolver is shared with
	// paths this plugin does not own and is out of this package's scope, so the
	// stricter rule lives here. pathIdentity() below is its one caller, and
	// pathIdentity() is what every erasedata decision reads:
	//
	//   - the path must be absolute, NUL-free and within the path ceiling;
	//   - every component is normalised BEFORE anything is resolved, and '.',
	//     '..' and an empty component are refused outright rather than
	//     collapsed -- a caller that means "the parent" must say so itself;
	//   - the ancestor is walked DOWN from the root one plain component at a
	//     time, and every component that EXISTS is resolved as it is met: a
	//     symlink becomes its realpath(), so the ancestor carries no symlink,
	//     no '.' and no '..' of its own;
	//   - the tail is only reconstructed once it is proven ABSENT, never merely
	//     unresolvable. realpath() and lstat() fail identically on a name that
	//     is not there, on an ancestor this uid may not search, on a dangling
	//     symlink and on a symlink loop, and the last three all reconstruct a
	//     tail whose components may be symlinks pointing anywhere. That is not
	//     hypothetical here: the producer runs as the web user and the eraser
	//     runs as whatever uid rTorrent uses, so a directory the producer cannot
	//     search is an ordinary state, and the child that CAN search it would
	//     resolve the "canonical" name straight through the link. Absence is
	//     therefore proven positively -- the deepest ancestor must be a
	//     directory this process can really search, and every remaining prefix
	//     must really lstat() as absent -- and everything else fails closed.
	//
	// A name built from a canonical ancestor plus components that are all plain
	// names AND all proven absent cannot climb out of the ancestor.
	public function canonicalMissingPath($path)
	{
		if(!is_string($path) || $path === '' || $path[0] !== '/'
			|| strpos($path, "\0") !== false
			|| strlen($path) > ErasedataManifestCodec::MAX_PATH_BYTES)
			return(false);
		$parts = explode('/', $path);
		$components = array();
		for($i = 1; $i < count($parts); $i++)
		{
			// A trailing slash lands here as an empty component as well, so
			// "<dir>/" and "<dir>//leaf" are both refused rather than silently
			// meaning "<dir>".
			if($parts[$i] === '' || $parts[$i] === '.' || $parts[$i] === '..')
				return(false);
			$components[] = $parts[$i];
		}
		$count = count($components);
		if(!$count)
			return(false);
		$ancestor = '/';
		$depth = 0;
		for(; $depth < $count; $depth++)
		{
			$candidate = ($ancestor === '/' ? '' : $ancestor).'/'.$components[$depth];
			clearstatcache(true, $candidate);
			$link = @lstat($candidate);
			if(!is_array($link) || !isset($link['mode']))
				break;
			// The name is there. A symlink is resolved HERE or the whole answer
			// fails: a link that cannot be resolved -- dangling today and live
			// tomorrow, or a loop -- is not an ancestor anything may be built on.
			if(($link['mode'] & 0170000) === 0120000)
			{
				$real = @realpath($candidate);
				if(!is_string($real) || $real === '' || $real[0] !== '/')
					return(false);
				$ancestor = ($real === '/') ? '/' : rtrim($real, '/');
			}
			else
				$ancestor = $candidate;
		}
		if($depth < $count)
		{
			// Prove the tail is ABSENT rather than merely unreadable. A
			// directory this process may not search fails lstat() on its
			// children exactly like a directory that does not hold them, so the
			// deepest ancestor is required to answer a search of its own '.'
			// entry -- which needs the very execute bit the walk above needed
			// and could not otherwise have been shown to hold.
			$search = ($ancestor === '/' ? '' : $ancestor).'/.';
			clearstatcache(true, $search);
			$searchStat = @stat($search);
			if(!is_array($searchStat) || !isset($searchStat['mode'])
				|| ($searchStat['mode'] & 0170000) !== 0040000)
				return(false);
			$probe = $ancestor;
			for($i = $depth; $i < $count; $i++)
			{
				$probe = ($probe === '/' ? '' : $probe).'/'.$components[$i];
				clearstatcache(true, $probe);
				if(@lstat($probe) !== false)
					return(false);
			}
		}
		$base = ($ancestor === '/') ? '' : $ancestor;
		$name = $base;
		for($i = $depth; $i < $count; $i++)
			$name .= '/'.$components[$i];
		if($name === '')
			$name = '/';
		// Belt and braces: the components above cannot climb, and this proves it
		// of the name that is actually returned rather than of the argument.
		if($base !== '' && $name !== $base && strpos($name, $base.'/') !== 0)
			return(false);
		return($name);
	}

	// The physical identity of ONE name, owned by this plugin.
	//
	// Every erasedata decision that compares two names, verifies a captured
	// object or authorises a deletion reads this. The answer shape is the one
	// its callers already read -- 'exists', a canonical 'path', and 'lstat' /
	// 'stat' dev+ino pairs -- and for a name that EXISTS the answer is the same
	// realpath()/lstat()/stat() reading the core resolver gives.
	//
	// The missing name is where this owner exists. The core XMLRPCPathResolver,
	// shared with core endpoints this plugin does not own, reconstructs an
	// unresolved tail VERBATIM through deepestExistingAncestor(): '.', '..' and
	// any symlink component survive into the "canonical" name, so a name that
	// satisfies a lexical containment check against its intended root can
	// resolve somewhere else entirely. erasedataPathsOverlap() turns exactly
	// that comparison into a deletion authorisation -- false means DELETE -- so
	// a missed overlap deletes a path a live download still owns. The plugin
	// answers with canonicalMissingPath() instead, and answers FALSE for every
	// missing name whose canonical location it cannot PROVE.
	//
	// false is never "no overlap": every caller in this plugin treats a
	// non-array identity as uncertainty and retains. Fail closed is the whole
	// contract of this method.
	public function pathIdentity($path)
	{
		if(!is_string($path) || $path === '' || $path[0] !== '/'
			|| strpos($path, "\0") !== false)
			return(false);
		clearstatcache(true, $path);
		if(!file_exists($path) && !is_link($path))
		{
			// Absent -- so the canonical name is the one it WOULD have, proven
			// component by component, or there is no answer at all.
			$canonical = $this->canonicalMissingPath($path);
			if(!is_string($canonical) || $canonical === '')
				return(false);
			return(array(
				'exists' => false,
				'path' => $canonical,
				'lstat' => null,
				'stat' => null,
			));
		}
		$lstat = @lstat($path);
		$stat = @stat($path);
		$resolved = @realpath($path);
		if(!is_array($lstat) || !is_array($stat)
			|| !is_string($resolved) || $resolved === '')
			return(false);
		return(array(
			'exists' => true,
			'path' => ($resolved === '/') ? '/' : rtrim($resolved, '/'),
			'lstat' => array('dev'=>$lstat['dev'], 'ino'=>$lstat['ino']),
			'stat' => array('dev'=>$stat['dev'], 'ino'=>$stat['ino']),
		));
	}

	// The numeric entries of $root that name the identity $identity right now,
	// or false when the root cannot be listed at all.
	//
	// Nothing in a /proc/self/fd entry says who opened it, so a matching dev/ino
	// alone identifies the DIRECTORY, never the descriptor. This is the half of
	// acquireDirectoryCapability() that lets it tell its own descriptor from a
	// stranger's: the snapshot is taken before the handle exists and again while
	// it is held, and scandir() is used rather than a bare numeric probe so the
	// answer is whatever the process really holds.
	//
	// The transient descriptor scandir() itself consumes is never a false
	// positive: it names $root, and it is closed before the stat() loop below
	// runs, so it neither matches the target identity nor survives to be stat'd.
	private function descriptorsNamingIdentity($root, array $identity)
	{
		if(!is_string($root) || $root === '' || !is_dir($root))
			return(false);
		$entries = @scandir($root);
		if(!is_array($entries))
			return(false);
		$naming = array();
		foreach($entries as $entry)
		{
			if(!ctype_digit($entry))
				continue;
			$reference = $root.'/'.$entry;
			clearstatcache(true, $reference);
			if(erasedataIdentityDeviceAndInode(@stat($reference)) === $identity)
				$naming[] = $entry;
		}
		return($naming);
	}

	// One open directory handle whose physical identity is proven, plus a
	// descriptor path that names THIS handle.
	//
	// Existence of /proc/self/fd is NOT the capability: the capability is the
	// open handle, and the descriptor path is only accepted once fstat() on the
	// handle and stat() on <root>/<fd> agree on dev and ino. The handle stays
	// open in the returned array, so the traversal and the erase decision that
	// follow keep operating on the directory that was proven, not on a name
	// that may since have been replaced.
	//
	// Agreement on dev/ino is necessary but NOT sufficient. Any other descriptor
	// this process holds on the same directory matches it exactly, and taking
	// the first match hands back a descriptor the capability does not own: when
	// its real owner closes it, the number is reused by the next open() and the
	// "capability" then names an unrelated object, which
	// erasedataDeleteDirectoryReferenceContents() deletes through. So ownership
	// is proven positively. The descriptors that already name this identity are
	// snapshotted BEFORE the handle is opened and again while it is held; in a
	// single-threaded PHP process nothing else can run between the two, so
	// exactly one descriptor can have appeared and it is the one fopen() just
	// returned. Anything else -- none new, or more than one -- is unprovable and
	// fails closed: a capability that cannot be proven is not a capability.
	//
	// $candidates exists so the fixed roots can be driven in a test: production
	// always gets erasedataDescriptorCandidates(), which is hardcoded.
	public function acquireDirectoryCapability($path, $expectedIdentity, $candidates = null)
	{
		$expected = erasedataIdentityDeviceAndInode($expectedIdentity);
		if($expected === false)
			return(false);
		if(!is_array($candidates))
			$candidates = erasedataDescriptorCandidates();
		// Before the handle exists: every descriptor that already names it.
		$before = array();
		foreach($candidates as $index => $root)
			$before[$index] = $this->descriptorsNamingIdentity($root, $expected);
		$handle = @fopen($path, 'r');
		if($handle === false)
			return(false);
		$stat = @fstat($handle);
		$open = erasedataIdentityDeviceAndInode($stat);
		// The handle must be a DIRECTORY of the proven identity. Existence of a
		// candidate root proves nothing, and neither does a name that happened
		// to open: only the two together, on the same dev and ino.
		if($open === false || $open !== $expected || !is_array($stat)
			|| !isset($stat['mode']) || ($stat['mode'] & 0170000) !== 0040000)
		{
			@fclose($handle);
			return(false);
		}
		foreach($candidates as $index => $root)
		{
			if(!isset($before[$index]) || !is_array($before[$index]))
				continue;
			$after = $this->descriptorsNamingIdentity($root, $open);
			if(!is_array($after))
				continue;
			$opened = array_values(array_diff($after, $before[$index]));
			if(count($opened) !== 1)
				continue;
			return(array(
				'handle' => $handle,
				'path' => $root.'/'.$opened[0],
				'root' => $root,
				'dev' => $open['dev'],
				'ino' => $open['ino'],
			));
		}
		@fclose($handle);
		return(false);
	}

	public function releaseDirectoryCapability($capability)
	{
		if(!is_array($capability) || !isset($capability['handle'])
			|| !is_resource($capability['handle']))
			return(false);
		$closed = @fclose($capability['handle']) === true;
		// The descriptor name disappears with the handle, but a stat of it taken
		// while the capability was held is still cached, and a cached stat is
		// how a released capability goes on looking like a live directory. The
		// cache is dropped here so nothing downstream can act on that answer.
		if(isset($capability['path']) && is_string($capability['path']))
			clearstatcache(true, $capability['path']);
		return($closed);
	}

	// The names the captured-entry protocol has always used. They are the
	// scripted seam the destructive-race tests override, so they stay, but the
	// capability itself has exactly one owner above.
	public function openDirectoryReference($path, $expectedIdentity)
	{
		return($this->acquireDirectoryCapability($path, $expectedIdentity));
	}

	public function closeDirectoryReference($reference)
	{
		$this->releaseDirectoryCapability($reference);
	}

	// Identity-bound deletion of one visible entry. Every mutation of the
	// captured-entry protocol below runs through this same object.
	// $emptyDirectoryOnly restricts a captured directory to rmdir(): the
	// recovery paths that must never recurse pass true and still arrive here.
	public function unlinkCapturedEntry($path, $expectedIdentity, $reservationKey,
		$emptyDirectoryOnly = false)
	{
		return(erasedataDeleteCapturedEntry(
			$path, $expectedIdentity, $reservationKey, $this, $emptyDirectoryOnly));
	}

	// Removal of one private protocol container. Only the listed entries may be
	// present, and the private marker is the only entry this may unlink.
	public function removePrivateContainer($root, array $allowedEntries)
	{
		$entries = $this->scanDirectory($root);
		if($entries === false || count(array_diff($entries, $allowedEntries)) > 0)
			return(false);
		$marker = erasedataPrivateMarkerPath($root);
		if(in_array(basename($marker), $allowedEntries, true)
			&& erasedataPathExists($marker)
			&& (!erasedataPrivateMarkerIsValid($root) || !$this->unlink($marker)))
			return(false);
		return($this->removeDirectory($root));
	}
}
function erasedataPathExists($path)
{
	clearstatcache(true, $path);
	return(file_exists($path) || is_link($path));
}

// "$directory/<name>" for each entry whose name starts with $prefix and ends
// with $suffix, in byte order: what glob($directory.'/'.$prefix.'*'.$suffix)
// answered for arguments without pattern bytes, a '*' that does not match a
// leading dot included. The file operations list directories under the
// session path, which is the daemon's own bytes, and under the profile path;
// glob() read a '\', '[...]', '*' or '?' there as pattern syntax and matched
// nothing, or a sibling directory (R5-REV-05). Here every byte is literal.
// False when the directory cannot be listed.
function erasedataDirectoryMatches($directory, $prefix, $suffix)
{
	$names = @scandir($directory);
	if(!is_array($names))
		return(false);
	$matches = array();
	foreach($names as $name)
		if($name !== '.' && $name !== '..'
			&& ($prefix !== '' || $name[0] !== '.')
			&& strlen($name) >= strlen($prefix) + strlen($suffix)
			&& strncmp($name, $prefix, strlen($prefix)) === 0
			&& ($suffix === '' || substr($name, -strlen($suffix)) === $suffix))
			$matches[] = $directory.'/'.$name;
	sort($matches, SORT_STRING);
	return($matches);
}

// The only roots a directory capability may be taken through, in the order they
// are tried. Hardcoded: a capability that could be pointed at an attacker-chosen
// root would not be a capability at all. /proc/self/fd is the normal answer and
// /dev/fd the fallback for systems that do not mount procfs.
function erasedataDescriptorCandidates()
{
	return(array('/proc/self/fd', '/dev/fd'));
}

// dev/ino of one identity array, as strings, or false.
//
// Strings because the other operand often already is one. dev/ino are spelled
// into captured-entry directory names and parsed back out of them as strings by
// erasedataCapturedEntryRootInfo(), and acquireDirectoryCapability() stores this
// function's own stringified answer, which erasedataRemovalCapabilityStillMatches()
// then compares against a fresh @fstat(). One spelling for both shapes is what
// lets === decide, and (string) of an int is exact.
//
// It is NOT a float-precision safeguard, and must not be read as one: (string)
// of a float renders through precision=14, so it merges values that a float ===
// keeps apart, not the reverse. Widths are not a concern here either --
// ErasedataManifestCodec::normalizeIdentity() rejects a dev/ino that is not a
// nonnegative int at the only boundary where a decoded one could arrive.
function erasedataIdentityDeviceAndInode($identity)
{
	if(!is_array($identity))
		return(false);
	$source = $identity;
	if(isset($identity['stat']) && is_array($identity['stat']))
		$source = $identity['stat'];
	else if(isset($identity['lstat']) && is_array($identity['lstat']))
		$source = $identity['lstat'];
	if(!isset($source['dev']) || !isset($source['ino'])
		|| !(is_int($source['dev']) || is_float($source['dev']) || is_string($source['dev']))
		|| !(is_int($source['ino']) || is_float($source['ino']) || is_string($source['ino'])))
		return(false);
	return(array('dev' => (string)$source['dev'], 'ino' => (string)$source['ino']));
}

// Report the path that was actually inspected, with enough metadata to repair
// an offline queue without implicitly attesting or changing its old obligations.
function erasedataLogPath($path)
{
	$encoded = json_encode($path, JSON_UNESCAPED_SLASHES);
	return($encoded === false ? 'base64:'.base64_encode($path) : $encoded);
}

function erasedataQueueRefusal($reason, $path, $stat, $expected, $action = '')
{
	$metadata = is_array($stat)
		? ' uid='.$stat['uid'].' gid='.$stat['gid'].' mode='.sprintf('%04o', $stat['mode'] & 07777)
			.' nlink='.$stat['nlink'] : ' metadata=missing';
	FileUtil::toLog('erasedata: queue refused: '.$reason.' path='
		.erasedataLogPath($path).$metadata.' expected='.$expected
		.'; cleanup held'.($action === '' ? '' : '; '.$action));
	return(false);
}

// A directory without subdirectories has two links on most Linux filesystems,
// but one on filesystems that do not count subdirectory links: btrfs, and the
// WSL2 drvfs over 9p that Docker Desktop uses for Windows drives (measured
// 2026-10-11 on the owner's host). Callers prove the contents from the
// listing; the native helper's leaf_directory_links() applies the same rule.
function erasedataLeafDirectoryLinks($nlink)
{
	return($nlink === 1 || $nlink === 2);
}

function erasedataRenameProbeName($name)
{
	return(is_string($name) && preg_match('/^\.rutorrent-rename-probe-[0-9]+-[a-f0-9]{16}$/D', $name));
}

// A probe workspace can hold only finite infrastructure. It never licenses a
// deletion, and the native helper alone recovers it under its private flock.
function erasedataRenameProbeWorkspaceValid($path, $uid)
{
	$root = @lstat($path);
	if(!is_array($root) || ($root['mode'] & 0170000) !== 0040000
		|| ($root['mode'] & 07777) !== 0700 || $root['uid'] !== $uid)
		return(false);
	$entries = @scandir($path);
	if(!is_array($entries)) return(false);
	foreach(array_diff($entries, array('.', '..')) as $name)
	{
		$isFile = in_array($name, array('.lock', 'from-file', 'to-file'), true);
		$isDirectory = in_array($name, array('from-dir', 'to-dir'), true);
		if(!$isFile && !$isDirectory) return(false);
		$child = @lstat($path.'/'.$name);
		// An active native probe can remove its own inspected entry.
		if($child === false && !erasedataPathExists($path.'/'.$name)) continue;
		if(!is_array($child) || $child['uid'] !== $uid
			|| ($child['mode'] & 0170000) !== ($isFile ? 0100000 : 0040000)
			|| ($child['mode'] & 07777) !== ($isFile ? 0600 : 0700)
			|| ($isFile ? $child['nlink'] !== 1 : !erasedataLeafDirectoryLinks($child['nlink']))
			|| ($name === '.lock' && $child['size'] !== 0)) return(false);
		if($isDirectory)
		{
			$children = @scandir($path.'/'.$name);
			if(!is_array($children) || count(array_diff($children, array('.', '..')))) return(false);
		}
	}
	return(true);
}

// Validate exactly the lstat metadata supplied by the caller. Separating the
// predicate lets every UID exercise the root-witness mode/link refusal paths.
function erasedataValidateFirstUseWitness($path, $marker, $recovery)
{
	$expected = 'regular-file uid=0 mode=0400 nlink=1';
	if(!is_array($marker))
		return(erasedataQueueRefusal('witness-missing', $path, $marker, $expected, $recovery));
	if(($marker['mode'] & 0170000) !== 0100000)
		return(erasedataQueueRefusal('witness-type', $path, $marker, $expected, $recovery));
	if($marker['uid'] !== 0)
		return(erasedataQueueRefusal('witness-owner', $path, $marker, $expected, $recovery));
	if(($marker['mode'] & 07777) !== 0400)
		return(erasedataQueueRefusal('witness-mode', $path, $marker, $expected, $recovery));
	if($marker['nlink'] !== 1)
		return(erasedataQueueRefusal('witness-links', $path, $marker, $expected, $recovery));
	return(true);
}

// A full plugin initialization checks admission once. Destructive entrypoints
// still call erasedataEnsureQueueDirectory directly and re-read every identity.
function erasedataInitializationQueueReady(&$path = null)
{
	static $admissions = array();
	$key = FileUtil::getSettingsPath();
	if(!array_key_exists($key, $admissions))
	{
		$queue = erasedataQueuePath();
		$admissions[$key] = array('path' => $queue,
			'ready' => $queue !== false && erasedataEnsureQueueDirectory($queue));
		// Full init routes load the RPC helper before admission. Retire a stale
		// ordinary collector once, while keeping every durable drain obligation.
		if(!$admissions[$key]['ready'] && function_exists('erasedataRetireRefusedCollector'))
			erasedataRetireRefusedCollector();
	}
	$path = $admissions[$key]['path'];
	return($admissions[$key]['ready']);
}

// A protected sibling is provisioned only on a first Docker profile boot (or
// by an operator who has attested the old queue). It is the persistent witness
// that an absent legacy name under a group-writable share cannot provide.
function erasedataQueuePath()
{
	$settingsPath = FileUtil::getSettingsPath();
	$legacy = $settingsPath.'/erasedata';
	$settings = @realpath($settingsPath);
	if($settings === false)
		return($legacy);
	$profile = dirname($settings);
	if(basename($profile) === 'share')
	{
		$root = dirname($profile);
		$leaf = 'default';
	}
	else if(basename(dirname($profile)) === 'users'
		&& basename(dirname(dirname($profile))) === 'share')
	{
		$root = dirname(dirname(dirname($profile)));
		$leaf = 'user-'.hash('sha256', basename($profile));
	}
	else
		return($legacy);
	$protected = $root.'/erasedata-jobs';
	$witness = $root.'/.erasedata-first-use';
	if(!erasedataPathExists($protected) && !erasedataPathExists($witness))
		return($legacy);
	$uid = erasedataEffectiveUid();
	$unsealed = false;
	clearstatcache(true, $protected);
	$stat = @lstat($protected);
	clearstatcache(true, $witness);
	$marker = @lstat($witness);
	$recovery = 'stop services; inspect legacy *.list and *.pending obligations before operator attestation; '
		.'restore the witness only through the documented root-owned bootstrap/attestation procedure';
	if($uid === false)
		return(erasedataQueueRefusal('effective-uid-unavailable', $protected, $stat, 'service UID', $recovery));
	if(!erasedataValidateFirstUseWitness($witness, $marker, $recovery))
		return(false);
	$recovery = 'stop services; preserve and inspect the named queue obligations before operator attestation';
	if(!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000)
		return(erasedataQueueRefusal('protected-root-type', $protected, $stat, 'directory uid='.$uid.' mode=0700', $recovery));
	if($stat['uid'] !== $uid || ($stat['mode'] & 0077) !== 0)
		return(erasedataQueueRefusal('protected-root-owner-mode', $protected, $stat, 'directory uid='.$uid.' mode=0700', $recovery));
	$ancestor = null;
	if(!erasedataGuardParentChain($protected.'/'.$leaf, $uid, false, $unsealed, false, $ancestor))
		return(false);
	if($unsealed)
		return(erasedataQueueRefusal('protected-root-writable-ancestor', $ancestor['path'],
			$ancestor['stat'], 'no group/other write; queue mode=0700', $recovery));
	// A pre-existing legacy name can hold obligations. Never hide it behind a
	// new queue, even if the protected root has already been provisioned.
	if(erasedataPathExists($legacy) || erasedataPathExists($settings.'/erasedata'))
	{
		return(erasedataQueueRefusal('legacy queue needs operator attestation', $settings.'/erasedata',
			@lstat($settings.'/erasedata'), 'offline reviewed obligations', $recovery));
	}
	return($protected.'/'.$leaf);
}

// Queue admission observes old writable ancestors and holds their contents.
// New private payload roots may seal service-owned writable ancestors before
// capture. Lexical links and their resolved targets are checked separately.
function erasedataGuardParentChain($path, $uid, $allowLinks, &$unsealed, $sealData = false, &$ancestor = null, $report = true)
{
	$parents = array();
	for($dir = dirname($path); $dir !== dirname($dir); $dir = dirname($dir))
		$parents[] = $dir;
	$parents[] = '/';
	foreach(array_reverse($parents) as $dir)
	{
		clearstatcache(true, $dir);
		$stat = @lstat($dir);
		$type = is_array($stat) ? $stat['mode'] & 0170000 : null;
		if(!is_array($stat) || ($stat['uid'] !== $uid && $stat['uid'] !== 0)
			|| ($type !== 0040000 && !($allowLinks && $type === 0120000)))
		{
			if(!$report) return(false);
			if(!$sealData)
				return(erasedataQueueRefusal('unsafe ancestor', $type === 0040000 ? (@realpath($dir) ?: $dir) : $dir,
					$stat, 'directory owned by root or uid='.$uid,
					'stop services; inspect queue obligations before operator attestation; restore the named ancestor owner/type'));
			FileUtil::toLog('erasedata: private-root refused: unsafe ancestor '.$dir);
			return(false);
		}
		if($type === 0120000)
			continue;
		if(($stat['mode'] & 0022) && !($stat['mode'] & 01000))
		{
			$settings = dirname($path);
			$userProfile = dirname($settings);
			$users = dirname($userProfile);
			$ownedProfileDirectory = $sealData || $dir === $settings
				|| (basename($users) === 'users'
					&& ($dir === $userProfile || $dir === $users));
			if(!$ownedProfileDirectory || $stat['uid'] !== $uid)
			{
				if(!$report) return(false);
				if(!$sealData)
					return(erasedataQueueRefusal('unsupported writable ancestor', @realpath($dir) ?: $dir,
						$stat, 'no group/other write; queue mode=0700',
						'stop services; inspect *.list and *.pending obligations before operator attestation; chmod go-w on the named ancestor'));
				FileUtil::toLog('erasedata: private-root refused: unsupported writable ancestor '.$dir);
				return(false);
			}
			$unsealed = true;
			if($ancestor === null)
				$ancestor = array('path' => @realpath($dir) ?: $dir, 'stat' => $stat);
			if($sealData)
			{
				if(!@chmod($dir, ($stat['mode'] & 07777) | 01000))
				{
					FileUtil::toLog('erasedata: private-root refused: cannot seal ancestor '.$dir);
					return(false);
				}
				clearstatcache(true, $dir);
				$sealed = @lstat($dir);
				if(!is_array($sealed) || $sealed['dev'] !== $stat['dev']
					|| $sealed['ino'] !== $stat['ino'] || !($sealed['mode'] & 01000))
				{
					FileUtil::toLog('erasedata: private-root refused: ancestor changed '.$dir);
					return(false);
				}
			}
		}
	}
	return(true);
}

// A path that resolves elsewhere after the first parent check cannot name the
// queue we inspected, even when both targets have otherwise safe modes.
function erasedataQueueParentUnchanged($listPath, $parent)
{
	if(@realpath(dirname($listPath)) === $parent)
		return(true);
	FileUtil::toLog('erasedata: queue refused: queue parent changed during admission '.$listPath);
	return(false);
}

// PHP builds without ext-posix still expose the effective UID on Linux.
function erasedataEffectiveUid()
{
	require_once(dirname(__DIR__, 2).'/php/utility/permission.php');
	return(Permission::effectiveUid());
}

// A listing is only a snapshot. Another trusted worker may finish and unlink
// its staging entry before we can inspect that name.
// null means positively absent; false means unreadable or continuous churn.
function erasedataListedEntryStat($directory, $entry)
{
	$path = $directory.'/'.$entry;
	for($attempt = 0; $attempt < 3; $attempt++)
	{
		clearstatcache(true, $path);
		$stat = @lstat($path);
		if(is_array($stat)) return($stat);
		$current = @scandir($directory);
		if(!is_array($current)) return(false);
		if(!in_array($entry, $current, true)) return(null);
		// The same worker may already have re-created the fixed intent name.
		// Inspect that inode rather than refusing from the first stale miss.
	}
	return(false);
}

function erasedataQueueEntriesOwned($physicalPath, array $entries, $uid, $listPath)
{
	foreach($entries as $entry)
	{
		if($entry === '.' || $entry === '..')
			continue;
		$entryPath = $physicalPath.'/'.$entry;
		$stat = erasedataListedEntryStat($physicalPath, $entry);
		if($stat === null) continue;
		if(!is_array($stat) || $stat['uid'] !== $uid)
		{
			FileUtil::toLog('erasedata: queue refused: foreign or unreadable entry in '.$listPath);
			return(false);
		}
		if(erasedataRenameProbeName($entry)
			&& !erasedataRenameProbeWorkspaceValid($entryPath, $uid))
			return(erasedataQueueRefusal('unsafe-rename-probe', $entryPath, $stat,
				'private owned probe directory with fixed plain entries',
				'stop services; preserve unexpected probe contents and inspect the named workspace'));
	}
	return(true);
}

// A queue whose path was writable before this call may already have lost an
// intent. Only an operator can attest that legacy state while services are stopped.
function erasedataEnsureQueueDirectory($listPath)
{
	if(!is_string($listPath) || $listPath === '' || $listPath[0] !== '/'
		|| strpos($listPath, "\0") !== false)
	{
		FileUtil::toLog('erasedata: queue refused: invalid queue path');
		return(false);
	}
	$uid = erasedataEffectiveUid();
	if($uid === false)
	{
		FileUtil::toLog('erasedata: queue refused: effective UID unavailable');
		return(false);
	}
	$settings = dirname($listPath);
	if(!is_dir($settings))
	{
		clearstatcache(true, $settings);
		$existing = @lstat($settings);
		$profile = @realpath(dirname($settings));
		$unsafe = false;
		if(is_array($existing))
		{
			FileUtil::toLog('erasedata: queue refused: settings path is not a directory '.$settings);
			return(false);
		}
		if($profile === false)
		{
			FileUtil::toLog('erasedata: queue refused: settings parent is unresolved '.$settings);
			return(false);
		}
		if(!erasedataGuardParentChain($settings, $uid, true, $unsafe))
			return(false);
		$physicalSettings = rtrim($profile, '/').'/'.basename($settings);
		if(!erasedataGuardParentChain($physicalSettings, $uid, false, $unsafe)
			|| $unsafe)
		{
			FileUtil::toLog('erasedata: queue refused: missing settings under unsafe parent '.$settings);
			return(false);
		}
		global $profileMask;
		@FileUtil::makeDirectory($physicalSettings,
			(isset($profileMask) ? $profileMask : 0777) | 01000, true);
		if(!is_dir($physicalSettings))
		{
			FileUtil::toLog('erasedata: queue refused: cannot create settings '.$settings);
			return(false);
		}
	}
	$unsealed = false;
	$ancestor = null;
	if(!erasedataGuardParentChain($listPath, $uid, true, $unsealed, false, $ancestor))
		return(false);
	$parent = @realpath(dirname($listPath));
	if($parent === false)
	{
		FileUtil::toLog('erasedata: queue refused: unresolved parent '.$listPath);
		return(false);
	}
	$physicalPath = rtrim($parent, '/').'/'.basename($listPath);
	if(!erasedataGuardParentChain($physicalPath, $uid, false, $unsealed, false, $ancestor))
		return(false);
	clearstatcache(true, $physicalPath);
	$before = @lstat($physicalPath);
	if(is_array($before) && (($before['mode'] & 0170000) !== 0040000
		|| $before['uid'] !== $uid))
	{
		FileUtil::toLog('erasedata: queue refused: foreign or non-directory queue '.$listPath);
		return(false);
	}
	if($unsealed || (is_array($before) && ($before['mode'] & 0022)))
	{
		$action = 'stop services; inspect *.list and *.pending obligations before operator attestation; '
			.'chmod go-w on the named ancestor and chmod 0700 on queue '.erasedataLogPath($physicalPath);
		$offending = $ancestor === null ? $physicalPath : $ancestor['path'];
		$offendingStat = $ancestor === null ? $before : $ancestor['stat'];
		return(erasedataQueueRefusal('legacy writable path needs operator attestation',
			$offending, $offendingStat, 'no group/other write; queue mode=0700', $action));
	}
	$reopened = false;
	if(!erasedataGuardParentChain($listPath, $uid, true, $reopened)
		|| !erasedataQueueParentUnchanged($listPath, $parent)
		|| !erasedataGuardParentChain($physicalPath, $uid, false, $reopened))
		return(false);
	if($reopened)
	{
		FileUtil::toLog('erasedata: queue refused: path reopened during admission; cleanup held '.$listPath);
		return(false);
	}
	@FileUtil::makeDirectory($physicalPath, 0700, true);
	clearstatcache(true, $physicalPath);
	$after = @lstat($physicalPath);
	if(!is_array($after) || ($after['mode'] & 0170000) !== 0040000
		|| $after['uid'] !== $uid || ($after['mode'] & 07777) !== 0700
		|| (is_array($before) && ($before['dev'] !== $after['dev']
			|| $before['ino'] !== $after['ino'])))
	{
		return(erasedataQueueRefusal('cannot seal queue', $physicalPath, $after,
			'directory uid='.$uid.' mode=0700 same-inode', 'stop services; inspect queue before restoring mode 0700'));
	}
	$entries = @scandir($physicalPath);
	if(!is_array($entries))
	{
		FileUtil::toLog('erasedata: queue refused: cannot inspect existing entries '.$listPath);
		return(false);
	}
	if(!erasedataQueueEntriesOwned($physicalPath, $entries, $uid, $listPath)
		|| !erasedataQueueParentUnchanged($listPath, $parent))
		return(false);
	clearstatcache(true, $physicalPath);
	$current = @lstat($physicalPath);
	if(!is_array($current) || $current['dev'] !== $after['dev']
		|| $current['ino'] !== $after['ino'])
	{
		FileUtil::toLog('erasedata: queue refused: queue changed during entry scan '.$listPath);
		return(false);
	}
	return(true);
}

if(!function_exists('erasedataSharedFileMode'))
{
	// The mode every file this plugin shares between the web user and the user
	// rTorrent runs the scheduled child as must carry. This is its ONE
	// definition -- removewithdata.php:27-30 records the same single-owner rule
	// from the other side -- and it lives here so the durable writer below and
	// the obligation store in pending.php do not have to depend on the RPC layer
	// to get at it.
	function erasedataSharedFileMode()
	{
		global $profileMask;
		return((isset($profileMask) ? $profileMask : 0777) & 0666);
	}
}

// A unique staging file inside $directory for a rename onto a name there, or
// false. tempnam() silently falls back to the system temporary directory when
// $directory takes no new file; a rename from there can cross filesystems,
// where PHP copies over the live target instead of replacing it atomically
// (R4-FS-08). Such a file is removed and refused. tempnam() resolves the
// directory, so the comparison is with its physical path.
function erasedataStagingFile($directory, $prefix)
{
	$physical = is_string($directory) && $directory !== '' ? @realpath($directory) : false;
	if($physical === false || !is_dir($physical))
		return(false);
	$staging = @tempnam($physical, $prefix);
	if($staging === false)
		return(false);
	if(dirname($staging) !== $physical)
	{
		@unlink($staging);
		return(false);
	}
	return($staging);
}

// Publish $bytes at $path, or publish nothing.
//
// Invariant 15, in one place: the whole payload is written and the count is
// checked against what was handed in, the stream is flushed, the close is
// CHECKED -- a buffered filesystem reports a full disk there and nowhere else --
// and only then is the staging name moved onto the final one with rename(),
// which is atomic. A reader therefore never observes a half-written record, and
// a caller that does not check the return value cannot proceed on one either,
// because there is nothing under the final name to proceed on.
//
// Returns true only when the bytes are published. Every failure leaves the final
// name exactly as it was and removes the staging object.
function erasedataWriteDurableFile($path, $bytes, $mode = null)
{
	if(!is_string($path) || $path === '' || strpos($path, "\0") !== false
		|| !is_string($bytes))
		return(false);
	if(is_null($mode))
		$mode = erasedataSharedFileMode();
	if(!is_int($mode))
		return(false);
	$token = erasedataPrivateToken();
	if($token === false)
		return(false);
	// Beside the final name, so the rename below is within one directory and
	// therefore atomic, and unique, so two writers never share a staging object.
	// The leading dot keeps it out of the <hash>.<generation>.<...>.tmp grammar
	// the drain worker's unbound-staging scan matches on: residue from an
	// interrupted write is this file's to clean up, and a scan that matched it
	// would strand that hash as `staging-unbound` until a human cleared it. The
	// collector is not the consumer this protects against -- it refuses every
	// generation-bearing .tmp candidate outright, dot or no dot.
	$staging = dirname($path).'/.'.basename($path).'.'.$token.'.tmp';
	$handle = @fopen($staging, 'xb');
	if($handle === false)
		return(false);
	$total = strlen($bytes);
	$written = 0;
	$complete = true;
	while($written < $total)
	{
		$chunk = @fwrite($handle, substr($bytes, $written));
		if(!is_int($chunk) || $chunk <= 0)
		{
			$complete = false;
			break;
		}
		$written += $chunk;
	}
	if($written !== $total)
		$complete = false;
	if($complete && @fflush($handle) === false)
		$complete = false;
	if(@fclose($handle) !== true)
		$complete = false;
	if($complete)
	{
		@chmod($staging, $mode);
		if(@rename($staging, $path) === true)
			return(true);
	}
	@unlink($staging);
	return(false);
}

// -- generations (invariant 14) ---------------------------------------------
//
// A generation is exactly 16 lowercase hex digits and is handled as a STRING
// from end to end. Hex-to-integer conversion and native-width arithmetic both
// collapse the top of that range on a 32-bit build, and the conversion silently
// yields a float even on 64-bit, so two distinct generations would compare equal
// and an increment would wrap. Nothing below turns a generation into a number.

function erasedataGenerationIsValid($value)
{
	return(is_string($value) && preg_match('/^[0-9a-f]{16}$/D', $value) === 1);
}

// The next generation, or false at ffffffffffffffff.
//
// Overflow fails closed rather than wrapping to zero: a wrapped generation would
// re-use a name an older obligation still owns, and invariant 3 rests on a
// generation naming exactly one physical job.
function erasedataGenerationIncrement($generation)
{
	if(!erasedataGenerationIsValid($generation))
		return(false);
	$digits = '0123456789abcdef';
	$next = $generation;
	for($position = 15; $position >= 0; $position--)
	{
		$value = strpos($digits, $next[$position]);
		if(!is_int($value))
			return(false);
		if($value < 15)
		{
			$next[$position] = $digits[$value + 1];
			return($next);
		}
		$next[$position] = '0';
	}
	return(false);
}

// -1, 0 or 1, or false when either operand is not a generation. Fixed width and
// one case means the lexical order IS the numeric order.
function erasedataGenerationCompare($left, $right)
{
	if(!erasedataGenerationIsValid($left) || !erasedataGenerationIsValid($right))
		return(false);
	$order = strcmp($left, $right);
	return($order < 0 ? -1 : ($order > 0 ? 1 : 0));
}

// Who is allowed to start the collector. A predicate rather than an inline
// condition at the entry point, because the SAPI half of it cannot be reached
// by any test the suite can run -- there is no non-CLI SAPI available to it,
// and without this seam removing that half left every test green while an
// unauthenticated HTTP request could reach a destructive collector.
//
// Both halves are load-bearing. The path test alone is useless: plugins live
// under the document root, so a request for /plugins/erasedata/update.php sets
// SCRIPT_FILENAME to that very path and satisfies it exactly. The SAPI test
// alone is not enough either: another CLI script requiring this file for
// erasedataRunCollector() must not trip the collector.
function erasedataMayStartCollector($sapi, $scriptFilename, $entryPoint)
{
	if($sapi !== 'cli')
		return(false);
	if(!is_string($scriptFilename) || $scriptFilename === '')
		return(false);
	return(realpath($scriptFilename) === $entryPoint);
}

// The identity read every erasedata decision goes through, as a free function.
//
// ErasedataFilesystemOps::pathIdentity() is the owner; this is the seamless
// spelling for the three callers that have no injected filesystem in scope --
// erasedataPathsOverlap() and erasedataCleanupCurrentIdentity(), which are pure
// predicates, and erasedataReservationHasEncodedIdentity(), which validates a
// name it was handed. The instance is stateless and is built once so the
// collector's per-file loops do not construct one per name.
//
// This function, and the method behind it, replace the core
// XMLRPCPathResolver's filesystemIdentity() everywhere in this plugin:
// identity is what authorises a payload deletion here, so its owner is this
// plugin and not a core file shared with endpoints answering a different
// question.
function erasedataPathIdentity($path)
{
	static $ops = null;
	if($ops === null)
		$ops = new ErasedataFilesystemOps();
	return($ops->pathIdentity($path));
}

// The single owned-path predicate of this plugin. Every collector decision that
// asks "does this manifest path touch something the torrent still owns?" runs
// through it, so files and directories can never diverge. erasedataPathsOverlap()
// (removewithdata.php) supplies the component-wise containment plus physical
// identity comparison and stays fail-closed on an unresolvable existing name.
function erasedataPathTouchesOwnedPaths($path, array $ownedPaths)
{
	// erasedataPathsOverlap() lives in removewithdata.php, which this file does
	// not require -- the dependency runs the other way. Every entry point loads
	// it before anything can reach here; fail closed rather than fatally if one
	// ever does not, because answering "touches nothing" would authorise a
	// deletion.
	if(!function_exists('erasedataPathsOverlap'))
		return(true);
	if(isset($ownedPaths['files']) && is_array($ownedPaths['files']))
		foreach($ownedPaths['files'] as $owned)
			if(erasedataPathsOverlap($path, $owned))
				return(true);
	return(isset($ownedPaths['base']) && is_string($ownedPaths['base'])
		&& erasedataPathsOverlap($path, $ownedPaths['base']));
}

function erasedataPrivateToken()
{
	try {
		return(bin2hex(random_bytes(16)));
	} catch(Exception $e) {
		return(false);
	}
}

function erasedataPrivateMarkerPath($root)
{
	return($root.'/.initialized');
}

function erasedataCaptureSourceOwned($path)
{
	clearstatcache(true, $path);
	$source = @lstat($path);
	$parent = @lstat(dirname($path));
	$uid = erasedataEffectiveUid();
	return($uid !== false && is_array($source) && is_array($parent)
		&& (!(($parent['mode'] & 0022) && ($parent['mode'] & 01000))
			|| $source['uid'] === $uid || $source['uid'] === 0));
}

// A different UID may rename a private 0700 root through any writable,
// non-sticky ancestor. Seal only service-owned directories, including both the
// lexical path and the resolved target of each lexical symlink.
function erasedataProtectPrivateParent($root)
{
	$uid = erasedataEffectiveUid();
	if($uid === false || !is_string($root) || $root === '' || $root[0] !== '/')
		return(false);
	$unsealed = false;
	$parent = @realpath(dirname($root));
	if($parent === false
		|| !erasedataGuardParentChain($root, $uid, true, $unsealed, true)
		|| @realpath(dirname($root)) !== $parent
		|| !erasedataGuardParentChain($parent.'/'.basename($root),
			$uid, false, $unsealed, true))
		return(false);
	return(true);
}

// The only key is stored in the protected obligation queue. An incomplete key
// after a crash is a visible hold, never a reason to invent replacement trust.
// Only an empty, service-owned unsigned shell can be discarded after a
// crash-before-marker. A body or marker of unknown provenance remains held.
function erasedataDiscardEmptyUnsignedRoot($root)
{
	if(!erasedataProtectPrivateParent($root))
		return(false);
	$uid = erasedataEffectiveUid();
	clearstatcache(true, $root);
	$stat = @lstat($root);
	$entries = @scandir($root);
	return($uid !== false && is_array($stat) && $stat['uid'] === $uid
		&& ($stat['mode'] & 0170000) === 0040000
		&& is_array($entries) && count(array_diff($entries, array('.', '..'))) === 0
		&& @rmdir($root));
}

function erasedataPrivateRootKey($reservationKey, $create)
{
	if(!is_string($reservationKey) || $reservationKey === ''
		|| $reservationKey[0] !== '/' || strpos($reservationKey, "\0") !== false)
		return(false);
	$queue = dirname($reservationKey);
	if(!erasedataEnsureQueueDirectory($queue))
		return(false);
	$path = $queue.'/.private-root-key';
	$statePath = $queue.'/.private-root-key-state';
	clearstatcache(true, $path);
	clearstatcache(true, $statePath);
	$stat = @lstat($path);
	$state = @lstat($statePath);
	// The state is published before the first marker can be signed. Once it
	// exists, a missing key is loss, never permission to mint another key.
	if(!is_array($stat) && $create)
	{
		if(is_array($state))
		{
			FileUtil::toLog('erasedata: private root key refused: missing after first use; cleanup held');
			return(false);
		}
		try { $bytes = bin2hex(random_bytes(32)); }
		catch(Exception $e)
		{
			FileUtil::toLog('erasedata: private root key refused: random source unavailable; cleanup held');
			return(false);
		}
		$handle = @fopen($path, 'xb');
		if($handle !== false)
		{
			@chmod($path, 0600);
			$written = @fwrite($handle, $bytes);
			$flushed = @fflush($handle);
			$closed = @fclose($handle);
			if($written !== 64 || !$flushed || !$closed)
			{
				FileUtil::toLog('erasedata: private root key refused: incomplete key write; cleanup held');
				return(false);
			}
		}
		clearstatcache(true, $path);
		$stat = @lstat($path);
	}
	$uid = erasedataEffectiveUid();
	if(!is_array($stat) || $uid === false || $stat['uid'] !== $uid
		|| ($stat['mode'] & 0170000) !== 0100000
		|| ($stat['mode'] & 077) !== 0 || $stat['nlink'] !== 1
		|| $stat['size'] !== 64)
	{
		FileUtil::toLog('erasedata: private root key refused: missing or invalid; cleanup held');
		return(false);
	}
	$key = ErasedataManifestCodec::readBoundedFile($path, 64);
	if(!is_string($key) || preg_match('/^[0-9a-f]{64}$/D', $key) !== 1)
	{
		FileUtil::toLog('erasedata: private root key refused: invalid bytes; cleanup held');
		return(false);
	}
	if(!is_array($state) && $create)
	{
		if(!erasedataWriteDurableFile($statePath, 'v1', 0600))
		{
			FileUtil::toLog('erasedata: private root key refused: cannot record first use; cleanup held');
			return(false);
		}
		clearstatcache(true, $statePath);
		$state = @lstat($statePath);
	}
	if(!is_array($state) || $state['uid'] !== $uid
		|| ($state['mode'] & 0170000) !== 0100000
		|| ($state['mode'] & 077) !== 0 || $state['nlink'] !== 1
		|| $state['size'] !== 2
		|| ErasedataManifestCodec::readBoundedFile($statePath, 2) !== 'v1')
	{
		FileUtil::toLog('erasedata: private root key refused: missing or invalid first-use state; cleanup held');
		return(false);
	}
	return($key);
}

function erasedataPrivateRootSignature($root, $reservationKey, $createKey)
{
	if(!erasedataProtectPrivateParent($root))
		return(false);
	clearstatcache(true, $root);
	$stat = @lstat($root);
	$uid = erasedataEffectiveUid();
	if(!is_array($stat) || $uid === false || $stat['uid'] !== $uid
		|| ($stat['mode'] & 0170000) !== 0040000
		|| ($stat['mode'] & 0022))
		return(false);
	$key = erasedataPrivateRootKey($reservationKey, $createKey);
	if($key === false)
		return(false);
	$physicalParent = @realpath(dirname($root));
	if($physicalParent === false)
		return(false);
	$physicalRoot = rtrim($physicalParent, '/').'/'.basename($root);
	$parentStat = @lstat($physicalParent);
	if(!is_array($parentStat))
		return(false);
	return(hash_hmac('sha256', "v1\0".$physicalRoot."\0"
		.$parentStat['dev']."\0".$parentStat['ino']."\0"
		.$stat['dev']."\0".$stat['ino'], $key));
}

function erasedataCreatePrivateMarker($root, $reservationKey = null)
{
	$signature = $reservationKey === null ? ''
		: erasedataPrivateRootSignature($root, $reservationKey, true);
	if($signature === false)
		return(false);
	$path = erasedataPrivateMarkerPath($root);
	$handle = @fopen($path, 'xb');
	if($handle === false)
		return(false);
	@chmod($path, 0600);
	$written = $signature === '' ? 0 : @fwrite($handle, $signature);
	$flushed = @fflush($handle);
	$closed = @fclose($handle);
	if($written !== strlen($signature) || !$flushed || !$closed)
		return(false);
	return(true);
}

function erasedataPrivateMarkerIsValid($root, $reservationKey = null)
{
	$marker = erasedataPrivateMarkerPath($root);
	if(!is_file($marker) || is_link($marker))
		return(false);
	if($reservationKey === null)
		return(true);
	$signature = erasedataPrivateRootSignature($root, $reservationKey, false);
	$stored = ErasedataManifestCodec::readBoundedFile($marker, 64);
	return(is_string($signature) && is_string($stored)
		&& hash_equals($signature, $stored));
}

function erasedataEntryIdentityParts($identity)
{
	if(!is_array($identity))
		return(false);
	$stat = isset($identity['lstat']) && is_array($identity['lstat'])
		? $identity['lstat'] : $identity;
	if(!isset($stat['dev']) || !isset($stat['ino']) || !isset($stat['mode']))
		return(false);
	$typeBits = $stat['mode'] & 0170000;
	$type = $typeBits === 0120000 ? 'l'
		: ($typeBits === 0040000 ? 'd' : ($typeBits === 0100000 ? 'f' : 'o'));
	return(array(
		'dev' => (string)$stat['dev'],
		'ino' => (string)$stat['ino'],
		'type' => $type,
	));
}

function erasedataSameEntryIdentity($expected, $current)
{
	$expectedParts = erasedataEntryIdentityParts($expected);
	$currentParts = erasedataEntryIdentityParts($current);
	return($expectedParts !== false && $currentParts !== false
		&& $expectedParts === $currentParts);
}

function erasedataCapturedEntryPrefix($path, $reservationKey)
{
	return(dirname($path).'/.erasedata-entry-'.hash(
		'sha256', $reservationKey."\0".basename($path)).'-');
}

function erasedataCapturedEntryNamePath($root)
{
	return($root.'/.name');
}

function erasedataCapturedEntryDataPath($root)
{
	return($root.'/entry');
}

function erasedataCapturedEntryRoots($path, $reservationKey,
	ErasedataFilesystemOps $filesystem)
{
	$parent = dirname($path);
	$entries = $filesystem->scanDirectory($parent);
	if($entries === false)
		return(erasedataPathExists($parent) ? false : array());
	$prefix = basename(erasedataCapturedEntryPrefix($path, $reservationKey));
	$roots = array();
	foreach($entries as $entry)
		if(strpos($entry, $prefix) === 0
			&& preg_match('/^[0-9]+-[0-9]+-[ldfo]-[a-f0-9]{32}$/D',
				substr($entry, strlen($prefix))))
		{
			$root = $parent.'/'.$entry;
			if(erasedataDiscardCompletedCapturedRoot($root, $reservationKey, $filesystem)) continue;
			if(!erasedataPathExists($root) && erasedataPathLookupAnswers($root)) continue;
			if(!erasedataPrivateMarkerIsValid($root,
				$reservationKey === 'manifest-consumption' ? null : $reservationKey))
			{
				if(erasedataDiscardEmptyUnsignedRoot($root)) continue;
				FileUtil::toLog('erasedata: private root retained: unsigned or changed '.basename($root));
				return(false);
			}
			$roots[] = $root;
		}
	return($roots);
}

function erasedataCapturedEntryName($root)
{
	$namePath = erasedataCapturedEntryNamePath($root);
	if(!erasedataPrivateMarkerIsValid($root)
		|| !is_file($namePath) || is_link($namePath))
		return(false);
	// Bounded while reading: a name record longer than the ceiling is refused
	// without ever being allocated whole.
	$encoded = ErasedataManifestCodec::readBoundedFile(
		$namePath, ErasedataManifestCodec::MAX_CAPTURED_NAME_BYTES);
	$name = is_string($encoded)
		? ErasedataManifestCodec::decodeCanonicalBase64($encoded) : false;
	if($name === false || $name === '' || $name === '.' || $name === '..'
		|| strpos($name, '/') !== false || strpos($name, "\0") !== false)
		return(false);
	return($name);
}

function erasedataCapturedEntryRootInfo($root, $path, $reservationKey,
	ErasedataFilesystemOps $filesystem)
{
	$prefix = erasedataCapturedEntryPrefix($path, $reservationKey);
	if(strpos($root, $prefix) !== 0 || erasedataCapturedEntryName($root) !== basename($path))
		return(false);
	$suffix = substr($root, strlen($prefix));
	if(!preg_match('/^([0-9]+)-([0-9]+)-([ldfo])-[a-f0-9]{32}$/D',
		$suffix, $matches))
		return(false);
	$rootIdentity = $filesystem->entryIdentity($root);
	if(!is_array($rootIdentity) || empty($rootIdentity['is_dir']))
		return(false);
	return(array(
		'root' => $root,
		'entry' => erasedataCapturedEntryDataPath($root),
		'identity' => array(
			'dev' => $matches[1],
			'ino' => $matches[2],
			'type' => $matches[3],
		),
	));
}

function erasedataCreateCapturedEntryRoot($path, $reservationKey, $expected,
	ErasedataFilesystemOps $filesystem)
{
	$parts = erasedataEntryIdentityParts($expected);
	$token = erasedataPrivateToken();
	if($parts === false || $token === false)
		return(false);
	$root = erasedataCapturedEntryPrefix($path, $reservationKey)
		.$parts['dev'].'-'.$parts['ino'].'-'.$parts['type'].'-'.$token;
	if(!erasedataProtectPrivateParent($root)
		|| !$filesystem->makeDirectory($root, 0700))
		return(false);
	$namePath = erasedataCapturedEntryNamePath($root);
	$name = base64_encode(basename($path));
	$written = @file_put_contents($namePath, $name, LOCK_EX);
	@chmod($namePath, 0600);
	if($written !== strlen($name) || !erasedataCreatePrivateMarker($root,
			$reservationKey === 'manifest-consumption' ? null : $reservationKey))
	{
		$filesystem->unlink($namePath);
		$filesystem->unlink(erasedataPrivateMarkerPath($root));
		$filesystem->removeDirectory($root);
		return(false);
	}
	return($root);
}

function erasedataCapturedEntryBridgeMatches($path, $entry,
	ErasedataFilesystemOps $filesystem)
{
	$target = basename(dirname($entry)).'/'.basename($entry);
	return(is_link($path) && $filesystem->readLink($path) === $target);
}

function erasedataPublishCapturedEntryBridge($path, $entry,
	ErasedataFilesystemOps $filesystem)
{
	if(erasedataCapturedEntryBridgeMatches($path, $entry, $filesystem))
		return(true);
	$target = basename(dirname($entry)).'/'.basename($entry);
	if(erasedataPathExists($path) || !$filesystem->makeSymlink($target, $path))
		return(false);
	return(erasedataCapturedEntryBridgeMatches($path, $entry, $filesystem));
}

function erasedataRemoveCapturedEntryBridge($path, $entry, $root,
	ErasedataFilesystemOps $filesystem, $publishOnMismatch = true)
{
	$entries = $filesystem->scanDirectory($root);
	if($entries === false)
		return(false);
	$tombstones = array_values(array_filter($entries, function($name) {
		return((bool)preg_match('/^\.bridge-([0-9]+)-([0-9]+)$/D', $name));
	}));
	if(count($tombstones) > 1)
		return(false);
	$target = basename(dirname($entry)).'/'.basename($entry);
	$renamed = false;
	if(!count($tombstones))
	{
		if(!erasedataCapturedEntryBridgeMatches($path, $entry, $filesystem))
			return(!erasedataPathExists($path));
		$expected = $filesystem->entryIdentity($path);
		if(!is_array($expected) || empty($expected['is_link'])
			|| $filesystem->readLink($path) !== $target)
			return(false);
		$tombstone = $root.'/.bridge-'.$expected['dev'].'-'.$expected['ino'];
		if(erasedataPathExists($tombstone)
			|| !$filesystem->rename($path, $tombstone))
			return(false);
		$renamed = true;
	}
	else
		$tombstone = $root.'/'.$tombstones[0];

	if(!preg_match('/^\.bridge-([0-9]+)-([0-9]+)$/D',
		basename($tombstone), $matches))
		return(false);
	$current = $renamed ? $filesystem->renamedEntryIdentity($tombstone)
		: $filesystem->entryIdentity($tombstone);
	if(!is_array($current) || empty($current['is_link'])
		|| (string)$current['dev'] !== $matches[1]
		|| (string)$current['ino'] !== $matches[2]
		|| $filesystem->readLink($tombstone) !== $target)
	{
		if($publishOnMismatch && !erasedataPathExists($path))
			$filesystem->makeSymlink(
				basename($root).'/'.basename($tombstone), $path);
		return(false);
	}
	if(erasedataPathExists($path) || !$filesystem->unlink($tombstone))
		return(false);
	return(!erasedataPathExists($tombstone));
}

function erasedataRemoveCapturedEntryRoot($root,
	ErasedataFilesystemOps $filesystem)
{
	$entries = $filesystem->scanDirectory($root);
	$namePath = erasedataCapturedEntryNamePath($root);
	$marker = erasedataPrivateMarkerPath($root);
	if($entries === false || count(array_diff($entries, array(
		'.', '..', basename($namePath), basename($marker)))) > 0)
		return(false);
	if(!$filesystem->unlink($namePath) && erasedataPathExists($namePath))
		return(false);
	if(!$filesystem->unlink($marker) && erasedataPathExists($marker))
		return(false);
	return($filesystem->removeDirectory($root) || !erasedataPathExists($root));
}

// Cleanup removes .name before .initialized. A crash there leaves no public
// basename, but the signed marker still authenticates the root and parent
// inodes. Retire only that exact metadata shell; never resume payload work or
// remove a public bridge from a shell whose name record has gone.
function erasedataDiscardCompletedCapturedRoot($root, $reservationKey,
	ErasedataFilesystemOps $filesystem)
{
	if(preg_match('/^\.erasedata-entry-[a-f0-9]{64}-[0-9]+-[0-9]+-[ldfo]-[a-f0-9]{32}$/D',
		basename($root)) !== 1 || !erasedataProtectPrivateParent($root)) return(false);
	$uid = erasedataEffectiveUid();
	$parent = dirname($root);$parentStat = @lstat($parent);
	clearstatcache(true, $root);
	$rootStat = @lstat($root);
	$contents = $filesystem->scanDirectory($root);
	$marker = erasedataPrivateMarkerPath($root);
	clearstatcache(true, $marker);
	$markerStat = @lstat($marker);
	if($uid === false || !is_array($parentStat) || !is_array($rootStat)
		|| $rootStat['uid'] !== $uid || ($rootStat['mode'] & 0177777) !== 0040700
		|| !erasedataLeafDirectoryLinks($rootStat['nlink']) || !is_array($contents)
		|| array_values(array_diff($contents, array('.', '..'))) !== array(basename($marker))
		|| !is_array($markerStat) || $markerStat['uid'] !== $uid
		|| ($markerStat['mode'] & 0177777) !== 0100600 || $markerStat['nlink'] !== 1
		|| $markerStat['size'] !== ($reservationKey === 'manifest-consumption' ? 0 : 64)) return(false);
	$handle = @fopen($marker, 'rb');
	if(!is_resource($handle)) return(false);
	try
	{
		$opened = @fstat($handle);
		if(!is_array($opened) || !erasedataSameStatIdentity($opened, $markerStat)) return(false);
		$stored = ErasedataManifestCodec::readBoundedHandle($handle, 64);
		$signature = $reservationKey === 'manifest-consumption' ? ''
			: erasedataPrivateRootSignature($root, $reservationKey, false);
		if(!is_string($signature) || !is_string($stored) || !hash_equals($signature, $stored)) return(false);
		$entries = $filesystem->scanDirectory($parent);
		if(!is_array($entries)) return(false);
		foreach($entries as $entry)
		{
			if($entry === '.' || $entry === '..') continue;
			$path = $parent.'/'.$entry;
			$stat = erasedataListedEntryStat($parent, $entry);
			if($stat === null) continue;
			if(!is_array($stat)) return(false);
			if(($stat['mode'] & 0170000) === 0120000)
			{
				$target = $filesystem->readLink($path);
				if(!is_string($target) || strpos($target, basename($root)) !== false) return(false);
			}
		}
		clearstatcache();
		$currentContents = $filesystem->scanDirectory($root);
		$currentRoot = @lstat($root);$currentMarker = @lstat($marker);
		if(!erasedataSameStatIdentity($parentStat, @lstat($parent))
			|| !erasedataSameStatIdentity($rootStat, $currentRoot)
			|| !erasedataSameStatIdentity($markerStat, $currentMarker)
			|| !is_array($currentContents)
			|| array_values(array_diff($currentContents, array('.', '..'))) !== array(basename($marker))) return(false);
		foreach(array('uid','mode','nlink') as $field)
			if($currentRoot[$field] !== $rootStat[$field]) return(false);
		foreach(array('uid','mode','nlink','size') as $field)
			if($currentMarker[$field] !== $markerStat[$field]) return(false);
		if(!@rewind($handle) || ErasedataManifestCodec::readBoundedHandle($handle,64) !== $stored
			|| !erasedataProtectPrivateParent($root)) return(false);
		if(!$filesystem->unlink($marker)) return(false);
		return($filesystem->removeDirectory($root) || (!erasedataPathExists($root) && erasedataPathLookupAnswers($root)));
	}
	finally { @fclose($handle); }
}

function erasedataDeleteCapturedEntry($path, $expected, $reservationKey,
	ErasedataFilesystemOps $filesystem, $emptyDirectoryOnly = false)
{
	$roots = erasedataCapturedEntryRoots($path, $reservationKey, $filesystem);
	if($roots === false || count($roots) > 1)
		return(false);
	$captured = false;
	if(!count($roots))
	{
		if(!is_array($expected))
			return(!erasedataPathExists($path));
		$root = erasedataCreateCapturedEntryRoot(
			$path, $reservationKey, $expected, $filesystem);
		if($root === false)
			return(false);
		$entry = erasedataCapturedEntryDataPath($root);
		if(!$filesystem->rename($path, $entry))
		{
			erasedataRemoveCapturedEntryRoot($root, $filesystem);
			return(false);
		}
		$roots = array($root);
		$captured = true;
	}

	$info = erasedataCapturedEntryRootInfo(
		$roots[0], $path, $reservationKey, $filesystem);
	if($info === false)
		return(false);
	// A negative answer for an entry captured just now is not a consumed
	// capture: the bridge-and-root branch below would otherwise take it as one.
	$entryIdentity = $captured ? $filesystem->renamedEntryIdentity($info['entry'])
		: $filesystem->entryIdentity($info['entry']);
	if($entryIdentity === false)
	{
		if(erasedataCapturedEntryBridgeMatches($path, $info['entry'], $filesystem)
			|| !erasedataPathExists($path))
		{
			if(!erasedataRemoveCapturedEntryBridge(
				$path, $info['entry'], $info['root'], $filesystem))
				return(false);
		}
		else if(erasedataPathExists($path))
		{
			$entries = $filesystem->scanDirectory($info['root']);
			if($entries === false || count(array_filter($entries, function($name) {
				return(strpos($name, '.bridge-') === 0);
			})) > 0)
				return(false);
			$current = $filesystem->entryIdentity($path);
			if(!is_array($current)
				|| erasedataEntryIdentityParts($current) !== $info['identity'])
				return(false);
			if(!erasedataRemoveCapturedEntryRoot($info['root'], $filesystem))
				return(false);
			return(erasedataDeleteCapturedEntry(
				$path, $current, $reservationKey, $filesystem, $emptyDirectoryOnly));
		}
		return(erasedataRemoveCapturedEntryRoot($info['root'], $filesystem));
	}

	if(erasedataEntryIdentityParts($entryIdentity) !== $info['identity'])
	{
		erasedataPublishCapturedEntryBridge($path, $info['entry'], $filesystem);
		return(false);
	}
	if(!erasedataPublishCapturedEntryBridge($path, $info['entry'], $filesystem))
		return(false);

	if(!empty($entryIdentity['is_dir']))
	{
		$reference = $filesystem->openDirectoryReference($info['entry'], $entryIdentity);
		if($reference === false)
			return(false);
		if($emptyDirectoryOnly)
		{
			$referencePath = is_array($reference) && isset($reference['path'])
				? $reference['path'] : $reference;
			$entries = $filesystem->scanDirectory($referencePath);
			$deleted = is_array($entries)
				&& count(array_diff($entries, array('.', '..'))) === 0;
		}
		else
			$deleted = erasedataDeleteDirectoryReferenceContents(
				$reference, $reservationKey, $filesystem);
		$filesystem->closeDirectoryReference($reference);
		$current = $filesystem->entryIdentity($info['entry']);
		if(!$deleted || !erasedataSameEntryIdentity($entryIdentity, $current)
			|| !$filesystem->removeDirectory($info['entry']))
			return(false);
	}
	else if(!$filesystem->unlink($info['entry']))
		return(false);

	if(erasedataPathExists($info['entry'])
		|| !erasedataRemoveCapturedEntryBridge(
			$path, $info['entry'], $info['root'], $filesystem))
		return(false);
	return(erasedataRemoveCapturedEntryRoot($info['root'], $filesystem));
}

// A false return here stops the caller's whole pass, not one entry of it, so
// $blocker carries out the single leftover that could not be got past. Callers
// that only branch on the boolean are unaffected; the one that reports to an
// operator has something to name.
function erasedataResumeCapturedEntries($parent, $reservationKey,
	ErasedataFilesystemOps $filesystem, &$blocker = null)
{
	$entries = $filesystem->scanDirectory($parent);
	if($entries === false)
	{
		$blocker = $parent;
		return(false);
	}
	foreach($entries as $entry)
	{
		if(strpos($entry, '.erasedata-entry-') !== 0)
			continue;
		$root = $parent.'/'.$entry;
		if(erasedataDiscardCompletedCapturedRoot($root, $reservationKey, $filesystem)) continue;
		if(!erasedataPathExists($root) && erasedataPathLookupAnswers($root)) continue;
		if($reservationKey !== 'manifest-consumption'
			&& !erasedataPrivateMarkerIsValid($root, $reservationKey))
		{
			if(erasedataDiscardEmptyUnsignedRoot($root)) continue;
			FileUtil::toLog('erasedata: private root retained: unsigned or changed '.basename($root));
			$blocker = $root;
			return(false);
		}
		$name = erasedataCapturedEntryName($root);
		if($name === false)
		{
			// A crash inside erasedataCreateCapturedEntryRoot(), in the window
			// between makeDirectory() and the .name/.initialized writes, leaves
			// this root EMPTY, and an empty one has nothing in it to lose.
			// rmdir() is the entire heal: it is atomic, it refuses a directory
			// that holds anything, and it refuses a symlink, so the only thing
			// it can ever take away is that window's residue -- including when
			// the process still inside the window races this one, because the
			// creator's next act is to write .name, which fails against a
			// removed directory and is retried whole. A root that still holds
			// bytes is not this function's to guess at: it is named and
			// refused, and the caller has to say so.
			$contents = $filesystem->scanDirectory($root);
			if(is_array($contents)
				&& count(array_diff($contents, array('.', '..'))) === 0
				&& $filesystem->removeDirectory($root))
				continue;
			$blocker = $root;
			return(false);
		}
		$path = $parent.'/'.$name;
		if(strpos($root, erasedataCapturedEntryPrefix($path, $reservationKey)) !== 0)
			continue;
		if(!erasedataDeleteCapturedEntry($path, null, $reservationKey, $filesystem))
		{
			$blocker = $root;
			return(false);
		}
	}
	return(true);
}

function erasedataDeleteDirectoryReferenceContents($reference, $reservationKey,
	ErasedataFilesystemOps $filesystem)
{
	$referencePath = is_array($reference) && isset($reference['path'])
		? $reference['path'] : $reference;
	if(!erasedataResumeCapturedEntries(
		$referencePath, $reservationKey, $filesystem))
		return(false);
	$files = $filesystem->scanDirectory($referencePath);
	if($files === false)
		return(false);
	foreach(array_diff($files, array('.', '..')) as $file)
	{
		if(strpos($file, '.erasedata-entry-') === 0)
			return(false);
		$child = $referencePath.'/'.$file;
		$identity = $filesystem->entryIdentity($child);
		if(!is_array($identity)
			|| !erasedataDeleteCapturedEntry(
				$child, $identity, $reservationKey, $filesystem))
			return(false);
	}
	return(true);
}

// The obligation store the durable primitives above exist for. It is required
// here, at the end, rather than at the top of pending.php alone, so that every
// entry point that already loads the filesystem seam also has the queue: the
// two files are one layer and require_once resolves the pair from either side.
require_once(dirname(__FILE__)."/pending.php");
