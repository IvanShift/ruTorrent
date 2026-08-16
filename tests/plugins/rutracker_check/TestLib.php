<?php

/**
 * Shared harness for the rutracker_check test suite.
 *
 * The always-defined part carries the runner, assertions and the XMLRPC test
 * doubles. Handler-facing stubs (Snoopy, a fake ruTrackerChecker, an identity
 * getCmd(), bencode fixture builders backed by the real Torrent class -- and,
 * transitively through requiring Torrent.php, the real FileUtil) are defined
 * only when the including test sets TESTLIB_HANDLER_STUBS, because
 * CheckerTest loads the real ruTrackerChecker and a fake Torrent instead.
 */

function testFindRepoRoot()
{
    $path = realpath(__DIR__ . '/../../..');
    if ($path !== false && is_file($path . '/plugins/rutracker_check/trackers/rutracker.php')) return $path;
    throw new RuntimeException('Unable to locate the ruTorrent repository root');
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
        foreach ($this->tests as $test) {
            list($name, $callback) = $test;
            try {
                call_user_func($callback);
                echo "ok - {$name}\n";
            } catch (Throwable $error) {
                $failures++;
                echo "not ok - {$name}\n";
                echo '  ' . get_class($error) . ': ' . $error->getMessage() . "\n";
            }
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

function strictInvoke($className, $method, $arguments = array())
{
    $reflection = new ReflectionMethod($className, $method);
    if (PHP_VERSION_ID < 80100) {
        $reflection->setAccessible(true);
    }
    return $reflection->invokeArgs(null, $arguments);
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

    public static function queue($commands, $ok, $fault, $values = array())
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
        self::$responses[$key][] = array($ok, $fault, $values);
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
            : array(false, true, array());
        $this->fault = $response[1];
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

class rTorrentSettings
{
    public $session = '/nonexistent/';
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

    // Identity command mapper. The real getCmd() (php/xmlrpc.php) resolves a
    // handful of legacy command names through rTorrentSettings' version-keyed
    // alias table; none of that table changes the shape of what any test in
    // this suite asserts on, so every rutracker_check test that needs
    // getCmd() (this file included) uses plain identity instead of pulling in
    // the real version-detection machinery.
    function getCmd($command)
    {
        return $command;
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
        }

        // $headers models the response headers real Snoopy collects into
        // $this->headers (raw "Name: value" lines, see
        // php/Snoopy.class.inc:596) — not $rawheaders, which is the request
        // side and stays unmodeled since nothing here asserts on it.
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

        const METADATA_POLL_US = 500000;
        const METADATA_WAIT_DEFAULT = 10;

        // Mirrors check.php's own awaitMetadata(): the real thing is a plain
        // d.is_meta poll, and the tests drive it through the queued XMLRPC
        // double exactly as production drives the real transport.
        public static function awaitMetadata($hash, $seconds = null)
        {
            global $rutrackerMetaWait;
            if (is_null($seconds))
                $seconds = isset($rutrackerMetaWait) ? $rutrackerMetaWait : self::METADATA_WAIT_DEFAULT;
            $seconds = max(0, (int) $seconds);
            $until = microtime(true) + $seconds;
            for (;;) {
                $meta = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.is_meta"), $hash));
                $meta->important = false;
                if ($meta->success() && isset($meta->val[0]) && (intval($meta->val[0]) === 0))
                    return true;
                if (microtime(true) >= $until)
                    return false;
                usleep(self::METADATA_POLL_US);
            }
        }

        // Mirrors check.php's chk-msg token vocabulary; the tests assert on
        // these constants rather than on the literals, exactly like the
        // production call sites do.
        const CHKMSG_SUPERSEDED = 'superseded';
        const CHKMSG_DELETING = 'deleting';
        const CHKMSG_TOPIC_STATUS = 'topic-status';
        const CHKMSG_FUSE = 'fuse';
        const CHKMSG_ABSORBED = 'absorbed';

        const USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
            . "AppleWebKit/537.36 (KHTML, like Gecko) "
            . "Chrome/120.0.0.0 Safari/537.36";

        public static $created = array();
        // $created only records payloads that parsed, so a handler that must
        // not reach createTorrent() at all is asserted against this counter.
        public static $createCalls = 0;
        public static $logs = array();
        public static $registrations = array();
        public static $createResult = null;
        public static $states = array();
        public static $messages = array();

        public static function reset()
        {
            self::$created = array();
            self::$createCalls = 0;
            self::$logs = array();
            self::$registrations = array();
            self::$createResult = null;
            self::$states = array();
            self::$messages = array();
            Snoopy::reset();
            rXMLRPCRequest::reset();
        }

        public static function registerTracker($commentFilter, $announceFilter, $handler)
        {
            self::$registrations[] = array($commentFilter, $announceFilter, $handler);
        }

        public static function makeClient($url, $method = 'GET', $contentType = '', $body = '')
        {
            $client = new Snoopy();
            $client->fetchComplex($url, $method, $contentType, $body);
            return $client;
        }

        // Mirrors check.php:76-83 (now public there too -- RuTrackerMetaFetch
        // is a separate class and needs to call this from outside).
        public static function torrentExists($hash)
        {
            $req = new rXMLRPCRequest(new rXMLRPCCommand(getCmd('d.hash'), $hash));
            $req->important = false;
            if (!$req->run())
                return null;
            return !$req->fault;
        }

        // Mirrors check.php:85-105's signature and null-on-missing-target
        // fallback; records every call for tests to assert against.
        public static function setState($hash, $state)
        {
            self::$states[] = array('hash' => $hash, 'state' => $state);
            $req = new rXMLRPCRequest(new rXMLRPCCommand(
                getCmd('d.set_custom'), array($hash, 'chk-state', (string) $state)));
            $req->important = false;
            if ($req->success())
                return true;
            return self::torrentExists($hash) === false ? null : false;
        }

        // Mirrors check.php:108-114; records every call for tests to assert
        // against.
        public static function setMessage($hash, $message)
        {
            self::$messages[] = array('hash' => $hash, 'message' => $message);
            $req = new rXMLRPCRequest(new rXMLRPCCommand(
                getCmd('d.set_custom'), array($hash, 'chk-msg', (string) $message)));
            $req->important = false;
            return $req->success();
        }

        public static function createTorrent($payload, $oldHash)
        {
            self::$createCalls++;
            $parsed = @new Torrent($payload);
            if ($parsed->errors() || strlen((string) $parsed->hash_info()) !== 40) {
                return self::STE_ERROR;
            }
            // Captured at call time so a test can assert the erase genuinely
            // preceded this replacement, not merely occurred somewhere in the
            // run (real createTorrent() refuses a surviving new hash).
            $newHash = (string) $parsed->hash_info();
            $erasedFirst = false;
            foreach (rXMLRPCRequest::$requests as $request)
                foreach ($request['commands'] as $command)
                    if ($command->command === getCmd('d.erase') && (string) $command->params === $newHash)
                        $erasedFirst = true;
            self::$created[] = array('payload' => $payload, 'old_hash' => $oldHash, 'erased_first' => $erasedFirst);
            return self::$createResult;
        }

        public static function logDebug($message)
        {
            self::$logs[] = $message;
        }
    }
}
