<?php

require_once(__DIR__ . '/TestCase.php');

class SnoopyProxyWireTest extends TestCase
{
    private function probe($url, $reply = null, $rebind = false)
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
class PublicTrackerForProxyWire extends Snoopy {
    static private $lookups = 0;
    static public function resolveHost($host) {
        if ($host !== 'tracker.test') return parent::resolveHost($host);
        self::$lookups++;
        return $GLOBALS['proxyWireRebind'] && self::$lookups > 1
            ? array('127.0.0.1') : array('93.184.216.34');
    }
}
$client = new PublicTrackerForProxyWire();
$client->proxy_host = '127.0.0.1';
$client->proxy_port = (int)$argv[2];
$client->block_private = true;
$client->private_allowlist = array();
$client->_fp_timeout = 2;
$client->read_timeout = 2;
echo json_encode(array('ok' => $client->fetch($argv[3]), 'status' => $client->status,
    'error' => $client->error, 'body' => $client->results));
PHP_CODE;
        $proc = proc_open(array(PHP_BINARY, '-r', $code,
            __DIR__ . '/../../php/Snoopy.class.inc', (string)$port, $url, $rebind ? '1' : '0'),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $this->assertTrue(is_resource($proc), 'synthetic proxy client starts');
        fclose($pipes[0]);
        $wire = null;
        try {
            if ($reply !== null) {
                $peer = stream_socket_accept($server, 3);
                $this->assertTrue($peer !== false, 'client reaches the configured proxy');
                stream_set_timeout($peer, 2);
                $wire = '';
                while (strpos($wire, "\r\n\r\n") === false && strlen($wire) < 8192) {
                    $piece = fread($peer, 4096);
                    if ($piece === false || $piece === '') break;
                    $wire .= $piece;
                }
                fwrite($peer, $reply);
                fclose($peer);
            }
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
            stream_set_blocking($server, false);
            $extra = @stream_socket_accept($server, 0);
            if ($extra !== false) fclose($extra);
            $this->assertSame(0, $exit, 'proxy client exits: ' . $err);
            return array($wire, json_decode($out, true), $extra !== false);
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
}
