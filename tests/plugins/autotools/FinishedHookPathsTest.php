<?php

require_once __DIR__ . '/FinishedHookPathsFixture.php';

$suite = new StrictTestSuite();
foreach (['stored-directory-changed', 'stored-directory-space', 'cold-stored-directory-changed'] as $shape) {
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
        } finally {
            strictRemoveTree($root);
        }
    });
}

$suite->test('a changed single-file stored directory refuses unchanged frozen path and name', function () {
    $root = finishedPathFixture();
    try {
        file_put_contents($root . '/dl/payload.bin', 'FOREIGN old payload');
        file_put_contents(
            $root . '/session/' . str_repeat('A', 40) . '.torrent',
            'd4:infod6:lengthi13e4:name11:payload.bin12:piece lengthi16384e6:pieces20:' . str_repeat("\x01", 20) . 'ee',
        );
        list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'deferred-single', 'Copy', 'stored-directory-changed');
        strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
        $result = json_decode($output, true);
        strictAssertSame(false, file_exists($root . '/fin/pending'), 'the old single-file path cannot authorize copying');
        strictAssertSame(0, $result['pending'], 'the changed stored directory is a visible refusal');
        strictAssertTrue(strpos($result['log'], 'source-context-changed') !== false, 'the changed context is named');
    } finally {
        strictRemoveTree($root);
    }
});

$suite->test('equivalent directory tail slashes retain the captured source identity', function () {
    $root = finishedPathFixture();
    try {
        list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'perform', 'Copy', 'stored-directory-tail');
        strictAssertSame(0, $exit, 'the real hook returns: ' . $error);
        strictAssertSame('published', json_decode($output, true)['status'], 'only separator tails are canonicalized');
    } finally {
        strictRemoveTree($root);
    }
});

foreach (['stored-directory-malformed', 'bad-flag-value'] as $shape) {
    $suite->test('an unknown directory snapshot ' . $shape . ' remains pending', function () use ($shape) {
        $root = finishedPathFixture();
        try {
            list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'deferred', 'Copy', $shape);
            strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
            strictAssertSame(1, json_decode($output, true)['pending'], 'unknown fields are not a proven source change');
            strictAssertSame(false, file_exists($root . '/fin/pending'), 'unknown fields cannot authorize copying');
        } finally {
            strictRemoveTree($root);
        }
    });
}

$suite->test('a cold single-file object retains its request until its captured frozen path can be acknowledged', function () {
    $root = finishedPathFixture();
    try {
        file_put_contents($root . '/dl/payload.bin', 'payload bytes');
        file_put_contents(
            $root . '/session/' . str_repeat('A', 40) . '.torrent',
            'd4:infod6:lengthi13e4:name11:payload.bin12:piece lengthi16384e6:pieces20:' . str_repeat("\x01", 20) . 'ee',
        );
        list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'deferred-single', 'Copy', 'cold-frozen');
        strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
        strictAssertSame(1, json_decode($output, true)['pending'], 'the request remains owned while acknowledgement is unprovable');
        strictAssertSame(false, file_exists($root . '/fin/pending'), 'the request is retained before any copy');
    } finally {
        strictRemoveTree($root);
    }
});

$suite->test('a cold multi-file object retains its request until its captured frozen path can be acknowledged', function () {
    $root = finishedPathFixture();
    try {
        list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'deferred', 'Copy', 'cold-frozen');
        strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
        strictAssertSame(1, json_decode($output, true)['pending'], 'the unconfirmed acknowledgement keeps its request');
        strictAssertSame(false, file_exists($root . '/fin/pending'), 'nothing is copied before acknowledgement can be proved');
    } finally {
        strictRemoveTree($root);
    }
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
        strictAssertSame([], glob($root . '/session/.autotools-file-jobs/*.nonmove.json'), 'no file-operation receipt is stranded');
    } finally {
        strictRemoveTree($root);
    }
});

$suite->test('a single-file object refuses a changed live filename before copying', function () {
    $root = finishedPathFixture();
    try {
        file_put_contents($root . '/dl/payload.bin', 'FOREIGN old payload');
        file_put_contents(
            $root . '/session/' . str_repeat('A', 40) . '.torrent',
            'd4:infod6:lengthi13e4:name11:payload.bin12:piece lengthi16384e6:pieces20:' . str_repeat("\x01", 20) . 'ee',
        );
        list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'deferred-single', 'Copy', 'single-path-changed');
        strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
        $result = json_decode($output, true);
        strictAssertSame(0, $result['pending'], 'a proven different live path retires the stale request');
        strictAssertTrue(strpos($result['log'], 'source-context-changed') !== false, 'the live path change is named');
        strictAssertSame(false, file_exists($root . '/fin/pending'), 'the old frozen path cannot authorize copying a different live file');
    } finally {
        strictRemoveTree($root);
    }
});

foreach (['stale-root', 'stale-token', 'stale-type', 'stale-basename'] as $shape) {
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
        } finally {
            strictRemoveTree($root);
        }
    });
}

$suite->test('a deferred single-file hook refuses a changed source root before copying', function () {
    $root = finishedPathFixture();
    try {
        file_put_contents($root . '/dl/payload.bin', 'FOREIGN old payload');
        file_put_contents($root . '/dl/Current.bin', 'CURRENT live payload');
        file_put_contents(
            $root . '/session/' . str_repeat('A', 40) . '.torrent',
            'd4:infod6:lengthi13e4:name11:payload.bin12:piece lengthi16384e6:pieces20:' . str_repeat("\x01", 20) . 'ee',
        );
        list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'deferred-single', 'Copy', 'stale-root');
        strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
        $result = json_decode($output, true);
        strictAssertSame(false, file_exists($root . '/fin/pending'), 'a stale single-file request cannot create a destination');
        strictAssertSame(0, $result['pending'], 'the superseded request retires visibly');
        strictAssertTrue(strpos($result['log'], 'source-context-changed') !== false, 'the refusal names the stale capture');
        strictAssertSame('FOREIGN old payload', file_get_contents($root . '/dl/payload.bin'), 'the old file is untouched');
    } finally {
        strictRemoveTree($root);
    }
});

foreach (['context-lost', 'context-short', 'context-malformed'] as $shape) {
    $suite->test('an unconfirmed source snapshot ' . $shape . ' retains its deferred request', function () use ($shape) {
        $root = finishedPathFixture();
        try {
            list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'deferred', 'Copy', $shape);
            strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
            strictAssertSame(1, json_decode($output, true)['pending'], 'an unknown context stays retryable');
            strictAssertSame(false, file_exists($root . '/fin/pending'), 'unknown context cannot create a destination');
        } finally {
            strictRemoveTree($root);
        }
    });
}

foreach (['Copy', 'HardLink', 'SoftLink'] as $operation) {
    $suite->test('background ' . $operation . ' uses selected UTF-8 paths through the real XMLRPC reader', function () use ($operation) {
        $root = finishedPathFixture();
        try {
            list($exit, $output, $error, $requests, $methods) = finishedPathRun($root, 'Серия.ass', 'perform', $operation);
            strictAssertSame(0, $exit, 'the real hook child succeeds: ' . $error);
            strictAssertSame('published', json_decode($output, true)['status'], 'selected-path operation publishes');
            strictAssertSame('payload bytes', file_get_contents($root . '/fin/pending/Root/Серия.ass'), 'the actual selected path is used');
            strictAssertSame('payload bytes', file_get_contents($root . '/dl/Root/Серия.ass'), 'source bytes are retained');
            strictAssertSame(['system.multicall'], $methods, 'source context and paths share one read-only callback');
            strictAssertTrue(strpos($requests[0], '<string>f.path=</string>') !== false, 'the shared alias factory builds the native getter');
        } finally {
            strictRemoveTree($root);
        }
    });
}

$suite->test('a selected UTF-8 skip filter runs before destination creation', function () {
    $root = finishedPathFixture();
    try {
        list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'perform', 'Copy', 'valid', '/Серия/');
        strictAssertSame(0, $exit, 'the real hook child succeeds: ' . $error);
        strictAssertSame('none', json_decode($output, true)['status'], 'the filter matches the selected path');
        strictAssertSame(false, file_exists($root . '/fin/pending'), 'a skipped alias creates no destination');
    } finally {
        strictRemoveTree($root);
    }
});

$suite->test('recovery publishes a selected-path hook through its existing deferred request', function () {
    $root = finishedPathFixture();
    try {
        list($exit, $output, $error, , $methods) = finishedPathRun($root, 'Серия.ass', 'deferred');
        strictAssertSame(0, $exit, 'the real recovery returns: ' . $error);
        strictAssertSame(0, json_decode($output, true)['pending'], 'successful recovery retires its request');
        strictAssertSame('payload bytes', file_get_contents($root . '/fin/pending/Root/Серия.ass'), 'the selected path is copied');
        strictAssertSame(
            1,
            count(array_filter($methods, function ($method) {
                return $method === 'system.multicall';
            })),
            'background recovery resolves the paths once',
        );
    } finally {
        strictRemoveTree($root);
    }
});

foreach (['lost', 'fault'] as $shape) {
    $suite->test('deferred path read ' . $shape . ' stays pending inside its retry window', function () use ($shape) {
        $root = finishedPathFixture();
        try {
            list($exit, $output, $error, , $methods) = finishedPathRun($root, 'Серия.ass', 'deferred', 'Copy', $shape);
            strictAssertSame(0, $exit, 'the real hook child returns: ' . $error);
            strictAssertSame(1, json_decode($output, true)['pending'], 'an unavailable daemon reply does not retire the request');
            strictAssertSame(['system.multicall'], $methods, 'only background recovery reads the source snapshot');
            strictAssertSame(false, file_exists($root . '/fin/pending'), 'unknown paths create no destination');
        } finally {
            strictRemoveTree($root);
        }
    });
}

foreach (['integer', 'extra'] as $shape) {
    $suite->test('malformed selected-path ' . $shape . ' reply refuses before filesystem work', function () use ($shape) {
        $root = finishedPathFixture();
        try {
            list($exit, $output, $error) = finishedPathRun($root, 'Серия.ass', 'perform', 'Copy', $shape);
            strictAssertSame(0, $exit, 'the real hook child returns: ' . $error);
            $result = json_decode($output, true);
            strictAssertSame(['refused', 'file-list-invalid'], [$result['status'], $result['reason']], 'malformed rows are a visible classified refusal');
            strictAssertSame(false, file_exists($root . '/fin/pending'), 'unproven list creates no destination');
            strictAssertSame('payload bytes', file_get_contents($root . '/dl/Root/Серия.ass'), 'source bytes remain untouched');
        } finally {
            strictRemoveTree($root);
        }
    });
}

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
    } finally {
        strictRemoveTree($root);
    }
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
    } finally {
        strictRemoveTree($root);
    }
});

exit($suite->run());
