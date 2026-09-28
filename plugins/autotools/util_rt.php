<?php

require_once( "../../php/xmlrpc.php" );
require_once( "../../php/Torrent.php" );
require_once( dirname(__FILE__)."/../../php/utility/rtpluginutil.php" );

//------------------------------------------------------------------------------
// Check if script was launched in background (with --daemon switch)
//------------------------------------------------------------------------------
function rtIsDaemon( $args )
{
	foreach( $args as $arg )
		if( $arg == '--daemon' )
			return true;
	return false;
}

//------------------------------------------------------------------------------
// Making current process a daemon (run in background)
//------------------------------------------------------------------------------
function rtDaemon( $php, $script, $args )
{
	if( !$php || $php == '' ) $php = 'php';
	$params = escapeshellarg( $script ).' --daemon';
	foreach( $args as $arg )
		$params .= ' '.escapeshellarg( $arg );
	exec( $php.' '.$params.' > /dev/null 2>/dev/null &', $out, $ret );
	exit( (int)$ret );
}


//------------------------------------------------------------------------------
// Operations with semaphores
//------------------------------------------------------------------------------
function rtSemGet( $id )
{
	//$available = in_array( "sysvsem", get_loaded_extensions() );
	$available = function_exists( "sem_get" );
	return $available ? sem_get( $id, 1 ) : false;
}

//------------------------------------------------------------------------------
function rtSemLock( $sem_key )
{
	if( $sem_key ) sem_acquire( $sem_key );
}

//------------------------------------------------------------------------------
function rtSemUnlock( $sem_key )
{
	if( $sem_key ) sem_release( $sem_key );
}


//------------------------------------------------------------------------------
// Operations with slashes in paths
//------------------------------------------------------------------------------
function rtRemoveTailSlash( $str )
{
	$len = strlen( $str );
	if( $len == 0 || $str[$len-1] != '/' )
		return $str;
	return substr( $str, 0, -1 );
}

//------------------------------------------------------------------------------
function rtRemoveHeadSlash( $str )
{
	$len = strlen( $str );
	if( $len == 0 || $str[0] != '/' )
		return $str;
	return substr( $str, 1 );
}

//------------------------------------------------------------------------------
// Remove last token from $str string, using $sep as separator
//------------------------------------------------------------------------------
function rtRemoveLastToken( $str, $sep )
{
	$pos = strrpos( $str, $sep );
	if( $pos === false )
		return $str;
	return substr( $str, 0, $pos );
}

//------------------------------------------------------------------------------
// Return a part of $real_dir path, relative to $base_dir
//------------------------------------------------------------------------------
function rtGetRelativePath( $base_dir, $real_dir )
{
	$base_dir = rtAddTailSlash( $base_dir );
	$len = strlen( $base_dir );
	$str = substr( $real_dir, 0, $len );
	if( $str != $base_dir )
		return '';			// $real_dir is NOT SUBDIR of $base_dir
	$str = substr( $real_dir, $len );
	if( $str != '' )
		return $str;			// $read_dir is SUBDIR of $base_dir
	return './';				// $real_dir is EQUAL to $base_dir
}

//------------------------------------------------------------------------------
// Check if path is a file (without 2 Gb limit)
//------------------------------------------------------------------------------
function rtIsFile( $path )
{
	// use Novik's implementation
	return LFS::is_file( $path );

	//if( is_file( $path ) )
	//	return true;
	//$out = array();
	//$ret = "1";
	//exec( 'test -f '.escapeshellarg( $path ), $out, $ret );
	//return (int)$ret == 0;
}


//------------------------------------------------------------------------------
// Preserve the file operation entry point for non-Move modes. Move uses the
// claimed transaction in move_tx.php; an old persisted hook must refuse visibly.
//------------------------------------------------------------------------------
function rtOpFiles( $files, $src, $dst, $op, $dbg = false, $context = null )
{
    if( in_array( $op, array( 'Copy', 'HardLink', 'SoftLink' ), true ) )
        return AutoToolsFileTransaction::run( $files, $src, $dst, $op, $dbg, $context );

    FileUtil::toLog( $op === 'Move'
        ? 'autotools: legacy Move entrypoint refused; safe Move requires move_tx.php'
        : 'autotools: unsupported file operation refused' );
    return false;
}

//------------------------------------------------------------------------------
// Recursively scan files at $path directory
//------------------------------------------------------------------------------
function rtScanFiles( $path, $mask, $subdir = '' )
{
	$path = rtAddTailSlash( $path );
	if( $subdir != '' )
		$subdir = rtAddTailSlash( $subdir );
	$ret = array();
	if( is_dir( $path.$subdir ) )
	{
		$handle = opendir( $path.$subdir );
		while( false !== ( $item = readdir( $handle ) ) )
		{
			if( $item == '.' || $item == '..' )
				continue;
			$path_to_item = $path.$subdir.$item;
			if( is_dir( $path_to_item ) )
			{
				$ret = array_merge( $ret,
					rtScanFiles( $path, $mask, $subdir.$item ) );
			}
			elseif( rtIsFile( $path_to_item ) &&
				preg_match( $mask, $item ) )
			{
				$ret[] = $subdir.$item;
			}
		}
		closedir( $handle );
	}
	return ( $ret );
}


//------------------------------------------------------------------------------
// Recursively remove $path directory (optionally with or without files)
//------------------------------------------------------------------------------
function rtRemoveDirectory( $path, $with_files = false )
{
	$path = rtRemoveTailSlash( $path );
	if( !file_exists( $path ) || !is_dir( $path ) )
		return false;
	$handle = opendir( $path );
	$empty = true;
	while( false !== ( $item = readdir( $handle ) ) )
	{
		if( $item == '.' || $item == '..' )
			continue;
		$path_to_item = $path.'/'.$item;
		if( is_dir( $path_to_item ) )
		{
			if( !rtRemoveDirectory( $path_to_item, $with_files ) )
				$empty = false;
		}
		else
		{
			if( !$with_files || !unlink( $path_to_item ) )
				$empty = false;
		}
	}
	closedir( $handle );
	return ( $empty && rmdir( $path ) );
}

// A finished hook can repeat. Keep its prepared files and old destinations until
// a later invocation can prove that every listed name was published.
class AutoToolsFileTransaction
{
    private static $failureReason;

    protected static function checkpoint($name) {}

    private static function identity($path, $follow = false)
    {
        clearstatcache(true, $path);
        $stat = $follow ? @stat($path) : @lstat($path);
        return $stat === false ? null : array($stat['dev'], $stat['ino'], $stat['mode'] & 0170000);
    }

    private static function same($a, $b)
    {
        return $a !== null && $b !== null && $a === $b;
    }

    private static function safeDirectory($path)
    {
        if (!is_string($path) || $path === '' || $path[0] !== '/') return false;
        $part = '';
        foreach (explode('/', $path) as $component) {
            if ($component === '') continue;
            if ($component === '.' || $component === '..') return false;
            $parent = $part === '' ? '/' : $part;
            $part .= '/' . $component;
            $stat = @lstat($part);
            if ($stat === false) return false;
            if (($stat['mode'] & 0170000) === 0120000) {
                $parentStat = @stat($parent);
                if ($parentStat === false ||
                    (($parentStat['mode'] & 01000) !== 0 && ($parentStat['mode'] & 0022) !== 0 &&
                    $stat['uid'] !== 0 &&
                    $stat['uid'] !== (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid())))
                    return false;
                $resolved = @realpath($part);
                if ($resolved === false || !self::safeDirectory($resolved)) return false;
                continue;
            }
            if (($stat['mode'] & 0170000) !== 0040000) return false;
            $foreignWritable = ($stat['mode'] & 0022) !== 0;
            $stickyProtected = ($stat['mode'] & 01000) !== 0 &&
                ($stat['uid'] === 0 || $stat['uid'] === (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid()));
            if ($foreignWritable && !$stickyProtected) return false;
        }
        return true;
    }

    private static function validFiles($files)
    {
        if (!is_array($files)) return false;
        $seen = array();
        foreach ($files as $file) {
            if (!is_string($file) || $file === '' || strpos($file, "\0") !== false ||
                $file[0] === '/' || preg_match('~(^|/)(\\.{1,2}|)(/|$)~', $file)) return false;
            if (isset($seen[$file])) return false;
            $seen[$file] = true;
        }
        return true;
    }

    private static function logHold($token, $reason)
    {
        FileUtil::toLog('autotools: nonmove ' . $token . ' held: ' . $reason);
    }

    private static function refuse($reason)
    {
        FileUtil::toLog('autotools: nonmove new refused: ' . $reason);
        return false;
    }

    private static function journal($root, $record)
    {
        $path = $root . '/' . $record['id'] . '.nonmove.json';
        $temp = $path . '.tmp';
        $body = json_encode($record);
        if ($body === false || @file_put_contents($temp, $body, LOCK_EX) === false ||
            !@rename($temp, $path)) return false;
        return true;
    }

    private static function treeRemove($path)
    {
        if (!is_dir($path) || is_link($path)) return @lstat($path) === false || @unlink($path);
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..' && !self::treeRemove($path . '/' . $name))
                return false;
        }
        return @rmdir($path);
    }

    private static function entryStage($record, $index)
    {
        return dirname($record['dst'] . '/' . $record['files'][$index]) .
            '/.autotools-stage-' . $record['id'] . '-' . $index;
    }

    private static function clearStages($record)
    {
        foreach ($record['files'] as $index => $file) {
            $stage = self::entryStage($record, $index);
            if (self::identity($stage) !== null && !self::treeRemove($stage)) return false;
        }
        return true;
    }

    private static function prepare($root, &$record, $predecessors = array())
    {
        if (!self::safeDirectory($record['src']) || !self::safeDirectory($record['dst']) ||
            !self::clearStages($record)) return false;
        $sourceProofs = array();
        foreach ($record['files'] as $index => $file) {
            $source = $record['src'] . '/' . $file;
            $stage = self::entryStage($record, $index);
            if (!self::safeDirectory(dirname($stage)) || !@mkdir($stage, 0700)) return false;
            static::checkpoint('after-stage-' . $index);
            if (($record['op'] === 'Copy' && !is_file($source)) ||
                ($record['op'] === 'HardLink' && !is_file($source) && !is_link($source))) {
                self::$failureReason = 'source unavailable for ' . $record['op'];
                return false;
            }
            $sourceHandle = null;
            if ($record['op'] === 'Copy') {
                $sourceHandle = @fopen($source, 'rb');
                $held = $sourceHandle ? @fstat($sourceHandle) : false;
                $sourceIdentity = $held === false ? null : array($held['dev'], $held['ino'], $held['mode'] & 0170000);
                if (!self::same(self::identity($source, true), $sourceIdentity)) {
                    if ($sourceHandle) fclose($sourceHandle);
                    return false;
                }
            }
            switch ($record['op']) {
                case 'Copy': $ok = @copy($source, $stage . '/new'); break;
                case 'HardLink': $ok = @link($source, $stage . '/new'); break;
                default: $ok = @symlink($source, $stage . '/new'); break;
            }
            if (!$ok) {
                if ($sourceHandle) fclose($sourceHandle);
                self::$failureReason = 'cannot stage ' . $record['op'] . ' source';
                return false;
            }
            $record['new'][$index] = self::identity($stage . '/new');
            static::checkpoint('after-stage-file-' . $index);
            if ($sourceHandle) {
                $hash = hash_init('sha256');
                $read = @hash_update_stream($hash, $sourceHandle);
                $sourceHash = hash_final($hash);
                $stable = $read !== false && self::same(self::identity($source, true), $sourceIdentity) &&
                    @hash_file('sha256', $stage . '/new') === $sourceHash;
                fclose($sourceHandle);
                if (!$stable) {
                    self::$failureReason = 'source changed or unreadable during Copy';
                    return false;
                }
                $sourceProofs[] = array($source, $sourceIdentity, $sourceHash);
            }
        }
        // Earlier files can change while later files are staged. Recheck the set
        // before claiming any occupied destination.
        foreach ($sourceProofs as $proof) {
            if (!self::same(self::identity($proof[0], true), $proof[1]) ||
                @hash_file('sha256', $proof[0]) !== $proof[2] ||
                !self::same(self::identity($proof[0], true), $proof[1])) {
                self::$failureReason = 'source changed or unreadable during Copy';
                return false;
            }
        }
        // A hardlink witness pins the occupied inode against reuse after claim.
        foreach ($record['files'] as $index => $file) {
            $dest = $record['dst'] . '/' . $file;
            $witness = self::entryStage($record, $index) . '/old';
            $prior = isset($predecessors[$index]) ? $predecessors[$index] : null;
            $oldSource = $prior !== null && $prior['record']['phase'] === 'prepared'
                ? self::entryStage($prior['record'], $prior['index']) . '/new' : $dest;
            $expected = $prior === null ? self::identity($dest)
                : $prior['record']['new'][$prior['index']];
            if ($expected === null) {
                $record['old'][$index] = null;
                continue;
            }
            if (!self::same(self::identity($oldSource), $expected) ||
                !@link($oldSource, $witness)) return false;
            $record['old'][$index] = self::identity($witness);
            static::checkpoint('after-witness-' . $index);
            if (!self::same(self::identity($oldSource), $record['old'][$index]) ||
                !in_array($record['old'][$index][2], array(0100000, 0120000), true))
                return false;
        }
        $record['phase'] = 'prepared';
        return self::journal($root, $record);
    }

    private static function publish($root, &$record)
    {
        foreach ($record['files'] as $index => $file) {
            $dest = $record['dst'] . '/' . $file;
            $stage = self::entryStage($record, $index);
            $witness = $stage . '/old';
            $removed = $stage . '/removed';
            $old = $record['old'][$index];
            $current = self::identity($dest);
            $saved = self::identity($witness);
            $moved = self::identity($removed);
            if ($old !== null && !self::same($saved, $old)) return false;
            if ($moved !== null && !self::same($moved, $old)) return false;
            if (self::same($current, $record['new'][$index])) continue;
            if ($current !== null) {
                if (!self::same($current, $old)) return false;
                if ($moved !== null && !@unlink($removed)) return false;
                static::checkpoint('before-backup-' . $index);
                if (!@rename($dest, $removed)) return false;
                static::checkpoint('after-backup-unverified-' . $index);
                if (!self::same(self::identity($removed), $old)) return false;
                static::checkpoint('after-backup-' . $index);
            } elseif ($old !== null && $moved === null) return false;
            if (!self::safeDirectory(dirname($dest)) ||
                !self::same(self::identity($stage . '/new'), $record['new'][$index]) ||
                !@link($stage . '/new', $dest)) return false;
            static::checkpoint('after-publish-' . $index);
        }
        $record['phase'] = 'committed';
        return self::journal($root, $record);
    }

    private static function rollback($record)
    {
        $complete = true;
        for ($index = count($record['files']) - 1; $index >= 0; --$index) {
            $dest = $record['dst'] . '/' . $record['files'][$index];
            $stage = self::entryStage($record, $index);
            $witness = $stage . '/old';
            $removed = $stage . '/removed';
            $old = $record['old'][$index];
            $current = self::identity($dest);
            if (self::same($current, $record['new'][$index])) {
                if (!@unlink($dest)) { $complete = false; continue; }
                $current = null;
            }
            if ($current !== null && !self::same($current, $old)) {
                $complete = false;
                continue;
            }
            $moved = self::identity($removed);
            if ($moved !== null && !self::same($moved, $old)) {
                if ($current === null) @link($removed, $dest);
                $complete = false;
                continue;
            }
            if ($old !== null && !self::same(self::identity($witness), $old)) {
                $complete = false;
                continue;
            }
            if ($moved !== null) {
                if ($current === null && !@link($witness, $dest)) {
                    $complete = false;
                    continue;
                }
                if (!@unlink($removed)) $complete = false;
            } elseif ($old !== null && $current === null) {
                // Another actor removed the old name before our commit.
                $complete = false;
            }
        }
        return $complete;
    }

    private static function quoteArgument($value)
    {
        return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), (string) $value) . '"';
    }

    private static function validContext($context, $dst = null)
    {
        if ($context === null) return true;
        if (!is_array($context)) return false;
        $keys = array_keys($context);
        if (($keys !== array('hash', 'token', 'sourceBase', 'destination') &&
            $keys !== array('hash', 'token', 'sourceBase', 'destination', 'notice')) ||
            !is_string($context['hash']) ||
            !preg_match('/^[0-9A-F]{40}$/D', $context['hash']) ||
            !is_string($context['token']) ||
            !preg_match('/^[0-9a-f]{32}$/D', $context['token'])) return false;
        foreach (array('sourceBase', 'destination') as $key)
            if (!is_string($context[$key]) || $context[$key] === '' ||
                $context[$key][0] !== '/' || strpos($context[$key], "\0") !== false)
                return false;
        if (array_key_exists('notice', $context)) {
            $notice = $context['notice'];
            if (!is_array($notice) || array_keys($notice) !==
                array('noticeDestination', 'finishedRoot', 'torrentName') ||
                !is_string($notice['noticeDestination']) ||
                $notice['noticeDestination'] === '' ||
                $notice['noticeDestination'][0] !== '/' ||
                strpos($notice['noticeDestination'], "\0") !== false ||
                !is_string($notice['finishedRoot']) || $notice['finishedRoot'] === '' ||
                $notice['finishedRoot'][0] !== '/' ||
                strpos($notice['finishedRoot'], "\0") !== false ||
                !is_string($notice['torrentName']) ||
                strpos($notice['torrentName'], "\0") !== false)
                return false;
        }
        return $dst === null || realpath($context['destination']) === $dst;
    }

    // Stage exact path bytes through XMLRPC. Embedding a quoted path in the
    // rTorrent command grammar fails for names containing a literal quote.
    private static function stageXdestPaths($context)
    {
        $commands = array(
            new rXMLRPCCommand(getCmd('d.set_custom'), array($context['hash'],
                'x-autotools-nonmove-source', $context['sourceBase'])),
            new rXMLRPCCommand(getCmd('d.set_custom'), array($context['hash'],
                'x-autotools-nonmove-target', $context['destination'])),
        );
        $request = new rXMLRPCRequest($commands);
        $request->important = false;
        return $request->success() && !$request->fault && is_array($request->val) &&
            count($request->val) === count($commands);
    }

    // The daemon evaluates this branch as one command; a separate read then
    // write could overwrite another finished hook's x-dest between requests.
    private static function xdestBranch($context, $expected, $set)
    {
        $q = array(__CLASS__, 'quoteArgument');
        $conditions = array(
            'equal=' . getCmd('d.get_custom=') . 'x-autotools-nonmove-job,cat=' . $context['token'],
            'equal=' . getCmd('d.get_base_path=') . ',' . getCmd('d.get_custom=') .
                'x-autotools-nonmove-source',
            'equal=' . getCmd('d.get_custom=') . 'x-dest,' .
                ($expected === '' ? 'cat=' : getCmd('d.get_custom=') . 'x-autotools-nonmove-target'),
        );
        $condition = 'and=' . implode(',', array_map($q, $conditions));
        $sentinel = $set ? 'AUTOTOOLS_XDEST_SET' : 'AUTOTOOLS_XDEST_ALREADY';
        $body = $set
            ? 'cat=' . call_user_func($q, '$' . getCmd('d.set_custom=') .
                'x-dest,$' . getCmd('d.get_custom=') . 'x-autotools-nonmove-target') .
                ',' . $sentinel
            : 'cat=' . $sentinel;
        $request = new rXMLRPCRequest(new rXMLRPCCommand(getCmd('branch'),
            array($context['hash'], $condition, $body, 'cat=AUTOTOOLS_XDEST_SKIP')));
        $request->important = false;
        return $request->success() && !$request->fault && count($request->val) === 1
            ? $request->val[0] : null;
    }

    private static function savedXdest($context, $requireDestination = true)
    {
        $session = rTorrentSettings::get()->session;
        if (!is_string($session) || $session === '') return false;
        // Current rTorrent stores each download's custom fields in this sidecar.
        $path = rtrim($session, '/') . '/' . $context['hash'] . '.torrent.rtorrent';
        $bytes = @file_get_contents($path);
        if ($bytes === false) return false;
        $torrent = Torrent::fromRawBytes($bytes);
        $custom = $torrent->meta('custom');
        if ($torrent->errors() !== false || !is_array($custom) ||
            !isset($custom['x-autotools-nonmove-job']) ||
            $custom['x-autotools-nonmove-job'] !== $context['token']) return false;
        return !$requireDestination ||
            (isset($custom['x-autotools-nonmove-source'],
                $custom['x-autotools-nonmove-target'], $custom['x-dest']) &&
            $custom['x-autotools-nonmove-source'] === $context['sourceBase'] &&
            $custom['x-autotools-nonmove-target'] === $context['destination'] &&
            $custom['x-dest'] === $context['destination']);
    }

    private static function replayXdest($context)
    {
        if (!self::stageXdestPaths($context)) {
            self::$failureReason = 'cannot stage source and x-dest daemon values';
            return false;
        }
        if (self::xdestBranch($context, '', true) !== 'AUTOTOOLS_XDEST_SET' &&
            self::xdestBranch($context, $context['destination'], false) !== 'AUTOTOOLS_XDEST_ALREADY') {
            self::$failureReason = 'torrent generation, source path or x-dest changed';
            return false;
        }
        $save = new rXMLRPCRequest(new rXMLRPCCommand(getCmd('d.save_full_session'),
            array($context['hash'])));
        $save->important = false;
        if (!$save->success() || $save->fault ||
            self::xdestBranch($context, $context['destination'], false) !== 'AUTOTOOLS_XDEST_ALREADY' ||
            !self::savedXdest($context)) {
            self::$failureReason = 'x-dest session sidecar not persisted';
            return false;
        }
        return true;
    }

    private static function published($record)
    {
        foreach ($record['files'] as $index => $file)
            if (!self::same(self::identity($record['dst'] . '/' . $file), $record['new'][$index]))
                return false;
        return true;
    }

    private static function finishNotice($root, &$record, $allowRpc)
    {
        if ($record['phase'] === 'notice-attempted') {
            self::$failureReason = 'completion mail outcome uncertain; receipt retained';
            return false;
        }
        if (!$allowRpc || !self::published($record) ||
            !self::savedXdest($record['context'])) {
            self::$failureReason = 'completion notice awaits durable x-dest';
            return false;
        }
        $notice = $record['context']['notice'];
        if (!class_exists('rAutoTools')) {
            self::$failureReason = 'completion mail helper unavailable';
            return false;
        }
        if (rAutoTools::completionMailFile($notice['noticeDestination'],
            $notice['finishedRoot']) !== null) {
            $record['phase'] = 'notice-attempted';
            if (!self::journal($root, $record)) return false;
            static::checkpoint('after-notice-attempt');
            $sent = rAutoTools::notifyCompletedFileTransfer($notice['noticeDestination'],
                $notice['finishedRoot'], $notice['torrentName']);
            FileUtil::toLog('autotools: nonmove ' . $record['id'] .
                ($sent ? ' completion mail sent' : ' completion mail refused'));
        }
        if (!self::clearStages($record)) return false;
        return @unlink($root . '/' . $record['id'] . '.nonmove.json');
    }

    private static function finish($root, &$record, $newJob = false, $allowRpc = true,
        $predecessors = array())
    {
        if ($record['phase'] === 'notice-pending' || $record['phase'] === 'notice-attempted')
            return static::finishNotice($root, $record, $allowRpc);
        if ($record['phase'] === 'staging' && (!$newJob || !self::prepare($root, $record, $predecessors)))
            return false;
        if ($record['phase'] === 'prepared' && !empty($record['waitFor'])) {
            foreach ($record['waitFor'] as $prior) {
                if (is_file($root . '/' . $prior['id'] . '.nonmove.json') ||
                    !self::savedXdest($prior['context'])) {
                    self::$failureReason = 'predecessor x-dest acknowledgement pending';
                    return false;
                }
            }
            // A queued hook returns no x-dest. Prove its saved generation and
            // live source before allowing delayed publication.
            if ($record['op'] === 'HardLink') {
                foreach ($record['files'] as $index => $file) {
                    if (!self::same(self::identity($record['src'] . '/' . $file),
                        $record['new'][$index])) {
                        self::$failureReason = 'queued HardLink source changed';
                        return false;
                    }
                }
            }
            if (!$allowRpc || !isset($record['context']) || $record['context'] === null ||
                !self::savedXdest($record['context'], false) ||
                !self::stageXdestPaths($record['context']) ||
                self::xdestBranch($record['context'], '', false) !== 'AUTOTOOLS_XDEST_ALREADY') {
                self::$failureReason = 'queued torrent generation or source changed';
                return false;
            }
        }
        if ($record['phase'] === 'prepared' && !static::publish($root, $record)) {
            self::rollback($record);
            return false;
        }
        if ($record['phase'] !== 'committed') return false;
        static::checkpoint('after-committed');
        if (!self::published($record)) return false;
        if (isset($record['context']) && $record['context'] !== null) {
            if ($newJob) return true; // execute.capture sets x-dest after this worker returns.
            if (!$allowRpc) {
                self::$failureReason = 'x-dest acknowledgement pending scheduled recovery';
                return false;
            }
            if (!self::replayXdest($record['context'])) return false;
            if (isset($record['context']['notice'])) {
                $record['phase'] = 'notice-pending';
                if (!self::journal($root, $record)) return false;
                return static::finishNotice($root, $record, $allowRpc);
            }
        }
        if (!self::clearStages($record)) return false;
        static::checkpoint('after-stage-cleanup');
        return @unlink($root . '/' . $record['id'] . '.nonmove.json');
    }

    private static function validRecord($record)
    {
        if (!is_array($record) || !isset($record['id'], $record['phase'], $record['src'],
                $record['dst'], $record['op'], $record['files'], $record['old'], $record['new']) ||
            !is_string($record['id']) ||
            !in_array($record['phase'], array('staging', 'prepared', 'committed',
                'notice-pending', 'notice-attempted'), true) ||
            !in_array($record['op'], array('Copy', 'HardLink', 'SoftLink'), true) ||
            !is_string($record['src']) || !is_string($record['dst']) ||
            realpath($record['dst']) !== $record['dst'] ||
            ($record['phase'] === 'staging' && realpath($record['src']) !== $record['src']) ||
            !self::validFiles($record['files']) ||
            !self::validContext(isset($record['context']) ? $record['context'] : null, $record['dst']) ||
            array_values($record['files']) !== $record['files'] || !is_array($record['old']) ||
            !is_array($record['new']) || array_values($record['old']) !== $record['old'] ||
            array_values($record['new']) !== $record['new'] ||
            count($record['old']) !== count($record['files']) ||
            !preg_match('/^[a-f0-9]{24}$/D', $record['id'])) return false;
        foreach ($record['old'] as $identity)
            if ($identity !== null && (!is_array($identity) || count($identity) !== 3 ||
                array_values($identity) !== $identity || !is_int($identity[0]) ||
                !is_int($identity[1]) || !is_int($identity[2]))) return false;
        if ($record['phase'] === 'staging' && $record['new']) return false;
        if ($record['phase'] !== 'staging' && count($record['new']) !== count($record['files']))
            return false;
        foreach ($record['new'] as $identity)
            if (!is_array($identity) || count($identity) !== 3 ||
                array_values($identity) !== $identity || !is_int($identity[0]) ||
                !is_int($identity[1]) || !is_int($identity[2])) return false;
        if (isset($record['waitFor'])) {
            if (!is_array($record['waitFor']) || array_values($record['waitFor']) !== $record['waitFor'])
                return false;
            $seen = array();
            foreach ($record['waitFor'] as $prior) {
                if (!is_array($prior) || array_keys($prior) !== array('id', 'context') ||
                    !is_string($prior['id']) || !preg_match('/^[a-f0-9]{24}$/D', $prior['id']) ||
                    $prior['id'] === $record['id'] || isset($seen[$prior['id']]) ||
                    !self::validContext($prior['context'])) return false;
                $seen[$prior['id']] = true;
            }
        }
        return true;
    }

    private static function overlaps($left, $right)
    {
        return $left === $right || strpos($left, rtrim($right, '/') . '/') === 0 ||
            strpos($right, rtrim($left, '/') . '/') === 0;
    }

    private static function finalEntry($directory, $file)
    {
        $path = $directory . '/' . $file;
        $parent = realpath(dirname($path));
        return $parent === false ? null : $parent . '/' . basename($path);
    }

    private static function overlapsFiles($record, $retry)
    {
        foreach ($record['files'] as $old) {
            $oldName = self::finalEntry($record['dst'], $old);
            if ($oldName === null) return true;
            foreach ($retry['files'] as $new) {
                $newName = self::finalEntry($retry['dst'], $new);
                if ($newName === null || self::overlaps($oldName, $newName)) return true;
            }
        }
        return false;
    }

    private static function plannedPredecessors($records, $retry)
    {
        $planned = array();
        foreach ($retry['files'] as $index => $file) {
            $name = self::finalEntry($retry['dst'], $file);
            if ($name === null) return false;
            $candidates = array();
            foreach ($records as $record) {
                foreach ($record['files'] as $priorIndex => $priorFile) {
                    $priorName = self::finalEntry($record['dst'], $priorFile);
                    if ($priorName === null) return false;
                    if ($priorName === $name)
                        $candidates[$record['id']] = array('record' => $record, 'index' => $priorIndex);
                    elseif (self::overlaps($priorName, $name)) return false;
                }
            }
            if (!$candidates) continue;
            $referenced = array();
            foreach ($candidates as $candidate)
                foreach (isset($candidate['record']['waitFor']) ? $candidate['record']['waitFor'] : array() as $prior)
                    $referenced[$prior['id']] = true;
            foreach ($referenced as $id => $_) unset($candidates[$id]);
            if (count($candidates) !== 1) return false;
            $planned[$index] = reset($candidates);
        }
        return $planned;
    }

    private static function recoverLocked($root, $forDestination = null, $allowRpc = true,
        $retry = null, &$matched = false, &$deferOn = array())
    {
        $entries = @scandir($root);
        if ($entries === false) {
            self::logHold('journal', 'cannot scan pending records');
            return false;
        }
        $paths = array();
        foreach ($entries as $entry)
            if (substr($entry, -13) === '.nonmove.json') $paths[] = $root . '/' . $entry;
        $usable = true;
        foreach ($paths as $path) {
            $record = json_decode(@file_get_contents($path), true);
            if (!self::validRecord($record) ||
                $path !== $root . '/' . $record['id'] . '.nonmove.json' ||
                !self::safeDirectory($record['dst'])) {
                self::logHold(basename($path), 'invalid recovery record');
                return false;
            }
            self::$failureReason = null;
            if (!static::finish($root, $record, false, $allowRpc)) {
                $sameJob = $retry !== null && $record['src'] === $retry['src'] &&
                    $record['dst'] === $retry['dst'] && $record['op'] === $retry['op'] &&
                    $record['files'] === $retry['files'] && isset($record['context']) &&
                    $record['context'] === $retry['context'];
                if ($sameJob && in_array($record['phase'], array('committed', 'notice-pending', 'notice-attempted'), true) &&
                    self::published($record)) {
                    $matched = true;
                    continue; // The same hook can return its original x-dest again.
                }
                if ($sameJob && $record['phase'] === 'prepared' && !empty($record['waitFor'])) {
                    $matched = 'queued';
                    continue; // Keep the same one-shot receipt and wait for recovery.
                }
                // A verified committed payload can coexist with disjoint final
                // names. A colliding one must wait for this receipt's disk ACK.
                $pendingAck = $retry !== null && $retry['context'] !== null && !$allowRpc &&
                    in_array($record['phase'], array('committed', 'notice-pending', 'notice-attempted'), true) &&
                    isset($record['context']) &&
                    $record['context'] !== null && self::published($record);
                if ($pendingAck) {
                    if (self::overlapsFiles($record, $retry)) $deferOn[] = $record;
                    continue;
                }
                $queued = $retry !== null && $retry['context'] !== null && !$allowRpc &&
                    $record['phase'] === 'prepared' && !empty($record['waitFor']) &&
                    self::$failureReason === 'predecessor x-dest acknowledgement pending';
                if ($queued) {
                    if (self::overlapsFiles($record, $retry)) $deferOn[] = $record;
                    continue;
                }
                self::logHold(basename($path), $record['phase'] === 'staging'
                        ? 'unclaimed intent requires explicit retry'
                        : (self::$failureReason ?: 'recovery could not prove destination identities'));
                if ($forDestination === null || self::overlaps($record['dst'], $forDestination))
                    $usable = false;
            }
        }
        return $usable;
    }

    public static function recoverAll($root)
    {
        if (!file_exists($root) && !is_link($root)) return true;
        if (!self::safeDirectory($root)) {
            self::logHold('journal', 'unsafe or absent journal directory');
            return false;
        }
        $handle = @fopen($root . '/.lock', 'c');
        if (!$handle || !@flock($handle, LOCK_EX)) {
            self::logHold('journal', 'cannot lock recovery');
            if ($handle) fclose($handle);
            return false;
        }
        try { return static::recoverLocked($root); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    public static function run($files, $src, $dst, $op, $dbg = false, $context = null)
    {
        if (!self::validFiles($files) || !in_array($op, array('Copy', 'HardLink', 'SoftLink'), true) ||
            !is_dir($src) || !is_string($dst) || $dst === '')
            return self::refuse('invalid file-operation request');
        $src = realpath($src);
        if (!self::safeDirectory($src) || !rtMkDir($dst, 0755) ||
            !self::safeDirectory(dirname($dst)) ||
            !self::safeDirectory($resolvedDst = realpath($dst))) {
            self::logHold('new', 'source or destination has an unsafe writable ancestor');
            return false;
        }
        $dst = $resolvedDst;
        if (!self::validContext($context, $dst)) return self::refuse('invalid torrent caller context');
        if ($src === $dst) return self::refuse('source equals destination');
        if (!$files) return true;
        $session = rTorrentSettings::get()->session;
        $root = rtrim($session, '/') . '/.autotools-file-jobs';
        if (!$session || !rtMkDir($root, 0700) || !self::safeDirectory($root)) {
            self::logHold('new', 'journal directory unavailable or unsafe');
            return false;
        }
        $handle = @fopen($root . '/.lock', 'c');
        if (!$handle || !@flock($handle, LOCK_EX)) {
            if ($handle) fclose($handle);
            return self::refuse('cannot lock journal');
        }
        try {
            $old = array();
            foreach ($files as $file) {
                $dest = $dst . '/' . $file;
                $dir = dirname($dest);
                if (!rtMkDir($dir, 0755) || !self::safeDirectory($dir))
                    return self::refuse('destination parent unavailable or unsafe');
                $identity = self::identity($dest);
                if ($identity !== null && !in_array($identity[2], array(0100000, 0120000), true))
                    return self::refuse('listed destination is not a file or symlink');
                $old[] = null;
            }
            $matched = false;
            $deferOn = array();
            $retry = array('src' => $src, 'dst' => $dst,
                'op' => $op, 'files' => array_values($files), 'context' => $context);
            if (!static::recoverLocked($root, $dst, false, $retry, $matched, $deferOn))
                return self::refuse('pending file job blocks hash=' .
                    ($context === null ? 'unknown' : $context['hash']));
            if ($matched === 'queued')
                return self::refuse('queued file job awaits recovery hash=' . $context['hash']);
            if ($matched) return true;
            // Once the sidecar owns this token and x-dest, a repeated hook is
            // the same completed job even after its journal has retired.
            if ($context !== null && self::savedXdest($context)) return true;
            $planned = self::plannedPredecessors($deferOn, $retry);
            if ($planned === false)
                return self::refuse('pending file job has an ambiguous final-name overlap');
            $waitFor = array();
            foreach ($planned as $prior) {
                $id = $prior['record']['id'];
                $waitFor[$id] = array('id' => $id, 'context' => $prior['record']['context']);
            }
            $record = array('id' => bin2hex(random_bytes(12)), 'phase' => 'staging',
                'src' => $src, 'dst' => $dst, 'op' => $op,
                'files' => array_values($files), 'old' => $old, 'new' => array(),
                'context' => $context, 'waitFor' => array_values($waitFor));
            if (!self::journal($root, $record)) return self::refuse('cannot persist intent');
            static::checkpoint('after-journal');
            self::$failureReason = null;
            $ok = static::finish($root, $record, true, true, $planned);
            if (!$ok) {
                $reason = self::$failureReason ?: 'publication or rollback incomplete; retry pending';
                if ($record['phase'] === 'staging' && self::clearStages($record) &&
                    @unlink($root . '/' . $record['id'] . '.nonmove.json'))
                    FileUtil::toLog('autotools: nonmove ' . $record['id'] . ' refused: ' . $reason);
                else self::logHold($record['id'], $reason .
                    ($record['phase'] === 'prepared' && $waitFor
                        ? '; queued hash=' . $context['hash'] : ''));
            }
            return $ok;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
