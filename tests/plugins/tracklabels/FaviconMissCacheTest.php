<?php

require_once(__DIR__ . '/../../php/TestCase.php');

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
$_POST = array('fetch' => '1', 'tracker' => getenv('ROUTE_TRACKER'));
$_REQUEST = $_POST;
register_shutdown_function(function () {
    file_put_contents(getenv('ROUTE_STATUS_FILE'), (string) http_response_code());
});
$route = getenv('ROUTE_FILE');
chdir(dirname($route));
require getenv('ROUTE_ROOT') . '/php/util.php';
$httpProxy = array('use' => true, 'proto' => 'http',
    'host' => '127.0.0.1', 'port' => (int) getenv('ROUTE_PROXY_PORT'));
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

    // Runs one favicon fetch for $tracker. Every request that reaches the
    // fake proxy gets $answer; their request lines are returned in order.
    private function fetch($tracker, $answer)
    {
        $statusFile = $this->scratch . '/status';
        @unlink($statusFile);
        $env = array(
            'RU_PROFILE_PATH' => $this->scratch . '/profile',
            'RU_LOG_FILE' => $this->scratch . '/errors.log',
            // No daemon: settings lookups fail fast instead of probing a TCP port.
            'RU_SCGI_HOST' => 'unix://' . $this->scratch . '/absent.sock',
            'RU_SCGI_PORT' => '0',
            'ROUTE_ROOT' => $this->root,
            'ROUTE_FILE' => $this->root . '/plugins/tracklabels/action.php',
            'ROUTE_TRACKER' => $tracker,
            'ROUTE_PROXY_PORT' => (string) $this->port,
            'ROUTE_STATUS_FILE' => $statusFile,
        );
        $proc = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', $this->scratch . '/request.php'),
            array(0 => array('pipe', 'r'),
                1 => array('file', $this->scratch . '/out', 'w'),
                2 => array('file', $this->scratch . '/err', 'w')),
            $pipes, null, $env);
        if (!is_resource($proc)) throw new RuntimeException('route child did not start');
        fclose($pipes[0]);
        $requests = array();
        $exit = null;
        $deadline = microtime(true) + 30;
        try {
            while (true) {
                $state = proc_get_status($proc);
                if (!$state['running']) {
                    $exit = $state['exitcode'];
                    break;
                }
                if (microtime(true) > $deadline) throw new RuntimeException('route child did not finish');
                $read = array($this->server);
                $write = $except = null;
                if (@stream_select($read, $write, $except, 0, 50000) < 1) continue;
                $peer = @stream_socket_accept($this->server, 1);
                if ($peer === false) continue;
                stream_set_timeout($peer, 2);
                $head = '';
                while (strpos($head, "\r\n\r\n") === false && strlen($head) < 8192) {
                    $piece = fread($peer, 4096);
                    if ($piece === false || $piece === '') break;
                    $head .= $piece;
                }
                $requests[] = strtok($head, "\r\n");
                fwrite($peer, $answer);
                fclose($peer);
            }
        } finally {
            if ($exit === null) proc_terminate($proc, 9);
            proc_close($proc);
        }
        clearstatcache();
        return array(
            'exit' => $exit,
            'status' => is_file($statusFile) ? file_get_contents($statusFile) : '',
            'out' => file_get_contents($this->scratch . '/out'),
            'err' => file_get_contents($this->scratch . '/err'),
            'requests' => $requests,
        );
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

    public function testCacheWriteFaultIsLoggedAndStaysRetryable()
    {
        // A regular file where the cache directory belongs defeats every writer, root included.
        file_put_contents($this->trackersDir(), 'not a directory');
        $response = $this->fetch(self::PUBLIC_HOST, $this->imageAnswer());
        $this->assertSame(0, $response['exit'], 'cache fault exits cleanly: ' . $response['err']);
        $this->assertSame('503', $response['status'], 'a local cache fault stays retryable');
        $log = $this->log();
        $this->assertSame(1, substr_count($log, 'tracklabels: favicon cache write failed'),
            'the local cache fault is logged once with its classified reason');
        $this->assertSame(1, $this->pluginLogLines(), 'no second, expected-outcome line follows it');
        $this->assertTrue(strpos($log, self::PUBLIC_HOST) === false, 'the log line carries no remote text');
    }

    public function testMissMarkerWriteFaultIsLoggedAndStillA404()
    {
        file_put_contents($this->trackersDir(), 'not a directory');
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
