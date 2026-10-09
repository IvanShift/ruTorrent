<?php

require_once(__DIR__.'/../../php/TestCase.php');

/** Drive the real synchronous callback with an on-disk settings cache. */
class ExtratioSynchronousHookSettingsTest extends TestCase
{
    private $base;

    public function setUp()
    {
        $this->base = sys_get_temp_dir().'/extratio-hook-'.bin2hex(random_bytes(4));
        mkdir($this->base, 0700);
    }

    public function tearDown()
    {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->base,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach($entries as $entry)
        {
            if($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
            else unlink($entry->getPathname());
        }
        rmdir($this->base);
    }

    private function hook($withCache)
    {
        $driver = <<<'DRIVER'
<?php
$_ENV['RU_PROFILE_PATH'] = $argv[2];
require $argv[1].'/conf/config.php';
require $argv[1].'/plugins/extratio/rules.php';
$root = $argv[1];
$profile = $argv[2];
$withCache = $argv[3] === '1';
$rpcLogCalls = true;
$rpcLogFaults = true;
$log_file = $profile.'/rpc.log';
$scgi_host = '127.0.0.1';
$scgi_port = 1;
$rpcTimeOut = 0.05;
$cache = new rCache();
$rules = new rRatioRulesList();
$rules->lst = array(new rRatioRule('public', RR_TRACKER_PUBLIC, '', 1, 0, 'rat_1', ''));
$rules->store();
$path = FileUtil::getSettingsPath().'/rtorrent.dat';
if($withCache) {
    $settings = (new ReflectionClass('rTorrentSettings'))->newInstanceWithoutConstructor();
    $settings->valueFormat = 0;
    $settings->linkExist = true;
    $settings->iVersion = 0x1019;
    $cache->set($settings);
}
$before = is_file($path) ? hash_file('sha256', $path) : null;
$argv = array($root.'/plugins/extratio/update.php', 'https://tracker.invalid/announce', '', 'ratio', '');
register_shutdown_function(function() use ($path, $profile, $before) {
    file_put_contents($profile.'/result', json_encode(array(
        'before'=>$before, 'after'=>is_file($path) ? hash_file('sha256', $path) : null,
        'rpc'=>is_file($profile.'/rpc.log') ? file_get_contents($profile.'/rpc.log') : '',
    )));
});
require $root.'/plugins/extratio/update.php';
DRIVER;
        file_put_contents($this->base.'/driver.php', $driver);
        $output = array();
        $status = 0;
        exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 '.escapeshellarg($this->base.'/driver.php')
            .' '.escapeshellarg(realpath(__DIR__.'/../../..')).' '.escapeshellarg($this->base)
            .' '.($withCache ? '1' : '0').' 2>&1', $output, $status);
        $data = json_decode(file_get_contents($this->base.'/result'), true);
        return array($status, implode("\n", $output), $data);
    }

    public function testLegacySettingsCacheIsReadWithoutCallingTheBlockedDaemon()
    {
        list($status, $output, $data) = $this->hook(true);
        $this->assertSame(0, $status, 'the synchronous public-tracker rule succeeds using stale settings');
        $this->assertSame('rat_1', $output, 'only the selected ratio name reaches execute_capture');
        $this->assertSame('', $data['rpc'], 'no request is sent back to the waiting daemon');
        $this->assertTrue($data['before'] !== null && $data['before'] === $data['after'],
            'the stale cache keeps its old format and bytes until asynchronous refresh');
    }

    public function testMissingSettingsCacheRefusesWithoutReturningAnErrorAsARatioName()
    {
        list($status, $output, $data) = $this->hook(false);
        $this->assertSame(1, $status, 'a missing cache refuses the synchronous callback');
        $this->assertSame('', $output, 'execute_capture receives no PHP diagnostic as a ratio name');
        $this->assertSame(null, $data['after'], 'the hook cannot bootstrap or stamp a settings cache');
        $this->assertTrue(strpos($data['rpc'], 'settings-cache-unavailable-in-synchronous-hook') !== false,
            'the shared settings owner logs its classified refusal');
        $this->assertTrue(strpos($data['rpc'], '<methodCall>') === false,
            'a missing cache never triggers an RPC to the waiting daemon');
    }
}
