<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/PermissionsBiteFixture.php');

/**
 * These tests exercise the two HTTP doors, not a reimplementation of them.
 * Each case copies the production entrypoint and proxy byte-for-byte, then
 * stubs only their dependencies below that boundary in a fresh PHP server.
 */
class XMLRPCProxyEntrypointTest extends TestCase
{
	private $sourceRoot;

	const ORDINARY_PAYLOAD = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params></params></methodCall>';
	const ORDINARY_LENGTH = 109;
	const ORDINARY_SHA256 = 'da404e3c00f2949eaae852190dce9909c81734f86e0f1c833c99f59003d9cdf3';

	const CANONICAL_MULTICALL_PAYLOAD = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>d.multicall2</methodName><params><param><value><string></string></value></param><param><value><string>default</string></value></param><param><value><string>d.stop=\"\"</string></value></param></params></methodCall>";
	const CANONICAL_MULTICALL_LENGTH = 275;
	const CANONICAL_MULTICALL_SHA256 = 'd92ec179c05929f0f8eebd4c6325a27a5fba884907c8cbb2e7c37c3b0f636908';
	const CANONICAL_SYSTEM_BATCH_SHA256 = '640d88150e348f7cfc7a070a4c0d78d41fceab4b7a99517c6ff29de9bb7abadc';

	public function setUp()
	{
		$this->sourceRoot = realpath(__DIR__ . '/../..');
		if($this->sourceRoot === false)
			throw new Exception('could not locate the production source root');
	}

	public function testHttprpcUnreadableInputReturnsClassified400()
	{
		$result = $this->runEntrypoint('action', 'unreadable', true);
		$this->assertHttp($result, '400 Bad Request', 'text/html; charset=UTF-8',
			'Could not read XMLRPC request.');
		$this->assertFullTranscript('httprpc', $result['state'], 0, null, null, null,
			null, null, null, null, null,
			array(array('event' => 'response', 'door' => 'httprpc')));
		$this->assertEquals(array('xmlrpc-proxy: could not read request body'), $result['state']['logs']);
	}

	public function testHttprpcEmptyInputReturnsClassified400()
	{
		$result = $this->runEntrypoint('action', '', true);
		$this->assertHttp($result, '400 Bad Request', 'text/html; charset=UTF-8', 'Empty XMLRPC request.');
		$this->assertFullTranscript('httprpc', $result['state'], 0, null, null, null,
			null, null, null, null, null,
			array(array('event' => 'response', 'door' => 'httprpc')));
		$this->assertEquals(array('xmlrpc-proxy: empty request body'), $result['state']['logs']);
	}

	public function testHttprpcInputFailuresDoNotLogWhenDisabled()
	{
		$unreadable = $this->runEntrypoint('action', 'unreadable', false);
		$this->assertHttp($unreadable, '400 Bad Request', 'text/html; charset=UTF-8',
			'Could not read XMLRPC request.');
		$this->assertFullTranscript('httprpc unreadable', $unreadable['state'], 0, null, null, null,
			null, null, null, null, null,
			array(array('event' => 'response', 'door' => 'httprpc')));
		$this->assertEquals(array(), $unreadable['state']['logs']);

		$empty = $this->runEntrypoint('action', '', false);
		$this->assertHttp($empty, '400 Bad Request', 'text/html; charset=UTF-8', 'Empty XMLRPC request.');
		$this->assertFullTranscript('httprpc empty', $empty['state'], 0, null, null, null,
			null, null, null, null, null,
			array(array('event' => 'response', 'door' => 'httprpc')));
		$this->assertEquals(array(), $empty['state']['logs']);
	}

	public function testHttprpcRefusalReturnsNamed403AndStops()
	{
		$result = $this->runEntrypoint('action', $this->deniedXml(), true);
		$this->assertHttp($result, '403 Forbidden', 'text/xml; charset=UTF-8');
		$this->assertTrue(strpos($result['body'], '<i4>-501</i4>') !== false,
			'httprpc refusal returns the XMLRPC -501 envelope');
		$this->assertTrue(strpos($result['body'],
			"The command 'execute.capture' was rejected by this server.") !== false,
			'httprpc refusal names the refused command');
		$this->assertFullTranscript('httprpc', $result['state'], 0, null, null, null,
			null, null, null, null, null,
			array(array('event' => 'response', 'door' => 'httprpc')));
	}

	public function testHttprpcTransportFailureReturnsNeutral500AndStops()
	{
		$result = $this->runEntrypoint('action', $this->allowedXml(), true, 'false');
		$this->assertHttp($result, '500 Server Error', 'text/html; charset=UTF-8',
			'Could not complete the rTorrent XMLRPC request.');
		$this->assertFullTranscript('httprpc', $result['state'], 1, self::ORDINARY_PAYLOAD,
			self::ORDINARY_LENGTH, self::ORDINARY_SHA256, null, null, false, null, null,
			array(
				array('event' => 'send', 'door' => 'httprpc', 'trusted' => false, 'sends' => 1),
				array('event' => 'response', 'door' => 'httprpc'),
			));
	}

	public function testRpc2UnreadableInputReturnsClassified400()
	{
		$result = $this->runEntrypoint('rpc2', 'unreadable', true);
		$this->assertHttp($result, '400 Bad Request', 'text/xml;charset=UTF-8');
		$this->assertTrue(strpos($result['body'], 'Could not read XMLRPC request.') !== false,
			'rpc2 unreadable input uses the exact client message');
		$this->assertTrue(strpos($result['body'], '<i4>-501</i4>') !== false,
			'rpc2 unreadable input returns the XMLRPC -501 envelope');
		$this->assertRpc2Log($result, 'could not read request body',
			'rpc2 logs the classified unreadable-input reason');
		$this->assertFullTranscript('rpc2', $result['state'], 0, null, null, null,
			null, null, null, null, null, array());
	}

	public function testRpc2EmptyInputReturnsClassified400()
	{
		$result = $this->runEntrypoint('rpc2', '', true);
		$this->assertHttp($result, '400 Bad Request', 'text/xml;charset=UTF-8');
		$this->assertTrue(strpos($result['body'], 'Empty XMLRPC request.') !== false,
			'rpc2 empty input uses the exact client message');
		$this->assertTrue(strpos($result['body'], 'post_max_size') === false,
			'rpc2 does not speculate about post_max_size in the client fault');
		$this->assertRpc2Log($result, 'empty request body',
			'rpc2 logs the classified empty-input reason');
		$this->assertFullTranscript('rpc2', $result['state'], 0, null, null, null,
			null, null, null, null, null, array());
	}

	public function testRpc2RefusalRendersTheSharedNamedMessage()
	{
		$result = $this->runEntrypoint('rpc2', $this->deniedXml(), true);
		$this->assertHttp($result, '403 Forbidden', 'text/xml;charset=UTF-8');
		$this->assertTrue(strpos($result['body'],
			"The command 'execute.capture' was rejected by this server.") !== false,
			'rpc2 refusal names the refused command');
		$this->assertTrue(strpos($result['body'], '<i4>-501</i4>') !== false,
			'rpc2 refusal returns the XMLRPC -501 envelope');
		$this->assertFullTranscript('rpc2', $result['state'], 0, null, null, null,
			null, null, null, null, null, array());
	}



	public function testDisabledProxyDoesNotSendSocketAllocationRequests()
	{
		$xml = '<methodCall><methodName>system.sockets.adjust_alloc</methodName><params></params></methodCall>';
		foreach(array('action', 'rpc2') as $door)
		{
			$result = $this->runEntrypoint($door, $xml, true, 'success', 'off');
			$this->assertTrue(strpos($result['status'], '403 Forbidden') !== false
				&& $result['state']['sends'] === 0,
				$door.' disabled proxy refuses before any socket action send');
		}
	}
	public function testBothDoorsRejectMalformedOrMixedSocketRequestsWithoutSend()
	{
		$malformed = '<methodCall><methodName>system.sockets.adjust_alloc</methodName>';
		$badShape = '<methodCall><methodName>system.sockets.adjust_alloc</methodName>'
			.'<params><param><value><string>extra</string></value></param></params></methodCall>';
		$denied = '<methodCall><methodName>system.multicall</methodName><params><param>'
			.'<value><array><data>'
			.'<value><struct><member><name>methodName</name><value><string>system.sockets.adjust_alloc</string></value></member>'
			.'<member><name>params</name><value><array><data></data></array></value></member></struct></value>'
			.'<value><struct><member><name>methodName</name><value><string>execute.capture</string></value></member>'
			.'<member><name>params</name><value><array><data></data></array></value></member></struct></value>'
			.'</data></array></value></param></params></methodCall>';
		$plainText = '<methodCall><methodName>d.custom1.set</methodName><params>'
			.'<param><value><string>'.str_repeat('A', 40).'</string></value></param>'
			.'<param><value><string>system.sockets. is plain text</string></value></param>'
			.'</params></methodCall>';
		foreach(array('action', 'rpc2') as $door)
		{
			foreach(array($malformed, $denied) as $xml)
			{
				$r = $this->runEntrypoint($door, $xml, true);
				$this->assertTrue(strpos($r['status'], '403') !== false && $r['state']['sends'] === 0,
					$door.' refuses malformed or denied socket XML without contacting rTorrent');
			}
			$r = $this->runEntrypoint($door, $badShape, true);
			$this->assertTrue(strpos($r['status'], '200') !== false && $r['state']['sends'] === 1
				&& $r['state']['trusted'] === false && $r['state']['payload'] === $badShape,
				$door.' leaves even an extra socket argument to the daemon untrusted gate');
			$r = $this->runEntrypoint($door, $plainText, true);
			$this->assertTrue(strpos($r['status'], '200') !== false && $r['state']['sends'] === 1,
				$door.' sends a harmless argument containing a socket method name only once');
		}
	}

	public function testBothDoorsForwardSocketMutatorsWithoutTrustAndRelayDaemonRefusal()
	{
		$xml = '<methodCall><methodName>system.sockets.adjust_alloc</methodName><params></params></methodCall>';
		$setter = '<methodCall><methodName>system.sockets.files.max_alloc.set</methodName>'
			.'<params><param><value><string></string></value></param>'
			.'<param><value><i8>1048576</i8></value></param></params></methodCall>';
		$batch = $this->systemBatchXml(array(
			array('system.sockets.files.max_alloc.set', array('', 1048576)),
			array('system.sockets.adjust_alloc', array()),
		));
		foreach(array('action', 'rpc2') as $door)
		{
			foreach(array($xml, $setter) as $direct)
			{
				$r = $this->runEntrypoint($door, $direct, true, 'socket-refused');
				$this->assertTrue(strpos($r['status'], '200 OK') !== false
					&& $r['state']['sends'] === 1 && $r['state']['trusted'] === false
					&& $r['state']['payload'] === $direct,
					$door.' forwards the direct socket method once without trust');
				$this->assertTrue(strpos($r['body'], '<i4>-507</i4>') !== false,
					$door.' relays the daemon untrusted refusal without changing its fault code');
			}
			$r = $this->runEntrypoint($door, $batch, true);
			$this->assertTrue(strpos($r['status'], '200 OK') !== false
				&& $r['state']['sends'] === 1 && $r['state']['trusted'] === false,
				$door.' forwards a homogeneous socket batch untrusted once');
		}
	}

	public function testBothDoorsApplySharedPolicyFromSymlinkedConfVolume()
	{
		foreach(array('action', 'rpc2') as $door)
		{
			$r = $this->runEntrypoint($door, $this->viewActionXml(), true,
				'success', 'symlink');
			$this->assertTrue(strpos($r['status'], '403 Forbidden') !== false
				&& $r['state']['sends'] === 0
				&& strpos($r['body'], '<i4>-501</i4>') !== false,
				$door.' applies the volume policy override and refuses the write');
		}
	}

	public function testBothDoorsDecideTheSameTrustForTheSameRequest()
	{
		$httprpc = $this->runEntrypoint('action', $this->viewActionXml(), true);
		$this->assertEquals(true, $httprpc['state']['trusted'],
			'httprpc sends a view-action multicall on a trusted connection');
		$this->assertTrue(in_array('xmlrpc-proxy: trusted: d.multicall2 (3 params)',
			$httprpc['state']['logs'], true),
			'httprpc rebuilt the multicall from the shared policy');

		$rpc2 = $this->runEntrypoint('rpc2', $this->viewActionXml(), true);
		$this->assertRpc2Log($rpc2, 'trusted: d.multicall2 (3 params)',
			'rpc2 reaches that same decision on that same request');
		$this->assertTrue(strpos($rpc2['rpc2logs'], 'untrusted') === false,
			'and neither door falls back to forwarding it untrusted');
	}

	public function testBothDoorsForwardSonarrImportedViewWithoutTrust()
	{
		$hash = str_repeat('a', 40);
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.views.push_back_unique</methodName><params>'
			.'<param><value><string>'.$hash.'</string></value></param>'
			.'<param><value><string>sonarr_imported</string></value></param>'
			.'</params></methodCall>';
		foreach(array('action', 'rpc2') as $door)
		{
			$r = $this->runEntrypoint($door, $xml, true);
			$this->assertTrue(strpos($r['status'], '200 OK') !== false
				&& $r['state']['sends'] === 1 && $r['state']['trusted'] === false,
				$door.' forwards the Sonarr view call without trust');
			$this->assertTrue($r['state']['payload_sha256'] === hash('sha256', $xml),
				$door.' keeps the exact direct-call bytes');
		}
	}

	public function testBothDoorsDenyOldLoadAndSizeLimitAliases()
	{
		foreach(array('load_start', 'set_xmlrpc_size_limit') as $method)
			foreach(array('action', 'rpc2') as $door)
			{
				$xml = '<?xml version="1.0"?><methodCall><methodName>'.$method
					.'</methodName><params></params></methodCall>';
				$r = $this->runEntrypoint($door, $xml, true);
				$this->assertTrue(strpos($r['status'], '403 Forbidden') !== false
					&& $r['state']['sends'] === 0,
					$door.' denies the '.$method.' legacy bypass before transport');
			}
	}

	public function testBothDoorsSendAllUntrustedReadBatchesWithoutTrust()
	{
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.multicall</methodName><params>'
			.'<param><value><array><data><value><struct>'
			.'<member><name>methodName</name><value><string>system.client_version</string></value></member>'
			.'<member><name>params</name><value><array><data></data></array></value></member>'
			.'</struct></value></data></array></value></param></params></methodCall>';
		foreach(array('action', 'rpc2') as $door)
		{
			$r = $this->runEntrypoint($door, $xml, true);
			$this->assertTrue(strpos($r['status'], '200 OK') !== false
				&& $r['state']['sends'] === 1 && $r['state']['trusted'] === false,
				$door.' sends an all-untrusted read batch without a trusted connection');
		}
	}

	public function testBothDoorsAcceptExactShowPeersReadExpressions()
	{
		$expression = 'cat="$t.multicall=d.hash=,t.scrape_complete=,cat={#}"';
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall2</methodName><params>'
			.'<param><value><string></string></value></param>'
			.'<param><value><string>main</string></value></param>'
			.'<param><value><string>d.hash=</string></value></param>'
			.'<param><value><string>'.$expression.'</string></value></param>'
			.'</params></methodCall>';
		foreach(array('action', 'rpc2') as $door)
		{
			$r = $this->runEntrypoint($door, $xml, true);
			$this->assertTrue(strpos($r['status'], '200 OK') !== false
				&& $r['state']['sends'] === 1 && $r['state']['trusted'] === true,
				$door.' accepts the exact read-only tracker expression');
			$this->assertTrue(strpos($r['state']['payload'], $expression) !== false,
				$door.' retains the captured tracker expression');
		}
	}

	public function testBothDoorsSendStopBatchAndDeferGetSavePathBatches()
	{
		$hash = str_repeat('A', 40);
		$stop = $this->systemBatchXml(array(
			array('d.stop', array($hash)), array('d.close', array($hash)),
		));
		foreach(array('action', 'rpc2') as $door)
		{
			$result = $this->runEntrypoint($door, $stop, true, 'success', 'none');
			$this->assertTrue(strpos($result['status'], '200 OK') !== false
				&& $result['state']['sends'] === 1 && $result['state']['trusted'] === true,
				$door.' sends the checked raw WebUI stop batch once');
			$log = ($door === 'rpc2') ? $result['rpc2logs'] : implode("\n", $result['state']['logs']);
			$this->assertEquals(1, substr_count($log, '[built-in policy]'),
				$door.' marks only the outer stop batch with the fallback policy');
			$this->assertEquals(1, count(array_filter(explode("\n", trim($log)), 'strlen')),
				$door.' writes one normal summary line for the successful stop batch');
			foreach(array('d.base_path', 'd.get_base_path') as $method)
			{
				$path = $this->systemBatchXml(array(
					array('d.open', array($hash)), array($method, array($hash)),
					array('d.close', array($hash)),
				));
				$r = $this->runEntrypoint($door, $path, true);
				$this->assertTrue(strpos($r['status'], '403 Forbidden') !== false
					&& $r['state']['sends'] === 0,
					$door.' defers the '.$method.' getsavepath batch without sending it');
			}
			$bad = $this->systemBatchXml(array(
				array('d.stop', array($hash)), array('d.close', array($hash, 'extra')),
			));
			$result = $this->runEntrypoint($door, $bad, true);
			$this->assertTrue(strpos($result['status'], '403 Forbidden') !== false
				&& $result['state']['sends'] === 0,
				$door.' rejects an unvalidated close member without sending any batch');
		}
	}

	public function testBothDoorsKeepHomogeneousFallbackUntrustedAndRejectReverseMixedBatch()
	{
		$hash = str_repeat('A', 40);
		$fallback = $this->systemBatchXml(array(
			array('d.views.push_back_unique', array($hash, 'sonarr_imported')),
			array('d.views.push_back_unique', array($hash, 'radarr_imported')),
		));
		$mixed = $this->systemBatchXml(array(
			array('d.wibble.set', array($hash, '1')),
			array('d.stop', array($hash)),
		));
		foreach(array('action', 'rpc2') as $door)
		{
			foreach(array('sonarr_imported', 'radarr_imported') as $view)
			{
				$direct = '<methodCall><methodName>d.views.push_back_unique</methodName><params>'
					.'<param><value><string>'.$hash.'</string></value></param>'
					.'<param><value><string>'.$view.'</string></value></param>'
					.'</params></methodCall>';
				$single = $this->runEntrypoint($door, $direct, true);
				$this->assertTrue(strpos($single['status'], '200 OK') !== false
					&& $single['state']['sends'] === 1 && $single['state']['trusted'] === false,
					$door.' forwards '.$view.' individually without trust');
			}
			$ok = $this->runEntrypoint($door, $fallback, true);
			$this->assertTrue(strpos($ok['status'], '200 OK') !== false
				&& $ok['state']['sends'] === 1 && $ok['state']['trusted'] === false,
				$door.' sends a homogeneous fallback batch without trust');
			$no = $this->runEntrypoint($door, $mixed, true);
			$this->assertTrue(strpos($no['status'], '403 Forbidden') !== false
				&& $no['state']['sends'] === 0,
				$door.' refuses an untrusted-first mixed batch without any send');
		}
	}

	public function testBothDoorsSendOnlyFullyCheckedSystemMulticalls()
	{
		$hash = str_repeat('A', 40);
		$member = function($method) use ($hash) {
			return '<value><struct><member><name>methodName</name><value><string>'
				.$method.'</string></value></member><member><name>params</name>'
				.'<value><array><data><value><string>'.$hash.'</string></value></data></array>'
				.'</value></member></struct></value>';
		};
		$wrap = function($members) {
			return '<methodCall><methodName>system.multicall</methodName><params>'
				.'<param><value><array><data>'.$members.'</data></array></value></param>'
				.'</params></methodCall>';
		};
		$allowed = $wrap($member('d.stop').$member('d.start'));
		$denied = $wrap($member('d.stop').$member('system.client_version'));
		foreach(array('action', 'rpc2') as $door)
		{
			$ok = $this->runEntrypoint($door, $allowed, true);
			$this->assertTrue(strpos($ok['status'], '200 OK') !== false
				&& $ok['state']['sends'] === 1 && $ok['state']['trusted'] === true,
				$door.' sends a fully checked WebUI batch exactly once as trusted');
			$this->assertTrue($ok['state']['payload_length'] === 703
				&& $ok['state']['payload_sha256'] === self::CANONICAL_SYSTEM_BATCH_SHA256,
				$door.' sends the canonical checked batch bytes');
			$no = $this->runEntrypoint($door, $denied, true);
			$this->assertTrue(strpos($no['status'], '403 Forbidden') !== false && $no['state']['sends'] === 0,
				$door.' refuses a batch whose second member was not trusted');
			$this->assertTrue(strpos($no['body'],
				"The command 'system.multicall' was rejected by this server.") !== false,
				$door.' retains the outer client-visible fault');
		}
	}

	/**
	 * An install without conf/xmlrpc_proxy.php is not an install that meant to
	 * forbid every command parameter, and treating it as one is how a client
	 * loses its labels and its directories for a reason nothing on its side can
	 * see. Measured on the live instance 2026-09-12: the container replaces
	 * conf/ with a volume seeded once, long before that file existed, so the
	 * policy reached the door only while a second copy of the list lived in
	 * plugins/httprpc/conf.php -- and upstream #3251 removed that copy.
	 *
	 * The decision names the built-in policy without a separate warning per poll.
	 */
	public function testHttprpcNamesTheBuiltInPolicyInOneDecisionLine()
	{
		$result = $this->runEntrypoint('action', $this->viewActionXml(), true, 'success', 'none');
		$this->assertHttp($result, '200 OK', 'text/xml; charset=UTF-8');
		$this->assertTrue($result['policy_exists'] === false, 'the policy file is absent');
		$this->assertEquals(array('xmlrpc-proxy: trusted: d.multicall2 (3 params) [built-in policy]'),
			$result['state']['logs'], 'one decision line identifies the fallback without a repeated warning');
		$this->assertFullTranscript('httprpc', $result['state'], 1,
			self::CANONICAL_MULTICALL_PAYLOAD, self::CANONICAL_MULTICALL_LENGTH,
			self::CANONICAL_MULTICALL_SHA256, null, null, true, null, null,
			array(
				array('event' => 'send', 'door' => 'httprpc', 'trusted' => true, 'sends' => 1),
				array('event' => 'response', 'door' => 'httprpc'),
			));
	}

	public function testRpc2FallsBackToTheBuiltInPolicyToo()
	{
		$result = $this->runEntrypoint('rpc2', $this->viewActionXml(), true, 'success', 'none');
		$this->assertHttp($result, '200 OK', 'text/xml;charset=UTF-8');
		$this->assertTrue($result['policy_exists'] === false, 'the shared policy file is absent');
		$this->assertRpc2Log($result, 'trusted: d.multicall2 (3 params) [built-in policy]', 'the decision names its fallback policy');
		$this->assertTrue(count(explode("\n", trim($result['rpc2logs']))) === 1, 'no separate fallback warning is emitted');
		$this->assertFullTranscript('rpc2', $result['state'], 1,
			self::CANONICAL_MULTICALL_PAYLOAD, self::CANONICAL_MULTICALL_LENGTH,
			self::CANONICAL_MULTICALL_SHA256, '127.0.0.1', 1, true,
			array('timeout' => 30, 'transferTimeout' => null, 'maxResponseBytes' => null), 1,
			array(array('event' => 'send', 'door' => 'rpc2', 'trusted' => true, 'sends' => 1)));
	}

	public function testExplicitRestrictedAndEmptyPoliciesOverrideDefaultsAtBothDoors()
	{
		foreach(array('restricted', 'empty') as $policy)
			foreach(array('action', 'rpc2') as $door)
			{
				$r = $this->runEntrypoint($door, $this->viewActionXml(), true, 'success', $policy);
				$this->assertTrue(strpos($r['status'], '403') !== false, $door.' honors the '.$policy.' override');
				$this->assertTrue($r['state']['sends'] === 0, 'an override refusal never reaches transport');
				$logs = $door === 'rpc2' ? $r['rpc2logs'] : implode(' ', $r['state']['logs']);
				$this->assertTrue(strpos($logs, '[built-in policy]') === false,
					$door.' labels the '.$policy.' override as explicit policy');
				$xml = str_replace('d.stop=', 'd.name=', $this->viewActionXml());
				$r = $this->runEntrypoint($door, $xml, true, 'success', $policy);
				$this->assertTrue($r['state']['sends'] === 1 && $r['state']['trusted'] === true, 'read-only listing remains available under a restricted setter policy');
			}
	}

	public function testInvalidExplicitPolicyFailsVisiblyAtBothDoors()
	{
		foreach(array(true, false) as $logging)
			foreach(array(array('action', 'null'), array('rpc2', 'null'),
				array('action', 'plugin_null')) as $case)
			{
				list($door, $policy) = $case;
				$r = $this->runEntrypoint($door, $this->viewActionXml(), $logging, 'success', $policy);
				$this->assertTrue(strpos($r['status'], '503') !== false,
					$door.' refuses an invalid policy with a visible HTTP status');
				$this->assertHttp($r, '503 Service Unavailable',
					$door === 'action' ? 'text/xml; charset=UTF-8' : 'text/xml;charset=UTF-8');
				$this->assertTrue(strpos($r['body'], '<i4>-501</i4>') !== false,
					$door.' returns a parseable XMLRPC fault for invalid policy');
				$this->assertTrue(strpos($r['body'], 'XMLRPCProxySafeParams') !== false,
					$door.' names the invalid policy key in the response');
				$this->assertTrue($r['state']['sends'] === 0,
					$door.' does not reach rTorrent with an invalid policy');
				$log = $door === 'rpc2' ? $r['rpc2logs'] : implode(' ', $r['state']['logs']);
				$this->assertTrue(strpos($log, 'XMLRPCProxySafeParams must be an array') !== false,
					$door.' logs the configuration error with routine logging '.($logging ? 'on' : 'off'));
			}
	}

	public function testUnreadablePolicyFailsClosedAtBothDoors()
	{
		if(testSkipUnlessPermissionsBite('an unreadable conf/xmlrpc_proxy.php fails closed at both doors')) return;
		foreach(array(true, false) as $logging)
			foreach(array('action', 'rpc2') as $door)
			{
				$r = $this->runEntrypoint($door, $this->viewActionXml(), $logging, 'success', 'unreadable');
				$this->assertTrue(strpos($r['status'], '503') !== false, $door.' refuses an unreadable policy instead of widening it');
				$this->assertHttp($r, '503 Service Unavailable',
					$door === 'action' ? 'text/xml; charset=UTF-8' : 'text/xml;charset=UTF-8');
				$this->assertTrue(strpos($r['body'], '<i4>-501</i4>') !== false,
					$door.' returns a parseable XMLRPC fault for unreadable policy');
				$this->assertTrue($r['state']['sends'] === 0, 'unreadable policy never reaches transport');
				$log = $door === 'rpc2' ? $r['rpc2logs'] : implode(' ', $r['state']['logs']);
				$this->assertTrue(strpos($log, 'exists but is not readable') !== false,
					$door.' reports the configuration failure with routine logging '.($logging ? 'on' : 'off'));
			}
	}

	public function testBothDoorsRejectMalformedOwnedCallWith403And501()
	{
		$malformedXml = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params></params></methodCall>';
		$httprpc = $this->runEntrypoint('action', $malformedXml, true);
		$this->assertHttp($httprpc, '403 Forbidden', 'text/xml; charset=UTF-8');
		$this->assertTrue(strpos($httprpc['body'], '<i4>-501</i4>') !== false,
			'httprpc malformed load refusal returns the XMLRPC -501 envelope');
		$this->assertTrue(strpos($httprpc['body'],
			"The command 'load.start' was rejected by this server.") !== false,
			'httprpc malformed load refusal names load.start');
		$this->assertFullTranscript('httprpc', $httprpc['state'], 0, null, null, null,
			null, null, null, null, null,
			array(array('event' => 'response', 'door' => 'httprpc')));

		$rpc2 = $this->runEntrypoint('rpc2', $malformedXml, true);
		$this->assertHttp($rpc2, '403 Forbidden', 'text/xml;charset=UTF-8');
		$this->assertTrue(strpos($rpc2['body'],
			"The command 'load.start' was rejected by this server.") !== false,
			'rpc2 malformed load refusal names load.start');
		$this->assertTrue(strpos($rpc2['body'], '<i4>-501</i4>') !== false,
			'rpc2 malformed load refusal returns the XMLRPC -501 envelope');
		$this->assertFullTranscript('rpc2', $rpc2['state'], 0, null, null, null,
			null, null, null, null, null, array());
	}

	public function testBothDoorsRejectNestedMulticallDenialNamingOuterMethod()
	{
		$nestedDeniedXml = '<?xml version="1.0"?><methodCall><methodName>d.multicall2</methodName>'
			. '<params><param><value><string></string></value></param>'
			. '<param><value><string>main</string></value></param>'
			. '<param><value><string>execute.capture=/bin/id</string></value></param>'
			. '</params></methodCall>';
		$httprpc = $this->runEntrypoint('action', $nestedDeniedXml, true);
		$this->assertHttp($httprpc, '403 Forbidden', 'text/xml; charset=UTF-8');
		$this->assertTrue(strpos($httprpc['body'], '<i4>-501</i4>') !== false,
			'httprpc nested denial returns XMLRPC -501 envelope');
		$this->assertTrue(strpos($httprpc['body'],
			"The command 'd.multicall2' was rejected by this server.") !== false,
			'httprpc nested denial names outer method d.multicall2');
		$this->assertFullTranscript('httprpc', $httprpc['state'], 0, null, null, null,
			null, null, null, null, null,
			array(array('event' => 'response', 'door' => 'httprpc')));

		$rpc2 = $this->runEntrypoint('rpc2', $nestedDeniedXml, true);
		$this->assertHttp($rpc2, '403 Forbidden', 'text/xml;charset=UTF-8');
		$this->assertTrue(strpos($rpc2['body'],
			"The command 'd.multicall2' was rejected by this server.") !== false,
			'rpc2 nested denial names outer method d.multicall2');
		$this->assertTrue(strpos($rpc2['body'], '<i4>-501</i4>') !== false,
			'rpc2 nested denial returns the XMLRPC -501 envelope');
		$this->assertFullTranscript('rpc2', $rpc2['state'], 0, null, null, null,
			null, null, null, null, null, array());
	}

	public function testBothDoorsPreserveOuterIdentityAfterLaterParameterFailure()
	{
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall2</methodName>'
			. '<params><param><value><string></string></value></param>'
			. '<param></param></params></methodCall>';

		$httprpc = $this->runEntrypoint('action', $xml, true);
		$this->assertHttp($httprpc, '403 Forbidden', 'text/xml; charset=UTF-8');
		$this->assertTrue(strpos($httprpc['body'], '<i4>-501</i4>') !== false,
			'httprpc returns XMLRPC -501 fault');
		$this->assertTrue(strpos($httprpc['body'],
			"The command 'd.multicall2' was rejected by this server.") !== false,
			'httprpc fault names normalized outer method d.multicall2');
		$this->assertFullTranscript('httprpc', $httprpc['state'], 0, null, null, null,
			null, null, null, null, null,
			array(array('event' => 'response', 'door' => 'httprpc')));

		$rpc2 = $this->runEntrypoint('rpc2', $xml, true);
		$this->assertHttp($rpc2, '403 Forbidden', 'text/xml;charset=UTF-8');
		$this->assertTrue(strpos($rpc2['body'], '<i4>-501</i4>') !== false,
			'rpc2 returns XMLRPC -501 fault');
		$this->assertTrue(strpos($rpc2['body'],
			"The command 'd.multicall2' was rejected by this server.") !== false,
			'rpc2 fault names normalized outer method d.multicall2');
		$this->assertFullTranscript('rpc2', $rpc2['state'], 0, null, null, null,
			null, null, null, null, null, array());
	}

	public function testBothDoorsSendOrdinaryPayloadOnceUntrusted()
	{
		$xml = $this->allowedXml();

		$httprpc = $this->runEntrypoint('action', $xml, true);
		$this->assertHttp($httprpc, '200 OK', 'text/xml; charset=UTF-8');
		$this->assertFullTranscript('httprpc', $httprpc['state'], 1,
			self::ORDINARY_PAYLOAD, self::ORDINARY_LENGTH, self::ORDINARY_SHA256,
			null, null, false, null, null,
			array(
				array('event' => 'send', 'door' => 'httprpc', 'trusted' => false, 'sends' => 1),
				array('event' => 'response', 'door' => 'httprpc'),
			));

		$rpc2 = $this->runEntrypoint('rpc2', $xml, true);
		$this->assertHttp($rpc2, '200 OK', 'text/xml;charset=UTF-8');
		$this->assertFullTranscript('rpc2', $rpc2['state'], 1,
			self::ORDINARY_PAYLOAD, self::ORDINARY_LENGTH, self::ORDINARY_SHA256,
			'127.0.0.1', 1, false,
			array('timeout' => 30, 'transferTimeout' => null, 'maxResponseBytes' => null), 1,
			array(
				array('event' => 'send', 'door' => 'rpc2', 'trusted' => false, 'sends' => 1),
			));
	}

	public function testBothDoorsSendCanonicalOwnedPayloadOnceTrusted()
	{
		$xml = $this->viewActionXml();

		$httprpc = $this->runEntrypoint('action', $xml, true);
		$this->assertHttp($httprpc, '200 OK', 'text/xml; charset=UTF-8');
		$this->assertFullTranscript('httprpc', $httprpc['state'], 1,
			self::CANONICAL_MULTICALL_PAYLOAD, self::CANONICAL_MULTICALL_LENGTH, self::CANONICAL_MULTICALL_SHA256,
			null, null, true, null, null,
			array(
				array('event' => 'send', 'door' => 'httprpc', 'trusted' => true, 'sends' => 1),
				array('event' => 'response', 'door' => 'httprpc'),
			));

		$rpc2 = $this->runEntrypoint('rpc2', $xml, true);
		$this->assertHttp($rpc2, '200 OK', 'text/xml;charset=UTF-8');
		$this->assertFullTranscript('rpc2', $rpc2['state'], 1,
			self::CANONICAL_MULTICALL_PAYLOAD, self::CANONICAL_MULTICALL_LENGTH, self::CANONICAL_MULTICALL_SHA256,
			'127.0.0.1', 1, true,
			array('timeout' => 30, 'transferTimeout' => null, 'maxResponseBytes' => null), 1,
			array(
				array('event' => 'send', 'door' => 'rpc2', 'trusted' => true, 'sends' => 1),
			));
	}

	public function testBothDoorsRejectMalformedOwnedWithoutTransport()
	{
		$malformedXml = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params></params></methodCall>';

		$httprpc = $this->runEntrypoint('action', $malformedXml, true);
		$this->assertHttp($httprpc, '403 Forbidden', 'text/xml; charset=UTF-8');
		$this->assertTrue(strpos($httprpc['body'], '<i4>-501</i4>') !== false, 'httprpc -501 fault');
		$this->assertTrue(strpos($httprpc['body'], "The command 'load.start' was rejected by this server.") !== false,
			'httprpc names load.start');
		$this->assertFullTranscript('httprpc', $httprpc['state'], 0, null, null, null,
			null, null, null, null, null,
			array(array('event' => 'response', 'door' => 'httprpc')));

		$rpc2 = $this->runEntrypoint('rpc2', $malformedXml, true);
		$this->assertHttp($rpc2, '403 Forbidden', 'text/xml;charset=UTF-8');
		$this->assertTrue(strpos($rpc2['body'], '<i4>-501</i4>') !== false, 'rpc2 -501 fault');
		$this->assertTrue(strpos($rpc2['body'], "The command 'load.start' was rejected by this server.") !== false,
			'rpc2 names load.start');
		$this->assertFullTranscript('rpc2', $rpc2['state'], 0, null, null, null,
			null, null, null, null, null, array());
	}

	public function testBothDoorsRejectNestedMulticallWithoutTransport()
	{
		$nestedXml = '<?xml version="1.0"?><methodCall><methodName>d.multicall2</methodName>'
			. '<params><param><value><string></string></value></param>'
			. '<param><value><string>main</string></value></param>'
			. '<param><value><string>execute.capture=/bin/id</string></value></param></params></methodCall>';

		$httprpc = $this->runEntrypoint('action', $nestedXml, true);
		$this->assertHttp($httprpc, '403 Forbidden', 'text/xml; charset=UTF-8');
		$this->assertTrue(strpos($httprpc['body'], '<i4>-501</i4>') !== false, 'httprpc -501 fault');
		$this->assertTrue(strpos($httprpc['body'], "The command 'd.multicall2' was rejected by this server.") !== false,
			'httprpc names d.multicall2');
		$this->assertFullTranscript('httprpc', $httprpc['state'], 0, null, null, null,
			null, null, null, null, null,
			array(array('event' => 'response', 'door' => 'httprpc')));

		$rpc2 = $this->runEntrypoint('rpc2', $nestedXml, true);
		$this->assertHttp($rpc2, '403 Forbidden', 'text/xml;charset=UTF-8');
		$this->assertTrue(strpos($rpc2['body'], '<i4>-501</i4>') !== false, 'rpc2 -501 fault');
		$this->assertTrue(strpos($rpc2['body'], "The command 'd.multicall2' was rejected by this server.") !== false,
			'rpc2 names d.multicall2');
		$this->assertFullTranscript('rpc2', $rpc2['state'], 0, null, null, null,
			null, null, null, null, null, array());
	}

	public function testBothDoorsReturnNeutralStatusAfterOneTransportFailure()
	{
		$xml = $this->allowedXml();

		$httprpc = $this->runEntrypoint('action', $xml, true, 'false');
		$this->assertHttp($httprpc, '500 Server Error', 'text/html; charset=UTF-8',
			'Could not complete the rTorrent XMLRPC request.');
		$this->assertFullTranscript('httprpc', $httprpc['state'], 1,
			self::ORDINARY_PAYLOAD, self::ORDINARY_LENGTH, self::ORDINARY_SHA256,
			null, null, false, null, null,
			array(
				array('event' => 'send', 'door' => 'httprpc', 'trusted' => false, 'sends' => 1),
				array('event' => 'response', 'door' => 'httprpc'),
			));

		$rpc2 = $this->runEntrypoint('rpc2', $xml, true, 'false');
		$this->assertHttp($rpc2, '502 Bad Gateway', 'text/xml;charset=UTF-8');
		$this->assertFullTranscript('rpc2', $rpc2['state'], 1,
			self::ORDINARY_PAYLOAD, self::ORDINARY_LENGTH, self::ORDINARY_SHA256,
			'127.0.0.1', 1, false,
			array('timeout' => 30, 'transferTimeout' => null, 'maxResponseBytes' => null), 1,
			array(
				array('event' => 'send', 'door' => 'rpc2', 'trusted' => false, 'sends' => 1),
			));
	}

	public function testBothDoorsLoggingDoesNotChangeTransport()
	{
		$dispositions = array(
			'zero_call' => array(
				'xml' => $this->deniedXml(),
				'send' => 'success',
				'sends' => 0,
				'payload' => null,
				'length' => null,
				'sha' => null,
				'trusted' => null,
				'httprpc_events' => array(array('event' => 'response', 'door' => 'httprpc')),
				'rpc2_events' => array(),
				'httprpc_status' => '403 Forbidden',
				'httprpc_type' => 'text/xml; charset=UTF-8',
				'rpc2_status' => '403 Forbidden',
				'rpc2_type' => 'text/xml;charset=UTF-8',
			),
			'ordinary_pass' => array(
				'xml' => $this->allowedXml(),
				'send' => 'success',
				'sends' => 1,
				'payload' => self::ORDINARY_PAYLOAD,
				'length' => self::ORDINARY_LENGTH,
				'sha' => self::ORDINARY_SHA256,
				'trusted' => false,
				'httprpc_events' => array(
					array('event' => 'send', 'door' => 'httprpc', 'trusted' => false, 'sends' => 1),
					array('event' => 'response', 'door' => 'httprpc'),
				),
				'rpc2_events' => array(
					array('event' => 'send', 'door' => 'rpc2', 'trusted' => false, 'sends' => 1),
				),
				'httprpc_status' => '200 OK',
				'httprpc_type' => 'text/xml; charset=UTF-8',
				'rpc2_status' => '200 OK',
				'rpc2_type' => 'text/xml;charset=UTF-8',
			),
			'canonical_owned' => array(
				'xml' => $this->viewActionXml(),
				'send' => 'success',
				'sends' => 1,
				'payload' => self::CANONICAL_MULTICALL_PAYLOAD,
				'length' => self::CANONICAL_MULTICALL_LENGTH,
				'sha' => self::CANONICAL_MULTICALL_SHA256,
				'trusted' => true,
				'httprpc_events' => array(
					array('event' => 'send', 'door' => 'httprpc', 'trusted' => true, 'sends' => 1),
					array('event' => 'response', 'door' => 'httprpc'),
				),
				'rpc2_events' => array(
					array('event' => 'send', 'door' => 'rpc2', 'trusted' => true, 'sends' => 1),
				),
				'httprpc_status' => '200 OK',
				'httprpc_type' => 'text/xml; charset=UTF-8',
				'rpc2_status' => '200 OK',
				'rpc2_type' => 'text/xml;charset=UTF-8',
			),
			'transport_failure' => array(
				'xml' => $this->allowedXml(),
				'send' => 'false',
				'sends' => 1,
				'payload' => self::ORDINARY_PAYLOAD,
				'length' => self::ORDINARY_LENGTH,
				'sha' => self::ORDINARY_SHA256,
				'trusted' => false,
				'httprpc_events' => array(
					array('event' => 'send', 'door' => 'httprpc', 'trusted' => false, 'sends' => 1),
					array('event' => 'response', 'door' => 'httprpc'),
				),
				'rpc2_events' => array(
					array('event' => 'send', 'door' => 'rpc2', 'trusted' => false, 'sends' => 1),
				),
				'httprpc_status' => '500 Server Error',
				'httprpc_type' => 'text/html; charset=UTF-8',
				'rpc2_status' => '502 Bad Gateway',
				'rpc2_type' => 'text/xml;charset=UTF-8',
			),
		);

		foreach($dispositions as $name => $disp)
		{
			// httprpc: logged vs quiet
			$httprpcLogged = $this->runEntrypoint('action', $disp['xml'], true, $disp['send']);
			$httprpcQuiet = $this->runEntrypoint('action', $disp['xml'], false, $disp['send']);
			$this->assertHttp($httprpcLogged, $disp['httprpc_status'], $disp['httprpc_type']);
			$this->assertHttp($httprpcQuiet, $disp['httprpc_status'], $disp['httprpc_type']);
			$this->assertFullTranscript('httprpc logged (' . $name . ')', $httprpcLogged['state'],
				$disp['sends'], $disp['payload'], $disp['length'], $disp['sha'],
				null, null, $disp['trusted'], null, null, $disp['httprpc_events']);
			$this->assertFullTranscript('httprpc quiet (' . $name . ')', $httprpcQuiet['state'],
				$disp['sends'], $disp['payload'], $disp['length'], $disp['sha'],
				null, null, $disp['trusted'], null, null, $disp['httprpc_events']);
			$this->assertTranscriptsStrictlyIdentical('httprpc (' . $name . ')', $httprpcLogged['state'], $httprpcQuiet['state']);
			$this->assertTrue(count($httprpcLogged['state']['logs']) >= count($httprpcQuiet['state']['logs']),
				'httprpc (' . $name . ') logged has at least as many log entries');
			if($disp['sends'] > 0 && $disp['trusted'])
			{
				$this->assertTrue(in_array('xmlrpc-proxy: trusted: d.multicall2 (3 params)', $httprpcLogged['state']['logs'], true),
					'httprpc (' . $name . ') logged has proxy log');
				$this->assertTrue(!in_array('xmlrpc-proxy: trusted: d.multicall2 (3 params)', $httprpcQuiet['state']['logs'], true),
					'httprpc (' . $name . ') quiet has no proxy log');
			}

			// rpc2: logged vs quiet
			$rpc2Logged = $this->runEntrypoint('rpc2', $disp['xml'], true, $disp['send']);
			$rpc2Quiet = $this->runEntrypoint('rpc2', $disp['xml'], false, $disp['send']);
			$this->assertHttp($rpc2Logged, $disp['rpc2_status'], $disp['rpc2_type']);
			$this->assertHttp($rpc2Quiet, $disp['rpc2_status'], $disp['rpc2_type']);
			$rpc2Host = ($disp['sends'] > 0) ? '127.0.0.1' : null;
			$rpc2Port = ($disp['sends'] > 0) ? 1 : null;
			$rpc2Timeouts = ($disp['sends'] > 0) ? array('timeout' => 30, 'transferTimeout' => null, 'maxResponseBytes' => null) : null;
			$rpc2ResponseMode = ($disp['sends'] > 0) ? 1 : null;
			$this->assertFullTranscript('rpc2 logged (' . $name . ')', $rpc2Logged['state'],
				$disp['sends'], $disp['payload'], $disp['length'], $disp['sha'],
				$rpc2Host, $rpc2Port, $disp['trusted'], $rpc2Timeouts, $rpc2ResponseMode, $disp['rpc2_events']);
			$this->assertFullTranscript('rpc2 quiet (' . $name . ')', $rpc2Quiet['state'],
				$disp['sends'], $disp['payload'], $disp['length'], $disp['sha'],
				$rpc2Host, $rpc2Port, $disp['trusted'], $rpc2Timeouts, $rpc2ResponseMode, $disp['rpc2_events']);
			$this->assertTranscriptsStrictlyIdentical('rpc2 (' . $name . ')', $rpc2Logged['state'], $rpc2Quiet['state']);
			$this->assertTrue($rpc2Quiet['rpc2logs'] === '', 'quiet rpc2 writes no diagnostic log');
			if($disp['sends'] > 0 && $disp['trusted'])
			{
				$this->assertTrue(strpos($rpc2Logged['rpc2logs'], 'trusted: d.multicall2 (3 params)') !== false,
					'rpc2 (' . $name . ') logged records diagnostic');
			}
		}
	}


	/**
	 * "Stop all": what a client sends to act on a whole view. rtorrent refuses
	 * d.stop to an untrusted caller, so a door that forwards this untrusted
	 * answers a fault where the other door succeeds.
	 */
	private function systemBatchXml($calls)
	{
		$members = '';
		foreach($calls as $call)
		{
			$members .= '<value><struct><member><name>methodName</name><value><string>'
				.$call[0].'</string></value></member><member><name>params</name>'
				.'<value><array><data>';
			foreach($call[1] as $param)
				$members .= '<value><string>'.$param.'</string></value>';
			$members .= '</data></array></value></member></struct></value>';
		}
		return '<methodCall><methodName>system.multicall</methodName><params>'
			.'<param><value><array><data>'.$members.'</data></array></value></param>'
			.'</params></methodCall>';
	}

	private function viewActionXml()
	{
		return '<?xml version="1.0"?><methodCall><methodName>d.multicall2</methodName>'
			. '<params><param><value><string></string></value></param>'
			. '<param><value><string>default</string></value></param>'
			. '<param><value><string>d.stop=</string></value></param>'
			. '</params></methodCall>';
	}

	private function deniedXml()
	{
		return '<?xml version="1.0"?><methodCall><methodName>execute.capture</methodName>'
			. '<params><param><value><string></string></value></param></params></methodCall>';
	}

	private function allowedXml()
	{
		return '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName>'
			. '<params></params></methodCall>';
	}

	private function runEntrypoint($door, $body, $logging, $send = 'success', $policy = 'shipped')
	{
		$tree = sys_get_temp_dir() . '/rutorrent-entrypoint-' . uniqid('', true);
		$process = null;
		try
		{
			if(!mkdir($tree, 0700, true) && !is_dir($tree))
				throw new Exception('could not create entrypoint fixture tree');
			$this->copyProductionTree($tree, $policy);
			$state = $tree . '/state.json';
			file_put_contents($state, json_encode(array(
				'sends' => 0, 'responses' => 0, 'logs' => array(),
				'payload' => null, 'trusted' => null,
				'host' => null, 'port' => null,
				'payload_base64' => null, 'payload_sha256' => null, 'payload_length' => null,
				'timeouts' => null, 'response_mode' => null, 'events' => array(),
			)));
			$this->writeStubs($tree);

			$port = $this->reservePort();
			$environment = array_merge($_ENV, array(
				'XMLRPC_ENTRYPOINT_STATE' => $state,
				'XMLRPC_ENTRYPOINT_LOGGING' => $logging ? '1' : '0',
				'XMLRPC_ENTRYPOINT_SEND' => $send,
				'XMLRPC_ENTRYPOINT_UNREADABLE' => ($body === 'unreadable') ? '1' : '0',
			));
			// Replace the command shell so proc_terminate() always targets the
			// disposable PHP server instead of leaving it orphaned.
			$command = 'exec ' . escapeshellarg(PHP_BINARY)
				. ' -d auto_prepend_file=' . escapeshellarg($tree . '/prepend.php')
				. ' -d display_errors=0'
				. ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($tree);
			$process = proc_open($command, array(
				0 => array('pipe', 'r'),
				1 => array('file', $tree . '/server.out', 'a'),
				2 => array('file', $tree . '/server.err', 'a'),
			), $pipes, $tree, $environment);
			if(!is_resource($process))
				throw new Exception('could not start copied PHP entrypoint server');
			fclose($pipes[0]);
			$this->waitForServer($port, $process, $tree . '/server.err');
			$response = $this->rawPost($port, ($door === 'action')
				? '/plugins/httprpc/action.php' : '/rpc2.php', ($body === 'unreadable') ? '' : $body);
			$decoded = json_decode(file_get_contents($state), true);
			if(!is_array($decoded))
				throw new Exception('copied entrypoint did not write readable state');
			$response['state'] = $decoded;
			$response['policy_exists'] = is_file($tree . '/conf/xmlrpc_proxy.php');
			$response['rpc2logs'] = is_file($tree . '/rpc2.log')
				? file_get_contents($tree . '/rpc2.log') : '';
			return $response;
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

	private function copyProductionTree($tree, $policy = 'shipped')
	{
		$files = array(
			'plugins/httprpc/action.php',
			'plugins/httprpc/settingspolicy.php',
			'php/xmlrpc_path.php',
			'php/xmlrpc_proxy.php',
			'php/xmlrpc_proxy_policy.php',
			'rpc2.php',
		);
		// Copy and byte-verify the shipped policy files before applying the
		// fixture's logging switch and restricted/empty lists below. The
		// unreadable variant chmods its copy to 0000; "none" omits both files.
		if($policy !== 'none')
		{
			$files[] = 'conf/xmlrpc_proxy.php';
			$files[] = 'plugins/httprpc/conf.php';
		}
		foreach($files as $relative)
		{
			$source = $this->sourceRoot . '/' . $relative;
			$target = $tree . '/' . $relative;
			if(!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true))
				throw new Exception('could not create copied source directory for ' . $relative);
			if(!copy($source, $target) || (hash_file('sha256', $source) !== hash_file('sha256', $target)))
				throw new Exception('could not byte-copy production source ' . $relative);
		}
		if($policy !== 'none')
		{
			$file = $tree . '/conf/xmlrpc_proxy.php';
			// Explicit deployment overrides follow the byte-verified shipped policy.
			file_put_contents($file, "\n\$XMLRPCProxyLog = (getenv('XMLRPC_ENTRYPOINT_LOGGING') === '1');\n", FILE_APPEND);
			if($policy === 'restricted' || $policy === 'empty')
				file_put_contents($file, "\$XMLRPCProxySafeParams = ".($policy === 'empty' ? 'array()' : "array('d.custom1.set')").";\n", FILE_APPEND);
			if($policy === 'off')
				file_put_contents($file, "\$XMLRPCProxy = 'off';\n", FILE_APPEND);
			if($policy === 'null')
				file_put_contents($file, "\$XMLRPCProxySafeParams = null;\n", FILE_APPEND);
			if($policy === 'plugin_null')
				file_put_contents($tree . '/plugins/httprpc/conf.php',
					"\$XMLRPCProxySafeParams = null;\n", FILE_APPEND);
			if($policy === 'unreadable' && !chmod($file, 0000))
				throw new Exception('could not make policy unreadable');
			if($policy === 'symlink')
				file_put_contents($file, "\$XMLRPCProxySafeParams = array();\n", FILE_APPEND);
			if($policy === 'symlink')
			{
				$volume = $tree . '/config-volume';
				if(!mkdir($volume, 0700) || !rename($tree . '/conf', $volume . '/conf')
					|| !symlink($volume . '/conf', $tree . '/conf'))
					throw new Exception('could not model a symlinked conf volume');
			}
		}

	}

	private function writeStubs($tree)
	{
		if(!is_dir($tree . '/conf'))
			mkdir($tree . '/conf', 0700, true);
		file_put_contents($tree . '/php/scgitransport.php', <<<'PHP'
<?php
class rSCGITransport
{
	const RESPONSE_BODY = 1;

	public static function send($host, $port, $payload, $trusted = false, $timeout = 0, &$failure = null, $transferTimeout = null, $maxResponseBytes = null, $responseMode = self::RESPONSE_BODY)
	{
		$path = getenv('XMLRPC_ENTRYPOINT_STATE');
		$state = json_decode(@file_get_contents($path), true);
		if(!is_array($state))
			$state = array('sends' => 0, 'responses' => 0, 'logs' => array(),
				'payload' => null, 'trusted' => null, 'host' => null, 'port' => null,
				'payload_base64' => null, 'payload_sha256' => null, 'payload_length' => null,
				'timeouts' => null, 'response_mode' => null, 'events' => array());

		$state['sends']++;
		$state['host'] = $host;
		$state['port'] = $port;
		$state['payload'] = $payload;
		$state['payload_base64'] = base64_encode($payload);
		$state['payload_sha256'] = hash('sha256', $payload);
		$state['payload_length'] = strlen($payload);
		$state['trusted'] = (bool)$trusted;
		$state['timeouts'] = array('timeout' => $timeout, 'transferTimeout' => $transferTimeout, 'maxResponseBytes' => $maxResponseBytes);
		$state['response_mode'] = $responseMode;
		$state['events'][] = array('event' => 'send', 'door' => 'rpc2', 'trusted' => (bool)$trusted, 'sends' => $state['sends']);

		file_put_contents($path, json_encode($state));

		if(getenv('XMLRPC_ENTRYPOINT_SEND') === 'false')
		{
			$failure = 'transport connection failed';
			return null;
		}

		$send = getenv('XMLRPC_ENTRYPOINT_SEND');
		if($send === 'socket-refused' && !$trusted
			&& strpos($payload, '<methodName>system.sockets.') !== false)
			return '<?xml version="1.0"?><methodResponse><fault><value><struct><member><name>faultCode</name><value><i4>-507</i4></value></member><member><name>faultString</name><value><string>Command refused</string></value></member></struct></value></fault></methodResponse>';
		return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodResponse><params><param><value><string>SCGI-REPLY</string></value></param></params></methodResponse>";
	}
}
PHP
);
		file_put_contents($tree . '/php/xmlrpc.php', <<<'PHP'
<?php
// The real xmlrpc.php loads util.php, which bootstraps conf/config.php.
require_once(dirname(__FILE__).'/../conf/config.php');
function entrypoint_state($key, $value = null)
{
	$path = getenv('XMLRPC_ENTRYPOINT_STATE');
	$state = json_decode(@file_get_contents($path), true);
	if(!is_array($state))
		$state = array('sends' => 0, 'responses' => 0, 'logs' => array(),
			'payload' => null, 'trusted' => null, 'host' => null, 'port' => null,
			'payload_base64' => null, 'payload_sha256' => null, 'payload_length' => null,
			'timeouts' => null, 'response_mode' => null, 'events' => array());
	if($key === 'log')
	{
		$state['logs'][] = $value;
	}
	elseif($key === 'send')
	{
		$state['sends']++;
		$state['host'] = null;
		$state['port'] = null;
		$state['payload'] = $value[0];
		$state['payload_base64'] = base64_encode($value[0]);
		$state['payload_sha256'] = hash('sha256', $value[0]);
		$state['payload_length'] = strlen($value[0]);
		$state['trusted'] = (bool)$value[1];
		$state['timeouts'] = null;
		$state['response_mode'] = null;
		$state['events'][] = array('event' => 'send', 'door' => 'httprpc', 'trusted' => (bool)$value[1], 'sends' => $state['sends']);
	}
	elseif($key === 'response')
	{
		$state['responses']++;
		$state['events'][] = array('event' => 'response', 'door' => 'httprpc');
	}
	file_put_contents($path, json_encode($state));
}
class FileUtil
{
	public static function getPluginConf($plugin)
	{
		// Production evaluates the plugin's own conf file here. The logging
		// setting is appended after it, which is where a deployment's own
		// override lands too.
		$conf = dirname(__FILE__) . '/../plugins/' . $plugin . '/conf.php';
		return (is_file($conf) ? 'require("' . $conf . '");' : '')
			. '$XMLRPCProxyLog = '
			. ((getenv('XMLRPC_ENTRYPOINT_LOGGING') === '1') ? 'true' : 'false') . ';';
	}
	public static function toLog($message) { entrypoint_state('log', $message); }
}
class rXMLRPCRequest
{
	public static function send($payload, $trusted)
	{
		entrypoint_state('send', array($payload, $trusted));
		if(getenv('XMLRPC_ENTRYPOINT_SEND') === 'false')
			return false;
		$send = getenv('XMLRPC_ENTRYPOINT_SEND');
		if($send === 'socket-refused' && !$trusted
			&& strpos($payload, '<methodName>system.sockets.') !== false)
			return 'HTTP/1.1 200 OK'."\r\n".'Content-Type: text/xml'."\r\n\r\n".'<?xml version="1.0"?><methodResponse><fault><value><struct><member><name>faultCode</name><value><i4>-507</i4></value></member><member><name>faultString</name><value><string>Command refused</string></value></member></struct></value></fault></methodResponse>';
		return "HTTP/1.1 200 OK\r\nContent-Type: text/xml\r\n\r\n<?xml version=\"1.0\"?>\n<methodResponse><params><param><value><string>SCGI-REPLY</string></value></param></params></methodResponse>";
	}
}
class CachedEcho
{
	public static function send($body, $type)
	{
		header('Content-Type: ' . $type . '; charset=UTF-8', true);
		entrypoint_state('response');
		echo $body;
		exit;
	}
}
class JSON { public static function safeEncode($value) { return json_encode($value); } }
PHP
);
		file_put_contents($tree . '/plugins/httprpc/rpccache.php', "<?php\n");
		file_put_contents($tree . '/conf/config.php', <<<'PHP'
<?php
// A real directory rather than "/": the shipped policy leaves
// $XMLRPCProxyAllowRootDirectory false, and rpc2.php refuses to serve at all
// while the boundary is one that confines nothing.
$XMLRPCProxyLog = (getenv('XMLRPC_ENTRYPOINT_LOGGING') === '1');
$topDirectory = realpath(dirname(__FILE__) . '/..');
$log_file = dirname(__FILE__) . '/../rpc2.log';
$scgi_host = '127.0.0.1';
$scgi_port = 1;
PHP
);
		file_put_contents($tree . '/prepend.php', <<<'PHP'
<?php
$_SERVER['RUTORRENT_XMLRPC_ENDPOINT'] = 'on';
if(getenv('XMLRPC_ENTRYPOINT_UNREADABLE') === '1')
{
	class EntrypointUnreadableInput
	{
		public $context;
		public function stream_open($path, $mode, $options, &$openedPath) { return false; }
	}
	stream_wrapper_unregister('php');
	stream_wrapper_register('php', 'EntrypointUnreadableInput');
}
PHP
);
	}

	private function reservePort()
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
		if($socket === false)
			throw new Exception('could not reserve local test port: ' . $error);
		$name = stream_socket_get_name($socket, false);
		fclose($socket);
		$parts = explode(':', $name);
		return intval(array_pop($parts));
	}

	private function waitForServer($port, $process, $errorFile)
	{
		for($i = 0; $i < 100; $i++)
		{
			$socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.05);
			if($socket !== false)
			{
				fclose($socket);
				return;
			}
			$status = proc_get_status($process);
			if(!$status['running'])
				throw new Exception('copied PHP entrypoint server exited: ' . @file_get_contents($errorFile));
			usleep(25000);
		}
		throw new Exception('copied PHP entrypoint server did not start');
	}

	private function rawPost($port, $path, $body)
	{
		$socket = @fsockopen('127.0.0.1', $port, $errno, $error, 2);
		if($socket === false)
			throw new Exception('could not connect raw HTTP client: ' . $error);
		$request = "POST " . $path . " HTTP/1.1\r\nHost: 127.0.0.1\r\n"
			. "Content-Type: text/xml\r\nContent-Length: " . strlen($body)
			. "\r\nConnection: close\r\n\r\n" . $body;
		if(fwrite($socket, $request) === false)
			throw new Exception('could not write raw HTTP request');
		$raw = stream_get_contents($socket);
		fclose($socket);
		if($raw === false || strpos($raw, "\r\n\r\n") === false)
			throw new Exception('copied entrypoint returned no complete HTTP response');
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

	private function assertHttp($result, $status, $type, $body = null)
	{
		$this->assertTrue(strpos($result['status'], $status) !== false,
			'HTTP status is ' . $status);
		$this->assertEquals($type, isset($result['headers']['content-type'])
			? $result['headers']['content-type'] : null, 'HTTP content type is ' . $type);
		if($body !== null)
			$this->assertEquals($body, $result['body'], 'HTTP body is exact');
	}

	private function assertFullTranscript($door, array $state, $expectedSends, $expectedPayload, $expectedLength, $expectedSha, $expectedHost, $expectedPort, $expectedTrusted, $expectedTimeouts, $expectedResponseMode, array $expectedEvents)
	{
		$this->assertSameStrict($expectedSends, $state['sends'], $door . ' call count');
		$this->assertSameStrict($expectedPayload, $state['payload'], $door . ' request bytes');
		$this->assertSameStrict($expectedLength, $state['payload_length'], $door . ' byte length');
		$this->assertSameStrict($expectedSha, $state['payload_sha256'], $door . ' SHA-256');
		$this->assertSameStrict($expectedHost, $state['host'], $door . ' host');
		$this->assertSameStrict($expectedPort, $state['port'], $door . ' port');
		$this->assertSameStrict($expectedTrusted, $state['trusted'], $door . ' trusted mode');
		$this->assertSameStrict($expectedTimeouts, $state['timeouts'], $door . ' timeouts');
		$this->assertSameStrict($expectedResponseMode, $state['response_mode'], $door . ' response mode');
		$this->assertSameStrict($expectedEvents, $state['events'], $door . ' event array');
	}

	private function assertSameStrict($expected, $actual, $message)
	{
		$this->assertTrue($expected === $actual, $message . ' (expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ')');
	}

	private function assertTranscriptsStrictlyIdentical($prefix, array $a, array $b)
	{
		$this->assertSameStrict($a['sends'], $b['sends'], $prefix . ' identical sends');
		$this->assertSameStrict($a['payload'], $b['payload'], $prefix . ' identical payload');
		$this->assertSameStrict($a['payload_length'], $b['payload_length'], $prefix . ' identical payload_length');
		$this->assertSameStrict($a['payload_sha256'], $b['payload_sha256'], $prefix . ' identical payload_sha256');
		$this->assertSameStrict($a['host'], $b['host'], $prefix . ' identical host');
		$this->assertSameStrict($a['port'], $b['port'], $prefix . ' identical port');
		$this->assertSameStrict($a['trusted'], $b['trusted'], $prefix . ' identical trusted');
		$this->assertSameStrict($a['timeouts'], $b['timeouts'], $prefix . ' identical timeouts');
		$this->assertSameStrict($a['response_mode'], $b['response_mode'], $prefix . ' identical response_mode');
		$this->assertSameStrict($a['events'], $b['events'], $prefix . ' identical events');
	}

	private function assertRpc2Log($result, $needle, $message)
	{
		$this->assertTrue(strpos($result['rpc2logs'], $needle) !== false, $message);
	}

	private function deleteTree($path)
	{
		if(!is_dir($path))
			return;
		foreach(scandir($path) as $entry)
			if(($entry !== '.') && ($entry !== '..'))
			{
				$child = $path . '/' . $entry;
				if(is_link($child))
					@unlink($child);
				else if(is_dir($child))
					$this->deleteTree($child);
				else
					@unlink($child);
			}
		@rmdir($path);
	}
}
