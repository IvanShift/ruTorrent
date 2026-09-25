<?php

require_once(__DIR__ . '/../../php/TestCase.php');

function getCmd($command) { return $command; }
class rTorrent
{
    public static function quoteCommandArg($value)
    {
        return('"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $value) . '"');
    }
}
class FileUtil
{
    public static $logs = array();
    public static function toLog($message) { self::$logs[] = $message; }
}

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
            };
            FileUtil::$logs = array();
            $jResult = '';
            $failure = null;
            $ok = retrackersRunLifecycleInit('alice', '/plugin/run.sh', '/usr/bin/php',
                $jResult, $failure, $adapter);
            $this->assertTrue($ok === false, 'the supplied startup refusal reaches the lifecycle seam');
            $this->assertEquals(array('retrackers-init: ' . $expected), FileUtil::$logs,
                'startup writes exactly one bounded reason without RPC payload or private path');
            $this->assertTrue(strpos($jResult, $expected) !== false &&
                strpos($jResult, 'tracker.invalid') === false,
                'the UI diagnostic retains the same bounded reason');
        }
    }

    public function testInPlaceUpgradeHintIsConditionalAndDoesNotReplaceTheFailureCode()
    {
        $legacy = retrackersLifecycleDiagnosticJavascript('init', 'receipt-ledger-corrupt');
        $this->assertTrue(strpos($legacy, 'receipt-ledger-corrupt') !== false &&
            strpos($legacy, 'if an in-place upgrade left old hooks, restart rTorrent and recheck') !== false,
            'init keeps the classified refusal and names the conditional legacy-hook remedy');
        $other = retrackersLifecycleDiagnosticJavascript('done', 'receipt-ledger-corrupt');
        $this->assertTrue(strpos($other, 'restart rTorrent') === false,
            'the upgrade hint is limited to the startup path with the documented old-hook case');
    }
}
