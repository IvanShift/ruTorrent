<?php

require_once(__DIR__ . '/RetrackersTestStubs.php');

define('RETRACKERS_IMPORT_ONLY', true);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');

class LifecycleInitLogTest extends TestCase
{
    public function testInitRefusalWritesOneClassifiedReasonWithoutRawFault()
    {
        foreach (array(
            'receipt-ledger-corrupt' => 'receipt-ledger-corrupt',
            '<?xml fault="https://tracker.invalid/passkey" path="/private/session">' => 'lifecycle-unsupported',
        ) as $reported => $expected) {
            $adapter = new class($reported) extends RetrackersLifecycleRpcAdapter {
                private $reported;
                public function __construct($reported) { $this->reported = $reported; }
                public function ensureLedgerExists(&$failure = null) {
                    $failure = $this->reported;
                    return(false);
                }
                public function recoveryMarkerDiagnostic() { return array(); }
            };
            FileUtil::$messages = array();
            $jResult = '';
            $failure = null;
            $ok = retrackersRunLifecycleInit('alice', '/plugin/run.sh', '/usr/bin/php',
                $jResult, $failure, $adapter);
            $this->assertTrue($ok === false, 'the supplied startup refusal reaches the lifecycle seam');
            $this->assertEquals(array('retrackers-init: ' . retrackersLifecycleDiagnosticMessage('init', $expected)), FileUtil::$messages,
                'startup writes exactly one bounded reason without RPC payload or private path');
            $this->assertTrue(strpos($jResult, $expected) !== false &&
                strpos($jResult, 'tracker.invalid') === false,
                'the UI diagnostic retains the same bounded reason');
        }
    }

    public function testCorruptLedgerDiagnosticNamesMarkerToolAndAffectedTorrent()
    {
        $hash = str_repeat('A', 40);
        $message = retrackersLifecycleDiagnosticMessage('init', 'receipt-ledger-corrupt',
            array('count' => 1, 'hashes' => array($hash, '<?xml secret="passkey">')));
        foreach (array('retrackers is disabled', 'd.custom=retrackers-recovery',
            'torrent session sidecars', 'tools/retrackers-clear-markers.php',
            'report first', 'marked torrents=1', $hash) as $part)
            $this->assertTrue(strpos($message, $part) !== false, 'corrupt ledger diagnostic names ' . $part);
        $this->assertTrue(strpos($message, 'passkey') === false && strpos($message, 'restart rTorrent') === false,
            'classified repair guidance contains no remote payload or false restart remedy');
    }

    public function testHookLedgerMismatchHintIsSpecificAndDoesNotMaskCorruption()
    {
        foreach (array('init', 'done') as $door) {
            $mismatch = retrackersLifecycleDiagnosticJavascript($door,
                'hook-ledger-mismatch-restart-required');
            $this->assertTrue(strpos($mismatch, 'hook-ledger-mismatch-restart-required') !== false &&
                strpos($mismatch, 'restart rTorrent and recheck') !== false,
                $door.' keeps the mismatch code and names the restart remedy');
            $corrupt = retrackersLifecycleDiagnosticJavascript($door, 'receipt-ledger-corrupt');
            $this->assertTrue(strpos($corrupt, 'receipt-ledger-corrupt') !== false &&
                strpos($corrupt, 'restart rTorrent') === false,
                $door.' does not claim a restart repairs structural ledger corruption');
        }
    }
    public function testSharedDaemonRefusalNamesTheCauseAndRecoveryInUiAndLog()
    {
        foreach (array('shared-daemon-contained','shared-daemon-no-owner',
            'shared-daemon-owner-ambiguous','shared-daemon-owner-ambiguous-uncontained',
            'shared-daemon-unowned-mutating') as $reason) {
            $adapter = new class($reason) extends RetrackersLifecycleRpcAdapter {
                private $reason;
                public function __construct($reason) { $this->reason=$reason; }
                public function ensureLedgerExists(&$failure=null) {
                    $failure=$this->reason; return false;
                }
            };
            FileUtil::$messages=array(); $js=''; $failure=null;
            retrackersRunLifecycleInit('alice','/plugin/run.sh','/usr/bin/php',$js,$failure,$adapter);
            foreach (array($js,implode('\n',FileUtil::$messages)) as $message) {
                $this->assertTrue(strpos($message,$reason)!==false &&
                    strpos($message,'multiple ruTorrent profiles')!==false &&
                    strpos($message,'$forbidUserSettings = true')!==false &&
                    strpos($message,'restart rTorrent')!==false,
                    'shared-daemon diagnostics retain the code and state profile unification plus restart');
            }
        }
    }

}
