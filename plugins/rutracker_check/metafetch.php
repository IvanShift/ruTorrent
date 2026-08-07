<?php

// Layer 4 of the post-API design: BEP-9 metadata fetch via a one-off service
// download. All markers ride inside the load command itself -- rTorrent
// re-applies load-command additions to the real download it creates when
// metadata arrives; customs set after load are lost in that transition
// (design doc section 4.4, point 2).
class RuTrackerMetaFetch
{
    const WAIT_ATTEMPTS = 40;
    const WAIT_DELAY_US = 50000;

    static private function serviceDirectory()
    {
        global $topDirectory;
        return FileUtil::addslash($topDirectory) . '.chk-meta';
    }

    // Cross-links the old, still-seeding download to the service download:
    // best effort, run after metadata has already started downloading, so a
    // failure here does not undo the successful load.
    static private function markOldTorrent($oldHash, $newHash, $deadline)
    {
        $req = new rXMLRPCRequest(array(
            new rXMLRPCCommand(getCmd("d.set_custom"), array($oldHash, "chk-meta-new", $newHash)),
            new rXMLRPCCommand(getCmd("d.set_custom"), array($oldHash, "chk-meta-until", (string) $deadline)),
        ));
        $req->important = false;
        $req->success();
    }

    static public function begin($oldHash, $newHash, $topicId, $announceUrl, $now)
    {
        global $rutrackerMetaDeadline;
        $deadline = $now + (isset($rutrackerMetaDeadline) ? (int) $rutrackerMetaDeadline : 86400);

        // load silently swallows a hash it already knows, so a user who
        // replaced the torrent by hand must be detected before loading.
        $exists = ruTrackerChecker::torrentExists($newHash);
        if ($exists === null) return ruTrackerChecker::STE_ERROR;
        if ($exists === true) {
            ruTrackerChecker::setMessage($oldHash,
                'новый торрент топика уже загружен вручную: ' . $newHash);
            return ruTrackerChecker::STE_NOT_NEED;
        }

        $magnet = 'magnet:?xt=urn:btih:' . $newHash . '&tr=' . rawurlencode($announceUrl);
        $addition = array(
            getCmd("d.set_custom") . "=chk-meta-old," . $oldHash,
            getCmd("d.set_custom") . "=chk-meta-topic," . (int) $topicId,
            getCmd("d.set_custom") . "=chk-meta-until," . $deadline,
        );
        // Stopped, plain load: the meta stub's start=0 survives into the
        // real download rTorrent creates once metadata arrives (§2.7).
        $sent = rTorrent::sendMagnet($magnet, false, false, self::serviceDirectory(), '', $addition);
        if ($sent === false) return ruTrackerChecker::STE_ERROR;

        // load is deferred: wait for the stub, and confirm the hash that
        // appears carries OUR chk-meta-old marker before touching it -- a
        // foreign load racing the same hash is not ours to start or erase.
        for ($attempt = 0; $attempt < self::WAIT_ATTEMPTS; $attempt++) {
            if ($attempt) usleep(self::WAIT_DELAY_US);
            $marker = new rXMLRPCRequest(new rXMLRPCCommand(
                getCmd("d.get_custom"), array($newHash, "chk-meta-old")));
            $marker->important = false;
            if (!$marker->run() || $marker->fault) continue;

            if (!isset($marker->val[0]) || (string) $marker->val[0] !== (string) $oldHash) {
                return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            }

            $start = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.start"), $newHash));
            $start->important = false;
            if (!$start->success()) {
                $erase = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.erase"), $newHash));
                $erase->important = false;
                $erase->success();
                return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            }

            self::markOldTorrent($oldHash, $newHash, $deadline);
            return ruTrackerChecker::STE_META_PENDING;
        }
        return ruTrackerChecker::STE_CANT_REACH_TRACKER;
    }

    // Advances a pending fetch by one cycle. Addressed by hash, straight off
    // the markers begin() left on the old download -- no re-classification,
    // no re-detection (design doc 4.4 point 4).
    //
    // @return null on a completed replacement (createTorrent()'s own success
    //         contract), STE_META_PENDING while still waiting, or
    //         STE_CANT_REACH_TRACKER/STE_ERROR on a retryable/hard failure.
    static public function pump($oldHash, $now)
    {
        $read = new rXMLRPCRequest(array(
            new rXMLRPCCommand(getCmd("d.get_custom"), array($oldHash, "chk-meta-new")),
            new rXMLRPCCommand(getCmd("d.get_custom"), array($oldHash, "chk-meta-until")),
        ));
        $read->important = false;
        if (!$read->success() || !isset($read->val[0], $read->val[1]))
            return ruTrackerChecker::STE_META_PENDING;
        $newHash = strtoupper(trim((string) $read->val[0]));
        $deadline = intval($read->val[1]);
        if (!preg_match('/^[0-9A-F]{40}$/', $newHash))
            return self::clearMarks($oldHash, ruTrackerChecker::STE_ERROR);

        // The stub is looked up by hash, never rediscovered by scanning --
        // gone means either it was reaped already or the user intervened;
        // either way there is nothing left to wait for.
        $exists = ruTrackerChecker::torrentExists($newHash);
        if ($exists === null) return ruTrackerChecker::STE_META_PENDING;
        if ($exists === false)
            return self::clearMarks($oldHash, ruTrackerChecker::STE_CANT_REACH_TRACKER);

        $meta = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.is_meta"), $newHash));
        $meta->important = false;
        if (!$meta->success() || !isset($meta->val[0]))
            return ruTrackerChecker::STE_META_PENDING;

        if (intval($meta->val[0]) !== 0) {
            // Still a BEP-9 stub. An early tracker rejection means no amount
            // of further waiting will help; otherwise enforce the deadline.
            // The transport flattens the multicall to one value per row (this
            // request asks for failed_counter only, the one column pump()
            // actually uses), so a plain scan for the worst counter is enough.
            $trackers = new rXMLRPCRequest(new rXMLRPCCommand("t.multicall", array(
                $newHash, "", getCmd("t.failed_counter") . "="
            )));
            $trackers->important = false;
            $failed = 0;
            if ($trackers->success())
                foreach ($trackers->val as $value)
                    $failed = max($failed, intval($value));

            if ($failed > 0)
                return self::dropStub($oldHash, $newHash, 'трекер не признал новый хеш');
            if ($now > $deadline)
                return self::dropStub($oldHash, $newHash, 'метаданные не пришли до дедлайна');
            return ruTrackerChecker::STE_META_PENDING;
        }

        // Metadata arrived. The topic id rides only on the stub (begin()'s
        // load-command addition); the old download's mirrored markers never
        // carried it.
        $topic = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.get_custom"), array($newHash, "chk-meta-topic")));
        $topic->important = false;
        if (!$topic->success() || !isset($topic->val[0]))
            return ruTrackerChecker::STE_META_PENDING;

        return self::harvest($oldHash, $newHash, intval($topic->val[0]));
    }

    // Byte collection, strictly in this order (design doc 4.4 point 5): read
    // the stub's session file before it can be erased, validate before
    // trusting it to createTorrent()'s legacy "unparseable == deleted topic"
    // contract, patch announce/comment, THEN erase the stub and confirm it
    // is gone -- createTorrent() refuses an existing new hash unless it
    // carries its own chk-replacement marker, and this one never does.
    static private function harvest($oldHash, $newHash, $topicId)
    {
        $torrent = rTorrent::getSource($newHash);
        if (!is_object($torrent) || $torrent->errors()
            || (string) $torrent->hash_info() !== $newHash)
            return self::dropStub($oldHash, $newHash, 'метаданные не прошли проверку');

        if ((string) $torrent->announce() === '') {
            $old = rTorrent::getSource($oldHash);
            if (is_object($old) && !$old->errors())
                $torrent->announce((string) $old->announce());
        }
        $torrent->comment('https://rutracker.org/forum/viewtopic.php?t=' . (int) $topicId);
        $bytes = (string) $torrent;

        $erase = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.erase"), $newHash));
        $erase->important = false;
        $erase->success();
        if (ruTrackerChecker::torrentExists($newHash) !== false)
            return ruTrackerChecker::STE_META_PENDING;

        return ruTrackerChecker::createTorrent($bytes, $oldHash);
    }

    // Best-effort: the stub is abandoned either way, so a failed erase here
    // just leaves an unused item behind rather than blocking the candidate's
    // return to the queue.
    static private function dropStub($oldHash, $newHash, $reason)
    {
        $erase = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.erase"), $newHash));
        $erase->important = false;
        $erase->success();
        ruTrackerChecker::setMessage($oldHash, $reason);
        return self::clearMarks($oldHash, ruTrackerChecker::STE_CANT_REACH_TRACKER);
    }

    static private function clearMarks($oldHash, $state)
    {
        $clear = new rXMLRPCRequest(array(
            new rXMLRPCCommand(getCmd("d.set_custom"), array($oldHash, "chk-meta-new", "")),
            new rXMLRPCCommand(getCmd("d.set_custom"), array($oldHash, "chk-meta-until", "")),
        ));
        $clear->important = false;
        $clear->success();
        return $state;
    }
}
