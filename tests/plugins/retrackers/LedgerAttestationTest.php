<?php
require_once(__DIR__ . '/RetrackersTestStubs.php');
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_PREFLIGHT_TIMEOUT', 0.02);
define('RETRACKERS_PREFLIGHT_POLL', 0.001);
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
        $this->assertTrue($adapter->observerStatuses === array('valid', 'valid', 'valid') && $adapter->mutationCalls === 1,
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

    public function testLedgerReadOutageIsUnconfirmedNotCorrupt()
    {
        $adapter = new LedgerAttestationAdapter();
        $adapter->ledgerFailures = 2;
        $failure = null;
        $this->assertTrue($adapter->attestLedger($failure) instanceof RetrackersHistoricalLedgerAttestation &&
            $adapter->ledgerReads === 3 && $adapter->mutationCalls === 1,
            'a transient ledger read outage is retried before the single probe');
        $adapter = new LedgerAttestationAdapter();
        $adapter->ledgerFailures = PHP_INT_MAX;
        $this->assertTrue($adapter->attestLedger($failure) === false &&
            $failure === 'receipt-ledger-attestation-unconfirmed' && $adapter->privacyCalls === 0 &&
            $adapter->mutationCalls === 0,
            'an unanswered ledger read refuses without a corruption verdict, privacy call or probe');
        $message = retrackersLifecycleDiagnosticMessage('init', $failure);
        $this->assertTrue(strpos($message, 'recheck after SCGI recovers') !== false &&
            strpos($message, 'retrackers is disabled') === false,
            'the init log names an SCGI outage instead of a disabled plugin');
    }

    public function testPrivacyProbeOutageIsUnconfirmedButAPublicLedgerIsCorrupt()
    {
        $adapter = new LedgerAttestationAdapter();
        $adapter->privacyFailures = PHP_INT_MAX;
        $failure = null;
        $this->assertTrue($adapter->attestLedger($failure) === false &&
            $failure === 'receipt-ledger-attestation-unconfirmed' && $adapter->privacyCalls > 1 &&
            $adapter->mutationCalls === 0,
            'an unanswered privacy probe is retried and then refused as unconfirmed, without the probe');
        $adapter = new LedgerAttestationAdapter();
        $adapter->publicLedger = true;
        $this->assertTrue($adapter->attestLedger($failure) === false &&
            $failure === 'receipt-ledger-corrupt' && $adapter->privacyCalls === 1 && $adapter->mutationCalls === 0,
            'a ledger whose value answers a direct call is a wrong answer and keeps its corruption verdict');
    }

    public function testV1CleanTransactionReadOutageIsUnconfirmedNotCorrupt()
    {
        // The 0.9.8 worker reads the ledger once before adopting its handoff.
        $adapter = new LedgerAttestationAdapter();
        $adapter->ledgerFailures = 1;
        $failure = null;
        $this->assertTrue(RetrackersRecoveryCoordinator::generateCleanTx($adapter, $failure) === false &&
            $failure === 'receipt-ledger-attestation-unconfirmed' && $adapter->ledgerReads === 1,
            'an unanswered ledger read is unconfirmed, not a corrupt ledger, and is read once');
        $adapter = new LedgerAttestationAdapter();
        $adapter->safe = false;
        $this->assertTrue(RetrackersRecoveryCoordinator::generateCleanTx($adapter, $failure) === false &&
            $failure === 'receipt-ledger-corrupt', 'a wrong ledger answer keeps its corruption verdict');
        $adapter = new LedgerAttestationAdapter();
        $tx = RetrackersRecoveryCoordinator::generateCleanTx($adapter, $failure);
        $this->assertTrue(is_string($tx) && preg_match('/^[0-9a-f]{32}$/D', $tx) === 1 && $failure === null,
            'a readable ledger yields a fresh transaction');
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
    public $ledgerReads = 0, $ledgerFailures = 0, $privacyFailures = 0, $publicLedger = false;
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
    // attestLedger sends exactly three request kinds; no direct has_key or set_key.
    protected function send($payload, $plan, &$failure = null, $historical = false)
    {
        $failure = null;
        if (strpos($payload, '<methodName>system.multicall</methodName>') !== false) {
            $this->ledgerReads++;
            if ($this->ledgerFailures-- > 0) { $failure = 'connect-failed'; return false; }
            // The real decoder rejects an unsafe ledger value as a malformed answer.
            if (!$this->safe) { $failure = 'malformed-response'; return false; }
            $value = array('ok' => true, 'family' => 2, 'value' => array(array('value' => array()), array('value' => array())));
        } elseif (strpos($payload, '<methodName>rr.receipts.v1</methodName>') !== false) {
            $this->privacyCalls++;
            if ($this->privacyFailures-- > 0) { $failure = 'read-timeout'; return false; }
            $value = $this->publicLedger ? array('ok' => true, 'family' => 2, 'value' => '0') :
                array('ok' => false, 'family' => 2, 'fault' => array('code' => '-506', 'code_type' => 'i8', 'message' => 'Method is private.'));
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
