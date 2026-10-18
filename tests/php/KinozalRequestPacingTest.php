<?php

// Every request to a Kinozal host waits its turn on one clock -- whichever
// client sends it, from whichever process -- and an HTTP 429 pauses them all
// (KinozalResponse::throttle() registers the hosts with Snoopy::throttleHosts()).
// Only curl's wire answers are scripted (ScriptedCurlFixture): account
// selection, the stored session, login, redirects, the checker's client
// factory and the Kinozal handler run for real, and the clock's state file
// lives in this run's private profile.
//
// The file has three roles. Without arguments it runs the cases. With
// --shipped-default it is a child that checks the unmodified two-second
// interval in a profile of its own; the cases start it first and collect it
// last, so its real wait overlaps them. With --request it is one checker
// request from a separate process on the parent's profile and stand-in.

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/ScriptedCurlFixture.php');

// Starts this file in another role. The command is a string, so /bin/sh runs
// it; exec leaves no shell between the test and the child.
function pacingStart($arguments)
{
    $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' -c ' . escapeshellarg(__DIR__ . '/../php-test.ini')
        . ' ' . escapeshellarg(__FILE__);
    foreach ($arguments as $argument) $command .= ' ' . escapeshellarg($argument);
    $pipes = array();
    $process = proc_open($command . ' 2>&1', array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w')),
        $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start ' . implode(' ', $arguments));
    return array($process, $pipes[1]);
}

// Waits for a child and returns what it printed. It must exit 0 and print
// nothing the suite itself counts as a failure.
function pacingFinish($child, $what)
{
    list($process, $output) = $child;
    $printed = (string) stream_get_contents($output);
    fclose($output);
    testAssertSame(0, proc_close($process), $what . ' exits cleanly: ' . $printed);
    testAssertSame(0, preg_match(pacingFailurePattern(), $printed),
        $what . ' prints nothing the suite counts as a failure: ' . $printed);
    return $printed;
}

// The pattern php-test.sh and tools/matrix.sh judge a test file's output by,
// read from the file they both source.
function pacingFailurePattern()
{
    $source = (string) file_get_contents(__DIR__ . '/../php-failure-pattern.sh');
    if (!preg_match("/^PHP_FAILURE_PATTERN='([^']+)'$/m", $source, $match))
        throw new RuntimeException('tests/php-failure-pattern.sh no longer assigns PHP_FAILURE_PATTERN'
            . ' in single quotes');
    return '~' . str_replace('~', '\\~', $match[1]) . '~m';
}

// Every case starts on a fresh clock: no group registered in this process, no
// state in it or in the profile, no log, a new checker cycle on the default
// doors, an empty plan, and the one stored session the cases start from.
// Otherwise an earlier case's slot or pause could decide a later case.
function pacingReset()
{
    global $pacingFixture, $pacingRoot;
    foreach (array('throttleGroups', 'throttleStates', 'throttleLogged') as $name) {
        $property = new ReflectionProperty('Snoopy', $name);
        if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
        $property->setValue(null, array());
    }
    foreach (glob(FileUtil::getSettingsPathEx('') . '/throttle-*.json') ?: array() as $state)
        testAssertTrue(unlink($state), 'The previous case\'s clock is removed');
    @unlink(pacingLoginStateFile());
    @unlink($pacingRoot . '/application.log');
    unset($GLOBALS['kinozalHosts']);
    KinozalCheckImpl::resetCycle();
    $pacingFixture->plan(array());
    (new privateData('KinozalTV'))->remove();
    $client = new Snoopy();
    $client->withAccountCookieScope('KinozalTV', function () use ($client) {
        $client->loadAccountResponseCookies(array(array('name' => 'sid', 'value' => 'stored-session',
            'domain' => 'kinozal.jumpingcrab.com', 'path' => '/', 'hostOnly' => true,
            'secure' => true, 'expires' => null)));
        testAssertTrue((new privateData('KinozalTV'))->store($client), 'The stored session is seeded');
    });
}

// The login pause's state, shared by every process on the profile.
function pacingLoginStateFile()
{
    return FileUtil::getSettingsPathEx('') . '/kinozal-login.json';
}

function pacingLoginState()
{
    return json_decode((string) @file_get_contents(pacingLoginStateFile()), true);
}

function pacingStoreLoginState($state)
{
    testAssertTrue(file_put_contents(pacingLoginStateFile(), json_encode($state)) !== false,
        'The login pause state is seeded');
}

// Forgets the request pause a 429 left, as its expiry would: the login pause
// must hold on its own, whatever answer the next GET brings.
function pacingExpireRequestPause()
{
    foreach (array('throttleStates') as $name) {
        $property = new ReflectionProperty('Snoopy', $name);
        if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
        $property->setValue(null, array());
    }
    foreach (glob(FileUtil::getSettingsPathEx('') . '/throttle-*.json') ?: array() as $state)
        testAssertTrue(unlink($state), 'The request pause is expired');
}

// The one line the account wrote about $host's login, and its time.
function pacingLoginLine($host, $pattern)
{
    global $pacingRoot;
    $log = (string) @file_get_contents($pacingRoot . '/application.log');
    testAssertSame(1, preg_match_all('/kinozal: login on ' . preg_quote($host, '/') . ' ' . $pattern . '/', $log,
        $lines), 'Exactly one such login line: ' . $log);
    return $lines;
}

function pacingChain($requests)
{
    return array_map(function ($request) { return array($request['method'], $request['url']); }, $requests);
}

// The requests the stand-in received, once they are exactly $expected (method
// and URL, in order) and every scripted answer was asked for.
function pacingWire($expected)
{
    global $pacingFixture;
    $requests = $pacingFixture->requests();
    testAssertSame($expected, pacingChain($requests), 'Exactly these wire requests were made, in this order');
    testAssertSame(array(), $pacingFixture->remaining(), 'Every scripted answer was asked for');
    return $requests;
}

// Each request started at least $minimum after the one before it. Snoopy's
// clock is microtime(), the stand-in records hrtime(): the millisecond off the
// default covers the two clocks, not a shorter wait.
function pacingAssertSpaced($requests, $minimum = KINOZAL_REQUEST_PAUSE - 0.001)
{
    for ($i = 1; $i < count($requests); $i++) {
        $gap = $requests[$i]['time'] - $requests[$i - 1]['time'];
        testAssertTrue($gap >= $minimum, sprintf('Wire request %d of %d (%s %s) started %.3f s after the one'
            . ' before it, not %.3f s', $i + 1, count($requests), $requests[$i]['method'], $requests[$i]['url'],
            $gap, $minimum));
    }
}

// Captured 2026-10-14 17:30:58 UTC from https://kinozal.jumpingcrab.com/login.php
// with the checker's user agent: HTTP/1.1 429, no Retry-After, and a 1984-byte
// nginx page whose lines end in CRLF and LF alike, hence base64. The stand-in
// writes its own reason phrase on the status line; every header after it is
// the captured one.
function pacingLive429Reply($url)
{
    $body = base64_decode(''
        . 'PGh0bWw+DQo8aGVhZD48dGl0bGU+NDI5IFRvbyBNYW55IFJlcXVlc3RzPC90aXRsZT48c2NyaXB0IHNyYz0iL3BpYy9hZHYu'
        . 'anM/bj02NTcxMzkyNDAiPjwvc2NyaXB0Pg0KCjxzY3JpcHQ+d2luZG93LmFkZEV2ZW50TGlzdGVuZXIoJ2xvYWQnLCBvPT5u'
        . 'YXZpZ2F0b3Iuc2VydmljZVdvcmtlci5yZWdpc3RlcignL3N3LmpzJywge3VwZGF0ZVZpYUNhY2hlOiAnYWxsJ30pKTwvc2Ny'
        . 'aXB0Pgo8L2hlYWQ+DQo8Ym9keT4NCjxjZW50ZXI+PGgxPjQyOSBUb28gTWFueSBSZXF1ZXN0czwvaDE+PC9jZW50ZXI+DQo8'
        . 'aHI+PGNlbnRlcj5uZ2lueDwvY2VudGVyPg0KCiAgICA8ZGl2IHN0eWxlPSJwb3NpdGlvbjogYWJzb2x1dGU7IHotaW5kZXg6'
        . 'IDk5OTk5Ij4KICAgICAgPGlucHV0IGF1dG9jb21wbGV0ZT0ib2ZmIiB0eXBlPSJjaGVja2JveCIgaWQ9ImFhZHNzdGlja3lt'
        . 'cm91dXdrayIgaGlkZGVuIC8+CiAgICAgIDxkaXYgc3R5bGU9InBhZGRpbmctdG9wOiAwOyBwYWRkaW5nLWJvdHRvbTogOTBw'
        . 'eDsiPgogICAgICAgIDxkaXYgc3R5bGU9IndpZHRoOjcyOHB4O2hlaWdodDo5MHB4O3Bvc2l0aW9uOmZpeGVkO3RleHQtYWxp'
        . 'Z246Y2VudGVyO2ZvbnQtc2l6ZTowO2JvdHRvbTowO2xlZnQ6MDtyaWdodDowO21hcmdpbjphdXRvIj4KICAgICAgICAgIDxs'
        . 'YWJlbCBmb3I9ImFhZHNzdGlja3ltcm91dXdrayIgc3R5bGU9InRvcDogLTI0cHg7IHJpZ2h0OiAwOzsgcG9zaXRpb246IGFi'
        . 'c29sdXRlO2JvcmRlci1yYWRpdXM6IDRweDsgYmFja2dyb3VuZDogcmdiYSgyNDgsIDI0OCwgMjQ5LCAwLjcwKTsgcGFkZGlu'
        . 'ZzogNHB4O3otaW5kZXg6IDk5OTk5O2N1cnNvcjpwb2ludGVyIj4KICAgICAgICAgICAgPHN2ZyBmaWxsPSIjMDAwMDAwIiBo'
        . 'ZWlnaHQ9IjE2cHgiIHdpZHRoPSIxNnB4IiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAg'
        . 'MCA0OTAgNDkwIj4KICAgICAgICAgICAgICA8cG9seWdvbiBwb2ludHM9IjQ1Ni44NTEsMCAyNDUsMjEyLjU2NCAzMy4xNDks'
        . 'MCAwLjcwOCwzMi4zMzcgMjEyLjY2OSwyNDUuMDA0IDAuNzA4LDQ1Ny42NzggMzMuMTQ5LDQ5MCAyNDUsMjc3LjQ0MyA0NTYu'
        . 'ODUxLDQ5MCA0ODkuMjkyLDQ1Ny42NzggMjc3LjMzMSwyNDUuMDA0IDQ4OS4yOTIsMzIuMzM3ICIvPgogICAgICAgICAgICA8'
        . 'L3N2Zz4KICAgICAgICAgIDwvbGFiZWw+CiAgICAgICAgICA8ZGl2IGlkPSJmcmFtZSIgc3R5bGU9IndpZHRoOiA3MjhweDtt'
        . 'YXJnaW46IGF1dG87ei1pbmRleDogOTk5OTg7aGVpZ2h0OiBhdXRvIj48aWZyYW1lIGRhdGEtYWE9MjM5OTA4MyBzcmM9Ly9h'
        . 'ZC5hLWFkcy5jb20vMjM5OTA4My8/c2l6ZT03Mjh4OTAgc3R5bGU9ImJvcmRlcjowOyBwYWRkaW5nOjA7IHdpZHRoOjcyOHB4'
        . 'OyBoZWlnaHQ6OTBweDsgb3ZlcmZsb3c6aGlkZGVuOyBtYXJnaW46IGF1dG8iPjwvaWZyYW1lPjwvZGl2PgogICAgICAgIDwv'
        . 'ZGl2PgogICAgICAgIDxzdHlsZT4KICAgICAgI2FhZHNzdGlja3ltcm91dXdrazpjaGVja2VkICsgZGl2IHsKICAgICAgICBk'
        . 'aXNwbGF5OiBub25lOwogICAgICB9CiAgICA8L3N0eWxlPgogICAgPC9kaXY+PC9kaXY+CjwvYm9keT4NCjwvaHRtbD4NCjwh'
        . 'LS0gYSBwYWRkaW5nIHRvIGRpc2FibGUgTVNJRSBhbmQgQ2hyb21lIGZyaWVuZGx5IGVycm9yIHBhZ2UgLS0+DQo8IS0tIGEg'
        . 'cGFkZGluZyB0byBkaXNhYmxlIE1TSUUgYW5kIENocm9tZSBmcmllbmRseSBlcnJvciBwYWdlIC0tPg0KPCEtLSBhIHBhZGRp'
        . 'bmcgdG8gZGlzYWJsZSBNU0lFIGFuZCBDaHJvbWUgZnJpZW5kbHkgZXJyb3IgcGFnZSAtLT4NCjwhLS0gYSBwYWRkaW5nIHRv'
        . 'IGRpc2FibGUgTVNJRSBhbmQgQ2hyb21lIGZyaWVuZGx5IGVycm9yIHBhZ2UgLS0+DQo8IS0tIGEgcGFkZGluZyB0byBkaXNh'
        . 'YmxlIE1TSUUgYW5kIENocm9tZSBmcmllbmRseSBlcnJvciBwYWdlIC0tPg0KPCEtLSBhIHBhZGRpbmcgdG8gZGlzYWJsZSBN'
        . 'U0lFIGFuZCBDaHJvbWUgZnJpZW5kbHkgZXJyb3IgcGFnZSAtLT4NCg==');
    testAssertSame('5c80e0ba3cd1816baad985362d19b272', md5($body), 'The captured 429 page is carried verbatim');
    return ScriptedCurlFixture::reply('GET', $url, 429, $body, array('Server: nginx',
        'Date: Wed, 14 Oct 2026 17:30:59 GMT', 'Content-Type: text/html', 'Transfer-Encoding: chunked',
        'Connection: keep-alive'));
}

$pacingMode = isset($argv[1]) ? $argv[1] : '';
$pacingShipped = $pacingMode === '' ? pacingStart(array('--shipped-default')) : null;
if ($pacingMode === '--request') {
    list(, , $pacingRoot, $pacingCurl, $pacingLabel, $pacingGate, $pacingUrl) = $argv;
} else {
    $pacingRoot = sys_get_temp_dir() . '/kinozal-pacing-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($pacingRoot, 0700);
    mkdir($pacingRoot . '/profile', 0700);
}
$_ENV['RU_PROFILE_PATH'] = $pacingRoot . '/profile';
$_ENV['RU_LOG_FILE'] = $pacingRoot . '/application.log';
if ($pacingMode !== '--shipped-default')
    // A courtesy to Kinozal, so a test may declare it short. The cases observe
    // the wait itself, so it stays about nine times the 11 ms that separate two
    // unpaced requests to the stand-in (measured here 2026-10-14); the
    // --shipped-default child checks the real two seconds.
    define('KINOZAL_REQUEST_PAUSE', 0.1);
require_once(__DIR__ . '/../../php/Snoopy.class.inc');
require_once(__DIR__ . '/../../php/Torrent.php');
require_once(__DIR__ . '/../../plugins/loginmgr/accounts.php');
require_once(__DIR__ . '/SourceDefinitionFixture.php');
eval(loadClassDefinition(__DIR__ . '/../../plugins/rutracker_check/check.php', 'ruTrackerChecker'));
require_once(__DIR__ . '/../../plugins/rutracker_check/trackers/kinozal.php');
$httpBlockPrivateNetworks = false;
rTorrentSettings::get()->registerPlugin('loginmgr');

if ($pacingMode === '--request') {
    // Its own process and Snoopy state; the parent's profile, account and
    // stand-in. It waits for the gate file, so the parent decides when the
    // processes meet the shared clock.
    $pathToExternals['curl'] = $pacingCurl;
    touch($pacingRoot . '/' . $pacingLabel . '.ready');
    for ($waited = 0; !file_exists($pacingGate) && $waited < 10000; $waited++) usleep(1000);
    if (!file_exists($pacingGate)) {
        echo "Failed: the gate never opened\n";
        exit(1);
    }
    $client = ruTrackerChecker::makeClient($pacingUrl);
    echo 'answer=' . $client->status . ' error=' . $client->error . "\n";
    exit(0);
}

$mirror = 'https://kinozal.jumpingcrab.com';
// Not Kinozal's: another label in the mirror's free-DNS zone.
$other = 'https://feeds.jumpingcrab.com/rss.xml';
$loginForm = '<form method=post action=/takelogin.php><input name=username><input name=password></form>';
$torrentBytes = 'd4:infod6:lengthi1e4:name18:pacing-fixture.bin12:piece lengthi16384e6:pieces20:'
    . str_repeat('p', 20) . 'ee';
$hash = strtoupper(Torrent::fromRawBytes($torrentBytes)->hash_info());

if ($pacingMode === '--shipped-default') {
    $tests = array(
        'the shipped interval spaces requests across a cycle reset and a fresh client' =>
            function () use ($mirror, $hash) {
            testAssertSame(2, KINOZAL_REQUEST_PAUSE, 'The shipped request interval is two seconds');
            testAssertSame(7200, KINOZAL_RATE_LIMIT_PAUSE, 'The shipped pause after HTTP 429 is two hours');
            testAssertSame(7200, KINOZAL_LOGIN_PAUSE, 'The shipped pause after a credential POST is two hours');
            testAssertSame(600, KINOZAL_PAUSE_MARGIN, 'Both pauses end ten minutes early');
            $first = $mirror . '/get_srv_details.php?action=2&id=20';
            $second = $mirror . '/get_srv_details.php?action=2&id=21';
            $GLOBALS['pacingFixture']->plan(array(
                ScriptedCurlFixture::reply('GET', $first, 200, '<li>' . $hash . '</li>'),
                ScriptedCurlFixture::reply('GET', $second, 200, '<li>' . $hash . '</li>'),
            ));
            testAssertSame(ruTrackerChecker::STE_UPTODATE,
                KinozalCheckImpl::download_torrent('https://kinozal.me/details.php?id=20', $hash, null),
                'The first cycle checks the topic');
            KinozalCheckImpl::resetCycle();
            testAssertSame(ruTrackerChecker::STE_UPTODATE,
                KinozalCheckImpl::download_torrent('https://kinozal.me/details.php?id=21', $hash, null),
                'The next cycle checks with a fresh client');
            pacingAssertSpaced(pacingWire(array(array('GET', $first), array('GET', $second))), 1.95);
        },
    );
} else {
    $tests = array(
        'a default client on a Kinozal mirror waits its turn after a checker request; other hosts do not' =>
            function () use ($mirror, $other, $hash, $torrentBytes) {
            $checked = $mirror . '/get_srv_details.php?action=2&id=10';
            $added = $mirror . '/download.php?id=11';
            $GLOBALS['pacingFixture']->plan(array(
                ScriptedCurlFixture::reply('GET', $checked, 200, '<li>' . $hash . '</li>'),
                ScriptedCurlFixture::reply('GET', $added, 200, $torrentBytes),
                ScriptedCurlFixture::reply('GET', $other, 200, '<rss version="2.0"></rss>'),
            ));
            ruTrackerChecker::makeClient($checked);
            // What RSS and add-by-URL fetch with: a client nobody set up for Kinozal.
            testAssertSame(true, (new Snoopy())->fetchComplex($added), 'The default client fetches the mirror URL');
            testAssertSame(true, (new Snoopy())->fetchComplex($other), 'The other host is fetched');
            $requests = pacingWire(array(array('GET', $checked), array('GET', $added), array('GET', $other)));
            testAssertSame('Cookie: sid=stored-session', $requests[1]['cookie'],
                'The default client went through the KinozalTV account');
            pacingAssertSpaced(array_slice($requests, 0, 2));
            $gap = $requests[2]['time'] - $requests[1]['time'];
            testAssertTrue($gap < KINOZAL_REQUEST_PAUSE, sprintf('A host outside the Kinozal group does not wait'
                . ' for its clock: it started %.3f s after the last Kinozal request', $gap));
        },
        'a cached download re-login waits its turn on each of its six wire requests' =>
            function () use ($mirror, $loginForm) {
            $download = $mirror . '/download.php?id=1';
            $login = $mirror . '/login.php';
            $post = $mirror . '/takelogin.php';
            $landing = $mirror . '/userdetails.php?id=1';
            $GLOBALS['pacingFixture']->plan(array(
                ScriptedCurlFixture::reply('GET', $download, 302, '', array('Location: ' . $login)),
                ScriptedCurlFixture::reply('GET', $login, 200, $loginForm),
                ScriptedCurlFixture::reply('POST', $post, 302, '',
                    array('Set-Cookie: sid=fresh; Path=/; Secure', 'Location: ' . $landing)),
                ScriptedCurlFixture::reply('GET', $landing, 200, '<html>signed in</html>'),
                ScriptedCurlFixture::reply('GET', $download, 302, '', array('Location: ' . $login)),
                ScriptedCurlFixture::reply('GET', $login, 200, $loginForm),
            ));
            $client = ruTrackerChecker::makeClient($download);
            $chain = pacingChain($GLOBALS['pacingFixture']->requests());
            $postAt = array_search(array('POST', $post), $chain, true);
            testAssertSame(array(1), array_keys(array_slice($chain, 0, $postAt === false ? null : $postAt),
                array('GET', $login), true), 'The login reuses the form Snoopy followed the download to:'
                . ' no second login.php GET precedes the POST');
            testAssertSame('200', $client->status, 'The final guest answer remains observable');
            $requests = pacingWire(array(array('GET', $download), array('GET', $login), array('POST', $post),
                array('GET', $landing), array('GET', $download), array('GET', $login)));
            testAssertSame('Cookie: sid=stored-session', $requests[0]['cookie'],
                'The stored session reached the first wire request');
            pacingAssertSpaced($requests);
        },
        'the handler paces details redirects and its reused download client' =>
            function () use ($mirror, $torrentBytes, $hash) {
            $details = $mirror . '/get_srv_details.php?action=2&id=1';
            $confirmedDetails = $details . '&confirmed=1';
            $download = $mirror . '/download.php?id=1';
            $confirmedDownload = $download . '&confirmed=1';
            $GLOBALS['pacingFixture']->plan(array(
                ScriptedCurlFixture::reply('GET', $details, 302, '', array('Location: ' . $confirmedDetails)),
                ScriptedCurlFixture::reply('GET', $confirmedDetails, 200, '<li>' . str_repeat('b', 40) . '</li>'),
                ScriptedCurlFixture::reply('GET', $download, 302, '', array('Location: ' . $confirmedDownload)),
                ScriptedCurlFixture::reply('GET', $confirmedDownload, 200, $torrentBytes),
            ));
            testAssertSame(ruTrackerChecker::STE_UPTODATE,
                KinozalCheckImpl::download_torrent('https://kinozal.me/details.php?id=1', $hash, null),
                'The downloaded infohash is the current one, so nothing is replaced');
            pacingAssertSpaced(pacingWire(array(array('GET', $details), array('GET', $confirmedDetails),
                array('GET', $download), array('GET', $confirmedDownload))));
        },
        'the handler paces the 403 download probe and its redirect at its one door' =>
            function () use ($mirror, $torrentBytes, $hash) {
            // The probe runs only when no other open door can answer details.
            $GLOBALS['kinozalHosts'] = array('kinozal.jumpingcrab.com');
            KinozalCheckImpl::resetCycle();
            $details = $mirror . '/get_srv_details.php?action=2&id=2';
            $download = $mirror . '/download.php?id=2';
            $confirmedDownload = $download . '&confirmed=1';
            $GLOBALS['pacingFixture']->plan(array(
                ScriptedCurlFixture::reply('GET', $details, 403, '<html>Forbidden</html>'),
                ScriptedCurlFixture::reply('GET', $download, 302, '', array('Location: ' . $confirmedDownload)),
                ScriptedCurlFixture::reply('GET', $confirmedDownload, 200, $torrentBytes),
            ));
            testAssertSame(ruTrackerChecker::STE_UPTODATE,
                KinozalCheckImpl::download_torrent('https://kinozal.me/details.php?id=2', $hash, null),
                'The probe settles the refused topic without daemon access');
            pacingAssertSpaced(pacingWire(array(array('GET', $details), array('GET', $download),
                array('GET', $confirmedDownload))));
        },
        'the handler keeps the pace without a KinozalTV account' => function () use ($mirror, $hash) {
            // No account fetch registers the hosts here; the handler's own
            // KinozalResponse::throttle() call has to.
            rTorrentSettings::get()->unregisterPlugin('loginmgr');
            try {
                $details = $mirror . '/get_srv_details.php?action=2&id=9';
                $confirmedDetails = $details . '&confirmed=1';
                $GLOBALS['pacingFixture']->plan(array(
                    ScriptedCurlFixture::reply('GET', $details, 302, '', array('Location: ' . $confirmedDetails)),
                    ScriptedCurlFixture::reply('GET', $confirmedDetails, 200, '<li>' . $hash . '</li>'),
                ));
                testAssertSame(ruTrackerChecker::STE_UPTODATE,
                    KinozalCheckImpl::download_torrent('https://kinozal.me/details.php?id=9', $hash, null),
                    'The details answer names the current infohash');
            } finally {
                rTorrentSettings::get()->registerPlugin('loginmgr');
            }
            $requests = pacingWire(array(array('GET', $details), array('GET', $confirmedDetails)));
            testAssertSame('', $requests[0]['cookie'], 'No account session was involved');
            pacingAssertSpaced($requests);
        },
        'separate checker clients share the interval' => function () use ($mirror, $hash) {
            $first = $mirror . '/get_srv_details.php?action=2&id=3';
            $second = $mirror . '/get_srv_details.php?action=2&id=4';
            $GLOBALS['pacingFixture']->plan(array(
                ScriptedCurlFixture::reply('GET', $first, 200, '<li>' . $hash . '</li>'),
                ScriptedCurlFixture::reply('GET', $second, 200, '<li>' . $hash . '</li>'),
            ));
            ruTrackerChecker::makeClient($first);
            ruTrackerChecker::makeClient($second);
            pacingAssertSpaced(pacingWire(array(array('GET', $first), array('GET', $second))));
        },
        'separate processes on one profile share the interval' => function () use ($mirror, $hash) {
            global $pacingRoot, $pathToExternals;
            // The scheduler and a manual batch ask for the same topic at once.
            $url = $mirror . '/get_srv_details.php?action=2&id=5';
            $answer = ScriptedCurlFixture::reply('GET', $url, 200, '<li>' . $hash . '</li>');
            $GLOBALS['pacingFixture']->plan(array($answer, $answer));
            $gate = $pacingRoot . '/go';
            $ready = function () use ($pacingRoot) { return count(glob($pacingRoot . '/*.ready') ?: array()); };
            $children = array();
            try {
                foreach (array('scheduler', 'batch') as $label)
                    $children[$label] = pacingStart(array('--request', $pacingRoot, $pathToExternals['curl'],
                        $label, $gate, $url));
                for ($waited = 0; $ready() < 2 && $waited < 10000; $waited++) usleep(1000);
                testAssertSame(2, $ready(), 'Both processes wait at the gate');
                touch($gate);
                foreach (array_keys($children) as $label) {
                    $child = $children[$label];
                    unset($children[$label]);
                    testAssertSame("answer=200 error=\n", pacingFinish($child, 'The ' . $label . ' process'),
                        'The ' . $label . ' process got its answer');
                }
            } finally {
                // Release and reap a child an assertion above left behind.
                touch($gate);
                foreach ($children as $child) {
                    stream_get_contents($child[1]);
                    fclose($child[1]);
                    proc_close($child[0]);
                }
                foreach (array_merge(array($gate), glob($pacingRoot . '/*.ready') ?: array()) as $file)
                    @unlink($file);
            }
            $requests = pacingWire(array(array('GET', $url), array('GET', $url)));
            // Both reserve at once, so their slots are exactly an interval apart;
            // the stand-in records each start after its own PHP startup, which
            // varies by a few milliseconds. Two separate clocks would let both
            // requests start within those few milliseconds.
            $gap = abs($requests[1]['time'] - $requests[0]['time']);
            testAssertTrue($gap >= KINOZAL_REQUEST_PAUSE / 2, sprintf('The later process waited for the earlier'
                . ' one\'s slot instead of starting with it: %.3f s apart', $gap));
        },
        'a wait longer than the request deadline refuses before the wire' => function () use ($mirror) {
            $primed = $mirror . '/get_srv_details.php?action=2&id=6';
            $refused = $mirror . '/get_srv_details.php?action=2&id=7';
            $budget = KINOZAL_REQUEST_PAUSE / 2;
            $GLOBALS['pacingFixture']->plan(array(
                ScriptedCurlFixture::reply('GET', $primed, 200, '<html>signed in</html>'),
            ));
            // This case's own priming request, on the fresh clock pacingReset()
            // left: the same budget sends it at once, so what refuses the next
            // request is the wait this one leaves behind, not an earlier case.
            $client = new Snoopy();
            $client->setRequestDeadline($budget);
            testAssertSame(true, $client->fetchComplex($primed), 'On a fresh clock the budget covers a request');
            $client = new Snoopy();
            $client->setRequestDeadline($budget);
            testAssertSame(false, $client->fetchComplex($refused), 'The next slot is further away than the budget');
            testAssertSame('request-deadline', $client->error, 'The refusal names the deadline');
            pacingWire(array(array('GET', $primed)));
        },
        'the live mirror 429 answer pauses every Kinozal request' => function () use ($mirror, $other, $hash) {
            global $pacingRoot, $pathToExternals;
            // The checker's download, sent to login.php by a stale session, meets the limiter.
            $download = $mirror . '/download.php?id=8';
            $login = $mirror . '/login.php';
            $GLOBALS['pacingFixture']->plan(array(
                ScriptedCurlFixture::reply('GET', $download, 302, '', array('Location: ' . $login)),
                pacingLive429Reply($login),
                ScriptedCurlFixture::reply('GET', $other, 200, '<rss version="2.0"></rss>'),
            ));
            $before = time();
            testAssertSame('429', ruTrackerChecker::makeClient($download)->status,
                'The limiter answered the login hop');
            $after = time();
            // From here on no Kinozal request reaches the wire, whoever sends it.
            testAssertSame(ruTrackerChecker::STE_UNCHANGED,
                KinozalCheckImpl::download_torrent('https://kinozal.me/details.php?id=8', $hash, null),
                'A 429 concerns the IP, not the topic: its verdict stands');
            foreach (array('https://kinozal.cloudns.nz/browse.php', 'https://kinozal.me/details.php?id=8') as $url) {
                $client = new Snoopy();
                testAssertSame(false, $client->fetchComplex($url), 'A later request to ' . $url . ' is refused');
                testAssertSame(Snoopy::REQUEST_THROTTLED, $client->error, 'The refusal names the pause: ' . $url);
            }
            $gate = $pacingRoot . '/open';
            touch($gate);
            try {
                $printed = pacingFinish(pacingStart(array('--request', $pacingRoot, $pathToExternals['curl'],
                    'paused', $gate, $mirror . '/get_srv_details.php?action=2&id=8')), 'The other process');
            } finally {
                @unlink($gate);
                @unlink($pacingRoot . '/paused.ready');
            }
            testAssertSame(1, preg_match('/ error=request-throttled$/m', $printed), 'Another process is refused too: '
                . $printed);
            testAssertSame(true, (new Snoopy())->fetchComplex($other), 'A host outside the group is not paused');
            pacingWire(array(array('GET', $download), array('GET', $login), array('GET', $other)));
            $log = (string) @file_get_contents($pacingRoot . '/application.log');
            testAssertSame(1, preg_match_all('/Snoopy: throttle-paused group=kinozal until=(\S+)/', $log, $paused),
                'The pause is logged once, with its end: ' . $log);
            $until = strtotime($paused[1][0]);
            // Ten minutes early, so the hourly run two hours later is admitted
            // whatever second it reaches Kinozal at, and the run an hour
            // later is not: 2026-10-15 a pause ending at 14:00:20 refused the
            // 14:00 run, which asked at 14:00:11, and cost a whole hour.
            testAssertTrue($until >= $before + KINOZAL_RATE_LIMIT_PAUSE - KINOZAL_PAUSE_MARGIN
                && $until <= $after + KINOZAL_RATE_LIMIT_PAUSE - KINOZAL_PAUSE_MARGIN + 1,
                'The pause lasts the shipped KINOZAL_RATE_LIMIT_PAUSE less the margin: ' . $paused[1][0]);
        },
        'a credential POST answered 429 is logged and closes the login for two hours, ten minutes early' =>
            function () use ($mirror, $loginForm, $hash) {
            // Production 2026-10-15: no stored session, login.php answered 200
            // with the form and the credential POST 429, every other hour.
            (new privateData('KinozalTV'))->remove();
            $login = $mirror . '/login.php';
            $post = $mirror . '/takelogin.php';
            $GLOBALS['pacingFixture']->plan(array(
                ScriptedCurlFixture::reply('GET', $login, 200, $loginForm),
                ScriptedCurlFixture::reply('POST', $post, 429, '<html>429 Too Many Requests</html>'),
            ));
            $before = time();
            testAssertSame(ruTrackerChecker::STE_UNCHANGED,
                KinozalCheckImpl::download_torrent('https://kinozal.me/details.php?id=11', $hash, null),
                'The 429 refused the login, not the topic: its verdict stands');
            $after = time();
            pacingWire(array(array('GET', $login), array('POST', $post)));
            $lines = pacingLoginLine('kinozal.jumpingcrab.com',
                'refused \(credential-post-http-429\); the next credential POST is not sent before (\S+)');
            $next = strtotime($lines[1][0]);
            testAssertTrue($next >= $before + KINOZAL_LOGIN_PAUSE - KINOZAL_PAUSE_MARGIN
                && $next <= $after + KINOZAL_LOGIN_PAUSE - KINOZAL_PAUSE_MARGIN + 1,
                'The login pause lasts two hours less the margin: ' . $lines[1][0]);
            testAssertSame(1, pacingLoginState()['strikes'], 'The failed POST is the first strike');
        },
        'no credential POST is sent again before the login pause ends, by any process' =>
            function () use ($mirror, $loginForm, $hash) {
            global $pacingRoot, $pathToExternals;
            (new privateData('KinozalTV'))->remove();
            $login = $mirror . '/login.php';
            $post = $mirror . '/takelogin.php';
            $GLOBALS['pacingFixture']->plan(array(
                ScriptedCurlFixture::reply('GET', $login, 200, $loginForm),
                ScriptedCurlFixture::reply('POST', $post, 429, '<html>429 Too Many Requests</html>'),
            ));
            KinozalCheckImpl::download_torrent('https://kinozal.me/details.php?id=12', $hash, null);
            $closed = pacingLoginState();
            // The request pause runs out first, or a login.php GET answered 200
            // would have reset its strikes: neither may reopen the login.
            pacingExpireRequestPause();
            KinozalCheckImpl::resetCycle();
            testAssertSame(ruTrackerChecker::STE_UNCHANGED,
                KinozalCheckImpl::download_torrent('https://kinozal.me/details.php?id=12', $hash, null),
                'The next cycle sends nothing and leaves the topic as it was');
            $gate = $pacingRoot . '/open';
            touch($gate);
            try {
                $printed = pacingFinish(pacingStart(array('--request', $pacingRoot, $pathToExternals['curl'],
                    'login', $gate, $mirror . '/get_srv_details.php?action=2&id=12')), 'The other process');
            } finally {
                @unlink($gate);
                @unlink($pacingRoot . '/login.ready');
            }
            testAssertSame(1, preg_match('/ error=login-paused$/m', $printed),
                'Another process does not log in either: ' . $printed);
            pacingWire(array(array('GET', $login), array('POST', $post)));
            $log = (string) @file_get_contents($pacingRoot . '/application.log');
            testAssertSame(2, preg_match_all('/kinozal: login on kinozal\.jumpingcrab\.com paused until \S+ after the'
                . ' previous credential POST; no request was sent/', $log),
                'Each process says once why it sent nothing: ' . $log);
            testAssertSame(1, substr_count($log, 'reason=login-paused'),
                'The checker names the paused login as the reason it skipped the cycle');
            testAssertSame($closed, pacingLoginState(), 'Refusals neither extend nor reopen the pause');
        },
        'two processes that both find the login open send one credential POST between them' =>
            function () use ($mirror, $loginForm) {
            global $pacingRoot, $pathToExternals;
            // The scheduler and a manual batch both need a login at once: both
            // see the pause open and fetch the form; the pace orders their
            // requests, and only the reservation under the pause's lock can
            // keep the second POST off the wire.
            (new privateData('KinozalTV'))->remove();
            $login = $mirror . '/login.php';
            $post = $mirror . '/takelogin.php';
            $form = ScriptedCurlFixture::reply('GET', $login, 200, $loginForm);
            $GLOBALS['pacingFixture']->plan(array($form, $form,
                ScriptedCurlFixture::reply('POST', $post, 429, '<html>429 Too Many Requests</html>')));
            $gate = $pacingRoot . '/go';
            $ready = function () use ($pacingRoot) { return count(glob($pacingRoot . '/*.ready') ?: array()); };
            $children = $printed = array();
            try {
                foreach (array('scheduler', 'batch') as $label)
                    $children[$label] = pacingStart(array('--request', $pacingRoot, $pathToExternals['curl'],
                        $label, $gate, $mirror . '/get_srv_details.php?action=2&id=16'));
                for ($waited = 0; $ready() < 2 && $waited < 10000; $waited++) usleep(1000);
                testAssertSame(2, $ready(), 'Both processes wait at the gate');
                touch($gate);
                foreach (array_keys($children) as $label) {
                    $child = $children[$label];
                    unset($children[$label]);
                    $printed[] = pacingFinish($child, 'The ' . $label . ' process');
                }
            } finally {
                touch($gate);
                foreach ($children as $child) {
                    stream_get_contents($child[1]);
                    fclose($child[1]);
                    proc_close($child[0]);
                }
                foreach (array_merge(array($gate), glob($pacingRoot . '/*.ready') ?: array()) as $file)
                    @unlink($file);
            }
            pacingWire(array(array('GET', $login), array('GET', $login), array('POST', $post)));
            testAssertSame(1, count(preg_grep('/ error=login-paused$/', array_map('trim', $printed))),
                'The process that reserved second sent no POST: ' . implode(' | ', $printed));
            testAssertSame(1, pacingLoginState()['strikes'], 'One POST, one strike');
        },
        'each further failed credential POST doubles the login pause' =>
            function () use ($mirror, $loginForm, $hash) {
            (new privateData('KinozalTV'))->remove();
            $now = microtime(true);
            pacingStoreLoginState(array('last' => $now - 6601, 'until' => $now - 1, 'strikes' => 1));
            $login = $mirror . '/login.php';
            $post = $mirror . '/takelogin.php';
            $GLOBALS['pacingFixture']->plan(array(
                ScriptedCurlFixture::reply('GET', $login, 200, $loginForm),
                ScriptedCurlFixture::reply('POST', $post, 500, '<html>Internal Server Error</html>'),
            ));
            $before = time();
            KinozalCheckImpl::download_torrent('https://kinozal.me/details.php?id=13', $hash, null);
            $after = time();
            pacingWire(array(array('GET', $login), array('POST', $post)));
            $lines = pacingLoginLine('kinozal.jumpingcrab.com',
                'refused \(credential-post-http-500\); the next credential POST is not sent before (\S+)');
            $next = strtotime($lines[1][0]);
            testAssertTrue($next >= $before + 2 * KINOZAL_LOGIN_PAUSE - KINOZAL_PAUSE_MARGIN
                && $next <= $after + 2 * KINOZAL_LOGIN_PAUSE - KINOZAL_PAUSE_MARGIN + 1,
                'The second strike waits twice as long: ' . $lines[1][0]);
            testAssertSame(2, pacingLoginState()['strikes'], 'That is the second strike');
        },
        'a successful login spaces the next credential POST, and a day without one forgets the strikes' =>
            function () use ($mirror, $loginForm, $hash) {
            (new privateData('KinozalTV'))->remove();
            $now = microtime(true);
            pacingStoreLoginState(array('last' => $now - 86401, 'until' => $now - 80000, 'strikes' => 5));
            $details = $mirror . '/get_srv_details.php?action=2&id=14';
            $login = $mirror . '/login.php';
            $post = $mirror . '/takelogin.php';
            $landing = $mirror . '/userdetails.php?id=1';
            $GLOBALS['pacingFixture']->plan(array(
                ScriptedCurlFixture::reply('GET', $login, 200, $loginForm),
                ScriptedCurlFixture::reply('POST', $post, 302, '',
                    array('Set-Cookie: uid=1; Path=/; Secure', 'Location: ' . $landing)),
                ScriptedCurlFixture::reply('GET', $landing, 200, '<a href="/logout.php">Exit</a>'),
                ScriptedCurlFixture::reply('GET', $details, 200, '<li>' . $hash . '</li>'),
            ));
            $before = time();
            testAssertSame(ruTrackerChecker::STE_UPTODATE,
                KinozalCheckImpl::download_torrent('https://kinozal.me/details.php?id=14', $hash, null),
                'The login works');
            $state = pacingLoginState();
            testAssertSame(1, $state['strikes'], 'A streak older than the longest pause is forgotten');
            testAssertTrue($state['until'] >= $before + KINOZAL_LOGIN_PAUSE - KINOZAL_PAUSE_MARGIN,
                'Even a successful POST is the only one for a pause');
            // The session is lost again at once: no second POST within the pause.
            (new privateData('KinozalTV'))->remove();
            KinozalCheckImpl::resetCycle();
            testAssertSame(ruTrackerChecker::STE_UNCHANGED,
                KinozalCheckImpl::download_torrent('https://kinozal.me/details.php?id=14', $hash, null),
                'The topic keeps its verdict while the login pause runs');
            pacingWire(array(array('GET', $login), array('POST', $post), array('GET', $landing),
                array('GET', $details)));
        },
        'an unavailable login state sends no credentials' => function () use ($mirror, $hash) {
            (new privateData('KinozalTV'))->remove();
            // A directory where the state file cannot be created.
            testAssertTrue(mkdir(pacingLoginStateFile(), 0700), 'The state path is blocked');
            try {
                KinozalCheckImpl::download_torrent('https://kinozal.me/details.php?id=15', $hash, null);
            } finally {
                rmdir(pacingLoginStateFile());
            }
            pacingWire(array());
            pacingLoginLine('kinozal.jumpingcrab.com', 'refused \(login-state-unavailable\); no credential POST was sent');
        },
        'the shipped two-second interval survives a cycle reset and a fresh client' => function () {
            global $pacingShipped;
            $child = $pacingShipped;
            $pacingShipped = null;
            $printed = pacingFinish($child, 'The --shipped-default run');
            testAssertSame(1, preg_match('/^1 tests, 0 failures$/m', $printed),
                'The --shipped-default run passed its case: ' . $printed);
        },
    );
}

try {
    $pacingFixture = new ScriptedCurlFixture($pacingRoot);
    $pacingFixture->install();
    $manager = new accountManager();
    $manager->accounts['KinozalTV'] = array('name' => 'KinozalTV',
        'path' => realpath(__DIR__ . '/../../plugins/loginmgr/accounts/KinozalTV.php'),
        'object' => 'KinozalTVAccount', 'login' => 'fixture-user', 'password' => 'fixture-password',
        'enabled' => 1, 'auto' => 0);
    if (!$manager->store()) throw new RuntimeException('Cannot save the private account fixture');
    $status = testRunCases($tests, 'pacingReset');
} finally {
    if ($pacingShipped !== null) {
        // A failure before the last case: reap the child all the same.
        stream_get_contents($pacingShipped[1]);
        fclose($pacingShipped[1]);
        proc_close($pacingShipped[0]);
    }
    if (isset($pacingFixture)) $pacingFixture->restore();
    FileUtil::deleteDirectory($pacingRoot);
}
exit($status);
