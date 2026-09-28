<?php

// ErasedataManifestCodec owns the bounded reads and the canonical-base64 rule
// this file reuses for the captured-entry name record.
require_once(dirname(__FILE__)."/manifest.php");

// Small, plugin-local seam for the filesystem mutations and identity reads the
// erasedata collector performs. Only operations that must be scriptable in the
// destructive-race tests live here; pure validation and stable read-only PHP
// calls stay inline in the collector.
class ErasedataFilesystemOps
{
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

	public function rename($from, $to)
	{
		// In a sticky public parent, another UID may still rename its own
		// source. Do not capture such an entry into a private root.
		if((strpos($to, '/.erasedata-rmdir-') !== false
			|| strpos($to, '/.erasedata-entry-') !== false)
			&& !erasedataCaptureSourceOwned($from))
			return(false);
		return(@rename($from, $to));
	}

	// PHP rename() may replace $to. The image builds this narrow Linux helper
	// from this plugin's source; an absent or unsupported helper refuses restore.
	public function renameNoReplace($from, $to)
	{
		$helper = '/usr/local/bin/rutorrent-erasedata-rename-noreplace';
		if(!is_executable($helper))
			return(false);
		$descriptors = array(
			0 => array('file', '/dev/null', 'r'),
			1 => array('file', '/dev/null', 'w'),
			2 => array('file', '/dev/null', 'w'),
		);
		$process = @proc_open(array($helper, $from, $to), $descriptors, $pipes);
		return(is_resource($process) && proc_close($process) === 0);
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

// Queue admission observes old writable ancestors and holds their contents.
// New private payload roots may seal service-owned writable ancestors before
// capture. Lexical links and their resolved targets are checked separately.
function erasedataGuardParentChain($path, $uid, $allowLinks, &$unsealed, $sealData = false)
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
			FileUtil::toLog('erasedata: '.($sealData ? 'private-root' : 'queue').' refused: unsafe ancestor '.$dir);
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
				FileUtil::toLog('erasedata: '.($sealData ? 'private-root' : 'queue').' refused: unsupported writable ancestor '.$dir);
				return(false);
			}
			$unsealed = true;
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
	if(function_exists('posix_geteuid'))
		return(posix_geteuid());
	$status = @file_get_contents('/proc/self/status');
	if(is_string($status) && preg_match('/^Uid:[ \t]*[0-9]+[ \t]+([0-9]+)/m', $status, $match))
		return((int)$match[1]);
	return(false);
}

// A listing is only a snapshot. Another trusted worker may finish and unlink
// its staging entry before we can inspect that name.
function erasedataQueueEntriesOwned($physicalPath, array $entries, $uid, $listPath)
{
	foreach($entries as $entry)
	{
		if($entry === '.' || $entry === '..')
			continue;
		$entryPath = $physicalPath.'/'.$entry;
		clearstatcache(true, $entryPath);
		$stat = @lstat($entryPath);
		if(!is_array($stat))
		{
			$current = @scandir($physicalPath);
			if(is_array($current) && !in_array($entry, $current, true))
				continue;
		}
		if(!is_array($stat) || $stat['uid'] !== $uid)
		{
			FileUtil::toLog('erasedata: queue refused: foreign or unreadable entry in '.$listPath);
			return(false);
		}
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
	if(!erasedataGuardParentChain($listPath, $uid, true, $unsealed))
		return(false);
	$parent = @realpath(dirname($listPath));
	if($parent === false)
	{
		FileUtil::toLog('erasedata: queue refused: unresolved parent '.$listPath);
		return(false);
	}
	$physicalPath = rtrim($parent, '/').'/'.basename($listPath);
	if(!erasedataGuardParentChain($physicalPath, $uid, false, $unsealed))
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
		FileUtil::toLog('erasedata: queue refused: legacy writable path needs operator attestation; cleanup held '.$listPath);
		return(false);
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
		FileUtil::toLog('erasedata: queue refused: cannot seal queue '.$listPath);
		return(false);
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
	}
	else
		$tombstone = $root.'/'.$tombstones[0];

	if(!preg_match('/^\.bridge-([0-9]+)-([0-9]+)$/D',
		basename($tombstone), $matches))
		return(false);
	$current = $filesystem->entryIdentity($tombstone);
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

function erasedataDeleteCapturedEntry($path, $expected, $reservationKey,
	ErasedataFilesystemOps $filesystem, $emptyDirectoryOnly = false)
{
	$roots = erasedataCapturedEntryRoots($path, $reservationKey, $filesystem);
	if($roots === false || count($roots) > 1)
		return(false);
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
	}

	$info = erasedataCapturedEntryRootInfo(
		$roots[0], $path, $reservationKey, $filesystem);
	if($info === false)
		return(false);
	$entryIdentity = $filesystem->entryIdentity($info['entry']);
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
