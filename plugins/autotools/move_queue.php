<?php

/** The native queue owns intent and retry; PHP owns only the payload journal. */
final class AutoToolsMoveQueueRecord
{
    const WAIT = array('source-not-ready', 'transport-unavailable');
    const COUNTED = array('preflight-refused', 'storage-failed', 'worker-exit');

    public static function parse($raw)
    {
        if (!is_string($raw) || strlen($raw) > 256
            || !preg_match('/^m1:([0-9a-f]{64}):([0-9a-f]{32}):([0-9a-fA-F]{40}):(0|[1-9][0-9]{0,10}):(.*)\n$/D', $raw, $m))
            throw new RuntimeException('queue-record-malformed');
        self::retry($m[5], $m[2]);
        return array('user'=>$m[1], 'qid'=>$m[2], 'local'=>$m[3], 'enqueued'=>(int)$m[4], 'retry'=>$m[5]);
    }

    public static function retry($raw, $qid)
    {
        if ($raw === '') return array('count'=>0, 'due'=>0, 'reason'=>'');
        if (!preg_match('/^r1:([0-9a-f]{32}):([0-6]):(0|[1-9][0-9]{0,10}):([a-z-]+)$/D', $raw, $m)
            || $m[1] !== $qid || !in_array($m[4], array_merge(self::WAIT, self::COUNTED), true)
            || ($m[2] === '0' && !in_array($m[4], self::WAIT, true))
            || (($m[3] === '0') !== ($m[2] === '6')))
            throw new RuntimeException('queue-retry-malformed');
        return array('count'=>(int)$m[2], 'due'=>(int)$m[3], 'reason'=>$m[4]);
    }

    public static function state($raw)
    {
        if ($raw === 'none') return array('phase'=>'none');
        if (preg_match('/^queued:([0-9a-f]{64}):([0-9a-f]{32}):([0-9a-fA-F]{40}):(.*)$/D', $raw, $m)) {
            self::retry($m[4], $m[2]);
            return array('phase'=>'queued', 'user'=>$m[1], 'qid'=>$m[2], 'local'=>$m[3], 'retry'=>$m[4]);
        }
        if (preg_match('/^leased:([0-9a-f]{64}):([0-9a-f]{32}):([0-9a-f]{32}:[0-9a-f]{16})$/D', $raw, $m))
            return array('phase'=>'leased', 'user'=>$m[1], 'qid'=>$m[2], 'token'=>$m[3]);
        if (preg_match('/^terminal:([0-9a-f]{64}):([0-9a-f]{32}):([a-z-]+)$/D', $raw, $m))
            return array('phase'=>'terminal', 'user'=>$m[1], 'qid'=>$m[2], 'reason'=>$m[3]);
        if (preg_match('/^held:([a-z-]+)$/D', $raw, $m)) return array('phase'=>'held', 'reason'=>$m[1]);
        throw new RuntimeException('queue-state-malformed');
    }
}

final class AutoToolsMoveQueue
{
    public static function call($method, $params)
    {
        $request = new rXMLRPCRequest(new rXMLRPCCommand($method, $params));
        $request->important = false;
        if (!$request->success() || $request->fault || count($request->val) !== 1
            || !is_scalar($request->val[0])) throw new RuntimeException('transport-unavailable');
        return (string)$request->val[0];
    }

    public static function state($hash)
    {
        return AutoToolsMoveQueueRecord::state(self::call('system.autotools.move_state', array('', $hash)));
    }

    public static function owner($hash, $state)
    {
        if (isset($state['user'])) return $state['user'];
        $owner = self::call('system.autotools.move_owner', array('', $hash));
        if (!preg_match('/^[0-9a-f]{64}$/D', $owner)) throw new RuntimeException('queue-owner-unconfirmed');
        return $owner;
    }

    /** Keep version-specific target insertion in the shared command alias factory. */
    public static function downloadRows($columns = array())
    {
        $request = new rXMLRPCRequest(new rXMLRPCCommand('d.multicall',
            array_merge(array('main', 'd.hash='), $columns)));
        $request->important = false;
        if (!$request->success() || $request->fault)
            throw new RuntimeException('download-list-unconfirmed');
        $rows = $request->stringTable(1 + count($columns));
        if ($rows === null) throw new RuntimeException('download-list-malformed');
        $seen = array();
        foreach ($rows as $row) {
            if (!preg_match('/^[0-9A-F]{40}$/D', $row[0]) || isset($seen[$row[0]]))
                throw new RuntimeException('download-list-malformed');
            $seen[$row[0]] = true;
        }
        return $rows;
    }

    public static function terminalCount($user)
    {
        try { $hashes = array_column(self::downloadRows(), 0); }
        catch (RuntimeException $e) { return null; }
        $states = new rXMLRPCRequest();
        foreach ($hashes as $hash)
            $states->addCommand(new rXMLRPCCommand('system.autotools.move_state', array('', $hash)));
        if (!$hashes) return 0;
        $states->important = false;
        if (!$states->success() || $states->fault || count($states->val) !== count($hashes)) return null;
        $count = 0;
        try {
            foreach ($states->val as $index => $raw) {
                $state = AutoToolsMoveQueueRecord::state($raw);
                if (in_array($state['phase'], array('terminal', 'held'), true)
                    && self::owner($hashes[$index], $state) === hash('sha256', $user)) ++$count;
            }
        } catch (RuntimeException $e) { return null; }
        return $count;
    }

    public static function owns($state, $qid, $user)
    {
        return in_array($state['phase'], array('queued', 'leased'), true)
            && $state['qid'] === $qid && $state['user'] === hash('sha256', $user);
    }

    public static function retryPlan($hash, $qid, $reason)
    {
        $state = self::state($hash);
        if ($state['phase'] !== 'queued' || $state['qid'] !== $qid)
            throw new RuntimeException('queue-defer-unowned');
        $retry = AutoToolsMoveQueueRecord::retry($state['retry'], $qid);
        if ($retry['count'] === 6) return;
        $wait = in_array($reason, AutoToolsMoveQueueRecord::WAIT, true);
        if (!$wait && !in_array($reason, AutoToolsMoveQueueRecord::COUNTED, true))
            throw new RuntimeException('queue-reason-invalid');
        $count = $retry['count'] + ($wait ? 0 : 1);
        $due = $count === 6 ? 0 : time() + ($wait ? 30 : 60 * (1 << ($count - 1)));
        $next = 'r1:' . $qid . ':' . $count . ':' . $due . ':' . $reason;
        return array('expected'=>$state['retry'], 'next'=>$next, 'reason'=>$reason);
    }

    public static function defer($hash, $qid, $reason)
    {
        $plan = self::retryPlan($hash, $qid, $reason);
        if ($plan !== null) self::deferPlan($hash, $qid, $plan);
    }

    public static function deferPlan($hash, $qid, $plan)
    {
        $next = $plan['next']; $reason = $plan['reason'];
        $retry = AutoToolsMoveQueueRecord::retry($next, $qid);
        $count = $retry['count'];
        $wait = in_array($reason, AutoToolsMoveQueueRecord::WAIT, true);
        $current = self::state($hash);
        if (($current['phase'] === 'queued' && $current['qid'] === $qid && $current['retry'] === $next)
            || ($current['phase'] === 'terminal' && $current['qid'] === $qid && $count === 6 && $current['reason'] === $reason)) return;
        if ($current['phase'] !== 'queued' || $current['qid'] !== $qid || $current['retry'] !== $plan['expected'])
            throw new RuntimeException('queue-defer-generation-changed');
        try { $answer = self::call('d.autotools.move_defer', array($hash, $qid, $plan['expected'], $next)); }
        catch (RuntimeException $e) { $answer = ''; }
        if ($answer !== 'deferred') {
            $current = self::state($hash);
            if (($count !== 6 && ($current['phase'] !== 'queued' || $current['qid'] !== $qid || $current['retry'] !== $next))
                || ($count === 6 && ($current['phase'] !== 'terminal' || $current['qid'] !== $qid || $current['reason'] !== $reason)))
                throw new RuntimeException('queue-defer-unconfirmed');
        }
        FileUtil::toLog('autotools: move ' . $hash . ' ' . ($count === 6 ? 'terminal: ' : 'waiting: ')
            . $reason . ($wait ? '' : '; attempt ' . $count . ' of 6'));
        if ($count === 6) AutoToolsMoveJob::showRefusal($hash, $reason,
            '; retries exhausted; reset with tools/autotools-move-reset.php ' . $hash . ' --apply');
    }

    private static function remove($hash, $qid, $reason)
    {
        $method = $reason === null ? 'd.autotools.move_finish' : 'd.autotools.move_cancel';
        $params = array($hash, $qid);
        if ($reason !== null) $params[] = $reason;
        try { $answer = self::call($method, $params); }
        catch (RuntimeException $e) { $answer = ''; }
        if (!in_array($answer, array('finished', 'none', 'cancelled'), true)
            && self::state($hash)['phase'] !== 'none') throw new RuntimeException('queue-retire-unconfirmed');
    }

    public static function finish($hash, $qid) { self::remove($hash, $qid, null); }

    public static function planningFailure($hash, $qid, $detail)
    {
        $reason = preg_split('/[:\s]/', $detail, 2)[0];
        $context = substr($detail, strlen($reason));
        $quiet = array('configuration-declined', 'source-outside-downloads');
        if (!in_array($reason, $quiet, true)) FileUtil::toLog('autotools: move ' . $hash . ' planning held: ' . $detail);
        $permanent = array('skip-filter-match', 'destination-outside-finished-root', 'manifest-path-invalid',
            'source-name-mismatch', 'manifest-empty');
        if (in_array($reason, array_merge($quiet, $permanent), true)) {
            if (!in_array($reason, $quiet, true)) AutoToolsMoveJob::showRefusal($hash, $reason, $context);
            self::remove($hash, $qid, $reason);
            FileUtil::toLog('autotools: move ' . $hash . ' cancelled: ' . $reason);
            return;
        }
        if ($reason === 'session-copy-unreadable' || $reason === 'source-not-ready') $reason = 'source-not-ready';
        elseif (strpos($reason, 'rpc-') === 0) $reason = 'transport-unavailable';
        elseif (strpos($reason, 'journal-') === 0 || strpos($reason, 'storage-') === 0) $reason = 'storage-failed';
        else {
            AutoToolsMoveJob::showRefusal($hash, $reason, $context);
            $reason = 'preflight-refused';
        }
        self::defer($hash, $qid, $reason);
    }
}
