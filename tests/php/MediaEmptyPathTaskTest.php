<?php
require_once(__DIR__ . '/TestCase.php');

class MediaEmptyPathTaskTest extends TestCase
{
    private $root;
    private $scratch;

    public function setUp()
    {
        $this->root = dirname(__DIR__, 2);
        $this->scratch = sys_get_temp_dir() . '/rt-media-empty-' . getmypid() . '-' . bin2hex(random_bytes(4));
        foreach (array('php', 'plugins/_task', 'plugins/screenshots', 'plugins/spectrogram', 'plugins/mediainfo') as $dir)
            mkdir($this->scratch . '/' . $dir, 0777, true);
        foreach (array('plugins/screenshots/action.php', 'plugins/screenshots/ffmpeg.php',
            'plugins/spectrogram/action.php', 'plugins/mediainfo/action.php') as $file)
            copy($this->root . '/' . $file, $this->scratch . '/' . $file);
        copy($this->root . '/php/rtorrent.php', $this->scratch . '/php/rtorrent.php');
        foreach (array('util.php', 'xmlrpc.php', 'Torrent.php') as $stub)
            file_put_contents($this->scratch . '/php/' . $stub, "<?php\n");
        file_put_contents($this->scratch . '/php/settings.php', "<?php\n");
        file_put_contents($this->scratch . '/plugins/_task/task.php', <<<'PHPSTUB'
<?php
class Requests { public static function requirePost() {} }
class FileUtil {
    public static function getPluginConf($name) { return $name === 'spectrogram' ? '$arguments = "";' : ''; }
    public static function getFileName($name) { return basename($name); }
}
class CachedEcho { public static function send($value, $type) { echo $value; } }
class JSON { public static function safeEncode($value) { return json_encode($value); } }
class Utility { public static function getExternal($name) { return $name; } }
class rCache { public function get($item) { return false; } }
class rXMLRPCCommand { public function __construct($name, $args = null) {} }
class rXMLRPCRequest {
    private static $calls = 0;
    public $val = array();
    public function __construct($command) {}
    public function success() {
        self::$calls++;
        if (self::$calls === 1) {
            $this->val = array(getenv('RPC_CASE') === 'present' ? '/synthetic/media' : '');
            return true;
        }
        if (getenv('RPC_CASE') === 'fallback-failure') return false;
        $this->val = array('', '');
        return true;
    }
}
class rTask {
    const FLG_NO_ERR = 16;
    const FLG_ONE_LOG = 4;
    const FLG_STRIP_LOGS = 2;
    public function __construct($params) {}
    public function start($commands, $flags) {
        $count = count($commands);
        file_put_contents(getenv('TASK_TRACE'), (string)$count);
        return $count;
    }
}
PHPSTUB
        );
        file_put_contents($this->scratch . '/request.php', <<<'PHPREQUEST'
<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array('cmd' => getenv('ROUTE_CMD'), 'hash' => str_repeat('A', 40), 'no' => '0');
$_REQUEST = $_POST;
require getenv('ROUTE_FILE');
PHPREQUEST
        );
    }

    public function tearDown()
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->scratch, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry)
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->scratch);
    }

    private function request($plugin, $command, $rpcCase)
    {
        $env = array(
            'ROUTE_FILE' => $this->scratch . '/plugins/' . $plugin . '/action.php',
            'ROUTE_CMD' => $command,
            'RPC_CASE' => $rpcCase,
            'TASK_TRACE' => $this->scratch . '/task-started',
        );
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr',
            $this->scratch . '/request.php'),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes, null, $env);
        if (!is_resource($process)) throw new RuntimeException('media route child did not start');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return array(proc_close($process), $out, $err);
    }

    public function testEmptyPathSkipsTaskAcrossFallbacks()
    {
        foreach (array(array('screenshots', 'ffmpeg'), array('spectrogram', 'sox'),
            array('mediainfo', 'mediainfo')) as $route) {
            foreach (array('fallback-empty', 'fallback-failure') as $rpcCase) {
                @unlink($this->scratch . '/task-started');
                list($exit, $out, $err) = $this->request($route[0], $route[1], $rpcCase);
                $label = $route[1] . ' ' . $rpcCase;
                $this->assertSame(0, $exit, $label . ' exits cleanly: ' . $err);
                $this->assertSame('', $err, $label . ' emits no PHP warning');
                $expected = $route[0] === 'mediainfo' ? 255 : array();
                $actual = json_decode($out, true);
                $this->assertSame($expected, $route[0] === 'mediainfo' ? $actual['status'] : $actual,
                    $label . ' returns no task');
                $this->assertTrue(!is_file($this->scratch . '/task-started'), $label . ' never starts a task');
            }
        }
    }

    public function testNonemptyPathStillStartsTask()
    {
        foreach (array(array('screenshots', 'ffmpeg'), array('spectrogram', 'sox'),
            array('mediainfo', 'mediainfo')) as $route) {
            @unlink($this->scratch . '/task-started');
            list($exit, $out, $err) = $this->request($route[0], $route[1], 'present');
            $this->assertSame(0, $exit, $route[1] . ' positive path exits cleanly: ' . $err);
            $this->assertSame('', $err, $route[1] . ' positive path emits no PHP warning');
            $this->assertTrue((int)$out > 0 && (int)file_get_contents($this->scratch . '/task-started') > 0,
                $route[1] . ' positive path still starts its task');
        }
    }
}
