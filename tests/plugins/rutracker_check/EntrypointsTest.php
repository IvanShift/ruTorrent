<?php

// The three files nothing else executes.
//
// update.php, batch_check.php and forumcrawl.php are pure composition: they
// build one XMLRPC request, then call the pieces every other suite tests
// individually, in an order that matters. Nothing asserted that order, or even
// that the calls were still there -- delete RuTrackerUpdatePass::reapOrphans()
// from update.php, or move sweepReplacements() below run(), and all thirteen
// suites stayed green.
//
// These files cannot simply be included: each one chdir()s, requires the real
// check.php and issues live XMLRPC on load. So the composition is read from the
// source with the tokeniser instead -- the sequence of Class::method() calls,
// in the order they appear, comments and formatting ignored. That pins what
// these drivers are for (which pieces, in which order) without pretending to
// execute them; the pieces' own behaviour is covered by the other suites.

require_once(__DIR__ . '/TestLib.php');

$suite = new StrictTestSuite();

define('EP_DIR', __DIR__ . '/../../../plugins/rutracker_check');

// Every "Class::method" appearing as a call, in source order.
function epCalls($file)
{
    $path = EP_DIR . '/' . $file;
    strictAssertTrue(is_file($path), $file . ' exists');
    $tokens = token_get_all(file_get_contents($path));
    $calls = array();
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_DOUBLE_COLON) continue;
        // <name> :: <name>, and a '(' after it to make it a call rather than a
        // constant reference.
        $class = null;
        for ($b = $i - 1; $b >= 0; $b--) {
            if (is_array($tokens[$b]) && $tokens[$b][0] === T_WHITESPACE) continue;
            if (is_array($tokens[$b]) && $tokens[$b][0] === T_STRING) $class = $tokens[$b][1];
            break;
        }
        $method = null;
        $paren = false;
        for ($f = $i + 1; $f < $count; $f++) {
            if (is_array($tokens[$f]) && $tokens[$f][0] === T_WHITESPACE) continue;
            if ($method === null && is_array($tokens[$f]) && $tokens[$f][0] === T_STRING) {
                $method = $tokens[$f][1];
                continue;
            }
            $paren = ($tokens[$f] === '(');
            break;
        }
        if ($class !== null && $method !== null && $paren) $calls[] = $class . '::' . $method;
    }
    return $calls;
}

// Index of the first occurrence, or -1.
function epAt($calls, $needle)
{
    $i = array_search($needle, $calls, true);
    return $i === false ? -1 : $i;
}

function epAssertOrder($calls, $before, $after, $why)
{
    $a = epAt($calls, $before);
    $b = epAt($calls, $after);
    strictAssertTrue($a >= 0, $before . ' is still called');
    strictAssertTrue($b >= 0, $after . ' is still called');
    strictAssertTrue($a < $b, $why . ' (' . $before . ' at ' . $a . ', ' . $after . ' at ' . $b . ')');
}

function epNamedFunctionCallIndices($path, $name)
{
    $tokens = token_get_all(file_get_contents($path));
    $indices = array();
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== $name) continue;
        $previous = $index - 1;
        while ($previous >= 0 && is_array($tokens[$previous])
            && in_array($tokens[$previous][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) $previous--;
        if ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_FUNCTION) continue;
        $next = $index + 1;
        while ($next < count($tokens) && is_array($tokens[$next])
            && in_array($tokens[$next][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) $next++;
        if ($next < count($tokens) && $tokens[$next] === '(') $indices[] = $index;
    }
    return array($tokens, $indices);
}

function epFunctionCallArguments($path, $name)
{
    list($tokens, $indices) = epNamedFunctionCallIndices($path, $name);
    $arguments = array();
    foreach ($indices as $index) {
        while ($tokens[$index] !== '(') $index++;
        $depth = 1;
        $text = '';
        for ($index++; $index < count($tokens) && $depth > 0; $index++) {
            $token = $tokens[$index];
            if ($token === '(') $depth++;
            elseif ($token === ')') {
                $depth--;
                if ($depth === 0) break;
            }
            if ($depth > 0) {
                if (is_array($token)) {
                    if (in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) continue;
                    $text .= $token[1];
                } else {
                    $text .= $token;
                }
            }
        }
        $arguments[] = $text;
    }
    return $arguments;
}

function epCallIsInsideIfVariable($path, $function, $variable)
{
    list($tokens, $calls) = epNamedFunctionCallIndices($path, $function);
    if (count($calls) !== 1) return null;
    $call = $calls[0];
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_IF) continue;
        $cursor = $index + 1;
        while ($cursor < count($tokens) && $tokens[$cursor] !== '(') $cursor++;
        if ($cursor >= count($tokens)) continue;
        $conditionDepth = 1;
        $mentionsVariable = false;
        for ($cursor++; $cursor < count($tokens) && $conditionDepth > 0; $cursor++) {
            if ($tokens[$cursor] === '(') $conditionDepth++;
            elseif ($tokens[$cursor] === ')') $conditionDepth--;
            elseif (is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_VARIABLE
                && $tokens[$cursor][1] === $variable) $mentionsVariable = true;
        }
        if (!$mentionsVariable) continue;
        while ($cursor < count($tokens) && is_array($tokens[$cursor])
            && in_array($tokens[$cursor][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) $cursor++;
        if ($cursor >= count($tokens) || $tokens[$cursor] !== '{') continue;
        $bodyStart = $cursor;
        $bodyDepth = 1;
        for ($cursor++; $cursor < count($tokens) && $bodyDepth > 0; $cursor++) {
            if ($tokens[$cursor] === '{') $bodyDepth++;
            elseif ($tokens[$cursor] === '}') $bodyDepth--;
        }
        $bodyEnd = $cursor - 1;
        if ($call > $bodyStart && $call < $bodyEnd) return true;
    }
    return false;
}

function epFunctionBodyCalls($path, $function, $called)
{
    $tokens = token_get_all(file_get_contents($path));
    $start = null;
    for ($index = 0; $index < count($tokens); $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) continue;
        for ($cursor = $index + 1; $cursor < count($tokens); $cursor++) {
            if (is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_WHITESPACE) continue;
            if (is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_STRING
                && $tokens[$cursor][1] === $function) $start = $cursor;
            break;
        }
        if ($start !== null) break;
    }
    if ($start === null) return array();
    while ($start < count($tokens) && $tokens[$start] !== '{') $start++;
    if ($start >= count($tokens)) return array();
    $depth = 1;
    $calls = array();
    for ($index = $start + 1; $index < count($tokens) && $depth > 0; $index++) {
        if ($tokens[$index] === '{') $depth++;
        elseif ($tokens[$index] === '}') $depth--;
        elseif (is_array($tokens[$index]) && $tokens[$index][0] === T_STRING
            && $tokens[$index][1] === $called) $calls[] = $called;
    }
    return $calls;
}

function epUnusedTcpPort()
{
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($server === false) {
        throw new RuntimeException('could not reserve an action-test port: ' . $error);
    }
    $address = stream_socket_get_name($server, false);
    fclose($server);
    $separator = strrpos($address, ':');
    if ($separator === false) {
        throw new RuntimeException('action-test port was not present in ' . $address);
    }
    return (int) substr($address, $separator + 1);
}

function epPostAction($port, $body, $username = null)
{
    $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 3);
    if ($socket === false) {
        throw new RuntimeException('could not reach the action-test server: ' . $error);
    }
    stream_set_timeout($socket, 5);
    $request = "POST /plugins/rutracker_check/action.php HTTP/1.0\r\n"
        . "Host: 127.0.0.1\r\n"
        . "Content-Type: application/x-www-form-urlencoded\r\n";
    if($username !== null)
        $request .= 'Authorization: Basic ' . base64_encode($username . ':fixture-password') . "\r\n";
    $request .= 'Content-Length: ' . strlen($body) . "\r\n"
        . "Connection: close\r\n\r\n"
        . $body;
    $written = 0;
    while ($written < strlen($request)) {
        $count = fwrite($socket, substr($request, $written));
        if ($count === false || $count === 0) {
            fclose($socket);
            throw new RuntimeException('could not write the complete action-test request');
        }
        $written += $count;
    }
    $response = stream_get_contents($socket);
    $metadata = stream_get_meta_data($socket);
    fclose($socket);
    if ($metadata['timed_out']) {
        throw new RuntimeException('action-test response timed out');
    }

    $parts = explode("\r\n\r\n", $response, 2);
    if (count($parts) !== 2 || !preg_match('/^HTTP\/1\.[01] ([0-9]{3})/D', strtok($parts[0], "\r\n"), $match)) {
        throw new RuntimeException('malformed action-test response: ' . $response);
    }
    return array(
        'status' => (int) $match[1],
        'headers' => $parts[0],
        'body' => $parts[1],
        'json' => json_decode($parts[1], true),
    );
}

function epWaitForActionInvocations($recordDirectory, $expectedCount, $timeoutSeconds = 3.0)
{
    $completeMarker = "\0\0RUTRACKER_ACTION_COMPLETE\0";
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        clearstatcache();
        $recordPaths = glob($recordDirectory . '/invocation-*');
        sort($recordPaths, SORT_STRING);
        $records = array();
        $allComplete = count($recordPaths) > 0;
        foreach($recordPaths as $recordPath) {
            $raw = file_get_contents($recordPath);
            if($raw === false || substr($raw, -strlen($completeMarker)) !== $completeMarker) {
                $allComplete = false;
                break;
            }
            $argumentBytes = substr($raw, 0, -strlen($completeMarker));
            $arguments = explode("\0", $argumentBytes);
            if(end($arguments) === '') array_pop($arguments);
            $records[] = $arguments;
        }
        if($allComplete && count($records) >= $expectedCount)
            return $records;
        usleep(10000);
    } while(microtime(true) < $deadline);

    throw new RuntimeException('timed out waiting for exactly ' . $expectedCount
        . ' completed fake PHP invocation record(s)');
}

function epWaitForActionServerStop($port, $timeoutSeconds = 1.0)
{
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.05);
        if($probe === false) return;
        fclose($probe);
        usleep(10000);
    } while(microtime(true) < $deadline);

    throw new RuntimeException('action-test server still accepted connections after termination');
}

function epWithActionServer($callback, $dispatchMode = 'refuse')
{
    $root = testFindRepoRoot();
    $fixture = sys_get_temp_dir() . '/rt-action-endpoint-' . getmypid() . '-' . mt_rand();
    $temp = $fixture . '/tmp';
    $profile = $fixture . '/profile';
    $customPath = $fixture . '/custom-path';
    $log = $fixture . '/action-errors.log';
    $recordDirectory = $fixture . '/invocations';
    mkdir($temp, 0700, true);
    mkdir($profile, 0700, true);
    mkdir($customPath, 0700, true);
    mkdir($recordDirectory, 0700, true);

    if($dispatchMode !== 'refuse' && $dispatchMode !== 'accept') {
        strictRemoveTree($fixture);
        throw new InvalidArgumentException('unknown action-test dispatch mode');
    }

    if ($dispatchMode === 'accept') {
        $fakePhpScript = $customPath . '/php';
        $script = "#!/bin/sh\n"
            . 'record=' . escapeshellarg($recordDirectory) . '/invocation-$$' . "\n"
            . "for argument in \"\$@\"; do\n"
            . "    printf '%s\\0' \"\$argument\" >> \"\$record\"\n"
            . "done\n"
            . "printf '\\0RUTRACKER_ACTION_COMPLETE\\0' >> \"\$record\"\n"
            . "exit 0\n";
        file_put_contents($fakePhpScript, $script);
        chmod($fakePhpScript, 0755);
    }

    $port = epUnusedTcpPort();
    $command = 'exec ' . escapeshellarg(PHP_BINARY)
        . ' -d display_errors=1 -d variables_order=EGPCS -S 127.0.0.1:' . $port
        . ' -t ' . escapeshellarg($root);
    $spec = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );
    $environment = getenv();
    $environment['PATH'] = $customPath;
    $environment['RU_TEMP_DIRECTORY'] = $temp;
    $environment['RU_PROFILE_PATH'] = $profile;
    $environment['RU_LOG_FILE'] = $log;
    $process = proc_open($command, $spec, $pipes, EP_DIR, $environment);
    if (!is_resource($process)) {
        strictRemoveTree($fixture);
        throw new RuntimeException('could not start the action-test server');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    try {
        $ready = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($probe !== false) {
                fclose($probe);
                $ready = true;
                break;
            }
            $status = proc_get_status($process);
            if (!$status['running']) break;
            usleep(20000);
        }
        if (!$ready) {
            throw new RuntimeException('action-test server did not start: ' . stream_get_contents($pipes[2]));
        }
        call_user_func($callback, $port, $temp, $log, $recordDirectory);
    } finally {
        proc_terminate($process);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        try {
            epWaitForActionServerStop($port);
        } finally {
            strictRemoveTree($fixture);
        }
    }
}

$suite->test('erasedata and rutracker_check register one identical collector schedule', function () {
    $erasedataInit = dirname(EP_DIR) . '/erasedata/init.php';
    $rutrackerInit = EP_DIR . '/init.php';
    $erasedataCalls = epFunctionCallArguments($erasedataInit, 'erasedataCollectorScheduleCommand');
    $rutrackerCalls = epFunctionCallArguments($rutrackerInit, 'erasedataCollectorScheduleCommand');

    strictAssertSame(array('$theSettings,$garbageCheckInterval'), $erasedataCalls,
        'erasedata registers exactly one collector schedule through the shared helper');
    strictAssertSame($erasedataCalls, $rutrackerCalls,
        'rutracker_check registers the identical key, interval, user, and collector command');
});

$suite->test('cleanup schedule is independent of rutracker update interval', function () {
    $init = EP_DIR . '/init.php';
    $source = file_get_contents($init);
    strictAssertSame(false, epCallIsInsideIfVariable($init, 'erasedataCollectorScheduleCommand', '$updateInterval'),
        'the cleanup schedule is registered even when the hourly checker interval is zero');
    $removeAt = strpos($source, "require( 'done.php' )");
    $cleanupRequestAt = strpos($source, '$req = new rXMLRPCRequest($commands)');
    strictAssertTrue($removeAt !== false,
        'zero checker interval still removes a stale hourly checker schedule');
    strictAssertTrue($cleanupRequestAt !== false && $removeAt < $cleanupRequestAt,
        'the stale checker schedule is removed before the independent cleanup schedule request is sent');
});

$suite->test('targeted kick and scheduled retry execute the same collector entrypoint', function () {
    $helper = dirname(EP_DIR) . '/erasedata/removewithdata.php';
    strictAssertSame(array('erasedataCollectorCommand'),
        epFunctionBodyCalls($helper, 'erasedataKickCollector', 'erasedataCollectorCommand'),
        'the targeted kick delegates shell construction to the shared collector builder');
    strictAssertSame(array('erasedataCollectorCommand'),
        epFunctionBodyCalls($helper, 'erasedataCollectorScheduleCommand', 'erasedataCollectorCommand'),
        'the scheduled retry delegates to that same builder without a second entrypoint');
});

$suite->test('update.php still composes the whole cycle, in the order the cycle depends on', function () {
    $calls = epCalls('update.php');

    foreach (array(
        'RuTrackerState::acquireCycleLock',
        'RuTrackerUpdatePass::sweepReplacements',
        'RuTrackerUpdatePass::parseMulticall',
        'RuTrackerUpdatePass::isTrackerSupported',
        'RuTrackerUpdatePass::pollFeed',
        'RuTrackerUpdatePass::run',
        'RuTrackerUpdatePass::reapOrphans',
        'RuTrackerForumIndex::spawnCrawl',
    ) as $call)
        strictAssertTrue(epAt($calls, $call) >= 0, $call . ' is part of the cycle');

    // The lock is first, or the whole point of it is gone: two cycles three
    // seconds apart is what it was added for.
    strictAssertSame(0, epAt($calls, 'RuTrackerState::acquireCycleLock'),
        'the cycle lock is taken before anything else happens');

    // The stranded-replacement sweep runs BEFORE the pass creates new work:
    // a replacement whose transaction died is stopped and closed, so it is
    // outside the seeding view this pass walks and nothing else would find it.
    epAssertOrder($calls, 'RuTrackerUpdatePass::sweepReplacements', 'RuTrackerUpdatePass::run',
        'unfinished replacements are finished before new ones are started');

    // The feed refreshes topic -> forum before the pass uses it, or every
    // candidate resolved this cycle falls through to a tracker-wide crawl.
    epAssertOrder($calls, 'RuTrackerUpdatePass::pollFeed', 'RuTrackerUpdatePass::run',
        'the forum map is refreshed before the pass consults it');

    // And the crawl is spawned after the pass, since the pass is what queues
    // the topics it has to resolve.
    epAssertOrder($calls, 'RuTrackerUpdatePass::run', 'RuTrackerForumIndex::spawnCrawl',
        'the crawl is spawned only once the pass has queued what it could not resolve');
});

$suite->test('update.php brackets the cycle with a start and a summary line', function () {
    $source = file_get_contents(EP_DIR . '/update.php');
    // A fatal partway through a detached cycle leaves no trace at all: the
    // scheduler's own "sh -c ... &" discards stderr. A start line without its
    // summary is the only evidence that the file died, so both must stay.
    strictAssertTrue(strpos($source, 'update: cycle start') !== false, 'the cycle announces itself');
    strictAssertTrue(strpos($source, 'update: cycle done') !== false, 'and reports its summary');
    strictAssertTrue(strpos($source, 'liveVersionLabel') !== false,
        'the daemon version rides along, since an upgrade changed how a cycle behaves');
    strictAssertTrue(strpos($source, 'update: aborted') !== false,
        'and a failed seeding multicall says so instead of being silent');
});

$suite->test('batch_check.php checks every handed-over hash and then removes the handover file', function () {
    $calls = epCalls('batch_check.php');
    strictAssertTrue(epAt($calls, 'ruTrackerChecker::run') >= 0, 'the click still checks its torrents');
    strictAssertTrue(epAt($calls, 'RuTrackerForumIndex::spawnCrawl') >= 0,
        'and still spawns the crawl: the hourly cycle may never come, the scheduler can be off');
    epAssertOrder($calls, 'ruTrackerChecker::run', 'RuTrackerForumIndex::spawnCrawl',
        'the crawl is spawned after the checks that queue topics for it');

    $source = file_get_contents(EP_DIR . '/batch_check.php');
    strictAssertTrue(strpos($source, "'allowed_classes' => false") !== false
        || strpos($source, "'allowed_classes'=>false") !== false,
        'the handover file is never allowed to build objects');
    strictAssertTrue(epAt($calls, 'RuTrackerBatchDispatch::removeHandover') >= 0,
        'and delegates observable cleanup once read, whatever happened to the checks');
});

$suite->test('forumcrawl.php is the crawl and nothing else', function () {
    $calls = epCalls('forumcrawl.php');
    strictAssertTrue(epAt($calls, 'RuTrackerForumIndex::runCrawl') >= 0, 'it runs the crawl');
    strictAssertTrue(epAt($calls, 'ruTrackerChecker::logDebug') >= 0, 'and logs the outcome');
    epAssertOrder($calls, 'RuTrackerForumIndex::runCrawl', 'ruTrackerChecker::logDebug',
        'the line it logs is the one runCrawl returned');
    // It must NOT take the cycle lock: it is spawned BY a cycle that holds it,
    // so taking it would deadlock the crawl out of existence every time.
    strictAssertSame(-1, epAt($calls, 'RuTrackerState::acquireCycleLock'),
        'the detached crawl never contends for the lock its parent holds');
});

$suite->test('the configured announce budget reaches the budget through its clamps', function () {
    // This round shipped RuTrackerAnnounce::probeCap()/probePause() as DEAD
    // CODE: the helpers existed, their own unit test passed, and layer 2 still
    // handed reserveProbe() and sleep() the raw globals. A helper's unit test
    // cannot see that; the call site is what has to be pinned.
    //
    // The cap has a behavioural test as well (RuTrackerHandlerTest). The pause
    // cannot have one -- asserting it would mean sleeping for the bound -- so
    // this is the only thing standing between it and the same fate.
    $calls = epCalls('trackers/rutracker.php');
    strictAssertTrue(epAt($calls, 'RuTrackerAnnounce::probeCap') >= 0,
        'the configured cap goes through probeCap() before it reaches the budget');
    strictAssertTrue(epAt($calls, 'RuTrackerAnnounce::probePause') >= 0,
        'and the configured pause through probePause() before it reaches sleep()');
    $source = file_get_contents(EP_DIR . '/trackers/rutracker.php');
    strictAssertTrue(strpos($source, '(int) $rutrackerAnnounceCap') === false,
        'the raw configured cap never reaches the budget again');
    strictAssertTrue(strpos($source, '(int) $rutrackerAnnouncePause') === false,
        'nor the raw configured pause');
});

$suite->test('the manual action delegates handover creation and launch to the tested dispatcher', function () {
    $calls = epCalls('action.php');
    $source = file_get_contents(EP_DIR . '/action.php');
    epAssertOrder($calls, 'RuTrackerBatchRequest::parseHashes', 'RuTrackerBatchDispatch::dispatch',
        'the tokenized entrypoint proves request validation completes before dispatch');
    strictAssertTrue(epAt($calls, 'Utility::getPHP') >= 0, 'the checker is still spawned');
    strictAssertTrue(strpos($source, "array( 'FileUtil', 'toLog' )") !== false,
        'observable dispatch failures are wired to the production log sink');
});

$suite->test('the manual action rejects empty or all-invalid body in its 2xx JSON answer', function () {
    epWithActionServer(function ($port, $temp, $log) {
        $emptyResponse = epPostAction($port, 'cmd=check');
        strictAssertSame(200, $emptyResponse['status'],
            'empty selection stays 2xx so the shared callback does not report rTorrent stopped');
        strictAssertSame(array('status' => 'rejected', 'accepted' => 0), $emptyResponse['json'],
            'empty body is rejected with 0 accepted');

        $invalidResponse = epPostAction($port, 'cmd=check&hash=short&hash=123');
        strictAssertSame(200, $invalidResponse['status'],
            'all-invalid selection also stays 2xx');
        strictAssertSame(array('status' => 'rejected', 'accepted' => 0), $invalidResponse['json'],
            'all-invalid body is rejected with 0 accepted');

        strictAssertSame(array(), glob($temp . '/rutorrent-prm-*'), 'no dispatch artifact created on empty/invalid batch');
        strictAssertTrue(!is_file($log), 'no dispatch logged on empty/invalid batch');
    });
});

$suite->test('the manual action reports a refused dispatch without claiming rTorrent is down', function () {
    epWithActionServer(function ($port, $temp, $log) {
        $response = epPostAction($port, 'hash=' . str_repeat('A', 40));

        strictAssertSame(200, $response['status'],
            'a launch refusal stays 2xx so the shared callback does not report rTorrent stopped');
        strictAssertSame(array('status' => 'refused', 'accepted' => 0), $response['json'],
            'the action exposes the failed dispatch as refused');
        strictAssertSame(array(), glob($temp . '/rutorrent-prm-*'),
            'the failed dispatch leaves no child handover behind');
        strictAssertTrue(is_file($log), 'the intentional launch refusal uses the fixture-local log');
        strictAssertTrue(strpos((string) file_get_contents($log),
            'manual batch worker was not accepted by the shell') !== false,
            'the fixture-local log records the intentional launch refusal');
    });
});

$suite->test('the manual action returns queued after one exact detached handover', function () {
    epWithActionServer(function ($port, $temp, $log, $recordDirectory) {
        $h1 = str_repeat('A', 40);
        $h2 = str_repeat('B', 40);
        $body = 'cmd=check&hash=' . strtolower($h1) . '&hash=short&hash=' . $h1 . '&hash=' . $h2;

        $response = epPostAction($port, $body, 'Endpoint User');

        strictAssertSame(200, $response['status'], 'accepted dispatch returns a handled 2xx answer');
        strictAssertSame(array('status' => 'queued', 'accepted' => 2), $response['json'],
            'accepted dispatch returns queued with count of unique valid hashes');

        $handovers = glob($temp . '/rutorrent-prm-*');
        strictAssertSame(1, count($handovers), 'accepted endpoint creates exactly one handover path');
        strictAssertSame(array($h1, $h2),
            unserialize(file_get_contents($handovers[0]), array('allowed_classes' => false)),
            'handover payload contains the exact unique normalized hash set in first-seen order');

        $records = epWaitForActionInvocations($recordDirectory, 1);
        strictAssertSame(1, count($records), 'accepted endpoint launches the detached boundary exactly once');
        strictAssertSame(array(
            '-f',
            realpath(EP_DIR . '/batch_check.php'),
            $handovers[0],
            'endpoint_user',
        ), $records[0], 'fake PHP receives only -f, exact worker, exact handover, and normalized user');
        strictAssertTrue(is_file($handovers[0]),
            'the harmless detached boundary leaves the queued handover untouched for the real worker');
        strictAssertTrue(!is_file($log),
            'the accepted HTTP action queues work without executing XMLRPC inline or logging a worker failure');
    }, 'accept');
});

$suite->test('the manual action rejects MAX_BODY_BYTES plus one in its bounded 2xx answer', function () {
    epWithActionServer(function ($port, $temp, $log) {
        $body = 'hash=' . str_repeat('B', 40) . '&pad=' . str_repeat('x', 262095);
        strictAssertSame(262145, strlen($body), 'the endpoint fixture is exactly 256 KiB plus one byte');

        $response = epPostAction($port, $body);

        strictAssertSame(200, $response['status'],
            'the over-limit refusal stays 2xx so the shared callback does not report rTorrent stopped');
        strictAssertSame(array('status' => 'rejected', 'accepted' => 0), $response['json'],
            'the over-limit response rejects the whole selection');
        strictAssertSame(array(), glob($temp . '/rutorrent-prm-*'),
            'an over-limit body cannot launch or leave a handover');
        strictAssertTrue(is_file($log), 'the over-limit request writes a classified refusal log');
    });
});

$suite->test('the cycle asks for exactly the columns parseMulticall reads, in that order', function () {
    // The fleet scan's schema lives in three places -- the accessor list in
    // update.php, RuTrackerUpdatePass::COLUMNS with parseMulticall's indices,
    // and the test fixture -- bound by nothing but a comment. Shrinking it from
    // nine columns to eight silently broke a fixture that addressed the tracker
    // blob by a fixed index, and the case went on passing under a name that no
    // longer described it. This binds the wire to its reader.
    $source = file_get_contents(EP_DIR . '/update.php');
    $at = strpos($source, 'd.multicall');
    strictAssertTrue($at !== false, 'the cycle still issues the fleet multicall');
    $end = strpos($source, '));', $at);
    $block = substr($source, $at, $end - $at);
    // The embedded t.multicall that builds the tracker blob asks for d.get_hash
    // again; everything from the getCmd("cat") that opens it belongs to the
    // blob, not to the column list.
    $blobAt = strpos($block, 'getCmd("cat")');
    if ($blobAt !== false) $block = substr($block, 0, $blobAt);

    // Every accessor the multicall asks for, in source order: a getCmd() name,
    // plus the custom field where one is concatenated onto d.get_custom=.
    preg_match_all('/getCmd\("([a-z0-9_.=]+)"\)(?:\s*\.\s*"([a-z-]+)")?/i', $block, $m, PREG_SET_ORDER);
    $columns = array();
    foreach ($m as $hit) {
        $name = $hit[0 + 1];
        if (strpos($name, 't.') === 0 || strpos($name, 'cat') === 0) continue;  // inside the blob
        $columns[] = isset($hit[2]) && $hit[2] !== '' ? $hit[2] : rtrim($name, '=');
    }
    $columns[] = 'tracker-blob';   // the embedded t.multicall, always last

    // COLUMNS read out of the reader's source rather than by loading it:
    // updatepass.php pulls in half the plugin, and this suite deliberately
    // executes none of it.
    preg_match('/const COLUMNS = (\\d+);/', file_get_contents(EP_DIR . '/updatepass.php'), $c);
    strictAssertTrue(isset($c[1]), 'the reader still declares its column count');
    strictAssertSame((int) $c[1], count($columns),
        'the wire asks for exactly as many columns as parseMulticall reads: ' . implode(', ', $columns));
    strictAssertSame(
        array('d.get_hash', 'chk-state', 'chk-time', 'd.get_custom1', 'd.get_message',
              'chk-del', 'chk-msg', 'tracker-blob'),
        $columns,
        'and in the order parseMulticall indexes them');
});

require_once(EP_DIR . '/batch_check.php');

$suite->test('the default detached launcher refuses a PHP executable that cannot be resolved', function () {
    strictAssertSame(
        true,
        strictInvoke('RuTrackerDetachedPhp', 'executableExists', array(PHP_BINARY)),
        'an executable supplied as a path resolves directly'
    );

    $savedPath = getenv('PATH');
    try {
        putenv('PATH=' . dirname(PHP_BINARY));
        strictAssertSame(
            true,
            strictInvoke('RuTrackerDetachedPhp', 'executableExists', array(basename(PHP_BINARY))),
            'a bare executable resolves by scanning PATH without a shell'
        );
        strictAssertSame(
            false,
            strictInvoke('RuTrackerDetachedPhp', 'executableExists', array(
                'rutorrent-php-definitely-missing-' . getmypid()
            )),
            'a missing bare executable is not guessed from shell behavior'
        );
    } finally {
        if ($savedPath === false) putenv('PATH');
        else putenv('PATH=' . $savedPath);
    }

    $notExecutable = tempnam(sys_get_temp_dir(), 'rt-php-noexec-');
    file_put_contents($notExecutable, "#!/bin/sh\nexit 0\n");
    chmod($notExecutable, 0600);
    clearstatcache(true, $notExecutable);
    try {
        strictAssertSame(
            false,
            strictInvoke('RuTrackerDetachedPhp', 'executableExists', array($notExecutable)),
            'an existing file without an executable bit is refused'
        );
    } finally {
        @unlink($notExecutable);
    }

    strictAssertSame(
        false,
        RuTrackerDetachedPhp::launch(
            '/definitely/missing/rutorrent-php-' . getmypid(),
            EP_DIR . '/batch_check.php'
        ),
        'a known-missing executable is rejected before an asynchronous shell can hide command-not-found'
    );
});

$suite->test('the default detached launcher refuses a missing PHP script', function () {
    strictAssertSame(
        false,
        RuTrackerDetachedPhp::launch(
            PHP_BINARY,
            '/definitely/missing/rutorrent-script-' . getmypid() . '.php'
        ),
        'a known-missing script is rejected before the shell accepts a child that cannot run it'
    );
    strictAssertSame(
        false,
        RuTrackerDetachedPhp::launch(PHP_BINARY, EP_DIR),
        'a readable directory is not accepted in place of a regular PHP script'
    );

    $readableScript = tempnam(sys_get_temp_dir(), 'rt-readable-script-');
    file_put_contents($readableScript, "<?php exit(0);\n");
    chmod($readableScript, 0600);
    clearstatcache(true, $readableScript);
    try {
        strictAssertSame(
            true,
            RuTrackerDetachedPhp::launch(PHP_BINARY, $readableScript),
            'a readable regular script reaches the documented shell-acceptance step'
        );
    } finally {
        @unlink($readableScript);
    }
});

$suite->test('manual batch dispatch quotes every detached PHP component and leaves one handover for the child', function () {
    $tmpDir = sys_get_temp_dir() . '/rt-manual-dispatch-space-' . getmypid() . '-' . mt_rand();
    mkdir($tmpDir, 0700, true);
    $php = "/opt/PHP tools/php's";
    $script = "/opt/ruTorrent plugins/rutracker_check/batch_check.php";
    $user = 'User Name';
    $hashes = array('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
    $commands = array();

    try {
        $accepted = RuTrackerBatchDispatch::dispatch(
            $hashes,
            $tmpDir,
            $php,
            $script,
            $user,
            function ($command) use (&$commands) {
                $commands[] = $command;
                return true;
            }
        );

        strictAssertSame(true, $accepted, 'the injected shell acceptance result is returned');
        strictAssertSame(1, count($commands), 'the detached command is attempted once');
        $handovers = glob($tmpDir . '/rutorrent-prm-*');
        strictAssertSame(1, count($handovers), 'one unpredictable handover is left for the accepted child');
        strictAssertSame($hashes, unserialize(file_get_contents($handovers[0]), array('allowed_classes' => false)),
            'the handover contains exactly the selected hashes');
        $expected = escapeshellarg($php) . ' -f ' . escapeshellarg($script)
            . ' ' . escapeshellarg($handovers[0]) . ' ' . escapeshellarg($user)
            . ' > /dev/null 2>&1 &';
        strictAssertSame($expected, $commands[0], 'PHP, script, handover and user are all shell-quoted');
    } finally {
        strictRemoveTree($tmpDir);
    }
});

$suite->test('manual batch dispatch removes its handover and logs safely when the shell refuses it', function () {
    $tmpDir = sys_get_temp_dir() . '/rt-manual-dispatch-refused-' . getmypid() . '-' . mt_rand();
    mkdir($tmpDir, 0700, true);
    $logs = array();
    $hash = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

    try {
        $accepted = RuTrackerBatchDispatch::dispatch(
            array($hash),
            $tmpDir,
            '/usr/bin/php',
            '/opt/rutorrent/batch_check.php',
            'user',
            function ($command) { return false; },
            function ($message) use (&$logs) { $logs[] = $message; }
        );

        strictAssertSame(false, $accepted, 'a shell refusal is returned to the web action');
        strictAssertSame(array(), glob($tmpDir . '/rutorrent-prm-*'),
            'the rejected child cannot consume the handover, so the parent removes it');
        strictAssertSame(1, count($logs), 'the refusal emits one actionable diagnostic');
        strictAssertTrue(strpos($logs[0], 'manual batch worker was not accepted by the shell') !== false,
            'the diagnostic names shell acceptance');
        strictAssertTrue(strpos($logs[0], $hash) === false,
            'the diagnostic contains neither selected hashes nor serialized payloads');
    } finally {
        strictRemoveTree($tmpDir);
    }
});

$suite->test('runHandover checks all hashes, tolerates thrown exceptions, and removes handover file in finally', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'rt-batch-test-');
    $hashes = array('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB', 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC');
    file_put_contents($tmp, serialize($hashes));

    $checked = array();
    $crawlerRan = false;
    ruTrackerBatchCheck::runHandover(
        $tmp,
        function ($hash) use (&$checked) {
            $checked[] = $hash;
            if ($hash === 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB') {
                throw new RuntimeException('simulated check failure');
            }
        },
        function () use (&$crawlerRan) {
            $crawlerRan = true;
        }
    );

    strictAssertSame($hashes, $checked, 'all hashes are processed even if an earlier hash threw');
    strictAssertTrue($crawlerRan, 'crawl is spawned in finally even when a check threw');
    strictAssertTrue(!file_exists($tmp), 'handover file is deleted in finally');
});

$suite->test('runHandover handles invalid or missing handover files safely', function () {
    $crawlerRan = false;
    ruTrackerBatchCheck::runHandover(
        '/nonexistent/path/for/handover',
        function ($hash) {
            throw new RuntimeException('should not be called');
        },
        function () use (&$crawlerRan) {
            $crawlerRan = true;
        }
    );
    strictAssertTrue($crawlerRan, 'crawler still runs even if handover file is nonexistent');

    $tmp = tempnam(sys_get_temp_dir(), 'rt-batch-test-');
    file_put_contents($tmp, 'not-serialized-data');
    $crawlerRan = false;
    ruTrackerBatchCheck::runHandover(
        $tmp,
        function ($hash) {
            throw new RuntimeException('should not be called');
        },
        function () use (&$crawlerRan) {
            $crawlerRan = true;
        }
    );
    strictAssertTrue($crawlerRan, 'crawler still runs on corrupt handover');
    strictAssertTrue(!file_exists($tmp), 'corrupt handover file is unlinked');
});

$suite->test('runHandover reports a failed handover unlink and still attempts the crawl', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'rt-batch-unlink-fail-');
    file_put_contents($tmp, serialize(array('CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC')));
    $logs = array();
    $crawlerRan = false;

    try {
        ruTrackerBatchCheck::runHandover(
            $tmp,
            function ($hash) {},
            function () use (&$crawlerRan) { $crawlerRan = true; },
            function ($path) { return false; },
            function ($message) use (&$logs) { $logs[] = $message; }
        );

        strictAssertTrue($crawlerRan, 'cleanup failure does not suppress the final crawl attempt');
        strictAssertTrue(file_exists($tmp), 'the injected failed unlink leaves the handover in place');
        strictAssertSame(1, count($logs), 'failed cleanup is visible exactly once');
        strictAssertTrue(strpos($logs[0], 'could not remove the handover file') !== false,
            'the diagnostic identifies cleanup rather than claiming it succeeded');
        strictAssertTrue(strpos($logs[0], 'CCCC') === false,
            'the diagnostic does not include hashes or serialized handover contents');
    } finally {
        @unlink($tmp);
    }
});

$suite->test('RuTrackerBatchRequest::parseHashes parses, normalizes, deduplicates, and bounds repeated hash parameters', function () {
    $h1 = str_repeat('A', 40);
    $h2 = str_repeat('B', 40);
    $h3 = str_repeat('C', 40);

    // Basic ordering, uppercase conversion, deduplication, extra fields ignored
    $body = 'hash=' . strtolower($h1) . '&other=123&hash=' . $h2 . '&hash=' . $h1 . '&hash=' . $h3;
    $res = RuTrackerBatchRequest::parseHashes($body);
    strictAssertSame(array($h1, $h2, $h3), $res, 'preserves first-seen order, converts to uppercase, dedupes');

    // Percent-encoded keys and values
    $bodyEncoded = 'hash=' . rawurlencode(strtolower($h1)) . '&%68%61%73%68=' . rawurlencode($h2);
    strictAssertSame(array($h1, $h2), RuTrackerBatchRequest::parseHashes($bodyEncoded), 'decodes url-encoded keys and values');

    // Malformed segments ignored without warnings
    $bodyMalformed = 'bare_hash&hash&hash=&hash=short&hash=' . str_repeat('Z', 40) . '&hash[]=123&hash=' . $h1;
    strictAssertSame(array($h1), RuTrackerBatchRequest::parseHashes($bodyMalformed), 'ignores malformed segments without warning');

    // The contract is exact 40-hex after URL decoding: surrounding whitespace
    // is malformed input, not formatting that the request parser may repair.
    $bodyWhitespace = 'hash=%20' . $h1 . '&hash=' . $h2 . '%20&hash=' . $h3;
    strictAssertSame(array($h3), RuTrackerBatchRequest::parseHashes($bodyWhitespace),
        'rejects hash values with leading or trailing whitespace');

    // Oversized body rejected (> 256 KiB)
    $error = null;
    $hugeBody = str_repeat('x', 262145);
    strictAssertSame(array(), RuTrackerBatchRequest::parseHashes($hugeBody, $error), 'oversized body rejected');
    strictAssertTrue($error !== null, 'error set on oversized body');

    // Exactly 256 KiB is admitted
    $bodyAllowed = 'hash=' . $h1 . '&pad=' . str_repeat('x', 262144 - 45 - 5);
    strictAssertSame(262144, strlen($bodyAllowed), 'boundary fixture is exactly 256 KiB');
    strictAssertSame(array($h1), RuTrackerBatchRequest::parseHashes($bodyAllowed), 'body of exactly 256 KiB admitted');

    // The byte bound is the single selection limit. Do not add a second,
    // arbitrary item-count refusal below it: 4097 valid hashes still fit.
    $hashes4096 = array();
    $bodySegments = array();
    for ($i = 0; $i < 4096; $i++) {
        $h = sprintf('%040X', $i);
        $hashes4096[] = $h;
        $bodySegments[] = 'hash=' . $h;
    }
    $body4096 = implode('&', $bodySegments);
    strictAssertSame($hashes4096, RuTrackerBatchRequest::parseHashes($body4096), 'accepts exactly 4096 unique hashes');

    // The 4097th unique hash is admitted because the body is still below the
    // documented 256 KiB boundary.
    $body4097 = $body4096 . '&hash=' . sprintf('%040X', 4096);
    $error4097 = null;
    $hashes4097 = $hashes4096;
    $hashes4097[] = sprintf('%040X', 4096);
    strictAssertSame($hashes4097, RuTrackerBatchRequest::parseHashes($body4097, $error4097),
        '4097th unique hash is accepted while the request remains below the byte bound');
    strictAssertSame(null, $error4097, 'no second item-count limit rejects a bounded body');

    // Non-string body
    strictAssertSame(array(), RuTrackerBatchRequest::parseHashes(null), 'null body returns empty');
    strictAssertSame(array(), RuTrackerBatchRequest::parseHashes(array()), 'array body returns empty');
});

exit($suite->run());
