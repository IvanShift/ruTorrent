<?php

require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/FakeRtorrentDaemon.php';
require_once __DIR__ . '/../../php/xmlrpc.php';

/** Synthetic type adversaries travel through the real SCGI transport and parser. */
class XMLRPCStringTableTest extends TestCase
{
    private $previousSettings;
    private $settingsProperty;
    private $globals = [];
    private $daemon;
    private $directory;

    public function setUp()
    {
        $this->directory = sys_get_temp_dir() . '/rpc-table-' . getmypid();
        mkdir($this->directory, 0700);
        $reflection = new ReflectionClass('rTorrentSettings');
        $settings = $reflection->newInstanceWithoutConstructor();
        $settings->iVersion = 0x1019;
        $settings->apiVersion = 10;
        $settings->aliases = [];
        $this->settingsProperty = $reflection->getProperty('theSettings');
        self::makeAccessible($this->settingsProperty);
        $this->previousSettings = $this->settingsProperty->getValue();
        $this->settingsProperty->setValue(null, $settings);
        foreach (['scgi_host' => '127.0.0.1', 'rpcTimeOut' => 3, 'rpcLogCalls' => false,
            'rpcLogFaults' => false, 'log_file' => $this->directory . '/app.log', 'scgi_port' => 0] as $key => $value) {
            $this->globals[$key] = [array_key_exists($key, $GLOBALS), $GLOBALS[$key] ?? null];
            $GLOBALS[$key] = $value;
        }
    }

    public function tearDown()
    {
        if ($this->daemon !== null) {
            $this->daemon->stop();
        }
        $this->settingsProperty->setValue(null, $this->previousSettings);
        foreach ($this->globals as $key => $value) {
            if ($value[0]) {
                $GLOBALS[$key] = $value[1];
            } else {
                unset($GLOBALS[$key]);
            }
        }
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testIndexedTableUsesTheExactCommandResultAndPreservesBinaryPaths()
    {
        $bytes = "selected \xFF\0\r name";
        $table = self::arrayValue([self::arrayValue([self::value('base64', base64_encode($bytes))])]);
        $this->serve([self::multicallEnvelope([self::value('string', '/downloads'), self::value('i8', '1'), $table])]);
        $request = $this->batchRequest();
        $this->assertTrue($request->success(), 'the scalar roots and file table are read in one batch');
        $this->assertSame(
            [[['/downloads'], ['1'], [[[$bytes]]]]],
            $request->responseValues,
            'the legacy semantic accessor retains native multicall wrappers',
        );
        $this->assertSame([[$bytes]], $request->stringTable(1, 2), 'the indexed table retains exact path bytes');
        $this->assertSame(null, $request->stringTable(1), 'the default still requires one sole result');
        foreach ([0, 1, 3, -1, '2', 2.0, true, false, []] as $index) {
            $this->assertSame(null, $request->stringTable(1, $index), 'only a present nonnegative integer table index is valid');
        }
        foreach ([0, -1, '1', 2] as $columns) {
            $this->assertSame(null, $request->stringTable($columns, 2), 'indexed results retain the exact positive integer width contract');
        }
    }

    public function testIndexedTablesRemainSeparateAndAnEmptyTableIsConfirmed()
    {
        $one = self::arrayValue([self::arrayValue([self::value('string', 'first')])]);
        $two = self::arrayValue([self::arrayValue([self::value('string', 'second'), self::value('string', 'column')])]);
        $empty = self::arrayValue([]);
        $this->serve([self::multicallEnvelope([$one, $two, $empty])]);
        $request = $this->batchRequest();
        $this->assertTrue($request->success(), 'separate tables remain distinct command results');
        $this->assertSame([['first']], $request->stringTable(1, 0), 'index zero names the first table');
        $this->assertSame([['second', 'column']], $request->stringTable(2, 1), 'each index retains its own table width');
        $this->assertSame([], $request->stringTable(1, 2), 'empty indexed inventory is confirmed, not unknown');
        $this->assertSame([], $request->stringTable(3, 2), 'an empty table has no invented width');
        $this->assertSame(null, $request->stringTable(1), 'the default cannot concatenate separate tables');
        $this->assertSame(null, $request->stringTable(1, 1), 'another table cannot supply a missing column proof');
    }

    public function testIndexedMalformedRowsCannotBorrowTheSameFlatScalarCount()
    {
        $live = self::value('string', 'selected-live');
        $padding = self::value('string', 'selected-pad');
        $map = self::value('struct', '<member><name>1</name>' . $live . '</member><member><name>0</name>' . $padding . '</member>');
        $valid = self::arrayValue([self::arrayValue([$live]), self::arrayValue([$padding])]);
        $bad = [self::arrayValue([$map]), self::arrayValue([$live, $padding]),
            self::arrayValue([self::arrayValue([$live, $padding])]), $map];
        $this->serve(array_map(function ($table) {
            return self::multicallEnvelope([self::value('string', '/downloads'), self::value('i8', '1'), $table]);
        }, array_merge([$valid], $bad)));
        foreach (array_merge([$valid], $bad) as $index => $table) {
            $request = $this->batchRequest();
            $this->assertTrue($request->success(), 'the indexed adversary is well-formed XML-RPC');
            $this->assertSame(
                ['/downloads', '1', 'selected-live', 'selected-pad'],
                $request->val,
                'all shapes have the same legacy flat accessors',
            );
            $this->assertSame(
                $index === 0 ? [['selected-live'], ['selected-pad']] : null,
                $request->stringTable(1, 2),
                'only genuine one-column XML rows can attest positional metadata indexes',
            );
        }
    }

    public function testIndexedTableIsUnavailableAfterFaultOrLateTransportRefusal()
    {
        $table = self::arrayValue([self::arrayValue([self::value('string', 'one')])]);
        $fault = '<methodResponse><fault><value><struct><member><name>faultCode</name>'
            . self::value('i4', '-1') . '</member><member><name>faultString</name>'
            . self::value('string', 'refused') . '</member></struct></value></fault></methodResponse>';
        foreach (['<methodResponse>', '', $fault] as $late) {
            $this->serve([self::envelope($table), $late,
                self::multicallEnvelope([self::value('string', '/downloads'), self::value('i8', '1'), $table])]);
            $big = str_repeat('x', 1100000);
            $request = new rXMLRPCRequest([new rXMLRPCCommand('d.custom.set', ['A', 'k', $big]),
                new rXMLRPCCommand('d.custom.set', ['B', 'k', $big])]);
            $request->important = false;
            $this->assertTrue(!$request->success(), 'a late unavailable result refuses the request');
            $this->assertSame(null, $request->stringTable(1, 0), 'an earlier indexed table cannot survive a failed request');
            $this->assertSame(null, $request->stringTable(1, 1), 'no later table is invented');
            $this->assertSame([], $request->responseCommandValues, 'failed requests expose no earlier command snapshot');
            $this->assertSame([], $request->responseCommandTypes, 'failed requests expose no earlier command type proof');
            foreach ([new rXMLRPCCommand('d.directory', 'A'), new rXMLRPCCommand('d.is_multi_file', 'A'),
                new rXMLRPCCommand('f.multicall', ['A', '', 'f.path='])] as $command) {
                $request->addCommand($command);
            }
            $this->assertTrue($request->success(), 'request reuse reads a fresh batch');
            $this->assertSame([['one']], $request->stringTable(1, 2), 'only fresh indexed table provenance is published');
        }
    }

    public function testMalformedSuccessWrappersKeepTheirCommandSlotWithoutAttestingARow()
    {
        $table = self::arrayValue([self::arrayValue([self::value('string', 'selected')])]);
        $outer = self::arrayValue([self::value('string', 'not a success wrapper'),
            self::arrayValue([$table]), self::arrayValue([]), self::arrayValue([$table, $table])]);
        $this->serve([self::envelope($outer)]);
        $request = $this->batchRequest();
        $request->addCommand(new rXMLRPCCommand('d.name', 'A'));
        $this->assertTrue($request->success(), 'well-formed XML keeps malformed command wrappers distinguishable');
        $this->assertSame(
            [null, 'array', null, null],
            $request->responseCommandTypes,
            'invalid wrappers retain null slots instead of shifting later results',
        );
        $this->assertSame(null, $request->stringTable(1, 0), 'a scalar command slot cannot impersonate a table');
        $this->assertSame([['selected']], $request->stringTable(1, 1), 'the second command remains at index one');
        $this->assertSame(null, $request->stringTable(1, 2), 'an empty success wrapper does not invent an empty table');
        $this->assertSame(null, $request->stringTable(1, 3), 'a wide success wrapper cannot choose one of several results');
    }

    public function testIndexedLargeTableRetainsOnlyItsOwnRowWidth()
    {
        $row = self::arrayValue([self::value('string', str_repeat('path', 25))]);
        $table = self::arrayValue(array_fill(0, 4096, $row));
        $this->serve([self::multicallEnvelope([self::value('string', '/downloads'), self::value('i8', '1'), $table])]);
        $request = $this->batchRequest();
        $this->assertTrue($request->success(), 'a large indexed manifest is read without an extra parser pass');
        $this->assertSame(4096, count($request->stringTable(1, 2)), 'all large-manifest rows retain their command identity');
        $this->assertSame(null, $request->stringTable(2, 2), 'the compact proof still enforces the one-column width');
    }

    public function testIndexedProofRequiresTheExactNumberOfSentCommands()
    {
        $table = self::arrayValue([self::arrayValue([self::value('string', 'forged')])]);
        $bodies = [];
        foreach ([0, 2, 4] as $count) {
            $bodies[] = self::multicallEnvelope(array_fill(0, $count, $table));
        }
        $this->serve($bodies);
        foreach ([0, 2, 4] as $count) {
            $request = $this->batchRequest();
            $this->assertTrue($request->success(), 'legacy accessors keep their response handling for ' . $count . ' results');
            $this->assertSame(
                [],
                $request->responseCommandValues,
                'a missing or extra command result cannot attest any snapshot field',
            );
            $this->assertSame(
                [],
                $request->responseCommandTypes,
                'a count mismatch cannot publish command type proof',
            );
            foreach ([0, 1, 2, 3] as $index) {
                $this->assertSame(null, $request->stringTable(1, $index), 'no position in an unmatched batch is trusted');
            }
        }
    }

    public function testCrossChunkCountCompensationNeverRepublishesCommandProof()
    {
        $table = self::arrayValue([self::arrayValue([self::value('string', 'forged')])]);
        $fresh = self::arrayValue([self::arrayValue([self::value('string', 'fresh')])]);
        foreach ([[0, 2], [2, 0]] as $counts) {
            $this->serve([self::multicallEnvelope(array_fill(0, $counts[0], $table)),
                self::multicallEnvelope(array_fill(0, $counts[1], $table)), self::envelope($table), self::envelope($fresh)]);
            $big = str_repeat('x', 1100000);
            $request = new rXMLRPCRequest([
                new rXMLRPCCommand('d.custom.set', ['A', 'k', $big]),
                new rXMLRPCCommand('d.custom.set', ['B', 'k', $big]),
                new rXMLRPCCommand('d.custom.set', ['C', 'k', $big])]);
            $request->important = false;
            $this->assertTrue($request->success(), 'legacy chunk parsing can succeed despite mismatched result counts');
            $this->assertSame(3, count($this->daemon->bodies()), 'the three commands were sent in three separate calls');
            $this->assertSame(
                ['forged', 'forged', 'forged'],
                $request->val,
                'missing and extra chunks compensate in the legacy flat count',
            );
            $this->assertSame(
                [],
                $request->responseCommandValues,
                'a bad chunk invalidates the complete logical command snapshot',
            );
            foreach ([0, 1, 2] as $index) {
                $this->assertSame(null, $request->stringTable(1, $index), 'later chunks cannot shift or restore a command position');
            }
            $request->addCommand(new rXMLRPCCommand('d.multicall2', ['', 'main', 'd.name=']));
            $this->assertTrue($request->success(), 'the next run may establish a new command snapshot');
            $this->assertSame([['fresh']], $request->stringTable(1, 0), 'count refusal is reset only by a new run');
        }
    }

    public function testExactChunkCountsKeepGlobalCommandIndexes()
    {
        $tables = [];
        foreach (['first', 'second', 'third'] as $name) {
            $tables[] = self::arrayValue([self::arrayValue([self::value('string', $name)])]);
        }
        $this->serve([self::multicallEnvelope([$tables[0]]),
            self::multicallEnvelope([$tables[1]]), self::envelope($tables[2])]);
        $big = str_repeat('x', 1100000);
        $request = new rXMLRPCRequest([
            new rXMLRPCCommand('d.custom.set', ['A', 'k', $big]),
            new rXMLRPCCommand('d.custom.set', ['B', 'k', $big]),
            new rXMLRPCCommand('d.custom.set', ['C', 'k', $big])]);
        $request->important = false;
        $this->assertTrue($request->success(), 'matching command results may span separate calls');
        $this->assertSame(3, count($this->daemon->bodies()), 'the fixture sent three calls');
        foreach (['first', 'second', 'third'] as $index => $name) {
            $this->assertSame(
                [[$name]],
                $request->stringTable(1, $index),
                'the global command index survives a native wrapper followed by a direct response',
            );
        }
        $this->assertSame(null, $request->stringTable(1), 'default top-level table semantics remain unchanged across calls');
    }

    public function testLateChunkCountMismatchClearsAnEarlierValidPrefix()
    {
        $table = self::arrayValue([self::arrayValue([self::value('string', 'prefix')])]);
        $this->serve([self::multicallEnvelope([$table]), self::multicallEnvelope([]), self::envelope($table)]);
        $big = str_repeat('x', 1100000);
        $request = new rXMLRPCRequest([
            new rXMLRPCCommand('d.custom.set', ['A', 'k', $big]),
            new rXMLRPCCommand('d.custom.set', ['B', 'k', $big]),
            new rXMLRPCCommand('d.custom.set', ['C', 'k', $big])]);
        $request->important = false;
        $this->assertTrue($request->success(), 'the legacy response contract remains unchanged');
        $this->assertSame(['prefix', 'prefix'], $request->val, 'the valid prefix preceded the count mismatch');
        $this->assertSame([], $request->responseCommandValues, 'a later count mismatch revokes the earlier valid prefix');
        $this->assertSame([], $request->responseCommandTypes, 'earlier command types are revoked with their values');
        $this->assertSame(null, $request->stringTable(1, 0), 'later valid chunks cannot leave the prefix table authorized');
    }

    public function testManuallySubmittedMulticallDoesNotInventIndexedCommands()
    {
        $values = [self::value('string', 'one'), self::value('string', 'two')];
        $table = self::arrayValue([self::arrayValue([self::value('string', 'inner table')])]);
        $this->serve([self::multicallEnvelope($values), self::multicallEnvelope([$table])]);
        foreach ([[['one'], ['two']], null] as $expectedDefault) {
            $request = new rXMLRPCRequest(new rXMLRPCCommand('system.multicall', ['opaque caller arguments']));
            $request->important = false;
            $this->assertTrue($request->success(), 'the explicit multicall keeps its legacy response contract');
            $this->assertSame($expectedDefault, $request->stringTable(1), 'the default table accessor remains unchanged');
            $this->assertSame(
                [],
                $request->responseCommandValues,
                'the request owner cannot attest inner command indexes of an opaque multicall',
            );
            $this->assertSame(null, $request->stringTable(1, 0), 'even one opaque inner result has no indexed proof');
        }
    }

    public function testExactStringTablesPreserveBytesAndEmptyRowsAreDistinctFromEmptyTables()
    {
        $bytes = "cp\xCF\x01\r\0 quote\" \\";
        $row = self::arrayValue([self::value('string', 'a&amp;b'), self::value('base64', base64_encode($bytes)), '<value/>']);
        $this->serve([self::envelope(self::arrayValue([$row])), self::envelope(self::arrayValue([]))]);
        $request = $this->request();
        $this->assertTrue($request->success(), 'the typed string table is read over SCGI');
        $this->assertSame([['a&b', $bytes, '']], $request->stringTable(3), 'string and base64 cells preserve exact bytes');
        $this->assertSame([['a&b', $bytes, '']], $request->stringTable(3, 0), 'a direct command is indexed at zero');
        $this->assertSame(null, $request->stringTable(3, 1), 'a direct command cannot invent another result');
        $this->assertSame(['a&b', $bytes, ''], $request->val, 'the legacy flat scalar accessor remains unchanged');
        foreach ([0, -1, '3', 2, 4] as $columns) {
            $this->assertSame(null, $request->stringTable($columns), 'a table requires its positive integer width');
        }
        $request->addCommand(new rXMLRPCCommand('d.multicall2', ['', 'main', 'd.name=']));
        $this->assertTrue($request->success(), 'an empty inventory is a successful response');
        $this->assertSame([], $request->stringTable(3), 'an empty table is valid for the requested width');
        $this->assertSame([], $request->stringTable(1), 'empty tables carry no invented row width');
    }

    public function testXmlMapsAndNonStringCellsCannotMasqueradeAsStringRows()
    {
        $string = self::value('string', '1');
        $numericMap = self::value('struct', '<member><name>0</name>' . $string . '</member>');
        $bad = [$string, $numericMap, self::arrayValue([$numericMap]),
            self::arrayValue([self::arrayValue([])]),
            self::arrayValue([self::arrayValue([$string]), self::arrayValue([$string, $string])])];
        foreach ([self::value('i8', '1'), self::value('boolean', '1'), self::value('double', '1'),
            '<value><nil/></value>', self::value('dateTime.iso8601', '20261009T10:00:00'),
            self::arrayValue([$string]), $numericMap] as $cell) {
            $bad[] = self::arrayValue([self::arrayValue([$cell])]);
        }
        $this->serve(array_map([__CLASS__, 'envelope'], $bad));
        foreach ($bad as $value) {
            $request = $this->request();
            $this->assertTrue($request->run(), 'the adversary is valid XML-RPC grammar');
            $this->assertSame(null, $request->stringTable(1), 'only genuine uniform arrays of string cells are a string table');
        }
    }

    public function testLateRefusalClearsGrammarMetadataAndReusePublishesOnlyTheNewTable()
    {
        $row = self::arrayValue([self::value('string', 'one'), self::value('string', 'two')]);
        foreach (['<methodResponse>', ''] as $late) {
            $this->serve([self::envelope(self::arrayValue([$row])), $late,
                self::envelope(self::arrayValue([self::arrayValue([self::value('string', 'new')])]))]);
            $big = str_repeat('x', 1100000);
            $request = new rXMLRPCRequest([new rXMLRPCCommand('d.custom.set', ['A', 'k', $big]),
                new rXMLRPCCommand('d.custom.set', ['B', 'k', $big])]);
            $request->important = false;
            $this->assertTrue(!$request->run(), 'a malformed or absent late reply refuses the whole request');
            $this->assertSame(null, $request->stringTable(2), 'no earlier table grammar survives a late refusal');
            $this->assertSame([], $request->responseValues, 'no earlier semantic rows survive either');
            $request->addCommand(new rXMLRPCCommand('d.name', 'A'));
            $this->assertTrue($request->success(), 'the same request can read a later valid table');
            $this->assertSame([['new']], $request->stringTable(1), 'only the new table is published');
            $this->assertSame(null, $request->stringTable(2), 'the earlier width cannot attest a later table');
        }
    }

    public function testMultipleResponseTablesAndFaultsNeverPublishOneInventedTable()
    {
        $table = self::arrayValue([self::arrayValue([self::value('string', 'one')])]);
        $fault = '<methodResponse><fault><value><struct><member><name>faultCode</name>'
            . self::value('i4', '-1') . '</member><member><name>faultString</name>'
            . self::value('string', 'refused') . '</member></struct></value></fault></methodResponse>';
        $this->serve([self::envelope($table), self::envelope($table), $fault]);
        $big = str_repeat('x', 1100000);
        $request = new rXMLRPCRequest([new rXMLRPCCommand('d.custom.set', ['A', 'k', $big]),
            new rXMLRPCCommand('d.custom.set', ['B', 'k', $big])]);
        $request->important = false;
        $this->assertTrue($request->success(), 'both separate response chunks can be well formed');
        $this->assertSame(null, $request->stringTable(1), 'multiple top response tables are not silently concatenated');
        $request->addCommand(new rXMLRPCCommand('d.name', 'A'));
        $this->assertTrue(!$request->success(), 'a daemon fault remains a fault');
        $this->assertSame(null, $request->stringTable(1), 'a fault publishes no string table');
    }

    private static function value($type, $text = '')
    {
        return '<value><' . $type . '>' . $text . '</' . $type . '></value>';
    }

    private static function arrayValue($members)
    {
        return self::value('array', '<data>' . implode('', $members) . '</data>');
    }

    private static function envelope($value)
    {
        return '<methodResponse><params><param>' . $value . '</param></params></methodResponse>';
    }

    private function serve($bodies)
    {
        if ($this->daemon !== null) {
            $this->daemon->stop();
        }
        $this->daemon = new FakeRtorrentDaemon(array_map(function ($body) {
            return new FakeRtorrentRawReply($body);
        }, $bodies), $this->directory . '/calls.log');
        $GLOBALS['scgi_port'] = $this->daemon->port();
    }

    private function request()
    {
        $request = new rXMLRPCRequest(new rXMLRPCCommand('d.multicall2', ['', 'main', 'd.name=']));
        $request->important = false;
        return $request;
    }

    private static function multicallEnvelope(array $values)
    {
        return self::envelope(self::arrayValue(array_map(function ($value) {
            return self::arrayValue([$value]);
        }, $values)));
    }

    private function batchRequest()
    {
        $request = new rXMLRPCRequest([
            new rXMLRPCCommand('d.directory', 'A'),
            new rXMLRPCCommand('d.is_multi_file', 'A'),
            new rXMLRPCCommand('f.multicall', ['A', '', 'f.path='])]);
        $request->important = false;
        return $request;
    }
}
