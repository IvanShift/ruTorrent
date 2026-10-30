<?php
// AutoTools Move, native and upstream mode, keeps the payload inside
// $topDirectory once links are followed. A finished directory, a directory on
// the way or the download's own name that links out of it is refused visibly
// before anything is created through it, and a link swapped in after the
// Move was planned is caught again before the payload is written through it.
// The tree is real: a top directory (the fixture root) and a directory beside
// it, outside.
define('MOVE_WORKER_TEST_LIBRARY', true);
require_once __DIR__ . '/MoveWorkerTest.php';

function topFixture($multi = false)
{
    $state = moveWorkerFixture($multi);
    $state['outside'] = $state['root'] . '-out';
    if (!@mkdir($state['outside'], 0700)) {
        strictRemoveTree($state['root']);
        throw new RuntimeException('cannot create the directory outside the top directory');
    }
    $state['topDirectory'] = $state['root'] . '/';
    return $state;
}

function topClean($state)
{
    strictRemoveTree($state['root']);
    strictRemoveTree($state['outside']);
}

// Every entry under the outside directory, links not followed.
function topOutside($state)
{
    $found = array();
    $walk = function ($dir, $prefix) use (&$walk, &$found) {
        foreach (array_diff(scandir($dir), array('.', '..')) as $entry) {
            $found[] = $prefix . $entry;
            if (!is_link($dir . '/' . $entry) && is_dir($dir . '/' . $entry))
                $walk($dir . '/' . $entry, $prefix . $entry . '/');
        }
    };
    $walk($state['outside'], '');
    sort($found);
    return $found;
}

function topPayloadInPlace($state)
{
    return @file_get_contents($state['multi'] ? $state['source'] . '/item' : $state['source']) === 'payload';
}

function topLog($state)
{
    return (string) @file_get_contents($state['root'] . '/errors.log');
}

// The download in a subdirectory "sub" of the download directory, so its
// destination is the same subdirectory of the finished one.
function topInSubdirectory(&$state)
{
    mkdir($state['root'] . '/dl/sub', 0700);
    $source = $state['root'] . '/dl/sub/' . $state['basename'];
    rename($state['source'], $source);
    $state['source'] = $source;
    $state['directory'] = $state['multi'] ? $source : $state['root'] . '/dl/sub';
}

// The finished directory moved outside and a link left in its place.
function topSwapFinished($state)
{
    rename($state['root'] . '/fin', $state['outside'] . '/fin');
    symlink($state['outside'] . '/fin', $state['root'] . '/fin');
}

function topEditJob($state, $edit)
{
    $path = $state['root'] . '/session/.autotools-file-jobs/' . $state['hash'] . '.move.json';
    file_put_contents($path, json_encode($edit(json_decode(file_get_contents($path), true))));
}

// A permanent refusal: cancelled under a reason native admits (the fake
// daemon faults on any other, as native does), named in errors.log and kept
// on the torrent.
function topAssertCancelled($state, $after, $calls, $bodies)
{
    strictAssertTrue(topPayloadInPlace($state), 'the payload stays where it is');
    strictAssertSame(false, $after['queued'], 'the Move is cancelled for good: ' . topLog($state));
    strictAssertTrue(strpos(topLog($state), 'autotools: move ' . $state['hash']
        . ' cancelled: destination-outside-top-directory') !== false, 'errors.log names the reason');
    strictAssertTrue(in_array('d.set_custom', $calls, true)
        && strpos(implode('', $bodies), 'autotools: move refused: destination-outside-top-directory') !== false,
        'and the refusal is kept on the torrent');
    $cancel = array_values(array_filter($bodies, function ($body) {
        return strpos($body, '<methodName>d.autotools.move_cancel</methodName>') !== false;
    }));
    strictAssertSame(1, count($cancel), 'one cancel reaches native');
    strictAssertTrue(strpos($cancel[0], '<string>destination-outside-finished-root</string>') !== false,
        'under the native reason the added one maps to');
}

$suite = new StrictTestSuite();

// The finished root is the link, and the Move would create a directory below it.
foreach (array(false, true) as $multi)
    $suite->test('native Move refuses a finished directory that links out multi=' . (int) $multi, function () use ($multi) {
        $state = topFixture($multi);
        try {
            rmdir($state['root'] . '/fin');
            symlink($state['outside'], $state['root'] . '/fin');
            $state['finished'] = $state['root'] . '/fin/pending';
            list($exit, $after, $calls, $output, $bodies) = moveWorkerRun($state);
            strictAssertSame(0, $exit, 'the refusal completes: ' . $output);
            topAssertCancelled($state, $after, $calls, $bodies);
            strictAssertSame(array(), topOutside($state), 'nothing is created or written outside the top directory');
        } finally { topClean($state); }
    });

foreach (array(false, true) as $multi)
    $suite->test('native Move refuses a download name in the finished directory that links out multi=' . (int) $multi,
        function () use ($multi) {
            $state = topFixture($multi);
            try {
                symlink($state['outside'], $state['destination']);
                list($exit, $after, $calls, $output, $bodies) = moveWorkerRun($state);
                strictAssertSame(0, $exit, 'the refusal completes: ' . $output);
                topAssertCancelled($state, $after, $calls, $bodies);
                strictAssertSame(array(), topOutside($state), 'nothing is written outside the top directory');
            } finally { topClean($state); }
        });

$suite->test('native Move refuses a directory on the way in the finished directory that links out', function () {
    $state = topFixture();
    try {
        topInSubdirectory($state);
        symlink($state['outside'], $state['root'] . '/fin/sub');
        list($exit, $after, $calls, $output, $bodies) = moveWorkerRun($state);
        strictAssertSame(0, $exit, 'the refusal completes: ' . $output);
        topAssertCancelled($state, $after, $calls, $bodies);
        strictAssertSame(array(), topOutside($state), 'nothing is written outside the top directory');
    } finally { topClean($state); }
});

// The name links back inside, so only the directory itself shows the way out.
$suite->test('native Move judges the destination directory, not only a name that links back inside', function () {
    $state = topFixture();
    try {
        rmdir($state['root'] . '/fin');
        symlink($state['outside'], $state['root'] . '/fin');
        mkdir($state['root'] . '/dl/elsewhere', 0700);
        symlink($state['root'] . '/dl/elsewhere', $state['outside'] . '/' . $state['basename']);
        list($exit, $after, $calls, $output, $bodies) = moveWorkerRun($state);
        strictAssertSame(0, $exit, 'the refusal completes: ' . $output);
        topAssertCancelled($state, $after, $calls, $bodies);
        strictAssertSame(array($state['basename']), topOutside($state), 'nothing else is written outside');
    } finally { topClean($state); }
});

$suite->test('with the top directory at / a finished directory link is followed as before', function () {
    $state = topFixture();
    try {
        $state['topDirectory'] = '/';
        rmdir($state['root'] . '/fin');
        symlink($state['outside'], $state['root'] . '/fin');
        list($exit, $after) = moveWorkerRun($state);
        strictAssertSame(0, $exit, 'the Move completes: ' . topLog($state));
        strictAssertSame(false, $after['queued'], 'and finishes');
        strictAssertSame('payload', @file_get_contents($state['outside'] . '/' . $state['basename']),
            'no top directory means no refusal');
    } finally { topClean($state); }
});

// The control, ported from upstream 2446f4827 (AutoToolsTopDirectoryTest):
// with no link out, a Move under a configured top directory completes, so the
// refusals here are about the links and not about the top directory. The
// name in the finished directory is not there yet, so each check resolves a
// missing tail.
foreach (array(false, true) as $multi)
    $suite->test('native Move into a finished directory inside the top directory completes multi=' . (int) $multi,
        function () use ($multi) {
            $state = topFixture($multi);
            try {
                list($exit, $after) = moveWorkerRun($state);
                strictAssertSame(0, $exit, 'the Move completes: ' . topLog($state));
                strictAssertSame(false, $after['queued'], 'and finishes');
                strictAssertSame('payload', @file_get_contents($multi ? $state['destination'] . '/item' : $state['destination']),
                    'the payload is in the finished directory');
                strictAssertSame(false, file_exists($state['source']), 'and not left at the source');
                strictAssertSame(false, strpos(topLog($state), 'outside-top-directory'), 'nothing is refused');
            } finally { topClean($state); }
        });

// The daemon's begin answer is where the probe swapped the link in: after
// prepare() planned and checked the destination, before the rename.
foreach (array(false, true) as $multi)
    $suite->test('native Move catches a finished directory swapped for a link out after planning multi=' . (int) $multi,
        function () use ($multi) {
            $state = topFixture($multi);
            try {
                $state['swapAtBegin'] = array($state['root'] . '/fin' => $state['outside'] . '/fin');
                list($exit, $after, $calls, $output) = moveWorkerRun($state);
                strictAssertSame(0, $exit, 'the abort completes: ' . $output . topLog($state));
                strictAssertTrue(topPayloadInPlace($state), 'the payload stays where it is');
                strictAssertSame(array('fin'), topOutside($state), 'nothing is published outside the top directory');
                strictAssertSame(true, $after['queued'], 'the queue keeps the Move');
                strictAssertSame('absent', $after['lease'], 'the claim is released');
                strictAssertSame(1, preg_match('/:1:[0-9]+:preflight-refused$/D', $after['retry']), 'one counted retry');
                strictAssertTrue(strpos(topLog($state), 'publication held: destination-outside-top-directory') !== false,
                    'errors.log names the reason');
            } finally { topClean($state); }
        });

// A cross-mount Move writes a stage through the destination's parent first.
// The outside directory's time shows whether anything was ever written there.
foreach (array(false, true) as $multi)
    $suite->test('a cross-mount Move resumed after a swap writes no stage outside multi=' . (int) $multi,
        function () use ($multi) {
            $state = topFixture($multi);
            try {
                moveWorkerSeed($state, 'claimed', 'active');
                topEditJob($state, function ($job) { $job['cross'] = true; return $job; });
                topSwapFinished($state);
                touch($state['outside'] . '/fin', 1000000000);
                list($exit, $after, $calls, $output) = moveWorkerRun($state);
                strictAssertSame(0, $exit, 'the abort completes: ' . $output . topLog($state));
                strictAssertTrue(topPayloadInPlace($state), 'the payload stays where it is');
                clearstatcache();
                strictAssertSame(array('fin'), topOutside($state), 'nothing is left outside the top directory');
                strictAssertSame(1000000000, filemtime($state['outside'] . '/fin'), 'nor ever written there');
                strictAssertSame(true, $after['queued'], 'the queue keeps the Move');
                strictAssertTrue(strpos(topLog($state), 'cross-mount stage failed: destination-outside-top-directory') !== false,
                    'errors.log names the reason');
            } finally { topClean($state); }
        });

// A stage copied before the swap moved out with its directory and kept its
// identity: only the name's resolution shows it is outside now.
foreach (array(false, true) as $multi)
    $suite->test('a staged cross-mount Move resumed after a swap does not publish outside multi=' . (int) $multi,
        function () use ($multi) {
            $state = topFixture($multi);
            try {
                $job = moveWorkerSeed($state, 'staged', 'active');
                if ($multi) {
                    mkdir($job['stage'], 0700);
                    copy($state['source'] . '/item', $job['stage'] . '/item');
                } else copy($state['source'], $job['stage']);
                $stat = lstat($job['stage']);
                topEditJob($state, function ($job) use ($stat) {
                    $job['cross'] = true;
                    $job['stage_identity'] = $stat['dev'] . ':' . $stat['ino'];
                    return $job;
                });
                topSwapFinished($state);
                list($exit, $after, $calls, $output) = moveWorkerRun($state);
                strictAssertSame(0, $exit, 'the abort completes: ' . $output . topLog($state));
                strictAssertTrue(topPayloadInPlace($state), 'the payload stays where it is');
                strictAssertSame(array('fin'), topOutside($state), 'nothing is published outside, and the stage is discarded');
                strictAssertSame(true, $after['queued'], 'the queue keeps the Move');
                strictAssertTrue(strpos(topLog($state), 'cross-mount stage failed: destination-outside-top-directory') !== false,
                    'errors.log names the reason');
            } finally { topClean($state); }
        });

// Upstream mode: check names the directory rTorrent records, move carries the
// data there; both run synchronously inside rTorrent and call no daemon.
function topUpstream($state, $source)
{
    list($exit, $after, $calls, $output) = moveWorkerRun($state, 'upstream-check');
    strictAssertSame(0, $exit, 'check completes: ' . $output);
    strictAssertSame($source, $output, 'check leaves the download where its data is');
    strictAssertSame(array(), $calls, 'without calling the daemon');
    strictAssertTrue(strpos(topLog($state), 'autotools: upstream-move ' . $state['hash']
        . ' refused: destination-outside-top-directory') !== false, 'errors.log names the reason');
    $state['upstreamDirectory'] = rtrim($output, '/');
    moveWorkerRun($state, 'upstream-move');
    strictAssertTrue(topPayloadInPlace($state), 'the data stays where it is');
}

foreach (array(false, true) as $multi)
    $suite->test('upstream mode refuses a finished directory that links out multi=' . (int) $multi, function () use ($multi) {
        $state = topFixture($multi);
        try {
            rmdir($state['root'] . '/fin');
            symlink($state['outside'], $state['root'] . '/fin');
            topUpstream($state, $multi ? $state['source'] . '/' : $state['root'] . '/dl/');
            strictAssertSame(array(), topOutside($state), 'nothing is written outside the top directory');
        } finally { topClean($state); }
    });

// A single file's name is checked as a taken destination; a multi-file
// download's name is its directory.
$suite->test('upstream mode refuses a multi-file download name that links out', function () {
    $state = topFixture(true);
    try {
        symlink($state['outside'], $state['destination']);
        topUpstream($state, $state['source'] . '/');
        strictAssertSame(array(), topOutside($state), 'nothing is written outside the top directory');
    } finally { topClean($state); }
});

$suite->test('upstream mode refuses a directory on the way that links out', function () {
    $state = topFixture();
    try {
        topInSubdirectory($state);
        symlink($state['outside'], $state['root'] . '/fin/sub');
        topUpstream($state, $state['root'] . '/dl/sub/');
        strictAssertSame(array(), topOutside($state), 'nothing is written outside the top directory');
    } finally { topClean($state); }
});

// The control for upstream mode: check names the finished directory and move
// carries the data there.
foreach (array(false, true) as $multi)
    $suite->test('upstream mode moves into a finished directory inside the top directory multi=' . (int) $multi,
        function () use ($multi) {
            $state = topFixture($multi);
            try {
                list($exit, $after, $calls, $output) = moveWorkerRun($state, 'upstream-check');
                strictAssertSame($multi ? $state['destination'] . '/' : $state['root'] . '/fin/', $output,
                    'check names the finished directory');
                $state['upstreamDirectory'] = rtrim($output, '/');
                list($exit, $after, $calls, $output) = moveWorkerRun($state, 'upstream-move');
                strictAssertSame(0, $exit, 'move completes: ' . $output);
                strictAssertSame('payload', @file_get_contents($multi ? $state['destination'] . '/item' : $state['destination']),
                    'and carries the data there');
                strictAssertSame(false, file_exists($state['source']), 'and not left at the source');
                strictAssertSame(false, strpos(topLog($state), 'refused'), 'nothing is refused');
            } finally { topClean($state); }
        });

$suite->test('upstream move refuses a finished directory swapped for a link out after its check', function () {
    $state = topFixture();
    try {
        list($exit, $after, $calls, $output) = moveWorkerRun($state, 'upstream-check');
        strictAssertSame($state['root'] . '/fin/', $output, 'check names the finished directory');
        topSwapFinished($state);
        list($exit, $after, $calls, $output) = moveWorkerRun($state, 'upstream-move');
        strictAssertTrue(topPayloadInPlace($state), 'the data stays where it is: ' . $output);
        strictAssertSame(array('fin'), topOutside($state), 'nothing is published outside the top directory');
        strictAssertTrue(strpos(topLog($state), 'autotools: upstream-move ' . $state['hash']
            . ' refused: destination-outside-top-directory') !== false, 'errors.log names the reason');
    } finally { topClean($state); }
});

exit($suite->run());
