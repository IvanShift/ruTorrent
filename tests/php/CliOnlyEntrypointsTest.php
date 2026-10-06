<?php
require_once __DIR__.'/TestCase.php';

class CliOnlyEntrypointsTest extends TestCase
{
    private function workers()
    {
        $root = dirname(__DIR__, 2);
        $workers = array();
        foreach (array('php', 'plugins', 'tools') as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,
                FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file->isFile() || $file->isLink() || $file->getExtension() !== 'php') continue;
                foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                    if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$argv') {
                        $workers[] = substr($file->getPathname(), strlen($root));
                        break;
                    }
                }
            }
        }
        sort($workers);
        return $workers;
    }

    public function testEveryArgvWorkerDeclaresItsSapiBoundary()
    {
        $workers = $this->workers();
        $this->assertTrue(count($workers) >= 29, 'the scanner covers all bundled worker entry points');
        foreach ($workers as $worker) {
            $source = file_get_contents(dirname(__DIR__, 2).$worker);
            $this->assertTrue(strpos($source, 'rutorrent_require_cli(') !== false ||
                preg_match('~PHP_SAPI\s*!==?\s*[\'\"]cli[\'\"]~', $source) === 1,
                'explicit CLI boundary for '.$worker);
        }
    }

    public function testSharedGuardAllowsCliWithoutApplicationBootstrap()
    {
        $code = 'require '.var_export(dirname(__DIR__, 2).'/php/cli-only.php', true).'; '
            .'rutorrent_require_cli(__FILE__); echo "allowed";';
        $process = proc_open(array(PHP_BINARY, '-r', $code), array(1=>array('pipe','w'),
            2=>array('pipe','w')), $pipes);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $this->assertEquals(0, proc_close($process), 'standalone CLI guard exits normally: '.$err);
        $this->assertEquals('allowed', $out, 'standalone CLI caller continues');
    }
    public function testWebSapiRefusesEveryWorkerBeforeApplicationBootstrap()
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if($listener === false) throw new RuntimeException('worker server port: '.$error);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $log = tempnam(sys_get_temp_dir(), 'cli-web-server-');
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=0', '-S', $address,
            '-t', dirname(__DIR__, 2)), array(0=>array('pipe','r'),
            1=>array('file',$log,'a'), 2=>array('file',$log,'a')), $pipes);
        if(!is_resource($process)) throw new RuntimeException('worker web server did not start');
        fclose($pipes[0]);
        try {
            $ready = false;
            for($i = 0; $i < 30; ++$i) {
                $socket = @stream_socket_client('tcp://'.$address, $errno, $error, 0.1);
                if($socket !== false) { fclose($socket); $ready = true; break; }
                usleep(100000);
            }
            if(!$ready) throw new RuntimeException('worker web server unavailable: '.file_get_contents($log));
            foreach($this->workers() as $worker) {
                $socket = stream_socket_client('tcp://'.$address, $errno, $error, 2);
                if($socket === false) throw new RuntimeException('worker web connection: '.$error);
                stream_set_timeout($socket, 2);
                fwrite($socket, 'GET '.$worker.' HTTP/1.0' . "\r\nHost: localhost\r\nConnection: close\r\n\r\n");
                $response = stream_get_contents($socket); fclose($socket);
                $parts = explode("\r\n\r\n", $response, 2);
                $this->assertTrue(preg_match('~^HTTP/1\.[01] 403 ~', $parts[0]) === 1,
                    'actual web SAPI refuses worker before bootstrap: '.$worker);
                $this->assertEquals('Forbidden', $parts[1] ?? null,
                    'worker returns only the refusal body: '.$worker);
            }
        } finally {
            proc_terminate($process); proc_close($process); unlink($log);
        }
    }

    public function testClearMarkersCopyKeepsStandaloneCliGuard()
    {
        $path = tempnam(sys_get_temp_dir(),'clear-markers-copy-');
        $source = file_get_contents(dirname(__DIR__,2).'/tools/retrackers-clear-markers.php');
        // Keep the copied tool offline; its only connection targets an absent socket.
        $source = str_replace("\$scgi_host = '127.0.0.1';", "\$scgi_host = '/missing-clear-marker-test.sock';", $source);
        file_put_contents($path,$source);
        $process = proc_open(array(PHP_BINARY,$path),array(1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $status = proc_close($process); unlink($path);
        $this->assertEquals(2,$status,'standalone tool reaches its classified connection refusal: '.$err);
        $this->assertTrue(strpos($err,'cannot reach rtorrent')!==false,
            'standalone copy runs without application cli-only dependency');
        $this->assertTrue(strpos($err,'Failed opening required')===false,'standalone guard is self-contained');
    }

    public function testCgiWorkerWithoutHttpRequestFailsVisibly()
    {
        $source = preg_replace('/^<\?php/', '', file_get_contents(dirname(__DIR__,2).'/php/cli-only.php'),1);
        $source = str_replace('PHP_SAPI', var_export('cgi-fcgi',true), $source);
        $code = '$_SERVER=array(); '.$source.' rutorrent_require_cli("worker.php"); echo "continued";';
        $process = proc_open(array(PHP_BINARY,'-r',$code),array(1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $this->assertEquals(1,proc_close($process),'CGI scheduler misconfiguration has a nonzero exit');
        $this->assertEquals('',$out,'refused worker emits no misleading success body');
        $this->assertTrue(strpos($err,'CLI binary')!==false,'failure names the required runtime correction');
    }

    public function testStandaloneCopyRejectsCgiWorkerWithNonzeroExit()
    {
        $path = tempnam(sys_get_temp_dir(),'standalone-cgi-');
        $source = file_get_contents(dirname(__DIR__,2).'/tools/retrackers-clear-markers.php');
        $source = str_replace('PHP_SAPI', var_export('cgi-fcgi',true), $source);
        file_put_contents($path, $source);
        $process = proc_open(array(PHP_BINARY,$path),array(1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $status = proc_close($process); unlink($path);
        $this->assertEquals(1,$status,'standalone CGI worker never reports success');
        $this->assertEquals('',$out,'standalone guard emits no success-looking body');
        $this->assertTrue(strpos($err,'CLI binary')!==false,'standalone guard names the runtime correction');
    }

}
