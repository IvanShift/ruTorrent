<?php

require_once( __DIR__ . '/../detector.php' );
require_once( __DIR__ . '/../announce.php' );
require_once( __DIR__ . '/../forumindex.php' );
require_once( __DIR__ . '/../metafetch.php' );

class RuTrackerCheckImpl
{
    // conf.php documents $updateInterval = 0 as "disable the scheduler", but
    // confirmDeletion()'s per-cycle cap is what stops repeated manual
    // batch_check.php clicks from reaching STE_DELETED in three clicks
    // instead of three real cycles -- with the scheduler disabled the
    // passed-in interval would otherwise be 0 and the cap would never hold.
    // Floors at the smallest legitimate non-zero scheduler interval
    // (1 minute; see plugins/scheduler/conf.php's own "1-6,10,12,15,20,30 or
    // 60" minutes).
    const MIN_DELETE_INTERVAL = 60;

    // The confirmation window is shortened by a tenth before it is compared
    // (see deletionGate()).
    const DELETE_INTERVAL_TOLERANCE_DIVISOR = 10;

    static private function normalizeHash($value)
    {
        if (!is_string($value)) return null;
        $value = strtoupper(trim($value));
        return preg_match('/^[0-9A-F]{40}$/', $value) ? $value : null;
    }

    static private function extractTopicId($url)
    {
        if (!is_string($url) || $url === '') return null;
        $parts = @parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'], $parts['path'])) return null;
        if (!preg_match('/^https?$/i', $parts['scheme'])) return null;
        // The detector's list, not a fifth hand-written copy of it: this one
        // was anchored at the START of the host and knew nothing of
        // rutracker.cc, so 'https://www.rutracker.org/forum/viewtopic.php?t=N'
        // -- the form the site's own links take -- and any .cc comment failed
        // here, the handler answered STE_NOT_NEED, and the torrent was stamped
        // "no need to check" for good.
        // Being more permissive costs nothing: the host is not carried
        // anywhere. Every URL built from the result uses the constant domain
        // (see $topicUrl below and metafetch's comment write), so this decides
        // recognition only, never where a request goes.
        if (!RuTrackerDetector::isTrackerHost($parts['host'])) return null;
        if (strcasecmp($parts['path'], '/forum/viewtopic.php') !== 0) return null;

        $query = array();
        parse_str(isset($parts['query']) ? $parts['query'] : '', $query);
        if (!isset($query['t'])) return null;
        return RuTrackerRpcValue::canonicalPositiveInt32($query['t']);
    }

    // --- Post-API active flow ----------------------------------------------

    // Shared chk-* custom-field boilerplate for the tiny helpers below: a
    // single read and a fire-and-forget write, both routed through getCmd()
    // like every other command here.
    //
    // null means the field could not be READ: the request faulted, or its
    // answer carried no value at all. An UNSET custom is NOT that case --
    // rTorrent answers for it with the empty string, and that is what comes
    // back here. Callers depend on the empty string being distinguishable
    // from null: rememberTopic() writes chk-topic exactly when it reads '',
    // resolveForum() reads '' as "no forum resolved yet" and queues a crawl,
    // and confirmDeletion() reads '' as "no healthy verdict on record" --
    // while for all three a null must conclude nothing. $readable reports
    // which of the two happened without the caller having to guess.
    static private function readCustom($hash, $field, &$readable = null)
    {
        $readable = false;
        $req = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.get_custom"), array($hash, $field)));
        $req->important = false;
        if (!$req->success() || !isset($req->val[0])) return null;
        $readable = true;
        return (string) $req->val[0];
    }

    // @return bool -- whether the write landed. Most callers do not care, but
    // the deletion counter does: a verdict that outruns its own durable record
    // is a verdict nothing can back up later.
    static private function writeCustom($hash, $field, $value)
    {
        return ruTrackerChecker::writeHandlerCustom($hash, $field, (string) $value);
    }

    // chk-topic := $topicId, but only the first time (one read, conditional
    // write) -- a later move/resolve must not clobber an already-known id.
    static private function rememberTopic($hash, $topicId)
    {
        if (self::readCustom($hash, "chk-topic") !== '') return;
        self::writeCustom($hash, "chk-topic", (string) $topicId);
    }

    // Layer 1: the torrent's own RuTracker tracker row plus
    // d.get_message, fed straight into RuTrackerDetector::classify(). Uses
    // the same fields as update.php's embedded t.multicall, but addressed at
    // a single hash -- this handler is reached from both the scheduled pass
    // and a manual batch_check.php click, so it must always re-derive its
    // own verdict rather than trust a cached one.
    // The URL of the torrent's own RuTracker announce row -- the row layer 1
    // actually judged. announce() gives only the PRIMARY tracker, which for a
    // torrent carrying RuTracker further down its announce-list is some other
    // tracker entirely: probing that proves nothing about the topic, and a
    // magnet built from it asks a stranger for RuTracker's metadata.
    static private function ruTrackerRowUrl($rows)
    {
        foreach ($rows as $row) {
            if (empty($row['enabled'])) continue;
            if (RuTrackerDetector::isTrackerRow((string) ($row['url'] ?? ''))) {
                return (string) $row['url'];
            }
        }
        return '';
    }

    static private function layer1Verdict($hash, &$trackerUrl = null)
    {
        $trackerUrl = '';
        $req = new rXMLRPCRequest(array(
            new rXMLRPCCommand(getCmd("d.get_tracker_size"), $hash),
            new rXMLRPCCommand("t.multicall", array($hash, "",
                getCmd("t.get_url") . "=", getCmd("t.is_enabled") . "=",
                getCmd("t.failed_counter") . "=", getCmd("t.success_counter") . "=")),
            new rXMLRPCCommand(getCmd("d.get_message"), $hash),
        ));
        $req->important = false;
        // A local RPC failure carries no tracker signal at all -- treat it
        // the same as a transport failure (retryable), never as "none"
        // (which would wrongly stop future checks of this torrent).
        if (!$req->success()) return 'transport';

        // The transport (php/xmlrpc.php rXMLRPCRequest::run()) never nests --
        // this one request's answer is a single FLAT list: the authoritative
        // tracker count, exactly 4 values per row (url, enabled, failed,
        // success), then d.get_message. The count makes a positively parsed
        // but truncated XMLRPC prefix distinguishable from a complete answer.
        $values = $req->val;
        if (count($values) < 2) return 'transport';

        $trackerCount = RuTrackerRpcValue::canonicalNonnegativeInteger($values[0]);
        if ($trackerCount === null || $trackerCount > intdiv(PHP_INT_MAX - 2, 4)) {
            return 'transport';
        }

        $expectedValues = 2 + 4 * $trackerCount;
        if (count($values) !== $expectedValues) return 'transport';
        $messageIndex = $expectedValues - 1;
        if (!is_string($values[$messageIndex])) return 'transport';
        $message = $values[$messageIndex];

        $rows = array();
        for ($i = 1; $i + 4 <= $messageIndex; $i += 4) {
            if (!is_string($values[$i])) return 'transport';
            $enabled = RuTrackerRpcValue::canonicalNonnegativeInteger($values[$i + 1]);
            $failed = RuTrackerRpcValue::canonicalNonnegativeInteger($values[$i + 2]);
            $success = RuTrackerRpcValue::canonicalNonnegativeInteger($values[$i + 3]);
            if ($enabled === null || $failed === null || $success === null) {
                return 'transport';
            }
            $rows[] = array(
                'url' => $values[$i], 'enabled' => $enabled,
                'failed' => $failed, 'success' => $success,
            );
        }
        $trackerUrl = self::ruTrackerRowUrl($rows);
        $verdict = RuTrackerDetector::classify($rows, $message);
        // Candidates only. Layer 1 runs over every seeding torrent, and the
        // healthy majority answers 'alive' -- logging those would put hundreds
        // of lines an hour into the log and bury everything that matters.
        if ($verdict === 'candidate')
            ruTrackerChecker::logDebug('download_torrent: ' . $hash . ' layer1 verdict=candidate from '
                . self::describeCounters($rows));
        return $verdict;
    }

    // The tracker counters layer 1's verdict was derived from. Hosts only,
    // never the row's URL: a RuTracker announce URL carries the user's passkey
    // in its query string, and no log line may ever contain it.
    static private function describeCounters($rows)
    {
        $described = array();
        foreach ($rows as $row) {
            if (!preg_match(RuTrackerDetector::TRACKER_PATTERN, (string) $row['url'])) continue;
            $host = (string) @parse_url($row['url'], PHP_URL_HOST);
            $described[] = ($host !== '' ? $host : 'unknown host')
                // Already canonical ints: layer1Verdict() rejects the whole
                // reply when any counter fails RuTrackerRpcValue.
                . ' enabled=' . $row['enabled']
                . ' failed=' . $row['failed']
                . ' success=' . $row['success'];
        }
        return count($described) ? implode('; ', $described) : 'no RuTracker tracker row';
    }

    // Layer 3's forum_id cache: chk-forum, written once resolved (feed or
    // full crawl, both outside this handler) and read back here.
    // null means "no usable forum id". $known separates the two reasons for
    // that, which the caller must act on differently: a field that is unset or
    // malformed is a topic whose forum nobody has resolved yet, and queueing a
    // crawl for it is the point -- but a field that could not be READ says
    // nothing at all, and queueing on it spends a tracker-wide walk on a
    // torrent whose forum is very probably cached and fine.
    static private function resolveForum($hash, &$known = null)
    {
        $forum = self::readCustom($hash, "chk-forum");
        $known = ($forum !== null);
        if ($forum === null) return null;
        // One spelling, shared with the two other readers of this custom as a
        // forum id: RuTrackerMetaFetch::registrationTime() and
        // ruTrackerChecker::createTorrent(). See
        // RuTrackerRpcValue::canonicalForumId() for why it is canonical or
        // nothing, and for the drift that made it one function.
        return RuTrackerRpcValue::canonicalForumId($forum);
    }

    // Clears the deletion counter. (The docblock here used to describe
    // dropping chk-forum, which this has never done -- invalidating a stale
    // forum id is the crawl's job, see RuTrackerForumIndex::queueTopic() and
    // topicsAwaitingForum().)
    static private function resetDeletion($hash)
    {
        return self::writeCustom($hash, "chk-del", '');
    }

    // Pure classification of a found dump row;
    // null means the row is simply missing (rule 1), which needs more
    // context (the tracker-confirmation flag, the current time) than this
    // function is given, so that case is left for the caller to resolve.
    static private function classifyDump($rows, $topicId, $localHash)
    {
        if (!isset($rows[$topicId])) return null;

        $row = $rows[$topicId];
        $status = $row['tor_status'];

        if ($status === 7) return array('verdict' => 'absorbed', 'status' => $status);
        if (in_array($status, array(1, 4, 5), true)) return array('verdict' => 'closed', 'status' => $status);
        if (!in_array($status, RuTrackerForumIndex::$VALID_STATUSES, true))
            return array('verdict' => 'unknown', 'status' => $status);
        if ($row['info_hash'] === $localHash) return array('verdict' => 'uptodate', 'status' => $status);
        // The successor hash arrives from the forum dump, i.e. off the
        // network, and from here it becomes a magnet target and a torrent
        // the client is asked to load. Anything that is not a hash is not a
        // verdict: say "unknown" and let a later cycle try again rather than
        // chase it.
        if (self::normalizeHash($row['info_hash']) === null)
            return array('verdict' => 'unknown', 'status' => $status);
        return array('verdict' => 'updated', 'status' => $status, 'newHash' => $row['info_hash']);
    }

    // The shortest gap between two increments that still counts as two
    // separate cycles. The gate is a rate limit whose length is the scheduler
    // PERIOD, but what it actually measures is the moment this torrent is
    // reached INSIDE a poll, and that offset moves: torrents earlier in the
    // same cycle may draw a paced announce probe or pay for a cold dump fetch.
    // On the user's log those 28 neighbouring cycle gaps spanned 3582-3618 s
    // around a median of exactly 3600, and 12 of them therefore came in under
    // the hour -- the range is the whole population's, not the short half's.
    // A gap shorter than the interval was dropped after the cycle had already
    // spent a dump fetch and a share of the per-host announce budget, and
    // reportDeletionProgress() then restated the same N/M as though it had
    // advanced.
    //
    // A tenth of the period absorbs that with room to spare while staying far
    // longer than any hand-clicked batch_check.php cadence. It is not free,
    // and the price is paid in the one thing this cap exists to buy:
    // repeated manual batch_check.php clicks must not fast-forward the
    // required cycles, and everywhere above the floor that protection is now
    // shorter than the interval -- by a full intdiv tenth from $interval 66
    // upward, which is where MIN_DELETE_INTERVAL stops raising the result:
    // 66 - intdiv(66, 10) is 60, the floor exactly. Below 66 the floor still
    // wins and the reduction is smaller than a tenth (61 loses 1 s, not 6).
    // At the shipped hourly default ($updateInterval 60 minutes, so
    // $interval 3600) the gate is 3240 s, so a click 54 minutes after the
    // previous increment advances the counter where 60 minutes used to be
    // required, and three confirmations cost 3 x 54 minutes of clicking
    // instead of 3 x 60. The gate equals the interval at exactly one value,
    // where the floor swallows the subtraction whole: $interval == 60,
    // max(60, 60 - 6) = 60 -- which is what confirmDeletion() floors to when
    // $updateInterval is 0, the documented way to disable the scheduler. It
    // is NOT restored at ten minutes: gate(600) is 540. That 10 percent is
    // the deliberate cost of not losing a scheduled cycle to jitter.
    static private function deletionGate($interval)
    {
        return max(self::MIN_DELETE_INTERVAL,
            $interval - intdiv($interval, self::DELETE_INTERVAL_TOLERANCE_DIVISOR));
    }

    // Is a deletion count whose last increment is $lastIncrement still part of
    // a consecutive run of missing cycles? chk-stime is the independent record
    // of the last up-to-date verdict -- check.php's stateCommands() writes it
    // for STE_UPTODATE and for nothing else -- so a healthy verdict stamped
    // LATER than the last increment proves a healthy cycle landed in between.
    //
    // Both deletion paths ask this one question, so they cannot answer it
    // differently for the same two stored fields.
    //
    // @return string 'unbroken', 'interrupted' (a healthy verdict landed
    //         after the count, or the stamp cannot be read as a time, which
    //         proves nothing about the order), or 'unreadable' (chk-stime
    //         could not be read at all). $detail is the clause the log uses
    //         for what was found: the canonical stamp itself when there is
    //         one -- byte-identical to the stored value, which is safe
    //         precisely because canonicalNonnegativeInteger() accepted it --
    //         and otherwise a phrase naming the reason. A chk-stime that will
    //         NOT parse never reaches it.
    static private function deletionRunStatus($hash, $lastIncrement, &$detail = null)
    {
        $readable = false;
        $successAt = self::readCustom($hash, "chk-stime", $readable);
        if (!$readable) {
            $detail = 'an unreadable healthy-verdict timestamp';
            return 'unreadable';
        }
        // An UNSET chk-stime reads back as '' -- no healthy verdict on record,
        // nothing to compare against. Any other spelling that will not parse
        // cannot prove the run consecutive, and (int) answered 0 for all of
        // them: smaller than any real stamp, so the guard passed and the count
        // stood. The value itself never reaches the log.
        if ($successAt === '') {
            $detail = 'no healthy verdict on record';
            return 'unbroken';
        }
        $successStamp = RuTrackerRpcValue::canonicalNonnegativeInteger($successAt);
        if ($successStamp === null) {
            $detail = 'a stamp that will not parse';
            return 'interrupted';
        }
        $detail = (string) $successStamp;
        return $successStamp > $lastIncrement ? 'interrupted' : 'unbroken';
    }

    // Whether confirmDeletion() ever reached its threshold for this torrent
    // AND that record still stands: the chk-del counter survives the verdict
    // (only an alive/present row resets it), so it is the durable record that
    // a full confirmation run happened. A nonterminal dispatch may replace
    // chk-state with STE_INPROGRESS; a DELETED/ABSORBED dispatch retains it.
    //
    // The count alone is not that record. A settled topic that comes back is
    // written STE_UPTODATE, which stamps chk-stime but leaves a chk-del whose
    // clear may not have landed -- and answering "yes" from the raw count then
    // re-flagged the torrent deleted on the next cycle that gathered no
    // evidence, while confirmDeletion(), given the identical two fields,
    // restarts the count at zero. So the same freshness rule decides here,
    // through the same helper: only a run nothing is known to have interrupted
    // re-affirms a settled verdict, and both "interrupted" and "cannot tell"
    // answer no -- which lands this call site on exactly the state
    // confirmDeletion() returns for the same stored fields.
    //
    // @param string|null $settledToken out: the chk-msg token that describes
    //        the settled run, set only when this answers true. The caller
    //        writes it back, because a refusal here falls through to the
    //        caller's setMessage(''), which strips the token off a row whose
    //        deletion is still settled.
    static private function deletionConfirmedOnce($hash, &$settledToken = null)
    {
        $settledToken = null;
        global $rutrackerDeleteCycles;
        // >= 1: at zero or below, the very first confirmation would return
        // STE_DELETED, and a settled deletion rests for a week.
        $cycles = max(1, isset($rutrackerDeleteCycles) ? (int) $rutrackerDeleteCycles : 3);
        $stored = self::readCustom($hash, "chk-del");
        // The whole canonical pair confirmDeletion() insists on, not just the
        // count: without the stamp of its last increment the run cannot be
        // checked for freshness at all. "03" is likewise not the count 3 --
        // it is a spelling confirmDeletion() cannot have written, so it is no
        // record of a completed confirmation run.
        if ($stored === null || !preg_match('/^([0-9]+):([0-9]+)$/D', (string) $stored, $m)) return false;
        $count = RuTrackerRpcValue::canonicalNonnegativeInteger($m[1]);
        $lastIncrement = RuTrackerRpcValue::canonicalNonnegativeInteger($m[2]);
        if ($count === null || $lastIncrement === null || $count < $cycles) return false;

        $runDetail = null;
        $run = self::deletionRunStatus($hash, $lastIncrement, $runDetail);
        if ($run === 'unbroken') {
            // Always M/M, never the stored count: past the threshold $count
            // may be larger than a since-lowered $cycles, and "4/3" is
            // exactly what M07 removed from the UI.
            $settledToken = ruTrackerChecker::CHKMSG_DELETING . '|' . $cycles . '/' . $cycles;
            return true;
        }
        // Visible, not silent: this is the one place a fully confirmed
        // deletion stops being re-affirmed, and the row goes back to being
        // re-litigated hourly.
        //
        // $runDetail rather than one hard-coded clause, and the same clauses
        // the sibling confirmDeletion() logs: 'interrupted' covers both a
        // healthy verdict stamped after the count AND a chk-stime that will
        // not parse, and telling an operator that a corrupt field "predates
        // the last up-to-date verdict" sends them hunting for a recent
        // STE_UPTODATE that does not exist instead of at the malformed value.
        ruTrackerChecker::logDebug('download_torrent: ' . $hash . ' the deletion counter reached '
            . $count . ' but ' . ($run === 'unreadable'
                ? 'cannot be checked against ' . $runDetail
                : 'predates the last up-to-date verdict at ' . $runDetail)
            . '; the settled verdict is not re-affirmed');
        return false;
    }

    // Two-independent-sources deletion confirmation: chk-del stores
    // "count:timestamp-of-last-increment". The increment is capped at once
    // per bounded interval, so repeated manual checks cannot fast-forward
    // the configured number of confirmation cycles.
    static private function confirmDeletion($hash, $now, $interval)
    {
        global $rutrackerDeleteCycles;
        // >= 1: at zero or below, the very first confirmation would return
        // STE_DELETED, and a settled deletion rests for a week.
        $cycles = max(1, isset($rutrackerDeleteCycles) ? (int) $rutrackerDeleteCycles : 3);
        $interval = max((int) $interval, self::MIN_DELETE_INTERVAL);

        $count = 0;
        $lastIncrement = 0;
        $stored = self::readCustom($hash, "chk-del");
        if ($stored === null) {
            // Unreadable is not "never counted". Treating it as zero would
            // walk a torrent already at 2 of 3 back to 1 on nothing but a
            // transport hiccup, and a torrent unlucky enough to hit one
            // every few cycles could never finish confirming.
            ruTrackerChecker::logDebug('download_torrent: ' . $hash
                . ' deletion counter unreadable, deferring rather than restarting the count');
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }
        if (preg_match('/^([0-9]+):([0-9]+)$/D', $stored, $m)) {
            // Both halves canonical or neither counts. A bare (int) read "03"
            // as three consecutive cycles the tracker never gave, and the
            // third of those is the settled STE_DELETED verdict.
            $count = RuTrackerRpcValue::canonicalNonnegativeInteger($m[1]);
            $lastIncrement = RuTrackerRpcValue::canonicalNonnegativeInteger($m[2]);
            if ($count === null || $lastIncrement === null) {
                ruTrackerChecker::logDebug('download_torrent: ' . $hash
                    . ' deletion counter is not canonically spelled; the count starts over'
                    . ' rather than counting toward a deletion verdict');
                $count = 0;
                $lastIncrement = 0;
            }
        }

        // "N of M CONSECUTIVE cycles" is enforced by resetDeletion()'s three
        // call sites alone, and until now that write was fire-and-forget: a
        // clear that never landed left a counter for the next missing row to
        // resume, so a single miss could finish a confirmation the tracker
        // never gave consecutively. chk-stime is the independent record of
        // the last up-to-date verdict -- setState() writes it alongside
        // chk-state -- so a count whose last increment PREDATES it cannot be
        // part of a consecutive run: a healthy cycle landed in between.
        // Restarting here makes the clear an optimisation rather than
        // something correctness depends on, and it also covers the layer-3
        // reset site, which the scheduler's own deletion clear never reaches:
        // that one is updatepass.php's free 'alive' fast path
        // (deferVerdict(..., $clearDeletion) -> setFastVerdict()'s chk-del
        // write), and it answers INSTEAD of dispatching the checker, so it
        // never runs in a pass that reaches layer 3 at all.
        // Asked only for a count that exists, because only such a count can
        // be interrupted: with none, both branches below are inert and the
        // answer was read and discarded -- one d.get_custom of chk-stime spent
        // on the first cycle of every confirmation run.
        if ($count > 0) {
            $runDetail = null;
            $run = self::deletionRunStatus($hash, $lastIncrement, $runDetail);
            if ($run === 'unreadable') {
                ruTrackerChecker::logDebug('download_torrent: ' . $hash . ' deletion count ' . $count
                    . ' cannot be checked against an unreadable healthy-verdict timestamp; deferring');
                return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            }
            if ($run === 'interrupted') {
                ruTrackerChecker::logDebug('download_torrent: ' . $hash . ' deletion count ' . $count
                    . ' predates the last up-to-date verdict at ' . $runDetail . ', restarting it');
                $count = 0;
                $lastIncrement = 0;
            }
        }

        // The message is the same token throughout: "row missing, this is
        // confirmation cycle N of M". At N == M the status label already says
        // "probably deleted", and N/M then reads as what backed that verdict.
        if ($count > 0 && ($now - $lastIncrement) < self::deletionGate($interval)) {
            self::reportDeletionProgress($hash, $count, $cycles);
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }

        // Progress toward $cycles, not a tally of how often the verdict was
        // re-confirmed. STE_DELETED rests for a week and is then re-derived,
        // so past the threshold both the stored value and the sentence the UI
        // shows used to grow by one a week for the life of the torrent --
        // "confirmation cycle 4/3". Capping keeps deletionConfirmedOnce()'s
        // ">= cycles" true and still refreshes the stamp, which is what the
        // freshness rule above reads.
        $count = min($count + 1, $cycles);
        if (!self::writeCustom($hash, "chk-del", $count . ':' . $now)) {
            // The verdict must not outrun its own record. STE_DELETED settles
            // and rests for a week, while deletionConfirmedOnce() reads
            // chk-del rather than this local count -- so a lost write at the
            // threshold produces a settled verdict that a later budget-denied
            // cycle then downgrades, un-settling the row into hourly
            // re-litigation. Defer instead: the next cycle re-derives the
            // same count from a chk-del that actually landed.
            ruTrackerChecker::logDebug('download_torrent: ' . $hash
                . ' deletion counter could not be advanced, deferring the verdict');
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }
        self::reportDeletionProgress($hash, $count, $cycles);

        return ($count >= $cycles)
            ? ruTrackerChecker::STE_DELETED
            : ruTrackerChecker::STE_CANT_REACH_TRACKER;
    }

    // The token is all the UI needs: the status label already says "probably
    // deleted" and "deleting|N/M" already carries the count, so the sentence
    // that used to spell this out belongs in the log, not in chk-msg.
    static private function reportDeletionProgress($hash, $count, $cycles)
    {
        ruTrackerChecker::setMessage($hash,
            ruTrackerChecker::CHKMSG_DELETING . '|' . $count . '/' . $cycles);
        ruTrackerChecker::logDebug('download_torrent: ' . $hash . ' row missing from the dump for '
            . $count . ' of ' . $cycles . ' consecutive cycles, with the tracker confirming the deletion');
    }

    // The superseded token is a settled verdict only while this topic's
    // successor remains in the client. The missing token is a durable retry
    // obligation and is recognized under any intermediate checker state.
    //
    // @return array|null the recorded successor and whether it is already
    //         missing, or null when the normal flow must run
    static private function successorRecord($hash)
    {
        $req = new rXMLRPCRequest(array(
            new rXMLRPCCommand(getCmd("d.get_custom"), array($hash, "chk-state")),
            new rXMLRPCCommand(getCmd("d.get_custom"), array($hash, "chk-msg")),
        ));
        $req->important = false;
        if (!$req->success() || !isset($req->val[0], $req->val[1])) return null;

        $state = RuTrackerRpcValue::canonicalNonnegativeInteger($req->val[0]);
        $parts = explode('|', (string) $req->val[1], 2);
        if (count($parts) !== 2) return null;
        $successor = self::normalizeHash($parts[1]);
        if ($successor === null) return null;
        if ($parts[0] === ruTrackerChecker::CHKMSG_SUCCESSOR_MISSING)
            return array('hash' => $successor, 'missing' => true);
        if ($parts[0] !== ruTrackerChecker::CHKMSG_SUPERSEDED
            || ($state !== ruTrackerChecker::STE_NOT_NEED
                && $state !== ruTrackerChecker::STE_INPROGRESS)) return null;
        return array('hash' => $successor, 'missing' => false);
    }

    // How long the paced probe actually sleeps. A misconfigured non-positive
    // pause must not turn into a negative sleep() argument, and zero is a
    // legitimate setting, so the floor is 0, not 1 -- that part is
    // probePause()'s job.
    //
    // The jitter goes through the SAME clamp rather than being added after it.
    // Written as probePause($configured) + random_int(0, 3) it escaped the
    // only clamp on this call path, which probePause()'s own contract (and
    // EntrypointsTest, which pins that the configured pause reaches sleep()
    // through it) says is the last word: a configured maximum could sleep
    // past PROBE_PAUSE_MAX, and a configured 0 still slept up to 3 seconds,
    // so the setting had no off position at all. Jitter spreads a pause that
    // exists; it never invents one where the operator asked for none.
    static private function probeSleepSeconds($configured)
    {
        $pause = RuTrackerAnnounce::probePause($configured);
        return $pause > 0 ? RuTrackerAnnounce::probePause($pause + random_int(0, 3)) : 0;
    }

    static public function download_torrent($url, $hash, $oldTorrent)
    {
        global $rutrackerLayer2Enabled, $rutrackerAnnouncePause, $rutrackerAnnounceCap, $updateInterval;

        $topicId = self::extractTopicId($url);
        if ($topicId === null && is_object($oldTorrent))
            $topicId = self::extractTopicId($oldTorrent->comment());
        if ($topicId === null) return ruTrackerChecker::STE_DECLINED;

        $localHash = self::normalizeHash($hash);
        if ($localHash === null) return ruTrackerChecker::STE_NOT_NEED;

        // Layer 0: a settled successor needs one existence probe. A missing successor
        // already carries a durable retry marker and needs no second probe;
        // the checker revalidates it under this run's local-id guard.
        $successor = self::successorRecord($hash);
        if ($successor !== null) {
            if ($successor['missing']) {
                if (!ruTrackerChecker::retainMissingSuccessor($hash, $successor['hash']))
                    return ruTrackerChecker::STE_ERROR;
            } else {
                // Unknown presence is not evidence of removal.
                if (ruTrackerChecker::torrentExists($successor['hash']) !== false)
                    return ruTrackerChecker::STE_NOT_NEED;
                if (!ruTrackerChecker::recordMissingSuccessor($hash, $successor['hash']))
                    return ruTrackerChecker::STE_ERROR;
            }
        }

        self::rememberTopic($hash, $topicId);

        // Layer 1: local, request-free verdict. Runs on
        // every call -- including a manual batch_check.php click -- rather
        // than trusting a cached scheduler verdict.
        $verdict = self::layer1Verdict($hash, $trackerUrl);
        if ($verdict === 'alive') {
            self::resetDeletion($hash);
            ruTrackerChecker::setMessage($hash, '');
            return ruTrackerChecker::STE_UPTODATE;
        }
        // 'cold' is not a failure: the torrent simply has not announced in this
        // rTorrent session, which is the permanent state of a stopped one. There
        // is nothing to judge by, so keep whatever verdict is already stored --
        // the same call updatepass.php's fast pass makes when it skips a cold
        // row without writing state. Reporting "cannot reach the tracker" here
        // used to overwrite a perfectly good verdict, and a stopped torrent is
        // outside the seeding view the hourly cycle walks, so it never recovered.
        if ($verdict === 'cold') return ruTrackerChecker::STE_UNCHANGED;
        if ($verdict === 'none') {
            // A parsed reply with no enabled RuTracker row says nothing about
            // the owned topic. Keep the prior verdict and its explanation.
            ruTrackerChecker::logDebug('download_torrent: ' . $hash . ' no-enabled-row');
            return ruTrackerChecker::STE_UNCHANGED;
        }
        // The sentence goes with the state it explained: init.js appends
        // chk-msg to whatever chk-state is current, so a token an earlier
        // cycle stored ('deleting|2/3', 'fuse|<host>', 'topic-status|5')
        // would render under a verdict it does not describe -- "No need --
        // the topic is missing from the forum list; confirmation cycle 2/3".
        // The no-signal 'cold' and 'none' exits above keep the previous
        // verdict and its sentence. The inconclusive exits further down --
        // a chk-forum that could not be read, an
        // unavailable dump -- learn nothing at all: they leave the row
        // untouched, token included, because the deletion counter the token
        // names is untouched too. An ambiguous tor_status is NOT one of them
        // and was wrongly listed here: that exit is reached only after
        // resetDeletion() has written chk-del and setMessage() has cleared
        // chk-msg, so both the row and the token are already gone. Layer 2's 'uncertain'
        // used to be on that list and no longer is: since the 2026-09-05
        // contract change it does not exit here at all, it falls through to
        // layer 3, and whichever of the exits below it reaches decides. One
        // consequence of that fall-through is worth naming: an uncertain
        // announce can now reach queueTopic(), which it could not before. It
        // is bounded the same way every other caller is -- spawnCrawl() by
        // sweepAllowed(), the store by the miss window -- and five other
        // probeDecision values already reached it.
        //
        // These paths cannot use the earlier verdict's stored token.
        if ($verdict === 'transport') {
            ruTrackerChecker::setMessage($hash, '');
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }
        if ($verdict !== 'candidate') {
            ruTrackerChecker::setMessage($hash, '');
            return ruTrackerChecker::STE_NOT_NEED;
        }

        // Only the enabled canonical row layer 1 actually judged may feed an
        // outgoing action. Falling back to Torrent::announce() would let an
        // unrelated primary tracker stand in for a missing RuTracker row.
        $announceUrl = $trackerUrl;
        $host = (string) @parse_url($announceUrl, PHP_URL_HOST);

        // Layer 2: passkey-less announce confirmation.
        // Optional and budgeted; the budget (reserveProbe/recordOutcome) is
        // consulted here too so repeated manual checks cannot outrun it --
        // the windowed cap is persisted (RuTrackerState, via announce.php),
        // so it holds across manual batch_check.php clicks just as much as
        // across the hourly update.php pass. $updateInterval*60 is the same
        // window every other per-cycle knob in this plugin uses; probeDecision/
        // reserveProbe floor it themselves so a disabled scheduler ($updateInterval=0)
        // cannot void the cap.
        $announceWindow = (int) $updateInterval * 60;
        $trackerConfirmed = false;
        // Named skip reasons, all of them logged: a cycle that concluded
        // nothing must say whether layer 2 was off, out of budget, or cooling
        // down after a 403 -- the three are indistinguishable from the verdict
        // alone, and guessing between them is exactly what this log removes.
        $probeDecision = 'skipped';
        if (empty($rutrackerLayer2Enabled)) $skip = 'disabled in the configuration';
        elseif ($host === '') $skip = 'the torrent carries no announce host';
        // The handler is dispatched by the topic COMMENT, and layer 1's verdict
        // comes from the t-ru tracker ROW -- neither guarantees the torrent's
        // primary announce is RuTracker's. An answer from some other tracker
        // proves nothing about the RuTracker topic (and the probe itself would
        // land on a tracker that never asked for it), so a foreign host is
        // treated exactly like a missing one: fall through to layer 3.
        // isTrackerHost(), not TRACKER_PATTERN: this decides whether to
        // SEND a request, so it must match whole domain labels rather than a
        // substring -- 'rutracker.evil.example' satisfies the latter.
        elseif (!RuTrackerDetector::isTrackerHost($host))
            $skip = 'the announce host ' . $host . ' is not RuTracker\'s';
        else {
            // reserveProbe(), not probeDecision(): the slot must be taken in
            // the same locked write that judges the budget, or two concurrent
            // checks both spend the last one.
            // One timestamp for the whole probe. releaseProbe() has to be
            // able to tell whether the window it is refunding into is still
            // the one the slot was taken from, and a fresh time() at release
            // cannot say -- the paced sleep below can straddle the boundary.
            $probeAt = time();
            $probeDecision = RuTrackerAnnounce::reserveProbe($host, $probeAt,
                RuTrackerAnnounce::probeCap($rutrackerAnnounceCap), $announceWindow);
            if ($probeDecision === 'cap') $skip = 'the per-host announce cap for ' . $host . ' is exhausted';
            elseif ($probeDecision === 'cooldown') $skip = 'the 403 cooldown for ' . $host . ' is still active';
            // Anything that is not an explicit 'allow' skips, rather than the
            // other way round: a reservation that could not be recorded (see
            // reserveProbe()) must not buy a request, and neither must any
            // answer added later that this branch has not been taught yet.
            elseif ($probeDecision !== 'allow') $skip = 'the announce budget for ' . $host . ' could not be reserved';
            else $skip = null;
        }
        if ($skip !== null)
            ruTrackerChecker::logDebug('download_torrent: ' . $hash . ' layer2 skipped: ' . $skip);

        if ($probeDecision === 'allow') {
            sleep(self::probeSleepSeconds($rutrackerAnnouncePause));
            $probeUrl = RuTrackerAnnounce::buildUrl($announceUrl, $localHash,
                RuTrackerAnnounce::makePeerId(), 63981, bin2hex(random_bytes(4)));
            if ($probeUrl === null) {
                // The slot was reserved for a request that will not happen.
                // Leaving it spent would shrink the budget for no traffic.
                RuTrackerAnnounce::releaseProbe($host, $probeAt);
                // And the decision must stop reading as 'allow': zero HTTP
                // happened, so this is one more way the probe did not run,
                // and the settled-deletion guard below asks exactly that
                // question. isTrackerRow() accepts a row by its host while
                // buildUrl() needs a path too, so a hand-edited or
                // magnet-sourced 'udp://bt.t-ru.org:2710' row lands here --
                // and left as 'allow' it downgraded a settled STE_DELETED to
                // STE_CANT_REACH_TRACKER every cycle, on the one path the
                // guard was written for.
                $probeDecision = 'unbuildable';
                ruTrackerChecker::logDebug('download_torrent: ' . $hash
                    . ' layer2 skipped: the announce URL cannot be turned into a probe URL');
            } else {
                $client = ruTrackerChecker::makeClient($probeUrl, "GET", "", "",
                    RuTrackerAnnounce::PROBE_USER_AGENT);
                RuTrackerAnnounce::recordOutcome($host, time(), $client->status);
                $answer = RuTrackerAnnounce::classify($client->status, $client->results);
                // The probe URL itself is never logged: buildUrl() strips the
                // passkey, but the announce host is all a diagnosis needs.
                ruTrackerChecker::logDebug('download_torrent: ' . $hash . ' layer2 verdict=' . $answer
                    . ' http=' . (int) $client->status . ' host=' . $host);
                if ($answer === 'registered') {
                    self::resetDeletion($hash);
                    ruTrackerChecker::setMessage($hash, '');
                    return ruTrackerChecker::STE_UPTODATE;
                }
                // 2026-09-05 contract change (S01). This used to be
                //     if ($answer === 'uncertain') return STE_CANT_REACH_TRACKER;
                // which was a real decision, not an accident: plan Task 11
                // spelled out "uncertain -> STE_CANT_REACH_TRACKER" and its
                // reference implementation repeated it. The rule is replaced
                // deliberately, and the amendment is recorded in plan Task 11
                // and design 4.2-4.3.
                //
                // Why it changes: layer 3 is an INDEPENDENT source. The forum
                // dump is fetched from a different host, needs no passkey and
                // no announce budget, and answers a different question --
                // "what does the forum list say about this topic" rather than
                // "does the tracker still know this hash". An announce that
                // taught this cycle nothing is no reason to discard an answer
                // the dump can give on its own: absorbed, closed, up to date
                // and superseded verdicts all stand without any tracker
                // confirmation, and every one of them used to wait for the
                // next cycle whose probe happened to come back conclusive.
                //
                // What does NOT change: an uncertain answer still confirms
                // nothing. $trackerConfirmed stays false, so a missing row
                // cannot start or advance a deletion count, and a settled
                // DELETED is only re-affirmed through deletionConfirmedOnce(),
                // which re-reads the counter AND chk-stime. 'inconclusive'
                // rather than leaving 'allow' in place: the guard below asks
                // "did a probe that could confirm anything run", and this one
                // could not, while the reasons the probe never ran stay
                // separately named ('cap', 'cooldown', 'unbuildable', ...).
                if ($answer === 'uncertain') $probeDecision = 'inconclusive';
                else $trackerConfirmed = true;
            }
        }

        // Layer 3: classification from the forum's static dump.
        $forumKnown = true;
        $forumId = self::resolveForum($hash, $forumKnown);
        if ($forumId === null && !$forumKnown) {
            // Nothing was learned about this torrent's forum, so nothing is
            // recorded about it either: no queue entry, and the stored token
            // stands, exactly like layer 2's inconclusive answer.
            ruTrackerChecker::logDebug('download_torrent: ' . $hash
                . ' layer3 could not read chk-forum; nothing is queued and nothing is concluded');
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }
        if ($forumId === null) {
            RuTrackerForumIndex::queueTopic($topicId);
            // Internal bookkeeping, not a verdict: the user is told nothing
            // (and any stale token is cleared), the reason goes to the log.
            ruTrackerChecker::setMessage($hash, '');
            ruTrackerChecker::logDebug('download_torrent: ' . $hash
                . ' layer3 forum unknown for topic ' . $topicId . ', queued for a sweep');
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }
        ruTrackerChecker::logDebug('download_torrent: ' . $hash . ' layer3 forum=' . $forumId
            . ' from the chk-forum cache');

        // The third argument is what turns "unavailable" from a dead end into
        // a diagnosis. fetchDump() classifies the non-answers it gives -- an
        // HTTP refusal, a transport failure, an empty body, a body that is
        // not a dump, a reservation it could not take, a cached body that
        // went missing, a fetch that could not become the durable one -- and
        // this is the consumer that prints the word, so it prints the
        // classification with it. Only dump-absent, dump-refused,
        // dump-empty and dump-malformed go through crawlFailureReason(), so
        // only they carry the shared 'statuses=' detail (http-status=N, or a named transport
        // failure below 100); the rest are bare codes. Either way it is a
        // classification, never third-party payload text.
        $dumpReason = null;
        $dumpAbsent = false;
        $dump = RuTrackerForumIndex::fetchDump($forumId, null, $dumpReason, $dumpAbsent);
        // An empty parsed dump is not null. For a 404/410, re-resolve the
        // topic even though chk-forum is filled; a transient refusal proves
        // nothing and must not launch a tracker-wide crawl.
        if ($dump === null) {
            if ($dumpAbsent) RuTrackerForumIndex::queueTopic($topicId);
            ruTrackerChecker::logDebug('download_torrent: ' . $hash . ' layer3 dump forum=' . $forumId
                . ' unavailable'
                . (($dumpReason === null || $dumpReason === '') ? '' : ': ' . $dumpReason)
                . ($dumpAbsent ? '; topic ' . $topicId . ' resolution attempted' : ''));
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }
        $rows = $dump['rows'];
        ruTrackerChecker::logDebug('download_torrent: ' . $hash . ' layer3 dump forum=' . $forumId
            . (empty($dump['fresh'])
                ? ' unchanged, ' . count($rows) . ' rows from the cache'
                : ' fetched, ' . count($rows) . ' rows'));

        $decision = self::classifyDump($rows, $topicId, $localHash);
        ruTrackerChecker::logDebug('download_torrent: ' . $hash . ' layer3 topic=' . $topicId . ' '
            . ($decision === null
                ? 'row missing from the dump'
                : 'verdict=' . $decision['verdict'] . ' tor_status=' . $decision['status']));
        if ($decision === null) {
            // Row missing: could be a move to another forum, not proof of
            // deletion on its own, so re-queue resolution -- a sweep that
            // finds the topic elsewhere overwrites chk-forum with the new
            // one. What it must NOT do is forget the forum now: layer 3
            // cannot fetch a dump without it, so the next cycle would stop
            // at "forum unknown" and the confirmation counter could never
            // advance past 1 -- and a topic that really was deleted is
            // exactly the one no crawl will ever resolve again, so
            // STE_DELETED would be unreachable by construction. Only count
            // towards it when layer 2 independently confirmed the hash is
            // unregistered.
            RuTrackerForumIndex::queueTopic($topicId);
            if (!$trackerConfirmed) {
                // A probe that confirmed nothing is no evidence either way:
                // one that was never sent, and -- since the 2026-09-05
                // contract change -- one that ran and answered 'uncertain'
                // alike. When the deletion was already fully confirmed once
                // (chk-del at the threshold) and the row is still missing,
                // keep the settled verdict; only a probe that CONFIRMED the
                // hash is registered may move it. Downgrading DELETED to
                // "can't reach" un-settles the row into hourly re-litigation
                // until the deletion is re-confirmed from scratch, and with
                // layer 2 switched off in the configuration it can never be
                // re-confirmed at all.
                //
                // Tested as "not an allowance", not as a list of refusal
                // labels: every path into this branch gathered no evidence
                // about registration. A probe that answered 'registered'
                // returns up to date above and one that answered
                // 'unregistered' sets $trackerConfirmed, so neither is here.
                // Since the 2026-09-05 contract change one path here DID
                // perform HTTP: an 'uncertain' answer arrives as
                // $probeDecision 'inconclusive'. It is still not an
                // allowance, because what this guard asks is whether the
                // probe confirmed anything, not whether a request was sent.
                // Enumerating 'cap' and 'cooldown' would silently exclude the
                // rest -- layer 2 disabled, no announce host, a foreign host,
                // a budget that could not be recorded, an announce row that
                // could not become a probe URL, and now 'inconclusive' too.
                // Same rule, same reason, as the skip gate above.
                //
                // With 'unbuildable' and 'inconclusive' both named, no path
                // that reaches here still reads 'allow', so today the conjunct
                // discriminates nothing: it is a default-deny against a value
                // added later. It is also what makes the regression test
                // load-bearing: measured, dropping the conjunct AND reverting
                // the 'unbuildable' assignment together leaves the suite green,
                // so without it that test would pass with its own production
                // change removed.
                $settledToken = null;
                if ($probeDecision !== 'allow' && self::deletionConfirmedOnce($hash, $settledToken)) {
                    // Re-asserted, not assumed to still be there. The refusal
                    // inside deletionConfirmedOnce() falls through to the
                    // setMessage('') below, so a single unreadable chk-stime
                    // strips "deleting|M/M" off a row that is still settled --
                    // and nothing else ever writes it back until layer 2 gets
                    // to run a real probe again. Writing the token on the path
                    // that re-affirms the verdict makes that loss recover on
                    // the next readable cycle, like the verdict itself.
                    ruTrackerChecker::setMessage($hash, $settledToken);
                    // Names the decision rather than guessing at it: 'cap'
                    // and 'cooldown' really are a spent budget, but
                    // 'inconclusive' is a probe that ran and answered
                    // nothing, and 'skipped'/'unbuildable' never reached the
                    // network at all. All of them are classification tokens
                    // from this file's own vocabulary, never remote text.
                    ruTrackerChecker::logDebug('download_torrent: ' . $hash
                        . ' row still missing and layer 2 confirmed nothing (probe=' . $probeDecision
                        . '): the settled DELETED verdict stands');
                    return ruTrackerChecker::STE_DELETED;
                }
                // Nothing was decided, so there is nothing to report: clear
                // chk-msg (an older token here would now be stale) and log.
                ruTrackerChecker::setMessage($hash, '');
                ruTrackerChecker::logDebug('download_torrent: ' . $hash
                    . ' row missing from the dump, but the tracker never confirmed deletion');
                return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            }
            return self::confirmDeletion($hash, time(), (int) $updateInterval * 60);
        }

        // The row is present in the dump -- whatever its verdict below, that
        // alone disproves "missing", so any deletion count built up over
        // prior miss cycles is stale, and so is any "row missing, cycle
        // n/3" message confirmDeletion() may have left behind.
        //
        // The clear has to LAND. confirmDeletion()'s own safety net compares
        // the count against chk-stime, and setState() writes chk-stime only
        // for STE_UPTODATE -- so on the closed, absorbed, updated and unknown
        // verdicts below, a lost clear leaves a counter nothing will
        // invalidate, and one later missing cycle finishes a confirmation the
        // tracker never gave consecutively.
        if (!self::resetDeletion($hash)) {
            ruTrackerChecker::logDebug('download_torrent: ' . $hash
                . ' the row is present but its stale deletion counter could not be cleared, deferring');
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }
        ruTrackerChecker::setMessage($hash, '');

        // Layer 4: hand a genuinely new hash to the metadata fetch (design
        // doc 4.4); every other verdict is terminal here.
        switch ($decision['verdict']) {
            case 'absorbed':
                // The bare topic id: the status label already says "absorbed,
                // resolve manually", so init.js only has to turn this into the
                // topic URL -- no sentence to translate at all.
                ruTrackerChecker::setMessage($hash,
                    ruTrackerChecker::CHKMSG_ABSORBED . '|' . $topicId);
                return ruTrackerChecker::STE_ABSORBED;
            case 'closed':
                ruTrackerChecker::setMessage($hash,
                    ruTrackerChecker::CHKMSG_TOPIC_STATUS . '|' . $decision['status']);
                return ruTrackerChecker::STE_NOT_NEED;
            case 'uptodate':
                return ruTrackerChecker::STE_UPTODATE;
            case 'updated':
                return RuTrackerMetaFetch::begin($hash, $decision['newHash'], $topicId, $announceUrl, time());
            default: // 'unknown': tor_status ambiguous, retry later
                return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }
    }
}

ruTrackerChecker::registerTracker("/rutracker\./", "/rutracker\.|t-ru\.org/", "RuTrackerCheckImpl::download_torrent");
