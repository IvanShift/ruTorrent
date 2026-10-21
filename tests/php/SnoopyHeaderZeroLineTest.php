<?php

require_once(__DIR__ . '/../../php/Snoopy.class.inc');

/**
 * Snoopy reads a response's header block a line at a time and keeps each line
 * in $headers, which is what its callers read a status, a Location or a
 * Content-Type out of. fgets() says "no more" by returning false; a line it
 * returns is a line that was there.
 *
 * One line is indistinguishable from false to a loop that reads while the
 * line is truthy: the single character '0', which only reaches the loop
 * without its terminator, so only when the peer closes straight after it.
 * That is a response the reader has every right to keep and report on -- a
 * peer that closed mid-header block is a thing that happens, and it is the
 * reader that decides what to do about it -- and the line is dropped from
 * $headers instead, with the loop ending as if the peer had sent nothing more
 * at all.
 *
 * The peer is a real listener in a separate process, so the lines are read off
 * a socket as they would be in use and not out of a scripted return value.
 */
function snoopyHeaderLineResponse($bytes)
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
fwrite($peer, base64_decode($argv[1]));
fclose($peer);
fclose($server);
PHP;
    $process = proc_open(
        array(PHP_BINARY, '-c', __DIR__ . '/../php-test.ini', '-r', $worker, base64_encode($bytes)),
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
        $socket = stream_socket_client('tcp://' . trim($address), $errno, $error, 5);
        if ($socket === false) throw new RuntimeException('Could not connect to the HTTP peer: ' . $error);
        $client = new Snoopy();
        $client->read_timeout = 2;
        $client->host = '127.0.0.1';
        $fetched = $client->_httprequest('/', $socket, 'http://' . trim($address) . '/', 'GET');
        fclose($socket);
        $socket = false;
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $process = null;
        if ($exit !== 0 || $stderr !== '')
            throw new RuntimeException('HTTP peer exited ' . $exit . ': ' . $stderr);
        return array('fetched' => $fetched, 'status' => $client->status,
            'headers' => $client->headers, 'body' => $client->results, 'error' => $client->error);
    } finally {
        if (is_resource($socket)) fclose($socket);
        if (is_resource($process)) {
            proc_terminate($process);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($process);
        }
    }
}

$failures = 0;
$cases = array(
    // Every byte of a header block that ends without its blank line has to be
    // accounted for in $headers, whatever the last line says.
    'a last header line of zero is kept' => array("HTTP/1.0 200 OK\r\nX-A: 1\r\n0", '200', ''),
    // A refusal discards every response field, including any incomplete header.
    'a lone zero without a final response is refused without response headers' => array(
        '0', Snoopy::RESPONSE_BODY_FAILED, '', false, 'missing-final-response'),
    'a last header line of one is kept' => array("HTTP/1.0 200 OK\r\nX-A: 1\r\n1", '200', ''),
    'a zero line inside a complete header block is kept' => array(
        "HTTP/1.0 200 OK\r\n0\r\nX-A: 1\r\n\r\nbody", '200', 'body'),
);
foreach ($cases as $name => $case) {
    try {
        list($bytes, $status, $body) = $case;
        $seen = snoopyHeaderLineResponse($bytes);
        // The header block is everything up to the blank line, which ends the
        // block and is not one of its lines.
        $block = explode("\r\n\r\n", $bytes, 2);
        $expected = array('fetched' => $case[3] ?? true, 'status' => $status,
            'headers' => $block[0] === '' ? array() : explode("\r\n", $block[0]), 'body' => $body,
            'error' => $case[4] ?? '');
        if (!$expected['fetched']) $expected['headers'] = array();
        foreach ($expected['headers'] as $line => $text) {
            // explode() drops the terminator fgets() keeps, except on the last
            // line of a block that has none.
            if (($line + 1) < count($expected['headers']) || isset($block[1]))
                $expected['headers'][$line] = $text . "\r\n";
        }
        if ($seen !== $expected)
            throw new RuntimeException('expected ' . json_encode($expected) . ', got ' . json_encode($seen));
        echo 'ok - ', $name, "\n";
    } catch (Throwable $error) {
        $failures++;
        echo 'not ok - ', $name, ': ', $error->getMessage(), "\n";
    }
}
echo count($cases), ' tests, ', $failures, " failures\n";
exit($failures ? 1 : 0);
