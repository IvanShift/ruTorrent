<?php

require_once( __DIR__ . '/state.php' );
require_once( __DIR__ . '/launcher.php' );

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

    /** Parse a canonical base-10 topic/forum id in the fixed signed-int32 positive domain. */
    static private function parsePositiveId($value)
    {
        if (is_int($value)) {
            return ($value >= 1 && $value <= 2147483647) ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) {
            return null;
        }
        if (strlen($value) > 10 || (strlen($value) === 10 && strcmp($value, '2147483647') > 0)) {
            return null;
        }
        return (int) $value;
    }

    /** Parse a canonical base-10 counter in the portable 0..INT32_MAX domain. */
    static private function parseNonnegativeCount($value)
    {
        if (is_int($value)) {
            return ($value >= 0 && $value <= 2147483647) ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)) {
            return null;
        }
        if (strlen($value) > 10 || (strlen($value) === 10 && strcmp($value, '2147483647') > 0)) {
            return null;
        }
        return (int) $value;
    }

    /** Parse a canonical base-10 status in the portable signed-int32 domain. */
    static private function parseSignedStatus($value)
    {
        if (is_int($value)) {
            return ($value >= -2147483648 && $value <= 2147483647) ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]*|-[1-9][0-9]*)$/D', $value)) {
            return null;
        }
        if ($value[0] === '-') {
            $digits = substr($value, 1);
            if (strlen($digits) > 10 || (strlen($digits) === 10 && strcmp($digits, '2147483648') > 0)) {
                return null;
            }
            return (int) $value;
        }
        if (strlen($value) > 10 || (strlen($value) === 10 && strcmp($value, '2147483647') > 0)) {
            return null;
        }
        return (int) $value;
    }

    static public function parseFeed($xml, &$unreadable = null)
    {
        // Keep transport/schema failure distinct from a valid empty Atom feed:
        // callers may persist an ETag only for the latter. Any malformed entry
        // rejects the complete map so a valid prefix cannot hide behind an ETag.
        $unreadable = true;
        if (!is_string($xml) || $xml === '') return array();
        if (!function_exists('simplexml_load_string')) return array();

        $prevErrors = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($prevErrors);
        if ($doc === false) return array();
        if ($doc->getName() !== 'feed') return array();
        $atomNamespace = 'http://www.w3.org/2005/Atom';
        $doc->registerXPathNamespace('rutracker_atom', $atomNamespace);
        $root = $doc->xpath('self::rutracker_atom:feed');
        if (!is_array($root) || count($root) !== 1) return array();
        $atom = $doc->children($atomNamespace);

        $map = array();
        foreach ($atom->entry as $entry) {
            $topic = null;
            foreach ($entry->link as $link) {
                $href = (string) $link->attributes()->href;
                $query = @parse_url($href, PHP_URL_QUERY);
                if (!is_string($query)) continue;
                $parameters = array();
                parse_str($query, $parameters);
                if (isset($parameters['t'])) {
                    $topic = self::parsePositiveId($parameters['t']);
                    if ($topic !== null) break;
                }
            }
            $forum = null;
            foreach ($entry->category as $category) {
                if (preg_match('/^f-(.+)$/D', (string) $category->attributes()->term, $m)) {
                    $forum = self::parsePositiveId($m[1]);
                    if ($forum !== null) break;
                }
            }
            // A partial feed is not a complete reading: publishing the valid
            // prefix would let this response's ETag hide the malformed entry.
            // Duplicate topic ID also rejects the whole response.
            if ($topic === null || $forum === null || isset($map[$topic])) return array();
            $map[$topic] = array('forum' => $forum);
        }
        $unreadable = false;
        return $map;
    }

    static public function parseDump($json, &$malformed = null)
    {
        // An empty result is a valid, fully read forum; an invalid envelope or
        // row is not. Reject the entire document rather than publishing a
        // prefix which could later be mistaken for authoritative absence.
        $malformed = true;
        $data = is_string($json) ? @json_decode($json, true) : null;
        if (!is_array($data) || !isset($data['result']) || !is_array($data['result'])
            || !isset($data['format']['topic_id']) || !is_array($data['format']['topic_id']))
            return array();

        $format = $data['format']['topic_id'];
        $columns = array();
        foreach ($format as $index => $column) {
            if (!is_string($column) || $column === '' || isset($columns[$column])) return array();
            $columns[$column] = $index;
        }
        if (!isset($columns['tor_status'], $columns['info_hash'])) return array();

        $rows = array();
        foreach ($data['result'] as $topicId => $row) {
            $parsedTopic = self::parsePositiveId($topicId);
            if ($parsedTopic === null || isset($rows[$parsedTopic]) || !is_array($row)
                || count($row) !== count($format)
                || array_keys($row) !== range(0, count($row) - 1)) {
                $malformed = true;
                return array();
            }
            if (!isset($row[$columns['tor_status']])) {
                $malformed = true;
                return array();
            }
            $parsedStatus = self::parseSignedStatus($row[$columns['tor_status']]);
            if ($parsedStatus === null) {
                $malformed = true;
                return array();
            }
            $rawHash = isset($row[$columns['info_hash']]) && is_string($row[$columns['info_hash']])
                ? $row[$columns['info_hash']] : '';
            if (strlen($rawHash) !== 40 || !ctype_xdigit($rawHash)) {
                $malformed = true;
                return array();
            }
            $seeders = 0;
            if (isset($columns['seeders'])) {
                if (!array_key_exists($columns['seeders'], $row)) {
                    $malformed = true;
                    return array();
                }
                $parsedSeeders = self::parseNonnegativeCount($row[$columns['seeders']]);
                if ($parsedSeeders === null) {
                    $malformed = true;
                    return array();
                }
                $seeders = $parsedSeeders;
            }
            $rows[$parsedTopic] = array(
                'tor_status' => $parsedStatus,
                'info_hash' => strtoupper($rawHash),
                'seeders' => $seeders,
            );
        }
        $malformed = false;
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
    // ETag. 200 -> parse, cache and return the rows; 304 -> the cached rows,
    // in the answer, so the caller needs no second load; anything else
    // (non-200, no body, unreadable JSON) -> null. An EMPTY rows array is an
    // answer, never a failure. $client lets a caller
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
            // "Unchanged" is only an answer while the cached document is
            // actually there AND is the generation the ETag we presented
            // names. It can go missing (a pruned-then-restored race, a
            // hand-cleaned settings directory), and it can disagree: the
            // rows and the copy of their ETag in forumindex.json are two
            // separate writes, so two dump fetches for the same forum -- the
            // hourly cycle and a batch_check.php a click spawned, neither of
            // which excludes the other -- can interleave and leave the newer
            // ETag standing over the older body. Every later 304 would then
            // confirm the wrong generation, and the retention touch below
            // would keep it from ever being pruned. The rows carry their own
            // ETag, published by the same rename, so the two can be compared;
            // a disagreement is treated exactly like a missing document.
            $sent = is_array($client->rawheaders)
                ? (string) ($client->rawheaders['If-None-Match'] ?? '') : '';
            // ONE load for both halves. They used to be two separate reads of
            // the same multi-megabyte document, and the handler then loaded it
            // a third time because 'unchanged' told it nothing but "go look
            // yourself" -- so N candidates in one forum cost N+2 decodes of the
            // whole cache. The rows travel back in the answer instead.
            $doc = self::cachedDocument($forumId);
            if ($doc === null || $doc['etag'] !== $sent) {
                RuTrackerState::update('forumindex', function ($state) use ($forumId, $sent) {
                    // A later request may have published a newer body and
                    // hint while this 304 was in flight. Revoke only the
                    // exact hint that licensed this request.
                    if (($state['etags'][$forumId] ?? null) === $sent)
                        unset($state['etags'][$forumId]);
                    return $state;
                });
                return self::remember($memoize, $forumId, null);
            }
            RuTrackerState::update('forumindex', function ($state) use ($forumId) {
                return self::touchDump($state, $forumId);
            });
            return self::remember($memoize, $forumId, array('rows' => $doc['rows'], 'fresh' => false));
        }
        if ((int) $client->status !== 200 || !is_string($client->results) || $client->results === '')
            return self::remember($memoize, $forumId, null);

        // An EMPTY dump is an answer, not a failure: a forum that lists nothing
        // is a fact about that forum, and folding it into "unavailable" meant
        // it could never be cached, never confirm that a topic is not there,
        // and cost a full refetch every cycle. Only a document that could not
        // be understood is a non-answer, and parseDump says which is which.
        $malformed = false;
        $rows = self::parseDump($client->results, $malformed);
        if ($malformed) return self::remember($memoize, $forumId, null);

        $etag = isset($client->headers) ? self::headerEtag($client->headers) : '';
        // The rows live in their own per-forum document: a forum dump is the
        // one unbounded thing this plugin stores (tens of thousands of rows
        // for a big forum), and keeping it inside forumindex.json made every
        // small mutation there -- a queued topic, a miss, a sweep stamp --
        // rewrite and re-parse megabytes, with one oversized forum enough to
        // exhaust the default memory_limit on every load().
        // The retention claim is staked BEFORE the document is written: a
        // concurrent touchDump() prunes by dump_touched, and in the window
        // between the save and the update that records it this forum would
        // still look stale -- its brand-new document deleted, and the ETag
        // written next left pointing at nothing, so every later 304 would be
        // honoured against an empty cache.
        RuTrackerState::update('forumindex', function ($state) use ($forumId) {
            return self::touchDump($state, $forumId);
        });
        // The rows and the ETag that names them go into the document in one
        // write, so no reader can ever see one without the other. The copy
        // kept in forumindex.json below stays the cheap pre-fetch hint --
        // reading it costs nothing, while loading a multi-megabyte dump just
        // to build a conditional GET would cost plenty.
        $stored = RuTrackerState::save(
            self::dumpDocument($forumId), array('etag' => $etag, 'rows' => $rows));
        // The hint licenses a 304 on the next cycle. Publish it only when the
        // document that 304 must serve actually reached disk.
        if ($stored)
            RuTrackerState::update('forumindex', function ($state) use ($forumId, $etag) {
                $state['etags'][$forumId] = $etag;
                return $state;
            });
        return self::remember($memoize, $forumId, array('rows' => $rows, 'fresh' => true));
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

    /**
     * The cached dump document, read ONCE: its rows and the ETag that names
     * them, which are published by a single rename and therefore always agree.
     *
     * null means there is nothing usable cached -- no document, or one written
     * before the rows and their ETag shared an envelope. An empty 'rows' array
     * is a real cached answer: a forum whose dump lists nothing.
     */
    static private function cachedDocument($forumId)
    {
        $doc = RuTrackerState::load(self::dumpDocument((int) $forumId));
        if (!isset($doc['rows']) || !is_array($doc['rows'])) return null;
        return array('rows' => $doc['rows'], 'etag' => (string) ($doc['etag'] ?? ''));
    }

    static public function cachedDump($forumId)
    {
        // Reads written before the dumps moved to per-forum documents served
        // from $state['dumps']; those rows are simply refetched now (the next
        // fetchDump() repopulates the new document), so only the new location
        // is consulted. A document written before the rows and their ETag
        // shared one envelope has no 'rows' key and reads as nothing cached,
        // which sends the next 304 down the refetch path -- the same way the
        // legacy blob was retired.
        $doc = self::cachedDocument($forumId);
        return $doc === null ? null : $doc['rows'];
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
    static private function storeQueuedTopic($topicId, $newGeneration)
    {
        $topicId = (int) $topicId;
        RuTrackerState::update('forumindex', function ($state) use ($topicId, $newGeneration) {
            $record = $state['misses'][$topicId] ?? null;
            if ($record !== null && time() - self::missedAt($record) <= self::missWindow($record)) return $state;

            $queue = isset($state['queue']) && is_array($state['queue']) ? $state['queue'] : array();
            $present = in_array($topicId, $queue, true);
            if (!$present) $queue[] = $topicId;
            $state['queue'] = $queue;

            // Increment even when the topic was already queued. A crawl keeps
            // its observed queue generation durable while it runs; this
            // version lets its completion distinguish that generation from a
            // same-topic request made concurrently and leave the newer one in
            // place for the next crawl.
            // Internal retry paths use ensureQueued() below: the existing
            // generation is already durable and must not be made to look like
            // a newer outside request merely because a second crawler lost
            // the cooldown claim.
            if (!$newGeneration && $present) return $state;
            $serial = (int) ($state['queue_serial'] ?? 0) + 1;
            $versions = isset($state['queue_versions']) && is_array($state['queue_versions'])
                ? $state['queue_versions'] : array();
            $versions[$topicId] = $serial;
            $state['queue_serial'] = $serial;
            $state['queue_versions'] = $versions;
            return $state;
        });
    }

    static public function queueTopic($topicId)
    {
        self::storeQueuedTopic($topicId, true);
    }

    static private function ensureQueued($topicId)
    {
        self::storeQueuedTopic($topicId, false);
    }

    // Non-draining read of the queued topics: lets a caller
    // decide whether outstanding work exists (e.g. crawlWanted()).
    static public function takeQueuePeek()
    {
        $state = RuTrackerState::load('forumindex');
        return isset($state['queue']) && is_array($state['queue']) ? $state['queue'] : array();
    }

    // Captures both the queued topics and their generations. Legacy queue
    // entries have generation zero, which remains safe: the first later
    // queueTopic() call gives them a non-zero generation and prevents an
    // older crawl from retiring that newer request.
    static private function queueSnapshot()
    {
        $state = RuTrackerState::load('forumindex');
        $topics = isset($state['queue']) && is_array($state['queue']) ? $state['queue'] : array();
        $stored = isset($state['queue_versions']) && is_array($state['queue_versions'])
            ? $state['queue_versions'] : array();
        $versions = array();
        foreach ($topics as $topic) $versions[(int) $topic] = (int) ($stored[(int) $topic] ?? 0);
        return array('topics' => array_map('intval', $topics), 'versions' => $versions);
    }

    // Retires only queue generations observed by this crawl. Topics added
    // after the snapshot, including a repeat request for the same topic, are
    // deliberately preserved. $keep names observed topics whose result was
    // inconclusive or could not be written back.
    static private function settleQueue($snapshot, $keep = array())
    {
        $observed = array_fill_keys($snapshot['topics'], true);
        $keep = array_fill_keys(array_map('intval', $keep), true);
        RuTrackerState::update('forumindex', function ($state) use ($snapshot, $observed, $keep) {
            $queue = isset($state['queue']) && is_array($state['queue']) ? $state['queue'] : array();
            $versions = isset($state['queue_versions']) && is_array($state['queue_versions'])
                ? $state['queue_versions'] : array();
            $remaining = array();
            foreach ($queue as $topic) {
                $topic = (int) $topic;
                $currentVersion = (int) ($versions[$topic] ?? 0);
                $observedVersion = (int) ($snapshot['versions'][$topic] ?? 0);
                if (isset($observed[$topic]) && !isset($keep[$topic]) && $currentVersion === $observedVersion) {
                    unset($versions[$topic]);
                    continue;
                }
                $remaining[] = $topic;
            }
            $state['queue'] = $remaining;
            $state['queue_versions'] = $versions;
            return $state;
        });
    }

    // Seconds between automatic full sweeps; doubles as the window that
    // suppresses a topic a completed sweep already failed to find.
    static private function sweepCooldown()
    {
        global $rutrackerSweepCooldown;
        // >= 0: a negative cooldown lets every caller through, so each
        // manual click would launch its own tracker-wide crawl -- the one
        // thing this value exists to prevent. It is also the base of the
        // miss window, which would then prune records instantly.
        return max(0, isset($rutrackerSweepCooldown) ? (int) $rutrackerSweepCooldown : 86400);
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

    // Claims the crawl window, atomically, and says whether the claim was
    // this caller's. spawnCrawl()'s sweepAllowed() check is only a cheap
    // pre-filter, and it runs in the PARENT -- seconds before the detached
    // child has finished starting PHP, required check.php and read the fleet,
    // which is when the window would actually be recorded. Several manual
    // "check" clicks all pass that filter and each launches a full
    // tracker-wide crawl, the very thing the 24h cooldown exists to prevent.
    // So the window is judged and taken in ONE locked write, the same way the
    // announce budget reserves a slot rather than reading and then spending.
    //
    // @return bool -- true when this caller took the window and should crawl
    static public function markSweep($now)
    {
        $now = (int) $now;
        $cooldown = self::sweepCooldown();
        $claimed = false;
        $stored = RuTrackerState::update('forumindex', function ($state) use ($now, $cooldown, &$claimed) {
            // Same predicate as sweepAllowed(), including its explicit
            // first-run case: an absent last_sweep is not a zero.
            if (isset($state['last_sweep']) && $now - (int) $state['last_sweep'] <= $cooldown)
                return $state;
            $claimed = true;
            $state['last_sweep'] = $now;
            return $state;
        });
        // A claim nobody could write down is not a claim: the next process
        // would read the same free window and crawl too.
        return $claimed && $stored;
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
        // Persisted state can be hand-edited or survive older code with an
        // unexpectedly large count. Clamp before shifting so that input can
        // never wrap the backoff negative and disable suppression.
        $count = min(self::MISS_WINDOW_CAP, self::missCount($record));
        return self::sweepCooldown() * min(self::MISS_WINDOW_CAP, 1 << ($count - 1));
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
    // $alsoWanted: topics whose CACHED forum id is a candidate for
    // overwriting rather than a reason to skip the torrent. A topic reaches
    // that set only by being queued, and it is queued only when layer 3
    // looked in the forum chk-forum names and did not find it -- which most
    // often means the topic MOVED. Nothing else in the plugin ever rewrites
    // chk-forum, so without this a stale id stands for good: the crawl finds
    // the topic in its new forum, has no hash to write it to, books it as
    // resolved, and records no miss either -- so the handler keeps re-queueing
    // it, a full tracker crawl runs every cooldown for ever, and layer 3 keeps
    // reading the wrong forum's dump. With layer 2 confirming "unregistered"
    // for a re-uploaded topic, that path ends in a DELETED verdict for a topic
    // that plainly still exists.
    // $currentForum comes back as hash => the chk-forum this row carries right
    // now (''  when it carries none), so a caller that asked about topics whose
    // forum is ALREADY known can tell a correction from a no-op write.
    static public function topicsAwaitingForum($alsoWanted = array(), &$currentForum = null)
    {
        $currentForum = array();
        $req = new rXMLRPCRequest(new rXMLRPCCommand("d.multicall", array("main",
            getCmd("d.get_hash="),
            getCmd("d.get_custom=") . "chk-topic",
            getCmd("d.get_custom=") . "chk-forum")));
        $req->important = false;
        if (!$req->success()) return null;

        $rows = array();
        for ($i = 0; $i + 3 <= count($req->val); $i += 3) {
            $topic = (int) $req->val[$i + 1];
            if (!$topic) continue;
            // Forum already known AND nobody asked about this topic.
            if ((string) $req->val[$i + 2] !== '' && !isset($alsoWanted[$topic])) continue;
            $rows[$topic][] = (string) $req->val[$i];
            $currentForum[(string) $req->val[$i]] = (string) $req->val[$i + 2];
        }
        return $rows;
    }

    const FORUM_WRITE_FAILED = 'failed';
    const FORUM_WRITE_WRITTEN = 'written';
    const FORUM_WRITE_CURRENT = 'current';
    const FORUM_WRITE_SUPERSEDED = 'superseded';
    const FORUM_WRITE_OBSOLETE = 'obsolete';

    /**
     * Serialises the two writers of chk-forum and performs the crawl's
     * compare-and-swap under that same lock. Feed mappings are authoritative
     * and may replace the current value; a crawl may write only while both the
     * topic and the forum still match the snapshot it took before its long
     * tracker-wide sweep. An optional guard is evaluated while the same lock
     * is held, before rTorrent is read, so a durable writer can reject a stale
     * obligation generation without reopening the mapping race.
     *
     * @return string one of the FORUM_WRITE_* constants above
     */
    static public function writeForumMapping(
        $hash,
        $topicId,
        $forumId,
        $expectedForum = null,
        $authoritative = false,
        $guard = null
    )
    {
        $lock = RuTrackerState::acquireScopedLock('forum-map', $hash);
        if ($lock === false) return self::FORUM_WRITE_FAILED;
        try {
            if ($guard !== null && !call_user_func($guard))
                return self::FORUM_WRITE_SUPERSEDED;

            $read = new rXMLRPCRequest(array(
                new rXMLRPCCommand(getCmd('d.get_custom'), array($hash, 'chk-topic')),
                new rXMLRPCCommand(getCmd('d.get_custom'), array($hash, 'chk-forum')),
            ));
            $read->important = false;
            if (!$read->success() || !isset($read->val[0], $read->val[1]))
                return self::FORUM_WRITE_FAILED;

            if ((int) $read->val[0] !== (int) $topicId)
                return self::FORUM_WRITE_OBSOLETE;
            $current = (string) $read->val[1];
            if (!$authoritative && $expectedForum !== null && $current !== (string) $expectedForum)
                return self::FORUM_WRITE_SUPERSEDED;
            if ($current === (string) $forumId)
                return self::FORUM_WRITE_CURRENT;

            $write = new rXMLRPCRequest(new rXMLRPCCommand(
                getCmd('d.set_custom'), array($hash, 'chk-forum', (string) $forumId)));
            $write->important = false;
            return $write->success() ? self::FORUM_WRITE_WRITTEN : self::FORUM_WRITE_FAILED;
        } finally {
            RuTrackerState::releaseScopedLock($lock);
        }
    }

    // Whether a crawl has anything to do -- the trigger its callers use.
    // The persistent queue is the durable trigger for explicit work such as
    // correcting a moved topic. The fleet scan adds torrents whose forum is
    // still unknown, including legacy work that predates the queue.
    static public function crawlWanted()
    {
        if (count(self::takeQueuePeek())) return true;
        $awaiting = self::topicsAwaitingForum();
        return is_array($awaiting) && count($awaiting) > 0;
    }

    // Starts the background crawl when there is work and the cooldown allows.
    // BOTH entry points call it -- the hourly cycle and a manual check --
    // because the crawl otherwise has a single trigger: with the scheduler
    // disabled ($updateInterval = 0) update.php never runs, and a topic whose
    // forum is unknown could then never be resolved by any amount of clicking
    // "check". The 24h cooldown is what keeps a manual click from launching a
    // crawl per click.
    static public function spawnCrawl($launcher = null)
    {
        if (!self::sweepAllowed(time()) || !self::crawlWanted()) return false;
        $result = RuTrackerDetachedPhp::launch(
            (string) Utility::getPHP(),
            dirname(__FILE__) . '/forumcrawl.php',
            array((string) User::getUser()),
            $launcher
        );
        if ($result === false)
            ruTrackerChecker::logDebug('forumcrawl: detached crawl command was not accepted by the shell');
        return $result;
    }

    // The crawl transaction, extracted from forumcrawl.php so its decisions
    // are testable: build the wanted set, mark the cooldown, sweep, and
    // either requeue everything (nothing was learned) or write back what was
    // found and record misses for the rest. $sweeper substitutes for sweep()
    // in tests. Returns the line for the driver to log, null when there was
    // nothing worth saying.
    static public function runCrawl($now, $sweeper = null)
    {
        // Keep explicit work durable until the sweep has completed. A killed
        // crawler cannot run a finally block, and a moved topic already has a
        // chk-forum value, so the fleet scan alone cannot reconstruct a queue
        // item drained before the process died. The generation snapshot also
        // prevents completion from deleting a same-topic request queued while
        // this crawl is in flight.
        $queueSnapshot = self::queueSnapshot();
        $queued = $queueSnapshot['topics'];

        // Every resolved topic is written back to chk-forum through the scan --
        // without it there is nothing useful sweep() could produce, so bail out
        // before crawling. The queue remains untouched on this failure path.
        $currentForum = array();
        $awaiting = self::topicsAwaitingForum(array_flip($queued), $currentForum);
        if ($awaiting === null) return null;

        // Wanted set: the explicit queue (topics an update pass couldn't
        // resolve from cache or feed) plus every torrent whose chk-topic is
        // known but chk-forum isn't -- catches anything a caller queued and
        // lost track of.
        // The fleet half of the wanted set goes through the same miss backoff
        // queueTopic() applies: without it a topic no completed crawl can
        // ever find -- a deleted one, the very case the backoff exists for --
        // is re-added from the fleet every time and makes each sweep a full
        // crawl of the tracker, for ever. The explicit queue is taken as-is:
        // whatever is in it already passed the backoff on the way in.
        $state = RuTrackerState::load('forumindex');
        $now = (int) $now;
        $wanted = array();
        foreach ($queued as $topic) $wanted[$topic] = true;
        foreach (array_keys($awaiting) as $topic) {
            $record = $state['misses'][(int) $topic] ?? null;
            if ($record !== null && $now - self::missedAt($record) <= self::missWindow($record)) continue;
            $wanted[(int) $topic] = true;
        }
        $wanted = array_keys($wanted);
        if (!count($wanted)) return null;

        // Mark the cooldown before crawling, not after: a crawl that fails
        // partway through (dead endpoint, network outage) must not be
        // retried every cycle until the next scheduled sweep is due.
        //
        // And the mark is a CLAIM: if another crawl took this window while
        // this one was starting up, that crawl is already doing exactly this
        // work, so stand down and give the wanted set back rather than
        // sweeping the tracker a second time.
        if (!self::markSweep($now)) {
            foreach ($wanted as $topic) self::ensureQueued($topic);
            return 'wanted ' . count($wanted) . ', another crawl already holds this window';
        }

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
            foreach ($wanted as $topic) self::ensureQueued($topic);
            return 'wanted ' . count($wanted) . ', crawl failed'
                . ($error !== null ? ': ' . $error : '');
        }

        $resolved = $outcome['resolved'];
        $requeued = array();
        foreach ($resolved as $topic => $forum) {
            $landed = false;
            $complete = true;
            foreach (($awaiting[$topic] ?? array()) as $hash) {
                $status = self::writeForumMapping($hash, $topic, $forum,
                    (string) ($currentForum[$hash] ?? ''), false);
                if ($status === self::FORUM_WRITE_FAILED) {
                    $complete = false;
                    continue;
                }
                // A different current forum means a newer serialised writer
                // won; that is a completed outcome for this stale crawl, not a
                // reason to overwrite it or crawl again.
                if ($status !== self::FORUM_WRITE_OBSOLETE) $landed = true;
            }
            // A previous completed crawl may have recorded this topic as
            // absent. Forget that evidence only once at least one torrent
            // actually carries the recovered forum; a failed write leaves
            // the old backoff intact for the retry path.
            if ($landed)
                RuTrackerState::update('forumindex', function ($state) use ($topic) {
                    unset($state['misses'][(int) $topic]);
                    return $state;
                });
            // A resolution nobody could write down is not a resolution: the
            // whole tracker-wide crawl that produced it would be spent for
            // nothing, and the topic would be neither requeued nor marked
            // missed. Put it back in the queue instead.
            if (!$complete && isset($awaiting[$topic]) && count($awaiting[$topic])) {
                unset($resolved[$topic]);
                $requeued[] = (int) $topic;
                self::ensureQueued($topic);
                ruTrackerChecker::logDebug('forumcrawl: topic ' . (int) $topic . ' resolved to forum '
                    . (int) $forum . ' but at least one write-back failed; requeued');
            }
        }

        // Only a complete crawl proves an absence: a topic unresolved while
        // any forum's dump went unread may simply live in the unread part,
        // and a miss recorded for it would suppress the retry for a whole
        // cooldown (doubling per repeat) on no evidence at all.
        // A topic whose write-back failed is already back in the queue; it was
        // FOUND, so it is no evidence of absence and must not be marked
        // missed on top of that.
        $unresolved = array_diff($wanted, array_keys($resolved), $requeued);
        if (!empty($outcome['complete'])) {
            foreach ($unresolved as $topic) self::markMiss($topic, $now);
            self::settleQueue($queueSnapshot, $requeued);
            return 'wanted ' . count($wanted) . ', resolved ' . count($resolved);
        }
        foreach ($unresolved as $topic) self::ensureQueued($topic);
        self::settleQueue($queueSnapshot, array_merge($requeued, $unresolved));
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
    /**
     * Whether an HTTP answer to a dump request means "the tracker turned us
     * away" rather than "this forum carries no dump".
     *
     * Public and pure so the rule can be pinned by a test. It used to live
     * inside the default fetcher below, unreachable from any test, and it
     * enumerated the refusals -- transport failure, 429, 5xx -- which left
     * every other 4xx on the "no dump" side. 401, 403 and 408 then read as
     * fully known, empty territory: they did not count as unread, so a crawl
     * could return complete=true and markMiss() would record a miss for every
     * wanted topic, on forums nobody ever read. The static API is documented
     * to answer 403 to a User-Agent it dislikes (design 2.9), so one tightened
     * filter on the tracker's side was enough to trigger it wholesale.
     *
     * So the rule is inverted: only the two statuses that genuinely mean "no
     * such document" are not refusals, and anything else unrecognised is.
     */
    static public function dumpRefused($status)
    {
        $status = (int) $status;
        // 404 is the ordinary answer for a forum that carries no torrent dump
        // at all -- a category container, an archive -- and RuTracker's tree is
        // full of them; reporting those as refusals is what used to abort a
        // crawl a handful of forums in. 410 says the same thing more firmly.
        if ($status === 404 || $status === 410) return false;
        return $status !== 200;
    }

    /**
     * The other half of the same rule: what one dump ANSWER amounts to, as the
     * two things the sweep needs -- a body to parse, or null, and whether the
     * forum is left UNREAD.
     *
     * It lived inside the default fetcher, where dumpRefused()'s verdict was
     * taken for the whole answer, and a 200 carrying an empty body therefore
     * read as "the tracker answered: this forum has no dump" -- fully known,
     * empty territory. fetchDump() has always called that same answer
     * unavailable (see its status check), and it is the answer a truncated
     * response or an interstitial gives. Counted as known, it lets a crawl
     * return complete=true and markMiss() record a miss for topics nobody ever
     * looked for, and it resets the refusal counter, so a site answering 200
     * with nothing for every forum could never trip the abort.
     *
     * Public and pure so the rule can be pinned, and used by the fetcher below
     * so there is one rule rather than two copies of it.
     */
    static public function dumpAnswer($status, $body, &$refused = null)
    {
        $status = (int) $status;
        $refused = self::dumpRefused($status);
        if (!is_string($body) || $body === '') {
            // Only a 200 needs correcting here: for every other status
            // dumpRefused() has already said which side it is on.
            if ($status === 200) $refused = true;
            return null;
        }
        return $status === 200 ? $body : null;
    }

    static public function sweep($wantedTopics, $fetcher = null)
    {
        $wanted = array();
        foreach ($wantedTopics as $topic) $wanted[(int) $topic] = true;
        if (!count($wanted)) return array('resolved' => array(), 'complete' => true);

        if ($fetcher === null) {
            $fetcher = function ($url, &$refused = null) {
                // Politeness: the sweep is the plugin's only bulk consumer --
                // a full crawl is on the order of the tracker's whole forum
                // list -- so each real request pays a small fixed pause. The
                // pause lives in this default fetcher, not in the loop, so a
                // test-supplied fetcher stays instant.
                usleep(self::SWEEP_PAUSE_US);
                $client = self::makeDumpClient();
                @$client->fetchComplex($url);
                return self::dumpAnswer($client->status, $client->results, $refused);
            };
        }

        $tree = @json_decode((string) $fetcher(self::TREE_URL), true);
        if (!is_array($tree) || !isset($tree['result']['f']) || !is_array($tree['result']['f']))
            return null;

        $forumKeys = array_keys($tree['result']['f']);
        if (count($forumKeys) === 0) {
            return null;
        }

        $validatedForumIds = array();
        $seenForumIds = array();
        foreach ($forumKeys as $key) {
            $forumId = self::parsePositiveId($key);
            if ($forumId === null || isset($seenForumIds[$forumId])) {
                return null;
            }
            $seenForumIds[$forumId] = true;
            $validatedForumIds[] = $forumId;
        }

        $resolved = array();
        $failures = 0;
        $unread = 0;
        foreach ($validatedForumIds as $forumId) {
            if (!count($wanted)) break;
            $refused = false;
            $body = $fetcher(self::DUMP_URL . $forumId, $refused);
            if ($body === null) {
                // A forum with no dump is FULLY KNOWN territory: most of the
                // tree is categories and archives that have none, the tracker
                // answered, and there is nothing there to have missed. It must
                // not count as unread -- counting it did, and since a real
                // tree is mostly such forums, every crawl came back incomplete
                // and markMiss() could never run. That made the whole miss
                // backoff dead code for the case it was written for: a deleted
                // topic no crawl can ever resolve kept re-triggering a
                // ~1500-forum walk every cooldown, for ever.
                //
                // A REFUSAL is the opposite -- unknown territory, the dump may
                // exist and may hold a wanted topic -- so it does count, and a
                // run of them means the tracker is turning us away: abort as
                // transient, and null tells the caller nothing was concluded.
                if ($refused) {
                    if (++$failures >= self::SWEEP_FAILURE_ABORT) return null;
                    $unread++;
                    continue;
                }
                // The tracker ANSWERED -- it said this forum has no dump. The
                // counter measures refusals in a row, so an answer ends the
                // run. Without this reset only a parsed dump cleared it, and a
                // tree where refusals are separated by ordinary 404s (the
                // common shape, since most forums have no dump) accumulated
                // across the gaps until an abort the tracker never earned.
                $failures = 0;
                continue;
            }
            // A 200 that is not a dump is unknown territory too, exactly as
            // fetchDump() already treats it. Without this it read as a fully
            // read, empty forum -- and, worse, reset the refusal counter, so a
            // block page served as 200 for every forum could never trip the
            // abort.
            $malformed = false;
            $rows = self::parseDump($body, $malformed);
            if ($malformed) {
                if (++$failures >= self::SWEEP_FAILURE_ABORT) return null;
                $unread++;
                continue;
            }
            $failures = 0;
            foreach ($rows as $topicId => $row) {
                if (isset($wanted[$topicId])) {
                    $resolved[$topicId] = (int) $forumId;
                    unset($wanted[$topicId]);
                }
            }
        }
        return array('resolved' => $resolved, 'complete' => $unread === 0);
    }
}
