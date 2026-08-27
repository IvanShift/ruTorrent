<?php

require_once(__DIR__ . '/TestCase.php');
// The live TCP peer, the response builder and the PHP child runner.
require_once(__DIR__ . '/SCGITransportFixture.php');

// The component under test is the transport by itself: request framing,
// response framing, the header and body bounds and response validation. It
// answers to no XMLRPC proxy policy, so this suite loads neither
// xmlrpc_proxy.php nor the ruTorrent stubs that policy needs.
require_once(__DIR__ . '/../../php/scgitransport.php');

class SCGITransportTest extends TestCase
{
	public function testSCGITransportExactContentLengthXmlAccepted()
	{
		$body = SCGITransportFixture::methodResponse('exact');
		$response = "Status: 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
		SCGITransportFixture::mockSCGIServer(array('response' => $response), function($host, $port) use ($body, $response) {
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2);
			$this->assertTrue(is_array($res), 'exact Content-Length XML accepted');
			$this->assertTrue($res['body'] === $body, 'body is returned byte for byte');
			$this->assertTrue($res['raw'] === $response, 'raw response is returned byte for byte');
		});
	}

	public function testSCGITransportMixedCaseContentLengthAccepted()
	{
		$body = SCGITransportFixture::methodResponse('mixed-case');
		$response = "Status: 200 OK\r\ncOnTeNt-LeNgTh: " . strlen($body) . "\r\n\r\n" . $body;
		SCGITransportFixture::mockSCGIServer(array('response' => $response), function($host, $port) {
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2);
			$this->assertTrue(is_array($res), 'mixed-case Content-Length is recognized');
		});
		$oversized = "Status: 200 OK\r\ncOnTeNt-LeNgTh: 67108865\r\n\r\n";
		SCGITransportFixture::mockSCGIServer(array('response' => $oversized), function($host, $port) {
			$err = null;
			rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue(strpos($err, 'response body exceeds 67108864 bytes') !== false,
				'mixed-case Content-Length enforces the same exact bound');
		});
	}

	public function testSCGITransportMalformedHeaderFieldRejected()
	{
		$response = "Status: 200 OK\r\nBroken header field\r\n\r\n" . SCGITransportFixture::methodResponse();
		SCGITransportFixture::mockSCGIServer(array('response' => $response), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue($res === null, 'malformed header field is rejected');
			$this->assertTrue(strpos($err, 'malformed response header') !== false, 'header error is classified');
		});
	}

	public function testSCGITransportEveryDuplicateContentLengthRejected()
	{
		$body = SCGITransportFixture::methodResponse();
		$response = "Status: 200 OK\r\nContent-Length: " . strlen($body)
			. "\r\ncontent-length: " . strlen($body) . "\r\n\r\n" . $body;
		SCGITransportFixture::mockSCGIServer(array('response' => $response), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue($res === null, 'identical duplicate Content-Length is rejected');
			$this->assertTrue(strpos($err, 'duplicate Content-Length') !== false, 'duplicate is classified');
		});
	}

	public function testSCGITransportMalformedContentLengthRejected()
	{
		$response = "Status: 200 OK\r\nContent-Length: 12oops\r\n\r\n" . SCGITransportFixture::methodResponse();
		SCGITransportFixture::mockSCGIServer(array('response' => $response), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue($res === null, 'malformed Content-Length is rejected');
			$this->assertTrue(strpos($err, 'malformed Content-Length') !== false, 'length error is classified');
		});
	}

	public function testSCGITransportExactLengthNonXmlRejected()
	{
		$body = 'not XML despite the exact byte count';
		$response = "Status: 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
		SCGITransportFixture::mockSCGIServer(array('response' => $response), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue($res === null, 'exact-length non-XML body is rejected');
			$this->assertTrue(strpos($err, 'invalid XML methodResponse') !== false, 'XML error is classified');
		});
	}

	public function testSCGITransportTruncatedBodyRejected()
	{
		$body = SCGITransportFixture::methodResponse();
		$response = "Status: 200 OK\r\nContent-Length: " . (strlen($body) + 10) . "\r\n\r\n" . $body;
		SCGITransportFixture::mockSCGIServer(array('response' => $response), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue($res === null, 'truncated body is rejected');
			$this->assertTrue(strpos($err, 'truncated response') !== false, 'truncation is classified');
		});
	}

	public function testSCGITransportOverlongBodyRejected()
	{
		$body = SCGITransportFixture::methodResponse();
		$response = "Status: 200 OK\r\nContent-Length: " . (strlen($body) - 1) . "\r\n\r\n" . $body;
		SCGITransportFixture::mockSCGIServer(array('response' => $response), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue($res === null, 'overlong body is rejected');
			$this->assertTrue(strpos($err, 'overlong response') !== false, 'overlong response is classified');
		});
	}

	public function testSCGITransportExactLengthTruncatedXmlRejected()
	{
		$body = '<?xml version="1.0"?><methodResponse><params><param><value><string>cut';
		$response = "Status: 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
		SCGITransportFixture::mockSCGIServer(array('response' => $response), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue($res === null, 'exact-length truncated XML is rejected');
			$this->assertTrue(strpos($err, 'invalid XML methodResponse') !== false, 'XML truncation is classified');
		});
	}

	public function testSCGITransportLengthlessTruncatedXmlRejected()
	{
		$body = '<?xml version="1.0"?><methodResponse><params><param><value><string>cut';
		$response = "Status: 200 OK\r\nContent-Type: text/xml\r\n\r\n" . $body;
		SCGITransportFixture::mockSCGIServer(array('response' => $response), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue($res === null, 'lengthless truncated XML is rejected');
			$this->assertTrue(strpos($err, 'invalid XML methodResponse') !== false, 'XML truncation is classified');
		});
	}

	public function testSCGITransportLengthlessValidXmlAccepted()
	{
		$body = SCGITransportFixture::methodResponse('lengthless');
		$response = "Status: 200 OK\r\nContent-Type: text/xml\r\n\r\n" . $body;
		SCGITransportFixture::mockSCGIServer(array('response' => $response), function($host, $port) use ($body) {
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2);
			$this->assertTrue(is_array($res), 'valid lengthless XML response is accepted');
			$this->assertTrue($res['body'] === $body, 'lengthless body is complete');
		});
	}

	public function testSCGITransportFragmentedValidResponseAccepted()
	{
		$body = SCGITransportFixture::methodResponse('fragmented');
		$response = "Status: 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
		SCGITransportFixture::mockSCGIServer(array(
			'response' => $response,
			'responseChunk' => 1,
			'responseDelayBetweenChunks' => 100,
		), function($host, $port) use ($body) {
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2);
			$this->assertTrue(is_array($res), 'one-byte response fragments are reassembled');
			$this->assertTrue($res['body'] === $body, 'fragmented body is complete');
		});
	}

	public function testSCGITransportReadTimeoutIsClassified()
	{
		SCGITransportFixture::mockSCGIServer(array(
			'response' => '',
			'responseDelayMicros' => 1500000,
		), function($host, $port) {
			$err = null;
			// The 7th argument is the one that bounds the reply now. Passing only
			// the 5th would bound the CONNECT and leave the read on PHP's
			// default_socket_timeout, so this test would sit for a minute and
			// then pass for the wrong reason.
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 1, $err, 1);
			$this->assertTrue($res === null, 'read timeout is rejected');
			$this->assertTrue(strpos($err, 'timed out reading') !== false, 'timeout is classified before empty read');
		});
	}

	public function testSCGITransportSlowReplySurvivesATightConnectTimeout()
	{
		// The regression this guards: one number used to bound both phases, so
		// $rpcTimeOut = 5 -- documented and shipped as a CONNECT timeout -- also
		// cut off any rtorrent that needed longer than five seconds to answer.
		// rtorrent computes a whole multicall before writing its first byte, so
		// on a large session at startup that is ordinary, not pathological.
		foreach(array(5, null) as $transfer)
		{
			SCGITransportFixture::mockSCGIServer(array(
				'reportRequestComplete' => true,
				'responseDelayMicros' => 1500000,
			), function($host, $port) use ($transfer) {
				$err = null;
				$res = rSCGITransport::send($host, $port, '<xml/>', true, 1, $err, $transfer);
				$this->assertTrue(is_array($res),
					'a 1.5s reply survives a 1s connect budget (transfer='
					. var_export($transfer, true) . '): ' . var_export($err, true));
			});
		}
	}

	public function testSCGITransportBudgetsDoNotConstrainEachOther()
	{
		// The connect budget is not a floor for the transfer budget. Clamping
		// them together would give an install that had raised $rpcTimeOut a
		// LONGER per-read idle budget than it ever had, and that budget has no
		// overall deadline behind it.
		SCGITransportFixture::mockSCGIServer(array(
			'response' => '',
			'responseDelayMicros' => 1500000,
		), function($host, $port) {
			$err = null;
			$started = microtime(true);
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 90, $err, 1);
			$elapsed = microtime(true) - $started;
			$this->assertTrue($res === null, 'a transfer budget below the connect budget is honoured');
			$this->assertTrue(strpos((string) $err, 'timed out reading') !== false,
				'the small transfer budget is what fires: ' . var_export($err, true));
			$this->assertTrue($elapsed < 3,
				'the large connect budget does not raise the reply budget, took '
				. round($elapsed, 2) . 's');
		});
	}

	public function testSCGITransportWriteFollowsTheTransferBudgetNotTheConnectBudget()
	{
		// No write-timeout test existed at all, so moving the write deadline off
		// the connect knob was unverified in either direction. Both directions
		// are checked here. The peer accepts the connection and then does not
		// read for three seconds, so a payload larger than the socket buffers
		// cannot drain and it is the write deadline that decides.
		//
		// rtorrent stalls a write for the same reason it stalls a reply: its
		// SCGI listener does not drain the request until the main loop services
		// that socket, so a busy or starting daemon leaves a large multicall
		// sitting in the buffers. Charging that to the connect budget would
		// reinstate the five-second abort in the write phase right after it was
		// removed from the read phase.
		$payload = '<xml>' . str_repeat('x', 16 * 1024 * 1024) . '</xml>';

		// Generous transfer budget, tight connect budget: the write must survive.
		SCGITransportFixture::mockSCGIServer(array(
			'reportRequestComplete' => true,
			'requestReadDelayMicros' => 3000000,
		), function($host, $port) use ($payload) {
			$err = null;
			$res = rSCGITransport::send($host, $port, $payload, true, 1, $err, 15);
			$this->assertTrue(is_array($res),
				'a peer that starts reading after 3s is not cut off by a 1s connect budget: '
				. var_export($err, true));
		});

		// Both budgets tight: the write timeout still fires and is still named.
		SCGITransportFixture::mockSCGIServer(array(
			'reportRequestComplete' => true,
			'requestReadDelayMicros' => 3000000,
		), function($host, $port) use ($payload) {
			$err = null;
			$started = microtime(true);
			$res = rSCGITransport::send($host, $port, $payload, true, 1, $err, 1);
			$elapsed = microtime(true) - $started;
			$this->assertTrue($res === null, 'a write that cannot drain in time is rejected');
			$this->assertTrue(strpos((string) $err, 'timed out writing') !== false,
				'the write timeout is classified: ' . var_export($err, true));
			$this->assertTrue($elapsed < 2.5,
				'the write deadline fires on the transfer budget, took ' . round($elapsed, 2) . 's');
		});
	}

	public function testSCGITransportExactHeaderLimitSurvivesEveryDelimiterSplit()
	{
		$prefix = "Status: 200 OK\r\nX-Fill: ";
		$headers = $prefix . str_repeat('h', 65536 - strlen($prefix));
		$body = SCGITransportFixture::methodResponse('header-limit');
		$response = $headers . "\r\n\r\n" . $body;
		foreach(array(1, 2, 3) as $delimiterBytesInFirstWrite)
		{
			SCGITransportFixture::mockSCGIServer(array(
				'response' => $response,
				'responseChunk' => 65536 + $delimiterBytesInFirstWrite,
				'responseDelayBetweenChunks' => 10000,
			), function($host, $port) use ($body, $delimiterBytesInFirstWrite) {
				$err = null;
				$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
				$this->assertTrue(is_array($res), '65536-byte header accepted when delimiter splits after byte '
					. $delimiterBytesInFirstWrite . ': ' . $err);
				$this->assertTrue(is_array($res) && $res['body'] === $body,
					'boundary response body is complete after delimiter split '
					. $delimiterBytesInFirstWrite);
			});
		}
	}

	public function testSCGITransportHeaderOver64KiBRejected()
	{
		$prefix = "Status: 200 OK\r\nX-Fill: ";
		$headers = $prefix . str_repeat('h', 65537 - strlen($prefix));
		$response = $headers . "\r\n\r\n" . SCGITransportFixture::methodResponse();
		SCGITransportFixture::mockSCGIServer(array(
			'response' => $response,
			'responseChunk' => 65540,
			'responseDelayBetweenChunks' => 10000,
		), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue($res === null, 'a 65537-byte header is rejected across delimiter fragmentation');
			$this->assertTrue(strpos($err, 'response headers exceed 65536 bytes') !== false, 'header bound is exact');
		});
	}

	public function testSCGITransportLengthlessBodyOver64MiBRejected()
	{
		SCGITransportFixture::mockSCGIServer(array('generatedBodyBytes' => 67108865), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 5, $err);
			$this->assertTrue($res === null, 'lengthless body over 67108864 bytes is rejected');
			$this->assertTrue(strpos($err, 'response body exceeds 67108864 bytes') !== false, 'body bound is exact');
		});
	}

	public function testSCGITransportDeclaredBodyOver64MiBRejected()
	{
		$response = "Status: 200 OK\r\nContent-Length: 67108865\r\n\r\n";
		SCGITransportFixture::mockSCGIServer(array('response' => $response), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue($res === null, 'declared body over 67108864 bytes is rejected');
			$this->assertTrue(strpos($err, 'response body exceeds 67108864 bytes') !== false, 'declared bound is classified');
		});
	}

	public function testSCGITransportMissingHeaderDelimiterRejected()
	{
		$response = 'Status: 200 OK Content-Length: 4 test';
		SCGITransportFixture::mockSCGIServer(array('response' => $response), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue($res === null, 'missing header delimiter is rejected');
			$this->assertTrue(strpos($err, 'missing header delimiter') !== false, 'framing error is classified');
		});
	}

	public function testSCGITransportWritesCompleteRequestAcrossPartialWrites()
	{
		SCGITransportFixture::mockSCGIServer(array(
			'reportRequestComplete' => true,
			'requestChunk' => 17,
			'requestReadDelayMicros' => 1500000,
		), function($host, $port) {
			$payload = str_repeat('p', 32 * 1024 * 1024);
			$res = rSCGITransport::send($host, $port, $payload, true, 5);
			$this->assertTrue(is_array($res), 'peer returns a response after the large request');
			$this->assertTrue(strpos($res['body'], '>complete<') !== false,
				'complete netstring and payload arrive across partial socket writes');
		});
	}

	public function testSCGITransportIgnoresXContentLengthHeader()
	{
		$body = SCGITransportFixture::methodResponse('x-header');
		$response = "Status: 200 OK\r\nX-Content-Length: 1\r\n\r\n" . $body;
		SCGITransportFixture::mockSCGIServer(array('response' => $response), function($host, $port) {
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2);
			$this->assertTrue(is_array($res), 'X-Content-Length is not mistaken for Content-Length');
		});
	}

	public function testSCGITransportLengthlessNonXmlRpcRootRejected()
	{
		$response = "Status: 200 OK\r\nContent-Type: text/html\r\n\r\n<html><body>error</body></html>";
		SCGITransportFixture::mockSCGIServer(array('response' => $response), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue($res === null, 'non-methodResponse XML root is rejected');
			$this->assertTrue(strpos($err, 'invalid XML methodResponse') !== false, 'root error is classified');
		});
	}

	public function testSCGITransportFailsBeforeNetworkWhenSimpleXMLFunctionIsDisabled()
	{
		$script = <<<'PHP'
$transportFile = $argv[1];
require_once($transportFile);

set_error_handler(function($severity, $message) {
	throw new RuntimeException('transport attempted before dependency guard: ' . $message);
});
$err = null;
$res = rSCGITransport::send('127.0.0.1', 1, '<methodCall/>', true, 1, $err);
restore_error_handler();

if($res !== null)
	exit(2);
if($err !== 'SimpleXML extension is required for SCGI response validation')
	exit(3);
echo "result=null\nerror=" . $err . "\n";
PHP;

		$transportPath = realpath(__DIR__ . '/../../php/scgitransport.php');
		$child = SCGITransportFixture::runPhpChild(array('-n', '-d',
			'disable_functions=simplexml_load_string', '-r', $script, '--', $transportPath));

		$this->assertEquals(0, $child['exitCode'],
			'disabled-SimpleXML child exits cleanly before transport: stderr=' . $child['stderr']
			. ', stdout=' . $child['stdout']);
		$this->assertEquals('', $child['stderr'], 'disabled-SimpleXML child emits no fatal or warning');
		$this->assertEquals("result=null\nerror=SimpleXML extension is required for SCGI response validation\n",
			$child['stdout'], 'send() returns null with the exact dependency error before transport');
	}
}
