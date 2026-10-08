<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');

/**
 * AutoTools through the real php/xmlrpc.php parser against a fake daemon.
 *
 * The parser hands out the daemon's own bytes. Until 2026-10 it escaped each
 * backslash and double quote of an answer for a JavaScript literal, and every
 * AutoTools comparison of a daemon path with a path its finished hook was
 * given failed for a download whose path carried either character: a claimed
 * Move stopped after its payload had moved (AUD-ESC-04, L4b-NEW-1, probe9b),
 * the recovery exits never finished (AUD-ESC-11), and the {DIR} label was
 * stored escaped (AUD-ESC-07, probe10). The paths here carry both characters
 * and the sequences \" and \\ themselves, so neither that escaping nor a
 * second unescaping of an answer can pass. setHandlers() and init.php are
 * driven the same way to show what they ask the daemon to run and what they
 * serve the WebUI.
 */
class AutoToolsParserTest extends TestCase
{
	const HASH = 'ABCDEF0123456789ABCDEF0123456789ABCDEF01';
	const JOB = '0123456789abcdef0123456789abcdef';
	// The claim token of the receipts the daemon answers.
	const TOKEN = 'dddddddddddddddddddddddddddddddd:0000000000000001';
	// A download directory, a destination directory and a payload name with a
	// double quote, a backslash, the sequences \" and \\, an apostrophe and an
	// ampersand, which the daemon writes as an entity.
	const SOURCE_DIR = 'aud "q" \\b \'a\' &c';
	const DEST_DIR = 'to \\"x\\"\\\\y';
	const PAYLOAD = 'pay \\"load\\" \\\\ x.bin';
	// A label folder: d.custom1 is 'Rock%20%22n%22%20Roll%5Cx' for it.
	const LABEL_DIR = 'Rock "n" Roll\\x';

	private $base;
	private $daemon;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-autotools-parser-' . getmypid() . '-' . bin2hex(random_bytes(4));
		@mkdir($this->base, 0700, true);
	}

	public function tearDown()
	{
		if ($this->daemon !== null)
			$this->daemon->stop();
		$this->daemon = null;
		$this->remove($this->base);
	}

	private function remove($path)
	{
		if (is_dir($path) && !is_link($path)) {
			@chmod($path, 0700);
			foreach (array_diff(scandir($path), array('.', '..')) as $entry)
				$this->remove($path . '/' . $entry);
			@rmdir($path);
		} else @unlink($path);
	}

	private function repoRoot()
	{
		return realpath(__DIR__ . '/../../..');
	}

	// Runs $body in a child PHP with the real core parser against a fake
	// daemon answering $replies (in order, or by method name), from the
	// AutoTools plugin directory as its CLI entry points run, and returns what
	// the body printed as JSON. $settings overrides the default and session
	// directories of the daemon.
	private function drive($replies, $body, array $settings = array())
	{
		$settings = array_merge(array('directory' => $this->base . '/dl', 'session' => $this->base . '/session'),
			$settings);
		if ($this->daemon !== null)
			$this->daemon->stop();
		$this->daemon = new FakeRtorrentDaemon($replies, $this->base . '/calls-' . uniqid() . '.log');
		$driver = $this->base . '/drive-' . uniqid() . '.php';
		file_put_contents($driver, "<?php\n"
			. '$_SERVER[\'REMOTE_USER\'] = "tester"; $_ENV[\'RU_PROFILE_PATH\'] = '
				. var_export($this->base . '/profile', true) . '; $_ENV[\'RU_LOG_FILE\'] = '
				. var_export($this->base . '/errors.log', true) . ";\n"
			. 'chdir(' . var_export($this->repoRoot() . '/plugins/autotools', true) . ");\n"
			. 'require_once(' . var_export($this->repoRoot() . '/conf/config.php', true) . ");\n"
			. 'require_once(' . var_export($this->repoRoot() . '/php/settings.php', true) . ");\n"
			. '$r = new ReflectionClass("rTorrentSettings"); $s = $r->newInstanceWithoutConstructor();'
			. ' $s->iVersion = 0x1019; $s->aliases = array(); $s->linkExist = true;'
			. ' $s->directory = ' . var_export($settings['directory'], true) . ';'
			. ' $s->session = ' . var_export($settings['session'], true) . ';'
			. ' $p = $r->getProperty("theSettings"); $p->setAccessible(true); $p->setValue(null, $s);' . "\n"
			. '$scgi_host = "127.0.0.1"; $scgi_port = ' . $this->daemon->port() . ';'
				. ' $rpcTimeOut = 10; $rpcLogCalls = false;' . "\n"
			. 'require_once(' . var_export($this->repoRoot() . '/php/xmlrpc.php', true) . ");\n"
			. $body);
		$out = array();
		exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 ' . escapeshellarg($driver) . ' 2>&1', $out);
		$printed = implode("\n", $out);
		$answer = json_decode($printed, true);
		if (!is_array($answer))
			throw new Exception('the driver printed: ' . $printed);
		return $answer;
	}

	private function logLines()
	{
		$log = @file($this->base . '/errors.log', FILE_IGNORE_NEW_LINES);
		return $log === false ? array() : array_map(function ($line) {
			return preg_replace('/^\[[^\]]*\] /', '', $line);
		}, $log);
	}

	private function receipt()
	{
		return self::TOKEN . '|1|1|1|' . self::HASH;
	}

	private function journalDir()
	{
		return $this->base . '/session/.autotools-file-jobs';
	}

	private function jobPath()
	{
		return $this->journalDir() . '/' . self::HASH . '.move.json';
	}

	private function saveJob(array $job)
	{
		file_put_contents($this->jobPath(), json_encode($job, JSON_UNESCAPED_SLASHES));
	}

	// A planned Move of a single file, journaled before its claim as
	// prepare() writes it, with $changes applied: from a download directory
	// into a destination directory that both carry the characters the parser
	// once escaped.
	private function journaledMove(array $changes = array())
	{
		$source = $this->base . '/dl/' . self::SOURCE_DIR;
		$destination = $this->base . '/fin/' . self::DEST_DIR;
		foreach (array($this->base . '/session', $this->journalDir(), $this->base . '/dl', $source,
			$this->base . '/fin', $destination) as $dir)
			@mkdir($dir, 0700);
		$src = $source . '/' . self::PAYLOAD;
		file_put_contents($src, 'payload bytes');
		$stat = lstat($src);
		$job = array_merge(array('version' => 1, 'kind' => 'Move', 'hash' => self::HASH, 'phase' => 'intent',
			'src' => $src, 'dst' => $destination . '/' . self::PAYLOAD, 'identity' => $stat['dev'] . ':' . $stat['ino'],
			'source_dir' => $source, 'dest_dir' => $destination, 'daemon_dir' => $destination,
			'multi' => false, 'files' => array(self::PAYLOAD), 'cross' => false,
			'stage' => $destination . '/.autotools-move-' . self::JOB . '.stage',
			'quarantine' => $source . '/.autotools-move-' . self::JOB . '.source',
			'job_id' => self::JOB, 'token' => '', 'finished_root' => $this->base . '/fin',
			'torrent_name' => self::PAYLOAD), $changes);
		$this->saveJob($job);
		return $job;
	}

	// The receipt captureReceipt() records for the single-file payload at
	// $path before its rename.
	private function receiptOf($path)
	{
		$stat = lstat($path);
		return array('files' => array('' => array('id' => $stat['dev'] . ':' . $stat['ino'],
			'size' => filesize($path), 'hash' => hash_file('sha256', $path))), 'dirs' => array());
	}

	// A no-replace rename helper that does on one filesystem what the
	// shipped one does, and records each rename it is asked for.
	private function renameHelper()
	{
		$helper = $this->base . '/rename-helper';
		file_put_contents($helper, "#!/bin/sh\n[ \"\$1\" = --probe ] && exit 0\n"
			. 'printf \'%s -> %s\n\' "$1" "$2" >> ' . escapeshellarg($this->base . '/renames.log') . "\n"
			. "[ -e \"\$2\" ] && exit 1\nexec mv -- \"\$1\" \"\$2\"\n");
		chmod($helper, 0700);
		return $helper;
	}

	private function renames()
	{
		$log = @file($this->base . '/renames.log', FILE_IGNORE_NEW_LINES);
		return $log === false ? array() : $log;
	}

	// The child code that has the Move transaction rename through $helper.
	private function renameSeam($helper)
	{
		return $helper === null ? '' : 'class ParserScriptedRename extends ErasedataFilesystemOps {'
			. ' public static function renameHelperPath() { return ' . var_export($helper, true) . '; } }' . "\n"
			. '$f = new ReflectionProperty("AutoToolsMoveTransaction", "files"); $f->setAccessible(true);'
			. ' $f->setValue(null, new ParserScriptedRename());' . "\n";
	}

	// The child code that prints $result with the journal the job left.
	private function printJournal()
	{
		return '$path = ' . var_export($this->jobPath(), true) . ';' . "\n"
			. 'echo json_encode(array("result" => $result, "job" => is_file($path)'
			. ' ? json_decode(file_get_contents($path), true) : null));';
	}

	// Runs the recovery step AutoToolsMoveTransaction::reconcile() on $job
	// against a daemon answering $replies in order; the result is its
	// answer, or the exception it threw, and the journal it left.
	private function reconcile(array $replies, array $job, $helper = null)
	{
		return $this->drive($replies, 'require_once("./move_tx_lib.php");' . "\n"
			. $this->renameSeam($helper)
			. '$m = new ReflectionMethod("AutoToolsMoveTransaction", "reconcile"); $m->setAccessible(true);' . "\n"
			. 'try { $result = $m->invoke(null, ' . var_export($this->journalDir(), true) . ', '
				. var_export($job, true) . '); }'
			. ' catch (Throwable $e) { $result = "exception: " . $e->getMessage(); }' . "\n"
			. $this->printJournal());
	}

	private function entries($directory)
	{
		$entries = @scandir($directory);
		return $entries === false ? null : array_values(array_diff($entries, array('.', '..')));
	}

	// ACC-OWNB-02 residual: the claim of a planned Move is skipped while
	// retrackers re-adds the download, and its source path is then read back.
	// It is the daemon's bytes, the job's source, so the job waits for the
	// download. While the parser escaped it (AUD-ESC-04, L4b-NEW-1) it read
	// as 'daemon source path changed' and the Move was refused for good.
	public function testASkippedClaimComparesTheDaemonsOwnSourceBytes()
	{
		$job = $this->journaledMove();
		$held = $this->reconcile(array(array('absent'), array('SKIP'), array(self::JOB), array($job['src']), array('0'),
			array('held')), $job);
		$this->assertSame(array('d.stop_close_claim_state', 'branch', 'd.get_custom', 'd.get_base_path', 'd.is_active',
			'system.retrackers.original_claim'), $this->daemon->calls(),
			'the claim is skipped and the download read: ' . json_encode($this->daemon->calls()));
		$this->assertSame(false, $held['result'], 'the Move does not proceed yet: ' . json_encode($held['result']));
		$this->assertSame(array('autotools: move ' . self::HASH . ' deferred: download-not-running (retrackers-claim);'
			. ' recover.php claims it again every 60 s'), $this->logLines(), 'the job waits, it is not refused: '
			. json_encode($this->logLines(), JSON_UNESCAPED_SLASHES));
		$this->assertTrue(is_array($held['job']) && $held['job']['phase'] === 'intent'
			&& is_int($held['job']['waiting_since']), 'and is kept for the recovery pass');
		$this->assertSame('payload bytes', @file_get_contents($job['src']), 'the data stays at its source');
	}

	// The same paths through a whole claimed Move: after the payload is
	// renamed and the directory published, the daemon's directory and base
	// path are compared with the job's. They match now. While the parser
	// escaped them (AUD-ESC-04, L4b-NEW-1, probe9b) the job stopped with
	// 'daemon-directory-mismatch' after the data had moved, leaving the
	// download stopped under its claim and the job retried every 60 s.
	public function testAClaimedMoveOfQuotedAndBackslashedPathsCompletes()
	{
		$job = $this->journaledMove();
		$r = $this->receipt();
		$moved = $this->reconcile(array(array('absent'), array($r), array('active|' . $r), array($r),
			array('active|' . $r), array('1'), array($job['daemon_dir']), array('1'), array($job['daemon_dir']),
			array('1'), array('1'), array($job['dst']), array('done|S|' . $r), array('1')), $job, $this->renameHelper());
		$this->assertSame(array('d.stop_close_claim_state', 'branch', 'd.stop_close_claim_state', 'd.stop_close_claim',
			'd.stop_close_claim_state', 'd.directory.set_if_stop_close_claim', 'd.get_directory',
			'd.start_if_stop_close_claim', 'd.get_directory', 'd.is_open', 'd.is_active', 'd.get_base_path',
			'd.stop_close_claim_state', 'd.ack_stop_close_claim'), $this->daemon->calls(),
			'claimed, published, restarted and acknowledged: ' . json_encode($this->daemon->calls()));
		$this->assertSame(true, $moved['result'], 'the Move completes: ' . json_encode($moved['result']));
		$this->assertSame('notice-pending', $moved['job']['phase'], 'and waits only for its notice');
		$this->assertSame(array('autotools: move ' . self::HASH . ' physical commit: payload and active daemon'
			. ' at destination'), $this->logLines(), 'one commit line, no hold: '
			. json_encode($this->logLines(), JSON_UNESCAPED_SLASHES));
		$this->assertSame(array($job['src'] . ' -> ' . $job['dst']), $this->renames(), 'the payload is renamed once');
		$this->assertSame('payload bytes', @file_get_contents($job['dst']), 'the payload is at its destination');
		$this->assertSame(false, file_exists($job['src']), 'and gone from its source');
	}

	// AUD-ESC-11: a worker that died in its abort after rTorrent restored the
	// original run intent (d.start_if_stop_close_claim answered, the claim
	// terminal) and before it acknowledged the claim is finished by recovery,
	// which compares the daemon's directory with the job's source directory.
	// Escaped, that directory never matched: recovery threw
	// 'terminal-with-ambiguous-payload' on every pass and the claim's lease
	// stayed on the download.
	public function testRecoveryFinishesAnAbortThatDiedBeforeItsAcknowledgement()
	{
		$job = $this->journaledMove(array('phase' => 'aborting', 'token' => self::TOKEN));
		$r = $this->receipt();
		$aborted = $this->reconcile(array(array('terminal|S|' . $r), array($job['source_dir']),
			array('terminal|S|' . $r), array('1'), array('done|S|' . $r), array('1')), $job);
		$this->assertSame(array('d.stop_close_claim_state', 'd.get_directory', 'd.stop_close_claim_state',
			'd.replay_stop_close_claim', 'd.stop_close_claim_state', 'd.ack_stop_close_claim'), $this->daemon->calls(),
			'the source directory is confirmed and the claim replayed and acknowledged: '
			. json_encode($this->daemon->calls()));
		$this->assertSame(false, $aborted['result'], 'the Move stays refused: ' . json_encode($aborted['result']));
		$this->assertSame(null, $aborted['job'], 'its job is retired');
		$this->assertSame(array('autotools: move ' . self::HASH . ' refused: original daemon state restored after move'
			. ' abort'), $this->logLines(), 'with one line that says so: '
			. json_encode($this->logLines(), JSON_UNESCAPED_SLASHES));
		$this->assertSame('payload bytes', @file_get_contents($job['src']), 'the data stays at its source');
	}

	// AUD-ESC-11: a worker that died after acknowledging its abort, before
	// retiring the job, leaves no claim; recovery retires the job once the
	// daemon's directory is the source directory. Escaped, it read
	// 'aborted-daemon-directory-ambiguous' and held the job for ever.
	public function testRecoveryRetiresAnAbortAcknowledgedBeforeTheWorkerDied()
	{
		$job = $this->journaledMove(array('phase' => 'aborting', 'token' => self::TOKEN));
		$retired = $this->reconcile(array(array('absent'), array($job['source_dir'])), $job);
		$this->assertSame(array('d.stop_close_claim_state', 'd.get_directory'), $this->daemon->calls(),
			'the source directory is read: ' . json_encode($this->daemon->calls()));
		$this->assertSame(false, $retired['result'], 'the Move stays refused: ' . json_encode($retired['result']));
		$this->assertSame(null, $retired['job'], 'its job is retired');
		$this->assertSame(array('autotools: move ' . self::HASH . ' refused: move abort was already reconciled'),
			$this->logLines(), 'with one line that says so: ' . json_encode($this->logLines(), JSON_UNESCAPED_SLASHES));
		$this->assertSame('payload bytes', @file_get_contents($job['src']), 'the data stays at its source');
	}

	// AUD-ESC-11: a worker that died after its rename, once the daemon had
	// published the directory and restored the run intent (the claim
	// terminal), is finished by recovery when the daemon's directory is the
	// job's. Escaped, recovery threw 'terminal-with-ambiguous-payload' while
	// the data already lay at its destination.
	public function testRecoveryFinishesAMoveWhoseClaimTurnedTerminalAfterTheRename()
	{
		$job = $this->journaledMove(array('phase' => 'published', 'token' => self::TOKEN));
		$job['receipt'] = $this->receiptOf($job['src']);
		rename($job['src'], $job['dst']);
		$this->saveJob($job);
		$r = $this->receipt();
		$recovered = $this->reconcile(array(array('terminal|S|' . $r), array($job['daemon_dir']),
			array('terminal|S|' . $r), array('1'), array('done|S|' . $r), array('1')), $job);
		$this->assertSame(array('d.stop_close_claim_state', 'd.get_directory', 'd.stop_close_claim_state',
			'd.replay_stop_close_claim', 'd.stop_close_claim_state', 'd.ack_stop_close_claim'), $this->daemon->calls(),
			'the published directory is confirmed and the claim acknowledged: ' . json_encode($this->daemon->calls()));
		$this->assertSame(true, $recovered['result'], 'the Move completes: ' . json_encode($recovered['result']));
		$this->assertSame('notice-pending', $recovered['job']['phase'], 'and waits only for its notice');
		$this->assertSame(array('autotools: move ' . self::HASH . ' recovered: terminal daemon receipt and payload'
			. ' reconciled'), $this->logLines(), 'with one line that says so: '
			. json_encode($this->logLines(), JSON_UNESCAPED_SLASHES));
		$this->assertSame('payload bytes', @file_get_contents($job['dst']), 'the payload is at its destination');
		$this->assertSame(false, file_exists($job['src']), 'and gone from its source');
	}

	// AUD-ESC-04 and -11, the cross-mount path: the payload is copied to a
	// stage, published at the destination, the daemon is pointed at it, and
	// only then is the source quarantined and retired. The publication is
	// checked against the daemon's directory; escaped it never matched, and
	// the worker stopped with 'daemon-directory-mismatch' while the payload
	// lay at both places. The path works on one filesystem too, as it runs
	// here.
	public function testACrossMountMoveLeavesThePayloadOnlyAtItsDestination()
	{
		$job = $this->journaledMove(array('phase' => 'claimed', 'token' => self::TOKEN, 'cross' => true));
		$r = $this->receipt();
		$moved = $this->reconcile(array(array('active|' . $r), array($r), array('active|' . $r), array('1'),
			array($job['daemon_dir']), array('1'), array('1'), array('1'), array($job['dst']), array($job['daemon_dir']),
			array('done|S|' . $r), array('1')), $job, $this->renameHelper());
		$this->assertSame(array('d.stop_close_claim_state', 'd.stop_close_claim', 'd.stop_close_claim_state',
			'd.directory.set_if_stop_close_claim', 'd.get_directory', 'd.start_if_stop_close_claim', 'd.is_open',
			'd.is_active', 'd.get_base_path', 'd.get_directory', 'd.stop_close_claim_state', 'd.ack_stop_close_claim'),
			$this->daemon->calls(), 'published, restarted, retired and acknowledged: '
			. json_encode($this->daemon->calls()));
		$this->assertSame(true, $moved['result'], 'the Move completes: ' . json_encode($moved['result']));
		$this->assertSame('notice-pending', $moved['job']['phase'], 'and waits only for its notice');
		$this->assertSame(array('autotools: move ' . self::HASH . ' physical commit: cross-mount payload at'
			. ' destination; source retired'), $this->logLines(), 'one commit line, no hold: '
			. json_encode($this->logLines(), JSON_UNESCAPED_SLASHES));
		$this->assertSame(array($job['stage'] . ' -> ' . $job['dst'], $job['src'] . ' -> ' . $job['quarantine']),
			$this->renames(), 'the stage is published, then the source quarantined');
		$this->assertSame('payload bytes', @file_get_contents($job['dst']), 'the payload is at its destination');
		$this->assertSame(array(self::PAYLOAD), $this->entries($job['dest_dir']), 'once, without its stage');
		$this->assertSame(array(), $this->entries($job['source_dir']), 'and neither it nor its quarantine is left'
			. ' at the source: ' . json_encode($this->entries($job['source_dir']), JSON_UNESCAPED_SLASHES));
	}

	// The journal the escaping parser left behind (AUD-ESC-04, probe9b): the
	// payload renamed to its destination, d.directory.set_if_stop_close_claim
	// answered 1, then 'daemon-directory-mismatch' -- the job in phase
	// 'moved', the claim active, the download stopped and closed. Its paths
	// come from the finished hook's arguments, never from the parser, so they
	// already are the daemon's bytes, and the first recovery pass after the
	// upgrade completes it: no second rename, the claim acknowledged, the job
	// retired.
	public function testAJournalLeftInMovedByTheEscapingParserCompletesOnTheNextPass()
	{
		$job = $this->journaledMove(array('phase' => 'moved', 'token' => self::TOKEN));
		$job['receipt'] = $this->receiptOf($job['src']);
		rename($job['src'], $job['dst']);
		$this->saveJob($job);
		$r = $this->receipt();
		$pass = $this->drive(array(array('active|' . $r), array($r), array('active|' . $r), array('1'),
			array($job['daemon_dir']), array('1'), array($job['daemon_dir']), array('1'), array('1'), array($job['dst']),
			array('done|S|' . $r), array('1'), array($job['daemon_dir']), array('0')),
			'require_once("./move_tx_lib.php");' . "\n" . $this->renameSeam($this->renameHelper())
			. 'AutoToolsMoveTransaction::recoverAll(); $result = null;' . "\n" . $this->printJournal());
		$this->assertSame(array('d.stop_close_claim_state', 'd.stop_close_claim', 'd.stop_close_claim_state',
			'd.directory.set_if_stop_close_claim', 'd.get_directory', 'd.start_if_stop_close_claim', 'd.get_directory',
			'd.is_open', 'd.is_active', 'd.get_base_path', 'd.stop_close_claim_state', 'd.ack_stop_close_claim',
			'd.get_directory', 'd.set_custom'), $this->daemon->calls(),
			'published again, restarted, acknowledged and its outcome cleared: ' . json_encode($this->daemon->calls()));
		$this->assertSame(array('autotools: move ' . self::HASH . ' physical commit: payload and active daemon at'
			. ' destination', 'autotools: move ' . self::HASH . ' completed: payload published; no .mailto'),
			$this->logLines(), 'the pass completes the Move: ' . json_encode($this->logLines(), JSON_UNESCAPED_SLASHES));
		$this->assertSame(null, $pass['job'], 'and retires its job');
		$this->assertSame(array(), $this->renames(), 'without a second rename');
		$this->assertSame('payload bytes', @file_get_contents($job['dst']), 'the payload stays at its destination');
		$this->assertSame(false, file_exists($job['src']), 'and nothing is back at its source');
	}

	// What the noty() call naming $property shows, evaluated as JavaScript
	// would: its text argument must be a '+' chain of string literals and
	// $property, which stands in as '<name>'. Null when it is not such a
	// chain, as when a quote inside a literal ends it early.
	private static function notyText($script, $property)
	{
		$at = strpos($script, $property);
		$start = $at === false ? false : strrpos(substr($script, 0, $at), 'noty(');
		if ($start === false) return null;
		$length = strlen($script);
		$i = $start + 5;
		$text = '';
		while (true) {
			while ($i < $length && $script[$i] === ' ') $i++;
			if ($i >= $length) return null;
			$quote = $script[$i];
			if ($quote === '"' || $quote === "'") {
				$end = $i + 1;
				while ($end < $length && $script[$end] !== $quote && $script[$end] !== "\n")
					$end += $script[$end] === '\\' ? 2 : 1;
				if ($end >= $length || $script[$end] !== $quote) return null;
				$body = substr($script, $i + 1, $end - $i - 1);
				if ($quote === '"') {
					// A JSON string is a JavaScript string literal.
					$value = json_decode('"' . $body . '"');
					if (!is_string($value)) return null;
				} else {
					$value = '';
					for ($k = 0; $k < strlen($body); $k++) {
						if ($body[$k] !== '\\') {
							$value .= $body[$k];
							continue;
						}
						$k++;
						if ($k >= strlen($body) || strpos('\\\'"', $body[$k]) === false) return null;
						$value .= $body[$k];
					}
				}
				$text .= $value;
				$i = $end + 1;
			} elseif (substr($script, $i, strlen($property)) === $property) {
				$text .= '<' . substr($property, strrpos($property, '.') + 1) . '>';
				$i += strlen($property);
			} else return null;
			while ($i < $length && $script[$i] === ' ') $i++;
			if ($i >= $length) return null;
			if ($script[$i] === ',') return $text;
			if ($script[$i] !== '+') return null;
			$i++;
		}
	}

	// init.php names an unusable session directory in a notice. The path is
	// the daemon's bytes (session.path), and pasted into a single-quoted
	// literal an apostrophe ended the literal and a trailing backslash escaped
	// its closing quote: the whole getplugins script, every plugin's init,
	// failed to parse (AUD-ESC section 4). It is a JSON string literal now,
	// which keeps every byte; the path here holds an apostrophe, double
	// quotes and a trailing backslash.
	public function testInitNamesAnUnusableSessionPathInAStringLiteral()
	{
		$session = $this->base . '/no "such" it\'s\\';
		@mkdir($this->base . '/fin', 0700);
		$answer = $this->drive(new FakeRtorrentMethodReplies(array()),
			'require_once("./autotools.php");' . "\n"
			. '$at = new rAutoTools(); $at->enable_move = 1; $at->fileop_type = "Copy";'
			. ' $at->path_to_finished = ' . var_export($this->base . '/fin', true) . '; $at->store();' . "\n"
			. '$jResult = ""; $plugin = array("name" => "autotools"); $pInfo = array("perms" => 0);'
			. ' $do_diagnostic = true; $theSettings = rTorrentSettings::get();' . "\n"
			. 'require("./init.php");' . "\n"
			. 'echo json_encode(array("script" => $jResult));',
			array('session' => $session));
		$script = isset($answer['script']) ? $answer['script'] : '';
		$this->assertTrue(strpos($script, 'plugin.disable();') !== false, 'the plugin is disabled: ' . $script);
		$this->assertSame('autotools: <webBadSessionWarning> (' . $session . ').',
			self::notyText($script, 'theUILang.webBadSessionWarning'),
			'the notice is a well-formed string expression that shows the session path byte for byte: ' . $script);
		$this->assertSame(false, strpos($script, "it's"), 'no part of the path is pasted into a quoted literal');
	}

	// Runs plugins/autotools/label.php as the insert hook does once it is in
	// the background (`php label.php --daemon <hash> <user>`), with AutoLabel
	// enabled for $template in the user's profile, against a daemon that
	// answers by method name: rTorrent 0.16.25 whose default directory is
	// <base>/dl, and a multi-file download in <base>/dl/Rock "n" Roll\x/Album,
	// plus $replies. Returns the label it set, as the d.set_custom1 value sent.
	private function autoLabel($template, array $replies)
	{
		$root = $this->base . '/dl';
		$directory = $root . '/' . self::LABEL_DIR . '/Album';
		$answers = array();
		foreach (FakeRtorrentAddTorrentReplies::STARTUP as $method => $value)
			$answers[$method] = array($value);
		$answers = array_merge($answers, array('system.client_version' => array('0.16.25'),
			'system.api_version' => array(11), 'get_directory' => array($root), 'directory.default' => array($root),
			'd.get_directory' => array($directory), 'd.directory' => array($directory),
			'd.get_custom3' => array(''), 'd.custom3' => array(''), 'd.is_multi_file' => array(1)), $replies);
		$this->daemon = new FakeRtorrentDaemon(new FakeRtorrentMethodReplies($answers), $this->base . '/calls.log');
		$plugin = $this->repoRoot() . '/plugins/autotools';
		$setup = $this->base . '/label-settings.php';
		file_put_contents($setup, "<?php\n" . '$_SERVER[\'REMOTE_USER\'] = "tester";' . "\n"
			. 'require_once("./autotools.php");' . "\n"
			. '$at = new rAutoTools(); $at->enable_label = 1; $at->label_template = '
				. var_export($template, true) . ";\n"
			. 'exit($at->store() ? 0 : 1);' . "\n");
		$this->assertSame(0, $this->runPhp(array($setup), $plugin), 'AutoLabel is enabled in the profile');
		// No SysV semaphore is left behind by the test: label.php locks only
		// when sem_get() exists.
		$status = $this->runPhp(array('-d', 'disable_functions=sem_get', 'label.php', '--daemon', self::HASH, 'tester'),
			$plugin);
		$this->assertSame(0, $status, 'label.php runs to its end: ' . @file_get_contents($this->base . '/child.out'));
		$labels = array();
		foreach ($this->daemon->bodies() as $body)
			if (preg_match('~<string>d\.(?:custom1\.set|set_custom1)</string>.*?<value><string>' . self::HASH
				. '</string></value>\s*<value><string>([^<]*)</string></value>~s', $body, $match))
				$labels[] = $match[1];
		$this->assertSame(1, count($labels), 'one label is set: ' . json_encode($this->daemon->calls()));
		return count($labels) === 1 ? $labels[0] : null;
	}

	// One PHP child with the environment conf/config.php reads, from $cwd,
	// without a shell; its output is kept for a failure message.
	private function runPhp(array $arguments, $cwd)
	{
		$environment = array('PATH' => (string) getenv('PATH'), 'TMPDIR' => sys_get_temp_dir(),
			'RU_SCGI_HOST' => '127.0.0.1', 'RU_SCGI_PORT' => (string) $this->daemon->port(),
			'RU_PROFILE_PATH' => $this->base . '/profile', 'RU_LOG_FILE' => $this->base . '/errors.log');
		$output = array('file', $this->base . '/child.out', 'a');
		$process = proc_open(array_merge(array(PHP_BINARY), $arguments),
			array(0 => array('file', '/dev/null', 'r'), 1 => $output, 2 => $output), $pipes, $cwd, $environment);
		if (!is_resource($process))
			throw new Exception('cannot start ' . implode(' ', $arguments));
		return proc_close($process);
	}

	// AUD-ESC-07: the automatic label {DIR}, also the template an empty one
	// stands for, is the download's folder relative to the default directory,
	// read with d.get_directory. The parser hands out the daemon's bytes, so
	// the label is the folder's name. While it escaped them the label here
	// became 'Rock \"n\" Roll\\x', custom1 'Rock%20%5C%22n%5C%22%20Roll%5C%5Cx'
	// (probe10 measured that shape on 0.16.25): "add label" then sent a Copy
	// or Link to another folder and automove_filter was matched against that
	// spelling.
	public function testAutoLabelIsTheFolderAsTheDaemonHoldsIt()
	{
		$label = $this->autoLabel('', array());
		$this->assertSame(rawurlencode(self::LABEL_DIR), $label,
			'the label is the folder name, URL-encoded as every label is: ' . var_export($label, true));
	}

	// The same folder with the domain of the first enabled HTTP tracker in
	// group 0, read with t.multicall by types (strings and integers apart).
	public function testAutoLabelJoinsTheFolderAndTheFirstTracker()
	{
		$label = $this->autoLabel('{DIR}/{TRACKER}', array('t.multicall' => array(1, 1, 0,
			'http://bt.tracker.example.org:2710/announce')));
		$this->assertSame(rawurlencode(self::LABEL_DIR . '/tracker.example.org'), $label,
			'the label is the folder and the tracker domain: ' . var_export($label, true));
	}

	// R4 fresh-journal gap: a fresh instance has no .autotools-file-jobs until
	// the first recovery pass, and a finished hook in a session directory that
	// turned unwritable could not even record its deferral. While the journal
	// is missing, setHandlers() has the daemon run one pass at once, as the
	// daemon's user.
	public function testSetHandlersRunsOneRecoveryPassWhileTheJournalIsMissing()
	{
		foreach (array('', '/session', '/dl', '/fin') as $dir)
			@mkdir($this->base . $dir, 0700);
		$body = 'require_once("./autotools.php");' . "\n"
			. '$at = new rAutoTools(); $at->enable_move = 1; $at->fileop_type = "Copy";'
			. ' $at->path_to_finished = ' . var_export($this->base . '/fin', true) . ';' . "\n"
			. 'echo json_encode(array("ok" => $at->setHandlers()));';
		$reply = array(array(0, 0, 0, 0, 0, 0, 0, 0));
		$this->assertSame(array('ok' => true), $this->drive($reply, $body), 'the handlers are set');
		$bodies = implode("\n", $this->daemon->bodies());
		$this->assertTrue(preg_match('~<string>autorecovernowtester</string></value>\s*<value><string>0</string>'
			. '</value>\s*<value><string>0</string></value>\s*<value><string>execute\.nothrow\.bg=\{[^<]*/recover\.php,'
			. 'All,tester\}</string>~', $bodies) === 1, 'one recovery pass is scheduled at once');
		mkdir($this->base . '/session/.autotools-file-jobs', 0700);
		$this->assertSame(array('ok' => true), $this->drive($reply, $body), 'the handlers are set again');
		$this->assertSame(false, strpos(implode("\n", $this->daemon->bodies()), 'autorecovernow'),
			'an existing journal needs no extra pass');
	}
}
