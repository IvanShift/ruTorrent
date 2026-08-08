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
    //
    // chk-meta-run rides along with the other two markers because harvest()
    // needs it for the same reason it needs chk-meta-new: it is a fact about
    // the moment the replacement was decided, and by the time harvest() runs
    // -- a later cycle, possibly many hours on -- nothing else records it.
    static private function markOldTorrent($oldHash, $newHash, $deadline, $wasRunning)
    {
        $req = new rXMLRPCRequest(array(
            new rXMLRPCCommand(getCmd("d.set_custom"), array($oldHash, "chk-meta-new", $newHash)),
            new rXMLRPCCommand(getCmd("d.set_custom"), array($oldHash, "chk-meta-until", (string) $deadline)),
            new rXMLRPCCommand(getCmd("d.set_custom"), array($oldHash, "chk-meta-run", $wasRunning === true ? "1" : "0")),
        ));
        $req->important = false;
        $req->success();
    }

    // The old torrent's run state right now, i.e. at the moment this fetch is
    // decided on. null when the read itself failed; for the marker that is
    // the same outcome as "stopped" (neither may start the replacement), but
    // it is logged distinctly so the two are never confused in a diagnosis.
    static private function readRunState($hash)
    {
        $req = new rXMLRPCRequest(array(
            new rXMLRPCCommand(getCmd("d.get_state"), $hash),
            new rXMLRPCCommand(getCmd("d.is_open"), $hash),
        ));
        $req->important = false;
        if (!$req->success() || !isset($req->val[0], $req->val[1])) return null;
        return (intval($req->val[0]) !== 0 || intval($req->val[1]) !== 0);
    }

    static private function describeRunState($wasRunning)
    {
        if ($wasRunning === null) return 'unreadable';
        return $wasRunning ? 'running' : 'stopped';
    }

    static public function begin($oldHash, $newHash, $topicId, $announceUrl, $now)
    {
        global $rutrackerMetaDeadline;
        $deadline = $now + (isset($rutrackerMetaDeadline) ? (int) $rutrackerMetaDeadline : 86400);

        // load silently swallows a hash it already knows, so an already
        // present successor must be detected before loading. How it got
        // there is unknowable -- the user, another automation, or an earlier
        // incomplete run of this plugin -- so the token records only the
        // hash, and rutracker.php's layer 0 reads it back to keep this
        // terminal outcome from re-running the whole chain every cycle.
        $exists = ruTrackerChecker::torrentExists($newHash);
        if ($exists === null) return ruTrackerChecker::STE_ERROR;
        if ($exists === true) {
            ruTrackerChecker::setMessage($oldHash,
                ruTrackerChecker::CHKMSG_SUPERSEDED . '|' . $newHash);
            return ruTrackerChecker::STE_NOT_NEED;
        }

        // Read before anything is loaded: this is the only point in the whole
        // layer-4 flow at which the old torrent is still the torrent the
        // candidate was picked from (update.php's "seeding" view).
        $wasRunning = self::readRunState($oldHash);
        ruTrackerChecker::logDebug('metafetch: begin ' . $oldHash . ' -> ' . $newHash
            . ' topic=' . (int) $topicId . ' old run state=' . self::describeRunState($wasRunning));

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

            self::markOldTorrent($oldHash, $newHash, $deadline, $wasRunning);
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
                return self::dropStub($oldHash, $newHash, 'the tracker rejected the new hash');
            if ($now > $deadline)
                return self::dropStub($oldHash, $newHash, 'metadata did not arrive before the deadline');
            ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' still a metadata stub at ' . $newHash
                . ', waiting (deadline ' . $deadline . ', ' . ($deadline - $now) . 's left)');
            return ruTrackerChecker::STE_META_PENDING;
        }

        ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' metadata arrived for ' . $newHash);

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
        $valid = (is_object($torrent) && !$torrent->errors()
            && (string) $torrent->hash_info() === $newHash);
        ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' harvest ' . $newHash
            . ' hash matched=' . ($valid ? 'yes' : 'no'));
        if (!$valid)
            return self::dropStub($oldHash, $newHash, 'the fetched metadata failed validation');

        if ((string) $torrent->announce() === '') {
            $old = rTorrent::getSource($oldHash);
            if (is_object($old) && !$old->errors())
                $torrent->announce((string) $old->announce());
        }
        $torrent->comment('https://rutracker.org/forum/viewtopic.php?t=' . (int) $topicId);
        $bytes = (string) $torrent;

        // Read while there is still an old torrent to read it off: the
        // replacement below erases it, and every chk-* custom it carried goes
        // with it.
        $wasRunning = self::recordedRunState($oldHash);

        $erase = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.erase"), $newHash));
        $erase->important = false;
        $erase->success();
        $stubGone = (ruTrackerChecker::torrentExists($newHash) === false);
        ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' harvest ' . $newHash
            . ' bytes=' . strlen($bytes) . ' service item erased=' . ($stubGone ? 'yes' : 'no')
            . ' recorded old run state=' . ($wasRunning ? 'running' : 'stopped'));
        if (!$stubGone)
            return ruTrackerChecker::STE_META_PENDING;

        $result = ruTrackerChecker::createTorrent($bytes, $oldHash);
        ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' replacement by ' . $newHash
            . ' returned ' . self::describeResult($result));
        if ($result === null && $wasRunning) self::startReplacement($newHash);
        return $result;
    }

    // The run state begin() recorded, as a plain "was it running" flag.
    // Anything other than a readable "1" -- an unset field, a failed read, a
    // marker written by a begin() whose own state read failed -- counts as
    // "not running", i.e. leaves the replacement alone.
    static private function recordedRunState($oldHash)
    {
        $req = new rXMLRPCRequest(new rXMLRPCCommand(
            getCmd("d.get_custom"), array($oldHash, "chk-meta-run")));
        $req->important = false;
        if (!$req->success() || !isset($req->val[0])) return false;
        return trim((string) $req->val[0]) === '1';
    }

    // createTorrent() decides the replacement's run state from a
    // d.get_state/d.is_open read it takes at its own commit point. In this
    // layer-4 flow that point is one or more cycles -- often hours -- after
    // begin() picked the candidate out of the seeding view, so anything that
    // stopped the old torrent in the meantime turns into "leave the
    // replacement stopped". The chk-meta-run marker is the state at decision
    // time instead of at commit time, and this restores it.
    //
    // What this does NOT claim: the five replacements observed sitting stopped
    // on the live system were never explained -- the debug log was off at the
    // time, so nothing recorded what createTorrent() actually read. This
    // removes a known dependency on a momentary state read; it is defence in
    // depth, not a fix for a diagnosed root cause.
    //
    // Deliberately silent when the marker says the old torrent was stopped:
    // never resurrecting something the user stopped on purpose is
    // createTorrent()'s existing, intended behaviour.
    static private function startReplacement($newHash)
    {
        $state = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.get_state"), $newHash));
        $state->important = false;
        if (!$state->success() || !isset($state->val[0])) {
            ruTrackerChecker::logDebug('metafetch: could not read the run state of the replacement '
                . $newHash . ', leaving it as it is');
            return;
        }
        if (intval($state->val[0]) !== 0) {
            ruTrackerChecker::logDebug('metafetch: the replacement ' . $newHash . ' is already running');
            return;
        }

        $start = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.start"), $newHash));
        $start->important = false;
        $started = $start->success();
        ruTrackerChecker::logDebug('metafetch: started the replacement ' . $newHash
            . ' because the old torrent was running when the fetch began (d.start '
            . ($started ? 'accepted' : 'refused') . ')');
    }

    // createTorrent()'s answer as text: null is its success contract, and
    // every other value is one of ruTrackerChecker's STE_* codes.
    static private function describeResult($result)
    {
        if ($result === null) return 'success';
        $names = array(
            ruTrackerChecker::STE_INPROGRESS => 'STE_INPROGRESS',
            ruTrackerChecker::STE_UPDATED => 'STE_UPDATED',
            ruTrackerChecker::STE_UPTODATE => 'STE_UPTODATE',
            ruTrackerChecker::STE_DELETED => 'STE_DELETED',
            ruTrackerChecker::STE_CANT_REACH_TRACKER => 'STE_CANT_REACH_TRACKER',
            ruTrackerChecker::STE_ERROR => 'STE_ERROR',
            ruTrackerChecker::STE_NOT_NEED => 'STE_NOT_NEED',
            ruTrackerChecker::STE_IGNORED => 'STE_IGNORED',
            ruTrackerChecker::STE_META_PENDING => 'STE_META_PENDING',
            ruTrackerChecker::STE_ABSORBED => 'STE_ABSORBED',
        );
        return isset($names[(int) $result]) ? $names[(int) $result] : ('an unknown code ' . (int) $result);
    }

    // Best-effort: the stub is abandoned either way, so a failed erase here
    // just leaves an unused item behind rather than blocking the candidate's
    // return to the queue. The abort reason is diagnostics -- the torrent
    // simply returns to the queue, which the status label already says -- so
    // it goes to the debug log, and chk-msg is cleared rather than left
    // carrying a token from an earlier cycle.
    static private function dropStub($oldHash, $newHash, $reason)
    {
        $erase = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.erase"), $newHash));
        $erase->important = false;
        $erase->success();
        ruTrackerChecker::setMessage($oldHash, '');
        ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' dropped stub ' . $newHash . ': ' . $reason);
        return self::clearMarks($oldHash, ruTrackerChecker::STE_CANT_REACH_TRACKER);
    }

    // chk-meta-run is deliberately left alone: it is only ever consulted
    // between markOldTorrent() and the createTorrent() call of the same fetch,
    // it is meaningless without chk-meta-new (which IS cleared here), and
    // markOldTorrent() rewrites it unconditionally on the next begin() -- so a
    // leftover value can never be read as this fetch's answer.
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
