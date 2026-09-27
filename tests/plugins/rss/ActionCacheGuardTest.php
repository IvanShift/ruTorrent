<?php

require_once(__DIR__ . '/../../php/TestCase.php');

class RSSActionCacheGuardTest extends TestCase
{
    private $root;

    public function setUp()
    {
        $this->root = sys_get_temp_dir() . '/rutorrent-rss-action-cache-' . getmypid() . '-' . bin2hex(random_bytes(8));
        @mkdir($this->root . '/profile/settings/rss/cache', 0777, true);
        $script = <<<'SCRIPT'
<?php
$_ENV['RU_PROFILE_PATH'] = getenv('RU_PROFILE_PATH');
require getenv('RSS_PLUGIN_DIR') . '/rss.php';
$GLOBALS['log_file'] = getenv('RU_LOG_FILE');
$GLOBALS['rss_debug_enabled'] = 'dry-run';
$cache = new rCache('/rss/cache');
$known = new rRSS();
$known->hash = 'known-feed';
$cache->set($known);
$orphan = new rRSS();
$orphan->hash = 'orphan-feed';
$cache->set($orphan);
$list = new rRSSMetaList();
$list->lst['known-feed'] = array('label' => 'Known', 'auto' => 0, 'enabled' => 0, 'url' => null);
$cache->set($list);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST = array('mode' => 'loadtorrents');
$HTTP_RAW_POST_DATA = 'rss=orphan-feed&url=magnet%3A%3Forphan&rss=known-feed&url=magnet%3A%3Fknown';
chdir(getenv('RSS_PLUGIN_DIR'));
require 'action.php';
SCRIPT;
        file_put_contents($this->root . '/request.php', $script);
    }

    public function tearDown()
    {
        if (!is_dir($this->root)) return;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testOnlyARegisteredFeedCanSelectACacheEntryForLoad()
    {
        $env = array(
            'RU_PROFILE_PATH' => $this->root . '/profile',
            'RU_LOG_FILE' => $this->root . '/rss.log',
            'RSS_PLUGIN_DIR' => realpath(__DIR__ . '/../../../plugins/rss'),
        );
        $process = proc_open(array(PHP_BINARY, $this->root . '/request.php'),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes, null, $env);
        $this->assertTrue(is_resource($process), 'the local RSS action child starts');
        if (!is_resource($process)) return;
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $this->assertEquals(0, $exit, 'the RSS action completes: ' . $errors);
        $this->assertEquals('', $errors, 'the RSS action emits no PHP diagnostics');
        $this->assertTrue($out !== '', 'the RSS action returns its regular response');
        $log = @file_get_contents($this->root . '/rss.log');
        $this->assertTrue(is_string($log), 'dry-run records the action in the local log');
        $this->assertEquals(1, substr_count((string) $log, 'Load torrent ['),
            'only one cache-backed feed reaches the load path');
        $this->assertTrue(strpos((string) $log, 'magnet:?known') !== false,
            'the registered feed still reaches the load path');
        $this->assertTrue(strpos((string) $log, 'magnet:?orphan') === false,
            'an unregistered cache file cannot be selected by rss=');
    }
}
