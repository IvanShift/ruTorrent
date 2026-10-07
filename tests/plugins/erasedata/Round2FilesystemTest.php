<?php

require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/CollectorFixture.php');
require_once(__DIR__.'/../../../plugins/erasedata/removewithdata.php');

class ErasedataRound2ProbeFixture extends ErasedataCollectorFixture
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
		ErasedataFilesystemOps::canRenameNoReplace($directory.'/missing-probe-parent', false);
		return(false);
	}
}

class Round2FilesystemTest extends TestCase
{
	private $dir;
	private $queue;
	private $hash;
	private $payload;

	public function setUp()
	{
		$this->dir = sys_get_temp_dir().'/r2fs-'.getmypid().'-'.uniqid();
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
		$filesystem = new ErasedataRound2ProbeFixture(dirname($this->payload));
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
		$filesystem = new ErasedataRound2ProbeFixture();
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
			$this->assertTrue(!erasedataArmObsoleteCleanupRun(array('listPath' => $this->queue,
				'user' => User::getUser(), 'filesystem' => $filesystem, 'log' => array('FileUtil', 'toLog'),
				'ackTimeout' => 0.5, 'ackPoll' => 0.01), $job),
				'cleanup arm refuses a changed parent before predecessor erase');
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

	public function testLateAdmissionRefusalRemainsRefusedByDrainAndRetriesAfterRepair()
	{
		$next = $this->dir.'/next-data';
		mkdir($next, 0700);
		file_put_contents($next.'/payload', 'next payload bytes');
		$filesystem = new ErasedataRound2ProbeFixture($next);
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

	private function ownerSnapshot()
	{
		return(erasedataCleanupOtherOwnerSnapshot($this->hash, str_repeat('B', 40),
			str_repeat('a', 32), $this->hash.'-started-1787587200'));
	}


	private function metadataSourceMirror()
	{
		$root = dirname(__DIR__, 3);
		$mirror = ErasedataProductionMirror::build($this->dir.'/source-mirror', $root);
		$this->assertTrue($mirror->isExact(), 'the metadata test executes the shipped erasedata files');
		// Keep the real source loader and parser: a fake info=null source hid
		// the native session envelope observed through load.start on 0.16.25.
		foreach(array('rtorrent.php', 'Torrent.php') as $name)
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

	public function testMetadataOnlyMagnetDoesNotOwnPayloadButCorruptRealTorrentRefuses()
	{
		$stubHash = str_repeat('C', 40);
		rXMLRPCRequest::$responses['d.multicall'] = array('ok' => true,
			'val' => array($stubHash, '', '', ''));
		ErasedataCollectorTestState::$fleetSources[$stubHash] = array('hash' => $stubHash, 'info' => null);
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
		ErasedataCollectorTestState::$fleetSources[$stubHash]['info'] = null;
		rXMLRPCRequest::$responses['d.is_meta']['val'] = array(1, str_repeat('D', 40));
		$this->assertEquals(false, $this->ownerSnapshot(), 'a mismatched daemon identity is never skipped');
		rXMLRPCRequest::$responses['d.is_meta']['val'] = array(1, $stubHash);
		unset(ErasedataCollectorTestState::$fleetSources[$stubHash]);
		$this->assertEquals(false, $this->ownerSnapshot(), 'an unreadable source is never mistaken for a stub');

	}
}
