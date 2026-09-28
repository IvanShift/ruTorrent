<?php

declare(strict_types=1);

require_once(__DIR__ . '/../../php/TestCase.php');

$minInterval = 2;
$feedsWithIncorrectTimes = array();
$rss_debug_enabled = false;
require_once(__DIR__ . '/../../../plugins/rss/rss.php');

// Exercise Snoopy's real HTTP writer and parser without DNS or a server child.
class RSSValidatorSocketReplies extends Snoopy
{
    public $responses = array();
    private $peers = array();

    public function connect()
    {
        testAssertTrue(count($this->responses) > 0, 'Unexpected RSS socket request');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        testAssertTrue(is_array($pair), 'RSS socket pair opens');
        $response = array_shift($this->responses);
        testAssertSame(strlen($response), fwrite($pair[1], $response),
            'Complete RSS response was written');
        stream_socket_shutdown($pair[1], STREAM_SHUT_WR);
        $this->peers[] = $pair[1];
        return $pair[0];
    }

    public function requests()
    {
        $requests = array();
        foreach ($this->peers as $peer) {
            $requests[] = stream_get_contents($peer);
            fclose($peer);
        }
        $this->peers = array();
        return $requests;
    }
}

final class RSSValidatorOriginTest extends TestCase
{
    private const SOURCE = 'http://feed.example/rss';
    private const DESTINATION = 'http://cdn.example/rss';
    private const B_DATE = 'Wed, 21 Oct 2015 07:28:00 GMT';
    private const A_DATE = 'Thu, 22 Oct 2015 07:28:00 GMT';

    private function response($status, array $headers = array(), $body = '')
    {
        return 'HTTP/1.1 ' . $status . "\r\n"
            . implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function headerValues($request, $name)
    {
        $values = array();
        $prefix = $name . ':';
        foreach (explode("\n", $request) as $line)
            if (strncasecmp($line, $prefix, strlen($prefix)) === 0)
                $values[] = trim(substr($line, strlen($prefix)));
        return $values;
    }

    private function feedWithResponses(array $cycles, &$seen, $maxredirs = null)
    {
        $seen = array();
        return new rRSS(self::SOURCE, function ($url, $cookies, $headers)
            use (&$cycles, &$seen, $maxredirs) {
            testAssertSame(self::SOURCE, $url, 'Each poll begins at configured feed A');
            testAssertTrue(count($cycles) > 0, 'Expected RSS poll exists');
            $client = new RSSValidatorSocketReplies();
            $client->responses = array_shift($cycles);
            if ($maxredirs !== null) $client->maxredirs = $maxredirs;
            $client->rawheaders = $headers;
            $client->cookies = $cookies;
            $client->fetchComplex($url);
            $seen[] = array('headers' => $headers, 'wire' => $client->requests(),
                'terminal' => $client->lastredirectaddr, 'status' => $client->status,
                'responseURL' => isset($client->lastResponseURL) ? $client->lastResponseURL : null);
            return $client;
        });
    }

    public function testDestinationValidatorsAreNeverReplayedToConfiguredFeedNextCycle()
    {
        $body = file_get_contents(__DIR__ . '/atom-sample.xml');
        $seen = array();
        $feed = $this->feedWithResponses(array(
            array(
                $this->response('302 Found', array('Location: ' . self::DESTINATION)),
                $this->response('200 OK', array('ETag: "B"',
                    'Last-Modified: ' . self::B_DATE), $body),
            ),
            array($this->response('200 OK', array('ETag: "A"',
                'Last-Modified: ' . self::A_DATE), $body)),
        ), $seen);
        $history = new rRSSHistory();
        $this->assertSame(true, $feed->fetch($history), 'A to B first poll parses the feed');
        $this->assertSame(self::DESTINATION, $seen[0]['terminal'],
            'The real Snoopy redirect ended on B');
        $this->assertSame(array(), $this->headerValues($seen[0]['wire'][0], 'If-None-Match'),
            'First A request starts without a validator');
        $this->assertSame(null, $feed->etag, 'B ETag is not stored under A');
        $this->assertSame(null, $feed->lastModified, 'B Last-Modified is not stored under A');

        $this->assertSame(true, $feed->fetch($history), 'Next cycle requests A again');
        $this->assertSame(array(), $seen[1]['headers'],
            'B validators are absent from the next fetcher call to A');
        $this->assertSame(array(), $this->headerValues($seen[1]['wire'][0], 'If-None-Match'),
            'B ETag never reaches A on the wire');
        $this->assertSame(array(), $this->headerValues($seen[1]['wire'][0], 'If-Modified-Since'),
            'B Last-Modified never reaches A on the wire');
        $this->assertSame('"A"', $feed->etag, 'A may store its own later ETag');
    }

    public function testUnvisitedLocationCannotClaimTheTerminalResponseOrigin()
    {
        $body = file_get_contents(__DIR__ . '/atom-sample.xml');
        $seen = array();
        $feed = $this->feedWithResponses(array(
            array(
                $this->response('302 Found', array('Location: ' . self::DESTINATION)),
                $this->response('200 OK', array('Location: ' . self::SOURCE,
                    'ETag: "B"', 'Last-Modified: ' . self::B_DATE), $body),
            ),
            array($this->response('200 OK', array(), $body)),
        ), $seen, 1);
        $history = new rRSSHistory();
        $this->assertSame(true, $feed->fetch($history), 'Stopped chain returns B feed body');
        $this->assertSame(200, (int)$seen[0]['status'], 'Terminal B response is successful');
        $this->assertSame(2, count($seen[0]['wire']), 'No third request is sent to A');
        $this->assertSame(array('cdn.example'),
            $this->headerValues($seen[0]['wire'][1], 'Host'),
            'The terminal response came from B');
        $this->assertSame(self::SOURCE, $seen[0]['terminal'],
            'Legacy lastredirectaddr names the unvisited Location A');
        $this->assertSame(self::DESTINATION, $seen[0]['responseURL'],
            'Snoopy records the URL that actually answered');
        $this->assertSame(null, $feed->etag, 'B ETag is not stored under A');
        $this->assertSame(null, $feed->lastModified,
            'B Last-Modified is not stored under A');
        $this->assertSame(true, $feed->fetch($history), 'Next cycle starts at A');
        $this->assertSame(array(), $seen[1]['headers'],
            'B validators never enter the next fetcher call to A');
        $this->assertSame(array(),
            $this->headerValues($seen[1]['wire'][0], 'If-None-Match'),
            'B ETag does not reach A on the next wire request');
    }

    public function testSameHostOnAnotherPortIsASeparateValidatorOrigin()
    {
        $destination = 'http://feed.example:8080/rss';
        $body = file_get_contents(__DIR__ . '/atom-sample.xml');
        $seen = array();
        $feed = $this->feedWithResponses(array(
            array(
                $this->response('302 Found', array('Location: ' . $destination)),
                $this->response('200 OK', array('ETag: "different-port"'), $body),
            ),
            array($this->response('200 OK', array(), $body)),
        ), $seen);
        $history = new rRSSHistory();
        $this->assertSame(true, $feed->fetch($history), 'Changed-port poll parses');
        $this->assertSame($destination, $seen[0]['terminal'],
            'The real redirect changed the effective port');
        $this->assertSame(null, $feed->etag,
            'Different-port ETag is not stored under the configured origin');
        $this->assertSame(true, $feed->fetch($history), 'Next poll returns to configured port');
        $this->assertSame(array(), $this->headerValues($seen[1]['wire'][0], 'If-None-Match'),
            'Different-port validator never reaches configured port');
    }

    public function testTerminalReturnToConfiguredOriginKeepsItsValidators()
    {
        $body = file_get_contents(__DIR__ . '/atom-sample.xml');
        $seen = array();
        $feed = $this->feedWithResponses(array(
            array(
                $this->response('302 Found', array('Location: ' . self::DESTINATION)),
                $this->response('302 Found', array('Location: ' . self::SOURCE)),
                $this->response('200 OK', array('ETag: "A"',
                    'Last-Modified: ' . self::A_DATE), $body),
            ),
            array($this->response('304 Not Modified')),
        ), $seen);
        $history = new rRSSHistory();
        $this->assertSame(true, $feed->fetch($history), 'A to B to A poll parses the feed');
        $this->assertSame(self::SOURCE, $seen[0]['terminal'],
            'The real Snoopy redirect returned to A');
        $this->assertSame('"A"', $feed->etag, 'Terminal A ETag is retained');
        $this->assertSame(self::A_DATE, $feed->lastModified,
            'Terminal A Last-Modified is retained');

        $this->assertSame(true, $feed->fetch($history), 'A answers 304 next cycle');
        $this->assertSame(array('If-None-Match' => '"A"',
            'If-Modified-Since' => self::A_DATE), $seen[1]['headers'],
            'Both terminal A validators reach the next fetcher call');
        $this->assertSame(array('"A"'),
            $this->headerValues($seen[1]['wire'][0], 'If-None-Match'),
            'A ETag reaches only A on the wire');
        $this->assertSame(array(self::A_DATE),
            $this->headerValues($seen[1]['wire'][0], 'If-Modified-Since'),
            'A Last-Modified reaches only A on the wire');
    }
}
