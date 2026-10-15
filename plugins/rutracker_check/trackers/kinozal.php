<?php

require_once( __DIR__ . '/../fetcherror.php' );
require_once( __DIR__ . '/../../../php/urlhost.php' );
require_once( __DIR__ . '/../../../php/kinozal.php' );

class KinozalCheckImpl
{
    const SITE_HOSTS = KinozalResponse::SITE_HOSTS;
    // The trackers a Kinozal torrent announces to. Each announce URL carries
    // the downloading account's passkey as uk= (live fleet, 2026-10-14).
    const ANNOUNCE_HOSTS = array('torrent4me.com', 'tor4me.info', 'tor2me.info');

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

    // The details endpoint's own verdict for an id it no longer serves: HTTP
    // 200 with this body, in an authenticated session. One of the two
    // authoritative deletion signals this handler has -- the other is the
    // download endpoint's, below -- and each is read only at its own endpoint.
    // Everything the handler merely fails to check stays retryable, so a
    // login wall, a dead socket or a layout change can never masquerade as a
    // removed release. Measured on kinozal.guru (2026-08-07) and, with a
    // trailing period, on kinozal.jumpingcrab.com (2026-10-15).
    const MISSING_MARKER = 'Торрент файл не найден';
    // Compatibility for legacy-encoded details answers; literal bytes also work without iconv.
    const MISSING_MARKER_CP1251 = "\xd2\xee\xf0\xf0\xe5\xed\xf2 \xf4\xe0\xe9\xeb \xed\xe5 \xed\xe0\xe9\xe4\xe5\xed";

    // The DOWNLOAD endpoint's own verdict for an id it does not serve: HTTP 200
    // carrying the site's ordinary error page, whose one-line body is this.
    // KinozalHandlerTest pins the 4010-byte page dl.kinozal.guru served on
    // 2026-09-14, identical for an id that never existed and a vanished fleet
    // topic, and the 5124-byte page kinozal.jumpingcrab.com served for an id
    // that never existed on 2026-10-15. It means what MISSING_MARKER above means:
    // this id serves no torrent. Read only after details refused the topic
    // without a classified reason, without a contradicting hash.
    //
    // The captured answer is windows-1251; the UTF-8 spelling is a defensive
    // compatibility case, not an observed answer from download.php. Both are
    // literal bytes, not converted. The production container's PHP has no
    // iconv() at all, so a needle that relied on conversion would never match.
    // Carrying both spellings costs one comparison and depends on no extension.
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

    // Per-process latches. Production runs one PHP process per cycle
    // (update.php, batch_check.php), so none outlives it. They are set by
    // answers that concern Kinozal rather than one topic: a session the
    // tracker has stopped honouring (guestAnswer), a rate limit, or no door
    // left that answers. Without them every Kinozal torrent spent a request
    // proving the same thing over again -- 130 of them per cycle on the live
    // fleet -- and buried the log under 130 identical lines. The download
    // latch stops only download.php and leaves healthy details checks running.
    // Each latch dies with the process, so the next cycle retries from
    // scratch and a restored session or a recovered host heals itself.
    static private $cycleAbandoned = false;
    static private $downloadAbandoned = false;
    // One ungated application-log line per cycle for each of these.
    static private $sessionHintLogged = false;
    static private $skipLogged = array();
    static private $failoverLogged = false;

    // How many guest answers in a row mean the session is really gone rather
    // than blinking. Measured on the live fleet: one cycle got a guest answer
    // and the next, three seconds later, was authorised again with the very
    // same stored cookies. Latching on that single answer cost every remaining
    // Kinozal torrent an hour of waiting, so one is forgiven and the second
    // in a row is believed -- two wasted requests instead of a hundred and
    // thirty, and a blink no longer skips the cycle.
    const GUEST_TOLERANCE = 2;

    // The details and download endpoints can wall the session independently:
    // download.php redirects a guest to login.php while get_srv_details.php
    // answers in place. A healthy details answer must therefore not erase a
    // guest streak reported by the download endpoint.
    static private $detailsGuestAnswers = 0;
    static private $downloadGuestAnswers = 0;

    // How many door failures in a row mean the door is refusing everybody
    // rather than one topic misbehaving: a transport failure, an HTTP refusal
    // or an answer that is not Kinozal's. A guest answer and a door failure
    // are counted apart because they mean different things and are repaired
    // differently: one is a dead session, the other an unreachable door, and
    // a login will not fix the second.
    //
    // Three rather than the guest path's two: a single 5xx or a dropped
    // socket belongs to the topic that hit it, and losing a door for the
    // rest of an hourly cycle to one blip costs more than two requests.
    const TRANSPORT_TOLERANCE = 3;

    // Answers whose guest markers could not be parsed (isGuestPage() null).
    // Such an answer proves neither a session nor a login wall, so it is
    // never a guest streak or a verdict. It is still bounded: loginmgr keeps
    // a stored session without logging in again, but with none stored it
    // cannot confirm a fresh login either, and every later request would
    // spend another credential POST. Counted per endpoint like door failures
    // and cleared by a readable answer: metainfo or a page the parser could
    // read. It does not move to another door, which would log in there too.
    static private $detailsUnverified = 0;
    static private $downloadUnverified = 0;

    // Doors are the configured Kinozal hosts (KinozalResponse::doors()). Both
    // answers for a topic come from one door, because session cookies belong
    // to the host that set them. A door that does not answer a topic -- a
    // transport failure, an HTTP refusal, a challenge, or a page that is not
    // Kinozal's -- is a fact about the door, not a topic verdict, so the same
    // topic is asked at the next open door at once, and the door that
    // answered is asked first from then on. TRANSPORT_TOLERANCE failures in a
    // row close an endpoint of a door for the rest of the cycle, a challenge
    // closes the door at once, and a cycle with no open door left stops.
    static private $door = 0;
    static private $failures = array();
    static private $lastFailure = array();
    static private $closed = array();

    // Internal answer of a door that did not answer the topic.
    const NEXT_DOOR = 'next-door';

    // Called once by the scheduler/manual batch, never once per hash.
    static public function resetCycle()
    {
        self::$cycleAbandoned = self::$downloadAbandoned = self::$sessionHintLogged = self::$failoverLogged = false;
        self::$detailsGuestAnswers = self::$downloadGuestAnswers = 0;
        self::$detailsUnverified = self::$downloadUnverified = 0;
        self::$door = 0;
        self::$failures = self::$lastFailure = self::$closed = self::$skipLogged = array();
        KinozalResponse::resetCycle();
    }

    // The hosts this cycle asks, in the configured order.
    static private function doors()
    {
        return KinozalResponse::doors();
    }

    static public function detailsUrl($id, $door = 0)
    {
        return 'https://' . self::doors()[$door] . '/get_srv_details.php?action=2&id=' . $id;
    }

    static public function downloadUrl($id, $door = 0)
    {
        return 'https://' . self::doors()[$door] . '/download.php?id=' . $id;
    }

    // The doors to ask for $endpoint, the one that answered last first.
    static private function doorOrder($endpoint, $first)
    {
        $order = array_merge(array($first), array_diff(array_keys(self::doors()), array($first)));
        return array_values(array_filter($order, function ($door) use ($endpoint) {
            return !self::isClosed($door, $endpoint);
        }));
    }

    static private function isClosed($door, $endpoint)
    {
        return isset(self::$closed[$door . '|details'])
            || ($endpoint === 'download' && (self::$downloadAbandoned || isset(self::$closed[$door . '|download'])));
    }

    // A door that answered becomes the one asked first. The guest and
    // unverified counts and the download latch stay: they describe the
    // account, which every door shares, and each such answer at any door
    // spent a login with the same credentials. Starting them afresh let two
    // doors failing in turn spend a credential POST on every topic.
    static private function answeredBy($door, $endpoint)
    {
        self::$failures[$door . '|' . $endpoint] = 0;
        if ($door === self::$door) return;
        $previous = self::$door . '|' . $endpoint;
        if (!self::$failoverLogged && isset(self::$lastFailure[$previous])) {
            self::$failoverLogged = true;
            ruTrackerChecker::logUnrepairable('kinozal: ' . self::doors()[self::$door] . ' did not answer ('
                . self::$lastFailure[$previous] . '); this cycle continues on ' . self::doors()[$door]);
        }
        self::$door = $door;
    }

    // A door did not answer this topic. Counted, and once the failures stop
    // being isolated the endpoint is closed at this door; the caller asks the
    // next open door.
    static private function doorFailed($door, $endpoint, $reason, $log)
    {
        ruTrackerChecker::logDebug($log);
        $key = $door . '|' . $endpoint;
        self::$lastFailure[$key] = $reason;
        self::$failures[$key] = (isset(self::$failures[$key]) ? self::$failures[$key] : 0) + 1;
        if (self::$failures[$key] >= self::TRANSPORT_TOLERANCE)
            self::closeDoor($door, $endpoint, $endpoint . '-unreachable',
                self::TRANSPORT_TOLERANCE . ' failures in a row, last ' . $reason);
        return self::NEXT_DOOR;
    }

    // $reason classifies why, for the cycle line once no door is left;
    // $detail is what this door's own line says.
    static private function closeDoor($door, $endpoint, $reason, $detail)
    {
        if (isset(self::$closed[$door . '|' . $endpoint])) return;
        self::$closed[$door . '|' . $endpoint] = true;
        ruTrackerChecker::logUnrepairable('kinozal: ' . self::doors()[$door]
            . ($endpoint === 'download' ? ' download.php' : '') . ' is skipped for the rest of this cycle ('
            . $detail . ')');
        if (!self::doorOrder('details', self::$door))
            self::abandonCycle($reason);
        elseif ($endpoint === 'download' && !self::doorOrder('download', self::$door))
            self::logCycleSkip('download', $reason);
    }

    static private function abandonCycle($reason)
    {
        self::$cycleAbandoned = true;
        self::logCycleSkip('all', $reason);
    }

    static private function logCycleSkip($scope, $reason)
    {
        if (isset(self::$skipLogged[$scope])) return;
        self::$skipLogged[$scope] = true;
        ruTrackerChecker::logUnrepairable('kinozal: Kinozal checks skipped for the rest of this cycle: scope='
            . $scope . ' reason=' . $reason . '; retried next cycle');
    }

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
    static private function abandonEndpoint($log, $endpoint, $reason)
    {
        if ($endpoint === 'download') {
            self::$downloadAbandoned = true;
            self::logCycleSkip('download', $reason);
            return self::cantReach($log . ', this endpoint is skipped for the rest of this cycle');
        }
        self::abandonCycle($reason);
        return self::cantReach($log . ', the Kinozal handler is skipped for the rest of this cycle');
    }

    // Retryable for this topic; TRANSPORT_TOLERANCE in a row skip the endpoint.
    static private function unverifiedAnswer($log, $endpoint)
    {
        $answers = ($endpoint === 'download')
            ? ++self::$downloadUnverified
            : ++self::$detailsUnverified;
        if ($answers < self::TRANSPORT_TOLERANCE)
            return self::cantReach($log);
        return self::abandonEndpoint($log, $endpoint, $endpoint . '-unverified');
    }

    // Count guest answers per endpoint; one isolated login wall is retryable.
    static private function guestAnswer($log, $endpoint)
    {
        $answers = ($endpoint === 'download')
            ? ++self::$downloadGuestAnswers
            : ++self::$detailsGuestAnswers;
        if ($answers < self::GUEST_TOLERANCE)
            return self::cantReach($log);
        self::logSessionHint();
        return self::abandonEndpoint($log, $endpoint, $endpoint . '-guest-session');
    }

    // The endpoint details stay in the debug log; the operator still needs to
    // learn, at the shipped default, that no authenticated session was
    // available. fetchComplex may leave a response from a re-login attempt,
    // so this observation does not identify the final endpoint.
    static private function logSessionHint()
    {
        if (self::$sessionHintLogged)
            return;
        self::$sessionHintLogged = true;
        ruTrackerChecker::logUnrepairable('Kinozal answers as a guest: no authenticated KinozalTV session was available for these checks;'
            .' re-enter the KinozalTV login and password in Settings -> Accounts and Save;'
            .' Kinozal update checks resume after a successful login');
    }

    // What a finished request says before its body is read, for the answers
    // that concern Kinozal rather than one door or one topic. Snoopy paces
    // every Kinozal host as one group -- the mirrors are one IP behind one
    // limiter, and the Cloudflare-fronted site hosts are paused with them as
    // a precaution -- so a 429, or the pause Snoopy keeps after one, stops
    // every door for the cycle. A credential POST that went out and failed
    // (KinozalTV marks the client) concerns the account: another door would
    // only receive the password again.
    static private function accountAnswer($client, $endpoint, $id)
    {
        if (KinozalResponse::isRateLimited($client)) {
            self::abandonCycle('rate-limited');
            return self::cantReach($endpoint . ' attempt: rate limited id=' . $id);
        }
        // No credential POST until the login pause ends: every door shares it.
        if (KinozalResponse::isLoginPaused($client)) {
            self::abandonCycle('login-paused');
            return self::cantReach($endpoint . ' attempt: login paused id=' . $id);
        }
        if (self::errorClass($client) === 'credential-post-failed')
            return self::guestAnswer($endpoint . ' attempt: the credential POST failed: id=' . $id, $endpoint);
        return null;
    }

    static private function errorClass($client)
    {
        return RuTrackerFetchError::classify(isset($client->error) ? $client->error : '');
    }

    // A classified token for a request that brought no usable answer. Snoopy
    // leaves curl's exit code as the status, which tells DNS from connect.
    static private function refusal($client)
    {
        $error = self::errorClass($client);
        if ($error === 'curl-transfer') return $error . '-' . (int) $client->status;
        return $error !== '' ? $error : 'http-' . (int) $client->status;
    }

    // A false fetch may leave the status of a loginmgr request here, so a
    // status is named as the client's last one, not as the endpoint's answer.
    static private function failedAttempt($endpoint, $client, $id)
    {
        $error = self::errorClass($client);
        return $endpoint . ' attempt failed: ' . ($error !== '' ? $error : 'last-status=' . $client->status)
            . ' id=' . $id;
    }

    // The bytes a door serves replace a torrent only once they prove to be
    // this topic's torrent for this account: the topic id in the comment,
    // and a passkey the old torrent announces with on a Kinozal tracker.
    // That stops what a door may serve by mistake -- another topic's or
    // another account's torrent, a parked name's answer -- from erasing a
    // started torrent and the payload files the new one does not list. It
    // does not stop a new owner of a mirror's name, which receives the
    // account's password at login and with it the account's own downloads.
    // A torrent added under another account, or before its passkey was
    // reset, is refused every cycle, visibly, until it is added again.
    static private function replacementRefusal($torrent, $id, $oldTorrent)
    {
        if (!preg_match(self::topicPattern(), (string) $torrent->comment(), $topic) || $topic['id'] !== (string) $id)
            return 'topic-mismatch';
        $passkeys = self::passkeys($oldTorrent);
        if (!$passkeys)
            return 'passkey-unknown';
        if (!array_intersect($passkeys, self::passkeys($torrent)))
            return 'passkey-mismatch';
        return null;
    }

    static private function passkeys($torrent)
    {
        $passkeys = array();
        if (!($torrent instanceof Torrent)) return $passkeys;
        $urls = array($torrent->announce(), $torrent->announce_list());
        array_walk_recursive($urls, function ($url) use (&$passkeys) {
            if (!is_string($url) || !UrlHost::urlIsOneOf($url, self::ANNOUNCE_HOSTS)) return;
            parse_str((string) @parse_url($url, PHP_URL_QUERY), $query);
            if (isset($query['uk']) && is_string($query['uk']) && $query['uk'] !== '')
                $passkeys[] = $query['uk'];
        });
        return array_values(array_unique($passkeys));
    }

    // What the download attempt's final response says about this topic.
    // fetchComplex may leave a loginmgr response before fetching the caller URL.
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
    // contradiction rather than a verdict. A door that does not answer is
    // reported as NEXT_DOOR, and the caller asks the next one.
    //
    // Reached as the probe after details refused the topic with an
    // unclassified 403 at the last open door in its order (the doors before
    // it may have failed for any reason), no authoritative details hash is
    // available. createTorrent() compares downloaded metainfo with the old
    // hash before any replacement work, and a "no such id" answer has nothing
    // to contradict it. A probe that brings no answer ends as "could not
    // check" without a door failure of its own: the details refusal it
    // follows is counted instead. Its guest and unverified answers count
    // like any other, and a challenge it meets closes the door.
    static private function decideFromDownload($client, $door, $id, $hash, $old_torrent, $detailsSilent,
        &$answered = null)
    {
        $answered = false;
        $url = self::downloadUrl($id, $door);
        if (KinozalResponse::observeChallenge($client, $url))
            return self::doorUnanswered($door, 'download', 'challenge', 'download attempt: Cloudflare challenge id='
                . $id, $detailsSilent);
        $account = self::accountAnswer($client, 'download', $id);
        if ($account !== null) return $account;
        // A guest download attempt may encounter a redirect to login.php. Whether Snoopy follows
        // it to the 200 login page (the guest marker below catches that) or
        // stops on a 3xx, the answer is retryable. A non-200 status alone
        // cannot establish whether the session failed; it is never deletion.
        if ($client->status != 200) {
            // This attempt observed the redirect, possibly during loginmgr,
            // rather than inheriting it from details. It still proves a guest wall.
            $redirect = isset($client->lastredirectaddr) ? $client->lastredirectaddr : '';
            if (UrlHost::urlIsOneOf($redirect, KinozalResponse::accountHosts(), 'https')
                && @parse_url($redirect, PHP_URL_PATH) === '/login.php') {
                self::answeredBy($door, 'download');
                self::$downloadUnverified = 0;
                return self::guestAnswer("download attempt encountered a login.php redirect: id=".$id, 'download');
            }
            return self::doorUnanswered($door, 'download', self::refusal($client),
                self::failedAttempt('download', $client, $id), $detailsSilent);
        }

        // Metainfo first: bytes that parse ARE the torrent, whatever text they
        // happen to contain, so a torrent is never mistaken for a login wall.
        // The one decode happens here and its result is what gets replaced.
        $payload = (string) $client->results;
        $parsed = ruTrackerChecker::parseMetainfo($payload);
        if ($parsed !== null) {
            // The bytes, not createTorrent()'s later result code, prove that
            // this topic received an independent answer from download.php.
            $answered = true;
            self::answeredBy($door, 'download');
            self::$downloadGuestAnswers = 0;
            self::$downloadUnverified = 0;
            if (strcasecmp((string) $parsed->hash_info(), (string) $hash) !== 0) {
                $refusal = self::replacementRefusal($parsed, $id, $old_torrent);
                if ($refusal !== null) {
                    ruTrackerChecker::logUnrepairable('kinozal: replacement refused (' . $refusal . '): id=' . $id
                        . ' from ' . self::doors()[$door] . '; the torrent is left as it is'
                        . ($refusal === 'topic-mismatch' ? '' : '; a torrent added under another account or'
                            . ' before a passkey reset is updated by adding the topic again'));
                    return self::cantReach('download attempt: replacement refused (' . $refusal . ') id=' . $id);
                }
            }
            return ruTrackerChecker::createTorrent($parsed, $hash, $old_torrent);
        }
        $guest = KinozalResponse::isGuestPage($payload);
        if ($guest === null) {
            self::answeredBy($door, 'download');
            return self::unverifiedAnswer("download attempt: guest-page-parse-failed id=".$id, 'download');
        }
        self::$downloadUnverified = 0;
        if ($guest) {
            self::answeredBy($door, 'download');
            return self::guestAnswer("download attempt ended at a guest page, check the loginmgr account: id=".$id,
                'download');
        }
        self::$downloadGuestAnswers = 0;
        // Checked after the guest test above, the way the details endpoint's
        // own missing marker is: a session that has died must never read as a
        // topic that is gone.
        if ($detailsSilent && self::isDownloadMissingAnswer($payload)) {
            $answered = true;
            self::answeredBy($door, 'download');
            return ruTrackerChecker::STE_DELETED;
        }
        // A page that is neither metainfo nor Kinozal's own answer is what a
        // door under maintenance or a parked name serves.
        return self::doorUnanswered($door, 'download', 'not-metainfo',
            "download attempt ended without metainfo: id=".$id." bytes=".strlen($payload), $detailsSilent);
    }

    // A door that did not answer: counted toward closing it, unless this was
    // the probe after a details refusal, which that refusal already counted.
    static private function doorUnanswered($door, $endpoint, $reason, $log, $probe)
    {
        if ($reason === 'challenge') {
            ruTrackerChecker::logDebug($log);
            self::closeDoor($door, 'details', 'cloudflare-challenge', 'Cloudflare challenge');
            return $probe ? ruTrackerChecker::STE_CANT_REACH_TRACKER : self::NEXT_DOOR;
        }
        if ($probe) return self::cantReach($log);
        return self::doorFailed($door, $endpoint, $reason, $log);
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
        // Every request below, loginmgr's and redirect hops included, keeps
        // Kinozal's pace and its pause after HTTP 429, across processes.
        KinozalResponse::throttle();
        $doors = self::doorOrder('details', self::$door);
        foreach ($doors as $index => $door) {
            if (self::isClosed($door, 'details')) continue;
            $verdict = self::askDoor($door, $id, $hash, $old_torrent, $index === count($doors) - 1);
            if ($verdict !== self::NEXT_DOOR) return $verdict;
            if (self::$cycleAbandoned) break;
        }
        return self::cantReach("no Kinozal door answered: id=".$id);
    }

    // Both answers for one topic at one door. NEXT_DOOR when the door did not
    // answer and the topic is to be asked at the next one.
    static private function askDoor($door, $id, $hash, $old_torrent, $lastDoor)
    {
        $detailsUrl = self::detailsUrl($id, $door);
        // A challenge seen at this site earlier in this run, by loginmgr included.
        if (KinozalResponse::requestsBlocked($detailsUrl)) {
            self::closeDoor($door, 'details', 'cloudflare-challenge', 'Cloudflare challenge');
            return self::NEXT_DOOR;
        }
        $client = ruTrackerChecker::makeClient($detailsUrl);
        // The final answer can belong to loginmgr at any URL/status. Stop
        // before another route or credential attempt, including on HTTP 200.
        if (KinozalResponse::observeChallenge($client, $detailsUrl)) {
            ruTrackerChecker::logDebug('details attempt: Cloudflare challenge id=' . $id);
            self::closeDoor($door, 'details', 'cloudflare-challenge', 'Cloudflare challenge');
            return self::NEXT_DOOR;
        }
        $account = self::accountAnswer($client, 'details', $id);
        if ($account !== null) return $account;
        if ($client->status != 200) {
            // HTTP 403 alone does not identify a challenge or the reason. Once
            // no other door can answer details, download.php at this door can
            // still answer the topic without interpreting the refusal: a door
            // may well refuse get_srv_details.php alone. A probe that brings
            // no answer leaves the details refusal below as the only count.
            // Snoopy stores the parsed HTTP code as a numeric string.
            if ($client->status == 403 && $lastDoor && !self::isClosed($door, 'download')) {
                $probe = self::decideFromDownload(ruTrackerChecker::makeClient(self::downloadUrl($id, $door)), $door,
                    $id, $hash, $old_torrent, true, $answered);
                if (self::$cycleAbandoned) return $probe;
                if ($answered) {
                    // A 403 can be specific to one topic. The probe settles
                    // this topic only; the next one must still ask details
                    // for its own authoritative verdict.
                    self::$failures[$door . '|details'] = 0;
                    ruTrackerChecker::logDebug("details attempt ended at unclassified last-status=403;"
                        . " id=" . $id . " checked through download.php");
                    return $probe;
                }
            }
            return self::doorFailed($door, 'details', self::refusal($client),
                self::failedAttempt('details', $client, $id));
        }

        $details = (string) $client->results;
        // This answer came back, so the door answers, whatever the body turns
        // out to say -- a guest page included.
        $guest = KinozalResponse::isGuestPage($details);
        if ($guest === null) {
            self::answeredBy($door, 'details');
            return self::unverifiedAnswer("details attempt: guest-page-parse-failed id=".$id, 'details');
        }
        self::$detailsUnverified = 0;
        if ($guest) {
            self::answeredBy($door, 'details');
            return self::guestAnswer("details attempt ended at a guest page, check the loginmgr account: id=".$id,
                'details');
        }
        // An authenticated answer clears the count: only guest answers that
        // run together mean a lost session, and isolated ones must not add up
        // across an otherwise healthy cycle.
        self::$detailsGuestAnswers = 0;

        // Strict comparison: loose == reads a hex hash shaped like scientific
        // notation as a number -- '1E' followed by 38 zeros == '00...01'
        // (both are numerically 1) -- so two different 40-char hashes could
        // pass as equal.
        $hasHash = preg_match('`<li>.*(?P<hash>[0-9A-Fa-f]{40})</li>`', $details, $matches1);
        if (!$hasHash) {
            if (self::isMissingAnswer($details)) {
                self::answeredBy($door, 'details');
                return ruTrackerChecker::STE_DELETED;
            }
            // Neither a hash nor Kinozal's own answer: a maintenance page, a
            // parked name or a gate in front of this door.
            return self::doorFailed($door, 'details', 'unrecognised-answer',
                "details attempt ended with unrecognised content: id=".$id." bytes=".strlen($details));
        }
        self::answeredBy($door, 'details');
        if (strtoupper($matches1["hash"]) === strtoupper((string) $hash))
            return ruTrackerChecker::STE_UPTODATE;

        // The details answer has said the infohash moved on. The download
        // comes from the same door first: its session answered details.
        foreach (self::doorOrder('download', $door) as $downloadDoor) {
            if (self::$cycleAbandoned) break;
            if (self::isClosed($downloadDoor, 'download')) continue;
            if ($downloadDoor === $door) {
                $client->setcookies();
                // Keep the cookies learned from details while making a failed
                // fetch unable to expose that earlier response as if it came
                // from download. loginmgr can refuse before Snoopy itself
                // clears the old redirect.
                $client->status = -1;
                $client->results = '';
                $client->headers = array();
                $client->error = '';
                $client->lastredirectaddr = '';
                $client->lastResponseURL = '';
                $client->fetchComplex(self::downloadUrl($id, $door));
            } else {
                $client = ruTrackerChecker::makeClient(self::downloadUrl($id, $downloadDoor));
            }
            // Snoopy can return false after recording an HTTP answer. With no
            // transport error, its fresh status and body still identify a guest
            // page or challenge and must use the same reader as a true return.
            $verdict = self::decideFromDownload($client, $downloadDoor, $id, $hash, $old_torrent, false);
            if ($verdict !== self::NEXT_DOOR) return $verdict;
        }
        return self::cantReach("no Kinozal door served the torrent: id=".$id);
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
$kinozalAnnounceHosts = array_merge(KinozalCheckImpl::ANNOUNCE_HOSTS, KinozalCheckImpl::SITE_HOSTS);

// The fourth argument says a successful announce on one of these hosts proves
// the topic still holds this infohash, so the scheduler may answer UPTODATE
// without spending a request; the fifth is the exact topic test that makes
// the scheduler's idea of "Kinozal's torrent" the same as this handler's --
// see ruTrackerChecker::registerTracker() for why the loose comment filter
// cannot be that.
//
// This rests on ONE assumption: that Kinozal stops answering announces for an
// infohash once its topic is re-uploaded or removed -- the event this handler
// exists to notice -- so that a live announce and a current topic are the
// same fact. It is the way trackers normally behave (a re-upload registers a
// new infohash and drops the old, whose announce then fails and takes the row
// to 'candidate', which is NOT short-circuited and reaches the handler).
// Measured once on the live fleet, 2026-10-14: topic 2154369 had been
// re-uploaded on 2026-09-29, and its old infohash drew 0 successes, 21
// failures and 'Failure reason "unregistered torrent"' from torrent4me, while
// all 125 started topics whose infohash the site still named announced
// successfully. One re-upload is a sample, not a proof. If the assumption is
// wrong -- Kinozal keeps a dead infohash registered -- a re-uploaded topic
// reads UPTODATE for as long as its old announce keeps succeeding, and the
// re-upload is missed. The gate only ever writes UPTODATE, never a deletion,
// so the failure mode is a missed update, not a destroyed torrent.
//
// It avoids an HTTP attempt for an already answering torrent. A recognised
// challenge closes only the door that showed it; the announce gate does not
// reopen it. Its separate ownership policy remains above.
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
