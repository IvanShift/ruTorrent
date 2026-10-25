<?php

require_once __DIR__ . '/TestLib.php';
require_once __DIR__ . '/../erasedata/CollectorFixture.php';
require_once __DIR__ . '/../../../plugins/erasedata/removewithdata.php';
eval(loadClassDefinition(__DIR__ . '/../../../plugins/rutracker_check/check.php', 'ruTrackerChecker'));

$suite = new StrictTestSuite();
foreach (array('blocking owner', 'unknown fleet') as $scene) {
    $suite->test('obsolete cleanup reports one classified refusal for ' . $scene, function () use ($scene) {
        $root = sys_get_temp_dir() . '/checker-owner-refusal-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        try {
            file_put_contents($root . '/old.bin', 'old');
            FileUtil::$settingsPath = $root;
            FileUtil::$log = array();
            ErasedataCollectorTestState::$source = false;
            ErasedataCollectorTestState::$fleetSources = array();
            $owner = str_repeat('D', 40);
            if ($scene === 'blocking owner')
                rXMLRPCRequest::queue('d.multicall', true, false, array($owner, '', '', '', ''));
            $old = new ErasedataCollectorTestSource(array('hash' => str_repeat('A', 40),
                'info' => array('name' => 'old.bin', 'length' => 3)));
            $new = new ErasedataCollectorTestSource(array('hash' => str_repeat('B', 40),
                'info' => array('name' => 'new.bin', 'length' => 3)));
            foreach (array('old.bin', 'new.bin') as $name) {
                rXMLRPCRequest::queueRaw(array('d.get_base_path', 'd.is_multi_file', 'f.multicall'),
                    ErasedataCollectorRpcWire::physicalBatch(array($root . '/' . $name, 0, $root . '/' . $name)));
                rXMLRPCRequest::queueRaw(array('d.get_directory', 'd.is_multi_file', 'f.multicall'),
                    ErasedataCollectorRpcWire::physicalBatch(array($root, 0, $name)));
            }
            $result = strictInvoke('ruTrackerChecker', 'buildObsoleteCleanupFiles', array(
                $old, $new, $root, str_repeat('A', 40), str_repeat('B', 40), str_repeat('a', 32),
                str_repeat('A', 40) . '-started-1787587200'));
            strictAssertSame(false, $result, 'unconfirmed other ownership blocks obsolete-file cleanup');
            $reason = $scene === 'blocking owner'
                ? 'owner-session-copy-unreadable-daemon-paths-unconfirmed owner=' . $owner
                : 'owner-snapshot-unconfirmed';
            strictAssertSame(array('rutracker_check: obsolete cleanup refused old=' . str_repeat('A', 40)
                . ' new=' . str_repeat('B', 40) . ' reason=' . $reason), FileUtil::$log,
                'the actual producer consumes the snapshot reason and emits it once');
            strictAssertSame('old', file_get_contents($root . '/old.bin'), 'refusal preserves obsolete payload');
            strictAssertSame(array(), rXMLRPCRequest::requestsFor('d.erase'), 'refusal never erases a torrent');
            foreach (array('d.get_base_path', 'd.get_directory') as $getter) {
                $requests = rXMLRPCRequest::requestsFor($getter . '|d.is_multi_file|f.multicall');
                strictAssertSame(array(str_repeat('A', 40), str_repeat('B', 40)), array_map(function ($request) {
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
