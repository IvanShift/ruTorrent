<?php

require_once(__DIR__ . '/TestCase.php');

// Keep this suite's diagnostics out of the shared application log.
$_ENV['RU_LOG_FILE'] = sys_get_temp_dir() . '/snoopy-core-' . getmypid() . '.log';

// Deliberately not using tests/plugins/rutracker_check/TestLib.php here:
// Snoopy.class.inc transitively loads php/settings.php -> php/xmlrpc.php,
// whose real rXMLRPC* classes collide with TestLib's doubles in either
// require order. A minimal local runner keeps the real classes intact.
require_once(__DIR__ . '/../../php/Snoopy.class.inc');

function snoopyCurlArgs()
{
    return file(getenv('SNOOPY_TEST_ARGS'), FILE_IGNORE_NEW_LINES);
}

function snoopyRequestHasCookie($request, $pair)
{
    foreach (preg_split('/\r?\n/', $request) as $line) {
        if (strncasecmp($line, 'Cookie:', 7) !== 0) continue;
        foreach (explode(';', substr($line, 7)) as $entry)
            if (trim($entry) === $pair) return true;
    }
    return false;
}

function snoopyCookieHeaderValues($wire)
{
    $lines = is_array($wire) ? $wire : preg_split('/\r?\n/', $wire);
    $headers = array();
    foreach ($lines as $line) {
        if (strncasecmp($line, 'Cookie:', 7) === 0)
            $headers[] = trim(substr($line, 7));
    }
    return $headers;
}

// Fake curl: records every argument, then fabricates a successful response.
// $SNOOPY_TEST_ARGS holds the arguments of the LAST invocation only, so a
// redirect test reads the request Snoopy made after following the redirect.
// With $SNOOPY_TEST_REDIRECT set, the first invocation answers 302 with that
// Location instead; $SNOOPY_TEST_SEEN is how the script remembers it did.
$curlPath = tempnam(sys_get_temp_dir(), 'snoopy-curl-');
$argsPath = tempnam(sys_get_temp_dir(), 'snoopy-args-');
$bodyPath = tempnam(sys_get_temp_dir(), 'snoopy-body-');
$seenPath = sys_get_temp_dir() . '/snoopy-seen-' . getmypid();
$script = <<<'SH'
#!/bin/sh
: > "$SNOOPY_TEST_ARGS"
header_file=
body_file=
while [ "$#" -gt 0 ]; do
	printf '%s\n' "$1" >> "$SNOOPY_TEST_ARGS"
	case "$1" in
		-D)
			shift
			header_file=$1
			;;
		-o)
			shift
			body_file=$1
			;;
	esac
	shift
done
if [ -n "$SNOOPY_TEST_REDIRECT" ] && [ ! -f "$SNOOPY_TEST_SEEN" ]; then
	: > "$SNOOPY_TEST_SEEN"
	if [ -n "$SNOOPY_TEST_INTERIM_COOKIE" ]; then
		printf 'HTTP/1.1 200 Connection established\r\nSet-Cookie: %s\r\n\r\n' "$SNOOPY_TEST_INTERIM_COOKIE" > "$header_file"
	else
		: > "$header_file"
	fi
	printf 'HTTP/1.1 302 Found\r\nLocation: %s\r\n' "$SNOOPY_TEST_REDIRECT" >> "$header_file"
	if [ -n "$SNOOPY_TEST_REDIRECT_COOKIE" ]; then
		printf 'Set-Cookie: %s\r\n' "$SNOOPY_TEST_REDIRECT_COOKIE" >> "$header_file"
	fi
	if [ -n "$SNOOPY_TEST_REDIRECT_COOKIE2" ]; then
		printf 'Set-Cookie: %s\r\n' "$SNOOPY_TEST_REDIRECT_COOKIE2" >> "$header_file"
	fi
	printf '\r\n' >> "$header_file"
else
	printf '%b\r\n' "${SNOOPY_TEST_RESPONSE:-HTTP/1.1 200 OK\r\n}" > "$header_file"
fi
if [ -n "$SNOOPY_TEST_OMIT_BODY" ]; then
	# Real curl does not create -o for a bodyless 304 (captured in V-13).
	:
elif [ -n "$SNOOPY_TEST_BODY_FILE" ]; then
	cp "$SNOOPY_TEST_BODY_FILE" "$body_file"
else
	: > "$body_file"
fi
if [ -n "$SNOOPY_TEST_EXIT" ]; then
	exit "$SNOOPY_TEST_EXIT"
fi
SH;
file_put_contents($curlPath, $script);
chmod($curlPath, 0700);
putenv('SNOOPY_TEST_ARGS=' . $argsPath);
putenv('SNOOPY_TEST_SEEN=' . $seenPath);
$pathToExternals['curl'] = $curlPath;

function snoopyRespondWith($response)
{
    putenv('SNOOPY_TEST_RESPONSE=' . $response);
}

// Hostname resolution is stubbed so the SSRF tests never depend on live DNS.
// Literal addresses still go through the real path, so a redirect to one is
// judged on its own merits.
class SnoopyResolvesToPublic extends Snoopy
{
    static public function resolveHost($host)
    {
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return parent::resolveHost($host);
        }
        return array('93.184.216.34');
    }
}

class SnoopyProxyTargetProbe extends SnoopyResolvesToPublic
{
    public $requestTarget;
    public $requestHost;
    public function connect() { return fopen('php://memory', 'r+'); }
    public function _httprequest($url, $fp, $URI, $method, $content_type = '', $body = '')
    {
        $this->requestTarget = $url;
        $this->requestHost = $this->host;
        $this->status = 200;
        return true;
    }
}

// Records the live fetch() policy without network or another plugin's test harness.
class SnoopyWithoutZlib extends Snoopy
{
    protected function hasZlibGzip() { return false; }
}

class SnoopyRedirectProbe extends Snoopy
{
    public $replies = array();
    public $requests = array();
    public function connect() { return fopen('php://memory', 'r+'); }
    public function _httprequest($url, $fp, $URI, $method, $content_type = '', $body = '')
    {
        return $this->_httpsrequest($URI, $content_type, $body, $method);
    }
    public function _httpsrequest($url, $content_type = '', $body = '', $method = 'GET')
    {
        if ($this->passcookies && $this->_redirectaddr) {
            $this->setcookies();
        }
        $this->requests[] = array($url, $this->cookies, $this->user, $this->pass, $method, $body, $this->rawheaders);
        $reply = array_shift($this->replies);
        $this->_redirectaddr = $reply[1];
        $this->headers = $reply[2];
        $this->status = $reply[3];
        $this->results = '';
        return $reply[0];
    }
}

class SnoopyRejectedRedirectProbe extends SnoopyRedirectProbe
{
    protected function checkTarget($host)
    {
        if ($host === 'blocked.test') {
            $this->error = 'blocked test target';
            return false;
        }
        return true;
    }
}

// Keep the real HTTP request and response parser while replacing only TCP.
class SnoopySocketReplies extends Snoopy
{
    public $responses = array();
    private $peers = array();

    public function connect()
    {
        if (!$this->responses) {
            throw new RuntimeException('Unexpected HTTP connection');
        }
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        testAssertTrue(is_array($pair), 'Unable to create the HTTP socket pair');
        $response = array_shift($this->responses);
        testAssertSame(strlen($response), fwrite($pair[1], $response),
            'Complete HTTP fixture response was written');
        stream_socket_shutdown($pair[1], STREAM_SHUT_WR);
        $this->peers[] = $pair[1];
        return $pair[0];
    }

    public function request($index)
    {
        testAssertTrue(isset($this->peers[$index]), 'Expected HTTP request was sent');
        $request = stream_get_contents($this->peers[$index]);
        fclose($this->peers[$index]);
        unset($this->peers[$index]);
        return $request;
    }
}

// With $peerStaysOpen the peer sends the reply and then holds the connection
// without closing it, so the client's read ends only by its read_timeout.
function snoopyPlainHttpReply($header, $body, $limit = null, $client = null, $peerStaysOpen = false)
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    testAssertTrue(is_array($pair), 'Unable to open gzip transport fixture');
    list($near, $far) = $pair;
    fwrite($far, "HTTP/1.1 200 OK\r\n".$header."\r\n\r\n".$body);
    if(!$peerStaysOpen) stream_socket_shutdown($far, STREAM_SHUT_WR);
    if($client === null) $client = new Snoopy();
    $client->host = 'tracker.example';
    $client->port = 80;
    if($limit !== null) $client->maxlength = $limit;
    try {
        $result = $client->_httprequest('/file', $near, 'http://tracker.example/file', 'GET');
    } finally {
        fclose($near);
        fclose($far);
    }
    return array($result, $client);
}

// One gzip member holding $size zero bytes, built a megabyte at a time so the
// suite never holds the inflated size itself: 64 MiB of zeros is about 64 KiB
// of gzip, which is the shape of answer that inflates past memory_limit.
function snoopyGzipZeros($size)
{
    $deflater = deflate_init(ZLIB_ENCODING_GZIP, array('level' => 9));
    $chunk = str_repeat("\0", 1048576);
    $encoded = '';
    while ($size > 0) {
        $piece = $size >= strlen($chunk) ? $chunk : substr($chunk, 0, $size);
        $size -= strlen($piece);
        $encoded .= deflate_add($deflater, $piece, $size > 0 ? ZLIB_NO_FLUSH : ZLIB_FINISH);
    }
    return $encoded;
}

function snoopyGzipResponse($body)
{
    return "HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\n\r\n" . $body;
}

// A loopback PHP server answering prepared gzip bodies on the plain-HTTP path,
// and a child PHP that fetches from it under a memory_limit of its own, so a
// body that inflates without bound ends the child and not this suite. The
// server command starts with exec so proc_terminate reaches PHP rather than
// the shell that started it. The router names its own directory with
// __FILE__. Both children get the suite's php-test.ini, as the suite runner
// passes it, so a developer's system php.ini cannot change what they measure.
class SnoopyGzipServer
{
    const SUITE_INI = __DIR__ . '/../php-test.ini';
    public $address;
    private $directory;
    private $process = null;
    private $log;
    private $files = array();

    public function __construct($bodies)
    {
        $this->directory = tempnam(sys_get_temp_dir(), 'snoopy-gzip-');
        unlink($this->directory);
        mkdir($this->directory, 0700);
        foreach ($bodies as $name => $body) {
            $this->write($name . '.gz', $body);
        }
        $router = $this->write('router.php', <<<'PHP'
<?php
$file = dirname(__FILE__) . '/' . basename($_SERVER['REQUEST_URI']) . '.gz';
if (!is_file($file)) {
    http_response_code(404);
    exit;
}
header('Content-Type: application/octet-stream');
header('Content-Encoding: gzip');
readfile($file);
PHP
        );
        $this->log = $this->write('server.log', '');
        try {
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            testAssertTrue($socket !== false, 'Could not reserve a loopback port: ' . $error);
            $this->address = stream_socket_get_name($socket, false);
            fclose($socket);
            $this->process = proc_open(
                'exec ' . escapeshellarg(PHP_BINARY) . ' -c ' . escapeshellarg(self::SUITE_INI)
                . ' -S ' . escapeshellarg($this->address)
                . ' -t ' . escapeshellarg($this->directory) . ' ' . escapeshellarg($router),
                array(0 => array('pipe', 'r'), 1 => array('file', $this->log, 'a'), 2 => array('file', $this->log, 'a')),
                $pipes
            );
            testAssertTrue(is_resource($this->process), 'Could not start the loopback server');
            fclose($pipes[0]);
            $deadline = microtime(true) + 5;
            do {
                $connection = @stream_socket_client('tcp://' . $this->address, $errno, $error, 0.1);
                if ($connection !== false) {
                    fclose($connection);
                    return;
                }
                usleep(10000);
            } while (microtime(true) < $deadline && proc_get_status($this->process)['running']);
            throw new RuntimeException('The loopback server did not become ready: ' . file_get_contents($this->log));
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    private function write($name, $content)
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, $content);
        $this->files[] = $path;
        return $path;
    }

    // What the child saw: fetch()'s answer, status, error and the body's
    // length and digest. A child that died first -- which on an unbounded
    // inflate is memory_limit ending it with a fatal error -- is reported with
    // everything it printed.
    public function fetchInChild($path, $memoryLimit, $maxlength = '')
    {
        $script = $this->write('fetch.php', <<<'PHP'
<?php
require_once($argv[1]);
$client = new Snoopy();
$client->read_timeout = 10;
if ($argv[3] !== '') {
    $client->maxlength = (int) $argv[3];
}
$fetched = $client->fetch($argv[2]);
echo json_encode(array(
    'fetched' => $fetched,
    'status' => $client->status,
    'error' => $client->error,
    'length' => is_string($client->results) ? strlen($client->results) : null,
    'sha1' => is_string($client->results) ? sha1($client->results) : null,
    'peak' => memory_get_peak_usage(true),
));
PHP
        );
        $process = proc_open(
            array(PHP_BINARY, '-c', self::SUITE_INI, '-d', 'memory_limit=' . $memoryLimit, $script,
                __DIR__ . '/../../php/Snoopy.class.inc', 'http://' . $this->address . $path, (string) $maxlength),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        testAssertTrue(is_resource($process), 'Could not start the fetching child');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $seen = json_decode($stdout, true);
        if (!is_array($seen)) {
            throw new RuntimeException(
                'The child exited ' . $exit . ' without answering; it printed: '
                . trim($stdout . "\n" . $stderr)
            );
        }
        return $seen;
    }

    public function close()
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }
}

function snoopyAssertPrefixRefused($cookie, $redirect = 'https://prefix.test/next')
{
    putenv('SNOOPY_TEST_REDIRECT=' . $redirect);
    putenv('SNOOPY_TEST_REDIRECT_COOKIE=' . $cookie);
    @unlink(getenv('SNOOPY_TEST_SEEN'));
    try {
        $client = new Snoopy();
        testAssertSame(true, $client->fetch('https://prefix.test/start'),
            'redirect response completes');
        testAssertSame(false,
            snoopyRequestHasCookie(implode("\r\n", snoopyCurlArgs()), explode('=', $cookie, 2)[0] . '=spoof'),
            'invalid prefixed cookie stays off the next wire');
    } finally {
        putenv('SNOOPY_TEST_REDIRECT');
        putenv('SNOOPY_TEST_REDIRECT_COOKIE');
        @unlink(getenv('SNOOPY_TEST_SEEN'));
    }
}

$tests = array(
    'real refused TCP connections are transport failures on both socket paths' => function () {
        // Reserve a port without listening so refusal is local, immediate and race-free.
        $bound = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        testAssertTrue(is_resource($bound), 'The refusal fixture reserved a TCP port');
        try {
            $address = stream_socket_get_name($bound, false);
            $port = (int) substr(strrchr($address, ':'), 1);
            foreach (array(null, '127.0.0.1') as $bind) {
                $client = new Snoopy();
                $client->host = '127.0.0.1';
                $client->port = $port;
                $client->IP = $bind;
                $client->_fp_timeout = 0.5;
                testAssertSame(false, @$client->connect(), 'The real TCP connection was refused');
                testAssertTrue($client->status < 0 && $client->status !== -100
                    && $client->status !== Snoopy::RESPONSE_BODY_FAILED,
                    'Connect refusal is neither HTTP, read timeout nor body refusal');
                testAssertSame(1, preg_match('/^connection failed \([1-9][0-9]*\)$/D', $client->error),
                    'The original positive OS errno remains in the diagnostic message');
            }
        } finally {
            fclose($bound);
        }
    },
    'the captured live RuTracker HTTPS 304 is accepted without a curl output file' => function () {
        $capture = file_get_contents(__DIR__.'/fixtures/rutracker-https-304/conditional.headers');
        snoopyRespondWith($capture);
        putenv('SNOOPY_TEST_OMIT_BODY=1');
        try {
            $client = new Snoopy();
            testAssertSame(true, $client->fetch('https://api.rutracker.cc/v1/static/pvc/f/7'),
                'the real public API 304 with representation gzip metadata is accepted verbatim');
            testAssertSame(304, (int)$client->status, 'captured final status is retained');
            testAssertSame('"6ac4c7cd-155"', trim($client->get_header('ETag')),
                'the captured conditional ETag is retained');
            testAssertSame('', $client->results, 'curl did not emit a representation body');
        } finally {
            putenv('SNOOPY_TEST_OMIT_BODY');
            snoopyRespondWith('');
        }
    },
    'curl 304 without an output file retains the final status and ETag' => function () {
        snoopyRespondWith('HTTP/1.1 200 Connection established\r\n\r\n'
            . 'HTTP/2 304\r\nETag: "forum-capture"\r\nContent-Encoding: gzip\r\n');
        putenv('SNOOPY_TEST_OMIT_BODY=1');
        try {
            $client = new Snoopy();
            $client->results = 'previous response must not survive';
            testAssertSame(true, $client->fetch('https://tracker.test/forum'),
                'the captured curl bodyless-file behavior is accepted');
            testAssertSame(304, (int)$client->status, 'the final 304 is retained');
            testAssertSame('"forum-capture"', trim($client->get_header('ETag')),
                'conditional cache validation can read the actual ETag');
            testAssertSame('', $client->results, '304 never reuses an earlier body');
            testAssertSame('', $client->error, 'no invented gzip body is decoded');
        } finally {
            putenv('SNOOPY_TEST_OMIT_BODY');
            snoopyRespondWith('');
        }
    },
    'curl accepts missing body output only for a bodyless response' => function () {
        putenv('SNOOPY_TEST_OMIT_BODY=1');
        try {
            foreach (array(array(204, 'GET'), array(205, 'GET'), array(200, 'HEAD')) as $case) {
                snoopyRespondWith('HTTP/1.1 '.$case[0].' Test\r\nContent-Encoding: gzip\r\n');
                $client = new Snoopy();
                testAssertSame(true, $client->fetch('https://tracker.test/empty', $case[1]),
                    $case[1].' '.$case[0].' has no representation body');
                testAssertSame($case[0], (int)$client->status, 'status survives without a file');
                testAssertSame('', $client->results, 'bodyless response stays empty');
            }
            snoopyRespondWith('HTTP/1.1 200 OK\r\n');
            $client = new Snoopy();
            testAssertSame(false, $client->fetch('https://tracker.test/missing'),
                'a missing ordinary GET body remains a transport refusal');
            testAssertSame('unreadable-or-oversized-response', $client->error,
                'the ordinary missing file remains classified');
        } finally {
            putenv('SNOOPY_TEST_OMIT_BODY');
            snoopyRespondWith('');
        }
    },
    'a valid parent Domain cookie follows its sibling without port isolation' => function () {
        putenv('SNOOPY_TEST_REDIRECT=https://dl.katcr.co/private/file');
        putenv('SNOOPY_TEST_REDIRECT_COOKIE=id=parent; Domain=.katcr.co; Path=/; Secure');
        @unlink(getenv('SNOOPY_TEST_SEEN'));
        try {
            $client = new Snoopy();
            testAssertSame(true, $client->fetch('https://www.katcr.co:8443/start'),
                'anonymous HTTPS redirect completes');
            testAssertSame(true, in_array('Cookie: id=parent', snoopyCurlArgs(), true),
                'valid parent Domain cookie reaches a sibling on a different port');
            testAssertSame(array(), $client->cookies,
                'response cookie is not flattened into the public facade');
        } finally {
            putenv('SNOOPY_TEST_REDIRECT');
            putenv('SNOOPY_TEST_REDIRECT_COOKIE');
            @unlink(getenv('SNOOPY_TEST_SEEN'));
        }
    },
    'parent Domain does not match a mere leading-label string' => function () {
        putenv('SNOOPY_TEST_REDIRECT=https://dl.katcr.co/next');
        putenv('SNOOPY_TEST_REDIRECT_COOKIE=id=wrong; Domain=.katcr.co; Path=/; Secure');
        @unlink(getenv('SNOOPY_TEST_SEEN'));
        try {
            $client = new Snoopy();
            testAssertSame(true, $client->fetch('https://evilkatcr.co/start'),
                'anonymous redirect completes without a credential');
            testAssertSame(false, in_array('Cookie: id=wrong', snoopyCurlArgs(), true),
                'Domain suffix requires a DNS label boundary');
        } finally {
            putenv('SNOOPY_TEST_REDIRECT');
            putenv('SNOOPY_TEST_REDIRECT_COOKIE');
            @unlink(getenv('SNOOPY_TEST_SEEN'));
        }
    },
    'public and private suffix Domain cookies are visibly refused' => function () {
        global $log_file;
        $old = $log_file;
        $log_file = tempnam(sys_get_temp_dir(), 'snoopy-domain-refusal-');
        try {
            foreach (array(
                array('https://a.co.uk/start', 'https://b.co.uk/next', 'Domain=.co.uk'),
                array('https://user.github.io/start', 'https://other.github.io/next', 'Domain=.github.io'),
            ) as $case) {
                putenv('SNOOPY_TEST_REDIRECT=' . $case[1]);
                putenv('SNOOPY_TEST_REDIRECT_COOKIE=id=wrong; Path=/; Secure; ' . $case[2]);
                @unlink(getenv('SNOOPY_TEST_SEEN'));
                $client = new Snoopy();
                testAssertSame(true, $client->fetch($case[0]), 'anonymous sibling redirect completes');
                testAssertSame(false, in_array('Cookie: id=wrong', snoopyCurlArgs(), true),
                    'public suffix Domain cookie stays off sibling wire');
            }
            $log = file_get_contents($log_file);
            testAssertSame(2, substr_count($log, 'response-cookie-domain-refused'),
                'each rejected response host is visible without a cookie value');
            testAssertSame(false, strpos($log, 'id=wrong') !== false,
                'refusal log does not contain cookie content');
        } finally {
            putenv('SNOOPY_TEST_REDIRECT');
            putenv('SNOOPY_TEST_REDIRECT_COOKIE');
            @unlink(getenv('SNOOPY_TEST_SEEN'));
            @unlink($log_file);
            $log_file = $old;
        }
    },
    'HTTP cannot plant a Secure cookie into an HTTPS upgrade' => function () {
        $client = new SnoopySocketReplies();
        $client->responses = array(
            "HTTP/1.1 302 Found\r\nLocation: https://secure-origin.test/next\r\n"
                . "Set-Cookie: sid=spoof; Secure; Path=/\r\n\r\n",
        );
        snoopyRespondWith('');
        testAssertSame(true, $client->fetch('http://secure-origin.test/start'),
            'same-host HTTP to HTTPS upgrade completes');
        $client->request(0);
        testAssertSame(false,
            snoopyRequestHasCookie(implode("\r\n", snoopyCurlArgs()), 'sid=spoof'),
            'insecure response cannot create a Secure cookie for HTTPS');
    },
    'HTTP cannot overwrite an existing Secure cookie tuple' => function () {
        putenv('SNOOPY_TEST_REDIRECT=https://secure-origin.test/landing');
        putenv('SNOOPY_TEST_REDIRECT_COOKIE=sid=good; Secure; Path=/');
        @unlink(getenv('SNOOPY_TEST_SEEN'));
        try {
            $client = new SnoopySocketReplies();
            testAssertSame(true, $client->fetch('https://secure-origin.test/login'),
                'HTTPS redirect creates a Secure cookie');
            putenv('SNOOPY_TEST_REDIRECT');
            putenv('SNOOPY_TEST_REDIRECT_COOKIE');
            $client->responses = array(
                "HTTP/1.1 302 Found\r\nLocation: https://secure-origin.test/next\r\n"
                    . "Set-Cookie: sid=spoof; Path=/\r\n\r\n",
            );
            testAssertSame(true, $client->fetch('http://secure-origin.test/plain'),
                'HTTP to HTTPS redirect is processed');
            $client->request(0);
            $wire = implode("\r\n", snoopyCurlArgs());
            testAssertSame(true, snoopyRequestHasCookie($wire, 'sid=good'),
                'original Secure cookie retains its tuple');
            testAssertSame(false, snoopyRequestHasCookie($wire, 'sid=spoof'),
                'insecure override stays off HTTPS wire');
        } finally {
            putenv('SNOOPY_TEST_REDIRECT');
            putenv('SNOOPY_TEST_REDIRECT_COOKIE');
            @unlink(getenv('SNOOPY_TEST_SEEN'));
        }
    },
    'a __Secure cookie without Secure stays off the redirect wire' => function () {
        snoopyAssertPrefixRefused('__sEcUrE-bad=spoof; Path=/');
    },
    'a __Host cookie with Domain stays off the redirect wire' => function () {
        snoopyAssertPrefixRefused('__hOsT-bad=spoof; Secure; Domain=prefix.test; Path=/');
    },
    'a __Host cookie without root Path stays off the redirect wire' => function () {
        snoopyAssertPrefixRefused('__Host-path=spoof; Secure; Path=/other',
            'https://prefix.test/other/next');
    },
    'a __Host cookie without an explicit root Path stays off the redirect wire' => function () {
        snoopyAssertPrefixRefused('__Host-no-path=spoof; Secure');
    },
    'valid prefixed cookies keep their Secure and host-only scope' => function () {
        putenv('SNOOPY_TEST_REDIRECT=https://prefix.test/next');
        putenv('SNOOPY_TEST_REDIRECT_COOKIE=__Host-good=host; Secure; Path=/');
        putenv('SNOOPY_TEST_REDIRECT_COOKIE2=__Secure-good=secure; Secure; Path=/');
        @unlink(getenv('SNOOPY_TEST_SEEN'));
        try {
            $client = new Snoopy();
            testAssertSame(true, $client->fetch('https://prefix.test/start'),
                'same-origin redirect completes');
            $wire = implode("\r\n", snoopyCurlArgs());
            testAssertSame(true, snoopyRequestHasCookie($wire, '__Host-good=host'),
                'valid __Host cookie reaches its own host');
            testAssertSame(true, snoopyRequestHasCookie($wire, '__Secure-good=secure'),
                'valid __Secure cookie reaches HTTPS');
            testAssertSame(true, $client->fetch('https://sibling.prefix.test/next'),
                'later sibling request completes');
            testAssertSame(false,
                snoopyRequestHasCookie(implode("\r\n", snoopyCurlArgs()), '__Host-good=host'),
                '__Host cookie never reaches a sibling');
        } finally {
            putenv('SNOOPY_TEST_REDIRECT');
            putenv('SNOOPY_TEST_REDIRECT_COOKIE');
            putenv('SNOOPY_TEST_REDIRECT_COOKIE2');
            @unlink(getenv('SNOOPY_TEST_SEEN'));
        }
    },
    'host-only and Domain cookies with one name retain separate tuples' => function () {
        $client = new SnoopySocketReplies();
        $client->responses = array(
            "HTTP/1.1 200 OK\r\nSet-Cookie: sid=host; Path=/\r\n"
                . "Set-Cookie: sid=domain; Domain=tracker.test; Path=/\r\n\r\n",
            "HTTP/1.1 200 OK\r\n\r\n",
            "HTTP/1.1 200 OK\r\n\r\n",
        );
        testAssertSame(true, $client->fetch('http://tracker.test/start'),
            'both response cookies are captured');
        testAssertSame(true, $client->fetch('http://tracker.test/next'),
            'same-host request completes');
        testAssertSame(true, $client->fetch('http://sibling.tracker.test/next'),
            'sibling request completes');
        $client->request(0);
        $sameHost = $client->request(1);
        $sibling = $client->request(2);
        testAssertSame(true, snoopyRequestHasCookie($sameHost, 'sid=host')
            && snoopyRequestHasCookie($sameHost, 'sid=domain'),
            'host and Domain tuples both reach their common host');
        testAssertSame(false, snoopyRequestHasCookie($sibling, 'sid=host'),
            'host-only tuple stays off sibling');
        testAssertSame(true, snoopyRequestHasCookie($sibling, 'sid=domain'),
            'parent Domain tuple reaches sibling');
    },
    'Domain cookies refuse uncanonicalized IP and IDN response hosts' => function () {
        foreach(array('127.000.0.1', '0x7f.0.0.1', 'xn--bcher-kva.test') as $host) {
            putenv('SNOOPY_TEST_REDIRECT=https://' . $host . '/next');
            putenv('SNOOPY_TEST_REDIRECT_COOKIE=id=wrong; Domain=' . $host . '; Path=/; Secure');
            @unlink(getenv('SNOOPY_TEST_SEEN'));
            try {
                $client = new Snoopy();
                testAssertSame(true, $client->fetch('https://' . $host . '/start'),
                    'fake curl response completes for a noncanonical host');
                testAssertSame(false, in_array('Cookie: id=wrong', snoopyCurlArgs(), true),
                    'Domain is refused until request host and Domain share canonical mapping');
            } finally {
                putenv('SNOOPY_TEST_REDIRECT');
                putenv('SNOOPY_TEST_REDIRECT_COOKIE');
                @unlink(getenv('SNOOPY_TEST_SEEN'));
            }
        }
    },
    'default Path and Max-Age deletion apply to later explicit fetches' => function () {
        $client = new SnoopySocketReplies();
        $client->responses = array(
            "HTTP/1.1 200 OK\r\nSet-Cookie: id=site; Max-Age=600\r\n\r\n",
            "HTTP/1.1 200 OK\r\n\r\n",
            "HTTP/1.1 200 OK\r\n\r\n",
            "HTTP/1.1 200 OK\r\nSet-Cookie: id=deleted; Path=/private; Max-Age=0\r\n\r\n",
            "HTTP/1.1 200 OK\r\n\r\n",
        );
        testAssertSame(true, $client->fetch('http://tracker.test/private/start'), 'cookie response');
        testAssertSame(true, $client->fetch('http://tracker.test/private/next'), 'matching path');
        testAssertSame(true, $client->fetch('http://tracker.test/public'), 'other path');
        testAssertSame(true, $client->fetch('http://tracker.test/private/delete'), 'deletion response');
        testAssertSame(true, $client->fetch('http://tracker.test/private/again'), 'after deletion');
        $client->request(0);
        testAssertSame(true, snoopyRequestHasCookie($client->request(1), 'id=site'),
            'default Path scopes to the response directory');
        testAssertSame(false, snoopyRequestHasCookie($client->request(2), 'id=site'),
            'default Path excludes a different directory');
        $client->request(3);
        testAssertSame(false, snoopyRequestHasCookie($client->request(4), 'id=site'),
            'Max-Age zero deletes only the scoped tuple');
    },
    'lastResponseURL names the response before a redirect limit stops following' => function () {
        $client = new SnoopySocketReplies();
        $client->maxredirs = 1;
        $client->responses = array(
            "HTTP/1.1 302 Found\r\nLocation: http://b.response.test/next\r\n\r\n",
            "HTTP/1.1 302 Found\r\nLocation: http://a.response.test/final\r\n\r\n",
        );
        testAssertSame(true, $client->fetch('http://a.response.test/start'),
            'redirect chain reaches the second response');
        testAssertSame('http://b.response.test/next',
            isset($client->lastResponseURL) ? $client->lastResponseURL : null,
            'terminal answer URL is not the last Location');
        $client->request(0);
        $client->request(1);
    },
    'socket ignores interim Set-Cookie and parses the final HTTP answer' => function () {
        $client = new SnoopySocketReplies();
        $client->responses = array(
            "HTTP/1.1 100 Continue\r\nSet-Cookie: interim=wrong; Path=/\r\n\r\n"
                . "HTTP/1.1 103 Early Hints\r\nSet-Cookie: hints=wrong; Path=/\r\n\r\n"
                . "HTTP/1.1 200 OK\r\nSet-Cookie: final=right; Path=/\r\n\r\nbody",
            "HTTP/1.1 200 OK\r\n\r\n",
        );
        testAssertSame(true, $client->fetch('http://tracker.test/start'), 'interim chain completes');
        testAssertSame(200, (int) $client->status, 'final status is parsed');
        testAssertSame('body', $client->results, 'interim header blocks are not body bytes');
        testAssertSame('final=right; Path=/', trim($client->get_header('Set-Cookie')),
            'only final Set-Cookie is exposed');
        testAssertSame(true, $client->fetch('http://tracker.test/next'), 'later request');
        $client->request(0);
        $wire = $client->request(1);
        testAssertSame(true, snoopyRequestHasCookie($wire, 'final=right'),
            'final origin cookie reaches later wire');
        testAssertSame(false, snoopyRequestHasCookie($wire, 'interim=wrong')
            || snoopyRequestHasCookie($wire, 'hints=wrong'),
            'interim cookies never enter the origin jar');
    },
    // fgets() answers false both at EOF and when read_timeout expires before
    // the first header line, so only the stream's timed_out flag tells them
    // apart. A loopback listener that never calls accept() took the connection
    // (the kernel completes the handshake into the backlog) and holds the
    // request without a byte back: the shape of the live NNMClub scrape that
    // was logged as missing-final-response five seconds after it started.
    'a silent plain-HTTP peer that keeps the connection open is a read timeout' => function () {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        testAssertTrue(is_resource($server), 'Could not open the silent loopback peer: ' . $error);
        try {
            $address = stream_socket_get_name($server, false);
            $client = new Snoopy();
            $client->block_private = false;
            $client->read_timeout = 1;
            $client->_fp_timeout = 1;
            $started = microtime(true);
            $fetched = $client->fetch('http://' . $address . '/scrape?info_hash=silent');
            $elapsed = microtime(true) - $started;
            testAssertSame(false, $fetched, 'A peer that never answered was reported as fetched');
            testAssertSame(-100, $client->status,
                'A silent open peer is the read timeout, not a body refusal (error: ' . $client->error . ')');
            testAssertSame(true, $client->timed_out, 'The read timeout is recorded');
            testAssertSame('', $client->error, 'A read timeout carries no body-refusal reason');
            testAssertTrue($elapsed >= 0.9, 'The client gave up before read_timeout expired: ' . $elapsed . ' s');
            // The request reached the peer, so the silence is the peer's.
            $peer = stream_socket_accept($server, 1);
            testAssertTrue(is_resource($peer), 'The client connection never reached the peer');
            stream_set_timeout($peer, 1);
            $request = stream_get_contents($peer);
            fclose($peer);
            testAssertSame(0, strpos((string) $request, "GET /scrape?info_hash=silent HTTP/"),
                'The peer received the request: ' . json_encode($request));
        } finally {
            fclose($server);
        }
    },
    'a plain-HTTP peer that closes without a byte is still a missing final response' => function () {
        $client = new SnoopySocketReplies();
        $client->read_timeout = 1;
        $client->responses = array('');
        testAssertSame(false, $client->fetch('http://tracker.test/scrape'), 'An empty answer was reported as fetched');
        testAssertSame(Snoopy::RESPONSE_BODY_FAILED, $client->status,
            'A closed silent peer is a body refusal, not a read timeout');
        testAssertSame('missing-final-response', $client->error, 'The refusal names the missing response');
        testAssertSame(false, $client->timed_out, 'EOF is not a read timeout');
        testAssertSame(0, strpos($client->request(0), 'GET /scrape HTTP/'), 'The peer received the request');
    },
    // fread() also answers a timeout as EOF, and the gzip decode ran before
    // the post-body timeout check, so a body cut short by a silent open peer
    // was refused as invalid gzip. The same truncated bytes followed by a
    // close are a complete transfer of a corrupt body and stay a refusal.
    'a gzip body cut short by a silent open peer is a read timeout' => function () {
        $client = new Snoopy();
        $client->read_timeout = 1;
        $truncated = substr(gzencode(str_repeat('a', 1000)), 0, 10);
        $started = microtime(true);
        list($ok, $client) = snoopyPlainHttpReply('Content-Encoding: gzip', $truncated, null, $client, true);
        $elapsed = microtime(true) - $started;
        testAssertSame(false, $ok, 'A body the peer never finished was reported as fetched');
        testAssertSame(-100, $client->status,
            'A silent open peer is the read timeout, not a gzip refusal (error: ' . $client->error . ')');
        testAssertSame(true, $client->timed_out, 'The read timeout is recorded');
        testAssertSame('', $client->error, 'A read timeout carries no body-refusal reason');
        testAssertSame('', $client->results, 'A truncated body is not handed out');
        testAssertTrue($elapsed >= 0.9, 'The client gave up before read_timeout expired: ' . $elapsed . ' s');
    },
    'gzip bodies with a read timeout set still decode or refuse when the peer closes' => function () {
        $client = new Snoopy();
        $client->read_timeout = 1;
        list($ok, $client) = snoopyPlainHttpReply('Content-Encoding: gzip', gzencode('decoded body'), null, $client);
        testAssertSame(true, $ok, 'A complete gzip body completes');
        testAssertSame('decoded body', $client->results, 'A complete gzip body is decoded');
        testAssertSame(false, $client->timed_out, 'A complete body is not a read timeout');
        $client = new Snoopy();
        $client->read_timeout = 1;
        $truncated = substr(gzencode(str_repeat('a', 1000)), 0, 10);
        list($ok, $client) = snoopyPlainHttpReply('Content-Encoding: gzip', $truncated, null, $client);
        testAssertSame(false, $ok, 'A corrupt gzip body was reported as fetched');
        testAssertSame(Snoopy::RESPONSE_BODY_FAILED, $client->status,
            'A corrupt body the peer finished is a body refusal, not a read timeout');
        testAssertSame('invalid-or-oversized-gzip', $client->error, 'The refusal names the gzip body');
        testAssertSame(false, $client->timed_out, 'EOF is not a read timeout');
    },
    'curl redirect ignores proxy CONNECT Set-Cookie on the next origin wire' => function () {
        putenv('SNOOPY_TEST_REDIRECT=https://tracker.test/next');
        putenv('SNOOPY_TEST_INTERIM_COOKIE=injected=proxy; Path=/');
        @unlink(getenv('SNOOPY_TEST_SEEN'));
        try {
            $client = new Snoopy();
            testAssertSame(true, $client->fetch('https://tracker.test/start'),
                'origin redirect after CONNECT completes');
            testAssertSame(false, in_array('Cookie: injected=proxy', snoopyCurlArgs(), true),
                'proxy Set-Cookie is not sent back to origin');
        } finally {
            putenv('SNOOPY_TEST_REDIRECT');
            putenv('SNOOPY_TEST_INTERIM_COOKIE');
            @unlink(getenv('SNOOPY_TEST_SEEN'));
        }
    },
    'curl accepts Set-Cookie only from the final origin response block' => function () {
        snoopyRespondWith('HTTP/1.1 200 Connection established\r\n'
            . 'Set-Cookie: injected=proxy; Path=/\r\n\r\nHTTP/1.1 200 OK');
        try {
            $client = new Snoopy();
            testAssertSame(true, $client->fetch('https://tracker.test/first'),
                'response with proxy/interim and origin blocks completes');
            testAssertSame(false, $client->get_header('Set-Cookie'),
                'intermediate Set-Cookie is absent from the origin response');
            snoopyRespondWith('');
            testAssertSame(true, $client->fetch('https://tracker.test/later'),
                'later explicit request completes');
            testAssertSame(false, in_array('Cookie: injected=proxy', snoopyCurlArgs(), true),
                'proxy/interim cookie never enters the origin jar');
        } finally {
            snoopyRespondWith('');
        }
    },
    'curl refuses an interim-only answer with no final response' => function () {
        snoopyRespondWith('HTTP/1.1 100 Continue\r\nSet-Cookie: provisional=wrong; Path=/');
        try {
            $client = new Snoopy();
            testAssertSame(false, $client->fetch('https://tracker.test/start'),
                'interim-only response is incomplete');
            testAssertSame('missing-final-response', $client->error,
                'the incomplete response has a classified reason');
            snoopyRespondWith('');
            testAssertSame(true, $client->fetch('https://tracker.test/next'),
                'client remains usable');
            testAssertSame(false, in_array('Cookie: provisional=wrong', snoopyCurlArgs(), true),
                'interim cookie was not captured');
        } finally {
            snoopyRespondWith('');
        }
    },
    'multiple interim curl blocks leave only the final cookie and status' => function () {
        snoopyRespondWith('HTTP/1.1 100 Continue\r\nSet-Cookie: first=wrong; Path=/\r\n\r\n'
            . 'HTTP/1.1 200 Connection established\r\nSet-Cookie: second=wrong; Path=/\r\n\r\n'
            . 'HTTP/2 200\r\nSet-Cookie: final=right; Path=/');
        try {
            $client = new Snoopy();
            testAssertSame(true, $client->fetch('https://tracker.test/start'),
                'multiple header blocks complete');
            testAssertSame(200, (int) $client->status, 'final status is retained');
            testAssertSame('final=right; Path=/', trim($client->get_header('Set-Cookie')),
                'only final response headers are exposed');
            snoopyRespondWith('');
            testAssertSame(true, $client->fetch('https://tracker.test/next'), 'later request');
            testAssertSame(true, in_array('Cookie: final=right', snoopyCurlArgs(), true),
                'only final origin cookie reaches the wire');
        } finally {
            snoopyRespondWith('');
        }
    },
    'Max-Age overrides past Expires for the exact cookie tuple' => function () {
        $client = new SnoopySocketReplies();
        $client->responses = array(
            "HTTP/1.1 200 OK\r\nSet-Cookie: id=alive; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=600\r\n\r\n",
            "HTTP/1.1 200 OK\r\n\r\n",
        );
        testAssertSame(true, $client->fetch('http://tracker.test/start'), 'cookie response');
        testAssertSame(true, $client->fetch('http://tracker.test/next'), 'later request');
        $client->request(0);
        testAssertSame(true, snoopyRequestHasCookie($client->request(1), 'id=alive'),
            'positive Max-Age wins over an expired Expires attribute');
    },
    'domain capacity evicts a nonSecure cookie before a Secure cookie' => function () {
        $headers = array('Set-Cookie: guard=keep; Secure; Path=/');
        for ($i = 0; $i < 50; $i++)
            $headers[] = 'Set-Cookie: c' . $i . '=v' . $i . '; Path=/';
        snoopyRespondWith("HTTP/1.1 200 OK\r\n" . implode("\r\n", $headers));
        try {
            $client = new Snoopy();
            testAssertSame(true, $client->fetch('https://capacity.test/start'),
                'domain receives mixed Secure and nonSecure cookies');
            snoopyRespondWith('');
            testAssertSame(true, $client->fetch('https://capacity.test/next'),
                'later HTTPS request completes');
            $wire = implode("\r\n", snoopyCurlArgs());
            testAssertSame(true, snoopyRequestHasCookie($wire, 'guard=keep'),
                'Secure cookie survives the domain cap');
            testAssertSame(false, snoopyRequestHasCookie($wire, 'c0=v0'),
                'oldest nonSecure cookie is evicted first');
            testAssertSame(true, snoopyRequestHasCookie($wire, 'c49=v49'),
                'newest cookie stays available');
        } finally {
            snoopyRespondWith('');
        }
    },
    'response cookie domain capacity evicts the oldest entry' => function () {
        $headers = array();
        for ($i = 0; $i < 51; $i++)
            $headers[] = 'Set-Cookie: c' . $i . '=v' . $i . '; Path=/';
        $client = new SnoopySocketReplies();
        $client->responses = array(
            "HTTP/1.1 200 OK\r\n" . implode("\r\n", $headers) . "\r\n\r\n",
            "HTTP/1.1 200 OK\r\n\r\n",
        );
        testAssertSame(true, $client->fetch('http://tracker.test/start'), 'bounded jar receives cookies');
        testAssertSame(true, $client->fetch('http://tracker.test/next'), 'later request');
        $client->request(0);
        $wire = $client->request(1);
        testAssertSame(false, snoopyRequestHasCookie($wire, 'c0=v0'),
            'oldest cookie is evicted at the domain cap');
        testAssertSame(true, snoopyRequestHasCookie($wire, 'c50=v50'),
            'newest cookie remains available');
        testAssertSame(1, substr_count($wire, "Cookie: "),
            'bounded jar still emits one header');
    },
    'raw Cookie header is the sole Cookie header on both transports' => function () {
        $client = new SnoopySocketReplies();
        $client->cookies['explicit'] = 'yes';
        $client->rawheaders['cOoKiE'] = 'raw=one';
        $client->responses = array("HTTP/1.1 200 OK\r\n\r\n");
        testAssertSame(true, $client->fetch('http://tracker.test/one'), 'socket fetch');
        testAssertSame(1, preg_match_all('/^Cookie:/mi', $client->request(0), $matches),
            'socket emits only the raw cookie field');
        $client = new Snoopy();
        $client->cookies['explicit'] = 'yes';
        $client->rawheaders['cOoKiE'] = 'raw=one';
        testAssertSame(true, $client->fetch('https://tracker.test/one'), 'curl fetch');
        $headers = array_values(array_filter(snoopyCurlArgs(), function($arg) {
            return stripos($arg, 'Cookie:') === 0;
        }));
        testAssertSame(array('cOoKiE: raw=one'), $headers,
            'curl emits only the raw cookie field');
    },
    'plain HTTP preserves unparsed redirect-like headers' => function () {
        $client = new SnoopySocketReplies();
        $client->responses = array("HTTP/1.1 200 OK\r\nLocation:\r\nRefresh: 5\r\n\r\n");
        testAssertSame(true, $client->fetch('http://tracker.example/start'),
            'plain HTTP reply completes');
        testAssertSame('', $client->get_header('Location'),
            'empty Location remains visible to callers');
        testAssertSame(true, in_array("Refresh: 5\r\n", $client->headers, true),
            'Refresh without a URL remains visible to callers');
        testAssertSame('', $client->lastredirectaddr,
            'neither header starts a redirect');
        $client->request(0);
    },
    'plain HTTP follows Location without a space' => function () {
        $client = new SnoopySocketReplies();
        $client->responses = array(
            "HTTP/1.1 302 Found\r\nLocation:http://destination.test/file\r\n\r\n",
            "HTTP/1.1 200 OK\r\n\r\n",
        );
        testAssertSame(true, $client->fetch('http://tracker.example/start'),
            'plain HTTP redirect chain completes');
        testAssertSame('http://destination.test/file', $client->lastredirectaddr,
            'Location without a space names the destination');
        $client->request(0);
        testAssertTrue(strpos($client->request(1), "Host: destination.test\r\n") !== false,
            'socket transport requests the named host');
    },
    'plain HTTP Basic uses decoded URL username and password' => function () {
        $client = new SnoopySocketReplies();
        $client->responses = array("HTTP/1.1 200 OK\r\n\r\n");
        testAssertSame(true,
            $client->fetch('http://bob%40mail.test:p%40ss@tracker.example/file'),
            'encoded userinfo request completes');
        $request = $client->request(0);
        $expected = "Authorization: Basic " . base64_encode('bob@mail.test:p@ss') . "\r\n";
        testAssertSame(1, substr_count($request, $expected),
            'socket transport sends decoded Basic credentials exactly once');
        testAssertSame('', $client->user, 'URL username is restored after fetch');
        testAssertSame('', $client->pass, 'URL password is restored after fetch');
    },
    'plain HTTP imports redirect cookies only when passcookies permits it' => function () {
        foreach (array(true, false) as $passcookies) {
            $client = new SnoopySocketReplies();
            $client->passcookies = $passcookies;
            $client->responses = array(
                "HTTP/1.1 302 Found\r\nLocation: http://tracker.example/next\r\nSet-Cookie: fresh=secret; Path=/\r\n\r\n",
                "HTTP/1.1 200 OK\r\n\r\n",
            );
            testAssertSame(true, $client->fetch('http://tracker.example/start'),
                'same-origin HTTP redirect completes');
            $client->request(0);
            $request = $client->request(1);
            testAssertSame($passcookies, strpos($request, "Cookie: fresh=secret\r\n") !== false,
                'redirect cookie import follows passcookies');
        }

        $client = new SnoopySocketReplies();
        $client->responses = array(
            "HTTP/1.1 200 OK\r\nSet-Cookie: fresh=secret; Path=/\r\n\r\n",
            "HTTP/1.1 200 OK\r\n\r\n",
        );
        testAssertSame(true, $client->fetch('http://tracker.example/start'),
            'non-redirect response completes');
        testAssertSame(true, $client->fetch('http://tracker.example/independent'),
            'independent HTTP request completes');
        $client->request(0);
        testAssertSame(true,
            strpos($client->request(1), "Cookie: fresh=secret\r\n") !== false,
            'response cookie remains scoped and available on a later explicit fetch');
    },
    'gzip content encoding is parsed as an exact header' => function () {
        list($ok, $client) = snoopyPlainHttpReply('Content-Encoding:gzip', gzencode('decoded body'));
        testAssertSame(true, $ok, 'a valid gzip reply completes');
        testAssertSame('decoded body', $client->results, 'gzip without a space is decoded');
        list($ok, $client) = snoopyPlainHttpReply('X-Content-Encoding: gzip', 'ordinary body');
        testAssertSame(true, $ok, 'unrelated header does not invalidate a plain reply');
        testAssertSame('ordinary body', $client->results, 'x-gzip is not decoded as gzip');
    },
    'gzip filename member and expanded body bound are handled' => function () {
        $encoded = gzencode('decoded with filename');
        $withName = substr($encoded, 0, 3).chr(8).substr($encoded, 4, 6)
            .'torrent.name'."\0".substr($encoded, 10);
        list($ok, $client) = snoopyPlainHttpReply('Content-Encoding: gzip', $withName);
        testAssertSame(true, $ok, 'gzip member with FNAME completes');
        testAssertSame('decoded with filename', $client->results, 'FNAME is skipped by the decoder');
        $withExtra = substr($encoded, 0, 3).chr(4).substr($encoded, 4, 6)
            .pack('v', 4).'ABCD'.substr($encoded, 10);
        list($ok, $client) = snoopyPlainHttpReply('Content-Encoding: gzip', $withExtra);
        testAssertSame(true, $ok, 'gzip member with FEXTRA completes');
        testAssertSame('decoded with filename', $client->results,
            'FEXTRA is skipped by the decoder');
        list($ok, $client) = snoopyPlainHttpReply('Content-Encoding: gzip',
            gzencode(str_repeat('x', 4096)), 128);
        testAssertSame(false, $ok, 'an expanded body above maxlength is refused');
        testAssertSame('', $client->results, 'oversized decompression is not exposed to consumers');
        list($ok, $client) = snoopyPlainHttpReply('Content-Encoding: gzip',
            gzencode('first').gzencode('second'));
        testAssertSame(true, $ok, 'multiple gzip members complete');
        testAssertSame('firstsecond', $client->results,
            'all gzip members reach the caller');
        list($ok, $client) = snoopyPlainHttpReply('Content-Encoding: gzip',
            gzencode('first').'junk');
        testAssertSame(false, $ok, 'trailing garbage after a valid member is refused');
        list($ok, $client) = snoopyPlainHttpReply('Content-Encoding: gzip',
            gzencode('first')."\0\0");
        testAssertSame(true, $ok, 'gzip zero padding is accepted');
        testAssertSame('first', $client->results, 'zero padding adds no body data');
    },
    'HTTPS curl gzip response is decoded with its own status and body' => function () {
        $bodyFile = tempnam(sys_get_temp_dir(), 'snoopy-gzip-body-');
        try {
            file_put_contents($bodyFile, gzencode('https decoded'));
            putenv('SNOOPY_TEST_BODY_FILE=' . $bodyFile);
            snoopyRespondWith("HTTP/1.1 200 OK\r\nContent-Encoding:gzip\r\n");
            $client = new Snoopy();
            testAssertSame(true, $client->fetch('https://example.test/file'),
                'HTTPS gzip fetch completes');
            testAssertSame('https decoded', $client->results,
                'curl body is decoded without replacing its exit code');
            testAssertSame('200', $client->status, 'curl HTTP status remains visible');
            $args = snoopyCurlArgs();
            $maxFlag = array_search('--max-filesize', $args, true);
            testAssertTrue($maxFlag !== false, 'curl bounds the temporary HTTPS body during transfer');
            testAssertSame((string)$client->maxlength, $args[$maxFlag + 1] ?? null,
                'curl uses Snoopy maxlength as its transfer limit');
            testAssertTrue(in_array('Accept-Encoding: gzip', snoopyCurlArgs(), true),
                'HTTPS request advertises gzip when enabled');
            $fallback = new SnoopyWithoutZlib();
            testAssertSame(false, $fallback->fetch('https://example.test/file'),
                'HTTPS gzip is refused without zlib');
            testAssertSame('', $fallback->results,
                'unsupported compressed body is not exposed');
            testAssertSame(Snoopy::RESPONSE_BODY_FAILED, $fallback->status,
                'unsupported gzip cannot look like HTTP success');
            testAssertSame('gzip-decoder-unavailable', $fallback->error,
                'HTTPS refusal names the missing decoder');
            testAssertSame(false, in_array('Accept-Encoding: gzip', snoopyCurlArgs(), true),
                'no-zlib client does not advertise gzip');
            file_put_contents($bodyFile, 'invalid gzip bytes');
            testAssertSame(false, $client->fetch('https://example.test/file'),
                'invalid gzip body is refused');
            testAssertSame('', $client->results, 'invalid gzip cannot retain the last body');
            testAssertSame(Snoopy::RESPONSE_BODY_FAILED, $client->status,
                'decoder refusal cannot look like a successful HTTP 200');
            testAssertSame('invalid-or-oversized-gzip', $client->error,
                'invalid gzip has a classified reason');
            file_put_contents($bodyFile, gzencode(str_repeat('x', 4096)));
            $client->maxlength = 128;
            testAssertSame(false, $client->fetch('https://example.test/file'),
                'HTTPS gzip bomb is refused at expanded size');
            testAssertSame('', $client->results,
                'HTTPS gzip bomb does not expose expanded content');
            file_put_contents($bodyFile, str_repeat('x', 4096));
            snoopyRespondWith("HTTP/1.1 200 OK\r\n");
            testAssertSame(false, $client->fetch('https://example.test/file'),
                'HTTPS raw body over maxlength is refused');
            testAssertSame('unreadable-or-oversized-response', $client->error,
                'HTTPS raw size refusal is classified');
        } finally {
            putenv('SNOOPY_TEST_BODY_FILE');
            unlink($bodyFile);
        }
    },
    'no-zlib gzip response refuses bytes without a trusted decoder' => function () {
        list($ok, $client) = snoopyPlainHttpReply('Content-Encoding: gzip',
            gzencode('valid response'), null, new SnoopyWithoutZlib());
        testAssertSame(false, $ok, 'no-zlib gzip response must fail closed');
        testAssertSame('', $client->results, 'unverified gzip body stays hidden');
        testAssertSame(Snoopy::RESPONSE_BODY_FAILED, $client->status,
            'unsupported gzip must not look like HTTP success');
        testAssertSame('gzip-decoder-unavailable', $client->error,
            'plain HTTP refusal names the missing decoder');
    },
    'plain HTTP chunked bodies are decoded before gzip' => function () {
        list($ok, $client) = snoopyPlainHttpReply('Transfer-Encoding: chunked',
            "5\r\nhello\r\n0\r\nX-Trailer: ignored\r\n\r\n");
        testAssertSame(true, $ok, 'a valid chunked response completes');
        testAssertSame('hello', $client->results, 'chunk framing is removed');
        $encoded = gzencode('decoded chunks');
        $chunked = dechex(strlen($encoded))."\r\n".$encoded."\r\n0\r\n\r\n";
        list($ok, $client) = snoopyPlainHttpReply(
            "Transfer-Encoding: chunked\r\nContent-Encoding: gzip", $chunked);
        testAssertSame(true, $ok, 'chunked gzip response completes');
        testAssertSame('decoded chunks', $client->results,
            'chunk framing is removed before gzip decoding');
        list($ok, $client) = snoopyPlainHttpReply('Transfer-Encoding: chunked',
            "Z\r\ninvalid\r\n0\r\n\r\n");
        testAssertSame(false, $ok, 'invalid chunk length is refused');
        testAssertSame('', $client->results, 'invalid chunk data is never returned');
        testAssertSame(Snoopy::RESPONSE_BODY_FAILED, $client->status,
            'chunk refusal cannot look like a successful HTTP 200');
        $encoded = gzencode('transfer gzip');
        $chunked = dechex(strlen($encoded))."\r\n".$encoded."\r\n0\r\n\r\n";
        foreach(array("Transfer-Encoding: gzip, chunked",
            "Transfer-Encoding: gzip\r\nTransfer-Encoding: chunked") as $headers) {
            list($ok, $client) = snoopyPlainHttpReply($headers, $chunked);
            testAssertSame(true, $ok, 'gzip transfer coding completes');
            testAssertSame('transfer gzip', $client->results,
                'gzip transfer coding is removed after chunk framing');
        }
        list($ok, $client) = snoopyPlainHttpReply('Transfer-Encoding: deflate, chunked',
            "5\r\nhello\r\n0\r\n\r\n");
        testAssertSame(false, $ok, 'unsupported transfer coding is refused');
        testAssertSame('unsupported-transfer-encoding', $client->error,
            'unsupported transfer coding is classified');
    },
    'URL cookie suffix keeps equals in values and ignores malformed pairs' => function () {
        $url = 'https://tracker.example/file:COOKIE:token=abc=def;broken;sid=2';
        testAssertSame(array('token' => 'abc=def', 'sid' => '2'), Snoopy::getURLCookies($url),
            'cookie parser keeps the full value and skips incomplete pairs');
        testAssertSame('https://tracker.example/file', $url, 'suffix removed from URL');
    },
    'a credential redirect refusal is not a successful download' => function () {
        $client = new Snoopy();
        $client->status = 200;
        $client->error = Snoopy::CREDENTIAL_REDIRECT_REFUSED;
        testAssertSame(false, Snoopy::isSuccessfulResponse($client),
            '2xx with a refused Location is not a successful response');
        $client->error = 'invalid-or-oversized-gzip';
        testAssertSame(false, Snoopy::isSuccessfulResponse($client),
            '2xx with a failed body decoder is not a successful response');
        $client->error = '';
        testAssertSame(true, Snoopy::isSuccessfulResponse($client),
            'ordinary 2xx response remains successful');
    },
    'torrent response guard rejects HTML and accepts parsed metainfo' => function () {
        $client = new Snoopy();
        $client->status = 200;
        $client->results = '<html>Login required</html>';
        testAssertSame(false, Snoopy::isTorrentResponse($client),
            'HTML must not be saved as a torrent');
        $client->results = 'd4:infod6:lengthi1e4:name4:test12:piece lengthi1e6:pieces20:abcdefghijklmnopqrstee';
        testAssertSame(true, Snoopy::isTorrentResponse($client),
            'valid metainfo remains downloadable');
        $rawBody = $client->results;
        $client->results = $rawBody . '<html>unexpected extra response</html>';
        testAssertSame(false, Snoopy::isTorrentResponse($client),
            'a bencoded prefix followed by HTML is not a complete torrent');
        // RSS, addtorrent, bulk_magnet and extsearch all ask this guard; a
        // tracker that ends the file with a line break must stay downloadable.
        $client->results = $rawBody . "\r\n";
        testAssertSame(true, Snoopy::isTorrentResponse($client),
            'metainfo followed by a line break remains downloadable');
        $client->results = $rawBody . "\n<html>unexpected extra response</html>";
        testAssertSame(false, Snoopy::isTorrentResponse($client),
            'a line break does not excuse HTML after the metainfo');
        $client->results = 'd4:infodee';
        testAssertSame(false, Snoopy::isTorrentResponse($client),
            'an empty info dictionary is not metainfo');
        $client->results = $rawBody;
        $conflictDir = sys_get_temp_dir() . '/snoopy-raw-' . getmypid();
        @mkdir($conflictDir);
        $previousDir = getcwd();
        try {
            chdir($conflictDir);
            file_put_contents($rawBody, 'a conflicting local file');
            testAssertSame(true, Snoopy::isTorrentResponse($client),
                'download validation parses raw bytes even when they name a local file');
        } finally {
            chdir($previousDir);
            @unlink($conflictDir . '/' . $rawBody);
            @rmdir($conflictDir);
        }
        $localPath = tempnam(sys_get_temp_dir(), 'local-metainfo-');
        try {
            file_put_contents($localPath, $client->results);
            $client->results = $localPath;
            testAssertSame(false, Snoopy::isTorrentResponse($client),
                'a remote body naming a local file cannot make the downloader read that file');
        } finally {
            @unlink($localPath);
        }
        $client->error = Snoopy::CREDENTIAL_REDIRECT_REFUSED;
        testAssertSame(false, Snoopy::isTorrentResponse($client),
            'classified refusal wins even over valid source bytes');
    },
    'a failed transport cannot import an earlier response cookie into the next request' => function () {
        $client = new SnoopyRedirectProbe();
        $client->replies = array(
            array(false, 'https://other.test/next', array("Set-Cookie: secret=one; Path=/\r\n"), -100),
            array(true, false, array(), 200),
        );
        testAssertSame(false, $client->fetch('http://tracker.example/first'), 'HTTP transport fails after parsing headers');
        testAssertSame(true, $client->fetch('https://other.test/second'), 'reused transport works');
        testAssertSame(array(), $client->requests[1][1], 'failed response cookie stays with its source');
    },
    'a rejected trusted redirect cannot import its response cookie on client reuse' => function () {
        $client = new SnoopyRejectedRedirectProbe();
        $client->block_private = true;
        $client->redirectTrust = function ($url) { return true; };
        $client->replies = array(
            array(true, 'https://blocked.test/landing', array("Set-Cookie: sid=source; Path=/\r\n"), 302),
            array(true, false, array(), 200),
        );
        testAssertSame(false, $client->fetch('https://tracker.example/start'), 'private target rejects trusted second hop');
        testAssertSame(false, $client->_redirectaddr, 'failed nested fetch clears pending cookie import');
        $client->cookies = array();
        testAssertSame(true, $client->fetch('https://other.test/independent'), 'client can be reused');
        testAssertSame(array(), $client->requests[1][1], 'source cookie never reaches unrelated host');
    },
    'URL userinfo is scoped to its fetch chain and does not persist on the client' => function () {
        $client = new SnoopyRedirectProbe();
        $client->replies = array(array(true, false, array(), 200), array(true, false, array(), 200));
        testAssertSame(true, $client->fetch('https://alice:secret@tracker.example/one'), 'userinfo request');
        testAssertSame('alice', $client->requests[0][2], 'userinfo credentials used for their own request');
        testAssertSame('secret', $client->requests[0][3], 'userinfo password used for its own request');
        testAssertSame('', $client->user, 'userinfo user restored');
        testAssertSame('', $client->pass, 'userinfo password restored');
        testAssertSame(true, $client->fetch('https://other.test/two'), 'next request');
        testAssertSame('', $client->requests[1][2], 'next host receives no prior user');
        testAssertSame('', $client->requests[1][3], 'next host receives no prior password');
    },
    'percent-encoded URL userinfo is decoded before Basic authentication' => function () {
        $client = new SnoopyRedirectProbe();
        $client->replies = array(array(true, false, array(), 200), array(true, false, array(), 200));
        testAssertSame(true, $client->fetch('https://alice:p%40ss@tracker.example/rss'), 'encoded userinfo request');
        testAssertSame('alice', $client->requests[0][2], 'Basic user is decoded');
        testAssertSame('p@ss', $client->requests[0][3], 'Basic password is decoded');
        $url = Snoopy::linkencode('https://alice:p!ss@tracker.example/rss');
        testAssertSame(true, $client->fetch($url), 'linkencode request');
        testAssertSame('p!ss', $client->requests[1][3], 'linkencode output is decoded before Basic');
    },
    'redirect userinfo is refused before same-origin cookies are forwarded' => function () {
        $client = new SnoopyRedirectProbe();
        $client->cookies = array('sid' => 'private');
        $client->replies = array(array(true, 'https://attacker:secret@tracker.example/next', array(), 302));
        testAssertSame(true, $client->fetch('https://tracker.example/one'), 'origin response remains visible');
        testAssertSame('credential-redirect-refused', $client->error, 'userinfo redirect is refused');
        testAssertSame(1, count($client->requests), 'no request to userinfo target');
        testAssertSame('', $client->user, 'redirect userinfo cannot persist');
    },
    'an anonymous cross-origin redirect does not carry the source Set-Cookie' => function () {
        $client = new SnoopyRedirectProbe();
        $client->replies = array(
            array(true, 'https://other.test/file', array("Set-Cookie: fresh=private; Path=/\r\n"), 302),
            array(true, false, array(), 200),
            array(true, false, array(), 200),
        );
        testAssertSame(true, $client->fetch('https://tracker.example/start'), 'anonymous chain reaches destination');
        testAssertSame(200, $client->status, 'destination response is available');
        testAssertSame(2, count($client->requests), 'anonymous cross-origin redirect is followed');
        testAssertSame(array(), $client->requests[1][1], 'source cookie is not sent to destination');
        testAssertSame(array(), $client->cookies, 'source cookie is not imported into the flat jar');
        testAssertSame(true, $client->fetch('https://other.test/independent'), 'later independent request works');
        testAssertSame(array(), $client->requests[2][1], 'new session never imported into flat jar');
    },
    'anonymous redirects keep conditional validators on their own origin' => function () {
        $client = new SnoopyRedirectProbe();
        $client->rawheaders['If-None-Match'] = '"v1"';
        $client->rawheaders['If-Modified-Since'] = 'Wed, 21 Oct 2015 07:28:00 GMT';
        $client->replies = array(
            array(true, 'https://cdn.test/feed', array(), 302),
            array(true, false, array(), 200),
        );
        testAssertSame(true, $client->fetch('https://tracker.test/feed'), 'anonymous validator chain follows');
        testAssertSame(2, count($client->requests), 'destination is requested');
        testAssertSame(array(), $client->requests[1][6],
            'conditional validators do not cross origin boundaries');
    },
    'an anonymous POST redirect is followed as GET without forwarding its body' => function () {
        $client = new SnoopyRedirectProbe();
        $client->replies = array(
            array(true, 'https://other.test/result', array(), 303),
            array(true, false, array(), 200),
        );
        testAssertSame(true, $client->fetch('https://tracker.example/submit', 'POST',
            'application/x-www-form-urlencoded', 'query=public'), 'anonymous POST chain succeeds');
        testAssertSame(2, count($client->requests), 'anonymous redirect is followed');
        testAssertSame('https://other.test/result', $client->requests[1][0], 'result URL');
        testAssertSame('GET', $client->requests[1][4], 'redirect uses GET');
        testAssertSame('', $client->requests[1][5], 'POST body is not forwarded');
        testAssertSame('', $client->error, 'body alone does not mean credentials were withheld');
    },
    'account cookie trust does not authorize Basic credentials on a sibling host' => function () {
        $client = new SnoopyRedirectProbe();
        $client->redirectTrust = function ($url) {
            return UrlHost::isOneOf(UrlHost::of($url), array('tracker.example'));
        };
        $client->replies = array(array(true, 'https://dl.tracker.example/file', array(), 302));
        testAssertSame(true, $client->fetch('https://alice:secret@tracker.example/start'), 'origin response');
        testAssertSame('credential-redirect-refused', $client->error, 'Basic credentials stay on their origin');
        testAssertSame(1, count($client->requests), 'sibling host receives no Basic authorization');
        testAssertSame('', $client->user, 'URL user restored after refusal');
    },
    'account cookie trust does not forward a raw Cookie header' => function () {
        $client = new SnoopyRedirectProbe();
        $client->redirectTrust = function ($url) {
            return UrlHost::isOneOf(UrlHost::of($url), array('tracker.example'));
        };
        $client->rawheaders['Cookie'] = 'sid=private';
        $client->replies = array(array(true, 'https://dl.tracker.example/file', array(), 302));
        testAssertSame(true, $client->fetch('https://tracker.example/start'), 'origin response');
        testAssertSame('credential-redirect-refused', $client->error, 'raw cookie is not account-scoped');
        testAssertSame(1, count($client->requests), 'sibling host receives no raw cookie');
    },
    'account trust never forwards raw authentication headers to a sibling host' => function () {
        foreach (array('aUtHoRiZaTiOn', 'PrOxY-AuThOrIzAtIoN') as $header) {
            $client = new SnoopyRedirectProbe();
            $client->redirectTrust = function ($url) {
                return UrlHost::isOneOf(UrlHost::of($url), array('tracker.example'));
            };
            $client->rawheaders[$header] = 'secret';
            $client->replies = array(array(true, 'https://dl.tracker.example/file', array(), 302));
            testAssertSame(true, $client->fetch('https://tracker.example/start'), 'source responds');
            testAssertSame(1, count($client->requests), $header . ' stays on source');
            testAssertSame('credential-redirect-refused', $client->error, 'raw header refusal is classified');
        }
    },
    'account trust cannot authorize an HTTPS downgrade' => function () {
        $client = new SnoopyRedirectProbe();
        $client->redirectTrust = function ($url) {
            return UrlHost::isOneOf(UrlHost::of($url), array('tracker.example'));
        };
        $client->cookies = array('sid' => 'secret');
        $client->replies = array(array(true, 'http://dl.tracker.example/file', array(), 302));
        testAssertSame(true, $client->fetch('https://tracker.example/start'), 'source responds');
        testAssertSame(1, count($client->requests), 'HTTP sibling receives no cookie');
        testAssertSame('credential-redirect-refused', $client->error, 'downgrade refusal is classified');
    },
    'account trust requires an HTTPS source for a cross-host cookie redirect' => function () {
        $client = new SnoopyRedirectProbe();
        // A custom account may accept both schemes on this tracker host.
        $client->redirectTrust = function ($url) {
            return UrlHost::isOneOf(UrlHost::of($url), array('tracker.example'));
        };
        $client->cookies = array('sid' => 'secret');
        $client->replies = array(
            array(true, 'https://dl.tracker.example/file', array(), 302),
            array(true, false, array(), 200),
        );
        testAssertSame(true, $client->fetch('http://tracker.example/start'), 'source responds');
        testAssertSame(1, count($client->requests), 'HTTP source cannot authorize a cross-host cookie hop');
        testAssertSame('credential-redirect-refused', $client->error,
            'the refusal keeps the source response and names its reason');
    },
    'account trust checks the source URL before forwarding a session' => function () {
        $client = new SnoopyRedirectProbe();
        $client->redirectTrust = function ($url) {
            return UrlHost::urlIsOneOf($url, array('tracker.example'), 'https', '/forum/');
        };
        $client->cookies = array('sid' => 'secret');
        $client->replies = array(array(true, 'https://dl.tracker.example/forum/file', array(), 302));
        testAssertSame(true, $client->fetch('https://tracker.example/login'), 'source responds');
        testAssertSame(1, count($client->requests), 'out-of-scope source does not authorize destination');
        testAssertSame('credential-redirect-refused', $client->error, 'source-scope refusal is classified');
    },
    'account scope refuses an anonymous foreign hop before its cookies can enter the jar' => function () {
        $client = new SnoopyRedirectProbe();
        $client->redirectTrust = function ($url) {
            return UrlHost::isOneOf(UrlHost::of($url), array('tracker.example'));
        };
        $client->replies = array(
            array(true, 'https://evil.test/a', array(), 302),
            array(true, false, array(), 200),
        );
        testAssertSame(true, $client->fetch('https://tracker.example/start'), 'origin response remains readable');
        testAssertSame(1, count($client->requests), 'foreign host is not requested');
        testAssertSame('credential-redirect-refused', $client->error, 'untrusted account redirect is visible');
        testAssertSame(array(), $client->cookies, 'no foreign cookies enter the flat jar');
        testAssertSame(true, $client->fetch('https://tracker.example/next'), 'later account request succeeds');
        testAssertSame(array(), $client->requests[1][1], 'later request has no foreign cookie');
    },
    'own Basic and raw authentication refuse anonymous cross-origin redirects' => function () {
        foreach (array('user', 'pass', 'Cookie', 'Authorization', 'Proxy-Authorization') as $source) {
            $client = new SnoopyRedirectProbe();
            if ($source === 'user') { $client->user = 'alice'; }
            elseif ($source === 'pass') { $client->pass = 'secret'; }
            else { $client->rawheaders[$source] = 'secret'; }
            $client->replies = array(array(true, 'https://other.test/file', array(), 302));
            testAssertSame(true, $client->fetch('https://tracker.example/start'), 'origin responds');
            testAssertSame(1, count($client->requests), $source . ' stays on origin');
            testAssertSame(Snoopy::CREDENTIAL_REDIRECT_REFUSED, $client->error, 'refusal is classified');
        }
    },
    'redirect depth limit clears pending cookie import before client reuse' => function () {
        $client = new SnoopyRedirectProbe();
        $client->maxredirs = 0;
        $client->replies = array(
            array(true, 'https://tracker.example/next', array('Set-Cookie: sid=source; Path=/'), 302),
            array(true, false, array(), 200),
        );
        testAssertSame(true, $client->fetch('https://tracker.example/start'), 'depth-limited origin responds');
        testAssertSame(false, $client->_redirectaddr, 'depth limit clears pending import');
        testAssertSame(true, $client->fetch('https://other.test/independent'), 'later request works');
        testAssertSame(array(), $client->requests[1][1], 'later host has no source cookie');
    },
    'a new explicit request clears the prior refusal error' => function () {
        $client = new SnoopyRedirectProbe();
        $client->cookies = array('sid' => 'private');
        $client->replies = array(
            array(true, 'https://other.test/file', array(), 302),
            array(true, false, array(), 200),
        );
        $client->fetch('https://tracker.example/start');
        testAssertSame(Snoopy::CREDENTIAL_REDIRECT_REFUSED, $client->error, 'first chain is refused');
        testAssertSame(true, $client->fetch('https://tracker.example/independent'), 'next request works');
        testAssertSame('', $client->error, 'old refusal is not retained');
    },
    'redirect refusal log is bounded per source and target origin pair' => function () {
        global $log_file;
        $previous = $log_file;
        $log_file = tempnam(sys_get_temp_dir(), 'snoopy-latch-');
        try {
            $client = new SnoopyRedirectProbe();
            $client->cookies = array('sid' => 'private');
            $client->replies = array(
                array(true, 'https://core-latch-target.test/file?secret=1', array(), 302),
                array(true, 'https://core-latch-target.test/other?secret=2', array(), 302),
            );
            $client->fetch('https://core-latch-source.test/start');
            $client->fetch('https://core-latch-source.test/again');
            $log = file_get_contents($log_file);
            testAssertSame(1, substr_count($log, 'https://core-latch-source.test:443 -> https://core-latch-target.test:443'),
                'one classified line per origin pair');
            testAssertSame(false, strpos($log, 'secret=') !== false, 'URL query is redacted');
        } finally {
            unlink($log_file);
            $log_file = $previous;
        }
    },
    'redirect refusal log distinguishes scheme and effective port without URL secrets' => function () {
        global $log_file;
        $previous = $log_file;
        $log_file = tempnam(sys_get_temp_dir(), 'snoopy-origin-log-');
        try {
            $client = new SnoopyRedirectProbe();
            $client->cookies = array('sid' => 'private');
            $client->replies = array(
                array(true, 'http://user:pass@core-origin-log.test/private?token=one', array(), 302),
                array(true, 'https://core-origin-log.test:8443/private?token=two', array(), 302),
            );
            $client->fetch('https://core-origin-log.test/start?secret=one');
            $client->fetch('https://core-origin-log.test/again?secret=two');
            $log = file_get_contents($log_file);
            testAssertSame(1, substr_count($log,
                'https://core-origin-log.test:443 -> http://core-origin-log.test:80'),
                'HTTPS downgrade has its own origin pair');
            testAssertSame(1, substr_count($log,
                'https://core-origin-log.test:443 -> https://core-origin-log.test:8443'),
                'same host with a different port has its own origin pair');
            foreach (array('user:pass', '/private', 'token=', 'secret=') as $secret)
                testAssertSame(false, strpos($log, $secret) !== false,
                    'log omits ' . $secret);
        } finally {
            unlink($log_file);
            $log_file = $previous;
        }
    },
    'the last redirect belongs only to its own explicit request chain' => function () {
        $client = new SnoopyRedirectProbe();
        $client->replies = array(
            array(true, 'https://tracker.example/next', array(), 302),
            array(true, false, array(), 200),
            array(true, false, array(), 200),
        );
        testAssertSame(true, $client->fetch('https://tracker.example/start'), 'redirect chain');
        testAssertSame('https://tracker.example/next', $client->lastredirectaddr, 'chain keeps last redirect');
        testAssertSame(true, $client->fetch('https://tracker.example/independent'), 'explicit next request');
        testAssertSame('', $client->lastredirectaddr, 'old redirect does not survive');
    },
    'a 2xx Location can still produce a classified credential refusal' => function () {
        snoopyRespondWith('HTTP/1.1 200 OK\r\nLocation: http://same-location.test/landing\r\n');
        try {
            $client = new Snoopy();
            $client->cookies = array('sid' => 'private');
            testAssertSame(true, $client->fetch('https://same-location.test/start'),
                'the source response arrived');
            testAssertSame('200', $client->status, 'the source status remains 2xx');
            testAssertSame(Snoopy::CREDENTIAL_REDIRECT_REFUSED, $client->error,
                'the Location refusal is independent of status');
            testAssertSame('http://same-location.test/landing', $client->lastredirectaddr,
                'the refused Location remains visible to the caller');
        } finally {
            snoopyRespondWith('');
        }
    },
    'a Location header without a space follows the named host' => function () {
        snoopyRespondWith('HTTP/1.1 302 Found\r\nLocation:https://destination.test/file\r\n');
        $client = new Snoopy();
        testAssertSame(true, $client->fetch('https://tracker.test/start'), 'redirect chain completes');
        testAssertSame('https://destination.test/file', $client->lastredirectaddr,
            'Location without a space keeps its absolute URL');
        testAssertTrue(in_array('https://destination.test/file', snoopyCurlArgs(), true),
            'destination host is requested');
        snoopyRespondWith('');
    },
    'source URL cookie stays on its host in real HTTPS curl headers' => function () {
        $source = new Snoopy();
        $source->maxredirs = 0;
        testAssertSame(true, $source->fetchComplex('https://tracker.test/start:COOKIE:url_marker=private'),
            'source request completes');
        testAssertTrue(in_array('Cookie: url_marker=private', snoopyCurlArgs(), true),
            'source host receives its explicit URL cookie');

        putenv('SNOOPY_TEST_REDIRECT=https://dl.tracker.test/next');
        @unlink(getenv('SNOOPY_TEST_SEEN'));
        try {
            $sibling = new Snoopy();
            $sibling->redirectTrust = function ($url) {
                return UrlHost::urlIsOneOf($url, array('tracker.test'), 'https');
            };
            testAssertSame(true, $sibling->fetchComplex('https://tracker.test/start:COOKIE:url_marker=private'),
                'account-allowed sibling redirect completes');
            testAssertTrue(in_array('https://dl.tracker.test/next', snoopyCurlArgs(), true),
                'fake curl captured the sibling request');
            testAssertSame(false, in_array('Cookie: url_marker=private', snoopyCurlArgs(), true),
                'source URL cookie stays off the sibling curl header');

            putenv('SNOOPY_TEST_REDIRECT=https://tracker.test/next');
            @unlink(getenv('SNOOPY_TEST_SEEN'));
            $same = new Snoopy();
            testAssertSame(true, $same->fetchComplex('https://tracker.test/start:COOKIE:url_marker=private'),
                'same-host redirect completes');
            testAssertTrue(in_array('Cookie: url_marker=private', snoopyCurlArgs(), true),
                'same-host curl redirect keeps the URL cookie');
        } finally {
            putenv('SNOOPY_TEST_REDIRECT');
            @unlink(getenv('SNOOPY_TEST_SEEN'));
        }
    },
    'an account sibling redirect carries its session but not arbitrary caller facade cookies' => function () {
        putenv('SNOOPY_TEST_REDIRECT=https://dl.tracker.test/next');
        @unlink(getenv('SNOOPY_TEST_SEEN'));
        try {
            $client = new Snoopy();
            $client->redirectTrust = function ($url) {
                return UrlHost::urlIsOneOf($url, array('tracker.test'), 'https');
            };
            $client->cookies['caller'] = 'private';
            $client->cookies['session'] = 'saved';
            $client->markAccountCookies(array('session' => 'saved'));
            testAssertSame(true, $client->fetch('https://tracker.test/start'),
                'authorized sibling redirect completes');
            testAssertSame(true,
                snoopyRequestHasCookie(implode("\r\n", snoopyCurlArgs()), 'session=saved'),
                'marked selected account session reaches sibling');
            foreach(snoopyCurlArgs() as $argument)
                testAssertSame(false, strpos($argument, 'caller=private') !== false,
                    'unmarked caller facade cookie stays on its source origin');
        } finally {
            putenv('SNOOPY_TEST_REDIRECT');
            @unlink(getenv('SNOOPY_TEST_SEEN'));
        }
    },
    'response cookie Path matches only its redirect target on socket wire' => function () {
        $actual = array();
        foreach (array('/private/file', '/private2', '/public') as $target) {
            $client = new SnoopySocketReplies();
            $client->responses = array(
                "HTTP/1.1 302 Found\r\nLocation: http://tracker.test{$target}\r\n"
                    . "Set-Cookie: sid=private; Path=/private\r\n\r\n",
                "HTTP/1.1 200 OK\r\n\r\n",
            );
            testAssertSame(true, $client->fetch('http://tracker.test/private/login'),
                'same-origin socket redirect completes');
            $client->request(0);
            $actual[$target] = snoopyCookieHeaderValues($client->request(1));
        }
        testAssertSame(array('/private/file' => array('sid=private'),
            '/private2' => array(), '/public' => array()), $actual,
            'Path=/private is sent only to its path segment on socket wire');
    },
    'response cookie Path matches only its redirect target on curl wire' => function () {
        $actual = array();
        try {
            foreach (array('/private/file', '/private2', '/public') as $target) {
                putenv('SNOOPY_TEST_REDIRECT=https://tracker.test' . $target);
                putenv('SNOOPY_TEST_REDIRECT_COOKIE=sid=private; Path=/private; Secure');
                @unlink(getenv('SNOOPY_TEST_SEEN'));
                $client = new Snoopy();
                testAssertSame(true, $client->fetch('https://tracker.test/private/login'),
                    'same-origin curl redirect completes');
                $actual[$target] = snoopyCookieHeaderValues(snoopyCurlArgs());
            }
        } finally {
            putenv('SNOOPY_TEST_REDIRECT');
            putenv('SNOOPY_TEST_REDIRECT_COOKIE');
            @unlink(getenv('SNOOPY_TEST_SEEN'));
        }
        testAssertSame(array('/private/file' => array('sid=private'),
            '/private2' => array(), '/public' => array()), $actual,
            'Path=/private is sent only to its path segment on curl wire');
    },
    'two response cookies with one name retain distinct Paths on socket wire' => function () {
        $client = new SnoopySocketReplies();
        $client->responses = array(
            "HTTP/1.1 302 Found\r\nLocation: http://tracker.test/private/file\r\n"
                . "Set-Cookie: id=base; Path=/\r\n"
                . "Set-Cookie: id=inner; Path=/private\r\n\r\n",
            "HTTP/1.1 200 OK\r\n\r\n",
        );
        testAssertSame(true, $client->fetch('http://tracker.test/private/login'),
            'two-cookie socket redirect completes');
        $client->request(0);
        testAssertSame(array('id=inner; id=base'),
            snoopyCookieHeaderValues($client->request(1)),
            'one Cookie header retains both matching names, longer Path first');
    },
    'two response cookies with one name retain distinct Paths on curl wire' => function () {
        putenv('SNOOPY_TEST_REDIRECT=https://tracker.test/private/file');
        putenv('SNOOPY_TEST_REDIRECT_COOKIE=id=base; Path=/; Secure');
        putenv('SNOOPY_TEST_REDIRECT_COOKIE2=id=inner; Path=/private; Secure');
        @unlink(getenv('SNOOPY_TEST_SEEN'));
        try {
            $client = new Snoopy();
            testAssertSame(true, $client->fetch('https://tracker.test/private/login'),
                'two-cookie curl redirect completes');
            testAssertSame(array('id=inner; id=base'),
                snoopyCookieHeaderValues(snoopyCurlArgs()),
                'one Cookie header retains both matching names, longer Path first');
        } finally {
            putenv('SNOOPY_TEST_REDIRECT');
            putenv('SNOOPY_TEST_REDIRECT_COOKIE');
            putenv('SNOOPY_TEST_REDIRECT_COOKIE2');
            @unlink(getenv('SNOOPY_TEST_SEEN'));
        }
    },
    'a Secure response cookie stays off a later explicit HTTP wire' => function () {
        global $log_file;
        $previousLog = $log_file;
        $log_file = tempnam(sys_get_temp_dir(), 'snoopy-secure-refusal-');
        putenv('SNOOPY_TEST_REDIRECT=https://tracker.test/next');
        putenv('SNOOPY_TEST_REDIRECT_COOKIE=sid=secret; sEcUrE=ignored; Path=/');
        @unlink(getenv('SNOOPY_TEST_SEEN'));
        try {
            $client = new SnoopySocketReplies();
            testAssertSame(true, $client->fetch('https://tracker.test/start'),
                'same-origin HTTPS redirect completes');
            testAssertSame(array(), $client->cookies,
                'response cookie stays out of the public flat facade');
            $client->responses = array("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
            testAssertSame(true, $client->fetch('http://tracker.test/other'),
                'later explicit HTTP request completes');
            testAssertSame(false, snoopyRequestHasCookie($client->request(0), 'sid=secret'),
                'Secure response cookie stays off HTTP wire');
            $log = file_get_contents($log_file);
            testAssertSame(1, substr_count($log,
                'Snoopy: secure-response-cookie-http-refused host=tracker.test'),
                'suppression is visible once with a normalized host');
            testAssertSame(false, strpos($log, 'sid') !== false || strpos($log, 'secret') !== false,
                'cookie name and value stay out of the log');
        } finally {
            putenv('SNOOPY_TEST_REDIRECT');
            putenv('SNOOPY_TEST_REDIRECT_COOKIE');
            @unlink(getenv('SNOOPY_TEST_SEEN'));
            @unlink($log_file);
            $log_file = $previousLog;
        }
    },
    'a non-Secure response cookie keeps legacy explicit HTTP behavior' => function () {
        putenv('SNOOPY_TEST_REDIRECT=https://tracker.test/next');
        putenv('SNOOPY_TEST_REDIRECT_COOKIE=sid=plain; Path=/');
        @unlink(getenv('SNOOPY_TEST_SEEN'));
        try {
            $client = new SnoopySocketReplies();
            testAssertSame(true, $client->fetch('https://tracker.test/start'),
                'same-origin HTTPS redirect completes');
            $client->responses = array("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
            testAssertSame(true, $client->fetch('http://tracker.test/other'),
                'later explicit HTTP request completes');
            testAssertSame(true, snoopyRequestHasCookie($client->request(0), 'sid=plain'),
                'plain response cookie retains existing explicit-fetch behavior');
        } finally {
            putenv('SNOOPY_TEST_REDIRECT');
            putenv('SNOOPY_TEST_REDIRECT_COOKIE');
            @unlink(getenv('SNOOPY_TEST_SEEN'));
        }
    },
    'an explicit caller cookie can replace a Secure response cookie by name' => function () {
        putenv('SNOOPY_TEST_REDIRECT=https://tracker.test/next');
        putenv('SNOOPY_TEST_REDIRECT_COOKIE=sid=secret; Secure; Path=/');
        @unlink(getenv('SNOOPY_TEST_SEEN'));
        try {
            $client = new SnoopySocketReplies();
            testAssertSame(true, $client->fetch('https://tracker.test/start'),
                'same-origin HTTPS redirect completes');
            $client->cookies['sid'] = 'secret';
            $client->responses = array_fill(0, 2, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
            testAssertSame(true, $client->fetch('http://tracker.test/same-value'),
                'same-value explicit HTTP request completes');
            testAssertSame(true, snoopyRequestHasCookie($client->request(0), 'sid=secret'),
                'an explicit same-value caller cookie takes precedence over the response jar');
            $client->cookies['sid'] = 'caller-value';
            testAssertSame(true, $client->fetch('http://tracker.test/other'),
                'distinct-value explicit HTTP request completes');
            testAssertSame(true, snoopyRequestHasCookie($client->request(1), 'sid=caller-value'),
                'a distinct caller cookie retains the explicit-fetch facade');
        } finally {
            putenv('SNOOPY_TEST_REDIRECT');
            putenv('SNOOPY_TEST_REDIRECT_COOKIE');
            @unlink(getenv('SNOOPY_TEST_SEEN'));
        }
    },
    'a later non-Secure response replaces a Secure cookie of the same name' => function () {
        $client = new SnoopySocketReplies();
        $client->headers = array('Set-Cookie: sid=secret; Secure; Path=/');
        $client->setcookies();
        $client->headers = array('Set-Cookie: sid=plain; Path=/');
        $client->setcookies();
        $client->responses = array("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        testAssertSame(true, $client->fetch('http://tracker.test/other'),
            'later explicit HTTP request completes');
        testAssertSame(true, snoopyRequestHasCookie($client->request(0), 'sid=plain'),
            'the newer non-Secure cookie retains existing HTTP behavior');
    },
    'a Secure host-only cookie remains on its HTTPS host across explicit fetches' => function () {
        putenv('SNOOPY_TEST_REDIRECT=https://tracker.test/next');
        putenv('SNOOPY_TEST_REDIRECT_COOKIE=sid=secret; Secure; Path=/');
        @unlink(getenv('SNOOPY_TEST_SEEN'));
        try {
            $client = new Snoopy();
            testAssertSame(true, $client->fetch('https://tracker.test/start'),
                'same-origin HTTPS redirect completes');
            putenv('SNOOPY_TEST_REDIRECT');
            testAssertSame(true, $client->fetch('https://tracker.test/other'),
                'later explicit HTTPS same-host request completes');
            testAssertSame(true, in_array('Cookie: sid=secret', snoopyCurlArgs(), true),
                'host-only response cookie remains available on its own host');
            testAssertSame(true, $client->fetch('https://dl.tracker.test/other'),
                'later explicit HTTPS sibling request completes');
            testAssertSame(false, in_array('Cookie: sid=secret', snoopyCurlArgs(), true),
                'host-only response cookie stays off the sibling host');
        } finally {
            putenv('SNOOPY_TEST_REDIRECT');
            putenv('SNOOPY_TEST_REDIRECT_COOKIE');
            @unlink(getenv('SNOOPY_TEST_SEEN'));
        }
    },
    'a source URL cookie does not block an anonymous CDN redirect' => function () {
        putenv('SNOOPY_TEST_REDIRECT=https://cdn.test/file');
        putenv('SNOOPY_TEST_REDIRECT_COOKIE=fresh=origin; Path=/; Secure');
        @unlink(getenv('SNOOPY_TEST_SEEN'));
        try {
            $client = new Snoopy();
            testAssertSame(true, $client->fetchComplex('https://tracker.test/start:COOKIE:sid=source'),
                'source response completes');
            $args = snoopyCurlArgs();
            testAssertSame(true, in_array('https://cdn.test/file', $args, true),
                'anonymous CDN target is requested');
            $wire = implode("\r\n", $args);
            testAssertSame(false, snoopyRequestHasCookie($wire, 'sid=source'),
                'source URL cookie stays off CDN wire');
            testAssertSame(false, snoopyRequestHasCookie($wire, 'fresh=origin'),
                'source response cookie stays off CDN wire');
        } finally {
            putenv('SNOOPY_TEST_REDIRECT');
            putenv('SNOOPY_TEST_REDIRECT_COOKIE');
            @unlink(getenv('SNOOPY_TEST_SEEN'));
        }
    },
    'a real HTTPS cross-origin redirect never imports the source cookie' => function () {
        putenv('SNOOPY_TEST_REDIRECT=https://cdn.test/file');
        putenv('SNOOPY_TEST_REDIRECT_COOKIE=secret=one; Path=/');
        @unlink(getenv('SNOOPY_TEST_SEEN'));
        try {
            $client = new Snoopy();
            testAssertSame(true, $client->fetch('https://tracker.test/start'), 'anonymous redirect follows');
            testAssertSame('200', $client->status, 'redirect destination responds');
            testAssertSame(array(), $client->cookies, 'source cookie stays out of the flat jar');
        } finally {
            putenv('SNOOPY_TEST_REDIRECT');
            putenv('SNOOPY_TEST_REDIRECT_COOKIE');
        }
        testAssertSame(true, $client->fetch('https://other.test/independent'), 'later request');
        foreach (snoopyCurlArgs() as $arg) {
            testAssertSame(false, strpos($arg, 'Cookie:') === 0,
                'later host receives no source response cookie');
        }
    },
    'a later real HTTPS request does not carry Basic from earlier URL userinfo' => function () {
        $client = new Snoopy();
        testAssertSame(true, $client->fetch('https://alice:secret@tracker.example/one'), 'first request');
        testAssertTrue(in_array('Authorization: Basic ' . base64_encode('alice:secret'), snoopyCurlArgs(), true),
            'first curl command carries URL Basic authentication');
        testAssertSame(true, $client->fetch('https://other.test/two'), 'second request');
        foreach (snoopyCurlArgs() as $arg) {
            testAssertSame(false, strpos($arg, 'Authorization: Basic') !== false,
                'second curl command contains no previous Basic header');
        }
    },
    'explicit HTTPS POST forwards -X POST to curl' => function () {
        $client = new Snoopy();
        testAssertTrue(
            $client->fetch('https://example.test/resource', 'POST', 'application/x-www-form-urlencoded', ''),
            'HTTPS request did not complete through the curl test double'
        );
        $args = snoopyCurlArgs();
        $flag = array_search('-X', $args, true);
        testAssertTrue($flag !== false, 'Explicit HTTPS method was not passed to curl');
        testAssertSame(
            'POST',
            isset($args[$flag + 1]) ? $args[$flag + 1] : null,
            'Empty-body explicit POST request was not preserved'
        );
    },
    'legacy positional HTTPS request never adds -X' => function () {
        $client = new Snoopy();
        testAssertTrue(
            $client->_httpsrequest('https://example.test/legacy', 'application/x-www-form-urlencoded', 'payload'),
            'Legacy positional HTTPS request did not complete'
        );
        $args = snoopyCurlArgs();
        testAssertSame(
            false,
            array_search('-X', $args, true),
            'Legacy 3-argument call must leave the HTTP method to curl'
        );
        testAssertTrue(
            in_array('Content-type: application/x-www-form-urlencoded', $args, true),
            'Legacy positional content-type argument remains supported'
        );
        testAssertTrue(in_array('payload', $args, true), 'Legacy positional request body remains supported');
    },
    'explicit HTTPS GET with body keeps -X GET' => function () {
        $client = new Snoopy();
        testAssertTrue(
            $client->fetch('https://example.test/get-with-body', 'GET', 'text/plain', 'payload'),
            'Explicit GET-with-body request did not complete'
        );
        $args = snoopyCurlArgs();
        $flag = array_search('-X', $args, true);
        testAssertTrue(
            $flag !== false && isset($args[$flag + 1]) && $args[$flag + 1] === 'GET',
            'Explicit HTTPS GET method must not be changed to POST by curl -d'
        );
    },
    'private targets stay reachable while the guard is off' => function () {
        $client = new Snoopy();
        testAssertTrue(
            $client->fetch('https://127.0.0.1/feed'),
            'Default configuration must not block loopback targets'
        );
    },
    'the guard blocks a literal private address' => function () {
        $client = new Snoopy();
        $client->block_private = true;
        testAssertSame(false, $client->fetch('https://127.0.0.1/feed'), 'Loopback target was fetched anyway');
        testAssertTrue(
            strpos($client->error, '127.0.0.1') !== false,
            'Blocked fetch must name the offending address, got: ' . $client->error
        );
    },
    'the guard blocks the IPv6 loopback literal' => function () {
        $client = new Snoopy();
        $client->block_private = true;
        testAssertSame(false, $client->fetch('https://[::1]/feed'), 'IPv6 loopback target was fetched anyway');
    },
    'the guard leaves public literals alone' => function () {
        $client = new Snoopy();
        $client->block_private = true;
        testAssertTrue($client->fetch('https://93.184.216.34/feed'), 'Public literal was blocked: ' . $client->error);
    },
    'the allowlist exempts a host from the guard' => function () {
        $client = new Snoopy();
        $client->block_private = true;
        $client->private_allowlist = array('127.0.0.1');
        testAssertTrue($client->fetch('https://127.0.0.1/feed'), 'Allowlisted host was blocked: ' . $client->error);
    },
    'the guard blocks a hostname that resolves to loopback' => function () {
        $client = new Snoopy();
        $client->block_private = true;
        testAssertSame(false, $client->fetch('https://localhost/feed'), 'localhost was fetched anyway');
    },
    'a host that cannot be resolved is blocked, and says so' => function () {
        $client = new Snoopy();
        $client->block_private = true;
        testAssertSame(
            false,
            $client->fetch('https://tracker.nonexistent.invalid/feed'),
            'Unresolvable host was fetched anyway'
        );
        testAssertTrue(
            stripos($client->error, 'resolve') !== false,
            'Unresolvable host must be reported as such, got: ' . $client->error
        );
    },
    'the validated address is pinned for the HTTPS request' => function () {
        $client = new SnoopyResolvesToPublic();
        $client->block_private = true;
        testAssertTrue($client->fetch('https://tracker.test/feed'), 'Public host was blocked: ' . $client->error);
        $args = snoopyCurlArgs();
        $flag = array_search('--resolve', $args, true);
        testAssertTrue($flag !== false, 'Validated host must be pinned with --resolve');
        testAssertSame(
            'tracker.test:443:93.184.216.34',
            isset($args[$flag + 1]) ? $args[$flag + 1] : null,
            'Pinned address must be the one the guard validated'
        );
    },
    'guarded HTTPS fixes whether curl uses a configured proxy' => function () {
        $direct = new SnoopyResolvesToPublic();
        $direct->block_private = true;
        testAssertTrue($direct->fetch('https://tracker.test/favicon.ico'),
            'Guarded direct fetch completes');
        $args = snoopyCurlArgs();
        $flag = array_search('--noproxy', $args, true);
        testAssertSame('*', $flag === false ? null : ($args[$flag + 1] ?? null),
            'A curl environment proxy must not evade direct DNS pinning');

        $proxied = new SnoopyResolvesToPublic();
        $proxied->proxy_host = '127.0.0.1';
        $proxied->proxy_port = 3128;
        $proxied->block_private = true;
        testAssertTrue($proxied->fetch('https://tracker.test/favicon.ico'),
            'Guarded configured-proxy fetch completes');
        $args = snoopyCurlArgs();
        $flag = array_search('--noproxy', $args, true);
        testAssertSame('', $flag === false ? null : ($args[$flag + 1] ?? null),
            'NO_PROXY must not bypass the configured pinned proxy');
    },
    'an HTTP proxy receives only the checked public IP as its request target' => function () {
        $client = new SnoopyProxyTargetProbe();
        $client->proxy_host = '127.0.0.1';
        $client->proxy_port = 3128;
        $client->block_private = true;
        testAssertTrue($client->fetch('http://tracker.test/favicon.ico'),
            'Guarded proxy request must complete: ' . $client->error);
        testAssertSame('http://93.184.216.34/favicon.ico', $client->requestTarget,
            'The proxy must not resolve the user-selected hostname');
        testAssertSame('tracker.test', $client->requestHost,
            'The origin Host header must still identify the tracker');
    },
    'an HTTPS proxy CONNECT uses the checked public IP' => function () {
        $client = new SnoopyResolvesToPublic();
        $client->proxy_host = '127.0.0.1';
        $client->proxy_port = 3128;
        $client->block_private = true;
        testAssertTrue($client->fetch('https://tracker.test/favicon.ico'),
            'Guarded proxy request must complete: ' . $client->error);
        $args = snoopyCurlArgs();
        $flag = array_search('--connect-to', $args, true);
        testAssertTrue($flag !== false, 'The proxy CONNECT needs a pinned public IP');
        testAssertSame('tracker.test:443:93.184.216.34:443', $args[$flag + 1] ?? null,
            'The proxy CONNECT destination must be the validated address');
    },
    'an HTTPS proxy receives a checked IP target for plain HTTP' => function () {
        $client = new SnoopyResolvesToPublic();
        $client->proxy_proto = 'https';
        $client->proxy_host = '127.0.0.1';
        $client->proxy_port = 3128;
        $client->block_private = true;
        testAssertTrue($client->fetch('http://tracker.test/favicon.ico'),
            'Guarded HTTPS proxy request must complete: ' . $client->error);
        $args = snoopyCurlArgs();
        testAssertSame('http://93.184.216.34/favicon.ico', end($args),
            'HTTPS proxy cannot re-resolve the tracker hostname');
        testAssertTrue(in_array('Host: tracker.test', $args, true),
            'The transfer address must not replace the original Host header');
        testAssertSame('http://tracker.test/favicon.ico', $client->lastResponseURL,
            'The published response URL must retain the original host');
    },
    'a guarded proxy refuses private targets before connecting' => function () {
        $client = new SnoopyProxyTargetProbe();
        $client->proxy_host = '127.0.0.1';
        $client->proxy_port = 3128;
        $client->block_private = true;
        testAssertSame(false, $client->fetch('http://127.0.0.1/favicon.ico'),
            'A private literal must not reach the proxy');
        testAssertSame(null, $client->requestTarget,
            'No proxy request may be written for a private literal');
    },
    'the guard covers ranges filter_var calls public' => function () {
        $client = new Snoopy();
        $client->block_private = true;
        testAssertSame(false, $client->fetch('https://100.64.0.1/feed'), 'Carrier-grade NAT target was fetched anyway');
        testAssertSame(false, $client->fetch('https://192.0.0.1/feed'), 'IETF protocol assignment target was fetched anyway');
    },
    'the guard is configured from conf/config.php' => function () {
        $GLOBALS['httpBlockPrivateNetworks'] = true;
        $GLOBALS['httpPrivateNetworkAllowlist'] = array('127.0.0.1');
        $client = new Snoopy();
        unset($GLOBALS['httpBlockPrivateNetworks'], $GLOBALS['httpPrivateNetworkAllowlist']);
        testAssertTrue($client->block_private, 'Configured guard was not picked up');
        testAssertSame(false, $client->fetch('https://10.0.0.1/feed'), 'Configured guard did not block a private target');
        testAssertTrue($client->fetch('https://127.0.0.1/feed'), 'Configured allowlist was not picked up: ' . $client->error);
    },
    'a redirect into a private address is blocked too' => function () {
        snoopyRespondWith('HTTP/1.1 302 Found\r\nLocation: http://127.0.0.1/secret\r\n');
        $client = new SnoopyResolvesToPublic();
        $client->block_private = true;
        testAssertSame(
            false,
            $client->fetch('https://tracker.test/start'),
            'Redirect to a private address was followed'
        );
        testAssertTrue(
            strpos($client->error, '127.0.0.1') !== false,
            'Blocked redirect must name the offending address, got: ' . $client->error
        );
    },
    // A Location like "//host/path" is a network-path reference (RFC 3986
    // 4.2): it carries its own authority and inherits only the scheme. Kinozal
    // answers exactly that to a guest download, and resolving it against the
    // requested host produced https://dl.kinozal.guru:443//kinozal.guru/... --
    // an address that redirects again, until maxredirs runs out.
    'protocol-relative HTTPS redirect keeps the scheme without a follow-up request' => function () use ($seenPath) {
        @unlink($seenPath);
        putenv('SNOOPY_TEST_REDIRECT=//kinozal.guru/login.php?to=%2Fdownload.php%3Fid%3D1');
        try {
            $client = new Snoopy();
            $client->maxredirs = 0;
            testAssertTrue(
                $client->fetch('https://dl.kinozal.guru/download.php?id=1'),
                'The source HTTPS response must complete'
            );
            testAssertSame(
                'https://kinozal.guru/login.php?to=%2Fdownload.php%3Fid%3D1',
                $client->lastredirectaddr,
                'The resolved redirect keeps HTTPS and names the destination host'
            );
            $args = snoopyCurlArgs();
            testAssertSame('https://dl.kinozal.guru/download.php?id=1', end($args),
                'The fixture sends only the source HTTPS request');
            testAssertSame('302', $client->status, 'The source response remains visible');
        } finally {
            putenv('SNOOPY_TEST_REDIRECT');
            @unlink($seenPath);
        }
    },
    // Same rule on the plain-HTTP path, which parses its headers off the
    // socket instead of curl's dump file. A socket pair stands in for the
    // connection: the response is written from the far end, whose write side
    // is then shut down so Snoopy sees EOF while its own request still has
    // somewhere to go.
    'protocol-relative redirect inherits the scheme over plain HTTP' => function () {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        testAssertTrue(is_array($pair), 'Unable to create the socket pair standing in for the connection');
        list($near, $far) = $pair;
        fwrite($far, "HTTP/1.1 302 Found\r\nLocation: //kinozal.guru/login.php?to=x\r\n\r\n");
        stream_socket_shutdown($far, STREAM_SHUT_WR);

        $client = new Snoopy();
        $client->host = 'dl.kinozal.guru';
        $client->port = 80;
        try {
            testAssertTrue(
                $client->_httprequest('/download.php?id=1', $near, 'http://dl.kinozal.guru/download.php?id=1', 'GET'),
                'Plain HTTP request did not complete'
            );
        } finally {
            fclose($near);
            fclose($far);
        }
        testAssertSame(
            'http://kinozal.guru/login.php?to=x',
            $client->_redirectaddr,
            'The redirect must be followed to the host it names'
        );
    },
    'a redirect within the same host keeps the credentials' => function () use ($seenPath) {
        @unlink($seenPath);
        putenv('SNOOPY_TEST_REDIRECT=https://tracker.test/elsewhere');
        try {
            $client = new Snoopy();
            $client->cookies['session'] = 'secret-session';
            $client->rawheaders['Authorization'] = 'Bearer secret-token';
            testAssertTrue(
                $client->fetch('https://user:pass@tracker.test/feed'),
                'Redirected HTTPS request did not complete'
            );
            $args = snoopyCurlArgs();
            testAssertTrue(
                in_array('Authorization: Bearer secret-token', $args, true),
                'A caller header must survive a redirect on the same host'
            );
            testAssertTrue(
                in_array('Cookie: session=secret-session', $args, true),
                'The cookie jar must survive a redirect on the same host'
            );
        } finally {
            putenv('SNOOPY_TEST_REDIRECT');
            @unlink($seenPath);
        }
    },
    // curl -k turns off certificate checking. It used to be appended to every
    // HTTPS fetch with no way to stop it, which made every feed, torrent
    // download and tracker login readable and changeable by anything on the
    // path.
    'HTTPS fetches check the certificate by default' => function () {
        $client = new Snoopy();
        testAssertTrue($client->fetch('https://tracker.test/feed'), 'HTTPS request did not complete');
        testAssertSame(
            false,
            array_search('-k', snoopyCurlArgs(), true),
            'Certificate checking was turned off without being asked'
        );
    },
    'turning the check off puts -k back' => function () {
        $client = new Snoopy();
        $client->verify_certificates = false;
        testAssertTrue($client->fetch('https://tracker.test/feed'), 'HTTPS request did not complete');
        testAssertTrue(
            array_search('-k', snoopyCurlArgs(), true) !== false,
            'An install that opts out must still reach a self-signed host'
        );
    },
    'the certificate check is configured from conf/config.php' => function () {
        $GLOBALS['httpVerifyCertificates'] = false;
        $client = new Snoopy();
        unset($GLOBALS['httpVerifyCertificates']);
        testAssertSame(false, $client->verify_certificates, 'Configured opt-out was not picked up');
        testAssertTrue($client->fetch('https://tracker.test/feed'), 'HTTPS request did not complete');
        testAssertTrue(
            array_search('-k', snoopyCurlArgs(), true) !== false,
            'Configured opt-out did not reach curl'
        );
    },
    'the proxy leg is checked on the same terms' => function () {
        $client = new Snoopy();
        $client->proxy_host = '127.0.0.1';
        $client->proxy_port = 3128;
        testAssertTrue($client->fetch('https://tracker.test/feed'), 'Proxied HTTPS request did not complete');
        $args = snoopyCurlArgs();
        testAssertSame(
            false,
            array_search('--proxy-insecure', $args, true),
            'The proxy leg was made insecure while checking is on'
        );
        testAssertTrue(
            array_search('--proxy', $args, true) !== false,
            'The proxy itself must still be passed to curl'
        );

        $client = new Snoopy();
        $client->verify_certificates = false;
        $client->proxy_host = '127.0.0.1';
        $client->proxy_port = 3128;
        testAssertTrue($client->fetch('https://tracker.test/feed'), 'Proxied HTTPS request did not complete');
        testAssertTrue(
            array_search('--proxy-insecure', snoopyCurlArgs(), true) !== false,
            'An install that opts out must still reach a self-signed proxy'
        );
    },
    // curl exits 60 when it cannot verify the peer. "error 60" on its own tells
    // an admin with a self-signed indexer nothing about what to do.
    'a certificate failure says what it was and how to opt out' => function () {
        putenv('SNOOPY_TEST_EXIT=60');
        try {
            $client = new Snoopy();
            testAssertSame(false, $client->fetch('https://tracker.test/feed'), 'A failed fetch reported success');
            testAssertTrue(
                stripos($client->error, 'certificate') !== false,
                'The failure must say it was the certificate, got: ' . $client->error
            );
            testAssertTrue(
                strpos($client->error, 'httpVerifyCertificates') !== false,
                'The failure must name the setting that turns it off, got: ' . $client->error
            );
            testAssertTrue(
                strpos($client->error, 'tracker.test') !== false,
                'The failure must name the host, got: ' . $client->error
            );
        } finally {
            putenv('SNOOPY_TEST_EXIT');
        }
    },
    'an ordinary curl failure is reported as before' => function () {
        putenv('SNOOPY_TEST_EXIT=7');
        try {
            $client = new Snoopy();
            testAssertSame(false, $client->fetch('https://tracker.test/feed'), 'A failed fetch reported success');
            testAssertSame(
                'Error: cURL could not retrieve the document, error 7.',
                $client->error,
                'A non-certificate failure must keep its wording'
            );
        } finally {
            putenv('SNOOPY_TEST_EXIT');
        }
    },
    // curl exits 35 for any failure in the TLS handshake, verification
    // included or not: a port answering something that is not TLS reaches it,
    // and so does a protocol or cipher mismatch. Naming the certificate there
    // sends an operator to install a certificate authority for a server that
    // presented no certificate at all.
    'a handshake failure is not reported as a certificate failure' => function () {
        putenv('SNOOPY_TEST_EXIT=35');
        try {
            $client = new Snoopy();
            testAssertSame(false, $client->fetch('https://tracker.test/feed'), 'A failed fetch reported success');
            testAssertSame(
                'Error: cURL could not retrieve the document, error 35.',
                $client->error,
                'A handshake failure must not be diagnosed as a certificate'
            );
        } finally {
            putenv('SNOOPY_TEST_EXIT');
        }
    },
    // The advice is to turn verification off. An install that has already
    // turned it off is told to do the thing it did, about a check that did
    // not run -- curl was given -k.
    'an install that already opted out is not told to opt out' => function () {
        foreach (array(35, 51, 60, 77) as $exit) {
            putenv('SNOOPY_TEST_EXIT=' . $exit);
            try {
                $client = new Snoopy();
                $client->verify_certificates = false;
                testAssertSame(false, $client->fetch('https://tracker.test/feed'), 'A failed fetch reported success');
                testAssertTrue(
                    strpos($client->error, 'httpVerifyCertificates') === false,
                    'With verification off, exit ' . $exit . ' must not name the setting, got: ' . $client->error
                );
            } finally {
                putenv('SNOOPY_TEST_EXIT');
            }
        }
    },
    // -k and --proxy-insecure are one setting, so either leg can be the one
    // that failed, and the exit code does not say which. The message names
    // the host it was fetching from; with a proxy in the way that host may
    // have presented no certificate at all.
    'a proxied certificate failure does not blame the origin alone' => function () {
        putenv('SNOOPY_TEST_EXIT=60');
        try {
            $client = new Snoopy();
            $client->proxy_host = '127.0.0.1';
            $client->proxy_port = 3128;
            $client->proxy_proto = 'https';
            testAssertSame(false, $client->fetch('https://tracker.test/feed'), 'A failed fetch reported success');
            testAssertTrue(
                stripos($client->error, 'proxy') !== false,
                'A proxied failure must say the proxy could be the one, got: ' . $client->error
            );
            testAssertTrue(
                strpos($client->error, 'tracker.test') !== false,
                'and must still name the host it was fetching, got: ' . $client->error
            );
        } finally {
            putenv('SNOOPY_TEST_EXIT');
        }
    },
    // From upstream's tests/php/SnoopyTest.php: the #3379 cases for
    // $httpMaxResponseLength and the #3370 cases the fork's gzip tests above
    // do not already close. They run against the fork's own decoder and
    // socket helper, and name the fork's refusal reasons for an oversized
    // plain body: oversized-response on the socket path,
    // unreadable-or-oversized-response on the curl path.

    // $maxlength bounds the compressed body at 4 MiB, and zeros compress
    // about 1000:1, so what a server sends in 64 KiB inflates to 64 MiB; the
    // child has 32 MiB, which is where memory_limit used to end the request
    // with a fatal error. The bound refuses the body instead, as a failed
    // fetch with a status below 100 and the reason in ->error.
    'a gzip body inflating past maxlength is refused before it fills memory' => function () {
        $server = new SnoopyGzipServer(array('zeros' => snoopyGzipZeros(64 * 1048576)));
        try {
            $seen = $server->fetchInChild('/zeros', '32M');
        } finally {
            $server->close();
        }
        testAssertSame(false, $seen['fetched'], 'An oversized body was reported as fetched');
        testAssertSame(Snoopy::RESPONSE_BODY_FAILED, $seen['status'], 'The refusal must carry its own status');
        testAssertSame('invalid-or-oversized-gzip', $seen['error'], 'The refusal must say why');
        testAssertSame(0, $seen['length'], 'A refused body must not be handed out in part');
    },
    'a small gzip body still decodes whole over plain http' => function () {
        $body = str_repeat("The quick brown fox jumps over the lazy dog.\n", 100);
        $server = new SnoopyGzipServer(array('small' => gzencode($body, 9)));
        try {
            $seen = $server->fetchInChild('/small', '32M');
        } finally {
            $server->close();
        }
        testAssertSame(true, $seen['fetched'], 'A small gzip body was refused: ' . $seen['error']);
        testAssertSame('200', $seen['status'], 'The status is still read off the response');
        testAssertSame(strlen($body), $seen['length'], 'The body must come back whole');
        testAssertSame(sha1($body), $seen['sha1'], 'The body must come back unchanged');
    },
    // The bound is $maxlength itself, on the inflated size: that many bytes
    // are a body, one more is not. Over the socket pair, in this process.
    'the bound is maxlength exactly: that many bytes decode, one more is refused' => function () {
        $exact = str_repeat('a', 100000);
        $client = new SnoopySocketReplies();
        $client->responses = array(
            snoopyGzipResponse(gzencode($exact, 9)),
            snoopyGzipResponse(gzencode($exact . 'a', 9)),
        );
        $client->maxlength = 100000;
        testAssertTrue($client->fetch('http://tracker.test/exact'), 'A body of exactly maxlength bytes was refused: ' . $client->error);
        testAssertSame($exact, $client->results, 'A body of exactly maxlength bytes must come back whole');
        testAssertSame(false, $client->fetch('http://tracker.test/over'), 'A body one byte past maxlength was accepted');
        testAssertSame(Snoopy::RESPONSE_BODY_FAILED, $client->status, 'The refusal must carry its own status');
        testAssertSame('invalid-or-oversized-gzip', $client->error, 'The refusal must say why');
        testAssertSame('', $client->results, 'A refused body must not be handed out in part');
    },
    // A body that gzinflate cannot read used to come back as results === false
    // under a 200, which callers read as an empty page (bulk_magnet and the
    // RSS plugin then write a 0-byte .torrent and report it downloaded), and
    // the external-gzip fallback handed back exec()'s output array. It is
    // refused on the same terms as an oversized one: gzinflate's return value
    // does not say which, and both become one classified reason on purpose.
    'a body that is not gzip at all is refused rather than returned as false' => function () {
        $client = new SnoopySocketReplies();
        $client->responses = array(snoopyGzipResponse('this is not a gzip member'));
        testAssertSame(false, $client->fetch('http://tracker.test/plain'), 'An unreadable body was reported as fetched');
        testAssertSame(Snoopy::RESPONSE_BODY_FAILED, $client->status, 'The refusal must carry its own status');
        testAssertSame('invalid-or-oversized-gzip', $client->error, 'The refusal must say why');
        testAssertSame('', $client->results, 'A refused body must be empty, not false');
    },
    // A 304 or 204 may carry Content-Encoding: gzip with no body behind it
    // (RFC 9110 only says a 304 SHOULD NOT), and the RSS plugin sends
    // If-None-Match and reads a 304 as "unchanged". Such an answer is an empty
    // body under its own status, never a refusal. An empty gzip member is the
    // same answer, spelled out. Ported from upstream #3370.
    'a bodyless answer with a gzip header keeps its status and an empty body' => function () {
        $answers = array(
            '304' => "HTTP/1.1 304 Not Modified\r\nETag: \"x\"\r\nContent-Encoding: gzip\r\n\r\n",
            '204' => "HTTP/1.1 204 No Content\r\nContent-Encoding: gzip\r\n\r\n",
            '200' => "HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\n\r\n",
            '200 with an empty member' => snoopyGzipResponse(gzencode('', 9)),
        );
        $client = new SnoopySocketReplies();
        $client->responses = array_values($answers);
        foreach ($answers as $label => $answer) {
            $status = substr($label, 0, 3);
            testAssertTrue($client->fetch('http://tracker.test/feed'), 'A bodyless ' . $label . ' with a gzip header was refused: ' . $client->error);
            testAssertSame($status, (string)$client->status, 'A bodyless ' . $label . ' must keep its status');
            testAssertSame('', $client->results, 'A bodyless ' . $label . ' must be an empty string, not false');
        }
    },
    // The curl path decodes the same way, off the file curl wrote. The fake
    // curl copies $SNOOPY_TEST_BODY_FILE into it.
    'the curl path refuses an oversized gzip body too' => function () use ($bodyPath) {
        file_put_contents($bodyPath, snoopyGzipZeros(1048576));
        putenv('SNOOPY_TEST_BODY_FILE=' . $bodyPath);
        snoopyRespondWith('HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\n');
        try {
            $client = new Snoopy();
            $client->maxlength = 100000;
            testAssertSame(false, $client->fetch('https://tracker.test/feed'), 'An oversized body was reported as fetched');
            testAssertSame(Snoopy::RESPONSE_BODY_FAILED, $client->status, 'The refusal must carry its own status');
            testAssertSame('invalid-or-oversized-gzip', $client->error, 'The refusal must say why');
            testAssertSame('', $client->results, 'A refused body must not be handed out in part');
        } finally {
            putenv('SNOOPY_TEST_BODY_FILE');
            putenv('SNOOPY_TEST_RESPONSE');
        }
    },
    // $httpMaxResponseLength is the operator's handle on the bound. A
    // conf/config.php that predates it keeps what the class ships with.
    'the bound is 4 MiB when conf/config.php says nothing' => function () {
        unset($GLOBALS['httpMaxResponseLength']);
        $client = new Snoopy();
        testAssertSame(4194304, $client->maxlength, 'The shipped bound must survive an absent setting');
    },
    'the bound is configured from conf/config.php' => function () {
        $GLOBALS['httpMaxResponseLength'] = 100000;
        $client = new Snoopy();
        unset($GLOBALS['httpMaxResponseLength']);
        testAssertSame(100000, $client->maxlength, 'Configured bound was not picked up');
    },
    // Anything that is not a positive byte count would refuse every fetch, and
    // an operator who wrote one did not mean that, so the shipped bound stands.
    'a bound that is not a positive byte count is ignored' => function () {
        foreach (array(0, -1, 'four megabytes') as $value) {
            $GLOBALS['httpMaxResponseLength'] = $value;
            $client = new Snoopy();
            unset($GLOBALS['httpMaxResponseLength']);
            testAssertSame(
                4194304,
                $client->maxlength,
                'A bound of ' . var_export($value, true) . ' must leave the shipped one in place'
            );
        }
    },
    // Raising the bound is the point of the setting: a body the shipped 4 MiB
    // refuses comes back whole once an operator asks for more. Zeros keep the
    // wire small, so what is measured here is the inflated size.
    'a raised bound accepts a body the shipped one refuses' => function () {
        $size = 5 * 1048576;
        $encoded = snoopyGzipZeros($size);
        $refusing = new SnoopySocketReplies();
        $refusing->responses = array(snoopyGzipResponse($encoded));
        testAssertSame(false, $refusing->fetch('http://tracker.test/big'), 'The shipped bound accepted a 5 MiB body');
        testAssertSame(Snoopy::RESPONSE_BODY_FAILED, $refusing->status, 'The refusal must carry its own status');
        $GLOBALS['httpMaxResponseLength'] = 8 * 1048576;
        $raised = new SnoopySocketReplies();
        $raised->responses = array(snoopyGzipResponse($encoded));
        unset($GLOBALS['httpMaxResponseLength']);
        testAssertTrue($raised->fetch('http://tracker.test/big'), 'A body under the raised bound was refused: ' . $raised->error);
        testAssertSame($size, strlen($raised->results), 'A body under the raised bound must come back whole');
    },
    // The bound means one thing whatever the body arrived as: that many bytes
    // are a document, one more is not, and the one more is refused rather than
    // quietly dropped -- a caller handed a truncated body under a 200 cannot
    // tell it from a complete one.
    'a lowered bound is the same boundary for gzip and for plaintext' => function () {
        $limit = 50000;
        $exact = str_repeat('a', $limit);
        $over = $exact . 'a';
        $GLOBALS['httpMaxResponseLength'] = $limit;
        $client = new SnoopySocketReplies();
        $client->responses = array(
            "HTTP/1.1 200 OK\r\n\r\n" . $exact,
            "HTTP/1.1 200 OK\r\n\r\n" . $over,
            snoopyGzipResponse(gzencode($exact, 9)),
            snoopyGzipResponse(gzencode($over, 9)),
        );
        unset($GLOBALS['httpMaxResponseLength']);
        foreach (array('plaintext' => 'oversized-response', 'gzip' => 'invalid-or-oversized-gzip') as $encoding => $reason) {
            testAssertTrue($client->fetch('http://tracker.test/exact'), 'A ' . $encoding . ' body of exactly the bound was refused: ' . $client->error);
            testAssertSame($exact, $client->results, 'A ' . $encoding . ' body of exactly the bound must come back whole');
            testAssertSame(false, $client->fetch('http://tracker.test/over'), 'A ' . $encoding . ' body one byte past the bound was accepted');
            testAssertSame(Snoopy::RESPONSE_BODY_FAILED, $client->status, 'An oversized ' . $encoding . ' body must carry the refusal status');
            testAssertSame($reason, $client->error, 'An oversized ' . $encoding . ' body must say why it was refused');
            testAssertSame('', $client->results, 'An oversized ' . $encoding . ' body must not be handed out in part');
        }
    },
    'a lowered bound is the same boundary on the curl path' => function () use ($bodyPath) {
        $limit = 50000;
        $exact = str_repeat('a', $limit);
        putenv('SNOOPY_TEST_BODY_FILE=' . $bodyPath);
        $GLOBALS['httpMaxResponseLength'] = $limit;
        try {
            foreach (array('plaintext' => 'unreadable-or-oversized-response', 'gzip' => 'invalid-or-oversized-gzip') as $encoding => $reason) {
                $gzipped = $encoding === 'gzip';
                snoopyRespondWith($gzipped ? 'HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\n' : 'HTTP/1.1 200 OK\r\n');
                file_put_contents($bodyPath, $gzipped ? gzencode($exact, 9) : $exact);
                $client = new Snoopy();
                testAssertTrue($client->fetch('https://tracker.test/exact'), 'A ' . $encoding . ' body of exactly the bound was refused on the curl path: ' . $client->error);
                testAssertSame($exact, $client->results, 'A ' . $encoding . ' body of exactly the bound must come back whole on the curl path');
                file_put_contents($bodyPath, $gzipped ? gzencode($exact . 'a', 9) : $exact . 'a');
                $client = new Snoopy();
                testAssertSame(false, $client->fetch('https://tracker.test/over'), 'A ' . $encoding . ' body one byte past the bound was accepted on the curl path');
                testAssertSame(Snoopy::RESPONSE_BODY_FAILED, $client->status, 'An oversized ' . $encoding . ' body must carry the refusal status on the curl path');
                testAssertSame($reason, $client->error, 'An oversized ' . $encoding . ' body must say why it was refused on the curl path');
                testAssertSame('', $client->results, 'An oversized ' . $encoding . ' body must not be handed out in part on the curl path');
            }
        } finally {
            unset($GLOBALS['httpMaxResponseLength']);
            putenv('SNOOPY_TEST_BODY_FILE');
            putenv('SNOOPY_TEST_RESPONSE');
        }
    },
    // curl writes the whole answer to a file of its own, and reading all of
    // that file back is how an answer far past the bound ends the request with
    // a memory_limit fatal error before anything can refuse it. One byte past
    // the bound is all that is read, so the refusal fits in the memory the
    // bound implies: a child holding 32 MiB refuses a 64 MiB answer.
    'the curl path refuses a huge answer without reading it into memory' => function () use ($curlPath) {
        $huge = tempnam(sys_get_temp_dir(), 'snoopy-huge-');
        $child = tempnam(sys_get_temp_dir(), 'snoopy-child-');
        $handle = fopen($huge, 'w');
        ftruncate($handle, 64 * 1048576);
        fclose($handle);
        file_put_contents($child, <<<'PHP'
<?php
require_once($argv[1]);
$pathToExternals['curl'] = $argv[2];
$client = new Snoopy();
$client->maxlength = 1048576;
$fetched = $client->fetch('https://tracker.test/huge');
echo json_encode(array(
    'fetched' => $fetched,
    'status' => $client->status,
    'error' => $client->error,
    'length' => is_string($client->results) ? strlen($client->results) : null,
));
PHP
        );
        putenv('SNOOPY_TEST_BODY_FILE=' . $huge);
        try {
            $process = proc_open(
                array(PHP_BINARY, '-c', SnoopyGzipServer::SUITE_INI, '-d', 'memory_limit=32M',
                    $child, __DIR__ . '/../../php/Snoopy.class.inc', $curlPath),
                array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
                $pipes
            );
            testAssertTrue(is_resource($process), 'Could not start the fetching child');
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $seen = json_decode($stdout, true);
            testAssertTrue(
                is_array($seen),
                'The child exited ' . $exit . ' without answering, which is what reading the whole file does; it printed: '
                . trim($stdout . "\n" . $stderr)
            );
            testAssertSame(false, $seen['fetched'], 'A 64 MiB answer was reported as fetched');
            testAssertSame(Snoopy::RESPONSE_BODY_FAILED, $seen['status'], 'The refusal must carry its own status');
            testAssertSame('unreadable-or-oversized-response', $seen['error'], 'The refusal must say why');
            testAssertSame(0, $seen['length'], 'A refused body must not be handed out in part');
        } finally {
            putenv('SNOOPY_TEST_BODY_FILE');
            putenv('SNOOPY_TEST_RESPONSE');
            @unlink($huge);
            @unlink($child);
        }
    },
    // The bound covers the bytes on the wire too, so a body already longer
    // than it is refused without being inflated at all.
    'a gzip body already past the bound on the wire is refused before inflating' => function () {
        $GLOBALS['httpMaxResponseLength'] = 50000;
        $client = new SnoopySocketReplies();
        $client->responses = array(snoopyGzipResponse(gzencode(str_repeat('a', 80000), 0)));
        unset($GLOBALS['httpMaxResponseLength']);
        testAssertSame(false, $client->fetch('http://tracker.test/stored'), 'A body oversized on the wire was accepted');
        testAssertSame(Snoopy::RESPONSE_BODY_FAILED, $client->status, 'The refusal must carry its own status');
        testAssertSame('oversized-response', $client->error, 'The refusal must say it was the size, not the gzip');
        testAssertSame('', $client->results, 'A refused body must not be handed out in part');
    },
);

$status = testRunCases($tests, function () {
    snoopyRespondWith('HTTP/1.1 200 OK\r\n');
});

putenv('SNOOPY_TEST_ARGS');
putenv('SNOOPY_TEST_SEEN');
@unlink($seenPath);
@unlink($curlPath);
@unlink($argsPath);
@unlink($bodyPath);
@unlink($_ENV['RU_LOG_FILE']);
exit($status);
