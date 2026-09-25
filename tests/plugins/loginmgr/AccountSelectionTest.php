<?php

require_once(__DIR__ . '/../../php/TestCase.php');

/**
 * accountManager picks an account for a URL by asking each enabled account
 * test($url). Getting that wrong is not a cosmetic bug: the account it picks
 * is the one whose cookies Snoopy then sends to whatever host the URL really
 * names, and the URL does not have to come from the user -- plugins/rss
 * follows links out of a feed, and plugins/extsearch out of a search result.
 */

require_once(__DIR__ . '/../../../plugins/loginmgr/accounts.php');
$productionAccountClasses = array();
foreach (glob(__DIR__ . '/../../../plugins/loginmgr/accounts/*.php') as $accountFile) {
    require_once($accountFile);
    $productionAccountClasses[] = basename($accountFile, ".php") . "Account";
}

$yggTorrentOrigin = 'https://www.ygg.re';

// A site still configured http, to pin the direction the rule allows.
class ProbeHttpSiteAccount extends commonAccount
{
    public $url = 'http://http-only.example';
    protected function isOK($client) { return true; }
    protected function login($c, $l, $p, &$u, &$m, &$ct, &$b, &$f) { return false; }
}

// Selection and download preparation read the host the same way. The host
// test folds the root dot, so it must not leave getDownloadId() blind, or
// the account is chosen and the download goes out as a plain GET without
// bb_dl -- measured 2026-09-14 before the id was read out of the parsed
// URL instead of a regex over the string.
class ProbeRuTrackerDownload extends ruTrackerAccount
{
    public $cached = true;
    protected function loadData($client = null)
    {
        $client->cookies = array('probe-session' => 'stored');
        return new ProbeStoredData($this->cached);
    }
}

class ProbeStoredData
{
    public $loaded;
    public function __construct($loaded) { $this->loaded = $loaded; }
    public function store($client) { return true; }
    public function remove() {}
}

// Records what would have gone on the wire; answers a page the account
// reads as logged in.
class ProbeRecordingTransport
{
    public $cookies = array();
    public $referer = '';
    public $status = 200;
    public $results = 'a readable page with no login form';
    public $requests = array();
    public function setcookies() {}
    public function fetch($url, $method = 'GET', $contentType = '', $body = '')
    {
        $this->requests[] = array('url' => $url, 'method' => $method,
            'contentType' => $contentType, 'body' => $body,
            'bb_dl' => isset($this->cookies['bb_dl']) ? $this->cookies['bb_dl'] : null);
        return true;
    }
}

$tests = array(
    'the first download after login is prepared with the complete POST contract' => function () {
        $account = new ProbeRuTrackerDownload();
        $account->cached = false;
        $client = new ProbeRecordingTransport();
        $url = 'https://rutracker.cr/forum/dl.php?t=42';
        testAssertSame(true, $account->fetch($client, $url, 'fake-user', 'fake-pass', 'GET', '', ''), 'login and download');
        testAssertSame(2, count($client->requests), 'login then download');
        testAssertSame('https://rutracker.org/forum/login.php', $client->requests[0]['url'], 'password goes to the configured login origin');
        testAssertSame(array('url' => $url, 'method' => 'POST', 'contentType' => 'application/x-www-form-urlencoded', 'body' => '', 'bb_dl' => '42'), $client->requests[1], 'download after login');
    },
    'a rutracker download url in either spelling of the host is prepared as a download' => function () {
        foreach (array('https://rutracker.org/forum/dl.php?t=12345', 'https://rutracker.org./forum/dl.php?t=12345',
                       'https://RUTRACKER.ORG/forum/dl.php?t=12345') as $url) {
            $account = new ProbeRuTrackerDownload();
            $client = new ProbeRecordingTransport();
            testAssertSame(true, $account->test($url), $url . ' is claimed');
            testAssertSame(true, $account->fetch($client, $url, '', '', 'GET', '', ''), $url . ' is fetched');
            testAssertSame(1, count($client->requests), 'one request went out for ' . $url);
            testAssertSame('POST', $client->requests[0]['method'], $url . ' goes out as the download POST');
            testAssertSame('application/x-www-form-urlencoded', $client->requests[0]['contentType'], 'download content type');
            testAssertSame('', $client->requests[0]['body'], 'empty download POST body');
            testAssertSame('12345', $client->requests[0]['bb_dl'], 'with the bb_dl cookie naming the topic');
        }
        // What the id is read from, and what it is not.
        $account = new ProbeRuTrackerDownload();
        foreach (array(
            'https://rutracker.org/forum/dl.php?t=12345&x=1' => true,      // t anywhere in the query
            'https://rutracker.org/forum/viewtopic.php?t=12345' => false,  // the topic page is not the download
            'https://rutracker.org/x/forum/dl.php?t=12345' => false,       // not the download path
            'https://evil.test/rutracker.org/forum/dl.php?t=12345' => false, // the name in the path
            'https://evil.test/forum/dl.php?t=12345' => false,
            'https://rutracker.org/forum/dl.php/?t=1' => false,
            'https://rutracker.org/forum/dl.php.bak?t=1' => false,
            'https://rutracker.org/forum/dl.php?t=abc' => false,           // not an id
            'https://rutracker.org/forum/dl.php?t[]=12345' => false,       // not a scalar
            'https://rutracker.org/forum/dl.php?t=12345%0A' => false,      // a line feed is not part of an id
        ) as $url => $isDownload) {
            $client = new ProbeRecordingTransport();
            $account->fetch($client, $url, '', '', 'GET', '', '');
            testAssertSame($isDownload ? 'POST' : 'GET', $client->requests[0]['method'], $url);
        }
    },
    // The query goes out untouched, so the id the cookie names has to be the
    // id the forum will read from that query. The forum is PHP: the last of a
    // repeated parameter wins and an encoded name is decoded before it is
    // matched. A reader that took the FIRST t=, or only a literal "t", named
    // topic 111 in bb_dl while the request asked for 222.
    'the bb_dl cookie names the topic the forum will read from the query' => function () {
        $account = new ProbeRuTrackerDownload();
        foreach (array(
            'https://rutracker.org/forum/dl.php?t=111&t=222'   => '222',
            'https://rutracker.org/forum/dl.php?t=111&%74=222' => '222',
        ) as $url => $id) {
            $client = new ProbeRecordingTransport();
            $account->fetch($client, $url, '', '', 'GET', '', '');
            testAssertSame($url, $client->requests[0]['url'], 'the query is sent as given');
            testAssertSame('POST', $client->requests[0]['method'], $url . ' is a download');
            testAssertSame($id, $client->requests[0]['bb_dl'], $url . ': bb_dl names the id the forum reads');
        }
    },
    'an nnmclub url in either spelling of the host reaches the account, and login.php never does' => function () {
        $account = new NNMClubAccount();
        foreach (array(
            'https://nnmclub.to/forum/viewtopic.php?t=1'  => true,
            'https://nnmclub.to/forum/' => true,
            'https://nnmclub.to/forum/x/..' => true,
            'https://nnmclub.to/forum' => false,
            'https://nnmclub.to./forum/viewtopic.php?t=1' => true,
            'https://nnmclub.to:8080/forum/dl.php?id=1' => true,
            'https://user@nnmclub.to/forum/dl.php?id=1' => true,
            'https://user:pass@nnmclub.to/forum/dl.php?id=1' => true,
            'https://nnmclub.to/forum/loginXphp' => true,
            'https://nnmclub.to/forum/login.php.bak' => false,
            'https://nnmclub.to/forum/login.php/extra' => false,
            'https://nnmclub.to/forum/login.phpx' => false,
            'https://nnmclub.to/forum/login.ph' => true,
            'https://nnmclub.to/x/nnmclub.to/forum/a' => false,
            'https://NNM-CLUB.ME/forum/dl.php?t=1'        => true,
            'https://nnmclub.to/forum/login.php'          => false,
            'https://nnmclub.to/forum//login.php'         => false,
            'https://nnmclub.to/forum/./login.php'        => false,
            'https://nnmclub.to/forum/x/../login.php'   => false,
            'https://nnmclub.to/forum/%2e%2e/forum/login.php' => false,
            'https://nnmclub.to/forum/../dl.php?id=1'   => false,
            'https://nnmclub.to/forum/login%2Ephp'        => false,
            'https://nnmclub.to./forum/login.php?x=1'     => false,
            'https://nnmclub.to/other/viewtopic.php?t=1'  => false,
            'https://evil.test/nnmclub.to/forum/dl.php'   => false,
        ) as $url => $expected) {
            testAssertSame($expected, (bool) $account->test($url), $url);
        }
    },
    // Whoever writes the URL picks the spelling of the host. Case is one
    // spelling DNS ignores; a trailing dot is the DNS root and names the same
    // host -- "abtorrents.me." is "abtorrents.me" -- and an anchored test that
    // forgot it once sent RuTracker's own announce row down the foreign path
    // in rutracker_check. The host test is shared with that plugin now
    // (php/urlhost.php), so the same folding holds here.
    'two spellings of the same host reach the same account' => function () {
        $account = new ABTorrentsAccount();
        testAssertSame(true, $account->test('https://ABTORRENTS.ME/x'), 'a host has no case');
        testAssertSame(true, $account->test('https://abtorrents.me./x'), 'the root dot names the same host');
        testAssertSame(false, $account->test('https://abtorrents.me.evil.test./x'),
            'and the root dot does not rescue a look-alike');
    },
    'an account is chosen by the url host, not by a substring of the url' => function () {
        // A prefix match accepts https://tracker.example@evil.test/ (the name
        // is userinfo) and https://tracker.example.evil.test/ (the name is one
        // label of a longer domain); matching the name anywhere accepts
        // https://evil.test/path/tracker.example/x. All three would have sent
        // the account's cookies to a host the attacker controls, and the url
        // need not come from the user -- plugins/rss follows feed links.
        $cases = array(
            array('ABTorrentsAccount', 'https://abtorrents.me/x', true),
            array('ABTorrentsAccount', 'https://abtorrents.me@evil.test/', false),
            array('ABTorrentsAccount', 'https://abtorrents.me.evil.test/', false),
            array('ABTorrentsAccount', 'https://evil.test/abtorrents.me/', false),
            array('KinozalTVAccount', 'https://kinozal.guru/details.php?id=1', true),
            array('KinozalTVAccount', 'https://dl.kinozal.guru/download.php?id=1', true),
            array('KinozalTVAccount', 'https://evil.test/path/kinozal.guru/feed', false),
            array('ruTrackerAccount', 'https://rutracker.org/forum/dl.php?t=1', true),
            array('ruTrackerAccount', 'https://rutracker.org/other/', false),
            array('ruTrackerAccount', 'https://evil.test/x/rutracker.org/forum/', false),
            array('TapochekNetAccount', 'https://tapochek.net/x', true),
            array('TapochekNetAccount', 'https://tapochek.net.evil.test/x', false),
            array('YggTorrentAccount', 'https://www.ygg.re/engine/download_torrent?id=1', true),
            array('YggTorrentAccount', 'https://evil.test/x/ygg.re/engine/download_torrent?id=1', false),
            array('LostFilmAccount', 'https://lostfilm.tv/download.php?id=7&', true),
            array('LostFilmAccount', 'https://lostfilm.tv.evil.test/download.php?id=7&', false),
        );
        foreach ($cases as $case) {
            list($class, $url, $expected) = $case;
            $account = new $class();
            testAssertSame($expected, (bool) $account->test($url), $class . ' vs ' . $url);
            if ($expected) {
                $host = parse_url($url, PHP_URL_HOST);
                foreach (array(strtoupper($host), $host . '.') as $spelling) {
                    testAssertSame(true, $account->test(str_replace($host, $spelling, $url)), $class . ' normalized host');
                }
            }
        }
    },

    'a url may not weaken the scheme its site is configured with' => function () {
        // Matched over http, an https site would have this account's cookies
        // put on the wire in clear by the very first request, before any
        // redirect could upgrade it -- and the url can arrive from a feed.
        foreach (array(
            array('LostFilmAccount', 'http://lostfilm.tv/download.php?id=7&'),
            array('TfileAccount', 'http://megatfile.cc/forum/index.php'),
            array('AniDUBAccount', 'http://tr.anidub.com/'),
            array('ABTorrentsAccount', 'http://abtorrents.me/x'),
        ) as $case) {
            list($class, $url) = $case;
            $account = new $class();
            testAssertSame(false, (bool) $account->test($url),
                $class . ' must not claim the http form of an https site');
        }
    },

    'every production HTTPS account rejects its HTTP download form' => function () {
        global $productionAccountClasses;
        $paths = array('RUTrackerAccount' => '/forum/dl.php?t=1',
            'NNMClubAccount' => '/forum/dl.php?id=1', 'TfileAccount' => '/forum/dl.php?id=1',
            'LostFilmAccount' => '/download.php?id=1', 'NovaFilmAccount' => '/download/1',
            'YggTorrentAccount' => '/engine/download_torrent?id=1');
        foreach ($productionAccountClasses as $class) {
            $account = new $class();
            testAssertSame('https', parse_url($account->url, PHP_URL_SCHEME), $class . ' has an HTTPS origin');
            $url = rtrim($account->url, '/') . (isset($paths[$class]) ? $paths[$class] : '/x');
            testAssertSame(true, $account->test($url), $class . ' positive control');
            testAssertSame(false, $account->test(preg_replace('/^https:/', 'http:', $url)), $class . ' refuses HTTP');
        }
    },

    'an HTTP link matching an HTTPS account logs one redacted migration hint' => function () {
        global $log_file;
        $previous = $log_file;
        $log_file = tempnam(sys_get_temp_dir(), 'http-account-');
        try {
            $manager = new accountManager();
            $manager->accounts = array('RUTracker' => array(
                'enabled' => 1,
                'path' => __DIR__ . '/../../../plugins/loginmgr/accounts/RUTracker.php',
                'object' => 'ruTrackerAccount',
            ));
            $url = 'http://rutracker.org/forum/dl.php?t=42&passkey=private';
            testAssertSame(false, $manager->getAccount($url), 'HTTP URL receives no loginmgr session');
            testAssertSame(false, $manager->getAccount($url), 'repeat HTTP URL still receives no session');
            $log = file_get_contents($log_file);
            testAssertSame(1, substr_count($log, 'loginmgr: http-url-not-authenticated: RUTracker rutracker.org'),
                'one host-only migration hint is logged');
            testAssertSame(false, strpos($log, 'passkey') !== false, 'URL query stays out of the log');
            testAssertSame('RUTracker', $manager->getAccount('https://rutracker.org/forum/dl.php?t=42'),
                'HTTPS URL still selects its account');
        } finally {
            unlink($log_file);
            $log_file = $previous;
        }
    },
    'unrelated HTTP URLs do not log a loginmgr migration hint' => function () {
        global $log_file;
        $previous = $log_file;
        $log_file = tempnam(sys_get_temp_dir(), 'http-unrelated-');
        try {
            $manager = new accountManager();
            $manager->accounts = array('RUTracker' => array(
                'enabled' => 1,
                'path' => __DIR__ . '/../../../plugins/loginmgr/accounts/RUTracker.php',
                'object' => 'ruTrackerAccount',
            ));
            foreach (array('http://unrelated.test/forum/dl.php?t=42',
                'http://rutracker.org/other/?t=42') as $url) {
                $httpsAccount = 'stale';
                testAssertSame(false, $manager->getAccount($url, $httpsAccount),
                    'unrelated HTTP URL has no selected account');
                testAssertSame(null, $httpsAccount,
                    'unrelated HTTP URL has no HTTPS candidate');
            }
            testAssertSame('', file_get_contents($log_file),
                'unrelated HTTP URLs do not produce a migration hint');
        } finally {
            unlink($log_file);
            $log_file = $previous;
        }
    },
    'an https url still reaches a site whose own scheme is http' => function () {
        // The other direction costs nothing to allow: an https url to a site
        // that really is http-only simply fails to connect.
        $account = new ProbeHttpSiteAccount();
        testAssertSame(true, (bool) $account->test('https://http-only.example/x'), 'https is accepted');
        testAssertSame(true, (bool) $account->test('http://http-only.example/x'), 'so is its own scheme');
    },

    'no account claims a url whose host it does not own' => function () {
        // A net rather than a proof: each account is asked about urls that
        // carry its own name but are served by someone else. It cannot know
        // every path a given tracker requires, so a rejection here may be for
        // the path rather than the host -- but an acceptance is always wrong,
        // and this is what catches the next test() written against the raw
        // url string instead of the parsed host.
        global $productionAccountClasses;
        foreach ($productionAccountClasses as $class) {
            $account = new $class();
            $host = parse_url((string) $account->url, PHP_URL_HOST);
            if (!$host) {
                continue;
            }
            $elsewhere = array(
                'https://evil.test/x/' . $host . '/forum/dl.php?t=1',
                'https://evil.test/x/' . $host . '/download.php?id=1',
                'https://evil.test/x/' . $host . '/engine/download_torrent?id=1',
                'https://evil.test/x/' . $host . '/',
                'https://' . $host . '@evil.test/forum/dl.php?t=1',
                'https://' . $host . '@evil.test/',
                'https://' . $host . '.evil.test/forum/dl.php?t=1',
            );
            foreach ($elsewhere as $url) {
                testAssertSame(false, (bool) $account->test($url),
                    $class . ' must not claim ' . var_export($url, true));
            }
        }
    },

    'a url with no host at all matches nothing' => function () {
        global $productionAccountClasses;
        foreach ($productionAccountClasses as $class) {
            $account = new $class();
            foreach (array('', 'not a url', '/relative/path', 'javascript:alert(1)') as $url) {
                testAssertSame(false, (bool) $account->test($url),
                    $class . ' must not claim ' . var_export($url, true));
            }
        }
    },
);

exit(testRunCases($tests));
