<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/xmlrpc.php');
require_once(__DIR__ . '/../../php/xmlrpc_proxy.php');

/**
 * cmd= reaches both multicall slots and direct RPC method names. Keep its
 * policy tied to the raw XMLRPC proxy rather than maintaining another list.
 */
class HttprpcCommandParameterTest extends TestCase
{
	public function testD4CommandExtensionsRequireNativeMethodsForTheDaemonVersion()
	{
		$old = 0x0908;
		$modern = 0x1016;
		$this->assertTrue(XMLRPCProxy::sanitizeHttprpcCommandParameter(
			'd.get_name=', 'd.name=', 'list', array(), $old) === 'd.name=""',
			'httprpc maps a client alias to a native old-daemon reader');
		$this->assertTrue(XMLRPCProxy::sanitizeHttprpcCommandParameter(
			'd.get_name=', 'd.get_name=', 'list', array(), $modern) === null,
			'a client-only spelling cannot become a trusted alias in an extension');
		$this->assertTrue(XMLRPCProxy::sanitizeHttprpcCommandParameter(
			'd.base_path.realpath.or_empty=', 'd.base_path.realpath.or_empty=',
			'list', array(), $old) === null,
			'a newer getter cannot be used as a trusted old-daemon command extension');
		$aliases = array('get_up_total' => array('name' => 'throttle.global_up.total', 'prm' => 0));
		$this->assertTrue(XMLRPCProxy::sanitizeHttprpcCommandParameter(
			'get_up_total', 'throttle.global_up.total', 'ttl', $aliases, $old)
			=== 'throttle.global_up.total',
			'a natively registered old-daemon global reader stays available');
	}

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
				'd.get_custom=imported', 'd.custom=imported', $mode)
					=== 'd.custom="imported"',
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

	// What the substring check answered, kept here as the control: every one
	// of these was admitted, and every one is now refused.
	private function oldFilterWouldAdmit($command)
	{
		return strpos($command, "execute") === false;
	}

	public function testTheSubstringCheckAdmittedTheRefusedFamilies()
	{
		// Not an assertion about the new code: the statement of what was wrong,
		// so that a later change cannot quietly restore it. What the substring
		// check caught, it caught by accident -- a command carrying the word
		// "execute" in an argument was dropped too. What it could not catch is
		// everything that never spells that word.
		foreach(array(
			'import=/etc/passwd',
			'try_import=/etc/passwd',
			'system.shutdown',
			'catch=/bin/id',
			'method.insert=x,simple,"d.name="',
			'method.set_key=event.download.inserted,x,"d.name="',
			'schedule2=x,0,0,"d.name="',
			'log.open_file=x,/tmp/x',
			'network.scgi.open_port=0.0.0.0:5000',
			'session.path.set=/tmp',
			'directory.default.set=/',
			'system.env=PATH',
		) as $command)
			$this->assertTrue($this->oldFilterWouldAdmit($command),
				'the substring check admitted '.$command);
	}

	public function testTheRefusedFamiliesAreRefused()
	{
		foreach(array(
			'execute=/bin/id',
			'execute2=/bin/id',
			'execute.capture=/bin/id',
			'execute.raw.bg=/bin/id',
			'import=/etc/passwd',
			'try_import=/etc/passwd',
			'method.insert=x,simple,"d.name="',
			'method.set_key=event.download.inserted,x,"d.name="',
			'schedule=x,0,0,"d.name="',
			'schedule2=x,0,0,"d.name="',
			'schedule.remove=x',
			'catch=/bin/id',
			'log.execute=/tmp/x',
			'log.open_file=x,/tmp/x',
			'network.scgi.open_port=0.0.0.0:5000',
			'session.path.set=/tmp',
			'directory.default.set=/',
			'system.env=PATH',
			'system.shutdown',
			'system.shutdown.quick',
		) as $command)
			$this->assertTrue(XMLRPCProxy::refusedCommandName($command) !== null,
				$command.' is refused');
	}

	public function testARefusalNamesTheCommandItRefused()
	{
		$this->assertTrue(XMLRPCProxy::refusedCommandName('import=/etc/passwd') === 'import',
			'the refusal of import=/etc/passwd names import');
		$this->assertTrue(XMLRPCProxy::refusedCommandName('d.multicall=main,execute=/bin/id') === 'execute',
			'the refusal of a nested execute names execute, not d.multicall');
	}

	public function testNestedAndDollarIntroducedNamesAreReached()
	{
		foreach(array(
			'$execute=/bin/id',
			'cat=$execute=/bin/id',
			'd.multicall=main,execute=/bin/id',
			'cat="$d.multicall=main,import=/etc/passwd"',
			'branch=1,"execute=/bin/id","d.name="',
			'cat={$system.shutdown}',
		) as $command)
			$this->assertTrue(XMLRPCProxy::refusedCommandName($command) !== null,
				$command.' is refused wherever the name stands');
	}

	public function testWhatRuTorrentItselfSendsIsNotRefused()
	{
		// Every literal ruTorrent registers through theRequestManager.addRequest,
		// in both the spellings theRequestManager.map() produces. A door that
		// refused one of these would cost a column and look like a plugin fault.
		foreach(array(
			'f.prioritize_first=',
			'f.prioritize_last=',
			'd.get_custom=sch_ignore',
			'd.custom=sch_ignore',
			'd.get_custom=chk-state',
			'd.get_custom=chk-time',
			'd.get_custom=seedingtime',
			'd.custom=seedingtime',
			'd.get_custom=addtime',
			'd.get_custom=x-pushbullet',
			'd.get_throttle_name=',
			'd.throttle_name=',
			'cat=$d.views=',
			'cat=$d.get_views=',
			'cat="$t.multicall=d.get_hash=,t.get_scrape_complete=,cat={#}"',
			'cat="$t.multicall=d.get_hash=,t.get_scrape_incomplete=,cat={#}"',
			'd.get_hash=',
			'd.get_name=',
			'd.is_multi_file=',
			't.get_url=',
			'p.get_address=',
		) as $command)
			$this->assertTrue(XMLRPCProxy::refusedCommandName($command) === null,
				$command.' is not refused');
	}

	public function testAnArgumentIsNotMistakenForACommand()
	{
		// A value that begins with a refused word stands where an argument
		// stands, not where a command stands.
		foreach(array(
			'd.get_custom=scheduled',
			'd.get_custom=imported',
			'd.get_custom=logs',
			'd.get_custom=execution',
		) as $command)
			$this->assertTrue(XMLRPCProxy::refusedCommandName($command) === null,
				$command.' is read as an argument, not as a command');
	}

	public function testTheTwoChecksOnACommandNameAreIndependent()
	{
		// php/xmlrpc.php refuses a name that is not shaped like an rtorrent
		// command name; this refuses a name the policy does not allow. Neither
		// answers for the other: "import" is well shaped and refused, and
		// "d.name=" is allowed and not a name at all.
		$this->assertTrue(rXMLRPCCommand::isValidCommandName('import'),
			'import is a well-formed command name');
		$this->assertTrue(XMLRPCProxy::refusedCommandName('import') !== null,
			'and the policy refuses it anyway');
		$this->assertTrue(!rXMLRPCCommand::isValidCommandName('d.multicall=main,execute=x'),
			'a command string is not a well-formed command name');
		$this->assertTrue(XMLRPCProxy::refusedCommandName('d.multicall=main,execute=x') !== null,
			'and the policy refuses what it carries');
	}
}
