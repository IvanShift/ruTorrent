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
        $this->requests[] = array('url' => $url, 'cookies' => $this->cookies);
        $this->status = 200;
        $this->results = 'readable tracker page';
        $this->_redirectaddr = false;
    }
}

function sourceSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
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

$failed = 0;
try {
    rTorrentSettings::get()->registerPlugin('cookies');
    rTorrentSettings::get()->registerPlugin('loginmgr');

    $pluginCookies = new rCookies();
    $pluginCookies->list['rutracker.org'] = array('plugin_marker' => 'plugin-value');
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
    sourceSame(true, $manager->store(), 'loginmgr account fixture saved');

    $session = new privateData('ruTracker');
    $session->cookies = array('loginmgr_marker' => 'session-value');
    sourceSame(true, (new rCache('/accounts'))->set($session), 'loginmgr session fixture saved');

    // These sources are present in the input. N-S1 remains open: this test
    // deliberately makes no assertion that sending either one over HTTP is safe.
    sourceSame(array('plugin_marker' => 'plugin-value'),
        rCookies::load()->getCookiesForHost('rutracker.org'), 'cookies plugin source');
    $urlWithCookie = 'http://rutracker.org/forum/index.php:COOKIE:url_marker=url-value';
    $urlForSourceCheck = $urlWithCookie;
    sourceSame(array('url_marker' => 'url-value'), Snoopy::getURLCookies($urlForSourceCheck), ':COOKIE: source');

    $httpClient = new SourceRecordingSnoopy();
    sourceSame(true, $httpClient->fetchComplex($urlWithCookie), 'HTTP request completed');
    sourceSame(1, count($httpClient->requests), 'one HTTP request');
    sourceSame($urlForSourceCheck, $httpClient->requests[0]['url'], ':COOKIE: suffix removed from URL');
    sourceSame(false, isset($httpClient->requests[0]['cookies']['loginmgr_marker']),
        'HTTPS loginmgr session must not reach the HTTP request');

    // Positive control: the same persisted session is loaded for the HTTPS
    // account, so the HTTP assertion cannot pass merely because cache setup failed.
    $httpsClient = new SourceRecordingSnoopy();
    sourceSame(true, $httpsClient->fetchComplex('https://rutracker.org/forum/index.php'),
        'HTTPS account request completed');
    sourceSame(1, count($httpsClient->requests), 'one HTTPS request');
    sourceSame('session-value', $httpsClient->requests[0]['cookies']['loginmgr_marker'] ?? null,
        'loginmgr session positive control');
    echo "ok - fetchComplex keeps the HTTPS loginmgr session off HTTP\n";
} catch (Throwable $error) {
    $failed = 1;
    echo "not ok - fetchComplex keeps the HTTPS loginmgr session off HTTP\n  "
        . $error->getMessage() . "\n";
} finally {
    sourceRemoveTree($sourceTestRoot);
}
echo "1 test, $failed failures\n";
exit($failed);
