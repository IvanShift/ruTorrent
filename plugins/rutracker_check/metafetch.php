<?php

require_once(dirname(__FILE__) . '/runstate.php');

// Layer 4 of the post-API design: BEP-9 metadata fetch via a one-off service
// download. All markers ride inside the load command itself -- rTorrent
// re-applies load-command additions to the real download it creates when
// metadata arrives; customs set after load are lost in that transition
// (measured on rTorrent 0.9.8/0.16.20).
class RuTrackerMetaFetch
{
    const WAIT_ATTEMPTS = 40;
    const WAIT_DELAY_US = 50000;

    const ACTIVATION_UNKNOWN = 'unknown';
    const ACTIVATION_SETTLED = 'settled';
    const ACTIVATION_UNCONFIRMED = 'unconfirmed';

    const REPLACEMENT_FOREIGN = 'foreign';
    const REPLACEMENT_UNPROVED = 'unproved';
    const REPLACEMENT_OWNED = 'owned';

    // The label the service download carries. The leading dot is the
    // convention that marks a download as a plugin's own bookkeeping rather
    // than the user's; the history plugin skips such entries, which is what
    // keeps a replacement from being logged as two deletions -- the stub
    // takes the real torrent's name once metadata arrives, and only the
    // label still tells the two apart. Unlike the inline chk-meta-* customs,
    // a label is a field rTorrent's event handlers actually pass on.
    const SERVICE_LABEL = '.chk-meta';

    // A replacement nonce identifies the writer, not the transaction. Only a
    // strict record naming the expected predecessor completes ownership.
    // Every collision/adoption path uses this same pair rule.
    static private function replacementOwnership($marker, $rawRecord, $oldHash)
    {
        if (!ruTrackerChecker::isPluginReplacementMarker((string) $marker)) {
            return array('status' => self::REPLACEMENT_FOREIGN, 'record' => null);
        }
        $record = ruTrackerChecker::decodeInheritance((string) $rawRecord);
        if ($record === null || strcasecmp((string) $record['old'], (string) $oldHash) !== 0) {
            return array('status' => self::REPLACEMENT_UNPROVED, 'record' => null);
        }
        return array('status' => self::REPLACEMENT_OWNED, 'record' => $record);
    }

    // Anchored on rTorrent's own default download directory, the one place
    // the daemon is known to be able to write: every download it creates
    // lands there. $topDirectory is only ruTorrent's UI-level ceiling and
    // defaults to '/', where the "mkdir -p /.chk-meta" that sendMagnet()
    // issues fails for any non-root daemon -- taking the whole metadata
    // fetch, and with it every RuTracker replacement, down with it. It stays
    // as the fallback for the case where the daemon's own answer is missing.
    static private function serviceDirectory()
    {
        global $topDirectory;
        $base = (string) rTorrentSettings::get()->directory;
        if ($base === '')
            $base = (string) $topDirectory;
        return FileUtil::addslash($base) . '.chk-meta';
    }

    // Cross-links the old, still-seeding download to the service download:
    // best effort, run after metadata has already started downloading, so a
    // failure here does not undo the successful load.
    static private function markOldTorrent($oldHash, $newHash, $deadline)
    {
        $commands = array(
            new rXMLRPCCommand(getCmd("d.set_custom"), array($oldHash, "chk-meta-new", $newHash)),
            new rXMLRPCCommand(getCmd("d.set_custom"), array($oldHash, "chk-meta-until", (string) $deadline)),
        );
        // Not fire-and-forget: these customs are the ONLY handle pump()
        // ever gets on the fetch. If they do not land, the stub keeps
        // downloading with nobody watching it, and only reapOrphans' deadline
        // eventually clears it -- so the caller is told, and treats it as a
        // fetch that never began.
        if (RuTrackerCustomProjection::write($oldHash, $commands, 'metafetch markOldTorrent') !== true) {
            ruTrackerChecker::logDebug('metafetch: ' . $oldHash
                . ' could not be marked for ' . $newHash . ', the claim did not land');
            return false;
        }
        return true;
    }

    // Pure parser for the exact tracker projection returned by rTorrent.
    // Flat response shape must be exactly [N, 3*N scalars, message] (2 + 3*N scalars).
    static private function parseTrackerProjection($values)
    {
        if (!is_array($values) || count($values) < 2) {
            return null;
        }
        $n = RuTrackerRpcValue::canonicalNonnegativeInteger($values[0]);
        if ($n === null) {
            return null;
        }
        $maxN = intdiv(PHP_INT_MAX - 2, 3);
        if ($n > $maxN) {
            return null;
        }
        $expectedCount = 2 + 3 * $n;
        if (count($values) !== $expectedCount) {
            return null;
        }
        $rawMessage = $values[count($values) - 1];
        if (!is_string($rawMessage)) {
            return null;
        }
        $rows = array();
        for ($i = 0; $i < $n; $i++) {
            $offset = 1 + 3 * $i;
            $url = $values[$offset];
            if (!is_string($url)) {
                return null;
            }
            $failed = RuTrackerRpcValue::canonicalNonnegativeInteger($values[$offset + 1]);
            if ($failed === null) {
                return null;
            }
            $enabled = RuTrackerRpcValue::canonicalNonnegativeInteger($values[$offset + 2]);
            if ($enabled === null) {
                return null;
            }
            $rows[] = array(
                'url' => $url,
                'failed' => $failed,
                'enabled' => $enabled,
            );
        }
        return array(
            'rows' => $rows,
            'message' => $rawMessage,
        );
    }

    // Every exit below logs what happened. The "begin" line alone says only
    // that a fetch was attempted: a misconfigured service directory used to
    // produce that one identical line an hour, forever, and nothing else.
    static public function begin($oldHash, $newHash, $topicId, $announceUrl, $now)
    {
        global $rutrackerMetaDeadline;
        // >= 0: a negative deadline lands in the past, so every fetch would
        // expire on its first pump while still spending an announce probe
        // and a dump fetch to get there. awaitMetadata() already floors its
        // own sibling knob the same way.
        $deadline = $now + max(0, isset($rutrackerMetaDeadline) ? (int) $rutrackerMetaDeadline : 86400);
        ruTrackerChecker::logDebug('metafetch: begin ' . $oldHash . ' -> ' . $newHash
            . ' topic=' . (int) $topicId);

        // The announce URL goes into the magnet's tr= below, so rTorrent will
        // send to it -- which makes this a decision to SEND, and detector.php
        // says the strict host test belongs at every one of those. It was
        // applied only before the layer-2 probe, and a foreign host there just
        // skips layer 2 and falls through to layer 3, which hands the same
        // unchecked URL here. The row it came from was picked by the LOOSE
        // pattern, a substring test over the whole URL, so
        // 'rutracker.evil.example' satisfies it.
        $announceHost = (string) @parse_url((string) $announceUrl, PHP_URL_HOST);
        if (!RuTrackerDetector::isTrackerHost($announceHost)) {
            ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' aborted: the announce host '
                . ($announceHost !== '' ? $announceHost : '(none)') . ' is not RuTracker\'s');
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }

        // load silently swallows a hash it already knows, so an already
        // present successor must be detected before loading. An earlier
        // incomplete run of this plugin announces itself through the
        // chk-meta-old marker and is adopted below; for anything else -- the
        // user, another automation -- how it got there is unknowable, so the
        // token records only the hash, and rutracker.php's layer 0 reads it
        // back to keep this terminal outcome from re-running the whole chain
        // every cycle.
        $exists = ruTrackerChecker::torrentExists($newHash);
        if ($exists === null) {
            ruTrackerChecker::logDebug('metafetch: ' . $oldHash
                . ' aborted: could not tell whether ' . $newHash . ' is already in the client');
            return ruTrackerChecker::STE_ERROR;
        }
        if ($exists === true) {
            // Unless it is OUR stub from an earlier cycle: a deferred load
            // that landed only after the wait loop below gave up was never
            // marked on the old torrent, so nothing pumps it -- declaring it
            // a successor would wedge the fetch behind a stub that cannot
            // finish by itself. The marker begin() plants is what tells the
            // two apart.
            $marker = new rXMLRPCRequest(array(
                new rXMLRPCCommand(getCmd("d.get_custom"), array($newHash, "chk-meta-old")),
                new rXMLRPCCommand(getCmd("d.get_custom"), array($newHash, ruTrackerChecker::REPLACEMENT_MARKER_KEY)),
                new rXMLRPCCommand(getCmd("d.get_custom"), array($newHash, "chk-replaces")),
            ));
            $marker->important = false;
            if (!$marker->success() || !isset($marker->val[2])) {
                // Unreadable is not "somebody else's": the terminal verdict
                // below would stick for good on nothing but a failed read.
                ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' aborted: could not read the marker on '
                    . $newHash . ', deferring rather than judging it');
                return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            }
            if ((string) $marker->val[0] === (string) $oldHash)
                return self::adoptStub($oldHash, $newHash, $deadline);
            $replacement = self::replacementOwnership(
                $marker->val[1], $marker->val[2], $oldHash);
            if ($replacement['status'] === self::REPLACEMENT_OWNED) {
                // A replacement copy staged by a createTorrent() that died
                // before committing. The sweep finishes those; declaring it a
                // successor here would end the predecessor's checks instead.
                ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' aborted: ' . $newHash
                    . ' is a staged replacement the sweep will finish');
                return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            }
            if ($replacement['status'] === self::REPLACEMENT_UNPROVED) {
                // The plugin nonce says this may be a replacement whose
                // record is only partly visible or was damaged. That is not
                // proof of this transaction, but neither is it proof of a
                // foreign occupant: retain every recovery handle and retry.
                ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' aborted: ' . $newHash
                    . ' has a plugin replacement marker without a strict matching record; deferring');
                return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            }

            ruTrackerChecker::setMessage($oldHash,
                ruTrackerChecker::CHKMSG_SUPERSEDED . '|' . $newHash);
            ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' aborted: ' . $newHash
                . ' is already in the client, nothing left to fetch');
            return ruTrackerChecker::STE_NOT_NEED;
        }

        $magnet = 'magnet:?xt=urn:btih:' . $newHash . '&tr=' . rawurlencode($announceUrl);
        $addition = array(
            getCmd("d.set_custom") . "=chk-meta-old," . $oldHash,
            getCmd("d.set_custom") . "=chk-meta-topic," . (int) $topicId,
            getCmd("d.set_custom") . "=chk-meta-until," . $deadline,
        );
        // Stopped, plain load: the meta stub's start=0 survives into the
        // real download rTorrent creates once metadata arrives.
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
        // appears carries OUR complete tuple before touching it.
        $expectedCustoms = array(
            'chk-meta-old' => $oldHash,
            'chk-meta-topic' => (string) (int) $topicId,
            'chk-meta-until' => (string) $deadline,
        );

        for ($attempt = 0; $attempt < self::WAIT_ATTEMPTS; $attempt++) {
            if ($attempt) usleep(self::WAIT_DELAY_US);
            $poll = new rXMLRPCRequest(array(
                new rXMLRPCCommand(getCmd("d.get_custom"), array($newHash, "chk-meta-old")),
                new rXMLRPCCommand(getCmd("d.get_custom"), array($newHash, "chk-meta-topic")),
                new rXMLRPCCommand(getCmd("d.get_custom"), array($newHash, "chk-meta-until")),
                new rXMLRPCCommand(getCmd("d.is_meta"), $newHash),
            ));
            $poll->important = false;
            if (!$poll->run() || $poll->fault || !is_array($poll->val) || count($poll->val) < 4) continue;

            if ((string) $poll->val[0] !== (string) $oldHash
                || (string) $poll->val[1] !== (string) (int) $topicId
                || (string) $poll->val[2] !== (string) $deadline) {
                ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' aborted: the item at '
                    . $newHash . ' belongs to someone else, leaving it alone');
                return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            }

            $status = RuTrackerAtomicOwnership::runState($newHash, $expectedCustoms, true, array('is_meta' => 1));
            if ($status !== RuTrackerAtomicOwnership::ACTED) {
                if ($status === RuTrackerAtomicOwnership::UNCONFIRMED) {
                    $eraseStatus = RuTrackerAtomicOwnership::erase($newHash, $expectedCustoms, array('is_meta' => 1, 'state' => 0, 'is_open' => 0));
                    $outcome = ($eraseStatus === RuTrackerAtomicOwnership::ACTED)
                        ? ', erased the measured stopped item'
                        : ', left the unreadable item for the next cycle to adopt';
                } else {
                    $outcome = ', left the unreadable item for the next cycle to adopt';
                }
                ruTrackerChecker::logDebug('metafetch: ' . $oldHash
                    . ' aborted: could not confirm the metadata stub ' . $newHash . ' running' . $outcome);
                return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            }

            if (!self::markOldTorrent($oldHash, $newHash, $deadline)) {
                // Nothing would advance this stub, and the caller must not be
                // told a fetch is under way. The stub keeps its own marker,
                // so the next cycle finds it here and adopts it.
                return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            }
            ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' loaded the metadata stub '
                . $newHash . ', waiting until ' . $deadline);

            // Give the metainfo a moment to land: it usually already has by
            // the time the marks above are written, and harvesting now saves
            // the replacement an idle hour until the next cycle.
            if (ruTrackerChecker::awaitMetadata($newHash)) {
                ruTrackerChecker::logDebug('metafetch: ' . $oldHash
                    . ' metadata arrived while the cycle waited, harvesting now');
                return self::pump($oldHash, $now, $newHash, $deadline);
            }
            return ruTrackerChecker::STE_META_PENDING;
        }
        ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' aborted: the metadata stub '
            . $newHash . ' never appeared after ' . self::WAIT_ATTEMPTS . ' polls');
        return ruTrackerChecker::STE_CANT_REACH_TRACKER;
    }

    // Picks up a stub begin() loaded on an earlier cycle but never got to
    // mark: start it, claim it on the old torrent, and let pump() take over.
    static private function adoptStub($oldHash, $newHash, $deadline)
    {
        $meta = new rXMLRPCRequest(array(
            new rXMLRPCCommand(getCmd("d.get_custom"), array($newHash, "chk-meta-old")),
            new rXMLRPCCommand(getCmd("d.get_custom"), array($newHash, "chk-meta-topic")),
            new rXMLRPCCommand(getCmd("d.get_custom"), array($newHash, "chk-meta-until")),
            new rXMLRPCCommand(getCmd("d.is_meta"), $newHash),
        ));
        $meta->important = false;
        if (!$meta->success() || $meta->fault || !is_array($meta->val) || count($meta->val) < 4) {
            ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' could not read whether its stub at '
                . $newHash . ' is still waiting for metadata, so it is left alone this cycle');
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }
        if ((string) $meta->val[0] !== (string) $oldHash) {
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }
        $stubTopic = (string) $meta->val[1];
        $stubUntil = (string) $meta->val[2];
        if (RuTrackerRpcValue::canonicalNonnegativeInteger($stubTopic) === null
            || RuTrackerRpcValue::canonicalNonnegativeInteger($stubUntil) === null) {
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }
        $isMetaVal = $meta->val[3];
        if ($isMetaVal !== 0 && $isMetaVal !== 1 && $isMetaVal !== '0' && $isMetaVal !== '1') {
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }
        $isMeta = (int) $isMetaVal;
        $expectedCustoms = array(
            'chk-meta-old' => $oldHash,
            'chk-meta-topic' => $stubTopic,
            'chk-meta-until' => $stubUntil,
        );

        if ($isMeta === 1) {
            $status = RuTrackerAtomicOwnership::runState(
                $newHash,
                $expectedCustoms,
                true,
                array('is_meta' => 1),
                array('chk-meta-until' => (string) $deadline)
            );
            if ($status !== RuTrackerAtomicOwnership::ACTED) {
                ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' could not start its own stub at '
                    . $newHash . ', leaving it unclaimed so the next cycle retries');
                return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            }
            $arrived = false;
        } else {
            $status = RuTrackerAtomicOwnership::setCustoms(
                $newHash,
                $expectedCustoms,
                array('chk-meta-until' => (string) $deadline),
                array('is_meta' => 0)
            );
            if ($status !== RuTrackerAtomicOwnership::ACTED) {
                ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' could not update stub deadline on '
                    . $newHash . '; retaining the durable predecessor marker so the next cycle retries adoption');
                return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            }
            $arrived = true;
        }

        if (!self::markOldTorrent($oldHash, $newHash, $deadline))
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;

        ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' adopted its own stub at '
            . $newHash . ' left by an earlier cycle, '
            . ($arrived ? 'with its metadata already in: harvesting from the session copy'
                        : 'waiting until ' . $deadline));
        return ruTrackerChecker::STE_META_PENDING;
    }

    // Advances a pending fetch by one cycle. Addressed by hash, straight off
    // the markers begin() left on the old download -- no re-classification,
    // no re-detection.
    //
    // @return null on a completed replacement (createTorrent()'s own success
    //         contract), STE_META_PENDING while still waiting,
    //         STE_NOT_NEED when a foreign item took over the successor hash,
    //         or STE_CANT_REACH_TRACKER/STE_ERROR on a retryable/hard failure.
    static public function pump($oldHash, $now, $knownNewHash = null, $knownDeadline = null)
    {
        if ($knownNewHash !== null && $knownDeadline !== null) {
            $rawNewHash = (string) $knownNewHash;
            $rawDeadline = (string) $knownDeadline;
        } else {
            $read = new rXMLRPCRequest(array(
                new rXMLRPCCommand(getCmd("d.get_custom"), array($oldHash, "chk-meta-new")),
                new rXMLRPCCommand(getCmd("d.get_custom"), array($oldHash, "chk-meta-until")),
            ));
            $read->important = false;
            if (!$read->success() || !isset($read->val[0], $read->val[1]))
                return ruTrackerChecker::STE_META_PENDING;
            $rawNewHash = (string) $read->val[0];
            $rawDeadline = (string) $read->val[1];
        }
        $deadline = RuTrackerRpcValue::canonicalNonnegativeInteger($rawDeadline);
        if (!preg_match('/^[0-9A-Fa-f]{40}$/D', $rawNewHash)
            || $deadline === null || $deadline <= 0) {
            ruTrackerChecker::logDebug('metafetch: ' . $oldHash
                . ' has a malformed durable fetch generation; keeping it untouched for diagnosis');
            return ruTrackerChecker::STE_META_PENDING;
        }
        // Policy may use canonical values, but ownership keeps the exact bytes
        // read from the predecessor. In particular, never trim, uppercase or
        // integer-roundtrip the values later used by clearMarks().
        $newHash = strtoupper($rawNewHash);
        $claim = array('hash' => $rawNewHash, 'until' => $rawDeadline);

        // Re-check ownership every cycle. Read all stub customs and is_meta status together.
        $owner = new rXMLRPCRequest(array(
            new rXMLRPCCommand(getCmd("d.get_custom"), array($newHash, "chk-meta-old")),
            new rXMLRPCCommand(getCmd("d.get_custom"), array($newHash, ruTrackerChecker::REPLACEMENT_MARKER_KEY)),
            new rXMLRPCCommand(getCmd("d.get_custom"), array($newHash, "chk-replaces")),
            new rXMLRPCCommand(getCmd("d.get_custom"), array($newHash, "chk-meta-topic")),
            new rXMLRPCCommand(getCmd("d.get_custom"), array($newHash, "chk-meta-until")),
            new rXMLRPCCommand(getCmd("d.is_meta"), $newHash),
        ));
        $owner->important = false;
        if (!$owner->success() || $owner->fault || !is_array($owner->val) || count($owner->val) !== 6) {
            $exists = ruTrackerChecker::torrentExists($newHash);
            if ($exists === false)
                return self::clearMarks($oldHash, ruTrackerChecker::STE_CANT_REACH_TRACKER,
                    $claim['hash'], $claim['until']);
            return self::stillPending($oldHash, null, $now, $deadline, $claim, false, 'unreadable owner');
        }
        if ((string) $owner->val[0] !== (string) $oldHash) {
            $replacement = self::replacementOwnership(
                $owner->val[1], $owner->val[2], $oldHash);
            if ($replacement['status'] === self::REPLACEMENT_OWNED) {
                ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' stopped waiting: ' . $newHash
                    . ' is this transaction\'s own staged replacement, which the sweep will finish');
                return self::clearMarks($oldHash, ruTrackerChecker::STE_CANT_REACH_TRACKER,
                    $claim['hash'], $claim['until']);
            }
            if ($replacement['status'] === self::REPLACEMENT_UNPROVED) {
                ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' keeps waiting: ' . $newHash
                    . ' has a plugin replacement marker without a strict matching record;'
                    . ' no ownership-changing action is safe');
                return self::stillPending($oldHash, null, $now, $deadline, $claim, false,
                    'replacement ownership never became provable before the deadline');
            }
            ruTrackerChecker::setMessage($oldHash,
                ruTrackerChecker::CHKMSG_SUPERSEDED . '|' . $newHash);
            ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' stopped waiting: the item at '
                . $newHash . ' belongs to someone else, leaving it alone');
            return self::clearMarks($oldHash, ruTrackerChecker::STE_NOT_NEED,
                $claim['hash'], $claim['until']);
        }

        $topicVal = (string) $owner->val[3];
        $untilVal = (string) $owner->val[4];
        if (RuTrackerRpcValue::canonicalNonnegativeInteger($topicVal) === null
            || RuTrackerRpcValue::canonicalNonnegativeInteger($untilVal) === null) {
            return self::stillPending($oldHash, null, $now, $deadline, $claim, false, 'malformed stub tuple');
        }
        $isMetaVal = $owner->val[5];
        if ($isMetaVal !== 0 && $isMetaVal !== 1 && $isMetaVal !== '0' && $isMetaVal !== '1') {
            return self::stillPending($oldHash, null, $now, $deadline, $claim, false, 'unreadable is_meta');
        }
        $isMeta = (int) $isMetaVal;
        $stubTuple = array(
            'hash' => $newHash,
            'old' => $oldHash,
            'topic' => $topicVal,
            'until' => $untilVal,
        );

        if ($isMeta !== 0) {
            // Still a BEP-9 stub. Request tracker projection:
            $trackers = new rXMLRPCRequest(array(
                new rXMLRPCCommand(getCmd("d.get_tracker_size"), $newHash),
                new rXMLRPCCommand("t.multicall", array(
                    $newHash, "", getCmd("t.get_url") . "=", getCmd("t.failed_counter") . "=",
                    getCmd("t.is_enabled") . "="
                )),
                new rXMLRPCCommand(getCmd("d.get_message"), $newHash),
            ));
            $trackers->important = false;
            if (!$trackers->success() || $trackers->fault || !is_array($trackers->val)) {
                ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' incomplete tracker projection on ' . $newHash . '; keeping fetch pending');
                return ruTrackerChecker::STE_META_PENDING;
            }
            $projection = self::parseTrackerProjection($trackers->val);
            if ($projection === null) {
                ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' incomplete tracker projection on ' . $newHash . '; keeping fetch pending');
                return ruTrackerChecker::STE_META_PENDING;
            }

            $failed = 0;
            $foreign = 0;
            foreach ($projection['rows'] as $row) {
                if (empty($row['enabled'])) continue;   // a disabled row says nothing
                if (!RuTrackerDetector::isTrackerRow($row['url'])) {
                    $foreign++;   // the dht:// row, or a tr= the tracker's own list added
                    continue;
                }
                $failed = max($failed, $row['failed']);
            }
            $message = $projection['message'];

            if ($failed > 0 && RuTrackerDetector::messageSpeaksForTracker($foreign, $message)
                && !RuTrackerDetector::isTransportFailure($message))
                return self::dropStub($oldHash, $stubTuple, $claim,
                    'the tracker rejected the new hash', 1);
            if ($now > $deadline)
                return self::dropStub($oldHash, $stubTuple, $claim,
                    'metadata did not arrive before the deadline', 1);
            ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' still a metadata stub at ' . $newHash
                . ', waiting (deadline ' . $deadline . ', ' . ($deadline - $now) . 's left)');
            return ruTrackerChecker::STE_META_PENDING;
        }

        ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' metadata arrived for ' . $newHash);
        return self::harvest($oldHash, $stubTuple, $claim, $now, $deadline);
    }

    static private function stillPending($oldHash, $stubTuple, $now, $deadline, $claim, $owned = true,
        $reason = 'metadata did not arrive before the deadline, and the stub can no longer be read', $isMeta = 1)
    {
        if ($now <= $deadline) return ruTrackerChecker::STE_META_PENDING;
        if (!$owned || $stubTuple === null) {
            ruTrackerChecker::setMessage($oldHash, '');
            ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' gave up'
                . ': the deadline passed and the item could not be read, so it is left alone rather than erased unseen');
            return self::clearMarks($oldHash, ruTrackerChecker::STE_CANT_REACH_TRACKER,
                $claim['hash'], $claim['until']);
        }
        return self::dropStub($oldHash, $stubTuple, $claim, $reason, $isMeta);
    }

    static private function harvest($oldHash, $stubTuple, $claim, $now, $deadline)
    {
        $newHash = $stubTuple['hash'];
        $torrent = rTorrent::getSource($newHash);
        $readable = is_object($torrent) && !$torrent->errors();
        $actualHash = $readable ? strtoupper((string) $torrent->hash_info()) : '(unreadable)';
        $valid = $readable && $actualHash === $newHash;

        // Session-file replacement is asynchronous with d.is_meta. Before the
        // durable generation expires, give the shared condition-based wait one
        // bounded chance to observe the expected bytes and then re-read them.
        if (!$valid && $now <= $deadline && ruTrackerChecker::awaitMetadata($newHash)) {
            $torrent = rTorrent::getSource($newHash);
            $readable = is_object($torrent) && !$torrent->errors();
            $actualHash = $readable ? strtoupper((string) $torrent->hash_info()) : '(unreadable)';
            $valid = $readable && $actualHash === $newHash;
        }

        ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' harvest ' . $newHash
            . ' hash matched=' . ($valid ? 'yes' : 'no')
            . ' expected=' . $newHash . ' actual=' . $actualHash);
        if (!$valid) {
            if ($now <= $deadline) {
                ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' harvest ' . $newHash
                    . ($readable
                        ? ' deferred: reason=session-hash-stale'
                        : ' deferred: reason=session-unreadable')
                    . '; session replacement is not durable yet');
                return ruTrackerChecker::STE_META_PENDING;
            }
            if (!$readable) {
                // Even an exactly marked rTorrent row is not deletion authority
                // when its session bytes cannot be inspected at all.
                ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' harvest ' . $newHash
                    . ' outcome=deadline-timeout reason=session-unreadable');
                return self::stillPending($oldHash, null, $now, $deadline, $claim, false,
                    'the metadata arrived but its session copy could never be read', 0);
            }
            return self::dropStub($oldHash, $stubTuple, $claim,
                'session-hash-timeout: the owned service item still had a stale session hash after the deadline', 0);
        }

        if ((string) $torrent->announce() === '') {
            $old = rTorrent::getSource($oldHash);
            if (!is_object($old) || $old->errors()) {
                ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' harvest ' . $newHash
                    . ' deferred: the metadata carries no announce and the predecessor\'s copy could not be'
                    . ' read, so committing now would replace a working torrent with a trackerless one');
                return self::stillPending($oldHash, $stubTuple, $now, $deadline, $claim, true,
                    'the metadata carries no announce and the predecessor\'s copy could never be read', 0);
            }

            $torrent->announce((string) $old->announce());
            $tiers = $old->announce_list();
            if (is_array($tiers) && count($tiers)) $torrent->announce_list($tiers);
        }
        $torrent->comment('https://rutracker.org/forum/viewtopic.php?t=' . (int) $stubTuple['topic']);
        $bytes = (string) $torrent;

        $expectedCustoms = array(
            'chk-meta-old' => $stubTuple['old'],
            'chk-meta-topic' => $stubTuple['topic'],
            'chk-meta-until' => $stubTuple['until'],
        );
        $eraseStatus = RuTrackerAtomicOwnership::erase($newHash, $expectedCustoms, array('is_meta' => 0));
        $stubGone = ($eraseStatus === RuTrackerAtomicOwnership::ACTED);
        ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' harvest ' . $newHash
            . ' bytes=' . strlen($bytes) . ' service item erased=' . ($stubGone ? 'yes' : 'no'));
        if (!$stubGone)
            return self::stillPending($oldHash, $stubTuple, $now, $deadline, $claim, true,
                'the service item could never be erased, so the replacement could not be committed', 0);

        $result = ruTrackerChecker::createTorrent($bytes, $oldHash);
        ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' replacement by ' . $newHash
            . ' returned ' . self::describeResult($result));
        if ($result === null) self::restoreReplacement($oldHash, $newHash);
        return $result;
    }

    static private function activationState($oldHash, $newHash)
    {
        $req = new rXMLRPCRequest(array(
            new rXMLRPCCommand(getCmd("d.get_custom"),
                array($newHash, ruTrackerChecker::REPLACEMENT_MARKER_KEY)),
            new rXMLRPCCommand(getCmd("d.get_custom"), array($newHash, 'chk-replaces')),
            new rXMLRPCCommand(getCmd("d.get_state"), $newHash),
            new rXMLRPCCommand(getCmd("d.is_open"), $newHash),
        ));
        $req->important = false;
        if (!$req->success() || $req->fault || !is_array($req->val) || count($req->val) !== 4) {
            ruTrackerChecker::logDebug('metafetch: unreadable replacement marker on ' . $newHash
                . ', refusing fallback activation');
            return array('status' => self::ACTIVATION_UNKNOWN, 'run' => null, 'marker' => null,
                'rawRecord' => null, 'expectedValues' => null);
        }
        if ((string) $req->val[0] === '')
            return array('status' => self::ACTIVATION_SETTLED, 'run' => null, 'marker' => null,
                'rawRecord' => null, 'expectedValues' => null);
        if (!in_array($req->val[2], array(0, 1, '0', '1'), true)
            || !in_array($req->val[3], array(0, 1, '0', '1'), true)) {
            ruTrackerChecker::logDebug('metafetch: unreadable replacement run state on ' . $newHash
                . ', refusing fallback activation');
            return array('status' => self::ACTIVATION_UNKNOWN, 'run' => null, 'marker' => null,
                'rawRecord' => null, 'expectedValues' => null);
        }
        $replacement = self::replacementOwnership($req->val[0], $req->val[1], $oldHash);
        if ($replacement['status'] !== self::REPLACEMENT_OWNED) {
            ruTrackerChecker::logDebug('metafetch: replacement ownership record on ' . $newHash
                . ' has no plugin nonce plus strict matching record; refusing fallback activation');
            return array('status' => self::ACTIVATION_UNKNOWN, 'run' => null, 'marker' => null,
                'rawRecord' => null, 'expectedValues' => null);
        }
        return array(
            'status' => self::ACTIVATION_UNCONFIRMED,
            'run' => $replacement['record']['run'],
            'marker' => (string) $req->val[0],
            'rawRecord' => (string) $req->val[1],
            'expectedValues' => array(
                'state' => (int) $req->val[2],
                'is_open' => (int) $req->val[3],
            ),
        );
    }

    static private function restoreReplacement($oldHash, $newHash)
    {
        $activation = self::activationState($oldHash, $newHash);
        if ($activation['status'] === self::ACTIVATION_UNKNOWN)
            return;
        if ($activation['status'] === self::ACTIVATION_SETTLED) {
            ruTrackerChecker::logDebug('metafetch: the replacement ' . $newHash
                . ' was already settled by the replacement itself, on a fresher reading than this fetch holds');
            return;
        }
        $run = $activation['run'];
        $marker = $activation['marker'];
        $rawRecord = $activation['rawRecord'];
        $expectedValues = $activation['expectedValues'];
        if (!$run['started'] && !$run['open']) {
            ruTrackerChecker::logDebug('metafetch: leaving the replacement ' . $newHash
                . ' stopped and closed: createTorrent selected the predecessor\'s fresh stopped state');
            RuTrackerAtomicOwnership::clearCustoms(
                $newHash,
                array('chk-replacement' => $marker, 'chk-replaces' => $rawRecord),
                array('chk-replaces', 'chk-replacement'),
                $expectedValues
            );
            return;
        }

        $expectedCustoms = array(
            'chk-replacement' => $marker,
            'chk-replaces' => $rawRecord,
        );
        $afterSuccess = array(
            'chk-replaces' => '',
            'chk-replacement' => '',
        );

        $issued = $run['started'] ? 'd.open+d.start' : 'd.open';
        $status = RuTrackerAtomicOwnership::runState(
            $newHash,
            $expectedCustoms,
            $run['started'],
            $expectedValues,
            $afterSuccess
        );
        if ($status === RuTrackerAtomicOwnership::ACTED) {
            ruTrackerChecker::logDebug('metafetch: the replacement ' . $newHash . ' inherited the old torrent\'s '
                . ($run['started'] ? RuTrackerReplacementRecord::RUN_STARTED : RuTrackerReplacementRecord::RUN_OPEN)
                . ' state (' . $issued . ' accepted)');
            return;
        }
        ruTrackerChecker::logDebug('metafetch: the replacement ' . $newHash . ' should be '
            . ($run['started'] ? RuTrackerReplacementRecord::RUN_STARTED : RuTrackerReplacementRecord::RUN_OPEN)
            . ' but ' . $issued . ' was unconfirmed');
    }

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

    static private function dropStub($oldHash, $stubTuple, $claim, $reason, $expectedIsMeta = 1)
    {
        $newHash = $stubTuple['hash'];
        $expectedCustoms = array(
            'chk-meta-old' => $stubTuple['old'],
            'chk-meta-topic' => $stubTuple['topic'],
            'chk-meta-until' => $stubTuple['until'],
        );
        $status = RuTrackerAtomicOwnership::erase($newHash, $expectedCustoms, array('is_meta' => $expectedIsMeta));
        if ($status !== RuTrackerAtomicOwnership::ACTED) {
            ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' could not confirm stub '
                . $newHash . ' erased after: ' . $reason . '; keeping the claim retryable');
            return ruTrackerChecker::STE_META_PENDING;
        }
        ruTrackerChecker::setMessage($oldHash, '');
        ruTrackerChecker::logDebug('metafetch: ' . $oldHash . ' dropped stub ' . $newHash . ': ' . $reason);
        return self::clearMarks($oldHash, ruTrackerChecker::STE_CANT_REACH_TRACKER,
            $claim['hash'], $claim['until']);
    }

    static private function clearMarks($oldHash, $state, $expectedNewHash, $expectedUntil)
    {
        $status = RuTrackerAtomicOwnership::clearCustoms(
            $oldHash,
            array(
                'chk-meta-new' => $expectedNewHash,
                'chk-meta-until' => $expectedUntil,
            ),
            array('chk-meta-until', 'chk-meta-new')
        );
        if ($status !== RuTrackerAtomicOwnership::ACTED) {
            ruTrackerChecker::logDebug('metafetch: ' . $oldHash
                . ' could not retire its exact fetch generation; keeping it retryable');
            return ruTrackerChecker::STE_META_PENDING;
        }
        return $state;
    }
}
