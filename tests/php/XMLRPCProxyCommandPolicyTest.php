<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/xmlrpc_proxy.php');

/**
 * Encoded commands, nested dispatchers and load aliases must be refused
 * locally. Positive controls use canonical names for the selected daemon
 * version so a refusal cannot pass merely because the fixture is obsolete.
 */
class XMLRPCProxyCommandPolicyTest extends TestCase
{
	private $safe = array('d.custom1.set', 'd.directory.set');
	private $opts;

	public function setUp()
	{
		$this->opts = array('directory' => array('root' => '/', 'resolve' => null),
			'rtorrentVersion' => 0x1018);
	}

	private function decide($xml)
	{
		return XMLRPCProxy::decide($xml, 'sanitize', $this->safe, false, $this->opts);
	}

	private function call($method, $params = array())
	{
		$xml = '<?xml version="1.0"?><methodCall><methodName>' . $method
			. '</methodName><params>';
		foreach($params as $p)
			$xml .= '<param><value><string>' . htmlspecialchars($p, ENT_NOQUOTES)
				. '</string></value></param>';
		$xml .= '</params></methodCall>';
		return $xml;
	}

	// --- load aliases and command tails ---

	public function testLoadStartVerboseRebuildsAllowedCommandsAndRejectsExecute()
	{
		$uri = 'http://example.invalid/a.torrent';
		$bad = $this->decide($this->call('load.start_verbose',
			array('', $uri, 'execute=/bin/id')));
		$this->assertTrue($bad['action'] === 'reject' && $bad['payload'] === '',
			'load.start_verbose refuses an executable command tail');
		$good = $this->decide($this->call('load.start_verbose',
			array('', $uri, 'd.custom1.set=label')));
		$this->assertTrue($good['action'] === 'send' && $good['trusted'],
			'load.start_verbose still accepts a safe tail');
		$this->assertTrue(strpos($good['payload'], 'd.custom1.set="label"') !== false,
			'the allowed tail is rebuilt before sending');
	}

	public function testLoadVerboseIsHeldToTheSameUriRuleAsLoadNormal()
	{
		// load.verbose reads parameter 1 as a URI exactly as load.normal does,
		// and a value that is not a network URI is a path on rtorrent's own
		// filesystem, which becomes the download's tied file.
		$xml = $this->call('load.verbose', array('', '/etc/passwd'));
		$d = $this->decide($xml);

		$this->assertTrue($d['action'] === 'reject',
			'load.verbose from a local path is refused, as load.normal is');
	}

	public function testEveryLoadSpellingRtorrentRegistersIsRebuilt()
	{
		$spellings = array(
			'load.normal', 'load.start', 'load.verbose', 'load.start_verbose',
			'load.raw', 'load.raw_start', 'load.raw_verbose', 'load.raw_start_verbose',
		);
		foreach($spellings as $method)
		{
			$raw = strpos($method, 'load.raw') === 0;
			$data = $raw ? '<value><base64>' . base64_encode('d1:ae')
				. '</base64></value>'
				: '<value><string>http://example.invalid/a.torrent</string></value>';
			$prefix = '<?xml version="1.0"?><methodCall><methodName>' . $method
				. '</methodName><params><param><value><string></string></value></param>'
				. '<param>' . $data . '</param>';
			$good = $this->decide($prefix . '<param><value><string>d.custom1.set=ok'
				. '</string></value></param></params></methodCall>');
			$this->assertTrue($good['action'] === 'send' && $good['trusted'],
				$method . ' accepts a safe command with a valid data parameter');
			$bad = $this->decide($prefix . '<param><value><string>execute=/bin/id'
				. '</string></value></param></params></methodCall>');
			$this->assertTrue($bad['action'] === 'reject' && $bad['payload'] === '',
				$method . ' refuses an executable command with valid data');
		}
	}

	// --- a parameter encoded as base64 rather than written as text ---

	public function testABase64CommandSlotIsRefused()
	{
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall</methodName>'
			. '<params><param><value><string></string></value></param>'
			. '<param><value><string>main</string></value></param>'
			. '<param><value><base64>' . base64_encode('execute=/bin/id')
			. '</base64></value></param></params></methodCall>';
		$d = $this->decide($xml);
		$this->assertTrue($d['action'] === 'reject' && $d['payload'] === '',
			'an encoded command slot never reaches rtorrent');
		$this->assertTrue($d['method'] === 'd.multicall'
			&& strpos(implode(' ', $d['log']), 'slot 3') !== false,
			'the refusal identifies the carrier and slot');
	}

	public function testAnAllowedStringCommandStillGetsThrough()
	{
		$xml = $this->call('load.start', array('',
			'http://example.invalid/a.torrent', 'd.custom1.set=label'));
		$d = $this->decide($xml);
		$this->assertTrue($d['action'] === 'send' && $d['trusted'],
			'a safe string command tail is forwarded trusted');
		$this->assertTrue(strpos($d['payload'], 'd.custom1.set="label"') !== false,
			'its argument is quoted in the rebuilt call');
	}

	// --- a command nested inside another command's arguments ---

	public function testACommandNestedInAMulticallArgumentIsRefused()
	{
		// A nested dispatcher is never allowed as a result expression.
		$xml = $this->call('d.multicall',
			array('', 'main', 'd.multicall=main,execute=/bin/id'));
		$d = $this->decide($xml);

		$this->assertTrue($d['action'] === 'reject',
			'a multicall parameter carrying a nested execute is refused');
		$this->assertTrue(isset($d['method']) && $d['method'] === 'd.multicall'
			&& strpos(implode(' ', $d['log']), 'slot 3: d.multicall') !== false,
			'the refusal names the carrier and nested slot');
	}

	public function testADollarIntroducedCommandIsRefused()
	{
		// A dollar introduces another command name in rTorrent syntax.
		$xml = $this->call('d.multicall', array('', 'main', '$execute=/bin/id'));
		$d = $this->decide($xml);

		$this->assertTrue($d['action'] === 'reject',
			'a $-introduced execute is refused');
		$this->assertTrue(isset($d['method']) && $d['method'] === 'd.multicall'
			&& strpos(implode(' ', $d['log']), 'slot 3: ?execute') !== false,
			'the refusal names the carrier and command slot');
	}

	// --- a call wrapped in a system.multicall ---

	public function testSystemMulticallMemberParametersAreJudged()
	{
		// The member's command slot is checked before the batch is sent.
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.multicall</methodName>'
			. '<params><param><value><array><data>'
			. '<value><struct>'
			. '<member><name>methodName</name><value><string>d.multicall</string></value></member>'
			. '<member><name>params</name><value><array><data>'
			. '<value><string></string></value>'
			. '<value><string>main</string></value>'
			. '<value><string>execute=/bin/id</string></value>'
			. '</data></array></value></member>'
			. '</struct></value>'
			. '</data></array></value></param></params></methodCall>';
		$d = $this->decide($xml);

		$this->assertTrue($d['action'] === 'reject',
			'a system.multicall member carrying execute is refused');
		$this->assertTrue(isset($d['method']) && $d['method'] === 'execute'
			&& strpos(implode(' ', $d['log']), 'slot 1: d.multicall') !== false,
			'the refusal names the batch and member slot');
	}

	public function testSystemMulticallMemberNamesAreStillJudged()
	{
		// What the member-name check already covered has to keep working.
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.multicall</methodName>'
			. '<params><param><value><array><data>'
			. '<value><struct>'
			. '<member><name>methodName</name><value><string>system.shutdown</string></value></member>'
			. '<member><name>params</name><value><array><data></data></array></value></member>'
			. '</struct></value>'
			. '</data></array></value></param></params></methodCall>';
		$d = $this->decide($xml);

		$this->assertTrue($d['action'] === 'reject',
			'a system.multicall member named system.shutdown is refused');
		$this->assertTrue(isset($d['method']) && $d['method'] === 'system.shutdown'
			&& strpos(implode(' ', $d['log']), 'slot 1: system.shutdown') !== false,
			'the refusal names the batch and denied member');
	}

	public function testANestedSystemMulticallIsRefused()
	{
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.multicall</methodName>'
			. '<params><param><value><array><data>'
			. '<value><struct>'
			. '<member><name>methodName</name><value><string>system.multicall</string></value></member>'
			. '<member><name>params</name><value><array><data></data></array></value></member>'
			. '</struct></value>'
			. '</data></array></value></param></params></methodCall>';
		$d = $this->decide($xml);

		$this->assertTrue($d['action'] === 'reject',
			'a system.multicall nested in a system.multicall is refused');
	}

	// --- what must keep working ---

	public function testAnOrdinaryReadMulticallIsStillForwarded()
	{
		$xml = $this->call('d.multicall', array('', 'main', 'd.name=', 'd.custom=seedingtime'));
		$d = $this->decide($xml);

		$this->assertTrue($d['action'] === 'send',
			'an ordinary read multicall is still forwarded');
	}

	public function testACustomFieldNamedAfterARefusedWordIsNotRefusedForIt()
	{
		// "scheduled" begins with the refused prefix "schedule", but it stands
		// where a command's argument stands, not where a command stands. What
		// follows '=' inside one element is argument text and is not a name.
		$xml = $this->call('d.multicall', array('', 'main', 'd.custom=scheduled'));
		$d = $this->decide($xml);

		$this->assertTrue($d['action'] === 'send',
			'a custom field named "scheduled" is not mistaken for schedule');
	}

	public function testTheShippedTrackerColumnExpressionIsNotRefused()
	{
		// The bundled plugin maps its legacy names to this canonical expression.
		$expression = 'cat="$t.multicall=d.hash=,t.scrape_complete=,cat={#}"';
		$xml = $this->call('d.multicall', array('', 'main', $expression));
		$d = $this->decide($xml);

		$this->assertTrue($d['action'] === 'send',
			'the shipped nested tracker-column expression is still forwarded');
	}
}
