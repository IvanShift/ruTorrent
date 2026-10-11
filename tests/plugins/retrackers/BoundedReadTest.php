<?php

require_once(__DIR__ . '/RetrackersTestStubs.php');
require_once(__DIR__ . '/../../php/SCGITransportFixture.php');
require_once(__DIR__ . '/../../../php/scgitransport.php');
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_PREFLIGHT_TIMEOUT', 0.025);
define('RETRACKERS_PREFLIGHT_POLL', 0.001);
define('RETRACKERS_READ_RETRY_PAUSE_MAX', 0.004);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');

/** The one read-only retry helper and the one transport vocabulary (QUAL-RU-09). */
class BoundedReadTest extends TestCase
{
	// rSCGITransport codes that describe a wrong or refused answer, not a missing one.
	const PROTOCOL = array('empty-request', 'invalid-response-mode', 'invalid-response-limit',
		'headers-too-large', 'malformed-header', 'missing-content-length', 'duplicate-content-length',
		'malformed-content-length', 'zero-content-length', 'response-too-large');

	private static function failureCodes($source)
	{
		// Read the full failure expression: a ternary may name either outcome.
		preg_match_all('~(?:fail\(\s*\$failure,\s*|\$failure\s*=\s*|readFailure\([^;]*?\$failure,\s*)([^;]+)~',
			$source, $expressions);
		$codes = array();
		foreach ($expressions[1] as $expression) {
			preg_match_all("~'([a-z-]+)'~", $expression, $matches);
			$codes = array_merge($codes, $matches[1]);
		}
		$codes = array_values(array_unique($codes));
		sort($codes);
		return($codes);
	}

	public function testTheTransportListPartitionsEveryScgiFailureCode()
	{
		$source = file_get_contents(__DIR__ . '/../../../php/scgitransport.php');
		$codes = self::failureCodes($source);
		$this->assertTrue(count($codes) >= 15, 'the scan reads the transport vocabulary: ' . implode(',', $codes));
		$this->assertTrue(in_array('closed-before-headers', $codes, true) && in_array('truncated-headers', $codes, true),
			'the scan includes both branches of the production readFailure ternary');
		$noAnswer = array_values(array_intersect($codes, RetrackersBoundedRead::TRANSPORT_FAILURES));
		$classified = array_merge($noAnswer, self::PROTOCOL);
		sort($classified);
		$this->assertTrue($classified === $codes && array_intersect(self::PROTOCOL, RetrackersBoundedRead::TRANSPORT_FAILURES) === array(),
			'every rSCGITransport code is either no answer or a wrong answer: ' .
			implode(',', array_diff($codes, $classified)));
	}

	public function testRetryHonoursAnswersBudgetsAndRetryableFailures()
	{
		$reads = 0;
		$failure = null;
		$value = RetrackersBoundedRead::retry(function (&$failure) use (&$reads) {
			if (++$reads < 3) { $failure = 'read-timeout'; return(false); }
			$failure = null;
			return('');
		}, $failure, 1.0);
		$this->assertTrue($value === '' && $reads === 3 && $failure === null,
			'an empty answer is an answer; transport failures are retried before it');
		$reads = 0;
		$value = RetrackersBoundedRead::retry(function (&$failure) use (&$reads) {
			$reads++;
			$failure = 'malformed-response';
			return(false);
		}, $failure, 1.0);
		$this->assertTrue($value === false && $reads === 1 && $failure === 'malformed-response',
			'a wrong answer is never retried');
		$reads = 0;
		$started = microtime(true);
		RetrackersBoundedRead::retry(function (&$failure) use (&$reads) {
			$reads++;
			$failure = $reads < 4 ? 'long' : 'short';
			return(false);
		}, $failure, function ($reason) { return($reason === 'short' ? 0.0 : 0.5); }, array('short', 'long'));
		$this->assertTrue($reads === 4 && microtime(true) - $started < 0.5,
			'a per-reason budget is measured from the first read and follows the current reason');
	}

	public function testCategoryAndDefaultRetryShareOneTransportList()
	{
		foreach (RetrackersBoundedRead::TRANSPORT_FAILURES as $code) {
			$reads = 0;
			$failure = null;
			RetrackersBoundedRead::retry(function (&$failure) use (&$reads, $code) {
				$failure = ++$reads < 2 ? $code : null;
				return($reads < 2 ? false : true);
			}, $failure, 1.0);
			$this->assertTrue($reads === 2 && RetrackersQueueRetry::category($code) === 'transport-unavailable',
				$code . ' is retried by default and deferred as a transport wait');
			$this->assertTrue(RetrackersQueueRetry::waitSeconds($code) === RETRACKERS_RETRY_WAIT_TRANSPORT &&
				retrackersBoundedFailureReason($code) === $code,
				$code . ' keeps its transport wait interval and classified diagnostic');
		}
		foreach (self::PROTOCOL as $code)
			$this->assertTrue(RetrackersQueueRetry::category($code) === 'preflight-refused', $code . ' is a counted refusal');
	}

	public function testSourceSnapshotsKeepCompleteWrongFramesOutOfTransportRetries()
	{
		global $scgi_host, $scgi_port;
		$hash = str_repeat('A', 40);
		$handoff = 'v2:original:0:' . str_repeat('B', 40) . ':' . str_repeat('c', 64) . ':' . str_repeat('d', 32);
		foreach (array(
			'missing-content-length' => "Status: 200 OK\r\nContent-Type: text/xml\r\n\r\nwrong",
			'malformed-content-length' => "Status: 200 OK\r\nContent-Length: nope\r\n\r\nwrong",
			'duplicate-content-length' => "Status: 200 OK\r\nContent-Length: 5\r\nContent-Length: 5\r\n\r\nwrong",
		) as $code => $frame) {
			foreach (array('direct', 'capture', 'observe') as $operation) {
				$peer = SCGITransportFixture::start($frame);
				$scgi_host = $peer->host(); $scgi_port = $peer->port();
				try {
					$adapter = new RetrackersDirectRpcAdapter();
					$failure = null;
					if ($operation === 'direct') {
						$this->assertTrue($adapter->sourceScalarSnapshot($hash, $failure) === false && $failure === $code,
							'the actual transport identifies the complete wrong frame as ' . $code);
					} else {
						$snapshot = new class($adapter) extends RetrackersStableSourceSnapshot {
							public $reads = 0;
							public function capture($hash, $handoff) {
								$this->reads++;
								return(parent::capture($hash, $handoff));
							}
						};
						if ($operation === 'capture') {
							$method = new ReflectionMethod('RetrackersRecoveryCoordinator', 'captureBeforeMutation');
							if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
							$result = $method->invoke(null, $snapshot, $hash, $handoff, new RetrackersWorkerRpcAdapter());
						} else {
							$result = $snapshot->observe($hash);
						}
						$failure = isset($result['failure']) ? $result['failure'] : null;
						$this->assertTrue(($operation !== 'capture' || $snapshot->reads === 1) && $failure === 'initial-malformed' &&
							RetrackersQueueRetry::category($failure) === 'preflight-refused',
							$operation . ': ' . $code . ' is a counted refusal without a transport retry');
					}
					$request = $peer->request();
					$this->assertTrue(strpos($request['payload'], '<methodName>system.multicall</methodName>') !== false,
						$operation . ': the production scalar snapshot request reached the peer');
				} finally {
					$peer->close();
				}
			}
		}
	}

	public function testSourceSnapshotsReserveTransportClassificationForKnownNoAnswerCodes()
	{
		$adapter = new class extends RetrackersDirectRpcAdapter {
			public $reason;
			public function sourceScalarSnapshot($hash, &$failure = null) {
				$failure = $this->reason;
				return(false);
			}
		};
		$snapshot = new RetrackersStableSourceSnapshot($adapter);
		foreach (array_merge(RetrackersBoundedRead::TRANSPORT_FAILURES, self::PROTOCOL,
			array('malformed-response', 'invalid-request', 'target-absent', 'rpc-fault', null, 'unknown-class')) as $code) {
			$adapter->reason = $code;
			$result = $snapshot->capture(str_repeat('A', 40), 'v2:original:0:' . str_repeat('B', 40) . ':' .
				str_repeat('c', 64) . ':' . str_repeat('d', 32));
			$expected = $code === 'target-absent' ? 'initial-absent' :
				($code === 'rpc-fault' ? 'initial-fault' :
					(in_array($code, RetrackersBoundedRead::TRANSPORT_FAILURES, true) ? 'initial-transport' : 'initial-malformed'));
			$this->assertTrue($result === array('ok' => false, 'failure' => $expected),
				'the source snapshot maps ' . var_export($code, true) . ' to ' . $expected);
		}
	}
}
