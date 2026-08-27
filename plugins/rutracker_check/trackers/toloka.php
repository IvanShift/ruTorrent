<?php

// Toloka.to support by ReMMeR github@r3mm3r.net

class tolokaCheckImpl
{
    static public function download_torrent($url, $hash, $old_torrent)
    {
        // Escaped dots. Unescaped, '.' matches any character, so this claimed
        // 'tolokaXto' and every other look-alike -- and the comment that
        // carries the URL comes out of the torrent, which anyone can write.
        // Every sibling handler escapes; this one did not.
        if (preg_match('`^https?://toloka\.to/p(?P<id>\d+)$`', $url, $matches)) {
            $topic_id = $matches["id"];
	    $req_url = "https://toloka.to/p".$topic_id;
	    sleep(5); // Do not want to be banned by cloudflare
            $client = ruTrackerChecker::makeClient($req_url);
            if ($client->status != 200) return ruTrackerChecker::STE_CANT_REACH_TRACKER;

	    $hash_now='';
	    if (preg_match('`href=\"magnet:[^:]+:[^:]+:(?P<hash>[0-9A-Fa-f]{40})`', $client->results, $matches)) {
		$hash_now = $matches["hash"];
	    }

	    $dow_id=0;
	    if (preg_match('`href=\"download.php\?id=(?P<id>\d+)`', $client->results, $matches)) {
		$dow_id = intval($matches["id"]);
	    }

            // Strict comparison, as kinozal.php:120-125 documents: a
            // loose == reads a hex hash shaped like scientific notation
            // as a number ('1E' + 38 zeros == '00...01'), so two
            // different 40-char hashes could pass as equal.
            //
            // The two questions are separate, and used to be ANDed together.
            // Whether the page's hash matches is answered by the page's hash
            // alone; $dow_id answers a different question -- can a replacement
            // be downloaded. With them joined, a page that proved the torrent
            // current but carried no download link (a guest view of a
            // login-gated tracker, an interstitial, any markup change) fell
            // through to fetching download.php?id=0 and spending a check on an
            // answer that could never parse. Toloka has no deletion signal of
            // its own, so an unparseable answer is a retryable error here and
            // the question is simply not asked.
            if ($hash_now !== '' && strtoupper($hash_now) === $hash) {
                return ruTrackerChecker::STE_UPTODATE;
            }
            // Nothing to download: that is "could not fetch", exactly as the
            // three sibling handlers answer it.
            if (!$dow_id) return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            $client->setcookies();

	    sleep(5); // Do not want to be banned by cloudflare
            $client->fetchComplex("https://toloka.to/download.php?id=" . $dow_id);
            return ruTrackerChecker::createTorrentFromDownload($client, $hash, $old_torrent);
        }
        return ruTrackerChecker::STE_DECLINED;
    }
}

ruTrackerChecker::registerTracker("/toloka\./", "/toloka\\.to/", "tolokaCheckImpl::download_torrent");
