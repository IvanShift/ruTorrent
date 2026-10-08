<?php

require_once(__DIR__ . '/RetrackersTestStubs.php');
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_DEFER_READ_TIMEOUT', 0.04);
define('RETRACKERS_RESET_READ_TIMEOUT', 0.04);
define('RETRACKERS_PREFLIGHT_POLL', 0.001);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');
require_once(__DIR__ . '/../../../plugins/retrackers/retryreset.php');
require_once(__DIR__ . '/RetrackersWireFake.php');

// queueRetryRows() through the real restricted codec, answered by one raw reply.
class TerminalNoticeRawAdapter extends RetrackersWorkerRpcAdapter
{
	public $raw;
	public $requests = array();

	protected function send($payload, $plan, &$failure = null, $historical = false)
	{
		$this->requests[] = $payload;
		$failure = null;
		$decoded = retrackersDecodeRestrictedRawResponse($this->raw, $plan);
		if ($decoded === false) $failure = 'malformed-response';
		return($decoded);
	}
}

/** The once-per-session WebUI notice for exhausted queue jobs (init.php -> init.js). */
class TerminalNoticeTest extends TestCase
{
	// The notice line for $count jobs of profile $user; the command's own form
	// is pinned by testTheCommandRunsFromAnyDirectoryAndNamesTheContainerForm.
	private static function notice($count, $user)
	{
		return('plugin.terminalJobs = ' . json_encode(array('count' => $count,
			'command' => RetrackersWorkerRpcAdapter::retryResetCommand($user)), JSON_UNESCAPED_SLASHES) . ";\n");
	}

	// R4-RT-10: "php tools/..." ran only from the application root.
	public function testTheCommandRunsFromAnyDirectoryAndNamesTheContainerForm()
	{
		$tool = realpath(__DIR__ . '/../../../tools/retrackers-retry-reset.php');
		$absent = sys_get_temp_dir() . '/rr-no-container-' . getmypid();
		$this->assertEquals('php ' . $tool . ' --user=alice ' . str_repeat('A', 40),
			RetrackersWorkerRpcAdapter::retryResetCommand('alice', str_repeat('A', 40), $absent),
			'outside a container: this install\'s PHP and the absolute tool path');
		$this->assertEquals('php ' . $tool, RetrackersWorkerRpcAdapter::retryResetCommand('', null, $absent),
			'the default profile needs no --user');
		$marker = sys_get_temp_dir() . '/rr-dockerenv-' . getmypid();
		touch($marker);
		$command = RetrackersWorkerRpcAdapter::retryResetCommand('', null, $marker);
		unlink($marker);
		if (!function_exists('posix_geteuid')) {
			$this->assertEquals('php ' . $tool, $command, 'without posix the user is unknown, so the plain form stays');
			return;
		}
		$account = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
		$name = is_array($account) && $account['name'] !== '' ? $account['name'] : (string)posix_geteuid();
		$this->assertEquals('docker exec -u ' . $name . ' <container> php ' . $tool, $command,
			'inside Docker: the exec form for the daemon\'s user, the container left to fill in');
	}

	// What one session init of alice reads: every download, one rows read.
	const ROWS_REQUEST = array('', 'main', 'd.hash=', 'd.local_id=', 'd.custom=retrackers-queued',
		'd.custom=retrackers-retry');

	// Captured from rutorrent-round2:20261007 (rTorrent 0.16.25, rutorrent-native
	// d883db88) by the L3 lab on 2026-10-08: the answer to queueRetryRows()'s
	// exact four-slot d.multicall2 over trusted SCGI, for two lab torrents -- a
	// private one without Q and a paused one whose counted retries ran out.
	const CAPTURED_ROWS = 'U3RhdHVzOiAyMDAgT0sNCkNvbnRlbnQtVHlwZTogdGV4dC94bWwNCkNvbnRlbnQtTGVuZ3RoOiA3ODcNCg0KPD94bWwgdmVyc2lvbj0iMS4wIj8+PG1ldGhvZFJlc3BvbnNlPjxwYXJhbXM+PHBhcmFtPjx2YWx1ZT48YXJyYXk+PGRhdGE+PHZhbHVlPjxhcnJheT48ZGF0YT48dmFsdWU+PHN0cmluZz43NUQ2NkZCNzk0MUY2NDM2QjMxQUU1QjExMDBDREE3NEM2RTVEOThBPC9zdHJpbmc+PC92YWx1ZT48dmFsdWU+PHN0cmluZz4yRDZDNzQzMTMwMzEzOTJERUM4REIzNEVDNzhCOEE5MjI2RjhCQzFFPC9zdHJpbmc+PC92YWx1ZT48dmFsdWU+PHN0cmluZz48L3N0cmluZz48L3ZhbHVlPjx2YWx1ZT48c3RyaW5nPjwvc3RyaW5nPjwvdmFsdWU+PC9kYXRhPjwvYXJyYXk+PC92YWx1ZT48dmFsdWU+PGFycmF5PjxkYXRhPjx2YWx1ZT48c3RyaW5nPjc5MDI4QTdERDgxMzIwQTQ1NEY5NzA2MzJDODk1Q0FBRTlEQTMxQjU8L3N0cmluZz48L3ZhbHVlPjx2YWx1ZT48c3RyaW5nPjJENkM3NDMxMzAzMTM5MkRGMkY3NkYwMTBCQjk3NTk1QkYxNzczN0I8L3N0cmluZz48L3ZhbHVlPjx2YWx1ZT48c3RyaW5nPnExOmUzYjBjNDQyOThmYzFjMTQ5YWZiZjRjODk5NmZiOTI0MjdhZTQxZTQ2NDliOTM0Y2E0OTU5OTFiNzg1MmI4NTU6YzNmNDg1ZjI5NGRjZGFmMWIyOTY2N2Q2Y2JkM2M3MDQ8L3N0cmluZz48L3ZhbHVlPjx2YWx1ZT48c3RyaW5nPnIxOmMzZjQ4NWYyOTRkY2RhZjFiMjk2NjdkNmNiZDNjNzA0OjY6MDpzb3VyY2Utbm90LXJlYWR5PC9zdHJpbmc+PC92YWx1ZT48L2RhdGE+PC9hcnJheT48L3ZhbHVlPjwvZGF0YT48L2FycmF5PjwvdmFsdWU+PC9wYXJhbT48L3BhcmFtcz48L21ldGhvZFJlc3BvbnNlPg==';

	// A missing API is an assertion failure here, not a fatal that hides the other cases.
	private function requireNoticeApi()
	{
		foreach (array(array('RetrackersRetryReset', 'sessionNotice'), array('RetrackersRetryReset', 'terminalRows'),
			array('RetrackersRetryReset', 'terminalCount'), array('RetrackersLifecycleRpcAdapter', 'queueRetryRows')) as $api) {
			if (!method_exists($api[0], $api[1])) {
				$this->assertTrue(false, 'the terminal-jobs notice API exists: ' . implode('::', $api));
				return(false);
			}
		}
		return(true);
	}

	private function terminal($wire, $hash, $user, $reason = 'source-not-ready', $claimed = false)
	{
		$wire->add($hash, strrev($hash), $user, '1', $claimed);
		$wire->torrents[$hash]['retry'] = 'r1:' . substr($wire->torrents[$hash]['queued'], 68) . ':6:0:' . $reason;
	}

	// alice: two terminal jobs (one held right now), one counted, one fresh,
	// one terminal state of an older Q; bob: one terminal job; one unqueued torrent.
	private function mixedQueue()
	{
		$wire = new RetrackersWireFake();
		$this->terminal($wire, str_repeat('A', 40), 'alice');
		$this->terminal($wire, str_repeat('B', 40), 'alice', 'capture-refused', true);
		$wire->torrents[str_repeat('B', 40)]['held'] = true;
		$wire->add(str_repeat('C', 40), str_repeat('3', 40), 'alice', '1', false);
		$wire->torrents[str_repeat('C', 40)]['retry'] = 'r1:' . substr($wire->torrents[str_repeat('C', 40)]['queued'], 68) .
			':5:' . ($wire->now + 32) . ':preflight-refused';
		$wire->add(str_repeat('D', 40), str_repeat('4', 40), 'alice', '1', false);
		$this->terminal($wire, str_repeat('E', 40), 'alice');
		$wire->torrents[str_repeat('E', 40)]['retry'] = 'r1:' . str_repeat('f', 32) . ':6:0:source-not-ready';
		$this->terminal($wire, str_repeat('F', 40), 'bob');
		$wire->add(str_repeat('0', 40), str_repeat('5', 40), 'alice', '1', false);
		$wire->torrents[str_repeat('0', 40)]['queued'] = '';
		return($wire);
	}

	public function testTheNoticeCountsTheCallersTerminalJobsFromOneRowsRead()
	{
		if (!$this->requireNoticeApi()) return;
		$wire = $this->mixedQueue();
		$adapter = new RetrackersWireWorkerAdapter($wire);
		$notice = RetrackersRetryReset::sessionNotice($adapter, 'alice');
		$this->assertEquals(self::notice(2, 'alice'),
			$notice, 'two terminal jobs of alice, held or not; counted, fresh, stale-Q, foreign and unqueued rows are not');
		$this->assertTrue($wire->calls === array('d.multicall2') &&
			RetrackersWireFake::params($wire->payloads['d.multicall2'][0]) === self::ROWS_REQUEST,
			'the notice costs exactly one rows read with the four columns, and no read per torrent');
		$this->assertEquals(self::notice(1, 'bob'),
			RetrackersRetryReset::sessionNotice($adapter, 'bob'), 'each profile counts only its own Q');
		$default = new RetrackersWireFake();
		$this->terminal($default, str_repeat('A', 40), '');
		$this->assertEquals(self::notice(1, ''),
			RetrackersRetryReset::sessionNotice(new RetrackersWireWorkerAdapter($default), ''),
			'without HTTP authentication the command carries no --user');
	}

	public function testTheListingSharesTheFilterAndInspectsOnlyTerminalJobs()
	{
		if (!$this->requireNoticeApi()) return;
		$wire = $this->mixedQueue();
		$adapter = new RetrackersWireWorkerAdapter($wire);
		$failure = null;
		$jobs = RetrackersRetryReset::terminalJobs($adapter, 'alice', $failure);
		$hashes = is_array($jobs) ? array_map(function ($job) { return($job['hash']); }, $jobs) : $jobs;
		$this->assertTrue($hashes === array(str_repeat('A', 40), str_repeat('B', 40)) &&
			!isset($jobs[0]['refused']) && $jobs[1]['refused'] === 'claim-not-resettable',
			'the list mode names the same two jobs the notice counts: ' . var_export($hashes, true));
		$counts = array_count_values($wire->calls);
		// A: Q, claim and retry; B: Q and its held claim, which ends that inspection.
		$this->assertTrue($counts['d.multicall2'] === 1 && $counts['d.custom'] === 3 &&
			$counts['system.retrackers.original_claim'] === 2 && count($wire->calls) === 6,
			'one rows read, then reads only for each terminal job: ' . json_encode($counts));
		$failure = null;
		$this->assertTrue(RetrackersRetryReset::terminalCount($adapter, 'alice', $failure) === count($jobs) &&
			$failure === null, 'terminalCount and the listing agree');
	}

	public function testNothingIsShownOrReadWithoutTerminalJobsOrNativeQueue()
	{
		if (!$this->requireNoticeApi()) return;
		$wire = new RetrackersWireFake();
		$wire->add(str_repeat('A', 40), str_repeat('1', 40), 'alice', '1', false);
		$wire->torrents[str_repeat('A', 40)]['retry'] = 'r1:' . substr($wire->torrents[str_repeat('A', 40)]['queued'], 68) .
			':5:' . ($wire->now + 32) . ':preflight-refused';
		$this->assertTrue(RetrackersRetryReset::sessionNotice(new RetrackersWireWorkerAdapter($wire), 'alice') === '' &&
			$wire->calls === array('d.multicall2'), 'a counted job still retries on its own, so nothing is shown');
		$this->terminal($wire, str_repeat('B', 40), 'alice');
		$wire->calls = array();
		$legacy = new RetrackersWireWorkerAdapter($wire);
		$legacy->setFamily(1);
		$this->assertTrue(RetrackersRetryReset::sessionNotice($legacy, 'alice') === '' && $wire->calls === array(),
			'a 0.9.8 daemon (family 1) has no native queue: no notice and no read');
	}

	public function testAnUnansweredReadShowsNothingAndTheNextInitReadsAgain()
	{
		if (!$this->requireNoticeApi()) return;
		$wire = new RetrackersWireFake();
		$this->terminal($wire, str_repeat('A', 40), 'alice');
		$adapter = new RetrackersWireWorkerAdapter($wire);
		$wire->fail['d.multicall2'] = array('read-timeout');
		$this->assertTrue(RetrackersRetryReset::sessionNotice($adapter, 'alice') === '' &&
			count($wire->payloads['d.multicall2']) === 1,
			'a page load never waits on a retry of the notice read; it shows nothing');
		$this->assertTrue(strpos(RetrackersRetryReset::sessionNotice($adapter, 'alice'), '"count":1') !== false &&
			count($wire->payloads['d.multicall2']) === 2, 'the next session init reads again and shows the job');
	}

	public function testTheRowsReadClassifiesFaultsAndRefusesMalformedRows()
	{
		if (!$this->requireNoticeApi()) return;
		$adapter = new TerminalNoticeRawAdapter();
		$adapter->setFamily(2);
		$adapter->raw = base64_decode('U3RhdHVzOiAyMDAgT0sNCkNvbnRlbnQtVHlwZTogdGV4dC94bWwNCkNvbnRlbnQtTGVuZ3RoOiAyODANCg0KPD94bWwgdmVyc2lvbj0iMS4wIj8+PG1ldGhvZFJlc3BvbnNlPjxmYXVsdD48dmFsdWU+PHN0cnVjdD48bWVtYmVyPjxuYW1lPmZhdWx0Q29kZTwvbmFtZT48dmFsdWU+PGk4Pi01MDA8L2k4PjwvdmFsdWU+PC9tZW1iZXI+PG1lbWJlcj48bmFtZT5mYXVsdFN0cmluZzwvbmFtZT48dmFsdWU+PHN0cmluZz5pbnZhbGlkIHBhcmFtZXRlcnM6IGluZm8taGFzaCBub3QgZm91bmQ8L3N0cmluZz48L3ZhbHVlPjwvbWVtYmVyPjwvc3RydWN0PjwvdmFsdWU+PC9mYXVsdD48L21ldGhvZFJlc3BvbnNlPg==');
		$failure = null;
		$this->assertTrue($adapter->queueRetryRows($failure) === false && $failure === 'rpc-fault',
			'a captured 0.16 fault answer is classified as a fault, not as rows');
		$adapter->raw = $this->tinyRows(array(str_repeat('A', 40), str_repeat('B', 40), '', ''));
		$this->assertTrue($adapter->queueRetryRows($failure) === array(array(str_repeat('A', 40), str_repeat('B', 40), '', '')) &&
			$failure === null, 'control: a TinyXML2 row with four string cells decodes');
		$adapter->raw = $this->tinyRows(array(str_repeat('A', 40), str_repeat('B', 40), ''));
		$this->assertTrue($adapter->queueRetryRows($failure) === false && $failure === 'malformed-response',
			'the same row without its fourth cell is refused by the restricted codec');
		$adapter->raw = $this->tinyRows(array(strtolower(str_repeat('A', 40)), str_repeat('B', 40), '', ''));
		$this->assertTrue($adapter->queueRetryRows($failure) === false && $failure === 'malformed-response',
			'a row whose hash is not the canonical uppercase identity is refused');
	}

	private function tinyRows(array $row)
	{
		$cells = '';
		foreach ($row as $cell) $cells .= '<value><string>' . $cell . '</string></value>';
		$body = '<?xml version="1.0"?><methodResponse><params><param><value><array><data><value><array><data>' .
			$cells . '</data></array></value></data></array></value></param></params></methodResponse>';
		return("Status: 200 OK\r\nContent-Type: text/xml\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
	}

	public function testTheCapturedFourColumnAnswerDecodesThroughTheRestrictedCodec()
	{
		if (!$this->requireNoticeApi()) return;
		if (self::CAPTURED_ROWS === '') {
			$this->assertTrue(false, 'the captured 0.16.25 four-column answer is pinned');
			return;
		}
		$adapter = new TerminalNoticeRawAdapter();
		$adapter->setFamily(2);
		$adapter->raw = base64_decode(self::CAPTURED_ROWS, true);
		$failure = null;
		$rows = $adapter->queueRetryRows($failure);
		$this->assertTrue(is_array($rows) && count($rows) === 2 && $failure === null &&
			RetrackersWireFake::params($adapter->requests[0]) === self::ROWS_REQUEST,
			'the captured answer to the exact request decodes into two rows: ' . var_export($failure, true));
		if (!is_array($rows) || count($rows) !== 2) return;
		$this->assertEquals(array(array('hash' => '79028A7DD81320A454F970632C895CAAE9DA31B5',
			'queue_id' => 'c3f485f294dcdaf1b29667d6cbd3c704',
			'retry' => 'r1:c3f485f294dcdaf1b29667d6cbd3c704:6:0:source-not-ready')),
			RetrackersRetryReset::terminalRows($rows, ''), 'the captured terminal job of the default profile is the only one');
		// The lab's getplugins.php answered exactly this line for the same state.
		$this->assertEquals(self::notice(1, ''),
			RetrackersRetryReset::sessionNotice($adapter, ''), 'the captured state produces the notice the lab page showed');
	}
}
