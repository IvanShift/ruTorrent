<?php

require_once(__DIR__ . '/RetrackersTestStubs.php');
define('RETRACKERS_IMPORT_ONLY', true);
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

	public function testTheTransportListPartitionsEveryScgiFailureCode()
	{
		$source = file_get_contents(__DIR__ . '/../../../php/scgitransport.php');
		preg_match_all("~(?:fail\\(\\\$failure, |\\\$failure = |readFailure\\([^,]+, [^,]+, \\\$failure, )'([a-z-]+)'~", $source, $matches);
		$codes = array_values(array_unique($matches[1]));
		sort($codes);
		$this->assertTrue(count($codes) >= 15, 'the scan reads the transport vocabulary: ' . implode(',', $codes));
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
		}
		foreach (self::PROTOCOL as $code)
			$this->assertTrue(RetrackersQueueRetry::category($code) === 'preflight-refused', $code . ' is a counted refusal');
	}
}
