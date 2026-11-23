<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');
require_once(__DIR__ . '/../../php/PhpProcess.php');
require_once(__DIR__ . '/../../php/RtorrentProbeFixture.php');
require_once(__DIR__ . '/../../php/TestTree.php');
require_once(__DIR__ . '/ClaimParserFixture.php');

/** An in-place directory change does not erase or reload the torrent. */
class SetDirFastResumeLabelTest extends TestCase
{
    use ClaimParserFixture;
    public const HASH = '0123456789ABCDEF0123456789ABCDEF01234567';
    public const USER = 'tester';

    private $base;
    private $daemon;

    public function setUp()
    {
        $this->base = sys_get_temp_dir() . '/rutorrent-datadir-label-' . getmypid();
    }

    public function tearDown()
    {
        if ($this->daemon !== null) {
            $this->daemon->stop();
            $this->daemon = null;
        }
        TestTree::remove($this->base);
    }

    public function testInPlaceChangeDoesNotEraseOrReload()
    {
        TestTree::remove($this->base);
        mkdir($this->base, 0700, true);
        $result = $this->claimInPlace($this->base . '/dest', 'Plain');
        $this->assertSame(
            ['refusal' => '', 'applied' => true, 'failure' => null],
            $result['answer'],
            'the guarded in-place change succeeds: ' . $result['output'],
        );
        $this->assertTrue(in_array('d.get_custom1', $result['calls'], true), 'the existing label is checked');
        $this->assertTrue(in_array('d.directory.set_if_stop_close_claim', $result['calls'], true), 'the claimed directory is changed');
        foreach (['load_raw', 'load_raw_start', 'load', 'load_start',
            'load.raw', 'load.raw_start', 'load.normal', 'load.start'] as $method) {
            $this->assertTrue(!in_array($method, $result['calls'], true), 'the in-place change does not call ' . $method);
        }
        $wire = implode("\n", $result['bodies']);
        foreach (['d.erase', 'load.raw', 'd.custom1.set', 'd.set_custom1', 'd.delete_tied', 'd.tied_to_file.set', 'd.set_tied_to_file'] as $method) {
            $this->assertTrue(strpos($wire, $method) === false, 'the in-place change does not call ' . $method);
        }
    }

}
