<?php
// Execute the shipped source action with the real rTorrent/Torrent classes and
// a deterministic RPC peer and a ZIP fault double under PHP -n.
define('TESTLIB_HANDLER_STUBS', true);
require_once(__DIR__ . '/../plugins/rutracker_check/TestLib.php');
eval(loadClassDefinition(__DIR__ . '/../../php/rtorrent.php', 'rTorrent'));

if (class_exists('ZipArchive', false))
    throw new RuntimeException('source ZIP fixture needs PHP -n for deterministic failure injection');
class SourceExportZipArchive
{
    const CREATE = 1;
    public $numFiles = 0;
    private $path;
    private $entries = array();
    private $writing = false;
    public function open($path, $flags = 0)
    {
        $this->path = $path;
        $this->writing = (bool)($flags & self::CREATE);
        if ($this->writing) {
            file_put_contents($path, 'incomplete ZIP');
            if (getenv('SOURCE_EXPORT_CASE') === 'open') return false;
        } elseif (is_file($path)) {
            $entries = @unserialize(file_get_contents($path));
            if (!is_array($entries)) return false;
            $this->entries = $entries;
        }
        $this->numFiles = count($this->entries);
        return true;
    }
    public function addFile($path, $name)
    {
        if (getenv('SOURCE_EXPORT_CASE') === 'add-file') {
            unlink($path); // The ordinary source disappeared after getSource().
            return false;
        }
        $this->entries[$name] = file_get_contents($path);
        if (getenv('SOURCE_EXPORT_CASE') === 'close')
            unlink($path); // The source disappears after addFile() accepts it.
        $this->numFiles = count($this->entries);
        return true;
    }
    public function addFromString($name, $bytes)
    {
        if (getenv('SOURCE_EXPORT_CASE') === 'add-string') return false;
        $this->entries[$name] = $bytes;
        $this->numFiles = count($this->entries);
        return true;
    }
    public function close()
    {
        if (!$this->writing) return true;
        if (getenv('SOURCE_EXPORT_CASE') === 'close') return false;
        return file_put_contents($this->path, serialize($this->entries)) !== false;
    }
    public function getNameIndex($index) { return array_keys($this->entries)[$index]; }
    public function getFromIndex($index) { return array_values($this->entries)[$index]; }
}
class_alias('SourceExportZipArchive', 'ZipArchive');
class SendFile
{
    public static function send($path, $mime, $name = null, $remove = true)
    {
        file_put_contents(getenv('SOURCE_EXPORT_SCRATCH') . '/send.called', '1');
        if (getenv('SOURCE_EXPORT_CASE') === 'send') return false;
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return false;
        $entries = array();
        for ($i = 0; $i < $zip->numFiles; ++$i)
            $entries[$zip->getNameIndex($i)] = base64_encode($zip->getFromIndex($i));
        $body = json_encode($entries);
        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) return true;
        if (isset($_SERVER['HTTP_RANGE'])) $body = substr($body, 0, 5);
        echo $body;
        $zip->close();
        return true;
    }
}
class CachedEcho
{
    public static function send($value, $type = null) { echo $value; exit; }
}

$scratch = getenv('SOURCE_EXPORT_SCRATCH');
$path = getenv('SOURCE_EXPORT_PATH');
$hash = getenv('SOURCE_EXPORT_HASH');
$log_file = getenv('SOURCE_EXPORT_LOG');
$tempDirectory = $scratch . '/zip-temp';
$profileMask = 0600;
foreach (array($tempDirectory, $scratch . '/php', $scratch . '/plugins/source',
    $scratch . '/plugins/dump', $scratch . '/plugins/_task') as $dir)
    if (!is_dir($dir)) mkdir($dir, 0700, true);
file_put_contents($scratch . '/php/rtorrent.php', "<?php\n");
copy(__DIR__ . '/../../plugins/source/action.php', $scratch . '/plugins/source/action.php');
copy(__DIR__ . '/../../plugins/dump/action.php', $scratch . '/plugins/dump/action.php');
if (getenv('SOURCE_EXPORT_DUMP_ARGS') !== false || getenv('SOURCE_EXPORT_RAW_ARGS') !== false) {
    // Simulate conf.local.php after the real bundled config, without writing to the repository.
    $actionPath = $scratch . '/plugins/dump/action.php';
    $action = file_get_contents($actionPath);
    $needle = "eval( FileUtil::getPluginConf( 'dump' ) );";
    if (substr_count($action, $needle) !== 1)
        throw new RuntimeException('dump config injection point changed');
    $override = '';
    if (getenv('SOURCE_EXPORT_DUMP_ARGS') !== false)
        $override .= "\n" . '$arguments = getenv("SOURCE_EXPORT_DUMP_ARGS");';
    if (getenv('SOURCE_EXPORT_RAW_ARGS') !== false)
        $override .= "\n" . '$rawMetadataArguments = getenv("SOURCE_EXPORT_RAW_ARGS");';
    file_put_contents($actionPath, str_replace($needle, $needle . $override, $action));
}
file_put_contents($scratch . '/plugins/_task/task.php', "<?php\n");
rXMLRPCRequest::queue(array('get_session', 'd.get_tied_to_file'), true, false,
    array(dirname($path) . '/', $path));
if (getenv('SOURCE_EXPORT_ROUTE') !== 'dump')
    rXMLRPCRequest::queue(array('get_session', 'd.get_tied_to_file'), true, false,
        array('', getenv('SOURCE_EXPORT_SECOND_PATH') ?: ''));
elseif (strcasecmp(basename($path), $hash . '.meta') === 0) {
    $revalidated = getenv('SOURCE_EXPORT_REVALIDATED_PATH') ?: $path;
    rXMLRPCRequest::queue(array('get_session', 'd.get_tied_to_file'), true, false,
        array(dirname($revalidated) . '/', $revalidated));
}
$_SERVER['REQUEST_METHOD'] = 'POST';
if (getenv('SOURCE_EXPORT_CASE') === 'conditional')
    $_SERVER['HTTP_IF_MODIFIED_SINCE'] = gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT';
if (getenv('SOURCE_EXPORT_CASE') === 'range')
    $_SERVER['HTTP_RANGE'] = 'bytes=0-4';
$_POST = array('hash' => $hash . ' ' . str_repeat('A', 40));
$_REQUEST = $_POST;
if (getenv('SOURCE_EXPORT_ROUTE') === 'dump') {
    class rTask {
        const FLG_DO_NOT_TRIM = 0x0400;
        public function __construct($params) {}
        public function start($commands, $flags) { return array('commands' => $commands, 'flags' => $flags); }
    }
    $_POST = array('cmd' => 'dumptorrent', 'hash' => $hash);
    $_REQUEST = $_POST;
    chdir($scratch . '/plugins/dump');
} else {
    chdir($scratch . '/plugins/source');
}
require 'action.php';
