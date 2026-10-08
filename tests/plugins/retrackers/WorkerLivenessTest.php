<?php

require_once(__DIR__ . '/RetrackersTestStubs.php');
if (!defined('RETRACKERS_IMPORT_ONLY')) define('RETRACKERS_IMPORT_ONLY', true);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');

// system.pid answered by the test; the request is kept for inspection.
class WorkerLivenessPidAdapter extends RetrackersLifecycleRpcAdapter
{
	public $pid;
	public $payloads = array();

	protected function send($payload, $plan, &$failure = null, $historical = false)
	{
		$this->payloads[] = array($payload, $plan);
		$failure = null;
		return($this->pid === null ? array('ok' => false, 'family' => 2,
			'fault' => array('code' => '-506', 'code_type' => 'i8', 'message' => 'no such method')) :
			array('ok' => true, 'family' => 2, 'value' => $this->pid));
	}
}

/**
 * A native same-hash transaction is live only while rTorrent's own worker for
 * it runs (R4-RT-01). The probe looks in /proc for a direct child of the
 * daemon whose arguments are that worker's: update.php, the hash and a v2
 * handoff ending with the transaction id.
 */
class WorkerLivenessTest extends TestCase
{
	const HASH = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
	const TX = '0123456789abcdef0123456789abcdef';

	private $root;
	private $children = array();

	public function setUp()
	{
		$this->root = sys_get_temp_dir() . '/rr-liveness-' . getmypid();
		mkdir($this->root, 0700);
	}

	public function tearDown()
	{
		foreach ($this->children as $child) {
			foreach ($child['pipes'] as $pipe) if (is_resource($pipe)) fclose($pipe);
			proc_close($child['process']);
		}
		$this->children = array();
		$this->remove($this->root);
	}

	private function remove($path)
	{
		if (is_link($path) || is_file($path)) {
			unlink($path);
			return;
		}
		foreach (scandir($path) as $entry)
			if ($entry !== '.' && $entry !== '..') $this->remove($path . '/' . $entry);
		rmdir($path);
	}

	private function process($pid, $parent, array $argv, $name = 'php85', $state = 'S')
	{
		$dir = $this->root . '/proc/' . $pid;
		mkdir($dir, 0700, true);
		file_put_contents($dir . '/stat', $pid . ' (' . $name . ') ' . $state . ' ' . $parent . ' 1 1 0 -1 4194560 0 0 0 0 0 0 0 0 20 0 1 0 100\n');
		file_put_contents($dir . '/cmdline', $argv === array() ? '' : implode("\0", $argv) . "\0");
	}

	private static function handoff($tx)
	{
		return('v2:original:1:' . str_repeat('B', 40) . ':' . hash('sha256', 'alice') . ':' . $tx);
	}

	private static function worker($hash, $tx, $user = 'alice')
	{
		return(array('/usr/bin/php85', '/rutorrent/app/plugins/retrackers/update.php', $hash, $user,
			self::handoff($tx), str_repeat('d', 32)));
	}

	private static function transactions(array $pairs)
	{
		return(array_map(function ($pair) { return(array('hash' => $pair[0], 'tx' => $pair[1])); }, $pairs));
	}

	public function testOnlyTheDaemonsOwnWorkerKeepsATransactionLive()
	{
		$other = str_repeat('C', 40);
		$otherTx = str_repeat('f', 32);
		$this->process(7, 1, array('/usr/local/bin/rtorrent', '-n', '-o', 'import=/home/torrent/.rtorrent.rc'), 'rtorrent');
		$this->process(40, 7, self::worker(self::HASH, self::TX));
		$this->process(41, 7, array('/usr/bin/php85', '/rutorrent/app/plugins/retrackers/dispatch.php', 'alice'));
		// A command name may hold ") S 7 (": the parent is read after the last ')'.
		$this->process(42, 1, self::worker($other, $otherTx), 'php) S 7 (x');
		$probe = new RetrackersWorkerLiveness($this->root . '/proc');
		$this->assertTrue($probe->probe('7', self::transactions(array(array(self::HASH, self::TX)))) === 'live',
			'the daemon child running update.php for this hash and transaction keeps it live');
		$this->assertTrue($probe->probe('7', self::transactions(array(array(self::HASH, $otherTx)))) === 'abandoned' &&
			$probe->probe('7', self::transactions(array(array($other, self::TX)))) === 'abandoned',
			'a worker for another transaction or another hash does not');
		$this->assertTrue($probe->probe('7', self::transactions(array(array($other, $otherTx)))) === 'abandoned',
			'a matching process that the daemon did not launch does not');
		$this->assertTrue($probe->probe('7', self::transactions(array(array(self::HASH, self::TX),
			array($other, $otherTx)))) === 'abandoned', 'one transaction without its worker is enough');
		// The shared profile of the Docker image is the empty argument.
		$this->process(44, 7, self::worker(str_repeat('F', 40), self::TX, ''));
		$this->assertTrue($probe->probe('7', self::transactions(array(array(str_repeat('F', 40), self::TX)))) === 'live',
			'a worker of the unnamed profile keeps its empty argument in place');
		// rTorrent has not reaped this killed worker of E... yet.
		$this->process(43, 7, array(), 'php85', 'Z');
		$this->assertTrue($probe->probe('7', self::transactions(array(array(str_repeat('E', 40), self::TX)))) === 'abandoned',
			'a killed, unreaped worker has no command line and keeps nothing live');
	}

	public function testADaemonThisPhpCannotSeeLeavesLivenessUnknown()
	{
		$transactions = self::transactions(array(array(self::HASH, self::TX)));
		$this->process(7, 1, array('php-fpm: pool www'), 'php-fpm85');
		$this->process(40, 7, self::worker(self::HASH, self::TX));
		$probe = new RetrackersWorkerLiveness($this->root . '/proc');
		$this->assertTrue($probe->probe('7', $transactions) === 'unknown',
			'a daemon PID that names another program here comes from another PID namespace');
		$this->assertTrue($probe->probe('8', $transactions) === 'unknown' &&
			$probe->probe('', $transactions) === 'unknown' && $probe->probe('07', $transactions) === 'unknown' &&
			$probe->probe(7, $transactions) === 'unknown',
			'an invisible or malformed daemon PID proves nothing');
		$this->process(9, 1, array('/usr/local/bin/rtorrent'), 'rtorrent');
		$this->assertTrue($probe->probe('9', array()) === 'unknown', 'there is nothing to probe without transactions');
		$this->assertTrue((new RetrackersWorkerLiveness($this->root . '/absent'))->probe('9', $transactions) === 'unknown',
			'an unreadable /proc proves nothing');
	}

	// The same rules on the kernel's own /proc: a PHP "daemon" whose name holds
	// rtorrent launches a direct child with a worker's arguments, then kills it
	// without reaping it.
	public function testTheRealProcSeesALiveWorkerAndForgetsAKilledOne()
	{
		if (!is_dir('/proc/self') || !function_exists('symlink')) {
			$this->assertTrue(true, 'no Linux /proc here');
			return;
		}
		mkdir($this->root . '/app/plugins/retrackers', 0700, true);
		$worker = $this->root . '/app/plugins/retrackers/update.php';
		// The worker ends when its stdin closes, so nothing outlives the test.
		file_put_contents($worker, '<?php echo "ready\n"; fgets(STDIN);');
		$daemon = $this->root . '/daemon.php';
		file_put_contents($daemon, '<?php
$worker = proc_open(array(PHP_BINARY, $argv[1], $argv[2], "alice", $argv[3], str_repeat("d", 32)),
	array(0 => array("pipe", "r"), 1 => array("pipe", "w")), $pipes);
fgets($pipes[1]);
$status = proc_get_status($worker);
echo getmypid(), " ", $status["pid"], "\n";
while (($line = fgets(STDIN)) !== false) {
	if (trim($line) !== "kill") continue;
	proc_terminate($worker, 9);
	$stat = "/proc/" . $status["pid"] . "/stat";
	for ($i = 0; $i < 200 && strpos((string)@file_get_contents($stat), ") Z ") === false; $i++) usleep(10000);
	echo strpos((string)@file_get_contents($stat), ") Z ") === false ? "alive\n" : "zombie\n";
}
fclose($pipes[0]);
fclose($pipes[1]);
proc_close($worker);
');
		$named = $this->root . '/rtorrent-fixture';
		symlink(PHP_BINARY, $named);
		$process = proc_open(array($named, $daemon, $worker, self::HASH, self::handoff(self::TX)),
			array(0 => array('pipe', 'r'), 1 => array('pipe', 'w')), $pipes);
		$this->assertTrue(is_resource($process), 'the fixture daemon starts');
		if (!is_resource($process)) return;
		$this->children[] = array('process' => $process, 'pipes' => $pipes);
		$pids = explode(' ', trim((string)fgets($pipes[1])));
		$this->assertTrue(count($pids) === 2 && ctype_digit($pids[0]) && ctype_digit($pids[1]),
			'the fixture reports its own PID and its worker PID');
		if (count($pids) !== 2) return;
		$probe = new RetrackersWorkerLiveness();
		$this->assertTrue($probe->probe($pids[0], self::transactions(array(array(self::HASH, self::TX)))) === 'live',
			'the kernel shows the worker as a live child of the daemon');
		$adapter = new WorkerLivenessPidAdapter();
		$adapter->pid = $pids[0];
		$this->assertTrue($adapter->transactionWorkers(self::transactions(array(array(self::HASH, self::TX)))) === 'live' &&
			count($adapter->payloads) === 1 && $adapter->payloads[0][0] === retrackersBuildDirectRequest('system.pid', array('')) &&
			$adapter->payloads[0][1] === retrackersRestrictedPlanDirectScalar('i8'),
			'the lifecycle adapter asks rTorrent for its PID once and probes that process');
		$adapter = new WorkerLivenessPidAdapter();
		$this->assertTrue($adapter->transactionWorkers(self::transactions(array(array(self::HASH, self::TX)))) === 'unknown',
			'without the daemon PID nothing is known');
		$this->assertTrue($probe->probe($pids[0], self::transactions(array(array(self::HASH, str_repeat('e', 32))))) ===
			'abandoned', 'it does not stand for another transaction');
		$this->assertTrue($probe->probe((string)getmypid(), self::transactions(array(array(self::HASH, self::TX)))) ===
			'unknown', 'this test process is not named like the daemon');
		fwrite($pipes[0], "kill\n");
		$this->assertTrue(trim((string)fgets($pipes[1])) === 'zombie' &&
			$probe->probe($pids[0], self::transactions(array(array(self::HASH, self::TX)))) === 'abandoned',
			'a killed worker that its daemon has not reaped yet keeps nothing live');
	}
}
