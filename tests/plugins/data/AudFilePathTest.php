<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/PluginEntryDriver.php');
require_once(__DIR__ . '/../../php/HttprpcListFixture.php');

/** Preserve the real system.multicall result slots, including optional name evidence. */
class AudFilePathReplies extends FakeRtorrentMethodReplies
{
    public function __invoke($request, $methods)
    {
        $values = parent::__invoke($request, $methods);
        if (strpos($request, '<methodName>system.multicall</methodName>') === false) {
            return $values;
        }
        $body = HttprpcListFixture::body(array_map(function ($value) {
            return [$value];
        }, $values));
        // Keep this witness's literal quote/backslash spelling on the wire.
        return new FakeRtorrentRawReply(str_replace(['&quot;', '&apos;'], ['"', "'"], $body));
    }
}

/**
 * AUD-ESC-06: the plugins that open one file of a download -- data ("Get
 * data"), mediainfo, screenshots and spectrogram -- take its path from
 * rTorrent::getFilePath(), in the daemon's own bytes.
 *
 * Each runs from its own entry script against FakeRtorrentDaemon, which
 * answers the way both daemons do: only & < > become entities, a quote and a
 * backslash arrive literally. When rXMLRPCRequest escaped every string, a file
 * whose path held a quote or a backslash was "not found" by all four; data
 * carried a private copy of getFilePath() as well.
 */
class AudFilePathTest extends TestCase
{
    public const HASH = '0123456789ABCDEF0123456789ABCDEF01234567';
    public const AUD = 'aud "q" \b \'a\' &c';
    // The probe name plus the pairs an extra unescape would collapse.
    public const AUD_PAIRS = 'aud "q" \b \'a\' &c \\\\x \\"y';
    public const PAYLOAD = "aud payload\n";

    private $base;
    private $file;
    private $daemon;

    public function setUp()
    {
        $this->base = sys_get_temp_dir() . '/rutorrent-aud-filepath-' . getmypid();
        $directory = $this->base . '/data/' . self::AUD_PAIRS;
        mkdir($directory, 0700, true);
        $this->file = $directory . '/' . self::AUD . '.mkv';
        file_put_contents($this->file, self::PAYLOAD);
    }

    public function tearDown()
    {
        $this->stopDaemon();
        PluginEntryDriver::removeTree($this->base);
    }

    public function testAudDataSendsTheFileTheDaemonNamed()
    {
        foreach (['open' => true, 'closed' => false] as $state => $open) {
            list($exit, $out, $err) = $this->drive(
                'plugins/data/action.php',
                $this->file,
                $open,
                ['get' => ['hash' => self::HASH, 'no' => '0']],
            );
            $this->assertSame(self::PAYLOAD, $out, $state . ' download: "Get data" sends the file: ' . $out . $err);
            $this->assertSame(0, $exit, $state . ' download: the script ends cleanly: ' . $err);
            $this->assertSame(
                1,
                count($this->daemon->bodies()),
                'optional download-label evidence stays in the existing path request',
            );
        }
        $this->assertSame(
            [],
            array_values(array_intersect($this->daemon->calls(), ['d.open', 'd.close'])),
            'a closed download is neither opened nor closed to read its path',
        );
    }

    public function testAudMediaTasksAreGivenTheFileTheDaemonNamed()
    {
        foreach (['mediainfo' => 'mediainfo', 'screenshots' => 'ffmpeg', 'spectrogram' => 'sox'] as $plugin => $cmd) {
            list($exit, $out, $err) = $this->drive(
                'plugins/' . $plugin . '/action.php',
                $this->file,
                false,
                ['post' => ['cmd' => $cmd, 'hash' => self::HASH, 'no' => '0']],
            );
            $scripts = PluginEntryDriver::taskScripts($this->base);
            $this->assertSame(1, count($scripts), $plugin . ' builds one task: ' . $out . $err);
            $this->assertTrue(
                isset($scripts[0]) && strpos($scripts[0], escapeshellarg($this->file)) !== false,
                $plugin . ' runs its tool on the file the daemon named: ' . ($scripts[0] ?? ''),
            );
        }
    }

    /**
     * spectrogram titles its image with the file's name. The name must not
     * depend on the locale: in C, PHP 7.4's basename() made Сериал.flac .flac.
     */
    public function testSpectrogramTitlesTheImageWithTheFileNameInTheCLocale()
    {
        $file = $this->base . '/data/Сериал.flac';
        file_put_contents($file, self::PAYLOAD);
        list($exit, $out, $err) = $this->drive(
            'plugins/spectrogram/action.php',
            $file,
            false,
            ['post' => ['cmd' => 'sox', 'hash' => self::HASH, 'no' => '0'],
                'before' => 'setlocale(LC_CTYPE, "C");'],
        );
        $scripts = PluginEntryDriver::taskScripts($this->base);
        $this->assertSame(1, count($scripts), 'spectrogram builds one task: ' . $out . $err);
        $this->assertTrue(
            isset($scripts[0]) && strpos($scripts[0], " -t 'Сериал.flac' ") !== false,
            'its title is the file\'s name: ' . ($scripts[0] ?? ''),
        );
    }

    private function stopDaemon()
    {
        if ($this->daemon !== null) {
            $this->daemon->stop();
            $this->daemon = null;
        }
    }

    /**
     * An open download answers its frozen path; a closed one has its file at
     * d.directory "/" f.path, wherever its frozen path says it was.
     */
    private function drive($script, $file, $open, array $options)
    {
        $this->stopDaemon();
        PluginEntryDriver::removeTree($this->base . '/profile');
        $slash = strrpos($file, '/');
        $replies = [];
        foreach (['d.is_open' => $open ? '1' : '0', 'f.get_frozen_path' => $open ? $file : '/moved/away' . substr($file, $slash),
            'd.get_directory' => substr($file, 0, $slash),
            'f.get_path' => substr($file, $slash + 1)] as $method => $value) {
            $replies[$method] = $replies[str_replace('.get_', '.', $method)] = [$value];
        }
        $this->daemon = new FakeRtorrentDaemon(new AudFilePathReplies($replies), $this->base . '/calls.log', false, [__FILE__]);
        return PluginEntryDriver::run($this->base, $this->daemon->port(), $script, $options);
    }
}
