<?php

require_once("state.php");
require_once("detector.php");
require_once("forumindex.php");
require_once("announce.php");
require_once(dirname(__FILE__) . "/runstate.php");
require_once(dirname(__FILE__) . "/../erasedata/removewithdata.php");

// The scheduler's per-cycle glue: turns update.php's raw d.multicall values
// into per-torrent rows (parseMulticall), decides per row whether the local
// detector's verdict needs the expensive checker or can be resolved for free
// (run()), and keeps the topic -> forum_id map fresh once per cycle
// (pollFeed()). update.php itself stays a thin XMLRPC-building driver; every
// branch worth testing lives here instead.
class RuTrackerUpdatePass
{
    const COLUMNS = 8;
    const FORUM_CORRECTION_MAX_AGE = 2592000; // 30 days

    // Test seam: run()'s production default dispatches straight to
    // ruTrackerChecker::run(); tests override this via
    // strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', ...) to
    // capture dispatches without loading a real tracker handler. null means
    // "use the production default".
    private static $checker = null;
    private static $foreignAuthoritativeResolver = null;

    static public function isForeignAuthoritative($row)
    {
        if (self::$foreignAuthoritativeResolver !== null) {
            return (bool) call_user_func(self::$foreignAuthoritativeResolver, $row);
        }
        if (isset($row['comment']) && is_string($row['comment']) && $row['comment'] !== '') {
            return ruTrackerChecker::isForeignComment($row['comment']);
        }
        if (isset($row['hash'])) {
            return ruTrackerChecker::hasForeignAuthoritativeComment($row['hash']);
        }
        return false;
    }

    static private function forumCorrections()
    {
        $stored = RuTrackerState::load('updatepass')['forum_corrections'] ?? array();
        return is_array($stored) ? $stored : array();
    }

    // One persisted correction, or null when the stored row is not one. The
    // row authorises a d.set_custom of chk-forum AND a full destructive
    // checker run, and every field was read through a bare (int): '22abc',
    // '022' and 22.9 wrote the forum 22, true wrote 1, '0x16' wrote 0 and
    // '-22' wrote -22 -- and 0 and -22 are ids resolveForum() can never
    // accept, so the mapping installed made writeForumMapping() answer
    // FORUM_WRITE_CURRENT for ever while nothing cleared the obligation. The
    // topic half went the same way: '0200', 200.7 and '200abc' all matched a
    // live chk-topic of "200".
    static private function forumCorrectionRecord($record)
    {
        if (!is_array($record)) return null;
        $topic = RuTrackerRpcValue::canonicalPositiveInt32($record['topic'] ?? null);
        $forum = RuTrackerRpcValue::canonicalPositiveInt32($record['forum'] ?? null);
        // The retention bound. ABSENT is the legacy row that predates the
        // stamp and reads as 0 -- immediately prunable, exactly as it always
        // was. PRESENT but unreadable is corruption: it can never age out, so
        // the row would outlive every window it was meant to be kept for.
        $at = array_key_exists('at', $record)
            ? RuTrackerRpcValue::canonicalNonnegativeInteger($record['at']) : 0;
        return ($topic === null || $forum === null || $at === null)
            ? null : array('topic' => $topic, 'forum' => $forum, 'at' => $at);
    }

    // There is no canonicalising guard here, and there deliberately is not.
    // The row's topic and forum are the feed's own: parseFeed() admits an
    // entry only through canonicalPositiveInt32() and rejects the whole
    // document when any entry fails (forumindex.php parseFeed()), and the only
    // caller -- pollFeed(), below -- passes that value straight through. So a
    // re-check here was unreachable by construction, and a guard no input can
    // reach is not a guard: weakening it back to (int) changed no test in this
    // suite, because no test could construct the input it refuses.
    //
    // The defence that does the work is at the READ sites, since the threat is
    // bytes already on disk -- hand-edited, half-written, or left by an older
    // release -- which no write-time check can reach at all.
    // forumCorrectionRecord() reads topic, forum and 'at' canonically at every
    // one of those sites, and each of them has a test that dies when it is
    // weakened.
    static private function rememberForumCorrection($hash, $topic, $forum)
    {
        $hash = strtoupper((string) $hash);
        $now = time();
        return RuTrackerState::update('updatepass', function ($state) use ($hash, $topic, $forum, $now) {
            $pending = isset($state['forum_corrections']) && is_array($state['forum_corrections'])
                ? $state['forum_corrections'] : array();
            foreach ($pending as $storedHash => $record) {
                // A row nobody can read can never be honoured either, so
                // keeping it is an obligation nothing is able to retire.
                $canonical = self::forumCorrectionRecord($record);
                if ($canonical === null
                    || $now - $canonical['at'] > self::FORUM_CORRECTION_MAX_AGE)
                    unset($pending[$storedHash]);
            }
            $pending[$hash] = array('topic' => $topic, 'forum' => $forum, 'at' => $now);
            $state['forum_corrections'] = $pending;
            return $state;
        });
    }

    static private function clearForumCorrection($hash, $expected)
    {
        $hash = strtoupper((string) $hash);
        $expected = self::forumCorrectionRecord($expected);
        RuTrackerState::update('updatepass', function ($state) use ($hash, $expected) {
            $pending = isset($state['forum_corrections']) && is_array($state['forum_corrections'])
                ? $state['forum_corrections'] : array();
            // false is ABSENT, null is present-but-unreadable: the two need
            // different answers below, and (int) told them apart as 0 and 0.
            $current = array_key_exists($hash, $pending)
                ? self::forumCorrectionRecord($pending[$hash]) : false;
            // A newer feed generation may have replaced the obligation while
            // this check ran. Clear only the exact topic/forum pair observed
            // -- or a stored row no parser can read, which nothing can ever
            // honour and which would otherwise hold this hash for ever.
            if ($current === null
                || ($current !== false && $expected !== null
                    && $current['topic'] === $expected['topic']
                    && $current['forum'] === $expected['forum']))
                unset($pending[$hash]);
            if (count($pending)) $state['forum_corrections'] = $pending;
            else unset($state['forum_corrections']);
            return $state;
        });
    }

    /**
     * Installs the durable feed correction on the torrent and answers whether
     * it is now actually in place, so the caller may dispatch a check against
     * it. A COMMAND that answers a question, not a predicate: it writes
     * chk-forum through writeForumMapping() (which is the point -- a check
     * dispatched against the old mapping is worse than no check), and it
     * retires an obligation writeForumMapping() reports as impossible. It was
     * called forumCorrectionReady(), which read as a pure question and hid
     * both of those. The name says what it does now.
     *
     * A process may die after recording the obligation but before writing
     * chk-forum; that record must survive without dispatching a check against
     * the old mapping, which is why "installed" is answered from the row that
     * is actually on the torrent rather than from the durable record.
     */
    static private function installForumCorrection($hash, $record)
    {
        if (!is_array($record)) return false;
        $hash = strtoupper((string) $hash);
        $canonical = self::forumCorrectionRecord($record);
        if ($canonical === null) {
            // Nothing is dispatched, nothing is written, and this returns
            // BEFORE writeForumMapping(), so a retained row costs one log line
            // per cycle -- not the checker run per cycle that made dropping it
            // look necessary. Both call sites dispatch nothing on false.
            //
            // So the bytes stay. Deleting them destroyed the only copy of the
            // evidence while this diagnostic names the document and the hash
            // but never the value, which leaves an operator with a report of
            // corruption and nothing to look at; and the row is bounded
            // anyway, since rememberForumCorrection()'s prune drops every
            // unreadable row the next time the feed applies anything at all.
            // Same rule as a malformed checker claim, which is likewise
            // RETAINED with a sanitised diagnostic (check.php claimCheck()).
            ruTrackerChecker::logDebug('updatepass: the stored forum correction for ' . $hash
                . ' in updatepass.json is not a canonical topic/forum/at record, so no chk-forum'
                . ' is written and no check is dispatched; the row is kept for inspection and the'
                . ' next feed application prunes it');
            return false;
        }
        $topic = $canonical['topic'];
        $forum = $canonical['forum'];
        // The record was loaded before the per-hash mapping lock. Revalidate
        // its generation under that lock: a newer feed response may have
        // replaced the durable target while this cycle was deciding what to
        // dispatch. Topic/forum identify the obligation; its timestamp only
        // bounds retention and does not make an otherwise equal target new.
        $stillCurrent = function () use ($hash, $topic, $forum) {
            $current = self::forumCorrectionRecord(self::forumCorrections()[$hash] ?? null);
            return $current !== null
                && $current['topic'] === $topic
                && $current['forum'] === $forum;
        };
        $status = RuTrackerForumIndex::writeForumMapping(
            $hash,
            $topic,
            $forum,
            (string) $forum,
            true,
            $stillCurrent
        );
        if ($status === RuTrackerForumIndex::FORUM_WRITE_CURRENT
            || $status === RuTrackerForumIndex::FORUM_WRITE_WRITTEN)
            return true;
        if ($status === RuTrackerForumIndex::FORUM_WRITE_OBSOLETE)
            self::clearForumCorrection($hash, $record);
        return false;
    }

    static private function dispatchChecker($checker, $defaultChecker, $row, $correction = null)
    {
        if ($defaultChecker) {
            $performed = false;
            ruTrackerChecker::run(
                $row['hash'], $row['state'], $row['time'], $row['label'], $performed);
        } else {
            call_user_func($checker, $row['hash'], $row);
            // An override is the test seam standing in for an actual checker;
            // production always uses the branch above and its stronger signal.
            $performed = true;
        }
        if ($performed && is_array($correction))
            self::clearForumCorrection($row['hash'], $correction);
    }

    // Splits update.php's flat d.multicall values into one associative row
    // per torrent, 8 values in (see update.php). Originally 11: chk-topic,
    // chk-forum, chk-meta-new and chk-meta-until were fetched but never read
    // by run() (pollFeed()/reapOrphans() resolve those for themselves via
    // their own d.multicall scans), so those four slots were replaced by the
    // two run()'s alive path actually needs -- chk-del and chk-msg -- to
    // reset a stale deletion counter without a per-torrent XMLRPC round
    // trip. Drops a trailing partial group the same way parseTrackerBlob
    // drops a malformed tracker row -- unknowable rather than guessed at.
    // A chk-* counter as the daemon hands it back. An UNSET custom reads back
    // as '' -- the only spelling of the absent 0; the rest is canonical or
    // corruption.
    static private function storedCounter($value)
    {
        if ($value === '') return 0;
        return RuTrackerRpcValue::canonicalNonnegativeInteger($value);
    }

    static public function parseMulticall($values)
    {
        $rows = array();
        for ($i = 0; $i + self::COLUMNS <= count($values); $i += self::COLUMNS) {
            // The whole row is rejected before anything reads it: chk-state
            // and chk-time steer dispatch and the fresh-scan comparison, and
            // zero looks exactly like a never-checked row.
            $state = self::storedCounter($values[$i + 1]);
            $time = self::storedCounter($values[$i + 2]);
            if ($state === null || $time === null) {
                ruTrackerChecker::logDebug('parseMulticall: a row carries a malformed'
                    . ' chk-state/chk-time and is dropped rather than read as never checked');
                continue;
            }
            $trackersComplete = true;
            $trackers = RuTrackerDetector::parseTrackerBlob($values[$i + 7], $trackersComplete);
            $rows[] = array(
                'hash' => $values[$i],
                'state' => $state,
                'time' => $time,
                'label' => $values[$i + 3],
                'message' => $values[$i + 4],
                'del' => $values[$i + 5],
                'msg' => $values[$i + 6],
                'trackers' => $trackers,
                'trackers_complete' => $trackersComplete,
            );
        }
        return $rows;
    }

    // True when any of the torrent's tracker rows matches any registered
    // announce filter. A torrent's tracker list can start with dht:// or any
    // other non-RuTracker row, so every row must be checked, not just the
    // first -- unlike RuTrackerDetector::classify(), which only cares about
    // the (single) RuTracker row, "supported" is a question about the whole
    // torrent.
    static public function isTrackerSupported($trackers, $filters)
    {
        foreach ((array) $trackers as $row)
            foreach ($filters as $filter)
                if (preg_match($filter, (string) ($row['url'] ?? '')))
                    return true;
        return false;
    }

    // The RuTracker row's announce host, or '' when none of the rows
    // matches. Mirrors RuTrackerDetector::classify()'s own row selection so
    // the fuse groups candidates by the same host classify() judged them on.
    static private function hostOf($trackers)
    {
        foreach ((array) $trackers as $row) {
            if (!preg_match(RuTrackerDetector::TRACKER_PATTERN, (string) ($row['url'] ?? ''))) continue;
            // Same row selection as classify(): a disabled row judges nothing
            // and a later one may, and when every RuTracker row is disabled
            // both answer "nothing here" -- '' and 'none'. Answering with a
            // host while the verdict says 'none' would strand the torrent
            // between the two dispatch paths (never a candidate, yet never
            // handed to the generic path either), frozen at whatever
            // chk-state it last carried.
            if (empty($row['enabled'])) continue;
            // Through the announce budget's normaliser, not raw: parse_url
            // does not case-fold a host, so BT.T-RU.ORG would become a fuse
            // group of its own and neither half would reach the floor. One
            // rule for host names, in one place.
            return RuTrackerAnnounce::hostKey(@parse_url($row['url'], PHP_URL_HOST));
        }
        return '';
    }

    static public function run($rows, $forumChanged = array())
    {
        global $rutrackerFuseShare, $rutrackerFuseFloor;
        // A FRACTION of 1, not a percentage: written as 20 rather than 0.2
        // the threshold becomes unreachable and the fuse silently inert, so
        // clamp rather than trust. At 0 with a floor of 0 the opposite
        // happens -- every host trips and nothing is ever checked again --
        // which is why the floor is at least one candidate.
        $share = isset($rutrackerFuseShare) ? min(1.0, max(0.0, (float) $rutrackerFuseShare)) : 0.2;
        $floor = max(1, isset($rutrackerFuseFloor) ? (int) $rutrackerFuseFloor : 3);
        $defaultChecker = self::$checker === null;
        $checker = self::$checker;
        $corrections = self::forumCorrections();
        $forumChangedSet = array();
        foreach ((array) $forumChanged as $hash)
            $forumChangedSet[strtoupper((string) $hash)] = true;

        // Pass 1: classify every row and tally per-host candidate share for
        // the fuse. A META_PENDING row is classified too (it still carries a
        // real tracker signal, worth counting toward its host's health) even
        // though pass 2 dispatches it unconditionally regardless of verdict.
        $verdicts = array();
        $hosts = array();
        $hostStats = array();
        foreach ($rows as $index => $row) {
            if (self::isForeignAuthoritative($row)) {
                $verdicts[$index] = 'none';
                $hosts[$index] = '';
                continue;
            }
            $verdict = RuTrackerDetector::classify(
                $row['trackers'],
                $row['message'],
                $row['trackers_complete']
            );
            $host = self::hostOf($row['trackers']);
            $verdicts[$index] = $verdict;
            $hosts[$index] = $host;
            if ($host === '' || ($verdict !== 'alive' && $verdict !== 'candidate')) continue;
            // A settled DELETED/ABSORBED row answers 'candidate' for good --
            // its announce is meant to keep failing. Counting those into the
            // fuse lets a handful of dead topics hold their host tripped
            // permanently, and a tripped host is one where no REAL candidate
            // is ever investigated. The fuse is for detecting an outage
            // among live torrents; a settled verdict is not evidence of one.
            if (self::isSettled($row)) continue;
            if (!isset($hostStats[$host])) $hostStats[$host] = array('total' => 0, 'candidates' => 0);
            $hostStats[$host]['total']++;
            if ($verdict === 'candidate') $hostStats[$host]['candidates']++;
        }
        $fused = RuTrackerDetector::fuseTrips($hostStats, $share, $floor);

        // Pass 2: act. A torrent already fetching replacement metadata must
        // reach the checker every cycle no matter what layer 1 says about
        // its counters -- that is what advances the pending download.
        $checked = array();
        $uptodate = 0;
        // The fast-path verdicts below are buffered rather than written where
        // they are decided; see flushVerdicts() for why.
        $deferred = array();
        foreach ($rows as $index => $row) {
            $correction = $corrections[strtoupper((string) $row['hash'])] ?? null;
            if ($row['state'] === ruTrackerChecker::STE_META_PENDING) {
                self::dispatchChecker($checker, $defaultChecker, $row,
                    self::installForumCorrection($row['hash'], $correction) ? $correction : null);
                $checked[] = $row['hash'];
                continue;
            }

            // check.php's run() has always honoured $ignoreLabels before
            // doing anything else; this pass's direct setState() writes
            // below must match, or an ignored torrent flaps between
            // STE_IGNORED (a manual check, or a cycle that falls through to
            // the checker) and a scheduler-derived state (this pass's own
            // fast-path writes) depending only on which path last ran.
            //
            // Ahead of the settled gate below, and for that same reason: a
            // label added AFTER a torrent settled into DELETED/ABSORBED must
            // take effect on the next cycle like any other, not a week later
            // when the recheck clock happens to run out.
            if (ruTrackerChecker::isIgnoredLabel($row['label'])) {
                // The sentence under the state must go with it: init.js
                // appends chk-msg to whatever state is current, so a token
                // from the verdict this replaces ("deleting|2/3") would be
                // read as an explanation of "ignored".
                $deferred[] = self::deferVerdict($row, ruTrackerChecker::STE_IGNORED,
                    (string) $row['msg'] !== '' ? '' : null);
                continue;
            }


            // This pass carries every registered tracker: update.php admits a
            // row when ANY announce filter matches (isTrackerSupported), and
            // this is the scheduler's only route into ruTrackerChecker::run().
            // Layer 1 below reads RuTracker tracker rows exclusively, so a
            // Kinozal/NNMClub/Toloka/tfile torrent gives classify() nothing to
            // judge and it answers 'none' -- which for those torrents means
            // "not my jurisdiction", NOT "no signal worth a request". They go
            // straight to their own handler, once per cycle, the way the
            // pre-layer-1 pass dispatched them; hostOf() answered '' for
            // exactly this case in pass 1.
            if ($hosts[$index] === '') {
                if (self::isSettled($row, true) && (time() - $row['time']) <= self::SETTLED_RECHECK)
                    continue;

                self::dispatchChecker($checker, $defaultChecker, $row);
                $checked[] = $row['hash'];
                continue;
            }

            $verdict = $verdicts[$index];
            // A durable feed correction is a stronger signal than the cached
            // rest state and is acknowledged only by a real checker run. Put
            // it ahead of even the free alive path: that path buffers its write
            // until the end of the cycle, so clearing the obligation there
            // could lose it if the flush or the process then failed.
            $correctionReady = self::installForumCorrection($row['hash'], $correction);
            if ($correctionReady
                || isset($forumChangedSet[strtoupper((string) $row['hash'])])) {
                self::dispatchChecker($checker, $defaultChecker, $row,
                    $correctionReady ? $correction : null);
                $checked[] = $row['hash'];
                continue;
            }
            if ($verdict === 'alive') {
                $deferred[] = self::deferVerdict($row, ruTrackerChecker::STE_UPTODATE, null, true, true);
                continue;
            }
            // pollFeed() just wrote a different authoritative forum id for this
            // topic. The cycle-start row still carries the old settled state, so
            // the ordinary rest gate would otherwise hide the correction for a
            // week. A candidate with a successfully changed mapping gets one
            // immediate full check; it also bypasses the host fuse because the
            // feed, not an announce failure, supplied the new signal.
            // Settled is settled: see SETTLED_RECHECK.
            //
            // Below the generic dispatch on purpose -- this rule is about
            // RuTracker's own verdicts, and a Kinozal/NNMClub/Toloka torrent
            // that happens to carry one of the same state numbers must keep
            // reaching its own handler every cycle.
            //
            // And below the free 'alive' branch on purpose too: a torrent
            // whose announce is succeeding again is PROOF the verdict was
            // wrong, it costs no request to act on, and making a wrongly
            // settled torrent wait a week for the obvious is the one thing
            // the rest period must not do.
            if (self::isSettled($row) && (time() - $row['time']) <= self::SETTLED_RECHECK)
                continue;

            if ($verdict === 'transport') {
                // The sentence under the state must change with it: a token
                // an earlier cycle stored ('deleting|2/3', 'fuse|<host>')
                // describes a different verdict, and init.js appends it to
                // whatever state is current.
                $deferred[] = self::deferVerdict($row, ruTrackerChecker::STE_CANT_REACH_TRACKER,
                    (string) $row['msg'] !== '' ? '' : null);
                continue;
            }
            if ($verdict !== 'candidate') continue; // cold / none: no request-worthy signal yet

            $host = $hosts[$index];
            if (in_array($host, $fused, true)) {
                // A settled verdict is DEFERRED by a fused host, never
                // overwritten by it. Pass 1 keeps settled rows out of the
                // fuse's statistics precisely because a dead topic answers
                // 'candidate' for ever -- but a settled row falls through the
                // rest gate once a week, and rewriting it here would make it
                // un-settled, so from the next cycle pass 1 WOULD count it.
                // Each outage would convert a few more dead topics into
                // evidence of an outage, the host would eventually stay
                // tripped on nothing but its own graveyard, and no real
                // candidate on it would ever be investigated again -- with
                // nothing to undo it, because this branch does not dispatch.
                if (self::isSettled($row)) continue;
                $deferred[] = self::deferVerdict($row, ruTrackerChecker::STE_CANT_REACH_TRACKER,
                    ruTrackerChecker::CHKMSG_FUSE . '|' . $host);
                continue;
            }

            self::dispatchChecker($checker, $defaultChecker, $row);
            $checked[] = $row['hash'];
        }
        $uptodate += self::flushVerdicts($deferred);
        return array('checked' => $checked, 'fused' => $fused, 'uptodate' => $uptodate);
    }

    // One buffered fast-path verdict: what to write, the snapshot values it was
    // derived from, and whether it counts toward the cycle's "uptodate" tally.
    // $message null means "leave chk-msg alone"; '' means "clear it".
    static private function deferVerdict($row, $state, $message, $counts = false, $clearDeletion = false)
    {
        return array(
            'hash' => $row['hash'],
            'state' => $state,
            'msg' => $message,
            'counts' => $counts,
            'seenState' => $row['state'],   // parseMulticall() made these ints
            'seenTime' => $row['time'],
            'clearDeletion' => (bool) $clearDeletion,
            'del' => (string) (isset($row['del']) ? $row['del'] : ''),
            'rawMsg' => (string) (isset($row['msg']) ? $row['msg'] : ''),
        );
    }

    /**
     * Write the buffered fast-path verdicts, skipping any row that moved since
     * the cycle-start snapshot. Returns how many "counts" verdicts landed.
     *
     * The four fast paths decide from the snapshot update.php took at the top
     * of the cycle and deliberately ask the daemon nothing per row -- that is
     * the whole point of them, with ~340 rows a cycle. But the snapshot goes
     * stale: a "check" click runs batch_check.php, which takes NO cycle lock
     * (update.php:16 holds the only acquireCycleLock), reads state live, takes
     * the per-hash claim and can leave STE_META_PENDING behind. This pass,
     * still holding the value from the start of the cycle, would write UPTODATE
     * over it -- and the metadata fetch is then invisible until the orphan
     * sweep, because the pump only ever runs for a row that reads META_PENDING.
     *
     * The claim alone would not close this: it covers only the interval while
     * the other worker is still running, and the ordinary case is a click that
     * finished before pass 2 reached the row. Only comparing against a fresh
     * reading does, so the verdicts are buffered and flushed behind ONE scan --
     * per cycle, not per row.
     *
     * A row that has left the "seeding" view since the snapshot is skipped for
     * the same reason: whatever moved it knows more than this pass does.
     */
    static private function flushVerdicts($deferred)
    {
        if (!count($deferred)) return 0;

        $heldClaims = array();
        $claimableVerdicts = array();
        $now = time();

        foreach ($deferred as $verdict) {
            $hash = $verdict['hash'];
            $token = ruTrackerChecker::claimCheckForWorker($hash, $now);
            if (is_string($token)) {
                $heldClaims[$hash] = $token;
                $claimableVerdicts[] = $verdict;
            }
        }

        if (!count($claimableVerdicts)) return 0;

        try {
            $scan = new rXMLRPCRequest(new rXMLRPCCommand("d.multicall", array("seeding",
                getCmd("d.get_hash="),
                getCmd("d.get_custom=") . "chk-state",
                getCmd("d.get_custom=") . "chk-time",
                getCmd("d.get_custom=") . "chk-del",
                getCmd("d.get_custom=") . "chk-msg",
            )));
            $scan->important = false;
            // Nothing is known, so nothing is written. Every verdict in this buffer
            // is derived from the row alone and costs nothing to derive again next
            // cycle; a blind write over a state somebody else set is not undoable.
            if (!$scan->success()) return 0;

            $live = array();
            for ($i = 0; $i + 5 <= count($scan->val); $i += 5) {
                // A fresh reading that will not parse is not comparable with
                // the snapshot, so it is left out of $live -- "not seen".
                $liveState = self::storedCounter($scan->val[$i + 1]);
                $liveTime = self::storedCounter($scan->val[$i + 2]);
                if ($liveState === null || $liveTime === null) {
                    ruTrackerChecker::logDebug('flushVerdicts: a row answered the fresh'
                        . ' scan with a malformed chk-state/chk-time; its verdict is dropped');
                    continue;
                }
                $live[(string) $scan->val[$i]] = array(
                    'state' => $liveState,
                    'time' => $liveTime,
                    'del' => (string) $scan->val[$i + 3],
                    'msg' => (string) $scan->val[$i + 4],
                );
            }

            $applied = 0;
            foreach ($claimableVerdicts as $verdict) {
                $hash = $verdict['hash'];
                if (!isset($live[$hash])) continue;
                if ($live[$hash]['state'] !== $verdict['seenState']
                    || $live[$hash]['time'] !== $verdict['seenTime'])
                    continue;
                // Compare every field this verdict may replace. A worker that
                // finished before our claim can legitimately leave the same
                // state/time but a newer deletion generation or message.
                if (!empty($verdict['clearDeletion'])
                    && ($live[$hash]['del'] !== $verdict['del']
                        || $live[$hash]['msg'] !== $verdict['rawMsg']))
                    continue;
                if ($verdict['msg'] !== null
                    && $live[$hash]['msg'] !== $verdict['rawMsg'])
                    continue;

                $message = $verdict['msg'];
                if (!empty($verdict['clearDeletion']) && $verdict['rawMsg'] !== '')
                    $message = '';
                $clearDeletion = !empty($verdict['clearDeletion']) && $verdict['del'] !== '';
                $stateRes = ruTrackerChecker::setFastVerdict(
                    $hash, $verdict['state'], $message, $clearDeletion);
                if ($stateRes === true && !empty($verdict['counts']))
                    $applied++;
                elseif ($stateRes === null)
                    ruTrackerChecker::logDebug("update: " . $hash
                        . " disappeared while its fast verdict was being saved");
                elseif ($stateRes === false)
                    ruTrackerChecker::logDebug("update: " . $hash
                        . " fast verdict was incomplete or unknown and will be derived again next cycle");
            }
            return $applied;
        } finally {
            foreach ($heldClaims as $hash => $token) {
                ruTrackerChecker::releaseCheckForWorker($hash, $token);
            }
        }
    }

    // Learns topic_id -> forum_id pairs from RuTracker's Atom feed (design
    // spec 4.3, source 2) once per cycle, via a conditional GET so an
    // unchanged feed (the common case inside its ~9h refresh window) costs a
    // 304 instead of a full parse. Feeds forum ids the plugin's own torrents
    // are waiting on straight into chk-forum.
    //
    // A missing/unreachable/unchanged feed is not an error, just a missed
    // optimisation for this cycle -- callers still have the chk-forum cache
    // and the background sweep to fall back on.
    static public function pollFeed($client = null)
    {
        $changed = array();
        if ($client === null) {
            // A plain read for the conditional GET, and nothing else held
            // across the fetch: load()+save() around a 5s request is exactly
            // the shape state.php's own docblock forbids, since a concurrent
            // writer's work would be erased by the stale snapshot.
            $state = RuTrackerState::load('updatepass');
            $etag = (!empty($state['feed_applied'])) ? ($state['feed_etag'] ?? null) : null;
            $client = new Snoopy();
            $client->read_timeout = 5;
            $client->_fp_timeout = 5;
            $client->agent = ruTrackerChecker::USER_AGENT;
            if (is_string($etag) && $etag !== '')
                $client->rawheaders = array('If-None-Match' => $etag);
            @$client->fetchComplex(RuTrackerForumIndex::FEED_URL);

            if ((int) $client->status === 304) return $changed;
            // The new ETag is deliberately NOT committed here. Recording it
            // before the feed has been applied means a cycle that dies (or
            // whose fleet scan fails) in between answers every later 304
            // with "unchanged" while never having used the feed at all --
            // the map it carried would be lost until the tracker's own ETag
            // moves. It is written at the end, once the feed HAS been
            // applied.
            $fresh = ((int) $client->status === 200)
                ? RuTrackerForumIndex::headerEtag($client->headers) : '';
        }
        if ((int) $client->status !== 200 || !is_string($client->results) || $client->results === '')
            return $changed;

        // A schema-readable Atom feed with zero entries has still been
        // applied -- there was nothing in it to apply -- so its ETag may
        // stand for it. Malformed or non-Atom content is unreadable and its
        // ETag is withheld below. A failure to read the fleet also leaves a
        // valid feed unapplied, so that path must not commit the ETag either:
        // doing so would answer every later 304 with "unchanged" for a map
        // this cycle never used.
        $unreadable = false;
        $map = RuTrackerForumIndex::parseFeed($client->results, $unreadable);
        if ($unreadable) {
            ruTrackerChecker::logDebug('pollFeed: the feed could not be read;'
                . ' its ETag is withheld so the next cycle asks again');
            return $changed;
        }
        // The feed is asked about EVERY topic it carries, not only the ones with
        // no forum yet. topicsAwaitingForum()'s own docblock says why the
        // second argument exists -- nothing else in the plugin rewrites
        // chk-forum, so a topic that MOVED keeps a stale id for good, layer 3
        // keeps reading the wrong forum's dump, and with layer 2 confirming
        // "unregistered" for a re-uploaded topic that path ends in a DELETED
        // verdict for a topic that plainly still exists. The crawl already
        // passes it; this caller did not, so the one cheap authoritative
        // source of the mapping was applied to new topics only.
        $current = array();
        $awaiting = count($map) ? RuTrackerForumIndex::topicsAwaitingForum($map, $current) : array();
        if ($awaiting === null) return $changed;

        // A resolution nobody could write down has not been applied either.
        // The same rule the crawl's write-back already follows (forumindex
        // runCrawl(): a topic whose write failed is put back rather than
        // counted), because the consequence is the same -- commit the ETag
        // over a lost write and every later 304 answers "unchanged" for a map
        // that never landed, leaving the tracker-wide crawl to redo work the
        // feed had already done.
        $applied = true;
        foreach ($awaiting as $topic => $hashes) {
            if (!isset($map[$topic])) continue;
            foreach ($hashes as $hash) {
                $was = (string) ($current[$hash] ?? '');
                // Asking about known topics means most rows now carry the
                // answer already. Rewriting the same id every time the feed's
                // ETag moves would be a request per fleet topic per cycle for
                // nothing.
                if ($was === (string) $map[$topic]['forum']) continue;
                // Not (int): parseFeed() already admits this id only through
                // canonicalPositiveInt32(), so the cast normalises nothing --
                // and if that ever stopped being true, a cast would coerce the
                // bad value into a row that LOOKS valid, while passing it on
                // unchanged leaves every reader (forumCorrectionRecord(),
                // writeForumMapping()) free to refuse it.
                $forum = $map[$topic]['forum'];
                // Persist the reason for a recheck BEFORE the mapping. A crash
                // in between leaves an unready obligation that will be retried
                // with the uncommitted feed ETag, never a silent settled row.
                if (!self::rememberForumCorrection($hash, $topic, $forum)) {
                    $applied = false;
                    ruTrackerChecker::logDebug('pollFeed: ' . $hash . ' forum=' . $forum
                        . ' for topic ' . $topic . ' could not be made durable; the feed ETag is withheld');
                    continue;
                }
                $status = RuTrackerForumIndex::writeForumMapping($hash, $topic, $forum, $was, true);
                if ($status === RuTrackerForumIndex::FORUM_WRITE_FAILED) {
                    $applied = false;
                    ruTrackerChecker::logDebug('pollFeed: ' . $hash . ' forum=' . $forum
                        . ' for topic ' . $topic . ' could not be written; the feed ETag is withheld');
                    continue;
                }
                if ($status === RuTrackerForumIndex::FORUM_WRITE_OBSOLETE) {
                    self::clearForumCorrection($hash, array('topic' => $topic, 'forum' => $forum));
                    continue;
                }
                $changed[] = $hash;
                // The other half of the handler's "layer3 forum=N from the
                // chk-forum cache": this is where a cached id came from when
                // the feed, rather than a sweep, resolved it. Bounded by the
                // torrents whose forum is still unknown, never the whole
                // fleet.
                ruTrackerChecker::logDebug('pollFeed: ' . $hash . ' forum=' . (int) $map[$topic]['forum']
                    . ' for topic ' . $topic . ($was === '' ? ', learned from the feed'
                        : ', corrected from ' . $was . ': the topic moved'));
            }
        }

        // Applied: now the ETag may stand for it. update(), not save(), so a
        // concurrent writer's keys survive.
        if ($applied && isset($fresh) && $fresh !== '')
            RuTrackerState::update('updatepass', function ($state) use ($fresh) {
                $state['feed_etag'] = $fresh;
                $state['feed_applied'] = true;
                return $state;
            });
        return $changed;
    }

    // Layer 4's orphan sweep. pump() only
    // ever advances an old torrent whose chk-state is still META_PENDING,
    // addressing the service download by hash off that old torrent's own
    // markers -- never by scanning. If that link breaks (the old torrent was
    // erased, diverted to another state by a label, or its markers were
    // cleared some other way) nothing else ever revisits the stub, so it
    // would sit in "main" forever. Run once per cycle: scan "main" for every
    // item carrying a non-empty chk-meta-old, and for each one read
    // chk-meta-new AND chk-state off the old torrent it names -- CLAIMED
    // only when chk-meta-new is still this item's own hash *and* chk-state
    // is still META_PENDING (pump() owns it, leave it alone), otherwise an
    // orphan. A stale chk-meta-new alone is not enough: e.g. the old torrent
    // gains an ignored label mid-fetch, check.php's run() applies the label
    // check before the META_PENDING short-circuit and writes STE_IGNORED,
    // pump() never runs again, yet chk-meta-new still names this stub --
    // without the state check that stub would be "claimed" forever. An
    // orphan is erased only once its own chk-meta-until deadline has
    // passed, the same deadline pump() itself enforces -- the guard against
    // reaping a fetch that only just began.
    //
    // The claim is bounded by that deadline too, and has to be: only pump()
    // retires a META_PENDING claim, and the scheduler reaches pump() through
    // update.php's "seeding" scan alone. A predecessor the user stops -- or
    // one this plugin's own transaction left stopped -- drops out of that
    // view, so its claim is never retired and an unbounded claim would
    // protect the stub forever, leaving it downloading with nobody watching.
    // Past the deadline the fetch is dead whoever holds it, so the claim
    // stops meaning "in progress" and the orphan branch takes over.
    static public function reapOrphans($now)
    {
        $scan = new rXMLRPCRequest(new rXMLRPCCommand("d.multicall", array("main",
            getCmd("d.get_hash="),
            getCmd("d.get_custom=") . "chk-meta-old",
            getCmd("d.get_custom=") . "chk-meta-until",
        )));
        $scan->important = false;
        if (!$scan->success()) return;

        for ($i = 0; $i + 3 <= count($scan->val); $i += 3) {
            $hash = (string) $scan->val[$i];
            $oldHash = (string) $scan->val[$i + 1];
            if ($oldHash === '') continue; // the user's own torrent, never touched
            $rawUntil = (string) $scan->val[$i + 2];
            $until = RuTrackerRpcValue::canonicalNonnegativeInteger($rawUntil);
            if ($until === null || $until <= 0) {
                ruTrackerChecker::logDebug("reapOrphans: " . $hash
                    . " has a malformed ownership deadline; leaving it untouched");
                continue;
            }

            $claim = new rXMLRPCRequest(array(
                new rXMLRPCCommand(getCmd("d.get_custom"), array($oldHash, "chk-meta-new")),
                new rXMLRPCCommand(getCmd("d.get_custom"), array($oldHash, "chk-state")),
                new rXMLRPCCommand(getCmd("d.get_custom"), array($oldHash, "chk-meta-until")),
            ));
            $claim->important = false;
            // Once: success() issues the request, so asking twice would send
            // the read twice.
            $claimRead = $claim->success();
            // The later of the two deadlines: begin() writes one on each side
            // and adoptStub() refreshes the stub's, so taking the max means a
            // fetch pump() would still be nursing is never reaped out from
            // under it.
            $predecessorUntil = ($claimRead && isset($claim->val[2]))
                ? RuTrackerRpcValue::canonicalNonnegativeInteger($claim->val[2]) : null;
            $claimedUntil = ($predecessorUntil !== null && $predecessorUntil > 0)
                ? max($until, $predecessorUntil) : $until;
            if ($claimRead && isset($claim->val[0], $claim->val[1])
                && (string) $claim->val[0] === $hash
                && RuTrackerRpcValue::canonicalNonnegativeInteger($claim->val[1]) === ruTrackerChecker::STE_META_PENDING
                && $now <= $claimedUntil)
                continue; // still claimed AND still live: pump() owns this item

            if ($now > $claimedUntil) {
                // The fleet row and the predecessor claim are snapshots. A
                // manual check can adopt the stub, or harvest can replace the
                // item at this hash, between either read and this erase. Read
                // the target itself at the destructive boundary and require
                // both its owner and its own deadline to still authorise the
                // action. Failed/changed reads defer; they never authorise an
                // erase of the current occupant.
                $eraseStatus = RuTrackerAtomicOwnership::erase(
                    $hash,
                    array(
                        'chk-meta-old' => $oldHash,
                        'chk-meta-until' => $rawUntil,
                    )
                );
                if ($eraseStatus !== RuTrackerAtomicOwnership::ACTED
                    && $eraseStatus !== RuTrackerAtomicOwnership::SKIPPED)
                    ruTrackerChecker::logDebug("reapOrphans: " . $hash
                        . " cleanup is unconfirmed; retaining it for a later generation-safe retry");
            }
        }
    }

    // How long a settled terminal verdict (DELETED/ABSORBED) rests before the
    // cycle re-verifies it. Its announce keeps failing -- that is what the
    // verdict MEANS -- so layer 1 calls it a candidate forever, and without
    // this gate every dead topic costs a paced probe from the shared per-host
    // budget every hour, for the rest of time. A mistaken verdict still has
    // two ways back: a manual check bypasses this pass entirely, and the
    // recheck itself resurrects the torrent the moment layer 2 answers
    // "registered" (resetDeletion + STE_UPTODATE).
    const SETTLED_RECHECK = 7 * 86400;

    // A verdict that has answered the question and should rest, rather than
    // buy a paced announce probe and a dump fetch every hour until the end of
    // time. DELETED and ABSORBED say so by their state; a topic closed by its
    // moderators (tor_status 1/4/5) is just as final but is stored as the
    // general-purpose STE_NOT_NEED, so it is recognised by the token the
    // handler wrote with it -- a bare NOT_NEED, which many transient paths
    // also write, keeps being checked.
    static private function isSettled($row, $foreign = false)
    {
        if ($row['time'] <= 0) return false;
        if (!$foreign && ($row['state'] === ruTrackerChecker::STE_DELETED
            || $row['state'] === ruTrackerChecker::STE_ABSORBED)) return true;
        if ($row['state'] !== ruTrackerChecker::STE_NOT_NEED) return false;
        $token = explode('|', (string) $row['msg'], 2);
        if ($token[0] === ruTrackerChecker::CHKMSG_SUPERSEDED) return true;
        return !$foreign && $token[0] === ruTrackerChecker::CHKMSG_TOPIC_STATUS;
    }

    // Columns of the sweep's own fleet scan: hash, ownership marker, record.
    const SWEEP_COLUMNS = 4;

    /**
     * Finish or diagnose a replacement transaction that never closed.
     *
     * The transaction is: stage the copy (marker + record written in the load
     * command list), erase the predecessor, activate, clear both keys. A
     * process that dies after the erase and before the clear leaves a torrent
     * nothing else in this plugin can reach -- it is stopped and closed, so it
     * is outside the "seeding" view the cycle scans, and its predecessor is
     * gone, so no later check reaches createTorrent()'s adoption path. That is
     * not hypothetical: a container auto-update did exactly this on
     * 2026-08-16 and left a 6.58 GiB download sitting at 0 %.
     *
     * Runs on "main" for that reason, before the cycle's own work, under the
     * cycle lock update.php already holds.
     */
    static public function sweepReplacements($now)
    {
        $scan = new rXMLRPCRequest(new rXMLRPCCommand("d.multicall", array("main",
            getCmd("d.get_hash="),
            getCmd("d.get_custom=") . ruTrackerChecker::REPLACEMENT_MARKER_KEY,
            getCmd("d.get_custom=") . ruTrackerChecker::INHERIT_KEY,
            getCmd("d.get_custom=") . ruTrackerChecker::REPLACING_KEY,
        )));
        $scan->important = false;
        if (!$scan->success()) return;

        for ($i = 0; $i + self::SWEEP_COLUMNS <= count($scan->val); $i += self::SWEEP_COLUMNS) {
            $hash = (string) $scan->val[$i];
            if ((string) $scan->val[$i + 1] === '') {
                // No staged copy was ever marked -- but this row may be a
                // PREDECESSOR whose transaction died before it staged
                // anything, in which case it is stopped and closed and
                // outside every other scan this plugin makes.
                if ((string) $scan->val[$i + 3] !== '')
                    self::sweepReplacingRow($hash, (string) $scan->val[$i + 3], $now);
                continue;   // otherwise unmarked: foreign, hands off
            }
            $rawMarker = (string) $scan->val[$i + 1];
            $rawRecord = (string) $scan->val[$i + 2];
			if (!ruTrackerChecker::isPluginReplacementMarker($rawMarker)) {
				ruTrackerChecker::logDebug("sweepReplacements: " . $hash
					. " carries a non-plugin replacement marker; leaving it untouched");
				continue;
			}
            $record = ruTrackerChecker::decodeInheritance($rawRecord);
            // A transaction younger than the lock window may simply be in
            // flight -- batch_check.php takes no cycle lock, so "no other
            // cycle is running" is not the same as "nobody is mid-replacement".
            if ($record !== null && ($now - $record['staged']) <= ruTrackerChecker::MAX_LOCK_TIME) continue;
            self::sweepMarkedRow($hash, $rawMarker, $rawRecord, $now);
        }
    }

    // Clear the predecessor's chk-replacing key only if its current stored value
    // matches the expected generation, preventing a sweep from erasing a newer transaction's key.
    static private function clearReplacingGeneration($hash, $expected, $expectedValues = array())
    {
        $status = RuTrackerAtomicOwnership::clearCustoms(
            $hash,
            array(ruTrackerChecker::REPLACING_KEY => (string) $expected),
            array(ruTrackerChecker::REPLACING_KEY),
            $expectedValues
        );
        if ($status === RuTrackerAtomicOwnership::UNKNOWN
            || $status === RuTrackerAtomicOwnership::UNCONFIRMED)
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                . " chk-replacing clear is unconfirmed; retaining the recovery generation");
        return $status;
    }

    // UNKNOWN may have landed the clear before its reply was lost; SKIPPED may
    // mean either generation drift or only a state/open interleaving. Read back
    // only to decide whether cleanup may continue; never repeat the mutation
    // outside the atomic helper.
    static private function replacingGenerationRelinquished($hash, $expected, $status)
    {
        if ($status === RuTrackerAtomicOwnership::ACTED) return true;
        // With state/open predicates in the same clear branch, SKIPPED can
        // mean either that ownership moved (settled for this generation) or
        // that only run state changed while chk-replacing still matches. The
        // latter must retain the staged recovery handle. UNKNOWN may likewise
        // mean that the clear landed before its reply was lost.
        if ($status !== RuTrackerAtomicOwnership::SKIPPED
            && $status !== RuTrackerAtomicOwnership::UNKNOWN)
            return false;

        $read = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.get_custom"),
            array($hash, ruTrackerChecker::REPLACING_KEY)));
        $read->important = false;
        if (!$read->success() || !isset($read->val[0])) return false;
        return (string) $read->val[0] !== (string) $expected;
    }

    static private function reconcileObsoleteCleanup($oldHash, $newHash, $marker, $replacementRecord, $oldExists)
    {
        if ($oldExists === true) {
            $status = erasedataCancelObsoleteCleanupGeneration(
                $oldHash, $newHash, $marker, $replacementRecord);
            return $status === ERASEDATA_CLEANUP_NONE || $status === ERASEDATA_CLEANUP_READY;
        }
        if ($oldExists !== false) return false;

        $status = erasedataRecoverObsoleteCleanup($oldHash, $newHash, $marker, $replacementRecord);
        if ($status === ERASEDATA_CLEANUP_READY) {
            if (!erasedataKickCollector($oldHash))
                ruTrackerChecker::logDebug("sweepReplacements: cleanup collector kick for " . $oldHash
                    . " was not confirmed; the scheduled collector will retry it");
            return true;
        }
        return $status === ERASEDATA_CLEANUP_NONE;
    }

    // A predecessor that createTorrent() stopped and closed for a replacement
    // that never got as far as staging anything. Nothing else can find it: it
    // is outside update.php's "seeding" view, it carries no replacement
    // marker (that only ever goes on the staged copy) and no chk-meta-old
    // (that only ever goes on a metadata stub). Its own recovery key,
    // written in the same multicall that stopped it, is the only handle.
    static private function sweepReplacingRow($hash, $encoded, $now)
    {
        $token = ruTrackerChecker::claimCheckForWorker($hash, $now);
        if (!is_string($token)) {
            if ($token === false)
                ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                    . " is claimed by a live check; skipping sweep");
            return;
        }

        try {
            // The hash field of this record names the SUCCESSOR, not a predecessor.
            $record = ruTrackerChecker::decodeInheritance($encoded);
            if ($record === null) {
                ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                    . " carries an unreadable replacement record; retaining the recovery key"
                    . " because no run policy can be proved");
                return;
            }
            $canonicalRecord = RuTrackerReplacementRecord::encode(
                $record['old'],
                $record['run']['started'],
                $record['run']['open'],
                $record['staged']
            );
            if ($canonicalRecord !== $encoded) {
                ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                    . " carries a non-canonical replacement recovery record; retaining both generations");
                return;
            }
            // Younger than the lock window: the transaction may simply be in
            // flight. batch_check.php takes no cycle lock, so "no other cycle is
            // running" is not the same as "nobody is mid-replacement".
            if (($now - $record['staged']) <= ruTrackerChecker::MAX_LOCK_TIME) return;

            // The value above came from the fleet scan at the top of this sweep,
            // and everything below is irreversible: d.open/d.start on the user's
            // torrent, then blanking the recovery key. batch_check.php takes no
            // cycle lock, so a fresh replacement can have started in between -- and
            // acting on the OLD record would restart a torrent that live
            // transaction has just deliberately stopped, and then clear the key it
            // is relying on. sweepMarkedRow() re-reads before it acts for exactly
            // this reason.
            $fresh = new rXMLRPCRequest(array(
                new rXMLRPCCommand(getCmd("d.get_custom"),
                    array($hash, ruTrackerChecker::REPLACING_KEY)),
                new rXMLRPCCommand(getCmd("d.get_state"), $hash),
                new rXMLRPCCommand(getCmd("d.is_open"), $hash),
            ));
            $fresh->important = false;
            if (!$fresh->success() || !isset($fresh->val[0], $fresh->val[1], $fresh->val[2])
                || (string) $fresh->val[0] !== (string) $encoded) {
                ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                    . " moved under the sweep; leaving it to whoever is holding it now");
                return;
            }
            $observedState = RuTrackerRpcValue::canonicalNonnegativeInteger($fresh->val[1]);
            $observedOpen = RuTrackerRpcValue::canonicalNonnegativeInteger($fresh->val[2]);
            if (!in_array($observedState, array(0, 1), true)
                || !in_array($observedOpen, array(0, 1), true))
                return;

            $successorHash = $record['old'];
            $isOurSuccessor = false;
            $succProbe = new rXMLRPCRequest(array(
                new rXMLRPCCommand(getCmd("d.hash"), $successorHash),
                new rXMLRPCCommand(getCmd("d.get_custom"),
                    array($successorHash, ruTrackerChecker::REPLACEMENT_MARKER_KEY)),
                new rXMLRPCCommand(getCmd("d.get_custom"),
                    array($successorHash, ruTrackerChecker::INHERIT_KEY)),
            ));
            $succProbe->important = false;
            if (!$succProbe->success() || !isset($succProbe->val[0], $succProbe->val[1], $succProbe->val[2])) {
                // A missing d.hash faults the aggregate request. Confirm only
                // absence separately; present/transport-unknown cannot combine
                // coherent ownership facts and therefore authorizes no action.
                $successorExists = ruTrackerChecker::torrentExists($successorHash);
                if ($successorExists !== false) return;
            } else {
                if (strcasecmp((string) $succProbe->val[0], $successorHash) !== 0) return;
                $succMarker = (string) $succProbe->val[1];
                $rawSuccRecord = (string) $succProbe->val[2];
                $succShaped = false;
                $succRecord = ruTrackerChecker::decodeInheritance($rawSuccRecord, $succShaped);
                if (ruTrackerChecker::isPluginReplacementMarker($succMarker)) {
                    // Record-shaped bytes that will not decode are as unproved
                    // as ones that decode non-canonically: clear nothing.
                    if ($succRecord === null && $succShaped) {
                        ruTrackerChecker::logDebug("sweepReplacements: " . $successorHash
                            . " carries an unreadable successor recovery record; retaining both generations");
                        return;
                    }
                    if ($succRecord !== null) {
                        $canonicalSuccRecord = RuTrackerReplacementRecord::encode(
                            $succRecord['old'],
                            $succRecord['run']['started'],
                            $succRecord['run']['open'],
                            $succRecord['staged']
                        );
                        if ($canonicalSuccRecord !== $rawSuccRecord) {
                            ruTrackerChecker::logDebug("sweepReplacements: " . $successorHash
                                . " carries a non-canonical successor recovery record; retaining both generations");
                            return;
                        }
                    }
                    $expectedSuccRecord = RuTrackerReplacementRecord::encode(
                        $hash,
                        $record['run']['started'],
                        $record['run']['open'],
                        $record['staged']
                    );
                    if ($rawSuccRecord === $expectedSuccRecord) $isOurSuccessor = true;
                }
            }

            if ($isOurSuccessor) {
                // It DID stage: the marked-row branch above owns that transaction
                // and this key is a leftover from a rollback that could not finish.
                if (!self::reconcileObsoleteCleanup($hash, $successorHash, $succMarker,
                    (string) $succProbe->val[2], true)) return;
                self::clearReplacingGeneration($hash, $encoded,
                    array('state' => $observedState, 'is_open' => $observedOpen));
                return;
            }

            if (!$record['run']['started'] && !$record['run']['open']) {
                // Stopped and closed before the transaction began, so being
                // stopped and closed now is not a strand -- it is where the user
                // left it.
                self::clearReplacingGeneration($hash, $encoded,
                    array('state' => $observedState, 'is_open' => $observedOpen));
                return;
            }
            $alreadySatisfied = $record['run']['started']
                ? $observedState === 1 : $observedOpen === 1;
            if ($alreadySatisfied || $observedState !== 0 || $observedOpen !== 0) {
                // The crash signature is gone. Preserve the current run state
                // (including a later UI pause/start) and retire only this exact
                // stale recovery generation.
                self::clearReplacingGeneration($hash, $encoded,
                    array('state' => $observedState, 'is_open' => $observedOpen));
                return;
            }
            $status = RuTrackerAtomicOwnership::runState(
                $hash,
                array(ruTrackerChecker::REPLACING_KEY => (string) $encoded),
                $record['run']['started'],
                array('state' => $observedState, 'is_open' => $observedOpen),
                array(ruTrackerChecker::REPLACING_KEY => '')
            );
            if ($status === RuTrackerAtomicOwnership::ACTED) {
                ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                    . " was stopped for a replacement that never staged anything; restored to "
                    . ($record['run']['started'] ? "started" : "open") . " so its own check can try again");
                return;
            }
            if ($status === RuTrackerAtomicOwnership::SKIPPED) {
                return;
            }
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                . " atomic restore for a replacement that never staged is " . $status
                . "; keeping the exact recovery generation retryable");
        } finally {
            ruTrackerChecker::releaseCheckForWorker($hash, $token);
        }
    }

    // A decoded marked row names its predecessor, so claim that predecessor
    // before the generation reread and retain ownership through every probe,
    // restore/discard/activation and final clear. A record-less legacy row has
    // no predecessor identity to claim and is handled by the same conservative
    // inspection, whose null-record branches never restore a predecessor.
    static private function sweepMarkedRow($hash, $rawMarker, $rawRecord, $now)
    {
		if (!ruTrackerChecker::isPluginReplacementMarker($rawMarker)) return;
        $record = ruTrackerChecker::decodeInheritance($rawRecord);
		if ($rawRecord !== '' && $record === null) {
			ruTrackerChecker::logDebug("sweepReplacements: " . $hash
				. " carries an unreadable nonempty replacement record; retaining its recovery keys");
			return;
		}
        if ($record !== null) {
            $canonicalRecord = RuTrackerReplacementRecord::encode(
                $record['old'],
                $record['run']['started'],
                $record['run']['open'],
                $record['staged']
            );
            if ($canonicalRecord !== $rawRecord) {
                ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                    . " carries a non-canonical replacement record; retaining its recovery keys");
                return;
            }
        }
        if ($record === null) {
            self::inspectMarkedRow($hash, $rawMarker, $rawRecord, $now, null);
            return;
        }

        $predecessor = $record['old'];
        $token = ruTrackerChecker::claimCheckForWorker($predecessor, $now);
        if (!is_string($token)) {
            if ($token === false)
                ruTrackerChecker::logDebug("sweepReplacements: " . $hash . " predecessor "
                    . $predecessor . " is claimed by a live check; skipping marked-row sweep");
            return;
        }
        try {
            self::inspectMarkedRow($hash, $rawMarker, $rawRecord, $now, $record);
        } finally {
            ruTrackerChecker::releaseCheckForWorker($predecessor, $token);
        }
    }

    // One claimed (or predecessor-less legacy) candidate. Reads first, decides
    // second, and writes at most once.
    static private function inspectMarkedRow($hash, $rawMarker, $rawRecord, $now, $record)
    {
        $probe = new rXMLRPCRequest(array(
            new rXMLRPCCommand(getCmd("d.get_state"), $hash),
            new rXMLRPCCommand(getCmd("d.is_open"), $hash),
            new rXMLRPCCommand(getCmd("d.get_chunks_hashed"), $hash),
            new rXMLRPCCommand(getCmd("d.get_completed_bytes"), $hash),
            new rXMLRPCCommand(getCmd("d.get_complete"), $hash),
            new rXMLRPCCommand(getCmd("d.get_message"), $hash),
            new rXMLRPCCommand(getCmd("d.get_custom"), array($hash, "chk-stime")),
            new rXMLRPCCommand(getCmd("d.get_custom"), array($hash, "chk-state")),
            // The marker and the record again, re-read HERE. Everything this
            // function goes on to do is irreversible -- it erases the staged
            // copy, or starts a download -- and the values it was handed came
            // from a fleet scan several round trips ago. batch_check.php takes
            // no cycle lock, so a concurrent createTorrent() can have finished
            // that transaction and staged a new one at this very hash in
            // between; acting on the old reading would then erase a live
            // transaction's only marker.
            new rXMLRPCCommand(getCmd("d.get_custom"), array($hash, ruTrackerChecker::REPLACEMENT_MARKER_KEY)),
            new rXMLRPCCommand(getCmd("d.get_custom"), array($hash, ruTrackerChecker::INHERIT_KEY)),
        ));
        $probe->important = false;
        // A faulting member injects its faultString into the flat value list
        // rather than shortening it, so the last slot must be present before
        // any of them is trusted.
        if (!$probe->success() || !isset($probe->val[9])) {
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash . " could not be read, deferring to the next cycle");
            return;
        }
        // Marker nonce and raw inheritance bytes are the generation. Decoded
        // predecessor/time equality is insufficient: a same-second retry can
        // change the nonce or run token while preserving both decoded fields.
        if ((string) $probe->val[8] !== $rawMarker
            || (string) $probe->val[9] !== $rawRecord) {
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                . " changed between the fleet scan and now; leaving it to whoever owns it");
            return;
        }
        // ...and it must still be old enough to be a strand rather than work
        // in flight, judged on the value just read.
        if ($record !== null && ($now - $record['staged']) <= ruTrackerChecker::MAX_LOCK_TIME) return;
        $val = $probe->val;

        if ($record === null) {
            // A marker alone is not ownership. The predecessor and run policy
            // are unproved, so even a diagnostic state write could land on a
            // same-hash torrent re-added after this probe. Log the legacy row,
            // but never write, clear, erase, open or start from this branch.
            // The canonical reads below are deliberately NOT hoisted above
            // this: the branch's only effect is the line, and the readings an
            // operator most needs it for are precisely the ones no integer
            // parse can make sense of.
            $stime = RuTrackerRpcValue::canonicalNonnegativeInteger($val[6]);
            if ($stime === null || $stime <= 0 || ($now - $stime) <= ruTrackerChecker::MAX_LOCK_TIME) return;
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                . " carries a replacement marker with no record: the predecessor and its run state"
                . " cannot be recovered, so it is logged and left untouched"
                . " (state=" . intval($val[0]) . " open=" . intval($val[1])
                . " hashed=" . intval($val[2]) . " bytes=" . intval($val[3]) . ")");
            return;
        }

        // Does the row already answer what its record asked for? That one
        // question separates a finished transaction from an unfinished one,
        // and it is asked of the RECORD, never of a label. Every RPC integer
        // the branches below act on is read canonically HERE, ahead of all of
        // them: an unparsable reading authorises nothing at all, and the exact
        // state/open projection guards every ownership-sensitive branch.
        // d.get_completed_bytes (slot 3) is deliberately not among them. It is
        // the one column with no int32 bound, and a canonical PHP-integer
        // roundtrip refuses everything past PHP_INT_MAX -- on a 32-bit build
        // every torrent past 2 GiB, all of it WELL-FORMED input the sweep
        // would then never finish for. It keeps the predecessor's intval() at
        // its single use site below, beside the two genuinely int32 counters
        // that carry the guard.
        foreach (array(0, 1, 2, 4) as $slot) {
            $val[$slot] = RuTrackerRpcValue::canonicalNonnegativeInt32($val[$slot]);
            if ($val[$slot] === null) return;
        }
        if (!in_array($val[0], array(0, 1), true) || !in_array($val[1], array(0, 1), true)) return;
        $observedState = $val[0];
        $observedOpen = $val[1];

        // Cleanup owns the same exact marker/record generation. Reconcile it
        // before any branch can clear keys, revive the predecessor, discard the
        // staged successor, or activate the committed replacement.
        $exists = ruTrackerChecker::torrentExists($record['old']);
        if (!self::reconcileObsoleteCleanup($record['old'], $hash, $rawMarker,
            $rawRecord, $exists)) return;

        $live = $observedState !== 0 || $observedOpen !== 0;
        $satisfied = $record['run']['started']
            ? $observedState === 1
            : ($record['run']['open'] ? $observedOpen === 1 : false);

        // A live row whose record is NOT yet satisfied is this plugin's own
        // half-finished activation: the keys are still here, so nothing ever
        // confirmed it. Retiring that would leave the replacement paused for
        // good -- precisely the outcome this sweep exists to prevent -- and
        // the "opened since staged" guard below would swallow it too, since
        // opening a complete download is what puts chunks on its counters.
        $resuming = $live && $record !== null && !$satisfied
            && ($record['run']['started'] || $record['run']['open']);

        // A marked row that is running is a finished replacement whose final
        // clear was lost -- or one a human started by hand. Either way its run
        // state is somebody's decision and the keys are simply retired.
        if ($live && !$resuming) {
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                . " is already running, retiring its replacement keys");
            ruTrackerChecker::clearReplacementRecord($hash, $rawMarker, $rawRecord,
                array('state' => $observedState, 'is_open' => $observedOpen));
            return;
        }

        // The recorded predecessor is read, and only ever read: it tells the
        // two halves of the transaction apart.
        if ($exists === true) {
            // The commit never happened. The staged copy is still adoptable by
            // a normal check of the predecessor, so nothing here may touch it
            // -- but that check may need help arriving: see below.
            self::reviveStrandedPredecessor(
                $hash,
                $record,
                (string) $probe->val[8],
                (string) $probe->val[9]
            );
            return;
        }
        self::finishStrandedReplacement($hash, $record, $val, $resuming);
    }

    // A crash between createTorrent()'s stop/close multicall and its commit
    // erase leaves the predecessor stopped AND closed -- outside the
    // "seeding" view the hourly cycle scans -- so "the predecessor's own
    // check redoes the replacement" never happens by itself. When the record
    // says it was running or open at staging and it now measures exactly
    // stopped+closed (the crash's own signature; any other reading is
    // somebody's later decision), restore the recorded run state so the
    // torrent re-enters the view and its own check adopts the staged copy.
    //
    // Write-once, keyed on the predecessor itself (chk-revived): a user who
    // stops the torrent again after one revival has made a decision, and the
    // sweep must not fight them for it. The flag is written only after the
    // revival is MEASURED to have taken -- an accepted-but-ineffective open
    // must stay retryable, or one flaky ack recreates the very strand this
    // exists to fix.
    //
    // And once the predecessor is up, the staged copy is DISCARDED. It was
    // only ever the marker by which a stranded transaction could be found at
    // all; a reachable predecessor carries everything needed to redo the
    // replacement from scratch, while the copy sitting at the successor hash
    // actively blocks that redo -- RuTrackerMetaFetch::begin() finds an item
    // already at the hash it means to fetch, sees the replacement marker, and
    // hands the transaction back to this sweep, which hands it back to the
    // predecessor's check. Neither would ever act, and the torrent would
    // never be updated again while still buying an announce probe and a dump
    // fetch every hour. Discarding costs one repeated magnet fetch in a case
    // that only arises after a crash mid-transaction.
    static private function reviveStrandedPredecessor($hash, $record, $expectedMarker, $expectedRecord)
    {
        $old = $record['old'];
        $wantStarted = $record['run']['started'];
        $expectedReplacing = RuTrackerReplacementRecord::encode(
            $hash,
            $record['run']['started'],
            $record['run']['open'],
            $record['staged']
        );
        if (!$wantStarted && !$record['run']['open']) {
            // The predecessor was stopped and closed BEFORE the transaction
            // began, so there is nothing to revive -- but the staged copy must
            // still go, for the same reason it goes on the branches below: it
            // sits at the hash the redo means to fetch, and begin() answers a
            // marker there by handing the transaction back to this sweep. Left
            // in place, the two defer to each other for ever and the torrent
            // is never updated again.
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                . " was staged for " . $old . ", which is still in the client and was already"
                . " stopped: discarding the staged copy so its own check can redo the replacement");
            $probe = new rXMLRPCRequest(array(
                new rXMLRPCCommand(getCmd("d.get_state"), $old),
                new rXMLRPCCommand(getCmd("d.is_open"), $old),
                new rXMLRPCCommand(getCmd("d.get_custom"), array($old, ruTrackerChecker::REPLACING_KEY)),
            ));
            $probe->important = false;
            if (!$probe->success() || !isset($probe->val[0], $probe->val[1], $probe->val[2]))
                return;
            $state = RuTrackerRpcValue::canonicalNonnegativeInteger($probe->val[0]);
            $open = RuTrackerRpcValue::canonicalNonnegativeInteger($probe->val[1]);
            if (!in_array($state, array(0, 1), true) || !in_array($open, array(0, 1), true))
                return;
            if ((string) $probe->val[2] !== $expectedReplacing) {
                self::discardStaged($hash, $expectedMarker, $expectedRecord);
                return;
            }
            $clearStatus = self::clearReplacingGeneration($old, $expectedReplacing,
                array('state' => $state, 'is_open' => $open));
            if (self::replacingGenerationRelinquished($old, $expectedReplacing, $clearStatus))
                self::discardStaged($hash, $expectedMarker, $expectedRecord);
            return;
        }

        $reviveStatus = RuTrackerAtomicOwnership::revivePredecessor(
            $old,
            $expectedReplacing,
            $record['run'],
            $record['staged']
        );

        if ($reviveStatus === RuTrackerAtomicOwnership::ACTED) {
            ruTrackerChecker::logDebug("sweepReplacements: revived " . $old . " ("
                . ($wantStarted ? "started" : "open") . ", as its record says) so its own check"
                . " can redo the replacement its dead cycle staged at " . $hash);
            self::discardStaged($hash, $expectedMarker, $expectedRecord);
            return;
        }
        if ($reviveStatus === RuTrackerAtomicOwnership::SPENT) {
            ruTrackerChecker::logDebug("sweepReplacements: " . $old
                . " was revived once already for this same transaction and is stopped again:"
                . " that is a decision, not a strand; closing only this recovery generation");
            $clearStatus = self::clearReplacingGeneration($old, $expectedReplacing,
                array('state' => 0, 'is_open' => 0));
            if (self::replacingGenerationRelinquished($old, $expectedReplacing, $clearStatus))
                self::discardStaged($hash, $expectedMarker, $expectedRecord);
            return;
        }
        if ($reviveStatus === RuTrackerAtomicOwnership::SKIPPED) {
            $probe = new rXMLRPCRequest(array(
                new rXMLRPCCommand(getCmd("d.get_state"), $old),
                new rXMLRPCCommand(getCmd("d.is_open"), $old),
                new rXMLRPCCommand(getCmd("d.get_custom"), array($old, "chk-revived")),
                new rXMLRPCCommand(getCmd("d.get_custom"), array($old, ruTrackerChecker::REPLACING_KEY)),
            ));
            $probe->important = false;
            if (!$probe->success() || !isset($probe->val[0], $probe->val[1], $probe->val[2], $probe->val[3]))
                return;
            $state = RuTrackerRpcValue::canonicalNonnegativeInteger($probe->val[0]);
            $open = RuTrackerRpcValue::canonicalNonnegativeInteger($probe->val[1]);
            if (!in_array($state, array(0, 1), true) || !in_array($open, array(0, 1), true))
                return;
            $activeReplacing = (string) $probe->val[3];
            if ($activeReplacing !== $expectedReplacing) {
                // Empty/different ownership means this transaction has no
                // authority over predecessor run state. Its own exact staged
                // generation can still be removed safely.
                self::discardStaged($hash, $expectedMarker, $expectedRecord);
                return;
            }
            $satisfied = $wantStarted ? $state === 1 : $open === 1;
            $spent = $state === 0 && $open === 0
                && (string) $probe->val[2] === (string) $record['staged'];
            if ($satisfied || $spent || $state !== 0 || $open !== 0) {
                $clearStatus = self::clearReplacingGeneration($old, $expectedReplacing,
                    array('state' => $state, 'is_open' => $open));
                if (self::replacingGenerationRelinquished($old, $expectedReplacing, $clearStatus))
                    self::discardStaged($hash, $expectedMarker, $expectedRecord);
            }
            return;
        }
        if ($reviveStatus === RuTrackerAtomicOwnership::UNCONFIRMED) {
            ruTrackerChecker::logDebug("sweepReplacements: " . $old
                . " did not come back " . ($wantStarted ? "started" : "open")
                . " when revived for its stranded replacement " . $hash
                . ", keeping it retryable");
            return;
        }
        ruTrackerChecker::logDebug("sweepReplacements: " . $old
            . " revival outcome for stranded replacement " . $hash
            . " is unknown; retaining both exact recovery generations");
    }

    // Erases a staged copy whose predecessor is up. The predecessor revival
    // took several round trips, so the exact marker+record generation proved
    // by sweepMarkedRow() must still be present now. A concurrent replacement
    // may stage a fresh generation at the same hash; erasing that would lose
    // its only durable recovery handle.
    static private function discardStaged($hash, $expectedMarker, $expectedRecord)
    {
        $status = RuTrackerAtomicOwnership::erase(
            $hash,
            array(
                ruTrackerChecker::REPLACEMENT_MARKER_KEY => (string) $expectedMarker,
                ruTrackerChecker::INHERIT_KEY => (string) $expectedRecord,
            ),
            array(
                'state' => 0,
                'is_open' => 0,
            )
        );
        if ($status === RuTrackerAtomicOwnership::ACTED) {
            return;
        }
        if ($status === RuTrackerAtomicOwnership::SKIPPED) {
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                . " changed before its staged copy could be discarded; leaving the current generation alone");
            return;
        }
        ruTrackerChecker::logDebug("sweepReplacements: " . $hash
            . " staged cleanup is " . $status
            . "; retaining the exact generation for a later atomic retry");
    }

    // Reached only when the marker is set, the record decoded, the transaction
    // aged past the lock window, the copy stopped AND closed, and the recorded
    // predecessor provably gone.
    static private function finishStrandedReplacement($hash, $record, $val, $resuming = false)
    {
        $marker = (string) $val[8];
        $rawRecord = (string) $val[9];
        if (!$record['run']['started'] && !$record['run']['open']) {
            // The predecessor was stopped, so leaving the replacement stopped
            // IS the finished outcome -- createTorrent()'s never-resurrect
            // rule, now surviving the erase that used to destroy its input.
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                . " inherited a stopped predecessor, so the transaction is complete as it stands");
            ruTrackerChecker::clearReplacementRecord($hash, $marker, $rawRecord,
                array('state' => $val[0], 'is_open' => $val[1]));
            return;
        }
        if ((string) $val[5] !== '') {
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                . " will not be started blind, the daemon already reports: " . (string) $val[5]);
            return;
        }
        // d.get_completed_bytes has no int32 bound, so it is judged in the
        // STRING domain: exactly canonical zero is the only reading that means
        // "never opened", which accepts an arbitrarily wide well-formed counter
        // (a 32-bit build must not refuse every torrent past 2 GiB) without an
        // int32 parser. intval() answered 0 for everything it could not read,
        // and 0 is the fail-OPEN direction: a wrong "never opened" starts a
        // download somebody deliberately left stopped.
        // int or decimal string only: (string) 0.0 and (string) false are "0"
        // and "", which would hide a reading that is not a count at all.
        $bytes = (is_int($val[3]) || is_string($val[3])) ? (string) $val[3] : '';
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $bytes) !== 1) {
            // DEFER, exactly like slots 0/1/2/4 do for the same reason
            // (inspectMarkedRow()): a reading nobody made authorises nothing,
            // and clearing the keys here is irreversible -- they are the only
            // durable handle the next cycle has on this transaction, so the
            // replacement would stay stopped for good with nothing able to
            // finish it. A faulting multicall member injects its faultString
            // into the flat value list rather than shortening it, so a
            // transient fault in THIS slot used to give up automatic
            // activation permanently while the same fault one slot earlier was
            // simply retried.
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                . " reports a completed-byte counter that does not read as a count, which is no"
                . " evidence either way about whether it was ever opened; its exact ownership"
                . " keys are kept for the next cycle");
            return;
        }
        if (!$resuming && ($val[2] > 0 || $bytes !== '0' || $val[4] !== 0)) {
            // It was opened at some point and is stopped now: that is a
            // decision somebody made after the crash, not one to undo. The
            // exception is this sweep's own half-done activation ($resuming),
            // whose d.open is exactly what put those counters there.
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                . " has been opened since it was staged, leaving its run state alone");
            ruTrackerChecker::clearReplacementRecord($hash, $marker, $rawRecord,
                array('state' => $val[0], 'is_open' => $val[1]));
            return;
        }
        $status = RuTrackerAtomicOwnership::runState(
            $hash,
            array(
                ruTrackerChecker::REPLACEMENT_MARKER_KEY => $marker,
                ruTrackerChecker::INHERIT_KEY => $rawRecord,
            ),
            $record['run']['started'],
            array(
                'state' => $val[0],
                'is_open' => $val[1],
            ),
            array(
                ruTrackerChecker::INHERIT_KEY => '',
                ruTrackerChecker::REPLACEMENT_MARKER_KEY => '',
            )
        );
        if ($status === RuTrackerAtomicOwnership::ACTED) {
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                . " finished a replacement its own cycle never activated"
                . " (wanted " . ($record['run']['started'] ? "started" : "open") . ")");
            return;
        }
        if ($status === RuTrackerAtomicOwnership::SKIPPED) {
            return;
        }
        ruTrackerChecker::logDebug("sweepReplacements: " . $hash
            . " atomic activation is " . $status
            . "; keeping its exact ownership keys for the next cycle");
    }
}
