<?php


class AniDUBCheckImpl{
    static public function download_torrent($url, $hash, $old_torrent){
        if( preg_match( '`^http://tr\.anidub\.com/\?newsid=(?P<id>\d+)$`',$url, $matches ) ) {
            $topic_id = $matches["id"];
            $torrent_name = $old_torrent->name();
            $torrent_quality = null;
            $torrent_quality_type = "";

            # checking torrent quality from torrent file name
            //
            // The alternation is GROUPED. Without the group, '\_\[' bound only
            // to the first branch and '\]\_' only to the last, so '_[bdrip1080p]_'
            // matched nothing at all and the release was never checked. And
            // the branches are exclusive, tested on participation rather than
            // on key presence: PHP fills every group PRECEDING the one that
            // matched with '', so array_key_exists() was true for 'vq' on a
            // PSP match and the unconditional assignments ran in order --
            // '_[PSP]_' came out as quality 'psp' with type 'tv', looking for
            // a div id of 'tvpsp' that no AniDUB page has.
            //
            // Four digits, not three: '\d{1,3}' could never match '1080p',
            // which is the commonest tag AniDUB publishes, so those releases
            // fell out at the "no quality tag" exit below and were never
            // checked at all.
            if (preg_match('/\_\[(?:(?<vq>\d{1,4})p|(?<vq1>PSP)|(?<vq2>HWP)|bdrip(?<vq3>\d{1,4})p)\]\_/i',
                $torrent_name, $qmatches)){

                if (($qmatches["vq3"] ?? '') !== '') {
                    $torrent_quality = strtolower($qmatches["vq3"]); # bd quality
                    $torrent_quality_type = "bd";
                } elseif (($qmatches["vq1"] ?? '') !== '') {
                    $torrent_quality = strtolower($qmatches["vq1"]);
                } elseif (($qmatches["vq2"] ?? '') !== '') {
                    $torrent_quality = strtolower($qmatches["vq2"]);
                } elseif (($qmatches["vq"] ?? '') !== '') {
                    $torrent_quality = $qmatches["vq"];  # tv quality
                    $torrent_quality_type = "tv";
                }
            }

            // The handler owns this URL, but cannot select a download block
            // without a supported tag. Do not guess and do not dismiss the
            // torrent permanently: a future cycle or parser update may be able
            // to check it.
            if ($torrent_quality === null) {
                ruTrackerChecker::logDebug('[AniDUB] unsupported or missing quality tag in torrent name: '
                    . (string) $torrent_name);
                return ruTrackerChecker::STE_CANT_REACH_TRACKER;
            }

            $client = ruTrackerChecker::makeClient($url);
            if($client->status!=200) return ruTrackerChecker::STE_CANT_REACH_TRACKER;

            $resp = preg_replace( "/\r|\n/", "", $client->results);
            $q =$torrent_quality_type.$torrent_quality;  // e.g. tv.720
            $pattern = sprintf('/\<div\sid\=\"%s\"\>\<div\sid=\'[\d\w\_]+\'\>\s+\<div\sclass=\"torrent\_h\"\>\s+\<a\shref=\"(?P<url>[\w\d\_\/\.\=\?]+)\"\s/i', $q);
            // These two guards computed a verdict and threw it away -- no
            // return -- so a page that matched neither pattern fell through to
            // $url_matches["url"] on an array that has no such key, and the
            // fetch below went out against the bare host.
            if (!preg_match($pattern, $resp, $url_matches)) return ruTrackerChecker::STE_CANT_REACH_TRACKER;

            if (!preg_match('/\/engine\/download\.php\?id=\d{1,10}/i', $url_matches["url"], $m)) return ruTrackerChecker::STE_CANT_REACH_TRACKER;

            $client->setcookies();
            $client->fetchComplex("http://tr.anidub.com" . $url_matches["url"]);

            return ruTrackerChecker::createTorrentFromDownload($client, $hash, $old_torrent);
        }

        return ruTrackerChecker::STE_DECLINED;
    }
}

ruTrackerChecker::registerTracker("/tr\.anidub\.com/", "/tr\.anidub\.com/", "AniDUBCheckImpl::download_torrent");
