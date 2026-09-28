<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/xmlrpc_proxy.php');

/** Carrier commands must be judged at every entry point, including batches. */
class XMLRPCProxyCommandCarrierTest extends TestCase
{
	private $hash = '0123456789abcdef0123456789abcdef01234567';

	private function value($text)
	{
		return '<value><string>' . htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8')
			. '</string></value>';
	}

	private function call($method, $params)
	{
		$xml = '<?xml version="1.0"?><methodCall><methodName>' . $method
			. '</methodName><params>';
		foreach($params as $param)
			$xml .= '<param>' . $this->value($param) . '</param>';
		return $xml . '</params></methodCall>';
	}

	private function batch($members)
	{
		$xml = '<?xml version="1.0"?><methodCall><methodName>system.multicall'
			. '</methodName><params><param><value><array><data>';
		foreach($members as $member)
		{
			$xml .= '<value><struct><member><name>methodName</name>'
				. $this->value($member[0]) . '</member><member><name>params</name>'
				. '<value><array><data>';
			foreach($member[1] as $param)
				$xml .= $this->value($param);
			$xml .= '</data></array></value></member></struct></value>';
		}
		return $xml . '</data></array></value></param></params></methodCall>';
	}

	private function decide($xml)
	{
		return XMLRPCProxy::decide($xml, 'sanitize', XMLRPCProxy::defaultSafeParams(),
			false, array('directory' => array('root' => '/torrents1', 'resolve' => null),
				'rtorrentVersion' => 0x1018));
	}

	private function assertRefused($decision, $method, $message)
	{
		$this->assertTrue($decision['action'] === 'reject'
			&& $decision['payload'] === '' && !$decision['trusted'],
			$message . ' is refused before transport');
		$this->assertTrue($decision['method'] === $method,
			$message . ' identifies ' . $method);
	}

	public function testBatchRebuildsSafeCommandAndIsIdempotent()
	{
		$request = $this->batch(array(array('d.multicall',
			array('', 'main', 'd.custom1.set=x;execute=cat,/etc/passwd'))));
		$first = $this->decide($request);
		$this->assertTrue($first['action'] === 'send' && $first['trusted'],
			'a safe label tail is sent trusted');
		$this->assertTrue(strpos($first['payload'],
			'd.custom1.set="x;execute=cat,/etc/passwd"') !== false,
			'the separator remains inside one quoted argument');
		$this->assertTrue(strpos($first['payload'],
			'd.custom1.set=x;execute=cat,/etc/passwd') === false,
			'the raw unquoted command is not forwarded');
		$second = $this->decide($first['payload']);
		$this->assertTrue($second['action'] === 'send'
			&& $second['payload'] === $first['payload'],
			'the rebuilt payload is a fixed point');
	}

	public function testBatchRefusesUnknownAndNestedCommands()
	{
		foreach(array('execute=/bin/id', 'd.multicall=main,execute=/bin/id') as $command)
		{
			$d = $this->decide($this->batch(array(array('d.multicall',
				array('', 'main', $command)))));
			$this->assertTrue($d['action'] === 'reject' && $d['payload'] === '',
				$command . ' is rejected inside the batch');
		}
	}

	public function testDirectorySettersCannotRunAsMulticallResults()
	{
		foreach(array('d.directory.set', 'd.directory_base.set', 'd.directory.base.set')
			as $name)
		{
			$d = $this->decide($this->batch(array(array('d.multicall',
				array('', 'main', $name . '=/torrents1/ok')))));
			$this->assertRefused($d, $name, $name . ' as a result command');
		}
	}

	public function testDirectDirectorySettersAreDeniedAtEitherPath()
	{
		foreach(array('d.directory.set', 'd.directory_base.set',
			'd.set_directory', 'd.set_directory_base') as $name)
		{
			foreach(array('/torrents1/ok', '/var/www/html') as $path)
			{
				$args = array($this->hash, $path);
				$this->assertRefused($this->decide($this->call($name, $args)),
					$name, $name . ' direct to ' . $path);
				$this->assertRefused($this->decide($this->batch(array(array($name, $args)))),
					$name, $name . ' batched to ' . $path);
			}
		}
	}

	public function testMixedTrustBatchIsRefusedButHomogeneousBatchesWork()
	{
		$read = array('d.name', array($this->hash));
		$write = array('d.custom1.set', array($this->hash, 'label'));
		$mixed = $this->decide($this->batch(array($write, $read)));
		$this->assertRefused($mixed, 'system.multicall',
			'a trusted setter mixed with an untrusted reader');
		$reads = $this->decide($this->batch(array($read, $read)));
		$this->assertTrue($reads['action'] === 'send' && !$reads['trusted'],
			'a read-only batch still works without trust');
		$writes = $this->decide($this->batch(array($write, $write)));
		$this->assertTrue($writes['action'] === 'send' && $writes['trusted'],
			'a homogeneous validated setter batch still works');
	}

	public function testEveryEvaluatorIsDeniedDirectAndBatched()
	{
		$list = new ReflectionProperty('XMLRPCProxy', 'evaluatorDenies');
		self::makeAccessible($list);
		$evaluators = $list->getValue();
		$this->assertTrue(count($evaluators) > 0, 'the current evaluator list is covered');
		foreach($evaluators as $name)
		{
			$args = array($this->hash, 'd.is_active=', 'd.name=');
			$this->assertRefused($this->decide($this->call($name, $args)),
				$name, $name . ' direct');
			$this->assertRefused($this->decide($this->batch(array(array($name, $args)))),
				$name, $name . ' batched');
		}
	}

	public function testDollarCommandScannerFindsNestedExecute()
	{
		$this->assertTrue(XMLRPCProxy::refusedCommandName('cat=$execute=/bin/id') === 'execute',
			'a dollar-introduced command is found');
		$this->assertTrue(XMLRPCProxy::refusedCommandName('d.custom1.set=season 1') === null,
			'ordinary text is not mistaken for a command');
	}
}
