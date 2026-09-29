<?php

require_once(__DIR__ . '/../../php/TestCase.php');

/** Drive the shipped action with a small manager stub so the raw POST is tested end to end. */
final class RSSActionFormPairsTest extends TestCase
{
    private $root;

    public function setUp()
    {
        $this->root = sys_get_temp_dir() . '/rutorrent-rss-form-' . getmypid() . '-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0700);
        copy(__DIR__ . '/../../../plugins/rss/action.php', $this->root . '/action.php');
        copy(__DIR__ . '/../../../php/utility/utility.php', $this->root . '/utility.php');
        file_put_contents($this->root . '/rss.php', <<<'PHPSTUB'
<?php
require_once 'utility.php';
const WAIT_AFTER_LOADING = 0;
class Requests { public static function requirePost() {} }
class JSON { public static function safeEncode($value) { return json_encode($value); } }
class CachedEcho { public static function send($body) { echo $body; } }
class rRSS { public $hash; }
class rRSSFilter { public $name; public function __construct($name) { $this->name = $name; } }
class rRSSFilterList {
    public $lst = array();
    public function add($filter) { $this->lst[] = $filter; }
}
class RSSFormList { public function isExist($hash) { return $hash === 'known-feed'; } }
class RSSFormCache { public function get($rss) { return true; } }
class rRSSManager {
    public $rssList;
    public $cache;
    public function __construct() { $this->rssList = new RSSFormList(); $this->cache = new RSSFormCache(); }
    private function record($name, $value) {
        file_put_contents(getenv('RSS_FORM_TRACE'), json_encode(array($name, $value)) . "\n", FILE_APPEND);
    }
    public function addGroup($label, $list) { $this->record('addGroup', $list); }
    public function setFilters($list) {
        $rows = array();
        foreach ($list->lst as $filter) $rows[] = get_object_vars($filter);
        $this->record('setFilters', $rows);
    }
    public function setHistoryState($urls, $times, $state) {
        $this->record('setHistoryState', array($urls, $times, $state));
    }
    public function getTorrents($rss, $url, $isStart, $isAddPath, $dir, $label) {
        $this->record('getTorrents', array($rss->hash, $url, $isStart, $isAddPath, $dir, $label));
    }
    public function saveHistory() {}
    public function get() { return array(); }
    public function hasErrors() { return false; }
}
PHPSTUB
        );
        file_put_contents($this->root . '/driver.php', <<<'PHPDRIVER'
<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST = array('mode' => getenv('RSS_FORM_MODE'), 'state' => '1');
$HTTP_RAW_POST_DATA = getenv('RSS_FORM_FALSE') === '1' ? false : getenv('RSS_FORM_BODY');
chdir(__DIR__);
require 'action.php';
PHPDRIVER
        );
    }

    public function tearDown()
    {
        foreach (glob($this->root . '/*') as $path) unlink($path);
        rmdir($this->root);
    }

    private function action($mode, $body)
    {
        $env = array_merge(getenv(), array(
            'RSS_FORM_MODE' => $mode,
            'RSS_FORM_BODY' => $body === false ? '' : $body,
            'RSS_FORM_FALSE' => $body === false ? '1' : '0',
            'RSS_FORM_TRACE' => $this->root . '/trace',
        ));
        @unlink($this->root . '/trace');
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', $this->root . '/driver.php'),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes, $this->root, $env);
        $this->assertTrue(is_resource($process), 'RSS action child starts');
        if (!is_resource($process)) return array();
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($process);
        $this->assertEquals(0, $exit, 'RSS action completes: ' . $errors);
        $this->assertEquals('', $errors, 'RSS action emits no diagnostics');
        $this->assertTrue($out !== '', 'RSS action returns a response');
        $trace = @file_get_contents($this->root . '/trace');
        return array_map(static function($line) { return json_decode($line, true); },
            explode("\n", trim((string)$trace)));
    }

    public function testAddGroupRetainsEqualsPlusEmptyAndDuplicateOrder()
    {
        $trace = $this->action('addgroup', 'rss=raw=A&rss=plus+tag&rss=&rss&unknown&rss=last%2Be');
        $this->assertEquals(array('addGroup', array('raw=A', 'plus+tag', '', null, 'last%2Be')),
            $trace[0] ?? null, 'feed IDs retain their raw ordered wire values');
    }

    public function testFailedBodyReadStillProcessesAnEmptyGroup()
    {
        $trace = $this->action('addgroup', false);
        $this->assertEquals(array('addGroup', array()), $trace[0] ?? null,
            'a failed body read still follows the old empty-group path');
    }

    public function testSetFiltersRetainsEqualsAndDuplicateOrder()
    {
        $trace = $this->action('setfilters',
            'name=A%2BB&pattern=one=two&exclude=plus+word&name=Second&pattern=&pattern=tail=part');
        $rows = $trace[0][1] ?? array();
        $this->assertEquals('A+B', $rows[0]['name'] ?? null, 'encoded plus decoded once');
        $this->assertEquals('one=two', $rows[0]['pattern'] ?? null, 'pattern keeps raw equals');
        $this->assertEquals('plus+word', $rows[0]['exclude'] ?? null, 'literal plus remains plus');
        $this->assertEquals('Second', $rows[1]['name'] ?? null, 'second name starts a second filter');
        $this->assertEquals('tail=part', $rows[1]['pattern'] ?? null, 'last complete duplicate wins');
    }

    public function testMarkKeepsParallelUrlTimeOrder()
    {
        $trace = $this->action('mark', 'url=https://a.test/?x=y&time=1=2&url=x+y&time=&url&time');
        $this->assertEquals(array('setHistoryState',
            array(array('https://a.test/?x=y', 'x+y', ''), array('1=2', '', null), '1')),
            $trace[0] ?? null, 'parallel URL/time arrays keep complete ordered values');
    }

    public function testLoadTorrentsKeepsFeedSelectionAndCompleteUrls()
    {
        $trace = $this->action('loadtorrents',
            'rss=known-feed&url=magnet:?xt=abc&url=x+y&url=&rss=unknown&url=skip=me&rss=known-feed&url=last=part');
        $this->assertEquals(array(
            array('getTorrents', array('known-feed', 'magnet:?xt=abc', true, true, null, null)),
            array('getTorrents', array('known-feed', 'x+y', true, true, null, null)),
            array('getTorrents', array('known-feed', '', true, true, null, null)),
            array('getTorrents', array('known-feed', 'last=part', true, true, null, null)),
        ), $trace, 'only registered feed receives complete URLs in duplicate order');
    }

    public function testLoadTorrentsRetainsBarePresenceFlags()
    {
        $trace = $this->action('loadtorrents',
            'rss=known-feed&url=one&missing&torrents_start_stopped&not_add_path&url=two');
        $this->assertEquals(array(
            array('getTorrents', array('known-feed', 'one', false, false, null, null)),
            array('getTorrents', array('known-feed', 'two', false, false, null, null)),
        ), $trace, 'name-only load flags retain their historical presence semantics');
    }
    public function testLoadTorrentsBareKeysMatchCommittedParserState()
    {
        $cases = array(
            array('torrents_start_stopped', array('before', 'after', 'resumed'), false, true, null, null),
            array('not_add_path', array('before', 'after', 'resumed'), true, false, null, null),
            array('dir_edit', array('before', 'after', 'resumed'), true, true, '', null),
            array('label', array('before', 'after', 'resumed'), true, true, null, ''),
            array('rss', array('before', 'resumed'), true, true, null, null),
            array('url', array('before', '', 'after', 'resumed'), true, true, null, null),
            array('unknown', array('before', 'after', 'resumed'), true, true, null, null),
        );
        foreach ($cases as $case)
        {
            list($key, $urls, $isStart, $isAddPath, $dir, $label) = $case;
            $trace = $this->action('loadtorrents',
                'rss=known-feed&url=before&' . $key . '&url=after&rss=known-feed&url=resumed');
            $expected = array();
            foreach ($urls as $url)
                $expected[] = array('getTorrents',
                    array('known-feed', $url, $isStart, $isAddPath, $dir, $label));
            $this->assertEquals($expected, $trace, 'bare ' . $key . ' retains committed parser state');
        }
    }

    public function testSetFiltersBareKeysRetainCommittedResetSemantics()
    {
        $trace = $this->action('setfilters', 'name=A&pattern=one&name&pattern=two');
        $this->assertEquals(array('setFilters', array(
            array('name' => 'A', 'pattern' => 'one'),
            array('name' => '', 'pattern' => 'two'),
        )), $trace[0] ?? null, 'bare name starts a fresh unnamed filter');

        $properties = array(
            'pattern' => array('pattern', ''),
            'exclude' => array('exclude', ''),
            'enabled' => array('enabled', null),
            'no' => array('no', null),
            'interval' => array('interval', null),
            'hash' => array('rssHash', null),
            'throttle' => array('throttle', null),
            'ratio' => array('ratio', null),
            'start' => array('start', null),
            'addPath' => array('addPath', null),
            'dir' => array('directory', ''),
            'label' => array('label', ''),
            'chktitle' => array('titleCheck', null),
            'chkdesc' => array('descCheck', null),
            'chklink' => array('linkCheck', null),
        );
        foreach ($properties as $key => $property)
        {
            list($field, $bareValue) = $property;
            $trace = $this->action('setfilters', 'name=A&' . $key . '=old&' . $key);
            $this->assertEquals(array('setFilters', array(array('name' => 'A', $field => $bareValue))),
                $trace[0] ?? null, 'bare ' . $key . ' resets the filter property as on HEAD');
        }
    }

}
