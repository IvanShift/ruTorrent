<?php

// Shared "remove with data" logic used by both the httprpc RPC handler and the
// direct (non-httprpc) endpoint plugins/erasedata/action.php. Records the list
// of files to delete -- read over RPC, which works on every rtorrent version --
// into the erasedata list directory for the garbage collector, then erases the
// torrents. The caller must have already loaded php/xmlrpc.php.
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

if(!function_exists('erasedataSharedFileMode'))
{
	function erasedataSharedFileMode()
	{
		global $profileMask;
		return((isset($profileMask) ? $profileMask : 0777) & 0666);
	}
}

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

if(!function_exists('erasedataPublishManifest'))
{
	function erasedataPublishManifest($tmpPath, $hash)
	{
		$listFile = substr($tmpPath, -4) === '.tmp'
			? substr($tmpPath, 0, -4).'.list' : '';
		if($listFile === '' || file_exists($listFile) || is_link($listFile) ||
			!is_file($tmpPath) || !@rename($tmpPath, $listFile))
		{
			FileUtil::toLog("erasedata: failed to publish manifest for ".$hash.", staging retained");
			return(false);
		}
		erasedataRepairFileMode($listFile);
		return(true);
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
require_once(dirname(__FILE__)."/../../php/xmlrpc_path.php");

if(!function_exists('erasedataCanonicalHash'))
{
	function erasedataCanonicalHash($hash)
	{
		return(is_string($hash) && preg_match('/^[0-9A-Fa-f]{40}$/D', $hash) ? strtoupper($hash) : false);
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
		if(preg_match('/^([0-9A-Fa-f]{40})\.cleanup\.([0-9]+)\.([A-Za-z0-9]+(?:\.[A-Za-z0-9]+)*)\.(list|tmp)$/D', $file, $matches))
			$operation = ErasedataManifestCodec::OPERATION_CLEANUP_OBSOLETE;
		// Keep the deployed remove-payload filename expression byte-for-byte.
		else if(preg_match('/^([0-9A-Fa-f]{40})(?:\.[0-9]+\.[A-Za-z0-9._-]+)?\.(list|tmp)$/D', $file, $matches))
			$operation = ErasedataManifestCodec::OPERATION_REMOVE_PAYLOAD;
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
	if(!function_exists('erasedataBeforeUnlinkExactStagedFile'))
	{
		function erasedataBeforeUnlinkExactStagedFile($path, $expectedStat)
		{
			return(true);
		}
	}

	function erasedataUnlinkExactStagedFile($path, $expectedStat, $revalidate = null)
	{
		clearstatcache(true, $path);
		$current = @lstat($path);
		if(is_link($path) || !is_array($current) || !is_array($expectedStat)
			|| !isset($current['dev'], $current['ino'], $current['mode'], $expectedStat['dev'], $expectedStat['ino'])
			|| $current['dev'] !== $expectedStat['dev'] || $current['ino'] !== $expectedStat['ino']
			|| (($current['mode'] & 0170000) !== 0100000))
			return(false);
		global $erasedataBeforeUnlinkExactStagedFileOverride;
		$allowed = is_callable($erasedataBeforeUnlinkExactStagedFileOverride)
			? call_user_func($erasedataBeforeUnlinkExactStagedFileOverride, $path, $expectedStat)
			: erasedataBeforeUnlinkExactStagedFile($path, $expectedStat);
		if(!$allowed)
			return(false);
		if(is_callable($revalidate) && !call_user_func($revalidate, $path, $expectedStat))
			return(false);
		clearstatcache(true, $path);
		$current = @lstat($path);
		if(is_link($path) || !is_array($current) || !erasedataSameStatIdentity($current, $expectedStat))
			return(false);
		return(@unlink($path));
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
		global $erasedataManifestWriteOverride;
		if(is_callable($erasedataManifestWriteOverride))
			$written = call_user_func($erasedataManifestWriteOverride, $path, $contents);
		else
		{
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
		$leftIdentity = XMLRPCPathResolver::filesystemIdentity($left);
		$rightIdentity = XMLRPCPathResolver::filesystemIdentity($right);
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
	function erasedataReadExactCleanupFile($candidate, $type, &$reason = null)
	{
		$reason = 'unreadable-manifest';
		if(!is_array($candidate) || !isset($candidate['operation'], $candidate['type'], $candidate['path'], $candidate['stat'])
			|| $candidate['operation'] !== ErasedataManifestCodec::OPERATION_CLEANUP_OBSOLETE
			|| $candidate['type'] !== $type || empty($candidate['regular']))
			return(false);
		global $erasedataBeforeReadExactCleanupFile;
		if(is_callable($erasedataBeforeReadExactCleanupFile)
			&& !call_user_func($erasedataBeforeReadExactCleanupFile, $candidate))
			return(false);
		$handle = @fopen($candidate['path'], 'rb');
		if($handle === false)
			return(false);
		$handleStat = @fstat($handle);
		clearstatcache(true, $candidate['path']);
		$pathStat = @lstat($candidate['path']);
		$bytes = @stream_get_contents($handle);
		$afterHandleStat = @fstat($handle);
		clearstatcache(true, $candidate['path']);
		$afterPathStat = @lstat($candidate['path']);
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

	function erasedataReadExactCleanupArtifact($candidate, $hash = null, &$reason = null)
	{
		$reason = 'unreadable-manifest';
		if(!is_string($hash))
			return(false);
		$read = erasedataReadExactCleanupFile($candidate, 'tmp', $reason);
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
	function erasedataReadExactCleanupToken($candidate, &$reason = null)
	{
		$reason = 'unreadable-manifest';
		$read = erasedataReadExactCleanupFile($candidate, 'list', $reason);
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
	function erasedataCleanupArtifactStillMatches($artifact, $hash = null)
	{
		if(!is_array($artifact) || !isset($artifact['candidate']))
			return(false);
		$current = erasedataReadExactCleanupArtifact($artifact['candidate'], $hash);
		if($current === false)
			return(false);
		return(!isset($artifact['manifest']) || (isset($artifact['bytes'])
			&& $current['manifest'] === $artifact['manifest'] && $current['bytes'] === $artifact['bytes']));
	}
}

if(!function_exists('erasedataCleanupTokenStillMatches'))
{
	function erasedataCleanupTokenStillMatches($token)
	{
		if(!is_array($token) || !isset($token['candidate']))
			return(false);
		return(erasedataReadExactCleanupToken($token['candidate']) !== false);
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
	function erasedataCleanupCommittedPairStillMatches($tmp, $token, $hash)
	{
		return(is_array($tmp) && is_array($token) && isset($tmp['candidate']['stem'], $token['candidate']['stem'])
			&& $tmp['candidate']['stem'] === $token['candidate']['stem']
			&& erasedataCleanupArtifactStillMatches($tmp, $hash)
			&& erasedataCleanupTokenStillMatches($token));
	}
}

if(!function_exists('erasedataCleanupPublicationPhase'))
{
	function erasedataCleanupPublicationPhase($phase, $context)
	{
		global $erasedataCleanupPublicationPhaseOverride;
		return(is_callable($erasedataCleanupPublicationPhaseOverride)
			? call_user_func($erasedataCleanupPublicationPhaseOverride, $phase, $context) : true);
	}
}

if(!function_exists('erasedataPublishExactStagedFile'))
{
	function erasedataPublishExactStagedFile($tmpPath, $expectedStat, $listPath, $expectedManifest = null)
	{
		$listDirectory = dirname($tmpPath);
		$tmpCandidate = erasedataParseCollectorCandidate($listDirectory, basename($tmpPath));
		if($tmpCandidate === false || $tmpCandidate['type'] !== 'tmp' || $tmpCandidate['path'] !== $tmpPath
			|| $listPath !== substr($tmpPath, 0, -4).'.list')
			return(false);
		$tmp = erasedataReadExactCleanupArtifact($tmpCandidate, $tmpCandidate['hash']);
		if($tmp === false || !erasedataSameStatIdentity($tmp['candidate']['stat'], $expectedStat)
			|| ($expectedManifest !== null && $tmp['manifest'] !== $expectedManifest))
			return(false);
		$context = array('tmp' => $tmpPath, 'list' => $listPath);
		$listCandidate = erasedataParseCollectorCandidate($listDirectory, basename($listPath));
		if($listCandidate !== false && is_array($listCandidate['stat']))
		{
			$token = erasedataReadExactCleanupToken($listCandidate);
			if($token === false)
				return(false);
			if(!erasedataRepairExactCleanupTokenMode($listPath, $token['candidate']['stat']))
				return(false);
			$listCandidate = erasedataParseCollectorCandidate($listDirectory, basename($listPath));
			$token = $listCandidate === false ? false : erasedataReadExactCleanupToken($listCandidate);
			return($token !== false && erasedataCleanupCommittedPairStillMatches($tmp, $token, $tmpCandidate['hash']));
		}
		if(@lstat($listPath) !== false || !erasedataCleanupPublicationPhase('before-token', $context)
			|| !erasedataCleanupArtifactStillMatches($tmp, $tmpCandidate['hash']))
			return(false);
		$handle = @fopen($listPath, 'x');
		if($handle === false)
			return(false);
		$tokenStat = @fstat($handle);
		clearstatcache(true, $listPath);
		$tokenPathStat = @lstat($listPath);
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
		$token = $listCandidate === false ? false : erasedataReadExactCleanupToken($listCandidate);
		if($token === false || !erasedataSameStatIdentity($token['candidate']['stat'], $tokenStat)
			|| !erasedataCleanupCommittedPairStillMatches($tmp, $token, $tmpCandidate['hash']))
			return(false);
		$ret = erasedataCleanupPublicationPhase('after-token', $context)
			&& erasedataCleanupCommittedPairStillMatches($tmp, $token, $tmpCandidate['hash']);
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

if(!function_exists('erasedataPublishObsoleteCleanup'))
{
	function erasedataPublishObsoleteCleanup(&$job)
	{
		try {
			$manifest = erasedataReadExactCleanupJob($job);
			if($manifest === false)
				return(false);
			$index = erasedataBuildCollectorIndex(FileUtil::getSettingsPath().'/erasedata', $job['old_hash']);
			$reason = null;
			$artifacts = erasedataCleanupGenerationArtifacts($index, $job['old_hash'], $job['new_hash'], $job['marker'],
				$job['replacement_record'], $reason);
			if($artifacts === false || $artifacts === null || $artifacts['tmp']['candidate']['path'] !== $job['tmp_path']
				|| !erasedataSameStatIdentity($artifacts['tmp']['candidate']['stat'], $job['tmp_stat']))
				return(false);
			if($artifacts['token'] !== null)
				return(erasedataCleanupCommittedPairStillMatches($artifacts['tmp'], $artifacts['token'], $job['old_hash']));
			return(erasedataPublishExactStagedFile($job['tmp_path'], $job['tmp_stat'], $job['list_path'], $manifest));
		} finally {
			erasedataReleaseObsoleteCleanupJob($job);
		}
	}
}

if(!function_exists('erasedataCancelObsoleteCleanup'))
{
	function erasedataCancelObsoleteCleanup(&$job)
	{
		try {
			if(erasedataReadExactCleanupJob($job) === false)
				return(false);
			$index = erasedataBuildCollectorIndex(FileUtil::getSettingsPath().'/erasedata', $job['old_hash']);
			return(erasedataCancelObsoleteCleanupGenerationLocked(FileUtil::getSettingsPath().'/erasedata',
				$job['old_hash'], $job['new_hash'], $job['marker'], $job['replacement_record'], $index) === ERASEDATA_CLEANUP_READY);
		} finally {
			erasedataReleaseObsoleteCleanupJob($job);
		}
	}
}

if(!function_exists('erasedataCollectorIndexBuilt'))
{
	function erasedataCollectorIndexBuilt($listPath)
	{
		return(true);
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
	function erasedataAnalyzeCleanupIndex($index)
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
					$artifact = erasedataReadExactCleanupArtifact($tmpItems[0], $hash, $artifactReason);
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
	function erasedataBuildCollectorIndex($listPath, $onlyHash = null)
	{
		$ret = array();
		if(!is_string($listPath) || !is_dir($listPath) || !($handle = @opendir($listPath)))
			return(false);
		erasedataCollectorIndexBuilt($listPath);
		while(false !== ($file = readdir($handle)))
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
		@closedir($handle);
		return(erasedataAnalyzeCleanupIndex($ret));
	}
}

if(!function_exists('erasedataCleanupGenerationArtifacts'))
{
	function erasedataCleanupGenerationArtifacts($index, $oldHash, $newHash, $marker, $replacementRecord, &$reason = null)
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
			$index = erasedataAnalyzeCleanupIndex($index);
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
		$tmp = erasedataReadExactCleanupArtifact($tmpItems[0], $oldHash, $artifactReason);
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
			$token = erasedataReadExactCleanupToken($tokenItems[0], $tokenReason);
			if($token === false || !erasedataCleanupCommittedPairStillMatches($tmp, $token, $oldHash))
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
	function erasedataRecoverObsoleteCleanupLocked($listPath, $oldHash, $newHash, $marker, $replacementRecord, &$reason = null, $index = null)
	{
		$reason = 'generation-mismatch';
		if($index === null)
			$index = erasedataBuildCollectorIndex($listPath, $oldHash);
		$artifacts = erasedataCleanupGenerationArtifacts($index, $oldHash, $newHash, $marker, $replacementRecord, $reason);
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
				&& erasedataCleanupCommittedPairStillMatches($tmp, $artifacts['token'], $oldHash))
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
		if(!erasedataPublishExactStagedFile($tmp['candidate']['path'], $tmp['candidate']['stat'], $listPathname, $tmp['manifest']))
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
	function erasedataCancelObsoleteCleanupGenerationLocked($listPath, $oldHash, $newHash, $marker, $replacementRecord, $index = null)
	{
		if($index === null)
			$index = erasedataBuildCollectorIndex($listPath, $oldHash);
		$reason = null;
		$artifacts = erasedataCleanupGenerationArtifacts($index, $oldHash, $newHash, $marker, $replacementRecord, $reason);
		if($artifacts === false)
			return(ERASEDATA_CLEANUP_RETRY);
		if($artifacts === null)
			return(ERASEDATA_CLEANUP_NONE);
		if($artifacts['token'] !== null)
			return(ERASEDATA_CLEANUP_RETRY);
		$tmp = $artifacts['tmp'];
		return(erasedataUnlinkExactStagedFile($tmp['candidate']['path'], $tmp['candidate']['stat'],
			function() use ($tmp, $oldHash) {
				$listPathname = substr($tmp['candidate']['path'], 0, -4).'.list';
				return(erasedataCleanupArtifactStillMatches($tmp, $oldHash) && @lstat($listPathname) === false);
			}) ? ERASEDATA_CLEANUP_READY : ERASEDATA_CLEANUP_RETRY);
	}
}

if(!function_exists('erasedataRecoverObsoleteCleanup'))
{
	function erasedataRecoverObsoleteCleanup($oldHash, $newHash, $marker, $replacementRecord, &$reason = null)
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
			$index = erasedataBuildCollectorIndex($listPath, $oldHash);
			return(erasedataRecoverObsoleteCleanupLocked($listPath, $oldHash, $newHash, $marker, $replacementRecord, $reason, $index));
		}
		finally { erasedataReleaseHashLock($lock); }
	}
}

if(!function_exists('erasedataCancelObsoleteCleanupGeneration'))
{
	function erasedataCancelObsoleteCleanupGeneration($oldHash, $newHash, $marker, $replacementRecord)
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
			$index = erasedataBuildCollectorIndex($listPath, $oldHash);
			return(erasedataCancelObsoleteCleanupGenerationLocked($listPath, $oldHash, $newHash, $marker, $replacementRecord, $index));
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

if(!function_exists('erasedataRemoveWithData'))
{
	function erasedataRemoveWithData($hashes, $forceDelete)
	{
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
		$destructiveForce = ErasedataManifestCodec::normalizeForce($forceDelete);
		if($destructiveForce !== $normalizedForce)
		{
			foreach($erasable as $h)
			{
				if(is_file($tmpMap[$h]))
					@unlink($tmpMap[$h]);
				erasedataReleaseHashLock($locks[$h]);
			}
			return(false);
		}
		$req = new rXMLRPCRequest();
		foreach($erasable as $h)
		{
			$req->addCommand( new rXMLRPCCommand( getCmd("d.set_custom5"), array($h, "") ) );
			$req->addCommand( new rXMLRPCCommand( getCmd("d.delete_tied"), $h ) );
			$req->addCommand( new rXMLRPCCommand( getCmd("d.erase"), $h ) );
		}
		$eraseSucceeded = $req->success();
		$published = true;
		foreach($erasable as $h)
		{
			$tmpPath = $tmpMap[$h];
			if($eraseSucceeded)
			{
				if(!erasedataPublishManifest($tmpPath, $h))
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
					if(erasedataPublishManifest($tmpPath, $h))
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
