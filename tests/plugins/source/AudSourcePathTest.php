<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/TorrentSequenceFixtures.php');
require_once(__DIR__ . '/../../php/PluginEntryDriver.php');

/**
 * AUD-ESC-13 for source and dump: both find a download's .torrent through
 * rTorrent::getSourceFile() or the same request, session.path and
 * d.tied_to_file, in the daemon's own bytes. A session directory or a tied file whose path held
 * a quote or a backslash was "not found" while every string came escaped.
 */
class AudSourcePathTest extends TestCase
{
    use TorrentSequenceFixtures;

    public const HASH = '0123456789ABCDEF0123456789ABCDEF01234567';
    public const AUD = 'aud "q" \b \'a\' &c';
    // The probe name plus the pairs an extra unescape would collapse.
    public const AUD_PAIRS = 'aud "q" \b \'a\' &c \\\\x \\"y';

    private $base;
    private $daemon;

    public function setUp()
    {
        $this->base = sys_get_temp_dir() . '/rutorrent-aud-source-' . getmypid();
        mkdir($this->base . '/' . self::AUD_PAIRS, 0700, true);
    }

    public function tearDown()
    {
        $this->stopDaemon();
        PluginEntryDriver::removeTree($this->base);
    }

    public function testAudSourceSendsTheTorrentTheDaemonNamed()
    {
        foreach ($this->sources() as $label => $source) {
            list($answers, $path) = $source;
            file_put_contents($path, $this->announceOnlyTorrent());
            list($exit, $out, $err) = $this->drive(
                'plugins/source/action.php',
                $answers,
                ['post' => ['hash' => self::HASH]],
            );
            @unlink($path);
            $this->assertSame($this->announceOnlyTorrent(), $out, $label . ': the .torrent is sent: ' . $out . $err);
        }
    }

    public function testAudDumpInspectsTheTorrentTheDaemonNamed()
    {
        foreach ($this->sources() as $label => $source) {
            list($answers, $path) = $source;
            file_put_contents($path, $this->announceOnlyTorrent());
            list($exit, $out, $err) = $this->drive(
                'plugins/dump/action.php',
                $answers,
                ['post' => ['cmd' => 'dumptorrent', 'hash' => self::HASH]],
            );
            @unlink($path);
            $scripts = PluginEntryDriver::taskScripts($this->base);
            $this->assertTrue(
                count($scripts) === 1 && strpos($scripts[0], escapeshellarg($path)) !== false,
                $label . ': dumptorrent is given the file the daemon named: ' . $out . ($scripts[0] ?? ''),
            );
        }
    }

    private function stopDaemon()
    {
        if ($this->daemon !== null) {
            $this->daemon->stop();
        }
        $this->daemon = null;
    }

    /** The two places a source can be: the session copy, or the tied file. */
    private function sources()
    {
        $session = $this->base . '/' . self::AUD_PAIRS . '/';
        $tied = $this->base . '/' . self::AUD_PAIRS . '/' . self::AUD . '.torrent';
        return [
            'session copy' => [[$session, ''], $session . self::HASH . '.torrent'],
            'tied file' => [['', $tied], $tied],
        ];
    }

    private function drive($script, array $answers, array $options)
    {
        $this->stopDaemon();
        PluginEntryDriver::removeTree($this->base . '/profile');
        list($session, $tied) = $answers;
        $this->daemon = new FakeRtorrentDaemon(new FakeRtorrentMethodReplies([
            'get_session' => [$session], 'session.path' => [$session],
            'd.get_tied_to_file' => [$tied], 'd.tied_to_file' => [$tied],
        ]), $this->base . '/calls.log');
        return PluginEntryDriver::run($this->base, $this->daemon->port(), $script, $options);
    }
}
