<?php

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

	public function rmdir($path)
	{
		return(@rmdir($path));
	}

	public function mkdir($path, $mode = 0700)
	{
		return(@mkdir($path, $mode));
	}

	public function symlink($target, $link)
	{
		return(@symlink($target, $link));
	}

	public function readlink($path)
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
}
