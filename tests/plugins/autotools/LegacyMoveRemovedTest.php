<?php
require_once __DIR__ . '/../rutracker_check/TestLib.php';
$suite = new StrictTestSuite();
$suite->test('only current Move implementation ships without importer or compatibility adapters', function () {
    $root = testFindRepoRoot();
    $banned = array('move_tx.php', 'move_tx_lib.php', 'AutoToolsMoveTransaction', 'claimIfDue',
        'waitForDownload', 'runPending', 'retrackersClaim', 'restoreJobMarker', 'failureKind', 'planFailed',
        'waiting_since', 'absent_since', 'ABSENT_CONFIRM_SECONDS', 'claimAbiStatus', 'moveAdmission',
        'daemon-claim-abi-', 'download-not-running', 'job-marker-mismatch', 'legacy Move entrypoint',
        'system.retrackers.original_claim', 'retrackers-queued', 'unescapeValue');
    $files = array();
    $process = proc_open(array('git', '-C', $root, 'ls-files', '-z', '--', 'plugins', 'php', 'tools', 'js', 'conf'),
        array(0=>array('file','/dev/null','r'),1=>array('pipe','w'),2=>array('file','/dev/null','w')), $pipes);
    if (is_resource($process)) {
        $listed = stream_get_contents($pipes[1]); fclose($pipes[1]);
        if (proc_close($process) === 0) $files = array_filter(explode("\0", $listed));
    }
    // Matrix archives omit .git. Scan the same shipped roots there, and include
    // new candidate files in an unstaged review without weakening the gate.
    foreach (array('plugins','php','tools','js','conf') as $directory)
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory,
            FilesystemIterator::SKIP_DOTS)) as $entry)
            if ($entry->isFile() && preg_match('/\.(php|js|sh|txt|md)$/D', $entry->getFilename()))
                $files[] = substr($entry->getPathname(), strlen($root) + 1);
    foreach (array_unique($files) as $relative) {
        $file = $root . '/' . $relative;
        if (!is_file($file)) continue;
        $source = file_get_contents($file);
        // Generic PHP ownership symbols and parser calls in other plugins are
        // unrelated; the old Move identifiers are forbidden in every owner.
        $global = array_values(array_diff($banned, array('system.retrackers.original_claim', 'retrackers-queued', 'unescapeValue')));
        $words = strpos($relative, 'plugins/autotools/') === 0 ? $banned : $global;
        $words = array_merge($words, array('x-autotools-move-job', '.move-request.json', 'move_adopt',
            'move_migrate.php', 'autotools-move-migrate.php', 'AutoToolsMoveMigration'));
        if (strpos($relative, 'plugins/autotools/') === 0)
            $words = array_merge($words, array('completion_recorded', 'job_id'));
        foreach ($words as $word) {
            $at = strpos($source, $word);
            $line = $at === false ? 0 : substr_count(substr($source, 0, $at), "\n") + 1;
            strictAssertSame(false, $at, $relative . ':' . $line . ': forbidden ' . $word);
        }
    }
    foreach (array('plugins/autotools/move_migrate.php', 'tools/autotools-move-migrate.php',
        'plugins/autotools/move_tx.php', 'plugins/autotools/move_tx_lib.php') as $removed)
        strictAssertSame(false, file_exists($root . '/' . $removed), 'removed entrypoint: ' . $removed);
    strictAssertSame(false, strpos('.nonmove-request.json', '.move-request.json'), 'current NonMove is not a retired Move request');
    $source = file_get_contents($root . '/plugins/autotools/autotools.php');
    strictAssertSame(2, substr_count($source, 'd.autotools.move_enqueue'), 'capability plus exactly one native hook');
    strictAssertSame(1, substr_count($source, 'upstream_move.php'), 'one upstream actor path');
});
exit($suite->run());
