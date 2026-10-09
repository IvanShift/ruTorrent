<?php
define('MOVE_WORKER_TEST_LIBRARY', true);
require_once __DIR__ . '/MoveWorkerTest.php';
require_once __DIR__ . '/../../php/ScgiToolPeer.php';
$suite = new StrictTestSuite();
foreach (array('current', 'legacy') as $version)
    foreach (array('readiness', 'reset-report', 'terminal-count') as $actor)
        $suite->test('M-INT-02 full aliases preserve one target ' . $version . ' ' . $actor, function () use ($version, $actor) {
            $state = moveWorkerFixture();
            $state['aliasVersion'] = $version;
            $state['inventoryLoaded'] = true;
            $state['nativeState'] = 'terminal:' . hash('sha256', 'tester') . ':' . $state['qid'] . ':worker-exit';
            try {
                list($exit, $after, $calls, $output, $bodies) = moveWorkerRun($state, $actor);
                strictAssertSame($actor === 'readiness' ? 1 : 0, $exit, 'known inventory: ' . $output);
                if ($actor === 'terminal-count') strictAssertSame('1', $output, 'terminal job counted');
                elseif ($actor === 'readiness') strictAssertSame(1, count(json_decode($output,true)['native']), 'held native work shown');
                else strictAssertSame(true, strpos($output, $state['hash'].' terminal: worker-exit') !== false, 'reset dry inventory sees own job');
                $seen = 0;
                foreach ($bodies as $body) {
                    if (!preg_match('~<methodName>(d\.multicall2?)</methodName>~', $body, $method)) continue;
                    $seen++;
                    strictAssertSame($version === 'legacy' ? 'd.multicall2' : 'd.multicall', $method[1], 'alias owner selects native name');
                    preg_match_all('~<param><value><string>(.*?)</string></value></param>~s', $body, $parameters);
                    strictAssertSame('', $parameters[1][0], 'one transport target');
                    strictAssertSame('main', $parameters[1][1], 'view follows target without extra empty parameter');
                    strictAssertSame('d.hash=', $parameters[1][2], 'first column follows view');
                }
                strictAssertSame(true, $seen > 0, 'actual inventory wire observed');
            } finally { strictRemoveTree($state['root']); }
        });
// The native capture is array -> arrays -> typed strings, never a flat scalar list.
foreach (array('flat', 'short', 'extra', 'nested', 'integer', 'row-struct', 'root-struct', 'duplicate', 'lost') as $shape)
    foreach (array('readiness', 'reset-report', 'terminal-count') as $actor)
        $suite->test('M-INT-02 unknown inventory refuses ' . $shape . ' ' . $actor, function () use ($shape, $actor) {
            $state = moveWorkerFixture();
            $state['aliasVersion'] = 'current';
            $h = '<value><string>' . $state['hash'] . '</string></value>';
            $cell = '<value><string>' . $state['qid'] . '</string></value>';
            $cells = $h;
            $row = '<value><array><data>' . $cells . '</data></array></value>';
            $value = '<array><data>' . $row . '</data></array>';
            if ($shape === 'flat') $value = '<array><data>' . $cells . '</data></array>';
            elseif ($shape === 'short') $value = '<array><data><value><array><data></data></array></value></data></array>';
            elseif ($shape === 'extra') $value = str_replace($cells, $cells . $cell, $value);
            elseif ($shape === 'nested') $value = str_replace($h, '<value><array><data>' . $h . '</data></array></value>', $value);
            elseif ($shape === 'integer') $value = str_replace($h, '<value><i8>' . str_repeat('1',40) . '</i8></value>', $value);
            elseif ($shape === 'row-struct') $value = '<array><data><value><struct><member><name>0</name>' . $h . '</member>'
                . '</struct></value></data></array>';
            elseif ($shape === 'root-struct') $value = '<struct><member><name>0</name>' . $row . '</member></struct>';
            elseif ($shape === 'duplicate') $value = '<array><data>' . $row . $row . '</data></array>';
            $state['inventoryReply'] = $shape === 'lost' ? null : ScgiToolPeer::response($value);
            try {
                list($exit, $after, $calls, $output) = moveWorkerRun($state, $actor);
                if ($actor === 'terminal-count') strictAssertSame('null', $output, 'unknown count stays unknown');
                else strictAssertSame(2, $exit, 'operator tool refuses unknown snapshot: ' . $output);
                strictAssertSame(array(), array_values(array_filter($calls, function ($method) {
                    return !in_array($method, array('d.multicall', 'd.multicall2'), true);
                })), 'unknown snapshot cannot authorize per-hash reads or writes');
            } finally { strictRemoveTree($state['root']); }
        });
$suite->test('M-INT-02 raw typed table preserves base64 bytes and quoted bytes', function () {
    $state = moveWorkerFixture();
    $state['aliasVersion'] = 'current';
    $bytes = " quote \" \\ & < > \0 ";
    $state['inventoryReply'] = ScgiToolPeer::response('<array><data><value><array><data><value><base64>'
        . base64_encode($state['hash']) . '</base64></value><value><base64>' . base64_encode($bytes)
        . '</base64></value></data></array></value></data></array>');
    try {
        list($exit, $after, $calls, $output) = moveWorkerRun($state, 'inventory-rows');
        strictAssertSame(0, $exit, 'typed raw table succeeds');
        strictAssertSame(array(array($state['hash'], $bytes)), json_decode($output,true), 'exact decoded bytes');
    } finally { strictRemoveTree($state['root']); }
});
exit($suite->run());
