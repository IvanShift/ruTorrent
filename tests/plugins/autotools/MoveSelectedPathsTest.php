<?php
define('MOVE_WORKER_TEST_LIBRARY', true);
require_once __DIR__ . '/MoveWorkerTest.php';
require_once __DIR__ . '/../../php/ScgiToolPeer.php';
require_once __DIR__ . '/../../../php/utility/json.php';
eval(loadClassDefinition(testFindRepoRoot() . '/php/Torrent.php', 'Torrent'));

function selectedMoveFixture($multi, $utf8)
{
    $state = moveWorkerFixture($multi);
    $raw = "episode \xF1\xE5\xF0\xE8\xFF.mkv";
    $alias = "episode \xD1\x81\xD0\xB5\xD1\x80\xD0\xB8\xD1\x8F.mkv";
    $selected = $utf8 ? $alias : $raw;
    $info = array('piece length' => 16384, 'pieces' => str_repeat('x', 20));
    if ($multi) {
        unlink($state['source'] . '/item');
        $selected = 'season/' . $selected;
        mkdir($state['source'] . '/season', 0700);
        file_put_contents($state['source'] . '/' . $selected, 'payload');
        $info['name'] = $state['name'];
        $info['files'] = array(array('length' => 7, 'path' => array('season', $raw),
            'path.utf-8' => array('season', $alias)));
    } else {
        rename($state['source'], dirname($state['source']) . '/' . $selected);
        $state['source'] = dirname($state['source']) . '/' . $selected;
        $state['basename'] = $selected;
        $state['destination'] = $state['root'] . '/fin/' . $selected;
        $info['name'] = $raw;
        $info['name.utf-8'] = $alias;
        $info['length'] = 7;
    }
    $state['filePaths'] = array($selected);
    file_put_contents($state['root'] . '/session/' . $state['hash'] . '.torrent',
        strictInvoke('Torrent', 'encode', array(array('info' => $info))));
    return $state;
}

$suite = new StrictTestSuite();
foreach (array(false, true) as $multi)
    foreach (array(false, true) as $utf8)
        $suite->test('Move plans the actual daemon path, multi=' . (int)$multi . ', utf8=' . (int)$utf8,
            function () use ($multi, $utf8) {
                $state = selectedMoveFixture($multi, $utf8);
                try {
                    list($exit, $after, $calls, $output) = moveWorkerRun($state, 'prepare');
                    strictAssertSame(0, $exit, 'preparation accepts the selected path: ' . $output);
                    $job = JSON::decodeBytes($output);
                    strictAssertSame($state['filePaths'], $job['files'], 'journal manifest uses exact daemon bytes');
                    strictAssertSame('payload', file_get_contents($multi ? $state['source'] . '/' . $state['filePaths'][0]
                        : $state['source']), 'preparation preserves the payload');
                    strictAssertSame(false, in_array('d.autotools.move_begin', $calls, true), 'planning never claims or starts');
                    list($exit, $after, $calls, $output) = moveWorkerRun($state);
                    strictAssertSame(0, $exit, 'the actual worker moves the selected manifest: ' . $output);
                    strictAssertSame('payload', file_get_contents($multi ? $state['destination'] . '/' . $state['filePaths'][0]
                        : $state['destination']), 'the destination retains the exact selected file and payload');
                    strictAssertSame(false, file_exists($state['source']), 'only the source root is retired after the move');
                    strictAssertSame(false, $after['queued'], 'the successful selected-path move retires its own queue entry');
                    strictAssertSame(1, $after['active'], 'the worker preserves the captured active state');
                } finally { strictRemoveTree($state['root']); }
            });
foreach (array('lost', 'fault', 'flat', 'empty', 'partial', 'extra', 'integer', 'nested', 'unsafe') as $shape)
    $suite->test('Move rejects an unconfirmed daemon file list: ' . $shape, function () use ($shape) {
        $state = selectedMoveFixture(true, true);
        $path = '<value><base64>' . base64_encode($state['filePaths'][0]) . '</base64></value>';
        $row = '<value><array><data>' . $path . '</data></array></value>';
        $value = '<array><data>' . $row . '</data></array>';
        if ($shape === 'lost') $state['filePathsReply'] = null;
        elseif ($shape === 'fault') $state['filePathsReply'] = FakeRtorrentDaemon::fault('file list unavailable', -500);
        else {
            if ($shape === 'partial') {
                $torrent = Torrent::fromRawBytes(file_get_contents($state['root'] . '/session/' . $state['hash'] . '.torrent'));
                $torrent->info['files'][] = array('length' => 1, 'path' => array('missing-second.bin'));
                file_put_contents($state['root'] . '/session/' . $state['hash'] . '.torrent',
                    strictInvoke('Torrent', 'encode', array(array('info' => $torrent->info))));
            }
            elseif ($shape === 'flat') $value = '<array><data>' . $path . '</data></array>';
            elseif ($shape === 'empty') $value = '<array><data></data></array>';
            elseif ($shape === 'extra') $value = '<array><data>' . $row . $row . '</data></array>';
            elseif ($shape === 'integer') $value = str_replace($path, '<value><i8>123</i8></value>', $value);
            elseif ($shape === 'nested') $value = str_replace($path, '<value><array><data>' . $path . '</data></array></value>', $value);
            elseif ($shape === 'unsafe') $value = str_replace($path, '<value><string>../foreign</string></value>', $value);
            $state['filePathsReply'] = ScgiToolPeer::response($value);
        }
        $helper = file_get_contents($state['root'] . '/rename');
        file_put_contents($state['root'] . '/rename', str_replace("#!/bin/sh\n", "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> "
            . escapeshellarg($state['root'] . '/helper-calls') . "\n", $helper));
        try {
            list($exit, $after, $calls, $output) = moveWorkerRun($state, 'prepare');
            strictAssertSame(true, $exit !== 0, 'unconfirmed manifest is refused');
            strictAssertSame(true, in_array('f.multicall', $calls, true), 'the actual file-list reply was inspected');
            $reason = in_array($shape, array('lost', 'fault'), true) ? 'file-list-unconfirmed'
                : ($shape === 'unsafe' ? 'manifest-path-invalid' : 'file-list-malformed');
            strictAssertSame(true, strpos($output, $reason) !== false, 'the refusal names the unconfirmed snapshot boundary');
            strictAssertSame(false, file_exists($state['root'] . '/helper-calls'), 'refusal precedes filesystem probe or move');
            strictAssertSame('payload', file_get_contents($state['source'] . '/' . $state['filePaths'][0]), 'source retained');
            strictAssertSame(false, in_array('d.autotools.move_begin', $calls, true), 'no daemon claim');
        } finally { strictRemoveTree($state['root']); }
    });
exit($suite->run());
