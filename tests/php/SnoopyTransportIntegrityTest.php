<?php

// A real loopback HTTP peer and the installed curl exercise both transports.
// No tracker, DNS lookup, account or scripted curl response is involved.
if (($argv[1] ?? '') === '--peer') {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($server === false) exit(2);
    fwrite(STDOUT, stream_socket_get_name($server, false) . "\n");
    fflush(STDOUT);
    $socket = @stream_socket_accept($server, 5);
    if ($socket === false) exit(3);
    stream_set_timeout($socket, 5);
    $headers = '';
    while (($line = fgets($socket)) !== false && $line !== "\r\n") $headers .= $line;
    $length = preg_match('/^Content-Length: *([0-9]+)\r?$/mi', $headers, $match) ? (int)$match[1] : 0;
    $body = '';
    while (strlen($body) < $length) {
        $part = fread($socket, $length - strlen($body));
        if ($part === false || $part === '') exit(4);
        $body .= $part;
    }
    $answer = $argv[2] === 'zero' ? '0' : 'ok';
    fwrite($socket, "HTTP/1.0 200 OK\r\nContent-Length: " . strlen($answer)
        . "\r\nConnection: close\r\n\r\n" . $answer);
    fclose($socket);
    fclose($server);
    echo json_encode(array('headers' => $headers, 'body' => base64_encode($body)));
    exit(0);
}

require_once(__DIR__ . '/TestCase.php');
$_ENV['RU_LOG_FILE'] = sys_get_temp_dir() . '/snoopy-integrity-' . getmypid() . '.log';
require_once(__DIR__ . '/../../php/Snoopy.class.inc');

function integrityRequest($curl, $method = 'GET', $body = '', $response = 'ok')
{
    $process = proc_open(array(PHP_BINARY, '-c', __DIR__ . '/../php-test.ini', __FILE__, '--peer', $response),
        array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    testAssertTrue(is_resource($process), 'The isolated HTTP peer starts');
    try {
        stream_set_timeout($pipes[1], 5);
        $address = trim((string) fgets($pipes[1]));
        testAssertSame(1, preg_match('/^127\.0\.0\.1:[0-9]+$/D', $address), 'The peer owns a loopback listener');
        $client = new Snoopy();
        $client->block_private = true;
        $client->private_allowlist = array('127.0.0.1');
        $client->proxy_host = $client->proxy_port = '';
        $client->read_timeout = 3;
        if ($curl) $client->setRequestDeadline(3);
        $ok = $client->fetch('http://' . $address . '/body', $method, 'text/plain', $body);
        $capture = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        unset($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        unset($pipes[2]);
        $code = proc_close($process);
        $process = null;
        testAssertSame(0, $code, 'The peer completes: ' . $errors);
        testAssertSame('', $errors, 'The peer has no runtime errors');
        $request = json_decode($capture, true);
        testAssertTrue(is_array($request) && isset($request['headers'], $request['body']), 'The peer captured the request');
        $request['body'] = base64_decode($request['body'], true);
        return array($ok, $client, $request);
    } finally {
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
}

$tests = array(
    'a literal zero HTTP response is a one-byte body' => function () {
        list($ok, $client) = integrityRequest(false, 'GET', '', 'zero');
        testAssertSame(true, $ok, 'The HTTP request succeeds');
        testAssertSame('0', $client->results, 'A falsey data chunk is retained');
    },
    'both transports send a literal zero request with its content length' => function () {
        foreach (array(false, true) as $curl) {
            list($ok, $client, $request) = integrityRequest($curl, 'POST', '0');
            testAssertSame(true, $ok, 'The POST succeeds');
            testAssertSame('0', $request['body'], 'The receiver reads the complete one-byte POST body');
            testAssertSame(1, preg_match('/^Content-Length: 1\r?$/mi', $request['headers']), 'The declared length includes zero');
        }
    },
    'curl sends an at-prefixed body literally without reading a local file' => function () {
        $file = tempnam(sys_get_temp_dir(), 'snoopy-body-canary-');
        try {
            $body = '@' . $file;
            // Match the wire Content-Length so the old file-reading path can
            // complete and expose its wrong bytes rather than merely time out.
            $canary = str_repeat('s', strlen($body));
            testAssertSame(strlen($canary), file_put_contents($file, $canary), 'The local file exists with distinct bytes');
            list($ok, $client, $request) = integrityRequest(true, 'POST', $body);
            testAssertSame(true, $ok, 'The POST succeeds');
            testAssertSame($body, $request['body'], 'The receiver gets the supplied body, never local file contents');
            testAssertSame($canary, file_get_contents($file), 'The local file remains unchanged');
        } finally {
            unlink($file);
        }
    },
    'malformed URL encoding leaves invalid input for a fetch refusal without throwing' => function () {
        foreach (array('http://example.test:99999/path', 'http://:') as $url) {
            testAssertSame($url, Snoopy::linkencode($url), 'Malformed URLs remain available for a classified fetch refusal');
        }
    },
    'a malformed URL cannot reuse an earlier successful response' => function () {
        list($ok, $client) = integrityRequest(false);
        testAssertSame(true, $ok, 'The reused client first receives a successful answer');
        foreach (array('http://example.test:99999/path', 'http://:') as $url) {
            testAssertSame(false, $client->fetch($url), 'The malformed URL is refused');
            testAssertSame(false, Snoopy::isSuccessfulResponse($client), 'An earlier successful response is not reused');
            testAssertSame('invalid-url', $client->error, 'The invalid target has a fixed failure reason');
            testAssertSame('', $client->results, 'The earlier response body is discarded');
        }
    },
);

try {
    $status = testRunCases($tests);
} finally {
    @unlink($_ENV['RU_LOG_FILE']);
}
exit($status);
