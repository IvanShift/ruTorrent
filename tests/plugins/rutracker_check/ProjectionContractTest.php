<?php

require_once(__DIR__ . '/TestLib.php');

// RuTrackerCustomProjection consults the checker only after an unproved
// write/readback. This suite keeps that boundary present so malformed
// positive replies exercise the real fail-closed path instead of dying on a
// missing test collaborator.
class ruTrackerChecker
{
    public static function torrentExists($hash)
    {
        return true;
    }

    public static function logDebug($message)
    {
    }
}

// The real php/xmlrpc.php wraps every command argument in rXMLRPCParam and
// XML-escapes string values before rXMLRPCRequest sees the command. Keep this
// boundary object intentionally tiny: the transport remains the shared
// recorder double, while the parameters have the exact production shape and
// encoding that the projection helper must consume.
class ProductionShapedXMLRPCParam
{
    public $type;
    public $value;

    public function __construct($type, $value)
    {
        $this->type = $type;
        $this->value = $type === 'string'
            ? htmlspecialchars($value, ENT_NOQUOTES, 'UTF-8')
            : number_format($value, 0, '.', '');
    }
}

function productionShapedSetter($hash, $field, $value)
{
    $command = new rXMLRPCCommand(getCmd('d.set_custom'));
    $command->params = array(
        new ProductionShapedXMLRPCParam('string', $hash),
        new ProductionShapedXMLRPCParam('string', $field),
        new ProductionShapedXMLRPCParam('string', $value),
    );
    return $command;
}

$suite = new StrictTestSuite();

$suite->test('projection proof decodes production-shaped XMLRPC parameters before exact readback', function () {
    rXMLRPCRequest::reset();
    $hash = str_repeat('A', 40);
    $message = 'left & right <ready>';
    $commands = array(
        productionShapedSetter($hash, 'chk-msg', $message),
        productionShapedSetter($hash, 'chk-time', '123'),
    );

    // Positive but short: the projection helper must inspect the desired
    // fields and values, not accept the aggregate response on faith.
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array(0));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false,
        array($message, '123'));

    strictAssertSame(true,
        RuTrackerCustomProjection::write($hash, $commands, 'production parameter contract'),
        'an exact decoded readback proves the write without casting parameter objects');
    $reads = rXMLRPCRequest::requestsFor('d.get_custom|d.get_custom');
    strictAssertSame(1, count($reads), 'a short positive response triggers one complete readback');
    strictAssertSame(array($hash, 'chk-msg'), $reads[0]['commands'][0]->params,
        'the first field name is extracted from the wrapped parameter');
    strictAssertSame(array($hash, 'chk-time'), $reads[0]['commands'][1]->params,
        'the second field name is extracted from the wrapped parameter');
});

$suite->test('extra write or readback scalars never prove a custom projection', function () {
    $hash = str_repeat('A', 40);
    $commands = array(
        productionShapedSetter($hash, 'chk-msg', 'ready'),
        productionShapedSetter($hash, 'chk-time', '123'),
    );

    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false,
        array(0, 0, 'unexpected-extra'));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false,
        array('ready', '123'));
    strictAssertSame(true,
        RuTrackerCustomProjection::write($hash, $commands, 'extra write response'),
        'an overlong positive write response requires exact readback');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('d.get_custom|d.get_custom')),
        'the extra write scalar prevents direct success');

    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(array('d.set_custom', 'd.set_custom'), true, false, array(0));
    rXMLRPCRequest::queue(array('d.get_custom', 'd.get_custom'), true, false,
        array('ready', '123', 'unexpected-extra'));
    rXMLRPCRequest::queue('d.hash', true, false, array($hash));
    strictAssertSame(false,
        RuTrackerCustomProjection::write($hash, $commands, 'extra readback response'),
        'an overlong readback is untrustworthy even when its prefix matches');
});

$suite->test('canonicalNonnegativeInteger accepts canonical nonnegative integers and strings, rejects malformed', function () {
    $valid = array(
        0 => 0,
        1 => 1,
        42 => 42,
        PHP_INT_MAX => PHP_INT_MAX,
    );
    foreach ($valid as $input => $expected) {
        strictAssertSame($expected, RuTrackerRpcValue::canonicalNonnegativeInteger($input),
            "valid int {$input} returns {$expected}");
    }

    // Numeric-looking array keys are coerced to integers by PHP, so keep the
    // input in the value slot to exercise the string branch for real.
    $validStrings = array(
        array('0', 0),
        array('1', 1),
        array('42', 42),
        array((string) PHP_INT_MAX, PHP_INT_MAX),
    );
    foreach ($validStrings as $case) {
        list($input, $expected) = $case;
        strictAssertSame($expected, RuTrackerRpcValue::canonicalNonnegativeInteger($input),
            "valid string {$input} returns {$expected}");
    }

    $invalid = array(
        -1,
        '-1',
        '-0',
        '+1',
        '+0',
        '01',
        '00',
        ' 1',
        '1 ',
        1.0,
        0.0,
        1.5,
        true,
        false,
        null,
        array(),
        array(1),
        new stdClass(),
        '999999999999999999999999999999999999999999',
        'abc',
        '',
    );
    foreach ($invalid as $bad) {
        strictAssertSame(null, RuTrackerRpcValue::canonicalNonnegativeInteger($bad),
            'invalid input is rejected');
    }
});

exit($suite->run());
