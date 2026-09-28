<?php

// Exercise the real fetchComplex() source selection with isolated disk caches.
$sourceTestRoot = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/loginmgr-sources-' . getmypid() . '-' . bin2hex(random_bytes(4));
$_ENV['RU_PROFILE_PATH'] = $sourceTestRoot;
$_ENV['RU_LOG_FILE'] = $sourceTestRoot . '/loginmgr.log';

require_once(__DIR__ . '/../../../php/Snoopy.class.inc');
require_once(__DIR__ . '/../../../plugins/cookies/cookies.php');
require_once(__DIR__ . '/../../../plugins/loginmgr/accounts.php');

class SourceRecordingSnoopy extends Snoopy
{
    public $requests = array();
    public $redirectTo = null;

    public function connect() { return fopen('php://memory', 'r+'); }

    public function _httprequest($url, $fp, $URI, $method, $content_type = '', $body = '')
    {
        $this->record($URI);
        return true;
    }

    public function _httpsrequest($url, $content_type = '', $body = '', $method = 'GET')
    {
        $this->record($url);
        return true;
    }

    private function record($url)
    {
        $this->requests[] = array('url' => $url, 'cookies' => $this->cookiesForRequest($url));
        $this->status = 200;
        $this->results = 'readable tracker page';
        $this->_redirectaddr = count($this->requests) === 1 ? $this->redirectTo : false;
    }
}

require_once(__DIR__ . '/RedirectProbeAccountFixture.php');

class SourceAltAccount extends RedirectProbeAccount
{
    public function test($url)
    {
        return UrlHost::urlIsOneOf($url, array('rutracker.org'), 'https', '/second/');
    }
}

class SourcePortAccount extends commonAccount
{
    public $url = 'https://tracker.example:8443';
    protected function isOK($client) { return true; }
    protected function login($client, $login, $password, &$url, &$method, &$content_type, &$body, &$is_result_fetched)
    {
        return false;
    }
}

function sourceSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

function sourceLog()
{
    $path = $_ENV['RU_LOG_FILE'];
    return is_file($path) ? file_get_contents($path) : '';
}

function sourceRemoveTree($path)
{
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path) as $item) {
        if ($item->isDir()) sourceRemoveTree($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($path);
}

function sourceWireRows($path)
{
    $rows = array();
    foreach(is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : array() as $line)
    {
        $row = explode("\t", $line, 2);
        $rows[] = array('url' => $row[0], 'cookie' => isset($row[1]) ? $row[1] : '');
    }
    return $rows;
}

function sourceWireHasCookie($row, $pair)
{
    foreach(explode(';', substr($row['cookie'], 7)) as $entry)
        if(trim($entry) === $pair)
            return true;
    return false;
}

$failed = 0;
try {
    rTorrentSettings::get()->registerPlugin('cookies');
    rTorrentSettings::get()->registerPlugin('loginmgr');

    $pluginCookies = new rCookies();
    $pluginCookies->list['rutracker.org'] = array('plugin_marker' => 'plugin-value');
    $pluginCookies->list['cookie.test'] = array('plugin_marker' => 'plugin-value');
    $pluginCookies->list['tracker.example'] = array('plugin_marker' => 'plugin-value');
    $pluginCookies->list['mteam.fr'] = array('plugin_marker' => 'plugin-value');
    sourceSame(true, $pluginCookies->store(), 'cookies plugin fixture saved');

    $manager = new accountManager();
    $manager->accounts['RUTracker'] = array(
        'name' => 'RUTracker',
        'path' => realpath(__DIR__ . '/../../../plugins/loginmgr/accounts/RUTracker.php'),
        'object' => 'ruTrackerAccount',
        'login' => 'fixture-user',
        'password' => 'fixture-password',
        'enabled' => 1,
        'auto' => 0,
    );
    $manager->accounts['RedirectProbe'] = array(
        'name' => 'RedirectProbe',
        'path' => realpath(__DIR__ . '/RedirectProbeAccountFixture.php'),
        'object' => 'RedirectProbeAccount',
        'login' => 'fixture-user',
        'password' => 'fixture-password',
        'enabled' => 1,
        'auto' => 0,
    );
    $manager->accounts['SourceAlt'] = array(
        'name' => 'SourceAlt',
        'path' => realpath(__DIR__ . '/RedirectProbeAccountFixture.php'),
        'object' => 'SourceAltAccount',
        'login' => 'fixture-user',
        'password' => 'fixture-password',
        'enabled' => 1,
        'auto' => 0,
    );
    $manager->accounts['mTeam'] = array(
        'name' => 'mTeam',
        'path' => realpath(__DIR__ . '/../../../plugins/loginmgr/accounts/mTeam.php'),
        'object' => 'mTeamAccount',
        'login' => 'fixture-user',
        'password' => 'fixture-password',
        'enabled' => 1,
        'auto' => 0,
    );
    sourceSame(true, $manager->store(), 'loginmgr account fixture saved');
    sourceSame('SourceAlt', $manager->getAccount('https://rutracker.org/second/file'),
        'the second path selects a different account on the same host');

    $session = new privateData('ruTracker');
    $session->cookies = array('loginmgr_marker' => 'session-value');
    sourceSame(true, (new rCache('/accounts'))->set($session), 'loginmgr session fixture saved');
    $mteamSession = new privateData('mTeam');
    $mteamSession->cookies = array('loginmgr_marker' => 'mteam-session');
    $mteamSession->referer = 'https://mteam.fr/landing';
    sourceSame(true, (new rCache('/accounts'))->set($mteamSession),
        'mTeam session fixture saved');

    // Legacy host-only cookies are treated as HTTPS-only. A URL's own
    // :COOKIE: source remains an explicit choice by the caller.
    sourceSame(array('plugin_marker' => 'plugin-value'),
        rCookies::load()->getCookiesForHost('rutracker.org'), 'cookies plugin source');
    $urlWithCookie = 'http://RuTracker.Org./forum/index.php:COOKIE:url_marker=url-value';
    $urlForSourceCheck = $urlWithCookie;
    sourceSame(array('url_marker' => 'url-value'), Snoopy::getURLCookies($urlForSourceCheck), ':COOKIE: source');

    $reloaded = new SourceRecordingSnoopy();
    $cached = privateData::load('ruTracker', $reloaded);
    sourceSame(true, $cached->loaded, 'cached session was loaded into a new client');
    sourceSame(true, $reloaded->fetch('http://rutracker.org/forum/index.php'),
        'direct HTTP request after cache reload completed');
    sourceSame(false, isset($reloaded->requests[0]['cookies']['loginmgr_marker']),
        'cached account session stays off direct HTTP request');
    sourceSame(1, substr_count(sourceLog(),
        'Snoopy: loginmgr-session-cookie-http-refused host=rutracker.org'),
        'cached account refusal is visible without cookie data');
    sourceSame(false, strpos(sourceLog(), 'session-value') !== false,
        'cached account cookie value stays out of the log');
    $reloaded->requests = array();
    sourceSame(true, $reloaded->fetchComplex('http://rutracker.org/forum/index.php'),
        'fetchComplex after a direct cache load completed');
    sourceSame(false, isset($reloaded->requests[0]['cookies']['loginmgr_marker']),
        'fetchComplex keeps a previously loaded account session off HTTP');
    $reloaded->requests = array();
    sourceSame(true, $reloaded->fetchComplex(
        'http://rutracker.org/forum/index.php:COOKIE:loginmgr_marker=explicit-value'),
        'explicit URL cookie remains available on HTTP');
    sourceSame('explicit-value', $reloaded->requests[0]['cookies']['loginmgr_marker'] ?? null,
        'the URL source is distinct from the cached account session');
    $reloaded->requests = array();
    sourceSame(true, $reloaded->fetch('http://rutracker.org/forum/index.php'),
        'client remains usable after explicit URL cookie');
    sourceSame(false, isset($reloaded->requests[0]['cookies']['loginmgr_marker']),
        'cached session remains protected after fetchComplex restores the facade');
    $reloaded->requests = array();
    sourceSame(true, $reloaded->fetchComplex('https://rutracker.org/forum/index.php'),
        'account HTTPS request remains available');
    sourceSame('session-value', $reloaded->requests[0]['cookies']['loginmgr_marker'] ?? null,
        'cached session still reaches its HTTPS account');

    $httpClient = new SourceRecordingSnoopy();
    sourceSame(true, $httpClient->fetchComplex($urlWithCookie), 'HTTP request completed');
    sourceSame(1, count($httpClient->requests), 'one HTTP request');
    sourceSame($urlForSourceCheck, $httpClient->requests[0]['url'], ':COOKIE: suffix removed from URL');
    sourceSame(false, isset($httpClient->requests[0]['cookies']['loginmgr_marker']),
        'HTTPS loginmgr session must not reach the HTTP request');
    sourceSame(false, isset($httpClient->requests[0]['cookies']['plugin_marker']),
        'legacy host-only plugin cookie must not reach HTTP');
    sourceSame('url-value', $httpClient->requests[0]['cookies']['url_marker'] ?? null,
        'explicit URL cookie remains available to its own request');
    sourceSame(1, substr_count(sourceLog(),
        'cookies: http-refused: source=cookies host=rutracker.org account=RUTracker; use HTTPS URL'),
        'plugin refusal logs its normalized host and known HTTPS account');

    // Both HTTPS accounts resolve to the same persisted-cookie host.
    // N-S1 permits one HTTP refusal per host for this PHP process.
    $secondAccount = new SourceRecordingSnoopy();
    sourceSame(true, $secondAccount->fetchComplex('http://rutracker.org/second/file'),
        'second account HTTP request completed');
    sourceSame(false, isset($secondAccount->requests[0]['cookies']['plugin_marker']),
        'the plugin cookie also stays off the second HTTP request');
    preg_match_all('/cookies: http-refused: [^\n]*host=rutracker\.org[^\n]*/',
        sourceLog(), $sharedHostLogs);
    sourceSame(1, count($sharedHostLogs[0]),
        'two accounts on one host produce only one plugin-cookie refusal');

    $explicitClient = new SourceRecordingSnoopy();
    $logBeforeExplicit = sourceLog();
    sourceSame(true, $explicitClient->fetchComplex('http://explicit.test/file:COOKIE:url_marker=url-value'),
        'explicit-only HTTP request completed');
    sourceSame('url-value', $explicitClient->requests[0]['cookies']['url_marker'] ?? null,
        'explicit URL cookie is a separate permitted HTTP source');
    sourceSame($logBeforeExplicit, sourceLog(),
        'an explicit-only cookie does not trigger the plugin refusal log');

    // Positive control: the same persisted session is loaded for the HTTPS
    // account, so the HTTP assertion cannot pass merely because cache setup failed.
    $httpsClient = new SourceRecordingSnoopy();
    sourceSame(true, $httpsClient->fetchComplex('https://rutracker.org/forum/index.php'),
        'HTTPS account request completed');
    sourceSame(1, count($httpsClient->requests), 'one HTTPS request');
    sourceSame('session-value', $httpsClient->requests[0]['cookies']['loginmgr_marker'] ?? null,
        'loginmgr session positive control');
    sourceSame(true, $httpsClient->fetchComplex('http://rutracker.org/forum/index.php'),
        'account client can be reused over HTTP');
    sourceSame(false, isset($httpsClient->requests[1]['cookies']['loginmgr_marker']),
        'HTTPS account session cannot survive into a later HTTP request');

    sourceSame(true, $httpClient->fetchComplex('http://other.test/file'),
        'client can be reused on a different host');
    sourceSame(false, isset($httpClient->requests[1]['cookies']['url_marker']),
        'URL cookie cannot survive into an unrelated later request');
    $pluginClient = new SourceRecordingSnoopy();
    sourceSame(true, $pluginClient->fetchComplex('https://cookie.test/file'),
        'HTTPS plugin-cookie request completed');
    sourceSame('plugin-value', $pluginClient->requests[0]['cookies']['plugin_marker'] ?? null,
        'legacy host-only plugin cookie remains available on HTTPS');
    sourceSame(true, $pluginClient->fetchComplex('http://cookie.test/file'),
        'reused client can make a later HTTP request');
    sourceSame(false, isset($pluginClient->requests[1]['cookies']['plugin_marker']),
        'earlier HTTPS plugin cookie must not survive into a later HTTP request');
    $upgrade = new SourceRecordingSnoopy();
    $upgrade->redirectTo = 'https://cookie.test/file/next';
    sourceSame(true, $upgrade->fetchComplex('http://COOKIE.TEST./file'),
        'anonymous HTTP to HTTPS upgrade completed');
    sourceSame(2, count($upgrade->requests), 'upgrade has HTTP and HTTPS requests');
    foreach($upgrade->requests as $request)
        sourceSame(false, isset($request['cookies']['plugin_marker']),
            'plugin cookie is absent on both hops when the source URL is HTTP');
    sourceSame(1, substr_count(sourceLog(),
        'cookies: http-refused: source=cookies host=cookie.test; use HTTPS URL'),
        'plugin refusal without loginmgr account logs only the normalized host');
    preg_match_all('/cookies: http-refused: [^\n]*host=cookie\.test[^\n]*/',
        sourceLog(), $unknownAccountLogs);
    sourceSame(1, count($unknownAccountLogs[0]),
        'HTTP plugin-cookie refusal is logged once for the account-free host');
    sourceSame(false, strpos($unknownAccountLogs[0][0], 'account=') !== false,
        'account-free refusal does not invent a loginmgr account');

    // An account may trust a sibling download host, but persisted and URL
    // cookies were supplied for the source host alone.
    $sibling = new SourceRecordingSnoopy();
    $sibling->cookies = array('preset_marker' => 'preset-value');
    $sibling->redirectTo = 'https://dl.tracker.example/file/next';
    sourceSame(true, $sibling->fetchComplex('https://tracker.example/file/start:COOKIE:url_marker=url-value'),
        'allowed sibling redirect completed');
    sourceSame(2, count($sibling->requests), 'source and sibling each made a request');
    sourceSame(1, RedirectProbeAccount::$loginCalls,
        'first account request exercises the commonAccount login lifecycle');
    sourceSame('plugin-value', $sibling->requests[0]['cookies']['plugin_marker'] ?? null,
        'persisted cookie reaches its exact source host');
    sourceSame('url-value', $sibling->requests[0]['cookies']['url_marker'] ?? null,
        'explicit URL cookie reaches its source host');
    sourceSame(false, isset($sibling->requests[1]['cookies']['plugin_marker']),
        'persisted host-only cookie stays off the allowed sibling wire');
    sourceSame(false, isset($sibling->requests[1]['cookies']['url_marker']),
        'explicit URL cookie stays off the allowed sibling wire');
    sourceSame('session-value', $sibling->requests[1]['cookies']['loginmgr_marker'] ?? null,
        'loginmgr account session still follows its allowed redirect');
    sourceSame('preset-value', $sibling->requests[1]['cookies']['preset_marker'] ?? null,
        'caller pre-set cookie retains its existing redirect behavior');
    $saved = new privateData('RedirectProbe');
    sourceSame(true, (new rCache('/accounts'))->get($saved), 'account session cache saved');
    sourceSame(false, isset($saved->cookies['plugin_marker']),
        'source plugin cookie is not promoted into a reusable account session');
    sourceSame(false, isset($saved->cookies['url_marker']),
        'source URL cookie is not promoted into a reusable account session');
    sourceSame('session-value', $saved->cookies['loginmgr_marker'] ?? null,
        'account session cookie remains persisted');

    $sameHost = new SourceRecordingSnoopy();
    $sameHost->cookies = array('preset_marker' => 'preset-value');
    $sameHost->redirectTo = 'https://tracker.example/file/next';
    sourceSame(true, $sameHost->fetchComplex('https://tracker.example/file/start:COOKIE:url_marker=url-value'),
        'same-host redirect completed');
    sourceSame(1, RedirectProbeAccount::$loginCalls,
        'saved account session is reused without another login');
    foreach($sameHost->requests as $request)
    {
        sourceSame('plugin-value', $request['cookies']['plugin_marker'] ?? null,
            'cached account keeps the persisted cookie on both same-host requests');
        sourceSame('url-value', $request['cookies']['url_marker'] ?? null,
            'cached account keeps the explicit URL cookie on both same-host requests');
        sourceSame('preset-value', $request['cookies']['preset_marker'] ?? null,
            'cached account keeps the caller cookie on both same-host requests');
        sourceSame('session-value', $request['cookies']['loginmgr_marker'] ?? null,
            'cached account keeps its loginmgr session on both same-host requests');
    }

    $mteamClient = new SourceRecordingSnoopy();
    $mteamClient->cookies = array('preset_marker' => 'preset-value');
    sourceSame(true, $mteamClient->fetchComplex('https://mteam.fr/file:COOKIE:url_marker=url-value'),
        'mTeam cached account request completed');
    sourceSame('plugin-value', $mteamClient->requests[0]['cookies']['plugin_marker'] ?? null,
        'mTeam keeps the persisted cookie with its cached session');
    sourceSame('url-value', $mteamClient->requests[0]['cookies']['url_marker'] ?? null,
        'mTeam keeps the URL cookie with its cached session');
    sourceSame('preset-value', $mteamClient->requests[0]['cookies']['preset_marker'] ?? null,
        'mTeam keeps the caller cookie with its cached session');
    sourceSame('mteam-session', $mteamClient->requests[0]['cookies']['loginmgr_marker'] ?? null,
        'mTeam keeps its cached loginmgr cookie');
    sourceSame('https://mteam.fr/landing', $mteamClient->referer,
        'mTeam still restores its cached referer');

    $rootDotClient = new SourceRecordingSnoopy();
    sourceSame(true, $rootDotClient->fetchComplex('https://cookie.test./file'),
        'trailing-root-dot request completed');
    sourceSame('plugin-value', $rootDotClient->requests[0]['cookies']['plugin_marker'] ?? null,
        'normalized equivalent host finds the existing plugin cookie');
    $legacy = new rCookies();
    $legacy->list['legacy.test.'] = array('old' => 'secret');
    sourceSame(true, (new rCache())->set($legacy), 'legacy root-dot key saved verbatim');
    sourceSame(array('old' => 'secret'), rCookies::load()->getCookiesForHost('legacy.test'),
        'old root-dot key is readable under its canonical host');
    sourceSame(array('legacy.test' => true), rCookies::load()->getInfo(),
        'loading legacy keys exposes only the normalized host without its value');
    $legacy = rCookies::load();
    $legacy->add('legacy.test', '');
    sourceSame(array(), rCookies::load()->getCookiesForHost('legacy.test.'),
        'deleting a normalized host cannot resurrect its old root-dot key');
    foreach(array(
        array('Rutracker.org' => array('sid' => 'folded'),
            'rutracker.org' => array('sid' => 'exact'),
            'rutracker.org.' => array('sid' => 'root-dot')),
        array('rutracker.org' => array('sid' => 'exact'),
            'Rutracker.org' => array('sid' => 'folded'),
            'rutracker.org.' => array('sid' => 'root-dot')),
    ) as $collision)
    {
        $legacy = new rCookies();
        $legacy->list = $collision;
        sourceSame(true, (new rCache())->set($legacy), 'colliding legacy keys saved verbatim');
        sourceSame(array('sid' => 'exact'), rCookies::load()->getCookiesForHost('rutracker.org.'),
            'the exact canonical host wins regardless of legacy insertion order');
    }
    $legacy = rCookies::load();
    $legacy->add('rutracker.org.', 'sid=updated');
    sourceSame(array('sid' => 'updated'), rCookies::load()->getCookiesForHost('rutracker.org'),
        'add through a root-dot host replaces the canonical value');

    foreach(array('http', 'https') as $scheme)
    {
        $pathless = new SourceRecordingSnoopy();
        sourceSame(true, $pathless->fetchComplex($scheme.'://cookie.test:COOKIE:sid=explicit'),
            'pathless URL with explicit cookie completed on '.$scheme);
        sourceSame('explicit', $pathless->requests[0]['cookies']['sid'] ?? null,
            'pathless URL cookie reaches only its original host on '.$scheme);
    }

    $equalsCookie = new rCookies();
    $equalsCookie->add('equal.test', 'token=abc==; malformed; other=ok');
    sourceSame(array('token' => 'abc==', 'other' => 'ok'),
        rCookies::load()->getCookiesForHost('equal.test'),
        'plugin add keeps equals inside values and ignores malformed pairs');
    $postedCookie = new rCookies();
    $postedCookie->set('cookie=' . rawurlencode('equal.test|token=abc==;other=ok'));
    sourceSame(array('token' => 'abc==', 'other' => 'ok'),
        rCookies::load()->getCookiesForHost('equal.test'),
        'plugin form input keeps equals inside cookie values');
    // Public account selection and the serialized curl Cookie header must
    // agree: two accounts on one host do not share response-cookie state.
    $wirePath = $sourceTestRoot . '/account-wire.log';
    $curlPath = $sourceTestRoot . '/account-fake-curl';
    $fakeCurl = <<<'SH'
#!/bin/sh
header_file=
body_file=
request_url=
cookie_header=
while [ "$#" -gt 0 ]; do
    case "$1" in
        -D) shift; header_file=$1 ;;
        -o) shift; body_file=$1 ;;
        -H)
            shift
            case "$1" in Cookie:*) cookie_header=$1 ;; esac
            ;;
        https://*) request_url=$1 ;;
    esac
    shift
done
printf '%s\t%s\n' "$request_url" "$cookie_header" >> "$SOURCE_WIRE_LOG"
printf 'HTTP/1.1 200 OK\r\n' > "$header_file"
case "$request_url" in
    https://rutracker.org/forum/*)
        printf 'Set-Cookie: a_marker=from-a; Domain=rutracker.org; Path=/; Secure\r\n' >> "$header_file"
        ;;
    https://rutracker.org/second/*)
        printf 'Set-Cookie: b_marker=from-b; Domain=rutracker.org; Path=/; Secure\r\n' >> "$header_file"
        ;;
esac
printf '\r\n' >> "$header_file"
printf 'readable tracker page' > "$body_file"
SH;
    sourceSame(true, file_put_contents($curlPath, $fakeCurl) !== false, 'wire fixture written');
    sourceSame(true, chmod($curlPath, 0700), 'wire fixture executable');
    putenv('SOURCE_WIRE_LOG=' . $wirePath);
    $previousCurl = isset($pathToExternals['curl']) ? $pathToExternals['curl'] : null;
    $pathToExternals['curl'] = $curlPath;
    try
    {
        $gateFailures = array();
        try
        {
            $partitioned = new Snoopy();
            sourceSame(true, $partitioned->fetchComplex('https://rutracker.org/forum/index.php'),
                'account A initial public fetch completed');
            sourceSame(true, $partitioned->fetchComplex('https://rutracker.org/forum/index.php'),
                'account A later explicit fetch completed');
            $rows = sourceWireRows($wirePath);
            sourceSame(2, count($rows), 'two A requests reached the wire');
            sourceSame(true, sourceWireHasCookie($rows[1], 'a_marker=from-a'),
                'A receives its own response cookie on a later explicit request');

            sourceSame(true, $partitioned->fetchComplex('https://rutracker.org/second/file'),
                'account B initial public fetch completed');
            $rows = sourceWireRows($wirePath);
            sourceSame(false, sourceWireHasCookie($rows[2], 'a_marker=from-a'),
                'B cannot receive A response cookie on the same host');
            sourceSame(true, $partitioned->fetchComplex('https://rutracker.org/second/file'),
                'account B later explicit fetch completed');
            $rows = sourceWireRows($wirePath);
            sourceSame(true, sourceWireHasCookie($rows[3], 'b_marker=from-b'),
                'B receives its own response cookie');
            sourceSame(false, sourceWireHasCookie($rows[3], 'a_marker=from-a'),
                'B still cannot receive A response cookie');

            sourceSame(true, $partitioned->fetchComplex('https://rutracker.org/forum/index.php'),
                'account A remains usable after B');
            $rows = sourceWireRows($wirePath);
            sourceSame(true, sourceWireHasCookie($rows[4], 'a_marker=from-a'),
                'A retains its own response cookie');
            sourceSame(false, sourceWireHasCookie($rows[4], 'b_marker=from-b'),
                'A cannot receive B response cookie');

            $storedA = new privateData('ruTracker');
            $storedB = new privateData('SourceAlt');
            $cache = new rCache('/accounts');
            sourceSame(true, $cache->get($storedA), 'A flat session cache remains readable');
            sourceSame(true, $cache->get($storedB), 'B flat session cache remains readable');
            sourceSame('session-value', $storedA->cookies['loginmgr_marker'] ?? null,
                'A legacy flat session stays intact');
            sourceSame(false, isset($storedA->cookies['a_marker']) || isset($storedA->cookies['b_marker'])
                || isset($storedB->cookies['a_marker']) || isset($storedB->cookies['b_marker']),
                'response jar cookies are not flattened into either account cache');
            $freshA = new Snoopy();
            sourceSame(true, $freshA->fetchComplex('https://rutracker.org/forum/index.php'),
                'fresh client reloads A legacy flat cache');
            $rows = sourceWireRows($wirePath);
            sourceSame(true, sourceWireHasCookie($rows[5], 'loginmgr_marker=session-value'),
                'flat cache cookie reaches its original account');
            sourceSame(false, sourceWireHasCookie($rows[5], 'a_marker=from-a'),
                'object-local response jar is not silently persisted');

        }
        catch (RuntimeException $error)
        {
            $gateFailures[] = $error->getMessage();
        }
        try
        {
            $cache = new rCache('/accounts');
            $manager->accounts['SourcePort'] = array(
                'name' => 'SourcePort', 'path' => __FILE__, 'object' => 'SourcePortAccount',
                'login' => 'fixture-user', 'password' => 'fixture-password',
                'enabled' => 1, 'auto' => 0,
            );
            sourceSame(true, $manager->store(), 'port-scoped account fixture saved');
            $portSession = new privateData('SourcePort');
            $portSession->cookies = array('port_marker' => 'port-session');
            sourceSame(true, $cache->set($portSession), 'port-scoped flat session saved');
            $portClient = new Snoopy();
            sourceSame(true, $portClient->fetchComplex('https://tracker.example:8443/port'),
                'configured port request completed');
            $rows = sourceWireRows($wirePath);
            sourceSame('https://tracker.example:8443/port', $rows[count($rows) - 1]['url'] ?? null,
                'configured account port reached the wire');
            sourceSame(true, sourceWireHasCookie($rows[count($rows) - 1], 'port_marker=port-session'),
                'configured account port receives its cached session');
            $before = count($rows);
            sourceSame(false, $portClient->fetchComplex('https://tracker.example:443/port'),
                'wrong initial port is refused before account fetch');
            sourceSame(Snoopy::CREDENTIAL_REDIRECT_REFUSED, $portClient->error,
                'wrong initial port has a classified refusal');
            sourceSame($before, count(sourceWireRows($wirePath)),
                'wrong initial port sends no request or session');
            sourceSame(true, strpos(sourceLog(), 'Snoopy: account-port-refused host=tracker.example') !== false,
                'wrong initial port writes a safe operator diagnostic');
        }
        catch (RuntimeException $error)
        {
            $gateFailures[] = $error->getMessage();
        }
        if($gateFailures)
            throw new RuntimeException(implode('; ', $gateFailures));
    }
    finally
    {
        if($previousCurl === null) unset($pathToExternals['curl']);
        else $pathToExternals['curl'] = $previousCurl;
        putenv('SOURCE_WIRE_LOG');
    }

    $log = sourceLog();
    foreach(array('plugin-value', 'url-value', 'session-value', 'mteam-session') as $secret)
        sourceSame(false, strpos($log, $secret) !== false,
            'refusal and account diagnostics never log cookie values');

    echo "ok - fetchComplex keeps loginmgr credentials off HTTP and parses URL cookies\n";
} catch (Throwable $error) {
    $failed = 1;
    echo "not ok - fetchComplex keeps loginmgr credentials off HTTP and parses URL cookies\n  "
        . $error->getMessage() . "\n";
} finally {
    sourceRemoveTree($sourceTestRoot);
}
echo "1 test, $failed failures\n";
exit($failed);
