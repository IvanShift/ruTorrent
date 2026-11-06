<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');
require_once(__DIR__ . '/../../php/PhpProcess.php');
require_once(__DIR__ . '/RealParserDriver.php');

/**
 * Cross-process collector checks on the explicitly supported stock 0.9.8
 * boundary. The real capability probe must establish that exception; modern
 * native intent and generation gates remain covered by the Native* suites.
 *
 * The collector asks d.hash separately for every owner. A returning owner
 * keeps both its data and the retryable manifest until its paths are no longer
 * owned. Only the exact captured legacy missing-hash fault permits the
 * collector to remove data, and retained staging permits nothing.
 *
 * Before a list is applied the collector also asks which downloads are loaded
 * (hash, directory, number of files), once a pass the sizes of their files,
 * and the paths of only the files of one of the list's sizes, and keeps a file
 * another download still uses (Novik #3514, cross-seed): the same path, or
 * the same file through a link; another hardlink is not a claim. Keeping it
 * completes the list.
 *
 * Each case leaves what the retired direct producer left in the queue, then a
 * fresh child executes the shipped collector service against a real SCGI peer,
 * with the real filesystem helper and profile.
 */
/** Stock capability reads stay separate from the scenario's erase/presence replies. */
class UnconfirmedEraseReplies
{
    private $replies;
    private $fleet;
    private $faults;

    // The questions about loaded downloads' files are answered from $fleet
    // (ErasedataLoadedFleetReplies), every other one with the next reply.
    public function __construct($replies, array $fleet = [], array $faults = [])
    {
        $this->replies = $replies;
        $this->fleet = $fleet;
        $this->faults = $faults;
    }

    public function __invoke($request, $methods)
    {
        $fleet = new ErasedataLoadedFleetReplies($this->fleet, ['not a fleet question'], $this->faults);
        $answer = $fleet($request, $methods);
        if ($answer !== 'not a fleet question') {
            return $answer;
        }
        if ($methods === ['system.listMethods']) {
            return new FakeRtorrentRawReply('<?xml version="1.0"?><methodResponse><params><param><value>'
                . '<array><data><value><string>system.listMethods</string></value></data></array>'
                . '</value></param></params></methodResponse>');
        }
        if ($methods === ['system.client_version']) {
            return new FakeRtorrentDirectStringReply('0.9.8');
        }
        return count($this->replies) ? array_shift($this->replies) : null;
    }
}

class UnconfirmedEraseTest extends TestCase
{
    public const HASH = '0123456789ABCDEF0123456789ABCDEF01234567';
    /** Another download the daemon may hold; never the one being erased. */
    public const OTHER = '89ABCDEF0123456789ABCDEF0123456789ABCDEF';

    private $base;
    private $daemon;
    private $port;

    public function setUp()
    {
        $this->base = sys_get_temp_dir() . '/rutorrent-unconfirmed-erase-' . getmypid();
        $this->removeTree($this->base);
        @mkdir($this->base . '/profile/settings', 0700, true);
    }

    public function tearDown()
    {
        $this->stopDaemon();
        $this->removeTree($this->base);
    }


    // -- an erase that was never confirmed --------------------------------------

    public function testRetainedStagingOfAnUnconfirmedEraseIsNotPublished()
    {
        $this->fresh();
        $download = $this->download();
        // The reply is for a collector that asks about hashes; one with
        // nothing to apply does not ask, and the reply stays unconsumed.
        $this->startDaemon([[self::HASH]]);
        $this->assertTrue($this->leaveManifest($download, self::HASH, 'tmp') === 'left', 'the staging is left');

        $out = $this->collect();

        $this->assertTrue($out === '', 'the collector runs without a diagnostic: ' . $out);
        $this->assertTrue(
            !$this->listPublished(),
            'the collector cannot grant deletion authority from the unknown retained staging',
        );
        foreach ($download['files'] as $file) {
            $this->assertTrue(
                $this->isFile($file),
                'a file of a download whose erase was not confirmed is still there: ' . basename($file),
            );
        }
    }

    // -- the same hash is loaded again before the collector's pass -------------

    public function testAListWhoseHashIsLoadedAgainRetainsItsObligationAndData()
    {
        $this->fresh();
        $download = $this->download();
        // The erase succeeds and the list is published. Before the collector's
        // next pass -- up to $garbageCheckInterval seconds later, longer when
        // a pass is skipped as busy -- the torrent is added again to the same
        // directory: a removal noticed as a mistake, a watch directory, an
        // RSS rule. rtorrent finds the files on disk and lists the hash
        // again; the first reply is what it tells the collector.
        $this->startDaemon([
            [self::HASH],
            $this->pathsReply($download),
        ], [self::HASH => $this->loaded($download)]);
        $this->assertTrue($this->leaveManifest($download) === 'left', 'the list is published');

        $out = $this->collect();

        $this->assertTrue($out === '', 'the collector runs without a diagnostic: ' . $out);
        $this->assertTrue(
            $this->daemon->received('d.hash'),
            'the collector asked the daemon which hashes it holds',
        );
        $this->assertTrue($this->listPublished(), 'the live owner retains the deletion obligation alongside its data');
        foreach ($download['files'] as $file) {
            $this->assertTrue(
                $this->isFile($file),
                'a file of a download the daemon holds again is still there: ' . basename($file),
            );
        }
        $this->assertTrue(
            count(array_keys($this->daemon->calls(), 'd.get_base_path', true)) === 1,
            'the returning torrent owns the same paths, so the collector reconciles them before retaining the obligation',
        );
    }

    // -- the daemon does not answer the collector ------------------------------

    public function testNothingIsDeletedWhenTheDaemonDoesNotAnswerTheCollector()
    {
        $this->fresh();
        $download = $this->download();
        $this->startDaemon([]);
        $this->assertTrue($this->leaveManifest($download) === 'left', 'the list is published');
        // rtorrent is down when the collector's pass comes: restarting,
        // crashed, its socket refused. Whether the download has been loaded
        // again is unknown, and the collector may not guess.
        $this->stopDaemon();

        $out = $this->collect();

        $this->assertTrue($out === '', 'the collector runs without a diagnostic: ' . $out);
        $this->assertTrue(
            $this->listPublished(),
            'the list is kept for a pass the daemon answers',
        );
        foreach ($download['files'] as $file) {
            $this->assertTrue(
                $this->isFile($file),
                'and nothing is deleted on an unknown answer: ' . basename($file),
            );
        }
        $this->assertTrue(
            count(glob($this->listPath() . '/' . self::HASH . '.*.list')) === 1,
            'the unknown capability/presence leaves its exact manifest discoverable for retry',
        );
    }

    public function testNothingIsDeletedWhenTheDaemonRefusesTheCollectorsQuestion()
    {
        $this->fresh();
        $download = $this->download();
        // rtorrent answers the collector's question with a fault -- what a
        // daemon that does not know the command as spelled does -- which is
        // as unknown an answer as none, and is told apart in the log so that
        // a line every pass can be read for what it is.
        $this->startDaemon([
            FakeRtorrentDaemon::fault('Unsupported target type found.'),
        ]);
        $this->assertTrue($this->leaveManifest($download) === 'left', 'the list is published');

        $out = $this->collect();

        $this->assertTrue($out === '', 'the collector runs without a diagnostic: ' . $out);
        $this->assertTrue($this->listPublished(), 'the list is kept for a pass the daemon answers');
        foreach ($download['files'] as $file) {
            $this->assertTrue(
                $this->isFile($file),
                'and nothing is deleted on an unknown answer: ' . basename($file),
            );
        }
        $this->assertTrue(
            $this->daemon->received('d.hash'),
            'the collector reached the per-hash presence question and retained its faulted obligation',
        );
    }

    // -- a hash is loaded again in the middle of a pass -------------------------

    public function testAHashLoadedAgainBetweenTwoListsOfOnePassIsSeen()
    {
        $this->fresh();
        $first = $this->download('First');
        $second = $this->download('Second');
        // Two downloads erased, two lists. rtorrent is asked before each list,
        // not once per pass: it holds nothing when asked the first time, and
        // both hashes when asked the second time. The order the collector
        // reads the directory in is the filesystem's, so what is pinned is
        // that exactly one list is applied and the other withdrawn, and that
        // the withdrawn one is the download whose files are still there.
        $this->startDaemon([
            FakeRtorrentDaemon::fault('Could not find info-hash.', -501),
            [self::OTHER],
            $this->pathsReply($second),
        ], [self::OTHER => $this->loaded($second)]);
        $this->assertTrue($this->leaveManifest($first, self::HASH) === 'left'
            && $this->leaveManifest($second, self::OTHER) === 'left', 'both lists are published');

        $out = $this->collect();

        $this->assertTrue($out === '', 'the collector runs without a diagnostic: ' . $out);
        $this->assertTrue(
            count(array_keys($this->daemon->calls(), 'd.hash', true)) === 2,
            'the collector asked the daemon once per list: ' . implode(' ', $this->daemon->calls()),
        );
        $this->assertTrue(
            !$this->listPublished(self::HASH) && $this->listPublished(self::OTHER),
            'the absent owner is collected while the returning owner keeps its exact obligation',
        );
        $kept = $second;
        $deleted = $first;
        foreach ($kept['files'] as $file) {
            $this->assertTrue(
                $this->isFile($file),
                'the download loaded again in the middle of the pass keeps its files: ' . basename($file),
            );
        }
        foreach ($deleted['files'] as $file) {
            $this->assertTrue(
                !$this->isFile($file),
                'and the one applied before the answer changed is deleted: ' . basename($file),
            );
        }
    }

    // -- the erase is confirmed and the hash stays gone -------------------------

    public function testAConfirmedEraseIsCollectedOnceTheHashIsGone()
    {
        $this->fresh();
        $download = $this->download();
        // At the collector's pass the daemon holds another download, not
        // this one.
        // Its file has our size, and is not there.
        $this->startDaemon([
            FakeRtorrentDaemon::fault('Could not find info-hash.', -501),
        ], [self::OTHER => ['directory' => $this->base . '/data/Other', 'files' => ['one.bin' => 3]]]);
        $this->assertTrue($this->leaveManifest($download) === 'left', 'the list is published');

        $out = $this->collect();

        $this->assertTrue($out === '', 'the collector runs without a diagnostic: ' . $out);
        foreach ($download['files'] as $file) {
            $this->assertTrue(
                !$this->isFile($file),
                'a file of the erased download is deleted: ' . basename($file),
            );
        }
        $this->assertTrue(!$this->isDir($download['base']), 'and so is its directory');
        $this->assertTrue(!$this->listPublished(), 'and the list is consumed');
        $this->assertTrue($this->logged() === '', 'with nothing to report: ' . $this->logged());
    }

    // -- another loaded download uses the same files (Novik #3514) -------------

    public function testFilesAnotherLoadedDownloadUsesAreKeptAndTheListCompletes()
    {
        $this->fresh();
        $download = $this->download();
        // S1: a cross-seed of the same release, loaded on the very same files.
        $this->startDaemon([
            FakeRtorrentDaemon::fault('Could not find info-hash.', -501),
        ], [self::OTHER => $this->loaded($download)]);
        $this->assertTrue($this->leaveManifest($download) === 'left', 'the list is published');

        $out = $this->collect();

        $this->assertTrue($out === '', 'the collector runs without a diagnostic: ' . $out);
        foreach ($download['files'] as $file) {
            $this->assertTrue($this->isFile($file), 'the other download keeps its file: ' . basename($file));
        }
        $this->assertTrue(!$this->listPublished(), 'keeping a shared file completes the list');
        $this->assertSame(
            ['erasedata: kept 2 file(s) of ' . self::HASH . ' claimed by 1 loaded torrent(s)'
                . ' reason=shared-with-loaded-torrent'],
            $this->loggedLines(),
            'one classified line says what was kept, without a path',
        );
    }

    public function testOnlyTheFileAnotherDownloadUsesIsKept()
    {
        $this->fresh();
        $download = $this->download();
        // S2: one file of the multi-file download is also a single-file one.
        $this->startDaemon([
            FakeRtorrentDaemon::fault('Could not find info-hash.', -501),
        ], [self::OTHER => ['directory' => $download['base'] . '/sub', 'files' => ['two.bin' => 3]]]);
        $this->assertTrue($this->leaveManifest($download) === 'left', 'the list is published');

        $out = $this->collect();

        $this->assertTrue($out === '', 'the collector runs without a diagnostic: ' . $out);
        $this->assertTrue(!$this->isFile($download['files'][0]), 'the file only this download used is deleted');
        $this->assertTrue($this->isFile($download['files'][1]), 'the single-file download keeps its file');
        $this->assertTrue(!$this->listPublished(), 'and the list completes');
        $this->assertSame(['erasedata: kept 1 file(s) of ' . self::HASH . ' claimed by 1 loaded torrent(s)'
            . ' reason=shared-with-loaded-torrent'], $this->loggedLines(), 'the kept file is counted');
    }

    public function testAnotherDownloadsHardlinkKeepsItsDataButNotOurName()
    {
        $this->fresh();
        $download = $this->download();
        // S4: the cross-seed hardlinked the files (#833's advice).
        $other = $this->base . '/data/Other';
        @mkdir($other, 0700, true);
        $this->assertTrue(link($download['files'][0], $other . '/one.bin'), 'the other download hardlinks one.bin');
        $this->startDaemon([
            FakeRtorrentDaemon::fault('Could not find info-hash.', -501),
        ], [self::OTHER => ['directory' => $other, 'files' => ['one.bin' => 3]]]);
        $this->assertTrue($this->leaveManifest($download) === 'left', 'the list is published');

        $out = $this->collect();

        $this->assertTrue($out === '', 'the collector runs without a diagnostic: ' . $out);
        foreach ($download['files'] as $file) {
            $this->assertTrue(!$this->isFile($file), 'our name is deleted: ' . basename($file));
        }
        $this->assertSame('one', file_get_contents($other . '/one.bin'), 'the other download keeps its data');
        $this->assertTrue(!$this->listPublished() && $this->loggedLines() === [], 'the list completes with nothing kept');
    }

    public function testAFileAnotherDownloadReachesThroughASymlinkIsKept()
    {
        $this->fresh();
        $download = $this->download();
        // S5: the cross-seed's file is a symlink to ours (linkDirs, no linkType).
        $other = $this->base . '/data/Other';
        @mkdir($other, 0700, true);
        $this->assertTrue(symlink($download['files'][0], $other . '/one.bin'), 'the other download links one.bin');
        $this->startDaemon([
            FakeRtorrentDaemon::fault('Could not find info-hash.', -501),
        ], [self::OTHER => ['directory' => $other, 'files' => ['one.bin' => 3]]]);
        $this->assertTrue($this->leaveManifest($download) === 'left', 'the list is published');

        $out = $this->collect();

        $this->assertTrue($out === '', 'the collector runs without a diagnostic: ' . $out);
        $this->assertSame('one', file_get_contents($other . '/one.bin'), 'the link still reads our file');
        $this->assertTrue(!$this->isFile($download['files'][1]), 'the unshared file is deleted');
        $this->assertTrue(!$this->listPublished(), 'and the list completes');
        $this->assertSame([
            'erasedata: kept 1 file(s) of ' . self::HASH . ' claimed by 1 loaded torrent(s)'
                . ' reason=shared-with-loaded-torrent',
        ], $this->loggedLines(), 'one classified line says what was kept');
    }

    public function testDeletePathRemovesOnlyOwnFilesWhenAnotherDownloadUsesItsDirectory()
    {
        foreach ([true, false] as $shared) {
            $this->fresh();
            $download = $this->download();
            // S3b: the other download keeps a file of its own in this directory.
            file_put_contents($download['base'] . '/other.bin', 'other');
            $this->startDaemon([
                FakeRtorrentDaemon::fault('Could not find info-hash.', -501),
            ], $shared ? [self::OTHER => ['directory' => $download['base'], 'files' => ['other.bin' => 5]]] : []);
            $this->assertTrue($this->leaveManifest($download, self::HASH, 'list', 2) === 'left', 'the list is published');

            $out = $this->collect('$enableForceDeletion = true;');

            $this->assertTrue($out === '', 'the collector runs without a diagnostic: ' . $out);
            $this->assertTrue(!$this->listPublished(), 'the list completes either way');
            if ($shared) {
                foreach ($download['files'] as $file) {
                    $this->assertTrue(!$this->isFile($file), 'its own file is deleted: ' . basename($file));
                }
                $this->assertSame('other', file_get_contents($download['base'] . '/other.bin'), 'the other file stays');
                $this->assertSame(
                    ['erasedata: Delete Path of ' . self::HASH . ' removed only its own files, because another'
                    . ' loaded torrent lies in its directory or uses its files reason=delete-path-narrowed'],
                    $this->loggedLines(),
                    'and the narrowing is said',
                );
            } else {
                $this->assertTrue(!$this->isDir($download['base']), 'with nothing else loaded, the whole directory goes');
                $this->assertSame([], $this->loggedLines(), 'with nothing to report');
            }
        }
    }

    // The list names its base through a symlink to the downloads root, and
    // rtorrent names another download's directory resolved: the base is
    // compared resolved too, so Delete Path is narrowed.
    public function testDeletePathSeesADownloadNamedThroughTheResolvedBase()
    {
        $this->fresh();
        $download = $this->download();
        $this->assertTrue(symlink($this->base . '/data', $this->base . '/data-link'), 'a link to the root');
        $listed = ['base' => $this->base . '/data-link/Download', 'files' => [
            $this->base . '/data-link/Download/one.bin', $this->base . '/data-link/Download/sub/two.bin']];
        file_put_contents($download['base'] . '/sub/other.bin', 'not ours, of no size of ours');
        $this->startDaemon(
            [FakeRtorrentDaemon::fault('Could not find info-hash.', -501)],
            [self::OTHER => ['directory' => $download['base'] . '/sub', 'files' => ['other.bin' => 28]]],
        );
        $this->assertTrue($this->leaveManifest($listed, self::HASH, 'list', 2) === 'left', 'the list is published');

        $out = $this->collect('$enableForceDeletion = true;');

        $this->assertTrue($out === '', 'the collector runs without a diagnostic: ' . $out);
        $this->assertTrue(
            !$this->isFile($download['files'][0]) && !$this->isFile($download['files'][1]),
            'its own files are deleted',
        );
        $this->assertSame(
            'not ours, of no size of ours',
            @file_get_contents($download['base'] . '/sub/other.bin'),
            'the other download\'s file stays',
        );
        $this->assertSame(
            ['erasedata: Delete Path of ' . self::HASH . ' removed only its own files, because another'
            . ' loaded torrent lies in its directory or uses its files reason=delete-path-narrowed'],
            $this->loggedLines(),
            'and the narrowing is said',
        );
        $this->assertSame([1, 1, 0], $this->questions(), 'decided from the directories alone');
    }

    // A download outside the base that uses a file of it narrows Delete Path
    // too: the base is not removed whole over the kept file.
    public function testDeletePathKeepsAFileADownloadOutsideTheBaseUses()
    {
        $this->fresh();
        $download = $this->download();
        $other = $this->base . '/data/Other';
        @mkdir($other, 0700, true);
        $this->assertTrue(symlink($download['files'][0], $other . '/one.bin'), 'the other download links one.bin');
        $this->startDaemon(
            [FakeRtorrentDaemon::fault('Could not find info-hash.', -501)],
            [self::OTHER => ['directory' => $other, 'files' => ['one.bin' => 3]]],
        );
        $this->assertTrue($this->leaveManifest($download, self::HASH, 'list', 2) === 'left', 'the list is published');

        $out = $this->collect('$enableForceDeletion = true;');

        $this->assertTrue($out === '', 'the collector runs without a diagnostic: ' . $out);
        $this->assertSame('one', @file_get_contents($other . '/one.bin'), 'the link still reads our file');
        $this->assertTrue(
            !$this->isFile($download['files'][1]) && !$this->listPublished(),
            'the unshared file is deleted and the list completes',
        );
        $this->assertSame([
            'erasedata: Delete Path of ' . self::HASH . ' removed only its own files, because another'
                . ' loaded torrent lies in its directory or uses its files reason=delete-path-narrowed',
            'erasedata: kept 1 file(s) of ' . self::HASH . ' claimed by 1 loaded torrent(s)'
                . ' reason=shared-with-loaded-torrent',
        ], $this->loggedLines(), 'both are said');
    }

    // A forced Delete Path whose base is gone holds nothing to narrow: it
    // completes, and says nothing of a download that lies elsewhere.
    public function testDeletePathOfABaseThatIsGoneIsNotNarrowed()
    {
        $this->fresh();
        $download = $this->download();
        $this->removeTree($download['base']);
        $this->startDaemon(
            [FakeRtorrentDaemon::fault('Could not find info-hash.', -501)],
            [self::OTHER => ['directory' => $this->base . '/data/Other', 'files' => ['other.bin' => 5]]],
        );
        $this->assertTrue($this->leaveManifest($download, self::HASH, 'list', 2) === 'left', 'the list is published');

        $out = $this->collect('$enableForceDeletion = true;');

        $this->assertTrue($out === '', 'the collector runs without a diagnostic: ' . $out);
        $this->assertTrue(!$this->listPublished(), 'the list completes');
        $this->assertSame([], $this->loggedLines(), 'with nothing narrowed to report');
    }

    // A forced Delete Path whose base cannot be resolved is narrowed; the
    // collector, unable to reach its files, retains the list without a word.
    public function testDeletePathOfABaseThatCannotBeResolvedIsRetained()
    {
        $this->fresh();
        $download = $this->download('Locked/Download');
        $this->startDaemon(
            [FakeRtorrentDaemon::fault('Could not find info-hash.', -501)],
            [self::OTHER => ['directory' => $this->base . '/data/Other', 'files' => ['other.bin' => 5]]],
        );
        $this->assertTrue($this->leaveManifest($download, self::HASH, 'list', 2) === 'left', 'the list is published');
        $this->assertTrue(chmod($this->base . '/data/Locked', 0600), 'the base can be made unsearchable');
        try {
            clearstatcache();
            // chmod cannot restrict root: the premise is checked, and the case
            // skipped where it does not hold.
            if (is_array(@stat($download['base']))) {
                $this->assertTrue(true, 'skipped: this process still searches a 0600 directory (running as root)');
                return;
            }
            $out = $this->collect('$enableForceDeletion = true;');
        } finally {
            chmod($this->base . '/data/Locked', 0700);
        }

        $this->assertTrue($out === '', 'the collector runs without a diagnostic: ' . $out);
        $this->assertTrue(
            $this->listPublished() && $this->isFile($download['files'][0]) && $this->isFile($download['files'][1]),
            'the list and its files wait',
        );
        $this->assertTrue(strpos($this->logged(), 'delete-path-narrowed') === false, 'nothing is reported narrowed');
    }

    // Delete Path is a multi-file download's: a single-file one forced the
    // same way keeps the file another download uses, and says only that.
    public function testAForcedSingleFileKeepsASharedFileWithoutNarrowing()
    {
        $this->fresh();
        $file = $this->base . '/data/Single.bin';
        @mkdir(dirname($file), 0700, true);
        file_put_contents($file, 'single');
        $this->startDaemon(
            [FakeRtorrentDaemon::fault('Could not find info-hash.', -501)],
            [self::OTHER => ['directory' => dirname($file), 'files' => ['Single.bin' => 6]]],
        );
        $this->assertTrue(
            $this->leaveManifest(['base' => $file, 'multi' => false, 'files' => [$file]], self::HASH, 'list', 2) === 'left',
            'the list is published',
        );

        $out = $this->collect('$enableForceDeletion = true;');

        $this->assertTrue($out === '', 'the collector runs without a diagnostic: ' . $out);
        $this->assertTrue($this->isFile($file) && !$this->listPublished(), 'the file stays and the list completes');
        $this->assertSame(['erasedata: kept 1 file(s) of ' . self::HASH . ' claimed by 1 loaded torrent(s)'
            . ' reason=shared-with-loaded-torrent'], $this->loggedLines(), 'one line, of the kept file');
    }

    // The same single-file download loaded again uses its file as the
    // download it is: the obligation is retained with it, not completed as
    // a file another download uses.
    public function testASingleFileDownloadLoadedAgainRetainsItsObligation()
    {
        $this->fresh();
        $file = $this->base . '/data/Single.bin';
        @mkdir(dirname($file), 0700, true);
        file_put_contents($file, 'single');
        $this->startDaemon(
            [[self::HASH], [$file, 0, $file]],
            [self::HASH => ['directory' => dirname($file), 'files' => ['Single.bin' => 6]]],
        );
        $this->assertTrue(
            $this->leaveManifest(['base' => $file, 'multi' => false, 'files' => [$file]]) === 'left',
            'the list is published',
        );

        $out = $this->collect();

        $this->assertTrue($out === '', 'the collector runs without a diagnostic: ' . $out);
        $this->assertTrue(
            $this->isFile($file) && $this->listPublished(),
            'the file stays, and so does the obligation while its own hash uses it',
        );
        $this->assertSame([], $this->loggedLines(), 'nothing is reported kept for another download');
    }

    // A file kept for another download is reported once its list completes,
    // not while another file of the list holds it back.
    public function testAKeptFileIsReportedOnlyWithItsCompletedList()
    {
        $this->fresh();
        $download = $this->download();
        // sub/ is now a link out of the download: two.bin is no longer one
        // the collector may delete, so the list stays.
        $this->assertTrue(rename($download['base'] . '/sub', $this->base . '/data/Elsewhere')
            && symlink($this->base . '/data/Elsewhere', $download['base'] . '/sub'), 'sub/ leads out');
        $this->startDaemon(
            [FakeRtorrentDaemon::fault('Could not find info-hash.', -501)],
            [self::OTHER => ['directory' => $download['base'], 'files' => ['one.bin' => 3]]],
        );
        $this->assertTrue($this->leaveManifest($download) === 'left', 'the list is published');

        $out = $this->collect();

        $this->assertTrue($out === '', 'the collector runs without a diagnostic: ' . $out);
        $this->assertTrue($this->listPublished(), 'the list is kept for the file it could not delete');
        $this->assertTrue(
            $this->isFile($download['files'][0]) && $this->isFile($this->base . '/data/Elsewhere/two.bin'),
            'both files are there',
        );
        $this->assertTrue(
            strpos($this->logged(), 'shared-with-loaded-torrent') === false,
            'nothing is reported kept yet: ' . $this->logged(),
        );
    }

    // An exhausted memory_limit while the downloads are read ends the
    // collector, and the list waits. One classified line says so wherever PHP
    // still runs shutdown functions after it; where PHP ends the process at
    // once (PhpProcess::memoryExhaustion()), nothing can.
    public function testAFatalErrorIsLoggedAndTheListIsKept()
    {
        $this->fresh();
        $download = $this->download();
        $row = '<value><array><data><value><string>' . self::OTHER . '</string></value><value><string>'
            . str_repeat('d', 2000) . '</string></value><value><i8>1</i8></value></data></array></value>';
        $this->startDaemon([FakeRtorrentDaemon::fault('Could not find info-hash.', -501)], [], [
            'loaded' => new FakeRtorrentRawReply('<?xml version="1.0"?><methodResponse><params><param><value><array>'
                . '<data>' . str_repeat($row, 20000) . '</data></array></value></param></params></methodResponse>')]);
        $this->assertTrue($this->leaveManifest($download) === 'left', 'the list is published');

        $ended = null;
        try {
            $this->collect('', '-d memory_limit=24M');
            $this->assertTrue(false, 'the collector ends on the fatal error');
        } catch (RuntimeException $error) {
            $ended = $error->getMessage();
        }
        // PHP's own text is not printed: the suite runner reads it as a
        // failure where it is printed. 7.4 prints none under the @ of the read
        // that exhausts memory, unless it ends the process at once.
        $php = PhpProcess::memoryExhaustion(['-c', __DIR__ . '/../../php-test.ini']);
        $this->assertTrue(
            is_string($ended) && strpos($ended, 'The production driver exited ' . $php['code'] . ':') === 0
            && ($php['shutdown'] || strpos($ended, 'Allowed memory size of 25165824 bytes exhausted') !== false),
            'the collector ends on the exhausted memory_limit as this PHP ends one (exit ' . $php['code']
            . ($php['shutdown'] ? ', shutdown functions run' : ', no shutdown function runs') . '): '
            . (is_string($ended) ? strtok($ended, ':') : 'it did not end'),
        );
        $this->assertSame(
            $php['shutdown'] ? ['erasedata: the collector stopped on a fatal error; its lists stay queued'
                . ' reason=collector-fatal memory_limit=24M'] : [],
            $this->loggedLines(),
            'one classified line, where PHP still runs shutdown functions: ' . json_encode($this->loggedLines()),
        );
        $this->assertTrue($this->listPublished() && $this->isFile($download['files'][0]), 'the list and its data wait');
    }

    public function testTwoDownloadsRemovedTogetherLeaveNothingAndAskTheSizesOnce()
    {
        $this->fresh();
        $download = $this->download();
        // S7: both cross-seeds removed with their data in one request. An
        // unrelated download stays loaded; its sizes are asked once a pass.
        $this->startDaemon([
            FakeRtorrentDaemon::fault('Could not find info-hash.', -501),
            FakeRtorrentDaemon::fault('Could not find info-hash.', -501),
        ], [str_repeat('C', 40) => ['directory' => $this->base . '/data/Unrelated', 'files' => ['big.bin' => 4096]]]);
        $this->assertTrue($this->leaveManifest($download, self::HASH) === 'left'
            && $this->leaveManifest($download, self::OTHER) === 'left', 'both lists are published');

        $out = $this->collect();

        $this->assertTrue($out === '', 'the collector runs without a diagnostic: ' . $out);
        foreach ($download['files'] as $file) {
            $this->assertTrue(!$this->isFile($file), 'the shared file is deleted: ' . basename($file));
        }
        $this->assertTrue(!$this->listPublished(self::HASH) && !$this->listPublished(self::OTHER), 'both lists complete');
        $this->assertSame([2, 1, 0], $this->questions(), 'each list asks for the downloads, the sizes are asked'
            . ' once a pass, and no path of an unrelated size');
    }

    public function testNothingIsDeletedWhileTheFilesOfLoadedDownloadsAreUnknown()
    {
        $cases = [
            'the downloads question faults' => ['loaded' => FakeRtorrentDaemon::fault('Unsupported target type found.')],
            'the sizes question faults' => ['sizes' => FakeRtorrentDaemon::fault('Unsupported target type found.')],
            'the paths question never ends' => ['paths' => FakeRtorrentDaemon::paced([
                "Status: 200 OK\r\nContent-Type: text/xml\r\nContent-Length: 400\r\n\r\n<?xml",
                '<methodResponse>'], 3000000)],
        ];
        foreach ($cases as $case => $faults) {
            $this->fresh();
            $download = $this->download();
            // The other download has a file of our size, so its path is asked.
            $this->startDaemon(
                [FakeRtorrentDaemon::fault('Could not find info-hash.', -501)],
                [self::OTHER => ['directory' => $this->base . '/data/Other', 'files' => ['one.bin' => 3]]],
                $faults,
            );
            $this->assertTrue($this->leaveManifest($download) === 'left', 'the list is published');

            $out = $this->collect('$rpcTransferTimeOut = 1;');

            $this->assertTrue($out === '', $case . ': the collector runs without a diagnostic: ' . $out);
            foreach ($download['files'] as $file) {
                $this->assertTrue($this->isFile($file), $case . ': nothing is deleted: ' . basename($file));
            }
            $this->assertTrue($this->listPublished(), $case . ': the whole list is kept for the next pass');
        }
        $this->assertTrue(
            strpos($this->logged(), 'rXMLRPCRequest: ') !== false && strpos($this->logged(), 'timeout') !== false,
            'a question that never ends is logged by the transport: ' . $this->logged(),
        );
    }

    private function stopDaemon()
    {
        if ($this->daemon !== null) {
            $this->daemon->stop();
            $this->daemon = null;
        }
    }

    private function removeTree($path)
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path), ['.', '..']) as $name) {
            $entry = $path . '/' . $name;
            is_dir($entry) && !is_link($entry) ? $this->removeTree($entry) : @unlink($entry);
        }
        @rmdir($path);
    }

    private function repoRoot()
    {
        return realpath(__DIR__ . '/../../..');
    }

    private function listPath()
    {
        return $this->base . '/profile/settings/erasedata';
    }

    /**
     * setUp() runs once for the whole class, so every case starts from its
     * own download, an empty list directory and an empty log.
     */
    private function fresh()
    {
        $this->stopDaemon();
        $this->removeTree($this->listPath());
        $this->removeTree($this->base . '/data');
        @unlink($this->base . '/calls.log');
        @unlink($this->base . '/calls.log.bodies');
        @unlink($this->base . '/app.log');
    }

    /** A multi-file download on disk, as rtorrent would have written it. */
    private function download($name = 'Download')
    {
        $base = $this->base . '/data/' . $name;
        @mkdir($base . '/sub', 0700, true);
        file_put_contents($base . '/one.bin', 'one');
        file_put_contents($base . '/sub/two.bin', 'two');
        return [
            'base' => $base,
            'files' => [$base . '/one.bin', $base . '/sub/two.bin'],
        ];
    }

    /**
     * What the daemon answers a request for the paths -- the base path, the
     * multi-file flag and f.frozen_path of every file, in one multicall.
     */
    private function pathsReply($download)
    {
        return array_merge([$download['base'], 1], $download['files']);
    }

    private function startDaemon($replies, array $fleet = [], array $faults = [])
    {
        $this->daemon = new FakeRtorrentDaemon(
            new UnconfirmedEraseReplies($replies, $fleet, $faults),
            $this->base . '/calls.log',
            false,
            [__FILE__],
        );
        // Kept apart from the daemon, so a collector can be pointed at the
        // address of a daemon that has since gone away.
        $this->port = $this->daemon->port();
    }

    /**
     * A script carrying the production configuration pointed at this test's
     * profile and daemon; the stub stands in for the settings cache that a
     * running instance would have filled from the daemon.
     */
    private function preamble()
    {
        $root = $this->repoRoot();
        return "<?php\n"
            . '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->base . '/profile', true) . ";\n"
            . '$_ENV[\'RU_LOG_FILE\'] = ' . var_export($this->base . '/app.log', true) . ";\n"
            . 'require_once(' . var_export($root . '/conf/config.php', true) . ");\n"
            . '$scgi_host = "127.0.0.1"; $scgi_port = ' . (int) $this->port . ";\n"
            . '$rpcTimeOut = 5; $rpcLogCalls = false; $rpcLogFaults = false;' . "\n"
            . 'chdir(' . var_export($root . '/php', true) . "); require_once('xmlrpc.php');\n"
            . '$__r = new ReflectionClass("rTorrentSettings");'
            . ' $__s = $__r->newInstanceWithoutConstructor();'
            . ' $__s->iVersion = 0x908; $__s->aliases = array(); $__s->plugins = array();'
            . ' $__s->directory = ' . var_export($this->base, true) . '; $__s->linkExist = true;'
            . ' $__p = $__r->getProperty("theSettings");'
            // Before 8.1 a private static property cannot be written through
            // reflection without lifting its visibility first; from 8.1 the call
            // has no effect, and on 8.5 its deprecation notice would land in the
            // driver output the cases compare. The driver runs under PHP_BINARY,
            // so this process's version is the driver's.
            . (PHP_VERSION_ID < 80100 ? ' $__p->setAccessible(true);' : '')
            . ' $__p->setValue(null, $__s);' . "\n";
    }

    private function run_($driver, $options = '')
    {
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -c ' . escapeshellarg(__DIR__ . '/../../php-test.ini')
            . ' -d display_errors=1 ' . $options . ' ' . escapeshellarg($driver) . ' 2>&1', $output, $status);
        if ($status !== 0) {
            throw new RuntimeException('The production driver exited ' . $status . ': ' . implode("\n", $output));
        }
        return implode("\n", $output);
    }

    /**
     * Leaves the manifest of $download (multi-file unless its 'multi' is
     * false) in the queue as the retired direct producer did,
     * <HASH>.<pid>.<uniqid>.$type: "list" after a confirmed erase, "tmp" for
     * the staging it retained after an unconfirmed one. The shipped code
     * makes the queue; the daemon is not asked.
     */
    private function leaveManifest($download, $hash = self::HASH, $type = 'list', $force = 1)
    {
        $root = $this->repoRoot();
        $driver = $this->base . '/drive-leave.php';
        file_put_contents($driver, $this->preamble()
            . 'require_once(' . var_export($root . '/plugins/erasedata/removewithdata.php', true) . ");\n"
            . '$__q = erasedataQueuePath();' . "\n"
            . '$__b = ErasedataManifestCodec::encode(' . var_export($hash, true) . ', array("base" => '
            . var_export($download['base'], true) . ', "multi" => '
            . var_export(!isset($download['multi']) || $download['multi'], true) . ', "files" => '
            . var_export($download['files'], true) . '), ' . (int) $force . ');' . "\n"
            . '$__n = $__q . "/" . ' . var_export($hash, true) . ' . "." . getmypid() . "." . uniqid("", true) . '
            . var_export('.' . $type, true) . ';' . "\n"
            . 'echo (erasedataEnsureQueueDirectory($__q) && is_string($__b)'
            . ' && file_put_contents($__n, $__b) === strlen($__b)) ? "left" : "refused";' . "\n");
        return $this->run_($driver);
    }

    /**
     * plugins/erasedata/update.php, as rtorrent's schedule runs it: a process
     * of its own, with no argument on a single-user install. It talks to the
     * daemon too, so it carries the same configuration as the writer.
     */
    private function collect($settings = '', $options = '')
    {
        $root = $this->repoRoot();
        $driver = $this->base . '/drive-collect.php';
        file_put_contents($driver, $this->preamble()
            . 'require(' . var_export($root . '/plugins/erasedata/update.php', true) . ");\n"
            . $settings . "\n"
            . 'erasedataCollectorMain(new ErasedataFilesystemOps());' . "\n");
        return $this->run_($driver, $options);
    }

    /**
     * is_file() for a path another process has created or removed since this
     * one last looked: PHP answers from its stat and realpath caches otherwise,
     * and on 7.4 and 8.1 that is a list the collector has already unlinked.
     */
    private function isFile($path)
    {
        clearstatcache(true, $path);
        return is_file($path);
    }

    private function isDir($path)
    {
        clearstatcache(true, $path);
        return is_dir($path);
    }

    private function listPublished($hash = self::HASH)
    {
        return count(glob($this->listPath() . '/' . $hash . '.*.list')) === 1;
    }

    /** Every file in the list directory, by name. */
    private function listed()
    {
        $names = [];
        foreach ((array) glob($this->listPath() . '/*') as $entry) {
            $names[] = basename($entry);
        }
        return $names;
    }

    /** What a loaded download lists for $download's files: its directory, each path and size. */
    private function loaded($download)
    {
        $files = [];
        foreach ($download['files'] as $file) {
            $files[substr($file, strlen($download['base']) + 1)] = filesize($file);
        }
        return ['directory' => $download['base'], 'files' => $files];
    }

    /** How many requests asked for the downloads, their sizes and their paths. */
    private function questions()
    {
        $count = [0, 0, 0];
        foreach ($this->daemon->bodies() as $body) {
            if (strpos($body, 'size_files=') !== false) {
                $count[0]++;
            } elseif (strpos($body, 'size_bytes') !== false) {
                $count[1]++;
            } elseif (preg_match('/>f\.(get_)?path</', $body)) {
                $count[2]++;
            }
        }
        return $count;
    }

    /** The log, one entry per line, without timestamps. */
    private function loggedLines()
    {
        $lines = [];
        foreach (explode("\n", trim($this->logged())) as $line) {
            if ($line !== '') {
                $lines[] = preg_replace('/^\[[^\]]*\] /', '', $line);
            }
        }
        return $lines;
    }

    private function logged()
    {
        $log = $this->base . '/app.log';
        return $this->isFile($log) ? file_get_contents($log) : '';
    }
}
