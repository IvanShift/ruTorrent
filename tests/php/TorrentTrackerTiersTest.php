<?php

require_once(__DIR__ . '/TestCase.php');

class TorrentTrackerTiersTest extends TestCase
{
    public function testCliTierBoundariesAndTrailingBlankLine()
    {
        $helper = __DIR__ . '/../../php/torrenttrackers.php';
        $this->assertTrue(is_file($helper), 'create and edit need one tracker tier parser');
        if (!is_file($helper)) {
            return;
        }
        require_once($helper);
        $parsed = TorrentTrackerTiers::fromLines(explode(
            "\r",
            " http://one/announce \rudp://backup/announce\r\r"
            . " http://two/announce \r\r",
        ));
        $this->assertSame([['http://one/announce', 'udp://backup/announce'],
            ['http://two/announce']], $parsed['tiers']);
        $this->assertSame(3, $parsed['count']);
        $this->assertSame(['tiers' => [['http://only/announce']],
            'count' => 1], TorrentTrackerTiers::fromLines([' http://only/announce ', '']));
        $this->assertSame(
            ['tiers' => [], 'count' => 0],
            TorrentTrackerTiers::fromLines(['', ' ']),
        );
    }

    public function testEditFormDecodesTrackerValuesBeforeTierParsing()
    {
        $helper = __DIR__ . '/../../php/torrenttrackers.php';
        $this->assertTrue(is_file($helper), 'edit form needs shared tier parser');
        if (!is_file($helper)) {
            return;
        }
        require_once($helper);
        $parsed = TorrentTrackerTiers::fromEditFormValues([
            'http%3A%2F%2Fone%2Fannounce', '', 'udp%3A%2F%2Ftwo%2Fannounce', '']);
        $this->assertSame([['http://one/announce'],
            ['udp://two/announce']], $parsed['tiers']);
        $this->assertSame(2, $parsed['count']);
    }
}
