<?php

define('TESTLIB_HANDLER_STUBS', true);
require_once(__DIR__.'/../plugins/rutracker_check/TestLib.php');
require_once(__DIR__.'/HttprpcListFixture.php');
require_once(__DIR__.'/PhpProcess.php');
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
        $payload = is_array(self::$payload) ? HttprpcListFixture::typed(self::$payload, HttprpcListFixture::FILE_INTEGERS) : self::$payload;
        $response = (new rXMLRPCResponse(HttprpcListFixture::body($payload), false))->read();
        $this->responseTypes = $response->valueTypes;
        $this->responseValues = $response->values;
        $this->fault = $response->fault;
        $this->val = array_column($response->scalars, 1);
        return true;
    }
    public function success($trusted) { return $this->run($trusted) && !$this->fault; }
}

// The session and tied-file request the names branch sends before getSource().
class FileListBatchPaths
{
    public static $requests = array();
    public static $answer;
    public $important = true;
    public $val;
    private $commands;
    public function __construct($commands) { $this->commands = $commands; }
    public function success()
    {
        self::$requests[] = array(array_map(function($command) { return $command->command; }, $this->commands), $this->important);
        $this->val = self::$answer;
        return self::$answer !== false;
    }
}

class FileListBatchSource
{
    public static $source;
    public static $calls;
    public static function getSource($hash, $knownPaths = null)
    {
        self::$calls[] = array($hash, $knownPaths);
        if(self::$source instanceof Throwable) throw self::$source;
        return self::$source;
    }
}

$multicall = loadFunctionDefinition(__DIR__.'/../../plugins/httprpc/action.php', 'makeMulticall');
eval(str_replace(array('function makeMulticall(', 'new rXMLRPCCommand(', 'new rXMLRPCRequest('),
    array('function fileListBatchMulticall(', 'new FileListBatchCommand(', 'new FileListBatchRequest('), $multicall));
eval(loadFunctionDefinition(__DIR__.'/../../plugins/httprpc/action.php', 'httprpcFileLabel'));
eval(loadFunctionDefinition(__DIR__.'/../../plugins/httprpc/action.php', 'httprpcRows'));

/**
 * httprpc's fls mode: the rows are the daemon's, and a names=1 request gets
 * a label cell after every requested column of a row whose path is not UTF-8.
 */
class TorrentFileListBatchTest extends TestCase
{
    private function execute($payload, $source = false, $fileNames = true, $hash = null)
    {
        $endpoint = file_get_contents(__DIR__.'/../../plugins/httprpc/action.php');
        $start = strpos($endpoint, 'case "fls":');
        $branch = substr($endpoint, $start, strpos($endpoint, 'case "prs":', $start)-$start);
        $branch = self::branch($endpoint);
        FileListBatchRequest::$payload = $payload;
        FileListBatchRequest::$calls = 0;
        FileListBatchSource::$source = $source;
        FileListBatchSource::$calls = array();
        FileListBatchPaths::$requests = array();
        FileListBatchPaths::$answer = array('/session/', '/tied.torrent');
        $mode = 'fls'; $add = array();
        $hash = array($hash ?? ($source instanceof Torrent ? $source->hash_info() : str_repeat('A', 40)));
        $result = null;
        eval('switch($mode) {'.$branch.'}');
        return $result;
    }

    // The production fls branch with its daemon owners replaced.
    private static function branch($endpoint)
    {
        $start = strpos($endpoint, 'case "fls":');
        $branch = substr($endpoint, $start, strpos($endpoint, 'case "prs":', $start)-$start);
        return str_replace(array('makeMulticall(', 'rTorrent::getSource(', 'new rXMLRPCRequest(', "require_once('../../php/rtorrent.php');",
            "require_once('../../php/torrentfilenames.php');"),
            array('fileListBatchMulticall(', 'FileListBatchSource::getSource(', 'new FileListBatchPaths(', '', ''), $branch);
    }

    private function row($name) { return array($name, '1', '2', '100', '1'); }

    private function source($info)
    {
        return Torrent::fromRawBytes(TorrentEncoder::raw(array('info'=>$info + array('piece length'=>16384, 'pieces'=>str_repeat('x', 20)))));
    }

    public function testNamesRequestAppendsALabelCellAndKeepsEveryOriginalCell()
    {
        $source = $this->source(array('name'=>'collection', 'files'=>array(
            array('path'=>array("legacy\xFF"), 'path.utf-8'=>array('Серия'), 'length'=>100),
            array('path'=>array('Unicode 映画'), 'length'=>100))));
        $rows = array($this->row("legacy\xFF"), $this->row('Unicode 映画'));
        $result = $this->execute($rows, $source);
        $this->assertSame(array_merge($rows[0], array(array('displayName'=>'Серия', 'pathKey'=>'legacy%FF'))), $result[0],
            'the label cell follows the five file columns and the daemon path stays the first cell');
        $this->assertSame($rows[1], $result[1], 'a UTF-8 row gets no label cell');
        $this->assertSame(array('f.multicall'), array_map(function($command) { return $command->command; }, FileListBatchRequest::$commands),
            'the file request carries no source path commands');
        $this->assertSame(array(array($source->hash_info(), array('/session/', '/tied.torrent'))), FileListBatchSource::$calls,
            'the source is read once, from the paths its own request read, because a row needs it');
        $this->assertSame(array(array(array('get_session', 'd.get_tied_to_file'), false)), FileListBatchPaths::$requests,
            'that request is not important, like the file list: an erase race logs no fault');
    }

    public function testWithoutNamesTheRowsAreTheDaemonsRows()
    {
        $source = $this->source(array('name'=>"legacy\xFF", 'name.utf-8'=>'Серия', 'length'=>100));
        $rows = array($this->row("legacy\xFF"));
        $this->assertSame($rows, $this->execute($rows, $source, false), 'an ordinary request gets the daemon rows unchanged');
        $this->assertSame(array(), FileListBatchSource::$calls, 'and reads no source');
    }

    public function testExtraColumnsKeepTheirOffsetsBeforeTheLabelCell()
    {
        $source = $this->source(array('name'=>"legacy\xFF", 'name.utf-8'=>'Серия', 'length'=>100));
        $branch = self::branch(file_get_contents(__DIR__.'/../../plugins/httprpc/action.php'));
        FileListBatchPaths::$requests = array();
        FileListBatchPaths::$answer = array('/session/', '/tied.torrent');
        $daemonRow = array_merge($this->row("legacy\xFF"), array('plugin-value'));
        FileListBatchRequest::$payload = array($daemonRow);
        FileListBatchRequest::$calls = 0;
        FileListBatchSource::$source = $source;
        FileListBatchSource::$calls = array();
        $mode = 'fls'; $add = array('f.get_priority='); $hash = array($source->hash_info()); $fileNames = true; $result = null;
        eval('switch($mode) {'.$branch.'}');
        $this->assertSame(array_merge($daemonRow, array(array('displayName'=>'Серия', 'pathKey'=>'legacy%FF'))), $result[0],
            'a plugin column keeps its offset and the label comes after it');
        $this->assertSame('f.get_priority=', FileListBatchRequest::$commands[0]->params[7], 'the extension command follows all five original file commands');
    }

    public function testEqualLabelsKeepDistinctPathKeys()
    {
        // cp1251 "Пример" and "Привет": the JSON reply turns both into six U+FFFD.
        $one = "\xCF\xF0\xE8\xEC\xE5\xF0/a.bin"; $two = "\xCF\xF0\xE8\xE2\xE5\xF2/a.bin";
        $source = $this->source(array('name'=>'collection', 'files'=>array(
            array('path'=>explode('/', $one), 'path.utf-8'=>array('Dir', 'a.bin'), 'length'=>100),
            array('path'=>explode('/', $two), 'path.utf-8'=>array('Dir', 'a.bin'), 'length'=>100))));
        $result = $this->execute(array($this->row($one), $this->row($two)), $source);
        $this->assertSame(json_encode($one, JSON_INVALID_UTF8_SUBSTITUTE), json_encode($two, JSON_INVALID_UTF8_SUBSTITUTE),
            'the two paths are equal on the wire');
        $this->assertSame('%CF%F0%E8%EC%E5%F0/a.bin', $result[0][5]['pathKey'], 'the first key keeps its bytes and separators');
        $this->assertSame('%CF%F0%E8%E2%E5%F2/a.bin', $result[1][5]['pathKey'], 'the second key differs');
        $this->assertSame(array('Dir/a.bin', 'Dir/a.bin'), array($result[0][5]['displayName'], $result[1][5]['displayName']),
            'while their labels are equal');
    }

    public function testRequiredReplyFaultsAndMalformedRowsNeverPublishPartialFiles()
    {
        $row = $this->row("legacy\xFF");
        foreach(array('scalar', array('faultCode'=>'-1', 'faultString'=>'missing files'), array(array_slice($row, 0, 4)),
            array(array_merge($row, array('extra'))), array($row, array('partial')), array(array(array('nested'), '1', '2', '100', '1'))) as $payload) {
            $this->assertSame(array(), $this->execute($payload), 'faults, wrong widths and nested cells refuse the complete file table');
            $this->assertSame(array(), FileListBatchSource::$calls, 'a refused file table never reads source metadata');
            $this->assertSame(1, FileListBatchRequest::$calls, 'invalid required replies are not retried');
        }
    }

    public function testSourceFailuresKeepEveryFileAndShowItsBytes()
    {
        $row = $this->row("legacy\xFF");
        foreach(array(false, new RuntimeException('source failed'), new Error('disabled'),
            $this->source(array('name'=>'other', 'name.utf-8'=>'Wrong', 'length'=>100))) as $failure) {
            $result = $this->execute(array($row), $failure, true, str_repeat('A', 40));
            $this->assertSame('legacy\\xFF', $result[0][5]['displayName'] ?? null, 'an unavailable or unrelated source shows the file bytes');
            $this->assertSame($row, array_slice($result[0], 0, 5), 'a source failure never changes the file cells');
        }
    }

    public function testUtf8AndEmptyTablesNeedNoMetadataAndManyLegacyFilesNeedOneRead()
    {
        $rows = array($this->row('映画/Серия 🙂'));
        $this->assertSame($rows, $this->execute($rows), 'valid Unicode retains its original wire cells');
        $this->assertSame(array(), FileListBatchSource::$calls, 'Unicode does not need metainfo');
        $this->assertSame(array(), $this->execute(array()), 'empty file inventory remains empty');
        $rows = array_fill(0, 500, $this->row("legacy\xFF"));
        $result = $this->execute($rows);
        $this->assertSame(500, count($result), 'a large legacy list keeps every original file index');
        $this->assertSame(1, count(FileListBatchSource::$calls), 'many legacy names still read one source');
        $this->assertSame(1, FileListBatchRequest::$calls, 'and the file list is one daemon request');
    }

    public function testOnlyAnInfoHashTargetReadsTheSource()
    {
        $rows = array($this->row("x\xFF"), $this->row("y\xFF"));
        foreach(array('../source', str_repeat('A', 40).':d0', str_repeat('A', 40)."\n") as $target) {
            $result = $this->execute($rows, false, true, $target);
            $this->assertSame(array(), FileListBatchSource::$calls, 'a target that is not an info hash never reaches the source files');
            $this->assertSame('x\\xFF', $result[0][5]['displayName'], 'and its rows still show their bytes');
        }
        $this->execute($rows, false, true, str_repeat('a', 40));
        $this->assertSame(array(array(str_repeat('A', 40), array('/session/', '/tied.torrent'))), FileListBatchSource::$calls,
            'the source is asked for by the upper-case hash the daemon names it with');
        $this->execute($rows, false, true, str_repeat('A', 40));
        FileListBatchPaths::$answer = false;
        $this->execute($rows, false, true, str_repeat('A', 40));
        $this->assertSame(array(), array_slice(FileListBatchPaths::$requests, 1), 'the first lookup is the only one per request');
    }

    public function testADisabledRawurlencodeLeavesOutOnlyThePathKey()
    {
        $script = 'define("TESTLIB_HANDLER_STUBS",true); require '
            .var_export(__DIR__.'/../plugins/rutracker_check/TestLib.php', true).';'
            .' eval(loadFunctionDefinition('.var_export(__DIR__.'/../../plugins/httprpc/action.php', true).', "httprpcFileLabel"));'
            .' echo json_encode(httprpcFileLabel("dir\\xFF/file\\xFF", "Label"));';
        $result = PhpProcess::run(array_merge(PhpProcess::sameIni(), array('-d', 'disable_functions=rawurlencode', '-r', $script)));
        $this->assertSame(0, $result['code'], 'a disabled rawurlencode() does not abort the file list');
        $this->assertSame('', $result['err'], 'and leaves no diagnostics');
        $this->assertSame(array('displayName'=>'Label'), json_decode($result['out'], true), 'the label stays and no partial key is invented');
    }
}
