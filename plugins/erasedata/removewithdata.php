<?php

// Shared admission and durable deletion obligations for the web and CLI
// removal entrypoints. The legacy producer remains for compatibility; active
// entrypoints use erasedataAdmitRemoval(). The caller loads php/xmlrpc.php.
if(!defined('ERASEDATA_TORRENT_PRESENT'))
	define('ERASEDATA_TORRENT_PRESENT', 1);
if(!defined('ERASEDATA_TORRENT_ABSENT'))
	define('ERASEDATA_TORRENT_ABSENT', 0);
if(!defined('ERASEDATA_TORRENT_UNKNOWN'))
	define('ERASEDATA_TORRENT_UNKNOWN', -1);
if(!defined('ERASEDATA_CLEANUP_NONE'))
	define('ERASEDATA_CLEANUP_NONE', 'none');
if(!defined('ERASEDATA_CLEANUP_READY'))
	define('ERASEDATA_CLEANUP_READY', 'ready');
if(!defined('ERASEDATA_CLEANUP_RETRY'))
	define('ERASEDATA_CLEANUP_RETRY', 'retry');
if(!defined('ERASEDATA_FILE_ALIAS_SAME'))
	define('ERASEDATA_FILE_ALIAS_SAME', 1);
if(!defined('ERASEDATA_FILE_ALIAS_DISTINCT'))
	define('ERASEDATA_FILE_ALIAS_DISTINCT', 0);
if(!defined('ERASEDATA_FILE_ALIAS_UNKNOWN'))
	define('ERASEDATA_FILE_ALIAS_UNKNOWN', -1);

// The shared file mode has exactly one owner, and it is filesystem.php: the
// durable writer and the obligation store are built out of it and must not have
// to load the RPC layer to get at it. This file requires filesystem.php below,
// so erasedataSharedFileMode() is always defined by the time anything calls it.
if(!function_exists('erasedataRepairFileMode'))
{
	function erasedataRepairFileMode($path)
	{
		@chmod($path, erasedataSharedFileMode());
	}
}

if(!function_exists('erasedataTorrentPresence'))
{
	function erasedataTorrentPresence($hash)
	{
		$probe = new rXMLRPCRequest( new rXMLRPCCommand( getCmd("d.hash"), $hash ) );
		$probe->important = false;
		if(!$probe->run())
			return(ERASEDATA_TORRENT_UNKNOWN);
		if($probe->fault)
		{
			$msg = isset($probe->rawFaultString) && is_string($probe->rawFaultString)
				? $probe->rawFaultString
				: (isset($probe->faultString) && is_string($probe->faultString) ? $probe->faultString : '');
			$missingFaults = array(
				'info-hash not found',
				'info-hash not found.',
				'could not find info-hash',
				'could not find info-hash.',
				'invalid parameters: info-hash not found',
			);
			if(in_array(strtolower($msg), $missingFaults, true))
				return(ERASEDATA_TORRENT_ABSENT);
			return(ERASEDATA_TORRENT_UNKNOWN);
		}
		if(count($probe->val) !== 1 || !is_string($probe->val[0]))
			return(ERASEDATA_TORRENT_UNKNOWN);
		return(strcasecmp($probe->val[0], $hash) === 0 ? ERASEDATA_TORRENT_PRESENT : ERASEDATA_TORRENT_UNKNOWN);
	}
}

if(!function_exists('erasedataAcquireHashLock'))
{
	function erasedataAcquireHashLock($listPath, $hash, $nonBlocking = false)
	{
		$lockPath = $listPath.'/'.$hash.'.lock';
		$lock = @fopen($lockPath, 'c');
		if($lock === false)
			return(false);
		erasedataRepairFileMode($lockPath);
		$operation = LOCK_EX | ($nonBlocking ? LOCK_NB : 0);
		if(!@flock($lock, $operation))
		{
			@fclose($lock);
			return(false);
		}
		return($lock);
	}
}

if(!function_exists('erasedataReleaseHashLock'))
{
	function erasedataReleaseHashLock($lock)
	{
		if(is_resource($lock))
		{
			@flock($lock, LOCK_UN);
			@fclose($lock);
		}
	}
}

if(!function_exists('erasedataLoadTorrentSource'))
{
	// Keep the metainfo lookup replaceable in the collector regression harness.
	function erasedataLoadTorrentSource($hash)
	{
		if(!class_exists('rTorrent', false))
			require_once(dirname(__FILE__)."/../../php/rtorrent.php");
		return(rTorrent::getSource($hash));
	}
}

if(!function_exists('erasedataPhysicalRelativePath'))
{
	function erasedataPhysicalRelativePath($components)
	{
		if(!is_array($components) || !count($components))
			return(false);
		$ret = array();
		foreach($components as $component)
		{
			if(!is_string($component) || $component === '' || $component === '.' || $component === '..'
				|| strpos($component, "\0") !== false || strpos($component, '/') !== false
				|| strpos($component, '\\') !== false)
				return(false);
			$ret[] = $component;
		}
		return(implode('/', $ret));
	}
}

if(!function_exists('erasedataPhysicalMetainfoPlan'))
{
	function erasedataPhysicalMetainfoPlan($hash)
	{
		$canonicalHash = erasedataCanonicalHash($hash);
		$source = $canonicalHash === false ? false : erasedataLoadTorrentSource($canonicalHash);
		if(!is_object($source) || !method_exists($source, 'hash_info')
			|| !isset($source->info) || !is_array($source->info)
			|| erasedataCanonicalHash($source->hash_info()) !== $canonicalHash)
			return(false);
		$info = $source->info;
		$single = array_key_exists('length', $info);
		$multi = array_key_exists('files', $info);
		if($single === $multi)
			return(false);
		$relative = array();
		$physical = array();
		if($single)
		{
			if(!array_key_exists('name', $info)
				|| ($path = erasedataPhysicalRelativePath(array($info['name']))) === false)
				return(false);
			$relative[] = $path;
			$physical[] = true;
		}
		else
		{
			if(!is_array($info['files']) || !count($info['files']))
				return(false);
			foreach($info['files'] as $file)
			{
				if(!is_array($file) || !array_key_exists('path', $file)
					|| ($path = erasedataPhysicalRelativePath($file['path'])) === false)
					return(false);
				$padding = false;
				if(array_key_exists('attr', $file))
				{
					if(!is_string($file['attr']))
						return(false);
					$padding = strpos($file['attr'], 'p') !== false;
				}
				$relative[] = $path;
				$physical[] = !$padding;
			}
		}
		return(array('multi' => $multi ? 1 : 0, 'relative' => $relative, 'physical' => $physical));
	}
}

if(!function_exists('erasedataPhysicalMultiValue'))
{
	function erasedataPhysicalMultiValue($value)
	{
		if($value === 0 || $value === '0' || $value === false)
			return(0);
		if($value === 1 || $value === '1' || $value === true)
			return(1);
		return(false);
	}
}

if(!function_exists('erasedataCollectPhysicalPaths'))
{
	function erasedataCollectPhysicalPaths($hash)
	{
		$plan = erasedataPhysicalMetainfoPlan($hash);
		if($plan === false)
			return(false);
		$count = count($plan['relative']);
		$frozen = new rXMLRPCRequest( array(
			new rXMLRPCCommand( getCmd("d.get_base_path"), $hash ),
			new rXMLRPCCommand( getCmd("d.is_multi_file"), $hash ),
			new rXMLRPCCommand( getCmd("f.multicall"), array($hash, "", getCmd("f.get_frozen_path")."=") )
		) );
		$frozenOk = $frozen->success();
		if($frozenOk && (count($frozen->val) !== $count + 2
			|| erasedataPhysicalMultiValue($frozen->val[1]) !== $plan['multi']
			|| !is_string($frozen->val[0])))
			return(false);

		$stored = new rXMLRPCRequest( array(
			new rXMLRPCCommand( getCmd("d.get_directory"), $hash ),
			new rXMLRPCCommand( getCmd("d.is_multi_file"), $hash ),
			new rXMLRPCCommand( getCmd("f.multicall"), array($hash, "", getCmd("f.get_path")."=") )
		) );
		if(!$stored->success() || count($stored->val) !== $count + 2
			|| erasedataPhysicalMultiValue($stored->val[1]) !== $plan['multi']
			|| !is_string($stored->val[0]) || $stored->val[0] === '' || $stored->val[0][0] !== '/'
			|| strpos($stored->val[0], "\0") !== false)
			return(false);
		$directory = $stored->val[0] === '/' ? '/' : rtrim($stored->val[0], '/');
		if($directory === '')
			return(false);
		$storedFiles = array();
		for($index = 0; $index < $count; $index++)
		{
			$path = $stored->val[$index + 2];
			if(!is_string($path) || $path !== $plan['relative'][$index])
				return(false);
			$storedFiles[] = ($directory === '/' ? '/' : $directory.'/').$path;
		}

		$frozenFiles = array();
		$useFrozen = $frozenOk;
		if($frozenOk)
		{
			$expectedBase = $plan['multi'] ? $directory : $storedFiles[0];
			if($frozen->val[0] !== '' && $frozen->val[0] !== $expectedBase)
				return(false);
			for($index = 0; $index < $count; $index++)
			{
				$path = $frozen->val[$index + 2];
				if(!is_string($path) || strpos($path, "\0") !== false
					|| ($path !== '' && $path[0] !== '/'))
					return(false);
				$expectedPath = $plan['multi']
					? ($expectedBase === '/' ? '/' : $expectedBase.'/').$plan['relative'][$index]
					: $expectedBase;
				if($path !== '' && ($frozen->val[0] === '' || $path !== $expectedPath))
					return(false);
				$frozenFiles[] = $path;
				if($plan['physical'][$index] && $path === '')
					$useFrozen = false;
			}
		}
		$files = array();
		$seen = array();
		for($index = 0; $index < $count; $index++)
			if($plan['physical'][$index])
			{
				$path = $useFrozen ? $frozenFiles[$index] : $storedFiles[$index];
				$key = "p\0".$path;
				if(!isset($seen[$key]))
				{
					$seen[$key] = true;
					$files[] = $path;
				}
			}
		return(array(
			'base' => $plan['multi'] ? $directory : $storedFiles[0],
			'multi' => $plan['multi'] ? '1' : '0',
			'files' => $files,
		));
	}
}

if(!function_exists('erasedataCollectPaths'))
{
	// d.base_path and f.frozen_path are only filled in when rtorrent opens a
	// download's file list, and are not restored from the session. A download
	// that has not been opened since rtorrent started -- any torrent that was
	// stopped when the session was loaded -- reports both as empty, so fall
	// back to d.directory and f.path, which are always available.
	function erasedataCollectPaths($hash, $physicalOnly = false)
	{
		if($physicalOnly)
			return(erasedataCollectPhysicalPaths($hash));
		// rXMLRPCRequest flattens every returned value into ->val, so query one
		// torrent per request, and keep the variable-length f.multicall last:
		// val[0] = directory, val[1] = is_multi, val[2..] = each file path.
		$frozen = new rXMLRPCRequest( array(
			new rXMLRPCCommand( getCmd("d.get_base_path"), $hash ),
			new rXMLRPCCommand( getCmd("d.is_multi_file"), $hash ),
			new rXMLRPCCommand( getCmd("f.multicall"), array($hash, "", getCmd("f.get_frozen_path")."=") )
		) );
		if($frozen->success() && count($frozen->val) >= 3)
		{
			$files = array();
			foreach(array_slice($frozen->val, 2) as $path)
				if(strlen($path))
					$files[] = $path;
			if(count($files))
				return( array(
					"base"  => $frozen->val[0],
					"multi" => $frozen->val[1] ? "1" : "0",
					"files" => $files ) );
		}

		$stored = new rXMLRPCRequest( array(
			new rXMLRPCCommand( getCmd("d.get_directory"), $hash ),
			new rXMLRPCCommand( getCmd("d.is_multi_file"), $hash ),
			new rXMLRPCCommand( getCmd("f.multicall"), array($hash, "", getCmd("f.get_path")."=") )
		) );
		if(!$stored->success() || count($stored->val) < 3)
			return(false);
		$dir = rtrim($stored->val[0], '/');
		if(!strlen($dir))
			return(false);
		$isMulti = $stored->val[1] ? "1" : "0";
		$files = array();
		foreach(array_slice($stored->val, 2) as $path)
			if(strlen($path))
				$files[] = $dir.'/'.$path;
		if(!count($files))
			return(false);
		// d.directory is the download's root directory, which for a single-file
		// torrent is the directory holding the file, not the file itself --
		// d.base_path returns the file. Mirror that here.
		return( array(
			"base"  => $isMulti=="1" ? $dir : $files[0],
			"multi" => $isMulti,
			"files" => $files ) );
	}
}

require_once(dirname(__FILE__)."/manifest.php");
require_once(dirname(__FILE__)."/filesystem.php");

// A cleanup may discard only names and inodes unclaimed by every other torrent.
// Keep the scan in erasedata so producer and delayed consumer use one rule.
function erasedataCleanupOtherOwnerSnapshot($oldHash, $newHash, $marker, $record)
{
	$oldHash = erasedataCanonicalHash($oldHash);
	$newHash = erasedataCanonicalHash($newHash);
	if($oldHash === false || $newHash === false || $oldHash === $newHash
		|| !is_string($marker) || !preg_match('/^[a-fA-F0-9]{32}$/D', $marker)
		|| !is_string($record)
		|| !preg_match('/^([a-fA-F0-9]{40})-(started|open|stopped)-([1-9][0-9]*)$/D',
			$record, $parts) || strtoupper($parts[1]) !== $oldHash)
		return(false);
	$oldMarker = $newHash.'-'.$parts[2].'-'.$parts[3];
	$scan = new rXMLRPCRequest(new rXMLRPCCommand('d.multicall', array('main',
		getCmd('d.get_hash='),
		getCmd('d.get_custom=').'chk-replacement',
		getCmd('d.get_custom=').'chk-replaces',
		getCmd('d.get_custom=').'chk-replacing')));
	$scan->important = false;
	if(!$scan->success() || !is_array($scan->val) || count($scan->val) % 4 !== 0)
		return(false);
	$seenHashes = array();
	$paths = array();
	$inodes = array();
	$bindings = array();
	for($index = 0; $index < count($scan->val); $index += 4)
	{
		$row = array_slice($scan->val, $index, 4);
		$hash = erasedataCanonicalHash($row[0]);
		if($hash === false || !is_string($row[0]) || strtoupper($row[0]) !== $hash
			|| !is_string($row[1]) || !is_string($row[2]) || !is_string($row[3])
			|| isset($seenHashes[$hash]))
			return(false);
		$seenHashes[$hash] = true;
		if($hash === $oldHash && $row[1] === '' && $row[2] === ''
			&& $row[3] === $oldMarker)
			continue;
		if($hash === $newHash && $row[1] === $marker && $row[2] === $record
			&& $row[3] === '')
			continue;
		$owned = erasedataCollectPhysicalPaths($hash);
		if(!is_array($owned) || !isset($owned['files']) || !is_array($owned['files']))
			return(false);
		foreach($owned['files'] as $file)
		{
			$identity = erasedataPathIdentity($file);
			if(!is_array($identity) || !isset($identity['path']))
				return(false);
			$key = "p\0".$identity['path'];
			$paths[$key] = true;
			if(!isset($bindings[$key])) $bindings[$key] = array();
			$bindings[$key]["r\0".$file] = true;
			if(!empty($identity['exists']))
			{
				if(!isset($identity['stat']['dev'], $identity['stat']['ino']))
					return(false);
				$inodes['i:'.$identity['stat']['dev'].':'.$identity['stat']['ino']] = true;
			}
		}
	}
	return(array('paths' => $paths, 'inodes' => $inodes,
		'bindings' => $bindings));
}

function erasedataCleanupOtherOwnerState($snapshot, $path, $stat)
{
	if(!is_array($snapshot) || !isset($snapshot['paths'], $snapshot['inodes'])
		|| !is_array($snapshot['paths']) || !is_array($snapshot['inodes'])
		|| !is_string($path) || !is_array($stat)
		|| !isset($stat['dev'], $stat['ino']))
		return('unknown');
	$identity = erasedataPathIdentity($path);
	if(!is_array($identity) || !isset($identity['path']))
		return('unknown');
	return(isset($snapshot['paths']["p\0".$identity['path']])
		|| isset($snapshot['inodes']['i:'.$stat['dev'].':'.$stat['ino']])
		? 'claimed' : 'unclaimed');
}

if(!function_exists('erasedataCanonicalHash'))
{
	function erasedataCanonicalHash($hash)
	{
		return(is_string($hash) && preg_match('/^[0-9A-Fa-f]{40}$/D', $hash) ? strtoupper($hash) : false);
	}
}

if(!function_exists('erasedataCleanupArtifactNameParts'))
{
	// Shared filename grammar for collection and drain retirement/rearm.
	function erasedataCleanupArtifactNameParts($name)
	{
		if(!is_string($name))
			return(false);
		return(preg_match('/^([0-9A-Fa-f]{40})\.cleanup\.([0-9]+)\.([A-Za-z0-9]+(?:\.[A-Za-z0-9]+)*)\.(list|tmp)$/D',
			$name, $matches) === 1 ? $matches : false);
	}
}

if(!function_exists('erasedataParseCollectorCandidate'))
{
	// Tagged cleanup names deliberately do not match the historic scanner. An
	// older collector therefore leaves a prepared v3 job untouched on downgrade.
	function erasedataParseCollectorCandidate($listPath, $file, &$malformed = null)
	{
		$malformed = null;
		if(!is_string($listPath) || !is_string($file) || $file === '' || strpos($file, '/') !== false)
			return(false);
		$operation = false;
		if(($matches = erasedataCleanupArtifactNameParts($file)) !== false)
			$operation = ErasedataManifestCodec::OPERATION_CLEANUP_OBSOLETE;
		// The deployed remove-payload alternative is kept byte-for-byte as the
		// second branch. The first is purely additive and exists because an
		// admitted manifest carries its 16-lowercase-hex generation as the
		// component right after the hash: a generation holding a hex letter
		// does not match [0-9]+. Generations start at 0000000000000000 and are
		// incremented by one, so the first such name is 000000000000000a -- the
		// tenth admitted generation -- and letter-bearing generations then recur
		// in bands (...000a-f, ...001a-f, ...). It is not a threshold crossed
		// once: 0000000000000010-19 still match the historic alternative.
		// Without this branch every manifest whose generation carries a letter
		// would be invisible to the collector and its payload would never be
		// deleted. Both alternatives are non-capturing, so $matches keeps its
		// historic shape.
		else if(preg_match('/^([0-9A-Fa-f]{40})(?:\.[0-9a-f]{16}\.[A-Za-z0-9._-]+|\.[0-9]+\.[A-Za-z0-9._-]+)?\.(list|tmp)$/D', $file, $matches))
		{
			// A `.tmp` that carries a 16-lowercase-hex generation right after the
			// hash is LIVE drain staging: it is bound by a journal record, it is
			// owned by the producer or the guarded worker under that hash's lock,
			// and only they may publish it -- and only for a download rTorrent
			// individually confirmed is gone.
			//
			// The periodic collector used to see it as an ordinary legacy staging
			// file and rename it to a final `.list`. That published a payload
			// manifest -- a licence to delete the payload -- for a download that
			// was still live and had never been erased, and it made
			// erasedataPublishedGenerations() report the generation as published,
			// which hid the whole obligation from BOTH obligation readers and
			// abandoned it for ever. So a generation-bearing staging name is not a
			// collector candidate at all: it is invisible here, untouched, and
			// stays the drain protocol's to resolve or to cancel.
			//
			// The published form of the same job, `<hash>.<generation>.<token>.list`,
			// is deliberately still a candidate: that IS the collector's work.
			if($matches[count($matches) - 1] === 'tmp'
				&& preg_match('/^[0-9A-Fa-f]{40}\.[0-9a-f]{16}\./D', $file) === 1)
				return(false);
			$operation = ErasedataManifestCodec::OPERATION_REMOVE_PAYLOAD;
		}
		else if(strlen($file) > 49 && substr($file, 40, 9) === '.cleanup.'
			&& ($hash = erasedataCanonicalHash(substr($file, 0, 40))) !== false)
		{
			$lastDot = strrpos($file, '.');
			$malformed = array(
				'hash' => $hash,
				// uniqid('', true) contains a dot. Preserve every dotted unique
				// component and remove only the malformed file's final suffix.
				'stem' => $lastDot !== false && $lastDot > 48 ? substr($file, 0, $lastDot) : $file,
				'path' => $listPath.'/'.$file,
				'file' => $file,
			);
			return(false);
		}
		if($operation === false)
			return(false);
		$path = $listPath.'/'.$file;
		$stat = @lstat($path);
		$regular = !is_link($path) && is_array($stat) && isset($stat['mode'])
			&& (($stat['mode'] & 0170000) === 0100000);
		if($operation === ErasedataManifestCodec::OPERATION_REMOVE_PAYLOAD && !$regular)
			return(false);
		$ret = array(
			'hash' => strtoupper($matches[1]),
			'operation' => $operation,
			'path' => $path,
			'type' => $matches[count($matches) - 1],
			'stat' => $stat,
			'regular' => $regular,
		);
		if($operation === ErasedataManifestCodec::OPERATION_CLEANUP_OBSOLETE)
			$ret['stem'] = strtoupper($matches[1]).'.cleanup.'.$matches[2].'.'.$matches[3];
		return($ret);
	}
}

if(!function_exists('erasedataUnlinkExactStagedFile'))
{
	function erasedataUnlinkExactStagedFile($path, $expectedStat, $revalidate = null,
		?ErasedataFilesystemOps $filesystem = null)
	{
		if($filesystem === null)
			$filesystem = new ErasedataFilesystemOps();
		$identity = $filesystem->entryIdentity($path);
		$current = $identity === false ? false : $identity['lstat'];
		if(is_link($path) || !is_array($current) || !is_array($expectedStat)
			|| !isset($current['dev'], $current['ino'], $current['mode'], $expectedStat['dev'], $expectedStat['ino'])
			|| $current['dev'] !== $expectedStat['dev'] || $current['ino'] !== $expectedStat['ino']
			|| (($current['mode'] & 0170000) !== 0100000))
			return(false);
		if(is_callable($revalidate) && !call_user_func($revalidate, $path, $expectedStat))
			return(false);
		$identity = $filesystem->entryIdentity($path);
		$current = $identity === false ? false : $identity['lstat'];
		if(is_link($path) || !is_array($current) || !erasedataSameStatIdentity($current, $expectedStat))
			return(false);
		return($filesystem->unlink($path));
	}
}

if(!function_exists('erasedataWriteStagedManifest'))
{
	function erasedataWriteStagedManifest($listPath, $hash, $contents, $tag = '')
	{
		$canonicalHash = erasedataCanonicalHash($hash);
		if($canonicalHash === false || !is_string($listPath) || !is_dir($listPath) || !is_string($contents))
			return(false);
		$manifest = ErasedataManifestCodec::decodeBytes($contents, $canonicalHash);
		$expectedOperation = $tag === '' ? ErasedataManifestCodec::OPERATION_REMOVE_PAYLOAD
			: ($tag === 'cleanup' ? ErasedataManifestCodec::OPERATION_CLEANUP_OBSOLETE : false);
		if($manifest === false || $expectedOperation === false || !isset($manifest['operation'])
			|| $manifest['operation'] !== $expectedOperation)
			return(false);

		$name = $canonicalHash.($tag === '' ? '.' : '.'.$tag.'.').getmypid().'.'.uniqid('', true).'.tmp';
		$path = $listPath.'/'.$name;
		$written = 0;
		$handle = @fopen($path, 'x');
		if($handle === false)
			$written = false;
		else
		{
			$length = strlen($contents);
			while($written < $length)
			{
				$part = @fwrite($handle, substr($contents, $written));
				if($part === false || $part === 0)
				{
					$written = false;
					break;
				}
				$written += $part;
			}
			@fclose($handle);
		}
		clearstatcache(true, $path);
		$stat = @lstat($path);
		if($written === false || $written !== strlen($contents) || is_link($path) || !is_file($path)
			|| !is_array($stat) || !isset($stat['mode']) || (($stat['mode'] & 0170000) !== 0100000))
		{
			if(is_array($stat))
				erasedataUnlinkExactStagedFile($path, $stat);
			return(false);
		}
		erasedataRepairFileMode($path);
		clearstatcache(true, $path);
		$stat = @lstat($path);
		if(is_link($path) || !is_file($path) || !is_array($stat) || !isset($stat['mode'])
			|| (($stat['mode'] & 0170000) !== 0100000))
		{
			if(is_array($stat))
				erasedataUnlinkExactStagedFile($path, $stat);
			return(false);
		}
		return(array('path' => $path, 'stat' => $stat));
	}
}

if(!function_exists('erasedataPathContains'))
{
	function erasedataPathContains($parent, $path)
	{
		$parent = $parent === '/' ? '/' : rtrim((string)$parent, '/');
		$path = $path === '/' ? '/' : rtrim((string)$path, '/');
		if($parent === '' || $path === '')
			return(false);
		return($parent === $path || ($parent !== '/' && strpos($path, $parent.'/') === 0));
	}
}

if(!function_exists('erasedataSameFilesystemEntry'))
{
	function erasedataSameFilesystemEntry($expected, $current)
	{
		if(!is_array($expected) || !is_array($current)
			|| empty($expected['exists']) || empty($current['exists']))
			return(false);
		return($expected['lstat']['dev'] === $current['lstat']['dev']
			&& $expected['lstat']['ino'] === $current['lstat']['ino']
			&& $expected['stat']['dev'] === $current['stat']['dev']
			&& $expected['stat']['ino'] === $current['stat']['ino']);
	}
}

if(!function_exists('erasedataPathsOverlap'))
{
	function erasedataPathsOverlap($left, $right)
	{
		if(erasedataPathContains($left, $right) || erasedataPathContains($right, $left))
			return(true);
		// The plugin's OWN identity owner, never the core resolver. This
		// predicate is the deletion authorisation itself -- false means the
		// collector may delete -- and the core resolver rebuilds an unresolved
		// tail verbatim, so a missing name carrying '..' or a directory symlink
		// can compare as "no overlap" while resolving straight into a path a
		// live download still owns. erasedataPathIdentity() proves a missing
		// name's canonical location component by component or answers false.
		$leftIdentity = erasedataPathIdentity($left);
		$rightIdentity = erasedataPathIdentity($right);
		// An existing name whose physical identity cannot be established is not
		// safe deletion territory. Treat uncertainty as overlap (fail closed).
		if($leftIdentity === false || $rightIdentity === false)
			return(true);
		if(!empty($leftIdentity['exists']) && !empty($rightIdentity['exists'])
			&& $leftIdentity['stat']['dev'] === $rightIdentity['stat']['dev']
			&& $leftIdentity['stat']['ino'] === $rightIdentity['stat']['ino'])
			return(true);
		return(erasedataPathContains($leftIdentity['path'], $rightIdentity['path'])
			|| erasedataPathContains($rightIdentity['path'], $leftIdentity['path']));
	}
}

if(!function_exists('erasedataExactFileAlias'))
{
	// Exact cleanup ownership is inode-based. Lexical containment remains a
	// separate directory-safety rule and must not satisfy a file obligation.
	function erasedataExactFileAlias($leftIdentity, $rightIdentity)
	{
		if(!is_array($leftIdentity) || !is_array($rightIdentity))
			return(ERASEDATA_FILE_ALIAS_UNKNOWN);
		if(empty($leftIdentity['exists']) || empty($rightIdentity['exists']))
			return(ERASEDATA_FILE_ALIAS_DISTINCT);
		if(!isset($leftIdentity['stat']['dev'], $leftIdentity['stat']['ino'],
			$rightIdentity['stat']['dev'], $rightIdentity['stat']['ino']))
			return(ERASEDATA_FILE_ALIAS_UNKNOWN);
		return($leftIdentity['stat']['dev'] === $rightIdentity['stat']['dev']
			&& $leftIdentity['stat']['ino'] === $rightIdentity['stat']['ino']
			? ERASEDATA_FILE_ALIAS_SAME : ERASEDATA_FILE_ALIAS_DISTINCT);
	}
}

if(!function_exists('erasedataSameStatIdentity'))
{
	function erasedataSameStatIdentity($left, $right)
	{
		return(is_array($left) && is_array($right) && isset($left['dev'], $left['ino'], $right['dev'], $right['ino'])
			&& $left['dev'] === $right['dev'] && $left['ino'] === $right['ino']);
	}
}

if(!function_exists('erasedataReadExactCleanupArtifact'))
{
	function erasedataReadExactCleanupFile($candidate, $type, &$reason = null,
		?ErasedataFilesystemOps $filesystem = null)
	{
		$reason = 'unreadable-manifest';
		if($filesystem === null)
			$filesystem = new ErasedataFilesystemOps();
		if(!is_array($candidate) || !isset($candidate['operation'], $candidate['type'], $candidate['path'], $candidate['stat'])
			|| $candidate['operation'] !== ErasedataManifestCodec::OPERATION_CLEANUP_OBSOLETE
			|| $candidate['type'] !== $type || empty($candidate['regular']))
			return(false);
		$handle = @fopen($candidate['path'], 'rb');
		if($handle === false)
			return(false);
		$handleStat = @fstat($handle);
		$pathIdentity = $filesystem->entryIdentity($candidate['path']);
		$pathStat = $pathIdentity === false ? false : $pathIdentity['lstat'];
		$bytes = ErasedataManifestCodec::readBoundedHandle($handle);
		$afterHandleStat = @fstat($handle);
		$afterIdentity = $filesystem->entryIdentity($candidate['path']);
		$afterPathStat = $afterIdentity === false ? false : $afterIdentity['lstat'];
		@fclose($handle);
		if(is_link($candidate['path']) || !is_array($handleStat) || !is_array($pathStat) || !is_array($afterHandleStat)
			|| !is_array($afterPathStat) || !is_string($bytes)
			|| !erasedataSameStatIdentity($candidate['stat'], $handleStat)
			|| !erasedataSameStatIdentity($candidate['stat'], $pathStat)
			|| !erasedataSameStatIdentity($candidate['stat'], $afterHandleStat)
			|| !erasedataSameStatIdentity($candidate['stat'], $afterPathStat)
			|| !isset($afterPathStat['mode']) || (($afterPathStat['mode'] & 0170000) !== 0100000))
			return(false);
		$candidate['stat'] = $afterPathStat;
		$reason = null;
		return(array('candidate' => $candidate, 'bytes' => $bytes));
	}

	function erasedataReadExactCleanupArtifact($candidate, $hash = null, &$reason = null,
		?ErasedataFilesystemOps $filesystem = null)
	{
		$reason = 'unreadable-manifest';
		if(!is_string($hash))
			return(false);
		$read = erasedataReadExactCleanupFile($candidate, 'tmp', $reason, $filesystem);
		if($read === false)
			return(false);
		$manifest = ErasedataManifestCodec::decodeBytes($read['bytes'], $hash);
		if($manifest === false || !isset($manifest['operation']))
			return(false);
		if($manifest['operation'] !== ErasedataManifestCodec::OPERATION_CLEANUP_OBSOLETE)
		{
			$reason = 'generation-mismatch';
			return(false);
		}
		$reason = null;
		$read['manifest'] = $manifest;
		return($read);
	}
}

if(!function_exists('erasedataReadExactCleanupToken'))
{
	function erasedataReadExactCleanupToken($candidate, &$reason = null,
		?ErasedataFilesystemOps $filesystem = null)
	{
		$reason = 'unreadable-manifest';
		$read = erasedataReadExactCleanupFile($candidate, 'list', $reason, $filesystem);
		if($read === false || $read['bytes'] !== '' || !isset($read['candidate']['stat']['size'])
			|| $read['candidate']['stat']['size'] != 0)
			return(false);
		$reason = null;
		return(array('candidate' => $read['candidate']));
	}
}

if(!function_exists('erasedataCleanupArtifactMatchesGeneration'))
{
	function erasedataCleanupArtifactMatchesGeneration($artifact, $oldHash, $newHash, $marker, $replacementRecord)
	{
		return(is_array($artifact) && isset($artifact['manifest']) && is_array($artifact['manifest'])
			&& isset($artifact['manifest']['hash'], $artifact['manifest']['new_hash'], $artifact['manifest']['marker'],
				$artifact['manifest']['replacement_record'])
			&& $artifact['manifest']['hash'] === $oldHash && $artifact['manifest']['new_hash'] === $newHash
			&& $artifact['manifest']['marker'] === $marker
			&& $artifact['manifest']['replacement_record'] === $replacementRecord);
	}
}

if(!function_exists('erasedataCleanupArtifactStillMatches'))
{
	function erasedataCleanupArtifactStillMatches($artifact, $hash = null,
		?ErasedataFilesystemOps $filesystem = null)
	{
		if(!is_array($artifact) || !isset($artifact['candidate']))
			return(false);
		$reason = null;
		$current = erasedataReadExactCleanupArtifact($artifact['candidate'], $hash, $reason, $filesystem);
		if($current === false)
			return(false);
		return(!isset($artifact['manifest']) || (isset($artifact['bytes'])
			&& $current['manifest'] === $artifact['manifest'] && $current['bytes'] === $artifact['bytes']));
	}
}

if(!function_exists('erasedataCleanupTokenStillMatches'))
{
	function erasedataCleanupTokenStillMatches($token, ?ErasedataFilesystemOps $filesystem = null)
	{
		if(!is_array($token) || !isset($token['candidate']))
			return(false);
		$reason = null;
		return(erasedataReadExactCleanupToken($token['candidate'], $reason, $filesystem) !== false);
	}
}

if(!function_exists('erasedataRepairExactCleanupTokenMode'))
{
	function erasedataRepairExactCleanupTokenMode($path, $expectedStat)
	{
		clearstatcache(true, $path);
		$current = @lstat($path);
		if(is_link($path) || !is_array($current) || !isset($current['mode'])
			|| (($current['mode'] & 0170000) !== 0100000)
			|| !erasedataSameStatIdentity($current, $expectedStat))
			return(false);
		erasedataRepairFileMode($path);
		clearstatcache(true, $path);
		$current = @lstat($path);
		return(!is_link($path) && is_array($current) && isset($current['mode'])
			&& (($current['mode'] & 0170000) === 0100000)
			&& erasedataSameStatIdentity($current, $expectedStat));
	}
}

if(!function_exists('erasedataCleanupCommittedPairStillMatches'))
{
	function erasedataCleanupCommittedPairStillMatches($tmp, $token, $hash,
		?ErasedataFilesystemOps $filesystem = null)
	{
		return(is_array($tmp) && is_array($token) && isset($tmp['candidate']['stem'], $token['candidate']['stem'])
			&& $tmp['candidate']['stem'] === $token['candidate']['stem']
			&& erasedataCleanupArtifactStillMatches($tmp, $hash, $filesystem)
			&& erasedataCleanupTokenStillMatches($token, $filesystem));
	}
}

if(!function_exists('erasedataPublishExactStagedFile'))
{
	function erasedataPublishExactStagedFile($tmpPath, $expectedStat, $listPath,
		$expectedManifest = null, ?ErasedataFilesystemOps $filesystem = null)
	{
		if($filesystem === null)
			$filesystem = new ErasedataFilesystemOps();
		$listDirectory = dirname($tmpPath);
		$tmpCandidate = erasedataParseCollectorCandidate($listDirectory, basename($tmpPath));
		if($tmpCandidate === false || $tmpCandidate['type'] !== 'tmp' || $tmpCandidate['path'] !== $tmpPath
			|| $listPath !== substr($tmpPath, 0, -4).'.list')
			return(false);
		$reason = null;
		$tmp = erasedataReadExactCleanupArtifact($tmpCandidate, $tmpCandidate['hash'], $reason, $filesystem);
		if($tmp === false || !erasedataSameStatIdentity($tmp['candidate']['stat'], $expectedStat)
			|| ($expectedManifest !== null && $tmp['manifest'] !== $expectedManifest))
			return(false);
		$listCandidate = erasedataParseCollectorCandidate($listDirectory, basename($listPath));
		if($listCandidate !== false && is_array($listCandidate['stat']))
		{
			$token = erasedataReadExactCleanupToken($listCandidate, $reason, $filesystem);
			if($token === false)
				return(false);
			if(!erasedataRepairExactCleanupTokenMode($listPath, $token['candidate']['stat']))
				return(false);
			$listCandidate = erasedataParseCollectorCandidate($listDirectory, basename($listPath));
			$token = $listCandidate === false ? false
				: erasedataReadExactCleanupToken($listCandidate, $reason, $filesystem);
			return($token !== false && erasedataCleanupCommittedPairStillMatches(
				$tmp, $token, $tmpCandidate['hash'], $filesystem));
		}
		if($filesystem->entryIdentity($listPath) !== false
			|| !erasedataCleanupArtifactStillMatches($tmp, $tmpCandidate['hash'], $filesystem))
			return(false);
		$handle = @fopen($listPath, 'x');
		if($handle === false)
			return(false);
		$tokenStat = @fstat($handle);
		$tokenIdentity = $filesystem->entryIdentity($listPath);
		$tokenPathStat = $tokenIdentity === false ? false : $tokenIdentity['lstat'];
		@fclose($handle);
		if(is_link($listPath) || !is_array($tokenStat) || !is_array($tokenPathStat)
			|| !erasedataSameStatIdentity($tokenStat, $tokenPathStat)
			|| !isset($tokenPathStat['mode'], $tokenPathStat['size'])
			|| (($tokenPathStat['mode'] & 0170000) !== 0100000) || $tokenPathStat['size'] != 0)
			return(false);
		if(!erasedataRepairExactCleanupTokenMode($listPath, $tokenStat))
			return(false);
		clearstatcache(true, $listPath);
		$listCandidate = erasedataParseCollectorCandidate($listDirectory, basename($listPath));
		$token = $listCandidate === false ? false
			: erasedataReadExactCleanupToken($listCandidate, $reason, $filesystem);
		if($token === false || !erasedataSameStatIdentity($token['candidate']['stat'], $tokenStat)
			|| !erasedataCleanupCommittedPairStillMatches($tmp, $token, $tmpCandidate['hash'], $filesystem))
			return(false);
		$ret = erasedataCleanupCommittedPairStillMatches(
			$tmp, $token, $tmpCandidate['hash'], $filesystem);
		clearstatcache(true, $listPath);
		return($ret);
	}
}

if(!function_exists('erasedataCleanupTmpMatchesJob'))
{
	function erasedataCleanupTmpMatchesJob($job)
	{
		if(!is_array($job) || !isset($job['tmp_path'], $job['tmp_stat']) || is_link($job['tmp_path'])
			|| !is_file($job['tmp_path']))
			return(false);
		clearstatcache(true, $job['tmp_path']);
		$current = @lstat($job['tmp_path']);
		return(is_array($current) && isset($current['mode']) && (($current['mode'] & 0170000) === 0100000)
			&& erasedataSameStatIdentity($job['tmp_stat'], $current));
	}
}

if(!function_exists('erasedataReleaseObsoleteCleanupJob'))
{
	function erasedataReleaseObsoleteCleanupJob(&$job)
	{
		if(is_array($job) && isset($job['lock']))
			erasedataReleaseHashLock($job['lock']);
		$job = null;
	}
}

if(!function_exists('erasedataReadExactCleanupJob'))
{
	function erasedataReadExactCleanupJob($job)
	{
		if(!is_array($job) || !isset($job['operation'], $job['old_hash'], $job['new_hash'], $job['marker'],
			$job['replacement_record'], $job['base'], $job['files'], $job['identities'], $job['tmp_path'],
			$job['tmp_stat'], $job['list_path'], $job['lock'])
			|| $job['operation'] !== ErasedataManifestCodec::OPERATION_CLEANUP_OBSOLETE
			|| !is_resource($job['lock']))
			return(false);
		$oldHash = erasedataCanonicalHash($job['old_hash']);
		$newHash = erasedataCanonicalHash($job['new_hash']);
		$listPath = FileUtil::getSettingsPath().'/erasedata';
		$candidate = erasedataParseCollectorCandidate($listPath, basename($job['tmp_path']));
		if($oldHash === false || $newHash === false || $oldHash !== $job['old_hash'] || $newHash !== $job['new_hash']
			|| dirname($job['tmp_path']) !== $listPath || dirname($job['list_path']) !== $listPath
			|| $candidate === false || $candidate['operation'] !== ErasedataManifestCodec::OPERATION_CLEANUP_OBSOLETE
			|| $candidate['hash'] !== $oldHash || $candidate['type'] !== 'tmp' || $candidate['path'] !== $job['tmp_path']
			|| !erasedataSameStatIdentity($candidate['stat'], $job['tmp_stat'])
			|| $job['list_path'] !== substr($job['tmp_path'], 0, -4).'.list')
			return(false);
		$artifact = erasedataReadExactCleanupArtifact($candidate, $oldHash);
		if($artifact === false || !erasedataSameStatIdentity($artifact['candidate']['stat'], $job['tmp_stat'])
			|| !erasedataCleanupTmpMatchesJob($job)
			|| !isset($artifact['manifest']))
			return(false);
		$manifest = $artifact['manifest'];
		if(!isset($manifest['operation'], $manifest['hash'], $manifest['new_hash'], $manifest['marker'], $manifest['replacement_record'])
			|| $manifest['operation'] !== ErasedataManifestCodec::OPERATION_CLEANUP_OBSOLETE
			|| $manifest['hash'] !== $job['old_hash'] || $manifest['new_hash'] !== $job['new_hash']
			|| $manifest['marker'] !== $job['marker'] || $manifest['replacement_record'] !== $job['replacement_record']
			|| $manifest['base'] !== $job['base'] || $manifest['files'] !== $job['files']
			|| $manifest['identities'] !== $job['identities'])
			return(false);
		return($manifest);
	}
}

if(!function_exists('erasedataPrepareObsoleteCleanup'))
{
	function erasedataPrepareObsoleteCleanup($oldHash, $newHash, $marker, $replacementRecord, $base, array $entries)
	{
		$canonicalOldHash = erasedataCanonicalHash($oldHash);
		$canonicalNewHash = erasedataCanonicalHash($newHash);
		if($canonicalOldHash === false || $canonicalNewHash === false)
			return(false);
		if(!count($entries))
			return(null);
		$listPath = FileUtil::getSettingsPath().'/erasedata';
		@FileUtil::makeDirectory($listPath);
		if(!is_dir($listPath))
			return(false);
		$lock = erasedataAcquireHashLock($listPath, $canonicalOldHash);
		if($lock === false)
			return(false);
		$contents = ErasedataManifestCodec::encodeCleanupObsolete($canonicalOldHash, $canonicalNewHash, $marker,
			$replacementRecord, $base, $entries);
		if($contents === false)
		{
			erasedataReleaseHashLock($lock);
			return(false);
		}
		$transaction = ErasedataManifestCodec::decodeBytes($contents, $canonicalOldHash);
		if($transaction === false)
		{
			erasedataReleaseHashLock($lock);
			return(false);
		}
		$staged = erasedataWriteStagedManifest($listPath, $canonicalOldHash, $contents, 'cleanup');
		if($staged === false)
		{
			erasedataReleaseHashLock($lock);
			return(false);
		}
		return(array(
			'operation' => ErasedataManifestCodec::OPERATION_CLEANUP_OBSOLETE,
			'old_hash' => $canonicalOldHash,
			'new_hash' => $canonicalNewHash,
			'marker' => $marker,
			'replacement_record' => $replacementRecord,
			'base' => $transaction['base'],
			'files' => $transaction['files'],
			'identities' => $transaction['identities'],
			'tmp_path' => $staged['path'],
			'tmp_stat' => $staged['stat'],
			'list_path' => substr($staged['path'], 0, -4).'.list',
			'lock' => $lock,
		));
	}
}

if(!function_exists('erasedataArmObsoleteCleanupRun'))
{
	// The prepared cleanup file is the obligation while OLD still exists. Use
	// the same durable generation, aligned schedule and guarded-child ack as
	// removal admission, without authorizing a payload removal for OLD.
	function erasedataArmObsoleteCleanupRun(array $dependencies, $job)
	{
		$listPath = isset($dependencies['listPath']) ? $dependencies['listPath'] : null;
		$user = isset($dependencies['user']) ? $dependencies['user'] : null;
		$log = isset($dependencies['log']) ? $dependencies['log'] : null;
		$timeout = isset($dependencies['ackTimeout']) ? (float)$dependencies['ackTimeout']
			: ERASEDATA_DRAIN_ACK_TIMEOUT;
		$poll = isset($dependencies['ackPoll']) ? (float)$dependencies['ackPoll']
			: ERASEDATA_DRAIN_ACK_POLL;
		if(!is_string($listPath) || !is_dir($listPath)
			|| erasedataDrainScheduleKey($user) === false
			|| !is_array($job) || !isset($job['tmp_path'], $job['list_path'], $job['lock'])
			|| !is_resource($job['lock']) || dirname($job['tmp_path']) !== $listPath
			|| dirname($job['list_path']) !== $listPath
			|| erasedataReadExactCleanupJob($job) === false)
		{
			erasedataDrainDiagnostic($log, 'cleanup-arm-job', null, 1,
				'old-torrent-retained-replacement-retryable');
			return(false);
		}
		$stateLock = erasedataAcquireDrainStateLock($listPath);
		if(!is_resource($stateLock))
		{
			erasedataDrainDiagnostic($log, 'cleanup-arm-lock', null, 1,
				'old-torrent-retained-replacement-retryable');
			return(false);
		}
		$state = erasedataReadDrainState($listPath);
		if(!is_array($state) || !erasedataDrainStateBelongsTo($state, $user))
		{
			erasedataReleaseDrainStateLock($stateLock);
			erasedataDrainDiagnostic($log, 'cleanup-arm-state', null, 1,
				'old-torrent-retained-replacement-retryable');
			return(false);
		}
		$generation = erasedataGenerationIncrement($state['generation']);
		if($generation === false)
		{
			erasedataReleaseDrainStateLock($stateLock);
			erasedataDrainDiagnostic($log, 'cleanup-arm-generation', $state['generation'], 1,
				'old-torrent-retained-replacement-retryable');
			return(false);
		}
		$state['user'] = $user;
		$state['generation'] = $generation;
		// The prepared cleanup artifact keeps retirement from removing the
		// schedule while the checker waits and until the exact job resolves.
		if(!erasedataWriteDrainState($listPath, $state))
		{
			erasedataReleaseDrainStateLock($stateLock);
			erasedataDrainDiagnostic($log, 'cleanup-arm-write', $generation, 1,
				'old-torrent-retained-replacement-retryable');
			return(false);
		}
		erasedataReleaseDrainStateLock($stateLock);
		// Re-arm even if durable phase said armed: rTorrent lost its volatile
		// schedule on restart, and that phase alone cannot prove a live tick.
		if(!erasedataRearmDrainScheduleRun($dependencies))
		{
			erasedataDrainDiagnostic($log, 'cleanup-arm-rearm', $generation, 1,
				'old-torrent-retained-replacement-retryable');
			return(false);
		}
		if(!erasedataWaitForDrainAcknowledgement($listPath, $generation, $timeout, $poll))
		{
			erasedataDrainDiagnostic($log, 'cleanup-drain-no-ack', $generation, 1,
				'old-torrent-retained-replacement-retryable');
			return(false);
		}
		$state = erasedataReadDrainState($listPath);
		$ack = is_array($state) && isset($state['acknowledged'])
			? erasedataGenerationCompare($state['acknowledged'], $generation) : false;
		if(!erasedataDrainStateBelongsTo($state, $user) || ($ack !== 0 && $ack !== 1)
			|| erasedataReadExactCleanupJob($job) === false)
		{
			erasedataDrainDiagnostic($log, 'cleanup-arm-changed', $generation, 1,
				'old-torrent-retained-replacement-retryable');
			return(false);
		}
		return(true);
	}
}

if(!function_exists('erasedataArmObsoleteCleanup'))
{
	function erasedataArmObsoleteCleanup($job)
	{
		return(erasedataArmObsoleteCleanupRun(array(
			'listPath' => FileUtil::getSettingsPath().'/erasedata',
			'user' => User::getUser(),
			'log' => array('FileUtil', 'toLog'),
			'ackTimeout' => ERASEDATA_DRAIN_ACK_TIMEOUT,
			'ackPoll' => ERASEDATA_DRAIN_ACK_POLL,
		), $job));
	}
}

if(!function_exists('erasedataPublishObsoleteCleanup'))
{
	function erasedataPublishObsoleteCleanup(&$job, ?ErasedataFilesystemOps $filesystem = null)
	{
		try {
			$manifest = erasedataReadExactCleanupJob($job);
			if($manifest === false)
				return(false);
			$index = erasedataBuildCollectorIndex(FileUtil::getSettingsPath().'/erasedata',
				$job['old_hash'], $filesystem);
			$reason = null;
			$artifacts = erasedataCleanupGenerationArtifacts($index, $job['old_hash'], $job['new_hash'], $job['marker'],
				$job['replacement_record'], $reason, $filesystem);
			if($artifacts === false || $artifacts === null || $artifacts['tmp']['candidate']['path'] !== $job['tmp_path']
				|| !erasedataSameStatIdentity($artifacts['tmp']['candidate']['stat'], $job['tmp_stat']))
				return(false);
			if($artifacts['token'] !== null)
				return(erasedataCleanupCommittedPairStillMatches($artifacts['tmp'], $artifacts['token'],
					$job['old_hash'], $filesystem));
			return(erasedataPublishExactStagedFile($job['tmp_path'], $job['tmp_stat'],
				$job['list_path'], $manifest, $filesystem));
		} finally {
			erasedataReleaseObsoleteCleanupJob($job);
		}
	}
}

if(!function_exists('erasedataCancelObsoleteCleanup'))
{
	function erasedataCancelObsoleteCleanup(&$job, ?ErasedataFilesystemOps $filesystem = null)
	{
		try {
			if(erasedataReadExactCleanupJob($job) === false)
				return(false);
			$index = erasedataBuildCollectorIndex(FileUtil::getSettingsPath().'/erasedata',
				$job['old_hash'], $filesystem);
			return(erasedataCancelObsoleteCleanupGenerationLocked(FileUtil::getSettingsPath().'/erasedata',
				$job['old_hash'], $job['new_hash'], $job['marker'], $job['replacement_record'],
				$index, $filesystem) === ERASEDATA_CLEANUP_READY);
		} finally {
			erasedataReleaseObsoleteCleanupJob($job);
		}
	}
}

if(!function_exists('erasedataCleanupTransactionKey'))
{
	function erasedataCleanupTransactionKey($manifest)
	{
		return(is_array($manifest) && isset($manifest['new_hash'], $manifest['marker'], $manifest['replacement_record'])
			? $manifest['new_hash'].'|'.$manifest['marker'].'|'.$manifest['replacement_record'] : false);
	}
}

if(!function_exists('erasedataAnalyzeCleanupIndex'))
{
	// Decode each staged cleanup generation once while building the queue view.
	// Later operations re-read only their selected stem immediately before use.
	function erasedataAnalyzeCleanupIndex($index, ?ErasedataFilesystemOps $filesystem = null)
	{
		if(!is_array($index))
			return(false);
		foreach($index as $hash => &$hashIndex)
		{
			if(!isset($hashIndex['cleanup']) || !is_array($hashIndex['cleanup']))
				continue;
			$transactions = array();
			foreach($hashIndex['cleanup'] as $stem => &$items)
			{
				$tmpItems = isset($items['tmp']) && is_array($items['tmp']) ? $items['tmp'] : array();
				$analysis = array(
					'malformed' => !empty($items['malformed']),
					'transaction_key' => null,
					'duplicate' => false,
				);
				if(count($tmpItems) === 1)
				{
					$artifactReason = null;
					$artifact = erasedataReadExactCleanupArtifact(
						$tmpItems[0], $hash, $artifactReason, $filesystem);
					if($artifact !== false && ($key = erasedataCleanupTransactionKey($artifact['manifest'])) !== false)
					{
						$analysis['transaction_key'] = $key;
						if(!isset($transactions[$key]))
							$transactions[$key] = array();
						$transactions[$key][] = $stem;
					}
				}
				$items['analysis'] = $analysis;
			}
			unset($items);
			foreach($transactions as $key => $stems)
				if(count($stems) > 1)
					foreach($stems as $stem)
						$hashIndex['cleanup'][$stem]['analysis']['duplicate'] = true;
			$hashIndex['cleanup_transactions'] = $transactions;
		}
		unset($hashIndex);
		return($index);
	}
}

if(!function_exists('erasedataBuildCollectorIndex'))
{
	function erasedataBuildCollectorIndex($listPath, $onlyHash = null,
		?ErasedataFilesystemOps $filesystem = null)
	{
		if($filesystem === null)
			$filesystem = new ErasedataFilesystemOps();
		$ret = array();
		if(!is_string($listPath) || !is_dir($listPath))
			return(false);
		// Exactly one enumeration of the queue directory per index build, and it
		// goes through the operations seam so a test can observe and script it
		// without production carrying a probe of its own.
		$files = $filesystem->scanDirectory($listPath);
		if(!is_array($files))
			return(false);
		foreach($files as $file)
		{
			$malformed = null;
			$candidate = erasedataParseCollectorCandidate($listPath, $file, $malformed);
			if($candidate === false)
			{
				if(is_array($malformed) && (is_null($onlyHash) || $malformed['hash'] === $onlyHash))
				{
					$hash = $malformed['hash'];
					if(!isset($ret[$hash]))
						$ret[$hash] = array('legacy' => array(), 'cleanup' => array());
					$stem = $malformed['stem'];
					if(!isset($ret[$hash]['cleanup'][$stem]))
						$ret[$hash]['cleanup'][$stem] = array('tmp' => array(), 'list' => array(), 'malformed' => array());
					$ret[$hash]['cleanup'][$stem]['malformed'][] = $malformed;
				}
				continue;
			}
			$hash = $candidate['hash'];
			if(!is_null($onlyHash) && $hash !== $onlyHash)
				continue;
			if(!isset($ret[$hash]))
				$ret[$hash] = array('legacy' => array(), 'cleanup' => array());
			if($candidate['operation'] === ErasedataManifestCodec::OPERATION_CLEANUP_OBSOLETE)
			{
				$stem = $candidate['stem'];
				if(!isset($ret[$hash]['cleanup'][$stem]))
					$ret[$hash]['cleanup'][$stem] = array('tmp' => array(), 'list' => array(), 'malformed' => array());
				$ret[$hash]['cleanup'][$stem][$candidate['type']][] = $candidate;
				continue;
			}
			$ret[$hash]['legacy'][] = $candidate;
		}
		return(erasedataAnalyzeCleanupIndex($ret, $filesystem));
	}
}

if(!function_exists('erasedataCleanupGenerationArtifacts'))
{
	function erasedataCleanupGenerationArtifacts($index, $oldHash, $newHash, $marker, $replacementRecord,
		&$reason = null, ?ErasedataFilesystemOps $filesystem = null)
	{
		$reason = null;
		if($index === false || !is_array($index))
		{
			$reason = 'unreadable-manifest';
			return(false);
		}
		if(!isset($index[$oldHash]['cleanup']) || !is_array($index[$oldHash]['cleanup']))
			return(null);
		if(!isset($index[$oldHash]['cleanup_transactions']) || !is_array($index[$oldHash]['cleanup_transactions']))
			$index = erasedataAnalyzeCleanupIndex($index, $filesystem);
		if($index === false || !isset($index[$oldHash]['cleanup_transactions']))
		{
			$reason = 'unreadable-manifest';
			return(false);
		}
		$key = $newHash.'|'.$marker.'|'.$replacementRecord;
		if(!isset($index[$oldHash]['cleanup_transactions'][$key]))
			return(null);
		$stems = $index[$oldHash]['cleanup_transactions'][$key];
		if(count($stems) !== 1 || !isset($index[$oldHash]['cleanup'][$stems[0]]))
		{
			$reason = 'generation-mismatch';
			return(false);
		}
		$stem = $stems[0];
		$items = $index[$oldHash]['cleanup'][$stem];
		$analysis = isset($items['analysis']) && is_array($items['analysis']) ? $items['analysis'] : array();
		$tmpItems = isset($items['tmp']) && is_array($items['tmp']) ? $items['tmp'] : array();
		$tokenItems = isset($items['list']) && is_array($items['list']) ? $items['list'] : array();
		if(!empty($analysis['duplicate']) || !empty($analysis['malformed'])
			|| count($tmpItems) !== 1 || count($tokenItems) > 1)
		{
			$reason = 'generation-mismatch';
			return(false);
		}
		$artifactReason = null;
		$tmp = erasedataReadExactCleanupArtifact($tmpItems[0], $oldHash, $artifactReason, $filesystem);
		if($tmp === false)
		{
			$reason = $artifactReason === null ? 'unreadable-manifest' : $artifactReason;
			return(false);
		}
		if(!erasedataCleanupArtifactMatchesGeneration($tmp, $oldHash, $newHash, $marker, $replacementRecord))
		{
			$reason = 'generation-mismatch';
			return(false);
		}
		$token = null;
		if(count($tokenItems))
		{
			$tokenReason = null;
			$token = erasedataReadExactCleanupToken($tokenItems[0], $tokenReason, $filesystem);
			if($token === false || !erasedataCleanupCommittedPairStillMatches($tmp, $token, $oldHash, $filesystem))
			{
				$reason = $tokenReason === null ? 'unreadable-manifest' : $tokenReason;
				return(false);
			}
		}
		return(array('tmp' => $tmp, 'token' => $token, 'stem' => $stem));
	}
}

if(!function_exists('erasedataCleanupSuccessorMatches'))
{
	function erasedataCleanupSuccessorMatches($newHash, $marker, $replacementRecord, &$reason = null)
	{
		$reason = 'generation-mismatch';
		$probe = new rXMLRPCRequest(array(
			new rXMLRPCCommand(getCmd('d.hash'), $newHash),
			new rXMLRPCCommand(getCmd('d.get_custom'), array($newHash, 'chk-replacement')),
			new rXMLRPCCommand(getCmd('d.get_custom'), array($newHash, 'chk-replaces')),
		));
		$probe->important = false;
		if(!$probe->success() || $probe->fault || !is_array($probe->val) || count($probe->val) !== 3)
		{
			$reason = 'rpc-unknown';
			return(false);
		}
		if(!is_string($probe->val[0]) || strcasecmp($probe->val[0], $newHash) !== 0
			|| (string)$probe->val[1] !== $marker || (string)$probe->val[2] !== $replacementRecord)
			return(false);
		$reason = null;
		return(true);
	}
}

if(!function_exists('erasedataRecoverObsoleteCleanupLocked'))
{
	function erasedataRecoverObsoleteCleanupLocked($listPath, $oldHash, $newHash, $marker, $replacementRecord,
		&$reason = null, $index = null, ?ErasedataFilesystemOps $filesystem = null)
	{
		$reason = 'generation-mismatch';
		if($index === null)
			$index = erasedataBuildCollectorIndex($listPath, $oldHash, $filesystem);
		$artifacts = erasedataCleanupGenerationArtifacts($index, $oldHash, $newHash, $marker,
			$replacementRecord, $reason, $filesystem);
		if($artifacts === false)
		{
			return(ERASEDATA_CLEANUP_RETRY);
		}
		if($artifacts === null)
		{
			$reason = null;
			return(ERASEDATA_CLEANUP_NONE);
		}
		$tmp = $artifacts['tmp'];
		if($artifacts['token'] !== null)
		{
			if(erasedataRepairExactCleanupTokenMode($artifacts['token']['candidate']['path'],
				$artifacts['token']['candidate']['stat'])
				&& erasedataCleanupCommittedPairStillMatches($tmp, $artifacts['token'], $oldHash, $filesystem))
			{
				$reason = null;
				return(ERASEDATA_CLEANUP_READY);
			}
			$reason = 'unreadable-manifest';
			return(ERASEDATA_CLEANUP_RETRY);
		}
		$oldPresence = erasedataTorrentPresence($oldHash);
		if($oldPresence === ERASEDATA_TORRENT_UNKNOWN)
		{
			$reason = 'rpc-unknown';
			return(ERASEDATA_CLEANUP_RETRY);
		}
		if($oldPresence !== ERASEDATA_TORRENT_ABSENT)
			return(ERASEDATA_CLEANUP_RETRY);
		$successorReason = null;
		if(!erasedataCleanupSuccessorMatches($newHash, $marker, $replacementRecord, $successorReason))
		{
			$reason = $successorReason === null ? 'generation-mismatch' : $successorReason;
			return(ERASEDATA_CLEANUP_RETRY);
		}
		$listPathname = substr($tmp['candidate']['path'], 0, -4).'.list';
		if(!erasedataPublishExactStagedFile($tmp['candidate']['path'], $tmp['candidate']['stat'],
			$listPathname, $tmp['manifest'], $filesystem))
		{
			$reason = 'generation-mismatch';
			return(ERASEDATA_CLEANUP_RETRY);
		}
		$reason = null;
		return(ERASEDATA_CLEANUP_READY);
	}
}

if(!function_exists('erasedataCancelObsoleteCleanupGenerationLocked'))
{
	function erasedataCancelObsoleteCleanupGenerationLocked($listPath, $oldHash, $newHash,
		$marker, $replacementRecord, $index = null, ?ErasedataFilesystemOps $filesystem = null)
	{
		if($index === null)
			$index = erasedataBuildCollectorIndex($listPath, $oldHash, $filesystem);
		$reason = null;
		$artifacts = erasedataCleanupGenerationArtifacts($index, $oldHash, $newHash, $marker,
			$replacementRecord, $reason, $filesystem);
		if($artifacts === false)
			return(ERASEDATA_CLEANUP_RETRY);
		if($artifacts === null)
			return(ERASEDATA_CLEANUP_NONE);
		if($artifacts['token'] !== null)
			return(ERASEDATA_CLEANUP_RETRY);
		$tmp = $artifacts['tmp'];
		return(erasedataUnlinkExactStagedFile($tmp['candidate']['path'], $tmp['candidate']['stat'],
			function() use ($tmp, $oldHash, $filesystem) {
				$listPathname = substr($tmp['candidate']['path'], 0, -4).'.list';
				return(erasedataCleanupArtifactStillMatches($tmp, $oldHash, $filesystem)
					&& @lstat($listPathname) === false);
			}, $filesystem) ? ERASEDATA_CLEANUP_READY : ERASEDATA_CLEANUP_RETRY);
	}
}

if(!function_exists('erasedataRecoverObsoleteCleanup'))
{
	function erasedataRecoverObsoleteCleanup($oldHash, $newHash, $marker, $replacementRecord,
		&$reason = null, ?ErasedataFilesystemOps $filesystem = null)
	{
		$reason = 'generation-mismatch';
		$oldHash = erasedataCanonicalHash($oldHash);
		$newHash = erasedataCanonicalHash($newHash);
		$listPath = FileUtil::getSettingsPath().'/erasedata';
		if($oldHash === false || $newHash === false)
			return(ERASEDATA_CLEANUP_RETRY);
		@FileUtil::makeDirectory($listPath);
		if(!is_dir($listPath))
			return(ERASEDATA_CLEANUP_RETRY);
		$lock = erasedataAcquireHashLock($listPath, $oldHash);
		if($lock === false)
			return(ERASEDATA_CLEANUP_RETRY);
		try {
			$index = erasedataBuildCollectorIndex($listPath, $oldHash, $filesystem);
			return(erasedataRecoverObsoleteCleanupLocked($listPath, $oldHash, $newHash, $marker,
				$replacementRecord, $reason, $index, $filesystem));
		}
		finally { erasedataReleaseHashLock($lock); }
	}
}

if(!function_exists('erasedataCancelObsoleteCleanupGeneration'))
{
	function erasedataCancelObsoleteCleanupGeneration($oldHash, $newHash, $marker, $replacementRecord,
		?ErasedataFilesystemOps $filesystem = null)
	{
		$oldHash = erasedataCanonicalHash($oldHash);
		$newHash = erasedataCanonicalHash($newHash);
		$listPath = FileUtil::getSettingsPath().'/erasedata';
		if($oldHash === false || $newHash === false)
			return(ERASEDATA_CLEANUP_RETRY);
		@FileUtil::makeDirectory($listPath);
		if(!is_dir($listPath))
			return(ERASEDATA_CLEANUP_RETRY);
		$lock = erasedataAcquireHashLock($listPath, $oldHash);
		if($lock === false)
			return(ERASEDATA_CLEANUP_RETRY);
		try {
			$index = erasedataBuildCollectorIndex($listPath, $oldHash, $filesystem);
			return(erasedataCancelObsoleteCleanupGenerationLocked($listPath, $oldHash, $newHash, $marker,
				$replacementRecord, $index, $filesystem));
		}
		finally { erasedataReleaseHashLock($lock); }
	}
}

if(!function_exists('erasedataCollectorCommand'))
{
	function erasedataCollectorCommand($user = null, $onlyHash = null)
	{
		if($user === null)
			$user = User::getUser();
		if(!is_string($user))
			return(false);
		if($onlyHash !== null && ($onlyHash = erasedataCanonicalHash($onlyHash)) === false)
			return(false);
		$command = escapeshellarg(Utility::getPHP()).' '.escapeshellarg(dirname(__FILE__).'/update.php').' '.escapeshellarg($user);
		return($onlyHash === null ? $command : $command.' '.escapeshellarg($onlyHash));
	}
}

if(!function_exists('erasedataCollectorScheduleCommand'))
{
	function erasedataCollectorScheduleCommand($theSettings, $interval, $user = null)
	{
		$command = erasedataCollectorCommand($user);
		if($command === false)
			return(false);
		return($theSettings->getAlignedScheduleCommand('erasedata', $interval,
			getCmd('execute').'={sh,-c,'.$command.' &}'));
	}
}

if(!function_exists('erasedataKickCollector'))
{
	function erasedataKickCollector($oldHash)
	{
		$command = erasedataCollectorCommand(null, $oldHash);
		if($command === false)
			return(false);
		$request = new rXMLRPCRequest(new rXMLRPCCommand(getCmd('execute.nothrow'), array('', 'sh', '-c',
			$command.' </dev/null >/dev/null 2>&1 &')));
		$request->important = false;
		return($request->success() && !$request->fault);
	}
}

// ---------------------------------------------------------------------------
// One generation-bound public admission transaction.
//
// All removal doors -- plugins/erasedata/action.php, plugins/httprpc/action.php
// and plugins/erasedata/erase.php -- enter here through erasedataAdmitRemoval().
// None calls the destructive
// producer directly any more, because "record the delete list and then erase"
// is not a thing one process can do safely: rTorrent answers one request at a
// time, and a firing that covers several hundred downloads used to block on it
// until everything timed out, nothing was recorded and the whole sweep came
// back on the next pass, for ever.
//
// What an admission does, in this exact order:
//
//   1. Partition the canonical unique request set R into A (admissible) and F
//      (refused) BEFORE any side effect. Not one member of F gets a marker, a
//      journal entry, a staging file, an RPC or a filesystem mutation.
//   2. Take the per-hash locks blocking, in the canonical sorted order
//      erasedataLockObligations() owns, and only then the state lock. The
//      state lock is never held while waiting for a hash lock.
//   3. Read the durable drain state strictly, bind ONE new generation to the
//      whole batch and write the state durably as `arming`.
//   4. Release the state lock, then the hash locks, and register the repeating
//      erasedata-drain<User> schedule holding nothing at all. The registration
//      is accepted only on success() && !fault.
//   5. Re-take the hash locks and the state lock, and compare-and-swap the
//      SAME generation from `arming` to durable `armed`. A fault, a false
//      return, a lost lock, a state that no longer reads or a generation or
//      phase that no longer matches all mean: stage nothing, erase nothing.
//   6. Record one marker per member of A, stage one manifest per member under
//      a name that carries the generation, and publish ONE capacity-checked
//      `prepared` journal record only once every file and identity is durable.
//   7. Wait -- bounded -- for the real guarded update.php child to raise the
//      durable acknowledgement to this exact generation. The producer never
//      writes that acknowledgement itself; an accepted registration is not one.
//   8. Re-take the hash locks and the state lock, revalidate the COMPLETE
//      prepared binding (generation, phase, sorted hashes, force, cardinality,
//      every staging path with its current dev/ino, and the schedule/user
//      binding), write `erase-started` durably, and only then erase.
//
// Every step that cannot be completed leaves either a clean refusal (when the
// admission was not yet durable) or a self-describing retryable obligation
// (once it was). Nothing anywhere gives up on an obligation after N attempts.
// ---------------------------------------------------------------------------

if(!defined('ERASEDATA_DRAIN_STATE_VERSION'))
	define('ERASEDATA_DRAIN_STATE_VERSION', 1);
// Dot-prefixed on purpose: the manifest grammar erasedataParseCollectorCandidate()
// scans for never matches a leading dot, so the plugin's own control files can
// never be mistaken for somebody's staged manifest.
if(!defined('ERASEDATA_DRAIN_STATE_NAME'))
	define('ERASEDATA_DRAIN_STATE_NAME', '.drain-state');
if(!defined('ERASEDATA_DRAIN_STATE_LOCK_NAME'))
	define('ERASEDATA_DRAIN_STATE_LOCK_NAME', '.drain-state.lock');
// A full journal of ERASEDATA_DRAIN_MAX_JOURNAL single-hash records is over a
// megabyte, and grows with the queue path because every record spells its
// member's staging path in full: measured at 1.15 MiB with a two-character
// $listPath and 1.37 MiB with a full settings path. The ceiling keeps several
// times that headroom and is still small enough that the state can never
// become a memory ceiling of its own.
if(!defined('ERASEDATA_DRAIN_STATE_MAX_BYTES'))
	define('ERASEDATA_DRAIN_STATE_MAX_BYTES', 8388608);
// Cardinality caps. Exceeding one is a refusal, never an eviction: dropping an
// active journal record to fit a bound would erase a download whose obligation
// nothing was left to describe. Deliberately at or below the queue's own
// ERASEDATA_PENDING_MAX_GENERATIONS, so the queue refuses first.
if(!defined('ERASEDATA_DRAIN_MAX_JOURNAL'))
	define('ERASEDATA_DRAIN_MAX_JOURNAL', 4095);
// The diagnostic memory has to cover what the QUEUE can legally hold, not a
// number chosen independently of it. It is what keeps an unchanged condition
// from being reported again on the next tick, and the drain fires every
// ERASEDATA_DRAIN_INTERVAL seconds for as long as an obligation is outstanding,
// so a generation whose digest does not fit is a generation reported for ever.
// At 32 the bound held for the first 32 generations and silently stopped
// holding for the rest, while the journal admits ERASEDATA_DRAIN_MAX_JOURNAL of
// them -- measured: with 33 admitted generations, the 33rd repeated verbatim on
// every tick.
//
// The bound is the UNION of the two sets, not either one. A tick's tasks come
// from the pending projection and from the carried journal, and the two are
// capped separately: a published final manifest takes its generation out of the
// pending projection (pending.php) while an unfinished journal record still
// yields a task, so journal-only generations are disjoint from pending-only
// ones and the worst case is the sum. Bounding by the journal alone left the
// same hole one order of magnitude further out -- measured, a queue of 10
// journal generations and 4095 marker-only ones produced 4105 tasks and four
// digests that did not fit, and those four repeated verbatim on every tick.
//
// One entry is a key and a sha1: 57 bytes for a generation, so the whole
// admissible union plus the fixed groups is about 470 KB against the 8 MiB
// state ceiling above. The headroom is for those fixed groups ('tick',
// 'retire', 'manifest', 'cleanup'), which are few and named rather than
// counted.
//
// ERASEDATA_PENDING_MAX_GENERATIONS is in scope here: filesystem.php, required
// above, requires pending.php.
if(!defined('ERASEDATA_DRAIN_MAX_DIAGNOSTICS'))
	define('ERASEDATA_DRAIN_MAX_DIAGNOSTICS',
		ERASEDATA_PENDING_MAX_GENERATIONS + ERASEDATA_DRAIN_MAX_JOURNAL + 8);
if(!defined('ERASEDATA_DRAIN_MAX_DIAGNOSTIC_BYTES'))
	define('ERASEDATA_DRAIN_MAX_DIAGNOSTIC_BYTES', 512);
if(!defined('ERASEDATA_DRAIN_MAX_USER_BYTES'))
	define('ERASEDATA_DRAIN_MAX_USER_BYTES', 255);
if(!defined('ERASEDATA_DRAIN_ZERO_GENERATION'))
	define('ERASEDATA_DRAIN_ZERO_GENERATION', '0000000000000000');
// Never 'erasedata' alone: done.php removes the periodic erasedata<User> key
// when the plugin is switched off, and the drain key must survive that.
if(!defined('ERASEDATA_DRAIN_KEY_PREFIX'))
	define('ERASEDATA_DRAIN_KEY_PREFIX', 'erasedata-drain');
// The drain tick is short because a public door waits for it: rTorrent starts
// the guarded child on the next tick, and the producer that armed the schedule
// cannot erase until that child has acknowledged the generation. Five seconds
// gives the acknowledgement two chances inside a wait that still fits a default
// max_execution_time, and the schedule is retired again as soon as the queue is
// provably empty, so the tick is not a standing cost.
if(!defined('ERASEDATA_DRAIN_INTERVAL'))
	define('ERASEDATA_DRAIN_INTERVAL', 5);
if(!defined('ERASEDATA_DRAIN_ACK_TIMEOUT'))
	define('ERASEDATA_DRAIN_ACK_TIMEOUT', 11.0);
if(!defined('ERASEDATA_DRAIN_ACK_POLL'))
	define('ERASEDATA_DRAIN_ACK_POLL', 0.05);

// The worker admission lock. It is taken NONBLOCKING and it is taken AFTER the
// acknowledgement: a tick that cannot get in has still told the producer that
// the schedule really fires, which is the whole point of the acknowledgement.
if(!defined('ERASEDATA_DRAIN_WORKER_LOCK_NAME'))
	define('ERASEDATA_DRAIN_WORKER_LOCK_NAME', '.drain-worker.lock');
// The pass lock the periodic collector already uses. The drain worker shares
// it so a drain tick and a periodic sweep can never walk the same queue at
// once; the drain worker takes it BLOCKING, the periodic pass keeps LOCK_NB.
if(!defined('ERASEDATA_DRAIN_SCHEDULER_LOCK_NAME'))
	define('ERASEDATA_DRAIN_SCHEDULER_LOCK_NAME', 'scheduler.lock');
// How many times ONE admission may re-enter the arm compare-and-swap before it
// gives up.
//
// This is NOT a retry cap on an obligation and invariant 9 is untouched by it:
// nothing has been written when it applies. The arm is a compare-and-swap and
// two producers started together from a RESTING queue necessarily collide in
// it -- both read the same durable state, both write their own `arming`, and
// the one that wrote first no longer finds its own generation when it comes
// back from the registration. Before retirement worked, `armed` was the resting
// state and the second producer never entered the window at all; now `disarmed`
// is the resting state and the collision is the COMMON case, measured 6 of 6 on
// a real daemon. The loser stages nothing and touches no payload, so it is
// safe, but "select two torrents, remove with data" silently did nothing for
// one of them. A loser re-enters instead, which resolves the ordinary collision
// in one pass: the winner's arm is durable `armed` by then, and re-reading it
// under the state lock is exactly the already-armed path a second removal on a
// live queue has always taken.
if(!defined('ERASEDATA_ADMISSION_ARM_ATTEMPTS'))
	define('ERASEDATA_ADMISSION_ARM_ATTEMPTS', 3);
// One erase costs exactly three commands -- d.set_custom5, d.delete_tied,
// d.erase -- for every member of the batch, in that order. The length of the
// reply list is therefore the only thing that makes a member's outcome
// INDIVIDUALLY known, so this constant is the whole arithmetic of invariant 2
// and it must stay in step with every builder of that request.
if(!defined('ERASEDATA_ERASE_COMMANDS_PER_HASH'))
	define('ERASEDATA_ERASE_COMMANDS_PER_HASH', 3);

if(!function_exists('erasedataDrainScheduleKey'))
{
	// The one fixed per-user key, or false. Never the periodic collector key:
	// done.php removes 'erasedata'.User::getUser() and must not take the drain
	// schedule with it.
	//
	// THE EMPTY USER IS A REAL USER. User::getUser() answers '' on every install
	// without HTTP authentication and on every install with
	// $forbidUserSettings = true -- including the shipped image's own generated
	// config -- and erase.php has documented it that way since the exact base
	// ("the ruTorrent user, on multi-user installs. Trails the other arguments
	// because it is empty on a single-user install"). Refusing '' here refused
	// EVERY removal on a single-user install: a functional regression against
	// the base, not hardening. The empty user's key is the bare prefix
	// 'erasedata-drain', still distinct from the ordinary collector key
	// 'erasedata' that done.php removes, and the child it starts receives the
	// same empty user as its first argv word.
	//
	// What must never be tolerated is a MISMATCH between the user a producer
	// admitted under and the user a child, a retirement or a re-arm carries.
	// erasedataDrainStateBelongsTo() binds that exactly; emptiness itself is
	// not a mismatch.
	function erasedataDrainScheduleKey($user)
	{
		if(!is_string($user)
			|| strlen($user) > ERASEDATA_DRAIN_MAX_USER_BYTES
			|| strpos($user, "\0") !== false)
			return(false);
		return(ERASEDATA_DRAIN_KEY_PREFIX.$user);
	}
}

if(!function_exists('erasedataDrainRescueScheduleKey'))
{
	function erasedataDrainRescueScheduleKey($user)
	{
		$key = erasedataDrainScheduleKey($user);
		return($key === false ? false : 'erasedata-rescue-drain'.$user);
	}
}

if(!function_exists('erasedataDrainStateBelongsTo'))
{
	// May $user act on this durable state?
	//
	// Exactly one of two things has to be true: the state names this very user,
	// or it is the PRISTINE default -- a queue no admission has ever claimed.
	// The pristine default is recognised by what it carries, not by its empty
	// user string: the zero generation, the zero acknowledgement, the disarmed
	// phase and an empty journal. That distinction is what makes the empty user
	// safe to accept as a real user. Before it, `$state['user'] !== ''` was read
	// as "unclaimed", which silently made the empty user match every queue on a
	// multi-user install once '' became a legitimate value.
	function erasedataDrainStateBelongsTo($state, $user)
	{
		if(!is_array($state) || !isset($state['user']) || !is_string($user))
			return(false);
		if($state['user'] === $user)
			return(true);
		return(isset($state['generation'], $state['acknowledged'], $state['phase'],
				$state['journal'])
			&& $state['user'] === ''
			&& $state['generation'] === ERASEDATA_DRAIN_ZERO_GENERATION
			&& $state['acknowledged'] === ERASEDATA_DRAIN_ZERO_GENERATION
			&& $state['phase'] === 'disarmed'
			&& is_array($state['journal']) && count($state['journal']) === 0);
	}
}

if(!function_exists('erasedataDrainStatePath'))
{
	function erasedataDrainStatePath($listPath)
	{
		if(!is_string($listPath) || $listPath === '' || strpos($listPath, "\0") !== false)
			return(false);
		return($listPath.'/'.ERASEDATA_DRAIN_STATE_NAME);
	}
}

if(!function_exists('erasedataDefaultDrainState'))
{
	// What a queue that has never been armed reads as.
	//
	// The empty user here is NOT a claim by the single-user install's empty
	// user: what marks this state as unclaimed is the whole shape -- zero
	// generation, zero acknowledgement, `disarmed`, empty journal -- and
	// erasedataDrainStateBelongsTo() reads exactly that shape. An install whose
	// user really is '' writes a state with a nonzero generation the moment it
	// admits anything, so the two are never confused.
	function erasedataDefaultDrainState()
	{
		return(array(
			'version' => ERASEDATA_DRAIN_STATE_VERSION,
			'user' => '',
			'generation' => ERASEDATA_DRAIN_ZERO_GENERATION,
			'acknowledged' => ERASEDATA_DRAIN_ZERO_GENERATION,
			'phase' => 'disarmed',
			'journal' => array(),
			'diagnostics' => array(),
		));
	}
}

if(!function_exists('erasedataDrainStatePhases'))
{
	function erasedataDrainStatePhases()
	{
		return(array('disarmed', 'arming', 'armed', 'settled'));
	}
}

if(!function_exists('erasedataDrainJournalPhases'))
{
	// prepared      -- staged and bound, nothing destructive attempted yet;
	// erase-started -- the complete binding was revalidated and d.erase is or
	//                  was in flight, so recovery must stay conservative;
	// published     -- erased and published, awaiting the collector;
	// retained      -- admitted but unresolved, to be retried with no cap.
	function erasedataDrainJournalPhases()
	{
		return(array('prepared', 'erase-started', 'published', 'retained'));
	}
}

if(!function_exists('erasedataValidateDrainStaging'))
{
	// One staging binding: the exact path a manifest was written to and the
	// physical identity it had when it was written. The name is checked against
	// the hash and the generation it is filed under, so a record can never bind
	// a file belonging to another hash or another generation of the same hash.
	function erasedataValidateDrainStaging($staging, $listPath, $hash, $generation)
	{
		$keys = array('path', 'dev', 'ino');
		if(!is_array($staging) || count($staging) !== count($keys))
			return(false);
		foreach($keys as $key)
			if(!array_key_exists($key, $staging))
				return(false);
		$path = $staging['path'];
		if(!is_string($path) || $path === ''
			|| strlen($path) > ErasedataManifestCodec::MAX_PATH_BYTES
			|| strpos($path, "\0") !== false
			|| strpos($path, $listPath.'/') !== 0)
			return(false);
		$name = substr($path, strlen($listPath) + 1);
		// $hash and $generation are already proven to be [0-9A-F]{40} and
		// [0-9a-f]{16}, so neither can carry a metacharacter into the pattern.
		if(strpos($name, '/') !== false
			|| preg_match('/^'.$hash.'\.'.$generation.'\.[A-Za-z0-9._-]+\.tmp$/D', $name) !== 1)
			return(false);
		foreach(array('dev', 'ino') as $key)
			if(!is_int($staging[$key]) && !(is_string($staging[$key])
				&& preg_match('/^-?[0-9]{1,20}$/D', $staging[$key]) === 1))
				return(false);
		return(true);
	}
}

if(!function_exists('erasedataValidateDrainJournalEntry'))
{
	// One journal record binds a generation to an exact force, an exact
	// canonical sorted unique hash set, and one staging path plus captured
	// dev/ino per member. Exact keys, exact types, exact cross-field agreement,
	// no unknown key, fail closed.
	function erasedataValidateDrainJournalEntry($entry, $listPath, $generation)
	{
		$keys = array('phase', 'force', 'hashes', 'staging');
		if(!is_array($entry) || count($entry) !== count($keys))
			return(false);
		foreach($keys as $key)
			if(!array_key_exists($key, $entry))
				return(false);
		if(!is_string($entry['phase'])
			|| !in_array($entry['phase'], erasedataDrainJournalPhases(), true))
			return(false);
		// Refused, not coerced: an unreadable force is not "delete the
		// download's own files".
		if($entry['force'] !== 1 && $entry['force'] !== 2)
			return(false);
		if(!is_array($entry['hashes']))
			return(false);
		$members = count($entry['hashes']);
		if($members < 1 || $members > ERASEDATA_PENDING_MAX_MARKERS)
			return(false);
		$index = 0;
		$previous = null;
		foreach($entry['hashes'] as $key => $hash)
		{
			// Strictly ascending is sorted AND deduplicated in one test, and a
			// non-sequential key means the list was rewritten by something that
			// did not understand it.
			if($key !== $index++ || !erasedataIsCanonicalPendingHash($hash)
				|| ($previous !== null && strcmp($hash, $previous) <= 0))
				return(false);
			$previous = $hash;
		}
		if(!is_array($entry['staging']) || count($entry['staging']) !== $members)
			return(false);
		foreach($entry['hashes'] as $hash)
			if(!array_key_exists($hash, $entry['staging'])
				|| !erasedataValidateDrainStaging($entry['staging'][$hash],
					$listPath, $hash, $generation))
				return(false);
		return(true);
	}
}

if(!function_exists('erasedataValidateDrainState'))
{
	// The complete bounded drain-state schema. Anything this does not recognise
	// exactly is refused rather than repaired: a state nobody can account for
	// must not be the basis of a decision to delete somebody's data.
	function erasedataValidateDrainState($state, $listPath)
	{
		if(!is_array($state) || !is_string($listPath) || $listPath === '')
			return(false);
		$keys = array('version', 'user', 'generation', 'acknowledged', 'phase',
			'journal', 'diagnostics');
		// Older states have seven fields. The optional flags record a verified
		// legacy marker and a rescue registration that may need retirement.
		$optional = 0;
		foreach(array('legacy_marker', 'legacy_rescue') as $flag)
			if(array_key_exists($flag, $state))
			{
				if($state[$flag] !== true)
					return(false);
				$optional++;
			}
		if(count($state) !== count($keys) + $optional)
			return(false);
		foreach($keys as $key)
			if(!array_key_exists($key, $state))
				return(false);
		if($state['version'] !== ERASEDATA_DRAIN_STATE_VERSION)
			return(false);
		// '' is a legitimate user (see erasedataDrainScheduleKey): a queue whose
		// owner is the single-user install's empty user must be writable and
		// readable exactly like anybody else's.
		if(!is_string($state['user'])
			|| strlen($state['user']) > ERASEDATA_DRAIN_MAX_USER_BYTES
			|| strpos($state['user'], "\0") !== false)
			return(false);
		if(!erasedataGenerationIsValid($state['generation'])
			|| !erasedataGenerationIsValid($state['acknowledged']))
			return(false);
		// An acknowledgement ahead of the generation it acknowledges is not a
		// state this producer could have written.
		if(erasedataGenerationCompare($state['acknowledged'], $state['generation']) !== -1
			&& erasedataGenerationCompare($state['acknowledged'], $state['generation']) !== 0)
			return(false);
		if(!is_string($state['phase'])
			|| !in_array($state['phase'], erasedataDrainStatePhases(), true))
			return(false);
		if(!is_array($state['journal'])
			|| count($state['journal']) > ERASEDATA_DRAIN_MAX_JOURNAL)
			return(false);
		foreach($state['journal'] as $generation => $entry)
		{
			// json_decode() hands back an int key for a generation that happens
			// to be a canonical decimal integer, so compare the string form.
			$generation = (string)$generation;
			if(!erasedataGenerationIsValid($generation)
				|| erasedataGenerationCompare($generation, $state['generation']) === 1
				|| erasedataGenerationCompare($generation, $state['generation']) === false
				|| !erasedataValidateDrainJournalEntry($entry, $listPath, $generation))
				return(false);
		}
		if(!is_array($state['diagnostics'])
			|| count($state['diagnostics']) > ERASEDATA_DRAIN_MAX_DIAGNOSTICS)
			return(false);
		$index = 0;
		foreach($state['diagnostics'] as $key => $line)
			if($key !== $index++ || !is_string($line) || $line === ''
				|| strlen($line) > ERASEDATA_DRAIN_MAX_DIAGNOSTIC_BYTES)
				return(false);
		return(true);
	}
}

if(!function_exists('erasedataDirectoryLookupsAnswer'))
{
	// Can a lookup of a child of $directory actually ANSWER in this process, or
	// would "not there" only ever mean "I was not allowed to look"?
	//
	// A directory at mode 0400 is readable but not SEARCHABLE. scandir() lists
	// it while stat(), is_file(), is_link() and file_get_contents() of every
	// child fail with EACCES -- and PHP reports each of those failures as the
	// same plain false a genuinely missing file gives. An absence gate that does
	// not ask this question is therefore fail-OPEN, not fail-closed, however
	// carefully it is worded: it reads an armed queue holding a live obligation
	// as pristine. Absence may only be concluded from a lookup that could have
	// succeeded, and the search bit is what decides that.
	//
	// is_executable() is access(X_OK): it answers for THIS process's REAL
	// uid/gid rather than for the mode alone -- and real and effective are equal
	// in both processes this plugin runs in, php-fpm and the CLI child, so that
	// is the identity that matters here. It is itself a stat of $directory, so an
	// unsearchable ancestor makes it false too -- which is the conservative
	// answer this wants. It is not a permission check on anything else: nothing
	// here repairs, grants or reports a mode.
	function erasedataDirectoryLookupsAnswer($directory)
	{
		if(!is_string($directory) || $directory === '' || strpos($directory, "\0") !== false)
			return(false);
		clearstatcache(true, $directory);
		return(is_dir($directory) && is_executable($directory));
	}

	// The same question about one exact path: the directory that would hold it
	// has to permit the lookup before "$path is not there" may be believed.
	function erasedataPathLookupAnswers($path)
	{
		if(!is_string($path) || $path === '' || strpos($path, "\0") !== false)
			return(false);
		$directory = dirname($path);
		return($directory !== $path && erasedataDirectoryLookupsAnswer($directory));
	}
}

if(!function_exists('erasedataReadDrainState'))
{
	// The durable drain state, or false.
	//
	// Absent is a KNOWN state -- this queue has never been armed -- and reads
	// as the default disarmed state. Present but unreadable, unparseable or
	// outside the schema is NOT known and fails closed, because a caller that
	// took "unreadable" for "nothing to do here" would arm a second schedule
	// over an obligation it could not see.
	//
	// "Absent" is a claim about a lookup that ANSWERED. file_exists() and
	// is_link() both answer false for a file inside a directory this process may
	// not search, exactly as they do for a file that is not there, so the two are
	// separated by erasedataPathLookupAnswers() and never by those two calls
	// alone.
	function erasedataReadDrainState($listPath)
	{
		$path = erasedataDrainStatePath($listPath);
		if($path === false)
			return(false);
		clearstatcache(true, $path);
		$bytes = @file_get_contents($path, false, null, 0,
			ERASEDATA_DRAIN_STATE_MAX_BYTES + 1);
		if($bytes === false || $bytes === '')
		{
			clearstatcache(true, $path);
			if($bytes === '' || file_exists($path) || is_link($path))
				return(false);
			// Nothing was found -- but only a lookup that could answer is allowed
			// to turn that into "this queue has never been armed".
			if(!erasedataPathLookupAnswers($path))
				return(false);
			return(erasedataDefaultDrainState());
		}
		if(strlen($bytes) > ERASEDATA_DRAIN_STATE_MAX_BYTES)
			return(false);
		$decoded = json_decode($bytes, true);
		if(!is_array($decoded) || !erasedataValidateDrainState($decoded, $listPath))
			return(false);
		return($decoded);
	}
}

if(!function_exists('erasedataWriteDrainState'))
{
	// Publish the drain state durably, or refuse. Validation happens before a
	// byte is written, so a state this process would refuse to read back is
	// never the state it leaves behind.
	function erasedataWriteDrainState($listPath, array $state)
	{
		$path = erasedataDrainStatePath($listPath);
		if($path === false || !erasedataValidateDrainState($state, $listPath))
			return(false);
		$bytes = json_encode($state);
		if(!is_string($bytes) || $bytes === ''
			|| strlen($bytes) > ERASEDATA_DRAIN_STATE_MAX_BYTES)
			return(false);
		return(erasedataWriteDurableFile($path, $bytes) === true);
	}
}

if(!function_exists('erasedataAcquireDrainStateLock'))
{
	// The state/journal lock. Invariant 7: this is taken AFTER the per-hash
	// locks and is never held while waiting for one of them.
	function erasedataAcquireDrainStateLock($listPath, $nonBlocking = false)
	{
		if(!is_string($listPath) || $listPath === '' || strpos($listPath, "\0") !== false)
			return(false);
		$path = $listPath.'/'.ERASEDATA_DRAIN_STATE_LOCK_NAME;
		$handle = @fopen($path, 'c');
		if($handle === false)
			return(false);
		// Shared between the web user and whoever rTorrent runs the child as.
		@chmod($path, erasedataSharedFileMode());
		if(!@flock($handle, LOCK_EX | ($nonBlocking ? LOCK_NB : 0)))
		{
			@fclose($handle);
			return(false);
		}
		return($handle);
	}
}

if(!function_exists('erasedataReleaseDrainStateLock'))
{
	function erasedataReleaseDrainStateLock($handle)
	{
		if(!is_resource($handle))
			return(false);
		$released = @flock($handle, LOCK_UN);
		return(@fclose($handle) === true && $released);
	}
}

if(!function_exists('erasedataReleaseAdmissionLocks'))
{
	// Innermost first: the state lock comes off before the hash locks, so the
	// acquisition order is never inverted on the way out either.
	function erasedataReleaseAdmissionLocks(&$stateLock, &$locks)
	{
		if(is_resource($stateLock))
			erasedataReleaseDrainStateLock($stateLock);
		$stateLock = null;
		if(is_array($locks))
			erasedataUnlockObligations($locks);
		$locks = null;
	}
}

if(!function_exists('erasedataIsStagingObjectName'))
{
	// The only filename a diagnostic may print, and the shape
	// erasedataStageAdmittedManifest() writes: hash, generation, pid and
	// uniqid, which is a classified token by construction -- no payload path,
	// no settings root, no byte anybody outside this plugin chose. Anything
	// else found in the queue directory is dropped rather than echoed, so a
	// name somebody else placed there cannot put arbitrary text into the log.
	function erasedataIsStagingObjectName($name)
	{
		return(is_string($name) && strlen($name) <= 200
			&& preg_match('/^[0-9A-Fa-f]{40}\\.[0-9a-f]{16}\\.[0-9A-Za-z._-]{1,96}\\.tmp$/D',
				$name) === 1);
	}
}

if(!function_exists('erasedataAdmissionRefusalReason'))
{
	// The request scope begins when the public admission wrapper resets this value.
	// Diagnostics below record a classified reason without exposing remote text.
	function erasedataAdmissionRefusalReason($reason = null)
	{
		static $last = 'admission-refused';
		if($reason !== null)
			$last = is_string($reason)
				&& preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $reason)
				? $reason : 'admission-refused';
		return($last);
	}
}

if(!function_exists('erasedataRemovalRefusalMessage'))
{
	function erasedataRemovalRefusalMessage($reason)
	{
		switch($reason)
		{
			case 'hash-lock': return('Torrent busy; try again.');
			case 'state-lock': return('Deletion queue busy; try again.');
			case 'queue-unavailable': return('Deletion queue unavailable.');
			case 'drain-no-ack': return('Deletion queue did not acknowledge the request.');
			case 'invalid-hash': return('Invalid deletion request: missing or invalid hash.');
			case 'invalid-request': return('Invalid deletion request.');
			default: return('Deletion refused ('.$reason.'). Check the server log.');
		}
	}
}

if(!function_exists('erasedataReportPartialRemovalRefusal'))
{
	// Admission can erase accepted members while refusing others. Keep the
	// partial result, but make the refusal visible without logging raw inputs.
	function erasedataReportPartialRemovalRefusal($result)
	{
		if(!is_array($result) || !isset($result['refused'])
			|| !is_array($result['refused']))
			return;
		$counts = array('invalid-hash' => 0, 'descriptor-unavailable' => 0,
			'unknown' => 0);
		foreach($result['refused'] as $member)
		{
			if(is_string($member) && strpos($member, 'invalid-hash:') === 0)
				$counts['invalid-hash']++;
			else if(erasedataIsCanonicalPendingHash($member))
				$counts['descriptor-unavailable']++;
			else
				$counts['unknown']++;
		}
		foreach($counts as $reason => $members)
			if($members)
				FileUtil::toLog('erasedata: removewithdata partially refused: reason='
					.$reason.' members='.$members);
	}
}

if(!function_exists('erasedataDrainDiagnostic'))
{
	// One bounded, classified, unconditional line: reason, the generation it
	// belongs to, how many members it covers and what the consequence is.
	// Never a raw path, a settings root, a manifest byte, a hash LIST or any
	// text rTorrent sent us.
	//
	// `file=` is the one exception, and it is a bare name, never a directory: a
	// refusal that only a human can repair has to name the thing they must act
	// on, and the pid and uniqid in a staging object's name cannot be derived
	// from the hash and the generation. It is printed only for a name of that
	// exact shape.
	function erasedataDrainDiagnostic($log, $reason, $generation, $members,
		$consequence, $hash = null, $object = null)
	{
		erasedataAdmissionRefusalReason($reason);
		$message = 'erasedata: '.$reason
			.' generation='.(erasedataGenerationIsValid($generation) ? $generation : 'none')
			.' members='.(int)$members
			.(erasedataIsCanonicalPendingHash($hash) ? ' hash='.$hash : '')
			.(erasedataIsStagingObjectName($object) ? ' file='.$object : '')
			.' consequence='.$consequence;
		if(is_callable($log))
			call_user_func($log, $message);
		else
			FileUtil::toLog($message);
		return($message);
	}
}

if(!function_exists('erasedataAdmissionPartition'))
{
	// The complete deterministic partition of the request set, computed before
	// any side effect whatsoever.
	//
	// A is the canonical uppercase, deduplicated, sorted set of admissible
	// hashes; F holds one classified token per inadmissible member, so |R| is
	// exactly |A| + |F| over the canonical unique request set and the two are
	// disjoint by construction. F carries a token rather than the value it was
	// handed: a refusal must be reportable without echoing whatever arrived.
	//
	// Force is the integer 1 or 2 and nothing else. The wire spellings "1" and
	// "2" are normalized by erasedataAdmitRemoval() before this function;
	// accepting them here as well would make that boundary optional and let a
	// footer-injection string reach a decision about which root to delete.
	function erasedataAdmissionPartition($hashes, $force)
	{
		if($force !== 1 && $force !== 2)
			return(false);
		if(!is_array($hashes))
			return(false);
		$accepted = array();
		$refused = array();
		$seen = array();
		foreach($hashes as $hash)
		{
			$canonical = erasedataCanonicalHash($hash);
			if($canonical === false)
			{
				$refused[] = 'invalid-hash:'.gettype($hash);
				continue;
			}
			if(isset($seen[$canonical]))
				continue;
			$seen[$canonical] = true;
			$accepted[] = $canonical;
		}
		sort($accepted, SORT_STRING);
		return(array(
			'force' => $force,
			'accepted' => $accepted,
			'refused' => $refused,
		));
	}
}

if(!function_exists('erasedataDrainWorkerCommand'))
{
	// The command line the scheduled tick really runs: the guarded worker entry
	// point, with the exact user the schedule key was built from -- '' included,
	// which is a real user on every single-user install. Every argument is
	// escapeshellarg()'d, because rTorrent hands this to sh.
	function erasedataDrainWorkerCommand($user)
	{
		if(erasedataDrainScheduleKey($user) === false)
			return(false);
		return(escapeshellarg(Utility::getPHP())
			.' '.escapeshellarg(dirname(__FILE__).'/update.php')
			.' '.escapeshellarg($user)
			.' '.escapeshellarg('drain'));
	}
}

if(!function_exists('erasedataDrainScheduleCommand'))
{
	// The repeating registration for one user's drain worker.
	//
	// The LOGICAL key 'schedule' goes to the constructor, never the resolved
	// spelling the command map hands back for it. rXMLRPCCommand::__construct()
	// calls rTorrentSettings::patchDeprecatedCommand($this, $cmd) BEFORE it
	// appends a single argument, and that helper looks the alias up by the name
	// it was HANDED. Hand it an already-resolved name and it finds no alias,
	// never adds the leading empty target, and rTorrent 0.9.x answers
	// "Unsupported target type found." while 0.16.x answers "invalid
	// parameters: invalid target" -- both measured on real daemons. Passing the
	// logical key is what makes one call correct on both, and it is exactly
	// what rTorrentSettings::getAlignedScheduleCommand() does; that method is
	// not used here only because it takes its user from User::getUser() rather
	// than from the injected dependencies this runner is handed.
	//
	// getCmd('execute') inside the scheduled string is a different matter and
	// is right: that string is parsed by rTorrent itself at tick time, so it
	// must carry the resolved name, exactly as the periodic collector schedule
	// erasedataCollectorScheduleCommand() builds does.
	//
	// THE START IS ALIGNED, not `now + interval`. rTorrent replaces the entry
	// for a reused key and restarts its countdown at now+start, and
	// php/getplugins.php re-runs every enabled plugin's init.php on EVERY full
	// load of the web interface, so a `start == interval` registration let two
	// page reloads inside the 11 s acknowledgement window push the first tick
	// past a blocked producer's timeout -- and reloading is exactly what a user
	// does when a deletion looks stuck. rTorrentSettings::getAlignedStart()
	// exists for precisely this bug (php/settings.php:450-484 documents it) and
	// the sibling erasedataCollectorScheduleCommand() already uses it: it
	// resolves every registration of one key to the same absolute fire instant,
	// so total postponement is capped at one interval however many
	// re-registrations occur -- with no liveness probe. It never returns
	// 0, so a reload can never fire the
	// child at once either.
	function erasedataDrainScheduleCommand($user, $interval, $rescue = false)
	{
		$key = $rescue ? erasedataDrainRescueScheduleKey($user)
			: erasedataDrainScheduleKey($user);
		$command = erasedataDrainWorkerCommand($user);
		$interval = (int)$interval;
		if($key === false || $command === false || $interval < 1)
			return(false);
		$start = rTorrentSettings::getAlignedStart($key, $interval);
		if(!is_int($start) || $start < 1)
			$start = $interval;
		return(new rXMLRPCCommand('schedule', array($key, (string)$start,
			(string)$interval,
			getCmd('execute').'={sh,-c,'.$command.' </dev/null >/dev/null 2>&1 &}')));
	}
}


// A daemon-local witness for the old scheduler, which has no if-absent API.
// A durable armed state may trust its absence only after this protocol has
// marked that state. The marker itself is volatile and disappears on restart.
if(!function_exists('erasedataLegacyDrainMarkerName'))
{
	function erasedataLegacyDrainMarkerName($user)
	{
		return(erasedataDrainScheduleKey($user) === false ? false
			: 'erasedata.drain.epoch.'.substr(hash('sha256', $user), 0, 32));
	}
}

// true: confirmed daemon before 0.16.21 without schedule.if_absent;
// false: version outside that legacy range; null: unknown. A failed probe cannot choose.
// 0.16.8 was probed live; the marker RPC still fails closed on other old builds.
if(!function_exists('erasedataLegacyDrainVersion'))
{
	function erasedataLegacyDrainVersion()
	{
		$request = new rXMLRPCRequest(new rXMLRPCCommand('system.client_version'));
		$request->important = false;
		if(!$request->success() || !is_array($request->val)
			|| count($request->val) !== 1 || !is_string($request->val[0])
			|| preg_match('/^[0-9]+(?:\.[0-9]+){2}$/D', $request->val[0]) !== 1)
			return(null);
		$version = $request->val[0];
		return($version === '0.9.8'
			|| (version_compare($version, '0.16.0', '>=')
				&& version_compare($version, '0.16.21', '<')));
	}
}

if(!function_exists('erasedataLegacyDrainMarkerStatus'))
{
	// true: exact marker; false: missing-method fault measured on 0.9.8 and 0.16.8;
	// null: transport, daemon or response uncertainty, which cannot prove loss.
	function erasedataLegacyDrainMarkerStatus($user)
	{
		$name = erasedataLegacyDrainMarkerName($user);
		if($name === false)
			return(null);
		$request = new rXMLRPCRequest(new rXMLRPCCommand($name));
		$request->important = false;
		if($request->success() && is_array($request->val)
			&& count($request->val) === 1 && (string)$request->val[0] === '1')
			return(true);
		if($request->fault && isset($request->rawFaultString)
			&& $request->rawFaultString === "Method '".$name."' not defined")
			return(false);
		return(null);
	}
}

if(!function_exists('erasedataLegacyDrainMarkerEnsure'))
{
	function erasedataLegacyDrainMarkerEnsure($user)
	{
		$status = erasedataLegacyDrainMarkerStatus($user);
		if($status === true)
			return(true);
		if($status !== false)
			return(false);
		$name = erasedataLegacyDrainMarkerName($user);
		$request = new rXMLRPCRequest(new rXMLRPCCommand('system.method.insert',
			array($name, 'value|const', '1')));
		$request->important = false;
		return($request->success()
			&& erasedataLegacyDrainMarkerStatus($user) === true);
	}
}

if(!function_exists('erasedataStageAdmittedManifest'))
{
	// One manifest, staged under a name that carries the generation it was
	// admitted under, published durably and identified by its physical dev/ino.
	//
	// The generation is in the NAME because two physical torrents can share an
	// infohash: a bare <hash>.list belongs to whichever of them wrote it, and
	// must never be able to acknowledge the other one.
	function erasedataStageAdmittedManifest($listPath, $hash, $generation, $contents)
	{
		$canonical = erasedataCanonicalHash($hash);
		if($canonical === false || !is_string($listPath) || !is_dir($listPath)
			|| !is_string($contents) || !erasedataGenerationIsValid($generation))
			return(false);
		$manifest = ErasedataManifestCodec::decodeBytes($contents, $canonical);
		if($manifest === false || !isset($manifest['operation'])
			|| $manifest['operation'] !== ErasedataManifestCodec::OPERATION_REMOVE_PAYLOAD)
			return(false);
		$pid = getmypid();
		$path = $listPath.'/'.$canonical.'.'.$generation.'.'
			.($pid === false ? '0' : (string)$pid).'.'.uniqid('', true).'.tmp';
		clearstatcache(true, $path);
		if(file_exists($path) || is_link($path))
			return(false);
		if(erasedataWriteDurableFile($path, $contents) !== true)
			return(false);
		clearstatcache(true, $path);
		$stat = @lstat($path);
		if(is_link($path) || !is_file($path) || !is_array($stat)
			|| !isset($stat['mode'], $stat['dev'], $stat['ino'])
			|| (($stat['mode'] & 0170000) !== 0100000))
		{
			if(is_array($stat))
				erasedataUnlinkExactStagedFile($path, $stat);
			return(false);
		}
		return(array('path' => $path, 'stat' => $stat));
	}
}

if(!function_exists('erasedataUnlinkAdmittedStaging'))
{
	// Remove one staging file, and only if it is still the exact object the
	// journal bound. A name is not an identity: something else may hold that
	// name by now, and rolling back a later generation's staging -- or an
	// unrelated file that merely reuses the name -- would destroy work this
	// producer never owned.
	function erasedataUnlinkAdmittedStaging($record, ?ErasedataFilesystemOps $filesystem = null)
	{
		if(!is_array($record) || !isset($record['path'], $record['dev'], $record['ino'])
			|| !is_string($record['path']))
			return(false);
		$path = $record['path'];
		clearstatcache(true, $path);
		if(is_link($path) || !is_file($path))
			return(false);
		$stat = @lstat($path);
		if(!is_array($stat) || !isset($stat['dev'], $stat['ino'], $stat['mode'])
			|| (($stat['mode'] & 0170000) !== 0100000)
			|| (string)$stat['dev'] !== (string)$record['dev']
			|| (string)$stat['ino'] !== (string)$record['ino'])
			return(false);
		return(erasedataUnlinkExactStagedFile($path, $stat, null, $filesystem));
	}
}

if(!function_exists('erasedataRollbackAdmittedStaging'))
{
	function erasedataRollbackAdmittedStaging(array $staging,
		?ErasedataFilesystemOps $filesystem = null)
	{
		$rolled = true;
		foreach($staging as $record)
			if(!erasedataUnlinkAdmittedStaging($record, $filesystem))
				$rolled = false;
		return($rolled);
	}
}

if(!function_exists('erasedataPreparedBindingStillMatches'))
{
	// The COMPLETE prepared tuple, re-checked under the re-taken locks after
	// the acknowledgement and before the first destructive call: the schedule
	// and user binding, the durable phase, the exact generation, the exact
	// force, the exact sorted hash set and its cardinality, and -- for every
	// member -- the exact staging path, the physical dev/ino it had when it was
	// written, AND the manifest it still holds. Anything else is a hard
	// no-erase.
	//
	// The content check is not belt and braces. dev/ino identifies a file only
	// as long as the inode is not reused, and an unlink immediately followed by
	// a create at the same name reuses it routinely -- measured here: the same
	// swap that changes the inode on the host's filesystem keeps it inside
	// php:7.4-cli and php:8.1-cli, so identity alone accepted an object that
	// was no longer the manifest this generation staged. Decoding what is
	// really there against this exact hash and this exact force is what closes
	// that, and it is the same decode the collector performs before it deletes
	// anything, so it can refuse nothing the collector would have accepted.
	function erasedataPreparedBindingStillMatches($state, $listPath, $user,
		$generation, array $accepted, $force)
	{
		if(!is_array($state) || !isset($state['user'], $state['phase'], $state['journal'])
			|| $state['user'] !== $user
			|| erasedataDrainScheduleKey($state['user']) !== erasedataDrainScheduleKey($user)
			|| $state['phase'] !== 'armed'
			|| !is_array($state['journal'])
			|| !isset($state['journal'][$generation]))
			return(false);
		$entry = $state['journal'][$generation];
		if(!is_array($entry) || !isset($entry['phase'], $entry['force'],
				$entry['hashes'], $entry['staging'])
			|| $entry['phase'] !== 'prepared'
			|| $entry['force'] !== $force
			|| !is_array($entry['hashes'])
			|| $entry['hashes'] !== $accepted
			|| count($entry['hashes']) !== count($accepted)
			|| !is_array($entry['staging'])
			|| count($entry['staging']) !== count($accepted))
			return(false);
		foreach($accepted as $hash)
		{
			if(!isset($entry['staging'][$hash]['path'], $entry['staging'][$hash]['dev'],
				$entry['staging'][$hash]['ino']))
				return(false);
			$path = $entry['staging'][$hash]['path'];
			if(!is_string($path) || strpos($path, $listPath.'/') !== 0)
				return(false);
			clearstatcache(true, $path);
			if(is_link($path) || !is_file($path))
				return(false);
			$stat = @lstat($path);
			if(!is_array($stat) || !isset($stat['dev'], $stat['ino'], $stat['mode'])
				|| (($stat['mode'] & 0170000) !== 0100000)
				|| (string)$stat['dev'] !== (string)$entry['staging'][$hash]['dev']
				|| (string)$stat['ino'] !== (string)$entry['staging'][$hash]['ino'])
				return(false);
			$bytes = @file_get_contents($path, false, null, 0,
				ErasedataManifestCodec::MAX_MANIFEST_BYTES + 1);
			if(!is_string($bytes)
				|| strlen($bytes) > ErasedataManifestCodec::MAX_MANIFEST_BYTES)
				return(false);
			$manifest = ErasedataManifestCodec::decodeBytes($bytes, $hash);
			if(!is_array($manifest) || !isset($manifest['operation'], $manifest['force'])
				|| $manifest['operation'] !== ErasedataManifestCodec::OPERATION_REMOVE_PAYLOAD
				|| $manifest['force'] !== $force)
				return(false);
		}
		return(true);
	}
}

if(!function_exists('erasedataWaitForDrainAcknowledgement'))
{
	// Wait for the durable acknowledgement that covers THIS generation. A
	// backward wall-clock step can extend this scheduler-aligned timeout.
	//
	// Only a really started guarded update.php child raises it, and it does so
	// before it tries the worker lock. The registration being accepted proves
	// nothing: rTorrent replaces the entry for a reused key and reports success
	// whether or not a tick ever runs.
	//
	// The predicate is `acknowledged >= generation`, not `== generation`, and
	// that is a correctness requirement rather than a relaxation. A tick raises
	// `acknowledged` to whatever generation the durable state carries at the
	// moment it runs, and a second producer over a DISJOINT hash set is not
	// serialised by anybody's hash lock, so it can push the state's generation
	// past a waiter's between that waiter's arm and the first tick. With exact
	// equality the older producer's generation then became permanently
	// unreachable -- every later child acknowledged the newer one -- and the
	// user's "Remove and delete data" answered false after the full timeout
	// while the other tab's succeeded. Measured, twice out of two, against a
	// real rTorrent 0.16.21.
	//
	// It proves exactly what the exact match proved. `acknowledged` never
	// decreases (erasedataValidateDrainState refuses a state whose
	// acknowledgement is ahead of its generation, and only
	// erasedataAcknowledgeDrainGeneration writes it, always to the durable
	// generation), and this producer's own generation was made durable BEFORE
	// this wait began. So an acknowledgement at or above it can only have been
	// written by a really started guarded child that ran after this arm. What
	// the acknowledgement licenses is unchanged: the caller still re-takes the
	// locks and revalidates its own complete `prepared` binding for its own
	// exact generation before anything destructive happens.
	function erasedataWaitForDrainAcknowledgement($listPath, $generation, $timeout, $poll,
		$clock = null)
	{
		if($clock === null)
			$clock = function() { return(array(microtime(true), hrtime(true) / 1000000000)); };
		$start = $clock();
		$duration = $timeout > 0 ? (float)$timeout : 0.0;
		$wallDeadline = $start[0] + $duration;
		$monotonicDeadline = $start[1] + $duration;
		$sleep = (int)($poll * 1000000);
		if($sleep < 1000)
			$sleep = 1000;
		while(true)
		{
			$state = erasedataReadDrainState($listPath);
			$reached = is_array($state) && isset($state['acknowledged'])
				? erasedataGenerationCompare($state['acknowledged'], $generation)
				: false;
			if($reached === 0 || $reached === 1)
				return(true);
			// rTorrent's scheduler uses wall time: a backward step can delay its
			// tick. Require that clock AND elapsed monotonic time to expire, so
			// a forward step cannot shorten the producer's acknowledgement window.
			$now = $clock();
			if($now[0] >= $wallDeadline && $now[1] >= $monotonicDeadline)
				return(false);
			usleep($sleep);
		}
	}
}

if(!function_exists('erasedataPruneResolvedJournalRecords'))
{
	// Drop only records that BIND NOTHING any more: no staging object of their
	// own, no *.pending marker of their own generation, and no manifest of that
	// generation still waiting for the collector. Nothing here evicts to make
	// room -- capacity refuses instead -- and nothing is dropped on elapsed
	// time or a vanished producer.
	//
	// The rule used to be applied to `published` records ONLY, and that is what
	// made retirement impossible after the very first removal: this is the sole
	// pruner in the plugin, invariant 10 counts every journal record as a
	// candidate, and so a `retained` record -- which the old filter skipped
	// outright -- pinned the drain schedule open for the lifetime of the queue,
	// re-probing erased hashes every five seconds. Measured against a real
	// rTorrent 0.16.21: 1.6 kB/s of faulting-RPC error log, about 137 MB a day,
	// from one stuck generation.
	//
	// Applying the same rule to every phase is not a relaxation, because the
	// rule is about BINDINGS rather than about phases. A producer mid-flight
	// writes its markers and its staging under the state lock before it
	// publishes the `prepared` record and keeps its hash locks until it is
	// finished, so an unfinished admission always binds at least a marker and
	// is never a candidate here. A record that binds nothing has no half of the
	// obligation left for any actor to act on: no marker to discharge, no
	// staging to publish, no manifest to collect. Keeping it changes nothing
	// except that the schedule can never go away.
	//
	// $abandoned, by reference, collects generation => phase for every record
	// dropped from a phase other than `published`, so a caller can report an
	// obligation that ended without being discharged rather than losing it in
	// silence.
	function erasedataPruneResolvedJournalRecords($listPath, array $state,
		&$abandoned = null)
	{
		$abandoned = array();
		if(!isset($state['journal']) || !is_array($state['journal']))
			return($state);
		$entries = @scandir($listPath);
		if(!is_array($entries))
			return($state);
		$published = erasedataPublishedGenerations($entries);
		$markers = array();
		foreach($entries as $entry)
		{
			$parts = explode('.', $entry);
			if(count($parts) === 3 && $parts[2] === 'pending'
				&& erasedataIsPendingHash($parts[0]))
				$markers[strtoupper($parts[0]).'.'.$parts[1]] = true;
		}
		foreach($state['journal'] as $generation => $entry)
		{
			$generation = (string)$generation;
			if(!is_array($entry) || !isset($entry['phase'], $entry['hashes'])
				|| !is_array($entry['hashes']))
				continue;
			$finished = true;
			foreach($entry['hashes'] as $hash)
			{
				$path = isset($entry['staging'][$hash]['path'])
					? $entry['staging'][$hash]['path'] : null;
				clearstatcache(true, is_string($path) ? $path : $listPath);
				if((is_string($path) && (is_file($path) || is_link($path)))
					|| isset($published[$hash][$generation])
					|| isset($markers[$hash.'.'.$generation]))
					$finished = false;
				// The published manifests and the markers above are read off the
				// NAMES scandir() returned, so they are as complete as the listing
				// is. The staging object is the one thing here decided by a stat of
				// a child, and a stat that was refused says nothing about whether
				// the object is still there. Dropping a record on that answer would
				// forget a binding that still exists.
				else if(is_string($path) && !erasedataPathLookupAnswers($path))
					$finished = false;
			}
			if(!$finished)
				continue;
			if($entry['phase'] !== 'published')
				$abandoned[$generation] = $entry['phase'];
			unset($state['journal'][$generation]);
		}
		return($state);
	}
}

if(!function_exists('erasedataEraseRequest'))
{
	// The ONE builder of the destructive request, and the other half of
	// invariant 2's arithmetic.
	//
	// erasedataClassifyEraseOutcomes() below partitions the reply list by
	// ERASEDATA_ERASE_COMMANDS_PER_HASH, so a builder that emits a different
	// number of commands per member mis-partitions `E` SILENTLY: a member is
	// read as individually accepted on somebody else's replies, and a final
	// manifest -- a licence to delete a payload -- is published for a download
	// rTorrent still holds. The constant's own comment demands that every
	// builder stay in step with it, and the way to guarantee that is to have
	// ONE builder. The admission producer, the drain pass and the retained
	// legacy producer all come through here, so they cannot drift apart, and this function checks its own output against the
	// constant so a change to erasedataEraseCommandsForHash() cannot drift away
	// from it either. A mismatch refuses to build the request at all: every
	// caller then reads it as an erase that did not run, retains its
	// obligations and erases nothing.
	//
	// Order is part of the contract: per member, d.set_custom5 clears the
	// erasedata mark, d.delete_tied drops the tied .torrent and d.erase removes
	// the download; members in the order they were handed over, which is the
	// order the classifier counts positions in.
	function erasedataEraseCommandsForHash($hash)
	{
		return(array(
			new rXMLRPCCommand(getCmd('d.set_custom5'), array($hash, '')),
			new rXMLRPCCommand(getCmd('d.delete_tied'), $hash),
			new rXMLRPCCommand(getCmd('d.erase'), $hash),
		));
	}

	function erasedataEraseRequest(array $hashes)
	{
		$request = new rXMLRPCRequest();
		$sent = 0;
		foreach($hashes as $hash)
		{
			$commands = erasedataEraseCommandsForHash($hash);
			if(count($commands) !== ERASEDATA_ERASE_COMMANDS_PER_HASH)
				return(false);
			foreach($commands as $command)
				$request->addCommand($command);
			$sent += count($commands);
		}
		if($sent !== count($hashes) * ERASEDATA_ERASE_COMMANDS_PER_HASH)
			return(false);
		return($request);
	}
}

if(!function_exists('erasedataClassifyEraseOutcomes'))
{
	// The per-hash outcome of ONE aggregate erase request, derived from the
	// individual replies it really came back with.
	//
	// This is invariant 2's `E`, and it is the reason the set is computed here
	// rather than from a single aggregate boolean. rTorrent answers a multicall
	// command by command, and the hazard is that a producer reading ONE boolean
	// off the request records every member of the batch as erased the moment
	// the first three commands worked, publishes a final manifest for a
	// download rTorrent still holds, and lets the collector delete its payload
	// out from under it.
	//
	// The position rule below is what makes that impossible, and it is written
	// to be indifferent to how the answer came up short. Two ways it can, and
	// they are not the same:
	//   - The transport loses the answer. On the SCGI path this fork uses that
	//     yields no values at all, not a prefix: rSCGITransport::readResponse()
	//     returns null for a truncated body or a socket closed before the
	//     headers (php/scgitransport.php), rXMLRPCRequest::send() turns null
	//     into false, and run() then returns false carrying nothing. Every
	//     member is `unknown`, which is the safe answer.
	//   - The daemon answers, but not for everyone. A per-item fault does NOT
	//     shorten the list -- verified against live rTorrent 0.9.8 and 0.16.21 --
	//     so a short list means the reply genuinely stops before some member's
	//     third command.
	// Neither case is trusted: only a reply list long enough to reach a
	// member's own third position makes that member `accepted`.
	//
	// So: `$hashes` is canonicalized and deduplicated in the order it was
	// handed over -- the same order the request was built in -- and member `i`
	// is `accepted` only when the reply list is long enough to contain that
	// member's own third reply. Everything else is `unknown`: never absence,
	// never success. A faulted reply makes the whole batch unknown, because a
	// fault carries no per-command position at all.
	//
	// Returns $canonicalHash => 'accepted'|'unknown'. Duplicates in the request
	// collapse to one outcome, so |outcome| is the canonical unique cardinality.
	function erasedataClassifyEraseOutcomes(array $hashes, $request)
	{
		$order = array();
		foreach($hashes as $hash)
		{
			$canonical = erasedataCanonicalHash($hash);
			if($canonical !== false)
				$order[$canonical] = true;
		}
		$replies = 0;
		$faulted = true;
		if(is_object($request))
		{
			$faulted = !isset($request->fault) || (bool)$request->fault;
			$replies = isset($request->val) && is_array($request->val)
				? count($request->val) : 0;
		}
		$outcome = array();
		$position = 0;
		foreach(array_keys($order) as $hash)
		{
			$needed = (++$position) * ERASEDATA_ERASE_COMMANDS_PER_HASH;
			$outcome[$hash] = (!$faulted && $replies >= $needed)
				? 'accepted' : 'unknown';
		}
		return($outcome);
	}
}

if(!function_exists('erasedataStagingIdentityStillMatches'))
{
	// The exact recorded physical identity of one staging record, and nothing
	// else: a regular file, not a symlink, on the same device with the same
	// inode the journal captured when it was written.
	//
	// This is the gate publication is allowed to use. A name is not an
	// identity: something else may hold that name by now, and promoting it
	// would publish an object this generation never staged.
	function erasedataStagingIdentityStillMatches($record)
	{
		if(!is_array($record) || !isset($record['path'], $record['dev'], $record['ino'])
			|| !is_string($record['path']) || $record['path'] === '')
			return(false);
		$path = $record['path'];
		clearstatcache(true, $path);
		if(is_link($path) || !is_file($path))
			return(false);
		$stat = @lstat($path);
		return(is_array($stat) && isset($stat['dev'], $stat['ino'], $stat['mode'])
			&& (($stat['mode'] & 0170000) === 0100000)
			&& (string)$stat['dev'] === (string)$record['dev']
			&& (string)$stat['ino'] === (string)$record['ino']);
	}
}

if(!function_exists('erasedataStagingObjectIsGone'))
{
	// Does this record bind NO staging object at all for $hash any more?
	//
	// Deliberately not the complement of erasedataStagingIdentityStillMatches():
	// that answers false both for "the file is gone" and for "something else
	// holds that name now", and only the first of those is a member nothing can
	// still act on. It also fails CLOSED, and closed here means the lookup has
	// to have ANSWERED: readable is not enough, because a queue directory that
	// is readable but not searchable lists its own contents while answering
	// "not there" for every one of them.
	function erasedataStagingObjectIsGone($listPath, array $staging, $hash)
	{
		if(!erasedataDirectoryLookupsAnswer($listPath) || !is_readable($listPath))
			return(false);
		if(isset($staging[$hash]))
		{
			$path = isset($staging[$hash]['path']) ? $staging[$hash]['path'] : null;
			if(is_string($path) && $path !== '')
			{
				clearstatcache(true, $path);
				if(file_exists($path) || is_link($path))
					return(false);
				// A staging path of its own -- validation keeps these inside the
				// queue, but the gate belongs to the path that was actually looked
				// for, not to the directory it was assumed to be in.
				if(!erasedataPathLookupAnswers($path))
					return(false);
			}
		}
		return(true);
	}
}

if(!function_exists('erasedataStagingManifestStillMatches'))
{
	// The identity above PLUS the manifest the file still holds, decoded
	// against this exact hash and this exact force.
	//
	// This is the gate a DESTRUCTIVE call has to pass. dev/ino identifies a
	// file only as long as the inode is not reused, and an unlink immediately
	// followed by a create at the same name reuses it routinely, so identity
	// alone can accept an object that is no longer the manifest this generation
	// staged. Erasing a download whose manifest is not the one that was bound
	// would leave its payload with nothing left to describe it.
	function erasedataStagingManifestStillMatches($record, $hash, $force)
	{
		if(!erasedataStagingIdentityStillMatches($record))
			return(false);
		$bytes = @file_get_contents($record['path'], false, null, 0,
			ErasedataManifestCodec::MAX_MANIFEST_BYTES + 1);
		if(!is_string($bytes)
			|| strlen($bytes) > ErasedataManifestCodec::MAX_MANIFEST_BYTES)
			return(false);
		$manifest = ErasedataManifestCodec::decodeBytes($bytes, $hash);
		return(is_array($manifest) && isset($manifest['operation'], $manifest['force'])
			&& $manifest['operation'] === ErasedataManifestCodec::OPERATION_REMOVE_PAYLOAD
			&& $manifest['force'] === $force);
	}
}

if(!function_exists('erasedataPendingMarkerStands'))
{
	// Is the obligation marker for this EXACT hash and generation still there?
	//
	// The marker IS the obligation: erasedataQueueRequest() writes it before
	// anything is staged, and every discharge in this file removes it. So its
	// absence means this member owes nothing -- not that anything was lost.
	// That distinction is the whole difference between a member that finished
	// and a member nothing can finish, and both of them can arrive at the same
	// place in the settle step with no manifest and no staging object left.
	//
	// Fails CLOSED: a name it cannot even build is "still owed", never
	// "already discharged", so an unnameable member is retained rather than
	// quietly counted as complete.
	function erasedataPendingMarkerStands($listPath, $hash, $generation)
	{
		$marker = erasedataPendingMarkerPath($listPath, $hash, $generation);
		if(!is_string($marker))
			return(true);
		clearstatcache(true, $marker);
		return(file_exists($marker) || is_link($marker));
	}
}

if(!function_exists('erasedataDischargePendingMarker'))
{
	// Remove the marker that records one obligation, and answer whether the
	// NAME is really gone afterwards.
	//
	// What is checked is the name, not what unlink() answered: invariant 10 can
	// only retire a schedule on a scan that proves there is no *.pending left,
	// so anything else that ends up sitting at that name -- a directory, say --
	// blocks retirement exactly as a leftover marker would and keeps the
	// obligation outstanding.
	function erasedataDischargePendingMarker($listPath, $hash, $generation)
	{
		$marker = erasedataPendingMarkerPath($listPath, $hash, $generation);
		if(!is_string($marker))
			return(false);
		clearstatcache(true, $marker);
		if(is_file($marker) || is_link($marker))
			@unlink($marker);
		clearstatcache(true, $marker);
		return(!file_exists($marker) && !is_link($marker));
	}
}

if(!function_exists('erasedataAcquireRemovalCapability'))
{
	// This is an admission capability, not the collector's force policy. The
	// collector checks that policy separately and acquires its own descriptor
	// for traversal. A producer must prove the required mechanism works BEFORE
	// dropping the download that could supply its file list again.
	function erasedataAcquireRemovalCapability(array $paths, ErasedataFilesystemOps $filesystem)
	{
		if(empty($paths['multi']))
			return(null);
		$base = isset($paths['base']) ? $paths['base'] : null;
		if(!ErasedataManifestCodec::isValidAbsolutePath($base) || $base === '/')
			return(false);
		$identity = $filesystem->targetIdentity($base);
		if(!is_array($identity) || empty($identity['is_dir']))
			return(false);
		$reference = $filesystem->openDirectoryReference($base, $identity);
		if(!is_array($reference))
			return(false);
		$capability = array('base' => $base, 'reference' => $reference);
		if(!erasedataRemovalCapabilityStillMatches($paths, $capability, $filesystem))
		{
			$filesystem->closeDirectoryReference($reference);
			return(false);
		}
		return($capability);
	}

	function erasedataRemovalCapabilityStillMatches(array $paths, $capability,
		ErasedataFilesystemOps $filesystem)
	{
		if($capability === null)
			return(empty($paths['multi']));
		if(!is_array($capability) || empty($paths['multi'])
			|| !isset($paths['base'], $capability['base'], $capability['reference'])
			|| $paths['base'] !== $capability['base'])
			return(false);
		$reference = $capability['reference'];
		if(!isset($reference['handle'], $reference['path']) || !is_resource($reference['handle']))
			return(false);
		$expected = erasedataIdentityDeviceAndInode($reference);
		return($expected !== false
			&& erasedataIdentityDeviceAndInode(@fstat($reference['handle'])) === $expected
			&& erasedataIdentityDeviceAndInode($filesystem->targetIdentity($reference['path'])) === $expected
			&& erasedataIdentityDeviceAndInode($filesystem->targetIdentity($paths['base'])) === $expected);
	}

	function erasedataReleaseRemovalCapabilities(array $capabilities, ErasedataFilesystemOps $filesystem)
	{
		foreach($capabilities as $capability)
			if(is_array($capability) && isset($capability['reference']))
				$filesystem->closeDirectoryReference($capability['reference']);
	}
}

if(!function_exists('erasedataRemovalAdmissionRun'))
{
	// The one admission transaction, with every dependency handed in.
	//
	// $dependencies is exactly listPath, user, filesystem, log, ackTimeout and
	// ackPoll. There is deliberately no 'forceEnabled' key -- see
	// erasedataAdmitRemoval() for why the force-2 policy is not evaluated here.
	// The public wrapper always builds the real dependencies; nothing in
	// production reads a test switch, an environment override or a global to
	// change what this does.
	function erasedataRemovalAdmissionRun(array $dependencies, $hashes, $force)
	{
		$listPath = isset($dependencies['listPath']) ? $dependencies['listPath'] : null;
		$user = isset($dependencies['user']) ? $dependencies['user'] : null;
		$log = isset($dependencies['log']) ? $dependencies['log'] : null;
		$filesystem = isset($dependencies['filesystem'])
			&& $dependencies['filesystem'] instanceof ErasedataFilesystemOps
			? $dependencies['filesystem'] : new ErasedataFilesystemOps();
		$ackTimeout = isset($dependencies['ackTimeout']) && is_numeric($dependencies['ackTimeout'])
			? (float)$dependencies['ackTimeout'] : ERASEDATA_DRAIN_ACK_TIMEOUT;
		$ackPoll = isset($dependencies['ackPoll']) && is_numeric($dependencies['ackPoll'])
			&& (float)$dependencies['ackPoll'] > 0
			? (float)$dependencies['ackPoll'] : ERASEDATA_DRAIN_ACK_POLL;
		if(!is_string($listPath) || $listPath === '' || strpos($listPath, "\0") !== false)
			return(false);
		if(erasedataDrainScheduleKey($user) === false)
		{
			erasedataDrainDiagnostic($log, 'invalid-user', null, 0, 'admission-refused');
			return(false);
		}

		// (1) The complete partition, before any side effect at all. Members of
		// F stop here: no marker, no journal record, no staging, no RPC and no
		// filesystem mutation of any kind is made on their behalf.
		$partition = erasedataAdmissionPartition($hashes, $force);
		if($partition === false)
		{
			erasedataDrainDiagnostic($log, 'invalid-request', null, 0, 'admission-refused');
			return(false);
		}
		$accepted = $partition['accepted'];
		$force = $partition['force'];
		$members = count($accepted);
		if(!$members)
		{
			erasedataDrainDiagnostic($log, 'invalid-hash', null, 0, 'admission-refused');
			return(false);
		}
		if(!is_dir($listPath))
			@FileUtil::makeDirectory($listPath);
		if(!is_dir($listPath))
		{
			erasedataDrainDiagnostic($log, 'queue-unavailable', null, $members,
				'admission-refused');
			return(false);
		}

		// (2) Sorted per-hash locks first, then the state lock.
		$locks = erasedataLockObligations($listPath, $accepted);
		if(!is_array($locks))
		{
			erasedataDrainDiagnostic($log, 'hash-lock', null, $members,
				'admission-refused');
			return(false);
		}
		$capabilities = array();
		try
		{
			// Force-2 preflight belongs to the A/F partition, before any marker or
			// staging. Keep each accepted descriptor through acknowledgement and
			// erase; finally closes it on every refusal and exception as well.
			if($force === 2)
			{
				$ready = array();
				foreach($accepted as $hash)
				{
					$paths = erasedataCollectPaths($hash);
					$capability = is_array($paths)
						? erasedataAcquireRemovalCapability($paths, $filesystem) : false;
					if($capability === false)
					{
						$partition['refused'][] = $hash;
						erasedataDrainDiagnostic($log, 'descriptor-unavailable', null, $members,
							'admission-refused-nothing-staged', $hash);
						continue;
					}
					$capabilities[$hash] = $capability;
					$ready[] = $hash;
				}
				$accepted = $ready;
				$members = count($accepted);
				if(!$members)
				{
					erasedataUnlockObligations($locks);
					return(false);
				}
			}
			$stateLock = erasedataAcquireDrainStateLock($listPath);
			if(!is_resource($stateLock))
			{
				erasedataReleaseAdmissionLocks($stateLock, $locks);
				erasedataDrainDiagnostic($log, 'state-lock', null, $members,
					'admission-refused');
				return(false);
			}

			// (3) Strict state read, one new generation, durable arming.
			//
			// This is a LOOP, and only because of the compare-and-swap at its end.
			// Each pass is one complete, unweakened invariant-4 arm: strict state
			// read, durable `arming`, successful mapped registration, durable
			// `armed` re-verified under the state lock. A pass that loses the swap
			// to another producer has written nothing an actor could act on -- no
			// marker, no staging, no journal record, no destructive RPC -- so it
			// re-enters from the strict read with a NEW generation rather than
			// refusing the user's removal. It never adopts the winner's arm on
			// trust: the next pass reads the durable phase itself, under the state
			// lock, exactly as a second removal onto a live queue does.
			for($armAttempt = 0; ; $armAttempt++)
			{
				$state = erasedataReadDrainState($listPath);
				if(!is_array($state))
				{
					erasedataReleaseAdmissionLocks($stateLock, $locks);
					erasedataDrainDiagnostic($log, 'state-write', null, $members,
						'admission-refused-unreadable-state');
					return(false);
				}
				$abandoned = array();
				$state = erasedataPruneResolvedJournalRecords($listPath, $state, $abandoned);
				foreach($abandoned as $dropped => $phase)
					erasedataDrainDiagnostic($log, 'journal-abandoned', (string)$dropped, 0,
						'record-bound-nothing-left-dropped-from-'.$phase);
				$generation = erasedataGenerationIncrement($state['generation']);
				if($generation === false || isset($state['journal'][$generation]))
				{
					erasedataReleaseAdmissionLocks($stateLock, $locks);
					erasedataDrainDiagnostic($log, 'generation-exhausted',
						$state['generation'], $members, 'admission-refused');
					return(false);
				}
				// Capacity refuses the new request before anything is erased, and never
				// evicts an active record to make room for it.
				if(count($state['journal']) >= ERASEDATA_DRAIN_MAX_JOURNAL)
				{
					erasedataReleaseAdmissionLocks($stateLock, $locks);
					erasedataDrainDiagnostic($log, 'journal-capacity', $generation,
						$members, 'admission-refused-no-eviction');
					return(false);
				}
				// A schedule that is already armed for this user is left exactly as it
				// is. Re-registering a live key restarts rTorrent's countdown and
				// reports success either way, so nothing would ever reveal it. The
				// aligned start bounds what one such registration costs (see
				// erasedataDrainScheduleCommand()), but not to nothing: a
				// registration made during the fire second itself resolves one slot
				// later, so a producer stream that keeps landing in that window
				// keeps pushing the tick. Skipping it also saves an RPC per
				// admission.
				//
				// The claim is durable and rTorrent's table is not, so a daemon
				// restart that lands while the phase is `armed` leaves this claim
				// standing over an emptied schedule table. Plugin init can re-arm it
				// on a later full web-UI load; a headless admission first times out
				// visibly, retains the torrents, rolls back its own staging, then
				// attempts an idempotent re-arm after releasing its locks.
				$live = $state['phase'] === 'armed' && $state['user'] === $user;
				$state['user'] = $user;
				$state['generation'] = $generation;
				$state['phase'] = $live ? 'armed' : 'arming';
				if(!$live)
					unset($state['legacy_marker']);
				if(!erasedataWriteDrainState($listPath, $state))
				{
					erasedataReleaseAdmissionLocks($stateLock, $locks);
					erasedataDrainDiagnostic($log, 'state-write', $generation, $members,
						'admission-refused-before-arm');
					return(false);
				}

				if(!$live)
				{
					// (4) The STATE lock comes off for the registration. The hash
					// locks do NOT.
					//
					// This used to release both, and that release was the only
					// moment in a whole admission when a producer held no hash lock
					// at all. Two things depend on closing it. First, "a `prepared`
					// record reached with its hash locks in hand proves its
					// producer is gone" becomes true of the ENTIRE admission rather
					// than of all of it but one RPC. Second -- the reason it is
					// closed here -- an `arming` phase is otherwise indistinguishable
					// from an abandoned one: with the hash locks held, a producer
					// inside this window always holds at least one <HASH>.lock, so
					// erasedataNoAdmissionHoldsThisQueue() can PROVE that a durable
					// `arming` belongs to nobody before correcting it.
					//
					// Invariant 7 is untouched. Nothing waits for a hash lock here
					// any more, and the state lock is re-taken while holding hash
					// locks, which is the canonical hash -> state direction. The
					// producer already holds these same locks across the erase RPC
					// and the whole acknowledgement wait, so this is strictly less
					// than it does later.
					erasedataReleaseDrainStateLock($stateLock);
					$stateLock = null;
					// Unreachable as configured: this builder refuses only an
					// unusable schedule key or an interval below 1, and the key
					// rule was already applied to this same $user at the top of
					// the function while ERASEDATA_DRAIN_INTERVAL is 5. The
					// branch stays so the refusal keeps its own classification
					// if the key rule, the callers or the interval ever change:
					// rXMLRPCRequest accepts a falsy command silently, so
					// without it the failure would be reported below as
					// `arm-refused` / "nothing-staged", which is a different
					// fault.
					$command = erasedataDrainScheduleCommand($user, ERASEDATA_DRAIN_INTERVAL);
					if($command === false)
					{
						erasedataReleaseAdmissionLocks($stateLock, $locks);
						erasedataDrainDiagnostic($log, 'arm-unavailable', $generation,
							$members, 'admission-refused');
						return(false);
					}
					$legacyArm = erasedataLegacyDrainVersion();
					if($legacyArm === null)
					{
						erasedataReleaseAdmissionLocks($stateLock, $locks);
						erasedataDrainDiagnostic($log, 'arm-version-unknown', $generation,
							$members, 'admission-refused-nothing-staged');
						return(false);
					}
					if($legacyArm && !erasedataLegacyDrainMarkerEnsure($user))
					{
						erasedataReleaseAdmissionLocks($stateLock, $locks);
						erasedataDrainDiagnostic($log, 'arm-legacy-marker', $generation,
							$members, 'admission-refused-nothing-staged');
						return(false);
					}
					$request = new rXMLRPCRequest($command);
					if(!$request->success() || $request->fault)
					{
						erasedataReleaseAdmissionLocks($stateLock, $locks);
						erasedataDrainDiagnostic($log, 'arm-refused', $generation,
							$members, 'admission-refused-nothing-staged');
						return(false);
					}
					// A restart between marker insertion and scheduling would leave
					// the new daemon armed without the marker. Refuse that uncertain
					// arm before claiming the durable witness.
					if($legacyArm && erasedataLegacyDrainMarkerStatus($user) !== true)
					{
						erasedataReleaseAdmissionLocks($stateLock, $locks);
						erasedataDrainDiagnostic($log, 'arm-legacy-marker', $generation,
							$members, 'admission-refused-nothing-staged');
						return(false);
					}

					// (5) Re-take the state lock -- the hash locks were never let go
					// -- and compare-and-swap the SAME generation arming -> armed.
					$stateLock = erasedataAcquireDrainStateLock($listPath);
					if(!is_resource($stateLock))
					{
						erasedataReleaseAdmissionLocks($stateLock, $locks);
						erasedataDrainDiagnostic($log, 'state-lock', $generation, $members,
							'admission-refused-after-arm');
						return(false);
					}
					$state = erasedataReadDrainState($listPath);
					if(!is_array($state) || $state['generation'] !== $generation
						|| $state['phase'] !== 'arming' || $state['user'] !== $user)
					{
						// Somebody else's admission owns this queue's state now. This
						// pass has staged nothing, written no marker, published no
						// journal record and erased nothing, so re-entering costs the
						// user nothing and loses nothing: the generation this pass
						// allocated is simply never used.
						//
						// Both locks are still held and both stay held: the next
						// pass re-reads the state under the very lock that just
						// showed it the collision, so nothing can slip in between
						// the observation and the retry.
						if($armAttempt + 1 >= ERASEDATA_ADMISSION_ARM_ATTEMPTS)
						{
							erasedataReleaseAdmissionLocks($stateLock, $locks);
							erasedataDrainDiagnostic($log, 'arm-lost', $generation,
								$members, 'admission-refused-nothing-staged');
							return(false);
						}
						continue;
					}
					$state['phase'] = 'armed';
					if($legacyArm)
						$state['legacy_marker'] = true;
					if(!erasedataWriteDrainState($listPath, $state))
					{
						erasedataReleaseAdmissionLocks($stateLock, $locks);
						erasedataDrainDiagnostic($log, 'state-write', $generation, $members,
							'admission-refused-nothing-staged');
						return(false);
					}
				}
				break;
			}

			// (6) The arm stands. Record the obligations, stage every member, and
			// publish one exact prepared journal record once -- and only once --
			// every file and identity is durable.
			foreach($accepted as $hash)
				if(!erasedataQueueRequest($listPath, $hash, $force, $generation))
				{
					erasedataReleaseAdmissionLocks($stateLock, $locks);
					erasedataDrainDiagnostic($log, 'marker-write', $generation, $members,
						'admission-refused-obligations-retained', $hash);
					return(false);
				}
			$staging = array();
			$rollback = array();
			$failure = false;
			foreach($accepted as $hash)
			{
				$paths = erasedataCollectPaths($hash);
				if($paths === false || ($force === 2
					&& !erasedataRemovalCapabilityStillMatches($paths, $capabilities[$hash], $filesystem)))
				{
					// Erasing now would drop the download and leave its data behind
					// with nothing left to identify it.
					$failure = array('paths', $hash);
					break;
				}
				$content = ErasedataManifestCodec::encode($hash, $paths, $force);
				if($content === false)
				{
					$failure = array('manifest-encode', $hash);
					break;
				}
				$staged = erasedataStageAdmittedManifest($listPath, $hash, $generation, $content);
				if($staged === false)
				{
					$failure = array('staging-write', $hash);
					break;
				}
				$staging[$hash] = array(
					'path' => $staged['path'],
					'dev' => (string)$staged['stat']['dev'],
					'ino' => (string)$staged['stat']['ino'],
				);
				$rollback[$hash] = $staging[$hash];
			}
			if($failure !== false)
			{
				erasedataRollbackAdmittedStaging($rollback, $filesystem);
				erasedataReleaseAdmissionLocks($stateLock, $locks);
				erasedataDrainDiagnostic($log, $failure[0], $generation, $members,
					'admission-refused-obligations-retained', $failure[1]);
				return(false);
			}
			$state['journal'][$generation] = array(
				'phase' => 'prepared',
				'force' => $force,
				'hashes' => $accepted,
				'staging' => $staging,
			);
			if(!erasedataWriteDrainState($listPath, $state))
			{
				erasedataRollbackAdmittedStaging($rollback, $filesystem);
				erasedataReleaseAdmissionLocks($stateLock, $locks);
				erasedataDrainDiagnostic($log, 'journal-write', $generation, $members,
					'admission-refused-obligations-retained');
				return(false);
			}

			// (7) Wait for the real child, holding the HASH locks and nothing else.
			//
			// The state lock comes off, because the tick raises the acknowledgement
			// under it and this producer must not stand in its way. The hash locks
			// STAY, and that is the liveness contract of the whole protocol: a
			// producer that can still reach its own erase never lets go of them, so
			// a hash lock the worker CAN take proves no producer is alive for the
			// generation behind it. The worker leans on exactly that when it
			// cancels an orphaned `prepared` staging, which is the only thing it is
			// allowed to do with one -- it may never erase it.
			//
			// Nothing but a file poll is waited for while these locks are held: the
			// acknowledgement needs the state lock alone, so the tick that writes
			// it can never end up behind them (invariant 7).
			erasedataReleaseDrainStateLock($stateLock);
			$stateLock = null;
			$acknowledged = erasedataWaitForDrainAcknowledgement($listPath, $generation,
				$ackTimeout, $ackPoll);

			// (8) Re-take the state lock -- the hash locks were never released, so
			// the order is still hash-then-state -- and revalidate everything.
			$stateLock = erasedataAcquireDrainStateLock($listPath);
			if(!is_resource($stateLock))
			{
				erasedataReleaseAdmissionLocks($stateLock, $locks);
				erasedataDrainDiagnostic($log, 'state-lock', $generation, $members,
					'obligations-retained-for-the-worker');
				return(false);
			}
			$state = erasedataReadDrainState($listPath);
			$capabilitiesMatch = true;
			foreach($capabilities as $hash => $capability)
				if(is_array($capability)
					&& !erasedataRemovalCapabilityStillMatches(array('multi' => 1,
						'base' => $capability['base']), $capability, $filesystem))
					$capabilitiesMatch = false;
			if(!$acknowledged || !erasedataPreparedBindingStillMatches($state, $listPath,
				$user, $generation, $accepted, $force) || !$capabilitiesMatch)
			{
				erasedataRetainAdmittedGeneration($listPath, $state, $generation,
					$staging, $filesystem);
				erasedataReleaseAdmissionLocks($stateLock, $locks);
				erasedataDrainDiagnostic($log,
					$acknowledged ? 'binding-mismatch' : 'drain-no-ack',
					$generation, $members,
					'torrents-retained-own-staging-rolled-back');
				// A daemon restart can lose the volatile schedule while the durable
				// phase remains armed. Once every admission lock is released, let the
				// existing conservative recovery scan re-arm owed work. No-ack alone
				// is not authority to rewrite the phase or erase this torrent.
				if(!$acknowledged && $live)
					erasedataRearmDrainScheduleRun(array('listPath' => $listPath,
						'user' => $user, 'log' => $log, 'context' => 'headless'));
				return(false);
			}
			$state['journal'][$generation]['phase'] = 'erase-started';
			if(!erasedataWriteDrainState($listPath, $state))
			{
				erasedataReleaseAdmissionLocks($stateLock, $locks);
				erasedataDrainDiagnostic($log, 'erase-started-write', $generation,
					$members, 'nothing-erased-obligations-retained');
				return(false);
			}

			// Only now, with erase-started durable, does anything destructive run.
			// The request comes from the one shared builder, so its length is the
			// length erasedataClassifyEraseOutcomes() partitions by, by construction.
			$request = erasedataEraseRequest($accepted);
			// Invariant 2's `E`, derived from the INDIVIDUAL replies the batch
			// really came back with and never from the aggregate. A request whose
			// own success() is false carries no per-command position at all, so it
			// contributes nothing: every member of it is UNKNOWN.
			$classified = ($request !== false && $request->success())
				? erasedataClassifyEraseOutcomes($accepted, $request) : array();
			$published = array();
			$retained = array();
			// Invariant 15: an I/O failure in this producer's OWN bookkeeping --
			// a staging object that is no longer the file the journal captured, a
			// publication that could not land, a marker it could not discharge --
			// is TERMINAL for the attempt and is reported as a failure. Remote
			// uncertainty is not: a member rTorrent gave no individual answer for
			// is an ordinary, completely described member of T0, and the caller
			// gets the exact partition rather than a bare false it can learn
			// nothing from.
			$terminal = false;
			foreach($accepted as $hash)
			{
				// A hash is only known erased when rTorrent's own reply for THAT
				// hash came back, or the download is individually known to be gone.
				// Transport, fault or parse uncertainty is UNKNOWN, and UNKNOWN
				// retains the obligation: it publishes nothing and unlinks nothing.
				$presence = (isset($classified[$hash]) && $classified[$hash] === 'accepted')
					? ERASEDATA_TORRENT_ABSENT : erasedataTorrentPresence($hash);
				// Invariant 12 asks for a canonical hash and a reason per physical
				// job, and T1 and T0 are not the same job: `erase-unresolved` is a
				// member rTorrent never individually answered for (T0), while
				// `publish-refused` and `marker-retained` are members this producer
				// really did erase and could not finish (T1). One aggregate count
				// cannot tell an operator which of the two the queue now carries.
				if($presence !== ERASEDATA_TORRENT_ABSENT)
				{
					$retained[] = $hash;
					erasedataDrainDiagnostic($log, 'erase-unresolved', $generation,
						$members, 'obligation-retained', $hash);
					continue;
				}
				$record = $state['journal'][$generation]['staging'][$hash];
				// false for the same reason as the tick above: the diagnostic
				// immediately below is this refusal, classified.
				if(!erasedataStagingIdentityStillMatches($record)
					|| !ErasedataManifestCodec::publishStaging($record['path'], $hash,
						$filesystem, false))
				{
					$retained[] = $hash;
					$terminal = true;
					erasedataDrainDiagnostic($log, 'publish-refused', $generation,
						$members, 'obligation-retained', $hash);
					continue;
				}
				// The marker removal is part of discharging the obligation, not an
				// afterthought: invariant 10 can only retire a schedule on a scan
				// that proves there is no *.pending left, so a marker this producer
				// could not remove is an obligation it still owes however completely
				// the manifest was published.
				if(!erasedataDischargePendingMarker($listPath, $hash, $generation))
				{
					$retained[] = $hash;
					$terminal = true;
					erasedataDrainDiagnostic($log, 'marker-retained', $generation,
						$members, 'obligation-retained', $hash);
					continue;
				}
				$published[] = $hash;
			}
			$state['journal'][$generation]['phase'] = count($retained) ? 'retained' : 'published';
			$recorded = erasedataWriteDrainState($listPath, $state);
			erasedataReleaseAdmissionLocks($stateLock, $locks);
			if(!$recorded)
			{
				erasedataDrainDiagnostic($log, 'journal-write', $generation, $members,
					'published-'.count($published).'-retained-'.count($retained));
				return(false);
			}
			if(count($retained))
			{
				erasedataDrainDiagnostic($log, 'erase-unresolved', $generation, $members,
					'published-'.count($published).'-retained-'.count($retained));
				if($terminal)
					return(false);
			}
			return(array(
				'generation' => $generation,
				'force' => $force,
				'accepted' => $accepted,
				'refused' => $partition['refused'],
				'published' => $published,
				'retained' => $retained,
			));
		}
		finally
		{
			erasedataReleaseRemovalCapabilities($capabilities, $filesystem);
		}
	}
}

if(!function_exists('erasedataRetainAdmittedGeneration'))
{
	// The obligation stays; only this producer's own prepared staging goes.
	//
	// Rolling the staging back is identity bound, so a later generation's
	// staging under a reused name, and anything this admission never wrote, are
	// left exactly as they are. The journal record is kept and marked retained
	// rather than deleted: the *.pending markers and this record together are
	// the self-describing obligation a later tick retries, with no cap.
	function erasedataRetainAdmittedGeneration($listPath, $state, $generation,
		array $staging, ?ErasedataFilesystemOps $filesystem = null)
	{
		erasedataRollbackAdmittedStaging($staging, $filesystem);
		// `erase-started` may not be downgraded because recovery has to stay
		// conservative about it, and `published` may not be downgraded because
		// the generation really is resolved: marking it retained would keep a
		// finished record alive for ever and stop the journal ever proving
		// empty for retirement.
		if(!is_array($state) || !isset($state['journal'][$generation]['phase'])
			|| $state['journal'][$generation]['phase'] === 'erase-started'
			|| $state['journal'][$generation]['phase'] === 'published')
			return(false);
		$state['journal'][$generation]['phase'] = 'retained';
		return(erasedataWriteDrainState($listPath, $state));
	}
}

// ---------------------------------------------------------------------------
// The guarded drain worker.
//
// One physical tick of the per-user drain schedule. rTorrent starts exactly
//
//     <php> plugins/erasedata/update.php <user> drain
//
// and update.php is the only production caller of erasedataDrainWorkerMain():
// nothing else in the shipped plugin reaches the worker, and update.php loads
// the queue implementation unconditionally at file scope, so the entry point
// cannot be present and unreachable at the same time.
//
// The order of one tick is invariant 7 and it is not negotiable:
//
//   1. a durable acknowledgement of the EXACT observed generation, under the
//      state lock and nothing else. It happens FIRST, before admission, because
//      a producer is blocked waiting for it and because the acknowledgement is
//      the only proof the schedule really fires;
//   2. NONBLOCKING worker admission. A tick that cannot get in has already
//      acknowledged, says so, and consumes nothing;
//   3. the BLOCKING scheduler lock, shared with the periodic collector pass
//      (which keeps LOCK_NB and gives up at once);
//   4. per generation: the BLOCKING per-hash locks, in the canonical sorted
//      order erasedataLockObligations() decides -- and the state lock is NEVER
//      held while waiting for one of them.
//
// What the worker owns is the RETRY. Every T0, T1, partial publish, RPC
// uncertainty, unreadable staging and unknown journal state is retried on a
// later tick, with no cap of any kind. Nothing here deletes an obligation
// because time passed or because the process that recorded it is gone.
// ---------------------------------------------------------------------------

if(!function_exists('erasedataAcquireDrainPassLock'))
{
	// One named pass lock in the queue directory. The mode is the caller's:
	// worker admission is nonblocking, the scheduler lock is blocking.
	function erasedataAcquireDrainPassLock($listPath, $name, $nonBlocking)
	{
		if(!is_string($listPath) || $listPath === ''
			|| strpos($listPath, "\0") !== false || !is_string($name) || $name === '')
			return(false);
		$path = $listPath.'/'.$name;
		$handle = @fopen($path, 'c');
		if($handle === false)
			return(false);
		// Shared between the web user and whoever rTorrent runs the child as.
		@chmod($path, erasedataSharedFileMode());
		if(!@flock($handle, LOCK_EX | ($nonBlocking ? LOCK_NB : 0)))
		{
			@fclose($handle);
			return(false);
		}
		return($handle);
	}
}

if(!function_exists('erasedataReleaseDrainPassLock'))
{
	function erasedataReleaseDrainPassLock($handle)
	{
		if(!is_resource($handle))
			return(false);
		$released = @flock($handle, LOCK_UN);
		return(@fclose($handle) === true && $released);
	}
}

if(!function_exists('erasedataAcknowledgeDrainGeneration'))
{
	// Invariant 5, the whole of it: only a really started guarded child raises
	// `acknowledged`, it raises it to the EXACT generation the durable state
	// currently carries, and it does so before it tries the worker lock.
	//
	// It is short on purpose. The only lock held is the state lock, no RPC is
	// made under it, and it is released before anything else is attempted, so a
	// producer waiting for the acknowledgement is never behind a hash lock.
	//
	// Returns array('generation' => ..., 'state' => ...), or false. A queue
	// whose durable state belongs to a different user is refused outright: this
	// child cannot acknowledge on somebody else's behalf, and an unreadable
	// state is not a state anything destructive may be decided from.
	function erasedataAcknowledgeDrainGeneration($listPath, $user, $log)
	{
		$stateLock = erasedataAcquireDrainStateLock($listPath);
		if(!is_resource($stateLock))
		{
			erasedataDrainDiagnostic($log, 'state-lock', null, 0,
				'nothing-acknowledged-nothing-consumed');
			return(false);
		}
		$state = erasedataReadDrainState($listPath);
		if(!is_array($state))
		{
			erasedataReleaseDrainStateLock($stateLock);
			erasedataDrainDiagnostic($log, 'state-unreadable', null, 0,
				'nothing-acknowledged-nothing-consumed');
			return(false);
		}
		// This child may acknowledge on its own queue, or on a queue no
		// admission has ever claimed. Anything else is somebody else's queue,
		// and '' is a user like any other rather than a wildcard.
		if(!erasedataDrainStateBelongsTo($state, $user))
		{
			erasedataReleaseDrainStateLock($stateLock);
			erasedataDrainDiagnostic($log, 'worker-user-mismatch',
				$state['generation'], 0, 'nothing-acknowledged-nothing-consumed');
			return(false);
		}
		$state['user'] = $user;
		$generation = $state['generation'];
		// The exact generation that is durable right now, never a computed or
		// remembered one: an acknowledgement of anything else would release a
		// producer that is waiting for a different admission.
		$state['acknowledged'] = $generation;
		if(!erasedataWriteDrainState($listPath, $state))
		{
			erasedataReleaseDrainStateLock($stateLock);
			erasedataDrainDiagnostic($log, 'ack-write', $generation, 0,
				'nothing-acknowledged-nothing-consumed');
			return(false);
		}
		erasedataReleaseDrainStateLock($stateLock);
		return(array('generation' => $generation, 'state' => $state));
	}
}

if(!function_exists('erasedataDrainWorkerJobs'))
{
	// Everything this tick still owes, grouped by generation.
	//
	// The obligation has TWO halves and a tick that read only one of them would
	// lose work: the *.pending markers describe requests whose manifest was
	// never published, and the journal describes generations whose staging was
	// written -- and possibly already erased against -- but never resolved. A
	// crashed producer can leave either half alone.
	//
	// A record whose own phase is `published` is finished and is skipped whole.
	// Nothing is dropped for any other reason: not a final manifest sitting
	// beside an unfinished record, not elapsed time, not a producer PID that no
	// longer exists, not a count of previous attempts.
	function erasedataDrainWorkerJobs($listPath, array $state, array $obligations)
	{
		$jobs = array();
		$seen = array();
		foreach($obligations as $generation => $group)
		{
			$generation = (string)$generation;
			$jobs[$generation] = array(
				'hashes' => $group['hashes'],
				'force' => $group['force'],
			);
			$seen[$generation] = array();
			foreach($group['hashes'] as $hash)
				$seen[$generation][$hash] = true;
		}
		$carried = array('prepared', 'erase-started', 'retained');
		if(isset($state['journal']) && is_array($state['journal']))
			foreach($state['journal'] as $generation => $entry)
			{
				$generation = (string)$generation;
				if(!is_array($entry) || !isset($entry['phase'], $entry['hashes'], $entry['force'])
					|| !is_array($entry['hashes'])
					|| !in_array($entry['phase'], $carried, true))
					continue;
				if(!isset($jobs[$generation]))
				{
					$jobs[$generation] = array('hashes' => array(),
						'force' => $entry['force']);
					$seen[$generation] = array();
				}
				foreach($entry['hashes'] as $hash)
				{
					// A final manifest is deliberately NOT a reason to drop a
					// member here. The record's own phase is: a `published`
					// record never reaches this loop at all. Anything else --
					// prepared, erase-started, retained -- is still owed, and a
					// `.list` standing beside it means the marker its publisher
					// could not remove is still outstanding. Filtering on the
					// manifest instead of the phase is what made a promoted
					// staging file able to hide a whole obligation from both
					// obligation readers at once.
					if(!erasedataIsCanonicalPendingHash($hash)
						|| isset($seen[$generation][$hash]))
						continue;
					$seen[$generation][$hash] = true;
					$jobs[$generation]['hashes'][] = $hash;
				}
			}
		foreach(array_keys($jobs) as $generation)
		{
			if(!count($jobs[$generation]['hashes']))
			{
				unset($jobs[$generation]);
				continue;
			}
			sort($jobs[$generation]['hashes'], SORT_STRING);
		}
		ksort($jobs, SORT_STRING);
		return($jobs);
	}
}

if(!function_exists('erasedataDrainJournalRecord'))
{
	// One journal record built from a staging map. The bound hash set is
	// exactly the set the map has an object for, sorted and unique, which is
	// what erasedataValidateDrainJournalEntry() requires; a member with no
	// staging of its own is not in the record and its *.pending marker remains
	// its self-describing obligation.
	function erasedataDrainJournalRecord(array $staging, $force, $phase)
	{
		if($force !== 1 && $force !== 2)
			return(false);
		ksort($staging, SORT_STRING);
		$hashes = array_keys($staging);
		if(!count($hashes))
			return(false);
		sort($hashes, SORT_STRING);
		return(array(
			'phase' => $phase,
			'force' => $force,
			'hashes' => $hashes,
			'staging' => $staging,
		));
	}
}

if(!function_exists('erasedataDrainReportMemory'))
{
	// The cross-tick diagnostic memory, as $key => $digest.
	//
	// Invariant 12 asks for BOUNDED diagnostics, and bounded per tick is not
	// bounded: the drain schedule fires every ERASEDATA_DRAIN_INTERVAL seconds
	// and, by design, never gives up, so one obligation nothing can discharge
	// used to write the same classified lines into the ruTorrent log for the
	// life of the installation. The durable state carries a small list of
	// digests instead -- one per physical job that reported -- and a job whose
	// COMPLETE classified outcome is identical to the remembered one has
	// already been reported and says nothing new. Any change at all -- another
	// reason, another member, another consequence, another count -- is another
	// digest and is reported again on the same tick it appears.
	function erasedataDrainReportMemory($state)
	{
		$memory = array();
		if(!is_array($state) || !isset($state['diagnostics'])
			|| !is_array($state['diagnostics']))
			return($memory);
		foreach($state['diagnostics'] as $line)
		{
			if(!is_string($line))
				continue;
			$split = strpos($line, ' ');
			if($split === false || $split === 0 || $split + 1 >= strlen($line))
				continue;
			$memory[substr($line, 0, $split)] = substr($line, $split + 1);
		}
		return($memory);
	}
}

if(!function_exists('erasedataDrainReportLines'))
{
	// The memory as the durable schema holds it: at most
	// ERASEDATA_DRAIN_MAX_DIAGNOSTICS "<key> <digest>" lines, in a
	// deterministic order.
	//
	// What does not fit is simply not remembered, which means it is REPORTED
	// again on the next tick. The cap bounds the MEMORY, never the reporting,
	// so no cap here can ever hide a condition from an operator.
	function erasedataDrainReportLines(array $memory)
	{
		ksort($memory, SORT_STRING);
		// Named groups first, generations after. A generation key is 16 lowercase
		// hex and sorts BEFORE every letter, so a plain ksort put 'tick',
		// 'retire', 'manifest' and 'cleanup' last and made them the first things
		// a truncation dropped -- the groups that describe the tick as a whole
		// evicted by the ones that describe single jobs. Whatever else is lost,
		// these are kept.
		$ordered = array();
		foreach($memory as $key => $digest)
			if(preg_match('/^[0-9a-f]{16}$/D', (string)$key) !== 1)
				$ordered[$key] = $digest;
		foreach($memory as $key => $digest)
			if(!isset($ordered[$key]))
				$ordered[$key] = $digest;
		$memory = $ordered;
		$lines = array();
		foreach($memory as $key => $digest)
		{
			if(count($lines) >= ERASEDATA_DRAIN_MAX_DIAGNOSTICS)
				break;
			$key = (string)$key;
			if($key === '' || strpos($key, ' ') !== false
				|| !is_string($digest) || $digest === '')
				continue;
			$line = $key.' '.$digest;
			if(strlen($line) <= ERASEDATA_DRAIN_MAX_DIAGNOSTIC_BYTES)
				$lines[] = $line;
		}
		return($lines);
	}
}

if(!function_exists('erasedataDrainReportGroup'))
{
	// Report one physical job's classified notes -- or say nothing at all,
	// because they are exactly what was already reported for that job.
	//
	// $notes is a list of array(reason, generation, members, consequence, hash)
	// with an optional sixth element, the name of the file the note is about.
	// The digest is order independent, so the same outcome classified in a
	// different order still counts as unchanged; the lines themselves are
	// written in the order the pass produced them. Returns how many lines were
	// really written.
	function erasedataDrainReportGroup($log, $key, array $notes, array $memory,
		array &$fresh)
	{
		if(!count($notes))
			return(0);
		$parts = array();
		// The named object is part of the classification, not decoration: two
		// ticks that strand two different files are two different states, and
		// suppressing the second would print a name that is no longer there.
		// Adding it changes every digest once, so the first tick after an
		// upgrade re-reports each group exactly once and then settles again.
		// The generation is part of the identity for the same reason the named
		// object is: a condition that moves to a different physical job is a
		// different condition, and suppressing the second would leave a standing
		// line naming a generation that is gone.
		//
		// This is NOT free for the callers that were here before. Only the
		// per-job group is keyed by its generation; 'tick', 'retire' and the
		// collector's 'manifest' are keyed by a literal, and their notes do carry
		// a generation, so those groups now re-report when the queue moves on.
		// That is the intended reading -- 'worker-busy' at generation 2 is not the
		// same fact as 'worker-busy' at generation 1 -- but it is a behaviour
		// change, not a no-op, and it costs one extra line per group per
		// generation. 'cleanup' is unaffected: its notes carry 'none' by
		// construction, because a cleanup job's name has no generation in it.
		foreach($notes as $note)
			$parts[] = $note[0].'|'
				.(erasedataGenerationIsValid($note[1]) ? $note[1] : '')
				.'|'.(int)$note[2].'|'
				.(erasedataIsCanonicalPendingHash($note[4]) ? $note[4] : '')
				.'|'.(isset($note[5]) && erasedataIsStagingObjectName($note[5])
					? $note[5] : '')
				.'|'.$note[3];
		sort($parts, SORT_STRING);
		$digest = sha1(implode("\n", $parts));
		$fresh[$key] = $digest;
		if(isset($memory[$key]) && $memory[$key] === $digest)
			return(0);
		foreach($notes as $note)
			erasedataDrainDiagnostic($log, $note[0], $note[1], $note[2], $note[3],
				$note[4], isset($note[5]) ? $note[5] : null);
		return(count($notes));
	}
}

if(!function_exists('erasedataPersistDrainReport'))
{
	// Publish the tick's diagnostic memory under the state lock, and nothing
	// else: the journal is whatever the passes already made durable, and this
	// re-reads it rather than carrying a copy across the passes.
	//
	// A tick that was refused worker admission asks NONBLOCKING, because that
	// refusal is the one this whole path exists to keep nonblocking: it must
	// never end up waiting behind the very owner it just refused to displace.
	// Failing to record the memory only means the same line is reported again on
	// the next tick, which is the safe direction in every case.
	function erasedataPersistDrainReport($listPath, $user, array $memory,
		$nonBlocking = false)
	{
		$stateLock = erasedataAcquireDrainStateLock($listPath, $nonBlocking);
		if(!is_resource($stateLock))
			return(false);
		$state = erasedataReadDrainState($listPath);
		$lines = erasedataDrainReportLines($memory);
		if(!is_array($state) || $state['user'] !== $user
			|| $state['diagnostics'] === $lines)
		{
			erasedataReleaseDrainStateLock($stateLock);
			return(false);
		}
		$state['diagnostics'] = $lines;
		$written = erasedataWriteDrainState($listPath, $state);
		erasedataReleaseDrainStateLock($stateLock);
		return($written);
	}
}

if(!function_exists('erasedataCancelPreparedStaging'))
{
	// Undo ONE prepared staging binding, and answer whether the exact object
	// the journal captured is gone afterwards.
	//
	// Identity bound in both directions. An object whose dev/ino no longer
	// match was never this generation's: it is left exactly where it is, and
	// that is also the answer "this binding holds nothing any more", so it
	// counts as cancelled. Nothing here acts on a name alone, so a later
	// generation's staging under a reused name is never destroyed.
	function erasedataCancelPreparedStaging($record,
		?ErasedataFilesystemOps $filesystem = null)
	{
		if(!erasedataStagingIdentityStillMatches($record))
			return(true);
		return(erasedataUnlinkAdmittedStaging($record, $filesystem));
	}
}

if(!function_exists('erasedataUnboundStagingCandidates'))
{
	// A filename is evidence for retention only when the journal does not bind
	// that exact path. The preview before the RPC and the locked decision after
	// it must use the same classifier; a stale preview can only defer a probe
	// for one tick, never authorize deletion or discharge.
	function erasedataUnboundStagingCandidates(array $entries, $generation,
		array $hashes, array $staging, $listPath)
	{
		$wanted = array_fill_keys($hashes, true);
		$unbound = array();
		foreach($entries as $name)
			if(preg_match('/^([0-9A-Fa-f]{40})\\.([0-9a-f]{16})\\..+\\.tmp$/D', $name, $match) === 1
				&& $match[2] === $generation)
			{
				$hash = strtoupper($match[1]);
				if(!isset($wanted[$hash]) || isset($unbound[$hash]))
					continue;
				if(!isset($staging[$hash]['path'])
					|| $staging[$hash]['path'] !== $listPath.'/'.$name)
					$unbound[$hash] = $name;
			}
		return($unbound);
	}
}

if(!function_exists('erasedataDrainGenerationPass'))
{
	// One generation, under its own blocking sorted hash locks.
	//
	// Returns array('published' => int, 'retained' => int, 'cancelled' => int,
	// 'unrecoverable' => int). `cancelled` and `unrecoverable` are separate
	// fields on purpose: a cancelled member kept its obligation and had nothing
	// erased under it, an unrecoverable one is an obligation that ended
	// undischargeable, and one field for both made a loss read as a rollback.
	// Every classified line is APPENDED to $notes rather than written out here,
	// so the caller can compare this physical job's complete outcome with the
	// one it last reported and stay silent when nothing has changed. Invariant
	// 12 wants bounded diagnostics, and a tick that never gives up would
	// otherwise repeat the same unresolvable line for ever.
	//
	// What this may NOT do: complete a `prepared` obligation. A `prepared`
	// journal record belongs to the producer that wrote it, and the producer is
	// the only actor allowed to move it to `erase-started` and erase under it.
	// A producer that can still reach its own erase holds the hash locks
	// continuously from admission to the end, so reaching a `prepared` record
	// HERE -- with those locks taken -- proves its producer is gone. The pass
	// then CANCELS the generation, exactly and identity bound, and erases
	// nothing under it. `erase-started` is the opposite case and stays
	// conservative: presence and publication recovery only, rebinding nothing.
	function erasedataDrainGenerationPass($listPath, $user, $generation, array $job,
		ErasedataFilesystemOps $filesystem, array &$notes, array $stateSnapshot = array())
	{
		$hashes = $job['hashes'];
		$members = count($hashes);
		$result = array('published' => 0, 'retained' => $members,
			'cancelled' => 0, 'unrecoverable' => 0);
		// A restart can lower soft nofile below an already durable batch.
		// The hard limit still bounds recovery; lock acquisition remains atomic.
		erasedataTryRaiseRecoveryHashLockLimit($members);
		// Invariant 7: blocking, in the canonical sorted order this one
		// function decides for every caller. Nothing is held while it waits.
		$locks = erasedataLockObligations($listPath, $hashes);
		if(!is_array($locks))
		{
			$notes[] = array('hash-lock', $generation, $members,
				'obligations-retained', null);
			return($result);
		}

		// A matched unbound staging object already decides retention for its
		// hash. Re-probing it every five seconds cannot change that decision and
		// scales linearly with stranded members. The acknowledged state is only a
		// preview: if the object or journal changes before the locked read below,
		// an omitted answer remains UNKNOWN for this tick and is probed next time.
		$unboundBeforeProbe = array();
		if(isset($stateSnapshot['journal']) && is_array($stateSnapshot['journal']))
		{
			$preview = isset($stateSnapshot['journal'][$generation]['staging'])
				&& is_array($stateSnapshot['journal'][$generation]['staging'])
				? $stateSnapshot['journal'][$generation]['staging'] : array();
			$previewEntries = $filesystem->scanDirectory($listPath);
			if(is_array($previewEntries))
				$unboundBeforeProbe = erasedataUnboundStagingCandidates($previewEntries,
					$generation, $hashes, $preview, $listPath);
		}
		// (1) The live-hash probe, one per member whose physical staging does
		// not already force retention, with NO state lock held. Transport, fault
		// or parse uncertainty is UNKNOWN: it publishes nothing, unlinks nothing
		// and resolves nothing.
		$presence = array();
		foreach($hashes as $hash)
		{
			if(isset($unboundBeforeProbe[$hash]))
			{
				$presence[$hash] = ERASEDATA_TORRENT_UNKNOWN;
				continue;
			}
			$presence[$hash] = erasedataTorrentPresence($hash);
			// Classified here, against its own canonical hash and generation,
			// and not at the end of the pass: every later refusal is reached
			// through a decision this answer already made, so a member whose
			// probe was uncertain must say so whatever the pass does next.
			if($presence[$hash] === ERASEDATA_TORRENT_UNKNOWN)
				$notes[] = array('probe-unknown', $generation, $members,
					'obligation-retained-nothing-erased', $hash);
		}

		$stateLock = erasedataAcquireDrainStateLock($listPath);
		if(!is_resource($stateLock))
		{
			erasedataReleaseAdmissionLocks($stateLock, $locks);
			$notes[] = array('state-lock', $generation, $members,
				'obligations-retained', null);
			return($result);
		}
		$state = erasedataReadDrainState($listPath);
		if(!is_array($state) || $state['user'] !== $user)
		{
			erasedataReleaseAdmissionLocks($stateLock, $locks);
			$notes[] = array(is_array($state) ? 'worker-user-mismatch' : 'state-unreadable',
				$generation, $members, 'obligations-retained', null);
			return($result);
		}
		// A generation ahead of the one the durable state carries was never
		// armed by this queue: a stale schedule, a lost state file or a marker
		// nobody here admitted. It binds nothing, so nothing destructive may be
		// decided from it. It is retained exactly as found and reported.
		$armed = erasedataGenerationCompare($generation, $state['generation']);
		if($armed !== 0 && $armed !== -1)
		{
			erasedataReleaseAdmissionLocks($stateLock, $locks);
			$notes[] = array('generation-unarmed', $generation, $members,
				'obligations-retained-nothing-erased', null);
			return($result);
		}
		$entry = isset($state['journal'][$generation])
			&& is_array($state['journal'][$generation])
			&& isset($state['journal'][$generation]['phase'],
				$state['journal'][$generation]['force'],
				$state['journal'][$generation]['staging'])
			&& is_array($state['journal'][$generation]['staging'])
			? $state['journal'][$generation] : null;
		// One admission binds one force to every member. A journal record and a
		// marker set that disagree, or markers that disagree among themselves,
		// are corruption: this worker refuses to pick one of the two rather
		// than deleting somebody's data under a force nobody asked for. The
		// group force is `false` in exactly the second case, so it is a
		// refusal in its own right and never something a journal record is
		// allowed to resolve -- least of all upward, to whole-base-path
		// deletion under a force no marker asked for.
		$force = is_array($entry) ? $entry['force'] : $job['force'];
		if(($force !== 1 && $force !== 2) || $job['force'] === false
			|| (is_array($entry) && $entry['force'] !== $job['force']))
		{
			erasedataReleaseAdmissionLocks($stateLock, $locks);
			$notes[] = array('force-disagreement', $generation, $members,
				'obligations-retained-nothing-erased', null);
			return($result);
		}
		// What is already final for this exact generation, read once under the
		// state lock. A published manifest is a licence to delete a payload, so
		// it is proof the member really was erased -- and the only thing that
		// may still be owed for it is the marker its publisher could not
		// remove.
		$entries = @scandir($listPath);
		if(!is_array($entries))
		{
			erasedataReleaseAdmissionLocks($stateLock, $locks);
			$notes[] = array('queue-unreadable', $generation, $members,
				'obligations-retained-nothing-erased', null);
			return($result);
		}
		$final = erasedataPublishedGenerations($entries);
		$staging = is_array($entry) ? $entry['staging'] : array();
		// A producer can die after a complete staging write but before the
		// journal binds its identity. Neither a replacement staging nor a
		// missing-hash reply proves that physical candidate was discharged.
		// Keep its marker until its exact binding can be recovered; a filename
		// alone must never authorize publication or cancellation.
		//
		// The refusal belongs to the HASH that owns the unbound object, not to
		// the generation. One admission binds up to a whole batch, so returning
		// out of the pass here froze every innocent sibling of a single crashed
		// producer: nothing published, no marker discharged, retirement
		// impossible for the life of the daemon, and no diagnostic naming any of
		// them. An unbound member is therefore skipped exactly the way a refused
		// force-2 capability is -- it stages nothing, erases nothing, publishes
		// nothing, cancels nothing and is never discharged as a loss -- and its
		// siblings resolve normally. Each one is reported here, once, against its
		// own hash, so the obligation that really is stranded stays visible.
		//
		// The note carries the name the scan MATCHED, never a name rebuilt from
		// the hash and the generation: the pid and uniqid in it are not derivable,
		// and a rebuilt name could point at a file that does not exist. Only a
		// human can clear this state, so the line has to say which file. scandir()
		// sorts, so when one member has several stray objects the first is named
		// deterministically and the next tick after it is removed names the next.
		$unbound = erasedataUnboundStagingCandidates($entries, $generation,
			$hashes, $staging, $listPath);
		foreach($unboundBeforeProbe as $hash => $name)
			if(!isset($unbound[$hash]))
				$notes[] = array('probe-deferred', $generation, $members,
					'staging-changed-presence-rechecked-next-tick', $hash);
		foreach($unbound as $hash => $name)
			$notes[] = array('staging-unbound', $generation, $members,
				'physical-staging-retained-journal-binding-recovery-required',
				$hash, $name);

		if(is_array($entry) && $entry['phase'] === 'prepared')
		{
			// The producer's own record, and its producer is gone. Nothing was
			// erased under a `prepared` record -- the first destructive call
			// only ever runs after a durable `erase-started` -- so cancelling
			// is complete and safe, and it is the ONLY thing allowed here.
			$cancelled = 0;
			$blocked = 0;
			foreach($hashes as $hash)
			{
				// Physical staging this record does not bind, reported above.
				// Cancelling would discharge its marker and leave the object
				// behind under no identity at all, so the member is retained.
				if(isset($unbound[$hash]))
				{
					$blocked++;
					continue;
				}
				// A final manifest under a record that never reached
				// `erase-started` cannot be accounted for. Nothing is cancelled
				// out from under it: it is retained, visibly, for a human.
				if(isset($final[$hash][$generation]))
				{
					$blocked++;
					$notes[] = array('publication-unaccounted', $generation, $members,
						'obligation-retained-nothing-erased', $hash);
					continue;
				}
				$released = !isset($staging[$hash])
					|| erasedataCancelPreparedStaging($staging[$hash], $filesystem);
				if($released
					&& erasedataDischargePendingMarker($listPath, $hash, $generation))
				{
					$cancelled++;
					$notes[] = array('prepared-cancelled', $generation, $members,
						'nothing-erased-obligation-cancelled', $hash);
					continue;
				}
				$blocked++;
				$notes[] = array('cancel-refused', $generation, $members,
					'obligation-retained-nothing-erased', $hash);
			}
			// The record goes only when every one of its bindings really did,
			// so a partial cancellation is retried on the next tick with no cap
			// of any kind. Cancelling is idempotent: a binding whose object is
			// already gone is already cancelled.
			if(!$blocked)
			{
				unset($state['journal'][$generation]);
				if(!erasedataWriteDrainState($listPath, $state))
				{
					erasedataReleaseAdmissionLocks($stateLock, $locks);
					$notes[] = array('journal-write', $generation, $members,
						'cancelled-'.$cancelled.'-retained-0', null);
					return(array('published' => 0, 'retained' => 0,
						'cancelled' => $cancelled, 'unrecoverable' => 0));
				}
			}
			erasedataReleaseAdmissionLocks($stateLock, $locks);
			if($blocked)
				$notes[] = array('drain-unresolved', $generation, $members,
					'cancelled-'.$cancelled.'-retained-'.$blocked, null);
			return(array('published' => 0, 'retained' => $blocked,
				'cancelled' => $cancelled, 'unrecoverable' => 0));
		}
		// `erase-started` means d.erase is or was in flight for this record, so
		// recovery stays conservative: it rebinds no staging and re-encodes no
		// manifest. It may only finish what the record already binds.
		$started = is_array($entry) && $entry['phase'] === 'erase-started';

		// Force-2 recovery has to prove the SAME physical capability the producer
		// proves, and prove it in the same place: before anything is staged and
		// long before d.erase. The producer's descriptor died with the producer,
		// so recovery never inherits one -- it opens its own on the base path here
		// and holds it, across the erase decision, until the finally below.
		//
		// Only a member rTorrent still holds can reach the destructive step, so
		// only those are asked for. A member whose capability is unavailable is
		// REFUSED, never resolved: it stages nothing, erases nothing, keeps its
		// pending marker and is retained for the next tick, so a base path that is
		// momentarily unopenable costs a retry and never an obligation.
		//
		// The paths each preflight collected are kept and reused by step (2), so
		// proving the capability does not ask the daemon for the same file list
		// twice.
		$capabilities = array();
		$collected = array();
		$refusedCapability = array();
		try
		{
			if($force === 2)
				foreach($hashes as $hash)
				{
					// The $unbound half of this test changes no outcome and no test
					// pins it: such a member is skipped again below, before anything
					// is staged or erased. It is here so a member that is going to be
					// retained does not have a directory descriptor opened and held
					// for it until the finally, which is work and an fd spent on a
					// hash this pass has already decided not to touch.
					if($presence[$hash] !== ERASEDATA_TORRENT_PRESENT
						|| isset($unbound[$hash]))
						continue;
					$collected[$hash] = erasedataCollectPaths($hash);
					$capability = is_array($collected[$hash])
						? erasedataAcquireRemovalCapability($collected[$hash], $filesystem)
						: false;
					if($capability === false)
					{
						$refusedCapability[$hash] = true;
						// Two different refusals, kept apart: the daemon could not
						// say what the download owns, or it could and the base path
						// would not yield a descriptor.
						$notes[] = array(is_array($collected[$hash])
							? 'descriptor-unavailable' : 'paths-unknown',
							$generation, $members,
							'obligation-retained-nothing-erased', $hash);
						continue;
					}
					$capabilities[$hash] = $capability;
				}

			// (2) Prepare. Every member rTorrent gave a definite answer for gets a
			// staging object of its own if it has none that is still exactly the
			// file the journal captured.
			$bound = array();
			$rollback = array();
			foreach($hashes as $hash)
			{
				if($presence[$hash] === ERASEDATA_TORRENT_UNKNOWN)
					continue;
				// A refused force-2 capability was reported above. Its member binds
				// nothing here, so this pass writes no staging under a mechanism it
				// could not prove and the obligation is left exactly as it was found.
				//
				// An unbound physical staging object, reported above, is skipped for
				// the same reason and one more: writing a second staging identity
				// beside it would let this pass erase under a manifest while the
				// crashed producer's own candidate still stands unaccounted for.
				if(isset($refusedCapability[$hash]) || isset($unbound[$hash]))
					continue;
				if(isset($staging[$hash])
					&& erasedataStagingIdentityStillMatches($staging[$hash]))
				{
					$bound[$hash] = true;
					continue;
				}
				if($started || isset($final[$hash][$generation]))
					continue;
				// The path collection is still attempted for a member rTorrent says
				// is GONE, and deliberately so: a daemon that can still answer for
				// it lets this tick stage a manifest and finish the payload
				// deletion, which is real recovery and the only thing that can
				// perform it. When the daemon cannot -- the ordinary case, since
				// every path command for an erased download faults -- the member
				// falls through to the settle step below, which discharges it ONCE
				// instead of asking again every five seconds for ever.
				$paths = isset($collected[$hash])
					? $collected[$hash] : erasedataCollectPaths($hash);
				if($paths === false)
				{
					// A present download cannot be erased without a file list.
					// For a gone one, step (4) decides whether its marker is
					// discharged; do not pre-report its obligation as retained.
					if($presence[$hash] === ERASEDATA_TORRENT_PRESENT)
						$notes[] = array('paths-unknown', $generation, $members,
							'obligation-retained', $hash);
					continue;
				}
				$content = ErasedataManifestCodec::encode($hash, $paths, $force);
				if($content === false)
				{
					$notes[] = array('manifest-encode', $generation, $members,
						'obligation-retained', $hash);
					continue;
				}
				$staged = erasedataStageAdmittedManifest($listPath, $hash, $generation, $content);
				if($staged === false)
				{
					$notes[] = array('staging-write', $generation, $members,
						'obligation-retained', $hash);
					continue;
				}
				$staging[$hash] = array(
					'path' => $staged['path'],
					'dev' => (string)$staged['stat']['dev'],
					'ino' => (string)$staged['stat']['ino'],
				);
				$rollback[$hash] = $staging[$hash];
				$bound[$hash] = true;
			}

			// (3) The destructive step, for the members rTorrent still holds and
			// whose complete binding -- identity AND the manifest bytes, decoded
			// against this exact hash and this exact force -- still stands. It runs
			// only after a durable `erase-started` record.
			$erasable = array();
			foreach($hashes as $hash)
				if($presence[$hash] === ERASEDATA_TORRENT_PRESENT && isset($bound[$hash])
					&& erasedataStagingManifestStillMatches($staging[$hash], $hash, $force))
					$erasable[] = $hash;
			// The producer re-checks its held descriptor immediately before its own
			// d.erase; recovery owes the identical re-check. A descriptor that no
			// longer names the base path it was opened on proves nothing any more, so
			// its member is dropped from the batch and retained instead. A null
			// capability is the single-file case, which is answered by
			// erasedataRemovalCapabilityStillMatches() itself and is why membership is
			// tested with array_key_exists() rather than isset().
			if($force === 2)
			{
				$ready = array();
				foreach($erasable as $hash)
					if(array_key_exists($hash, $capabilities) && isset($collected[$hash])
						&& is_array($collected[$hash])
						&& erasedataRemovalCapabilityStillMatches($collected[$hash],
							$capabilities[$hash], $filesystem))
						$ready[] = $hash;
					else
					{
						$refusedCapability[$hash] = true;
						$notes[] = array('descriptor-unavailable', $generation, $members,
							'obligation-retained-nothing-erased', $hash);
					}
				$erasable = $ready;
			}
			$classified = array();
			// Whether any JOURNAL record written by THIS pass became durable. It
			// decides, at step (5), whether the staging step (2) wrote is bound by
			// anything at all -- and it can only ever be raised here, because the
			// `erase-started` write below is the only durable JOURNAL write the
			// pass makes before its own last one. Read it that narrowly: the settle
			// step does write to disk between the two (it discharges markers and
			// publishes final manifests), and none of that binds staging, which is
			// exactly why those writes must not raise this flag.
			$durable = false;
			if(count($erasable))
			{
				$record = erasedataDrainJournalRecord($staging, $force, 'erase-started');
				$state['journal'][$generation] = $record;
				if($record === false || !erasedataWriteDrainState($listPath, $state))
				{
					erasedataRollbackAdmittedStaging($rollback, $filesystem);
					erasedataReleaseAdmissionLocks($stateLock, $locks);
					$notes[] = array('erase-started-write', $generation, $members,
						'nothing-erased-obligations-retained', null);
					return($result);
				}
				$durable = true;
				$request = erasedataEraseRequest($erasable);
				// Invariant 2's `E`: individual replies only. A request whose own
				// success() is false carries no per-command position at all, and a
				// request the shared builder refused to build was never sent.
				if($request !== false && $request->success())
					$classified = erasedataClassifyEraseOutcomes($erasable, $request);
			}

			// (4) Settle. A member is published only when it is individually known
			// gone AND the staging file still is the exact object the journal
			// captured; its marker is then discharged, which is part of the
			// obligation and not an afterthought. Everything else is retained.
			$published = array();
			$retained = array();
			$unrecoverable = 0;
			$completed = 0;
			foreach($hashes as $hash)
			{
				// The unbound physical candidate, retained before any settle branch
				// can reach it. `gone` plus an empty journal binding would otherwise
				// read as an unrecoverable loss and discharge the very marker that
				// is the only surviving record of this obligation, while its staging
				// object sat in the queue. It was reported once, by hash, above.
				if(isset($unbound[$hash]))
				{
					$retained[] = $hash;
					continue;
				}
				$gone = $presence[$hash] === ERASEDATA_TORRENT_ABSENT
					|| (isset($classified[$hash]) && $classified[$hash] === 'accepted');
				// A member whose final manifest for THIS generation already stands
				// was erased and published by whoever wrote it; all that can still
				// be owed for it is the marker that publisher could not remove.
				// Discharging that is the rest of the same obligation, and until it
				// is discharged the hash stays visible to every obligation reader.
				if($gone && !isset($bound[$hash]) && isset($final[$hash][$generation]))
				{
					if(erasedataDischargePendingMarker($listPath, $hash, $generation))
					{
						$published[] = $hash;
						continue;
					}
					$retained[] = $hash;
					$notes[] = array('marker-retained', $generation, $members,
						'obligation-retained', $hash);
					continue;
				}
				// THREE entirely different members arrive here, and calling all of
				// them a loss is FALSE.
				//
				// (1) Finished under THIS generation: its manifest was published,
				// the collector consumed it and deleted the payload, and its marker
				// was discharged by whoever completed it. Its manifest is absent
				// precisely BECAUSE collection worked, so it satisfies "gone, no
				// manifest, no staging" exactly as the other two do -- measured
				// twice against a real daemon, where the two members that had
				// completed perfectly were the ones reported as unrecoverable.
				//
				// (2) Served under ANOTHER generation: this generation's marker
				// stands because its admission wrote the marker and was then
				// refused, while the batch that won published a manifest for the
				// same hash and the collector has already deleted the payload.
				// Nothing was lost -- this is the ordinary residue of a refused
				// admission followed by a successful one.
				//
				// (3) A real loss: no generation ever published a manifest for it
				// and no file list can be constructed any more.
				//
				// rTorrent answered individually that the download is gone; no
				// manifest of THIS generation was ever published for it; and no
				// staging object of this record survives to publish. A file list
				// cannot be read off a download that no longer exists, so no later
				// tick can construct one either. Retrying costs one faulting RPC
				// every five seconds for ever and buys nothing, while the surviving
				// marker keeps the whole queue un-retirable -- both measured against
				// a real daemon. The marker is discharged and the outcome is stated,
				// once, with its hash.
				//
				// The pass cannot tell (2) from (3) and does not try: $final is
				// consulted only for THIS generation, and once the winning manifest
				// has been collected and its marker discharged the queue keeps no
				// record that the hash was ever served. So `obligation-unrecoverable`
				// is the pessimistic reading of a member that may simply have been
				// served elsewhere, not a certainty. What IS certain is that nothing
				// any actor could still act on is discharged: an UNKNOWN probe never
				// reaches here (it is retained below), and a member whose staging
				// object still exists never reaches here either (it is retained as
				// `publish-refused` or `staging-unbound`).
				if($gone && !isset($bound[$hash])
					&& erasedataStagingObjectIsGone($listPath, $staging, $hash))
				{
					// The marker separates (1) from the other two. It is the
					// obligation record itself, so a member with no marker of this
					// generation left owes nothing at all and its record is simply
					// finished; a marker that still stands says only that THIS
					// generation's obligation was never discharged.
					if(!erasedataPendingMarkerStands($listPath, $hash, $generation))
					{
						unset($staging[$hash]);
						$completed++;
						$published[] = $hash;
						$notes[] = array('obligation-complete', $generation,
							$members, 'download-gone-obligation-already-discharged',
							$hash);
						continue;
					}
					if(erasedataDischargePendingMarker($listPath, $hash, $generation))
					{
						unset($staging[$hash]);
						$unrecoverable++;
						$notes[] = array('obligation-unrecoverable', $generation,
							$members, 'download-gone-no-manifest-marker-discharged',
							$hash);
						continue;
					}
					$retained[] = $hash;
					$notes[] = array('marker-retained', $generation, $members,
						'obligation-retained', $hash);
					continue;
				}
				if(!$gone || !isset($bound[$hash]))
				{
					$retained[] = $hash;
					// An uncertain probe, and a refused force-2 descriptor, each already
					// said so once, above.
					//
					// No file is named here: the queue scan matched no unbound object
					// for this member, so there is no name to give. That is narrower
					// than "this member has none" -- the scan only recognises the
					// name shape a staging write produces, so an object of some other
					// shape would not have been matched either. Its record binds
					// nothing, which is what is reported.
					if($presence[$hash] !== ERASEDATA_TORRENT_UNKNOWN
						&& !isset($refusedCapability[$hash]))
						$notes[] = array(isset($bound[$hash])
							? 'erase-unresolved' : 'staging-unbound',
							$generation, $members, 'obligation-retained', $hash);
					continue;
				}
				// false: the note below classifies this refusal and goes through
				// the tick's report memory, so the codec's own unclassified line
				// would be the same condition said twice -- and said again on
				// every tick, since the codec has no memory at all.
				if(!erasedataStagingIdentityStillMatches($staging[$hash])
					|| !ErasedataManifestCodec::publishStaging($staging[$hash]['path'],
						$hash, $filesystem, false))
				{
					$retained[] = $hash;
					$notes[] = array('publish-refused', $generation, $members,
						'obligation-retained', $hash);
					continue;
				}
				if(!erasedataDischargePendingMarker($listPath, $hash, $generation))
				{
					$retained[] = $hash;
					$notes[] = array('marker-retained', $generation, $members,
						'obligation-retained', $hash);
					continue;
				}
				$published[] = $hash;
			}

			// (5) The journal, durably, before a single obligation is called
			// released. A record is never completed while a bound staging survives:
			// anything unresolved leaves it `retained` for the next tick.
			$record = erasedataDrainJournalRecord($staging, $force,
				count($retained) ? 'retained' : 'published');
			if($record !== false)
			{
				$state['journal'][$generation] = $record;
				if(!erasedataWriteDrainState($listPath, $state))
				{
					// Step (3) rolls its staging back when its journal write fails,
					// and this final write owes the identical rollback. Staging
					// this pass wrote whose binding never became
					// durable is exactly the unjournaled-staging condition the pass
					// refuses to resolve, so leaving it behind would manufacture, for
					// its own member, the permanent conservative retention above.
					//
					// It is released only when NO record written by this pass became
					// durable, and that is also what makes it safe: `$durable` is
					// false only when nothing was erasable, so nothing was erased
					// under any of these objects, and an already published one is a
					// path that no longer exists and is left alone by identity.
					if(!$durable)
						erasedataRollbackAdmittedStaging($rollback, $filesystem);
					$notes[] = array('journal-write', $generation, $members,
						'published-'.count($published).'-retained-'.count($retained), null);
				}
			}
			else if(($unrecoverable || $completed) && !count($retained)
				&& !count($staging) && isset($state['journal'][$generation]))
			{
				// Every binding this record ever had is discharged -- whether it
				// finished or whether nothing could ever finish it -- and none of
				// them can come back, so the record itself is now the only thing
				// keeping the queue un-retirable. Dropping it here rather than
				// leaving it for the next prune costs nothing and closes the tick.
				unset($state['journal'][$generation]);
				if(!erasedataWriteDrainState($listPath, $state))
					$notes[] = array('journal-write', $generation, $members,
						'unrecoverable-'.$unrecoverable.'-complete-'.$completed, null);
			}
			erasedataReleaseAdmissionLocks($stateLock, $locks);
			if(count($retained))
				$notes[] = array('drain-unresolved', $generation, $members,
					'published-'.count($published).'-retained-'.count($retained), null);
			// `cancelled` and `unrecoverable` are NOT the same outcome and are not
			// summed into one field. A cancelled member is a `prepared` staging
			// released with nothing erased and its obligation intact to re-admit;
			// an unrecoverable one is an obligation that ended undischargeable.
			// Reporting them in one number made a loss read as a tidy rollback.
			return(array('published' => count($published),
				'retained' => count($retained), 'cancelled' => 0,
				'unrecoverable' => $unrecoverable));
		}
		finally
		{
			erasedataReleaseRemovalCapabilities($capabilities, $filesystem);
		}
	}
}

// ---------------------------------------------------------------------------
// Conservative restart, rearm and retirement (invariant 10).
//
// Retirement is the only thing in this plugin that takes a schedule AWAY, so it
// is the one decision that can strand a destructive request nobody will ever
// serve again. Three facts, all measured on real 0.9.8 and 0.16.21 daemons and
// stated here in full rather than by reference, shape every line below:
//
//   * schedule_remove answers OK for a key that was NEVER REGISTERED, on both
//     versions. A successful return therefore proves nothing about what was
//     there before, and refusal is visible only as a transport failure or an
//     actual fault. That is why the durable `settled` -> `disarmed` writes come
//     FIRST: the removal's own result can never reconstruct what the durable
//     state should have been;
//   * re-registering a live key succeeds silently and RESTARTS the countdown,
//     so nothing may re-register unconditionally;
//   * the leading empty target is mandatory, and it is added by
//     rXMLRPCCommand::__construct() only when it is handed the LOGICAL name.
//
// The scan that licenses a retirement is conservative in one direction only.
// Anything it cannot account for -- a marker, a staging object, a final
// manifest, a journal record, residue of an interrupted durable write, a name
// outside every grammar, or a directory it could not read at all -- means
// RETAIN AND DIAGNOSE. "I could not tell" is never "the queue is empty".
// ---------------------------------------------------------------------------

if(!function_exists('erasedataClassifyRetirementCandidate'))
{
	// The class one queue entry belongs to, or false when the entry is a
	// control file this plugin owns and no obligation can hide behind.
	//
	// Classification is by NAME alone and on purpose. A marker whose BODY no
	// longer decodes is exactly the case a retirement scan must refuse on, so
	// deciding the class by decoding the content would make corruption look
	// like emptiness -- the one mistake this function exists to prevent.
	function erasedataClassifyRetirementCandidate($name)
	{
		if(!is_string($name) || $name === '' || $name === '.' || $name === '..')
			return(false);
		// The plugin's own control files. Every one of them is either
		// dot-prefixed or ends in .lock, so none can collide with a manifest,
		// a marker or a staging object.
		if($name === ERASEDATA_DRAIN_STATE_NAME
			|| $name === ERASEDATA_DRAIN_STATE_LOCK_NAME
			|| $name === ERASEDATA_DRAIN_WORKER_LOCK_NAME
			|| $name === ERASEDATA_DRAIN_SCHEDULER_LOCK_NAME)
			return(false);
		if(preg_match('/^[0-9A-Fa-f]{40}\.lock$/D', $name) === 1)
			return(false);
		// Residue of an interrupted durable write: erasedataWriteDurableFile()
		// stages at .<final name>.<token>.tmp, deliberately outside the
		// manifest grammar. The writer removes its own staging on every failure
		// it survives, so one that is still here outlived a process -- which is
		// uncertainty, not emptiness.
		if(substr($name, 0, 1) === '.')
			return('residue');
		if(erasedataCleanupArtifactNameParts($name) !== false)
			return('cleanup');
		if(preg_match('/^[0-9A-Fa-f]{40}\.[0-9a-f]{16}\.pending$/D', $name) === 1)
			return('pending');
		if(preg_match('/^[0-9A-Fa-f]{40}\.[0-9a-f]{16}\..+\.tmp$/D', $name) === 1)
			return('staging');
		if(preg_match('/^[0-9A-Fa-f]{40}\.[0-9a-f]{16}\..+\.list$/D', $name) === 1)
			return('final');
		// A bare <hash>.list carries no generation, so it acknowledges nothing
		// -- but it is still a published manifest the collector owes a payload
		// deletion for, and the queue is not empty while one stands.
		if(preg_match('/^[0-9A-Fa-f]{40}\.list$/D', $name) === 1)
			return('final');
		if(preg_match('/^[0-9A-Fa-f]{40}\./D', $name) === 1)
			return('malformed');
		return('unknown');
	}
}

if(!function_exists('erasedataRetirementScan'))
{
	// One fresh conservative scan of everything that could still be owed.
	//
	// Returns array('empty' => bool, 'candidates' => int, 'unreadable' => bool,
	// 'classes' => <class> => int). `empty` is true only when the directory was
	// read, the durable state was read, every entry was accounted for as a
	// control file, and the journal holds no record at all. Every other outcome
	// -- including a directory that could not be scanned and a state that could
	// not be parsed -- is `empty => false`.
	//
	// It is a PURE READ. It consumes nothing, unlinks nothing and rewrites
	// nothing, so a scan can never be the thing that makes a queue look empty.
	function erasedataRetirementScan(array $dependencies)
	{
		$listPath = isset($dependencies['listPath']) ? $dependencies['listPath'] : null;
		$scan = array(
			'empty' => false,
			'candidates' => 0,
			'unreadable' => true,
			'classes' => array('pending' => 0, 'staging' => 0, 'final' => 0,
				'cleanup' => 0, 'journal' => 0, 'residue' => 0, 'malformed' => 0, 'unknown' => 0),
		);
		if(!is_string($listPath) || $listPath === '' || strpos($listPath, "\0") !== false)
			return($scan);
		clearstatcache();
		$entries = @scandir($listPath);
		// Unreadable, or beyond the cardinality this store is bounded by: in
		// neither case can this process account for what is in there.
		if(!is_array($entries) || count($entries) > ERASEDATA_PENDING_MAX_MARKERS)
			return($scan);
		$scan['unreadable'] = false;
		foreach($entries as $entry)
		{
			$class = erasedataClassifyRetirementCandidate($entry);
			if($class === false)
				continue;
			$scan['classes'][$class]++;
			$scan['candidates']++;
		}
		// The SAME durable state the worker reads, not the markers alone. A
		// journal record with no file beside it is still an obligation -- a
		// `retained` one never prunes -- and a state nobody can parse is not
		// evidence of anything.
		$state = erasedataReadDrainState($listPath);
		if(!is_array($state) || !isset($state['journal']) || !is_array($state['journal']))
		{
			$scan['unreadable'] = true;
			return($scan);
		}
		$records = count($state['journal']);
		$scan['classes']['journal'] += $records;
		$scan['candidates'] += $records;
		$scan['empty'] = ($scan['candidates'] === 0);
		return($scan);
	}
}

if(!function_exists('erasedataRetirementRun'))
{
	// The retirement transaction itself, under the state lock and nothing else.
	//
	// The state lock is held across the whole decision INCLUDING the removal
	// RPC, and that is not an oversight. A producer takes the same lock before
	// it reads the phase that tells it whether to register, and it writes its
	// markers and its journal record without ever letting go of it, so holding
	// it here is what makes "the queue was empty when the schedule went away"
	// true rather than merely observed. Releasing it after the durable
	// `disarmed` write and before the RPC would let a producer arm a brand new
	// schedule that this removal then took away again.
	//
	// No hash lock is taken here and none is needed: nothing is erased,
	// published or unlinked, so invariant 7's "never hold the state lock while
	// waiting for a hash lock" cannot be violated from this path at all.
	//
	// Every classified note is APPENDED to $notes and NOTHING is reported here.
	// $notes is required rather than optional deliberately: the one production
	// caller is erasedataDrainWorkerRun(), which folds these notes into the
	// tick's own report memory so a refusal that has not changed since the last
	// tick stays silent -- invariant 12's bound is over TIME, not per tick, and
	// retirement is exactly the condition that repeats every
	// ERASEDATA_DRAIN_INTERVAL seconds for as long as one obligation cannot be
	// discharged. A wrapper that reported on its own used to sit here for
	// callers that hand in nothing; production never took that branch, so it
	// was code only the tests could reach and it is gone.
	function erasedataRetirementRun(array $dependencies, array &$notes)
	{
		$listPath = isset($dependencies['listPath']) ? $dependencies['listPath'] : null;
		$user = isset($dependencies['user']) ? $dependencies['user'] : null;
		$key = erasedataDrainScheduleKey($user);
		if(!is_string($listPath) || $listPath === '' || strpos($listPath, "\0") !== false
			|| $key === false)
		{
			$notes[] = array('retire-user', null, 0, 'schedule-retained', null);
			return(false);
		}
		// A queue directory that does not exist was never armed by anybody, so
		// there is nothing here to retire and nothing to report.
		if(!is_dir($listPath))
			return(false);
		$stateLock = erasedataAcquireDrainStateLock($listPath);
		if(!is_resource($stateLock))
		{
			$notes[] = array('retire-state-lock', null, 0, 'schedule-retained', null);
			return(false);
		}
		$state = erasedataReadDrainState($listPath);
		if(!is_array($state))
		{
			erasedataReleaseDrainStateLock($stateLock);
			$notes[] = array('retire-state-unreadable', null, 0,
				'schedule-retained', null);
			return(false);
		}
		// This user's own queue, or one nobody has ever claimed. '' is a real
		// user here, not a wildcard that may retire anybody's schedule.
		if(!erasedataDrainStateBelongsTo($state, $user))
		{
			erasedataReleaseDrainStateLock($stateLock);
			$notes[] = array('retire-user-mismatch', $state['generation'], 0,
				'schedule-retained', null);
			return(false);
		}
		// Nothing was ever admitted on this queue, so this user owns no drain
		// schedule to take away. Removing one anyway would answer OK on both
		// daemons and prove nothing, which is exactly the failure mode a race
		// with a producer that is about to arm looks like.
		//
		// The note is not decoration. This refusal used to be the one silent
		// exit in the whole transaction, and a re-arm that armed on the zero
		// generation therefore produced a schedule nothing could retire and
		// nothing would ever mention. The arm side is fixed in
		// erasedataRearmDrainScheduleRun(); this makes the refusal visible in
		// its own right, as invariant 10 requires.
		if($state['generation'] === ERASEDATA_DRAIN_ZERO_GENERATION)
		{
			erasedataReleaseDrainStateLock($stateLock);
			$notes[] = array('retire-unarmed', $state['generation'], 0,
				'no-generation-was-ever-admitted-nothing-to-retire', null);
			return(false);
		}
		// A STABLE generation: the arm completed, and a really started guarded
		// child has acknowledged exactly the generation the state carries. An
		// `arming` phase is a registration in flight, and an acknowledgement
		// behind the generation is an admission no child has picked up yet;
		// retiring on either is retiring a just-admitted generation.
		if($state['phase'] === 'arming'
			|| $state['acknowledged'] !== $state['generation'])
		{
			erasedataReleaseDrainStateLock($stateLock);
			$notes[] = array('retire-unstable', $state['generation'], 0,
				'schedule-retained-admission-in-flight', null);
			return(false);
		}
		// Resolved journal records go FIRST, durably, under this same lock.
		//
		// The journal is half of the scan (invariant 10 counts every record as
		// a candidate), and before this the only pruner in the plugin ran on
		// the admission path -- so after the LAST removal on a queue at least
		// one record always stood, the scan was never empty, and the durable
		// `settled`/`disarmed` writes and the mapped schedule_remove below were
		// unreachable for ever. Pruning here is what makes retirement able to
		// observe the emptiness it exists to act on. It drops only records that
		// bind nothing at all (see erasedataPruneResolvedJournalRecords) and it
		// runs before the scan because the scan re-reads the durable state.
		$abandoned = array();
		$pruned = erasedataPruneResolvedJournalRecords($listPath, $state, $abandoned);
		if($pruned['journal'] !== $state['journal'])
		{
			if(!erasedataWriteDrainState($listPath, $pruned))
			{
				erasedataReleaseDrainStateLock($stateLock);
				$notes[] = array('retire-prune-write', $state['generation'],
					count($state['journal']), 'schedule-retained', null);
				return(false);
			}
			$state = $pruned;
			foreach($abandoned as $dropped => $phase)
				$notes[] = array('journal-abandoned', (string)$dropped, 0,
					'record-bound-nothing-left-dropped-from-'.$phase, null);
		}
		// FRESH, and taken after the lock: a scan from before it describes a
		// queue a producer may already have added to.
		$scan = erasedataRetirementScan($dependencies);
		if($scan['empty'] !== true)
		{
			erasedataReleaseDrainStateLock($stateLock);
			$notes[] = array($scan['unreadable'] ? 'retire-scan-uncertain'
				: 'retire-refused', $state['generation'], $scan['candidates'],
				'schedule-retained-obligations-outstanding', null);
			return(false);
		}
		// Durable first, in this exact order, and only then the RPC.
		if($state['phase'] !== 'settled' && $state['phase'] !== 'disarmed')
		{
			$state['phase'] = 'settled';
			if(!erasedataWriteDrainState($listPath, $state))
			{
				erasedataReleaseDrainStateLock($stateLock);
				$notes[] = array('retire-settle-write', $state['generation'], 0,
					'schedule-retained', null);
				return(false);
			}
		}
		if($state['phase'] !== 'disarmed')
		{
			$state['phase'] = 'disarmed';
			if(!erasedataWriteDrainState($listPath, $state))
			{
				erasedataReleaseDrainStateLock($stateLock);
				$notes[] = array('retire-disarm-write', $state['generation'], 0,
					'schedule-retained', null);
				return(false);
			}
		}
		// The LOGICAL name to the constructor, never the resolved spelling.
		// getCmd() answers the deprecated 0.9.x removal alias there, and that
		// alias is not itself a key in the alias table, so the mandatory
		// leading empty target would never be added and a real 0.9.8 daemon
		// answers "Unsupported target type found." -- measured, not read.
		$request = new rXMLRPCRequest(new rXMLRPCCommand('schedule_remove', $key));
		$removed = $request->success() && !$request->fault;
		if(!$removed)
		{
			erasedataReleaseDrainStateLock($stateLock);
			// The durable settled/disarmed writes stand for a later retry.
			$notes[] = array('retire-remove-refused', $state['generation'], 0,
				'durable-disarm-stands-removal-retried', null);
			return(false);
		}
		if(isset($state['legacy_rescue']))
		{
			$request = new rXMLRPCRequest(new rXMLRPCCommand('schedule_remove',
				erasedataDrainRescueScheduleKey($user)));
			if(!$request->success() || $request->fault)
			{
				erasedataReleaseDrainStateLock($stateLock);
				$notes[] = array('retire-rescue-remove-refused',
					$state['generation'], 0,
					'durable-disarm-stands-removal-retried', null);
				return(false);
			}
		}
		if(isset($state['legacy_rescue']))
		{
			unset($state['legacy_rescue']);
			if(!erasedataWriteDrainState($listPath, $state))
			{
				erasedataReleaseDrainStateLock($stateLock);
				$notes[] = array('retire-rescue-state-write',
					$state['generation'], 0,
					'keys-removed-rescue-intent-retained');
				return(false);
			}
		}
		erasedataReleaseDrainStateLock($stateLock);
		return(true);
	}
}

if(!function_exists('erasedataNoAdmissionHoldsThisQueue'))
{
	// Is there provably NO admission inside its arm window on this queue?
	//
	// A producer takes its sorted hash locks BEFORE it writes `arming` and
	// holds every one of them until its admission ends -- across the
	// registration RPC included, which is exactly why that release was removed
	// from erasedataRemovalAdmissionRun(). So a producer that is genuinely in
	// flight always holds at least one <HASH>.lock in this queue, and a queue
	// in which every one of them can be taken NONBLOCKING holds no admission
	// at all. That is what makes a durable `arming` correctable without ever
	// touching one somebody is still using.
	//
	// It proves ONE direction, which is the direction that matters here: true
	// means "nobody is arming", false means "cannot tell". A directory this
	// process cannot read, a lock file it cannot open and a lock somebody else
	// holds -- the periodic collector, the legacy producer, a drain pass -- all
	// answer false and leave the phase exactly as found.
	//
	// It costs one open per <HASH>.lock, and it is only ever reached on a queue
	// that owes nothing AND carries a durable `arming`, which is the wedge
	// itself: once corrected, nothing calls it again.
	function erasedataNoAdmissionHoldsThisQueue($listPath)
	{
		if(!is_string($listPath) || $listPath === ''
			|| strpos($listPath, "\0") !== false)
			return(false);
		clearstatcache(true, $listPath);
		if(!is_dir($listPath) || !is_readable($listPath))
			return(false);
		$entries = @scandir($listPath);
		if(!is_array($entries))
			return(false);
		foreach($entries as $entry)
		{
			if(preg_match('/^[0-9A-Fa-f]{40}\.lock$/D', $entry) !== 1)
				continue;
			$handle = @fopen($listPath.'/'.$entry, 'c');
			if($handle === false)
				return(false);
			$free = @flock($handle, LOCK_EX | LOCK_NB);
			if($free)
				@flock($handle, LOCK_UN);
			@fclose($handle);
			if(!$free)
				return(false);
		}
		return(true);
	}
}

if(!function_exists('erasedataRearmDrainScheduleRun'))
{
	// Startup recovery, with every dependency handed in.
	//
	// It reads the SAME durable state and runs the SAME conservative scan the
	// drain worker and retirement do. It never infers safety -- or work -- from
	// a *.pending file alone: a state that is present but unparseable fails
	// closed and registers nothing, because a process that cannot read the
	// journal cannot tell which generation a marker belongs to, and arming a
	// schedule over an obligation it cannot see is how two schedules end up
	// draining one queue.
	//
	// It registers ONLY when this SCHEDULE is really owed something AND the
	// durable state carries a generation the arm could later be retired on.
	// When something IS owed the schedule is required. Startup registers the
	// same key after a restart; headless recovery uses schedule.if_absent on
	// modern daemons or a verified volatile marker on legacy daemons.
	//
	// THREE guards make that convergent rather than a one-way door.
	//
	// (1) THE ARM PREDICATE IS THE DRAIN PROTOCOL'S OWN OBLIGATIONS, not the
	// whole conservative scan. The scan is written for RETIREMENT, which must
	// refuse on anything it cannot account for, so it also counts `final`,
	// `malformed`, `residue` and `unknown` entries. Arming on those made the
	// arm predicate and the retire predicate disagree, and the disagreement was
	// reachable on a stock install when plugins/httprpc/action.php ran the
	// legacy producer, which published a <HASH>.<pid>.<uniqid>.list that lived
	// about a collector interval, classified as `malformed` and never touched
	// the drain state. One full UI load in that window used to arm
	// erasedata-drain<User> at the zero generation, which retirement then
	// refuses for ever -- a five-second child spawn for the life of the daemon
	// that nothing in the plugin could remove.
	//
	// (2) THE ZERO GENERATION IS NEVER ARMED. Retirement refuses at the zero
	// generation and must: removing a schedule nobody admitted anything on
	// answers OK on both daemons and proves nothing. So an arm made there could
	// never be taken away, and a worker started by it would refuse every job it
	// found with `generation-unarmed` anyway.
	//
	// (3) A QUEUE THAT OWES NOTHING WRITES `disarmed`. Leaving the phase alone
	// made a restart leave a stale `armed` claim standing over a schedule table
	// rTorrent had just emptied, and the producer's own live-schedule guard
	// then trusted that claim, registered nothing and let the first removal
	// after the restart time out with an unscheduled marker behind it. Nothing
	// is owed here and the volatile table is provably suspect, so claiming an
	// arm is unsound; one extra registration by the next producer is the entire
	// cost.
	//
	// The postponement hazard the daemon evidence names is handled in
	// erasedataDrainScheduleCommand(), which builds an ALIGNED start: every
	// re-registration of one key resolves to the same absolute fire instant, so
	// however many page loads reach this function they cannot push a live
	// countdown past a blocked producer's acknowledgement window.
	//
	// Returns true when the drain schedule is known to be in the state the
	// durable record calls for -- re-armed, or provably not needed -- and false
	// whenever anything about that is uncertain.
	function erasedataRearmDrainScheduleRun(array $dependencies)
	{
		$listPath = isset($dependencies['listPath']) ? $dependencies['listPath'] : null;
		$user = isset($dependencies['user']) ? $dependencies['user'] : null;
		$log = isset($dependencies['log']) ? $dependencies['log'] : null;
		$context = isset($dependencies['context']) && $dependencies['context'] === 'headless'
			? 'headless-recovery' : 'startup-recovery';
		if(!is_string($listPath) || $listPath === '' || strpos($listPath, "\0") !== false)
			return(false);
		if(erasedataDrainScheduleKey($user) === false)
		{
			erasedataDrainDiagnostic($log, 'rearm-user', null, 0,
				$context . '-refused');
			return(false);
		}
		if(!is_dir($listPath))
			@FileUtil::makeDirectory($listPath);
		if(!is_dir($listPath))
		{
			erasedataDrainDiagnostic($log, 'queue-unavailable', null, 0,
				$context . '-refused');
			return(false);
		}
		// NONBLOCKING: plugin init must not stall a full web-UI load, and a
		// headless admission must not wait behind another actor after its own
		// acknowledgement timeout. A later caller can retry a busy re-arm.
		$stateLock = erasedataAcquireDrainStateLock($listPath, true);
		if(!is_resource($stateLock))
		{
			erasedataDrainDiagnostic($log, 'rearm-state-busy', null, 0,
				$context . '-deferred-another-actor-owns-the-state');
			return(false);
		}
		$state = erasedataReadDrainState($listPath);
		if(!is_array($state))
		{
			erasedataReleaseDrainStateLock($stateLock);
			erasedataDrainDiagnostic($log, 'rearm-state-unreadable', null, 0,
				$context . '-refused-nothing-armed');
			return(false);
		}
		// This user's own queue, or one nobody has ever claimed. '' is a real
		// user here, not a wildcard that may arm over anybody's obligation.
		if(!erasedataDrainStateBelongsTo($state, $user))
		{
			erasedataReleaseDrainStateLock($stateLock);
			erasedataDrainDiagnostic($log, 'rearm-user-mismatch',
				$state['generation'], 0, $context . '-refused-nothing-armed');
			return(false);
		}
		$scan = erasedataRetirementScan($dependencies);
		// Guard (1): what this SCHEDULE serves, out of everything the
		// conservative scan counts. The scan is written for RETIREMENT, which
		// must refuse on anything it cannot account for, so it also counts
		// `final`, `malformed` and `residue` entries -- a legacy
		// <HASH>.<pid>.<uniqid>.list formerly published by httprpc, or the
		// staging of an interrupted durable write. Those belong to the ordinary
		// erasedata<User> collector, or to nobody; the drain worker cannot
		// discharge one, and arming for one is what created a schedule
		// retirement could never take away. A queue this process could not
		// fully account for (`unreadable`) counts as owed, because "I could not
		// tell" is never "nothing to do".
		$owed = $scan['unreadable'] === true
			|| $scan['classes']['pending'] > 0
			|| $scan['classes']['staging'] > 0
			|| $scan['classes']['cleanup'] > 0
			|| $scan['classes']['journal'] > 0;
		if(!$owed)
		{
			// Guard (3): correct a stale claim rather than leave it standing.
			// A queue that owes this schedule nothing needs no schedule, and
			// rTorrent's table is volatile, so `armed` here can only mislead
			// the next producer into registering nothing and timing out.
			//
			// `armed` is corrected unconditionally. `arming` is corrected only
			// on PROOF that no admission is in one: the producer releases the
			// state lock across its schedule RPC, so a page load that rewrote
			// the phase on nothing but the phase itself would break a perfectly
			// good admission. It keeps its HASH locks across that RPC, though,
			// so a queue whose every hash lock is free holds no producer at
			// all -- and an `arming` left standing by one that is gone is
			// corrected by NOTHING otherwise. Retirement refuses on `arming`
			// for ever (`retire-unstable`), so a live registration plus a
			// permanently `arming` phase respawns the drain child every
			// ERASEDATA_DRAIN_INTERVAL seconds for the life of the daemon: the
			// F1 consequence, re-entered by a different door.
			//
			// `settled` is retirement's own half-written transaction, which
			// retirement converges on by itself, and is not this function's to
			// correct.
			$written = true;
			$abandonedArm = $state['phase'] === 'arming'
				&& erasedataNoAdmissionHoldsThisQueue($listPath);
			if($state['phase'] === 'armed' || $abandonedArm)
			{
				$state['user'] = $user;
				$state['phase'] = 'disarmed';
				$written = erasedataWriteDrainState($listPath, $state);
			}
			erasedataReleaseDrainStateLock($stateLock);
			if(!$written)
			{
				erasedataDrainDiagnostic($log, 'rearm-disarm-write',
					$state['generation'], 0,
					'nothing-owed-stale-armed-claim-not-cleared');
				return(false);
			}
			// Said once, and classified, because it is the one correction this
			// function makes that a reader would otherwise have no record of:
			// an admission really did die between its registration and its
			// compare-and-swap.
			if($abandonedArm)
				erasedataDrainDiagnostic($log, 'rearm-arming-abandoned',
					$state['generation'], 0,
					'nothing-owed-no-producer-holds-the-queue-disarmed');
			return(true);
		}
		// Guard (2): something is owed, but no generation was ever admitted on
		// this queue, so nothing here can be bound to an arm and no arm made
		// now could ever be retired -- retirement refuses at the zero
		// generation, and must. A worker started anyway would refuse every job
		// with `generation-unarmed`. This is the durable state having been lost
		// underneath surviving markers, or a queue directory that cannot be
		// read at all: an abnormal condition, reported rather than papered over
		// with a schedule that fires for ever.
		if($state['generation'] === ERASEDATA_DRAIN_ZERO_GENERATION)
		{
			erasedataReleaseDrainStateLock($stateLock);
			erasedataDrainDiagnostic($log, 'rearm-unarmed', $state['generation'],
				$scan['candidates'],
				'no-generation-was-ever-admitted-nothing-armed');
			return(false);
		}
		// Unreachable as configured, for the same reason as `arm-unavailable`
		// in the producer: this $user already passed the key rule at the top of
		// the function, and the interval is the constant 5. Kept so a refusal
		// stays classified rather than surfacing as the neighbouring
		// `rearm-refused`.
		$command = erasedataDrainScheduleCommand($user, ERASEDATA_DRAIN_INTERVAL);
		$legacy = erasedataLegacyDrainVersion();
		if($legacy === null)
		{
			erasedataReleaseDrainStateLock($stateLock);
			erasedataDrainDiagnostic($log, 'rearm-version-unknown',
				$state['generation'], $scan['candidates'],
				$context . '-refused-obligations-retained');
			return(false);
		}
		$rescue = false;
		if($legacy && $context === 'headless-recovery')
		{
			// A seven-field state cannot tell whether the original key is live.
			// Its first headless recovery arms a distinct guarded-worker key.
			$rescue = !isset($state['legacy_marker'])
				&& !isset($state['legacy_rescue']);
			if(!$rescue)
			{
				$marker = erasedataLegacyDrainMarkerStatus($user);
				if($marker === null)
				{
					erasedataReleaseDrainStateLock($stateLock);
					erasedataDrainDiagnostic($log, 'rearm-legacy-probe',
						$state['generation'], $scan['candidates'],
						'headless-recovery-refused-daemon-identity-unknown');
					return(false);
				}
				if($marker === true && !isset($state['legacy_marker']))
					$rescue = true;
				if($marker === true && !$rescue && $state['phase'] === 'armed')
				{
					erasedataReleaseDrainStateLock($stateLock);
					return(true);
				}
			}
		}
		if($rescue)
			$command = erasedataDrainScheduleCommand($user,
				ERASEDATA_DRAIN_INTERVAL, true);
		if(!$legacy && $command !== false && $context === 'headless-recovery')
		{
			// Reuse the already encoded arguments, including the target.
			$command->command = getCmd('schedule.if_absent');
		}
		if($legacy && isset($state['legacy_marker']))
		{
			// If any subsequent RPC fails, future callers see an unverified
			// arm and refuse instead of trusting a marker for a failed schedule.
			unset($state['legacy_marker']);
			if(!erasedataWriteDrainState($listPath, $state))
			{
				erasedataReleaseDrainStateLock($stateLock);
				erasedataDrainDiagnostic($log, 'rearm-legacy-state',
					$state['generation'], $scan['candidates'],
					$context . '-refused-obligations-retained');
				return(false);
			}
		}
		if($command === false)
		{
			erasedataReleaseDrainStateLock($stateLock);
			erasedataDrainDiagnostic($log, 'rearm-unavailable',
				$state['generation'], $scan['candidates'],
				$context . '-refused-obligations-retained');
			return(false);
		}
		if($legacy && !erasedataLegacyDrainMarkerEnsure($user))
		{
			erasedataReleaseDrainStateLock($stateLock);
			erasedataDrainDiagnostic($log, 'rearm-legacy-marker',
				$state['generation'], $scan['candidates'],
				$context . '-refused-obligations-retained');
			return(false);
		}
		if($rescue && !isset($state['legacy_rescue']))
		{
			// Persist the retirement obligation before registering a second
			// key. A crash after acceptance can never leave an endless child.
			$state['legacy_rescue'] = true;
			if(!erasedataWriteDrainState($listPath, $state))
			{
				erasedataReleaseDrainStateLock($stateLock);
				erasedataDrainDiagnostic($log, 'rearm-rescue-state',
					$state['generation'], $scan['candidates'],
					'headless-recovery-refused-obligations-retained');
				return(false);
			}
		}
		$request = new rXMLRPCRequest($command);
		if(!$request->success() || $request->fault)
		{
			erasedataReleaseDrainStateLock($stateLock);
			erasedataDrainDiagnostic($log, 'rearm-refused', $state['generation'],
				$scan['candidates'], $context . '-refused-obligations-retained');
			return(false);
		}
		if($legacy && erasedataLegacyDrainMarkerStatus($user) !== true)
		{
			erasedataReleaseDrainStateLock($stateLock);
			erasedataDrainDiagnostic($log, 'rearm-legacy-marker',
				$state['generation'], $scan['candidates'],
				$context . '-refused-obligations-retained');
			return(false);
		}
		// The generation is NEVER touched here. What a restart lost is the
		// volatile registration; the obligation it belongs to is exactly the
		// one the durable state already names, and inventing a new generation
		// for it would orphan every marker and journal record bound to the old.
		$state['user'] = $user;
		$state['phase'] = 'armed';
		if($legacy)
			$state['legacy_marker'] = true;
		$written = erasedataWriteDrainState($listPath, $state);
		erasedataReleaseDrainStateLock($stateLock);
		if(!$written)
		{
			erasedataDrainDiagnostic($log, 'rearm-state-write', $state['generation'],
				$scan['candidates'], 'schedule-armed-durable-state-not-updated');
			return(false);
		}
		return(true);
	}
}

if(!function_exists('erasedataRearmDrainSchedule'))
{
	// The public startup-recovery entry point, called by erasedata init or
	// Ratio init when erasedata is not registered. It builds every real
	// dependency itself; nothing below reads a test switch, an environment
	// override or a global.
	function erasedataRearmDrainSchedule()
	{
		$listPath = FileUtil::getSettingsPath()."/erasedata";
		@FileUtil::makeDirectory($listPath);
		// listPath, user and log, and nothing else: erasedataRearmDrainScheduleRun()
		// and the retirement scan below it read exactly those three. A
		// 'filesystem' key here would be built on every full web-interface load
		// and thrown away unread -- the same shape as the 'forceEnabled' key
		// that was already removed from the admission dependencies.
		return(erasedataRearmDrainScheduleRun(array(
			'listPath' => $listPath,
			'user' => User::getUser(),
			'log' => array('FileUtil', 'toLog'),
		)));
	}
}

if(!function_exists('erasedataDrainWorkerRun'))
{
	// One tick of the guarded worker, with every dependency handed in.
	//
	// $dependencies is exactly listPath, user, filesystem and log. The public
	// wrapper erasedataDrainWorkerMain() always builds the real ones; nothing
	// here reads a test switch, an environment override or a global.
	function erasedataDrainWorkerRun(array $dependencies)
	{
		$listPath = isset($dependencies['listPath']) ? $dependencies['listPath'] : null;
		$user = isset($dependencies['user']) ? $dependencies['user'] : null;
		$log = isset($dependencies['log']) ? $dependencies['log'] : null;
		$filesystem = isset($dependencies['filesystem'])
			&& $dependencies['filesystem'] instanceof ErasedataFilesystemOps
			? $dependencies['filesystem'] : new ErasedataFilesystemOps();
		if(!is_string($listPath) || $listPath === '' || strpos($listPath, "\0") !== false)
			return(false);
		// A non-string, oversized or NUL-carrying user has no schedule key, so
		// the child cannot tell whose queue it was started for. It admits no
		// worker work at all. '' is NOT one of those: it is the ordinary user of
		// a single-user install and gets the bare-prefix key like any other.
		if(erasedataDrainScheduleKey($user) === false)
		{
			erasedataDrainDiagnostic($log, 'worker-user', null, 0,
				'worker-refused-nothing-consumed');
			return(false);
		}
		if(!is_dir($listPath))
			@FileUtil::makeDirectory($listPath);
		if(!is_dir($listPath))
		{
			erasedataDrainDiagnostic($log, 'queue-unavailable', null, 0,
				'worker-refused-nothing-consumed');
			return(false);
		}

		// (1) The acknowledgement, first, short, under the state lock alone.
		//
		// Its own four refusals are written straight out, unsuppressed: they
		// are exactly the conditions in which there is no readable or writable
		// durable state to carry a memory in, so there is nothing to compare
		// against and a broken queue must not be able to go quiet.
		$acknowledgement = erasedataAcknowledgeDrainGeneration($listPath, $user, $log);
		if(!is_array($acknowledgement))
			return(false);
		$generation = $acknowledgement['generation'];
		$memory = erasedataDrainReportMemory($acknowledgement['state']);
		$fresh = array();

		// (2) Worker admission, NONBLOCKING. A hung owner is a bounded
		// classified consequence: the flock is never broken and not one of the
		// jobs the owner holds is consumed behind its back.
		$workerLock = erasedataAcquireDrainPassLock($listPath,
			ERASEDATA_DRAIN_WORKER_LOCK_NAME, true);
		if(!is_resource($workerLock))
		{
			erasedataDrainReportGroup($log, 'tick', array(array('worker-busy',
				$generation, 0, 'stale-worker-owns-the-queue-nothing-consumed',
				null)), $memory, $fresh);
			// A tick that got no further re-evaluated nothing but itself, so it
			// KEEPS what the last complete tick remembered and only adds its
			// own; replacing the memory here would make the owner's own
			// obligations look new again on the very next tick.
			erasedataPersistDrainReport($listPath, $user, array_merge($memory, $fresh),
				true);
			// A tick that was refused worker admission retires nothing: the
			// owner it just declined to displace is the actor whose evidence
			// the decision would have to be made on.
			return(array('acknowledged' => $generation, 'admitted' => false,
				'generations' => 0, 'published' => 0, 'retained' => 0,
				'cancelled' => 0, 'unrecoverable' => 0, 'retired' => false));
		}

		// (3) The scheduler lock, BLOCKING: the drain tick waits for the
		// periodic pass rather than walking the same queue beside it.
		$schedulerLock = erasedataAcquireDrainPassLock($listPath,
			ERASEDATA_DRAIN_SCHEDULER_LOCK_NAME, false);
		if(!is_resource($schedulerLock))
		{
			erasedataDrainReportGroup($log, 'tick', array(array('scheduler-lock',
				$generation, 0, 'nothing-consumed', null)), $memory, $fresh);
			erasedataPersistDrainReport($listPath, $user, array_merge($memory, $fresh));
			erasedataReleaseDrainPassLock($workerLock);
			return(false);
		}

		// (4) Both halves of the obligation, then one pass per generation.
		try
		{
			$obligations = erasedataPendingObligations($listPath);
			if(!is_array($obligations))
			{
				erasedataDrainReportGroup($log, 'tick', array(array('queue-unreadable',
					$generation, 0, 'nothing-consumed', null)), $memory, $fresh);
				erasedataPersistDrainReport($listPath, $user, array_merge($memory, $fresh));
				return(false);
			}
			$jobs = erasedataDrainWorkerJobs($listPath, $acknowledgement['state'], $obligations);
			$published = 0;
			$retained = 0;
			$cancelled = 0;
			$unrecoverable = 0;
			foreach($jobs as $jobGeneration => $job)
			{
				$notes = array();
				$outcome = erasedataDrainGenerationPass($listPath, $user,
					(string)$jobGeneration, $job, $filesystem, $notes,
					$acknowledgement['state']);
				erasedataDrainReportGroup($log, (string)$jobGeneration, $notes,
					$memory, $fresh);
				$published += $outcome['published'];
				$retained += $outcome['retained'];
				$cancelled += $outcome['cancelled'];
				$unrecoverable += $outcome['unrecoverable'];
			}
			// (5) Published manifests remain work for this same repeating drain,
			// including when the ordinary erasedata schedule is disabled. Keep
			// global admission through collection and retirement; only drain waits
			// for busy payload hashes, while ordinary collectors remain nonblocking.
			require_once(dirname(__FILE__).'/collector.php');
			global $enableForceDeletion;
			$collector = new ErasedataCollector($filesystem, 'erasedataTorrentPresence',
				'erasedataCollectPaths', 'eLog', !empty($enableForceDeletion), true);
			// Retention is a CONDITION, not an event: a job the presence probe
			// cannot answer for is retained again on every tick,
			// ERASEDATA_DRAIN_INTERVAL apart, for as long as the probe stays
			// unreadable, and the retries deliberately have no terminal limit.
			// Logged per tick that is about 17k identical lines a day per retained
			// job. So both halves of the collector's retention -- payload and
			// cleanup, which its own comment calls one rule -- go through this
			// tick's report memory, exactly as retirement does below. An unchanged
			// retention says nothing on the thousandth tick; a reason that CHANGES
			// is reported at once, and so is a generation -- for the manifest half,
			// which carries one. The cleanup half is identified by hash and reason
			// alone, because a cleanup job's name carries no generation to
			// distinguish two of them by.
			//
			// The memory covers the pending/journal union, and serialization keeps
			// named groups before generation keys if truncation is ever needed.
			$retainNotes = array('manifest' => array(), 'cleanup' => array());
			$collector->reportRetentionsTo(
				function($kind, $hash, $generation, $reason) use (&$retainNotes) {
					if(!isset($retainNotes[$kind]))
						return;
					$retainNotes[$kind][] = array($kind.'-retained', $generation, 0,
						$reason, $hash, null);
				});
			$collector->run($listPath);
			foreach($retainNotes as $kind => $notes)
				erasedataDrainReportGroup($log, $kind, $notes, $memory, $fresh);

			// (6) Retirement, on this tick's own evidence and nobody else's. It is
			// wired HERE, in the one production tick, because a retirement nothing
			// calls is a schedule that fires for ever over an empty queue; and it
			// is given this tick's report memory so a refusal that has not changed
			// says nothing on the thousandth tick either.
			$retireNotes = array();
			$retired = erasedataRetirementRun($dependencies, $retireNotes);
			erasedataDrainReportGroup($log, 'retire', $retireNotes, $memory, $fresh);
			// A complete tick re-evaluated every physical job the queue owes, so
			// the memory becomes exactly what it reported. A condition that has
			// gone away is forgotten, and if it ever comes back it is reported
			// again at once rather than staying suppressed for ever.
			erasedataPersistDrainReport($listPath, $user, $fresh);
			return(array('acknowledged' => $generation, 'admitted' => true,
				'generations' => count($jobs), 'published' => $published,
				'retained' => $retained, 'cancelled' => $cancelled,
				'unrecoverable' => $unrecoverable, 'retired' => $retired));
		}
		finally
		{
			erasedataReleaseDrainPassLock($schedulerLock);
			erasedataReleaseDrainPassLock($workerLock);
		}
	}
}

if(!function_exists('erasedataDrainWorkerMain'))
{
	// The public production entry point, called by update.php and by nothing
	// else. It takes the user the scheduler passed on the command line -- never
	// User::getUser(), which on a CLI child answers whatever REMOTE_USER
	// happened to be set to -- and builds every dependency below it itself.
	//
	// Returns true when the tick ran to a decision, false when it refused.
	function erasedataDrainWorkerMain($user)
	{
		$listPath = FileUtil::getSettingsPath()."/erasedata";
		@FileUtil::makeDirectory($listPath);
		return(erasedataDrainWorkerRun(array(
			'listPath' => $listPath,
			'user' => $user,
			'filesystem' => new ErasedataFilesystemOps(),
			'log' => array('FileUtil', 'toLog'),
		)) !== false);
	}
}

if(!function_exists('erasedataAdmitRemoval'))
{
	// The one public admission door. All removal entry points come through
	// here, and it takes nothing but the request: every dependency below it is
	// the real one, constructed here.
	//
	// Being public, it normalizes the force the same way the producer does: a
	// caller that read it off the wire hands over the decimal spelling, and
	// this is the boundary that turns it into the integer the internal runner
	// requires. Anything that is not exactly 1, 2, "1" or "2" is refused here
	// and reaches no lock, marker, journal record, staging file or RPC.
	//
	// There is deliberately no `forceEnabled` dependency here, and this is the
	// explanation erasedataRemovalAdmissionRun()'s comment points at.
	//
	// It used to be built as !empty($enableForceDeletion) and read by nobody,
	// which is worse than either honest alternative: a key that looks like a
	// policy and decides nothing. Reading it was considered and rejected,
	// because the value cannot be constructed truthfully at this door. None of
	// erase.php, action.php or plugins/httprpc/action.php evaluates the
	// plugin configuration before admission. Two files
	// do evaluate it, and neither puts it in reach of this door: update.php
	// does it at file scope, which is what both erasedataCollectorService()
	// and the drain tick's own collector read, and it never calls this
	// function; and plugins/rutracker_check/init.php does it INSIDE A CLOSURE
	// that returns only $garbageCheckInterval, so $enableForceDeletion never
	// escapes into global scope there -- and that plugin does not call this
	// door at all. (If that eval is ever hoisted out of its closure, this
	// paragraph stops being true before anything visibly breaks.)
	// So $enableForceDeletion is unset in every process that reaches this
	// function, !empty() answers false, and an admission-time refusal built on
	// it would refuse EVERY force-2 removal, including the ones an operator
	// switched the setting on for. Coercing force 2 down to force 1 is
	// forbidden outright.
	//
	// So the configured deletion policy stays exactly where the exact base
	// enforces it: at deletion time in collector.php, which runs under
	// update.php and does have the setting, plus the client-side check in
	// init.js. Whoever wants the refusal to move earlier has to give the doors
	// the setting first.
	//
	// Descriptor capability is a different thing entirely and is independent of
	// that setting: a force-2 multi-file admission must prove it before staging
	// or d.erase, and retain its owned handle through the destructive decision.
	// The collector later acquires a fresh capability and holds it through its
	// own traversal.
	function erasedataAdmitRemoval($hashes, $force)
	{
		erasedataAdmissionRefusalReason('admission-refused');
		$normalizedForce = ErasedataManifestCodec::normalizeForce($force);
		if(is_null($normalizedForce))
		{
			erasedataAdmissionRefusalReason('invalid-force');
			return(false);
		}
		$listPath = FileUtil::getSettingsPath()."/erasedata";
		@FileUtil::makeDirectory($listPath);
		return(erasedataRemovalAdmissionRun(array(
			'listPath' => $listPath,
			'user' => User::getUser(),
			'filesystem' => new ErasedataFilesystemOps(),
			'log' => array('FileUtil', 'toLog'),
			'ackTimeout' => ERASEDATA_DRAIN_ACK_TIMEOUT,
			'ackPoll' => ERASEDATA_DRAIN_ACK_POLL,
		), $hashes, $normalizedForce));
	}
}

if(!function_exists('erasedataRemoveWithData'))
{
	function erasedataRemoveWithData($hashes, $forceDelete)
	{
		// Invariant 13 at a PUBLIC boundary: the two exact wire spellings "1"
		// and "2" ARE accepted here and normalized exactly once, and every
		// value below this line is the integer 1 or 2.
		//
		// Keep this compatibility boundary for direct or external callers of
		// the legacy helper. The active web and CLI doors use the shared admission
		// wrapper, which also accepts exact decimal wire spellings.
		//
		// Accepting the spelling is not coercing a value. normalizeForce()
		// answers null for everything that is not exactly 1, 2, "1" or "2" --
		// true, 1.0, "01", " 1", "1\n" and a footer-injection string included --
		// and a null force is refused right here, before a single lock, path
		// collection, staged byte or RPC. The integer-only rule then holds from
		// admission inward: erasedataAdmissionPartition(), the marker codec,
		// the drain journal, the worker and the collector each refuse anything
		// that is not already the integer.
		$normalizedForce = ErasedataManifestCodec::normalizeForce($forceDelete);
		if(is_null($normalizedForce))
			return(false);
		$pending = array();
		if(!is_array($hashes))
			return(false);
		foreach($hashes as $hash)
		{
			if(!is_string($hash) || !preg_match('/^[0-9A-Fa-f]{40}$/D', $hash))
				return(false);
			$canonicalHash = strtoupper($hash);
			if(!isset($pending[$canonicalHash]))
				$pending[$canonicalHash] = $canonicalHash;
		}
		if(!count($pending))
			return(false);
		$listPath = FileUtil::getSettingsPath()."/erasedata";
		@FileUtil::makeDirectory($listPath);
		$erasable = array();
		$tmpMap = array();
		$locks = array();
		ksort($pending, SORT_STRING);
		foreach($pending as $h)
		{
			$lock = erasedataAcquireHashLock($listPath, $h);
			if($lock === false)
			{
				FileUtil::toLog("erasedata: could not lock ".$h.", torrent not erased");
				continue;
			}
			$paths = erasedataCollectPaths($h);
			if($paths === false)
			{
				// Erasing now would drop the torrent and leave its data behind
				// with nothing left to identify it, so keep the torrent.
				FileUtil::toLog("erasedata: could not determine the files of ".$h.", torrent not erased");
				erasedataReleaseHashLock($lock);
				continue;
			}
			$content = ErasedataManifestCodec::encode($h, $paths, $normalizedForce);
			if($content === false)
			{
				FileUtil::toLog("erasedata: failed to encode valid manifest for ".$h.", torrent not erased");
				erasedataReleaseHashLock($lock);
				continue;
			}
			$staged = erasedataWriteStagedManifest($listPath, $h, $content);
			if($staged === false)
			{
				FileUtil::toLog("erasedata: failed to write complete manifest for ".$h.", torrent not erased");
				erasedataReleaseHashLock($lock);
				continue;
			}
			$tmpMap[$h] = $staged['path'];
			$locks[$h] = $lock;
			$erasable[] = $h;
		}
		if(!count($erasable))
			return(false);
		// The same shared builder the drain protocol uses: three commands per
		// member in the same order, so this legacy producer cannot drift away
		// from ERASEDATA_ERASE_COMMANDS_PER_HASH either. A refused build is an
		// erase that did not run, and the per-hash fallback below then probes
		// presence and retains rather than publishing anything.
		$req = erasedataEraseRequest($erasable);
		$eraseSucceeded = ($req !== false) && $req->success();
		$published = true;
		foreach($erasable as $h)
		{
			$tmpPath = $tmpMap[$h];
			if($eraseSucceeded)
			{
				if(!ErasedataManifestCodec::publishStaging($tmpPath, $h))
					$published = false;
			}
			else
			{
				$presence = erasedataTorrentPresence($h);
				if($presence === ERASEDATA_TORRENT_PRESENT)
				{
					FileUtil::toLog("erasedata: RPC erase failed and torrent ".$h.
						" is present, staging retained for owned-path reconciliation");
				}
				elseif($presence === ERASEDATA_TORRENT_ABSENT)
				{
					if(ErasedataManifestCodec::publishStaging($tmpPath, $h))
						FileUtil::toLog("erasedata: RPC erase unconfirmed but torrent ".$h." is gone, manifest published");
					else
						$published = false;
				}
				else
					FileUtil::toLog("erasedata: RPC erase and torrent presence are unconfirmed for ".$h.", staging retained");
			}
			erasedataReleaseHashLock($locks[$h]);
		}
		return(($eraseSucceeded && $published) ? $req->val : false);
	}
}
