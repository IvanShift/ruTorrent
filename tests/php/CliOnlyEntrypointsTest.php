<?php
require_once __DIR__.'/TestCase.php';

class CliOnlyEntrypointsTest extends TestCase
{
    /** A local variable named argv does not make a shared library a worker. */
    private function readsProcessArguments($source)
    {
        $depth = 0;
        $functions = array();
        $pendingFunction = false;
        $global = false;
        $tokens = token_get_all($source);
        foreach ($tokens as $index => $token) {
            if (is_array($token)) {
                if ($token[0] === T_STRING && strtolower($token[1]) === 'getopt') {
                    $before = $index - 1;
                    while ($before >= 0 && is_array($tokens[$before]) && $tokens[$before][0] === T_WHITESPACE) --$before;
                    if ($before < 0 || !is_array($tokens[$before]) ||
                        !in_array($tokens[$before][0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION), true)) return true;
                }
                if ($token[0] === T_FUNCTION) $pendingFunction = true;
                elseif ($token[0] === T_GLOBAL) $global = true;
                elseif ($token[0] === T_VARIABLE && $token[1] === '$argv'
                    && ($global || (!$pendingFunction && !$functions))) return true;
                elseif ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) ++$depth;
                continue;
            }
            if ($token === '{') {
                ++$depth;
                if ($pendingFunction) { $functions[] = $depth; $pendingFunction = false; }
            } elseif ($token === '}') {
                if ($functions && end($functions) === $depth) array_pop($functions);
                --$depth;
            } elseif ($token === ';') {
                $global = false;
                $pendingFunction = false;
            }
        }
        return false;
    }

    public function testWorkerInventoryDistinguishesProcessArgumentsFromLocalVectors()
    {
        foreach (array('<?php echo $argv[1];', '<?php if (true) { echo $argv[1]; }',
            '<?php function run() { global $argv; echo $argv[1]; }',
            '<?php $options = getopt("", array("report"));') as $source)
            $this->assertTrue($this->readsProcessArguments($source), 'process argv access is inventoried');
        foreach (array('<?php function launch($argv) { return $argv; }',
            '<?php class Launcher { static function launch() { $argv = array("php"); return $argv; } }') as $source)
            $this->assertTrue(!$this->readsProcessArguments($source), 'local argument vector is not a CLI entry point');
    }

    private function workers()
    {
        $root = dirname(__DIR__, 2);
        $workers = array();
        foreach (array('php', 'plugins', 'tools') as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,
                FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file->isFile() || $file->isLink() || $file->getExtension() !== 'php') continue;
                if ($this->readsProcessArguments(file_get_contents($file->getPathname())))
                    $workers[] = substr($file->getPathname(), strlen($root));
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
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=0', '-d', 'output_buffering=0', '-S', $address,
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

    /**
     * A CGI binary whose environment holds a request looks like a web entry
     * to the guard. The unpack scripts read the status of place.php: on a zero
     * they report success, and with $deleteAutoArchives delete their archives,
     * although nothing was placed.
     */
    public function testAWebRefusalAlsoFailsByStatus()
    {
        $shared = preg_replace('/^<\?php/', '', file_get_contents(dirname(__DIR__,2).'/php/cli-only.php'),1);
        $shared .= ' rutorrent_require_cli("worker.php");';
        $standalone = preg_replace('/^<\?php/', '', file_get_contents(dirname(__DIR__,2).'/tools/retrackers-clear-markers.php'),1);
        $standalone = str_replace('dirname(__DIR__)', '"/nonexistent"', $standalone);
        foreach(array('shared guard'=>$shared, 'standalone copy'=>$standalone) as $what=>$source) {
            $code = '$_SERVER["REQUEST_METHOD"]="GET"; '.str_replace('PHP_SAPI', var_export('cgi-fcgi',true), $source).' echo "continued";';
            $process = proc_open(array(PHP_BINARY,'-r',$code),array(1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
            $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
            stream_get_contents($pipes[2]); fclose($pipes[2]);
            $this->assertEquals(1,proc_close($process),$what.' fails by status');
            $this->assertEquals('Forbidden',$out,$what.' answers with the refusal body only');
        }
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
