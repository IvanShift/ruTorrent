<?php

require_once(__DIR__ . '/../../php/TestCase.php');

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

// The real Snoopy error classifier, not a stand-in.
//
// The suites that need it reach ruTrackerChecker through loadClassDefinition()
// -- they eval the class body out of check.php and never run that file's
// require_once lines -- so the leaf its classifyFetchError() delegates to has
// to be loaded here. It is required rather than restated: a stub copy of the
// token/pattern pairs is exactly the drift this extraction removed, and
// a test suite carrying its own copy could not see the production one change.
// The file is a leaf with no dependencies of its own, so requiring it costs
// the suites nothing.
require_once(testFindRepoRoot() . '/plugins/rutracker_check/fetcherror.php');

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

// Where a quoted string that begins at $offset ends -- the offset just past its
// closing quote. Backticks count as quotes: shell_exec's operand is a string,
// and a brace inside one is not a brace. Escapes are honoured so that a literal
// ending in a backslash is not read as running on past its own closing quote.
//
// This and its two companions below exist because the shipped Alpine image
// loads no tokenizer extension, and a reader that only works on the developer's
// machine is a reader with a hole in it. They are deliberately narrow: each
// refuses what it cannot read rather than guessing at it.
function testEndOfStringLiteral($source, $offset, $where)
{
    $quote = $source[$offset];
    if ($quote !== "'" && $quote !== '"' && $quote !== '`')
        throw new RuntimeException('No string literal begins at offset ' . $offset . ' in ' . $where);
    $length = strlen($source);
    for ($i = $offset + 1; $i < $length; $i++) {
        if ($source[$i] === '\\') {
            $i++;
            continue;
        }
        if ($source[$i] === $quote) return $i + 1;
    }
    throw new RuntimeException('An unterminated string literal begins at offset '
        . $offset . ' in ' . $where);
}

// Where the comment that begins at $offset ends, or false when none begins
// there. An unterminated /* runs to the end of the file, which is what PHP
// itself does with one.
function testEndOfComment($source, $offset, $where)
{
    $two = substr($source, $offset, 2);
    if ($two === '/*') {
        $end = strpos($source, '*/', $offset + 2);
        return $end === false ? strlen($source) : $end + 2;
    }
    // "#[" opens a PHP 8 attribute, not a comment to the end of the line. This
    // project targets 7.4 and carries none; refuse rather than swallow a line.
    if ($two === '#[')
        throw new RuntimeException('An attribute at offset ' . $offset . ' in ' . $where
            . '; this reader knows comments, not attributes');
    if ($two === '//' || $source[$offset] === '#') {
        $end = strpos($source, "\n", $offset);
        return $end === false ? strlen($source) : $end + 1;
    }
    return false;
}

// Where the heredoc or nowdoc that begins at $offset ends, or false when none
// begins there. The body ends at the first line whose leading whitespace is
// followed by the label and then anything that cannot continue an identifier.
function testEndOfHeredoc($source, $offset, $where)
{
    if (substr($source, $offset, 3) !== '<<<') return false;
    $length = strlen($source);
    $i = $offset + 3;
    while ($i < $length && ($source[$i] === ' ' || $source[$i] === "\t")) $i++;
    $quote = ($i < $length && ($source[$i] === "'" || $source[$i] === '"')) ? $source[$i] : '';
    if ($quote !== '') $i++;
    $label = '';
    while ($i < $length && preg_match('/[A-Za-z0-9_]/', $source[$i])) {
        $label .= $source[$i];
        $i++;
    }
    if ($label === '' || ($quote !== '' && (!isset($source[$i]) || $source[$i] !== $quote)))
        throw new RuntimeException('A heredoc at offset ' . $offset . ' in ' . $where
            . ' has no label this reader can make out');
    if ($quote !== '') $i++;
    $newline = strpos($source, "\n", $i);
    if ($newline === false)
        throw new RuntimeException('A heredoc at offset ' . $offset . ' in ' . $where
            . ' never opens its body');
    $i = $newline + 1;
    while ($i <= $length) {
        $lineEnd = strpos($source, "\n", $i);
        $line = $lineEnd === false ? substr($source, $i) : substr($source, $i, $lineEnd - $i);
        $trimmed = ltrim($line, " \t");
        if (strpos($trimmed, $label) === 0) {
            $after = substr($trimmed, strlen($label), 1);
            if ($after === '' || !preg_match('/[A-Za-z0-9_]/', $after))
                return $i + (strlen($line) - strlen($trimmed)) + strlen($label);
        }
        if ($lineEnd === false) break;
        $i = $lineEnd + 1;
    }
    throw new RuntimeException('A heredoc opened at offset ' . $offset . ' in ' . $where
        . ' is never closed by its own label');
}

// The source of one function -- top-level or method -- out of a file the test
// must not require whole: the function-level companion to loadClassDefinition().
// A test that binds a double to the function it doubles needs the real
// declaration.
//
// The braces are matched over the code only. A brace inside a string, a
// comment or a heredoc is not a brace, and counting them over the raw text is
// exactly what this function exists not to do; the three readers above are how
// it tells the difference without the tokenizer extension, which the shipped
// Alpine image does not load. Checked against a token_get_all() implementation
// over every function in every PHP file of this repository: 4126 comparisons,
// byte-identical but for two methods named with reserved words (echo, match),
// which the tokenizer version could not find at all -- there this reader is the
// more capable of the two, never the less.
function loadFunctionDefinition($filename, $functionName)
{
    $source = file_get_contents($filename);
    $length = strlen($source);
    $start = null;
    $depth = 0;
    $i = 0;
    while ($i < $length) {
        $character = $source[$i];
        if ($character === "'" || $character === '"' || $character === '`') {
            $i = testEndOfStringLiteral($source, $i, $filename);
            continue;
        }
        $skip = testEndOfComment($source, $i, $filename);
        if ($skip === false) $skip = testEndOfHeredoc($source, $i, $filename);
        if ($skip !== false) {
            $i = $skip;
            continue;
        }
        if ($start === null) {
            // "function", as a whole word, then the wanted name as a whole word.
            if ($character === 'f' && substr($source, $i, 8) === 'function'
                && ($i === 0 || !preg_match('/[A-Za-z0-9_$\\\\]/', $source[$i - 1]))) {
                $after = $i + 8;
                while ($after < $length && strpos(" \t\r\n&", $source[$after]) !== false) $after++;
                if (substr($source, $after, strlen($functionName)) === $functionName) {
                    $tail = $after + strlen($functionName);
                    if ($tail >= $length || !preg_match('/[A-Za-z0-9_]/', $source[$tail])) {
                        $start = $i;
                        $i = $tail;
                        continue;
                    }
                }
            }
            $i++;
            continue;
        }
        if ($character === '{') {
            $depth++;
            $i++;
            continue;
        }
        if ($character === '}') {
            $depth--;
            if ($depth === 0) return substr($source, $start, $i + 1 - $start);
            $i++;
            continue;
        }
        $i++;
    }
    if ($start === null)
        throw new RuntimeException("Function {$functionName} was not found in {$filename}");
    throw new RuntimeException("Function {$functionName} in {$filename} has an unbalanced body");
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
                    if (class_exists('RuTrackerUpdatePass', false)
                        && property_exists('RuTrackerUpdatePass', 'checker')) {
                        strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', null);
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
    testAssertTrue($condition, $message);
}

// Verify the target and the daemon-side fence in a forum mapping branch.
function testAssertForumBranch($request, $hash, $forum, $message)
{
    $params = $request['commands'][0]->params;
    strictAssertSame($hash, $params[0], $message . ': target hash');
    strictAssertTrue(strpos($params[1], 'chk-topic') !== false
        && strpos($params[1], 'less=') !== false
        && strpos($params[1], 'chk-forum-version') !== false,
        $message . ': daemon-side generation fence');
    strictAssertTrue(strpos($params[2], 'chk-forum,' . $forum . '"') !== false
        && strpos($params[2], 'chk-forum-version,') !== false,
        $message . ': requested forum in branch body');
}

function strictAssertSame($expected, $actual, $message)
{
    testAssertSame($expected, $actual, $message);
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
    if (is_link($path) || (file_exists($path) && !is_dir($path))) {
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

function strictGetPrivateStatic($class, $property)
{
    $reflection = new ReflectionProperty($class, $property);
    if (PHP_VERSION_ID < 80100) $reflection->setAccessible(true);
    return $reflection->getValue();
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
    return fiDumpAt($topicId, $status, $hash, $seeders, 1);
}

/** fiDump() with the reg_time column spelled by the caller.
 *
 * Split out so a test can put a value there that does not parse. fiDump()'s
 * own placeholder 1 is a valid one, which is exactly why it cannot exercise
 * the unreadable-column path. */
function fiDumpAt($topicId, $status, $hash, $seeders, $regTime)
{
    return json_encode(array(
        'format' => array('topic_id' => array('tor_status', 'seeders', 'reg_time', 'tor_size_bytes',
            'keeping_priority', 'keepers', 'seeder_last_seen', 'info_hash', 'topic_poster', 'leechers')),
        'result' => array((string) $topicId => array($status, $seeders, $regTime, 2, 0, array(), 3, $hash, 4, 0)),
    ));
}

// The Snoopy failure sentences this plugin classifies, each paired with the
// token it must become.
//
// One table, read by three suites: the classifier's own FetchErrorTest and the
// two production callers that log the field -- ruTrackerChecker::makeClient()
// (CheckerTest) and NNMClubCheckImpl::guestFetch() (NNMClubHandlerTest). It
// lives here because "the same message means the same token whichever path
// logged it" is a property of the plugin, and a property no per-suite list can
// state: two lists agreeing is a coincidence that has to be maintained.
//
// The whitespace rows carry the weight. The two callers each normalised the
// message themselves and did it differently -- one collapsed internal runs,
// the other only trimmed -- so a re-spaced or wrapped sentence landed on its
// token down one path and on 'unclassified' down the other.
//
// The cases include errors emitted by php/Snoopy.class.inc.
function fetchErrorParityCases()
{
    return array(
        array('Invalid protocol "gopher"\n', 'invalid-protocol'),
        array('Refusing to fetch: cannot resolve host "nx.invalid".', 'refused-unresolvable-host'),
        array('Refusing to fetch: host "a.invalid" resolves to the non-public address 127.0.0.1.',
            'refused-non-public-address'),
        array('credential-redirect-refused', 'redirect-refused'),
        array('Error: cURL could not retrieve the document, error 6.', 'curl-transfer'),
        array('socket creation failed (-3)', 'socket-create'),
        array('dns lookup failure (-4)', 'dns-lookup'),
        array('connection refused or timed out (-5)', 'connect-refused'),
        array('connection failed (111)', 'connect-errno'),
        array('too-many-interim-responses', 'too-many-interim-responses'),
        array('missing-final-response', 'missing-final-response'),
        array('unsupported-transfer-encoding', 'unsupported-transfer-encoding'),
        array('invalid-or-oversized-chunked', 'invalid-or-oversized-chunked'),
        array('oversized-response', 'oversized-response'),
        array('invalid-or-oversized-gzip', 'invalid-or-oversized-gzip'),
        array('gzip-decoder-unavailable', 'gzip-decoder-unavailable'),
        array('unreadable-or-oversized-response', 'unreadable-or-oversized-response'),
        // Repeated spaces, a tab and a newline in one message. Under a
        // trim-only normalisation this is 'unclassified'.
        array("  \t connection   failed\t(111)\nhost bt4.t-ru.org  ", 'connect-errno'),
        array("dns   lookup\tfailure (-4)", 'dns-lookup'),
        array("\n socket\r\ncreation  failed (-3) \t", 'socket-create'),
        array("Refusing to fetch:  cannot  resolve\thost \"nx.invalid\".", 'refused-unresolvable-host'),
        // Not one of the known errors. The canary is shaped like an NNMClub passkey:
        // an unrecognised sentence is classified, never quoted, so no merge
        // that teaches the vendored Snoopy to name the URL it failed on can
        // put a credential in this plugin's log.
        array('Refusing to fetch: uk=AbCdEf0123456789AbCdEf0123456789 leaked', 'unclassified'),
        array('something php/Snoopy.class.inc does not say today', 'unclassified'),
    );
}

// The complete set of answers the classifier is allowed to give: the fixed
// tokens, the catch-all, and '' for "Snoopy wrote no message". A test that
// only checks known messages cannot see a raw-text fallback; one that checks
// membership can.
function fetchErrorTokenVocabulary()
{
    return array('', 'invalid-protocol', 'refused-unresolvable-host', 'refused-non-public-address',
        'redirect-refused', 'curl-transfer', 'socket-create', 'dns-lookup', 'connect-refused', 'connect-errno',
        'too-many-interim-responses', 'missing-final-response',
        'unsupported-transfer-encoding', 'invalid-or-oversized-chunked', 'oversized-response',
        'invalid-or-oversized-gzip', 'gzip-decoder-unavailable',
        'unreadable-or-oversized-response', 'unclassified');
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
    public static $defaultLocalProjectionResponse = null;
    private $commands = array();
    public $important = true;
    public $fault = false;
    public $faultString = '';
    // php/xmlrpc.php declares this next to faultString and leaves it null on a
    // clean answer. Consumers that need exact fault boundaries prefer it, so a
    // double that omits it silently reroutes them to the trimmed copy.
    public $rawFaultString = null;
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
        // Nothing queued stands for a transport that answered nothing: run()
        // false, no fault. php/xmlrpc.php cannot report a fault on a request
        // that did not run (see the fault rules below), so the fallback must
        // not either.
        $response = (isset(self::$responses[$key]) && count(self::$responses[$key]))
            ? array_shift(self::$responses[$key]) : null;
        if ($response === null && self::$defaultLocalProjectionResponse !== null
            && $key === 'branch' && isset($this->commands[0]->params[1], $this->commands[0]->params[2])
            && strpos($this->commands[0]->params[1], getCmd('d.get_local_id=')) !== false
            && strpos($this->commands[0]->params[2], '$' . getCmd('d.set_custom=')) !== false)
            $response = array(true, false, array(self::$defaultLocalProjectionResponse), '');
        if ($response === null) $response = array(false, false, array(), '');
        // php/xmlrpc.php declares fault false, faultString '' and
        // rawFaultString null (:79-81) and assigns all three together, only
        // inside the branch that saw a faultCode (:223-227). Two consequences
        // this double reproduces. First, makeNextCall() puts fault back to
        // false before every batch (:135) while nothing ever clears the two
        // strings, so a later clean run still carries the previous fault's
        // text. Second, that faultCode branch sits inside if($ret) (:221) and
        // $ret is what run() returns (:241), so a request that did not run
        // never reports a fault. Both are pinned against the real transport by
        // UpdatePassTest's 'the XMLRPC double models php/xmlrpc.php fault
        // fields across a sequence of answers'. The queued string is the raw
        // text, so a fixture can describe a fault whose boundaries matter.
        $queuedFault = isset($response[3]) ? (string) $response[3] : '';
        $this->fault = false;
        if ($response[0] && $response[1]) {
            $this->fault = true;
            $this->rawFaultString = $queuedFault;
            $this->faultString = trim($queuedFault);
        }
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
        self::$requests[count(self::$requests) - 1]['values'] = $this->val;
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
    // Behaviourally identical to plugins/erasedata/removewithdata.php's
    // function of the same name -- reformatted to this file's style, not copied
    // line for line -- and it must stay identical: ruTrackerChecker::
    // torrentExists() reads it, and that is the gate in front of every
    // irreversible step of the replacement transaction. A double that answers
    // ABSENT where production answers UNKNOWN (or the reverse) lets this suite
    // prove a step production would never take. Change it only together with
    // the real function: UpdatePassTest's 'the erasedata presence double is
    // bound to removewithdata.php' reads that file, compares the whitelists and
    // runs both functions over the same probes, so a one-sided change fails
    // there rather than passing quietly.
    function erasedataTorrentPresence($hash)
    {
        $probe = new rXMLRPCRequest(new rXMLRPCCommand(getCmd('d.hash'), $hash));
        $probe->important = false;
        if (!$probe->run()) return ERASEDATA_TORRENT_UNKNOWN;
        if ($probe->fault) {
            $msg = isset($probe->rawFaultString) && is_string($probe->rawFaultString)
                ? $probe->rawFaultString
                : (isset($probe->faultString) && is_string($probe->faultString) ? $probe->faultString : '');
            $missingFaults = array(
                'info-hash not found',
                'info-hash not found.',
                'could not find info-hash',
                'could not find info-hash.',
                'invalid parameters: info-hash not found',
            );
            if (in_array(strtolower($msg), $missingFaults, true))
                return ERASEDATA_TORRENT_ABSENT;
            return ERASEDATA_TORRENT_UNKNOWN;
        }
        if (count($probe->val) !== 1 || !is_string($probe->val[0]))
            return ERASEDATA_TORRENT_UNKNOWN;
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

    class Snoopy
    {
        const RESPONSE_BODY_FAILED = -101;
        public static $responses = array();
        // Catch-all queue, consulted only when $url has no exact match.
        // RuTracker's layer-2 probe URL carries a random peer_id tail and a
        // random key (RuTrackerAnnounce::makePeerId()/random_bytes()), so a
        // test can never know the exact URL in advance the way it does for
        // every other (deterministic) request this suite makes.
        public static $any = array();
        public static $requests = array();
        public static $rawheadersLog = array();
        public static $agentsLog = array();
        public static $beforeFetchSnapshots = array();

        public $lastredirectaddr = '';
        public $status = -1;
        public $results = '';
        public $headers = array();
        public $error = '';
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
            self::$agentsLog = array();
            self::$beforeFetchSnapshots = array();
        }

        // $headers models the response headers real Snoopy collects into
        // $this->headers (raw "Name: value" lines, see
        // php/Snoopy.class.inc:596). The request side ($rawheaders) is
        // recorded per request into $rawheadersLog, index-parallel to
        // $requests, for the tests that pin conditional-GET behaviour.
        public static function queue($url, $status, $results, $headers = array(), $redirect = null, $error = '')
        {
            if (!isset(self::$responses[$url])) {
                self::$responses[$url] = array();
            }
            self::$responses[$url][] = array($status, $results, $headers, $redirect, $error);
        }

        // A refusal before Snoopy receives a response leaves status/results
        // from the previous fetch on this same client, but returns false.
        public static function queueEarlyFailure($url, $error)
        {
            if (!isset(self::$responses[$url])) self::$responses[$url] = array();
            self::$responses[$url][] = array('earlyFailure' => $error);
        }

        // Model a fetchComplex() refusal after fresh HTTP bytes were recorded.
        public static function queueAnsweredFailure($url, $status, $results, $headers = array(), $redirect = null)
        {
            if (!isset(self::$responses[$url])) self::$responses[$url] = array();
            self::$responses[$url][] = array('answeredFailure' => array($status, $results, $headers, $redirect));
        }

        // loginmgr may refuse before it reaches Snoopy::fetch(), preserving
        // metadata from the preceding request on this reused client.
        public static function queueBeforeFetchFailure($url, $lastStatus = null, $lastBody = '')
        {
            if (!isset(self::$responses[$url])) self::$responses[$url] = array();
            self::$responses[$url][] = array('beforeFetchFailure' => array($lastStatus, $lastBody));
        }

        public static function queueAny($status, $results, $headers = array())
        {
            self::$any[] = array($status, $results, $headers);
        }

        private function respond($method, $url)
        {
            if (isset(self::$responses[$url][0]['beforeFetchFailure'])) {
                self::$beforeFetchSnapshots[] = array($this->status, $this->results,
                    $this->headers, $this->error, $this->lastredirectaddr);
                $queued = array_shift(self::$responses[$url]);
                list($lastStatus, $lastBody) = $queued['beforeFetchFailure'];
                if ($lastStatus !== null) {
                    $this->status = $lastStatus;
                    $this->results = $lastBody;
                }
                self::$requests[] = array($method, $url);
                self::$rawheadersLog[] = $this->rawheaders;
                self::$agentsLog[] = (string) $this->agent;
                return false;
            }
            // A fresh top-level Snoopy fetch clears the previous redirect and
            // error, even when it fails before a new HTTP response is received.
            $this->lastredirectaddr = '';
            $this->error = '';
            self::$requests[] = array($method, $url);
            self::$rawheadersLog[] = $this->rawheaders;
            // The agent the client actually held when it fetched. Recorded
            // beside the URL because for an announce probe the User-Agent
            // decides whether the request is answered at all: Cloudflare
            // refuses browser agents on the announce hosts, so a probe sent
            // with the wrong one is not a weaker probe, it is a 403.
            self::$agentsLog[] = (string) $this->agent;
            if (isset(self::$responses[$url]) && count(self::$responses[$url])) {
                $response = array_shift(self::$responses[$url]);
                if (isset($response['earlyFailure'])) {
                    $this->error = $response['earlyFailure'];
                    return false;
                }
                if (isset($response['answeredFailure'])) {
                    list($this->status, $this->results, $this->headers, $redirect) = $response['answeredFailure'];
                    $this->error = '';
                    if ($redirect !== null) $this->lastredirectaddr = $redirect;
                    return false;
                }
                list($this->status, $this->results, $this->headers, $redirect, $this->error) = $response;
                // A top-level fetch clears the previous redirect above; only
                // a new redirect sets this field.
                if ($redirect !== null) $this->lastredirectaddr = $redirect;
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
        const CHKMSG_SUCCESSOR_MISSING = 'successor-missing';
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
        public static $messages = array();
        public static $calls = array();
        public static $runLocalId = null;
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

        public static function activeRunLocalId($hash)
        {
            return self::$runLocalId;
        }

        public static function writeHandlerCustom($hash, $field, $value)
        {
            self::$calls[] = array('method' => __FUNCTION__,
                'arguments' => array($hash, $field, $value),
                'xmlrpc_count' => count(rXMLRPCRequest::$requests));
            $req = new rXMLRPCRequest(new rXMLRPCCommand(getCmd('d.set_custom'),
                array($hash, $field, (string) $value)));
            $req->important = false;
            return $req->success();
        }

        public static function awaitMetadata($hash)
        {
            return self::answer(__FUNCTION__, array($hash));
        }

        public static function reset()
        {
            self::$logs = array();
            self::$messages = array();
            self::$calls = array();
            self::$runLocalId = null;
            self::$results = array();
            self::$agents = array();
            Snoopy::reset();
            rXMLRPCRequest::reset();
        }

        public static function registerTracker($commentFilter, $announceFilter, $handler)
        {
        }

        // Every User-Agent this double was asked to send, in order. Without
        // it the double silently swallowed the production signature's fifth
        // argument and never set Snoopy::$agent, so the whole point of the
        // layer 2 fix -- that the probe does NOT go out as a browser -- had
        // no assertion anywhere: a refactor dropping the argument, or one
        // restoring the old unconditional browser agent, would have left every
        // suite green while layer 2 went back to answering 403 for ever.
        public static $agents = array();

        public static function makeClient($url, $method = 'GET', $contentType = '', $body = '',
            $agent = self::USER_AGENT)
        {
            self::$agents[] = (string) $agent;
            $client = new Snoopy();
            $client->agent = (string) $agent;
            $client->fetchComplex($url, $method, $contentType, $body);
            return $client;
        }

        // Kept byte-identical to the production text in check.php. A double
        // that formats a log line differently from the class it stands in for
        // lets an assertion pass here and fail there.
        public static function transportFailureDetail($status)
        {
            $status = (int) $status;
            if ($status === Snoopy::RESPONSE_BODY_FAILED)
                return 'transport=response-body status=' . $status . ' reason=body-refusal';
            if ($status < 0) {
                $reasons = array(-100 => 'timeout', -5 => 'connect', -4 => 'dns', -3 => 'socket-create');
                $reason = isset($reasons[$status]) ? $reasons[$status] : 'socket';
                return 'transport=socket status=' . $status . ' reason=' . $reason;
            }
            if ($status === 0) return 'transport=no-status reason=unset';
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

        public static function recordMissingSuccessor($hash, $successor)
        {
            self::$calls[] = array(
                'method' => __FUNCTION__,
                'arguments' => array($hash, $successor),
                'xmlrpc_count' => count(rXMLRPCRequest::$requests),
            );
            return true;
        }

        public static function retainMissingSuccessor($hash, $successor)
        {
            self::$calls[] = array(
                'method' => __FUNCTION__,
                'arguments' => array($hash, $successor),
                'xmlrpc_count' => count(rXMLRPCRequest::$requests),
            );
            return true;
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
