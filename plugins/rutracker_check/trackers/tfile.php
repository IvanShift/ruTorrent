<?php

class TfileCheckImpl
{
    // Said once per process rather than once per torrent: a cycle holding
    // many tfile topics would otherwise repeat it for each of them.
    static private $unconfiguredLogged = false;

    // The forum this handler reads, as the Tfile loginmgr account names it,
    // or '' while that account names no site. The domain written here before
    // is not one this project can vouch for -- its registration changed
    // hands, which is why the account stopped carrying it and took
    // $tfileOrigin instead -- and this path does more than read: what comes
    // back from download.php is handed to createTorrent() and replaces the
    // torrent rtorrent is running.
    static private function origin()
    {
        $origin = ruTrackerChecker::accountOrigin('Tfile');
        if ($origin !== '') {
            return $origin;
        }
        if (!self::$unconfiguredLogged) {
            self::$unconfiguredLogged = true;
            ruTrackerChecker::logDebug('tfile topics are not checked while $tfileOrigin names no site;'
                . ' set it to the forum\'s https origin in the loginmgr plugin configuration');
        }
        return '';
    }

    static public function download_torrent($url, $hash, $old_torrent)
    {
        // The comment URL is what the torrent itself carries, so it is
        // matched as it was written, http included. Where the forum is read
        // is a separate question, and the answer is the configured origin.
        if (preg_match('`^https?://tfile\.me/forum/viewtopic\.php\?p=(?P<id>\d+)$`', $url, $matches)) {
            $origin = self::origin();
            if ($origin === '') return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            $client = ruTrackerChecker::makeClient($origin . "/forum/viewtopic.php?p=".$matches["id"]);
            if ($client->status != 200) return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            if (preg_match('`Info hash:</td><td><strong>(?P<hash>[0-9A-Fa-f]{40})</strong></td>`', $client->results, $matches)) {
                // Strict comparison, as the Kinozal handler's hash check documents: a
                // loose == reads a hex hash shaped like scientific notation
                // as a number ('1E' + 38 zeros == '00...01'), so two
                // different 40-char hashes could pass as equal.
                if (strtoupper($matches["hash"])===$hash) {
                    return  ruTrackerChecker::STE_UPTODATE;
                }
                if (preg_match('`\"download.php\?id=(?P<id>\d+)`', $client->results, $matches)) {
                    $client->setcookies();
                    if (!$client->fetchComplex($origin . "/forum/download.php?id=".$matches["id"])) {
                        $reason = RuTrackerFetchError::classify($client->error);
                        ruTrackerChecker::logDebug('[Tfile] download fetch returned false: error='
                            . ($reason === '' ? 'none' : $reason));
                        return ruTrackerChecker::STE_CANT_REACH_TRACKER;
                    }
                    return ruTrackerChecker::createTorrentFromDownload($client, $hash, $old_torrent);
                }
            }
            // The topic URL is ours and the host answered 200, but nothing in
            // the page could be read: no Info hash, or a changed one with no
            // download link. STE_NOT_NEED used to be the answer, and
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
