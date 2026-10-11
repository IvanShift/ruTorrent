<?php

require_once(__DIR__ . '/TestCase.php');

class SnoopyProxyWireTest extends TestCase
{
    private function probe($url, $reply = null, $rebind = false, array $options = array())
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertTrue($server !== false, 'synthetic proxy listens: ' . $error);
        $port = (int)substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        $code = <<<'PHP_CODE'
$_ENV['RU_LOG_FILE'] = sys_get_temp_dir() . '/snoopy-proxy-wire-child-' . getmypid() . '.log';
putenv('NO_PROXY=tracker.test');
putenv('no_proxy=tracker.test');
require $argv[1];
$GLOBALS['proxyWireRebind'] = $argv[4] === '1';
$GLOBALS['proxyWireOptions'] = json_decode($argv[5], true);
class PublicTrackerForProxyWire extends Snoopy {
    static private $lookups = 0;
    static public function resolveHost($host) {
        if ($host !== 'tracker.test') return parent::resolveHost($host);
        if (isset($GLOBALS['proxyWireOptions']['dnsDelay'])) usleep($GLOBALS['proxyWireOptions']['dnsDelay']);
        self::$lookups++;
        return $GLOBALS['proxyWireRebind'] && self::$lookups > 1
            ? array('127.0.0.1') : ($GLOBALS['proxyWireOptions']['addresses'] ?? array('93.184.216.34'));
    }
}
$client = new PublicTrackerForProxyWire();
$client->proxy_host = '127.0.0.1';
$client->proxy_port = (int)$argv[2];
$client->block_private = true;
$client->private_allowlist = array();
$client->_fp_timeout = 2;
$client->read_timeout = 2;
if (isset($GLOBALS['proxyWireOptions']['deadline'])) $client->setRequestDeadline($GLOBALS['proxyWireOptions']['deadline']);
echo json_encode(array('ok' => $client->fetch($argv[3]), 'status' => $client->status,
    'error' => $client->error, 'body' => $client->results,
    'responseURL' => $client->lastResponseURL, 'redirectURL' => $client->lastredirectaddr));
PHP_CODE;
        $proc = proc_open(array(PHP_BINARY, '-r', $code,
            __DIR__ . '/../../php/Snoopy.class.inc', (string)$port, $url, $rebind ? '1' : '0', json_encode($options)),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $this->assertTrue(is_resource($proc), 'synthetic proxy client starts');
        fclose($pipes[0]);
        $wire = null;
        $wires = array();
        try {
            foreach ($reply === null ? array() : (is_array($reply) ? $reply : array($reply)) as $answer) {
                $peer = stream_socket_accept($server, 3);
                $this->assertTrue($peer !== false, 'client reaches the configured proxy');
                if ($peer === false) break;
                stream_set_timeout($peer, 2);
                $wire = '';
                while (strpos($wire, "\r\n\r\n") === false && strlen($wire) < 8192) {
                    $piece = fread($peer, 4096);
                    if ($piece === false || $piece === '') break;
                    $wire .= $piece;
                }
                $wires[] = $wire;
                fwrite($peer, $answer);
                fclose($peer);
            }
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $out = $err = '';
            $unexpected = false;
            $deadline = microtime(true) + 5;
            do {
                $out .= stream_get_contents($pipes[1]);
                $err .= stream_get_contents($pipes[2]);
                $state = proc_get_status($proc);
                if (!$state['running']) break;
                if ($reply === null) {
                    // Admission regressions must fail instead of deadlocking this
                    // no-request fixture while an unexpected curl waits for a reply.
                    $peer = @stream_socket_accept($server, 0);
                    if ($peer !== false) {
                        $unexpected = true;
                        fwrite($peer, "HTTP/1.1 503 Probe\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                        fclose($peer);
                    }
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(false, $state['running'], 'the proxy client finishes within the fixture budget');
            if ($state['running']) proc_terminate($proc, 9);
            $out .= stream_get_contents($pipes[1]);
            $err .= stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closed = proc_close($proc);
            $exit = $state['exitcode'] >= 0 ? $state['exitcode'] : $closed;
            stream_set_blocking($server, false);
            $extra = @stream_socket_accept($server, 0);
            if ($extra !== false) fclose($extra);
            $this->assertSame(0, $exit, 'proxy client exits: ' . $err);
            return array($wire, json_decode($out, true), $unexpected || $extra !== false, $wires);
        } finally {
            fclose($server);
            if (is_resource($proc)) proc_terminate($proc);
        }
    }

    public function testHttpProxySeesCheckedIpAndOriginalHost()
    {
        list($wire, $result, $extra) = $this->probe('http://tracker.test/favicon.ico',
            "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK");
        $this->assertTrue(strpos($wire, "GET http://93.184.216.34/favicon.ico HTTP/") === 0,
            'proxy must receive the checked address in its absolute request URI: ' . $wire);
        $this->assertTrue(strpos($wire, "\r\nHost: tracker.test\r\n") !== false,
            'origin Host remains the public tracker');
        $this->assertSame(true, $result['ok'], 'proxy-only public favicon fetch succeeds');
        $this->assertSame('OK', $result['body'], 'proxy response body reaches the caller');
        $this->assertSame(false, $extra, 'one favicon fetch makes one proxy request');
    }

    public function testHttpsProxyConnectNamesCheckedIp()
    {
        list($wire, $result) = $this->probe('https://tracker.test/favicon.ico',
            "HTTP/1.1 502 Probe\r\nContent-Length: 0\r\n\r\n");
        $this->assertTrue(strpos($wire, "CONNECT 93.184.216.34:443 HTTP/") === 0,
            'proxy CONNECT must use the checked public address: ' . $wire);
        $this->assertSame(false, $result['ok'], 'synthetic 502 is not a successful fetch');
    }

    public function testPrivateLiteralNeverReachesProxy()
    {
        list($wire, $result, $extra) = $this->probe('http://127.0.0.1/favicon.ico');
        $this->assertSame(null, $wire, 'private literal has no proxy request');
        $this->assertSame(false, $extra, 'private literal opens no proxy connection');
        $this->assertSame(false, $result['ok'], 'private literal is refused');
    }

    public function testReboundHostnameIsRefusedBeforeASecondProxyRequest()
    {
        list($wire, $result, $extra) = $this->probe('http://tracker.test/favicon.ico',
            "HTTP/1.1 302 Found\r\nLocation: http://tracker.test/secret\r\nContent-Length: 0\r\n\r\n",
            true);
        $this->assertTrue(strpos($wire, 'GET http://93.184.216.34/favicon.ico') === 0,
            'initial proxy target is the first checked address');
        $this->assertSame(false, $extra, 'rebound private address never reaches proxy');
        $this->assertSame(false, $result['ok'], 'rebound redirect is refused');
        $this->assertTrue(strpos($result['error'], 'non-public') !== false,
            'rebound refusal reports the classified address decision');
    }

    public function testPrivateRedirectNeverMakesSecondProxyRequest()
    {
        list($wire, $result, $extra) = $this->probe('http://tracker.test/favicon.ico',
            "HTTP/1.1 302 Found\r\nLocation: http://127.0.0.1/secret\r\nContent-Length: 0\r\n\r\n");
        $this->assertTrue(strpos($wire, 'GET http://93.184.216.34/favicon.ico') === 0,
            'first request pins the public target');
        $this->assertSame(false, $extra, 'private redirect never reaches proxy');
        $this->assertSame(false, $result['ok'], 'private redirect is refused');
    }

    public function testDeadlineHttpReusesPinnedProxyCookiesAndOriginalScheme()
    {
        list($wire, $result, $extra, $wires) = $this->probe('http://tracker.test/favicon.ico', array(
            "HTTP/1.1 302 Found\r\nLocation: //tracker.test/landing\r\nSet-Cookie: sid=probe; Path=/\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK"), false, array('deadline' => 2));
        $this->assertSame(2, count($wires), 'the HTTP redirect stays in the same two-request chain');
        if (count($wires) !== 2) return;
        $this->assertTrue(strpos($wires[1], 'GET http://93.184.216.34/landing HTTP/1.0') === 0,
            'the redirect inherits HTTP and retains the checked proxy target');
        $this->assertTrue(strpos($wires[1], "\r\nHost: tracker.test\r\n") !== false,
            'the original Host has the HTTP default port');
        $this->assertTrue(strpos($wires[1], "\r\nCookie: sid=probe\r\n") !== false,
            'the existing cookie owner imports only the same-origin response cookie');
        $this->assertSame(true, $result['ok'], 'the opt-in HTTP curl chain succeeds');
        $this->assertSame('OK', $result['body'], 'the final response body reaches the caller');
        $this->assertSame('http://tracker.test/landing', $result['responseURL'],
            'response metadata retains the original host instead of the transfer address');
        $this->assertSame('http://tracker.test/landing', $result['redirectURL'],
            'the redirect owner retains the original HTTP origin');
        $this->assertSame(false, $extra, 'the chain makes no extra request');
    }

    public function testDeadlineProxyPinsIpv6WithOriginalPortQueryAndMetadata()
    {
        $url = 'http://tracker.test:8080/favicon.ico?version=1';
        list($wire, $result, $extra) = $this->probe($url,
            "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK", false,
            array('deadline' => 2, 'addresses' => array('2001:4860:4860::8888')));
        $this->assertTrue(strpos($wire,
            'GET http://[2001:4860:4860::8888]:8080/favicon.ico?version=1 HTTP/1.0') === 0,
            'the proxy sees a bracketed checked IPv6 target with the original port and query');
        $this->assertTrue(strpos($wire, "\r\nHost: tracker.test:8080\r\n") !== false,
            'the Host header retains the original authority');
        $this->assertSame(true, $result['ok'], 'the IPv6 target succeeds through the local proxy');
        $this->assertSame('OK', $result['body'], 'the proxy returns the expected response');
        $this->assertSame($url, $result['responseURL'], 'the published response URL retains the original authority');
        $this->assertSame(false, $extra, 'the IPv6 fetch makes exactly one proxy request');
    }

    public function testDeadlineStillRefusesPrivateAndReboundTargetsBeforeCurl()
    {
        list($wire, $result, $extra) = $this->probe('http://127.0.0.1/favicon.ico', null, false, array('deadline' => 2));
        $this->assertSame(null, $wire, 'a deadline does not bypass literal target admission');
        $this->assertSame(false, $extra, 'a private literal opens no proxy connection');
        $this->assertSame(false, $result['ok'], 'private literal is refused before curl');
        list($wire, $result, $extra) = $this->probe('http://tracker.test/favicon.ico', null, false,
            array('deadline' => 2, 'addresses' => array('93.184.216.34', '127.0.0.1')));
        $this->assertSame(null, $wire, 'a public answer does not conceal a private sibling address');
        $this->assertSame(false, $extra, 'a mixed address set opens no proxy connection');
        $this->assertSame(false, $result['ok'], 'every resolved address must pass target admission');
        $this->assertTrue(strpos($result['error'], 'non-public') !== false,
            'the mixed address refusal retains its classification');
        list($wire, $result, $extra) = $this->probe('http://tracker.test/favicon.ico',
            "HTTP/1.1 302 Found\r\nLocation: //tracker.test/secret\r\nContent-Length: 0\r\n\r\n", true, array('deadline' => 2));
        $this->assertTrue(strpos($wire, 'GET http://93.184.216.34/favicon.ico') === 0, 'the first proxy target was checked');
        $this->assertSame(false, $extra, 'the rebound redirect opens no second connection');
        $this->assertSame(false, $result['ok'], 'the private rebound is refused');
        $this->assertTrue(strpos($result['error'], 'non-public') !== false, 'the refusal retains the address classification');
    }

    public function testExpiredDnsAdmissionDoesNotStartAnotherTransfer()
    {
        list($wire, $result, $extra) = $this->probe('http://tracker.test/favicon.ico', null, false,
            array('deadline' => 0.05, 'dnsDelay' => 100000));
        $this->assertSame(null, $wire, 'the delayed resolver is the only operation');
        $this->assertSame(false, $extra, 'elapsed DNS admission cannot restart the transfer budget');
        $this->assertSame(false, $result['ok'], 'an exhausted preflight budget refuses the request');
        $this->assertSame('request-deadline', $result['error'], 'the elapsed deadline has a classified reason');
    }

    public function testSubMillisecondBudgetIsRefusedBeforeStartingCurl()
    {
        list($wire, $result, $extra) = $this->probe('http://tracker.test/favicon.ico', null, false,
            array('deadline' => 0.0005));
        $this->assertSame(null, $wire, 'a sub-millisecond budget is refused before transfer');
        $this->assertSame(false, $extra, 'curl never receives a timeout that rounds to unlimited zero');
        $this->assertSame(false, $result['ok'], 'an unrepresentable remaining budget refuses the request');
        $this->assertSame('request-deadline', $result['error'], 'the deadline is classified before starting curl');
    }
}
