<?php

require_once(__DIR__.'/TestCase.php');
require_once(__DIR__.'/AddTorrentHttpHarness.php');

/**
 * php/addtorrent.php answers an upload POST with a short relative redirect,
 * or, when the Location field would pass its 2 KiB budget, with the result
 * script in the POST body. The budget is load-bearing: behind nginx's 4 KiB
 * FastCGI header buffer a Location of about 4 KiB answers 502 (measured on
 * the image, R3C-02), so the boundary itself is pinned here.
 */
final class AddTorrentRedirectTest extends TestCase
{
    /** The whole "Location: ..." field, as php/addtorrent.php measures it. */
    const LOCATION_BUDGET = 2048;

    private $server = null;

    public function tearDown()
    {
        if ($this->server !== null) {
            $diagnostics = $this->server->phpDiagnostics();
            $this->server->close();
            $this->server = null;
            $this->assertSame(array(), $diagnostics,
                'the requests were served without a PHP diagnostic: '.implode(' | ', $diagnostics));
        }
    }

    private function server()
    {
        if ($this->server === null)
            $this->server = AddTorrentHttpHarness::forAddTorrent(
                new FakeRtorrentAddTorrentReplies(array(), array(), false),
                array('phpSelf' => '/internal/php/addtorrent.php'));
        return $this->server;
    }

    /** A request as a rewriting proxy forwards it: internal Host, forwarded host. */
    private function send($method, $query = '', $body = '', $contentType = 'application/x-www-form-urlencoded')
    {
        return $this->server()->request($method, '/torrents/php/addtorrent.php'.$query, $body,
            array('Host' => 'internal:8080', 'X-Forwarded-Host' => 'trap.invalid',
                'Content-Type' => $contentType));
    }

    private function upload($names, $json = false)
    {
        list($contentType, $body) = AddTorrentHttpHarness::multipart(
            AddTorrentHttpHarness::torrentFiles($names, 'invalid torrent'));
        return $this->send('POST', $json ? '?json=1' : '', $body, $contentType);
    }

    /** The notifications of a result script, decoded. */
    private function notifications($body)
    {
        preg_match_all('/noty\((.*?)\+theUILang\["addTorrent"\+(".*?")\],(".*?")\);/', $body, $calls, PREG_SET_ORDER);
        $decoded = array();
        foreach ($calls as $call)
            $decoded[] = array(json_decode($call[1], true), json_decode($call[2], true), json_decode($call[3], true));
        return $decoded;
    }

    public function testActualPostRedirectStaysRelativeBehindARewritingProxy()
    {
        $response = $this->send('POST');
        $this->assertSame(302, $response['code'], 'the real POST endpoint responds with a redirect');
        $location = $response['headers']['location'] ?? '';
        $this->assertTrue(strpos($location, './addtorrent.php?') === 0,
            'the browser keeps the public origin and proxy prefix');
        $this->assertTrue(strpos($location, 'internal') === false
            && strpos($location, 'trap.invalid') === false,
            'proxy Host rewriting cannot redirect to an internal or forwarded host');
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
        $this->assertSame(200, $response['code'],
            'a batch whose encoded redirect exceeds the header budget returns a body');
        $this->assertTrue(!isset($response['headers']['location']),
            'a large batch has no oversized FastCGI Location header');
        $this->assertTrue(stripos($response['headers']['content-type'] ?? '', 'text/html') === 0,
            'the iframe receives the same result script content type as a result GET');
        $calls = $this->notifications($response['body']);
        $this->assertEquals(32, count($calls), 'each uploaded file has exactly one notification');
        foreach ($calls as $index=>$call) {
            $this->assertEquals($names[$index].' - ', $call[0],
                'notification preserves the exact UTF-8 filename at index '.$index);
            $this->assertEquals('FailedFile', $call[1],
                'the invalid uploaded torrent retains its classified status');
            $this->assertEquals('error', $call[2],
                'the invalid upload retains its notification kind');
        }
    }

    public function testLargeJsonUploadKeepsTheExistingFirstResultContract()
    {
        $response = $this->upload($this->largeBatchNames(), true);
        $this->assertSame(200, $response['code'], 'large JSON upload responds directly');
        $this->assertTrue(!isset($response['headers']['location']),
            'large JSON upload has no oversized redirect');
        $this->assertTrue(stripos($response['headers']['content-type'] ?? '', 'application/json') === 0,
            'the direct response keeps the JSON content type');
        $this->assertEquals(array('result'=>'FailedFile'), json_decode($response['body'], true),
            'JSON callers receive the same first status as the result GET endpoint');
    }

    public function testSmallUploadKeepsRelativeRedirectAndTheExistingResultRenderer()
    {
        $response = $this->upload(array('one.torrent'));
        $this->assertSame(302, $response['code'], 'a small file batch keeps its redirect');
        $location = $response['headers']['location'] ?? null;
        $this->assertEquals('./addtorrent.php?result[]=FailedFile&name[]=one.torrent&', $location,
            'the small redirect preserves all names and statuses with a relative target');
        $query = $location !== null ? strstr($location, '?') : '?result[]=Failed';
        $result = $this->send('GET', $query);
        $this->assertEquals('noty("one.torrent - "+theUILang["addTorrent"+"FailedFile"],"error");', $result['body'],
            'the redirected result uses the existing iframe notification script');
    }

    /**
     * Eight invalid uploads whose redirect field "Location: ./addtorrent.php?..."
     * is exactly $length bytes. Each file adds "result[]=FailedFile&name[]=<name>&";
     * the names are unreserved ASCII, so rawurlencode() keeps their length, and
     * each stays well under a filesystem name limit.
     */
    private function namesForLocationOf($length)
    {
        $fixed = strlen('Location: ./addtorrent.php?');
        $perFile = strlen('result[]=FailedFile&name[]=&');
        $names = array();
        $left = $length - $fixed;
        for ($index = 0; $index < 8; $index++) {
            $size = ($index < 7) ? 225 : ($left - $perFile);
            $names[] = sprintf('%d-', $index).str_repeat('b', $size - strlen('0-.torrent')).'.torrent';
            $left -= $perFile + $size;
        }
        $this->assertSame(0, $left, 'the fixture names fill the field exactly');
        return $names;
    }

    private function locationFor($names)
    {
        $location = './addtorrent.php?';
        foreach ($names as $name)
            $location .= 'result[]=FailedFile&name[]='.rawurlencode($name).'&';
        return $location;
    }

    public function testLocationAtTheBudgetStillRedirects()
    {
        $names = $this->namesForLocationOf(self::LOCATION_BUDGET);
        $expected = $this->locationFor($names);
        $this->assertSame(self::LOCATION_BUDGET, strlen('Location: '.$expected),
            'the fixture field is exactly at the budget');
        $response = $this->upload($names);
        $this->assertSame(302, $response['code'], 'a Location of exactly 2048 bytes is still a redirect');
        $this->assertSame($expected, $response['headers']['location'] ?? null,
            'the redirect at the budget carries every status and name');
        $this->assertSame('', $response['body'], 'the redirect carries no result body');
        $result = $this->send('GET', strstr($expected, '?'));
        $calls = $this->notifications($result['body']);
        $this->assertSame(8, count($calls), 'the redirected result names all eight files');
        foreach ($calls as $index=>$call)
            $this->assertSame($names[$index].' - ', $call[0], 'the redirected result keeps name '.$index);
    }

    public function testLocationOneByteOverTheBudgetMovesToThePostBody()
    {
        $names = $this->namesForLocationOf(self::LOCATION_BUDGET + 1);
        $this->assertSame(self::LOCATION_BUDGET + 1, strlen('Location: '.$this->locationFor($names)),
            'the fixture field is one byte over the budget');
        $response = $this->upload($names);
        $this->assertSame(200, $response['code'], 'a Location of 2049 bytes is answered in the POST body');
        $this->assertTrue(!isset($response['headers']['location']),
            'no redirect follows a body response');
        $calls = $this->notifications($response['body']);
        $this->assertSame(8, count($calls), 'the POST body names all eight files');
        foreach ($calls as $index=>$call)
            $this->assertSame(array($names[$index].' - ', 'FailedFile', 'error'), $call,
                'the POST body keeps file '.$index);
    }
}
