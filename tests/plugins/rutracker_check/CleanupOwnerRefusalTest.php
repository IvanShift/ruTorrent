<?php

require_once __DIR__ . '/TestLib.php';
require_once __DIR__ . '/../erasedata/CollectorFixture.php';
require_once __DIR__ . '/../../../plugins/erasedata/removewithdata.php';
eval(loadClassDefinition(__DIR__ . '/../../../plugins/rutracker_check/check.php', 'ruTrackerChecker'));

$suite = new StrictTestSuite();
foreach (['unplaceable owner', 'short owner scan', 'unknown fleet'] as $scene) {
    $suite->test('obsolete cleanup reports one classified refusal for ' . $scene, function () use ($scene) {
        $root = sys_get_temp_dir() . '/checker-owner-refusal-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        try {
            file_put_contents($root . '/old.bin', 'old');
            FileUtil::$settingsPath = $root;
            FileUtil::$log = [];
            ErasedataCollectorTestState::$source = false;
            $owner = str_repeat('D', 40);
            if ($scene === 'unplaceable owner') {
                // The owner scan answers, but the downloads question gets an
                // answer without whole rows (erasedataLoadedDownloads()).
                rXMLRPCRequest::queue('d.multicall', true, false, [$owner, '', '', '', '']);
                rXMLRPCRequest::queue('d.multicall', true, false, [$owner, $root]);
            }
            if ($scene === 'short owner scan') {
                // The owner scan's last row lacks a value.
                rXMLRPCRequest::queue('d.multicall', true, false, [$owner, '', '', '']);
            }
            $old = new ErasedataCollectorTestSource(['hash' => str_repeat('A', 40),
                'info' => ['name' => 'old.bin', 'length' => 3]]);
            $new = new ErasedataCollectorTestSource(['hash' => str_repeat('B', 40),
                'info' => ['name' => 'new.bin', 'length' => 3]]);
            foreach (['old.bin', 'new.bin'] as $name) {
                rXMLRPCRequest::queueRaw(
                    ['d.get_base_path', 'd.is_multi_file', 'f.multicall'],
                    ErasedataCollectorRpcWire::physicalBatch([$root . '/' . $name, 0, $root . '/' . $name]),
                );
                rXMLRPCRequest::queueRaw(
                    ['d.get_directory', 'd.is_multi_file', 'f.multicall'],
                    ErasedataCollectorRpcWire::physicalBatch([$root, 0, $name]),
                );
            }
            $result = strictInvoke('ruTrackerChecker', 'buildObsoleteCleanupFiles', [
                $old, $new, $root, str_repeat('A', 40), str_repeat('B', 40), str_repeat('a', 32),
                str_repeat('A', 40) . '-started-1787587200']);
            strictAssertSame(false, $result, 'unconfirmed other ownership blocks obsolete-file cleanup');
            $reason = 'owner-snapshot-unconfirmed why=' . ($scene === 'unknown fleet' ? 'fault' : 'unreadable');
            strictAssertSame(
                ['rutracker_check: obsolete cleanup refused old=' . str_repeat('A', 40)
                . ' new=' . str_repeat('B', 40) . ' reason=' . $reason],
                FileUtil::$log,
                'the actual producer consumes the snapshot reason and emits it once',
            );
            $scans = rXMLRPCRequest::requestsFor('d.multicall');
            strictAssertSame(
                $scene === 'unplaceable owner' ? ['default', 'default'] : ['default'],
                array_map(function ($request) {
                    return $request['commands'][0]->params[0];
                }, $scans),
                'the owner scan and the downloads question read one view, the one shared.php reads',
            );
            strictAssertSame('old', file_get_contents($root . '/old.bin'), 'refusal preserves obsolete payload');
            strictAssertSame([], rXMLRPCRequest::requestsFor('d.erase'), 'refusal never erases a torrent');
            foreach (['d.get_base_path', 'd.get_directory'] as $getter) {
                $requests = rXMLRPCRequest::requestsFor($getter . '|d.is_multi_file|f.multicall');
                strictAssertSame([str_repeat('A', 40), str_repeat('B', 40)], array_map(function ($request) {
                    $hash = $request['commands'][0]->params;
                    strictAssertSame($hash, $request['commands'][1]->params, 'multi getter targets the same generation');
                    strictAssertSame($hash, $request['commands'][2]->params[0], 'file table targets the same generation');
                    return $hash;
                }, $requests), 'confirmed path tables belong to the old and new sources in order');
            }
        } finally {
            unlink($root . '/old.bin');
            rmdir($root);
        }
    });
}
exit($suite->run());
