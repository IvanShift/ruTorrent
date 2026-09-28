<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/AdditionCallerFixtures.php');
require_once(__DIR__ . '/TorrentAdditionSourceScanner.php');
require_once(__DIR__ . '/../../php/rtorrent.php');
require_once(__DIR__ . '/../../php/xmlrpc_proxy.php');

/**
 * The trusted in-process addition list and the external XMLRPC policy have
 * different authority. Verify that their shared commands work at the proxy,
 * while internal-only commands stay outside its public load-tail allowance.
 * The source scanner supplies the command names built by shipped callers.
 */
class AdditionProxyPolicyParityTest extends TestCase
{
	use AdditionCallerFixtures;

	/**
	 * An ordinary two-word value. Somebody's throttle group, ratio view or
	 * custom field: nothing in it is a command, and the second word begins
	 * with a refused prefix, which is all refusedCommandName() looks at.
	 */
	const ORDINARY_VALUE = 'evening catch-up';

	private $previousSettings;

	public function setUp()
	{
		$this->previousSettings = $this->currentSettings();
	}

	public function tearDown()
	{
		$this->restoreSettings($this->previousSettings);
	}

	/** $XMLRPCProxySafeParams exactly as conf/xmlrpc_proxy.php ships it. */
	private function shippedSafeParams()
	{
		$read = function ($file) {
			$XMLRPCProxySafeParams = null;
			require($file);
			return $XMLRPCProxySafeParams;
		};
		return (array)$read($this->repoRoot() . '/conf/xmlrpc_proxy.php');
	}

	/**
	 * Every addition the tree builds under one alias table, as
	 * array('name' => wire spelling, 'where' => file:line).
	 */
	private function additionsUnder($iVersion)
	{
		$this->installSettingsFor($iVersion);
		$scanner = new TorrentAdditionSourceScanner($this->repoRoot());
		$out = array();
		foreach ($scanner->callSites() as $site) {
			foreach ($site['elements'] as $element) {
				$name = TorrentAdditionSourceScanner::commandName($element);
				if ($name === null) {
					// Unreadable elements are TorrentAdditionCoverageTest's
					// failure, not this one's.
					continue;
				}
				$out[$name] = array('name' => $name,
					'where' => $site['file'] . ':' . $site['line']);
			}
		}
		ksort($out);
		return $out;
	}

	private function torrent()
	{
		return 'd8:announce20:http://tr.invalid/a4:infod6:lengthi12e4:name8:file.txt'
			. '12:piece lengthi16384e6:pieces20:' . str_repeat("\x01", 20) . 'ee';
	}

	private function value($text, $type = 'string')
	{
		return '<value><' . $type . '>' . htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8')
			. '</' . $type . '></value>';
	}

	/** One load.raw_start carrying $parameters. */
	private function single($parameters)
	{
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<methodCall><methodName>load.raw_start</methodName><params>'
			. '<param>' . $this->value('') . '</param>'
			. '<param>' . $this->value(base64_encode($this->torrent()), 'base64') . '</param>';
		foreach ($parameters as $parameter) {
			$xml .= '<param>' . $this->value($parameter) . '</param>';
		}
		return $xml . '</params></methodCall>';
	}

	/** One load.raw_start member per parameter, all in one system.multicall. */
	private function batched($parameters)
	{
		$members = '';
		foreach ($parameters as $parameter) {
			$members .= '<value><struct>'
				. '<member><name>methodName</name>'
				. '<value><string>load.raw_start</string></value></member>'
				. '<member><name>params</name><value><array><data>'
				. $this->value('')
				. $this->value(base64_encode($this->torrent()), 'base64')
				. $this->value($parameter)
				. '</data></array></value></member></struct></value>';
		}
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<methodCall><methodName>system.multicall</methodName>'
			. '<params><param><value><array><data>' . $members
			. '</data></array></value></param></params></methodCall>';
	}

	private function decide($xml, $version)
	{
		return XMLRPCProxy::decide($xml, 'sanitize', $this->shippedSafeParams(), false,
			array('directory' => array('root' => '/', 'resolve' => null),
				'rtorrentVersion' => $version));
	}

	private function proxyVersions()
	{
		return array(0x0908 => '0.9.8', 0x1018 => '0.16.24');
	}

	private function partitionAdditions($version)
	{
		$safe = $this->shippedSafeParams();
		$external = array();
		$internal = array();
		foreach ($this->additionsUnder($version) as $name => $addition)
		{
			if (in_array($name, $safe, true)) $external[$name] = $addition;
			else $internal[$name] = $addition;
		}
		return array($external, $internal);
	}

	private function parameterFor($name)
	{
		return $name . '=' . ($name === 'd.custom.set'
			? 'user-note,' . self::ORDINARY_VALUE : self::ORDINARY_VALUE);
	}

	public function testTheAdditionSetAndThePolicyAreBothRead()
	{
		$this->assertTrue(count($this->shippedSafeParams()) > 0,
			'conf/xmlrpc_proxy.php defines an external load-tail policy');
		foreach ($this->proxyVersions() as $version => $label)
		{
			list($external, $internal) = $this->partitionAdditions($version);
			$this->assertTrue(count($external) > 0 && count($internal) > 0,
				'on rtorrent ' . $label . ' the scanner finds both shared and internal-only additions');
		}
	}

	public function testInternalOnlyAdditionsAreRefusedAtTheExternalDoor()
	{
		$this->assertTrue(!in_array('d.connection_seed.set', $this->shippedSafeParams(), true),
			'the private connection-seed setter is not granted to external clients');
		foreach ($this->proxyVersions() as $version => $label)
		{
			list($external, $internal) = $this->partitionAdditions($version);
			$this->assertTrue(isset($internal['d.connection_seed.set']),
				'on rtorrent ' . $label . ' the internal-only control comes from a real caller');
			foreach ($internal as $addition)
			{
				$parameter = $this->parameterFor($addition['name']);
				foreach (array($this->single(array($parameter)),
					$this->batched(array($parameter))) as $xml)
				{
					$decision = $this->decide($xml, $version);
					$this->assertTrue($decision['action'] === 'reject' && $decision['payload'] === '',
						'on rtorrent ' . $label . ' external load refuses ' . $addition['name']
						. ' from ' . $addition['where']);
				}
			}
		}
	}

	public function testSharedAdditionsSurviveSingleAndBatchedLoads()
	{
		foreach ($this->proxyVersions() as $version => $label)
		{
			list($external, $internal) = $this->partitionAdditions($version);
			$parameters = array();
			foreach ($external as $addition)
			{
				$parameter = $this->parameterFor($addition['name']);
				$parameters[] = $parameter;
				foreach (array($this->single(array($parameter)),
					$this->batched(array($parameter))) as $xml)
				{
					$decision = $this->decide($xml, $version);
					$this->assertTrue($decision['action'] === 'send' && $decision['trusted'] === true
						&& strpos($decision['payload'], $addition['name'] . '=') !== false,
						'on rtorrent ' . $label . ' the proxy rebuilds shared ' . $addition['name']
						. ' from ' . $addition['where'] . ': ' . implode(' ', $decision['log']));
				}
			}
			$batch = $this->decide($this->batched($parameters), $version);
			$this->assertTrue($batch['action'] === 'send' && $batch['trusted'] === true,
				'on rtorrent ' . $label . ' all shared additions survive one batch: '
				. implode(' ', $batch['log']));
		}
	}

	public function testDeniedCommandsStayDeniedBesideSharedAdditions()
	{
		$refused = array('execute=/bin/id', 'execute2=/bin/id', 'import=/etc/passwd',
			'try_import=/etc/passwd', 'method.insert=x,simple,"d.name="',
			'schedule2=x,0,0,"d.name="', 'catch=/bin/id', 'log.open_file=x,/tmp/x',
			'network.scgi.open_port=0.0.0.0:5000', 'session.path.set=/tmp',
			'directory.default.set=/', 'system.env=PATH');
		foreach ($this->proxyVersions() as $version => $label)
		{
			list($external, $internal) = $this->partitionAdditions($version);
			$first = reset($external);
			foreach ($refused as $parameter)
			{
				$decision = $this->decide($this->batched(array(
					$this->parameterFor($first['name']), $parameter)), $version);
				$this->assertTrue($decision['action'] === 'reject' && $decision['payload'] === '',
					'on rtorrent ' . $label . ' refused ' . $parameter
					. ' cannot borrow an allowed addition');
			}
		}
	}

	public function testCalledArgumentsStayDeniedOnSharedAdditions()
	{
		foreach ($this->proxyVersions() as $version => $label)
		{
			list($external, $internal) = $this->partitionAdditions($version);
			foreach ($external as $addition)
			{
				$decision = $this->decide($this->batched(array(
					$addition['name'] . '=$execute=/bin/id')), $version);
				$this->assertTrue($decision['action'] === 'reject' && $decision['payload'] === '',
					'on rtorrent ' . $label . ' ' . $addition['name']
					. ' carrying a called argument is refused');
			}
		}
	}
}
