<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../../plugins/extsearch/engines.php');
require_once(__DIR__ . '/../../../plugins/extsearch/engines/Redacted.php');

class CapturedRedactedEngine extends RedactedEngine
{
    public $requested = array();
    public $answer = false;

    public function fetch($url, $encode = 1, $method = 'GET', $content_type = '', $body = '')
    {
        $this->requested[] = $url;
        return $this->answer === false ? false : (object) array('results' => $this->answer);
    }
}

final class RedactedOriginTest extends TestCase
{
    public function testSearchRequestsTheCurrentWebsite()
    {
        $engine = new CapturedRedactedEngine();
        $results = array();
        $engine->action('needle', 'all', $results, 1, false);
        $this->assertEquals(array(
            'https://redacted.sh/torrents.php?searchstr=needle&tags_type=1&searchsubmit=1'
                . '&order_by=seeders&order_way=desc&page=1',
        ), $engine->requested, 'private search uses the live website origin');
    }

    public function testParsedDownloadAndDescriptionStayOnTheCurrentWebsite()
    {
        $engine = new CapturedRedactedEngine();
        $engine->answer = '<tr class="torrent"><a href="torrents.php?action=download&id=7">DL</a>'
            . '</span>Artist <a href="torrents.php?id=42&groupid=7">Title'
            . '<div class="torrent_info">Music</div><td class="nobr"><span class="date" title="2026-09-29 10:00:00">date'
            . '</span><td class="nobr">1 GB</td><td class="nobr">0</td>'
            . '<td class="nobr">5</td><td class="nobr">2</td></tr>';
        $results = array();
        $engine->action('needle', 'all', $results, 1, false);
        $this->assertEquals(1, count($results), 'synthetic row exercises the parser');
        $link = 'https://redacted.sh/torrents.php?action=download&id=7';
        $this->assertTrue(isset($results[$link]), 'parsed download link uses current website origin');
        if(isset($results[$link]))
            $this->assertEquals('https://redacted.sh/torrents.php?id=42', $results[$link]['desc'],
                'parsed description link uses current website origin');
    }
}
