<?php

require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/../../php/SCGITransportFixture.php');
require_once(__DIR__.'/RealActionFixture.php');

class ExtsearchHistoryRealChainTest extends TestCase
{
	public function testRealHistoryActionKeepsAgedReceiptWhenScgiReplyStalls()
	{
		$url = 'https://example.invalid/pending/real-chain';
		$key = 'ru-load-proof-'.str_repeat('b', 32);
		$fixture = new ExtsearchRealActionFixture();
		$peer = SCGITransportFixture::startChunks(array(), true);
		try
		{
			$result = $fixture->run(array(
				'method'=>'POST', 'request'=>array('mode'=>'history'),
				'body'=>'mode=history&url='.rawurlencode($url),
				'rows'=>array($url=>array('hash'=>str_repeat('A', 40),
					'key'=>$key))), $peer);
			$this->assertSame(0, $result['exit'],
				'real history action finishes: '.$result['error']);
			if($result['exit'] !== 0) return;
			$this->assertSame(array($url=>null), json_decode($result['output'], true),
				'unknown daemon reply leaves the requested history entry pending');
			$this->assertTrue($result['elapsed'] < 4.0,
				'real action and XMLRPC chain use the short reply timeout: '.$result['elapsed']);
			$saved = $fixture->snapshot();
			$this->assertSame(true, $saved['rows'][$url]['pending'] ?? null,
				'the aged receipt survives the stalled real XMLRPC request');
			$this->assertSame($key, $saved['rows'][$url]['receipt']['key'] ?? null,
				'the exact durable receipt remains available for recovery');
			$this->assertTrue(strpos($fixture->log(), 'rXMLRPCRequest: read-timeout') !== false,
				'the real XMLRPC request classifies the stalled reply');
			$request = $peer->request();
			$this->assertTrue(strpos($request['payload'], '<methodName>d.custom</methodName>') !== false,
				'real pendingLoadStatus sends the receipt getter');
			$this->assertTrue(strpos($request['payload'], $key) !== false,
				'the getter asks for this receipt key');
		}
		finally { $peer->close(); $fixture->close(); }
	}
}
