<?php

require_once( __DIR__ . '/../../php/Torrent.php' );
require_once( __DIR__ . '/state.php' );

// Torrent::decode() (php/Torrent.php:185) is a public instance method, so
// reaching it still needs an object. Torrent's own constructor does
// unrelated file/folder detection on its argument, which is pointless (and
// throws internally, caught, for a bare bencode string) for the sole purpose
// of decoding a tracker reply. This subclass skips that constructor and
// inherits decode() as-is.
class RuTrackerBencodeDecoder extends Torrent
{
    public function __construct()
    {
    }
}

// Layer 2 of the post-API design: passkey-less announce confirmation.
// The probe identity MUST differ from rTorrent's own peer_id/port/key —
// event=stopped removes a peer record and trackers key peers by these.
class RuTrackerAnnounce
{
    const PEER_PREFIX = '-RC0001-';

    // Measured live 2026-08-07: eight probes to bt4.t-ru.org/ann, this exact
    // probe identity (no passkey, event=stopped, numwant=0, left=0). Three
    // hashes rTorrent was announcing successfully came back with no failure
    // reason at all; three hashes independently known to be genuinely
    // re-uploaded (deregistered) came back with exactly this text. Anything
    // else -- a rate-limit notice, a ban, a malformed-request complaint --
    // has NOT been confirmed to mean "deregistered" and must stay
    // inconclusive; classify() accepts no other text.
    const UNREGISTERED_FAILURE_REASON = 'Torrent not registered';

    // Floor for the announce cap's window (reserveProbe()/probeDecision()'s
    // $window, seconds), mirroring RuTrackerCheckImpl::MIN_DELETE_INTERVAL
    // (trackers/rutracker.php): conf.php documents $updateInterval = 0 as
    // "disable the scheduler", but a manual batch_check.php click still
    // computes the window as $updateInterval * 60 -- i.e. 0 -- and without a
    // floor every click would open and instantly close its own window,
    // buying a fresh cap each time. Floored at the smallest legitimate
    // non-zero scheduler interval (1 minute; see plugins/scheduler/conf.php's
    // own "1-6,10,12,15,20,30 or 60" minutes).
    const MIN_WINDOW = 60;

    // The largest announce answer classify() will decode. See the reasoning
    // where it is applied: this is a bound on the decoder's recursion depth
    // as much as on the transfer, so it cannot be raised on transfer grounds
    // alone. Over a hundred times the size of any answer this probe asks for.
    const MAX_ANNOUNCE_BODY = 8192;

    // The per-host announce budget -- a windowed probe count (window_start +
    // window_count) plus the 403 cooldown -- lives entirely in the
    // persisted 'announce' state (RuTrackerState, state.php), keyed one
    // entry per host. Persisting the count, not just the cooldown, is what
    // makes the cap hold across every process that probes: the hourly
    // update.php pass, its detached forumcrawl.php, and a fresh
    // batch_check.php per manual "check" click all share the same file, so
    // ten manual clicks share one window's budget instead of each buying
    // its own the way an in-memory-only counter used to.

    // Why this host may or may not be probed right now: 'allow', 'cooldown'
    // (a 403 cooldown is still running) or 'cap' (the window's budget is
    // spent). A named answer rather than a boolean, because this is what the
    // debug log prints: a skipped layer 2 says which of the two budgets
    // stopped it instead of merely that something did.
    // Host names are case-insensitive, so the budget must be keyed on one
    // spelling or "BT.T-RU.ORG" quietly buys a second full allowance of
    // probes -- and a 403 cooldown recorded under one spelling would not
    // stop the other.
    // Public because the fuse keys its per-host statistics on the same names
    // (RuTrackerUpdatePass::hostOf) and must not grow a second copy of this
    // rule -- BT.T-RU.ORG splitting off as its own fuse group would leave both
    // halves short of the floor.
    static public function hostKey($host)
    {
        return strtolower(rtrim((string) $host, '.'));
    }

    static private function entryFor($state, $host)
    {
        return isset($state[$host]) && is_array($state[$host]) ? $state[$host] : array();
    }

    // THE budget rule, in one place. It takes an entry that has already been
    // read, so reserveProbe()'s locked read-modify-write judges the very state
    // it is about to write while the lock-free probeDecision() judges a plain
    // load() -- neither carries its own copy of the rule. They used to, and
    // the copies could disagree silently: the tests assert the budget almost
    // entirely through probeDecision(), which production does not call, so a
    // drift in reserveProbe() alone would have left every one of them green.
    //
    // @return array(decision, windowStart, count) -- the window the answer was
    //         computed against, so a caller that takes the slot writes back
    //         that same window instead of re-deriving it.
    // conf.php promises out-of-range configuration is clamped where it is
    // read. These two had no upper bound at all.
    //
    // The cap is probes per window per announce host. RuTracker started
    // answering 403 after roughly forty announces when the design measured it
    // (design 2.6), and the shipped default of 10 is that measurement with a
    // 4x margin. A value above the measured limit does not buy more
    // confirmations, it buys the refusal the cooldown below exists to avoid.
    const PROBE_CAP_MAX = 40;

    // Seconds between probes. With the cap at 10 a one-minute pause already
    // spends ten minutes of the hour on a single host, so past that the value
    // stops being a politeness setting and starts being a stalled cycle.
    const PROBE_PAUSE_MAX = 60;

    // Floored at 0, not 1: zero is a meaningful setting here, the same way
    // $updateInterval = 0 means "disabled" -- judge() answers 'cap' for every
    // probe once the allowance is nothing, which is how layer 2 is switched off
    // per host without touching $rutrackerLayer2Enabled.
    static public function probeCap($configured)
    {
        return min(self::PROBE_CAP_MAX, max(0, (int) $configured));
    }

    static public function probePause($configured)
    {
        return min(self::PROBE_PAUSE_MAX, max(0, (int) $configured));
    }

    static private function judge($entry, $now, $cap, $window)
    {
        if ($now <= (int) ($entry['cooldown_until'] ?? 0)) return array('cooldown', 0, 0);

        $windowStart = (int) ($entry['window_start'] ?? 0);
        $count = (int) ($entry['window_count'] ?? 0);
        if ($windowStart === 0 || $now - $windowStart >= $window) {
            $windowStart = $now;
            $count = 0;
        }
        return array($count < $cap ? 'allow' : 'cap', $windowStart, $count);
    }

    // Read-only: why this host may or may not be probed right now, WITHOUT
    // taking a slot. Production does not decide through this -- reserveProbe()
    // below does, because deciding and taking must be one locked write -- so
    // it exists for diagnostics and for the tests that have to ask the same
    // question repeatedly without spending the budget they are measuring.
    static public function probeDecision($host, $now, $cap, $window)
    {
        $host = self::hostKey($host);
        $window = max((int) $window, self::MIN_WINDOW);
        $judged = self::judge(self::entryFor(RuTrackerState::load('announce'), $host), $now, $cap, $window);
        return $judged[0];
    }

    // Decide AND take the slot in one locked read-modify-write. probeDecision()
    // above only reads, and the request it authorises happens seconds later
    // (a paced sleep, then the announce itself), so two cycles asking at the
    // same moment both saw the last free slot and both spent it. Production
    // asks HERE, and the answer it returns is also what names the refusing
    // budget in trackers/rutracker.php's log line.
    //
    // @return 'allow' (slot taken), 'cap', 'cooldown' (nothing taken), or
    //         'unstorable' (the slot could not be recorded -- see below)
    static public function reserveProbe($host, $now, $cap, $window)
    {
        $host = self::hostKey($host);
        $window = max((int) $window, self::MIN_WINDOW);
        $decision = 'allow';
        $stored = RuTrackerState::update('announce', function ($state) use ($host, $now, $cap, $window, &$decision) {
            $entry = self::entryFor($state, $host);
            list($decision, $windowStart, $count) = self::judge($entry, $now, $cap, $window);
            if ($decision !== 'allow') return $state;

            $entry['window_start'] = $windowStart;
            $entry['window_count'] = $count + 1;
            $state[$host] = $entry;
            return $state;
        });

        // A budget that cannot be written is a budget of zero. The slot is
        // only spent once it is on disk; if the store refused -- an
        // unwritable settings directory, a full disk, a lock that could not
        // be opened -- then nothing is holding it, the next process reads the
        // same untouched allowance, and the cap stops capping exactly when
        // the machine is least healthy. So the refusal is the answer, not a
        // detail to be logged under an 'allow'.
        if ($decision === 'allow' && !$stored) {
            if (class_exists('ruTrackerChecker'))
                ruTrackerChecker::logDebug('announce: the budget for ' . $host
                    . ' could not be written, so the slot is refused rather than spent unrecorded');
            return 'unstorable';
        }
        return $decision;
    }

    // Gives back a slot reserveProbe() took for a request that never went
    // out. Without it a probe abandoned after the reservation silently
    // shrinks the window's budget.
    //
    // The second argument is the moment the slot was RESERVED, not "now".
    // window_start only ever moves forward, so a start later than the
    // reservation means a fresh window has opened in between and the slot
    // being handed back does not exist in the current count -- refunding it
    // there would give the new window an extra probe out of thin air.
    static public function releaseProbe($host, $reservedAt)
    {
        $host = self::hostKey($host);
        RuTrackerState::update('announce', function ($state) use ($host, $reservedAt) {
            if (!isset($state[$host]) || !is_array($state[$host])) return $state;
            if ((int) ($state[$host]['window_start'] ?? 0) > (int) $reservedAt) return $state;
            $count = (int) ($state[$host]['window_count'] ?? 0);
            if ($count > 0) $state[$host]['window_count'] = $count - 1;
            return $state;
        });
    }

    // What the probe answered. The slot is already spent by reserveProbe();
    // this only moves the 403 cooldown.
    //
    // The third argument is the HTTP status, not a "was it a 403" boolean.
    // The reset below asserts that the host is serving us again, and only a
    // real 200 says that: a 429, a 5xx, or the negative status Snoopy reports
    // for a connect/read failure are not refusals worth doubling a cooldown
    // for, but they are no evidence the refusal has lifted either. Read as
    // successes they wiped cooldown_length, so the next genuine 403 started
    // the backoff over at one hour instead of continuing to double.
    static public function recordOutcome($host, $now, $status)
    {
        $host = self::hostKey($host);
        $status = (int) $status;
        // Anything that is neither a refusal nor a served answer leaves the
        // host's record exactly as it was -- including untouched on disk.
        if ($status !== 403 && $status !== 200) return;
        RuTrackerState::update('announce', function ($state) use ($host, $now, $status) {
            $entry = isset($state[$host]) && is_array($state[$host]) ? $state[$host] : array();

            if ($status === 403) {
                $previous = (int) ($entry['cooldown_length'] ?? 0);
                $length = min(86400, max(3600, $previous * 2));
                $entry['cooldown_until'] = $now + $length;
                $entry['cooldown_length'] = $length;
            } elseif ($now > (int) ($entry['cooldown_until'] ?? 0)) {
                // A success resets the doubling -- but only against a cooldown
                // that has already lapsed. One installed while this probe was
                // in flight belongs to a 403 the tracker answered LATER than
                // the request this success came from, and that is the fresher
                // fact about the host.
                $entry['cooldown_until'] = 0;
                $entry['cooldown_length'] = 0;
            }

            $state[$host] = $entry;
            return $state;
        });
    }

    static public function makePeerId()
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $tail = '';
        for ($i = 0; $i < 12; $i++)
            $tail .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        return self::PEER_PREFIX . $tail;
    }

    static public function buildUrl($announceUrl, $hash, $peerId, $port, $key)
    {
        if (!is_string($hash) || !preg_match('/^[0-9A-Fa-f]{40}$/', $hash)) return null;
        $parts = @parse_url((string) $announceUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'], $parts['path'])) return null;

        // Rebuild scheme+host+path only: an existing query string (RuTracker
        // announce URLs carry ?pk=<passkey>) must never reach the probe.
        $base = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '') . $parts['path'];
        return $base . '?info_hash=' . rawurlencode(hex2bin($hash))
            . '&peer_id=' . rawurlencode($peerId)
            . '&port=' . (int) $port
            . '&uploaded=0&downloaded=0&left=0&compact=1&numwant=0'
            . '&event=stopped&key=' . rawurlencode($key);
    }

    static public function classify($status, $body)
    {
        if ((int) $status !== 200 || !is_string($body) || $body === '') return 'uncertain';

        // Bencode dictionaries start with 'd'. Reject any other top-level
        // type (lists, ints, raw strings) before decoding: an empty list
        // ('le') and an empty dictionary ('de') both decode to the same PHP
        // array(), so the distinction can only be made on the raw bytes.
        if ($body[0] !== 'd') return 'uncertain';
        // A legitimate announce reply to THIS probe is a few hundred bytes:
        // numwant=0 and compact=1 mean no peer list, so the answer is a
        // handful of integers or a failure reason. The decoder is a
        // recursive-descent parser inheriting Torrent's, with no depth bound
        // of its own, and its worst case is a body that is nothing but
        // nesting -- roughly one frame per two bytes. Memory exhaustion there
        // is a fatal error, which the catch below cannot stop, so the size
        // cap IS the depth bound and has to be set as one: 8 KiB admits at
        // most ~4k levels, a few megabytes of frames, which survives even the
        // 8M memory_limit of a small install. The previous 64 KiB allowed
        // ~32k levels and did not.
        if (strlen($body) > self::MAX_ANNOUNCE_BODY) return 'uncertain';

        try {
            $decoded = (new RuTrackerBencodeDecoder())->decode($body);
        } catch (Exception $e) {
            return 'uncertain';
        }
        if (!is_array($decoded)) return 'uncertain';
        // Same 2026-08-07 measurement (see UNREGISTERED_FAILURE_REASON):
        // the two never-before-seen hashes probed alongside the ones above
        // ALSO came back with no failure reason -- just a short interval
        // (328-479s, vs. 3600s for a known-good hash). So "no failure
        // reason" alone does not prove the tracker has ever heard of this
        // hash, only that it isn't reporting a failure for it right now.
        // This never bites in production -- the plugin only ever probes
        // hashes its own client is seeding, i.e. hashes the tracker already
        // knows -- but classify() has no way to tell the two cases apart
        // from the response body alone, so do not assume otherwise here.
        if (!array_key_exists('failure reason', $decoded)) return 'registered';

        $reason = $decoded['failure reason'];
        if (!is_string($reason) || $reason !== self::UNREGISTERED_FAILURE_REASON) return 'uncertain';
        return 'unregistered';
    }
}
