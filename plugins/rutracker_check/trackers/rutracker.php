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
    // single read (null on any RPC failure or a genuinely unset field, so
    // callers can tell "no data" from "empty string") and a fire-and-forget
    // write, both routed through getCmd() like every other command here.
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
        $req = new rXMLRPCRequest(new rXMLRPCCommand(
            getCmd("d.set_custom"), array($hash, $field, (string) $value)));
        $req->important = false;
        return $req->success();
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
        // Canonical or nothing: ctype_digit() plus a bare (int) read "007" as
        // the forum 7 and "0" as the forum 0, and layer 3 then fetched a dump
        // from a forum the stored value never named -- whose rows go on to
        // decide whether this torrent is deleted. trim() stays: transport
        // whitespace is not the question, the spelling of the id is.
        return RuTrackerRpcValue::canonicalPositiveInt32(trim($forum));
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

    // Two-independent-sources deletion confirmation:
    // chk-del holds "count:timestamp-of-last-increment". The increment is
    // capped at once per $interval regardless of how many times this runs,
    // so repeated manual batch_check.php clicks cannot fast-forward the
    // three required cycles.
    // Whether confirmDeletion() ever reached its threshold for this torrent:
    // the chk-del counter survives the verdict (only an alive/present row
    // resets it), so it is the durable record that a full confirmation run
    // happened, readable even after run() has overwritten chk-state with
    // STE_INPROGRESS for the current dispatch.
    static private function deletionConfirmedOnce($hash)
    {
        global $rutrackerDeleteCycles;
        // >= 1: at zero or below, the very first confirmation would return
        // STE_DELETED, and a settled deletion rests for a week.
        $cycles = max(1, isset($rutrackerDeleteCycles) ? (int) $rutrackerDeleteCycles : 3);
        $stored = self::readCustom($hash, "chk-del");
        if ($stored === null || !preg_match('/^([0-9]+):/', (string) $stored, $m)) return false;
        // "03" is not the count 3: it is a spelling confirmDeletion() cannot
        // have written, so it is no record of a completed confirmation run.
        $count = RuTrackerRpcValue::canonicalNonnegativeInteger($m[1]);
        return $count !== null && $count >= $cycles;
    }

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
        // reset site, which the scheduler's own clearStaleDeletion() guard
        // never sees.
        $successAtReadable = false;
        $successAt = self::readCustom($hash, "chk-stime", $successAtReadable);
        if ($count > 0 && !$successAtReadable) {
            ruTrackerChecker::logDebug('download_torrent: ' . $hash . ' deletion count ' . $count
                . ' cannot be checked against an unreadable healthy-verdict timestamp; deferring');
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }
        // An UNSET chk-stime reads back as '' -- no healthy verdict on record,
        // nothing to compare against. Any other spelling that will not parse
        // cannot prove the run consecutive, and (int) answered 0 for all of
        // them: smaller than any real stamp, so the guard passed and the count
        // stood. The value itself never reaches the log.
        $successStamp = ($successAt === null || $successAt === '')
            ? null : RuTrackerRpcValue::canonicalNonnegativeInteger($successAt);
        $stampUnusable = $successAt !== null && $successAt !== '' && $successStamp === null;
        if ($count > 0 && ($stampUnusable || ($successStamp !== null && $successStamp > $lastIncrement))) {
            ruTrackerChecker::logDebug('download_torrent: ' . $hash . ' deletion count ' . $count
                . ' predates the last up-to-date verdict at '
                . ($stampUnusable ? 'a stamp that will not parse' : $successStamp) . ', restarting it');
            $count = 0;
            $lastIncrement = 0;
        }

        // The message is the same token throughout: "row missing, this is
        // confirmation cycle N of M". At N == M the status label already says
        // "probably deleted", and N/M then reads as what backed that verdict.
        if ($count > 0 && ($now - $lastIncrement) < $interval) {
            self::reportDeletionProgress($hash, $count, $cycles);
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }

        $count++;
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

    // Fix A: "the topic's current version is already in the client" is a
    // terminal outcome -- nothing about it can change until the user removes
    // that successor -- yet the old code re-ran the whole chain every cycle,
    // spending one of the host's ten announce probes and a forum dump fetch
    // on a settled case, forever. The chk-msg token metafetch.php wrote IS
    // the record (no new custom field), and one d.hash probe re-verifies it.
    //
    // check.php's run() writes STE_INPROGRESS immediately before dispatching
    // to this handler, so that -- not the stored verdict -- is the state a
    // scheduled or manual check actually sees here; a direct call still sees
    // STE_NOT_NEED. Any other state means something else has judged this
    // torrent since the token was written, so the token is stale.
    //
    // @return string|null the recorded successor hash, or null when there is
    //         no usable record and the normal flow must run
    static private function supersededBy($hash)
    {
        $req = new rXMLRPCRequest(array(
            new rXMLRPCCommand(getCmd("d.get_custom"), array($hash, "chk-state")),
            new rXMLRPCCommand(getCmd("d.get_custom"), array($hash, "chk-msg")),
        ));
        $req->important = false;
        if (!$req->success() || !isset($req->val[0], $req->val[1])) return null;

        $state = RuTrackerRpcValue::canonicalNonnegativeInteger($req->val[0]);
        if ($state !== ruTrackerChecker::STE_NOT_NEED && $state !== ruTrackerChecker::STE_INPROGRESS)
            return null;

        $parts = explode('|', (string) $req->val[1], 2);
        if (count($parts) !== 2 || $parts[0] !== ruTrackerChecker::CHKMSG_SUPERSEDED) return null;
        return self::normalizeHash($parts[1]);
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

        // Layer 0: a settled "superseded" verdict is re-verified with one
        // existence probe and nothing else (see supersededBy()), ahead of
        // even the local bookkeeping below -- chk-topic was already recorded
        // by the run that wrote the token. If the user has since removed the
        // successor, the stale token is cleared and the normal flow decides
        // again.
        $successor = self::supersededBy($hash);
        if ($successor !== null) {
            // Only a definite "gone" reopens the question: a failed probe
            // (null) is no evidence the user removed anything, and spending
            // the whole chain on that guess is exactly what this avoids.
            if (ruTrackerChecker::torrentExists($successor) !== false)
                return ruTrackerChecker::STE_NOT_NEED;
            ruTrackerChecker::setMessage($hash, '');
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
        // The sentence goes with the state it explained: init.js appends
        // chk-msg to whatever chk-state is current, so a token an earlier
        // cycle stored ('deleting|2/3', 'fuse|<host>', 'topic-status|5')
        // would render under a verdict it does not describe -- "No need --
        // the topic is missing from the forum list; confirmation cycle 2/3".
        // Two exits are deliberately NOT in this list. 'cold' returns
        // STE_UNCHANGED, which puts the PREVIOUS verdict back, so that
        // verdict's sentence is still the right one. And the inconclusive
        // exits further down (layer 2 'uncertain', an unavailable dump) learn
        // nothing at all: they leave the row untouched, token included,
        // because the deletion counter the token names is untouched too.
        //
        // These two DID change the verdict, and to one no stored token
        // describes.
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
        // TRACKER_HOST_PATTERN, not TRACKER_PATTERN: this decides whether to
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
            // A misconfigured non-positive pause must not turn into a
            // negative sleep() argument; zero is a legitimate pause, so the
            // floor is 0, not 1.
            sleep(RuTrackerAnnounce::probePause($rutrackerAnnouncePause) + random_int(0, 3));
            $probeUrl = RuTrackerAnnounce::buildUrl($announceUrl, $localHash,
                RuTrackerAnnounce::makePeerId(), 63981, bin2hex(random_bytes(4)));
            if ($probeUrl === null) {
                // The slot was reserved for a request that will not happen.
                // Leaving it spent would shrink the budget for no traffic.
                RuTrackerAnnounce::releaseProbe($host, $probeAt);
                ruTrackerChecker::logDebug('download_torrent: ' . $hash
                    . ' layer2 skipped: the announce URL cannot be turned into a probe URL');
            } else {
                $client = ruTrackerChecker::makeClient($probeUrl);
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
                // Deliberately writes NOTHING: an inconclusive answer taught
                // this cycle nothing, and the stored token still describes
                // something true -- the deletion counter it names is
                // untouched. Clearing it here would throw away the most
                // informative thing the row has.
                if ($answer === 'uncertain') return ruTrackerChecker::STE_CANT_REACH_TRACKER;
                $trackerConfirmed = true;
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

        $dump = RuTrackerForumIndex::fetchDump($forumId);
        // Same as layer 2's inconclusive answer: nothing was learned, so
        // nothing is written and the stored token stands. An empty dump is NOT
        // this case -- it is the forum answering that it lists nothing.
        if ($dump === null) {
            ruTrackerChecker::logDebug('download_torrent: ' . $hash . ' layer3 dump forum=' . $forumId
                . ' unavailable');
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
                // A probe that did not run is no evidence either way: when
                // the deletion was already fully confirmed once (chk-del at
                // the threshold) and the row is still missing, keep the
                // settled verdict -- only a probe that actually RAN may move
                // it. Downgrading DELETED to "can't reach" un-settles the row
                // into hourly re-litigation until the deletion is re-confirmed
                // from scratch, and with layer 2 switched off in the
                // configuration it can never be re-confirmed at all.
                //
                // Tested as "not an allowance", not as a list of refusal
                // labels: every path into this branch means no evidence was
                // gathered (a probe that ran returns above -- 'registered'
                // up to date, 'uncertain' retryable, 'unregistered' sets
                // $trackerConfirmed), so enumerating 'cap' and 'cooldown'
                // silently excluded the rest -- layer 2 disabled, no announce
                // host, a foreign host, a budget that could not be recorded.
                // Same rule, same reason, as the skip gate above.
                if ($probeDecision !== 'allow' && self::deletionConfirmedOnce($hash)) {
                    ruTrackerChecker::logDebug('download_torrent: ' . $hash
                        . ' row still missing and the probe budget is spent:'
                        . ' the settled DELETED verdict stands');
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
