<?php

require_once '../../php/xmlrpc.php';
require_once './util_rt.php';
require_once __DIR__ . '/../rutracker_check/runstate.php';
require_once __DIR__ . '/../erasedata/filesystem.php';
require_once __DIR__ . '/movejob.php';

function rtDataDirClaimRpc($method, $hash, $args)
{
    $request = new rXMLRPCRequest(new rXMLRPCCommand(getCmd($method),
        array_merge(array($hash), $args)));
    $request->important = false;
    if (!$request->success() || $request->fault || count($request->val) !== 1)
        return null;
    return $request->val[0];
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

function rtDataDirMoveNoReplace($source, $destination)
{
    static $files = null;
    if ($files === null)
        $files = new ErasedataFilesystemOps();
    return $files->renameNoReplace($source, $destination);
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

function rtDataDirRecover()
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
                'rtDataDirClaimRpc', 'rtDataDirMoveNoReplace');
            $job->run();
        } catch (DataDirMoveRefused $e) {
            FileUtil::toLog('datadir: recovery refused hash=' . $hash . ' reason=' . $e->getMessage());
        } catch (Exception $e) {
            FileUtil::toLog('datadir: recovery held hash=' . $hash . ' reason=' . $e->getMessage());
            $ok = false;
        }
    }
    return $ok;
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
    if ($physicalDestination === false || !is_dir($physicalDestination))
        throw new RuntimeException('destination-root-unavailable');
    if (!DataDirMoveIntent::trustedDirectory($physicalDestination))
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
        $name = trim($request->val[$offset]);
        $basePath = trim($request->val[$offset + 1]);
        $baseFile = trim($request->val[$offset + 2]);
        $multi = (string) $request->val[$offset + 3] !== '0';
        if ($basePath === '' || $baseFile === ''
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

// A read-only hint for the dialog. The claimed worker remains authoritative.
function rtDataDirCollision($hash, $destPath, $addPath, $dbg = false)
{
    try {
        $snapshot = rtDataDirSnapshot($hash, $destPath, $addPath, true, $dbg, true);
        if ($snapshot === null || !$snapshot['move'])
            return '';
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
    } catch (Exception $e) {
        // The worker logs and handles uncertain metadata under its claim.
    }
    return '';
}

function rtSetDataDir($hash, $destPath, $addPath, $moveFiles, $fastResume, $dbg = false)
{
    if (!is_string($destPath) || $destPath === '' || !is_string($hash)
        || preg_match('/^[0-9A-Fa-f]{40}$/D', $hash) !== 1) {
        FileUtil::toLog('datadir: change refused: invalid-arguments');
        return false;
    }
    $hash = strtoupper($hash);
    $destPath = rtAddTailSlash($destPath);
    $path = null;
    try {
        if ($moveFiles && !ErasedataFilesystemOps::canRenameNoReplace())
            throw new RuntimeException('no-replace-helper-unavailable');
        rtDataDirOwnership($hash);
        // Confirm the whole claim ABI before publishing a new job. The state
        // probe still binds admission to this torrent and catches a later fault.
        $capability = rtDataDirClaimCapability();
        if ($capability !== 'available')
            throw new RuntimeException($capability === 'unsupported'
                ? 'daemon-claim-unavailable' : 'daemon-claim-unconfirmed');
        $probe = rtDataDirClaimRpc('d.stop_close_claim_state', $hash,
            array(str_repeat('0', 32)));
        if ($probe !== 'absent')
            throw new RuntimeException('daemon-claim-unconfirmed');
        $journal = rtDataDirJournal();
        $path = $journal . '/' . $hash . '.json';
        if (file_exists($path) || is_link($path))
            throw new RuntimeException('prior-job-pending');
        $snapshot = rtDataDirSnapshot($hash, $destPath, $addPath, $moveFiles, $dbg);
        if ($fastResume)
            FileUtil::toLog('datadir: ' . $hash
                . ' fast resume disabled: changing directory in place');
        $job = DataDirMoveJob::create($journal, $snapshot,
            'rtDataDirClaimRpc', 'rtDataDirMoveNoReplace');
        return $job->run();
    } catch (DataDirMoveRefused $e) {
        FileUtil::toLog('datadir: ' . $hash . ' change refused: ' . $e->getMessage());
        return false;
    } catch (Exception $e) {
        $pending = $path !== null && (file_exists($path) || is_link($path));
        FileUtil::toLog('datadir: ' . $hash . ($pending ? ' change held: ' : ' change refused: ')
            . $e->getMessage());
        return false;
    }
}
