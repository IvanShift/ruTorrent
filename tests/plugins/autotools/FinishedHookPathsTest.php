<?php

// The child runs the actual hook and XMLRPC reader; only its SCGI peer differs.
if (isset($argv[1]) && $argv[1] === '--selected-paths-child') {
    $root = $argv[2];
    $mode = $argv[3];
    $single = strpos($mode, '-single') !== false;
    $mode = str_replace('-single', '', $mode);
    $operation = $argv[4];
    $repo = dirname(__DIR__, 3);
    $_SERVER['REMOTE_USER'] = 'finishedpaths';
    $_ENV['RU_PROFILE_PATH'] = $root . '/profile';
    $_ENV['RU_LOG_FILE'] = $root . '/errors.log';
    chdir($repo . '/plugins/autotools');
    require_once $repo . '/conf/config.php';
    require_once $repo . '/php/settings.php';
    $reflection = new ReflectionClass('rTorrentSettings');
    $settings = $reflection->newInstanceWithoutConstructor();
    $settings->iVersion = 0x101A;
    $settings->aliases = array();
    $settings->linkExist = true;
    $settings->directory = $root . '/dl';
    $settings->session = $root . '/session';
    (function () use ($repo) {
        foreach (array('0.9.4', '0.10.2', '0.16.0', '0.16.16', '0.16.18') as $version)
            require $repo . '/php/methods-' . $version . '.php';
    })->call($settings);
    $instance = $reflection->getProperty('theSettings');
    $instance->setAccessible(true);
    $instance->setValue(null, $settings);
    $scgi_host = 'unix://' . $root . '/rpc.sock';
    $scgi_port = 0;
    $rpcTimeOut = 1;
    $rpcLogCalls = false;
    require_once $repo . '/plugins/autotools/hook_lib.php';
    $at = new rAutoTools();
    $at->enable_move = 1;
    $at->fileop_type = $operation;
    $at->automove_filter = '/.*/';
    $at->skip_move_for_files = $argv[5];
    $at->addLabel = 0;
    $at->addName = 0;
    $at->path_to_finished = $root . '/fin/pending';
    if (!$at->store()) throw new RuntimeException('could not store the hook fixture settings');
    $hash = str_repeat('A', 40);
    $args = array($hash, $root . '/dl/Root', 'Root', '1', '', 'Root', 'finishedpaths', str_repeat('b', 32));
    if ($single) {
        $args[1] = $root . '/dl/payload.bin'; $args[2] = $args[5] = 'payload.bin'; $args[3] = '0';
        rename($root . '/session/' . $hash . '.torrent', $root . '/session/held.torrent');
    }
    if ($mode === 'perform') {
        $result = AutoToolsFinishedHook::perform($args, false, true);
    } else {
        $result = array('destination' => AutoToolsFinishedHook::hook($args));
        if ($single) rename($root . '/session/held.torrent', $root . '/session/' . $hash . '.torrent');
        if ($mode === 'expired') {
            foreach (erasedataDirectoryMatches($root . '/session/.autotools-file-jobs', '', AutoToolsFinishedHook::REQUEST_SUFFIX) as $path) {
                $request = JSON::decodeBytes(file_get_contents($path));
                $request['at'] = time() - (int) ceil(rtFileJobRetryWindow()) - 1;
                file_put_contents($path, JSON::encodeBytes($request));
            }
        }
        AutoToolsFinishedHook::performDeferred();
    }
    $journal = $root . '/session/.autotools-file-jobs';
    $result['pending'] = is_dir($journal)
        ? count(erasedataDirectoryMatches($journal, '', AutoToolsFinishedHook::REQUEST_SUFFIX)) : 0;
    $result['log'] = is_file($root . '/errors.log') ? file_get_contents($root . '/errors.log') : '';
    echo json_encode($result);
    exit(0);
}

require_once __DIR__ . '/../rutracker_check/TestLib.php';
require_once __DIR__ . '/../../php/ScgiToolPeer.php';

function finishedPathFixture($selected = 'Серия.ass')
{
    $root = sys_get_temp_dir() . '/fp-' . bin2hex(random_bytes(5));
    foreach (array('', '/session', '/dl', '/dl/Root', '/fin') as $directory)
        if (!mkdir($root . $directory, 0700)) throw new RuntimeException('fixture directory unavailable');
    file_put_contents($root . '/dl/Root/' . $selected, 'payload bytes');
    $raw = "\xD1\xE5\xF0\xE8\xFF.ass";
    file_put_contents($root . '/session/' . str_repeat('A', 40) . '.torrent',
        'd4:infod5:filesld6:lengthi13e4:pathl' . strlen($raw) . ':' . $raw
        . 'e10:path.utf-8l' . strlen($selected) . ':' . $selected . 'ee'
        . 'e4:name4:Root12:piece lengthi16384e6:pieces20:' . str_repeat("\x01", 20) . 'ee');
    return $root;
}

function finishedPathReply($xml)
{
    return "Status: 200 OK\r\nContent-Type: text/xml\r\nContent-Length: " . strlen($xml) . "\r\n\r\n" . $xml;
}

function finishedPathRun($root, $selected, $mode = 'perform', $operation = 'Copy', $shape = 'valid', $filter = '')
{
    return ScgiToolPeer::run($root . '/rpc.sock', __FILE__,
        array('--selected-paths-child', $root, $mode, $operation, $filter),
        function ($request) use ($root, $selected, $shape, $mode) {
            $method = ScgiToolPeer::method($request);
            if ($method === 'system.multicall' && strpos($request, '<string>d.base_path</string>') !== false) {
                if ($shape === 'context-lost' || $shape === 'lost') return null;
                if ($shape === 'fault') return finishedPathReply('<?xml version="1.0"?><methodResponse><fault><value><struct>'
                    . '<member><name>faultCode</name><value><int>-500</int></value></member>'
                    . '<member><name>faultString</name><value><string>source snapshot unavailable</string></value></member>'
                    . '</struct></value></fault></methodResponse>');
                $single = strpos($mode, '-single') !== false;
                $base = $root . '/dl/' . ($single ? 'payload.bin' : 'Root');
                if ($shape === 'stale-root') $base = $root . '/dl/' . ($single ? 'Current.bin' : 'Current');
                $values = array('<base64>' . base64_encode($base) . '</base64>',
                    '<i8>' . ($shape === 'bad-flag-value' ? '2' : ($shape === 'stale-type' ? '0' : ($single ? '0' : '1'))) . '</i8>',
                    '<string>' . ($shape === 'stale-basename' ? 'Other' : ($single ? 'payload.bin' : 'Root')) . '</string>',
                    '<string>' . str_repeat($shape === 'stale-token' ? 'c' : 'b', 32) . '</string>');
                if ($shape === 'cold-frozen' || $shape === 'cold-stored-directory-changed') $values[0] = $values[2] = '<string></string>';
                if ($shape === 'context-malformed')
                    $values[0] = '<array><data><value>' . $values[0] . '</value></data></array>';
                if (strpos($request, '<string>d.directory</string>') !== false) {
                    $directory = $single ? dirname($base) : $base;
                    if ($shape === 'stored-directory-changed' || $shape === 'cold-stored-directory-changed') $directory = $root . '/dl/Current';
                    elseif ($shape === 'stored-directory-space') $directory .= ' ';
                    elseif ($shape === 'stored-directory-tail') $directory .= '///';
                    $stored = '<base64>' . base64_encode($directory) . '</base64>';
                    if ($shape === 'stored-directory-malformed') $stored = '<array><data><value>' . $stored . '</value></data></array>';
                    $values[] = $stored;
                }
                $actual = $single ? ($shape === 'single-path-changed' ? 'Other.bin' : 'payload.bin') : $selected;
                $cell = $shape === 'integer' ? '<i8>1</i8>' : '<base64>' . base64_encode($actual) . '</base64>';
                $row = '<value><array><data><value>' . $cell . '</value></data></array></value>';
                $values[] = '<array><data>' . $row . ($shape === 'extra' ? $row : '') . '</data></array>';
                if ($shape === 'context-short') array_pop($values);
                $rows = '';
                foreach ($values as $value) $rows .= '<value><array><data><value>' . $value . '</value></data></array></value>';
                return ScgiToolPeer::scgiAnswer('<array><data>' . $rows . '</data></array>');
            }
            if ($method === 'f.multicall') {
                if ($shape === 'lost') return null;
                if ($shape === 'fault') return finishedPathReply('<?xml version="1.0"?><methodResponse><fault><value><struct>'
                    . '<member><name>faultCode</name><value><int>-500</int></value></member>'
                    . '<member><name>faultString</name><value><string>file list unavailable</string></value></member>'
                    . '</struct></value></fault></methodResponse>');
                $cell = $shape === 'integer' ? '<i8>1</i8>' : '<base64>' . base64_encode($selected) . '</base64>';
                $row = '<value><array><data><value>' . $cell . '</value></data></array></value>';
                return ScgiToolPeer::scgiAnswer('<array><data>' . $row
                    . ($shape === 'extra' ? $row : '') . '</data></array>');
            }
            if ($method === 'd.directory') {
                $directory = $root . (strpos($mode, '-single') !== false ? '/dl' : '/dl/Root');
                if (in_array($shape, array('stale-root', 'stored-directory-changed', 'cold-stored-directory-changed'), true))
                    $directory = $root . '/dl/Current';
                elseif ($shape === 'stored-directory-space') $directory .= ' ';
                return ScgiToolPeer::scgiAnswer('<string>' . htmlspecialchars($directory, ENT_XML1) . '</string>');
            }
            if ($method === 'system.multicall')
                return ScgiToolPeer::scgiAnswer('<array><data><value><array><data><value><string>0</string></value></data></array></value>'
                    . '<value><array><data><value><string>0</string></value></data></array></value></data></array>');
            return ScgiToolPeer::scgiAnswer('<string>0</string>');
        }, array(), false);
}

$suite = new StrictTestSuite();
foreach (array('stored-directory-changed', 'stored-directory-space', 'cold-stored-directory-changed') as $shape)
    $suite->test('a changed stored directory ' . $shape . ' refuses an unchanged frozen source', function () use ($shape) {
        $root = finishedPathFixture();
        try {
            file_put_contents($root . '/dl/Root/Серия.ass', 'FOREIGN old payload');
            list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'deferred', 'Copy', $shape);
            strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
            $result = json_decode($output, true);
            strictAssertSame(false, file_exists($root . '/fin/pending'), 'the unchanged frozen path cannot authorize copying the old root');
            strictAssertSame(0, $result['pending'], 'the changed directory is a visible final refusal');
            strictAssertTrue(strpos($result['log'], 'source-context-changed') !== false, 'the refusal names the changed context');
            strictAssertSame('FOREIGN old payload', file_get_contents($root . '/dl/Root/Серия.ass'), 'the old root is untouched');
        } finally { strictRemoveTree($root); }
    });

$suite->test('a changed single-file stored directory refuses unchanged frozen path and name', function () {
    $root = finishedPathFixture();
    try {
        file_put_contents($root . '/dl/payload.bin', 'FOREIGN old payload');
        file_put_contents($root . '/session/' . str_repeat('A', 40) . '.torrent',
            'd4:infod6:lengthi13e4:name11:payload.bin12:piece lengthi16384e6:pieces20:' . str_repeat("\x01", 20) . 'ee');
        list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'deferred-single', 'Copy', 'stored-directory-changed');
        strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
        $result = json_decode($output, true);
        strictAssertSame(false, file_exists($root . '/fin/pending'), 'the old single-file path cannot authorize copying');
        strictAssertSame(0, $result['pending'], 'the changed stored directory is a visible refusal');
        strictAssertTrue(strpos($result['log'], 'source-context-changed') !== false, 'the changed context is named');
    } finally { strictRemoveTree($root); }
});

$suite->test('equivalent directory tail slashes retain the captured source identity', function () {
    $root = finishedPathFixture();
    try {
        list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'perform', 'Copy', 'stored-directory-tail');
        strictAssertSame(0, $exit, 'the real hook returns: ' . $error);
        strictAssertSame('published', json_decode($output, true)['status'], 'only separator tails are canonicalized');
    } finally { strictRemoveTree($root); }
});

foreach (array('stored-directory-malformed', 'bad-flag-value') as $shape)
    $suite->test('an unknown directory snapshot ' . $shape . ' remains pending', function () use ($shape) {
        $root = finishedPathFixture();
        try {
            list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'deferred', 'Copy', $shape);
            strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
            strictAssertSame(1, json_decode($output, true)['pending'], 'unknown fields are not a proven source change');
            strictAssertSame(false, file_exists($root . '/fin/pending'), 'unknown fields cannot authorize copying');
        } finally { strictRemoveTree($root); }
    });

$suite->test('a cold single-file object retains its request until its captured frozen path can be acknowledged', function () {
    $root = finishedPathFixture();
    try {
        file_put_contents($root . '/dl/payload.bin', 'payload bytes');
        file_put_contents($root . '/session/' . str_repeat('A', 40) . '.torrent',
            'd4:infod6:lengthi13e4:name11:payload.bin12:piece lengthi16384e6:pieces20:' . str_repeat("\x01", 20) . 'ee');
        list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'deferred-single', 'Copy', 'cold-frozen');
        strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
        strictAssertSame(1, json_decode($output, true)['pending'], 'the request remains owned while acknowledgement is unprovable');
        strictAssertSame(false, file_exists($root . '/fin/pending'), 'the request is retained before any copy');
    } finally { strictRemoveTree($root); }
});

$suite->test('a cold multi-file object retains its request until its captured frozen path can be acknowledged', function () {
    $root = finishedPathFixture();
    try {
        list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'deferred', 'Copy', 'cold-frozen');
        strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
        strictAssertSame(1, json_decode($output, true)['pending'], 'the unconfirmed acknowledgement keeps its request');
        strictAssertSame(false, file_exists($root . '/fin/pending'), 'nothing is copied before acknowledgement can be proved');
    } finally { strictRemoveTree($root); }
});

$suite->test('a cold source still unavailable after its retry window is refused without creating a receipt', function () {
    $root = finishedPathFixture();
    try {
        list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'expired', 'Copy', 'cold-frozen');
        strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
        $result = json_decode($output, true);
        strictAssertSame(0, $result['pending'], 'the retry window ends with a visible refusal');
        strictAssertTrue(strpos($result['log'], 'source-context-unconfirmed') !== false, 'the unconfirmed source is named');
        strictAssertSame(false, file_exists($root . '/fin/pending'), 'no unacknowledgeable copy is made');
        strictAssertSame(array(), glob($root . '/session/.autotools-file-jobs/*.nonmove.json'), 'no file-operation receipt is stranded');
    } finally { strictRemoveTree($root); }
});

$suite->test('a single-file object refuses a changed live filename before copying', function () {
    $root = finishedPathFixture();
    try {
        file_put_contents($root . '/dl/payload.bin', 'FOREIGN old payload');
        file_put_contents($root . '/session/' . str_repeat('A', 40) . '.torrent',
            'd4:infod6:lengthi13e4:name11:payload.bin12:piece lengthi16384e6:pieces20:' . str_repeat("\x01", 20) . 'ee');
        list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'deferred-single', 'Copy', 'single-path-changed');
        strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
        $result = json_decode($output, true);
        strictAssertSame(0, $result['pending'], 'a proven different live path retires the stale request');
        strictAssertTrue(strpos($result['log'], 'source-context-changed') !== false, 'the live path change is named');
        strictAssertSame(false, file_exists($root . '/fin/pending'), 'the old frozen path cannot authorize copying a different live file');
    } finally { strictRemoveTree($root); }
});

foreach (array('stale-root', 'stale-token', 'stale-type', 'stale-basename') as $shape)
    $suite->test('a deferred hook refuses ' . $shape . ' before copying a captured source', function () use ($shape) {
        $root = finishedPathFixture();
        try {
            mkdir($root . '/dl/Current', 0700);
            file_put_contents($root . '/dl/Current/Серия.ass', 'CURRENT live payload');
            file_put_contents($root . '/dl/Root/Серия.ass', 'FOREIGN old payload');
            list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'deferred', 'Copy', $shape);
            strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
            $result = json_decode($output, true);
            strictAssertSame(false, file_exists($root . '/fin/pending'), 'stale captured context cannot create a destination');
            strictAssertSame(0, $result['pending'], 'the superseded request retires visibly');
            strictAssertTrue(strpos($result['log'], 'source-context-changed') !== false, 'the refusal names the stale capture');
            strictAssertSame('FOREIGN old payload', file_get_contents($root . '/dl/Root/Серия.ass'), 'the old root is untouched');
            strictAssertSame('CURRENT live payload', file_get_contents($root . '/dl/Current/Серия.ass'), 'the current root is untouched');
        } finally { strictRemoveTree($root); }
    });

$suite->test('a deferred single-file hook refuses a changed source root before copying', function () {
    $root = finishedPathFixture();
    try {
        file_put_contents($root . '/dl/payload.bin', 'FOREIGN old payload');
        file_put_contents($root . '/dl/Current.bin', 'CURRENT live payload');
        file_put_contents($root . '/session/' . str_repeat('A', 40) . '.torrent',
            'd4:infod6:lengthi13e4:name11:payload.bin12:piece lengthi16384e6:pieces20:' . str_repeat("\x01", 20) . 'ee');
        list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'deferred-single', 'Copy', 'stale-root');
        strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
        $result = json_decode($output, true);
        strictAssertSame(false, file_exists($root . '/fin/pending'), 'a stale single-file request cannot create a destination');
        strictAssertSame(0, $result['pending'], 'the superseded request retires visibly');
        strictAssertTrue(strpos($result['log'], 'source-context-changed') !== false, 'the refusal names the stale capture');
        strictAssertSame('FOREIGN old payload', file_get_contents($root . '/dl/payload.bin'), 'the old file is untouched');
    } finally { strictRemoveTree($root); }
});

foreach (array('context-lost', 'context-short', 'context-malformed') as $shape)
    $suite->test('an unconfirmed source snapshot ' . $shape . ' retains its deferred request', function () use ($shape) {
        $root = finishedPathFixture();
        try {
            list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'deferred', 'Copy', $shape);
            strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
            strictAssertSame(1, json_decode($output, true)['pending'], 'an unknown context stays retryable');
            strictAssertSame(false, file_exists($root . '/fin/pending'), 'unknown context cannot create a destination');
        } finally { strictRemoveTree($root); }
    });

foreach (array('Copy', 'HardLink', 'SoftLink') as $operation)
    $suite->test('background ' . $operation . ' uses selected UTF-8 paths through the real XMLRPC reader', function () use ($operation) {
        $root = finishedPathFixture();
        try {
            list($exit, $output, $error, $requests, $methods) = finishedPathRun($root, 'Серия.ass', 'perform', $operation);
            strictAssertSame(0, $exit, 'the real hook child succeeds: ' . $error);
            strictAssertSame('published', json_decode($output, true)['status'], 'selected-path operation publishes');
            strictAssertSame('payload bytes', file_get_contents($root . '/fin/pending/Root/Серия.ass'), 'the actual selected path is used');
            strictAssertSame('payload bytes', file_get_contents($root . '/dl/Root/Серия.ass'), 'source bytes are retained');
            strictAssertSame(array('system.multicall'), $methods, 'source context and paths share one read-only callback');
            strictAssertTrue(strpos($requests[0], '<string>f.path=</string>') !== false, 'the shared alias factory builds the native getter');
        } finally { strictRemoveTree($root); }
    });

$suite->test('a selected UTF-8 skip filter runs before destination creation', function () {
    $root = finishedPathFixture();
    try {
        list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'perform', 'Copy', 'valid', '/Серия/');
        strictAssertSame(0, $exit, 'the real hook child succeeds: ' . $error);
        strictAssertSame('none', json_decode($output, true)['status'], 'the filter matches the selected path');
        strictAssertSame(false, file_exists($root . '/fin/pending'), 'a skipped alias creates no destination');
    } finally { strictRemoveTree($root); }
});

$suite->test('recovery publishes a selected-path hook through its existing deferred request', function () {
    $root = finishedPathFixture();
    try {
        list($exit, $output, $error, , $methods) = finishedPathRun($root, 'Серия.ass', 'deferred');
        strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
        strictAssertSame(0, json_decode($output, true)['pending'], 'successful recovery retires its request');
        strictAssertSame('payload bytes', file_get_contents($root . '/fin/pending/Root/Серия.ass'), 'the selected path is copied');
        strictAssertSame(1, count(array_filter($methods, function ($method) { return $method === 'system.multicall'; })),
            'background recovery resolves the paths once');
    } finally { strictRemoveTree($root); }
});

foreach (array('lost', 'fault') as $shape)
    $suite->test('deferred path read ' . $shape . ' stays pending inside its retry window', function () use ($shape) {
        $root = finishedPathFixture();
        try {
            list($exit, $output, $error, , $methods) = finishedPathRun($root, 'Серия.ass', 'deferred', 'Copy', $shape);
            strictAssertSame(0, $exit, 'the real hook child returns: ' . $error);
            strictAssertSame(1, json_decode($output, true)['pending'], 'an unavailable daemon reply does not retire the request');
            strictAssertSame(array('system.multicall'), $methods, 'only background recovery reads the source snapshot');
            strictAssertSame(false, file_exists($root . '/fin/pending'), 'unknown paths create no destination');
        } finally { strictRemoveTree($root); }
    });

foreach (array('integer', 'extra') as $shape)
    $suite->test('malformed selected-path ' . $shape . ' reply refuses before filesystem work', function () use ($shape) {
        $root = finishedPathFixture();
        try {
            list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'perform', 'Copy', $shape);
            strictAssertSame(0, $exit, 'the real hook child returns: ' . $error);
            $result = json_decode($output, true);
            strictAssertSame(array('refused', 'file-list-invalid'), array($result['status'], $result['reason']), 'malformed rows are a visible classified refusal');
            strictAssertSame(false, file_exists($root . '/fin/pending'), 'unproven list creates no destination');
            strictAssertSame('payload bytes', file_get_contents($root . '/dl/Root/Серия.ass'), 'source bytes remain untouched');
        } finally { strictRemoveTree($root); }
    });

$suite->test('a malformed deferred path reply retires with a visible classified refusal', function () {
    $root = finishedPathFixture();
    try {
        list($exit, $output, $error, , $methods) = finishedPathRun($root, 'Серия.ass', 'deferred', 'Copy', 'integer');
        strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
        $result = json_decode($output, true);
        strictAssertSame(0, $result['pending'], 'a proven malformed reply is a final refusal');
        strictAssertTrue(strpos($result['log'], 'refused: file-list-invalid') !== false, 'the malformed reply is logged');
        strictAssertTrue(in_array('system.multicall', $methods, true), 'the refusal is named and kept on the torrent');
        strictAssertSame(false, file_exists($root . '/fin/pending'), 'refusal creates no destination');
    } finally { strictRemoveTree($root); }
});

$suite->test('a deferred path read that never becomes available retires with a durable refusal', function () {
    $root = finishedPathFixture();
    try {
        list($exit, $output, $error, $requests, $methods) = finishedPathRun($root, 'Серия.ass', 'expired', 'Copy', 'fault');
        strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
        $result = json_decode($output, true);
        strictAssertSame(0, $result['pending'], 'the expired request retires');
        strictAssertTrue(strpos($result['log'], 'refused: source-context-unconfirmed') !== false, 'the final refusal is logged with its reason');
        strictAssertTrue(in_array('system.multicall', $methods, true), 'the refusal is named on the torrent');
        strictAssertSame(false, file_exists($root . '/fin/pending'), 'failed resolution creates no destination');
    } finally { strictRemoveTree($root); }
});

exit($suite->run());
