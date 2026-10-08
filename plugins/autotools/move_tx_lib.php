<?php

require_once(dirname(__FILE__) . '/util_rt.php');
require_once(dirname(__FILE__) . '/autotools.php');
require_once(dirname(__FILE__) . '/../rutracker_check/runstate.php');
require_once(dirname(__FILE__) . '/../erasedata/filesystem.php');
require_once(dirname(__FILE__) . '/../erasedata/presence.php');
require_once(dirname(__FILE__) . '/../erasedata/outcome.php');

/** Whole-root Move backed by a durable daemon claim. */
class AutoToolsMoveTransaction
{
    const JOURNAL_DIR = '.autotools-file-jobs';
    // How long after a first sight of a gone download a later pass must
    // still find it gone before its waiting job is retired: one recovery
    // schedule.
    const ABSENT_CONFIRM_SECONDS = 60;

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

    static private function journalRoot()
    {
        $session = rTorrentSettings::get()->session;
        if (!is_string($session) || $session === '' || !is_dir($session))
            throw new RuntimeException('session-unavailable');
        $root = rtrim($session, '/') . '/' . self::JOURNAL_DIR;
        if (!is_dir($root) && !@mkdir($root, 0700) && !is_dir($root))
            throw new RuntimeException('journal-unavailable');
        return $root;
    }

    static private function lock($root)
    {
        $previousUmask = umask(0077);
        try { $handle = @fopen($root . '/.lock', 'c'); }
        finally { umask($previousUmask); }
        if ($handle === false || !flock($handle, LOCK_EX))
            throw new RuntimeException('journal-lock-unavailable');
        return $handle;
    }

    static private function jobPath($root, $hash)
    {
        return $root . '/' . $hash . '.move.json';
    }

    static private function writeJob($root, $job)
    {
        $encoded = json_encode($job, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) throw new RuntimeException('journal-encode-failed');
        // Never tempnam()'s fallback outside the journal: see erasedataStagingFile().
        $tmp = erasedataStagingFile($root, '.move-');
        if ($tmp === false) throw new RuntimeException('journal-temp-failed');
        $bytes = @file_put_contents($tmp, $encoded);
        if ($bytes !== strlen($encoded) || !@rename($tmp, self::jobPath($root, $job['hash']))) {
            @unlink($tmp);
            throw new RuntimeException('journal-write-failed');
        }
    }

    static private function readJob($root, $hash)
    {
        $path = self::jobPath($root, $hash);
        clearstatcache(true, $path);
        if (@lstat($path) === false) return null;
        if (!is_file($path)) throw new RuntimeException('journal-nonfile: path='
            . self::JOURNAL_DIR . '/' . self::logHash($hash)
            . '.move.json; Move held pending journal repair');
        $job = json_decode((string) @file_get_contents($path), true);
        if (!is_array($job) || !isset($job['hash'], $job['src'], $job['dst'], $job['identity'], $job['daemon_dir'])
            || $job['hash'] !== $hash)
            throw new RuntimeException('journal-malformed');
        return $job;
    }

    static private function retireJob($root, $hash)
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

    static private function claimState($job)
    {
        $raw = self::rpc('d.stop_close_claim_state', array($job['hash'], $job['job_id']));
        if ($raw === 'absent') return array('phase'=>'absent', 'token'=>null);
        if (preg_match('/^active\|([0-9a-f]{32}:[0-9a-f]{16})\|[01]\|[01]\|[01]\|[0-9a-fA-F]{40}$/D', $raw, $parts)) {
            $phase = 'active'; $mode = null; $token = $parts[1];
        } elseif (preg_match('/^(terminal|done)\|(S|O|R|K)\|([0-9a-f]{32}:[0-9a-f]{16})\|[01]\|[01]\|[01]\|[0-9a-fA-F]{40}$/D', $raw, $parts)) {
            $phase = $parts[1]; $mode = $parts[2]; $token = $parts[3];
        } else throw new RuntimeException('daemon-claim-malformed');
        if (isset($job['token']) && $job['token'] !== '' && $job['token'] !== $token)
            throw new RuntimeException('daemon-claim-different');
        return array('phase'=>$phase, 'mode'=>$mode, 'token'=>$token);
    }

    static private function acknowledgeClaim($job)
    {
        $state = self::claimState($job);
        if ($state['phase'] === 'terminal') {
            self::rpc('d.replay_stop_close_claim', array($job['hash'], $job['job_id']));
            $state = self::claimState($job);
        }
        if ($state['phase'] !== 'done'
            || self::rpc('d.ack_stop_close_claim', array($job['hash'], $job['job_id'])) !== '1')
            throw new RuntimeException('daemon-claim-ack-unconfirmed');
    }

    static private function claimIfDue($root, &$job)
    {
        $q = function ($value) { return RuTrackerAtomicOwnership::quoteRtorrentArgument($value); };
        $marker = 'equal=' . getCmd('d.get_custom=') . 'x-autotools-move-job,cat=' . $job['job_id'];
        $base = 'equal=' . $q(getCmd('d.get_base_path=')) . ','
            . $q('cat=' . $q($job['src']));
        $condition = 'and=' . $q('equal=d.is_active=,value=1') . ','
            . $q('equal=d.is_open=,value=1') . ',' . $q($marker) . ',' . $q($base);
        $receipt = self::rpc('branch', array($job['hash'], $condition,
            'cat="$d.stop_close_claim=' . $job['job_id'] . '"', 'cat=SKIP'));
        if ($receipt === 'SKIP') return false;
        if (!preg_match('/^([0-9a-f]{32}:[0-9a-f]{16})\|[01]\|[01]\|[01]\|[0-9a-fA-F]{40}$/D', $receipt, $parts))
            throw new RuntimeException('claim-receipt-unconfirmed');
        $job['token'] = $parts[1];
        $job['phase'] = 'claimed';
        // A wait for the download ends with its claim; a later one starts its own window.
        unset($job['waiting_since'], $job['absent_since']);
        self::writeJob($root, $job);
        return true;
    }

    // A download that is not open and active when its claim is due. While
    // retrackers re-adds it (its claim answers anything but none, or it is
    // queued) or rTorrent is to run it but has not opened or activated it yet
    // (d.state 1: loading, checking hashes), the job is kept and recovery
    // claims it again within rtFileJobRetryWindow(), with one 'deferred' line.
    // Measured on 0.16.25 (L4 lab B, 2026-10-08): absent for 3-5 ms, then
    // closed -- d.state 0, empty d.base_path, retrackers-queued set, claim
    // held -- for 59-197 ms, then d.state 1 hashing with the claim finalized,
    // then open and active; 7 of 160 public Moves on 9p were refused for good
    // in that window. A download its user stopped is refused at once, and one
    // that still does not run past the window then, both visibly.
    static private function waitForDownload($root, $job)
    {
        $hash = $job['hash'];
        $pending = self::runPending($hash);
        $detail = '';
        if ($pending !== null) {
            if (!isset($job['waiting_since']) || !is_int($job['waiting_since'])) {
                $job['waiting_since'] = time();
                self::writeJob($root, $job);
                self::log($hash, 'deferred: download-not-running (' . $pending
                    . '); recover.php claims it again every 60 s');
                return false;
            }
            if (time() - $job['waiting_since'] < rtFileJobRetryWindow()) return false;
            $reason = 'download-not-running';
            $detail = ' unresolved for ' . (int) ceil(rtFileJobRetryWindow() / 60) . ' min';
        } else $reason = 'download-stopped';
        self::retireJob($root, $hash);
        self::log($hash, 'refused: ' . $reason . $detail);
        self::showRefusal($hash, $reason, $detail);
        return false;
    }

    // Why a download that is not running yet may still run: its retrackers
    // claim, its retrackers queue entry, or d.state 1; null for one its user
    // stopped. Only answers decide a stop: an unanswered claim waits like a
    // held one, and an unanswered queue or state read holds the job (it
    // throws), so a transient failure in the re-add window is no final
    // refusal. The claim ABI Move needs comes with the native queue.
    static private function runPending($hash)
    {
        $claim = self::retrackersClaim($hash);
        if ($claim === null) return 'retrackers-claim-unconfirmed';
        if ($claim !== 'none') return 'retrackers-claim';
        if (self::rpc(getCmd('d.get_custom'), array($hash, 'retrackers-queued')) !== '')
            return 'retrackers-queued';
        return self::rpc('d.state', $hash) === '1' ? 'starting' : null;
    }

    // $reason names why the move stopped; the one log line carries it, and
    // the download keeps it where tracker traffic cannot erase it.
    static private function abortBeforeMove($root, $job, $token, $reason = '')
    {
        $job['phase'] = 'aborting';
        self::writeJob($root, $job);
        $result = self::rpc('d.start_if_stop_close_claim', array($job['hash'], $token));
        if ($result !== '0' && $result !== '1') throw new RuntimeException('abort-result-unknown');
        self::acknowledgeClaim($job);
        self::retireJob($root, $job['hash']);
        self::log($job['hash'], 'refused: ' . ($reason === '' ? '' : $reason . '; ')
            . 'physical move did not publish; original run intent reconciled');
        if ($reason !== '') self::showRefusal($job['hash'], $reason);
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
        }
    }

    static private function markNoticePending($root, $job)
    {
        $job['phase'] = 'notice-pending';
        self::writeJob($root, $job);
    }

    static private function finishCrossSource($root, $job, $claimPresent)
    {
        if (self::rpc('d.get_directory', $job['hash']) !== $job['daemon_dir'])
            throw new RuntimeException('post-start-directory-changed');
        self::verifyReceipt($job, $job['dst'], false);
        if (self::identity($job['src']) !== null)
            throw new RuntimeException('source-still-public');
        $quarantine = self::identity($job['quarantine']);
        if ($quarantine !== null && $quarantine !== $job['identity'])
            throw new RuntimeException('quarantine-replaced');
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
            $dst = self::identity($job['dst']);
            $job['phase'] = 'published';
            self::writeJob($root, $job);
        }
        if (!isset($job['stage_identity']) || $dst !== $job['stage_identity']
            || self::identity($job['stage']) !== null)
            throw new RuntimeException('published-receipt-ambiguous');
        self::verifyReceipt($job, $job['dst'], false);
        $published = self::rpc('d.directory.set_if_stop_close_claim',
            array($hash, $token, $job['dest_dir']));
        if ($published !== '1' && self::rpc('d.get_directory', $hash) !== $job['daemon_dir'])
            throw new RuntimeException('daemon-directory-unconfirmed');
        if (self::rpc('d.get_directory', $hash) !== $job['daemon_dir'])
            throw new RuntimeException('daemon-directory-mismatch');
        $job['phase'] = 'directory';
        self::writeJob($root, $job);
        self::quarantineCrossSource($root, $job);
        $started = self::rpc('d.start_if_stop_close_claim', array($hash, $token));
        if ($started !== '0' && $started !== '1') throw new RuntimeException('reopen-result-unknown');
        if ($started === '1' && (self::rpc('d.is_open', $hash) !== '1'
            || self::rpc('d.is_active', $hash) !== '1'
            || self::rpc('d.get_base_path', $hash) !== $job['dst']))
            throw new RuntimeException('final-open-state-mismatch');
        $job['phase'] = 'started';
        self::writeJob($root, $job);
        self::finishCrossSource($root, $job, true);
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

    static private function deliverNotice($root, $hash)
    {
        $job = self::readJob($root, $hash);
        if ($job === null) return;
        if ($job['phase'] === 'notice-attempted') {
            self::log($hash, 'hold: completion mail outcome uncertain; journal retained for inspection');
            return;
        }
        if ($job['phase'] !== 'notice-pending')
            throw new RuntimeException('notification-phase-ambiguous');
        self::verifyCompleted($job);
        // A completed Move retires the outcome of an earlier refusal.
        FileOperationOutcome::clear('autotools', $hash);
        if (rAutoTools::completionMailFile($job['dest_dir'], $job['finished_root']) === null) {
            self::retireJob($root, $hash);
            self::log($hash, 'completed: payload published; no .mailto');
            return;
        }
        // Persist attempt before the non-idempotent mail transport call.
        $job['phase'] = 'notice-attempted';
        self::writeJob($root, $job);
        $sent = rAutoTools::notifyCompletedFileTransfer($job['dest_dir'],
            $job['finished_root'], $job['torrent_name']);
        self::retireJob($root, $hash);
        self::log($hash, $sent ? 'completed: payload published; completion mail sent'
            : 'completed: payload published; completion mail refused');
    }

    static private function finishTerminal($root, $job)
    {
        $hash = $job['hash'];
        $src = self::identity($job['src']);
        $dst = self::identity($job['dst']);
        if ($job['phase'] === 'aborting' && $src === $job['identity'] && $dst === null
            && self::rpc('d.get_directory', $hash) === $job['source_dir']) {
            self::acknowledgeClaim($job);
            self::retireJob($root, $hash);
            self::log($hash, 'refused: original daemon state restored after move abort');
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

    static private function reconcile($root, $job)
    {
        $hash = $job['hash'];
        if ($job['phase'] === 'notice-attempted') return true;
        try { $state = self::claimState($job); }
        catch (RuntimeException $e) {
            // A planned Move that holds no claim and has not touched the
            // payload belongs to a download that may be gone: one removed
            // while its claim waited is retired, not held for ever. While
            // retrackers re-adds it the download is briefly absent too, and
            // its claim says so (absence()); an erase and reload without a
            // retrackers claim (edit) is brief as well, so the job is retired
            // only when a pass at least one schedule later still finds it gone.
            if ($job['phase'] !== 'intent' || !preg_match('/^rpc-[A-Za-z0-9_.]+-unconfirmed$/D', $e->getMessage())
                || self::absence($hash) !== 'gone')
                throw $e;
            if (!isset($job['absent_since']) || !is_int($job['absent_since'])) {
                $job['absent_since'] = time();
                self::writeJob($root, $job);
                throw $e;
            }
            if (time() - $job['absent_since'] < self::ABSENT_CONFIRM_SECONDS)
                throw $e;
            self::retireJob($root, $hash);
            self::log($hash, 'refused: download-absent');
            return false;
        }
        if (isset($job['absent_since'])) {
            unset($job['absent_since']);
            self::writeJob($root, $job);
        }
        if ($job['phase'] === 'notice-pending') {
            self::verifyCompleted($job);
            if ($state['phase'] !== 'absent') self::acknowledgeClaim($job);
            return true;
        }
        $src = self::identity($job['src']);
        $dst = self::identity($job['dst']);
        $expected = $job['identity'];
        if ($state['phase'] === 'absent') {
            if (!empty($job['cross']) && isset($job['stage_identity'])
                && $dst === $job['stage_identity']
                && self::rpc('d.get_directory', $hash) === $job['daemon_dir']) {
                self::verifyReceipt($job, $job['dst'], false);
                self::finishCrossSource($root, $job, false);
                return true;
            }
            if (empty($job['cross']) && $src === null && $dst === $expected
                && self::rpc('d.get_directory', $hash) === $job['daemon_dir']) {
                self::verifyReceipt($job, $job['dst'], true);
                self::markNoticePending($root, $job);
                self::log($hash, 'recovered: physical destination and daemon directory published');
                return true;
            }
            if ($src !== $expected || $dst !== null)
                throw new RuntimeException('missing-claim-with-ambiguous-state');
            if ($job['phase'] === 'aborting') {
                if (self::rpc('d.get_directory', $hash) !== $job['source_dir'])
                    throw new RuntimeException('aborted-daemon-directory-ambiguous');
                self::retireJob($root, $hash);
                self::log($hash, 'refused: move abort was already reconciled');
                return false;
            }
            if (!self::claimIfDue($root, $job)) {
                // A failed session save can lose this marker across daemon restart.
                $marker = self::rpc(getCmd('d.get_custom'), array($hash, 'x-autotools-move-job'));
                if ($marker === '' && $job['phase'] === 'intent') {
                    // Retrackers re-added the download without it (jobMarker());
                    // the next pass claims with the marker back.
                    if (self::restoreJobMarker($hash, $job['job_id']) !== $job['job_id'])
                        throw new RuntimeException('job-marker-missing-or-changed');
                    self::log($hash, 'deferred: job marker restored after the download was re-added;'
                        . ' recover.php claims it again every 60 s');
                    return false;
                }
                if ($marker !== $job['job_id'])
                    throw new RuntimeException('job-marker-missing-or-changed');
                $base = self::rpc('d.get_base_path', $hash);
                // A download that is not open reports no base path at all, so
                // only another path proves that the source moved.
                if ($base !== '' && $base !== $job['src']) {
                    self::retireJob($root, $hash);
                    self::log($hash, 'refused: daemon source path changed');
                    return false;
                }
                if (self::rpc('d.is_active', $hash) === '1' && self::rpc('d.is_open', $hash) === '1')
                    throw new RuntimeException('claim-precondition-unconfirmed');
                return self::waitForDownload($root, $job);
            }
            $state = self::claimState($job);
        }
        if ($state['phase'] === 'active') {
            $receipt = self::rpc('d.stop_close_claim', array($hash, $job['job_id']));
            if (!preg_match('/^[0-9a-f]{32}:[0-9a-f]{16}\|[01]\|[01]\|[01]\|[0-9a-fA-F]{40}$/D', $receipt))
                throw new RuntimeException('claim-quiescence-unconfirmed');
            $state = self::claimState($job);
            if ($state['phase'] !== 'active')
                throw new RuntimeException('claim-phase-changed-before-move');
            if ($job['phase'] === 'intent') {
                $job['token'] = $state['token'];
                $job['phase'] = 'claimed';
                self::writeJob($root, $job);
            }
            if ($job['phase'] === 'aborting') {
                self::abortBeforeMove($root, $job, $state['token']);
                return false;
            }
        } elseif ($state['phase'] === 'terminal' || $state['phase'] === 'done')
            return self::finishTerminal($root, $job);
        else throw new RuntimeException('daemon-claim-phase-unknown');
        $token = $state['token'];
        $src = self::identity($job['src']);
        $dst = self::identity($job['dst']);
        if (!empty($job['cross'])) {
            return self::reconcileCross($root, $job, $token);
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
                if (self::identity($job['src']) === $expected) {
                    self::abortBeforeMove($root, $job, $token, 'publication refused: ' . $refusal);
                    return false;
                }
                throw new RuntimeException('rename-failed-ambiguous: ' . $refusal);
            }
            $src = self::identity($job['src']);
            $dst = self::identity($job['dst']);
        }
        if ($src !== null || $dst !== $expected)
            throw new RuntimeException('payload-identity-ambiguous');
        self::verifyReceipt($job, $job['dst'], true);
        $job['phase'] = 'moved';
        self::writeJob($root, $job);

        $published = self::rpc('d.directory.set_if_stop_close_claim',
            array($hash, $token, $job['dest_dir']));
        if ($published !== '1' && self::rpc('d.get_directory', $hash) !== $job['daemon_dir'])
            throw new RuntimeException('daemon-directory-unconfirmed');
        if (self::rpc('d.get_directory', $hash) !== $job['daemon_dir'])
            throw new RuntimeException('daemon-directory-mismatch');
        $job['phase'] = 'published';
        self::writeJob($root, $job);

        $started = self::rpc('d.start_if_stop_close_claim', array($hash, $token));
        if ($started !== '0' && $started !== '1') throw new RuntimeException('reopen-result-unknown');
        if (self::rpc('d.get_directory', $hash) !== $job['daemon_dir'])
            throw new RuntimeException('final-directory-mismatch');
        if ($started === '1' && (self::rpc('d.is_open', $hash) !== '1'
            || self::rpc('d.is_active', $hash) !== '1'
            || self::rpc('d.get_base_path', $hash) !== $job['dst']))
            throw new RuntimeException('final-open-state-mismatch');
        self::acknowledgeClaim($job);
        self::markNoticePending($root, $job);
        self::log($hash, $started === '1' ? 'physical commit: payload and active daemon at destination'
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

    static private function prepare($args)
    {
        if (count($args) < 8) throw new RuntimeException('worker-arguments-missing');
        self::requireRenameCapability(array());
        $hash = (string) $args[0];
        if (!preg_match('/^[0-9A-F]{40}$/D', $hash)) throw new RuntimeException('hash-invalid');
        $basePath = rtrim((string) $args[1], '/');
        $baseName = (string) $args[2];
        $multi = (string) $args[3] === '1';
        $label = rawurldecode((string) $args[4]);
        $name = (string) $args[5];
        $jobId = (string) $args[7];
        if (!preg_match('/^[0-9a-f]{32}$/D', $jobId) || self::jobMarker($hash, $jobId) !== $jobId)
            throw new RuntimeException('job-marker-mismatch');
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
            throw new RuntimeException('untrusted-directory: ' . $destDir);
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
                throw new RuntimeException('untrusted-directory: ' . $directory);
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
        $nonce = $jobId;
        return array('version'=>1, 'kind'=>'Move', 'hash'=>$hash, 'phase'=>'intent',
            'src'=>$src, 'dst'=>$dst, 'identity'=>self::identity($src),
            'source_dir'=>rtrim($sourceDir, '/'), 'dest_dir'=>$destDir,
            'daemon_dir'=>$multi ? $dst : $destDir, 'multi'=>$multi, 'files'=>$files,
            'cross'=>$cross, 'stage'=>$destDir.'/.autotools-move-'.$nonce.'.stage',
            'quarantine'=>dirname($src).'/.autotools-move-'.$nonce.'.source',
            'job_id'=>$nonce, 'token'=>'',
            'finished_root'=>$finished, 'torrent_name'=>$torrent->name());
    }

    // The Move job marker of a download, for the job $jobId. Retrackers
    // re-adds a public download from the original it captured when the
    // download was inserted, so a download that finished in that window comes
    // back without the marker its finished hook set, and its Move was refused
    // as 'job-marker-mismatch' (1 of 40 public Moves, L4b lab, 2026-10-08). An
    // empty marker is not another job's: it is put back for $jobId, only while
    // it is still empty (restoreJobMarker()). A marker of another job is left
    // alone; that job owns the download.
    static private function jobMarker($hash, $jobId)
    {
        $marker = self::rpc(getCmd('d.get_custom'), array($hash, 'x-autotools-move-job'));
        if ($marker !== '') return $marker;
        $marker = self::restoreJobMarker($hash, $jobId);
        if ($marker === $jobId)
            self::log($hash, 'job marker restored after the download was re-added');
        return $marker;
    }

    // Sets the marker to $jobId in one daemon branch only while it is empty,
    // asks for the session save that keeps it across a restart, and answers
    // the marker the daemon then holds.
    static private function restoreJobMarker($hash, $jobId)
    {
        // A download the user removed and added again while a request
        // waited is not finished yet: its own hook will move it. Only a
        // complete download, as a re-add brings back a finished one, gets the
        // marker of the job its earlier finish planned.
        if (self::rpc('d.complete', $hash) !== '1') return '';
        $condition = 'equal=' . getCmd('d.get_custom=') . 'x-autotools-move-job,cat=';
        self::rpc('branch', array($hash, $condition,
            getCmd('d.set_custom') . '=x-autotools-move-job,' . $jobId, 'cat=SKIP'));
        $save = new rXMLRPCRequest(new rXMLRPCCommand(getCmd('d.save_full_session'), array($hash)));
        $save->important = false;
        $save->success();
        return self::rpc(getCmd('d.get_custom'), array($hash, 'x-autotools-move-job'));
    }

    // A final refusal is named on the download itself and kept where tracker
    // traffic cannot erase it (ACC-OWNB-01); a download that is gone gets
    // the log line only.
    static private function showRefusal($hash, $reason, $detail = '')
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

    static private function clearUnjournaledOldHookMarker($root, $hash, $marker)
    {
        // A persisted daemon hook has already set the marker before this worker starts.
        // The journal lock and the daemon branch both matter: never clear a different job.
        $journal = self::jobPath($root, $hash);
        clearstatcache(true, $journal);
        if (@lstat($journal) !== false) return 'journal-present';
        $condition = 'equal=' . getCmd('d.get_custom=') . 'x-autotools-move-job,cat=' . $marker;
        $result = self::rpc('branch', array($hash, $condition,
            getCmd('d.set_custom') . '=x-autotools-move-job,', 'cat=SKIP'));
        if ($result === 'SKIP') return 'changed';
        self::rpc(getCmd('d.save_full_session'), array($hash));
        $current = self::rpc(getCmd('d.get_custom'), array($hash, 'x-autotools-move-job'));
        $sidecar = rtrim(rTorrentSettings::get()->session, '/') . '/' . $hash . '.torrent.rtorrent';
        $bytes = @file_get_contents($sidecar);
        if ($bytes === false) return 'unconfirmed';
        $torrent = Torrent::fromRawBytes($bytes);
        $custom = $torrent->meta('custom');
        return $current === '' && $torrent->errors() === false && is_array($custom)
            && (!isset($custom['x-autotools-move-job']) || $custom['x-autotools-move-job'] === '')
            ? 'cleared' : 'unconfirmed';
    }

    // The planning of a Move meets transient states: rTorrent publishes the
    // session copy seconds after an add, and retrackers re-adds a public
    // download (erase, then load) right when a small one finishes, so the
    // download is briefly absent. The hook's arguments are therefore kept as
    // a request beside the journal until the Move is planned or finally
    // refused, and the recovery pass plans it again every 60 s within
    // rtFileJobRetryWindow() (ACC-OWNB-02). Requests are written and read
    // only under the journal lock, which only background processes hold: the
    // finished hook of a Move starts this worker in the background and never
    // waits for the lock (see the one-owner invariant in hook_lib.php).
    const REQUEST_SUFFIX = '.move-request.json';

    static private function requestPath($root, $hash)
    {
        return $root . '/' . $hash . self::REQUEST_SUFFIX;
    }

    // A request of a well-formed hook, durable before planning can fail; a
    // repeated finish of the same job keeps its first time. Null for the
    // arguments of an old or broken hook, which are planned once as before.
    static private function recordRequest($root, $hash, $args)
    {
        $args = array_values($args);
        if (count($args) < 8 || !preg_match('/^[0-9A-F]{40}$/D', $hash)
            || !preg_match('/^[0-9a-f]{32}$/D', (string) $args[7]))
            return null;
        $args = array_map('strval', array_slice($args, 0, 8));
        $previous = self::readRequest($root, $hash);
        $request = $previous !== null && $previous['args'][7] === $args[7] ? $previous
            : array('version' => 1, 'hash' => $hash, 'args' => $args, 'first_at' => time(),
                'attempts' => 0, 'reason' => '');
        $request['args'] = $args;
        self::writeRequest($root, $request);
        return $request;
    }

    static private function writeRequest($root, $request)
    {
        $encoded = json_encode($request, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) throw new RuntimeException('request-encode-failed');
        // Never tempnam()'s fallback outside the journal: see erasedataStagingFile().
        $tmp = erasedataStagingFile($root, '.move-');
        if ($tmp === false) throw new RuntimeException('journal-temp-failed');
        if (@file_put_contents($tmp, $encoded) !== strlen($encoded)
            || !@rename($tmp, self::requestPath($root, $request['hash']))) {
            @unlink($tmp);
            throw new RuntimeException('request-write-failed');
        }
    }

    static private function readRequest($root, $hash)
    {
        $path = self::requestPath($root, $hash);
        clearstatcache(true, $path);
        if (@lstat($path) === false) return null;
        $request = is_file($path) && !is_link($path)
            ? json_decode((string) @file_get_contents($path, false, null, 0, 65536), true) : null;
        $valid = is_array($request)
            && array_keys($request) === array('version', 'hash', 'args', 'first_at', 'attempts', 'reason')
            && $request['version'] === 1 && $request['hash'] === $hash && is_array($request['args'])
            && array_values($request['args']) === $request['args'] && count($request['args']) === 8
            && is_int($request['first_at']) && is_int($request['attempts']) && is_string($request['reason']);
        if ($valid)
            foreach ($request['args'] as $arg)
                if (!is_string($arg)) $valid = false;
        if ($valid && $request['args'][0] === $hash) return $request;
        // A request is planned from nothing else, so a broken one is said
        // once and removed: it cannot be planned.
        self::log($hash, 'refused: invalid move request; the hook it held is not performed');
        self::retireRequest($root, $hash);
        return null;
    }

    static private function retireRequest($root, $hash)
    {
        $path = self::requestPath($root, $hash);
        if (!@unlink($path)) {
            clearstatcache(true, $path);
            if (@lstat($path) !== false)
                throw new RuntimeException('request-cleanup-failed: path=' . self::JOURNAL_DIR . '/'
                    . self::logHash($hash) . self::REQUEST_SUFFIX . '; remove it to let the Move be planned');
        }
    }

    // The retrackers native claim of a download: 'held' while retrackers
    // re-adds it, 'none' without a claim, 'other' for another answer, null
    // when the daemon does not answer (no native queue, or a transport
    // failure).
    static private function retrackersClaim($hash)
    {
        $request = new rXMLRPCRequest(new rXMLRPCCommand('system.retrackers.original_claim', array('', $hash)));
        $request->important = false;
        if (!$request->success() || $request->fault || !is_array($request->val)
            || count($request->val) !== 1 || !is_string($request->val[0]))
            return null;
        return in_array($request->val[0], array('held', 'none'), true) ? $request->val[0] : 'other';
    }

    // What a planning failure means: 'transient' keeps the request for the
    // recovery pass, 'quiet' retires it with the log line only, 'visible'
    // also names the refusal on the download. A download that answers like
    // a removed one is told apart from one retrackers re-adds by its claim.
    static private function failureKind($hash, &$reason)
    {
        if ($reason === 'session-copy-unreadable' || $reason === 'daemon-claim-abi-unconfirmed')
            return 'transient';
        if (in_array($reason, array('configuration-declined', 'job-marker-mismatch', 'hash-invalid',
            'worker-arguments-missing'), true))
            return 'quiet';
        if (!preg_match('/^rpc-[A-Za-z0-9_.]+-unconfirmed$/D', $reason))
            return 'visible';
        $absence = self::absence($hash);
        if ($absence === 'present')
            return 'transient';
        if ($absence === 'gone') {
            $reason = 'download-absent';
            return 'quiet';
        }
        $reason = $absence === 'reloading' ? 'download-reloading' : 'download-absent-unconfirmed';
        return 'transient';
    }

    // What an RPC that did not answer for a download says about it: 'present'
    // (or not provably absent), 'gone' (absent without a retrackers claim, and
    // absent again after the claim was read: removed), 'reloading' (retrackers
    // holds its claim while it re-adds it) or 'unconfirmed'.
    static private function absence($hash)
    {
        if (erasedataTorrentPresence($hash) !== ERASEDATA_TORRENT_ABSENT)
            return 'present';
        $claim = self::retrackersClaim($hash);
        // The claim may end between the two reads; only a second absence
        // after it proves the download is gone.
        if ($claim === 'none' && erasedataTorrentPresence($hash) === ERASEDATA_TORRENT_ABSENT)
            return 'gone';
        return $claim === 'held' ? 'reloading' : 'unconfirmed';
    }

    static private function planFailed($root, $hash, $request, $reason)
    {
        $kind = self::failureKind($hash, $reason);
        $detail = '';
        if ($kind === 'transient' && $request !== null) {
            if (time() - $request['first_at'] < rtFileJobRetryWindow()) {
                // Said once per reason; the passes in between are silent.
                $first = $request['attempts'] === 0 || $request['reason'] !== $reason;
                $request['attempts']++;
                $request['reason'] = $reason;
                self::writeRequest($root, $request);
                if ($first) {
                    self::log($hash, 'deferred: ' . $reason . '; recover.php plans the Move again every 60 s');
                    $hint = new rXMLRPCRequest(new rXMLRPCCommand(getCmd('d.message.set'), array($hash,
                        'autotools: move waiting: ' . $reason . '; it is retried automatically')));
                    $hint->important = false;
                    $hint->success();
                }
                return;
            }
            $detail = ' unresolved for ' . (int) ceil(rtFileJobRetryWindow() / 60) . ' min';
            $kind = 'visible';
        }
        if ($request !== null) self::retireRequest($root, $hash);
        self::log($hash, 'refused: ' . $reason . $detail);
        if ($kind !== 'quiet') self::showRefusal($hash, $reason, $detail);
    }

    // Admission, planning and the first reconcile of a Move without a job,
    // for the worker and for the recovery pass alike.
    static private function plan($root, $hash, $args, $request)
    {
        $admission = rAutoTools::claimAbiStatus();
        if ($admission === 'unconfirmed' && $request !== null) {
            self::planFailed($root, $hash, $request, 'daemon-claim-abi-unconfirmed');
            return;
        }
        if ($admission !== 'available') {
            if ($request !== null) self::retireRequest($root, $hash);
            $marker = isset($args[7]) ? (string) $args[7] : '';
            if ($admission === 'unsupported' && preg_match('/^[0-9a-f]{32}$/D', $marker)) {
                try {
                    $cleanup = self::clearUnjournaledOldHookMarker($root, $hash, $marker);
                    self::log($hash, ($cleanup === 'cleared' ? 'refused: ' : 'hold: ')
                        . 'daemon-claim-abi-unsupported; old-hook-marker-' . $cleanup);
                } catch (Throwable $e) {
                    self::log($hash, 'hold: daemon-claim-abi-unsupported; old-hook-marker-'
                        . $e->getMessage());
                }
            } else self::log($hash, 'refused: daemon-claim-abi-' . $admission);
            return;
        }
        try { $job = self::prepare($args); }
        catch (Throwable $e) {
            self::planFailed($root, $hash, $request, $e->getMessage());
            return;
        }
        self::writeJob($root, $job);
        if ($request !== null) self::retireRequest($root, $hash);
        if (self::reconcile($root, $job)) self::deliverNotice($root, $hash);
    }

    // One download under the journal lock: its job, else its request.
    static private function service($root, $hash, $args = null)
    {
        $old = self::readJob($root, $hash);
        if ($old !== null) {
            // The planned job owns the download; a request beside it is spent.
            if (@lstat(self::requestPath($root, $hash)) !== false) self::retireRequest($root, $hash);
            if (self::reconcile($root, $old)) self::deliverNotice($root, $hash);
            return;
        }
        if ($args === null) {
            $request = self::readRequest($root, $hash);
            if ($request === null) return;
        } else {
            try { $request = self::recordRequest($root, $hash, $args); }
            catch (RuntimeException $e) {
                self::refuseUnrecorded($hash, $e->getMessage());
                return;
            }
        }
        self::plan($root, $hash, $request !== null ? $request['args'] : $args, $request);
    }

    // A finished hook that nothing durable holds -- the session directory
    // cannot keep the journal, its lock or the request -- is never retried,
    // so it is refused in the log and on the download (R4: a fresh journal in
    // an unwritable session directory once logged 'hold: journal-unavailable'
    // and nothing else).
    static private function refuseUnrecorded($hash, $reason)
    {
        self::log($hash, 'refused: ' . $reason . '; the Move request could not be recorded');
        if (is_string($hash) && preg_match('/^[0-9A-F]{40}$/D', $hash)) self::showRefusal($hash, $reason);
    }

    static public function run($args)
    {
        $hash = isset($args[0]) ? (string) $args[0] : 'unknown';
        try {
            $root = self::journalRoot();
            $lock = self::lock($root);
        } catch (Throwable $e) {
            self::refuseUnrecorded($hash, $e->getMessage());
            return;
        }
        try { self::service($root, $hash, $args); }
        catch (Throwable $e) { self::log($hash, 'hold: ' . $e->getMessage()); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    static public function recoverAll()
    {
        try { $root = self::journalRoot(); }
        catch (Throwable $e) { self::log('scanner', 'hold: ' . $e->getMessage()); return; }
        $entries = @scandir($root);
        if ($entries === false) { self::log('scanner', 'hold: journal-scan-failed'); return; }
        $hashes = array();
        foreach ($entries as $entry)
            if (preg_match('/^([0-9A-F]{40})(\.move\.json|\.move-request\.json)$/D', $entry, $parts))
                $hashes[$parts[1]] = true;
        foreach (array_keys($hashes) as $hash) {
            try {
                $lock = self::lock($root);
                try { self::service($root, $hash); }
                finally { flock($lock, LOCK_UN); fclose($lock); }
            } catch (Throwable $e) { self::log($hash, 'recovery hold: ' . $e->getMessage()); }
        }
    }
}
