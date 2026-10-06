<?php

require_once(__DIR__.'/TestCase.php');

final class AddTorrentRedirectTest extends TestCase
{
    public function testActualPostRedirectStaysRelativeBehindARewritingProxy()
    {
        $base = tempnam(sys_get_temp_dir(), 'add-redirect-');
        unlink($base);
        mkdir($base, 0700);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$listener) throw new RuntimeException('Cannot reserve HTTP fixture port: '.$error);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $router = $base.'/router.php';
        $php = realpath(__DIR__.'/../../php');
        file_put_contents($router, '<?php '
            .'$_ENV["RU_PROFILE_PATH"] = '.var_export($base.'/profile', true).';'
            .'$_ENV["RU_LOG_FILE"] = '.var_export($base.'/application.log', true).';'
            .'$_SERVER["PHP_SELF"] = "/internal/php/addtorrent.php";'
            .'set_include_path('.var_export($php, true).'.PATH_SEPARATOR.get_include_path());'
            .'chdir('.var_export($php, true).');'
            .'require "addtorrent.php";');
        // An argv command keeps proc_terminate attached to the server itself.
        $process = proc_open(array(PHP_BINARY, '-c', __DIR__.'/../php-test.ini',
            '-S', $address, $router), array(0 => array('pipe', 'r'),
            1 => array('file', $base.'/server.log', 'a'),
            2 => array('file', $base.'/server.log', 'a')), $pipes);
        if (!is_resource($process)) throw new RuntimeException('HTTP server did not start');
        fclose($pipes[0]);
        try {
            $socket = false;
            for ($attempt = 0; $attempt < 100 && !$socket; $attempt++) {
                $socket = @stream_socket_client('tcp://'.$address, $errno, $error, 0.1);
                if (!$socket) usleep(10000);
            }
            if (!$socket) throw new RuntimeException('HTTP fixture is unavailable: '.$error);
            stream_set_timeout($socket, 5);
            fwrite($socket, "POST /torrents/php/addtorrent.php HTTP/1.1\r\n"
                ."Host: internal:8080\r\nX-Forwarded-Host: trap.invalid\r\n"
                ."Content-Length: 0\r\nConnection: close\r\n\r\n");
            $response = stream_get_contents($socket);
            fclose($socket);
            $this->assertTrue(preg_match('/^HTTP\/1\.[01] 302 /', $response) === 1,
                'the real POST endpoint responds with a redirect');
            preg_match('/^Location: ([^\r\n]+)/mi', $response, $matches);
            $this->assertTrue(isset($matches[1]) && strpos($matches[1], './addtorrent.php?') === 0,
                'the browser keeps the public origin and proxy prefix');
            $this->assertTrue(isset($matches[1]) && strpos($matches[1], 'internal') === false
                && strpos($matches[1], 'trap.invalid') === false,
                'proxy Host rewriting cannot redirect to an internal or forwarded host');
        } finally {
            proc_terminate($process);
            proc_close($process);
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,
                FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
                else unlink($entry->getPathname());
            }
            rmdir($base);
        }
    }
}
