<?php
require_once(__DIR__ . '/TestCase.php');

class SourceExportActionTest extends TestCase
{
    private $directory;
    private const INFO_HASH = '2E1B01E70020BA7C1BC960ADC9724B19C5D80D18';

    public function setUp()
    {
        $this->directory = sys_get_temp_dir() . '/rt-source-export-' . bin2hex(random_bytes(5));
        mkdir($this->directory, 0700, true);
    }

    public function tearDown()
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry)
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->directory);
    }

    // The child runs with -n so the real zip extension cannot declare ZipArchive
    // before the fixture's double does. PHP 7.4 builds that ship json as a shared
    // module (the GitHub runner's does) lose it with the ini files, so it is
    // loaded back by name; builds with json compiled in need nothing.
    private static function isolatedPhp()
    {
        static $command = null;
        if ($command === null) {
            $command = array(PHP_BINARY, '-n');
            $probe = proc_open(array(PHP_BINARY, '-n', '-r',
                'exit(function_exists("json_encode") ? 0 : 1);'),
                array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
            if (!is_resource($probe)) throw new RuntimeException('PHP probe did not start');
            if (proc_close($probe) !== 0) {
                $command[] = '-d';
                $command[] = 'extension=json';
            }
        }
        return $command;
    }

    private function request($path, $route = 'source', $dumpArguments = null, $rawMetadataArguments = null, $secondPath = null, $revalidatedPath = null, $zipCase = null)
    {
        $env = array('SOURCE_EXPORT_SCRATCH' => $this->directory,
            'SOURCE_EXPORT_PATH' => $path,
            'SOURCE_EXPORT_HASH' => self::INFO_HASH,
            'SOURCE_EXPORT_ROUTE' => $route, 'RU_PROFILE_PATH' => $this->directory . '/profile',
            'SOURCE_EXPORT_LOG' => $this->directory . '/source.log');
        if ($dumpArguments !== null) $env['SOURCE_EXPORT_DUMP_ARGS'] = $dumpArguments;
        if ($rawMetadataArguments !== null) $env['SOURCE_EXPORT_RAW_ARGS'] = $rawMetadataArguments;
        if ($secondPath !== null) $env['SOURCE_EXPORT_SECOND_PATH'] = $secondPath;
        if ($revalidatedPath !== null) $env['SOURCE_EXPORT_REVALIDATED_PATH'] = $revalidatedPath;
        if ($zipCase !== null) $env['SOURCE_EXPORT_CASE'] = $zipCase;
        $process = proc_open(array_merge(self::isolatedPhp(), array('-d', 'display_errors=stderr',
            __DIR__ . '/SourceExportFixture.php')),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes, null, $env);
        if (!is_resource($process)) throw new RuntimeException('source export fixture did not start');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return array(proc_close($process), $out, $err);
    }

    public function testMultiSourceZipWrapsValidatedRawMagnetMetadata()
    {
        $raw = 'd6:lengthi1e4:name8:seed.bin12:piece lengthi16384e6:pieces20:'
            . str_repeat("\0", 20) . 'e';
        $path = $this->directory . '/' . self::INFO_HASH . '.meta';
        file_put_contents($path, $raw);
        list($status, $out, $err) = $this->request($path);
        $this->assertSame(0, $status, 'source export exits cleanly: ' . $err);
        $this->assertSame('', $err, 'source export has no PHP warnings');
        $archive = json_decode($out, true);
        $this->assertTrue(is_array($archive), 'source ZIP fixture captures entries: ' . $out);
        $this->assertSame(array('seed.bin-1.torrent'), array_keys($archive),
            'metadata source is named after torrent content');
        $encoded = is_array($archive) && count($archive) ? reset($archive) : '';
        $bytes = base64_decode($encoded, true);
        $this->assertSame('d4:info' . $raw . 'e', $bytes,
            'archive carries the validated info dictionary in a full metainfo envelope');
    }

    public function testMultiSourceZipKeepsOrdinaryMetainfoFileBytes()
    {
        $rawInfo = 'd6:lengthi1e4:name8:seed.bin12:piece lengthi16384e6:pieces20:'
            . str_repeat("\0", 20) . 'e';
        $bytes = 'd8:announce31:http://tracker.invalid/announce4:info' . $rawInfo . 'e';
        $path = $this->directory . '/' . self::INFO_HASH . '.torrent';
        file_put_contents($path, $bytes);
        list($status, $out, $err) = $this->request($path);
        $this->assertSame(0, $status, 'ordinary source export exits cleanly: ' . $err);
        $this->assertSame('', $err, 'ordinary source export emits no warning');
        $archive = json_decode($out, true);
        $this->assertSame(array('seed.bin-1.torrent'), array_keys($archive),
            'ordinary source retains the torrent-content name');
        $this->assertSame($bytes, base64_decode($archive['seed.bin-1.torrent'], true),
            'ordinary source ZIP preserves the original full metainfo bytes');
    }

    public function testMultiSourceZipLogsRejectedMetadataEntry()
    {
        $raw = 'd6:lengthi1e4:name8:seed.bin12:piece lengthi16384e6:pieces20:'
            . str_repeat("\0", 20) . 'e';
        $path = $this->directory . '/' . self::INFO_HASH . '.meta';
        file_put_contents($path, $raw);
        $rejected = $this->directory . '/' . str_repeat('A', 40) . '.meta';
        file_put_contents($rejected, 'invalid metadata bytes');
        list($status, $out, $err) = $this->request($path, 'source', null, null, $rejected);
        $this->assertSame(0, $status, 'partial source ZIP exits cleanly: ' . $err);
        $this->assertSame('', $err, 'partial source ZIP emits no warning');
        $archive = json_decode($out, true);
        $this->assertSame(array('seed.bin-1.torrent'), array_keys($archive),
            'a malformed source does not enter the ZIP');
        $log = file_get_contents($this->directory . '/source.log');
        $this->assertTrue(strpos($log, 'source: ZIP entry refused: ' . str_repeat('A', 40)
            . ' source-invalid') !== false, 'the omitted hash and reason are visible in the journal');
    }

    public function testMultiSourceZipReportsEveryRejectionWhenNoFilesRemain()
    {
        $path = $this->directory . '/' . self::INFO_HASH . '.meta';
        file_put_contents($path, 'invalid metadata bytes');
        list($status, $out, $err) = $this->request($path);
        $this->assertSame(0, $status, 'empty source ZIP fallback exits cleanly: ' . $err);
        $this->assertSame('', $err, 'empty source ZIP fallback emits no warning');
        $this->assertSame('', $out, 'all-rejected path does not emit a ZIP');
        $log = file_get_contents($this->directory . '/source.log');
        $this->assertTrue(strpos($log, 'source: ZIP entry refused: ' . self::INFO_HASH
            . ' source-invalid') !== false, 'malformed metadata is named in the journal');
        $this->assertTrue(strpos($log, 'source: ZIP entry refused: ' . str_repeat('A', 40)
            . ' source-unavailable') !== false, 'unavailable source is named in the journal');
    }

    public function testOrdinarySourceZipWriteAndSendFailuresAreVisibleAndCleanedUp()
    {
        $rawInfo = 'd6:lengthi1e4:name8:seed.bin12:piece lengthi16384e6:pieces20:'
            . str_repeat("\0", 20) . 'e';
        $bytes = 'd8:announce31:http://tracker.invalid/announce4:info' . $rawInfo . 'e';
        $path = $this->directory . '/' . self::INFO_HASH . '.torrent';
        foreach (array('open' => 'open-failed', 'add-file' => 'add-file-failed',
            'close' => 'close-failed', 'send' => 'send-failed') as $mode => $reason) {
            file_put_contents($path, $bytes);
            @unlink($this->directory . '/send.called');
            list($status, $out, $err) = $this->request($path, 'source', null, null, null, null, $mode);
            $this->assertSame(0, $status, $mode . ' refusal exits without fatal: ' . $err);
            $this->assertSame('', $err, $mode . ' refusal emits no PHP warning');
            $this->assertSame('noty("Could not export source ZIP archive.","error");', $out,
                $mode . ' refusal is visible without a partial download');
            $log = file_get_contents($this->directory . '/source.log');
            $this->assertTrue(strpos($log, 'source: ZIP export failed: ' . $reason) !== false,
                $mode . ' refusal has a classified journal reason');
            $this->assertSame(array(), glob($this->directory . '/zip-temp/*.zip'),
                $mode . ' refusal removes the partial ZIP');
            $this->assertSame($mode === 'send', file_exists($this->directory . '/send.called'),
                $mode . ' sends only after a complete archive');
        }
    }

    public function testRawMetadataZipAddFailureIsVisibleAndCleanedUp()
    {
        $raw = 'd6:lengthi1e4:name8:seed.bin12:piece lengthi16384e6:pieces20:'
            . str_repeat("\0", 20) . 'e';
        $path = $this->directory . '/' . self::INFO_HASH . '.meta';
        file_put_contents($path, $raw);
        list($status, $out, $err) = $this->request($path, 'source', null, null, null, null, 'add-string');
        $this->assertSame(0, $status, 'raw metadata add refusal exits without fatal: ' . $err);
        $this->assertSame('', $err, 'raw metadata add refusal emits no warning');
        $this->assertSame('noty("Could not export source ZIP archive.","error");', $out,
            'raw metadata add refusal is visible without partial output');
        $log = file_get_contents($this->directory . '/source.log');
        $this->assertTrue(strpos($log, 'source: ZIP export failed: add-string-failed') !== false,
            'raw metadata add refusal has a classified journal reason');
        $this->assertSame(array(), glob($this->directory . '/zip-temp/*.zip'),
            'raw metadata add refusal removes the partial ZIP');
        $this->assertTrue(!file_exists($this->directory . '/send.called'),
            'raw metadata add refusal never starts a download');
    }

    public function testRequestConditionalsCannotSuppressFreshSourceZip()
    {
        $rawInfo = 'd6:lengthi1e4:name8:seed.bin12:piece lengthi16384e6:pieces20:'
            . str_repeat("\0", 20) . 'e';
        $bytes = 'd8:announce31:http://tracker.invalid/announce4:info' . $rawInfo . 'e';
        $path = $this->directory . '/' . self::INFO_HASH . '.torrent';
        file_put_contents($path, $bytes);
        foreach (array('conditional', 'range') as $mode) {
            list($status, $out, $err) = $this->request($path, 'source', null, null, null, null, $mode);
            $this->assertSame(0, $status, $mode . ' request exits cleanly: ' . $err);
            $this->assertSame('', $err, $mode . ' request emits no warning');
            $archive = json_decode($out, true);
            $this->assertSame($bytes, is_array($archive) && isset($archive['seed.bin-1.torrent'])
                ? base64_decode($archive['seed.bin-1.torrent'], true) : null,
                $mode . ' request receives the complete new ZIP entry');
            $this->assertSame(array(), glob($this->directory . '/zip-temp/*.zip'),
                $mode . ' request cleans up the completed ZIP');
        }
    }

    public function testDumpKeepsRawSourcePathForItsExternalTask()
    {
        $raw = 'd6:lengthi1e4:name8:seed.bin12:piece lengthi16384e6:pieces20:'
            . str_repeat("\0", 20) . 'e';
        $path = $this->directory . '/' . self::INFO_HASH . '.meta';
        file_put_contents($path, $raw);
        list($status, $out, $err) = $this->request($path, 'dump');
        $this->assertSame(0, $status, 'dump action exits cleanly: ' . $err);
        $this->assertSame('', $err, 'dump action emits no warning');
        $result = json_decode($out, true);
        $this->assertTrue(is_array($result) && isset($result['commands'][0]),
            'dump starts its external task');
        $this->assertTrue(strpos($result['commands'][0], escapeshellarg($path)) !== false,
            'dump keeps the original raw path for its external inspector');
        $this->assertTrue(strpos($result['commands'][0], ' -d ') !== false,
            'raw magnet metadata uses the inspector mode that does not require announce');
    }

    public function testDumpPreservesVerboseModeForOrdinaryTorrent()
    {
        $path = $this->directory . '/' . self::INFO_HASH . '.torrent';
        file_put_contents($path, 'ordinary metainfo bytes');
        list($status, $out, $err) = $this->request($path, 'dump', '-v -w 4');
        $this->assertSame(0, $status, 'ordinary dump action exits cleanly: ' . $err);
        $this->assertSame('', $err, 'ordinary dump action emits no warning');
        $result = json_decode($out, true);
        $this->assertTrue(isset($result['commands'][0]), 'ordinary dump starts its external task');
        $this->assertTrue(strpos($result['commands'][0], ' -v -w 4 ') !== false,
            'ordinary metainfo retains all configured inspector arguments');
    }

    public function testDumpPreservesAdditionalConfiguredArgumentsForRawMetadata()
    {
        $raw = 'd6:lengthi1e4:name8:seed.bin12:piece lengthi16384e6:pieces20:'
            . str_repeat("\0", 20) . 'e';
        $path = $this->directory . '/' . self::INFO_HASH . '.meta';
        file_put_contents($path, $raw);
        list($status, $out, $err) = $this->request($path, 'dump', '-v -w 4');
        $this->assertSame(0, $status, 'configured raw dump exits cleanly: ' . $err);
        $this->assertSame('', $err, 'configured raw dump emits no warning');
        $result = json_decode($out, true);
        $this->assertTrue(isset($result['commands'][0]), 'configured raw dump starts');
        $this->assertTrue(strpos($result['commands'][0], ' -d -w 4 ') !== false,
            'raw mode replaces only the verbose flag and retains other configured flags');
    }

    public function testDumpAddsRawModeWhenConfiguredArgumentsHaveNoVerboseFlag()
    {
        $raw = 'd6:lengthi1e4:name8:seed.bin12:piece lengthi16384e6:pieces20:'
            . str_repeat("\0", 20) . 'e';
        $path = $this->directory . '/' . self::INFO_HASH . '.meta';
        file_put_contents($path, $raw);
        list($status, $out, $err) = $this->request($path, 'dump', '-w 4');
        $this->assertSame(0, $status, 'nonverbose raw dump exits cleanly: ' . $err);
        $this->assertSame('', $err, 'nonverbose raw dump emits no warning');
        $result = json_decode($out, true);
        $this->assertTrue(isset($result['commands'][0]), 'nonverbose raw dump starts');
        $this->assertTrue(strpos($result['commands'][0], ' -w 4 -d ') !== false,
            'raw mode is appended when no standalone verbose flag exists');
    }

    public function testDumpAcceptsExplicitRawArgumentsForAlternateInspector()
    {
        $raw = 'd6:lengthi1e4:name8:seed.bin12:piece lengthi16384e6:pieces20:'
            . str_repeat("\0", 20) . 'e';
        $path = $this->directory . '/' . self::INFO_HASH . '.meta';
        file_put_contents($path, $raw);
        list($status, $out, $err) = $this->request($path, 'dump', '-v -w 4', '--inspect-raw');
        $this->assertSame(0, $status, 'alternate raw dump exits cleanly: ' . $err);
        $this->assertSame('', $err, 'alternate raw dump emits no warning');
        $result = json_decode($out, true);
        $this->assertTrue(isset($result['commands'][0]), 'alternate raw dump starts');
        $this->assertTrue(strpos($result['commands'][0], ' --inspect-raw ') !== false,
            'explicit raw arguments replace the dumptorrent-specific mode');
    }

    public function testDumpInspectsThePathItActuallyValidated()
    {
        $raw = 'd6:lengthi1e4:name8:seed.bin12:piece lengthi16384e6:pieces20:'
            . str_repeat("\0", 20) . 'e';
        $first = $this->directory . '/' . self::INFO_HASH . '.meta';
        file_put_contents($first, $raw);
        $secondDirectory = $this->directory . '/new-session';
        mkdir($secondDirectory, 0700);
        $second = $secondDirectory . '/' . self::INFO_HASH . '.meta';
        file_put_contents($second, $raw);
        list($status, $out, $err) = $this->request($first, 'dump', null, null, null, $second);
        $this->assertSame(0, $status, 'changing-source dump exits cleanly: ' . $err);
        $this->assertSame('', $err, 'changing-source dump emits no warning');
        $result = json_decode($out, true);
        $this->assertTrue(isset($result['commands'][0]), 'changing-source dump starts');
        $this->assertTrue(strpos($result['commands'][0], escapeshellarg($second)) !== false,
            'the task inspects the source returned by the validating lookup');
        $this->assertTrue(strpos($result['commands'][0], escapeshellarg($first)) === false,
            'the earlier unvalidated path is not inspected');
    }

    public function testDumpRefusesUnavailableSourceWithClassifiedLog()
    {
        $path = $this->directory . '/missing.torrent';
        list($status, $out, $err) = $this->request($path, 'dump');
        $this->assertSame(0, $status, 'unavailable dump exits cleanly: ' . $err);
        $this->assertSame('', $err, 'unavailable dump emits no warning');
        $result = json_decode($out, true);
        $this->assertSame(255, $result['status'], 'unavailable source does not start a task');
        $this->assertSame(array('Source torrent is unavailable'), $result['errors'],
            'unavailable-source refusal is visible to the requester');
        $log = file_get_contents($this->directory . '/source.log');
        $this->assertTrue(strpos($log, 'dump: source refused: ' . self::INFO_HASH
            . ' source-unavailable') !== false, 'unavailable-source refusal reaches the journal');
    }

    public function testDumpRefusesUnverifiedRawMetadata()
    {
        $path = $this->directory . '/' . self::INFO_HASH . '.meta';
        file_put_contents($path, 'invalid metadata bytes');
        list($status, $out, $err) = $this->request($path, 'dump');
        $this->assertSame(0, $status, 'invalid dump action exits cleanly: ' . $err);
        $this->assertSame('', $err, 'invalid dump action emits no warning');
        $result = json_decode($out, true);
        $this->assertSame(255, $result['status'], 'invalid raw metadata is refused before task start');
        $this->assertSame(array('Magnet metadata is missing or invalid'), $result['errors'],
            'invalid raw metadata refusal explains why the task did not start');
        $log = file_get_contents($this->directory . '/source.log');
        $this->assertTrue(strpos($log, 'dump: source refused: ' . self::INFO_HASH
            . ' source-invalid') !== false, 'invalid raw metadata refusal reaches the journal');
    }

}
