<?php

// Keep this suite's diagnostics out of the shared application log.
$_ENV['RU_LOG_FILE'] = sys_get_temp_dir() . '/loginmgr-boundary-' . getmypid() . '.log';

require_once(__DIR__ . '/../../../plugins/loginmgr/accounts.php');
require_once(__DIR__ . '/../../../plugins/loginmgr/accounts/YggTorrent.php');
require_once(__DIR__ . '/../../../plugins/loginmgr/accounts/LostFilm.php');
require_once(__DIR__ . '/../../../plugins/loginmgr/accounts/KinozalTV.php');

function boundarySame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

// The transport double also mirrors cookie import and header parsing; core
// transport behavior is covered separately by SnoopyTest with a fake curl.
class BoundaryTransport extends Snoopy
{
    public $requests = array();
    public $redirect = false;
    public $replyCookies = false;
    public $guest = false;
    public $queue = array();
    public function connect() { return fopen('php://memory', 'r+'); }
    public function _httprequest($url, $fp, $URI, $method, $content_type = '', $body = '')
    {
        return $this->_httpsrequest($URI, $content_type, $body, $method);
    }
    public function _httpsrequest($url, $content_type = '', $body = '', $method = 'GET')
    {
        if ($this->passcookies && $this->_redirectaddr) {
            $this->setcookies();
        }
        $this->requests[] = array($url, $method, $body, $this->cookies);
        if ($this->queue) {
            $reply = array_shift($this->queue);
            $this->_redirectaddr = $reply[1];
            $this->headers = $reply[2];
            $this->status = $reply[0];
            $this->results = $reply[3];
            return true;
        }
        $this->_redirectaddr = count($this->requests) === 1 ? $this->redirect : false;
        $this->headers = $this->replyCookies ? array("Set-Cookie: fresh=secret; Path=/\r\n") : array();
        $this->status = $this->_redirectaddr ? 302 : 200;
        $this->results = $this->guest ? "S'identifier</a>" : 'authenticated response';
        return true;
    }
}
class BoundaryData
{
    public $loaded;
    public $stored = 0;
    public $removed = 0;
    public function __construct($loaded) { $this->loaded = $loaded; }
    public function store($client) { $this->stored++; return true; }
    public function remove() { $this->removed++; }
}
class BoundaryYgg extends YggTorrentAccount
{
    public $cached = false;
    protected function loadData($client = null)
    {
        if ($client && $this->cached) { $client->cookies = array('ygg_' => 'fake-session'); }
        return new BoundaryData($this->cached);
    }
    public function directLogin($client, $url)
    {
        $method = 'GET'; $contentType = ''; $body = ''; $fetched = false;
        return $this->login($client, 'fake-user', 'fake-password', $url, $method, $contentType, $body, $fetched);
    }
}
class BoundaryUnconfigured extends commonAccount
{
    public $logins = 0;
    protected function isOK($client) { return true; }
    protected function loadData($client = null) { return new BoundaryData(false); }
    protected function login($c, $l, $p, &$u, &$m, &$ct, &$b, &$f) { $this->logins++; return false; }
}
class BoundaryUnconfiguredLogged extends BoundaryUnconfigured {}
class BoundaryLostFilm extends LostFilmAccount
{
    public function recover($client, $url) { return $this->isOKPostFetch($client, $url, 'GET', '', ''); }
}

class BoundaryKinozal extends KinozalTVAccount
{
    public $data;
    public function __construct() { $this->data = new BoundaryData(true); }
    protected function loadData($client = null)
    {
        if ($client) { $client->cookies = array('sid' => 'expired'); }
        return $this->data;
    }
}

$tests = array(
    'authenticated redirects never send cookies or credentials across origins' => function () {
        foreach (array('https://evil.test/x', 'http://tracker.example/x', 'https://tracker.example:8443/x',
                       'https://tracker.example@evil.test/x', 'https://tracker.example.evil.test/x') as $target) {
            foreach (array('jar', 'basic', 'raw-cookie', 'raw-auth', 'raw-proxy-auth') as $source) {
                $client = new BoundaryTransport();
                $client->redirect = $target;
                if ($source === 'jar') { $client->cookies = array('session' => 'fake-secret'); }
                if ($source === 'basic') { $client->user = 'fake-user'; $client->pass = 'fake-pass'; }
                if ($source === 'raw-cookie') { $client->rawheaders['cOoKiE'] = 'session=fake-secret'; }
                if ($source === 'raw-auth') { $client->rawheaders['aUtHoRiZaTiOn'] = 'Bearer fake-secret'; }
                if ($source === 'raw-proxy-auth') { $client->rawheaders['PrOxY-AuThOrIzAtIoN'] = 'Basic fake-secret'; }
                boundarySame(true, $client->fetch('https://tracker.example/start'), $source . ' redirect to ' . $target);
                boundarySame(1, count($client->requests), 'no second request leaves the client');
                boundarySame(302, $client->status, 'the received HTTP response remains available');
                boundarySame($target, $client->lastredirectaddr, 'the refused redirect remains observable to tracker classifiers');
                boundarySame('credential-redirect-refused', $client->error, 'classified refusal');
            }
        }
    },
    'a same host default port HTTPS upgrade keeps existing credentials' => function () {
        foreach (array('https://tracker.example/next', 'https://TRACKER.EXAMPLE.:443/next') as $target) {
            $client = new BoundaryTransport();
            $client->cookies = array('sid' => 'existing');
            $client->redirect = $target;
            boundarySame(true, $client->fetch('http://tracker.example:80/start'), 'upgrade response');
            boundarySame(2, count($client->requests), 'upgrade follows');
            boundarySame(array('sid' => 'existing'), $client->requests[1][3], 'upgrade retains session');
        }
        foreach (array(array('http://tracker.example:8080/start', 'https://tracker.example/next'),
                       array('http://tracker.example/start', 'https://tracker.example:8443/next')) as $pair) {
            $client = new BoundaryTransport(); $client->cookies = array('sid' => 'existing');
            $client->redirect = $pair[1];
            $client->fetch($pair[0]);
            boundarySame(1, count($client->requests), 'nondefault port is a separate trust decision');
        }
    },
    'anonymous redirects without source cookies keep cookies from a later same origin hop' => function () {
        $client = new BoundaryTransport();
        $client->queue = array(
            array(302, 'https://cdn.example/first', array(), ''),
            array(302, 'https://cdn.example/last', array('Set-Cookie: destination=own; Path=/'), ''),
            array(200, false, array(), 'torrent'),
        );
        boundarySame(true, $client->fetch('https://tracker.example/start'), 'anonymous chain succeeds');
        boundarySame(3, count($client->requests), 'all three replies are consumed');
        boundarySame(array('destination' => 'own'), $client->requests[2][3], 'CDN can retain its own cookie');
    },
    'a refused redirect leaves response cookies explicit and never imports them on reuse' => function () {
        global $log_file;
        $previous = $log_file; $log_file = tempnam(sys_get_temp_dir(), 'redirect-log-');
        try {
            $client = new BoundaryTransport();
            $client->rawheaders['Authorization'] = 'Bearer source-token';
            // This origin pair is unique in the process-wide redirect log latch.
            $client->queue = array(
                array(302, 'https://evil-refusal.test/secret?token=private', array('Set-Cookie: fresh=private; Path=/'), ''),
                array(200, false, array(), 'next response'),
            );
            boundarySame(true, $client->fetch('https://tracker.example/login', 'POST', '', 'password=private'), 'POST response survives refusal');
            boundarySame(302, $client->status, 'login sees the actual response status');
            boundarySame('fresh=private; Path=/', $client->get_header('Set-Cookie'), 'trusted login response cookies remain readable');
            boundarySame(false, $client->_redirectaddr, 'no pending cookie import remains');
            $log = file_get_contents($log_file);
            boundarySame(true, strpos($log, 'credential-redirect-refused') !== false, 'application log classifies refusal');
            boundarySame(true, strpos($log, 'https://tracker.example:443 -> https://evil-refusal.test:443') !== false, 'log names source and target origins');
            boundarySame(false, strpos($log, 'private') !== false, 'application log contains no URL or cookie secrets');
            boundarySame(true, $client->fetch('https://other.test/next'), 'independent request remains usable');
            boundarySame(array(), $client->requests[1][3], 'previous response cookie was not imported');
            boundarySame('', $client->error, 'independent success clears the previous refusal');
            $client->queue = array(array(302, 'https://evil-refusal.test/again', array(), ''));
            boundarySame(true, $client->fetch('https://tracker.example/login', 'POST', '', 'password=private'), 'repeat refusal');
            boundarySame($log, file_get_contents($log_file), 'same origin pair is logged once per PHP request');
        } finally { unlink($log_file); $log_file = $previous; }
    },
    'an expired Kinozal session follows its trusted login redirect and downloads after relogin' => function () {
        $account = new BoundaryKinozal();
        $client = new BoundaryTransport();
        $url = 'https://dl.kinozal.guru/download.php?id=7';
        $client->queue = array(
            array(302, 'https://kinozal.guru/login.php', array(), ''),
            array(200, false, array(), '<input name="password">'),
            array(200, false, array(), '<input name="password">'),
            array(302, 'http://kinozal.guru/', array('Set-Cookie: sid=renewed; Path=/'), ''),
            array(200, false, array(), 'd4:infodee'),
        );
        boundarySame(true, $account->fetch($client, $url, 'user', 'pass', 'GET', '', ''), 'cached guest session is repaired');
        boundarySame(array($url, 'https://kinozal.guru/login.php', 'https://kinozal.guru',
            'https://kinozal.guru/takelogin.php', $url), array_column($client->requests, 0), 'only trusted login and final download are requested');
        boundarySame('username=user&password=pass', $client->requests[3][2], 'login uses real account body');
        boundarySame(array('sid' => 'renewed'), $client->requests[4][3], 'POST cookie is kept without following the downgrade');
        boundarySame(1, $account->data->stored, 'renewed session is stored');
        boundarySame(0, $account->data->removed, 'renewal does not discard the session');
        boundarySame(null, $client->redirectTrust, 'account trust is scoped to its own operation');
    },
    'automatic account refresh scopes the same trusted redirect policy' => function () {
        $account = new BoundaryKinozal();
        $client = new BoundaryTransport();
        $client->cookies = array('sid' => 'existing');
        $client->queue = array(
            array(302, 'https://dl.kinozal.guru/welcome', array(), ''),
            array(200, false, array(), 'welcome'),
            array(302, 'http://kinozal.guru/', array('Set-Cookie: sid=renewed; Path=/'), ''),
        );
        $account->check($client, 'user', 'pass', 0);
        boundarySame(array('https://kinozal.guru', 'https://dl.kinozal.guru/welcome',
            'https://kinozal.guru/takelogin.php'), array_column($client->requests, 0), 'refresh may follow account-owned HTTPS URLs');
        boundarySame(1, $account->data->stored, 'refresh stores the received session');
        boundarySame(null, $client->redirectTrust, 'refresh restores caller policy');
    },
    'a depth-limited response never imports its cookies on the next explicit request' => function () {
        $client = new BoundaryTransport();
        $client->maxredirs = 0;
        $client->queue = array(
            array(302, 'https://tracker.example/next', array('Set-Cookie: stopped=private; Path=/'), ''),
            array(200, false, array(), 'independent'),
        );
        boundarySame(true, $client->fetch('https://tracker.example/start'), 'depth limit retains response');
        boundarySame(true, $client->fetch('https://other.test/independent'), 'later explicit fetch works');
        boundarySame(array(), $client->requests[1][3], 'depth limit left no automatic cookie import');
    },
    'account redirect trust is restored on refusal and never follows a lookalike host' => function () {
        $account = new BoundaryKinozal();
        $client = new BoundaryTransport();
        $previous = function ($url) { return true; };
        $client->redirectTrust = $previous;
        $client->redirect = 'https://kinozal.guru.evil.test/login.php';
        boundarySame(false, $account->fetch($client, 'https://dl.kinozal.guru/download.php?id=7', 'user', 'pass', 'GET', '', ''), 'untrusted redirect is not a guest verdict');
        boundarySame(1, count($client->requests), 'neither credentials nor automatic login follow the target');
        boundarySame(0, $account->data->removed, 'ambiguous answer does not delete cached session');
        boundarySame($previous, $client->redirectTrust, 'caller policy restored');
    },
    'same origin redirects preserve session and anonymous redirects remain available' => function () {
        $client = new BoundaryTransport();
        $client->cookies = array('session' => 'fake-secret');
        $client->replyCookies = true;
        $client->redirect = 'https://TRACKER.EXAMPLE.:443/next';
        boundarySame(true, $client->fetch('https://tracker.example/start'), 'normalized same origin');
        boundarySame(array('session' => 'fake-secret', 'fresh' => 'secret'), $client->requests[1][3], 'cookies remain available');
        $client = new BoundaryTransport();
        $client->redirect = 'https://cdn.example/file';
        boundarySame(true, $client->fetch('https://tracker.example/start'), 'anonymous CDN redirect');
        boundarySame(2, count($client->requests), 'anonymous request follows redirect');
    },
    'redirect depth belongs to one request chain when a client is reused' => function () {
        $client = new BoundaryTransport();
        $client->cookies = array('session' => 'fake-secret');
        $client->maxredirs = 1;
        $client->redirect = 'https://tracker.example/next';
        foreach (array(1, 2) as $attempt) {
            $client->requests = array();
            boundarySame(true, $client->fetch('https://tracker.example/start'), 'same-origin chain ' . $attempt);
            boundarySame(2, count($client->requests), 'each explicit fetch can follow one redirect');
            boundarySame(0, $client->_redirectdepth, 'recursion depth unwinds after the chain');
        }
    },
    'Ygg trusts only its configured HTTPS origin and never a similarly named domain' => function () {
        global $yggTorrentOrigin;
        $yggTorrentOrigin = 'https://www.ygg.re'; // Test configuration, not a claim about a current mirror.
        $account = new BoundaryYgg();
        foreach (array('https://yggevil.example/engine/download_torrent?id=1',
                       'http://www.ygg.re/engine/download_torrent?id=1',
                       'https://www.ygg.re:8443/engine/download_torrent?id=1',
                       'https://cdn.www.ygg.re/engine/download_torrent?id=1',
                       'https://user@www.ygg.re/engine/download_torrent?id=1',
                       'https://user:pass@www.ygg.re/engine/download_torrent?id=1',
                       'https://@www.ygg.re/engine/download_torrent?id=1') as $url) {
            boundarySame(false, $account->test($url), 'selector refuses ' . $url);
            foreach (array(false, true) as $cached) {
                $account->cached = $cached;
                $client = new BoundaryTransport();
                $client->guest = true;
                boundarySame(false, $account->fetch($client, $url, 'fake-user', 'fake-pass', 'GET', '', ''), 'direct fetch refuses untrusted origin');
                boundarySame(array(), $client->requests, 'no session or credential request');
                boundarySame(false, $account->directLogin($client, $url), 'login independently refuses');
                boundarySame(array(), $client->requests, 'login makes no request');
            }
        }
        boundarySame(true, $account->test('https://WWW.YGG.RE./engine/download_torrent?id=1'), 'normalized configured origin');
        $account->cached = false;
        $client = new BoundaryTransport();
        boundarySame(true, $account->fetch($client, 'https://www.ygg.re/engine/download_torrent?id=1', 'fake-user', 'fake-pass', 'GET', '', ''), 'configured login succeeds');
        boundarySame(array('https://www.ygg.re/user/login', 'POST', 'id=fake-user&pass=fake-pass&submit=', array()), $client->requests[1], 'password goes to configured origin');
    },
    'account scoped anonymous redirect never imports a foreign cookie before login' => function () {
        global $yggTorrentOrigin;
        $yggTorrentOrigin = 'https://trusted.example';
        $account = new BoundaryYgg();
        $client = new BoundaryTransport();
        $client->queue = array(
            array(302, 'https://foreign.example/landing', array(), ''),
            array(200, false, array('Set-Cookie: sid=foreign; Path=/'), 'foreign landing'),
            array(200, false, array(), 'login accepted'),
        );
        $url = 'https://trusted.example/engine/download_torrent?id=7';
        boundarySame(false, $account->fetch($client, $url, 'fake-user', 'fake-pass', 'GET', '', ''),
            'an account does not authenticate after leaving its trusted origin');
        boundarySame(array($url), array_column($client->requests, 0),
            'the account never fetches the foreign landing or sends a password afterward');
        boundarySame(array(), $client->cookies, 'the foreign Set-Cookie never enters the flat jar');
        boundarySame('credential-redirect-refused', $client->error, 'the refusal remains visible');
    },
    'Ygg refresh uses a configured origin and an unconfigured account sends nothing' => function () {
        global $yggTorrentOrigin;
        foreach (array('', 'http://abstract.com', 'https://user:pass@ygg.example', 'https://ygg.example/path', 'https://ygg.example?x=1') as $origin) {
            $yggTorrentOrigin = $origin;
            $account = new BoundaryYgg();
            $client = new BoundaryTransport();
            $account->check($client, 'fake-user', 'fake-pass', 0);
            boundarySame(array(), $client->requests, 'invalid configuration cannot refresh');
        }
        $yggTorrentOrigin = 'https://www.ygg.re';
        $account = new BoundaryYgg();
        $client = new BoundaryTransport();
        $account->check($client, 'fake-user', 'fake-pass', 0);
        boundarySame('https://www.ygg.re', $client->requests[0][0], 'refresh landing page');
        boundarySame('https://www.ygg.re/user/login', $client->requests[1][0], 'refresh credential endpoint');
    },
    'an account without an origin cannot enter automatic login' => function () {
        $account = new BoundaryUnconfigured();
        boundarySame('', $account->url, 'the base account has no placeholder website');
        $account->check(new BoundaryTransport(), 'fake-user', 'fake-pass', 0);
        boundarySame(0, $account->logins, 'no credential flow starts without a configured origin');
    },
    'an automatic refresh without origin writes one classified diagnostic' => function () {
        global $log_file;
        $previous = $log_file;
        $log_file = tempnam(sys_get_temp_dir(), 'missing-origin-');
        try {
            $account = new BoundaryUnconfiguredLogged();
            $account->check(new BoundaryTransport(), 'fake-user', 'fake-pass', 0);
            $account->check(new BoundaryTransport(), 'fake-user', 'fake-pass', 0);
            $log = file_get_contents($log_file);
            boundarySame(1, substr_count($log, 'loginmgr: missing-origin: BoundaryUnconfiguredLogged'),
                'unconfigured account explains skipped automatic refresh only once');
            boundarySame(0, $account->logins, 'diagnostic sends no credentials');
        } finally {
            unlink($log_file);
            $log_file = $previous;
        }
    },
    'LostFilm recovery reads the actual query id and exact download path' => function () {
        $account = new BoundaryLostFilm();
        foreach (array(
            'https://lostfilm.tv/download.php?ref=/download.php?id=999&id=7' => '7',
            'https://lostfilm.tv/download.php?id=1&%69d=7' => '7',
            'https://lostfilm.tv/download.php?id=7' => '7',
            'https://lostfilm.tv/download.php?id[]=7' => false,
            'https://lostfilm.tv/download.php?id=7%0A' => false,
            'https://lostfilm.tv/download.php.bak?id=7&' => false,
            'https://evil.test/download.php?id=7&' => false,
        ) as $url => $id) {
            $client = new BoundaryTransport();
            $client->status = 200;
            $client->results = 'download response';
            $client->lastredirectaddr = 'https://lostfilm.tv/browse.php?cat=1';
            $account->recover($client, $url);
            boundarySame($id === false ? array() : array('https://lostfilm.tv/details.php?id=' . $id), array_column($client->requests, 0), 'recovery target for ' . $url);
        }
    },
);
$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "ok - $name\n"; }
    catch (Throwable $error) { $failures++; echo "not ok - $name\n  " . $error->getMessage() . "\n"; }
}
echo count($tests) . " tests, $failures failures\n";
@unlink($_ENV['RU_LOG_FILE']);
exit($failures ? 1 : 0);
