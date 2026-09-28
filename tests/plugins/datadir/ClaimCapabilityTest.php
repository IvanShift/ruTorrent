<?php

require_once __DIR__ . '/../rutracker_check/TestLib.php';

eval(loadFunctionDefinition(__DIR__ . '/../../../php/xmlrpc.php',
    'rpcMethodCapability'));
eval(loadFunctionDefinition(__DIR__ . '/../../../plugins/datadir/util_setdir.php',
    'rtDataDirClaimCapability'));

$required = array(
    'system.listMethods',
    'd.stop_close_claim_state',
    'd.stop_close_claim',
    'd.directory.set_if_stop_close_claim',
    'd.directory.base.set_if_stop_close_claim',
    'd.start_if_stop_close_claim',
    'd.open_if_stop_close_claim',
    'd.release_stop_close_claim',
    'd.replay_stop_close_claim',
    'd.ack_stop_close_claim',
);

$suite = new StrictTestSuite();

$suite->test('complete daemon claim ABI admits DataDir request', function () use ($required) {
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('system.listMethods', true, false, $required);
    strictAssertSame('available', rtDataDirClaimCapability(),
        'all required claim commands are advertised');
    $requests = rXMLRPCRequest::requestsFor('system.listMethods');
    strictAssertSame(1, count($requests), 'one read-only capability request is sent');
    strictAssertSame(false, $requests[0]['important'],
        'routine capability fault never logs a raw RPC transcript');
});

$suite->test('every missing native claim command refuses DataDir before dispatch',
    function () use ($required) {
        foreach (array_slice($required, 1) as $method) {
            rXMLRPCRequest::reset();
            rXMLRPCRequest::queue('system.listMethods', true, false,
                array_values(array_diff($required, array($method))));
            strictAssertSame('unsupported', rtDataDirClaimCapability(),
                'missing ' . $method . ' is an unsupported daemon');
        }
    });

$suite->test('transport and opaque faults remain unknown', function () use ($required) {
    foreach (array(
        array(false, false, array()),
        array(true, true, array('Method list refused')),
        array(true, false, array()),
        array(true, false, array('system.listMethods', 1)),
    ) as $response) {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('system.listMethods', $response[0], $response[1],
            $response[2]);
        strictAssertSame('unknown', rtDataDirClaimCapability(),
            'no missing-method conclusion from an unconfirmed method list');
    }
});

$suite->test('empty method name makes the daemon claim list unconfirmed', function () use ($required) {
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('system.listMethods', true, false,
        array_merge($required, array('')));
    strictAssertSame('unknown', rtDataDirClaimCapability(),
        'a malformed list cannot authorize a file move');
});

exit($suite->run());
