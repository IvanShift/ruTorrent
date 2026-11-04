<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/CollectorFixture.php');
require_once(__DIR__ . '/../../../plugins/erasedata/removewithdata.php');

class ErasedataNativeRpcTest extends TestCase
{
    public function testUnknownPrepareReconcilesExactPreparedAuthorityWithoutReplay()
    {
        $hash = str_repeat('A', 40);
        $intent = $this->intent();
        $this->replies('prepared', 'exact');
        $this->assertTrue(erasedataNativePrepareIntent($hash, $intent), 'exact prepared reconciliation is accepted');
        $this->assertEquals(
            ['system.erasedata.prepare', 'system.erasedata.state'],
            rXMLRPCRequest::$requested,
            'unknown preparation is followed only by one read',
        );
        $this->assertEquals(
            ['', $hash, $intent['incarnation'], $intent['generation'], '',
                $intent['nonce'], $intent['digest']],
            rXMLRPCRequest::$commandCalls[1][0]->params,
            'reconciliation carries the original nonce and full tuple',
        );
        foreach ([['missing','absent'], ['mismatch','exact'], ['prepared','different'],
            ['committed','absent']] as $state) {
            $this->replies($state[0], $state[1]);
            $this->assertEquals(
                false,
                erasedataNativePrepareIntent($hash, $intent),
                'other native states cannot be adopted as this preparation',
            );
        }
    }
    public function testPrepareRefusalUsesOnlyExactTypedNativeReasonLiterals()
    {
        $hash = str_repeat('A', 40);
        $intent = $this->intent();
        foreach (['independent-file-operation-owner','partial-erase'] as $known) {
            $this->replies('prepared', 'exact');
            rXMLRPCRequest::$responses['system.erasedata.prepare'] = ['ok' => true,'val' => ['refused:' . $known]];
            $reason = null;
            $this->assertTrue(
                !erasedataNativePrepareIntent($hash, $intent, $reason),
                'a positively refused prepare never adopts even an inconsistent state reply',
            );
            $this->assertEquals($known, $reason, 'the trusted literal names the known refusal');
            $this->assertEquals(
                ['system.erasedata.prepare'],
                rXMLRPCRequest::$requested,
                'a known refusal does not replay or guess another mutation',
            );
        }
        foreach ([
            ['ok' => false],
            ['ok' => true,'val' => ['refused:future-reason']],
            ['ok' => true,'val' => [' refused:partial-erase']],
            ['ok' => true,'val' => ['refused:partial-erase'],'responseTypes' => ['array']],
            ['ok' => false,'fault' => true,'rawFaultString' => 'refused:partial-erase'],
        ] as $reply) {
            $this->replies('missing', 'exact');
            rXMLRPCRequest::$responses['system.erasedata.prepare'] = $reply;
            $reason = null;
            $this->assertTrue(!erasedataNativePrepareIntent($hash, $intent, $reason), 'unknown prepare remains unresolved');
            $this->assertEquals('unresolved', $reason, 'transport, malformed and unknown values cannot impersonate known busy');
            $this->assertEquals(
                ['system.erasedata.prepare','system.erasedata.state'],
                rXMLRPCRequest::$requested,
                'unknown preparation performs only exact read-only reconciliation',
            );
        }
    }
    public function testUnknownEraseRequiresCommittedExactIntentAndPositiveAbsence()
    {
        $hash = str_repeat('A', 40);
        $intent = $this->intent();
        foreach ([['missing','absent'], ['mismatch','absent'], ['prepared','absent'],
            ['erase-started','absent'], ['committed','different'], ['committed','exact']] as $state) {
            $this->replies($state[0], $state[1]);
            $this->assertEquals(
                false,
                erasedataNativeEraseIntent($hash, $intent),
                'generic absence and another incarnation cannot prove guarded erase',
            );
            $this->assertEquals(
                ['system.erasedata.erase', 'system.erasedata.state'],
                rXMLRPCRequest::$requested,
                'unknown erase never falls back to raw d.erase or presence',
            );
        }
        $this->replies('committed', 'absent');
        $this->assertTrue(erasedataNativeEraseIntent($hash, $intent), 'exact commit and absence settle a lost reply');
    }
    public function testNativeStateRejectsUntypedMalformedAndExtraFields()
    {
        $hash = str_repeat('A', 40);
        $intent = $this->intent();
        foreach ([
            ['responseTypes' => ['array'], 'responseValues' => [['phase' => 'committed','live_relation' => 'absent']]],
            ['responseTypes' => ['struct'], 'responseValues' => [['phase' => 'committed','live_relation' => 'absent','extra' => 'permission']]],
            ['responseTypes' => ['struct'], 'responseValues' => [['phase' => 'committed']]],
            ['responseTypes' => ['struct'], 'responseValues' => [['phase' => 'revoked','live_relation' => 'absent']]],
            ['responseTypes' => ['string'], 'responseValues' => ['committed:absent']],
        ] as $reply) {
            $this->replies('committed', 'absent');
            rXMLRPCRequest::$responses['system.erasedata.state'] = $reply;
            $this->assertEquals(
                false,
                erasedataNativeCall('state', $hash, $intent),
                'a malformed state never grants authority',
            );
        }
    }
    public function testUnknownFinishReconcilesMissingWithoutReplayingTheConsumer()
    {
        $hash = str_repeat('A', 40);
        $intent = $this->intent();
        $this->replies('missing', 'absent');
        $this->assertTrue(erasedataNativeFinishIntent($hash, $intent, 123), 'missing settles only an already incapacitated finish');
        $this->assertEquals(['system.erasedata.collect_finish','system.erasedata.state'], rXMLRPCRequest::$requested, 'unknown finish invokes one read without replaying the consumer');
        foreach (['mismatch','prepared','erase-started','committed'] as $phase) {
            $this->replies($phase, 'absent');
            $this->assertEquals(false, erasedataNativeFinishIntent($hash, $intent, 123), 'other phases retain terminal metadata after unknown finish');
        }
    }

    public function testIncarnationRequiresTheExactStringWireType()
    {
        $hash = str_repeat('A', 40);
        $id = str_repeat('a', 32);
        rXMLRPCRequest::$responses = ['d.incarnation' => ['ok' => true,'val' => [$id]]];
        $this->assertEquals($id, erasedataNativeIncarnation($hash), 'exact native incarnation string is accepted');
        rXMLRPCRequest::$responses['d.incarnation'] = ['ok' => true,'val' => [$id],
            'responseTypes' => ['struct'],'responseValues' => [['incarnation' => $id]]];
        $this->assertEquals(false, erasedataNativeIncarnation($hash), 'a flattened struct cannot claim an incarnation');
    }

    public function testUnknownPreparedCancelCannotBecomeEraseSuccess()
    {
        $this->replies('missing', 'exact');
        $this->assertTrue(
            erasedataNativeCancelIntent(str_repeat('A', 40), $this->intent()),
            'missing settles application-only cancellation after an unknown reply',
        );
        $this->replies('committed', 'absent');
        $this->assertEquals(
            false,
            erasedataNativeCancelIntent(str_repeat('A', 40), $this->intent()),
            'committed authority cannot be cancelled as a prepared orphan',
        );
    }
    private function intent()
    {
        return(['incarnation' => str_repeat('a', 32), 'generation' => '0000000000000001',
            'user' => '', 'nonce' => str_repeat('b', 32), 'digest' => str_repeat('c', 64)]);
    }
    private function replies($phase, $relation)
    {
        rXMLRPCRequest::$requested = [];
        rXMLRPCRequest::$commandCalls = [];
        rXMLRPCRequest::$responses = [
            'system.erasedata.prepare' => ['ok' => false],
            'system.erasedata.erase' => ['ok' => false],
            'system.erasedata.cancel' => ['ok' => false],
            'system.erasedata.collect_finish' => ['ok' => false],
            'system.erasedata.state' => ['ok' => true,
                'responseTypes' => ['struct'],
                'responseValues' => [['phase' => $phase, 'live_relation' => $relation]]],
        ];
    }
}
