<?php

if( count( $argv ) > 1 )
	$_SERVER['REMOTE_USER'] = $argv[1];

if(!class_exists('rXMLRPCRequest'))
	require_once( dirname(__FILE__)."/../../php/xmlrpc.php" );
require_once( dirname(__FILE__)."/filesystem.php" );
require_once( dirname(__FILE__)."/manifest.php" );
require_once( dirname(__FILE__)."/removewithdata.php" );
eval(FileUtil::getPluginConf('erasedata'));

function eLog( $str )
{
	global $erasedebug_enabled;
	if($erasedebug_enabled)
		FileUtil::toLog( "erasedata: ".$str );
}

function sortByLevel( $a, $b )
{
	return( strrpos($b,"/")-strrpos($a,"/") );
}

function erasedataPathExists($path)
{
	clearstatcache(true, $path);
	return(file_exists($path) || is_link($path));
}

function erasedataSamePathIdentity($expected, $current)
{
	return(erasedataSameFilesystemEntry($expected, $current)
		&& $expected['path'] === $current['path']);
}

function erasedataOwnedPathCandidates($ownedPaths)
{
	if(!is_array($ownedPaths)) return(array());
	$ret = isset($ownedPaths['files']) && is_array($ownedPaths['files'])
		? $ownedPaths['files'] : array();
	if(isset($ownedPaths['base']) && is_string($ownedPaths['base']))
		$ret[] = $ownedPaths['base'];
	return(array_values(array_unique($ret)));
}

function erasedataFileTouchesOwnedPath($path, $ownedPaths)
{
	foreach(erasedataOwnedPathCandidates($ownedPaths) as $owned)
		if(erasedataPathsOverlap($path, $owned))
			return(true);
	return(false);
}

function erasedataDirectoryTouchesOwnedPath($path, $ownedPaths)
{
	foreach(erasedataOwnedPathCandidates($ownedPaths) as $candidate)
		if(erasedataPathsOverlap($path, $candidate))
			return(true);
	return(false);
}

function erasedataUnlinkSamePath($path, $expectedIdentity, $reservationKey,
	ErasedataFilesystemOps $filesystem)
{
	return(erasedataDeleteCapturedEntry(
		$path, $expectedIdentity, $reservationKey, $filesystem));
}

if(!function_exists('erasedataDirectoryRemovalOverride'))
{
	// Deterministic fail-first seam; null executes the real inline rmdir().
	function erasedataDirectoryRemovalOverride($path, $reserved)
	{
		return(null);
	}
}

if(!function_exists('erasedataBeforeReserveDirectory'))
{
	// Deterministic stat-to-rename race seam; production continues immediately.
	function erasedataBeforeReserveDirectory($path)
	{
		return(true);
	}
}

if(!function_exists('erasedataBeforeRestoreDirectory'))
{
	// Deterministic recovery-collision seam; production continues immediately.
	function erasedataBeforeRestoreDirectory($reserved, $path)
	{
		return(true);
	}
}

if(!function_exists('erasedataBeforeDeleteRecoveryDirectory'))
{
	// Deterministic recovery-target replacement seam; production continues.
	function erasedataBeforeDeleteRecoveryDirectory($path)
	{
		return(true);
	}
}

if(!function_exists('erasedataBeforeCreateRecoveryCapture'))
{
	// Deterministic capture-directory collision seam; production continues.
	function erasedataBeforeCreateRecoveryCapture($path)
	{
		return(true);
	}
}

if(!function_exists('erasedataAfterDeleteRecoveryDirectory'))
{
	// Deterministic post-delete recreation seam; production continues.
	function erasedataAfterDeleteRecoveryDirectory($path)
	{
		return(true);
	}
}

if(!function_exists('erasedataBeforeTraverseCapturedRecoveryDirectory'))
{
	// Legacy forced-recovery characterization seam; production continues.
	function erasedataBeforeTraverseCapturedRecoveryDirectory($path)
	{
		return(true);
	}
}

if(!function_exists('erasedataAfterUnlinkRecoveryTombstone'))
{
	function erasedataAfterUnlinkRecoveryTombstone($path)
	{
		return(true);
	}
}

if(!function_exists('erasedataAfterUnlinkRecoveryBridge'))
{
	function erasedataAfterUnlinkRecoveryBridge($path)
	{
		return(true);
	}
}

if(!function_exists('erasedataAfterRemoveRecoveryContainer'))
{
	function erasedataAfterRemoveRecoveryContainer($path)
	{
		return(true);
	}
}

if(!function_exists('erasedataAfterCreateDirectoryReservation'))
{
	function erasedataAfterCreateDirectoryReservation($path)
	{
		return(true);
	}
}

if(!function_exists('erasedataAfterInitializeDirectoryReservation'))
{
	function erasedataAfterInitializeDirectoryReservation($path)
	{
		return(true);
	}
}

if(!function_exists('erasedataAfterCreateRecoveryCapture'))
{
	function erasedataAfterCreateRecoveryCapture($path)
	{
		return(true);
	}
}

if(!function_exists('erasedataAfterInitializeRecoveryCapture'))
{
	function erasedataAfterInitializeRecoveryCapture($path)
	{
		return(true);
	}
}

if(!function_exists('erasedataContainerRemovalOverride'))
{
	// Deterministic fail-first seam; null executes the real inline rmdir().
	function erasedataContainerRemovalOverride($path)
	{
		return(null);
	}
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

function erasedataCreatePrivateMarker($root)
{
	$handle = @fopen(erasedataPrivateMarkerPath($root), 'x');
	if($handle === false)
		return(false);
	$closed = @fclose($handle);
	@chmod(erasedataPrivateMarkerPath($root), 0600);
	return($closed);
}

function erasedataPrivateMarkerIsValid($root)
{
	$marker = erasedataPrivateMarkerPath($root);
	return(is_file($marker) && !is_link($marker));
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
			$roots[] = $parent.'/'.$entry;
	return($roots);
}

function erasedataCapturedEntryName($root)
{
	$namePath = erasedataCapturedEntryNamePath($root);
	if(!erasedataPrivateMarkerIsValid($root)
		|| !is_file($namePath) || is_link($namePath))
		return(false);
	$encoded = @file_get_contents($namePath);
	if(!is_string($encoded) || strlen($encoded) === 0 || strlen($encoded) > 4096)
		return(false);
	$name = base64_decode($encoded, true);
	if($name === false || base64_encode($name) !== $encoded || $name === ''
		|| $name === '.' || $name === '..' || strpos($name, '/') !== false
		|| strpos($name, "\0") !== false)
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
	if(!$filesystem->mkdir($root, 0700))
		return(false);
	$namePath = erasedataCapturedEntryNamePath($root);
	$name = base64_encode(basename($path));
	$written = @file_put_contents($namePath, $name, LOCK_EX);
	@chmod($namePath, 0600);
	if($written !== strlen($name) || !erasedataCreatePrivateMarker($root))
	{
		$filesystem->unlink($namePath);
		$filesystem->unlink(erasedataPrivateMarkerPath($root));
		$filesystem->rmdir($root);
		return(false);
	}
	return($root);
}

function erasedataCapturedEntryBridgeMatches($path, $entry,
	ErasedataFilesystemOps $filesystem)
{
	$target = basename(dirname($entry)).'/'.basename($entry);
	return(is_link($path) && $filesystem->readlink($path) === $target);
}

function erasedataPublishCapturedEntryBridge($path, $entry,
	ErasedataFilesystemOps $filesystem)
{
	if(erasedataCapturedEntryBridgeMatches($path, $entry, $filesystem))
		return(true);
	$target = basename(dirname($entry)).'/'.basename($entry);
	if(erasedataPathExists($path) || !$filesystem->symlink($target, $path))
		return(false);
	return(erasedataCapturedEntryBridgeMatches($path, $entry, $filesystem));
}

function erasedataRemoveCapturedEntryBridge($path, $entry, $root,
	ErasedataFilesystemOps $filesystem)
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
			|| $filesystem->readlink($path) !== $target)
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
		|| $filesystem->readlink($tombstone) !== $target)
	{
		if(!erasedataPathExists($path))
			$filesystem->symlink(
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
	return($filesystem->rmdir($root) || !erasedataPathExists($root));
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
			|| !$filesystem->rmdir($info['entry']))
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

function erasedataResumeCapturedEntries($parent, $reservationKey,
	ErasedataFilesystemOps $filesystem)
{
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
		$path = $parent.'/'.$name;
		if(strpos($root, erasedataCapturedEntryPrefix($path, $reservationKey)) !== 0)
			continue;
		if(!erasedataDeleteCapturedEntry($path, null, $reservationKey, $filesystem))
			return(false);
	}
	return(true);
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

function erasedataRemoveReservationContainer($reserved, $path, $reservationKey,
	ErasedataFilesystemOps $filesystem)
{
	if(erasedataReservationEncodedIdentity($reserved, $path, $reservationKey) === false)
		return(false);
	$entries = $filesystem->scanDirectory($reserved);
	$marker = erasedataPrivateMarkerPath($reserved);
	if($entries === false || count(array_diff(
		$entries, array('.', '..', basename($marker)))) > 0)
		return(false);
	if(erasedataPathExists($marker)
		&& (!erasedataPrivateMarkerIsValid($reserved) || !$filesystem->unlink($marker)))
		return(false);
	$override = erasedataContainerRemovalOverride($reserved);
	return(!erasedataPathExists($reserved)
		|| (is_null($override) ? @rmdir($reserved) : (bool)$override));
}

function erasedataReservationLinkMatches($path, $reserved)
{
	return(is_link($path) && @readlink($path) === $reserved);
}

function erasedataPublishReservationLink($reserved, $path)
{
	if(erasedataReservationLinkMatches($path, $reserved))
		return(true);
	if(erasedataPathExists($path) || !erasedataBeforeRestoreDirectory($reserved, $path))
		return(false);
	// symlink() is the portable PHP 7.4 filesystem primitive that creates the
	// original name only when it is still absent. Unlike rename(), it cannot
	// replace a concurrently created empty directory.
	if(!@symlink($reserved, $path))
		return(false);
	return(erasedataReservationLinkMatches($path, $reserved));
}

function erasedataDropReservationLink($path, $reserved, $reservationKey,
	ErasedataFilesystemOps $filesystem)
{
	if(!erasedataReservationLinkMatches($path, $reserved))
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
	$target = $filesystem->readlink($path);
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
			$override = erasedataContainerRemovalOverride($reservationRoot);
			if(!(is_null($override) ? @rmdir($reservationRoot) : (bool)$override))
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
			$override = erasedataContainerRemovalOverride($captureRoot);
			if(!(is_null($override) ? @rmdir($captureRoot) : (bool)$override))
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
		if(@readlink($target) !== $capture)
			return(array('safe'=>false, 'linkTarget'=>$target) + $layout);
		$captured = true;
		$candidate = $capture;
		$targetExists = false;
	}
	if($captured || !$targetExists)
	{
		if($captureExists && is_link($capture) && @readlink($capture) === $deleted)
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

function erasedataUnlinkRecoveryLink($path, $target, $reservationKey,
	ErasedataFilesystemOps $filesystem)
{
	$expected = $filesystem->entryIdentity($path);
	if(!is_array($expected))
		return(!erasedataPathExists($path));
	if(empty($expected['is_link']) || $filesystem->readlink($path) !== $target)
		return(false);
	return(erasedataDeleteCapturedEntry(
		$path, $expected, $reservationKey, $filesystem));
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
		if(count($roots) === 1 && !erasedataDeleteCapturedEntry(
			$linkLayout['target'], null, $reservationKey, $filesystem, true))
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
	if(!erasedataDeleteCapturedEntry(
		$target, $capturedIdentity, $reservationKey, $filesystem, true))
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
		if(!erasedataBeforeCreateRecoveryCapture($captureRoot)
			|| !$filesystem->mkdir($captureRoot, 0700))
			return(false);
		erasedataAfterCreateRecoveryCapture($captureRoot);
		if(!erasedataCreatePrivateMarker($captureRoot))
		{
			$filesystem->rmdir($captureRoot);
			return(false);
		}
		erasedataAfterInitializeRecoveryCapture($captureRoot);
	}
	if(erasedataPathExists($capture)
		|| !erasedataBeforeDeleteRecoveryDirectory($target)
		|| !$filesystem->rename($target, $capture))
		return(false);
	// The rename captures one directory name atomically; only the inode that was
	// validated through the recovery link may cross into recursive deletion.
	$current = XMLRPCPathResolver::filesystemIdentity($capture);
	if(!erasedataSameFilesystemEntry($recovery['identity'], $current))
	{
		if(!erasedataPathExists($target))
			@symlink($capture, $target);
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
	if(!is_link($path) || @readlink($path) !== $target)
		return(false);
	return(erasedataUnlinkRecoveryLink(
		$path, $target, $reservationKey, $filesystem));
}

function erasedataRemoveRecoveryReservationContainer($recovery,
	ErasedataFilesystemOps $filesystem)
{
	if(empty($recovery['reservationRoot']))
		return(true);
	if(!erasedataPathExists($recovery['reservationRoot']))
		return(true);
	$entries = $filesystem->scanDirectory($recovery['reservationRoot']);
	$marker = erasedataPrivateMarkerPath($recovery['reservationRoot']);
	if($entries === false || count(array_diff(
		$entries, array('.', '..', basename($marker)))) > 0)
		return(false);
	if(erasedataPathExists($marker)
		&& (!erasedataPrivateMarkerIsValid($recovery['reservationRoot'])
			|| !$filesystem->unlink($marker)))
		return(false);
	$override = erasedataContainerRemovalOverride($recovery['reservationRoot']);
	return(is_null($override) ? @rmdir($recovery['reservationRoot']) : (bool)$override);
}

function erasedataRemoveRecoveryCaptureContainer($recovery,
	ErasedataFilesystemOps $filesystem)
{
	if(!erasedataPathExists($recovery['captureRoot']))
		return(true);
	$entries = $filesystem->scanDirectory($recovery['captureRoot']);
	$marker = erasedataPrivateMarkerPath($recovery['captureRoot']);
	if($entries === false || count(array_diff(
		$entries, array('.', '..', basename($marker)))) > 0)
		return(false);
	if(erasedataPathExists($marker)
		&& (!erasedataPrivateMarkerIsValid($recovery['captureRoot'])
			|| !$filesystem->unlink($marker)))
		return(false);
	$override = erasedataContainerRemovalOverride($recovery['captureRoot']);
	return(is_null($override) ? @rmdir($recovery['captureRoot']) : (bool)$override);
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
		erasedataAfterUnlinkRecoveryTombstone($recovery['path']);
		if(erasedataPathExists($recovery['path'])
			|| !erasedataRemoveExactLinkOrAbsent(
				$target, $recovery['path'], $reservationKey, $filesystem))
			return(false);
		erasedataAfterUnlinkRecoveryBridge($target);
		if(erasedataPathExists($target))
			return(false);
		if(!erasedataRemoveRecoveryCaptureContainer($recovery, $filesystem))
			return(false);
		if(!erasedataRemoveRecoveryReservationContainer($recovery, $filesystem))
			return(false);
		erasedataAfterRemoveRecoveryContainer($recovery['reservationRoot']);
		return(erasedataUnlinkRecoveryLink(
			$path, $target, $reservationKey, $filesystem));
	}

	$captured = erasedataCaptureRecoveryDirectory($recovery, $filesystem);
	if($captured === false)
		return(false);
	$capture = $captured['path'];
	if(!erasedataReservationLinkMatches($target, $capture))
	{
		if(erasedataPathExists($target) || !@symlink($capture, $target))
			return(false);
	}
	$reference = erasedataOpenDirectoryReference($capture, $captured['identity'], $filesystem);
	if($reference === false)
		return(false);
	erasedataBeforeTraverseCapturedRecoveryDirectory($capture);
	$deleted = erasedataDeleteDirectoryReferenceContents(
		$reference, $reservationKey, $filesystem);
	erasedataCloseDirectoryReference($reference, $filesystem);
	$current = XMLRPCPathResolver::filesystemIdentity($capture);
	if(!$deleted || !erasedataSameFilesystemEntry($captured['identity'], $current)
		|| !$filesystem->rmdir($capture) || erasedataPathExists($capture))
		return(false);
	erasedataAfterDeleteRecoveryDirectory($capture);
	// Occupy the just-deleted name before unlinking the visible chain. A
	// concurrent recreation therefore keeps the chain and manifest intact.
	if(!@symlink($captured['deleted'], $capture))
		return(false);

	if(!erasedataRemoveExactLinkOrAbsent(
		$capture, $captured['deleted'], $reservationKey, $filesystem))
		return(false);
	erasedataAfterUnlinkRecoveryTombstone($capture);
	if(erasedataPathExists($capture)
		|| !erasedataRemoveExactLinkOrAbsent(
			$target, $capture, $reservationKey, $filesystem))
		return(false);
	erasedataAfterUnlinkRecoveryBridge($target);
	if(erasedataPathExists($target))
		return(false);
	if(!erasedataRemoveRecoveryCaptureContainer($captured, $filesystem))
		return(false);
	if(!erasedataRemoveRecoveryReservationContainer($captured, $filesystem))
		return(false);
	erasedataAfterRemoveRecoveryContainer($captured['reservationRoot']);
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
	$restoredLink = erasedataReservationLinkMatches($path, $reserved);
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
			erasedataPublishReservationLink($reserved, $path);
		return(false);
	}
	if(count(array_diff($entries, array('.', '..'))) > 0)
		return($restoredLink || erasedataPublishReservationLink($reserved, $path));

	$override = erasedataDirectoryRemovalOverride($path, $reserved);
	if(!erasedataReservationHasEncodedIdentity($reservation, $path, $reservationKey))
		return(false);
	if(is_null($override) && $restoredLink
		&& !erasedataDropReservationLink(
			$path, $reserved, $reservationKey, $filesystem))
		return(false);
	$removed = is_null($override) ? @rmdir($reserved) : (bool)$override;
	if($removed || !erasedataPathExists($reserved))
		return(erasedataRemoveReservationContainer(
			$reservation, $path, $reservationKey, $filesystem));
	if(!erasedataReservationLinkMatches($path, $reserved))
		erasedataPublishReservationLink($reserved, $path);
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
	if(!erasedataSamePathIdentity($expected, $current)
		|| !erasedataBeforeReserveDirectory($path))
		return(false);

	// Rename atomically captures one checked directory entry. A concurrent
	// replacement at the original name is never passed to rmdir().
	$reservation = erasedataDirectoryReservationPath($path, $reservationKey, $expected);
	if($reservation === false || !@mkdir($reservation, 0700))
		return(false);
	erasedataAfterCreateDirectoryReservation($reservation);
	if(!erasedataCreatePrivateMarker($reservation))
	{
		@rmdir($reservation);
		return(false);
	}
	erasedataAfterInitializeDirectoryReservation($reservation);
	$reserved = erasedataReservationDataPath($reservation);
	if(!$filesystem->rename($path, $reserved))
	{
		$filesystem->unlink(erasedataPrivateMarkerPath($reservation));
		@rmdir($reservation);
		return(false);
	}
	$override = erasedataDirectoryRemovalOverride($path, $reserved);
	$reservedIdentity = XMLRPCPathResolver::filesystemIdentity($reserved);
	if(!erasedataSameFilesystemEntry($expected, $reservedIdentity))
	{
		// Publish only through the no-replace link helper. If another directory
		// owns the original name, both objects and the manifest remain untouched.
		erasedataPublishReservationLink($reserved, $path);
		return(false);
	}
	$removed = is_null($override) ? @rmdir($reserved) : (bool)$override;
	if($removed || !erasedataPathExists($reserved))
		return(erasedataRemoveReservationContainer(
			$reservation, $path, $reservationKey, $filesystem));

	// Restore a failed removal to the manifest's exact path. A nonempty
	// directory contains unrelated data and completes only after restoration;
	// an empty or ambiguous directory remains a retry obligation.
	$after = $filesystem->scanDirectory($reserved);
	$hasUnrelated = is_array($after)
		&& count(array_diff($after, array('.', '..'))) > 0;
	if(!erasedataPublishReservationLink($reserved, $path))
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
		$restoredLink = erasedataReservationLinkMatches($path, $reserved);
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
		if(!$restoredLink && !erasedataPublishReservationLink($reserved, $path))
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
		return(erasedataUnlinkSamePath(
			$path, $expected, $reservationKey, $filesystem));
	}
	$expected = XMLRPCPathResolver::filesystemIdentity($path);
	if($expected === false || empty($expected['exists']) || !is_dir($path))
		return(false);

	$recovery = erasedataRecoveryLinkTarget($path, $filesystem);
	if($recovery !== false)
		return(erasedataDeleteRecoveryDirectory(
			$path, $recovery, $reservationKey, $filesystem));

	$reservation = erasedataDirectoryReservationPath($path, $reservationKey, $expected);
	if($reservation === false || !@mkdir($reservation, 0700))
		return(false);
	erasedataAfterCreateDirectoryReservation($reservation);
	if(!erasedataCreatePrivateMarker($reservation))
	{
		@rmdir($reservation);
		return(false);
	}
	erasedataAfterInitializeDirectoryReservation($reservation);
	$reserved = erasedataReservationDataPath($reservation);
	if(!$filesystem->rename($path, $reserved))
	{
		$filesystem->unlink(erasedataPrivateMarkerPath($reservation));
		@rmdir($reservation);
		return(false);
	}
	$reservedIdentity = XMLRPCPathResolver::filesystemIdentity($reserved);
	if(!erasedataSameFilesystemEntry($expected, $reservedIdentity))
	{
		erasedataPublishReservationLink($reserved, $path);
		return(false);
	}
	if(!erasedataPublishReservationLink($reserved, $path))
		return(false);

	$recovery = erasedataRecoveryLinkTarget($path, $filesystem);
	if($recovery === false)
		return(false);
	return(erasedataDeleteRecoveryDirectory(
		$path, $recovery, $reservationKey, $filesystem));
}

function parseOneItem($item, $manifest, $ownedPaths, ErasedataFilesystemOps $filesystem)
{
	global $enableForceDeletion;
	eLog('*** Parse item '.$item);
	if(is_null($manifest))
	{
		$hash = preg_match('/^([0-9A-Fa-f]{40})/D', basename($item), $m) ? $m[1] : '';
		$manifest = ErasedataManifestCodec::decodeBytes(@file_get_contents($item), $hash);
	}
	else if(is_array($manifest) && !isset($manifest['version']))
	{
		$hash = preg_match('/^([0-9A-Fa-f]{40})/D', basename($item), $m) ? $m[1] : '';
		$manifest = ErasedataManifestCodec::decodeBytes(implode("\n", $manifest)."\n", $hash);
	}
	if($manifest === false || !is_array($manifest))
		return(false);

	$dirs = array();
	$complete = true;
	$force_delete = ($manifest['force'] === 2 && empty($manifest['legacy'])) && $enableForceDeletion;
	$is_multi = !empty($manifest['multi']);
	$base_path = $manifest['base'];
	$files = $manifest['files'];

	if(!$force_delete || !$is_multi)
	{
		foreach($files as $file)
		{
			$entry = $filesystem->entryIdentity($file);
			if(!is_array($entry))
			{
				if(erasedataPathExists($file))
				{
					eLog('Retain unresolved file '.$file);
					$complete = false;
				}
				else if(erasedataDeleteCapturedEntry(
					$file, null, $item, $filesystem))
					eLog('Successfully delete file '.$file);
				else
				{
					eLog('FAIL resume captured file '.$file);
					$complete = false;
				}
			}
			else if(!empty($entry['is_link']))
			{
				if(erasedataFileTouchesOwnedPath($file, $ownedPaths))
				{
					eLog('Retain active file '.$file);
					$complete = false;
				}
				else
				{
					if(erasedataUnlinkSamePath($file, $entry, $item, $filesystem))
						eLog('Successfully delete file '.$file);
					else
					{
						eLog('FAIL Delete file '.$file);
						$complete = false;
					}
				}
			}
			else
			{
				$identity = XMLRPCPathResolver::filesystemIdentity($file);
				if($identity === false)
				{
					eLog('Retain unresolved file '.$file);
					$complete = false;
				}
				else if(empty($identity['exists']))
				{
					eLog('Retain identity-changed file '.$file);
					$complete = false;
				}
				else if(erasedataFileTouchesOwnedPath($file, $ownedPaths))
				{
					eLog('Retain active file '.$file);
					$complete = false;
				}
				else if(!empty($entry['is_dir']))
				{
					eLog('Retain directory in file manifest '.$file);
					$complete = false;
				}
				else
				{
					if(erasedataUnlinkSamePath($file, $entry, $item, $filesystem))
						eLog('Successfully delete file '.$file);
					else
					{
						eLog('FAIL Delete file '.$file);
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
			if(erasedataDirectoryTouchesOwnedPath($base_path, $ownedPaths))
			{
				eLog('Retain active forced directory '.$base_path);
				$complete = false;
			}
			else
			{
				$existed = erasedataPathExists($base_path);
				if(erasedataCompleteForcedDirectory($base_path, $item, $filesystem))
				{
					if($existed && erasedataPathExists($base_path))
						eLog('Leave unrelated dir '.$base_path);
					else
						eLog('Successfully forced delete dir '.$base_path);
				}
				else
				{
					eLog('FAIL force delete dir '.$base_path);
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
				if(erasedataDirectoryTouchesOwnedPath($dir, $ownedPaths))
				{
					eLog('Retain active dir '.$dir);
					$complete = false;
				}
				else
				{
					$existed = erasedataPathExists($dir);
					if(erasedataCompleteNonForceDirectory($dir, $item, $filesystem))
					{
						if($existed && erasedataPathExists($dir))
							eLog('Leave unrelated dir '.$dir);
						else
							eLog('Successfully delete dir '.$dir);
					}
					else
					{
						eLog('FAIL delete dir '.$dir);
						$complete = false;
					}
				}
			}
			if(erasedataDirectoryTouchesOwnedPath($base_path, $ownedPaths))
			{
				eLog('Retain active dir '.$base_path);
				$complete = false;
			}
			else
			{
				$existed = erasedataPathExists($base_path);
				if(erasedataCompleteNonForceDirectory($base_path, $item, $filesystem))
				{
					if($existed && erasedataPathExists($base_path))
					{
						eLog('Leave unrelated dir '.$base_path);
						if(!empty($manifest['legacy']) && $manifest['force'] === 2)
							$complete = false;
					}
					else
						eLog('Successfully delete dir '.$base_path);
				}
				else
				{
					eLog('FAIL delete dir '.$base_path);
					$complete = false;
				}
			}
		}
	}
	return($complete);
}

function erasedataCollectorCandidates($listPath, ErasedataFilesystemOps $filesystem,
	$onlyHash = null)
{
	$ret = array();
	if(!erasedataResumeCapturedEntries(
		$listPath, 'manifest-consumption', $filesystem))
		return($ret);
	if(!($handle = @opendir($listPath)))
		return($ret);
	while(false !== ($file = readdir($handle)))
	{
		if(!preg_match('/^([0-9A-Fa-f]{40})(?:\.[0-9]+\.[A-Za-z0-9._-]+)?\.(list|tmp)$/D', $file, $matches))
			continue;
		$hash = strtoupper($matches[1]);
		if(!is_null($onlyHash) && $hash !== $onlyHash)
			continue;
		$path = $listPath.'/'.$file;
		if(is_file($path) && !is_link($path))
		{
			$identity = $filesystem->entryIdentity($path);
			if(is_array($identity))
				$ret[$hash][] = array(
					'path'=>$path, 'type'=>$matches[2], 'stat'=>$identity['lstat']);
		}
	}
	closedir($handle);
	return($ret);
}

function erasedataUnlinkSameFile($path, $expectedStat,
	ErasedataFilesystemOps $filesystem)
{
	if(is_null($expectedStat))
	{
		$handle = @fopen($path, 'r');
		if($handle === false)
			return(false);
		$expectedStat = @fstat($handle);
	}
	else
		$handle = null;
	$ret = is_array($expectedStat) && erasedataDeleteCapturedEntry(
		$path, $expectedStat, 'manifest-consumption', $filesystem);
	if(is_resource($handle))
		@fclose($handle);
	return($ret);
}

function erasedataConsumeManifest($path, $expectedStat, $ownedPaths,
	ErasedataFilesystemOps $filesystem)
{
	$handle = @fopen($path, 'r');
	if($handle === false)
		return(false);
	$stat = @fstat($handle);
	$pathIdentity = $filesystem->entryIdentity($path);
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
	$complete = parseOneItem($path, $manifest, $ownedPaths, $filesystem);
	$ret = $complete ? erasedataUnlinkSameFile($path, $stat, $filesystem) : false;
	@fclose($handle);
	return($ret);
}

function erasedataReadCleanupManifest($path, $expectedStat, $hash, &$artifact = null)
{
	$artifact = null;
	$candidate = erasedataParseCollectorCandidate(dirname($path), basename($path));
	if($candidate === false || $candidate['operation'] !== ErasedataManifestCodec::OPERATION_CLEANUP_OBSOLETE
		|| $candidate['type'] !== 'tmp' || $candidate['path'] !== $path
		|| !is_array($expectedStat) || !erasedataSameStatIdentity($candidate['stat'], $expectedStat))
		return(false);
	$read = erasedataReadExactCleanupArtifact($candidate, $hash);
	if($read === false || !erasedataSameStatIdentity($read['candidate']['stat'], $expectedStat))
		return(false);
	$artifact = $read;
	return($read['manifest']);
}

function erasedataCleanupSuccessorPaths($newHash)
{
	$presence = erasedataTorrentPresence($newHash);
	if($presence === ERASEDATA_TORRENT_ABSENT)
		return(array());
	if($presence !== ERASEDATA_TORRENT_PRESENT)
		return(false);
	$paths = erasedataCollectPaths($newHash, true);
	return($paths === false || !isset($paths['files']) || !is_array($paths['files']) ? false : $paths['files']);
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

function erasedataCleanupSuccessorObservation($path)
{
	global $erasedataCleanupSuccessorObservationOverride;
	if(is_callable($erasedataCleanupSuccessorObservationOverride))
		call_user_func($erasedataCleanupSuccessorObservationOverride, $path);
	$identity = erasedataCleanupCurrentIdentity($path);
	if($identity === false || empty($identity['exists']))
		return($identity);
	clearstatcache(true, $path);
	$lstat = @lstat($path);
	$stat = @stat($path);
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

function erasedataCleanupSuccessorSnapshot($paths)
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
		$identity = erasedataCleanupSuccessorObservation($path);
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

function erasedataCleanupSuccessorSnapshotStillMatches($snapshot)
{
	if(!is_array($snapshot) || !isset($snapshot['observations']) || !is_array($snapshot['observations']))
		return(false);
	foreach($snapshot['observations'] as $observation)
	{
		if(!is_array($observation) || !isset($observation['path'], $observation['identity']))
			return(false);
		$current = erasedataCleanupSuccessorObservation($observation['path']);
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

if(!function_exists('erasedataBeforeCleanupUnlink'))
{
	// Narrow deterministic seam for the cleanup branch's final unlink race.
	function erasedataBeforeCleanupUnlink($path, $expected)
	{
		return(true);
	}
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
	if(!erasedataCleanupSuccessorSnapshotStillMatches($successorSnapshot))
		return(false);
	foreach($captureParents as $parent)
		if(!erasedataResumeCapturedEntries(
				$parent, $reservationKey, $filesystem))
			return(false);
	return(true);
}

function erasedataCleanupLog($hash, $state, $reason = null, $jobPath = '', $jobKey = null)
{
	global $erasedataCleanupLogState;
	if(!is_array($erasedataCleanupLogState))
		$erasedataCleanupLogState = array('completed' => array(), 'retained' => array());
	$key = $hash.'|'.($jobKey === null ? $jobPath : $jobKey);
	if($state === 'complete')
	{
		if(isset($erasedataCleanupLogState['completed'][$key])) return;
		$erasedataCleanupLogState['completed'][$key] = true;
		eLog('cleanup complete '.$hash);
		return;
	}
	if(isset($erasedataCleanupLogState['retained'][$key])) return;
	$erasedataCleanupLogState['retained'][$key] = true;
	FileUtil::toLog('erasedata: cleanup retained '.$hash.' '.$reason);
}

function erasedataConsumeCleanupManifest($path, $expectedStat, $hash, $token,
	ErasedataFilesystemOps $filesystem, $jobKey = null)
{
	$artifact = null;
	$manifest = erasedataReadCleanupManifest($path, $expectedStat, $hash, $artifact);
	if($manifest === false || !erasedataCleanupCommittedPairStillMatches($artifact, $token, $hash))
	{
		erasedataCleanupLog($hash, 'retained', 'unreadable-manifest', $path, $jobKey);
		return(false);
	}
	if(!erasedataRepairExactCleanupTokenMode($token['candidate']['path'], $token['candidate']['stat'])
		|| !erasedataCleanupCommittedPairStillMatches($artifact, $token, $hash))
	{
		erasedataCleanupLog($hash, 'retained', 'unreadable-manifest', $path, $jobKey);
		return(false);
	}
	$successorFiles = erasedataCleanupSuccessorPaths($manifest['new_hash']);
	if($successorFiles === false)
	{
		erasedataCleanupLog($hash, 'retained', 'rpc-unknown', $path, $jobKey);
		return(false);
	}
	$successorSnapshot = erasedataCleanupSuccessorSnapshot($successorFiles);
	if($successorSnapshot === false)
	{
		erasedataCleanupLog($hash, 'retained', 'unsafe-path', $path, $jobKey);
		return(false);
	}
	// The successor probe can take long enough for a same-inode manifest rewrite.
	// Re-read exact bytes before an obsolete target can be authorized from them.
	if(!erasedataCleanupCommittedPairStillMatches($artifact, $token, $hash))
	{
		erasedataCleanupLog($hash, 'retained', 'unreadable-manifest', $path, $jobKey);
		return(false);
	}
	// Cleanup captures live beside payload targets, not in the queue directory.
	// Preflight their aliases against the stable successor snapshot before a
	// missing public name can satisfy the exact job.
	if(!erasedataResumeCleanupCapturedTargets(
		$manifest, $path, $successorSnapshot, $filesystem))
	{
		erasedataCleanupLog($hash, 'retained', 'unlink-failure', $path, $jobKey);
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
	// Run every deterministic race seam before the first mutation. One final
	// linear successor scan then covers every name that could have become an
	// alias without multiplying successor probes by obsolete-file count.
	if($complete)
		foreach($actions as $action)
			if(!erasedataBeforeCleanupUnlink($action['file'], $action['expected']))
			{
				$complete = false;
				$reason = 'unlink-failure';
				break;
			}
	if($complete && !erasedataCleanupSuccessorSnapshotStillMatches($successorSnapshot))
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
				$supporter = erasedataCleanupSuccessorObservation($observation['path']);
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
			$captured = $filesystem->entryIdentity($action['file']);
			if(!is_array($captured)
				|| !erasedataSameStatIdentity($captured['lstat'], $current['lstat'])
				|| !erasedataUnlinkSamePath(
					$action['file'], $captured, $path, $filesystem))
			{
				$complete = false;
				$reason = 'unlink-failure';
				break;
			}
		}
	if($complete)
		foreach(erasedataCleanupParents($manifest) as $dir)
			if(!erasedataCompleteNonForceDirectory($dir, $path, $filesystem))
			{
				$complete = false;
				$reason = 'rmdir-failure';
				break;
			}
	if(!$complete)
	{
		erasedataCleanupLog($hash, 'retained', ($reason === null ? 'unsafe-path' : $reason), $path, $jobKey);
		return(false);
	}
	$consumed = erasedataUnlinkExactStagedFile($path, $artifact['candidate']['stat'], function() use ($artifact, $token, $hash) {
		return(erasedataCleanupCommittedPairStillMatches($artifact, $token, $hash));
	});
	if(!$consumed)
	{
		erasedataCleanupLog($hash, 'retained', 'unreadable-manifest', $path, $jobKey);
		return(false);
	}
	$consumed = erasedataUnlinkExactStagedFile($token['candidate']['path'], $token['candidate']['stat'], function() use ($token) {
		return(erasedataCleanupTokenStillMatches($token));
	});
	if(!$consumed)
	{
		erasedataCleanupLog($hash, 'retained', 'unreadable-manifest', $path, $jobKey);
		return(false);
	}
	erasedataCleanupLog($hash, 'complete', null, $path, $jobKey);
	return(true);
}

function erasedataCollectHash($listPath, $hash, ErasedataFilesystemOps $filesystem,
	$hashIndex = null, $index = null)
{
	$lock = erasedataAcquireHashLock($listPath, $hash, true);
	if($lock === false)
		return;
	if($index === null)
		$index = erasedataBuildCollectorIndex($listPath, $hash);
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
		$presence = erasedataTorrentPresence($hash);
		$ownedPaths = null;
		if($presence === ERASEDATA_TORRENT_PRESENT)
			$ownedPaths = erasedataCollectPaths($hash);
		if($presence !== ERASEDATA_TORRENT_UNKNOWN && !($presence === ERASEDATA_TORRENT_PRESENT && $ownedPaths === false))
			foreach($legacyItems as $item)
			{
				$path = $item['path'];
				if(!is_file($path)) continue;
				if($item['type'] === 'tmp')
				{
					// An operation-mismatched v3 payload under a legacy-looking name
					// must remain untouched rather than entering the legacy promotion.
					$bytes = @file_get_contents($path);
					$decoded = is_string($bytes) ? ErasedataManifestCodec::decodeBytes($bytes, $hash) : false;
					clearstatcache(true, $path);
					$current = @lstat($path);
					if(is_array($decoded) && isset($decoded['operation'])
						&& $decoded['operation'] !== $item['operation'] && is_array($current)
						&& erasedataSameStatIdentity($item['stat'], $current))
						continue;
					$listFile = substr($path, 0, -4).'.list';
					if(file_exists($listFile) || is_link($listFile)
						|| !$filesystem->rename($path, $listFile))
					{
						eLog('Failed to promote staging '.$path);
						continue;
					}
					erasedataRepairFileMode($listFile);
					$path = $listFile;
				}
				erasedataConsumeManifest($path, $item['stat'], $ownedPaths, $filesystem);
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
			erasedataCleanupLog($hash, 'retained', 'generation-mismatch', '', $jobKey);
			continue;
		}
		if(!count($tmpItems))
		{
			if(count($tokenItems) === 1 && !count($malformed))
			{
				$tokenReason = null;
				$token = erasedataReadExactCleanupToken($tokenItems[0], $tokenReason);
				if($token === false)
				{
					erasedataCleanupLog($hash, 'retained', $tokenReason === null ? 'unreadable-manifest' : $tokenReason,
						$tokenItems[0]['path'], $jobKey);
					continue;
				}
				if(!erasedataRepairExactCleanupTokenMode($token['candidate']['path'], $token['candidate']['stat'])
					|| !erasedataCleanupTokenStillMatches($token)
					|| !erasedataUnlinkExactStagedFile($token['candidate']['path'], $token['candidate']['stat'],
						function() use ($token) { return(erasedataCleanupTokenStillMatches($token)); }))
					erasedataCleanupLog($hash, 'retained', 'unreadable-manifest', $token['candidate']['path'], $jobKey);
				else
					erasedataCleanupLog($hash, 'complete', null, $token['candidate']['path'], $jobKey);
				continue;
			}
			if(count($tokenItems) || count($malformed))
				erasedataCleanupLog($hash, 'retained', 'generation-mismatch', '', $jobKey);
			continue;
		}
		if(count($tmpItems) !== 1 || count($tokenItems) > 1 || count($malformed))
		{
			erasedataCleanupLog($hash, 'retained', 'generation-mismatch', '', $jobKey);
			continue;
		}
		$tmpReason = null;
		$tmp = erasedataReadExactCleanupArtifact($tmpItems[0], $hash, $tmpReason);
		if($tmp === false)
		{
			erasedataCleanupLog($hash, 'retained', $tmpReason === null ? 'unreadable-manifest' : $tmpReason,
				$tmpItems[0]['path'], $jobKey);
			continue;
		}
		if(isset($analysis['transaction_key']) && $analysis['transaction_key'] !== null
			&& erasedataCleanupTransactionKey($tmp['manifest']) !== $analysis['transaction_key'])
		{
			erasedataCleanupLog($hash, 'retained', 'unreadable-manifest', $tmp['candidate']['path'], $jobKey);
			continue;
		}
		$token = null;
		if(count($tokenItems))
		{
			$tokenReason = null;
			$token = erasedataReadExactCleanupToken($tokenItems[0], $tokenReason);
			if($token === false || !erasedataCleanupCommittedPairStillMatches($tmp, $token, $hash))
			{
				erasedataCleanupLog($hash, 'retained', $tokenReason === null ? 'unreadable-manifest' : $tokenReason,
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
				erasedataCleanupLog($hash, 'retained',
					$recoveryReason === null ? 'generation-mismatch' : $recoveryReason, $tmp['candidate']['path'], $jobKey);
				continue;
			}
			$tmpCandidate = erasedataParseCollectorCandidate($listPath, basename($tmp['candidate']['path']));
			$tokenCandidate = erasedataParseCollectorCandidate($listPath, basename(substr($tmp['candidate']['path'], 0, -4).'.list'));
			$tmp = $tmpCandidate === false ? false : erasedataReadExactCleanupArtifact($tmpCandidate, $hash);
			$token = $tokenCandidate === false ? false : erasedataReadExactCleanupToken($tokenCandidate);
			if($tmp === false || $token === false || !erasedataCleanupCommittedPairStillMatches($tmp, $token, $hash))
			{
				erasedataCleanupLog($hash, 'retained', 'unreadable-manifest', '', $jobKey);
				continue;
			}
		}
		erasedataConsumeCleanupManifest($tmp['candidate']['path'], $tmp['candidate']['stat'],
			$hash, $token, $filesystem, $jobKey);
	}
	// The lock file is deliberately persistent; only the descriptor is released.
	erasedataReleaseHashLock($lock);
}

function erasedataRunCollectorWithFilesystem($listPath, ErasedataFilesystemOps $filesystem,
	$onlyHash = null)
{
	global $erasedataCleanupLogState;
	$erasedataCleanupLogState = array('completed' => array(), 'retained' => array());
	if(!erasedataResumeCapturedEntries(
		$listPath, 'manifest-consumption', $filesystem))
		return;
	$index = erasedataBuildCollectorIndex($listPath, $onlyHash);
	if($index === false)
		return;
	foreach(array_keys($index) as $hash)
		erasedataCollectHash($listPath, $hash, $filesystem, $index[$hash], $index);
}

function erasedataRunCollector($listPath, $onlyHash = null)
{
	return(erasedataRunCollectorWithFilesystem(
		$listPath, new ErasedataFilesystemOps(), $onlyHash));
}

function erasedataCollectorMain(ErasedataFilesystemOps $filesystem)
{
	global $argv;
	$listPath = FileUtil::getSettingsPath()."/erasedata";
	@FileUtil::makeDirectory($listPath);
	$onlyHash = null;
	if(is_array($argv) && count($argv) > 2)
	{
		$onlyHash = erasedataCanonicalHash($argv[2]);
		if($onlyHash === false)
		{
			eLog('Invalid targeted collector hash.');
			return;
		}
	}
	$schedulerLockPath = $listPath.'/scheduler.lock';
	$schedulerLock = @fopen($schedulerLockPath, 'c');
	if($schedulerLock === false)
		eLog('Could not open scheduler lock.');
	else
	{
		erasedataRepairFileMode($schedulerLockPath);
		if(@flock($schedulerLock, LOCK_EX | LOCK_NB))
		{
			erasedataRunCollectorWithFilesystem($listPath, $filesystem, $onlyHash);
			@flock($schedulerLock, LOCK_UN);
			@fclose($schedulerLock);
		}
		else
		{
			eLog('Busy, wait for next time.');
			@fclose($schedulerLock);
		}
	}
}

if(isset($_SERVER['SCRIPT_FILENAME'])
	&& realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__)
	erasedataCollectorMain(new ErasedataFilesystemOps());
