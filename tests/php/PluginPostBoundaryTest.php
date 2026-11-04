<?php

require_once(__DIR__ . '/TestCase.php');

class PluginPostBoundaryTest extends TestCase
{
    private $scratch;
    private $root;

    public function setUp()
    {
        $this->root = dirname(__DIR__, 2);
        $this->scratch = sys_get_temp_dir() . '/rt-plugin-post-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->scratch . '/profile/settings', 0777, true);
        file_put_contents($this->scratch . '/request.php', <<<'PHP'
<?php
class RequestInputStream {
    public $context;
    private $position = 0;
    private $body = '';
    public function stream_open($path, $mode, $options, &$opened_path) {
        if ($path !== 'php://input') return false;
        $this->body = getenv('ROUTE_BODY');
        return true;
    }
    public function stream_read($count) {
        $piece = substr($this->body, $this->position, $count);
        $this->position += strlen($piece);
        return $piece;
    }
    public function stream_eof() { return $this->position >= strlen($this->body); }
    public function stream_stat() { return array(); }
}
stream_wrapper_unregister('php');
stream_wrapper_register('php', 'RequestInputStream');
$_ENV['RU_PROFILE_PATH'] = getenv('RU_PROFILE_PATH');
$_ENV['RU_LOG_FILE'] = getenv('RU_LOG_FILE');
$_SERVER['REQUEST_METHOD'] = getenv('ROUTE_METHOD');
$_SERVER = array_merge($_SERVER, json_decode(getenv('ROUTE_HEADERS'), true) ?: array());
$_GET = json_decode(getenv('ROUTE_GET'), true);
$_POST = json_decode(getenv('ROUTE_POST'), true);
$_REQUEST = array_merge($_GET, $_POST);
register_shutdown_function(function () {
    file_put_contents(getenv('ROUTE_STATUS_FILE'), (string) http_response_code());
});
// The shipped image has no SQLite extension. Keep the real GeoIP action and
// capture its SQL when this test runtime lacks the extension as well.
if (!class_exists('SQLite3')) {
    class SQLite3 {
        public function __construct($path) { file_put_contents($path, 'test db'); }
        public function exec($sql) { file_put_contents(getenv('ROUTE_SQL_TRACE'), $sql . "\n", FILE_APPEND); return true; }
        public function querySingle($sql) { return 0; }
        public function close() {}
        public static function escapeString($value) { return addslashes($value); }
    }
}
$route = getenv('ROUTE_FILE');
chdir(dirname($route));
if (getenv('ROUTE_PROXY_PORT') !== false) {
    require getenv('ROUTE_ROOT') . '/php/util.php';
    $httpProxy = array('use' => true, 'proto' => 'http',
        'host' => '127.0.0.1', 'port' => (int)getenv('ROUTE_PROXY_PORT'));
}
require basename($route);
PHP
        );
    }

    public function tearDown()
    {
        if (!is_dir($this->scratch)) { return; }
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->scratch, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($this->scratch);
    }

    public function testRssSetRulesRequiresPost()
    {
        $this->assertRefused('plugins/rssurlrewrite/action.php', ['mode' => 'setrules']);
        $this->assertTrue(!is_file($this->scratch . '/profile/settings/urlrewriterules.dat'),
            'GET cannot store URL rewrite rules');
    }

    public function testExtraRatioOrderedRuleBoundaryPreservesRawAndEncodedEquals()
    {
        $body = 'pattern=ignored&name=Z=raw&pattern=first=part&name=&pattern=&name=Z%3Draw&pattern=second%3Dpart';
        $result = $this->request('plugins/extratio/action.php', 'POST', [],
            ['mode' => 'setrules'], $body);
        $this->assertSame(0, $result['exit'], 'extratio POST finishes: ' . $result['err']);
        $rules = json_decode($result['out'], true);
        $this->assertSame(['Z=raw', '', 'Z=raw'], array_column($rules, 'name'),
            'extratio keeps the submitted rule order, empty name and duplicate name');
        $this->assertSame(['first=part', '', 'second=part'], array_column($rules, 'pattern'),
            'extratio preserves raw and encoded equals in the rule value');
    }

    public function testRssRewriteOrderedRuleBoundaryPreservesRawAndEncodedEquals()
    {
        $body = 'replacement=ignored&name=Z=raw&pattern=first=part&replacement=to=one'
            . '&name=&pattern=&replacement=&name=Z%3Draw&pattern=second%3Dpart&replacement=to%3Dtwo';
        $result = $this->request('plugins/rssurlrewrite/action.php', 'POST', [],
            ['mode' => 'setrules'], $body);
        $this->assertSame(0, $result['exit'], 'rssurlrewrite POST finishes: ' . $result['err']);
        $rules = json_decode($result['out'], true);
        $this->assertSame(['', 'Z=raw', 'Z=raw'], array_column($rules, 'name'),
            'rewrite sorts by name while keeping the empty and duplicate names');
        $pairs = [];
        foreach($rules as $rule) {
            $pairs[] = [$rule['name'], $rule['pattern'], $rule['replacement']]; }
        sort($pairs);
        $this->assertSame([
            ['', '', ''],
            ['Z=raw', 'first=part', 'to=one'],
            ['Z=raw', 'second=part', 'to=two'],
        ], $pairs, 'rewrite preserves raw and encoded equals in each rule value');
    }

    public function testExtraRatioFormDecodesSpaceAndLiteralPlus()
    {
        $body = 'name=A+B&pattern=seed+peer&name=A%2BB&pattern=seed%2Bpeer';
        $result = $this->request('plugins/extratio/action.php', 'POST', [],
            ['mode' => 'setrules'], $body);
        $this->assertSame(0, $result['exit'], 'extratio plus POST finishes: ' . $result['err']);
        $rules = json_decode($result['out'], true);
        $this->assertSame(['A B', 'A+B'], array_column($rules, 'name'),
            'extratio decodes raw plus as space and encoded plus as literal');
        $this->assertSame(['seed peer', 'seed+peer'], array_column($rules, 'pattern'),
            'extratio keeps literal regex plus from the UI producer');
    }

    public function testRssRewriteFormDecodesSpaceAndLiteralPlus()
    {
        $body = 'name=A+B&pattern=src+link&replacement=dest+file'
            . '&name=A%2BB&pattern=src%2Blink&replacement=dest%2Bfile';
        $result = $this->request('plugins/rssurlrewrite/action.php', 'POST', [],
            ['mode' => 'setrules'], $body);
        $this->assertSame(0, $result['exit'], 'rssurlrewrite plus POST finishes: ' . $result['err']);
        $rules = json_decode($result['out'], true);
        $this->assertSame(['A B', 'A+B'], array_column($rules, 'name'),
            'rewrite decodes raw plus as space and encoded plus as literal');
        $this->assertSame(['src link', 'src+link'], array_column($rules, 'pattern'),
            'rewrite keeps literal regex plus from the UI producer');
        $this->assertSame(['dest file', 'dest+file'], array_column($rules, 'replacement'),
            'rewrite decodes raw and encoded plus in replacement');
    }

    public function testExtraRatioDecodesEncodedFieldNamesWithoutCollapsingRules()
    {
        $body = 'na%6De=A&pattern=x&name=A&pa%74tern=y';
        $result = $this->request('plugins/extratio/action.php', 'POST', [],
            ['mode' => 'setrules'], $body);
        $this->assertSame(0, $result['exit'], 'extratio encoded-name POST finishes: ' . $result['err']);
        $rules = json_decode($result['out'], true);
        $this->assertSame(['A', 'A'], array_column($rules, 'name'),
            'encoded name starts the first rule and repeated names stay ordered');
        $this->assertSame(['x', 'y'], array_column($rules, 'pattern'),
            'encoded pattern field belongs to the second rule');
    }

    public function testRssRewriteDecodesEncodedFieldNamesWithoutCollapsingRules()
    {
        $body = 'na%6De=A&pattern=x&repla%63ement=one'
            . '&name=A&pa%74tern=y&replacement=two';
        $result = $this->request('plugins/rssurlrewrite/action.php', 'POST', [],
            ['mode' => 'setrules'], $body);
        $this->assertSame(0, $result['exit'], 'rssurlrewrite encoded-name POST finishes: ' . $result['err']);
        $rules = json_decode($result['out'], true);
        $this->assertSame(['A', 'A'], array_column($rules, 'name'),
            'encoded name starts the first rewrite rule without collapsing duplicates');
        $triples = [];
        foreach($rules as $rule) {
            $triples[] = [$rule['name'], $rule['pattern'], $rule['replacement']]; }
        sort($triples);
        $this->assertSame([['A', 'x', 'one'], ['A', 'y', 'two']], $triples,
            'encoded pattern and replacement fields keep their own rule');
    }

    public function testExtraRatioDecodesNumericValuesOnceAndKeepsDuplicateRules()
    {
        $body = 'name=A%252B&pattern=x%252By&enabled=%31&reason=%31'
            . '&name=A%252B&pattern=z%252Bw&enabled=1&reason=0';
        $result = $this->request('plugins/extratio/action.php', 'POST', [],
            ['mode' => 'setrules'], $body);
        $this->assertSame(0, $result['exit'], 'extratio encoded-value POST finishes: ' . $result['err']);
        $rules = json_decode($result['out'], true);
        $this->assertSame(['A%2B', 'A%2B'], array_column($rules, 'name'),
            'one decode retains literal percent sequence and duplicate rule names');
        $this->assertSame(['x%2By', 'z%2Bw'], array_column($rules, 'pattern'),
            'text values are decoded exactly once in submitted order');
        $this->assertSame([1, 1], array_column($rules, 'enabled'),
            'encoded and plain enabled values have the same numeric meaning');
        $this->assertSame([1, 0], array_column($rules, 'reason'),
            'encoded and plain reason values have the same numeric meaning');
    }

    public function testRssRewriteDecodesNumericValuesOnceAndKeepsDuplicateRules()
    {
        $body = 'name=A%252B&pattern=x%252By&replacement=to%252Bvalue'
            . '&enabled=%31&hrefAsSrc=%31&hrefAsDest=%30'
            . '&name=A%252B&pattern=z%252Bw&replacement=more%252Bvalue'
            . '&enabled=1&hrefAsSrc=0&hrefAsDest=1';
        $result = $this->request('plugins/rssurlrewrite/action.php', 'POST', [],
            ['mode' => 'setrules'], $body);
        $this->assertSame(0, $result['exit'], 'rssurlrewrite encoded-value POST finishes: ' . $result['err']);
        $rules = json_decode($result['out'], true);
        $this->assertSame(['A%2B', 'A%2B'], array_column($rules, 'name'),
            'one decode retains literal percent sequence and duplicate rewrite names');
        $tuples = [];
        foreach($rules as $rule) {
            $tuples[] = [$rule['pattern'], $rule['replacement'],
                $rule['enabled'], $rule['hrefAsSrc'], $rule['hrefAsDest']]; }
        sort($tuples);
        $this->assertSame([
            ['x%2By', 'to%2Bvalue', 1, 1, 0],
            ['z%2Bw', 'more%2Bvalue', 1, 0, 1],
        ], $tuples, 'encoded flags match plain flags and each rule decodes once');
    }

    public function testGeoipCommentRequiresPostBody()
    {
        $this->assertRefused('plugins/geoip/action.php',
            ['ip' => '192.0.2.3', 'comment' => 'written-by-GET']);
        $this->assertTrue(!is_file($this->scratch . '/sql.log')
            && !is_file($this->scratch . '/profile/settings/peers3.dat'),
            'GET cannot write a peer comment');
    }

    public function testLogClearRequiresPostBody()
    {
        $route = 'plugins/log_history/log_history.php';
        $saved = $this->request($route, 'POST', [],
            ['message' => 'keep this', 'status' => 'info']);
        $this->assertSame('success', json_decode($saved['out'], true)['status'] ?? null,
            'normal POST saves a log line');
        $this->assertRefused($route, ['clear' => '1']);
        $read = $this->request($route, 'GET');
        $this->assertSame(['keep this'], array_column(json_decode($read['out'], true)['logs'], 'message'),
            'rejected GET leaves the log intact');

        $queryOnly = $this->request($route, 'POST', ['clear' => '1']);
        $this->assertTrue(($queryOnly['out'] !== '{"status":"cleared"}'),
            'POST query parameter alone does not clear the log');
        $cleared = $this->request($route, 'POST', [], ['clear' => '1']);
        $this->assertSame('cleared', json_decode($cleared['out'], true)['status'] ?? null,
            'POST body clears the log');
    }

    public function testOnlyPostBodyValuesCanChangeGeoipOrThrottle()
    {
        $geoip = 'plugins/geoip/action.php';
        $queryOnly = $this->request($geoip, 'POST',
            ['ip' => '192.0.2.3', 'comment' => 'query-only']);
        $this->assertSame('Bad Request', $queryOnly['out'],
            'GeoIP refuses POST when its fields are only in the query');
        $this->assertTrue(!is_file($this->scratch . '/sql.log')
            && !is_file($this->scratch . '/profile/settings/peers3.dat'),
            'query-only peer comment is not stored');
        $body = $this->request($geoip, 'POST', [],
            ['ip' => '192.0.2.3', 'comment' => 'body-value']);
        $this->assertSame('body-value', json_decode($body['out'], true)['comment'] ?? null,
            'GeoIP still accepts the shipped POST body');
        $this->assertTrue(is_file($this->scratch . '/sql.log')
            || is_file($this->scratch . '/profile/settings/peers3.dat'),
            'POST body reaches the peer comment store');

        $throttle = 'plugins/throttle/action.php';
        $queryOnly = $this->request($throttle, 'POST', ['default' => '1']);
        $this->assertSame('Bad Request', $queryOnly['out'],
            'throttle settings refuse a query-only POST');
        $this->assertTrue(!is_file($this->scratch . '/profile/settings/throttle.dat'),
            'query-only throttle settings are not stored');
        $body = $this->request($throttle, 'POST', [], ['default' => '0']);
        $this->assertSame(0, $body['exit'], 'normal throttle POST finishes: ' . $body['err']);
        $this->assertTrue(is_file($this->scratch . '/profile/settings/throttle.dat'),
            'normal throttle POST stores its settings');
    }

    public function testReadOnlyGetRoutesRemainAvailable()
    {
        $rss = $this->request('plugins/rssurlrewrite/action.php', 'GET',
            ['mode' => 'getrules']);
        $this->assertSame(0, $rss['exit'], 'GET rules completes: ' . $rss['err']);
        $this->assertSame([], json_decode($rss['out'], true),
            'GET rules still returns the current rule list');
        $history = $this->request('plugins/history/action.php', 'GET',
            ['cmd' => 'get', 'mark' => '0']);
        $this->assertSame(0, $history['exit'], 'GET history completes: ' . $history['err']);
        $this->assertTrue(is_array(json_decode($history['out'], true)),
            'GET history still returns JSON');
    }

    public function testPostQueryCommandsAloneCannotChangeRssOrHistory()
    {
        $rss = $this->request('plugins/rssurlrewrite/action.php', 'POST',
            ['mode' => 'setrules']);
        $this->assertSame(0, $rss['exit'], 'query-only RSS POST finishes: ' . $rss['err']);
        $this->assertTrue(!is_file($this->scratch . '/profile/settings/urlrewriterules.dat'),
            'query-only RSS POST does not store rules');
        $history = 'plugins/history/action.php';
        $set = $this->request($history, 'POST', ['cmd' => 'set']);
        $delete = $this->request($history, 'POST', ['cmd' => 'delete']);
        $this->assertSame(0, $set['exit'], 'query-only history set finishes: ' . $set['err']);
        $this->assertSame(0, $delete['exit'], 'query-only history delete finishes: ' . $delete['err']);
        $this->assertTrue(!is_file($this->scratch . '/profile/settings/history.dat')
            && !is_file($this->scratch . '/profile/settings/history_data.dat'),
            'query-only history POST does not store settings or deletions');
    }

    public function testThrottleApplyAndSettingsRequirePost()
    {
        $route = 'plugins/throttle/action.php';
        $this->assertRefused($route, ['apply' => '1', 'hash' => '0']);
        $this->assertRefused($route, ['default' => '1']);
        $this->assertTrue(!is_file($this->scratch . '/profile/settings/throttle.dat'),
            'GET cannot store throttle settings');
    }

    public function testHistorySetAndDeleteRequirePost()
    {
        $route = 'plugins/history/action.php';
        $this->assertRefused($route, ['cmd' => 'set', 'addition' => '1']);
        $this->assertRefused($route, ['cmd' => 'delete', 'hash' => 'old']);
        $this->assertTrue(!is_file($this->scratch . '/profile/settings/history.dat')
            && !is_file($this->scratch . '/profile/settings/history_data.dat'),
            'GET cannot store history settings or deletions');
    }


    public function testRatioSchedulerAndUploadetaMutationsRequirePost()
    {
        $this->assertRefused('plugins/ratio/action.php', ['default' => '2']);
        $this->assertRefused('plugins/scheduler/action.php', ['enabled' => '1']);
        $this->assertRefused('plugins/uploadeta/action.php', ['uploadtarget' => '31']);
        $this->assertTrue(!is_file($this->scratch . '/profile/settings/ratio.dat')
            && !is_file($this->scratch . '/profile/settings/scheduler.dat')
            && !is_file($this->scratch . '/profile/settings/uploadeta.dat'),
            'GET cannot store ratio, schedule or upload target');
    }

    public function testCreateMutationsRequirePostBody()
    {
        $this->assertRefused('plugins/create/action.php', [
            'cmd' => 'rtdelete', 'trackers' => 'http://tracker.invalid/announce']);
        $this->assertRefused('plugins/create/action.php', ['cmd' => 'create', 'path_edit' => '/tmp']);
        $read = $this->request('plugins/create/action.php', 'GET', ['cmd' => 'rtget']);
        $this->assertSame(0, $read['exit'], 'recent tracker GET still exits: ' . $read['err']);
        $this->assertTrue(isset(json_decode($read['out'], true)['last_used']),
            'recent tracker GET remains readable');
        $queryOnly = $this->request('plugins/create/action.php', 'POST', [
            'cmd' => 'create', 'path_edit' => '/tmp', 'trackers' => '',
            'piece_size' => '1024', 'start_seeding' => '0', 'private' => '0',
            'hybrid' => '0', 'comment' => '', 'source' => '']);
        $this->assertSame(0, $queryOnly['exit'], 'query-only create command exits: ' . $queryOnly['err']);
        $tasks = glob($this->scratch . '/profile/settings/tasks/*');
        $this->assertTrue($tasks === false || count($tasks) === 0,
            'query-only POST cannot start a create task');
    }

    public function testRatioSchedulerAndUploadetaReadOnlyFromPostBody()
    {
        // Ratio emits several assignments; the other two routes emit one JSON value.
        $ratioRoute = 'plugins/ratio/action.php';
        $ratioValues = ['default' => '2'];
        $ratioQuery = $this->request($ratioRoute, 'POST', $ratioValues);
        $this->assertSame(0, $ratioQuery['exit'], 'ratio query-only POST exits: ' . $ratioQuery['err']);
        $this->assertTrue(strpos($ratioQuery['out'], 'theWebUI.defaultRatio = 0;') !== false,
            'ratio ignores query values when changing settings');
        $ratioBody = $this->request($ratioRoute, 'POST', [], $ratioValues);
        $this->assertSame(0, $ratioBody['exit'], 'ratio normal POST exits: ' . $ratioBody['err']);
        $this->assertTrue(strpos($ratioBody['out'], 'theWebUI.defaultRatio = 2;') !== false,
            'ratio accepts the shipped POST body');

        $cases = [
            ['plugins/scheduler/action.php', ['enabled' => '1'],
                'theWebUI.scheduleTable', 0, 1],
            ['plugins/uploadeta/action.php', ['uploadtarget' => '31'],
                'theWebUI.uploadtarget', 200, 31],
        ];
        foreach ($cases as $case) {
            list($route, $values, $name, $old, $new) = $case;
            $queryOnly = $this->request($route, 'POST', $values);
            $this->assertSame(0, $queryOnly['exit'], $route . ' query-only POST exits: ' . $queryOnly['err']);
            $queryValue = $this->assignedValue($queryOnly['out'], $name);
            if ($name === 'theWebUI.scheduleTable') { $queryValue = $queryValue['enabled'] ?? null; }
            $this->assertSame($old, $queryValue,
                $route . ' ignores query values when changing settings');
            $body = $this->request($route, 'POST', [], $values);
            $this->assertSame(0, $body['exit'], $route . ' normal POST exits: ' . $body['err']);
            $bodyValue = $this->assignedValue($body['out'], $name);
            if ($name === 'theWebUI.scheduleTable') { $bodyValue = $bodyValue['enabled'] ?? null; }
            $this->assertSame($new, $bodyValue,
                $route . ' accepts the shipped POST body');
        }
    }

    public function testUnpackMutationsRequirePost()
    {
        $this->assertRefused('plugins/unpack/action.php', ['cmd' => 'set']);
        $this->assertRefused('plugins/unpack/action.php', ['cmd' => 'unpack',
            'hash' => str_repeat('A', 40), 'dir' => '/', 'mode' => 'full', 'no' => '0']);
    }

    public function testTaskKillRequiresPost()
    {
        $this->assertRefused('plugins/_task/action.php', ['cmd' => 'kill', 'no' => 'absent']);
    }

    public function testDumpTaskRequiresPost()
    {
        $this->assertRefused('plugins/dump/action.php', ['cmd' => 'dumptorrent',
            'hash' => str_repeat('A', 40)]);
    }

    public function testForcePortRequiresPostBody()
    {
        $this->assertRefused('plugins/check_port/action.php', ['setport' => '1']);
    }

    public function testScreenshotMutationsRequirePost()
    {
        $this->assertRefused('plugins/screenshots/action.php', ['cmd' => 'ffmpegset']);
        $this->assertRefused('plugins/screenshots/action.php', ['cmd' => 'ffmpeg',
            'hash' => str_repeat('A', 40), 'no' => '0']);
    }

    public function testSpectrogramTaskRequiresPost()
    {
        $this->assertRefused('plugins/spectrogram/action.php', ['cmd' => 'sox',
            'hash' => str_repeat('A', 40), 'no' => '0']);
    }

    public function testMediaInfoTaskRequiresPost()
    {
        $this->assertRefused('plugins/mediainfo/action.php', ['cmd' => 'mediainfo',
            'hash' => str_repeat('A', 40), 'no' => '0']);
    }

    public function testPostQueryCommandsCannotChangeUnpackOrScreenshots()
    {
        $unpack = $this->request('plugins/unpack/action.php', 'POST', ['cmd' => 'set']);
        $shots = $this->request('plugins/screenshots/action.php', 'POST', ['cmd' => 'ffmpegset']);
        $this->assertSame(0, $unpack['exit'], 'query-only unpack POST exits cleanly: ' . $unpack['err']);
        $this->assertSame(0, $shots['exit'], 'query-only screenshots POST exits cleanly: ' . $shots['err']);
        $this->assertTrue(!is_file($this->scratch . '/profile/settings/unpack.dat')
            && !is_file($this->scratch . '/profile/settings/ffmpeg.dat'),
            'query-only POST cannot store unpack or screenshot settings');
    }

    public function testScreenshotPostBodyStillStoresSettings()
    {
        $response = $this->request('plugins/screenshots/action.php', 'POST', [],
            ['cmd' => 'ffmpegset', 'exfrmcount' => '2', 'exfrminterval' => '5']);
        $this->assertSame(0, $response['exit'], 'screenshot POST exits cleanly: ' . $response['err']);
        $this->assertTrue(is_file($this->scratch . '/profile/settings/ffmpeg.dat'),
            'normal screenshot POST body stores its settings');
    }

    public function testDumpRpcFailureDoesNotStartBogusTask()
    {
        $response = $this->request('plugins/dump/action.php', 'POST', [],
            ['cmd' => 'dumptorrent', 'hash' => str_repeat('A', 40)]);
        $this->assertSame(0, $response['exit'], 'dump RPC failure exits cleanly');
        $this->assertSame(255, json_decode($response['out'], true)['status'] ?? null,
            'RPC failure remains a classified failed dump');
        $this->assertTrue(strpos($response['err'], 'Undefined variable $fname') === false
            && strpos($response['err'], 'TaskStart failed') === false,
            'RPC failure does not create a task from an undefined filename');
    }

    public function testAutotoolsGetCannotResetOptions()
    {
        $this->assertSettingsGetRefused('autotools', ['enable_label' => '1',
            'label_template' => '{NAME}'], 'autotools.dat',
            'enable_label=1&label_template={NAME}');
    }

    public function testLookatGetCannotClearTemplates()
    {
        $this->assertSettingsGetRefused('lookat', ['look' =>
            'Search|https://example.test/?q={title}'], 'look.dat');
    }

    public function testLookatPostKeepsRawEqualsAndIgnoresBareField()
    {
        $result = $this->request('plugins/lookat/action.php', 'POST', [], [],
            'look=Search%7Chttps%3A%2F%2Fexample.com%2F%3Fq%3Da=b&look');
        $this->assertSame(0, $result['exit'], 'lookat action exits: ' . $result['err']);
        $this->assertTrue(strpos($result['err'], 'Undefined') === false,
            'lookat action reads no absent value: ' . $result['err']);
        $this->assertSame(['Search' => 'https://example.com/?q=a=b{title}'],
            json_decode($result['out'], true), 'the action response contains the complete URL');
        $this->assertTrue(is_file($this->scratch . '/profile/settings/look.dat'),
            'the action stores the accepted URL');
    }

    public function testRetrackersGetCannotClearTrackerList()
    {
        $this->assertSettingsGetRefused('retrackers', ['tracker' =>
            'udp://tracker.example.test:80', 'dont_private' => '1'], 'retrackers.dat');
    }

    public function testXmppGetCannotClearNotificationAccount()
    {
        $this->assertSettingsGetRefused('xmpp', ['jabberJid' => 'user@example.test',
            'jabberPasswd' => 'example-secret', 'message' => 'ready'], 'xmpp.dat',
            'jabberJid=user@example.test&jabberPasswd=example-secret&message=ready');
    }

    public function testThemeGetCannotChangeTheme()
    {
        $route = 'plugins/theme/action.php';
        $saved = $this->request($route, 'POST', [], ['theme' => 'Dark']);
        $this->assertSame(0, $saved['exit'], 'normal theme POST finishes: ' . $saved['err']);
        $path = $this->scratch . '/profile/settings/theme.dat';
        $this->assertTrue(is_file($path), 'normal theme POST stores the chosen theme');
        if (!is_file($path)) { return; }
        $bytes = file_get_contents($path);
        $this->assertRefused($route, ['theme' => 'Blue']);
        $this->assertSame($bytes, file_get_contents($path), 'GET preserves stored theme');
        $queryOnly = $this->request($route, 'POST', ['theme' => 'Blue']);
        $this->assertSame(0, $queryOnly['exit'], 'query-only theme POST finishes');
        $this->assertSame($bytes, file_get_contents($path), 'POST query cannot change theme');
    }

    public function testTrafficGetCannotClearHistoryButReadsRemain()
    {
        $route = 'plugins/trafic/getdata.php';
        $path = $this->scratch . '/profile/settings/trafic/global.csv';
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, 'historic-traffic');
        $read = $this->request($route, 'GET', ['mode' => 'day', 'tracker' => 'global']);
        $this->assertSame(0, $read['exit'], 'normal traffic GET finishes: ' . $read['err']);
        $this->assertTrue(is_array(json_decode($read['out'], true)), 'traffic GET still returns graph JSON');
        $this->assertRefused($route, ['mode' => 'clear', 'tracker' => 'global']);
        $this->assertTrue(is_file($path) && file_get_contents($path) === 'historic-traffic',
            'traffic clear GET preserves CSV');
        $queryOnly = $this->request($route, 'POST', ['mode' => 'clear', 'tracker' => 'global']);
        $this->assertSame(0, $queryOnly['exit'], 'query-only traffic POST finishes');
        $this->assertTrue(is_file($path) && file_get_contents($path) === 'historic-traffic',
            'query-only POST does not clear CSV');
        $clear = $this->request($route, 'POST', [], ['mode' => 'clear', 'tracker' => 'global']);
        $this->assertSame(0, $clear['exit'], 'normal clear POST finishes: ' . $clear['err']);
        clearstatcache(true, $path);
        $this->assertTrue(!is_file($path), 'normal clear POST removes traffic CSV');
    }
    public function testTracklabelsGetIsReadOnlyEvenWithoutBrowserHeaders()
    {
        $route = 'plugins/tracklabels/action.php';
        $response = $this->request($route, 'GET', ['tracker' => '127.0.0.1']);
        $this->assertSame(0, $response['exit'], 'tracker icon GET exits cleanly: ' . $response['err']);
        $this->assertTrue(!is_dir($this->scratch . '/profile/settings/trackers'),
            'missing icon GET cannot create cache or begin a favicon fetch');
    }

    public function testTracklabelsFetchRequiresSameOriginAjaxPostBody()
    {
        $route = 'plugins/tracklabels/action.php';
        $headers = ['HTTP_HOST' => 'localhost', 'REQUEST_SCHEME' => 'http',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_ORIGIN' => 'http://localhost'];
        $get = $this->request($route, 'GET', ['tracker' => '127.0.0.1', 'fetch' => '1']);
        $this->assertTrue(!is_dir($this->scratch . '/profile/settings/trackers'),
            'GET query flag cannot start a favicon fetch');
        $form = $this->request($route, 'POST', [],
            ['tracker' => '127.0.0.1', 'fetch' => '1']);
        $this->assertSame('Forbidden', $form['out'],
            'a plain cross-site form cannot warm favicon cache');
        $query = $this->request($route, 'POST', ['tracker' => '127.0.0.1', 'fetch' => '1'],
            [], null, $headers);
        $this->assertTrue(!is_dir($this->scratch . '/profile/settings/trackers'),
            'query-only POST cannot warm favicon cache');
        $foreign = $this->request($route, 'POST', [],
            ['tracker' => '127.0.0.1', 'fetch' => '1'],
            null, array_merge($headers, ['HTTP_ORIGIN' => 'http://foreign.test']));
        $this->assertSame('Forbidden', $foreign['out'],
            'AJAX header does not override a foreign Origin');
        $same = $this->request($route, 'POST', [],
            ['tracker' => '127.0.0.1', 'fetch' => '1'], null, $headers);
        $this->assertSame(0, $same['exit'], 'same-origin fetch exits cleanly: ' . $same['err']);
        $this->assertSame(404, $same['status'], 'blocked favicon fetch is a permanent miss');
        $this->assertSame('Favicon unavailable', $same['out'], 'POST returns no placeholder body');
        $log = $this->scratch . '/errors.log';
        $this->assertTrue(strpos(is_file($log) ? file_get_contents($log) : '', 'tracklabels:') === false,
            'a refused tracker host is an expected miss and is not logged');
        $this->assertTrue(!is_file($this->scratch . '/profile/settings/trackers/127.0.0.1.ico'),
            'same-origin fetch still refuses a private network address');
    }



    public function testTracklabelsUsesConfiguredProxyForPublicFavicon()
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertTrue($server !== false, 'synthetic favicon proxy listens: ' . $error);
        $port = (int)substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        $route = 'plugins/tracklabels/action.php';
        $post = ['tracker' => '93.184.216.34', 'fetch' => '1'];
        $headers = ['HTTP_HOST' => 'localhost', 'REQUEST_SCHEME' => 'http',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ORIGIN' => 'http://localhost'];
        $retryProc = null;
        list($proc, $pipes) = $this->startRequest($route, 'POST', [], $post, null, $headers, $port);
        try {
            $peer = @stream_socket_accept($server, 3);
            $this->assertTrue($peer !== false, 'favicon request reaches the configured proxy');
            if ($peer === false) { return; }
            stream_set_timeout($peer, 2);
            $wire = '';
            while (strpos($wire, "\r\n\r\n") === false && strlen($wire) < 8192) {
                $part = fread($peer, 4096);
                if ($part === false || $part === '') { break; }
                $wire .= $part;
            }
            $icon = base64_decode('R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=');
            fwrite($peer, "HTTP/1.1 200 OK\r\nContent-Type: image/gif\r\nContent-Length: "
                . strlen($icon) . "\r\n\r\n" . $icon);
            fclose($peer);
            $response = $this->finishRequest($proc, $pipes);
            $this->assertSame(0, $response['exit'], 'favicon route exits: ' . $response['err']);
            $this->assertTrue(strpos($wire, 'GET http://93.184.216.34/favicon.ico HTTP/') === 0,
                'public literal remains the proxy request target');
            $this->assertSame($icon, $response['out'], 'proxy-only favicon reaches the image response');
            $cache = $this->scratch . '/profile/settings/trackers/93.184.216.34.ico';
            $this->assertSame($icon, file_get_contents($cache),
                'proxy-only favicon is cached');
            unlink($cache);
            list($retryProc, $retryPipes) = $this->startRequest($route, 'POST', [], $post,
                null, $headers, $port);
            for ($i = 0; $i < 2; $i++) {
                $failedPeer = @stream_socket_accept($server, 3);
                $this->assertTrue($failedPeer !== false, 'unavailable favicon reaches proxy');
                if ($failedPeer === false) { return; }
                stream_set_timeout($failedPeer, 2);
                $request = '';
                while (strpos($request, "\r\n\r\n") === false && strlen($request) < 8192) {
                    $piece = fread($failedPeer, 4096);
                    if ($piece === false || $piece === '') { break; }
                    $request .= $piece;
                }
                fwrite($failedPeer, "HTTP/1.1 503 Unavailable\r\nContent-Length: 0\r\n\r\n");
                fclose($failedPeer);
            }
            $failed = $this->finishRequest($retryProc, $retryPipes);
            $this->assertSame(0, $failed['exit'], 'unavailable favicon exits: ' . $failed['err']);
            $this->assertSame(404, $failed['status'],
                'upstream failure is a settled miss, not a browser retry');
            $this->assertSame('Favicon unavailable', $failed['out'],
                'failed POST does not return the placeholder image');
            $this->assertTrue(!is_file($cache), 'failed fetch does not cache a placeholder');
        } finally {
            fclose($server);
            if (is_resource($proc)) { proc_terminate($proc); }
            if (is_resource($retryProc)) { proc_terminate($retryProc); }
        }
    }

    public function testTracklabelsCachedIconReadNeedsNoBrowserHeaders()
    {
        $dir = $this->scratch . '/profile/settings/trackers';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/example.test.ico', 'synthetic-icon');
        $response = $this->request('plugins/tracklabels/action.php', 'GET',
            ['tracker' => 'example.test']);
        $this->assertSame(0, $response['exit'], 'headerless cached icon GET exits: ' . $response['err']);
        $this->assertSame('synthetic-icon', $response['out'],
            'legacy image GET serves cached icon without Origin or Referer');
    }

    public function testTracklabelsUploadAndDeleteRequireSameOriginAjax()
    {
        $route = 'plugins/tracklabels/action.php';
        $dir = $this->scratch . '/profile/settings/trackers';
        mkdir($dir, 0777, true);
        $icon = $dir . '/example.test.png';
        file_put_contents($icon, 'keep');
        $form = $this->request($route, 'POST', ['tracker' => 'example.test'],
            ['delete' => 'on']);
        $this->assertSame('Forbidden', $form['out'], 'cross-site form cannot delete icon');
        $this->assertTrue(is_file($icon), 'denied icon deletion preserves cached file');
        $upload = $this->request($route, 'POST', ['tracker' => 'example.test'],
            ['upload' => 'on']);
        $this->assertSame('Forbidden', $upload['out'], 'cross-site form cannot upload icon');
        $before = $this->request($route, 'GET', ['tracker' => 'example.test']);
        $this->assertSame('keep', $before['out'], 'cached PNG read reaches seeded file');
        $headers = ['HTTP_HOST' => 'localhost', 'REQUEST_SCHEME' => 'http',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_ORIGIN' => 'http://localhost'];
        $conflict = $this->request($route, 'POST', ['tracker' => 'example.test'],
            ['tracker' => 'example.test', 'fetch' => '1', 'delete' => 'on'], null, $headers);
        $this->assertSame('Conflicting icon actions', $conflict['out'],
            'ambiguous favicon fetch/delete is refused');
        $this->assertTrue(is_file($icon), 'ambiguous action preserves the cached icon');
        $same = $this->request($route, 'POST', ['tracker' => 'example.test'],
            ['delete' => 'on'], null, $headers);
        $this->assertSame(0, $same['exit'], 'same-origin icon deletion exits cleanly: ' . $same['err']);
        clearstatcache(true, $icon);
        $this->assertTrue(!is_file($icon), 'same-origin icon deletion remains available');
    }

    private function startRequest($route, $method, $get = [], $post = [], $body = null,
        $headers = [], $proxyPort = null)
    {
        $env = [
            'RU_PROFILE_PATH' => $this->scratch . '/profile',
            'RU_LOG_FILE' => $this->scratch . '/errors.log',
            'ROUTE_FILE' => $this->root . '/' . $route,
            'ROUTE_ROOT' => $this->root,
            'ROUTE_METHOD' => $method,
            'ROUTE_GET' => json_encode($get),
            'ROUTE_POST' => json_encode($post),
            'ROUTE_BODY' => $body === null ? http_build_query($post) : $body,
            'ROUTE_HEADERS' => json_encode($headers),
            'ROUTE_SQL_TRACE' => $this->scratch . '/sql.log',
            'ROUTE_STATUS_FILE' => $this->scratch . '/status',
        ];
        if ($proxyPort !== null) { $env['ROUTE_PROXY_PORT'] = (string)$proxyPort; }
        $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr',
            $this->scratch . '/request.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, null, $env);
        if (!is_resource($proc)) { throw new RuntimeException('route child did not start'); }
        fclose($pipes[0]);
        return [$proc, $pipes];
    }

    private function finishRequest($proc, $pipes)
    {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        return ['exit' => $exit, 'out' => $out, 'err' => $err,
            'status' => (int) file_get_contents($this->scratch . '/status')];
    }

    private function request($route, $method, $get = [], $post = [], $body = null, $headers = [])
    {
        list($proc, $pipes) = $this->startRequest($route, $method, $get, $post, $body, $headers);
        return $this->finishRequest($proc, $pipes);
    }

    private function assertRefused($route, $get)
    {
        $response = $this->request($route, 'GET', $get);
        $this->assertSame(0, $response['exit'], $route . ' GET exits cleanly: ' . $response['err']);
        $this->assertSame('Method Not Allowed', $response['out'], $route . ' refuses the mutating GET');
    }

    private function assignedValue($script, $name)
    {
        $script = trim($script);
        $prefix = $name . ' = ';
        if (substr($script, 0, strlen($prefix)) !== $prefix || substr($script, -1) !== ';') {
            throw new RuntimeException($name . ' must be one JavaScript assignment'); }
        $value = json_decode(substr($script, strlen($prefix), -1), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException($name . ' must contain one JSON value'); }
        return $value;
    }

    private function assertSettingsGetRefused($plugin, $post, $cache, $body = null)
    {
        $route = 'plugins/' . $plugin . '/action.php';
        $saved = $this->request($route, 'POST', [], $post, $body);
        $this->assertSame(0, $saved['exit'], $plugin . ' normal POST finishes: ' . $saved['err']);
        $path = $this->scratch . '/profile/settings/' . $cache;
        $this->assertTrue(is_file($path), $plugin . ' normal POST stores settings');
        if (!is_file($path)) { return; }
        $bytes = file_get_contents($path);
        $this->assertRefused($route, []);
        $this->assertSame($bytes, file_get_contents($path), $plugin . ' GET preserves stored settings');
    }


}
