<?php

require_once("state.php");
require_once("detector.php");
require_once("forumindex.php");
require_once("announce.php");

// The scheduler's per-cycle glue: turns update.php's raw d.multicall values
// into per-torrent rows (parseMulticall), decides per row whether the local
// detector's verdict needs the expensive checker or can be resolved for free
// (run()), and keeps the topic -> forum_id map fresh once per cycle
// (pollFeed()). update.php itself stays a thin XMLRPC-building driver; every
// branch worth testing lives here instead (design doc section 5).
class RuTrackerUpdatePass
{
    const COLUMNS = 9;

    // Test seam: run()'s production default dispatches straight to
    // ruTrackerChecker::run(); tests override this via
    // strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', ...) to
    // capture dispatches without loading a real tracker handler. null means
    // "use the production default".
    private static $checker = null;

    // Splits update.php's flat d.multicall values into one associative row
    // per torrent, 9 values in (see update.php). Originally 11: chk-topic,
    // chk-forum, chk-meta-new and chk-meta-until were fetched but never read
    // by run() (pollFeed()/reapOrphans() resolve those for themselves via
    // their own d.multicall scans), so those four slots were replaced by the
    // two run()'s alive path actually needs -- chk-del and chk-msg -- to
    // reset a stale deletion counter without a per-torrent XMLRPC round
    // trip. Drops a trailing partial group the same way parseTrackerBlob
    // drops a malformed tracker row -- unknowable rather than guessed at.
    static public function parseMulticall($values)
    {
        $rows = array();
        for ($i = 0; $i + self::COLUMNS <= count($values); $i += self::COLUMNS) {
            $rows[] = array(
                'hash' => $values[$i],
                'state' => intval($values[$i + 1]),
                'time' => intval($values[$i + 2]),
                'stime' => intval($values[$i + 3]),
                'label' => $values[$i + 4],
                'message' => $values[$i + 5],
                'del' => $values[$i + 6],
                'msg' => $values[$i + 7],
                'trackers' => RuTrackerDetector::parseTrackerBlob($values[$i + 8]),
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
        foreach ((array) $trackers as $row)
            if (preg_match(RuTrackerDetector::TRACKER_PATTERN, (string) ($row['url'] ?? '')))
                return (string) @parse_url($row['url'], PHP_URL_HOST);
        return '';
    }

    static public function run($rows)
    {
        global $rutrackerFuseShare, $rutrackerFuseFloor;
        $share = isset($rutrackerFuseShare) ? (float) $rutrackerFuseShare : 0.2;
        $floor = isset($rutrackerFuseFloor) ? (int) $rutrackerFuseFloor : 3;
        $checker = self::$checker !== null ? self::$checker
            : function ($hash, $row) {
                ruTrackerChecker::run($hash, $row['state'], $row['time'], $row['stime'], $row['label']);
            };

        RuTrackerAnnounce::resetCycle();

        // Pass 1: classify every row and tally per-host candidate share for
        // the fuse. A META_PENDING row is classified too (it still carries a
        // real tracker signal, worth counting toward its host's health) even
        // though pass 2 dispatches it unconditionally regardless of verdict.
        $verdicts = array();
        $hosts = array();
        $hostStats = array();
        foreach ($rows as $index => $row) {
            $verdict = RuTrackerDetector::classify($row['trackers'], $row['message']);
            $host = self::hostOf($row['trackers']);
            $verdicts[$index] = $verdict;
            $hosts[$index] = $host;
            if ($host === '' || ($verdict !== 'alive' && $verdict !== 'candidate')) continue;
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
        foreach ($rows as $index => $row) {
            if ($row['state'] === ruTrackerChecker::STE_META_PENDING) {
                call_user_func($checker, $row['hash'], $row);
                $checked[] = $row['hash'];
                continue;
            }

            // check.php's run() has always honoured $ignoreLabels before
            // doing anything else; this pass's direct setState() writes
            // below must match, or an ignored torrent flaps between
            // STE_IGNORED (a manual check, or a cycle that falls through to
            // the checker) and a scheduler-derived state (this pass's own
            // fast-path writes) depending only on which path last ran.
            if (ruTrackerChecker::isIgnoredLabel($row['label'])) {
                ruTrackerChecker::setState($row['hash'], ruTrackerChecker::STE_IGNORED);
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
            // pre-layer-1 pass dispatched them; hostOf() returns '' for
            // exactly this case.
            if (self::hostOf($row['trackers']) === '') {
                call_user_func($checker, $row['hash'], $row);
                $checked[] = $row['hash'];
                continue;
            }

            $verdict = $verdicts[$index];
            if ($verdict === 'alive') {
                self::clearStaleDeletion($row['hash'], $row['del'], $row['msg']);
                ruTrackerChecker::setState($row['hash'], ruTrackerChecker::STE_UPTODATE);
                $uptodate++;
                continue;
            }
            if ($verdict === 'transport') {
                ruTrackerChecker::setState($row['hash'], ruTrackerChecker::STE_CANT_REACH_TRACKER);
                continue;
            }
            if ($verdict !== 'candidate') continue; // cold / none: no request-worthy signal yet

            $host = $hosts[$index];
            if (in_array($host, $fused, true)) {
                ruTrackerChecker::setState($row['hash'], ruTrackerChecker::STE_CANT_REACH_TRACKER);
                ruTrackerChecker::setMessage($row['hash'], ruTrackerChecker::CHKMSG_FUSE . '|' . $host);
                continue;
            }

            call_user_func($checker, $row['hash'], $row);
            $checked[] = $row['hash'];
        }
        return array('checked' => $checked, 'fused' => $fused, 'uptodate' => $uptodate);
    }

    // The alive path writes STE_UPTODATE directly and never reaches
    // check.php's run(), the only place that otherwise resets chk-del and
    // clears chk-msg -- left alone, a deletion count built up over prior
    // miss cycles survives a torrent's recovery, so a single later miss
    // cycle can reach the deletion threshold instead of needing the full
    // count again. $del/$msg are the row's own chk-del/chk-msg values,
    // already fetched by update.php's batched multicall, so clearing them
    // costs no extra per-torrent XMLRPC round trip; a torrent with neither
    // set (the common case) costs no write at all.
    static private function clearStaleDeletion($hash, $del, $msg)
    {
        $writes = array();
        if ((string) $del !== '')
            $writes[] = new rXMLRPCCommand(getCmd("d.set_custom"), array($hash, "chk-del", ""));
        if ((string) $msg !== '')
            $writes[] = new rXMLRPCCommand(getCmd("d.set_custom"), array($hash, "chk-msg", ""));
        if (!count($writes)) return;

        $req = new rXMLRPCRequest($writes);
        $req->important = false;
        $req->success();
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
        if ($client === null) {
            $state = RuTrackerState::load('updatepass');
            $client = new Snoopy();
            $client->read_timeout = 5;
            $client->_fp_timeout = 5;
            $client->agent = ruTrackerChecker::USER_AGENT;
            $etag = isset($state['feed_etag']) ? $state['feed_etag'] : null;
            if (is_string($etag) && $etag !== '')
                $client->rawheaders = array('If-None-Match' => $etag);
            @$client->fetchComplex(RuTrackerForumIndex::FEED_URL);

            if ((int) $client->status === 304) return;
            if ((int) $client->status === 200) {
                $etag = RuTrackerForumIndex::headerEtag($client->headers);
                if ($etag !== '') {
                    $state['feed_etag'] = $etag;
                    RuTrackerState::save('updatepass', $state);
                }
            }
        }
        if ((int) $client->status !== 200 || !is_string($client->results) || $client->results === '')
            return;

        $map = RuTrackerForumIndex::parseFeed($client->results);
        if (!count($map)) return;

        $req = new rXMLRPCRequest(new rXMLRPCCommand("d.multicall", array("main",
            getCmd("d.get_hash="),
            getCmd("d.get_custom=") . "chk-topic",
            getCmd("d.get_custom=") . "chk-forum")));
        $req->important = false;
        if (!$req->success()) return;

        for ($i = 0; $i + 3 <= count($req->val); $i += 3) {
            $topic = intval($req->val[$i + 1]);
            if (!$topic || !isset($map[$topic])) continue;
            if ((string) $req->val[$i + 2] !== '') continue; // chk-forum already known

            $write = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.set_custom"),
                array($req->val[$i], "chk-forum", (string) $map[$topic]['forum'])));
            $write->important = false;
            $write->success();
            // The other half of the handler's "layer3 forum=N from the
            // chk-forum cache": this is where a cached id came from when the
            // feed, rather than a sweep, resolved it. Bounded by the torrents
            // whose forum is still unknown, never the whole fleet.
            ruTrackerChecker::logDebug('pollFeed: ' . $req->val[$i] . ' forum=' . (int) $map[$topic]['forum']
                . ' for topic ' . $topic . ', learned from the feed');
        }
    }

    // Layer 4's orphan sweep (design doc 4.4 point 4, task 12). pump() only
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
            $until = intval($scan->val[$i + 2]);

            $claim = new rXMLRPCRequest(array(
                new rXMLRPCCommand(getCmd("d.get_custom"), array($oldHash, "chk-meta-new")),
                new rXMLRPCCommand(getCmd("d.get_custom"), array($oldHash, "chk-state")),
            ));
            $claim->important = false;
            if ($claim->success() && isset($claim->val[0], $claim->val[1])
                && (string) $claim->val[0] === $hash
                && (int) $claim->val[1] === ruTrackerChecker::STE_META_PENDING)
                continue; // still claimed: pump() owns this item, and only pump() revisits it

            if ($now > $until) {
                $erase = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.erase"), $hash));
                $erase->important = false;
                $erase->success();
            }
        }
    }

    // Columns of the sweep's own fleet scan: hash, ownership marker, record.
    const SWEEP_COLUMNS = 3;

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
        )));
        $scan->important = false;
        if (!$scan->success()) return;

        for ($i = 0; $i + self::SWEEP_COLUMNS <= count($scan->val); $i += self::SWEEP_COLUMNS) {
            $hash = (string) $scan->val[$i];
            if ((string) $scan->val[$i + 1] === '') continue;   // unmarked: foreign, hands off
            $record = ruTrackerChecker::decodeInheritance((string) $scan->val[$i + 2]);
            // A transaction younger than the lock window may simply be in
            // flight -- batch_check.php takes no cycle lock, so "no other
            // cycle is running" is not the same as "nobody is mid-replacement".
            if ($record !== null && ($now - $record['staged']) <= ruTrackerChecker::MAX_LOCK_TIME) continue;
            self::sweepMarkedRow($hash, $record, $now);
        }
    }

    // One candidate. Reads first, decides second, and writes at most once.
    static private function sweepMarkedRow($hash, $record, $now)
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
            new rXMLRPCCommand(getCmd("d.get_directory_base"), $hash),
        ));
        $probe->important = false;
        // A faulting member injects its faultString into the flat value list
        // rather than shortening it, so the last slot must be present before
        // any of them is trusted.
        if (!$probe->success() || !isset($probe->val[8])) {
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash . " could not be read, deferring to the next cycle");
            return;
        }
        $val = $probe->val;

        // A marked row that is running is a finished replacement whose final
        // clear was lost -- or one a human started by hand. Either way its run
        // state is somebody's decision and the keys are simply retired.
        if (intval($val[0]) !== 0 || intval($val[1]) !== 0) {
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                . " is already running, retiring its replacement keys");
            ruTrackerChecker::clearReplacementRecord($hash);
            return;
        }

        if ($record === null) {
            // Staged before the record existed, or its load command list
            // aborted before the record landed. The predecessor and the run
            // state it was meant to inherit are both unrecoverable, so the row
            // is labelled once -- never started, and never cleared, because
            // clearing would make the hash foreign to any future replacement.
            $stime = intval($val[6]);
            if ($stime <= 0 || ($now - $stime) <= ruTrackerChecker::MAX_LOCK_TIME) return;
            if (intval($val[7]) === ruTrackerChecker::STE_ERROR) return;   // already settled
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                . " carries a replacement marker with no record: the predecessor and its run state"
                . " cannot be recovered, so it is flagged and left untouched"
                . " (state=" . intval($val[0]) . " open=" . intval($val[1])
                . " hashed=" . intval($val[2]) . " bytes=" . intval($val[3]) . ")");
            ruTrackerChecker::setState($hash, ruTrackerChecker::STE_ERROR);
            return;
        }

        // The recorded predecessor is read, and only ever read: it tells the
        // two halves of the transaction apart.
        $exists = ruTrackerChecker::torrentExists($record['old']);
        if ($exists === null) return;                      // never act on an unknowable fact
        if ($exists === true) {
            // The commit never happened. The staged copy is still adoptable by
            // a normal check of the predecessor, so nothing here may touch it.
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                . " was staged for " . $record['old'] . ", which is still in the client:"
                . " the commit never happened, leaving both to the predecessor's own check");
            return;
        }
        self::finishStrandedReplacement($hash, $record, $val, $now);
    }

    // Reached only when the marker is set, the record decoded, the transaction
    // aged past the lock window, the copy stopped AND closed, and the recorded
    // predecessor provably gone.
    static private function finishStrandedReplacement($hash, $record, $val, $now)
    {
        if (!$record['run']['started'] && !$record['run']['open']) {
            // The predecessor was stopped, so leaving the replacement stopped
            // IS the finished outcome -- createTorrent()'s never-resurrect
            // rule, now surviving the erase that used to destroy its input.
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                . " inherited a stopped predecessor, so the transaction is complete as it stands");
            ruTrackerChecker::clearReplacementRecord($hash);
            return;
        }
        if (intval($val[2]) > 0 || intval($val[3]) > 0 || intval($val[4]) !== 0) {
            // It was opened at some point and is stopped now: that is a
            // decision somebody made after the crash, not one to undo.
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                . " has been opened since it was staged, leaving its run state alone");
            ruTrackerChecker::clearReplacementRecord($hash);
            return;
        }
        if ((string) $val[5] !== '') {
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                . " will not be started blind, the daemon already reports: " . (string) $val[5]);
            if (intval($val[7]) !== ruTrackerChecker::STE_ERROR)
                ruTrackerChecker::setState($hash, ruTrackerChecker::STE_ERROR);
            return;
        }

        $commands = array(new rXMLRPCCommand(getCmd("d.open"), $hash));
        // d.open before d.start: a bare d.start on a closed download can leave
        // it closed (the measurement RuTrackerMetaFetch records).
        if ($record['run']['started'])
            $commands[] = new rXMLRPCCommand(getCmd("d.start"), $hash);
        $activate = new rXMLRPCRequest($commands);
        $activate->important = false;
        $activate->success();

        // Believe the measured reading, never the ack. A started download may
        // still be waiting on a scheduler slot, so it is judged on d.get_state
        // exactly as activateReplacement() judges it.
        $check = new rXMLRPCRequest(array(
            new rXMLRPCCommand(getCmd("d.get_state"), $hash),
            new rXMLRPCCommand(getCmd("d.is_open"), $hash),
        ));
        $check->important = false;
        $satisfied = $check->success() && isset($check->val[1])
            && ($record['run']['started'] ? intval($check->val[0]) === 1 : intval($check->val[1]) === 1);
        if ($satisfied) {
            ruTrackerChecker::logDebug("sweepReplacements: " . $hash
                . " finished a replacement its own cycle never activated"
                . " (wanted " . ($record['run']['started'] ? "started" : "open")
                . ", measured state=" . intval($check->val[0]) . " open=" . intval($check->val[1]) . ")");
            ruTrackerChecker::clearReplacementRecord($hash);
            return;
        }
        ruTrackerChecker::logDebug("sweepReplacements: " . $hash
            . " did not come up as recorded, keeping its keys for the next cycle");
        if (intval($val[7]) !== ruTrackerChecker::STE_ERROR)
            ruTrackerChecker::setState($hash, ruTrackerChecker::STE_ERROR);
    }
}
