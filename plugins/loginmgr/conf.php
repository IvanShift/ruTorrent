<?php

// Values set in conf/config.php or per-user config.php survive plugin defaults.
// YggTorrent: the origin the account logs in at and downloads from, as
// "https://host" with an optional port and nothing else -- the address the
// tracker is opened at in a browser, e.g. 'https://www3.yggtorrent.example'.
// The tracker moves between domains, so none is trusted by default: until
// this is set, the account claims no URL, posts no credentials, and says so
// once in the log. Set it in plugins/loginmgr/conf.local.php or in
// conf/users/<user>/plugins/loginmgr/conf.php, which are read after this file.
// $yggTorrentOrigin = 'https://tracker.example';

// ABTorrents, RevolutionTT, Tfile and Redacted: the origin each account logs
// in at, in the same form -- "https://host" with an optional port and nothing
// else, the address the tracker is opened at in a browser. A tracker's domain
// is not fixed and the one it answered at when its account was written is not
// one this project can vouch for, so none is trusted by default: until the
// setting below is filled in, that account claims no URL, posts no
// credentials, and says so once in the log.
if(!isset($abTorrentsOrigin)) $abTorrentsOrigin = '';
if(!isset($revolutionTTOrigin)) $revolutionTTOrigin = '';
if(!isset($tfileOrigin)) $tfileOrigin = '';
if(!isset($redactedOrigin)) $redactedOrigin = '';

// KinozalTV: $kinozalHosts in conf/config.php orders the Kinozal hosts. Its
// first entry is where the automatic refresh logs in, and only the mirrors it
// lists receive the account session; see plugins/rutracker_check/conf.php.
