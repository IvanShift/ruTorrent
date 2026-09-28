<?php

require_once(dirname(__FILE__) . '/util_rt.php');
require_once(dirname(__FILE__) . '/autotools.php');
require_once(dirname(__FILE__) . '/../rutracker_check/runstate.php');

/** Whole-root Move backed by a durable daemon claim. */
class AutoToolsMoveTransaction
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
        $handle = @fopen($root . '/.lock', 'c');
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
        $tmp = @tempnam($root, '.move-');
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
        self::writeJob($root, $job);
        return true;
    }

    static private function abortBeforeMove($root, $job, $token)
    {
        $job['phase'] = 'aborting';
        self::writeJob($root, $job);
        $result = self::rpc('d.start_if_stop_close_claim', array($job['hash'], $token));
        if ($result !== '0' && $result !== '1') throw new RuntimeException('abort-result-unknown');
        self::acknowledgeClaim($job);
        self::retireJob($root, $job['hash']);
        self::log($job['hash'], 'refused: physical move did not publish; original run intent reconciled');
    }

    static private function renameHelper()
    {
        $binary = '/usr/local/bin/rutorrent-erasedata-rename-noreplace';
        if (!is_executable($binary)) throw new RuntimeException('rename-helper-unavailable');
        return $binary;
    }

    static private function renameNoReplace($src, $dst)
    {
        $binary = self::renameHelper();
        $output = array(); $status = 1;
        exec(escapeshellarg($binary) . ' ' . escapeshellarg($src) . ' '
            . escapeshellarg($dst), $output, $status);
        return $status === 0;
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
                throw new RuntimeException('quarantine-rename-failed');
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
                self::abortBeforeMove($root, $job, $token);
                self::log($hash, 'refused: cross-mount stage failed: ' . $e->getMessage());
                return false;
            }
            if (!self::renameNoReplace($job['stage'], $job['dst'])) {
                if (self::identity($job['src']) === $job['identity']) {
                    self::discardStage($job);
                    self::abortBeforeMove($root, $job, $token);
                    return false;
                }
                throw new RuntimeException('stage-publish-ambiguous');
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
        $state = self::claimState($job);
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
                if (self::rpc(getCmd('d.get_custom'), array($hash, 'x-autotools-move-job'))
                    !== $job['job_id'])
                    throw new RuntimeException('job-marker-missing-or-changed');
                if (self::rpc('d.get_base_path', $hash) !== $job['src'])
                    $reason = 'daemon source path changed';
                elseif (self::rpc('d.is_active', $hash) !== '1'
                    || self::rpc('d.is_open', $hash) !== '1')
                    $reason = 'daemon was no longer active/open';
                else throw new RuntimeException('claim-precondition-unconfirmed');
                self::retireJob($root, $hash);
                self::log($hash, 'refused: ' . $reason);
                return false;
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
            self::abortBeforeMove($root, $job, $token);
            return false;
        }
        if ($src === $expected && $dst === null) {
            try {
                if (!isset($job['receipt'])) self::captureReceipt($root, $job);
                else self::verifyReceipt($job, $job['src'], true);
            } catch (Throwable $e) {
                self::abortBeforeMove($root, $job, $token);
                self::log($hash, 'refused: source receipt failed: ' . $e->getMessage());
                return false;
            }
            if (!self::renameNoReplace($job['src'], $job['dst'])) {
                if (self::identity($job['src']) === $expected) {
                    self::abortBeforeMove($root, $job, $token);
                    return false;
                }
                throw new RuntimeException('rename-failed-ambiguous');
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
        self::renameHelper();
        $hash = (string) $args[0];
        if (!preg_match('/^[0-9A-F]{40}$/D', $hash)) throw new RuntimeException('hash-invalid');
        $basePath = rtrim((string) $args[1], '/');
        $baseName = (string) $args[2];
        $multi = (string) $args[3] === '1';
        $label = rawurldecode((string) $args[4]);
        $name = (string) $args[5];
        $jobId = (string) $args[7];
        if (!preg_match('/^[0-9a-f]{32}$/D', $jobId)
            || self::rpc(getCmd('d.get_custom'), array($hash, 'x-autotools-move-job')) !== $jobId)
            throw new RuntimeException('job-marker-mismatch');
        $at = rAutoTools::load();
        if (!$at->enable_move || $at->fileop_type !== 'Move'
            || @preg_match($at->automove_filter . 'u', $label) !== 1)
            throw new RuntimeException('configuration-declined');
        $session = rTorrentSettings::get()->session;
        $torrentPath = rtrim($session, '/') . '/' . $hash . '.torrent';
        if (!is_readable($torrentPath)) throw new RuntimeException('session-copy-unreadable');
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
        if ($finished === '' || !rtMkDir($finished)) throw new RuntimeException('destination-unavailable');
        $destDir = rtAddTailSlash($finished . '/' . $relative);
        if ($at->addLabel && $label !== '' && $label !== trim($relative, '/'))
            $destDir .= FileUtil::addslash($label);
        if ($at->addName && $name !== '') $destDir .= FileUtil::addslash($name);
        $destDir = rtrim($destDir, '/');
        if (!rAutoTools::destinationWithinRoot($finished, $destDir))
            throw new RuntimeException('destination-outside-finished-root');
        if (!is_dir($destDir) && !@mkdir($destDir, 0777, true) && !is_dir($destDir))
            throw new RuntimeException('destination-parent-unavailable');
        if (!rAutoTools::destinationWithinRoot($finished, $destDir))
            throw new RuntimeException('destination-outside-finished-root');
        $src = $basePath;
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

    static public function run($args)
    {
        $hash = isset($args[0]) ? (string) $args[0] : 'unknown';
        try {
            $root = self::journalRoot();
            $lock = self::lock($root);
            try {
                $old = self::readJob($root, $hash);
                if ($old !== null) {
                    if (self::reconcile($root, $old)) self::deliverNotice($root, $hash);
                    return;
                }
                $admission = rAutoTools::claimAbiStatus();
                if ($admission !== 'available') {
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
                    self::log($hash, 'refused: ' . $e->getMessage());
                    return;
                }
                self::writeJob($root, $job);
                if (self::reconcile($root, $job)) self::deliverNotice($root, $hash);
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        } catch (Throwable $e) {
            self::log($hash, 'hold: ' . $e->getMessage());
        }
    }

    static public function recoverAll()
    {
        try { $root = self::journalRoot(); }
        catch (Throwable $e) { self::log('scanner', 'hold: ' . $e->getMessage()); return; }
        $entries = @scandir($root);
        if ($entries === false) { self::log('scanner', 'hold: journal-scan-failed'); return; }
        foreach ($entries as $entry) {
            if (!preg_match('/^([0-9A-F]{40})\.move\.json$/D', $entry, $parts)) continue;
            $hash = $parts[1];
            try {
                $lock = self::lock($root);
                try {
                    $job = self::readJob($root, $hash);
                    if ($job !== null && self::reconcile($root, $job))
                        self::deliverNotice($root, $hash);
                } finally { flock($lock, LOCK_UN); fclose($lock); }
            } catch (Throwable $e) { self::log($hash, 'recovery hold: ' . $e->getMessage()); }
        }
    }
}
