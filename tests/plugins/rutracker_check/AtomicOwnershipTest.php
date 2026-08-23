<?php

require_once(__DIR__ . '/TestLib.php');

$suite = new StrictTestSuite();

$validHash = str_repeat('A', 40);
$validMarker = str_repeat('B', 32);
$validRecord = RuTrackerReplacementRecord::encode(str_repeat('C', 40), true, true, 1234567890);

$suite->test('conditional erase builds exactly one top-level branch command with exact arguments', function () use ($validHash, $validMarker, $validRecord) {
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));

    $result = RuTrackerAtomicOwnership::erase(
        $validHash,
        array(
            'chk-replacement' => $validMarker,
            'chk-replaces' => $validRecord,
        ),
        array(
            'state' => 0,
            'is_open' => 0,
        )
    );

    strictAssertSame(RuTrackerAtomicOwnership::ACTED, $result, 'ERASED sentinel maps to ACTED');
    strictAssertSame(1, count(rXMLRPCRequest::$requests), 'exactly one XMLRPC request was issued');
    $req = rXMLRPCRequest::$requests[0];
    strictAssertSame('branch', $req['key'], 'command key is branch');
    strictAssertSame(1, count($req['commands']), 'exactly one command in request (not multicall)');
    $cmd = $req['commands'][0];
    strictAssertSame('branch', $cmd->command, 'command is branch');
    strictAssertSame(4, count($cmd->params), 'branch has 4 parameters (hash, cond, true, false)');
    strictAssertSame($validHash, $cmd->params[0], 'target is the target hash');

    // Condition should test custom keys and state predicates
    $cond = $cmd->params[1];
    strictAssertTrue(strpos($cond, 'equal=' . getCmd('d.get_custom=') . 'chk-replacement,cat=' . $validMarker) !== false,
        'condition contains exact chk-replacement equality');
    strictAssertTrue(strpos($cond, 'equal=' . getCmd('d.get_custom=') . 'chk-replaces,cat=' . $validRecord) !== false,
        'condition contains exact chk-replaces equality');
    strictAssertTrue(strpos($cond, 'equal=' . getCmd('d.get_state=') . ',value=0') !== false,
        'condition contains state == 0');
    strictAssertTrue(strpos($cond, 'equal=' . getCmd('d.is_open=') . ',value=0') !== false,
        'condition contains is_open == 0');

    // True body should execute d.erase and return SENTINEL_ERASED
    $trueBody = $cmd->params[2];
    strictAssertTrue(strpos($trueBody, '$' . getCmd('d.erase=')) !== false, 'true body includes $d.erase=');
    strictAssertTrue(strpos($trueBody, RuTrackerAtomicOwnership::SENTINEL_ERASED) !== false, 'true body ends with ERASED sentinel');

    // False body should return SENTINEL_SKIPPED
    $falseBody = $cmd->params[3];
    strictAssertSame('cat=' . RuTrackerAtomicOwnership::SENTINEL_SKIPPED, $falseBody, 'false body is cat=SKIPPED');
});

$suite->test('compound ownership condition has the exact rTorrent 0.9.8 quoting shape', function () use ($validHash, $validMarker, $validRecord) {
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));

    RuTrackerAtomicOwnership::erase(
        $validHash,
        array(
            'chk-replacement' => $validMarker,
            'chk-replaces' => $validRecord,
        ),
        array('state' => 0, 'is_open' => 0)
    );

    $command = rXMLRPCRequest::$requests[0]['commands'][0];
    $expected = 'and="equal=d.get_custom=chk-replacement,cat=' . $validMarker
        . '","equal=d.get_custom=chk-replaces,cat=' . $validRecord
        . '","equal=d.get_state=,value=0","equal=d.is_open=,value=0"';
    strictAssertSame($expected, $command->params[1],
        'whole atoms are quoted once and validated values do not introduce nested raw quotes');
});

$suite->test('empty expected custom is represented by an exact empty cat command', function () use ($validHash) {
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

    RuTrackerAtomicOwnership::clearCustoms(
        $validHash,
        array('chk-revived' => '', 'chk-replacing' => ''),
        array('chk-revived')
    );

    $condition = rXMLRPCRequest::$requests[0]['commands'][0]->params[1];
    strictAssertSame(
        'and="equal=d.get_custom=chk-revived,cat=","equal=d.get_custom=chk-replacing,cat="',
        $condition,
        'empty equality remains one parseable atom rather than an unterminated nested quote'
    );
});

$suite->test('conditional clearCustoms clears keys in specified order and validates ownership', function () use ($validHash, $validMarker, $validRecord) {
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));

    $result = RuTrackerAtomicOwnership::clearCustoms(
        $validHash,
        array(
            'chk-replacement' => $validMarker,
            'chk-replaces' => $validRecord,
        ),
        array('chk-replaces', 'chk-replacement')
    );

    strictAssertSame(RuTrackerAtomicOwnership::ACTED, $result, 'CLEARED sentinel maps to ACTED');
    strictAssertSame(1, count(rXMLRPCRequest::$requests), 'exactly one XMLRPC request was issued');
    $cmd = rXMLRPCRequest::$requests[0]['commands'][0];
    $trueBody = $cmd->params[2];

    // Verify clear order: chk-replaces first, chk-replacement second
    $posReplaces = strpos($trueBody, '$' . getCmd('d.set_custom=') . 'chk-replaces,');
    $posReplacement = strpos($trueBody, '$' . getCmd('d.set_custom=') . 'chk-replacement,');
    strictAssertTrue($posReplaces !== false && $posReplacement !== false, 'both keys are cleared');
    strictAssertTrue($posReplaces < $posReplacement, 'chk-replaces is cleared before chk-replacement');
    strictAssertTrue(strpos($trueBody, RuTrackerAtomicOwnership::SENTINEL_CLEARED) !== false, 'true body ends with CLEARED sentinel');
});

$suite->test('setting a positive deadline has one quoted mutation argument and no raw nested quotes', function () use ($validHash) {
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
    $owner = str_repeat('C', 40);

    RuTrackerAtomicOwnership::setCustoms(
        $validHash,
        array('chk-meta-old' => $owner),
        array('chk-meta-until' => '1234567890')
    );

    $command = rXMLRPCRequest::$requests[0]['commands'][0];
    strictAssertSame('equal=d.get_custom=chk-meta-old,cat=' . $owner, $command->params[1],
        'single exact ownership atom needs no extra quoting');
    strictAssertSame(
        'cat="$d.set_custom=chk-meta-until,1234567890",RUT_ATOMIC_ACTED',
        $command->params[2],
        'validated numeric value is inside the one quoted mutation argument'
    );
});

$suite->test('conditional runState builds open+start and nested state verification for started policy', function () use ($validHash, $validMarker, $validRecord) {
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));

    $result = RuTrackerAtomicOwnership::runState(
        $validHash,
        array(
            'chk-replacement' => $validMarker,
            'chk-replaces' => $validRecord,
        ),
        true, // wantStarted
        array('state' => 0, 'is_open' => 0),
        array('chk-replaces' => '', 'chk-replacement' => '') // afterSuccess
    );

    strictAssertSame(RuTrackerAtomicOwnership::ACTED, $result, 'ACTED sentinel maps to ACTED');
    strictAssertSame(1, count(rXMLRPCRequest::$requests), 'exactly one request issued');
    $cmd = rXMLRPCRequest::$requests[0]['commands'][0];
    $trueBody = $cmd->params[2];

    strictAssertTrue(strpos($trueBody, '$' . getCmd('d.open=')) !== false, 'true body includes $d.open=');
    strictAssertTrue(strpos($trueBody, '$' . getCmd('d.start=')) !== false, 'true body includes $d.start=');
    strictAssertTrue(strpos($trueBody, '$branch=' . getCmd('d.get_state=')) !== false, 'nested branch verifies d.state');
    strictAssertTrue(strpos($trueBody, RuTrackerAtomicOwnership::SENTINEL_UNCONFIRMED) !== false, 'nested branch false body is UNCONFIRMED');
    strictAssertTrue(strpos($trueBody, RuTrackerAtomicOwnership::SENTINEL_ACTED) !== false, 'nested branch success includes ACTED');
});

$suite->test('conditional runState has exact recursively quoted nested branch grammar', function () use ($validHash, $validMarker, $validRecord) {
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));

    RuTrackerAtomicOwnership::runState(
        $validHash,
        array('chk-replacement' => $validMarker, 'chk-replaces' => $validRecord),
        true,
        array('state' => 0, 'is_open' => 0),
        array('chk-replaces' => '', 'chk-replacement' => '')
    );

    $trueBody = rXMLRPCRequest::$requests[0]['commands'][0]->params[2];
    $expected = <<<'RTORRENT'
cat="$d.open=","$d.start=","$branch=d.get_state=,\"cat=\\\"$d.set_custom=chk-replaces,\\\",\\\"$d.set_custom=chk-replacement,\\\",RUT_ATOMIC_ACTED\",cat=RUT_ATOMIC_UNCONFIRMED"
RTORRENT;
    strictAssertSame($expected, $trueBody,
        'open/start and nested verification are each one quoted cat argument');
});

$suite->test('conditional runState builds open only and verifies is_open for open policy', function () use ($validHash, $validMarker, $validRecord) {
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));

    $result = RuTrackerAtomicOwnership::runState(
        $validHash,
        array(
            'chk-replacement' => $validMarker,
            'chk-replaces' => $validRecord,
        ),
        false, // wantStarted = false
        array('state' => 0, 'is_open' => 0)
    );

    strictAssertSame(RuTrackerAtomicOwnership::ACTED, $result, 'open policy ACTED');
    $cmd = rXMLRPCRequest::$requests[0]['commands'][0];
    $trueBody = $cmd->params[2];

    strictAssertTrue(strpos($trueBody, '$' . getCmd('d.open=')) !== false, 'true body includes $d.open=');
    strictAssertTrue(strpos($trueBody, '$' . getCmd('d.start=')) === false, 'true body does NOT include $d.start=');
    strictAssertTrue(strpos($trueBody, '$branch=' . getCmd('d.is_open=')) !== false, 'nested branch verifies d.is_open');
});

$suite->test('conditional revival checks spent stamp and updates chk-revived on success', function () use ($validHash) {
    $expectedReplacing = RuTrackerReplacementRecord::encode(str_repeat('C', 40), true, true, 1234567890);
    $recordedRun = array('started' => true, 'open' => true);
    $stamp = 1234567890;

    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_REVIVED));

    $result = RuTrackerAtomicOwnership::revivePredecessor(
        $validHash,
        $expectedReplacing,
        $recordedRun,
        $stamp
    );

    strictAssertSame(RuTrackerAtomicOwnership::ACTED, $result, 'REVIVED sentinel maps to ACTED');
    strictAssertSame(1, count(rXMLRPCRequest::$requests), 'exactly one request issued');
    $cmd = rXMLRPCRequest::$requests[0]['commands'][0];

    $cond = $cmd->params[1];
    strictAssertTrue(strpos($cond, 'equal=' . getCmd('d.get_custom=') . 'chk-replacing,cat=' . $expectedReplacing) !== false,
        'predecessor condition contains exact chk-replacing');

    $trueBody = $cmd->params[2];
    strictAssertTrue(strpos($trueBody, 'equal=' . getCmd('d.get_custom=') . 'chk-revived,cat=' . $stamp) !== false,
        'nested branch checks if chk-revived equals stamp');
    strictAssertTrue(strpos($trueBody, RuTrackerAtomicOwnership::SENTINEL_SPENT) !== false,
        'nested branch contains SPENT sentinel');
    strictAssertTrue(strpos($trueBody, '$' . getCmd('d.set_custom=') . 'chk-revived,' . $stamp) !== false,
        'success branch writes chk-revived');
    strictAssertTrue(strpos($trueBody, '$' . getCmd('d.set_custom=') . 'chk-replacing,') !== false,
        'success branch clears chk-replacing');
    strictAssertTrue(strpos($trueBody, RuTrackerAtomicOwnership::SENTINEL_REVIVED) !== false,
        'success branch returns REVIVED sentinel');
});

$suite->test('conditional revival has the exact nested spent and verify grammar', function () use ($validHash) {
    $expectedReplacing = RuTrackerReplacementRecord::encode(str_repeat('C', 40), true, true, 1234567890);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_REVIVED));

    RuTrackerAtomicOwnership::revivePredecessor(
        $validHash,
        $expectedReplacing,
        array('started' => true, 'open' => true),
        1234567890
    );

    $command = rXMLRPCRequest::$requests[0]['commands'][0];
    $expectedCondition = <<<'RTORRENT'
and="equal=d.get_custom=chk-replacing,cat=CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC-started-1234567890","equal=d.get_state=,value=0","equal=d.is_open=,value=0"
RTORRENT;
    $expectedTrueBody = <<<'RTORRENT'
branch="equal=d.get_custom=chk-revived,cat=1234567890","cat=RUT_ATOMIC_SPENT","cat=\"$d.open=\",\"$d.start=\",\"$branch=d.get_state=,\\\"cat=\\\\\\\"$d.set_custom=chk-revived,1234567890\\\\\\\",\\\\\\\"$d.set_custom=chk-replacing,\\\\\\\",RUT_ATOMIC_REVIVED\\\",cat=RUT_ATOMIC_UNCONFIRMED\""
RTORRENT;
    strictAssertSame($expectedCondition, $command->params[1],
        'revival always rechecks exact generation and stopped/closed predicates');
    strictAssertSame($expectedTrueBody, $command->params[2],
        'spent check, run, verification, stamp and clear form one exact nested command');
});

$suite->test('revival cannot weaken mandatory stopped and closed predicates', function () use ($validHash) {
    $expectedReplacing = RuTrackerReplacementRecord::encode(str_repeat('C', 40), true, true, 1234567890);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_REVIVED));

    RuTrackerAtomicOwnership::revivePredecessor(
        $validHash,
        $expectedReplacing,
        array('started' => true, 'open' => true),
        1234567890,
        array('state' => 1, 'is_open' => 1)
    );

    $condition = rXMLRPCRequest::$requests[0]['commands'][0]->params[1];
    strictAssertTrue(strpos($condition, 'equal=d.get_state=,value=0') !== false,
        'caller cannot replace mandatory state=0');
    strictAssertTrue(strpos($condition, 'equal=d.is_open=,value=0') !== false,
        'caller cannot replace mandatory is_open=0');
    strictAssertTrue(strpos($condition, 'value=1') === false,
        'conflicting caller predicates never weaken revival ownership');
});

$suite->test('revival rejects stopped and incoherent run policies before issuing XMLRPC', function () use ($validHash) {
    $expectedReplacing = RuTrackerReplacementRecord::encode(str_repeat('C', 40), true, true, 1234567890);
    $badRuns = array(
        array('started' => false, 'open' => false),
        array('started' => true, 'open' => false),
        array('started' => 1, 'open' => true),
        array('started' => true),
    );
    foreach ($badRuns as $run) {
        rXMLRPCRequest::reset();
        $status = RuTrackerAtomicOwnership::revivePredecessor(
            $validHash, $expectedReplacing, $run, 1234567890
        );
        strictAssertSame(RuTrackerAtomicOwnership::UNKNOWN, $status,
            'unsafe run policy fails closed');
        strictAssertSame(0, count(rXMLRPCRequest::$requests),
            'unsafe run policy sends zero open/start requests');
    }
});

$suite->test('production-shaped rTorrent 0.9.8 aliases preserve nested ownership DSL', function () use ($validHash, $validMarker) {
    $GLOBALS['testCommandAliases'] = array(
        'd.get_custom' => 'd.custom',
        'd.set_custom' => 'd.custom.set',
        'd.get_state' => 'd.state',
    );
    try {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('branch', true, false,
            array(RuTrackerAtomicOwnership::SENTINEL_ACTED));

        $status = RuTrackerAtomicOwnership::runState(
            $validHash,
            array('chk-replacement' => $validMarker),
            true,
            array('state' => 0, 'is_open' => 0),
            array('chk-replacement' => '')
        );

        strictAssertSame(RuTrackerAtomicOwnership::ACTED, $status,
            'aliased production command accepts the same strict sentinel contract');
        $params = rXMLRPCRequest::$requests[0]['commands'][0]->params;
        $expectedCondition = 'and="equal=d.custom=chk-replacement,cat=' . $validMarker
            . '","equal=d.state=,value=0","equal=d.is_open=,value=0"';
        $expectedTrueBody = <<<'RTORRENT'
cat="$d.open=","$d.start=","$branch=d.state=,\"cat=\\\"$d.custom.set=chk-replacement,\\\",RUT_ATOMIC_ACTED\",cat=RUT_ATOMIC_UNCONFIRMED"
RTORRENT;
        strictAssertSame($expectedCondition, $params[1],
            'the entire condition preserves 0.9.8 aliases and exact quoting');
        strictAssertSame($expectedTrueBody, $params[2],
            'the entire nested success body preserves 0.9.8 aliases and escaping');
    }
    finally {
        unset($GLOBALS['testCommandAliases']);
    }
});

$suite->test('sentinel responses and error paths map to expected atomic status', function () use ($validHash, $validMarker) {
    $matrix = array(
        array(true, false, array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED), RuTrackerAtomicOwnership::SKIPPED, 'SKIPPED sentinel'),
        array(true, false, array(RuTrackerAtomicOwnership::SENTINEL_UNCONFIRMED), RuTrackerAtomicOwnership::UNKNOWN, 'UNCONFIRMED from erase'),
        array(true, false, array(RuTrackerAtomicOwnership::SENTINEL_SPENT), RuTrackerAtomicOwnership::UNKNOWN, 'SPENT from erase'),
        array(true, false, array('UNKNOWN_TOKEN'), RuTrackerAtomicOwnership::UNKNOWN, 'unknown token'),
        array(true, false, array(), RuTrackerAtomicOwnership::UNKNOWN, 'empty value list'),
        array(true, false, array('a', 'b'), RuTrackerAtomicOwnership::UNKNOWN, 'multiple values'),
        array(false, true, array(), RuTrackerAtomicOwnership::UNKNOWN, 'XMLRPC fault'),
        array(false, false, array(), RuTrackerAtomicOwnership::UNKNOWN, 'transport failure'),
    );

    foreach ($matrix as $entry) {
        list($ok, $fault, $vals, $expectedStatus, $desc) = $entry;
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('branch', $ok, $fault, $vals);

        $status = RuTrackerAtomicOwnership::erase($validHash, array('chk-replacement' => $validMarker), array('state' => 0));
        strictAssertSame($expectedStatus, $status, $desc . ' maps correctly');
    }
});

$suite->test('sentinels from another atomic operation are rejected as UNKNOWN', function () use ($validHash, $validMarker) {
    $cases = array(
        'erase rejects CLEARED' => array('sentinel' => RuTrackerAtomicOwnership::SENTINEL_CLEARED,
            'call' => function () use ($validHash, $validMarker) {
                return RuTrackerAtomicOwnership::erase($validHash,
                    array('chk-replacement' => $validMarker), array('state' => 0));
            }),
        'clear rejects ERASED' => array('sentinel' => RuTrackerAtomicOwnership::SENTINEL_ERASED,
            'call' => function () use ($validHash, $validMarker) {
                return RuTrackerAtomicOwnership::clearCustoms($validHash,
                    array('chk-replacement' => $validMarker), array('chk-replacement'));
            }),
        'set rejects REVIVED' => array('sentinel' => RuTrackerAtomicOwnership::SENTINEL_REVIVED,
            'call' => function () use ($validHash, $validMarker) {
                return RuTrackerAtomicOwnership::setCustoms($validHash,
                    array('chk-replacement' => $validMarker), array('chk-replacement' => ''));
            }),
        'run rejects ERASED' => array('sentinel' => RuTrackerAtomicOwnership::SENTINEL_ERASED,
            'call' => function () use ($validHash, $validMarker) {
                return RuTrackerAtomicOwnership::runState($validHash,
                    array('chk-replacement' => $validMarker), true, array('state' => 0));
            }),
        'revive rejects ACTED' => array('sentinel' => RuTrackerAtomicOwnership::SENTINEL_ACTED,
            'call' => function () use ($validHash) {
                return RuTrackerAtomicOwnership::revivePredecessor(
                    $validHash,
                    RuTrackerReplacementRecord::encode(str_repeat('C', 40), true, true, 1234567890),
                    array('started' => true, 'open' => true),
                    1234567890
                );
            }),
    );

    foreach ($cases as $label => $case) {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('branch', true, false, array($case['sentinel']));
        strictAssertSame(RuTrackerAtomicOwnership::UNKNOWN, call_user_func($case['call']),
            $label);
    }
});

$suite->test('non-string branch scalars are never coerced into valid sentinels', function () use ($validHash, $validMarker) {
    $values = array(0, false, null, array(), new stdClass());
    foreach ($values as $value) {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('branch', true, false, function () use ($value) {
            return array($value);
        });
        $status = RuTrackerAtomicOwnership::erase(
            $validHash, array('chk-replacement' => $validMarker), array('state' => 0)
        );
        strictAssertSame(RuTrackerAtomicOwnership::UNKNOWN, $status,
            'non-string scalar/object/array response maps to UNKNOWN');
    }
});

$suite->test('invalid grammar parameters fail closed with UNKNOWN and zero requests', function () use ($validHash, $validMarker) {
    $badInputs = array(
        array('not-a-hash', array('chk-replacement' => $validMarker), array()),
        array($validHash, array('unknown-custom-key' => 'value'), array()),
        array($validHash, array('chk-replacement' => 'not-32-hex'), array()),
        array($validHash, array('chk-replaces' => 'malformed-record'), array()),
        array($validHash, array('chk-meta-until' => '-100'), array()),
        array($validHash, array('chk-meta-until' => '0100'), array()),
        array($validHash, array('chk-meta-until' => 'invalid'), array()),
        array($validHash, array('chk-replacement' => $validMarker), array('state' => 2)),
        array($validHash, array('chk-replacement' => $validMarker), array('invalid_predicate' => 0)),
    );

    foreach ($badInputs as $bad) {
        rXMLRPCRequest::reset();
        $status = RuTrackerAtomicOwnership::erase($bad[0], $bad[1], $bad[2]);
        strictAssertSame(RuTrackerAtomicOwnership::UNKNOWN, $status, 'bad input fails closed with UNKNOWN');
        strictAssertSame(0, count(rXMLRPCRequest::$requests), 'zero XMLRPC requests sent for bad input');
    }
});

exit($suite->run());
