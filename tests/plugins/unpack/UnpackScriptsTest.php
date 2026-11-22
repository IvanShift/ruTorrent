<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/PluginEntryDriver.php');
require_once(__DIR__ . '/UnpackArchiveFixture.php');
require_once(__DIR__ . '/../../../php/utility/utility.php');

/**
 * The unpack scripts and stage.sh under this runtime's own sh, with
 * extractors written here: what reaches place.php, the links unrar -ol-
 * skips, a private directory a cancelled task left and one that cannot be
 * made. The real unzip and unrar are run in UnpackArchiveLinksImageSmoke.php.
 */
class UnpackScriptsTest extends TestCase
{
    public const HASH = UnpackArchiveFixture::HASH;
    // unrar 7.23's own "lt" lines for a RAR 5.0 archive with a Unix and a
    // Windows symbolic link and a junction (UnpackArchiveFixture::rar()).
    public const LISTED_LINKS = "        Name: real\n        Type: File\n        Name: lib.so\n        Type: Unix symbolic link\n"
        . "      Target: real\n        Name: winlink\n        Type: Windows symbolic link\n      Target: real\n"
        . "        Name: junction\n        Type: NTFS junction point\n      Target: real\n"
        . "        Name: Type: Junction\n        Type: File\n";

    private $base;
    private $user;

    public function setUp()
    {
        $this->base = sys_get_temp_dir() . '/rutorrent-unpack-scripts-' . getmypid() . '-' . bin2hex(random_bytes(3));
        foreach (['profile', 'in', 'out', 'outside'] as $directory) {
            mkdir($this->base . '/' . $directory, 0700, true);
        }
        $this->base = realpath($this->base);
        $this->user = UnpackArchiveFixture::userWithLog($this->base . '/user.log');
    }

    public function tearDown()
    {
        exec('chmod -R u+rwx ' . escapeshellarg($this->base));
        PluginEntryDriver::removeTree($this->base);
        UnpackArchiveFixture::removeUser($this->user);
    }

    public function testTheUsersContextReachesPlacePhp()
    {
        $unzip = $this->extractor('unzip', 'ln -s ' . escapeshellarg($this->base . '/outside') . ' "$dest/l"');
        file_put_contents($this->base . '/in/a.zip', 'zip');
        $run = $this->script('unzip_file.sh', [$unzip, $this->base . '/in/a.zip', $this->base . '/out/']);
        $this->assertSame(1, $run['code'], 'the link is refused: ' . json_encode($run));
        $this->assertSame(['unpack: automatic unpack refused hash=' . self::HASH
            . ' reason=archive-symbolic-link entries=1'], self::lines($run['log']), 'in the user\'s own log');
        $this->assertSame('', $run['global'], 'not in the log of no user');
    }

    public function testUnallHandsBothScriptsTheirContext()
    {
        $link = 'ln -s "$outside" "$dest/l"';
        // unrar lists the link, once for each of two volumes, and creates it
        // only without -ol-.
        $unrar = $this->extractor('unrar', '[ "$1" = lt ] && printf "        Name: l\n        Type: Unix symbolic link\n%.0s" 1 2'
            . "\n" . '[ "$1" = x ] || exit 0; case " $* " in *" -ol- "*) ;; *) ' . $link . ' ;; esac');
        $unzip = $this->extractor('unzip', $link);
        file_put_contents($this->base . '/in/a.rar', 'rar');
        file_put_contents($this->base . '/in/a.zip', 'zip');
        $run = $this->script('unall_dir.sh', [$unrar, $this->base . '/in/', $this->base . '/out/', $unzip]);
        $this->assertSame(['lt', 'x'], $this->calls('unrar'), 'unrar lists, then extracts');
        $args = file($this->base . '/unrar.args', FILE_IGNORE_NEW_LINES);
        $this->assertTrue(
            isset($args[1]) && strpos(' ' . $args[1] . ' ', ' -ol- ') !== false,
            'unrar_dir.sh extracts without creating symbolic links: ' . json_encode($args),
        );
        $this->assertSame(['-o'], $this->calls('unzip'), 'unzip extracts');
        $line = 'unpack: automatic unpack refused hash=' . self::HASH . ' reason=archive-symbolic-link entries=1';
        $this->assertSame(
            [$line, $line],
            self::lines($run['log']),
            'each script logs its refusal as the user, the link listed twice counted once',
        );
        $this->assertSame(
            2,
            substr_count($run['err'], "unpack: refused archive-symbolic-link: l\n"),
            'and each names it once: ' . $run['err'],
        );
    }

    public function testLinksUnrarSkipsAreRefusedAndTheArchiveKept()
    {
        // With -ol- unrar extracts none of them, and here nothing else.
        $unrar = $this->extractor('unrar', '[ "$1" = lt ] && printf %s ' . escapeshellarg(self::LISTED_LINKS));
        file_put_contents($this->base . '/in/a.rar', 'rar');
        $run = $this->script('unrar_file.sh', [$unrar, $this->base . '/in/a.rar', $this->base . '/out/', '',
            $this->base . '/in/a.rar']);
        $this->assertSame(1, $run['code'], 'the unpack fails: ' . json_encode($run));
        $args = file($this->base . '/unrar.args', FILE_IGNORE_NEW_LINES);
        $this->assertTrue(
            isset($args[1]) && strpos(' ' . $args[1] . ' ', ' -ol- ') !== false,
            'unrar extracts without creating symbolic links: ' . json_encode($args),
        );
        $this->assertTrue(strpos($args[0], 'lt -v -p- -- ') === 0, 'every volume is listed, without a password prompt: ' . $args[0]);
        $this->assertTrue(strpos($run['err'], 'Type: Junction') === false, 'a file named like a type is not refused: ' . $run['err']);
        foreach (['lib.so', 'winlink', 'junction'] as $name) {
            $this->assertTrue(
                strpos($run['err'], "unpack: refused archive-symbolic-link: $name\n") !== false,
                'the task output names ' . $name . ': ' . $run['err'],
            );
        }
        $this->assertSame(['unpack: automatic unpack refused hash=' . self::HASH
            . ' reason=archive-symbolic-link entries=3'], self::lines($run['log']), 'the log counts them');
        $this->assertSame([], UnpackArchiveFixture::tree($this->base . '/out'), 'nothing is delivered');
        $this->assertTrue(is_file($this->base . '/in/a.rar'), 'and the archive is kept');
    }

    public function testALinkOneZipMadeIsNotWrittenThroughByTheNext()
    {
        $unzip = $this->zipsWithALinkThenAFileBelowIt();
        $run = $this->script('unzip_dir.sh', [$unzip, $this->base . '/in/', $this->base . '/out/', '',
            $this->base . '/in/a.zip;' . $this->base . '/in/b.zip;']);
        $this->assertZipLinkRefused($run, $this->base . '/out', $this->base . '/out');
    }

    public function testALinkOneZipMadeIsNotWrittenThroughByTheNextInTheTempDirectory()
    {
        $unzip = $this->zipsWithALinkThenAFileBelowIt();
        // An output that is not there yet is made.
        $run = $this->script('unzip_dir.sh', [$unzip, $this->base . '/in/', $this->base . '/out/new/', '',
            $this->base . '/in/a.zip;' . $this->base . '/in/b.zip;', $this->base . '/temp/']);
        $this->assertZipLinkRefused($run, $this->base . '/temp', $this->base . '/out/new');
        $this->assertTrue(!file_exists($this->base . '/temp'), 'the temp directory is removed');
    }

    public function testArchivesTwoDirectoriesDeepAreUnpacked()
    {
        // unzip -d makes only the last directory of the path it is given.
        $unzip = $this->extractor('unzip', '{ mkdir "$dest" 2>/dev/null || [ -d "$dest" ]; } || exit 2; echo z > "$dest/z"');
        // unrar is given each directory as "<directory>/.", rar or not, and
        // makes the whole path it extracts to.
        $unrar = $this->extractor('unrar', '[ "$1" = x ] || exit 0; for a; do case "$a" in *.) d=${a%.} ;; esac; done;'
            . ' for a in "$d"*.rar; do [ -f "$a" ] && mkdir -p "$dest" && echo r > "$dest/r"; done; exit 0');
        mkdir($this->base . '/in/Subs/eng', 0700, true);
        file_put_contents($this->base . '/in/Subs/eng/x.zip', 'zip');
        file_put_contents($this->base . '/in/Subs/eng/x.rar', 'rar');
        $run = $this->script('unall_dir.sh', [$unrar, $this->base . '/in/', $this->base . '/out/', $unzip]);
        $this->assertSame(0, $run['code'], 'the unpack succeeds: ' . json_encode($run));
        $this->assertSame('', $run['err'], 'and reports nothing');
        $this->assertSame(
            ['Subs', 'Subs/eng', 'Subs/eng/r', 'Subs/eng/z'],
            UnpackArchiveFixture::tree($this->base . '/out'),
            'the nested subdirectory is made and filled',
        );
    }

    public function testADirectoryNameEndingInANewlineKeepsIt()
    {
        // $(basename ...) would drop the newline; ${fn##*/} keeps it.
        $unzip = $this->extractor('unzip', '{ mkdir "$dest" 2>/dev/null || [ -d "$dest" ]; } || exit 2; echo z > "$dest/z"');
        $unrar = $this->unrarWritingR();
        mkdir($this->base . "/in/sub\n");
        file_put_contents($this->base . "/in/sub\n/x.zip", 'zip');
        file_put_contents($this->base . "/in/sub\n/x.rar", 'rar');
        foreach (['unzip_dir.sh' => [$unzip, "sub\n/z"], 'unrar_dir.sh' => [$unrar, "sub\n/r"]] as $script => $case) {
            $output = $this->base . '/out/' . $script;
            mkdir($output);
            $run = $this->script($script, [$case[0], $this->base . '/in/', $output . '/']);
            $this->assertSame(0, $run['code'], $script . ' succeeds: ' . json_encode($run));
            $this->assertSame(
                ["sub\n", $case[1]],
                UnpackArchiveFixture::tree($output),
                $script . ' places the directory under its own name, newline included: '
                . json_encode(UnpackArchiveFixture::tree($output)),
            );
        }
    }

    public function testUnallRunsFromADirectoryWithASpace()
    {
        $unzip = $this->extractor('unzip', '{ mkdir "$dest" 2>/dev/null || [ -d "$dest" ]; } || exit 2; echo z > "$dest/z"');
        $unrar = $this->unrarWritingR();
        file_put_contents($this->base . '/in/x.zip', 'zip');
        file_put_contents($this->base . '/in/x.rar', 'rar');
        $directory = $this->base . '/plug in';
        symlink(dirname(__DIR__, 3) . '/plugins/unpack', $directory);
        $run = $this->script('unall_dir.sh', [$unrar, $this->base . '/in/', $this->base . '/out/', $unzip], $directory);
        $this->assertSame(0, $run['code'], 'the unpack succeeds: ' . json_encode($run));
        $this->assertSame(['r', 'z'], UnpackArchiveFixture::tree($this->base . '/out'), 'both scripts are found and run');
    }

    public function testDirectoriesAnArchiveLockedArePlaced()
    {
        // A directory that cannot be written to or entered by its owner.
        $unzip = $this->extractor('unzip', 'mkdir -p "$dest/ro/sub" "$dest/shut" && echo f > "$dest/ro/sub/f"'
            . ' && echo g > "$dest/shut/g" && chmod 555 "$dest/ro/sub" "$dest/ro" && chmod 644 "$dest/shut"');
        file_put_contents($this->base . '/in/a.zip', 'zip');
        mkdir($this->base . '/out/ro');
        $run = $this->script('unzip_file.sh', [$unzip, $this->base . '/in/a.zip', $this->base . '/out/']);
        $this->assertSame(0, $run['code'], 'the unpack succeeds: ' . json_encode($run));
        $this->assertSame(
            ['ro', 'ro/sub', 'ro/sub/f', 'shut', 'shut/g'],
            UnpackArchiveFixture::tree($this->base . '/out'),
            'everything is placed',
        );
        $this->assertTrue(
            !is_executable($this->base . '/out/ro/sub/f') && !is_executable($this->base . '/out/shut/g'),
            'and a file is not made executable',
        );
    }

    public function testNamesWithSpacesNewlinesAndALeadingDashArePlaced()
    {
        $input = $this->base . "/in/-in put\nx/";
        $output = $this->base . "/out/-out put\nx/";
        mkdir($input . "-sub dir\ny", 0700, true);
        file_put_contents($input . "-a b\nc.zip", 'zip');
        file_put_contents($input . "-sub dir\ny/-d e\nf.zip", 'zip');
        // Each file is named after its archive.
        $unzip = $this->extractor('unzip', 'name=${2##*/}; echo z > "$dest/${name%.zip}"');
        $run = $this->script('unzip_dir.sh', [$unzip, $input, $output]);
        $this->assertSame(0, $run['code'], 'the unpack succeeds: ' . json_encode($run));
        $this->assertSame(
            ["-out put\nx", "-out put\nx/-a b\nc", "-out put\nx/-sub dir\ny", "-out put\nx/-sub dir\ny/-d e\nf"],
            UnpackArchiveFixture::tree($this->base . '/out'),
            'every name lands as it is',
        );
        $this->assertSame('', $run['err'], 'and nothing is reported');
        $this->assertSame('', $run['log'], 'or logged');
    }

    public function testZipArchivePatternsKeepLiteralNames()
    {
        $input = $this->base . '/in/parent*[x]/';
        mkdir($input);
        foreach (["Al [1].zip" => "Al [[]1].zip", "x*y.zip" => "x[*]y.zip",
            "q?r.zip" => "q[?]r.zip", "a\\b*.zip" => "a\\\\b[*].zip",
            "обычный file.zip" => "обычный file.zip", "a\\b.zip" => "a\\b.zip", "trailing*\n" => "trailing[*]\n"] as $name => $pattern) {
            file_put_contents($input . $name, 'archive');
            $unzip = $this->extractor('unzip', '[ "$2" = '
                . Utility::escapeShellArg($input . $pattern) . ' ] || exit 5; echo true > "$dest/true"');
            $run = $this->script('unzip_file.sh', [$unzip, $input . $name, $this->base . '/out/']);
            $this->assertEquals(0, $run['code'], 'the literal archive is selected: ' . json_encode($run));
            $this->assertEquals("true\n", file_get_contents($this->base . '/out/true'), 'the selected archive is placed');
            unlink($this->base . '/out/true');
            unlink($input . $name);
        }
    }

    public function testRelativeZipNameIsEscaped()
    {
        file_put_contents($this->base . '/x*y.zip', 'zip');
        $unzip = $this->extractor('unzip', '[ "$2" = "x[*]y.zip" ] || exit 5; echo true > "$dest/true"');
        $run = $this->script('unzip_file.sh', [$unzip, 'x*y.zip', $this->base . '/out/']);
        $this->assertEquals(0, $run['code'], 'a relative name is escaped: ' . json_encode($run));
        $this->assertEquals("true\n", file_get_contents($this->base . '/out/true'), 'the archive is placed');
    }

    public function testZipDirectoryEscapesArchiveNames()
    {
        $input = $this->base . '/in/';
        file_put_contents($input . 'x*y.zip', 'zip');
        $unzip = $this->extractor('unzip', '[ "$2" = '
            . Utility::escapeShellArg($input . 'x[*]y.zip') . ' ] || exit 5; echo true > "$dest/true"');
        $run = $this->script('unzip_dir.sh', [$unzip, $input, $this->base . '/out/']);
        $this->assertEquals(0, $run['code'], 'directory mode selects the literal archive: ' . json_encode($run));
        $this->assertEquals("true\n", file_get_contents($this->base . '/out/true'), 'the selected archive is placed');
    }

    public function testRarWildcardPathsRefuseBeforeCallingUnrar()
    {
        $unrar = $this->extractor('unrar', 'exit 0');
        foreach (['x*y.rar', 'q?r.rar', 'parent*/safe.rar'] as $name) {
            if (strpos($name, '/') !== false) {
                mkdir($this->base . '/in/parent*');
            }
            file_put_contents($this->base . '/in/' . $name, 'rar');
            @unlink($this->base . '/errors.log');
            $run = $this->script(
                'unrar_file.sh',
                [$unrar, $this->base . '/in/' . $name, $this->base . '/out/', '',
                    [$this->base . '/in/' . $name]],
            );
            $this->assertEquals(1, $run['code'], 'a wildcard path is refused: ' . json_encode($run));
            $this->assertTrue(strpos($run['err'], 'rename') !== false, 'the task output asks for a rename');
            $this->assertTrue(strpos($run['log'], 'reason=archive-name-pattern') !== false, 'the log classifies the refusal');
            $this->assertTrue($this->calls('unrar') === [], 'unrar is never called');
            $this->assertTrue(is_file($this->base . '/in/' . $name), 'the refused archive is kept');
            unlink($this->base . '/in/' . $name);
        }
        $run = $this->script('unrar_dir.sh', [$unrar, $this->base . '/in/parent*/', $this->base . '/out/']);
        $this->assertEquals(1, $run['code'], 'a wildcard directory is refused');
        $this->assertTrue($this->calls('unrar') === [], 'the directory is not listed or extracted');
    }

    public function testZipPatternConversionFailureKeepsArchive()
    {
        $unzip = $this->extractor('unzip', 'exit 0');
        file_put_contents($this->base . '/in/x*y.zip', 'zip');
        file_put_contents($this->base . '/sed', "#!/bin/sh\nprintf partial\nexit 1\n");
        chmod($this->base . '/sed', 0700);
        $oldPath = getenv('PATH');
        putenv('PATH=' . $this->base . ':' . $oldPath);
        try {
            $run = $this->script('unzip_file.sh', [$unzip, $this->base . '/in/x*y.zip', $this->base . '/out/', '',
                [$this->base . '/in/x*y.zip']]);
            $dirRun = $this->script('unzip_dir.sh', [$unzip, $this->base . '/in/', $this->base . '/out/', '',
                [$this->base . '/in/x*y.zip']]);
        } finally {
            putenv('PATH=' . $oldPath);
        }
        $this->assertSame(1, $run['code'], 'conversion failure refuses the unpack');
        $this->assertSame(1, $dirRun['code'], 'conversion failure refuses the directory unpack');
        $this->assertSame([], $this->calls('unzip'), 'unzip is not called with partial output');
        $this->assertTrue(is_file($this->base . '/in/x*y.zip'), 'the archive is kept');
        $this->assertTrue(strpos($run['log'], 'reason=archive-name-unavailable') !== false, 'the log classifies the failure');
    }

    public function testMixedUnpackKeepsBothArchivesWhenRarPathIsRefused()
    {
        $dir = $this->base . '/in/parent*/';
        mkdir($dir);
        file_put_contents($dir . 'a.rar', 'rar');
        file_put_contents($dir . 'a.zip', 'zip');
        $unrar = $this->extractor('unrar', 'exit 0');
        $unzip = $this->extractor('unzip', 'echo z > "$dest/z"');
        $run = $this->script('unall_dir.sh', [$unrar, $dir, $this->base . '/out/', $unzip,
            [$dir . 'a.rar', $dir . 'a.zip']]);
        $this->assertEquals(1, $run['code'], 'the mixed unpack is refused');
        $this->assertTrue(is_file($dir . 'a.rar') && is_file($dir . 'a.zip'), 'both archives are kept');
    }

    public function testMixedUnpackKeepsBothArchivesWhenZipPatternFails()
    {
        $dir = $this->base . '/in/';
        file_put_contents($dir . 'a.rar', 'rar');
        file_put_contents($dir . 'x*y.zip', 'zip');
        $unrar = $this->extractor('unrar', 'exit 0');
        $unzip = $this->extractor('unzip', 'echo z > "$dest/z"');
        file_put_contents($this->base . '/sed', "#!/bin/sh\nexit 1\n");
        chmod($this->base . '/sed', 0700);
        $oldPath = getenv('PATH');
        putenv('PATH=' . $this->base . ':' . $oldPath);
        try {
            $run = $this->script('unall_dir.sh', [$unrar, $dir, $this->base . '/out/', $unzip,
                [$dir . 'a.rar', $dir . 'x*y.zip']]);
        } finally {
            putenv('PATH=' . $oldPath);
        }
        $this->assertEquals(1, $run['code'], 'the mixed unpack is refused');
        $this->assertTrue(is_file($dir . 'a.rar') && is_file($dir . 'x*y.zip'), 'both archives are kept');
    }

    public function testMixedUnpackDeletesOnlyTheListedArchivesAfterBothSucceed()
    {
        $dir = $this->base . '/in/';
        file_put_contents($dir . 'a.rar', 'rar');
        file_put_contents($dir . 'x*y.zip', 'zip');
        file_put_contents($dir . 'xzy.zip', 'unrelated');
        $unrar = $this->extractor('unrar', 'exit 0');
        $unzip = $this->extractor('unzip', 'exit 0');
        $run = $this->script('unall_dir.sh', [$unrar, $dir, $this->base . '/out/', $unzip,
            [$dir . 'a.rar', $dir . 'x*y.zip']]);
        $this->assertEquals(0, $run['code'], 'both extractors succeed');
        $this->assertTrue(!file_exists($dir . 'a.rar') && !file_exists($dir . 'x*y.zip'), 'listed archives are deleted');
        $this->assertTrue(is_file($dir . 'xzy.zip'), 'an unlisted matching path is kept');
    }

    public function testDirectoryCleanupDoesNotExpandArchiveNames()
    {
        foreach (['zip', 'rar'] as $format) {
            $dir = $this->base . '/in/' . (($format === 'zip') ? 'zip*' : 'rar') . '/';
            mkdir($dir);
            file_put_contents($dir . 'x*y.' . $format, 'archive');
            file_put_contents($dir . 'xzy.' . $format, 'decoy');
            $tool = $this->extractor('un' . $format, 'exit 0');
            $run = $this->script('un' . $format . '_dir.sh', [$tool, $dir, $this->base . '/out/', '',
                [$dir . 'x*y.' . $format]]);
            $this->assertEquals(0, $run['code'], 'the directory unpack succeeds');
            $this->assertTrue(!file_exists($dir . 'x*y.' . $format), 'the selected archive is removed');
            $this->assertTrue(is_file($dir . 'xzy.' . $format), 'the unrelated archive remains');
        }
    }

    public function testAStalePrivateDirectoryIsReportedOnceAndKept()
    {
        // No process has number 999999999; this test's own is alive.
        $stale = $this->base . '/out/.rutorrent-unpack.999999999.dead00';
        $live = $this->base . '/out/.rutorrent-unpack.' . getmypid() . '.live00';
        mkdir($stale);
        file_put_contents($stale . '/partial', 'p');
        mkdir($live);
        $unzip = $this->extractor('unzip', 'echo f > "$dest/$(basename "$2")"');
        file_put_contents($this->base . '/in/a.zip', 'zip');
        file_put_contents($this->base . '/in/b.zip', 'zip');
        $run = $this->script('unzip_dir.sh', [$unzip, $this->base . '/in/', $this->base . '/out/']);
        $this->assertSame(0, $run['code'], 'the unpack itself succeeds: ' . json_encode($run));
        $this->assertSame(
            1,
            substr_count($run['err'], 'unpack: found stale-private-directory: '),
            'one report for the run: ' . $run['err'],
        );
        $this->assertTrue(
            strpos($run['err'], "unpack: found stale-private-directory: $stale\n") !== false,
            'naming the directory whose process is gone: ' . $run['err'],
        );
        $this->assertSame(['unpack: automatic unpack found hash=' . self::HASH
            . ' reason=stale-private-directory entries=1'], self::lines($run['log']), 'and it is logged');
        $this->assertTrue(is_file($stale . '/partial') && is_dir($live), 'neither directory is removed');
    }

    public function testTheUserArgumentIsNotTreatedAsAnArchive()
    {
        $archive = $this->base . '/in/a.zip';
        $userFile = $this->base . '/' . $this->user;
        file_put_contents($archive, 'zip');
        file_put_contents($userFile, 'user file');
        $unzip = $this->extractor('unzip', 'exit 0');
        $run = $this->script('unzip_file.sh', [$unzip, $archive, $this->base . '/out/', '', [$archive]]);
        $this->assertSame(0, $run['code'], 'the unpack succeeds: ' . json_encode($run));
        $this->assertTrue(!file_exists($archive), 'the listed archive is deleted');
        $this->assertSame('user file', @file_get_contents($userFile), 'the user argument is not a deletion target');
    }

    public function testShortInvocationRefusesWithoutCallingRm()
    {
        $rm = $this->base . '/rm';
        file_put_contents($rm, "#!/bin/sh\necho called > " . escapeshellarg($this->base . '/rm.called') . "\n");
        chmod($rm, 0700);
        $unrar = $this->extractor('unrar', 'exit 0');
        $script = dirname(__DIR__, 3) . '/plugins/unpack/unrar_file.sh';
        $process = proc_open(
            [$script, $unrar, 'archive', 'output', '', '', '', PHP_BINARY, 'manual', self::HASH],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->base,
            array_merge(getenv(), ['PATH' => $this->base . ':' . getenv('PATH')]),
        );
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(1, proc_close($process), 'short invocation fails: ' . $out . $err);
        $this->assertTrue(strpos($err, 'fewer than 10 arguments') !== false, 'refusal is visible');
        $this->assertTrue(!file_exists($this->base . '/rm.called'), 'rm is never called');
    }

    public function testFailedUnrarKeepsListedArchives()
    {
        $archive = $this->base . '/in/a.rar';
        file_put_contents($archive, 'archive');
        $unrar = $this->extractor('unrar', '[ "$1" = lt ] && exit 0; exit 2');
        foreach (['unrar_file.sh' => $archive, 'unrar_dir.sh' => $this->base . '/in/'] as $script => $input) {
            $run = $this->script($script, [$unrar, $input, $this->base . '/out/', '', [$archive]]);
            clearstatcache();
            $this->assertSame(2, $run['code'], $script . ' reports extraction failure');
            $this->assertTrue(is_file($archive), $script . ' keeps the archive');
        }
    }

    public function testNothingIsDeletedWhenNoArchiveIsListed()
    {
        file_put_contents($this->base . '/in/a.zip', 'zip');
        $unzip = $this->extractor('unzip', 'exit 0');
        foreach (['unzip_file.sh' => $this->base . '/in/a.zip', 'unzip_dir.sh' => $this->base . '/in/'] as $script => $input) {
            $run = $this->script($script, [$unzip, $input, $this->base . '/out/']);
            $this->assertTrue($run['code'] === 0 && $run['err'] === '', $script . ' succeeds quietly: ' . json_encode($run));
            $this->assertTrue(is_file($this->base . '/in/a.zip'), 'and keeps the archive');
        }
    }

    public function testAPrivateDirectoryThatCannotBeMadeIsRefusedAndLogged()
    {
        $unzip = $this->extractor('unzip', 'echo f > "$dest/f"');
        file_put_contents($this->base . '/in/a.zip', 'zip');
        chmod($this->base . '/out', 0500);
        $this->assertTrue(!is_writable($this->base . '/out'), 'precondition: not run as root');
        $run = $this->script('unzip_file.sh', [$unzip, $this->base . '/in/a.zip', $this->base . '/out/']);
        $this->assertSame(1, $run['code'], 'the unpack fails: ' . json_encode($run));
        $this->assertSame([], $this->calls('unzip'), 'without starting the extractor');
        $this->assertTrue(strpos($run['err'], 'unpack: refused private-directory-unavailable: '
            . $this->base . "/out/\n") !== false, 'the task output says why: ' . $run['err']);
        $this->assertSame(['unpack: automatic unpack refused hash=' . self::HASH
            . ' reason=private-directory-unavailable entries=1'], self::lines($run['log']), 'and so does the log');
    }

    /**
     * An extractor that records each call, its arguments and the directory
     * it was given, and runs $body with $dest, its last argument, that
     * directory, $1 the command ("x" or "lt" for unrar) and $outside the
     * directory beside the output that nothing may reach.
     */
    private function extractor($name, $body)
    {
        $path = $this->base . '/' . $name;
        file_put_contents($path, "#!/bin/sh\nfor dest; do :; done\noutside=\"\$(dirname \"\$0\")/outside\"\n"
            . "echo \"\$1\" >> \"\$0.calls\"\necho \"\$*\" >> \"\$0.args\"\necho \"\$dest\" >> \"\$0.dest\"\n"
            . $body . "\n");
        chmod($path, 0700);
        return $path;
    }

    /** What extractor $name recorded of its calls: "calls", "args" or "dest". */
    private function calls($name, $what = 'calls')
    {
        $calls = $this->base . '/' . $name . '.' . $what;
        return is_file($calls) ? file($calls, FILE_IGNORE_NEW_LINES) : [];
    }

    /**
     * Runs plugins/unpack/$script as unpack.php does, with $this->user, from
     * $directory when it is given.
     */
    private function script($script, array $arguments, $directory = null)
    {
        $arguments = array_pad($arguments, 6, '');
        $archives = is_array($arguments[4]) ? $arguments[4] : [];
        $arguments[4] = '';
        array_push($arguments, PHP_BINARY, 'automatic', self::HASH, $this->user);
        $arguments = array_merge($arguments, $archives);
        $directory = ($directory === null) ? dirname(__DIR__, 3) . '/plugins/unpack' : $directory;
        $process = proc_open(
            array_merge([$directory . '/' . $script], $arguments),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->base,
            array_merge(getenv(), ['RU_PROFILE_PATH' => $this->base . '/profile',
                'RU_LOG_FILE' => $this->base . '/errors.log']),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('cannot start ' . $script);
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        return ['code' => $code, 'out' => $out, 'err' => $err,
            'log' => (string) @file_get_contents($this->base . '/user.log'),
            'global' => (string) @file_get_contents($this->base . '/errors.log')];
    }

    /** The application log's lines without their times. */
    private static function lines($log)
    {
        return array_values(array_filter(array_map(function ($line) {
            return preg_replace('/^\[[^\]]*\] /', '', $line);
        }, explode("\n", $log)), 'strlen'));
    }

    /**
     * The first zip makes d -> outside; the second has d/pwn.txt, and unzip
     * writes it through that link.
     */
    private function zipsWithALinkThenAFileBelowIt()
    {
        file_put_contents($this->base . '/in/a.zip', 'zip');
        file_put_contents($this->base . '/in/b.zip', 'zip');
        return $this->extractor('unzip', 'case "$2" in'
            . ' *a.zip) ln -s "$outside" "$dest/d" ;;'
            . ' *b.zip) mkdir -p "$dest/d" && echo PWN > "$dest/d/pwn.txt" ;; esac');
    }

    /** $root holds the private directories, $output gets what is placed. */
    private function assertZipLinkRefused(array $run, $root, $output)
    {
        $private = $this->calls('unzip', 'dest');
        $this->assertTrue(
            count($private) === 2 && count(array_unique($private)) === 2 && count(array_filter(
                $private,
                function ($dest) use ($root) {
                    return strpos($dest, $root . '/.rutorrent-unpack.') === 0;
                },
            )) === 2,
            'each zip is unpacked into a private directory of its own in ' . $root . ': ' . json_encode($private),
        );
        $this->assertSame(
            [],
            UnpackArchiveFixture::tree($this->base . '/outside'),
            'nothing is written outside: ' . json_encode($run),
        );
        $this->assertSame(
            ['d', 'd/pwn.txt'],
            is_dir($output) ? UnpackArchiveFixture::tree($output) : null,
            'the second zip is unpacked into the output, the link of the first is not',
        );
        $this->assertSame(1, $run['code'], 'the unpack fails');
        $this->assertTrue(
            strpos($run['err'], "unpack: refused archive-symbolic-link: d\n") !== false,
            'the task output names the link: ' . $run['err'],
        );
        $this->assertSame(['unpack: automatic unpack refused hash=' . self::HASH
            . ' reason=archive-symbolic-link entries=1'], self::lines($run['log']), 'the log has the refusal');
        $this->assertTrue(
            is_file($this->base . '/in/a.zip') && is_file($this->base . '/in/b.zip'),
            'and the archives are kept',
        );
    }

    /** unrar is given each directory as "<directory>/.", and makes the whole path it extracts to. */
    private function unrarWritingR()
    {
        return $this->extractor('unrar', '[ "$1" = x ] || exit 0; for a; do case "$a" in *.) d=${a%.} ;; esac; done;'
            . ' for a in "$d"*.rar; do [ -f "$a" ] && mkdir -p "$dest" && echo r > "$dest/r"; done; exit 0');
    }
}
