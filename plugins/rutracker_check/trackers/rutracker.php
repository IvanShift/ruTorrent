<?php

class RuTrackerCheckImpl
{
    static private function looksLikeHtmlError($content)
    {
        return (stripos($content, '<html') !== false)
            || (stripos($content, '<!DOCTYPE') !== false)
            || (stripos($content, '<center>') !== false)
            || (stripos($content, 'Error:') !== false)
            || (stripos($content, 'attachment data not found') !== false)
            || (stripos($content, 'name="login_password"') !== false);
    }

    // Decode CP1251 HTML to UTF-8 for reliable text search.
    static private function decodePage($content)
    {
        if (!$content) return '';

        $decoded = false;
        if (function_exists('iconv')) {
            $decoded = @iconv('CP1251', 'UTF-8//IGNORE', $content);
        }
        if (($decoded === false) && function_exists('mb_convert_encoding')) {
            $decoded = @mb_convert_encoding($content, 'UTF-8', 'CP1251');
        }
        return ($decoded === false || is_null($decoded)) ? $content : $decoded;
    }

    // Load the last page of the topic
    static private function extractLastPageHtml($client, $topic_id)
    {
        $topicUrl = "https://rutracker.org/forum/viewtopic.php?t=" . $topic_id;
        $client->setcookies();
        $client->fetchComplex($topicUrl);

        if (($client->status != 200) || empty($client->results)) {
            return null;
        }

        $html = self::decodePage($client->results);
        $lastStart = 0;

        // Look for pagination parameters (&start= or &amp;start=)
        if (preg_match_all('~viewtopic\.php\?t=' . $topic_id . '(?:&amp;|&)start=(\d+)~i', $html, $startMatches) && count($startMatches[1])) {
            $lastStart = max(array_map('intval', $startMatches[1]));
        }

        if ($lastStart > 0) {
            $client->setcookies();
            $client->fetchComplex($topicUrl . "&start=" . $lastStart);
            if (($client->status == 200) && !empty($client->results)) {
                $html = self::decodePage($client->results);
            }
        }

        return $html;
    }

    // Detect new topic (if old one was absorbed) without relying on specific HTML tags
    static private function detectAbsorbedTopic($client, $topic_id)
    {
        $html = self::extractLastPageHtml($client, $topic_id);

        if (empty($html)) return null;

        // Search for keywords "absorbed" (поглощ) or "merged" (объедин) - case-insensitive, UTF-8
        if (preg_match_all('/(поглощ|объедин)/iu', $html, $matches, PREG_OFFSET_CAPTURE)) {

            // Take the last occurrence of the keyword on the page
            $lastMatch = end($matches[0]);
            $keywordPos = $lastMatch[1];

            // Search for a link in a 3000-character radius BEFORE the keyword (links often appear before the word)
            $searchZoneBefore = substr($html, max(0, $keywordPos - 3000), 3000);

            // Look for the new topic ID (t=Digits) BEFORE the keyword
            if (preg_match_all('/viewtopic\.php\?t=(\d+)/i', $searchZoneBefore, $linkMatches)) {
                // Get the last link before the keyword (closest to it)
                $lastLinkId = end($linkMatches[1]);
                $newTopicId = intval($lastLinkId);
                if ($newTopicId && $newTopicId != $topic_id) {
                    return $newTopicId;
                }
            }

            // If not found before, search AFTER the keyword (2000-character radius)
            $searchZoneAfter = substr($html, $keywordPos, 2000);

            if (preg_match('/viewtopic\.php\?t=(\d+)/i', $searchZoneAfter, $linkMatch)) {
                $newTopicId = intval($linkMatch[1]);
                if ($newTopicId && $newTopicId != $topic_id) {
                    return $newTopicId;
                }
            }
        }

        return null;
    }

    static public function download_torrent($url, $hash, $old_torrent)
    {
        if (preg_match('`^https?://rutracker\.(org|cr|net|nl)/forum/viewtopic\.php\?t=(?P<id>\d+)$`', $url, $matches)) {
            $topic_id = $matches["id"];

            // --- STAGE 1: Check via API ---
            $req_url = "https://api.rutracker.cc/v1/get_tor_hash?by=topic_id&val=" . $topic_id;
            $client = ruTrackerChecker::makeClient($req_url);
            $remoteHash = null;

            if ($client->status == 200) {
                $ret = @json_decode($client->results, true);

                if (is_array($ret)) {
                     if (array_key_exists("result", $ret)) $ret = $ret["result"];

                     // IMPORTANT: We ignore error_code == 1 (Deleted) here,
                     // to allow the script to manually check for absorption/relocation on the site.

                     if (array_key_exists($topic_id, $ret)) {
                         $apiVal = $ret[$topic_id];
                         // The hash can be a string or an array ['hash' => '...']
                         if (is_array($apiVal) && isset($apiVal['hash'])) {
                             $remoteHash = $apiVal['hash'];
                         } elseif (is_string($apiVal) && ($apiVal !== '')) {
                             $remoteHash = $apiVal;
                         }

                         if (($remoteHash !== null) && !empty($hash) && strtoupper($remoteHash) == strtoupper($hash)) {
                             return ruTrackerChecker::STE_UPTODATE;
                         }
                     }
                }
            }

            // --- STAGE 2: Attempt direct download ---
            $client->setcookies();
            $client->fetchComplex("https://rutracker.org/forum/dl.php?t=" . $topic_id);

            // Protection against "Soft 404": server returned 200 OK, but the content is an HTML error.
            $is_html_garbage = self::looksLikeHtmlError($client->results);

            if ($client->status == 200 && !$is_html_garbage) {
                return ruTrackerChecker::createTorrent($client->results, $hash);
            }

            // --- STAGE 3: If download failed, check for relocation (absorption) ---

            // We enter here if status != 200 OR if HTML garbage was returned
            $absorbedTopicId = self::detectAbsorbedTopic($client, $topic_id);

            if (!is_null($absorbedTopicId)) {
                $client->setcookies();
                // Download torrent for the NEW topic
                $client->fetchComplex("https://rutracker.org/forum/dl.php?t=" . $absorbedTopicId);

                $is_new_html_garbage = self::looksLikeHtmlError($client->results);

                if ($client->status == 200 && !$is_new_html_garbage) {
                    return ruTrackerChecker::createTorrent($client->results, $hash);
                }
            }

            if ($client->status < 0) {
                return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            }

            // If the API reports a concrete remote hash, the topic is not deleted.
            // A failed dl.php response here is an access/auth/download problem, not deletion.
            if (($remoteHash !== null) && (strtoupper($remoteHash) != strtoupper($hash))) {
                ruTrackerChecker::logDebug(
                    "RuTracker topic {$topic_id}: API reports hash {$remoteHash}, but dl.php did not return a torrent"
                    . " (status={$client->status}, length=" . strlen($client->results) . ")"
                );
                return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            }

            return ruTrackerChecker::STE_DELETED;
        }
        return ruTrackerChecker::STE_NOT_NEED;
    }
}

ruTrackerChecker::registerTracker("/rutracker\./", "/rutracker\.|t-ru\.org/", "RuTrackerCheckImpl::download_torrent");
