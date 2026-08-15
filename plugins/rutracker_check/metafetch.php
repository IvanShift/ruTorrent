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

    // How many times a restore is issued and then verified before giving up,
    // the same budget activateReplacement() spends (check.php).
    const RESTORE_ATTEMPTS = 2;

    // chk-meta-run's vocabulary. Three values, not a bit: "stopped but open"
    // is what ruTorrent's pause button produces (d.stop alone), and
    // createTorrent()'s restoreExistingTorrent() answers it with d.open rather
    // than d.start -- a single "was it running" flag could not tell the two
    // apart and brought a paused torrent back seeding.
    const RUN_STARTED = 'started';
    const RUN_OPEN = 'open';
    const RUN_STOPPED = 'stopped';

    // The label the service download carries. The leading dot is the
    // convention that marks a download as a plugin's own bookkeeping rather
    // than the user's; the history plugin skips such entries, which is what
    // keeps a replacement from being logged as two deletions -- the stub
    // takes the real torrent's name once metadata arrives, and only the
    // label still tells the two apart. Unlike the inline chk-meta-* customs,
    // a label is a field rTorrent's event handlers actually pass on.
    const SERVICE_LABEL = '.chk-meta';

    static private function serviceDirectory()
    {
        global $topDirectory;
        return FileUtil::addslash($topDirectory) . '.chk-meta';
    }

    // Cross-links the old, still-seeding download to the service download:
    // best effort, run after metadata has already started downloading, so a
    // failure here does not undo the successful load.
    //
    // chk-meta-run rides along with the other two markers as harvest()'s
    // fallback: it is the run state at the moment the replacement was decided,
    // and it is the only answer left when the old torrent can no longer be
    // read at commit time. A reading taken then wins over it -- see harvest().
    static private function markOldTorrent($oldHash, $newHash, $deadline, $run)
    {
        $req = new rXMLRPCRequest(array(
            new rXMLRPCCommand(getCmd("d.set_custom"), array($oldHash, "chk-meta-new", $newHash)),
            new rXMLRPCCommand(getCmd("d.set_custom"), array($oldHash, "chk-meta-until", (string) $deadline)),
            new rXMLRPCCommand(getCmd("d.set_custom"), array($oldHash, "chk-meta-run", self::encodeRunState($run))),
        ));
        $req->important = false;
        $req->success();
    }

    // A torrent's run state as the pair rTorrent itself keeps it: started
    // (d.state) and open (d.is_open). null when the read failed -- for the
    // marker that is the same outcome as "stopped" (neither may start the
    // replacement), but it is logged distinctly so the two are never confused
    // in a diagnosis.
    //
    // @return array('started'=>bool,'open'=>bool)|null
    static private function readRunState($hash)
    {
        $req = new rXMLRPCRequest(array(
            new rXMLRPCCommand(getCmd("d.get_state"), $hash),
            new rXMLRPCCommand(getCmd("d.is_open"), $hash),
        ));
        $req->important = false;
        if (!$req->success() || !isset($req->val[0], $req->val[1])) return null;
        return array(
            'started' => intval($req->val[0]) !== 0,
            'open' => intval($req->val[1]) !== 0,
        );
    }

    static private function encodeRunState($run)
    {
        if (!is_array($run)) return self::RUN_STOPPED;
        if ($run['started']) return self::RUN_STARTED;
        return $run['open'] ? self::RUN_OPEN : self::RUN_STOPPED;
    }

    // The inverse. "1"/"0" are what the first version of this marker wrote, so
    // a fetch that began before an upgrade is still honoured rather than
    // silently downgraded to "stopped".
    static private function decodeRunState($value)
    {
        $value = trim((string) $value);
        if ($value === self::RUN_STARTED || $value === '1') return array('started' => true, 'open' => true);
        if ($value === self::RUN_OPEN) return array('started' => false, 'open' => true);
        return array('started' => false, 'open' => false);
    }

    static private function describeRunState($run)
    {
        return $run === null ? 'unreadable' : self::encodeRunState($run);
    }

    // The measured pair, for a log line that reports what was read back rather
    // than what was asked for.
    static private function measuredRunState($run)
    {
        if ($run === null) return 'state=? open=?';
        return 'state=' . ($run['started'] ? 1 : 0) . ' open=' . ($run['open'] ? 1 : 0);
    }

    // Every exit below logs what happened. The "begin" line alone says only
    // that a fetch was attempted: a misconfigured service directory used to
    // produce that one identical line an hour, forever, and nothing else.
    static public function begin($oldHash, $newHash, $topicId, $announceUrl, $now)
    {
        global $rutrackerMetaDeadline;
        $deadline = $now + (isset($rutrackerMetaDeadline) ? (int) $rutrackerMetaDeadline : 86400);
        ruTrackerChecker::logDebug('metafetch: begin ' . $oldHash . ' -> ' . $newHash
            . ' topic=' . (int) $topicId);

        // load silently swallows a hash it already knows, so an already
        // present successor must be detected before loading. How it got
        // there is unknowable -- the user, another automation, or an earlier
        // incomplete run of this plugin -- so the token records only the
        // hash, and rutracker.php's layer 0 reads it back to keep this
        // terminal outcome from re-running the whole chain every cycle.
        $exists = ruTrackerChecker::torrentExists($newHash);
        if ($exists === null) {
            ruTrackerChecker::logDebug('metafetch: ' . $oldHash
                . ' aborted: could not tell whether ' . $newHash . ' is already in the client');
            return ruTrackerChecker::STE_ERROR;
        }
        if ($exists === true) {
            ruTrackerChecker::setMessage($oldHash,
                ruTrackerChecker::CHKMSG_SUPERSEDED . '|' . $newHash);
            ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' aborted: ' . $newHash
                . ' is already in the client, nothing left to fetch');
            return ruTrackerChecker::STE_NOT_NEED;
        }

        // Read before anything is loaded: this is the only point in the whole
        // layer-4 flow at which the old torrent is still the torrent the
        // candidate was picked from (update.php's "seeding" view).
        $run = self::readRunState($oldHash);
        ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' old run state='
            . self::describeRunState($run));

        $magnet = 'magnet:?xt=urn:btih:' . $newHash . '&tr=' . rawurlencode($announceUrl);
        $addition = array(
            getCmd("d.set_custom") . "=chk-meta-old," . $oldHash,
            getCmd("d.set_custom") . "=chk-meta-topic," . (int) $topicId,
            getCmd("d.set_custom") . "=chk-meta-until," . $deadline,
        );
        // Stopped, plain load: the meta stub's start=0 survives into the
        // real download rTorrent creates once metadata arrives (§2.7).
        $sent = rTorrent::sendMagnet($magnet, false, false, self::serviceDirectory(), self::SERVICE_LABEL, $addition);
        if ($sent === false) {
            // The likeliest cause by far, and the one thing worth naming: a
            // service directory rTorrent cannot write into.
            ruTrackerChecker::logDebug('metafetch: ' . $oldHash
                . ' aborted: rTorrent refused the service load of ' . $newHash
                . ' into ' . self::serviceDirectory());
            return ruTrackerChecker::STE_ERROR;
        }

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
                ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' aborted: the item at '
                    . $newHash . ' belongs to someone else, leaving it alone');
                return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            }

            $start = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.start"), $newHash));
            $start->important = false;
            if (!$start->success()) {
                $erase = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.erase"), $newHash));
                $erase->important = false;
                $erase->success();
                ruTrackerChecker::logDebug('metafetch: ' . $oldHash
                    . ' aborted: could not start the metadata stub ' . $newHash . ', erased it');
                return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            }

            self::markOldTorrent($oldHash, $newHash, $deadline, $run);
            ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' loaded the metadata stub '
                . $newHash . ', waiting until ' . $deadline);
            return ruTrackerChecker::STE_META_PENDING;
        }
        ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' aborted: the metadata stub '
            . $newHash . ' never appeared after ' . self::WAIT_ATTEMPTS . ' polls');
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

        $erase = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.erase"), $newHash));
        $erase->important = false;
        $erase->success();
        $stubGone = (ruTrackerChecker::torrentExists($newHash) === false);
        ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' harvest ' . $newHash
            . ' bytes=' . strlen($bytes) . ' service item erased=' . ($stubGone ? 'yes' : 'no'));
        if (!$stubGone)
            return ruTrackerChecker::STE_META_PENDING;

        // The old torrent's run state, read here -- the last moment at which
        // it can be read at all, since createTorrent() erases it at its own
        // commit point a few statements later. The live read outranks
        // chk-meta-run: the marker was written when the fetch was decided on,
        // up to $rutrackerMetaDeadline (a day, by default) ago, and it can
        // only disagree with a reading taken now in the very cases where the
        // reading is the truthful one -- the user stopping the old torrent
        // while the metadata was being fetched. The marker is what is left
        // when there is nothing to read.
        $run = self::readRunState($oldHash);
        $source = 'a live read';
        if ($run === null) {
            $run = self::decodeRunState(self::recordedRunState($oldHash));
            $source = 'the chk-meta-run marker, the live read having failed';
        }
        ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' old run state before the replacement: started='
            . ($run['started'] ? 1 : 0) . ' open=' . ($run['open'] ? 1 : 0) . ' from ' . $source);

        $result = ruTrackerChecker::createTorrent($bytes, $oldHash);
        ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' replacement by ' . $newHash
            . ' returned ' . self::describeResult($result));
        if ($result === null) self::restoreReplacement($newHash, $run);
        return $result;
    }

    // The raw chk-meta-run value begin() recorded, '' when it cannot be read;
    // decodeRunState() turns anything unrecognised into "stopped", so a failed
    // read leaves the replacement alone.
    static private function recordedRunState($oldHash)
    {
        $req = new rXMLRPCRequest(new rXMLRPCCommand(
            getCmd("d.get_custom"), array($oldHash, "chk-meta-run")));
        $req->important = false;
        if (!$req->success() || !isset($req->val[0])) return '';
        return (string) $req->val[0];
    }

    // Give the replacement the run state its predecessor had, and then check
    // that it actually took: an XMLRPC ack proves only that rTorrent accepted
    // the command, and the failure this whole layer was chasing is a
    // replacement sitting at state 0, closed, with correct metainfo. Every
    // line below reports what was measured afterwards, never what was asked
    // for.
    //
    // The shape mirrors createTorrent()'s activateReplacement() (check.php):
    // issue, re-read, retry once, and say so when the reading disagrees.
    // Its restoreExistingTorrent() is not reused: it is private to
    // ruTrackerChecker, whose createTorrent() logic the owner has frozen, and
    // it issues one command (d.start OR d.open) where this needs d.open before
    // d.start -- and it reports an ack, which is the very thing this must not
    // trust.
    //
    // Deliberately does nothing when the predecessor was neither open nor
    // started: never resurrecting a torrent the user stopped on purpose is
    // createTorrent()'s existing, intended behaviour.
    static private function restoreReplacement($newHash, $run)
    {
        if (!$run['started'] && !$run['open']) {
            ruTrackerChecker::logDebug('metafetch: leaving the replacement ' . $newHash
                . ' stopped and closed: the old torrent was neither open nor started');
            return;
        }

        $observed = self::readRunState($newHash);
        if ($observed !== null && self::satisfies($observed, $run['started'])) {
            ruTrackerChecker::logDebug('metafetch: the replacement ' . $newHash . ' is already '
                . ($run['started'] ? 'running' : 'open') . ' (' . self::measuredRunState($observed) . ')');
            return;
        }

        // d.open before d.start, exactly the order ruTorrent's own UI sends
        // (plugins/httprpc/action.php, case "start"): a bare d.start on a
        // closed download can leave it closed.
        $issued = $run['started'] ? 'd.open+d.start' : 'd.open';
        for ($attempt = 0; $attempt < self::RESTORE_ATTEMPTS; $attempt++) {
            $commands = array(new rXMLRPCCommand(getCmd("d.open"), $newHash));
            if ($run['started']) $commands[] = new rXMLRPCCommand(getCmd("d.start"), $newHash);
            $restore = new rXMLRPCRequest($commands);
            $restore->important = false;
            $accepted = $restore->success() ? 'accepted' : 'refused';

            $observed = self::readRunState($newHash);
            if ($observed !== null && self::satisfies($observed, $run['started'])) {
                ruTrackerChecker::logDebug('metafetch: the replacement ' . $newHash . ' inherited the old torrent\'s '
                    . self::encodeRunState($run) . ' state (' . $issued . ' ' . $accepted . ', now '
                    . self::measuredRunState($observed) . ')');
                return;
            }
        }
        ruTrackerChecker::logDebug('metafetch: the replacement ' . $newHash . ' should be '
            . self::encodeRunState($run) . ' but ' . $issued . ' was ' . $accepted
            . ' and it is still ' . self::measuredRunState($observed));
    }

    // A started torrent may stay closed until the scheduler grants it a slot,
    // the same allowance activateReplacement() makes.
    static private function satisfies($observed, $wantStarted)
    {
        return $wantStarted ? $observed['started'] : $observed['open'];
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
