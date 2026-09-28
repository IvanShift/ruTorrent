<?php

require_once(__DIR__ . '/TestCase.php');

class TorrentTrackerTiersTest extends TestCase
{
    public function testCliTierBoundariesAndTrailingBlankLine()
    {
        $helper = __DIR__ . '/../../php/torrenttrackers.php';
        $this->assertTrue(is_file($helper), 'create and edit need one tracker tier parser');
        if (!is_file($helper)) return;
        require_once($helper);
        $parsed = TorrentTrackerTiers::fromLines(explode("\r",
            " http://one/announce \rudp://backup/announce\r\r" .
            " http://two/announce \r\r"));
        $this->assertSame(array(array('http://one/announce', 'udp://backup/announce'),
            array('http://two/announce')), $parsed['tiers']);
        $this->assertSame(3, $parsed['count']);
        $this->assertSame(array('tiers' => array(array('http://only/announce')),
            'count' => 1), TorrentTrackerTiers::fromLines(array(' http://only/announce ', '')));
        $this->assertSame(array('tiers' => array(), 'count' => 0),
            TorrentTrackerTiers::fromLines(array('', ' ')));
    }

    public function testEditFormDecodesTrackerValuesBeforeTierParsing()
    {
        $helper = __DIR__ . '/../../php/torrenttrackers.php';
        $this->assertTrue(is_file($helper), 'edit form needs shared tier parser');
        if (!is_file($helper)) return;
        require_once($helper);
        $parsed = TorrentTrackerTiers::fromEditFormValues(array(
            'http%3A%2F%2Fone%2Fannounce', '', 'udp%3A%2F%2Ftwo%2Fannounce', ''));
        $this->assertSame(array(array('http://one/announce'),
            array('udp://two/announce')), $parsed['tiers']);
        $this->assertSame(2, $parsed['count']);
    }
}
