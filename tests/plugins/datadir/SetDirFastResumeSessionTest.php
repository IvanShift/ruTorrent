<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');
require_once(__DIR__ . '/../../php/PhpProcess.php');
require_once(__DIR__ . '/../../php/RtorrentProbeFixture.php');
require_once(__DIR__ . '/../../php/TestTree.php');
require_once(__DIR__ . '/ClaimParserFixture.php');

/** An in-place directory change leaves session copies and tied files intact. */
class SetDirFastResumeSessionTest extends TestCase
{
    use ClaimParserFixture;
    public const HASH = '0123456789ABCDEF0123456789ABCDEF01234567';
    public const USER = 'tester';

    private $base;
    private $daemon;

    public function setUp()
    {
        $this->base = sys_get_temp_dir() . '/rutorrent-datadir-fastresume-' . getmypid();
    }

    public function tearDown()
    {
        if ($this->daemon !== null) {
            $this->daemon->stop();
            $this->daemon = null;
        }
        TestTree::remove($this->base);
    }

    public function testInPlaceChangeKeepsSessionCopyWithAQuoteAndABackslash()
    {
        $this->assertSessionKept('se"ss\\ion');
    }

    public function testInPlaceChangeKeepsSessionCopyWithABackslashBeforeAQuote()
    {
        $this->assertSessionKept('a\\"b');
    }

    public function testInPlaceChangeKeepsPlainSessionCopy()
    {
        $this->assertSessionKept('session');
    }

    public function testInPlaceChangeKeepsTiedFileWhenTheSessionHoldsNoCopy()
    {
        $this->assertSessionKept('session', 'ti"ed\\x/t.torrent');
    }

    private function assertSessionKept($sessionName, $tiedName = null)
    {
        TestTree::remove($this->base);
        $session = $this->base . '/' . $sessionName;
        mkdir($session, 0700, true);
        $copy = $tiedName === null ? $session . '/' . self::HASH . '.torrent' : $this->base . '/' . $tiedName;
        if (!is_dir(dirname($copy))) {
            mkdir(dirname($copy), 0700, true);
        }
        file_put_contents($copy, 'owned recovery bytes');
        $result = $this->claimInPlace($this->base . '/dest', '', $session, $tiedName === null ? '' : $copy);
        $this->assertSame(
            ['refusal' => '', 'applied' => true, 'failure' => null],
            $result['answer'],
            'the guarded in-place change succeeds: ' . $result['output'],
        );
        $this->assertSame('owned recovery bytes', file_get_contents($copy), 'the exact session or tied file remains');
        $this->assertTrue(in_array('d.directory.set_if_stop_close_claim', $result['calls'], true), 'the claimed directory is changed');
        $wire = implode("\n", $result['bodies']);
        foreach (['d.erase', 'load.raw', 'd.delete_tied', 'd.tied_to_file.set', 'd.set_tied_to_file'] as $method) {
            $this->assertTrue(strpos($wire, $method) === false, 'the in-place change does not call ' . $method);
        }
    }
}
