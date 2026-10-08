<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/ScgiToolPeer.php');

/**
 * tools/retrackers-clear-markers.php against a method-aware SCGI peer.
 *
 * tests/plugins/erasedata/RepairToolRefusalTest.php pins the refusal and
 * transport contract of one pair; this suite pins the classified refusal that
 * tells an operator what to do, and the reviewed multi-pair apply.
 */
class ClearMarkersToolTest extends TestCase
{
	private $dir = null;
	private $socket = null;

	public function setUp()
	{
		$this->dir = sys_get_temp_dir() . '/rt-clear-markers-' . getmypid() . '-' . uniqid();
		mkdir($this->dir, 0700);
		// sun_path holds about 108 bytes; keep the socket beside TMPDIR.
		$this->socket = sys_get_temp_dir() . '/rcm-' . getmypid() . '-' . substr(sha1($this->dir), 0, 8) . '.sock';
		@unlink($this->socket);
	}

	public function tearDown()
	{
		@unlink($this->socket);
		foreach (ScgiToolPeer::stagedEntries() as $entry)
			@is_dir($this->dir . $entry) ? @rmdir($this->dir . $entry) : @unlink($this->dir . $entry);
		@rmdir($this->dir);
	}

	private static function row($hash, $marker)
	{
		return(array($hash, 'probe.bin', $marker, '', str_repeat('C', 39) . substr($hash, -1)));
	}

	private static function fingerprint($row)
	{
		return(hash('sha256', implode("\0", array($row[0], $row[4], $row[2], $row[3]))));
	}

	// Run the shipped tool as the standalone /tmp copy; the peer answers by
	// method and records every request.
	private function runTool(array $args, array $rows, array $branchReplies)
	{
		$tool = ScgiToolPeer::stageClearMarkersTool($this->dir, $this->socket, false);
		return(ScgiToolPeer::run($this->socket, $tool, $args, function ($request) use ($rows, &$branchReplies) {
			$method = ScgiToolPeer::method($request);
			if ($method === 'd.multicall2') return(ScgiToolPeer::rows($rows));
			if ($method === 'branch')
				return(ScgiToolPeer::response('<string>' . array_shift($branchReplies) . '</string>'));
			if ($method === 'session.save') return(ScgiToolPeer::response('<i8>0</i8>'));
			return(ScgiToolPeer::response('<string></string>'));
		}));
	}

	public function testLiveReceiptLedgerRefusalSaysWhyAndWhereToClear()
	{
		$first = self::row(str_repeat('A', 40), 'v1:original:0:' . str_repeat('C', 40));
		$second = self::row(str_repeat('B', 40), 'v1:original:1:' . str_repeat('C', 39) . 'B');
		list($code, $out, $err) = $this->runTool(array('apply', $first[0], self::fingerprint($first)),
			array($first), array('LEDGER'));
		$this->assertTrue($code === 3 && strpos($err, 'receipt ledger rr.receipts.v1 is not empty') !== false &&
			strpos($err, 'inside the new container after it starts') !== false,
			'a single reviewed pair refused by a live ledger is told why and where to clear it: ' . $err);
		list($code, $out, $err, $requests, $methods) = $this->runTool(array('apply',
			$first[0], self::fingerprint($first), $second[0], self::fingerprint($second)),
			array($first, $second), array('LEDGER', 'LEDGER'));
		$this->assertTrue($code === 3 && $methods === array('d.multicall2', 'branch'),
			'a live ledger refuses the first conditional clear and stops: exit ' . $code . ' ' . implode(',', $methods));
		$this->assertTrue(strpos($err, 'conditional clear refused for ' . $first[0] . ': receipt ledger rr.receipts.v1 is not empty') !== false &&
			strpos($err, 'inside the new container after it starts') !== false &&
			strpos($err, 'the copy of this tool that shipped with that container') !== false &&
			strpos($out, 'session.save') === false,
			'the refusal names the non-empty ledger and both places where the marker can be cleared: ' . $err);
		$this->assertTrue(strpos($requests[1], 'branch=') !== false && strpos($requests[1], 'cat=CHANGED,cat=LEDGER') !== false &&
			substr_count($requests[1], 'not=(method.list_keys,rr.receipts.v1)') === 2,
			'one daemon call both guards the clear and classifies its refusal');
	}

	public function testReviewedPairsAreClearedOneByOneAndSavedOnce()
	{
		$first = self::row(str_repeat('A', 40), 'v1:original:0:' . str_repeat('C', 40));
		$second = self::row(str_repeat('B', 40), 'v1:original:1:' . str_repeat('C', 39) . 'B');
		list($code, $out, $err, $requests, $methods) = $this->runTool(array('apply',
			strtolower($first[0]), self::fingerprint($first), $second[0], self::fingerprint($second)),
			array($first, $second), array('CLEARED', 'CLEARED'));
		$this->assertTrue($code === 0 && $methods === array('d.multicall2', 'branch', 'd.custom', 'd.custom',
			'branch', 'd.custom', 'd.custom', 'session.save', 'd.custom', 'd.custom', 'd.custom', 'd.custom'),
			'each pair gets its own conditional clear and readback; the session is saved once: ' . implode(',', $methods) . ' ' . $err);
		$this->assertTrue(strpos($requests[1], $first[0]) !== false && strpos($requests[4], $second[0]) !== false &&
			strpos($out, 'marker empty in daemon memory ' . $first[0]) !== false &&
			strpos($out, 'marker empty in daemon memory ' . $second[0]) !== false,
			'both reviewed generations are reported clear');
	}

	public function testAChangedMarkerDoesNotStopTheOtherReviewedPairs()
	{
		$first = self::row(str_repeat('A', 40), 'v1:original:0:' . str_repeat('C', 40));
		$second = self::row(str_repeat('B', 40), 'v1:original:1:' . str_repeat('C', 39) . 'B');
		list($code, $out, $err, $requests, $methods) = $this->runTool(array('apply',
			$first[0], self::fingerprint($first), $second[0], self::fingerprint($second)),
			array($first, $second), array('CHANGED', 'CLEARED'));
		$this->assertTrue($code === 3 && in_array('session.save', $methods, true) &&
			strpos($err, 'conditional clear refused for ' . $first[0] . ': its marker, ack or local_id changed after the report') !== false &&
			strpos($out, 'marker empty in daemon memory ' . $second[0]) !== false &&
			strpos($out, 'marker empty in daemon memory ' . $first[0]) === false,
			'a changed generation is refused alone and named; the exit status reports it');
	}

	public function testEveryPairIsVerifiedBeforeAnyMutation()
	{
		$first = self::row(str_repeat('A', 40), 'v1:original:0:' . str_repeat('C', 40));
		$second = self::row(str_repeat('B', 40), 'v1:original:1:' . str_repeat('C', 39) . 'B');
		list($code, $out, $err, $requests, $methods) = $this->runTool(array('apply',
			$first[0], self::fingerprint($first), $second[0], str_repeat('0', 64)),
			array($first, $second), array());
		$this->assertTrue($code === 2 && $methods === array('d.multicall2') &&
			strpos($err, 'fingerprint changed for ' . $second[0]) !== false,
			'one stale fingerprint refuses the whole run before the first clear');
		list($code, $out, $err, $requests, $methods) = $this->runTool(array('apply',
			$first[0], self::fingerprint($first), $first[0], self::fingerprint($first)),
			array($first), array());
		$this->assertTrue($code === 2 && $methods === array() && strpos($err, 'usage:') !== false,
			'a repeated hash is a usage error');
	}

	// R4-RT-07: a pair cleared in daemon memory returns after a restart unless
	// the session is saved, so no exit after the first clear may skip the save.
	public function testEveryExitAfterAClearSavesTheSessionFirst()
	{
		$rows = array(self::row(str_repeat('A', 40), 'v1:original:0:' . str_repeat('C', 40)),
			self::row(str_repeat('B', 40), 'v1:original:1:' . str_repeat('C', 39) . 'B'),
			self::row(str_repeat('D', 40), 'v1:original:0:' . str_repeat('C', 39) . 'D'));
		$args = array('apply');
		foreach ($rows as $row) array_push($args, $row[0], self::fingerprint($row));
		$tool = ScgiToolPeer::stageClearMarkersTool($this->dir, $this->socket, false);
		// 'reappears': B's marker is back right after its clear; 'fault' and
		// 'silent': B's conditional clear faults or gets no answer.
		foreach (array('reappears' => 3, 'fault' => 2, 'silent' => 2) as $cut => $exit) {
			$cleared = array();
			list($code, $out, $err, $requests, $methods) = ScgiToolPeer::run($this->socket, $tool, $args,
				function ($request) use ($rows, $cut, &$cleared) {
					$method = ScgiToolPeer::method($request);
					$params = ScgiToolPeer::params($request);
					if ($method === 'd.multicall2') return(ScgiToolPeer::rows($rows));
					if ($method === 'branch') {
						if ($params[0] === $rows[1][0] && $cut === 'fault')
							return('<?xml version="1.0"?><methodResponse><fault><value><struct><member><name>faultCode</name>' .
								'<value><i8>-500</i8></value></member><member><name>faultString</name><value><string>' .
								'injected</string></value></member></struct></value></fault></methodResponse>');
						if ($params[0] === $rows[1][0] && $cut === 'silent') return(null);
						$cleared[] = $params[0];
						return(ScgiToolPeer::response('<string>CLEARED</string>'));
					}
					if ($method === 'session.save') return(ScgiToolPeer::response('<i8>0</i8>'));
					if ($method === 'd.custom')
						return(ScgiToolPeer::response('<string>' . ($params[0] === $rows[1][0] ?
							'v1:original:1:' . str_repeat('C', 39) . 'B' : '') . '</string>'));
					return(null);
				});
			$this->assertTrue($code === $exit && in_array('session.save', $methods, true) &&
				!in_array('branch', array_slice($methods, array_search('session.save', $methods, true)), true),
				$cut . ': the run stops at B and saves the session before it exits: exit ' . $code . ' ' . implode(',', $methods) . ' ' . $err);
			$this->assertTrue(strpos($err, 'session.save requested for the pairs cleared before this exit: ' . $rows[0][0]) !== false &&
				strpos($err, $rows[2][0]) === false && $cleared[0] === $rows[0][0],
				$cut . ': the operator learns which cleared pair the save covers: ' . $err);
		}
		list($code, $out, $err, $requests, $methods) = ScgiToolPeer::run($this->socket, $tool, $args,
			function ($request) use ($rows) {
				$method = ScgiToolPeer::method($request);
				if ($method === 'd.multicall2') return(ScgiToolPeer::rows($rows));
				if ($method === 'branch') return(ScgiToolPeer::response('<string>' .
					(strpos($request, $rows[0][0]) !== false ? 'CLEARED' : 'CHANGED') . '</string>'));
				if ($method === 'session.save') return(null);
				if ($method === 'd.custom') return(ScgiToolPeer::response('<string></string>'));
				return(null);
			});
		$this->assertTrue($code === 2 && strpos($err, 'cleared in daemon memory only; session.save unconfirmed: ' . $rows[0][0]) !== false,
			'a save that gets no answer names what may return after a restart: exit ' . $code . ' ' . $err);
	}
}
