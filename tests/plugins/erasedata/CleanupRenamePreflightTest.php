<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/CollectorFixture.php');
require_once(__DIR__ . '/../../../plugins/erasedata/removewithdata.php');
require_once(__DIR__ . '/CleanupArmReasonFixture.php');

// The missing parent is rejected before any helper process is started.
class ErasedataMissingParentProbe extends ErasedataFilesystemOps
{
    public static function renameHelperPath()
    {
        return PHP_BINARY;
    }
}

class ErasedataCleanupProbeFixture extends ErasedataCollectorFixture
{
    public $parents = [];
    public $refused = null;

    public function __construct($refused = null)
    {
        parent::__construct([]);
        $this->refused = $refused;
    }

    protected function renameCapability($directory)
    {
        $this->parents[] = $directory;
        if ($directory !== $this->refused) {
            return(true);
        }
        ErasedataMissingParentProbe::canRenameNoReplace($directory . '/missing-probe-parent', false);
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
        $this->dir = sys_get_temp_dir() . '/cleanup-preflight-' . getmypid() . '-' . uniqid();
        mkdir($this->dir, 0700);
        mkdir($this->dir . '/data', 0700);
        $this->queue = $this->dir . '/erasedata';
        mkdir($this->queue, 0700);
        $this->hash = str_repeat('A', 40);
        $this->payload = $this->dir . '/data/payload';
        file_put_contents($this->payload, 'payload bytes');
        FileUtil::$settingsPath = $this->dir;
        FileUtil::$log = [];
        ErasedataCollectorTestState::$source = false;
        rXMLRPCRequest::$erased = [];
        rXMLRPCRequest::$requested = [];
        rXMLRPCRequest::$responses = [
            'system.client_version' => ['ok' => true, 'val' => ['0.16.25']],
            'd.hash' => ['ok' => true, 'val' => [$this->hash]],
            'd.get_base_path' => ['ok' => true, 'val' => [$this->payload, 0, $this->payload]],
            'd.set_custom5' => ['ok' => true, 'val' => ['', '', '']],
            'schedule.if_absent' => ['ok' => true, 'val' => [0]],
            'schedule' => ['ok' => true, 'val' => [0], 'callback' => function () {
                $state = erasedataReadDrainState($this->queue);
                $state['acknowledged'] = $state['generation'];
                erasedataWriteDrainState($this->queue, $state);
            }],
        ];
    }

    public function tearDown()
    {
        $this->remove($this->dir);
    }

    public function testFallbackProbeStatusIsUsableOnlyForAProbe()
    {
        $helper = $this->dir . '/fallback-helper';
        file_put_contents($helper, "#!/bin/sh\nexit 5\n");
        chmod($helper, 0700);
        $method = new ReflectionMethod('ErasedataFilesystemOps', 'runRenameHelper');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $this->assertTrue(
            $method->invoke(null, [$helper, '--probe', $this->dir], $this->dir),
            'the distinct fallback probe status admits a usable filesystem',
        );
        $this->assertTrue(!$method->invoke(
            null,
            [$helper, $this->payload, $this->dir . '/target'],
            $this->payload,
            $this->dir . '/target',
        ), 'a fallback probe status cannot attest a rename');
    }

    public function testCleanupPrepareProbesQueueAndPayloadBeforeStaging()
    {
        $filesystem = new ErasedataCleanupProbeFixture(dirname($this->payload));
        $job = $this->prepareJob($filesystem);
        $this->assertEquals(false, $job, 'unsupported cleanup parents refuse before predecessor erase');
        if (is_array($job)) {
            erasedataCancelObsoleteCleanup($job, $filesystem);
        }
        $this->assertTrue(
            in_array($this->queue, $filesystem->parents, true)
            && in_array(dirname($this->payload), $filesystem->parents, true),
            'cleanup uses the same queue and payload parent probes as HTTP admission',
        );
        $this->assertEquals([], glob($this->queue . '/*.tmp'), 'refused cleanup stages no obligation');
        $this->assertEquals([], rXMLRPCRequest::$erased, 'cleanup preparation never erases a download');
        $this->assertEquals('payload bytes', file_get_contents($this->payload), 'cleanup refusal preserves bytes');
        $filesystem->refused = null;
        $job = $this->prepareJob($filesystem);
        $this->assertTrue(is_array($job), 'a usable cleanup parent can prepare on the next attempt');
        if (is_array($job)) {
            erasedataCancelObsoleteCleanup($job, $filesystem);
        }

    }

    public function testCleanupArmReprobesBeforeReturningEraseAuthority()
    {
        $filesystem = new ErasedataCleanupProbeFixture();
        $job = $this->prepareJob($filesystem);
        $this->assertTrue(is_array($job), 'usable parents prepare the exact cleanup job');
        if (!is_array($job)) {
            return;
        }
        try {
            $child = null;
            $script = 'require ' . var_export(__FILE__, true) . '; FileUtil::$settingsPath='
                . var_export($this->dir, true) . '; usleep(10000); '
                . 'exit(is_array(erasedataAcknowledgeDrainGeneration(' . var_export($this->queue, true)
                . ', ' . var_export(User::getUser(), true) . ', array("FileUtil","toLog"))) ? 0 : 1);';
            rXMLRPCRequest::$responses['schedule']['callback'] = function () use (&$child, $script, $filesystem) {
                $child = ErasedataTestProcess::start('exec ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script));
                $filesystem->parents = [];
                $filesystem->refused = dirname($this->payload);
            };
            $reason = 'stale';
            // The child parses this file and its fixtures first; the wait ends at its acknowledgement.
            $this->assertTrue(
                !erasedataArmObsoleteCleanupRun(['listPath' => $this->queue,
                    'user' => User::getUser(), 'filesystem' => $filesystem, 'log' => ['FileUtil', 'toLog'],
                    'ackTimeout' => 30, 'ackPoll' => 0.01], $job, $reason),
                'cleanup arm refuses a changed parent before predecessor erase',
            );
            $this->assertEquals('cleanup-rename-unconfirmed', $reason, 'an unresolved parent does not establish a lasting capability failure');
            $this->assertTrue(
                in_array(dirname($this->payload), $filesystem->parents, true),
                'acknowledgement does not reuse an earlier successful parent probe',
            );
            $this->assertTrue(is_file($job['tmp_path']), 'late refusal preserves the exact prepared job for cancellation');
            $this->assertEquals([], rXMLRPCRequest::$erased, 'late cleanup refusal never erases its predecessor');
        } finally {
            $this->assertTrue($child instanceof ErasedataTestProcess && $child->wait(2)
                && $child->reap() === 0, 'the real acknowledgement child finishes cleanly');
            erasedataCancelObsoleteCleanup($job, $filesystem);
        }
    }

    public function testEveryCleanupArmOutcomeSuppliesItsOwnClassifiedReason()
    {
        foreach (['job' => 'cleanup-arm-job', 'lock' => 'cleanup-arm-lock',
            'state' => 'cleanup-arm-state', 'generation' => 'cleanup-arm-generation',
            'write' => 'cleanup-arm-write', 'rearm' => 'cleanup-arm-rearm',
            'no-ack' => 'cleanup-drain-no-ack', 'changed' => 'cleanup-arm-changed',
            'rename' => 'cleanup-rename-capability', 'success' => null] as $failure => $expected) {
            \ErasedataCleanupArmFixture\Scenario::reset($failure);
            $lock = \ErasedataCleanupArmFixture\Scenario::$lock;
            $reason = 'stale';
            try {
                $result = \ErasedataCleanupArmFixture\erasedataArmObsoleteCleanupRun(
                    [
                        'listPath' => $this->queue, 'user' => User::getUser(), 'ackTimeout' => 0, 'ackPoll' => 0],
                    ['tmp_path' => $this->queue . '/prepared.tmp', 'list_path' => $this->queue . '/prepared.list',
                        'lock' => $lock],
                    $reason,
                );
                $this->assertEquals($failure === 'success', $result, 'the bool arm API remains exact: ' . $failure);
                $this->assertEquals($expected, $reason, 'the output reason names the actual refusal: ' . $failure);
                $this->assertEquals(
                    $expected === null ? [] : [$expected],
                    \ErasedataCleanupArmFixture\Scenario::$diagnostics,
                    'diagnostic and consumer share one classified reason',
                );
                $this->assertEquals(
                    in_array($failure, ['job', 'lock'], true) ? 0 : 1,
                    \ErasedataCleanupArmFixture\Scenario::$releases,
                    'every acquired state lock is released exactly once',
                );
            } finally {
                fclose($lock);
            }
        }
    }

    public function testCleanupArmDistinguishesKnownCapabilityRefusalsFromUnconfirmedProbes()
    {
        foreach (['rename-noreplace-unsupported', 'rename-noreplace-manifest-unavailable',
            'rename-noreplace-manifest-invalid', 'rename-noreplace-helper-unavailable',
            'rename-noreplace-process-unavailable', 'rename-noreplace-process-exit-127',
            'rename-noreplace-lease-unavailable', 'rename-noreplace-descriptor-unavailable',
            'rename-noreplace-parent-unresolved', 'rename-noreplace-error', ''] as $index => $rawReason) {
            \ErasedataCleanupArmFixture\Scenario::reset('rename');
            \ErasedataCleanupArmFixture\Scenario::$renameReason = $rawReason;
            $reason = null;
            try {
                $job = ['tmp_path' => $this->queue . '/job.tmp', 'list_path' => $this->queue . '/job.list',
                    'lock' => \ErasedataCleanupArmFixture\Scenario::$lock];
                $this->assertEquals(
                    false,
                    \ErasedataCleanupArmFixture\erasedataArmObsoleteCleanupRun([
                        'listPath' => $this->queue, 'user' => 'fixture-user'], $job, $reason),
                    'every failed probe retains its boolean refusal',
                );
                $this->assertEquals(
                    $index < 4 ? 'cleanup-rename-capability' : 'cleanup-rename-unconfirmed',
                    $reason,
                    'only a known local capability or helper configuration refusal buys a hold: ' . $rawReason,
                );
            } finally {
                fclose(\ErasedataCleanupArmFixture\Scenario::$lock);
            }
        }
    }

    public function testLateAdmissionRefusalRemainsRefusedByDrainAndRetriesAfterRepair()
    {
        $next = $this->dir . '/next-data';
        mkdir($next, 0700);
        file_put_contents($next . '/payload', 'next payload bytes');
        $filesystem = new ErasedataCleanupProbeFixture($next);
        $ack = rXMLRPCRequest::$responses['schedule']['callback'];
        rXMLRPCRequest::$responses['schedule']['callback'] = function () use ($ack, $next) {
            $ack();
            rXMLRPCRequest::$responses['d.get_base_path']['val'] = [$next . '/payload', 0, $next . '/payload'];
        };
        $dependencies = ['listPath' => $this->queue, 'user' => User::getUser(),
            'filesystem' => $filesystem, 'log' => ['FileUtil', 'toLog'],
            'ackTimeout' => 0.1, 'ackPoll' => 0.01];
        $admission = erasedataRemovalAdmissionRun($dependencies, [$this->hash], 1);
        $this->assertEquals(false, $admission, 'the changed payload parent refuses the late admission probe');
        $this->assertEquals([], rXMLRPCRequest::$erased, 'late refusal erases nothing');
        $pending = glob($this->queue . '/*.pending');
        $this->assertEquals(1, count($pending), 'the late refusal retains one durable drain obligation');
        $filesystem->parents = [];
        $tick = erasedataDrainWorkerRun($dependencies);
        $this->assertTrue(
            in_array($next, $filesystem->parents, true),
            'drain probes the refused actual parent before considering erase',
        );
        $this->assertEquals(
            [],
            rXMLRPCRequest::$erased,
            'drain cannot erase a loaded torrent under a refused rename capability',
        );
        $this->assertTrue(
            is_array($tick) && $tick['published'] === 0,
            'refused recovery publishes no payload deletion authority',
        );
        $this->assertEquals($pending, glob($this->queue . '/*.pending'), 'the exact obligation remains retryable');
        $this->assertEquals('next payload bytes', file_get_contents($next . '/payload'), 'drain retains payload bytes');
        $this->assertEquals([], glob($this->queue . '/*.tmp'), 'refused drain stages no new artifact');
        $filesystem->refused = null;
        erasedataDrainWorkerRun($dependencies);
        $this->assertEquals([$this->hash], rXMLRPCRequest::$erased, 'a repaired parent lets the next tick erase');
    }

    // Every loaded download is claimed through its daemon file list
    // (erasedataLoadedDownloads(), erasedataClaimedFiles()). A download
    // without a session copy needs no save and blocks nothing, and a magnet
    // stub claims only its <HASH>.meta.
    public function testEveryLoadedDownloadIsClaimedThroughItsDaemonFileList()
    {
        $other = str_repeat('C', 40);
        $stub = str_repeat('F', 40);
        $dir = $this->dir . '/data/other';
        mkdir($dir . '/pad', 0700, true);
        file_put_contents($dir . '/kept.bin', 'kept');
        file_put_contents($dir . '/pad/0', "\0\0\0\0\0");
        rXMLRPCRequest::$responses['d.multicall'] = ['fleet' => [
            $other => ['directory' => $dir, 'files' => ['kept.bin' => 4, 'pad/0' => 5]],
            $stub => ['directory' => $this->dir . '/session', 'files' => [$stub . '.meta' => 1]]]];
        $reason = null;
        $own = [$dir . '/kept.bin', $dir . '/pad/0', $this->payload];
        $snapshot = $this->ownerSnapshot($reason, $own);
        $this->assertTrue(is_array($snapshot) && $reason === null, 'the snapshot is read: ' . $reason);
        $this->assertEquals('claimed', erasedataCleanupOtherOwnerState($snapshot, $dir . '/kept.bin'), 'its listed file is claimed');
        $this->assertEquals('claimed', erasedataCleanupOtherOwnerState($snapshot, $dir . '/pad/0'), 'so is a listed file that may be padding');
        $this->assertEquals('unclaimed', erasedataCleanupOtherOwnerState($snapshot, $this->payload), 'the stub claims no payload');
        $this->assertEquals([], array_values(array_intersect(rXMLRPCRequest::$requested, ['d.save_full_session'])), 'no save is requested');

        rXMLRPCRequest::$responses['d.multicall'] = ['fleet' => [], 'faults' => ['sizes' => true]];
        rXMLRPCRequest::$responses['d.multicall']['fleet'][$other] = ['directory' => $dir, 'files' => ['kept.bin' => 4]];
        $this->assertEquals(false, $this->ownerSnapshot($reason, $own), 'an unanswered question is no snapshot');
        $this->assertEquals('owner-snapshot-unconfirmed why=fault', $reason, 'and the refusal is classified with its cause');
    }

    private function remove($path)
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
                $this->remove($path . '/' . $entry);
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }

    private function prepareJob($filesystem)
    {
        $stat = lstat($this->payload);
        $identity = ['canonical' => realpath($this->payload),
            'lstat' => ['dev' => $stat['dev'], 'ino' => $stat['ino']],
            'stat' => ['dev' => $stat['dev'], 'ino' => $stat['ino']],
            'size' => $stat['size'], 'mtime' => $stat['mtime']];
        return(erasedataPrepareObsoleteCleanup(
            $this->hash,
            str_repeat('B', 40),
            str_repeat('a', 32),
            $this->hash . '-started-1787587200',
            dirname($this->payload),
            [['path' => $this->payload, 'identity' => $identity]],
            $filesystem,
        ));
    }

    private function ownerSnapshot(&$reason, array $own)
    {
        return(erasedataCleanupOtherOwnerSnapshot(
            $this->hash,
            str_repeat('B', 40),
            str_repeat('a', 32),
            $this->hash . '-started-1787587200',
            $reason,
            $own,
        ));
    }

}
