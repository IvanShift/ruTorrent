<?php

/**
 * One grammar for replacement ownership shared by the real checker, the
 * scheduler sweep and MetaFetch (including its lightweight checker double).
 *
 * A custom value is not ownership merely because it is non-empty. The marker
 * is the 128-bit hexadecimal nonce createTorrent() writes, while the record is
 * a strict predecessor/successor hash, one of three run tokens, and a positive
 * staging epoch. Keeping this standalone avoids copying the security boundary
 * into every class that has to recognize a replacement transaction.
 */
class RuTrackerReplacementRecord
{
    const RUN_STARTED = 'started';
    const RUN_OPEN = 'open';
    const RUN_STOPPED = 'stopped';

    static public function isPluginMarker($value)
    {
        return is_string($value) && preg_match('/^[0-9a-fA-F]{32}$/D', $value) === 1;
    }

    static public function encode($hash, $wasStarted, $wasOpen, $now)
    {
        if (!is_string($hash) || !preg_match('/^[0-9a-fA-F]{40}$/D', $hash)) {
            throw new InvalidArgumentException('Invalid hash in replacement record: ' . var_export($hash, true));
        }
        return strtoupper($hash) . '-'
            . ($wasStarted ? self::RUN_STARTED : ($wasOpen ? self::RUN_OPEN : self::RUN_STOPPED))
            . '-' . intval($now);
    }

    static public function decode($value)
    {
        if (!is_string($value)) return null;
        $parts = explode('-', $value);
        if (count($parts) !== 3) return null;
        if (!preg_match('/^[0-9a-fA-F]{40}$/D', $parts[0])) return null;
        if (!in_array($parts[1], array(self::RUN_STARTED, self::RUN_OPEN, self::RUN_STOPPED), true))
            return null;
        if (!ctype_digit($parts[2]) || intval($parts[2]) <= 0) return null;
        return array(
            'old' => $parts[0],
            'run' => array(
                'started' => $parts[1] === self::RUN_STARTED,
                'open' => $parts[1] === self::RUN_STARTED || $parts[1] === self::RUN_OPEN,
            ),
            'staged' => intval($parts[2]),
        );
    }
}

/**
 * Shared XML-RPC scalar value validation and parsing helpers.
 */
class RuTrackerRpcValue
{
    /**
     * Parse a value as a canonical nonnegative integer.
     * Accepts nonnegative PHP integer, or canonical decimal string matching
     * /^(?:0|[1-9][0-9]*)$/D that fits in PHP integer range.
     * Rejects booleans, floats, objects, arrays, signs, whitespace, leading zeroes.
     *
     * @param mixed $value
     * @return int|null Canonical integer or null if malformed/non-canonical
     */
    static public function canonicalNonnegativeInteger($value)
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)) {
            return null;
        }
        $parsed = (int) $value;
        return (string) $parsed === $value ? $parsed : null;
    }

    /**
     * Parse a value as a canonical positive 32-bit integer (e.g. topic ID or forum ID).
     * Accepts positive integer in range 1..2147483647 or exact canonical decimal string.
     *
     * @param mixed $value
     * @return int|null Positive int32 or null if invalid
     */
    static public function canonicalPositiveInt32($value)
    {
        if (is_int($value)) {
            return ($value > 0 && $value <= 2147483647) ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', $value)) {
            return null;
        }
        $parsed = (int) $value;
        return ($parsed > 0 && $parsed <= 2147483647 && (string) $parsed === $value) ? $parsed : null;
    }
}

/**
 * Prove a bundle of d.set_custom writes from either a complete reply or an
 * exact readback. rXMLRPCRequest::success() alone is insufficient because the
 * legacy parser can return true after extracting only a prefix of the values.
 */
class RuTrackerCustomProjection
{
    // Test commands keep raw scalar arguments, while php/xmlrpc.php wraps
    // every production argument in rXMLRPCParam and XML-escapes strings at
    // construction time. Read the original logical value at this boundary so
    // the expected projection is compared with the decoder's unescaped
    // readback rather than with transport markup such as "&amp;".
    static private function parameterValue($parameter)
    {
        if (is_object($parameter) && property_exists($parameter, 'value')) {
            $value = (string) $parameter->value;
            return !property_exists($parameter, 'type') || $parameter->type === 'string'
                ? html_entity_decode($value, ENT_NOQUOTES, 'UTF-8')
                : $value;
        }
        return (string) $parameter;
    }

    /** @return bool|null true when complete, null only when target absence is confirmed */
    static public function write($hash, $commands, $context)
    {
        $expected = array();
        foreach ($commands as $command) {
            $field = self::parameterValue($command->params[1]);
            $expected[$field] = self::parameterValue($command->params[2]);
        }

        $write = new rXMLRPCRequest($commands);
        $write->important = false;
        $writeOk = $write->success();
        if ($writeOk && !$write->fault && is_array($write->val)
            && count($write->val) === count($commands)) return true;

        $reads = array();
        foreach ($expected as $field => $value)
            $reads[] = new rXMLRPCCommand(getCmd('d.get_custom'), array($hash, $field));
        $verify = new rXMLRPCRequest($reads);
        $verify->important = false;
        if ($verify->success() && !$verify->fault && is_array($verify->val)
            && count($verify->val) === count($expected)) {
            $mismatches = array();
            $index = 0;
            foreach ($expected as $field => $value) {
                if ((string) $verify->val[$index] !== $value) $mismatches[] = $field;
                $index++;
            }
            if (!count($mismatches)) return true;
            ruTrackerChecker::logDebug($context . ': ' . $hash
                . ' has an incomplete projection; mismatched fields: '
                . implode(', ', $mismatches));
            return false;
        }

        $exists = ruTrackerChecker::torrentExists($hash);
        if ($exists === false) {
            ruTrackerChecker::logDebug($context . ': Torrent ' . $hash
                . ' not found after the unproved write');
            return null;
        }
        ruTrackerChecker::logDebug($context . ': outcome for ' . $hash
            . ' is unknown because the write projection could not be proved while absence was not confirmed');
        return false;
    }
}

/**
 * Shared atomic ownership helper executing daemon-side conditional branch commands
 * (branch + and + equal + cat) to eliminate read/action TOCTOU windows.
 */
class RuTrackerAtomicOwnership
{
    const ACTED = 'acted';
    const SKIPPED = 'skipped';
    const UNCONFIRMED = 'unconfirmed';
    const SPENT = 'spent';
    const UNKNOWN = 'unknown';

    const SENTINEL_ACTED = 'RUT_ATOMIC_ACTED';
    const SENTINEL_ERASED = 'RUT_ATOMIC_ERASED';
    const SENTINEL_CLEARED = 'RUT_ATOMIC_CLEARED';
    const SENTINEL_SKIPPED = 'RUT_ATOMIC_SKIPPED';
    const SENTINEL_UNCONFIRMED = 'RUT_ATOMIC_UNCONFIRMED';
    const SENTINEL_SPENT = 'RUT_ATOMIC_SPENT';
    const SENTINEL_REVIVED = 'RUT_ATOMIC_REVIVED';

    static private $allowedCustomKeys = array(
        'chk-replacement',
        'chk-replaces',
        'chk-replacing',
        'chk-revived',
        'chk-meta-old',
        'chk-meta-new',
        'chk-meta-until',
        'chk-meta-topic',
    );

    static public function quoteRtorrentArgument($value)
    {
        return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), (string) $value) . '"';
    }

    static private function isValidHash($hash)
    {
        return is_string($hash) && preg_match('/^[0-9a-fA-F]{40}$/D', $hash) === 1;
    }

    static private function validateCustomKeyAndValue($key, $value)
    {
        if (!is_string($key) || !in_array($key, self::$allowedCustomKeys, true)) {
            return false;
        }
        if ($value === '') {
            return true; // clearing key is allowed
        }
        if ($key === 'chk-replacement') {
            return RuTrackerReplacementRecord::isPluginMarker($value);
        }
        if ($key === 'chk-replaces' || $key === 'chk-replacing') {
            $decoded = RuTrackerReplacementRecord::decode($value);
            if ($decoded === null) return false;
            $reencoded = RuTrackerReplacementRecord::encode(
                $decoded['old'],
                $decoded['run']['started'],
                $decoded['run']['open'],
                $decoded['staged']
            );
            return $reencoded === $value;
        }
        if ($key === 'chk-revived' || $key === 'chk-meta-until' || $key === 'chk-meta-topic') {
            $int = RuTrackerRpcValue::canonicalNonnegativeInteger($value);
            return $int !== null && $int > 0;
        }
        if ($key === 'chk-meta-old' || $key === 'chk-meta-new') {
            return self::isValidHash($value);
        }
        return false;
    }

    static private function validateExpectedValues($expectedValues)
    {
        if (!is_array($expectedValues)) return false;
        foreach ($expectedValues as $k => $v) {
            if (!in_array($k, array('state', 'is_open', 'is_meta'), true)) {
                return false;
            }
            if ($v !== 0 && $v !== 1 && $v !== '0' && $v !== '1') {
                return false;
            }
        }
        return true;
    }

    static private function buildCondition($expectedCustoms, $expectedValues)
    {
        if (!is_array($expectedCustoms) || !self::validateExpectedValues($expectedValues)) {
            return null;
        }
        $conditions = array();
        foreach ($expectedCustoms as $k => $v) {
            if (!self::validateCustomKeyAndValue($k, $v)) {
                return null;
            }
            // Every accepted value is deliberately comma/quote/backslash-free.
            // Keeping it raw inside cat= lets the whole condition atom be
            // quoted exactly once when it becomes an and= argument. Quoting
            // both levels produces invalid grammar such as cat="VALUE" inside
            // an already quoted atom.
            $conditions[] = 'equal=' . getCmd('d.get_custom=') . $k . ',cat=' . $v;
        }
        foreach ($expectedValues as $k => $v) {
            $intVal = (int) $v;
            if ($k === 'state') {
                $conditions[] = 'equal=' . getCmd('d.get_state=') . ',value=' . $intVal;
            } elseif ($k === 'is_open') {
                $conditions[] = 'equal=' . getCmd('d.is_open=') . ',value=' . $intVal;
            } elseif ($k === 'is_meta') {
                $conditions[] = 'equal=' . getCmd('d.is_meta=') . ',value=' . $intVal;
            }
        }
        if (count($conditions) === 0) {
            return null;
        }
        if (count($conditions) === 1) {
            return $conditions[0];
        }
        return 'and=' . implode(',', array_map(function ($condition) {
            return self::quoteRtorrentArgument($condition);
        }, $conditions));
    }

    static private function executeBranch($hash, $condStr, $trueBody, $falseBody, $allowedReplies)
    {
        if (!self::isValidHash($hash) || $condStr === null || !is_array($allowedReplies)) {
            return self::UNKNOWN;
        }
        $cmd = new rXMLRPCCommand('branch', array($hash, $condStr, $trueBody, $falseBody));
        $req = new rXMLRPCRequest(array($cmd));
        $req->important = false;
        if (!$req->success() || $req->fault || !is_array($req->val) || count($req->val) !== 1) {
            return self::UNKNOWN;
        }
        $sentinel = $req->val[0];
        if (!is_string($sentinel) || !array_key_exists($sentinel, $allowedReplies)) {
            return self::UNKNOWN;
        }
        return $allowedReplies[$sentinel];
    }

    /**
     * Conditionally erase a download if its ownership customs and state match expectations.
     */
    static public function erase($hash, $expectedCustoms, $expectedValues = array())
    {
        $condStr = self::buildCondition($expectedCustoms, $expectedValues);
        if ($condStr === null) return self::UNKNOWN;

        $trueBody = 'cat="$' . getCmd('d.erase=') . '",' . self::SENTINEL_ERASED;
        $falseBody = 'cat=' . self::SENTINEL_SKIPPED;
        return self::executeBranch($hash, $condStr, $trueBody, $falseBody, array(
            self::SENTINEL_ERASED => self::ACTED,
            self::SENTINEL_SKIPPED => self::SKIPPED,
        ));
    }

    /**
     * Conditionally clear customs on a download in the given order if its ownership matches.
     */
    static public function clearCustoms($hash, $expectedCustoms, $keys, $expectedValues = array())
    {
        if (!is_array($keys) || count($keys) === 0) return self::UNKNOWN;
        foreach ($keys as $k) {
            if (!in_array($k, self::$allowedCustomKeys, true)) return self::UNKNOWN;
        }
        $condStr = self::buildCondition($expectedCustoms, $expectedValues);
        if ($condStr === null) return self::UNKNOWN;

        $parts = array();
        foreach ($keys as $k) {
            $parts[] = '"$' . getCmd('d.set_custom=') . $k . ',"';
        }
        $parts[] = self::SENTINEL_CLEARED;
        $trueBody = 'cat=' . implode(',', $parts);
        $falseBody = 'cat=' . self::SENTINEL_SKIPPED;
        return self::executeBranch($hash, $condStr, $trueBody, $falseBody, array(
            self::SENTINEL_CLEARED => self::ACTED,
            self::SENTINEL_SKIPPED => self::SKIPPED,
        ));
    }

    /**
     * Conditionally set customs on a download in the given order if its ownership matches.
     */
    static public function setCustoms($hash, $expectedCustoms, $customsToSet, $expectedValues = array())
    {
        if (!is_array($customsToSet) || count($customsToSet) === 0) return self::UNKNOWN;
        foreach ($customsToSet as $k => $v) {
            if (!self::validateCustomKeyAndValue($k, $v)) return self::UNKNOWN;
        }
        $condStr = self::buildCondition($expectedCustoms, $expectedValues);
        if ($condStr === null) return self::UNKNOWN;

        $parts = array();
        foreach ($customsToSet as $k => $v) {
            // $v is grammar-safe by validateCustomKeyAndValue(). The mutation
            // itself is the one quoted cat argument; quoting $v again would
            // terminate that argument early.
            $parts[] = self::quoteRtorrentArgument(
                '$' . getCmd('d.set_custom=') . $k . ',' . $v
            );
        }
        $parts[] = self::SENTINEL_ACTED;
        $trueBody = 'cat=' . implode(',', $parts);
        $falseBody = 'cat=' . self::SENTINEL_SKIPPED;
        return self::executeBranch($hash, $condStr, $trueBody, $falseBody, array(
            self::SENTINEL_ACTED => self::ACTED,
            self::SENTINEL_SKIPPED => self::SKIPPED,
        ));
    }

    /**
     * Conditionally open/start a download and verify immediate daemon postcondition.
     */
    static public function runState($hash, $expectedCustoms, $wantStarted, $expectedValues = array(), $afterSuccess = array())
    {
        if (!is_bool($wantStarted)) return self::UNKNOWN;
        if (!is_array($afterSuccess)) return self::UNKNOWN;
        foreach ($afterSuccess as $k => $v) {
            if (!self::validateCustomKeyAndValue($k, $v)) return self::UNKNOWN;
        }
        $condStr = self::buildCondition($expectedCustoms, $expectedValues);
        if ($condStr === null) return self::UNKNOWN;

        $afterParts = array();
        foreach ($afterSuccess as $k => $v) {
            $afterParts[] = self::quoteRtorrentArgument(
                '$' . getCmd('d.set_custom=') . $k . ',' . $v
            );
        }
        $afterParts[] = self::SENTINEL_ACTED;
        $successBody = 'cat=' . implode(',', $afterParts);

        $postCmd = $wantStarted ? getCmd('d.get_state=') : getCmd('d.is_open=');
        $nestedBranch = '$branch=' . $postCmd . ','
            . self::quoteRtorrentArgument($successBody)
            . ',cat=' . self::SENTINEL_UNCONFIRMED;

        $parts = array();
        $parts[] = self::quoteRtorrentArgument('$' . getCmd('d.open='));
        if ($wantStarted) {
            $parts[] = self::quoteRtorrentArgument('$' . getCmd('d.start='));
        }
        $parts[] = self::quoteRtorrentArgument($nestedBranch);
        $trueBody = 'cat=' . implode(',', $parts);
        $falseBody = 'cat=' . self::SENTINEL_SKIPPED;
        return self::executeBranch($hash, $condStr, $trueBody, $falseBody, array(
            self::SENTINEL_ACTED => self::ACTED,
            self::SENTINEL_SKIPPED => self::SKIPPED,
            self::SENTINEL_UNCONFIRMED => self::UNCONFIRMED,
        ));
    }

    /**
     * Conditionally revive a predecessor torrent: checks active chk-replacing, state=0, is_open=0,
     * checks if chk-revived is already equal to stamp (SPENT), otherwise opens/starts, verifies postcondition,
     * writes chk-revived and clears chk-replacing on verified success.
     */
    static public function revivePredecessor($hash, $expectedReplacing, $recordedRun, $stamp, $expectedValues = array())
    {
        $stampInt = RuTrackerRpcValue::canonicalNonnegativeInteger($stamp);
        if ($stampInt === null || $stampInt <= 0) return self::UNKNOWN;
        if (!is_array($recordedRun)
            || count($recordedRun) !== 2
            || !array_key_exists('started', $recordedRun)
            || !array_key_exists('open', $recordedRun)
            || !is_bool($recordedRun['started'])
            || !is_bool($recordedRun['open'])
            || ($recordedRun['started'] && !$recordedRun['open'])
            || (!$recordedRun['started'] && !$recordedRun['open'])) {
            return self::UNKNOWN;
        }

        $expectedCustoms = array('chk-replacing' => $expectedReplacing);
        // Revival is authorized only for the stopped+closed crash signature.
        // Caller predicates may add is_meta, but can never weaken these two.
        $allExpectedValues = array_merge((array) $expectedValues,
            array('state' => 0, 'is_open' => 0));
        $condStr = self::buildCondition($expectedCustoms, $allExpectedValues);
        if ($condStr === null) return self::UNKNOWN;

        $wantStarted = !empty($recordedRun['started']);
        $postCmd = $wantStarted ? getCmd('d.get_state=') : getCmd('d.is_open=');

        $successActions = 'cat='
            . self::quoteRtorrentArgument('$' . getCmd('d.set_custom=') . 'chk-revived,' . $stampInt)
            . ',' . self::quoteRtorrentArgument('$' . getCmd('d.set_custom=') . 'chk-replacing,')
            . ',' . self::SENTINEL_REVIVED;

        $verifyBranch = '$branch=' . $postCmd . ',' . self::quoteRtorrentArgument($successActions)
            . ',cat=' . self::SENTINEL_UNCONFIRMED;

        $unspentParts = array();
        $unspentParts[] = self::quoteRtorrentArgument('$' . getCmd('d.open='));
        if ($wantStarted) {
            $unspentParts[] = self::quoteRtorrentArgument('$' . getCmd('d.start='));
        }
        $unspentParts[] = self::quoteRtorrentArgument($verifyBranch);
        $unspentBody = 'cat=' . implode(',', $unspentParts);

        $spentCheck = 'equal=' . getCmd('d.get_custom=') . 'chk-revived,cat=' . $stampInt;
        $spentBranch = 'branch=' . self::quoteRtorrentArgument($spentCheck)
            . ',' . self::quoteRtorrentArgument('cat=' . self::SENTINEL_SPENT)
            . ',' . self::quoteRtorrentArgument($unspentBody);

        $trueBody = $spentBranch;
        $falseBody = 'cat=' . self::SENTINEL_SKIPPED;
        return self::executeBranch($hash, $condStr, $trueBody, $falseBody, array(
            self::SENTINEL_REVIVED => self::ACTED,
            self::SENTINEL_SKIPPED => self::SKIPPED,
            self::SENTINEL_UNCONFIRMED => self::UNCONFIRMED,
            self::SENTINEL_SPENT => self::SPENT,
        ));
    }
}
