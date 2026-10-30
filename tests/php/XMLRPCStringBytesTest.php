<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/xmlrpc.php');
require_once(__DIR__ . '/FakeRtorrentDaemon.php');

/**
 * What rXMLRPCRequest::run() hands its callers for the strings in a daemon's
 * answer (AUD-ESC, round 4).
 *
 * run() used to double every backslash and prefix every double quote in a
 * value before decoding its entities, so ->val and ->strings held bytes the
 * daemon never had: a path that did not exist, JSON that did not decode, a
 * directory that the next quoteCommandArg() escaped a second time. It also
 * called any answer that contained the word "faultCode" anywhere a fault, so
 * one torrent or tracker message spelling it emptied the WebUI list.
 *
 * The contract pinned here: strings are the daemon's own bytes, decoded once
 * as XML text (all five predefined entities and character references); the
 * deprecated $escapeForScript flag restores the old escaped form for an
 * external plugin; a fault is the faultCode member's markup, at the top level
 * or inside system.multicall, never the word in a value; <base64> values keep
 * their place in the column order.
 *
 * The bodies below are what the daemons write: 0.16 (tinyxml2) and 0.9.8
 * (xmlrpc-c) both answer `aud "q" \b 'a' &c` as
 * <string>aud "q" \b 'a' &amp;c</string> (AUD-ESC probe 2, both daemons).
 */
class XMLRPCStringBytesTest extends TestCase
{
	// The daemons' own spelling of the value `aud "q" \b 'a' &c`.
	const DAEMON_TEXT = 'aud "q" \\b \'a\' &amp;c';
	const RAW = 'aud "q" \\b \'a\' &c';
	const ESCAPED = 'aud \\"q\\" \\\\b \'a\' &c';

	private $previousSettings;
	private $base;
	private $daemon;

	public function setUp()
	{
		$reflection = new ReflectionClass('rTorrentSettings');
		$settings = $reflection->newInstanceWithoutConstructor();
		$settings->iVersion = 0x1019;
		$settings->apiVersion = 11;
		$settings->aliases = array();
		$property = $reflection->getProperty('theSettings');
		self::makeAccessible($property);
		$this->previousSettings = $property->getValue();
		$property->setValue(null, $settings);

		$GLOBALS['scgi_host'] = '127.0.0.1';
		$GLOBALS['rpcTimeOut'] = 10;
		$GLOBALS['rpcLogCalls'] = false;
		$GLOBALS['rpcLogFaults'] = false;
		$this->base = sys_get_temp_dir() . '/rutorrent-xmlrpc-bytes-' . getmypid();
		$this->removeTree($this->base);
		@mkdir($this->base, 0700, true);
		$GLOBALS['log_file'] = $this->base . '/app.log';
	}

	public function tearDown()
	{
		$reflection = new ReflectionClass('rTorrentSettings');
		$property = $reflection->getProperty('theSettings');
		self::makeAccessible($property);
		$property->setValue(null, $this->previousSettings);
		if ($this->daemon !== null) {
			$this->daemon->stop();
			$this->daemon = null;
		}
		$this->removeTree($this->base);
	}

	private function removeTree($path)
	{
		if (!is_dir($path)) {
			return;
		}
		foreach (glob($path . '/*') as $entry) {
			is_dir($entry) ? $this->removeTree($entry) : @unlink($entry);
		}
		@rmdir($path);
	}

	/** Serve these response bodies, one per request, and point the transport at them. */
	private function serve(array $bodies)
	{
		// A replaced daemon would keep its worker until the accept timeout.
		if ($this->daemon !== null) {
			$this->daemon->stop();
		}
		$this->daemon = new FakeRtorrentDaemon(array_map(function ($body) {
			return new FakeRtorrentRawReply($body);
		}, $bodies), $this->base . '/calls.log');
		$GLOBALS['scgi_port'] = $this->daemon->port();
	}

	private static function arrayBody(array $members)
	{
		return '<?xml version="1.0" encoding="UTF-8"?><methodResponse><params><param><value><array><data>'
			. implode('', $members) . '</data></array></value></param></params></methodResponse>';
	}

	private static function str($text)
	{
		return '<value><string>' . $text . '</string></value>';
	}

	private static function faultBody($code, $text)
	{
		return '<?xml version="1.0"?><methodResponse><fault><value><struct>'
			. '<member><name>faultCode</name><value><i4>' . $code . '</i4></value></member>'
			. '<member><name>faultString</name><value><string>' . $text . '</string></value></member>'
			. '</struct></value></fault></methodResponse>';
	}

	private function request($typed = false, $escapeForScript = false)
	{
		$request = new rXMLRPCRequest(array(
			new rXMLRPCCommand('d.name', 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'),
			new rXMLRPCCommand('d.message', 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA')));
		if ($typed) {
			$request->setParseByTypes();
		}
		if ($escapeForScript) {
			$request->escapeForScript = true;
		}
		return $request;
	}

	private function same($expected, $actual, $what)
	{
		$this->assertSame($expected, $actual, $what
			. ($expected === $actual ? '' : ': got ' . var_export($actual, true)));
	}

	public function testDefaultModeHandsOutTheDaemonsOwnBytes()
	{
		$this->serve(array(self::arrayBody(array(
			self::str(self::DAEMON_TEXT), self::str(''), '<value><i8>42</i8></value>',
			self::str('{"k":"v\\\\w"}')))));
		$request = $this->request();
		$this->assertTrue($request->run(), 'the answer is read');
		$this->assertTrue(!$request->fault, 'an ordinary answer is not a fault');
		$this->same(array(self::RAW, '', '42', '{"k":"v\\\\w"}'), $request->val,
			'->val holds each string as the daemon has it, an empty one included');
		$this->same(array('k' => 'v\\w'), json_decode($request->val[3], true),
			'JSON kept in a custom field decodes as it was written');
	}

	public function testTypedModeHandsOutTheDaemonsOwnBytes()
	{
		$this->serve(array(self::arrayBody(array(
			self::str(self::DAEMON_TEXT), '<value><i8>42</i8></value>', self::str('tail\\')))));
		$request = $this->request(true);
		$this->assertTrue($request->run(), 'the typed answer is read');
		$this->same(array(self::RAW, 'tail\\'), $request->strings,
			'->strings holds the daemon bytes, a trailing backslash included');
		$this->same(array('42'), $request->i8s, '->i8s keeps the numbers');
	}

	// 0.16 writes a name that is not UTF-8 byte for byte; decoding the
	// entities must not drop or replace those bytes.
	public function testANameThatIsNotUtf8KeepsItsBytes()
	{
		$this->serve(array(self::arrayBody(array(self::str("cp1251 \xD4\xE8\xEB\xFC\xEC &amp; \xFF")))));
		$request = $this->request();
		$this->assertTrue($request->run(), 'the answer is read');
		$this->same(array("cp1251 \xD4\xE8\xEB\xFC\xEC & \xFF"), $request->val,
			'bytes that are not UTF-8 pass through the entity decoding unchanged');
	}

	public function testEveryEntitySpellingIsDecodedExactlyOnce()
	{
		$this->serve(array(self::arrayBody(array(
			self::str('it&apos;s'), self::str('it&#39;s'), self::str('a&quot;b&#92;c'),
			self::str('&#x5C;&#X22;'), self::str('&amp;quot; &amp;amp; &lt;x&gt;')))));
		$request = $this->request();
		$this->assertTrue($request->run(), 'the answer is read');
		$this->same(array('it\'s', 'it\'s', 'a"b\\c', '\\"', '&quot; &amp; <x>'), $request->val,
			'&apos;, character references and &quot; become their bytes, and an escaped '
			. 'entity stays the text it spells');
	}

	public function testEscapeForScriptRestoresTheDeprecatedEscapedForm()
	{
		$body = self::arrayBody(array(self::str(self::DAEMON_TEXT), self::str('a&quot;b&#92;c'),
			'<value><i8>7</i8></value>'));
		$this->serve(array($body, $body));
		$request = $this->request(false, true);
		$this->assertTrue($request->run(), 'the answer is read');
		$this->same(array(self::ESCAPED, 'a\\"b\\\\c', '7'), $request->val,
			'with $escapeForScript every quote and backslash is escaped after decoding');
		$request = $this->request(true, true);
		$this->assertTrue($request->run(), 'the typed answer is read');
		$this->same(array(self::ESCAPED, 'a\\"b\\\\c'), $request->strings,
			'the flag escapes ->strings the same way');
	}

	// Upstream's reader escapes every value and its callers undo that with
	// unescapeValue(). Here the values are already the daemon's bytes, so it
	// returns them unchanged: a name holding \\ or \" keeps both characters.
	public function testUnescapeValueReturnsTheDaemonsBytes()
	{
		$body = self::arrayBody(array(self::str(self::DAEMON_TEXT), self::str('dbs\\\\x'), self::str('bq\\"x')));
		$this->serve(array($body));
		$request = $this->request(false);
		$this->assertTrue($request->run(), 'the answer is read');
		$this->same(array(self::RAW, 'dbs\\\\x', 'bq\\"x'), array_map(array('rXMLRPCRequest', 'unescapeValue'), $request->val),
			'unescapeValue() hands back what run() read');
		foreach(array(null, 7, array('a\\\\b')) as $value)
			$this->same($value, rXMLRPCRequest::unescapeValue($value), 'a value that is not a string is returned as it is');
	}

	// AUD-ESC-01: a torrent named "x faultCode y.bin" and a tracker failure
	// reason "quota faultCode reached" emptied the WebUI list (HTTP 500) and
	// aborted every rutracker_check cycle on both daemons.
	public function testAValueSpellingFaultCodeIsNotAFault()
	{
		$this->serve(array(self::arrayBody(array(
			self::str('x faultCode y.bin'), self::str('quota faultCode reached'),
			self::str('&lt;name&gt;faultCode&lt;/name&gt;')))));
		$request = $this->request();
		$this->assertTrue($request->success(), 'an answer whose values spell faultCode succeeds');
		$this->assertTrue(!$request->fault, 'such an answer is not a fault');
		$this->same('', $request->faultString, 'no fault text is invented');
		$this->same(array('x faultCode y.bin', 'quota faultCode reached', '<name>faultCode</name>'),
			$request->val, 'the values reach the caller');
	}

	public function testARealFaultIsAFaultWithItsTextDecodedOnce()
	{
		$this->serve(array(self::faultBody(-503, ' bad "x" \\y &amp; it&apos;s ')));
		$request = $this->request();
		$this->assertTrue($request->run(), 'a fault answer is read');
		$this->assertTrue($request->fault, 'the fault member makes it a fault');
		$this->same(' bad "x" \\y & it\'s ', $request->rawFaultString,
			'rawFaultString is the daemon text, decoded once and untrimmed');
		$this->same('bad "x" \\y & it\'s', $request->faultString, 'faultString is that text trimmed');
		$this->same(array('-503', ' bad "x" \\y & it\'s '), $request->val,
			'->val carries the code and the raw text of a fault');
	}

	public function testAFaultInsideSystemMulticallIsAFault()
	{
		$this->serve(array(self::arrayBody(array(
			'<value><array><data>' . self::str('name') . '</data></array></value>',
			'<value><struct><member><name>faultCode</name><value><i4>-500</i4></value></member>'
			. '<member><name>faultString</name><value><string>invalid parameters: info-hash not found'
			. '</string></value></member></struct></value>'))));
		$request = $this->request();
		$this->assertTrue($request->run(), 'the multicall answer is read');
		$this->assertTrue($request->fault, 'a member fault inside system.multicall is a fault');
		$this->same('invalid parameters: info-hash not found', $request->rawFaultString,
			'the member fault text is kept');
	}

	// AUD-ESC S8: rTorrent 0.16.13+ answers *.as_binary commands (and
	// *.or_as_binary for a name that is not UTF-8) with <base64>. run() used to
	// skip it, shifting every later value into the wrong column.
	public function testBase64ValuesKeepTheirColumn()
	{
		$bytes = "na\xFFme \"q\" \\";
		$wrapped = chunk_split(base64_encode($bytes . str_repeat('x', 60)), 76, "\r\n");
		$this->serve(array(
			self::arrayBody(array(self::str('a'), '<value><base64>' . base64_encode($bytes) . '</base64></value>',
				'<value><i8>3</i8></value>', '<value><base64>' . "\r\n" . $wrapped . '</base64></value>',
				self::str('z'))),
			self::arrayBody(array(self::str('a'), '<value><base64>' . base64_encode($bytes) . '</base64></value>',
				'<value><i8>3</i8></value>', self::str('z')))));
		$request = $this->request();
		$this->assertTrue($request->run(), 'an answer with base64 values is read');
		$this->same(array('a', $bytes, '3', $bytes . str_repeat('x', 60), 'z'), $request->val,
			'a base64 value is decoded in its place, line breaks and all');
		$request = $this->request(true);
		$this->assertTrue($request->run(), 'the typed answer is read');
		$this->same(array('a', $bytes, 'z'), $request->strings, 'typed mode keeps base64 among the strings');
		$this->same(array('3'), $request->i8s, 'and the numbers apart');
	}

	// R5-REV-12: the refusal used to leave the other values in ->val and
	// ->strings, each one column early, and the numbers in ->i8s. A caller
	// that ignores run()'s false return must find no column at all.
	public function testAnUndecodableBase64ValueRefusesTheAnswer()
	{
		$body = self::arrayBody(array(self::str('a'), '<value><base64>@@not base64@@</base64></value>',
			self::str('z'), '<value><i8>5</i8></value>'));
		$this->serve(array($body, $body));
		$request = $this->request();
		$this->assertTrue(!$request->run(), 'an answer with a value that does not decode is not read short');
		$this->same(array(), $request->val, 'the refusal leaves no value in ->val to be read shifted');
		$request = $this->request(true);
		$this->assertTrue(!$request->run(), 'nor in typed mode');
		$this->same(array(), $request->strings, 'the typed refusal leaves no string in ->strings');
		$this->same(array(), $request->i8s, 'nor a number in ->i8s');
		$this->assertTrue(strpos((string)@file_get_contents($this->base . '/app.log'),
			'rXMLRPCRequest: rpc-invalid-base64') !== false,
			'the refusal is logged with its classified reason');
	}

	// A request larger than maxContentSize() goes out in several calls whose
	// values run() collects in one ->val. A refused later call must not leave
	// the earlier calls' values behind as if they were the whole answer.
	public function testARefusalInALaterCallClearsTheValuesOfTheEarlierOnes()
	{
		$settings = rTorrentSettings::get();
		$settings->apiVersion = 10;
		$this->assertSame(2 << 20, $settings->maxContentSize(), 'the fixture uses 2 MiB calls');
		$this->serve(array(
			self::arrayBody(array(self::str('first'))),
			self::arrayBody(array(self::str('a'), '<value><base64>@@not base64@@</base64></value>',
				self::str('z')))));
		$big = str_repeat('x', 1100000);
		$request = new rXMLRPCRequest(array(
			new rXMLRPCCommand('d.custom.set', array('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'k', $big)),
			new rXMLRPCCommand('d.custom.set', array('BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB', 'k', $big))));
		$this->assertTrue(!$request->run(), 'the refused second call fails the request');
		$this->same(array('d.custom.set', 'd.custom.set'), $this->daemon->calls(),
			'the request went out as two calls');
		$this->same(array(), $request->val, 'the first call\'s value does not stay behind in ->val');
	}
	public function testEveryScalarShapeKeepsItsColumn()
	{
		$this->serve(array(self::arrayBody(array('<value>implicit &amp; text</value>',
			'<value><string/></value>', '<value><nil/></value>',
			'<value><boolean>1</boolean></value>', '<value><double>1.25</double></value>'))));
		$request = $this->request();
		$this->assertTrue($request->run(), 'all XMLRPC scalar shapes are read');
		$this->same(array('implicit & text', '', null, '1', '1.25'), $request->val,
			'each scalar occupies exactly its own column');
	}

	public function testMalformedLateRepliesDiscardAllValuesAndClassifyTheFault()
	{
		foreach(array('', '<methodResponse>', '<methodResponse><params/></methodResponse>',
			self::arrayBody(array('<value><base64>@@</base64></value>')),
			self::arrayBody(array('<value><string>x</string><i8>3</i8></value>'))) as $late) {
			$this->serve(array(self::arrayBody(array(self::str('first'))), $late));
			$settings = rTorrentSettings::get();
			$settings->apiVersion = 10;
			$big = str_repeat('x', 1100000);
			$request = new rXMLRPCRequest(array(new rXMLRPCCommand('cat', $big), new rXMLRPCCommand('cat', $big)));
			$this->assertTrue(!$request->run(), 'a bad late reply refuses the whole request');
			$this->same(array(), $request->val, 'no early values survive');
			$this->assertTrue($request->fault && $request->faultString !== '', 'the refusal has a classified fault');
		}
	}

	public function testTypedChunksAccumulateNumbersAndStrings()
	{
		$this->serve(array(self::arrayBody(array('<value><i8>1</i8></value>', self::str('a'))),
			self::arrayBody(array('<value><i8>2</i8></value>', self::str('b')))));
		rTorrentSettings::get()->apiVersion = 10;
		$big = str_repeat('x', 1100000);
		$request = new rXMLRPCRequest(array(new rXMLRPCCommand('cat', $big), new rXMLRPCCommand('cat', $big)));
		$request->setParseByTypes();
		$this->assertTrue($request->run(), 'both chunks are read');
		$this->same(array('1', '2'), $request->i8s, 'numbers accumulate across chunks');
		$this->same(array('a', 'b'), $request->strings, 'strings accumulate across chunks');
	}

	public function testStructuredReaderPreservesBinaryTextAndRejectsAmbiguousMarkup()
	{
		$binary = "raw\r\xFF";
		$body = self::arrayBody(array(self::str($binary), '<value><string><![CDATA[a<&]]></string></value>'));
		$this->serve(array($body));
		$request = $this->request();
		$this->assertTrue($request->run(), 'legacy daemon byte text remains readable');
		$this->same(array($binary, 'a<&'), $request->val, 'CR and nonUTF8 remain bytes, CDATA is literal text');
		foreach(array('<!DOCTYPE methodResponse SYSTEM "file:///etc/passwd">'.self::arrayBody(array(self::str('x'))),
			self::arrayBody(array('<value><unknown>x</unknown></value>')),
			'<methodResponse><fault><value><string>not a fault</string></value></fault></methodResponse>') as $bad) {
			$this->serve(array($bad));
			$request = $this->request();
			$this->assertTrue(!$request->run() && $request->fault, 'unsupported or ambiguous markup refuses visibly');
			$this->same(array(), $request->val, 'malformed markup yields no partial values');
		}
	}

	public function testUserStructKeysNamedFaultCodeRemainOrdinaryData()
	{
		$map = '<value><struct><member><name>faultCode</name><value><string>user-value</string></value></member></struct></value>';
		$single = '<?xml version="1.0"?><methodResponse><params><param>'.$map.'</param></params></methodResponse>';
		$this->serve(array($single, self::arrayBody(array('<value><array><data>'.$map.'</data></array></value>'))));
		$request = new rXMLRPCRequest(new rXMLRPCCommand('d.custom.items', 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'));
		$this->assertTrue($request->success(), 'a direct user dictionary key cannot invent an RPC fault');
		$this->same(array('user-value'), $request->val, 'the user dictionary value reaches the caller');
		$request = $this->request();
		$this->assertTrue($request->success(), 'a successful multicall wrapper keeps its user dictionary ordinary');
		$this->same(array('user-value'), $request->val, 'the nested user dictionary retains its value');
	}

}
