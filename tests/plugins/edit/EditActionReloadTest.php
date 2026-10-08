<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/TorrentSequenceFixtures.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');
require_once(__DIR__ . '/../../../php/Torrent.php');

/**
 * plugins/edit/action.php erases the torrent before it reloads it.
 *
 * Saving an edit is d.erase followed by a load of the edited bytes, with
 * no automatic rollback between them. An unconfirmed erase or reload must
 * retain and name the edited recovery file. The two daemon calls and the
 * surviving bytes have to be watched together to establish that contract.
 *
 * rTorrent::ADDITION_COMMANDS is what made the reload fail. action.php passes
 * d.set_custom3 in its addition and sendTorrent() drops the whole add when one
 * addition is not on that list, so it returned false before building a
 * payload -- after the erase had already gone through.
 *
 * action.php is a top level script that reads php://input, so it is driven
 * where it lives, in a process of its own, with FakeRtorrentDaemon on the
 * other end of its SCGI socket. Everything asserted here is what that daemon
 * was actually asked for. tests/plugins/edit/EditActionSequenceTest.php covers
 * the bencoding the script produces, against a transcription of it; this
 * covers the call sequence, against the script itself.
 */
class EditActionReloadTest extends TestCase
{
	use TorrentSequenceFixtures;

	const HASH = '0123456789ABCDEF0123456789ABCDEF01234567';
	// The AUD-ESC probe name: a quote, a backslash, an apostrophe and an
	// ampersand, as both daemons write it (only & < > become entities).
	const AUD = 'aud "q" \b \'a\' &c';
	// The same name plus the two pairs rXMLRPCRequest::unescapeValue()
	// collapses, so a second unescape of a raw value changes the bytes.
	const AUD_PAIRS = 'aud "q" \b \'a\' &c \\\\x \\"y';

	private $base;
	private $daemon;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-edit-reload-' . getmypid();
		@mkdir($this->base . '/session', 0700, true);
		@mkdir($this->base . '/profile/settings', 0700, true);
	}

	public function tearDown()
	{
		if ($this->daemon !== null) {
			$this->daemon->stop();
			$this->daemon = null;
		}
		$this->removeTree($this->base);
	}

	// scandir, not glob: a fixture directory may carry a backslash, a quote
	// or a bracket in its name, and glob reads those as pattern syntax.
	private function removeTree($path)
	{
		if (is_link($path) || is_file($path)) {
			@unlink($path);
			return;
		}
		if (!is_dir($path)) {
			return;
		}
		foreach (scandir($path) as $entry) {
			if ($entry !== '.' && $entry !== '..') {
				$this->removeTree($path . '/' . $entry);
			}
		}
		@rmdir($path);
	}

	private function repoRoot()
	{
		return realpath(__DIR__ . '/../../..');
	}

	/**
	 * Runs plugins/edit/action.php against the daemon and returns what it
	 * printed. The nine values are the multicall action.php opens with:
	 * get_session, d.is_open, d.is_active, d.get_state, d.get_tied_to_file,
	 * d.get_custom1, d.get_directory_base, d.get_connection_seed and
	 * d.get_complete. d.get_complete is 0 so the reload does not take the
	 * fast resume branch, and the directory is empty so the add does not need
	 * a directory to be approved.
	 */
	private function saveAnEdit($post, $options = array())
	{
		$root = $this->repoRoot();
		if ($this->daemon !== null) {
			$this->daemon->stop();
		}
		// The daemon owns the session copy; edit owns a separate recovery file.
		// Each run starts with a fresh session snapshot.
		// 'session' names another session directory, 'noSessionCopy' leaves
		// it without the daemon's copy, so the tied file is the only source.
		$session = $options['session'] ?? ($this->base . '/session/');
		@mkdir($session, 0700, true);
		if (empty($options['noSessionCopy']))
			file_put_contents($session . self::HASH . '.torrent', $this->announceOnlyTorrent());
		if (!empty($options['uploadsFile'])) file_put_contents($this->base.'/profile/torrents', 'operator-file');
		if (!empty($options['uploadsSymlink'])) {
			mkdir($this->base.'/saved-uploads',0700);
			symlink($this->base.'/saved-uploads',$this->base.'/profile/torrents');
		}
		$directory = $options['directory'] ?? '';
		$tied = $options['tied'] ?? (!empty($options['guarded']) ? $this->base.'/user-source.torrent' : '');
		if($tied !== '') file_put_contents($tied, $this->announceOnlyTorrent());
		$values = array($session, 1, 1, 1, $tied, $options['label'] ?? '', $directory,
			$options['seed'] ?? 'seed', 0);
		if(!empty($options['throttle'])) $values[] = $options['throttleName'] ?? 'slowpool';
		if(!empty($options['guarded'])) $values[] = str_repeat('a', 32).':0000000000000001';
		$options['tied'] = $tied;
		$replies = array(
			$values,
			array(0), // d.erase
			array(0), // load
			array('1'), // load proof key
		);
		if (!empty($options['removeSession']) || isset($options['erase']) ||
			!empty($options['pending']) || !empty($options['readOnlyRecovery']) || !empty($options['guarded'])) {
			$source = $session.self::HASH.'.torrent';
			$options['uploads'] = $this->base.'/profile/torrents';
			$replies = new FakeRtorrentEditReloadReplies($source, $values, $options);
		}
		$this->daemon = new FakeRtorrentDaemon($replies, $this->base . '/calls.log');

		$driver = $this->base . '/drive-edit.php';
		file_put_contents($driver, "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->base . '/profile', true) . ";\n"
			. 'require_once(' . var_export($root . '/conf/config.php', true) . ");\n"
			. 'require_once(' . var_export($root . '/php/settings.php', true) . ');' . "\n"
			. '$r = new ReflectionClass("rTorrentSettings"); $s = $r->newInstanceWithoutConstructor();'
			. ' $s->iVersion = '.(!empty($options['guarded']) ? '0x1019' : '0x1018').'; $s->aliases = array(); $s->directory = "/";'
			. (!empty($options['throttle']) ? ' $s->plugins["throttle"] = true;' : '')
			. ' $s->linkExist = true; $p = $r->getProperty("theSettings");'
			. ' if(PHP_VERSION_ID < 80100) $p->setAccessible(true); $p->setValue(null, $s);' . "\n"
			. '$scgi_host = "127.0.0.1";' . "\n"
			. '$scgi_port = ' . $this->daemon->port() . ";\n"
			. '$rpcTimeOut = 10;' . "\n"
			. '$rpcLogCalls = false;' . "\n"
			. '$log_file = '.var_export($this->base.'/application.log', true).';' . "\n"
			. '$profileMask = '.var_export($options['profileMask'] ?? 0770, true).';' . "\n"
			. '$saveUploadedTorrents = false;' . "\n"
			. '$topDirectory = '.var_export($options['topDirectory'] ?? '/', true).';' . "\n"
			. '$localhosts = '.var_export(!empty($options['remote']) ? array('remote.test') : array('127.0.0.1'), true).';' . "\n"
			. '$HTTP_RAW_POST_DATA = ' . var_export($post, true) . ";\n"
			. 'chdir(' . var_export($root . '/plugins/edit', true) . ");\n"
			. 'require(' . var_export($root . '/plugins/edit/action.php', true) . ");\n");

		$output = array();
		exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 ' .
			escapeshellarg($driver) . ' 2>&1', $output);
		return implode("\n", $output);
	}

	private function loadedTorrent()
	{
		foreach ($this->daemon->bodies() as $body) {
			$xml = @simplexml_load_string($body);
			if ($xml === false) continue;
			foreach ($xml->xpath('//base64') as $value)
				return Torrent::fromRawBytes(base64_decode((string)$value, true));
		}
		return null;
	}

	public function testRawEqualsAndMalformedTrailingFieldKeepTrackerAndComment()
	{
		$url = 'https://tracker.test/announce?pass=a=b%2Bc';
		$post = 'hash=' . self::HASH . '&set_trackers=1&tracker='
			. str_replace('%3D', '=', rawurlencode($url))
			. '&set_comment=1&comment=left=right&comment';
		$printed = $this->saveAnEdit($post);
		$torrent = $this->loadedTorrent();
		$this->assertTrue($torrent instanceof Torrent, 'the edit reloads: ' . $printed);
		if (!($torrent instanceof Torrent)) return;
		$this->assertSame($url, $torrent->announce(), 'the full tracker URL reaches the reload');
		$this->assertSame('left=right', $torrent->comment(), 'the malformed field does not erase the valid comment');
	}

	/** XMLRPC text as rXMLRPCParam writes a string: & < > only. */
	private static function xmlText($value)
	{
		return htmlspecialchars($value, ENT_NOQUOTES, 'UTF-8');
	}

	/** rtorrent's quoted-argument syntax: backslash first, then the quote. */
	private static function quotedArg($value)
	{
		return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $value) . '"';
	}

	private function loadBody()
	{
		foreach ($this->daemon->bodies() as $body)
			if (preg_match('/<(?:methodName|string)>load[._]/', $body) === 1)
				return $body;
		return '';
	}

	/**
	 * AUD-ESC-05/edit: the snapshot is read in the daemon's own bytes, so the
	 * reload must forward it unchanged. Unescaping it once more turned the
	 * pairs \\ and \" into \ and ", and the reload went to another
	 * directory, label, throttle and tied path than the one it replaced.
	 */
	public function testAudSnapshotReachesTheGuardedReloadInTheDaemonsOwnBytes()
	{
		@mkdir($this->base . '/watch', 0700, true);
		$tied = $this->base . '/watch/' . self::AUD_PAIRS . '.torrent';
		$directory = $this->base . '/dl/' . self::AUD_PAIRS;
		$throttle = 'thr ' . self::AUD_PAIRS;
		$printed = $this->saveAnEdit('hash=' . self::HASH . '&set_comment=1&comment=aud-edit',
			array('guarded' => true, 'throttle' => true, 'tied' => $tied,
				'directory' => $directory, 'label' => self::AUD_PAIRS, 'throttleName' => $throttle));
		$this->assertSame(array(), json_decode($printed, true)['errors'] ?? null,
			'the edit reloads: ' . $printed);
		$erase = '';
		foreach ($this->daemon->bodies() as $body)
			if (strpos($body, '<methodName>d.erase_preserving_tied</methodName>') !== false)
				$erase = $body;
		$this->assertTrue(strpos($erase, '<string>' . self::xmlText($tied) . '</string>') !== false,
			'the guarded erase binds the tied path the daemon reported, byte for byte');
		$load = $this->loadBody();
		$this->assertTrue(strpos($load, self::xmlText('d.set_directory_base=' . self::quotedArg($directory))) !== false,
			'the reload keeps the reported directory, quoted once');
		$this->assertTrue(strpos($load, 'd.set_custom1=' . rawurlencode(self::AUD_PAIRS)) !== false,
			'the reload keeps the reported label');
		$this->assertTrue(strpos($load, self::xmlText('d.set_throttle_name=' . self::quotedArg($throttle))) !== false,
			'the reload keeps the reported throttle name, quoted once');
		$this->assertSame($this->announceOnlyTorrent(), @file_get_contents($tied),
			'the user-owned tied file is untouched');
	}

	/**
	 * AUD-ESC-12/edit: session.path is read in the daemon's own bytes, so a
	 * session directory with a quote or a backslash still finds the copy.
	 */
	public function testAudSessionDirectoryStillFindsTheDaemonsCopy()
	{
		$session = $this->base . '/' . self::AUD_PAIRS . '/';
		$printed = $this->saveAnEdit('hash=' . self::HASH . '&set_comment=1&comment=from-session',
			array('session' => $session));
		$this->assertTrue(strpos($printed, 'cantFindTorrent') === false,
			'the session copy is found: ' . $printed);
		$torrent = $this->loadedTorrent();
		$this->assertTrue($torrent instanceof Torrent && $torrent->comment() === 'from-session',
			'the edited session copy is reloaded: ' . $printed);
	}

	/**
	 * AUD-ESC-10: without a session copy the tied file is the source, and its
	 * path is read in the daemon's own bytes.
	 */
	public function testAudTiedFileIsTheSourceWithoutASessionCopy()
	{
		@mkdir($this->base . '/watch', 0700, true);
		$tied = $this->base . '/watch/' . self::AUD . '.torrent';
		$printed = $this->saveAnEdit('hash=' . self::HASH . '&set_comment=1&comment=from-tied',
			array('noSessionCopy' => true, 'tied' => $tied));
		$this->assertTrue(strpos($printed, 'cantFindTorrent') === false,
			'the tied file is found: ' . $printed);
		$torrent = $this->loadedTorrent();
		$this->assertTrue($torrent instanceof Torrent && $torrent->comment() === 'from-tied',
			'the edited tied file is reloaded: ' . $printed);
	}

	/** The index of the first call to $method, or -1. */
	private function indexOf($method)
	{
		$calls = $this->daemon->calls();
		$at = array_search($method, $calls, true);
		return $at === false ? -1 : $at;
	}

	/** The load command, whatever the daemon in front of it spells it. */
	private function loadCallIndex()
	{
		foreach ($this->daemon->calls() as $index => $call) {
			if (strpos($call, 'load') === 0 || strpos($call, 'load.') === 0) {
				return $index;
			}
		}
		return -1;
	}

	/**
	 * The whole point: the torrent the script erased is loaded again in the
	 * same run. Before the addition allowlist knew about d.set_custom3 the
	 * erase went through and the load never did.
	 */
	public function testAnEditedTorrentIsReloadedAfterItIsErased()
	{
		$printed = $this->saveAnEdit('hash=' . self::HASH . '&set_comment=1&comment='
			. rawurlencode('an edited comment'));

		$erase = $this->indexOf('d.erase');
		$load = $this->loadCallIndex();

		$this->assertTrue($erase >= 0,
			'the script erases the torrent before reloading it; calls: '
			. implode(', ', $this->daemon->calls()));
		$this->assertTrue($load >= 0,
			'and loads it again -- a run that erases and does not load has lost the '
			. 'download; calls: ' . implode(', ', $this->daemon->calls()));
		$this->assertTrue($erase < $load,
			'the reload follows erase after preflight and recovery preparation');
		$this->assertTrue(strpos($printed, 'errorAddTorrent') === false,
			'and the script reports no add error: ' . $printed);
		$this->assertTrue(strpos($printed, self::HASH) !== false,
			'the saved hash comes back in the response: ' . $printed);
	}

	/**
	 * The same run seen from the other side: the reload carries the commands
	 * action.php builds as its addition, so they reached the daemon rather
	 * than stopping the add.
	 */
	public function testTheReloadCarriesTheEditPluginsAddition()
	{
		$this->saveAnEdit('hash=' . self::HASH . '&set_private=1&private=1');

		$this->assertTrue($this->loadCallIndex() >= 0,
			'a private-flag edit reloads too; calls: ' . implode(', ', $this->daemon->calls()));
	}

	/**
	 * The throttle plugin is not registered in this fixture, so action.php
	 * asks for nine values and never reads a throttle name. It used to put the
	 * unset throttle into the addition list anyway, and a non-string entry
	 * refuses the whole add.
	 */
	public function testTheReloadHappensWithNoThrottlePluginRegistered()
	{
		$this->saveAnEdit('hash=' . self::HASH . '&set_comment=1&comment=x');

		$calls = $this->daemon->calls();
		$this->assertTrue(!in_array('d.get_throttle_name', $calls, true),
			'the fixture has no throttle plugin, so no throttle name is read');
		$this->assertTrue($this->loadCallIndex() >= 0,
			'and the reload still happens; calls: ' . implode(', ', $calls));
	}
	public function testOutsideTopDirectoryRefusesBeforeErase()
	{
		$printed = $this->saveAnEdit('hash='.self::HASH.'&set_comment=1&comment=changed',
			array('directory'=>'/outside/download', 'topDirectory'=>'/allowed'));
		$this->assertSame(-1, $this->indexOf('d.erase'), 'an invalid directory preserves the live download');
		$this->assertSame(-1, $this->loadCallIndex(), 'the refused edit dispatches no reload');
		$this->assertTrue(is_file($this->base.'/session/'.self::HASH.'.torrent'), 'the source torrent remains');
		$this->assertTrue(strpos($printed, 'addTorrentFailedDirectory') !== false,
			'the response identifies the download directory refusal');
	}

	public function testRemoteLargeTorrentRefusesBeforeErase()
	{
		$printed = $this->saveAnEdit('hash='.self::HASH.'&set_comment=1&comment='.str_repeat('x', 1300000),
			array('remote'=>true));
		$this->assertSame(-1, $this->indexOf('d.erase'), 'a remote load above the raw packet limit preserves the live download');
		$this->assertSame(-1, $this->loadCallIndex(), 'the remote filesystem refusal dispatches no load');
		$this->assertTrue(strpos($printed, 'editPreflightFailed') !== false, 'the remote refusal is reported before erase');
	}

	public function testFailedDispatchReturnsAnExistingEditedRecoveryFileAfterSessionRemoval()
	{
		$printed = $this->saveAnEdit('hash='.self::HASH.'&set_comment=1&comment=edited-recovery',
			array('removeSession'=>true));
		$this->assertTrue($this->indexOf('d.erase') >= 0 && $this->loadCallIndex() >= 0,
			'the fixture erases and then refuses the actual load RPC');
		$this->assertTrue(!is_file($this->base.'/session/'.self::HASH.'.torrent'), 'the daemon removes the old session file');
		$result = json_decode($printed, true);
		$path = $result['errors'][0]['prm'] ?? '';
		$this->assertTrue(strpos($path, $this->base.'/profile/torrents/') === 0 && is_file($path),
			'the error names a surviving recovery file in the profile uploads directory');
		if (!is_file($path)) return;
		$torrent = new Torrent($path);
		$this->assertSame('edited-recovery', $torrent->comment(), 'the retained torrent contains the requested edit');
		$this->assertSame(0660, fileperms($path) & 0777, 'recovery bytes use the shared profile mask');
		$this->assertSame(0770, fileperms(dirname($path)) & 0777, 'the daemon can traverse the profile uploads directory');
	}

	public function testLostEraseReplyRetainsAndNamesTheEditedRecoveryFile()
	{
		$printed = $this->saveAnEdit('hash='.self::HASH.'&set_comment=1&comment=lost-reply-edit',
			array('erase'=>'lost'));
		$result = json_decode($printed, true);
		$path = $result['errors'][0]['prm'] ?? '';
		$this->assertSame('theUILang.editEraseUnknown', $result['errors'][0]['desc'] ?? '', 'a lost reply has an unknown erase outcome');
		$this->assertTrue(!is_file($this->base.'/session/'.self::HASH.'.torrent'), 'erase applied before the reply was dropped');
		$this->assertSame(-1, $this->loadCallIndex(), 'an unknown erase outcome dispatches no speculative reload');
		$this->assertTrue(is_file($path), 'the response names the surviving edited recovery file');
		if(!is_file($path)) return;
		$this->assertSame('lost-reply-edit', (new Torrent($path))->comment(), 'the retained bytes contain the requested edit');
		$this->assertTrue($path !== '' && strpos(file_get_contents($this->base.'/application.log'), $path) !== false, 'the recovery path is visible in the application log');
	}

	public function testEraseFaultRetainsTheRecoveryAndReportsAClassifiedRefusal()
	{
		$printed = $this->saveAnEdit('hash='.self::HASH.'&set_comment=1&comment=refused-edit',
			array('erase'=>'fault'));
		$result = json_decode($printed, true);
		$this->assertSame('theUILang.editEraseFailed', $result['errors'][0]['desc'] ?? '', 'a daemon fault is distinguished from a lost reply');
		$this->assertTrue(is_file($result['errors'][0]['prm'] ?? ''), 'even a daemon fault retains the only edited copy');
		$this->assertTrue(is_file($this->base.'/session/'.self::HASH.'.torrent'), 'the refusing daemon preserves its session snapshot');
		$this->assertSame(-1, $this->loadCallIndex(), 'the refused erase dispatches no load');
	}

	public function testPendingReloadNamesTheSurvivingRecoveryFile()
	{
		$printed = $this->saveAnEdit('hash='.self::HASH.'&set_private=1&private=1', array('pending'=>true));
		$result = json_decode($printed, true);
		$path = $result['errors'][0]['prm'] ?? '';
		$this->assertSame(true, $result['pending'] ?? null, 'the response retains the pending outcome');
		$this->assertSame('theUILang.editReloadPending', $result['errors'][0]['desc'] ?? '', 'the pending notice names its recovery purpose');
		$this->assertTrue(is_file($path), 'the pending notice includes an existing recovery file');
		if(is_file($path)) $this->assertTrue((new Torrent($path))->is_private(), 'the recovery contains the changed info dictionary');
		$this->assertTrue($path !== '' && strpos(file_get_contents($this->base.'/application.log'), $path) !== false, 'the pending recovery path is logged');
	}

	public function testLargePathReloadNeverRewritesTheSavedRecoveryAfterErase()
	{
		$comment = str_repeat('x', 1300000);
		$printed = $this->saveAnEdit('hash='.self::HASH.'&set_comment=1&comment='.$comment,
			array('readOnlyRecovery'=>true));
		$this->assertTrue($this->loadCallIndex() >= 0, 'a read-only saved recovery is loaded by path without another write: '.$printed);
		$result = json_decode($printed, true);
		$this->assertSame(array(), $result['errors'] ?? null, 'loading the existing recovery needs no writable source');
		$paths = glob($this->base.'/profile/torrents/edited-*.torrent');
		$this->assertSame(1, count($paths), 'a path load retains the tied recovery file');
		if(count($paths) !== 1) return;
		$this->assertSame(0440, fileperms($paths[0]) & 0777, 'the reload does not replace or chmod the saved recovery');
		$this->assertSame($comment, (new Torrent($paths[0]))->comment(), 'the complete saved bytes survive the reload');
	}

	public function testRecoveryWriteFailureNamesTheUploadsDirectory()
	{
		$printed = $this->saveAnEdit('hash='.self::HASH.'&set_comment=1&comment=changed', array('uploadsFile'=>true));
		$result = json_decode($printed, true);
		$this->assertSame($this->base.'/profile/torrents', $result['errors'][0]['prm'] ?? '', 'the write refusal names the actual recovery destination');
	}

	public function testFailedRecoveryWriteRefusesBeforeErase()
	{
		$printed = $this->saveAnEdit('hash='.self::HASH.'&set_comment=1&comment=changed',
			array('uploadsFile'=>true));
		$this->assertSame(-1,$this->indexOf('d.erase'),'a recovery path that cannot be written preserves the live download');
		$this->assertSame('operator-file',file_get_contents($this->base.'/profile/torrents'),'the operator file is unchanged');
		$this->assertTrue(strpos($printed,'errorWriteTorrent') !== false,'the response identifies the recovery write refusal');
	}

	public function testSymlinkedProfileUploadsStillReloads()
	{
		$printed = $this->saveAnEdit('hash='.self::HASH.'&set_comment=1&comment=changed',
			array('uploadsSymlink'=>true));
		$this->assertTrue($this->indexOf('d.erase') >= 0 && $this->loadCallIndex() >= 0,
			'a valid symlinked profile upload directory accepts edit reload');
		$this->assertTrue(strpos($printed,'errorWriteTorrent') === false,'canonicalizing the profile path permits its recovery file');
	}

	public function testGuardedEditPreservesUserTiedSourceAndForwardsTheSnapshot()
	{
		$printed = $this->saveAnEdit('hash='.self::HASH.'&set_comment=1&comment=owned-edit',
			array('guarded'=>true));
		$result = json_decode($printed, true);
		$this->assertSame(array(), $result['errors'] ?? null, 'the guarded edit reload succeeds: '.$printed);
		$this->assertSame(-1, $this->indexOf('d.erase'), 'the current daemon never receives an unguarded erase');
		$this->assertTrue($this->indexOf('d.erase_preserving_tied') >= 0, 'the atomic source-preserving erase is dispatched');
		$path = $this->base.'/user-source.torrent';
		$this->assertSame($this->announceOnlyTorrent(), @file_get_contents($path), 'the user owns the unchanged original tied file');
		foreach($this->daemon->bodies() as $body)
		{
			if(preg_match('/<(?:methodName|string)>load[._]/', $body) === 1)
				$this->assertTrue(strpos($body, 'd.set_throttle_name=') === false, 'a Stop token is never mistaken for an absent throttle');
			if(strpos($body, '<methodName>d.erase_preserving_tied</methodName>') !== false)
			{
				$this->assertTrue(strpos($body, $path) !== false, 'erase binds to the observed tied path');
				$this->assertTrue(strpos($body, str_repeat('a',32).':0000000000000001') !== false,
					'erase binds to the observed torrent identity and Stop generation');
				$this->assertSame(3, substr_count($body, '<i4>1</i4>'), 'state, open and active are guarded integer values');
			}
		}
	}

	public function testGuardedEditLostReplyKeepsUserSourceAndEditedRecovery()
	{
		$printed = $this->saveAnEdit('hash='.self::HASH.'&set_comment=1&comment=lost-guarded',
			array('guarded'=>true, 'erase'=>'lost'));
		$result = json_decode($printed, true);
		$this->assertSame('theUILang.editEraseUnknown', $result['errors'][0]['desc'] ?? '', 'the erased outcome remains unknown');
		$this->assertTrue(is_file($result['errors'][0]['prm'] ?? ''), 'the edited recovery survives a lost erase reply');
		$this->assertSame($this->announceOnlyTorrent(), @file_get_contents($this->base.'/user-source.torrent'), 'the source survives even when erase applied');
		$this->assertSame(-1, $this->loadCallIndex(), 'a lost erase reply never causes a speculative reload');
	}

	public function testGuardedEditRefusalDoesNotReloadOrDeleteTheUserSource()
	{
		$printed = $this->saveAnEdit('hash='.self::HASH.'&set_private=1&private=1',
			array('guarded'=>true, 'erase'=>'fault'));
		$result = json_decode($printed, true);
		$this->assertSame('theUILang.editEraseFailed', $result['errors'][0]['desc'] ?? '', 'stale generation or tied-path refusal stays visible');
		$this->assertTrue(is_file($result['errors'][0]['prm'] ?? ''), 'refused erase retains the changed-info recovery');
		$this->assertSame(-1, $this->loadCallIndex(), 'refused erase never dispatches load');
		$this->assertSame($this->announceOnlyTorrent(), @file_get_contents($this->base.'/user-source.torrent'), 'refused erase preserves the user source');
	}

	public function testCurrentDaemonWithoutTheGuardedAbiRefusesBeforeAnyErase()
	{
		$printed = $this->saveAnEdit('hash='.self::HASH.'&set_comment=1&comment=unsupported',
			array('guarded'=>true, 'unsupported'=>true));
		$result = json_decode($printed, true);
		$this->assertSame('theUILang.editPreflightFailed', $result['errors'][0]['desc'] ?? '', 'missing native ABI has a visible refusal');
		$this->assertSame(-1, $this->indexOf('d.erase'), 'missing support never falls back to destructive erase');
		$this->assertSame(-1, $this->indexOf('d.erase_preserving_tied'), 'missing support dispatches no erase');
		$this->assertSame(-1, $this->loadCallIndex(), 'missing support dispatches no load');
		$this->assertTrue(is_file($this->base.'/session/'.self::HASH.'.torrent'), 'missing support leaves the live session intact');
		$this->assertTrue(strpos(@file_get_contents($this->base.'/application.log'), 'preserving-erase-unsupported') !== false,
			'the operator receives a classified recovery reason');
	}

	public function testGuardedSnapshotWithThrottleKeepsTheStopTokenAndThrottleSeparate()
	{
		$printed = $this->saveAnEdit('hash='.self::HASH.'&set_comment=1&comment=throttled-edit',
			array('guarded'=>true, 'throttle'=>true));
		$this->assertSame(array(), json_decode($printed, true)['errors'] ?? null, 'the guarded throttled edit reloads');
		$loadSeen = false;
		foreach($this->daemon->bodies() as $body)
		{
			if(strpos($body, '<methodName>d.erase_preserving_tied</methodName>') !== false)
				$this->assertTrue(strpos($body, str_repeat('a',32).':0000000000000001') !== false,
					'the Stop token remains at its own snapshot slot when throttle is present');
			if(preg_match('/<(?:methodName|string)>load[._]/', $body) === 1)
			{
				$loadSeen = true;
				$this->assertTrue(strpos($body, 'slowpool') !== false, 'the actual throttle reaches reload');
				$this->assertTrue(strpos($body, str_repeat('a',32)) === false, 'the Stop token never becomes a throttle name');
			}
		}
		$this->assertTrue($loadSeen, 'the load transcript was inspected rather than skipped');
	}

}
