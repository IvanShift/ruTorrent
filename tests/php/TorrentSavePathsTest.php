<?php

require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../../php/utility/fileutil.php';
require_once __DIR__ . '/../../php/utility/utf.php';

class DirectorySavePathCommand
{
    public $name;
    public function __construct($name, $hash)
    {
        $this->name = $name;
    }
}

class DirectorySavePathRequest
{
    public static $replies;
    public static $calls;
    public static $basePath;
    public $val;
    public function __construct($commands)
    {
        self::$calls[] = array_map(
            function ($command) {
                return $command->name;
            },
            is_array($commands) ? $commands : [$commands],
        );
        $this->val = array_shift(self::$replies);
    }
    public function success($trusted)
    {
        return is_array($this->val);
    }
    // Stands in for rTorrent::getBasePath(), which RtorrentFilePathTest and
    // ClosedPathReadTest cover.
    public static function basePath($hash)
    {
        self::$calls[] = ['basePath'];
        return self::$basePath;
    }
}

class TorrentSavePathsTest extends TestCase
{
    public function testInvalidPathsCompareExactDaemonBytes()
    {
        $base = "/literal%2F\\path\xFF/name\xFE";
        foreach (["name\xFE", "name\xFD"] as $name) {
            $result = $this->response($base, [[$name]]);
            $this->assertSame(['0', $base, '0'], array_slice($result, 0, 3), 'the existing numeric response cells keep their original values');
            $this->assertSame(rawurlencode($base), $result[3]['base']['pathKey'], 'the full raw path is recoverable');
            $save = $name === "name\xFE" ? "/literal%2F\\path\xFF" : $base;
            $this->assertSame(rawurlencode($save), $result[3]['save']['pathKey'], 'distinct invalid names are compared before JSON substitution');
            $this->assertSame([['basePath'], ['d.get_name']], DirectorySavePathRequest::$calls, 'the name is read only for a path that is not UTF-8');
        }
    }

    public function testValidPathsKeepTheirShapeAndPartialRepliesRefuse()
    {
        $this->assertSame(['0', '/映画/%2F\\name', '0'], $this->response('/映画/%2F\\name', []), 'valid UTF8 paths need no sidecar');
        $this->assertSame([['basePath']], DirectorySavePathRequest::$calls, 'and no name');
        $this->assertSame(null, $this->response(false, [['name']]), 'an unavailable path is not presented as a usable path');
        foreach ([false, null, [], ['a', 'b']] as $partial) {
            $this->assertSame(null, $this->response("/x\xFF", [$partial]), 'an unavailable or partial name read is not presented as a usable path');
        }
    }
    private function response($basePath, $nameReplies)
    {
        $source = file_get_contents(__DIR__ . '/../../plugins/httprpc/action.php');
        if (!function_exists('httprpcPathValues')) {
            $start = strpos($source, 'function httprpcPathValues(');
            eval(substr($source, $start, strpos($source, "\n\n\$result = null;", $start) - $start));
        }
        if (!function_exists('validTorrentHashBatch')) {
            $start = strpos($source, 'function validTorrentHashBatch(');
            eval(substr($source, $start, strpos($source, "\n}\n", $start) + 3 - $start));
        }
        $start = strpos($source, 'case "getsavepath":');
        $branch = substr($source, $start, strpos($source, 'case "start":', $start) - $start);
        $branch = preg_replace('/^\s*require_once\(.*$/m', '', $branch);
        $branch = str_replace(
            ['new rXMLRPCRequest(', 'new rXMLRPCCommand(', 'rTorrent::getBasePath('],
            ['new DirectorySavePathRequest(', 'new DirectorySavePathCommand(', 'DirectorySavePathRequest::basePath('],
            $branch,
        );
        DirectorySavePathRequest::$basePath = $basePath;
        DirectorySavePathRequest::$replies = $nameReplies;
        DirectorySavePathRequest::$calls = [];
        $result = null;
        $mode = 'getsavepath';
        $hash = [str_repeat('A', 40)];
        $malformedForm = false;
        eval('switch($mode) {' . $branch . '}');
        return $result;
    }
}
