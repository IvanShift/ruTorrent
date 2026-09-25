<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/xmlrpc_proxy.php');

/**
 * cmd= reaches both multicall slots and direct RPC method names. Keep its
 * policy tied to the raw XMLRPC proxy rather than maintaining another list.
 */
class HttprpcCommandParameterTest extends TestCase
{
	public function testRefusedCommandsAreRecognizedForEveryCmdMode()
	{
		$this->assertTrue(method_exists('XMLRPCProxy', 'refusedCommandName'),
			'httprpc can call the shared command policy');
		if(!method_exists('XMLRPCProxy', 'refusedCommandName'))
			return;

		// Every mode consumes the same $add array. These are the dangerous
		// names that the old substring check let through in either position.
		foreach(array('list', 'ttl', 'prp') as $mode)
			foreach(array('import=/tmp/commands.rc',
				'method.insert=x,simple,"d.name="', 'system.shutdown') as $command)
			{
				$this->assertTrue(XMLRPCProxy::refusedCommandName($command) !== null,
					$mode.' names '.$command);
				$this->assertTrue(XMLRPCProxy::sanitizeHttprpcCommandParameter(
					$command, $command, $mode) === null,
					$mode.' rejects '.$command);
			}
	}

	public function testNestedCommandsAndSafeReadersUseSharedPolicy()
	{
		if(!method_exists('XMLRPCProxy', 'refusedCommandName'))
		{
			$this->assertTrue(false, 'httprpc has a shared command policy');
			return;
		}
		foreach(array('cat="$d.multicall=main,import=/tmp/commands.rc"',
			'cat=$system.shutdown', 'd.multicall=main,method.insert=x') as $command)
			$this->assertTrue(XMLRPCProxy::refusedCommandName($command) !== null,
				'nested command is refused: '.$command);
		$this->assertTrue(XMLRPCProxy::refusedCommandName('d.directory.set=/outside')
			=== 'd.directory.set', 'directory setters follow the shared direct-call boundary');
		foreach(array('d.get_custom=imported', 'd.get_custom=execution',
			'd.get_hash=', 'cat=$d.views=', 't.get_url=') as $command)
			$this->assertTrue(XMLRPCProxy::refusedCommandName($command) === null,
				'reader stays available: '.$command);
	}

	public function testDirectModesAdmitGettersButRefuseOtherMutators()
	{
		$this->assertTrue(method_exists('XMLRPCProxy', 'sanitizeHttprpcCommandParameter'),
			'httprpc has a shared read-only policy for cmd= values');
		if(!method_exists('XMLRPCProxy', 'sanitizeHttprpcCommandParameter'))
			return;

		foreach(array('stg', 'ttl', 'prp') as $mode)
			$this->assertTrue(XMLRPCProxy::sanitizeHttprpcCommandParameter(
				'd.erase', 'd.erase', $mode) === null,
				$mode.' refuses a state-changing method outside the deny list');
		$aliases = array(
			'get_up_total' => array('name' => 'throttle.global_up.total', 'prm' => 0),
		);
		$this->assertTrue(XMLRPCProxy::sanitizeHttprpcCommandParameter(
			'get_up_total', 'throttle.global_up.total', 'ttl', $aliases)
				=== 'throttle.global_up.total',
			'ttl keeps a mapped stock global getter');
		$this->assertTrue(XMLRPCProxy::sanitizeHttprpcCommandParameter(
			'throttle.global_up.total', 'throttle.global_up.total', 'ttl', $aliases)
				=== 'throttle.global_up.total',
			'ttl keeps the canonical getter already mapped by the frontend');
		$this->assertTrue(XMLRPCProxy::sanitizeHttprpcCommandParameter(
			'get_custom_side_effect', 'get_custom_side_effect', 'stg') === null,
			'a dynamically registered get_* method is not proven read-only by its name');
		$this->assertTrue(XMLRPCProxy::sanitizeHttprpcCommandParameter(
			'get_up_total', 'throttle.global_up.total', 'stg', $aliases)
				=== 'throttle.global_up.total',
			'stg keeps a stock getter from the shipped alias registry');
		$this->assertTrue(XMLRPCProxy::sanitizeHttprpcCommandParameter(
			'd.get_peers_max', 'd.peers_max', 'prp') === 'd.peers_max',
			'prp keeps a mapped download getter');
	}

	public function testMulticallModeAdmitsReadersButRefusesOtherMutators()
	{
		if(!method_exists('XMLRPCProxy', 'sanitizeHttprpcCommandParameter'))
		{
			$this->assertTrue(false, 'httprpc has a shared read-only slot policy');
			return;
		}
		$this->assertTrue(XMLRPCProxy::sanitizeHttprpcCommandParameter(
			'd.custom.if_z=key,imported', 'd.custom.if_z=key,imported', 'list')
				=== 'd.custom.if_z="key","imported"',
			'a fallback argument is data even when it begins with a denied prefix');
		foreach(array(
			'cat=$d.views=',
			'cat="$t.multicall=d.hash=,t.scrape_complete=,cat={#}"',
			'cat="$t.multicall=d.hash=,t.scrape_incomplete=,cat={#}"',
		) as $reader)
			$this->assertTrue(XMLRPCProxy::sanitizeHttprpcCommandParameter(
				$reader, $reader, 'list') === $reader,
				'bundled read expression remains available: '.$reader);
		$this->assertTrue(XMLRPCProxy::sanitizeHttprpcCommandParameter(
			't.get_url=', 't.url=', 'trkall') === 't.url=',
			'trkall keeps a plain reader slot without injecting quotes into its nested cat');
		foreach(array('list', 'fls', 'prs', 'trk') as $mode)
		{
			$this->assertTrue(XMLRPCProxy::sanitizeHttprpcCommandParameter(
				'd.erase=', 'd.erase=', $mode) === null,
				$mode.' refuses a state-changing multicall slot');
			$this->assertTrue(XMLRPCProxy::sanitizeHttprpcCommandParameter(
				'd.get_custom=imported', 'd.get_custom=imported', $mode)
					=== 'd.get_custom="imported"',
				$mode.' keeps a safe reader and quotes its argument');
		}
	}

	public function testCmdParserUsesSharedPolicyBeforeModeDispatch()
	{
		$source = file_get_contents(__DIR__ . '/../../plugins/httprpc/action.php');
		$cmdStart = strpos($source, 'case "cmd":');
		$modeStart = strpos($source, 'switch($mode)');
		$policyCall = strpos($source, 'XMLRPCProxy::sanitizeHttprpcCommandParameter(');
		$aliases = strpos($source, 'rTorrentSettings::get()->aliases');
		$this->assertTrue($cmdStart !== false && $modeStart !== false
			&& $policyCall !== false && $cmdStart < $policyCall && $policyCall < $modeStart,
			'all cmd= values are checked before the mode branches consume $add');
		$this->assertTrue($aliases !== false && $policyCall < $aliases && $aliases < $modeStart,
			'the guard receives the existing exact getter alias registry');
	}
}
