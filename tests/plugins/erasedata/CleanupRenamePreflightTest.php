<?php

require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/CollectorFixture.php');
require_once(__DIR__.'/../../../plugins/erasedata/removewithdata.php');
require_once(__DIR__.'/CleanupArmReasonFixture.php');

// The missing parent is rejected before any helper process is started.
class ErasedataMissingParentProbe extends ErasedataFilesystemOps
{
	public static function renameHelperPath() { return PHP_BINARY; }
}

class ErasedataCleanupProbeFixture extends ErasedataCollectorFixture
{
	public $parents = array();
	public $refused = null;

	public function __construct($refused = null)
	{
		parent::__construct(array());
		$this->refused = $refused;
	}

	protected function renameCapability($directory)
	{
		$this->parents[] = $directory;
		if($directory !== $this->refused) return(true);
		ErasedataMissingParentProbe::canRenameNoReplace($directory.'/missing-probe-parent', false);
		return(false);
	}
}

class CleanupRenamePreflightTest extends TestCase
{
	private $dir;
	private $queue;
	private $hash;
	private $payload;

	public function setUp()
	{
		$this->dir = sys_get_temp_dir().'/cleanup-preflight-'.getmypid().'-'.uniqid();
		mkdir($this->dir, 0700);
		mkdir($this->dir.'/data', 0700);
		$this->queue = $this->dir.'/erasedata';
		mkdir($this->queue, 0700);
		$this->hash = str_repeat('A', 40);
		$this->payload = $this->dir.'/data/payload';
		file_put_contents($this->payload, 'payload bytes');
		FileUtil::$settingsPath = $this->dir;
		FileUtil::$log = array();
		ErasedataCollectorTestState::$source = false;
		ErasedataCollectorTestState::$fleetSources = array();
		rXMLRPCRequest::$erased = array();
		rXMLRPCRequest::$requested = array();
		rXMLRPCRequest::$responses = array(
			'system.client_version' => array('ok' => true, 'val' => array('0.16.25')),
			'd.hash' => array('ok' => true, 'val' => array($this->hash)),
			'd.get_base_path' => array('ok' => true, 'val' => array($this->payload, 0, $this->payload)),
			'd.set_custom5' => array('ok' => true, 'val' => array('', '', '')),
			'schedule.if_absent' => array('ok' => true, 'val' => array(0)),
			'schedule' => array('ok' => true, 'val' => array(0), 'callback' => function() {
				$state = erasedataReadDrainState($this->queue);
				$state['acknowledged'] = $state['generation'];
				erasedataWriteDrainState($this->queue, $state);
			}),
		);
	}

	public function tearDown()
	{
		$this->remove($this->dir);
	}

	private function remove($path)
	{
		if(is_dir($path) && !is_link($path))
		{
			foreach(array_diff(scandir($path), array('.', '..')) as $entry)
				$this->remove($path.'/'.$entry);
			rmdir($path);
		}
		else unlink($path);
	}

	public function testFallbackProbeStatusIsUsableOnlyForAProbe()
	{
		$helper = $this->dir.'/fallback-helper';
		file_put_contents($helper, "#!/bin/sh\nexit 5\n");
		chmod($helper, 0700);
		$method = new ReflectionMethod('ErasedataFilesystemOps', 'runRenameHelper');
		if(PHP_VERSION_ID < 80100) $method->setAccessible(true);
		$this->assertTrue($method->invoke(null, array($helper, '--probe', $this->dir), $this->dir),
			'the distinct fallback probe status admits a usable filesystem');
		$this->assertTrue(!$method->invoke(null, array($helper, $this->payload, $this->dir.'/target'),
			$this->payload, $this->dir.'/target'), 'a fallback probe status cannot attest a rename');
	}

	private function prepareJob($filesystem)
	{
		$stat = lstat($this->payload);
		$identity = array('canonical' => realpath($this->payload),
			'lstat' => array('dev' => $stat['dev'], 'ino' => $stat['ino']),
			'stat' => array('dev' => $stat['dev'], 'ino' => $stat['ino']),
			'size' => $stat['size'], 'mtime' => $stat['mtime']);
		return(erasedataPrepareObsoleteCleanup($this->hash, str_repeat('B', 40),
			str_repeat('a', 32), $this->hash.'-started-1787587200', dirname($this->payload),
			array(array('path' => $this->payload, 'identity' => $identity)), $filesystem));
	}

	public function testCleanupPrepareProbesQueueAndPayloadBeforeStaging()
	{
		$filesystem = new ErasedataCleanupProbeFixture(dirname($this->payload));
		$job = $this->prepareJob($filesystem);
		$this->assertEquals(false, $job, 'unsupported cleanup parents refuse before predecessor erase');
		if(is_array($job)) erasedataCancelObsoleteCleanup($job, $filesystem);
		$this->assertTrue(in_array($this->queue, $filesystem->parents, true)
			&& in_array(dirname($this->payload), $filesystem->parents, true),
			'cleanup uses the same queue and payload parent probes as HTTP admission');
		$this->assertEquals(array(), glob($this->queue.'/*.tmp'), 'refused cleanup stages no obligation');
		$this->assertEquals(array(), rXMLRPCRequest::$erased, 'cleanup preparation never erases a download');
		$this->assertEquals('payload bytes', file_get_contents($this->payload), 'cleanup refusal preserves bytes');
		$filesystem->refused = null;
		$job = $this->prepareJob($filesystem);
		$this->assertTrue(is_array($job), 'a usable cleanup parent can prepare on the next attempt');
		if(is_array($job)) erasedataCancelObsoleteCleanup($job, $filesystem);

	}

	public function testCleanupArmReprobesBeforeReturningEraseAuthority()
	{
		$filesystem = new ErasedataCleanupProbeFixture();
		$job = $this->prepareJob($filesystem);
		$this->assertTrue(is_array($job), 'usable parents prepare the exact cleanup job');
		if(!is_array($job)) return;
		try
		{
			$child = null;
			$script = 'require '.var_export(__FILE__, true).'; FileUtil::$settingsPath='
				.var_export($this->dir, true).'; usleep(10000); '
				.'exit(is_array(erasedataAcknowledgeDrainGeneration('.var_export($this->queue, true)
				.', '.var_export(User::getUser(), true).', array("FileUtil","toLog"))) ? 0 : 1);';
			rXMLRPCRequest::$responses['schedule']['callback'] = function() use (&$child, $script, $filesystem) {
				$child = ErasedataTestProcess::start('exec '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));
				$filesystem->parents = array();
				$filesystem->refused = dirname($this->payload);
			};
			$reason = 'stale';
			$this->assertTrue(!erasedataArmObsoleteCleanupRun(array('listPath' => $this->queue,
				'user' => User::getUser(), 'filesystem' => $filesystem, 'log' => array('FileUtil', 'toLog'),
				'ackTimeout' => 0.5, 'ackPoll' => 0.01), $job, $reason),
				'cleanup arm refuses a changed parent before predecessor erase');
			$this->assertEquals('cleanup-rename-unconfirmed', $reason, 'an unresolved parent does not establish a lasting capability failure');
			$this->assertTrue(in_array(dirname($this->payload), $filesystem->parents, true),
				'acknowledgement does not reuse an earlier successful parent probe');
			$this->assertTrue(is_file($job['tmp_path']), 'late refusal preserves the exact prepared job for cancellation');
			$this->assertEquals(array(), rXMLRPCRequest::$erased, 'late cleanup refusal never erases its predecessor');
		}
		finally
		{
			$this->assertTrue($child instanceof ErasedataTestProcess && $child->wait(2)
				&& $child->reap() === 0, 'the real acknowledgement child finishes cleanly');
			erasedataCancelObsoleteCleanup($job, $filesystem);
		}
	}

	public function testEveryCleanupArmOutcomeSuppliesItsOwnClassifiedReason()
	{
		foreach(array('job' => 'cleanup-arm-job', 'lock' => 'cleanup-arm-lock',
			'state' => 'cleanup-arm-state', 'generation' => 'cleanup-arm-generation',
			'write' => 'cleanup-arm-write', 'rearm' => 'cleanup-arm-rearm',
			'no-ack' => 'cleanup-drain-no-ack', 'changed' => 'cleanup-arm-changed',
			'rename' => 'cleanup-rename-capability', 'success' => null) as $failure => $expected)
		{
			\ErasedataCleanupArmFixture\Scenario::reset($failure);
			$lock = \ErasedataCleanupArmFixture\Scenario::$lock;
			$reason = 'stale';
			try
			{
				$result = \ErasedataCleanupArmFixture\erasedataArmObsoleteCleanupRun(array(
					'listPath' => $this->queue, 'user' => User::getUser(), 'ackTimeout' => 0, 'ackPoll' => 0),
					array('tmp_path' => $this->queue.'/prepared.tmp', 'list_path' => $this->queue.'/prepared.list',
						'lock' => $lock), $reason);
				$this->assertEquals($failure === 'success', $result, 'the bool arm API remains exact: '.$failure);
				$this->assertEquals($expected, $reason, 'the output reason names the actual refusal: '.$failure);
				$this->assertEquals($expected === null ? array() : array($expected),
					\ErasedataCleanupArmFixture\Scenario::$diagnostics, 'diagnostic and consumer share one classified reason');
				$this->assertEquals(in_array($failure, array('job', 'lock'), true) ? 0 : 1,
					\ErasedataCleanupArmFixture\Scenario::$releases, 'every acquired state lock is released exactly once');
			}
			finally { fclose($lock); }
		}
	}

	public function testCleanupArmDistinguishesKnownCapabilityRefusalsFromUnconfirmedProbes()
	{
		foreach(array('rename-noreplace-unsupported', 'rename-noreplace-manifest-unavailable',
			'rename-noreplace-manifest-invalid', 'rename-noreplace-helper-unavailable',
			'rename-noreplace-process-unavailable', 'rename-noreplace-process-exit-127',
			'rename-noreplace-lease-unavailable', 'rename-noreplace-descriptor-unavailable',
			'rename-noreplace-parent-unresolved', 'rename-noreplace-error', '') as $index => $rawReason)
		{
			\ErasedataCleanupArmFixture\Scenario::reset('rename');
			\ErasedataCleanupArmFixture\Scenario::$renameReason = $rawReason;
			$reason = null;
			try {
				$job = array('tmp_path' => $this->queue.'/job.tmp', 'list_path' => $this->queue.'/job.list',
					'lock' => \ErasedataCleanupArmFixture\Scenario::$lock);
				$this->assertEquals(false, \ErasedataCleanupArmFixture\erasedataArmObsoleteCleanupRun(array(
					'listPath' => $this->queue, 'user' => 'fixture-user'), $job, $reason),
					'every failed probe retains its boolean refusal');
				$this->assertEquals($index < 4 ? 'cleanup-rename-capability' : 'cleanup-rename-unconfirmed',
					$reason, 'only a known local capability or helper configuration refusal buys a hold: '.$rawReason);
			}
			finally { fclose(\ErasedataCleanupArmFixture\Scenario::$lock); }
		}
	}

	public function testLateAdmissionRefusalRemainsRefusedByDrainAndRetriesAfterRepair()
	{
		$next = $this->dir.'/next-data';
		mkdir($next, 0700);
		file_put_contents($next.'/payload', 'next payload bytes');
		$filesystem = new ErasedataCleanupProbeFixture($next);
		$ack = rXMLRPCRequest::$responses['schedule']['callback'];
		rXMLRPCRequest::$responses['schedule']['callback'] = function() use ($ack, $next) {
			$ack();
			rXMLRPCRequest::$responses['d.get_base_path']['val'] = array($next.'/payload', 0, $next.'/payload');
		};
		$dependencies = array('listPath' => $this->queue, 'user' => User::getUser(),
			'filesystem' => $filesystem, 'log' => array('FileUtil', 'toLog'),
			'ackTimeout' => 0.1, 'ackPoll' => 0.01);
		$admission = erasedataRemovalAdmissionRun($dependencies, array($this->hash), 1);
		$this->assertEquals(false, $admission, 'the changed payload parent refuses the late admission probe');
		$this->assertEquals(array(), rXMLRPCRequest::$erased, 'late refusal erases nothing');
		$pending = glob($this->queue.'/*.pending');
		$this->assertEquals(1, count($pending), 'the late refusal retains one durable drain obligation');
		$filesystem->parents = array();
		$tick = erasedataDrainWorkerRun($dependencies);
		$this->assertTrue(in_array($next, $filesystem->parents, true),
			'drain probes the refused actual parent before considering erase');
		$this->assertEquals(array(), rXMLRPCRequest::$erased,
			'drain cannot erase a loaded torrent under a refused rename capability');
		$this->assertTrue(is_array($tick) && $tick['published'] === 0,
			'refused recovery publishes no payload deletion authority');
		$this->assertEquals($pending, glob($this->queue.'/*.pending'), 'the exact obligation remains retryable');
		$this->assertEquals('next payload bytes', file_get_contents($next.'/payload'), 'drain retains payload bytes');
		$this->assertEquals(array(), glob($this->queue.'/*.tmp'), 'refused drain stages no new artifact');
		$filesystem->refused = null;
		erasedataDrainWorkerRun($dependencies);
		$this->assertEquals(array($this->hash), rXMLRPCRequest::$erased, 'a repaired parent lets the next tick erase');
	}

	// ACC-OWNB-04: a download whose session copy rTorrent could not write
	// when it was added names its files only through the daemon. It is
	// claimed through that list instead of blocking every replacement
	// cleanup, and a save of its copy is requested once, with its hash logged.
	public function testDownloadWithoutSessionCopyIsClaimedThroughItsDaemonFileList()
	{
		$other = str_repeat('C', 40);
		$dir = $this->dir.'/data/other';
		mkdir($dir, 0700);
		file_put_contents($dir.'/kept.bin', 'kept');
		rXMLRPCRequest::$responses['d.multicall'] = array('ok' => true, 'val' => array($other, '', '', ''));
		rXMLRPCRequest::$responses['d.get_directory'] = array('ok' => true,
			'val' => array($dir, 'kept.bin', $dir.'/kept.bin', 'pad/0', ''));
		rXMLRPCRequest::$responses['d.save_full_session'] = array('ok' => true, 'val' => array(0));
		$snapshot = $this->ownerSnapshot();
		$this->assertTrue(is_array($snapshot), 'an unrelated download without a session copy does not block');
		$kept = realpath($dir.'/kept.bin');
		$stat = stat($kept);
		$this->assertTrue(isset($snapshot['paths']["p\0".$kept])
			&& isset($snapshot['inodes']['i:'.$stat['dev'].':'.$stat['ino']]), 'its listed file is claimed');
		$this->assertTrue(isset($snapshot['paths']["p\0".realpath($dir).'/pad/0']),
			'a listed file that may be padding is claimed as well');
		$this->assertEquals(array('d.save_full_session'), array_values(array_intersect(
			rXMLRPCRequest::$requested, array('d.save_full_session'))), 'one save of its copy is requested');
		$this->assertEquals(array('erasedata: cleanup owner '.$other.' session-copy-unreadable; its daemon file'
			.' list is claimed instead; session save requested'), FileUtil::$log, 'the download is named');
		$this->assertTrue(is_array($this->ownerSnapshot()), 'a later snapshot is not blocked either');
		$this->assertEquals(1, count(array_keys(rXMLRPCRequest::$requested, 'd.save_full_session')),
			'and asks for no second save in the same process');
	}

	// A readable copy whose files the daemon does not confirm (a name the
	// parser escaped once made exactly this) is claimed the same way, with its
	// own reason and without a save: the copy is there.
	public function testDownloadWhoseDaemonPathsDoNotConfirmItsCopyIsClaimedWithoutASave()
	{
		$other = str_repeat('E', 40);
		$dir = $this->dir.'/data/third';
		mkdir($dir, 0700);
		file_put_contents($dir.'/kept.bin', 'kept');
		ErasedataCollectorTestState::$fleetSources[$other] = array('hash' => $other,
			'info' => array('length' => 4, 'name' => 'kept.bin'));
		rXMLRPCRequest::$responses['d.multicall'] = array('ok' => true, 'val' => array($other, '', '', ''));
		// The exact plan's stored read and the claim read start with the same
		// command; the claim's answer has no multi-file flag, so the plan is
		// not confirmed while the claim is.
		rXMLRPCRequest::$responses['d.get_directory'] = array('ok' => true, 'val' => array($dir, 'kept.bin', ''));
		try
		{
			$snapshot = $this->ownerSnapshot();
			$this->assertTrue(is_array($snapshot), 'an unconfirmed plan does not block the cleanup');
			$kept = realpath($dir.'/kept.bin');
			$this->assertTrue(is_array($snapshot) && isset($snapshot['paths']["p\0".$kept]), 'its listed file is claimed');
			$this->assertEquals(array(), array_values(array_intersect(rXMLRPCRequest::$requested,
				array('d.save_full_session'))), 'no session save is requested for a copy that is there');
			$this->assertEquals(array('erasedata: cleanup owner '.$other.' daemon-paths-unconfirmed; its daemon file'
				.' list is claimed instead'), FileUtil::$log, 'the download and its reason are named');
		}
		finally { unset(ErasedataCollectorTestState::$fleetSources[$other]); }
	}

	public function testDownloadWithoutSessionCopyOrDaemonFileListBlocksWithItsHashNamed()
	{
		$other = str_repeat('D', 40);
		rXMLRPCRequest::$responses['d.multicall'] = array('ok' => true, 'val' => array($other, '', '', ''));
		foreach(array('unanswered' => null, 'relative directory' => array('relative', 'f', ''),
			'absolute file path' => array($this->dir, '/f', ''), 'odd answer' => array($this->dir, 'f')) as $case => $val)
		{
			FileUtil::$log = array();
			if($val === null) unset(rXMLRPCRequest::$responses['d.get_directory']);
			else rXMLRPCRequest::$responses['d.get_directory'] = array('ok' => true, 'val' => $val);
			$this->assertEquals(false, $this->ownerSnapshot($reason), 'an unknown owner still refuses: '.$case);
			$this->assertEquals('owner-session-copy-unreadable-daemon-paths-unconfirmed owner='.$other,
				$reason, 'the single consumer refusal names the blocking download: '.$case);
			$this->assertEquals(array(), FileUtil::$log, 'the probe does not duplicate its consumer diagnostic');
		}
	}

	private function ownerSnapshot(&$reason = null)
	{
		return(erasedataCleanupOtherOwnerSnapshot($this->hash, str_repeat('B', 40),
			str_repeat('a', 32), $this->hash.'-started-1787587200', $reason));
	}


	private function metadataSourceMirror()
	{
		$root = dirname(__DIR__, 3);
		$mirror = ErasedataProductionMirror::build($this->dir.'/source-mirror', $root);
		$this->assertTrue($mirror->isExact(), 'the metadata test executes the shipped erasedata files');
		// Keep the real source loader and parser: a fake info=null source hid
		// the native session envelope observed through load.start on 0.16.25.
		foreach(array('rtorrent.php', 'Torrent.php', 'commandargs.php') as $name)
		{
			$bytes = file_get_contents($root.'/php/'.$name);
			file_put_contents($mirror->root.'/php/'.$name, $bytes);
			$this->assertEquals($bytes, file_get_contents($mirror->root.'/php/'.$name),
				'the source loader and parser remain byte-identical to production');
		}
		return($mirror);
	}

	private function metadataSourceProbe($mirror, array $changes = array(), $meta = 1,
		$replyHash = null, $rawOverride = null, $rpcOk = true)
	{
		$hash = str_repeat('F', 40);
		$mirror->scriptRpc(array(
			'get_session' => array('ok' => true, 'val' => array($mirror->settings.'/', '')),
			'd.multicall' => array('ok' => true, 'val' => array($hash, '', '', '')),
			'd.is_meta' => array('ok' => $rpcOk,
				'val' => array($meta, $replyHash === null ? $hash : $replyHash))));
		// Captured verbatim from a real daemon's unresolved magnet session on
		// 2026-10-07 (load.start, requested hash F*40); no tracker or credentials.
		$raw = $rawOverride === null ? base64_decode(trim(file_get_contents(
			__DIR__.'/fixtures/native-unresolved-magnet-session.b64')), true) : $rawOverride;
		$this->assertTrue(is_string($raw), 'the captured native session fixture decodes');
		$body = 'require_once('.var_export($mirror->root.'/php/rtorrent.php', true).');'
			.'require_once('.var_export($mirror->pluginDir.'/removewithdata.php', true).');'
			.'$hash='.var_export($hash, true).'; $raw='.var_export($raw, true).';'
			.'$changes='.var_export($changes, true).';'
			.'if(count($changes)) { $parsed=Torrent::fromRawBytes($raw);'
			.' foreach($changes as $key=>$value) {'
			.' if($value === null) unset($parsed->info[$key]); else $parsed->info[$key]=$value; }'
			.' $raw=(string)$parsed; }'
			.'file_put_contents('.var_export($mirror->settings.'/'.$hash.'.torrent', true).', $raw);'
			.'$source=erasedataLoadTorrentSource($hash);'
			.'$snapshot=erasedataCleanupOtherOwnerSnapshot('.var_export($this->hash, true).','
			.var_export(str_repeat('B', 40), true).','.var_export(str_repeat('a', 32), true).','
			.var_export($this->hash.'-started-1787587200', true).');'
			.'echo json_encode(array("source"=>is_object($source) ? get_class($source) : false,'
			.'"metadata_only"=>erasedataCleanupMetadataOnly($hash), "snapshot"=>$snapshot));';
		$runner = $mirror->writeRunner('metadata-source', $body);
		$process = ErasedataTestProcess::start($mirror->php($runner));
		try
		{
			$this->assertTrue($process->wait(5), 'the real metadata source probe terminates');
			$this->assertEquals(0, $process->reap(), 'the real source loader runs without error: '.$process->err);
			$result = json_decode($process->out, true);
			$this->assertTrue(is_array($result), 'the real source probe emits its result: '.$process->out);
			return($result);
		}
		finally { $process->reap(); }
	}

	public function testCapturedNativeMagnetSessionIsSkippedByRealSourceLoader()
	{
		$result = $this->metadataSourceProbe($this->metadataSourceMirror());
		$this->assertEquals('Torrent', $result['source'], 'getSource accepts the captured synthetic info envelope');
		$this->assertEquals(true, $result['metadata_only'], 'the captured native stub has no torrent payload');
		$this->assertEquals(array('paths'=>array(), 'inodes'=>array(), 'bindings'=>array()),
			$result['snapshot'], 'an unresolved native magnet does not block unrelated replacement cleanup');
	}

	public function testNativeMagnetEnvelopeRejectsPayloadAndIdentityUncertainty()
	{
		$mirror = $this->metadataSourceMirror();
		$changes = array(
			'length' => array('length'=>1),
			'files' => array('files'=>array(array('length'=>1, 'path'=>array('payload')))),
			'piece length' => array('piece length'=>16384),
			'meta type' => array('meta_download'=>'1'),
			'meta absent' => array('meta_download'=>null),
			'wrong name' => array('name'=>str_repeat('E', 40).'.meta'),
			// rTorrent writes only the uppercase spelling (rTorrent::isMetadataStubName()).
			'lowercase name' => array('name'=>str_repeat('f', 40).'.meta'),
			'uppercase suffix' => array('name'=>str_repeat('F', 40).'.META'),
			'wrong pieces' => array('pieces'=>str_repeat("\xee", 20)),
			'incomplete info' => array('pieces'=>null));
		foreach($changes as $reason=>$change)
		{
			$result = $this->metadataSourceProbe($mirror, $change);
			$this->assertEquals(false, $result['metadata_only'], 'a changed native stub refuses: '.$reason);
			$this->assertEquals(false, $result['snapshot'], 'uncertain payload ownership blocks cleanup: '.$reason);
		}
		foreach(array(0, false, 'broken') as $meta)
		{
			$result = $this->metadataSourceProbe($mirror, array(), $meta);
			$this->assertEquals(false, $result['snapshot'], 'nonmetadata or unknown daemon state refuses');
		}
		$result = $this->metadataSourceProbe($mirror, array(), 1, str_repeat('E', 40));
		$this->assertEquals(false, $result['snapshot'], 'a mismatched current daemon hash refuses');
		$result = $this->metadataSourceProbe($mirror, array(), 1, null, 'd4:infod');
		$this->assertEquals(false, $result['source'], 'malformed captured source bytes fail the actual loader');
		$this->assertEquals(false, $result['snapshot'], 'a parse failure cannot become metadata-only');
		$result = $this->metadataSourceProbe($mirror, array(), 1, null, null, false);
		$this->assertEquals(false, $result['snapshot'], 'an unavailable daemon attestation refuses');
	}

	// The info dictionary of the captured native session for F*40, as
	// php/Torrent.php decodes it (bencoded integers become floats).
	private function capturedEnvelope($hash)
	{
		$raw = base64_decode(trim(file_get_contents(
			__DIR__.'/fixtures/native-unresolved-magnet-session.b64')), true);
		$this->assertTrue(is_string($raw) && strpos($raw, 'd4:infod13:meta_downloadi1e4:name45:'
			.$hash.'.meta6:pieces20:'.hex2bin($hash).'e') === 0,
			'the in-process envelope is the captured info dictionary byte for byte');
		return(array('meta_download' => 1.0, 'name' => $hash.'.meta', 'pieces' => hex2bin($hash)));
	}

	public function testMetadataOnlyMagnetDoesNotOwnPayloadButCorruptRealTorrentRefuses()
	{
		$stubHash = str_repeat('F', 40);
		rXMLRPCRequest::$responses['d.multicall'] = array('ok' => true,
			'val' => array($stubHash, '', '', ''));
		$envelope = $this->capturedEnvelope($stubHash);
		ErasedataCollectorTestState::$fleetSources[$stubHash] = array('hash' => $stubHash, 'info' => $envelope);
		rXMLRPCRequest::$responses['d.is_meta'] = array('ok' => true, 'val' => array(1, $stubHash));
		$this->assertEquals(array('paths' => array(), 'inodes' => array(), 'bindings' => array()),
			$this->ownerSnapshot(), 'an attested metadata-only magnet has no torrent payload ownership');
		foreach(array(0, false, 'broken') as $meta)
		{
			rXMLRPCRequest::$responses['d.is_meta']['val'][0] = $meta;
			$this->assertEquals(false, $this->ownerSnapshot(), 'missing info on a real or unknown torrent refuses');
		}
		rXMLRPCRequest::$responses['d.is_meta']['val'] = array(1, $stubHash);
		ErasedataCollectorTestState::$fleetSources[$stubHash]['info'] = array('name' => 'corrupt');
		$this->assertEquals(false, $this->ownerSnapshot(), 'a malformed info dictionary is never skipped');
		// The real loader never yields a missing info; such a source is not the
		// captured envelope and cannot bypass its check.
		ErasedataCollectorTestState::$fleetSources[$stubHash]['info'] = null;
		$this->assertEquals(false, erasedataCleanupMetadataOnly($stubHash),
			'a source without the captured envelope is not a metadata stub');
		$this->assertEquals(false, $this->ownerSnapshot(), 'a missing info dictionary is never skipped');
		ErasedataCollectorTestState::$fleetSources[$stubHash]['info'] = $envelope;
		rXMLRPCRequest::$responses['d.is_meta']['val'] = array(1, str_repeat('D', 40));
		$this->assertEquals(false, $this->ownerSnapshot(), 'a mismatched daemon identity is never skipped');
		rXMLRPCRequest::$responses['d.is_meta']['val'] = array(1, $stubHash);
		unset(ErasedataCollectorTestState::$fleetSources[$stubHash]);
		$this->assertEquals(false, $this->ownerSnapshot(), 'an unreadable source is never mistaken for a stub');
	}
}
