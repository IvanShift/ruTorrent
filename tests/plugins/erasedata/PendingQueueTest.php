<?php

require_once(__DIR__ . '/../../php/TestCase.php');

/**
 * The queue erase.php writes into, and the single drainer that empties it.
 *
 * The load behaviour this exists for -- hundreds of firings not becoming
 * hundreds of blocking RPC clients -- is not something a unit test can show.
 * What it can hold is the contract underneath: one generation-bound marker per
 * hash however many times a caller fires, a hash that is not a hash never
 * reaching the filesystem, an obligation that is retained until it is really
 * resolved rather than abandoned after N attempts, and hash locks taken in one
 * canonical sorted order so two concurrent drainers cannot deadlock.
 *
 * pending.php is copied rather than included so the requires below its own
 * boundary can be answered by stubs: the real ones pull in settings.php and a
 * live rtorrent. filesystem.php and manifest.php are copied with it, because
 * the durable writer, the generation rules and the shared file mode the queue
 * is built out of have exactly one owner and it is not this file. Every copy is
 * byte-compared, so what runs is the shipped bytes.
 */
class PendingQueueTest extends TestCase
{
	private $tree;
	private $listPath;

	public function setUpClass()
	{
		$this->tree = sys_get_temp_dir() . '/rutorrent-pending-' . getmypid();
		try {
			$this->buildMirror();
		} catch (Throwable $e) {
			$this->wipe();
			throw $e;
		}
	}

	private function buildMirror()
	{
		$this->wipe();
		mkdir($this->tree . '/plugins/erasedata', 0777, true);
		mkdir($this->tree . '/php', 0777, true);
		mkdir($this->tree . '/list', 0777, true);
		$this->listPath = $this->tree . '/list';

		// The shipped bytes of the queue, of everything it is built out of, and
		// of the worker that drains it. removewithdata.php is here because
		// erasedataDrainWorkerRun() -- the real drain entry point the retry
		// cases below drive -- lives in it; php/xmlrpc_path.php because
		// removewithdata.php requires that core file by name.
		// collector.php is here because the drain tick requires it BY NAME at
		// step (5) of erasedataDrainWorkerRun(): a manifest published by the
		// tick is collected inside that same tick, under the worker and
		// scheduler locks. dirname(__FILE__) inside the mirrored
		// removewithdata.php resolves into this mirror, so the file has to be
		// here or the tick fatals. collector.php's own requires
		// (filesystem.php, manifest.php, removewithdata.php) resolve the same
		// way and are already copied above. It goes through the same copy plus
		// hash_file('sha256') equality check as every other entry, so what runs
		// is the shipped ErasedataCollector -- do not replace it with a stub,
		// or a drain that stopped collecting would pass here. Any further file
		// the drain requires by name belongs in this list too.
		$copies = array(
			'plugins/erasedata/pending.php' => 'plugins/erasedata/pending.php',
			'plugins/erasedata/filesystem.php' => 'plugins/erasedata/filesystem.php',
			'plugins/erasedata/manifest.php' => 'plugins/erasedata/manifest.php',
			'plugins/erasedata/removewithdata.php' => 'plugins/erasedata/removewithdata.php',
			'plugins/erasedata/collector.php' => 'plugins/erasedata/collector.php',
			'php/xmlrpc_path.php' => 'php/xmlrpc_path.php',
		);
		foreach ($copies as $relative => $name) {
			$source = dirname(dirname(dirname(__DIR__))) . '/' . $relative;
			$target = $this->tree . '/' . $name;
			if (!copy($source, $target) ||
				hash_file('sha256', $source) !== hash_file('sha256', $target))
				throw new Exception('could not copy ' . $relative);
		}

		// Everything below the queue's own boundary.
		file_put_contents($this->tree . '/php/xmlrpc.php', '<?php
			class rXMLRPCCommand {
				public $command; public $params;
				public function __construct($c, $p = null) { $this->command = $c; $this->params = $p; }
			}
			class rXMLRPCRequest {
				public static $live = array();      // hashes rtorrent admits to
				public static $reachable = true;
				public static $requested = array(); // every request the double saw
				public static $erased = array();    // hashes handed to d.erase
				public $val = array(); public $important = true;
				public $fault = false; public $faultString = "";
				public $rawFaultString = null; public $faultCode = 0;
				private $commands = array();
				public function __construct($cmds = null) {
					self::$requested[] = $cmds;
					if (is_array($cmds)) $this->commands = $cmds;
					else if (!is_null($cmds)) $this->commands = array($cmds);
				}
				// Widened for the drain worker: it builds its erase request
				// command by command, and a double without addCommand() would
				// fatal the case instead of failing it.
				public function addCommand($command) { $this->commands[] = $command; }
				public function run() { return $this->success(); }
				public function success() {
					foreach ($this->commands as $command)
						if (isset($command->command) && $command->command === "d.erase")
							self::$erased[] = $command->params;
					if (!self::$reachable) return false;
					$this->val = self::$live;
					return true;
				}
			}
			function getCmd($c) { return $c; }
		');
		// The plugin logs through FileUtil::toLog(); removewithdata.php expects
		// the entry point to have loaded it, so the tree loads it here.
		file_put_contents($this->tree . '/php/util.php', '<?php
			class FileUtil {
				public static $log = array();
				public static function toLog($m) { self::$log[] = $m; }
				public static function makeDirectory($d) { return @mkdir($d, 0777, true); }
			}
		');
		require_once($this->tree . '/php/util.php');
		require_once($this->tree . '/php/xmlrpc.php');
		require_once($this->tree . '/plugins/erasedata/pending.php');
		require_once($this->tree . '/plugins/erasedata/removewithdata.php');
	}

	public function tearDownClass()
	{
		$this->wipe();
	}

	private function wipe($dir = null)
	{
		$dir = is_null($dir) ? $this->tree : $dir;
		if (!is_dir($dir)) return;
		foreach (array_diff(scandir($dir), array('.', '..')) as $e) {
			$p = $dir . '/' . $e;
			is_dir($p) ? $this->wipe($p) : unlink($p);
		}
		rmdir($dir);
	}

	/**
	 * setUpClass() runs once for the whole file, not once per test, so a case that
	 * counts what is in the queue has to start from an empty one.
	 */
	private function fresh()
	{
		foreach (glob($this->listPath . '/*') as $f) unlink($f);
		foreach (glob($this->tree . '/*.pending') as $f) unlink($f);
		// The doubles are static, so they outlive a test as well.
		rXMLRPCRequest::$live = array();
		rXMLRPCRequest::$reachable = true;
		rXMLRPCRequest::$requested = array();
		rXMLRPCRequest::$erased = array();
		FileUtil::$log = array();
	}

	private function hash($c) { return str_repeat($c, 40); }
	private function marker($h) { return $this->listPath . '/' . strtoupper($h) . '.pending'; }

	/**
	 * The generation-bound marker name the corrected contract requires.
	 *
	 * The name carries the CANONICAL uppercase hash whatever spelling the
	 * caller used. erasedataIsPendingHash() accepts both cases, so a name built
	 * from the caller's spelling turns one obligation into two files: the
	 * "already recorded" guard looks under one name, the record inside is always
	 * canonical, and markers[HASH]['path'] can only ever name one of the two.
	 * Discharging the obligation then leaves the other marker standing for ever
	 * and invariant 10's proven-empty scan of pending can never succeed.
	 *
	 * This helper used to reflect the caller's spelling back, which pinned that
	 * defect into place rather than catching it; markerNamed() below keeps the
	 * verbatim form for the cases that assert a name is NOT written.
	 */
	private function markerFor($h, $gen)
	{
		return $this->listPath . '/' . strtoupper($h) . '.' . $gen . '.pending';
	}

	/** A marker name exactly as spelled, canonical or not. */
	private function markerNamed($h, $gen)
	{
		return $this->listPath . '/' . $h . '.' . $gen . '.pending';
	}

	/**
	 * One drain pass, through the real production entry point.
	 *
	 * Draining is the guarded update.php worker's job (invariants 5 and 7), and
	 * erasedataDrainWorkerRun() is the internal runner the naming contract in
	 * RemoveWithDataTest gives it. Every caller below asks requireApi() for that
	 * symbol first, so a missing worker is exactly one "[not implemented yet]"
	 * line per case rather than a fatal or -- as it was until this fix round --
	 * a silent no-op that let a case assert retention nobody had challenged.
	 */
	private function drainPass()
	{
		return erasedataDrainWorkerRun($this->workerDependencies());
	}

	/**
	 * The explicit dependency array the internal runners take, exactly as the
	 * naming contract states it. Test owned: no production code reads any of it.
	 */
	private function workerDependencies()
	{
		return array(
			'listPath' => $this->listPath,
			'user' => 'rutorrent',
			'filesystem' => new ErasedataFilesystemOps(),
			'log' => array('FileUtil', 'toLog'),
			'forceEnabled' => true,
			'ackTimeout' => 0.30,
			'ackPoll' => 0.02,
		);
	}

	public function testAFiringRecordsOneMarker()
	{
		$this->fresh();
		$h = $this->hash('a');
		$gen = '000000000000000b';
		$this->assertTrue(erasedataQueueRequest($this->listPath, $h, 1, $gen) === true,
			'the request is queued');
		$this->assertTrue(is_file($this->markerFor($h, $gen)),
			'a marker names the canonical hash and the exact generation it was queued for');
		$this->assertEquals(1, count(glob($this->listPath . '/*.pending')),
			'and one firing leaves exactly one marker behind');
		$this->assertTrue(!is_file($this->markerNamed($h, $gen)),
			'never under the caller\'s own lowercase spelling');
		$this->assertTrue(!is_file($this->marker($h)),
			'the generation-less marker name is never written');
	}

	/** Exclusive create, preserved: a repeated firing is never a second request. */
	public function testFiringAgainBeforeTheDrainIsNotASecondRequest()
	{
		$this->fresh();
		$h = $this->hash('b');
		$gen = '000000000000000c';
		erasedataQueueRequest($this->listPath, $h, 1, $gen);
		erasedataQueueRequest($this->listPath, $h, 1, $gen);
		$this->assertEquals(1, count(glob($this->listPath . '/*.pending')),
			'a ratio group command firing on every check queues once');
	}

	public function testSomethingThatIsNotAHashNeverReachesTheFilesystem()
	{
		$this->fresh();
		// A name that would land one directory up, where this test can write --
		// so the refusal has to come from the check, not from the write failing.
		$escape = '../escaped';
		$gen = '000000000000000d';
		$this->assertTrue(erasedataQueueRequest($this->listPath, $escape, 1, $gen) === false,
			'a name that is not a hash is refused');
		$this->assertTrue(!is_file($this->listPath . '/' . $escape . '.pending'),
			'and nothing was written outside the queue directory');
		$this->assertEquals(array(), glob($this->listPath . '/*'), 'nor inside it');

		$this->assertTrue(erasedataQueueRequest($this->listPath, '../../etc/passwd', 1, $gen) === false,
			'and neither is a path');
	}

	/**
	 * The marker is created exclusively, so a caller that fires again lands on
	 * the early return. Rewriting it instead would let a later firing change
	 * the force or the generation an obligation was already admitted under.
	 * (supersedes testFiringAgainDoesNotResetTheAttemptCount: there is no
	 * attempt count left to reset)
	 */
	public function testFiringAgainNeverRewritesTheRecordedObligation()
	{
		$this->fresh();
		$invariant = 'a repeated firing never rewrites the obligation already'
			. ' recorded for that hash and generation';
		if (!$this->requireApi(array('erasedataQueueRequest'), $invariant))
			return;
		$h = $this->hash('3');
		$gen = '000000000000000e';
		$this->assertTrue(erasedataQueueRequest($this->listPath, $h, 1, $gen) === true,
			'the obligation is recorded once');
		$recorded = @file_get_contents($this->markerFor($h, $gen));
		$this->assertTrue(is_string($recorded) && $recorded !== '',
			'and its generation-bound marker is readable');
		erasedataQueueRequest($this->listPath, $h, 1, $gen);
		erasedataQueueRequest($this->listPath, $h, 2, $gen);
		$this->assertTrue(is_string($recorded)
			&& $recorded === @file_get_contents($this->markerFor($h, $gen)),
			'firing again leaves the recorded obligation byte for byte as it was');
		$this->assertEquals(1, count(glob($this->listPath . '/*.pending')),
			'and never adds a second marker for the same generation');
	}

	/** Invariant 13: an invalid force is refused, never coerced to the safe one. */
	public function testAForceModeNobodyDefinedIsRefused()
	{
		$this->fresh();
		$h = $this->hash('c');
		$gen = '000000000000000f';
		$this->assertTrue(erasedataQueueRequest($this->listPath, $h, 9, $gen) === false,
			'an unknown force is refused outright rather than coerced to 1');
		$this->assertEquals(array(), glob($this->listPath . '/*.pending'),
			'and no marker is written for it under any name');
	}

	/**
	 * (supersedes testAHashAlreadyCollectedLeavesTheQueue, which read a bare
	 * <hash>.list -- invariant 3 forbids that from acknowledging anything)
	 */
	public function testAHashAlreadyCollectedInThisGenerationIsNotQueuedAgain()
	{
		$this->fresh();
		$invariant = 'a hash whose own generation is already published carries'
			. ' no unresolved obligation';
		if (!$this->requireApi(array('erasedataQueueRequest', 'erasedataPendingObligations'), $invariant))
			return;
		$h = $this->hash('d');
		$gen = '0000000000000010';
		file_put_contents($this->listPath . '/' . $h . '.' . $gen . '.1.list', 'x');
		erasedataQueueRequest($this->listPath, $h, 1, $gen);
		$obligations = erasedataPendingObligations($this->listPath);
		$this->assertTrue(is_array($obligations) && !isset($obligations[$gen]),
			'it is not queued again behind its own published manifest');
	}

	/**
	 * (supersedes testAMarkerThatIsNotAHashIsSweptUp: a candidate nobody can
	 * parse must survive for the retirement scan to see, so the store reports
	 * it as no obligation rather than deleting it)
	 */
	public function testAMarkerNobodyCouldHaveWrittenIsNeverAnObligation()
	{
		$this->fresh();
		$invariant = 'a marker that is not a canonical hash bound to a valid'
			. ' generation is never returned as an obligation';
		if (!$this->requireApi(array('erasedataPendingObligations'), $invariant))
			return;
		$candidates = array(
			$this->listPath . '/notahash.pending',
			$this->listPath . '/' . $this->hash('a') . '.not-a-generation.pending',
		);
		foreach ($candidates as $candidate)
			file_put_contents($candidate, "x\n");
		$obligations = erasedataPendingObligations($this->listPath);
		$this->assertTrue(is_array($obligations) && count($obligations) === 0,
			'neither malformed candidate becomes an obligation');
		// Invariant 10 retires only on a fresh proven-empty scan of pending,
		// staging, final, journal, MALFORMED and unknown candidates. A read that
		// tidied a candidate away would delete the very evidence that refuses
		// retirement, so the read has to leave both of them exactly as they are.
		foreach ($candidates as $candidate)
			$this->assertEquals("x\n", (string)@file_get_contents($candidate),
				'and reading the store leaves ' . basename($candidate) . ' byte for byte where it was');
	}

	/**
	 * A marker whose record contradicts the name it is filed under.
	 *
	 * It passes the filename filter and decodes cleanly, so it reaches the
	 * cross-field equality check rather than being turned away before the
	 * record is ever read -- which is the only part of that check a test can
	 * actually reach. It binds nothing, so it is no obligation; and invariant 9
	 * forbids deleting an obligation on uncertainty while invariant 10 needs the
	 * candidate to survive, so the read must not unlink it either.
	 */
	public function testAMarkerThatContradictsItsOwnNameIsNeitherObeyedNorDeleted()
	{
		$this->fresh();
		$invariant = 'a marker whose record disagrees with its own name is not an'
			. ' obligation, and reading the store neither obeys nor deletes it';
		if (!$this->requireApi(array('erasedataPendingObligations',
			'erasedataEncodePendingMarker'), $invariant))
			return;
		$gen = '0000000000000021';
		$other = '0000000000000022';
		$a = strtoupper($this->hash('a'));
		$b = strtoupper($this->hash('b'));
		$planted = array(
			// filed under A, records B
			$this->listPath . '/' . $a . '.' . $gen . '.pending'
				=> erasedataEncodePendingMarker(array('version' => 1,
					'generation' => $gen, 'hash' => $b, 'force' => 1)),
			// filed under one generation, records another
			$this->listPath . '/' . $b . '.' . $gen . '.pending'
				=> erasedataEncodePendingMarker(array('version' => 1,
					'generation' => $other, 'hash' => $b, 'force' => 2)),
			// a well-formed name over bytes nobody can decode
			$this->listPath . '/' . strtoupper($this->hash('c')) . '.' . $gen . '.pending'
				=> "version=1\nnonsense\n",
		);
		foreach ($planted as $path => $bytes) {
			$this->assertTrue(is_string($bytes) && $bytes !== '',
				'the contradicting marker for ' . basename($path) . ' is buildable');
			file_put_contents($path, (string)$bytes);
		}
		$obligations = erasedataPendingObligations($this->listPath);
		$this->assertTrue(is_array($obligations) && count($obligations) === 0,
			'none of the three contradicting markers becomes an obligation');
		foreach ($planted as $path => $bytes)
			$this->assertEquals((string)$bytes, (string)@file_get_contents($path),
				basename($path) . ' survives the read byte for byte');
		$this->assertEquals(3, count(glob($this->listPath . '/*.pending')),
			'and all three are still there for the retirement scan to refuse on');
	}

	/**
	 * Two spellings of one hash are one obligation, filed under one name.
	 *
	 * erasedataIsPendingHash() accepts either case. If the marker carried the
	 * caller's spelling, the same hash and generation would produce two files
	 * while markers[HASH]['path'] named only one of them: a worker that
	 * discharged the obligation would unlink one and leave the other standing
	 * for ever, and invariant 10's proven-empty scan of pending could never
	 * succeed, so the drain schedule could never retire.
	 */
	public function testOneObligationIsOneMarkerWhateverCaseTheCallerSpellsIt()
	{
		$this->fresh();
		$invariant = 'the marker file name carries the canonical uppercase hash,'
			. ' so two spellings of one hash are one obligation and one file';
		if (!$this->requireApi(array('erasedataQueueRequest',
			'erasedataPendingObligations', 'erasedataPendingMarkerPath'), $invariant))
			return;
		$lower = $this->hash('a');
		$gen = '0000000000000020';
		$this->assertTrue(erasedataQueueRequest($this->listPath, $lower, 1, $gen) === true,
			'the lowercase spelling is queued');
		$this->assertTrue(erasedataQueueRequest($this->listPath, strtoupper($lower), 1, $gen) === true,
			'the uppercase spelling of the same hash and generation is accepted as already recorded');
		$this->assertEquals(1, count(glob($this->listPath . '/*.pending')),
			'and leaves exactly one marker, not two');
		$this->assertTrue(is_file($this->markerFor($lower, $gen)),
			'the one marker is filed under the canonical uppercase name');
		$this->assertTrue(!is_file($this->markerNamed($lower, $gen)),
			'never under the lowercase spelling the first caller used');
		$this->assertEquals($this->markerFor($lower, $gen),
			erasedataPendingMarkerPath($this->listPath, $lower, $gen),
			'and erasedataPendingMarkerPath() canonicalises whatever it is handed');
		// The obligation the worker reads names the file that really exists, so
		// discharging it can leave nothing behind.
		$obligations = erasedataPendingObligations($this->listPath);
		$canonical = strtoupper($lower);
		$this->assertTrue(is_array($obligations) && isset($obligations[$gen]['markers'][$canonical]['path'])
			&& $obligations[$gen]['markers'][$canonical]['path'] === $this->markerFor($lower, $gen),
			'the reported marker path is the file that is really on disk');
		$this->assertTrue(is_array($obligations) && isset($obligations[$gen]['markers'][$canonical]['path'])
			&& @unlink($obligations[$gen]['markers'][$canonical]['path']) === true
			&& count(glob($this->listPath . '/*.pending')) === 0,
			'so unlinking the reported path discharges the obligation completely');
		$this->assertTrue(erasedataPendingMarkerPath($this->listPath, 'not-a-hash', $gen) === false,
			'a name that is not a hash gets no marker path at all');
		$this->assertTrue(erasedataPendingMarkerPath($this->listPath, $lower, 'nope') === false,
			'and neither does a malformed generation');
	}

	// A recorded generation can outlive a daemon restart with a lower soft
	// nofile limit. The worker must still take its whole sorted lock set when
	// the process hard limit leaves enough room. Run in a child so the suite's
	// own descriptor limit never changes.
	public function testRecoveryDrainerRaisesSoftFdLimitForRecordedGeneration()
	{
		$this->fresh();
		$runner = $this->tree . '/fd-limit-runner.php';
		$script = <<<'RUNNER'
<?php
require_once __TREE__ . '/php/xmlrpc.php';
require_once __TREE__ . '/plugins/erasedata/removewithdata.php';
$limits = function_exists('posix_getrlimit') ? posix_getrlimit() : false;
$hard = is_array($limits) && isset($limits['hard openfiles'])
    ? $limits['hard openfiles'] : null;
$hardForRun = isset($argv[1]) && $argv[1] === 'low-hard' ? 64 : $hard;
if (!function_exists('posix_setrlimit') || !defined('POSIX_RLIMIT_NOFILE')
    || !is_int($hard) || $hard < 256
    || !@posix_setrlimit(POSIX_RLIMIT_NOFILE, 64, $hardForRun)) {
    echo "FD_LIMIT_UNAVAILABLE\n";
    exit(3);
}
$hashes = array();
for ($i = 0; $i < 192; $i++) $hashes[] = strtoupper(sha1('fd-generation-'.$i));
$notes = array();
$result = erasedataDrainGenerationPass(__LIST__, 'fd-lab', '0000000000000001',
    array('hashes' => $hashes, 'force' => 1, 'markers' => array()),
    new ErasedataFilesystemOps(), $notes);
echo json_encode(array('reasons' => array_values(array_unique(array_map(
    function($note) { return $note[0]; }, $notes))),
    'lockFiles' => count(glob(__LIST__.'/*.lock')),
    'soft' => posix_getrlimit()['soft openfiles'])), "\n";
RUNNER;
		$script = str_replace(array('__TREE__', '__LIST__'),
			array(var_export($this->tree, true), var_export($this->listPath, true)), $script);
		file_put_contents($runner, $script);
		foreach (array('hard-capped' => 'low-hard', 'recoverable' => '') as $case => $mode)
		{
			$pipes = array();
			$process = proc_open('exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($runner)
				.' '.escapeshellarg($mode),
				array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
			$this->assertTrue(is_resource($process), $case.': FD-limit child starts');
			if (!is_resource($process)) continue;
			$output = stream_get_contents($pipes[1]);
			$error = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$exit = proc_close($process);
			$report = json_decode($output, true);
			$this->assertTrue($exit === 0 && is_array($report),
				$case.': child runs the real drain pass: '.$error.' '.$output);
			if (!is_array($report)) continue;
			if ($case === 'recoverable')
			{
				$this->assertTrue((in_array('state-unreadable', $report['reasons'], true)
					|| in_array('worker-user-mismatch', $report['reasons'], true))
					&& !in_array('hash-lock', $report['reasons'], true),
					'the pass reaches state validation after taking all hash locks');
				$this->assertEquals(192, $report['lockFiles'],
					'the worker opened a lock file for every recorded hash');
				$this->assertTrue($report['soft'] >= 256,
					'the child soft limit was raised with reserve space');
			}
			else
			{
				$this->assertTrue(in_array('hash-lock', $report['reasons'], true),
					'a hard-capped worker retains the whole generation visibly');
				$this->assertTrue($report['lockFiles'] < 192 && $report['soft'] === 64,
					'no partial hash set is processed when the hard limit is too low');
			}
		}
	}

	/**
	 * A batch that cannot be taken whole releases every lock it did take.
	 *
	 * PHP closes a leaked handle when the local array goes out of scope, so the
	 * flock really does come off either way and no ordinary observation can tell
	 * the two apart. The lock files are therefore addressed through a
	 * test-owned stream wrapper, which sees the LOCK_UN the production code does
	 * or does not issue. Both refusal paths are driven: the open that fails and
	 * the lock that fails.
	 */
	public function testAPartlyTakenLockBatchReleasesEveryLockItHadTaken()
	{
		$this->fresh();
		$invariant = 'a lock batch that cannot be completed releases every lock'
			. ' it had already taken, in reverse order, before it refuses';
		if (!$this->requireApi(array('erasedataLockObligations',
			'erasedataUnlockObligations'), $invariant))
			return;
		ErasedataLockProbeStream::register($this->tree . '/locks');
		$a = strtoupper($this->hash('a'));
		$b = strtoupper($this->hash('b'));
		$c = strtoupper($this->hash('c'));
		$batch = array($this->hash('c'), $this->hash('a'), $this->hash('b'));
		$root = ErasedataLockProbeStream::SCHEME . '://list';
		foreach (array('the open that fails' => 'refuse', 'the lock that fails' => 'lockFail')
			as $label => $mode) {
			ErasedataLockProbeStream::reset();
			if ($mode === 'refuse')
				ErasedataLockProbeStream::$refuse = array($c . '.lock');
			else
				ErasedataLockProbeStream::$lockFails = array($c . '.lock');
			$locks = erasedataLockObligations($root, $batch);
			$this->assertTrue($locks === false,
				$label . ': the whole batch is refused rather than partly held');
			$this->assertEquals(array($b, $a), ErasedataLockProbeStream::released(),
				$label . ': every lock already taken is released, innermost first');
			$this->assertEquals(array($a, $b), ErasedataLockProbeStream::heldOrder(),
				$label . ': and they were taken in canonical sorted order first');
		}
		ErasedataLockProbeStream::reset();
		$locks = erasedataLockObligations($root, $batch);
		$this->assertTrue(is_array($locks) && count($locks) === 3,
			'a batch nothing refuses is taken whole');
		if (is_array($locks))
			erasedataUnlockObligations($locks);
		$this->assertEquals(array($c, $b, $a), ErasedataLockProbeStream::released(),
			'and releasing it unlocks innermost first');
	}

	/**
	 * (supersedes testAHashRtorrentNoLongerHoldsIsDroppedNotRetried: deciding
	 * what rTorrent still holds is the guarded worker's job, and dropping an
	 * unresolved obligation is exactly what invariant 9 forbids)
	 */
	public function testTheObligationStoreDecidesNothingAboutLiveness()
	{
		$this->fresh();
		$invariant = 'the obligation store records and reports; reading it never'
			. ' erases anything and never drops an unresolved obligation';
		if (!$this->requireApi(array('erasedataQueueRequest', 'erasedataPendingObligations'), $invariant))
			return;
		$h = $this->hash('e');
		$gen = '0000000000000011';
		erasedataQueueRequest($this->listPath, $h, 1, $gen);
		rXMLRPCRequest::$live = array();          // the web UI removed it meanwhile
		// The double records every request that is built, so "no RPC at all"
		// below is an observation and not the absence of a recorder.
		new rXMLRPCRequest(new rXMLRPCCommand('d.hash', $h));
		$this->assertEquals(1, count(rXMLRPCRequest::$requested),
			'the RPC double really records the requests that are built');
		rXMLRPCRequest::$requested = array();
		$obligations = erasedataPendingObligations($this->listPath);
		$this->assertTrue(is_array($obligations) && isset($obligations[$gen]),
			'the obligation is reported whatever rtorrent currently holds');
		$this->assertTrue(is_file($this->markerFor($h, $gen)),
			'and reading the store never removes its marker');
		$this->assertEquals(array(), rXMLRPCRequest::$requested,
			'nor asks rtorrent anything at all, let alone to erase');
	}

	/**
	 * (supersedes testAFailedEraseIsCountedAndRetriedLater: nothing counts an
	 * attempt any more)
	 */
	public function testAnUnresolvedObligationIsRetriedRatherThanCounted()
	{
		$this->fresh();
		$invariant = 'an unresolved obligation is retried: it carries no attempt'
			. ' count and a failed pass never rewrites its record';
		if (!$this->requireApi(array('erasedataQueueRequest', 'erasedataPendingObligations',
			'erasedataDecodePendingMarker', 'erasedataDrainWorkerRun'), $invariant))
			return;
		$h = $this->hash('f');
		$gen = '0000000000000012';
		erasedataQueueRequest($this->listPath, $h, 1, $gen);
		$recorded = @file_get_contents($this->markerFor($h, $gen));
		rXMLRPCRequest::$live = array(strtoupper($h));
		// rTorrent still holds it, but the transport is down, so the pass
		// cannot resolve anything.
		rXMLRPCRequest::$reachable = false;
		$this->drainPass();
		$obligations = erasedataPendingObligations($this->listPath);
		$this->assertTrue(is_array($obligations) && isset($obligations[$gen]),
			'the request stays queued after a failed pass');
		$this->assertTrue(is_string($recorded)
			&& $recorded === @file_get_contents($this->markerFor($h, $gen)),
			'and its record is byte identical: no attempt was counted into it');
		$decoded = erasedataDecodePendingMarker((string)@file_get_contents($this->markerFor($h, $gen)));
		$this->assertTrue(is_array($decoded) && count($decoded) === 4,
			'the marker still holds exactly its four schema fields');
	}

	public function testASuccessfulEraseLeavesTheQueueToTheGarbageCollector()
	{
		$this->fresh();
		$invariant = 'a published manifest of the obligation\'s own generation'
			. ' releases it and is left in place for the garbage collector';
		if (!$this->requireApi(array('erasedataQueueRequest', 'erasedataPendingObligations'), $invariant))
			return;
		$h = $this->hash('0');
		$gen = '0000000000000013';
		erasedataQueueRequest($this->listPath, $h, 1, $gen);
		$published = $this->listPath . '/' . $h . '.' . $gen . '.1.list';
		file_put_contents($published, 'x');
		$obligations = erasedataPendingObligations($this->listPath);
		$this->assertTrue(is_array($obligations) && !isset($obligations[$gen]),
			'the obligation is released');
		$this->assertTrue(is_file($published),
			'and the file list the collector consumes is there');
	}

	/**
	 * The upstream queue abandoned a request after N attempts. Invariant 9
	 * forbids any terminal retry cap, so the third failed pass has to retain
	 * the obligation instead.
	 * (supersedes testItGivesUpRatherThanRetryingForever)
	 */
	public function testTheThirdFailedAttemptStillRetainsTheRequest()
	{
		$this->fresh();
		$invariant = 'there is no attempt limit: a third failed pass retains the'
			. ' obligation rather than abandoning the download';
		$source = $this->pendingSource();
		$this->assertTrue(is_string($source) && strpos($source, 'maxAttempts') === false,
			'pending.php carries no finite attempt cap');
		if (!$this->requireApi(array('erasedataQueueRequest', 'erasedataPendingObligations',
			'erasedataDrainWorkerRun'), $invariant))
			return;
		$h = $this->hash('1');
		$gen = '0000000000000014';
		erasedataQueueRequest($this->listPath, $h, 1, $gen);
		rXMLRPCRequest::$live = array(strtoupper($h));
		rXMLRPCRequest::$reachable = false;
		for ($i = 0; $i < 3; $i++)
			$this->drainPass();
		$obligations = erasedataPendingObligations($this->listPath);
		$this->assertTrue(is_array($obligations) && isset($obligations[$gen]),
			'the third failed pass keeps the obligation instead of abandoning it');
		$this->assertEquals(array(), glob($this->listPath . '/' . $h . '.*.list'),
			'and the download and its data are left in place');
	}

	/**
	 * (supersedes testALimitOfZeroNeverGivesUp, which pinned the two-line
	 * attempt-count marker format)
	 */
	public function testTheObligationSurvivesEveryReadWithItsExactSchema()
	{
		$this->fresh();
		$invariant = 'reading the obligation store never consumes, counts or'
			. ' rewrites an obligation, however many times it is read';
		if (!$this->requireApi(array('erasedataQueueRequest', 'erasedataPendingObligations',
			'erasedataDecodePendingMarker'), $invariant))
			return;
		$h = $this->hash('2');
		$gen = '0000000000000015';
		erasedataQueueRequest($this->listPath, $h, 2, $gen);
		$recorded = @file_get_contents($this->markerFor($h, $gen));
		for ($i = 0; $i < 5; $i++)
			erasedataPendingObligations($this->listPath);
		$obligations = erasedataPendingObligations($this->listPath);
		$this->assertTrue(is_array($obligations) && isset($obligations[$gen]),
			'the request is still queued after five reads');
		$this->assertTrue(is_string($recorded)
			&& $recorded === @file_get_contents($this->markerFor($h, $gen)),
			'and its bytes never changed');
		$decoded = erasedataDecodePendingMarker((string)$recorded);
		$this->assertTrue(is_array($decoded) && count($decoded) === 4
			&& isset($decoded['force']) && $decoded['force'] === 2,
			'the record still holds exactly its four fields and its integer force');
	}

	/**
	 * The producer used to own a drain.lock and stand down when someone else
	 * held it. Invariants 5 and 7 move that admission to the guarded worker's
	 * own nonblocking lock, so nothing a competitor does to the old name may
	 * stop a producer from recording. "Exactly one nonblocking drainer" is
	 * preserved one layer up, by the real update.php worker cases in
	 * RemoveWithDataTest (testAStaleWorkerOwnerIsClassifiedWithoutBreakingItsLock
	 * and testGlobalLockBypassWouldShowOverlapInSharedRecovery).
	 * (supersedes testASecondDrainerStandsDownInsteadOfWorkingTheSameQueue)
	 */
	public function testTheProducerNeverStandsDownOnADrainLockOfItsOwn()
	{
		$this->fresh();
		$source = $this->pendingSource();
		$this->assertTrue(is_string($source) && strpos($source, 'drain.lock') === false,
			'pending.php owns no drain lock of its own');
		$this->assertTrue(!function_exists('erasedataDrainQueue'),
			'and exposes no producer-side drain entry point');
		$lockPath = $this->listPath . '/drain.lock';
		$holder = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
			'$f = fopen(' . var_export($lockPath, true) . ', "c"); flock($f, LOCK_EX);'
			. ' echo "held\n"; flush(); sleep(20);'),
			array(1 => array('pipe', 'w')), $pipes);
		if (!is_resource($holder))
			throw new Exception('could not start the competing drainer');
		fgets($pipes[1]);

		$h = $this->hash('4');
		$gen = '0000000000000016';
		$began = microtime(true);
		$queued = erasedataQueueRequest($this->listPath, $h, 1, $gen);
		$took = microtime(true) - $began;

		proc_terminate($holder, 9);
		for ($i = 0; $i < 100; $i++) {
			$s = proc_get_status($holder);
			if (!$s['running']) break;
			usleep(50000);
		}
		foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
		proc_close($holder);

		$this->assertTrue($queued === true,
			'the producer still records while a competitor holds the legacy lock');
		$this->assertTrue(is_file($this->markerFor($h, $gen)),
			'under the generation-bound marker name');
		$this->assertTrue($took < 1.0, 'and returns at once rather than waiting: took ' . round($took, 2) . 's');
	}

	// =======================================================================
	// Package 6: the queue as a durable, generation-bound obligation store.
	//
	// RED-first against the exact base: every case probes for the production
	// symbol it needs and then asserts its invariant, so a missing slice reads
	// as one "Failed: <invariant>" line rather than a fatal that would hide
	// every later case in this file.
	// =======================================================================

	private function requireApi(array $functions, $invariant)
	{
		$missing = array();
		foreach ($functions as $function)
			if (!function_exists($function))
				$missing[] = $function.'()';
		if (count($missing)) {
			$this->assertTrue(false, $invariant.' [not implemented yet: '.implode(', ', $missing).']');
			return false;
		}
		return true;
	}

	private function pendingSource()
	{
		$path = dirname(dirname(dirname(__DIR__))) . '/plugins/erasedata/pending.php';
		$bytes = @file_get_contents($path);
		$this->assertTrue(is_string($bytes) && $bytes !== '',
			'plugins/erasedata/pending.php is readable for inspection');
		return is_string($bytes) && $bytes !== '' ? $bytes : null;
	}

	/** One marker per hash and generation, with an exact strict schema. */
	public function testAMarkerRecordsItsExactGenerationHashAndIntegerForce()
	{
		$this->fresh();
		$invariant = 'a queued request records one exact 16-hex generation, its'
			. ' canonical hash and integer force 1|2 in a strict schema';
		if (!$this->requireApi(array('erasedataQueueRequest', 'erasedataDecodePendingMarker'), $invariant))
			return;
		$h = $this->hash('a');
		$gen = '000000000000000f';
		$this->assertTrue(erasedataQueueRequest($this->listPath, $h, 2, $gen) === true,
			'a generation-bound request is queued');
		$marker = $this->markerFor($h, $gen);
		$this->assertTrue(is_file($marker),
			'the marker names the canonical hash and its exact generation');
		$decoded = erasedataDecodePendingMarker((string)@file_get_contents($marker));
		$this->assertTrue(is_array($decoded) && isset($decoded['generation'])
			&& $decoded['generation'] === $gen, 'and records that generation inside');
		$this->assertTrue(is_array($decoded) && array_key_exists('force', $decoded)
			&& $decoded['force'] === 2, 'and integer force 2, not the string "2"');
		$this->assertTrue(is_array($decoded) && isset($decoded['hash'])
			&& $decoded['hash'] === strtoupper($h), 'and the canonical uppercase hash');
	}

	/** Invalid force is refused outright: the safe default is no request. */
	public function testAnInvalidForceIsRefusedRatherThanCoercedToOne()
	{
		$this->fresh();
		$invariant = 'an invalid force is refused, never quietly coerced to 1';
		$source = $this->pendingSource();
		$this->assertTrue(is_string($source) && strpos($source, '$force = "1";') === false,
			'pending.php no longer coerces an unknown force to 1');
		if (!$this->requireApi(array('erasedataQueueRequest'), $invariant))
			return;
		$h = $this->hash('b');
		$gen = '0000000000000001';
		foreach (array('1', '2', 0, 3, '1 ', true, null, 1.0, array(1), '') as $force) {
			$this->assertTrue(erasedataQueueRequest($this->listPath, $h, $force, $gen) === false,
				'force ' . json_encode($force) . ' is refused');
		}
		$this->assertEquals(array(), glob($this->listPath . '/*.pending'),
			'and no marker is written for any of them');
	}

	/** No positive attempt cap may ever delete an unresolved obligation. */
	public function testNoAttemptCapEverDeletesAnUnresolvedObligation()
	{
		$this->fresh();
		$invariant = 'there is no terminal retry cap: an unresolved obligation is'
			. ' retained however many times it has failed';
		$source = $this->pendingSource();
		$this->assertTrue(is_string($source) && strpos($source, 'giving up on') === false,
			'pending.php no longer gives up on an unresolved request');
		$this->assertTrue(is_string($source) && strpos($source, 'maxAttempts') === false,
			'and carries no finite attempt cap at all');
		if (!$this->requireApi(array('erasedataQueueRequest', 'erasedataPendingObligations',
			'erasedataDrainWorkerRun'), $invariant))
			return;
		$h = $this->hash('c');
		$gen = '0000000000000002';
		erasedataQueueRequest($this->listPath, $h, 1, $gen);
		$recorded = @file_get_contents($this->markerFor($h, $gen));
		rXMLRPCRequest::$live = array(strtoupper($h));
		rXMLRPCRequest::$reachable = false;
		for ($i = 0; $i < 20; $i++)
			$this->drainPass();
		$obligations = erasedataPendingObligations($this->listPath);
		$this->assertTrue(is_array($obligations) && count($obligations) === 1,
			'twenty failed passes still leave the obligation queued');
		$this->assertTrue(is_string($recorded)
			&& $recorded === @file_get_contents($this->markerFor($h, $gen)),
			'and its record is byte identical after all twenty of them');
	}

	/** A bare <hash>.list belongs to an older torrent and acknowledges nothing. */
	public function testABareListNeverAcknowledgesAGenerationBoundMarker()
	{
		$this->fresh();
		$invariant = 'only a manifest carrying the exact generation acknowledges'
			. ' that generation; a bare <hash>.list never does';
		if (!$this->requireApi(array('erasedataQueueRequest', 'erasedataPendingObligations'), $invariant))
			return;
		$h = $this->hash('d');
		$gen = '0000000000000003';
		erasedataQueueRequest($this->listPath, $h, 1, $gen);
		file_put_contents($this->listPath . '/' . $h . '.list', 'a manifest of an older torrent');
		file_put_contents($this->listPath . '/' . $h . '.0000000000000002.7.list', 'an older generation');
		$obligations = erasedataPendingObligations($this->listPath);
		$this->assertTrue(is_array($obligations) && isset($obligations[$gen]),
			'the obligation survives both foreign manifests');
		file_put_contents($this->listPath . '/' . $h . '.' . $gen . '.7.list', 'this generation');
		$obligations = erasedataPendingObligations($this->listPath);
		$this->assertTrue(is_array($obligations) && !isset($obligations[$gen]),
			'and is released only by a manifest of its own exact generation');
	}

	/** Two physical torrents can share an infohash; each is its own obligation. */
	public function testTwoPhysicalGenerationsOfOneHashAreSeparateObligations()
	{
		$this->fresh();
		$invariant = 'two physical generations of the same infohash are two'
			. ' separate obligations that never acknowledge each other';
		if (!$this->requireApi(array('erasedataQueueRequest', 'erasedataPendingObligations'), $invariant))
			return;
		$h = $this->hash('e');
		$first = '0000000000000004';
		$second = '0000000000000005';
		$this->assertTrue(erasedataQueueRequest($this->listPath, $h, 1, $first) === true,
			'the first generation is queued');
		$this->assertTrue(erasedataQueueRequest($this->listPath, $h, 2, $second) === true,
			'the second generation of the same hash is queued as well');
		$obligations = erasedataPendingObligations($this->listPath);
		$this->assertTrue(is_array($obligations) && count($obligations) === 2,
			'both survive as distinct obligations');
		$this->assertTrue(is_array($obligations) && isset($obligations[$first]['force'])
			&& $obligations[$first]['force'] === 1
			&& isset($obligations[$second]['force']) && $obligations[$second]['force'] === 2,
			'each keeps its own exact integer force');
		file_put_contents($this->listPath . '/' . $h . '.' . $first . '.7.list', 'first');
		$obligations = erasedataPendingObligations($this->listPath);
		$this->assertTrue(is_array($obligations) && !isset($obligations[$first])
			&& isset($obligations[$second]),
			'publishing one generation releases only that one');
	}

	/** A marker is a durable record, not a best-effort write. */
	public function testAMarkerIsPublishedDurablyOrNotAtAll()
	{
		$this->fresh();
		$invariant = 'a marker is published atomically: a write that cannot'
			. ' complete leaves no half-written marker under the final name';
		if (!$this->requireApi(array('erasedataQueueRequest'), $invariant))
			return;
		$h = $this->hash('f');
		$gen = '0000000000000006';
		$blocked = $this->markerFor($h, $gen);
		mkdir($blocked, 0777, true);
		$this->assertTrue(erasedataQueueRequest($this->listPath, $h, 1, $gen) === false,
			'a final marker name already taken by a directory fails the request');
		$this->assertTrue(is_dir($blocked), 'and leaves the colliding object exactly as it was');
		rmdir($blocked);
		$residue = array();
		foreach (glob($this->listPath . '/*') as $file)
			if (substr($file, -4) === '.tmp')
				$residue[] = basename($file);
		$this->assertEquals(array(), $residue, 'and leaves no staging residue behind');
	}

	/** The schema is strict: unknown keys and wrong shapes are refused. */
	public function testThePendingSchemaIsStrictAndBounded()
	{
		$this->fresh();
		$invariant = 'the marker schema has exact keys and types: unknown keys,'
			. ' malformed generations and non-canonical hashes are all refused';
		if (!$this->requireApi(array('erasedataEncodePendingMarker', 'erasedataDecodePendingMarker'), $invariant))
			return;
		$h = strtoupper($this->hash('a'));
		$good = "version=1\ngeneration=0000000000000001\nhash=" . $h . "\nforce=1\n";
		$decoded = erasedataDecodePendingMarker($good);
		$this->assertTrue(is_array($decoded) && count($decoded) === 4,
			'a complete marker decodes to exactly its four fields');
		// Labelled by what each row actually exercises. The decoder requires
		// exactly four lines before it parses anything, so a row with more or
		// fewer never reaches the key checks -- naming such a row after a key
		// rule would claim coverage it does not have. The four-line rows below
		// are the ones that reach the parse loop and the field validators.
		$broken = array(
			'a fifth line, whatever it says' => $good . "surprise=1\n",
			'a fifth line repeating a key' => $good . "force=2\n",
			'only three lines' => "generation=0000000000000001\nhash=" . $h . "\nforce=1\n",
			'only three lines, force missing' => "version=1\ngeneration=0000000000000001\nhash=" . $h . "\n",
			'two trailing blank lines' => $good . "\n\n",
			'an unknown key in place of force' => "version=1\ngeneration=0000000000000001\nhash=" . $h . "\nsurprise=1\n",
			'a duplicated key in place of generation' => "version=1\nhash=" . $h . "\nforce=1\nforce=2\n",
			'an uppercase generation' => "version=1\ngeneration=000000000000000A\nhash=" . $h . "\nforce=1\n",
			'a short generation' => "version=1\ngeneration=1\nhash=" . $h . "\nforce=1\n",
			'a lowercase hash' => "version=1\ngeneration=0000000000000001\nhash=" . strtolower($h) . "\nforce=1\n",
		);
		foreach ($broken as $label => $bytes)
			$this->assertTrue(erasedataDecodePendingMarker($bytes) === false,
				'a marker with ' . $label . ' is refused');
		$this->assertTrue(erasedataEncodePendingMarker(array(
			'version' => 1, 'generation' => '0000000000000001', 'hash' => $h, 'force' => 1)) === $good,
			'encoding is byte deterministic');
	}

	/** The producer records; only the guarded worker resolves. */
	public function testTheProducerRecordsAndOnlyTheGuardedWorkerResolves()
	{
		$this->fresh();
		$invariant = 'the queue is drained by the guarded update.php worker, not'
			. ' by the producer that recorded the request';
		$source = $this->pendingSource();
		$this->assertTrue(is_string($source) && strpos($source, 'erasedataDrainQueue') === false,
			'the producer-side drain entry point is gone from pending.php');
		$this->assertTrue(is_string($source) && strpos($source, 'erasedataPendingObligations') !== false,
			'pending.php exposes the obligation store the worker reads');
		$this->assertTrue(is_string($source) && strpos($source, 'drain.lock') === false,
			'and no longer owns a drain lock of its own');
	}

	/**
	 * Two real drainers over inverse batches of the same hashes.
	 *
	 * The child does not choose the lock order: it hands the batch it was given
	 * to the production entry point and production decides. Invariant 7 makes
	 * that decision canonical -- uppercase, deduplicated, sorted -- BEFORE the
	 * first lock is taken, so both children walk a -> b -> c whichever order
	 * they were handed, and neither can deadlock against the other. A child
	 * that took the order it was handed would deadlock deterministically, which
	 * is exactly the mutation this case has to kill.
	 */
	public function testTwoRealDrainersOverInverseBatchesNeitherDeadlockNorDouble()
	{
		$this->fresh();
		$invariant = 'two concurrent drainers over inverse batches of the same'
			. ' hashes take their hash locks in canonical sorted order, so'
			. ' neither deadlocks and no obligation is resolved twice';
		$canonical = array(strtoupper($this->hash('a')), strtoupper($this->hash('b')),
			strtoupper($this->hash('c')));
		// Mixed case and a duplicate, so canonicalization and deduplication are
		// pinned by the same run as the ordering.
		$batch = array($this->hash('a'), $this->hash('b'), $this->hash('c'),
			strtolower($this->hash('B')));
		$runner = $this->tree . '/drain-runner.php';
		file_put_contents($runner, '<?php' . "\n"
			. 'require_once(' . var_export($this->tree . '/php/xmlrpc.php', true) . ');' . "\n"
			. 'require_once(' . var_export($this->tree . '/plugins/erasedata/pending.php', true) . ');' . "\n"
			. '$order = json_decode($argv[1], true);' . "\n"
			. '$report = $argv[2];' . "\n"
			. 'if (!function_exists("erasedataLockObligations")' . "\n"
			. '	|| !function_exists("erasedataUnlockObligations")) exit(3);' . "\n"
			. '$locks = erasedataLockObligations(' . var_export($this->listPath, true) . ', $order);' . "\n"
			. 'if (!is_array($locks)) exit(4);' . "\n"
			. '@file_put_contents($report, json_encode(array_keys($locks)));' . "\n"
			. '// Held long enough that an order taken as handed really deadlocks.' . "\n"
			. 'usleep(300000);' . "\n"
			. 'erasedataUnlockObligations($locks);' . "\n"
			. 'exit(0);' . "\n");
		$children = array();
		$reports = array();
		foreach (array('forward' => $batch, 'inverse' => array_reverse($batch)) as $label => $order) {
			$reports[$label] = $this->tree . '/locks-' . $label . '.json';
			$pipes = array();
			$handle = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -f ' . escapeshellarg($runner)
				. ' -- ' . escapeshellarg(json_encode($order)) . ' ' . escapeshellarg($reports[$label]),
				array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
			if (!is_resource($handle)) {
				$this->assertTrue(false, $invariant . ' [could not start the ' . $label . ' drainer]');
				foreach ($children as $child) { proc_terminate($child[0], 9); proc_close($child[0]); }
				return;
			}
			$children[$label] = array($handle, $pipes);
		}
		$deadline = microtime(true) + 20;
		$codes = array();
		while (count($codes) < count($children) && microtime(true) < $deadline) {
			foreach ($children as $label => $child) {
				if (isset($codes[$label])) continue;
				$status = proc_get_status($child[0]);
				if (!$status['running']) $codes[$label] = $status['exitcode'];
			}
			if (count($codes) < count($children)) usleep(20000);
		}
		foreach ($children as $label => $child) {
			$status = proc_get_status($child[0]);
			if ($status['running']) { proc_terminate($child[0], 9); usleep(100000); }
			foreach ($child[1] as $pipe) if (is_resource($pipe)) fclose($pipe);
			$closed = proc_close($child[0]);
			if (!isset($codes[$label])) $codes[$label] = $closed;
		}
		$this->assertEquals(2, count(array_filter($codes, function ($c) { return $c !== null; })),
			'both drainers were reaped');
		$this->assertTrue(!in_array(3, $codes, true),
			$invariant . ' [not implemented yet: erasedataLockObligations(),'
			. ' erasedataUnlockObligations()]');
		// Completion order is a race; compare the labelled exit codes in key order.
		ksort($codes);
		$this->assertSame(array('forward' => 0, 'inverse' => 0), $codes,
			'both drainers finished on their own rather than deadlocking on inverse orders');
		foreach ($reports as $label => $file) {
			$taken = json_decode((string)@file_get_contents($file), true);
			$this->assertTrue(is_array($taken) && $taken === $canonical,
				'the ' . $label . ' drainer locked the canonical sorted unique set, in that order');
		}
	}
}

/**
 * A lock directory that records every lock, unlock and close it is asked for.
 *
 * erasedataLockObligations() builds <listPath>/<HASH>.lock, so handing it a
 * stream URL as the list path routes those files through this wrapper.
 * Ordinary observation cannot tell "released the locks it had taken" from
 * "dropped the array and let PHP close the handles": both end with the flock
 * off. The wrapper sees the difference, because only the first issues LOCK_UN.
 *
 * Everything is backed by real files under a real directory, so the wrapper
 * decides nothing about the outcome except the two failures it is asked to
 * inject.
 */
class ErasedataLockProbeStream
{
	const SCHEME = 'erasedata-lockprobe';

	public $context;
	private $handle;
	private $name;

	public static $root = null;
	/** Basenames whose open must fail. */
	public static $refuse = array();
	/** Basenames whose LOCK_EX must fail. */
	public static $lockFails = array();
	/** Every operation, in order: open/lock/close. */
	public static $ops = array();

	public static function register($root)
	{
		self::$root = $root;
		if (!is_dir($root))
			@mkdir($root, 0777, true);
		if (!in_array(self::SCHEME, stream_get_wrappers(), true))
			stream_wrapper_register(self::SCHEME, __CLASS__);
		self::reset();
	}

	public static function reset()
	{
		self::$refuse = array();
		self::$lockFails = array();
		self::$ops = array();
	}

	/** The lock names LOCK_UN was really issued for, in the order it happened. */
	public static function released()
	{
		$ret = array();
		foreach (self::$ops as $op)
			if (strpos($op, 'lock:') === 0 && substr($op, -(strlen(':' . LOCK_UN))) === ':' . LOCK_UN)
				$ret[] = substr($op, 5, strlen($op) - 5 - strlen(':' . LOCK_UN) - strlen('.lock'));
		return $ret;
	}

	/** The lock names LOCK_EX really succeeded for, in the order it happened. */
	public static function heldOrder()
	{
		$ret = array();
		foreach (self::$ops as $op)
			if (strpos($op, 'held:') === 0)
				$ret[] = substr($op, 5, strlen($op) - 5 - strlen('.lock'));
		return $ret;
	}

	private static function real($path)
	{
		return self::$root . '/' . str_replace('/', '_',
			substr($path, strlen(self::SCHEME . '://')));
	}

	public function stream_open($path, $mode, $options, &$openedPath)
	{
		$this->name = basename($path);
		self::$ops[] = 'open:' . $this->name;
		if (in_array($this->name, self::$refuse, true))
			return false;
		$this->handle = @fopen(self::real($path), $mode);
		return $this->handle !== false;
	}

	public function stream_lock($operation)
	{
		self::$ops[] = 'lock:' . $this->name . ':' . $operation;
		if ($operation !== LOCK_UN && in_array($this->name, self::$lockFails, true))
			return false;
		if ($operation !== LOCK_UN)
			self::$ops[] = 'held:' . $this->name;
		return true;
	}

	public function stream_close()
	{
		self::$ops[] = 'close:' . $this->name;
		if (is_resource($this->handle))
			@fclose($this->handle);
	}

	public function stream_write($data) { return @fwrite($this->handle, $data); }
	public function stream_read($count) { return @fread($this->handle, $count); }
	public function stream_eof() { return @feof($this->handle); }
	public function stream_stat() { return @fstat($this->handle); }
	public function url_stat($path, $flags) { return @stat(self::real($path)); }
	public function unlink($path) { return @unlink(self::real($path)); }
}
