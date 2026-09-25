<?php

require_once(__DIR__ . '/../../php/TestCase.php');

$_ENV['RU_LOG_FILE'] = sys_get_temp_dir() . '/ygg-config-' . getmypid() . '.log';

require_once(__DIR__ . '/../../../plugins/loginmgr/accounts.php');
require_once(__DIR__ . '/../../../plugins/loginmgr/accounts/YggTorrent.php');
require_once(__DIR__ . '/../../../plugins/extsearch/engines.php');
require_once(__DIR__ . '/../../../plugins/extsearch/engines/YggTorrent.php');
// These in-process engine tests model an enabled loginmgr plugin.
rTorrentSettings::get()->registerPlugin('loginmgr');

// Copy the small runtime boundary so the real config loader runs in a fresh PHP
// process without editing an operator's conf or reusing require_once state.
function yggCopyTree($source, $target)
{
    mkdir($target, 0700, true);
    foreach (new DirectoryIterator($source) as $entry) {
        if ($entry->isDot()) { continue; }
        $destination = $target . '/' . $entry->getFilename();
        if ($entry->isDir()) { yggCopyTree($entry->getPathname(), $destination); }
        else { copy($entry->getPathname(), $destination); }
    }
}
function yggRemoveTree($path)
{
    foreach (new DirectoryIterator($path) as $entry) {
        if ($entry->isDot()) { continue; }
        if ($entry->isDir() && !$entry->isLink()) { yggRemoveTree($entry->getPathname()); }
        else { unlink($entry->getPathname()); }
    }
    rmdir($path);
}
function yggChild($arguments, $cwd = null)
{
    $process = proc_open(array_merge(array(PHP_BINARY, '-c', __DIR__ . '/../../php-test.ini'), $arguments),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $cwd);
    if (!is_resource($process)) { throw new RuntimeException('Configuration child did not start'); }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    testAssertSame(0, proc_close($process), 'configuration child exit: ' . $stdout . $stderr);
    testAssertSame('', $stderr, 'configuration child diagnostics');
    return $stdout;
}
function yggEditedPluginTemplate($path, $origin)
{
    $template = file_get_contents($path);
    $edited = preg_replace('/^[ \t]*(?:\/\/[ \t]*)?\$yggTorrentOrigin[ \t]*=[ \t]*[^;]+;/m',
        '$yggTorrentOrigin = ' . var_export($origin, true) . ';', $template, 1, $count);
    testAssertSame(1, $count, 'template exposes one origin assignment to edit');
    return $edited;
}
function yggConfigProbe($configuration)
{
    $source = dirname(__DIR__, 3);
    $root = sys_get_temp_dir() . '/ygg-' . uniqid();
    mkdir($root, 0700, true);
    try {
        yggCopyTree($source . '/php', $root . '/php');
        yggCopyTree($source . '/plugins/loginmgr', $root . '/plugins/loginmgr');
        // Local overrides belong to the fixture, never to the developer's checkout.
        if (is_file($root . '/plugins/loginmgr/conf.local.php')) { unlink($root . '/plugins/loginmgr/conf.local.php'); }
        mkdir($root . '/conf', 0700);
        $config = file_get_contents($source . '/conf/config.php');
        $config .= "\n\$profilePath = " . var_export($root . '/share', true) . ";\n";
        $config .= "\$log_file = " . var_export($root . '/application.log', true) . ";\n";
        if ($configuration !== 'default') { $config .= "\$yggTorrentOrigin = 'https://global.example:8443';\n"; }
        $config .= "\$forbidUserSettings = " . (in_array($configuration, array('user', 'template-user'), true) ? 'false' : 'true') . ";\n";
        file_put_contents($root . '/conf/config.php', $config);
        $expected = 'https://global.example:8443';
        if (in_array($configuration, array('local', 'user', 'template-local', 'template-user'), true)) {
            $expected = 'https://local.example:9443';
            $local = $configuration === 'template-local' || $configuration === 'template-user'
                ? yggEditedPluginTemplate($source . '/plugins/loginmgr/conf.php', $expected)
                : "<?php \$yggTorrentOrigin = '$expected';\n";
            file_put_contents($root . '/plugins/loginmgr/conf.local.php', $local);
        }
        if ($configuration === 'user' || $configuration === 'template-user') {
            mkdir($root . '/conf/users/test/plugins/loginmgr', 0700, true);
            $expected = 'https://user.example:10443';
            $user = $configuration === 'template-user'
                ? yggEditedPluginTemplate($source . '/plugins/loginmgr/conf.php', $expected)
                : "<?php \$yggTorrentOrigin = '$expected';\n";
            file_put_contents($root . '/conf/users/test/plugins/loginmgr/conf.php', $user);
        }
        // The native runner rewrites directory magic constants throughout the
        // source, including this child script. Its cwd is the fixture root.
        $script = <<<'CHILD'
<?php
$_SERVER['REMOTE_USER'] = 'test';
require getcwd() . '/php/util.php';
require getcwd() . '/plugins/loginmgr/accounts.php';
$manager = new accountManager();
$manager->accounts = array('YggTorrent' => array('path' => getcwd() . '/plugins/loginmgr/accounts/YggTorrent.php', 'object' => 'YggTorrentAccount', 'enabled' => 1));
echo json_encode(array($yggTorrentOrigin, $manager->getAccount($argv[1] . '/engine/download_torrent?id=1')));
CHILD;
        file_put_contents($root . '/probe.php', $script);
        $stdout = yggChild(array($root . '/probe.php', $expected), $root);
        testAssertSame($configuration === 'default' ? array(null, false) : array($expected, 'YggTorrent'),
            json_decode($stdout, true), $configuration . ' configuration reaches account selection');
    } finally { yggRemoveTree($root); }
}

function yggManager($enabled = 1)
{
    $manager = new accountManager();
    $manager->accounts = array('YggTorrent' => array(
        'path' => __DIR__ . '/../../../plugins/loginmgr/accounts/YggTorrent.php',
        'object' => 'YggTorrentAccount', 'enabled' => $enabled,
        'login' => '', 'password' => '', 'auto' => 0,
    ));
    return $manager;
}

class YggConfigLogin extends YggTorrentAccount
{
    public function directLogin($client, $url)
    {
        $method = 'GET'; $contentType = ''; $body = ''; $fetched = false;
        return $this->login($client, 'fake-user', 'fake-pass', $url, $method, $contentType, $body, $fetched);
    }
}
class YggConfigTransport
{
    public $requests = array();
    public $status = 200;
    public $referer = '';
    public function fetch($url, $method = 'GET', $contentType = '', $body = '')
    {
        $this->requests[] = array($url, $method, $contentType, $body);
        return true;
    }
    public function setcookies() {}
}

class YggConfigSearch extends YggTorrentEngine
{
    public $requests = array();
    public $response = false;
    public $failureAfter = null;
    public function fetch($url, $encode = 1, $method = 'GET', $content_type = '', $body = '')
    {
        $this->requests[] = $url;
        if ($this->failureAfter !== null && count($this->requests) > $this->failureAfter) {
            return false;
        }
        return $this->response === false ? false : (object) array('results' => $this->response);
    }
}

$tests = array(
    // This probes tracked conf/config.php, not an operator's local override.
    'the shipped Ygg default is unconfigured' => function () { yggConfigProbe('default'); },
    'the global persisted config survives plugin defaults' => function () { yggConfigProbe('global'); },
    'invalid Ygg origin stays visible when application logging is disabled' => function () {
        global $yggTorrentOrigin, $log_file;
        $yggTorrentOrigin = '';
        $previousLog = $log_file;
        $log_file = '';
        try {
            $manager = yggManager();
            testAssertSame(true, strpos($manager->get(), 'configurationRequired: true') !== false,
                'settings show disabled account without a log');
            $engine = new YggConfigSearch();
            $results = array();
            $engine->action('query', 'Tout', $results, 10, false);
            testAssertSame(true, strpos($results['']['name'], '$yggTorrentOrigin') !== false,
                'search shows required setting without a log');
        } finally {
            $log_file = $previousLog;
        }
    },
    'an invalid Ygg origin is visible in account metadata and sends no extsearch request' => function () {
        global $yggTorrentOrigin;
        $yggTorrentOrigin = '';
        $manager = yggManager();
        testAssertSame(true, $manager->getInfo()[0]['configurationRequired'], 'settings API identifies disabled origin');
        testAssertSame(true, strpos($manager->get(), 'configurationRequired: true') !== false, 'settings bootstrap identifies disabled origin');
        $engine = new YggConfigSearch();
        $results = array();
        $engine->action('query', 'Tout', $results, 10, false);
        testAssertSame(array(), $engine->requests, 'unconfigured engine makes no search request');
        testAssertSame(true, strpos($results['']['name'], '$yggTorrentOrigin') !== false, 'search reports the required setting');
    },
    'Ygg extsearch survives a stale request after loginmgr files are removed' => function () {
        $root = sys_get_temp_dir() . '/ygg-missing-loginmgr-' . uniqid();
        mkdir($root . '/plugins/extsearch/engines', 0700, true);
        try {
            copy(__DIR__ . '/../../../plugins/extsearch/engines/YggTorrent.php',
                $root . '/plugins/extsearch/engines/YggTorrent.php');
            $script = <<<'CHILD'
<?php
class commonEngine {
    public function getNewEntry() { return array('name' => ''); }
    public function getTorrent($url) { return 'unexpected-download'; }
}
class rTorrentSettings {
    public static function get() { return new self(); }
    public function isPluginRegistered($name) { return true; }
}
require getcwd() . '/plugins/extsearch/engines/YggTorrent.php';
$engine = new YggTorrentEngine();
$results = array();
$engine->action('query', 'Tout', $results, 10, false);
echo json_encode(array($engine->getTorrent('https://tracker.example/engine/download_torrent?id=1'), $results['']['name']));
CHILD;
            file_put_contents($root . '/probe.php', $script);
            $answer = json_decode(yggChild(array($root . '/probe.php'), $root), true);
            testAssertSame(false, $answer[0], 'stale download is refused without loginmgr');
            testAssertSame(true, strpos($answer[1], 'loginmgr') !== false,
                'stale search explains missing loginmgr');
        } finally { yggRemoveTree($root); }
    },
    'Ygg extsearch ignores cached requests while loginmgr is disabled' => function () {
        global $yggTorrentOrigin;
        $yggTorrentOrigin = 'https://trusted.example';
        rTorrentSettings::get()->unregisterPlugin('loginmgr');
        try {
            $engine = new YggConfigSearch();
            $results = array();
            $engine->action('query', 'Tout', $results, 10, false);
            testAssertSame(array(), $engine->requests, 'disabled loginmgr causes no tracker request');
            testAssertSame(true, strpos($results['']['name'], 'loginmgr') !== false,
                'stale search explains unavailable dependency');
            testAssertSame(false, $engine->getTorrent('https://trusted.example/engine/download_torrent?id=1'),
                'stale download is refused');
        } finally {
            rTorrentSettings::get()->registerPlugin('loginmgr');
        }
    },
    'Ygg extsearch builds download links only from the configured login origin' => function () {
        global $yggTorrentOrigin;
        $yggTorrentOrigin = 'https://trusted.example:8443';
        $manager = yggManager();
        testAssertSame(false, $manager->getInfo()[0]['configurationRequired'], 'configured account is available');
        $engine = new YggConfigSearch();
        $engine->response = '>1 résultats trouvés<td><div class="hidden"><a id="torrent_name" href="https://foreign.test/torrent/film/action/123">Title</td>'
            . '<a target="123" href="#"><div class="hidden">2h</div><td>1GB</td><td>10</td><td>5</td><td>1</td>';
        $results = array();
        $engine->action('query', 'Tout', $results, 10, false);
        testAssertSame('https://trusted.example:8443/engine/search/?name=query&do=search&attempt=1',
            $engine->requests[0], 'search uses configured origin');
        testAssertSame(true, isset($results['https://trusted.example:8443/engine/download_torrent?id=123']),
            'download link uses configured origin, never the HTML href host');
        testAssertSame(false, $engine->getTorrent('https://foreign.test/engine/download_torrent?id=123'),
            'stale or foreign download link is refused before a request');
        testAssertSame(1, count($engine->requests), 'foreign download attempted no fetch');
    },
    'Ygg download selection uses a parsed nonempty id parameter' => function () {
        global $yggTorrentOrigin;
        $yggTorrentOrigin = 'https://trusted.example';
        $account = new YggTorrentAccount();
        foreach (array('?id=', '?id[]=1', '?id=9&id=', '?ref=1') as $query) {
            testAssertSame(false, $account->test('https://trusted.example/engine/download_torrent' . $query),
                'invalid final id must not select account: ' . $query);
        }
        testAssertSame(true, $account->test('https://trusted.example/engine/download_torrent?i%64=abc'),
            'encoded parameter name is parsed as id; Ygg id need not be numeric');
    },
    'a later Ygg search page outage keeps earlier results without a PHP warning' => function () {
        global $yggTorrentOrigin;
        $yggTorrentOrigin = 'https://trusted.example';
        $engine = new YggConfigSearch();
        $engine->response = '>51 résultats trouvés<td><div class="hidden"><a id="torrent_name" href="https://trusted.example/torrent/film/action/123">Title</td>'
            . '<a target="123" href="#"><div class="hidden">2h</div><td>1GB</td><td>10</td><td>5</td><td>1</td>';
        $engine->failureAfter = 1;
        $results = array();
        set_error_handler(function ($severity, $message) { throw new ErrorException($message, 0, $severity); });
        try {
            $engine->action('query', 'Tout', $results, 10, false);
        } finally {
            restore_error_handler();
        }
        testAssertSame(2, count($engine->requests), 'second page was requested');
        testAssertSame(true, isset($results['https://trusted.example/engine/download_torrent?id=123']),
            'first page remains usable');
    },
    'a plugin local config can override the global origin' => function () { yggConfigProbe('local'); },
    'an enabled per-user config overrides global and local origins' => function () { yggConfigProbe('user'); },
    'an edited copy of the plugin template applies as a local override' => function () { yggConfigProbe('template-local'); },
    'an edited copy of the plugin template applies as a per-user override' => function () { yggConfigProbe('template-user'); },
    'only Ygg-shaped downloads and explicit refresh diagnose missing configuration once' => function () {
        global $yggTorrentOrigin, $log_file;
        $yggTorrentOrigin = '';
        $previousLogFile = $log_file;
        $log_file = tempnam(sys_get_temp_dir(), 'ygg-log-');
        $warningFlag = new ReflectionProperty(YggTorrentAccount::class, 'configurationWarningShown');
        if (PHP_VERSION_ID < 80100) { $warningFlag->setAccessible(true); }
        $previousWarning = $warningFlag->getValue();
        $warningFlag->setValue(null, false);
        $previousErrorLog = ini_set('error_log', $log_file);
        try {
            $manager = new accountManager();
            $manager->accounts = array('YggTorrent' => array('path' => __DIR__ . '/../../../plugins/loginmgr/accounts/YggTorrent.php',
                'object' => 'YggTorrentAccount', 'enabled' => 0));
            $manager->getInfo();
            testAssertSame('', file_get_contents($log_file), 'disabled account listing is quiet');
            $manager->accounts['YggTorrent']['enabled'] = 1;
            foreach (array('https://api.rutracker.cc/api', 'https://feed.example/rss', 'https://tracker.example/engine/download_torrent?ref=1') as $url) {
                testAssertSame(false, $manager->getAccount($url), 'unrelated URL has no Ygg account');
            }
            testAssertSame('', file_get_contents($log_file), 'unrelated requests do not diagnose Ygg');
            // The application log must receive the diagnostic even when PHP's error
            // stream is elsewhere (the CLI and web entrypoints share FileUtil).
            ini_set('error_log', sys_get_temp_dir() . '/ygg-unused-error-' . getmypid());
            testAssertSame(false, $manager->getAccount('https://tracker.example/engine/download_torrent?id=1'), 'unconfigured download refuses');
            $diagnostic = file_get_contents($log_file);
            foreach (array('invalid-or-missing-origin', '$yggTorrentOrigin', 'conf/config.php', 'automatic login are disabled') as $part) {
                testAssertSame(true, strpos($diagnostic, $part) !== false, 'application diagnostic names ' . $part);
            }
            testAssertSame(1, substr_count($diagnostic, "\n"), 'one bounded diagnostic');
            (new YggTorrentAccount())->check(new YggConfigTransport(), 'fake-user', 'fake-pass', 0);
            testAssertSame($diagnostic, file_get_contents($log_file), 'refresh does not repeat configuration warning');
        } finally {
            ini_set('error_log', $previousErrorLog);
            unlink($log_file);
            @unlink(sys_get_temp_dir() . '/ygg-unused-error-' . getmypid());
            $warningFlag->setValue(null, $previousWarning);
            $log_file = $previousLogFile;
        }
    },
    'an explicit refresh alone emits the missing-origin application diagnostic' => function () {
        $log = tempnam(sys_get_temp_dir(), 'ygg-refresh-');
        try {
            $script = 'require ' . var_export(__DIR__ . '/../../../plugins/loginmgr/accounts.php', true) . ';'
                . 'require ' . var_export(__DIR__ . '/../../../plugins/loginmgr/accounts/YggTorrent.php', true) . ';'
                . '$log_file = ' . var_export($log, true) . '; $yggTorrentOrigin = "";'
                . '(new YggTorrentAccount())->check(null, "fake-user", "fake-pass", 0);';
            yggChild(array('-r', $script));
            $diagnostic = file_get_contents($log);
            testAssertSame(true, strpos($diagnostic, 'invalid-or-missing-origin') !== false, 'refresh diagnoses invalid configuration before using the client');
            testAssertSame(1, substr_count($diagnostic, "\n"), 'refresh emits one application diagnostic');
        } finally { unlink($log); }
    },
    'invalid origins stay disabled before any credential request' => function () {
        global $yggTorrentOrigin;
        foreach (array('', 'http://ygg.example', 'https://ygg example', "https://ygg.example\n", "https://ygg.ex\tample",
                       'https://ygg_example', 'https://-ygg.example', 'https://ygg..example', 'https://ygg.example..',
                       'https://ygg.example:0', 'https://ygg.example:65536', 'https://ygg.example:443x', 'https://ygg.example?', 'https://ygg.example#',
                       'https://user:pass@ygg.example', 'https://ygg.example/path', 'https://ygg.example\\bad', 'https://[broken]') as $origin) {
            $yggTorrentOrigin = $origin;
            $account = new YggConfigLogin();
            testAssertSame('', $account->url, 'invalid origin is disabled: ' . var_export($origin, true));
            if ($origin === 'https://ygg.example:0') {
                testAssertSame('bad-port', $account->configurationError(), 'zero port has a precise reason');
            }
            if ($origin === 'https://ygg.example:65536' || $origin === 'https://ygg.example:443x') {
                testAssertSame('invalid-url', $account->configurationError(), 'malformed port is rejected before trust');
            }
            $client = new YggConfigTransport();
            testAssertSame(false, $account->directLogin($client, 'https://ygg.example'), 'invalid origin refuses login');
            testAssertSame(array(), $client->requests, 'no credential request');
        }
    },
    'valid normalized origins retain explicit ports in credential POSTs' => function () {
        global $yggTorrentOrigin;
        foreach (array('https://YGG.EXAMPLE.:8443/' => 'https://ygg.example:8443',
                       'https://127.0.0.1:443' => 'https://127.0.0.1:443',
                       'https://[::1]:8443/' => 'https://[::1]:8443') as $origin => $normalized) {
            $yggTorrentOrigin = $origin;
            $account = new YggConfigLogin();
            $client = new YggConfigTransport();
            testAssertSame(true, $account->directLogin($client, $normalized), 'valid origin login');
            testAssertSame(array($normalized . '/user/login', 'POST', 'application/x-www-form-urlencoded', 'id=fake-user&pass=fake-pass&submit='),
                $client->requests[1], 'credential POST retains the configured origin');
        }
    },
    'only a successful landing response permits the Ygg credential POST' => function () {
        global $yggTorrentOrigin;
        $yggTorrentOrigin = 'https://ygg.example';
        foreach (array(199 => false, 200 => true, 299 => true, 300 => false, 403 => false, 503 => false) as $status => $expected) {
            $client = new YggConfigTransport();
            $client->status = $status;
            testAssertSame($expected, (new YggConfigLogin())->directLogin($client, 'https://ygg.example'), 'landing status ' . $status);
            testAssertSame($expected ? 2 : 1, count($client->requests), 'credential request count for status ' . $status);
        }
    },
);
$status = testRunCases($tests);
@unlink($_ENV['RU_LOG_FILE']);
exit($status);
