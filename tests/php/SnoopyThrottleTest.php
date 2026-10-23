<?php

// Snoopy throttle groups (Snoopy::throttleHosts): every http/https wire request
// to a group's hosts and their subdomains -- from any client, redirect hops
// included -- takes a turn from one flock-guarded state file in the shared
// settings path, so separate processes keep one pace, and an HTTP 429 pauses
// the group everywhere. Snoopy, the state file and the child processes are
// real; only curl's wire answers come from tests/php/ScriptedCurlFixture.php.
// Wire times are the fixture's: hrtime() when the stand-in started, the same
// monotonic clock in every process, so they compare across processes. They
// come after the stand-in's own process and PHP startup, though: a late start
// only lengthens a measured wait, so a lower bound may use them, but how soon
// a request left, and the turns of two processes, are judged where fetch()
// hands the request to curl (ThrottleRecordingSnoopy below).

// A child process runs this file with --child <config.json>: it shares the
// parent's profile and scripted curl, fetches the configured URLs and prints
// one JSON result per fetch. Snoopy is loaded at file scope in both roles,
// because its configuration lives in global variables.
$throttleChild = null;
if (isset($argv[1]) && $argv[1] === '--child') {
    $throttleChild = json_decode((string) file_get_contents($argv[2]), true);
    if (!is_array($throttleChild)) {
        fwrite(STDERR, "SnoopyThrottleTest: unreadable child configuration\n");
        exit(2);
    }
    if (!empty($throttleChild['readOnly'])) define('RUTORRENT_SETTINGS_READ_ONLY', true);
    $_ENV['RU_PROFILE_PATH'] = $throttleChild['profile'];
    $_ENV['RU_LOG_FILE'] = $throttleChild['log'];
} else {
    require_once(__DIR__ . '/TestCase.php');
    require_once(__DIR__ . '/ScriptedCurlFixture.php');
    $throttleRoot = sys_get_temp_dir() . '/snoopy-throttle-' . getmypid() . '-' . bin2hex(random_bytes(4));
    foreach (array('', '/profile', '/curl') as $throttleDir) mkdir($throttleRoot . $throttleDir, 0700);
    $_ENV['RU_PROFILE_PATH'] = $throttleRoot . '/profile';
    $_ENV['RU_LOG_FILE'] = $throttleRoot . '/application.log';
}
require_once(__DIR__ . '/../../php/Snoopy.class.inc');
$httpBlockPrivateNetworks = false;

// Slack for comparing a wire time with the moment the previous fetch returned:
// Snoopy ends a turn inside fetch() on microtime(); the fixture and this file
// measure on hrtime(), and a busy host may delay this process in between.
const THROTTLE_SLACK = 0.05;

function throttleClock()
{
    $time = hrtime();
    return $time[0] + $time[1] / 1000000000.0;
}

// Records each request fetch() hands to curl: the turn the throttle granted it
// (Snoopy's own reservation, on microtime) and the moment of the hand-off on
// both clocks. The throttle decides when a request may leave; how long the
// stand-in then takes to start is the host's. On a loaded CI runner two turns
// 0.25 s apart reached the fixture's record 0.025 s apart: the first stand-in
// took about 0.22 s longer to start than the second.
class ThrottleRecordingSnoopy extends Snoopy
{
    public $launches = array();

    function _httpsrequest($url, $content_type = "", $body = "", $http_method = "GET")
    {
        $reservation = Closure::bind(static function (Snoopy $client) { return $client->throttleReservation; }, null, 'Snoopy');
        $turn = $reservation($this);
        $this->launches[] = array('turn' => $turn === null ? null : $turn[1], 'wall' => microtime(true), 'clock' => throttleClock());
        // Forward exactly the arguments given: Snoopy counts them.
        return parent::_httpsrequest(...func_get_args());
    }
}

function throttleFetch($url, $deadline = null, $tag = null, $complex = false, $maxlength = null)
{
    $client = new ThrottleRecordingSnoopy();
    if ($maxlength !== null) $client->maxlength = $maxlength;
    // A cookie names the sending process in the fixture's record.
    if ($tag !== null) $client->cookies = array('process' => $tag);
    // A closure computes the deadline at the last moment before it is set.
    if ($deadline instanceof Closure) $deadline = $deadline();
    if ($deadline !== null) $client->setRequestDeadline($deadline);
    $wallCalled = microtime(true);
    $called = throttleClock();
    $ok = $complex ? $client->fetchComplex($url) : $client->fetch($url);
    $returned = throttleClock();
    return array('ok' => $ok, 'status' => $client->status, 'error' => $client->error,
        'called' => $called, 'returned' => $returned,
        'wallCalled' => $wallCalled, 'wallReturned' => microtime(true), 'launches' => $client->launches);
}

// Stands in for a filesystem on which flock() fails, for the throttle state
// files of the child that installs it. Every other file operation reaches the
// real files through PHP's own wrapper, restored around each path call.
class ThrottleLockRefusingFiles
{
    public $context;
    private $handle;
    private $refuseLocks = false;

    public static function install()
    {
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', __CLASS__);
    }

    private static function native(Closure $call)
    {
        stream_wrapper_restore('file');
        try {
            return $call();
        } finally {
            self::install();
        }
    }

    public function stream_open($path, $mode, $options, &$openedPath)
    {
        $this->handle = self::native(function () use ($path, $mode) { return @fopen($path, $mode); });
        $this->refuseLocks = preg_match('~/throttle-[^/]*\.json$~D', $path) === 1;
        return $this->handle !== false;
    }

    public function stream_lock($operation)
    {
        if ($this->refuseLocks) return false;
        // Operation 0 asks whether locking is supported at all.
        return ($operation & (LOCK_SH | LOCK_EX | LOCK_UN)) === 0 || flock($this->handle, $operation);
    }

    public function stream_read($count) { return fread($this->handle, $count); }
    public function stream_write($data) { return fwrite($this->handle, $data); }
    public function stream_eof() { return feof($this->handle); }
    public function stream_tell() { return ftell($this->handle); }
    public function stream_seek($offset, $whence) { return fseek($this->handle, $offset, $whence) === 0; }
    public function stream_flush() { return fflush($this->handle); }
    public function stream_truncate($size) { return ftruncate($this->handle, $size); }
    public function stream_stat() { return fstat($this->handle); }
    public function stream_close() { fclose($this->handle); }
    public function stream_set_option($option, $first, $second) { return false; }

    public function url_stat($path, $flags)
    {
        return self::native(function () use ($path, $flags) {
            return ($flags & STREAM_URL_STAT_LINK) !== 0 ? @lstat($path) : @stat($path);
        });
    }

    public function unlink($path) { return self::native(function () use ($path) { return @unlink($path); }); }
    public function rename($from, $to) { return self::native(function () use ($from, $to) { return @rename($from, $to); }); }

    public function mkdir($path, $mode, $options)
    {
        return self::native(function () use ($path, $mode, $options) {
            return @mkdir($path, $mode, ($options & STREAM_MKDIR_RECURSIVE) !== 0);
        });
    }

    public function rmdir($path, $options) { return self::native(function () use ($path) { return @rmdir($path); }); }

    public function stream_metadata($path, $option, $value)
    {
        return self::native(function () use ($path, $option, $value) {
            if ($option === STREAM_META_TOUCH) return @touch($path, $value[0], $value[1]);
            if ($option === STREAM_META_ACCESS) return @chmod($path, $value);
            if ($option === STREAM_META_OWNER || $option === STREAM_META_OWNER_NAME) return @chown($path, $value);
            if ($option === STREAM_META_GROUP || $option === STREAM_META_GROUP_NAME) return @chgrp($path, $value);
            return false;
        });
    }
}

function throttleChildWait(Closure $done)
{
    $giveUp = throttleClock() + 30;
    while (!$done()) {
        if (throttleClock() > $giveUp) exit(3);
        usleep(500);
    }
}

if ($throttleChild !== null) {
    // Constructing a fixture here would reset the shared plan and record.
    $pathToExternals['curl'] = $throttleChild['curl'];
    putenv('SCRIPTED_CURL_ROOT=' . $throttleChild['curlRoot']);
    Snoopy::throttleHosts($throttleChild['group'], $throttleChild['hosts'], $throttleChild['interval'],
        $throttleChild['pause'], $throttleChild['maxPause']);
    // Probe rTorrent now, so that the first fetch reserves its turn at once.
    rTorrentSettings::get();
    // A request outside the group loads every class a fetch needs.
    if (isset($throttleChild['warm'])) throttleFetch($throttleChild['warm']);
    if (!empty($throttleChild['refuseLocks'])) ThrottleLockRefusingFiles::install();
    // Announce readiness, then wait for the go: a file the parent or the hook creates.
    if (isset($throttleChild['ready'])) touch($throttleChild['ready']);
    if (isset($throttleChild['go']))
        throttleChildWait(function () use ($throttleChild) { return file_exists($throttleChild['go']); });
    if (isset($throttleChild['advance'])) {
        // Wait until another process has reserved the turn after the one the go file names.
        $published = json_decode((string) file_get_contents($throttleChild['go']), true);
        throttleChildWait(function () use ($throttleChild, $published) {
            $state = json_decode((string) @file_get_contents($throttleChild['advance']), true);
            return is_array($state) && isset($state['next']) && $state['next'] > $published['next'];
        });
    }
    $results = array();
    foreach ($throttleChild['urls'] as $url)
        $results[] = throttleFetch($url, null, isset($throttleChild['tag']) ? $throttleChild['tag'] : null);
    echo json_encode($results);
    exit(0);
}

$throttleCurl = new ScriptedCurlFixture($throttleRoot . '/curl');
$throttleCurl->install();
$throttleFixtureCurl = $pathToExternals['curl'];

// Wraps the scripted curl for cases the stand-in cannot express alone.
// THROTTLE_HOOK_RESERVE names a group state file in which another process's
// reservation is made first, the way Snoopy makes one: under the file's lock
// the turn starts after 'next' and 'ended' and moves 'next' on by
// THROTTLE_HOOK_INTERVAL; the competing turn is written to THROTTLE_HOOK_RECORD.
// Once the answer is recorded, THROTTLE_HOOK_SIGNAL receives a copy of the
// THROTTLE_HOOK_STATE file (the request is on the wire), THROTTLE_HOOK_HOLD
// keeps the transfer open that many microseconds, after which
// THROTTLE_HOOK_HELD receives the time and the state again, and
// THROTTLE_HOOK_EXIT replaces curl's success, as curl does when it stops a
// transfer after the headers.
file_put_contents($throttleRoot . '/hook.php', <<<'PHP'
<?php
$reserve = getenv('THROTTLE_HOOK_RESERVE');
if ($reserve !== false && $reserve !== '') {
    $fp = fopen($reserve, 'c+');
    flock($fp, LOCK_EX);
    $state = json_decode(stream_get_contents($fp), true);
    $slot = max(microtime(true), $state['next'], isset($state['ended']) ? $state['ended'] : 0.0);
    $state['next'] = $slot + (float) getenv('THROTTLE_HOOK_INTERVAL');
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    file_put_contents(getenv('THROTTLE_HOOK_RECORD'), json_encode(array('slot' => $slot, 'next' => $state['next'])));
}
$curl = proc_open(array_merge(array(getenv('THROTTLE_HOOK_CURL')), array_slice($argv, 1)),
    array(STDIN, STDOUT, STDERR), $pipes);
$code = proc_close($curl);
$signal = getenv('THROTTLE_HOOK_SIGNAL');
if ($signal !== false && $signal !== '') {
    file_put_contents($signal . '.tmp', file_get_contents(getenv('THROTTLE_HOOK_STATE')));
    rename($signal . '.tmp', $signal);
}
$hold = (int) getenv('THROTTLE_HOOK_HOLD');
if ($hold > 0) usleep($hold);
$held = getenv('THROTTLE_HOOK_HELD');
if ($held !== false && $held !== '')
    file_put_contents($held, json_encode(array('at' => microtime(true),
        'state' => json_decode(file_get_contents(getenv('THROTTLE_HOOK_STATE')), true))));
$exit = getenv('THROTTLE_HOOK_EXIT');
exit($code === 0 && $exit !== false && $exit !== '' ? (int) $exit : $code);
PHP
);
file_put_contents($throttleRoot . '/hook', "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' -c '
    . escapeshellarg(__DIR__ . '/../php-test.ini') . ' ' . escapeshellarg($throttleRoot . '/hook.php') . ' "$@"' . "\n");
chmod($throttleRoot . '/hook', 0700);

function throttleUseHook(array $environment)
{
    global $pathToExternals, $throttleRoot, $throttleFixtureCurl;
    $pathToExternals['curl'] = $throttleRoot . '/hook';
    putenv('THROTTLE_HOOK_CURL=' . $throttleFixtureCurl);
    foreach ($environment as $name => $value) putenv($name . '=' . $value);
}

function throttleResetTransport()
{
    global $pathToExternals, $throttleFixtureCurl;
    $pathToExternals['curl'] = $throttleFixtureCurl;
    foreach (array('CURL', 'RESERVE', 'INTERVAL', 'RECORD', 'SIGNAL', 'STATE', 'HOLD', 'HELD', 'EXIT') as $name)
        putenv('THROTTLE_HOOK_' . $name);
}

function throttlePlan(array $replies)
{
    global $throttleCurl;
    $throttleCurl->plan($replies);
}

function throttleWire()
{
    global $throttleCurl;
    return $throttleCurl->requests();
}

function throttleAssertWire(array $urls, $message)
{
    global $throttleCurl;
    testAssertSame($urls, array_map(function ($request) { return $request['url']; }, throttleWire()), $message);
    testAssertSame(array(), $throttleCurl->remaining(), 'Every scripted answer was asked for');
}

// Sequential fetches, one wire request each: a turn starts an interval after
// the previous request started, and a whole interval after it ended.
function throttleAssertTurns(array $fetches, array $wire, $interval)
{
    testAssertSame(count($fetches), count($wire), 'Each fetch made one wire request');
    for ($i = 1; $i < count($wire); $i++) {
        $started = $wire[$i]['time'] - $wire[$i - 1]['time'];
        $ended = $wire[$i]['time'] - $fetches[$i - 1]['returned'];
        testAssertTrue($started >= $interval, sprintf('Wire request %d starts an interval after request %d started (%.3f s)', $i + 1, $i, $started));
        testAssertTrue($ended >= $interval - THROTTLE_SLACK, sprintf('Wire request %d starts an interval after request %d ended (%.3f s)', $i + 1, $i, $ended));
    }
}

// A request that waits for no turn is handed to curl promptly; the stand-in's
// startup after that is not a wait (see ThrottleRecordingSnoopy).
function throttleAssertPrompt(array $fetch, array $request, $limit, $message)
{
    testAssertSame(1, count($fetch['launches']), $message . ': one request was handed to curl');
    $launch = $fetch['launches'][0];
    testAssertTrue($request['time'] >= $launch['clock'], $message . ': the wire request is the one handed to curl');
    $wait = $launch['clock'] - $fetch['called'];
    testAssertTrue($wait < $limit, sprintf('%s (handed to curl %.3f s after the call)', $message, $wait));
}

function throttleAssertBetween($value, $low, $high, $message)
{
    testAssertTrue($value >= $low - 0.000001 && $value <= $high + 0.000001,
        sprintf('%s: %.6f is not within [%.6f, %.6f]', $message, $value, $low, $high));
}

function throttleAssertRefused(array $fetch, $reason, $message)
{
    testAssertSame(false, $fetch['ok'], $message . ': the fetch fails');
    testAssertSame($reason, $fetch['error'], $message . ': the refusal is named');
    testAssertSame(Snoopy::RESPONSE_BODY_FAILED, $fetch['status'], $message . ': the status says no body was read');
}

function throttleStatePath($group)
{
    return FileUtil::getSettingsPathEx('') . '/throttle-' . $group . '.json';
}

function throttleState($group)
{
    $state = json_decode((string) @file_get_contents(throttleStatePath($group)), true);
    testAssertTrue(is_array($state) && isset($state['next'], $state['ended'], $state['until'], $state['strikes']),
        "The $group state file holds the next turn, the end bound, the pause and the strike count");
    return $state;
}

// When the group's next turn can start: an interval after the previous turn
// started, and an interval after the last request that ended.
function throttleTurn(array $state)
{
    return max($state['next'], $state['ended']);
}

function throttleStoreState($group, $stored)
{
    $directory = FileUtil::getSettingsPathEx('');
    if (!is_dir($directory)) mkdir($directory, 0700, true);
    file_put_contents(throttleStatePath($group), is_string($stored) ? $stored : json_encode($stored));
}

function throttleSleepUntil($wallTime)
{
    while (($left = $wallTime - microtime(true)) > 0) usleep((int) ceil($left * 1000000));
}

function throttleIso($until)
{
    return gmdate('Y-m-d\TH:i:s\Z', (int) ceil($until));
}

function throttleLogLines($path = null)
{
    global $throttleRoot;
    $text = @file_get_contents($path === null ? $throttleRoot . '/application.log' : $path);
    return $text === false ? array() : explode("\n", rtrim($text, "\n"));
}

function throttleLogCount($text, $path = null)
{
    return count(array_filter(throttleLogLines($path), function ($line) use ($text) {
        return substr($line, -strlen($text) - 1) === ' ' . $text;
    }));
}

// The until= values of one event for one group, in log order.
function throttleLogUntils($event, $group, $path = null)
{
    $untils = array();
    foreach (throttleLogLines($path) as $line)
        if (preg_match('/^\[[^\]]+\] Snoopy: ' . preg_quote($event, '/') . ' group=' . preg_quote($group, '/')
            . ' until=(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ)$/D', $line, $match))
            $untils[] = $match[1];
    return $untils;
}

function throttleChildLog($name)
{
    global $throttleRoot;
    return $throttleRoot . '/' . $name . '.log';
}

function throttleStartChild($name, array $config)
{
    global $throttleRoot, $throttleFixtureCurl;
    $config += array('profile' => $throttleRoot . '/profile', 'log' => throttleChildLog($name),
        'curl' => $throttleFixtureCurl, 'curlRoot' => getenv('SCRIPTED_CURL_ROOT'));
    $configPath = $throttleRoot . '/' . $name . '.json';
    file_put_contents($configPath, json_encode($config));
    // exec: the shell must not stay between this file and the child.
    $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' -c ' . escapeshellarg(__DIR__ . '/../php-test.ini') . ' '
        . escapeshellarg(__FILE__) . ' --child ' . escapeshellarg($configPath);
    $process = proc_open($command, array(0 => array('file', '/dev/null', 'r'),
        1 => array('file', $throttleRoot . '/' . $name . '.out', 'w'),
        2 => array('file', $throttleRoot . '/' . $name . '.err', 'w')), $pipes);
    testAssertTrue(is_resource($process), "Child $name starts");
    return array('name' => $name, 'process' => $process);
}

function throttleFinishChild(array $child)
{
    global $throttleRoot;
    $giveUp = throttleClock() + 60;
    while (($status = proc_get_status($child['process'])) && $status['running']) {
        if (throttleClock() > $giveUp) {
            proc_terminate($child['process'], 9);
            proc_close($child['process']);
            throw new RuntimeException("Child {$child['name']} did not finish");
        }
        usleep(5000);
    }
    proc_close($child['process']);
    $out = $throttleRoot . '/' . $child['name'];
    testAssertSame('', (string) file_get_contents($out . '.err'), "Child {$child['name']} wrote no diagnostics");
    testAssertSame(0, $status['exitcode'], "Child {$child['name']} exits cleanly");
    $results = json_decode((string) file_get_contents($out . '.out'), true);
    testAssertTrue(is_array($results), "Child {$child['name']} reports its fetches");
    return $results;
}

function throttleRunChild($name, array $config)
{
    return throttleFinishChild(throttleStartChild($name, $config));
}

function throttleAwaitFiles(array $paths, $message)
{
    $giveUp = throttleClock() + 30;
    while (count(array_filter($paths, 'file_exists')) < count($paths)) {
        testAssertTrue(throttleClock() < $giveUp, $message);
        usleep(1000);
    }
}

// Waits for every child even when one of them fails its checks.
function throttleFinishChildren(array $children)
{
    $results = array();
    $failure = null;
    foreach ($children as $key => $child) {
        try {
            $results[$key] = throttleFinishChild($child);
        } catch (Throwable $error) {
            if ($failure === null) $failure = $error;
        }
    }
    if ($failure !== null) throw $failure;
    return $results;
}

function throttleRemoveTree($path)
{
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path) as $entry) throttleRemoveTree($entry->getPathname());
        rmdir($path);
    } else unlink($path);
}

$tests = array(
    'separate clients in one process take turns, over https and http alike' => function () {
        $interval = 0.2;
        Snoopy::throttleHosts('spacing', array('spacing.test'), $interval, 1, 1);
        throttlePlan(array(ScriptedCurlFixture::reply('GET', 'https://spacing.test/one', 200, 'one'),
            ScriptedCurlFixture::reply('GET', 'https://spacing.test/two', 200, 'two'),
            ScriptedCurlFixture::reply('GET', 'http://spacing.test/three', 200, 'three')));
        $fetches = array(throttleFetch('https://spacing.test/one'),
            throttleFetch('https://spacing.test/two', null, null, true),
            // A deadline sends plain http through curl as well.
            throttleFetch('http://spacing.test/three', 5.0));
        foreach ($fetches as $i => $fetch) testAssertSame(true, $fetch['ok'], 'Request ' . ($i + 1) . ' is answered');
        throttleAssertWire(array('https://spacing.test/one', 'https://spacing.test/two', 'http://spacing.test/three'),
            'Every client reaches the wire once');
        throttleAssertTurns($fetches, throttleWire(), $interval);
        $state = throttleState('spacing');
        throttleAssertBetween($state['ended'], $fetches[2]['wallCalled'] + $interval, $fetches[2]['wallReturned'] + $interval,
            'The shared state file holds the end bound, an interval after the last request ended');
    },
    'a transfer longer than the interval delays the next turn until an interval after it ended' => function () {
        $interval = 0.2;
        $hold = 0.4;
        Snoopy::throttleHosts('slow', array('slow.test'), $interval, 1, 1);
        throttlePlan(array(ScriptedCurlFixture::reply('GET', 'https://slow.test/long', 200, 'long'),
            ScriptedCurlFixture::reply('GET', 'https://slow.test/next', 200, 'next')));
        throttleUseHook(array('THROTTLE_HOOK_HOLD' => (int) ($hold * 1000000)));
        try {
            $long = throttleFetch('https://slow.test/long');
        } finally {
            throttleResetTransport();
        }
        $next = throttleFetch('https://slow.test/next');
        testAssertSame(true, $long['ok'] && $next['ok'], 'Both requests are answered');
        $wire = throttleWire();
        testAssertTrue($long['returned'] - $wire[0]['time'] >= $hold, 'The first transfer outlasted the interval (precondition)');
        throttleAssertTurns(array($long, $next), $wire, $interval);
    },
    'a redirect hop to a group host waits its own turn' => function () {
        $interval = 0.2;
        Snoopy::throttleHosts('hop', array('hop.test'), $interval, 1, 1);
        throttlePlan(array(ScriptedCurlFixture::reply('GET', 'https://hop.test/start', 302, '', array('Location: https://hop.test/landing')),
            ScriptedCurlFixture::reply('GET', 'https://hop.test/landing', 200, 'landed')));
        $fetch = throttleFetch('https://hop.test/start');
        testAssertSame(true, $fetch['ok'], 'The redirect is followed');
        testAssertSame('200', $fetch['status'], 'The landing page answers');
        throttleAssertWire(array('https://hop.test/start', 'https://hop.test/landing'), 'Both hops reach the wire');
        $wire = throttleWire();
        testAssertTrue($wire[1]['time'] - $wire[0]['time'] >= $interval,
            sprintf('The hop starts an interval after the first request (%.3f s)', $wire[1]['time'] - $wire[0]['time']));
    },
    'a subdomain shares its group while other hosts and schemes never wait for it' => function () {
        $interval = 0.4;
        Snoopy::throttleHosts('family', array('family.test'), $interval, 1, 1);
        $outside = array('https://other.test/b', 'https://family.test.example/c', 'https://notfamily.test/d');
        throttlePlan(array(ScriptedCurlFixture::reply('GET', 'https://family.test/a', 200, 'a'),
            ScriptedCurlFixture::reply('GET', $outside[0], 200, 'b'),
            ScriptedCurlFixture::reply('GET', $outside[1], 200, 'c'),
            ScriptedCurlFixture::reply('GET', $outside[2], 200, 'd'),
            ScriptedCurlFixture::reply('GET', 'https://cdn.family.test/e', 200, 'e')));
        $first = throttleFetch('https://family.test/a');
        $turn = throttleState('family');
        $others = array_map('throttleFetch', $outside);
        $dummy = throttleFetch('dummy://family.test/f');
        testAssertSame(true, $dummy['ok'], 'A dummy:// fetch succeeds without curl');
        testAssertSame($turn, throttleState('family'), 'Requests outside the group, and a non-HTTP scheme, leave its turn alone');
        $subdomain = throttleFetch('https://cdn.family.test/e');
        $wire = throttleWire();
        foreach ($others as $i => $other) {
            testAssertSame(true, $other['ok'], $outside[$i] . ' is answered');
            throttleAssertPrompt($other, $wire[$i + 1], $interval / 2, $outside[$i] . ' does not wait for the group');
        }
        testAssertTrue($wire[4]['time'] - $first['returned'] >= $interval - THROTTLE_SLACK,
            sprintf('A subdomain waits for the group\'s turn (%.3f s)', $wire[4]['time'] - $first['returned']));
        testAssertSame(true, $subdomain['ok'], 'The subdomain is answered');
        throttleAssertWire(array_merge(array('https://family.test/a'), $outside, array('https://cdn.family.test/e')),
            'Each HTTP request reaches the wire once');
    },
    'two processes sharing one profile take turns on the wire' => function () {
        global $throttleRoot;
        $interval = 0.25;
        $url = 'https://shared.test/x';
        throttlePlan(array_fill(0, 6, ScriptedCurlFixture::reply('GET', $url, 200, 'x')));
        $go = $throttleRoot . '/shared.go';
        $children = array();
        foreach (array('a', 'b') as $tag)
            $children[$tag] = throttleStartChild('shared-' . $tag, array('group' => 'shared', 'hosts' => array('shared.test'),
                'interval' => $interval, 'pause' => 1, 'maxPause' => 1, 'urls' => array($url, $url, $url), 'tag' => $tag,
                'ready' => $throttleRoot . '/shared-' . $tag . '.ready', 'go' => $go));
        // A third writer holds the state file in the middle of its write, after
        // ftruncate() and before fwrite(), while both processes ask for their
        // first turn: they must wait for its lock and queue after its turn. The
        // hold is how long a reader that skipped the lock has to show itself;
        // each process reserves within milliseconds of the go. The children
        // start first: they must not inherit the lock.
        $statePath = throttleStatePath('shared');
        if (!is_dir(dirname($statePath))) mkdir(dirname($statePath), 0700, true);
        $writer = fopen($statePath, 'c+');
        $writerTurn = null;
        try {
            testAssertTrue($writer !== false && flock($writer, LOCK_EX) && ftruncate($writer, 0),
                'The third writer holds the state file mid-write (precondition)');
            throttleAwaitFiles(array($throttleRoot . '/shared-a.ready', $throttleRoot . '/shared-b.ready'), 'Both processes start');
            touch($go);
            usleep(300000);
            $writerTurn = microtime(true);
            fwrite($writer, json_encode(array('next' => $writerTurn + $interval, 'ended' => 0.0, 'until' => 0.0, 'strikes' => 0)));
            fflush($writer);
        } finally {
            if ($writer !== false) fclose($writer);
            if (!file_exists($go)) touch($go);
            $results = throttleFinishChildren($children);
        }
        foreach ($results as $tag => $fetches) {
            testAssertSame(3, count($fetches), "Process $tag made three fetches");
            foreach ($fetches as $fetch) testAssertSame(true, $fetch['ok'], "Process $tag's requests are answered");
        }
        testAssertTrue(abs($results['a'][0]['called'] - $results['b'][0]['called']) < $interval / 2,
            'Both processes asked for their first turn at the same time (precondition)');
        $wire = throttleWire();
        $senders = array_map(function ($request) { return $request['cookie']; }, $wire);
        sort($senders);
        testAssertSame(array_merge(array_fill(0, 3, 'Cookie: process=a'), array_fill(0, 3, 'Cookie: process=b')), $senders,
            'Each process sent its three requests');
        // Each wire request is held to its own turn where it is handed to curl,
        // and all turns, the third writer's included, are an interval apart.
        $turns = array($writerTurn);
        foreach ($results as $tag => $fetches) {
            $sent = array_values(array_filter($wire, function ($request) use ($tag) { return $request['cookie'] === "Cookie: process=$tag"; }));
            foreach ($fetches as $k => $fetch) {
                $request = "Process $tag's request " . ($k + 1);
                testAssertSame(1, count($fetch['launches']), "$request was handed to curl once");
                $launch = $fetch['launches'][0];
                testAssertTrue(is_float($launch['turn']), "$request was granted a turn");
                testAssertTrue($launch['wall'] >= $launch['turn'],
                    sprintf('%s waited for its turn (handed to curl %.3f s early)', $request, $launch['turn'] - $launch['wall']));
                testAssertTrue($sent[$k]['time'] >= $launch['clock'], "$request reached the wire after it was handed to curl");
                $turns[] = $launch['turn'];
            }
        }
        sort($turns);
        testAssertSame($writerTurn, $turns[0], 'No process took a turn while the third writer held the state file');
        for ($i = 1; $i < count($turns); $i++)
            testAssertTrue($turns[$i] - $turns[$i - 1] >= $interval - 0.000001,
                sprintf('Turns %d and %d, from the third writer or either process, are an interval apart (%.3f s)', $i, $i + 1, $turns[$i] - $turns[$i - 1]));
    },
    'an HTTP 429 in one process pauses the group for every process, and the next one doubles it' => function () {
        $pause = 1.0;
        $config = array('group' => 'xpause', 'hosts' => array('xpause.test'), 'interval' => 0.05, 'pause' => $pause, 'maxPause' => 30);
        throttlePlan(array(ScriptedCurlFixture::reply('GET', 'https://xpause.test/a', 429, 'slow down')));
        $first = throttleRunChild('xpause-a', $config + array('urls' => array('https://xpause.test/a')));
        testAssertSame(true, $first[0]['ok'], 'Process A receives its answer');
        testAssertSame('429', $first[0]['status'], 'The answer is HTTP 429');
        $state = throttleState('xpause');
        testAssertSame(1, $state['strikes'], 'The first 429 is the first strike');
        throttleAssertBetween($state['until'], $first[0]['wallCalled'] + $pause, $first[0]['wallReturned'] + $pause,
            'The pause lasts the configured time from the 429');
        $refused = throttleRunChild('xpause-b', $config + array('urls' => array('https://xpause.test/b', 'https://www.xpause.test/c')));
        throttleAssertRefused($refused[0], Snoopy::REQUEST_THROTTLED, 'Process B during the pause');
        throttleAssertRefused($refused[1], Snoopy::REQUEST_THROTTLED, 'Process B on a subdomain during the pause');
        testAssertTrue($refused[1]['wallReturned'] < $state['until'], 'Process B asked during the pause (precondition)');
        throttleAssertWire(array('https://xpause.test/a'), 'Only the request that drew the 429 reached the wire');
        $iso = throttleIso($state['until']);
        testAssertSame(array($iso), throttleLogUntils('throttle-paused', 'xpause', throttleChildLog('xpause-a')),
            'Process A logs the pause it started, with its end in UTC');
        testAssertSame(array($iso), throttleLogUntils(Snoopy::REQUEST_THROTTLED, 'xpause', throttleChildLog('xpause-b')),
            'Process B logs its refusals once');
        // The strike count is shared as well: another process's 429 right after the pause doubles it.
        throttleSleepUntil($state['until'] + 0.05);
        throttlePlan(array(ScriptedCurlFixture::reply('GET', 'https://xpause.test/d', 429, 'slow down')));
        $again = throttleRunChild('xpause-c', $config + array('urls' => array('https://xpause.test/d')));
        testAssertSame('429', $again[0]['status'], 'Process C is answered after the pause, with another 429');
        $state = throttleState('xpause');
        testAssertSame(2, $state['strikes'], 'The second 429 is the second strike');
        throttleAssertBetween($state['until'], $again[0]['wallCalled'] + 2 * $pause, $again[0]['wallReturned'] + 2 * $pause,
            'The second pause is twice as long');
        throttleAssertWire(array('https://xpause.test/d'), 'Process C sent its request after the pause');
    },
    'requests already waiting for their turn are refused when a 429 pauses the group meanwhile' => function () {
        global $throttleRoot, $throttleCurl;
        $interval = 0.5;
        $hold = 0.25;
        $pause = 3.0;
        $config = array('group' => 'waiter', 'hosts' => array('waiter.test'), 'interval' => $interval, 'pause' => $pause, 'maxPause' => 10);
        Snoopy::throttleHosts('waiter', $config['hosts'], $interval, $pause, 10);
        $statePath = throttleStatePath('waiter');
        $signal = $throttleRoot . '/waiter.signal';
        $replies = array(ScriptedCurlFixture::reply('GET', 'https://waiter.test/b', 200, ''),
            ScriptedCurlFixture::reply('GET', 'https://waiter.test/c', 200, ''));
        throttlePlan(array_merge(array(ScriptedCurlFixture::reply('GET', 'https://waiter.test/a', 429, '')), $replies));
        // B reserves the turn after A's while A's request is on the wire, C the
        // turn after B's; A's 429 arrives before either turn comes.
        $children = array(
            'B' => throttleStartChild('waiter-b', $config + array('urls' => array('https://waiter.test/b'),
                'ready' => $throttleRoot . '/waiter-b.ready', 'go' => $signal)),
            'C' => throttleStartChild('waiter-c', $config + array('urls' => array('https://waiter.test/c'),
                'ready' => $throttleRoot . '/waiter-c.ready', 'go' => $signal, 'advance' => $statePath)),
        );
        try {
            throttleAwaitFiles(array($throttleRoot . '/waiter-b.ready', $throttleRoot . '/waiter-c.ready'), 'Both waiting processes start');
            throttleUseHook(array('THROTTLE_HOOK_SIGNAL' => $signal, 'THROTTLE_HOOK_STATE' => $statePath,
                'THROTTLE_HOOK_HOLD' => (int) ($hold * 1000000)));
            try {
                $first = throttleFetch('https://waiter.test/a');
            } finally {
                throttleResetTransport();
            }
        } finally {
            if (!file_exists($signal)) touch($signal);
            try {
                $results = array('B' => throttleFinishChild($children['B']));
                // B has been refused; C still waits for the turn after B's.
                $between = throttleState('waiter');
                $betweenAt = throttleClock();
            } finally {
                $results['C'] = throttleFinishChild($children['C']);
            }
        }
        testAssertSame('429', $first['status'], 'Process A draws a 429');
        foreach ($results as $name => $fetches) {
            testAssertTrue($fetches[0]['called'] < $first['returned'] - 0.1, "Process $name reserved its turn before the 429 arrived (precondition)");
            throttleAssertRefused($fetches[0], Snoopy::REQUEST_THROTTLED, "Process $name, still waiting when the pause began");
            testAssertTrue($fetches[0]['returned'] - $fetches[0]['called'] >= $interval - $hold,
                sprintf('Process %s was refused when its turn came (after %.3f s)', $name, $fetches[0]['returned'] - $fetches[0]['called']));
        }
        testAssertSame(array('https://waiter.test/a'), array_map(function ($request) { return $request['url']; }, throttleWire()),
            'No waiting request reached the wire');
        testAssertSame($replies, $throttleCurl->remaining(), 'The answers planned for the waiting requests were never asked for');
        $state = throttleState('waiter');
        $published = json_decode((string) @file_get_contents($signal), true);
        testAssertTrue(is_array($published) && isset($published['next']), 'The hook published the state while A was on the wire');
        testAssertTrue($results['C'][0]['returned'] > $betweenAt, 'C was still waiting when B had finished (precondition)');
        // The sums repeat Snoopy's own: each turn ends an interval after the one before.
        testAssertSame($published['next'] + $interval + $interval, $between['next'],
            'B could not hand its turn back while C held the turn after it');
        testAssertSame($published['next'] + $interval, $state['next'], 'C, the last to reserve, handed its turn back');
        testAssertSame(1, $state['strikes'], 'The refused requests reset no strikes');
        throttleAssertBetween($state['until'], $first['wallCalled'] + $pause, $first['wallReturned'] + $pause, 'The pause is the one A started');
        foreach (array('waiter-b', 'waiter-c') as $name)
            testAssertSame(array(throttleIso($state['until'])), throttleLogUntils(Snoopy::REQUEST_THROTTLED, 'waiter', throttleChildLog($name)),
                "$name logs its refusal once");
    },
    'a request during a pause is refused at once, without waiting for a turn' => function () {
        $interval = 0.5;
        Snoopy::throttleHosts('instant', array('instant.test'), $interval, 1, 1);
        throttlePlan(array(ScriptedCurlFixture::reply('GET', 'https://instant.test/a', 429, '')));
        throttleFetch('https://instant.test/a');
        $turn = throttleState('instant');
        $refused = throttleFetch('https://instant.test/b');
        throttleAssertRefused($refused, Snoopy::REQUEST_THROTTLED, 'A request during the pause');
        testAssertTrue($refused['returned'] - $refused['called'] < $interval / 2,
            sprintf('It is refused at once, not when the next turn comes (%.3f s)', $refused['returned'] - $refused['called']));
        testAssertSame($turn, throttleState('instant'), 'It takes no turn');
        throttleAssertWire(array('https://instant.test/a'), 'The refused request never reached the wire');
    },
    'a 429 that arrives during the pause another 429 started adds no strike and only lengthens it' => function () {
        global $throttleRoot;
        $pause = 1.0;
        $hold = 0.3;
        // B's 429 starts the pause while A's request is on the wire; A's own
        // 429 lands during that pause, with the longer or the shorter candidate.
        $cases = array('longer' => array('a' => array('Retry-After: 5'), 'b' => array()),
            'shorter' => array('a' => array(), 'b' => array('Retry-After: 5')));
        foreach ($cases as $name => $headers) {
            $group = "overlap-$name";
            Snoopy::throttleHosts($group, array("$group.test"), 0.02, $pause, 30);
            $signal = "$throttleRoot/$group.signal";
            $held = "$throttleRoot/$group.held";
            throttlePlan(array(ScriptedCurlFixture::reply('GET', "https://$group.test/a", 429, '', $headers['a']),
                ScriptedCurlFixture::reply('GET', "https://$group.test/b", 429, '', $headers['b'])));
            $child = throttleStartChild($group, array('group' => $group, 'hosts' => array("$group.test"), 'interval' => 0.02,
                'pause' => $pause, 'maxPause' => 30, 'urls' => array("https://$group.test/b"),
                'ready' => "$throttleRoot/$group.ready", 'go' => $signal));
            try {
                throttleAwaitFiles(array("$throttleRoot/$group.ready"), "$name: process B starts");
                throttleUseHook(array('THROTTLE_HOOK_SIGNAL' => $signal, 'THROTTLE_HOOK_STATE' => throttleStatePath($group),
                    'THROTTLE_HOOK_HOLD' => (int) ($hold * 1000000), 'THROTTLE_HOOK_HELD' => $held));
                try {
                    $first = throttleFetch("https://$group.test/a");
                } finally {
                    throttleResetTransport();
                }
            } finally {
                if (!file_exists($signal)) touch($signal);
                $second = throttleFinishChild($child);
            }
            testAssertSame('429', $second[0]['status'], "$name: process B draws a 429");
            testAssertSame('429', $first['status'], "$name: process A draws a 429 as well");
            // The state as A's answer arrived: B's 429 had made the first strike.
            $during = json_decode((string) @file_get_contents($held), true);
            testAssertTrue(is_array($during) && is_array($during['state']), "$name: the hook recorded the state as A's answer arrived");
            testAssertTrue($during['state']['until'] > $during['at'], "$name: B's pause was on when A's answer arrived (precondition)");
            testAssertSame(1, $during['state']['strikes'], "$name: B's 429 was the first strike (precondition)");
            $state = throttleState($group);
            testAssertSame(1, $state['strikes'], "$name: A's 429 during the pause adds no strike");
            if ($name === 'longer')
                throttleAssertBetween($state['until'], $during['at'] + 5, $first['wallReturned'] + 5, "$name: A's longer candidate lengthens the pause");
            else
                testAssertSame($during['state']['until'], $state['until'], "$name: A's shorter candidate never shortens the pause");
            testAssertSame(array(throttleIso($state['until'])), throttleLogUntils('throttle-paused', $group),
                "$name: A logs the pause as it stands after its 429");
            throttleAssertWire(array("https://$group.test/a", "https://$group.test/b"), "$name: each process sent its request once");
        }
    },
    'a 429 right after a pause doubles it in one process too, and any other answer resets the count' => function () {
        $pause = 0.2;
        $host = 'https://doubling.test';
        Snoopy::throttleHosts('doubling', array('doubling.test'), 0.02, $pause, 10);
        throttlePlan(array(ScriptedCurlFixture::reply('GET', "$host/1", 429, ''), ScriptedCurlFixture::reply('GET', "$host/3", 429, ''),
            ScriptedCurlFixture::reply('GET', "$host/5", 200, ''), ScriptedCurlFixture::reply('GET', "$host/6", 429, '')));
        $first = throttleFetch("$host/1");
        $paused = array(throttleState('doubling'));
        throttleAssertBetween($paused[0]['until'], $first['wallCalled'] + $pause, $first['wallReturned'] + $pause, 'The first 429 pauses the group');
        throttleAssertRefused(throttleFetch("$host/2"), Snoopy::REQUEST_THROTTLED, 'A request during the pause');
        throttleSleepUntil($paused[0]['until'] + 0.02);
        $second = throttleFetch("$host/3");
        testAssertSame('429', $second['status'], 'The request after the pause draws another 429');
        $paused[] = throttleState('doubling');
        testAssertSame(2, $paused[1]['strikes'], 'That is the second strike');
        throttleAssertBetween($paused[1]['until'], $second['wallCalled'] + 2 * $pause, $second['wallReturned'] + 2 * $pause,
            'The second pause is twice as long');
        throttleSleepUntil($second['wallReturned'] + 1.25 * $pause);
        throttleAssertRefused(throttleFetch("$host/4"), Snoopy::REQUEST_THROTTLED, 'A request after the first pause length');
        throttleSleepUntil($paused[1]['until'] + 0.02);
        testAssertSame('200', throttleFetch("$host/5")['status'], 'The request after the doubled pause is answered');
        testAssertSame(0, throttleState('doubling')['strikes'], 'Any other HTTP answer resets the strikes');
        $third = throttleFetch("$host/6");
        $paused[] = throttleState('doubling');
        testAssertSame(1, $paused[2]['strikes'], 'A 429 after the reset is a first strike again');
        throttleAssertBetween($paused[2]['until'], $third['wallCalled'] + $pause, $third['wallReturned'] + $pause,
            'and pauses for the configured time again');
        throttleAssertWire(array("$host/1", "$host/3", "$host/5", "$host/6"), 'Refused requests never reached the wire');
        testAssertSame(array_map(function ($state) { return throttleIso($state['until']); }, $paused),
            throttleLogUntils('throttle-paused', 'doubling'), 'Each pause is logged when it starts');
        testAssertSame(array(throttleIso($paused[0]['until'])), throttleLogUntils(Snoopy::REQUEST_THROTTLED, 'doubling'),
            'A process logs its refusals for a group once');
    },
    'consecutive pauses stop growing at maxPause, which never undercuts the pause' => function () {
        $host = 'https://capped.test';
        Snoopy::throttleHosts('capped', array('capped.test'), 0.02, 0.1, 0.15);
        throttlePlan(array(ScriptedCurlFixture::reply('GET', "$host/1", 429, ''), ScriptedCurlFixture::reply('GET', "$host/2", 429, ''),
            ScriptedCurlFixture::reply('GET', "$host/3", 429, ''), ScriptedCurlFixture::reply('GET', 'https://floor.test/1', 429, '')));
        foreach (array(1 => 0.1, 2 => 0.15, 3 => 0.15) as $strike => $length) {
            if ($strike > 1) throttleSleepUntil(throttleState('capped')['until'] + 0.02);
            $fetch = throttleFetch("$host/$strike");
            testAssertSame('429', $fetch['status'], "Request $strike draws a 429");
            $state = throttleState('capped');
            testAssertSame($strike, $state['strikes'], "Request $strike is strike $strike");
            throttleAssertBetween($state['until'], $fetch['wallCalled'] + $length, $fetch['wallReturned'] + $length,
                "Strike $strike pauses for $length s");
        }
        Snoopy::throttleHosts('floor', array('floor.test'), 0.02, 0.1, 0.01);
        $fetch = throttleFetch('https://floor.test/1');
        throttleAssertBetween(throttleState('floor')['until'], $fetch['wallCalled'] + 0.1, $fetch['wallReturned'] + 0.1,
            'A maxPause below the pause is raised to the pause');
        throttleAssertWire(array("$host/1", "$host/2", "$host/3", 'https://floor.test/1'), 'Every request after a pause was sent');
    },
    'a 429 with Retry-After pauses for the longer of it and the backoff, within maxPause' => function () {
        $pause = 0.1;
        $at = time() + 3;
        // Each case has its own group, so each 429 is a first strike: backoff 0.1 s.
        $cases = array(
            'retry-delta' => array('Retry-After: 2', 5),
            // An exact IMF-fixdate. The field name is lower-case, as curl writes
            // HTTP/2 headers: field names ignore case, the date itself does not.
            'retry-date' => array('retry-after: ' . gmdate('D, d M Y H:i:s', $at) . ' GMT', 5),
            'retry-short' => array('Retry-After: 0', 5),
            'retry-cap' => array('Retry-After: 120', 1.5),
        );
        $plan = array();
        foreach ($cases as $group => $case)
            $plan[] = ScriptedCurlFixture::reply('GET', "https://$group.test/x", 429, '', array($case[0]));
        throttlePlan($plan);
        $fetches = $states = array();
        foreach ($cases as $group => $case) {
            Snoopy::throttleHosts($group, array("$group.test"), 0.02, $pause, $case[1]);
            $fetches[$group] = throttleFetch("https://$group.test/x");
            testAssertSame('429', $fetches[$group]['status'], "$group: the host answers 429");
            $states[$group] = throttleState($group);
            testAssertSame(1, $states[$group]['strikes'], "$group: Retry-After does not change the strike count");
        }
        $range = function ($group, $length) use ($fetches) {
            return array($fetches[$group]['wallCalled'] + $length, $fetches[$group]['wallReturned'] + $length);
        };
        list($low, $high) = $range('retry-delta', 2);
        throttleAssertBetween($states['retry-delta']['until'], $low, $high, 'Retry-After seconds longer than the backoff extend the pause');
        // Snoopy counts the date from time(), a whole second at a time.
        throttleAssertBetween($states['retry-date']['until'], $at,
            $at + 1 + $fetches['retry-date']['wallReturned'] - $fetches['retry-date']['wallCalled'],
            'A Retry-After HTTP-date extends the pause until that time');
        testAssertSame(array(throttleIso($states['retry-date']['until'])), throttleLogUntils('throttle-paused', 'retry-date'),
            'The extended pause is logged with its end');
        list($low, $high) = $range('retry-short', $pause);
        throttleAssertBetween($states['retry-short']['until'], $low, $high, 'A Retry-After shorter than the backoff keeps the backoff');
        list($low, $high) = $range('retry-cap', 1.5);
        throttleAssertBetween($states['retry-cap']['until'], $low, $high, 'maxPause caps a longer Retry-After');
        throttleSleepUntil($fetches['retry-delta']['wallReturned'] + 1.5 * $pause);
        throttleAssertRefused(throttleFetch('https://retry-delta.test/again'), Snoopy::REQUEST_THROTTLED,
            'A request after the backoff but within Retry-After');
        throttleAssertWire(array_map(function ($reply) { return $reply['url']; }, $plan), 'The refused request never reached the wire');
    },
    'a margin ends each pause early after the doubling, never shortens a Retry-After, and is at most half a pause' =>
        function () {
        $pause = 0.4;
        $margin = 0.1;
        $host = 'https://margin429.test';
        Snoopy::throttleHosts('margin429', array('margin429.test'), 0.02, $pause, 10, $margin);
        throttlePlan(array(ScriptedCurlFixture::reply('GET', "$host/1", 429, ''), ScriptedCurlFixture::reply('GET', "$host/2", 429, ''),
            ScriptedCurlFixture::reply('GET', 'https://marginra.test/x', 429, '', array('Retry-After: 2')),
            ScriptedCurlFixture::reply('GET', 'https://marginhalf.test/x', 429, '')));
        $first = throttleFetch("$host/1");
        $paused = throttleState('margin429');
        throttleAssertBetween($paused['until'], $first['wallCalled'] + $pause - $margin, $first['wallReturned'] + $pause - $margin,
            'The first pause ends the margin early');
        throttleSleepUntil($paused['until'] + 0.02);
        $second = throttleFetch("$host/2");
        throttleAssertBetween(throttleState('margin429')['until'], $second['wallCalled'] + 2 * $pause - $margin,
            $second['wallReturned'] + 2 * $pause - $margin, 'The doubled pause ends the same margin early');
        Snoopy::throttleHosts('marginra', array('marginra.test'), 0.02, $pause, 10, $margin);
        $ra = throttleFetch('https://marginra.test/x');
        throttleAssertBetween(throttleState('marginra')['until'], $ra['wallCalled'] + 2, $ra['wallReturned'] + 2,
            'A Retry-After is honoured in full');
        Snoopy::throttleHosts('marginhalf', array('marginhalf.test'), 0.02, $pause, 10, 5 * $pause);
        $half = throttleFetch('https://marginhalf.test/x');
        throttleAssertBetween(throttleState('marginhalf')['until'], $half['wallCalled'] + $pause / 2, $half['wallReturned'] + $pause / 2,
            'A margin longer than half the pause is cut to half');
        throttleAssertWire(array("$host/1", "$host/2", 'https://marginra.test/x', 'https://marginhalf.test/x'), 'Each request was sent');
    },
    'a Retry-After that is neither delta-seconds nor an exact IMF-fixdate is ignored' => function () {
        $pause = 0.1;
        $at = time() + 30;
        // Read as dates, each of these would pause for 30 s to a day.
        $values = array(
            'negative' => '-5', 'letter' => 'x', 'word' => 'tomorrow', 'relative' => '+1 week', 'signed' => '+30',
            'fraction' => '1.5', 'lowercase' => strtolower(gmdate('D, d M Y H:i:s', $at)) . ' gmt',
            'rfc850' => gmdate('l, d-M-y H:i:s', $at) . ' GMT', 'asctime' => gmdate('D M j H:i:s Y', $at),
            'weekday' => gmdate('D', $at + 86400) . gmdate(', d M Y H:i:s', $at) . ' GMT',
        );
        throttlePlan(array_map(function ($name) use ($values) {
            return ScriptedCurlFixture::reply('GET', "https://ra-$name.test/x", 429, '', array('Retry-After: ' . $values[$name]));
        }, array_keys($values)));
        foreach ($values as $name => $value) {
            Snoopy::throttleHosts("ra-$name", array("ra-$name.test"), 0.02, $pause, 86400);
            $fetch = throttleFetch("https://ra-$name.test/x");
            testAssertSame('429', $fetch['status'], "Retry-After: $value draws a 429");
            throttleAssertBetween(throttleState("ra-$name")['until'], $fetch['wallCalled'] + $pause, $fetch['wallReturned'] + $pause,
                "Retry-After: $value leaves the plain backoff");
        }
        throttleAssertWire(array_map(function ($name) { return "https://ra-$name.test/x"; }, array_keys($values)), 'Each request was sent');
    },
    'a request that never reached the host hands its turn back' => function () {
        $interval = 0.4;
        foreach (array(6, 7) as $exit) {
            $group = 'unsent' . $exit;
            $host = "https://$group.test";
            Snoopy::throttleHosts($group, array("$group.test"), $interval, 1, 1);
            throttlePlan(array(ScriptedCurlFixture::reply('GET', "$host/first", 200, ''),
                ScriptedCurlFixture::failure('GET', "$host/lost", $exit), ScriptedCurlFixture::reply('GET', "$host/after", 200, '')));
            $first = throttleFetch("$host/first");
            $before = throttleState($group);
            $lost = throttleFetch("$host/lost");
            testAssertSame(false, $lost['ok'], "curl exit $exit fails the fetch");
            testAssertSame($exit, $lost['status'], "curl's exit code $exit is the status");
            testAssertSame("Error: cURL could not retrieve the document, error $exit.", $lost['error'], "curl exit $exit is reported");
            $wire = throttleWire();
            testAssertTrue($wire[1]['time'] - $first['returned'] >= $interval - THROTTLE_SLACK, "The request lost with exit $exit waited for its turn");
            testAssertSame($before['next'], throttleState($group)['next'], "Exit $exit hands the turn back: the next turn is the one before it");
            $after = throttleFetch("$host/after");
            $wire = throttleWire();
            throttleAssertPrompt($after, $wire[2], $interval / 2, "After exit $exit the next request takes the returned turn at once");
            testAssertTrue($wire[2]['time'] - $wire[0]['time'] >= $interval, "and stays an interval after the last request that reached the host");
            throttleAssertWire(array("$host/first", "$host/lost", "$host/after"), "Each request went out once (exit $exit)");
        }
    },
    'a transfer that may have reached the host keeps its turn, and only an HTTP answer clears the strikes' => function () {
        $interval = 0.25;
        $pause = 0.1;
        $host = 'https://sentfail.test';
        Snoopy::throttleHosts('sentfail', array('sentfail.test'), $interval, $pause, 10);
        throttlePlan(array(ScriptedCurlFixture::reply('GET', "$host/limited", 429, ''), ScriptedCurlFixture::failure('GET', "$host/timeout", 28),
            ScriptedCurlFixture::reply('GET', "$host/unavailable", 503, ''), ScriptedCurlFixture::reply('GET', "$host/after", 200, '')));
        throttleFetch("$host/limited");
        testAssertSame(1, throttleState('sentfail')['strikes'], 'The 429 is a strike');
        throttleSleepUntil(throttleState('sentfail')['until'] + 0.02);
        $timeout = throttleFetch("$host/timeout");
        testAssertSame(false, $timeout['ok'], 'A timed-out transfer fails the fetch');
        testAssertSame(28, $timeout['status'], "curl's timeout is the status");
        $state = throttleState('sentfail');
        throttleAssertBetween($state['ended'], $timeout['wallCalled'] + $interval, $timeout['wallReturned'] + $interval,
            'A timed-out transfer keeps a whole interval after it ended');
        testAssertSame(1, $state['strikes'], 'A transfer without an HTTP answer leaves the strikes');
        $unavailable = throttleFetch("$host/unavailable");
        testAssertSame('503', $unavailable['status'], 'The host answers 503');
        testAssertSame(0, throttleState('sentfail')['strikes'], 'An HTTP answer other than 429 clears the strikes');
        $after = throttleFetch("$host/after");
        testAssertSame(true, $after['ok'], 'The following request is answered');
        $wire = throttleWire();
        testAssertTrue($wire[2]['time'] - $timeout['returned'] >= $interval - THROTTLE_SLACK,
            sprintf('The request after a timeout waits a whole interval (%.3f s)', $wire[2]['time'] - $timeout['returned']));
        testAssertTrue($wire[3]['time'] - $unavailable['returned'] >= $interval - THROTTLE_SLACK,
            sprintf('The request after a 503 waits a whole interval (%.3f s)', $wire[3]['time'] - $unavailable['returned']));
        throttleAssertWire(array("$host/limited", "$host/timeout", "$host/unavailable", "$host/after"), 'Each request went out once');
    },
    'a 429 whose body is refused still pauses the group, and a refused answer still clears the strikes' => function () {
        $pause = 0.15;
        $gzip = array('Content-Encoding: gzip');
        throttlePlan(array(ScriptedCurlFixture::reply('GET', 'https://body-gzip.test/x', 429, 'not gzip', $gzip),
            ScriptedCurlFixture::reply('GET', 'https://body-size.test/x', 429, str_repeat('x', 64)),
            ScriptedCurlFixture::reply('GET', 'https://body-gzip.test/y', 200, 'not gzip', $gzip)));
        $refusals = array('body-gzip' => array('invalid-or-oversized-gzip', 'gzip-decoder-unavailable'),
            'body-size' => array('unreadable-or-oversized-response'));
        foreach ($refusals as $group => $reasons) {
            Snoopy::throttleHosts($group, array("$group.test"), 0.02, $pause, 10);
            $fetch = throttleFetch("https://$group.test/x", null, null, false, 16);
            testAssertSame(false, $fetch['ok'], "$group: the body is refused");
            testAssertTrue(in_array($fetch['error'], $reasons, true), "$group: the refusal is named: " . $fetch['error']);
            $state = throttleState($group);
            testAssertSame(1, $state['strikes'], "$group: the 429 line is a strike");
            throttleAssertBetween($state['until'], $fetch['wallCalled'] + $pause, $fetch['wallReturned'] + $pause, "$group: the 429 line pauses the group");
        }
        throttleSleepUntil(throttleState('body-gzip')['until'] + 0.02);
        $after = throttleFetch('https://body-gzip.test/y');
        testAssertSame(false, $after['ok'], 'A 200 with a broken body is refused too');
        testAssertSame(0, throttleState('body-gzip')['strikes'], 'but its status line is an answer, and clears the strikes');
        throttleAssertWire(array('https://body-gzip.test/x', 'https://body-size.test/x', 'https://body-gzip.test/y'), 'Each request was sent once');
    },
    'a 429 that curl cuts off at its size limit still pauses the group' => function () {
        $pause = 0.15;
        Snoopy::throttleHosts('cutoff', array('cutoff.test'), 0.02, $pause, 10);
        Snoopy::throttleHosts('cutoff-retry', array('cutoff-retry.test'), 0.02, $pause, 10);
        // The second answer comes after a proxy's CONNECT block, as curl -D writes it.
        throttlePlan(array(ScriptedCurlFixture::reply('GET', 'https://cutoff.test/x', 429, str_repeat('x', 64)),
            ScriptedCurlFixture::reply('GET', 'https://cutoff-retry.test/x', 200, str_repeat('x', 64),
                array('', 'HTTP/1.1 429 Too Many Requests', 'Retry-After: 2')),
            ScriptedCurlFixture::reply('GET', 'https://cutoff.test/moved', 302, str_repeat('x', 64),
                array('Location: https://cutoff.test/elsewhere'))));
        // curl has written the headers when --max-filesize stops the body: exit 63.
        throttleUseHook(array('THROTTLE_HOOK_EXIT' => 63));
        try {
            $fetch = throttleFetch('https://cutoff.test/x');
            $retry = throttleFetch('https://cutoff-retry.test/x');
            // The transport alone, without the throttle: what a cut-off answer leaves behind.
            $client = new Snoopy();
            $moved = $client->_httpsrequest('https://cutoff.test/moved');
        } finally {
            throttleResetTransport();
        }
        testAssertSame(63, $fetch['status'], "curl's exit code is the status");
        testAssertSame('oversized-response', $fetch['error'], 'The oversized answer is named');
        $state = throttleState('cutoff');
        testAssertSame(1, $state['strikes'], 'The 429 line in the headers curl wrote is a strike');
        throttleAssertBetween($state['until'], $fetch['wallCalled'] + $pause, $fetch['wallReturned'] + $pause, 'and pauses the group');
        throttleAssertBetween(throttleState('cutoff-retry')['until'], $retry['wallCalled'] + 2, $retry['wallReturned'] + 2,
            'The final block of the headers is the answer, and its Retry-After counts');
        testAssertSame(false, $moved, 'A cut-off redirect fails the transfer');
        testAssertSame(63, $client->status, "and keeps curl's exit code");
        testAssertSame(false, $client->_redirectaddr, 'Nothing is left to follow');
        testAssertSame("HTTP/1.1 302 Fixture\r\n", $client->response_code, 'The response line describes the answer');
        testAssertTrue(in_array("Location: https://cutoff.test/elsewhere\r\n", $client->headers, true), 'and so do its headers');
        throttleAssertWire(array('https://cutoff.test/x', 'https://cutoff-retry.test/x', 'https://cutoff.test/moved'),
            'Each request was sent once and no redirect was followed');
    },
    'a request that cannot connect never reuses the previous answer\'s status line' => function () {
        $pause = 0.1;
        // Nothing listens on port 1; plain http without a deadline goes through
        // Snoopy's own socket, which reports the refusal without curl.
        Snoopy::throttleHosts('reused', array('127.0.0.1'), 0.02, $pause, 10);
        throttlePlan(array(ScriptedCurlFixture::reply('GET', 'https://127.0.0.1:1/a', 429, '')));
        $client = new Snoopy();
        testAssertSame(true, $client->fetch('https://127.0.0.1:1/a'), 'The first request is answered');
        $paused = throttleState('reused');
        testAssertSame(1, $paused['strikes'], 'Its 429 is a strike');
        throttleSleepUntil($paused['until'] + 0.02);
        // connect() reports the refusal as a PHP warning as well; it is expected here.
        set_error_handler(function ($number, $message) { return strpos($message, 'fsockopen') !== false; }, E_WARNING);
        try {
            $refused = $client->fetch('http://127.0.0.1:1/b');
        } finally {
            restore_error_handler();
        }
        testAssertSame(false, $refused, 'The second request cannot connect');
        testAssertSame(Snoopy::SOCKET_CONNECT_FAILED, $client->status, 'The status names the failed connection');
        $state = throttleState('reused');
        testAssertSame(1, $state['strikes'], 'A request that reached no host adds no strike');
        testAssertSame($paused['until'], $state['until'], 'and starts no pause');
        testAssertSame(1, count(throttleLogUntils('throttle-paused', 'reused')), 'Only the real 429 is logged as a pause');
        throttleAssertWire(array('https://127.0.0.1:1/a'), 'Only the first request went through curl');
    },
    'a request that never reached the host keeps a turn reserved after its own' => function () {
        global $throttleRoot;
        $interval = 0.3;
        $host = 'https://contended.test';
        Snoopy::throttleHosts('contended', array('contended.test'), $interval, 1, 1);
        throttlePlan(array(ScriptedCurlFixture::reply('GET', "$host/first", 200, ''),
            ScriptedCurlFixture::failure('GET', "$host/lost", 6), ScriptedCurlFixture::reply('GET', "$host/after", 200, '')));
        throttleFetch("$host/first");
        // Another process reserves the following turn while the lost request is
        // on its way; the hook makes that reservation before curl gives up.
        $record = $throttleRoot . '/contended.competitor';
        throttleUseHook(array('THROTTLE_HOOK_RESERVE' => throttleStatePath('contended'),
            'THROTTLE_HOOK_INTERVAL' => $interval, 'THROTTLE_HOOK_RECORD' => $record));
        try {
            $lost = throttleFetch("$host/lost");
        } finally {
            throttleResetTransport();
        }
        testAssertSame(6, $lost['status'], 'The request is lost before it reaches the host');
        $competitor = json_decode((string) file_get_contents($record), true);
        testAssertTrue(is_array($competitor), 'The competing turn was reserved (precondition)');
        testAssertSame($competitor['next'], throttleState('contended')['next'],
            'The lost request does not hand back a turn somebody reserved after it');
        $after = throttleFetch("$host/after");
        $wire = throttleWire();
        $sentAt = $after['wallCalled'] + ($wire[2]['time'] - $after['called']);
        testAssertTrue($sentAt >= $competitor['next'] - THROTTLE_SLACK,
            sprintf('The next request waits until the competing turn is over (%.3f s early)', $competitor['next'] - $sentAt));
        throttleAssertWire(array("$host/first", "$host/lost", "$host/after"), 'Each request went out once');
    },
    'a turn handed back keeps the interval after the last request that ended' => function () {
        global $throttleRoot;
        $interval = 0.5;
        $hold = 0.3;
        Snoopy::throttleHosts('endrule', array('endrule.test'), $interval, 1, 1);
        $signal = $throttleRoot . '/endrule.signal';
        throttlePlan(array(ScriptedCurlFixture::reply('GET', 'https://endrule.test/a', 200, ''),
            ScriptedCurlFixture::failure('GET', 'https://endrule.test/b', 6),
            ScriptedCurlFixture::reply('GET', 'https://endrule.test/c', 200, '')));
        // B reserves the turn after A's while A's transfer lasts. That turn
        // comes after A ended and is lost (curl exit 6), so B hands it back,
        // and B's next request follows at once.
        $child = throttleStartChild('endrule', array('group' => 'endrule', 'hosts' => array('endrule.test'), 'interval' => $interval,
            'pause' => 1, 'maxPause' => 1, 'urls' => array('https://endrule.test/b', 'https://endrule.test/c'),
            'ready' => $throttleRoot . '/endrule.ready', 'go' => $signal));
        try {
            throttleAwaitFiles(array($throttleRoot . '/endrule.ready'), 'Process B starts');
            throttleUseHook(array('THROTTLE_HOOK_SIGNAL' => $signal, 'THROTTLE_HOOK_STATE' => throttleStatePath('endrule'),
                'THROTTLE_HOOK_HOLD' => (int) ($hold * 1000000)));
            try {
                $first = throttleFetch('https://endrule.test/a');
            } finally {
                throttleResetTransport();
            }
        } finally {
            if (!file_exists($signal)) touch($signal);
            $results = throttleFinishChild($child);
        }
        testAssertSame(true, $first['ok'], 'A is answered');
        testAssertTrue($results[0]['called'] < $first['returned'] - 0.1, 'B reserved its turn while A was on the wire (precondition)');
        testAssertSame(6, $results[0]['status'], "B's first request is lost before it reaches the host");
        testAssertSame(true, $results[1]['ok'], "B's next request is answered");
        $wire = throttleWire();
        testAssertTrue($wire[1]['time'] > $first['returned'], "B's lost turn came after A ended (precondition)");
        $gap = $wire[2]['time'] - $first['returned'];
        testAssertTrue($gap >= $interval - THROTTLE_SLACK, sprintf("B's next request starts an interval after A ended (%.3f s)", $gap));
        throttleAssertWire(array('https://endrule.test/a', 'https://endrule.test/b', 'https://endrule.test/c'), 'Each request went out once');
    },
    'a turn that would outlast the request deadline is refused before anything is sent' => function () {
        $interval = 0.6;
        $host = 'https://deadline.test';
        Snoopy::throttleHosts('deadline', array('deadline.test'), $interval, 1, 1);
        throttlePlan(array(ScriptedCurlFixture::reply('GET', "$host/first", 200, ''), ScriptedCurlFixture::reply('GET', "$host/patient", 200, '')));
        $first = throttleFetch("$host/first");
        $turn = throttleState('deadline');
        $hasty = throttleFetch("$host/hasty", 0.2);
        throttleAssertRefused($hasty, 'request-deadline', 'A request whose deadline ends before its turn');
        testAssertTrue($hasty['returned'] - $hasty['called'] < 0.1,
            sprintf('It is refused at once instead of waiting out its deadline (%.3f s)', $hasty['returned'] - $hasty['called']));
        testAssertSame($turn, throttleState('deadline'), 'A refused request takes no turn');
        $patient = throttleFetch("$host/patient", 3.0);
        testAssertSame(true, $patient['ok'], 'A deadline that covers the wait is answered');
        $wire = throttleWire();
        $waited = $wire[1]['time'] - $first['returned'];
        testAssertTrue($waited >= $interval - THROTTLE_SLACK, sprintf('It waits for its turn (%.3f s)', $waited));
        testAssertTrue($waited < 2 * $interval - THROTTLE_SLACK, sprintf('and not for a turn the refused request never used (%.3f s)', $waited));
        throttleAssertWire(array("$host/first", "$host/patient"), 'The refused request never reached the wire');
    },
    'a deadline that would leave its last millisecond after the wait is refused at once' => function () {
        $interval = 0.3;
        Snoopy::throttleHosts('margin', array('margin.test'), $interval, 1, 1);
        throttlePlan(array(ScriptedCurlFixture::reply('GET', 'https://margin.test/first', 200, ''),
            ScriptedCurlFixture::reply('GET', 'https://margin.test/next', 200, '')));
        $first = throttleFetch('https://margin.test/first');
        $turn = throttleState('margin');
        // The final millisecond of a deadline is never spent on the wire.
        $hasty = throttleFetch('https://margin.test/hasty', function () use ($turn) { return throttleTurn($turn) - microtime(true) + 0.0005; });
        throttleAssertRefused($hasty, 'request-deadline', 'A request whose turn leaves under a millisecond of its deadline');
        testAssertTrue($hasty['returned'] - $hasty['called'] < $interval / 2,
            sprintf('It is refused at once instead of waiting for its turn (%.3f s)', $hasty['returned'] - $hasty['called']));
        testAssertSame($turn, throttleState('margin'), 'It takes no turn');
        $next = throttleFetch('https://margin.test/next');
        $wire = throttleWire();
        $waited = $wire[1]['time'] - $first['returned'];
        testAssertTrue($waited >= $interval - THROTTLE_SLACK && $waited < 2 * $interval - THROTTLE_SLACK,
            sprintf('The next request keeps the original turn (%.3f s after the first ended)', $waited));
        throttleAssertWire(array('https://margin.test/first', 'https://margin.test/next'), 'The refused request never reached the wire');
    },
    'a deadline that runs out while the request waits hands its turn back' => function () {
        $interval = 0.3;
        Snoopy::throttleHosts('phantom', array('phantom.test'), $interval, 1, 1);
        throttlePlan(array(ScriptedCurlFixture::reply('GET', 'https://phantom.test/first', 200, ''),
            ScriptedCurlFixture::reply('GET', 'https://phantom.test/next', 200, '')));
        throttleFetch('https://phantom.test/first');
        $turn = throttleState('phantom');
        // Admitted with exactly the final millisecond to spare: waking up at the
        // turn takes longer than the microsecond between this sum and the
        // deadline being set, so the deadline is spent when the turn comes.
        $late = throttleFetch('https://phantom.test/late', function () use ($turn) { return throttleTurn($turn) - microtime(true) + 0.001; });
        throttleAssertRefused($late, 'request-deadline', 'A request whose deadline ran out at its turn');
        testAssertTrue($late['returned'] - $late['called'] >= $interval / 2,
            sprintf('It was admitted and waited for its turn (%.3f s, precondition)', $late['returned'] - $late['called']));
        testAssertSame($turn, throttleState('phantom'), 'Its unused turn was handed back');
        $next = throttleFetch('https://phantom.test/next');
        throttleAssertPrompt($next, throttleWire()[1], $interval / 2, 'The next request does not wait for a turn nobody used');
        throttleAssertWire(array('https://phantom.test/first', 'https://phantom.test/next'), 'The refused request never reached the wire');
    },
    'stored turns, pauses and strikes no writer could have stored are ignored and replaced' => function () {
        $interval = 0.1;
        $pause = 0.05;
        $maxPause = 5;
        $now = microtime(true);
        $cases = array(
            'garbage' => 'not json {',
            'scalar' => '12345',
            'far' => array('next' => $now + 700, 'until' => $now + $maxPause + 60, 'strikes' => 17),
            'far-ended' => array('next' => 0, 'ended' => $now + 700, 'until' => 0, 'strikes' => 0),
            'negative' => array('next' => -1, 'ended' => -2, 'until' => -100, 'strikes' => -3),
            'text' => array('next' => 'soon', 'ended' => 'over', 'until' => 'later', 'strikes' => 'many'),
            'infinite' => '{"next":1e999,"ended":1e999,"until":1e999,"strikes":1e999}',
        );
        $urls = array();
        foreach ($cases as $name => $stored) $urls[$name] = "https://sane-$name.test/x";
        throttlePlan(array_map(function ($url) { return ScriptedCurlFixture::reply('GET', $url, 429, ''); }, array_values($urls)));
        foreach ($cases as $name => $stored) {
            Snoopy::throttleHosts("sane-$name", array("sane-$name.test"), $interval, $pause, $maxPause);
            throttleStoreState("sane-$name", $stored);
            // The deadline turns an honored far-future turn into a quick refusal.
            $fetch = throttleFetch($urls[$name], 2.0);
            testAssertSame(true, $fetch['ok'], "Stored state '$name' neither pauses the group nor delays its turn: " . $fetch['error']);
            $state = throttleState("sane-$name");
            testAssertSame(1, $state['strikes'], "Stored state '$name' adds no strikes: the 429 is the first");
            throttleAssertBetween($state['until'], $fetch['wallCalled'] + $pause, $fetch['wallReturned'] + $pause,
                "Stored state '$name' is replaced by a first pause");
            throttleAssertBetween($state['next'], $fetch['wallCalled'] + $interval, $fetch['wallReturned'] + $interval,
                "Stored state '$name' is replaced by the next turn");
            throttleAssertBetween($state['ended'], $fetch['wallCalled'] + $interval, $fetch['wallReturned'] + $interval,
                "Stored state '$name' is replaced by the end bound");
        }
        throttleAssertWire(array_values($urls), 'Every request was sent');
    },
    'stored turns, pauses and strikes within what a writer could store are honored' => function () {
        throttlePlan(array(ScriptedCurlFixture::reply('GET', 'https://honor-next.test/x', 200, ''),
            ScriptedCurlFixture::reply('GET', 'https://honor-ended.test/x', 200, ''),
            ScriptedCurlFixture::reply('GET', 'https://honor-strikes.test/x', 429, '')));
        $stored = array('next' => 'A turn another process reserved', 'ended' => 'The interval after another process\'s request ended');
        foreach ($stored as $key => $what) {
            Snoopy::throttleHosts("honor-$key", array("honor-$key.test"), 0.1, 1, 1);
            $turn = microtime(true) + 0.3;
            $storedState = array('next' => 0, 'ended' => 0, 'until' => 0, 'strikes' => 0);
            $storedState[$key] = $turn;
            throttleStoreState("honor-$key", $storedState);
            $waiting = throttleFetch("https://honor-$key.test/x");
            $wire = throttleWire();
            $request = $wire[count($wire) - 1];
            $sentAt = $waiting['wallCalled'] + ($request['time'] - $waiting['called']);
            testAssertTrue($sentAt >= $turn - THROTTLE_SLACK, sprintf('%s is waited for (%.3f s early)', $what, $turn - $sentAt));
        }
        Snoopy::throttleHosts('honor-until', array('honor-until.test'), 0.1, 1, 5);
        throttleStoreState('honor-until', array('next' => 0, 'until' => microtime(true) + 3, 'strikes' => 1));
        throttleAssertRefused(throttleFetch('https://honor-until.test/x'), Snoopy::REQUEST_THROTTLED,
            'A pause another process started');
        // The bound on a stored turn grows with the interval: 32 intervals of 30 s exceed 600 s.
        Snoopy::throttleHosts('honor-long', array('honor-long.test'), 30, 1, 1);
        throttleStoreState('honor-long', array('next' => microtime(true) + 700, 'until' => 0, 'strikes' => 0));
        throttleAssertRefused(throttleFetch('https://honor-long.test/x', 0.2), 'request-deadline',
            'A turn 700 s away in a group with a 30 s interval');
        // Sixteen strikes is the ceiling, and its pause is maxPause.
        Snoopy::throttleHosts('honor-strikes', array('honor-strikes.test'), 0.02, 0.05, 0.3);
        throttleStoreState('honor-strikes', array('next' => 0, 'until' => 0, 'strikes' => 16));
        $limited = throttleFetch('https://honor-strikes.test/x');
        testAssertSame('429', $limited['status'], 'A group at the strike ceiling is answered with another 429');
        $state = throttleState('honor-strikes');
        testAssertSame(16, $state['strikes'], 'The strike count stays at its ceiling');
        throttleAssertBetween($state['until'], $limited['wallCalled'] + 0.3, $limited['wallReturned'] + 0.3, 'and the pause at maxPause');
        throttleAssertWire(array('https://honor-next.test/x', 'https://honor-ended.test/x', 'https://honor-strikes.test/x'),
            'Refused requests never reached the wire');
    },
    'a state file that cannot be opened leaves the group paced in this process, logged once' => function () {
        $interval = 0.2;
        $host = 'https://blocked.test';
        $path = throttleStatePath('blocked');
        mkdir($path, 0700, true);
        Snoopy::throttleHosts('blocked', array('blocked.test'), $interval, 0.5, 0.5);
        throttlePlan(array(ScriptedCurlFixture::reply('GET', "$host/1", 200, ''), ScriptedCurlFixture::reply('GET', "$host/2", 200, ''),
            ScriptedCurlFixture::reply('GET', "$host/3", 429, '')));
        $fetches = array(throttleFetch("$host/1"), throttleFetch("$host/2"), throttleFetch("$host/3"));
        testAssertSame('429', $fetches[2]['status'], 'The third request draws a 429');
        throttleAssertTurns($fetches, throttleWire(), $interval);
        throttleAssertRefused(throttleFetch("$host/4"), Snoopy::REQUEST_THROTTLED, 'The pause holds in this process');
        testAssertTrue(is_dir($path), 'Nothing replaced the unusable path');
        testAssertSame(1, throttleLogCount('Snoopy: throttle-state-unavailable group=blocked; pacing stays within this process'),
            'The fallback is logged once');
        throttleAssertWire(array("$host/1", "$host/2", "$host/3"), 'The refused request never reached the wire');
    },
    'a state file that cannot be locked leaves the group paced in this process, logged once' => function () {
        $interval = 0.15;
        $url = 'https://unlocked.test/x';
        throttlePlan(array_merge(array(ScriptedCurlFixture::reply('GET', 'https://warm.test/', 200, '')),
            array_fill(0, 3, ScriptedCurlFixture::reply('GET', $url, 200, ''))));
        $fetches = throttleRunChild('unlocked', array('refuseLocks' => true, 'warm' => 'https://warm.test/', 'group' => 'unlocked',
            'hosts' => array('unlocked.test'), 'interval' => $interval, 'pause' => 1, 'maxPause' => 1, 'urls' => array($url, $url, $url)));
        foreach ($fetches as $fetch) testAssertSame(true, $fetch['ok'], 'The process without locks is answered');
        $wire = throttleWire();
        testAssertSame('https://warm.test/', $wire[0]['url'], 'The warm-up request went first');
        throttleAssertTurns($fetches, array_slice($wire, 1), $interval);
        testAssertSame('', (string) @file_get_contents(throttleStatePath('unlocked')), 'Nothing was written without the lock');
        testAssertSame(1, throttleLogCount('Snoopy: throttle-state-unavailable group=unlocked; pacing stays within this process',
            throttleChildLog('unlocked')), 'The fallback is logged once');
    },
    'a read-only process paces its group by itself' => function () {
        $interval = 0.2;
        $url = 'https://readonly.test/x';
        throttlePlan(array_fill(0, 3, ScriptedCurlFixture::reply('GET', $url, 200, '')));
        $fetches = throttleRunChild('readonly', array('readOnly' => true, 'group' => 'readonly', 'hosts' => array('readonly.test'),
            'interval' => $interval, 'pause' => 1, 'maxPause' => 1, 'urls' => array($url, $url, $url)));
        foreach ($fetches as $fetch) testAssertSame(true, $fetch['ok'], 'The read-only process is answered');
        throttleAssertTurns($fetches, throttleWire(), $interval);
        testAssertTrue(!file_exists(throttleStatePath('readonly')), 'A read-only process writes no state file');
    },
    'a read-only process logs its in-process fallback once' => function () {
        $url = 'https://readonly-log.test/x';
        throttlePlan(array_fill(0, 2, ScriptedCurlFixture::reply('GET', $url, 200, '')));
        $fetches = throttleRunChild('readonly-log', array('readOnly' => true, 'group' => 'readonly-log',
            'hosts' => array('readonly-log.test'), 'interval' => 0.05, 'pause' => 1, 'maxPause' => 1, 'urls' => array($url, $url)));
        foreach ($fetches as $fetch) testAssertSame(true, $fetch['ok'], 'The read-only process is answered');
        testAssertSame(1, throttleLogCount('Snoopy: throttle-state-unavailable group=readonly-log; pacing stays within this process',
            throttleChildLog('readonly-log')), 'A read-only process that paces a group by itself says so once');
    },
    'invalid group names register nothing' => function () {
        $interval = 0.4;
        $names = array('Upper', 'dot.ted', '../escape', "trailing\n", 'with space', '', str_repeat('a', 33), 42, null, array('list'));
        $plan = array();
        foreach ($names as $i => $name)
            foreach (array('one', 'two') as $path) $plan[] = ScriptedCurlFixture::reply('GET', "https://invalid$i.test/$path", 200, '');
        $plan[] = ScriptedCurlFixture::reply('GET', 'https://boundary.test/one', 200, '');
        $plan[] = ScriptedCurlFixture::reply('GET', 'https://boundary.test/two', 200, '');
        throttlePlan($plan);
        $settings = FileUtil::getSettingsPathEx('');
        $files = glob($settings . '/throttle-*');
        foreach ($names as $i => $name) {
            Snoopy::throttleHosts($name, array("invalid$i.test"), $interval, 1, 1);
            throttleFetch("https://invalid$i.test/one");
            $second = throttleFetch("https://invalid$i.test/two");
            $wire = throttleWire();
            throttleAssertPrompt($second, $wire[count($wire) - 1], $interval / 2, 'Group name ' . json_encode($name) . ' throttles nothing');
        }
        testAssertSame($files, glob($settings . '/throttle-*'), 'Invalid names create no state file');
        Snoopy::throttleHosts(str_repeat('a', 32), array('boundary.test'), $interval, 1, 1);
        $first = throttleFetch('https://boundary.test/one');
        throttleFetch('https://boundary.test/two');
        $wire = throttleWire();
        testAssertTrue($wire[count($wire) - 1]['time'] - $first['returned'] >= $interval - THROTTLE_SLACK,
            'A 32-character name registers a group');
        testAssertSame(count($plan), count($wire), 'Every request was sent');
        throttleAssertWire(array_map(function ($reply) { return $reply['url']; }, $plan), 'Every request was sent in order');
    },
    'a disabled group takes no turns, and each half of a group works on its own' => function () {
        $interval = 0.4;
        $offs = array('negative' => array(-1, -1), 'text' => array('none', 'none'), 'infinite' => array(INF, 1));
        $plan = array(ScriptedCurlFixture::reply('GET', 'https://toggle.test/one', 200, ''),
            ScriptedCurlFixture::reply('GET', 'https://toggle.test/two', 200, ''));
        foreach ($offs as $name => $values)
            foreach (array('one', 'two') as $path) $plan[] = ScriptedCurlFixture::reply('GET', "https://off-$name.test/$path", 200, '');
        $plan = array_merge($plan, array(ScriptedCurlFixture::reply('GET', 'https://pauseonly.test/1', 200, ''),
            ScriptedCurlFixture::reply('GET', 'https://pauseonly.test/2', 200, ''), ScriptedCurlFixture::reply('GET', 'https://pauseonly.test/3', 429, ''),
            ScriptedCurlFixture::reply('GET', 'https://paceonly.test/1', 429, ''), ScriptedCurlFixture::reply('GET', 'https://paceonly.test/2', 200, '')));
        throttlePlan($plan);
        Snoopy::throttleHosts('toggle', array('toggle.test'), $interval, 1, 1);
        throttleFetch('https://toggle.test/one');
        $turn = throttleState('toggle');
        Snoopy::throttleHosts('toggle', array('toggle.test'), 0, 0, 0);
        $second = throttleFetch('https://toggle.test/two');
        $wire = throttleWire();
        throttleAssertPrompt($second, $wire[1], $interval / 2, 'An interval and a pause of 0 disable the group');
        testAssertSame($turn, throttleState('toggle'), 'A disabled group takes no turns');
        foreach ($offs as $name => $values) {
            Snoopy::throttleHosts("off-$name", array("off-$name.test"), $interval, 1, 1);
            throttleFetch("https://off-$name.test/one");
            Snoopy::throttleHosts("off-$name", array("off-$name.test"), $values[0], $values[1], 10);
            $second = throttleFetch("https://off-$name.test/two");
            $wire = throttleWire();
            throttleAssertPrompt($second, $wire[count($wire) - 1], $interval / 2, "A $name interval and pause disable the group");
        }
        Snoopy::throttleHosts('pauseonly', array('pauseonly.test'), 0, 0.5, 0.5);
        throttleFetch('https://pauseonly.test/1');
        $second = throttleFetch('https://pauseonly.test/2');
        $wire = throttleWire();
        throttleAssertPrompt($second, $wire[count($wire) - 1], $interval / 2, 'An interval of 0 spaces nothing');
        testAssertSame('429', throttleFetch('https://pauseonly.test/3')['status'], 'The pause-only group draws a 429');
        throttleAssertRefused(throttleFetch('https://pauseonly.test/4'), Snoopy::REQUEST_THROTTLED, 'and still pauses on it');
        Snoopy::throttleHosts('paceonly', array('paceonly.test'), $interval, 0, 0);
        $limited = throttleFetch('https://paceonly.test/1');
        testAssertSame('429', $limited['status'], 'The pace-only group draws a 429');
        $next = throttleFetch('https://paceonly.test/2');
        testAssertSame(true, $next['ok'], 'A pause of 0 refuses nothing');
        $wire = throttleWire();
        testAssertTrue($wire[count($wire) - 1]['time'] - $limited['returned'] >= $interval - THROTTLE_SLACK, 'and the interval still spaces requests');
        throttleAssertWire(array_map(function ($reply) { return $reply['url']; }, $plan), 'Every request but the refused one was sent');
    },
);

try {
    $status = testRunCases($tests, function () {
        throttleResetTransport();
        throttlePlan(array());
    });
} finally {
    throttleResetTransport();
    $throttleCurl->restore();
    throttleRemoveTree($throttleRoot);
}
exit($status);
