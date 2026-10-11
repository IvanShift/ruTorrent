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
                list($exit, $after, $calls, $output) = moveWorkerRun($state);
                strictAssertSame(0, $exit, 'the staged move completes: ' . $output . ' ' . @file_get_contents($state['root'] . '/errors.log'));
                strictAssertSame(array($late => 3), moveSettleLookups($state), 'the ' . $site . ' name is confirmed on its third lookup');
                strictAssertSame(2, count(moveSettleRenames($state)), 'one stage publication and one quarantine rename');
                strictAssertSame(false, $after['queued'], 'the native queue retires');
                strictAssertSame('payload', file_get_contents($multi ? $state['destination'] . '/item' : $state['destination']), 'copied bytes');
                strictAssertSame(array(), moveSettleDownloads($state), 'neither the source nor its quarantine copy is left behind');
            } finally { strictRemoveTree($state['root']); }
        });
// The quarantine this worker renamed stays invisible past the bound: retiring
// then would leave the whole source copy hidden with no journal to recover it.
foreach (array(false, true) as $multi)
    $suite->test('a source quarantine that never answers stays held and the next worker deletes it multi=' . (int) $multi, function () use ($multi) {
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
            rename(SettlingRenameFiles::hiddenName($late), $late);
            unset($after['settle']);
            list($exit, $after, $calls, $output) = moveWorkerRun($after);
            strictAssertSame(0, $exit, 'the next worker completes: ' . $output . ' ' . @file_get_contents($state['root'] . '/errors.log'));
            strictAssertSame(2, count(moveSettleRenames($state)), 'no worker renames the payload again');
            strictAssertSame(false, $after['queued'], 'the native queue retires');
            strictAssertSame('payload', file_get_contents($multi ? $state['destination'] . '/item' : $state['destination']), 'copied bytes');
            strictAssertSame(array(), moveSettleDownloads($state), 'the quarantine copy is deleted, not leaked');
        } finally { strictRemoveTree($state['root']); }
    });
exit($suite->run());
