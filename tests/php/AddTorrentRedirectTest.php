<?php

require_once(__DIR__.'/TestCase.php');
require_once(__DIR__.'/FakeRtorrentDaemon.php');

final class AddTorrentRedirectTest extends TestCase
{
    private function request($body = '', $contentType = 'application/x-www-form-urlencoded',
        $query = '', $method = 'POST')
    {
        $base = tempnam(sys_get_temp_dir(), 'add-redirect-');
        unlink($base);
        mkdir($base, 0700);
        mkdir($base.'/profile/torrents', 0700, true);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$listener) throw new RuntimeException('Cannot reserve HTTP fixture port: '.$error);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $router = $base.'/router.php';
        $php = realpath(__DIR__.'/../../php');
        $daemon = new FakeRtorrentDaemon(new FakeRtorrentAddTorrentReplies(array(), array(), false),
            $base.'/daemon.log');
        file_put_contents($router, '<?php '
            .'$_ENV["RU_PROFILE_PATH"] = '.var_export($base.'/profile', true).';'
            .'$_ENV["RU_LOG_FILE"] = '.var_export($base.'/application.log', true).';'
            .'$_SERVER["PHP_SELF"] = "/internal/php/addtorrent.php";'
            .'set_include_path('.var_export($php, true).'.PATH_SEPARATOR.get_include_path());'
            .'chdir('.var_export($php, true).');'
            .'require_once "util.php";'
            .'$scgi_host = "127.0.0.1"; $scgi_port = '.$daemon->port().';'
            .'require "addtorrent.php";');
        // An argv command keeps proc_terminate attached to the server itself.
        $process = proc_open(array(PHP_BINARY, '-c', __DIR__.'/../php-test.ini',
            '-d', 'max_file_uploads=128', '-S', $address, $router), array(0 => array('pipe', 'r'),
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
            fwrite($socket, $method." /torrents/php/addtorrent.php".$query." HTTP/1.1\r\n"
                ."Host: internal:8080\r\nX-Forwarded-Host: trap.invalid\r\n"
                ."Content-Type: ".$contentType."\r\nContent-Length: ".strlen($body)
                ."\r\nConnection: close\r\n\r\n".$body);
            $response = stream_get_contents($socket);
            fclose($socket);
            return $response;
        } finally {
            proc_terminate($process);
            proc_close($process);
            $daemon->stop();
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,
                FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
                else unlink($entry->getPathname());
            }
            rmdir($base);
        }
    }
    public function testActualPostRedirectStaysRelativeBehindARewritingProxy()
    {
        $response = $this->request();
        $this->assertTrue(preg_match('/^HTTP\/1\.[01] 302 /', $response) === 1,
            'the real POST endpoint responds with a redirect');
        preg_match('/^Location: ([^\r\n]+)/mi', $response, $matches);
        $this->assertTrue(isset($matches[1]) && strpos($matches[1], './addtorrent.php?') === 0,
            'the browser keeps the public origin and proxy prefix');
        $this->assertTrue(isset($matches[1]) && strpos($matches[1], 'internal') === false
            && strpos($matches[1], 'trap.invalid') === false,
            'proxy Host rewriting cannot redirect to an internal or forwarded host');
    }
    private function upload($names, $json = false)
    {
        $boundary = 'rutorrent-upload-fixture';
        $body = '';
        foreach ($names as $name)
            $body .= '--'.$boundary."\r\nContent-Disposition: form-data; name=\"torrent_file[]\"; filename=\""
                .$name."\"\r\nContent-Type: application/x-bittorrent\r\n\r\ninvalid torrent\r\n";
        $body .= '--'.$boundary."--\r\n";
        return $this->request($body, 'multipart/form-data; boundary='.$boundary, $json ? '?json=1' : '');
    }
    private function largeBatchNames()
    {
        $names = array();
        for ($index = 0; $index < 32; $index++)
            $names[] = sprintf('%02d', $index).'-'.str_repeat('Кириллица', 8).'.torrent';
        return $names;
    }
    public function testLargeCyrillicUploadReturnsEveryResultInThePostBody()
    {
        $names = $this->largeBatchNames();
        $response = $this->upload($names);
        list($headers, $body) = explode("\r\n\r\n", $response, 2);
        $this->assertTrue(preg_match('/^HTTP\/1\.[01] 200 /', $headers) === 1,
            'a batch whose encoded redirect exceeds the header budget returns a body');
        $this->assertTrue(stripos($headers, "\r\nLocation:") === false,
            'a large batch has no oversized FastCGI Location header');
        $this->assertTrue(stripos($headers, 'Content-Type: text/html') !== false,
            'the iframe receives the same result script content type as a result GET');
        preg_match_all('/noty\((.*?)\+theUILang\["addTorrent"\+(".*?")\],(".*?")\);/', $body, $calls, PREG_SET_ORDER);
        $this->assertEquals(32, count($calls), 'each uploaded file has exactly one notification');
        foreach ($calls as $index=>$call) {
            $this->assertEquals($names[$index].' - ', json_decode($call[1], true),
                'notification preserves the exact UTF-8 filename at index '.$index);
            $this->assertEquals('FailedFile', json_decode($call[2], true),
                'the invalid uploaded torrent retains its classified status');
            $this->assertEquals('error', json_decode($call[3], true),
                'the invalid upload retains its notification kind');
        }
    }
    public function testLargeJsonUploadKeepsTheExistingFirstResultContract()
    {
        $response = $this->upload($this->largeBatchNames(), true);
        list($headers, $body) = explode("\r\n\r\n", $response, 2);
        $this->assertTrue(preg_match('/^HTTP\/1\.[01] 200 /', $headers) === 1,
            'large JSON upload responds directly');
        $this->assertTrue(stripos($headers, "\r\nLocation:") === false,
            'large JSON upload has no oversized redirect');
        $this->assertTrue(stripos($headers, 'Content-Type: application/json') !== false,
            'the direct response keeps the JSON content type');
        $this->assertEquals(array('result'=>'FailedFile'), json_decode($body, true),
            'JSON callers receive the same first status as the result GET endpoint');
    }
    public function testSmallUploadKeepsRelativeRedirectAndTheExistingResultRenderer()
    {
        $name = 'one.torrent';
        $response = $this->upload(array($name));
        $this->assertTrue(preg_match('/^HTTP\/1\.[01] 302 /', $response) === 1,
            'a small file batch keeps its redirect');
        preg_match('/^Location: ([^\r\n]+)/mi', $response, $matches);
        $this->assertEquals('./addtorrent.php?result[]=FailedFile&name[]=one.torrent&', $matches[1] ?? null,
            'the small redirect preserves all names and statuses with a relative target');
        $query = isset($matches[1]) ? strstr($matches[1], '?') : '?result[]=Failed';
        $result = $this->request('', 'application/x-www-form-urlencoded', $query, 'GET');
        list($headers, $body) = explode("\r\n\r\n", $result, 2);
        $this->assertEquals('noty("one.torrent - "+theUILang["addTorrent"+"FailedFile"],"error");', $body,
            'the redirected result uses the existing iframe notification script');
    }
}
