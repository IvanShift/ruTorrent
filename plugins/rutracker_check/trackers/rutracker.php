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
        if (!preg_match('/^rutracker\.(?:org|cr|net|nl)$/i', $parts['host'])) return null;
        if (strcasecmp($parts['path'], '/forum/viewtopic.php') !== 0) return null;

        $query = array();
        parse_str(isset($parts['query']) ? $parts['query'] : '', $query);
        if (!isset($query['t']) || !is_scalar($query['t']) || !ctype_digit((string) $query['t'])) return null;
        return (int) $query['t'];
    }

    // --- Absorption detection (design doc 4.5): dormant, kept as-is -------
    //
    // Detects a topic absorbed into another one by parsing the forum thread
    // for a final moderator notice; the thread itself sits behind RuTracker's
    // Cloudflare challenge (toggled on/off repeatedly through 2026) and is no
    // longer reachable from the active flow below. Nothing here is called
    // from download_torrent() any more -- resolution is now decided by
    // classifyDump()'s tor_status == 7 rule, which needs no HTML fetch at
    // all. These functions are left wired up so they revive instantly if the
    // challenge ever lifts; do not remove or "clean up" as dead code.

    // Decode CP1251 HTML to UTF-8 for reliable text search.
    static private function decodePage($content)
    {
        if (!is_string($content) || $content === '') return '';

        $decoded = false;
        if (function_exists('iconv')) {
            $decoded = @iconv('CP1251', 'UTF-8//IGNORE', $content);
        }
        if (($decoded === false) && function_exists('mb_convert_encoding')) {
            $decoded = @mb_convert_encoding($content, 'UTF-8', 'CP1251');
        }
        return ($decoded === false || is_null($decoded)) ? $content : $decoded;
    }

    static private function extractLastPageHtml($client, $topicId)
    {
        $topicUrl = 'https://rutracker.org/forum/viewtopic.php?t=' . $topicId;
        $client->setcookies();
        $client->fetchComplex($topicUrl);

        if ($client->status != 200 || empty($client->results)) return null;

        $html = self::decodePage($client->results);
        $lastStart = 0;
        if (preg_match_all('/<a\b[^>]*>/i', $html, $anchors)) {
            foreach ($anchors[0] as $anchor) {
                if (!preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/is', $anchor, $classMatch)
                    || !preg_match('/(?:^|\s)pg(?:\s|$)/i', $classMatch[2])
                    || !preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/is', $anchor, $hrefMatch)) {
                    continue;
                }

                $href = html_entity_decode($hrefMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $parts = @parse_url($href);
                if (!is_array($parts) || !isset($parts['path'], $parts['query'])
                    || strcasecmp(basename($parts['path']), 'viewtopic.php') !== 0
                    || (isset($parts['host']) && !preg_match('/^rutracker\.(?:org|cr|net|nl)$/i', $parts['host']))) {
                    continue;
                }
                $query = array();
                parse_str($parts['query'], $query);
                if (!isset($query['t'], $query['start'])
                    || !is_scalar($query['t']) || !is_scalar($query['start'])
                    || (int) $query['t'] !== (int) $topicId
                    || !ctype_digit((string) $query['start'])) {
                    continue;
                }
                $lastStart = max($lastStart, (int) $query['start']);
            }
        }

        if ($lastStart > 0) {
            $client->setcookies();
            $client->fetchComplex($topicUrl . '&start=' . $lastStart);
            if ($client->status != 200 || empty($client->results)) return null;
            $html = self::decodePage($client->results);
        }

        return $html;
    }

    static private function isModeratorPost($postHtml)
    {
        if (!preg_match_all('/<img\b[^>]*>/i', $postHtml, $images)) return false;

        foreach ($images[0] as $image) {
            if (!preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/is', $image, $classMatch)) continue;
            if (!preg_match('/(?:^|\s)user-rank(?:\s|$)/i', $classMatch[2])) continue;
            if (!preg_match('/\balt\s*=\s*(["\'])(.*?)\1/is', $image, $altMatch)) continue;
            if (preg_match('/\bmoderator\b|модератор/iu', $altMatch[2])) return true;
        }
        return false;
    }

    static private function extractPostBody($postHtml)
    {
        if (!preg_match_all('/<div\b[^>]*>/i', $postHtml, $divs, PREG_OFFSET_CAPTURE)) return null;

        foreach ($divs[0] as $div) {
            $tag = $div[0];
            if (!preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/is', $tag, $classMatch)) continue;
            if (!preg_match('/(?:^|\s)post_body(?:\s|$)/i', $classMatch[2])) continue;

            $start = $div[1] + strlen($tag);
            if (!preg_match('/<\/div>\s*<!--\/post_body-->/i', $postHtml, $end, PREG_OFFSET_CAPTURE, $start)) {
                return null;
            }
            return substr($postHtml, $start, $end[0][1] - $start);
        }
        return null;
    }

    // Accept only a final absorption marker written in a moderator post.
    static private function detectAbsorbedTopic($client, $topicId)
    {
        $html = self::extractLastPageHtml($client, $topicId);
        if (empty($html)) return null;

        if (!preg_match_all(
            '~<tbody\b[^>]*\bid=["\']post_\d+["\'][^>]*>.*?</tbody>~is',
            $html,
            $posts
        )) return null;

        foreach (array_reverse($posts[0]) as $post) {
            if (!self::isModeratorPost($post)) continue;
            $body = self::extractPostBody($post);
            if ($body === null) continue;

            $plain = html_entity_decode(
                preg_replace('/<[^>]+>/', ' ', $body),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
            $plain = preg_replace('/\s+/u', ' ', trim($plain));
            if (!preg_match('/(?:^|\s)(?:Поглощено|Объединено)\.?$/iu', $plain)) continue;

            $decodedBody = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match_all(
                '~href=["\'](?:(?:https?://rutracker\.(?:org|cr|net|nl)/forum/)|/forum/|\./)?viewtopic\.php\?[^"\']*\bt=(\d+)[^"\']*["\']~i',
                $decodedBody,
                $links
            )) continue;

            $candidates = array();
            foreach ($links[1] as $candidate) {
                $candidate = (int) $candidate;
                if ($candidate && $candidate !== (int) $topicId) $candidates[$candidate] = true;
            }
            if (count($candidates) === 1) return (int) key($candidates);
        }
        return null;
    }

    // --- Post-API active flow (design doc 4.1-4.4) -------------------------

    // Shared chk-* custom-field boilerplate for the tiny helpers below: a
    // single read (null on any RPC failure or a genuinely unset field, so
    // callers can tell "no data" from "empty string") and a fire-and-forget
    // write, both routed through getCmd() like every other command here.
    static private function readCustom($hash, $field)
    {
        $req = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.get_custom"), array($hash, $field)));
        $req->important = false;
        if (!$req->success() || !isset($req->val[0])) return null;
        return (string) $req->val[0];
    }

    static private function writeCustom($hash, $field, $value)
    {
        $req = new rXMLRPCRequest(new rXMLRPCCommand(
            getCmd("d.set_custom"), array($hash, $field, (string) $value)));
        $req->important = false;
        $req->success();
    }

    // chk-topic := $topicId, but only the first time (one read, conditional
    // write) -- a later move/resolve must not clobber an already-known id.
    static private function rememberTopic($hash, $topicId)
    {
        if (self::readCustom($hash, "chk-topic") !== '') return;
        self::writeCustom($hash, "chk-topic", (string) $topicId);
    }

    // Layer 1 (design doc 4.1): the torrent's own RuTracker tracker row plus
    // d.get_message, fed straight into RuTrackerDetector::classify(). Uses
    // the same fields as update.php's embedded t.multicall, but addressed at
    // a single hash -- this handler is reached from both the scheduled pass
    // and a manual batch_check.php click, so it must always re-derive its
    // own verdict rather than trust a cached one.
    static private function layer1Verdict($hash)
    {
        $req = new rXMLRPCRequest(array(
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
        // this one request's answer is a single FLAT list: the tracker rows,
        // 4 values each in order (url, enabled, failed, success), followed
        // by the single d.get_message value tacked on at the end.
        $values = $req->val;
        $messageIndex = count($values) - 1;
        // An answer that carried no values at all is no signal either.
        if ($messageIndex < 0) return 'transport';
        $message = (string) $values[$messageIndex];

        $rows = array();
        for ($i = 0; $i + 4 <= $messageIndex; $i += 4) {
            $rows[] = array(
                'url' => $values[$i], 'enabled' => (int) $values[$i + 1],
                'failed' => (int) $values[$i + 2], 'success' => (int) $values[$i + 3],
            );
        }
        return RuTrackerDetector::classify($rows, $message);
    }

    // Layer 3's forum_id cache: chk-forum, written once resolved (feed or
    // full crawl, both outside this handler) and read back here.
    static private function resolveForum($hash)
    {
        $forum = self::readCustom($hash, "chk-forum");
        if ($forum === null) return null;
        $forum = trim($forum);
        return ($forum !== '' && ctype_digit($forum)) ? (int) $forum : null;
    }

    // Invalidates a stale chk-forum: the topic may simply have moved forum,
    // so the cache is dropped and re-resolution is queued rather than
    // treating a cache miss as proof of deletion.
    static private function forgetForum($hash)
    {
        self::writeCustom($hash, "chk-forum", '');
    }

    static private function resetDeletion($hash)
    {
        self::writeCustom($hash, "chk-del", '');
    }

    // Pure classification of a found dump row (design doc 4.3, rules 2-6);
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
        return array('verdict' => 'updated', 'status' => $status, 'newHash' => $row['info_hash']);
    }

    // Two-independent-sources deletion confirmation (design doc 4.3):
    // chk-del holds "count:timestamp-of-last-increment". The increment is
    // capped at once per $interval regardless of how many times this runs,
    // so repeated manual batch_check.php clicks cannot fast-forward the
    // three required cycles.
    static private function confirmDeletion($hash, $now, $interval)
    {
        global $rutrackerDeleteCycles;
        $cycles = isset($rutrackerDeleteCycles) ? (int) $rutrackerDeleteCycles : 3;
        $interval = max((int) $interval, self::MIN_DELETE_INTERVAL);

        $count = 0;
        $lastIncrement = 0;
        $stored = self::readCustom($hash, "chk-del");
        if ($stored !== null && preg_match('/^(\d+):(\d+)$/', $stored, $m)) {
            $count = (int) $m[1];
            $lastIncrement = (int) $m[2];
        }

        if ($count > 0 && ($now - $lastIncrement) < $interval) {
            ruTrackerChecker::setMessage($hash, 'строки нет, цикл ' . $count . '/' . $cycles);
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }

        $count++;
        self::writeCustom($hash, "chk-del", $count . ':' . $now);

        if ($count >= $cycles) {
            ruTrackerChecker::setMessage($hash,
                'строки нет ' . $count . ' цикл(а) подряд, трекер подтвердил удаление');
            return ruTrackerChecker::STE_DELETED;
        }
        ruTrackerChecker::setMessage($hash, 'строки нет, цикл ' . $count . '/' . $cycles);
        return ruTrackerChecker::STE_CANT_REACH_TRACKER;
    }

    static public function download_torrent($url, $hash, $oldTorrent)
    {
        global $rutrackerLayer2Enabled, $rutrackerAnnouncePause, $rutrackerAnnounceCap, $updateInterval;

        $topicId = self::extractTopicId($url);
        if ($topicId === null && is_object($oldTorrent))
            $topicId = self::extractTopicId($oldTorrent->comment());
        if ($topicId === null) return ruTrackerChecker::STE_NOT_NEED;
        self::rememberTopic($hash, $topicId);

        $localHash = self::normalizeHash($hash);
        if ($localHash === null) return ruTrackerChecker::STE_NOT_NEED;

        // Layer 1: local, request-free verdict (design doc 4.1). Runs on
        // every call -- including a manual batch_check.php click -- rather
        // than trusting a cached scheduler verdict.
        $verdict = self::layer1Verdict($hash);
        if ($verdict === 'alive') {
            self::resetDeletion($hash);
            ruTrackerChecker::setMessage($hash, '');
            return ruTrackerChecker::STE_UPTODATE;
        }
        if ($verdict === 'cold' || $verdict === 'transport') return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        if ($verdict !== 'candidate') return ruTrackerChecker::STE_NOT_NEED;

        $announceUrl = is_object($oldTorrent) ? (string) $oldTorrent->announce() : '';
        $host = (string) @parse_url($announceUrl, PHP_URL_HOST);

        // Layer 2: passkey-less announce confirmation (design doc 4.2).
        // Optional and budgeted; the budget (allowProbe/recordProbe) is
        // consulted here too so repeated manual checks cannot outrun it --
        // the windowed cap is persisted (RuTrackerState, via announce.php),
        // so it holds across manual batch_check.php clicks just as much as
        // across the hourly update.php pass. $updateInterval*60 is the same
        // window every other per-cycle knob in this plugin uses; allowProbe/
        // recordProbe floor it themselves so a disabled scheduler ($updateInterval=0)
        // cannot void the cap.
        $announceWindow = (int) $updateInterval * 60;
        $trackerConfirmed = false;
        if (!empty($rutrackerLayer2Enabled) && $host !== ''
            && RuTrackerAnnounce::allowProbe($host, time(), (int) $rutrackerAnnounceCap, $announceWindow)) {
            // A misconfigured non-positive pause must not turn into a
            // negative sleep() argument; zero is a legitimate pause, so the
            // floor is 0, not 1.
            sleep(max(0, (int) $rutrackerAnnouncePause + random_int(0, 3)));
            $probeUrl = RuTrackerAnnounce::buildUrl($announceUrl, $localHash,
                RuTrackerAnnounce::makePeerId(), 63981, bin2hex(random_bytes(4)));
            if ($probeUrl !== null) {
                $client = ruTrackerChecker::makeClient($probeUrl);
                RuTrackerAnnounce::recordProbe($host, time(), (int) $client->status === 403, $announceWindow);
                $answer = RuTrackerAnnounce::classify($client->status, $client->results,
                    RuTrackerAnnounce::UNREGISTERED_FAILURE_REASON);
                if ($answer === 'registered') {
                    self::resetDeletion($hash);
                    ruTrackerChecker::setMessage($hash, '');
                    return ruTrackerChecker::STE_UPTODATE;
                }
                if ($answer === 'uncertain') return ruTrackerChecker::STE_CANT_REACH_TRACKER;
                $trackerConfirmed = true;
            }
        }

        // Layer 3: classification from the forum's static dump (design doc 4.3).
        $forumId = self::resolveForum($hash);
        if ($forumId === null) {
            RuTrackerForumIndex::queueTopic($topicId);
            ruTrackerChecker::setMessage($hash, 'форум топика неизвестен, поставлен в очередь обхода');
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }

        $rows = RuTrackerForumIndex::fetchDump($forumId);
        if ($rows === 'unchanged') $rows = RuTrackerForumIndex::cachedDump($forumId);
        if (!is_array($rows)) return ruTrackerChecker::STE_CANT_REACH_TRACKER;

        $decision = self::classifyDump($rows, $topicId, $localHash);
        if ($decision === null) {
            // Row missing: could be a move to another forum, not proof of
            // deletion on its own. Invalidate the cache and re-queue
            // resolution; only count towards STE_DELETED when layer 2
            // independently confirmed the hash is unregistered.
            self::forgetForum($hash);
            RuTrackerForumIndex::queueTopic($topicId);
            if (!$trackerConfirmed) {
                ruTrackerChecker::setMessage($hash, 'строки нет в дампе; трекер не подтверждал удаление');
                return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            }
            return self::confirmDeletion($hash, time(), (int) $updateInterval * 60);
        }

        // The row is present in the dump -- whatever its verdict below, that
        // alone disproves "missing", so any deletion count built up over
        // prior miss cycles is stale, and so is any "row missing, cycle
        // n/3" message confirmDeletion() may have left behind.
        self::resetDeletion($hash);
        ruTrackerChecker::setMessage($hash, '');

        // Layer 4: hand a genuinely new hash to the metadata fetch (design
        // doc 4.4); every other verdict is terminal here.
        switch ($decision['verdict']) {
            case 'absorbed':
                ruTrackerChecker::setMessage($hash,
                    'поглощена другой раздачей: https://rutracker.org/forum/viewtopic.php?t=' . $topicId);
                return ruTrackerChecker::STE_ABSORBED;
            case 'closed':
                ruTrackerChecker::setMessage($hash,
                    'topic status ' . $decision['status'] . ': закрыта/не оформлена/повтор');
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
