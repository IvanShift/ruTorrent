<?php

// Importable erasedata collector service. Requiring this file only defines
// symbols: it performs no RPC, no filesystem mutation, no scheduler lock and no
// log line. plugins/erasedata/update.php owns the runtime entry point.
require_once(dirname(__FILE__)."/filesystem.php");
require_once(dirname(__FILE__)."/manifest.php");
require_once(dirname(__FILE__)."/removewithdata.php");

if(!function_exists('eLog'))
{
	function eLog( $str )
	{
		global $erasedebug_enabled;
		if($erasedebug_enabled)
			FileUtil::toLog( "erasedata: ".$str );
	}
}
function sortByLevel( $a, $b )
{
	return( strrpos($b,"/")-strrpos($a,"/") );
}

function erasedataSamePathIdentity($expected, $current)
{
	return(erasedataSameFilesystemEntry($expected, $current)
		&& $expected['path'] === $current['path']);
}

function erasedataDirectoryReservationPrefix($path, $reservationKey)
{
	return(dirname($path).'/.erasedata-rmdir-'.hash('sha256', $reservationKey."\0".$path).'-');
}

function erasedataDirectoryReservationPath($path, $reservationKey, $identity)
{
	$token = erasedataPrivateToken();
	if($token === false)
		return(false);
	return(erasedataDirectoryReservationPrefix($path, $reservationKey)
		.$identity['lstat']['dev'].'-'.$identity['lstat']['ino'].'-'.$token);
}

function erasedataReservationDataPath($reservation)
{
	return($reservation.'/directory');
}

function erasedataDirectoryReservations($path, $reservationKey,
	ErasedataFilesystemOps $filesystem)
{
	$directory = dirname($path);
	if(!is_dir($directory))
		return(array());
	$entries = $filesystem->scanDirectory($directory);
	if($entries === false)
		return(false);
	$prefix = basename(erasedataDirectoryReservationPrefix($path, $reservationKey));
	$ret = array();
	foreach($entries as $entry)
		if(strpos($entry, $prefix) === 0
			&& preg_match('/^[0-9]+-[0-9]+-[a-f0-9]{32}$/D',
				substr($entry, strlen($prefix))))
			$ret[] = $directory.'/'.$entry;
	return($ret);
}

function erasedataReservationEncodedIdentity($reserved, $path, $reservationKey)
{
	$prefix = erasedataDirectoryReservationPrefix($path, $reservationKey);
	if(strpos($reserved, $prefix) !== 0)
		return(false);
	$suffix = substr($reserved, strlen($prefix));
	if(!preg_match('/^([0-9]+)-([0-9]+)-[a-f0-9]{32}$/D', $suffix, $matches)
		|| is_link($reserved) || !is_dir($reserved))
		return(false);
	return(array('device'=>$matches[1], 'inode'=>$matches[2]));
}

function erasedataReservationHasEncodedIdentity($reserved, $path, $reservationKey)
{
	$encoded = erasedataReservationEncodedIdentity(
		$reserved, $path, $reservationKey);
	if($encoded === false || !erasedataPrivateMarkerIsValid($reserved))
		return(false);
	$data = erasedataReservationDataPath($reserved);
	$identity = erasedataPathIdentity($data);
	return(is_array($identity) && !empty($identity['exists']) && !is_link($data)
		&& is_dir($data)
		&& (string)$identity['lstat']['dev'] === $encoded['device']
		&& (string)$identity['lstat']['ino'] === $encoded['inode']
		&& $identity['lstat']['dev'] === $identity['stat']['dev']
		&& $identity['lstat']['ino'] === $identity['stat']['ino']);
}

// The reservation shell of one checked directory. Only the encoded identity is
// verified here; the allowlist, the marker and the removal itself belong to the
// container owner.
function erasedataRemoveReservationContainer($reserved, $path, $reservationKey,
	ErasedataFilesystemOps $filesystem)
{
	if(erasedataReservationEncodedIdentity($reserved, $path, $reservationKey) === false)
		return(false);
	return(!erasedataPathExists($reserved)
		|| $filesystem->removePrivateContainer($reserved, array('.', '..',
			basename(erasedataPrivateMarkerPath($reserved)))));
}

// The shell survives a PHP process crash, so a missing private directory needs
// an operation phase. An inode number cannot distinguish a restored parent from
// a new directory after rmdir. These flags do not claim power-loss durability:
// PHP 7.4 has no portable directory fsync for their new directory entries.
function erasedataCleanupPhasePath($reservation, $phase)
{
	return($reservation.'/.cleanup-'.$phase.'-intent');
}

function erasedataCleanupPhaseState($reservation)
{
	$present = array();
	foreach(array('delete', 'restore') as $phase)
	{
		$path = erasedataCleanupPhasePath($reservation, $phase);
		if(!erasedataPathExists($path)) continue;
		clearstatcache(true, $path);
		$stat = @lstat($path);
		if(!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
			|| $stat['size'] !== 0)
			return(false);
		$present[$phase] = true;
	}
	// A failed rmdir can be followed by restore. Its later intent wins.
	if(isset($present['restore'])) return('restore');
	if(isset($present['delete'])) return('delete');
	return(null);
}

function erasedataCleanupMarkPhase($reservation, $phase)
{
	if($phase !== 'delete' && $phase !== 'restore') return(false);
	$path = erasedataCleanupPhasePath($reservation, $phase);
	if(erasedataPathExists($path))
		return(erasedataCleanupPhaseState($reservation) !== false);
	$handle = @fopen($path, 'x');
	if($handle === false) return(false);
	$closed = @fclose($handle);
	@chmod($path, 0600);
	return($closed && erasedataCleanupPhaseState($reservation) !== false);
}

function erasedataCleanupRemoveReservationContainer($reservation, $path,
	$reservationKey, ErasedataFilesystemOps $filesystem)
{
	if(erasedataCleanupPhaseState($reservation) === false) return(false);
	// Delete first: a crash with only restore-intent remaining stays safe.
	foreach(array('delete', 'restore') as $phase)
	{
		$flag = erasedataCleanupPhasePath($reservation, $phase);
		if(erasedataPathExists($flag)
			&& (!$filesystem->unlink($flag) || erasedataPathExists($flag)))
			return(false);
	}
	return(erasedataRemoveReservationContainer(
		$reservation, $path, $reservationKey, $filesystem));
}

function erasedataReservationLinkMatches($path, $reserved,
	ErasedataFilesystemOps $filesystem)
{
	return(is_link($path) && $filesystem->readLink($path) === $reserved);
}

function erasedataPublishReservationLink($reserved, $path,
	ErasedataFilesystemOps $filesystem)
{
	if(erasedataReservationLinkMatches($path, $reserved, $filesystem))
		return(true);
	if(erasedataPathExists($path))
		return(false);
	// symlink() is the portable PHP 7.4 filesystem primitive that creates the
	// original name only when it is still absent. Unlike rename(), it cannot
	// replace a concurrently created empty directory.
	if(!$filesystem->makeSymlink($reserved, $path))
		return(false);
	return(erasedataReservationLinkMatches($path, $reserved, $filesystem));
}

function erasedataDropReservationLink($path, $reserved, $reservationKey,
	ErasedataFilesystemOps $filesystem)
{
	if(!erasedataReservationLinkMatches($path, $reserved, $filesystem))
		return(!erasedataPathExists($path));
	return(erasedataUnlinkRecoveryLink(
		$path, $reserved, $reservationKey, $filesystem));
}

function erasedataRecoveryCapturePrefix($target)
{
	return($target.'.force-');
}

function erasedataRecoveryCaptureRoots($target, ErasedataFilesystemOps $filesystem)
{
	$directory = dirname($target);
	if(!is_dir($directory))
		return(array());
	$entries = $filesystem->scanDirectory($directory);
	if($entries === false)
		return(false);
	$prefix = basename(erasedataRecoveryCapturePrefix($target));
	$ret = array();
	foreach($entries as $entry)
		if(strpos($entry, $prefix) === 0
			&& preg_match('/^[a-f0-9]{32}$/D', substr($entry, strlen($prefix))))
			$ret[] = $directory.'/'.$entry;
	return($ret);
}

function erasedataNewRecoveryCaptureRoot($target)
{
	$token = erasedataPrivateToken();
	return($token === false ? false : erasedataRecoveryCapturePrefix($target).$token);
}

function erasedataRecoveryLinkLayout($path, ErasedataFilesystemOps $filesystem)
{
	if(!is_link($path))
		return(false);
	$target = $filesystem->readLink($path);
	if(!is_string($target) || $target === '' || $target[0] !== '/'
		|| (dirname($target) !== dirname($path)
			&& dirname(dirname($target)) !== dirname($path)))
		return(false);
	$reservationRoot = null;
	$reservationName = basename($target);
	if($reservationName === 'directory')
	{
		$reservationRoot = dirname($target);
		$reservationName = basename($reservationRoot);
	}
	if(!preg_match('/^\.erasedata-rmdir-[a-f0-9]{64}-([0-9]+)-([0-9]+)-[a-f0-9]{32}$/D',
		$reservationName, $matches))
		return(false);
	return(array(
		'target' => $target,
		'reservationRoot' => $reservationRoot,
		'dev' => $matches[1],
		'ino' => $matches[2],
	));
}

function erasedataRecoveryLinkTarget($path, ErasedataFilesystemOps $filesystem)
{
	$linkLayout = erasedataRecoveryLinkLayout($path, $filesystem);
	if($linkLayout === false)
		return(false);
	$target = $linkLayout['target'];
	$reservationRoot = $linkLayout['reservationRoot'];
	if(!is_null($reservationRoot) && erasedataPathExists($reservationRoot))
	{
		if(is_link($reservationRoot) || !is_dir($reservationRoot))
			return(array('safe'=>false, 'linkTarget'=>$target));
		$reservationEntries = $filesystem->scanDirectory($reservationRoot);
		if(!erasedataPrivateMarkerIsValid($reservationRoot))
		{
			if($reservationEntries === false || count(array_diff(
				$reservationEntries, array('.', '..'))) > 0)
				return(array('safe'=>false, 'linkTarget'=>$target));
			if(!$filesystem->removePrivateContainer(
				$reservationRoot, array('.', '..')))
				return(array('safe'=>false, 'linkTarget'=>$target));
		}
	}
	$layout = array('reservationRoot'=>$reservationRoot);
	$captureRoots = erasedataRecoveryCaptureRoots($target, $filesystem);
	if($captureRoots === false || count($captureRoots) > 1)
		return(array('safe'=>false, 'linkTarget'=>$target) + $layout);
	$captureRoot = count($captureRoots) === 1
		? $captureRoots[0] : erasedataNewRecoveryCaptureRoot($target);
	if($captureRoot === false)
		return(array('safe'=>false, 'linkTarget'=>$target) + $layout);
	if(count($captureRoots) === 1)
	{
		$captureEntries = $filesystem->scanDirectory($captureRoot);
		if(!erasedataPrivateMarkerIsValid($captureRoot))
		{
			if($captureEntries === false || count(array_diff(
				$captureEntries, array('.', '..'))) > 0)
				return(array('safe'=>false, 'linkTarget'=>$target) + $layout);
			if(!$filesystem->removePrivateContainer(
				$captureRoot, array('.', '..')))
				return(array('safe'=>false, 'linkTarget'=>$target) + $layout);
			$captureRoots = array();
			$captureRoot = erasedataNewRecoveryCaptureRoot($target);
			if($captureRoot === false)
				return(array('safe'=>false, 'linkTarget'=>$target) + $layout);
		}
	}
	if(!is_null($reservationRoot) && erasedataPathExists($reservationRoot))
	{
		$reservationEntries = $filesystem->scanDirectory($reservationRoot);
		$allowed = array('.', '..', 'directory',
			basename(erasedataPrivateMarkerPath($reservationRoot)));
		if(count($captureRoots) === 1)
			$allowed[] = basename($captureRoot);
		if($reservationEntries === false
			|| count(array_diff($reservationEntries, $allowed)) > 0)
			return(array('safe'=>false, 'linkTarget'=>$target) + $layout);
	}
	$capture = $captureRoot.'/directory';
	$deleted = $captureRoot.'/deleted';
	$targetExists = erasedataPathExists($target);
	$rootExists = erasedataPathExists($captureRoot);
	if($rootExists)
	{
		if(is_link($captureRoot) || !is_dir($captureRoot)
			|| !erasedataPrivateMarkerIsValid($captureRoot))
			return(array('safe'=>false, 'linkTarget'=>$target) + $layout);
		$entries = $filesystem->scanDirectory($captureRoot);
		if($entries === false || count(array_diff(
			$entries, array('.', '..', 'directory',
				basename(erasedataPrivateMarkerPath($captureRoot))))) > 0)
			return(array('safe'=>false, 'linkTarget'=>$target) + $layout);
	}
	$captureExists = $rootExists && erasedataPathExists($capture);
	$captured = $rootExists && !$targetExists;
	$candidate = $target;

	if($targetExists && is_link($target))
	{
		if($filesystem->readLink($target) !== $capture)
			return(array('safe'=>false, 'linkTarget'=>$target) + $layout);
		$captured = true;
		$candidate = $capture;
		$targetExists = false;
	}
	if($captured || !$targetExists)
	{
		if($captureExists && is_link($capture)
			&& $filesystem->readLink($capture) === $deleted)
			return(array('safe'=>true, 'exists'=>false, 'captured'=>true,
				'linkTarget'=>$target, 'path'=>$capture, 'deleted'=>$deleted,
				'captureRoot'=>$captureRoot) + $layout);
		if(!$captureExists)
			return(array('safe'=>true, 'exists'=>false, 'captured'=>$captured,
				'linkTarget'=>$target, 'path'=>$candidate, 'deleted'=>$deleted,
				'captureRoot'=>$captureRoot) + $layout);
		$captured = true;
		$candidate = $capture;
	}
	else if($captureExists)
		return(array('safe'=>false, 'linkTarget'=>$target) + $layout);

	$identity = $filesystem->pathIdentity($candidate);
	if(!is_array($identity) || empty($identity['exists']) || is_link($candidate)
		|| !is_dir($candidate)
		|| (string)$identity['lstat']['dev'] !== $linkLayout['dev']
		|| (string)$identity['lstat']['ino'] !== $linkLayout['ino']
		|| $identity['lstat']['dev'] !== $identity['stat']['dev']
		|| $identity['lstat']['ino'] !== $identity['stat']['ino'])
		return(array('safe'=>false, 'linkTarget'=>$target) + $layout);
	return(array('safe'=>true, 'exists'=>true, 'captured'=>$captured,
		'linkTarget'=>$target, 'path'=>$candidate, 'identity'=>$identity,
		'deleted'=>$deleted, 'captureRoot'=>$captureRoot) + $layout);
}

function erasedataOpenDirectoryReference($path, $expectedIdentity, ErasedataFilesystemOps $filesystem)
{
	return($filesystem->openDirectoryReference($path, $expectedIdentity));
}

function erasedataCloseDirectoryReference($reference, ErasedataFilesystemOps $filesystem)
{
	$filesystem->closeDirectoryReference($reference);
}

function erasedataUnlinkRecoveryLink($path, $target, $reservationKey,
	ErasedataFilesystemOps $filesystem)
{
	$expected = $filesystem->entryIdentity($path);
	if(!is_array($expected))
		return(!erasedataPathExists($path));
	if(empty($expected['is_link']) || $filesystem->readLink($path) !== $target)
		return(false);
	return($filesystem->unlinkCapturedEntry(
		$path, $expected, $reservationKey));
}

function erasedataCompleteRecoveryLink($path, $reservationKey,
	ErasedataFilesystemOps $filesystem)
{
	$linkLayout = erasedataRecoveryLinkLayout($path, $filesystem);
	if(is_array($linkLayout))
	{
		$roots = erasedataCapturedEntryRoots(
			$linkLayout['target'], $reservationKey, $filesystem);
		if($roots === false || count($roots) > 1)
			return(false);
		if(count($roots) === 1 && !$filesystem->unlinkCapturedEntry(
			$linkLayout['target'], null, $reservationKey, true))
			return(false);
	}
	$recovery = erasedataRecoveryLinkTarget($path, $filesystem);
	if($recovery === false)
		return(null);
	if(empty($recovery['safe']) || !empty($recovery['captured']))
		return(false);
	$target = $recovery['linkTarget'];
	if(empty($recovery['exists']))
		return(erasedataUnlinkRecoveryLink(
			$path, $target, $reservationKey, $filesystem));
	$reference = erasedataOpenDirectoryReference($target, $recovery['identity'], $filesystem);
	if($reference === false)
		return(false);
	$entries = $filesystem->scanDirectory($reference['path']);
	if($entries === false)
	{
		erasedataCloseDirectoryReference($reference, $filesystem);
		return(false);
	}
	if(count(array_diff($entries, array('.', '..'))) > 0)
	{
		erasedataCloseDirectoryReference($reference, $filesystem);
		return(true);
	}
	erasedataCloseDirectoryReference($reference, $filesystem);
	$capturedIdentity = array(
		'dev' => $recovery['identity']['lstat']['dev'],
		'ino' => $recovery['identity']['lstat']['ino'],
		'mode' => 0040000,
	);
	if(!$filesystem->unlinkCapturedEntry(
		$target, $capturedIdentity, $reservationKey, true))
		return(false);
	return(erasedataUnlinkRecoveryLink(
		$path, $target, $reservationKey, $filesystem));
}

function erasedataCaptureRecoveryDirectory($recovery, ErasedataFilesystemOps $filesystem)
{
	if(!empty($recovery['captured']))
		return($recovery);
	$target = $recovery['linkTarget'];
	$captureRoot = $recovery['captureRoot'];
	$capture = $captureRoot.'/directory';
	if(!erasedataPathExists($captureRoot))
	{
		if(!$filesystem->makeDirectory($captureRoot, 0700))
			return(false);
		if(!erasedataCreatePrivateMarker($captureRoot))
		{
			$filesystem->removeDirectory($captureRoot);
			return(false);
		}
	}
	if(erasedataPathExists($capture)
		|| !$filesystem->rename($target, $capture))
		return(false);
	// The rename captures one directory name atomically; only the inode that was
	// validated through the recovery link may cross into recursive deletion.
	$current = $filesystem->pathIdentity($capture);
	if(!erasedataSameFilesystemEntry($recovery['identity'], $current))
	{
		if(!erasedataPathExists($target))
			$filesystem->makeSymlink($capture, $target);
		return(false);
	}
	$recovery['captured'] = true;
	$recovery['path'] = $capture;
	return($recovery);
}

function erasedataRemoveExactLinkOrAbsent($path, $target, $reservationKey,
	ErasedataFilesystemOps $filesystem)
{
	if(!erasedataPathExists($path))
		return(true);
	if(!is_link($path) || $filesystem->readLink($path) !== $target)
		return(false);
	return(erasedataUnlinkRecoveryLink(
		$path, $target, $reservationKey, $filesystem));
}

// Both private shells of one recovery layout, capture root first, removed
// through the single container owner. A shell that is already gone is a
// completed removal; a layout without a reservation root has nothing to remove.
function erasedataRemoveRecoveryContainers($recovery,
	ErasedataFilesystemOps $filesystem)
{
	$roots = array($recovery['captureRoot']);
	if(!empty($recovery['reservationRoot']))
		$roots[] = $recovery['reservationRoot'];
	foreach($roots as $root)
		if(erasedataPathExists($root)
			&& !$filesystem->removePrivateContainer($root, array('.', '..',
				basename(erasedataPrivateMarkerPath($root)))))
			return(false);
	return(true);
}

function erasedataDeleteRecoveryDirectory($path, $recovery, $reservationKey,
	ErasedataFilesystemOps $filesystem)
{
	if(empty($recovery['safe']))
		return(false);
	$target = $recovery['linkTarget'];
	if(empty($recovery['exists']))
	{
		if(!erasedataRemoveExactLinkOrAbsent(
			$recovery['path'], $recovery['deleted'], $reservationKey, $filesystem))
			return(false);
		if(erasedataPathExists($recovery['path'])
			|| !erasedataRemoveExactLinkOrAbsent(
				$target, $recovery['path'], $reservationKey, $filesystem))
			return(false);
		if(erasedataPathExists($target))
			return(false);
		if(!erasedataRemoveRecoveryContainers($recovery, $filesystem))
			return(false);
		return(erasedataUnlinkRecoveryLink(
			$path, $target, $reservationKey, $filesystem));
	}

	$captured = erasedataCaptureRecoveryDirectory($recovery, $filesystem);
	if($captured === false)
		return(false);
	$capture = $captured['path'];
	if(!erasedataReservationLinkMatches($target, $capture, $filesystem))
	{
		if(erasedataPathExists($target) || !$filesystem->makeSymlink($capture, $target))
			return(false);
	}
	$reference = erasedataOpenDirectoryReference($capture, $captured['identity'], $filesystem);
	if($reference === false)
		return(false);
	$deleted = erasedataDeleteDirectoryReferenceContents(
		$reference, $reservationKey, $filesystem);
	erasedataCloseDirectoryReference($reference, $filesystem);
	$current = $filesystem->pathIdentity($capture);
	if(!$deleted || !erasedataSameFilesystemEntry($captured['identity'], $current)
		|| !$filesystem->removeDirectory($capture) || erasedataPathExists($capture))
		return(false);
	// Occupy the just-deleted name before unlinking the visible chain. A
	// concurrent recreation therefore keeps the chain and manifest intact.
	if(!$filesystem->makeSymlink($captured['deleted'], $capture))
		return(false);

	if(!erasedataRemoveExactLinkOrAbsent(
		$capture, $captured['deleted'], $reservationKey, $filesystem))
		return(false);
	if(erasedataPathExists($capture)
		|| !erasedataRemoveExactLinkOrAbsent(
			$target, $capture, $reservationKey, $filesystem))
		return(false);
	if(erasedataPathExists($target))
		return(false);
	if(!erasedataRemoveRecoveryContainers($captured, $filesystem))
		return(false);
	return(erasedataUnlinkRecoveryLink(
		$path, $target, $reservationKey, $filesystem));
}

function erasedataRecoverNonForceDirectory($path, $reservationKey, $reservations,
	ErasedataFilesystemOps $filesystem)
{
	if(count($reservations) !== 1)
		return(false);
	$reservation = $reservations[0];
	$reserved = erasedataReservationDataPath($reservation);
	$restoredLink = erasedataReservationLinkMatches($path, $reserved, $filesystem);
	if(!erasedataPathExists($reserved))
	{
		$entries = $filesystem->scanDirectory($reservation);
		$marker = erasedataPrivateMarkerPath($reservation);
		// Both mkdir-before-marker and marker-before-rename crashes contain no
		// data entry. Cleanup is limited to the empty private protocol shell.
		if($entries === false || count(array_diff(
			$entries, array('.', '..', basename($marker)))) > 0
			|| !erasedataRemoveReservationContainer(
				$reservation, $path, $reservationKey, $filesystem))
			return(false);
		if($restoredLink)
			return(erasedataUnlinkRecoveryLink(
				$path, $reserved, $reservationKey, $filesystem));
		if(erasedataPathExists($path))
			return(erasedataCompleteNonForceDirectory($path, $reservationKey, $filesystem));
		return(true);
	}
	if(erasedataPathExists($path) && !$restoredLink)
		return(false);
	if(!erasedataReservationHasEncodedIdentity($reservation, $path, $reservationKey))
		return(false);
	$entries = $filesystem->scanDirectory($reserved);
	if($entries === false)
	{
		if(!$restoredLink)
			erasedataPublishReservationLink($reserved, $path, $filesystem);
		return(false);
	}
	if(count(array_diff($entries, array('.', '..'))) > 0)
		return($restoredLink || erasedataPublishReservationLink($reserved, $path, $filesystem));

	if(!erasedataReservationHasEncodedIdentity($reservation, $path, $reservationKey))
		return(false);
	if($restoredLink
		&& !erasedataDropReservationLink(
			$path, $reserved, $reservationKey, $filesystem))
		return(false);
	$removed = $filesystem->removeDirectory($reserved);
	if($removed || !erasedataPathExists($reserved))
		return(erasedataRemoveReservationContainer(
			$reservation, $path, $reservationKey, $filesystem));
	if(!erasedataReservationLinkMatches($path, $reserved, $filesystem))
		erasedataPublishReservationLink($reserved, $path, $filesystem);
	return(false);
}

function erasedataCompleteNonForceDirectory($path, $reservationKey,
	ErasedataFilesystemOps $filesystem)
{
	$reservations = erasedataDirectoryReservations($path, $reservationKey, $filesystem);
	if($reservations === false)
		return(false);
	if(count($reservations) > 0)
		return(erasedataRecoverNonForceDirectory(
			$path, $reservationKey, $reservations, $filesystem));
	if(!erasedataPathExists($path))
		return(true);
	$recoveryComplete = erasedataCompleteRecoveryLink(
		$path, $reservationKey, $filesystem);
	if(!is_null($recoveryComplete))
		return($recoveryComplete);
	// A directory alias is not the directory entry the manifest can remove.
	// Listed children are handled separately; leave the symlink itself alone.
	if(is_link($path))
		return(true);
	$expected = $filesystem->pathIdentity($path);
	if($expected === false || empty($expected['exists']) || !is_dir($path))
		return(false);
	$entries = $filesystem->scanDirectory($path);
	if($entries === false)
		return(false);
	if(count(array_diff($entries, array('.', '..'))) > 0)
		return(true);

	$current = $filesystem->pathIdentity($path);
	if(!erasedataSamePathIdentity($expected, $current))
		return(false);

	// Rename atomically captures one checked directory entry. A concurrent
	// replacement at the original name is never passed to rmdir().
	$reservation = erasedataDirectoryReservationPath($path, $reservationKey, $expected);
	if($reservation === false || !$filesystem->makeDirectory($reservation, 0700))
		return(false);
	if(!erasedataCreatePrivateMarker($reservation))
	{
		$filesystem->removeDirectory($reservation);
		return(false);
	}
	$reserved = erasedataReservationDataPath($reservation);
	if(!$filesystem->rename($path, $reserved))
	{
		$filesystem->unlink(erasedataPrivateMarkerPath($reservation));
		$filesystem->removeDirectory($reservation);
		return(false);
	}
	$reservedIdentity = $filesystem->pathIdentity($reserved);
	if(!erasedataSameFilesystemEntry($expected, $reservedIdentity))
	{
		// Publish only through the no-replace link helper. If another directory
		// owns the original name, both objects and the manifest remain untouched.
		erasedataPublishReservationLink($reserved, $path, $filesystem);
		return(false);
	}
	$removed = $filesystem->removeDirectory($reserved);
	if($removed || !erasedataPathExists($reserved))
		return(erasedataRemoveReservationContainer(
			$reservation, $path, $reservationKey, $filesystem));

	// Restore a failed removal to the manifest's exact path. A nonempty
	// directory contains unrelated data and completes only after restoration;
	// an empty or ambiguous directory remains a retry obligation.
	$after = $filesystem->scanDirectory($reserved);
	$hasUnrelated = is_array($after)
		&& count(array_diff($after, array('.', '..'))) > 0;
	if(!erasedataPublishReservationLink($reserved, $path, $filesystem))
		return(false);
	return($hasUnrelated);
}

function erasedataCompleteForcedDirectory($path, $reservationKey,
	ErasedataFilesystemOps $filesystem)
{
	$reservations = erasedataDirectoryReservations(
		$path, $reservationKey, $filesystem);
	if($reservations === false || count($reservations) > 1)
		return(false);
	if(count($reservations) === 1)
	{
		$reservation = $reservations[0];
		$reserved = erasedataReservationDataPath($reservation);
		$restoredLink = erasedataReservationLinkMatches($path, $reserved, $filesystem);
		if(!erasedataPathExists($reserved))
		{
			if(!erasedataRemoveReservationContainer(
				$reservation, $path, $reservationKey, $filesystem))
				return(false);
			if($restoredLink
				&& !erasedataUnlinkRecoveryLink(
					$path, $reserved, $reservationKey, $filesystem))
				return(false);
			return(erasedataPathExists($path)
				? erasedataCompleteForcedDirectory(
					$path, $reservationKey, $filesystem)
				: true);
		}
		if(!erasedataReservationHasEncodedIdentity(
			$reservation, $path, $reservationKey))
			return(false);
		if(erasedataPathExists($path) && !$restoredLink)
			return(false);
		if(!$restoredLink && !erasedataPublishReservationLink($reserved, $path, $filesystem))
			return(false);
		$recovery = erasedataRecoveryLinkTarget($path, $filesystem);
		return($recovery !== false && erasedataDeleteRecoveryDirectory(
			$path, $recovery, $reservationKey, $filesystem));
	}
	if(!erasedataPathExists($path))
		return(true);
	if(is_link($path))
	{
		$recovery = erasedataRecoveryLinkTarget($path, $filesystem);
		if($recovery !== false)
			return(erasedataDeleteRecoveryDirectory(
				$path, $recovery, $reservationKey, $filesystem));
		$expected = $filesystem->entryIdentity($path);
		return($filesystem->unlinkCapturedEntry(
			$path, $expected, $reservationKey));
	}
	$expected = $filesystem->pathIdentity($path);
	if($expected === false || empty($expected['exists']) || !is_dir($path))
		return(false);

	$recovery = erasedataRecoveryLinkTarget($path, $filesystem);
	if($recovery !== false)
		return(erasedataDeleteRecoveryDirectory(
			$path, $recovery, $reservationKey, $filesystem));

	$reservation = erasedataDirectoryReservationPath($path, $reservationKey, $expected);
	if($reservation === false || !$filesystem->makeDirectory($reservation, 0700))
		return(false);
	if(!erasedataCreatePrivateMarker($reservation))
	{
		$filesystem->removeDirectory($reservation);
		return(false);
	}
	$reserved = erasedataReservationDataPath($reservation);
	if(!$filesystem->rename($path, $reserved))
	{
		$filesystem->unlink(erasedataPrivateMarkerPath($reservation));
		$filesystem->removeDirectory($reservation);
		return(false);
	}
	$reservedIdentity = $filesystem->pathIdentity($reserved);
	if(!erasedataSameFilesystemEntry($expected, $reservedIdentity))
	{
		erasedataPublishReservationLink($reserved, $path, $filesystem);
		return(false);
	}
	if(!erasedataPublishReservationLink($reserved, $path, $filesystem))
		return(false);

	$recovery = erasedataRecoveryLinkTarget($path, $filesystem);
	if($recovery === false)
		return(false);
	return(erasedataDeleteRecoveryDirectory(
		$path, $recovery, $reservationKey, $filesystem));
}

function erasedataReadCleanupManifest($path, $expectedStat, $hash, &$artifact = null,
	?ErasedataFilesystemOps $filesystem = null)
{
	$artifact = null;
	$candidate = erasedataParseCollectorCandidate(dirname($path), basename($path));
	if($candidate === false || $candidate['operation'] !== ErasedataManifestCodec::OPERATION_CLEANUP_OBSOLETE
		|| $candidate['type'] !== 'tmp' || $candidate['path'] !== $path
		|| !is_array($expectedStat) || !erasedataSameStatIdentity($candidate['stat'], $expectedStat))
		return(false);
	$reason = null;
	$read = erasedataReadExactCleanupArtifact($candidate, $hash, $reason, $filesystem);
	if($read === false || !erasedataSameStatIdentity($read['candidate']['stat'], $expectedStat))
		return(false);
	$artifact = $read;
	return($read['manifest']);
}

// The identity of one cleanup artifact, plus the size and mtime the exact
// cleanup protocol compares on top of it.
//
// A name that is ABSENT is an ordinary answer here, not an uncertainty: the
// exact protocol's whole job is to record "this name was absent, twice, with
// nothing changing in between", and rTorrent names files that do not exist yet
// all the time. erasedataPathIdentity() answers false for a missing name whose
// canonical location it cannot PROVE -- one below a regular file, a dangling
// link or a directory this uid cannot search -- so this falls back, for a name
// it has itself just proven absent, to an observation carrying the name AS
// GIVEN.
//
// That fallback is sound HERE and nowhere else, and the reason is what the
// value is used for. This 'path' is only ever compared for EQUALITY against
// another observation of the SAME input name
// (erasedataCleanupSuccessorObservationMatches, erasedataCleanupIdentityMatches
// against a manifest's recorded canonical name, which is only reached when the
// name exists). It is never compared for CONTAINMENT. Containment is
// erasedataPathsOverlap()'s question -- the one that authorises a deletion --
// and that reads erasedataPathIdentity() directly and stays fail closed on
// exactly the names this falls back for.
function erasedataCleanupCurrentIdentity($path)
{
	$identity = erasedataPathIdentity($path);
	if($identity === false)
	{
		if(!is_string($path) || $path === '' || $path[0] !== '/'
			|| strpos($path, "\0") !== false)
			return(false);
		clearstatcache(true, $path);
		// Only a name proven absent. Anything that is THERE and could not be
		// identified stays an uncertainty and fails closed.
		if(file_exists($path) || is_link($path))
			return(false);
		return(array('exists' => false, 'path' => $path,
			'lstat' => null, 'stat' => null));
	}
	if(empty($identity['exists']))
		return($identity);
	$lstat = @lstat($path);
	$stat = @stat($path);
	if(!is_array($lstat) || !is_array($stat))
		return(false);
	$identity['size'] = $stat['size'];
	$identity['mtime'] = $stat['mtime'];
	return($identity);
}

function erasedataCleanupExactIdentityKey($identity)
{
	if(!is_array($identity) || empty($identity['exists'])
		|| !isset($identity['stat']['dev'], $identity['stat']['ino']))
		return(false);
	return('i:'.$identity['stat']['dev'].':'.$identity['stat']['ino']);
}

function erasedataCleanupSuccessorObservation($path,
	ErasedataFilesystemOps $filesystem)
{
	// One observation is two independent reads of the same name. The first goes
	// through the injectable seam so it also covers a name that is still absent.
	$entry = $filesystem->entryIdentity($path);
	$target = $filesystem->targetIdentity($path);
	$identity = erasedataCleanupCurrentIdentity($path);
	if($identity === false || empty($identity['exists']))
		return($identity);
	clearstatcache(true, $path);
	$lstat = $entry === false ? false : $entry['lstat'];
	$stat = $target === false ? false : $target['stat'];
	if(!is_file($path) || !is_array($lstat) || !is_array($stat)
		|| !isset($lstat['mode'], $stat['mode'], $lstat['dev'], $lstat['ino'], $stat['dev'], $stat['ino'])
		|| !isset($identity['lstat']['dev'], $identity['lstat']['ino'],
			$identity['stat']['dev'], $identity['stat']['ino'])
		|| (($lstat['mode'] & 0170000) !== 0100000 && ($lstat['mode'] & 0170000) !== 0120000)
		|| (($stat['mode'] & 0170000) !== 0100000)
		|| $identity['lstat']['dev'] !== $lstat['dev'] || $identity['lstat']['ino'] !== $lstat['ino']
		|| $identity['stat']['dev'] !== $stat['dev'] || $identity['stat']['ino'] !== $stat['ino'])
		return(false);
	return($identity);
}

function erasedataCleanupSuccessorSnapshot($paths,
	ErasedataFilesystemOps $filesystem)
{
	if(!is_array($paths))
		return(false);
	$seen = array();
	$observations = array();
	$byIdentity = array();
	foreach($paths as $path)
	{
		if(!is_string($path) || $path === '')
			return(false);
		if(isset($seen["p\0".$path]))
			continue;
		$seen["p\0".$path] = true;
		$identity = erasedataCleanupSuccessorObservation($path, $filesystem);
		if($identity === false)
			return(false);
		$observationKey = "p\0".$path;
		$observations[$observationKey] = array('path' => $path, 'identity' => $identity);
		if(!empty($identity['exists']))
		{
			$key = erasedataCleanupExactIdentityKey($identity);
			if($key === false)
				return(false);
			if(!isset($byIdentity[$key]))
				$byIdentity[$key] = array();
			$byIdentity[$key][] = $observationKey;
		}
	}
	return(array('observations' => $observations, 'by_identity' => $byIdentity));
}

function erasedataCleanupSuccessorObservationMatches($expected, $current)
{
	if(!is_array($expected) || !is_array($current)
		|| !array_key_exists('exists', $expected) || !array_key_exists('exists', $current)
		|| (bool)$expected['exists'] !== (bool)$current['exists']
		|| !isset($expected['path'], $current['path']) || $expected['path'] !== $current['path'])
		return(false);
	if(empty($expected['exists']))
		return(true);
	return(erasedataSameStatIdentity($expected['lstat'], $current['lstat'])
		&& erasedataSameStatIdentity($expected['stat'], $current['stat']));
}

function erasedataCleanupSuccessorSnapshotStillMatches($snapshot,
	ErasedataFilesystemOps $filesystem)
{
	if(!is_array($snapshot) || !isset($snapshot['observations']) || !is_array($snapshot['observations']))
		return(false);
	foreach($snapshot['observations'] as $observation)
	{
		if(!is_array($observation) || !isset($observation['path'], $observation['identity']))
			return(false);
		$current = erasedataCleanupSuccessorObservation($observation['path'], $filesystem);
		if($current === false
			|| !erasedataCleanupSuccessorObservationMatches($observation['identity'], $current))
			return(false);
	}
	return(true);
}

function erasedataCleanupIdentityMatches($expected, $current)
{
	return(is_array($expected) && is_array($current) && !empty($current['exists'])
		&& isset($expected['canonical'], $expected['lstat'], $expected['stat'], $expected['size'], $expected['mtime'])
		&& $expected['canonical'] === $current['path'] && erasedataSameStatIdentity($expected['lstat'], $current['lstat'])
		&& erasedataSameStatIdentity($expected['stat'], $current['stat'])
		&& $expected['size'] === $current['size'] && $expected['mtime'] === $current['mtime']);
}

// A cleanup capture has a different authority from ordinary forced deletion:
// recovery restores it before another ownership decision, and never publishes
// a public symlink to private bytes.
function erasedataCleanupRestoreCapturedFile($path, $info,
	ErasedataFilesystemOps $filesystem, &$reason)
{
	$entry = $info['entry'];
	$captured = $filesystem->entryIdentity($entry);
	if(!is_array($captured))
	{
		$reason = 'capture-missing';
		return(false);
	}
	if(erasedataCapturedEntryBridgeMatches($path, $entry, $filesystem)
		&& !erasedataRemoveCapturedEntryBridge(
			$path, $entry, $info['root'], $filesystem, false))
	{
		$reason = 'legacy-bridge-retained';
		return(false);
	}
	if(erasedataPathExists($path))
	{
		$reason = 'restore-occupied';
		return(false);
	}
	if(!$filesystem->renameNoReplace($entry, $path))
	{
		$reason = erasedataPathExists($path) ? 'restore-occupied' : 'restore-failed';
		return(false);
	}
	$restored = $filesystem->entryIdentity($path);
	if(!erasedataSameEntryIdentity($captured, $restored)
		|| erasedataPathExists($entry))
	{
		$reason = 'restore-unverified';
		return(false);
	}
	if(!erasedataRemoveCapturedEntryRoot($info['root'], $filesystem))
	{
		$reason = 'capture-root-retained';
		return(false);
	}
	return(true);
}

function erasedataCleanupCaptureReferencedByName($entry, $public,
	$successorSnapshot, $otherSnapshot)
{
	$key = "p\0".$entry;
	if(isset($otherSnapshot['paths'][$key]))
	{
		// The previous release published a bridge at $public. A torrent
		// naming that bridge keeps working after exact restoration; a direct
		// reference to the private name would break, so keep the capture.
		if(!isset($otherSnapshot['bindings'][$key])
			|| !is_array($otherSnapshot['bindings'][$key]))
			return(true);
		foreach($otherSnapshot['bindings'][$key] as $raw => $_)
			if($raw !== "r\0".$public)
				return(true);
	}
	foreach($successorSnapshot['observations'] as $observation)
		if(!empty($observation['identity']['exists'])
			&& $observation['identity']['path'] === $entry
			&& $observation['path'] !== $public)
			return(true);
	return(false);
}

function erasedataCleanupCapturedFileMatches($entry, $expected, $original,
	ErasedataFilesystemOps $filesystem)
{
	$current = $filesystem->entryIdentity($entry);
	if(!erasedataSameEntryIdentity($original, $current)
		|| !isset($expected['lstat']['dev'], $expected['lstat']['ino'])
		|| $current['dev'] !== $expected['lstat']['dev']
		|| $current['ino'] !== $expected['lstat']['ino'])
		return(false);
	if(!empty($current['is_link']))
	{
		// Moving a relative symlink changes how stat() resolves its target.
		// Its own inode and link text are the properties that remain stable.
		return(isset($original['link']) && $filesystem->readLink($entry) === $original['link']);
	}
	if(empty($current['is_file']))
		return(false);
	$target = $filesystem->targetIdentity($entry);
	return(is_array($target) && !empty($target['is_file'])
		&& $target['dev'] === $expected['stat']['dev']
		&& $target['ino'] === $expected['stat']['ino']
		&& $target['size'] === $expected['size']
		&& $target['mtime'] === $expected['mtime']);
}

function erasedataCleanupParents($manifest)
{
	$dirs = array();
	foreach($manifest['files'] as $file)
	{
		$parent = dirname($file);
		while($parent !== $manifest['base'] && erasedataPathContains($manifest['base'], $parent))
		{
			$dirs[$parent] = $parent;
			$next = dirname($parent);
			if($next === $parent)
				break;
			$parent = $next;
		}
	}
	$dirs = array_values($dirs);
	usort($dirs, 'sortByLevel');
	return($dirs);
}

function erasedataCleanupParentOwnerState($snapshot, $path)
{
	if(!is_array($snapshot) || !isset($snapshot['paths'])
		|| !is_array($snapshot['paths']))
		return('unknown');
	foreach($snapshot['paths'] as $key => $_)
	{
		if(strpos($key, "p\0") !== 0)
			return('unknown');
		if(erasedataPathsOverlap($path, substr($key, 2)))
			return('claimed');
	}
	return('unclaimed');
}

// Legacy cleanup used a public symlink to a reserved directory. Move only
// that exact link to a private tombstone and remove it; never publish a new
// bridge while restoring the directory itself.
function erasedataCleanupDropReservationBridge($path, $reserved, $reservation,
	ErasedataFilesystemOps $filesystem, &$reason)
{
	$entries = $filesystem->scanDirectory($reservation);
	if($entries === false)
	{
		$reason = 'legacy-capture-retained';
		return(false);
	}
	$tombstones = array_values(array_filter($entries, function($name) {
		return((bool)preg_match('/^\.bridge-[0-9]+-[0-9]+$/D', $name));
	}));
	if(count($tombstones) > 1)
	{
		$reason = 'legacy-capture-retained';
		return(false);
	}
	if(!count($tombstones))
	{
		if(!erasedataReservationLinkMatches($path, $reserved, $filesystem))
		{
			if(erasedataPathExists($path))
			{
				$reason = 'restore-occupied';
				return(false);
			}
			return(true);
		}
		$identity = $filesystem->entryIdentity($path);
		if(!is_array($identity) || empty($identity['is_link']))
		{
			$reason = 'legacy-capture-retained';
			return(false);
		}
		$tombstone = $reservation.'/.bridge-'.$identity['dev'].'-'.$identity['ino'];
		if(!$filesystem->renameNoReplace($path, $tombstone))
		{
			$reason = 'legacy-capture-retained';
			return(false);
		}
	}
	else
		$tombstone = $reservation.'/'.$tombstones[0];
	if(!preg_match('/^\.bridge-([0-9]+)-([0-9]+)$/D',
		basename($tombstone), $matches))
	{
		$reason = 'legacy-capture-retained';
		return(false);
	}
	$current = $filesystem->entryIdentity($tombstone);
	if(!is_array($current) || empty($current['is_link'])
		|| (string)$current['dev'] !== $matches[1]
		|| (string)$current['ino'] !== $matches[2]
		|| $filesystem->readLink($tombstone) !== $reserved
		|| !$filesystem->unlink($tombstone))
	{
		$reason = 'legacy-capture-retained';
		return(false);
	}
	return(!erasedataPathExists($tombstone));
}

function erasedataCleanupRestoreReservation($path, $reservation, $reservationKey,
	ErasedataFilesystemOps $filesystem, &$reason, &$skipParent)
{
	$reserved = erasedataReservationDataPath($reservation);
	if(!erasedataPathExists($reserved))
	{
		if(erasedataReservationEncodedIdentity(
				$reservation, $path, $reservationKey) === false
			|| !erasedataPrivateMarkerIsValid($reservation))
		{
			$reason = 'capture-identity';
			return(false);
		}
		$phase = erasedataCleanupPhaseState($reservation);
		if($phase !== 'delete')
		{
			// After restore, even matching dev:ino cannot prove that the public
			// name still holds the old directory. Retain both job and shell.
			$reason = ($phase === 'restore' ? 'restore-uncertain' : 'phase-unknown')
				.' parent-key='.substr(hash('sha256', $path), 0, 16);
			return(false);
		}
		if((!erasedataPathExists($path)
				|| erasedataReservationLinkMatches($path, $reserved, $filesystem))
			&& !erasedataCleanupDropReservationBridge(
				$path, $reserved, $reservation, $filesystem, $reason))
			return(false);
		if(!erasedataCleanupRemoveReservationContainer(
			$reservation, $path, $reservationKey, $filesystem))
		{
			$reason = 'legacy-capture-retained';
			return(false);
		}
		$skipParent = true;
		return(true);
	}
	$encoded = erasedataReservationEncodedIdentity(
		$reservation, $path, $reservationKey);
	if($encoded === false || !erasedataPrivateMarkerIsValid($reservation))
	{
		$reason = 'capture-identity';
		return(false);
	}
	$captured = $filesystem->entryIdentity($reserved);
	if(!is_array($captured) || empty($captured['is_dir'])
		|| !erasedataCleanupDropReservationBridge(
			$path, $reserved, $reservation, $filesystem, $reason))
	{
		if($reason === null) $reason = 'capture-identity';
		return(false);
	}
	if(!erasedataCleanupMarkPhase($reservation, 'restore'))
	{
		$reason = 'phase-write-failed';
		return(false);
	}
	if(!$filesystem->renameNoReplace($reserved, $path))
	{
		$reason = erasedataPathExists($path) ? 'restore-occupied' : 'restore-failed';
		return(false);
	}
	$restored = $filesystem->entryIdentity($path);
	if(!erasedataSameEntryIdentity($captured, $restored)
		|| erasedataPathExists($reserved)
		|| !erasedataCleanupRemoveReservationContainer(
			$reservation, $path, $reservationKey, $filesystem))
	{
		$reason = 'restore-unverified';
		return(false);
	}
	if((string)$captured['dev'] !== $encoded['device']
		|| (string)$captured['ino'] !== $encoded['inode'])
		$skipParent = true;
	return(true);
}

function erasedataResumeCleanupCapturedTargets($manifest, $reservationKey,
	$successorSnapshot, $otherSnapshot, ErasedataFilesystemOps $filesystem, &$reason)
{
	if(!is_array($successorSnapshot)
		|| !isset($successorSnapshot['observations'])
		|| !is_array($successorSnapshot['observations'])
		|| !is_array($otherSnapshot) || !isset($otherSnapshot['paths'])
		|| !is_array($otherSnapshot['paths']))
	{
		$reason = 'rpc-unknown';
		return(false);
	}
	$parents = array();
	$targets = array();
	foreach($manifest['files'] as $file)
	{
		if(!erasedataPathContains($manifest['base'], $file)
			|| $file === $manifest['base'])
		{
			$reason = 'unsafe-path';
			return(false);
		}
		$parents["p\0".dirname($file)] = dirname($file);
		$targets["p\0".$file] = $file;
	}
	// Refuse an unknown capture in a job parent; otherwise a later missing
	// public name could retire a job while its private payload is still there.
	foreach($parents as $parent)
	{
		$entries = $filesystem->scanDirectory($parent);
		if($entries === false)
		{
			if(!erasedataPathExists($parent))
				continue;
			$reason = 'capture-scan-failed';
			return(false);
		}
		foreach($entries as $name)
		{
			if(strpos($name, '.erasedata-entry-') !== 0)
				continue;
			$root = $parent.'/'.$name;
			$original = erasedataCapturedEntryName($root);
			$candidate = $parent.'/'.$original;
			if($original === false || !isset($targets["p\0".$candidate])
				|| strpos($root,
					erasedataCapturedEntryPrefix($candidate, $reservationKey)) !== 0)
			{
				$reason = 'capture-unbound';
				return(false);
			}
		}
	}
	foreach($targets as $file)
	{
		$roots = erasedataCapturedEntryRoots($file, $reservationKey, $filesystem);
		if($roots === false || count($roots) > 1)
		{
			$reason = 'capture-ambiguous';
			return(false);
		}
		if(!count($roots))
			continue;
		$info = erasedataCapturedEntryRootInfo(
			$roots[0], $file, $reservationKey, $filesystem);
		if($info === false)
		{
			$reason = 'capture-unbound';
			return(false);
		}
		$captured = $filesystem->entryIdentity($info['entry']);
		if($captured === false)
		{
			// A crash after restore or after private unlink leaves this shell.
			// Never infer a new deletion from a public inode seen here.
			if((!erasedataPathExists($file)
					|| erasedataCapturedEntryBridgeMatches(
						$file, $info['entry'], $filesystem))
				&& !erasedataRemoveCapturedEntryBridge(
					$file, $info['entry'], $info['root'], $filesystem, false))
			{
				$reason = 'legacy-bridge-retained';
				return(false);
			}
			if(!erasedataRemoveCapturedEntryRoot($info['root'], $filesystem))
			{
				$reason = 'capture-root-retained';
				return(false);
			}
			continue;
		}
		if(erasedataCleanupCaptureReferencedByName(
			$info['entry'], $file, $successorSnapshot, $otherSnapshot))
		{
			$reason = 'capture-aliased';
			return(false);
		}
		if(!erasedataCleanupRestoreCapturedFile($file, $info, $filesystem, $reason))
			return(false);
	}
	return(true);
}

final class ErasedataCollector
{
	private $filesystem;
	private $presenceProbe;
	private $pathCollector;
	private $logger;
	private $enableForceDeletion;
	private $blockingHashLocks;
	private $cleanupLogState = array('completed' => array(), 'retained' => array());
	private $manifestLogState = array();
	// Set by reportRetentionsTo(); null means log directly, as the ordinary
	// schedule does.
	private $retentionSink = null;

	public function __construct(ErasedataFilesystemOps $filesystem, $presenceProbe,
		$pathCollector, $logger, $enableForceDeletion, $blockingHashLocks = false)
	{
		$this->filesystem = $filesystem;
		$this->presenceProbe = $presenceProbe;
		$this->pathCollector = $pathCollector;
		$this->logger = $logger;
		$this->enableForceDeletion = $enableForceDeletion;
		$this->blockingHashLocks = $blockingHashLocks;
	}

	// Hand the retentions to the caller instead of logging them.
	//
	// Called as $sink($kind, $hash, $generation, $reason) with $kind 'manifest'
	// or 'cleanup' -- the two halves of the one rule this class states in
	// manifestLog() below. The sink belongs to the caller for the life of this
	// object; run() does not clear it, and the drain builds a fresh collector per
	// tick, so the buffer behind it is per-tick by construction.
	//
	// This object's own deduplication is per-run and both memories are cleared at
	// the top of every run(), so one pass says one line per condition. That is
	// enough only if the passes are far apart. The drain is the other shape: it
	// builds a collector on EVERY tick, ERASEDATA_DRAIN_INTERVAL apart, for as
	// long as the condition lasts, and an unchanged retention was reported again
	// on each -- about 17k identical lines a day per retained job.
	//
	// The drain already owns the answer -- erasedataDrainReportGroup() over a
	// digest it persists across ticks -- and already gives it to retirement for
	// exactly this reason. A sink lets it give the same treatment to retention
	// without this class learning anything about drain state.
	//
	// NOT a claim about the ordinary schedule. That one also fires repeatedly
	// -- $garbageCheckInterval in conf.php, 15 seconds by default -- with no sink
	// and no durable memory, so it still repeats an unchanged retention. It is a
	// separate condition with a separate home for its memory, and nothing here
	// fixes it.
	public function reportRetentionsTo($sink)
	{
		$this->retentionSink = is_callable($sink) ? $sink : null;
	}

	private function log($str)
	{
		if(is_callable($this->logger))
			call_user_func($this->logger, $str);
	}

	// One unconditional, bounded, classified line per physical job that ends
	// RETAINED: the canonical hash, the generation its manifest is bound under
	// and the reason. Never a raw path, never a settings root, never a manifest
	// byte and never anything rTorrent sent.
	//
	// Retention on the payload side used to go out through $this->log(), which
	// is eLog(), which the shipped $erasedebug_enabled = false silences: a
	// queue that retained every job for ever said nothing at any level an
	// operator ever looks at, and the only way to find out was to turn a debug
	// flag on and wait for it to happen again. The cleanup side of this same
	// collector already reports its retentions through the channel debug cannot
	// silence (cleanupLog() above); this is the payload side of that one rule.
	//
	// Bounded by construction: one line per hash, generation and reason, so a
	// queue holding many items of one job cannot turn one refusal into many
	// lines.
	private function manifestLog($hash, $path, $reason)
	{
		if(!is_array($this->manifestLogState))
			$this->manifestLogState = array();
		// The generation comes out of the NAME, which carries the hash and the
		// generation and nothing else; a legacy name that carries neither
		// reports 'none' rather than leaking what it does carry.
		$parts = explode('.', basename(is_string($path) ? $path : ''));
		$generation = isset($parts[1]) && erasedataGenerationIsValid($parts[1])
			? $parts[1] : 'none';
		$key = $hash.'|'.$generation.'|'.$reason;
		if(isset($this->manifestLogState[$key]))
			return;
		$this->manifestLogState[$key] = true;
		// The report belongs to whoever was scheduled BECAUSE of the condition.
		//
		// Two schedules run this collector and they have opposite lifetimes. The
		// ordinary one (erasedata, $garbageCheckInterval) is registered at plugin
		// init unconditionally and fires for ever, in a fresh process each time,
		// whether or not anything is retained -- so no memory this object could
		// keep would bound anything, and an un-silenceable line here is one line
		// every 15 seconds for the life of the installation. The drain
		// (erasedata-drain<User>) is armed only while an obligation exists and is
		// retired when it does not: a retained published manifest classifies as
		// 'final', keeps the retirement scan non-empty, and therefore keeps that
		// schedule alive exactly as long as there is something to say.
		//
		// So the drain reports retention and the ordinary pass says nothing --
		// which is also what this plugin did before the sink existed: there was
		// no payload retention line at all on the ordinary path.
		//
		// Publication failures retain the codec's direct report on the ordinary
		// path: legacy staging may have no drain to report for it. With a sink,
		// the codec stays quiet and this classified note goes through its memory.
		if($this->retentionSink !== null)
		{
			// The per-run key above still runs, so one tick offers one note per
			// condition; the caller decides whether the tick says anything at all.
			call_user_func($this->retentionSink, 'manifest', $hash, $generation,
				$reason);
		}
	}

	private function probePresence($hash)
	{
		return(call_user_func($this->presenceProbe, $hash));
	}

	private function collectPaths($hash, $physicalOnly = false)
	{
		return(call_user_func($this->pathCollector, $hash, $physicalOnly));
	}

	// One hash, discovered and indexed by this call. Callers that already hold a
	// queue index use run() so the directory is scanned exactly once.
	public function collectHash($listPath, $hash)
	{
		$this->collectHashIndexed($listPath, $hash, null, null);
	}

	private function parseOneItem($item, $manifest, $ownedPaths)
	{
		$this->log('*** Parse item '.$item);
		// Callers hand over a record the codec already normalized; nothing here
		// reassembles or re-parses the physical manifest bytes.
		if(!is_array($manifest) || !isset($manifest['version']))
			return(false);
		// An unknown or unreadable owned-path set is an empty one here; every
		// caller below hands the same normalized array to the owned-path owner.
		if(!is_array($ownedPaths))
			$ownedPaths = array();

		$dirs = array();
		$complete = true;
		$force_delete = $manifest['force'] === 2 && $this->enableForceDeletion;
		$is_multi = !empty($manifest['multi']);
		$base_path = $manifest['base'];
		$files = $manifest['files'];

		if(!$force_delete || !$is_multi)
		{
			foreach($files as $file)
			{
				$entry = $this->filesystem->entryIdentity($file);
				if(!is_array($entry))
				{
					if(erasedataPathExists($file))
					{
						$this->log('Retain unresolved file '.$file);
						$complete = false;
					}
					else if($this->filesystem->unlinkCapturedEntry(
						$file, null, $item))
						$this->log('Successfully delete file '.$file);
					else
					{
						$this->log('FAIL resume captured file '.$file);
						$complete = false;
					}
				}
				else if(!empty($entry['is_link']))
				{
					if(erasedataPathTouchesOwnedPaths($file, $ownedPaths))
					{
						$this->log('Retain active file '.$file);
						$complete = false;
					}
					else
					{
						if($this->filesystem->unlinkCapturedEntry($file, $entry, $item))
							$this->log('Successfully delete file '.$file);
						else
						{
							$this->log('FAIL Delete file '.$file);
							$complete = false;
						}
					}
				}
				else
				{
					$identity = $this->filesystem->pathIdentity($file);
					if($identity === false)
					{
						$this->log('Retain unresolved file '.$file);
						$complete = false;
					}
					else if(empty($identity['exists']))
					{
						$this->log('Retain identity-changed file '.$file);
						$complete = false;
					}
					else if(erasedataPathTouchesOwnedPaths($file, $ownedPaths))
					{
						$this->log('Retain active file '.$file);
						$complete = false;
					}
					else if(!empty($entry['is_dir']))
					{
						$this->log('Retain directory in file manifest '.$file);
						$complete = false;
					}
					else
					{
						if($this->filesystem->unlinkCapturedEntry($file, $entry, $item))
							$this->log('Successfully delete file '.$file);
						else
						{
							$this->log('FAIL Delete file '.$file);
							$complete = false;
						}
					}
				}
				if($is_multi)
				{
					$dir = $base_path;
					$relative = substr($file, strlen($base_path)+1);
					$pieces = explode('/', $relative);
					for($i = 0; $i < count($pieces) - 1; $i++)
					{
						$dir .= '/'.$pieces[$i];
						$dirs[] = $dir;
					}
				}
			}
		}
		if($is_multi)
		{
			if($force_delete)
			{
				if(erasedataPathTouchesOwnedPaths($base_path, $ownedPaths))
				{
					$this->log('Retain active forced directory '.$base_path);
					$complete = false;
				}
				else
				{
					$existed = erasedataPathExists($base_path);
					if(erasedataCompleteForcedDirectory($base_path, $item, $this->filesystem))
					{
						if($existed && erasedataPathExists($base_path))
							$this->log('Leave unrelated dir '.$base_path);
						else
							$this->log('Successfully forced delete dir '.$base_path);
					}
					else
					{
						$this->log('FAIL force delete dir '.$base_path);
						$complete = false;
					}
					if(erasedataPathExists($base_path))
						$complete = false;
				}
			}
			else
			{
				$dirs = array_unique($dirs);
				usort($dirs, "sortByLevel");
				foreach($dirs as $dir)
				{
					if(erasedataPathTouchesOwnedPaths($dir, $ownedPaths))
					{
						$this->log('Retain active dir '.$dir);
						$complete = false;
					}
					else
					{
						$existed = erasedataPathExists($dir);
						if(erasedataCompleteNonForceDirectory($dir, $item, $this->filesystem))
						{
							if($existed && erasedataPathExists($dir))
								$this->log('Leave unrelated dir '.$dir);
							else
								$this->log('Successfully delete dir '.$dir);
						}
						else
						{
							$this->log('FAIL delete dir '.$dir);
							$complete = false;
						}
					}
				}
				if(erasedataPathTouchesOwnedPaths($base_path, $ownedPaths))
				{
					$this->log('Retain active dir '.$base_path);
					$complete = false;
				}
				else
				{
					$existed = erasedataPathExists($base_path);
					if(erasedataCompleteNonForceDirectory($base_path, $item, $this->filesystem))
					{
						if($existed && erasedataPathExists($base_path))
						{
							$this->log('Leave unrelated dir '.$base_path);
						}
						else
							$this->log('Successfully delete dir '.$base_path);
					}
					else
					{
						$this->log('FAIL delete dir '.$base_path);
						$complete = false;
					}
				}
			}
		}
		return($complete);
	}

	private function quarantineUnverifiedManifest($path, $expectedStat, $hash)
	{
		$token = erasedataPrivateToken();
		$aside = $token === false ? false : $path.'.'.$token.'.unverified';
		$current = $this->filesystem->entryIdentity($path);
		$stat = is_array($current) ? $current['lstat'] : false;
		if($aside === false || is_link($path) || !is_array($stat)
			|| !erasedataSameStatIdentity($expectedStat, $stat)
			|| !$this->filesystem->renameNoReplace($path, $aside))
		{
			FileUtil::toLog('erasedata: unverified-legacy-list '.basename($path)
				.' for '.$hash.' could not be quarantined; original manifest and payload retained');
			return(false);
		}
		$quarantined = $this->filesystem->entryIdentity($aside);
		$asideStat = is_array($quarantined) ? $quarantined['lstat'] : false;
		if(!is_array($asideStat) || !erasedataSameStatIdentity($expectedStat, $asideStat)
			|| erasedataPathExists($path))
		{
			FileUtil::toLog('erasedata: unverified-legacy-list '.basename($path)
				.' for '.$hash.' has uncertain quarantine state; payload retained; inspect '
				.basename($aside));
			return(false);
		}
		FileUtil::toLog('erasedata: unverified-legacy-list '.basename($path)
			.' for '.$hash.' moved to '.basename($aside)
			.'; payload retained for manual inspection');
		return(true);
	}

	private function quarantinePlaintextCandidate($item)
	{
		if($item['type'] !== 'list')
			return(null);
		$path = $item['path'];
		$handle = @fopen($path, 'rb');
		if($handle === false)
			return(null);
		$stat = @fstat($handle);
		$identity = $this->filesystem->entryIdentity($path);
		$current = is_array($identity) ? $identity['lstat'] : false;
		if(is_link($path) || !is_array($stat) || !is_array($current)
			|| !erasedataSameStatIdentity($stat, $item['stat'])
			|| !erasedataSameStatIdentity($stat, $current))
		{
			@fclose($handle);
			return(null);
		}
		// Every current writer starts JSON with '{'. A one-byte peek keeps
		// normal jobs on their existing single-read path.
		$first = @fread($handle, 1);
		if($first === '{')
		{
			@fclose($handle);
			return(null);
		}
		@rewind($handle);
		$bytes = ErasedataManifestCodec::readBoundedHandle($handle);
		if(!is_string($bytes) || !ErasedataManifestCodec::isUnverifiedPlaintext($bytes))
		{
			@fclose($handle);
			return(null);
		}
		$result = $this->quarantineUnverifiedManifest($path, $stat, $item['hash']);
		@fclose($handle);
		return($result);
	}

	private function consumeManifest($path, $expectedStat, $ownedPaths)
	{
		$handle = @fopen($path, 'r');
		if($handle === false)
			return(false);
		$stat = @fstat($handle);
		$pathIdentity = $this->filesystem->entryIdentity($path);
		$pathStat = is_array($pathIdentity) ? $pathIdentity['lstat'] : false;
		if(is_link($path) || !is_array($stat) || !is_array($pathStat) || !is_array($expectedStat) ||
			$stat['dev'] !== $expectedStat['dev'] || $stat['ino'] !== $expectedStat['ino'] ||
			$stat['dev'] !== $pathStat['dev'] || $stat['ino'] !== $pathStat['ino'])
		{
			@fclose($handle);
			return(false);
		}
		$hash = preg_match('/^([0-9A-Fa-f]{40})/D', basename($path), $m) ? $m[1] : '';
		$bytes = ErasedataManifestCodec::readBoundedHandle($handle);
		if(is_string($bytes) && ErasedataManifestCodec::isUnverifiedPlaintext($bytes))
		{
			// Keep the inode open while moving the exact entry out of the
			// collector namespace. A missing no-replace helper retains it.
			$quarantined = $this->quarantineUnverifiedManifest($path, $stat, $hash);
			@fclose($handle);
			return($quarantined);
		}
		$manifest = is_string($bytes)
			? ErasedataManifestCodec::decodeBytes($bytes, $hash) : false;
		if($manifest === false || !isset($manifest['operation'])
			|| $manifest['operation'] !== ErasedataManifestCodec::OPERATION_REMOVE_PAYLOAD)
		{
			@fclose($handle);
			return(false);
		}
		$complete = $this->parseOneItem($path, $manifest, $ownedPaths);
		// $stat is the fstat of the handle this function still holds open, so the
		// deletion below is bound to the exact inode that was decoded.
		$ret = $complete && $this->filesystem->unlinkCapturedEntry(
			$path, $stat, 'manifest-consumption');
		@fclose($handle);
		return($ret);
	}

	private function cleanupSuccessorPaths($newHash)
	{
		$presence = $this->probePresence($newHash);
		if($presence === ERASEDATA_TORRENT_ABSENT)
			return(array());
		if($presence !== ERASEDATA_TORRENT_PRESENT)
			return(false);
		$paths = $this->collectPaths($newHash, true);
		return($paths === false || !isset($paths['files']) || !is_array($paths['files']) ? false : $paths['files']);
	}

	private function cleanupLog($hash, $state, $reason = null, $jobPath = '', $jobKey = null)
	{
		if(!is_array($this->cleanupLogState))
			$this->cleanupLogState = array('completed' => array(), 'retained' => array());
		$key = $hash.'|'.($jobKey === null ? $jobPath : $jobKey);
		if($state === 'complete')
		{
			if(isset($this->cleanupLogState['completed'][$key])) return;
			$this->cleanupLogState['completed'][$key] = true;
			$this->log('cleanup complete '.$hash);
			return;
		}
		if(isset($this->cleanupLogState['retained'][$key])) return;
		$this->cleanupLogState['retained'][$key] = true;
		if(is_string($reason) && (strpos($reason, 'restore-uncertain') === 0
			|| strpos($reason, 'phase-unknown') === 0))
			$reason .= ' job='.basename($jobPath);
		// The cleanup half of the one rule manifestLog() names. 'unreadable-manifest'
		// and 'generation-mismatch' are healed by no retry and pruned by nothing, so
		// on the drain's schedule this line repeated for the life of the daemon.
		if($this->retentionSink !== null)
		{
			// 'none', and not a parse that happens to fail: a cleanup job is named
			// <hash>.cleanup.<digits>.<token>, and that number is not a manifest
			// generation -- erasedataParseCollectorCandidate() matches it as
			// [0-9]+ where a generation is 16 lowercase hex. There is no
			// generation to report here, so the note says so.
			call_user_func($this->retentionSink, 'cleanup', $hash, 'none',
				$reason === null ? 'unspecified' : $reason);
			return;
		}
		FileUtil::toLog('erasedata: cleanup retained '.$hash.' '.$reason);
	}

	private function cleanupEmptyParent($dir, $reservationKey, $manifest, &$reason)
	{
		$reservations = erasedataDirectoryReservations(
			$dir, $reservationKey, $this->filesystem);
		if($reservations === false || count($reservations) > 1)
		{
			$reason = 'legacy-capture-retained';
			return(false);
		}
		$restoredIdentity = null;
		if(count($reservations))
		{
			$encoded = erasedataReservationEncodedIdentity(
				$reservations[0], $dir, $reservationKey);
			$skipParent = false;
			if(!erasedataCleanupRestoreReservation($dir, $reservations[0],
				$reservationKey, $this->filesystem, $reason, $skipParent))
				return(false);
			if($skipParent)
				return(true);
			$restoredIdentity = $encoded;
		}
		if(is_link($dir))
		{
			$target = $this->filesystem->readLink($dir);
			if(is_string($target) && strpos($target,
				erasedataDirectoryReservationPrefix($dir, $reservationKey)) === 0)
			{
				$reason = 'legacy-bridge-retained';
				return(false);
			}
			return(true);
		}
		if(!erasedataPathExists($dir))
			return(true);
		if(!is_dir($dir))
		{
			$reason = 'unsafe-path';
			return(false);
		}
		$owners = erasedataCleanupOtherOwnerSnapshot($manifest['hash'],
			$manifest['new_hash'], $manifest['marker'], $manifest['replacement_record']);
		$state = erasedataCleanupParentOwnerState($owners, $dir);
		if($state === 'unknown')
		{
			$reason = 'rpc-unknown';
			return(false);
		}
		if($state === 'claimed')
			return(true);
		$entries = $this->filesystem->scanDirectory($dir);
		if($entries === false)
		{
			$reason = 'unsafe-path';
			return(false);
		}
		if(count(array_diff($entries, array('.', '..'))) > 0)
			return(true);
		$expected = $this->filesystem->pathIdentity($dir);
		$before = $this->filesystem->entryIdentity($dir);
		if(!is_array($expected) || empty($expected['exists'])
			|| !is_array($before) || empty($before['is_dir'])
			|| ($restoredIdentity !== null
				&& (!is_array($restoredIdentity)
					|| (string)$before['dev'] !== $restoredIdentity['device']
					|| (string)$before['ino'] !== $restoredIdentity['inode']))
			|| $before['dev'] !== $expected['lstat']['dev']
			|| $before['ino'] !== $expected['lstat']['ino']
			|| !erasedataSameFilesystemEntry(
				$expected, $this->filesystem->pathIdentity($dir)))
		{
			$reason = 'unsafe-path';
			return(false);
		}
		$reservation = erasedataDirectoryReservationPath($dir, $reservationKey, $expected);
		if($reservation === false
			|| !$this->filesystem->makeDirectory($reservation, 0700))
		{
			$reason = 'capture-failed';
			return(false);
		}
		if(!erasedataCreatePrivateMarker($reservation))
		{
			$this->filesystem->removeDirectory($reservation);
			$reason = 'capture-failed';
			return(false);
		}
		$reserved = erasedataReservationDataPath($reservation);
		if(!$this->filesystem->rename($dir, $reserved))
		{
			erasedataRemoveReservationContainer(
				$reservation, $dir, $reservationKey, $this->filesystem);
			$reason = 'capture-failed';
			return(false);
		}
		$captured = $this->filesystem->entryIdentity($reserved);
		if(!is_array($captured) || !erasedataSameEntryIdentity($before, $captured)
			|| !erasedataReservationHasEncodedIdentity(
				$reservation, $dir, $reservationKey))
		{
			$skipParent = false;
			if(!erasedataCleanupRestoreReservation($dir, $reservation,
				$reservationKey, $this->filesystem, $reason, $skipParent))
				return(false);
			return(true); // A different directory is not this job's parent.
		}
		$postOwners = erasedataCleanupOtherOwnerSnapshot($manifest['hash'],
			$manifest['new_hash'], $manifest['marker'], $manifest['replacement_record']);
		$postState = erasedataCleanupParentOwnerState($postOwners, $dir);
		if($postState !== 'unclaimed')
		{
			$skipParent = false;
			if(!erasedataCleanupRestoreReservation($dir, $reservation,
				$reservationKey, $this->filesystem, $reason, $skipParent))
				return(false);
			if($postState === 'claimed')
				return(true);
			$reason = 'rpc-unknown';
			return(false);
		}
		$after = $this->filesystem->scanDirectory($reserved);
		if(!is_array($after) || count(array_diff($after, array('.', '..'))) > 0
			|| erasedataPathExists($dir)
			|| !erasedataSameEntryIdentity(
				$captured, $this->filesystem->entryIdentity($reserved)))
		{
			if(!erasedataPathExists($dir))
			{
				$skipParent = false;
				erasedataCleanupRestoreReservation($dir, $reservation,
					$reservationKey, $this->filesystem, $reason, $skipParent);
			}
			$reason = 'unsafe-path';
			return(false);
		}
		if(!erasedataCleanupMarkPhase($reservation, 'delete'))
		{
			$reason = 'phase-write-failed';
			return(false);
		}
		if(!$this->filesystem->removeDirectory($reserved))
		{
			$skipParent = false;
			erasedataCleanupRestoreReservation($dir, $reservation,
				$reservationKey, $this->filesystem, $reason, $skipParent);
			$reason = 'rmdir-failure';
			return(false);
		}
		if(!erasedataCleanupRemoveReservationContainer(
			$reservation, $dir, $reservationKey, $this->filesystem))
		{
			$reason = 'capture-root-retained';
			return(false);
		}
		return(true);
	}

	private function cleanupCapturedFile($file, $current, $expected, $reservationKey,
		$manifest, $successorFiles, $successorSnapshot, &$reason)
	{
		$original = $this->filesystem->entryIdentity($file);
		if(!is_array($original) || (empty($original['is_file']) && empty($original['is_link'])))
		{
			$reason = 'capture-identity';
			return(false);
		}
		if(!empty($original['is_link']))
		{
			$original['link'] = $this->filesystem->readLink($file);
			if(!is_string($original['link']))
			{
				$reason = 'capture-identity';
				return(false);
			}
		}
		$root = erasedataCreateCapturedEntryRoot(
			$file, $reservationKey, $original, $this->filesystem);
		if($root === false)
		{
			$reason = 'capture-failed';
			return(false);
		}
		$entry = erasedataCapturedEntryDataPath($root);
		if(!$this->filesystem->rename($file, $entry))
		{
			erasedataRemoveCapturedEntryRoot($root, $this->filesystem);
			$reason = 'capture-failed';
			return(false);
		}
		$info = erasedataCapturedEntryRootInfo(
			$root, $file, $reservationKey, $this->filesystem);
		if($info === false)
		{
			$reason = 'capture-unbound';
			return(false);
		}
		if(!erasedataCleanupCapturedFileMatches(
			$entry, $expected, $original, $this->filesystem))
		{
			erasedataCleanupRestoreCapturedFile($file, $info, $this->filesystem, $reason);
			$reason = 'capture-identity';
			return(false);
		}

		// Rebuild both authorities while the public name is absent. The old
		// snapshots were only permission to begin capture, never to unlink.
		$postOwners = erasedataCleanupOtherOwnerSnapshot($manifest['hash'],
			$manifest['new_hash'], $manifest['marker'], $manifest['replacement_record']);
		$postPaths = $this->cleanupSuccessorPaths($manifest['new_hash']);
		$postSuccessor = is_array($postPaths) && $postPaths === $successorFiles
			? erasedataCleanupSuccessorSnapshot($postPaths, $this->filesystem) : false;
		$owner = $postOwners === false ? 'unknown'
			: erasedataCleanupOtherOwnerState($postOwners, $file, $current['stat']);
		if($postSuccessor === false)
			$owner = 'unknown';
		else
			foreach($postSuccessor['observations'] as $observation)
			{
				$alias = erasedataExactFileAlias($current, $observation['identity']);
				if($alias === ERASEDATA_FILE_ALIAS_SAME)
					$owner = 'claimed';
				else if($alias === ERASEDATA_FILE_ALIAS_UNKNOWN)
					$owner = 'unknown';
			}
		if($owner !== 'unclaimed')
		{
			if(!erasedataCleanupRestoreCapturedFile(
				$file, $info, $this->filesystem, $reason))
				return(false);
			if($owner === 'claimed')
				return(true);
			$reason = 'rpc-unknown';
			return(false);
		}
		if(erasedataPathExists($file)
			|| !erasedataCleanupSuccessorSnapshotStillMatches(
				$postSuccessor, $this->filesystem)
			|| !erasedataCleanupCapturedFileMatches(
					$entry, $expected, $original, $this->filesystem))
		{
			if(!erasedataPathExists($file))
				erasedataCleanupRestoreCapturedFile($file, $info, $this->filesystem, $reason);
			$reason = 'unsafe-path';
			return(false);
		}
		if(!$this->filesystem->unlink($entry) || erasedataPathExists($entry))
		{
			erasedataCleanupRestoreCapturedFile($file, $info, $this->filesystem, $reason);
			$reason = 'unlink-failure';
			return(false);
		}
		if(!erasedataRemoveCapturedEntryRoot($root, $this->filesystem))
		{
			$reason = 'capture-root-retained';
			return(false);
		}
		return(true);
	}

	private function consumeCleanupManifest($path, $expectedStat, $hash, $token, $jobKey = null)
	{
		$artifact = null;
		$manifest = erasedataReadCleanupManifest($path, $expectedStat, $hash, $artifact,
			$this->filesystem);
		if($manifest === false || !erasedataCleanupCommittedPairStillMatches(
			$artifact, $token, $hash, $this->filesystem))
		{
			$this->cleanupLog($hash, 'retained', 'unreadable-manifest', $path, $jobKey);
			return(false);
		}
		if(!erasedataRepairExactCleanupTokenMode($token['candidate']['path'], $token['candidate']['stat'])
			|| !erasedataCleanupCommittedPairStillMatches($artifact, $token, $hash, $this->filesystem))
		{
			$this->cleanupLog($hash, 'retained', 'unreadable-manifest', $path, $jobKey);
			return(false);
		}
		$successorFiles = $this->cleanupSuccessorPaths($manifest['new_hash']);
		if($successorFiles === false)
		{
			$this->cleanupLog($hash, 'retained', 'rpc-unknown', $path, $jobKey);
			return(false);
		}
		$otherSnapshot = erasedataCleanupOtherOwnerSnapshot($manifest['hash'],
			$manifest['new_hash'], $manifest['marker'], $manifest['replacement_record']);
		if($otherSnapshot === false)
		{
			$this->cleanupLog($hash, 'retained', 'rpc-unknown', $path, $jobKey);
			return(false);
		}
		$successorSnapshot = erasedataCleanupSuccessorSnapshot($successorFiles, $this->filesystem);
		if($successorSnapshot === false)
		{
			$this->cleanupLog($hash, 'retained', 'unsafe-path', $path, $jobKey);
			return(false);
		}
		// The successor probe can take long enough for a same-inode manifest rewrite.
		// Re-read exact bytes before an obsolete target can be authorized from them.
		if(!erasedataCleanupCommittedPairStillMatches($artifact, $token, $hash, $this->filesystem))
		{
			$this->cleanupLog($hash, 'retained', 'unreadable-manifest', $path, $jobKey);
			return(false);
		}
		// Cleanup captures live beside payload targets, not in the queue directory.
		// Preflight their aliases against the stable successor snapshot before a
		// missing public name can satisfy the exact job.
		$resumeReason = null;
		if(!erasedataResumeCleanupCapturedTargets(
			$manifest, $path, $successorSnapshot, $otherSnapshot, $this->filesystem, $resumeReason))
		{
			$this->cleanupLog($hash, 'retained',
				$resumeReason === null ? 'unlink-failure' : $resumeReason, $path, $jobKey);
			return(false);
		}
		// Recovery can restore a public inode. Refresh other ownership, while
		// keeping the original successor observation as the comparison baseline:
		// accepting a changed successor here would authorize stale deletion.
		$otherSnapshot = erasedataCleanupOtherOwnerSnapshot($manifest['hash'],
			$manifest['new_hash'], $manifest['marker'], $manifest['replacement_record']);
		if($otherSnapshot === false)
		{
			$this->cleanupLog($hash, 'retained', 'rpc-unknown', $path, $jobKey);
			return(false);
		}
		$complete = true;
		$reason = null;
		$actions = array();
		foreach($manifest['files'] as $file)
		{
			if(!erasedataPathContains($manifest['base'], $file) || $file === $manifest['base'])
			{
				$complete = false;
				$reason = 'unsafe-path';
				break;
			}
			$current = erasedataCleanupCurrentIdentity($file);
			if($current === false)
			{
				$complete = false;
				$reason = 'unsafe-path';
				break;
			}
			if(empty($current['exists']))
				continue;
			$expected = isset($manifest['identities'][$file]) ? $manifest['identities'][$file] : null;
			if(!is_array($expected) || !isset($expected['canonical']))
			{
				$complete = false;
				$reason = 'unreadable-manifest';
				break;
			}
			if($current['path'] !== $expected['canonical'])
			{
				$complete = false;
				$reason = 'unsafe-path';
				break;
			}
			if(!erasedataCleanupIdentityMatches($expected, $current))
				continue; // A replacement object satisfies the old object's obligation.
			$owner = erasedataCleanupOtherOwnerState($otherSnapshot, $file, $current['stat']);
			if($owner === 'unknown')
			{
				$complete = false;
				$reason = 'rpc-unknown';
				break;
			}
			if($owner === 'claimed')
				continue; // A present third-owner claim intentionally protects this path.
			$key = erasedataCleanupExactIdentityKey($current);
			if($key === false)
			{
				$complete = false;
				$reason = 'unsafe-path';
				break;
			}
			$aliasObservation = null;
			if(isset($successorSnapshot['by_identity'][$key]))
			{
				foreach($successorSnapshot['by_identity'][$key] as $observationKey)
				{
					if(!isset($successorSnapshot['observations'][$observationKey]['identity']))
					{
						$complete = false;
						$reason = 'unsafe-path';
						break 2;
					}
					$alias = erasedataExactFileAlias($current,
						$successorSnapshot['observations'][$observationKey]['identity']);
					if($alias === ERASEDATA_FILE_ALIAS_UNKNOWN)
					{
						$complete = false;
						$reason = 'unsafe-path';
						break 2;
					}
					if($alias === ERASEDATA_FILE_ALIAS_SAME)
						$aliasObservation = $observationKey;
				}
			}
			$actions[] = array('file' => $file, 'expected' => $expected,
				'alias_observation' => $aliasObservation);
		}
		// One final linear successor scan covers every name that could have become
		// an alias without multiplying successor probes by obsolete-file count.
		if($complete && !erasedataCleanupSuccessorSnapshotStillMatches(
			$successorSnapshot, $this->filesystem))
		{
			$complete = false;
			$reason = 'unsafe-path';
		}
		if($complete)
			foreach($actions as $action)
			{
				$current = erasedataCleanupCurrentIdentity($action['file']);
				if(!erasedataCleanupIdentityMatches($action['expected'], $current))
				{
					$complete = false;
					$reason = 'unlink-failure';
					break;
				}
				if(!is_null($action['alias_observation']))
				{
					$observation = $successorSnapshot['observations'][$action['alias_observation']];
					$supporter = erasedataCleanupSuccessorObservation($observation['path'], $this->filesystem);
					if($supporter === false
						|| !erasedataCleanupSuccessorObservationMatches($observation['identity'], $supporter)
						|| erasedataExactFileAlias($current, $supporter) !== ERASEDATA_FILE_ALIAS_SAME)
					{
						$complete = false;
						$reason = 'unsafe-path';
						break;
					}
					continue;
				}
				$captured = $this->filesystem->entryIdentity($action['file']);
				if(!is_array($captured)
					|| !erasedataSameStatIdentity($captured['lstat'], $current['lstat'])
					|| !$this->cleanupCapturedFile($action['file'], $current,
						$action['expected'], $path, $manifest, $successorFiles,
						$successorSnapshot, $reason))
				{
					$complete = false;
					if($reason === null) $reason = 'unlink-failure';
					break;
				}
			}
		if($complete)
			foreach(erasedataCleanupParents($manifest) as $dir)
			{
				if(!$this->cleanupEmptyParent($dir, $path, $manifest, $reason))
				{
					$complete = false;
					break;
				}
			}
		if(!$complete)
		{
			$this->cleanupLog($hash, 'retained', ($reason === null ? 'unsafe-path' : $reason), $path, $jobKey);
			return(false);
		}
		$filesystem = $this->filesystem;
		$consumed = erasedataUnlinkExactStagedFile($path, $artifact['candidate']['stat'],
			function() use ($artifact, $token, $hash, $filesystem) {
				return(erasedataCleanupCommittedPairStillMatches($artifact, $token, $hash, $filesystem));
			}, $filesystem);
		if(!$consumed)
		{
			$this->cleanupLog($hash, 'retained', 'unreadable-manifest', $path, $jobKey);
			return(false);
		}
		$consumed = erasedataUnlinkExactStagedFile($token['candidate']['path'],
			$token['candidate']['stat'], function() use ($token, $filesystem) {
				return(erasedataCleanupTokenStillMatches($token, $filesystem));
			}, $filesystem);
		if(!$consumed)
		{
			$this->cleanupLog($hash, 'retained', 'unreadable-manifest', $path, $jobKey);
			return(false);
		}
		$this->cleanupLog($hash, 'complete', null, $path, $jobKey);
		return(true);
	}

	private function collectHashIndexed($listPath, $hash, $hashIndex, $index)
	{
		$lock = erasedataAcquireHashLock($listPath, $hash, !$this->blockingHashLocks);
		if($lock === false)
			return;
		if($index === null)
			$index = erasedataBuildCollectorIndex($listPath, $hash, $this->filesystem);
		if($hashIndex === null && is_array($index) && isset($index[$hash]))
			$hashIndex = $index[$hash];
		if(!is_array($hashIndex))
		{
			erasedataReleaseHashLock($lock);
			return;
		}

		$legacyItems = isset($hashIndex['legacy']) && is_array($hashIndex['legacy']) ? $hashIndex['legacy'] : array();
		// Refuse plaintext independent of rTorrent's current presence answer.
		$processable = array();
		foreach($legacyItems as $item)
			if($this->quarantinePlaintextCandidate($item) === null)
				$processable[] = $item;
		$legacyItems = $processable;
		if(count($legacyItems))
		{
			$presence = $this->probePresence($hash);
			$ownedPaths = null;
			if($presence === ERASEDATA_TORRENT_PRESENT)
				$ownedPaths = $this->collectPaths($hash);
			if($presence === ERASEDATA_TORRENT_UNKNOWN
				|| ($presence === ERASEDATA_TORRENT_PRESENT && $ownedPaths === false))
				// The whole job is retained on an answer nobody could read.
				// Unknown is not absence: it says so, once per job, at a level
				// the shipped configuration cannot silence.
				foreach($legacyItems as $item)
					$this->manifestLog($hash, $item['path'],
						$presence === ERASEDATA_TORRENT_UNKNOWN
							? 'rpc-unknown' : 'owned-paths-unknown');
			else
				foreach($legacyItems as $item)
				{
					$path = $item['path'];
					if(!is_file($path)) continue;
					if($item['type'] === 'tmp')
					{
						// An operation-mismatched v3 payload under a legacy-looking name
						// must remain untouched rather than entering the legacy promotion.
						$bytes = ErasedataManifestCodec::readBoundedFile($path);
						$decoded = is_string($bytes) ? ErasedataManifestCodec::decodeBytes($bytes, $hash) : false;
						$identity = $this->filesystem->entryIdentity($path);
						$current = is_array($identity) ? $identity['lstat'] : false;
						if(is_array($decoded) && isset($decoded['operation'])
							&& $decoded['operation'] !== $item['operation'] && is_array($current)
							&& erasedataSameStatIdentity($item['stat'], $current))
							continue;
						if(!ErasedataManifestCodec::publishStaging(
							$path, $hash, $this->filesystem, $this->retentionSink === null))
						{
							$this->manifestLog($hash, $path, 'publish-refused');
							continue;
						}
						$path = substr($path, 0, -4).'.list';
					}
					if(!$this->consumeManifest($path, $item['stat'], $ownedPaths))
						$this->manifestLog($hash, $path, 'incomplete');
				}
		}
		$cleanupItems = isset($hashIndex['cleanup']) && is_array($hashIndex['cleanup']) ? $hashIndex['cleanup'] : array();
		foreach($cleanupItems as $stem => $items)
		{
			$jobKey = $stem;
			$tmpItems = isset($items['tmp']) && is_array($items['tmp']) ? $items['tmp'] : array();
			$tokenItems = isset($items['list']) && is_array($items['list']) ? $items['list'] : array();
			$malformed = isset($items['malformed']) && is_array($items['malformed']) ? $items['malformed'] : array();
			$analysis = isset($items['analysis']) && is_array($items['analysis']) ? $items['analysis'] : array();
			if(!empty($analysis['duplicate']))
			{
				$this->cleanupLog($hash, 'retained', 'generation-mismatch', '', $jobKey);
				continue;
			}
			if(!count($tmpItems))
			{
				if(count($tokenItems) === 1 && !count($malformed))
				{
					$tokenReason = null;
					$token = erasedataReadExactCleanupToken($tokenItems[0], $tokenReason, $this->filesystem);
					if($token === false)
					{
						$this->cleanupLog($hash, 'retained', $tokenReason === null ? 'unreadable-manifest' : $tokenReason,
							$tokenItems[0]['path'], $jobKey);
						continue;
					}
					$filesystem = $this->filesystem;
					if(!erasedataRepairExactCleanupTokenMode($token['candidate']['path'], $token['candidate']['stat'])
						|| !erasedataCleanupTokenStillMatches($token, $filesystem)
						|| !erasedataUnlinkExactStagedFile($token['candidate']['path'], $token['candidate']['stat'],
							function() use ($token, $filesystem) {
								return(erasedataCleanupTokenStillMatches($token, $filesystem));
							}, $filesystem))
						$this->cleanupLog($hash, 'retained', 'unreadable-manifest', $token['candidate']['path'], $jobKey);
					else
						$this->cleanupLog($hash, 'complete', null, $token['candidate']['path'], $jobKey);
					continue;
				}
				if(count($tokenItems) || count($malformed))
					$this->cleanupLog($hash, 'retained', 'generation-mismatch', '', $jobKey);
				continue;
			}
			if(count($tmpItems) !== 1 || count($tokenItems) > 1 || count($malformed))
			{
				$this->cleanupLog($hash, 'retained', 'generation-mismatch', '', $jobKey);
				continue;
			}
			$tmpReason = null;
			$tmp = erasedataReadExactCleanupArtifact($tmpItems[0], $hash, $tmpReason, $this->filesystem);
			if($tmp === false)
			{
				$this->cleanupLog($hash, 'retained', $tmpReason === null ? 'unreadable-manifest' : $tmpReason,
					$tmpItems[0]['path'], $jobKey);
				continue;
			}
			if(isset($analysis['transaction_key']) && $analysis['transaction_key'] !== null
				&& erasedataCleanupTransactionKey($tmp['manifest']) !== $analysis['transaction_key'])
			{
				$this->cleanupLog($hash, 'retained', 'unreadable-manifest', $tmp['candidate']['path'], $jobKey);
				continue;
			}
			$token = null;
			if(count($tokenItems))
			{
				$tokenReason = null;
				$token = erasedataReadExactCleanupToken($tokenItems[0], $tokenReason, $this->filesystem);
				if($token === false || !erasedataCleanupCommittedPairStillMatches(
					$tmp, $token, $hash, $this->filesystem))
				{
					$this->cleanupLog($hash, 'retained', $tokenReason === null ? 'unreadable-manifest' : $tokenReason,
						$tmp['candidate']['path'], $jobKey);
					continue;
				}
			}
			else
			{
				$recoveryReason = null;
				if(erasedataRecoverObsoleteCleanupLocked($listPath, $hash, $tmp['manifest']['new_hash'], $tmp['manifest']['marker'],
					$tmp['manifest']['replacement_record'], $recoveryReason, $index, $this->filesystem) !== ERASEDATA_CLEANUP_READY)
				{
					$this->cleanupLog($hash, 'retained',
						$recoveryReason === null ? 'generation-mismatch' : $recoveryReason, $tmp['candidate']['path'], $jobKey);
					continue;
				}
				$tmpCandidate = erasedataParseCollectorCandidate($listPath, basename($tmp['candidate']['path']));
				$tokenCandidate = erasedataParseCollectorCandidate($listPath, basename(substr($tmp['candidate']['path'], 0, -4).'.list'));
				$recoveredReason = null;
				$tmp = $tmpCandidate === false ? false : erasedataReadExactCleanupArtifact(
					$tmpCandidate, $hash, $recoveredReason, $this->filesystem);
				$token = $tokenCandidate === false ? false : erasedataReadExactCleanupToken(
					$tokenCandidate, $recoveredReason, $this->filesystem);
				if($tmp === false || $token === false || !erasedataCleanupCommittedPairStillMatches(
					$tmp, $token, $hash, $this->filesystem))
				{
					$this->cleanupLog($hash, 'retained', 'unreadable-manifest', '', $jobKey);
					continue;
				}
			}
			$this->consumeCleanupManifest($tmp['candidate']['path'], $tmp['candidate']['stat'],
				$hash, $token, $jobKey);
		}
		// The lock file is deliberately persistent; only the descriptor is released.
		erasedataReleaseHashLock($lock);
	}

	public function run($listPath, $onlyHash = null)
	{
		$this->cleanupLogState = array('completed' => array(), 'retained' => array());
		$this->manifestLogState = array();
		// Seeded so the report below always names something real even if a
		// future refusal in there forgets to.
		$blocker = $listPath;
		if(!erasedataResumeCapturedEntries(
			$listPath, 'manifest-consumption', $this->filesystem, $blocker))
		{
			// This refusal stands in front of the whole queue rather than in
			// front of one job, and it does not heal by itself, so it goes out
			// through the channel $erasedebug_enabled cannot silence -- the one
			// the retained case already uses -- and it names the exact
			// directory to remove. The name is built from the settings path and
			// a reservation digest: no payload path, no credential, nothing to
			// redact.
			FileUtil::toLog('erasedata: no manifest was collected for any'
				.' torrent and no payload was deleted, because the leftover '
				.$blocker.' could not be resumed or removed; nothing in the'
				.' queue runs until that directory is removed by hand');
			return;
		}
		$index = erasedataBuildCollectorIndex($listPath, $onlyHash, $this->filesystem);
		if($index === false)
			return;
		foreach(array_keys($index) as $hash)
			$this->collectHashIndexed($listPath, $hash, $index[$hash], $index);
	}
}
