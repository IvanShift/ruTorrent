<?php
require_once(__DIR__ . '/../../php/TestCase.php');
class rTorrent { public static function quoteCommandArg($value) { return '"' . addcslashes((string)$value, "\\\"") . '"'; } }
define('RETRACKERS_IMPORT_ONLY', true);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');

class LedgerAttestationTest extends TestCase
{
    public function testProbeCannotBecomeAReceiptBetweenDaemonRequests()
    {
        $adapter = new LedgerAttestationAdapter();
        $failure = null;
        $result = $adapter->attestLedger($failure);
        $this->assertTrue($result instanceof RetrackersHistoricalLedgerAttestation && $failure === null,
            'a private writable ledger completes both probe reuse cycles');
        $this->assertTrue($adapter->observerStatuses === array('valid') && $adapter->mutationCalls === 1,
            'a concurrent initializer never observes a temporary probe without an epoch between daemon calls');
    }

    public function testUncertainProbeReplyNeverReplaysOrClaimsCorruption()
    {
        foreach (array(false, 'opaque', array('ok' => false, 'family' => 2, 'fault' => array())) as $reply) {
            $adapter = new LedgerAttestationAdapter();
            $adapter->reply = $reply;
            $failure = null;
            $this->assertTrue($adapter->attestLedger($failure) === false &&
                $failure === 'receipt-ledger-attestation-unconfirmed' && $adapter->mutationCalls === 1,
                'lost, opaque and fault replies refuse attestation without mutation replay or an invented corruption verdict');
        }
    }

    public function testKnownProbeRefusalCannotGrantWriterTrust()
    {
        foreach (array('CHANGED', '0010ACQUIRED', '1011ACQUIRED') as $reply) {
            $adapter = new LedgerAttestationAdapter();
            $adapter->reply = $reply;
            $failure = null;
            $this->assertTrue($adapter->attestLedger($failure) === false &&
                $failure === 'receipt-ledger-corrupt' && $adapter->mutationCalls === 1,
                'an existing nonce or failed insert/delete proof refuses writer trust without another attempt');
        }
    }

    public function testUnsafeLedgerStillRefusesBeforePrivacyOrProbe()
    {
        $adapter = new LedgerAttestationAdapter();
        $adapter->safe = false;
        $failure = null;
        $this->assertTrue($adapter->attestLedger($failure) === false &&
            $failure === 'receipt-ledger-corrupt' && $adapter->privacyCalls === 0 && $adapter->mutationCalls === 0,
            'coherent key/value validation remains ahead of private-method invocation and all probe writes');
    }

    public function testNakedEraseBeginReceiptStillRequiresAnEpoch()
    {
        // Minimized from the synthetic 405-torrent capture in local scale-init03-diagnostic:
        // PID198 sampled PID232's eb nonce before bootstrap pv, then sampled an empty ledger.
        $classifier = new RetrackersHistoricalBindingClassifier();
        $this->assertTrue($classifier->classify(LedgerAttestationAdapter::sample(true), '')['status'] === 'ledger-corrupt' &&
            $classifier->classify(LedgerAttestationAdapter::sample(false), '')['status'] === 'valid',
            'atomic attestation does not whitelist a real erase-begin receipt in a ledger with no epoch');
    }
}

class LedgerAttestationAdapter extends RetrackersLifecycleRpcAdapter
{
    public $safe = true, $privacyCalls = 0, $mutationCalls = 0, $observerStatuses = array();
    public $reply = '1010ACQUIRED';
    private $published = false;
    public function __construct() { $this->family = 2; }
    public static function sample($published)
    {
        $value = array_fill_keys(array('digest', 'event_key_digest', 'event_map_digest',
            'recovery_rows_digest', 'ledger_key_digest', 'ledger_map_digest', 'ledger_digest'), str_repeat('a', 64));
        $value += array('event_keys' => array(), 'actions' => array(), 'recovery_rows' => "RR4\0" . pack('N', 0),
            'ledger_keys' => $published ? array('eb:f5de5e172de8b9ff782afd2994298461') : array(),
            'counts' => array('event_keys' => 0, 'actions' => 0, 'recovery_rows' => 0, 'ledger_keys' => $published ? 1 : 0),
            'production_accepted' => false);
        return array('ok' => true, 'family' => 2, 'value' => $value);
    }
    public function getLedgerKeys(&$failure = null) { $failure = $this->safe ? null : 'receipt-ledger-corrupt'; return $this->safe ? array() : false; }
    public function directPrivacyCheck(&$failure = null) { $this->privacyCalls++; $failure = null; return true; }
    protected function send($payload, $plan, &$failure = null, $historical = false)
    {
        $failure = null;
        if (strpos($payload, '<methodName>method.has_key</methodName>') !== false) {
            $value = $this->published ? '1' : '0';
        } elseif (strpos($payload, '<methodName>method.set_key</methodName>') !== false) {
            preg_match_all('~<string>(.*?)</string>~s', $payload, $parts);
            $this->published = count($parts[1]) === 4;
            $this->mutationCalls++;
            $value = '0';
        } elseif (strpos($payload, '<methodName>branch</methodName>') !== false) {
            // One synchronous daemon callback is one observer boundary. Its command
            // grammar and nonce collision are also exercised against the real daemon.
            $this->mutationCalls++;
            $value = $this->reply;
        } else throw new RuntimeException('unexpected ledger attestation RPC');
        $this->observerStatuses[] = (new RetrackersHistoricalBindingClassifier())->classify(self::sample($this->published), '')['status'];
        if ($value === false) { $failure = 'closed-before-headers'; return false; }
        return is_array($value) ? $value : array('ok' => true, 'family' => 2, 'value' => $value);
    }
}
