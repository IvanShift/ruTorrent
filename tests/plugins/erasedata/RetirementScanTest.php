<?php

require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/CollectorFixture.php');
require_once(__DIR__.'/../../../plugins/erasedata/removewithdata.php');
require_once(__DIR__.'/RetirementScanFixture.php');

class RetirementScanTest extends TestCase
{
	private $queue;

	public function setUp()
	{
		$this->queue = sys_get_temp_dir().'/retirement-scan-'.getmypid().'-'.uniqid();
		mkdir($this->queue, 0700);
		\ErasedataRetirementScanFixture\DirectoryEntries::$entries = null;
	}

	public function tearDown()
	{
		\ErasedataRetirementScanFixture\DirectoryEntries::$entries = null;
		foreach(array_diff(scandir($this->queue), array('.', '..')) as $name)
			unlink($this->queue.'/'.$name);
		rmdir($this->queue);
	}

	public function testPermanentHashLocksDoNotExhaustTheObligationLimit()
	{
		$entries = array('.', '..', ERASEDATA_DRAIN_STATE_LOCK_NAME);
		for($index = 0; $index <= ERASEDATA_PENDING_MAX_MARKERS; $index++)
			$entries[] = sprintf('%040X.lock', $index);
		\ErasedataRetirementScanFixture\DirectoryEntries::$entries = $entries;
		$scan = \ErasedataRetirementScanFixture\erasedataRetirementScan(array('listPath' => $this->queue));
		$this->assertSame(true, $scan['empty'], 'historical hash locks leave an obligation-free queue empty');
		$this->assertSame(false, $scan['unreadable'], 'trusted control entries do not consume the obligation limit');
		$this->assertSame(0, $scan['candidates'], 'hash locks are not pending deletions');
	}

	public function testTooManyActualCandidatesStillRefuseRetirement()
	{
		$entries = array('.', '..');
		for($index = 0; $index < ERASEDATA_PENDING_MAX_MARKERS; $index++)
			$entries[] = 'unknown-'.$index;
		\ErasedataRetirementScanFixture\DirectoryEntries::$entries = $entries;
		$scan = \ErasedataRetirementScanFixture\erasedataRetirementScan(array('listPath' => $this->queue));
		$this->assertSame(false, $scan['empty'], 'a full candidate set remains outstanding work');
		$this->assertSame(false, $scan['unreadable'], 'the admitted candidate boundary is still readable');
		$this->assertSame(ERASEDATA_PENDING_MAX_MARKERS, $scan['candidates'], 'control entries do not shift the candidate boundary');
		$entries[] = 'unknown-over-limit';
		\ErasedataRetirementScanFixture\DirectoryEntries::$entries = $entries;
		$scan = \ErasedataRetirementScanFixture\erasedataRetirementScan(array('listPath' => $this->queue));
		$this->assertSame(false, $scan['empty'], 'an excessive obligation set cannot retire');
		$this->assertSame(true, $scan['unreadable'], 'the candidate bound still refuses an excessive queue');
	}

	public function testARealDirectoryRetainsPendingUnknownAndResidueWithoutChangingFiles()
	{
		$control = str_repeat('B', 40).'.lock';
		file_put_contents($this->queue.'/'.$control, 'permanent lock');
		$scan = erasedataRetirementScan(array('listPath' => $this->queue));
		$this->assertSame(true, $scan['empty'], 'a real hash lock alone leaves the queue empty');
		$files = array(
			$control => 'permanent lock',
			str_repeat('A', 40).'.0000000000000001.pending' => 'unreadable pending obligation',
			'.interrupted-write.tmp' => 'interrupted durable write',
			'operator-note' => 'unclassified entry',
		);
		foreach($files as $name => $bytes)
			file_put_contents($this->queue.'/'.$name, $bytes);
		$scan = erasedataRetirementScan(array('listPath' => $this->queue));
		$this->assertSame(false, $scan['empty'], 'uncertain entries keep real retirement conservative');
		$this->assertSame(false, $scan['unreadable'], 'a readable queue reports its candidates');
		$this->assertSame(3, $scan['candidates'], 'only the three non-control entries count');
		foreach(array('pending', 'residue', 'unknown') as $class)
			$this->assertSame(1, $scan['classes'][$class], 'the real scan retains '.$class);
		foreach($files as $name => $bytes)
			$this->assertSame($bytes, file_get_contents($this->queue.'/'.$name), 'retirement scan preserves '.$name);
	}
}
