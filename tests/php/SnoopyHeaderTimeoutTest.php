<?php

require_once(__DIR__ . '/../../php/Snoopy.class.inc');

// Use a real TCP peer in a separate PHP process; no pcntl extension is needed.
// The delay starts after it receives the request, not when the process starts.
function snoopyTimeoutResponse($client, $first, $late, $delay, $publicFetch = false, $proxyTunnel = false)
{
    $worker = <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, 'listen: ' . $error);
    exit(1);
}
echo stream_socket_get_name($server, false), "\n";
flush();
$peer = stream_socket_accept($server, 5);
if ($peer === false) exit(2);
stream_set_timeout($peer, 5);
do {
    $line = fgets($peer);
    if ($line === false) exit(3);
} while ($line !== "\r\n");
$first = base64_decode($argv[1]);
if ($first !== '') fwrite($peer, $first);
usleep((int) $argv[3]);
// A correct client has already closed on timeout; that is an expected write failure.
@fwrite($peer, base64_decode($argv[2]));
fclose($peer);
fclose($server);
PHP;
    $process = proc_open(
        array(PHP_BINARY, '-c', __DIR__ . '/../php-test.ini', '-r', $worker,
            base64_encode($first), base64_encode($late), (string) $delay),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    if (!is_resource($process)) throw new RuntimeException('Could not start the HTTP peer');
    $socket = false;
    try {
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 5);
        $address = fgets($pipes[1]);
        if ($address === false) throw new RuntimeException('The HTTP peer did not announce its address');
        $client->read_timeout = 1;
        $client->host = '127.0.0.1';
        if ($publicFetch) {
            if ($proxyTunnel) {
                $client->proxy_host = '127.0.0.1';
                $client->proxy_port = parse_url('http://' . trim($address), PHP_URL_PORT);
                $client->proxy_proto = 'http';
                $client->block_private = true;
                $client->private_allowlist = array('proxy.test');
                $fetched = $client->fetch('https://proxy.test/limited');
            } else {
                $fetched = $client->fetch('http://' . trim($address) . '/limited');
            }
        } else {
            $socket = stream_socket_client('tcp://' . trim($address), $errno, $error, 5);
            if ($socket === false) throw new RuntimeException('Could not connect to the HTTP peer: ' . $error);
            $fetched = $client->_httprequest('/', $socket, 'http://' . trim($address) . '/', 'GET');
            fclose($socket);
            $socket = false;
        }
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $process = null;
        if ($exit !== 0 || $stderr !== '')
            throw new RuntimeException('HTTP peer exited ' . $exit . ': ' . $stderr);
        return array('fetched' => $fetched, 'status' => $client->status,
            'timed_out' => $client->timed_out, 'body' => $client->results, 'error' => $client->error);
    } finally {
        if (is_resource($socket)) fclose($socket);
        if (is_resource($process)) {
            proc_terminate($process);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($process);
        }
    }
}

class SnoopyTimeoutThrottleClient extends Snoopy
{
    public $transfers = 0;
    public function connect() { ++$this->transfers; return parent::connect(); }
    public function _httpsrequest($url, $content_type = '', $body = '', $http_method = 'GET')
    {
        ++$this->transfers;
        return parent::_httpsrequest(...func_get_args());
    }
}

$failures = 0;
$cases = array(
    // A failed second read must not leave the first response's body available.
    'a late first response line clears the previous body' => array('', "HTTP/1.0 200 OK\r\n\r\nlate", 1300000,
        array(false, -100, true, '', '')),
    'a late next header clears the previous body' => array("HTTP/1.0 200 OK\r\n", "X-Late: yes\r\n\r\nlate", 1300000,
        array(false, -100, true, '', '')),
    // fgets() can return a partial line with its timeout flag already set.
    'a timed out partial header clears the previous body' => array('HTTP/1.0 200', " OK\r\n\r\nlate", 1300000,
        array(false, -100, true, '', '')),
    'a timed out body clears the previous body' => array("HTTP/1.0 200 OK\r\n\r\n", 'late', 1300000,
        array(false, -100, true, '', '')),
    'a second complete response replaces the previous body' => array('', "HTTP/1.0 200 OK\r\nX-Test: yes\r\n\r\ncomplete", 0,
        array(true, '200', false, 'complete', '')),
    'a second empty response replaces the previous body' => array('', "HTTP/1.0 200 OK\r\n\r\n", 0,
        array(true, '200', false, '', '')),
    'an oversized response keeps its distinct refusal' => array('', "HTTP/1.0 200 OK\r\n\r\noversized", 0,
        array(false, -101, false, '', 'oversized-response')),
    // Also pin body-read admission on a fresh client, independently of reuse cleanup.
    'a timed out partial body is refused' => array("HTTP/1.0 200 OK\r\n\r\ncomp", 'lete', 1300000,
        array(false, -100, true, '', ''), false),
    'a timed out partial chunk body is refused' => array("HTTP/1.0 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n8\r\ncomp", "lete\r\n0\r\n\r\n", 1300000,
        array(false, -100, true, '', ''), false),
    'a fragmented chunk body arriving before timeout is accepted' => array("HTTP/1.0 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n8\r\ncomp", "lete\r\n0\r\n\r\n", 100000,
        array(true, '200', false, 'complete', ''), false),
    'a fragmented body arriving before timeout is accepted' => array("HTTP/1.0 200 OK\r\n\r\ncomp", 'lete', 100000,
        array(true, '200', false, 'complete', ''), false),
);
foreach ($cases as $name => $case) {
    try {
        $client = new Snoopy();
        $client->maxlength = 8;
        if ($case[4] ?? true) {
            $seed = snoopyTimeoutResponse($client, '', "HTTP/1.0 200 OK\r\n\r\nprevious", 0);
            $seedExpected = array('fetched' => true, 'status' => '200', 'timed_out' => false,
                'body' => 'previous', 'error' => '');
            if ($seed !== $seedExpected)
                throw new RuntimeException('The first response must seed a real successful body: ' . json_encode($seed));
        }
        list($first, $late, $delay, $want) = $case;
        $seen = snoopyTimeoutResponse($client, $first, $late, $delay);
        $expected = array_combine(array('fetched', 'status', 'timed_out', 'body', 'error'), $want);
        if ($seen !== $expected)
            throw new RuntimeException('expected ' . json_encode($expected) . ', got ' . json_encode($seen));
        echo 'ok - ', $name, "\n";
    } catch (Throwable $error) {
        $failures++;
        echo 'not ok - ', $name, ': ', $error->getMessage(), "\n";
    }
}
// Completed 429 headers still own Retry-After when the body times out.
// Incomplete headers cannot publish a pause; all peers are loopback-only.
$throttleCases = array(
    'a plain body timeout retains the completed 429 pause' => array(false, false, true),
    'a chunked body timeout retains the completed 429 pause' => array(false, true, true),
    'a curl body timeout retains the completed 429 pause' => array(true, false, true),
    'an incomplete 429 socket header starts no pause' => array(false, false, false),
    'an incomplete 429 curl header starts no pause' => array(true, false, false),
    'a socket interim response starts no pause' => array(false, false, false, true),
    'a curl interim response starts no pause' => array(true, false, false, true),
    'a successful proxy CONNECT followed by TLS timeout preserves existing strikes' => array(true, false, false, false, true),
);
foreach ($throttleCases as $name => $case) {
    $previousProfile = $_ENV['RU_PROFILE_PATH'] ?? null;
    $previousLog = $_ENV['RU_LOG_FILE'] ?? null;
    $previousProfilePath = $GLOBALS['profilePath'] ?? null;
    $previousLogFile = $GLOBALS['log_file'] ?? null;
    $profile = sys_get_temp_dir() . '/timeout-pause-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($profile, 0700);
    $_ENV['RU_PROFILE_PATH'] = $profile;
    $_ENV['RU_LOG_FILE'] = $profile . '/application.log';
    $GLOBALS['profilePath'] = $profile;
    $GLOBALS['log_file'] = $profile . '/application.log';
    $group = 'timeout-pause-' . bin2hex(random_bytes(3));
    try {
        list($curl, $chunked, $complete) = $case;
        Snoopy::throttleHosts($group, array('127.0.0.1', 'proxy.test'), 0.01, 0.1, 10);
        $client = new SnoopyTimeoutThrottleClient();
        $client->block_private = false;
        $client->proxy_host = $client->proxy_port = '';
        $client->proxy_proto = $curl ? 'https' : 'http';
        $first = "HTTP/1.0 429 Too Many Requests\r\nRetry-After: 10\r\n";
        if ($complete) $first .= $chunked ? "Transfer-Encoding: chunked\r\n\r\n8\r\ncomp" : "\r\ncomp";
        if (!empty($case[3])) $first = "HTTP/1.1 103 Early Hints\r\nRetry-After: 3\r\n\r\n";
        $proxy = !empty($case[4]);
        $path = FileUtil::getSettingsPathEx('') . '/throttle-' . $group . '.json';
        if ($proxy) {
            if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
            file_put_contents($path, json_encode(array('next' => 0, 'ended' => 0, 'until' => 0, 'strikes' => 2)));
            $first = "HTTP/1.1 200 Arbitrary successful tunnel phrase\r\n\r\n";
        }
        $late = $chunked ? "lete\r\n0\r\n\r\n" : 'lete';
        $seen = snoopyTimeoutResponse($client, $first, $late, 1300000, true, $proxy);
        if ($seen['fetched'] !== false || $seen['status'] !== ($curl ? 28 : -100))
            throw new RuntimeException('The real peer must cause the transport timeout: ' . json_encode($seen));
        $state = json_decode(file_get_contents($path), true);
        if ($state['strikes'] !== ($proxy ? 2 : ($complete ? 1 : 0)))
            throw new RuntimeException('Only a completed final header block counts as a strike: ' . json_encode($state));
        if ($complete) {
            if ($state['until'] - $state['ended'] < 9.9)
                throw new RuntimeException('Retry-After must outlast the shorter base pause');
            if ($client->fetch('http://127.0.0.1:1/next') !== false
                || $client->error !== Snoopy::REQUEST_THROTTLED || $client->transfers !== 1)
                throw new RuntimeException('The next request must refuse before another transport');
        } elseif ($state['until'] != 0) {
            throw new RuntimeException('Incomplete response headers cannot publish a pause');
        }
        if ($client->headers !== array() || $client->response_code !== '' || $client->results !== '')
            throw new RuntimeException('A timeout must still discard all consumer response data');
        echo 'ok - ', $name, "\n";
    } catch (Throwable $error) {
        ++$failures;
        echo 'not ok - ', $name, ': ', $error->getMessage(), "\n";
    } finally {
        Snoopy::throttleHosts($group, array(), 0, 0, 0);
        if ($previousProfile === null) unset($_ENV['RU_PROFILE_PATH']);
        else $_ENV['RU_PROFILE_PATH'] = $previousProfile;
        if ($previousLog === null) unset($_ENV['RU_LOG_FILE']);
        else $_ENV['RU_LOG_FILE'] = $previousLog;
        if ($previousProfilePath === null) unset($GLOBALS['profilePath']);
        else $GLOBALS['profilePath'] = $previousProfilePath;
        if ($previousLogFile === null) unset($GLOBALS['log_file']);
        else $GLOBALS['log_file'] = $previousLogFile;
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($profile,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry)
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($profile);
    }
}
echo count($cases) + count($throttleCases), ' tests, ', $failures, " failures\n";
exit($failures ? 1 : 0);
