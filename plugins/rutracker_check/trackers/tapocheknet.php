<?php

class TapochekNetCheckImpl
{
    // The tracker's own verdict for a topic it no longer serves: HTTP 200 with
    // this exact sentence, measured 2026-08-21 against the live site. It is the
    // only authoritative deletion signal this handler has, which is why nothing
    // else it fails to read may mean "deleted": a login wall, a ratio gate and
    // a protection page all arrive as HTTP 200 too.
    const MISSING_MARKER = 'Темы, которую вы запросили, не существует';

    // The measured removal page is windows-1251. Literal needles keep this
    // verdict available in production PHP, which has no iconv extension.
    const MISSING_MARKER_CP1251 = "\xD2\xE5\xEC\xFB\x2C\x20\xEA\xEE\xF2\xEE\xF0\xF3\xFE\x20\xE2\xFB\x20\xE7\xE0\xEF\xF0\xEE\xF1\xE8\xEB\xE8\x2C\x20\xED\xE5\x20\xF1\xF3\xF9\xE5\xF1\xF2\xE2\xF3\xE5\xF2";
    const INFORMATION_CP1251 = "\xC8\xED\xF4\xEE\xF0\xEC\xE0\xF6\xE8\xFF";

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
        // Only rendered markup may speak for the tracker. A table in a
        // template comment or script literal is not an Information answer.
        $body = preg_replace('`<!--.*?-->|<script\b[^>]*>.*?</script\s*>|<style\b[^>]*>.*?</style\s*>`is',
            '', $body);
        // Find each table's own close tag. A lazy whole-table regex consumes
        // a nested system message as part of its outer layout table.
        if (!preg_match_all('`</?table\b[^>]*>`is', $body, $tags, PREG_OFFSET_CAPTURE)) return false;
        $stack = array();
        foreach ($tags[0] as $tag) {
            if (strpos($tag[0], '</') !== 0) {
                $stack[] = array('attrs' => substr($tag[0], 6, -1),
                    'start' => $tag[1] + strlen($tag[0]));
                continue;
            }
            if (!$stack) continue;
            $table = array_pop($stack);
            $table['body'] = substr($body, $table['start'], $tag[1] - $table['start']);
            if (!preg_match('`\bclass\s*=\s*(["\'])(?P<class>.*?)\1`is', $table['attrs'], $classMatch))
                continue;
            $classes = preg_split('/\s+/', strtolower(trim($classMatch['class'])));
            if (!in_array('forumline', $classes, true) || !in_array('message', $classes, true)) continue;
            if (!preg_match_all('`<th\b[^>]*>(.*?)</th>`is', $table['body'], $heads)) continue;
            $information = false;
            foreach ($heads[1] as $head) {
                if (in_array(self::plainText($head), array('Информация', self::INFORMATION_CP1251), true)) {
                    $information = true;
                    break;
                }
            }
            if (!$information || !preg_match_all('`<td\b[^>]*>(.*?)</td>`is', $table['body'], $cells)) continue;
            foreach ($cells[1] as $cell) {
                $text = self::plainText($cell);
                if (in_array($text, array(self::MISSING_MARKER, self::MISSING_MARKER . '.',
                    self::MISSING_MARKER_CP1251, self::MISSING_MARKER_CP1251 . '.'), true)) return true;
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
                // Strict comparison, as the Kinozal handler's hash check documents: a
                // loose == reads a hex hash shaped like scientific notation
                // as a number ('1E' + 38 zeros == '00...01'), so two
                // different 40-char hashes could pass as equal.
                if (strtoupper($matches["hash"])===$hash) {
                    return  ruTrackerChecker::STE_UPTODATE;
                }
                if (preg_match('`\"download.php\?id=(?P<id>\d+)\"`', $client->results, $matches)) {
                    $client->setcookies();
                    if (!$client->fetchComplex("https://tapochek.net/download.php?id=".$matches["id"]))
                        return ruTrackerChecker::STE_CANT_REACH_TRACKER;
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
