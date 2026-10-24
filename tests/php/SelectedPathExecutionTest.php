<?php
require_once(__DIR__.'/TestCase.php');
require_once(__DIR__.'/PhpProcess.php');

class SelectedPathExecutionTest extends TestCase
{
    private $root;

    public function setUp()
    {
        $this->root = sys_get_temp_dir().'/selected-path-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
    }

    public function tearDown()
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file)
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->root);
    }

    private function names()
    {
        return array("legacy\xFF ", "雪 ", "line\n");
    }

    private function execute($code)
    {
        $result = PhpProcess::run(array('-d', 'display_errors=stderr', '-r', $code));
        $this->assertSame(0, $result['code'], 'the actual entrypoint completes');
        $this->assertSame('', $result['err'], 'the actual entrypoint emits no diagnostics');
        $decoded = json_decode($result['out'], true);
        $this->assertTrue(is_array($decoded), 'the fixture captured its real call boundary');
        return $decoded;
    }

    // Only bootstrap imports are replaced. The entrypoint's branching, path
    // handling and calls into the captured owner run from the shipped source.
    private function entry($path)
    {
        $path = dirname(__DIR__, 2).'/'.$path;
        return '$source = file_get_contents('.var_export($path, true).');'
            .'$source = preg_replace("~^\\s*require_once[^\\n]*~m", "", $source);'
            .'$source = str_replace("__FILE__", '.var_export(var_export($path, true), true).', $source);'
            .'eval("?>".$source);';
    }

    public function testAddTorrentValidatesAndLoadsTheSelectedDirectoryUnchanged()
    {
        foreach($this->names() as $name) {
            $path = $this->root.'/'.$name;
            mkdir($path, 0700);
            $form = http_build_query(array('url'=>'magnet:?xt=urn:btih:'.str_repeat('a', 40), 'dir_edit'=>$path));
            $code = 'parse_str('.var_export($form, true).', $_POST); $_REQUEST = $_POST; $_SERVER["REQUEST_METHOD"] = "POST";'
                . <<<'PHP'
class Requests { public static function requirePost() {} }
class rTorrentSettings {
    public static function get() { return new self(); }
    public function correctDirectory(&$path) { $GLOBALS['validated'] = base64_encode($path); return is_dir($path); }
}
class rTorrent {
    public static function loadedHashes() { return array(); }
    public static function sendMagnet($url, $start, $addPath, $directory, $label, $addition) {
        $GLOBALS['loaded'] = base64_encode($directory); return false;
    }
}
register_shutdown_function(function() {
    echo json_encode(array('validated'=>$GLOBALS['validated'] ?? null, 'loaded'=>$GLOBALS['loaded'] ?? null));
});
PHP
                .$this->entry('php/addtorrent.php');
            $result = $this->execute($code);
            $this->assertSame(base64_encode($path), $result['validated'], 'directory validation sees the selected bytes');
            $this->assertSame(base64_encode($path), $result['loaded'], 'the load owner receives that same directory');
        }
    }

    public function testCreateWorkerAndSeedingUseThePersistedSelection()
    {
        foreach($this->names() as $name) {
            $path = $this->root.'/'.$name;
            mkdir($path, 0700);
            file_put_contents($path.'/payload', 'selected payload');
            mkdir(trim($path), 0700);
            file_put_contents(trim($path).'/payload', 'wrong neighbor');
            $request = array('trackers'=>'', 'path_edit'=>$path, 'piece_size'=>256,
                'private'=>false, 'start_seeding'=>true);
            file_put_contents($this->root.'/params', serialize($request));
            $code = '$fixture = '.var_export($this->root, true).'; $argv = array("create", "1"); $profileMask = 0777;'
                .'require '.var_export(dirname(__DIR__, 2).'/php/torrenttrackers.php', true).';'
                .'require '.var_export(dirname(__DIR__, 2).'/plugins/create/seeding.php', true).';'
                . <<<'PHP'
function rutorrent_require_cli($file) {}
class FileUtil {
    public static function getPluginConf($name) { return ''; }
    public static function getUniqueUploadedFilename($name) { return $GLOBALS['fixture'].'/upload.torrent'; }
    public static function addslash($path) { return rtrim($path, '/').'/'; }
}
class rTask { public static function formatPath($id) { return $GLOBALS['fixture']; } }
class Torrent {
    public $info;
    public function __construct($path, $trackers, $size, $out, $err) {
        $GLOBALS['created'] = array(base64_encode($path), file_get_contents($path.'/payload'));
        $this->info = array('name'=>basename($path));
    }
    public function save($path) { return file_put_contents($path, 'fixture torrent'); }
}
class rTorrentSettings {
    public static function get() { return new self(); }
    public function correctDirectory(&$path) { $GLOBALS['validated'] = base64_encode($path); return is_dir($path); }
}
class rTorrent {
    public static function fastResume($torrent, $directory) { $GLOBALS['resume'] = base64_encode($directory); return false; }
    public static function sendTorrent($torrent, $start, $addPath, $directory) { $GLOBALS['loaded'] = base64_encode($directory); return true; }
}
class User { public static function isLocalMode() { return true; } }
register_shutdown_function(function() {
    echo json_encode(array('created'=>$GLOBALS['created'] ?? null, 'validated'=>$GLOBALS['validated'] ?? null,
        'resume'=>$GLOBALS['resume'] ?? null, 'loaded'=>$GLOBALS['loaded'] ?? null));
});
PHP
                .$this->entry('plugins/create/createtorrent.php');
            $result = $this->execute($code);
            $this->assertSame(array(base64_encode($path), 'selected payload'), $result['created'], 'creation reads the selected directory rather than its trimmed neighbor');
            $this->assertSame(base64_encode($path.'/'), $result['validated'], 'seeding validates the original selected directory');
            $this->assertSame(base64_encode(dirname($path)), $result['resume'], 'resume receives the original parent');
            $this->assertSame($result['resume'], $result['loaded'], 'resume and load use the same parent');
        }
    }

    public function testAutoWatchScansItsPersistedDirectoryWithoutTrimming()
    {
        foreach($this->names() as $name) {
            $path = $this->root.'/'.$name;
            mkdir($path, 0700); mkdir(trim($path), 0700);
            file_put_contents($path.'/selected.torrent', 'selected bytes');
            file_put_contents(trim($path).'/neighbor.torrent', 'wrong bytes');
            $repo = dirname(__DIR__, 2);
            $code = '$watch = '.var_export($path, true).'; $download = '.var_export($this->root.'/downloads', true).';'
                .'require '.var_export($repo.'/tests/php/SourceDefinitionFixture.php', true).';'
                .'require '.var_export($repo.'/php/utility/lfs.php', true).';'
                .'foreach(array("rtAddTailSlash", "rtMkDir") as $name) eval(loadFunctionDefinition('.var_export($repo.'/php/utility/rtpluginutil.php', true).', $name));'
                .'foreach(array("rtScanFiles", "rtIsFile") as $name) eval(loadFunctionDefinition('.var_export($repo.'/plugins/autotools/util_rt.php', true).', $name));'
                . <<<'PHP'
$argv = array('watch'); $autodebug_enabled = false;
function rutorrent_require_cli($file) {}
class FileUtil { public static function getPluginConf($name) { return ''; } }
class rAutoTools {
    public static function load() { return (object) array('enable_watch'=>true, 'watch_start'=>false, 'path_to_watch'=>$GLOBALS['watch']); }
}
class rTorrentSettings {
    public static function get() { return (object) array('directory'=>$GLOBALS['download']); }
}
class rTorrent {
    public static function sendTorrent($path, $start, $addPath, $directory) {
        $GLOBALS['loaded'][] = array(base64_encode($path), file_get_contents($path)); return true;
    }
}
register_shutdown_function(function() { echo json_encode($GLOBALS['loaded'] ?? array()); });
PHP
                .$this->entry('plugins/autotools/watch.php');
            $result = $this->execute($code);
            $this->assertSame(array(array(base64_encode($path.'/selected.torrent'), 'selected bytes')), $result,
                'AutoWatch scans and loads only its configured byte path');
        }
    }

    public function testAutoToolsDiagnosticsValidateTheStoredPaths()
    {
        foreach($this->names() as $name) {
            $path = $this->root.'/'.$name;
            mkdir($path, 0700);
            $code = '$path = '.var_export($path, true).'; $session = '.var_export($this->root, true).';'
                . <<<'PHP'
$jResult = ''; $do_diagnostic = true; $plugin = array('name'=>'autotools'); $pInfo = array('perms'=>array());
class rAutoTools {
    public $enable_move = true, $enable_watch = true, $moveStatus = 'legacy', $path_to_finished, $path_to_watch;
    public static function load() { $value = new self(); $value->path_to_finished = $value->path_to_watch = $GLOBALS['path']; return $value; }
    public function setHandlers() { return true; }
    public function get() { return ''; }
    public function modeNotice() { return ''; }
}
class rTorrentSettings {
    public $session;
    public static function get() { $value = new self(); $value->session = $GLOBALS['session']; return $value; }
    public function correctDirectory(&$path) { $GLOBALS['validated'][] = base64_encode($path); return is_dir($path); }
    public function registerPlugin($name, $permissions) {}
}
class FileUtil { public static function addslash($path) { return rtrim($path, '/').'/'; } }
class FileOperationOutcome { public static function sessionNotice($plugin) { return ''; } }
$theSettings = rTorrentSettings::get();
PHP
                .$this->entry('plugins/autotools/init.php').'; echo json_encode(array("paths"=>$validated, "result"=>$jResult));';
            $result = $this->execute($code);
            $this->assertSame(array(base64_encode($path), base64_encode($path)), $result['paths'], 'both diagnostics validate the stored path bytes');
            $this->assertSame('', $result['result'], 'an existing selected directory produces no false missing-path warning');
        }
    }
}
