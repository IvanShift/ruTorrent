<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/RealParserDriver.php');

/**
 * Which of a removal's files another loaded download still uses (Novik
 * #3514): erasedataLoadedDownloads(), erasedataClaimedFiles() and
 * erasedataDownloadsUnder() in plugins/erasedata/shared.php.
 *
 * The questions go through the real php/xmlrpc.php parser, first to the
 * answers real daemons gave on 2026-11-05, with their SCGI
 * headers (task 2026-11-05-issue-3514, evidence-fix/r1/captures): the
 * downloads, the sizes of their files joined by cat, and the paths of the
 * three files of the own files' sizes. The first answer is extended in the
 * test with total sizes derived from the second captured answer: the original
 * capture predates the additional d.get_size_bytes column.
 * - fixtures/loaded-downloads-0.16.27/: rtorrent 0b334910 (0.16.27), image
 *   rutorrent-local:release-20261104;
 * - fixtures/loaded-downloads-0.9.8/: rtorrent v0.9.8, image
 *   rutorrent-local:pins-20261101-legacy (pretty-printed, CRLF).
 * Both escape only '&', '<' and '>'. A magnet stub is one file of one byte,
 * in the session directory on 0.16.27 and the download directory on 0.9.8.
 * The other cases use a daemon (ErasedataLoadedFleetReplies) that answers in
 * the same wire shape.
 */
class LoadedFilesTest extends TestCase
{
    public const B = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';
    public const C = 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';
    public const STUB = 'F3514F3514F3514F3514F3514F3514F3514F3514';
    // Where the captured downloads lie, and the multi-file one's name.
    public const ROOT = '/data/downloads/c3514r1';
    public const MULTI = 'Multi "Q" & \\ <üж';
    public const CAPTURED = [
        '0.16.27' => ['37F8C5FF57A7720169FDC076F093FEEC3D579884', '8B84AA8A55F4398F853AF9BE83793DE691042F1D',
            '/data/.session'],
        '0.9.8' => ['74C5453644F2CE3F1EC506D31121BFF8194B25DA', '325B634BE820EA510744026DCE51450937B8641A',
            '/data/downloads'],
    ];

    private $base;
    private $driver;

    public function setUp()
    {
        $this->base = sys_get_temp_dir() . '/rutorrent-loaded-files-' . getmypid() . '-' . uniqid();
        mkdir($this->base, 0700, true);
        $this->base = realpath($this->base);
        $this->driver = new ErasedataRealParserDriver($this->base);
        require_once(__DIR__ . '/../../../plugins/erasedata/shared.php');
    }

    public function tearDown()
    {
        $this->driver->stop();
        $this->remove($this->base);
    }

    // -- the questions, on what real daemons answered ---------------------------

    public function testTheCapturedAnswersAreRead()
    {
        $own = [$this->file('own/own17.bin', str_repeat('a', 17000)), $this->file('own/own25.bin', str_repeat('b', 25000))];
        foreach (self::CAPTURED as $version => $hashes) {
            $answer = $this->drive([], $this->claimBody($own), [], null, $this->captured($version));
            $this->assertSame([
                $hashes[0] => [self::ROOT . '/multi/' . self::MULTI, 3, '74000'],
                $hashes[1] => [self::ROOT . '/single', 1, '25000'],
                self::STUB => [$hashes[2], 1, '1'],
            ], $answer['downloads'], $version . ': hashes, directories in the daemon\'s bytes, and file counts');
            $this->assertSame(
                [$hashes[0] => '17000/17000/40000/', $hashes[1] => '25000/', self::STUB => '1/'],
                $answer['sizes'],
                $version . ': the sizes of each download\'s files',
            );
            $this->assertSame([[], null], [$answer['claimed'], $answer['why']], $version . ': no path is ours here');
            $this->assertSame(
                ['loaded' => 1, 'sizes' => 1, 'paths' => 1, 'f.path' => 3],
                $answer['asked'],
                $version . ': the paths of the three files of our sizes, in one request',
            );
        }
    }

    // The same answers, the downloads moved under this test's directory: a
    // path joined from the daemon's bytes reaches the file it names.
    public function testTheCapturedPathsReachTheFilesTheyName()
    {
        foreach (self::CAPTURED as $version => $hashes) {
            $root = $this->base . '/' . $version;
            $own = [$this->file($version . '/multi/' . self::MULTI . '/sub "d"/ü ж\\b.bin', str_repeat('a', 17000)),
                $this->file($version . '/single/single "one" & ä.bin', str_repeat('b', 25000))];
            $answer = $this->drive([], $this->claimBody($own), [], null, $this->captured($version, $root));
            $this->assertSame(
                [$own[0] => [$hashes[0] => true], $own[1] => [$hashes[1] => true]],
                $answer['claimed'],
                $version . ': each file is claimed by the download that names it',
            );
        }
    }

    // -- the rule, on real files ----------------------------------------------

    public function testTheSameFileUnderTheSamePathIsClaimedAndOtherSizesAreNotAsked()
    {
        $own = $this->file('s1/Show/e01.bin', 'one');
        $this->file('s1/Show/e02.bin', 'two');
        $answer = $this->claim([
            // S1 and S2: B names this very path, and a path nobody has.
            self::B => ['directory' => $this->base . '/s1/Show', 'files' => ['e01.bin' => 3, 'gone.bin' => 3]],
            // Files of no size of ours are never asked about.
            self::C => ['directory' => $this->base . '/s1/Show', 'files' => ['e02.bin' => 4]],
        ], [$own, $this->base . '/s1/Show/e02.bin']);
        $this->assertSame([$own => [self::B => true]], $answer['claimed'], 'exactly the named file is claimed, by B');
        $this->assertSame(
            ['loaded' => 1, 'sizes' => 1, 'paths' => 1, 'f.path' => 2],
            $answer['asked'],
            'the paths of B\'s two files of our size, in one request: ' . json_encode($answer['asked']),
        );
    }

    public function testASymlinkToOurFileClaimsItAndOurSymlinkIsTheNameItIs()
    {
        $own = $this->file('s5a/movie.bin', 'movie bytes');
        mkdir($this->base . '/s5b');
        $this->assertTrue(symlink($own, $this->base . '/s5b/movie.bin'), 'B links our file');
        $fleet = [self::B => ['directory' => $this->base . '/s5b', 'files' => ['movie.bin' => 11]]];
        $this->assertSame(
            [$own => [self::B => true]],
            $this->claim($fleet, [$own])['claimed'],
            'S5: B reads our file through its link',
        );
        // Hardlinked elsewhere too, our file's link count says nothing; the
        // link still resolves to our very name.
        $this->assertTrue(link($own, $this->base . '/library.bin'), 'a second link');
        $this->assertSame(
            [$own => [self::B => true]],
            $this->claim($fleet, [$own])['claimed'],
            'S5 with a second link: the symlink resolves to our name',
        );

        // Ours is the symlink: unlink() removes the link, so only a download
        // that reaches the link itself uses it.
        $target = $this->file('pool/data.bin', 'shared data');
        $link = $this->base . '/ours/data.bin';
        mkdir(dirname($link));
        $this->assertTrue(symlink($target, $link), 'our file is a symlink');
        $this->assertSame([], $this->claim(
            [self::B => ['directory' => $this->base . '/pool', 'files' => ['data.bin' => 11]]],
            [$link],
        )['claimed'], 'a download of the target does not use our link');
        $this->assertSame([$link => [self::C => true]], $this->claim(
            [self::C => ['directory' => $this->base . '/ours', 'files' => ['data.bin' => 11]]],
            [$link],
        )['claimed'], 'a download that names our link uses it');
    }

    public function testAnotherHardlinkIsNotAClaim()
    {
        $own = $this->file('s4a/movie.bin', 'movie bytes');
        mkdir($this->base . '/s4b');
        link($own, $this->base . '/s4b/movie.bin');
        link($own, $this->base . '/s4a/other.bin');
        $this->assertSame([], $this->claim(
            [self::B => ['directory' => $this->base . '/s4b', 'files' => ['movie.bin' => 11]],
                self::C => ['directory' => $this->base . '/s4a', 'files' => ['other.bin' => 11]]],
            [$own],
        )['claimed'], 'S4: deleting our name leaves either download its own name and the data, in another'
            . ' directory or beside ours');
    }

    // The entry check on a directory that folds case and Unicode
    // normalisation is pinned by a probe on a casefold tmpfs (task
    // 2026-11-05-issue-3514, evidence-fix/r1/casefold); this is the listing it
    // reads.
    public function testOnlyAListedNameIsAnotherEntry()
    {
        $own = $this->file('fold/Movie.mkv', 'movie');
        link($own, $this->base . '/fold/Other.mkv');
        $this->assertTrue(erasedataSameEntry('Movie.mkv', $own), 'our own name');
        $this->assertTrue(!erasedataSameEntry('Other.mkv', $own), 'a hard link listed under its own name');
        $this->assertTrue(erasedataSameEntry('MOVIE.MKV', $own), 'a spelling the listing does not hold');
        $this->assertTrue(
            erasedataSameEntry('Movie.mkv', $this->base . '/fold/MOVIE.MKV'),
            'our own spelling not in the listing, the other\'s in it',
        );
        $this->assertTrue(
            erasedataSameEntry('Other.mkv', $this->base . '/gone/Movie.mkv'),
            'kept when the directory cannot be listed',
        );
        $lower = $this->file('case/movie.mkv', 'movie');
        $this->assertTrue(link($lower, $this->base . '/case/MOVIE.mkv'), 'a case-sensitive directory holds both');
        $this->assertTrue(
            !erasedataSameEntry('MOVIE.mkv', $lower),
            'two listed names that differ only in case are two hard links',
        );
    }

    // erasedataSameName() asks the listing (erasedataSameEntry()), not the
    // spelling: another hard link beside ours, in a directory it cannot list,
    // is kept.
    public function testAHardLinkBesideOursInADirectoryThatCannotBeListedIsKept()
    {
        $own = $this->file('locked/Movie.mkv', 'movie');
        $this->assertTrue(link($own, $this->base . '/locked/Other.mkv'), 'a hard link beside it');
        $this->assertTrue(chmod($this->base . '/locked', 0300), 'the directory can be made unlistable');
        try {
            clearstatcache();
            // chmod cannot restrict root: the premise is checked, and the case
            // skipped where it does not hold.
            if (is_array(@scandir($this->base . '/locked'))) {
                $this->assertTrue(true, 'skipped: this process still lists a 0300 directory (running as root)');
                return;
            }
            $this->assertSame([$own => [self::C => true]], $this->claim(
                [self::C => ['directory' => $this->base . '/locked', 'files' => ['Other.mkv' => 5]]],
                [$own],
            )['claimed'], 'the other name cannot be told from ours, so ours is kept');
        } finally {
            chmod($this->base . '/locked', 0700);
        }
    }

    // stat() follows a chain of up to 40 symlinks, PHP's realpath() gives up
    // after 32: such a path to a file with two links is kept, not guessed.
    public function testAPathRealpathCannotResolveIsKept()
    {
        $own = $this->file('chain/movie.bin', 'movie bytes');
        $this->assertTrue(link($own, $this->base . '/chain-library.bin'), 'a second link');
        $previous = $own;
        for ($i = 0; $i < 35; $i++) {
            $this->assertTrue(symlink($previous, $this->base . '/chain/l' . $i), 'link ' . $i);
            $previous = $this->base . '/chain/l' . $i;
        }
        // symlink() left the links in this process's realpath cache; the
        // collector, another process, starts without them.
        clearstatcache(true);
        $this->assertTrue(
            is_array(@stat($previous)) && @realpath($previous) === false,
            'stat() reaches the file, realpath() does not',
        );
        $this->assertSame([$own => [self::B => true]], $this->claim(
            [self::B => ['directory' => $this->base . '/chain', 'files' => ['l34' => 11]]],
            [$own],
        )['claimed'], 'kept rather than guessed');
    }

    public function testSizesAreComparedModulo2To32()
    {
        $own = $this->file('wrap/own.bin', 'five!');
        $other = $this->file('wrap/other.bin', 'other');
        $answer = $this->claim([self::B => ['directory' => dirname($other),
            'files' => ['other.bin' => 4294967301, 'own.bin' => 6]]], [$own]);
        $this->assertSame([], $answer['claimed'], 'another file of the same size modulo 2^32 is not ours');
        $this->assertSame(1, $answer['asked']['f.path'], 'but is asked about, as a 32-bit PHP must: '
            . json_encode($answer['asked']));
    }

    public function testPathsAreAskedInBatches()
    {
        $files = [];
        $own = [];
        for ($i = 0; $i < 5; $i++) {
            $own[] = $this->file('batch/f' . $i . '.bin', 'xyz');
            $files['f' . $i . '.bin'] = 3;
        }
        $answer = $this->claim([self::B => ['directory' => $this->base . '/batch', 'files' => $files]], $own, [], 2);
        $this->assertSame(5, count($answer['claimed']), 'every file is claimed');
        $this->assertSame(
            ['loaded' => 1, 'sizes' => 1, 'paths' => 3, 'f.path' => 5],
            $answer['asked'],
            'two paths a request: ' . json_encode($answer['asked']),
        );
    }

    public function testTheSizesAreAskedOnceAPassAndAgainWhenADownloadIsNew()
    {
        $own = $this->file('pass/own.bin', 'abc');
        $answer = $this->drive(
            [self::B => ['directory' => $this->base . '/pass', 'files' => ['own.bin' => 3]]],
            '$why = null; $sizes = null; $d = erasedataLoadedDownloads($why);'
            . ' $own = ' . var_export([$own], true) . ';'
            . ' $first = erasedataClaimedFiles($own, $d, $sizes, $why);'
            . ' $second = erasedataClaimedFiles($own, $d, $sizes, $why);'
            . ' $d["' . self::C . '"] = array("/elsewhere", 1, "1");'
            . ' $new = erasedataClaimedFiles($own, $d, $sizes, $why); $newWhy = $why;'
            . ' unset($d["' . self::C . '"]); $d["' . self::B . '"][1] = 2; $sizes = array("' . self::B . '" => "3/");'
            . ' $count = erasedataClaimedFiles($own, $d, $sizes, $why);'
            . ' echo json_encode(array("first" => $first, "second" => $second, "new" => $new, "newWhy" => $newWhy,'
            . ' "count" => $count, "why" => $why, "sizes" => $sizes));',
        );
        $this->assertSame([$own => [self::B => true]], $answer['first'], 'the file is claimed');
        $this->assertSame($answer['first'], $answer['second'], 'and again from the kept sizes');
        $this->assertSame(
            [null, 'unreadable'],
            [$answer['new'], $answer['newWhy']],
            'a download the sizes do not have is asked for again, and still missing it is unreadable',
        );
        $this->assertSame(
            [null, 'unreadable'],
            [$answer['count'], $answer['why']],
            'so is a download that holds another number of files',
        );
        $this->assertSame(3, $answer['asked']['sizes'], 'once for the pass, once for each change');
    }

    // The sizes kept from the last question are dropped before they are
    // asked for again, so the old and the new answer are never held at once.
    public function testOldSizesAreDroppedBeforeTheyAreAskedForAgain()
    {
        $count = 1 << 21;
        $answer = new FakeRtorrentRawReply('<?xml version="1.0"?><methodResponse><params><param><value><array>'
            . '<data><value><array><data><value><string>' . self::B . '</string></value><value><string>'
            . str_repeat('1/', $count) . '</string></value></data></array></value></data></array></value></param>'
            . '</params></methodResponse>');
        $peak = [];
        foreach (['old' => 1 << 20, 'none' => 1] as $case => $old) {
            $run = $this->drive([], '$why = null; $d = array("' . self::B . '" => array("/elsewhere", ' . $count . '));'
                . ' $sizes = array("' . self::B . '" => str_repeat("1/", ' . $old . '));'
                . ' $c = erasedataClaimedFiles(array(), $d, $sizes, $why);'
                . ' echo json_encode(array("claimed" => $c, "peak" => memory_get_peak_usage()));', ['sizes' => $answer]);
            $this->assertSame([[], 1], [$run['claimed'], $run['asked']['sizes']], $case . ': the sizes are asked again');
            $peak[$case] = $run['peak'];
        }
        $this->assertTrue(
            $peak['old'] - $peak['none'] < (1 << 20),
            'the 2 MiB of old sizes are gone before the new 4 MiB arrive: ' . json_encode($peak),
        );
    }

    public function testSingleFileSizeChangeRefreshesTheSizes()
    {
        $own = $this->file('changed/own.bin', 'abc');
        $answer = $this->drive(
            [self::B => ['directory' => $this->base . '/changed', 'files' => ['own.bin' => 3]]],
            '$why = null; $sizes = array("' . self::B . '" => "1/");'
            . ' $d = erasedataLoadedDownloads($why);'
            . ' $claimed = erasedataClaimedFiles(' . var_export([$own], true) . ', $d, $sizes, $why);'
            . ' echo json_encode(array("claimed" => $claimed, "sizes" => $sizes, "why" => $why));',
        );
        $this->assertSame([$own => [self::B => true]], $answer['claimed'], 'the changed single file is still protected');
        $this->assertSame([self::B => '3/'], $answer['sizes'], 'the stale single-file size is refreshed');
        $this->assertSame(1, $answer['asked']['sizes'], 'the sizes are queried again');
    }

    public function testAFailedQuestionIsNoAnswerAndKeepsNoSizes()
    {
        $own = $this->file('fail/own.bin', 'abc');
        $fleet = [self::B => ['directory' => $this->base . '/fail', 'files' => ['own.bin' => 3]]];
        $multicallFault = new FakeRtorrentRawReply('<?xml version="1.0"?><methodResponse><params><param><value><array>'
            . '<data><value><struct><member><name>faultCode</name><value><i8>-501</i8></value></member><member>'
            . '<name>faultString</name><value><string>Could not find info-hash.</string></value></member></struct>'
            . '</value></data></array></value></param></params></methodResponse>');
        $sizesRow = function ($sizes) {
            return new FakeRtorrentRawReply('<?xml version="1.0"?><methodResponse><params><param><value><array>'
                . '<data><value><array><data><value><string>' . self::B . '</string></value><value><string>'
                . $sizes . '</string></value></data></array></value></data></array></value></param></params>'
                . '</methodResponse>');
        };
        $cases = [
            'the downloads refused' => [['loaded' => FakeRtorrentDaemon::fault('Bad view.')], 'fault'],
            'the downloads unanswered' => [['loaded' => null], 'no-answer'],
            'the sizes refused' => [['sizes' => FakeRtorrentDaemon::fault('Bad command.')], 'fault'],
            'a size that is not a number' => [['sizes' => $sizesRow('three/')], 'unreadable'],
            // A row without its sizes is not read as a download of none.
            'a row without its sizes' => [['sizes' => new FakeRtorrentRawReply('<?xml version="1.0"?>'
                . '<methodResponse><params><param><value><array><data><value><array><data><value><string>'
                . self::B . '</string></value></data></array></value></data></array></value></param></params>'
                . '</methodResponse>')], 'unreadable'],
            'the paths refused' => [['paths' => FakeRtorrentDaemon::fault('Bad target.')], 'fault'],
            // A fault in place of one result reads as two values for one.
            'a path refused in the batch' => [['paths' => $multicallFault], 'unreadable'],
        ];
        foreach ($cases as $case => $plan) {
            $answer = $this->drive($fleet, '$why = null; $sizes = array("' . self::C . '" => "1/");'
                . ' $d = erasedataLoadedDownloads($why);'
                . ' $c = is_array($d) ? erasedataClaimedFiles(' . var_export([$own], true) . ', $d, $sizes, $why) : null;'
                . ' echo json_encode(array("claimed" => $c, "why" => $why, "sizes" => $sizes));', $plan[0]);
            $this->assertSame([null, $plan[1]], [$answer['claimed'], $answer['why']], $case . ' is no answer');
            if (isset($plan[0]['sizes'])) {
                $this->assertSame(null, $answer['sizes'], $case . ': no sizes are kept, old or partial');
                $this->assertSame(1, $answer['asked']['sizes'], $case . ': asked once');
            }
        }
        $answer = $this->drive(
            [self::B => ['directory' => '/x', 'files' => []]],
            '$why = null; $d = erasedataLoadedDownloads($why); echo json_encode(array("d" => $d, "why" => $why));',
            ['loaded' => new FakeRtorrentRawReply('<?xml version="1.0"?><methodResponse><params><param><value>'
                . '<array><data><value><array><data><value><string>' . self::B . '</string></value><value><string>/x'
                . '</string></value></data></array></value></data></array></value></param></params></methodResponse>')],
        );
        $this->assertSame([null, 'unreadable'], [$answer['d'], $answer['why']], 'a row without its file count is unreadable');
    }

    public function testAFileNamedLikeAFaultIsAFile()
    {
        $own = $this->file('names/faultCode <faultString> & "q" \\ ü.nfo', 'nfo');
        $this->assertSame(
            [$own => [self::B => true]],
            $this->claim([self::B => [
                'directory' => $this->base . '/names', 'files' => [basename($own) => 3]]], [$own])['claimed'],
            'only a <fault> element is a fault, never a path that names one, and the path is the daemon\'s bytes',
        );
    }

    // -- which downloads lie in a directory -------------------------------------

    public function testADownloadLiesInADirectoryByItsNameOrResolved()
    {
        $real = $this->base . '/pool/Show';
        mkdir($real . '/Season1', 0700, true);
        mkdir($this->base . '/pool/Show2', 0700, true);
        symlink($this->base . '/pool', $this->base . '/link');
        symlink($this->base . '/pool', $this->base . '/alias');
        $listed = $this->base . '/link/Show';
        $downloads = [
            'IN' => [$real . '/Season1', 2],
            'SAME' => [$real, 1],
            'NAMED' => [$listed . '/Season1/', 2],
            'RESOLVED' => [$this->base . '/alias/Show/Season1', 1],
            'RELATIVE' => ['Show', 1],
            'EMPTY' => ['', 0],
            // Not on disk yet, so only the spelling tells.
            'NOT YET, AS LISTED' => [$listed . '/Season2', 2],
            'NOT YET, RESOLVED' => [$real . '/Season3', 2],
            'PREFIX' => [$this->base . '/pool/Show2', 1],
            'ABOVE' => [$this->base . '/pool', 1],
        ];
        $this->assertSame(
            ['IN', 'SAME', 'NAMED', 'RESOLVED', 'RELATIVE', 'EMPTY', 'NOT YET, AS LISTED', 'NOT YET, RESOLVED'],
            array_keys(erasedataDownloadsUnder($real, $listed . '/', $downloads)),
            'in it as rtorrent names it or resolved, or placed only by rtorrent\'s working directory;'
            . ' not a sibling with a longer name, not above it',
        );
        $this->assertSame(
            ['IN', 'SAME', 'NAMED', 'RESOLVED', 'RELATIVE', 'EMPTY', 'NOT YET, AS LISTED', 'NOT YET, RESOLVED', 'PREFIX',
                'ABOVE'],
            array_keys(erasedataDownloadsUnder('', $listed, $downloads)),
            'a directory that does not resolve holds every download',
        );
    }

    // -- helpers ----------------------------------------------------------------

    // erasedataClaimedFiles() for $own against $fleet, with what was asked.
    private function claim(array $fleet, array $own, array $faults = [], $per = null)
    {
        return $this->drive($fleet, $this->claimBody($own), $faults, $per);
    }

    private function claimBody(array $own)
    {
        return '$why = null; $sizes = null; $d = erasedataLoadedDownloads($why);'
            . ' $c = is_array($d) ? erasedataClaimedFiles(' . var_export($own, true) . ', $d, $sizes, $why) : null;'
            . ' echo json_encode(array("downloads" => $d, "sizes" => $sizes, "claimed" => $c, "why" => $why));';
    }

    // The three answers $version gave, the downloads' root moved to $root
    // and Content-Length counted again when it is.
    private function captured($version, $root = null)
    {
        $replies = [];
        for ($n = 1; $n <= 3; $n++) {
            $bytes = file_get_contents(__DIR__ . '/fixtures/loaded-downloads-' . $version . '/answer-' . $n . '.xml');
            if ($n === 1 || $root !== null) {
                list($head, $body) = explode("\r\n\r\n", $bytes, 2);
                if ($n === 1) {
                    $totals = [74000, 25000, 1];
                    $body = preg_replace_callback(
                        '/(<value><i8>[0-9]+<\/i8><\/value>)(\s*<\/data><\/array><\/value>)/',
                        function ($match) use (&$totals) {
                            return $match[1] . '<value><i8>' . array_shift($totals) . '</i8></value>' . $match[2];
                        },
                        $body,
                    );
                }
                if ($root !== null) {
                    $body = str_replace(self::ROOT, $root, $body);
                }
                $bytes = preg_replace('/Content-Length: [0-9]+/', 'Content-Length: ' . strlen($body), $head)
                    . "\r\n\r\n" . $body;
            }
            $replies[] = FakeRtorrentDaemon::raw($bytes);
        }
        return $replies;
    }

    private function drive(array $fleet, $body, array $faults = [], $per = null, $replies = null)
    {
        $answer = $this->driver->drive(
            $replies === null ? new ErasedataLoadedFleetReplies($fleet, [], $faults) : $replies,
            ($per === null ? '' : 'define("ERASEDATA_PATHS_PER_REQUEST", ' . (int) $per . ");\n")
            . 'require_once(' . var_export(realpath(__DIR__ . '/../../../plugins/erasedata/shared.php'), true) . ");\n"
            . $body,
        );
        $asked = ['loaded' => 0, 'sizes' => 0, 'paths' => 0, 'f.path' => 0];
        foreach ($this->driver->daemon()->bodies() as $request) {
            // f.get_path, or f.path as rtorrent 0.9.7 and later spell it.
            $paths = preg_match_all('#<(methodName|string)>f\.(get_)?path</#', $request);
            $kind = strpos($request, 'size_files') !== false ? 'loaded'
                : (strpos($request, 'size_bytes') !== false ? 'sizes' : ($paths ? 'paths' : null));
            if ($kind !== null) {
                $asked[$kind]++;
            }
            $asked['f.path'] += $paths;
        }
        $answer['asked'] = $asked;
        return $answer;
    }

    private function file($relative, $bytes)
    {
        $path = $this->base . '/' . $relative;
        @mkdir(dirname($path), 0700, true);
        file_put_contents($path, $bytes);
        return $path;
    }

    private function remove($path)
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path), ['.', '..']) as $name) {
            $this->remove($path . '/' . $name);
        }
        @rmdir($path);
    }
}
