<?php

require_once(__DIR__.'/TestCase.php');
require_once(__DIR__.'/../../php/xmlrpc_response.php');

class XMLRPCResponseGrammarTest extends TestCase
{
	private function envelope($value)
	{
		return '<?xml version="1.0"?><methodResponse><params><param>'.$value.'</param></params></methodResponse>';
	}

	public function testScalarAndNestedValuesRetainTheirTypesAndBoundaries()
	{
		$xml = $this->envelope('<value><array><data><value><string /></value><value/><value><nil/></value>'
			.'<value><int>-12</int></value><value><boolean>0</boolean></value><value><double>1e2</double></value>'
			.'<value><dateTime.iso8601>20261009T10:00:00</dateTime.iso8601></value>'
			.'<value><struct><member><name>key</name><value><base64>YQBi</base64></value></member></struct></value>'
			.'</data></array></value>');
		$response = (new rXMLRPCResponse($xml))->read();
		$this->assertSame(array(array('', '', null, '-12', '0', '1e2', '20261009T10:00:00', array('key' => "a\0b"))),
			$response->values, 'nested values preserve scalar text, nil and binary bytes');
		$this->assertSame(8, count($response->scalars), 'every leaf occupies one flattened column');
	}

	public function testMalformedGrammarIsRefusedWithoutDtdOrEntityExpansion()
	{
		$badValues = array('<value><array/></value>', '<value><struct><member/></struct></value>',
			'<value><string>a</string><string>b</string></value>', '<value><boolean>yes</boolean></value>',
			'<value><i8>12x</i8></value>', '<value><double>INF</double></value>', '<value><nil>x</nil></value>',
			'<value><string>&unresolved;</string></value>', '<value><unsupported/></value>',
			'<value><struct><member><name>x</name><value>1</value></member><member><name>x</name><value>2</value></member></struct></value>');
		foreach($badValues as $value)
		{
			$refused = false;
			try { (new rXMLRPCResponse($this->envelope($value)))->read(); }
			catch(UnexpectedValueException $error) { $refused = true; }
			$this->assertTrue($refused, 'ambiguous grammar is refused: '.$value);
		}
		foreach(array('<!DOCTYPE methodResponse [<!ENTITY x SYSTEM "file:///etc/passwd">]>'.$this->envelope('<value>&x;</value>'),
			$this->envelope('<value>x</value>').'<extra/>',
			$this->envelope(str_repeat('<value><array><data>', 70).'<value>x</value>'.str_repeat('</data></array></value>', 70))) as $xml)
		{
			$refused = false;
			try { (new rXMLRPCResponse($xml))->read(); }
			catch(UnexpectedValueException $error) { $refused = true; }
			$this->assertTrue($refused, 'DTD, extra roots and excessive nesting are refused');
		}
	}

	public function testFaultPositionsAreDistinctFromUserStructs()
	{
		$map = '<value><struct><member><name>faultCode</name><value><i4>7</i4></value></member>'
			.'<member><name>faultString</name><value><string>user data</string></value></member></struct></value>';
		$this->assertTrue(!(new rXMLRPCResponse($this->envelope($map)))->read()->fault,
			'a direct result struct remains data even when it exactly resembles a fault');
		$wrapped = '<value><array><data><value><array><data>'.$map.'</data></array></value></data></array></value>';
		$this->assertTrue(!(new rXMLRPCResponse($this->envelope($wrapped), true))->read()->fault,
			'a successful multicall result containing the same dictionary remains data');
		$fault = '<value><array><data>'.$map.'</data></array></value>';
		$this->assertTrue((new rXMLRPCResponse($this->envelope($fault), true))->read()->fault,
			'a struct in the fault slot of a multicall is classified as a daemon fault');
	}
	public function testIntegerWidthsAreCheckedWithoutLosingValidTextSpellings()
	{
		foreach(array(array('i4', '-2147483648'), array('int', '+0002147483647'),
			array('i8', '-9223372036854775808'), array('i8', '+0009223372036854775807')) as $case) {
			list($type, $text) = $case;
			$response = (new rXMLRPCResponse($this->envelope('<value><'.$type.'>'.$text.'</'.$type.'></value>')))->read();
			$this->assertSame(array($text), $response->values, 'valid signed integer spelling remains exact');
		}
		foreach(array(array('i4', '2147483648'), array('int', '-2147483649'),
			array('i8', '9223372036854775808'), array('i8', '-9223372036854775809'),
			array('i8', str_repeat('9', 100))) as $case) {
			list($type, $text) = $case;
			$refused = false;
			try { (new rXMLRPCResponse($this->envelope('<value><'.$type.'>'.$text.'</'.$type.'></value>')))->read(); }
			catch(UnexpectedValueException $error) { $refused = true; }
			$this->assertTrue($refused, 'an integer outside its declared width refuses the response');
		}
	}

	public function testNumericReferencesAreXmlScalarsWhileRawLegacyBytesRemainExact()
	{
		foreach(array('&#0;', '&#xD800;', '&#xFFFE;', '&#x110000;', '&#1114112;', '&#99999999999999999999999999999;') as $reference) {
			$refused = false;
			try { (new rXMLRPCResponse($this->envelope('<value><string>'.$reference.'</string></value>')))->read(); }
			catch(UnexpectedValueException $error) { $refused = true; }
			$this->assertTrue($refused, 'a forbidden numeric reference refuses the response: '.$reference);
		}
		$text = "raw\x01\xFF\r";
		$response = (new rXMLRPCResponse($this->envelope('<value><string>'.$text.'&#9;&#10;&#13;&#x1F642;&amp;#0;</string></value>')))->read();
		$this->assertSame(array($text."\t\n\r\xF0\x9F\x99\x82&#0;"), $response->values,
			'valid references decode once, escaped reference text and raw legacy bytes remain exact');
	}

	public function testLargeListResponseFitsTheNormal128MiBWorker()
	{
		$directory = sys_get_temp_dir().'/rpc-scale-'.getmypid();
		mkdir($directory, 0700);
		$driver = $directory.'/read.php';
		file_put_contents($driver, <<<'DRIVER'
<?php
$root = $argv[1];
$_ENV['RU_PROFILE_PATH'] = __DIR__.'/profile';
require $root.'/php/xmlrpc.php';
require $root.'/tests/php/FakeRtorrentDaemon.php';
$r = new ReflectionClass('rTorrentSettings');
$s = $r->newInstanceWithoutConstructor();
$s->iVersion = 0x1019; $s->apiVersion = 11; $s->aliases = array();
$p = $r->getProperty('theSettings');
if(PHP_VERSION_ID < 80100) $p->setAccessible(true);
$p->setValue(null, $s);
$rpcLogCalls = false; $rpcLogFaults = false; $rpcTimeOut = 3;
$scgi_host = '127.0.0.1'; $log_file = __DIR__.'/app.log';
$leaf = '<value><string>filename.ext</string></value>';
$row = '<value><array><data>'.str_repeat($leaf, 36).'</data></array></value>';
$xml = '<methodResponse><params><param><value><array><data>'.str_repeat($row, 3000)
    .'</data></array></value></param></params></methodResponse>';
$d = new FakeRtorrentDaemon(array(new FakeRtorrentRawReply($xml)), __DIR__.'/daemon.log');
$scgi_port = $d->port();
unset($xml);
$q = new rXMLRPCRequest(new rXMLRPCCommand('d.multicall', array_merge(array('main'), array_fill(0, 36, 'd.name='))));
$ok = $q->success();
echo json_encode(array($ok, count($q->val), count($q->responseValues[0]), memory_get_peak_usage(true),
    $q->stringTable(36) === $q->responseValues[0], $q->stringTable(35) === null));
$d->stop();
DRIVER
		);
		$out = array();
		exec(escapeshellarg(PHP_BINARY).' -d memory_limit=128M '.escapeshellarg($driver).' '
			.escapeshellarg(realpath(__DIR__.'/../..')).' 2>&1', $out, $code);
		$result = json_decode(implode("\n", $out), true);
		$this->assertSame(0, $code, 'a large valid list response must fit a normal 128MiB worker');
		$this->assertTrue(is_array($result) && array_slice($result, 0, 3) === array(true, 108000, 3000),
			'the full request retains every flat value and nested row');
		$this->assertTrue(is_array($result) && array_slice($result, 4) === array(true, true),
			'the large response retains exact string-table grammar without another type tree');
		foreach(glob($directory.'/*') ?: array() as $file) if(is_file($file)) unlink($file);
		rmdir($directory);
	}

	public function testDepthLimitIsReachedInsideAValidResponseEnvelope()
	{
		$within = str_repeat('<value><array><data>', 64).'<value>x</value>'.str_repeat('</data></array></value>', 64);
		$response = (new rXMLRPCResponse($this->envelope($within)))->read();
		$this->assertSame(array(array('string', 'x')), $response->scalars, 'the maximum supported semantic depth is readable');
		$outside = '<value><array><data>'.$within.'</data></array></value>';
		$refused = false;
		try { (new rXMLRPCResponse($this->envelope($outside)))->read(); }
		catch(UnexpectedValueException $error) { $refused = true; }
		$this->assertTrue($refused, 'the next semantic level reaches and fails the actual depth guard');
	}

}
