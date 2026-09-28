<?php

require_once(__DIR__ . '/TestCase.php');
// The SCGI transport contract lives in SCGITransportTest; only its PHP
// child runner is still needed here, for the env_check dependency test.
require_once(__DIR__ . '/SCGITransportFixture.php');

// Stub the dependencies that production callers (httprpc/action.php) load
// before invoking XMLRPCProxy. We don't exercise the real SCGI path here —
// we verify XMLRPCProxy's own logic.
if(!class_exists('FileUtil'))
{
	class FileUtil
	{
		public static $log = array();
		public static function toLog($msg) { self::$log[] = $msg; }
	}
}
if(!class_exists('rXMLRPCRequest'))
{
	class rXMLRPCRequest
	{
		public static $lastPayload = null;
		public static $lastTrusted = null;
		public static $sent = 0;
		public static function send($data, $trusted)
		{
			self::$lastPayload = $data;
			self::$lastTrusted = $trusted;
			self::$sent++;
			return '';
		}
	}
}

require_once(__DIR__ . '/../../php/xmlrpc_proxy.php');

class XMLRPCProxyTest extends TestCase
{
	// 0.16.22 keeps directory_base.set as a redirect to directory.base.set.
	const DIRECTORY_SETTERS = array('d.directory.set', 'd.directory_base.set', 'd.directory.base.set');

	// The historical policy cases model a 0.16.22 daemon. Keep that version
	// explicit so an omitted production version can be tested fail-closed above.
	private static function decideOnModernDaemon($raw, $mode = 'sanitize', $safe = array(),
		$allowPaths = false, $options = array())
	{
		if(!array_key_exists('rtorrentVersion', $options))
			$options['rtorrentVersion'] = 0x1016;
		return XMLRPCProxy::decide($raw, $mode, $safe, $allowPaths, $options);
	}

	private static function processOnModernDaemon($raw, $mode = 'sanitize', $enableLog = true,
		$safe = array(), $allowPaths = false, $options = array())
	{
		if(!array_key_exists('rtorrentVersion', $options))
			$options['rtorrentVersion'] = 0x1016;
		return XMLRPCProxy::process($raw, $mode, $enableLog, $safe, $allowPaths, $options);
	}

	public function testD4NativeGateRejectsAliasesAcrossDirectAndTrustedSlots()
	{
		$policy = XMLRPCProxy::defaultSafeParams();
		$old = array('rtorrentVersion' => 0x0908);
		$new = array('rtorrentVersion' => 0x1016);
		$direct = XMLRPCProxy::decide($this->methodCallXml('my_shell_alias', array()),
			'sanitize', $policy, false, $old);
		$this->assertTrue($direct['action'] === 'reject' && !$direct['trusted'],
			'an arbitrary direct alias cannot rely on an ignored untrusted flag in 0.9.8');
		$modernGetter = $this->methodCallXml('d.multicall2',
			array('', 'main', 'd.base_path.realpath.or_empty=/outside'));
		$slot = XMLRPCProxy::decide($modernGetter, 'sanitize', $policy, false, $old);
		$this->assertTrue($slot['action'] === 'reject' && !$slot['trusted'],
			'a 0.16.21 getter name is not native in a 0.9.8 trusted slot');
		$clientAlias = $this->methodCallXml('d.multicall2',
			array('', 'main', 'd.get_name='));
		$slot = XMLRPCProxy::decide($clientAlias, 'sanitize', $policy, false, $new);
		$this->assertTrue($slot['action'] === 'reject' && !$slot['trusted'],
			'a client-only getter spelling is not a native method even on 0.16.22');
		$native = XMLRPCProxy::decide($this->methodCallXml('d.multicall2',
			array('', 'main', 'd.name=')), 'sanitize', $policy, false, $old);
		$this->assertTrue($native['action'] === 'send' && $native['trusted'],
			'a stock 0.9.8 native getter keeps the established trusted listing path');
	}


	public function testD4OmittedVersionUsesOnlyMethodsNativeOnEverySupportedDaemon()
	{
		$policy = XMLRPCProxy::defaultSafeParams();
		$alias = XMLRPCProxy::decide($this->methodCallXml('my_shell_alias', array()),
			'sanitize', $policy);
		$this->assertEquals('reject', $alias['action'],
			'an omitted version cannot forward an rc alias to an old daemon');
		$newerSlot = XMLRPCProxy::decide($this->methodCallXml('d.multicall2',
			array('', 'main', 'd.base_path.realpath.or_empty=')), 'sanitize', $policy);
		$this->assertEquals('reject', $newerSlot['action'],
			'an omitted version cannot elevate a getter absent from 0.9.8');
		$native = XMLRPCProxy::decide($this->methodCallXml('d.multicall2',
			array('', 'main', 'd.name=')), 'sanitize', $policy);
		$this->assertTrue($native['action'] === 'send' && $native['trusted'],
			'a common native getter remains available when a caller omits version');
	}


	public function testD4VersionParserRefusesUnknownAndMalformedReplies()
	{
		$reply = function($value) {
			return '<?xml version="1.0"?><methodResponse><params><param><value><string>'
				.$value.'</string></value></param></params></methodResponse>';
		};
		$this->assertEquals(0x0908, XMLRPCProxy::daemonVersionFromReply($reply('0.9.8')),
			'the supported 0.9.8 baseline is recognized');
		$this->assertEquals(0x1016, XMLRPCProxy::daemonVersionFromReply($reply('0.16.22')),
			'a reviewed 0.16 release is recognized');
		foreach(array('0.16.25', '0.9.7', '0.16.22evil', '0.16.999') as $value)
			$this->assertTrue(XMLRPCProxy::daemonVersionFromReply($reply($value)) === null,
				'unreviewed version '.$value.' fails closed');
		$this->assertTrue(XMLRPCProxy::daemonVersionFromReply('<methodResponse><fault/></methodResponse>') === null,
			'a fault is not a version');
	}

	public function testD4OldDaemonChecksLoadTailAndSystemBatchMembers()
	{
		$policy = XMLRPCProxy::defaultSafeParams();
		$old = array('rtorrentVersion' => 0x0908);
		$load = XMLRPCProxy::decide($this->methodCallXml('load.start',
			array('', 'http://example.test/a.torrent', 'd.directory.base.set=/outside')),
			'sanitize', $policy, false, $old);
		$this->assertTrue($load['action'] === 'reject',
			'a newer setter spelling cannot be executed as an old-daemon alias in a load tail');
		$batch = XMLRPCProxy::decide($this->systemMulticallXml(array(
			array('d.stop', array(str_repeat('A', 40))),
			array('my_shell_alias', array()),
		)), 'sanitize', $policy, false, $old);
		$this->assertTrue($batch['action'] === 'reject' && !$batch['trusted'],
			'an unknown member cannot borrow system.multicall trust on an old daemon');
		$native = XMLRPCProxy::decide($this->systemMulticallXml(array(
			array('d.stop', array(str_repeat('A', 40))),
		)), 'sanitize', $policy, false, $old);
		$this->assertTrue($native['action'] === 'send' && $native['trusted'],
			'a native elevated action remains available in a system batch on 0.9.8');
	}

	public function testMulticallDirectoryRefusalDoesNotSuggestAnIneffectivePolicyEdit()
	{
		$xml = $this->methodCallXml('d.multicall2',
			array('', 'main', 'd.directory.base.set=/downloads/x'));
		foreach(array(
			array('d.directory_base.set'),
			array('d.directory_base.set', 'd.directory.base.set'),
		) as $safeParams)
		{
			$decision = self::decideOnModernDaemon($xml, 'sanitize', $safeParams);
			$this->assertTrue($decision['action'] === 'reject',
				'a directory setter never belongs in a multicall result slot');
			$this->assertTrue(strpos(implode(' ', $decision['log']), 'add it to conf/xmlrpc_proxy.php') === false,
				'the refusal does not suggest a policy edit that cannot allow this slot');
		}
	}

	public function testHomogeneousUntrustedFallbackMembersCanShareAnUntrustedCarrier()
	{
		$hash = str_repeat('A', 40);
		foreach(array('sonarr_imported', 'radarr_imported') as $view)
		{
			$single = self::decideOnModernDaemon($this->methodCallXml('d.views.push_back_unique',
				array($hash, $view)), 'sanitize', XMLRPCProxy::defaultSafeParams());
			$this->assertTrue($single['action'] === 'send' && !$single['trusted'],
				$view.' individually takes the unchanged untrusted route');
		}
		$xml = $this->systemMulticallXml(array(
			array('d.views.push_back_unique', array($hash, 'sonarr_imported')),
			array('d.views.push_back_unique', array($hash, 'radarr_imported')),
		));
		$decision = self::decideOnModernDaemon($xml, 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($decision['action'] === 'send' && !$decision['trusted'],
			'members that individually forward untrusted can share one untrusted carrier');
		$this->assertTrue(strpos($decision['payload'], 'sonarr_imported') !== false
			&& strpos($decision['payload'], 'radarr_imported') !== false,
			'both member values survive canonical rebuilding');
		$this->assertTrue(strpos($decision['log'][0], 'd.views.push_back_unique') !== false
			&& strpos($decision['log'][0], 'sonarr_imported') === false,
			'the untrusted carrier names its command without logging argument values');
	}

	public function testUntrustedMulticallLogNamesBoundedDistinctCommands()
	{
		$hash = str_repeat('A', 40);
		$members = array();
		foreach(array('d.name', 'd.hash', 'd.size_bytes', 'd.is_active') as $method)
			$members[] = array($method, array($hash));
		$decision = self::decideOnModernDaemon($this->systemMulticallXml($members),
			'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($decision['action'] === 'send' && !$decision['trusted'],
			'the read-only batch remains untrusted');
		$this->assertEquals(array('untrusted: system.multicall (4 members) '
			.'[methods: d.name, d.hash, d.size_bytes, ...]'), $decision['log'],
			'the one summary bounds method names without logging their arguments');
	}

	public function testSuccessfulSystemMulticallLogsOnlySummaryAndNonroutineWarnings()
	{
		$hash = str_repeat('A', 40);
		$decision = self::decideOnModernDaemon($this->systemMulticallXml(array(
			array('d.stop', array($hash)), array('d.close', array($hash)),
			array('d.open', array($hash)),
		)), 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertEquals(1, count($decision['log']),
			'routine member decisions do not multiply a successful batch log');
		$this->assertTrue(strpos($decision['log'][0], 'system.multicall (3 members)') !== false,
			'the summary still says what request was sent');
		$warning = self::decideOnModernDaemon($this->systemMulticallXml(array(
			array('load.start', array('', '/tmp/sample.torrent')),
		)), 'sanitize', XMLRPCProxy::defaultSafeParams(), true);
		$this->assertTrue($warning['action'] === 'send' && count($warning['log']) === 2
			&& strpos($warning['log'][1], 'WARNING: operator-enabled local path') !== false,
			'a nonroutine local-path warning remains visible with its member slot');
	}

	public function testUntrustedFirstThenTrustedSystemBatchIsRejected()
	{
		$hash = str_repeat('A', 40);
		$decision = self::decideOnModernDaemon($this->systemMulticallXml(array(
			array('d.wibble.set', array($hash, '1')),
			array('d.stop', array($hash)),
		)), 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($decision['action'] === 'reject' && !$decision['trusted']
			&& $decision['payload'] === '',
			'an untrusted first member cannot be upgraded by a later trusted member');
	}

	public function testStopBatchElevatesCloseWhileGetSavePathRemainsDeferred()
	{
		$hash = str_repeat('A', 40);
		$stop = self::decideOnModernDaemon($this->systemMulticallXml(array(
			array('d.stop', array($hash)), array('d.close', array($hash)),
		)), 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($stop['action'] === 'send' && $stop['trusted'],
			'the raw WebUI stop batch keeps its checked close member');
		$badClose = self::decideOnModernDaemon($this->systemMulticallXml(array(
			array('d.stop', array($hash)), array('d.close', array($hash, 'extra')),
		)), 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($badClose['action'] === 'reject',
			'an unvalidated close member cannot borrow batch trust');
		foreach(array('d.base_path', 'd.get_base_path') as $method)
		{
			$xml = $this->methodCallXml($method, array($hash));
			$direct = self::decideOnModernDaemon($xml, 'sanitize', XMLRPCProxy::defaultSafeParams());
			$this->assertTrue($direct['action'] === 'send' && !$direct['trusted']
				&& $direct['payload'] === $xml,
				$method.' stays on the direct untrusted read path');
			$batch = self::decideOnModernDaemon($this->systemMulticallXml(array(
				array('d.open', array($hash)), array($method, array($hash)),
				array('d.close', array($hash)),
			)), 'sanitize', XMLRPCProxy::defaultSafeParams());
			$this->assertTrue($batch['action'] === 'reject',
				$method.' getsavepath batch remains deferred under the mixed-trust rule');
		}
	}

	public function testSocketAllocationMutatorsStayUntrustedForDirectAndHomogeneousCalls()
	{
		$mutators = array(
			'system.sockets.files.min_alloc.set', 'system.sockets.files.max_alloc.set',
			'system.sockets.http.min_alloc.set', 'system.sockets.http.max_alloc.set',
			'system.sockets.adjust_alloc',
		);
		foreach($mutators as $method)
		{
			$params = $method === 'system.sockets.adjust_alloc' ? array() : array('', 1048576);
			$xml = $this->methodCallXml($method, $params);
			$decision = self::decideOnModernDaemon($xml, 'sanitize', XMLRPCProxy::defaultSafeParams());
			$this->assertTrue($decision['action'] === 'send' && !$decision['trusted']
				&& $decision['payload'] === $xml,
				$method.' is forwarded unchanged to the daemon untrusted gate');
		}
		$call = $this->systemMulticallXml(array(
			array('system.sockets.files.max_alloc.set', array('', 1048576)),
			array('system.sockets.adjust_alloc', array()),
		));
		$decision = self::decideOnModernDaemon($call, 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($decision['action'] === 'send' && !$decision['trusted'],
			'homogeneous socket allocation batch reaches the daemon untrusted gate');
		$decision = self::decideOnModernDaemon($this->systemMulticallXml(array(
			array('d.stop', array(str_repeat('A', 40))),
			array('system.sockets.adjust_alloc', array()),
		)), 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($decision['action'] === 'reject',
			'a socket mutator cannot borrow trust from another batch member');
		$read = self::decideOnModernDaemon($this->methodCallXml('system.sockets.files.max_alloc'),
			'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($read['action'] === 'send' && !$read['trusted'],
			'a socket allocation getter remains a normal untrusted read');
	}

	public function testXmlrpcSizeLimitCannotBeLoweredBelowRecoveryRequestSize()
	{
		$xml = $this->methodCallXml('network.xmlrpc.size_limit.set', array('', '1'));
		$decision = self::decideOnModernDaemon($xml, 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($decision['action'] === 'send' && $decision['trusted'],
			'size-limit setter keeps its checked elevation');
		$this->assertTrue(strpos($decision['payload'], '<i8>1024</i8>') !== false,
			'the proxy retains enough XMLRPC budget for a follow-up recovery request');
		$this->assertTrue(in_array('WARNING: network.xmlrpc.size_limit.set requested 1, sent 1024',
			$decision['log'], true), 'a silently raised size limit is named in the decision log');
		$recovery = self::decideOnModernDaemon($this->methodCallXml('network.xmlrpc.size_limit.set',
			array('', '16777216')), 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($recovery['action'] === 'send' && $recovery['trusted']
			&& strlen($recovery['payload']) < 1024,
			'the actual emitted recovery setter fits below the enforced floor');
		$this->assertEquals(1, count($recovery['log']),
			'an unchanged size limit does not produce a clamp warning');
		$ceiling = self::decideOnModernDaemon($this->methodCallXml('network.xmlrpc.size_limit.set',
			array('', '999999999')), 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue(in_array('WARNING: network.xmlrpc.size_limit.set requested 999999999, sent 16777216',
			$ceiling['log'], true), 'the upper clamp is visible too');
	}

	public function testExplicitPolicyCannotSetXmlrpcLimitThroughNestedCommands()
	{
		$safeParams = array('network.xmlrpc.size_limit.set');
		$expression = 'network.xmlrpc.size_limit.set=1';
		$cases = array(
			'load tail' => $this->methodCallXml('load.start',
				array('', 'https://example.test/a.torrent', $expression)),
			'multicall result' => $this->methodCallXml('d.multicall2',
				array('', 'main', $expression)),
			'multicall filter' => $this->methodCallXml('d.multicall.filtered',
				array('', 'main', $expression, 'd.name=')),
			'view-first multicall' => $this->methodCallXml('d.multicall',
				array('main', $expression)),
		);
		foreach($cases as $label => $xml)
		{
			$decision = self::decideOnModernDaemon($xml, 'sanitize', $safeParams);
			$this->assertTrue($decision['action'] === 'reject'
				&& !$decision['trusted'] && $decision['payload'] === '',
				$label.' cannot bypass the direct size-limit floor under an explicit policy');
		}
		$direct = self::decideOnModernDaemon($this->methodCallXml('network.xmlrpc.size_limit.set',
			array('', '1')), 'sanitize', $safeParams);
		$this->assertTrue($direct['action'] === 'send' && $direct['trusted']
			&& strpos($direct['payload'], '<i8>1024</i8>') !== false,
			'the direct recovery setter still clamps an explicit-policy call');
		$member = self::decideOnModernDaemon($this->systemMulticallXml(array(
			array('network.xmlrpc.size_limit.set', array('', '1')),
		)), 'sanitize', $safeParams);
		$this->assertTrue($member['action'] === 'send' && $member['trusted']
			&& strpos($member['payload'], '<i8>1024</i8>') !== false,
			'a system.multicall member uses the same direct size shape and floor');
		$this->assertTrue(strpos(implode("\n", $member['log']),
			'[slot 1] WARNING: network.xmlrpc.size_limit.set requested 1, sent 1024') !== false,
			'a batch member reports the same clamp without repeating a routine decision');
	}

	public function testBuiltInPolicyHintAppearsOnlyOnFirstMulticallDenialLine()
	{
		$policy = XMLRPCProxy::policySettings(array());
		$hash = str_repeat('A', 40);
		$decision = self::decideOnModernDaemon($this->systemMulticallXml(array(
			array('d.stop', array($hash)), array('execute.capture', array('id')),
		)), 'sanitize', $policy['safeParams']);
		$lines = XMLRPCProxy::decisionLogLines($decision, $policy);
		$this->assertTrue($decision['action'] === 'reject' && count($lines) >= 2
			&& strpos($lines[0], 'system.multicall') !== false
			&& strpos($lines[1], '[slot 2]') !== false,
			'denied multicall has an outer refusal and a member reason');
		$this->assertEquals(1, substr_count(implode("\n", $lines), '[built-in policy]'),
			'denied multicall names the fallback only once');
		$this->assertTrue(strpos($lines[0], '[built-in policy]') !== false,
			'the outer refusal carries the policy source');
	}

	public function testBuiltInPolicyHintAppearsOnlyOnFirstMulticallWarningLine()
	{
		$policy = XMLRPCProxy::policySettings(array());
		$decision = self::decideOnModernDaemon($this->systemMulticallXml(array(
			array('load.start', array('', '/tmp/sample.torrent')),
		)), 'sanitize', $policy['safeParams'], true);
		$lines = XMLRPCProxy::decisionLogLines($decision, $policy);
		$this->assertTrue($decision['action'] === 'send' && count($lines) === 2
			&& strpos($lines[0], 'system.multicall') !== false
			&& strpos($lines[1], '[slot 1] WARNING:') !== false,
			'allowed local-path multicall logs a separate member warning');
		$this->assertEquals(1, substr_count(implode("\n", $lines), '[built-in policy]'),
			'multicall warning names the fallback only once');
		$this->assertTrue(strpos($lines[0], '[built-in policy]') !== false,
			'the outer summary carries the policy source');
	}

	public function testExplicitNullSafeParamsCannotSelectBuiltInPolicy()
	{
		$settings = XMLRPCProxy::policySettings(array('XMLRPCProxySafeParams' => null));
		$this->assertEquals(array(), $settings['safeParams'],
			'an explicitly invalid policy fails closed');
		$this->assertEquals('', $settings['logSuffix'],
			'an explicit policy cannot claim the built-in fallback');
		$this->assertEquals('XMLRPCProxySafeParams must be an array; proxy disabled',
			$settings['policyError'], 'an invalid policy names its failing key and consequence');
		$call = $this->methodCallXml('load.start',
			array('', 'https://example.test/a.torrent', 'd.custom1.set=secret'));
		$decision = self::decideOnModernDaemon($call, 'sanitize', $settings['safeParams']);
		$this->assertTrue($decision['action'] === 'reject',
			'an explicit null policy cannot authorize a custom-field write');
	}
	private function resetMocks()
	{
		rXMLRPCRequest::$lastPayload = null;
		rXMLRPCRequest::$lastTrusted = null;
		rXMLRPCRequest::$sent = 0;
		FileUtil::$log = array();
	}

	private function methodCallXml($method, $params = array())
	{
		$xml = '<?xml version="1.0"?><methodCall><methodName>'
			.htmlspecialchars($method, ENT_QUOTES, 'UTF-8').'</methodName><params>';
		foreach($params as $value)
			$xml .= '<param><value><string>'.htmlspecialchars($value, ENT_NOQUOTES, 'UTF-8').'</string></value></param>';
		return $xml.'</params></methodCall>';
	}

	private function systemMulticallXml($calls)
	{
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.multicall</methodName><params><param><value><array><data>';
		foreach($calls as $call)
		{
			$xml .= '<value><struct><member><name>methodName</name><value><string>'
				. htmlspecialchars($call[0], ENT_NOQUOTES, 'UTF-8')
				. '</string></value></member><member><name>params</name><value><array><data>';
			foreach($call[1] as $parameter)
			{
				$type = is_int($parameter) ? 'i4' : 'string';
				$xml .= '<value><'.$type.'>'.htmlspecialchars((string)$parameter, ENT_NOQUOTES, 'UTF-8')
					.'</'.$type.'></value>';
			}
			$xml .= '</data></array></value></member></struct></value>';
		}
		return $xml.'</data></array></value></param></params></methodCall>';
	}

	public function testNewBatchElevationsKeepUnsupportedDirectCallsUntrusted()
	{
		$hash = str_repeat('a', 40);
		foreach(array(
			array('d.views.push_back_unique', array($hash, 'sonarr_imported')),
			array('view.set_visible', array($hash, 'main')),
			array('view.set_visible', array($hash, 'sonarr_imported')),
			array('d.throttle_name.set', array($hash, 'thr_1')),
			array('d.views.remove', array($hash)),
			array('d.views.remove', array($hash, 'sonarr_imported')),
		) as $call)
		{
			$xml = $this->methodCallXml($call[0], $call[1]);
			$decision = self::decideOnModernDaemon($xml, 'sanitize', XMLRPCProxy::defaultSafeParams());
			$this->assertTrue($decision['action'] === 'send' && !$decision['trusted'],
				$call[0].' outside the batch elevation shape retains the direct untrusted route');
			$this->assertEquals($xml, $decision['payload'],
				$call[0].' outside the elevation shape reaches rTorrent byte for byte');
		}
		$batched = $this->systemMulticallXml(array(
			array('d.stop', array(strtoupper($hash))),
			array('d.views.push_back_unique', array($hash, 'sonarr_imported')),
		));
		$decision = self::decideOnModernDaemon($batched, 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($decision['action'] === 'reject',
			'an unsupported member must not borrow trust from another batch member');
		$oldElevation = self::decideOnModernDaemon($this->methodCallXml('d.stop', array($hash, 'extra')),
			'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($oldElevation['action'] === 'reject',
			'an existing elevation keeps its terminal shape refusal');
		$missingHash = self::decideOnModernDaemon($this->methodCallXml('d.start', array()),
			'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($missingHash['action'] === 'reject' && !$missingHash['trusted']
			&& $missingHash['payload'] === '',
			'd.start without a download hash is refused before reaching the daemon');
	}

	public function testWebUiGetTotalReadBatchRemainsUntrusted()
	{
		$xml = $this->systemMulticallXml(array(
			array('throttle.global_up.total', array()),
			array('throttle.global_down.total', array()),
			array('throttle.global_up.max_rate', array()),
			array('throttle.global_down.max_rate', array()),
		));
		$decision = self::decideOnModernDaemon($xml, 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($decision['action'] === 'send' && !$decision['trusted']
			&& strpos($decision['log'][0], 'untrusted: system.multicall (4 members)') === 0,
			'the four gettotal readers need no trusted XMLRPC connection');
	}

	public function testSavedPolicyRefusalNamesBothRecreateFilesSetters()
	{
		$oldPolicy = array('d.directory_base.set', 'd.custom1.set');
		$hash = str_repeat('A', 40);
		$call = array($hash, '', 'f.set_create_queued=0', 'f.set_resize_queued=0');
		foreach(array($this->methodCallXml('f.multicall', $call),
			$this->systemMulticallXml(array(array('f.multicall', $call)))) as $xml)
		{
			$d = self::decideOnModernDaemon($xml, 'sanitize', $oldPolicy);
			$this->assertTrue($d['action'] === 'reject', 'saved policy still refuses unlisted setters');
			$this->assertTrue(strpos(implode(' ', $d['log']), 'f.set_create_queued') !== false
				&& strpos(implode(' ', $d['log']), 'f.set_resize_queued') !== false
				&& strpos(implode(' ', $d['log']), 'conf/xmlrpc_proxy.php') !== false,
				'the operator sees both required policy additions at either nesting level');
		}
	}

	public function testSystemMulticallRetainsMemberDecisionReasonsAndLocalPathWarning()
	{
		$local = $this->systemMulticallXml(array(
			array('load.start', array('', '/srv/watch/x.torrent')),
		));
		$d = self::decideOnModernDaemon($local, 'sanitize', XMLRPCProxy::defaultSafeParams(), true);
		$this->assertTrue($d['action'] === 'send' && $d['trusted'], 'operator-enabled local load is still sent');
		$this->assertTrue(strpos(implode(' ', $d['log']), '[slot 1] WARNING: operator-enabled local path forwarded') !== false,
			'the outer decision carries the local-path warning of its member');
		$denied = $this->systemMulticallXml(array(
			array('load.start', array('', 'http://example.test/x.torrent', 'execute.capture=id')),
		));
		$d = self::decideOnModernDaemon($denied, 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($d['action'] === 'reject', 'unsafe nested load is refused');
		$this->assertTrue(strpos(implode(' ', $d['log']), '[slot 1] rejected (not allowed on this connection): load.start [slot 3: execute.capture]') !== false,
			'the log keeps the member reason and inner command slot');
	}

	public function testDirectoryBoundaryRefusalHasItsOwnDiagnostic()
	{
		$xml = $this->methodCallXml('load.start', array('', 'http://example.test/x.torrent',
			'd.directory.set=/outside'));
		$d = self::decideOnModernDaemon($xml, 'sanitize', XMLRPCProxy::defaultSafeParams(), false,
			array('directory' => array('root' => '/downloads')));
		$this->assertTrue($d['action'] === 'reject', 'the outside path is refused');
		$this->assertTrue(strpos($d['log'][0], 'directory outside boundary') !== false
			&& strpos($d['log'][0], '$topDirectory') === false,
			'a configured boundary reports the path refusal without a missing-configuration hint');
		$d = self::decideOnModernDaemon($xml, 'sanitize', XMLRPCProxy::defaultSafeParams(), false,
			array('directory' => array('root' => '')));
		$this->assertTrue(strpos($d['log'][0], '$topDirectory') !== false
			&& strpos($d['log'][0], '$XMLRPCProxyAllowRootDirectory') !== false,
			'an empty boundary points the operator to its configuration');
	}

	public function testLegacyViewFirstResultGetsResultExceptionAndAccurateRefusal()
	{
		$d = self::decideOnModernDaemon($this->methodCallXml('d.multicall',
			array('main', 'cat=$d.views=')), 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($d['action'] === 'send' && $d['trusted']
			&& strpos($d['payload'], 'cat=$d.views=') !== false,
			'the exact read-only cat expression works in the first result slot');
		$d = self::decideOnModernDaemon($this->methodCallXml('d.multicall',
			array('main', 'd.wibble=')), 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue(strpos($d['log'][0], 'not allowed on this connection') !== false
			&& strpos($d['log'][0], 'ambiguous multicall view') === false,
			'an invalid legacy result is reported as a command refusal');
	}

	public function testTrustedSystemMulticallPayloadIsCanonicalAcrossMembers()
	{
		$hash = str_repeat('a', 40);
		$xml = $this->systemMulticallXml(array(
			array('network.xmlrpc.size_limit.set', array('', '999999999')),
			array('d.priority.set', array($hash, '2')),
		));
		$d = self::decideOnModernDaemon($xml, 'sanitize', XMLRPCProxy::defaultSafeParams());
		$expected = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
			.'<methodCall><methodName>system.multicall</methodName><params><param><value><array><data>'
			.'<value><struct><member><name>methodName</name><value><string>network.xmlrpc.size_limit.set</string></value></member>'
			.'<member><name>params</name><value><array><data><value><string></string></value><value><i8>16777216</i8></value>'
			.'</data></array></value></member></struct></value>'
			.'<value><struct><member><name>methodName</name><value><string>d.priority.set</string></value></member>'
			.'<member><name>params</name><value><array><data><value><string>'.str_repeat('A', 40)
			.'</string></value><value><i8>2</i8></value></data></array></value></member></struct></value>'
			.'</data></array></value></param></params></methodCall>';
		$this->assertTrue($d['action'] === 'send' && $d['trusted'],
			'two validated members form one trusted carrier');
		$this->assertEquals($expected, $d['payload'],
			'the carrier clamps size, uppercases the hash, and emits integer values');
	}

	public function testAllUntrustedReadMembersCanShareOnlyAnUntrustedCarrier()
	{
		$hash = str_repeat('A', 40);
		$xml = $this->systemMulticallXml(array(
			array('system.client_version', array()),
			array('d.name', array($hash)),
		));
		$d = self::decideOnModernDaemon($xml, 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($d['action'] === 'send' && !$d['trusted'],
			'a batch of individually untrusted reads is sent without trust');
		$this->assertTrue(strpos($d['payload'], '<?xml version="1.0" encoding="UTF-8"?>') === 0
			&& strpos($d['payload'], '<methodName>d.name</methodName>') === false
			&& strpos($d['payload'], '<string>d.name</string>') !== false,
			'the all-untrusted carrier is rebuilt from parsed members');
		$denied = self::decideOnModernDaemon($this->systemMulticallXml(array(
			array('system.client_version', array()),
			array('execute.capture', array('id')),
		)), 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($denied['action'] === 'reject',
			'a locally forbidden member still refuses the entire carrier');
	}

	public function testSystemMulticallTrustRequiresEveryMemberToBeIndividuallyTrusted()
	{
		$hash = str_repeat('A', 40);
		$allowed = $this->systemMulticallXml(array(
			array('d.stop', array($hash)), array('d.start', array($hash)),
		));
		$decision = self::decideOnModernDaemon($allowed, 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($decision['action'] === 'send' && $decision['trusted'],
			'validated WebUI action members may share one trusted request');
		foreach(array('system.client_version', 'execute.capture', 'system.multicall') as $untrusted)
		{
			$denied = $this->systemMulticallXml(array(
				array('d.stop', array($hash)), array($untrusted, array()),
			));
			$decision = self::decideOnModernDaemon($denied, 'sanitize', XMLRPCProxy::defaultSafeParams());
			$this->assertTrue($decision['action'] === 'reject' && !$decision['trusted'],
				$untrusted.' must not inherit trust from another member');
			$refused = $untrusted === 'execute.capture' ? $untrusted : 'system.multicall';
			$this->assertTrue($decision['method'] === $refused,
				'a denied member is named; a trust mismatch names the outer carrier');
		}
	}

	public function testWebUiFileSchedulerAndRatioActionsHaveCheckedBatchShapes()
	{
		$hash = str_repeat('B', 40);
		$bundledCalls = array(
			array('f.prioritize_first.enable', array($hash.':f0')),
			array('f.prioritize_last.disable', array($hash.':f0')),
			array('d.update_priorities', array($hash)),
			array('d.throttle_name.set', array($hash, 'NULL')),
			array('d.custom.set', array($hash, 'sch_ignore', '1')),
			array('view.set_not_visible', array($hash, 'rat_0')),
			array('d.views.remove', array($hash, 'rat_0')),
			array('d.views.push_back_unique', array($hash, 'rat_1')),
			array('view.set_visible', array($hash, 'rat_1')),
		);
		$decision = self::decideOnModernDaemon($this->systemMulticallXml($bundledCalls),
			'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($decision['action'] === 'send' && $decision['trusted'],
			'the bundled WebUI and plugin multicall methods are accepted with their real argument shapes');
		foreach(array(
			array('f.prioritize_first.enable', array($hash.':f0;execute.capture=id')),
			array('d.set_custom', array($hash, 'sch_ignore', '$execute.capture=id')),
			array('view.set_visible', array($hash, 'other_view')),
		) as $bad)
		{
			$decision = self::decideOnModernDaemon($this->systemMulticallXml(array($bad)),
				'sanitize', XMLRPCProxy::defaultSafeParams());
			$this->assertTrue($decision['action'] === ($bad[0] === 'd.set_custom' ? 'reject' : 'send')
				&& !$decision['trusted'],
				$bad[0].' cannot become a trusted unchecked batch member');
		}
	}

	public function testSchedulerUnignoreAcceptsEmptyThrottleAndState()
	{
		$hash = str_repeat('A', 40);
		foreach(array(
			array(array('d.stop', array($hash)), array('d.throttle_name.set', array($hash, '')),
				array('d.start', array($hash)), array('d.custom.set', array($hash, 'sch_ignore', ''))),
		) as $calls)
		{
			$d = self::decideOnModernDaemon($this->systemMulticallXml($calls),
				'sanitize', XMLRPCProxy::defaultSafeParams());
			$this->assertTrue($d['action'] === 'send' && $d['trusted'],
				'scheduler unignore uses the empty throttle and state forms');
		}
		$legacy = self::decideOnModernDaemon($this->systemMulticallXml(array(
			array('d.set_throttle_name', array($hash, '')),
			array('d.set_custom', array($hash, 'sch_ignore', '')),
		)), 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertEquals('reject', $legacy['action'],
			'a client-side alias cannot borrow trust as an XMLRPC method');

	}

	public function testRecreateFilesUsesTheRegisteredRTorrentSetterSpellings()
	{
		$hash = str_repeat('C', 40);
		$xml = $this->methodCallXml('f.multicall', array($hash, '', 'f.set_create_queued=0', 'f.set_resize_queued=0'));
		$decision = self::decideOnModernDaemon($xml, 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($decision['action'] === 'send' && $decision['trusted'],
			'one torrent recreates files through the two registered f setters');
		$this->assertTrue(strpos($decision['payload'], 'f.set_create_queued="0"') !== false,
			'the requested value is rebuilt as a single literal argument');
		$batched = $this->systemMulticallXml(array(array('f.multicall',
			array($hash, '', 'f.set_create_queued=0', 'f.set_resize_queued=0'))));
		$decision = self::decideOnModernDaemon($batched, 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($decision['action'] === 'send' && $decision['trusted'],
			'multiple torrent actions can use the same validated member');
	}

	public function testRatioListingKeepsTheStringProducedByTheExactCatExpression()
	{
		$good = $this->methodCallXml('d.multicall2', array('', 'main', 'cat=$d.views=', 'd.name='));
		$decision = self::decideOnModernDaemon($good, 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($decision['action'] === 'send' && $decision['trusted'],
			'the exact shipped ratio expression can return a string');
		$this->assertTrue(strpos($decision['payload'], 'cat=$d.views=') !== false,
			'the return type preserving expression reaches rTorrent unchanged');
		foreach(array('cat=$execute.capture=id', 'cat=$d.views=,$execute.capture=id', 'cat=$d.views=;execute.capture=id') as $bad)
		{
			$decision = self::decideOnModernDaemon($this->methodCallXml('d.multicall2', array('', 'main', $bad)), 'sanitize', XMLRPCProxy::defaultSafeParams());
			$this->assertTrue($decision['action'] === 'reject', 'nearby executable cat expression is refused');
		}
		$filtered = $this->methodCallXml('d.multicall.filtered',
			array('', 'main', 'cat=$d.views=', 'd.name='));
		$decision = self::decideOnModernDaemon($filtered, 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($decision['action'] === 'reject',
			'the ratio result exception does not become an executable filter exception');
	}

	public function testShowPeersReadExpressionsAreExactResultOnlyExceptions()
	{
		foreach(array('t.scrape_complete=', 't.scrape_incomplete=') as $counter)
		{
			$expression = 'cat="$t.multicall=d.hash=,'.$counter.',cat={#}"';
			$d = self::decideOnModernDaemon($this->methodCallXml('d.multicall2',
				array('', 'main', 'd.hash=', $expression)),
				'sanitize', XMLRPCProxy::defaultSafeParams());
			$this->assertTrue($d['action'] === 'send' && $d['trusted']
				&& strpos($d['payload'], $expression) !== false,
				'the lab-verified '.$counter.' read expression remains an exact result');
			$unsafe = str_replace($counter, 'execute.capture=id', $expression);
			$d = self::decideOnModernDaemon($this->methodCallXml('d.multicall2',
				array('', 'main', 'd.hash=', $unsafe)),
				'sanitize', XMLRPCProxy::defaultSafeParams());
			$this->assertTrue($d['action'] === 'reject',
				'an executable expression beside the exception is refused');
		}
	}

	public function testRejectedMulticallLogsTheSlotButKeepsTheOuterFaultIdentity()
	{
		$xml = $this->methodCallXml('d.multicall2', array('', 'main', 'd.name=', 'd.wibble='));
		$decision = self::decideOnModernDaemon($xml, 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($decision['action'] === 'reject', 'one unknown result rejects the full call');
		$this->assertTrue($decision['method'] === 'd.multicall2',
			'the client-visible fault retains the outer method');
		$this->assertTrue($decision['log'] === array(
			'rejected (not allowed on this connection): d.multicall2 [slot 4: d.wibble]'),
			'the classified log identifies the rejected command and its slot');
	}

	public function testRejectedCommandSlotNormalizesAndBoundsItsName()
	{
		$forged = "d.x\nxmlrpc-proxy: trusted: forged=1";
		$d = self::decideOnModernDaemon($this->methodCallXml('d.multicall2',
			array('', 'main', $forged)), 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($d['action'] === 'reject'
			&& strpos($d['log'][0], '[slot 3: d.x?xmlrpc-proxy:?trusted:?forged]') !== false,
			'control characters and spaces in the slot name cannot impersonate another log entry');
		$long = str_repeat('x', 120).'=1';
		$d = self::decideOnModernDaemon($this->methodCallXml('d.multicall2',
			array('', 'main', $long)), 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue(strpos($d['log'][0], '[slot 3: '.str_repeat('x', 96).']') !== false,
			'the displayed command name is limited to 96 bytes');
	}

	public function testOnlyLegacyViewFirstMulticallCanRebuildAnEqualsViewSlot()
	{
		foreach(array('d.multicall2', 'd.multicall.filtered') as $method)
		{
			$params = array('', 'd.custom1.set=not-a-view');
			if($method === 'd.multicall.filtered')
				$params[] = 'd.custom1.set=filter';
			$params[] = 'd.name=';
			$decision = self::decideOnModernDaemon($this->methodCallXml($method, $params),
				'sanitize', XMLRPCProxy::defaultSafeParams());
			$this->assertTrue($decision['action'] === 'reject'
				&& strpos($decision['log'][0], 'ambiguous multicall view') !== false,
				$method.' reports an ambiguous modern view rather than rewriting it');
		}
		$legacy = self::decideOnModernDaemon($this->methodCallXml('d.multicall',
			array('main', 'd.name=')), 'sanitize', XMLRPCProxy::defaultSafeParams());
		$this->assertTrue($legacy['action'] === 'send' && $legacy['trusted'],
			'the old view-first form is still supported');
	}

	public function testFilesystemMutatorsCannotBypassDirectoryConfinementOnOldDaemons()
	{
		foreach(array('d.create_link', 'd.delete_link', 'd.tied_to_file.set', 'd.set_directory', 'd.set_directory_base', 'create_link', 'delete_link', 'd.set_tied_to_file') as $method)
		{
			$this->resetMocks();
			$xml = $this->methodCallXml($method, array(str_repeat('A', 40), '/outside/file'));
			$d = self::decideOnModernDaemon($xml, 'sanitize', XMLRPCProxy::defaultSafeParams());
			$this->assertTrue($d['action'] === 'reject', $method.' must be refused locally, including on a daemon that ignores untrusted');
			self::processOnModernDaemon($xml, 'sanitize');
			$this->assertTrue(rXMLRPCRequest::$sent === 0, $method.' must never reach transport');
		}
	}

	public function testLegacyAliasesOfDeniedCommandsNeverReachTransport()
	{
		// Four redirects from rTorrent v0.9.8 src/main.cc; the remaining names
		// are pre-0.9.4 spellings in php/methods-0.9.4.php.
		foreach(array('directory', 'session', 'scgi_port', 'scgi_local',
			'set_directory', 'set_session', 'get_scgi_dont_route', 'set_scgi_dont_route',
			'load', 'load_verbose', 'load_start', 'load_start_verbose',
			'load_raw', 'load_raw_start', 'load_raw_verbose',
			'set_xmlrpc_size_limit', 'xmlrpc_size_limit',
			'system.method.erase', 'system.method.get', 'system.method.has_key',
			'system.method.insert', 'system.method.list_keys', 'system.method.set',
			'system.method.set_key', 'view_filter', 'view_sort_new', 'view_sort_current') as $method)
		{
			$this->resetMocks();
			$xml = $this->methodCallXml($method, array('', '/outside'));
			$d = self::decideOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', $method.' is locally denied without relying on daemon trust support');
			self::processOnModernDaemon($xml, 'sanitize');
			$this->assertTrue(rXMLRPCRequest::$sent === 0, $method.' never reaches transport');
		}
	}

	public function testLegacyViewFirstListingsRebuildTheirFirstResultSlot()
	{
		foreach(array(array('main', 'd.hash='), array('main', 'd.hash=', 'd.name='),
			array('main', 'd.custom=key;execute.throw=id', 'd.name=')) as $params)
		{
			$d = self::decideOnModernDaemon($this->methodCallXml('d.multicall', $params), 'sanitize');
			$this->assertTrue($d['action'] === 'send' && $d['trusted'], 'safe view-first listing remains available');
			$expected = count($params) === 3 && $params[1] !== 'd.hash='
				? 'd.custom="key;execute.throw=id"' : 'd.hash=""';
			$this->assertTrue(strpos($d['payload'], $expected) !== false, 'the first result slot is rebuilt with quoted data');
		}
		foreach(array('d.get_hash=', 'd.get_name=') as $alias)
		{
			$d = self::decideOnModernDaemon($this->methodCallXml('d.multicall',
				array('main', $alias)), 'sanitize');
			$this->assertEquals('reject', $d['action'],
				'a client-side getter alias cannot execute in a trusted result slot');
		}
		foreach(array('execute.throw=id', 'd.wibble=', 'd.directory.set=/downloads/x',
			'd.custom=$execute.capture=id') as $command)
		{
			$d = self::decideOnModernDaemon($this->methodCallXml('d.multicall', array('main', $command)), 'sanitize', XMLRPCProxy::defaultSafeParams());
			$this->assertTrue($d['action'] === 'reject', 'a single legacy result slot cannot bypass command validation: '.$command);
		}
	}

	public function testNonDownloadMulticallsKeepEqualsInTheirDataSlot()
	{
		foreach(array('f.multicall' => 'f.path=', 't.multicall' => 't.url=', 'p.multicall' => 'p.address=') as $method => $command)
		{
			$d = self::decideOnModernDaemon($this->methodCallXml($method, array(str_repeat('A', 40), '*v=abc*', $command)), 'sanitize');
			$this->assertTrue($d['action'] === 'send' && $d['trusted'], $method.' permits equals in the non-executable data slot');
			$this->assertTrue(strpos($d['payload'], '<string>*v=abc*</string>') !== false, 'data slot reaches the daemon unchanged');
		}
	}

	public function testSymlinkDotSegmentsCannotChangeTheCheckedDirectory()
	{
		require_once(__DIR__ . '/../../php/xmlrpc_path.php');
		$root = sys_get_temp_dir().'/proxy-dot-'.uniqid();
		if(!mkdir($root.'/inside', 0700, true) || !mkdir($root.'/outside/child', 0700, true)
			|| !symlink($root.'/outside/child', $root.'/inside/link'))
			throw new Exception('could not create directory boundary fixture');
		try
		{
			$policy = array('root' => $root.'/inside', 'resolve' => array('XMLRPCPathResolver', 'deepestExistingAncestor'));
			foreach(self::DIRECTORY_SETTERS as $setter)
				foreach(array('/link/..', '/link/../new', '/./new', '/new/../target') as $tail)
				{
					$this->assertTrue(!$this->loadInto($root.'/inside'.$tail, $policy, $setter), $setter.' refuses dot segments before path normalization: '.$tail);
					$this->assertTrue(rXMLRPCRequest::$sent === 0, 'dot segments never reach transport');
				}
			$this->assertTrue($this->loadInto($root.'/inside//new/', $policy), 'repeated and trailing separators remain valid');
		}
		finally
		{
			unlink($root.'/inside/link');
			rmdir($root.'/inside');
			rmdir($root.'/outside/child');
			rmdir($root.'/outside');
			rmdir($root);
		}
	}

	public function testMulticallFilterMayNotChangeAnyDownloadDirectory()
	{
		foreach(self::DIRECTORY_SETTERS as $setter)
		{
			$xml = $this->methodCallXml('d.multicall.filtered', array('', 'main', $setter.'=/downloads/x', 'd.name='));
			$d = self::decideOnModernDaemon($xml, 'sanitize', array($setter), false, array('directory' => array('root' => '/downloads')));
			$this->assertTrue($d['action'] === 'reject', $setter.' in a filter is as unsafe as in a result slot');
		}
	}

	public function testQuotedDirectoryWhitespaceCannotChangeThePathBeingChecked()
	{
		foreach(array('" /downloads/x"', '"'."\t".'/downloads/x"', '"/downloads/x "') as $path)
		{
			$this->assertTrue(!$this->loadInto($path, array('root' => '/downloads')), 'quoted boundary whitespace must not be trimmed only for validation');
			$this->assertTrue(rXMLRPCRequest::$sent === 0, 'refused path never reaches transport');
		}
		$this->assertTrue($this->loadInto('"/downloads/a b"', array('root' => '/downloads')), 'internal spaces are valid path data');
		$this->assertTrue(strpos(rXMLRPCRequest::$lastPayload, 'd.directory.set="/downloads/a b"') !== false, 'the exact checked path reaches transport');
	}

	public function testDefaultPolicyAllowsCanonicalBaseDirectoryWithinBoundary()
	{
		$xml = $this->methodCallXml('load.start', array('', 'https://example.test/x.torrent', 'd.directory.base.set=/downloads/x'));
		$d = self::decideOnModernDaemon($xml, 'sanitize', XMLRPCProxy::defaultSafeParams(), false, array('directory' => array('root' => '/downloads')));
		$this->assertTrue($d['action'] === 'send' && $d['trusted'], 'the canonical setter is available under the same boundary as its alias');
	}

	public function testPersistedDirectoryPolicyRemainsAnExactOverride()
	{
		foreach(array('d.directory_base.set' => 'send', 'd.directory.base.set' => 'reject') as $setter => $action)
		{
			$xml = $this->methodCallXml('load.start', array('', 'https://example.test/x.torrent', $setter.'=/downloads/x'));
			$d = self::decideOnModernDaemon($xml, 'sanitize', array('d.directory_base.set'), false, array('directory' => array('root' => '/downloads')));
			$this->assertTrue($d['action'] === $action, 'an existing explicit policy permits only its configured spelling: '.$setter);
			if($setter === 'd.directory.base.set')
			{
				$this->assertTrue($d['method'] === 'load.start',
					'the client fault still names the outer load call');
				$this->assertTrue(strpos($d['log'][0],
					'policy lists d.directory_base.set but not d.directory.base.set; add it to conf/xmlrpc_proxy.php or the httprpc policy override]') !== false,
					'the deployed policy mismatch has a precise actionable log message');
			}
		}
	}

	// ---- Mode dispatch ----

	public function testOffModeReturnsNull()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params></params></methodCall>';
		$this->assertTrue(self::processOnModernDaemon($xml, 'off') === null, 'off mode returns null');
	}

	public function testOffModeRejectsGarbage()
	{
		$this->resetMocks();
		$this->assertTrue(self::processOnModernDaemon('not xml at all', 'off') === null, 'off mode rejects garbage too');
	}

	public function testPassthroughUnsafeForwardsTrusted()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>execute</methodName><params></params></methodCall>';
		self::processOnModernDaemon($xml, 'passthrough_unsafe');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === true, 'passthrough_unsafe forwards as trusted');
		$this->assertEquals($xml, rXMLRPCRequest::$lastPayload, 'passthrough_unsafe forwards payload verbatim');
	}

	/**
	 * The legacy method name says "ForwardsUntrusted" for compatibility with
	 * test inventories; the current contract is terminal rejection.
	 */
	public function testInvalidXmlForwardsUntrusted()
	{
		$this->resetMocks();
		$this->assertTrue(self::processOnModernDaemon('not xml at all', 'sanitize') === null, 'invalid XML is rejected');
	}

	public function testNonLoadMethodForwardsUntrusted()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params></params></methodCall>';
		self::processOnModernDaemon($xml, 'sanitize');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === false, 'non-load method forwarded as untrusted');
	}

	// ---- Sanitize-mode whitelist (the security-critical path) ----

	public function testSanitizeEndToEndForwardsCleanedPayload()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.com/t.torrent</string></value></param><param><value><string>execute=evil</string></value></param></params></methodCall>';
		$result = self::processOnModernDaemon($xml, 'sanitize', false, array('d.directory.set'));
		$this->assertTrue($result === null, 'all-or-nothing load with denied parameter returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport sends for denied parameter');
		$this->assertTrue(rXMLRPCRequest::$lastPayload === null, 'no payload forwarded for denied parameter');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === null, 'null trust for denied parameter');
	}

	// ---- Sanity ----

	public function testSanitizeMethodsList()
	{
		$ref = new ReflectionProperty('XMLRPCProxy', 'sanitizeMethods');
		if(PHP_VERSION_ID < 80100) $ref->setAccessible(true);
		$methods = $ref->getValue();
		$this->assertTrue(in_array('load.start', $methods), 'load.start in sanitize list');
		$this->assertTrue(in_array('load.raw_start', $methods), 'load.raw_start in sanitize list');
		$this->assertTrue(!in_array('execute', $methods), 'execute NOT in sanitize list');
		$this->assertTrue(!in_array('system.multicall', $methods), 'system.multicall NOT in sanitize list');
		$this->assertTrue(!in_array('execute2', $methods), 'execute2 NOT in sanitize list');
	}

	// ---- A command parameter is not one command ----

	private function sanitizeParam($param, $safeParams = array('d.custom1.set', 'd.custom2.set', 'd.custom.set'))
	{
		$this->resetMocks();
		$torrentB64 = base64_encode("d8:announce11:http://test4:infod6:lengthi1234eee");
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.raw_start</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><base64>' . $torrentB64 . '</base64></value></param>'
			. '<param><value><string>' . htmlspecialchars($param, ENT_NOQUOTES) . '</string></value></param>'
			. '</params></methodCall>';
		self::processOnModernDaemon($xml, 'sanitize', false, $safeParams);
		return (string) rXMLRPCRequest::$lastPayload;
	}

	public function testChainedCommandIsNotForwarded()
	{
		$sent = $this->sanitizeParam('d.custom1.set=A;d.custom2.set=(execute.capture,/bin/sh,-c,id)');
		// The ';' and everything after it end up inside the quoted argument.
		$this->assertTrue(strpos($sent, 'd.custom1.set="A;d.custom2.set=(execute.capture,/bin/sh,-c,id)"') !== false,
			'a chained command is forwarded as text inside an argument, not as a command');
	}

	public function testNestedCommandValueIsQuoted()
	{
		$sent = $this->sanitizeParam('d.custom2.set=(execute.capture,/bin/sh,-c,id)');
		$this->assertTrue(strpos($sent, 'd.custom2.set="(execute.capture,/bin/sh,-c,id)"') !== false,
			'a parenthesised value must be forwarded quoted, as an argument');
	}

	public function testCommandNameMustMatchExactly()
	{
		$sent = $this->sanitizeParam('d.custom1.setEVIL=x;d.custom2.set=(execute.capture,/bin/sh,-c,"id")');
		$this->assertTrue(strpos($sent, 'custom1.setEVIL') === false, 'a command that merely starts with an allowed name is dropped');
		$this->assertTrue(strpos($sent, 'execute.capture') === false, 'and its payload goes with it');
	}

	public function testParameterWithoutSeparatorIsDropped()
	{
		$sent = $this->sanitizeParam('d.custom1.set');
		$this->assertTrue(strpos($sent, 'd.custom1.set') === false, 'a parameter with no = is dropped');
	}

	// ---- and the values clients legitimately send still arrive ----

	public function testValueKeepsCharactersThatUsedToBreakIt()
	{
		$sent = $this->sanitizeParam('d.custom1.set=Movies (2024)');
		$this->assertTrue(strpos($sent, 'd.custom1.set="Movies (2024)"') !== false,
			'parentheses and spaces survive as a quoted argument');
	}

	public function testSingleArgumentCommandsPreserveCommasInValues()
	{
		$sent1 = $this->sanitizeParam('d.custom1.set=Movies, Inc');
		$this->assertTrue(strpos($sent1, 'd.custom1.set="Movies, Inc"') !== false,
			'commas in single-argument commands are preserved inside the argument');

		$sent2 = $this->sanitizeParam('d.directory.set=/data/Movies, Inc', array('d.directory.set'));
		$this->assertTrue(strpos($sent2, 'd.directory.set="/data/Movies, Inc"') !== false,
			'commas in directory path argument are preserved');
	}

	public function testMultiArgumentCommandsSplitProperly()
	{
		$sent = $this->sanitizeParam('d.custom.set=category,Movies, Inc', array('d.custom.set'));
		$this->assertTrue(strpos($sent, 'd.custom.set="category","Movies, Inc"') !== false,
			'd.custom.set splits key from value and preserves commas in the value');
	}

	// The one read command that takes two arguments. Measured 2026-09-14 on
	// 0.16.22: d.custom.if_z=key,fallback answers the fallback when the key is
	// unset, and d.custom.if_z="key,fallback" -- one argument -- faults
	// "Missing default argument." A read is split like the two-argument setter
	// is, and each half is screened for an evaluator on its own.
	public function testTheTwoArgumentReadCommandIsSplitLikeTheSetter()
	{
		$this->multicall(array('', 'main', 'd.custom.if_z=chk-state,none'));
		$this->assertTrue(rXMLRPCRequest::$sent === 1, 'the listing reaches rtorrent');
		$this->assertTrue(strpos((string) rXMLRPCRequest::$lastPayload, 'd.custom.if_z="chk-state","none"') !== false,
			'key and fallback are two quoted arguments, not one');

		$this->multicall(array('', 'main', 'd.custom.if_z="chk-state","none"'));
		$this->assertTrue(rXMLRPCRequest::$sent === 1
			&& strpos((string) rXMLRPCRequest::$lastPayload, 'd.custom.if_z="chk-state","none"') !== false,
			'the already-quoted spelling is accepted and rebuilt the same way');

		$this->multicall(array('', 'main', 'd.custom.if_z=chk-state,$execute.capture=/bin/hostname'));
		$this->assertTrue(rXMLRPCRequest::$sent === 0,
			'an evaluator in the fallback slot is refused, not quoted into the key');
	}

	public function testQuotesAndBackslashesAreEscaped()
	{
		$sent = $this->sanitizeParam('d.custom1.set=say "hi" \\ bye');
		$this->assertTrue(strpos($sent, '\\"hi\\"') !== false, 'a quote in the value is escaped, not closing the argument');
	}

	public function testMultipleArgumentsArePreserved()
	{
		$sent = $this->sanitizeParam('d.custom.set=chk-state,7');
		$this->assertTrue(strpos($sent, 'd.custom.set="chk-state","7"') !== false,
			'a command taking two arguments still gets two');
	}

	// ---- trust ----

	public function testRebuiltRequestIsTrusted()
	{
		$this->sanitizeParam('d.custom1.set=label');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === true, 'a fully rebuilt request may be trusted');
	}

	public function testRequestIsUntrustedWhenAParamCannotBeRebuilt()
	{
		$this->resetMocks();
		// An <int> target is a type this side does not rebuild, so the malformed load is rejected.
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.raw_start</methodName><params>'
			. '<param><value><int>1</int></value></param>'
			. '<param><value><string>http://example.test/x.torrent</string></value></param>'
			. '</params></methodCall>';
		$res = self::processOnModernDaemon($xml, 'sanitize', false, array('d.custom1.set'));
		$this->assertTrue($res === null, 'anything not rebuilt here is terminally rejected, for rtorrent never to see');
	}

	public function testDollarPrefixedArgumentIsDropped()
	{
		$sent = $this->sanitizeParam('d.custom1.set=$execute.capture=/bin/hostname');
		$this->assertTrue(strpos($sent, 'execute.capture') === false,
			'an argument that would be re-parsed as a command is dropped, not quoted');
	}

	public function testDollarPrefixedSecondArgumentIsDropped()
	{
		$sent = $this->sanitizeParam('d.custom.set=key,$execute.capture=/bin/hostname');
		$this->assertTrue(strpos($sent, 'execute.capture') === false,
			'a denied second argument rejects the whole load');
	}

	public function testDollarInsideAValueIsKept()
	{
		$sent = $this->sanitizeParam('d.custom1.set=cost 20$ or so');
		$this->assertTrue(strpos($sent, 'd.custom1.set="cost 20$ or so"') !== false,
			'only a leading $ is special, so ordinary values keep theirs');
	}

	// ---- what the log says ----

	private function logText()
	{
		return implode("\n", FileUtil::$log);
	}

	public function testUntrustedRequestIsNeverLoggedAsTrusted()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params></params></methodCall>';
		self::processOnModernDaemon($xml, 'sanitize', true, array('d.custom1.set'));

		$this->assertTrue(rXMLRPCRequest::$lastTrusted === false, 'the call is sent untrusted');
		foreach(FileUtil::$log as $line)
			$this->assertTrue(strpos($line, 'xmlrpc-proxy: trusted') !== 0,
				'a request sent untrusted is never logged as trusted');
		$this->assertTrue(strpos($this->logText(), 'untrusted') !== false, 'and it is logged as untrusted');
	}

	public function testStrippedValueCannotForgeALogLine()
	{
		$this->resetMocks();
		$this->sanitizeParamLogged("execute=evil\nxmlrpc-proxy: trusted: load.raw_start (2 params)");
		$this->assertTrue(strpos($this->logText(), "\nxmlrpc-proxy: trusted") === false,
			'a rejected command cannot inject a second log entry');
	}

	public function testLoggedValueIsLengthCapped()
	{
		$this->resetMocks();
		$this->sanitizeParamLogged('execute=' . str_repeat('A', 500));
		$this->assertTrue(strlen($this->logText()) < 400, 'a long rejected command is bounded in the log');
	}

	private function sanitizeParamLogged($param)
	{
		$torrentB64 = base64_encode("d8:announce11:http://test4:infod6:lengthi1234eee");
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.raw_start</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><base64>' . $torrentB64 . '</base64></value></param>'
			. '<param><value><string>' . htmlspecialchars($param, ENT_NOQUOTES) . '</string></value></param>'
			. '</params></methodCall>';
		self::processOnModernDaemon($xml, 'sanitize', true, array('d.custom1.set'));
	}

	// ---- shapes rtorrent itself accepts ----

	public function testWhitespaceAroundTheCommandNameIsAccepted()
	{
		$this->assertTrue(strpos($this->sanitizeParam(' d.custom1.set=x'), 'd.custom1.set="x"') !== false,
			'a leading space does not hide the command name');
		$this->assertTrue(strpos($this->sanitizeParam('d.custom1.set =x'), 'd.custom1.set="x"') !== false,
			'nor does a space before the =');
	}

	public function testArgumentsAreTrimmedAsRtorrentTrimsThem()
	{
		$this->assertTrue(strpos($this->sanitizeParam('d.custom.set=chk-state, 7'), 'd.custom.set="chk-state","7"') !== false,
			'an unquoted argument is trimmed, matching what rtorrent stores');
	}

	public function testDollarIsCheckedAfterTrimming()
	{
		$sent = $this->sanitizeParam('d.custom1.set= $execute.capture=/bin/hostname');
		$this->assertTrue(strpos($sent, 'execute.capture') === false,
			'trimming must not quote a leading space into a leading $');
	}

	// ---- parameter forms ----

	public function testImplicitStringParamFormIsRead()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>http://example.test/x.torrent</string></value></param>'
			. '<param><value>d.custom1.set=label</value></param>'
			. '</params></methodCall>';
		self::processOnModernDaemon($xml, 'sanitize', false, array('d.custom1.set'));
		$this->assertTrue(strpos((string) rXMLRPCRequest::$lastPayload, 'd.custom1.set="label"') !== false,
			'a value without an explicit <string> is read the same way');
	}

	public function testBase64DataParamIsRebuiltAndStillTrusted()
	{
		$this->resetMocks();
		$data = str_repeat("torrent-bytes\x00\xc8", 20);
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.raw_start</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><base64>' . chunk_split(base64_encode($data), 76, "\n") . '</base64></value></param>'
			. '<param><value><string>d.custom1.set=label</string></value></param>'
			. '</params></methodCall>';
		self::processOnModernDaemon($xml, 'sanitize', false, array('d.custom1.set'));
		$sent = (string) rXMLRPCRequest::$lastPayload;

		$this->assertTrue(preg_match('#<base64>(.*?)</base64>#s', $sent, $m) === 1, 'the data param is still base64');
		$this->assertTrue(base64_decode($m[1], true) === $data, 'and it carries the same bytes, wrapping removed');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === true, 'a rebuilt base64 param does not force the call untrusted');
	}

	public function testPreQuotedValueIsKeptAsOneArgument()
	{
		$sent = $this->sanitizeParam('d.custom1.set="Movies, Inc"');
		$this->assertTrue(strpos($sent, 'd.custom1.set="Movies, Inc"') !== false,
			'a value the client quoted itself is unquoted and re-quoted as one argument');
		$this->assertTrue(strpos($sent, 'd.custom1.set="Movies","Inc"') === false,
			'and the comma inside the quotes does not split it');
	}

	public function testCrossSeedQuotedLoadParamsAreKept()
	{
		$sent = $this->sanitizeParam('d.custom1.set="cross-seed"',
			array('d.custom1.set', 'd.directory_base.set', 'd.custom.set'));
		$this->assertTrue(strpos($sent, 'd.custom1.set="cross-seed"') !== false,
			'cross-seed quotes the label; that must still set custom1');

		$sent = $this->sanitizeParam(
			'd.directory_base.set="/data/Media/torrent/download/cross-seed/PassThePopcorn"',
			array('d.custom1.set', 'd.directory_base.set', 'd.custom.set'));
		$this->assertTrue(strpos($sent,
			'd.directory_base.set="/data/Media/torrent/download/cross-seed/PassThePopcorn"') !== false,
			'and the quoted save path must still set directory_base');
	}

	public function testQuotedDollarPrefixedArgumentIsDropped()
	{
		$sent = $this->sanitizeParam('d.custom1.set="$execute.capture=/bin/hostname"');
		$this->assertTrue(strpos($sent, 'execute.capture') === false,
			'quoting does not hide a leading $; the load is rejected');
	}

	public function testUnclosedQuoteIsDropped()
	{
		$this->resetMocks();
		$this->sanitizeParamLogged('d.custom1.set="Movies, Inc');
		$this->assertTrue(strpos((string) rXMLRPCRequest::$lastPayload, 'd.custom1.set') === false,
			'an unclosed quote rejects the whole load without forwarding it');
		$this->assertTrue(strpos($this->logText(), 'rejected') !== false,
			'and the rejection is visible in the log');
	}

	public function testUnknownMethodNameCannotForgeALogLine()
	{
		$this->resetMocks();
		$name = "system.foo\nxmlrpc-proxy: trusted: forged";
		$xml = '<?xml version="1.0"?><methodCall><methodName>' . htmlspecialchars($name)
			. '</methodName><params></params></methodCall>';
		self::processOnModernDaemon($xml, 'sanitize', true, array());

		$this->assertTrue(rXMLRPCRequest::$lastTrusted === false, 'an unknown method is sent untrusted');
		$this->assertTrue(strpos($this->logText(), "\nxmlrpc-proxy: trusted") === false,
			'a method name cannot start a log line of its own');
	}

	// ---- multicalls carry commands too ----

	private function multicall($params, $safeParams = array('d.custom1.set'))
	{
		$this->resetMocks();
		$xml = $this->methodCallXml('d.multicall2', $params);
		self::processOnModernDaemon($xml, 'sanitize', true, $safeParams);
		return $xml;
	}

	public function testMulticallCommandsAreRebuiltLikeLoadParams()
	{
		$this->multicall(array('', 'main', 'd.custom1.set=Movies (2024)'));
		$this->assertTrue(strpos((string) rXMLRPCRequest::$lastPayload,
			'd.custom1.set="Movies (2024)"') !== false,
			'an allowed command in a multicall is quoted the same way as in a load');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === true,
			'and a multicall of nothing but allowed commands may be trusted');
	}

	// An unknown command in a multicall causes terminal outer rejection.
	public function testMulticallWithAnUnknownCommandIsRejected()
	{
		// d.name= used to stand here, and stopped being unknown when the read
		// list arrived. A command on neither list is what this case is about.
		$this->multicall(array('', 'main', 'd.wibble='));
		$this->assertTrue(rXMLRPCRequest::$sent === 0,
			'the request is rejected, not forwarded');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === null,
			'and not trusted');
	}


	// The listing request every remote client makes. It names no setter, so
	// before the read list it matched nothing in $safeParams and took the
	// whole outer call down with it -- which is what the live instance did to
	// a client on 2026-09-08, four times, before it gave up and asked about
	// each download one command at a time.
	public function testMulticallOfReadCommandsIsRebuiltAndTrusted()
	{
		$this->multicall(array('', 'main', 'd.hash=', 'd.name=', 'd.custom=chk-state'));
		$this->assertTrue(rXMLRPCRequest::$sent === 1,
			'a listing request reaches rtorrent');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === true,
			'rebuilt from its parsed parts, so it goes trusted');
		$this->assertTrue(strpos(rXMLRPCRequest::$lastPayload, 'd.custom="chk-state"') !== false,
			'a read command that carries an argument keeps it, quoted');
	}

	// A multicall applies its commands to everything in a view, so a directory
	// setter in one names where every download in that view is written. It is
	// refused outright here, ahead of the rebuild, rather than left to the
	// $topDirectory boundary -- which is configuration, and is absent on a
	// caller that passes no directory policy at all.
	//
	// Measured by mutation 2026-09-12: dropping the isDirectDenied() call from
	// the result slot let d.directory.set= through with no suite noticing.
	public function testMulticallMayNotCarryADirectorySetter()
	{
		foreach(self::DIRECTORY_SETTERS as $setter)
		{
			$this->multicall(array('', 'main', $setter.'=/srv/anywhere'), array($setter));
			$this->assertTrue(rXMLRPCRequest::$sent === 0, $setter.' is refused in result slots even when allowed in load tails');
			$this->assertTrue(rXMLRPCRequest::$lastTrusted === null, 'a refused setter never reaches transport');
		}
	}

	// Quoting cannot make a $-prefixed argument safe: rtorrent parses and calls
	// it. rebuildSafeLoadParam() refuses it for setters, and a read command
	// goes through the same rebuild, so it is refused there too.
	public function testReadCommandArgumentNamingAnEvaluatorIsRefused()
	{
		$this->multicall(array('', 'main', 'd.custom=$execute.capture=/bin/hostname'));
		$this->assertTrue(rXMLRPCRequest::$sent === 0,
			'an evaluator smuggled into a read command argument is refused');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === null,
			'and nothing is trusted');
	}

	public function testMulticallCarryingExecuteIsRefused()
	{
		$this->multicall(array('', 'main', 'execute.capture=/bin/sh,-c,id'));
		$this->assertTrue(rXMLRPCRequest::$sent === 0,
			'a multicall carrying execute.capture is refused, not forwarded');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === null,
			'and it certainly is not trusted');
	}

	public function testMulticallDollarArgumentIsNeverTrusted()
	{
		$this->multicall(array('', 'main', 'd.custom1.set=$execute.capture=/bin/hostname'));
		$this->assertTrue(rXMLRPCRequest::$sent === 0,
			'a multicall whose argument would be re-parsed is rejected, not forwarded');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === null,
			'and not trusted');
	}

	public function testMulticallViewNameIsDataNotACommand()
	{
		$this->multicall(array('', 'a view with spaces', 'd.custom1.set=label'));
		$this->assertTrue(strpos((string) rXMLRPCRequest::$lastPayload,
			'<string>a view with spaces</string>') !== false,
			'the view name is re-emitted as the value it is, not quoted as a command');
	}

	public function testCommandCarryingMethodsList()
	{
		$ref = new ReflectionProperty('XMLRPCProxy', 'multicallMethods');
		if(PHP_VERSION_ID < 80100) $ref->setAccessible(true);
		$methods = $ref->getValue();
		$this->assertTrue(in_array('d.multicall2', $methods), 'd.multicall2 is command-carrying');
		$this->assertTrue(in_array('t.multicall', $methods), 't.multicall is command-carrying');
		$this->assertTrue(!in_array('system.multicall', $methods),
			'system.multicall is NOT: its members are calls, not command strings');
		$this->assertTrue(!in_array('load.start', $methods),
			'load.start belongs to the list that strips, not this one');
	}

	// ---- load.* may not name a path on rtorrent's own filesystem ----

	private function load($uri, $allowLocalPaths = false, $method = 'load.start')
	{
		$this->resetMocks();
		$xml = $this->methodCallXml($method, array('', $uri));
		return self::processOnModernDaemon($xml, 'sanitize', true, array('d.custom1.set'), $allowLocalPaths);
	}

	public function testLoadFromALocalPathIsRejected()
	{
		$this->assertTrue($this->load('/srv/watch/x.torrent') === null,
			'a load naming a path on rtorrent\'s own filesystem is refused');
		$this->assertTrue(rXMLRPCRequest::$lastPayload === null,
			'and nothing is sent, not even untrusted');
	}

	/**
	 * Untrusted is not a refusal on rtorrent below 0.16.10 — the header is read
	 * and ignored — so this one cannot be left for rtorrent to sort out.
	 */
	public function testLocalPathIsRefusedRatherThanForwardedUntrusted()
	{
		$this->load('/srv/watch/x.torrent');
		$this->assertTrue(rXMLRPCRequest::$sent === 0, 'the request never reaches rtorrent');
		$this->assertTrue(strpos($this->logText(), 'local path') !== false,
			'and the refusal says why');
	}

	public function testNetworkAndMagnetUrisAreAccepted()
	{
		foreach(array('http://example.test/x.torrent', 'https://example.test/x.torrent',
			'ftp://example.test/x.torrent', 'magnet:?xt=urn:btih:abc') as $uri)
		{
			$this->load($uri);
			$this->assertTrue(rXMLRPCRequest::$sent === 1, $uri . ' is forwarded');
		}
	}

	/**
	 * rtorrent compares these with strncmp, so anything it would not recognise
	 * as a URI is a path to it, and has to be a path here too. Matching more
	 * loosely than rtorrent does is exactly the hole this closes.
	 */
	public function testSchemeMatchingIsAsStrictAsRtorrents()
	{
		foreach(array('HTTP://example.test/x.torrent', 'Magnet:?xt=urn:btih:abc',
			'magnet:xt=urn:btih:abc', ' http://example.test/x.torrent',
			'file:///srv/watch/x.torrent', 'watch/x.torrent', '~/x.torrent') as $uri)
		{
			$this->assertTrue($this->load($uri) === null, $uri . ' is treated as a local path');
		}
	}

	public function testBase64EncodingDoesNotHideALocalPath()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><base64>' . base64_encode('/srv/watch/x.torrent') . '</base64></value></param>'
			. '</params></methodCall>';
		$this->assertTrue(self::processOnModernDaemon($xml, 'sanitize', true, array(), false) === null,
			'a base64 parameter is read as the URI it decodes to');
	}

	public function testRawLoadIsUnaffected()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.raw_start</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><base64>' . base64_encode('d4:infoe') . '</base64></value></param>'
			. '</params></methodCall>';
		self::processOnModernDaemon($xml, 'sanitize', true, array(), false);
		$this->assertTrue(rXMLRPCRequest::$sent === 1,
			'load.raw_start carries the torrent itself, not a URI, so it is untouched');
	}

	public function testOperatorCanAllowLocalPathsWithAnExplicitRiskWarning()
	{
		$this->load('/srv/watch/x.torrent', true);
		$this->assertTrue(rXMLRPCRequest::$sent === 1,
			'the setting exists for automation that posts server-local paths');
		$this->assertTrue(strpos($this->logText(), 'operator-enabled local path forwarded') !== false,
			'the exceptional path mode is visible in the operational log');
	}


	// ---- refused outright, without asking rtorrent ----

	private function callMethod($method, $params = array(), $mode = 'sanitize')
	{
		$this->resetMocks();
		$xml = $this->methodCallXml($method, $params);
		return self::processOnModernDaemon($xml, $mode, true, array('d.custom1.set'));
	}

	public function testCapturedSettingsReadBatchUsesTrustOnlyForItsExactReadShape()
	{
		// Captured from the actual WebUI on rt-lab rTorrent 0.16.24.
		$xml = file_get_contents(__DIR__.'/fixtures/settings-read-rtorrent-0.16.24.xml');
		$this->assertTrue($xml !== false, 'the captured settings request is present');
		$policy = XMLRPCProxy::defaultSafeParams();
		$decision = self::decideOnModernDaemon($xml, 'sanitize', $policy,
			false, array('rtorrentVersion' => 0x1018));
		$this->assertTrue($decision['action'] === 'send' && $decision['trusted'],
			'the complete Settings read runs trusted so both listener flag getters answer');
		$this->assertTrue(strpos($decision['payload'], 'network.scgi.dont_route') !== false,
			'the exact read-only SCGI flag survives the rebuilt request');
		foreach(array(
			array('network.scgi.dont_route', 'network.scgi.open_port'),
			array('network.scgi.dont_route', 'system.sockets.files.min_alloc.set'),
			array('network.listen.port.range', 'execute.capture'),
		) as $replacement)
		{
			$changed = str_replace('<string>'.$replacement[0].'</string>',
				'<string>'.$replacement[1].'</string>', $xml);
			$this->assertTrue($changed !== $xml, 'the mutation changed a captured member');
			$blocked = self::decideOnModernDaemon($changed, 'sanitize', $policy,
				false, array('rtorrentVersion' => 0x1018));
			if($replacement[1] === 'system.sockets.files.min_alloc.set')
				$this->assertTrue($blocked['action'] !== 'send' || !$blocked['trusted'],
					'a socket setter cannot borrow trust from the settings read batch');
			else
				$this->assertEquals('reject', $blocked['action'],
					$replacement[1].' cannot borrow trust from the settings read batch');
		}
		$slot = '<string>network.scgi.dont_route</string></value></member><member><name>params</name><value><array><data></data>';
		$withArgument = str_replace($slot, '<string>network.scgi.dont_route</string></value></member><member><name>params</name><value><array><data><value><string>x</string></value></data>', $xml);
		$this->assertTrue($withArgument !== $xml, 'the extra-argument mutation changed slot 37');
		$blocked = self::decideOnModernDaemon($withArgument, 'sanitize', $policy,
			false, array('rtorrentVersion' => 0x1018));
		$this->assertEquals('reject', $blocked['action'],
			'the settings batch cannot carry a parameter in the SCGI getter slot');
		$mixed = self::decideOnModernDaemon($this->systemMulticallXml(array(
			array('throttle.max_uploads.global', array()),
			array('d.open', array(str_repeat('A', 40))),
		)), 'sanitize', $policy);
		$this->assertEquals('reject', $mixed['action'],
			'an arbitrary read plus privileged write cannot use the settings exception');
		$direct = self::decideOnModernDaemon($this->methodCallXml('network.scgi.dont_route'),
			'sanitize', $policy);
		$this->assertEquals('reject', $direct['action'],
			'the exact read exception belongs to the captured batch only');
		$short = self::decideOnModernDaemon($this->systemMulticallXml(array(
			array('throttle.max_uploads.global', array()),
			array('network.scgi.dont_route', array()),
		)), 'sanitize', $policy);
		$this->assertEquals('reject', $short['action'],
			'an arbitrary read batch cannot gain the special settings trust');
	}

	public function testCapturedLegacyPanelReadBatchesRequireExactTrustedShapes()
	{
		$policy = XMLRPCProxy::defaultSafeParams();
		foreach(array(
			'settings-read-rtorrent-0.9.8.xml' => 49,
			'panel-total-rtorrent-0.9.8.xml' => 4,
			'panel-open-rtorrent-0.9.8.xml' => 2,
		) as $fixture => $count)
		{
			$xml = file_get_contents(__DIR__.'/fixtures/'.$fixture);
			$this->assertTrue($xml !== false, $fixture.' is a captured browser request');
			$this->assertEquals($count, substr_count($xml, '<name>methodName</name>'),
				$fixture.' keeps its captured number of result slots');
			$decision = self::decideOnModernDaemon($xml, 'sanitize', $policy,
				false, array('rtorrentVersion' => 0x0908));
			$this->assertTrue($decision['action'] === 'send' && $decision['trusted'],
				$fixture.' is the exact read-only batch allowed on 0.9.8');
		}
	}

	public function testCapturedLegacyReadBatchesCannotElevateChangedCalls()
	{
		$policy = XMLRPCProxy::defaultSafeParams();
		$old = array('rtorrentVersion' => 0x0908);
		foreach(array('settings-read-rtorrent-0.9.8.xml',
			'panel-total-rtorrent-0.9.8.xml', 'panel-open-rtorrent-0.9.8.xml') as $fixture)
		{
			$xml = file_get_contents(__DIR__.'/fixtures/'.$fixture);
			$this->assertTrue($xml !== false, $fixture.' exists for the security mutations');
			$withWrite = preg_replace('/<name>methodName<\/name><value><string>[^<]+<\/string>/',
				'<name>methodName</name><value><string>execute.capture</string>', $xml, 1);
			$this->assertTrue($withWrite !== $xml, $fixture.' changed its first method');
			$blocked = self::decideOnModernDaemon($withWrite, 'sanitize', $policy, false, $old);
			$this->assertEquals('reject', $blocked['action'],
				$fixture.' cannot borrow trust for an execution command');
			$withArgument = preg_replace('/<name>params<\/name><value><array><data><\/data>/',
				'<name>params</name><value><array><data><value><string>x</string></value></data>', $xml, 1);
			$this->assertTrue($withArgument !== $xml, $fixture.' changed its first argument list');
			$blocked = self::decideOnModernDaemon($withArgument, 'sanitize', $policy, false, $old);
			$this->assertEquals('reject', $blocked['action'],
				$fixture.' cannot borrow trust with a parameter');
			preg_match_all('/<name>methodName<\/name><value><string>([^<]+)<\/string>/',
				$xml, $names);
			$this->assertTrue(count($names[1]) >= 2, $fixture.' has ordered read slots');
			$reordered = str_replace('<string>'.$names[1][0].'</string>',
				'<string>SWAP_SLOT</string>', $xml);
			$reordered = str_replace('<string>'.$names[1][1].'</string>',
				'<string>'.$names[1][0].'</string>', $reordered);
			$reordered = str_replace('<string>SWAP_SLOT</string>',
				'<string>'.$names[1][1].'</string>', $reordered);
			$blocked = self::decideOnModernDaemon($reordered, 'sanitize', $policy, false, $old);
			$this->assertEquals('reject', $blocked['action'],
				$fixture.' cannot reorder the captured read slots');
			$wrongVersion = self::decideOnModernDaemon($xml, 'sanitize', $policy,
				false, array('rtorrentVersion' => 0x1016));
			$this->assertTrue(!$wrongVersion['trusted'],
				$fixture.' exact legacy trust does not apply to a newer daemon');
		}
		$mixed = self::decideOnModernDaemon($this->systemMulticallXml(array(
			array('throttle.global_up.total', array()),
			array('d.open', array(str_repeat('A', 40))),
		)), 'sanitize', $policy, false, $old);
		$this->assertEquals('reject', $mixed['action'],
			'a shorter read and write batch cannot borrow the captured read grant');
		foreach(array('dht.statistics', 'throttle.global_up.total',
			'network.http.current_open', 'network.http.max_open', 'network.port_open') as $name)
		{
			$direct = self::decideOnModernDaemon($this->methodCallXml($name),
				'sanitize', $policy, false, $old);
			$this->assertEquals('reject', $direct['action'],
				$name.' alone cannot borrow trust from a captured batch');
		}
		$native = require(__DIR__.'/../../php/xmlrpc_proxy_native.php');
		foreach(array('network.http.max_open', 'network.port_open',
			'network.port_random', 'network.port_range') as $name)
			$this->assertEquals(0x1, $native[$name],
				$name.' is registered only for the live 0.9.8 daemon');
	}

	public function testExecutionPrimitivesAreRefused()
	{
		foreach(array('execute', 'execute.capture', 'execute.raw.bg', 'execute2',
			'method.insert', 'method.set_key', 'import', 'try_import',
			'schedule', 'schedule2', 'schedule.remove', 'log.execute',
			'log.open_file', 'network.scgi.open_port', 'catch', 'system.env') as $method)
		{
			$this->assertTrue($this->callMethod($method) === null, $method . ' is refused');
			$this->assertTrue(rXMLRPCRequest::$sent === 0, $method . ' never reaches rtorrent');
		}
	}

	/**
	 * The families are spelled differently across versions — 0.9.8 has execute2
	 * and schedule_remove2, 0.16.x has execute.raw.bg and schedule.remove — so
	 * the list matches prefixes. An exact list would go stale silently, and for
	 * a refusal list that is the wrong way to fail.
	 */
	public function testRefusalMatchesTheWholeFamily()
	{
		$ref = new ReflectionProperty('XMLRPCProxy', 'denyPrefixes');
		if(PHP_VERSION_ID < 80100) $ref->setAccessible(true);
		$this->assertTrue(in_array('execute', $ref->getValue()),
			'one entry covers every execute spelling');
		$this->assertTrue($this->callMethod('execute.capture_nothrow') === null,
			'including the ones not written down anywhere');
	}

	public function testHarmlessMethodsAreNotCaughtByTheRefusalList()
	{
		foreach(array('system.client_version', 'd.name', 'view.list', 'directory.default') as $method)
		{
			$this->callMethod($method);
			$this->assertTrue(rXMLRPCRequest::$sent === 1, $method . ' is still forwarded');
			$this->assertTrue(rXMLRPCRequest::$lastTrusted === false, $method . ' untrusted');
		}
	}

	public function testPassthroughUnsafeIsNotSubjectToTheRefusalList()
	{
		$this->callMethod('execute.capture', array('', '/bin/sh'), 'passthrough_unsafe');
		$this->assertTrue(rXMLRPCRequest::$sent === 1,
			'passthrough_unsafe is documented as dangerous and stays literal');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === true, 'and trusted');
	}

	public function testSystemMulticallMembersAreJudgedToo()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.multicall</methodName>'
			. '<params><param><value><array><data><value><struct>'
			. '<member><name>methodName</name><value><string>execute.capture</string></value></member>'
			. '<member><name>params</name><value><array><data>'
			. '<value><string></string></value></data></array></value></member>'
			. '</struct></value></data></array></value></param></params></methodCall>';
		$this->assertTrue(self::processOnModernDaemon($xml, 'sanitize', true, array()) === null,
			'a refused method does not get through inside a struct');
		$this->assertTrue(rXMLRPCRequest::$sent === 0, 'and nothing is forwarded');
	}

	// ---- elevated: refused by rtorrent untrusted, needed by real clients ----

	public function testOneDownloadByHashIsElevated()
	{
		foreach(array('d.open', 'd.start', 'd.stop', 'd.delete_tied') as $method)
		{
			$this->callMethod($method, array('0123456789abcdef0123456789ABCDEF01234567'));
			$this->assertTrue(rXMLRPCRequest::$lastTrusted === true, $method . ' is elevated');
			$this->assertTrue(strpos((string) rXMLRPCRequest::$lastPayload,
				'0123456789ABCDEF0123456789ABCDEF01234567') !== false,
				$method . ' is re-emitted with the hash this side validated');
		}
	}

	public function testAnArgumentThatIsNotAHashIsNotElevated()
	{
		foreach(array('not-a-hash', '', '0123456789abcdef0123456789ABCDEF0123456',
			'0123456789abcdef0123456789ABCDEF012345678', '../../etc/passwd') as $bad)
		{
			$this->assertTrue($this->callMethod('d.start', array($bad)) === null,
				var_export($bad, true) . ' is not a hash, so the call returns a refusal');
			$this->assertTrue(rXMLRPCRequest::$sent === 0,
				var_export($bad, true) . ' never reaches rtorrent');
		}
	}

	public function testTheArgumentCountHasToMatch()
	{
		$this->callMethod('d.start', array('0123456789ABCDEF0123456789ABCDEF01234567', 'extra'));
		$this->assertTrue(rXMLRPCRequest::$sent === 0,
			'an extra argument means the call is not the shape that was approved');
	}

	public function testAnElevatedValueIsCarriedAsDataNotAsACommand()
	{
		$this->callMethod('d.custom1.set',
			array('0123456789ABCDEF0123456789ABCDEF01234567', '$execute.capture=/bin/hostname'));
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === true, 'the call is elevated');
		$this->assertTrue(strpos((string) rXMLRPCRequest::$lastPayload,
			'<string>$execute.capture=/bin/hostname</string>') !== false,
			'and the value travels as a string parameter, which rtorrent stores rather than parses');
	}

	public function testTheSizeLimitIsClamped()
	{
		$this->callMethod('network.xmlrpc.size_limit.set', array('', '999999999'));
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === true, 'the call is elevated');
		$this->assertTrue(strpos((string) rXMLRPCRequest::$lastPayload, '<i8>16777216</i8>') !== false,
			'a client raising it to add a big torrent is fine; an unbounded value is not');
		$this->callMethod('network.xmlrpc.size_limit.set', array('', '2097152'));
		$this->assertTrue(strpos((string) rXMLRPCRequest::$lastPayload, '<i8>2097152</i8>') !== false,
			'a value under the ceiling is passed through as asked');
	}

	public function testElevationListHoldsNoCommandCarryingMethod()
	{
		$ref = new ReflectionProperty('XMLRPCProxy', 'elevate');
		if(PHP_VERSION_ID < 80100) $ref->setAccessible(true);
		$elevated = array_keys($ref->getValue());
		foreach(array('load.start', 'load.raw_start', 'd.multicall2', 't.multicall') as $method)
			$this->assertTrue(!in_array($method, $elevated),
				$method . ' takes command strings, so it is rebuilt rather than elevated');
	}

	// ---- where a download may be written ----
	//
	// d.directory.set names the directory rtorrent writes a download into, and
	// the caller supplies the torrent, so it names the file too. Unconfined and
	// forwarded trusted, that is an arbitrary file write as the rtorrent user —
	// found by a tester writing a .php into a webroot and running it.

	private function loadInto($dir, $policy = null, $command = 'd.directory.set',
		$uri = 'http://example.test/x.torrent', $allowLocalPaths = false)
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>' . htmlspecialchars($uri, ENT_NOQUOTES) . '</string></value></param>'
			. '<param><value><string>' . $command . '=' . htmlspecialchars($dir, ENT_NOQUOTES)
			. '</string></value></param>'
			. '</params></methodCall>';
		$options = ($policy === null) ? array() : array('directory' => $policy);
		self::processOnModernDaemon($xml, 'sanitize', true,
			self::DIRECTORY_SETTERS, $allowLocalPaths, $options);
		return strpos((string) rXMLRPCRequest::$lastPayload, $command . '=') !== false;
	}

	public function testRootBoundaryOptInDoesNotImplicitlyEnableLocalPaths()
	{
		$this->assertTrue(!$this->loadInto('/var/lib/downloads', array('root' => '/'),
			'd.directory.set', '/srv/watch/x.torrent', false),
			'an explicit root boundary does not bypass the independent local-path switch');
		$this->assertTrue(rXMLRPCRequest::$sent === 0,
			'the local-path request remains fail-closed before reaching rtorrent');
	}

	public function testBothPathOptInsPreserveLocalPathAutomation()
	{
		$this->assertTrue($this->loadInto('/var/lib/downloads', array('root' => '/'),
			'd.directory.set', '/srv/watch/x.torrent', true),
			'an operator may combine local paths with an explicit root boundary');
		$this->assertTrue(rXMLRPCRequest::$sent === 1,
			'the explicitly enabled automation still reaches rtorrent');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === true,
			'the fully rebuilt request keeps the same trusted transport contract');
	}

	public function testADirectoryOutsideTheBoundaryIsDropped()
	{
		$policy = array('root' => '/torrents1/downloads');
		$this->assertTrue(!$this->loadInto('/var/www/user1/rtorrent/share/settings/x/', $policy),
			'the reported attack — a webroot path — does not reach rtorrent');
		$this->assertTrue(rXMLRPCRequest::$sent === 0,
			'and the request is rejected with zero transport calls');
	}

	public function testADirectoryInsideTheBoundaryIsKept()
	{
		$policy = array('root' => '/torrents1/downloads');
		$this->assertTrue($this->loadInto('/torrents1/downloads/Movies', $policy),
			'a directory the customer is entitled to still works');
		$this->assertTrue($this->loadInto('/torrents1/downloads', $policy),
			'the boundary itself is inside it');
	}

	public function testAllDirectorySettersAreConfinedTheSameWay()
	{
		$policy = array('root' => '/torrents1/downloads');
		foreach (self::DIRECTORY_SETTERS as $setter) {
			$this->assertTrue(!$this->loadInto('/var/www/user1', $policy, $setter),
				$setter . ' cannot place torrent data outside the configured boundary');
			$this->assertTrue($this->loadInto('/torrents1/downloads/x', $policy, $setter),
				$setter . ' still works inside the boundary');
		}
	}

	public function testQuotedDirectoryInsideTheBoundaryIsKept()
	{
		$policy = array('root' => '/torrents1/downloads');
		$this->assertTrue($this->loadInto('"/torrents1/downloads/cross-seed"', $policy, 'd.directory_base.set'),
			'a client-quoted path is unquoted before the boundary check');
	}

	public function testPathTricksDoNotEscape()
	{
		$policy = array('root' => '/torrents1/downloads');
		foreach(array(
			'/torrents1/downloads/../../var/www',   // climbing out
			'/torrents1/downloads/./../../etc',     // with a . in the way
			'/torrents1/downloadsEVIL',             // a prefix, not a child
			'/torrents1/downloads/../downloads2',   // sibling
			'downloads/x',                          // not absolute at all
			'',                                     // nothing
		) as $dir)
		{
			$this->assertTrue(!$this->loadInto($dir, $policy),
				var_export($dir, true) . ' is not inside the boundary');
		}
	}

	/**
	 * The value normally does not exist yet, so realpath() on it answers
	 * nothing and a lexical check is all that is left — which one symlink
	 * inside the tree defeats, and the customer can create symlinks. The
	 * resolver is asked about the deepest part that does exist.
	 */
	public function testASymlinkOutOfTheTreeIsCaught()
	{
		$policy = array(
			'root' => '/torrents1/downloads',
			'resolve' => function($path) {
				// stands in for a symlink at /torrents1/downloads/escape
				if(strpos($path, '/torrents1/downloads/escape') === 0)
					return '/var/www/user1' . substr($path, strlen('/torrents1/downloads/escape'));
				return $path;
			},
		);
		$this->assertTrue(!$this->loadInto('/torrents1/downloads/escape/x', $policy),
			'a path that is inside on paper and outside in fact is dropped');
		$this->assertTrue($this->loadInto('/torrents1/downloads/real/x', $policy),
			'and one that resolves where it says still works');
	}

	public function testAResolverThatCannotAnswerIsANo()
	{
		$policy = array(
			'root' => '/torrents1/downloads',
			'resolve' => function($path) { return ''; },
		);
		$this->assertTrue(!$this->loadInto('/torrents1/downloads/x', $policy),
			'an open question about a write target is not a yes');
	}

	public function testNoBoundaryStatedMeansNoBoundaryChecked()
	{
		// The library keeps this compatibility mode for callers that omit a
		// policy. Both shipped endpoints now state a boundary; rpc2.php refuses
		// to start when its configured boundary is empty or implicit root.
		$this->assertTrue($this->loadInto('/anywhere/at/all', null),
			'a caller that states no boundary is not policed here');
	}

	public function testAPolicyWithNoRootRefuses()
	{
		$this->assertTrue(!$this->loadInto('/torrents1/downloads/x', array()),
			'a stated policy that names no root permits nothing, rather than everything');
	}


	public function testMalformedSystemMulticallIsRejected()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.multicall</methodName>'
			. '<params><param><value><string>x</string></value></param></params></methodCall>';
		$this->assertTrue(self::processOnModernDaemon($xml, 'sanitize', true, array('d.custom1.set')) === null, 'system.multicall is rejected');
	}

	public function testEnvCheckRecommendsTheAvailableSimpleXMLFunction()
	{
		$envCheckPath = realpath(__DIR__ . '/../../env_check.php');
		$child = SCGITransportFixture::runPhpChild(array('-d',
			'disable_functions=simplexml_load_string', $envCheckPath));

		$this->assertEquals(0, $child['exitCode'],
			'env_check keeps optional proxy sanitisation a recommendation');
		$this->assertEquals('', $child['stderr'], 'env_check emits no fatal or warning');
		$this->assertTrue(strpos($child['stdout'], 'Recommended:') !== false,
			'Recommended section present');
		$recommendedSection = '';
		if(preg_match('/Recommended:(.*?)(?:Configuration:|$)/s', $child['stdout'], $m)) {
			$recommendedSection = $m[1];
		}
		$this->assertTrue(preg_match('/^  \[WARN\] PHP extension: simplexml\s+'
			. 'XMLRPC proxy sanitisation \(Sonarr\/Radarr raw pass-through\)$/m',
			$recommendedSection) === 1,
			'simplexml must be a recommendation with the exact proxy-sanitisation reason');
	}

	// ---- an escaped comma is part of the value, not a separator ----

	public function testAnEscapedCommaDoesNotSeparateArguments()
	{
		$sent = $this->sanitizeParam('d.custom.set=a\,b', array('d.custom.set'));
		$this->assertTrue(strpos($sent, 'd.custom.set="a,b"') !== false,
			'rtorrent reads a\,b as the one argument a,b; splitting on it invented a second');
	}

	public function testAnEscapedCommaAtTheEndOfAValueIsKept()
	{
		$sent = $this->sanitizeParam('d.custom1.set=a\,', array('d.custom1.set'));
		$this->assertTrue(strpos($sent, 'd.custom1.set="a,"') !== false,
			'the comma is value content, so it survives to the end');
	}

	public function testAnUnescapedCommaStillSeparates()
	{
		$sent = $this->sanitizeParam('d.custom.set=chk-state,7', array('d.custom.set'));
		$this->assertTrue(strpos($sent, 'd.custom.set="chk-state","7"') !== false,
			'a bare comma keeps separating arguments');
	}

	/**
	 * Every other backslash is left where it is. rtorrent would read it as an
	 * escape and turn C:\downloads into C:downloads, but this side re-quotes
	 * the value, so the backslash reaches rtorrent whole -- and clients have
	 * been sending paths and labels through here on that basis.
	 */
	public function testABackslashThatIsNotBeforeACommaIsKept()
	{
		$sent = $this->sanitizeParam('d.custom1.set=C:\downloads\tv', array('d.custom1.set'));
		$this->assertTrue(strpos($sent, 'd.custom1.set="C:\\\\downloads\\\\tv"') !== false,
			'a windows-style path survives, re-escaped on the way out');
	}

	public function testATrailingBackslashIsStillJustAValue()
	{
		$sent = $this->sanitizeParam('d.custom1.set=abc\\', array('d.custom1.set'));
		$this->assertTrue(strpos($sent, 'd.custom1.set="abc\\\\"') !== false,
			'a value ending in a backslash is quoted, not dropped');
	}

	public function testVerboseDotLoadsAreOwnedAndSanitized()
	{
		$methods = array(
			'load.normal', 'load.start', 'load.verbose', 'load.start_verbose',
			'load.raw', 'load.raw_start', 'load.raw_verbose', 'load.raw_start_verbose'
		);
		$torrentB64 = base64_encode("d8:announce11:http://test4:infod6:lengthi1234eee");
		foreach($methods as $method)
		{
			$isRaw = (strpos($method, '.raw') !== false);
			$dataVal = $isRaw
				? '<param><value><base64>' . $torrentB64 . '</base64></value></param>'
				: '<param><value><string>http://example.test/test.torrent</string></value></param>';
			$xml = '<?xml version="1.0"?><methodCall><methodName>' . $method . '</methodName><params>'
				. '<param><value><string></string></value></param>'
				. $dataVal
				. '<param><value><string>d.custom1.set=safe_val</string></value></param>'
				. '</params></methodCall>';
			$d = self::decideOnModernDaemon($xml, 'sanitize', array('d.custom1.set'));
			$this->assertTrue($d['action'] === 'send', $method . ' should be accepted');
			$this->assertTrue($d['trusted'] === true, $method . ' should be trusted');
			$this->assertTrue(strpos($d['payload'], 'd.custom1.set="safe_val"') !== false, $method . ' should have sanitized param');
		}
	}

	public function testExactEvaluatorsAreRejected()
	{
		$evaluators = array('catch', 'branch', 'try', 'and', 'or', 'less', 'greater', 'equal', 'match');
		foreach($evaluators as $ev)
		{
			$xml = '<?xml version="1.0"?><methodCall><methodName>' . $ev . '</methodName><params></params></methodCall>';
			$d = self::decideOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', $ev . ' must be rejected');
			$this->assertTrue($d['trusted'] === false, $ev . ' must not be trusted');
		}
	}

	public function testIfEvaluatorIsNotOverblockedAndNearMissesPass()
	{
		$nearMisses = array('if', 'not', 'compare', 'catch_extra', 'branching', 'trying');
		foreach($nearMisses as $nm)
		{
			$xml = '<?xml version="1.0"?><methodCall><methodName>' . $nm . '</methodName><params></params></methodCall>';
			$d = self::decideOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'send', $nm . ' must not be blocked');
			$this->assertTrue($d['trusted'] === false, $nm . ' must be ordinary untrusted');
		}
	}

	public function testDirectCarriersAreRejected()
	{
		$carriers = array(
			'p.call_target',
			'directory.watch.added',
			'view.filter',
			'view.filter.temp',
			'view.sort_new',
			'view.sort_current',
			'view.event_added',
			'view.event_removed',
		);
		foreach($carriers as $carrier)
		{
			$xml = '<?xml version="1.0"?><methodCall><methodName>' . $carrier . '</methodName><params></params></methodCall>';
			$d = self::decideOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', $carrier . ' must be rejected');
		}
	}

	public function testDirectDirectorySettersAreRejected()
	{
		$setters = self::DIRECTORY_SETTERS;
		foreach($setters as $setter)
		{
			$xml = '<?xml version="1.0"?><methodCall><methodName>' . $setter . '</methodName><params>'
				. '<param><value><string>1234567890123456789012345678901234567890</string></value></param>'
				. '<param><value><string>/tmp</string></value></param>'
				. '</params></methodCall>';
			$d = self::decideOnModernDaemon($xml, 'sanitize', array($setter));
			$this->assertTrue($d['action'] === 'reject', 'direct call to ' . $setter . ' must be rejected');
		}
	}

	public function testUnknownUnderscoreLoadNameRemainsUntrusted()
	{
		$xml = $this->methodCallXml('load_normal', array('http://example.com/test.torrent'));
		$d = self::decideOnModernDaemon($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'send' && !$d['trusted'],
			'an unregistered underscore spelling remains an ordinary unknown method');
		$this->assertEquals($xml, $d['payload'], 'unknown method retains its original bytes');
	}

	public function testDirectMulticallsAdversarialGrammarRejectsWholeCall()
	{
		$multicalls = array(
			'd.multicall',
			'd.multicall2',
			't.multicall',
			'f.multicall',
			'p.multicall',
		);
		foreach($multicalls as $mc)
		{
			$xml = '<?xml version="1.0"?><methodCall><methodName>' . $mc . '</methodName><params>'
				. '<param><value><string></string></value></param>'
				. '<param><value><string>main</string></value></param>'
				. '<param><value><string>execute.capture=/bin/id</string></value></param>'
				. '</params></methodCall>';
			$d = self::decideOnModernDaemon($xml, 'sanitize', array('d.custom1.set'));
			$this->assertTrue($d['action'] === 'reject', $mc . ' with unallowed command must be rejected');
			$this->assertTrue($d['method'] === $mc, 'refusal must name outer method ' . $mc);
		}
	}

	public function testDirectMulticallFilteredFilterSlotRejectsWhenUnsafe()
	{
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall.filtered</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>main</string></value></param>'
			. '<param><value><string>execute.capture=/bin/id</string></value></param>'
			. '<param><value><string>d.custom1.set=safe_val</string></value></param>'
			. '</params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'd.multicall.filtered with unsafe filter must reject outer call');
		$this->assertTrue($d['method'] === 'd.multicall.filtered', 'refusal must name outer d.multicall.filtered');
	}


	public function testSystemMulticallWithAnUntrustedReadMemberRemainsUntrusted()
	{
		$xml = $this->systemMulticallXml(array(array('system.client_version', array())));
		$d = self::decideOnModernDaemon($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'send' && !$d['trusted'],
			'a read method can use the carrier without gaining trust');
		$this->assertTrue($d['method'] === null, 'a forwarded batch names no refused method');
	}

	public function testMalformedXmlOrMissingMethodIsTerminallyRejected()
	{
		$cases = array(
			'not xml at all',
			'',
			'<?xml version="1.0"?><methodCall></methodCall>',
			'<?xml version="1.0"?><methodCall><methodName></methodName></methodCall>',
			'<?xml version="1.0"?><methodCall><methodName>   </methodName></methodCall>'
		);
		foreach($cases as $raw)
		{
			$d = self::decideOnModernDaemon($raw, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'malformed XML must be rejected');
			$this->assertTrue($d['payload'] === '', 'malformed XML rejection payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'malformed XML rejection must be untrusted');
		}
	}

	public function testEmptyMethodNameHasNoRefusedMethodOrEmptyLogSuffix()
	{
		$xml = '<?xml version="1.0"?><methodCall><methodName></methodName></methodCall>';
		$decision = self::decideOnModernDaemon($xml, 'sanitize');
		$this->assertEquals('reject', $decision['action'], 'empty method name is rejected');
		$this->assertEquals(null, $decision['method'], 'empty method name identifies no method');
		$this->assertEquals(array('rejected (invalid XML)'), $decision['log'],
			'empty method name adds no method suffix to the log');
	}

	public function testMalformedOwnedLoadsAreTerminallyRejected()
	{
		$badXmls = array(
			'<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params></params></methodCall>',
			'<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param></params></methodCall>',
			'<?xml version="1.0"?><methodCall><methodName>load.raw_start</methodName><params><param><value><string></string></value></param><param><value><base64>!!!not-valid-base64!!!</base64></value></param></params></methodCall>'
		);
		foreach($badXmls as $xml)
		{
			$d = self::decideOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'malformed load call must be rejected');
			$this->assertTrue($d['payload'] === '', 'malformed load rejection payload must be empty');
		}
	}

	public function testInvalidExactElevatedShapesAreTerminallyRejected()
	{
		$hashCases = array(
			'd.open' => array(
				'<param><value><string>123456789012345678901234567890123456789</string></value></param>', // 39
			),
			'd.start' => array(
				'<param><value><string>123456789012345678901234567890123456789</string></value></param>', // 39
				'<param><value><string>12345678901234567890123456789012345678901</string></value></param>', // 41
				'<param><value><string>123456789012345678901234567890123456789G</string></value></param>', // non-hex
				'<param><value><array><data><value><string>1234567890123456789012345678901234567890</string></value></data></array></value></param>',
				'<param><value><struct><member><name>hash</name><value><string>1234567890123456789012345678901234567890</string></value></member></struct></value></param>',
			),
			'd.stop' => array(
				'<param><value><string>123456789012345678901234567890123456789</string></value></param>', // 39
			),
			'd.custom1.set' => array(
				'<param><value><string>1234567890123456789012345678901234567890</string></value></param>', // missing 2nd
				'<param><value><string>1234567890123456789012345678901234567890</string></value></param><param><value><array><data><value><string>val</string></value></data></array></value></param>',
				'<param><value><string>1234567890123456789012345678901234567890</string></value></param><param><value><string>v1</string></value></param><param><value><string>v2</string></value></param>', // extra 3rd
			),
			'd.custom2.set' => array(
				'<param><value><string>1234567890123456789012345678901234567890</string></value></param>', // missing 2nd
			),
			'd.custom.set' => array(
				'<param><value><string>1234567890123456789012345678901234567890</string></value></param><param><value><string>v1</string></value></param>', // missing 3rd
				'<param><value><string>1234567890123456789012345678901234567890</string></value></param><param><value><string>v1</string></value></param><param><value><string>v2</string></value></param><param><value><string>v3</string></value></param>', // extra 4th
			),
			'd.priority.set' => array(
				'<param><value><string>1234567890123456789012345678901234567890</string></value></param><param><value><string>01</string></value></param>', // leading zero
				'<param><value><string>1234567890123456789012345678901234567890</string></value></param><param><value><string>+5</string></value></param>', // plus sign
				'<param><value><string>1234567890123456789012345678901234567890</string></value></param><param><value><string>-0</string></value></param>', // negative zero
				'<param><value><string>1234567890123456789012345678901234567890</string></value></param><param><value><string>abc</string></value></param>', // non-int
				'<param><value><string>1234567890123456789012345678901234567890</string></value></param><param><value><string> 5 </string></value></param>', // whitespace
			),
			'network.xmlrpc.size_limit.set' => array(
				'<param><value><string>target</string></value></param><param><value><int>1024</int></value></param>', // non-empty target
				'<param><value><string></string></value></param><param><value><string>0</string></value></param>', // zero size
				'<param><value><string></string></value></param><param><value><string>-1</string></value></param>', // negative size
				'<param><value><string></string></value></param><param><value><string>0500</string></value></param>', // leading zero
				'<param><value><string></string></value></param><param><value><string>+500</string></value></param>', // plus sign
				'<param><value><string></string></value></param><param><value><string>bad</string></value></param>', // bad size
			)
		);

		foreach($hashCases as $method => $paramVariants)
		{
			foreach($paramVariants as $pv)
			{
				$xml = '<?xml version="1.0"?><methodCall><methodName>' . $method . '</methodName><params>' . $pv . '</params></methodCall>';
				$d = self::decideOnModernDaemon($xml, 'sanitize');
				$this->assertTrue($d['action'] === 'reject', $method . ' with invalid shape must be rejected');
				$this->assertTrue($d['payload'] === '', 'rejection payload must be empty');
				$this->assertTrue($d['trusted'] === false, 'rejection must be untrusted');
			}
		}
	}

	public function testInvalidModeIsTerminallyRejectedBeforeXmlParsing()
	{
		$d1 = self::decideOnModernDaemon('not xml', 'invalid_mode');
		$this->assertTrue($d1['action'] === 'reject', 'invalid mode must be rejected');
		$this->assertTrue($d1['payload'] === '', 'invalid mode payload must be empty');

		$d2 = self::decideOnModernDaemon('not xml', 'off');
		$this->assertTrue($d2['action'] === 'reject', 'off mode must be rejected');
		$this->assertTrue($d2['payload'] === '', 'off mode payload must be empty');
	}

	public function testDiagnosticsBoundsAndNormalization()
	{
		$longBadMethod = 'method.bad*name/with@punctuation#and;characters!' . str_repeat('X', 200);
		$xml = '<?xml version="1.0"?><methodCall><methodName>' . htmlspecialchars($longBadMethod) . '</methodName><params></params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'method must be rejected');
		$this->assertTrue(strlen($d['method']) <= 96, 'refusal method must be capped at 96 bytes');
		$this->assertTrue(strpos($d['method'], '?') !== false, 'non-whitelisted chars in method must be normalized to ?');

		$cleanMsg = XMLRPCProxy::formatLogMessage("line1\r\nline2\tline3\x00line4");
		$this->assertTrue(strpos($cleanMsg, "\n") === false, 'formatLogMessage must strip newlines');
		$this->assertTrue(strpos($cleanMsg, "\r") === false, 'formatLogMessage must strip CR');
		$this->assertTrue(strpos($cleanMsg, "\x00") === false, 'formatLogMessage must strip NUL');
		$this->assertTrue(strlen($cleanMsg) <= 512, 'formatLogMessage must cap at 512 bytes');
	}

	public function testStoppedShapesAreTerminallyRejected()
	{
		// 1. non-methodCall root carrying load.start
		$xml1 = '<?xml version="1.0"?><bogus><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.com/test.torrent</string></value></param></params></bogus>';
		$d1 = self::decideOnModernDaemon($xml1, 'sanitize');
		$this->assertTrue($d1['action'] === 'reject', 'non-methodCall root must be rejected');
		$this->assertTrue($d1['trusted'] === false, 'non-methodCall root must not be trusted');

		// 2. sibling methodName values
		$xml2 = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><methodName>execute</methodName><params><param><value><string></string></value></param><param><value><string>http://example.com/test.torrent</string></value></param></params></methodCall>';
		$d2 = self::decideOnModernDaemon($xml2, 'sanitize');
		$this->assertTrue($d2['action'] === 'reject', 'sibling methodName elements must be rejected');

		// 3. load.raw data slot is string, not base64
		$xml3 = '<?xml version="1.0"?><methodCall><methodName>load.raw</methodName><params><param><value><string></string></value></param><param><value><string>raw_not_base64_data</string></value></param></params></methodCall>';
		$d3 = self::decideOnModernDaemon($xml3, 'sanitize');
		$this->assertTrue($d3['action'] === 'reject', 'load.raw string data must be rejected');

		// 4. d.multicall.filtered with filter but no result
		$xml4 = '<?xml version="1.0"?><methodCall><methodName>d.multicall.filtered</methodName><params><param><value><string></string></value></param><param><value><string>default</string></value></param><param><value><string>d.is_active=</string></value></param></params></methodCall>';
		$d4 = self::decideOnModernDaemon($xml4, 'sanitize');
		$this->assertTrue($d4['action'] === 'reject', 'd.multicall.filtered without results must be rejected');

		// 5. d.multicall2 with target/view but no result
		$xml5 = '<?xml version="1.0"?><methodCall><methodName>d.multicall2</methodName><params><param><value><string></string></value></param><param><value><string>default</string></value></param></params></methodCall>';
		$d5 = self::decideOnModernDaemon($xml5, 'sanitize');
		$this->assertTrue($d5['action'] === 'reject', 'd.multicall2 without results must be rejected');

		// 6. d.start value containing both string and int children
		$xml6 = '<?xml version="1.0"?><methodCall><methodName>d.start</methodName><params><param><value><string>1234567890123456789012345678901234567890</string><int>1</int></value></param></params></methodCall>';
		$d6 = self::decideOnModernDaemon($xml6, 'sanitize');
		$this->assertTrue($d6['action'] === 'reject', 'd.start with mixed string and int types must be rejected');
	}

	public function testMalformedStructuralEnvelopeAndValuesAreTerminallyRejected()
	{
		// missing <value> in param
		$xmlMissingVal = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param></param></params></methodCall>';
		try {
			$d = self::decideOnModernDaemon($xmlMissingVal, 'sanitize');
			$this->assertTrue(is_array($d) && $d['action'] === 'reject', 'missing value must be rejected');
		} catch (Throwable $e) {
			$this->assertTrue(false, 'missing value crashed with ' . get_class($e) . ': ' . $e->getMessage());
		}

		// duplicate <value> in param
		$xmlDupVal = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value><value><string>http://example.com</string></value></param></params></methodCall>';
		$d = self::decideOnModernDaemon($xmlDupVal, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'duplicate value under param must be rejected');

		// missing <params> on owned method
		$xmlMissingParams = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName></methodCall>';
		$d = self::decideOnModernDaemon($xmlMissingParams, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'missing params on owned method must be rejected');

		// duplicate <params>
		$xmlDupParams = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param></params><params><param><value><string></string></value></param></params></methodCall>';
		$d = self::decideOnModernDaemon($xmlDupParams, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'duplicate params containers must be rejected');

		// unexpected child under <params>
		$xmlUnexpChild = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><extra>bad</extra></params></methodCall>';
		$d = self::decideOnModernDaemon($xmlUnexpChild, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'unexpected child under params must be rejected');

		// duplicate type child in value
		$xmlDupType = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string>a</string><string>b</string></value></param></params></methodCall>';
		$d = self::decideOnModernDaemon($xmlDupType, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'duplicate type children in value must be rejected');

		// unknown type child in value
		$xmlUnknownType = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><bogustype>a</bogustype></value></param></params></methodCall>';
		$d = self::decideOnModernDaemon($xmlUnknownType, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'unknown type in value must be rejected');

		// mixed implicit text and typed child
		$xmlMixedImplicit = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value>implicit<string>typed</string></value></param></params></methodCall>';
		$d = self::decideOnModernDaemon($xmlMixedImplicit, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'mixed implicit text and typed child must be rejected');

		// ordinary method with structural defects must also be rejected by the decoder
		$xmlOrdDupParams = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value><string>a</string></value></param></params><params><param><value><string>b</string></value></param></params></methodCall>';
		$d = self::decideOnModernDaemon($xmlOrdDupParams, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'duplicate params containers on ordinary method must be rejected');

		$xmlOrdUnexpChild = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value><string>a</string></value></param><extra>bad</extra></params></methodCall>';
		$d = self::decideOnModernDaemon($xmlOrdUnexpChild, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'unexpected child under params on ordinary method must be rejected');

		$xmlOrdDupVal = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value><string>a</string></value><value><string>b</string></value></param></params></methodCall>';
		$d = self::decideOnModernDaemon($xmlOrdDupVal, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'duplicate value under param on ordinary method must be rejected');

		$xmlOrdDupType = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value><string>a</string><string>b</string></value></param></params></methodCall>';
		$d = self::decideOnModernDaemon($xmlOrdDupType, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'duplicate type children in value on ordinary method must be rejected');

		$xmlOrdMixedImplicit = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value>implicit<string>typed</string></value></param></params></methodCall>';
		$d = self::decideOnModernDaemon($xmlOrdMixedImplicit, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'mixed implicit text and typed child on ordinary method must be rejected');
	}

	public function testResultlessMulticallsAreTerminallyRejected()
	{
		$multicalls = array(
			'd.multicall' => 2,
			'd.multicall2' => 2,
			'd.multicall.filtered' => 3,
			't.multicall' => 2,
			'f.multicall' => 2,
			'p.multicall' => 2,
		);
		foreach($multicalls as $mc => $minParams)
		{
			$paramsXml = '<param><value><string></string></value></param><param><value><string>main</string></value></param>';
			if($mc === 'd.multicall.filtered')
				$paramsXml .= '<param><value><string>d.is_active=</string></value></param>';
			$xml = '<?xml version="1.0"?><methodCall><methodName>' . $mc . '</methodName><params>' . $paramsXml . '</params></methodCall>';
			$d = self::decideOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', $mc . ' without results must be rejected');
			$this->assertTrue($d['method'] === $mc, 'refusal must name outer ' . $mc);
		}
	}

	public function testSafePlusUnknownMulticallRejectsEntireCall()
	{
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall2</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>main</string></value></param>'
			. '<param><value><string>d.custom1.set=safe_label</string></value></param>'
			. '<param><value><string>d.unknown_non_deny_method=data</string></value></param>'
			. '</params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'safe plus unknown non-deny member must reject entire call');
		$this->assertTrue($d['method'] === 'd.multicall2', 'refusal must name outer method d.multicall2');
		$this->assertTrue($d['payload'] === '', 'refusal payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'refusal must be untrusted');
	}

	// --- C1: Filter Owner Tests ---

	public function testFilteredMulticallRejectsGetterOutsideSetterOnlyFilterPolicy()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall.filtered</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>main</string></value></param>'
			. '<param><value><string>d.is_active=</string></value></param>'
			. '<param><value><string>d.custom1.set=safe_val</string></value></param>'
			. '</params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'a reader outside the setter-only filter policy must be rejected');
		$this->assertTrue($d['method'] === 'd.multicall.filtered', 'refusal must name outer method d.multicall.filtered');
		$this->assertTrue($d['payload'] === '', 'refusal payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'refusal must be untrusted');
		$res = self::processOnModernDaemon($xml, 'sanitize', true, array('d.custom1.set'));
		$this->assertTrue($res === null, 'process must return null on rejected filter');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends on rejected filter');
	}

	public function testFilteredMulticallRejectsDollarCommandFilter()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall.filtered</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>main</string></value></param>'
			. '<param><value><string>$method.insert=fixture</string></value></param>'
			. '<param><value><string>d.custom1.set=safe_val</string></value></param>'
			. '</params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'dollar command filter must be rejected');
		$this->assertTrue($d['method'] === 'd.multicall.filtered', 'refusal must name outer method');
		$this->assertTrue($d['payload'] === '', 'refusal payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'refusal must be untrusted');
		$res = self::processOnModernDaemon($xml, 'sanitize', true, array('d.custom1.set'));
		$this->assertTrue($res === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
	}

	public function testFilteredMulticallRejectsParenthesizedCommandFilter()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall.filtered</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>main</string></value></param>'
			. '<param><value><string>(method.insert,fixture)</string></value></param>'
			. '<param><value><string>d.custom1.set=safe_val</string></value></param>'
			. '</params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'parenthesized command filter must be rejected');
		$this->assertTrue($d['method'] === 'd.multicall.filtered', 'refusal must name outer method');
		$this->assertTrue($d['payload'] === '', 'refusal payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'refusal must be untrusted');
		$res = self::processOnModernDaemon($xml, 'sanitize', true, array('d.custom1.set'));
		$this->assertTrue($res === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
	}

	public function testFilteredMulticallRejectsNestedCommandFilter()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall.filtered</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>main</string></value></param>'
			. '<param><value><string>(branch,(method.insert,fixture))</string></value></param>'
			. '<param><value><string>d.custom1.set=safe_val</string></value></param>'
			. '</params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'nested command filter must be rejected');
		$this->assertTrue($d['method'] === 'd.multicall.filtered', 'refusal must name outer method');
		$this->assertTrue($d['payload'] === '', 'refusal payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'refusal must be untrusted');
		$res = self::processOnModernDaemon($xml, 'sanitize', true, array('d.custom1.set'));
		$this->assertTrue($res === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
	}

	public function testFilteredMulticallRejectsUnclosedQuotedFilter()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall.filtered</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>main</string></value></param>'
			. '<param><value><string>d.custom1.set="unterminated</string></value></param>'
			. '<param><value><string>d.custom1.set=safe_val</string></value></param>'
			. '</params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'unclosed quoted filter must be rejected');
		$this->assertTrue($d['method'] === 'd.multicall.filtered', 'refusal must name outer method');
		$this->assertTrue($d['payload'] === '', 'refusal payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'refusal must be untrusted');
		$res = self::processOnModernDaemon($xml, 'sanitize', true, array('d.custom1.set'));
		$this->assertTrue($res === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
	}

	public function testFilteredMulticallRejectsMalformedEscapedFilter()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall.filtered</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>main</string></value></param>'
			. '<param><value><string>d.custom1.set="val\\</string></value></param>'
			. '<param><value><string>d.custom1.set=safe_val</string></value></param>'
			. '</params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'malformed escaped filter must be rejected');
		$this->assertTrue($d['method'] === 'd.multicall.filtered', 'refusal must name outer method');
		$this->assertTrue($d['payload'] === '', 'refusal payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'refusal must be untrusted');
		$res = self::processOnModernDaemon($xml, 'sanitize', true, array('d.custom1.set'));
		$this->assertTrue($res === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
	}

	public function testFilteredMulticallCanonicallyRebuildsSetterFilter()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall.filtered</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>main</string></value></param>'
			. '<param><value><string>d.custom1.set=filter value</string></value></param>'
			. '<param><value><string>d.custom.set=result_val</string></value></param>'
			. '</params></methodCall>';
		$safeParams = array('d.custom1.set', 'd.custom.set');
		$d = self::decideOnModernDaemon($xml, 'sanitize', $safeParams);
		$this->assertTrue($d['action'] === 'send', 'allowlisted setter filter must be admitted for send');
		$this->assertTrue($d['trusted'] === true, 'setter-only filter multicall must be trusted');
		$this->assertTrue(strpos($d['payload'], 'd.custom1.set="filter value"') !== false,
			'filter must become canonical rebuilt form');
		$this->assertTrue(strpos($d['payload'], 'd.custom1.set=filter value') === false,
			'original filter bytes must be absent');
		$this->assertTrue(strpos($d['payload'], 'd.custom.set="result_val"') !== false,
			'result slot must become canonical rebuilt form');
		$res = self::processOnModernDaemon($xml, 'sanitize', true, $safeParams);
		$this->assertEquals(1, rXMLRPCRequest::$sent, 'exactly one send');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === true, 'sent as trusted');
	}

	// --- C2: Structural Decoder Tests ---

	public function testStructuralDecoderRejectsTextUnderMethodCall()
	{
		$this->resetMocks();
		$p = '<param><value><string></string></value></param><param><value><string>http://example.com/test.torrent</string></value></param>';
		$inputs = array(
			'<?xml version="1.0"?><methodCall>text_before<methodName>load.start</methodName><params>' . $p . '</params></methodCall>',
			'<?xml version="1.0"?><methodCall><methodName>load.start</methodName>text_between<params>' . $p . '</params></methodCall>',
			'<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params>' . $p . '</params>text_after</methodCall>',
		);
		foreach($inputs as $xml)
		{
			$d = self::decideOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'text directly below methodCall must be rejected');
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = self::processOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($res === null, 'process must return null');
			$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
		}
	}

	public function testStructuralDecoderRejectsTextUnderParams()
	{
		$this->resetMocks();
		$p1 = '<param><value><string></string></value></param>';
		$p2 = '<param><value><string>http://example.com/test.torrent</string></value></param>';
		$inputs = array(
			'<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params>text_before' . $p1 . $p2 . '</params></methodCall>',
			'<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params>' . $p1 . 'text_between' . $p2 . '</params></methodCall>',
			'<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params>' . $p1 . $p2 . 'text_after</params></methodCall>',
		);
		foreach($inputs as $xml)
		{
			$d = self::decideOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'text directly below params must be rejected');
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = self::processOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($res === null, 'process must return null');
			$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
		}
	}

	public function testStructuralDecoderRejectsTextUnderParam()
	{
		$this->resetMocks();
		$p2 = '<param><value><string>http://example.com/test.torrent</string></value></param>';
		$inputs = array(
			'<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param>text_before<value><string></string></value></param>' . $p2 . '</params></methodCall>',
			'<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value>text_after</param>' . $p2 . '</params></methodCall>',
		);
		foreach($inputs as $xml)
		{
			$d = self::decideOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'text directly below param must be rejected');
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = self::processOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($res === null, 'process must return null');
			$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
		}
	}

	public function testStructuralDecoderRejectsMixedValueContent()
	{
		$this->resetMocks();
		$inputs = array(
			'<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value>text_before<string>test</string></value></param></params></methodCall>',
			'<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string>test</string>text_after</value></param></params></methodCall>',
		);
		foreach($inputs as $xml)
		{
			$d = self::decideOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'mixed value content must be rejected');
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = self::processOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($res === null, 'process must return null');
			$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
		}
	}

	public function testStructuralDecoderRejectsChildrenInsideScalarTypes()
	{
		$this->resetMocks();
		$types = array('string', 'base64', 'int', 'i4', 'i8');
		foreach($types as $tag)
		{
			$xml = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><'
				. $tag . '>http://example.com/test.torrent<bogus>ignored</bogus></' . $tag . '></value></param></params></methodCall>';
			$d = self::decideOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'child inside scalar type <' . $tag . '> must be rejected');
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = self::processOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($res === null, 'process must return null');
			$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
		}
	}

	public function testStructuralDecoderRejectsAttributes()
	{
		$this->resetMocks();
		$inputs = array(
			'<methodCall attr="1"><methodName>load.start</methodName><params></params></methodCall>',
			'<methodCall><methodName attr="1">load.start</methodName><params></params></methodCall>',
			'<methodCall><methodName>load.start</methodName><params attr="1"></params></methodCall>',
			'<methodCall><methodName>load.start</methodName><params><param attr="1"><value><string></string></value></param></params></methodCall>',
			'<methodCall><methodName>load.start</methodName><params><param><value attr="1"><string></string></value></param></params></methodCall>',
			'<methodCall><methodName>load.start</methodName><params><param><value><string attr="1"></string></value></param></params></methodCall>',
		);
		foreach($inputs as $body)
		{
			$xml = '<?xml version="1.0"?>' . $body;
			$d = self::decideOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'attributes on XMLRPC elements must be rejected: ' . $body);
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = self::processOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($res === null, 'process must return null');
			$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
		}
	}

	public function testStructuralDecoderRejectsNamespaces()
	{
		$this->resetMocks();
		$inputs = array(
			'<methodCall xmlns="http://example.com"><methodName>system.client_version</methodName><params></params></methodCall>',
			'<ns:methodCall xmlns:ns="http://example.com"><ns:methodName>system.client_version</ns:methodName><params></params></ns:methodCall>',
			'<methodCall><methodName>system.client_version</methodName><params xmlns="http://example.com"></params></methodCall>',
			'<methodCall><methodName>system.client_version</methodName><params><param><value xmlns="http://example.com"><string></string></value></param></params></methodCall>',
			'<methodCall><methodName>system.client_version</methodName><params><param><value><string xmlns="http://example.com"></string></value></param></params></methodCall>',
		);
		foreach($inputs as $body)
		{
			$xml = '<?xml version="1.0"?>' . $body;
			$d = self::decideOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'namespaces must be rejected: ' . $body);
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = self::processOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($res === null, 'process must return null');
			$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
		}
	}

	public function testStructuralDecoderRejectsDuplicateNodes()
	{
		$this->resetMocks();
		$inputs = array(
			'<methodCall><methodName>system.client_version</methodName><methodName>d.start</methodName><params></params></methodCall>',
			'<methodCall><methodName>system.client_version</methodName><params></params><params></params></methodCall>',
			'<methodCall><methodName>system.client_version</methodName><params><param><value><string></string></value><value><string></string></value></param></params></methodCall>',
			'<methodCall><methodName>system.client_version</methodName><params><param><value><string>a</string><string>b</string></value></param></params></methodCall>',
		);
		foreach($inputs as $body)
		{
			$xml = '<?xml version="1.0"?>' . $body;
			$d = self::decideOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'duplicate nodes must be rejected: ' . $body);
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = self::processOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($res === null, 'process must return null');
			$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
		}
	}

	public function testStructuralDecoderRejectsMissingRequiredNodes()
	{
		$this->resetMocks();
		$inputs = array(
			'<methodCall><params></params></methodCall>', // missing methodName
			'<methodCall><methodName>system.client_version</methodName><params><param></param></params></methodCall>', // missing value
			'<methodCall><methodName>system.client_version</methodName><params><param><value><array></array></value></param></params></methodCall>', // array missing data
			'<methodCall><methodName>system.client_version</methodName><params><param><value><struct><member><value><string>v</string></value></member></struct></value></param></params></methodCall>', // struct member missing name
			'<methodCall><methodName>system.client_version</methodName><params><param><value><struct><member><name>k</name></member></struct></value></param></params></methodCall>', // struct member missing value
		);
		foreach($inputs as $body)
		{
			$xml = '<?xml version="1.0"?>' . $body;
			$d = self::decideOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'missing required nodes must be rejected: ' . $body);
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = self::processOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($res === null, 'process must return null');
			$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
		}
	}

	public function testStructuralDecoderRejectsUnknownChildren()
	{
		$this->resetMocks();
		$inputs = array(
			'<methodCall><extra>bad</extra><methodName>system.client_version</methodName><params></params></methodCall>',
			'<methodCall><methodName>system.client_version</methodName><extra>bad</extra><params></params></methodCall>',
			'<methodCall><methodName>system.client_version</methodName><params><extra>bad</extra><param><value><string></string></value></param></params></methodCall>',
			'<methodCall><methodName>system.client_version</methodName><params><param><extra>bad</extra><value><string></string></value></param></params></methodCall>',
			'<methodCall><methodName>system.client_version</methodName><params><param><value><extra>bad</extra></value></param></params></methodCall>',
		);
		foreach($inputs as $body)
		{
			$xml = '<?xml version="1.0"?>' . $body;
			$d = self::decideOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'unknown child elements must be rejected: ' . $body);
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = self::processOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($res === null, 'process must return null');
			$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
		}
	}

	public function testStructuralDecoderRejectsMalformedArrays()
	{
		$this->resetMocks();
		$inputs = array(
			'<methodCall><methodName>system.client_version</methodName><params><param><value><array><data></data><data></data></array></value></param></params></methodCall>',
			'<methodCall><methodName>system.client_version</methodName><params><param><value><array><data><unknown>x</unknown></data></array></value></param></params></methodCall>',
			'<methodCall><methodName>system.client_version</methodName><params><param><value><array>unconsumed_text<data></data></array></value></param></params></methodCall>',
		);
		foreach($inputs as $body)
		{
			$xml = '<?xml version="1.0"?>' . $body;
			$d = self::decideOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'malformed array must be rejected: ' . $body);
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = self::processOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($res === null, 'process must return null');
			$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
		}
	}

	public function testStructuralDecoderRejectsMalformedStructs()
	{
		$this->resetMocks();
		$inputs = array(
			'<methodCall><methodName>system.client_version</methodName><params><param><value><struct><member><name>k</name><value><string>v</string></value></member><extra>bad</extra></struct></value></param></params></methodCall>',
			'<methodCall><methodName>system.client_version</methodName><params><param><value><struct><member><name>k1</name><name>k2</name><value><string>v</string></value></member></struct></value></param></params></methodCall>',
			'<methodCall><methodName>system.client_version</methodName><params><param><value><struct><member><name>k</name><value><string>v1</string></value><value><string>v2</string></value></member></struct></value></param></params></methodCall>',
			'<methodCall><methodName>system.client_version</methodName><params><param><value><struct><member><unknown>bad</unknown></member></struct></value></param></params></methodCall>',
			'<methodCall><methodName>system.client_version</methodName><params><param><value><struct>unconsumed_text<member><name>k</name><value><string>v</string></value></member></struct></value></param></params></methodCall>',
		);
		foreach($inputs as $body)
		{
			$xml = '<?xml version="1.0"?>' . $body;
			$d = self::decideOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'malformed struct must be rejected: ' . $body);
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = self::processOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($res === null, 'process must return null');
			$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
		}
	}

	public function testStructuralDecoderRejectsNestedElementInsideHash()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.start</methodName><params><param><value>'
			. '<string>0123456789abcdef0123456789abcdef01234567<bogus>ignored</bogus></string>'
			. '</value></param></params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'nested element inside hash must be rejected');
		$this->assertTrue($d['payload'] === '', 'payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'trusted must be false');
		$res = self::processOnModernDaemon($xml, 'sanitize');
		$this->assertTrue($res === null, 'process must return null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
	}

	public function testStructuralDecoderRejectsNestedElementInsideRawLoadCommand()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.raw_start</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><base64>ZGF0YQ==</base64></value></param>'
			. '<param><value><string>d.custom1.set=safe<bogus>ignored</bogus></string></value></param>'
			. '</params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'nested element inside load.raw_start trailing command must be rejected');
		$this->assertTrue($d['payload'] === '', 'payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'trusted must be false');
		$res = self::processOnModernDaemon($xml, 'sanitize', true, array('d.custom1.set'));
		$this->assertTrue($res === null, 'process must return null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
	}

	public function testStructuralDecoderRejectsMalformedOrdinaryMethod()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params attr="1"></params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'malformed ordinary method must be rejected and never enter untrusted fallback');
		$this->assertTrue($d['payload'] === '', 'payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'trusted must be false');
		$res = self::processOnModernDaemon($xml, 'sanitize');
		$this->assertTrue($res === null, 'process must return null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
	}

	public function testStructuralDecoderPreservesValidRecursiveOrdinaryValues()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value>'
			. '<array><data><value><struct><member><name>key</name><value><string>val</string></value></member></struct></value></data></array>'
			. '</value></param></params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'send', 'valid recursive structure on ordinary method must be accepted');
		$this->assertTrue($d['trusted'] === false, 'ordinary method must be untrusted');
		$this->assertEquals($xml, $d['payload'], 'original payload preserved');
		$res = self::processOnModernDaemon($xml, 'sanitize');
		$this->assertEquals(1, rXMLRPCRequest::$sent, 'one send');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === false, 'sent as untrusted');
	}

	// --- I1: URI Encoding Tests ---

	public function testUriLoadRejectsBase64DecodedInvalidUtf8WithoutSending()
	{
		$this->resetMocks();
		// 1. Invalid UTF-8 bytes (\xFF)
		$invalidUtf8Bytes = "http://example.test/x\xFF";
		$b64Invalid = base64_encode($invalidUtf8Bytes);
		$xmlInvalid = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><base64>' . $b64Invalid . '</base64></value></param>'
			. '</params></methodCall>';
		$d1 = self::decideOnModernDaemon($xmlInvalid, 'sanitize');
		$this->assertTrue($d1['action'] === 'reject', 'invalid UTF-8 URI must be rejected');
		$this->assertTrue($d1['method'] === 'load.start', 'refusal must name load.start');
		$this->assertTrue($d1['payload'] === '', 'payload must be empty');
		$this->assertTrue($d1['trusted'] === false, 'trusted must be false');
		$res1 = self::processOnModernDaemon($xmlInvalid, 'sanitize');
		$this->assertTrue($res1 === null, 'process must return null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends on invalid UTF-8 URI');

		// 2. XML 1.0 forbidden control byte (\x00, \x0b)
		$ctrlBytes = "http://example.test/x\x0b";
		$b64Ctrl = base64_encode($ctrlBytes);
		$xmlCtrl = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><base64>' . $b64Ctrl . '</base64></value></param>'
			. '</params></methodCall>';
		$d2 = self::decideOnModernDaemon($xmlCtrl, 'sanitize');
		$this->assertTrue($d2['action'] === 'reject', 'XML forbidden control byte URI must be rejected');
		$this->assertTrue($d2['method'] === 'load.start', 'refusal must name load.start');
		$this->assertTrue($d2['payload'] === '', 'payload must be empty');
		$this->assertTrue($d2['trusted'] === false, 'trusted must be false');
		$res2 = self::processOnModernDaemon($xmlCtrl, 'sanitize');
		$this->assertTrue($res2 === null, 'process must return null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends on control byte URI');

		// 3. Valid UTF-8 URI
		$validUtf8Uri = "http://example.test/тест";
		$b64Valid = base64_encode($validUtf8Uri);
		$xmlValid = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><base64>' . $b64Valid . '</base64></value></param>'
			. '</params></methodCall>';
		$d3 = self::decideOnModernDaemon($xmlValid, 'sanitize');
		$this->assertTrue($d3['action'] === 'send', 'valid UTF-8 URI must be accepted');
		$this->assertTrue($d3['trusted'] === true, 'valid load.start must be trusted');
		$this->assertTrue(strpos($d3['payload'], htmlspecialchars($validUtf8Uri, ENT_NOQUOTES, 'UTF-8')) !== false,
			'canonical string re-emission must contain valid URI');
		$res3 = self::processOnModernDaemon($xmlValid, 'sanitize');
		$this->assertEquals(1, rXMLRPCRequest::$sent, 'one send on valid URI');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === true, 'sent as trusted');
	}

	public function testUriLoadRejectsXmlForbiddenUnicodeBeforeTransport()
	{
		foreach(array('load.normal', 'load.start', 'load.verbose', 'load.start_verbose') as $method)
		{
			foreach(array("\xef\xbf\xbe", "\xef\xbf\xbf") as $forbidden)
			{
				$this->resetMocks();
				$xml = '<methodCall><methodName>'.$method.'</methodName><params>'
					. '<param><value><string></string></value></param>'
					. '<param><value><base64>'.base64_encode('https://example.test/'.$forbidden)
					. '</base64></value></param></params></methodCall>';
				$decision = self::decideOnModernDaemon($xml);
				$this->assertTrue($decision['action'] === 'reject', $method.': XML-forbidden Unicode is rejected');
				$this->assertTrue($decision['method'] === $method, 'refusal retains the outer URI load method');
				$this->assertTrue($decision['payload'] === '', 'no malformed canonical XML is emitted');
				$this->assertTrue($decision['trusted'] === false, 'rejected input is never trusted');
				$this->assertTrue(self::processOnModernDaemon($xml) === null, 'rejection is terminal');
				$this->assertTrue(rXMLRPCRequest::$sent === 0, 'XML-forbidden URI never reaches transport');
			}
		}
	}

	public function testUriLoadPreservesXmlAllowedUnicodeAtTheForbiddenBoundary()
	{
		// XML 1.0 excludes U+FFFE/U+FFFF, not the entire supplementary range.
		foreach(array('load.normal', 'load.start', 'load.verbose', 'load.start_verbose') as $method)
		{
			foreach(array("\xef\xbf\xbd", "\xf0\x90\x80\x80", "\xf4\x8f\xbf\xbf") as $allowed)
			{
				$this->resetMocks();
				$uri = 'https://example.test/'.$allowed;
				$xml = '<methodCall><methodName>'.$method.'</methodName><params>'
					. '<param><value><string></string></value></param>'
					. '<param><value><base64>'.base64_encode($uri)
					. '</base64></value></param></params></methodCall>';
				self::processOnModernDaemon($xml);
				$this->assertTrue(rXMLRPCRequest::$sent === 1, 'XML-allowed Unicode URI is sent exactly once');
				$this->assertTrue(rXMLRPCRequest::$lastTrusted === true, 'valid URI load remains trusted');
				$parsed = @simplexml_load_string(rXMLRPCRequest::$lastPayload);
				$this->assertTrue($parsed !== false, 'canonical payload is valid XML');
				$this->assertTrue($parsed !== false && (string)$parsed->params->param[1]->value->string === $uri,
					'allowed URI code points survive byte for byte');
			}
		}
	}

	public function testRawMetainfoDoesNotApplyTheUriUnicodeRestriction()
	{
		$bytes = "\x00\xff\xef\xbf\xbe\xef\xbf\xbf";
		foreach(array('load.raw', 'load.raw_start', 'load.raw_verbose', 'load.raw_start_verbose') as $method)
		{
			$this->resetMocks();
			$xml = '<methodCall><methodName>'.$method.'</methodName><params>'
				. '<param><value><string></string></value></param>'
				. '<param><value><base64>'.base64_encode($bytes)
				. '</base64></value></param></params></methodCall>';
			self::processOnModernDaemon($xml);
			$this->assertTrue(rXMLRPCRequest::$sent === 1, 'raw metainfo is not interpreted as URI text');
			$this->assertTrue(rXMLRPCRequest::$lastTrusted === true, 'raw load remains trusted');
			$parsed = @simplexml_load_string(rXMLRPCRequest::$lastPayload);
			$this->assertTrue($parsed !== false && base64_decode((string)$parsed->params->param[1]->value->base64, true) === $bytes,
				'arbitrary raw bytes remain exact base64 data');
		}
	}

	// --- I2: Outer Identity Preservation Tests ---

	public function testOuterIdentitySurvivesMissingValue()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall2</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param></param>'
			. '</params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'must be rejected');
		$this->assertTrue($d['method'] === 'd.multicall2', 'rejection must name outer method d.multicall2');
		$this->assertTrue(in_array('rejected (not allowed on this connection): d.multicall2', $d['log'], true)
			|| in_array('rejected (malformed XML envelope or structure): d.multicall2', $d['log'], true),
			'log must name normalized outer d.multicall2');
	}

	public function testOuterIdentitySurvivesMixedTypeChildren()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall2</methodName><params>'
			. '<param><value><string>s</string><int>1</int></value></param>'
			. '</params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'must be rejected');
		$this->assertTrue($d['method'] === 'd.multicall2', 'rejection must name outer method d.multicall2');
	}

	public function testOuterIdentitySurvivesMalformedArray()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall2</methodName><params>'
			. '<param><value><array><data><unknown>x</unknown></data></array></value></param>'
			. '</params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'must be rejected');
		$this->assertTrue($d['method'] === 'd.multicall2', 'rejection must name outer method d.multicall2');
	}

	public function testOuterIdentitySurvivesMalformedResultSlot()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall2</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>main</string></value></param>'
			. '<param><value><string>d.custom1.set=safe<bogus>x</bogus></string></value></param>'
			. '</params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'must be rejected');
		$this->assertTrue($d['method'] === 'd.multicall2', 'rejection must name outer method d.multicall2');
	}

	public function testOuterIdentityRemainsNullBeforeAValidMethodIsKnown()
	{
		$this->resetMocks();
		$inputs = array(
			'not xml at all',
			'<?xml version="1.0"?><methodCall><params></params></methodCall>',
			'<?xml version="1.0"?><methodCall><methodName>m1</methodName><methodName>m2</methodName><params></params></methodCall>',
			'<?xml version="1.0"?><methodCall><nested><methodName>nested</methodName></nested><params></params></methodCall>',
			'<?xml version="1.0"?><methodCall><methodName><bogus>x</bogus></methodName><params></params></methodCall>',
		);
		foreach($inputs as $xml)
		{
			$d = self::decideOnModernDaemon($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'must be rejected');
			$this->assertTrue($d['method'] === null, 'method must remain null when no valid method is established');
		}
	}

	// --- I4: Surface Gaps & Preserved Matrix Tests ---

	public function testLegacyLoadRawStartIsDeniedBeforeTransport()
	{
		$this->resetMocks();
		$xml = $this->methodCallXml('load_raw_start', array('', 'torrent_data'));
		$d = self::decideOnModernDaemon($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'legacy raw load alias is denied locally');
		self::processOnModernDaemon($xml, 'sanitize');
		$this->assertTrue(rXMLRPCRequest::$sent === 0, 'legacy raw load alias never reaches transport');
	}

	public function testCatchExtraIsOrdinaryNotRefused()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>catch.extra</methodName><params></params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'send', 'catch.extra must not be refused');
		$this->assertTrue($d['trusted'] === false, 'catch.extra must be untrusted');
	}

	public function testDirectoryWatchReadyIsDirectlyRefused()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>directory.watch.ready</methodName><params></params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'directory.watch.ready must be directly refused');
		$this->assertTrue($d['method'] === 'directory.watch.ready', 'refusal names directory.watch.ready');
	}

	public function testDirectoryWatchfulIsOrdinaryNotRefused()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>directory.watchful</methodName><params></params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'send', 'directory.watchful must not be refused');
		$this->assertTrue($d['trusted'] === false, 'directory.watchful must be untrusted');
	}

	public function testViewFilterOnIsOrdinaryNotRefused()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>view.filter_on</methodName><params></params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'send', 'view.filter_on must not be refused');
		$this->assertTrue($d['trusted'] === false, 'view.filter_on must be untrusted');
	}

	public function testViewSortIsOrdinaryNotRefused()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>view.sort</methodName><params></params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'send', 'view.sort must not be refused');
		$this->assertTrue($d['trusted'] === false, 'view.sort must be untrusted');
	}

	public function testViewSetIsOrdinaryNotRefused()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>view.set</methodName><params></params></methodCall>';
		$d = self::decideOnModernDaemon($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'send', 'view.set must not be refused');
		$this->assertTrue($d['trusted'] === false, 'view.set must be untrusted');
	}

	public function testDirectMulticallFamiliesGrammarMatrix()
	{
		$this->resetMocks();
		$families = array('d.multicall', 'd.multicall2', 'd.multicall.filtered', 't.multicall', 'f.multicall', 'p.multicall');
		$safeParams = array('d.custom1.set', 'd.custom.set', 't.url');

		foreach($families as $family)
		{
			$isFiltered = ($family === 'd.multicall.filtered');
			$prefixParams = '<param><value><string></string></value></param><param><value><string>main</string></value></param>';
			if($isFiltered)
				$prefixParams .= '<param><value><string>d.custom.set=filter_val</string></value></param>';

			// 1. dollar argument
			$xmlDollar = '<?xml version="1.0"?><methodCall><methodName>' . $family . '</methodName><params>'
				. $prefixParams . '<param><value><string>d.custom1.set=$bad</string></value></param></params></methodCall>';
			$d = self::decideOnModernDaemon($xmlDollar, 'sanitize', $safeParams);
			$this->assertTrue($d['action'] === 'reject', $family . ' with dollar argument must reject outer call');
			$this->assertTrue($d['method'] === $family, $family . ' dollar refusal names outer method');

			// 2. parenthesized command
			$xmlParen = '<?xml version="1.0"?><methodCall><methodName>' . $family . '</methodName><params>'
				. $prefixParams . '<param><value><string>(d.custom1.set,val)</string></value></param></params></methodCall>';
			$d = self::decideOnModernDaemon($xmlParen, 'sanitize', $safeParams);
			$this->assertTrue($d['action'] === 'reject', $family . ' with parenthesized command must reject outer call');

			// 3. nested-parenthesis command
			$xmlNested = '<?xml version="1.0"?><methodCall><methodName>' . $family . '</methodName><params>'
				. $prefixParams . '<param><value><string>(branch,(d.custom1.set,val))</string></value></param></params></methodCall>';
			$d = self::decideOnModernDaemon($xmlNested, 'sanitize', $safeParams);
			$this->assertTrue($d['action'] === 'reject', $family . ' with nested parenthesis must reject outer call');

			// 4. unclosed quote
			$xmlUnclosed = '<?xml version="1.0"?><methodCall><methodName>' . $family . '</methodName><params>'
				. $prefixParams . '<param><value><string>d.custom1.set="val</string></value></param></params></methodCall>';
			$d = self::decideOnModernDaemon($xmlUnclosed, 'sanitize', $safeParams);
			$this->assertTrue($d['action'] === 'reject', $family . ' with unclosed quote must reject outer call');

			// 5. malformed escape
			$xmlEscape = '<?xml version="1.0"?><methodCall><methodName>' . $family . '</methodName><params>'
				. $prefixParams . '<param><value><string>d.custom1.set="val\\</string></value></param></params></methodCall>';
			$d = self::decideOnModernDaemon($xmlEscape, 'sanitize', $safeParams);
			$this->assertTrue($d['action'] === 'reject', $family . ' with malformed escape must reject outer call');

			// 6. unknown command
			$xmlUnknown = '<?xml version="1.0"?><methodCall><methodName>' . $family . '</methodName><params>'
				. $prefixParams . '<param><value><string>unknown.command=val</string></value></param></params></methodCall>';
			$d = self::decideOnModernDaemon($xmlUnknown, 'sanitize', $safeParams);
			$this->assertTrue($d['action'] === 'reject', $family . ' with unknown command must reject outer call');

			// 7. mixed safe and unsafe
			$xmlMixed = '<?xml version="1.0"?><methodCall><methodName>' . $family . '</methodName><params>'
				. $prefixParams . '<param><value><string>d.custom1.set=safe</string></value></param>'
				. '<param><value><string>unknown.command=bad</string></value></param></params></methodCall>';
			$d = self::decideOnModernDaemon($xmlMixed, 'sanitize', $safeParams);
			$this->assertTrue($d['action'] === 'reject', $family . ' with mixed commands must reject outer call');

			// 8. valid canonical slots
			$xmlValid = '<?xml version="1.0"?><methodCall><methodName>' . $family . '</methodName><params>'
				. $prefixParams . '<param><value><string>d.custom1.set=canonical_val</string></value></param></params></methodCall>';
			$d = self::decideOnModernDaemon($xmlValid, 'sanitize', $safeParams);
			$this->assertTrue($d['action'] === 'send', $family . ' with valid canonical slot must be admitted');
			$this->assertTrue($d['trusted'] === true, $family . ' must be trusted');
			$this->assertTrue(strpos($d['payload'], 'd.custom1.set="canonical_val"') !== false,
				$family . ' payload must be canonically rebuilt');
		}
	}

	public function testFixtureSourceHasNoDuplicateKeys()
	{
		if(!function_exists('token_get_all'))
		{
			$this->assertTrue(false, 'tokenizer extension is required for source-aware duplicate key detection');
			return;
		}

		$fixturePath = __DIR__ . '/XMLRPCProxyContractFixture.php';
		$source = file_get_contents($fixturePath);
		$tokens = token_get_all($source);

		$inCases = false;
		$arrayDepth = 0;
		$sourceKeys = array();
		$dynamicDetected = false;
		$state = 0; // 0: expecting key, 1: expecting =>, 2: in value
		$count = count($tokens);

		for($i = 0; $i < $count; $i++)
		{
			$tok = $tokens[$i];
			if(!$inCases)
			{
				if(is_array($tok) && ($tok[0] === T_VARIABLE) && ($tok[1] === '$cases'))
				{
					while(++$i < $count)
					{
						if($tokens[$i] === '(')
						{
							$inCases = true;
							$arrayDepth = 1;
							$state = 0;
							break;
						}
					}
				}
				continue;
			}

			if($tokens[$i] === '(')
			{
				$arrayDepth++;
				continue;
			}
			elseif($tokens[$i] === ')')
			{
				$arrayDepth--;
				if($arrayDepth === 0)
					break;
				continue;
			}

			if($arrayDepth === 1)
			{
				if(is_array($tok) && in_array($tok[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true))
					continue;

				if($state === 0)
				{
					if($tokens[$i] === ',')
						continue;
					if(is_array($tok) && ($tok[0] === T_CONSTANT_ENCAPSED_STRING))
					{
						$strVal = eval('return ' . $tok[1] . ';');
						$sourceKeys[] = $strVal;
						$state = 1;
					}
					else
					{
						$dynamicDetected = true;
						break;
					}
				}
				elseif($state === 1)
				{
					if(is_array($tok) && ($tok[0] === T_DOUBLE_ARROW))
					{
						$state = 2;
					}
					else
					{
						$dynamicDetected = true;
						break;
					}
				}
				elseif($state === 2)
				{
					if($tokens[$i] === ',')
						$state = 0;
				}
			}
		}

		$this->assertTrue(!$dynamicDetected, 'fixture source must contain no dynamic or unrecognized top-level registrations');
		$this->assertTrue(count($sourceKeys) > 0, 'source keys must not be empty');
		$this->assertEquals(73, count($sourceKeys), 'source must contain exactly 73 top-level literal registrations');
		$this->assertEquals(count($sourceKeys), count(array_unique($sourceKeys)), 'source keys must have no duplicates before array overwrite');

		$runtimeFixture = require($fixturePath);
		$runtimeKeys = array_keys($runtimeFixture);

		$this->assertEquals(count($sourceKeys), count($runtimeKeys), 'source registration count must equal runtime array_keys count');
		$this->assertEquals(73, count($runtimeKeys), 'exact 73-key runtime set must remain unchanged');
		$this->assertEquals($sourceKeys, $runtimeKeys, 'source key order and values must match runtime keys exactly');
	}

	// ---- Correction A: Strict Structural XML Validation ----

	public function testStructuralDecoderRejectsParamsBeforeMethodName()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><params></params><methodName>system.client_version</methodName></methodCall>';
		$res = self::decideOnModernDaemon($xml);
		$error = isset($res['error']) ? $res['error'] : null;
		$this->assertEquals('reject', $res['action'], 'reversed methodCall children rejected');
		$this->assertEquals('rejected (invalid XML)', $error, 'error is rejected (invalid XML)');
		$this->assertTrue($res['method'] === null, 'method remains null');
		$this->assertTrue(self::processOnModernDaemon($xml) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');
		$this->assertTrue(rXMLRPCRequest::$lastPayload === null, 'no payload forwarded');
	}

	public function testStructuralDecoderRejectsValueBeforeNameInStructMember()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value><struct><member><value><string>foo</string></value><name>k</name></member></struct></value></param></params></methodCall>';
		$res = self::decideOnModernDaemon($xml);
		$error = isset($res['error']) ? $res['error'] : null;
		$this->assertEquals('reject', $res['action'], 'reversed struct member children rejected');
		$this->assertEquals('rejected (malformed XML envelope or structure)', $error, 'error is malformed envelope');
		$this->assertEquals('system.client_version', $res['method'], 'outer method retained');
		$this->assertTrue(self::processOnModernDaemon($xml) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');
		$this->assertTrue(rXMLRPCRequest::$lastPayload === null, 'no payload forwarded');
	}

	public function testStructuralDecoderRejectsCommentInsideMethodName()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system<!-- comment -->.client_version</methodName></methodCall>';
		$res = self::decideOnModernDaemon($xml);
		$error = isset($res['error']) ? $res['error'] : null;
		$this->assertEquals('reject', $res['action'], 'comment inside methodName rejected');
		$this->assertEquals('rejected (invalid XML)', $error, 'error is rejected (invalid XML)');
		$this->assertTrue(self::processOnModernDaemon($xml) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');
		$this->assertTrue(rXMLRPCRequest::$lastPayload === null, 'no payload forwarded');
	}

	public function testStructuralDecoderRejectsWhitespaceInsideMethodName()
	{
		$this->resetMocks();
		$xml1 = '<?xml version="1.0"?><methodCall><methodName> system.client_version </methodName></methodCall>';
		$res1 = self::decideOnModernDaemon($xml1);
		$error1 = isset($res1['error']) ? $res1['error'] : null;
		$this->assertEquals('reject', $res1['action'], 'leading/trailing whitespace inside methodName rejected');
		$this->assertEquals('rejected (invalid XML)', $error1, 'error is rejected (invalid XML)');
		$this->assertTrue(self::processOnModernDaemon($xml1) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');

		$xml2 = "<?xml version=\"1.0\"?><methodCall><methodName>\nsystem.client_version\n</methodName></methodCall>";
		$res2 = self::decideOnModernDaemon($xml2);
		$error2 = isset($res2['error']) ? $res2['error'] : null;
		$this->assertEquals('reject', $res2['action'], 'newlines inside methodName rejected');
		$this->assertEquals('rejected (invalid XML)', $error2, 'error is rejected (invalid XML)');
		$this->assertTrue(self::processOnModernDaemon($xml2) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');
	}

	public function testStructuralDecoderRejectsProcessingInstructionInsideMethodName()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system<?pi target?>.client_version</methodName></methodCall>';
		$res = self::decideOnModernDaemon($xml);
		$error = isset($res['error']) ? $res['error'] : null;
		$this->assertEquals('reject', $res['action'], 'processing instruction inside methodName rejected');
		$this->assertEquals('rejected (invalid XML)', $error, 'error is rejected (invalid XML)');
		$this->assertTrue(self::processOnModernDaemon($xml) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');
		$this->assertTrue(rXMLRPCRequest::$lastPayload === null, 'no payload forwarded');
	}

	public function testStructuralDecoderRejectsCommentInsideScalarValue()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value><string>hello<!-- comment -->world</string></value></param></params></methodCall>';
		$res = self::decideOnModernDaemon($xml);
		$error = isset($res['error']) ? $res['error'] : null;
		$this->assertEquals('reject', $res['action'], 'comment inside string scalar rejected');
		$this->assertEquals('rejected (malformed XML envelope or structure)', $error, 'error is malformed envelope');
		$this->assertTrue(self::processOnModernDaemon($xml) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');

		$xmlInt = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value><int>12<!-- comment -->34</int></value></param></params></methodCall>';
		$resInt = self::decideOnModernDaemon($xmlInt);
		$errorInt = isset($resInt['error']) ? $resInt['error'] : null;
		$this->assertEquals('reject', $resInt['action'], 'comment inside int scalar rejected');
		$this->assertEquals('rejected (malformed XML envelope or structure)', $errorInt, 'error is malformed envelope');
		$this->assertTrue(self::processOnModernDaemon($xmlInt) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');
	}

	public function testStructuralDecoderRejectsProcessingInstructionInsideScalarValue()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value><string>hello<?pi target?>world</string></value></param></params></methodCall>';
		$res = self::decideOnModernDaemon($xml);
		$error = isset($res['error']) ? $res['error'] : null;
		$this->assertEquals('reject', $res['action'], 'processing instruction inside scalar value rejected');
		$this->assertEquals('rejected (malformed XML envelope or structure)', $error, 'error is malformed envelope');
		$this->assertTrue(self::processOnModernDaemon($xml) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');
		$this->assertTrue(rXMLRPCRequest::$lastPayload === null, 'no payload forwarded');
	}

	public function testStructuralDecoderRejectsDoctypeOrEntityDeclaration()
	{
		$this->resetMocks();
		$xml = '<!DOCTYPE methodCall [<!ENTITY xxe "evil">]><methodCall><methodName>system.client_version</methodName></methodCall>';
		$res = self::decideOnModernDaemon($xml);
		$error = isset($res['error']) ? $res['error'] : null;
		$this->assertEquals('reject', $res['action'], 'DOCTYPE/entity declaration rejected');
		$this->assertEquals('rejected (invalid XML)', $error, 'error is rejected (invalid XML)');
		$this->assertTrue(self::processOnModernDaemon($xml) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');
		$this->assertTrue(rXMLRPCRequest::$lastPayload === null, 'no payload forwarded');
	}

	// ---- Correction B: Load-Expression All-Or-Nothing ----

	public function testLoadWithUnclassifiableExpressionMemberIsLocallyNonProcessing()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/a.torrent</string></value></param><param><value><string>not_a_valid_command=foo</string></value></param></params></methodCall>';
		$res = self::decideOnModernDaemon($xml);
		$error = isset($res['error']) ? $res['error'] : null;
		$this->assertEquals('reject', $res['action'], 'unclassifiable load expression member rejected');
		$this->assertEquals('rejected (not allowed on this connection): load.start', $error, 'error names outer load.start');
		$this->assertEquals('load.start', $res['method'], 'outer method retained');
		$this->assertTrue(self::processOnModernDaemon($xml) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls for unclassifiable load param');
		$this->assertTrue(rXMLRPCRequest::$lastPayload === null, 'no payload forwarded');
	}

	public function testLoadWithDeniedExpressionMemberIsLocallyNonProcessing()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/a.torrent</string></value></param><param><value><string>execute=evil</string></value></param></params></methodCall>';
		$res = self::decideOnModernDaemon($xml);
		$error = isset($res['error']) ? $res['error'] : null;
		$this->assertEquals('reject', $res['action'], 'denied load expression member rejected');
		$this->assertEquals('rejected (not allowed on this connection): load.start', $error, 'error names outer load.start');
		$this->assertEquals('load.start', $res['method'], 'outer method retained');
		$this->assertTrue(self::processOnModernDaemon($xml) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls for denied load param');
		$this->assertTrue(rXMLRPCRequest::$lastPayload === null, 'no payload forwarded');
	}

	public function testLoadWithBoundaryRefusedDirectoryMemberIsTerminal()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>http://example.test/a.torrent</string></value></param>'
			. '<param><value><string>d.directory.set=/var/www/user1/rtorrent/share/settings/x/</string></value></param>'
			. '</params></methodCall>';
		$options = array('directory' => array('root' => '/torrents1/downloads'));
		$res = self::decideOnModernDaemon($xml, 'sanitize', array('d.directory.set'), false, $options);
		$this->assertEquals('reject', $res['action'], 'action = reject');
		$this->assertEquals(false, $res['trusted'], 'trusted = false');
		$this->assertTrue($res['payload'] === '' || $res['payload'] === null, 'payload = empty');
		$this->assertEquals('load.start', $res['method'], 'normalized method = the outer load method');

		$processRes = self::processOnModernDaemon($xml, 'sanitize', true, array('d.directory.set'), false, $options);
		$this->assertTrue($processRes === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'transport call count = 0');
	}
}
