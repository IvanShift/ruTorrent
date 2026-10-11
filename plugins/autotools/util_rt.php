<?php
require_once dirname(__DIR__, 2) . '/php/rtorrent.php';

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
	exec( escapeshellarg($php).' '.$params.' > /dev/null 2>/dev/null &', $out, $ret );
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
// native queue worker; unsupported operation names refuse visibly.
//------------------------------------------------------------------------------
function rtOpFiles( $files, $src, $dst, $op, $dbg = false, $context = null )
{
    if( in_array( $op, array( 'Copy', 'HardLink', 'SoftLink' ), true ) )
        return AutoToolsFileTransaction::run( $files, $src, $dst, $op, $dbg, $context );

    FileUtil::toLog('autotools: unsupported file operation refused');
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
		$handle = @opendir( $path.$subdir );
		if( $handle === false )
			return $ret;
		while( false !== ( $item = readdir( $handle ) ) )
		{
			if( $item == '.' || $item == '..' )
				continue;
			$path_to_item = $path.$subdir.$item;
			if( !is_link( $path_to_item ) && is_dir( $path_to_item ) )
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


// rTorrent 0.16 publishes session files, a new download's .torrent copy and
// the .torrent.rtorrent sidecar with its custom fields, from its session
// thread after the RPC that asked for them has answered. Unless $ready()
// already holds, ask for the save once and wait for $ready() within the
// bound; an unconfirmed request is not a refusal, the daemon may still
// publish. The bound is a courtesy to the daemon's save schedule, so tests
// may declare it shorter. AutoTools Move and the non-Move x-dest recovery
// both wait through this one helper.
if (!defined('AUTOTOOLS_SESSION_SAVE_WAIT'))
    define('AUTOTOOLS_SESSION_SAVE_WAIT', 10.0);

// How long the recovery pass plans again a file operation that met a
// transient state -- a session copy rTorrent has not published yet, a
// download retrackers is re-adding -- before that state is a refusal
// (ACC-OWNB-02). Tests may declare it shorter.
function rtFileJobRetryWindow()
{
    return defined('AUTOTOOLS_RETRY_WINDOW') ? (int) AUTOTOOLS_RETRY_WINDOW : 1800;
}

function rtAwaitSessionSave($hash, $ready)
{
    if (call_user_func($ready)) return true;
    $save = new rXMLRPCRequest(new rXMLRPCCommand(getCmd('d.save_full_session'), array($hash)));
    $save->important = false;
    $save->success();
    // A monotonic clock: a step of the wall clock cannot stretch or cut the wait.
    $deadline = hrtime(true) / 1e9 + AUTOTOOLS_SESSION_SAVE_WAIT;
    do {
        usleep(100000);
        if (call_user_func($ready)) return true;
    } while (hrtime(true) / 1e9 < $deadline);
    return false;
}

// A finished hook can repeat. Keep its prepared files and old destinations until
// a later invocation can prove that every listed name was published.
class AutoToolsFileTransaction
{
    private static $failureReason;
    // The refusal of the last run() that the finished hook names on the
    // download (hook_lib.php), array('reason' => classification, 'detail' =>
    // text): an untrusted payload directory (R4 trust refusal, once only the
    // anonymous 'nonmove new held: source or destination has an unsafe
    // writable ancestor') or a journal the job cannot use (R4 fresh-journal
    // gap). Null otherwise; other refusals stay in the log.
    private static $refusal = null;
    // Whose job the current run() is: the download's hash when the hook gave
    // one, else 'new'.
    private static $subject = 'new';

    public static function lastRefusal()
    {
        return self::$refusal;
    }

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

    // The shared bounded settle of a just-renamed name; a test may return a
    // scripted subclass.
    private static $files = null;

    protected static function filesystem()
    {
        if (!class_exists('ErasedataFilesystemOps', false))
            require_once(dirname(__FILE__) . '/../erasedata/filesystem.php');
        if (self::$files === null) self::$files = new ErasedataFilesystemOps();
        return self::$files;
    }

    // identity() of a name this call has just renamed into place. On drvfs
    // over 9p (cache=0x5, the owner's Docker Desktop volumes) lstat of a new
    // name once answered ENOENT right after a successful rename. Only that
    // negative answer is asked again, within the shared bound; the first
    // present identity is compared exactly by the caller.
    private static function renamedIdentity($path)
    {
        $entry = static::filesystem()->renamedEntryIdentity($path);
        return $entry === false ? null : array($entry['dev'], $entry['ino'], $entry['mode'] & 0170000);
    }

    // The file-job journal's rule. Every component of the spelled path exists
    // and is a directory or a link, and no directory is group/other-writable
    // unless sticky and owned by root or this UID; '.' and '..' and a foreign
    // link in a sticky shared directory refuse.
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

    // A source, destination or staging directory of the payload follows the
    // rule datadir and AutoTools Move share (R3-OWN-01), for the path as it
    // is spelled: ErasedataFilesystemOps::payloadPathTrusted() checks every
    // directory and link the spelling passes (R4-FS-03). Group/other write is
    // accepted at and below the configured top, the strict rule applies above
    // it, and a component of another owner, a link included, refuses
    // everywhere. With $missingTail the path may end in names that do not
    // exist yet. The rule is loaded on first use; check.php, watch.php and
    // label.php include this file for other helpers and never need it.
    private static function payloadDirectory($path, $missingTail = false)
    {
        if (!class_exists('ErasedataFilesystemOps', false))
            require_once(dirname(__FILE__) . '/../erasedata/filesystem.php');
        return ErasedataFilesystemOps::payloadPathTrusted($path, null, $missingTail);
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
        $classified = $reason === 'cannot persist intent' ? 'journal-write-failed' : 'file-operation-refused';
        return self::refuseVisibly($reason, $classified, '');
    }

    // A refusal the finished hook names on the download, logged once with
    // the download's hash.
    private static function refuseVisibly($reason, $classified, $detail)
    {
        FileUtil::toLog('autotools: nonmove ' . self::$subject . ' refused: ' . $reason);
        self::$refusal = array('reason' => $classified, 'detail' => $detail);
        return false;
    }

    // A payload directory that fails the trust rule, by its spelled path.
    private static function refusePath($classified, $path)
    {
        if ($classified === 'untrusted-directory' && ErasedataFilesystemOps::lastPayloadRefusalPath() !== '')
            $path = ErasedataFilesystemOps::lastPayloadRefusalPath();
        $logged = function_exists('erasedataLogPath') ? erasedataLogPath($path)
            : json_encode($path, JSON_UNESCAPED_SLASHES);
        return self::refuseVisibly($classified . ' path=' . $logged, $classified, $path);
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
        if (!self::payloadDirectory($record['src']) || !self::payloadDirectory($record['dst']) ||
            !self::clearStages($record)) return false;
        $sourceProofs = array();
        foreach ($record['files'] as $index => $file) {
            $source = $record['src'] . '/' . $file;
            $stage = self::entryStage($record, $index);
            if (!self::payloadDirectory(dirname($stage)) || !@mkdir($stage, 0700)) return false;
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
                // Read as missing, this backup would fail the publication, and
                // the rollback, which cannot see it either, would leave the
                // public name empty until recovery.
                if (!self::same(self::renamedIdentity($removed), $old)) return false;
                static::checkpoint('after-backup-' . $index);
            } elseif ($old !== null && $moved === null) return false;
            if (!self::payloadDirectory(dirname($dest)) ||
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
        $q = array('rTorrent', 'quoteCommandArg');
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
        // The sidecar is published after the save request answers; wait for
        // it within the bound rather than holding the receipt for another
        // 60 s recovery cycle (L4-N5). A sidecar that never shows the values
        // still holds the receipt.
        $persisted = rtAwaitSessionSave($context['hash'], function () use ($context) {
            return self::savedXdest($context);
        });
        if (!$persisted ||
            self::xdestBranch($context, $context['destination'], false) !== 'AUTOTOOLS_XDEST_ALREADY') {
            self::$failureReason = 'x-dest session sidecar not persisted';
            return false;
        }
        // A confirmed operation retires the outcome of an earlier refusal.
        if (!class_exists('FileOperationOutcome', false))
            require_once(dirname(__FILE__) . '/../erasedata/outcome.php');
        FileOperationOutcome::clear('autotools', $context['hash']);
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
                !self::payloadDirectory($record['dst'])) {
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

    // The journal directory in the session, strict-safe; false when it is
    // unsafe, cannot be created, or ($create false) does not exist yet. It is
    // made only in a session directory the same rule trusts, and quietly: a
    // warning printed by the finished hook would become x-dest.
    public static function journalRoot($create = true)
    {
        $session = rTorrentSettings::get()->session;
        if (!is_string($session) || $session === '') return false;
        $root = rtrim($session, '/') . '/.autotools-file-jobs';
        if (!is_dir($root) && (!$create || !self::safeDirectory(rtrim($session, '/'))
            || (!@mkdir($root, 0700) && !is_dir($root))))
            return false;
        return self::safeDirectory($root) ? $root : false;
    }

    public static function recoverAll($root)
    {
        if (!file_exists($root) && !is_link($root)) return true;
        // Without a receipt there is nothing to recover; a hook arriving now
        // then finds the journal free. An unreadable journal is still refused.
        $entries = is_dir($root) && !is_link($root) ? @scandir($root) : false;
        if (is_array($entries) && !preg_grep('/\.nonmove\.json$/D', $entries)) return true;
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

    // Returns true when the job is published (or already was), false when it
    // is refused or held (both logged), and null when another owner holds the
    // journal lock: the synchronous finished hook never waits for it (see the
    // one-owner invariant in hook_lib.php), so its caller defers the hook.
    private static function makeDestination($path, &$created)
    {
        if (is_dir($path)) return true;
        $parent = dirname($path);
        if ($parent === $path || !self::makeDestination($parent, $created)) return false;
        if (@mkdir($path, 0755)) { $created[$path] = self::identity($path); return true; }
        return is_dir($path);
    }

    public static function run($files, $src, $dst, $op, $dbg = false, $context = null)
    {
        self::$refusal = null;
        self::$subject = is_array($context) && isset($context['hash']) && is_string($context['hash']) &&
            preg_match('/^[0-9A-F]{40}$/D', $context['hash']) ? $context['hash'] : 'new';
        if (!self::validFiles($files) || !in_array($op, array('Copy', 'HardLink', 'SoftLink'), true) ||
            !is_dir($src) || !is_string($dst) || $dst === '')
            return self::refuse('invalid file-operation request');
        // Both spellings are checked before anything is created through them,
        // and the destination again once it exists (R4-FS-03). The refusal
        // names the download and the path that failed: it once said only
        // 'nonmove new held: source or destination has an unsafe writable
        // ancestor' and nothing on the torrent.
        if (!self::payloadDirectory($src)) return self::refusePath('untrusted-directory', $src);
        if (!self::payloadDirectory($dst, true)) return self::refusePath('untrusted-directory', $dst);
        $created = array();
        try {
        if (!self::makeDestination($dst, $created)) return self::refusePath('destination-unavailable', $dst);
        if (!self::payloadDirectory($dst)) return self::refusePath('untrusted-directory', $dst);
        if (($resolvedDst = realpath($dst)) === false) return self::refusePath('destination-unavailable', $dst);
        $src = realpath($src);
        $dst = $resolvedDst;
        if (!self::validContext($context, $dst)) return self::refuse('invalid torrent caller context');
        if ($src === $dst) return self::refuse('source equals destination');
        if (!$files) return true;
        $root = self::journalRoot();
        if ($root === false)
            return self::refuseVisibly('journal-unavailable: the file-job journal directory is unavailable or'
                . ' unsafe', 'journal-unavailable', '');
        $handle = @fopen($root . '/.lock', 'c');
        if (!$handle) return self::refuseVisibly('journal-unavailable: cannot lock journal', 'journal-unavailable', '');
        if (!@flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            fclose($handle);
            return $wouldBlock ? null
                : self::refuseVisibly('journal-unavailable: cannot lock journal', 'journal-unavailable', '');
        }
        try {
            $old = array();
            foreach ($files as $file) {
                $dest = $dst . '/' . $file;
                $dir = dirname($dest);
                if (!self::makeDestination($dir, $created) || !self::payloadDirectory($dir))
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
            if ($matched === 'queued') {
                FileUtil::toLog('autotools: nonmove ' . self::$subject . ' waiting: predecessor pending');
                return false;
            }
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
            // The job's own run never calls the daemon: recovery replays x-dest.
            $ok = static::finish($root, $record, true, false, $planned);
            if (!$ok) {
                $reason = self::$failureReason ?: 'publication or rollback incomplete; retry pending';
                if (self::$failureReason !== 'predecessor x-dest acknowledgement pending')
                    self::$refusal = array('reason'=>'file-operation-refused', 'detail'=>'');
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
        } finally {
            // Remove only directories this invocation made and only while empty.
            foreach (array_reverse($created, true) as $directory => $identity)
                if (self::same(self::identity($directory), $identity)) @rmdir($directory);
        }
    }
}
