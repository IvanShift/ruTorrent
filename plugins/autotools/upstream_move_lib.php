<?php

/** Upstream synchronous Move, ported from Novik/ruTorrent f9c5a32b.
 * No daemon RPC is permitted while rTorrent waits for this actor.
 */
final class AutoToolsUpstreamMove
{
public static function rtMoveFile( $src, $dst, $dbg = false )
{
	$ss = LFS::stat($src);
	if( rename( $src, $dst ) )
	{
		// Across filesystems PHP's rename() copies the file itself and
		// returns true without its times; a real rename keeps the inode and
		// the times. touch() opens a name that access() reports missing with
		// O_TRUNC, and drvfs over 9p (Docker Desktop, 2026-10-11) answered
		// ENOENT for a name it had just renamed. Settle that new name through
		// the shared bound before restoring a copy's times; an unseen name is
		// never touched. A same-filesystem rename needs no timestamp write.
		$files = new ErasedataFilesystemOps();
		$ds = $files->renamedEntryIdentity( $dst );
		if( $ss!==false && $ds!==false && $ds['is_file'] && ($ds['dev']!==$ss['dev'] || $ds['ino']!==$ss['ino']) )
			touch( $dst, $ss['mtime'], $ss['atime'] );
		return true;
	}
	if( $dbg ) rtDbg( __FUNCTION__, "from ".$src );
	if( $dbg ) rtDbg( __FUNCTION__, "to   ".$dst );
	if( $dbg ) rtDbg( __FUNCTION__, "move fail, try to copy" );
	if( !copy( $src, $dst ) )
	{
		if( $dbg ) rtDbg( __FUNCTION__, "copy fail" );
		return false;
	}
	if( !unlink( $src ) )
		if( $dbg ) rtDbg( __FUNCTION__, "delete fail (".$src.")" );
	// there are problems here, if run-user is not file owner
	if($ss!==false)
		touch( $dst, $ss['mtime'], $ss['atime'] );
	return true;
}

public static function rtDestinationRoot( $dst )
{
	$dst = rtrim( $dst, '/' );
	if( $dst == '' )
		return '';
	$real = realpath( $dst );
	if( $real !== false )
		return rtRemoveTailSlash( $real );
	// Not there yet, so there is nothing in it to resolve. The deepest part of
	// it that is there is resolved instead, and what is added to that is
	// elements that do not exist and so cannot be symlinks. self::rtOpFiles()
	// confirms that is still so once it has made them.
	$tail = array();
	$path = $dst;
	while( true )
	{
		$parent = dirname( $path );
		if( $parent === $path )
			return '';
		array_unshift( $tail, FileUtil::getFileName( $path ) );
		$real = realpath( $parent );
		if( $real !== false )
			return rtRemoveTailSlash( $real ).'/'.implode( '/', $tail );
		$path = $parent;
	}
}

public static function rtContainedPath( $root, $file, $make = false, &$made = null )
{
	$root = rtRemoveTailSlash( $root );
	if( $root == '' || !is_string( $file ) || $file == '' || $file[0] == '/' )
		return '';
	$parts = explode( '/', $file );
	$leaf = array_pop( $parts );
	if( $leaf == '' || $leaf == '.' || $leaf == '..' )
		return '';
	$path = $root;
	foreach( $parts as $part )
	{
		if( $part == '' || $part == '.' || $part == '..' )
			return '';
		$path .= '/'.$part;
		$st = @lstat( $path );
		if( $st === false )
		{
			// Something is there that cannot be inspected. What it is decides
			// where the walk goes, so not knowing ends it.
			if( is_link( $path ) || file_exists( $path ) )
				return '';
			if( !$make )
				continue;
			if( !@mkdir( $path, 0777 ) )
			{
				// Lost the race to create it. What won is only acceptable if
				// it is the directory this was going to make.
				$st = @lstat( $path );
				if( $st === false || ( $st['mode'] & 0170000 ) != 0040000 )
					return '';
				continue;
			}
			if( is_array( $made ) )
				$made[] = array( 'rmdir', $path );
			continue;
		}
		if( ( $st['mode'] & 0170000 ) != 0040000 )
			return '';
	}
	return $path.'/'.$leaf;
}

public static function rtNameInUse( $path )
{
	return rtIsFile( $path ) || is_dir( $path ) || is_link( $path ) ||
		file_exists( $path );
}

public static function rtTakenDestination( $files, $dst )
{
	if( !is_array( $files ) || $dst == '' )
		return '';
	$root = self::rtDestinationRoot( $dst );
	if( $root == '' )
		return rtAddTailSlash( $dst );
	foreach( $files as $file )
	{
		$dest = self::rtContainedPath( $root, $file );
		if( $dest == '' )
			return rtAddTailSlash( $dst ).( is_string( $file ) ? $file : '' );
		if( self::rtNameInUse( $dest ) )
			return $dest;
	}
	return '';
}

public static function rtUndoOpFiles( $journal, $dbg = false )
{
	$whole = true;
	foreach( array_reverse( $journal ) as $entry )
	{
		switch( $entry[0] )
		{
			case 'moveback':
			{
				if( !self::rtMoveFile( $entry[1], $entry[2], $dbg ) )
				{
					if( $dbg ) rtDbg( __FUNCTION__, "can't carry ".$entry[1]." back to ".$entry[2] );
					$whole = false;
				}
				break;
			}
			case 'unlink':
			{
				if( !@unlink( $entry[1] ) )
				{
					if( $dbg ) rtDbg( __FUNCTION__, "can't remove ".$entry[1] );
					$whole = false;
				}
				break;
			}
			default:
			{
				// A directory this operation made. Something else may have put
				// a file in it since, and then it is no longer this
				// operation's to remove.
				@rmdir( $entry[1] );
				break;
			}
		}
	}
	return $whole;
}

public static function rtOpFiles( $files, $src, $dst, $op, $dbg = false )
{
	// Check if source and destination directories are valid
	if( !is_array( $files ) || $src == '' || $dst == '' )
	{
		if( $dbg ) rtDbg( __FUNCTION__, "invalid params" );
		return false;
	}

	// Check if source directory exists
	if( !is_dir( $src ) )
	{
		if( $dbg ) rtDbg( __FUNCTION__, "src is not a directory" );
		if( $dbg ) rtDbg( __FUNCTION__, "( ".$src." )" );
		return false;
	}
	else $src = rtAddTailSlash( $src );

	// Check if destination directory exists or can be created
	if( !rtMkDir( dirname( rtRemoveTailSlash( $dst ) ), 0777 ) )
	{
		if( $dbg ) rtDbg( __FUNCTION__, "can't create ".dirname( $dst ) );
		return false;
	}

	// Everything below is named from here down, and measured against here.
	$root = self::rtDestinationRoot( $dst );
	if( $root == '' )
	{
		if( $dbg ) rtDbg( __FUNCTION__, "can't resolve ".$dst );
		return false;
	}
	$dst = rtAddTailSlash( $root );

	// Check if source and destination directories are the same
	if( realpath( $src ) === $root )
	{
		if( $dbg ) rtDbg( __FUNCTION__, "source is equal to destination" );
		if( $dbg ) rtDbg( __FUNCTION__, "( ".$root." )" );
		return false;
	}

	// What this operation has changed so far, so that stopping part way
	// through can be undone. Without it a refusal in the middle leaves the
	// download split between two directories with nothing to say where the
	// other half went, and every caller reads false as "the operation did not
	// happen".
	$journal = array();

	// The root itself. Its parent was made above; this one is made here so
	// that a refusal can take it away again.
	if( !is_dir( $root ) )
	{
		if( !@mkdir( $root, 0777 ) && !is_dir( $root ) )
		{
			if( $dbg ) rtDbg( __FUNCTION__, "can't create ".$root );
			return false;
		}
		$journal[] = array( 'rmdir', $root );
	}

	// Everything below is measured from the root, so the root has to be what
	// it was resolved to be. Anything else means a symlink stands somewhere in
	// it that was not there when it was resolved.
	if( realpath( $root ) !== $root )
	{
		if( $dbg ) rtDbg( __FUNCTION__, "refused, ".$root." is no longer its own path" );
		self::rtUndoOpFiles( $journal, $dbg );
		return false;
	}

	// Every name is settled before the first file is carried, and settled
	// again below immediately before each one is used: a name that was free
	// when the walk started can be taken by the time it is reached, and a
	// directory on the way down can have become a symlink in the same window.
	$taken = self::rtTakenDestination( $files, $root );
	if( $taken != '' )
	{
		if( $dbg ) rtDbg( __FUNCTION__, "refused, destination not available: ".$taken );
		self::rtUndoOpFiles( $journal, $dbg );
		return false;
	}

	$failed = '';
	foreach( $files as $file )
	{
		$source = $src.$file;
		$dest = self::rtContainedPath( $root, $file, true, $journal );
		if( $dest == '' )
			$failed = "can't reach ".$dst.$file;
		elseif( self::rtNameInUse( $dest ) )
			$failed = "destination already exists: ".$dest;
		else switch( $op )
		{
			case "HardLink":
			{
				if( link( $source, $dest ) )
				{
					$journal[] = array( 'unlink', $dest );
					break;
				}
			}
			case "Copy":
			{
				if( !copy( $source, $dest ) )
				{
					$failed = "can't copy ".$source;
					break;
				}
				$journal[] = array( 'unlink', $dest );
				break;
			}
			case "SoftLink":
			{
				if( !symlink( $source, $dest ) )
				{
					$failed = "can't link ".$dest;
					break;
				}
				$journal[] = array( 'unlink', $dest );
				break;
			}
			default:
			{
				if( !self::rtMoveFile( $source, $dest, $dbg ) )
				{
					$failed = "can't move ".$source;
					break;
				}
				$journal[] = array( 'moveback', $dest, $source );
				break;
			}
		}
		if( $failed != '' )
			break;
	}

	if( $failed == '' )
	{
		if( $dbg ) rtDbg( __FUNCTION__, "finished" );
		return true;
	}

	if( $dbg ) rtDbg( __FUNCTION__, "refused, ".$failed );
	if( !self::rtUndoOpFiles( $journal, $dbg ) )
		if( $dbg ) rtDbg( __FUNCTION__, "and it could not be undone in full" );
	return false;
}

public static function rtRemoveDirectory( $path, $with_files = false )
{
	$path = rtRemoveTailSlash( $path );
	if( !file_exists( $path ) || !is_dir( $path ) )
		return false;
	$empty = true;
	$handle = @opendir( $path );
	if($handle !== false)
	{
		while( false !== ( $item = readdir( $handle ) ) )
		{
			if( $item == '.' || $item == '..' )
				continue;
			$path_to_item = $path.'/'.$item;
			if( is_dir( $path_to_item ) )
			{
				if( !self::rtRemoveDirectory( $path_to_item, $with_files ) )
					$empty = false;
			}
			else
			{
				if( !$with_files || !@unlink( $path_to_item ) )
					$empty = false;
			}
		}
		closedir( $handle );
	}
	return ( $empty && @rmdir( $path ) );
}

    public static function plan($basePath, $baseName, $multi, $label, $name)
    {
        $source = rtAddTailSlash(dirname(rtrim($basePath, '/')));
        $at = rAutoTools::load();
        $destination = $source;
        $finished = $at->path_to_finished;
        $label = rawurldecode($label);
        if ($at->enable_move && $at->fileop_type === 'Move'
            && @preg_match($at->automove_filter . (UTF::isValid($label) ? 'u' : ''), $label) === 1 && $finished !== '') {
            $relative = rtGetRelativePath(rtAddTailSlash(rTorrentSettings::get()->directory), $source);
            if ($relative !== '') {
                if ($relative === './') $relative = '';
                $destination = rtAddTailSlash($finished . '/' . $relative);
                if ($at->addLabel && $label !== '' && $label !== trim($relative, '/'))
                    $destination .= FileUtil::addslash($label);
                if ($at->addName && $name !== '') $destination .= FileUtil::addslash($name);
            }
        }
        $sub = $multi ? rtAddTailSlash($baseName) : '';
        return array($source . $sub, $destination . $sub, $at);
    }

    public static function check($args)
    {
        $count = is_array($args) ? count($args) : null;
        if ($count !== 5 && $count !== 6) throw new RuntimeException('callback-arguments-invalid');
        $hasHash = $count === 6;
        $hash = $hasHash ? $args[0] : '';
        $args = self::sourceArguments($args, $hasHash ? 1 : 0);
        $source = $args[2] === '1' ? @rtrim($args[0], '/') : @dirname($args[0]);
        if (!is_string($source) || $source === '' || $source[0] !== '/')
            throw new RuntimeException('callback-arguments-invalid');
        $source = $source === '/' ? '/' : $source.'/';
        // Old handlers have no torrent identity and cannot attest a file list.
        if (!$hasHash) {
            self::refuse($hash, 'file-list-needs-daemon');
            return $source;
        }
        if (!is_string($hash) || @preg_match('/^[0-9A-Fa-f]{40}$/D', $hash) !== 1) {
            self::refuse($hash, 'torrent-hash-invalid');
            return $source;
        }
        list($source, $destination, $at) = self::plan($args[0], $args[1], $args[2] === '1', $args[3], $args[4]);
        if ($destination !== $source) {
            try { list($torrent, $files) = self::sourceFiles($hash, $args[2] === '1', $args[1], $at); }
            catch (RuntimeException $error) {
                self::refuse($hash, $error->getMessage());
                return $source;
            }
            if (!ErasedataFilesystemOps::payloadPathTrusted($destination, null, true)
                || self::rtTakenDestination($files, $destination) !== '') {
                FileUtil::toLog('autotools: upstream-move refused: destination-unavailable');
                return $source;
            }
        }
        return $destination;
    }

    public static function move($args)
    {
        $count = is_array($args) ? count($args) : null;
        if ($count !== 6 && $count !== 7) throw new RuntimeException('callback-arguments-invalid');
        $hash = $args[0];
        $directory = $count === 7 ? $args[6] : null;
        $args = self::sourceArguments($args, 1);
        if (!is_string($hash) || @preg_match('/^[0-9A-Fa-f]{40}$/D', $hash) !== 1)
            return self::refuse($hash, 'torrent-hash-invalid');
        if (!is_string($directory)) return self::refuse($hash, 'directory-publication-unconfirmed');
        list($source, $destination, $at) = self::plan($args[0], $args[1], $args[2] === '1', $args[3], $args[4]);
        if ($source === $destination) return false;
        // The hook captures this after its directory setter. An old handler,
        // or a check that kept the source, cannot authorize moving the data.
        if (rtrim($directory, '/') !== rtrim($destination, '/'))
            return self::refuse($hash, 'directory-publication-unconfirmed');
        try { list($torrent, $files) = self::sourceFiles($hash, $args[2] === '1', $args[1], $at); }
        catch (RuntimeException $error) { return self::refuse($hash, $error->getMessage()); }
        if (!ErasedataFilesystemOps::payloadPathTrusted($destination, null, true))
            return self::refuse($hash, 'untrusted-directory');
        if (!self::rtOpFiles($files, $source, $destination, 'Move')) return self::refuse($hash, 'payload-refused');
        if ($args[2] === '1') self::rtRemoveDirectory($source, false);
        rAutoTools::notifyCompletedFileTransfer(dirname(rtrim($destination, '/')), $at->path_to_finished, $torrent->name());
        return true;
    }

    private static function sourceFiles($hash, $multi, $baseName, $at)
    {
        $path = rtrim(rTorrentSettings::get()->session, '/') . '/' . $hash . '.torrent';
        if (!is_readable($path)) throw new RuntimeException('session-copy-unreadable');
        $torrent = new Torrent($path);
        if ($torrent->errors()) throw new RuntimeException('session-copy-invalid');
        $files = AutoToolsFilePaths::forSynchronousHook($torrent->info, $multi, $baseName);
        foreach ($files as $file)
            if ($at->skip_move_for_files !== '' && @preg_match($at->skip_move_for_files . (UTF::isValid($file) ? 'u' : ''), $file) === 1)
                throw new RuntimeException('skip-filter-match');
        return array($torrent, $files);
    }

    private static function sourceArguments($args, $offset)
    {
        // Keep the captured slots fixed even when PHP array helpers are unavailable.
        $source = array();
        for ($index = 0; $index < 5; ++$index) {
            if (!isset($args[$offset + $index]) || !is_string($args[$offset + $index]))
                throw new RuntimeException('callback-arguments-invalid');
            $source[] = $args[$offset + $index];
        }
        if ($source[0] === '' || $source[0][0] !== '/' || $source[1] === ''
            || ($source[2] !== '0' && $source[2] !== '1'))
            throw new RuntimeException('callback-arguments-invalid');
        return $source;
    }

    private static function refuse($hash, $reason)
    {
        FileUtil::toLog('autotools: upstream-move ' . $hash . ' refused: ' . $reason);
        return false;
    }
}
