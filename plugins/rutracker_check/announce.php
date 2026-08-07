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
    // inconclusive; see classify()'s $expectedFailure parameter.
    const UNREGISTERED_FAILURE_REASON = 'Torrent not registered';

    // Floor for the announce cap's window (allowProbe()/recordProbe()'s
    // $window, seconds), mirroring RuTrackerCheckImpl::MIN_DELETE_INTERVAL
    // (trackers/rutracker.php): conf.php documents $updateInterval = 0 as
    // "disable the scheduler", but a manual batch_check.php click still
    // computes the window as $updateInterval * 60 -- i.e. 0 -- and without a
    // floor every click would open and instantly close its own window,
    // buying a fresh cap each time. Floored at the smallest legitimate
    // non-zero scheduler interval (1 minute; see plugins/scheduler/conf.php's
    // own "1-6,10,12,15,20,30 or 60" minutes).
    const MIN_WINDOW = 60;

    // The per-host announce budget -- a windowed probe count (window_start +
    // window_count) plus the 403 cooldown -- lives entirely in the
    // persisted 'announce' state (RuTrackerState, state.php), keyed one
    // entry per host. Persisting the count, not just the cooldown, is what
    // makes the cap hold across every process that probes: the hourly
    // update.php pass, its detached forumcrawl.php, and a fresh
    // batch_check.php per manual "check" click all share the same file, so
    // ten manual clicks share one window's budget instead of each buying
    // its own the way an in-memory-only counter used to.
    //
    // Formerly cleared an in-memory per-cycle counter, which was correct
    // for the hourly update.php pass (one process per cycle) but meaningless
    // for a manual click, where a fresh process meant a fresh counter every
    // time. The windowed counter above replaces it: it expires itself once
    // $window elapses, so there is nothing left here to reset. Kept as a
    // no-op, rather than removed, so update.php's per-cycle call site does
    // not need to change and so a test can assert directly that calling
    // this no longer voids the cap.
    static public function resetCycle()
    {
    }

    static public function allowProbe($host, $now, $cap, $window)
    {
        $window = max((int) $window, self::MIN_WINDOW);
        $state = RuTrackerState::load('announce');
        $entry = isset($state[$host]) && is_array($state[$host]) ? $state[$host] : array();

        $until = (int) ($entry['cooldown_until'] ?? 0);
        if ($now <= $until) return false;

        $windowStart = (int) ($entry['window_start'] ?? 0);
        $count = ($windowStart !== 0 && $now - $windowStart < $window) ? (int) ($entry['window_count'] ?? 0) : 0;
        return $count < $cap;
    }

    static public function recordProbe($host, $now, $got403, $window)
    {
        $window = max((int) $window, self::MIN_WINDOW);
        RuTrackerState::update('announce', function ($state) use ($host, $now, $got403, $window) {
            $entry = isset($state[$host]) && is_array($state[$host]) ? $state[$host] : array();

            $windowStart = (int) ($entry['window_start'] ?? 0);
            $count = (int) ($entry['window_count'] ?? 0);
            if ($windowStart === 0 || $now - $windowStart >= $window) {
                $windowStart = $now;
                $count = 0;
            }
            $entry['window_start'] = $windowStart;
            $entry['window_count'] = $count + 1;

            if ($got403) {
                $previous = (int) ($entry['cooldown_length'] ?? 0);
                $length = min(86400, max(3600, $previous * 2));
                $entry['cooldown_until'] = $now + $length;
                $entry['cooldown_length'] = $length;
            } else {
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

    static public function classify($status, $body, $expectedFailure = null)
    {
        if ((int) $status !== 200 || !is_string($body) || $body === '') return 'uncertain';

        // Bencode dictionaries start with 'd'. Reject any other top-level
        // type (lists, ints, raw strings) before decoding: an empty list
        // ('le') and an empty dictionary ('de') both decode to the same PHP
        // array(), so the distinction can only be made on the raw bytes.
        if ($body[0] !== 'd') return 'uncertain';

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
        if (!is_string($reason) || $reason === '') return 'uncertain';
        if ($expectedFailure !== null && $reason !== $expectedFailure) return 'uncertain';
        return 'unregistered';
    }
}
