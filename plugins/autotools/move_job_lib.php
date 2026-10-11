<?php

require_once(dirname(__FILE__) . '/util_rt.php');
require_once(dirname(__FILE__) . '/autotools.php');
require_once(dirname(__FILE__) . '/move_queue.php');
require_once(dirname(__FILE__) . '/../erasedata/filesystem.php');
require_once(dirname(__FILE__) . '/../erasedata/outcome.php');

/** Whole-root Move backed by a durable daemon claim. */
class AutoToolsMoveJob
{
    const JOURNAL_DIR = '.autotools-file-jobs';
    static private function logHash($hash)
    {
        return is_string($hash) && preg_match('/^[0-9A-Fa-f]{40}$/D', $hash)
            ? $hash : '<invalid-hash>';
    }

    static private function log($hash, $reason)
    {
        FileUtil::toLog('autotools: move ' . self::logHash($hash) . ' ' . $reason);
    }

    static private function rpc($method, $params)
    {
        $req = new rXMLRPCRequest(new rXMLRPCCommand($method, $params));
        $req->important = false;
        if (!$req->success() || $req->fault || !is_array($req->val) || count($req->val) !== 1)
            throw new RuntimeException('rpc-' . $method . '-unconfirmed');
        return (string) $req->val[0];
    }

    static public function journalRoot()
    {
        $root = AutoToolsFileTransaction::journalRoot(true);
        if ($root === false) throw new RuntimeException('journal-unavailable');
        return $root;
    }

    static public function lock($root, $hash)
    {
        $previousUmask = umask(0077);
        try { $handle = @fopen($root . '/' . $hash . '.move.lock', 'c'); }
        finally { umask($previousUmask); }
        if ($handle === false) throw new RuntimeException('journal-lock-unavailable');
        if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); return null; }
        return $handle;
    }

    static private function jobPath($root, $hash)
    {
        return $root . '/' . $hash . '.move.json';
    }

    static public function writeJob($root, $job)
    {
        $encoded = json_encode($job, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) throw new RuntimeException('path-not-utf8');
        // Never tempnam()'s fallback outside the journal: see erasedataStagingFile().
        $tmp = erasedataStagingFile($root, '.move-');
        if ($tmp === false) throw new RuntimeException('journal-temp-failed');
        $bytes = @file_put_contents($tmp, $encoded);
        if ($bytes !== strlen($encoded) || !@rename($tmp, self::jobPath($root, $job['hash']))) {
            @unlink($tmp);
            throw new RuntimeException('journal-write-failed');
        }
    }

    static public function readJob($root, $hash, $settle = false)
    {
        $path = self::jobPath($root, $hash);
        clearstatcache(true, $path);
        // $settle: the journal this worker has just renamed into place, read
        // back through the shared bounded settle (see renamedIdentity()).
        if (($settle ? self::files()->renamedEntryIdentity($path) : @lstat($path)) === false) return null;
        if (!is_file($path)) throw new RuntimeException('journal-nonfile: path='
            . self::JOURNAL_DIR . '/' . self::logHash($hash)
            . '.move.json; Move held pending journal repair');
        $job = json_decode((string) @file_get_contents($path), true);
        if (!is_array($job) || !isset($job['hash'], $job['src'], $job['dst'], $job['identity'], $job['daemon_dir'])
            || $job['hash'] !== $hash || !isset($job['version'], $job['qid'], $job['kind'], $job['phase'])
            || $job['version'] !== 2 || $job['kind'] !== 'Move' || !is_string($job['qid'])
            || !preg_match('/^[0-9a-f]{32}$/D', $job['qid'])
            || !in_array($job['phase'], array('intent', 'claimed', 'receipted', 'staged', 'moved',
                'published', 'directory', 'started', 'quarantined', 'aborting',
                'notice-pending', 'notice-attempted', 'completed'), true)
            || ($job['phase'] === 'aborting' && !array_key_exists('abort_identity', $job)))
            throw new RuntimeException('journal-malformed');
        return $job;
    }

    static public function retireJob($root, $hash)
    {
        $path = self::jobPath($root, $hash);
        if (!@unlink($path)) {
            clearstatcache(true, $path);
            if (@lstat($path) !== false)
                throw new RuntimeException('journal-cleanup-failed');
        }
    }

    static private function identity($path)
    {
        clearstatcache(true, $path);
        $st = @lstat($path);
        return $st === false ? null : (string) $st['dev'] . ':' . (string) $st['ino'];
    }

    // identity() of a name this call has just renamed into place. On drvfs
    // over 9p (cache=0x5, the owner's Docker Desktop volumes) lstat of a new
    // name once answered ENOENT right after a successful rename. Only that
    // negative answer is asked again, within the shared bound; the first
    // present identity is compared exactly by the caller, and the rename is
    // never repeated.
    static private function renamedIdentity($path)
    {
        $entry = self::files()->renamedEntryIdentity($path);
        return $entry === false ? null : (string) $entry['dev'] . ':' . (string) $entry['ino'];
    }

    static private function claimState($job)
    {
        $raw = self::rpc('d.stop_close_claim_state', array($job['hash'], $job['qid']));
        if ($raw === 'absent') return array('phase'=>'absent', 'token'=>null);
        if (preg_match('/^active\|([0-9a-f]{32}:[0-9a-f]{16})\|[01]\|[01]\|[01]\|[0-9a-fA-F]{40}$/D', $raw, $parts)) {
            $phase = 'active'; $mode = null; $token = $parts[1];
        } elseif (preg_match('/^(terminal|done)\|(S|O|R|K)\|([0-9a-f]{32}:[0-9a-f]{16})\|[01]\|[01]\|[01]\|[0-9a-fA-F]{40}$/D', $raw, $parts)) {
            $phase = $parts[1]; $mode = $parts[2]; $token = $parts[3];
        } else throw new RuntimeException('daemon-claim-malformed');
        if (isset($job['token']) && $job['token'] !== '' && $job['token'] !== $token)
            throw new RuntimeException('daemon-claim-different');
        return array('phase'=>$phase, 'mode'=>$mode, 'token'=>$token, 'receipt'=>explode('|', $raw));
    }

    static private function acknowledgeClaim($job)
    {
        $state = self::claimState($job);
        if ($state['phase'] === 'absent') return;
        if ($state['phase'] === 'terminal') {
            try { self::rpc('d.replay_stop_close_claim', array($job['hash'], $job['qid'])); }
            catch (RuntimeException $e) { /* Read the durable receipt after an unknown reply. */ }
            $state = self::claimState($job);
        }
        if ($state['phase'] !== 'done') throw new RuntimeException('daemon-claim-ack-unconfirmed');
        try { self::rpc('d.ack_stop_close_claim', array($job['hash'], $job['qid'])); }
        catch (RuntimeException $e) { /* A committed acknowledgement may lose its reply. */ }
        if (self::claimState($job)['phase'] !== 'absent')
            throw new RuntimeException('daemon-claim-ack-unconfirmed');
    }

    static private function restoreState($job, $token)
    {
        $method = !empty($job['run_state']) ? 'd.start_if_stop_close_claim'
            : (!empty($job['run_open']) ? 'd.open_if_stop_close_claim' : 'd.release_stop_close_claim');
        try { $result = self::rpc($method, array($job['hash'], $token)); }
        catch (RuntimeException $e) {
            // Lost mutation replies are resolved by the durable receipt, never replayed here.
            $state = self::claimState($job);
            if ($state['phase'] !== 'terminal' && $state['phase'] !== 'done') throw $e;
            return '0';
        }
        if ($result !== '0' && $result !== '1') throw new RuntimeException('restore-result-unknown');
        return $result;
    }

    static private function abortBeforeMove($root, $job, $token, $reason = '')
    {
        $job['phase'] = 'aborting';
        if (!array_key_exists('abort_identity', $job)) $job['abort_identity'] = self::identity($job['src']);
        if ($reason !== '') $job['abort_reason'] = $reason;
        self::writeJob($root, $job);
        self::restoreState($job, $token);
        self::acknowledgeClaim($job);
        self::finishAbort($root, $job);
    }

    static private function finishAbort($root, $job)
    {
        // Persist the abort until native has durably accepted its retry outcome.
        if (!isset($job['abort_retry'])) {
            $job['abort_retry'] = AutoToolsMoveQueue::retryPlan($job['hash'], $job['qid'], 'preflight-refused');
            self::writeJob($root, $job);
        }
        if ($job['abort_retry'] !== null) AutoToolsMoveQueue::deferPlan($job['hash'], $job['qid'], $job['abort_retry']);
        if (!empty($job['abort_reason'])) {
            self::log($job['hash'], 'publication held: ' . $job['abort_reason']);
            self::showRefusal($job['hash'], 'preflight-refused', '; ' . $job['abort_reason']);
        }
        self::retireJob($root, $job['hash']);
        self::log($job['hash'], 'deferred: preflight-refused; original run intent reconciled');
    }

    static private function publishDirectory($job, $token)
    {
        $method = $job['multi'] ? 'd.directory.base.set_if_stop_close_claim'
            : 'd.directory.set_if_stop_close_claim';
        try { self::rpc($method, array($job['hash'], $token, $job['daemon_dir'])); }
        catch (RuntimeException $e) {
            if (self::rpc('d.get_directory', $job['hash']) !== $job['daemon_dir']) throw $e;
        }
        if (self::rpc('d.get_directory', $job['hash']) !== $job['daemon_dir'])
            throw new RuntimeException('daemon-directory-mismatch');
    }

    // The shared no-replace owner; a test may install a scripted subclass.
    static private $files = null;

    static private function files()
    {
        if (self::$files === null) self::$files = new ErasedataFilesystemOps();
        return self::$files;
    }

    // No directories asks only whether the shared helper is installed. A
    // refusal carries its classified reason, path, errno and operator action
    // in the exception; run() logs it once with the job hash.
    static private function requireRenameCapability(array $directories)
    {
        $probed = array();
        $ready = $directories === array() ? self::files()->probeRenameParent(null)
            : self::files()->preflightParents($directories, $probed);
        if (!$ready) {
            $report = ErasedataFilesystemOps::renameRefusalReport();
            throw new RuntimeException($report !== '' ? $report
                : 'rename-noreplace-preflight-refused');
        }
    }

    static private function renameNoReplace($src, $dst)
    {
        return self::files()->renameNoReplace($src, $dst);
    }

    // The classified helper refusal of the last renameNoReplace(), or why it
    // has none, for the caller's single log line or exception.
    static private function renameRefusal()
    {
        $report = ErasedataFilesystemOps::renameRefusalReport();
        return $report !== '' ? $report : 'rename-noreplace-refused';
    }

    static private function manifestPaths($job)
    {
        $files = array(); $dirs = array();
        foreach ($job['files'] as $file) {
            $relative = $job['multi'] ? $file : '';
            $files[$relative] = true;
            if ($job['multi']) {
                $parts = explode('/', $relative);
                array_pop($parts);
                $prefix = '';
                foreach ($parts as $part) {
                    $prefix = $prefix === '' ? $part : $prefix . '/' . $part;
                    $dirs[$prefix] = true;
                }
            }
        }
        return array($files, $dirs);
    }

    static private function treePath($root, $relative)
    {
        return $relative === '' ? $root : $root . '/' . $relative;
    }

    static private function checkTreeNames($job, $root, $complete)
    {
        list($files, $dirs) = self::manifestPaths($job);
        if (!$job['multi']) {
            if (is_link($root) || !is_file($root)) throw new RuntimeException('payload-type-mismatch');
            return;
        }
        if (is_link($root) || !is_dir($root)) throw new RuntimeException('payload-type-mismatch');
        $seen = array();
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $entry) {
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            if ($entry->isLink()) throw new RuntimeException('payload-extra-entry');
            if ($entry->isDir()) {
                if (!isset($dirs[$relative])) throw new RuntimeException('payload-extra-entry');
            } elseif ($entry->isFile()) {
                if (!isset($files[$relative])) throw new RuntimeException('payload-extra-entry');
                $seen[$relative] = true;
            } else throw new RuntimeException('payload-extra-entry');
        }
        if ($complete && array_keys($seen) != array_keys($files)) {
            ksort($seen); ksort($files);
            if (array_keys($seen) !== array_keys($files))
                throw new RuntimeException('payload-manifest-mismatch');
        }
    }

    static private function copyStage($root, &$job)
    {
        $stage = $job['stage'];
        if (self::identity($stage) !== null)
            throw new RuntimeException('stage-already-exists');
        if ($job['multi'] && !@mkdir($stage, 0700))
            throw new RuntimeException('stage-create-failed');
        $receipt = array('files'=>array(), 'dirs'=>array());
        list($files, $dirs) = self::manifestPaths($job);
        foreach ($dirs as $relative => $_) {
            $from = self::treePath($job['src'], $relative);
            $to = self::treePath($stage, $relative);
            if (!@mkdir($to, 0700) && !is_dir($to))
                throw new RuntimeException('stage-directory-failed');
            $id = self::identity($from);
            if ($id === null || is_link($from) || !is_dir($from))
                throw new RuntimeException('source-directory-changed');
            $receipt['dirs'][$relative] = $id;
        }
        foreach ($files as $relative => $_) {
            $from = self::treePath($job['src'], $relative);
            $to = self::treePath($stage, $relative);
            $id = self::identity($from);
            if ($id === null || is_link($from) || !is_file($from) || !@copy($from, $to))
                throw new RuntimeException('stage-copy-failed');
            $hash = @hash_file('sha256', $from);
            $copied = @hash_file('sha256', $to);
            $size = @filesize($from);
            if ($hash === false || $copied !== $hash || $size === false
                || @filesize($to) !== $size || self::identity($from) !== $id)
                throw new RuntimeException('stage-receipt-mismatch');
            $receipt['files'][$relative] = array('id'=>$id, 'hash'=>$hash, 'size'=>$size);
            $stat = @stat($from);
            if ($stat !== false) {
                @chmod($to, $stat['mode'] & 0777);
                @touch($to, $stat['mtime'], $stat['atime']);
            }
        }
        self::checkTreeNames($job, $job['src'], true);
        self::checkTreeNames($job, $stage, true);
        $job['receipt'] = $receipt;
        $job['stage_identity'] = self::identity($stage);
        if ($job['stage_identity'] === null) throw new RuntimeException('stage-identity-missing');
        $job['phase'] = 'staged';
        self::writeJob($root, $job);
    }

    static private function verifyReceipt($job, $path, $source)
    {
        if (!isset($job['receipt']['files'], $job['receipt']['dirs']))
            throw new RuntimeException('receipt-missing');
        self::checkTreeNames($job, $path, true);
        foreach ($job['receipt']['dirs'] as $relative => $id) {
            $entry = self::treePath($path, $relative);
            if (!is_dir($entry) || is_link($entry)
                || ($source && self::identity($entry) !== $id))
                throw new RuntimeException('directory-receipt-mismatch');
        }
        foreach ($job['receipt']['files'] as $relative => $entry) {
            $file = self::treePath($path, $relative);
            if (!is_file($file) || is_link($file)
                || ($source && self::identity($file) !== $entry['id'])
                || @filesize($file) !== $entry['size']
                || @hash_file('sha256', $file) !== $entry['hash'])
                throw new RuntimeException('file-receipt-mismatch');
        }
    }

    static private function discardStage($job)
    {
        $stage = $job['stage'];
        if (self::identity($stage) === null) return;
        self::checkTreeNames($job, $stage, false);
        if (!$job['multi']) {
            if (!@unlink($stage)) throw new RuntimeException('stage-cleanup-failed');
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                if (!@rmdir($entry->getPathname())) throw new RuntimeException('stage-cleanup-failed');
            } elseif (!@unlink($entry->getPathname()))
                throw new RuntimeException('stage-cleanup-failed');
        }
        if (!@rmdir($stage)) throw new RuntimeException('stage-cleanup-failed');
    }

    static private function deleteQuarantine($job)
    {
        $path = $job['quarantine'];
        if (self::identity($path) !== $job['identity'])
            throw new RuntimeException('quarantine-identity-mismatch');
        self::checkTreeNames($job, $path, false);
        if (!$job['multi']) {
            $entry = $job['receipt']['files'][''];
            if (@hash_file('sha256', $path) !== $entry['hash'] || @filesize($path) !== $entry['size'])
                throw new RuntimeException('quarantine-content-mismatch');
            if (!@unlink($path)) throw new RuntimeException('quarantine-delete-failed');
            return;
        }
        foreach ($job['receipt']['files'] as $relative => $entry) {
            $file = self::treePath($path, $relative);
            if (self::identity($file) === null) continue;
            if (self::identity($file) !== $entry['id'] || is_link($file)
                || @hash_file('sha256', $file) !== $entry['hash']
                || @filesize($file) !== $entry['size'] || !@unlink($file))
                throw new RuntimeException('quarantine-file-changed');
        }
        $dirs = array_keys($job['receipt']['dirs']);
        usort($dirs, function ($a, $b) { return strlen($b) - strlen($a); });
        foreach ($dirs as $relative) {
            $dir = self::treePath($path, $relative);
            if (self::identity($dir) === null) continue;
            if (self::identity($dir) !== $job['receipt']['dirs'][$relative] || !@rmdir($dir))
                throw new RuntimeException('quarantine-directory-changed');
        }
        if (!@rmdir($path)) throw new RuntimeException('quarantine-root-not-empty');
    }

    static private function quarantineCrossSource($root, $job)
    {
        self::verifyReceipt($job, $job['dst'], false);
        $src = self::identity($job['src']);
        $quarantine = self::identity($job['quarantine']);
        if ($src !== null && $src !== $job['identity'])
            throw new RuntimeException('source-replaced-after-publish');
        if ($quarantine !== null && $quarantine !== $job['identity'])
            throw new RuntimeException('quarantine-replaced');
        if ($src !== null && $quarantine !== null)
            throw new RuntimeException('source-and-quarantine-present');
        if ($src !== null) {
            self::verifyReceipt($job, $job['src'], true);
            if (!self::renameNoReplace($job['src'], $job['quarantine']))
                throw new RuntimeException('quarantine-rename-failed: ' . self::renameRefusal());
            $job['phase'] = 'quarantined';
            self::writeJob($root, $job);
            return true;
        }
        return false;
    }

    static private function markNoticePending($root, $job)
    {
        $job['phase'] = 'notice-pending';
        self::writeJob($root, $job);
    }

    static private function finishCrossSource($root, $job, $claimPresent, $quarantined = false)
    {
        if (self::rpc('d.get_directory', $job['hash']) !== $job['daemon_dir'])
            throw new RuntimeException('post-start-directory-changed');
        self::verifyReceipt($job, $job['dst'], false);
        if (self::identity($job['src']) !== null)
            throw new RuntimeException('source-still-public');
        // Read as absent, a quarantine this call renamed would never be
        // deleted: the job would retire and leave the whole source copy behind.
        $quarantine = $quarantined ? self::renamedIdentity($job['quarantine'])
            : self::identity($job['quarantine']);
        if ($quarantine !== null && $quarantine !== $job['identity'])
            throw new RuntimeException('quarantine-replaced');
        // Still absent after the bounded settle: hold rather than retire. The
        // next worker resumes at 'started' and reads the quarantine again.
        if ($quarantined && $quarantine === null)
            throw new RuntimeException('quarantine-unconfirmed');
        if ($quarantine !== null) self::deleteQuarantine($job);
        if ($claimPresent) self::acknowledgeClaim($job);
        self::markNoticePending($root, $job);
        self::log($job['hash'], 'physical commit: cross-mount payload at destination; source retired');
    }

    static private function captureReceipt($root, &$job)
    {
        if (self::identity($job['src']) !== $job['identity'])
            throw new RuntimeException('source-identity-changed');
        self::checkTreeNames($job, $job['src'], true);
        list($files, $dirs) = self::manifestPaths($job);
        $receipt = array('files'=>array(), 'dirs'=>array());
        foreach ($dirs as $relative => $_) {
            $entry = self::treePath($job['src'], $relative);
            $id = self::identity($entry);
            if ($id === null || is_link($entry) || !is_dir($entry))
                throw new RuntimeException('source-directory-changed');
            $receipt['dirs'][$relative] = $id;
        }
        foreach ($files as $relative => $_) {
            $file = self::treePath($job['src'], $relative);
            $id = self::identity($file);
            $size = @filesize($file);
            $hash = @hash_file('sha256', $file);
            if ($id === null || is_link($file) || !is_file($file) || $size === false
                || $hash === false || self::identity($file) !== $id || @filesize($file) !== $size)
                throw new RuntimeException('source-file-changed');
            $receipt['files'][$relative] = array('id'=>$id,'size'=>$size,'hash'=>$hash);
        }
        if (self::identity($job['src']) !== $job['identity'])
            throw new RuntimeException('source-identity-changed');
        self::checkTreeNames($job, $job['src'], true);
        $job['receipt'] = $receipt;
        $job['phase'] = 'receipted';
        self::writeJob($root, $job);
    }

    static private function reconcileCross($root, $job, $token)
    {
        $hash = $job['hash'];
        $src = self::identity($job['src']);
        $dst = self::identity($job['dst']);
        if ($dst === null) {
            if ($src !== $job['identity'])
                throw new RuntimeException('source-identity-ambiguous');
            try {
                if (!isset($job['stage_identity'])) {
                    self::discardStage($job);
                    self::copyStage($root, $job);
                } else {
                    if (self::identity($job['stage']) !== $job['stage_identity'])
                        throw new RuntimeException('stage-identity-mismatch');
                    self::verifyReceipt($job, $job['src'], true);
                    self::verifyReceipt($job, $job['stage'], false);
                }
            } catch (Throwable $e) {
                self::discardStage($job);
                self::abortBeforeMove($root, $job, $token, 'cross-mount stage failed: ' . $e->getMessage());
                return false;
            }
            if (!self::renameNoReplace($job['stage'], $job['dst'])) {
                $refusal = self::renameRefusal();
                if (self::identity($job['src']) === $job['identity']) {
                    self::discardStage($job);
                    self::abortBeforeMove($root, $job, $token, 'stage publication refused: ' . $refusal);
                    return false;
                }
                throw new RuntimeException('stage-publish-ambiguous: ' . $refusal);
            }
            $dst = self::renamedIdentity($job['dst']);
            $job['phase'] = 'published';
            self::writeJob($root, $job);
        }
        if (!isset($job['stage_identity']) || $dst !== $job['stage_identity']
            || self::identity($job['stage']) !== null)
            throw new RuntimeException('published-receipt-ambiguous');
        self::verifyReceipt($job, $job['dst'], false);
        self::publishDirectory($job, $token);
        $job['phase'] = 'directory';
        self::writeJob($root, $job);
        $quarantined = self::quarantineCrossSource($root, $job);
        $started = self::restoreState($job, $token);
        if ($started !== '0' && $started !== '1') throw new RuntimeException('reopen-result-unknown');
        if ($started === '1' && !empty($job['run_state']) && (self::rpc('d.is_open', $hash) !== '1'
            || self::rpc('d.is_active', $hash) !== '1'
            || self::rpc('d.get_base_path', $hash) !== $job['dst']))
            throw new RuntimeException('final-open-state-mismatch');
        $job['phase'] = 'started';
        self::writeJob($root, $job);
        self::finishCrossSource($root, $job, true, $quarantined);
        return true;
    }

    static private function verifyCompleted($job)
    {
        if (self::rpc('d.get_directory', $job['hash']) !== $job['daemon_dir']
            || self::identity($job['src']) !== null)
            throw new RuntimeException('completed-state-changed');
        if (!empty($job['cross'])) {
            if (!isset($job['stage_identity'])
                || self::identity($job['dst']) !== $job['stage_identity']
                || self::identity($job['stage']) !== null
                || self::identity($job['quarantine']) !== null)
                throw new RuntimeException('completed-cross-payload-ambiguous');
            self::verifyReceipt($job, $job['dst'], false);
        } else {
            if (self::identity($job['dst']) !== $job['identity'])
                throw new RuntimeException('completed-payload-ambiguous');
            self::verifyReceipt($job, $job['dst'], true);
        }
    }

    static public function deliverNotice($root, $hash)
    {
        // run() calls this under its lock once reconcile() has kept the job,
        // usually just after renaming its journal into place, so an absent
        // answer is asked again.
        $job = self::readJob($root, $hash, true);
        if ($job === null) return;
        if (!in_array($job['phase'], array('notice-pending', 'notice-attempted', 'completed'), true))
            throw new RuntimeException('notification-phase-ambiguous');
        self::verifyCompleted($job);
        FileOperationOutcome::clear('autotools', $hash);
        if ($job['phase'] === 'notice-pending') {
            $job['phase'] = 'notice-attempted';
            self::writeJob($root, $job);
            if (rAutoTools::completionMailFile($job['dest_dir'], $job['finished_root']) !== null)
                rAutoTools::notifyCompletedFileTransfer($job['dest_dir'], $job['finished_root'], $job['torrent_name']);
        }
        // Persist completion before native Q retirement, so a lost finish reply
        // or a crash before journal removal can be swept without repeating mail.
        $job['phase'] = 'completed';
        self::writeJob($root, $job);
        AutoToolsMoveQueue::finish($hash, $job['qid']);
        self::retireJob($root, $hash);
        self::log($hash, 'completed: payload published');
    }

    static private function finishTerminal($root, $job)
    {
        $hash = $job['hash'];
        $src = self::identity($job['src']);
        $dst = self::identity($job['dst']);
        if ($job['phase'] === 'aborting' && $src === $job['abort_identity']
            && self::rpc('d.get_directory', $hash) === $job['source_dir']) {
            self::acknowledgeClaim($job);
            self::finishAbort($root, $job);
            return false;
        }
        if ($src === null && self::rpc('d.get_directory', $hash) === $job['daemon_dir']) {
            if (!empty($job['cross'])) {
                if (!isset($job['stage_identity']) || $dst !== $job['stage_identity'])
                    throw new RuntimeException('terminal-cross-payload-ambiguous');
                self::verifyReceipt($job, $job['dst'], false);
                self::finishCrossSource($root, $job, true);
            } else {
                if ($dst !== $job['identity'])
                    throw new RuntimeException('terminal-payload-ambiguous');
                self::verifyReceipt($job, $job['dst'], true);
                self::acknowledgeClaim($job);
                self::markNoticePending($root, $job);
            }
            self::log($hash, 'recovered: terminal daemon receipt and payload reconciled');
            return true;
        }
        throw new RuntimeException('terminal-with-ambiguous-payload');
    }

    static private function reconcile($root, $job, $incarnation)
    {
        $hash = $job['hash'];
        if (in_array($job['phase'], array('notice-pending', 'notice-attempted', 'completed'), true)) return true;
        $state = self::claimState($job);
        $src = self::identity($job['src']);
        $dst = self::identity($job['dst']);
        $expected = $job['identity'];
        if ($state['phase'] === 'absent') {
            if ($job['phase'] === 'aborting' && $src === $job['abort_identity']
                && self::rpc('d.get_directory', $hash) === $job['source_dir']) {
                self::finishAbort($root, $job);
                return false;
            }
            if ($job['phase'] !== 'intent') {
                self::verifyCompleted($job);
                self::markNoticePending($root, $job);
                return true;
            }
            if ($src !== $expected || $dst !== null) {
                AutoToolsMoveQueue::defer($hash, $job['qid'], 'preflight-refused');
                self::retireJob($root, $hash);
                return false;
            }
        }
        if ($state['phase'] === 'terminal' || $state['phase'] === 'done')
            return self::finishTerminal($root, $job);
        if ($state['phase'] !== 'active' && $state['phase'] !== 'absent')
            throw new RuntimeException('daemon-claim-phase-unknown');
        // Lease publication precedes Stop+Close. Re-enter the idempotent native begin
        // after every crash; an active receipt alone does not attest quiescence.
        $receipt = self::rpc('d.autotools.move_begin', array($hash, $job['qid'], $incarnation));
        if (strpos($receipt, 'busy:') === 0) {
            if ($state['phase'] !== 'absent') throw new RuntimeException('leased-begin-unconfirmed');
            AutoToolsMoveQueue::defer($hash, $job['qid'], 'source-not-ready');
            return false;
        }
        if (!preg_match('/^active\|[0-9a-f]{32}:[0-9a-f]{16}\|[01]\|[01]\|[01]\|[0-9a-fA-F]{40}$/D', $receipt))
            throw new RuntimeException('begin-receipt-malformed');
        $state = self::claimState($job);
        if ($state['phase'] !== 'active') throw new RuntimeException('begin-phase-unconfirmed');
        $fields = $state['receipt'];
        $job['token'] = $state['token'];
        $job['run_state'] = (int)$fields[2];
        $job['run_open'] = (int)$fields[3];
        $job['run_active'] = (int)$fields[4];
        if ($job['phase'] === 'intent') $job['phase'] = 'claimed';
        self::writeJob($root, $job);
        if ($job['phase'] === 'aborting') {
            self::abortBeforeMove($root, $job, $state['token']);
            return false;
        }
        $token = $state['token'];
        $src = self::identity($job['src']);
        $dst = self::identity($job['dst']);
        if (!empty($job['cross'])) {
            return self::reconcileCross($root, $job, $token);
        }

        if ($src !== $expected && $dst === null) {
            self::abortBeforeMove($root, $job, $token, 'source-identity-changed');
            return false;
        }
        if ($src === $expected && $dst !== null) {
            self::abortBeforeMove($root, $job, $token, 'destination occupied before publication');
            return false;
        }
        if ($src === $expected && $dst === null) {
            try {
                if (!isset($job['receipt'])) self::captureReceipt($root, $job);
                else self::verifyReceipt($job, $job['src'], true);
            } catch (Throwable $e) {
                self::abortBeforeMove($root, $job, $token, 'source receipt failed: ' . $e->getMessage());
                return false;
            }
            if (!self::renameNoReplace($job['src'], $job['dst'])) {
                $refusal = self::renameRefusal();
                if (ErasedataFilesystemOps::isCrossDeviceRenameRefusal()
                    && self::identity($job['src']) === $expected && self::identity($job['dst']) === null) {
                    $job['cross'] = true;
                    self::writeJob($root, $job);
                    return self::reconcileCross($root, $job, $token);
                }
                if (self::identity($job['src']) === $expected) {
                    self::abortBeforeMove($root, $job, $token, 'publication refused: ' . $refusal);
                    return false;
                }
                throw new RuntimeException('rename-failed-ambiguous: ' . $refusal);
            }
            $src = self::identity($job['src']);
            $dst = self::renamedIdentity($job['dst']);
        }
        if ($src !== null || $dst !== $expected)
            throw new RuntimeException('payload-identity-ambiguous');
        self::verifyReceipt($job, $job['dst'], true);
        $job['phase'] = 'moved';
        self::writeJob($root, $job);

        self::publishDirectory($job, $token);
        $job['phase'] = 'published';
        self::writeJob($root, $job);

        $started = self::restoreState($job, $token);
        if ($started !== '0' && $started !== '1') throw new RuntimeException('reopen-result-unknown');
        if (self::rpc('d.get_directory', $hash) !== $job['daemon_dir'])
            throw new RuntimeException('final-directory-mismatch');
        if ($started === '1' && !empty($job['run_state']) && (self::rpc('d.is_open', $hash) !== '1'
            || self::rpc('d.is_active', $hash) !== '1'
            || self::rpc('d.get_base_path', $hash) !== $job['dst']))
            throw new RuntimeException('final-open-state-mismatch');
        self::acknowledgeClaim($job);
        self::markNoticePending($root, $job);
        self::log($hash, $started === '1' && !empty($job['run_state']) ? 'physical commit: payload and active daemon at destination'
            : 'physical commit: payload at destination; later Stop preserved');
        return true;
    }

    static private function validateSourceManifest($src, $files, $multi)
    {
        if (is_link($src) || ($multi && !is_dir($src)) || (!$multi && !is_file($src)))
            throw new RuntimeException('source-type-mismatch');
        if ($multi) {
            $actual = array();
            $directories = array();
            foreach ($files as $file) {
                $parts = explode('/', $file);
                array_pop($parts);
                $prefix = '';
                foreach ($parts as $part) {
                    $prefix = $prefix === '' ? $part : $prefix . '/' . $part;
                    $directories[$prefix] = true;
                }
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src,
                FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $entry) {
                $relativePath = substr($entry->getPathname(), strlen($src) + 1);
                if ($entry->isLink()) throw new RuntimeException('source-extra-entry');
                if ($entry->isDir()) {
                    if (!isset($directories[$relativePath])) throw new RuntimeException('source-extra-entry');
                } elseif ($entry->isFile()) $actual[] = $relativePath;
                else throw new RuntimeException('source-extra-entry');
            }
            sort($actual); sort($files);
            if ($actual !== $files) throw new RuntimeException('source-manifest-mismatch');
        }
    }

    static private function prepare($hash, $jobId)
    {
        $sourceDirectory = self::rpc('d.get_directory', $hash);
        $multi = self::rpc('d.is_multi_file', $hash) === '1';
        $baseName = self::rpc('d.get_base_filename', $hash);
        $basePath = $multi ? $sourceDirectory : rtrim($sourceDirectory, '/') . '/' . $baseName;
        $label = rawurldecode(self::rpc('d.get_custom1', $hash));
        $name = self::rpc('d.get_name', $hash);
        if (self::rpc('d.complete', $hash) !== '1') throw new RuntimeException('source-not-ready');
        foreach (array($basePath, $baseName, $label, $name) as $value)
            if (preg_match('//u', $value) !== 1) throw new RuntimeException('path-not-utf8');
        $at = rAutoTools::load();
        if (!$at->enable_move || $at->fileop_type !== 'Move'
            || @preg_match($at->automove_filter . 'u', $label) !== 1)
            throw new RuntimeException('configuration-declined');
        $session = rTorrentSettings::get()->session;
        $torrentPath = rtrim($session, '/') . '/' . $hash . '.torrent';
        if (!self::sessionCopyReady($hash, $torrentPath)) throw new RuntimeException('session-copy-unreadable');
        $torrent = new Torrent($torrentPath);
        if ($torrent->errors()) throw new RuntimeException('session-copy-invalid');
        $info = $torrent->info;
        $files = array();
        if ($multi && isset($info['files'])) {
            foreach ($info['files'] as $file) $files[] = implode('/', $file['path']);
        } elseif (!$multi && isset($info['name'])) $files[] = $info['name'];
        if (!$files) throw new RuntimeException('manifest-empty');
        foreach ($files as $file) {
            if (!is_string($file) || $file === '' || $file[0] === '/'
                || preg_match('~(^|/)\.\.?(/|$)~', $file))
                throw new RuntimeException('manifest-path-invalid');
            if (strlen($at->skip_move_for_files) && @preg_match($at->skip_move_for_files . 'u', $file) === 1)
                throw new RuntimeException('skip-filter-match');
        }
        $sourceDir = rtAddTailSlash(dirname($basePath));
        $downloads = rtAddTailSlash(rTorrentSettings::get()->directory);
        $relative = rtGetRelativePath($downloads, $sourceDir);
        if ($relative === '') throw new RuntimeException('source-outside-downloads');
        if ($relative === './') $relative = '';
        $finished = rtrim($at->path_to_finished, '/');
        if ($finished === '') throw new RuntimeException('destination-unavailable');
        $destDir = rtAddTailSlash($finished . '/' . $relative);
        if ($at->addLabel && $label !== '' && $label !== trim($relative, '/'))
            $destDir .= FileUtil::addslash($label);
        if ($at->addName && $name !== '') $destDir .= FileUtil::addslash($name);
        $destDir = rtrim($destDir, '/');
        $src = $basePath;
        // The payload trust rule shared with datadir (R3-OWN-01) for both
        // parents as spelled (R4-FS-03): the destination before anything is
        // created through it, and both before a claim, a probe or a payload
        // copy.
        if (!ErasedataFilesystemOps::payloadPathTrusted($destDir, null, true))
            throw new RuntimeException('untrusted-directory: ' . erasedataLogPath(ErasedataFilesystemOps::lastPayloadRefusalPath() ?: $destDir));
        // Created directories are at most 0755, like the non-move operations:
        // the umask only removes bits, so they are never group/other-writable,
        // which the payload rule refuses above the configured top.
        if (!rtMkDir($finished, 0755)) throw new RuntimeException('destination-unavailable');
        if (!rAutoTools::destinationWithinRoot($finished, $destDir))
            throw new RuntimeException('destination-outside-finished-root');
        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir))
            throw new RuntimeException('destination-parent-unavailable');
        if (!rAutoTools::destinationWithinRoot($finished, $destDir))
            throw new RuntimeException('destination-outside-finished-root');
        foreach (array(dirname($src), $destDir) as $directory)
            if (!ErasedataFilesystemOps::payloadPathTrusted($directory))
                throw new RuntimeException('untrusted-directory: ' . erasedataLogPath(ErasedataFilesystemOps::lastPayloadRefusalPath() ?: $directory));
        $dst = $destDir . '/' . $baseName;
        if (realpath($src) === realpath($dst) || file_exists($dst) || is_link($dst))
            throw new RuntimeException('destination-occupied');
        $sourceStat = @lstat($src);
        $destStat = @stat($destDir);
        if ($sourceStat === false || $destStat === false)
            throw new RuntimeException('source-or-destination-unavailable');
        $cross = $sourceStat['dev'] !== $destStat['dev'];
        if (!$multi && $baseName !== $info['name']) throw new RuntimeException('source-name-mismatch');
        self::validateSourceManifest($src, $files, $multi);
        // Probe both actual publication/quarantine parents through the shared
        // preflight before a daemon claim or any payload copy: the source
        // parent holds the quarantine, the destination parent the stage.
        self::requireRenameCapability(array(dirname($src), $destDir));
        return array('version'=>2, 'qid'=>$jobId, 'kind'=>'Move', 'hash'=>$hash, 'phase'=>'intent',
            'src'=>$src, 'dst'=>$dst, 'identity'=>self::identity($src),
            'source_dir'=>$sourceDirectory, 'dest_dir'=>$destDir,
            'daemon_dir'=>$multi ? $dst : $destDir, 'multi'=>$multi, 'files'=>$files,
            'cross'=>$cross, 'stage'=>$destDir.'/.autotools-move-'.$jobId.'.stage',
            'quarantine'=>dirname($src).'/.autotools-move-'.$jobId.'.source',
            'token'=>'',
            'finished_root'=>$finished, 'torrent_name'=>$torrent->name());
    }

    // A final refusal is named on the download itself and kept where tracker
    // traffic cannot erase it (ACC-OWNB-01); a download that is gone gets
    // the log line only.
    static public function showRefusal($hash, $reason, $detail = '')
    {
        if ($reason === 'session-copy-unreadable')
            $message = 'autotools: move refused: session-copy-unreadable' . $detail . '; rTorrent did not'
                . ' publish the session copy of this download, so the data stays in place; check the'
                . ' session directory, then move it with Set directory';
        elseif ($reason === 'download-stopped')
            $message = 'autotools: move refused: download-stopped; the download was stopped before its'
                . ' Move could claim it, so the data stays in place; move it with Set directory';
        elseif (in_array($reason, array('session-unavailable', 'journal-unavailable', 'journal-lock-unavailable',
            'journal-temp-failed', 'request-encode-failed', 'request-write-failed'), true))
            $message = 'autotools: move refused: ' . $reason . $detail . '; the Move could not be recorded in'
                . ' rTorrent\'s session directory (' . self::JOURNAL_DIR . '), so the data stays in place; check'
                . ' that rTorrent can write its session directory and that no other user can, then move it with'
                . ' Set directory';
        else
            $message = 'autotools: move refused: ' . $reason . $detail . '; the data stays in place;'
                . ' resolve the cause, then move it with Set directory';
        if (!FileOperationOutcome::record('autotools', $hash, $reason, $message))
            self::log($hash, 'hold: refusal not shown on the torrent');
    }

    // rTorrent 0.16 writes a new download's session copy from its session
    // thread, seconds after the add; a small download can finish first. The
    // shared rtAwaitSessionSave() asks for the save and waits for the copy
    // within its bound, so a fast finish moves instead of being refused.
    static private function sessionCopyReady($hash, $path)
    {
        return rtAwaitSessionSave($hash, function () use ($path) {
            clearstatcache(true, $path);
            return is_readable($path);
        });
    }

    static public function run($hash, $qid, $user, $incarnation)
    {
        $lock = null; $job = null;
        try {
            $state = AutoToolsMoveQueue::state($hash);
            if (!AutoToolsMoveQueue::owns($state, $qid, $user)) return 0;
            $root = self::journalRoot();
            $lock = self::lock($root, $hash);
            if ($lock === null) return 0;
            $job = self::readJob($root, $hash);
            if ($job !== null && $job['qid'] !== $qid) {
                self::showRefusal($hash, 'journal-foreign');
                AutoToolsMoveQueue::defer($hash, $qid, 'preflight-refused');
                return 0;
            }
            if ($job === null) {
                if ($state['phase'] === 'leased') throw new RuntimeException('journal-missing-for-lease');
                try { $job = self::prepare($hash, $qid); }
                catch (Throwable $e) {
                    AutoToolsMoveQueue::planningFailure($hash, $qid, $e->getMessage());
                    return 0;
                }
                self::writeJob($root, $job);
            }
            if (self::reconcile($root, $job, $incarnation)) self::deliverNotice($root, $hash);
            return 0;
        } catch (Throwable $e) {
            self::log($hash, 'held: ' . $e->getMessage() . '; journal='
                . self::JOURNAL_DIR . '/' . $hash . '.move.json; ownership retained');
            return 1;
        } finally { if ($lock !== null) { flock($lock, LOCK_UN); fclose($lock); } }
    }

    static public function recoverCompleted()
    {
        $root = self::journalRoot();
        $paths = erasedataDirectoryMatches($root, '', '.move.json');
        if ($paths === false) throw new RuntimeException('journal-scan-failed');
        foreach ($paths as $path) {
            $hash = basename($path, '.move.json');
            if (!preg_match('/^[0-9A-F]{40}$/D', $hash)) continue;
            $lock = null;
            try {
                $lock = self::lock($root, $hash);
                if ($lock === null) continue;
                $job = self::readJob($root, $hash);
                if ($job !== null && $job['phase'] === 'completed'
                    && AutoToolsMoveQueue::state($hash)['phase'] === 'none') self::retireJob($root, $hash);
            } catch (Throwable $e) {
                self::log($hash, 'completed recovery held: ' . $e->getMessage()
                    . '; journal=' . $path . '; unrelated recovery continues');
            } finally { if ($lock !== null) { flock($lock, LOCK_UN); fclose($lock); } }
        }
    }
}
