<?php

require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/../../php/SCGITransportFixture.php');
require_once(__DIR__.'/RealActionFixture.php');

class ExtsearchOtherActionBudgetTest extends TestCase
{
	public function testSearchGetBoundsRealSilentScgiAndRetainsSixReceipts()
	{
		$fixture = new ExtsearchRealActionFixture();
		$peer = SCGITransportFixture::startChunks(array(), true);
		try
		{
			$rows = array();
			for($i=0; $i<6; $i++)
				$rows['https://example.invalid/search/'.$i] = array(
					'hash'=>str_repeat((string)$i,40),
					'key'=>'ru-load-proof-'.str_repeat('b',32));
			$result = $fixture->run(array(
				'method'=>'GET',
				'request'=>array('mode'=>'get','eng'=>'all','what'=>'needle','cat'=>'all'),
				'body'=>'', 'empty_engines'=>true, 'rows'=>$rows), $peer);
			$this->assertSame(0, $result['exit'],
				'real search action finishes: '.$result['error']);
			$this->assertSame(array('eng'=>'all','cat'=>'all','data'=>array()),
				json_decode($result['output'],true), 'search returns without tracker fetch');
			$this->assertTrue($result['elapsed'] < 6.0,
				'search cannot wait through six default SCGI read timeouts: '.$result['elapsed']);
			$snapshot = $fixture->snapshot();
			$this->assertSame(6, count(array_filter($snapshot['rows'], function($row) {
				return $row['pending'];
			})), 'all six accepted receipts survive unknown daemon state');
			$this->assertSame(4, $snapshot['next'],
				'the same durable cursor reserves only four search probes');
			$this->assertTrue(strpos($fixture->log(), 'rXMLRPCRequest: read-timeout') !== false,
				'the real first SCGI stall has a classified timeout');
			$request = $peer->request();
			$this->assertTrue(strpos($request['payload'], '<methodName>d.custom</methodName>') !== false,
				'the real search invokes the receipt getter');
		}
		finally { $peer->close(); $fixture->close(); }
	}

	public function testMalformedLoadLeavesExistingReceiptAndDaemonUntouched()
	{
		$url = 'https://example.invalid/pending/malformed';
		$key = 'ru-load-proof-'.str_repeat('c',32);
		$fixture = new ExtsearchRealActionFixture();
		$peer = SCGITransportFixture::startChunks(array(), true);
		try
		{
			$result = $fixture->run(array(
				'method'=>'POST', 'request'=>array('mode'=>'loadtorrents'),
				'body'=>'mode=loadtorrents&url='.rawurlencode($url).'&ndx=3',
				'rows'=>array($url=>array('hash'=>str_repeat('C',40),
					'key'=>$key))), $peer);
			$this->assertSame(0, $result['exit'],
				'malformed load action exits cleanly: '.$result['error']);
			$this->assertSame(array('error'=>'invalid request'),
				json_decode($result['output'], true), 'partial load trio is rejected');
			$saved = $fixture->snapshot();
			$this->assertSame(true, $saved['rows'][$url]['pending'] ?? null,
				'existing durable receipt remains pending');
			$this->assertSame($key, $saved['rows'][$url]['receipt']['key'] ?? null,
				'existing receipt key is unchanged');
			$this->assertSame(0, $saved['next'],
				'rejected form does not spend a daemon probe');
			$this->assertSame(false, $peer->accepted(),
				'rejected form never connects to rTorrent');
			$this->assertTrue(strpos($fixture->log(),
				'extsearch: load refused: parallel fields mismatch') !== false,
				'refusal is visible without logging submitted data');
		}
		finally { $peer->close(); $fixture->close(); }
	}
}
