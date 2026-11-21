<?php

// The child runs the actual hook and XMLRPC reader; only its SCGI peer differs.
if (isset($argv[1]) && $argv[1] === '--selected-paths-child') {
    $root = $argv[2];
    $mode = $argv[3];
    $single = strpos($mode, '-single') !== false;
    $mode = str_replace('-single', '', $mode);
    $operation = $argv[4];
    $layout = json_decode($argv[6], true);
    $default = $root . '/' . $layout['default'];
    $source = $default . ($layout['sub'] === '' ? '' : '/' . $layout['sub']);
    $session = $root . '/' . $layout['session'];
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
    $settings->aliases = [];
    $settings->linkExist = true;
    $settings->directory = $default;
    $settings->session = $session;
    (function () use ($repo) {
        foreach (['0.9.4', '0.10.2', '0.16.0', '0.16.16', '0.16.18'] as $version) {
            require $repo . '/php/methods-' . $version . '.php';
        }
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
    if (!$at->store()) {
        throw new RuntimeException('could not store the hook fixture settings');
    }
    $hash = str_repeat('A', 40);
    $args = [$hash, $source . '/Root', 'Root', '1', '', 'Root', 'finishedpaths', str_repeat('b', 32)];
    if ($single) {
        $args[1] = $source . '/payload.bin';
        $args[2] = $args[5] = 'payload.bin';
        $args[3] = '0';
        rename($session . '/' . $hash . '.torrent', $session . '/held.torrent');
    }
    if ($mode === 'perform') {
        $result = AutoToolsFinishedHook::perform($args, false, true);
    } else {
        $result = ['destination' => AutoToolsFinishedHook::hook($args)];
        if ($single) {
            rename($session . '/held.torrent', $session . '/' . $hash . '.torrent');
        }
        if ($mode === 'expired') {
            foreach (erasedataDirectoryMatches($session . '/.autotools-file-jobs', '', AutoToolsFinishedHook::REQUEST_SUFFIX) as $path) {
                $request = JSON::decodeBytes(file_get_contents($path));
                $request['at'] = time() - (int) ceil(rtFileJobRetryWindow()) - 1;
                file_put_contents($path, JSON::encodeBytes($request));
            }
        }
        AutoToolsFinishedHook::performDeferred();
    }
    $journal = $session . '/.autotools-file-jobs';
    $result['pending'] = is_dir($journal)
        ? count(erasedataDirectoryMatches($journal, '', AutoToolsFinishedHook::REQUEST_SUFFIX)) : 0;
    $result['log'] = is_file($root . '/errors.log') ? file_get_contents($root . '/errors.log') : '';
    echo json_encode($result);
    exit(0);
}

require_once __DIR__ . '/../rutracker_check/TestLib.php';
require_once __DIR__ . '/../../php/ScgiToolPeer.php';

function finishedPathFixture($selected = 'Серия.ass', $layout = ['default' => 'dl', 'sub' => '', 'session' => 'session'])
{
    $root = sys_get_temp_dir() . '/fp-' . bin2hex(random_bytes(5));
    $source = $root . '/' . $layout['default'] . ($layout['sub'] === '' ? '' : '/' . $layout['sub']);
    $session = $root . '/' . $layout['session'];
    foreach ([$root, $session, $source . '/Root', $root . '/fin'] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException('fixture directory unavailable');
        }
    }
    file_put_contents($source . '/Root/' . $selected, 'payload bytes');
    $raw = "\xD1\xE5\xF0\xE8\xFF.ass";
    file_put_contents(
        $session . '/' . str_repeat('A', 40) . '.torrent',
        'd4:infod5:filesld6:lengthi13e4:pathl' . strlen($raw) . ':' . $raw
        . 'e10:path.utf-8l' . strlen($selected) . ':' . $selected . 'ee'
        . 'e4:name4:Root12:piece lengthi16384e6:pieces20:' . str_repeat("\x01", 20) . 'ee',
    );
    return $root;
}

function finishedPathReply($xml)
{
    return "Status: 200 OK\r\nContent-Type: text/xml\r\nContent-Length: " . strlen($xml) . "\r\n\r\n" . $xml;
}

function finishedPathRun($root, $selected, $mode = 'perform', $operation = 'Copy', $shape = 'valid', $filter = '', $layout = ['default' => 'dl', 'sub' => '', 'session' => 'session'])
{
    return ScgiToolPeer::run(
        $root . '/rpc.sock',
        __FILE__,
        ['--selected-paths-child', $root, $mode, $operation, $filter, json_encode($layout)],
        function ($request) use ($root, $selected, $shape, $mode, $layout) {
            $source = $root . '/' . $layout['default'] . ($layout['sub'] === '' ? '' : '/' . $layout['sub']);
            $method = ScgiToolPeer::method($request);
            if ($method === 'system.multicall' && strpos($request, '<string>d.base_path</string>') !== false) {
                if ($shape === 'context-lost' || $shape === 'lost') {
                    return null;
                }
                if ($shape === 'fault') {
                    return finishedPathReply('<?xml version="1.0"?><methodResponse><fault><value><struct>'
                        . '<member><name>faultCode</name><value><int>-500</int></value></member>'
                        . '<member><name>faultString</name><value><string>source snapshot unavailable</string></value></member>'
                        . '</struct></value></fault></methodResponse>');
                }
                $single = strpos($mode, '-single') !== false;
                $base = $source . '/' . ($single ? 'payload.bin' : 'Root');
                if ($shape === 'stale-root') {
                    $base = $source . '/' . ($single ? 'Current.bin' : 'Current');
                }
                $values = ['<base64>' . base64_encode($base) . '</base64>',
                    '<i8>' . ($shape === 'bad-flag-value' ? '2' : ($shape === 'stale-type' ? '0' : ($single ? '0' : '1'))) . '</i8>',
                    '<string>' . ($shape === 'stale-basename' ? 'Other' : ($single ? 'payload.bin' : 'Root')) . '</string>',
                    '<string>' . str_repeat($shape === 'stale-token' ? 'c' : 'b', 32) . '</string>'];
                if ($shape === 'cold-frozen' || $shape === 'cold-stored-directory-changed') {
                    $values[0] = $values[2] = '<string></string>';
                }
                if ($shape === 'context-malformed') {
                    $values[0] = '<array><data><value>' . $values[0] . '</value></data></array>';
                }
                if (strpos($request, '<string>d.directory</string>') !== false) {
                    $directory = $single ? dirname($base) : $base;
                    if ($shape === 'stored-directory-changed' || $shape === 'cold-stored-directory-changed') {
                        $directory = $source . '/Current';
                    } elseif ($shape === 'stored-directory-space') {
                        $directory .= ' ';
                    } elseif ($shape === 'stored-directory-tail') {
                        $directory .= '///';
                    }
                    $stored = '<base64>' . base64_encode($directory) . '</base64>';
                    if ($shape === 'stored-directory-malformed') {
                        $stored = '<array><data><value>' . $stored . '</value></data></array>';
                    }
                    $values[] = $stored;
                }
                $actual = $single ? ($shape === 'single-path-changed' ? 'Other.bin' : 'payload.bin') : $selected;
                $cell = $shape === 'integer' ? '<i8>1</i8>' : '<base64>' . base64_encode($actual) . '</base64>';
                $row = '<value><array><data><value>' . $cell . '</value></data></array></value>';
                $values[] = '<array><data>' . $row . ($shape === 'extra' ? $row : '') . '</data></array>';
                if ($shape === 'context-short') {
                    array_pop($values);
                }
                $rows = '';
                foreach ($values as $value) {
                    $rows .= '<value><array><data><value>' . $value . '</value></data></array></value>';
                }
                return ScgiToolPeer::scgiAnswer('<array><data>' . $rows . '</data></array>');
            }
            if ($method === 'f.multicall') {
                if ($shape === 'lost') {
                    return null;
                }
                if ($shape === 'fault') {
                    return finishedPathReply('<?xml version="1.0"?><methodResponse><fault><value><struct>'
                        . '<member><name>faultCode</name><value><int>-500</int></value></member>'
                        . '<member><name>faultString</name><value><string>file list unavailable</string></value></member>'
                        . '</struct></value></fault></methodResponse>');
                }
                $cell = $shape === 'integer' ? '<i8>1</i8>' : '<base64>' . base64_encode($selected) . '</base64>';
                $row = '<value><array><data><value>' . $cell . '</value></data></array></value>';
                return ScgiToolPeer::scgiAnswer('<array><data>' . $row
                    . ($shape === 'extra' ? $row : '') . '</data></array>');
            }
            if ($method === 'd.directory') {
                $directory = $source . (strpos($mode, '-single') !== false ? '' : '/Root');
                if (in_array($shape, ['stale-root', 'stored-directory-changed', 'cold-stored-directory-changed'], true)) {
                    $directory = $source . '/Current';
                } elseif ($shape === 'stored-directory-space') {
                    $directory .= ' ';
                }
                return ScgiToolPeer::scgiAnswer('<string>' . htmlspecialchars($directory, ENT_XML1) . '</string>');
            }
            if ($method === 'system.multicall') {
                return ScgiToolPeer::scgiAnswer('<array><data><value><array><data><value><string>0</string></value></data></array></value>'
                    . '<value><array><data><value><string>0</string></value></data></array></value></data></array>');
            }
            return ScgiToolPeer::scgiAnswer('<string>0</string>');
        },
        [],
        false,
    );
}
