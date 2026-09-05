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
	private function resetMocks()
	{
		rXMLRPCRequest::$lastPayload = null;
		rXMLRPCRequest::$lastTrusted = null;
		rXMLRPCRequest::$sent = 0;
		FileUtil::$log = array();
	}

	// ---- Mode dispatch ----

	public function testOffModeReturnsNull()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params></params></methodCall>';
		$this->assertTrue(XMLRPCProxy::process($xml, 'off') === null, 'off mode returns null');
	}

	public function testOffModeRejectsGarbage()
	{
		$this->resetMocks();
		$this->assertTrue(XMLRPCProxy::process('not xml at all', 'off') === null, 'off mode rejects garbage too');
	}

	public function testPassthroughUnsafeForwardsTrusted()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>execute</methodName><params></params></methodCall>';
		XMLRPCProxy::process($xml, 'passthrough_unsafe');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === true, 'passthrough_unsafe forwards as trusted');
		$this->assertEquals($xml, rXMLRPCRequest::$lastPayload, 'passthrough_unsafe forwards payload verbatim');
	}

	/**
	 * Legacy method identifier preserved for test surface compatibility.
	 * The contract is terminal rejection, not forwarding.
	 */
	public function testInvalidXmlForwardsUntrusted()
	{
		$this->resetMocks();
		$this->assertTrue(XMLRPCProxy::process('not xml at all', 'sanitize') === null, 'invalid XML is rejected');
	}

	public function testNonLoadMethodForwardsUntrusted()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params></params></methodCall>';
		XMLRPCProxy::process($xml, 'sanitize');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === false, 'non-load method forwarded as untrusted');
	}

	// ---- Sanitize-mode whitelist (the security-critical path) ----

	public function testSanitizeStripsDangerousCommandParam()
	{
		$xml = simplexml_load_string('<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.com/t.torrent</string></value></param><param><value><string>execute=evil</string></value></param></params></methodCall>');
		$result = XMLRPCProxy::rebuildLoadParams($xml, 'load.start', array('d.directory.set', 'd.custom1.set'));
		$this->assertEquals(2, $result['kept'], 'should keep target + URL only');
		$this->assertEquals(1, count($result['stripped']), 'should strip one param');
		$this->assertTrue(strpos($result['xml'], 'execute=evil') === false, 'rebuilt XML must not contain execute=evil');
	}

	public function testSanitizeKeepsWhitelistedCommandParam()
	{
		$xml = simplexml_load_string('<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.com/t.torrent</string></value></param><param><value><string>d.directory.set=/srv/torrents</string></value></param></params></methodCall>');
		$result = XMLRPCProxy::rebuildLoadParams($xml, 'load.start', array('d.directory.set', 'd.custom1.set'));
		$this->assertEquals(3, $result['kept'], 'should keep target + URL + safe param');
		$this->assertEquals(0, count($result['stripped']), 'should strip nothing');
		$this->assertTrue(strpos($result['xml'], 'd.directory.set="/srv/torrents"') !== false,
			'safe param survives, rebuilt as a quoted argument');
	}

	public function testSanitizeAlwaysKeepsFirstTwoParams()
	{
		$xml = simplexml_load_string('<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>execute=looks_evil_but_is_url</string></value></param></params></methodCall>');
		$result = XMLRPCProxy::rebuildLoadParams($xml, 'load.start', array());
		$this->assertEquals(2, $result['kept'], 'positional params always kept');
	}

	public function testEmptyWhitelistStripsAllCommandParams()
	{
		$xml = simplexml_load_string('<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.com/t.torrent</string></value></param><param><value><string>d.directory.set=/srv</string></value></param><param><value><string>d.custom1.set=label</string></value></param></params></methodCall>');
		$result = XMLRPCProxy::rebuildLoadParams($xml, 'load.start', array());
		$this->assertEquals(2, $result['kept'], 'empty whitelist keeps only positional');
		$this->assertEquals(2, count($result['stripped']), 'both command params stripped');
	}

	public function testRebuiltXmlIsValid()
	{
		$xml = simplexml_load_string('<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.com/t.torrent</string></value></param></params></methodCall>');
		$result = XMLRPCProxy::rebuildLoadParams($xml, 'load.start', array());
		$reparsed = @simplexml_load_string($result['xml']);
		$this->assertTrue($reparsed !== false, 'rebuilt XML round-trips through simplexml');
		$this->assertEquals('load.start', (string)$reparsed->methodName, 'method name preserved');
	}

	public function testSanitizeEndToEndForwardsCleanedPayload()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.com/t.torrent</string></value></param><param><value><string>execute=evil</string></value></param></params></methodCall>';
		$result = XMLRPCProxy::process($xml, 'sanitize', false, array('d.directory.set'));
		$this->assertTrue($result === null, 'all-or-nothing load with denied parameter returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport sends for denied parameter');
		$this->assertTrue(rXMLRPCRequest::$lastPayload === null, 'no payload forwarded for denied parameter');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === null, 'null trust for denied parameter');
	}

	// ---- Sanity ----

	public function testSanitizeMethodsList()
	{
		$ref = new ReflectionProperty('XMLRPCProxy', 'sanitizeMethods');
		$ref->setAccessible(true);
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
		XMLRPCProxy::process($xml, 'sanitize', false, $safeParams);
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
		$res = XMLRPCProxy::process($xml, 'sanitize', false, array('d.custom1.set'));
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
			'every argument is checked, not only the first');
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
		XMLRPCProxy::process($xml, 'sanitize', true, array('d.custom1.set'));

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
			'a newline in a stripped value cannot start a new log entry');
	}

	public function testLoggedValueIsLengthCapped()
	{
		$this->resetMocks();
		$this->sanitizeParamLogged('execute=' . str_repeat('A', 500));
		$this->assertTrue(strlen($this->logText()) < 400, 'a long stripped value is truncated');
	}

	private function sanitizeParamLogged($param)
	{
		$torrentB64 = base64_encode("d8:announce11:http://test4:infod6:lengthi1234eee");
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.raw_start</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><base64>' . $torrentB64 . '</base64></value></param>'
			. '<param><value><string>' . htmlspecialchars($param, ENT_NOQUOTES) . '</string></value></param>'
			. '</params></methodCall>';
		XMLRPCProxy::process($xml, 'sanitize', true, array('d.custom1.set'));
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
		XMLRPCProxy::process($xml, 'sanitize', false, array('d.custom1.set'));
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
		XMLRPCProxy::process($xml, 'sanitize', false, array('d.custom1.set'));
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
			'quoting does not hide a leading $; the argument is still dropped');
	}

	public function testUnclosedQuoteIsDropped()
	{
		$this->resetMocks();
		$this->sanitizeParamLogged('d.custom1.set="Movies, Inc');
		$this->assertTrue(strpos((string) rXMLRPCRequest::$lastPayload, 'd.custom1.set') === false,
			'an unclosed quote is malformed and dropped, not split inside it');
		$this->assertTrue(strpos($this->logText(), 'rejected') !== false || strpos($this->logText(), 'stripped') !== false,
			'and the drop is visible in the log');
	}

	public function testUnknownMethodNameCannotForgeALogLine()
	{
		$this->resetMocks();
		$name = "system.foo\nxmlrpc-proxy: trusted: forged";
		$xml = '<?xml version="1.0"?><methodCall><methodName>' . htmlspecialchars($name)
			. '</methodName><params></params></methodCall>';
		XMLRPCProxy::process($xml, 'sanitize', true, array());

		$this->assertTrue(rXMLRPCRequest::$lastTrusted === false, 'an unknown method is sent untrusted');
		$this->assertTrue(strpos($this->logText(), "\nxmlrpc-proxy: trusted") === false,
			'a method name cannot start a log line of its own');
	}

	// ---- multicalls carry commands too ----

	private function multicall($params, $safeParams = array('d.custom1.set'))
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall2</methodName><params>';
		foreach($params as $param)
			$xml .= '<param><value><string>' . htmlspecialchars($param, ENT_NOQUOTES)
				. '</string></value></param>';
		$xml .= '</params></methodCall>';
		XMLRPCProxy::process($xml, 'sanitize', true, $safeParams);
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

	/**
	 * Legacy method identifier preserved for test surface compatibility.
	 * An unknown command on a multicall causes terminal outer rejection,
	 * never raw forwarding or partial execution.
	 */
	public function testMulticallWithAnUnknownCommandIsForwardedUntouched()
	{
		$this->multicall(array('', 'main', 'd.name='));
		$this->assertTrue(rXMLRPCRequest::$sent === 0,
			'the request is rejected, not forwarded');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === null,
			'and not trusted');
	}

	public function testMulticallNeverSilentlyDropsACommand()
	{
		$this->multicall(array('', 'main', 'd.custom1.set=label', 'd.name='));
		$this->assertTrue(rXMLRPCRequest::$sent === 0,
			'a multicall with unknown command is rejected, never drops a command or forwards untrusted');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === null,
			'and not trusted');
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
		$this->multicall(array('', 'd.custom1.set=notacommand', 'd.custom1.set=label'));
		$this->assertTrue(strpos((string) rXMLRPCRequest::$lastPayload,
			'<string>d.custom1.set=notacommand</string>') !== false,
			'the view name is re-emitted as the value it is, not quoted as a command');
	}

	public function testCommandCarryingMethodsList()
	{
		$ref = new ReflectionProperty('XMLRPCProxy', 'multicallMethods');
		$ref->setAccessible(true);
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
		$xml = '<?xml version="1.0"?><methodCall><methodName>' . $method
			. '</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>' . htmlspecialchars($uri, ENT_NOQUOTES) . '</string></value></param>'
			. '</params></methodCall>';
		return XMLRPCProxy::process($xml, 'sanitize', true, array('d.custom1.set'), $allowLocalPaths);
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
		$this->assertTrue(XMLRPCProxy::process($xml, 'sanitize', true, array(), false) === null,
			'a base64 parameter is read as the URI it decodes to');
	}

	public function testRawLoadIsUnaffected()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.raw_start</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><base64>' . base64_encode('d4:infoe') . '</base64></value></param>'
			. '</params></methodCall>';
		XMLRPCProxy::process($xml, 'sanitize', true, array(), false);
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

	public function testLoadUriListDoesNotCoverTheRawMethods()
	{
		$ref = new ReflectionProperty('XMLRPCProxy', 'uriLoadMethods');
		$ref->setAccessible(true);
		$methods = $ref->getValue();
		$this->assertTrue(in_array('load.start', $methods), 'load.start takes a URI');
		$this->assertTrue(!in_array('load.raw_start', $methods),
			'load.raw_start takes the torrent, so it is not checked');
	}

	// ---- refused outright, without asking rtorrent ----

	private function callMethod($method, $params = array(), $mode = 'sanitize')
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>' . htmlspecialchars($method)
			. '</methodName><params>';
		foreach($params as $p)
			$xml .= '<param><value><string>' . htmlspecialchars($p, ENT_NOQUOTES) . '</string></value></param>';
		$xml .= '</params></methodCall>';
		return XMLRPCProxy::process($xml, $mode, true, array('d.custom1.set'));
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
		$ref->setAccessible(true);
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
		$this->assertTrue(XMLRPCProxy::process($xml, 'sanitize', true, array()) === null,
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
			$this->callMethod('d.start', array($bad));
			$this->assertTrue(rXMLRPCRequest::$sent === 0,
				var_export($bad, true) . ' is not a hash, so the call is rejected');
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
		$ref->setAccessible(true);
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
		XMLRPCProxy::process($xml, 'sanitize', true,
			array('d.directory.set', 'd.directory_base.set'), $allowLocalPaths, $options);
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

	public function testDirectoryBaseIsConfinedTheSameWay()
	{
		$policy = array('root' => '/torrents1/downloads');
		$this->assertTrue(!$this->loadInto('/var/www/user1', $policy, 'd.directory_base.set'),
			'd.directory_base.set sets the root directly, so it is the blunter of the two');
		$this->assertTrue($this->loadInto('/torrents1/downloads/x', $policy, 'd.directory_base.set'),
			'and still works inside the boundary');
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

	public function testTheConfinedCommandsAreTheOnesThatWriteSomewhere()
	{
		$ref = new ReflectionProperty('XMLRPCProxy', 'directoryCommands');
		$ref->setAccessible(true);
		$commands = $ref->getValue();
		$this->assertTrue(in_array('d.directory.set', $commands), 'd.directory.set is confined');
		$this->assertTrue(in_array('d.directory_base.set', $commands), 'd.directory_base.set is confined');
		$this->assertTrue(!in_array('d.custom1.set', $commands),
			'a label is not a path and is not confined');
	}

	/**
	 * Legacy method identifier preserved for test surface compatibility.
	 * The contract is terminal rejection in sanitize mode, not forwarding.
	 */
	public function testSystemMulticallIsStillForwardedUntouched()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.multicall</methodName>'
			. '<params><param><value><string>x</string></value></param></params></methodCall>';
		$this->assertTrue(XMLRPCProxy::process($xml, 'sanitize', true, array('d.custom1.set')) === null, 'system.multicall is rejected');
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
			$d = XMLRPCProxy::decide($xml, 'sanitize', array('d.custom1.set'));
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
			$d = XMLRPCProxy::decide($xml, 'sanitize');
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
			$d = XMLRPCProxy::decide($xml, 'sanitize');
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
			$d = XMLRPCProxy::decide($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', $carrier . ' must be rejected');
		}
	}

	public function testDirectDirectorySettersAreRejected()
	{
		$setters = array('d.directory.set', 'd.directory_base.set');
		foreach($setters as $setter)
		{
			$xml = '<?xml version="1.0"?><methodCall><methodName>' . $setter . '</methodName><params>'
				. '<param><value><string>1234567890123456789012345678901234567890</string></value></param>'
				. '<param><value><string>/tmp</string></value></param>'
				. '</params></methodCall>';
			$d = XMLRPCProxy::decide($xml, 'sanitize', array($setter));
			$this->assertTrue($d['action'] === 'reject', 'direct call to ' . $setter . ' must be rejected');
		}
	}

	public function testUnsupportedUnderscoreLoadsAreUnownedOrdinaryUnknown()
	{
		$underscoreLoads = array('load_start', 'load_normal', 'load_raw');
		foreach($underscoreLoads as $ul)
		{
			$xml = '<?xml version="1.0"?><methodCall><methodName>' . $ul . '</methodName><params>'
				. '<param><value><string>http://example.com/test.torrent</string></value></param>'
				. '</params></methodCall>';
			$d = XMLRPCProxy::decide($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'send', $ul . ' must not be rejected by proxy');
			$this->assertTrue($d['trusted'] === false, $ul . ' must be untrusted');
		}
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
			$d = XMLRPCProxy::decide($xml, 'sanitize', array('d.custom1.set'));
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
		$d = XMLRPCProxy::decide($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'd.multicall.filtered with unsafe filter must reject outer call');
		$this->assertTrue($d['method'] === 'd.multicall.filtered', 'refusal must name outer d.multicall.filtered');
	}

	public function testMixedSafeAndUnsafeMulticallRejectsEntireCall()
	{
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall2</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>main</string></value></param>'
			. '<param><value><string>d.custom1.set=safe_value</string></value></param>'
			. '<param><value><string>unknown_unrebuildable_cmd=bad</string></value></param>'
			. '</params></methodCall>';
		$d = XMLRPCProxy::decide($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'mixed multicall with unknown command must reject entire call');
		$this->assertTrue($d['method'] === 'd.multicall2', 'refusal must name outer method');
	}

	public function testSystemMulticallIsUnconditionallyRejectedInSanitize()
	{
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.multicall</methodName><params>'
			. '<param><value><array><data>'
			. '<value><struct><member><name>methodName</name><value><string>system.client_version</string></value></member><member><name>params</name><value><array><data></data></array></value></member></struct></value>'
			. '</data></array></value></param>'
			. '</params></methodCall>';
		$d = XMLRPCProxy::decide($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'benign system.multicall must be rejected');
		$this->assertTrue($d['method'] === 'system.multicall', 'refusal must name system.multicall');
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
			$d = XMLRPCProxy::decide($raw, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'malformed XML must be rejected');
			$this->assertTrue($d['payload'] === '', 'malformed XML rejection payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'malformed XML rejection must be untrusted');
		}
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
			$d = XMLRPCProxy::decide($xml, 'sanitize');
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
				$d = XMLRPCProxy::decide($xml, 'sanitize');
				$this->assertTrue($d['action'] === 'reject', $method . ' with invalid shape must be rejected');
				$this->assertTrue($d['payload'] === '', 'rejection payload must be empty');
				$this->assertTrue($d['trusted'] === false, 'rejection must be untrusted');
			}
		}
	}

	public function testInvalidModeIsTerminallyRejectedBeforeXmlParsing()
	{
		$d1 = XMLRPCProxy::decide('not xml', 'invalid_mode');
		$this->assertTrue($d1['action'] === 'reject', 'invalid mode must be rejected');
		$this->assertTrue($d1['payload'] === '', 'invalid mode payload must be empty');

		$d2 = XMLRPCProxy::decide('not xml', 'off');
		$this->assertTrue($d2['action'] === 'reject', 'off mode must be rejected');
		$this->assertTrue($d2['payload'] === '', 'off mode payload must be empty');
	}

	public function testDiagnosticsBoundsAndNormalization()
	{
		$longBadMethod = 'method.bad*name/with@punctuation#and;characters!' . str_repeat('X', 200);
		$xml = '<?xml version="1.0"?><methodCall><methodName>' . htmlspecialchars($longBadMethod) . '</methodName><params></params></methodCall>';
		$d = XMLRPCProxy::decide($xml, 'sanitize');
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
		$d1 = XMLRPCProxy::decide($xml1, 'sanitize');
		$this->assertTrue($d1['action'] === 'reject', 'non-methodCall root must be rejected');
		$this->assertTrue($d1['trusted'] === false, 'non-methodCall root must not be trusted');

		// 2. sibling methodName values
		$xml2 = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><methodName>execute</methodName><params><param><value><string></string></value></param><param><value><string>http://example.com/test.torrent</string></value></param></params></methodCall>';
		$d2 = XMLRPCProxy::decide($xml2, 'sanitize');
		$this->assertTrue($d2['action'] === 'reject', 'sibling methodName elements must be rejected');

		// 3. load.raw data slot is string, not base64
		$xml3 = '<?xml version="1.0"?><methodCall><methodName>load.raw</methodName><params><param><value><string></string></value></param><param><value><string>raw_not_base64_data</string></value></param></params></methodCall>';
		$d3 = XMLRPCProxy::decide($xml3, 'sanitize');
		$this->assertTrue($d3['action'] === 'reject', 'load.raw string data must be rejected');

		// 4. d.multicall.filtered with filter but no result
		$xml4 = '<?xml version="1.0"?><methodCall><methodName>d.multicall.filtered</methodName><params><param><value><string></string></value></param><param><value><string>default</string></value></param><param><value><string>d.is_active=</string></value></param></params></methodCall>';
		$d4 = XMLRPCProxy::decide($xml4, 'sanitize');
		$this->assertTrue($d4['action'] === 'reject', 'd.multicall.filtered without results must be rejected');

		// 5. d.multicall2 with target/view but no result
		$xml5 = '<?xml version="1.0"?><methodCall><methodName>d.multicall2</methodName><params><param><value><string></string></value></param><param><value><string>default</string></value></param></params></methodCall>';
		$d5 = XMLRPCProxy::decide($xml5, 'sanitize');
		$this->assertTrue($d5['action'] === 'reject', 'd.multicall2 without results must be rejected');

		// 6. d.start value containing both string and int children
		$xml6 = '<?xml version="1.0"?><methodCall><methodName>d.start</methodName><params><param><value><string>1234567890123456789012345678901234567890</string><int>1</int></value></param></params></methodCall>';
		$d6 = XMLRPCProxy::decide($xml6, 'sanitize');
		$this->assertTrue($d6['action'] === 'reject', 'd.start with mixed string and int types must be rejected');
	}

	public function testMalformedStructuralEnvelopeAndValuesAreTerminallyRejected()
	{
		// missing <value> in param
		$xmlMissingVal = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param></param></params></methodCall>';
		try {
			$d = XMLRPCProxy::decide($xmlMissingVal, 'sanitize');
			$this->assertTrue(is_array($d) && $d['action'] === 'reject', 'missing value must be rejected');
		} catch (Throwable $e) {
			$this->assertTrue(false, 'missing value crashed with ' . get_class($e) . ': ' . $e->getMessage());
		}

		// duplicate <value> in param
		$xmlDupVal = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value><value><string>http://example.com</string></value></param></params></methodCall>';
		$d = XMLRPCProxy::decide($xmlDupVal, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'duplicate value under param must be rejected');

		// missing <params> on owned method
		$xmlMissingParams = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName></methodCall>';
		$d = XMLRPCProxy::decide($xmlMissingParams, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'missing params on owned method must be rejected');

		// duplicate <params>
		$xmlDupParams = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param></params><params><param><value><string></string></value></param></params></methodCall>';
		$d = XMLRPCProxy::decide($xmlDupParams, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'duplicate params containers must be rejected');

		// unexpected child under <params>
		$xmlUnexpChild = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><extra>bad</extra></params></methodCall>';
		$d = XMLRPCProxy::decide($xmlUnexpChild, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'unexpected child under params must be rejected');

		// duplicate type child in value
		$xmlDupType = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string>a</string><string>b</string></value></param></params></methodCall>';
		$d = XMLRPCProxy::decide($xmlDupType, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'duplicate type children in value must be rejected');

		// unknown type child in value
		$xmlUnknownType = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><bogustype>a</bogustype></value></param></params></methodCall>';
		$d = XMLRPCProxy::decide($xmlUnknownType, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'unknown type in value must be rejected');

		// mixed implicit text and typed child
		$xmlMixedImplicit = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value>implicit<string>typed</string></value></param></params></methodCall>';
		$d = XMLRPCProxy::decide($xmlMixedImplicit, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'mixed implicit text and typed child must be rejected');

		// ordinary method with structural defects must also be rejected by the decoder
		$xmlOrdDupParams = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value><string>a</string></value></param></params><params><param><value><string>b</string></value></param></params></methodCall>';
		$d = XMLRPCProxy::decide($xmlOrdDupParams, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'duplicate params containers on ordinary method must be rejected');

		$xmlOrdUnexpChild = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value><string>a</string></value></param><extra>bad</extra></params></methodCall>';
		$d = XMLRPCProxy::decide($xmlOrdUnexpChild, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'unexpected child under params on ordinary method must be rejected');

		$xmlOrdDupVal = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value><string>a</string></value><value><string>b</string></value></param></params></methodCall>';
		$d = XMLRPCProxy::decide($xmlOrdDupVal, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'duplicate value under param on ordinary method must be rejected');

		$xmlOrdDupType = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value><string>a</string><string>b</string></value></param></params></methodCall>';
		$d = XMLRPCProxy::decide($xmlOrdDupType, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'duplicate type children in value on ordinary method must be rejected');

		$xmlOrdMixedImplicit = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value>implicit<string>typed</string></value></param></params></methodCall>';
		$d = XMLRPCProxy::decide($xmlOrdMixedImplicit, 'sanitize');
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
			$d = XMLRPCProxy::decide($xml, 'sanitize');
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
		$d = XMLRPCProxy::decide($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'safe plus unknown non-deny member must reject entire call');
		$this->assertTrue($d['method'] === 'd.multicall2', 'refusal must name outer method d.multicall2');
		$this->assertTrue($d['payload'] === '', 'refusal payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'refusal must be untrusted');
	}

	// --- C1: Filter Owner Tests ---

	public function testFilteredMulticallRejectsUnknownFilterCommand()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall.filtered</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>main</string></value></param>'
			. '<param><value><string>d.is_active=</string></value></param>'
			. '<param><value><string>d.custom1.set=safe_val</string></value></param>'
			. '</params></methodCall>';
		$d = XMLRPCProxy::decide($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'd.multicall.filtered with unknown filter command must be rejected');
		$this->assertTrue($d['method'] === 'd.multicall.filtered', 'refusal must name outer method d.multicall.filtered');
		$this->assertTrue($d['payload'] === '', 'refusal payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'refusal must be untrusted');
		$res = XMLRPCProxy::process($xml, 'sanitize', true, array('d.custom1.set'));
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
		$d = XMLRPCProxy::decide($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'dollar command filter must be rejected');
		$this->assertTrue($d['method'] === 'd.multicall.filtered', 'refusal must name outer method');
		$this->assertTrue($d['payload'] === '', 'refusal payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'refusal must be untrusted');
		$res = XMLRPCProxy::process($xml, 'sanitize', true, array('d.custom1.set'));
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
		$d = XMLRPCProxy::decide($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'parenthesized command filter must be rejected');
		$this->assertTrue($d['method'] === 'd.multicall.filtered', 'refusal must name outer method');
		$this->assertTrue($d['payload'] === '', 'refusal payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'refusal must be untrusted');
		$res = XMLRPCProxy::process($xml, 'sanitize', true, array('d.custom1.set'));
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
		$d = XMLRPCProxy::decide($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'nested command filter must be rejected');
		$this->assertTrue($d['method'] === 'd.multicall.filtered', 'refusal must name outer method');
		$this->assertTrue($d['payload'] === '', 'refusal payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'refusal must be untrusted');
		$res = XMLRPCProxy::process($xml, 'sanitize', true, array('d.custom1.set'));
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
		$d = XMLRPCProxy::decide($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'unclosed quoted filter must be rejected');
		$this->assertTrue($d['method'] === 'd.multicall.filtered', 'refusal must name outer method');
		$this->assertTrue($d['payload'] === '', 'refusal payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'refusal must be untrusted');
		$res = XMLRPCProxy::process($xml, 'sanitize', true, array('d.custom1.set'));
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
		$d = XMLRPCProxy::decide($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'malformed escaped filter must be rejected');
		$this->assertTrue($d['method'] === 'd.multicall.filtered', 'refusal must name outer method');
		$this->assertTrue($d['payload'] === '', 'refusal payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'refusal must be untrusted');
		$res = XMLRPCProxy::process($xml, 'sanitize', true, array('d.custom1.set'));
		$this->assertTrue($res === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
	}

	public function testFilteredMulticallCanonicallyRebuildsAllowedFilter()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall.filtered</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>main</string></value></param>'
			. '<param><value><string>d.custom1.set=filter value</string></value></param>'
			. '<param><value><string>d.custom.set=result_val</string></value></param>'
			. '</params></methodCall>';
		$safeParams = array('d.custom1.set', 'd.custom.set');
		$d = XMLRPCProxy::decide($xml, 'sanitize', $safeParams);
		$this->assertTrue($d['action'] === 'send', 'allowed filter must be admitted for send');
		$this->assertTrue($d['trusted'] === true, 'allowed filter multicall must be trusted');
		$this->assertTrue(strpos($d['payload'], 'd.custom1.set="filter value"') !== false,
			'filter must become canonical rebuilt form');
		$this->assertTrue(strpos($d['payload'], 'd.custom1.set=filter value') === false,
			'original filter bytes must be absent');
		$this->assertTrue(strpos($d['payload'], 'd.custom.set="result_val"') !== false,
			'result slot must become canonical rebuilt form');
		$res = XMLRPCProxy::process($xml, 'sanitize', true, $safeParams);
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
			$d = XMLRPCProxy::decide($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'text directly below methodCall must be rejected');
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = XMLRPCProxy::process($xml, 'sanitize');
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
			$d = XMLRPCProxy::decide($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'text directly below params must be rejected');
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = XMLRPCProxy::process($xml, 'sanitize');
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
			$d = XMLRPCProxy::decide($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'text directly below param must be rejected');
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = XMLRPCProxy::process($xml, 'sanitize');
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
			$d = XMLRPCProxy::decide($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'mixed value content must be rejected');
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = XMLRPCProxy::process($xml, 'sanitize');
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
			$d = XMLRPCProxy::decide($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'child inside scalar type <' . $tag . '> must be rejected');
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = XMLRPCProxy::process($xml, 'sanitize');
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
			$d = XMLRPCProxy::decide($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'attributes on XMLRPC elements must be rejected: ' . $body);
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = XMLRPCProxy::process($xml, 'sanitize');
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
			$d = XMLRPCProxy::decide($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'namespaces must be rejected: ' . $body);
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = XMLRPCProxy::process($xml, 'sanitize');
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
			$d = XMLRPCProxy::decide($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'duplicate nodes must be rejected: ' . $body);
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = XMLRPCProxy::process($xml, 'sanitize');
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
			$d = XMLRPCProxy::decide($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'missing required nodes must be rejected: ' . $body);
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = XMLRPCProxy::process($xml, 'sanitize');
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
			$d = XMLRPCProxy::decide($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'unknown child elements must be rejected: ' . $body);
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = XMLRPCProxy::process($xml, 'sanitize');
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
			$d = XMLRPCProxy::decide($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'malformed array must be rejected: ' . $body);
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = XMLRPCProxy::process($xml, 'sanitize');
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
			$d = XMLRPCProxy::decide($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'malformed struct must be rejected: ' . $body);
			$this->assertTrue($d['payload'] === '', 'payload must be empty');
			$this->assertTrue($d['trusted'] === false, 'trusted must be false');
			$res = XMLRPCProxy::process($xml, 'sanitize');
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
		$d = XMLRPCProxy::decide($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'nested element inside hash must be rejected');
		$this->assertTrue($d['payload'] === '', 'payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'trusted must be false');
		$res = XMLRPCProxy::process($xml, 'sanitize');
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
		$d = XMLRPCProxy::decide($xml, 'sanitize', array('d.custom1.set'));
		$this->assertTrue($d['action'] === 'reject', 'nested element inside load.raw_start trailing command must be rejected');
		$this->assertTrue($d['payload'] === '', 'payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'trusted must be false');
		$res = XMLRPCProxy::process($xml, 'sanitize', true, array('d.custom1.set'));
		$this->assertTrue($res === null, 'process must return null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
	}

	public function testStructuralDecoderRejectsMalformedOrdinaryMethod()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params attr="1"></params></methodCall>';
		$d = XMLRPCProxy::decide($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'malformed ordinary method must be rejected and never enter untrusted fallback');
		$this->assertTrue($d['payload'] === '', 'payload must be empty');
		$this->assertTrue($d['trusted'] === false, 'trusted must be false');
		$res = XMLRPCProxy::process($xml, 'sanitize');
		$this->assertTrue($res === null, 'process must return null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends');
	}

	public function testStructuralDecoderPreservesValidRecursiveOrdinaryValues()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value>'
			. '<array><data><value><struct><member><name>key</name><value><string>val</string></value></member></struct></value></data></array>'
			. '</value></param></params></methodCall>';
		$d = XMLRPCProxy::decide($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'send', 'valid recursive structure on ordinary method must be accepted');
		$this->assertTrue($d['trusted'] === false, 'ordinary method must be untrusted');
		$this->assertEquals($xml, $d['payload'], 'original payload preserved');
		$res = XMLRPCProxy::process($xml, 'sanitize');
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
		$d1 = XMLRPCProxy::decide($xmlInvalid, 'sanitize');
		$this->assertTrue($d1['action'] === 'reject', 'invalid UTF-8 URI must be rejected');
		$this->assertTrue($d1['method'] === 'load.start', 'refusal must name load.start');
		$this->assertTrue($d1['payload'] === '', 'payload must be empty');
		$this->assertTrue($d1['trusted'] === false, 'trusted must be false');
		$res1 = XMLRPCProxy::process($xmlInvalid, 'sanitize');
		$this->assertTrue($res1 === null, 'process must return null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends on invalid UTF-8 URI');

		// 2. XML 1.0 forbidden control byte (\x00, \x0b)
		$ctrlBytes = "http://example.test/x\x0b";
		$b64Ctrl = base64_encode($ctrlBytes);
		$xmlCtrl = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><base64>' . $b64Ctrl . '</base64></value></param>'
			. '</params></methodCall>';
		$d2 = XMLRPCProxy::decide($xmlCtrl, 'sanitize');
		$this->assertTrue($d2['action'] === 'reject', 'XML forbidden control byte URI must be rejected');
		$this->assertTrue($d2['method'] === 'load.start', 'refusal must name load.start');
		$this->assertTrue($d2['payload'] === '', 'payload must be empty');
		$this->assertTrue($d2['trusted'] === false, 'trusted must be false');
		$res2 = XMLRPCProxy::process($xmlCtrl, 'sanitize');
		$this->assertTrue($res2 === null, 'process must return null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero sends on control byte URI');

		// 3. Valid UTF-8 URI
		$validUtf8Uri = "http://example.test/тест";
		$b64Valid = base64_encode($validUtf8Uri);
		$xmlValid = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><base64>' . $b64Valid . '</base64></value></param>'
			. '</params></methodCall>';
		$d3 = XMLRPCProxy::decide($xmlValid, 'sanitize');
		$this->assertTrue($d3['action'] === 'send', 'valid UTF-8 URI must be accepted');
		$this->assertTrue($d3['trusted'] === true, 'valid load.start must be trusted');
		$this->assertTrue(strpos($d3['payload'], htmlspecialchars($validUtf8Uri, ENT_NOQUOTES, 'UTF-8')) !== false,
			'canonical string re-emission must contain valid URI');
		$res3 = XMLRPCProxy::process($xmlValid, 'sanitize');
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
				$decision = XMLRPCProxy::decide($xml);
				$this->assertTrue($decision['action'] === 'reject', $method.': XML-forbidden Unicode is rejected');
				$this->assertTrue($decision['method'] === $method, 'refusal retains the outer URI load method');
				$this->assertTrue($decision['payload'] === '', 'no malformed canonical XML is emitted');
				$this->assertTrue($decision['trusted'] === false, 'rejected input is never trusted');
				$this->assertTrue(XMLRPCProxy::process($xml) === null, 'rejection is terminal');
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
				XMLRPCProxy::process($xml);
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
			XMLRPCProxy::process($xml);
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
		$d = XMLRPCProxy::decide($xml, 'sanitize');
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
		$d = XMLRPCProxy::decide($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'must be rejected');
		$this->assertTrue($d['method'] === 'd.multicall2', 'rejection must name outer method d.multicall2');
	}

	public function testOuterIdentitySurvivesMalformedArray()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall2</methodName><params>'
			. '<param><value><array><data><unknown>x</unknown></data></array></value></param>'
			. '</params></methodCall>';
		$d = XMLRPCProxy::decide($xml, 'sanitize');
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
		$d = XMLRPCProxy::decide($xml, 'sanitize', array('d.custom1.set'));
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
			$d = XMLRPCProxy::decide($xml, 'sanitize');
			$this->assertTrue($d['action'] === 'reject', 'must be rejected');
			$this->assertTrue($d['method'] === null, 'method must remain null when no valid method is established');
		}
	}

	// --- I4: Surface Gaps & Preserved Matrix Tests ---

	public function testLoadRawStartIsOrdinaryUnknown()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>load_raw_start</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>torrent_data</string></value></param>'
			. '</params></methodCall>';
		$d = XMLRPCProxy::decide($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'send', 'load_raw_start must be admitted');
		$this->assertTrue($d['trusted'] === false, 'load_raw_start must be untrusted');
		$this->assertEquals($xml, $d['payload'], 'load_raw_start payload is original bytes');
	}

	public function testCatchExtraIsOrdinaryNotRefused()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>catch.extra</methodName><params></params></methodCall>';
		$d = XMLRPCProxy::decide($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'send', 'catch.extra must not be refused');
		$this->assertTrue($d['trusted'] === false, 'catch.extra must be untrusted');
	}

	public function testDirectoryWatchReadyIsDirectlyRefused()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>directory.watch.ready</methodName><params></params></methodCall>';
		$d = XMLRPCProxy::decide($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'reject', 'directory.watch.ready must be directly refused');
		$this->assertTrue($d['method'] === 'directory.watch.ready', 'refusal names directory.watch.ready');
	}

	public function testDirectoryWatchfulIsOrdinaryNotRefused()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>directory.watchful</methodName><params></params></methodCall>';
		$d = XMLRPCProxy::decide($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'send', 'directory.watchful must not be refused');
		$this->assertTrue($d['trusted'] === false, 'directory.watchful must be untrusted');
	}

	public function testViewFilterOnIsOrdinaryNotRefused()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>view.filter_on</methodName><params></params></methodCall>';
		$d = XMLRPCProxy::decide($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'send', 'view.filter_on must not be refused');
		$this->assertTrue($d['trusted'] === false, 'view.filter_on must be untrusted');
	}

	public function testViewSortIsOrdinaryNotRefused()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>view.sort</methodName><params></params></methodCall>';
		$d = XMLRPCProxy::decide($xml, 'sanitize');
		$this->assertTrue($d['action'] === 'send', 'view.sort must not be refused');
		$this->assertTrue($d['trusted'] === false, 'view.sort must be untrusted');
	}

	public function testViewSetIsOrdinaryNotRefused()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>view.set</methodName><params></params></methodCall>';
		$d = XMLRPCProxy::decide($xml, 'sanitize');
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
			$d = XMLRPCProxy::decide($xmlDollar, 'sanitize', $safeParams);
			$this->assertTrue($d['action'] === 'reject', $family . ' with dollar argument must reject outer call');
			$this->assertTrue($d['method'] === $family, $family . ' dollar refusal names outer method');

			// 2. parenthesized command
			$xmlParen = '<?xml version="1.0"?><methodCall><methodName>' . $family . '</methodName><params>'
				. $prefixParams . '<param><value><string>(d.custom1.set,val)</string></value></param></params></methodCall>';
			$d = XMLRPCProxy::decide($xmlParen, 'sanitize', $safeParams);
			$this->assertTrue($d['action'] === 'reject', $family . ' with parenthesized command must reject outer call');

			// 3. nested-parenthesis command
			$xmlNested = '<?xml version="1.0"?><methodCall><methodName>' . $family . '</methodName><params>'
				. $prefixParams . '<param><value><string>(branch,(d.custom1.set,val))</string></value></param></params></methodCall>';
			$d = XMLRPCProxy::decide($xmlNested, 'sanitize', $safeParams);
			$this->assertTrue($d['action'] === 'reject', $family . ' with nested parenthesis must reject outer call');

			// 4. unclosed quote
			$xmlUnclosed = '<?xml version="1.0"?><methodCall><methodName>' . $family . '</methodName><params>'
				. $prefixParams . '<param><value><string>d.custom1.set="val</string></value></param></params></methodCall>';
			$d = XMLRPCProxy::decide($xmlUnclosed, 'sanitize', $safeParams);
			$this->assertTrue($d['action'] === 'reject', $family . ' with unclosed quote must reject outer call');

			// 5. malformed escape
			$xmlEscape = '<?xml version="1.0"?><methodCall><methodName>' . $family . '</methodName><params>'
				. $prefixParams . '<param><value><string>d.custom1.set="val\\</string></value></param></params></methodCall>';
			$d = XMLRPCProxy::decide($xmlEscape, 'sanitize', $safeParams);
			$this->assertTrue($d['action'] === 'reject', $family . ' with malformed escape must reject outer call');

			// 6. unknown command
			$xmlUnknown = '<?xml version="1.0"?><methodCall><methodName>' . $family . '</methodName><params>'
				. $prefixParams . '<param><value><string>unknown.command=val</string></value></param></params></methodCall>';
			$d = XMLRPCProxy::decide($xmlUnknown, 'sanitize', $safeParams);
			$this->assertTrue($d['action'] === 'reject', $family . ' with unknown command must reject outer call');

			// 7. mixed safe and unsafe
			$xmlMixed = '<?xml version="1.0"?><methodCall><methodName>' . $family . '</methodName><params>'
				. $prefixParams . '<param><value><string>d.custom1.set=safe</string></value></param>'
				. '<param><value><string>unknown.command=bad</string></value></param></params></methodCall>';
			$d = XMLRPCProxy::decide($xmlMixed, 'sanitize', $safeParams);
			$this->assertTrue($d['action'] === 'reject', $family . ' with mixed commands must reject outer call');

			// 8. valid canonical slots
			$xmlValid = '<?xml version="1.0"?><methodCall><methodName>' . $family . '</methodName><params>'
				. $prefixParams . '<param><value><string>d.custom1.set=canonical_val</string></value></param></params></methodCall>';
			$d = XMLRPCProxy::decide($xmlValid, 'sanitize', $safeParams);
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
		$this->assertEquals(70, count($sourceKeys), 'source must contain exactly 70 top-level literal registrations');
		$this->assertEquals(count($sourceKeys), count(array_unique($sourceKeys)), 'source keys must have no duplicates before array overwrite');

		$runtimeFixture = require($fixturePath);
		$runtimeKeys = array_keys($runtimeFixture);

		$this->assertEquals(count($sourceKeys), count($runtimeKeys), 'source registration count must equal runtime array_keys count');
		$this->assertEquals(70, count($runtimeKeys), 'exact 70-key runtime set must remain unchanged');
		$this->assertEquals($sourceKeys, $runtimeKeys, 'source key order and values must match runtime keys exactly');
	}

	// ---- Correction A: Strict Structural XML Validation ----

	public function testStructuralDecoderRejectsParamsBeforeMethodName()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><params></params><methodName>system.client_version</methodName></methodCall>';
		$res = XMLRPCProxy::decide($xml);
		$error = isset($res['error']) ? $res['error'] : null;
		$this->assertEquals('reject', $res['action'], 'reversed methodCall children rejected');
		$this->assertEquals('rejected (invalid XML)', $error, 'error is rejected (invalid XML)');
		$this->assertTrue($res['method'] === null, 'method remains null');
		$this->assertTrue(XMLRPCProxy::process($xml) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');
		$this->assertTrue(rXMLRPCRequest::$lastPayload === null, 'no payload forwarded');
	}

	public function testStructuralDecoderRejectsValueBeforeNameInStructMember()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value><struct><member><value><string>foo</string></value><name>k</name></member></struct></value></param></params></methodCall>';
		$res = XMLRPCProxy::decide($xml);
		$error = isset($res['error']) ? $res['error'] : null;
		$this->assertEquals('reject', $res['action'], 'reversed struct member children rejected');
		$this->assertEquals('rejected (malformed XML envelope or structure)', $error, 'error is malformed envelope');
		$this->assertEquals('system.client_version', $res['method'], 'outer method retained');
		$this->assertTrue(XMLRPCProxy::process($xml) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');
		$this->assertTrue(rXMLRPCRequest::$lastPayload === null, 'no payload forwarded');
	}

	public function testStructuralDecoderRejectsCommentInsideMethodName()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system<!-- comment -->.client_version</methodName></methodCall>';
		$res = XMLRPCProxy::decide($xml);
		$error = isset($res['error']) ? $res['error'] : null;
		$this->assertEquals('reject', $res['action'], 'comment inside methodName rejected');
		$this->assertEquals('rejected (invalid XML)', $error, 'error is rejected (invalid XML)');
		$this->assertTrue(XMLRPCProxy::process($xml) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');
		$this->assertTrue(rXMLRPCRequest::$lastPayload === null, 'no payload forwarded');
	}

	public function testStructuralDecoderRejectsWhitespaceInsideMethodName()
	{
		$this->resetMocks();
		$xml1 = '<?xml version="1.0"?><methodCall><methodName> system.client_version </methodName></methodCall>';
		$res1 = XMLRPCProxy::decide($xml1);
		$error1 = isset($res1['error']) ? $res1['error'] : null;
		$this->assertEquals('reject', $res1['action'], 'leading/trailing whitespace inside methodName rejected');
		$this->assertEquals('rejected (invalid XML)', $error1, 'error is rejected (invalid XML)');
		$this->assertTrue(XMLRPCProxy::process($xml1) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');

		$xml2 = "<?xml version=\"1.0\"?><methodCall><methodName>\nsystem.client_version\n</methodName></methodCall>";
		$res2 = XMLRPCProxy::decide($xml2);
		$error2 = isset($res2['error']) ? $res2['error'] : null;
		$this->assertEquals('reject', $res2['action'], 'newlines inside methodName rejected');
		$this->assertEquals('rejected (invalid XML)', $error2, 'error is rejected (invalid XML)');
		$this->assertTrue(XMLRPCProxy::process($xml2) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');
	}

	public function testStructuralDecoderRejectsProcessingInstructionInsideMethodName()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system<?pi target?>.client_version</methodName></methodCall>';
		$res = XMLRPCProxy::decide($xml);
		$error = isset($res['error']) ? $res['error'] : null;
		$this->assertEquals('reject', $res['action'], 'processing instruction inside methodName rejected');
		$this->assertEquals('rejected (invalid XML)', $error, 'error is rejected (invalid XML)');
		$this->assertTrue(XMLRPCProxy::process($xml) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');
		$this->assertTrue(rXMLRPCRequest::$lastPayload === null, 'no payload forwarded');
	}

	public function testStructuralDecoderRejectsCommentInsideScalarValue()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value><string>hello<!-- comment -->world</string></value></param></params></methodCall>';
		$res = XMLRPCProxy::decide($xml);
		$error = isset($res['error']) ? $res['error'] : null;
		$this->assertEquals('reject', $res['action'], 'comment inside string scalar rejected');
		$this->assertEquals('rejected (malformed XML envelope or structure)', $error, 'error is malformed envelope');
		$this->assertTrue(XMLRPCProxy::process($xml) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');

		$xmlInt = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value><int>12<!-- comment -->34</int></value></param></params></methodCall>';
		$resInt = XMLRPCProxy::decide($xmlInt);
		$errorInt = isset($resInt['error']) ? $resInt['error'] : null;
		$this->assertEquals('reject', $resInt['action'], 'comment inside int scalar rejected');
		$this->assertEquals('rejected (malformed XML envelope or structure)', $errorInt, 'error is malformed envelope');
		$this->assertTrue(XMLRPCProxy::process($xmlInt) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');
	}

	public function testStructuralDecoderRejectsProcessingInstructionInsideScalarValue()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.client_version</methodName><params><param><value><string>hello<?pi target?>world</string></value></param></params></methodCall>';
		$res = XMLRPCProxy::decide($xml);
		$error = isset($res['error']) ? $res['error'] : null;
		$this->assertEquals('reject', $res['action'], 'processing instruction inside scalar value rejected');
		$this->assertEquals('rejected (malformed XML envelope or structure)', $error, 'error is malformed envelope');
		$this->assertTrue(XMLRPCProxy::process($xml) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');
		$this->assertTrue(rXMLRPCRequest::$lastPayload === null, 'no payload forwarded');
	}

	public function testStructuralDecoderRejectsDoctypeOrEntityDeclaration()
	{
		$this->resetMocks();
		$xml = '<!DOCTYPE methodCall [<!ENTITY xxe "evil">]><methodCall><methodName>system.client_version</methodName></methodCall>';
		$res = XMLRPCProxy::decide($xml);
		$error = isset($res['error']) ? $res['error'] : null;
		$this->assertEquals('reject', $res['action'], 'DOCTYPE/entity declaration rejected');
		$this->assertEquals('rejected (invalid XML)', $error, 'error is rejected (invalid XML)');
		$this->assertTrue(XMLRPCProxy::process($xml) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls at production seam');
		$this->assertTrue(rXMLRPCRequest::$lastPayload === null, 'no payload forwarded');
	}

	// ---- Correction B: Load-Expression All-Or-Nothing ----

	public function testLoadWithUnclassifiableExpressionMemberIsLocallyNonProcessing()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/a.torrent</string></value></param><param><value><string>not_a_valid_command=foo</string></value></param></params></methodCall>';
		$res = XMLRPCProxy::decide($xml);
		$error = isset($res['error']) ? $res['error'] : null;
		$this->assertEquals('reject', $res['action'], 'unclassifiable load expression member rejected');
		$this->assertEquals('rejected (not allowed on this connection): load.start', $error, 'error names outer load.start');
		$this->assertEquals('load.start', $res['method'], 'outer method retained');
		$this->assertTrue(XMLRPCProxy::process($xml) === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'zero transport calls for unclassifiable load param');
		$this->assertTrue(rXMLRPCRequest::$lastPayload === null, 'no payload forwarded');
	}

	public function testLoadWithDeniedExpressionMemberIsLocallyNonProcessing()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/a.torrent</string></value></param><param><value><string>execute=evil</string></value></param></params></methodCall>';
		$res = XMLRPCProxy::decide($xml);
		$error = isset($res['error']) ? $res['error'] : null;
		$this->assertEquals('reject', $res['action'], 'denied load expression member rejected');
		$this->assertEquals('rejected (not allowed on this connection): load.start', $error, 'error names outer load.start');
		$this->assertEquals('load.start', $res['method'], 'outer method retained');
		$this->assertTrue(XMLRPCProxy::process($xml) === null, 'process returns null');
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
		$res = XMLRPCProxy::decide($xml, 'sanitize', array('d.directory.set'), false, $options);
		$this->assertEquals('reject', $res['action'], 'action = reject');
		$this->assertEquals(false, $res['trusted'], 'trusted = false');
		$this->assertTrue($res['payload'] === '' || $res['payload'] === null, 'payload = empty');
		$this->assertEquals('load.start', $res['method'], 'normalized method = the outer load method');

		$processRes = XMLRPCProxy::process($xml, 'sanitize', true, array('d.directory.set'), false, $options);
		$this->assertTrue($processRes === null, 'process returns null');
		$this->assertEquals(0, rXMLRPCRequest::$sent, 'transport call count = 0');
	}
}
