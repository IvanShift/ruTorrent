<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/settings.php');

class RtorrentCompatibilityTest extends TestCase
{
	private function makeSettings($version)
	{
		$reflection = new ReflectionClass('rTorrentSettings');
		$settings = $reflection->newInstanceWithoutConstructor();
		$settings->iVersion = $version;
		$settings->aliases = [];

		$this->loadMethodAliases($settings, 'methods-0.9.4.php');
		if ($version >= 0x0a02) {
			$this->loadMethodAliases($settings, 'methods-0.10.2.php');
		}
		if ($version >= 0x1000 && is_file(__DIR__ . '/../../php/methods-0.16.0.php')) {
			$this->loadMethodAliases($settings, 'methods-0.16.0.php');
		}
		if ($version >= 0x1010) {
			$this->loadMethodAliases($settings, 'methods-0.16.16.php');
		}
		if ($version >= 0x1012) {
			$this->loadMethodAliases($settings, 'methods-0.16.18.php');
		}

		return $settings;
	}

	private function loadMethodAliases($settings, $file)
	{
		$loader = function () use ($file) {
			require __DIR__ . '/../../php/' . $file;
		};
		$loader = $loader->bindTo($settings, get_class($settings));
		$loader();
	}

	private function useSettingsSingleton($settings)
	{
		$property = new ReflectionProperty('rTorrentSettings', 'theSettings');
		if (PHP_VERSION_ID < 80100) {
			$property->setAccessible(true);
		}
		$property->setValue(null, $settings);
	}

	public function testRtorrent016UsesNonDeprecatedRpcCommandNames()
	{
		$settings = $this->makeSettings(0x100e);
		$this->useSettingsSingleton($settings);

		$this->assertEquals('execute', $settings->getCommand('execute'), 'rTorrent 0.16 uses execute directly');
		$this->assertEquals('schedule', $settings->getCommand('schedule'), 'rTorrent 0.16 uses schedule directly');
		$this->assertEquals('schedule.remove', $settings->getCommand('schedule_remove'), 'rTorrent 0.16 uses schedule.remove directly');

		$command = new rXMLRPCCommand('schedule', array('test_schedule', '0', '60', 'system.method'));
		$this->assertEquals('schedule', $command->command, 'rTorrent 0.16 schedule command name stays direct');
		$this->assertEquals('', $command->params[0]->value, 'rTorrent 0.16 schedule keeps an empty target parameter');
		$this->assertEquals('test_schedule', $command->params[1]->value, 'rTorrent 0.16 schedule keeps requested parameters after the empty target');
	}

	public function testRtorrent0102AliasesAreLoadedFor0102AndInheritedBy016()
	{
		foreach (array(0x0a02 => '0.10.2', 0x1000 => '0.16.0') as $version => $label) {
			$settings = $this->makeSettings($version);

			$this->assertEquals('dht.mode.set', $settings->getCommand('dht'), 'rTorrent '.$label.' maps DHT mode command');
			$this->assertEquals('protocol.connection.leech.set', $settings->getCommand('connection_leech'), 'rTorrent '.$label.' maps leech connection command');
		}

		$settings = $this->makeSettings(0x1000);
		$this->assertEquals('group.seeding.ratio.min.set', $settings->getCommand('ratio.min.set'), 'rTorrent 0.16.0 overrides ratio aliases back to group commands');
	}

	public function testRtorrent016UsesGroupRatioCommands()
	{
		$settings = $this->makeSettings(0x100e);
		$this->useSettingsSingleton($settings);

		$aliasCommand = new rXMLRPCCommand('ratio.min.set', 100);
		$this->assertEquals('group.seeding.ratio.min.set', $aliasCommand->command, 'rTorrent 0.16 maps bare ratio setters to group commands');
		$this->assertEquals(1, count($aliasCommand->params), 'rTorrent 0.16 bare ratio value setters send one scalar argument');
		$this->assertEquals('100', $aliasCommand->params[0]->value, 'rTorrent 0.16 bare ratio setters keep the requested value');

		$command = $settings->getRatioGroupCommand('rat_0', 'ratio.min.set', 100);

		$this->assertEquals('group.rat_0.ratio.min.set', $command->command, 'rTorrent 0.16 uses group ratio command names');
		$this->assertEquals(2, count($command->params), 'rTorrent 0.16 group ratio value setters send target and value arguments');
		$this->assertEquals('', $command->params[0]->value, 'rTorrent 0.16 group ratio commands send an empty target argument');
		$this->assertEquals('100', $command->params[1]->value, 'rTorrent 0.16 group ratio commands keep the requested value');
	}

	public function testRtorrent01615UsesSocketManagerCommands()
	{
		$settings = $this->makeSettings(0x100f);
		$this->useSettingsSingleton($settings);

		$this->assertEquals('system.sockets.files.max_size', $settings->getCommand('get_max_open_files'), 'rTorrent 0.16.15 reads file allocation through socket manager');
		$this->assertEquals('system.sockets.files.max_alloc.set', $settings->getCommand('set_max_open_files'), 'rTorrent 0.16.15 writes the file allocation ceiling');
		$this->assertEquals('system.sockets.http.max_alloc.set', $settings->getCommand('set_max_open_http'), 'rTorrent 0.16.15 writes the HTTP allocation ceiling');
		$this->assertEquals('system.sockets.size', $settings->getCommand('network.open_sockets'), 'rTorrent 0.16.15 reads open sockets through socket manager');

		$commands = array(
			new rXMLRPCCommand('set_max_open_files', 600),
			new rXMLRPCCommand('set_max_open_http', 50),
		);
		$settings->patchDeprecatedRequest($commands);

		$this->assertEquals(3, count($commands), 'socket allocation setters append one allocation recalculation command');
		$this->assertEquals('system.sockets.adjust_alloc', $commands[2]->command, 'socket allocation ceilings are applied after setters');
	}

	public function testRtorrent01616UsesCanonicalCommands()
	{
		$settings = $this->makeSettings(0x1010);
		$this->useSettingsSingleton($settings);

		$this->assertEquals('network.proxy.http', $settings->getCommand('get_http_proxy'), 'rTorrent 0.16.16 reads HTTP proxy through proxy manager');
		$this->assertEquals('network.proxy.http.set', $settings->getCommand('set_http_proxy'), 'rTorrent 0.16.16 writes HTTP proxy through proxy manager');
		$this->assertEquals('network.proxy.global', $settings->getCommand('get_proxy_address'), 'rTorrent 0.16.16 reads global proxy through proxy manager');
		$this->assertEquals('network.proxy.global.set', $settings->getCommand('set_proxy_address'), 'rTorrent 0.16.16 writes global proxy through proxy manager');
		$this->assertEquals('d.multicall', $settings->getCommand('d.multicall'), 'rTorrent 0.16.16 uses the canonical download multicall command');
		$this->assertEquals('d.multicall', $settings->getCommand('d.multicall2'), 'rTorrent 0.16.16 maps the legacy download multicall command to the canonical command');

		$command = new rXMLRPCCommand('d.multicall', array('main', 'd.hash='));
		$this->assertEquals('d.multicall', $command->command, 'rTorrent 0.16.16 sends canonical download multicalls');
		$this->assertEquals('', $command->params[0]->value, 'canonical download multicalls keep the required empty target argument');
	}

	public function testRtorrent01618AndLaterLoadCanonicalPortAliasesAtExactThreshold()
	{
		$legacyAliases = array(
			'get_port_range' => 'network.port_range',
			'set_port_range' => 'network.port_range.set',
			'get_port_random' => 'network.port_random',
			'set_port_random' => 'network.port_random.set',
			'get_port_open' => 'network.port_open',
			'set_port_open' => 'network.port_open.set',
			'port_open' => 'network.port_open',
		);
		$settings = $this->makeSettings(0x1011);
		foreach ($legacyAliases as $alias => $command) {
			$this->assertEquals($command, $settings->getCommand($alias), 'rTorrent 0.16.17 keeps '.$alias.' on its legacy command');
		}

		$canonicalAliases = array(
			'get_port_range' => 'network.listen.port.range',
			'set_port_range' => 'network.listen.port.range.set',
			'get_port_random' => 'network.listen.port.random',
			'set_port_random' => 'network.listen.port.random.set',
			'get_port_open' => 'cat',
			'set_port_open' => 'cat',
			'port_open' => 'cat',
		);
		foreach (array(0x1012 => '0.16.18', 0x1013 => '0.16.19') as $version => $label) {
			$settings = $this->makeSettings($version);
			foreach ($canonicalAliases as $alias => $command) {
				$this->assertEquals($command, $settings->getCommand($alias), 'rTorrent '.$label.' maps '.$alias.' to its canonical command');
			}
			$this->assertEquals('system.sockets.max_size', $settings->getCommand('get_max_open_sockets'), 'rTorrent '.$label.' reads the generic socket ceiling without removed allocation commands');
			$this->assertEquals('system.sockets.files.max_alloc.set', $settings->getCommand('set_max_open_files'), 'rTorrent '.$label.' keeps the file-category allocation setter');
		}
	}
}
