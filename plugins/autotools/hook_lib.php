<?php

// The AutoTools finished hook for Copy, HardLink and SoftLink, shared by
// move.php, which rTorrent runs synchronously in execute.capture, and by
// recover.php, which performs the hooks move.php deferred.
//
// One-owner invariant (R4-FS-01): rTorrent's main thread runs move.php and is
// the thread that answers every RPC. move.php therefore never waits for the
// daemon and never waits for .autotools-file-jobs/.lock, because the other
// owner of that lock (recover.php) calls the daemon and waits for its session saves
// while they hold it. Only background processes may wait for that lock, so
// the daemon never waits on a holder that waits on the daemon. Multi-file
// hooks also resolve the daemon's selected paths only in the background.
// When the lock is busy, or the session copy is not published yet,
// move.php records its arguments as a request and returns at once;
// recover.php performs the request in the background, where waiting is
// harmless, one pass at a time (.recover.lock), within the retry window of
// rtFileJobRetryWindow(). A final refusal of the hook -- or a deferral that
// could not be recorded -- is named on the download by notify.php, which
// move.php starts detached and never waits for (notifyLater()); it reaches
// the daemon once the hook has returned.

require_once(dirname(__FILE__) . '/util_rt.php');
require_once(dirname(__FILE__) . '/autotools.php');
require_once(dirname(__FILE__) . '/../erasedata/filesystem.php');
require_once(dirname(__FILE__) . '/../erasedata/outcome.php');

final class AutoToolsFinishedHook
{
    const REQUEST_SUFFIX = '.nonmove-request.json';
    const NON_MOVE = array('Copy', 'HardLink', 'SoftLink');

    // move.php: perform the hook now when nothing would make rTorrent wait,
    // otherwise record it for recover.php. Returns what x-dest becomes.
    public static function hook(array $args, $dbg = false)
    {
        $result = self::perform($args, $dbg);
        if ($result['status'] === 'busy' || $result['status'] === 'copy-missing')
            self::defer($args, $result['status'] === 'busy' ? 'journal-busy' : 'session-copy-unreadable',
                $result['op']);
        elseif ($result['status'] === 'daemon-paths-needed')
            self::defer($args, 'daemon-paths-needed', $result['op']);
        elseif ($result['status'] === 'refused' && in_array($result['op'], self::NON_MOVE, true))
            self::notifyLater((string) $args[0], $result['op'], $result['reason'], self::user($args),
                $result['detail']);
        return $result['status'] === 'published' ? $result['destination'] : '';
    }

    private static function user(array $args)
    {
        return isset($args[6]) ? (string) $args[6] : '';
    }

    private static function refused($op, $reason, $detail = '')
    {
        return array('status' => 'refused', 'op' => $op, 'reason' => $reason, 'detail' => (string) $detail);
    }

    private static function directoryIdentity($directory)
    {
        if (!is_string($directory)) throw new RuntimeException('source-context-unconfirmed');
        try { $withoutTail = @rtrim($directory, '/'); }
        catch (Throwable $error) { throw new RuntimeException('source-context-unconfirmed', 0, $error); }
        if (!is_string($withoutTail)) throw new RuntimeException('source-context-unconfirmed');
        return $directory !== '' && $withoutTail === '' ? '/' : $withoutTail;
    }

    // Capture the current source and generation with its file table in one
    // read-only daemon callback. A delayed request cannot borrow a new load
    // of the same hash or a directory the user selected after completion.
    private static function backgroundFiles($hash, $info, $multi, $basePath, $baseName, $token)
    {
        $request = new rXMLRPCRequest(array(
            new rXMLRPCCommand('d.get_base_path', $hash),
            new rXMLRPCCommand('d.is_multi_file', $hash),
            new rXMLRPCCommand('d.get_base_filename', $hash),
            new rXMLRPCCommand('d.get_custom', array($hash, 'x-autotools-nonmove-job')),
            new rXMLRPCCommand('d.get_directory', $hash),
            new rXMLRPCCommand('f.multicall', array($hash, '', getCmd('f.get_path=')))));
        $request->important = false;
        if (!$request->success() || $request->fault
            || count($request->responseCommandValues) !== 6
            || count($request->responseCommandTypes) !== 6)
            throw new RuntimeException('source-context-unconfirmed');
        $values = $request->responseCommandValues;
        $types = $request->responseCommandTypes;
        foreach (array(0, 2, 3, 4) as $index)
            if (!in_array($types[$index], array('string', 'base64'), true) || !is_string($values[$index]))
                throw new RuntimeException('source-context-unconfirmed');
        if (!in_array($types[1], array('int', 'i4', 'i8', 'boolean'), true)
            || !in_array($values[1], array('0', '1'), true))
            throw new RuntimeException('source-context-unconfirmed');
        try { $directory = $multi ? $basePath : @dirname($basePath); }
        catch (Throwable $error) { throw new RuntimeException('source-context-unconfirmed', 0, $error); }
        if (self::directoryIdentity($values[4]) !== self::directoryIdentity($directory)
            || $values[1] !== ($multi ? '1' : '0') || $values[3] !== $token
            || ($values[0] !== '' && $values[0] !== $basePath)
            || ($values[2] !== '' && $values[2] !== $baseName))
            throw new RuntimeException('source-context-changed');
        // The later x-dest acknowledgement binds the captured frozen path.
        // Keep a cold object's request until it can prove that same path.
        if ($values[0] === '' || $values[2] === '')
            throw new RuntimeException('source-context-unconfirmed');
        $rows = $request->stringTable(1, 5);
        if ($multi) return AutoToolsFilePaths::fromTable($rows, $info);
        if ($rows === null || count($rows) !== 1) throw new RuntimeException('file-list-malformed');
        $paths = AutoToolsFilePaths::read($hash, $info, false, $rows[0][0]);
        if ($paths[0] !== $baseName) throw new RuntimeException('source-context-changed');
        return $paths;
    }

    // Plans one finished hook from rTorrent's arguments (hash, base path, base
    // file name, multi-file flag, url-encoded label, name, user, job token) and
    // runs the planned file operation. The status is 'published' (with the
    // destination for x-dest), 'refused' (an untrusted directory or an
    // unusable journal, logged with the hash; 'reason' and 'detail' name it
    // on the download), 'none' (another refusal is logged; a filter or a
    // download outside the default directory is skipped silently, by design),
    // 'busy' (another owner holds the journal), 'copy-missing',
    // 'daemon-paths-needed' (the synchronous hook must return before RPC),
    // or 'paths-unavailable' (background resolution may be retried).
    public static function perform(array $args, $dbg = false, $daemonReady = false)
    {
        $none = array('status' => 'none', 'op' => null);
        $hash = isset($args[0]) ? (string) $args[0] : '';
        if (preg_match('/^[0-9A-F]{40}$/D', $hash) !== 1) {
            FileUtil::toLog('autotools: file operation refused: invalid hash');
            return $none;
        }
        $basePath = isset($args[1]) ? (string) $args[1] : '';
        $baseName = isset($args[2]) ? (string) $args[2] : '';
        $isMulti = isset($args[3]) ? (string) $args[3] : '';
        $label = isset($args[4]) ? rawurldecode((string) $args[4]) : '';
        $name = isset($args[5]) ? (string) $args[5] : '';
        $token = isset($args[7]) ? (string) $args[7] : '';
        $at = rAutoTools::load();
        if (!$at->enable_move || @preg_match($at->automove_filter . (UTF::isValid($label) ? 'u' : ''), $label) != 1)
            return $none;
        $finished = $at->path_to_finished;
        $op = $at->fileop_type;
        if (!in_array($op, self::NON_MOVE, true)) return $none;
        $session = rTorrentSettings::get()->session;
        if ($finished === '' || empty($session))
            return $none;
        $finished = rtAddTailSlash($finished);
        $copy = rtAddTailSlash($session) . $hash . '.torrent';
        clearstatcache(true, $copy);
        if (!is_readable($copy))
            return array('status' => 'copy-missing', 'op' => $op);
        $directory = rTorrentSettings::get()->directory;
        if (empty($directory))
            return $none;
        $torrent = new Torrent($copy);
        if ($torrent->errors()) {
            FileUtil::toLog('autotools: file operation refused hash=' . $hash . ': session-copy-invalid');
            return self::refused($op, 'session-copy-invalid');
        }
        $directory = rtAddTailSlash($directory);
        $base = rtAddTailSlash(rtRemoveLastToken(rtRemoveTailSlash($basePath), '/'));
        $relative = rtGetRelativePath($directory, $base);
        // A download saved outside the default directory is never moved: an
        // empty relative path skips it, by design.
        if ($relative === '')
            return $none;
        if ($relative === './') $relative = '';
        $destination = rtAddTailSlash($finished . $relative);
        // The label is not appended twice when the folder already is the label.
        if ($at->addLabel && $label !== '' && $label !== trim($relative, '/'))
            $destination .= FileUtil::addslash($label);
        if ($at->addName && $name !== '')
            $destination .= FileUtil::addslash($name);
        $multi = $isMulti === '1';
        $sub = $multi ? rtAddTailSlash($baseName) : '';
        $source = $base . $sub;
        $target = $destination . $sub;
        if ($multi && !$daemonReady)
            return array('status' => 'daemon-paths-needed', 'op' => $op);
        try {
            $files = $daemonReady ? self::backgroundFiles($hash, $torrent->info, $multi, $basePath, $baseName, $token)
                : AutoToolsFilePaths::read($hash, $torrent->info, false, $baseName);
        } catch (RuntimeException $error) {
            if (in_array($error->getMessage(), array('file-list-unconfirmed', 'source-context-unconfirmed'), true))
                return array('status' => 'paths-unavailable', 'op' => $op, 'reason' => $error->getMessage());
            if ($error->getMessage() === 'source-context-changed') {
                FileUtil::toLog('autotools: nonmove ' . $hash . ' refused: source-context-changed');
                return self::refused($op, 'source-context-changed');
            }
            FileUtil::toLog('autotools: nonmove ' . $hash . ' refused: file-list-invalid: ' . $error->getMessage());
            return self::refused($op, 'file-list-invalid', $error->getMessage());
        }
        if (self::skipped($at->skip_move_for_files, $files, $dbg) || $source === $target || !is_dir($source))
            return $none;
        if (!rtMkDir($finished, 0755)) {
            FileUtil::toLog('autotools: file operation refused hash=' . $hash . ': finished-root-unavailable');
            return self::refused($op, 'finished-root-unavailable');
        }
        if (!rAutoTools::destinationWithinRoot($finished, $destination)) {
            FileUtil::toLog('autotools: file operation refused hash=' . $hash . ': destination-outside-finished-root');
            return self::refused($op, 'destination-outside-finished-root');
        }
        if ($dbg) rtDbg('AutoMove', 'Operation ' . $op . ' from ' . $source . ' to ' . $target);
        $context = array('hash' => $hash, 'token' => $token, 'sourceBase' => $basePath, 'destination' => $target);
        $context['notice'] = array('noticeDestination' => $destination, 'finishedRoot' => $finished,
                'torrentName' => $torrent->name());
        $result = rtOpFiles($files, $source, $target, $op, $dbg, $context);
        if ($result === null)
            return array('status' => 'busy', 'op' => $op);
        if (!$result) {
            // run() resets its refusal on entry and keeps one only for the
            // refusals named on the download.
            $refusal = in_array($op, self::NON_MOVE, true) ? AutoToolsFileTransaction::lastRefusal() : null;
            return $refusal === null ? array('status' => 'none', 'op' => $op)
                : self::refused($op, $refusal['reason'], $refusal['detail']);
        }
        return array('status' => 'published', 'op' => $op, 'destination' => $target);
    }

    private static function skipped($filter, array $files, $dbg)
    {
        if (strlen($filter) === 0)
            return false;
        foreach ($files as $file)
            if (preg_match($filter . (UTF::isValid($file) ? 'u' : ''), $file) === 1) {
                if ($dbg) rtDbg('AutoMove', 'skip filter ' . $filter . ' matched ' . $file);
                return true;
            }
        return false;
    }

    // The request is written whole under a unique name without the journal
    // lock: a recover.php pass sees it complete or not at all.
    private static function defer(array $args, $reason, $op)
    {
        $hash = (string) $args[0];
        if (!in_array($op, self::NON_MOVE, true)) return;
        $root = AutoToolsFileTransaction::journalRoot();
        $id = bin2hex(random_bytes(12));
        $body = JSON::encodeBytes(array('version' => 1, 'id' => $id, 'reason' => $reason, 'at' => time(),
            'args' => array_map('strval', array_values($args))));
        $staging = $root === false ? false : erasedataStagingFile($root, '.request-');
        $published = $staging !== false && is_string($body)
            && @file_put_contents($staging, $body) === strlen($body)
            && @rename($staging, $root . '/' . $id . self::REQUEST_SUFFIX);
        if (!$published) {
            if ($staging !== false) @unlink($staging);
            FileUtil::toLog('autotools: nonmove ' . $hash . ' refused: ' . $reason
                . '; the deferred request could not be recorded');
            self::notifyLater($hash, $op, $reason, self::user($args));
            return;
        }
        FileUtil::toLog('autotools: nonmove ' . $hash . ' deferred: ' . $reason
            . '; recover.php performs the ' . $op . ' within 60 s');
    }

    // The hook cannot call the daemon, which waits for it, so a refusal it
    // makes is named on the download by a detached notify.php once the daemon
    // is free: a deferral that could not even be recorded, or an operation
    // the journal cannot hold (no journal can be made in an unwritable
    // session directory, R4 fresh-journal gap), and an untrusted directory
    // (R4 trust refusal, L4-N6). Its standard streams are not rTorrent's
    // capture pipe, so the hook returns at once. A test replaces the spawn.
    private static $spawn = null;

    private static function notifyLater($hash, $op, $reason, $user, $detail = '')
    {
        // Not named $argv: that name marks a CLI entry point for the
        // CLI-only guard scanners (tests/php/CliOnlyEntrypointsTest.php).
        $arguments = array(dirname(__FILE__) . '/notify.php', $hash, $op, $reason, $user, $detail);
        if (is_callable(self::$spawn)) {
            call_user_func(self::$spawn, $arguments);
            return;
        }
        $command = Utility::escapeShellArg(Utility::getPHP());
        foreach ($arguments as $argument)
            $command .= ' ' . Utility::escapeShellArg($argument);
        @exec($command . ' < /dev/null > /dev/null 2>&1 &');
    }

    // What the download shows for a refusal notifyRefusal() names; null for
    // a reason it does not know.
    private static function refusalMessage($op, $reason, $detail)
    {
        $nothing = ', so nothing was copied or linked';
        switch ($reason) {
            case 'journal-busy':
            case 'session-copy-unreadable':
            case 'daemon-paths-needed':
                return 'autotools: ' . $op . ' refused: ' . $reason . '; the deferred request could not be'
                    . ' recorded in the session directory' . $nothing . '; check that rTorrent can write its'
                    . ' session directory and that no other user can';
            case 'journal-unavailable':
                return 'autotools: ' . $op . ' refused: journal-unavailable; the session directory cannot hold'
                    . ' the file-job journal (.autotools-file-jobs)' . $nothing . '; check its owner and mode';
            case 'untrusted-directory':
                return 'autotools: ' . $op . ' refused: untrusted-directory ' . $detail . '; a directory on this'
                    . ' path is writable by others or owned by another user' . $nothing
                    . '; correct its owner or mode';
            case 'destination-unavailable':
                return 'autotools: ' . $op . ' refused: destination-unavailable ' . $detail
                    . '; the directory could not be created' . $nothing;
            case 'source-context-changed':
                return 'autotools: ' . $op . ' refused: source-context-changed; the download source or job token'
                    . ' changed after its finished hook; source data is retained';
            case 'source-context-unconfirmed':
                return 'autotools: ' . $op . ' refused: source-context-unconfirmed; rTorrent did not confirm'
                    . ' the captured source within the retry window; source data is retained';
        }
        if (in_array($reason, array('session-copy-invalid', 'file-list-unconfirmed', 'file-list-invalid', 'finished-root-unavailable',
            'destination-outside-finished-root', 'journal-write-failed', 'file-operation-refused'), true))
            return 'autotools: ' . $op . ' refused: ' . $reason
                . '; inspect the file-job journal and errors.log; source data is retained';
        return null;
    }

    // notify.php, and recover.php for a deferred hook: name a refusal on the
    // download and keep it where tracker traffic cannot erase it
    // (ACC-OWNB-01).
    public static function notifyRefusal($hash, $op, $reason, $detail = '')
    {
        $message = preg_match('/^[0-9A-F]{40}$/D', (string) $hash) === 1 && in_array($op, self::NON_MOVE, true)
            && is_string($reason) && is_string($detail) ? self::refusalMessage($op, $reason, $detail) : null;
        if ($message === null)
            return false;
        if (FileOperationOutcome::record('autotools', $hash, $reason, $message))
            return true;
        FileUtil::toLog('autotools: nonmove ' . $hash . ' hold: refusal not shown on the torrent');
        return false;
    }

    // recover.php: perform the requests move.php deferred, oldest first. One
    // performer at a time; a pass that finds another performer leaves them to
    // it. A request whose journal is still busy waits for the next pass.
    public static function performDeferred()
    {
        $root = AutoToolsFileTransaction::journalRoot(false);
        if ($root === false) return;
        // The journal is under the session path: list it, never glob() it.
        $paths = erasedataDirectoryMatches($root, '', self::REQUEST_SUFFIX);
        if (!$paths) return;
        $lock = @fopen($root . '/.requests.lock', 'c');
        if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) fclose($lock);
            return;
        }
        try {
            $requests = array();
            foreach ($paths as $path) {
                $request = self::readRequest($path);
                if ($request === null) {
                    FileUtil::toLog('autotools: nonmove request ' . basename($path)
                        . ' refused: invalid request; the hook it held is not performed');
                    @unlink($path);
                    continue;
                }
                $requests[] = array($request['at'], $request['id'], $path, $request);
            }
            sort($requests);
            foreach ($requests as $entry) {
                list(, , $path, $request) = $entry;
                $args = $request['args'];
                $session = rTorrentSettings::get()->session;
                $copy = rtrim($session, '/') . '/' . $args[0] . '.torrent';
                // The session copy may still be on its way; recover.php may wait.
                rtAwaitSessionSave($args[0], function () use ($copy) {
                    clearstatcache(true, $copy);
                    return is_readable($copy);
                });
                $result = self::perform($args, false, true);
                if ($result['status'] === 'busy')
                    break;
                if ($result['status'] === 'copy-missing') {
                    // rTorrent may still publish the copy: a download that
                    // retrackers re-adds is briefly without one. Only a copy
                    // missing past the retry window is a refusal (ACC-OWNB-02).
                    if (time() - $request['at'] < rtFileJobRetryWindow())
                        continue;
                    self::refuseMissingCopy($args[0], $result['op']);
                }
                elseif ($result['status'] === 'paths-unavailable') {
                    if (time() - $request['at'] < rtFileJobRetryWindow())
                        continue;
                    FileUtil::toLog('autotools: nonmove ' . $args[0] . ' refused: ' . $result['reason']);
                    self::notifyRefusal($args[0], $result['op'], $result['reason']);
                }
                elseif ($result['status'] === 'refused')
                    self::notifyRefusal($args[0], $result['op'], $result['reason'], $result['detail']);
                elseif ($result['status'] === 'published')
                    FileOperationOutcome::clear('autotools', $args[0]);
                @unlink($path);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function readRequest($path)
    {
        $name = basename($path);
        $request = JSON::decodeBytes((string) @file_get_contents($path, false, null, 0, 65536));
        if (!is_array($request) || array_keys($request) !== array('version', 'id', 'reason', 'at', 'args')
            || $request['version'] !== 1 || !is_string($request['id'])
            || preg_match('/^[0-9a-f]{24}$/D', $request['id']) !== 1
            || $name !== $request['id'] . self::REQUEST_SUFFIX || !is_int($request['at'])
            || !in_array($request['reason'], array('journal-busy', 'session-copy-unreadable', 'daemon-paths-needed'), true)
            || !is_array($request['args']) || count($request['args']) < 7 || count($request['args']) > 8
            || array_values($request['args']) !== $request['args'])
            return null;
        foreach ($request['args'] as $arg)
            if (!is_string($arg)) return null;
        return preg_match('/^[0-9A-F]{40}$/D', $request['args'][0]) === 1 ? $request : null;
    }

    // A deferred hook whose session copy never appeared: named in the log and
    // on the torrent, where tracker traffic cannot erase it, like the Move
    // worker's refusal (R4-FS-04, ACC-OWNB-01). Every recovery pass of the
    // retry window waited for it, so the message names the window, not one
    // pass's bounded wait.
    private static function refuseMissingCopy($hash, $op)
    {
        FileUtil::toLog('autotools: nonmove ' . $hash . ' refused: session-copy-unreadable');
        $message = 'autotools: ' . $op . ' refused: session-copy-unreadable; rTorrent did not publish the'
            . ' session copy of this download within ' . (int) ceil(rtFileJobRetryWindow() / 60) . ' min,'
            . ' so nothing was copied or linked; check the session directory';
        if (!FileOperationOutcome::record('autotools', $hash, 'session-copy-unreadable', $message))
            FileUtil::toLog('autotools: nonmove ' . $hash . ' hold: refusal not shown on the torrent');
    }
}
