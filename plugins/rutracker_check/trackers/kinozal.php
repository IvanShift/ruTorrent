<?php

require_once( __DIR__ . '/../fetcherror.php' );
require_once( __DIR__ . '/../../../php/urlhost.php' );

class KinozalCheckImpl
{
    const SITE_HOSTS = array('kinozal.tv', 'kinozal.me', 'kinozal.guru');

    // The one test this handler applies to a comment before it does anything:
    // a topic URL on one of Kinozal's own hosts, and nothing else. Used both
    // by download_torrent(), which declines whatever fails it, and by the
    // registration at the bottom, which hands it to the scheduler as the
    // declared meaning of "this torrent is Kinozal's" -- one pattern, so the
    // two cannot disagree about what Kinozal owns.
    static public function topicPattern()
    {
        $hosts = array_map(function ($host) { return preg_quote($host, '`'); }, self::SITE_HOSTS);
        return '`^https?://(?:' . implode('|', $hosts) . ')/details\.php\?id=(?P<id>\d+)$`';
    }

    // Kinozal serves no torrent data to guests: get_srv_details.php answers
    // with a plain "not authorized" line and dl.kinozal.guru redirects to
    // login.php. Both of those answers -- and the login page itself -- offer
    // registration; no authenticated answer does (measured 2026-08-07 against
    // the live site, authorized and anonymous, on both endpoints). So this one
    // link is what tells "we are locked out" apart from "the tracker replied".
    const GUEST_MARKER = '/signup.php';

    // The details endpoint's own verdict for an id it no longer serves: HTTP
    // 200 with this body, in an authenticated session. One of the two
    // authoritative deletion signals this handler has -- the other is the
    // download endpoint's, below -- and each is read only at its own door.
    // Everything the handler merely fails to check stays retryable, so a
    // login wall, a dead socket or a layout change can never masquerade as a
    // removed release.
    const MISSING_MARKER = 'Торрент файл не найден';
    // Compatibility for legacy-encoded details answers; literal bytes also work without iconv.
    const MISSING_MARKER_CP1251 = "\xd2\xee\xf0\xf0\xe5\xed\xf2 \xf4\xe0\xe9\xeb \xed\xe5 \xed\xe0\xe9\xe4\xe5\xed";
    const DOWNLOAD_URL = 'https://dl.kinozal.guru/download.php?id=';

    // The DOWNLOAD endpoint's own verdict for an id it does not serve: HTTP 200
    // carrying the site's ordinary error page, whose one-line body is this.
    // Measured 2026-09-12 against the live endpoint on the stored session: the
    // whole 3100-byte page is byte-identical (same md5) for an id that never
    // existed and for a topic that was in the fleet and is gone. It means what
    // MISSING_MARKER above means -- this id serves no torrent -- read at the
    // door the challenge left open.
    //
    // The captured answer is windows-1251; the UTF-8 spelling is a defensive
    // compatibility case, not an observed answer from download.php. Both are
    // literal bytes, not converted. get_srv_details.php answers UTF-8, and the
    // production container's PHP has no iconv() at all, so a needle that
    // relied on conversion would never match. Carrying both spellings costs
    // one comparison and depends on no extension.
    const DOWNLOAD_MISSING_CP1251 = "\xcd\xe5\xf2 \xf0\xe0\xe7\xe4\xe0\xf7\xe8 \xf1 \xf2\xe0\xea\xe8\xec ID.";
    const DOWNLOAD_MISSING_UTF8   = 'Нет раздачи с таким ID.';

    // Anchored to the block the site puts that line in, rather than looked up
    // anywhere in the page. The two failure directions are not equal: an
    // anchor that stops matching when the markup changes loses the verdict and
    // reports "could not check", which is recoverable; a loose match that finds
    // the phrase somewhere it did not mean it reports a deletion, which is what
    // this handler is built never to guess.
    //
    // Pinned by the live page: KinozalHandlerTest carries the 4010-byte answer
    // captured 2026-09-14 through the production fetch path on the stored
    // session, byte-identical for an id that never existed and for a topic
    // that was in the fleet and is gone. Exactly one pad5x5 block on it, the
    // class written bare (<div class=pad5x5>), the block's stripped text the
    // marker byte for byte -- and the suite reads that page through this
    // pattern, so a change to either has to confront the real answer.
    const DOWNLOAD_MISSING_BLOCK = '~<div\s+class=["\']?pad5x5["\']?\s*>((?:(?!</?div\b).)*)</div>~is';

    // Per-endpoint, per-process latches. Production runs one PHP process per
    // cycle (update.php, batch_check.php), so process lifetime IS cycle lifetime
    // and each latch
    // cannot outlive the run. They are set by the two answers that are a
    // property of an endpoint rather than of one topic: a session the
    // tracker has stopped honouring (guestAnswer) and a host that has stopped
    // answering (unreachable). Without it every Kinozal torrent spent a
    // request proving the same thing over again -- 130 of them per cycle on
    // the live fleet -- and buried the log under 130 identical lines. The
    // download latch leaves healthy details checks available. Each latch
    // dies with the process, so the next cycle retries from scratch
    // and a restored session or a recovered host heals itself.
    static private $cycleAbandoned = false;
    static private $downloadAbandoned = false;

    // How many guest answers in a row mean the session is really gone rather
    // than blinking. Measured on the live fleet: one cycle got a guest answer
    // and the next, three seconds later, was authorised again with the very
    // same stored cookies. Latching on that single answer cost every remaining
    // Kinozal torrent an hour of waiting, so one is forgiven and the second
    // in a row is believed -- two wasted requests instead of a hundred and
    // thirty, and a blink no longer skips the cycle.
    const GUEST_TOLERANCE = 2;

    // The details and download endpoints are separate hosts and can wall the
    // session independently. A healthy details answer must therefore not
    // erase a guest streak reported by dl.kinozal.guru.
    static private $detailsGuestAnswers = 0;
    static private $downloadGuestAnswers = 0;

    // How many answers that never arrived in a row mean the host is refusing
    // everybody rather than one topic misbehaving. A guest answer and a
    // refusal are counted apart because they mean different things and are
    // repaired differently: one is a dead session, the other an unreachable
    // host, and a login will not fix the second.
    //
    // Three rather than the guest path's two: a single 5xx or a dropped
    // socket belongs to the topic that hit it, and losing the rest of an
    // hourly cycle to one blip costs more than the two extra requests. Three
    // in a row is no longer about one topic.
    //
    // Counts socket failures and HTTP refusals. A details challenge takes the
    // independent download route through detailsWalled instead of this count.
    const TRANSPORT_TOLERANCE = 3;

    static private $detailsTransportFailures = 0;
    static private $downloadTransportFailures = 0;

    // Per-cycle, like $cycleAbandoned and for the same reason: what the
    // details endpoint refused for one topic it refuses for every other, so
    // asking it again costs a request and tells nobody anything. Unlike
    // $cycleAbandoned this one does not end the cycle -- there is another
    // door, which answered one measured download on 2026-09-12 through the
    // production fetch path: get_srv_details.php answered 403 with the Cloudflare
    // interstitial while dl.kinozal.guru/download.php answered 200 with
    // 241474 bytes of torrent, on the loginmgr session already stored.
    static private $detailsWalled = false;

    static private function isMissingAnswer($body)
    {
        if (!is_string($body) || $body === '') return false;
        $plain = trim(preg_replace('/[ \t\r\n\f\v]+/', ' ', strip_tags($body)));
        $needle = self::MISSING_MARKER;
        if ($plain === $needle || $plain === $needle . '.') return true;
        $legacy = self::MISSING_MARKER_CP1251;
        if ($plain === $legacy || $plain === $legacy . '.') return true;
        return false;
    }

    static private function isGuestAnswer($body)
    {
        // The marker is ASCII in both endpoint encodings.
        return is_string($body) && strpos($body, self::GUEST_MARKER) !== false;
    }

    // Whether the download endpoint answered "there is no such id". Every
    // block with that class is read, not only the first: the class is
    // padding, the site may well use it above the error line too, and which
    // block comes first is layout. What a block says is the verdict, and it
    // has to say exactly the marker and nothing else.
    static private function isDownloadMissingAnswer($body)
    {
        if (!is_string($body) || $body === '') return false;
        if (!preg_match_all(self::DOWNLOAD_MISSING_BLOCK, $body, $matches)) return false;
        foreach ($matches[1] as $block) {
            $line = trim(strip_tags($block));
            if (($line === self::DOWNLOAD_MISSING_CP1251) || ($line === self::DOWNLOAD_MISSING_UTF8))
                return true;
        }
        return false;
    }

    // The state constant carries the whole user-facing verdict: init.js renders
    // it through theUILang.chkResults, which every lang/ file translates. The
    // detail below stays in the debug log, which is developer-facing and
    // gated by $rutrackerCheckDebug -- no untranslated text reaches the UI.
    static private function cantReach($log)
    {
        ruTrackerChecker::logDebug($log);
        return ruTrackerChecker::STE_CANT_REACH_TRACKER;
    }

    // A download latch skips only later download.php requests. A details
    // latch ends this Kinozal handler's checks for the current process cycle.
    static private function abandonEndpoint($log, $endpoint)
    {
        if ($endpoint === 'download') {
            self::$downloadAbandoned = true;
            return self::cantReach($log . ', this endpoint is skipped for the rest of this cycle');
        }
        self::$cycleAbandoned = true;
        return self::cantReach($log . ', the Kinozal handler is skipped for the rest of this cycle');
    }

    // Count guest answers per endpoint; one isolated login wall is retryable.
    static private function guestAnswer($log, $endpoint)
    {
        $answers = ($endpoint === 'download')
            ? ++self::$downloadGuestAnswers
            : ++self::$detailsGuestAnswers;
        if ($answers < self::GUEST_TOLERANCE)
            return self::cantReach($log);
        return self::abandonEndpoint($log, $endpoint);
    }

    // Same verdict as cantReach(), plus the running count of answers that
    // never arrived. What the host refused to answer for one topic it will
    // refuse for the next, so once the refusals stop being isolated the rest
    // of that endpoint's requests are skipped -- three wasted requests instead
    // of a hundred and twenty-two. Any answer that does arrive clears the count, so a blip
    // never accumulates across an otherwise healthy cycle.
    //
    // A challenge is a refusal with a page attached, and the status alone
    // cannot say so; where one is recognised the line names it, so a wall of
    // "status=403" reads as what is actually in the way. Recognised the way
    // the details path routes on it -- RuTrackerFetchError::isChallenge(),
    // the cf-mitigated header and the interstitial's own markers -- so the
    // log and the routing never disagree about what an answer was.
    static private function unreachable($log, $client, $endpoint, $freshResponse = true)
    {
        if ($freshResponse && RuTrackerFetchError::isChallenge($client->headers, $client->results))
            $log .= ' (Cloudflare challenge)';
        $failures = ($endpoint === 'download')
            ? ++self::$downloadTransportFailures
            : ++self::$detailsTransportFailures;
        if ($failures < self::TRANSPORT_TOLERANCE)
            return self::cantReach($log);
        return self::abandonEndpoint($log, $endpoint);
    }

    // What the bytes from the download endpoint say about this topic.
    //
    // One reader for both callers. The handler used to decide this in the one
    // place it could be reached from, and the guest latch above shows what
    // that costs: a second way in that skipped the counting went unnoticed
    // until the tracker made it the only way in.
    //
    // $detailsSilent is what separates them, and it decides two things.
    //
    // Reached the ordinary way, the details answer has ALREADY said the
    // infohash moved on, so these bytes are handed to the replacement as the
    // new torrent -- createTorrent() compares the hash again before it acts,
    // so a download that has not caught up with the details answer yet is
    // caught there rather than here -- and that same answer named a hash for
    // this id, so "there is no such id" from the other endpoint is a
    // contradiction rather than a verdict.
    //
    // Reached past a walled details endpoint, no authoritative details hash
    // is available. createTorrent() compares downloaded metainfo with the
    // old hash before any replacement work. A "no such id" answer has nothing
    // to contradict it on this path.
    static private function decideFromDownload($client, $id, $hash, $old_torrent, $detailsSilent,
        &$independent = null)
    {
        $independent = false;
        // A guest download is a redirect to login.php. Whether Snoopy follows
        // it to the 200 login page (the guest marker below catches that) or
        // stops on a 3xx, the answer is retryable. A non-200 status alone
        // cannot establish whether the session failed; it is never deletion.
        if ($client->status != 200) {
            // The redirect was observed in this fetch, not inherited from the
            // details request. A challenged login page still proves a guest wall.
            $redirect = isset($client->lastredirectaddr) ? $client->lastredirectaddr : '';
            if (UrlHost::urlIsOneOf($redirect, self::SITE_HOSTS, 'https')
                && @parse_url($redirect, PHP_URL_PATH) === '/login.php') {
                self::$downloadTransportFailures = 0;
                return self::guestAnswer("download.php redirected to login.php: id=".$id, 'download');
            }
            return self::unreachable("download.php failed: status=".$client->status." id=".$id,
                $client, 'download');
        }
        // An answer arrived, so the host is reachable, whatever the body says
        // -- a guest page included. Cleared here, ahead of every reading of
        // the body, because the two streaks count different things: a guest
        // page must not stand in a count of refusals, or two refusals, a login
        // wall and one more refusal skip a cycle that no three of them would
        // have skipped as one kind.
        self::$downloadTransportFailures = 0;

        // Metainfo first: bytes that parse ARE the torrent, whatever text they
        // happen to contain, so a torrent is never mistaken for a login wall.
        // The one decode happens here and its result is what gets replaced.
        $payload = (string) $client->results;
        $parsed = ruTrackerChecker::parseMetainfo($payload);
        if ($parsed !== null) {
            // The bytes, not createTorrent()'s later result code, prove that
            // this topic received an independent answer from download.php.
            $independent = true;
            self::$downloadGuestAnswers = 0;
            return ruTrackerChecker::createTorrent($parsed, $hash, $old_torrent);
        }
        if (self::isGuestAnswer($payload))
            return self::guestAnswer("download.php answered a guest page, check the loginmgr account: id=".$id,
                'download');
        self::$downloadGuestAnswers = 0;
        // Checked after the guest test above, the way the details endpoint's
        // own missing marker is: a session that has died must never read as a
        // topic that is gone.
        if ($detailsSilent && self::isDownloadMissingAnswer($payload)) {
            $independent = true;
            return ruTrackerChecker::STE_DELETED;
        }
        // Malformed bytes can concern this topic alone (including a download
        // contradicting a details hash), so they do not trip an endpoint latch.
        return self::cantReach("download.php returned no metainfo: id=".$id." bytes=".strlen($payload));
    }

    static private function downloadClient($id)
    {
        return ruTrackerChecker::makeClient(self::DOWNLOAD_URL.$id);
    }

    static public function download_torrent($url, $hash, $old_torrent)
    {
        if (!preg_match(self::topicPattern(), $url, $matches))
            return ruTrackerChecker::STE_DECLINED;

        // Checked after the URL match, so a topic this handler does not own
        // still falls through to STE_DECLINED exactly as before.
        if (self::$cycleAbandoned)
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;

        $id = $matches["id"];

        // The details endpoint already refused this run, with a page rather
        // than a failure. Try the independently fetched download endpoint.
        if (self::$detailsWalled && self::$downloadAbandoned)
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        if (self::$detailsWalled)
            return self::decideFromDownload(self::downloadClient($id), $id, $hash, $old_torrent, true);

        $client = ruTrackerChecker::makeClient("https://kinozal.guru/get_srv_details.php?action=2&id=".$id);
        if ($client->status != 200) {
            // A challenge is not an outage. It is a door that will not open
            // for this client however often it is asked -- so it is neither
            // counted as unreachable nor retried, and the check continues
            // through the endpoint that is not behind one, which has a missing
            // marker of its own -- see DOWNLOAD_MISSING_CP1251. Told apart
            // from an outage by the header Cloudflare sets for the purpose and
            // by the interstitial's own markers, never by the word
            // "cloudflare": an origin that is down answers through Cloudflare
            // too, with that word on the page, and that is an outage to count.
            if (RuTrackerFetchError::isChallenge($client->headers, $client->results)) {
                self::$detailsWalled = true;
                if (self::$downloadAbandoned) return ruTrackerChecker::STE_CANT_REACH_TRACKER;
                ruTrackerChecker::logDebug("get_srv_details is behind a Cloudflare challenge (status="
                    .$client->status."); this cycle checks Kinozal through download.php,"
                    ." reading its own missing marker for deletions");
                return self::decideFromDownload(self::downloadClient($id), $id, $hash, $old_torrent, true);
            }
            // HTTP 403 alone does not identify a challenge, but the other
            // endpoint can answer this topic without interpreting its body.
            // One bounded download probe avoids losing a whole cycle when
            // Cloudflare changes its page and the documented header is lost.
            // A failed probe still counts the details refusal below.
            // Snoopy stores the parsed HTTP code as a numeric string.
            if ($client->status == 403 && !self::$downloadAbandoned) {
                $independent = false;
                $verdict = self::decideFromDownload(self::downloadClient($id),
                    $id, $hash, $old_torrent, true, $independent);
                if ($independent) {
                    // A 403 can be specific to one topic. The successful
                    // download settles this topic only; the next one must
                    // still ask details for its own authoritative verdict.
                    self::$detailsTransportFailures = 0;
                    ruTrackerChecker::logDebug("get_srv_details returned unclassified status=403;"
                        . " id=" . $id . " checked through download.php");
                    return $verdict;
                }
            }
            return self::unreachable("get_srv_details failed: status=".$client->status." id=".$id,
                $client, 'details');
        }

        $details = (string) $client->results;
        // This answer came back, so the host is reachable, whatever the body
        // turns out to say -- a guest page included. Cleared ahead of the
        // guest test for that reason: the two streaks count different things,
        // and a guest page must not finish a count of refusals.
        self::$detailsTransportFailures = 0;
        if (self::isGuestAnswer($details))
            return self::guestAnswer("get_srv_details answered a guest page, check the loginmgr account: id=".$id,
                'details');
        // An authenticated answer clears the count: only guest answers that
        // run together mean a lost session, and isolated ones must not add up
        // across an otherwise healthy cycle.
        self::$detailsGuestAnswers = 0;

        // Strict comparison: loose == reads a hex hash shaped like scientific
        // notation as a number -- '1E' followed by 38 zeros == '00...01'
        // (both are numerically 1) -- so two different 40-char hashes could
        // pass as equal.
        $hasHash = preg_match('`<li>.*(?P<hash>[0-9A-Fa-f]{40})</li>`', $details, $matches1);
        if ($hasHash) {
            if (strtoupper($matches1["hash"]) === strtoupper((string) $hash))
                return ruTrackerChecker::STE_UPTODATE;
        } else {
            if (self::isMissingAnswer($details))
                return ruTrackerChecker::STE_DELETED;
            // An unreadable topic is not evidence of an endpoint-wide outage.
            return self::cantReach("get_srv_details returned unrecognised content: id=".$id." bytes=".strlen($details));
        }

        if (self::$downloadAbandoned) return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        $client->setcookies();
        // Keep the cookies learned from details while making a failed fetch
        // unable to expose that earlier response as if it came from download.
        // loginmgr can refuse before Snoopy itself clears the old redirect.
        $client->status = -1;
        $client->results = '';
        $client->headers = array();
        $client->error = '';
        $client->lastredirectaddr = '';
        if (!$client->fetchComplex(self::DOWNLOAD_URL.$id) && (string) $client->error !== '')
            return self::unreachable("download.php failed before response: "
                . RuTrackerFetchError::classify($client->error) . " id=" . $id,
                $client, 'download', false);
        // Snoopy can return false after recording an HTTP answer. With no
        // transport error, its fresh status and body still identify a guest
        // page or challenge and must use the same reader as a true return.
        // The details answer has already said the infohash moved on; the
        // replacement path compares the downloaded hash once more itself.
        return self::decideFromDownload($client, $id, $hash, $old_torrent, false);
    }
}

// The hosts Kinozal announces on, written once and read twice: as the
// registry's announce filter, a substring test over whole URLs that decides
// which torrents are this handler's to look at, and as the authority list
// below, matched whole through UrlHost, which decides which of them may be
// certified alive without a look. Two hand-written patterns for one set of
// hosts had already drifted apart -- the authority named kinozal.me and
// kinozal.guru, the filter did not, and a host the filter never admits can
// certify nothing.
$kinozalAnnounceHosts = array_merge(array('torrent4me.com', 'tor4me.info', 'tor2me.info'),
    KinozalCheckImpl::SITE_HOSTS);

// The fourth argument says a successful announce on one of these hosts proves
// the topic still holds this infohash, so the scheduler may answer UPTODATE
// without spending a request; the fifth is the exact topic test that makes
// the scheduler's idea of "Kinozal's torrent" the same as this handler's --
// see ruTrackerChecker::registerTracker() for why the loose comment filter
// cannot be that.
//
// This rests on ONE assumption, stated here because it is not proven: that
// Kinozal stops answering announces for an infohash once its topic is
// re-uploaded or removed -- the event this handler exists to notice -- so
// that a live announce and a current topic are the same fact. It is the way
// trackers normally behave (a re-upload registers a new infohash and drops
// the old, whose announce then fails and takes the row to 'candidate', which
// is NOT short-circuited and reaches the handler). It was NOT measured on
// Kinozal: as of 2026-09-14 no topic in the fleet was known to have been
// re-uploaded or removed, so there was nothing to observe it on, and
// dl.kinozal.guru times out under a burst of download probes. If the
// assumption is wrong -- Kinozal keeps a dead infohash registered -- a
// re-uploaded topic reads UPTODATE for as long as its old announce keeps
// succeeding, and the re-upload is missed. The gate only ever writes
// UPTODATE, never a deletion, so the failure mode is a missed update, not a
// destroyed torrent. Re-check the first time a Kinozal topic is known to
// have been re-uploaded or removed: the OLD torrent's announce counters
// (t.failed_counter, t.success_counter) answer it -- still succeeding means
// the assumption is wrong.
//
// It earns its keep while the details endpoint is walled: the only other way
// to check a topic then is to download the whole .torrent, one per due topic
// per hour -- 122 due of 147 held, about 200 KB each. The shared makeClient
// timeout is five seconds; the 2026-09-14 burst exhausted it. Three consecutive
// download failures trip its fuse, so this fallback relies on the announce
// gate to limit load. A separate download timeout needs its own measurement.
// Measured 2026-09-12 on that fleet: 66 of the 122 were already answering, 4 were failing, and the
// other 52 were only cold because the daemon had restarted -- announces run
// hourly, so the share climbs through a session rather than staying there.
// Measured again 2026-09-14, later in a session: 122 of 122 answering.
//
// The authority is the host list matched whole where the registry filter
// beside it is a substring test; RuTrackerDetector::announceVerdict() says why.
ruTrackerChecker::registerTracker("/kinozal\./",
	'/' . implode('|', array_map(function ($host) { return preg_quote($host, '/'); }, $kinozalAnnounceHosts)) . '/',
	"KinozalCheckImpl::download_torrent",
	$kinozalAnnounceHosts,
	KinozalCheckImpl::topicPattern());
