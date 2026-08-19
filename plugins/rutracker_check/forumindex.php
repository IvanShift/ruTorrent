<?php

require_once( __DIR__ . '/state.php' );

// Layer 3 of the post-API design: topic_id -> forum_id resolution and the
// per-forum static dump (api.rutracker.cc/v1/static/pvc/f/{forum_id}).
class RuTrackerForumIndex
{
    const FEED_URL = 'https://feed.rutracker.cc/atom/f/0.atom';
    const DUMP_URL = 'https://api.rutracker.cc/v1/static/pvc/f/';
    const TREE_URL = 'https://api.rutracker.cc/v1/static/cat_forum_tree';

    // How long a cached forum dump survives once nothing confirms it's
    // still wanted (a fresh 200 or a confirming 304 both count). Keeps
    // forumindex.json bounded to the forums actually in play, since a 304
    // has no body and the cached parse is the only way fetchDump can ever
    // answer for that forum again.
    const DUMP_RETENTION = 30 * 86400;

    // Sweep politeness: the pause each real dump request pays, and how many
    // CONSECUTIVE fetch failures end the crawl as transient (see sweep()).
    const SWEEP_PAUSE_US = 250000;
    const SWEEP_FAILURE_ABORT = 5;

    // tor_status values that mean "a normal, live topic" -- everything else
    // is checking, absorbed, closed or a duplicate. A public
    // static property rather than a const so other classes read it as
    // RuTrackerForumIndex::$VALID_STATUSES, matching this file's own style.
    static public $VALID_STATUSES = array(0, 2, 3, 8, 10);

    static public function parseFeed($xml)
    {
        if (!is_string($xml) || $xml === '') return array();
        // ext-simplexml is a recommended, not required, extension for
        // ruTorrent itself (env_check.php), so its absence must degrade to
        // "the feed found nothing" -- the chk-forum cache and the sweep
        // already cover a feed that answers nothing -- rather than fatal the
        // whole hourly cycle. plugin.info declares the warning.
        if (!function_exists('simplexml_load_string')) return array();
        $prevErrors = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($prevErrors);
        if ($doc === false || !isset($doc->entry)) return array();

        $map = array();
        foreach ($doc->entry as $entry) {
            $topic = null;
            if (isset($entry->link['href'])
                && preg_match('/[?&]t=(\d+)/', (string) $entry->link['href'], $m))
                $topic = (int) $m[1];
            $forum = null;
            if (isset($entry->category['term'])
                && preg_match('/^f-(\d+)$/', (string) $entry->category['term'], $m))
                $forum = (int) $m[1];
            if ($topic === null || $forum === null) continue;
            $map[$topic] = array(
                'forum' => $forum,
                'updated' => strpos((string) $entry->title, '[Обновлено]') !== false,
            );
        }
        return $map;
    }

    static public function parseDump($json)
    {
        $data = is_string($json) ? @json_decode($json, true) : null;
        if (!is_array($data) || !isset($data['result']) || !is_array($data['result'])
            || !isset($data['format']['topic_id']) || !is_array($data['format']['topic_id']))
            return array();

        $columns = array_flip($data['format']['topic_id']);
        if (!isset($columns['tor_status'], $columns['info_hash'], $columns['seeders'])) return array();

        $rows = array();
        foreach ($data['result'] as $topicId => $row) {
            if (!is_array($row)) continue;
            $rows[(int) $topicId] = array(
                'tor_status' => (int) ($row[$columns['tor_status']] ?? -1),
                'info_hash' => strtoupper((string) ($row[$columns['info_hash']] ?? '')),
                'seeders' => (int) ($row[$columns['seeders']] ?? 0),
            );
        }
        return $rows;
    }

    // A bare Snoopy client sized for a dump fetch: ruTrackerChecker::makeClient()'s
    // 5s timeout is too short for a multi-megabyte dump, so both fetchDump()
    // and sweep()'s default fetcher build their own client instead, sharing
    // this constructor so the two stay consistent.
    static private function makeDumpClient()
    {
        $client = new Snoopy();
        $client->read_timeout = 30;
        $client->_fp_timeout = 30;
        $client->agent = ruTrackerChecker::USER_AGENT;
        return $client;
    }

    // Per-process memo, keyed by forum id: production runs one PHP process
    // per cycle (update.php, batch_check.php), so process lifetime IS cycle
    // lifetime, and this makes the design's "at most one dump fetch per
    // forum per cycle" hold even when several candidates in the same cycle
    // share a forum -- without it, N candidates cost N conditional GETs and
    // N full state-file rewrites. Only covers the $client===null (default,
    // production) path; a caller-supplied $client always gets a real fetch,
    // exactly as before. Reset via reflection between simulated "cycles" in
    // tests the same way other per-process statics in this suite are.
    private static $memo = array();

    // GET the per-forum static dump, sending If-None-Match from the cached
    // ETag. 200 -> parse, cache and return the rows; 304 -> 'unchanged'
    // (the caller reads the rows back with cachedDump()); anything else
    // (non-200, no body, unparseable JSON) -> null. $client lets a caller
    // supply an already-configured Snoopy instead of the default one built
    // here.
    static public function fetchDump($forumId, $client = null)
    {
        $forumId = (int) $forumId;
        $memoize = ($client === null);
        // array_key_exists, not isset(): a remembered null (fetch failed
        // this cycle) must still short-circuit the next candidate in the
        // same forum rather than be mistaken for "never tried".
        if ($memoize && array_key_exists($forumId, self::$memo))
            return self::$memo[$forumId];

        if ($client === null) {
            $client = self::makeDumpClient();
            // A plain read, not a locked update(): only the cached ETag is
            // needed before the fetch, and this must not hold the state
            // lock across the request below.
            $cached = RuTrackerState::load('forumindex');
            $etag = $cached['etags'][$forumId] ?? null;
            if (is_string($etag) && $etag !== '')
                $client->rawheaders = array('If-None-Match' => $etag);
        }

        // The fetch -- up to 30s, see makeDumpClient() -- runs with NO
        // state lock held. Only the plain read above and the short locked
        // update() calls below bracket it; holding the lock across the
        // fetch would block every other writer (update.php, its detached
        // forumcrawl.php, a concurrent batch_check.php) for the whole
        // request, and is exactly the window that used to let a stale
        // pre-fetch snapshot clobber whatever those writers persisted while
        // this fetch was in flight.
        @$client->fetchComplex(self::DUMP_URL . $forumId);

        if ((int) $client->status === 304) {
            RuTrackerState::update('forumindex', function ($state) use ($forumId) {
                return self::touchDump($state, $forumId);
            });
            return self::remember($memoize, $forumId, 'unchanged');
        }
        if ((int) $client->status !== 200 || !is_string($client->results) || $client->results === '')
            return self::remember($memoize, $forumId, null);

        $rows = self::parseDump($client->results);
        if (!count($rows)) return self::remember($memoize, $forumId, null);

        $etag = isset($client->headers) ? self::headerEtag($client->headers) : '';
        // The rows live in their own per-forum document: a forum dump is the
        // one unbounded thing this plugin stores (tens of thousands of rows
        // for a big forum), and keeping it inside forumindex.json made every
        // small mutation there -- a queued topic, a miss, a sweep stamp --
        // rewrite and re-parse megabytes, with one oversized forum enough to
        // exhaust the default memory_limit on every load().
        RuTrackerState::save(self::dumpDocument($forumId), $rows);
        RuTrackerState::update('forumindex', function ($state) use ($forumId, $etag) {
            $state['etags'][$forumId] = $etag;
            return self::touchDump($state, $forumId);
        });
        return self::remember($memoize, $forumId, $rows);
    }

    static private function remember($memoize, $forumId, $value)
    {
        if ($memoize) self::$memo[$forumId] = $value;
        return $value;
    }

    static public function headerEtag($headers)
    {
        foreach ((array) $headers as $header)
            if (is_string($header) && preg_match('/^ETag:\s*(.+)$/i', trim($header), $m))
                return trim($m[1]);
        return '';
    }

    // Records that $forumId's dump was just used (fetched or 304-confirmed)
    // and drops any dump not used for longer than DUMP_RETENTION. Pure:
    // takes and returns the state to apply, rather than loading/saving on
    // its own, so every caller applies it inside a RuTrackerState::update()
    // callback (see fetchDump()) and can never persist a snapshot older
    // than what is on disk at write time.
    static private function touchDump($state, $forumId)
    {
        $now = time();
        $state['dump_touched'][$forumId] = $now;
        foreach ((array) $state['dump_touched'] as $id => $touchedAt) {
            if ($now - (int) $touchedAt > self::DUMP_RETENTION) {
                unset($state['dump_touched'][$id], $state['etags'][$id]);
                RuTrackerState::drop(self::dumpDocument((int) $id));
            }
        }
        // Dumps written before they moved to per-forum documents: the whole
        // legacy blob goes at once, it only ever duplicated what a refetch
        // reproduces.
        unset($state['dumps']);
        return $state;
    }

    static public function cachedDump($forumId)
    {
        // Reads written before the dumps moved to per-forum documents served
        // from $state['dumps']; those rows are simply refetched now (the next
        // fetchDump() repopulates the new document), so only the new location
        // is consulted.
        $rows = RuTrackerState::load(self::dumpDocument((int) $forumId));
        return count($rows) ? $rows : null;
    }

    static private function dumpDocument($forumId)
    {
        return 'forumdump-' . (int) $forumId;
    }

    // Queues $topicId for the next sweep, unless markMiss() already recorded
    // that a completed sweep looked at every forum and didn't find it more
    // recently than the suppression window: an unresolvable topic must stop
    // retriggering sweeps rather than sit in the queue
    // forever. Once the window elapses the miss is stale and the topic is
    // queueable again, so a moved/restored topic still gets a second look.
    static public function queueTopic($topicId)
    {
        $topicId = (int) $topicId;
        RuTrackerState::update('forumindex', function ($state) use ($topicId) {
            $record = $state['misses'][$topicId] ?? null;
            if ($record !== null && time() - self::missedAt($record) <= self::missWindow($record)) return $state;

            $queue = isset($state['queue']) && is_array($state['queue']) ? $state['queue'] : array();
            if (!in_array($topicId, $queue, true)) $queue[] = $topicId;
            $state['queue'] = $queue;
            return $state;
        });
    }

    static public function takeQueue()
    {
        $queue = array();
        RuTrackerState::update('forumindex', function ($state) use (&$queue) {
            $queue = isset($state['queue']) && is_array($state['queue']) ? $state['queue'] : array();
            $state['queue'] = array();
            return $state;
        });
        return $queue;
    }

    // Non-draining read of the same queue takeQueue() drains: lets a caller
    // decide whether outstanding work exists (update.php's sweep trigger)
    // without stealing what the sweep itself is about to take with takeQueue().
    static public function takeQueuePeek()
    {
        $state = RuTrackerState::load('forumindex');
        return isset($state['queue']) && is_array($state['queue']) ? $state['queue'] : array();
    }

    // Seconds between automatic full sweeps; doubles as the window that
    // suppresses a topic a completed sweep already failed to find.
    static private function sweepCooldown()
    {
        global $rutrackerSweepCooldown;
        return isset($rutrackerSweepCooldown) ? (int) $rutrackerSweepCooldown : 86400;
    }

    static public function sweepAllowed($now)
    {
        $cooldown = self::sweepCooldown();
        $state = RuTrackerState::load('forumindex');
        // No recorded sweep yet -> always allowed. Comparing against a
        // default of 0 breaks for small test timestamps ($now=1000 is not
        // "> 86400" seconds past epoch 0) even though in production $now
        // is always a real (large) Unix timestamp, so this was never
        // caught by that arithmetic; an explicit first-run check is correct
        // either way.
        if (!isset($state['last_sweep'])) return true;
        return $now - (int) $state['last_sweep'] > $cooldown;
    }

    static public function markSweep($now)
    {
        RuTrackerState::update('forumindex', function ($state) use ($now) {
            $state['last_sweep'] = (int) $now;
            return $state;
        });
    }

    // Records that a completed sweep looked at every forum and did not find
    // $topicId, so queueTopic() suppresses it until the window elapses (see
    // there). Prunes miss records older than their own window on every
    // write, mirroring touchDump()'s prune-on-write, so this map stays
    // bounded rather than growing forever.
    static public function markMiss($topicId, $now)
    {
        RuTrackerState::update('forumindex', function ($state) use ($topicId, $now) {
            $misses = isset($state['misses']) && is_array($state['misses']) ? $state['misses'] : array();
            $prior = $misses[(int) $topicId] ?? null;
            $misses[(int) $topicId] = array(
                'at' => (int) $now,
                'n' => ($prior === null ? 1 : self::missCount($prior) + 1),
            );
            foreach ($misses as $id => $record) {
                if ((int) $now - self::missedAt($record) > self::missWindow($record)) unset($misses[$id]);
            }
            $state['misses'] = $misses;
            return $state;
        });
    }

    // A topic every completed sweep keeps failing to find -- deleted from the
    // site, the very case that leaves chk-forum unresolvable -- is suppressed
    // for one cooldown after its first miss, two after its second, and so on
    // (capped), because each retry costs a crawl of every forum dump on the
    // tracker. The miss record was a bare timestamp before the counter was
    // added; missedAt()/missCount() read that legacy shape as a first miss.
    const MISS_WINDOW_CAP = 8;

    static private function missWindow($record)
    {
        return self::sweepCooldown() * min(self::MISS_WINDOW_CAP, 1 << (self::missCount($record) - 1));
    }

    static private function missCount($record)
    {
        return is_array($record) ? max(1, (int) ($record['n'] ?? 1)) : 1;
    }

    static private function missedAt($record)
    {
        return is_array($record) ? (int) ($record['at'] ?? 0) : (int) $record;
    }

    // One fleet scan shared by the feed poll (updatepass.php) and the crawl
    // below: for every torrent whose chk-topic is known but chk-forum is not
    // yet, its topic id and the hashes carrying it. Returns null when the
    // scan itself failed -- the callers must then leave their queues alone,
    // since a resolution with no hash to write to could be neither applied
    // nor safely requeued.
    static public function topicsAwaitingForum()
    {
        $req = new rXMLRPCRequest(new rXMLRPCCommand("d.multicall", array("main",
            getCmd("d.get_hash="),
            getCmd("d.get_custom=") . "chk-topic",
            getCmd("d.get_custom=") . "chk-forum")));
        $req->important = false;
        if (!$req->success()) return null;

        $rows = array();
        for ($i = 0; $i + 3 <= count($req->val); $i += 3) {
            $topic = (int) $req->val[$i + 1];
            if (!$topic || (string) $req->val[$i + 2] !== '') continue; // no topic, or forum already known
            $rows[$topic][] = (string) $req->val[$i];
        }
        return $rows;
    }

    // The crawl transaction, extracted from forumcrawl.php so its decisions
    // are testable: build the wanted set, mark the cooldown, sweep, and
    // either requeue everything (nothing was learned) or write back what was
    // found and record misses for the rest. $sweeper substitutes for sweep()
    // in tests. Returns the line for the driver to log, null when there was
    // nothing worth saying.
    static public function runCrawl($now, $sweeper = null)
    {
        // Every resolved topic is written back to chk-forum through the
        // scan -- without it there is nothing useful sweep() could produce,
        // so bail out before draining the queue or crawling: a topic
        // resolved with no known hash could be neither written nor requeued,
        // and draining the queue here would just lose whatever it held.
        $awaiting = self::topicsAwaitingForum();
        if ($awaiting === null) return null;

        // Wanted set: the explicit queue (topics an update pass couldn't
        // resolve from cache or feed) plus every torrent whose chk-topic is
        // known but chk-forum isn't -- catches anything a caller queued and
        // lost track of.
        $wanted = array_values(array_unique(array_map('intval',
            array_merge(self::takeQueue(), array_keys($awaiting)))));
        if (!count($wanted)) return null;

        // Mark the cooldown before crawling, not after: a crawl that fails
        // partway through (dead endpoint, network outage) must not be
        // retried every cycle until the next scheduled sweep is due.
        self::markSweep($now);

        // takeQueue() above already drained the persistent queue, so a topic
        // that doesn't come out of sweep() resolved would otherwise vanish.
        // sweep() returns null when the crawl couldn't even start (the tree
        // fetch failed); a thrown exception is treated the same way, since
        // either means nothing was learned about any wanted topic. Both are
        // transient, so requeue the whole wanted set and retry next
        // cooldown. Any other return is a completed crawl: write back what
        // it found, and mark everything still unresolved as a miss instead
        // of requeueing it, so a topic the crawl actually proved absent
        // stops retriggering sweeps forever (queueTopic() suppresses a
        // fresh miss on its own).
        $outcome = null;
        $error = null;
        try {
            $outcome = ($sweeper !== null) ? call_user_func($sweeper, $wanted) : self::sweep($wanted);
        } catch (Throwable $failure) {
            $error = $failure->getMessage();
        }

        if ($outcome === null) {
            foreach ($wanted as $topic) self::queueTopic($topic);
            return 'wanted ' . count($wanted) . ', crawl failed'
                . ($error !== null ? ': ' . $error : '');
        }

        $resolved = $outcome['resolved'];
        foreach ($resolved as $topic => $forum) {
            foreach (($awaiting[$topic] ?? array()) as $hash) {
                $write = new rXMLRPCRequest(new rXMLRPCCommand(
                    getCmd("d.set_custom"), array($hash, "chk-forum", (string) $forum)));
                $write->important = false;
                $write->success();
            }
        }

        // Only a complete crawl proves an absence: a topic unresolved while
        // any forum's dump went unread may simply live in the unread part,
        // and a miss recorded for it would suppress the retry for a whole
        // cooldown (doubling per repeat) on no evidence at all.
        $unresolved = array_diff($wanted, array_keys($resolved));
        if (!empty($outcome['complete'])) {
            foreach ($unresolved as $topic) self::markMiss($topic, $now);
            return 'wanted ' . count($wanted) . ', resolved ' . count($resolved);
        }
        foreach ($unresolved as $topic) self::queueTopic($topic);
        return 'wanted ' . count($wanted) . ', resolved ' . count($resolved)
            . ', ' . count($unresolved) . ' requeued: some dumps went unread';
    }

    // The full-forum crawl, layer 3's last resort: walk every
    // forum in cat_forum_tree, in the order the tree lists them, and collect
    // topic_id -> forum_id for whichever of $wantedTopics turns up, stopping
    // as soon as every one of them is found. A pure function deliberately:
    // no RuTrackerState read or write here, so it stays trivially testable
    // and callers decide what to persist. $fetcher(url) returns a response
    // body or null on any failure; production defaults to a one-off Snoopy
    // per request via makeDumpClient() (same timeout/User-Agent as
    // fetchDump()) but, unlike fetchDump(), never touches the ETag/dump
    // cache -- caching a sweep's worth of forums would blow that cache far
    // past the "forums actually in play" it's sized for.
    //
    // Return value: null means the crawl could not even start (the forum
    // tree itself couldn't be fetched or parsed) -- the caller learns
    // nothing about any of $wantedTopics and should treat this as
    // transient. Any other return is array('resolved' => topic => forum,
    // 'complete' => bool): 'resolved' is what turned up, and 'complete'
    // says whether EVERY forum's dump was actually read. Only a complete
    // crawl is the final word on the topics it didn't resolve -- a skipped
    // dump (429, timeout) is indistinguishable from "topic not in that
    // forum", so an incomplete crawl proves no absence at all.
    static public function sweep($wantedTopics, $fetcher = null)
    {
        $wanted = array();
        foreach ($wantedTopics as $topic) $wanted[(int) $topic] = true;
        if (!count($wanted)) return array('resolved' => array(), 'complete' => true);

        if ($fetcher === null) {
            $fetcher = function ($url) {
                // Politeness: the sweep is the plugin's only bulk consumer --
                // a full crawl is on the order of the tracker's whole forum
                // list -- so each real request pays a small fixed pause. The
                // pause lives in this default fetcher, not in the loop, so a
                // test-supplied fetcher stays instant.
                usleep(self::SWEEP_PAUSE_US);
                $client = self::makeDumpClient();
                @$client->fetchComplex($url);
                return ((int) $client->status === 200 && is_string($client->results)
                    && $client->results !== '') ? $client->results : null;
            };
        }

        $tree = @json_decode((string) $fetcher(self::TREE_URL), true);
        if (!is_array($tree) || !isset($tree['result']['f']) || !is_array($tree['result']['f']))
            return null;

        $resolved = array();
        $failures = 0;
        $unread = 0;
        foreach (array_keys($tree['result']['f']) as $forumId) {
            if (!count($wanted)) break;
            $body = $fetcher(self::DUMP_URL . (int) $forumId);
            if ($body === null) {
                // One missing dump is normal (forums come and go); a run of
                // them means the tracker is refusing or unreachable, and a
                // crawl that keeps knocking through hundreds more forums is
                // exactly the hammering a throttling tracker is asking to
                // stop. Abort as transient: null tells the caller nothing
                // was concluded, so every topic is requeued and retried by
                // a later, cooldown-gated sweep.
                if (++$failures >= self::SWEEP_FAILURE_ABORT) return null;
                $unread++;
                continue;
            }
            $failures = 0;
            foreach (self::parseDump($body) as $topicId => $row) {
                if (isset($wanted[$topicId])) {
                    $resolved[$topicId] = (int) $forumId;
                    unset($wanted[$topicId]);
                }
            }
        }
        return array('resolved' => $resolved, 'complete' => $unread === 0);
    }
}
