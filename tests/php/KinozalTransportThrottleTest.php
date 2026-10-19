<?php

// Anonymous clients must observe the installation's Kinozal pause without
// loading a checker or account. Only the external curl binary is scripted.
require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/ScriptedCurlFixture.php');

$transportRoot = sys_get_temp_dir() . '/kinozal-transport-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($transportRoot, 0700);
mkdir($transportRoot . '/profile', 0700);
$_ENV['RU_PROFILE_PATH'] = $transportRoot . '/profile';
$_ENV['RU_LOG_FILE'] = $transportRoot . '/application.log';
define('KINOZAL_REQUEST_PAUSE', 0.1);
require_once(__DIR__ . '/../../php/Snoopy.class.inc');
$httpBlockPrivateNetworks = false;
rTorrentSettings::get()->unregisterPlugin('loginmgr');
rTorrentSettings::get()->unregisterPlugin('cookies');

function transportClearProcessState()
{
    foreach (array('throttleGroups', 'throttleStates', 'throttleLogged') as $name) {
        $property = new ReflectionProperty('Snoopy', $name);
        if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
        $property->setValue(null, array());
    }
}

function transportStatePath()
{
    return FileUtil::getSettingsPathEx('') . '/throttle-kinozal.json';
}

function transportStoreState($until, $next = 0.0)
{
    $path = transportStatePath();
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    $bytes = json_encode(array('next' => $next, 'ended' => 0.0, 'until' => $until, 'strikes' => 1));
    testAssertSame(strlen($bytes), file_put_contents($path, $bytes), 'The shared clock is seeded');
    return $bytes;
}

function transportReset()
{
    global $transportFixture;
    transportClearProcessState();
    @unlink(transportStatePath());
    $transportFixture->plan(array());
    testAssertSame(false, class_exists('accountManager', false), 'No account manager is loaded');
    testAssertSame(false, class_exists('ruTrackerChecker', false), 'No checker is loaded');
}

function transportClient($budget = 5.0)
{
    $client = new Snoopy();
    // A deadline sends HTTP through the same scripted curl path as HTTPS;
    // neither DNS nor a real network transport is used by this suite.
    $client->setRequestDeadline($budget);
    return $client;
}

$tests = array(
    'an anonymous fetchComplex observes a pause before any account or checker runs' => function () {
        global $transportFixture;
        $url = 'https://kinozal.jumpingcrab.com/rss.xml';
        transportStoreState(microtime(true) + 600);
        $transportFixture->plan(array(ScriptedCurlFixture::reply('GET', $url, 200, '<rss/>')));
        $client = transportClient();
        testAssertSame(false, $client->fetchComplex($url), 'The anonymous feed fetch is refused');
        testAssertSame(Snoopy::REQUEST_THROTTLED, $client->error, 'The refusal names the shared pause');
        testAssertSame(array(), $transportFixture->requests(), 'The feed request never reaches the wire');
        testAssertSame(false, class_exists('accountManager', false), 'Throttling does not load loginmgr');
    },
    'direct HTTP and HTTPS clients observe the same pause on every Kinozal host' => function () {
        global $transportFixture;
        transportStoreState(microtime(true) + 600);
        foreach (array('http://kinozal.jumpingcrab.com/favicon.ico', 'https://kinozal.cloudns.nz/download.php?id=1',
            'https://kinozal.tv/', 'https://kinozal.me/', 'https://DL.KINOZAL.GURU./download.php?id=1') as $url) {
            $transportFixture->plan(array(ScriptedCurlFixture::reply('GET', $url, 200, 'body')));
            $client = transportClient();
            testAssertSame(false, $client->fetch($url), 'A direct request is refused: ' . $url);
            testAssertSame(Snoopy::REQUEST_THROTTLED, $client->error, 'The direct request sees the shared pause');
            testAssertSame(array(), $transportFixture->requests(), 'No direct request reaches the wire');
        }
    },
    'an unrelated source cannot redirect around the paused Kinozal transport' => function () {
        global $transportFixture;
        $source = 'https://feeds.example.test/redirect';
        $target = 'https://kinozal.cloudns.nz/rss.xml';
        transportStoreState(microtime(true) + 600);
        $transportFixture->plan(array(
            ScriptedCurlFixture::reply('GET', $source, 302, '', array('Location: ' . $target)),
            ScriptedCurlFixture::reply('GET', $target, 200, '<rss/>'),
        ));
        $client = transportClient();
        testAssertSame(false, $client->fetchComplex($source), 'The redirected target is refused');
        testAssertSame(Snoopy::REQUEST_THROTTLED, $client->error, 'The redirect sees the shared pause');
        $requests = $transportFixture->requests();
        testAssertSame(array($source), array_column($requests, 'url'), 'Only the unrelated source is fetched');
        testAssertSame('', $requests[0]['cookie'], 'The source request is anonymous');
    },
    'unrelated hosts and mirror lookalikes do not consume the Kinozal clock' => function () {
        global $transportFixture;
        $state = transportStoreState(microtime(true) + 600);
        $urls = array('https://feeds.jumpingcrab.com/rss.xml', 'https://kinozal.jumpingcrab.com.evil.test/rss.xml',
            'https://example.test/kinozal.guru/rss.xml', 'https://kinozal.guru@elsewhere.test/rss.xml');
        $transportFixture->plan(array_map(function ($url) {
            return ScriptedCurlFixture::reply('GET', $url, 200, '<rss/>');
        }, $urls));
        foreach ($urls as $url)
            testAssertSame(true, transportClient()->fetchComplex($url), 'The unrelated host is fetched: ' . $url);
        testAssertSame($urls, array_column($transportFixture->requests(), 'url'), 'Every unrelated host is fetched once');
        testAssertSame($state, file_get_contents(transportStatePath()), 'Other hosts leave the Kinozal clock untouched');
    },
    'an anonymous request cannot wait past its deadline for a Kinozal turn' => function () {
        global $transportFixture;
        $url = 'https://kinozal.jumpingcrab.com/download.php?id=1';
        transportStoreState(0.0, microtime(true) + 5);
        $transportFixture->plan(array(ScriptedCurlFixture::reply('GET', $url, 200, 'body')));
        $client = transportClient(0.1);
        testAssertSame(false, $client->fetchComplex($url), 'The reserved turn is outside the request budget');
        testAssertSame('request-deadline', $client->error, 'The original deadline policy decides the refusal');
        testAssertSame(array(), $transportFixture->requests(), 'No expired request reaches the wire');
    },
    'an anonymous 429 persists a pause that another fresh client observes' => function () {
        global $transportFixture;
        $source = 'https://kinozal.jumpingcrab.com/rss.xml';
        $target = 'https://kinozal.cloudns.nz/download.php?id=1';
        // This tests HTTP status handling, not a third-party response schema.
        $transportFixture->plan(array(ScriptedCurlFixture::reply('GET', $source, 429, 'rate limited')));
        $client = transportClient();
        $client->fetchComplex($source);
        testAssertSame('429', $client->status, 'The anonymous request receives the HTTP 429');
        testAssertTrue(is_file(transportStatePath()), 'The anonymous refusal creates the shared pause');
        transportClearProcessState();
        $client = transportClient();
        testAssertSame(false, $client->fetch($target), 'The fresh client reads the persisted pause');
        testAssertSame(Snoopy::REQUEST_THROTTLED, $client->error, 'The second mirror is paused too');
        testAssertSame(array($source), array_column($transportFixture->requests(), 'url'), 'Only the first request was sent');
    },
);

try {
    $transportFixture = new ScriptedCurlFixture($transportRoot);
    $transportFixture->install();
    $status = testRunCases($tests, 'transportReset');
} finally {
    if (isset($transportFixture)) $transportFixture->restore();
    FileUtil::deleteDirectory($transportRoot);
}
exit($status);
