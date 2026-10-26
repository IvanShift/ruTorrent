<?php

// Real Snoopy transport/account code; only curl's answers and the plain
// socket's peer are offline fixtures. No tracker or DNS request is made.
require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/ScriptedCurlFixture.php');
require_once(__DIR__ . '/PhpProcess.php');
$diagnosticChild = getenv('KINOZAL_DIAGNOSTIC_CHILD');
$diagnosticRoot = $diagnosticChild ?: sys_get_temp_dir() . '/kinozal-http-' . getmypid();
if (!$diagnosticChild) mkdir($diagnosticRoot, 0700);
$_ENV['RU_PROFILE_PATH'] = $diagnosticRoot . '/profile';
$_ENV['RU_LOG_FILE'] = $diagnosticRoot . '/application.log';
define('KINOZAL_REQUEST_PAUSE', 0.001);
// Keep the production 429 pause: a separate PHP worker may take longer to start
// than a shortened pause. Each case clears its state instead of waiting for expiry.
require_once(__DIR__ . '/../../php/Snoopy.class.inc');
$httpBlockPrivateNetworks = false;
rTorrentSettings::get()->unregisterPlugin('loginmgr');
rTorrentSettings::get()->unregisterPlugin('cookies');
if ($diagnosticChild) {
    $pathToExternals['curl'] = $diagnosticRoot . '/curl';
    putenv('SCRIPTED_CURL_ROOT=' . $diagnosticRoot);
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/../../plugins/rss/update.php';
    $client = new Snoopy();
    if (getenv('KINOZAL_DIAGNOSTIC_FAULT')) {
        exit($client->fetch('https://kinozal.jumpingcrab.com/login.php') === true ? 0 : 1);
    }
    exit($client->fetchComplex('https://kinozal.cloudns.nz/rss.xml') === false
        && $client->error === Snoopy::REQUEST_THROTTLED ? 0 : 1);
}

function diagnosticLines()
{
    global $diagnosticRoot;
    $records = array();
    foreach (file($diagnosticRoot . '/application.log', FILE_IGNORE_NEW_LINES) ?: array() as $line) {
        if (!preg_match('/kinozal-http: (.+)$/D', $line, $match)) continue;
        testAssertTrue(strlen($line) < 1200, 'The diagnostic line is bounded');
        $record = json_decode($match[1], true);
        testAssertTrue(is_array($record), 'The diagnostic payload is JSON');
        foreach (array('refusal_reason', 'pause_remaining_s', 'session_cookie_pair') as $field)
            testAssertTrue(array_key_exists($field, $record), 'The diagnostic explicitly includes nullable field ' . $field);
        $records[] = $record;
    }
    return $records;
}

function diagnosticReset()
{
    global $diagnosticRoot, $diagnosticFixture;
    foreach (array('throttleGroups', 'throttleStates', 'throttleLogged') as $name) {
        $property = new ReflectionProperty('Snoopy', $name);
        if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
        $property->setValue(null, array());
    }
    @unlink(FileUtil::getSettingsPathEx('') . '/throttle-kinozal.json');
    file_put_contents($diagnosticRoot . '/application.log', '');
    $diagnosticFixture->plan(array());
    rTorrentSettings::get()->unregisterPlugin('loginmgr');
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/../../plugins/rutracker_check/update.php';
}

function diagnosticClient()
{
    $client = new Snoopy();
    $client->block_private = false;
    return $client;
}

// A duplex stream drives the real plain HTTP parser without a TCP listener.
class DiagnosticPlainClient extends Snoopy
{
    public $peer;
    public $reply = "HTTP/1.0 200 OK\r\n\r\nbody";
    public $hold = false;
    public function connect()
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) throw new RuntimeException('Cannot create the private HTTP peer');
        $this->peer = $pair[1];
        fwrite($this->peer, $this->reply);
        if (!$this->hold) stream_socket_shutdown($this->peer, STREAM_SHUT_WR);
        return $pair[0];
    }
}

class DiagnosticMethodSettings extends rTorrentSettings
{
    public function pushEvent($name, $parameters)
    {
        if ($name === 'HostConnect') $parameters['method'] = 'HEAD';
    }
}

class DiagnosticRefusedClient extends Snoopy
{
    protected function checkTarget($host)
    {
        $this->error = 'CANARY_PRIVATE_REFUSAL with arbitrary third-party text';
        return false;
    }
}

$mirror = 'https://kinozal.jumpingcrab.com';
$tests = array(
    'each redirect hop retains its own operation method and result before the next hop' => function () use ($mirror) {
        global $diagnosticFixture;
        $diagnosticFixture->plan(array(
            ScriptedCurlFixture::reply('POST', $mirror . '/takelogin.php', 302, '', array('Location: ' . $mirror . '/details.php?id=4',
                'Set-Cookie: uid=CANARY_UID; Path=/; Secure', 'Set-Cookie: pass=CANARY_PASS; Path=/; Secure')),
            ScriptedCurlFixture::reply('GET', $mirror . '/details.php?id=4', 200, 'details'),
        ));
        testAssertSame(true, diagnosticClient()->fetch($mirror . '/takelogin.php', 'POST', '', 'password=secret'), 'The redirect completes');
        $lines = diagnosticLines();
        testAssertSame(2, count($lines), 'Exactly one completion record per actual hop');
        testAssertSame(array('credential-post', 'details'), array_column($lines, 'operation'), 'The parent hop is logged before recursion');
        testAssertSame(array('POST', 'GET'), array_column($lines, 'method'), 'The redirect changes the method on its own hop');
        testAssertSame(array(302, 200), array_column($lines, 'http'), 'Each hop retains its origin status');
        testAssertSame(array(false, true), array_column($lines, 'session_cookie_pair'), 'Only the redirect request carries the newly issued pair');
        testAssertSame('Cookie: uid=CANARY_UID; pass=CANARY_PASS', $diagnosticFixture->requests()[1]['cookie'], 'The diagnostic describes the actual outgoing header');
        testAssertTrue($lines[0]['attempt'] < $lines[1]['attempt'], 'The process sequence increases');
        foreach ($lines as $line) {
            testAssertSame(getmypid(), $line['pid'], 'The PHP worker is identified');
            testAssertSame('checker-update', $line['caller'], 'The caller is a fixed entrypoint category');
            testAssertSame(true, $line['transport_attempted'], 'The transport was invoked, without claiming delivery');
            testAssertSame(false, $line['configured_proxy'], 'No explicit Snoopy proxy was configured');
            testAssertSame('response', $line['result'], 'The HTTP response completed');
            testAssertSame(null, $line['refusal_reason'], 'An ordinary answer has no refusal reason');
            testAssertTrue(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/D', $line['started_at']) === 1, 'Start time is UTC with milliseconds');
            testAssertTrue(is_numeric($line['duration_ms']) && $line['duration_ms'] >= 0, 'Duration is nonnegative');
        }
    },
    'a real 429 and the next mirror local refusal are distinct and share the locked pause snapshot' => function () use ($mirror) {
        global $diagnosticFixture;
        $url = $mirror . '/takelogin.php';
        $diagnosticFixture->plan(array(ScriptedCurlFixture::reply('POST', $url, 429, 'limited', array('Retry-After: 5'))));
        $remote = diagnosticClient();
        $remote->fetch($url, 'POST', '', 'username=secret&password=secret');
        $before = file_get_contents(FileUtil::getSettingsPathEx('') . '/throttle-kinozal.json');
        $local = diagnosticClient();
        testAssertSame(false, $local->fetch('https://kinozal.cloudns.nz/rss.xml?passkey=secret'), 'The other mirror stays off the wire');
        $lines = diagnosticLines();
        testAssertSame(2, count($lines), 'Both the actual hop and local refusal are visible');
        testAssertSame(array(true, false), array_column($lines, 'transport_attempted'), 'Admission is not mistaken for a transfer');
        testAssertSame(array(429, 0), array_column($lines, 'http'), 'The refused RSS does not inherit the POST response');
        testAssertSame('rss', $lines[1]['operation'], 'The feed is identified without its query');
        testAssertSame('local-refused', $lines[1]['result'], 'No request was sent');
        testAssertSame(array('remote-http-429', 'request-throttled'), array_column($lines, 'refusal_reason'), 'The cause distinguishes the remote refusal and its saved local pause');
        testAssertSame(array(false, null), array_column($lines, 'session_cookie_pair'), 'A local refusal does not claim that an empty Cookie header was sent');
        foreach ($lines as $line)
            testAssertTrue(is_int($line['pause_remaining_s']) && $line['pause_remaining_s'] > 6000
                && $line['pause_remaining_s'] <= 6600, 'The remaining default pause is positive and bounded');
        $state = json_decode($before, true);
        testAssertSame($state['until'], $remote->observedThrottleUntil(), 'The remote refusal getter retains its exact future deadline');
        testAssertSame($state['until'], $local->observedThrottleUntil(), 'The local refusal getter retains the same exact future deadline');
        testAssertSame($state['strikes'], $lines[0]['strikes'], 'The completed pause snapshot supplies strikes');
        testAssertSame($state['until'], $lines[0]['until'], 'The completed pause snapshot supplies until');
        testAssertSame($lines[0]['until'], $lines[1]['until'], 'The admission snapshot sees the same pause');
        testAssertSame(5, $lines[0]['retry_after_s'], 'Only parsed Retry-After is logged');
        testAssertSame(1, count($diagnosticFixture->requests()), 'The local refusal invokes no second curl');
        testAssertSame($before, file_get_contents(FileUtil::getSettingsPathEx('') . '/throttle-kinozal.json'), 'Observation adds no state change');
    },
    'another PHP worker identifies its RSS local refusal on the same profile without another wire attempt' => function () use ($mirror) {
        global $diagnosticFixture, $diagnosticRoot;
        $url = $mirror . '/takelogin.php';
        $diagnosticFixture->plan(array(ScriptedCurlFixture::reply('POST', $url, 429)));
        diagnosticClient()->fetch($url, 'POST');
        $environment = getenv();
        $environment['KINOZAL_DIAGNOSTIC_CHILD'] = $diagnosticRoot;
        $process = proc_open(array(PHP_BINARY, '-c', __DIR__ . '/../php-test.ini', __FILE__),
            array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes, null, $environment);
        testAssertTrue(is_resource($process), 'The separate offline PHP worker starts');
        $out = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        testAssertSame(0, proc_close($process), 'The other worker observes the pause: ' . $out . $error);
        $lines = diagnosticLines();
        testAssertSame(2, count($lines), 'Both workers append one record');
        testAssertTrue($lines[0]['pid'] !== $lines[1]['pid'], 'The independent PHP workers are distinguishable');
        testAssertSame($lines[0]['profile'], $lines[1]['profile'], 'The shared limiter scope has the same pseudonym');
        testAssertSame('rss', $lines[1]['caller'], 'The caller is distinguished from its endpoint');
        testAssertSame(false, $lines[1]['transport_attempted'], 'The worker never launches curl');
        testAssertSame(1, count($diagnosticFixture->requests()), 'Only the original POST reached the wire');
    },
    'the method rewritten by HostConnect is the method handed to transport and logged' => function () use ($mirror) {
        global $diagnosticFixture;
        $url = $mirror . '/details.php?id=7';
        $diagnosticFixture->plan(array(ScriptedCurlFixture::reply('HEAD', $url, 200)));
        $property = new ReflectionProperty('rTorrentSettings', 'theSettings');
        if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
        $previous = $property->getValue();
        $replacement = (new ReflectionClass('DiagnosticMethodSettings'))->newInstanceWithoutConstructor();
        foreach (get_object_vars($previous) as $key => $value) $replacement->$key = $value;
        $property->setValue(null, $replacement);
        try {
            testAssertSame(true, diagnosticClient()->fetch($url), 'The rewritten HEAD completes');
            testAssertSame('HEAD', diagnosticLines()[0]['method'], 'The original GET is not misreported');
            testAssertSame('HEAD', $diagnosticFixture->requests()[0]['method'], 'The observation matches transport');
        } finally {
            $property->setValue(null, $previous);
        }
    },
    'curl timeout keeps attested final 429 facts while clearing all consumer response data' => function () use ($mirror) {
        global $diagnosticFixture;
        $url = $mirror . '/get_srv_details.php?action=2&id=3';
        $reply = ScriptedCurlFixture::reply('GET', $url, 429, 'partial', array('Retry-After: 5', 'Set-Cookie: secret=secret'));
        $reply['exit'] = 28;
        $diagnosticFixture->plan(array($reply));
        $client = diagnosticClient();
        testAssertSame(false, $client->fetch($url), 'The incomplete transfer fails');
        $lines = diagnosticLines();
        testAssertSame(1, count($lines), 'The failed transfer is logged once');
        testAssertSame(429, $lines[0]['http'], 'Completed origin headers remain diagnostic facts');
        testAssertSame(28, $lines[0]['status'], 'curl status remains separate from HTTP');
        testAssertSame(5, $lines[0]['retry_after_s'], 'The discarded answer retains only parsed timing');
        testAssertSame('remote-http-429', $lines[0]['refusal_reason'], 'A body timeout does not hide the observed server refusal');
        testAssertSame('details-api', $lines[0]['operation'], 'The details API endpoint is identified');
        testAssertSame(array(), $client->headers, 'Consumer headers remain empty');
        testAssertSame('', $client->results, 'Consumer body remains empty');
    },
    'CONNECT success with curl timeout is not a final origin response or a strike reset' => function () use ($mirror) {
        global $diagnosticFixture, $diagnosticRoot;
        $path = FileUtil::getSettingsPathEx('') . '/throttle-kinozal.json';
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        file_put_contents($path, json_encode(array('next' => 0, 'ended' => 0, 'until' => 0, 'strikes' => 2)));
        $url = $mirror . '/login.php';
        $reply = ScriptedCurlFixture::reply('GET', $url, 200, '', array('Retry-After: 999', 'Set-Cookie: secret=secret'));
        $reply['exit'] = 28;
        $reply['http_code'] = 0;
        $reply['http_connect'] = 200;
        $diagnosticFixture->plan(array($reply));
        $client = diagnosticClient();
        $client->proxy_host = 'CANARY_PROXY_SECRET';
        $client->proxy_port = 9999;
        testAssertSame(false, $client->fetch($url), 'TLS never received an origin answer');
        $lines = diagnosticLines();
        testAssertSame(1, count($lines), 'The transport failure is still observed');
        testAssertSame(0, $lines[0]['http'], 'CONNECT is excluded without a reason-phrase heuristic');
        testAssertSame(null, $lines[0]['retry_after_s'], 'Proxy headers are not origin timing');
        testAssertSame(2, $lines[0]['strikes'], 'The existing strikes survive');
        testAssertSame(true, $lines[0]['configured_proxy'], 'Only explicit proxy configuration is reported');
        testAssertTrue(strpos(file_get_contents($diagnosticRoot . '/application.log'), 'CANARY_PROXY_SECRET') === false, 'Proxy identity remains secret');
    },
    'plain socket success and body timeouts use the same diagnostic final-header owner' => function () {
        foreach (array('complete', 'plain-timeout', 'chunked-timeout') as $mode) {
            diagnosticReset();
            $timeout = $mode !== 'complete';
            $client = new DiagnosticPlainClient();
            $client->block_private = false;
            $client->cookies = array('uid' => 'CANARY_UID', 'pass' => 'CANARY_PASS');
            $client->read_timeout = 1;
            $client->hold = $timeout;
            if ($timeout) $client->reply = "HTTP/1.0 429 Limited\r\nRetry-After: 5\r\n\r\npartial";
            if ($mode === 'chunked-timeout') $client->reply = "HTTP/1.0 429 Limited\r\nRetry-After: 5\r\nTransfer-Encoding: chunked\r\n\r\n8\r\npart";
            try {
                $ok = $client->fetch('http://kinozal.me/download.php?id=4');
                $lines = diagnosticLines();
                testAssertSame(!$timeout, $ok, 'Only the complete plain response succeeds');
                testAssertSame(1, count($lines), 'The plain hop is logged once');
                testAssertSame('socket', $lines[0]['transport'], 'The plain transport is identified');
                testAssertSame($timeout ? 429 : 200, $lines[0]['http'], 'The final status survives a body timeout');
                testAssertSame($timeout ? -100 : 200, $lines[0]['status'], 'The plain transport status remains separate');
                testAssertSame(true, $lines[0]['transport_attempted'], 'The plain transport was invoked');
                testAssertSame(true, $lines[0]['session_cookie_pair'], 'The plain request carries the same observed cookie pair');
            } finally {
                fclose($client->peer);
            }
        }
    },
    'plain socket cookie observation matches the formed header including an empty jar and raw override' => function () {
        foreach (array('empty', 'pair', 'override') as $mode) {
            diagnosticReset();
            $client = new DiagnosticPlainClient();
            $client->block_private = false;
            if ($mode !== 'empty') $client->cookies = array('uid' => 'CANARY_UID', 'pass' => 'CANARY_PASS');
            if ($mode === 'override') $client->rawheaders['cOoKiE'] = 'uid=CANARY_OVERRIDE';
            try {
                testAssertSame(true, $client->fetch('http://kinozal.me/login.php'), 'The plain request succeeds');
                $lines = diagnosticLines();
                testAssertSame(1, count($lines), 'Exactly one plain request is observed');
                testAssertSame($mode === 'pair', $lines[0]['session_cookie_pair'], 'The flag comes from the actual formed socket header');
                $request = stream_get_contents($client->peer);
                preg_match('/^Cookie:[^\r\n]*/mi', $request, $match);
                $expected = $mode === 'empty' ? '' : ($mode === 'pair'
                    ? 'Cookie: uid=CANARY_UID; pass=CANARY_PASS' : 'cOoKiE: uid=CANARY_OVERRIDE');
                testAssertSame($expected, $match[0] ?? '', 'The socket peer receives the original cookie header exactly');
            } finally {
                fclose($client->peer);
            }
        }
    },
    'deadline preflight refusals never claim a transport attempt or invent a pause snapshot' => function () use ($mirror) {
        global $diagnosticFixture;
        $client = diagnosticClient();
        $client->setRequestDeadline(0);
        testAssertSame(false, $client->fetch($mirror . '/login.php'), 'The exhausted deadline refuses locally');
        $lines = diagnosticLines();
        testAssertSame(1, count($lines), 'The early refusal is visible');
        testAssertSame(false, $lines[0]['transport_attempted'], 'No transport started');
        testAssertSame(null, $lines[0]['configured_proxy'], 'Preflight routing is unknown');
        testAssertSame(null, $lines[0]['strikes'], 'No state was observed before admission');
        testAssertSame(null, $lines[0]['until'], 'Unknown pause is not reported as zero');
        testAssertSame(null, $lines[0]['pause_remaining_s'], 'Unknown pause duration is not reported as zero');
        testAssertSame(null, $lines[0]['session_cookie_pair'], 'No outgoing Cookie header was formed');
        testAssertSame('request-deadline', $lines[0]['refusal_reason'], 'A deadline is distinct from a saved remote refusal');
        testAssertSame(null, $client->observedThrottleUntil(), 'No snapshot exists before admission');
        testAssertSame(array(), $diagnosticFixture->requests(), 'No curl runs');
    },
    'an observed expired pause has zero remaining time and a reused preflight refusal forgets it' => function () use ($mirror) {
        global $diagnosticFixture;
        $path = FileUtil::getSettingsPathEx('') . '/throttle-kinozal.json';
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        $expired = microtime(true) - 10;
        file_put_contents($path, json_encode(array('next' => 0, 'ended' => 0, 'until' => $expired, 'strikes' => 2)));
        $url = $mirror . '/login.php';
        $diagnosticFixture->plan(array(ScriptedCurlFixture::reply('GET', $url, 200, 'login')));
        $client = diagnosticClient();
        testAssertSame(true, $client->fetch($url), 'An expired pause admits the request');
        $lines = diagnosticLines();
        testAssertSame(0, $lines[0]['pause_remaining_s'], 'Known elapsed pause is zero rather than unknown');
        testAssertSame($lines[0]['until'], $client->observedThrottleUntil(), 'The handler sees only the already observed snapshot');
        testAssertTrue(abs($expired - $client->observedThrottleUntil()) < 0.001, 'The retained deadline belongs to the admitted attempt');
        $client->setRequestDeadline(0);
        testAssertSame(false, $client->fetch($url), 'The next attempt fails before reading pause state');
        $lines = diagnosticLines();
        testAssertSame(null, $client->observedThrottleUntil(), 'A reused client cannot lend the previous snapshot to an unobserved attempt');
        testAssertSame(null, $lines[1]['pause_remaining_s'], 'The new diagnostic remains unknown');
        testAssertSame(1, count($diagnosticFixture->requests()), 'No extra request observes the pause');
    },
    'a fractional saved pause rounds remaining seconds up without changing its state' => function () use ($mirror) {
        global $diagnosticFixture;
        $path = FileUtil::getSettingsPathEx('') . '/throttle-kinozal.json';
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        $until = microtime(true) + 60.9;
        $state = json_encode(array('next' => 0, 'ended' => 0, 'until' => $until, 'strikes' => 1));
        file_put_contents($path, $state);
        $client = diagnosticClient();
        $before = microtime(true);
        testAssertSame(false, $client->fetch($mirror . '/login.php'), 'The remaining fraction still refuses');
        $after = microtime(true);
        $remaining = diagnosticLines()[0]['pause_remaining_s'];
        testAssertTrue($remaining >= (int)ceil($until - $after) && $remaining <= (int)ceil($until - $before),
            'Remaining seconds round up within the measured observation interval');
        testAssertSame($state, file_get_contents($path), 'Logging does not refresh the saved deadline');
        testAssertSame(array(), $diagnosticFixture->requests(), 'No wire request is added');
    },
    'cookie diagnostics follow path host secure and expiry scope without selecting the jar again' => function () use ($mirror) {
        global $diagnosticFixture, $diagnosticRoot;
        $api = '/get_srv_details.php?action=2&id=3';
        $diagnosticFixture->plan(array(
            ScriptedCurlFixture::reply('GET', $mirror . '/login.php', 200, '', array(
                'Set-Cookie: uid=CANARY_UID; Path=/get_srv_details.php; Secure',
                'Set-Cookie: pass=CANARY_PASS; Path=/get_srv_details.php; Secure')),
            ScriptedCurlFixture::reply('GET', $mirror . '/details.php?id=3', 200),
            ScriptedCurlFixture::reply('GET', $mirror . $api, 200),
            ScriptedCurlFixture::reply('GET', 'https://kinozal.cloudns.nz' . $api, 200),
            ScriptedCurlFixture::reply('GET', 'http://kinozal.jumpingcrab.com' . $api, 200),
            ScriptedCurlFixture::reply('GET', $mirror . $api, 200, '', array(
                'Set-Cookie: pass=deleted; Path=/get_srv_details.php; Secure; Max-Age=0')),
            ScriptedCurlFixture::reply('GET', $mirror . $api, 200),
        ));
        $client = diagnosticClient();
        $client->fetch($mirror . '/login.php');
        $client->fetch($mirror . '/details.php?id=3');
        $sequence = new ReflectionProperty('Snoopy', 'responseCookieSequence');
        if (PHP_VERSION_ID < 80100) $sequence->setAccessible(true);
        $before = $sequence->getValue($client);
        $client->fetch($mirror . $api);
        testAssertSame($before + 2, $sequence->getValue($client), 'Each selected cookie is used once, not again for diagnostics');
        $client->fetch('https://kinozal.cloudns.nz' . $api);
        $client->setRequestDeadline(30); // Drive plain HTTP through the same offline curl fixture.
        $client->fetch('http://kinozal.jumpingcrab.com' . $api);
        $client->fetch($mirror . $api);
        $client->fetch($mirror . $api);
        testAssertSame(array(false, false, true, false, false, true, false), array_column(diagnosticLines(), 'session_cookie_pair'),
            'Only the scoped nonexpired secure pair is reported');
        testAssertSame(array('', '', 'Cookie: uid=CANARY_UID; pass=CANARY_PASS', '', '',
            'Cookie: uid=CANARY_UID; pass=CANARY_PASS', 'Cookie: uid=CANARY_UID'), array_column($diagnosticFixture->requests(), 'cookie'),
            'The wire header and the diagnostic agree without changing the wire');
        testAssertTrue(strpos(file_get_contents($diagnosticRoot . '/application.log'), 'CANARY_') === false, 'Cookie values stay out of diagnostic logs');
    },
    'raw Cookie overrides and empty lookalike names do not borrow the stored pair' => function () use ($mirror) {
        global $diagnosticFixture, $diagnosticRoot;
        $url = $mirror . '/login.php';
        $values = array('uid=CANARY_UID; pass=CANARY_PASS', 'uid=CANARY_UID',
            'uid=; pass=CANARY_PASS', 'userid=CANARY_UID; password=CANARY_PASS', 'UID=CANARY_UID; pass=CANARY_PASS');
        $diagnosticFixture->plan(array_fill(0, count($values), ScriptedCurlFixture::reply('GET', $url, 200)));
        foreach ($values as $header) {
            $client = diagnosticClient();
            $client->cookies = array('uid' => 'CANARY_STORED_UID', 'pass' => 'CANARY_STORED_PASS');
            $client->rawheaders['cOoKiE'] = $header;
            testAssertSame(true, $client->fetch($url), 'The caller Cookie header reaches the transport');
        }
        testAssertSame(array(true, false, false, false, false), array_column(diagnosticLines(), 'session_cookie_pair'),
            'The diagnostic reads the override, exact names and nonempty values');
        testAssertSame(array_map(function ($value) { return 'cOoKiE: ' . $value; }, $values), array_column($diagnosticFixture->requests(), 'cookie'),
            'Observation preserves the original header spelling and bytes');
        testAssertTrue(strpos(file_get_contents($diagnosticRoot . '/application.log'), 'CANARY_') === false, 'Raw cookie values stay private');
    },
    'unrecognised refusal text is classified without being echoed' => function () use ($mirror) {
        global $diagnosticFixture, $diagnosticRoot;
        $client = new DiagnosticRefusedClient();
        $client->block_private = true;
        testAssertSame(false, $client->fetch($mirror . '/login.php'), 'The real request guard refuses the target');
        $lines = diagnosticLines();
        testAssertSame('client-refusal', $lines[0]['refusal_reason'], 'Unknown text has a fixed safe category');
        testAssertSame(null, $lines[0]['session_cookie_pair'], 'A refused target never forms its cookie header');
        testAssertTrue(strpos(file_get_contents($diagnosticRoot . '/application.log'), 'CANARY_PRIVATE_REFUSAL') === false, 'Untrusted refusal text remains private');
        testAssertSame(array(), $diagnosticFixture->requests(), 'No probe is introduced to explain the refusal');
    },
    'ordinary HTTP refusal and unknown transport failure use fixed categories' => function () use ($mirror) {
        global $diagnosticFixture;
        $url = $mirror . '/login.php';
        $failure = ScriptedCurlFixture::reply('GET', $url, 0, 'CANARY_FAILED_BODY');
        $failure['exit'] = 28;
        $diagnosticFixture->plan(array(ScriptedCurlFixture::reply('GET', $url, 403, 'CANARY_REMOTE_REFUSAL'), $failure));
        $client = diagnosticClient();
        testAssertSame(true, $client->fetch($url), 'An HTTP refusal remains a completed transfer');
        testAssertSame(false, $client->fetch($url), 'A failed transfer remains a failure');
        testAssertSame(array('http-refusal', 'client-refusal'), array_column(diagnosticLines(), 'refusal_reason'),
            'HTTP refusal is separate from an unknown transport failure');
    },
    'unavailable optional numeric and date formatters cannot break the fetch' => function () use ($mirror) {
        global $diagnosticFixture, $diagnosticRoot;
        foreach (array('ceil', 'gmdate') as $disabled) {
            diagnosticReset();
            $url = $mirror . '/login.php';
            $diagnosticFixture->plan(array(ScriptedCurlFixture::reply('GET', $url, 200, 'login')));
            $process = PhpProcess::run(array_merge(PhpProcess::sameIni(), array('-d', 'disable_functions=' . $disabled, __FILE__)),
                array('env' => array('KINOZAL_DIAGNOSTIC_CHILD' => $diagnosticRoot, 'KINOZAL_DIAGNOSTIC_FAULT' => $disabled)));
            testAssertSame(0, $process['code'], 'A disabled diagnostic formatter leaves the request successful: ' . $disabled);
            testAssertSame(1, count($diagnosticFixture->requests()), 'Diagnostics do not retry the successful wire request');
            if ($disabled === 'ceil') {
                $lines = diagnosticLines();
                testAssertSame(1, count($lines), 'A missing optional rounding function retains the other observed diagnostics');
                testAssertSame(null, $lines[0]['pause_remaining_s'], 'Unavailable rounding reports unknown on PHP 7 and PHP 8');
            }
        }
    },
    'a failed log sink cannot break the fetch or change the request' => function () use ($mirror) {
        global $diagnosticFixture, $diagnosticRoot, $log_file;
        $url = $mirror . '/login.php';
        $diagnosticFixture->plan(array(ScriptedCurlFixture::reply('GET', $url, 200, 'login')));
        $saved = $log_file;
        try {
            $log_file = $diagnosticRoot . '/missing/parent/log';
            testAssertSame(true, diagnosticClient()->fetch($url), 'An unwritable log is best effort');
            testAssertSame(1, count($diagnosticFixture->requests()), 'A log write failure does not retry the request');
        } finally {
            $log_file = $saved;
        }
    },
    'safe host method operation caller and profile fields never echo arbitrary URL or payload text' => function () use ($mirror) {
        global $diagnosticFixture, $diagnosticRoot;
        $secret = 'CANARY_429_SECRET';
        $url = $mirror . '/' . $secret . '?key=' . $secret . '#fragment-' . $secret;
        $_SERVER['SCRIPT_FILENAME'] = '/tmp/' . $secret . '.php';
        $diagnosticFixture->plan(array(ScriptedCurlFixture::reply('MALICIOUS_' . $secret, $url, 200, $secret,
            array('X-Secret: ' . $secret, 'Set-Cookie: secret=' . $secret))));
        $client = diagnosticClient();
        $client->cookies = array('secret' => $secret);
        $client->referer = 'https://secret.test/' . $secret;
        $client->fetch($url, 'MALICIOUS_' . $secret, '', 'password=' . $secret);
        $lines = diagnosticLines();
        testAssertSame(1, count($lines), 'The unknown endpoint is still recorded');
        testAssertSame('other', $lines[0]['operation'], 'Only fixed operation labels are emitted');
        testAssertSame('OTHER', $lines[0]['method'], 'Unknown methods are not copied');
        testAssertSame('other', $lines[0]['caller'], 'Unknown script paths are not copied');
        testAssertTrue(strpos(file_get_contents($diagnosticRoot . '/application.log'), $secret) === false, 'No diagnostic secret canary is exposed');
        testAssertTrue(preg_match('/^[a-f0-9]{12}$/D', $lines[0]['profile']) === 1, 'Only the settings-path pseudonym is exposed');
        $userinfo = 'https://' . $secret . ':' . $secret . '@' . $secret . '.kinozal.me/login.php?key=' . $secret;
        $diagnosticFixture->plan(array(ScriptedCurlFixture::reply('GET', $userinfo, 200, $secret)));
        diagnosticClient()->fetch($userinfo);
        $lines = diagnosticLines();
        testAssertSame('*.kinozal.me', $lines[1]['host'], 'Arbitrary subdomain labels are not copied');
        testAssertSame('login-form', $lines[1]['operation'], 'Known categories survive userinfo and query secrets');
        testAssertTrue(strpos(file_get_contents($diagnosticRoot . '/application.log'), $secret) === false, 'Userinfo and subdomain secrets are absent');
        $lookalike = 'https://kinozal.me.evil.test/rss.xml';
        $diagnosticFixture->plan(array(ScriptedCurlFixture::reply('GET', $lookalike, 200, 'other')));
        diagnosticClient()->fetch($lookalike);
        testAssertSame(2, count(diagnosticLines()), 'Unrelated HTTP hosts do not produce this log');
    },
);

try {
    $diagnosticFixture = new ScriptedCurlFixture($diagnosticRoot);
    $diagnosticFixture->install();
    $status = testRunCases($tests, 'diagnosticReset');
} finally {
    if (isset($diagnosticFixture)) $diagnosticFixture->restore();
    FileUtil::deleteDirectory($diagnosticRoot);
}
exit($status);
