<?php

require_once(__DIR__ . '/../../php/TestCase.php');

$legacyNoticeRoot = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/loginmgr-notice-'
    . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($legacyNoticeRoot, 0700, true);
$_ENV['RU_PROFILE_PATH'] = $legacyNoticeRoot . '/parent';
$_ENV['RU_LOG_FILE'] = $legacyNoticeRoot . '/parent.log';
require_once(__DIR__ . '/../../../plugins/loginmgr/accounts.php');

function legacyNoticeSession($root, $owner = 'KinozalTV', $cookie = 'private-session', $loaded = false)
{
    if (!is_dir($root . '/settings/accounts')) mkdir($root . '/settings/accounts', 0700, true);
    $session = new privateData($owner);
    $session->cookies = array('sid' => $cookie);
    $session->loaded = $loaded;
    unset($session->scopedCookies);
    $path = $root . '/settings/accounts/' . $owner . '.dat';
    $bytes = serialize($session);
    testAssertTrue(file_put_contents($path, $bytes) !== false, 'legacy session fixture saved');
    return array($path, $bytes);
}

function legacyNoticeStart($root, $owner = 'KinozalTV', $action = 'load')
{
    $script = <<<'PHP'
$_ENV['RU_PROFILE_PATH'] = $argv[1];
$_ENV['RU_LOG_FILE'] = $argv[1] . '/app.log';
require $argv[3];
fgets(STDIN);
if ($argv[4] === 'expire') {
    $cache = new rCache('/accounts');
    $notices = new legacyCookieNotices();
    if (!$cache->get($notices)) exit(91);
    foreach ($notices->notices as &$notice) $notice = time() - legacyCookieNotices::INTERVAL - 1;
    unset($notice);
    if (!$cache->set($notices)) exit(92);
}
if ($argv[4] === 'fetch') {
    class LegacyNoticeFlowClient {
        public $cookies = array();
        public $referer = '';
        public $status = 200;
        public $results = 'readable public page';
        public function fetch($url, $method = 'GET', $contentType = '', $body = '') { return true; }
    }
    class LegacyNoticeFlowAccount extends commonAccount {
        public $owner;
        public $logins = 0;
        public function getName() { return $this->owner; }
        protected function isOK($client) { return true; }
        protected function login($client, $login, $password, &$url, &$method, &$contentType, &$body, &$fetched) {
            $this->logins++;
            $client->cookies = array('sid' => 'new-private-session');
            return true;
        }
    }
    $account = new LegacyNoticeFlowAccount();
    $account->owner = $argv[2];
    echo json_encode(array($account->fetch(new LegacyNoticeFlowClient(), 'https://fixture.invalid',
        'fixture-user', 'fixture-password', 'GET', '', ''), $account->logins));
    exit;
}
$client = new Snoopy();
$session = privateData::load($argv[2], $client);
echo json_encode(array($session->loaded, $client->cookies));
PHP;
    $process = proc_open(array(PHP_BINARY, '-c', __DIR__ . '/../../php-test.ini', '-r',
        $script, $root, $owner, realpath(__DIR__ . '/../../../plugins/loginmgr/accounts.php'), $action),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    testAssertTrue(is_resource($process), 'independent request process started');
    return array($process, $pipes);
}

function legacyNoticeRelease(&$child)
{
    fwrite($child[1][0], "go\n");
    fclose($child[1][0]);
}

function legacyNoticeFinish($child, $expected = null)
{
    $stdout = stream_get_contents($child[1][1]); fclose($child[1][1]);
    $stderr = stream_get_contents($child[1][2]); fclose($child[1][2]);
    testAssertSame(0, proc_close($child[0]), 'independent request completed: ' . $stdout . $stderr);
    testAssertSame('', $stderr, 'request has no PHP diagnostics');
    testAssertSame($expected === null ? array(false, array()) : $expected, json_decode($stdout, true),
        'a refused legacy cache cannot act as an authenticated session');
}

function legacyNoticeRequest($root, $owner = 'KinozalTV', $action = 'load')
{
    $child = legacyNoticeStart($root, $owner, $action);
    legacyNoticeRelease($child);
    legacyNoticeFinish($child);
}

function legacyNoticeLog($root)
{
    return is_file($root . '/app.log') ? file_get_contents($root . '/app.log') : '';
}

$tests = array(
    'a persisted loaded flag cannot make a refused legacy session warm' => function () use ($legacyNoticeRoot) {
        $root = $legacyNoticeRoot . '/persisted-loaded';
        list($path, $bytes) = legacyNoticeSession($root, 'KinozalTV', 'private-session', true);
        legacyNoticeRequest($root);
        testAssertSame($bytes, file_get_contents($path), 'refusal preserves the original legacy bytes');
    },
    'the public fetch path renews a legacy cache even when its persisted loaded flag is true' => function () use ($legacyNoticeRoot) {
        $root = $legacyNoticeRoot . '/fetch-loaded';
        legacyNoticeSession($root, 'KinozalTV', 'private-session', true);
        $child = legacyNoticeStart($root, 'KinozalTV', 'fetch');
        legacyNoticeRelease($child);
        legacyNoticeFinish($child, array(true, 1));
    },
    'separate requests explain one legacy session once and preserve its bytes' => function () use ($legacyNoticeRoot) {
        $root = $legacyNoticeRoot . '/sequential';
        list($path, $bytes) = legacyNoticeSession($root);
        legacyNoticeRequest($root);
        legacyNoticeRequest($root);
        testAssertSame(1, substr_count(legacyNoticeLog($root), 'legacy-cookie-cache-needs-refresh: KinozalTV'),
            'separate PHP requests share the warning throttle');
        testAssertSame($bytes, file_get_contents($path), 'warning never rewrites legacy session data');
        testAssertSame(false, strpos(legacyNoticeLog($root), 'private-session') !== false,
            'warning never logs a cookie value');
    },
    'concurrent requests claim one legacy session explanation' => function () use ($legacyNoticeRoot) {
        $root = $legacyNoticeRoot . '/concurrent';
        legacyNoticeSession($root);
        $children = array();
        for ($i = 0; $i < 4; $i++) $children[] = legacyNoticeStart($root);
        foreach ($children as &$child) legacyNoticeRelease($child);
        unset($child);
        foreach ($children as $child) legacyNoticeFinish($child);
        testAssertSame(1, substr_count(legacyNoticeLog($root), 'legacy-cookie-cache-needs-refresh: KinozalTV'),
            'racing PHP requests leave one explanation');
    },
    'changed sessions and different accounts or profiles keep their own guidance' => function () use ($legacyNoticeRoot) {
        $root = $legacyNoticeRoot . '/generation';
        legacyNoticeSession($root);
        legacyNoticeRequest($root);
        legacyNoticeSession($root, 'KinozalTV', 'changed-private-session');
        legacyNoticeRequest($root);
        legacyNoticeSession($root, 'RUTracker');
        legacyNoticeRequest($root, 'RUTracker');
        testAssertSame(2, substr_count(legacyNoticeLog($root), 'legacy-cookie-cache-needs-refresh: KinozalTV'),
            'a different legacy session is explained immediately');
        testAssertSame(1, substr_count(legacyNoticeLog($root), 'legacy-cookie-cache-needs-refresh: RUTracker'),
            'another account is not silenced');
        $otherRoot = $legacyNoticeRoot . '/other-profile';
        legacyNoticeSession($otherRoot);
        legacyNoticeRequest($otherRoot);
        testAssertSame(1, substr_count(legacyNoticeLog($otherRoot), 'legacy-cookie-cache-needs-refresh: KinozalTV'),
            'another profile is not silenced');
    },
    'an unresolved legacy session is explained again after the notice expires' => function () use ($legacyNoticeRoot) {
        $root = $legacyNoticeRoot . '/expiry';
        legacyNoticeSession($root);
        legacyNoticeRequest($root);
        legacyNoticeRequest($root, 'KinozalTV', 'expire');
        testAssertSame(2, substr_count(legacyNoticeLog($root), 'legacy-cookie-cache-needs-refresh: KinozalTV'),
            'persistent deduplication cannot hide repair guidance forever');
        $noticeBytes = file_get_contents($root . '/settings/accounts/legacy-cookie-notices.dat');
        testAssertSame(false, strpos($noticeBytes, 'private-session') !== false,
            'the notice cache contains no cookie value');
    },
    'an older session request cannot make an already explained session noisy again' => function () use ($legacyNoticeRoot) {
        $root = $legacyNoticeRoot . '/overlap';
        list($path, $firstBytes) = legacyNoticeSession($root);
        legacyNoticeRequest($root);
        legacyNoticeSession($root, 'KinozalTV', 'second-private-session');
        legacyNoticeRequest($root);
        file_put_contents($path, $firstBytes);
        legacyNoticeRequest($root);
        testAssertSame(2, substr_count(legacyNoticeLog($root), 'legacy-cookie-cache-needs-refresh: KinozalTV'),
            'overlapping legacy generations each retain their own warning interval');
    },
    'a delayed cache claim cannot replace a newer notice from the next second' => function () {
        $cache = new rCache('/accounts');
        $older = new legacyCookieNotices();
        $cache->get($older);
        $key = hash('sha256', 'rollover-account' . "\0" . hash('sha256', 'rollover-session'));
        $newerAt = time();
        $older->notices[$key] = $newerAt - 1;
        foreach (array('claimKey' => $key, 'claimed' => true) as $name => $value) {
            $property = new ReflectionProperty(legacyCookieNotices::class, $name);
            if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
            $property->setValue($older, $value);
        }
        $newer = new legacyCookieNotices();
        $newer->notices[$key] = $newerAt;
        testAssertSame(true, $cache->set($newer), 'next-second winner published');
        testAssertSame(true, $cache->set($older), 'older contender reconciled under the real cache lock');
        $claimed = new ReflectionProperty(legacyCookieNotices::class, 'claimed');
        if (PHP_VERSION_ID < 80100) $claimed->setAccessible(true);
        testAssertSame(false, $claimed->getValue($older), 'older contender does not own another explanation');
        $saved = new legacyCookieNotices();
        testAssertSame(true, $cache->get($saved), 'winning notice remains readable');
        testAssertSame($newerAt, $saved->notices[$key], 'older contender preserves the winner timestamp');
    },
    'a refused notice write stays visible and preserves the legacy session' => function () use ($legacyNoticeRoot) {
        $root = $legacyNoticeRoot . '/refused-write';
        list($path, $bytes) = legacyNoticeSession($root);
        mkdir($root . '/settings/accounts/legacy-cookie-notices.dat.lock', 0700);
        legacyNoticeRequest($root);
        $log = legacyNoticeLog($root);
        testAssertSame(true, strpos($log, 'legacy-cookie-notice-cache-unavailable: KinozalTV') !== false,
            'a failed warning cache cannot silently suppress legacy-session repair guidance');
        testAssertSame(true, strpos($log, 'Settings -> Accounts -> Save') !== false,
            'the fallback retains the repair action');
        testAssertSame($bytes, file_get_contents($path), 'failed notice write never rewrites session data');
    },
);

try {
    $status = testRunCases($tests);
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($legacyNoticeRoot,
        FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($legacyNoticeRoot);
}
exit($status);
