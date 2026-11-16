<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/GlobalSettingsFixture.php');
require_once(__DIR__ . '/FakeRtorrentDaemon.php');
require_once(__DIR__ . '/HalfLoadedReplies.php');
require_once(__DIR__ . '/PluginEntryDriver.php');
require_once(__DIR__ . '/TestTree.php');
require_once(__DIR__ . '/../../php/rtorrent.php');

/**
 * The data, mediainfo, screenshots, spectrogram and unpack doors
 * (rTorrent::getFilePath()), httprpc's getsavepath, unpack's archive check
 * and the datadir move (rTorrent::getBasePath()) read the path of a closed
 * download without opening it, and from where it is now.
 *
 * A download rtorrent has queued for its hash check is closed while it
 * waits, and one it has not opened since it loaded its session has empty
 * f.frozen_path and d.base_path. The doors used to open such a download to
 * read its path and close it again; the d.close cancels the queued check,
 * and the download stays closed with d.state 1 until it is started by hand
 * (b5repro cut 1, 2026-11-16). Those answers were captured on such
 * downloads (HalfLoadedReplies).
 *
 * A download that was open once keeps the paths frozen at that open after
 * d.close, and d.directory.set on the closed download does not refresh
 * them. Its path is d.directory and f.path then, where a payload moved
 * since really is: the frozen one sent the datadir plugin's next move to a
 * source that no longer exists (b10app lab, 2026-11-16). Those answers were
 * captured on 0.16.28 and 0.9.8 (fixtures/closed-moved-*.json).
 */
class ClosedPathReadTest extends TestCase
{
    private const FILE_PATH = 'd.is_open|f.frozen_path|d.directory|f.path';
    private const BASE_PATH = 'd.is_open|d.base_path|d.directory|f.path|d.is_multi_file';

    private $globals;
    private $previousSettings;
    private $base;
    private $daemon;

    public function setUp()
    {
        $this->globals = GlobalSettingsFixture::scgi();
        $settings = (new ReflectionClass('rTorrentSettings'))->newInstanceWithoutConstructor();
        $settings->iVersion = 0x1019;
        $settings->apiVersion = 11;
        $settings->aliases = [];
        // The daemon's own command names, as the application sends them to 0.9.4 and later.
        (function () {
            require(__DIR__ . '/../../php/methods-0.9.4.php');
        })->call($settings);
        $property = new ReflectionProperty('rTorrentSettings', 'theSettings');
        self::makeAccessible($property);
        $this->previousSettings = $property->getValue();
        $property->setValue(null, $settings);
        $this->base = sys_get_temp_dir() . '/rutorrent-half-loaded-' . getmypid();
        TestTree::remove($this->base);
        mkdir($this->base, 0700, true);
        $GLOBALS['scgi_host'] = '127.0.0.1';
        $GLOBALS['rpcTimeOut'] = 10;
        $GLOBALS['rpcLogCalls'] = false;
        $GLOBALS['rpcLogFaults'] = false;
        $GLOBALS['log_file'] = $this->base . '/app.log';
    }

    public function tearDown()
    {
        if ($this->daemon !== null) {
            $this->daemon->stop();
            $this->daemon = null;
        }
        $property = new ReflectionProperty('rTorrentSettings', 'theSettings');
        self::makeAccessible($property);
        $property->setValue(null, $this->previousSettings);
        $this->globals->restore();
        TestTree::remove($this->base);
    }

    public function testFilePathOfAQueuedMultiFileDownloadIsReadWithoutOpeningIt()
    {
        $this->daemon(['f.frozen_path' => 'frozen-path-call', self::FILE_PATH => 'filepath-multi']);
        $this->assertSame(
            '/data/downloads/t318/s0/f0.bin',
            rTorrent::getFilePath(HalfLoadedReplies::MULTI, 0),
            'the path is d.directory and f.path, the bytes the daemon freezes when it opens the download',
        );
        $this->assertNeverOpenedOrClosed();
    }

    public function testFilePathOfAQueuedSingleFileDownloadIsReadWithoutOpeningIt()
    {
        $this->daemon(['f.frozen_path' => 'frozen-path-call', self::FILE_PATH => 'filepath-single']);
        $this->assertSame(
            '/data/downloads/t83',
            rTorrent::getFilePath(HalfLoadedReplies::SINGLE, 0),
            'a single-file download\'s d.directory is the directory its file is in',
        );
        $this->assertNeverOpenedOrClosed();
    }

    public function testBasePathsOfQueuedDownloadsAreReadWithoutOpeningThem()
    {
        $this->daemon([self::BASE_PATH => 'basepath-multi']);
        $this->assertSame(
            '/data/downloads/t318',
            rTorrent::getBasePath(HalfLoadedReplies::MULTI),
            'a closed multi-file download\'s base path is its d.directory',
        );
        $this->assertNeverOpenedOrClosed();
        $this->daemon([self::BASE_PATH => 'basepath-single']);
        $this->assertSame(
            '/data/downloads/t83',
            rTorrent::getBasePath(HalfLoadedReplies::SINGLE),
            'a closed single-file download\'s base path is the path of its file',
        );
        $this->assertNeverOpenedOrClosed();
    }

    /** httprpc mode=getsavepath, the datadir dialog's and mobile's read of a closed torrent. */
    public function testGetsavepathAnswersAQueuedDownloadWithoutOpeningIt()
    {
        foreach ([[HalfLoadedReplies::MULTI, '/data/downloads/t318', 'basepath-multi'],
            [HalfLoadedReplies::SINGLE, '/data/downloads/t83', 'basepath-single']] as [$hash, $path, $fixture]) {
            $this->daemon(['d.is_open' => 'is-open-call', self::BASE_PATH => $fixture]);
            $this->assertSame(
                ['0', $path, '0'],
                $this->getsavepath($hash),
                'every cell keeps its meaning: the d.open and d.close answers stay "0"',
            );
            $this->assertNeverOpenedOrClosed();
        }
    }

    /**
     * Stopped and closed after it was open, its payload moved and d.directory
     * set: the frozen paths still name the old place (rtorrent 0.16.28 and
     * 0.9.8), and the reads answer the new one, the place d.open; d.base_path
     * answered on the same downloads.
     */
    public function testAClosedDownloadMovedSinceItWasOpenIsReadWhereItIsNow()
    {
        foreach ([['0.16.28-cdff6007', 'cur-moved', [
            'multi' => ['7DE84FE10952653CCCD4EBFF1C03CE38BEE31D06', 't240', '/s0/f0.bin'],
            'single' => ['9DCE43993FB98F8C645F9FC8AB1D543ED6BC20EE', 't46', '']]],
            ['0.9.8', 'legacy-moved', [
                'multi' => ['C2C3718DB86DC2FA923A64DB1F03A0B829DA9982', 'lxm', '/sub/m0.bin'],
                'single' => ['69D5F5FFBBFE9E6D1B1910925BEFEA2D086CBF6B', 'lx6', '']]]] as [$version, $moved, $downloads]) {
            $answers = json_decode(file_get_contents(__DIR__ . '/fixtures/closed-moved-rtorrent-' . $version . '.json'), true);
            foreach ($downloads as $kind => [$hash, $name, $file]) {
                $this->daemon([
                    self::FILE_PATH => $answers['filepath-' . $kind],
                    self::BASE_PATH => $answers['basepath-' . $kind],
                ]);
                $what = $version . ' ' . $kind . ': ';
                $this->assertSame(
                    '/data/' . $moved . '/' . $name . $file,
                    rTorrent::getFilePath($hash, 0),
                    $what . 'the file is under the new d.directory, not the path frozen at the last open',
                );
                $this->assertSame(
                    '/data/' . $moved . '/' . $name,
                    rTorrent::getBasePath($hash),
                    $what . 'and so is the base path',
                );
                $this->assertSame(['0', '/data/' . $moved . '/' . $name, '0'], $this->getsavepath($hash), $what . 'getsavepath');
                $this->assertNeverOpenedOrClosed();
            }
        }
    }

    private function getsavepath($hash)
    {
        list($code, $out, $err) = PluginEntryDriver::run(
            $this->base,
            $this->daemon->port(),
            'plugins/httprpc/action.php',
            ['post' => ['mode' => 'getsavepath', 'hash' => $hash],
                'before' => '$HTTP_RAW_POST_DATA = ' . var_export('mode=getsavepath&hash=' . $hash, true) . ';'
                    . ' (function () { require(' . var_export(__DIR__ . '/../../php/methods-0.9.4.php', true) . '); })'
                    . '->call(rTorrentSettings::get());'],
        );
        $this->assertSame(0, $code, 'getsavepath exits cleanly: ' . $out . $err);
        return json_decode($out, true);
    }

    private function daemon(array $answers)
    {
        if ($this->daemon !== null) {
            $this->daemon->stop();
        }
        $this->daemon = new FakeRtorrentDaemon(
            new HalfLoadedReplies($answers),
            $this->base . '/calls.log',
            false,
            [__DIR__ . '/HalfLoadedReplies.php'],
        );
        $GLOBALS['scgi_port'] = $this->daemon->port();
    }

    private function assertNeverOpenedOrClosed()
    {
        $calls = $this->daemon->calls();
        $this->assertTrue(
            $calls !== [] && !in_array('d.open', $calls, true) && !in_array('d.close', $calls, true),
            'a read neither opens nor closes the download; calls: ' . implode(', ', $calls),
        );
    }
}
