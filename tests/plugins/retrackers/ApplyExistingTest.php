<?php
require_once(__DIR__ . '/RetrackersTestStubs.php');
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_DISPATCH_IMPORT_ONLY', true);
require_once(__DIR__ . '/../../../plugins/retrackers/dispatch.php');
require_once(__DIR__ . '/../../../plugins/retrackers/applyexisting.php');

class ApplyExistingAdapter
{
	public $jobs = array();
	public $writes = array();
	public $capable = true;
	public $answer = 'queued';

	public function getFamily() { return(2); }
	public function nativeQueueCapability(&$failure = null) { return($this->capable); }
	public function enqueueRefusalRows(&$failure = null)
	{
		return(array(
			array(str_repeat('A', 40), str_repeat('B', 40), 'session-storage-failed', hash('sha256', 'alice')),
			array(str_repeat('C', 40), str_repeat('D', 40), 'session-storage-failed', hash('sha256', 'bob')),
		));
	}
	public function downloadLocalIdRows(&$failure = null)
	{
		$rows = array();
		foreach ($this->jobs as $hash => $job) $rows[] = array($hash, $job['local_id']);
		return($rows);
	}
	public function applyExistingSnapshot($hash, &$failure = null)
	{
		if (!isset($this->jobs[$hash])) { $failure = 'target-absent'; return(false); }
		return($this->jobs[$hash]);
	}
	public function enqueueOriginal($hash, $id, $user, $state, &$failure = null, $guarded = false)
	{
		if (!$guarded) throw new RuntimeException('operator enqueue must be guarded');
		$this->writes[] = array($hash, $id, $user, $state);
		if ($this->answer === 'queued') $this->jobs[$hash]['queued'] = 'Q';
		if ($this->answer === false) $failure = 'read-timeout';
		return($this->answer);
	}
}

class ApplyExistingTest extends TestCase
{
	private function adapter()
	{
		$adapter = new ApplyExistingAdapter();
		$adapter->jobs[str_repeat('A', 40)] = array('local_id' => str_repeat('B', 40), 'state' => '0',
			'private' => '0', 'name' => 'torrent', 'queued' => '', 'recovery' => '', 'ack' => '',
			'claim' => 'none', 'lease' => '', 'move' => 'none', 'trackers' => array('https://original.test/a'));
		return($adapter);
	}
	private function runTool($adapter, $args = array(), $context = array())
	{
		$out = fopen('php://memory', 'w+'); $err = fopen('php://memory', 'w+');
		try {
			$code = RetrackersApplyExisting::main($args, $adapter, $out, $err,
				array_merge(array('trackers' => array('https://add.test/a'), 'dont_add_private' => true), $context));
			rewind($out); rewind($err);
			return(array($code, stream_get_contents($out), stream_get_contents($err)));
		} finally { fclose($out); fclose($err); }
	}
	public function testEveryCandidateRuleHasAClassifiedSkip()
	{
		foreach (array('private' => array('private', '1'),
			'metadata-stub' => array('name', str_repeat('A', 40) . '.meta'),
			'already-has-retrackers' => array('trackers', array('https://add.test/a')),
			'queued' => array('queued', 'Q'), 'claimed' => array('claim', 'held'),
			'recovery' => array('recovery', 'R'), 'ack' => array('ack', 'A'),
			'stop-lease' => array('lease', 'L'), 'move-pending' => array('move', 'queued:owner:q:id:1')) as $reason => $change) {
			$adapter = $this->adapter();
			$adapter->jobs[str_repeat('A', 40)][$change[0]] = $change[1];
			list($code, $out) = $this->runTool($adapter, array('--apply'));
			$expected = in_array($reason, array('recovery', 'ack'), true) ? 'claimed' : $reason;
			$this->assertTrue($code === 0 && strpos($out, str_repeat('A', 40) . ' ' . $expected) !== false &&
				$adapter->writes === array(), $reason . ' skips without mutation');
		}
	}
	public function testDryRunRawTrackersPrivatePolicyAndExactEnqueue()
	{
		$adapter = $this->adapter(); $hash = str_repeat('A', 40);
		list($code, $out) = $this->runTool($adapter);
		$this->assertTrue($code === 0 && strpos($out, ' candidate') !== false && $adapter->writes === array(),
			'dry run is read only');
		$adapter->jobs[$hash]['private'] = '1';
		$adapter->jobs[$hash]['trackers'] = array('https://add.test/a ');
		list($code) = $this->runTool($adapter, array('--user=alice', '--apply'), array('dont_add_private' => false));
		$this->assertTrue($code === 0 && $adapter->writes === array(array($hash, str_repeat('B', 40), hash('sha256', 'alice'), '0')),
			'private override, raw comparison, and stopped run bit');
		list($code, $out) = $this->runTool($adapter, array('--apply'));
		$this->assertTrue($code === 0 && strpos($out, ' queued') !== false && count($adapter->writes) === 1,
			'repeat queued is idempotent');
		$adapter->jobs[$hash]['queued'] = ''; $adapter->jobs[$hash]['private'] = '0';
		$adapter->jobs[$hash]['trackers'] = array('https://add.test/a');
		list($code, $out) = $this->runTool($adapter, array('--apply'));
		$this->assertTrue($code === 0 && strpos($out, ' already-has-retrackers') !== false && count($adapter->writes) === 1,
			'repeat completed torrent is idempotent');
	}
	public function testLimitAndAbsentHashes()
	{
		$adapter = $this->adapter();
		$adapter->jobs[str_repeat('C', 40)] = $adapter->jobs[str_repeat('A', 40)];
		list($code, $out) = $this->runTool($adapter, array('--apply', '--limit=1'));
		$this->assertTrue($code === 0 && count($adapter->writes) === 1 && strpos($out, ' limit') !== false,
			'limit bounds enqueue calls');
		list($code, $out) = $this->runTool($adapter, array(str_repeat('D', 40)));
		$this->assertTrue($code === 1 && strpos($out, ' not-loaded') !== false, 'absent explicit hash refuses');
	}
	public function testRefusalsAndLostReplyAreNeverReplayed()
	{
		foreach (array(array('refused:changed', 1, 'refused:changed'), array('refused:stop-lease', 2, 'transport-unconfirmed'),
			array(false, 2, 'transport-unconfirmed'), array('strange remote text', 2, 'transport-unconfirmed')) as $case) {
			$adapter = $this->adapter(); $adapter->answer = $case[0];
			list($code, $out) = $this->runTool($adapter, array('--apply'));
			$this->assertTrue($code === $case[1] && strpos($out, $case[2]) !== false && count($adapter->writes) === 1 &&
				strpos($out, 'remote text') === false, 'one mutation with classified outcome');
		}
	}
	public function testCapabilityUsageAndSharedProfile()
	{
		$adapter = $this->adapter(); $adapter->capable = false;
		list($code, , $err) = $this->runTool($adapter, array('--apply'));
		$this->assertTrue($code === 2 && strpos($err, 'native retrackers queue') !== false && $adapter->writes === array(),
			'legacy daemon has clear exit2');
		foreach (array(array('--limit=0'), array('--limit=-1'), array('--user=Alice'), array('bad')) as $args) {
			list($code) = $this->runTool($this->adapter(), $args);
			$this->assertTrue($code === 2, 'invalid arguments refuse');
		}
		$adapter = $this->adapter();
		$this->runTool($adapter, array('--user=alice', '--apply'), array('shared_profile' => true));
		$this->assertTrue($adapter->writes[0][2] === hash('sha256', ''), 'shared profile uses unnamed owner');
	}
	public function testStorageRefusalNoticeNamesRecoveryForOnlyItsProfile()
	{
		$adapter = $this->adapter(); $notice = RetrackersApplyExisting::sessionNotice($adapter, 'alice');
		$this->assertTrue(strpos($notice, 'plugin.enqueueRefusals = {"count":1,') === 0 &&
			strpos($notice, 'retrackers-apply-existing.php --user=alice') !== false && strpos($notice, 'retry-reset') === false,
			'persistent storage refusal gives its caller the queue admission recovery command');
		$this->assertTrue(RetrackersApplyExisting::sessionNotice($adapter, 'charlie') === '', 'another profile sees no refusal notice');
	}
	public function testDefaultLimitAndExplicitHashSelection()
	{
		$adapter = $this->adapter(); $job = $adapter->jobs[str_repeat('A', 40)]; $adapter->jobs = array();
		for ($n = 1; $n <= 51; $n++) $adapter->jobs[str_pad(strtoupper(dechex($n)), 40, '0', STR_PAD_LEFT)] = $job;
		list($code, $out) = $this->runTool($adapter, array('--apply'));
		$this->assertTrue($code === 0 && count($adapter->writes) === 50 && substr_count($out, ' limit') === 1,
			'default limit admits fifty enqueue attempts');
		$adapter = $this->adapter(); $adapter->jobs[str_repeat('C', 40)] = $job;
		$this->runTool($adapter, array('--apply', str_repeat('C', 40), str_repeat('C', 40)));
		$this->assertTrue(count($adapter->writes) === 1 && $adapter->writes[0][0] === str_repeat('C', 40),
			'explicit hashes select and deduplicate one torrent');
	}
}
