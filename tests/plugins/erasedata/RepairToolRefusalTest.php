<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/ScgiToolPeer.php');

/**
 * Exercise the shipped standalone repair tool against a controlled SCGI peer.
 *
 * The operator needs a refusal on missing answers, changed markers, and real
 * XML-RPC faults. A torrent name containing faultString remains ordinary data.
 * The peer can answer an exact number of calls and then close, so both report
 * and apply paths can be checked without touching a running daemon.
 */
class RepairToolRefusalTest extends TestCase
{
	private $dir = null;
	private $socketPath = null;

	public function setUp()
	{
		$this->dir = sys_get_temp_dir() . '/erasedata-repair-tool-'
			. getmypid() . '-' . uniqid('', true);
		@mkdir($this->dir, 0700, true);
		// A UNIX socket path is bounded at about 108 bytes, so it lives beside
		// the temporary directory rather than inside its longer path.
		$this->socketPath = sys_get_temp_dir() . '/erp-' . getmypid() . '-'
			. substr(sha1($this->dir), 0, 8) . '.sock';
		@unlink($this->socketPath);
	}

	public function tearDown()
	{
		@unlink($this->socketPath);
		foreach (ScgiToolPeer::stagedEntries() as $entry)
			@is_dir($this->dir . $entry) ? @rmdir($this->dir . $entry)
				: @unlink($this->dir . $entry);
		@rmdir($this->dir);
	}

	// Run the shipped tool, in its repository layout with the shared CLI guard,
	// while this process plays a peer that answers $replies requests and then
	// accepts-and-closes for ever.
	private function runAgainstPeer($argument, $replies, $marker = null)
	{
		$tool = ScgiToolPeer::stageClearMarkersTool($this->dir, $this->socketPath, true);
		$this->assertTrue(is_file($tool) && is_file($this->dir . '/php/cli-only.php'),
			'the staged tool carries the actual shared CLI guard dependency');
		if ($marker === null) $marker = 'v1:original:0:' . str_repeat('B', 40);
		$inventory = ScgiToolPeer::rows(array(array(str_repeat('A', 40), 'probe.bin', $marker, '',
			str_repeat('C', 40))));
		$args = $argument === '' ? array() : (is_array($argument) ? $argument : array($argument));
		list($code, $out, $err, $requests) = ScgiToolPeer::run($this->socketPath, $tool, $args,
			function ($request, $index) use ($replies, $inventory) {
				if ($index >= (is_array($replies) ? count($replies) : $replies)) return(null);
				return(is_array($replies) ? $replies[$index] : $inventory);
			});
		return(array($code, $out, $err, $requests));
	}

	public function testAnUnansweredReadIsNotReportedAsNothingToDo()
	{
		list($code, $out, $err) = $this->runAgainstPeer('', 0);
		$this->assertTrue($code !== 0,
			'a peer that never answers is not a successful run: exit ' . $code
				. ' out=' . $out . ' err=' . $err);
		$this->assertTrue(strpos($out, 'carry a recovery marker') === false,
			'and no count is reported from an answer that never arrived: ' . $out);
		$this->assertTrue(strpos($err, 'no complete answer') !== false,
			'and it says which call went unanswered: ' . $err);
	}

	public function testReportIdentifiesCandidateClaimWithoutPrintingTransactionId()
	{
		$transaction = str_repeat('a', 32);
		list($code, $out, $err) = $this->runAgainstPeer('', 1,
			'v1:candidate-claim:' . $transaction);
		$this->assertTrue($code === 0, 'the read-only report succeeds: ' . $err);
		$this->assertTrue(strpos($out, '[candidate-claim; ack empty; fingerprint ') !== false,
			'the operator can distinguish an incomplete replacement claim');
		$this->assertTrue(strpos($out, $transaction) === false,
			'the transaction identifier is not printed in a routine report');
	}

	public function testTorrentNameContainingFaultStringIsReportable()
	{
		$body = '<?xml version="1.0"?><methodResponse><params><param><value>'
			. '<array><data><value><array><data>'
			. '<value><string>' . str_repeat('A', 40) . '</string></value>'
			. '<value><string>faultString</string></value>'
			. '<value><string>marker</string></value>'
			. '<value><string></string></value>'
			. '<value><string>' . str_repeat('C', 40) . '</string></value>'
			. '</data></array></value></data></array></value></param></params></methodResponse>';
		list($code, $out, $err) = $this->runAgainstPeer('', array($body));
		$this->assertTrue($code === 0 && strpos($out, '1 carry a recovery marker') !== false,
			'a valid torrent name containing faultString remains reportable: ' . $err);
	}

	public function testActualXmlRpcFaultIsRefused()
	{
		$fault = '<?xml version="1.0"?><methodResponse><fault><value><struct>'
			. '<member><name>faultString</name><value><string>denied</string></value>'
			. '</member></struct></value></fault></methodResponse>';
		list($code, $out, $err) = $this->runAgainstPeer('', array($fault));
		$this->assertTrue($code !== 0 && strpos($err, 'rtorrent refused') !== false,
			'a real XML-RPC fault is still visible: ' . $err);
	}

	public function testBareApplyCannotClearEveryMarkedTorrent()
	{
		list($code, $out, $err, $requests) = $this->runAgainstPeer('apply', 1);
		$this->assertTrue($code !== 0, 'bulk apply is refused');
		$this->assertTrue(count($requests) <= 1,
			'bare apply sends no mutation after the inventory scan');
		$this->assertTrue(strpos($err, 'hash') !== false,
			'the operator is told to select one exact hash');
	}

	public function testApplyRefusesWhenSelectedMarkerIsAbsent()
	{
		$empty = '<?xml version="1.0"?><methodResponse><params><param><value>'
			. '<array><data></data></array></value></param></params></methodResponse>';
		list($code, $out, $err, $requests) = $this->runAgainstPeer(
			array('apply', str_repeat('A', 40), str_repeat('a', 64)),
			array($empty));
		$this->assertTrue($code !== 0, 'an absent selected marker is a refusal');
		$this->assertTrue(strpos($err, 'selected marker absent') !== false,
			'the operator is told to report again: ' . $err);
		$this->assertTrue(count($requests) === 1,
			'no mutation follows an empty inventory');
	}

	public function testChangedGenerationRefusesTheConditionalClear()
	{
		$marker = 'v1:original:0:' . str_repeat('B', 40);
		$fingerprint = hash('sha256', implode("\0", array(str_repeat('A', 40),
			str_repeat('C', 40), $marker, '')));
		$branchRefused = '<?xml version="1.0"?><methodResponse><params><param>'
			. '<value><string>REFUSED</string></value></param></params></methodResponse>';
		// The first body is replaced by runAgainstPeer's normal inventory fixture.
		$inventory = '<?xml version="1.0"?><methodResponse><params><param><value>'
			. '<array><data><value><array><data>'
			. '<value><string>' . str_repeat('A', 40) . '</string></value>'
			. '<value><string>probe.bin</string></value>'
			. '<value><string>' . $marker . '</string></value>'
			. '<value><string></string></value>'
			. '<value><string>' . str_repeat('C', 40) . '</string></value>'
			. '</data></array></value></data></array></value></param></params></methodResponse>';
		list($code, $out, $err, $requests) = $this->runAgainstPeer(
			array('apply', str_repeat('A', 40), $fingerprint),
			array($inventory, $branchRefused), $marker);
		$this->assertTrue($code !== 0 && strpos($err, 'conditional clear refused') !== false,
			'a changed marker or live ledger is reported as a refusal');
		$this->assertTrue(count($requests) === 2,
			'the refusal sends inventory and one atomic branch only');
		$this->assertTrue(strpos($requests[0], 'd.local_id=') !== false,
			'the inventory reads the immutable daemon local_id getter');
		$this->assertTrue(strpos($requests[1], 'method.list_keys') !== false
			&& strpos($requests[1], 'd.local_id=') !== false
			&& strpos($requests[1], 'retrackers-recovery-ack') !== false,
			'the branch compares ledger, local_id, marker and ack');
		$this->assertTrue(substr_count($requests[1], 'and=') === 3,
			'four CAS predicates use nested binary and commands');
		$this->assertTrue(strpos(implode('', $requests), 'session.save') === false,
			'a refused clear never saves a session');
	}

	public function testAnUnansweredClearIsNotReportedAsCleared()
	{
		// The first read succeeds, so the tool has a real marker to act on; every
		// call after it is accepted and dropped. Before the fix this printed
		// "session saved" and "cleared 1, remaining 0" and exited 0.
		$marker = 'v1:original:0:' . str_repeat('B', 40);
		$fingerprint = hash('sha256', implode("\0", array(str_repeat('A', 40),
			str_repeat('C', 40), $marker, '')));
		list($code, $out, $err) = $this->runAgainstPeer(array('apply',
			str_repeat('A', 40), $fingerprint), 1, $marker);
		$this->assertTrue($code !== 0,
			'an unanswered clear is not a successful run: exit ' . $code
				. ' out=' . $out);
		$this->assertTrue(strpos($out, 'cleared') === false,
			'nothing claims the markers were cleared: ' . $out);
		$this->assertTrue(strpos($out, 'session saved') === false,
			'nor that the session was saved: ' . $out);
		$this->assertTrue(strpos($err, 'no complete answer') !== false,
			'and it says which call went unanswered: ' . $err);
	}
}
