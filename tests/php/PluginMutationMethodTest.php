<?php

require_once(__DIR__ . '/TestCase.php');

/** Exercise shipped plugin action.php files with inert managers and no daemon/network. */
class PluginMutationMethodTest extends TestCase
{
    private $root;

    public function setUp()
    {
        $this->root = sys_get_temp_dir() . '/rutorrent-plugin-method-' . getmypid() . '-' . bin2hex(random_bytes(6));
        foreach (array('rss', 'extratio', 'extsearch') as $plugin) {
            mkdir($this->root . '/plugins/' . $plugin, 0700, true);
            copy(__DIR__ . '/../../plugins/' . $plugin . '/action.php',
                $this->root . '/plugins/' . $plugin . '/action.php');
        }
        mkdir($this->root . '/php', 0700, true);
        file_put_contents($this->root . '/php/util.php', '<?php');
        file_put_contents($this->root . '/php/rtorrent.php', '<?php');
        file_put_contents($this->root . '/plugins/rss/rss.php', <<<'STUB'
<?php
class rRSSManager {
    public function __call($name, $args) { $GLOBALS['calls'][] = $name; return array(); }
    public function get() { $GLOBALS['calls'][] = 'get'; return array(); }
}
class rRSSFilter { public function __construct(...$args) {} }
class rRSSFilterList { public function add($filter) {} }
STUB
        );
        file_put_contents($this->root . '/plugins/extratio/rules.php', <<<'STUB'
<?php
class rRatioRulesList {
    public static function load() { return new self(); }
    public function __call($name, $args) { $GLOBALS['calls'][] = $name; return array(); }
}
STUB
        );
        file_put_contents($this->root . '/plugins/extsearch/engines.php', <<<'STUB'
<?php
class engineManager {
    public static function load() { return new self(); }
    public function __call($name, $args) { $GLOBALS['calls'][] = $name; return array(); }
    public function get() { $GLOBALS['calls'][] = 'get'; return '{}'; }
}
STUB
        );
    }

    public function tearDown()
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $entry)
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->root);
    }

    private function request($plugin, $method, $query)
    {
        $script = <<<'SCRIPT'
parse_str(getenv('ROUTE_QUERY'), $_REQUEST);
$_SERVER['REQUEST_METHOD'] = getenv('ROUTE_METHOD');
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTP_ORIGIN'] = 'http://localhost';
$GLOBALS['calls'] = array();
register_shutdown_function(function () {
    $status = http_response_code();
    echo 'ROUTE_RESULT:' . ($status === false ? 200 : $status) . ':' . json_encode($GLOBALS['calls']);
});
require getenv('ROUTE_SOURCE') . '/php/utility/requests.php';
require getenv('ROUTE_SOURCE') . '/php/utility/utility.php';
class JSON { public static function safeEncode($value) { return '{}'; } }
class CachedEcho { public static function send($value, $type = null, $compress = null, $cache = null) {} }
require 'action.php';
SCRIPT;
        $process = proc_open(array(PHP_BINARY, '-r', $script),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes, $this->root . '/plugins/' . $plugin,
            array_merge($_ENV, array(
                'ROUTE_QUERY' => $query,
                'ROUTE_METHOD' => $method,
                'ROUTE_SOURCE' => realpath(__DIR__ . '/../..'),
            )));
        if (!is_resource($process)) throw new RuntimeException('cannot start route fixture');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if (!preg_match('/ROUTE_RESULT:(\d+):(\[[^\]]*\])/', $output, $match))
            throw new RuntimeException('route fixture failed: ' . $output . ' ' . $errors);
        return array((int) $match[1], json_decode($match[2], true), $exit, $errors);
    }

    public function testRssMutationsRejectGetBeforeManagerAction()
    {
        $modes = array('setsettings', 'add', 'addgroup', 'edit', 'clearhistory',
            'refresh', 'get', 'refreshgroup', 'toggle', 'setgroupstate', 'remove',
            'removegroup', 'removegroupcontents', 'setfilters', 'clearfiltertime',
            'mark', 'loadtorrents');
        foreach ($modes as $mode) {
            list($status, $calls, $exit) = $this->request('rss', 'GET', 'mode=' . $mode
                . '&interval=5&delayerrui=1&url=http%3A%2F%2Fexample.test&rss=feed&state=1&no=1');
            $this->assertEquals(405, $status, $mode . ' refuses GET');
            $this->assertEquals(array(), $calls, $mode . ' made no manager call');
            $this->assertEquals(0, $exit, $mode . ' exits cleanly');
        }
        foreach (array('', 'mode=unknown') as $query) {
            list($status, $calls) = $this->request('rss', 'GET', $query);
            $this->assertEquals(405, $status, 'default/unknown RSS get refuses GET');
            $this->assertEquals(array(), $calls, 'default/unknown RSS get made no manager call');
        }
    }

    public function testRssOrdinaryPostAndReadOnlyGetRoutesStillReachManagers()
    {
        foreach (array('setsettings' => 'setSettings', 'add' => 'add',
            'addgroup' => 'addGroup', 'edit' => 'change', 'clearhistory' => 'clearHistory',
            'refresh' => 'updateRSS', 'get' => 'update', 'refreshgroup' => 'updateRSSGroup',
            'toggle' => 'toggleStatus', 'setgroupstate' => 'setStatusGroup',
            'remove' => 'remove', 'removegroup' => 'removeGroup',
            'removegroupcontents' => 'removeGroupContents', 'setfilters' => 'setFilters',
            'clearfiltertime' => 'clearFilterTime', 'mark' => 'setHistoryState',
            'loadtorrents' => 'saveHistory') as $mode => $call) {
            list($status, $calls, $exit) = $this->request('rss', 'POST', 'mode=' . $mode
                . '&interval=5&delayerrui=1&url=http%3A%2F%2Fexample.test&rss=feed&state=1&no=1');
            $this->assertEquals(200, $status, $mode . ' accepts POST');
            $this->assertTrue(in_array($call, $calls, true), $mode . ' reaches its manager action');
            $this->assertEquals(0, $exit, $mode . ' exits cleanly');
        }
        foreach (array('getsettings' => 'getSettings', 'getfilters' => 'getFilters',
            'getdesc' => 'getDescription', 'checkfilter' => 'testFilter') as $mode => $call) {
            list($status, $calls, $exit) = $this->request('rss', 'GET',
                'mode=' . $mode . '&rss=feed&href=item');
            $this->assertEquals(200, $status, $mode . ' remains available by GET');
            $this->assertTrue(in_array($call, $calls, true), $mode . ' reaches its reader');
            $this->assertEquals(0, $exit, $mode . ' exits cleanly');
        }
    }

    public function testExtratioMutationsRejectGetAndAcceptPost()
    {
        foreach (array('setrules' => 'set', 'checklabels' => 'checkLabels') as $mode => $call) {
            list($status, $calls) = $this->request('extratio', 'GET', 'mode=' . $mode);
            $this->assertEquals(405, $status, $mode . ' refuses GET');
            $this->assertEquals(array(), $calls, $mode . ' made no manager call');
            list($status, $calls, $exit) = $this->request('extratio', 'POST', 'mode=' . $mode);
            $this->assertEquals(200, $status, $mode . ' accepts POST');
            $this->assertTrue(in_array($call, $calls, true), $mode . ' reaches its manager action');
            $this->assertEquals(0, $exit, $mode . ' exits cleanly');
        }
        list($status, $calls) = $this->request('extratio', 'GET', '');
        $this->assertEquals(200, $status, 'read-only ratio-rule listing still accepts GET');
        $this->assertTrue(in_array('getContents', $calls, true), 'listing reaches its reader');
    }

    public function testExtsearchMutationsRejectGetAndAcceptPost()
    {
        foreach (array('set' => 'set', 'loadtorrents' => 'getTorrents') as $mode => $call) {
            list($status, $calls) = $this->request('extsearch', 'GET', 'mode=' . $mode);
            $this->assertEquals(405, $status, $mode . ' refuses GET');
            $this->assertEquals(array(), $calls, $mode . ' made no manager call');
            list($status, $calls, $exit) = $this->request('extsearch', 'POST', 'mode=' . $mode);
            $this->assertEquals(200, $status, $mode . ' accepts POST');
            $this->assertTrue(in_array($call, $calls, true), $mode . ' reaches its manager action');
            $this->assertEquals(0, $exit, $mode . ' exits cleanly');
        }
        list($status, $calls) = $this->request('extsearch', 'GET', 'mode=get&eng=stub&what=x&cat=all');
        $this->assertEquals(200, $status, 'search results still accept GET');
        $this->assertTrue(in_array('action', $calls, true), 'search reaches its engine action');
    }
}
