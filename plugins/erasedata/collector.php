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
	$identity = XMLRPCPathResolver::filesystemIdentity($data);
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

	$identity = XMLRPCPathResolver::filesystemIdentity($candidate);
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
	$current = XMLRPCPathResolver::filesystemIdentity($capture);
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
	$current = XMLRPCPathResolver::filesystemIdentity($capture);
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
	$expected = XMLRPCPathResolver::filesystemIdentity($path);
	if($expected === false || empty($expected['exists']) || !is_dir($path))
		return(false);
	$entries = $filesystem->scanDirectory($path);
	if($entries === false)
		return(false);
	if(count(array_diff($entries, array('.', '..'))) > 0)
		return(true);

	$current = XMLRPCPathResolver::filesystemIdentity($path);
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
	$reservedIdentity = XMLRPCPathResolver::filesystemIdentity($reserved);
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
	$expected = XMLRPCPathResolver::filesystemIdentity($path);
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
	$reservedIdentity = XMLRPCPathResolver::filesystemIdentity($reserved);
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

function erasedataCleanupCurrentIdentity($path)
{
	$identity = XMLRPCPathResolver::filesystemIdentity($path);
	if($identity === false || empty($identity['exists']))
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

function erasedataResumeCleanupCapturedTargets($manifest, $reservationKey,
	$successorSnapshot, ErasedataFilesystemOps $filesystem)
{
	if(!is_array($successorSnapshot)
		|| !isset($successorSnapshot['observations'], $successorSnapshot['by_identity'])
		|| !is_array($successorSnapshot['observations'])
		|| !is_array($successorSnapshot['by_identity']))
		return(false);
	$parents = array();
	$targets = array();
	$observedRoots = array();
	$captureParents = array();
	foreach($manifest['files'] as $file)
	{
		if(!erasedataPathContains($manifest['base'], $file)
			|| $file === $manifest['base'])
			return(false);
		$parent = dirname($file);
		$parents["p\0".$parent] = $parent;
		$targets["p\0".$file] = $file;
	}
	foreach($parents as $parent)
	{
		$identity = $filesystem->entryIdentity($parent);
		if($identity === false)
		{
			if(erasedataPathExists($parent))
				return(false);
			continue;
		}
		$entries = $filesystem->scanDirectory($parent);
		if($entries === false)
			return(false);
		foreach($entries as $entry)
		{
			if(strpos($entry, '.erasedata-entry-') !== 0)
				continue;
			$root = $parent.'/'.$entry;
			$name = erasedataCapturedEntryName($root);
			if($name === false)
				return(false);
			$candidate = $parent.'/'.$name;
			if(strpos($root,
				erasedataCapturedEntryPrefix($candidate, $reservationKey)) === 0)
			{
				$targetKey = "p\0".$candidate;
				if(!isset($targets[$targetKey]))
					return(false);
				if(!isset($observedRoots[$targetKey]))
					$observedRoots[$targetKey] = array();
				$observedRoots[$targetKey][] = $root;
				$captureParents["p\0".$parent] = $parent;
			}
		}
	}
	$hasCaptures = false;
	foreach($targets as $file)
	{
		$roots = erasedataCapturedEntryRoots(
			$file, $reservationKey, $filesystem);
		$targetKey = "p\0".$file;
		$observed = isset($observedRoots[$targetKey])
			? $observedRoots[$targetKey] : array();
		if($roots === false || count($roots) > 1
			|| count($roots) !== count($observed)
			|| (count($roots) === 1 && $roots[0] !== $observed[0]))
			return(false);
		if(!count($roots))
			continue;
		$hasCaptures = true;
		$info = erasedataCapturedEntryRootInfo(
			$roots[0], $file, $reservationKey, $filesystem);
		if($info === false)
			return(false);
		$entryIdentity = $filesystem->entryIdentity($info['entry']);
		if($entryIdentity === false)
		{
			if(erasedataPathExists($info['entry']))
				return(false);
			continue;
		}
		if(erasedataEntryIdentityParts($entryIdentity) !== $info['identity']
			|| (empty($entryIdentity['is_file']) && empty($entryIdentity['is_link'])))
			return(false);
		$targetIdentity = $filesystem->targetIdentity($info['entry']);
		if($targetIdentity === false)
		{
			if(empty($entryIdentity['is_link']))
				return(false);
			continue;
		}
		if(empty($targetIdentity['is_file'])
			|| !isset($entryIdentity['lstat'], $targetIdentity['stat']))
			return(false);
		$capturedIdentity = array(
			'exists' => true,
			'path' => $info['entry'],
			'lstat' => $entryIdentity['lstat'],
			'stat' => $targetIdentity['stat'],
		);
		$key = erasedataCleanupExactIdentityKey($capturedIdentity);
		if($key === false)
			return(false);
		if(isset($successorSnapshot['by_identity'][$key]))
			foreach($successorSnapshot['by_identity'][$key] as $observationKey)
			{
				if(!isset($successorSnapshot['observations'][$observationKey]['identity'])
					|| erasedataExactFileAlias($capturedIdentity,
						$successorSnapshot['observations'][$observationKey]['identity'])
					!== ERASEDATA_FILE_ALIAS_DISTINCT)
					return(false);
			}
	}
	if(!$hasCaptures)
		return(true);
	if(!erasedataCleanupSuccessorSnapshotStillMatches($successorSnapshot, $filesystem))
		return(false);
	foreach($captureParents as $parent)
		if(!erasedataResumeCapturedEntries(
				$parent, $reservationKey, $filesystem))
			return(false);
	return(true);
}

final class ErasedataCollector
{
	private $filesystem;
	private $presenceProbe;
	private $pathCollector;
	private $logger;
	private $enableForceDeletion;
	private $cleanupLogState = array('completed' => array(), 'retained' => array());

	public function __construct(ErasedataFilesystemOps $filesystem, $presenceProbe,
		$pathCollector, $logger, $enableForceDeletion)
	{
		$this->filesystem = $filesystem;
		$this->presenceProbe = $presenceProbe;
		$this->pathCollector = $pathCollector;
		$this->logger = $logger;
		$this->enableForceDeletion = $enableForceDeletion;
	}

	private function log($str)
	{
		if(is_callable($this->logger))
			call_user_func($this->logger, $str);
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
		$force_delete = ($manifest['force'] === 2 && empty($manifest['legacy']))
			&& $this->enableForceDeletion;
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
					$identity = XMLRPCPathResolver::filesystemIdentity($file);
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
							if(!empty($manifest['legacy']) && $manifest['force'] === 2)
								$complete = false;
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
		$manifest = ErasedataManifestCodec::decodeStream($handle, $hash);
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
		FileUtil::toLog('erasedata: cleanup retained '.$hash.' '.$reason);
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
		if(!erasedataResumeCleanupCapturedTargets(
			$manifest, $path, $successorSnapshot, $this->filesystem))
		{
			$this->cleanupLog($hash, 'retained', 'unlink-failure', $path, $jobKey);
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
					|| !$this->filesystem->unlinkCapturedEntry(
						$action['file'], $captured, $path))
				{
					$complete = false;
					$reason = 'unlink-failure';
					break;
				}
			}
		if($complete)
			foreach(erasedataCleanupParents($manifest) as $dir)
				if(!erasedataCompleteNonForceDirectory($dir, $path, $this->filesystem))
				{
					$complete = false;
					$reason = 'rmdir-failure';
					break;
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
		$lock = erasedataAcquireHashLock($listPath, $hash, true);
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
		if(count($legacyItems))
		{
			$presence = $this->probePresence($hash);
			$ownedPaths = null;
			if($presence === ERASEDATA_TORRENT_PRESENT)
				$ownedPaths = $this->collectPaths($hash);
			if($presence !== ERASEDATA_TORRENT_UNKNOWN && !($presence === ERASEDATA_TORRENT_PRESENT && $ownedPaths === false))
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
							$path, $hash, $this->filesystem))
							continue;
						$path = substr($path, 0, -4).'.list';
					}
					$this->consumeManifest($path, $item['stat'], $ownedPaths);
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
					$tmp['manifest']['replacement_record'], $recoveryReason, $index) !== ERASEDATA_CLEANUP_READY)
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
