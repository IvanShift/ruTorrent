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

// Fake curl: records every argument, then fabricates a successful response.
// $SNOOPY_TEST_ARGS holds the arguments of the LAST invocation only, so a
// redirect test reads the request Snoopy made after following the redirect.
// With $SNOOPY_TEST_REDIRECT set, the first invocation answers 302 with that
// Location instead; $SNOOPY_TEST_SEEN is how the script remembers it did.
$curlPath = tempnam(sys_get_temp_dir(), 'snoopy-curl-');
$argsPath = tempnam(sys_get_temp_dir(), 'snoopy-args-');
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
	printf 'HTTP/1.1 302 Found\r\nLocation: %s\r\n' "$SNOOPY_TEST_REDIRECT" > "$header_file"
	if [ -n "$SNOOPY_TEST_REDIRECT_COOKIE" ]; then
		printf 'Set-Cookie: %s\r\n' "$SNOOPY_TEST_REDIRECT_COOKIE" >> "$header_file"
	fi
	printf '\r\n' >> "$header_file"
else
	printf '%b\r\n' "${SNOOPY_TEST_RESPONSE:-HTTP/1.1 200 OK\r\n}" > "$header_file"
fi
if [ -n "$SNOOPY_TEST_BODY_FILE" ]; then
	cp "$SNOOPY_TEST_BODY_FILE" "$body_file"
else
	: > "$body_file"
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

function snoopyPlainHttpReply($header, $body, $limit = null, $client = null)
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    testAssertTrue(is_array($pair), 'Unable to open gzip transport fixture');
    list($near, $far) = $pair;
    fwrite($far, "HTTP/1.1 200 OK\r\n".$header."\r\n\r\n".$body);
    stream_socket_shutdown($far, STREAM_SHUT_WR);
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

$tests = array(
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
        testAssertSame(false,
            strpos($client->request(1), "Cookie: fresh=secret\r\n") !== false,
            'non-redirect response cookie is not imported later');
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
            testAssertSame(true, $fallback->fetch('https://example.test/file'),
                'HTTPS external gzip fallback completes');
            testAssertSame('https decoded', $fallback->results,
                'HTTPS fallback reads the curl body');
            testAssertSame('200', $fallback->status,
                'gzip child exit does not replace curl HTTP status');
            testAssertSame(false, in_array('Accept-Encoding: gzip', snoopyCurlArgs(), true),
                'fallback does not advertise gzip without zlib');
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
    'external gzip fallback keeps paths with spaces and bounds output' => function () {
        global $pathToExternals;
        $wrapper = sys_get_temp_dir() . '/snoopy gzip ' . getmypid() . '.sh';
        file_put_contents($wrapper, "#!/bin/sh\nexec gzip \"\$@\"\n");
        chmod($wrapper, 0700);
        $previous = $pathToExternals['gzip'] ?? null;
        $pathToExternals['gzip'] = $wrapper;
        try {
            list($ok, $client) = snoopyPlainHttpReply('Content-Encoding: gzip',
                gzencode('external body'), null, new SnoopyWithoutZlib());
            testAssertSame(true, $ok, 'fallback decoded gzip through argv-safe command');
            testAssertSame('external body', $client->results, 'fallback keeps its body');
            list($ok, $client) = snoopyPlainHttpReply('Content-Encoding: gzip',
                gzencode(str_repeat('x', 4096)), 128, new SnoopyWithoutZlib());
            testAssertSame(false, $ok, 'fallback refuses expanded body over maxlength');
            testAssertSame('', $client->results, 'fallback does not expose oversized body');
            list($ok, $client) = snoopyPlainHttpReply('Content-Encoding: gzip',
                gzencode('first').gzencode('second'), null, new SnoopyWithoutZlib());
            testAssertSame(true, $ok, 'fallback decodes every gzip member');
            testAssertSame('firstsecond', $client->results, 'fallback keeps every member body');
            list($ok, $client) = snoopyPlainHttpReply('Content-Encoding: gzip',
                gzencode('first').'junk', null, new SnoopyWithoutZlib());
            testAssertSame(false, $ok, 'fallback refuses trailing garbage');
            list($ok, $client) = snoopyPlainHttpReply('Content-Encoding: gzip',
                gzencode('first')."\0\0", null, new SnoopyWithoutZlib());
            testAssertSame(true, $ok, 'fallback accepts gzip zero padding');
            testAssertSame('first', $client->results, 'fallback padding adds no body data');
        } finally {
            $pathToExternals['gzip'] = $previous;
            unlink($wrapper);
        }
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
    'anonymous redirects may carry conditional validators to the destination' => function () {
        $client = new SnoopyRedirectProbe();
        $client->rawheaders['If-None-Match'] = '"v1"';
        $client->rawheaders['If-Modified-Since'] = 'Wed, 21 Oct 2015 07:28:00 GMT';
        $client->replies = array(
            array(true, 'https://cdn.test/feed', array(), 302),
            array(true, false, array(), 200),
        );
        testAssertSame(true, $client->fetch('https://tracker.test/feed'), 'anonymous validator chain follows');
        testAssertSame(2, count($client->requests), 'destination is requested');
        testAssertSame($client->requests[0][6], $client->requests[1][6],
            'conditional validators remain request metadata, not credentials');
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
);

$status = testRunCases($tests, function () {
    snoopyRespondWith('HTTP/1.1 200 OK\r\n');
});

putenv('SNOOPY_TEST_ARGS');
putenv('SNOOPY_TEST_SEEN');
@unlink($seenPath);
@unlink($curlPath);
@unlink($argsPath);
@unlink($_ENV['RU_LOG_FILE']);
exit($status);
