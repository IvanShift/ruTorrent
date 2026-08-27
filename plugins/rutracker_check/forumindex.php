<?php

require_once( __DIR__ . '/state.php' );
require_once( __DIR__ . '/launcher.php' );
require_once( __DIR__ . '/runstate.php' );

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
                    $topic = RuTrackerRpcValue::canonicalPositiveInt32($parameters['t']);
                    if ($topic !== null) break;
                }
            }
            $forum = null;
            foreach ($entry->category as $category) {
                if (preg_match('/^f-(.+)$/D', (string) $category->attributes()->term, $m)) {
                    $forum = RuTrackerRpcValue::canonicalPositiveInt32($m[1]);
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
            $parsedTopic = RuTrackerRpcValue::canonicalPositiveInt32($topicId);
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
            $parsedStatus = RuTrackerRpcValue::canonicalSignedInt32($row[$columns['tor_status']]);
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
                $parsedSeeders = RuTrackerRpcValue::canonicalNonnegativeInt32($row[$columns['seeders']]);
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
    // A persisted counter as this state file stores it, or null when the
    // stored bytes cannot be believed. An ABSENT key is the fresh zero --
    // there was nothing to read -- while a key that IS there and does not
    // spell a canonical nonnegative integer is corruption. A bare (int)
    // answered 0 for both, and 0 was the dangerous reading at every counter
    // that uses this: a lapsed sweep cooldown, an unclaimed dump generation,
    // a queue ordering guard restarted underneath a running crawl.
    static private function storedCount($container, $key)
    {
        if ($container === null) return 0;
        if (!is_array($container)) return null;
        if (!array_key_exists($key, $container)) return 0;
        return RuTrackerRpcValue::canonicalNonnegativeInteger($container[$key]);
    }

    // Settles one of this document's per-forum BOOKS -- the container, not the
    // entry storedCount() reads out of it -- into something an entry can be
    // read from and written into. Every READ here is already refused for a
    // book that is not an array, because storedCount() answers null for one; a
    // WRITE has no such refusal, and that asymmetry is the whole of this.
    // `$state[$book][$id] = ...` into a scalar book is an uncaught Error on
    // PHP 8, and into a STRING book on the PHP 7.4 target it silently swallows
    // the value's first byte and pads the string to length -- a forum that
    // refetches a multi-megabyte dump every cycle, publishes none of them,
    // answers nothing from cache, and writes not one log line. unset() on
    // either shape is an Error on both. So the book is settled BEFORE anything
    // touches an entry in it.
    //
    // An ABSENT book and a NULL one are left exactly as they are: both ARE the
    // clean empty book -- every reader here reads them as empty and PHP
    // vivifies both on write -- and repairing well-formed input is the mistake
    // in the other direction.
    //
    // Anything else holds no entry any reader can see, for $subject or for
    // anything else it was holding, so starting it again loses nothing that
    // was readable. Nor can it weaken the reservation invariant: a counter or
    // a token no reader can see is no ordering guard, and a request in flight
    // over such a book already fails its publish gate on both halves.
    //
    // The replacement is ANNOUNCED. A book quietly repaired underneath an
    // operator is only the better half of a defect whose worse half was a
    // forum going dark with nothing in the log at all.
    static private function seatBook(&$state, $book, $subject)
    {
        if (!array_key_exists($book, $state)) return;
        if ($state[$book] === null || is_array($state[$book])) return;
        ruTrackerChecker::logDebug('forumindex: forumindex.json ' . $book . ' is not an array but a'
            . ' value of type ' . gettype($state[$book]) . ', so no entry in it can be read or'
            . ' written for ' . $subject
            . ' or for anything else it was holding; the whole book is discarded and started again,'
            . ' rather than left to refuse every write into it -- fatally on PHP 8, and on PHP 7.4'
            . ' silently, which is a dump refetched and thrown away every cycle for ever');
        $state[$book] = array();
    }

    // Whether the reservation a request is holding is still the current one.
    // One shared predicate so the 304 and 200 publish paths cannot drift apart
    // on it.
    //
    // The TOKEN is what holds the invariant. It is a per-request nonce, and it
    // is here because the NUMBER alone stopped being able to: retiring a
    // forum's corrupt counters (see fetchDump()) has to free the numbers those
    // counters were holding, and a request already inside the lock-free fetch
    // window is holding one of them -- so an integer that both it and its
    // successor can present separates them not at all. The token is compared
    // for IDENTITY: === refuses an absent entry, a retired one, a newer
    // request's, and any value that is not a string, all alike. == would not:
    // on the PHP 7.4 target a stored integer equal to getmypid() compares
    // equal to a token beginning "<pid>-", because PHP 7 casts that
    // non-numeric string to an int and gets the pid back, and a stored true
    // compares equal to any non-empty string on every version.
    //
    // The NUMBER is not a second identity and is no longer claimed to be one.
    // It is the DEPTH this reservation was issued at: which generation the
    // body is published AS, and which name versionedDumpDocument() gives it.
    // Comparing it refuses what the token cannot see -- a reservations book
    // replaced underneath this request, an entry hand-edited, and an entry one
    // byte of which stopped reading, since storedCount() answers null for all
    // three and null matches no reservation ever issued. Both are compared,
    // and neither is redundant in the shapes the other is blind to.
    //
    // A caller that fails this lost the forum -- to a newer reservation or to
    // a retirement -- and publishes nothing, which costs one refetch and no
    // correctness. dump_tokens is retired exactly where dump_reservations is,
    // which is to say by a retirement and never by retention: pruning it under
    // a request in flight would only make that request refetch for nothing.
    static private function holdsReservation($state, $forumId, $reservation, $token)
    {
        return self::storedCount($state['dump_reservations'] ?? null, $forumId) === $reservation
            && ($state['dump_tokens'][$forumId] ?? null) === $token;
    }

    // The generation a published document NAMES. versionedDumpDocument() is
    // the only writer of that shape, so the suffix is the exact spelling the
    // publication used; the legacy unversioned name, and anything hand-edited,
    // name no generation at all and answer null.
    static private function documentGeneration($document, $forumId)
    {
        $prefix = self::dumpDocument($forumId) . '-';
        if (!is_string($document) || strncmp($document, $prefix, strlen($prefix)) !== 0)
            return null;
        return RuTrackerRpcValue::canonicalNonnegativeInteger(substr($document, strlen($prefix)));
    }

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

        // Reserve before I/O. The reservation is the request's authority to
        // publish: a later request advances it before either response can
        // arrive, so response order and repeated/absent ETags cannot make an
        // older body current again. A reservation that did not reach disk buys
        // no request -- otherwise another process could reuse the same number.
        // It has two halves, and both publish gates below compare both: the
        // NUMBER, which orders it against every other reservation, and a
        // per-request TOKEN, which identifies it. See holdsReservation().
        $reservation = null;
        $token = null;
        $cachedEtag = '';
        $retired = null;
        $reserved = RuTrackerState::update('forumindex', function ($state) use (
            $forumId,
            &$reservation,
            &$token,
            &$cachedEtag,
            &$retired
        ) {
            // dump_gen is the migration path from the first incomplete
            // generation attempt. A counter that will not READ cannot be
            // counted from -- the number after it is unknowable -- and (int)'s
            // 0 handed out a reservation an older body could still publish
            // under, so THIS request is not made. But every writer is gated
            // behind this same read and cachedDocument() refuses the cached
            // body for the same reason, so merely refusing left the forum dark
            // permanently and silently. These are publication bookkeeping for
            // a CACHE, not an audit trail, so the forum's corrupt entries and
            // the body they named are RETIRED and the next call starts clean.
            //
            // Every book is read BEFORE anything is decided: the floor below
            // is only a floor if it saw all of them, and the loop that
            // returned on the first unreadable one had counted a prefix.
            $floor = 0;
            $unreadable = null;
            foreach (array('dump_reservations', 'dump_generations', 'dump_gen') as $book) {
                $seen = self::storedCount($state[$book] ?? null, $forumId);
                if ($seen === null) {
                    if ($unreadable === null) $unreadable = $book;
                    continue;
                }
                if ($seen > $floor) $floor = $seen;
            }
            // The published document NAMES its own generation, so it is a
            // fourth witness -- and the only one left when the reservation
            // book is itself the entry that will not read.
            $current = self::stateDumpDocument($state, $forumId);
            $named = self::documentGeneration($current, $forumId);
            if ($named !== null && $named > $floor) $floor = $named;

            if ($unreadable !== null) {
                ruTrackerChecker::logDebug('forumindex: forumindex.json ' . $unreadable . '[' . $forumId
                    . '] is not a readable counter, so no dump is fetched for forum ' . $forumId
                    . ' this cycle; its cached body and counters are retired so the next one can,'
                    . ' and the counter is reseeded above every generation still readable on disk');
                $retired = $current;
                // Seated, not SKIPPED. The old guard passed over exactly the
                // books that most needed clearing: one that is not an array
                // cannot be unset at all (an Error on both target runtimes),
                // cannot take the reseeded floor below, and cannot hold the
                // next request's token -- so a retirement whose entire promise
                // is that the next call starts clean left the forum in the one
                // state from which no next call can.
                foreach (array('dump_reservations', 'dump_generations', 'dump_gen',
                    'dump_documents', 'dump_touched', 'etags', 'dump_tokens') as $key) {
                    self::seatBook($state, $key, 'forum ' . $forumId);
                    unset($state[$key][$forumId]);
                }
                // Retiring the counters necessarily FREES the numbers they
                // were holding, and a request inside the 30s window below is
                // holding one of them. Restarting at 1 hands that very number
                // out again, which is precisely what this counter exists to
                // prevent, so the highest number anything still readable can
                // prove was issued is kept as the floor. A floor of zero IS
                // the clean absent state and is left absent. Reuse alone can
                // no longer republish anything -- the erased token above is
                // what settles that -- but a generation names a document, and
                // two bodies must never be able to share one name.
                if ($floor > 0) $state['dump_reservations'][$forumId] = $floor;
                return $state;
            }
            // Nothing can be counted past the ceiling: $floor + 1 leaves the
            // integer domain, and a float reservation reads back as no
            // canonical counter at all. So this forum fetches nothing, for
            // ever -- and it used to do that in complete silence.
            //
            // It says so instead of recovering, deliberately -- but not
            // because recovery is impossible. One exists and it keeps
            // monotonicity: run the same retirement the unreadable-counter
            // branch above runs, seating and clearing the per-forum books and
            // dropping the named document, but WITHOUT reseeding the floor.
            // Reissuing a number is not by itself a violation, because the
            // number is the depth and the erased token is what settles whether
            // anything can republish -- which is what this file says forty
            // lines up. That variant was built and measured; it works.
            //
            // It is not taken for a different reason. The ceiling is not
            // reachable by counting: at one fetch per forum per cycle it is
            // about 10^12 years away. The only way into this state is a hand
            // edit, of the counter or of a document name. Repairing that
            // automatically would quietly paper over a document somebody
            // edited by hand, and the person who edited it is the one who
            // needs to see what it did. So this branch tells them, on the
            // channel that is not gated behind a debug flag, and leaves the
            // repair to them. What was missing here was never recovery; it was
            // anybody being told.
            //
            // Every other refusal in this file reports through
            // ruTrackerChecker::logDebug(), and that is right: they all
            // recover on their own, so a line per cycle for every corrupt book
            // would be noise in the shared application log. This is the one
            // here that DELIBERATELY does not repair itself, so it is the one
            // that takes the ungated channel instead -- the same channel, the
            // same rule and now the same writer as the three other refusals in
            // this plugin that cannot heal either.
            if ($floor >= PHP_INT_MAX) {
                ruTrackerChecker::logUnrepairable('forumindex: forumindex.json puts forum ' . $forumId
                    . ' at ' . PHP_INT_MAX . ', the highest generation this platform can count to,'
                    . ' so no further reservation can be issued and that forum fetches no dump at'
                    . ' all; nothing here can repair it without handing out a generation a document'
                    . ' on disk still proves was issued, so clear forum ' . $forumId . ' out of'
                    . ' dump_reservations, dump_generations and dump_gen and drop its forumdump-'
                    . $forumId . '-* documents to start it again');
                return $state;
            }
            $reservation = $floor + 1;
            $token = getmypid() . '-' . uniqid('', true);
            // Before the writes, never after: this is the pair a request's
            // whole authority to publish rests on, and a book that cannot hold
            // the token is a forum that can never pass its own publish gate.
            // etags rides along, and honestly: seating it here changes
            // nothing today. This callback only READS the hint two lines
            // down, never writes it, and one byte of a string book reads back
            // as a perfectly good string -- which costs one wasted conditional
            // GET, not a fatal. It is seated anyway because this is where the
            // book first becomes this request's business, so a future line
            // that starts WRITING the hint here cannot reintroduce the hazard
            // by forgetting to.
            foreach (array('dump_reservations', 'dump_tokens', 'etags') as $book)
                self::seatBook($state, $book, 'forum ' . $forumId);
            $state['dump_reservations'][$forumId] = $reservation;
            $state['dump_tokens'][$forumId] = $token;
            $cachedEtag = is_string($state['etags'][$forumId] ?? null)
                ? (string) $state['etags'][$forumId] : '';
            return $state;
        });
        // The retired body goes only once its bookkeeping is provably gone
        // from disk; a failed update leaves both exactly as they were.
        if ($reserved && $retired !== null) self::dropDocuments(array($retired));
        if (!$reserved || $reservation === null)
            return self::remember($memoize, $forumId, null);

        if ($client === null) {
            $client = self::makeDumpClient();
            if ($cachedEtag !== '')
                $client->rawheaders = array('If-None-Match' => $cachedEtag);
        }
        $sent = is_array($client->rawheaders)
            ? (string) ($client->rawheaders['If-None-Match'] ?? '') : '';

        // The fetch -- up to 30s, see makeDumpClient() -- runs with NO
        // state lock held. Only the short reservation/publication updates
        // bracket it; holding the lock across the
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
                    //
                    // The seat is unreachable as the code stands: nothing gets
                    // this far without having persisted a seated etags book on
                    // the way in. It stays because the unset below is the kind
                    // of write that turns a string book into a fatal, and a
                    // later caller reaching this path by another route should
                    // not have to know that the guard is somewhere upstream.
                    self::seatBook($state, 'etags', 'forum ' . $forumId);
                    if (($state['etags'][$forumId] ?? null) === $sent)
                        unset($state['etags'][$forumId]);
                    return $state;
                });
                return self::remember($memoize, $forumId, null);
            }
            $dropDocuments = array();
            $touched = RuTrackerState::update('forumindex', function ($state) use (
                $forumId,
                $reservation,
                $token,
                $doc,
                &$dropDocuments
            ) {
                if (!self::holdsReservation($state, $forumId, $reservation, $token))
                    return $state;
                if (self::stateDumpDocument($state, $forumId) !== $doc['document'])
                    return $state;
                return self::touchDump($state, $forumId, $dropDocuments);
            });
            if ($touched) self::dropDocuments($dropDocuments);
            return self::remember($memoize, $forumId, self::durableDumpAnswer($forumId));
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
        // The final name is unique to the durable reservation. Promotion may
        // happen before forumindex.json is replaced, but it can never overwrite
        // the stable winner. If the state replacement then fails, only this
        // unreferenced generation is removed.
        $document = self::versionedDumpDocument($forumId, $reservation);
        $stagedKey = $document . '-staged-' . getmypid() . '-' . uniqid('', true);
        $saved = RuTrackerState::save($stagedKey, array(
            'generation' => $reservation,
            'etag' => $etag,
            'rows' => $rows,
        ));
        if (!$saved)
            return self::remember($memoize, $forumId, self::durableDumpAnswer($forumId));

        $promoted = false;
        $published = false;
        $oldDocument = null;
        $dropDocuments = array();
        $stored = RuTrackerState::update('forumindex', function ($state) use (
            $forumId,
            $reservation,
            $token,
            $etag,
            $stagedKey,
            $document,
            &$promoted,
            &$published,
            &$oldDocument,
            &$dropDocuments
        ) {
            if (!self::holdsReservation($state, $forumId, $reservation, $token))
                return $state;

            // Ahead of the read below as well as the three writes: one byte of
            // a string dump_documents book reads back as a perfectly good
            // document name, and this request would then drop the file it
            // names as its own predecessor.
            foreach (array('dump_documents', 'dump_generations', 'etags') as $book)
                self::seatBook($state, $book, 'forum ' . $forumId);
            $oldDocument = self::stateDumpDocument($state, $forumId);
            if (!RuTrackerState::promote($stagedKey, $document)) return $state;
            $promoted = true;
            $state['dump_documents'][$forumId] = $document;
            $state['dump_generations'][$forumId] = $reservation;
            if ($etag !== '') $state['etags'][$forumId] = $etag;
            else unset($state['etags'][$forumId]);
            $state = self::touchDump($state, $forumId, $dropDocuments);
            $published = true;
            return $state;
        });
        RuTrackerState::drop($stagedKey);

        if (!$stored) {
            if ($promoted) RuTrackerState::drop($document);
            return self::remember($memoize, $forumId, self::durableDumpAnswer($forumId));
        }
        if (!$published)
            return self::remember($memoize, $forumId, self::durableDumpAnswer($forumId));

        if ($oldDocument !== null && $oldDocument !== $document)
            RuTrackerState::drop($oldDocument);
        self::dropDocuments($dropDocuments, $document);
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
    static private function touchDump($state, $forumId, &$dropDocuments = null)
    {
        if (!is_array($dropDocuments)) $dropDocuments = array();
        // Every book the stamp below writes into, and every book the prune
        // under it unsets an entry in. A scalar dump_touched is the sharpest
        // of them: the (array) cast turns it into a one-entry book whose stamp
        // is three decades stale, so the prune fires on it and then unsets
        // entries out of three more books that may be no more a book than it
        // was.
        //
        // dump_generations is the one entry in this list that cannot fire
        // today: both callers already guarantee it is a book -- the 304 path
        // because cachedDocument() answers null through storedCount()
        // otherwise, the 200 path because the publish callback seated it. It
        // is listed anyway rather than pruned out, because the prune below
        // unsets an entry in it and the next caller of touchDump() should not
        // have to re-derive that argument to stay safe.
        foreach (array('dump_touched', 'etags', 'dump_documents', 'dump_generations') as $book)
            self::seatBook($state, $book, 'forum ' . $forumId);
        $now = time();
        $state['dump_touched'][$forumId] = $now;
        foreach ((array) $state['dump_touched'] as $id => $touchedAt) {
            // A stamp nobody can read is not evidence the body is 30 days
            // stale: (int) answered 0, which is stale by three decades, and a
            // perfectly good cached dump was dropped on it. The next touch of
            // that forum rewrites the stamp.
            $at = RuTrackerRpcValue::canonicalNonnegativeInteger($touchedAt);
            if ($at !== null && $now - $at > self::DUMP_RETENTION) {
                // The KEY, not only the stamp. json_decode() keeps a
                // non-canonical key like "0921" the string it was written as,
                // and (int) "0921" is 921 -- so this prune deleted forum 921's
                // cached document while unsetting the "0921" bookkeeping, and
                // 921 was left naming a file that is gone. A key that names no
                // forum retires only what is stored under that exact key.
                $forum = RuTrackerRpcValue::canonicalPositiveInt32($id);
                $dropDocuments[] = $forum !== null
                    ? self::stateDumpDocument($state, $forum)
                    : ($state['dump_documents'][$id] ?? null);
                unset(
                    $state['dump_touched'][$id],
                    $state['etags'][$id],
                    $state['dump_documents'][$id],
                    $state['dump_generations'][$id]
                );
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
        $forumId = (int) $forumId;
        $readable = true;
        $state = RuTrackerState::load('forumindex', $readable);
        if (!$readable) return null;
        $document = self::stateDumpDocument($state, $forumId);
        $doc = RuTrackerState::load($document, $readable);
        if (!$readable) return null;
        if (!isset($doc['rows']) || !is_array($doc['rows'])) return null;
        // The document must name the generation the state published, in the
        // one spelling that names it: "05" is not the generation 5, and
        // reading both sides through (int) made a body nothing ever published
        // as current answer as the cache -- and every later 304 confirm it.
        //
        // Unconditionally, rather than only while dump_documents names a
        // document. When it does NOT, stateDumpDocument() falls back to the
        // legacy unversioned name, and a body sitting there -- written before
        // dumps were versioned, or left behind when the retirement in
        // fetchDump() erased the pointer -- was served as the current cache
        // with nothing whatsoever checking which generation it was. Layer 3
        // answers "this topic is not in this forum" from that body, and that
        // is what decides STE_DELETED. A forum with no published generation
        // now simply has no cache: the next fetch publishes one and drops the
        // legacy body as its own predecessor.
        $generation = self::storedCount($state['dump_generations'] ?? null, $forumId);
        if ($generation === null || $generation < 1
            || RuTrackerRpcValue::canonicalNonnegativeInteger($doc['generation'] ?? null) !== $generation)
            return null;
        return array(
            'rows' => $doc['rows'],
            'etag' => (string) ($doc['etag'] ?? ''),
            'document' => $document,
        );
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

    static private function versionedDumpDocument($forumId, $generation)
    {
        return self::dumpDocument($forumId) . '-' . (int) $generation;
    }

    static private function stateDumpDocument($state, $forumId)
    {
        $document = $state['dump_documents'][(int) $forumId] ?? null;
        return is_string($document) && $document !== ''
            ? $document : self::dumpDocument($forumId);
    }

    static private function durableDumpAnswer($forumId)
    {
        $doc = self::cachedDocument($forumId);
        return $doc === null ? null : array('rows' => $doc['rows'], 'fresh' => false);
    }

    static private function dropDocuments($documents, $keep = null)
    {
        foreach (array_unique((array) $documents) as $document)
            if (is_string($document) && $document !== '' && $document !== $keep)
                RuTrackerState::drop($document);
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
            // A miss nobody can DATE is not a lapsed suppression window; it is
            // repaired in place instead of holding the topic for ever. And a
            // BOOK nobody can read holds no record to date: reading one out of
            // a string book reads a character, and the repair would then write
            // a suppression window back into that same string.
            self::seatBook($state, 'misses', 'topic ' . $topicId);
            $repair = null;
            if (self::missSuppresses($state['misses'][$topicId] ?? null, $topicId, time(), $repair)) {
                if ($repair !== null) $state['misses'][$topicId] = $repair;
                return $state;
            }

            $queue = isset($state['queue']) && is_array($state['queue']) ? $state['queue'] : array();
            $present = in_array($topicId, $queue, true);

            // Increment even when the topic was already queued. A crawl keeps
            // its observed queue generation durable while it runs; this
            // version lets its completion distinguish that generation from a
            // same-topic request made concurrently and leave the newer one in
            // place for the next crawl.
            // Internal retry paths use ensureQueued() below: the existing
            // generation is already durable and must not be made to look like
            // a newer outside request merely because a second crawler lost
            // the cooldown claim.
            if (!$newGeneration && $present) {
                $state['queue'] = $queue;
                return $state;
            }
            // The persisted ordering guard for concurrent crawlers. One that
            // will not read cannot be counted from, and (int)'s fresh 1
            // collides with generations a running crawl has already observed
            // -- settleQueue() would then retire a request nobody made.
            //
            // Refusing it outright wedged the EXPLICIT half of the crawl for
            // the whole installation, permanently: this function is the
            // serial's only writer and is gated behind this same read, and a
            // MOVED topic -- one that already carries a chk-forum -- reaches a
            // crawl through nothing but this queue, so its mapping could never
            // be corrected again and layer 3 kept reading the wrong forum's
            // dump. The guard is not the number, it is "no generation a
            // running crawl has already observed may be handed out again", and
            // queue_versions holds every generation any crawl can be holding:
            // queueSnapshot() takes a crawl's observed set from it, and an
            // entry leaves it only when settleQueue() retires that request. So
            // the serial is reseeded ABOVE the highest readable entry, which
            // restores exactly that guarantee rather than restarting the
            // number space underneath a running crawl.
            $serial = self::storedCount($state, 'queue_serial');
            if ($serial === null) {
                $serial = self::maxQueueVersion($state);
                ruTrackerChecker::logDebug('forumcrawl: forumindex.json queue_serial is not a readable'
                    . ' counter; it is reseeded above every queue generation still readable on disk'
                    . ' rather than leaving a moved topic unresolvable for ever');
            }
            $serial++;
            $versions = isset($state['queue_versions']) && is_array($state['queue_versions'])
                ? $state['queue_versions'] : array();
            $versions[$topicId] = $serial;
            if (!$present) $queue[] = $topicId;
            $state['queue'] = $queue;
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
        foreach ($topics as $topic) $versions[(int) $topic] = self::queueVersion($stored, (int) $topic);
        return array('topics' => array_map('intval', $topics), 'versions' => $versions);
    }

    // One queued topic's generation. An ABSENT entry is the legacy zero, which
    // stays safe: the first later queueTopic() gives it a real one. Present but
    // not canonical is corruption and answers null -- (int) read both sides of
    // the comparison below as 0, they matched, and a completing crawl retired a
    // request it had never observed.
    static private function queueVersion($versions, $topic)
    {
        if (!is_array($versions) || !array_key_exists($topic, $versions)) return 0;
        return RuTrackerRpcValue::canonicalNonnegativeInteger($versions[$topic]);
    }

    // The highest queue generation anything on disk can still prove was
    // handed out. An entry that will not read is SKIPPED rather than counted
    // from -- settleQueue() already refuses to retire one of those, so it can
    // never be the generation a completing crawl matches against.
    static private function maxQueueVersion($state)
    {
        $versions = isset($state['queue_versions']) && is_array($state['queue_versions'])
            ? $state['queue_versions'] : array();
        $max = 0;
        foreach ($versions as $version) {
            $seen = RuTrackerRpcValue::canonicalNonnegativeInteger($version);
            if ($seen !== null && $seen > $max) $max = $seen;
        }
        return $max;
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
                $currentVersion = self::queueVersion($versions, $topic);
                $observedVersion = self::queueVersion($snapshot['versions'], $topic);
                if ($currentVersion !== null && isset($observed[$topic]) && !isset($keep[$topic])
                    && $currentVersion === $observedVersion) {
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
        if (!array_key_exists('last_sweep', $state)) return true;
        // A stamp nobody can read is not a lapsed cooldown -- but it is not a
        // reason to disable the tracker-wide crawl for the whole installation
        // for ever either, and REFUSING it did exactly that: markSweep() is
        // last_sweep's only writer and is gated behind this same read, so the
        // value could never be repaired by anything. It is a pure cooldown
        // STAMP, not an audit value, so the caller is let through to
        // markSweep(), which restamps it. That grants at most the single
        // crawl markSweep() then claims, and the document heals.
        $last = RuTrackerRpcValue::canonicalNonnegativeInteger($state['last_sweep']);
        if ($last === null) {
            ruTrackerChecker::logDebug('forumcrawl: forumindex.json last_sweep does not read as a'
                . ' timestamp; the cooldown cannot be judged, so one crawl is allowed through to'
                . ' restamp it rather than leaving the crawl disabled for ever');
            return true;
        }
        return $now - $last > $cooldown;
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
            // first-run case: an absent last_sweep is not a zero. And one
            // nobody can read is REPAIRED here rather than refused: this is
            // the stamp's only writer, so refusing it left the crawl disabled
            // installation-wide with no path back. Writing $now over it hands
            // out this one claim and nothing more.
            if (array_key_exists('last_sweep', $state)) {
                $last = RuTrackerRpcValue::canonicalNonnegativeInteger($state['last_sweep']);
                if ($last === null)
                    ruTrackerChecker::logDebug('forumcrawl: forumindex.json last_sweep is not a'
                        . ' readable timestamp; it is restamped to now, which rations the crawl'
                        . ' from here on instead of disabling it for ever');
                elseif ($now - $last <= $cooldown) return $state;
            }
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
            // missCount() answers null for a count nobody can read, and
            // null + 1 is 1 in PHP -- the NARROWEST window, written in the
            // very direction missWindow() had just refused for the same
            // record. An unreadable count counts up to the cap it is already
            // being suppressed at.
            $priorCount = $prior === null ? null : self::missCount($prior);
            $misses[(int) $topicId] = array(
                'at' => (int) $now,
                'n' => $prior === null ? 1
                    : ($priorCount === null ? self::MISS_WINDOW_CAP : $priorCount + 1),
            );
            foreach ($misses as $id => $record) {
                // Prune only what is provably past its own window. A record
                // whose stamp will not read is not stale on that evidence, and
                // pruning it hands the topic a fresh tracker-wide crawl.
                $missedAt = self::missedAt($record);
                if ($missedAt !== null && (int) $now - $missedAt > self::missWindow($record))
                    unset($misses[$id]);
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
        // A count that will not read is the LONGEST suppression, not the
        // shortest: the record exists because a completed sweep already failed
        // to find the topic, and (int)'s 1 bought a full tracker-wide crawl
        // one cooldown later on a number nobody could read.
        $count = self::missCount($record);
        $count = $count === null ? self::MISS_WINDOW_CAP : min(self::MISS_WINDOW_CAP, $count);
        return self::sweepCooldown() * min(self::MISS_WINDOW_CAP, 1 << ($count - 1));
    }

    // Whether a miss record still suppresses this topic, and -- through
    // $repair -- the canonical record to store when its stamp will not read.
    // Refusing an undatable record suppressed the topic SILENTLY and FOR
    // EVER: markMiss() is its only writer, markMiss() only ever runs for a
    // topic that was crawled for, and this predicate is what keeps that topic
    // out of every wanted set. The record is a pure per-topic suppression
    // cooldown, so restamping it to now grants no crawl and leaves a record
    // that can actually expire.
    static private function missSuppresses($record, $topicId, $now, &$repair = null)
    {
        $repair = null;
        if ($record === null) return false;
        $missedAt = self::missedAt($record);
        if ($missedAt !== null) return $now - $missedAt <= self::missWindow($record);
        ruTrackerChecker::logDebug('forumcrawl: forumindex.json misses[' . (int) $topicId
            . '] carries no readable stamp, so topic ' . (int) $topicId . ' could never be crawled'
            . ' for again; it is restamped to now under the count it does read');
        // The STAMP is what will not read. The count beside it may be
        // perfectly good, and overwriting that with the cap suppressed a topic
        // on its FIRST miss for eight cooldowns instead of one -- seven in
        // which nothing looks for it. Only a count nobody can read takes the
        // cap, exactly as missWindow() decides it.
        $count = self::missCount($record);
        $repair = array('at' => (int) $now, 'n' => $count === null ? self::MISS_WINDOW_CAP : $count);
        return true;
    }

    static private function missCount($record)
    {
        if (!is_array($record) || !array_key_exists('n', $record)) return 1;
        $count = RuTrackerRpcValue::canonicalNonnegativeInteger($record['n']);
        return $count === null ? null : max(1, $count);
    }

    // null, never zero: a stamp that will not read is not "long ago", and
    // every comparison against it decides whether another crawl of the whole
    // tracker is authorised.
    static private function missedAt($record)
    {
        if (!is_array($record)) return RuTrackerRpcValue::canonicalNonnegativeInteger($record);
        return array_key_exists('at', $record)
            ? RuTrackerRpcValue::canonicalNonnegativeInteger($record['at']) : null;
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
            // An UNSET chk-topic reads back as '' -- no topic on this row.
            // Anything else that is not the canonical spelling of a topic id
            // names no topic either: this map decides which hash the crawl
            // writes chk-forum onto, and (int) made "007" the topic 7.
            $topic = RuTrackerRpcValue::canonicalPositiveInt32($req->val[$i + 1]);
            if ($topic === null) continue;
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
        // The id about to be WRITTEN, canonicalised HERE rather than trusted
        // from the caller: every caller already coerced its own copy, so the
        // topic guard below compared a coerced value with itself and this one
        // was never checked at all. '0' and '-22' are ids resolveForum() can
        // never accept, so the chk-forum they installed made every later call
        // answer FORUM_WRITE_CURRENT while the reader stayed blind, for ever.
        // Unwritable is OBSOLETE, not FAILED: the obligation is impossible
        // rather than stale, so a caller retires it instead of retrying.
        $canonicalForum = RuTrackerRpcValue::canonicalPositiveInt32($forumId);
        if ($canonicalForum === null) {
            ruTrackerChecker::logDebug('forumindex: the chk-forum write for ' . $hash
                . ' was handed a forum id that is not a canonical positive integer;'
                . ' nothing is written and the obligation is retired as obsolete');
            return self::FORUM_WRITE_OBSOLETE;
        }
        $forumId = $canonicalForum;
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

            // The row must provably carry the topic this mapping is about, in
            // the one spelling that names it. (int) made "007" equal to 7 and
            // let the write below land on a row whose chk-topic never said so.
            // Unprovable is OBSOLETE, not FAILED: the read itself succeeded,
            // so there is nothing to retry -- and a retry loop here costs a
            // tracker-wide crawl per cooldown, for ever.
            $readTopic = RuTrackerRpcValue::canonicalPositiveInt32($read->val[0]);
            if ($readTopic === null || $readTopic !== RuTrackerRpcValue::canonicalPositiveInt32($topicId))
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
        // Read through the same is_array() every other reader of this book
        // uses, rather than seated: this snapshot is never written back, and a
        // repair nothing persists is not a repair worth announcing. A book
        // that is not an array holds no record anyone can see, and indexing a
        // string one reads a character and calls it an undatable miss.
        $misses = isset($state['misses']) && is_array($state['misses']) ? $state['misses'] : array();
        $now = (int) $now;
        $wanted = array();
        foreach ($queued as $topic) $wanted[$topic] = true;
        $missRepairs = array();
        foreach (array_keys($awaiting) as $topic) {
            $repair = null;
            if (self::missSuppresses($misses[(int) $topic] ?? null, $topic, $now, $repair)) {
                if ($repair !== null) $missRepairs[(int) $topic] = $repair;
                continue;
            }
            $wanted[(int) $topic] = true;
        }
        // Same repair storeQueuedTopic() applies, in the one place this half
        // of the wanted set reads the record: a topic whose miss cannot be
        // dated is otherwise never crawled for again by anything.
        //
        // The seat inside the callback is NOT the same guard as the is_array()
        // above, and removing it because "the snapshot was already checked" is
        // the mistake to avoid. update() takes the lock and then re-reads the
        // document, so the $state handed to this callback is a fresh read, not
        // the snapshot taken at the top of this function. Between those two
        // reads another PROCESS -- another cycle, another crawl, the update
        // pass -- can have replaced the book with anything, which is the whole
        // reason update() locks and re-reads at all. No single-process test can
        // reach it, so it is deliberately left unpinned; the is_array() above
        // is pinned, and guards a different read at a different time.
        if (count($missRepairs))
            RuTrackerState::update('forumindex', function ($state) use ($missRepairs) {
                self::seatBook($state, 'misses', 'the topics this crawl repaired');
                foreach ($missRepairs as $topic => $repair)
                    if (self::missedAt($state['misses'][$topic] ?? null) === null)
                        $state['misses'][$topic] = $repair;
                return $state;
            });
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
        $failureReason = null;
        $threw = false;
        try {
            $outcome = ($sweeper !== null)
                ? call_user_func($sweeper, $wanted)
                : self::sweep($wanted, null, $failureReason);
        } catch (Throwable $failure) {
            // Exception messages may contain an HTTP target or response
            // fragment. The stable class is enough for an operator and safe
            // to place in the shared application log.
            $threw = true;
        }

        if ($outcome === null) {
            foreach ($wanted as $topic) self::ensureQueued($topic);
            return 'wanted ' . count($wanted) . ', crawl failed'
                . ($failureReason !== null ? ' reason=' . $failureReason
                    : ($threw ? ' reason=crawl-exception' : ''));
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
                    // unset() into a string book is an Error on both target
                    // runtimes, and this one is taken after the tracker-wide
                    // sweep that produced the resolution has already been paid
                    // for.
                    self::seatBook($state, 'misses', 'topic ' . (int) $topic);
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
            . ', ' . count($unresolved) . ' requeued: some dumps went unread'
            . ($failureReason !== null ? ' reason=' . $failureReason : '');
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

    static private function crawlFailureReason($code, $statuses)
    {
        $details = array();
        foreach ((array) $statuses as $status) {
            $detail = ruTrackerChecker::fetchStatusDetail($status);
            if ($detail !== '') $details[$detail] = true;
        }
        return $code . (count($details) ? ' statuses=' . implode('|', array_keys($details)) : '');
    }

    static public function sweep($wantedTopics, $fetcher = null, &$failureReason = null)
    {
        $failureReason = null;
        $wanted = array();
        foreach ($wantedTopics as $topic) $wanted[(int) $topic] = true;
        if (!count($wanted)) return array('resolved' => array(), 'complete' => true);

        if ($fetcher === null) {
            $fetcher = function ($url, &$refused = null, &$status = null) {
                // Politeness: the sweep is the plugin's only bulk consumer --
                // a full crawl is on the order of the tracker's whole forum
                // list -- so each real request pays a small fixed pause. The
                // pause lives in this default fetcher, not in the loop, so a
                // test-supplied fetcher stays instant.
                usleep(self::SWEEP_PAUSE_US);
                $client = self::makeDumpClient();
                @$client->fetchComplex($url);
                $status = $client->status;
                return self::dumpAnswer($client->status, $client->results, $refused);
            };
        }

        $treeRefused = false;
        $treeStatus = null;
        $treeBody = $fetcher(self::TREE_URL, $treeRefused, $treeStatus);
        if (!is_string($treeBody) || $treeBody === '') {
            $failureReason = self::crawlFailureReason('tree-transport', array($treeStatus));
            return null;
        }
        $tree = @json_decode($treeBody, true);
        if (!is_array($tree) || !isset($tree['result']['f']) || !is_array($tree['result']['f'])) {
            $failureReason = self::crawlFailureReason('tree-malformed', array($treeStatus));
            return null;
        }

        $forumKeys = array_keys($tree['result']['f']);
        if (count($forumKeys) === 0) {
            $failureReason = self::crawlFailureReason('tree-malformed', array($treeStatus));
            return null;
        }

        $validatedForumIds = array();
        $seenForumIds = array();
        foreach ($forumKeys as $key) {
            $forumId = RuTrackerRpcValue::canonicalPositiveInt32($key);
            if ($forumId === null || isset($seenForumIds[$forumId])) {
                $failureReason = self::crawlFailureReason('tree-malformed', array($treeStatus));
                return null;
            }
            $seenForumIds[$forumId] = true;
            $validatedForumIds[] = $forumId;
        }

        $resolved = array();
        $failures = 0;
        $unread = 0;
        $failureCodes = array();
        $failureStatuses = array();
        foreach ($validatedForumIds as $forumId) {
            if (!count($wanted)) break;
            $refused = false;
            $status = null;
            $body = $fetcher(self::DUMP_URL . $forumId, $refused, $status);
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
                    $failureCodes['dump-refused'] = true;
                    $failureStatuses[] = $status;
                    if (++$failures >= self::SWEEP_FAILURE_ABORT) {
                        $failureReason = self::crawlFailureReason(
                            implode('+', array_keys($failureCodes)), $failureStatuses);
                        return null;
                    }
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
                $failureCodes['dump-malformed'] = true;
                $failureStatuses[] = $status;
                if (++$failures >= self::SWEEP_FAILURE_ABORT) {
                    $failureReason = self::crawlFailureReason(
                        implode('+', array_keys($failureCodes)), $failureStatuses);
                    return null;
                }
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
        if ($unread > 0) {
            $failureReason = self::crawlFailureReason(
                implode('+', array_keys($failureCodes)), $failureStatuses);
        }
        return array('resolved' => $resolved, 'complete' => $unread === 0);
    }
}
