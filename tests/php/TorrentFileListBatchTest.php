<?php

define('TESTLIB_HANDLER_STUBS', true);
require_once(__DIR__.'/../plugins/rutracker_check/TestLib.php');
require_once(__DIR__.'/HttprpcListFixture.php');
require_once(__DIR__.'/../../php/xmlrpc_response.php');
require_once(__DIR__.'/../../php/torrentfilenames.php');

class FileListBatchCommand extends rXMLRPCCommand
{
    public function __construct($command, $parameters = null)
    {
        $this->command = $command;
        $this->params = $parameters === null ? array() : (is_array($parameters) ? $parameters : array($parameters));
    }
    public function addParameter($value, $aType = null) { $this->params[] = $value; }
    public function addParameters($values) { foreach($values as $value) $this->addParameter($value); }
}

class FileListBatchRequest
{
    public static $payload;
    public static $calls;
    public static $commands;
    public $important;
    public $responseTypes;
    public $responseValues;
    public $fault;
    public $val;
    public function __construct($command) { self::$commands = array($command); }
    public function addCommand($command) { self::$commands[] = $command; }
    public function run($trusted)
    {
        self::$calls++;
        $response = (new rXMLRPCResponse(HttprpcListFixture::body(self::$payload), true))->read();
        $this->responseTypes = $response->valueTypes;
        $this->responseValues = $response->values;
        $this->fault = $response->fault;
        $this->val = array_column($response->scalars, 1);
        return true;
    }
    public function success($trusted) { return $this->run($trusted) && !$this->fault; }
}

class FileListBatchSource
{
    public static $source;
    public static $paths;
    public static function getSource($hash, &$sourcePath = null, $knownPaths = null)
    {
        if($knownPaths === null) throw new RuntimeException('unexpected extra source RPC');
        self::$paths[] = $knownPaths;
        return $knownPaths === false ? false : self::$source;
    }
}

$multicall = loadFunctionDefinition(__DIR__.'/../../plugins/httprpc/action.php', 'makeMulticall');
eval(str_replace(array('function makeMulticall(', 'new rXMLRPCCommand(', 'new rXMLRPCRequest('),
    array('function fileListBatchMulticall(', 'new FileListBatchCommand(', 'new FileListBatchRequest('), $multicall));

class TorrentFileListBatchTest extends TestCase
{
    private function execute($payload, $source = false, $add = array())
    {
        $endpoint = file_get_contents(__DIR__.'/../../plugins/httprpc/action.php');
        $start = strpos($endpoint, 'case "fls":');
        $branch = substr($endpoint, $start, strpos($endpoint, 'case "prs":', $start)-$start);
        $branch = str_replace(array('makeMulticall(', 'rTorrent::getSource(', "require_once(__DIR__.'/../../php/rtorrent.php');"),
            array('fileListBatchMulticall(', 'FileListBatchSource::getSource(', ''), $branch);
        FileListBatchRequest::$payload = $payload;
        FileListBatchRequest::$calls = 0;
        FileListBatchSource::$source = $source;
        FileListBatchSource::$paths = array();
        $mode = 'fls'; $hash = array($source instanceof Torrent ? $source->hash_info() : str_repeat('A', 40));
        $result = null;
        eval('switch($mode) {'.$branch.'}');
        return $result;
    }

    private function row($name) { return array($name, '1', '2', '100', '1'); }
    private function payload($rows) { return array(array($rows), array('/session/'), array('/tied/source.torrent')); }

    public function testOneBatchPreservesLegacyLabelsExtensionsAndSourcePaths()
    {
        $source = Torrent::fromRawBytes(TorrentEncoder::raw(array('info'=>array('name'=>"legacy\xFF", 'name.utf-8'=>'Серия', 'length'=>100))));
        $row = array_merge($this->row("legacy\xFF"), array('plugin-value'));
        $result = $this->execute($this->payload(array($row)), $source, array('f.get_priority='));
        $this->assertSame(array('name'=>'Серия', 'pathKey'=>'legacy%FF'), $result[0][0], 'real XML base64 and the resolver preserve a legacy name and its raw identity');
        $this->assertSame(array_slice($row, 1), array_slice($result[0], 1), 'the file fields and extension retain exact offsets');
        $this->assertSame(1, FileListBatchRequest::$calls, 'files and source paths share one daemon roundtrip');
        $this->assertSame(array('f.multicall', 'get_session', 'd.get_tied_to_file'), array_map(function($command) { return $command->command; }, FileListBatchRequest::$commands), 'one fixed batch includes the source path reads');
        $this->assertSame(array(array('/session/', '/tied/source.torrent')), FileListBatchSource::$paths, 'the source owner cannot repeat the already-read path RPC');
        $this->assertSame('f.get_priority=', FileListBatchRequest::$commands[0]->params[7], 'the extension command follows all five original file commands');
    }

    public function testRequiredReplyFaultsAndMalformedRowsNeverPublishPartialFiles()
    {
        $row = $this->row('valid');
        $fault = array('faultCode'=>'-1', 'faultString'=>'missing files');
        foreach(array('scalar', array(), array($fault, array('/session/'), array('/tied')),
            $this->payload(array(array_slice($row, 0, 4))), $this->payload(array(array_merge($row, array('extra')))),
            $this->payload(array($row, array('partial'))), $this->payload(array(array(array('nested'), '1', '2', '100', '1')))) as $payload) {
            $this->assertSame(array(), $this->execute($payload), 'faults, missing main results, wrong widths and nested cells refuse the complete file table');
            $this->assertSame(array(), FileListBatchSource::$paths, 'a refused main result never reads source metadata');
            $this->assertSame(1, FileListBatchRequest::$calls, 'invalid required replies are not retried');
        }
    }

    public function testOptionalFaultsAndPartialPathRepliesKeepEveryFile()
    {
        $row = $this->row("legacy\xFF"); $fault = array('faultCode'=>'-1', 'faultString'=>'missing source');
        foreach(array(array(), array($fault, array('/tied')), array(array('/session/'), $fault),
            array('/session/', array('/tied')), array(array('/session/'), array('/tied', 'extra')),
            array(array(array('nested')), array('/tied'))) as $optional) {
            $result = $this->execute(array_merge(array(array(array($row))), $optional));
            $this->assertSame('legacy\\xFF', $result[0][0]['name'], 'an optional source failure preserves the visible file bytes');
            $this->assertSame(array_slice($row, 1), array_slice($result[0], 1), 'an optional fault never changes sizes or priority');
            $this->assertSame(array(false), FileListBatchSource::$paths, 'unknown optional paths are explicitly false, never a new network lookup');
            $this->assertSame(1, FileListBatchRequest::$calls, 'source failures keep the one-request budget');
        }
    }

    public function testUtf8AndEmptyTablesNeedNoMetadataAndManyLegacyFilesNeedOneRead()
    {
        $rows = array($this->row('映画/Серия 🙂'));
        $this->assertSame($rows, $this->execute($this->payload($rows)), 'valid Unicode retains its original wire cells');
        $this->assertSame(array(), FileListBatchSource::$paths, 'Unicode does not need metainfo');
        $this->assertSame(array(), $this->execute($this->payload(array())), 'empty file inventory remains empty');
        $rows = array_fill(0, 500, $this->row("legacy\xFF"));
        $result = $this->execute($this->payload($rows));
        $this->assertSame(500, count($result), 'a large legacy list keeps every original file index');
        $this->assertSame(1, count(FileListBatchSource::$paths), 'many legacy names still read one source');
        $this->assertSame(1, FileListBatchRequest::$calls, 'many legacy names still use one daemon roundtrip');
    }
}
