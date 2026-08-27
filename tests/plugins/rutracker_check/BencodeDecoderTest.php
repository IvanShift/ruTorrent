<?php

/**
 * Conformance suite for the ONE bencode grammar this plugin owns
 * (plugins/rutracker_check/bencode.php).
 *
 * The plugin used to carry two independent grammars -- a recursive scanner
 * inheriting Torrent's decoder in announce.php and a handwritten stack in
 * trackers/nnmclub.php -- so every syntax rule had two chances to drift. This
 * file pins the shared grammar itself: the typed node contract, the five
 * explicit ceilings at their exact accept/reject boundary, and the rejections
 * both consumers depend on (non-canonical integers, duplicate keys, trailing
 * bytes, truncation).
 *
 * The schema verdicts stay where they belong: RuTrackerAnnounceTest owns the
 * announce registered/unregistered/uncertain rules and NNMClubHandlerTest owns
 * the scrape UPTODATE/NOT_FOUND/FAILED rules. Nothing here decides a verdict.
 */

require_once(__DIR__ . '/TestLib.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/bencode.php');

$suite = new StrictTestSuite();

// Deliberately generous defaults: a bound test overrides exactly the one
// ceiling it is measuring, so a fixture can never be rejected by a limit the
// test did not mean to exercise.
function bdLimits($overrides = array())
{
    return array_merge(array(
        'max_bytes' => 65536,
        'max_depth' => 64,
        'max_tokens' => 4096,
        'max_integer_digits' => 19,
        'max_length_digits' => 7,
    ), $overrides);
}

// The exact ceilings announce passes. Pinned again in RuTrackerAnnounceTest
// against the production constant; repeated here because the deepest-body
// characterization below is a property of the GRAMMAR under those numbers.
function bdAnnounceLimits()
{
    return array(
        'max_bytes' => 8192,
        'max_depth' => 4096,
        'max_tokens' => 4096,
        'max_integer_digits' => 8192,
        'max_length_digits' => 4,
    );
}

function bdDecode($payload, $overrides = array())
{
    return RuTrackerBencode::decode($payload, bdLimits($overrides));
}

// Every payload in $cases must be rejected, and the rejection must be exactly
// `false` -- never null, never an empty node that a consumer could mistake for
// a valid document.
function bdAssertAllRejected($suiteLabel, $cases, $overrides = array())
{
    foreach ($cases as $label => $payload) {
        strictAssertSame(false, bdDecode($payload, $overrides), $suiteLabel . ': ' . $label);
    }
}

$suite->test('scalar nodes carry the exact typed contract', function () {
    strictAssertSame(array('type' => 'string', 'value' => ''), bdDecode('0:'),
        'an empty string is a string node carrying zero bytes');
    strictAssertSame(array('type' => 'string', 'value' => 'abcd'), bdDecode('4:abcd'),
        'a string node carries its raw bytes');
    strictAssertSame(array('type' => 'string', 'value' => "\x00\xffz"), bdDecode("3:\x00\xffz"),
        'string bytes are raw: NUL and high bytes survive unchanged');

    strictAssertSame(array('type' => 'integer', 'value' => '0'), bdDecode('i0e'), 'canonical zero');
    strictAssertSame(array('type' => 'integer', 'value' => '42'), bdDecode('i42e'), 'canonical positive');
    strictAssertSame(array('type' => 'integer', 'value' => '-42'), bdDecode('i-42e'), 'canonical negative');

    // The integer stays a TOKEN until the consumer range-validates it: the
    // announce port check and the NNM counter check both read the digits, and
    // an early (int) cast would silently saturate a 30-digit token into
    // PHP_INT_MAX and hand the schema a number the tracker never sent.
    $node = bdDecode('i9223372036854775808e');
    strictAssertSame('integer', $node['type'], 'a token past PHP_INT_MAX still decodes');
    strictAssertTrue(is_string($node['value']), 'the integer value is a string token, not a cast number');
    strictAssertSame('9223372036854775808', $node['value'], 'and it is the exact canonical token');
});

$suite->test('an empty string, an empty list and an empty dictionary are three distinct nodes', function () {
    $string = bdDecode('0:');
    $list = bdDecode('le');
    $dictionary = bdDecode('de');

    strictAssertSame(array('type' => 'string', 'value' => ''), $string, 'empty string');
    strictAssertSame(array('type' => 'list', 'value' => array()), $list, 'empty list');
    strictAssertSame(array('type' => 'dictionary', 'value' => array()), $dictionary, 'empty dictionary');

    // The distinction is the whole reason the plugin cannot reuse
    // Torrent::decode(): it collapses all three into the same PHP value, and
    // the announce schema treats an empty peer LIST as a valid answer while an
    // empty peer DICTIONARY is malformed.
    strictAssertTrue($string !== $list, 'an empty string is not an empty list');
    strictAssertTrue($list !== $dictionary, 'an empty list is not an empty dictionary');
    strictAssertTrue($string !== $dictionary, 'an empty string is not an empty dictionary');
});

$suite->test('lists preserve order and nesting', function () {
    strictAssertSame(
        array('type' => 'list', 'value' => array(
            array('type' => 'string', 'value' => 'spam'),
            array('type' => 'integer', 'value' => '42'),
        )),
        bdDecode('l4:spami42ee'),
        'a list keeps its members in payload order'
    );

    strictAssertSame(
        array('type' => 'list', 'value' => array(
            array('type' => 'list', 'value' => array(
                array('type' => 'integer', 'value' => '1'),
            )),
            array('type' => 'dictionary', 'value' => array()),
        )),
        bdDecode('lli1eedee'),
        'nested containers are typed nodes of their own'
    );
});

$suite->test('dictionaries return ordered entry records, not associative arrays', function () {
    strictAssertSame(
        array('type' => 'dictionary', 'value' => array(
            array('key' => 'b', 'value' => array('type' => 'string', 'value' => 'x')),
            array('key' => 'a', 'value' => array('type' => 'string', 'value' => 'y')),
        )),
        bdDecode('d1:b1:x1:a1:ye'),
        'entries are ordered records in payload order, unsorted keys included'
    );

    strictAssertSame(
        array('type' => 'dictionary', 'value' => array(
            array('key' => 'outer', 'value' => array('type' => 'dictionary', 'value' => array(
                array('key' => 'inner', 'value' => array('type' => 'integer', 'value' => '7')),
            ))),
        )),
        bdDecode('d5:outerd5:inneri7eee'),
        'nested dictionaries are entry-record dictionaries too'
    );
});

$suite->test('numeric-looking dictionary keys stay raw strings', function () {
    // An ordinary PHP associative array coerces "0" and "10" to INTEGER keys.
    // NNM matches a 20-byte binary hash key with ===, so a coerced key would
    // silently stop matching; announce compares 'failure reason' and 'peers'
    // the same way.
    $node = bdDecode('d1:00:2:100:e');
    strictAssertSame('dictionary', $node['type'], 'the document decodes');
    strictAssertSame(2, count($node['value']), 'both entries survive');
    strictAssertSame('0', $node['value'][0]['key'], 'the first raw key is the STRING "0"');
    strictAssertTrue(is_string($node['value'][0]['key']), 'and it is a string, not integer 0');
    strictAssertSame('10', $node['value'][1]['key'], 'the second raw key is the STRING "10"');
    strictAssertTrue(is_string($node['value'][1]['key']), 'and it is a string, not integer 10');

    $wide = bdDecode('d19:92233720368547758080:e');
    strictAssertSame('9223372036854775808', $wide['value'][0]['key'],
        'a key past PHP_INT_MAX is preserved byte for byte');

    $binaryKey = "\x00\x01" . '1';
    $binary = bdDecode('d3:' . $binaryKey . '0:e');
    strictAssertSame($binaryKey, $binary['value'][0]['key'], 'a key containing NUL is preserved byte for byte');
});

$suite->test('a duplicate raw dictionary key is rejected immediately', function () {
    bdAssertAllRejected('duplicate key', array(
        'plain duplicate' => 'd1:a0:1:a0:e',
        'duplicate with different values' => 'd1:a1:x1:a1:ye',
        'duplicate numeric-looking key' => 'd1:00:1:00:e',
        'duplicate integer-like key' => 'd3:1231:a3:1231:be',
        'duplicate empty key' => 'd0:0:0:0:e',
        'duplicate inside a nested dictionary' => 'd1:ad1:b0:1:b0:eee',
        'duplicate inside a dictionary nested in a list' => 'ld1:b0:1:b0:eee',
        'duplicate separated by other keys' => 'd1:a0:1:b0:1:c0:1:a0:e',
        'duplicate binary key' => 'd2:' . "\x00\x01" . '0:2:' . "\x00\x01" . '0:e',
    ));

    // The control: distinct raw keys that a coercing store would confuse are
    // still two entries, so the rejection above is duplicate detection and
    // not a blanket refusal of numeric keys.
    $ok = bdDecode('d1:00:2:000:e');
    strictAssertSame(2, count($ok['value']), '"0" and "00" are two distinct raw keys');
    strictAssertSame('0', $ok['value'][0]['key'], 'first key');
    strictAssertSame('00', $ok['value'][1]['key'], 'second key');

    // Immediately: nothing after the duplicate can rescue the document, and
    // nothing before it is published as a partial result.
    strictAssertSame(false, bdDecode('d1:a0:1:a0:1:b0:e'), 'a duplicate rejects the whole payload');
});

$suite->test('a dictionary key must be a string', function () {
    bdAssertAllRejected('non-string key', array(
        'integer key' => 'di1e0:e',
        'list key' => 'dle0:e',
        'dictionary key' => 'dde0:e',
        'nested integer key' => 'd1:adi1e0:eee',
        'integer key after a valid pair' => 'd1:a0:i1e0:e',
    ));
});

$suite->test('only canonical integer tokens are accepted', function () {
    bdAssertAllRejected('noncanonical integer', array(
        'leading zero i01e' => 'i01e',
        'leading zeroes i00e' => 'i00e',
        'negative zero i-0e' => 'i-0e',
        'negative leading zero i-01e' => 'i-01e',
        'explicit plus i+1e' => 'i+1e',
        'decimal i1.0e' => 'i1.0e',
        'empty integer ie' => 'ie',
        'bare sign i-e' => 'i-e',
        'whitespace i 1e' => 'i 1e',
        'hexadecimal i0x1e' => 'i0x1e',
    ));

    foreach (array('i0e' => '0', 'i7e' => '7', 'i10e' => '10', 'i-1e' => '-1', 'i-10e' => '-10') as $payload => $token) {
        strictAssertSame(array('type' => 'integer', 'value' => $token), bdDecode($payload),
            'canonical token ' . $payload);
    }
});

$suite->test('only canonical string length prefixes are accepted', function () {
    bdAssertAllRejected('noncanonical length', array(
        'leading zero length' => '01:a',
        'signed length' => '+1:a',
        'negative length' => '-1:a',
        'missing colon' => '3abc',
        'empty length prefix' => ':abc',
        'decimal length' => '1.0:a',
    ));

    strictAssertSame(array('type' => 'string', 'value' => ''), bdDecode('0:'),
        'a single zero is the one canonical way to spell an empty string');
});

$suite->test('truncated tokens are rejected', function () {
    bdAssertAllRejected('truncated token', array(
        'string shorter than its length' => '5:abc',
        'string with no bytes at all' => '4:',
        'string prefix with no colon' => '3',
        'unterminated integer' => 'i42',
        'lone i' => 'i',
        'empty payload' => '',
        'string truncated inside a container' => 'l5:abce',
        'unterminated integer inside a dictionary' => 'd1:ai42',
    ));
});

$suite->test('truncated containers are rejected', function () {
    bdAssertAllRejected('truncated container', array(
        'open list' => 'l',
        'open list with a member' => 'l0:',
        'open dictionary' => 'd',
        'dictionary with a dangling key' => 'd1:a',
        'dictionary with an unclosed pair' => 'd1:a1:b',
        'nested open container' => 'ld',
        'inner list closed, outer open' => 'lle',
        'closing byte with nothing open' => 'e',
        'over-closed list' => 'le' . 'e',
    ));
});

$suite->test('trailing bytes reject the whole payload', function () {
    bdAssertAllRejected('trailing bytes', array(
        'byte after an empty dictionary' => 'de0',
        'value after an empty dictionary' => 'de0:',
        'byte after a string' => '0:x',
        'byte after an integer' => 'i0ee',
        'html after a complete dictionary' => 'd1:a0:e<html>',
        'second root value' => 'lele',
        'NUL after a complete list' => "le\x00",
    ));

    // The control: the very same documents without the tail are accepted, so
    // the rejection above is about the trailing bytes and nothing else.
    strictAssertSame(array('type' => 'dictionary', 'value' => array()), bdDecode('de'), 'bare empty dictionary');
    strictAssertSame(array('type' => 'list', 'value' => array()), bdDecode('le'), 'bare empty list');
    strictAssertSame(array('type' => 'integer', 'value' => '0'), bdDecode('i0e'), 'bare integer');
});

$suite->test('max_bytes is enforced at the exact boundary', function () {
    $limits = array('max_bytes' => 8);

    $atLimit = 'd1:a1:be';
    strictAssertSame(8, strlen($atLimit), 'the at-limit fixture really is 8 bytes');
    strictAssertSame('dictionary', bdDecode($atLimit, $limits)['type'], 'exactly max_bytes is accepted');

    // One byte over, and still perfectly well-formed, so only the size can be
    // what rejected it.
    $overLimit = 'd1:a2:bce';
    strictAssertSame(9, strlen($overLimit), 'the over-limit fixture really is 9 bytes');
    strictAssertSame('dictionary', bdDecode($overLimit)['type'], 'the over-limit fixture is valid under a larger ceiling');
    strictAssertSame(false, bdDecode($overLimit, $limits), 'one byte over max_bytes is rejected');

    strictAssertSame(false, bdDecode('', $limits), 'an empty payload is not a document');
});

$suite->test('max_depth is enforced at the exact boundary', function () {
    $limits = array('max_depth' => 3);

    strictAssertSame('list', bdDecode('llleee', $limits)['type'], 'three nested lists are exactly max_depth');
    strictAssertSame(false, bdDecode('lllleeee', $limits), 'four nested lists exceed max_depth');

    strictAssertSame('dictionary', bdDecode('d1:ad1:adeee', $limits)['type'],
        'three nested dictionaries are exactly max_depth');
    strictAssertSame(false, bdDecode('d1:ad1:ad1:adeeee', $limits), 'four nested dictionaries exceed max_depth');

    strictAssertSame('dictionary', bdDecode('d1:alleee', $limits)['type'], 'mixed nesting counts the same way');
    strictAssertSame(false, bdDecode('d1:alll' . 'eeee', $limits), 'mixed nesting one level too deep is rejected');

    // Depth is the number of OPEN containers, not the number of containers:
    // a wide but shallow document is unaffected by the ceiling.
    strictAssertSame('list', bdDecode('ll' . str_repeat('le', 10) . 'ee', $limits)['type'],
        'many sibling containers stay within a depth of three');
});

$suite->test('max_tokens counts every container and every scalar exactly once', function () {
    // 1 list + 3 strings.
    strictAssertSame('list', bdDecode('l0:0:0:e', array('max_tokens' => 4))['type'],
        'exactly max_tokens is accepted');
    strictAssertSame(false, bdDecode('l0:0:0:0:e', array('max_tokens' => 4)),
        'one token over the ceiling is rejected');

    // A dictionary KEY is a token of its own: 1 dictionary + 1 key + 1 value.
    strictAssertSame('dictionary', bdDecode('d1:a0:e', array('max_tokens' => 3))['type'],
        'a dictionary costs one token per container plus one per key and value');
    strictAssertSame(false, bdDecode('d1:a0:e', array('max_tokens' => 2)),
        'the same document is rejected one token below its cost');

    // A container costs exactly one token, not one per delimiter byte.
    strictAssertSame('list', bdDecode('llleee', array('max_tokens' => 3))['type'],
        'three nested containers cost exactly three tokens');
    strictAssertSame(false, bdDecode('llleee', array('max_tokens' => 2)),
        'and are rejected at two');
});

$suite->test('max_integer_digits counts digits and not the sign', function () {
    $limits = array('max_integer_digits' => 3);

    strictAssertSame(array('type' => 'integer', 'value' => '123'), bdDecode('i123e', $limits),
        'exactly max_integer_digits is accepted');
    strictAssertSame(array('type' => 'integer', 'value' => '-123'), bdDecode('i-123e', $limits),
        'the minus sign is not a digit');
    strictAssertSame(false, bdDecode('i1234e', $limits), 'one digit over the ceiling is rejected');
    strictAssertSame(false, bdDecode('i-1234e', $limits), 'and a negative token is measured the same way');

    strictAssertSame(array('type' => 'integer', 'value' => '1234567890123456789'),
        bdDecode('i1234567890123456789e', array('max_integer_digits' => 19)),
        'the NNM ceiling admits a full 19-digit counter');
    strictAssertSame(false, bdDecode('i12345678901234567890e', array('max_integer_digits' => 19)),
        'a twentieth digit is refused');
});

$suite->test('max_length_digits bounds the string length prefix', function () {
    $limits = array('max_length_digits' => 2);

    strictAssertSame(array('type' => 'string', 'value' => str_repeat('a', 12)),
        bdDecode('12:' . str_repeat('a', 12), $limits), 'a two-digit length prefix is exactly at the ceiling');
    strictAssertSame(array('type' => 'string', 'value' => ''), bdDecode('0:', $limits),
        'a one-digit prefix is within it');

    $three = '100:' . str_repeat('a', 100);
    strictAssertSame('string', bdDecode($three)['type'], 'the over-limit fixture is valid under a larger ceiling');
    strictAssertSame(false, bdDecode($three, $limits), 'a three-digit length prefix is refused');

    // The ceiling applies to keys too, not only to values.
    strictAssertSame(false, bdDecode('d100:' . str_repeat('k', 100) . '0:e', $limits),
        'an over-long key prefix is refused as well');
});

$suite->test('an incomplete or unusable limit set decodes nothing', function () {
    $complete = bdLimits();
    strictAssertSame('string', RuTrackerBencode::decode('0:', $complete)['type'], 'the complete set works');

    foreach (array_keys($complete) as $missing) {
        $partial = $complete;
        unset($partial[$missing]);
        strictAssertSame(false, RuTrackerBencode::decode('0:', $partial),
            'a limit set missing ' . $missing . ' decodes nothing');
    }

    strictAssertSame(false, RuTrackerBencode::decode('0:', array()), 'an empty limit set decodes nothing');
    strictAssertSame(false, RuTrackerBencode::decode('0:', 'nonsense'), 'a non-array limit set decodes nothing');
    strictAssertSame(false, RuTrackerBencode::decode(null, $complete), 'a non-string payload decodes nothing');
    strictAssertSame(false, RuTrackerBencode::decode('0:', array_merge($complete, array('max_bytes' => 0))),
        'a zero ceiling admits nothing rather than everything');
});

// The announce body cap has always been a bound on decoding cost as much as on
// transfer size. The ceilings the announce consumer passes are chosen so the
// grammar admits EVERY shape 8192 bytes can spell -- otherwise the migration
// would have quietly narrowed what counts as a decodable answer.
$suite->test('the announce ceilings admit every shape an 8192-byte body can spell', function () {
    $limits = bdAnnounceLimits();

    // The deepest admitted body, byte for byte the fixture RuTrackerAnnounceTest
    // pins: one dictionary plus intdiv(8192 - 33, 2) nested lists.
    $depth = intdiv($limits['max_bytes'] - 33, 2);
    strictAssertSame(4079, $depth, 'the deepest admitted nesting is 4079 lists under the top dictionary');
    $deep = 'd8:intervali1800e5:peers0:1:a' . str_repeat('l', $depth) . str_repeat('e', $depth) . 'e';
    strictAssertTrue(strlen($deep) <= $limits['max_bytes'], 'the deepest admitted body is within the cap');

    $node = RuTrackerBencode::decode($deep, $limits);
    strictAssertTrue(is_array($node), 'the deepest admitted body decodes');
    strictAssertSame('dictionary', $node['type'], 'to a dictionary');
    strictAssertSame(3, count($node['value']), 'carrying its three top-level entries');
    strictAssertSame('a', $node['value'][2]['key'], 'the last of which holds the nesting');

    $walk = $node['value'][2]['value'];
    $levels = 0;
    while ($walk['type'] === 'list' && count($walk['value']) === 1) {
        $levels++;
        $walk = $walk['value'][0];
    }
    if ($walk['type'] === 'list') $levels++;
    strictAssertSame($depth, $levels, 'and every one of the nested lists is present as a typed node');

    // Every ceiling is slack enough that the byte cap is what binds. A token
    // costs at least two bytes ('le', 'de', '0:'), and so does a level of
    // nesting, so 8192 bytes can spell at most 4096 of either.
    $widest = 'l' . str_repeat('le', 4095) . 'e';
    strictAssertSame(8192, strlen($widest), 'the token-densest admitted body is exactly the cap');
    strictAssertSame('list', RuTrackerBencode::decode($widest, $limits)['type'],
        '4096 tokens -- the most 8192 bytes can spell -- are admitted');

    $deepest = str_repeat('l', 4096) . str_repeat('e', 4096);
    strictAssertSame(8192, strlen($deepest), 'the depth-densest admitted body is exactly the cap');
    strictAssertSame('list', RuTrackerBencode::decode($deepest, $limits)['type'],
        'depth 4096 -- the most 8192 bytes can spell -- is admitted');

    $longest = '8190:' . str_repeat('a', 8187);
    strictAssertSame(false, RuTrackerBencode::decode($longest, $limits),
        'a four-digit length that overruns the body is still truncation, not an accepted string');
    strictAssertSame(array('type' => 'string', 'value' => str_repeat('a', 8187)),
        RuTrackerBencode::decode('8187:' . str_repeat('a', 8187), $limits),
        'the longest string 8192 bytes can carry needs only four length digits');
});

exit($suite->run());
