<?php

class TapochekNetCheckImpl
{
    // The tracker's own verdict for a topic it no longer serves: HTTP 200 with
    // this exact sentence, measured 2026-08-21 against the live site. It is the
    // only authoritative deletion signal this handler has, which is why nothing
    // else it fails to read may mean "deleted": a login wall, a ratio gate and
    // a protection page all arrive as HTTP 200 too.
    const MISSING_MARKER = 'Темы, которую вы запросили, не существует';

    // Topic pages are windows-1251 in production, while fixtures and future
    // deployments may already be UTF-8. Structural policy stays in this
    // handler; this helper only gives it one encoding to inspect.
    static private function utf8Body($body)
    {
        if (!is_string($body) || $body === '') return '';
        if (strpos($body, self::MISSING_MARKER) !== false
            || strpos($body, 'Информация') !== false) return $body;
        if (!function_exists('iconv')) return $body;
        $converted = @iconv('CP1251', 'UTF-8//IGNORE', $body);
        return is_string($converted) ? $converted : $body;
    }

    static private function plainText($html)
    {
        $plain = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = str_replace("\xC2\xA0", ' ', $plain);
        return trim(preg_replace('/[ \t\r\n\f\v]+/', ' ', $plain));
    }

    static private function hasLivePageSignal($body)
    {
        return preg_match('`btih\s*:`i', (string) $body)
            || preg_match('`href\s*=\s*(["\'])[^"\']*download\.php\?id=\d+[^"\']*\1`i', (string) $body);
    }

    // The measured deletion answer is not a phrase anywhere on the page. It
    // is an Information system message whose td contains only that sentence.
    static private function isMissingAnswer($body)
    {
        if (!is_string($body) || $body === '' || self::hasLivePageSignal($body)) return false;
        $body = self::utf8Body($body);
        if (!preg_match_all('`<table\b(?P<attrs>[^>]*)>(?P<body>.*?)</table>`is',
            $body, $tables, PREG_SET_ORDER)) return false;

        foreach ($tables as $table) {
            if (!preg_match('`\bclass\s*=\s*(["\'])(?P<class>.*?)\1`is', $table['attrs'], $classMatch))
                continue;
            $classes = preg_split('/\s+/', strtolower(trim($classMatch['class'])));
            if (!in_array('forumline', $classes, true) || !in_array('message', $classes, true)) continue;
            if (!preg_match_all('`<th\b[^>]*>(.*?)</th>`is', $table['body'], $heads)) continue;
            $information = false;
            foreach ($heads[1] as $head) {
                if (self::plainText($head) === 'Информация') {
                    $information = true;
                    break;
                }
            }
            if (!$information || !preg_match_all('`<td\b[^>]*>(.*?)</td>`is', $table['body'], $cells)) continue;
            foreach ($cells[1] as $cell) {
                $text = self::plainText($cell);
                if ($text === self::MISSING_MARKER || $text === self::MISSING_MARKER . '.') return true;
            }
        }
        return false;
    }

    static public function download_torrent($url, $hash, $old_torrent)
    {
        if (preg_match('`^https?://tapochek\.net/viewtopic\.php\?p=(?P<id>\d+)$`', $url, $matches)) {
            $client = ruTrackerChecker::makeClient("https://tapochek.net/viewtopic.php?p=".$matches["id"]);
            if ($client->status != 200) return ruTrackerChecker::STE_CANT_REACH_TRACKER;

            if (preg_match('`btih:(?P<hash>[0-9A-Fa-f]{40})&dn`', $client->results, $matches)) {
                // Strict comparison, as kinozal.php:120-125 documents: a
                // loose == reads a hex hash shaped like scientific notation
                // as a number ('1E' + 38 zeros == '00...01'), so two
                // different 40-char hashes could pass as equal.
                if (strtoupper($matches["hash"])===$hash) {
                    return  ruTrackerChecker::STE_UPTODATE;
                }
                if (preg_match('`\"download.php\?id=(?P<id>\d+)\"`', $client->results, $matches)) {
                    $client->setcookies();
                    $client->fetchComplex("https://tapochek.net/download.php?id=".$matches["id"]);
                    return ruTrackerChecker::createTorrentFromDownload($client, $hash, $old_torrent);
                }
            }

            // Consult the deletion marker only when no valid live evidence (btih) is present
            if (self::isMissingAnswer($client->results))
                return ruTrackerChecker::STE_DELETED;

            // The topic URL is ours and the tracker answered 200, but nothing
            // in the page could be read: no removal marker, no info hash, or a
            // changed hash with no download link. STE_NOT_NEED used to be the
            // answer, and it states something false and sticky -- "this handler
            // has no business with this torrent" -- for what is really "ask
            // again later".
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }
        // Reached only for a URL this handler does not own, which is the one
        // thing STE_DECLINED means.
        return ruTrackerChecker::STE_DECLINED;
    }
}

ruTrackerChecker::registerTracker("/tapochek\.net/", "/tapochek\.net/", "TapochekNetCheckImpl::download_torrent");
