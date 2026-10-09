<?php
require_once __DIR__.'/TestCase.php';
require_once __DIR__.'/FakeRtorrentDaemon.php';
require_once __DIR__.'/../../php/xmlrpc.php';

/** Synthetic type adversaries travel through the real SCGI transport and parser. */
class XMLRPCStringTableTest extends TestCase
{
    private $previousSettings;
    private $settingsProperty;
    private $globals = array();
    private $daemon;
    private $directory;

    public function setUp()
    {
        $this->directory = sys_get_temp_dir().'/rpc-table-'.getmypid();
        mkdir($this->directory, 0700);
        $reflection = new ReflectionClass('rTorrentSettings');
        $settings = $reflection->newInstanceWithoutConstructor();
        $settings->iVersion = 0x1019;
        $settings->apiVersion = 10;
        $settings->aliases = array();
        $this->settingsProperty = $reflection->getProperty('theSettings');
        self::makeAccessible($this->settingsProperty);
        $this->previousSettings = $this->settingsProperty->getValue();
        $this->settingsProperty->setValue(null, $settings);
        foreach(array('scgi_host'=>'127.0.0.1', 'rpcTimeOut'=>3, 'rpcLogCalls'=>false,
            'rpcLogFaults'=>false, 'log_file'=>$this->directory.'/app.log', 'scgi_port'=>0) as $key=>$value) {
            $this->globals[$key] = array(array_key_exists($key, $GLOBALS), $GLOBALS[$key] ?? null);
            $GLOBALS[$key] = $value;
        }
    }

    public function tearDown()
    {
        if($this->daemon !== null) $this->daemon->stop();
        $this->settingsProperty->setValue(null, $this->previousSettings);
        foreach($this->globals as $key=>$value)
            if($value[0]) $GLOBALS[$key] = $value[1]; else unset($GLOBALS[$key]);
        foreach(glob($this->directory.'/*') ?: array() as $file) unlink($file);
        rmdir($this->directory);
    }

    private static function value($type, $text = '')
    {
        return '<value><'.$type.'>'.$text.'</'.$type.'></value>';
    }

    private static function arrayValue($members)
    {
        return self::value('array', '<data>'.implode('', $members).'</data>');
    }

    private static function envelope($value)
    {
        return '<methodResponse><params><param>'.$value.'</param></params></methodResponse>';
    }

    private function serve($bodies)
    {
        if($this->daemon !== null) $this->daemon->stop();
        $this->daemon = new FakeRtorrentDaemon(array_map(function($body) {
            return new FakeRtorrentRawReply($body);
        }, $bodies), $this->directory.'/calls.log');
        $GLOBALS['scgi_port'] = $this->daemon->port();
    }

    private function request()
    {
        $request = new rXMLRPCRequest(new rXMLRPCCommand('d.multicall2', array('', 'main', 'd.name=')));
        $request->important = false;
        return $request;
    }

    public function testExactStringTablesPreserveBytesAndEmptyRowsAreDistinctFromEmptyTables()
    {
        $bytes = "cp\xCF\x01\r\0 quote\" \\";
        $row = self::arrayValue(array(self::value('string', 'a&amp;b'), self::value('base64', base64_encode($bytes)), '<value/>'));
        $this->serve(array(self::envelope(self::arrayValue(array($row))), self::envelope(self::arrayValue(array()))));
        $request = $this->request();
        $this->assertTrue($request->success(), 'the typed string table is read over SCGI');
        $this->assertSame(array(array('a&b', $bytes, '')), $request->stringTable(3), 'string and base64 cells preserve exact bytes');
        $this->assertSame(array('a&b', $bytes, ''), $request->val, 'the legacy flat scalar accessor remains unchanged');
        foreach(array(0, -1, '3', 2, 4) as $columns)
            $this->assertSame(null, $request->stringTable($columns), 'a table requires its positive integer width');
        $request->addCommand(new rXMLRPCCommand('d.multicall2', array('', 'main', 'd.name=')));
        $this->assertTrue($request->success(), 'an empty inventory is a successful response');
        $this->assertSame(array(), $request->stringTable(3), 'an empty table is valid for the requested width');
        $this->assertSame(array(), $request->stringTable(1), 'empty tables carry no invented row width');
    }

    public function testXmlMapsAndNonStringCellsCannotMasqueradeAsStringRows()
    {
        $string = self::value('string', '1');
        $numericMap = self::value('struct', '<member><name>0</name>'.$string.'</member>');
        $bad = array($string, $numericMap, self::arrayValue(array($numericMap)),
            self::arrayValue(array(self::arrayValue(array()))),
            self::arrayValue(array(self::arrayValue(array($string)), self::arrayValue(array($string, $string)))));
        foreach(array(self::value('i8', '1'), self::value('boolean', '1'), self::value('double', '1'),
            '<value><nil/></value>', self::value('dateTime.iso8601', '20261009T10:00:00'),
            self::arrayValue(array($string)), $numericMap) as $cell)
            $bad[] = self::arrayValue(array(self::arrayValue(array($cell))));
        $this->serve(array_map(array(__CLASS__, 'envelope'), $bad));
        foreach($bad as $value) {
            $request = $this->request();
            $this->assertTrue($request->run(), 'the adversary is valid XML-RPC grammar');
            $this->assertSame(null, $request->stringTable(1), 'only genuine uniform arrays of string cells are a string table');
        }
    }

    public function testLateRefusalClearsGrammarMetadataAndReusePublishesOnlyTheNewTable()
    {
        $row = self::arrayValue(array(self::value('string', 'one'), self::value('string', 'two')));
        foreach(array('<methodResponse>', '') as $late) {
            $this->serve(array(self::envelope(self::arrayValue(array($row))), $late,
                self::envelope(self::arrayValue(array(self::arrayValue(array(self::value('string', 'new'))))))));
            $big = str_repeat('x', 1100000);
            $request = new rXMLRPCRequest(array(new rXMLRPCCommand('d.custom.set', array('A', 'k', $big)),
                new rXMLRPCCommand('d.custom.set', array('B', 'k', $big))));
            $request->important = false;
            $this->assertTrue(!$request->run(), 'a malformed or absent late reply refuses the whole request');
            $this->assertSame(null, $request->stringTable(2), 'no earlier table grammar survives a late refusal');
            $this->assertSame(array(), $request->responseValues, 'no earlier semantic rows survive either');
            $request->addCommand(new rXMLRPCCommand('d.name', 'A'));
            $this->assertTrue($request->success(), 'the same request can read a later valid table');
            $this->assertSame(array(array('new')), $request->stringTable(1), 'only the new table is published');
            $this->assertSame(null, $request->stringTable(2), 'the earlier width cannot attest a later table');
        }
    }

    public function testMultipleResponseTablesAndFaultsNeverPublishOneInventedTable()
    {
        $table = self::arrayValue(array(self::arrayValue(array(self::value('string', 'one')))));
        $fault = '<methodResponse><fault><value><struct><member><name>faultCode</name>'
            .self::value('i4', '-1').'</member><member><name>faultString</name>'
            .self::value('string', 'refused').'</member></struct></value></fault></methodResponse>';
        $this->serve(array(self::envelope($table), self::envelope($table), $fault));
        $big = str_repeat('x', 1100000);
        $request = new rXMLRPCRequest(array(new rXMLRPCCommand('d.custom.set', array('A', 'k', $big)),
            new rXMLRPCCommand('d.custom.set', array('B', 'k', $big))));
        $request->important = false;
        $this->assertTrue($request->success(), 'both separate response chunks can be well formed');
        $this->assertSame(null, $request->stringTable(1), 'multiple top response tables are not silently concatenated');
        $request->addCommand(new rXMLRPCCommand('d.name', 'A'));
        $this->assertTrue(!$request->success(), 'a daemon fault remains a fault');
        $this->assertSame(null, $request->stringTable(1), 'a fault publishes no string table');
    }
}
