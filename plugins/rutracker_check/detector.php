<?php

// Layer 1 of the post-API design: request-free candidate detection from
// rTorrent's own per-tracker counters, plus the fleet-level fuse.
class RuTrackerDetector
{
    const TRACKER_PATTERN = '/t-ru\.org|rutracker\./i';

    // The host-identity form of the pattern above, anchored to whole domain
    // labels AND to RuTracker's own top-level domains. TRACKER_PATTERN itself
    // is deliberately a substring test -- it is applied to entire announce
    // URLs, where the host sits in the middle -- but that makes it useless as
    // a trust decision: 'rutracker.evil.example' and 'bt.t-ru.org.evil.example'
    // both satisfy it. Anywhere the answer decides whether to SEND something
    // to a host, use this one.
    //
    // The TLDs are enumerated rather than left as [a-z]{2,} for the same
    // reason the rest of the plugin enumerates them (trackers/rutracker.php
    // :33, :90, :173): anyone who can put an announce URL into a torrent picks
    // the host, so a wildcard TLD hands 'rutracker.xyz' -- or, on a machine
    // with a search domain, plain 'rutracker.local' -- an outgoing request and
    // a say in the verdict. '.cc' is the API/dump host; the other four are the
    // site's own mirrors.
    const TRACKER_HOST_PATTERN = '/(^|\.)(t-ru\.org|rutracker\.(?:org|cr|net|nl|cc))$/i';

    // Anchored at the start of the message rTorrent itself composes
    // ("Tracker: [Could not resolve hostname]"), because the tail of that
    // string is the TRACKER's own prose. Unanchored, a tracker could put
    // "timed out" in any failure reason and veto its own deletion detection
    // for good -- the plugin would read its refusal as a network problem
    // and never conclude anything.
    const TRANSPORT_PATTERN = '/^\s*(?:Tracker:\s*)?\[?\s*(?:Could not resolve hostname|Could not connect|Timed? ?out)/i';

    /**
     * Is this announce URL one of RuTracker's own?
     *
     * The one predicate that answers it AS A TRUST QUESTION. Other sites ask
     * a different question with TRACKER_PATTERN -- hostOf(), the counter log,
     * metadata harvest, and the row selection in classify() below -- and they
     * keep the loose substring test on
     * purpose, because they decide JURISDICTION: a torrent whose announce was
     * tampered with is still a RuTracker topic that layers 2 and 3 must look
     * at. Trust is the other question, and a substring test over a whole URL
     * answers YES for rutracker.evil.example
     * and bt.t-ru.org.evil.example. That is harmless when the answer only
     * selects which row to LOG, and not harmless at all when it decides a
     * verdict: a lookalike row announcing successfully made classify() answer
     * 'alive', the scheduler wrote UPTODATE from it, and the real RuTracker
     * topic was never checked again. Anyone who can put an announce URL into a
     * torrent picks that host.
     *
     * So the host is extracted and matched whole, against RuTracker's own
     * domains -- the same anchored rule the outgoing paths already use.
     */
    static public function isTrackerRow($url)
    {
        $host = @parse_url((string) $url, PHP_URL_HOST);
        if (!is_string($host)) return false;
        return self::isTrackerHost($host);
    }

    // The anchored test itself, and the one normalisation it needs. Three
    // places used to apply TRACKER_HOST_PATTERN by hand and only this one
    // stripped the trailing dot, so 'bt.t-ru.org.' -- the same host, written
    // as a fully qualified name -- was RuTracker's here and a stranger to the
    // outgoing paths, which then skipped layer 2 and refused the metadata
    // fetch. Normalising can only ever widen the match to the same host under
    // another spelling: rtrim removes the root dot and nothing else, so
    // 'rutracker.evil.example.' stays rejected.
    static public function isTrackerHost($host)
    {
        // Same normalisation RuTrackerAnnounce::hostKey() applies before it
        // keys the announce budget: a trailing dot is the DNS root and names
        // the same host, and the pattern is anchored at the end.
        return preg_match(self::TRACKER_HOST_PATTERN, rtrim((string) $host, '.')) === 1;
    }

    // Splits the '#'-terminated, '|'-joined tracker blob an embedded
    // t.multicall assembles (see Task 8). A row whose field count is wrong
    // (e.g. a literal '|' inside its URL broke the framing) is dropped
    // rather than guessed at.
    static public function parseTrackerBlob($blob, &$complete = null)
    {
        $complete = true;
        $rows = array();
        foreach (explode('#', (string) $blob) as $chunk) {
            if ($chunk === '') continue;
            $fields = explode('|', $chunk);
            if (count($fields) !== 4) {
                $complete = false;
                continue;
            }
            $rows[] = array(
                'url' => $fields[0],
                'enabled' => (int) $fields[1],
                'failed' => (int) $fields[2],
                'success' => (int) $fields[3],
            );
        }
        return $rows;
    }

    // Layer-1 verdict for one torrent, driven only by the
    // RuTracker tracker row; dht:// and any other row are never consulted.
    // $dMessage (d.message) is download-global and holds only the most
    // recent tracker event of ANY row, so it may recognise a transport
    // failure but never prove a topic is gone.
    //
    // 'none' is returned both for a disabled RuTracker row and for a torrent
    // that has no RuTracker row at all -- a Kinozal/NNMClub/Toloka/tfile
    // torrent, over which this detector simply has no jurisdiction. Callers
    // that sweep the whole seeding view (RuTrackerUpdatePass::run) carry all
    // of those too and must not read that second 'none' as "nothing to do",
    // or every other tracker's handler silently stops running.
    static public function classify($rows, $dMessage, $trackersComplete = true)
    {
        // Every RuTracker row is weighed, not just the first: a RuTracker
        // torrent normally carries several (bt.t-ru.org, bt2..., bt3...), and
        // deciding on whichever happens to come first let one disabled or one
        // failing row speak for all of them. A single row that still
        // announces successfully proves the topic is alive, and 'alive' is
        // also the safe direction -- 'candidate' is what spends requests and
        // ultimately replaces the user's torrent.
        $enabled = 0;
        $foreign = 0;
        $alive = false;
        $counted = false;
        foreach ((array) $rows as $row) {
            if (!is_array($row) || empty($row['enabled'])) continue;
            if (!preg_match(self::TRACKER_PATTERN, (string) ($row['url'] ?? ''))) {
                $foreign++;   // some other tracker, and it can write $dMessage too
                continue;
            }
            $enabled++;

            $failed = (int) ($row['failed'] ?? 0);
            $success = (int) ($row['success'] ?? 0);
            if ($failed === 0 && $success === 0) continue;   // no signal from this row yet
            $counted = true;
            // 'alive' is the only verdict that STOPS the check, so it is the
            // only one a lookalike may not reach. The row selection above stays
            // the loose substring test on purpose -- it decides jurisdiction,
            // and a torrent whose announce was tampered with is still a
            // RuTracker topic that layers 2 and 3 must look at -- but a host
            // anyone can register must not be able to certify the topic alive
            // and have the scheduler write UPTODATE from it. Both outgoing
            // layers already gate on this same anchored test.
            if ($failed === 0 && self::isTrackerRow($row['url'] ?? '')) $alive = true;
        }
        if ($enabled === 0) return 'none';
        if ($alive) return 'alive';
        if (!$counted) return 'cold';
        // d.message is download-global: it holds the most recent tracker event
        // of ANY row. Reading it as the RuTracker row's excuse is only sound
        // when no other enabled tracker could have written it -- otherwise a
        // third party's timeout silences a genuinely re-uploaded topic, and
        // the check never gets past layer 1. With a foreign row present the
        // message proves nothing, so the counters speak alone: 'candidate'
        // only means "worth investigating", and layers 2 and 3 still have to
        // agree before anything is concluded.
        if ($trackersComplete
            && self::messageSpeaksForTracker($foreign, $dMessage)
            && self::isTransportFailure($dMessage))
            return 'transport';
        return 'candidate';
    }

    /**
     * Is this d.message a transport failure and NOTHING else?
     *
     * rTorrent joins the messages of every failing row into one string with
     * ' /// ' between them. Live sample from the fleet, 2026-08-21:
     * "Tracker: [Could not connect to server /// Could not resolve hostname]"
     * on a torrent whose two failing rows were a retracker and an IPv6 mirror,
     * while its two working rows had nothing to say.
     *
     * The anchored pattern alone therefore only ever read the FIRST part, and
     * answered for the whole: a real "Failure reason" queued behind a timeout
     * was excused as a pure transport failure, so layer 1 said 'transport' --
     * do nothing this cycle -- about a topic that had just been deregistered,
     * and would keep saying it for as long as one row could not be resolved.
     *
     * So every part has to be a transport failure for the message to be one.
     */
    static public function isTransportFailure($message)
    {
        if (!is_string($message) || trim($message) === '') return false;
        $body = trim($message);
        // The wrapper is written once around the whole join, not per part.
        if (preg_match('/^Tracker:\s*\[(.*)\]$/is', $body, $m)) $body = $m[1];
        foreach (preg_split('~\s*///\s*~', $body) as $part) {
            if (trim($part) === '') continue;
            if (!preg_match(self::TRANSPORT_PATTERN, $part)) return false;
        }
        return true;
    }

    /**
     * May $dMessage be read as the RuTracker row's own account of itself?
     *
     * Only when nobody else could have written it, and when there is something
     * to read. Both callers need the same answer and read it in opposite
     * directions: classify() looks for an excuse that spares a torrent, and
     * the metadata fetch looks for a rejection that ends one -- so a message
     * that cannot be attributed must silence BOTH, and an empty one is not a
     * rejection any more than it is an excuse.
     *
     * $foreignEnabled counts the enabled rows belonging to somebody else. A
     * magnet's own dht:// row is one of them, which is why the fetch's stub --
     * "exactly one HTTP row, so nobody else could have written the message" --
     * was not the closed world it took itself for.
     */
    static public function messageSpeaksForTracker($foreignEnabled, $dMessage)
    {
        return (int) $foreignEnabled === 0 && is_string($dMessage) && $dMessage !== '';
    }

    // Fleet-level circuit breaker: an announce host trips when its share of
    // layer-1 candidates reaches both the relative share and the absolute
    // floor, so a handful of failures in a tiny group can't trip it on
    // their own.
    static public function fuseTrips($hostStats, $share, $floor)
    {
        $tripped = array();
        foreach ((array) $hostStats as $host => $stat) {
            $total = (int) ($stat['total'] ?? 0);
            $candidates = (int) ($stat['candidates'] ?? 0);
            if ($total > 0 && $candidates >= max((int) $floor, (int) ceil($share * $total)))
                $tripped[] = $host;
        }
        return $tripped;
    }
}
