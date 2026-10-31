<?php

require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/../../php/PhpProcess.php');
require_once(__DIR__.'/../../../php/nativehelpers.php');
require_once(__DIR__.'/CollectorFixture.php');
require_once(__DIR__.'/../../../plugins/erasedata/removewithdata.php');
require_once(__DIR__.'/RetirementScanFixture.php');
require_once(__DIR__.'/RenameProbeReadFixture.php');

// A queue's rename-probe workspace is read under the helper's own parent flock,
// asked without waiting. flock conflicts between two open file descriptions of one
// process, so these tests hold that lock themselves: no helper, load or timing.
class RenameProbeInspectionTest extends TestCase
{
	const NAME = '.rutorrent-rename-probe-4242-06ade481acbaf577';
	const REFUSAL = 'queue refused: unsafe-rename-probe path=';

	private $dir;
	private $queue;
	private $root;

	public function setUp()
	{
		$this->dir = sys_get_temp_dir().'/erp-'.getmypid().'-'.uniqid();
		mkdir($this->dir, 0700);
		$this->queue = $this->dir.'/erasedata';
		mkdir($this->queue, 0700);
		$this->root = $this->queue.'/'.self::NAME;
		FileUtil::$settingsPath = $this->dir;
		FileUtil::$log = array();
		rXMLRPCRequest::$responses = array(
			'system.client_version' => array('ok' => true, 'val' => array('0.16.24')));
		\ErasedataRetirementScanFixture\DirectoryEntries::$entries = null;
		\ErasedataRenameProbeReadFixture\Script::$lockRefused = false;
		\ErasedataRenameProbeReadFixture\Script::$beforeRead = null;
		\ErasedataRenameProbeReadFixture\Script::$lstatChanges = array();
	}

	public function tearDown()
	{
		$this->remove($this->dir);
	}

	private function remove($path)
	{
		if(is_link($path) || !is_dir($path)) { unlink($path); return; }
		chmod($path, 0700);
		foreach(array_diff(scandir($path), array('.', '..')) as $name)
			$this->remove($path.'/'.$name);
		rmdir($path);
	}

	private function hold($operation)
	{
		// Close-on-exec: a helper that inherited this description would hold the lock
		// it is waiting for after the test released it.
		$handle = fopen($this->queue, 're');
		$this->assertTrue(is_resource($handle) && flock($handle, $operation | LOCK_NB),
			'the fixture holds the flock on the queue directory');
		return($handle);
	}

	private function parentLockIsFree()
	{
		$other = fopen($this->queue, 'r');
		return(flock($other, LOCK_EX | LOCK_NB));
	}

	private function workspace($extra = 'unexpected')
	{
		mkdir($this->root, 0700);
		foreach(array('.lock', 'from-file') as $name)
		{
			file_put_contents($this->root.'/'.$name, '');
			chmod($this->root.'/'.$name, 0600);
		}
		mkdir($this->root.'/from-dir', 0700);
		if($extra !== null) file_put_contents($this->root.'/'.$extra, 'keep');
	}

	private function valid($path = null)
	{
		return(erasedataRenameProbeWorkspaceValid($path === null ? $this->root : $path, fileowner($this->queue)));
	}

	// Through the fixture's copy, with a scripted lock and announced reads.
	private function validScripted()
	{
		return(call_user_func('ErasedataRenameProbeReadFixture\\erasedataRenameProbeWorkspaceValid',
			$this->root, fileowner($this->queue)));
	}

	private function logged()
	{
		return(implode("\n", FileUtil::$log));
	}

	public function testABusyParentLockSkipsTheReadUntilTheHolderLetsGo()
	{
		$this->workspace();
		$holder = $this->hold(LOCK_EX);
		$this->assertSame(true, $this->valid(), 'a live holder of the parent lock owns the workspace state');
		$this->assertSame(true, erasedataEnsureQueueDirectory($this->queue), 'and does not refuse the queue');
		$scan = erasedataRetirementScan(array('listPath' => $this->queue));
		$this->assertSame(0, $scan['classes']['residue'], 'nor count as residue');
		$this->assertEquals(array(), FileUtil::$log, 'and nothing is logged');
		fclose($holder);
		$this->assertSame(false, erasedataEnsureQueueDirectory($this->queue), 'once it lets go the read runs');
		$this->assertTrue(strpos($this->logged(), self::REFUSAL) !== false, 'and refuses with the classified reason');
		$this->assertSame('keep', file_get_contents($this->root.'/unexpected'), 'and the bytes stay');
	}

	public function testAnotherReaderDoesNotSkipTheRead()
	{
		$this->workspace();
		$reader = $this->hold(LOCK_SH);
		$this->assertSame(false, $this->valid(), 'a shared holder is no helper, so the read runs');
		fclose($reader);
	}

	public function testTheReadRunsUnderTheLockAndReleasesIt()
	{
		$this->workspace(null);
		$held = array();
		\ErasedataRenameProbeReadFixture\Script::$beforeRead = function() use (&$held) {
			$held[] = !$this->parentLockIsFree();
		};
		$this->assertSame(true, $this->validScripted(), 'a complete interrupted workspace passes the strict read');
		$this->assertEquals(array_fill(0, 4, true), $held, 'every lstat of the read ran under the parent lock');
		$this->assertTrue($this->parentLockIsFree(), 'the lock is gone when the read returns a verdict');
		file_put_contents($this->root.'/unexpected', 'keep');
		\ErasedataRenameProbeReadFixture\Script::$beforeRead = null;
		$this->assertSame(false, $this->validScripted(), 'a foreign workspace is refused under the lock');
		$this->assertTrue($this->parentLockIsFree(), 'and a refusal releases the lock too');
	}

	public function testAThrowDuringTheReadReleasesTheLock()
	{
		$this->workspace(null);
		$thrown = false;
		\ErasedataRenameProbeReadFixture\Script::$beforeRead = function() {
			$this->assertTrue(!$this->parentLockIsFree(), 'the read holds the lock when it throws');
			throw new RuntimeException('a builtin disabled on PHP 8 throws here');
		};
		try { $this->validScripted(); }
		catch(RuntimeException $error) { $thrown = true; }
		$this->assertTrue($thrown, 'the read was interrupted');
		$this->assertTrue($this->parentLockIsFree(), 'and the exception left no lock behind, even while it is alive');
	}

	// A process started during the read must not inherit the lock: the trap of a
	// helper that holds the very description it waits for.
	public function testTheReadsDescriptionIsCloseOnExec()
	{
		$this->workspace(null);
		$flags = array();
		\ErasedataRenameProbeReadFixture\Script::$beforeRead = function() use (&$flags) {
			foreach(array_diff(scandir('/proc/self/fd'), array('.', '..')) as $fd)
				if(@readlink('/proc/self/fd/'.$fd) === realpath($this->queue)
					&& preg_match('/^flags:\s+([0-7]+)$/m', (string)file_get_contents('/proc/self/fdinfo/'.$fd), $match))
					$flags[] = octdec($match[1]) & 02000000;
		};
		$this->assertSame(true, $this->validScripted(), 'the read passes');
		$this->assertTrue($flags !== array(), 'the description was seen during the read');
		$this->assertEquals(array(), array_filter($flags, function($flag) { return($flag === 0); }),
			'every description of the parent is close-on-exec');
	}

	public function testAWorkspaceGoneBeforeTheLockIsNotARefusal()
	{
		$this->assertSame(true, $this->valid(), 'a probe that finished after the listing left nothing to refuse');
		$this->assertTrue($this->parentLockIsFree(), 'and that exit releases the lock too');
		\ErasedataRetirementScanFixture\DirectoryEntries::$entries = array('.', '..', self::NAME);
		// The fixture evaluates this function at load, out of PHPStan's sight.
		$scan = call_user_func('ErasedataRetirementScanFixture\\erasedataRetirementScan', array('listPath' => $this->queue));
		$this->assertSame(0, $scan['classes']['residue'], 'a finished probe is not residue');
		$this->assertSame(true, $scan['empty'], 'and it does not hold retirement');
		$this->assertEquals(array(), FileUtil::$log, 'and nothing is logged');
	}

	// A readable, unsearchable queue answers no lookup, so the lock proves nothing
	// about a name that merely cannot be seen (RemoveWithDataTest pins the same rule).
	public function testAnUnsearchableQueueKeepsItsResidue()
	{
		$this->workspace(null);
		chmod($this->queue, 0400);
		clearstatcache();
		if(is_dir($this->root))
		{
			echo "NOT CHECKED: an unsearchable queue needs a non-root process\n";
			return;
		}
		$this->assertSame(false, $this->valid(), 'a name this process may not look at is not a finished probe');
		$scan = erasedataRetirementScan(array('listPath' => $this->queue));
		$this->assertSame(1, $scan['classes']['residue'], 'it stays residue at retirement');
		$this->assertSame(false, $scan['empty'], 'and holds retirement');
	}

	public function testAParentThatCannotBeOpenedFallsBackToTheStrictRead()
	{
		$this->workspace(null);
		chmod($this->queue, 0300);
		clearstatcache();
		if(@fopen($this->queue, 'r') !== false)
		{
			echo "NOT CHECKED: an unreadable parent needs a non-root process\n";
			return;
		}
		$loud = 0;
		set_error_handler(function($level) use (&$loud) { if(error_reporting() & $level) $loud++; return(true); });
		$this->assertSame(true, $this->valid(), 'an intact workspace passes without the lock');
		restore_error_handler();
		$this->assertSame(0, $loud, 'and the failed open writes no warning');
		$this->assertSame(false, $this->valid($this->queue.'/.rutorrent-rename-probe-1-0123456789abcdef'),
			'but without the lock a missing root stays the visible refusal it always was');
	}

	public function testARefusedLockFallsBackToTheStrictRead()
	{
		\ErasedataRenameProbeReadFixture\Script::$lockRefused = true;
		$this->assertSame(false, $this->validScripted(), 'a missing root without the lock is no proof of absence');
		$this->workspace(null);
		$this->assertSame(true, $this->validScripted(), 'an intact workspace passes');
		file_put_contents($this->root.'/unexpected', 'keep');
		$this->assertSame(false, $this->validScripted(), 'and a foreign one is refused, never called valid');
	}

	// On PHP 8 a disabled builtin throws Error and on 7.4 it answers NULL.
	public function testADisabledLockBuiltinFallsBackToTheStrictRead()
	{
		$this->workspace();
		$code = 'require '.var_export(__DIR__.'/CollectorFixture.php', true).';'
			.'require '.var_export(__DIR__.'/../../../plugins/erasedata/removewithdata.php', true).';'
			.'$uid = fileowner($argv[1]);'
			.'echo json_encode(array(erasedataRenameProbeWorkspaceValid($argv[1]."/".$argv[2], $uid),'
			.' erasedataRenameProbeWorkspaceValid($argv[1]."/.rutorrent-rename-probe-1-0123456789abcdef", $uid)));';
		foreach(array('flock', 'fopen') as $builtin)
		{
			$child = PhpProcess::run(array_merge(PhpProcess::sameIni(), array('-d', 'disable_functions='.$builtin,
				'-r', $code, '--', $this->queue, self::NAME)), array('timeout' => 30));
			$this->assertSame('[false,false]', $child['out'],
				'with '.$builtin.' disabled neither a foreign nor a missing root is valid: '.$child['err']);
		}
	}

	// A foreign owner needs a second user, and a link's mode and a one-link directory
	// need another filesystem. The other checks would hide these on this one.
	public function testMetadataThisFilesystemCannotShowIsRefused()
	{
		$this->workspace(null);
		$other = fileowner($this->queue) + 1;
		$changes = array(
			'a root that is a link with the private mode' => array($this->root => array('mode' => 0120700)),
			'a root owned by someone else' => array($this->root => array('uid' => $other)),
			'an entry owned by someone else' => array($this->root.'/from-file' => array('uid' => $other)),
			'a file name that is a one-link directory' => array($this->root.'/from-file' => array('mode' => 0040600)),
		);
		$this->assertSame(true, $this->validScripted(), 'the unchanged workspace passes');
		foreach($changes as $case => $change)
		{
			\ErasedataRenameProbeReadFixture\Script::$lstatChanges = $change;
			$this->assertSame(false, $this->validScripted(), $case.' is refused');
		}
	}

	// Real bytes through the real doors: admission, the worker and retirement.
	private function unsafeWorkspaces()
	{
		$outside = $this->dir.'/outside';
		return(array(
			'root is a file' => function($root) { file_put_contents($root, 'bytes'); chmod($root, 0700); },
			'root is a link' => function($root) use ($outside) { mkdir($outside, 0700); symlink($outside, $root); },
			'root mode' => function($root) { $this->workspace(null); chmod($root, 0755); },
			'unknown name' => function($root) { $this->workspace(null); file_put_contents($root.'/unexpected', 'keep'); },
			'unknown directory' => function($root) { $this->workspace(null); mkdir($root.'/unexpected', 0700); },
			'child link' => function($root) use ($outside) {
				$this->workspace(null); file_put_contents($outside, 'keep'); unlink($root.'/from-file'); symlink($outside, $root.'/from-file');
			},
			'child type' => function($root) { $this->workspace(null); mkdir($root.'/to-file', 0700); },
			'child mode' => function($root) { $this->workspace(null); chmod($root.'/from-file', 0644); },
			'second hard link' => function($root) use ($outside) { $this->workspace(null); link($root.'/from-file', $outside); },
			'directory contents' => function($root) { $this->workspace(null); file_put_contents($root.'/from-dir/payload', 'keep'); },
			'lock with bytes' => function($root) { $this->workspace(null); file_put_contents($root.'/.lock', 'keep'); },
		));
	}

	private function fingerprint($path)
	{
		if(is_link($path)) return(array('link' => readlink($path)));
		if(!is_dir($path)) return(array('bytes' => file_get_contents($path), 'mode' => fileperms($path)));
		$listing = array('mode' => fileperms($path));
		foreach(array_diff(scandir($path), array('.', '..')) as $name)
			$listing[$name] = $this->fingerprint($path.'/'.$name);
		return($listing);
	}

	public function testUnsafeWorkspaceIsRefusedVisiblyAtEveryDoorAndKept()
	{
		$dependencies = array('listPath' => $this->queue, 'user' => '', 'filesystem' => new ErasedataFilesystemOps(),
			'log' => array('FileUtil', 'toLog'), 'ackTimeout' => 0.1, 'ackPoll' => 0.01);
		foreach($this->unsafeWorkspaces() as $case => $build)
		{
			$build($this->root);
			$before = $this->fingerprint($this->root);
			$doors = array(
				'queue admission' => function() { return(erasedataEnsureQueueDirectory($this->queue)); },
				'removal admission' => function() use ($dependencies) {
					return(erasedataRemovalAdmissionRun($dependencies, array(str_repeat('A', 40)), 1));
				},
				'drain worker' => function() use ($dependencies) { return(erasedataDrainWorkerRun($dependencies)); },
			);
			foreach($doors as $door => $call)
			{
				FileUtil::$log = array();
				$this->assertSame(false, $call(), $case.' refuses at '.$door);
				$this->assertTrue(strpos($this->logged(), self::REFUSAL) !== false, $case.' names the reason at '.$door);
				if($door !== 'queue admission')
					$this->assertTrue(strpos($this->logged(), 'queue-unavailable') !== false,
						$case.' reports queue-unavailable at '.$door);
			}
			$scan = erasedataRetirementScan(array('listPath' => $this->queue));
			$this->assertSame(1, $scan['classes']['residue'], $case.' stays residue at retirement');
			$this->assertSame(false, $scan['empty'], $case.' holds retirement');
			$this->assertEquals($before, $this->fingerprint($this->root), $case.' is kept byte for byte');
			$this->remove($this->root);
			if(file_exists($this->dir.'/outside') || is_link($this->dir.'/outside')) $this->remove($this->dir.'/outside');
		}
	}

	// Both directions against one lock: the reader answers busy under it, and the
	// real helper does not start its workspace until it is released.
	public function testTheRealHelperWaitsOnTheLockTheReaderAsks()
	{
		$helper = NativeHelpers::optionalPath('rutorrent-erasedata-rename-noreplace');
		if(!is_executable($helper))
		{
			echo "NOT CHECKED: the native rename helper is not installed\n";
			return;
		}
		$this->workspace();
		$holder = $this->hold(LOCK_EX);
		$this->assertSame(true, $this->valid(), 'the reader asks the flock this test holds');
		$this->remove($this->root);
		$process = proc_open(array($helper, '--probe', $this->queue), array(0 => array('file', '/dev/null', 'r'),
			1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
		$this->assertTrue(is_resource($process), 'the fixture starts the helper');
		// However late the helper starts, it may not get past this lock while it is held.
		$until = microtime(true) + 0.3;
		do
		{
			$waiting = proc_get_status($process)['running'] && !array_diff(scandir($this->queue), array('.', '..'));
			usleep(10000);
		} while($waiting && microtime(true) < $until);
		$this->assertTrue($waiting, 'the helper waits on the flock this test holds');
		fclose($holder);
		$status = proc_close($process);
		$this->assertTrue($status === 0 || $status === 5, 'after the release the probe is usable: '.$status);
		$this->assertEquals(array('.', '..'), scandir($this->queue), 'and removes its own workspace');
	}
}
