<?php

/**
 * Shared harness for the rutracker_check test suite.
 *
 * The always-defined part carries the runner, assertions, the XMLRPC test
 * doubles, the identity getCmd() and loadClassDefinition(). Handler-facing
 * stubs (Snoopy, a fake ruTrackerChecker, bencode fixture builders backed by
 * the real Torrent class -- and, transitively through requiring Torrent.php,
 * the real FileUtil) are defined only when the including test sets
 * TESTLIB_HANDLER_STUBS, because CheckerTest loads the real ruTrackerChecker
 * and a fake Torrent instead.
 */

function testFindRepoRoot()
{
    $path = realpath(__DIR__ . '/../../..');
    if ($path !== false && is_file($path . '/plugins/rutracker_check/trackers/rutracker.php')) return $path;
    throw new RuntimeException('Unable to locate the ruTorrent repository root');
}

// Identity command mapper by default. A focused test may install the real
// production-shaped alias names in $testCommandAliases to prove that nested
// rTorrent DSL remains valid after getCmd() rewrites legacy names.
function getCmd($command)
{
    $suffix = '';
    if ($command !== '' && substr($command, -1) === '=') {
        $command = substr($command, 0, -1);
        $suffix = '=';
    }
    if (isset($GLOBALS['testCommandAliases'])
        && is_array($GLOBALS['testCommandAliases'])
        && array_key_exists($command, $GLOBALS['testCommandAliases'])) {
        return $GLOBALS['testCommandAliases'][$command] . $suffix;
    }
    return $command . $suffix;
}

if (!defined('ERASEDATA_TORRENT_PRESENT')) define('ERASEDATA_TORRENT_PRESENT', 1);
if (!defined('ERASEDATA_TORRENT_ABSENT')) define('ERASEDATA_TORRENT_ABSENT', 0);
if (!defined('ERASEDATA_TORRENT_UNKNOWN')) define('ERASEDATA_TORRENT_UNKNOWN', -1);
if (!defined('ERASEDATA_FILE_ALIAS_SAME')) define('ERASEDATA_FILE_ALIAS_SAME', 1);
if (!defined('ERASEDATA_FILE_ALIAS_DISTINCT')) define('ERASEDATA_FILE_ALIAS_DISTINCT', 0);
if (!defined('ERASEDATA_FILE_ALIAS_UNKNOWN')) define('ERASEDATA_FILE_ALIAS_UNKNOWN', -1);

// The shared run-state primitive, loaded for real. It is a small standalone
// file with no application dependencies, and the suites that eval() only the
// ruTrackerChecker class body out of check.php (see loadClassDefinition below)
// never execute that file's own require_once lines -- so without this the
// class the checker calls would simply not exist under test.
require_once(dirname(__FILE__) . '/../../../plugins/rutracker_check/runstate.php');

// The source of one class out of a file the test must not require whole
// (check.php would pull in util.php and half the application with it).
function loadClassDefinition($filename, $className)
{
    $source = file_get_contents($filename);
    $offset = strpos($source, 'class ' . $className);
    if ($offset === false)
        throw new RuntimeException("Class {$className} was not found in {$filename}");
    // ruTrackerChecker is the final declaration in check.php.
    return substr($source, $offset);
}

class StrictTestSuite
{
    private $tests = array();

    public function test($name, $callback)
    {
        $this->tests[] = array($name, $callback);
    }

    // Register every public test* method of an object as a test case.
    public function addFromObject($object)
    {
        foreach (get_class_methods($object) as $method) {
            if (strpos($method, 'test') === 0) {
                $this->tests[] = array($method, array($object, $method));
            }
        }
    }

    public function run()
    {
        $failures = 0;
        // A PHP warning is a failed test, not noise above a green summary.
        // Round 3 shipped a case that printed four "Undefined array key"
        // warnings and still said ok: its double handed an associative row to
        // a helper the production code indexes numerically, so ZERO tracker
        // rows were parsed and the expected verdict fell out of the empty
        // values by accident. Nothing in the run said so -- the summary counts
        // only thrown Throwables, and the habit of grepping a run for
        // "not ok|Failed|Fatal" cannot see a warning by construction, which is
        // exactly how it survived a commit and two review rounds.
        //
        // '@' is still honoured. The plugin suppresses deliberately in a dozen
        // places (@json_decode, @parse_url, @new Torrent), and under the
        // suppressor PHP 8 leaves a fixed mask in error_reporting() holding
        // none of these bits, so the guard below re-raises exactly what was
        // NOT suppressed and stays silent about what was.
        set_error_handler(function ($errno, $message, $file, $line) {
            if (!(error_reporting() & $errno)) return false;
            throw new ErrorException($message, 0, $errno, $file, $line);
        });
        try {
            foreach ($this->tests as $test) {
                list($name, $callback) = $test;
                $savedGlobals = array(
                    'rutrackerLayer2Enabled' => $GLOBALS['rutrackerLayer2Enabled'] ?? null,
                    'rutrackerAnnounceCap' => $GLOBALS['rutrackerAnnounceCap'] ?? null,
                    'ignoreLabels' => $GLOBALS['ignoreLabels'] ?? null,
                    'rutrackerCheckDebug' => $GLOBALS['rutrackerCheckDebug'] ?? null,
                    'rutrackerMetaWait' => $GLOBALS['rutrackerMetaWait'] ?? null,
                    'rutrackerFuseShare' => $GLOBALS['rutrackerFuseShare'] ?? null,
                    'rutrackerFuseFloor' => $GLOBALS['rutrackerFuseFloor'] ?? null,
                );
                try {
                    call_user_func($callback);
                    echo "ok - {$name}\n";
                } catch (Throwable $error) {
                    $failures++;
                    echo "not ok - {$name}\n";
                    echo '  ' . get_class($error) . ': ' . $error->getMessage() . "\n";
                } finally {
                    foreach ($savedGlobals as $k => $v) {
                        if ($v === null) unset($GLOBALS[$k]);
                        else $GLOBALS[$k] = $v;
                    }
                    if (class_exists('rXMLRPCRequest', false) && method_exists('rXMLRPCRequest', 'reset')) {
                        rXMLRPCRequest::reset();
                    }
                    if (class_exists('ruTrackerChecker', false) && method_exists('ruTrackerChecker', 'reset')) {
                        ruTrackerChecker::reset();
                    }
                    if (class_exists('Snoopy', false) && method_exists('Snoopy', 'reset')) {
                        Snoopy::reset();
                    }
                    if (class_exists('rTorrentSettings', false)
                        && property_exists('rTorrentSettings', 'instance')) {
                        strictSetPrivateStatic('rTorrentSettings', 'instance', null);
                    }
                    if (class_exists('RuTrackerUpdatePass', false)) {
                        foreach (array('checker', 'foreignAuthoritativeResolver') as $property) {
                            if (property_exists('RuTrackerUpdatePass', $property)) {
                                strictSetPrivateStatic('RuTrackerUpdatePass', $property, null);
                            }
                        }
                    }
                }
            }
        } finally {
            // Restored rather than left installed: a suite file may do work of
            // its own after run(), and this handler is the loop's policy, not
            // the process's.
            restore_error_handler();
        }
        echo count($this->tests) . ' tests, ' . $failures . " failures\n";
        return $failures === 0 ? 0 : 1;
    }
}

function strictAssertTrue($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function strictAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '; expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
}

// Every log line this plugin writes must be English. The UI text moved into
// plugins/rutracker_check/lang/*.js (the plugin writes a chk-msg token and the
// browser renders it), so what is left in PHP is log lines only, and the log is
// read by whoever maintains the plugin rather than by the torrent's owner.
// Plain printable ASCII is the check: it rejects Cyrillic prose without
// pretending to judge grammar.
function strictAssertEnglish($text, $message)
{
    strictAssertTrue(is_string($text) && $text !== '', $message . '; not a non-empty string');
    strictAssertTrue(preg_match('/^[\x09\x20-\x7E]+$/', $text) === 1,
        $message . '; log line is not plain-ASCII English: ' . $text);
}

// Every recorded line is English AND carries no passkey. The pair is one rule,
// asserted together in a dozen places: the log is developer-facing, so it is
// written in English, and it is written to a file the user may hand to anyone,
// so it must never contain the credential the request used.
function strictAssertLogsClean($logs, $secret, $what)
{
    foreach ((array) $logs as $line) {
        strictAssertEnglish($line, 'every ' . $what . ' log line');
        strictAssertTrue(strpos((string) $line, $secret) === false,
            'no ' . $what . ' log line carries the passkey: ' . $line);
    }
}

// The recorded log lines containing $needle. Log assertions name the line they
// mean rather than its index, so adding a diagnostic elsewhere in the same
// flow cannot silently retarget an existing assertion.
function strictLogsMatching($logs, $needle)
{
    return array_values(array_filter((array) $logs, function ($line) use ($needle) {
        return strpos((string) $line, $needle) !== false;
    }));
}

function strictAssertOneLogMatching($logs, $needle, $message)
{
    $matched = strictLogsMatching($logs, $needle);
    strictAssertSame(1, count($matched), $message . '; expected exactly one line containing "'
        . $needle . '", saw ' . var_export($logs, true));
    return $matched[0];
}

function strictRemoveTree($path)
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path), array('.', '..')) as $entry) {
        strictRemoveTree($path . '/' . $entry);
    }
    @rmdir($path);
}

function strictSetPrivateStatic($className, $property, $value)
{
    $reflection = new ReflectionProperty($className, $property);
    if (PHP_VERSION_ID < 80100) {
        $reflection->setAccessible(true);
    }
    $reflection->setValue(null, $value);
}

// A per-forum dump exactly as RuTracker serves it. It lives here rather than in
// a suite because the SCHEMA is the tracker's, not any one test's: two suites
// need it (the parser's own, and the handler's end-to-end flow) and a schema
// change had to be made in both copies or they would silently disagree.
function fiDump($topicId, $status, $hash, $seeders = 7)
{
    return json_encode(array(
        'format' => array('topic_id' => array('tor_status', 'seeders', 'reg_time', 'tor_size_bytes',
            'keeping_priority', 'keepers', 'seeder_last_seen', 'info_hash', 'topic_poster', 'leechers')),
        'result' => array((string) $topicId => array($status, $seeders, 1, 2, 0, array(), 3, $hash, 4, 0)),
    ));
}

function strictInvoke($className, $method, $arguments = array())
{
    $reflection = new ReflectionMethod($className, $method);
    if (PHP_VERSION_ID < 80100) {
        $reflection->setAccessible(true);
    }
    return $reflection->invokeArgs(null, $arguments);
}

function strictWithStateDir($prefix, $callback)
{
    $tmp = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(4));
    if (!mkdir($tmp, 0777, true)) {
        throw new RuntimeException('Unable to create temporary state directory: ' . $tmp);
    }
    strictSetPrivateStatic('RuTrackerState', 'dir', $tmp);
    try {
        return $callback($tmp);
    } finally {
        strictRemoveTree($tmp);
        strictSetPrivateStatic('RuTrackerState', 'dir', null);
    }
}

// What ruTorrent's shared APPLICATION LOG actually received while $body ran.
//
// The only capture that can tell this plugin's two log channels apart.
// ruTrackerChecker::logDebug() is gated on $rutrackerCheckDebug, which
// conf.php ships as FALSE; ruTrackerChecker::logUnrepairable() is not. Both
// end at FileUtil::toLog(), which writes whenever $log_file is set -- so a
// body run at the shipped default reaches this file through the ungated
// channel and no other.
//
// Which is load-bearing, and not a stylistic preference: the handler stub's
// own logDebug() below records into ruTrackerChecker::$logs UNGATED, so a
// test that asserts on that array cannot tell a line that reaches an operator
// from one that says nothing at all in production. That is exactly how a
// permanently wedged refusal stayed silent at the shipped default while its
// test passed.
//
// $debug defaults to that shipped false. Pass true only to read back a line
// that is SUPPOSED to be debug-gated.
function testCapturedAppLog($body, $debug = false)
{
    $file = tempnam(sys_get_temp_dir(), 'chk-app-log');
    $savedFile = array_key_exists('log_file', $GLOBALS) ? $GLOBALS['log_file'] : null;
    $savedDebug = array_key_exists('rutrackerCheckDebug', $GLOBALS)
        ? $GLOBALS['rutrackerCheckDebug'] : null;
    $GLOBALS['log_file'] = $file;
    $GLOBALS['rutrackerCheckDebug'] = $debug;
    try {
        $body();
        return (string) @file_get_contents($file);
    } finally {
        @unlink($file);
        if ($savedFile === null) unset($GLOBALS['log_file']);
        else $GLOBALS['log_file'] = $savedFile;
        if ($savedDebug === null) unset($GLOBALS['rutrackerCheckDebug']);
        else $GLOBALS['rutrackerCheckDebug'] = $savedDebug;
    }
}

class rXMLRPCCommand
{
    public $command;
    public $params;

    public function __construct($command, $params = null)
    {
        $this->command = $command;
        $this->params = $params;
    }
}

/**
 * XMLRPC test double. Responses are queued per command-name pipeline
 * ('d.hash' or array('d.get_state', 'd.is_open')); every executed request is
 * recorded with its full command objects so tests can assert the parameters
 * (e.g. WHICH hash a d.hash probe targeted), not just the command sequence.
 */
class rXMLRPCRequest
{
    public static $responses = array();
    public static $requests = array();
    private $commands = array();
    public $important = true;
    public $fault = false;
    public $faultString = '';
    public $val = array();

    public function __construct($commands = null)
    {
        if (is_array($commands))
            $this->commands = $commands;
        elseif ($commands !== null)
            $this->commands[] = $commands;
    }

    public function addCommand($command)
    {
        $this->commands[] = $command;
    }

    public static function reset()
    {
        self::$responses = array();
        self::$requests = array();
    }

    public static function queue($commands, $ok, $fault, $values = array(), $faultString = null)
    {
        // The real transport (php/xmlrpc.php rXMLRPCRequest::run()) parses
        // the SCGI answer with one flat regex over the whole XML document,
        // however many commands or however deeply nested XMLRPC arrays
        // (e.g. t.multicall's own <array><data>) it contains -- $val is
        // always a flat list of scalars, never an array of rows. A queued
        // value that nests an array inside $values describes a response the
        // transport cannot produce; reject it here so a test built on that
        // fiction fails loudly at queue time instead of silently passing.
        // A Closure is exempt: it is resolved lazily at execute() time (see
        // CheckerTest's queueLoadConfirmed()), and its own body must still
        // return a flat array when called.
        if (is_array($values)) {
            foreach ($values as $value) {
                if (is_array($value)) {
                    throw new InvalidArgumentException(
                        'rXMLRPCRequest::queue(): nested array value queued for "' . (is_array($commands) ? implode('|', $commands) : $commands)
                        . '" -- the real transport only ever returns a flat list of scalars, flatten the fixture instead'
                    );
                }
            }
        }
        $key = is_array($commands) ? implode('|', $commands) : $commands;
        if ($faultString === null)
            $faultString = ($ok && $fault && $key === getCmd('d.hash')) ? 'info-hash not found' : '';
        self::$responses[$key][] = array($ok, $fault, $values, $faultString);
    }

    public static function requestsFor($key)
    {
        $matched = array();
        foreach (self::$requests as $request)
            if ($request['key'] === $key)
                $matched[] = $request;
        return $matched;
    }

    private function execute()
    {
        $key = implode('|', array_map(function ($command) { return $command->command; }, $this->commands));
        self::$requests[] = array('key' => $key, 'important' => $this->important, 'commands' => $this->commands);
        $response = (isset(self::$responses[$key]) && count(self::$responses[$key]))
            ? array_shift(self::$responses[$key])
            : array(false, true, array(), '');
        $this->fault = $response[1];
        $this->faultString = isset($response[3]) ? (string) $response[3] : '';
        // A lazily-computed value is queued as a Closure (see CheckerTest's
        // queueLoadConfirmed()); every other value is a plain literal, most
        // often a two-element array of strings. is_callable() would treat
        // that shape as a ['Class', 'method'] callable and probe the
        // autoloader for a class named after the first element -- harmless
        // (the probe fails and the literal array is used regardless) but
        // noisy when $al_diagnostic is on (conf/config.php default), and
        // wasted work either way. Closure is the only callable this double
        // ever needs to recognise.
        $this->val = ($response[2] instanceof Closure) ? call_user_func($response[2], $this->commands) : $response[2];
        // A successful d.set_custom returns one scalar per command in the real
        // XMLRPC response. Most legacy fixtures used [] merely because they
        // did not inspect those scalars; make that shorthand realistic while
        // preserving every explicitly nonempty short list (e.g. [0] for a
        // truncated two-command response).
        if ($response[0] && !$response[1] && is_array($this->val) && !count($this->val)
            && count($this->commands)) {
            $settersOnly = true;
            foreach ($this->commands as $command)
                if ($command->command !== getCmd('d.set_custom')) {
                    $settersOnly = false;
                    break;
                }
            if ($settersOnly) $this->val = array_fill(0, count($this->commands), 0);
        }
        return $response[0];
    }

    public function run($trusted = true)
    {
        return $this->execute();
    }

    public function success($trusted = true)
    {
        return $this->execute() && !$this->fault;
    }
}

if (!function_exists('erasedataTorrentPresence')) {
    function erasedataTorrentPresence($hash)
    {
        $probe = new rXMLRPCRequest(new rXMLRPCCommand(getCmd('d.hash'), $hash));
        $probe->important = false;
        if (!$probe->run()) return ERASEDATA_TORRENT_UNKNOWN;
        if ($probe->fault)
            return preg_match('/(?:info-hash\s+not\s+found|could\s+not\s+find\s+info-hash)/i',
                $probe->faultString) === 1 ? ERASEDATA_TORRENT_ABSENT : ERASEDATA_TORRENT_UNKNOWN;
        if (!is_array($probe->val) || count($probe->val) !== 1 || !is_string($probe->val[0]))
            return ERASEDATA_TORRENT_UNKNOWN;
        if ($probe->val[0] === '') return ERASEDATA_TORRENT_ABSENT;
        return strcasecmp($probe->val[0], $hash) === 0
            ? ERASEDATA_TORRENT_PRESENT : ERASEDATA_TORRENT_UNKNOWN;
    }
}

if (!function_exists('erasedataExactFileAlias')) {
    function erasedataExactFileAlias($leftIdentity, $rightIdentity)
    {
        if (!is_array($leftIdentity) || !is_array($rightIdentity)) return ERASEDATA_FILE_ALIAS_UNKNOWN;
        if (empty($leftIdentity['exists']) || empty($rightIdentity['exists'])) return ERASEDATA_FILE_ALIAS_DISTINCT;
        if (!isset($leftIdentity['stat']['dev'], $leftIdentity['stat']['ino'],
            $rightIdentity['stat']['dev'], $rightIdentity['stat']['ino'])) return ERASEDATA_FILE_ALIAS_UNKNOWN;
        return $leftIdentity['stat']['dev'] === $rightIdentity['stat']['dev']
            && $leftIdentity['stat']['ino'] === $rightIdentity['stat']['ino']
            ? ERASEDATA_FILE_ALIAS_SAME : ERASEDATA_FILE_ALIAS_DISTINCT;
    }
}

class rTorrentSettings
{
    public $session = '/nonexistent/';
    // The daemon's own default download directory (rTorrent's get_directory).
    // Empty by default, which is what a settings object that never reached
    // the daemon looks like; metafetch falls back to $topDirectory then.
    public $directory = '';
    private static $instance;

    public static function get()
    {
        if (!self::$instance)
            self::$instance = new self();
        return self::$instance;
    }
}

if (defined('TESTLIB_HANDLER_STUBS')) {

    $testLibRepoRoot = testFindRepoRoot();
    $testLibPrevCwd = getcwd();
    chdir($testLibRepoRoot . '/php');
    require_once($testLibRepoRoot . '/php/Torrent.php');
    chdir($testLibPrevCwd);

    if (!function_exists('iconv')) {
        function iconv($from, $to, $content)
        {
            $utf8 = 'Поглощено';
            $cp1251 = "\xCF\xEE\xE3\xEB\xEE\xF9\xE5\xED\xEE";
            if (stripos($from, 'UTF-8') === 0 && stripos($to, 'CP1251') === 0) {
                return str_replace($utf8, $cp1251, $content);
            }
            if (stripos($from, 'CP1251') === 0 && stripos($to, 'UTF-8') === 0) {
                return str_replace($cp1251, $utf8, $content);
            }
            return false;
        }
    }

    // FileUtil itself needs no stub here: requiring Torrent.php above already
    // pulls in the real php/util.php, which autoloads the real FileUtil
    // (util.php:59's own FileUtil::getProfilePath() call) before this point.

    // Fixtures use the production encoder, so they are byte-identical to what
    // the plugin itself produces and re-parses.
    class TorrentEncoder extends Torrent
    {
        public static function raw($value)
        {
            return self::encode($value);
        }
    }

    function strictTorrentRaw($name, $announce, $comment = '', $announceList = null, $extra = array())
    {
        $root = array(
            'announce' => $announce,
            'info' => array(
                'length' => 1,
                'name' => $name,
                'piece length' => 16384,
                'pieces' => str_repeat("\0", 20),
            ),
        );
        if ($comment !== '') {
            $root['comment'] = $comment;
        }
        if ($announceList !== null) {
            $root['announce-list'] = $announceList;
        }
        foreach ($extra as $key => $value) {
            $root[$key] = $value;
        }
        return TorrentEncoder::raw($root);
    }

    // Built by hand: Torrent::encode drops dictionary keys that start with a
    // NUL byte, and scrape dictionaries are keyed by raw 20-byte hashes.
    function strictScrapePayload($hash, $found)
    {
        if (!$found) {
            return 'd5:filesdee';
        }
        return 'd5:filesd20:' . hex2bin($hash)
            . 'd8:completei1e10:downloadedi1e10:incompletei0eee'
            . 'e';
    }

    function strictCp1251($html)
    {
        $encoded = iconv('UTF-8', 'CP1251//IGNORE', $html);
        if ($encoded === false) {
            throw new RuntimeException('Unable to create CP1251 test fixture');
        }
        return $encoded;
    }

    class Snoopy
    {
        public static $responses = array();
        // Catch-all queue, consulted only when $url has no exact match.
        // RuTracker's layer-2 probe URL carries a random peer_id tail and a
        // random key (RuTrackerAnnounce::makePeerId()/random_bytes()), so a
        // test can never know the exact URL in advance the way it does for
        // every other (deterministic) request this suite makes.
        public static $any = array();
        public static $requests = array();
        public static $rawheadersLog = array();

        public $status = -1;
        public $results = '';
        public $headers = array();
        public $rawheaders = array();
        public $read_timeout = 0;
        public $_fp_timeout = 0;
        public $agent = '';

        public static function reset()
        {
            self::$responses = array();
            self::$any = array();
            self::$requests = array();
            self::$rawheadersLog = array();
        }

        // $headers models the response headers real Snoopy collects into
        // $this->headers (raw "Name: value" lines, see
        // php/Snoopy.class.inc:596). The request side ($rawheaders) is
        // recorded per request into $rawheadersLog, index-parallel to
        // $requests, for the tests that pin conditional-GET behaviour.
        public static function queue($url, $status, $results, $headers = array())
        {
            if (!isset(self::$responses[$url])) {
                self::$responses[$url] = array();
            }
            self::$responses[$url][] = array($status, $results, $headers);
        }

        public static function queueAny($status, $results, $headers = array())
        {
            self::$any[] = array($status, $results, $headers);
        }

        private function respond($method, $url)
        {
            self::$requests[] = array($method, $url);
            self::$rawheadersLog[] = $this->rawheaders;
            if (isset(self::$responses[$url]) && count(self::$responses[$url])) {
                list($this->status, $this->results, $this->headers) = array_shift(self::$responses[$url]);
                return true;
            }
            if (count(self::$any)) {
                list($this->status, $this->results, $this->headers) = array_shift(self::$any);
                return true;
            }
            throw new RuntimeException("Unexpected {$method} request: {$url}");
        }

        public function fetch($url, $method = 'GET', $contentType = '', $body = '')
        {
            return $this->respond('fetch', $url);
        }

        public function fetchComplex($url, $method = 'GET', $contentType = '', $body = '')
        {
            return $this->respond('fetchComplex', $url);
        }

        public function setcookies()
        {
        }
    }

    class ruTrackerChecker
    {
        const STE_INPROGRESS = 1;
        const STE_UPDATED = 2;
        const STE_UPTODATE = 3;
        const STE_DELETED = 4;
        const STE_CANT_REACH_TRACKER = 5;
        const STE_ERROR = 6;
        const STE_NOT_NEED = 7;
        const STE_IGNORED = 8;
        const STE_META_PENDING = 9;
        const STE_ABSORBED = 10;
        // Not a status but a handler answer: "no data to judge by, keep the
        // stored verdict". Mirrors check.php's own constant.
        const STE_UNCHANGED = -1;
        const STE_DECLINED = -2;

        // Mirrors check.php's chk-msg token vocabulary; the tests assert on
        // these constants rather than on the literals, exactly like the
        // production call sites do.
        const CHKMSG_SUPERSEDED = 'superseded';
        const CHKMSG_DELETING = 'deleting';
        // Mirrors check.php: metafetch reads it to tell an activation the
        // replacement confirmed from one it left unfinished.
        const REPLACEMENT_MARKER_KEY = 'chk-replacement';

        const CHKMSG_TOPIC_STATUS = 'topic-status';
        const CHKMSG_FUSE = 'fuse';
        const CHKMSG_ABSORBED = 'absorbed';

        const USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
            . "AppleWebKit/537.36 (KHTML, like Gecko) "
            . "Chrome/120.0.0.0 Safari/537.36";

        public static $logs = array();
        public static $registrations = array();
        public static $messages = array();
        public static $calls = array();
        private static $results = array();

        public static function queueResult($method, $result)
        {
            if (!array_key_exists($method, self::$results))
                self::$results[$method] = array();
            self::$results[$method][] = $result;
        }

        public static function callsFor($method)
        {
            return array_values(array_filter(self::$calls, function ($call) use ($method) {
                return $call['method'] === $method;
            }));
        }

        private static function answer($method, $arguments)
        {
            self::$calls[] = array(
                'method' => $method,
                'arguments' => $arguments,
                'xmlrpc_count' => count(rXMLRPCRequest::$requests),
            );
            if (!array_key_exists($method, self::$results) || !count(self::$results[$method]))
                throw new RuntimeException('No TestLib result queued for ruTrackerChecker::' . $method);
            return array_shift(self::$results[$method]);
        }

        public static function awaitMetadata($hash)
        {
            return self::answer(__FUNCTION__, array($hash));
        }

        public static function reset()
        {
            self::$logs = array();
            self::$registrations = array();
            self::$messages = array();
            self::$calls = array();
            self::$results = array();
            Snoopy::reset();
            rXMLRPCRequest::reset();
        }

        public static function registerTracker($commentFilter, $announceFilter, $handler)
        {
            self::$registrations[] = array($commentFilter, $announceFilter, $handler);
        }

        public static function isForeignComment($comment)
        {
            if ((string)$comment === '')
                return false;
            foreach (self::$registrations as $reg) {
                if (preg_match($reg[0], (string)$comment)) {
                    return $reg[2] !== 'RuTrackerCheckImpl::download_torrent';
                }
            }
            if (preg_match('/kinozal\.|nnmclub\.|nnm-club\.|toloka\.|tfile\.|anidub\.|tapochek\./i', (string)$comment)) {
                return true;
            }
            return false;
        }

        public static function hasForeignAuthoritativeComment($hash)
        {
            return false;
        }

        public static function makeClient($url, $method = 'GET', $contentType = '', $body = '')
        {
            $client = new Snoopy();
            $client->fetchComplex($url, $method, $contentType, $body);
            return $client;
        }

        public static function transportFailureDetail($status)
        {
            $status = (int) $status;
            if ($status < 0) {
                $reasons = array(-100 => 'timeout', -5 => 'connect', -4 => 'dns', -3 => 'socket-create');
                $reason = isset($reasons[$status]) ? $reasons[$status] : 'socket';
                return 'transport=socket status=' . $status . ' reason=' . $reason;
            }
            $reasons = array(
                5 => 'proxy-dns', 6 => 'dns', 7 => 'connect', 28 => 'timeout',
                35 => 'tls', 51 => 'tls-certificate', 52 => 'empty-reply',
                56 => 'receive', 60 => 'tls-certificate',
            );
            $reason = isset($reasons[$status]) ? $reasons[$status] : 'curl';
            return 'transport=curl-exit code=' . $status . ' reason=' . $reason;
        }

        public static function fetchStatusDetail($status)
        {
            if ($status === null || $status === '') return '';
            $status = (int) $status;
            return $status < 100 ? self::transportFailureDetail($status) : 'http-status=' . $status;
        }

        public static function torrentExists($hash)
        {
            return self::answer(__FUNCTION__, array($hash));
        }

		public static function isPluginReplacementMarker($value)
		{
			return RuTrackerReplacementRecord::isPluginMarker($value);
		}

		public static function encodeInheritance($oldHash, $wasStarted, $wasOpen, $now)
		{
			return RuTrackerReplacementRecord::encode($oldHash, $wasStarted, $wasOpen, $now);
		}

		public static function decodeInheritance($value)
		{
			return RuTrackerReplacementRecord::decode($value);
		}

        public static function setMessage($hash, $message)
        {
            self::$messages[] = array('hash' => $hash, 'message' => $message);
            self::$calls[] = array(
                'method' => __FUNCTION__,
                'arguments' => array($hash, $message),
                'xmlrpc_count' => count(rXMLRPCRequest::$requests),
            );
            return true;
        }

        // Torrent|null, exactly like the real one: the single place downloaded
        // bytes are decoded. A handler under test queues the parsed object it
        // expects to travel on, or null for "these bytes are not metainfo".
        public static function parseMetainfo($payload)
        {
            return self::answer(__FUNCTION__, array($payload));
        }

        public static function createTorrentFromDownload($client, $hash, $oldTorrent = null)
        {
            return self::answer(__FUNCTION__, array($client, $hash, $oldTorrent));
        }

        // $torrent is an already parsed Torrent, never bytes.
        public static function createTorrent($torrent, $oldHash, $oldTorrent = null)
        {
            return self::answer(__FUNCTION__, array($torrent, $oldHash, $oldTorrent));
        }

        public static function logDebug($message)
        {
            self::$logs[] = $message;
        }

        // Deliberately NOT recorded beside logDebug() above, and deliberately
        // the real ungated write: this is the channel whose whole purpose is
        // reaching ruTorrent's application log at conf.php's shipped
        // $rutrackerCheckDebug = false. Recording it into the same array as
        // the gated one would make the two indistinguishable to every test
        // that reads it -- which is the fault this stub already caused once.
        // testCapturedAppLog() is how a test reads this channel back.
        public static function logUnrepairable($message)
        {
            FileUtil::toLog('rutracker_check: ' . preg_replace('/[\r\n]+/', ' ', (string) $message));
        }
    }
}
