<?php

class TfileCheckImpl
{
    static public function download_torrent($url, $hash, $old_torrent)
    {
        if (preg_match('`^https?://tfile\.me/forum/viewtopic\.php\?p=(?P<id>\d+)$`', $url, $matches)) {
            $client = ruTrackerChecker::makeClient("http://megatfile.cc/forum/viewtopic.php?p=".$matches["id"]);
            if ($client->status != 200) return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            if (preg_match('`Info hash:</td><td><strong>(?P<hash>[0-9A-Fa-f]{40})</strong></td>`', $client->results, $matches)) {
                // Strict comparison, as kinozal.php:120-125 documents: a
                // loose == reads a hex hash shaped like scientific notation
                // as a number ('1E' + 38 zeros == '00...01'), so two
                // different 40-char hashes could pass as equal.
                if (strtoupper($matches["hash"])===$hash) {
                    return  ruTrackerChecker::STE_UPTODATE;
                }
                if (preg_match('`\"download.php\?id=(?P<id>\d+)`', $client->results, $matches)) {
                    $client->setcookies();
                    $client->fetchComplex("http://megatfile.cc/forum/download.php?id=".$matches["id"]);
                    return ruTrackerChecker::createTorrentFromDownload($client, $hash, $old_torrent);
                }
            }
            // The topic URL is ours and the host answered 200, but nothing in
            // the page could be read: no Info hash, or a changed one with no
            // download link. megatfile.cc -- where this handler routes every
            // tfile.me topic -- served a parked-domain page when the review
            // probed it on 2026-08-21, so this is the branch a Tfile torrent
            // actually lands in today. STE_NOT_NEED used to be the answer, and
            // it says the handler has no business with the torrent, which stops
            // the plugin looking; the truth is "ask again later". This tracker
            // has no confirmed HTTP-200 removal marker, so nothing here may
            // conclude a deletion either.
            return ruTrackerChecker::STE_CANT_REACH_TRACKER;
        }
        // Reached only for a URL this handler does not own.
        return ruTrackerChecker::STE_DECLINED;
    }
}

ruTrackerChecker::registerTracker("/tfile\.me/", "/tfile\.|peersteers\.org/", "TfileCheckImpl::download_torrent");
