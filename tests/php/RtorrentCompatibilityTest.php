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

		$command = $settings->getRatioGroupCommand('rat_0', 'ratio.min.set', 100);

		$this->assertEquals('group.rat_0.ratio.min.set', $command->command, 'rTorrent 0.16 uses group ratio command names');
		$this->assertEquals('', $command->params[0]->value, 'rTorrent 0.16 group ratio commands keep an empty target parameter');
		$this->assertEquals('100', $command->params[1]->value, 'rTorrent 0.16 group ratio commands keep the requested value');
	}
}
