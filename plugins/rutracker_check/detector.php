<?php

// Layer 1 of the post-API design: request-free candidate detection from
// rTorrent's own per-tracker counters, plus the fleet-level fuse.
class RuTrackerDetector
{
    const TRACKER_PATTERN = '/t-ru\.org|rutracker\./i';
    const TRANSPORT_PATTERN = '/Could not resolve hostname|Could not connect|Timed? ?out/i';

    // Splits the '#'-terminated, '|'-joined tracker blob an embedded
    // t.multicall assembles (see Task 8). A row whose field count is wrong
    // (e.g. a literal '|' inside its URL broke the framing) is dropped
    // rather than guessed at.
    static public function parseTrackerBlob($blob)
    {
        $rows = array();
        foreach (explode('#', (string) $blob) as $chunk) {
            if ($chunk === '') continue;
            $fields = explode('|', $chunk);
            if (count($fields) !== 4) continue;
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
    static public function classify($rows, $dMessage)
    {
        foreach ((array) $rows as $row) {
            if (!is_array($row) || !preg_match(self::TRACKER_PATTERN, (string) ($row['url'] ?? ''))) continue;
            if (empty($row['enabled'])) return 'none';

            $failed = (int) ($row['failed'] ?? 0);
            $success = (int) ($row['success'] ?? 0);
            if ($failed === 0 && $success === 0) return 'cold';
            if ($failed === 0) return 'alive';
            if (is_string($dMessage) && $dMessage !== '' && preg_match(self::TRANSPORT_PATTERN, $dMessage))
                return 'transport';
            return 'candidate';
        }
        return 'none';
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
