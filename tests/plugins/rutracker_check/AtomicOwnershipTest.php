<?php

require_once(__DIR__ . '/TestLib.php');
require_once(__DIR__ . '/../../php/RtorrentCommandParser.php');

$suite = new StrictTestSuite();

$validHash = str_repeat('A', 40);
$validMarker = str_repeat('B', 32);
$validRecord = RuTrackerReplacementRecord::encode(str_repeat('C', 40), true, true, 1234567890);

$suite->test('fast projection refuses substituted and truncated values before any RPC', function () use ($validHash) {
    foreach (array('$execute=touch,/tmp/forbidden', "\0hidden", "visible\0hidden") as $value) {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
        strictAssertSame(RuTrackerAtomicOwnership::UNKNOWN,
            RuTrackerAtomicOwnership::setFastProjection($validHash, str_repeat('B', 40), array('chk-msg' => $value)),
            'unrepresentable custom value is refused');
        strictAssertSame(array(), rXMLRPCRequest::$requests, 'refusal sends no mutation');
    }
});

$suite->test('fast projection preserves literal grammar bytes and its exact incarnation guard', function () use ($validHash) {
    foreach (array('', '0', 'fuse|a"b\\c,d;e' . "\n" . '$f{g}', "o'neil", ' leading $ literal') as $value) {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
        strictAssertSame(RuTrackerAtomicOwnership::ACTED,
            RuTrackerAtomicOwnership::setFastProjection($validHash, str_repeat('B', 40), array('chk-msg' => $value)),
            'representable custom value is accepted');
        $command = rXMLRPCRequest::$requests[0]['commands'][0];
        strictAssertSame($validHash, $command->params[0], 'the projection targets the exact hash');
        strictAssertTrue(strpos($command->params[1], str_repeat('B', 40)) !== false,
            'the projection checks the exact local incarnation');
        $body = RtorrentCommandParser::commands($command->params[2]);
        strictAssertSame(1, count($body), 'one cat command encloses the guarded mutation');
        $setter = RtorrentCommandParser::commands(substr($body[0]['args'][0], 1));
        strictAssertSame(array('chk-msg', $value), $setter[0]['args'], 'the daemon parser receives the original bytes');
        strictAssertSame(array(), RtorrentCommandParser::substitutedArguments($setter[0]['args']),
            'no custom value becomes a nested command');
    }
});

$suite->test('atomic predicates must be arrays and retain malformed values as refusals', function () use ($validHash, $validRecord) {
    foreach (array(null, false, '', 0, new stdClass()) as $invalid) {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
        strictAssertSame(RuTrackerAtomicOwnership::UNKNOWN,
            RuTrackerAtomicOwnership::setFastProjection($validHash, str_repeat('B', 40),
                array('chk-msg' => 'expected'), $invalid),
            'an invalid expected-custom projection cannot silently become an unguarded write');
        strictAssertSame(array(), rXMLRPCRequest::$requests, 'malformed projection predicates send no command');
    }
    foreach (array(null, false, '', 0, new stdClass(), array('state' => 2),
        array('is_open' => null)) as $invalid) {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_REVIVED));
        strictAssertSame(RuTrackerAtomicOwnership::UNKNOWN,
            RuTrackerAtomicOwnership::revivePredecessor($validHash, $validRecord,
                array('started' => true, 'open' => true), 1234567890, $invalid),
            'revival refuses invalid predicates before enforcing stopped and closed');
        strictAssertSame(array(), rXMLRPCRequest::$requests, 'invalid revival predicates send no command');
    }
});

$suite->test('clear-only replacement release cannot acknowledge an erase reply', function () use ($validHash, $validMarker, $validRecord) {
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));
    strictAssertSame(RuTrackerAtomicOwnership::UNKNOWN,
        RuTrackerAtomicOwnership::closeReplacement($validHash, $validMarker, $validRecord),
        'a clear-only command cannot emit the erase sentinel');
    strictAssertSame(1, count(rXMLRPCRequest::$requests), 'an unexpected reply is not replayed');
});

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
        . '","equal=d.get_custom=chk-reuse,cat='
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

$suite->test('reused successor claim writes protection then requests a session save before success', function () use ($validHash, $validMarker, $validRecord) {
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
    $localId = str_repeat('D', 40);
    strictAssertSame(RuTrackerAtomicOwnership::ACTED, RuTrackerAtomicOwnership::setCustoms(
        $validHash,
        array('chk-reuse' => '', 'chk-replaces' => '', 'chk-replacement' => ''),
        array('chk-reuse' => $validMarker, 'chk-replaces' => $validRecord, 'chk-replacement' => $validMarker),
        array('local_id' => $localId, 'is_meta' => 0), true
    ), 'one guarded claim accepts the reuse marker');
    strictAssertSame(1, count(rXMLRPCRequest::$requests), 'claim sends one request');
    $command = rXMLRPCRequest::$requests[0]['commands'][0];
    strictAssertSame('branch', $command->command, 'claim is a synchronous branch');
    strictAssertSame($validHash, $command->params[0], 'claim targets the successor');
    $condition = RtorrentCommandParser::commands($command->params[1]);
    strictAssertSame('and', $condition[0]['name'], 'the claim combines ownership and incarnation predicates');
    strictAssertSame(5, count($condition[0]['args']), 'all three empty keys and both value predicates are guarded');
    foreach (array('chk-reuse', 'chk-replaces', 'chk-replacement') as $key) {
        strictAssertTrue(in_array('equal=d.get_custom=' . $key . ',cat=', $condition[0]['args'], true),
            'claim requires the ' . $key . ' key to be empty');
    }
    $identity = RtorrentCommandParser::commands($condition[0]['args'][3]);
    strictAssertSame('d.get_local_id=', $identity[0]['args'][0], 'claim reads the daemon incarnation');
    $identityValue = RtorrentCommandParser::commands($identity[0]['args'][1]);
    strictAssertSame(array($localId), $identityValue[0]['args'], 'the exact incarnation survives nested quoting');
    $body = RtorrentCommandParser::commands($command->params[2]);
    strictAssertSame(array(array('name' => 'cat', 'args' => array(
        '$d.set_custom=chk-reuse,' . $validMarker,
        '$d.set_custom=chk-replaces,' . $validRecord,
        '$d.set_custom=chk-replacement,' . $validMarker,
        '$branch=d.save_full_session=,cat=' . RuTrackerAtomicOwnership::SENTINEL_ACTED
            . ',cat=' . RuTrackerAtomicOwnership::SENTINEL_ACTED,
    ))), $body, 'all claim keys precede the queued session save and success sentinel');
});

$suite->test('session save return values do not prefix the exact claim sentinel', function () use ($validHash, $validMarker) {
    foreach (array(0, 1) as $saveResult) {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
        RuTrackerAtomicOwnership::setCustoms($validHash, array('chk-reuse' => ''),
            array('chk-reuse' => $validMarker), array(), true);
        $effects = array();
        // Evaluate only the generated cat/branch sequence. The real legacy
        // daemon returns integer 1 from save_full_session.
        $evaluate = function ($source) use (&$evaluate, &$effects, $saveResult) {
            $commands = RtorrentCommandParser::commands($source);
            strictAssertSame(1, count($commands), 'each generated value contains one command');
            $command = $commands[0];
            if ($command['name'] === 'cat') {
                $result = '';
                foreach ($command['args'] as $arg)
                    $result .= isset($arg[0]) && $arg[0] === '$' ? $evaluate(substr($arg, 1)) : $arg;
                return $result;
            }
            if ($command['name'] === 'branch') {
                strictAssertSame(3, count($command['args']), 'save branch has exactly two outcomes');
                return $evaluate($command['args'][$evaluate($command['args'][0]) ? 1 : 2]);
            }
            if ($command['name'] === 'd.set_custom') {
                $effects[] = $command['args'][0];
                return '';
            }
            strictAssertSame('d.save_full_session', $command['name'], 'only the session save follows setters');
            $effects[] = 'save';
            return $saveResult;
        };
        $reply = $evaluate(rXMLRPCRequest::$requests[0]['commands'][0]->params[2]);
        strictAssertSame(RuTrackerAtomicOwnership::SENTINEL_ACTED, $reply,
            'integer save result is consumed instead of concatenated into success');
        strictAssertSame(array('chk-reuse', 'save'), $effects, 'save executes exactly once after the setter');

        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('branch', true, false, array($saveResult . RuTrackerAtomicOwnership::SENTINEL_ACTED));
        strictAssertSame(RuTrackerAtomicOwnership::UNKNOWN,
            RuTrackerAtomicOwnership::setCustoms($validHash, array('chk-reuse' => ''),
                array('chk-reuse' => $validMarker), array(), true),
            'a prefixed success reply is still rejected rather than weakening sentinel validation');
    }
});

$suite->test('custom session saves are opt-in and reject non-boolean selectors', function () use ($validHash, $validMarker) {
    foreach (array(null, 0, 1, 'true', array()) as $invalid) {
        rXMLRPCRequest::reset();
        strictAssertSame(RuTrackerAtomicOwnership::UNKNOWN,
            RuTrackerAtomicOwnership::setCustoms($validHash, array('chk-reuse' => ''),
                array('chk-reuse' => $validMarker), array(), $invalid),
            'a non-boolean save selector refuses before RPC');
        strictAssertSame(array(), rXMLRPCRequest::$requests, 'invalid save selection sends no mutation');
    }
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
    RuTrackerAtomicOwnership::setCustoms($validHash, array('chk-reuse' => ''), array('chk-reuse' => $validMarker));
    $body = RtorrentCommandParser::commands(rXMLRPCRequest::$requests[0]['commands'][0]->params[2]);
    strictAssertSame(array('$d.set_custom=chk-reuse,' . $validMarker, RuTrackerAtomicOwnership::SENTINEL_ACTED),
        $body[0]['args'], 'ordinary custom writes retain their existing command sequence');
});

$suite->test('replacement release clears reuse last and confines erase to the stopped ordinary branch', function () use ($validHash, $validMarker, $validRecord) {
    foreach (array(false, true) as $discard) {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED));
        $localId = str_repeat('D', 40);
        strictAssertSame(RuTrackerAtomicOwnership::ACTED,
            RuTrackerAtomicOwnership::closeReplacement($validHash, $validMarker, $validRecord,
                array('local_id' => $localId, 'is_meta' => 0, 'state' => 1, 'is_open' => 1), $discard),
            'reuse release is accepted for either cleanup policy');
        strictAssertSame(1, count(rXMLRPCRequest::$requests), 'both alternatives execute in one request');
        strictAssertSame(1, count(rXMLRPCRequest::$requests[0]['commands']), 'cleanup does not split its guards into a multicall');
        $command = rXMLRPCRequest::$requests[0]['commands'][0];
        strictAssertSame('branch', $command->command, 'cleanup uses a synchronous branch');
        strictAssertSame($validHash, $command->params[0], 'cleanup targets the successor');
        $reuse = RtorrentCommandParser::commands($command->params[1]);
        strictAssertSame('and', $reuse[0]['name'], 'reuse requires the whole ownership triple');
        strictAssertSame(5, count($reuse[0]['args']), 'reuse keeps identity and metadata guards but ignores run state');
        strictAssertSame(array(
            'equal=d.get_custom=chk-replacement,cat=' . $validMarker,
            'equal=d.get_custom=chk-replaces,cat=' . $validRecord,
            'equal=d.get_custom=chk-reuse,cat=' . $validMarker,
        ), array_slice($reuse[0]['args'], 0, 3), 'reuse must match this exact transaction');
        $clear = RtorrentCommandParser::commands($command->params[2]);
        strictAssertSame(array(array('name' => 'cat', 'args' => array(
            '$d.set_custom=chk-replaces,', '$d.set_custom=chk-replacement,', '$d.set_custom=chk-reuse,',
            RuTrackerAtomicOwnership::SENTINEL_CLEARED,
        ))), $clear, 'reuse only clears ownership, with protection last and no erase or run-state mutation');

        $fallback = RtorrentCommandParser::commands($command->params[3]);
        strictAssertSame(1, count($fallback), 'the fallback is one nested command');
        strictAssertSame('branch', $fallback[0]['name'], 'ordinary cleanup has its own synchronous guard');
        strictAssertSame(3, count($fallback[0]['args']), 'nested branch has condition, success and refusal');
        $ordinary = RtorrentCommandParser::commands($fallback[0]['args'][0]);
        strictAssertSame('and', $ordinary[0]['name'], 'ordinary cleanup combines every predicate');
        strictAssertSame(7, count($ordinary[0]['args']), 'ordinary cleanup retains ownership, identity, metadata and run-state guards');
        strictAssertSame(array(
            'equal=d.get_custom=chk-replacement,cat=' . $validMarker,
            'equal=d.get_custom=chk-replaces,cat=' . $validRecord,
            'equal=d.get_custom=chk-reuse,cat=',
        ), array_slice($ordinary[0]['args'], 0, 3), 'ordinary cleanup requires the reuse marker to be empty');
        foreach (array($reuse, $ordinary) as $condition) {
            $identity = RtorrentCommandParser::commands($condition[0]['args'][3]);
            strictAssertSame('d.get_local_id=', $identity[0]['args'][0], 'both alternatives check the same incarnation');
            $value = RtorrentCommandParser::commands($identity[0]['args'][1]);
            strictAssertSame(array($localId), $value[0]['args'], 'both alternatives preserve the supplied exact local id');
            strictAssertSame('equal=d.is_meta=,value=0', $condition[0]['args'][4], 'both alternatives preserve the metadata predicate');
        }
        strictAssertSame(array(
            'equal=d.get_state=,value=' . ($discard ? '0' : '1'),
            'equal=d.is_open=,value=' . ($discard ? '0' : '1'),
        ), array_slice($ordinary[0]['args'], 5), 'discard always requires stopped and closed, overriding caller run-state values');
        $ordinaryBody = RtorrentCommandParser::commands($fallback[0]['args'][1]);
        strictAssertSame($discard ? array(array('name' => 'cat', 'args' => array(
            '$d.erase=', RuTrackerAtomicOwnership::SENTINEL_ERASED,
        ))) : $clear, $ordinaryBody, 'only the stopped ordinary discard branch can erase');
        strictAssertSame('cat=' . RuTrackerAtomicOwnership::SENTINEL_SKIPPED, $fallback[0]['args'][2],
            'foreign or partial ownership is left untouched');
    }
});

$suite->test('reuse values are validated and ordinary mutation refuses an explicit reuse claim', function () use ($validHash, $validMarker) {
    foreach (array(null, false, 0, array(), str_repeat('B', 31), str_repeat('B', 33),
        str_repeat('G', 32), '$d.erase=', $validMarker) as $value) {
        foreach (array('erase', 'runState', 'setCustoms') as $operation) {
            if ($operation === 'setCustoms' && $value === $validMarker) continue;
            rXMLRPCRequest::reset();
            if ($operation === 'erase') {
                $status = RuTrackerAtomicOwnership::erase($validHash, array('chk-reuse' => $value));
            } elseif ($operation === 'runState') {
                $status = RuTrackerAtomicOwnership::runState($validHash, array('chk-reuse' => $value), true);
            } else {
                $status = RuTrackerAtomicOwnership::setCustoms($validHash, array('chk-reuse' => ''), array('chk-reuse' => $value));
            }
            strictAssertSame(RuTrackerAtomicOwnership::UNKNOWN, $status, $operation . ' refuses unsafe reuse input');
            strictAssertSame(array(), rXMLRPCRequest::$requests, $operation . ' refuses before sending a daemon command');
        }
    }
});

$suite->test('ordinary runState requires empty reuse even when the caller omits that key', function () use ($validHash, $validMarker) {
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED));
    RuTrackerAtomicOwnership::runState($validHash, array('chk-replacement' => $validMarker), true);
    $condition = RtorrentCommandParser::commands(rXMLRPCRequest::$requests[0]['commands'][0]->params[1]);
    strictAssertSame(array(array('name' => 'and', 'args' => array(
        'equal=d.get_custom=chk-replacement,cat=' . $validMarker,
        'equal=d.get_custom=chk-reuse,cat=',
    ))), $condition, 'a stale ordinary caller cannot open or start an independently loaded successor');
});

$suite->test('replacement release refuses invalid ownership and predicates before any RPC', function () use ($validHash, $validMarker, $validRecord) {
    $cases = array(
        array('not-a-hash', $validMarker, $validRecord, array(), false),
        array($validHash, '', $validRecord, array(), false),
        array($validHash, '$d.erase=', $validRecord, array(), true),
        array($validHash, $validMarker, '', array(), false),
        array($validHash, $validMarker, 'malformed-record', array(), true),
        array($validHash, $validMarker, $validRecord, array('local_id' => str_repeat('Z', 40)), false),
        array($validHash, $validMarker, $validRecord, array('state' => 2), true),
        array($validHash, $validMarker, $validRecord, array('is_open' => null), true),
        array($validHash, $validMarker, $validRecord, null, false),
        array($validHash, $validMarker, $validRecord, array(), 1),
    );
    foreach ($cases as $case) {
        rXMLRPCRequest::reset();
        strictAssertSame(RuTrackerAtomicOwnership::UNKNOWN,
            call_user_func_array(array('RuTrackerAtomicOwnership', 'closeReplacement'), $case),
            'invalid release input cannot become a partial or weakened guard');
        strictAssertSame(array(), rXMLRPCRequest::$requests, 'invalid release sends no command');
    }
});

$suite->test('replacement release distinguishes confirmed actions from skipped and unknown replies', function () use ($validHash, $validMarker, $validRecord) {
    $cases = array(
        array(true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED), RuTrackerAtomicOwnership::ACTED),
        array(true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED), RuTrackerAtomicOwnership::ACTED),
        array(true, false, array(RuTrackerAtomicOwnership::SENTINEL_SKIPPED), RuTrackerAtomicOwnership::SKIPPED),
        array(true, false, array(RuTrackerAtomicOwnership::SENTINEL_ACTED), RuTrackerAtomicOwnership::UNKNOWN),
        array(true, false, array(RuTrackerAtomicOwnership::SENTINEL_CLEARED, RuTrackerAtomicOwnership::SENTINEL_ERASED), RuTrackerAtomicOwnership::UNKNOWN),
        array(false, true, array(), RuTrackerAtomicOwnership::UNKNOWN),
        array(false, false, array(), RuTrackerAtomicOwnership::UNKNOWN),
    );
    foreach ($cases as $case) {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('branch', $case[0], $case[1], $case[2]);
        strictAssertSame($case[3], RuTrackerAtomicOwnership::closeReplacement(
            $validHash, $validMarker, $validRecord, array(), true),
            'release acknowledges only its exact single terminal sentinel');
        strictAssertSame(1, count(rXMLRPCRequest::$requests), 'unknown replies do not replay cleanup');
    }
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

$suite->test('legacy revival binds the start branch to the observed local id', function () use ($validHash) {
    $localId = str_repeat('A', 40);
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_REVIVED));
    $result = RuTrackerAtomicOwnership::revivePredecessor($validHash, '',
        array('started' => true, 'open' => true), 1234567890,
        array('local_id' => $localId), array('chk-meta-new' => str_repeat('B', 40),
            'chk-state' => '1', 'chk-time' => '1234567890', 'chk-replacement' => '',
            'chk-replaces' => ''));
    strictAssertSame(RuTrackerAtomicOwnership::ACTED, $result, 'valid local id keeps revival eligible');
    $condition = rXMLRPCRequest::$requests[0]['commands'][0]->params[1];
    strictAssertTrue(strpos($condition, getCmd('d.get_local_id=')) !== false
        && strpos($condition, $localId) !== false,
        'the same branch checks the exact observed daemon object identity');
    $parsed = RtorrentCommandParser::commands($condition);
    strictAssertTrue(in_array('equal=d.get_custom=chk-reuse,cat=', $parsed[0]['args'], true),
        'legacy revival cannot start a successor with a partially written reuse claim');
    rXMLRPCRequest::reset();
    strictAssertSame(RuTrackerAtomicOwnership::UNKNOWN,
        RuTrackerAtomicOwnership::revivePredecessor($validHash, '',
            array('started' => true, 'open' => true), 1234567890,
            array('local_id' => str_repeat('Z', 40)), array(
                'chk-meta-new' => str_repeat('B', 40), 'chk-state' => '1',
                'chk-time' => '1234567890', 'chk-replacement' => '', 'chk-replaces' => '')),
        'invalid local identity is rejected before a daemon command');
    strictAssertSame(0, count(rXMLRPCRequest::$requests), 'invalid identity sends no branch');
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
and="equal=d.get_custom=chk-replacing,cat=CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC-started-1234567890","equal=d.get_custom=chk-reuse,cat=","equal=d.get_state=,value=0","equal=d.is_open=,value=0"
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
            . '","equal=d.custom=chk-reuse,cat='
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

$suite->test('replacement record parser owns canonical hash and epoch spelling', function () {
    $hash = str_repeat('A', 40);
    $record = RuTrackerReplacementRecord::encode(strtolower($hash), true, true, 1234567890);
    $shaped = null;
    strictAssertSame($hash . '-started-1234567890', $record, 'encoding has one canonical uppercase hash');
    strictAssertSame($hash, RuTrackerReplacementRecord::decode($record, $shaped)['old'],
        'the emitted record is accepted by its shared parser');
    strictAssertSame(true, $shaped, 'the emitted record has the replacement shape');
    foreach (array(strtolower($hash) . '-started-1234567890',
        $hash . '-started-01234567890', $hash . '-started-0') as $invalid) {
        $shaped = null;
        strictAssertSame(null, RuTrackerReplacementRecord::decode($invalid, $shaped),
            'noncanonical persisted records do not grant any caller ownership');
        strictAssertSame(true, $shaped, 'corrupt shaped records remain distinguishable from absence');
    }
    strictAssertSame(null, RuTrackerReplacementRecord::decode('', $shaped), 'absence is not a record');
    strictAssertSame(false, $shaped, 'absence retains the legacy signal');
});

$suite->test('RuTrackerReplacementRecord::encode rejects invalid or non-40-hex hashes', function () {
    $badHashes = array('', 'short', str_repeat('G', 40), '0', null, false, 12345);
    foreach ($badHashes as $bad) {
        $threw = false;
        try {
            RuTrackerReplacementRecord::encode($bad, true, true, 1234567890);
        } catch (InvalidArgumentException $e) {
            $threw = true;
        }
        strictAssertTrue($threw, 'encode must throw InvalidArgumentException for invalid hash: ' . var_export($bad, true));
    }
});

exit($suite->run());
