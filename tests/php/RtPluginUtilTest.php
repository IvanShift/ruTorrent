<?php

require_once(__DIR__ . '/TestCase.php');

/** Load each real plugin utility entrypoint in its own PHP process. */
class RtPluginUtilTest extends TestCase
{
    private $root;
    private $temp;

    public function setUp()
    {
        $this->root = realpath(__DIR__ . '/../..');
        $parent = getenv('TMPDIR') ?: sys_get_temp_dir();
        $this->temp = $parent . '/rt-plugin-util-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!mkdir($this->temp, 0700)) throw new RuntimeException('cannot create utility fixture');
    }

    public function tearDown()
    {
        foreach (array('autotools', 'datadir') as $plugin) {
            @rmdir($this->temp . '/' . $plugin . '/nested');
            @rmdir($this->temp . '/' . $plugin);
        }
        @rmdir($this->temp);
    }

    private function probe($plugin)
    {
        $script = <<<'SCRIPT'
require 'util_rt.php';
$shared = array('rtDbg', 'rtAddTailSlash', 'rtMkDir', 'rtExec');
$origins = array();
foreach ($shared as $name) $origins[$name] = realpath((new ReflectionFunction($name))->getFileName());
$base = getenv('RT_UTIL_TEMP') . '/' . getenv('RT_UTIL_PLUGIN');
$made = rtMkDir($base . '/nested', 0700);
$unused = array('rtIsDaemon', 'rtDaemon', 'rtSemGet', 'rtSemLock', 'rtSemUnlock',
    'rtRemoveTailSlash', 'rtRemoveHeadSlash', 'rtRemoveLastToken',
    'rtGetRelativePath', 'rtIsFile', 'rtScanFiles', 'rtRemoveDirectory',
    'rtMakeStrParam', 'rtAddTorrent');
$present = array();
foreach ($unused as $name) $present[$name] = function_exists($name);
echo 'RT_UTIL_RESULT:' . json_encode(array(
    'origins' => $origins,
    'slash' => array(rtAddTailSlash(''), rtAddTailSlash('/data'), rtAddTailSlash('/data/')),
    'made' => $made && is_dir($base . '/nested') && rtMkDir($base . '/nested', 0700),
    'unused' => $present,
    'auto_op' => function_exists('rtOpFiles'),
));
SCRIPT;
        $dir = $this->root . '/plugins/' . $plugin;
        $process = proc_open(array(PHP_BINARY, '-r', $script),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes, $dir,
            array_merge($_ENV, array('RT_UTIL_TEMP' => $this->temp,
                'RT_UTIL_PLUGIN' => $plugin)));
        if (!is_resource($process)) throw new RuntimeException('cannot start utility fixture');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || strpos($output, 'RT_UTIL_RESULT:') !== 0)
            throw new RuntimeException($plugin . ' utility fixture failed: ' . $output . ' ' . $errors);
        $value = json_decode(substr($output, strlen('RT_UTIL_RESULT:')), true);
        if (!is_array($value)) throw new RuntimeException('invalid utility fixture result');
        return $value;
    }

    public function testFourLiveHelpersComeFromOneImplementation()
    {
        $auto = $this->probe('autotools');
        $data = $this->probe('datadir');
        foreach ($auto['origins'] as $name => $origin)
            $this->assertSame($origin, $data['origins'][$name], $name . ' has one implementation');
    }

    public function testBothEntrypointsPreservePathAndDirectoryBehavior()
    {
        foreach (array('autotools', 'datadir') as $plugin) {
            $value = $this->probe($plugin);
            $this->assertSame(array('/', '/data/', '/data/'), $value['slash'],
                $plugin . ' trailing slash contract, including empty input');
            $this->assertSame(true, $value['made'], $plugin . ' creates and accepts directory');
        }
    }

    public function testDataDirDoesNotLoadUncalledLegacyOperations()
    {
        $data = $this->probe('datadir');
        foreach ($data['unused'] as $name => $present)
            $this->assertSame(false, $present, 'DataDir no longer loads ' . $name);
        $this->assertSame(false, $data['auto_op'], 'DataDir never gets AutoTools transaction');
    }

    public function testAutoToolsRetainsItsOwnOperations()
    {
        $auto = $this->probe('autotools');
        foreach (array('rtIsDaemon', 'rtDaemon', 'rtSemGet', 'rtScanFiles') as $name)
            $this->assertSame(true, $auto['unused'][$name], 'AutoTools retains ' . $name);
        $this->assertSame(false, $auto['unused']['rtRemoveDirectory'],
            'AutoTools has no legacy Move cleanup');
        $this->assertSame(true, $auto['auto_op'], 'AutoTools retains non-Move transaction');
    }
}
