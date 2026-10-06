<?php

require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/CollectorFixture.php');
require_once(__DIR__.'/../../../plugins/erasedata/removewithdata.php');

class ErasedataAdmissionProbeFixture extends ErasedataCollectorFixture
{
    public $parents = array();
    public $refused = null;
    public $aliasIdentity = null;

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

    public function targetIdentity($path)
    {
        if($this->aliasIdentity !== null) return($this->aliasIdentity);
        return(parent::targetIdentity($path));
    }
}

class RenameAdmissionTest extends TestCase
{
    private $dir;
    private $queue;
    private $hash;
    private $payload;

    public function setUp()
    {
        $this->dir = sys_get_temp_dir().'/era-'.getmypid().'-'.uniqid();
        mkdir($this->dir, 0700);
        mkdir($this->dir.'/data', 0700);
        $this->queue = $this->dir.'/erasedata';
        mkdir($this->queue, 0700);
        $this->hash = str_repeat('A', 40);
        $this->payload = $this->dir.'/data/payload';
        file_put_contents($this->payload, 'payload bytes');
        FileUtil::$settingsPath = $this->dir;
        FileUtil::$log = array();
        rXMLRPCRequest::$erased = array();
        rXMLRPCRequest::$requested = array();
        rXMLRPCRequest::$responses = array(
            'system.client_version' => array('ok'=>true, 'val'=>array('0.16.24')),
            'd.get_base_path' => array('ok'=>true, 'val'=>array($this->payload, 0, $this->payload)),
            'd.set_custom5' => array('ok'=>true, 'val'=>array('', '', '')),
            'schedule.if_absent' => array('ok'=>true, 'val'=>array(0)),
            'schedule' => array('ok'=>true, 'val'=>array(0), 'callback'=>function() {
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

    private function admit($filesystem)
    {
        return erasedataRemovalAdmissionRun(array('listPath'=>$this->queue,
            'user'=>User::getUser(), 'filesystem'=>$filesystem,
            'log'=>array('FileUtil','toLog'), 'ackTimeout'=>0.1, 'ackPoll'=>0.01),
            array($this->hash), 1);
    }

    public function testUnsupportedPayloadParentRefusesBeforeEraseAndStaging()
    {
        $filesystem = new ErasedataAdmissionProbeFixture($this->dir.'/data');
        $this->assertTrue($this->admit($filesystem) === false, 'unsupported data parent refuses admission');
        $this->assertEquals(array(), rXMLRPCRequest::$erased, 'unsupported filesystem never reaches d.erase');
        $this->assertEquals('payload bytes', file_get_contents($this->payload), 'refusal preserves payload bytes');
        $this->assertEquals(array(), glob($this->queue.'/*.tmp'), 'refusal creates no staging');
        $this->assertEquals(array(), glob($this->queue.'/*.pending'), 'refusal creates no obligation');
        $this->assertTrue(!in_array('schedule', rXMLRPCRequest::$requested, true), 'refusal precedes schedule registration');
    }

    public function testSupportedAdmissionProbesPublicParentsBeforeErase()
    {
        $filesystem = new ErasedataAdmissionProbeFixture();
        rXMLRPCRequest::$responses['d.set_custom5']['callback'] = function() use ($filesystem) {
            $this->assertTrue(in_array($this->dir.'/data', $filesystem->parents, true),
                'actual payload parent was probed before the erase request');
            $this->assertTrue(in_array($this->queue, $filesystem->parents, true),
                'queue support is proved before erase');
        };
        $outcome = $this->admit($filesystem);
        $this->assertTrue(is_array($outcome), 'supported admission publishes its generation');
        $this->assertEquals(array($this->hash), rXMLRPCRequest::$erased, 'supported admission erases the requested torrent');
        $this->assertEquals(array($this->queue, $this->dir.'/data'), $filesystem->parents,
            'admission probes queue and payload parents before publication');
    }

    public function testPrivateManifestPublicationNeedsNoNoReplaceCapability()
    {
        $filesystem = new ErasedataAdmissionProbeFixture($this->queue);
        $tmp = $this->queue.'/'.$this->hash.'.1.test.tmp';
        file_put_contents($tmp, 'private manifest bytes');
        $this->assertTrue(ErasedataManifestCodec::publishStaging($tmp, $this->hash, $filesystem),
            'private publication succeeds without no-replace filesystem support');
        $this->assertEquals(array(), $filesystem->parents, 'publication leaves no probe debris in the queue');
        $this->assertEquals('private manifest bytes', file_get_contents(substr($tmp, 0, -4).'.list'),
            'publication preserves exact bytes');
    }

    public function testMountAliasesWithTheSameInodeStillRequireSeparateProbes()
    {
        $filesystem = new ErasedataAdmissionProbeFixture($this->dir.'/data');
        $filesystem->aliasIdentity = $filesystem->targetIdentity($this->queue);
        $probed = array();
        $paths = array('base'=>$this->payload, 'files'=>array($this->payload), 'multi'=>false);
        $this->assertTrue(!erasedataRemovalRenameReady($this->queue, $paths, $filesystem, $probed),
            'a refused mount alias cannot inherit support from the same inode at a different path');
        $this->assertEquals(array($this->queue, $this->dir.'/data'), $filesystem->parents,
            'each resolved mount path is probed even when its dev and inode match');
    }

    public function testChangedPayloadParentRefusalKeepsItsDiagnosticContext()
    {
        $next = $this->dir.'/next-data';
        mkdir($next, 0700);
        file_put_contents($next.'/payload', 'next payload bytes');
        $filesystem = new ErasedataAdmissionProbeFixture($next);
        $ack = rXMLRPCRequest::$responses['schedule']['callback'];
        rXMLRPCRequest::$responses['schedule']['callback'] = function() use ($ack, $next) {
            $ack();
            rXMLRPCRequest::$responses['d.get_base_path']['val'] = array($next.'/payload', 0, $next.'/payload');
        };
        $this->assertTrue($this->admit($filesystem) === false, 'changed unsupported parent refuses before erase');
        $this->assertEquals(array(), rXMLRPCRequest::$erased, 'late preflight failure retains the torrent');
        $this->assertTrue(strpos(implode("\n", FileUtil::$log),
            'path='.erasedataLogPath($next.'/missing-probe-parent')) !== false,
            'the second parent lookup retains the named helper refusal context');
    }

    public function testLegacyAdmissionReportsTheActualParentAndErrno()
    {
        chmod($this->dir.'/data', 0500);
        try
        {
            $this->assertTrue(erasedataRemoveWithData(array($this->hash), 1) === false,
                'legacy producer refuses an unwritable payload parent');
            $this->assertEquals(array(), rXMLRPCRequest::$erased, 'legacy refusal retains the torrent');
            $log = implode("\n", FileUtil::$log);
            $this->assertTrue(strpos($log, 'path='.erasedataLogPath($this->dir.'/data')) !== false
                && strpos($log, 'errno=13') !== false,
                'legacy refusal reports the actual native parent and errno');
        }
        finally { chmod($this->dir.'/data', 0700); }
    }

    public function testCaptureProbesOnlyThePublicSourceParent()
    {
        $filesystem = new ErasedataAdmissionProbeFixture();
        $private = $this->dir.'/data/.erasedata-entry-test';
        mkdir($private, 0700);
        $this->assertTrue($filesystem->rename($this->payload, $private.'/entry'), 'supported capture succeeds');
        $this->assertEquals(array($this->dir.'/data'), $filesystem->parents,
            'capture never probes inside its private protocol root');
    }

    public function testAttestedProbeResidueDoesNotHoldAnEmptyQueue()
    {
        $root = $this->queue.'/.rutorrent-rename-probe-999-0123456789abcdef';
        mkdir($root, 0700);
        file_put_contents($root.'/from-file', 'interrupted probe'); chmod($root.'/from-file', 0600);
        $scan = erasedataRetirementScan(array('listPath'=>$this->queue));
        $this->assertTrue($scan['empty'], 'finite attested probe residue is not a deletion obligation');
        file_put_contents($root.'/unexpected', 'preserve unrelated bytes');
        $scan = erasedataRetirementScan(array('listPath'=>$this->queue));
        $this->assertTrue(!$scan['empty'], 'unexpected workspace contents conservatively hold retirement');
        $this->assertTrue(!erasedataEnsureQueueDirectory($this->queue), 'unsafe workspace refuses queue admission visibly');
        $this->assertEquals('preserve unrelated bytes', file_get_contents($root.'/unexpected'), 'admission never discards unexpected bytes');
    }

    public function testOnlyAttestedPrivateCaptureParentsSkipAProbe()
    {
        $root = $this->dir.'/data/.erasedata-entry-private';
        mkdir($root, 0700);
        file_put_contents($root.'/captured', 'captured bytes');
        $filesystem = new ErasedataAdmissionProbeFixture();
        $this->assertTrue($filesystem->rename($root.'/captured', $root.'/unattested'), 'ordinary parent still supports its capture');
        $this->assertEquals(array($root), $filesystem->parents, 'a matching directory name alone never bypasses the probe');
        file_put_contents(erasedataPrivateMarkerPath($root), '');
        chmod(erasedataPrivateMarkerPath($root), 0600);
        $filesystem->parents = array();
        $this->assertTrue($filesystem->rename($root.'/unattested', $root.'/captured'), 'attested private capture operation succeeds');
        $this->assertEquals(array(), $filesystem->parents, 'attested protocol roots receive no crash-prone probe artifacts');
    }
}
