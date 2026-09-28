<?php

require_once(__DIR__ . '/../../php/TestCase.php');

$_ENV['RU_LOG_FILE'] = (getenv('TMPDIR') ?: sys_get_temp_dir())
    . '/loginmgr-redirect-wire-' . getmypid() . '.log';
require_once(__DIR__ . '/../../../plugins/loginmgr/accounts.php');

class RedirectScopeWireData
{
    public $loaded = true;
    public function store($client) { return true; }
    public function remove() {}
}

class RedirectScopeWireAccount extends commonAccount
{
    public $url = 'https://tracker.example:8443';
    protected function isOK($client) { return true; }
    protected function loadData($client = null)
    {
        $client->cookies['session'] = 'saved';
        $client->markAccountCookies(array('session' => 'saved'));
        return new RedirectScopeWireData();
    }
    protected function login($client, $login, $password, &$url, &$method,
        &$content_type, &$body, &$is_result_fetched)
    {
        throw new RuntimeException('A cached session must not log in during a redirect probe');
    }
    public function test($url)
    {
        return UrlHost::urlIsOneOf($url, array('tracker.example'), 'https', '/file/');
    }
}

// The real HTTP request writer and response parser run against socket pairs.
class RedirectScopeSocketReplies extends Snoopy
{
    public $responses = array();
    private $peers = array();

    public function connect()
    {
        testAssertTrue(count($this->responses) > 0, 'Unexpected socket request');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        testAssertTrue(is_array($pair), 'Socket pair opens');
        $response = array_shift($this->responses);
        testAssertSame(strlen($response), fwrite($pair[1], $response),
            'Entire fixture response was written');
        stream_socket_shutdown($pair[1], STREAM_SHUT_WR);
        $this->peers[] = $pair[1];
        return $pair[0];
    }

    public function request($index)
    {
        testAssertTrue(isset($this->peers[$index]), 'Expected socket request exists');
        $request = stream_get_contents($this->peers[$index]);
        fclose($this->peers[$index]);
        unset($this->peers[$index]);
        return $request;
    }
}

class RedirectScopeWireTest extends TestCase
{
    private $fixtureDir;
    private $previousCurl;

    public function setUp()
    {
        global $pathToExternals;
        $base = getenv('TMPDIR') ?: sys_get_temp_dir();
        $this->fixtureDir = $base . '/redirect-scope-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!mkdir($this->fixtureDir, 0700))
            throw new RuntimeException('Unable to create redirect fixture directory');
        $this->previousCurl = isset($pathToExternals['curl']) ? $pathToExternals['curl'] : null;
        $curl = <<<'SH'
#!/bin/sh
printf '%s\n' '@@CALL@@' >> "$REDIRECT_SCOPE_ARGS"
header_file=
body_file=
for argument do
    printf '%s\n' "$argument" >> "$REDIRECT_SCOPE_ARGS"
done
while [ "$#" -gt 0 ]; do
    case "$1" in
        -D) shift; header_file=$1 ;;
        -o) shift; body_file=$1 ;;
    esac
    shift
done
count=$(cat "$REDIRECT_SCOPE_COUNT")
if [ "$count" -eq 0 ]; then
    printf 'HTTP/1.1 302 Found\r\nLocation: %s\r\nSet-Cookie: source=visible; Path=/\r\n\r\n' "$REDIRECT_SCOPE_TARGET" > "$header_file"
    : > "$body_file"
else
    printf 'HTTP/1.1 200 OK\r\n\r\n' > "$header_file"
    printf '%s' 'authenticated response' > "$body_file"
fi
printf '%s\n' "$((count + 1))" > "$REDIRECT_SCOPE_COUNT"
SH;
        file_put_contents($this->fixtureDir . '/curl', $curl);
        chmod($this->fixtureDir . '/curl', 0700);
        file_put_contents($this->fixtureDir . '/count', "0\n");
        putenv('REDIRECT_SCOPE_ARGS=' . $this->fixtureDir . '/args');
        putenv('REDIRECT_SCOPE_COUNT=' . $this->fixtureDir . '/count');
        $pathToExternals['curl'] = $this->fixtureDir . '/curl';
    }

    public function tearDown()
    {
        global $pathToExternals;
        if ($this->previousCurl === null) unset($pathToExternals['curl']);
        else $pathToExternals['curl'] = $this->previousCurl;
        putenv('REDIRECT_SCOPE_ARGS');
        putenv('REDIRECT_SCOPE_COUNT');
        putenv('REDIRECT_SCOPE_TARGET');
        foreach (glob($this->fixtureDir . '/*') as $path) unlink($path);
        rmdir($this->fixtureDir);
    }

    private function curlCalls()
    {
        $text = file_get_contents($this->fixtureDir . '/args');
        $calls = array();
        foreach (explode("@@CALL@@\n", $text) as $block)
            if ($block !== '') $calls[] = explode("\n", trim($block));
        return $calls;
    }

    private function headerValues($lines, $name)
    {
        $values = array();
        $prefix = $name . ':';
        foreach ($lines as $line)
            if (strncasecmp($line, $prefix, strlen($prefix)) === 0)
                $values[] = trim(substr($line, strlen($prefix)));
        return $values;
    }

    private function curlHeaders($call, $name)
    {
        $lines = array();
        foreach ($call as $i => $argument)
            if ($argument === '-H' && isset($call[$i + 1]))
                $lines[] = $call[$i + 1];
        return $this->headerValues($lines, $name);
    }

    private function socketHeaders($request, $name)
    {
        return $this->headerValues(explode("\n", $request), $name);
    }

    private function runAccountRedirect($target, $rawHeader = null)
    {
        putenv('REDIRECT_SCOPE_TARGET=' . $target);
        $client = new Snoopy();
        if ($rawHeader !== null) $client->rawheaders[$rawHeader] = 'private';
        $account = new RedirectScopeWireAccount();
        $source = 'https://tracker.example:8443/file/start';
        $result = $account->fetch($client, $source, 'user', 'password', 'GET', '', '');
        return array($result, $client, $this->curlCalls());
    }

    public function testSelectedAccountAllowsItsHttpsSiblingOnConfiguredPort()
    {
        list($result, $client, $calls) = $this->runAccountRedirect(
            'https://dl.tracker.example:8443/file/next');
        $this->assertSame(true, $result, 'Authorized sibling answer is accepted');
        $this->assertSame(2, count($calls), 'Authorized sibling is requested');
        $this->assertSame('https://dl.tracker.example:8443/file/next', end($calls[1]),
            'Second request reaches the selected account sibling');
        $cookies = $this->curlHeaders($calls[1], 'Cookie');
        $this->assertSame(1, count($cookies), 'Authorized sibling has one Cookie header');
        $cookie = isset($cookies[0]) ? $cookies[0] : '';
        $this->assertTrue(strpos($cookie, 'session=saved') !== false,
            'Selected account session reaches the authorized sibling');
        $this->assertTrue(strpos($cookie, 'source=visible') === false,
            'Host-only source response cookie does not reach a sibling');
        $this->assertSame('', $client->error, 'Successful account redirect has no refusal');
    }

    public function testSelectedAccountRefusesPortAndForeignHopsWithSourceReplyVisible()
    {
        foreach (array(
            'https://tracker.example/file/next',
            'https://dl.tracker.example/file/next',
            'https://sso.test/file/next',
            'https://tracker.example.evil.test:8443/file/next',
            'https://tracker.example@evil.test:8443/file/next',
            'http://dl.tracker.example:8443/file/next',
            'https://dl.tracker.example:8443/other',
        ) as $target) {
            file_put_contents($this->fixtureDir . '/count', "0\n");
            @unlink($this->fixtureDir . '/args');
            list($result, $client, $calls) = $this->runAccountRedirect($target);
            $this->assertSame(false, $result, 'Account refuses ' . $target);
            $this->assertSame(1, count($calls), 'No request reaches ' . $target);
            foreach (array_slice($calls, 1) as $unexpectedCall) {
                foreach ($this->curlHeaders($unexpectedCall, 'Cookie') as $wireCookie)
                    $this->assertTrue(strpos($wireCookie, 'session=saved') === false,
                        'Selected account session never reaches refused target');
            }
            $this->assertSame(302, (int)$client->status, 'Source 302 remains visible');
            $this->assertSame($target, $client->lastredirectaddr,
                'Refused Location remains available to account classifiers');
            $this->assertSame('source=visible; Path=/',
                trim($client->get_header('Set-Cookie')),
                'Source Set-Cookie remains available to callers');
            $this->assertSame(Snoopy::CREDENTIAL_REDIRECT_REFUSED, $client->error,
                'Refusal has a classified reason');
        }
    }

    public function testSelectedAccountNeverAuthorizesRawCredentialsOnSibling()
    {
        foreach (array('cOoKiE', 'aUtHoRiZaTiOn', 'pRoXy-AuThOrIzAtIoN') as $header) {
            file_put_contents($this->fixtureDir . '/count', "0\n");
            @unlink($this->fixtureDir . '/args');
            list($result, $client, $calls) = $this->runAccountRedirect(
                'https://dl.tracker.example:8443/file/next', $header);
            $this->assertSame(false, $result, 'Raw credential is not account-scoped');
            $this->assertSame(1, count($calls), 'Raw credential cannot reach sibling');
            $this->assertSame(302, (int)$client->status, 'Source reply remains available');
            $this->assertSame(Snoopy::CREDENTIAL_REDIRECT_REFUSED, $client->error,
                'Raw credential refusal is classified');
        }
    }

    public function testSocketValidatorsStayOnOriginAndReturnToCallerAfterRedirect()
    {
        foreach (array('http://tracker.test/next' => true,
            'http://cdn.test/feed' => false) as $target => $sameOrigin) {
            $client = new RedirectScopeSocketReplies();
            $client->rawheaders = array('iF-NoNe-MaTcH' => '"old"',
                'IF-MODIFIED-SINCE' => 'Wed, 21 Oct 2015 07:28:00 GMT');
            $original = $client->rawheaders;
            $client->responses = array(
                "HTTP/1.1 302 Found\r\nLocation: {$target}\r\n\r\n",
                "HTTP/1.1 200 OK\r\n\r\n",
                "HTTP/1.1 200 OK\r\n\r\n",
            );
            $this->assertSame(true, $client->fetch('http://tracker.test/feed'), 'Socket redirect succeeds');
            $first = $client->request(0);
            $second = $client->request(1);
            $this->assertSame(array('"old"'), $this->socketHeaders($first, 'If-None-Match'),
                'Origin receives exactly one ETag validator');
            $this->assertSame($sameOrigin ? array('"old"') : array(),
                $this->socketHeaders($second, 'If-None-Match'),
                'ETag validator follows only same-origin socket hop');
            $this->assertSame($sameOrigin ? array('Wed, 21 Oct 2015 07:28:00 GMT') : array(),
                $this->socketHeaders($second, 'If-Modified-Since'),
                'Last-Modified validator follows only same-origin socket hop');
            $this->assertSame($original, $client->rawheaders,
                'Redirect-scoped header change never mutates caller headers');
            $this->assertSame(true, $client->fetch('http://tracker.test/again'),
                'A later explicit source fetch remains available');
            $third = $client->request(2);
            $this->assertSame(array('"old"'), $this->socketHeaders($third, 'If-None-Match'),
                'Later source request keeps its original validator');
        }
    }

    public function testCurlValidatorsAreRemovedOnlyOnChangedOrigin()
    {
        foreach (array('https://tracker.test/next' => true,
            'https://cdn.test/feed' => false) as $target => $sameOrigin) {
            file_put_contents($this->fixtureDir . '/count', "0\n");
            @unlink($this->fixtureDir . '/args');
            putenv('REDIRECT_SCOPE_TARGET=' . $target);
            $client = new Snoopy();
            $client->rawheaders = array('If-None-Match' => '"old"',
                'If-Modified-Since' => 'Wed, 21 Oct 2015 07:28:00 GMT');
            $original = $client->rawheaders;
            $this->assertSame(true, $client->fetch('https://tracker.test/feed'),
                'Fake curl redirect succeeds');
            $calls = $this->curlCalls();
            $this->assertSame(2, count($calls), 'Fake curl sends two requests');
            $this->assertSame(array('"old"'),
                $this->curlHeaders($calls[0], 'If-None-Match'), 'Source receives ETag');
            $this->assertSame($sameOrigin ? array('"old"') : array(),
                $this->curlHeaders($calls[1], 'If-None-Match'),
                'ETag follows only same-origin curl hop');
            $this->assertSame($sameOrigin ? array('Wed, 21 Oct 2015 07:28:00 GMT') : array(),
                $this->curlHeaders($calls[1], 'If-Modified-Since'),
                'Last-Modified follows only same-origin curl hop');
            $this->assertSame($original, $client->rawheaders,
                'Caller headers survive fake curl redirect');
        }
    }
}
