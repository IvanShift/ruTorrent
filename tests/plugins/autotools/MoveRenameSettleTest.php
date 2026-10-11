<?php
define('MOVE_WORKER_TEST_LIBRARY', true);
require_once __DIR__ . '/MoveWorkerTest.php';
require_once __DIR__ . '/SettlingRenameFiles.php';

// POST-1011-01 for the Move worker: on drvfs over 9p (cache=0x5) lstat of a new
// name answered ENOENT once right after its successful rename. The real worker
// child renames through the shared runner and a scripted helper; the settling
// files object hides the matching new name until its lookup number $revealAt.

function moveSettleLookups($state)
{
    $lookups = @json_decode((string) @file_get_contents($state['root'] . '/settle-lookups.json'), true);
    return is_array($lookups) ? $lookups : array();
}

function moveSettleRenames($state)
{
    return @file($state['root'] . '/renames', FILE_IGNORE_NEW_LINES) ?: array();
}

// The same st_dev EXDEV refusal the worker suite uses, for the source renamed
// onto the destination or onto the hidden sibling the settling object picks.
function moveSettleExdev($state)
{
    $path = $state['root'] . '/rename';
    $refused = array();
    foreach (array($state['destination'], SettlingRenameFiles::hiddenName($state['destination'])) as $to)
        $refused[] = '[ "$2" = ' . escapeshellarg($to) . ' ]';
    file_put_contents($path, str_replace('[ -e "$2" ]', 'if [ "$1" = ' . escapeshellarg($state['source'])
        . ' ] && { ' . implode(' || ', $refused) . '; }; then echo "rename-noreplace-error: errno=18 Invalid cross-device link" >&2; exit 4; fi'
        . "\n" . '[ -e "$2" ]', file_get_contents($path)));
}

function moveSettlePattern($path)
{
    return '#^' . preg_quote($path, '#') . '$#D';
}

// Everything left in the downloads directory: no source, quarantine or hidden name.
function moveSettleDownloads($state)
{
    return array_values(array_diff(scandir($state['root'] . '/dl'), array('.', '..')));
}

$suite = new StrictTestSuite();
foreach (array(false, true) as $multi)
    $suite->test('same-mount publication confirms a destination that answers late multi=' . (int) $multi, function () use ($multi) {
        $state = moveWorkerFixture($multi);
        $state['settle'] = array(moveSettlePattern($state['destination']), 3);
        try {
            list($exit, $after, $calls, $output) = moveWorkerRun($state);
            strictAssertSame(0, $exit, 'the published move completes: ' . $output . ' ' . @file_get_contents($state['root'] . '/errors.log'));
            strictAssertSame(array($state['destination'] => 3), moveSettleLookups($state), 'the destination is confirmed on its third lookup');
            strictAssertSame(1, count(moveSettleRenames($state)), 'the publication rename is made once');
            strictAssertSame(false, $after['queued'], 'the native queue retires');
            strictAssertSame('absent', $after['lease'], 'the lease is acknowledged');
            strictAssertSame('payload', file_get_contents($multi ? $state['destination'] . '/item' : $state['destination']), 'payload at destination');
            strictAssertSame(array(), moveSettleDownloads($state), 'the source is retired');
        } finally { strictRemoveTree($state['root']); }
    });

$suite->test('same-mount publication that never answers stays held and its rename is not repeated', function () {
    $state = moveWorkerFixture();
    $state['settle'] = array(moveSettlePattern($state['destination']), ERASEDATA_RENAME_SETTLE_ATTEMPTS + 1);
    try {
        list($exit, $after, $calls, $output) = moveWorkerRun($state);
        strictAssertSame(1, $exit, 'an absent destination keeps the claim: ' . $output);
        strictAssertSame(array($state['destination'] => ERASEDATA_RENAME_SETTLE_ATTEMPTS), moveSettleLookups($state),
            'the settle stops at its bound');
        strictAssertSame(true, strpos(file_get_contents($state['root'] . '/errors.log'), 'payload-identity-ambiguous') !== false,
            'the classification is the one an absent destination always had');
        strictAssertSame('active', $after['lease'], 'the captured obligation is retained');
        strictAssertSame(false, in_array('d.ack_stop_close_claim', $calls, true), 'the lease is never acknowledged');
        rename(SettlingRenameFiles::hiddenName($state['destination']), $state['destination']);
        unset($after['settle']);
        list($exit, $after, $calls, $output) = moveWorkerRun($after);
        strictAssertSame(0, $exit, 'the next worker completes: ' . $output . ' ' . @file_get_contents($state['root'] . '/errors.log'));
        strictAssertSame(1, count(moveSettleRenames($state)), 'no worker renames the payload again');
        strictAssertSame(false, $after['queued'], 'the native queue retires');
        strictAssertSame('payload', file_get_contents($state['destination']), 'payload at destination');
    } finally { strictRemoveTree($state['root']); }
});

$suite->test('a different inode at the late destination is still refused exactly', function () {
    $state = moveWorkerFixture();
    $state['settle'] = array(moveSettlePattern($state['destination']), 2, true);
    try {
        list($exit, $after, $calls, $output) = moveWorkerRun($state);
        strictAssertSame(1, $exit, 'another inode never passes for the published payload: ' . $output);
        strictAssertSame(array($state['destination'] => 2), moveSettleLookups($state), 'the first present answer ends the settle');
        strictAssertSame(true, strpos(file_get_contents($state['root'] . '/errors.log'), 'payload-identity-ambiguous') !== false,
            'the identity mismatch is classified as before');
        strictAssertSame('active', $after['lease'], 'the captured obligation is retained');
        strictAssertSame(false, in_array('d.ack_stop_close_claim', $calls, true), 'the lease is never acknowledged');
        strictAssertSame('payload', file_get_contents(SettlingRenameFiles::hiddenName($state['destination'])), 'the renamed inode is untouched');
    } finally { strictRemoveTree($state['root']); }
});

foreach (array(false, true) as $multi)
    foreach (array('stage publication', 'source quarantine') as $site)
        $suite->test('same st_dev EXDEV ' . $site . ' that answers late completes multi=' . (int) $multi, function () use ($multi, $site) {
            $state = moveWorkerFixture($multi);
            $late = $site === 'stage publication' ? $state['destination']
                : dirname($state['source']) . '/.autotools-move-' . $state['qid'] . '.source';
            $state['settle'] = array(moveSettlePattern($late), 3);
            moveSettleExdev($state);
            try {
                touch($multi ? $state['source'] . '/item' : $state['source'], 1000000000, 1000000100);
                list($exit, $after, $calls, $output) = moveWorkerRun($state);
                strictAssertSame(0, $exit, 'the staged move completes: ' . $output . ' ' . @file_get_contents($state['root'] . '/errors.log'));
                strictAssertSame(array($late => 3), moveSettleLookups($state), 'the ' . $site . ' name is confirmed on its third lookup');
                strictAssertSame(2, count(moveSettleRenames($state)), 'one stage publication and one quarantine rename');
                strictAssertSame(false, $after['queued'], 'the native queue retires');
                strictAssertSame('payload', file_get_contents($multi ? $state['destination'] . '/item' : $state['destination']), 'copied bytes');
                strictAssertSame(1000000000, filemtime($multi ? $state['destination'] . '/item' : $state['destination']), 'the staged copy preserves mtime');
                strictAssertSame(array(), moveSettleDownloads($state), 'neither the source nor its quarantine copy is left behind');
            } finally { strictRemoveTree($state['root']); }
        });
foreach (array(false, true) as $multi)
    foreach (array('published', 'staged') as $phase)
    $suite->test('a published cross-mount destination still missing on recovery keeps its original receipt phase=' . $phase . ' multi=' . (int) $multi, function () use ($multi, $phase) {
        $state = moveWorkerFixture($multi);
        $state['settle'] = array(moveSettlePattern($state['destination']), ERASEDATA_RENAME_SETTLE_ATTEMPTS + 1);
        moveSettleExdev($state);
        $journal = $state['root'] . '/session/.autotools-file-jobs/' . $state['hash'] . '.move.json';
        try {
            list($exit, $after, $calls, $output) = moveWorkerRun($state);
            strictAssertSame(1, $exit, 'the first publication stays unconfirmed: ' . $output);
            $record = json_decode(file_get_contents($journal), true);
            strictAssertSame('published', $record['phase'], 'publication is already durably recorded');
            // A death after rename but before its journal write leaves the
            // earlier staged receipt and the same missing stage name.
            $record['phase'] = $phase;
            file_put_contents($journal, json_encode($record, JSON_UNESCAPED_SLASHES));
            $before = file_get_contents($journal);
            unset($after['settle']);
            list($exit, $after, $calls, $output) = moveWorkerRun($after);
            strictAssertSame(1, $exit, 'the recovery worker still holds the published payload: ' . $output);
            strictAssertSame($before, file_get_contents($journal), 'the exact publication receipt is retained');
            strictAssertSame('active', $after['lease'], 'the captured lease remains owned');
            strictAssertSame(true, $after['queued'], 'the native queue remains owned');
            strictAssertSame(false, in_array('d.ack_stop_close_claim', $calls, true), 'the unresolved publication is not acknowledged');
            strictAssertSame(true, strpos(file_get_contents($state['root'] . '/errors.log'), 'published-destination-unconfirmed') !== false,
                'the retained destination is named as unconfirmed');
            strictAssertSame(1, count(moveSettleRenames($state)), 'recovery never repeats stage publication');
            strictAssertSame(false, file_exists($record['stage']), 'recovery never makes a second stage copy');
            strictAssertSame('payload', file_get_contents($multi ? $state['source'] . '/item' : $state['source']), 'the original source is retained');
            rename(SettlingRenameFiles::hiddenName($state['destination']), $state['destination']);
            list($exit, $after, $calls, $output) = moveWorkerRun($after);
            strictAssertSame(0, $exit, 'later visibility completes the original publication: ' . $output);
            strictAssertSame(false, $after['queued'], 'the native queue retires after recovery');
            strictAssertSame(false, file_exists($journal), 'the original receipt retires only after completion');
            strictAssertSame(2, count(moveSettleRenames($state)), 'only the original stage publication and source quarantine occur');
            strictAssertSame(array(), moveSettleDownloads($state), 'no public source or hidden source copy is left behind');
            strictAssertSame('payload', file_get_contents($multi ? $state['destination'] . '/item' : $state['destination']), 'the original copy is published');
        } finally { strictRemoveTree($state['root']); }
    });
// The quarantine this worker renamed stays invisible past the bound: retiring
// then would leave the whole source copy hidden with no journal to recover it.
foreach (array(false, true) as $multi)
    foreach (array('current', 'legacy') as $receipt)
    foreach (array('retained', 'absent', 'notice-pending', 'notice-attempted', 'completed') as $claim)
    $suite->test('a source quarantine that never answers stays held and the next worker deletes it receipt=' . $receipt . ' claim=' . $claim . ' multi=' . (int) $multi, function () use ($multi, $receipt, $claim) {
        $state = moveWorkerFixture($multi);
        $late = dirname($state['source']) . '/.autotools-move-' . $state['qid'] . '.source';
        $state['settle'] = array(moveSettlePattern($late), ERASEDATA_RENAME_SETTLE_ATTEMPTS + 1);
        moveSettleExdev($state);
        try {
            list($exit, $after, $calls, $output) = moveWorkerRun($state);
            strictAssertSame(1, $exit, 'an unconfirmed quarantine keeps the job: ' . $output);
            strictAssertSame(array($late => ERASEDATA_RENAME_SETTLE_ATTEMPTS), moveSettleLookups($state), 'the settle stops at its bound');
            $log = file_get_contents($state['root'] . '/errors.log');
            strictAssertSame(true, strpos($log, 'quarantine-unconfirmed') !== false, 'the hold names the unconfirmed quarantine: ' . $log);
            strictAssertSame(false, strpos($log, 'source retired') !== false, 'nothing claims the source was retired');
            strictAssertSame(false, in_array('d.ack_stop_close_claim', $calls, true), 'the claim is not acknowledged');
            strictAssertSame(true, $after['queued'], 'the native queue keeps the job');
            $journal = $state['root'] . '/session/.autotools-file-jobs/' . $state['hash'] . '.move.json';
            $record = json_decode(file_get_contents($journal), true);
            strictAssertSame(false, $record['quarantine_confirmed'], 'the unconfirmed rename remains a durable obligation');
            if ($receipt === 'legacy') {
                unset($record['quarantine_confirmed']);
            }
            // An old worker could ACK after a negative quarantine lookup and
            // die before writing notice-pending. Its absent native claim does
            // not attest deletion of this still-hidden source obligation.
            if ($claim !== 'retained') $after['lease'] = 'absent';
            if (!in_array($claim, array('retained', 'absent'), true)) $record['phase'] = $claim;
            file_put_contents($journal, json_encode($record, JSON_UNESCAPED_SLASHES));
            unset($after['settle']);
            list($exit, $after, $calls, $output) = moveWorkerRun($after);
            strictAssertSame(1, $exit, 'a second worker still holds the unconfirmed quarantine: ' . $output);
            strictAssertSame(true, $after['queued'], 'the hidden source obligation remains queued');
            strictAssertSame(false, in_array('d.ack_stop_close_claim', $calls, true), 'recovery never acknowledges an unseen quarantine');
            strictAssertSame(true, file_exists(SettlingRenameFiles::hiddenName($late)), 'the unconfirmed source copy remains recoverable');
            rename(SettlingRenameFiles::hiddenName($late), $late);
            list($exit, $after, $calls, $output) = moveWorkerRun($after);
            strictAssertSame(0, $exit, 'the next worker completes: ' . $output . ' ' . @file_get_contents($state['root'] . '/errors.log'));
            strictAssertSame(2, count(moveSettleRenames($state)), 'no worker renames the payload again');
            strictAssertSame(false, $after['queued'], 'the native queue retires');
            strictAssertSame('payload', file_get_contents($multi ? $state['destination'] . '/item' : $state['destination']), 'copied bytes');
            strictAssertSame(array(), moveSettleDownloads($state), 'the quarantine copy is deleted, not leaked');
        } finally { strictRemoveTree($state['root']); }
    });
$suite->test('a cross-mount receipt confirmed before source cleanup recovers after the final unlink', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        echo "scope - permission denial requires non-root worker\n";
        return;
    }
    $state = moveWorkerFixture();
    $quarantine = dirname($state['source']) . '/.autotools-move-' . $state['qid'] . '.source';
    moveSettleExdev($state);
    $helper = $state['root'] . '/rename';
    $script = file_get_contents($helper);
    $script = str_replace('exec mv -- "$1" "$2"', 'mv -- "$1" "$2" || exit $?'
        . "\n" . 'if [ "$2" = ' . escapeshellarg($quarantine) . ' ]; then chmod 0500 '
        . escapeshellarg($state['root'] . '/dl') . '; fi' . "\nexit 0", $script, $replaced);
    strictAssertSame(1, $replaced, 'the fixture only denies deletion after the quarantine rename');
    file_put_contents($helper, $script);
    try {
        list($exit, $after, $calls, $output) = moveWorkerRun($state);
        strictAssertSame(1, $exit, 'source cleanup is held after exact identity confirmation: ' . $output);
        $journal = $state['root'] . '/session/.autotools-file-jobs/' . $state['hash'] . '.move.json';
        $record = json_decode(file_get_contents($journal), true);
        strictAssertSame(true, isset($record['quarantine_confirmed']) && $record['quarantine_confirmed'] === true,
            'positive quarantine identity is durable before deletion');
        strictAssertSame(true, file_exists($quarantine), 'the confirmed source still exists at the deletion cut');
        strictAssertSame(false, in_array('d.ack_stop_close_claim', $calls, true), 'cleanup failure retains the native claim');
        strictAssertSame(true, strpos(file_get_contents($state['root'] . '/errors.log'), 'quarantine-delete-failed') !== false,
            'the actual cleanup refusal is reached');
        // The durable cut after the final unlink but before native ACK. The
        // earlier worker, rather than this test, saved the confirmation bit.
        chmod($state['root'] . '/dl', 0700);
        unlink($quarantine);
        list($exit, $after, $calls, $output) = moveWorkerRun($after);
        strictAssertSame(0, $exit, 'cleanup already completed before the cut: ' . $output);
        strictAssertSame(false, $after['queued'], 'the native queue retires');
        strictAssertSame('absent', $after['lease'], 'the exact native lease retires');
        strictAssertSame(false, file_exists($journal), 'the completed receipt retires');
        strictAssertSame(array(), moveSettleDownloads($state), 'no source copy remains');
        strictAssertSame('payload', file_get_contents($state['destination']), 'the copied payload remains');
    } finally { chmod($state['root'] . '/dl', 0700); strictRemoveTree($state['root']); }
});
exit($suite->run());
