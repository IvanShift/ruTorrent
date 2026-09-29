<?php

require_once(__DIR__.'/TestCase.php');

class CachedEchoGzipExitTest extends TestCase
{
    private $profile;

    public function setUp()
    {
        $this->profile = (getenv('TMPDIR') ?: sys_get_temp_dir())
            .'/cached-echo-gzip-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($this->profile, 0700);
    }

    public function tearDown()
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->profile,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file)
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->profile);
    }

    private function sendLargeBody($exit, $acceptEncoding = 'gzip')
    {
        $util = var_export(__DIR__.'/../../php/util.php', true);
        $encoding = var_export($acceptEncoding, true);
        $code = '$_ENV["RU_PROFILE_PATH"] = $argv[1]; '
            .'putenv("RU_PROFILE_PATH=".$argv[1]); '
            .'$_SERVER["REQUEST_METHOD"] = "GET"; '
            .'$_SERVER["HTTP_ACCEPT_ENCODING"] = '.$encoding.'; '
            .'require '.$util.'; '
            .'$phpUseGzip = true; $phpGzipLevel = 2; '
            .'CachedEcho::send(str_repeat("A", 2500), "text/plain", false'
            .($exit ? '' : ', false').'); '
            .'echo "AFTER_SEND";';
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr',
            '-d', 'error_reporting=E_ALL', '-d', 'log_errors=0',
            '-d', 'zlib.output_compression=0', '-r', $code, $this->profile),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes, __DIR__.'/../../php');
        if(!is_resource($process)) throw new RuntimeException('Could not start CachedEcho child');
        $body = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if($status !== 0 || $errors !== '')
            throw new RuntimeException('CachedEcho child failed: '.$errors);
        return $body;
    }

    private function withHttpServer($check)
    {
        $router = $this->profile.'/router.php';
        $util = var_export(__DIR__.'/../../php/util.php', true);
        $profile = var_export($this->profile.'/runtime', true);
        file_put_contents($router, '<?php $_ENV["RU_PROFILE_PATH"] = '.$profile.'; '
            .'putenv("RU_PROFILE_PATH=".'.$profile.'); require '.$util.'; '
            .'$phpUseGzip = true; $phpGzipLevel = 2; '
            .'CachedEcho::send(str_repeat("A", 2500), "text/plain");');
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if($socket === false) throw new RuntimeException('Could not reserve fixture port: '.$error);
        $address = stream_socket_get_name($socket, false);
        $port = (int)substr($address, strrpos($address, ':') + 1);
        fclose($socket);
        $server = proc_open(array(PHP_BINARY, '-d', 'zlib.output_compression=0',
            '-S', '127.0.0.1:'.$port, $router), array(
            0 => array('pipe', 'r'),
            1 => array('file', $this->profile.'/server.log', 'a'),
            2 => array('file', $this->profile.'/server.log', 'a')),
            $pipes, __DIR__.'/../../php');
        if(!is_resource($server)) throw new RuntimeException('Could not start PHP server');
        fclose($pipes[0]);
        try
        {
            $ready = false;
            for($attempt = 0; $attempt < 100; $attempt++)
            {
                $peer = @fsockopen('127.0.0.1', $port, $errno, $error, 0.05);
                if($peer !== false) { fclose($peer); $ready = true; break; }
                usleep(10000);
            }
            if(!$ready) throw new RuntimeException('PHP server did not become ready');
            $check($port);
        }
        finally
        {
            proc_terminate($server);
            proc_close($server);
        }
    }

    private function requestWithEncoding($port, $acceptEncoding)
    {
        $peer = fsockopen('127.0.0.1', $port, $errno, $error, 2);
        if($peer === false) throw new RuntimeException('Could not connect to PHP server: '.$error);
        stream_set_timeout($peer, 2);
        fwrite($peer, "GET / HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept-Encoding: "
            .$acceptEncoding."\r\nConnection: close\r\n\r\n");
        $reply = stream_get_contents($peer);
        fclose($peer);
        $separator = strpos($reply, "\r\n\r\n");
        if($separator === false) throw new RuntimeException('PHP server sent no response headers');
        $headers = substr($reply, 0, $separator);
        if(strpos($headers, 'HTTP/1.1 200 OK') !== 0)
            throw new RuntimeException('PHP server failed: '.$headers);
        preg_match('/^Content-Encoding:\s*([^\r\n]+)$/mi', $headers, $match);
        return array(isset($match[1]) ? strtolower(trim($match[1])) : null,
            substr($reply, $separator + 4));
    }

    public function testHttpAcceptEncodingSelectsOnlyAllowedExactCoding()
    {
        $expected = array(
            'gzip;q=0' => null,
            'x-gzip;q=0, gzip;q=1' => 'gzip',
            'gzip;q=0, *;q=1' => null,
            'x-gzip;q=0, *;q=1' => null,
            'gzip;q=0.000' => null,
            'gzip;q=0.001' => 'gzip',
            'gzip;q=bogus, *;q=1' => null,
            'gzip;q=0, gzip;q=1' => null,
            '*;q=0, gzip;q=1' => 'gzip',
            'x-gzip;q=0.5, gzip;q=1' => 'gzip',
            'x-gzip;q=1, gzip;q=1' => 'gzip',
            'x-gzip;q=1, gzip;q=0.5' => 'x-gzip',
            'x-gzip' => 'x-gzip',
            'GZIP' => 'gzip',
            'notgzip' => null,
            'br' => null,
            '*' => 'gzip',
        );
        $this->withHttpServer(function($port) use ($expected) {
            foreach($expected as $header => $encoding)
            {
                list($actual, $body) = $this->requestWithEncoding($port, $header);
                $this->assertSame($encoding, $actual, $header.' selects an accepted coding');
                $decoded = $actual === null ? $body : gzdecode($body);
                $this->assertSame(str_repeat('A', 2500), $decoded,
                    $header.' keeps the complete response body');
            }
        });
    }

    public function testLargeGzipBodyStopsAtDefaultExit()
    {
        $body = $this->sendLargeBody(true);
        $this->assertSame(str_repeat('A', 2500), gzdecode($body),
            'the response is the exact compressed body');
        $this->assertTrue(strpos($body, 'AFTER_SEND') === false,
            'default exit prevents code after a compressed response');
    }

    public function testUnsupportedEncodingSendsPlainBodyWithoutWarning()
    {
        $this->assertSame(str_repeat('A', 2500), $this->sendLargeBody(true, 'br'),
            'unsupported encoding sends the uncompressed body without warnings');
    }

    public function testLargeGzipBodyAllowsExplicitContinue()
    {
        $body = $this->sendLargeBody(false);
        $this->assertSame('AFTER_SEND', substr($body, -10),
            'explicit exit=false keeps post-response work reachable');
        $this->assertSame(str_repeat('A', 2500), gzdecode(substr($body, 0, -10)),
            'the continued response still has the expected gzip payload');
    }
}
