<?php

define('TESTLIB_HANDLER_STUBS', true);
require_once(__DIR__.'/../plugins/rutracker_check/TestLib.php');
require_once(__DIR__.'/../../php/torrentfilenames.php');
require_once(__DIR__.'/../../plugins/httprpc/rpccache.php');
require_once(__DIR__.'/../../php/utility/json.php');
require_once(__DIR__.'/PhpProcess.php');
require_once(__DIR__.'/HttprpcListFixture.php');
require_once(__DIR__.'/../../php/xmlrpc_response.php');
require_once(__DIR__.'/../../php/utility/fileutil.php');
require_once(__DIR__.'/../../php/utility/utf.php');

class TorrentTitleCache extends rpcCache
{
    public static $previous = array();
    public static $stored;
    public function __construct() {}
    protected function load($cid) { return self::$previous; }
    protected function store($torrents = array()) { self::$stored = $torrents; return 123; }
}

class TorrentTitleSource
{
    public static $source;
    public static $calls = array();
    public static $paths = array();
    public static function getSource($hash, &$sourcePath = null, $knownPaths = null)
    {
        self::$calls[] = $hash;
        self::$paths[] = $knownPaths;
        if($knownPaths === null) throw new RuntimeException('unexpected per-title RPC lookup');
        if(self::$source instanceof Throwable) throw self::$source;
        return self::$source;
    }
}

class TorrentTitleRequest
{
    public static $values;
    public static $command;
    public static $calls;
    public $val;
    public $responseTypes = array('array');
    public $responseValues;
    public $fault = false;
    public $faultString = '';
    public function __construct($command) { self::$command = $command; $this->val = array(); $this->responseValues = self::$values; }
    public function run($trusted)
    {
        self::$calls++;
        $response = (new rXMLRPCResponse(HttprpcListFixture::body(self::$values[0]), true))->read();
        $this->responseTypes = $response->valueTypes;
        $this->responseValues = $response->values;
        return true;
    }
    public function success($parse) { return true; }
}

class TorrentTitleCommand extends rXMLRPCCommand
{
    public function __construct($command, $parameter = null) { $this->command = $command; $this->params = $parameter === null ? array() : array($parameter); }
    public function addParameter($parameter, $aType = null) { $this->params[] = $parameter; }
    public function addParameters($parameters)
    {
        foreach($parameters as $parameter) $this->addParameter($parameter);
    }
}

class TorrentTitlesTest extends TestCase
{
    private function source($name, $alias = null, $encoding = null)
    {
        $meta = array('info'=>array('name'=>$name, 'length'=>100));
        if($alias !== null) $meta['info']['name.utf-8'] = $alias;
        if($encoding !== null) $meta['encoding'] = $encoding;
        return Torrent::fromRawBytes(TorrentEncoder::raw($meta));
    }

    private function row($name)
    {
        $row = array_fill(0, 36, '0');
        $row[4] = $name;
        $row[25] = '/downloads/'.$name;
        $row[35] = 'plugin-column';
        return $row;
    }

    private function response($rows, $source, $previous = array(), $noMessage = false, $optional = null, $primary = null)
    {
        $endpoint = file_get_contents(__DIR__.'/../../plugins/httprpc/action.php');
        if(!function_exists('httprpcPathValues')) {
            $helper = strpos($endpoint, 'function httprpcPathValues(');
            eval(substr($endpoint, $helper, strpos($endpoint, "\n\n\$result = null;", $helper)-$helper));
        }
        $start = strpos($endpoint, 'case "list":');
        $end = strpos($endpoint, 'case "fls":', $start);
        $branch = substr($endpoint, $start, $end-$start);
        $branch = str_replace(array('new rpcCache()', 'new rXMLRPCRequest(', 'new rXMLRPCCommand(', 'rTorrent::getSource(',
            "require_once(__DIR__.'/../../php/rtorrent.php');"),
            array('new TorrentTitleCache()', 'new TorrentTitleRequest(', 'new TorrentTitleCommand(', 'TorrentTitleSource::getSource(', ''), $branch);
        $list = array(); $paths = array();
        foreach($rows as $hash=>$row) {
            $list[] = array_merge(array($hash), $row);
            $paths[] = array($hash, '/tied/'.$hash.'.torrent');
        }
        TorrentTitleRequest::$values = array(array(array($list), array($paths), array('/session/')));
        if($optional !== null) TorrentTitleRequest::$values[0] = array_merge(array(array($list)), $optional);
        if($primary !== null) TorrentTitleRequest::$values[0][0] = $primary;
        TorrentTitleRequest::$calls = 0;
        TorrentTitleSource::$source = $source;
        TorrentTitleSource::$calls = array();
        TorrentTitleSource::$paths = array();
        TorrentTitleCache::$previous = $previous;
        TorrentTitleCache::$stored = null;
        $mode = 'list'; $cid = 1; $add = array('d.get_custom3=');
        $result = null;
        eval('switch($mode) {'.$branch.'}');
        return $result;
    }

    public function testAttestedTitleAliasKeepsEveryRawCacheAndPathValue()
    {
        $raw = hex2bin('d1e5f0e8ff');
        $source = $this->source($raw, 'Серия');
        $hash = $source->hash_info();
        $row = $this->row($raw);
        $result = $this->response(array($hash=>$row), $source);
        $this->assertSame(array('name'=>'Серия', 'raw'=>$raw), $result['t'][$hash][4], 'exact source alias supplies display while the wire name remains available for path comparison');
        $this->assertSame(array($hash=>$row), TorrentTitleCache::$stored, 'server cache receives only original daemon values');
        unset($row[4]); $actual = $result['t'][$hash]; unset($actual[4]);
        $this->assertSame($row, $actual, 'every other field, base path and extension slot retain the original value');
        $this->assertSame(array($hash), TorrentTitleSource::$calls, 'one emitted legacy title makes one metadata lookup');
        $this->assertSame(1, $result['full'], 'a cache miss still marks a full response');
        $wire = json_decode(JSON::safeEncode($result), true)['t'][$hash];
        $this->assertSame('Серия', $wire[4]['name'], 'the actual JSON owner preserves the resolved label');
        // Match the browser's lastIndexOf('/') + substring; PHP 7.4 basename()
        // drops non-ASCII bytes under LC_CTYPE=C, unlike that UI comparison.
        $lastComponent = substr($wire[25], strrpos($wire[25], '/') + 1);
        $this->assertSame($lastComponent, $wire[4]['raw'], 'the actual JSON owner substitutes legacy name and base path equally, preserving their prior comparison');
    }

    public function testSparseDiffSkipsUnchangedNamesAndPreservesDeletions()
    {
        $raw = "legacy\xFF";
        $source = $this->source($raw, 'Display'); $hash = $source->hash_info();
        $old = $this->row($raw); $new = $old; $new[11] = '1234';
        $deleted = str_repeat('0', 40);
        $result = $this->response(array($hash=>$new), $source, array($hash=>$old, $deleted=>$old));
        $this->assertSame(array($hash=>array(11=>'1234')), $result['t'], 'a rate-only change keeps the same sparse index contract');
        $this->assertSame(array(), TorrentTitleSource::$calls, 'unchanged legacy names do not read metadata again');
        $this->assertSame(array($deleted), $result['d'], 'deleted hashes still accompany the sparse rows');
        $this->assertSame(false, isset($result['full']), 'a known cache remains a delta');
        $old[4] = 'old-title';
        $result = $this->response(array($hash=>$new), $source, array($hash=>$old));
        $this->assertSame(array(4, 11), array_keys($result['t'][$hash]), 'a changed title retains its sparse field index');
        $this->assertSame('Display', $result['t'][$hash][4]['name'], 'changed titles receive a complete new envelope');
    }

    public function testValidUnicodeNeedsNoSourceAndKeepsItsStringShape()
    {
        $hash = str_repeat('A',40); $row = $this->row('映画/Фильм 🙂');
        $result = $this->response(array($hash=>$row), new Error('unavailable'));
        $this->assertSame($row, $result['t'][$hash], 'already valid Unicode is unchanged');
        $this->assertSame(array(), TorrentTitleSource::$calls, 'UTF8 title display never needs source metadata');
    }

    public function testPathProjectionUsesRawNamesAndFollowsRelevantSparseUpdates()
    {
        $hash = str_repeat('A', 40);
        $row = $this->row("\xFF");
        $row[25] = "/base%2F\\path\xFD/\xFE";
        $result = $this->response(array($hash=>$row), false);
        $paths = $result['p'][$hash];
        $this->assertSame(rawurlencode($row[25]), $paths['base']['pathKey'], 'base identity preserves percent, backslash and each distinct raw byte');
        $this->assertSame($paths['base'], $paths['save'], 'different raw basenames stay distinct even if JSON replaces both with U+FFFD');
        $this->assertSame(array($hash=>$row), TorrentTitleCache::$stored, 'the projection never enters the raw cache');
        $previous = $row; $row[4] = "\xFE";
        $result = $this->response(array($hash=>$row), false, array($hash=>$previous));
        $this->assertSame(rawurlencode("/base%2F\\path\xFD"), $result['p'][$hash]['save']['pathKey'], 'a name-only delta refreshes the exact raw parent relation');
        $previous = $row; $row[11] = '99';
        $result = $this->response(array($hash=>$row), false, array($hash=>$previous));
        $this->assertSame(false, isset($result['p']), 'rate-only updates leave the remembered path alone');
        $previous = $row; $row[25] = '/valid/path';
        $result = $this->response(array($hash=>$row), false, array($hash=>$previous));
        $this->assertSame(false, isset($result['p']), 'an emitted valid path clears the old client projection without changing scalar cells');
    }

    public function testCoreNoMessagePreferenceKeepsItsOriginalCommandSlot()
    {
        $hash = str_repeat('A',40);
        $this->response(array($hash=>$this->row('valid')), false, array(), true);
        $this->assertSame('d.get_custom5=', TorrentTitleRequest::$command[0]->params[31], 'the former XML preference retains its harmless custom field in the message slot');
        $this->response(array($hash=>$this->row('valid')), false);
        $this->assertSame('d.get_message=', TorrentTitleRequest::$command[0]->params[31], 'ordinary httprpc message command remains unchanged');
        $endpoint = file_get_contents(__DIR__.'/../../plugins/httprpc/action.php');
        $start = strpos($endpoint, '$mode = "raw";');
        $end = strpos($endpoint, '// The settings object', $start);
        $parser = substr($endpoint, $start, $end-$start);
        foreach(array('1'=>true, '0'=>false, 'true'=>false, 'yes'=>false, '01'=>false) as $flag=>$expected) {
            $HTTP_RAW_POST_DATA = 'mode=list&nomsg='.$flag;
            $noMessage = null;
            eval($parser);
            $this->assertSame($expected, $noMessage, 'the mode flag accepts only an explicit boolean 1');
        }
    }

    public function testMissingMismatchedOrThrowingSourceCannotLoseTheList()
    {
        $raw = "legacy\xFF"; $hash = str_repeat('A',40); $row = $this->row($raw);
        foreach(array(false, null, new stdClass(), new RuntimeException('source failed'), new Error('disabled'),
            $this->source($raw, 'wrong-hash')) as $failure) {
            $result = $this->response(array($hash=>$row), $failure);
            $this->assertSame(array('name'=>'legacy\\xFF', 'raw'=>$raw), $result['t'][$hash][4], 'failure retains visibly escaped title and original wire name');
            $this->assertSame(1, count($result['t']), 'optional display metadata never removes a live torrent');
        }
        $source = $this->source("other\xFF", 'wrong-name', 'Windows-1251');
        $result = $this->response(array($source->hash_info()=>$row), $source);
        $this->assertSame('legacy\\xFF', $result['t'][$source->hash_info()][4]['name'], 'matching hash alone cannot attest a changed daemon title');
    }

    public function testDeclaredEncodingAndAliasValidationUseTheSharedFallback()
    {
        $raw = hex2bin('d1e5f0e8ff');
        foreach(array(null, '', '.', '..', 'a/b', "bad\0name", "bad\xFF", array('name')) as $alias) {
            $source = $this->source($raw, $alias, 'Windows-1251');
            $result = $this->response(array($source->hash_info()=>$this->row($raw)), $source);
            $this->assertSame('Серия', $result['t'][$source->hash_info()][4]['name'], 'invalid aliases use the exact declared-encoding roundtrip');
        }
        $source = $this->source($raw, 'Alias', 'Windows-1251');
        $result = $this->response(array($source->hash_info()=>$this->row($raw)), $source);
        $this->assertSame('Alias', $result['t'][$source->hash_info()][4]['name'], 'an attested valid alias wins over encoding');
    }

    public function testDisabledHashFunctionsCannotAttestAnUnrelatedSource()
    {
        $source = $this->source("legacy\xFF", 'ALIAS');
        $script = 'error_reporting(0); define("TESTLIB_HANDLER_STUBS",true); require '
            .var_export(__DIR__.'/../plugins/rutracker_check/TestLib.php', true).'; require '
            .var_export(__DIR__.'/../../php/torrentfilenames.php', true).';'
            .' $source=Torrent::fromRawBytes(base64_decode('.var_export(base64_encode((string)$source), true).'));'
            .' $hash=str_repeat("A",40); $name="legacy\\xFF";'
            .' echo json_encode(array(TorrentFileNames::title($name,$hash,$source)["name"],'
            .' TorrentFileNames::resolve(array(array($name,"0","0","100","1")),$hash,$source)[0][0]["name"]));';
        foreach(array('strtoupper', 'sha1', 'strcasecmp') as $disabled) {
            $result = PhpProcess::run(array_merge(PhpProcess::sameIni(), array('-d', 'disable_functions='.$disabled, '-r', $script)));
            $this->assertSame(0, $result['code'], 'disabled hash functions do not abort optional display resolution');
            $this->assertSame('', $result['err'], 'the disabled-function probe has no hidden diagnostics');
            $this->assertSame(array('legacy\\xFF', 'legacy\\xFF'), json_decode($result['out'], true), 'NULL or partial hashes never attest either title or file aliases');
        }
    }

    public function testListAndSourcePathsShareOneRequestAndOptionalFailuresKeepTheList()
    {
        $source = $this->source("legacy\xFF", 'Alias'); $hash = $source->hash_info();
        $rows = array($hash=>$this->row("legacy\xFF"));
        $result = $this->response($rows, $source);
        $this->assertSame(1, TorrentTitleRequest::$calls, 'main list and optional source paths use one daemon roundtrip');
        $this->assertSame(array('d.multicall', 'd.multicall', 'get_session'), array_map(function($command) { return $command->command; }, TorrentTitleRequest::$command), 'the fixed batch does not grow per torrent');
        $this->assertSame(array(array('/session/', '/tied/'.$hash.'.torrent')), TorrentTitleSource::$paths, 'source owner receives its paths without a second RPC');
        foreach(array(array(), array(array('faultCode'=>'-1', 'faultString'=>'missing'), array('/session/')),
            array(array(array(array($hash, '/tied/file'))), array('faultCode'=>'-1', 'faultString'=>'missing')),
            array(array(array(array($hash))), array('/session/')), array(array('not-a-table'), array(7)),
            array(array(array(array($hash, '/tied/file'))), '/session/'),
            array(array(array(array($hash, '/tied/file'))), array(array('/session/'))),
            array(array(array(array($hash, '/tied/file', 'extra'))), array('/session/'))) as $optional) {
            $result = $this->response($rows, false, array(), false, $optional);
            $this->assertSame(array($hash), array_keys($result['t']), 'missing, faulted or malformed optional results never become list rows or erase valid rows');
            $this->assertSame('legacy\\xFF', $result['t'][$hash][4]['name'], 'unavailable source paths keep visible fallback');
            $this->assertSame(1, TorrentTitleRequest::$calls, 'optional failures never replay or extend the daemon request');
            $this->assertSame(array(false), TorrentTitleSource::$paths, 'explicitly unavailable paths cannot trigger a fallback RPC');
        }
        $result = $this->response($rows, $source, array());
        $this->assertSame('Alias', $result['t'][$hash][4]['name'], 'an explicit full refresh recovers a title after metadata becomes available');
    }

    public function testMalformedRequiredListCannotPublishOrCacheAPartialInventory()
    {
        $hash = str_repeat('A', 40); $row = array_merge(array($hash), $this->row('valid'));
        $nested = $row; $nested[5] = array('nested-name');
        foreach(array('scalar', array('faultCode'=>'-1', 'faultString'=>'missing list'),
            array(array(array_slice($row, 0, -1))), array(array($row, array('partial'))), array(array($nested))) as $primary) {
            $this->assertSame(null, $this->response(array(), false, array(), false, null, $primary), 'invalid required result refuses the whole list');
            $this->assertSame(null, TorrentTitleCache::$stored, 'a partial inventory never replaces the existing cache');
            $this->assertSame(array(), TorrentTitleSource::$calls, 'invalid main rows never invoke optional source reads');
        }
    }
}
