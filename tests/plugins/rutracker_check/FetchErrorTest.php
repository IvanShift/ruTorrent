<?php

/**
 * Focused tests for plugins/rutracker_check/fetcherror.php.
 *
 * The classifier is the plugin's only reader of a third-party file's error
 * text, and the one place where remote text could reach a log line. It is a
 * leaf -- no checker, no handler, no Snoopy -- so this suite loads it and
 * nothing else beyond the shared runner.
 */

require_once(__DIR__ . '/TestLib.php');

$suite = new StrictTestSuite();

// The shared corpus, asserted here against the classifier directly. The same
// table is driven through both production callers by CheckerTest and
// NNMClubHandlerTest; this is the row-by-row statement of what those two are
// agreeing about.
$suite->test('every shared Snoopy message classifies to its token', function () {
    foreach (fetchErrorParityCases() as $case) {
        list($message, $expected) = $case;
        strictAssertSame($expected, RuTrackerFetchError::classify($message),
            'token for ' . var_export($message, true));
    }
});

// The eight sentences php/Snoopy.class.inc actually writes, spelled here with
// the values that file interpolates, so a merge that rewords one is caught by
// the token it stops producing rather than by a live log nobody reads.
$suite->test('each of Snoopy own eight messages has its own token', function () {
    $expected = array(
        'Invalid protocol "gopher"\n' => 'invalid-protocol',
        'Refusing to fetch: cannot resolve host "nx.invalid".' => 'refused-unresolvable-host',
        'Refusing to fetch: host "a.invalid" resolves to the non-public address 10.0.0.1.'
            => 'refused-non-public-address',
        'Error: cURL could not retrieve the document, error 28.' => 'curl-transfer',
        'socket creation failed (-3)' => 'socket-create',
        'dns lookup failure (-4)' => 'dns-lookup',
        'connection refused or timed out (-5)' => 'connect-refused',
        'connection failed (0)' => 'connect-errno',
    );
    strictAssertSame(8, count($expected), 'php/Snoopy.class.inc writes eight error messages');
    $seen = array();
    foreach ($expected as $message => $token) {
        strictAssertSame($token, RuTrackerFetchError::classify($message),
            'token for ' . var_export($message, true));
        $seen[$token] = true;
    }
    strictAssertSame(8, count($seen), 'the eight messages map onto eight distinct tokens');
});

// Whitespace is normalised before matching, not after. Snoopy's strings are
// vendored and the log is line-oriented, so a reworded message that wraps or
// re-spaces must still land on its token instead of on 'unclassified' for a
// reason that has nothing to do with what it says.
$suite->test('leading, trailing, repeated and embedded whitespace does not change the class',
        function () {
    $variants = array(
        'connection failed (111)',
        '  connection failed (111)',
        "connection failed (111)\n",
        "\tconnection failed (111)\t",
        'connection   failed (111)',
        "connection\tfailed (111)",
        "connection\nfailed (111)",
        "connection\r\nfailed (111)",
        "  \t connection   failed\t(111)\nhost bt4.t-ru.org  ",
        "\n\n connection \r\n\t failed  (111) \n\n",
    );
    foreach ($variants as $variant) {
        strictAssertSame('connect-errno', RuTrackerFetchError::classify($variant),
            'whitespace variant ' . var_export($variant, true));
    }
    // A message that is nothing but whitespace carries no more information
    // than an absent one, and says so the same way.
    foreach (array(' ', "\t", "\n", "  \r\n\t  ") as $blank) {
        strictAssertSame('', RuTrackerFetchError::classify($blank),
            'whitespace-only message ' . var_export($blank, true));
    }
});

// An absent message is evidence in its own right -- Snoopy's header loops and
// its early URI bail touch only the status -- and both callers render '' by
// omitting the error= field entirely rather than logging an empty one.
$suite->test('an empty or non-string message is reported as no message at all', function () {
    strictAssertSame('', RuTrackerFetchError::classify(''), 'the empty string');
    strictAssertSame('', RuTrackerFetchError::classify(null), 'null');
    strictAssertSame('', RuTrackerFetchError::classify(false), 'false');
    strictAssertSame('', RuTrackerFetchError::classify(true), 'true');
    strictAssertSame('', RuTrackerFetchError::classify(0), 'integer zero');
    strictAssertSame('', RuTrackerFetchError::classify(111), 'a bare errno');
    strictAssertSame('', RuTrackerFetchError::classify(1.5), 'a float');
    strictAssertSame('', RuTrackerFetchError::classify(array('connection failed (0)')),
        'an array is not unwrapped');
    strictAssertSame('', RuTrackerFetchError::classify(new stdClass()), 'an object');
});

// A sentence php/Snoopy.class.inc does not write today is the one nobody has
// audited, and the only kind that could carry a credential.
$suite->test('an unrecognised message classifies rather than echoing', function () {
    $unknown = array(
        'something php/Snoopy.class.inc does not say today',
        'Error fetching http://bt.t-ru.org/ann?pk=deadbeefcafe: refused',
        'Refusing to fetch: uk=AbCdEf0123456789AbCdEf0123456789 leaked',
        // Near-misses. Seven of the eight patterns are anchored at the start
        // of the message, so a sentence that merely contains one of Snoopy's
        // openings is not that message; and each ends at a word boundary, so
        // a different word is a different message. (A boundary is not a space:
        // 'socket creation failed-ish' would still match, which is why these
        // rows change a word rather than glue a suffix onto one.)
        'the connection failed (111)',
        'socket create failed (-3)',
        'dns lookup failed (-4)',
    );
    foreach ($unknown as $message) {
        strictAssertSame('unclassified', RuTrackerFetchError::classify($message),
            'unrecognised message ' . var_export($message, true));
    }
});

// The canary. Whatever the input, the answer comes out of this plugin's own
// fixed vocabulary, so no fragment of a remote sentence can reach the shared
// application log through the error= field -- there is no raw-text fallback to
// fall through to. Membership is the check that can see one; a list of known
// messages cannot.
$suite->test('no input produces anything but a token from the fixed vocabulary', function () {
    $vocabulary = fetchErrorTokenVocabulary();
    $inputs = array(
        '', null, false, 0, 1.5, array(), new stdClass(),
        'connection failed (111)',
        'Error fetching http://bt.t-ru.org/ann?pk=deadbeefcafe: refused',
        'uk=AbCdEf0123456789AbCdEf0123456789',
        "connection failed (111)\nuk=AbCdEf0123456789AbCdEf0123456789",
        'Invalid protocol "gopher://user:hunter2@host"',
        str_repeat('A', 4096),
        "\x00\x01\x02 binary \xff",
        'Поглощено',
    );
    foreach ($inputs as $input) {
        $token = RuTrackerFetchError::classify($input);
        strictAssertTrue(in_array($token, $vocabulary, true),
            'the answer is one of the plugin own tokens for ' . var_export($input, true)
                . ', got ' . var_export($token, true));
        strictAssertTrue(strpos($token, 'AbCdEf0123456789AbCdEf0123456789') === false
                && strpos($token, 'deadbeefcafe') === false
                && strpos($token, 'hunter2') === false,
            'no credential fragment survives into the answer: ' . var_export($token, true));
    }
});

// Every token the classifier can return is safe to append to a line-oriented
// log without quoting or redaction: that property is what lets both callers
// write ` error=<token>` unquoted at the end of a record.
$suite->test('every token is one plain-ASCII word with no whitespace', function () {
    foreach (fetchErrorTokenVocabulary() as $token) {
        if ($token === '') continue;
        strictAssertSame(1, preg_match('/^[a-z][a-z-]*[a-z]$/', $token),
            'the token is lower-case ASCII with no separator to break a log record: ' . $token);
    }
    // And the vocabulary is the whole vocabulary: every token the corpus and
    // the eight messages produce is in it.
    foreach (fetchErrorParityCases() as $case) {
        strictAssertTrue(in_array($case[1], fetchErrorTokenVocabulary(), true),
            'the corpus expects only tokens the vocabulary lists: ' . $case[1]);
    }
});

exit($suite->run());
