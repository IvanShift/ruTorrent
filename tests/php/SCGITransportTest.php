<?php

require_once(__DIR__.'/TestCase.php');
require_once(__DIR__.'/SCGITransportFixture.php');

$transport = __DIR__.'/../../php/scgitransport.php';
if(is_file($transport))
	require_once($transport);
require_once(__DIR__.'/../../php/xmlrpc.php');

if(class_exists('rSCGITransport'))
{
	/** Deterministic seams for failure/deadline branches; send() stays real. */
	class SCGITransportInjected extends rSCGITransport
	{
		public static $mode;
		public static $now;
		public static $peer;
		public static $readTimeout;
		public static $readQueue;
		public static $step;
		public static $writeSize;

		public static function prepare($mode)
		{
			self::finish();
			self::$mode = $mode;
			self::$now = 0.0;
			self::$step = 0.0;
			self::$writeSize = 65536;
			self::$readQueue = array("Content-Length: 1\r\n\r\nx");
			self::$readTimeout = null;
		}

		public static function finish()
		{
			if(is_resource(self::$peer))
				fclose(self::$peer);
			self::$peer = null;
		}

		protected static function openSocket($address, &$errno, &$error, $timeout)
		{
			$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
			if($pair === false)
				return false;
			self::$peer = $pair[1];
			return $pair[0];
		}

		protected static function configureBlocking($socket, $blocking)
		{
			return self::$mode !== 'socket-config';
		}

		protected static function waitWritable($socket, $seconds, $microseconds)
		{
			return (self::$mode === 'write-wait') ? false : 1;
		}

		protected static function writeBytes($socket, $bytes, $length)
		{
			if(self::$mode === 'write-false')
				return false;
			if(self::$mode === 'write-zero')
				return 0;
			return min(self::$writeSize, $length);
		}

		protected static function configureReadTimeout($socket, $seconds, $microseconds)
		{
			self::$readTimeout = array($seconds, $microseconds);
			return self::$mode !== 'timeout-config';
		}

		protected static function readBytes($socket, $length)
		{
			if(in_array(self::$mode, array('read-empty', 'read-timeout-meta', 'read-eof-meta'), true))
				return '';
			if(count(self::$readQueue) === 0)
				return '';
			$chunk = array_shift(self::$readQueue);
			if(strlen($chunk) > $length)
			{
				array_unshift(self::$readQueue, substr($chunk, $length));
				return substr($chunk, 0, $length);
			}
			return $chunk;
		}

		protected static function socketMetadata($socket)
		{
			if(self::$mode === 'read-timeout-meta')
				return array('timed_out' => true, 'eof' => false);
			if(self::$mode === 'read-eof-meta')
				return array('timed_out' => false, 'eof' => true);
			return array('timed_out' => false, 'eof' => false);
		}

		protected static function monotonicNow()
		{
			$value = self::$now;
			self::$now += self::$step;
			return $value;
		}
	}

	class SCGITransportShortWriter extends rSCGITransport
	{
		public static $offers = array();
		public static $slices = array();

		public static function reset()
		{
			self::$offers = array();
			self::$slices = array();
		}

		protected static function writeBytes($socket, $bytes, $length)
		{
			self::$offers[] = $length;
			self::$slices[] = $bytes;
			return fwrite($socket, substr($bytes, 0, min(37, $length)));
		}
	}

	class SCGITransportNoConnect extends rSCGITransport
	{
		public static $attempts = 0;

		protected static function openSocket($address, &$errno, &$error, $timeout)
		{
			self::$attempts++;
			return false;
		}
	}
}

class SCGITransportTest extends TestCase
{
	private $sourceRoot;

	public function setUp()
	{
		$this->sourceRoot = realpath(__DIR__.'/../..');
		if($this->sourceRoot === false)
			throw new Exception('could not locate production source root');
	}

	private function transportIsAvailable()
	{
		$this->assertTrue(class_exists('rSCGITransport'),
			'the shared SCGI transport is available to both consumers');
		return class_exists('rSCGITransport');
	}

	public function testCompleteDeclaredFrameReturnsBeforePeerEOF()
	{
		if(!$this->transportIsAvailable()) return;
		$body = '<methodResponse><params><param><value><string>ok</string></value></param></params></methodResponse>';
		$headers = "Status: 200 OK\r\nContent-Type: text/xml\r\nContent-Length: ".strlen($body)."\r\n\r\n";
		$peer = SCGITransportFixture::start($headers.$body, true);
		try
		{
			$failure = 'not-cleared';
			$started = microtime(true);
			$result = rSCGITransport::send($peer->host(), $peer->port(), '<request/>', false, 1,
				$failure, 1, 4096, rSCGITransport::RESPONSE_RAW);
			$this->assertTrue($result === $headers.$body,
				'a complete Content-Length frame is returned verbatim before the peer closes');
			$this->assertTrue($failure === null, 'a complete frame clears the failure code');
			$this->assertTrue((microtime(true) - $started) < 0.5,
				'the client does not wait for EOF after the declared body');
		}
		finally { $peer->close(); }
	}

	public function testRequestFramingAndBothTrustBitsAreExact()
	{
		if(!$this->transportIsAvailable()) return;
		foreach(array(true => '0', false => '1') as $trusted => $untrusted)
		{
			$payload = '<request trust="'.$untrusted.'"/>';
			$peer = SCGITransportFixture::start("Content-Length: 2\r\n\r\nok");
			try
			{
				$failure = null;
				$result = rSCGITransport::send($peer->host(), $peer->port(), $payload, (bool)$trusted,
					1, $failure, 1, 1024, rSCGITransport::RESPONSE_BODY);
				$request = $peer->request();
				$expected = "CONTENT_LENGTH\0".strlen($payload)."\0CONTENT_TYPE\0text/xml\0"
					."SCGI\0"."1\0UNTRUSTED_CONNECTION\0".$untrusted."\0";
				$this->assertTrue($result === 'ok' && $failure === null,
					'trust '.$untrusted.' request receives the framed response');
				$this->assertEquals($expected, $request['header'],
					'trust '.$untrusted.' emits exact SCGI fields and ASCII 1');
				$this->assertEquals($payload, $request['payload'],
					'trust '.$untrusted.' preserves the payload byte for byte');
			}
			finally { $peer->close(); }
		}
	}

	public function testTcpAndUnixPortZeroReturnExactRawAndBodyModes()
	{
		if(!$this->transportIsAvailable()) return;
		$raw = "Status: 200 OK\r\nContent-Length: 3\r\n\r\nraw";
		$tcp = SCGITransportFixture::start($raw);
		try
		{
			$failure = null;
			$result = rSCGITransport::send($tcp->host(), $tcp->port(), '<tcp/>', true, 1,
				$failure, 1, 1024, rSCGITransport::RESPONSE_RAW);
			$this->assertTrue($result === $raw && $failure === null,
				'a real TCP peer returns the exact raw frame');
		}
		finally { $tcp->close(); }

		foreach(array(0, '0') as $port)
		{
			$unix = SCGITransportFixture::startUnix("Content-Length: 4\r\n\r\nunix");
			try
			{
				$failure = null;
				$result = rSCGITransport::send($unix->host(), $port, '<unix/>', true, 1,
					$failure, 1, 1024, rSCGITransport::RESPONSE_BODY);
				$this->assertTrue($result === 'unix' && $failure === null,
					'UNIX-domain transport accepts legacy port '.var_export($port, true));
			}
			finally { $unix->close(); }
		}
	}

	public function testInvalidInputsAndLimitsMakeZeroConnectAttempts()
	{
		if(!$this->transportIsAvailable()) return;
		$cases = array(
			array('', null, null, rSCGITransport::RESPONSE_RAW, 'empty-request'),
			array('<x/>', null, null, 'invalid', 'invalid-response-mode'),
			array('<x/>', null, false, rSCGITransport::RESPONSE_RAW, 'invalid-response-limit'),
			array('<x/>', null, 1.0, rSCGITransport::RESPONSE_RAW, 'invalid-response-limit'),
			array('<x/>', null, 0, rSCGITransport::RESPONSE_RAW, 'invalid-response-limit'),
			array('<x/>', null, -1, rSCGITransport::RESPONSE_RAW, 'invalid-response-limit'),
			array('<x/>', null, '', rSCGITransport::RESPONSE_RAW, 'invalid-response-limit'),
			array('<x/>', null, '0', rSCGITransport::RESPONSE_RAW, 'invalid-response-limit'),
			array('<x/>', null, '000', rSCGITransport::RESPONSE_RAW, 'invalid-response-limit'),
			array('<x/>', null, '+1', rSCGITransport::RESPONSE_RAW, 'invalid-response-limit'),
			array('<x/>', null, ' 1', rSCGITransport::RESPONSE_RAW, 'invalid-response-limit'),
			array('<x/>', null, '1e2', rSCGITransport::RESPONSE_RAW, 'invalid-response-limit'),
			array('<x/>', null, '999999999999999999999', rSCGITransport::RESPONSE_RAW, 'invalid-response-limit'),
			array('<x/>', null, '104857601', rSCGITransport::RESPONSE_RAW, 'invalid-response-limit'),
		);
		SCGITransportNoConnect::$attempts = 0;
		foreach($cases as $case)
		{
			$failure = null;
			$result = SCGITransportNoConnect::send('unused', 1, $case[0], true, 1,
				$failure, $case[1], $case[2], $case[3]);
			$this->assertTrue($result === null && $failure === $case[4],
				$case[4].' rejects its invalid input');
		}
		$this->assertEquals(0, SCGITransportNoConnect::$attempts,
			'all invalid inputs are rejected before any socket connect attempt');
	}

	public function testExactMaximumResponseLimitIsAccepted()
	{
		if(!$this->transportIsAvailable()) return;
		$peer = SCGITransportFixture::start("Content-Length: 1\r\n\r\nx");
		try
		{
			$failure = null;
			$result = rSCGITransport::send($peer->host(), $peer->port(), '<x/>', true, 1,
				$failure, 1, '104857600', rSCGITransport::RESPONSE_BODY);
			$this->assertTrue($result === 'x' && $failure === null,
				'the exact daemon wire ceiling is a valid response limit');
		}
		finally { $peer->close(); }
	}

	public function testCanonicalStringResponseLimitAcceptsLeadingZeros()
	{
		if(!$this->transportIsAvailable()) return;
		$peer = SCGITransportFixture::start("Content-Length: 4\r\n\r\ntest");
		try
		{
			$failure = null;
			$result = rSCGITransport::send($peer->host(), $peer->port(), '<x/>', true, 1,
				$failure, 1, '0004', rSCGITransport::RESPONSE_BODY);
			$this->assertTrue($result === 'test' && $failure === null,
				'a decimal response limit is normalized without integer overflow');
		}
		finally { $peer->close(); }
	}

	public function testShortWritesAreRetriedInSegmentedBoundedSlices()
	{
		if(!$this->transportIsAvailable()) return;
		$peer = SCGITransportFixture::start("Content-Length: 2\r\n\r\nok");
		SCGITransportShortWriter::reset();
		$payload = str_repeat('p', 70000);
		$header = "CONTENT_LENGTH\x00".strlen($payload)."\x00CONTENT_TYPE\x00text/xml\x00"
			."SCGI\x001\x00UNTRUSTED_CONNECTION\x000\x00";
		$expectedPrefix = strlen($header).':'.$header.',';
		try
		{
			$failure = null;
			$result = SCGITransportShortWriter::send($peer->host(), $peer->port(), $payload, true, 1,
				$failure, 2, 1024, rSCGITransport::RESPONSE_BODY);
			$request = $peer->request();
			$this->assertTrue($result === 'ok' && $failure === null,
				'positive short writes are retried until the exact request is complete');
			$this->assertTrue(count(SCGITransportShortWriter::$offers) > 2
				&& max(SCGITransportShortWriter::$offers) <= rSCGITransport::MAX_WRITE_BYTES,
				'every writer offer is bounded and the short-write loop executed');
			$this->assertEquals($expectedPrefix, SCGITransportShortWriter::$slices[0],
				'the first writer offer is exactly the prefix, never a full request slice');
			$this->assertEquals($payload, $request['payload'],
				'the captured payload proves no bytes were lost after short writes');
		}
		finally { $peer->close(); }
	}

	public function testWriteDeadlineDoesNotResetAfterProgress()
	{
		if(!$this->transportIsAvailable()) return;
		SCGITransportInjected::prepare('deadline');
		SCGITransportInjected::$step = 0.25;
		SCGITransportInjected::$writeSize = 32;
		$failure = null;
		$result = SCGITransportInjected::send('unused', 1, '<request/>', true, 1,
			$failure, 0.6, 1024, rSCGITransport::RESPONSE_BODY);
		SCGITransportInjected::finish();
		$this->assertTrue($result === null && $failure === 'write-timeout',
			'one absolute write deadline expires despite positive progress');
	}

	public function testWriteAndSocketFailuresKeepDistinctStableCodes()
	{
		if(!$this->transportIsAvailable()) return;
		$cases = array(
			'socket-config' => 'socket-config-failed',
			'write-wait' => 'write-wait-failed',
			'write-false' => 'write-failed',
			'write-zero' => 'write-failed',
			'timeout-config' => 'socket-config-failed',
		);
		foreach($cases as $mode => $expected)
		{
			SCGITransportInjected::prepare($mode);
			$failure = null;
			$result = SCGITransportInjected::send('unused', 1, '<request/>', true, 1,
				$failure, 1, 1024, rSCGITransport::RESPONSE_BODY);
			SCGITransportInjected::finish();
			$this->assertTrue($result === null && $failure === $expected,
				$mode.' maps to '.$expected);
		}
	}

	public function testReadBudgetIsIndependentFromTightConnectBudget()
	{
		if(!$this->transportIsAvailable()) return;
		$peer = SCGITransportFixture::startChunks(array(
			array(0.06, "Content-Length: 4\r\n\r\n"),
			array(0.04, 's'), array(0.04, 'l'), array(0.04, 'o'), array(0.04, 'w'),
		), false, false);
		try
		{
			$failure = null;
			$result = rSCGITransport::send($peer->host(), $peer->port(), '<request/>', true,
				0.01, $failure, 0.12, 1024, rSCGITransport::RESPONSE_BODY);
			$this->assertTrue($result === 'slow' && $failure === null,
				'slow progressing reply uses the transfer idle budget, not connect budget');
		}
		finally { $peer->close(); }
	}

	public function testTransferTimeoutIsFiniteAndPreservesFractions()
	{
		if(!$this->transportIsAvailable()) return;
		$cases = array(
			array('1e309', array(60, 0), 'a non-finite timeout falls back to sixty seconds'),
			array(0.25, array(0, 250000), 'a fractional timeout is not rounded to an integer'),
		);
		foreach($cases as $case)
		{
			SCGITransportInjected::prepare('timeout-capture');
			$failure = null;
			$result = SCGITransportInjected::send('unused', 1, '<request/>', true, 1,
				$failure, $case[0], 1024, rSCGITransport::RESPONSE_BODY);
			SCGITransportInjected::finish();
			$this->assertTrue($result === 'x' && $failure === null,
				$case[2].' and the exchange remains valid');
			$this->assertEquals($case[1], SCGITransportInjected::$readTimeout, $case[2]);
		}
	}

	public function testReadTimeoutMetadataIsNotCollapsedWithEOFOrReadError()
	{
		if(!$this->transportIsAvailable()) return;
		$cases = array(
			'read-timeout-meta' => 'read-timeout',
			'read-eof-meta' => 'closed-before-headers',
			'read-empty' => 'read-failed',
		);
		foreach($cases as $mode => $expected)
		{
			SCGITransportInjected::prepare($mode);
			$failure = null;
			$result = SCGITransportInjected::send('unused', 1, '<request/>', true, 1,
				$failure, 1, 1024, rSCGITransport::RESPONSE_BODY);
			SCGITransportInjected::finish();
			$this->assertTrue($result === null && $failure === $expected,
				$mode.' remains classified as '.$expected);
		}
	}

	public function testClosedBeforeHeadersIsClassified()
	{
		if(!$this->transportIsAvailable()) return;
		$this->assertRejectedFrame('', 1024, 'closed-before-headers');
	}

	public function testTruncatedHeadersAreClassified()
	{
		if(!$this->transportIsAvailable()) return;
		$this->assertRejectedFrame('Content-Length: 1', 1024, 'truncated-headers');
	}

	public function testTruncatedBodyIsClassified()
	{
		if(!$this->transportIsAvailable()) return;
		$this->assertRejectedFrame("Content-Length: 4\r\n\r\nabc", 1024, 'truncated-body');
	}

	public function testSilentOpenPeerIsReadTimeout()
	{
		if(!$this->transportIsAvailable()) return;
		$peer = SCGITransportFixture::start('', true);
		try
		{
			$failure = null;
			$result = rSCGITransport::send($peer->host(), $peer->port(), '<request/>', true, 1,
				$failure, 0.05, 1024, rSCGITransport::RESPONSE_BODY);
			$this->assertTrue($result === null && $failure === 'read-timeout',
				'a silent open peer is a read timeout, not EOF');
		}
		finally { $peer->close(); }
	}

	public function testCapturedRtorrentHeaderIsAcceptedVerbatim()
	{
		if(!$this->transportIsAvailable()) return;
		$body = str_repeat('x', 125);
		$peer = SCGITransportFixture::start("Status: 200 OK\r\nContent-Type: text/xml\r\n"
			."Content-Length: 125\r\n\r\n".$body);
		try
		{
			$failure = null;
			$result = rSCGITransport::send($peer->host(), $peer->port(), '<request/>', true, 1,
				$failure, 1, 1024, rSCGITransport::RESPONSE_BODY);
			$this->assertTrue($result === $body && $failure === null,
				'the captured rTorrent Content-Length 125 header is accepted verbatim');
		}
		finally { $peer->close(); }
	}

	public function testContentLengthIsCaseInsensitiveWithOwsAndLeadingZeros()
	{
		if(!$this->transportIsAvailable()) return;
		$peer = SCGITransportFixture::start("Status: 200 OK\r\ncontent-length:\t0004 \t\r\n\r\ntest");
		try
		{
			$failure = null;
			$result = rSCGITransport::send($peer->host(), $peer->port(), '<request/>', true, 1,
				$failure, 1, 1024, rSCGITransport::RESPONSE_BODY);
			$this->assertTrue($result === 'test' && $failure === null,
				'case-insensitive Content-Length accepts outer OWS and leading zeros');
		}
		finally { $peer->close(); }
	}

	public function testMissingContentLengthFailsClosed()
	{
		if(!$this->transportIsAvailable()) return;
		$this->assertRejectedFrame("Status: 200 OK\r\n\r\nx", 1024, 'missing-content-length');
		$this->assertRejectedFrame("Status: 200 OK\r\nX-Content-Length: 1\r\n\r\nx", 1024,
			'missing-content-length');
	}

	public function testDuplicateContentLengthFailsClosed()
	{
		if(!$this->transportIsAvailable()) return;
		$this->assertRejectedFrame("Content-Length: 1\r\ncontent-length: 1\r\n\r\nx", 1024,
			'duplicate-content-length');
	}

	public function testMalformedContentLengthsFailClosed()
	{
		if(!$this->transportIsAvailable()) return;
		$cases = array(
			array("Content-Length: \r\n\r\n", 'malformed-content-length'),
			array("Content-Length: +1\r\n\r\nx", 'malformed-content-length'),
			array("Content-Length: 1 0\r\n\r\n0123456789", 'malformed-content-length'),
		);
		foreach($cases as $case)
			$this->assertRejectedFrame($case[0], 4, $case[1]);
	}

	public function testZeroContentLengthFailsClosed()
	{
		if(!$this->transportIsAvailable()) return;
		$this->assertRejectedFrame("Content-Length: 0\r\n\r\n", 4, 'zero-content-length');
	}

	public function testOversizeContentLengthsFailClosed()
	{
		if(!$this->transportIsAvailable()) return;
		$cases = array(
			"Content-Length: 10\r\n\r\n0123456789",
			"Content-Length: 999999999999999999999\r\n\r\n",
		);
		foreach($cases as $frame)
			$this->assertRejectedFrame($frame, 4, 'response-too-large');
	}

	public function testHeaderGrammarRejectsBareLfObsFoldAndInvalidNames()
	{
		if(!$this->transportIsAvailable()) return;
		$cases = array(
			"Content-Length: 1\nX: y\r\n\r\nx",
			"Content-Length: 1\r\n folded\r\n\r\nx",
			"Bad Name: x\r\nContent-Length: 1\r\n\r\nx",
			"NoColon\r\nContent-Length: 1\r\n\r\nx",
			"X-Bad: \x00\r\nContent-Length: 1\r\n\r\nx",
			"X-Bad: \x7f\r\nContent-Length: 1\r\n\r\nx",
		);
		foreach($cases as $frame)
			$this->assertRejectedFrame($frame, 1024, 'malformed-header');
	}

	public function testHeaderBoundaryIncludesSplitDelimiterButRejectsOneByteMore()
	{
		if(!$this->transportIsAvailable()) return;
		$base = "Content-Length: 1\r\nX:";
		$header = $base.str_repeat('a', rSCGITransport::MAX_HEADER_BYTES - strlen($base));
		$delimiter = "\r\n\r\n";
		foreach(array(1, 2, 3) as $split)
		{
			$peer = SCGITransportFixture::startChunks(array(
				array(0, $header.substr($delimiter, 0, $split)),
				array(0.01, substr($delimiter, $split).'x'),
			), false, false);
			try
			{
				$failure = null;
				$result = rSCGITransport::send($peer->host(), $peer->port(), '<request/>', true, 1,
					$failure, 1, 1024, rSCGITransport::RESPONSE_BODY);
				$this->assertTrue($result === 'x' && $failure === null,
					'the exact header ceiling accepts delimiter split '.$split.' at its boundary');
			}
			finally { $peer->close(); }
		}

		$this->assertRejectedFrame($header.'a'."\r\n\r\nx", 1024, 'headers-too-large');
	}

	public function testCoalescedSuffixIsDiscardedBeforeAccumulation()
	{
		if(!$this->transportIsAvailable()) return;
		$frame = "Content-Length: 1\r\n\r\nx".str_repeat('z', 262144);
		$peer = SCGITransportFixture::start($frame, true);
		try
		{
			$failure = null;
			$started = microtime(true);
			$result = rSCGITransport::send($peer->host(), $peer->port(), '<request/>', true, 1,
				$failure, 1, 1024, rSCGITransport::RESPONSE_BODY);
			$this->assertTrue($result === 'x' && $failure === null,
				'only the declared body enters the response accumulator');
			$this->assertTrue((microtime(true) - $started) < 0.5,
				'a coalesced suffix does not make the client wait for EOF');
		}
		finally { $peer->close(); }
	}

	public function testTransportDoesNotPerformXmlValidation()
	{
		if(!$this->transportIsAvailable()) return;
		$body = 'not XML at all';
		$peer = SCGITransportFixture::start("Content-Length: ".strlen($body)."\r\n\r\n".$body);
		try
		{
			$failure = null;
			$result = rSCGITransport::send($peer->host(), $peer->port(), '<request/>', true, 1,
				$failure, 1, 1024, rSCGITransport::RESPONSE_BODY);
			$this->assertTrue($result === $body && $failure === null,
				'transport returns a framed non-XML body for its consumer to validate');
		}
		finally { $peer->close(); }
	}

	public function testDefaultRawLimitFitsWithinA128MiBWorker()
	{
		if(!$this->transportIsAvailable()) return;
		$length = rSCGITransport::DEFAULT_MAX_RESPONSE_BYTES;
		$peer = SCGITransportFixture::startRepeatedBody($length, false);
		try
		{
			$code = 'require '.$this->phpLiteral($this->sourceRoot.'/php/scgitransport.php').';'
				.'$f=null;$r=rSCGITransport::send($argv[1],(int)$argv[2],"<x/>",true,2,$f,5,null,rSCGITransport::RESPONSE_RAW);'
				.'echo json_encode(array("length"=>is_string($r)?strlen($r):null,"failure"=>$f));';
			$run = $this->runPhp(array('-d', 'memory_limit=128M', '-r', $code,
				$peer->host(), (string)$peer->port()));
			$value = json_decode($run['out'], true);
			$expected = strlen("Content-Length: ".$length."\r\n\r\n") + $length;
			$this->assertTrue($run['code'] === 0 && is_array($value)
				&& $value['length'] === $expected && $value['failure'] === null,
				'the default raw response owns one bounded representation at memory_limit=128M');
			$this->assertTrue(strpos($run['err'], 'Allowed memory size') === false,
				'the bounded raw response does not trigger a PHP memory fatal');
		}
		finally { $peer->close(); }
	}

	public function testCoreConsumerUsesLegacyDefaultsAndLogsOneClassifiedFailure()
	{
		if(!$this->transportIsAvailable()) return;
		global $scgi_host, $scgi_port, $rpcTimeOut;
		global $rpcLogCalls, $log_file, $profileMask;
		$port = $this->reservePort();
		$scgi_host = '127.0.0.1';
		$scgi_port = $port;
		$rpcTimeOut = 0.1;
		unset($GLOBALS['rpcTransferTimeOut'], $GLOBALS['rpcMaxResponseBytes']);
		$rpcLogCalls = false;
		$log_file = sys_get_temp_dir().'/rutorrent-scgi-core-'.uniqid('', true).'.log';
		$profileMask = 0600;
		$warnings = array();
		set_error_handler(function($severity, $message) use (&$warnings) {
			if(error_reporting() & $severity)
				$warnings[] = $message;
			return true;
		});
		try { $result = rXMLRPCRequest::send('<request/>', true); }
		finally { restore_error_handler(); }
		$lines = is_file($log_file) ? file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
		@unlink($log_file);
		$this->assertTrue($result === false, 'core maps transport null strictly to legacy false');
		$this->assertTrue(count($warnings) === 0,
			'missing new globals use legacy-safe defaults without warnings: '.json_encode($warnings));
		$this->assertTrue(count($lines) === 1 && preg_match('/rXMLRPCRequest: connect-failed$/', $lines[0]),
			'core emits exactly one classified transport failure line');
	}

	public function testCoreConsumerForwardsRawModeAndConfiguredLimit()
	{
		if(!$this->transportIsAvailable()) return;
		global $scgi_host, $scgi_port, $rpcTimeOut, $rpcTransferTimeOut;
		global $rpcMaxResponseBytes, $rpcLogCalls, $log_file;
		$peer = SCGITransportFixture::start("Content-Length: 2\r\n\r\nok");
		$log_file = sys_get_temp_dir().'/rutorrent-scgi-core-limit-'.uniqid('', true).'.log';
		try
		{
			$scgi_host = $peer->host();
			$scgi_port = $peer->port();
			$rpcTimeOut = 1;
			$rpcTransferTimeOut = 1;
			$rpcMaxResponseBytes = 1;
			$rpcLogCalls = false;
			$result = rXMLRPCRequest::send('<request/>', true);
			$lines = is_file($log_file) ? file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
			$this->assertTrue($result === false, 'core maps configured response-limit refusal to false');
			$this->assertTrue(count($lines) === 1 && preg_match('/rXMLRPCRequest: response-too-large$/', $lines[0]),
				'core forwards its configured limit and logs that classified result once');
		}
		finally { @unlink($log_file); $peer->close(); }
	}

	public function testCoreConsumerReturnsExactRawFrameWithLegacyDefaults()
	{
		if(!$this->transportIsAvailable()) return;
		global $scgi_host, $scgi_port, $rpcTimeOut, $rpcLogCalls;
		$frame = "Status: 200 OK\r\nContent-Length: 2\r\n\r\nok";
		$peer = SCGITransportFixture::start($frame, true);
		try
		{
			$scgi_host = $peer->host();
			$scgi_port = $peer->port();
			$rpcTimeOut = 1;
			unset($GLOBALS['rpcTransferTimeOut'], $GLOBALS['rpcMaxResponseBytes']);
			$rpcLogCalls = false;
			$result = rXMLRPCRequest::send('<request/>', true);
			$this->assertEquals($frame, $result,
				'core consumer returns exact raw headers, delimiter and body');
		}
		finally { $peer->close(); }
	}

	public function testCopiedRealRpc2UsesBodyModeOverUnixWithLegacyGlobals()
	{
		if(!$this->transportIsAvailable()) return;
		$body = '<?xml version="1.0"?><methodResponse><params></params></methodResponse>';
		$peer = SCGITransportFixture::startUnix("Status: 200 OK\r\nContent-Type: text/xml\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
		try
		{
			$result = $this->runCopiedRpc2($peer->host(), '0', $this->allowedXml(), array('legacy' => true));
			$this->assertTrue(strpos($result['status'], '200 OK') !== false,
				'copied real rpc2 endpoint completes a UNIX SCGI request');
			$this->assertEquals($body, $result['body'], 'rpc2 returns body mode without daemon headers');
			$this->assertEquals((string)strlen($body), $result['headers']['content-length'],
				'rpc2 publishes the exact framed body length');
			$this->assertTrue(strpos($result['serverError'], 'Warning') === false
				&& strpos($result['serverError'], 'Notice') === false,
				'legacy config without new globals produces no warning');
			$request = $peer->request();
			$this->assertTrue(strpos($request['header'], "UNTRUSTED_CONNECTION\0"."1\0") !== false,
				'rpc2 forwards an ordinary admitted request as untrusted');
		}
		finally { $peer->close(); }
	}

	public function testCopiedRealRpc2ReturnsNeutral502AndOneClassifiedLog()
	{
		if(!$this->transportIsAvailable()) return;
		$port = $this->reservePort();
		$result = $this->runCopiedRpc2('127.0.0.1', (string)$port, $this->allowedXml(), array('legacy' => true));
		$this->assertTrue(strpos($result['status'], '502 Bad Gateway') !== false,
			'copied real rpc2 maps transport null to HTTP 502');
		$this->assertTrue(substr_count($result['body'], 'Could not complete the rTorrent XMLRPC request.') === 1,
			'rpc2 returns the exact neutral transport sentence once');
		$this->assertTrue(preg_match_all('/ rpc2: connect-failed$/m', $result['rpcLog'], $matches) === 1,
			'rpc2 emits exactly one classified transport failure line');
		$this->assertTrue(strpos($result['rpcLog'], $this->allowedXml()) === false,
			'rpc2 transport diagnostics do not include request payloads');
	}

	public function testCopiedRealRpc2ForwardsTransferBudgetAndResponseLimit()
	{
		if(!$this->transportIsAvailable()) return;
		$body = '<?xml version="1.0"?><methodResponse><params></params></methodResponse>';
		$peer = SCGITransportFixture::startChunks(array(
			array(0.06, "Content-Length: ".strlen($body)."\r\n\r\n"),
			array(0.04, $body),
		), false, false);
		try
		{
			$result = $this->runCopiedRpc2($peer->host(), (string)$peer->port(), $this->allowedXml(), array(
				'connect' => 0.01, 'transfer' => 0.12, 'max' => 1024,
			));
			$this->assertTrue(strpos($result['status'], '200 OK') !== false && $result['body'] === $body,
				'rpc2 forwards transfer timeout independently from tight connect timeout');
		}
		finally { $peer->close(); }

		$oversize = SCGITransportFixture::start("Content-Length: 2\r\n\r\nok");
		try
		{
			$result = $this->runCopiedRpc2($oversize->host(), (string)$oversize->port(), $this->allowedXml(), array(
				'connect' => 1, 'transfer' => 1, 'max' => 1,
			));
			$this->assertTrue(strpos($result['status'], '502 Bad Gateway') !== false,
				'rpc2 forwards the configured response cap to the transport');
			$this->assertTrue(preg_match_all('/ rpc2: response-too-large$/m', $result['rpcLog'], $matches) === 1,
				'rpc2 logs the configured response-cap refusal exactly once');
		}
		finally { $oversize->close(); }
	}

	private function assertRejectedFrame($frame, $limit, $expected)
	{
		$peer = SCGITransportFixture::start($frame);
		try
		{
			$failure = null;
			$result = rSCGITransport::send($peer->host(), $peer->port(), '<request/>', true, 1,
				$failure, 1, $limit, rSCGITransport::RESPONSE_RAW);
			$this->assertTrue($result === null && $failure === $expected,
				$expected.' rejects its malformed frame');
		}
		finally { $peer->close(); }
	}

	private function allowedXml()
	{
		return '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName>'
			.'<params></params></methodCall>';
	}

	private function runCopiedRpc2($host, $port, $body, $options)
	{
		$tree = sys_get_temp_dir().'/rutorrent-scgi-rpc2-'.uniqid('', true);
		$process = null;
		try
		{
			if(!mkdir($tree, 0700, true))
				throw new Exception('could not create copied rpc2 tree');
			foreach(array('rpc2.php', 'php/xmlrpc_path.php', 'php/xmlrpc_proxy.php',
				'php/scgitransport.php') as $relative)
			{
				$source = $this->sourceRoot.'/'.$relative;
				$target = $tree.'/'.$relative;
				if(!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true))
					throw new Exception('could not create copied rpc2 source directory');
				if(!copy($source, $target) || hash_file('sha256', $source) !== hash_file('sha256', $target))
					throw new Exception('could not byte-copy production '.$relative);
			}
			mkdir($tree.'/conf', 0700, true);
			$config = <<<'PHP'
<?php
$scgi_host = getenv('SCGI_TEST_HOST');
$scgi_port = getenv('SCGI_TEST_PORT');
$topDirectory = '/';
$XMLRPCProxyAllowRootDirectory = true;
$XMLRPCProxy = 'sanitize';
$XMLRPCProxyLog = true;
$log_file = getenv('SCGI_TEST_RPC_LOG');
PHP;
			$config .= "\n\$rpcTimeOut = ".var_export(isset($options['connect']) ? $options['connect'] : 0.05, true).";\n";
			if(empty($options['legacy']))
			{
				$config .= "\$rpcTransferTimeOut = ".var_export(isset($options['transfer']) ? $options['transfer'] : 0.2, true).";\n";
				$config .= "\$rpcMaxResponseBytes = ".var_export(isset($options['max']) ? $options['max'] : 67108864, true).";\n";
			}
			file_put_contents($tree.'/conf/config.php', $config);
			file_put_contents($tree.'/prepend.php', "<?php\n\$_SERVER['RUTORRENT_XMLRPC_ENDPOINT'] = 'on';\n");
			$rpcLog = $tree.'/rpc2.log';
			$serverError = $tree.'/server.err';
			$httpPort = $this->reservePort();
			$environment = array_merge($_ENV, array(
				'SCGI_TEST_HOST' => $host,
				'SCGI_TEST_PORT' => (string)$port,
				'SCGI_TEST_RPC_LOG' => $rpcLog,
			));
			$command = escapeshellarg(PHP_BINARY)
				.' -d auto_prepend_file='.escapeshellarg($tree.'/prepend.php')
				.' -d display_errors=0 -S 127.0.0.1:'.$httpPort.' -t '.escapeshellarg($tree);
			$process = proc_open($command, array(
				0 => array('pipe', 'r'),
				1 => array('file', $tree.'/server.out', 'a'),
				2 => array('file', $serverError, 'a'),
			), $pipes, $tree, $environment);
			if(!is_resource($process))
				throw new Exception('could not start copied rpc2 server');
			fclose($pipes[0]);
			$this->waitForServer($httpPort, $process, $serverError);
			$result = $this->rawPost($httpPort, '/rpc2.php', $body);
			$result['rpcLog'] = is_file($rpcLog) ? file_get_contents($rpcLog) : '';
			$result['serverError'] = is_file($serverError) ? file_get_contents($serverError) : '';
			return $result;
		}
		finally
		{
			if(is_resource($process))
			{
				@proc_terminate($process);
				@proc_close($process);
			}
			$this->deleteTree($tree);
		}
	}

	private function rawPost($port, $path, $body)
	{
		$socket = @fsockopen('127.0.0.1', $port, $errno, $error, 2);
		if($socket === false)
			throw new Exception('could not connect copied rpc2 HTTP client: '.$error);
		$request = "POST ".$path." HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Type: text/xml\r\n"
			."Content-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body;
		fwrite($socket, $request);
		$raw = stream_get_contents($socket);
		fclose($socket);
		if($raw === false || strpos($raw, "\r\n\r\n") === false)
			throw new Exception('copied rpc2 returned no complete HTTP response');
		list($headerBlock, $responseBody) = explode("\r\n\r\n", $raw, 2);
		$headers = explode("\r\n", $headerBlock);
		$status = array_shift($headers);
		$values = array();
		foreach($headers as $header)
		{
			$position = strpos($header, ':');
			if($position !== false)
				$values[strtolower(trim(substr($header, 0, $position)))] = trim(substr($header, $position + 1));
		}
		return array('status' => $status, 'headers' => $values, 'body' => $responseBody);
	}

	private function waitForServer($port, $process, $errorFile)
	{
		for($i = 0; $i < 100; $i++)
		{
			$socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.05);
			if($socket !== false)
			{
				// An empty connect leaves PHP 7.4's single-threaded development
				// server waiting for a request and makes newer versions log a
				// malformed-request warning. Complete the probe before returning.
				$request = "GET /__rutorrent_scgi_ready__ HTTP/1.0\r\n"
					."Host: 127.0.0.1\r\nConnection: close\r\n\r\n";
				stream_set_timeout($socket, 1);
				$written = fwrite($socket, $request);
				$response = ($written === strlen($request)) ? stream_get_contents($socket) : false;
				fclose($socket);
				if($response !== false && strpos($response, "\r\n\r\n") !== false)
					return;
			}
			$status = proc_get_status($process);
			if(!$status['running'])
				throw new Exception('copied rpc2 server exited: '.@file_get_contents($errorFile));
			usleep(25000);
		}
		throw new Exception('copied rpc2 server did not start');
	}

	private function runPhp($arguments)
	{
		$command = escapeshellarg(PHP_BINARY);
		foreach($arguments as $argument)
			$command .= ' '.escapeshellarg($argument);
		$process = proc_open($command, array(
			0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'),
		), $pipes, $this->sourceRoot);
		if(!is_resource($process))
			throw new Exception('could not start PHP subprocess');
		fclose($pipes[0]);
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return array('code' => proc_close($process), 'out' => $out, 'err' => $err);
	}

	private function phpLiteral($value)
	{
		return var_export($value, true);
	}

	private function reservePort()
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
		if($socket === false)
			throw new Exception('could not reserve local port: '.$error);
		$name = stream_socket_get_name($socket, false);
		fclose($socket);
		$position = strrpos($name, ':');
		return intval(substr($name, $position + 1));
	}

	private function deleteTree($path)
	{
		if(!is_dir($path)) return;
		foreach(scandir($path) as $entry)
			if(($entry !== '.') && ($entry !== '..'))
			{
				$child = $path.'/'.$entry;
				if(is_dir($child)) $this->deleteTree($child); else @unlink($child);
			}
		@rmdir($path);
	}
}
