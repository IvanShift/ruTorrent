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

// The sentences php/Snoopy.class.inc actually writes, spelled here with
// the values that file interpolates, so a merge that rewords one is caught by
// the token it stops producing rather than by a live log nobody reads.
$suite->test('each of Snoopy own messages has its own token', function () {
    $expected = array(
        'invalid-url' => 'invalid-url',
        'Invalid protocol "gopher"\n' => 'invalid-protocol',
        'Refusing to fetch: cannot resolve host "nx.invalid".' => 'refused-unresolvable-host',
        'Refusing to fetch: host "a.invalid" resolves to the non-public address 10.0.0.1.'
            => 'refused-non-public-address',
        'credential-redirect-refused' => 'redirect-refused',
        'Error: cURL could not retrieve the document, error 28.' => 'curl-transfer',
        'Error: cURL could not verify the certificate of "tracker.invalid" (error 60). Install the certificate authority that signed it.' => 'certificate-verification',
        'socket creation failed (-3)' => 'socket-create',
        'dns lookup failure (-4)' => 'dns-lookup',
        'connection refused or timed out (-5)' => 'connect-refused',
        'connection failed (0)' => 'connect-errno',
        'too-many-interim-responses' => 'too-many-interim-responses',
        'missing-final-response' => 'missing-final-response',
        'unsupported-transfer-encoding' => 'unsupported-transfer-encoding',
        'invalid-or-oversized-chunked' => 'invalid-or-oversized-chunked',
        'oversized-response' => 'oversized-response',
        'invalid-or-oversized-gzip' => 'invalid-or-oversized-gzip',
        'gzip-decoder-unavailable' => 'gzip-decoder-unavailable',
        'unreadable-or-oversized-response' => 'unreadable-or-oversized-response',
        'request-deadline' => 'request-deadline',
        'request-throttled' => 'request-throttled',
        'credential-post-failed' => 'credential-post-failed',
    );
    $source = file_get_contents(__DIR__ . '/../../../php/Snoopy.class.inc');
    strictAssertTrue(is_string($source), 'Snoopy source is readable');
    preg_match_all('/\$this->error\s*=/', $source, $assignments);
    // The original assignments, initializer, shared refusal helper,
    // second account-port refusal and zero-errno resolver failure are classified.
    strictAssertSame(15, count($assignments[0]),
        'new direct Snoopy error assignments require classification');
    // Compare distinct reasons, not call sites: redirects and transports may
    // reuse a refusal, while a new reason still needs explicit classification.
    preg_match_all('/refuseResponseBody\(\s*\'([^\']+)\'\s*\)/', $source, $refusals);
    $actualRefusals = array_values(array_unique($refusals[1]));
    $expectedRefusals = array('invalid-url', 'too-many-interim-responses', 'missing-final-response',
        'unsupported-transfer-encoding', 'invalid-or-oversized-chunked',
        'oversized-response', 'invalid-or-oversized-gzip', 'gzip-decoder-unavailable',
        'unreadable-or-oversized-response', 'request-deadline');
    sort($actualRefusals);
    sort($expectedRefusals);
    strictAssertSame($expectedRefusals, $actualRefusals,
        'every distinct body refusal requires an explicit classified case');
    strictAssertTrue(strpos($source, "\$this->error = 'oversized-response';") !== false,
        'the oversized response assignment stays classified');
    // Count alone misses a reworded assignment. For direct assignments,
    // include the opening quote and literal prefix: a changed first word
    // otherwise still matches a needle taken from the middle of the message.
    // Body refusal tokens are exact helper arguments.
    $sourceNeedles = array(
        'invalid-url' => 'invalid-url',
        'invalid-protocol' => '\'Invalid protocol "',
        'refused-unresolvable-host' => '\'Refusing to fetch: cannot resolve host "',
        'refused-non-public-address' => '\'Refusing to fetch: host "',
        'redirect-refused' => 'self::CREDENTIAL_REDIRECT_REFUSED',
        'curl-transfer' => '"Error: cURL could not retrieve the document, error ',
        'certificate-verification' => '"Error: cURL could not verify the certificate of ',
        'socket-create' => '"socket creation failed (-3)"',
        'dns-lookup' => '"dns lookup failure (-4)"',
        'connect-refused' => '"connection refused or timed out (-5)"',
        'connect-errno' => '"connection failed ("',
        'too-many-interim-responses' => 'too-many-interim-responses',
        'missing-final-response' => 'missing-final-response',
        'unsupported-transfer-encoding' => 'unsupported-transfer-encoding',
        'invalid-or-oversized-chunked' => 'invalid-or-oversized-chunked',
        'oversized-response' => 'oversized-response',
        'invalid-or-oversized-gzip' => 'invalid-or-oversized-gzip',
        'gzip-decoder-unavailable' => 'gzip-decoder-unavailable',
        'unreadable-or-oversized-response' => 'unreadable-or-oversized-response',
        'request-deadline' => 'request-deadline',
    );
    strictAssertSame(1, preg_match("/const CREDENTIAL_REDIRECT_REFUSED\s*=\s*'credential-redirect-refused';/", $source),
        'the redirect refusal constant retains the classified token');
    // The throttle refusal is a constant too, so the literal scan above
    // cannot see it; its definition and its one refusal are pinned here.
    strictAssertSame(1, preg_match("/const REQUEST_THROTTLED\s*=\s*'request-throttled';/", $source),
        'the throttle refusal constant retains the classified token');
    strictAssertSame(1, preg_match('/refuseResponseBody\(\s*self::REQUEST_THROTTLED\s*\)/', $source),
        'request-throttled: Snoopy still refuses with the reason this token classifies');
    // The KinozalTV account marks a failed credential POST with a constant too.
    $kinozal = (string) file_get_contents(__DIR__ . '/../../../php/kinozal.php');
    strictAssertSame(1, preg_match("/const CREDENTIAL_POST_FAILED\s*=\s*'credential-post-failed';/", $kinozal),
        'the credential POST mark retains the classified token');
    foreach ($sourceNeedles as $token => $needle) {
        $isBodyRefusal = in_array($token, $expectedRefusals, true);
        $pattern = $isBodyRefusal
            ? '/refuseResponseBody\(\s*\'' . preg_quote($needle, '/') . '\'\s*\)/'
            : '/\$this->error\s*=\s*[^;]*' . preg_quote($needle, '/') . '[^;]*;/s';
        strictAssertSame(1, preg_match($pattern, $source),
            $token . ': Snoopy still writes the reason this token classifies');
    }
    $seen = array();
    foreach ($expected as $message => $token) {
        strictAssertSame($token, RuTrackerFetchError::classify($message),
            'token for ' . var_export($message, true));
        $seen[$token] = true;
    }
    strictAssertSame(count($expected), count($seen), 'the messages map onto distinct tokens');
});

// A second DNS sentence is an errno variant, not a new diagnostic category.
// Keep it separate from the one-message-per-token assertion above.
$suite->test('the zero errno resolver message retains the existing DNS token', function () {
    $source = file_get_contents(__DIR__ . '/../../../php/Snoopy.class.inc');
    strictAssertSame(1, preg_match('/\$this->error\s*=\s*\'dns lookup failure \(0\)\';/', $source),
        'the zero-errno resolver assignment keeps its classified sentence');
    strictAssertSame('dns-lookup', RuTrackerFetchError::classify('dns lookup failure (0)'),
        'a recognised resolver failure keeps its DNS category even without an errno');
    strictAssertSame('connect-errno', RuTrackerFetchError::classify('connection failed (0)'),
        'an unclassified zero-errno connection failure does not imply DNS');
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

// An absent message is evidence in its own right. Both callers render '' by
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
        // Near-misses. Most transport patterns are anchored at the start;
        // curl-transfer is intentionally a substring. The rows here exercise
        // the anchored patterns and their word boundaries, so
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
    // Snoopy messages produce is in it.
    foreach (fetchErrorParityCases() as $case) {
        strictAssertTrue(in_array($case[1], fetchErrorTokenVocabulary(), true),
            'the corpus expects only tokens the vocabulary lists: ' . $case[1]);
    }
});


$suite->test('challenge routing needs interstitial evidence, not prose or JavaScript Detections', function () {
    foreach (array('Please wait just a moment', '<script src="/cdn-cgi/challenge-platform/scripts/jsd/main.js"></script>',
        '<p>cf-chl challenge-platform</p>') as $body)
        strictAssertSame(false, RuTrackerFetchError::isChallenge(array(), $body), 'ordinary body is not a routing signal');
    strictAssertSame(true, RuTrackerFetchError::isChallenge(array(' CF-Mitigated: challenge '), ''), 'explicit header');
    strictAssertSame(true, RuTrackerFetchError::isChallenge(array('cf-mitigated: challenge'),
        '<title>Подождите</title><script src="/cdn-cgi/changed.js"></script>'),
        'the documented header routes a changed or localised body');
    strictAssertSame(true, RuTrackerFetchError::isChallenge(array(), '<title>Just a moment...</title>'), 'interstitial title');
    strictAssertSame(true, RuTrackerFetchError::isChallenge(array(), '<script src="/cdn-cgi/challenge-platform/h/g/orchestrate/chl_page/v1"></script>'), 'interstitial script');
});

$suite->test('a long unrelated script path does not hide a later challenge title', function () {
    $body = '/cdn-cgi/challenge-platform/' . str_repeat('segment/', 30)
        . 'not-a-challenge.js<title>Just a moment...</title>';
    strictAssertSame(true, RuTrackerFetchError::isChallenge(array(), $body), 'later title is still inspected');
});

$suite->test('repeated challenge path prefixes preserve signals with bounded work', function () {
    $prefixes = '/' . str_repeat('cdn-cgi/challenge-platform/', 20000);
    $cases = array(
        array($prefixes . '<title>Just a moment...</title>', true, 'later title'),
        array($prefixes . 'orchestrate/chl_page/v1', true, 'path marker'),
        array($prefixes . 'not-a-challenge.js', false, 'unrelated path'),
    );
    $failures = array();
    // Run this file in separate JIT-on/off processes: changing the ini value
    // cannot remove JIT code from a regex already in PCRE's pattern cache.
    foreach ($cases as $case) {
        $start = microtime(true);
        $actual = RuTrackerFetchError::isChallenge(array(), $case[0]);
        $elapsed = microtime(true) - $start;
        if ($actual !== $case[1] || preg_last_error() !== PREG_NO_ERROR || $elapsed > 2.0)
            $failures[] = $case[2] . ' jit=' . ini_get('pcre.jit') . ' answer=' . (int) $actual
                . ' pcre=' . preg_last_error() . ' elapsed=' . $elapsed;
    }
    strictAssertSame(array(), $failures, 'signals and negative control remain readable within two seconds');
});

exit($suite->run());
