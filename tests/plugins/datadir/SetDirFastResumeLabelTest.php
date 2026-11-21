<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');
require_once(__DIR__ . '/../../php/PhpProcess.php');
require_once(__DIR__ . '/../../php/RtorrentProbeFixture.php');
require_once(__DIR__ . '/../../php/TestTree.php');
require_once(__DIR__ . '/ClaimParserFixture.php');

/** A fast-resume request changes directory in place and leaves labels owned by rTorrent. */
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

    public function testAPlainLabelIsKept()
    {
        $this->assertLabelKept('Plain');
    }

    public function testALabelWithAQuoteIsKept()
    {
        $this->assertLabelKept('Foo"Bar');
    }

    public function testALabelWithABackslashIsKept()
    {
        $this->assertLabelKept('C\\D');
    }

    public function testALabelRuTorrentEncodedIsKept()
    {
        $this->assertLabelKept('TV%20Shows');
    }

    public function testEncodedLabelRemainsUnchangedDuringInPlaceMove()
    {
        $this->assertLabelKept('a%5C%22z');
    }

    private function assertLabelKept($label)
    {
        TestTree::remove($this->base);
        mkdir($this->base, 0700, true);
        $result = $this->claimInPlace($this->base . '/dest', $label);
        $this->assertSame(
            ['refusal' => '', 'applied' => true, 'failure' => null],
            $result['answer'],
            'the guarded in-place change succeeds: ' . $result['output'],
        );
        $this->assertTrue(in_array('d.get_custom1', $result['calls'], true), 'the existing label is checked');
        $this->assertTrue(in_array('d.directory.set_if_stop_close_claim', $result['calls'], true), 'the claimed directory is changed');
        $wire = implode("\n", $result['bodies']);
        foreach (['d.erase', 'load.raw', 'd.custom1.set', 'd.set_custom1'] as $method) {
            $this->assertTrue(strpos($wire, $method) === false, 'the in-place change does not call ' . $method);
        }
    }

}
