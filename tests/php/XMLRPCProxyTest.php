<?php

require_once(__DIR__ . '/TestCase.php');

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

	public function testInvalidXmlForwardsUntrusted()
	{
		$this->resetMocks();
		XMLRPCProxy::process('not xml at all', 'sanitize');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === false, 'invalid XML forwards as untrusted');
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
		XMLRPCProxy::process($xml, 'sanitize', false, array('d.directory.set'));
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === true, 'sanitized load.start forwarded as trusted');
		$this->assertTrue(strpos(rXMLRPCRequest::$lastPayload, 'execute=evil') === false, 'forwarded payload must not contain malicious param');
		$this->assertTrue(strpos(rXMLRPCRequest::$lastPayload, 'http://example.com/t.torrent') !== false, 'URL preserved');
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
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.raw_start</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>http://example.test/x.torrent</string></value></param>'
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
		// An <int> target is a type this side does not rebuild.
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.raw_start</methodName><params>'
			. '<param><value><int>1</int></value></param>'
			. '<param><value><string>http://example.test/x.torrent</string></value></param>'
			. '</params></methodCall>';
		XMLRPCProxy::process($xml, 'sanitize', false, array('d.custom1.set'));
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === false,
			'anything not rebuilt here is forwarded untrusted, for rtorrent to judge');
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
		// An <int> target is a type this side does not rebuild, so nothing is
		// stripped but the call still cannot be trusted.
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.raw_start</methodName><params>'
			. '<param><value><int>1</int></value></param>'
			. '<param><value><string>http://example.test/x.torrent</string></value></param>'
			. '</params></methodCall>';
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
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.raw_start</methodName><params>'
			. '<param><value><string></string></value></param>'
			. '<param><value><string>http://example.test/x.torrent</string></value></param>'
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
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.raw_start</methodName><params>'
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

	public function testPreQuotedValueIsDroppedNotMangled()
	{
		$this->resetMocks();
		$this->sanitizeParamLogged('d.custom1.set="Movies, Inc"');
		$this->assertTrue(strpos((string) rXMLRPCRequest::$lastPayload, 'd.custom1.set') === false,
			'a value the client quoted itself is dropped, not split inside its quotes');
		$this->assertTrue(strpos($this->logText(), 'stripped') !== false,
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
	 * The one place multicalls differ from load.*: a load can lose a command
	 * and still add the torrent, but a multicall's commands are the request.
	 * Dropping one would answer with a short row and no fault, so the request
	 * goes on untouched and rtorrent's own gate decides.
	 */
	public function testMulticallWithAnUnknownCommandIsForwardedUntouched()
	{
		$sent = $this->multicall(array('', 'main', 'd.name='));
		$this->assertTrue(rXMLRPCRequest::$lastPayload === $sent,
			'the request is forwarded byte for byte, not rebuilt');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === false,
			'and untrusted, so rtorrent applies its own restrictions');
	}

	public function testMulticallNeverSilentlyDropsACommand()
	{
		$this->multicall(array('', 'main', 'd.custom1.set=label', 'd.name='));
		$this->assertTrue(strpos((string) rXMLRPCRequest::$lastPayload, 'd.name=') !== false,
			'a read command is not stripped out of the caller\'s multicall');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === false,
			'the mixed call goes untrusted rather than losing a command');
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
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === false,
			'an allowed command whose argument would be re-parsed is not trusted');
		$this->assertTrue(strpos((string) rXMLRPCRequest::$lastPayload, 'execute.capture') !== false,
			'the original is forwarded rather than a rebuilt one, so nothing is smuggled in quoted');
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
			$this->assertTrue(rXMLRPCRequest::$lastTrusted === false,
				var_export($bad, true) . ' is not a hash, so the call is left untrusted');
		}
	}

	public function testTheArgumentCountHasToMatch()
	{
		$this->callMethod('d.start', array('0123456789ABCDEF0123456789ABCDEF01234567', 'extra'));
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === false,
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
		$this->assertTrue(rXMLRPCRequest::$sent === 1,
			'and the torrent is still added, to the default directory');
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

	public function testSystemMulticallIsStillForwardedUntouched()
	{
		$this->resetMocks();
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.multicall</methodName>'
			. '<params><param><value><string>x</string></value></param></params></methodCall>';
		XMLRPCProxy::process($xml, 'sanitize', true, array('d.custom1.set'));
		$this->assertTrue(rXMLRPCRequest::$lastPayload === $xml, 'forwarded byte for byte');
		$this->assertTrue(rXMLRPCRequest::$lastTrusted === false, 'and untrusted');
	}

	private function methodResponse($value = 'ok')
	{
		return '<?xml version="1.0"?><methodResponse><params><param><value><string>'
			. htmlspecialchars($value, ENT_NOQUOTES) . '</string></value></param></params></methodResponse>';
	}

	private function runPhpChild($arguments)
	{
		$command = array(escapeshellarg(PHP_BINARY));
		foreach($arguments as $argument)
			$command[] = escapeshellarg($argument);
		$proc = proc_open(implode(' ', $command), array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		), $pipes);
		if(!is_resource($proc))
			throw new RuntimeException('failed to start PHP child process');
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		return array(
			'exitCode' => proc_close($proc),
			'stdout' => $stdout,
			'stderr' => $stderr,
		);
	}

	/**
	 * Run one live TCP SCGI peer. It deliberately reads the request as tiny
	 * fragments, so a successful response proves the complete netstring and
	 * XML payload arrived rather than merely the first socket-buffer write.
	 */
	private function mockSCGIServer($behavior, $callback)
	{
		require_once(__DIR__ . '/../../php/scgitransport.php');
		$encodedBehavior = base64_encode(serialize($behavior));
		$code = '$behavior = unserialize(base64_decode(' . var_export($encodedBehavior, true) . '));' . <<<'PHP'
function readExactFragmented($conn, $length, $chunkSize)
{
	$data = '';
	while(strlen($data) < $length)
	{
		$part = @fread($conn, min($chunkSize, $length - strlen($data)));
		if($part === false || $part === '')
			return null;
		$data .= $part;
	}
	return $data;
}

function readSCGIRequest($conn, $chunkSize)
{
	$lengthText = '';
	while(strlen($lengthText) <= 20)
	{
		$char = readExactFragmented($conn, 1, $chunkSize);
		if($char === null)
			return false;
		if($char === ':')
			break;
		if($char < '0' || $char > '9')
			return false;
		$lengthText .= $char;
	}
	if($lengthText === '' || strlen($lengthText) > 20)
		return false;
	$headerLength = (int) $lengthText;
	$headers = readExactFragmented($conn, $headerLength, $chunkSize);
	$comma = readExactFragmented($conn, 1, $chunkSize);
	if($headers === null || $comma !== ',')
		return false;
	$parts = explode(chr(0), $headers);
	$contentLength = null;
	for($i = 0; $i + 1 < count($parts); $i += 2)
	{
		if($parts[$i] === 'CONTENT_LENGTH' && ctype_digit($parts[$i + 1]))
			$contentLength = (int) $parts[$i + 1];
	}
	if($contentLength === null)
		return false;
	$payload = readExactFragmented($conn, $contentLength, $chunkSize);
	return $payload !== null && strlen($payload) === $contentLength;
}

function writeFragmented($conn, $data, $fragmentSize, $delayMicros)
{
	$offset = 0;
	while($offset < strlen($data))
	{
		$written = @fwrite($conn, substr($data, $offset, $fragmentSize));
		if($written === false || $written === 0)
			return;
		$offset += $written;
		if($delayMicros > 0)
			usleep($delayMicros);
	}
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if($server === false)
	exit(2);
echo stream_socket_get_name($server, false) . "\n";
flush();
$conn = @stream_socket_accept($server, 5);
if($conn === false)
	exit(3);
stream_set_timeout($conn, 2);
if(isset($behavior['requestReadDelayMicros']))
	usleep($behavior['requestReadDelayMicros']);
$complete = readSCGIRequest($conn, isset($behavior['requestChunk']) ? $behavior['requestChunk'] : 7);
if(isset($behavior['responseDelayMicros']))
	usleep($behavior['responseDelayMicros']);
if(!empty($behavior['reportRequestComplete']))
{
	$value = $complete ? 'complete' : 'incomplete';
	$body = '<?xml version="1.0"?><methodResponse><params><param><value><string>'
		. $value . '</string></value></param></params></methodResponse>';
	$response = "Status: 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
	writeFragmented($conn, $response, 11, 0);
}
elseif(isset($behavior['generatedBodyBytes']))
{
	writeFragmented($conn, "Status: 200 OK\r\nContent-Type: text/xml\r\n\r\n", 1024, 0);
	$remaining = $behavior['generatedBodyBytes'];
	$block = str_repeat('x', 1048576);
	while($remaining > 0)
	{
		$size = min($remaining, strlen($block));
		$written = @fwrite($conn, substr($block, 0, $size));
		if($written === false || $written === 0)
			break;
		$remaining -= $written;
	}
}
else
{
	writeFragmented($conn, $behavior['response'],
		isset($behavior['responseChunk']) ? $behavior['responseChunk'] : strlen($behavior['response']),
		isset($behavior['responseDelayBetweenChunks']) ? $behavior['responseDelayBetweenChunks'] : 0);
}
fclose($conn);
fclose($server);
PHP;
		$proc = proc_open(PHP_BINARY . ' -r ' . escapeshellarg($code), array(
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		), $pipes);
		if(!is_resource($proc))
			throw new RuntimeException('failed to start mock SCGI peer');
		$endpoint = trim((string) fgets($pipes[1]));
		if(!preg_match('/^([^:]+):(\d+)$/', $endpoint, $match))
			throw new RuntimeException('mock SCGI peer did not publish an endpoint');
		try {
			$callback($match[1], (int) $match[2]);
		} finally {
			$status = proc_get_status($proc);
			if($status['running'])
				proc_terminate($proc);
			fclose($pipes[1]);
			fclose($pipes[2]);
			proc_close($proc);
		}
	}

	public function testSCGITransportExactContentLengthXmlAccepted()
	{
		$body = $this->methodResponse('exact');
		$response = "Status: 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
		$this->mockSCGIServer(array('response' => $response), function($host, $port) use ($body, $response) {
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2);
			$this->assertTrue(is_array($res), 'exact Content-Length XML accepted');
			$this->assertTrue($res['body'] === $body, 'body is returned byte for byte');
			$this->assertTrue($res['raw'] === $response, 'raw response is returned byte for byte');
		});
	}

	public function testSCGITransportMixedCaseContentLengthAccepted()
	{
		$body = $this->methodResponse('mixed-case');
		$response = "Status: 200 OK\r\ncOnTeNt-LeNgTh: " . strlen($body) . "\r\n\r\n" . $body;
		$this->mockSCGIServer(array('response' => $response), function($host, $port) {
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2);
			$this->assertTrue(is_array($res), 'mixed-case Content-Length is recognized');
		});
		$oversized = "Status: 200 OK\r\ncOnTeNt-LeNgTh: 67108865\r\n\r\n";
		$this->mockSCGIServer(array('response' => $oversized), function($host, $port) {
			$err = null;
			rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue(strpos($err, 'response body exceeds 67108864 bytes') !== false,
				'mixed-case Content-Length enforces the same exact bound');
		});
	}

	public function testSCGITransportMalformedHeaderFieldRejected()
	{
		$response = "Status: 200 OK\r\nBroken header field\r\n\r\n" . $this->methodResponse();
		$this->mockSCGIServer(array('response' => $response), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue($res === null, 'malformed header field is rejected');
			$this->assertTrue(strpos($err, 'malformed response header') !== false, 'header error is classified');
		});
	}

	public function testSCGITransportEveryDuplicateContentLengthRejected()
	{
		$body = $this->methodResponse();
		$response = "Status: 200 OK\r\nContent-Length: " . strlen($body)
			. "\r\ncontent-length: " . strlen($body) . "\r\n\r\n" . $body;
		$this->mockSCGIServer(array('response' => $response), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue($res === null, 'identical duplicate Content-Length is rejected');
			$this->assertTrue(strpos($err, 'duplicate Content-Length') !== false, 'duplicate is classified');
		});
	}

	public function testSCGITransportMalformedContentLengthRejected()
	{
		$response = "Status: 200 OK\r\nContent-Length: 12oops\r\n\r\n" . $this->methodResponse();
		$this->mockSCGIServer(array('response' => $response), function($host, $port) {
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
		$this->mockSCGIServer(array('response' => $response), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue($res === null, 'exact-length non-XML body is rejected');
			$this->assertTrue(strpos($err, 'invalid XML methodResponse') !== false, 'XML error is classified');
		});
	}

	public function testSCGITransportTruncatedBodyRejected()
	{
		$body = $this->methodResponse();
		$response = "Status: 200 OK\r\nContent-Length: " . (strlen($body) + 10) . "\r\n\r\n" . $body;
		$this->mockSCGIServer(array('response' => $response), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue($res === null, 'truncated body is rejected');
			$this->assertTrue(strpos($err, 'truncated response') !== false, 'truncation is classified');
		});
	}

	public function testSCGITransportOverlongBodyRejected()
	{
		$body = $this->methodResponse();
		$response = "Status: 200 OK\r\nContent-Length: " . (strlen($body) - 1) . "\r\n\r\n" . $body;
		$this->mockSCGIServer(array('response' => $response), function($host, $port) {
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
		$this->mockSCGIServer(array('response' => $response), function($host, $port) {
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
		$this->mockSCGIServer(array('response' => $response), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue($res === null, 'lengthless truncated XML is rejected');
			$this->assertTrue(strpos($err, 'invalid XML methodResponse') !== false, 'XML truncation is classified');
		});
	}

	public function testSCGITransportLengthlessValidXmlAccepted()
	{
		$body = $this->methodResponse('lengthless');
		$response = "Status: 200 OK\r\nContent-Type: text/xml\r\n\r\n" . $body;
		$this->mockSCGIServer(array('response' => $response), function($host, $port) use ($body) {
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2);
			$this->assertTrue(is_array($res), 'valid lengthless XML response is accepted');
			$this->assertTrue($res['body'] === $body, 'lengthless body is complete');
		});
	}

	public function testSCGITransportFragmentedValidResponseAccepted()
	{
		$body = $this->methodResponse('fragmented');
		$response = "Status: 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
		$this->mockSCGIServer(array(
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
		$this->mockSCGIServer(array(
			'response' => '',
			'responseDelayMicros' => 1500000,
		), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 1, $err);
			$this->assertTrue($res === null, 'read timeout is rejected');
			$this->assertTrue(strpos($err, 'timed out reading') !== false, 'timeout is classified before empty read');
		});
	}

	public function testSCGITransportExactHeaderLimitSurvivesEveryDelimiterSplit()
	{
		$prefix = "Status: 200 OK\r\nX-Fill: ";
		$headers = $prefix . str_repeat('h', 65536 - strlen($prefix));
		$body = $this->methodResponse('header-limit');
		$response = $headers . "\r\n\r\n" . $body;
		foreach(array(1, 2, 3) as $delimiterBytesInFirstWrite)
		{
			$this->mockSCGIServer(array(
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
		$response = $headers . "\r\n\r\n" . $this->methodResponse();
		$this->mockSCGIServer(array(
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
		$this->mockSCGIServer(array('generatedBodyBytes' => 67108865), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 5, $err);
			$this->assertTrue($res === null, 'lengthless body over 67108864 bytes is rejected');
			$this->assertTrue(strpos($err, 'response body exceeds 67108864 bytes') !== false, 'body bound is exact');
		});
	}

	public function testSCGITransportDeclaredBodyOver64MiBRejected()
	{
		$response = "Status: 200 OK\r\nContent-Length: 67108865\r\n\r\n";
		$this->mockSCGIServer(array('response' => $response), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue($res === null, 'declared body over 67108864 bytes is rejected');
			$this->assertTrue(strpos($err, 'response body exceeds 67108864 bytes') !== false, 'declared bound is classified');
		});
	}

	public function testSCGITransportMissingHeaderDelimiterRejected()
	{
		$response = 'Status: 200 OK Content-Length: 4 test';
		$this->mockSCGIServer(array('response' => $response), function($host, $port) {
			$err = null;
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2, $err);
			$this->assertTrue($res === null, 'missing header delimiter is rejected');
			$this->assertTrue(strpos($err, 'missing header delimiter') !== false, 'framing error is classified');
		});
	}

	public function testSCGITransportWritesCompleteRequestAcrossPartialWrites()
	{
		$this->mockSCGIServer(array(
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
		$body = $this->methodResponse('x-header');
		$response = "Status: 200 OK\r\nX-Content-Length: 1\r\n\r\n" . $body;
		$this->mockSCGIServer(array('response' => $response), function($host, $port) {
			$res = rSCGITransport::send($host, $port, '<xml/>', true, 2);
			$this->assertTrue(is_array($res), 'X-Content-Length is not mistaken for Content-Length');
		});
	}

	public function testSCGITransportLengthlessNonXmlRpcRootRejected()
	{
		$response = "Status: 200 OK\r\nContent-Type: text/html\r\n\r\n<html><body>error</body></html>";
		$this->mockSCGIServer(array('response' => $response), function($host, $port) {
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
		$child = $this->runPhpChild(array('-n', '-d',
			'disable_functions=simplexml_load_string', '-r', $script, '--', $transportPath));

		$this->assertEquals(0, $child['exitCode'],
			'disabled-SimpleXML child exits cleanly before transport: stderr=' . $child['stderr']
			. ', stdout=' . $child['stdout']);
		$this->assertEquals('', $child['stderr'], 'disabled-SimpleXML child emits no fatal or warning');
		$this->assertEquals("result=null\nerror=SimpleXML extension is required for SCGI response validation\n",
			$child['stdout'], 'send() returns null with the exact dependency error before transport');
	}

	public function testEnvCheckRequiresTheAvailableSimpleXMLFunction()
	{
		$envCheckPath = realpath(__DIR__ . '/../../env_check.php');
		$child = $this->runPhpChild(array('-n', '-d',
			'disable_functions=simplexml_load_string', $envCheckPath));

		$this->assertEquals(1, $child['exitCode'],
			'env_check must exit 1 when the required SimpleXML function is disabled');
		$this->assertEquals('', $child['stderr'], 'env_check emits no fatal or warning');
		$this->assertTrue(strpos($child['stdout'], 'Required:') !== false, 'Required section present');
		$reqSection = '';
		if(preg_match('/Required:(.*?)(?:Recommended:|Configuration:|$)/s', $child['stdout'], $m)) {
			$reqSection = $m[1];
		}
		$this->assertTrue(preg_match('/^  \[FAIL\] PHP extension: simplexml\s+'
			. 'core SCGI XMLRPC response validation$/m', $reqSection) === 1,
			'simplexml must be a required FAIL with the exact core-validation reason');
	}
}
