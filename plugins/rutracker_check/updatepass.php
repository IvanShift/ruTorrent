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
}
