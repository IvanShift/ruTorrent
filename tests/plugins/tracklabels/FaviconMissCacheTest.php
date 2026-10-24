<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/AddTorrentHttpHarness.php');

// Drives the real plugins/tracklabels/action.php in a child PHP process as a
// same-origin favicon fetch. Public literal addresses go through a configured
// HTTP proxy that this test plays, so no case reaches the network or DNS.
class FaviconMissCacheTest extends TestCase
{
    const PUBLIC_HOST = '93.184.216.34';
    const NOT_FOUND = "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";

    private $root;
    private $scratch;
    private $server;
    private $port;

    public function setUp()
    {
        $this->root = dirname(__DIR__, 3);
        $this->scratch = sys_get_temp_dir() . '/rt-tracklabels-miss-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->scratch . '/profile/settings', 0777, true);
        file_put_contents($this->scratch . '/request.php', <<<'PHP'
<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER = array_merge($_SERVER, array('HTTP_HOST' => 'localhost', 'REQUEST_SCHEME' => 'http',
    'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ORIGIN' => 'http://localhost'));
$_GET = array();
$_POST = array('fetch' => '1', 'tracker' => json_decode(getenv('ROUTE_TRACKER'), true));
$_REQUEST = $_POST;
register_shutdown_function(function () {
    file_put_contents(getenv('ROUTE_STATUS_FILE'), (string) http_response_code());
});
$route = getenv('ROUTE_FILE');
chdir(dirname($route));
if ((string) getenv('ROUTE_FETCH_TIMEOUT') !== '')
    define('TRACKLABELS_FAVICON_FETCH_TIMEOUT', (int) getenv('ROUTE_FETCH_TIMEOUT'));
require getenv('ROUTE_ROOT') . '/php/util.php';
$httpProxy = array('use' => true, 'proto' => 'http',
    'host' => '127.0.0.1', 'port' => (int) getenv('ROUTE_PROXY_PORT'));
$resolve = (string) getenv('ROUTE_RESOLUTION') !== '' || (string) getenv('ROUTE_ATTEMPTS') !== '';
$observe = getenv('ROUTE_OBSERVE_VALIDATION') === '1' || getenv('ROUTE_STAGE_EVENT') === 'pause';
$published = getenv('ROUTE_STAGE_EVENT') === 'published';
$fallback = getenv('ROUTE_TEMPNAM_FALLBACK') === '1';
if ($resolve || $observe || $fallback || $published) {
    function favicon_stage_checkpoint($path) {
        file_put_contents(getenv('ROUTE_CHECKPOINT'), $path);
        $deadline = microtime(true) + 20;
        while (!is_file(getenv('ROUTE_RELEASE'))) {
            if (microtime(true) > $deadline) throw new RuntimeException('stage checkpoint timed out');
            usleep(10000);
        }
    }
    require getenv('ROUTE_ROOT') . '/php/Snoopy.class.inc';
    // Only the external resolver is replaced, and requests are recorded: Snoopy's
    // target guard and the action's error classification still run production code.
    class FaviconTestResolver extends Snoopy {
        public static function resolveHost($host) {
            if ($host !== 'retry.test') return parent::resolveHost($host);
            static $calls = 0;
            $calls++;
            $mode = getenv('ROUTE_RESOLUTION');
            return ($mode === 'unresolved' || ($mode === 'second-unresolved' && $calls > 1))
                ? array() : array('93.184.216.34');
        }
        // Records each URL the action asks for, including requests that never connect.
        public function fetchComplex($URI, $method = "GET", $content_type = "", $body = "") {
            if ((string) getenv('ROUTE_ATTEMPTS') !== '')
                file_put_contents(getenv('ROUTE_ATTEMPTS'), $URI . "\n", FILE_APPEND);
            return parent::fetchComplex($URI, $method, $content_type, $body);
        }
    }
    $source = file_get_contents($route);
    if ($resolve) {
        $source = str_replace('new Snoopy()', 'new FaviconTestResolver()', $source, $replacements);
        if ($replacements !== 1) throw new RuntimeException('favicon resolver seam moved');
    }
    if ($observe) {
        // Capture a competing reader's view at the validation boundary, while
        // preserving the actual MIME verdict and every filesystem operation.
        function favicon_observe_mime($path) {
            $icon = getenv('ROUTE_CACHE_ICON');
            clearstatcache(true, $icon);
            file_put_contents(getenv('ROUTE_OBSERVATION_FILE'),
                json_encode(is_file($icon) ? file_get_contents($icon) : null) . "\n", FILE_APPEND);
            if (getenv('ROUTE_STAGE_EVENT') === 'pause') favicon_stage_checkpoint($path);
            return mime_content_type($path);
        }
        $source = str_replace('mime_content_type(', 'favicon_observe_mime(', $source, $replacements);
        if ($replacements !== 2) throw new RuntimeException('favicon validation seam moved');
    }
    if ($published) {
        function favicon_test_rename($from, $to) {
            $result = rename($from, $to);
            if ($result) favicon_stage_checkpoint($from);
            return $result;
        }
        $source = str_replace('rename(', 'favicon_test_rename(', $source, $replacements);
        if ($replacements !== 1) throw new RuntimeException('favicon publication seam moved');
    }
    if ($fallback) {
        function favicon_test_tempnam($dir, $prefix) {
            return tempnam(sys_get_temp_dir(), $prefix);
        }
        $source = str_replace('tempnam(', 'favicon_test_tempnam(', $source, $replacements);
        if ($replacements !== 1) throw new RuntimeException('favicon tempnam seam moved');
    }
    $source = str_replace('__FILE__', var_export($route, true), $source);
    eval('?>' . $source);
    exit;
}
require basename($route);
PHP
        );
        $this->server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($this->server === false) throw new RuntimeException('fake favicon proxy cannot listen: ' . $error);
        $this->port = (int) substr(strrchr(stream_socket_get_name($this->server, false), ':'), 1);
    }

    public function tearDown()
    {
        if (is_resource($this->server)) fclose($this->server);
        if (!is_dir($this->scratch)) return;
        @chmod($this->scratch . '/profile/settings', 0777);
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->scratch, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry)
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->scratch);
    }

    private function trackersDir()
    {
        return $this->scratch . '/profile/settings/trackers';
    }

    private function log()
    {
        $path = $this->scratch . '/errors.log';
        clearstatcache(true, $path);
        return is_file($path) ? file_get_contents($path) : '';
    }

    // The child has no daemon, so core logs its own connect failure when
    // Snoopy raises HostConnect; only this plugin's lines are under test.
    private function pluginLogLines()
    {
        return substr_count($this->log(), 'tracklabels:');
    }

    private function gif()
    {
        return base64_decode('R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=');
    }

    private function imageAnswer()
    {
        $icon = $this->gif();
        return "HTTP/1.1 200 OK\r\nContent-Type: image/gif\r\nContent-Length: " . strlen($icon)
            . "\r\nConnection: close\r\n\r\n" . $icon;
    }

    private function childEnv($call, $tracker, $resolution, $observe, $options)
    {
        return array(
            'TMPDIR' => sys_get_temp_dir(),
            'RU_PROFILE_PATH' => $this->scratch . '/profile',
            'RU_LOG_FILE' => $this->scratch . '/errors.log',
            // No daemon: settings lookups fail fast instead of probing a TCP port.
            'RU_SCGI_HOST' => 'unix://' . $this->scratch . '/absent.sock',
            'RU_SCGI_PORT' => '0',
            'ROUTE_ROOT' => $this->root,
            'ROUTE_FILE' => $this->root . '/plugins/tracklabels/action.php',
            'ROUTE_TRACKER' => json_encode($tracker),
            'ROUTE_PROXY_PORT' => (string) (isset($options['proxyPort']) ? $options['proxyPort'] : $this->port),
            'ROUTE_STATUS_FILE' => $call . '-status',
            'ROUTE_RESOLUTION' => $resolution,
            'ROUTE_OBSERVE_VALIDATION' => $observe ? '1' : '0',
            'ROUTE_STAGE_EVENT' => isset($options['checkpoint']) ? (!empty($options['published']) ? 'published' : 'pause') : '',
            'ROUTE_CHECKPOINT' => $call . '-checkpoint', 'ROUTE_RELEASE' => $call . '-release',
            'ROUTE_TEMPNAM_FALLBACK' => !empty($options['fallback']) ? '1' : '0',
            'ROUTE_CACHE_ICON' => $this->trackersDir() . '/' . (is_string($tracker) ? $tracker : '') . '.ico',
            'ROUTE_OBSERVATION_FILE' => $this->scratch . '/validation-view',
            // A longer timeout lets a test hold the upstream silent; empty keeps the shipped one.
            'ROUTE_FETCH_TIMEOUT' => isset($options['timeout']) ? (string) $options['timeout'] : '',
            'ROUTE_ATTEMPTS' => !empty($options['attempts']) ? $call . '-attempts' : '',
        );
    }

    // Starts one favicon fetch for $tracker in a child PHP process. The argv
    // array runs PHP without a shell, so a terminated child leaves nothing behind.
    private function startFetch($tracker, $options = array(), $resolution = '', $observe = false)
    {
        $call = $this->scratch . '/call-' . bin2hex(random_bytes(4));
        $proc = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', $this->scratch . '/request.php'),
            array(0 => array('pipe', 'r'),
                1 => array('file', $call . '-out', 'w'),
                2 => array('file', $call . '-err', 'w')),
            $pipes, null, $this->childEnv($call, $tracker, $resolution, $observe, $options));
        if (!is_resource($proc)) throw new RuntimeException('route child did not start');
        fclose($pipes[0]);
        return array('proc' => $proc, 'call' => $call, 'started' => microtime(true), 'result' => null);
    }

    // The finished child's result, collected once; null while it still runs.
    private function finished(array &$child)
    {
        if ($child['result'] === null) {
            $state = proc_get_status($child['proc']);
            if ($state['running']) return null;
            proc_close($child['proc']);
            clearstatcache();
            $call = $child['call'];
            $child['result'] = array(
                'exit' => $state['exitcode'],
                'elapsed' => microtime(true) - $child['started'],
                'status' => is_file($call . '-status') ? file_get_contents($call . '-status') : '',
                'out' => (string) @file_get_contents($call . '-out'),
                'err' => (string) @file_get_contents($call . '-err'),
                'attempts' => is_file($call . '-attempts') ? file($call . '-attempts', FILE_IGNORE_NEW_LINES) : array(),
            );
        }
        return $child['result'];
    }

    private function stopChildren(array &$children)
    {
        foreach ($children as &$child) {
            if ($child['result'] !== null) continue;
            proc_terminate($child['proc'], 9);
            proc_close($child['proc']);
            $child['result'] = false;
        }
    }

    private function readRequestLine($peer)
    {
        stream_set_timeout($peer, 2);
        $head = '';
        while (strpos($head, "\r\n\r\n") === false && strlen($head) < 8192) {
            $piece = fread($peer, 4096);
            if ($piece === false || $piece === '') break;
            $head .= $piece;
        }
        return strtok($head, "\r\n");
    }

    // Accepts at most one connection that reaches the fake proxy within
    // $seconds and keeps it open and unanswered, as a slow upstream would.
    private function acceptHeld(array &$held, $seconds)
    {
        $read = array($this->server);
        $write = $except = null;
        if (@stream_select($read, $write, $except, 0, (int) ($seconds * 1000000)) < 1) return;
        $peer = @stream_socket_accept($this->server, 1);
        if ($peer !== false) $held[] = array('peer' => $peer, 'request' => $this->readRequestLine($peer));
    }

    private function answerHeld(array &$held, $answer)
    {
        foreach ($held as &$entry) {
            if (!is_resource($entry['peer'])) continue;
            @fwrite($entry['peer'], $answer);
            fclose($entry['peer']);
        }
    }

    // Answers every new upstream request at once until the named children exit.
    private function serveUntilFinished(array &$children, array $names, $answer, $seconds)
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $running = 0;
            foreach ($names as $name) if ($this->finished($children[$name]) === null) $running++;
            if ($running === 0) return;
            $held = array();
            $this->acceptHeld($held, 0.05);
            $this->answerHeld($held, $answer);
        }
    }

    // Runs one favicon fetch for $tracker. Every request that reaches the
    // fake proxy gets $answer; their request lines are returned in order.
    // A null answer keeps that connection open and silent until the child exits.
    private function fetch($tracker, $answer, $resolution = '', $observe = false, $options = array())
    {
        $child = $this->startFetch($tracker, $options, $resolution, $observe);
        $proc = $child['proc'];
        $checkpoint = $child['call'] . '-checkpoint';
        $release = $child['call'] . '-release';
        $requests = array();
        $held = array();
        $deadline = microtime(true) + 30;
        $checkpointHandled = false;
        try {
            while (true) {
                if (!$checkpointHandled && is_file($checkpoint)) {
                    $checkpointHandled = true;
                    $options['checkpoint']($proc, file_get_contents($checkpoint));
                    touch($release);
                }
                if ($this->finished($child) !== null) break;
                if (microtime(true) > $deadline) throw new RuntimeException('route child did not finish');
                $read = array($this->server);
                $write = $except = null;
                if (@stream_select($read, $write, $except, 0, 50000) < 1) continue;
                $peer = @stream_socket_accept($this->server, 1);
                if ($peer === false) continue;
                $requests[] = $this->readRequestLine($peer);
                $reply = is_array($answer) ? $answer[count($requests) - 1] : $answer;
                if ($reply === null) {
                    $held[] = $peer;
                    continue;
                }
                fwrite($peer, $reply);
                fclose($peer);
            }
        } finally {
            foreach ($held as $peer) fclose($peer);
            $children = array($child);
            $this->stopChildren($children);
        }
        return $child['result'] + array('requests' => $requests);
    }

    public function testUnavailableFaviconIsRememberedQuietlyAndNotRefetched()
    {
        $marker = $this->trackersDir() . '/' . self::PUBLIC_HOST . '.miss';
        $first = $this->fetch(self::PUBLIC_HOST, self::NOT_FOUND);
        $this->assertSame(0, $first['exit'], 'first failed fetch exits cleanly: ' . $first['err']);
        $this->assertSame(array('GET http://' . self::PUBLIC_HOST . '/favicon.ico HTTP/1.0',
            'GET http://' . self::PUBLIC_HOST . '/favicon.png HTTP/1.0'), $first['requests'],
            'the first fetch asks the source for both favicon paths');
        $this->assertSame('404', $first['status'], 'an unavailable favicon is a settled 404, not a retryable 503');
        $this->assertSame('Favicon unavailable', $first['out'], 'the failed fetch answers without a placeholder');
        $this->assertTrue(is_file($marker) && filesize($marker) === 0,
            'the failed fetch leaves a zero-byte miss marker beside the icon cache');
        $this->assertTrue(abs(time() - filemtime($marker)) < 60, 'the marker mtime is the miss time');
        $this->assertSame(0, $this->pluginLogLines(), 'an unreachable favicon source is an expected miss and is not logged');

        $second = $this->fetch(self::PUBLIC_HOST, self::NOT_FOUND);
        $this->assertSame(0, $second['exit'], 'remembered miss exits cleanly: ' . $second['err']);
        $this->assertSame(array(), $second['requests'], 'a fresh miss marker suppresses the source fetch');
        $this->assertSame('404', $second['status'], 'a remembered miss is answered with 404 at once');
        $this->assertSame('Favicon unavailable', $second['out'], 'a remembered miss returns no placeholder');
        $this->assertSame(0, $this->pluginLogLines(), 'a remembered miss is not logged');
    }

    public function testNonPublicHostRefusalIsRememberedQuietly()
    {
        $marker = $this->trackersDir() . '/127.0.0.1.miss';
        $first = $this->fetch('127.0.0.1', self::NOT_FOUND);
        $this->assertSame(0, $first['exit'], 'refused fetch exits cleanly: ' . $first['err']);
        $this->assertSame(array(), $first['requests'], 'a non-public tracker host is never contacted');
        $this->assertSame('404', $first['status'], 'a refused tracker host is a settled 404');
        $this->assertTrue(is_file($marker), 'the refusal is remembered as a miss');
        $this->assertSame(0, $this->pluginLogLines(), 'a non-public tracker host is an expected miss and is not logged');
        $this->assertTrue(!is_file($this->trackersDir() . '/127.0.0.1.ico'), 'the refusal caches no icon');

        $missTime = time() - 3600;
        touch($marker, $missTime);
        $second = $this->fetch('127.0.0.1', self::NOT_FOUND);
        $this->assertSame('404', $second['status'], 'the remembered refusal is still a 404');
        clearstatcache(true, $marker);
        $this->assertSame($missTime, filemtime($marker),
            'an hour-old miss is honoured without a new fetch attempt rewriting it');
        $this->assertSame(0, $this->pluginLogLines(), 'the remembered refusal is not logged');
    }

    public function testInvalidTrackerNameIsAQuietMissWithoutMarker()
    {
        $response = $this->fetch('not_a_host', self::NOT_FOUND);
        $this->assertSame(0, $response['exit'], 'invalid tracker fetch exits cleanly: ' . $response['err']);
        $this->assertSame(array(), $response['requests'], 'a name that is not a host name is never fetched');
        $this->assertSame('404', $response['status'], 'an invalid tracker name is a settled 404');
        $this->assertSame(0, $this->pluginLogLines(), 'an invalid tracker name is not logged');
        $this->assertTrue(!file_exists($this->trackersDir()), 'an invalid tracker name builds no cache path');
    }

    public function testTrackerSubdirectoriesCannotBypassTheGlobalFetchSlots()
    {
        // This child receives PHP's already-decoded request values.
        $response = $this->fetch('extra/' . self::PUBLIC_HOST, self::NOT_FOUND);
        $this->assertSame('400', $response['status'], 'tracker input must name one host, not a subdirectory');
        $this->assertSame(array(), $response['requests'], 'a nested tracker cannot open an independent fetch pool');
        $this->assertTrue(!file_exists($this->trackersDir()), 'a rejected tracker creates no cache or lock directory');
    }

    public function testMissExpiresAfterADayAndSuccessClearsTheMarker()
    {
        mkdir($this->trackersDir(), 0777, true);
        $marker = $this->trackersDir() . '/' . self::PUBLIC_HOST . '.miss';
        touch($marker, time() - 86400 + 120);
        $fresh = $this->fetch(self::PUBLIC_HOST, $this->imageAnswer());
        $this->assertSame(array(), $fresh['requests'], 'a miss just under a day old still suppresses the fetch');
        $this->assertSame('404', $fresh['status'], 'a miss just under a day old is still a 404');

        touch($marker, time() - 86400 - 120);
        $expired = $this->fetch(self::PUBLIC_HOST, $this->imageAnswer());
        $this->assertSame(0, $expired['exit'], 'expired miss refetch exits cleanly: ' . $expired['err']);
        $this->assertSame(array('GET http://' . self::PUBLIC_HOST . '/favicon.ico HTTP/1.0'),
            $expired['requests'], 'after a day the source is asked again');
        $this->assertSame($this->gif(), $expired['out'], 'a recovered source serves its favicon');
        $this->assertSame($this->gif(), file_get_contents($this->trackersDir() . '/' . self::PUBLIC_HOST . '.ico'),
            'the recovered favicon is cached');
        $this->assertTrue(!file_exists($marker), 'a successful fetch removes the stale miss marker');
        $this->assertSame(0, $this->pluginLogLines(), 'a successful refetch is not logged');
    }

    public function testFutureDatedMarkerDoesNotPinTheMiss()
    {
        mkdir($this->trackersDir(), 0777, true);
        $marker = $this->trackersDir() . '/' . self::PUBLIC_HOST . '.miss';
        touch($marker, time() + 7 * 86400);
        $response = $this->fetch(self::PUBLIC_HOST, $this->imageAnswer());
        $this->assertSame(1, count($response['requests']),
            'a marker dated in the future (clock step) is treated as stale');
        $this->assertSame($this->gif(), $response['out'], 'the favicon is served after the stale marker');
        $this->assertTrue(!file_exists($marker), 'the future-dated marker is removed by the success');
    }

    public function testTemporaryDnsFailureRetriesAfterFiveMinutes()
    {
        $retry = $this->trackersDir() . '/retry.test.retry';
        $miss = $this->trackersDir() . '/retry.test.miss';
        $first = $this->fetch('retry.test', self::NOT_FOUND, 'unresolved');
        $this->assertSame(0, $first['exit'], 'unresolved source exits cleanly: ' . $first['err']);
        $this->assertSame(array(), $first['requests'], 'the DNS guard refuses before any connection');
        $this->assertSame('404', $first['status'], 'temporary source failure remains a quiet browser miss');
        $this->assertTrue(is_file($retry), 'an unresolved source gets a short retry marker');
        $this->assertTrue(!file_exists($miss), 'an unresolved source is not assumed absent for a day');

        $second = $this->fetch('retry.test', $this->imageAnswer(), 'public');
        $this->assertSame(array(), $second['requests'], 'the retry marker suppresses immediate duplicate fetches');
        if (is_file($retry)) touch($retry, time() - 360);
        $recovered = $this->fetch('retry.test', $this->imageAnswer(), 'public');
        $this->assertSame(array('GET http://' . self::PUBLIC_HOST . '/favicon.ico HTTP/1.0'),
            $recovered['requests'], 'a recovered resolver is retried after five minutes');
        $this->assertSame($this->gif(), $recovered['out'], 'the recovered resolver serves the favicon');
        $this->assertTrue(!file_exists($retry) && !file_exists($miss), 'success removes both negative cache markers');
        $this->assertSame(0, $this->pluginLogLines(), 'temporary DNS failures remain quiet');
    }

    public function testTemporaryHttpFailureRetriesEvenWhenOtherPathIsMissing()
    {
        $retry = $this->trackersDir() . '/' . self::PUBLIC_HOST . '.retry';
        $miss = $this->trackersDir() . '/' . self::PUBLIC_HOST . '.miss';
        $unavailable = "HTTP/1.1 503 Unavailable\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
        $first = $this->fetch(self::PUBLIC_HOST, array($unavailable, self::NOT_FOUND));
        $this->assertSame(0, $first['exit'], 'temporary source failure exits cleanly: ' . $first['err']);
        $this->assertSame(2, count($first['requests']), 'both possible favicon paths are considered');
        $this->assertTrue(is_file($retry), 'one temporary failure keeps the overall miss retryable');
        $this->assertTrue(!file_exists($miss), 'a second-path 404 cannot hide a first-path outage for a day');
        if (is_file($retry)) touch($retry, time() - 360);
        $recovered = $this->fetch(self::PUBLIC_HOST, $this->imageAnswer());
        $this->assertSame($this->gif(), $recovered['out'], 'a temporary HTTP outage is retried after five minutes');
        $this->assertSame(1, count($recovered['requests']), 'recovery needs only the first available favicon');
        $this->assertTrue(!file_exists($retry), 'recovery removes the retry marker');
        $this->assertSame(0, $this->pluginLogLines(), 'expected upstream HTTP failures remain quiet');
    }

    public function testUncertainFailuresBackOffByAttemptCountAndResetAfterRecovery()
    {
        $retry = $this->trackersDir() . '/retry.test.retry';
        $delays = array(300, 600, 1200, 2400, 4800, 9600, 19200, 38400, 76800, 86400, 86400);
        foreach ($delays as $index => $delay) {
            $attempt = $index + 1;
            $response = $this->fetch('retry.test', self::NOT_FOUND, 'unresolved');
            $this->assertSame('404', $response['status'], 'a repeated DNS failure stays a quiet browser miss');
            $this->assertSame((string)$attempt, is_file($retry) ? file_get_contents($retry) : '',
                'the retry marker counts uncertain failures in a row');
            touch($retry, time() - $delay + 30);
            $waiting = $this->fetch('retry.test', $this->imageAnswer(), 'public');
            $this->assertSame(array(), $waiting['requests'],
                'failure ' . $attempt . ' waits min(5 min * 2^(n-1), 24 h) = ' . $delay . ' s');
            touch($retry, time() - $delay - 2);
        }
        $recovered = $this->fetch('retry.test', $this->imageAnswer(), 'public');
        $this->assertSame($this->gif(), $recovered['out'], 'a recovered resolver is tried after the capped delay');
        $this->assertTrue(!file_exists($retry), 'successful recovery clears the retry history');
        unlink($this->trackersDir() . '/retry.test.ico');
        $this->fetch('retry.test', self::NOT_FOUND, 'unresolved');
        $this->assertSame('1', file_get_contents($retry), 'a later outage starts with the short first retry again');
        $this->assertSame(0, $this->pluginLogLines(), 'persistent upstream failure remains quiet');
    }

    public function testTwoTabsDoNotCountTheSameRecentHostFailureTwice()
    {
        $children = array();
        $held = array();
        $answer = "HTTP/1.1 503 Unavailable\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
        try {
            foreach (array('tab-a', 'tab-b') as $tab) {
                $children[$tab] = $this->startFetch(self::PUBLIC_HOST);
                $deadline = microtime(true) + 5;
                while (count($held) < count($children) && microtime(true) < $deadline) $this->acceptHeld($held, 0.02);
            }
            $this->assertSame(2, count($held), 'both tabs began fetching before either negative marker existed');
            if (count($held) !== 2) return;
            // Finish A before releasing B's already-admitted first response.
            // B must observe A's recent marker rather than advance it again.
            fwrite($held[0]['peer'], $answer);
            fclose($held[0]['peer']);
            $this->serveUntilFinished($children, array('tab-a'), $answer, 2);
            $this->answerHeld($held, $answer);
            $this->serveUntilFinished($children, array('tab-b'), $answer, 2);
            foreach ($children as &$child) {
                $result = $this->finished($child);
                $this->assertTrue(is_array($result) && $result['status'] === '404', 'both tabs finish with a quiet miss');
            }
            $retry = $this->trackersDir() . '/' . self::PUBLIC_HOST . '.retry';
            $this->assertSame('1', (string) @file_get_contents($retry), 'near-simultaneous failures count as one backoff step');
            touch($retry, time() - 360);
            $later = $this->fetch(self::PUBLIC_HOST, $answer);
            $this->assertSame(2, count($later['requests']), 'the host is tried again after the first delay');
            $this->assertSame('2', file_get_contents($retry), 'a later failure advances the backoff normally');
        } finally {
            $this->answerHeld($held, '');
            $this->stopChildren($children);
        }
    }

    public function testRetryMarkersFromEarlierReleasesKeepTheirDelay()
    {
        mkdir($this->trackersDir(), 0777, true);
        $retry = $this->trackersDir() . '/retry.test.retry';
        // content => [delay it stands for, count written by the next failure];
        // '' and 'garbage' stand for a truncated or damaged marker.
        $markers = array('' => array(300, '2'), '600' => array(600, '3'),
            '86400' => array(86400, '11'), 'garbage' => array(300, '2'));
        foreach ($markers as $content => $expected) {
            file_put_contents($retry, $content);
            touch($retry, time() - $expected[0] + 30);
            $waiting = $this->fetch('retry.test', $this->imageAnswer(), 'public');
            $this->assertSame(array(), $waiting['requests'],
                json_encode($content) . ' still waits ' . $expected[0] . ' s');
            touch($retry, time() - $expected[0] - 2);
            $this->fetch('retry.test', self::NOT_FOUND, 'unresolved');
            $this->assertSame($expected[1], file_get_contents($retry),
                json_encode($content) . ' continues the attempt count it stands for');
        }
    }

    public function testCompleteClientRefusalsAreTerminalButTimeoutAndRateLimitRetry()
    {
        foreach (array(400, 401, 403, 451, 408, 429, 501) as $index => $code) {
            $host = '93.184.216.' . (100 + $index);
            $answer = 'HTTP/1.1 ' . $code . " Refused\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
            $response = $this->fetch($host, $answer);
            $this->assertSame(2, count($response['requests']), $code . ' is an answer, so both favicon locations are asked');
            $this->assertSame('404', $response['status'], 'an upstream refusal is a quiet local miss');
            $temporary = in_array($code, array(408, 429, 501), true);
            $this->assertTrue(is_file($this->trackersDir() . '/' . $host . ($temporary ? '.retry' : '.miss')),
                $code . ' receives the appropriate short or terminal marker');
            $this->assertTrue(!file_exists($this->trackersDir() . '/' . $host . ($temporary ? '.miss' : '.retry')),
                $code . ' receives only that marker');
        }
    }

    public function testSticky404DoesNotHideASecondPathResolverFailure()
    {
        $response = $this->fetch('retry.test', self::NOT_FOUND, 'second-unresolved');
        $this->assertSame(1, count($response['requests']), 'only the first path reaches the proxy before DNS fails');
        $this->assertTrue(is_file($this->trackersDir() . '/retry.test.retry'),
            'Snoopy retains status 404, but its second-path resolver error requires a short retry');
        $this->assertTrue(!file_exists($this->trackersDir() . '/retry.test.miss'),
            'an incomplete second request cannot be certified absent from the sticky first status');
    }

    public function testNoHttpAnswerOnTheFirstPathSkipsTheSecond()
    {
        $reset = $this->fetch(self::PUBLIC_HOST, '');
        $this->assertSame(array('GET http://' . self::PUBLIC_HOST . '/favicon.ico HTTP/1.0'), $reset['requests'],
            'a connection closed without an answer is not repeated for favicon.png');
        $this->assertSame('404', $reset['status'], 'a host without an HTTP answer is a quiet browser miss');
        $this->assertSame('1', (string) @file_get_contents($this->trackersDir() . '/' . self::PUBLIC_HOST . '.retry'),
            'a host without an HTTP answer is uncertain and gets the first retry delay');

        // Bound but not listening: every connection is refused while the test holds the port.
        $closed = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        if ($closed === false) throw new RuntimeException('refusing port fixture failed: ' . $error);
        try {
            $port = (int) substr(strrchr(stream_socket_get_name($closed, false), ':'), 1);
            $refused = $this->fetch('93.184.216.36', self::NOT_FOUND, '', false,
                array('proxyPort' => $port, 'attempts' => true));
        } finally {
            fclose($closed);
        }
        $this->assertSame(array('http://93.184.216.36/favicon.ico'), $refused['attempts'],
            'a refused connection is not repeated for favicon.png');
        $this->assertSame('1', (string) @file_get_contents($this->trackersDir() . '/93.184.216.36.retry'),
            'a refused port is uncertain and gets the first retry delay');

        $silent = $this->fetch('93.184.216.37', null);
        $this->assertSame(array('GET http://93.184.216.37/favicon.ico HTTP/1.0'), $silent['requests'],
            'a source that never answers is not asked a second time');
        $this->assertTrue($silent['elapsed'] >= 2.5 && $silent['elapsed'] < 4.5,
            'one silent request is abandoned after about three seconds: ' . round($silent['elapsed'], 2) . ' s');
        $this->assertSame('1', (string) @file_get_contents($this->trackersDir() . '/93.184.216.37.retry'),
            'a silent source gets the first retry delay');
        $this->assertSame(0, $this->pluginLogLines(), 'unreachable sources remain quiet');
    }

    public function testBurstFetchesAtMostTwoHostsAtOnceAndBusyRequestsAnswerAtOnce()
    {
        $slow = array('93.184.216.50', '93.184.216.51');
        $busy = array('93.184.216.52', '93.184.216.53', '93.184.216.54');
        $children = array();
        $held = array();
        try {
            // The upstream accepts the first fetches and does not answer them yet.
            foreach ($slow as $host) $children[$host] = $this->startFetch($host, array('timeout' => 30));
            $deadline = microtime(true) + 20;
            while (count($held) < 2 && microtime(true) < $deadline) $this->acceptHeld($held, 0.05);
            $this->assertSame(2, count($held), 'two fetches reach the slow upstream');
            foreach ($busy as $host) $children[$host] = $this->startFetch($host);
            $deadline = microtime(true) + 15;
            do {
                $this->acceptHeld($held, 0.05);
                $running = 0;
                foreach ($busy as $host) if ($this->finished($children[$host]) === null) $running++;
            } while ($running > 0 && microtime(true) < $deadline);
            $requests = array_column($held, 'request');
            sort($requests);
            $this->assertSame(array('GET http://93.184.216.50/favicon.ico HTTP/1.0',
                'GET http://93.184.216.51/favicon.ico HTTP/1.0'), $requests,
                'no third request reaches the upstream while two fetches are in flight');
            foreach ($slow as $host)
                $this->assertTrue($this->finished($children[$host]) === null,
                    'the slow fetch for ' . $host . ' still holds its slot');
            foreach ($busy as $host) {
                $result = $this->finished($children[$host]);
                $this->assertTrue(is_array($result), $host . ' is answered while both slots are held');
                if (!is_array($result)) continue;
                $this->assertSame(0, $result['exit'], 'a busy answer exits cleanly: ' . $result['err']);
                $this->assertSame('429', $result['status'], 'a request without a free slot is asked to come back');
                $this->assertSame('Favicon fetch busy', $result['out'], 'the busy answer has no placeholder body');
                $this->assertTrue($result['elapsed'] < 3,
                    'a busy answer does not wait for a slot: ' . round($result['elapsed'], 2) . ' s');
                $this->assertTrue(!file_exists($this->trackersDir() . '/' . $host . '.miss')
                    && !file_exists($this->trackersDir() . '/' . $host . '.retry'),
                    'a busy answer remembers nothing about ' . $host);
            }

            $this->answerHeld($held, self::NOT_FOUND);
            $this->serveUntilFinished($children, $slow, self::NOT_FOUND, 20);
            foreach ($slow as $host) {
                $result = $this->finished($children[$host]);
                $this->assertTrue(is_array($result) && $result['status'] === '404',
                    'the slow fetch for ' . $host . ' finishes once the upstream answers');
                $this->assertTrue(is_file($this->trackersDir() . '/' . $host . '.miss'),
                    'the slow fetch records its own outcome');
            }
            $later = $this->fetch($busy[0], self::NOT_FOUND);
            $this->assertSame(2, count($later['requests']), 'finished fetches released their slots');
            $this->assertSame('404', $later['status'], 'a host turned away while busy is fetched later');
            $this->assertSame(0, $this->pluginLogLines(), 'busy answers are not logged');
        } finally {
            $this->answerHeld($held, self::NOT_FOUND);
            $this->stopChildren($children);
        }
    }

    public function testTricklingHttpBodyCannotHoldAFetchSlotPastTheDeadline()
    {
        $children = array();
        $held = array();
        try {
            $children['drip'] = $this->startFetch('93.184.216.70', array('timeout' => 1));
            $deadline = microtime(true) + 5;
            while (count($held) === 0 && microtime(true) < $deadline) $this->acceptHeld($held, 0.05);
            $this->assertSame(1, count($held), 'the slow body fixture receives the actual favicon request');
            if (count($held) !== 1) return;
            fwrite($held[0]['peer'], "HTTP/1.1 200 OK\r\nContent-Length: 100\r\nConnection: close\r\n\r\n");
            // Each byte arrives well inside the read timeout. Only a total
            // deadline can release the worker while the response keeps arriving.
            $deadline = microtime(true) + 2;
            while ($this->finished($children['drip']) === null && microtime(true) < $deadline) {
                @fwrite($held[0]['peer'], 'x');
                usleep(100000);
            }
            $result = $this->finished($children['drip']);
            $this->assertTrue(is_array($result), 'a trickling response cannot extend the one-second fetch budget');
            if (!is_array($result)) return;
            $this->assertSame('404', $result['status'], 'an incomplete upstream image remains a quiet browser miss');
            $this->assertTrue($result['elapsed'] < 1.8, 'the total transfer budget is bounded: ' . $result['elapsed']);
            $this->assertSame('1', (string) @file_get_contents($this->trackersDir() . '/93.184.216.70.retry'),
                'a deadline failure is uncertain and keeps the short first retry');
            $this->assertTrue(!is_file($this->trackersDir() . '/93.184.216.70.ico'),
                'an incomplete trickling response cannot publish an icon');
        } finally {
            $this->answerHeld($held, '');
            $this->stopChildren($children);
        }
    }

    public function testRedirectAndPngFallbackShareTheOriginalDeadline()
    {
        foreach (array(false, true) as $redirect) {
            $host = $redirect ? '93.184.216.72' : '93.184.216.71';
            $children = array();
            $held = array();
            try {
                $children['fetch'] = $this->startFetch($host, array('timeout' => 1));
                $deadline = microtime(true) + 5;
                while (count($held) === 0 && microtime(true) < $deadline) $this->acceptHeld($held, 0.05);
                $this->assertSame(1, count($held), 'the first path reaches the proxy');
                if (count($held) !== 1) continue;
                usleep(650000);
                $answer = $redirect
                    ? "HTTP/1.1 302 Found\r\nLocation: //{$host}/landing\r\nContent-Length: 0\r\n\r\n"
                    : self::NOT_FOUND;
                $this->answerHeld($held, $answer);
                $deadline = microtime(true) + 0.6;
                while (count($held) < 2 && microtime(true) < $deadline) $this->acceptHeld($held, 0.02);
                $this->assertSame(2, count($held), 'the second path gets the remaining budget');
                if (count($held) !== 2) continue;
                $path = $redirect ? 'landing' : 'favicon.png';
                $this->assertSame("GET http://{$host}/{$path} HTTP/1.0", $held[1]['request'],
                    'an HTTP network-path redirect retains its scheme, and fallback names the PNG');
                fwrite($held[1]['peer'], "HTTP/1.1 200 OK\r\nContent-Length: 100\r\nConnection: close\r\n\r\n");
                $deadline = microtime(true) + 0.65;
                while ($this->finished($children['fetch']) === null && microtime(true) < $deadline) {
                    @fwrite($held[1]['peer'], 'x');
                    usleep(50000);
                }
                $result = $this->finished($children['fetch']);
                $this->assertTrue(is_array($result), 'redirects and fallback cannot restart the one-second budget');
                if (!is_array($result)) continue;
                $this->assertSame('404', $result['status'], 'the expired chain remains a quiet miss');
                $this->assertTrue($result['elapsed'] < 1.5, 'both paths share one deadline: ' . $result['elapsed']);
                $this->assertSame('1', (string) @file_get_contents($this->trackersDir() . '/' . $host . '.retry'),
                    'the expired chain is retryable');
            } finally {
                $this->answerHeld($held, '');
                $this->stopChildren($children);
            }
        }
    }

    public function testKilledFetchReleasesItsSlotToTheNextRequest()
    {
        $children = array();
        $held = array();
        try {
            foreach (array('93.184.216.60', '93.184.216.61') as $host)
                $children[$host] = $this->startFetch($host, array('timeout' => 30));
            $deadline = microtime(true) + 20;
            while (count($held) < 2 && microtime(true) < $deadline) $this->acceptHeld($held, 0.05);
            $this->assertSame(2, count($held), 'both slots are held by fetches waiting on the upstream');
            $children['93.184.216.62'] = $this->startFetch('93.184.216.62');
            $deadline = microtime(true) + 15;
            while ($this->finished($children['93.184.216.62']) === null && microtime(true) < $deadline)
                $this->acceptHeld($held, 0.05);
            $busy = $this->finished($children['93.184.216.62']);
            $this->assertTrue(is_array($busy) && $busy['status'] === '429', 'a third request finds no free slot');

            proc_terminate($children['93.184.216.60']['proc'], 9);
            $deadline = microtime(true) + 10;
            while ($this->finished($children['93.184.216.60']) === null && microtime(true) < $deadline) usleep(20000);
            $children['93.184.216.63'] = $this->startFetch('93.184.216.63', array('timeout' => 30));
            $deadline = microtime(true) + 15;
            while (count($held) < 3 && $this->finished($children['93.184.216.63']) === null
                && microtime(true) < $deadline)
                $this->acceptHeld($held, 0.05);
            $this->assertSame('GET http://93.184.216.63/favicon.ico HTTP/1.0',
                count($held) === 3 ? $held[2]['request'] : null,
                'the slot of a killed worker is free for the next request at once');
            $this->answerHeld($held, self::NOT_FOUND);
            $this->serveUntilFinished($children, array('93.184.216.61', '93.184.216.63'), self::NOT_FOUND, 20);
            foreach (array('93.184.216.61', '93.184.216.63') as $host) {
                $result = $this->finished($children[$host]);
                $this->assertTrue(is_array($result) && $result['status'] === '404', $host . ' finishes its fetch');
            }
        } finally {
            $this->answerHeld($held, self::NOT_FOUND);
            $this->stopChildren($children);
        }
    }

    public function testBusyAnswerOverHttpIs429WithRetryAfterAndNoMarker()
    {
        mkdir($this->trackersDir(), 0777, true);
        $locks = array();
        try {
            foreach (array(0, 1) as $slot) {
                $lock = fopen($this->trackersDir() . '/.fetch-slot-' . $slot . '.lock', 'c+b');
                $this->assertTrue($lock !== false && flock($lock, LOCK_EX | LOCK_NB), 'the test holds fetch slot ' . $slot);
                $locks[] = $lock;
            }
            $response = $this->httpRequest(array(),
                http_build_query(array('fetch' => '1', 'tracker' => self::PUBLIC_HOST)),
                array('Origin' => 'http://localhost', 'X-Requested-With' => 'XMLHttpRequest',
                    'Content-Type' => 'application/x-www-form-urlencoded'));
        } finally {
            foreach ($locks as $lock) fclose($lock);
        }
        $this->assertTrue(strpos($response['headers'], ' 429 ') !== false, 'a busy fetch answers HTTP 429');
        $this->assertTrue(stripos($response['headers'], "\r\nRetry-After: 30") !== false,
            'the busy answer names the cooldown the WebUI waits before it asks again');
        $this->assertSame('Favicon fetch busy', $response['out'], 'the busy answer has a readable body');
        $this->assertTrue(!file_exists($this->trackersDir() . '/' . self::PUBLIC_HOST . '.miss')
            && !file_exists($this->trackersDir() . '/' . self::PUBLIC_HOST . '.retry'),
            'a busy answer writes no negative cache marker');
        $this->assertSame(0, $this->pluginLogLines(), 'a busy answer is not logged');
    }

    public function testAnyLaterFetchSweepsADeadWorkerStage()
    {
        $stage = null;
        $this->fetch(self::PUBLIC_HOST, $this->imageAnswer(), '', false, array('checkpoint' =>
            function ($proc, $path) use (&$stage) { $stage = $path; proc_terminate($proc, 9); }));
        $this->assertTrue(is_string($stage) && is_file($stage) && is_file($stage . '.json'),
            'a worker killed before publication leaves its attested stage');
        touch($stage, time() - 7200);
        touch($stage . '.json', time() - 7200);
        $response = $this->fetch('93.184.216.35', self::NOT_FOUND);
        $this->assertSame('404', $response['status'], 'the later fetch ends in a miss without staging anything');
        $this->assertTrue(!file_exists($stage) && !file_exists($stage . '.json'),
            'a fetch that only writes a miss marker still sweeps the aged dead-worker stage');
    }

    public function testTempnamFallbackCannotPublishOutsideTheCacheDirectory()
    {
        $response = $this->fetch(self::PUBLIC_HOST, $this->imageAnswer(), '', false, array('fallback' => true));
        $this->assertSame('503', $response['status'], 'a tempnam fallback is a local cache fault');
        $this->assertTrue(!file_exists($this->trackersDir() . '/' . self::PUBLIC_HOST . '.ico'),
            'a staging file outside the cache directory is never published');
        $this->assertSame(1, substr_count($this->log(), 'tracklabels: favicon cache write failed'),
            'the fallback has a classified local diagnostic');
        $this->assertSame(array(), glob(sys_get_temp_dir() . '/.favicon-*'),
            'a fallback staging file is removed from the private test temp directory');
    }

    public function testDeadWorkerStageIsReapedButUnattestedFilesSurvive()
    {
        $stage = null;
        $this->fetch(self::PUBLIC_HOST, $this->imageAnswer(), '', false, array('checkpoint' =>
            function ($proc, $path) use (&$stage) { $stage = $path; proc_terminate($proc, 9); }));
        $this->assertTrue(is_string($stage) && is_file($stage),
            'killing the real worker after its write leaves the practical crash-stage artifact');
        $meta = $stage . '.json';
        $this->assertTrue(is_file($meta), 'the staged bytes carry an exact ownership record');
        touch($stage, time() - 7200);
        touch($meta, time() - 7200);
        $unknown = $this->trackersDir() . '/.favicon-legacy';
        file_put_contents($unknown, 'unattested old stage');
        touch($unknown, time() - 7200);
        $replacement = $stage . 'X';
        file_put_contents($replacement, 'unattested replacement');
        chmod($replacement, 0600);
        touch($replacement, time() - 7200);
        $record = json_decode(file_get_contents($meta), true);
        $record['name'] = basename($replacement);
        file_put_contents($replacement . '.json', json_encode($record));
        chmod($replacement . '.json', 0600);
        touch($replacement . '.json', time() - 7200);
        $response = $this->fetch('93.184.216.35', $this->imageAnswer());
        $this->assertSame($this->gif(), $response['out'], 'cleanup permits the next successful publication');
        $this->assertTrue(!file_exists($stage) && !file_exists($meta), 'an aged attested dead-worker stage is removed');
        $this->assertSame('unattested old stage', file_get_contents($unknown), 'legacy files are preserved');
        $this->assertSame('unattested replacement', file_get_contents($replacement),
            'a name with a mismatched ownership record is preserved');
        $this->assertTrue(is_file($replacement . '.json'), 'a mismatched record is also preserved');
    }

    public function testActiveStageSurvivesConcurrentCleanupDespiteOldTimestamp()
    {
        $this->fetch(self::PUBLIC_HOST, $this->imageAnswer(), '', false, array('checkpoint' =>
            function ($proc, $stage) {
                touch($stage, time() - 7200);
                touch($stage . '.json', time() - 7200);
                $other = $this->fetch('93.184.216.35', $this->imageAnswer());
                $this->assertSame($this->gif(), $other['out'], 'a competing worker publishes while this stage is active');
                $this->assertTrue(is_file($stage) && is_file($stage . '.json'),
                    'a held ownership lock protects an active stage even when its timestamps are stale');
            }));
        $this->assertSame($this->gif(), file_get_contents($this->trackersDir() . '/' . self::PUBLIC_HOST . '.ico'),
            'the paused worker can finish publication after the competing cleanup');
        $this->assertSame(array(), glob($this->trackersDir() . '/.favicon-*'), 'both finished workers remove their stages');
    }

    public function testPostPublicationCrashReapsOnlyAnAgedUnlockedOwnedRecord()
    {
        $records = array();
        $activeLock = null;
        try {
            foreach (array('aged', 'young', 'active', 'replacement', 'malformed') as $index => $fixture) {
                $stage = null;
                $host = '93.184.216.' . (40 + $index);
                $this->fetch($host, $this->imageAnswer(), '', false, array('published' => true, 'checkpoint' =>
                    function ($proc, $path) use (&$stage) { $stage = $path; proc_terminate($proc, 9); }));
                $this->assertSame($this->gif(), file_get_contents($this->trackersDir() . '/' . $host . '.ico'),
                    $fixture . ' kills the actual worker after successful publication');
                $this->assertTrue(is_string($stage) && !file_exists($stage) && is_file($stage . '.json'),
                    $fixture . ' leaves only the real worker ownership record');
                $record = $stage . '.json';
                touch($record, time() - ($fixture === 'young' ? 60 : 7200));
                if ($fixture === 'active') {
                    $activeLock = fopen($record, 'r+b');
                    $this->assertTrue(flock($activeLock, LOCK_EX | LOCK_NB), 'an active record owner holds the lock');
                } else if ($fixture === 'replacement') mkdir($stage, 0700);
                else if ($fixture === 'malformed') {
                    file_put_contents($record, '{}');
                    touch($record, time() - 7200);
                }
                $records[$fixture] = $record;
            }
            $response = $this->fetch('93.184.216.45', $this->imageAnswer());
            $this->assertSame($this->gif(), $response['out'], 'manifest cleanup permits the next publication');
            $this->assertTrue(!file_exists($records['aged']), 'an aged exact unlocked record with no stage is reclaimed');
            foreach (array('young', 'active', 'replacement', 'malformed') as $fixture)
                $this->assertTrue(is_file($records[$fixture]), $fixture . ' record is preserved');
            $this->assertTrue(is_dir(substr($records['replacement'], 0, -5)), 'an unknown stage replacement survives');
        } finally {
            if (is_resource($activeLock)) fclose($activeLock);
        }
    }

    public function testCleanupPreservesStagesWithoutCompleteFilesystemAdmission()
    {
        $preserved = array();
        foreach (array('young-stage', 'future-stage', 'young-record', 'future-record',
            'stage-mode', 'record-mode', 'stage-symlink', 'record-symlink',
            'stage-hardlink', 'record-hardlink', 'foreign-claim', 'stage-fifo') as $fixture) {
            $stage = null;
            $this->fetch(self::PUBLIC_HOST, $this->imageAnswer(), '', false, array('checkpoint' =>
                function ($proc, $path) use (&$stage) { $stage = $path; proc_terminate($proc, 9); }));
            $this->assertTrue(is_string($stage) && is_file($stage . '.json'),
                $fixture . ' starts with the actual crashed worker ownership record');
            $record = $stage . '.json';
            touch($stage, time() - 7200);
            touch($record, time() - 7200);
            if ($fixture === 'young-stage') touch($stage, time() - 60);
            else if ($fixture === 'future-stage') touch($stage, time() + 600);
            else if ($fixture === 'young-record') touch($record, time() - 60);
            else if ($fixture === 'future-record') touch($record, time() + 600);
            else if ($fixture === 'stage-mode') chmod($stage, 0644);
            else if ($fixture === 'record-mode') chmod($record, 0644);
            else if ($fixture === 'stage-fifo') {
                unlink($stage);
                if (!posix_mkfifo($stage, 0600)) throw new RuntimeException('FIFO fixture unavailable');
                clearstatcache(true, $stage);
                $stat = lstat($stage);
                $data = json_decode(file_get_contents($record), true);
                $data['dev'] = $stat['dev'];
                $data['ino'] = $stat['ino'];
                file_put_contents($record, json_encode($data));
                touch($stage, time() - 7200);
                touch($record, time() - 7200);
            }
            else if ($fixture === 'foreign-claim') {
                $data = json_decode(file_get_contents($record), true);
                $data['uid']++;
                file_put_contents($record, json_encode($data));
                touch($record, time() - 7200);
            } else {
                $path = strpos($fixture, 'stage-') === 0 ? $stage : $record;
                if (substr($fixture, -7) === 'symlink') {
                    rename($path, $path . '-saved');
                    symlink($path . '-saved', $path);
                } else link($path, $path . '-saved');
            }
            // Check every fixture after each later worker: a malformed object
            // must not be mistaken for an abandoned stage by the next fetch.
            $preserved[$fixture] = array($stage, $record);
        }
        $response = $this->fetch('93.184.216.35', $this->imageAnswer());
        $this->assertSame($this->gif(), $response['out'], 'refused cleanup candidates do not prevent publication');
        foreach ($preserved as $fixture => $paths) {
            $this->assertTrue(file_exists($paths[0]) && file_exists($paths[1]),
                $fixture . ' fails exact admission and remains untouched');
        }
    }

    public function testArrayTrackerIsRefusedWithoutAFatalError()
    {
        $response = $this->fetch(array('example.test'), self::NOT_FOUND);
        $this->assertSame(0, $response['exit'], 'an array tracker exits without a fatal error: ' . $response['err']);
        $this->assertSame('400', $response['status'], 'an array tracker is a bad request');
        $this->assertSame(array(), $response['requests'], 'an array tracker never reaches the network');
        $this->assertSame('Invalid tracker', $response['out'], 'an array tracker has a controlled error response');
    }

    public function testFetchedBytesStayPrivateUntilImageValidation()
    {
        $body = '<html>temporary upstream page</html>';
        $invalid = "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body)
            . "\r\nConnection: close\r\n\r\n" . $body;
        $response = $this->fetch(self::PUBLIC_HOST, array($invalid, self::NOT_FOUND), '', true);
        $this->assertSame(0, $response['exit'], 'non-image source exits cleanly: ' . $response['err']);
        $views = file($this->scratch . '/validation-view', FILE_IGNORE_NEW_LINES);
        $this->assertSame(array('null'), $views,
            'a concurrent icon reader cannot see fetched bytes before MIME validation');
        $this->assertSame('404', $response['status'], 'non-image content is refused');
        $this->assertTrue(!file_exists($this->trackersDir() . '/' . self::PUBLIC_HOST . '.ico'),
            'a non-image never becomes a published icon');
        $this->assertSame(array(), glob($this->trackersDir() . '/.favicon-*'),
            'rejected content leaves no temporary cache file');
    }

    public function testNulTrackerIsRefusedBeforeBuildingACachePath()
    {
        $response = $this->fetch("example.test\0", self::NOT_FOUND);
        $this->assertSame(0, $response['exit'], 'a NUL tracker exits without a fatal error: ' . $response['err']);
        $this->assertSame('400', $response['status'], 'a NUL tracker is a bad request');
        $this->assertSame(array(), $response['requests'], 'a NUL tracker never reaches the network');
        $this->assertSame('Invalid tracker', $response['out'], 'a NUL tracker has a controlled error response');
    }

    private function httpRequest($query, $body = '', $headers = array())
    {
        $router = $this->scratch . '/http.php';
        file_put_contents($router, '<?php $_ENV["RU_PROFILE_PATH"] = '
            . var_export($this->scratch . '/profile', true) . '; $_ENV["RU_LOG_FILE"] = '
            . var_export($this->scratch . '/errors.log', true) . '; $_ENV["RU_SCGI_HOST"] = '
            . var_export('unix://' . $this->scratch . '/absent.sock', true) . '; $_ENV["RU_SCGI_PORT"] = "0"; $route = '
            . var_export($this->root . '/plugins/tracklabels/action.php', true)
            . '; chdir(dirname($route)); require ' . var_export($this->root . '/php/util.php', true)
            // A favicon fetch, if one starts, goes to the test proxy and never to the network.
            . '; $httpProxy = array("use" => true, "proto" => "http", "host" => "127.0.0.1", "port" => '
            . (int) $this->port . '); require $route;');
        $http = AddTorrentHttpHarness::forDocumentRoot($this->scratch);
        try {
            $reply = $http->request($body === '' ? 'GET' : 'POST', '/http.php?' . http_build_query($query),
                $body, array_merge(array('Host' => 'localhost'), $headers));
            $this->assertSame(array(), $http->phpDiagnostics(), 'the HTTP route emits no PHP diagnostics');
            return array('headers' => $reply['status'] . "\r\n" . implode("\r\n", $reply['lines']),
                'out' => $reply['body']);
        } finally {
            $http->close();
        }
    }

    public function testEncodedForbiddenTrackerBytesAreRefusedAfterHttpDecoding()
    {
        foreach(array('extra%2F'.self::PUBLIC_HOST, 'example.test%00') as $encoded) {
            $response = $this->httpRequest(array(), 'fetch=1&tracker='.$encoded,
                array('Origin'=>'http://localhost', 'X-Requested-With'=>'XMLHttpRequest',
                    'Content-Type'=>'application/x-www-form-urlencoded'));
            $this->assertTrue(strpos($response['headers'], ' 400 ') !== false,
                'the actual HTTP decoder passes the forbidden byte to tracker validation');
            $this->assertSame('Invalid tracker', $response['out'], 'the request has a controlled refusal');
            $this->assertTrue(!file_exists($this->trackersDir()), 'refusal happens before acquiring cache resources');
        }
    }

    public function testInvalidLabelErrorCannotExecuteHtml()
    {
        $label = '../<script>alert(document.domain)</script>';
        $response = $this->httpRequest(array('label' => $label));
        $this->assertTrue(strpos($response['headers'], ' 400 ') !== false, 'an invalid label returns HTTP 400');
        $this->assertTrue(stripos($response['headers'], 'Content-Type: text/plain') !== false,
            'user-controlled validation errors are served as plain text');
        $this->assertSame('Invalid label: ' . $label, $response['out'],
            'the diagnostic remains readable without an HTML execution context');
    }

    public function testCanonicalAndLegacyRussianAppAliasesServeTheBundledImage()
    {
        $png = file_get_contents($this->root . '/plugins/tracklabels/labels/app.png');
        foreach (array('программы', 'трограммы') as $alias) {
            $response = $this->httpRequest(array('label' => $alias));
            $this->assertSame($png, $response['out'], $alias . ' resolves to the bundled application image');
        }
    }

    public function testMalformedUploadsAreControlledBadRequests()
    {
        foreach (array('missing', 'nested') as $fixture) {
            $body = "--favicon\r\nContent-Disposition: form-data; name=\"upload\"\r\n\r\n1\r\n";
            if ($fixture === 'nested')
                $body .= "--favicon\r\nContent-Disposition: form-data; name=\"uploadfile[]\"; filename=\"icon.png\""
                    . "\r\nContent-Type: image/png\r\n\r\n" . $this->gif() . "\r\n";
            $response = $this->httpRequest(array('label' => 'upload-fixture'), $body . "--favicon--\r\n",
                array('Origin' => 'http://localhost', 'X-Requested-With' => 'XMLHttpRequest',
                    'Content-Type' => 'multipart/form-data; boundary=favicon'));
            $this->assertTrue(strpos($response['headers'], ' 400 ') !== false,
                $fixture . ' upload returns a controlled HTTP 400');
            $this->assertSame('Invalid image upload', $response['out'],
                $fixture . ' upload returns a readable validation error');
            $this->assertTrue(!is_dir($this->scratch . '/profile/settings/labels'),
                $fixture . ' upload is refused before creating a destination directory');
        }
    }

    public function testValidPngUploadRemainsUsable()
    {
        $png = file_get_contents($this->root . '/plugins/tracklabels/labels/app.png');
        $body = "--favicon\r\nContent-Disposition: form-data; name=\"upload\"\r\n\r\n1\r\n"
            . "--favicon\r\nContent-Disposition: form-data; name=\"uploadfile\"; filename=\"icon.png\""
            . "\r\nContent-Type: image/png\r\n\r\n" . $png . "\r\n--favicon--\r\n";
        $response = $this->httpRequest(array('label' => 'upload-fixture'), $body,
            array('Origin' => 'http://localhost', 'X-Requested-With' => 'XMLHttpRequest',
                'Content-Type' => 'multipart/form-data; boundary=favicon'));
        $this->assertTrue(strpos($response['headers'], ' 200 ') !== false,
            'a scalar successful PNG upload is accepted');
        $this->assertSame($png, file_get_contents($this->scratch . '/profile/settings/labels/upload-fixture.png'),
            'a successful upload stores the exact user image');
        $read = $this->httpRequest(array('label' => 'upload-fixture'));
        $this->assertSame($png, $read['out'], 'the uploaded PNG remains readable through the icon route');
    }

    public function testCacheWriteFaultIsLoggedAndStaysRetryable()
    {
        // A regular file where the cache directory belongs defeats every writer, root included.
        file_put_contents($this->trackersDir(), 'not a directory');
        $response = $this->fetch(self::PUBLIC_HOST, $this->imageAnswer());
        $this->assertSame(0, $response['exit'], 'cache fault exits cleanly: ' . $response['err']);
        $this->assertSame('503', $response['status'], 'a local cache fault stays retryable');
        $this->assertSame(array(), $response['requests'],
            'without a fetch slot in the cache directory no worker waits on the source');
        $log = $this->log();
        $this->assertSame(1, substr_count($log, 'tracklabels: favicon fetch slot unavailable'),
            'the local cache fault is logged once with its classified reason');
        $this->assertSame(1, $this->pluginLogLines(), 'no second, expected-outcome line follows it');
        $this->assertTrue(strpos($log, self::PUBLIC_HOST) === false, 'the log line carries no remote text');
    }

    public function testDirectoryAtIconPathIsALocalCacheFault()
    {
        $icon = $this->trackersDir() . '/' . self::PUBLIC_HOST . '.ico';
        mkdir($icon, 0777, true);
        $response = $this->fetch(self::PUBLIC_HOST, $this->imageAnswer());
        $this->assertSame(0, $response['exit'], 'a cache directory collision exits cleanly: ' . $response['err']);
        $this->assertSame('503', $response['status'], 'a directory cannot be served as a successful cached icon');
        $this->assertSame(1, count($response['requests']), 'a directory collision does not masquerade as a cache hit');
        $this->assertSame(1, substr_count($this->log(), 'tracklabels: favicon cache write failed'),
            'publication failure has one classified local diagnostic');
        $this->assertTrue(is_dir($icon), 'the conflicting directory is preserved');
        $this->assertSame(array(), glob($this->trackersDir() . '/.favicon-*'),
            'failed publication removes its temporary file');
    }

    public function testMissMarkerWriteFaultIsLoggedAndStillA404()
    {
        mkdir($this->trackersDir(), 0777, true);
        // A marker name that leads into a missing directory cannot be created, root included.
        symlink($this->scratch . '/absent/marker', $this->trackersDir() . '/' . self::PUBLIC_HOST . '.miss');
        $response = $this->fetch(self::PUBLIC_HOST, self::NOT_FOUND);
        $this->assertSame(0, $response['exit'], 'marker fault exits cleanly: ' . $response['err']);
        $this->assertSame(2, count($response['requests']), 'both favicon paths were asked');
        $this->assertSame('404', $response['status'], 'the favicon is still unavailable, so the answer is 404');
        $log = $this->log();
        $this->assertSame(1, substr_count($log, 'tracklabels: favicon miss cache write failed'),
            'a miss marker that cannot be written is a logged local fault');
        $this->assertSame(1, $this->pluginLogLines(), 'the expected miss itself is not logged');
        $this->assertTrue(strpos($log, self::PUBLIC_HOST) === false, 'the log line carries no remote text');
    }
}
