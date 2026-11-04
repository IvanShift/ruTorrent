<?php

require_once(__DIR__ . '/RetrackersTestStubs.php');
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_DISPATCH_IMPORT_ONLY', true);
require_once(__DIR__ . '/../../../plugins/retrackers/dispatch.php');
require_once(__DIR__ . '/../../../plugins/retrackers/applyexisting.php');

class ApplyExistingAdapter
{
    public $jobs = [];
    public $writes = [];
    public $capable = true;
    public $answer = 'queued';

    public function getFamily()
    {
        return(2);
    }
    public function nativeQueueCapability(&$failure = null)
    {
        return($this->capable);
    }
    public function enqueueRefusalRows(&$failure = null)
    {
        return([
            [str_repeat('A', 40), str_repeat('B', 40), 'session-storage-failed', hash('sha256', 'alice')],
            [str_repeat('C', 40), str_repeat('D', 40), 'session-storage-failed', hash('sha256', 'bob')],
        ]);
    }
    public function downloadLocalIdRows(&$failure = null)
    {
        $rows = [];
        foreach ($this->jobs as $hash => $job) {
            $rows[] = [$hash, $job['local_id']];
        }
        return($rows);
    }
    public function applyExistingSnapshot($hash, &$failure = null)
    {
        if (!isset($this->jobs[$hash])) {
            $failure = 'target-absent';
            return(false);
        }
        return($this->jobs[$hash]);
    }
    public function enqueueOriginal($hash, $id, $user, $state, &$failure = null, $guarded = false)
    {
        if (!$guarded) {
            throw new RuntimeException('operator enqueue must be guarded');
        }
        $this->writes[] = [$hash, $id, $user, $state];
        if ($this->answer === 'queued') {
            $this->jobs[$hash]['queued'] = 'Q';
        }
        if ($this->answer === false) {
            $failure = 'read-timeout';
        }
        return($this->answer);
    }
}

class ApplyExistingTest extends TestCase
{
    public function testEveryCandidateRuleHasAClassifiedSkip()
    {
        foreach (['private' => ['private', '1'],
            'metadata-stub' => ['name', str_repeat('A', 40) . '.meta'],
            'already-has-retrackers' => ['trackers', ['https://add.test/a']],
            'queued' => ['queued', 'Q'], 'claimed' => ['claim', 'held'],
            'recovery' => ['recovery', 'R'], 'ack' => ['ack', 'A'],
            'stop-lease' => ['lease', 'L'], 'move-pending' => ['move', 'queued:owner:q:id:1']] as $reason => $change) {
            $adapter = $this->adapter();
            $adapter->jobs[str_repeat('A', 40)][$change[0]] = $change[1];
            list($code, $out) = $this->runTool($adapter, ['--apply']);
            $expected = in_array($reason, ['recovery', 'ack'], true) ? 'claimed' : $reason;
            $this->assertTrue($code === 0 && strpos($out, str_repeat('A', 40) . ' ' . $expected) !== false
                && $adapter->writes === [], $reason . ' skips without mutation');
        }
    }
    public function testDryRunRawTrackersPrivatePolicyAndExactEnqueue()
    {
        $adapter = $this->adapter();
        $hash = str_repeat('A', 40);
        list($code, $out) = $this->runTool($adapter);
        $this->assertTrue(
            $code === 0 && strpos($out, ' candidate') !== false && $adapter->writes === [],
            'dry run is read only',
        );
        $adapter->jobs[$hash]['private'] = '1';
        $adapter->jobs[$hash]['trackers'] = ['https://add.test/a '];
        list($code) = $this->runTool($adapter, ['--user=alice', '--apply'], ['dont_add_private' => false]);
        $this->assertTrue(
            $code === 0 && $adapter->writes === [[$hash, str_repeat('B', 40), hash('sha256', 'alice'), '0']],
            'private override, raw comparison, and stopped run bit',
        );
        list($code, $out) = $this->runTool($adapter, ['--apply']);
        $this->assertTrue(
            $code === 0 && strpos($out, ' queued') !== false && count($adapter->writes) === 1,
            'repeat queued is idempotent',
        );
        $adapter->jobs[$hash]['queued'] = '';
        $adapter->jobs[$hash]['private'] = '0';
        $adapter->jobs[$hash]['trackers'] = ['https://add.test/a'];
        list($code, $out) = $this->runTool($adapter, ['--apply']);
        $this->assertTrue(
            $code === 0 && strpos($out, ' already-has-retrackers') !== false && count($adapter->writes) === 1,
            'repeat completed torrent is idempotent',
        );
    }
    public function testLimitAndAbsentHashes()
    {
        $adapter = $this->adapter();
        $adapter->jobs[str_repeat('C', 40)] = $adapter->jobs[str_repeat('A', 40)];
        list($code, $out) = $this->runTool($adapter, ['--apply', '--limit=1']);
        $this->assertTrue(
            $code === 0 && count($adapter->writes) === 1 && strpos($out, ' limit') !== false,
            'limit bounds enqueue calls',
        );
        list($code, $out) = $this->runTool($adapter, [str_repeat('D', 40)]);
        $this->assertTrue($code === 1 && strpos($out, ' not-loaded') !== false, 'absent explicit hash refuses');
    }
    public function testRefusalsAndLostReplyAreNeverReplayed()
    {
        foreach ([['refused:changed', 1, 'refused:changed'], ['refused:stop-lease', 2, 'transport-unconfirmed'],
            [false, 2, 'transport-unconfirmed'], ['strange remote text', 2, 'transport-unconfirmed']] as $case) {
            $adapter = $this->adapter();
            $adapter->answer = $case[0];
            list($code, $out) = $this->runTool($adapter, ['--apply']);
            $this->assertTrue($code === $case[1] && strpos($out, $case[2]) !== false && count($adapter->writes) === 1
                && strpos($out, 'remote text') === false, 'one mutation with classified outcome');
        }
    }
    public function testCapabilityUsageAndSharedProfile()
    {
        $adapter = $this->adapter();
        $adapter->capable = false;
        list($code, , $err) = $this->runTool($adapter, ['--apply']);
        $this->assertTrue(
            $code === 2 && strpos($err, 'native retrackers queue') !== false && $adapter->writes === [],
            'legacy daemon has clear exit2',
        );
        foreach ([['--limit=0'], ['--limit=-1'], ['--user=Alice'], ['bad']] as $args) {
            list($code) = $this->runTool($this->adapter(), $args);
            $this->assertTrue($code === 2, 'invalid arguments refuse');
        }
        $adapter = $this->adapter();
        $this->runTool($adapter, ['--user=alice', '--apply'], ['shared_profile' => true]);
        $this->assertTrue($adapter->writes[0][2] === hash('sha256', ''), 'shared profile uses unnamed owner');
    }
    public function testStorageRefusalNoticeNamesRecoveryForOnlyItsProfile()
    {
        $adapter = $this->adapter();
        $notice = RetrackersApplyExisting::sessionNotice($adapter, 'alice');
        $this->assertTrue(
            strpos($notice, 'plugin.enqueueRefusals = {"count":1,') === 0
            && strpos($notice, 'retrackers-apply-existing.php --user=alice') !== false && strpos($notice, 'retry-reset') === false,
            'persistent storage refusal gives its caller the queue admission recovery command',
        );
        $this->assertTrue(RetrackersApplyExisting::sessionNotice($adapter, 'charlie') === '', 'another profile sees no refusal notice');
    }
    public function testDefaultLimitAndExplicitHashSelection()
    {
        $adapter = $this->adapter();
        $job = $adapter->jobs[str_repeat('A', 40)];
        $adapter->jobs = [];
        for ($n = 1; $n <= 51; $n++) {
            $adapter->jobs[str_pad(strtoupper(dechex($n)), 40, '0', STR_PAD_LEFT)] = $job;
        }
        list($code, $out) = $this->runTool($adapter, ['--apply']);
        $this->assertTrue(
            $code === 0 && count($adapter->writes) === 50 && substr_count($out, ' limit') === 1,
            'default limit admits fifty enqueue attempts',
        );
        $adapter = $this->adapter();
        $adapter->jobs[str_repeat('C', 40)] = $job;
        $this->runTool($adapter, ['--apply', str_repeat('C', 40), str_repeat('C', 40)]);
        $this->assertTrue(
            count($adapter->writes) === 1 && $adapter->writes[0][0] === str_repeat('C', 40),
            'explicit hashes select and deduplicate one torrent',
        );
    }
    private function adapter()
    {
        $adapter = new ApplyExistingAdapter();
        $adapter->jobs[str_repeat('A', 40)] = ['local_id' => str_repeat('B', 40), 'state' => '0',
            'private' => '0', 'name' => 'torrent', 'queued' => '', 'recovery' => '', 'ack' => '',
            'claim' => 'none', 'lease' => '', 'move' => 'none', 'trackers' => ['https://original.test/a']];
        return($adapter);
    }
    private function runTool($adapter, $args = [], $context = [])
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        try {
            $code = RetrackersApplyExisting::main(
                $args,
                $adapter,
                $out,
                $err,
                array_merge(['trackers' => ['https://add.test/a'], 'dont_add_private' => true], $context),
            );
            rewind($out);
            rewind($err);
            return([$code, stream_get_contents($out), stream_get_contents($err)]);
        } finally {
            fclose($out);
            fclose($err);
        }
    }
}
