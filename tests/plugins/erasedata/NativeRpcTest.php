<?php
require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/CollectorFixture.php');
require_once(__DIR__.'/../../../plugins/erasedata/removewithdata.php');

class ErasedataNativeRpcTest extends TestCase
{
	private function intent()
	{
		return(array('incarnation' => str_repeat('a', 32), 'generation' => '0000000000000001',
			'user' => '', 'nonce' => str_repeat('b', 32), 'digest' => str_repeat('c', 64)));
	}
	private function replies($phase, $relation)
	{
		rXMLRPCRequest::$requested = array();
		rXMLRPCRequest::$commandCalls = array();
		rXMLRPCRequest::$responses = array(
			'system.erasedata.prepare' => array('ok' => false),
			'system.erasedata.erase' => array('ok' => false),
			'system.erasedata.cancel' => array('ok' => false),
			'system.erasedata.collect_finish' => array('ok' => false),
			'system.erasedata.state' => array('ok' => true,
				'responseTypes' => array('struct'),
				'responseValues' => array(array('phase' => $phase, 'live_relation' => $relation))),
		);
	}
	public function testUnknownPrepareReconcilesExactPreparedAuthorityWithoutReplay()
	{
		$hash = str_repeat('A', 40); $intent = $this->intent();
		$this->replies('prepared', 'exact');
		$this->assertTrue(erasedataNativePrepareIntent($hash, $intent), 'exact prepared reconciliation is accepted');
		$this->assertEquals(array('system.erasedata.prepare', 'system.erasedata.state'),
			rXMLRPCRequest::$requested, 'unknown preparation is followed only by one read');
		$this->assertEquals(array('', $hash, $intent['incarnation'], $intent['generation'], '',
			$intent['nonce'], $intent['digest']), rXMLRPCRequest::$commandCalls[1][0]->params,
			'reconciliation carries the original nonce and full tuple');
		foreach(array(array('missing','absent'), array('mismatch','exact'), array('prepared','different'),
			array('committed','absent')) as $state)
		{
			$this->replies($state[0], $state[1]);
			$this->assertEquals(false, erasedataNativePrepareIntent($hash, $intent),
				'other native states cannot be adopted as this preparation');
		}
	}
	public function testUnknownEraseRequiresCommittedExactIntentAndPositiveAbsence()
	{
		$hash = str_repeat('A', 40); $intent = $this->intent();
		foreach(array(array('missing','absent'), array('mismatch','absent'), array('prepared','absent'),
			array('erase-started','absent'), array('committed','different'), array('committed','exact')) as $state)
		{
			$this->replies($state[0], $state[1]);
			$this->assertEquals(false, erasedataNativeEraseIntent($hash, $intent),
				'generic absence and another incarnation cannot prove guarded erase');
			$this->assertEquals(array('system.erasedata.erase', 'system.erasedata.state'),
				rXMLRPCRequest::$requested, 'unknown erase never falls back to raw d.erase or presence');
		}
		$this->replies('committed','absent');
		$this->assertTrue(erasedataNativeEraseIntent($hash, $intent), 'exact commit and absence settle a lost reply');
	}
	public function testNativeStateRejectsUntypedMalformedAndExtraFields()
	{
		$hash = str_repeat('A', 40); $intent = $this->intent();
		foreach(array(
			array('responseTypes'=>array('array'), 'responseValues'=>array(array('phase'=>'committed','live_relation'=>'absent'))),
			array('responseTypes'=>array('struct'), 'responseValues'=>array(array('phase'=>'committed','live_relation'=>'absent','extra'=>'permission'))),
			array('responseTypes'=>array('struct'), 'responseValues'=>array(array('phase'=>'committed'))),
			array('responseTypes'=>array('struct'), 'responseValues'=>array(array('phase'=>'revoked','live_relation'=>'absent'))),
			array('responseTypes'=>array('string'), 'responseValues'=>array('committed:absent')),
		) as $reply)
		{
			$this->replies('committed','absent');
			rXMLRPCRequest::$responses['system.erasedata.state'] = $reply;
			$this->assertEquals(false, erasedataNativeCall('state', $hash, $intent),
				'a malformed state never grants authority');
		}
	}
	public function testUnknownFinishReconcilesMissingWithoutReplayingTheConsumer()
	{
		$hash=str_repeat('A',40); $intent=$this->intent();
		$this->replies('missing','absent');
		$this->assertTrue(erasedataNativeFinishIntent($hash,$intent,123),'missing settles only an already incapacitated finish');
		$this->assertEquals(array('system.erasedata.collect_finish','system.erasedata.state'),rXMLRPCRequest::$requested,'unknown finish invokes one read without replaying the consumer');
		foreach(array('mismatch','prepared','erase-started','committed') as $phase)
		{
			$this->replies($phase,'absent');
			$this->assertEquals(false,erasedataNativeFinishIntent($hash,$intent,123),'other phases retain terminal metadata after unknown finish');
		}
	}

	public function testIncarnationRequiresTheExactStringWireType()
	{
		$hash=str_repeat('A',40); $id=str_repeat('a',32);
		rXMLRPCRequest::$responses=array('d.incarnation'=>array('ok'=>true,'val'=>array($id)));
		$this->assertEquals($id,erasedataNativeIncarnation($hash),'exact native incarnation string is accepted');
		rXMLRPCRequest::$responses['d.incarnation']=array('ok'=>true,'val'=>array($id),
			'responseTypes'=>array('struct'),'responseValues'=>array(array('incarnation'=>$id)));
		$this->assertEquals(false,erasedataNativeIncarnation($hash),'a flattened struct cannot claim an incarnation');
	}

	public function testUnknownPreparedCancelCannotBecomeEraseSuccess()
	{
		$this->replies('missing','exact');
		$this->assertTrue(erasedataNativeCancelIntent(str_repeat('A',40),$this->intent()),
			'missing settles application-only cancellation after an unknown reply');
		$this->replies('committed','absent');
		$this->assertEquals(false, erasedataNativeCancelIntent(str_repeat('A',40),$this->intent()),
			'committed authority cannot be cancelled as a prepared orphan');
	}
}
