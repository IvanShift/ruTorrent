<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/xmlrpc_proxy.php');

/**
 * Resolve as much of a path as exists, the way rpc2_resolve_path() and
 * httprpcResolvePath() do, so the boundary is tested through the resolver the
 * production callers install rather than a lexical check on its own.
 */
function xmlrpcProxyDirectoryBatchResolve($path)
{
	$real = @realpath($path);
	if($real !== false)
		return $real;
	$parts = explode('/', trim($path, '/'));
	$tail = array();
	while(count($parts) > 0)
	{
		array_unshift($tail, array_pop($parts));
		$base = '/' . implode('/', $parts);
		$real = @realpath(($base === '') ? '/' : $base);
		if($real !== false)
			return rtrim($real, '/') . '/' . implode('/', $tail);
	}
	return '';
}

/**
 * Directory setters are confined in load tails and refused as multicall
 * result commands. The latter must hold even when the path is inside the
 * configured root; a result command runs once per download.
 */
class XMLRPCProxyDirectoryBatchTest extends TestCase
{
	private $root;
	private $inside;
	private $outside = '/etc/ru-directory-batch-outside';

	private $safe;

	public function setUp()
	{
		$this->safe = XMLRPCProxy::defaultSafeParams();
		$this->root = sys_get_temp_dir() . '/ru-directory-batch-root';
		$this->inside = $this->root . '/ok';
		@mkdir($this->root, 0700, true);
	}

	public function tearDown()
	{
		@rmdir($this->root);
	}

	/**
	 * The commands the class itself says it confines.
	 */
	private function confinedCommands()
	{
		$property = new ReflectionProperty('XMLRPCProxy', 'directoryCommands');
		self::makeAccessible($property);
		return $property->getValue();
	}

	private function decide($xml, $withBoundary = true)
	{
		$options = $withBoundary
			? array('directory' => array(
				'root'    => $this->root,
				'resolve' => 'xmlrpcProxyDirectoryBatchResolve'))
			: array();
		$options['rtorrentVersion'] = 0x1018;
		return XMLRPCProxy::decide($xml, 'sanitize', $this->safe, false, $options);
	}

	private function value($s)
	{
		return '<value><string>' . htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8')
			. '</string></value>';
	}

	private function multicall($command)
	{
		$xml = '<?xml version="1.0"?><methodCall><methodName>d.multicall</methodName><params>';
		foreach(array('', 'main', $command) as $p)
			$xml .= '<param>' . $this->value($p) . '</param>';
		return $xml . '</params></methodCall>';
	}

	private function systemMulticall($command)
	{
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.multicall</methodName>'
			. '<params><param><value><array><data><value><struct>'
			. '<member><name>methodName</name><value><string>load.start</string></value></member>'
			. '<member><name>params</name><value><array><data>';
		foreach(array('', 'http://example.invalid/a.torrent', $command) as $p)
			$xml .= $this->value($p);
		return $xml . '</data></array></value></member></struct></value></data></array>'
			. '</value></param></params></methodCall>';
	}

	private function load($command)
	{
		$xml = '<?xml version="1.0"?><methodCall><methodName>load.start</methodName><params>';
		foreach(array('', 'http://example.invalid/a.torrent', $command) as $param)
			$xml .= '<param>' . $this->value($param) . '</param>';
		return $xml . '</params></methodCall>';
	}

	public function testTheConfinedListIsReadable()
	{
		$commands = $this->confinedCommands();
		$this->assertTrue(count($commands) > 0,
			'XMLRPCProxy names the commands it confines (' . implode(', ', $commands) . ')');
	}

	/**
	 * The boundary holds on a d.multicall, not only on a single load.
	 */
	public function testAMulticallCannotCarryADirectoryOutsideTheBoundary()
	{
		$leaked = array();
		foreach($this->confinedCommands() as $command)
		{
			$decision = $this->decide($this->multicall($command . '=' . $this->outside));
			if(($decision['action'] !== 'reject') &&
				(strpos($decision['payload'], $this->outside) !== false))
				$leaked[] = $command;
		}
		$this->assertTrue(count($leaked) === 0,
			'a d.multicall naming a directory outside the boundary is refused'
			. (count($leaked) ? ', forwarded: ' . implode(', ', $leaked) : ''));
	}

	/**
	 * And on a system.multicall, where the commands sit inside a member rather
	 * than in the call's own parameters.
	 */
	public function testASystemMulticallCannotCarryADirectoryOutsideTheBoundary()
	{
		$leaked = array();
		foreach($this->confinedCommands() as $command)
		{
			$decision = $this->decide($this->systemMulticall($command . '=' . $this->outside));
			if(($decision['action'] !== 'reject') &&
				(strpos($decision['payload'], $this->outside) !== false))
				$leaked[] = $command;
		}
		$this->assertTrue(count($leaked) === 0,
			'a system.multicall member naming a directory outside the boundary is refused'
			. (count($leaked) ? ', forwarded: ' . implode(', ', $leaked) : ''));
	}

	/**
	 * Control: a directory the caller is entitled to is not refused, so a green
	 * above is the boundary and not a batch that fails whatever it carries.
	 */
	public function testMulticallDirectorySettersAreRefusedEvenInsideTheBoundary()
	{
		foreach($this->confinedCommands() as $command)
		{
			$decision = $this->decide($this->multicall($command . '=' . $this->inside));
			$this->assertTrue($decision['action'] === 'reject' && $decision['payload'] === '',
				$command . ' cannot run as a per-download result command');
		}
	}

	public function testLoadTailInsideBoundaryIsTrustedAndOutsideIsRefused()
	{
		foreach($this->confinedCommands() as $command)
		{
			$inside = $this->decide($this->load($command . '=' . $this->inside));
			$this->assertTrue($inside['action'] === 'send' && $inside['trusted'],
				$command . ' inside the boundary is rebuilt and sent trusted');
			$outside = $this->decide($this->load($command . '=' . $this->outside));
			$this->assertTrue($outside['action'] === 'reject' && $outside['payload'] === '',
				$command . ' outside the boundary is refused');
		}
	}

	public function testNoBoundaryOptionLeavesLoadTailUnconfined()
	{
		foreach($this->confinedCommands() as $command)
		{
			$decision = $this->decide($this->load($command . '=' . $this->inside), false);
			$this->assertTrue($decision['action'] === 'send' && $decision['trusted'],
				$command . ' is rebuilt when no optional boundary is supplied');
		}
	}
}
