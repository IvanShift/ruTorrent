<?php

require_once '../../php/xmlrpc.php';
require_once './util_rt.php';
require_once __DIR__ . '/../rutracker_check/runstate.php';
// The shared no-replace owner and the torrent presence probe, without the
// erasedata removal machinery in removewithdata.php.
require_once __DIR__ . '/../erasedata/filesystem.php';
require_once __DIR__ . '/../erasedata/presence.php';
require_once __DIR__ . '/../erasedata/outcome.php';
require_once __DIR__ . '/movejob.php';
require_once __DIR__ . '/apply.php';

if (!defined('DATADIR_START_CHECK_SECONDS'))
    define('DATADIR_START_CHECK_SECONDS', 3.0);

function rtDataDirClaimRpc($method, $hash, $args)
{
    $request = new rXMLRPCRequest(new rXMLRPCCommand(getCmd($method),
        array_merge(array($hash), $args)));
    $request->important = false;
    if (!$request->success() || $request->fault || count($request->val) !== 1)
        return null;
    return $request->val[0];
}

// Only an attested absence may retire a job; a fault or lost answer is unknown.
function rtDataDirTorrentPresence($hash)
{
    $presence = erasedataTorrentPresence($hash);
    return $presence === ERASEDATA_TORRENT_ABSENT ? 'absent'
        : ($presence === ERASEDATA_TORRENT_PRESENT ? 'present' : 'unknown');
}

function rtDataDirClaimCapability()
{
    $required = array(
        'd.stop_close_claim_state',
        'd.stop_close_claim',
        'd.directory.set_if_stop_close_claim',
        'd.directory.base.set_if_stop_close_claim',
        'd.start_if_stop_close_claim',
        'd.open_if_stop_close_claim',
        'd.release_stop_close_claim',
        'd.replay_stop_close_claim',
        'd.ack_stop_close_claim',
    );
    $status = rpcMethodCapability($required);
    return $status === 'unconfirmed' ? 'unknown' : $status;
}

// One shared no-replace owner per process, for the helper and its preflight.
function rtDataDirFilesystem()
{
    static $files = null;
    if ($files === null)
        $files = new ErasedataFilesystemOps();
    return $files;
}

function rtDataDirMoveNoReplace($source, $destination)
{
    return rtDataDirFilesystem()->renameNoReplace($source, $destination);
}

function rtDataDirLock($nonBlocking)
{
    $path = rtDataDirJournal() . '/.lock';
    if (!function_exists('posix_geteuid'))
        throw new RuntimeException('lock-owner-unavailable');
    if (is_link($path))
        throw new RuntimeException('lock-path-untrusted');
    $handle = @fopen($path, 'c');
    if ($handle === false)
        throw new RuntimeException('lock-unavailable');
    $stat = @fstat($handle);
    if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
        || $stat['uid'] !== posix_geteuid() || !@chmod($path, 0600))
    {
        @fclose($handle);
        throw new RuntimeException('lock-path-untrusted');
    }
    if (!@flock($handle, LOCK_EX | ($nonBlocking ? LOCK_NB : 0)))
    {
        @fclose($handle);
        if ($nonBlocking)
            return false;
        throw new RuntimeException('lock-unavailable');
    }
    return $handle;
}

function rtDataDirUnlock($handle)
{
    if (is_resource($handle))
    {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

function rtDataDirJournalParent()
{
    $profile = @realpath(FileUtil::getProfilePathEx(''));
    if ($profile === false || !is_dir($profile))
        throw new RuntimeException('journal-parent-unavailable');
    // The profile share may be group-writable; use its canonical parent only
    // when that parent is a trusted journal authority.
    return dirname($profile);
}

function rtDataDirJournalRoot($create)
{
    $settings = @realpath(FileUtil::getSettingsPathEx(''));
    if ($settings === false || !is_dir($settings))
        throw new RuntimeException('journal-parent-unavailable');
    $parent = rtDataDirJournalParent();
    $private = $parent . '/datadir-jobs';
    $legacy = $settings . '/datadir-jobs';
    $parentTrusted = DataDirMoveIntent::trustedDirectory($parent);
    $settingsTrusted = DataDirMoveIntent::trustedDirectory($settings);
    $hasPrivate = file_exists($private) || is_link($private);
    $hasLegacy = file_exists($legacy) || is_link($legacy);
    if ($private !== $legacy && $hasPrivate && $hasLegacy)
        throw new RuntimeException('split-journal-roots');
    if ($hasLegacy && !$settingsTrusted)
        throw new RuntimeException('legacy-journal-untrusted');
    if ($hasPrivate && !$parentTrusted)
        throw new RuntimeException('journal-parent-untrusted');
    if ($hasLegacy || (!$hasPrivate && $settingsTrusted))
        $root = $legacy;
    elseif ($hasPrivate || $parentTrusted)
        $root = $private;
    else
        throw new RuntimeException('journal-parent-untrusted');
    if (!$create && !file_exists($root) && !is_link($root))
        return '';
    $root = DataDirMoveJob::journalDirectory($root);
    if (!DataDirMoveIntent::trustedDirectory($root))
        throw new RuntimeException('journal-path-untrusted');
    return $root;
}

function rtDataDirJournal()
{
    $journal = rtDataDirJournalRoot(true);
    $user = User::getUser();
    if (!is_string($user) || preg_match('/^[a-z0-9_-]*$/D', $user) !== 1)
        throw new RuntimeException('journal-user-invalid');
    $journal = DataDirMoveJob::journalDirectory($journal . '/' . ($user === '' ? '~default' : $user));
    if (!DataDirMoveIntent::trustedDirectory($journal))
        throw new RuntimeException('journal-path-untrusted');
    return $journal;
}

function rtDataDirRecoveryProfiles()
{
    $root = rtDataDirJournalRoot(false);
    if ($root === '')
        return array();
    $names = @scandir($root);
    if (!is_array($names))
        throw new RuntimeException('profile-scan-failed');
    $profiles = array();
    foreach ($names as $name)
    {
        if ($name === '.' || $name === '..')
            continue;
        $path = $root . '/' . $name;
        try {
            if (($name !== '~default' && preg_match('/^[a-z0-9_-]+$/D', $name) !== 1)
                || is_link($path)
                || !is_dir($path)
                || DataDirMoveJob::journalDirectory($path) !== $path
                || !DataDirMoveIntent::trustedDirectory($path))
                throw new RuntimeException('profile-journal-untrusted');
            $entries = @scandir($path);
            if (!is_array($entries))
                throw new RuntimeException('profile-journal-unreadable');
            foreach ($entries as $entry)
                if ($entry !== '.' && $entry !== '..' && $entry !== '.lock')
                {
                    $profiles[] = $name;
                    break;
                }
        } catch (RuntimeException $e) {
            FileUtil::toLog('datadir: global recovery skipped path=' . $root . '/'
                . rawurlencode($name) . ' reason=' . $e->getMessage()
                . ' consequence=profile-held');
        }
    }
    sort($profiles, SORT_STRING);
    return $profiles;
}

// $payloadMoves false is the web request: it replays only directory-only
// jobs. A payload move stays with its CLI worker or the scheduled recovery.
function rtDataDirRecover($payloadMoves = true)
{
    try {
        $journal = rtDataDirJournal();
        $names = scandir($journal);
        if (!is_array($names))
            throw new RuntimeException('job-scan-failed');
    } catch (RuntimeException $e) {
        FileUtil::toLog('datadir: recovery refused: ' . $e->getMessage());
        return false;
    }
    $ok = true;
    sort($names, SORT_STRING);
    foreach ($names as $name) {
        if (substr($name, -5) === '.move') {
            $jobPath = $journal . '/' . substr($name, 0, -5);
            if (!is_file($jobPath) && is_file($journal . '/' . $name)) {
                FileUtil::toLog('datadir: recovery refused: orphan-move-receipt path='
                    . $journal . '/' . rawurlencode($name) . ' consequence=job-held');
                $ok = false;
            }
            continue;
        }
        if ($name === '.' || $name === '..' || $name === '.lock')
            continue;
        if (!preg_match('/^[0-9A-F]{40}\.json$/D', $name)) {
            FileUtil::toLog('datadir: recovery refused: unexpected-job-entry path='
                . $journal . '/' . rawurlencode($name) . ' consequence=recovery-held');
            $ok = false;
            continue;
        }
        $hash = substr($name, 0, 40);
        try {
            $job = DataDirMoveJob::load($journal . '/' . $name,
                'rtDataDirClaimRpc', 'rtDataDirMoveNoReplace', 'rtDataDirTorrentPresence');
            if (!$payloadMoves && $job->movesPayload())
                continue;
            $job->run();
            FileOperationOutcome::clear('datadir', $hash);
        } catch (DataDirMoveRefused $e) {
            FileUtil::toLog('datadir: recovery refused hash=' . $hash . ' reason=' . $e->getMessage());
            rtDataDirWorkerRefusal($hash, $e->getMessage());
        } catch (Exception $e) {
            rtDataDirHeld($hash, $e->getMessage(), $journal . '/' . $name);
            $ok = false;
        }
    }
    return $ok;
}

// Admission checks the protected document before asking for another daemon
// lease. The worker repeats this check under the profile lock.
function rtDataDirPendingRefusal($hash)
{
    $path = rtDataDirJournal() . '/' . strtoupper($hash) . '.json';
    return file_exists($path) || is_link($path) ? 'prior-job-pending' : '';
}

function rtDataDirHeld($hash, $reason, $path)
{
    $context = 'document=' . rawurlencode($path) . ' key=' . strtoupper($hash)
        . ' consequence=claim-retained-start-and-move-blocked';
    FileUtil::toLog('datadir: recovery held hash=' . strtoupper($hash)
        . ' reason=' . $reason . ' ' . $context);
    FileOperationOutcome::record('datadir', $hash, (string) $reason,
        'datadir: ' . $reason . '; ' . $context
        . '; preserve the document and payload; see plugins/datadir/RECOVERY.md, then run recover.php --retry', true);
}

function rtDataDirOwnership($hash)
{
    $keys = RuTrackerAtomicOwnership::ownershipKeys();
    $commands = array(new rXMLRPCCommand(getCmd('d.get_custom1'), $hash));
    foreach ($keys as $key)
        $commands[] = new rXMLRPCCommand(getCmd('d.get_custom'), array($hash, $key));
    $request = new rXMLRPCRequest($commands);
    $request->important = false;
    if (!$request->success() || $request->fault || !is_array($request->val)
        || count($request->val) !== count($commands)
        || count(array_filter($request->val, 'is_string')) !== count($commands))
        throw new RuntimeException('unreadable-checker-ownership');
    $markers = array_combine($keys, array_slice($request->val, 1));
    unset($markers['chk-revived']);
    if (rawurldecode($request->val[0]) === '.chk-meta'
        || count(array_filter($markers, 'strlen')) !== 0)
        throw new RuntimeException('active-checker-transaction-or-service-label');
}

function rtDataDirSnapshot($hash, $destPath, $addPath, $moveFiles, $dbg, $readOnly = false)
{
    $request = rtExec(array('d.is_open', 'd.local_id', 'd.directory'), $hash, $dbg);
    if (!$request || !is_array($request->val) || count($request->val) !== 3
        || !in_array((string) $request->val[0], array('0', '1'), true)
        || !is_string($request->val[1]) || $request->val[1] === ''
        || !is_string($request->val[2]) || $request->val[2] === '')
        throw new RuntimeException('unreadable-torrent-state');
    $wasOpen = (string) $request->val[0] === '1';
    if ($readOnly && !$wasOpen)
        return null;
    $localId = $request->val[1];
    $previousDirectory = $request->val[2];
    $source = '';
    $destination = '';
    $files = array();
    // Both metadata-only and payload moves must bind the setter to a physical root.
    $physicalDestination = @realpath($destPath);
    if ($physicalDestination === false && $readOnly)
    {
        // The worker creates a new destination. Preflight probes its existing
        // ancestor and must not reject the canonical requested suffix.
        $reason = DataDirMoveIntent::destinationReason($destPath);
        if ($reason !== '')
            throw new RuntimeException($reason);
        $physicalDestination = $destPath;
    }
    elseif ($physicalDestination === false || !is_dir($physicalDestination))
        throw new RuntimeException('destination-root-unavailable');
    elseif (!ErasedataFilesystemOps::payloadDirectoryTrusted($physicalDestination))
        throw new RuntimeException('destination-root-untrusted');
    $destPath = rtAddTailSlash($physicalDestination);
    if ($moveFiles) {
        $info = array('d.get_name', 'd.get_base_path', 'd.get_base_filename', 'd.is_multi_file');
        if (!$wasOpen) {
            array_unshift($info, 'd.open');
            $info[] = 'd.close';
        }
        $request = rtExec($info, $hash, $dbg);
        if (!$request || !is_array($request->val) || count($request->val) !== count($info)
            || (!$wasOpen && (string) end($request->val) !== '0'))
            throw new RuntimeException('unreadable-payload-metadata');
        $offset = $wasOpen ? 0 : 1;
        $name = $request->val[$offset];
        $basePath = $request->val[$offset + 1];
        $baseFile = $request->val[$offset + 2];
        $multi = (string) $request->val[$offset + 3] !== '0';
        if (!is_string($name) || !is_string($basePath) || !is_string($baseFile)
            || $basePath === '' || $baseFile === ''
            || ($multi && ($name === '' || $name === '.' || $name === '..'
                || strpos($name, '/') !== false || strpos($name, "\0") !== false))
            || $baseFile === '.' || $baseFile === '..'
            || strpos($baseFile, '/') !== false || strpos($baseFile, "\0") !== false)
            throw new RuntimeException('invalid-payload-path');
        $parent = dirname(rtrim($basePath, '/'));
        $source = $multi ? $parent . '/' . $baseFile : $parent;
        $destination = $multi && $addPath ? rtrim($destPath, '/') . '/' . $name
            : rtrim($destPath, '/');
        if (!is_dir($source))
            throw new RuntimeException('source-root-unavailable');
        $destinationAncestor = $destination;
        while (!is_dir($destinationAncestor) && dirname($destinationAncestor) !== $destinationAncestor)
            $destinationAncestor = dirname($destinationAncestor);
        $sourceStat = @stat($source);
        $destinationStat = @stat($destinationAncestor);
        if (!is_array($sourceStat) || !is_array($destinationStat))
            throw new RuntimeException('unreadable-move-root');
        if ($sourceStat['dev'] !== $destinationStat['dev'])
            throw new RuntimeException('cross-filesystem');
        $request = rtExec('f.multicall', array($hash, '', getCmd('f.get_path=')), $dbg);
        if (!$request || !is_array($request->val) || count($request->val) > DataDirMoveIntent::MAX_FILES)
            throw new RuntimeException('unreadable-file-list');
        foreach ($request->val as $file) {
            if (!is_string($file) || $file === '')
                throw new RuntimeException('invalid-file-list');
            $files[] = $file;
        }
        if (realpath($source) === realpath($destination))
            $moveFiles = false;
    }
    return array('hash' => strtoupper($hash), 'directory' => $destPath,
        'local_id' => $localId, 'previous_directory' => $previousDirectory,
        'add' => (bool) $addPath, 'move' => (bool) $moveFiles,
        'source' => $source, 'destination' => $destination, 'files' => $files);
}

// Read torrent metadata without opening it; temporary filesystem entries probe
// rename support before launch. The claimed worker remains authoritative.
function rtDataDirCollision($hash, $destPath, $addPath, $dbg = false)
{
    $snapshot = rtDataDirSnapshot($hash, $destPath, $addPath, true, $dbg, true);
    if ($snapshot === null)
    {
        // A closed torrent has no readable file list. Its destination mount
        // can still be checked without opening it or creating directories.
        rtDataDirProbeRename(null, $destPath, array());
        return '';
    }
    if (!$snapshot['move'])
        return '';
    rtDataDirProbeRename($snapshot['source'], $snapshot['destination'], $snapshot['files']);
    $root = @realpath($snapshot['destination']);
    if ($root === false)
        return '';
    foreach ($snapshot['files'] as $file) {
        if (!is_string($file) || $file === '' || $file[0] === '/'
            || strpos($file, "\0") !== false
            || in_array('', explode('/', $file), true)
            || in_array('.', explode('/', $file), true)
            || in_array('..', explode('/', $file), true))
            continue;
        $path = $root . '/' . $file;
        $parent = @realpath(dirname($path));
        if ($parent === false || ($parent !== $root
            && strpos($parent, $root . '/') !== 0))
            continue;
        if (@lstat($path) !== false)
            return $path;
    }
    return '';
}

// Both roots and the source and destination parent of every file the worker
// renames, through the shared actual-parent preflight. Each side must stay
// within its own root. A refusal throws its classified context (reason,
// path, errno) for the dialog and the caller's one log line.
function rtDataDirProbeRename($source, $destination, $files)
{
    // A root spelled with a trailing slash, the filesystem root included.
    $destination = rtrim($destination, '/') === '' ? '/' : rtrim($destination, '/');
    $sources = $source === null ? array() : array($source);
    $destinations = array($destination);
    foreach ($files as $relative) {
        if (!is_string($relative) || $relative === '' || $relative[0] === '/'
            || strpos($relative, "\0") !== false || in_array('..', explode('/', $relative), true))
            throw new RuntimeException('invalid-file-list');
        $sources[] = dirname($source . '/' . $relative);
        $destinations[] = dirname($destination . '/' . $relative);
    }
    // A source parent must exist: the worker renames each file out of it.
    // Only a destination parent may be created later, on its deepest
    // existing ancestor, which is what preflightParents() probes (R4-FS-02).
    foreach ($sources as $parent)
        if (!is_dir($parent))
            throw new RuntimeException('rename-noreplace-source-parent-unavailable path=' . erasedataLogPath($parent));
    $probed = array();
    $filesystem = rtDataDirFilesystem();
    if (($source !== null && !$filesystem->preflightParents($sources, $probed, $source))
        || !$filesystem->preflightParents($destinations, $probed, $destination))
    {
        $context = ErasedataFilesystemOps::lastRenameRefusalContext();
        throw new RuntimeException($context !== '' ? $context : 'rename-noreplace-preflight-refused');
    }
}

// Applies one directory change under the caller's profile lock. The caller
// has already validated the request and confirmed the claim ABI once; the
// zero-job state probe below still binds admission to this torrent. One line
// is logged per refusal. $warning names what a successful change left behind.
function rtSetDataDir($hash, $destPath, $addPath, $moveFiles, $fastResume, $dbg = false,
    &$failure = null, &$warning = null)
{
    $failure = null;
    $warning = '';
    if (!is_string($destPath) || $destPath === '' || !is_string($hash)
        || preg_match('/^[0-9A-Fa-f]{40}$/D', $hash) !== 1) {
        $failure = 'invalid-arguments';
        FileUtil::toLog('datadir: change refused: invalid-arguments');
        return false;
    }
    $hash = strtoupper($hash);
    $destPath = rtAddTailSlash($destPath);
    $path = null;
    $job = null;
    try {
        $journal = rtDataDirJournal();
        $path = $journal . '/' . $hash . '.json';
        if (file_exists($path) || is_link($path))
            throw new RuntimeException('prior-job-pending');
        if ($moveFiles && !rtDataDirFilesystem()->probeRenameParent(null))
            throw new RuntimeException(ErasedataFilesystemOps::lastRenameRefusalContext());
        rtDataDirOwnership($hash);
        $probe = rtDataDirClaimRpc('d.stop_close_claim_state', $hash,
            array(str_repeat('0', 32)));
        if ($probe !== 'absent')
            throw new RuntimeException('daemon-claim-unconfirmed');
        $snapshot = rtDataDirSnapshot($hash, $destPath, $addPath, $moveFiles, $dbg);
        if ($snapshot['move'])
            rtDataDirProbeRename($snapshot['source'], $snapshot['destination'], $snapshot['files']);
        if ($fastResume)
            FileUtil::toLog('datadir: ' . $hash
                . ' fast resume disabled: changing directory in place');
        $job = DataDirMoveJob::create($journal, $snapshot,
            'rtDataDirClaimRpc', 'rtDataDirMoveNoReplace', 'rtDataDirTorrentPresence');
        $job->run();
        if ($job->restoredStart())
            $warning = rtDataDirStartOutcome($hash, rtrim($snapshot['directory'], '/'));
        return true;
    } catch (DataDirMoveRefused $e) {
        $failure = $e->getMessage();
        FileUtil::toLog('datadir: change refused hash=' . $hash . ' reason=' . $failure);
        return false;
    } catch (Exception $e) {
        $failure = $e->getMessage();
        // A job created by this call renames its document in place at each
        // phase. Read as absent, such a held job would be logged as a plain
        // refusal with no outcome while its claim blocks the download.
        $pending = $path !== null && ($job !== null
            ? rtDataDirFilesystem()->renamedEntryIdentity($path) !== false
            : (file_exists($path) || is_link($path)));
        if ($pending)
            rtDataDirHeld($hash, $failure, $path);
        else
            FileUtil::toLog('datadir: change refused hash=' . $hash . ' reason=' . $failure);
        return false;
    }
}

// The claim restores a started download with d.start. Without the data in
// the new directory, rTorrent's own hash check stops it again ("registered as
// completed, but hash check returned unfinished chunks"), and the next
// successful tracker answer clears d.message. Report that stop: classified
// to the caller, once in the log, and as the download's kept outcome while
// it stays stopped (ACC-OWNB-01). A check still running at the bound is not
// a failure; rTorrent reports its own outcome.
function rtDataDirStartOutcome($hash, $directory)
{
    // A monotonic clock: a step of the wall clock cannot stretch or cut the wait.
    $deadline = hrtime(true) / 1e9 + DATADIR_START_CHECK_SECONDS;
    while (true) {
        $request = new rXMLRPCRequest(array(
            new rXMLRPCCommand(getCmd('d.hashing'), $hash),
            new rXMLRPCCommand(getCmd('d.state'), $hash)));
        $request->important = false;
        if (!$request->success() || !is_array($request->val) || count($request->val) !== 2)
            return '';
        if ((string) $request->val[0] === '0')
            break;
        if (hrtime(true) / 1e9 >= $deadline)
            return '';
        usleep(100000);
    }
    if ((string) $request->val[1] !== '0')
        return '';
    $warning = 'stopped-after-hash-check';
    FileOperationOutcome::record('datadir', $hash, $warning, 'datadir: ' . $warning . ' directory='
        . $directory . '; rTorrent did not find the complete data there and stopped the download;'
        . ' move the data there or set the previous directory, then start it', true);
    FileUtil::toLog('datadir: change applied hash=' . $hash . ' warning=' . $warning
        . ' directory=' . $directory);
    return $warning;
}

// A change refused in the detached worker of a payload move: the dialog saw
// only the launch, so the refusal is kept on the download where tracker
// traffic cannot erase it (ACC-OWNB-01). A held job also needs a visible
// outcome because its daemon lease prevents Start and another move.
function rtDataDirWorkerRefusal($hash, $failure)
{
    $hash = strtoupper((string) $hash);
    try {
        $journal = rtDataDirJournal();
        if (file_exists($journal . '/' . $hash . '.json') || is_link($journal . '/' . $hash . '.json')) {
            rtDataDirHeld($hash, $failure, $journal . '/' . $hash . '.json');
            return;
        }
    } catch (Exception $e) {
        // An unknown journal does not hide a refusal.
    }
    if (!FileOperationOutcome::record('datadir', $hash, (string) $failure, 'datadir: move refused: '
        . $failure . '; the data was not moved; resolve the cause, then set the directory again'))
        FileUtil::toLog('datadir: worker refusal not shown on the torrent hash=' . $hash);
}
