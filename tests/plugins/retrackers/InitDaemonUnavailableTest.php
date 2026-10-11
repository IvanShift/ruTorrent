<?php

require_once(__DIR__ . '/RetrackersTestStubs.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');
require_once(__DIR__ . '/../../php/SCGITransportFixture.php');
require_once(__DIR__ . '/../../../php/scgitransport.php');
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_PREFLIGHT_TIMEOUT', 0.05);
define('RETRACKERS_PREFLIGHT_POLL', 0.001);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');
require_once(__DIR__ . '/RetrackersBindingSample.php');

// The ledger insert travels the production adapter, codec and SCGI transport.
// Every later init step is answered in memory from an installed idle profile,
// so a passing init proves that the ledger step let it through.
class InitDaemonUnavailableAdapter extends RetrackersLifecycleRpcAdapter
{
	public $sample;
	public $arms = 0;
	public $samples = 0;

	public function __construct($sample)
	{
		$this->sample = $sample;
	}
	public function nativeOriginalCapability(&$failure = null) { $failure = null; return(true); }
	public function nativeQueueCapability(&$failure = null) { $failure = null; return(true); }
	public function attestLedger(&$failure = null)
	{
		$failure = null;
		return(new RetrackersHistoricalLedgerAttestation(true, true, true, true));
	}
	public function historicalBindingSample(&$failure = null)
	{
		$this->samples++;
		$failure = null;
		return($this->sample);
	}
	public function armNativeQueueDispatcher($user, &$failure = null)
	{
		$this->arms++;
		$failure = null;
		return(true);
	}
}

// Answers every ledger step before $wire in memory, as an answered family-2
// insert would leave them, so the first SCGI request of the init (or of done)
// is the step under test: 'capability', 'keys' or 'privacy'.
class InitMidHeadersAdapter extends RetrackersLifecycleRpcAdapter
{
	public $wire;
	public $samples = 0;

	public function __construct($wire)
	{
		$this->wire = $wire;
	}
	public function ensureLedgerExists(&$failure = null)
	{
		$this->setFamily(2);
		$failure = null;
		return(true);
	}
	public function nativeOriginalCapability(&$failure = null)
	{
		if ($this->wire === 'capability') return(parent::nativeOriginalCapability($failure));
		$failure = null;
		return(true);
	}
	public function nativeQueueCapability(&$failure = null) { $failure = null; return(true); }
	public function getLedgerKeys(&$failure = null)
	{
		if ($this->wire === 'keys') return(parent::getLedgerKeys($failure));
		$failure = null;
		return(array());
	}
	public function historicalBindingSample(&$failure = null)
	{
		$this->samples++;
		$failure = 'connect-failed';
		return(false);
	}
}

// A ledger attested in memory: done's first SCGI request is its binding sample.
class DoneSampleAdapter extends RetrackersLifecycleRpcAdapter
{
	public function attestLedger(&$failure = null)
	{
		$failure = null;
		return(new RetrackersHistoricalLedgerAttestation(true, true, true, true));
	}
}

/**
 * POST-1011-03: while rTorrent stopped, init logged "retrackers-init:
 * receipt-ledger-corrupt; retrackers is disabled; ..." with no marker suffix.
 * The first init RPC, the rr.receipts.v1 insert, had no answer at all, and
 * ensureLedgerExists() turned that into a corruption verdict. No answer says
 * nothing about the ledger: it is one classified line, the insert is not
 * resent, and a wrong answer keeps the corruption verdict and its markers.
 * A daemon that closes part-way through its response headers
 * (truncated-headers) has not answered either; the delimiter that ends the
 * headers is the boundary between no answer and a wrong one. The same holds
 * for done's notice and for the ledger read of the v1 (0.9.8) worker.
 */
class InitDaemonUnavailableTest extends TestCase
{
	// rTorrent 0.16 (TinyXML2) answers, byte for byte as captured in
	// UpdateTest: rt1621-mutating-zero and rt1621-direct-fault.
	const INSERTED = '<?xml version="1.0"?><methodResponse><params><param><value><i8>0</i8></value></param></params></methodResponse>';
	const FOREIGN_FAULT = '<?xml version="1.0"?><methodResponse><fault><value><struct><member><name>faultCode</name><value><i8>-500</i8></value></member><member><name>faultString</name><value><string>invalid parameters: info-hash not found</string></value></member></struct></value></fault></methodResponse>';
	// rTorrent's SCGI reply prefix: header_xml in src/rpc/scgi_task.h on
	// upstream master (7ee4b147d9b0); v0.9.8 sprintf()s the same bytes. The
	// headers end only at "\r\n\r\n" (header_last).
	const HEADER_XML = "Status: 200 OK\r\nContent-Type: text/xml\r\nContent-Length: ";

	private $dir;
	private $daemons = array();
	private $peers = array();

	public function setUp()
	{
		global $scgi_host, $scgi_port;
		$this->dir = sys_get_temp_dir() . '/rt-init-unavail-' . bin2hex(random_bytes(6));
		mkdir($this->dir, 0700);
		// Nothing listens here: the SCGI connect itself fails.
		$scgi_host = 'unix://' . $this->dir . '/absent.sock';
		$scgi_port = 0;
		FileUtil::$messages = array();
	}

	public function tearDown()
	{
		foreach ($this->daemons as $daemon) $daemon->stop();
		$this->daemons = array();
		foreach ($this->peers as $peer) $peer->close();
		$this->peers = array();
		foreach ((array)glob($this->dir . '/*') as $file) @unlink($file);
		@rmdir($this->dir);
	}

	private function daemon(array $plan)
	{
		global $scgi_host, $scgi_port;
		$daemon = new FakeRtorrentDaemon($plan, $this->dir . '/daemon-' . count($this->daemons));
		$this->daemons[] = $daemon;
		$scgi_host = '127.0.0.1';
		$scgi_port = $daemon->port();
		return($daemon);
	}

	// One SCGI connection answered with these exact bytes, then closed. The
	// peer listens for that one request only, so any later request fails with
	// another class and replaces the one the peer produced.
	private function peer($frame)
	{
		global $scgi_host, $scgi_port;
		$peer = SCGITransportFixture::start($frame);
		$this->peers[] = $peer;
		$scgi_host = $peer->host();
		$scgi_port = $peer->port();
		return($peer);
	}

	// rTorrent's reply to the insert, cut before the delimiter that ends its
	// headers: the daemon stopped part-way through the response.
	private static function cutMidHeaders()
	{
		return(self::HEADER_XML . strlen(self::INSERTED));
	}

	private static function settings()
	{
		return((object)array('list' => array(array('http://retracker.invalid/announce')),
			'todelete' => array(), 'dontAddPrivate' => 0));
	}

	private static function installed()
	{
		return(RetrackersBindingSample::idle('alice', retrackersBuildInsertAction(
			'/plugin/run.sh', '/usr/bin/php', 'alice', true, true, self::settings())));
	}

	private function init($adapter, &$failure, &$jResult)
	{
		$jResult = '';
		$failure = null;
		return(retrackersRunLifecycleInit('alice', '/plugin/run.sh', '/usr/bin/php',
			$jResult, $failure, $adapter, self::settings()));
	}

	private function assertDaemonUnavailable($ok, $failure, $jResult, $label)
	{
		$this->assertTrue($ok === false && in_array($failure, RetrackersBoundedRead::TRANSPORT_FAILURES, true),
			$label . ': init refuses and keeps the SCGI classification ' . var_export($failure, true));
		$this->assertEquals(array('retrackers-init: daemon-unavailable; retried at the next init after SCGI recovers'), FileUtil::$messages,
			$label . ': one concise classified line, no corruption verdict or repair instruction');
		$this->assertTrue(strpos($jResult, 'retrackers: daemon-unavailable') !== false &&
			strpos($jResult, '; retried at the next init after SCGI recovers') !== false &&
			strpos($jResult, 'receipt-ledger-corrupt') === false &&
			strpos($jResult, 'retrackers-clear-markers') === false,
			$label . ': the WebUI notice carries the same class');
	}

	public function testUnreachableDaemonIsDaemonUnavailableNotACorruptLedger()
	{
		$adapter = new InitDaemonUnavailableAdapter(self::installed());
		$ok = $this->init($adapter, $failure, $jResult);
		$this->assertTrue($failure === 'connect-failed', 'the fixture really refuses the connection');
		$this->assertDaemonUnavailable($ok, $failure, $jResult, 'connect-failed');
		$this->assertTrue($adapter->samples === 0,
			'no marker diagnostic is sampled for a daemon that did not answer');
	}

	public function testInsertClosedBeforeHeadersIsDaemonUnavailableAndNotResent()
	{
		// A second planned answer records any further request the init makes.
		$daemon = $this->daemon(array(null, new FakeRtorrentRawReply(self::INSERTED)));
		$ok = $this->init(new RetrackersLifecycleRpcAdapter(), $failure, $jResult);
		$this->assertTrue($failure === 'closed-before-headers',
			'the fixture really closes after reading the insert');
		$this->assertDaemonUnavailable($ok, $failure, $jResult, 'closed-before-headers');
		$this->assertTrue($daemon->calls() === array('method.insert'),
			'the unknown insert is sent once; neither a resend nor a marker sample follows: ' .
			implode(',', $daemon->calls()));
	}

	public function testInsertCutMidHeadersIsDaemonUnavailableAndNotResent()
	{
		$peer = $this->peer(self::cutMidHeaders());
		$ok = $this->init(new RetrackersLifecycleRpcAdapter(), $failure, $jResult);
		$request = $peer->request();
		$this->assertTrue(strpos($request['payload'], '<methodName>method.insert</methodName>') !== false &&
			strpos($request['payload'], 'rr.receipts.v1') !== false,
			'the one request the peer read is the ledger insert');
		// The peer serves one connection: a resent insert would replace this
		// class with the failure of its own request.
		$this->assertTrue($failure === 'truncated-headers',
			'the peer really stops mid-headers and nothing follows the insert: ' . var_export($failure, true));
		$this->assertDaemonUnavailable($ok, $failure, $jResult, 'truncated-headers');
	}

	public function testCompleteHeadersWithoutContentLengthStayACorruptionVerdict()
	{
		// The same reply with its headers ended by the delimiter but no
		// Content-Length: a complete frame that is wrong, not a missing one.
		$hash = str_repeat('A', 40);
		$marked = RetrackersBindingSample::make(array(), array(),
			array(array($hash, str_repeat('B', 40), 'v9:unknown', '')));
		$peer = $this->peer("Status: 200 OK\r\nContent-Type: text/xml\r\n\r\n" . self::INSERTED);
		$adapter = new InitDaemonUnavailableAdapter($marked);
		$ok = $this->init($adapter, $failure, $jResult);
		$peer->request();
		$expected = 'retrackers-init: ' . retrackersLifecycleDiagnosticMessage('init',
			'receipt-ledger-corrupt', array('count' => 1, 'hashes' => array($hash)));
		$this->assertTrue($ok === false && $failure === 'receipt-ledger-corrupt' &&
			FileUtil::$messages === array($expected) && $adapter->samples === 1,
			'a complete wrong frame keeps the corruption verdict and its markers: ' .
			var_export($failure, true) . ' ' . implode(' | ', FileUtil::$messages));
	}

	public function testLaterInitStepsCutMidHeadersGiveNoCorruptionVerdict()
	{
		foreach (array(
			// A raw SCGI class reaches the init log through the capability read.
			'capability' => 'daemon-unavailable',
			// The attestation reads classify their own unknown reply.
			'keys' => 'receipt-ledger-attestation-unconfirmed',
			'privacy' => 'receipt-ledger-attestation-unconfirmed',
		) as $wire => $reason) {
			FileUtil::$messages = array();
			$this->peer(self::cutMidHeaders());
			$adapter = new InitMidHeadersAdapter($wire);
			$ok = $this->init($adapter, $failure, $jResult);
			$expected = 'retrackers-init: ' . retrackersLifecycleDiagnosticMessage('init', $reason);
			$this->assertTrue($ok === false && FileUtil::$messages === array($expected) &&
				strpos($jResult, 'retrackers: ' . $reason) !== false &&
				strpos($jResult, 'receipt-ledger-corrupt') === false && $adapter->samples === 0,
				$wire . ': a reply cut mid-headers is ' . $reason . ', with no corruption verdict or marker sample: ' .
				var_export($failure, true) . ' ' . implode(' | ', FileUtil::$messages));
		}
	}

	public function testDoneAttestationCutMidHeadersGivesNoCorruptionVerdict()
	{
		$this->peer(self::cutMidHeaders());
		$jResult = '';
		$failure = null;
		$ok = retrackersRunLifecycleDone('alice', $jResult, $failure, new InitMidHeadersAdapter('keys'));
		$this->assertTrue($ok === false && $failure === 'receipt-ledger-attestation-unconfirmed' &&
			$jResult === retrackersLifecycleDiagnosticJavascript('done', 'receipt-ledger-attestation-unconfirmed'),
			'done refuses on an unknown attestation reply without a corruption notice: ' .
			var_export($failure, true) . ' ' . $jResult);
	}

	public function testDoneSampleWithoutAnswerIsDaemonUnavailable()
	{
		foreach (array('connect-failed', 'truncated-headers') as $class) {
			// setUp leaves an absent socket; the second case cuts the sample's reply.
			if ($class === 'truncated-headers') $this->peer(self::cutMidHeaders());
			$jResult = '';
			$failure = null;
			$ok = retrackersRunLifecycleDone('alice', $jResult, $failure, new DoneSampleAdapter());
			$this->assertTrue($ok === false && $failure === $class &&
				$jResult === retrackersLifecycleDiagnosticJavascript('done', 'daemon-unavailable'),
				$class . ': done keeps the SCGI class and its notice says daemon-unavailable: ' .
				var_export($failure, true) . ' ' . $jResult);
		}
	}

	public function testV1WorkerLedgerReadCutMidHeadersIsUnconfirmed()
	{
		$peer = $this->peer(self::cutMidHeaders());
		$failure = null;
		$tx = RetrackersRecoveryCoordinator::generateCleanTx(new RetrackersWorkerRpcAdapter(), $failure);
		$request = $peer->request();
		$this->assertTrue(strpos($request['payload'], 'method.list_keys') !== false &&
			strpos($request['payload'], 'rr.receipts.v1') !== false,
			'the one request the peer read is the worker ledger read');
		$this->assertTrue($tx === false && $failure === 'receipt-ledger-attestation-unconfirmed',
			'a ledger read cut mid-headers is unconfirmed, not a corrupt ledger: ' . var_export($failure, true));
	}

	public function testDaemonStoppingAfterTheInsertLogsTheSameClass()
	{
		// The insert is answered; the capability read that follows is not.
		$daemon = $this->daemon(array(new FakeRtorrentRawReply(self::INSERTED), null,
			new FakeRtorrentRawReply(self::INSERTED)));
		$ok = $this->init(new RetrackersLifecycleRpcAdapter(), $failure, $jResult);
		$this->assertTrue($failure === 'closed-before-headers',
			'the fixture really closes after reading the capability request');
		$this->assertDaemonUnavailable($ok, $failure, $jResult, 'capability read');
		$this->assertTrue($daemon->calls() === array('method.insert', 'system.listMethods'),
			'the init stops at the unanswered read: ' . implode(',', $daemon->calls()));
	}

	public function testNextInitAfterTheDaemonReturnsProceeds()
	{
		$adapter = new InitDaemonUnavailableAdapter(self::installed());
		$this->assertDaemonUnavailable($this->init($adapter, $failure, $jResult),
			$failure, $jResult, 'stopped daemon');
		FileUtil::$messages = array();
		$daemon = $this->daemon(array(new FakeRtorrentRawReply(self::INSERTED)));
		$ok = $this->init($adapter, $failure, $jResult);
		$this->assertTrue($ok === true && $failure === null && FileUtil::$messages === array() &&
			$jResult === '' && $adapter->arms === 1,
			'the refusal left nothing behind: the next init installs the profile and arms the queue');
		// UpdateTest's first-insertion family case pins the family binding.
		$this->assertTrue($daemon->calls() === array('method.insert'),
			'the next init inserts the ledger once');
	}

	public function testWrongLedgerAnswerStillReportsCorruptionWithMarkers()
	{
		$hash = str_repeat('A', 40);
		// One torrent still carries an unrecognized retrackers-recovery marker.
		$marked = RetrackersBindingSample::make(array(), array(),
			array(array($hash, str_repeat('B', 40), 'v9:unknown', '')));
		foreach (array(
			'foreign fault' => self::FOREIGN_FAULT,
			'malformed body' => self::INSERTED . 'x',
		) as $label => $answer) {
			FileUtil::$messages = array();
			$daemon = $this->daemon(array(new FakeRtorrentRawReply($answer)));
			$adapter = new InitDaemonUnavailableAdapter($marked);
			$ok = $this->init($adapter, $failure, $jResult);
			$expected = 'retrackers-init: ' . retrackersLifecycleDiagnosticMessage('init',
				'receipt-ledger-corrupt', array('count' => 1, 'hashes' => array($hash)));
			$this->assertTrue($ok === false && $failure === 'receipt-ledger-corrupt' &&
				FileUtil::$messages === array($expected) &&
				strpos($expected, 'marked torrents=1; hashes=' . $hash) !== false,
				$label . ': a wrong insert answer remains a corruption verdict with its marked torrents: ' .
				implode(' | ', FileUtil::$messages));
			$this->assertTrue($daemon->calls() === array('method.insert') && $adapter->samples === 1,
				$label . ': one insert, then only the read-only marker sample');
		}
	}

	public function testWebUiAndCliInitShareTheClassifiedSeam()
	{
		$root = __DIR__ . '/../../..';
		$init = file_get_contents($root . '/plugins/retrackers/init.php');
		$this->assertTrue(preg_match('/= new RetrackersLifecycleRpcAdapter\(\);\s*\$ok = retrackersRunLifecycleInit\(/', $init) === 1,
			'init.php hands the production adapter to the one classified init seam');
		// getplugins.php (WebUI) and initplugins.php (rTorrent start, CLI) both
		// run that same init.php, so they log the same class.
		foreach (array('getplugins.php', 'initplugins.php') as $door) {
			$this->assertTrue(strpos(file_get_contents($root . '/php/' . $door),
				'$php = "../plugins/".$file."/init.php";') !== false,
				$door . ' loads each plugin through its init.php');
		}
	}
}
