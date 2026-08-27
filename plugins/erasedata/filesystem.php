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
		return(@rename($from, $to));
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

	public function openDirectoryReference($path, $expectedIdentity)
	{
		if(!is_dir('/proc/self/fd'))
			return(false);
		$handle = @fopen($path, 'r');
		if($handle === false)
			return(false);
		$stat = @fstat($handle);
		$expDev = isset($expectedIdentity['stat']['dev']) ? $expectedIdentity['stat']['dev'] : (isset($expectedIdentity['dev']) ? $expectedIdentity['dev'] : null);
		$expIno = isset($expectedIdentity['stat']['ino']) ? $expectedIdentity['stat']['ino'] : (isset($expectedIdentity['ino']) ? $expectedIdentity['ino'] : null);
		if(!is_array($stat) || is_null($expDev) || is_null($expIno)
			|| $stat['dev'] !== $expDev || $stat['ino'] !== $expIno)
		{
			@fclose($handle);
			return(false);
		}
		$entries = @scandir('/proc/self/fd');
		if($entries !== false)
		{
			foreach($entries as $entry)
			{
				if(!ctype_digit($entry))
					continue;
				$reference = '/proc/self/fd/'.$entry;
				$referenceStat = @stat($reference);
				if(is_array($referenceStat) && $referenceStat['dev'] === $stat['dev']
					&& $referenceStat['ino'] === $stat['ino'])
					return(array('handle'=>$handle, 'path'=>$reference));
			}
		}
		@fclose($handle);
		return(false);
	}

	public function closeDirectoryReference($reference)
	{
		if(is_array($reference) && isset($reference['handle']) && is_resource($reference['handle']))
			@fclose($reference['handle']);
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
	if(!$filesystem->makeDirectory($root, 0700))
		return(false);
	$namePath = erasedataCapturedEntryNamePath($root);
	$name = base64_encode(basename($path));
	$written = @file_put_contents($namePath, $name, LOCK_EX);
	@chmod($namePath, 0600);
	if($written !== strlen($name) || !erasedataCreatePrivateMarker($root))
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
		if(!erasedataPathExists($path))
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
