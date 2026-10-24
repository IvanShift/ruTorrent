<?php
require_once __DIR__.'/TestCase.php';
require_once __DIR__.'/../../php/utility/fileutil.php';
require_once __DIR__.'/../../php/utility/utf.php';

class DirectorySavePathCommand
{
    public $name;
    public function __construct($name, $hash) { $this->name = $name; }
}

class DirectorySavePathRequest
{
    public static $replies;
    public static $calls;
    public $val;
    public function __construct($commands)
    {
        self::$calls[] = array_map(function($command) { return $command->name; },
            is_array($commands) ? $commands : array($commands));
        $this->val = array_shift(self::$replies);
    }
    public function success($trusted) { return is_array($this->val); }
}

class TorrentSavePathsTest extends TestCase
{
    private function response($open, $values)
    {
        $source = file_get_contents(__DIR__.'/../../plugins/httprpc/action.php');
        if(!function_exists('httprpcPathValues')) {
            $start = strpos($source, 'function httprpcPathValues(');
            eval(substr($source, $start, strpos($source, "\n\n\$result = null;", $start)-$start));
        }
        if(!function_exists('validTorrentHashBatch')) {
            $start = strpos($source, 'function validTorrentHashBatch(');
            eval(substr($source, $start, strpos($source, "\n}\n", $start)+3-$start));
        }
        $start = strpos($source, 'case "getsavepath":');
        $branch = substr($source, $start, strpos($source, 'case "start":', $start)-$start);
        $branch = str_replace(array('new rXMLRPCRequest(', 'new rXMLRPCCommand('),
            array('new DirectorySavePathRequest(', 'new DirectorySavePathCommand('), $branch);
        DirectorySavePathRequest::$replies = array(array($open), $values);
        DirectorySavePathRequest::$calls = array();
        $result = null; $mode = 'getsavepath'; $hash = array(str_repeat('A', 40)); $malformedForm = false;
        eval('switch($mode) {'.$branch.'}');
        return $result;
    }

    public function testOpenAndClosedReadsCompareExactDaemonBytes()
    {
        $base = "/literal%2F\\path\xFF/name\xFE";
        foreach(array('0', '1') as $open) {
            foreach(array("name\xFE", "name\xFD") as $name) {
                $result = $this->response($open, $open === '1' ? array($base, $name) : array('0', $base, $name, '0'));
                $this->assertSame(array('0', $base, '0'), array_slice($result, 0, 3), 'the existing numeric response cells keep their original values');
                $this->assertSame(rawurlencode($base), $result[3]['base']['pathKey'], 'the full raw path is recoverable');
                $save = $name === "name\xFE" ? "/literal%2F\\path\xFF" : $base;
                $this->assertSame(rawurlencode($save), $result[3]['save']['pathKey'], 'distinct invalid names are compared before JSON substitution');
                $this->assertSame(array('d.is_open'), DirectorySavePathRequest::$calls[0], 'state is observed before any open/close');
                $expected = $open === '1' ? array('d.get_base_path', 'd.get_name') : array('d.open', 'd.get_base_path', 'd.get_name', 'd.close');
                $this->assertSame($expected, DirectorySavePathRequest::$calls[1], 'the owner preserves an open torrent and closes only its own temporary open');
            }
        }
    }

    public function testValidPathsKeepTheirShapeAndPartialRepliesRefuse()
    {
        $this->assertSame(array('0', '/映画/%2F\\name', '0'), $this->response('1', array('/映画/%2F\\name', '%2F\\name')), 'valid UTF8 paths need no sidecar');
        foreach(array(false, null, array(), array('one'), array('a', 'b', 'c')) as $partial)
            $this->assertSame(null, $this->response('1', $partial), 'an unavailable or partial read is not presented as a usable path');
    }
}
