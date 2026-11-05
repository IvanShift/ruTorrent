<?php

require_once __DIR__ . '/../../php/TestCase.php';
require_once __DIR__ . '/CollectorFixture.php';
require_once __DIR__ . '/../../../plugins/erasedata/removewithdata.php';

class ErasedataPhysicalPathsTest extends TestCase
{
    public const HASH = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    public function setUp()
    {
        rXMLRPCRequest::$responses = [];
        rXMLRPCRequest::$requested = [];
        rXMLRPCRequest::$commandCalls = [];
    }

    public function testSelectedUtf8PathsArePhysicalForOpenAndClosedDownloads()
    {
        $legacy = "episode \xF1\xE5\xF0\xE8\xFF.mkv";
        $utf8 = 'episode серия.mkv';
        foreach ([false, true] as $open) {
            $this->fixture(true, [['path' => [$legacy],
                'path.utf-8' => [$utf8], 'length' => 7]], [$utf8], $open);
            $this->assertEquals(
                ['base' => '/downloads/Show', 'multi' => '1',
                    'files' => ['/downloads/Show/' . $utf8]],
                erasedataCollectPhysicalPaths(self::HASH),
                'the daemon selected path is returned without metadata substitution',
            );
        }
    }

    public function testSingleFileUsesTheDaemonBasenameIncludingAnOperatorRename()
    {
        foreach (['серия.mkv', 'operator renamed.mkv'] as $selected) {
            $this->fixture(false, ['name' => "\xF1\xE5\xF0\xE8\xFF.mkv",
                'name.utf-8' => 'серия.mkv', 'length' => 7], [$selected]);
            $this->assertEquals(
                ['base' => '/downloads/' . $selected, 'multi' => '0',
                    'files' => ['/downloads/' . $selected]],
                erasedataCollectPhysicalPaths(self::HASH),
                'single-file ownership follows the actual path, not an encoding guess',
            );
        }
    }

    public function testPaddingFlagsRemainAlignedWithTheDaemonFileIndexes()
    {
        $this->fixture(
            true,
            [
                ['path' => ['legacy-pad'], 'length' => 1, 'attr' => 'p'],
                ['path' => ['legacy-file'], 'length' => 7]],
            ['выравнивание', 'серия.mkv'],
            false,
        );
        $result = erasedataCollectPhysicalPaths(self::HASH);
        $this->assertEquals(
            ['/downloads/Show/серия.mkv'],
            is_array($result) ? $result['files'] : null,
            'only the ordinary indexed entry claims a physical file',
        );
    }

    public function testRawByteDaemonPathsArePreserved()
    {
        $selected = "raw \xFF.bin";
        $this->fixture(true, [['path' => [$selected], 'length' => 7]], [$selected]);
        $this->assertSame(
            ['/downloads/Show/' . $selected],
            erasedataCollectPhysicalPaths(self::HASH)['files'],
            'an invalid UTF-8 path remains in its original bytes',
        );
    }

    public function testInvalidOrInconsistentDaemonPathsNeverFallBackToMetadata()
    {
        foreach (['', '/outside', '../outside', './file', 'sub//file', "bad\0name"] as $selected) {
            $this->fixture(true, [['path' => ['safe.bin'], 'length' => 7]], [$selected], false);
            $this->assertSame(false, erasedataCollectPhysicalPaths(self::HASH), 'unsafe selected path refuses: ' . bin2hex($selected));
        }
        $this->fixture(true, [['path' => ['safe.bin'], 'length' => 7]], ['selected.bin']);
        rXMLRPCRequest::$responses['d.get_base_path']['val'][2] = '/downloads/Show/other.bin';
        $this->assertSame(false, erasedataCollectPhysicalPaths(self::HASH), 'frozen and stored names must identify the same file');
        $this->fixture(true, [['path' => ['safe.bin'], 'length' => 7]], ['selected.bin'], false);
        rXMLRPCRequest::$responses['d.get_directory']['val'][] = 'extra.bin';
        $this->assertSame(false, erasedataCollectPhysicalPaths(self::HASH), 'partial or additional rows cannot be matched by guesswork');
        rXMLRPCRequest::$responses['d.get_directory'] = ['ok' => false, 'fault' => true];
        $this->assertSame(false, erasedataCollectPhysicalPaths(self::HASH), 'a failed daemon request is not an empty physical plan');
    }

    public function testMalformedXmlRowsCannotReassignPaddingToAnOrdinaryFile()
    {
        $live = ErasedataCollectorRpcWire::value('selected-live');
        $padding = ErasedataCollectorRpcWire::value('selected-pad');
        $tables = [
            'numeric struct row' => '<value><array><data><value><struct>'
                . '<member><name>1</name>' . $live . '</member><member><name>0</name>' . $padding
                . '</member></struct></value></data></array></value>',
            'wide row' => '<value><array><data><value><array><data>' . $live . $padding
                . '</data></array></value></data></array></value>',
            'flat scalar list' => '<value><array><data>' . $live . $padding . '</data></array></value>',
        ];
        foreach ($tables as $label => $table) {
            $this->fixture(true, [['path' => ['legacy-pad'], 'length' => 1, 'attr' => 'p'],
                ['path' => ['legacy-live'], 'length' => 7]], ['selected-pad', 'selected-live'], false);
            rXMLRPCRequest::$responses['d.get_directory']['wire'] = $this->batchWire(
                ErasedataCollectorRpcWire::value('/downloads/Show'),
                ErasedataCollectorRpcWire::value(1),
                $table,
            );
            $this->assertSame(
                false,
                erasedataCollectPhysicalPaths(self::HASH),
                $label . ' is refused before a flattened scalar can acquire a metadata index',
            );
        }
    }

    public function testMalformedRootAndExtraCommandResultsAreUnconfirmed()
    {
        $table = '<value><array><data>' . ErasedataCollectorRpcWire::value(['selected.bin'])
            . '</data></array></value>';
        $root = ErasedataCollectorRpcWire::value('/downloads/Show');
        $multi = ErasedataCollectorRpcWire::value(1);
        foreach ([
            'struct root' => '<value><struct><member><name>root</name>' . $root . '</member></struct></value>',
            'array root' => '<value><array><data>' . $root . '</data></array></value>',
        ] as $label => $badRoot) {
            $this->fixture(true, [['path' => ['legacy.bin'], 'length' => 7]], ['selected.bin'], false);
            rXMLRPCRequest::$responses['d.get_directory']['wire'] = $this->batchWire($badRoot, $multi, $table);
            $this->assertSame(false, erasedataCollectPhysicalPaths(self::HASH), $label . ' cannot be flattened into a directory scalar');
        }
        $this->fixture(true, [['path' => ['legacy.bin'], 'length' => 7]], ['selected.bin'], false);
        $wire = $this->batchWire($root, $multi, $table);
        rXMLRPCRequest::$responses['d.get_directory']['wire'] = str_replace(
            '</data></array></value></param>',
            '<value><array><data><value><array><data></data></array></value></data></array></value>'
            . '</data></array></value></param>',
            $wire,
        );
        $this->assertSame(false, erasedataCollectPhysicalPaths(self::HASH), 'an unrequested result is not part of the three-command snapshot');
    }

    public function testMalformedFrozenRowsRefuseInsteadOfUsingTheStoredFallback()
    {
        $this->fixture(true, [['path' => ['legacy.bin'], 'length' => 7]], ['selected.bin']);
        $table = '<value><array><data><value><struct><member><name>0</name>'
            . ErasedataCollectorRpcWire::value('/downloads/Show/selected.bin')
            . '</member></struct></value></data></array></value>';
        rXMLRPCRequest::$responses['d.get_base_path']['wire'] = $this->batchWire(
            ErasedataCollectorRpcWire::value('/downloads/Show'),
            ErasedataCollectorRpcWire::value(1),
            $table,
        );
        $this->assertSame(
            false,
            erasedataCollectPhysicalPaths(self::HASH),
            'an answered malformed frozen list is not treated as an unavailable legacy method',
        );
    }

    public function testFixtureCannotReuseRowProvenanceAfterAnUnavailableReply()
    {
        $this->fixture(true, [['path' => ['legacy.bin'], 'length' => 7]], ['selected.bin']);
        $request = new rXMLRPCRequest([new rXMLRPCCommand('d.get_directory', self::HASH),
            new rXMLRPCCommand('d.is_multi_file', self::HASH),
            new rXMLRPCCommand('f.multicall', [self::HASH, '', 'f.get_path='])]);
        $this->assertTrue($request->success(), 'the scripted physical reply is available');
        $this->assertSame(
            [['selected.bin']],
            $request->stringTable(1, 2),
            'the fixture supplies parser-proven table rows',
        );
        unset(rXMLRPCRequest::$responses['d.get_directory']);
        $this->assertSame(false, $request->success(), 'the next scripted reply is unavailable');
        $this->assertSame(
            null,
            $request->stringTable(1, 2),
            'stale fixture provenance cannot make an unavailable table look confirmed',
        );
    }

    public function testParsedMetainfoCanSupplyLayoutWithoutAnotherSessionRead()
    {
        $this->fixture(true, [['path' => ['legacy.bin'], 'length' => 7]], ['selected.bin']);
        $source = new ErasedataCollectorTestSource(ErasedataCollectorTestState::$source);
        ErasedataCollectorTestState::$source = false;
        $result = erasedataCollectPhysicalPaths(self::HASH, $source);
        $this->assertEquals(
            ['/downloads/Show/selected.bin'],
            is_array($result) ? $result['files'] : null,
            'the replacement producer can reuse its hash-verified parsed source',
        );
        $wrong = new ErasedataCollectorTestSource(['hash' => str_repeat('B', 40), 'info' => $source->info]);
        $this->assertSame(
            false,
            erasedataCollectPhysicalPaths(self::HASH, $wrong),
            'the supplied source must still match the requested info hash',
        );
        $invalid = new ErasedataCollectorTestSource(['hash' => 'invalid', 'info' => $source->info]);
        $this->assertSame(
            false,
            erasedataCollectPhysicalPaths('invalid', $invalid),
            'two invalid hash spellings cannot attest a supplied source',
        );
    }

    private function fixture($multi, array $metadata, array $selected, $open = true)
    {
        $info = $multi ? ['name' => 'Show', 'files' => $metadata] : $metadata;
        ErasedataCollectorTestState::$source = ['hash' => self::HASH, 'info' => $info];
        $base = $multi ? '/downloads/Show' : '/downloads/' . $selected[0];
        $directory = $multi ? $base : '/downloads';
        $frozen = array_map(function ($path) use ($directory, $open) {
            return $open ? $directory . '/' . $path : '';
        }, $selected);
        rXMLRPCRequest::$responses['d.get_base_path'] = ['val' => array_merge(
            [$open ? $base : '', $multi ? 1 : 0],
            $frozen,
        )];
        rXMLRPCRequest::$responses['d.get_directory'] = ['val' => array_merge(
            [$directory, $multi ? 1 : 0],
            $selected,
        )];
        return [$base, $directory];
    }

    private function batchWire($root, $multi, $table)
    {
        return ErasedataCollectorRpcWire::batch([$root, $multi, $table]);
    }
}
