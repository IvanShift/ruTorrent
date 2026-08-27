<?php

/**
 * The one bencode grammar this plugin owns.
 *
 * There used to be two. announce.php carried a recursive scanner that also
 * inherited Torrent's decoder -- two passes over the same bytes, each with its
 * own idea of what "valid" meant -- and trackers/nnmclub.php carried a
 * handwritten stack machine with the schema check woven through it. Every
 * syntax rule (canonical integers, canonical length prefixes, duplicate keys,
 * trailing bytes) therefore existed twice and could drift apart silently.
 *
 * This decoder answers ONE question: is the payload a single, fully consumed,
 * well-formed bencode value inside the caller's explicit ceilings? It knows
 * nothing about announce replies or scrape rows -- the two schema validators
 * stay in their own files, reading the typed tree below.
 *
 * The tree is typed because Torrent::decode() cannot be reused here: it turns
 * integers into floats and collapses an empty string, an empty list and an
 * empty dictionary into the same PHP value, and the announce schema has to
 * tell those three apart (an empty peer LIST is a valid answer; an empty peer
 * DICTIONARY is malformed).
 *
 *   array('type' => 'string',     'value' => $rawBytes)
 *   array('type' => 'integer',    'value' => $canonicalToken)
 *   array('type' => 'list',       'value' => array($node, ...))
 *   array('type' => 'dictionary', 'value' => array(array('key' => $rawKeyBytes,
 *                                                        'value' => $node), ...))
 *
 * An integer stays the exact token the payload spelled, NOT a PHP number: the
 * consumers range-check it themselves (announce ports, NNM counters), and an
 * early cast would saturate an oversized token into PHP_INT_MAX and hand the
 * schema a value the tracker never sent.
 *
 * A dictionary is an ORDERED list of entry records rather than an associative
 * array, because PHP coerces numeric-looking string keys to integers. Raw
 * bencode keys are arbitrary bytes -- NNM matches a 20-byte binary info hash
 * with === -- so a coerced key would stop matching, and two distinct raw keys
 * could collapse into one entry.
 */
class RuTrackerBencode
{
    /**
     * Decode one bencode payload.
     *
     * Everything is bounded by the caller, in bytes, nesting depth, token
     * count, integer digits and length-prefix digits. The body this plugin
     * decodes comes off the network, and the ceilings are what stop a
     * pathological answer from costing more than the answer is worth; they are
     * required rather than defaulted so that neither consumer can inherit a
     * limit it never chose.
     *
     * @param  string $payload Raw bencode bytes
     * @param  array  $limits  max_bytes, max_depth, max_tokens,
     *                         max_integer_digits, max_length_digits
     * @return array|false One typed root node, or false for anything that is
     *                     not a single fully consumed value within the limits
     */
    public static function decode($payload, $limits)
    {
        if (!is_string($payload) || !is_array($limits)) return false;

        $bounds = array();
        foreach (array('max_bytes', 'max_depth', 'max_tokens',
                       'max_integer_digits', 'max_length_digits') as $name) {
            // A missing or nonsensical ceiling is refused rather than guessed:
            // a decoder that silently invents its own bound is a decoder with
            // no bound the caller can reason about.
            if (!isset($limits[$name]) || !is_int($limits[$name]) || $limits[$name] < 1) return false;
            $bounds[$name] = $limits[$name];
        }

        $len = strlen($payload);
        if ($len === 0 || $len > $bounds['max_bytes']) return false;

        $pos = 0;
        $tokens = 0;
        $root = false;
        // One frame per open container. A loop over an explicit stack, not
        // recursion: the byte cap alone used to be the only thing standing
        // between a nested answer and a stack the process could not afford,
        // and exhausting it is a fatal error no caller can catch.
        $stack = array();

        while (true) {
            $depth = count($stack);
            if ($pos >= $len) return false;
            $byte = $payload[$pos];
            $node = null;

            if ($depth > 0) {
                $top = $depth - 1;
                if ($stack[$top]['type'] === 'dictionary' && $stack[$top]['pending'] === null) {
                    // The frame is between pairs: either the dictionary ends
                    // here or the next token is a key, which must be a string.
                    if ($byte === 'e') {
                        $pos++;
                        $node = array('type' => 'dictionary', 'value' => $stack[$top]['value']);
                        array_pop($stack);
                    } else {
                        if (!self::isDigit($byte)) return false;
                        $key = self::takeString($payload, $pos, $len, $bounds['max_length_digits']);
                        if ($key === false) return false;
                        if (++$tokens > $bounds['max_tokens']) return false;
                        // NOT an ordinary array key: "1" would become integer
                        // 1 and "0123" would not, so a raw-bytes guard needs a
                        // prefix no bencode key can produce a collision with.
                        $guard = "\0" . $key;
                        if (isset($stack[$top]['seen'][$guard])) return false;
                        $stack[$top]['seen'][$guard] = true;
                        $stack[$top]['pending'] = $key;
                        continue;
                    }
                } elseif ($stack[$top]['type'] === 'list' && $byte === 'e') {
                    $pos++;
                    $node = array('type' => 'list', 'value' => $stack[$top]['value']);
                    array_pop($stack);
                }
            }

            if ($node === null) {
                if ($byte === 'l' || $byte === 'd') {
                    // A container costs exactly one token, counted when it
                    // opens, so a document cannot buy extra budget by nesting.
                    if (++$tokens > $bounds['max_tokens']) return false;
                    if ($depth >= $bounds['max_depth']) return false;
                    $pos++;
                    $stack[] = array(
                        'type' => $byte === 'l' ? 'list' : 'dictionary',
                        'value' => array(),
                        'pending' => null,
                        'seen' => array(),
                    );
                    continue;
                }

                if ($byte === 'i') {
                    $token = self::takeInteger($payload, $pos, $len, $bounds['max_integer_digits']);
                    if ($token === false) return false;
                    if (++$tokens > $bounds['max_tokens']) return false;
                    $node = array('type' => 'integer', 'value' => $token);
                } elseif (self::isDigit($byte)) {
                    $raw = self::takeString($payload, $pos, $len, $bounds['max_length_digits']);
                    if ($raw === false) return false;
                    if (++$tokens > $bounds['max_tokens']) return false;
                    $node = array('type' => 'string', 'value' => $raw);
                } else {
                    return false;
                }
            }

            $parent = count($stack) - 1;
            if ($parent < 0) {
                $root = $node;
                break;
            }
            if ($stack[$parent]['type'] === 'list') {
                $stack[$parent]['value'][] = $node;
            } else {
                $stack[$parent]['value'][] = array(
                    'key' => $stack[$parent]['pending'],
                    'value' => $node,
                );
                $stack[$parent]['pending'] = null;
            }
        }

        // A payload is one value and nothing else. Trailing bytes are the
        // shape a tracker error page takes when it is appended to an otherwise
        // valid answer, and reading only the prefix would call that answer
        // trustworthy.
        if ($pos !== $len) return false;
        return $root;
    }

    private static function isDigit($byte)
    {
        return $byte >= '0' && $byte <= '9';
    }

    /**
     * Consume one `<length>:<bytes>` token and return its raw bytes.
     *
     * @param  int $pos Advanced past the token on success
     * @return string|false
     */
    private static function takeString($payload, &$pos, $len, $maxLengthDigits)
    {
        $colon = strpos($payload, ':', $pos);
        if ($colon === false) return false;
        $digits = $colon - $pos;
        if ($digits < 1 || $digits > $maxLengthDigits) return false;
        $prefix = substr($payload, $pos, $digits);
        if (!ctype_digit($prefix)) return false;
        // Canonical: "0" is the only spelling of zero and no length carries a
        // leading zero, so one string has exactly one encoding.
        if ($digits > 1 && $prefix[0] === '0') return false;
        $length = (int) $prefix;
        $start = $colon + 1;
        // Written as a subtraction rather than $start + $length so an absurd
        // prefix cannot overflow the comparison itself.
        if ($length > $len - $start) return false;
        $pos = $start + $length;
        return substr($payload, $start, $length);
    }

    /**
     * Consume one `i<token>e` and return the canonical token unchanged.
     *
     * @param  int $pos Advanced past the token on success
     * @return string|false
     */
    private static function takeInteger($payload, &$pos, $len, $maxIntegerDigits)
    {
        $end = strpos($payload, 'e', $pos + 1);
        if ($end === false) return false;
        $token = substr($payload, $pos + 1, $end - $pos - 1);
        $negative = isset($token[0]) && $token[0] === '-';
        $digits = $negative ? substr($token, 1) : $token;
        if ($digits === '' || !ctype_digit($digits)) return false;
        // Canonical: i01e and i-0e are two spellings of a number that already
        // has one, and a tracker answer that spells a counter twice over is
        // not an answer this plugin acts on.
        if (strlen($digits) > 1 && $digits[0] === '0') return false;
        if ($negative && $digits === '0') return false;
        // The sign is not a digit: the ceiling bounds magnitude, so i-1e and
        // i1e cost the same.
        if (strlen($digits) > $maxIntegerDigits) return false;
        $pos = $end + 1;
        return $token;
    }

    /**
     * The value of the FIRST entry whose raw key matches, or null.
     *
     * The decoder has already rejected duplicate keys, so "first" is "the
     * only". Both schema validators look their keys up by raw bytes and
     * compare with ===, which is why this walks entry records instead of
     * handing back an associative array.
     *
     * @param  array  $node Any node; a non-dictionary simply has no entries
     * @param  string $key  Raw key bytes
     * @return array|null The typed value node
     */
    public static function entry($node, $key)
    {
        if (!is_array($node) || !isset($node['type']) || $node['type'] !== 'dictionary') return null;
        foreach ($node['value'] as $entry) {
            if ($entry['key'] === $key) return $entry['value'];
        }
        return null;
    }
}
