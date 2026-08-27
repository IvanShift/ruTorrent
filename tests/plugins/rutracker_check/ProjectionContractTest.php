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

// ---------------------------------------------------------------------------
// The canonical RPC/persisted integer matrix. These four helpers are the ONLY
// public parsers for an integer that arrived over XMLRPC or came back off
// disk; every production boundary reads through one of them, so the exact
// domain of each is pinned here once, for both the int and the string form of
// every boundary value. Every helper answers int|null and never a coerced 0.
// ---------------------------------------------------------------------------

// One row per input: the label, the value, then the expected answer from
// canonicalNonnegativeInteger, canonicalNonnegativeInt32, canonicalPositiveInt32
// and canonicalSignedInt32 in that order. RPC_REJECT marks "returns null".
define('RPC_REJECT', 'sentinel-null');

function rpcMatrixRows()
{
    $overflowString = '9223372036854775808';       // PHP_INT_MAX + 1
    $hugeString = '99999999999999999999999999999';
    return array(
        // label,                value,               nonneg,        nonneg32,      pos32,         signed32
        array('int zero',        0,                   0,             0,             RPC_REJECT,    0),
        array('int one',         1,                   1,             1,             1,             1),
        array('int INT32_MAX',   2147483647,          2147483647,    2147483647,    2147483647,    2147483647),
        array('int INT32_MIN',   -2147483648,         RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    -2147483648),
        array('int PHP_INT_MAX', PHP_INT_MAX,         PHP_INT_MAX,   RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('int minus one',   -1,                  RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    -1),

        array('string zero',        '0',              0,             0,             RPC_REJECT,    0),
        array('string one',         '1',              1,             1,             1,             1),
        array('string INT32_MAX',   '2147483647',     2147483647,    2147483647,    2147483647,    2147483647),
        array('string INT32_MIN',   '-2147483648',    RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    -2147483648),
        array('string PHP_INT_MAX', (string) PHP_INT_MAX, PHP_INT_MAX, RPC_REJECT,  RPC_REJECT,    RPC_REJECT),
        array('string minus one',   '-1',             RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    -1),

        // Overflow strings: past each helper's own ceiling, never wrapped or clamped.
        array('int32 overflow',        '2147483648',  2147483648,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('int32 underflow',       '-2147483649', RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('php int overflow',      $overflowString, RPC_REJECT,  RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('php int underflow',     '-' . $overflowString, RPC_REJECT, RPC_REJECT, RPC_REJECT,  RPC_REJECT),
        array('absurd overflow',       $hugeString,   RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),

        // Non-canonical spellings of numbers that would otherwise be in range.
        array('leading zero',          '01',          RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('leading zeroes',        '0001',        RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('zero zero',             '00',          RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('negative leading zero', '-01',         RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('leading plus',          '+1',          RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('leading plus zero',     '+0',          RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('minus zero',            '-0',          RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('leading space',         ' 1',          RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('trailing space',        '1 ',          RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('leading newline',       "\n1",         RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('trailing newline',      "1\n",         RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('empty string',          '',            RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('bare minus',            '-',           RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('hex',                   '0x1',         RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('digits then letters',   '6oops',       RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('decimal string',        '1.0',         RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('exponent string',       '1e3',         RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),

        // Types that are not an RPC integer at all.
        array('float whole',     1.0,                 RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('float zero',      0.0,                 RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('float fraction',  1.5,                 RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('float negative',  -1.5,                RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('bool true',       true,                RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('bool false',      false,               RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('null',            null,                RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('empty array',     array(),             RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('list array',      array(1),            RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('map array',       array('v' => 1),     RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
        array('object',          new stdClass(),      RPC_REJECT,    RPC_REJECT,    RPC_REJECT,    RPC_REJECT),
    );
}

$suite->test('the four canonical RPC integer parsers answer int|null over the exact same matrix', function () {
    $helpers = array(
        'canonicalNonnegativeInteger' => 2,
        'canonicalNonnegativeInt32' => 3,
        'canonicalPositiveInt32' => 4,
        'canonicalSignedInt32' => 5,
    );
    foreach (rpcMatrixRows() as $row) {
        $label = $row[0];
        $value = $row[1];
        foreach ($helpers as $helper => $column) {
            $expected = $row[$column] === RPC_REJECT ? null : $row[$column];
            $actual = call_user_func(array('RuTrackerRpcValue', $helper), $value);
            strictAssertSame($expected, $actual,
                $helper . ' on ' . $label);
            strictAssertTrue($actual === null || is_int($actual),
                $helper . ' on ' . $label . ' answers int|null and nothing else');
        }
    }
});

// A persisted replacement record is the plugin's own transaction ownership
// token. Its epoch is a persisted integer, so "03" is not the epoch 3: it is a
// record nothing may act on, and the atomic ownership boundary must refuse to
// build a condition from it rather than sending a branch that erases the
// occupant.
$suite->test('a replacement record whose persisted epoch is "03" authorises no atomic ownership action', function () {
    $hash = str_repeat('A', 40);
    $predecessor = str_repeat('C', 40);
    $noncanonical = $predecessor . '-started-03';
    $canonical = $predecessor . '-started-3';

    strictAssertSame(null, RuTrackerReplacementRecord::decode($noncanonical),
        'a leading-zero epoch is not decoded into the epoch it resembles');
    strictAssertSame(3, RuTrackerReplacementRecord::decode($canonical)['staged'],
        'the canonical spelling of the same epoch still decodes');
    $shaped = false;
    RuTrackerReplacementRecord::decode($noncanonical, $shaped);
    strictAssertSame(true, $shaped,
        'it is still recognisably a record, so it is corruption rather than absence');
    RuTrackerReplacementRecord::decode('not-a-record-at-all', $shaped);
    strictAssertSame(false, $shaped, 'while bytes that are not a record at all say so');

    rXMLRPCRequest::reset();
    strictAssertSame(RuTrackerAtomicOwnership::UNKNOWN,
        RuTrackerAtomicOwnership::erase($hash, array('chk-replaces' => $noncanonical)),
        'no erase condition may be built from a non-canonical epoch');
    strictAssertSame(RuTrackerAtomicOwnership::UNKNOWN,
        RuTrackerAtomicOwnership::clearCustoms($hash, array('chk-replaces' => $noncanonical),
            array('chk-replaces')),
        'and no clear condition either');
    strictAssertSame(RuTrackerAtomicOwnership::UNKNOWN,
        RuTrackerAtomicOwnership::runState($hash, array('chk-replacing' => $noncanonical), true),
        'and no run-state condition either');
    strictAssertSame(0, count(rXMLRPCRequest::requestsFor('branch')),
        'a malformed epoch reaches no branch command at all');

    // The canonical spelling of the very same transaction still works, so this
    // is a rejection of the bytes and not of the code path.
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));
    strictAssertSame(RuTrackerAtomicOwnership::ACTED,
        RuTrackerAtomicOwnership::erase($hash, array('chk-replaces' => $canonical)),
        'the canonical record still authorises the exact same action');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor('branch')),
        'exactly one atomic attempt for the canonical record');
});

// buildCondition()'s state/is_open projection reads through the same canonical
// parser as everything else, and refuses anything outside {0, 1}.
$suite->test('the projection state/is_open cast is a canonical 0/1 read, not a coercion', function () {
    $hash = str_repeat('A', 40);
    $marker = str_repeat('b', 32);
    foreach (array('leading zero' => '01', 'plus one' => '+1', 'float' => 1.0,
                   'bool' => true, 'two' => 2, 'string two' => '2', 'null' => null) as $label => $bad) {
        rXMLRPCRequest::reset();
        strictAssertSame(RuTrackerAtomicOwnership::UNKNOWN,
            RuTrackerAtomicOwnership::erase($hash, array('chk-replacement' => $marker),
                array('state' => $bad)),
            $label . ': a non-canonical run-state expectation builds no condition');
        strictAssertSame(0, count(rXMLRPCRequest::requestsFor('branch')),
            $label . ': and reaches no branch command');
    }

    foreach (array(0, 1, '0', '1') as $good) {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('branch', true, false, array(RuTrackerAtomicOwnership::SENTINEL_ERASED));
        strictAssertSame(RuTrackerAtomicOwnership::ACTED,
            RuTrackerAtomicOwnership::erase($hash, array('chk-replacement' => $marker),
                array('state' => $good)),
            'the four canonical spellings of 0/1 still build the condition');
    }
});

exit($suite->run());
