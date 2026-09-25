<?php

require_once(__DIR__ . '/../../php/urlhost.php');

// Layer 1 of the post-API design: request-free candidate detection from
// rTorrent's own per-tracker counters, plus the fleet-level fuse.
class RuTrackerDetector
{
    const TRACKER_PATTERN = '/t-ru\.org|rutracker\./i';

    // The host-identity form of the pattern above: RuTracker's own hosts,
    // matched whole -- a listed host or a subdomain of one, through UrlHost --
    // and limited to RuTracker's own top-level domains. TRACKER_PATTERN itself
    // is deliberately a substring test -- it is applied to entire announce
    // URLs, where the host sits in the middle -- but that makes it useless as
    // a trust decision: 'rutracker.evil.example' and 'bt.t-ru.org.evil.example'
    // both satisfy it. Anywhere the answer decides whether to SEND something
    // to a host, use this one.
    //
    // The TLDs are enumerated rather than left as [a-z]{2,} because anyone who
    // can put an announce URL into a torrent picks the host, so a wildcard TLD
    // hands 'rutracker.xyz' -- or, on a machine with a search domain, plain
    // 'rutracker.local' -- an outgoing request and a say in the verdict.
    // '.cc' is the API/dump host; the other four are the site's own mirrors.
    //
    // This constant is the ONLY enumeration of them inside this plugin. It
    // once had hand-written copies in trackers/rutracker.php, and they
    // drifted: the topic-URL copy was anchored at the START of the host and
    // had never heard of rutracker.cc, so the site's own
    // 'https://www.rutracker.org/...' links stopped being recognised.
    // No copy of the list is left: every decision that has to know WHICH
    // domains now asks isTrackerHost(), isTrackerRow() or isForeignRow().
    // Other host tests do remain -- TRACKER_PATTERN's substring jurisdiction
    // test at three production sites, and the two literal regexes
    // registerTracker() is handed at the bottom of trackers/rutracker.php --
    // but none of them enumerates the TLDs, so none of them has to change when
    // a domain is added. Search those helper names across the plugin before
    // changing the list. classify() attributes d.message to tracker rows; announceSignal()
    // selects enabled announce rows and evaluates their live signal for announceVerdict().
    //
    // That grep stops at this plugin's border, and so does this constant:
    // detector.php is required only from inside plugins/rutracker_check (and
    // from the tests), so anything elsewhere in the tree that recognises
    // RuTracker by name necessarily carries its own list and cannot be reached
    // from here. A sixth domain is therefore a whole-tree search, not a
    // directory one, and sharing a list across plugins is a real change rather
    // than something a grep can finish.
    const TRACKER_HOSTS = array('t-ru.org', 'rutracker.org', 'rutracker.cr', 'rutracker.net',
        'rutracker.nl', 'rutracker.cc');

    // Anchored at the start of the message rTorrent itself composes
    // ("Tracker: [Could not resolve hostname]"), because the tail of that
    // string is the TRACKER's own prose. Unanchored, a tracker could put
    // "timed out" in any failure reason and veto its own deletion detection
    // for good -- the plugin would read its refusal as a network problem
    // and never conclude anything.
    const TRANSPORT_PATTERN = '/^\s*(?:Tracker:\s*)?\[?\s*(?:Could not resolve hostname|Could not connect|Timed? ?out)/i';

    /**
     * Is this announce URL one of RuTracker's own -- or, given another
     * tracker's host list, one of that tracker's?
     *
     * The one predicate that answers it AS A TRUST QUESTION. Exactly three
     * other sites ask a different question with TRACKER_PATTERN -- hostOf()
     * (updatepass.php), describeCounters()'s counter log (trackers/
     * rutracker.php) and the row selection in announceSignal() below -- and
     * they keep the loose substring test on purpose, because they decide
     * JURISDICTION: a torrent whose announce was tampered with is still a
     * RuTracker topic that layers 2 and 3 must look at. Trust is the other
     * question, and a substring test over a whole URL answers YES for
     * rutracker.evil.example and bt.t-ru.org.evil.example. That is harmless
     * when the answer only selects which row to LOG, and not harmless at all
     * when it decides a verdict: a lookalike row announcing successfully made
     * classify() answer 'alive', the scheduler wrote UPTODATE from it, and the
     * real RuTracker topic was never checked again. Anyone who can put an
     * announce URL into a torrent picks that host.
     *
     * So the host is extracted and matched whole, against RuTracker's own
     * domains -- the same anchored rule the outgoing paths already use.
     */
    static public function isTrackerRow($url, $hosts = null)
    {
        $host = UrlHost::of($url);
        if ($host === null) return false;
        return self::isTrackerHost($host, $hosts);
    }

    // The host test itself is UrlHost::isOneOf() (php/urlhost.php), the one
    // this tree keeps: three places used to apply an anchored pattern by hand
    // and only one of them stripped the trailing dot, so 'bt.t-ru.org.' --
    // the same host, written as a fully qualified name -- was RuTracker's
    // here and a stranger to the outgoing paths, which then skipped layer 2
    // and refused the metadata fetch. One implementation, one normalisation.
    //
    // $hosts is RuTracker's own list unless a caller supplies another
    // tracker's: the test is the same whoever the host belongs to, so a
    // second tracker's authority check goes through this one function
    // rather than through a copy of it.
    static public function isTrackerHost($host, $hosts = null)
    {
        return UrlHost::isOneOf($host, $hosts === null ? self::TRACKER_HOSTS : $hosts);
    }

    // Splits the '#'-terminated, '|'-joined tracker blob an embedded
    // t.multicall assembles (see Task 8). A row whose field count is wrong
    // (e.g. a literal '|' inside its URL broke the framing) or whose counter
    // fields are non-numeric is dropped rather than guessed at.
    static public function parseTrackerBlob($blob, &$complete = null)
    {
        $complete = true;
        $rows = array();
        if (!is_string($blob) || $blob === '') {
            return $rows;
        }
        if (substr($blob, -1) !== '#') {
            $complete = false;
        }
        foreach (explode('#', $blob) as $chunk) {
            if ($chunk === '') continue;
            $fields = explode('|', $chunk);
            if (count($fields) !== 4 || !is_numeric($fields[1]) || !is_numeric($fields[2]) || !is_numeric($fields[3])) {
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

    /**
     * What a set of tracker rows says about a topic, by announce alone.
     *
     * The shared core of announceVerdict() and classify(), stated once so a
     * second tracker cannot grow a second, subtly different copy of it. A
     * pattern and a list, and they answer different questions:
     *
     *   $jurisdiction  is this row about the tracker we are asking about?
     *                  Deliberately loose, applied to a whole announce URL: a
     *                  torrent whose announce was tampered with is still that
     *                  tracker's topic and still has to be looked at.
     *   $authority     may this row certify the topic ALIVE? A list of hosts
     *                  matched whole, because 'alive' is the verdict that
     *                  stops the check, and a host anyone can register must
     *                  not be able to reach it. Applied through the same
     *                  isTrackerRow() the outgoing RuTracker paths use.
     *
     * @return array enabled: rows in jurisdiction, counted: any of them
     *               carrying a signal yet, alive: one of them announcing
     *               successfully on an authoritative host
     */
    static private function announceSignal($rows, $jurisdiction, $authority)
    {
        $enabled = 0;
        $alive = false;
        $counted = false;
        foreach ((array) $rows as $row) {
            if (!is_array($row) || empty($row['enabled'])) continue;
            $url = (string) ($row['url'] ?? '');
            if (!preg_match($jurisdiction, $url)) continue;
            $enabled++;

            $failed = (int) ($row['failed'] ?? 0);
            $success = (int) ($row['success'] ?? 0);
            if ($failed === 0 && $success === 0) continue;   // no signal from this row yet
            $counted = true;
            if ($failed === 0 && self::isTrackerRow($url, $authority)) $alive = true;
        }
        return array('enabled' => $enabled, 'alive' => $alive, 'counted' => $counted);
    }

    /**
     * The announce verdict for a tracker this class knows nothing else about,
     * and the first three rungs of classify()'s own ladder.
     *
     * No d.message reading, so no 'transport' verdict: d.message is
     * download-global and says which tracker event happened last, not which
     * row it happened to -- attributing it needs the look-alike test
     * isForeignRow() makes, and that test is about RuTracker. classify() adds
     * that rung for RuTracker; for everyone else the honest answers are the
     * three below plus 'candidate'.
     *
     * The two are NOT interchangeable and the caller must not pass one for
     * both. A registry announce filter is a substring test over whole URLs --
     * 'torrent4me\.com' also matches 'evil-torrent4me.com.attacker.test' --
     * so using it to certify a topic alive would hand that decision to anyone
     * who can register a domain. The authority is a list of hosts matched
     * whole, which is why a handler opts into this by SUPPLYING it rather
     * than by setting a flag.
     *
     * @param string $jurisdiction  the handler's announce filter, from the
     *                              tracker registry
     * @param array  $authority     the hosts the handler declared authoritative:
     *                              each of them, or a subdomain of one
     * @return string 'none' | 'cold' | 'alive' | 'candidate'
     */
    static public function announceVerdict($rows, $jurisdiction, $authority)
    {
        $signal = self::announceSignal($rows, $jurisdiction, $authority);
        if ($signal['enabled'] === 0) return 'none';
        if ($signal['alive']) return 'alive';
        if (!$signal['counted']) return 'cold';
        return 'candidate';
    }

    // Layer-1 verdict for one RuTracker torrent, driven only by its RuTracker
    // tracker rows; dht:// and any other row are never consulted. $dMessage
    // (d.message) is download-global and holds only the most recent tracker
    // event of ANY row, so it may recognise a transport failure but never
    // prove a topic is gone.
    //
    // 'none' is returned both for a disabled RuTracker row and for a torrent
    // that has no RuTracker row at all -- a Kinozal/NNMClub/Toloka/tfile
    // torrent, over which this verdict simply has no jurisdiction. Callers
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
        //
        // Jurisdiction is the loose substring test on purpose -- a torrent
        // whose announce was tampered with is still a RuTracker topic that
        // layers 2 and 3 must look at -- and the one verdict that must be
        // sure, 'alive', is gated on the anchored host test instead, inside
        // announceSignal(). Both outgoing layers already gate on that same
        // test.
        $verdict = self::announceVerdict($rows, self::TRACKER_PATTERN, self::TRACKER_HOSTS);
        if ($verdict !== 'candidate') return $verdict;

        // Attribution, asked of every enabled row: could it have written
        // $dMessage on its own? isForeignRow() is the canonical answer, shared
        // with the metadata fetch, and it is the anchored host test -- a
        // look-alike is not RuTracker and must not lend RuTracker its excuses.
        // A look-alike row is therefore counted BOTH ways: judged by the
        // signal above, trusted nowhere.
        $foreign = 0;
        foreach ((array) $rows as $row)
            if (is_array($row) && !empty($row['enabled']) && self::isForeignRow($row))
                $foreign++;
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
     * libtorrent may try both address families for one tracker. If both fail,
     * it joins their IPv4 and IPv6 reasons with ' /// ' (upstream #3201).
     * A live fleet sample from 2026-08-21 was:
     * "Tracker: [Could not connect to server /// Could not resolve hostname]"
     * The separator does not identify different tracker rows; d.message is
     * download-global, and messageSpeaksForTracker() handles its attribution.
     *
     * An anchored match on only the first part could treat a later tracker
     * "Failure reason" as a pure transport failure. Every nonempty part must
     * therefore be a transport failure.
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
     * $foreignEnabled counts the enabled rows belonging to somebody else, and
     * both callers count them with isForeignRow() -- one definition, or this
     * helper answers two different questions depending on who asked. A
     * magnet's own dht:// row is one of them, which is why the fetch's stub --
     * "exactly one HTTP row, so nobody else could have written the message" --
     * was not the closed world it took itself for.
     */
    static public function messageSpeaksForTracker($foreignEnabled, $dMessage)
    {
        return (int) $foreignEnabled === 0 && is_string($dMessage) && $dMessage !== '';
    }

    /**
     * Could this row have written d.message without RuTracker's involvement?
     *
     * The canonical counterpart to messageSpeaksForTracker(): whatever that
     * helper's $foreignEnabled is counted from must ask exactly this, and both
     * of its callers -- classify() above and RuTrackerMetaFetch::pump()'s stub
     * projection -- now do. They used to disagree. classify() counted a row
     * foreign by TRACKER_PATTERN, a substring test over the whole URL, which
     * 'rutracker.evil.example' and 'bt.t-ru.org.evil.example' both satisfy, so
     * a look-alike row was not counted foreign at all and its own "Could not
     * connect" was handed to RuTracker as an excuse: layer 1 answered
     * 'transport' -- do nothing this cycle -- and layers 2 and 3 never ran.
     *
     * Attribution is a trust question, so it takes the anchored host test.
     * Row SELECTION is a separate, deliberately loose question about
     * jurisdiction, and stays where it is: a torrent whose announce was
     * tampered with is still a RuTracker topic the later layers must look at.
     *
     * Two guards, and they answer in OPPOSITE directions, so they are not one
     * condition. A disabled row is nobody: it announces nothing, so it writes
     * no event, and false is right. Anything that is not a row array is not a
     * row this helper can read at all, and an unreadable row's author is
     * unknown -- which is "somebody else could have written it", the answer
     * that silences d.message for both callers. Failing the other way here
     * would say "this is RuTracker's own" about garbage, which is the
     * opposite of what the two host predicates do: isTrackerRow() and
     * isTrackerHost() both answer false for an argument they cannot parse
     * into one of RuTracker's domains, null and '' included.
     * No caller can reach it today -- classify() drops non-arrays first and
     * parseTrackerProjection() emits only well-formed rows -- but the
     * signature pair invites the slip: isTrackerRow() takes a URL string and
     * this one takes a row array, so isForeignRow($row['url']) at a future
     * call site would otherwise put $foreign back at 0 and restore the exact
     * defect above, silently.
     */
    static public function isForeignRow($row)
    {
        if (!is_array($row)) return true;
        if (empty($row['enabled'])) return false;
        return !self::isTrackerRow($row['url'] ?? '');
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
