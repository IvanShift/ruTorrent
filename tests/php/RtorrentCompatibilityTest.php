<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/settings.php');

class RtorrentCompatibilityTest extends TestCase
{
	/**
	 * settings.php loads the per-version method alias files from obtain(),
	 * which interrogates a live daemon over XMLRPC before it reaches the
	 * require_once ladder, so the ladder cannot be executed directly here.
	 * Instead, parse the version => file gate table out of the source and
	 * replay it, so the constants under test are the real ones and a wrong
	 * gate in settings.php fails these tests.
	 */
	private function methodFileGates()
	{
		// This fork carries one downlevel gate (methods-pre-0.9.0.php loads
		// under iVersion < 0x900), so both comparison directions are parsed.
		$source = file_get_contents(__DIR__ . '/../../php/settings.php');
		preg_match_all(
			'/if\s*\(\s*\$this->iVersion\s*(>=|<)\s*(0x[0-9A-Fa-f]+)\s*\)\s*\{\s*require_once\(\s*\'(methods-[^\']+\.php)\'\s*\);/',
			$source,
			$matches,
			PREG_SET_ORDER
		);
		if (count($matches) < 4) {
			throw new Exception('Could not parse the method alias gates out of php/settings.php');
		}
		$gates = array();
		foreach ($matches as $match) {
			$gates[] = array('op' => $match[1], 'version' => intval($match[2], 16), 'file' => $match[3]);
		}
		return $gates;
	}

	/**
	 * The require ladder is not the whole map. obtain() assigns two aliases
	 * inline, under a gate of its own (iVersion > 0x806), before it reaches
	 * the ladder -- so a walk that only replays the ladder silently misses
	 * them and every count taken off it is two short. Parse that block out
	 * of the source for the same reason methodFileGates() parses the gates:
	 * the entries under test have to be the real ones.
	 */
	private function seedAliases()
	{
		$source = file_get_contents(__DIR__ . '/../../php/settings.php');
		if (!preg_match('/if\s*\(\s*\$this->iVersion\s*>\s*(0x[0-9A-Fa-f]+)\s*\)\s*\{\s*\$this->aliases\s*=\s*array\s*\((.*?)\);/s', $source, $block)) {
			throw new Exception('Could not parse the inline alias seed out of php/settings.php');
		}
		preg_match_all('/"([^"]+)"\s*=>\s*array\s*\(\s*"name"\s*=>\s*"([^"]*)"\s*,\s*"prm"\s*=>\s*(\d+)/', $block[2], $entries, PREG_SET_ORDER);
		if (!count($entries)) {
			throw new Exception('The inline alias seed in php/settings.php parsed to nothing');
		}
		$aliases = array();
		foreach ($entries as $entry) {
			$aliases[$entry[1]] = array('name' => $entry[2], 'prm' => intval($entry[3]));
		}
		return array('version' => intval($block[1], 16), 'aliases' => $aliases);
	}

	private function makeSettings($version)
	{
		$reflection = new ReflectionClass('rTorrentSettings');
		$settings = $reflection->newInstanceWithoutConstructor();
		$settings->iVersion = $version;
		$settings->aliases = [];

		$seed = $this->seedAliases();
		if ($version > $seed['version']) {
			$settings->aliases = $seed['aliases'];
		}

		foreach ($this->methodFileGates() as $gate) {
			$applies = ($gate['op'] === '>=')
				? ($version >= $gate['version'])
				: ($version < $gate['version']);
			if ($applies) {
				$this->loadMethodAliases($settings, $gate['file']);
			}
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

	public function testRtorrent0102MethodAliasGateUsesBytewiseVersion()
	{
		// iVersion packs one version component per byte, so 0.10.2 is 0x0a02
		// (0x1002 would be 0.16.2) and 0.10.1 is 0x0a01.
		foreach (array(0x908 => '0.9.8', 0x0a01 => '0.10.1') as $version => $label) {
			$settings = $this->makeSettings($version);
			$this->assertEquals('dht', $settings->getCommand('dht'), 'rTorrent '.$label.' predates the 0.10.2 aliases so dht stays unmapped');
			$this->assertEquals('connection_leech', $settings->getCommand('connection_leech'), 'rTorrent '.$label.' predates the 0.10.2 aliases so connection_leech stays unmapped');
		}

		$settings = $this->makeSettings(0x0a02);
		$this->assertEquals('dht.mode.set', $settings->getCommand('dht'), 'rTorrent 0.10.2 itself gets its dht alias');
		$this->assertEquals('group2.seeding.ratio.min.set', $settings->getCommand('ratio.min.set'), 'rTorrent 0.10.2 itself gets its ratio aliases');
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

	public function testRtorrent016RatioSettersPrependAnEmptyTarget()
	{
		// rtorrent 0.16's XMLRPC dispatcher (src/rpc/xmlrpc_tinyxml2.cc)
		// unconditionally parses the FIRST param of any call as the target,
		// so group.NAME.ratio.*.set must be sent as ('', value); a bare
		// (value) faults with "invalid parameters: target must be a string".
		// getRatioGroupCommand in php/settings.php prepends '' for the same
		// reason. This pins the shape on both the alias path (prm=1 makes
		// rXMLRPCCommand prepend the empty target) and the group-command
		// path — this fork once got the alias shape wrong (prm=0), which
		// this test now guards against.
		$settings = $this->makeSettings(0x100e);
		$this->useSettingsSingleton($settings);

		$aliasCommand = new rXMLRPCCommand('ratio.min.set', 100);
		$this->assertEquals('group.seeding.ratio.min.set', $aliasCommand->command, 'rTorrent 0.16 maps bare ratio setters to group commands');
		$this->assertEquals(2, count($aliasCommand->params), 'rTorrent 0.16 ratio setters send target and value arguments');
		$this->assertEquals('', $aliasCommand->params[0]->value, 'rTorrent 0.16 ratio setters prepend the empty target the dispatcher requires');
		$this->assertEquals('100', $aliasCommand->params[1]->value, 'rTorrent 0.16 ratio setters keep the requested value after the target');

		$command = $settings->getRatioGroupCommand('rat_0', 'ratio.min.set', 100);

		$this->assertEquals('group.rat_0.ratio.min.set', $command->command, 'rTorrent 0.16 uses group ratio command names');
		$this->assertEquals(2, count($command->params), 'rTorrent 0.16 group ratio value setters send target and value arguments');
		$this->assertEquals('', $command->params[0]->value, 'rTorrent 0.16 group ratio commands send an empty target argument');
		$this->assertEquals('100', $command->params[1]->value, 'rTorrent 0.16 group ratio commands keep the requested value');
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
		foreach (array(0x1012 => '0.16.18', 0x1013 => '0.16.19', 0x1015 => '0.16.21') as $version => $label) {
			$settings = $this->makeSettings($version);
			foreach ($canonicalAliases as $alias => $command) {
				$this->assertEquals($command, $settings->getCommand($alias), 'rTorrent '.$label.' maps '.$alias.' to its canonical command');
			}
			$this->assertEquals('system.sockets.max_size', $settings->getCommand('get_max_open_sockets'), 'rTorrent '.$label.' reads the generic socket ceiling without removed allocation commands');
			$this->assertEquals('system.sockets.files.max_alloc.set', $settings->getCommand('set_max_open_files'), 'rTorrent '.$label.' keeps the file-category allocation setter');
		}
	}

	public function testPartiallyDoneCommandIsANoOpBelowRtorrent090()
	{
		$settings = $this->makeSettings(0x809);
		$this->assertEquals('cat', $settings->getCommand('d.is_partially_done'), 'rTorrent 0.8.9 answers the partially done question with the no-op cat');
		$this->assertEquals('cat=', $settings->getCommand('d.is_partially_done='), 'the no-op keeps the trailing = so the multicall field stays in place');

		foreach (array(0x900 => '0.9.0', 0x908 => '0.9.8', 0x1014 => '0.16.20', 0x1015 => '0.16.21') as $version => $label) {
			$settings = $this->makeSettings($version);
			$this->assertEquals('d.is_partially_done', $settings->getCommand('d.is_partially_done'), 'rTorrent '.$label.' asks the daemon directly');
		}
	}

	/**
	 * Every name a stock rTorrent 0.16.20 registers: the verbatim answer to
	 * system.listMethods from the daemon, 982 names, sorted. It is the oracle
	 * for "does this alias target exist" -- the alias tables are hand-maintained
	 * lists of strings, and nothing but a real registry can tell a working mapping
	 * from a typo or from a name that a later rtorrent quietly stopped registering.
	 *
	 * 0.16.21 adds names, it removes none, so a 0.16.20 registry is the conservative
	 * oracle for the whole 0.16.18+ generation: every name it contains is still
	 * registered further up.
	 */
	private function stockDaemonMethodList()
	{
		static $list = null;
		if ($list === null) {
			// Keep boundary blank rows visible to the fixture-integrity test.
			$list = explode("\n", str_replace("\r\n", "\n", <<<'LISTMETHODS'
add_peer
and
argument.0
argument.1
argument.2
argument.3
branch
cat
catch
check_hash
choke_group.all.down.update_balance
choke_group.all.up.update_balance
choke_group.down.heuristics
choke_group.down.heuristics.set
choke_group.down.max
choke_group.down.max.set
choke_group.down.max.unlimited
choke_group.down.queued
choke_group.down.rate
choke_group.down.total
choke_group.down.unchoked
choke_group.general.size
choke_group.index_of
choke_group.insert
choke_group.list
choke_group.size
choke_group.tracker.mode
choke_group.tracker.mode.set
choke_group.up.heuristics
choke_group.up.heuristics.set
choke_group.up.max
choke_group.up.max.set
choke_group.up.max.unlimited
choke_group.up.queued
choke_group.up.rate
choke_group.up.total
choke_group.up.unchoked
close_low_diskspace
close_low_diskspace.normal
close_untied
compare
connection_leech
connection_seed
convert.date
convert.elapsed_time
convert.gm_date
convert.gm_time
convert.kb
convert.mb
convert.throttle
convert.time
convert.xb
d.accepting_seeders
d.accepting_seeders.disable
d.accepting_seeders.enable
d.base_filename
d.base_filename.as_binary
d.base_filename.base64
d.base_filename.base64_as_binary
d.base_filename.hex
d.base_filename.or_as_binary
d.base_filename.or_base64
d.base_path
d.base_path.as_binary
d.base_path.base64
d.base_path.base64_as_binary
d.base_path.hex
d.base_path.or_as_binary
d.base_path.or_base64
d.bitfield
d.bytes_done
d.check_hash
d.chunk_size
d.chunks_hashed
d.chunks_seen
d.close
d.close.directly
d.complete
d.complete.set
d.completed_bytes
d.completed_chunks
d.connection_current
d.connection_current.set
d.connection_leech
d.connection_leech.set
d.connection_seed
d.connection_seed.set
d.create_link
d.creation_date
d.custom
d.custom.if_z
d.custom.items
d.custom.keys
d.custom.set
d.custom1
d.custom1.set
d.custom2
d.custom2.set
d.custom3
d.custom3.set
d.custom4
d.custom4.set
d.custom5
d.custom5.set
d.custom_throw
d.delete_link
d.delete_tied
d.directory
d.directory.set
d.directory_base
d.directory_base.set
d.disconnect.seeders
d.down.choke_heuristics
d.down.choke_heuristics.leech
d.down.choke_heuristics.leech.set
d.down.choke_heuristics.seed
d.down.choke_heuristics.seed.set
d.down.choke_heuristics.set
d.down.rate
d.down.total
d.downloads_max
d.downloads_max.set
d.downloads_min
d.downloads_min.set
d.erase
d.free_diskspace
d.group
d.group.name
d.group.set
d.hash
d.hashing
d.hashing.set
d.hashing_failed
d.hashing_failed.set
d.ignore_commands
d.ignore_commands.set
d.incomplete
d.is_active
d.is_hash_checked
d.is_hash_checking
d.is_meta
d.is_multi_file
d.is_not_partially_done
d.is_open
d.is_partially_done
d.is_pex_active
d.is_private
d.left_bytes
d.load_date
d.loaded_file
d.loaded_file.set
d.local_id
d.local_id_html
d.max_file_size
d.max_file_size.set
d.max_size_pex
d.message
d.message.set
d.mode
d.mode.set
d.multicall
d.multicall.filtered
d.multicall2
d.name
d.name.as_binary
d.name.base64
d.name.base64_as_binary
d.name.hex
d.name.or_as_binary
d.name.or_base64
d.open
d.pause
d.peer_exchange
d.peer_exchange.set
d.peers_accounted
d.peers_complete
d.peers_connected
d.peers_max
d.peers_max.set
d.peers_min
d.peers_min.set
d.peers_not_connected
d.priority
d.priority.set
d.priority_str
d.ratio
d.resume
d.save_full_session
d.save_resume
d.size_bytes
d.size_chunks
d.size_files
d.size_pex
d.skip.rate
d.skip.total
d.start
d.state
d.state.set
d.state_changed
d.state_changed.set
d.state_counter
d.state_counter.set
d.stop
d.throttle_name
d.throttle_name.set
d.tied_to_file
d.tied_to_file.set
d.timestamp.finished
d.timestamp.finished.elapsed
d.timestamp.finished.or_zero
d.timestamp.finished.set
d.timestamp.finished.set_if_z
d.timestamp.started
d.timestamp.started.elapsed
d.timestamp.started.or_zero
d.timestamp.started.set
d.timestamp.started.set_if_z
d.tracker.has_active
d.tracker.has_active_not_scrape
d.tracker.has_usable
d.tracker.insert
d.tracker.send_scrape
d.tracker_announce
d.tracker_announce.force
d.tracker_focus
d.tracker_numwant
d.tracker_numwant.set
d.tracker_size
d.try_close
d.try_start
d.try_stop
d.up.choke_heuristics
d.up.choke_heuristics.leech
d.up.choke_heuristics.leech.set
d.up.choke_heuristics.seed
d.up.choke_heuristics.seed.set
d.up.choke_heuristics.set
d.up.rate
d.up.total
d.update_priorities
d.uploads_max
d.uploads_max.set
d.uploads_min
d.uploads_min.set
d.views
d.views.has
d.views.push_back
d.views.push_back_unique
d.views.remove
d.wanted_chunks
dht.add_node
dht.mode.set
dht.override_port
dht.override_port.set
dht.port
dht.port.set
dht.statistics
directory
directory.default
directory.default.set
directory.watch.added
directory.watch.ready
download_list
download_rate
elapsed.greater
elapsed.less
encryption
enum.log_group
equal
event.download.closed
event.download.erased
event.download.finished
event.download.hash_done
event.download.hash_failed
event.download.hash_final_failed
event.download.hash_queued
event.download.hash_removed
event.download.inserted
event.download.inserted_new
event.download.inserted_session
event.download.opened
event.download.paused
event.download.resumed
event.system.shutdown
event.system.startup_done
event.view.hide
event.view.show
execute
execute.capture
execute.capture_nothrow
execute.nothrow
execute.nothrow.bg
execute.raw
execute.raw.bg
execute.raw_nothrow
execute.raw_nothrow.bg
execute.throw
execute.throw.bg
f.completed_chunks
f.frozen_path
f.frozen_path.as_binary
f.frozen_path.base64
f.frozen_path.hex
f.frozen_path.or_as_binary
f.frozen_path.or_base64
f.is_create_queued
f.is_created
f.is_open
f.is_resize_queued
f.last_touched
f.match_depth_next
f.match_depth_prev
f.multicall
f.offset
f.path
f.path_components
f.path_components.as_binary
f.path_components.base64
f.path_components.base64_as_binary
f.path_components.hex
f.path_components.or_as_binary
f.path_components.or_base64
f.path_depth
f.prioritize_first
f.prioritize_first.disable
f.prioritize_first.enable
f.prioritize_last
f.prioritize_last.disable
f.prioritize_last.enable
f.priority
f.priority.set
f.range_first
f.range_second
f.set_create_queued
f.set_resize_queued
f.size_bytes
f.size_chunks
f.unset_create_queued
f.unset_resize_queued
false
fi.filename_last
fi.is_file
file.prioritize_toc
file.prioritize_toc.first
file.prioritize_toc.first.push_back
file.prioritize_toc.first.set
file.prioritize_toc.last
file.prioritize_toc.last.push_back
file.prioritize_toc.last.set
file.prioritize_toc.set
greater
group.insert
group.insert_persistent_view
group.rat_0.ratio.command
group.rat_0.ratio.disable
group.rat_0.ratio.enable
group.rat_0.ratio.max
group.rat_0.ratio.max.set
group.rat_0.ratio.min
group.rat_0.ratio.min.set
group.rat_0.ratio.upload
group.rat_0.ratio.upload.set
group.rat_0.view
group.rat_0.view.set
group.rat_1.ratio.command
group.rat_1.ratio.disable
group.rat_1.ratio.enable
group.rat_1.ratio.max
group.rat_1.ratio.max.set
group.rat_1.ratio.min
group.rat_1.ratio.min.set
group.rat_1.ratio.upload
group.rat_1.ratio.upload.set
group.rat_1.view
group.rat_1.view.set
group.rat_2.ratio.command
group.rat_2.ratio.disable
group.rat_2.ratio.enable
group.rat_2.ratio.max
group.rat_2.ratio.max.set
group.rat_2.ratio.min
group.rat_2.ratio.min.set
group.rat_2.ratio.upload
group.rat_2.ratio.upload.set
group.rat_2.view
group.rat_2.view.set
group.rat_3.ratio.command
group.rat_3.ratio.disable
group.rat_3.ratio.enable
group.rat_3.ratio.max
group.rat_3.ratio.max.set
group.rat_3.ratio.min
group.rat_3.ratio.min.set
group.rat_3.ratio.upload
group.rat_3.ratio.upload.set
group.rat_3.view
group.rat_3.view.set
group.rat_4.ratio.command
group.rat_4.ratio.disable
group.rat_4.ratio.enable
group.rat_4.ratio.max
group.rat_4.ratio.max.set
group.rat_4.ratio.min
group.rat_4.ratio.min.set
group.rat_4.ratio.upload
group.rat_4.ratio.upload.set
group.rat_4.view
group.rat_4.view.set
group.rat_5.ratio.command
group.rat_5.ratio.disable
group.rat_5.ratio.enable
group.rat_5.ratio.max
group.rat_5.ratio.max.set
group.rat_5.ratio.min
group.rat_5.ratio.min.set
group.rat_5.ratio.upload
group.rat_5.ratio.upload.set
group.rat_5.view
group.rat_5.view.set
group.rat_6.ratio.command
group.rat_6.ratio.disable
group.rat_6.ratio.enable
group.rat_6.ratio.max
group.rat_6.ratio.max.set
group.rat_6.ratio.min
group.rat_6.ratio.min.set
group.rat_6.ratio.upload
group.rat_6.ratio.upload.set
group.rat_6.view
group.rat_6.view.set
group.rat_7.ratio.command
group.rat_7.ratio.disable
group.rat_7.ratio.enable
group.rat_7.ratio.max
group.rat_7.ratio.max.set
group.rat_7.ratio.min
group.rat_7.ratio.min.set
group.rat_7.ratio.upload
group.rat_7.ratio.upload.set
group.rat_7.view
group.rat_7.view.set
group.seeding.ratio.command
group.seeding.ratio.disable
group.seeding.ratio.enable
group.seeding.ratio.max
group.seeding.ratio.max.set
group.seeding.ratio.min
group.seeding.ratio.min.set
group.seeding.ratio.upload
group.seeding.ratio.upload.set
group.seeding.view
group.seeding.view.set
if
import
ip_tables.add_address
ip_tables.get
ip_tables.insert_table
ip_tables.size_data
ipv4_filter.add_address
ipv4_filter.dump
ipv4_filter.get
ipv4_filter.load
ipv4_filter.size_data
keys.layout
keys.layout.set
less
load.normal
load.raw
load.raw_start
load.raw_start_verbose
load.raw_verbose
load.start
load.start_verbose
load.verbose
log.add_output
log.append_file
log.append_file.flush
log.append_gz_file
log.close
log.execute
log.open_file
log.open_file.flush
log.open_file_pid
log.open_gz_file
log.open_gz_file_pid
log.print
log.rpc
log.vmmap.dump
log.xmlrpc
magnet.path
magnet.path.set
match
math.add
math.avg
math.cnt
math.div
math.max
math.med
math.min
math.mod
math.mul
math.sub
max_downloads
max_downloads_div
max_downloads_global
max_peers
max_peers_seed
max_uploads
max_uploads_div
max_uploads_global
method.const
method.const.enable
method.erase
method.get
method.has_key
method.insert
method.insert.bool
method.insert.c_simple
method.insert.list
method.insert.s_c_simple
method.insert.simple
method.insert.string
method.insert.value
method.list_keys
method.redirect
method.rlookup
method.rlookup.clear
method.set
method.set_key
method.use_deprecated
method.use_deprecated.set
method.use_intermediate
method.use_intermediate.set
min_downloads
min_peers
min_peers_seed
min_uploads
network.bind_address
network.bind_address.ipv4
network.bind_address.ipv4.set
network.bind_address.ipv6
network.bind_address.ipv6.set
network.bind_address.set
network.block.ipv4
network.block.ipv4.set
network.block.ipv4in6
network.block.ipv4in6.set
network.block.ipv6
network.block.ipv6.set
network.block.outgoing
network.block.outgoing.set
network.http.cacert
network.http.cacert.set
network.http.capath
network.http.capath.set
network.http.current_open
network.http.dns_cache_timeout
network.http.dns_cache_timeout.set
network.http.max_cache_connections
network.http.max_cache_connections.set
network.http.max_host_connections
network.http.max_host_connections.set
network.http.max_total_connections
network.http.max_total_connections.set
network.http.proxy_address
network.http.proxy_address.set
network.http.ssl_verify_host
network.http.ssl_verify_host.set
network.http.ssl_verify_peer
network.http.ssl_verify_peer.set
network.listen.backlog
network.listen.backlog.set
network.listen.port
network.listen.port.random
network.listen.port.random.set
network.listen.port.range
network.listen.port.range.set
network.listen.port.set
network.local_address
network.local_address.ipv4
network.local_address.ipv4.set
network.local_address.ipv6
network.local_address.ipv6.set
network.local_address.set
network.max_open_files
network.max_open_files.set
network.max_open_sockets
network.max_open_sockets.set
network.open_files
network.open_sockets
network.prefer.ipv6
network.prefer.ipv6.set
network.proxy.global
network.proxy.global.set
network.proxy.http
network.proxy.http.set
network.receive_buffer.size
network.receive_buffer.size.set
network.rpc.use_jsonrpc
network.rpc.use_jsonrpc.set
network.rpc.use_xmlrpc
network.rpc.use_xmlrpc.set
network.scgi.dont_route
network.scgi.dont_route.set
network.scgi.gzip.min_size
network.scgi.gzip.min_size.set
network.scgi.open_local
network.scgi.open_port
network.scgi.open_systemd
network.scgi.use_gzip
network.scgi.use_gzip.set
network.send_buffer.size
network.send_buffer.size.set
network.tos.set
network.total_handshakes
network.xmlrpc.dialect.set
network.xmlrpc.size_limit
network.xmlrpc.size_limit.set
not
on_ratio
or
p.address
p.banned
p.banned.set
p.call_target
p.client_version
p.completed_percent
p.disconnect
p.disconnect_delayed
p.down_rate
p.down_total
p.id
p.id_html
p.is_encrypted
p.is_incoming
p.is_obfuscated
p.is_preferred
p.is_snubbed
p.is_unwanted
p.multicall
p.options_str
p.peer_rate
p.peer_total
p.port
p.snubbed
p.snubbed.set
p.up_rate
p.up_total
pieces.hash.on_completion
pieces.hash.on_completion.set
pieces.hash.queue_size
pieces.memory.block_count
pieces.memory.current
pieces.memory.max
pieces.memory.max.set
pieces.memory.sync_queue
pieces.preload.min_rate
pieces.preload.min_rate.set
pieces.preload.min_size
pieces.preload.min_size.set
pieces.preload.type
pieces.preload.type.set
pieces.stats.total_size
pieces.stats_not_preloaded
pieces.stats_preloaded
pieces.sync.always_safe
pieces.sync.always_safe.set
pieces.sync.queue_size
pieces.sync.safe_free_diskspace
pieces.sync.timeout
pieces.sync.timeout.set
pieces.sync.timeout_safe
pieces.sync.timeout_safe.set
port_range
print
protocol.choke_heuristics.down.leech
protocol.choke_heuristics.down.leech.set
protocol.choke_heuristics.down.seed
protocol.choke_heuristics.down.seed.set
protocol.choke_heuristics.up.leech
protocol.choke_heuristics.up.leech.set
protocol.choke_heuristics.up.seed
protocol.choke_heuristics.up.seed.set
protocol.connection.leech
protocol.connection.leech.set
protocol.connection.seed
protocol.connection.seed.set
protocol.encryption
protocol.encryption.handshake
protocol.encryption.set
protocol.encryption.stream
protocol.pex
protocol.pex.set
ratio.disable
ratio.enable
ratio.max
ratio.max.set
ratio.min
ratio.min.set
ratio.upload
ratio.upload.set
remove_untied
scgi_local
scgi_port
schedule
schedule.remove
scheduler.max_active
scheduler.max_active.set
scheduler.simple.added
scheduler.simple.removed
scheduler.simple.update
session
session.name
session.name.set
session.on_completion
session.on_completion.set
session.path
session.path.set
session.save
session.use_lock
session.use_lock.set
start_tied
stop_untied
strings.choke_heuristics
strings.choke_heuristics.download
strings.choke_heuristics.upload
strings.connection_type
strings.encryption
strings.encryption.handshake
strings.encryption.stream
strings.ip_filter
strings.ip_tos
strings.log_group
strings.tracker_event
strings.tracker_mode
system.api_version
system.client_version
system.cwd
system.cwd.set
system.daemon
system.daemon.set
system.env
system.file.allocate
system.file.allocate.set
system.file.max_size
system.file.max_size.set
system.file.split_size
system.file.split_size.set
system.file.split_suffix
system.file.split_suffix.set
system.file_status_cache.prune
system.file_status_cache.size
system.files.advise_random
system.files.advise_random.hashing
system.files.advise_random.hashing.set
system.files.advise_random.set
system.files.closed_counter
system.files.failed_counter
system.files.opened_counter
system.files.session.fdatasync
system.files.session.fdatasync.set
system.hostname
system.library_version
system.listMethods
system.multicall
system.pid
system.shutdown
system.shutdown.normal
system.shutdown.quick
system.sockets.adjust_alloc
system.sockets.available_alloc
system.sockets.files.max_alloc
system.sockets.files.max_alloc.set
system.sockets.files.max_size
system.sockets.files.min_alloc
system.sockets.files.min_alloc.set
system.sockets.files.size
system.sockets.generic.max_size
system.sockets.generic.min_alloc
system.sockets.generic.size
system.sockets.http.max_alloc
system.sockets.http.max_alloc.set
system.sockets.http.max_size
system.sockets.http.min_alloc
system.sockets.http.min_alloc.set
system.sockets.http.size
system.sockets.internal.max_alloc
system.sockets.internal.max_alloc.set
system.sockets.internal.max_size
system.sockets.internal.min_alloc
system.sockets.internal.min_alloc.set
system.sockets.internal.size
system.sockets.max_size
system.sockets.max_size.set
system.sockets.reserved_alloc
system.sockets.rpc.max_alloc
system.sockets.rpc.max_alloc.set
system.sockets.rpc.max_size
system.sockets.rpc.min_alloc
system.sockets.rpc.min_alloc.set
system.sockets.rpc.size
system.sockets.size
system.time
system.time_seconds
system.time_usec
system.umask.set
t.activity_time_last
t.activity_time_next
t.can_scrape
t.disable
t.enable
t.failed_counter
t.failed_time_last
t.failed_time_next
t.group
t.id
t.is_busy
t.is_enabled
t.is_enabled.set
t.is_extra_tracker
t.is_open
t.is_scrapable
t.is_usable
t.latest_event
t.latest_new_peers
t.latest_sum_peers
t.min_interval
t.multicall
t.normal_interval
t.scrape_complete
t.scrape_counter
t.scrape_downloaded
t.scrape_incomplete
t.scrape_time_last
t.success_counter
t.success_time_last
t.success_time_next
t.type
t.url
throttle.down
throttle.down.max
throttle.down.rate
throttle.global_down.max_rate
throttle.global_down.max_rate.set
throttle.global_down.max_rate.set_kb
throttle.global_down.rate
throttle.global_down.total
throttle.global_up.max_rate
throttle.global_up.max_rate.set
throttle.global_up.max_rate.set_kb
throttle.global_up.rate
throttle.global_up.total
throttle.max_downloads
throttle.max_downloads.div
throttle.max_downloads.div._val
throttle.max_downloads.div._val.set
throttle.max_downloads.div.set
throttle.max_downloads.global
throttle.max_downloads.global._val
throttle.max_downloads.global._val.set
throttle.max_downloads.global.set
throttle.max_downloads.set
throttle.max_peers.normal
throttle.max_peers.normal.set
throttle.max_peers.seed
throttle.max_peers.seed.set
throttle.max_unchoked_downloads
throttle.max_unchoked_uploads
throttle.max_uploads
throttle.max_uploads.div
throttle.max_uploads.div._val
throttle.max_uploads.div._val.set
throttle.max_uploads.div.set
throttle.max_uploads.global
throttle.max_uploads.global._val
throttle.max_uploads.global._val.set
throttle.max_uploads.global.set
throttle.max_uploads.set
throttle.min_downloads
throttle.min_downloads.set
throttle.min_peers.normal
throttle.min_peers.normal.set
throttle.min_peers.seed
throttle.min_peers.seed.set
throttle.min_uploads
throttle.min_uploads.set
throttle.unchoked_downloads
throttle.unchoked_uploads
throttle.up
throttle.up.max
throttle.up.rate
to_date
to_elapsed_time
to_gm_date
to_gm_time
to_kb
to_mb
to_throttle
to_time
to_xb
trackers.delay_scrape
trackers.delay_scrape.set
trackers.disable
trackers.enable
trackers.numwant
trackers.numwant.set
trackers.use_udp
trackers.use_udp.set
try
try_import
ui.color.alarm
ui.color.alarm.set
ui.color.complete
ui.color.complete.set
ui.color.even
ui.color.even.set
ui.color.focus
ui.color.focus.set
ui.color.footer
ui.color.footer.set
ui.color.incomplete
ui.color.incomplete.set
ui.color.info
ui.color.info.set
ui.color.label
ui.color.label.set
ui.color.leeching
ui.color.leeching.set
ui.color.odd
ui.color.odd.set
ui.color.queued
ui.color.queued.set
ui.color.seeding
ui.color.seeding.set
ui.color.stopped
ui.color.stopped.set
ui.color.title
ui.color.title.set
ui.current_view
ui.current_view.set
ui.focus.page_size
ui.focus.page_size.set
ui.input.history.clear
ui.input.history.size
ui.input.history.size.set
ui.keymap.style
ui.keymap.style.set
ui.status.throttle.down.set
ui.status.throttle.up.set
ui.throttle.global.step.large
ui.throttle.global.step.large.set
ui.throttle.global.step.medium
ui.throttle.global.step.medium.set
ui.throttle.global.step.small
ui.throttle.global.step.small.set
ui.torrent_list.layout
ui.torrent_list.layout.set
ui.unfocus_download
upload_rate
value
view.add
view.event_added
view.event_removed
view.filter
view.filter.temp
view.filter.temp.excluded
view.filter.temp.excluded.set
view.filter.temp.log
view.filter.temp.log.set
view.filter_all
view.filter_download
view.filter_on
view.list
view.persistent
view.set
view.set_not_visible
view.set_visible
view.size
view.size_not_visible
view.sort
view.sort_current
view.sort_new
LISTMETHODS
			));
		}
		return $list;
	}

	private function stockDaemonMethods()
	{
		static $methods = null;
		if ($methods === null) {
			$methods = array_flip($this->stockDaemonMethodList());
		}
		return $methods;
	}

	public function testStockDaemonMethodFixtureIsCompleteUniqueAndSorted()
	{
		$methods = $this->stockDaemonMethodList();
		$this->assertEquals(982, count($methods), 'fixture contains exactly 982 names');
		$this->assertEquals(982, count(array_unique($methods)), 'fixture contains no duplicate names');
		$sorted = $methods;
		sort($sorted, SORT_STRING);
		$this->assertEquals($sorted, $methods, 'fixture is sorted in LC_ALL=C order');
		foreach ($methods as $name) {
			$this->assertTrue(is_string($name) && strlen($name) > 0, 'fixture entries are non-empty strings');
		}
	}

	/**
	 * The alias keys that resolve to the deprecated-only targets, so the
	 * dormancy claim below can be checked rather than asserted.
	 */
	private function deprecatedOnlyAliases()
	{
		// rtorrent registers these three names only inside
		// if(rpc::call_command_value("method.use_deprecated") == 1)
		// (src/main.cc, main(), method.use_deprecated gate). Since 0.16.14
		// that flag can only be
		// turned on with the -D launch option, not from the rc file. A stock
		// daemon answers "Method not defined" to all three, which faults
		// the whole batch they were sent in.
		//
		// They are dormant, not broken: the keys below appear nowhere but the
		// two alias tables, so nothing ever asks for them. The pairing is
		// pinned as an exact set precisely because that is a fragile kind of
		// safe -- a FOURTH such target, or a first caller of one of these
		// keys, has to fail loudly here rather than in a user's settings
		// panel.
		return array(
			'get_dht_throttle' => 'dht.throttle.name',
			'set_dht_throttle' => 'dht.throttle.name.set',
			'throttle_ip'      => 'throttle.ip',
		);
	}

	public function testEveryAliasTargetIsANameTheDaemonRegisters()
	{
		// 23 of the 310 effective aliases used to be asserted by name, and
		// nothing at all checked that a target is a command the daemon
		// actually has. Walk the whole map instead and hold every target
		// against a real registry.
		//
		// Only 0.16.18 and later are held against it: 0.16.16 and below map
		// the port commands to network.port_range and friends, which 0.16.18
		// removed, so a 0.16.20 registry is the wrong oracle down there. The
		// shape of those older maps is covered by the walk further below.
		$deprecatedOnly = array_values($this->deprecatedOnlyAliases());
		sort($deprecatedOnly);

		foreach (array(0x1012 => '0.16.18', 0x1013 => '0.16.19', 0x1014 => '0.16.20', 0x1015 => '0.16.21') as $version => $label) {
			$settings = $this->makeSettings($version);

			// getCommand() indexes straight into $entry['name'], so an entry
			// that is not an array at all takes the whole test FILE down with
			// a TypeError before the walk below can name it -- and a fatal
			// here would also swallow the shape report further down. Collect
			// those and keep walking; their shape is asserted properly by
			// testEveryAliasEntryIsWellFormedAtEveryVersionGate.
			$targets = array();
			$unreadable = array();
			foreach ($settings->aliases as $alias => $entry) {
				if (!is_array($entry) || !array_key_exists('name', $entry)) {
					$unreadable[] = $alias;
					continue;
				}
				$targets[$settings->getCommand($alias)] = true;
			}
			$this->assertEquals(array(), $unreadable, 'rTorrent '.$label.' alias entries all carry a readable target; unreadable: '.implode(', ', $unreadable));

			// deprecatedOnlyAliases() is written as key => target, but the
			// two halves are consumed apart: the targets exempt names from
			// the registry check, the keys drive the dormancy scan. Nothing
			// tied a key to its target, so renaming a key in the alias table
			// left this test green and the scan looking for a string that no
			// longer exists. Assert the pairing, so a rename lands here.
			foreach ($this->deprecatedOnlyAliases() as $alias => $target) {
				$this->assertEquals($target, $settings->getCommand($alias), 'rTorrent '.$label.' still maps the alias key '.$alias.' to the deprecated-only target '.$target.', got '.$settings->getCommand($alias));
			}

			// The counts are pinned so that an edit which drops or duplicates
			// a table entry cannot pass unnoticed: the walk above would
			// happily assert nothing at all over an empty map.
			$this->assertEquals(310, count($settings->aliases), 'rTorrent '.$label.' has 310 effective alias keys, got '.count($settings->aliases));
			$this->assertEquals(296, count($targets), 'rTorrent '.$label.' resolves those keys to 296 distinct targets, got '.count($targets));

			$missing = array_keys(array_diff_key($targets, $this->stockDaemonMethods()));
			sort($missing);
			$this->assertEquals($deprecatedOnly, $missing, 'rTorrent '.$label.' maps only to registered names, apart from the known deprecated-only three; unregistered here: '.implode(', ', $missing));
		}
	}

	/**
	 * Scans owned production PHP and JS sources across root-level PHP entrypoints
	 * and the php, plugins, js, conf, and lang trees, excluding tests and node_modules.
	 */
	private function ownedProductionSources($root)
	{
		$paths = array();

		foreach (scandir($root) as $item) {
			if ($item === '.' || $item === '..' || is_dir($root.'/'.$item)) {
				continue;
			}
			if (preg_match('/\.php$/D', $item)) {
				$paths[] = $item;
			}
		}

		foreach (array('php', 'plugins', 'js', 'conf', 'lang') as $dir) {
			$base = $root.'/'.$dir;
			if (!is_dir($base)) {
				continue;
			}
			$walk = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
			foreach ($walk as $file) {
				$path = $file->getPathname();
				if (strpos($path, '/node_modules/') !== false) {
					continue;
				}
				if (!preg_match('/\.(php|js)$/D', $path)) {
					continue;
				}
				$paths[] = ltrim(substr($path, strlen($root)), '/');
			}
		}
		sort($paths);

		$sentinels = array('rpc2.php', 'env_check.php', 'lang/en.js', 'php/settings.php', 'js/content.js');
		$this->assertTrue(
			count($paths) > 100,
			'owned production sources scan found more than 100 paths; got '.count($paths)
		);
		foreach ($sentinels as $sentinel) {
			$this->assertTrue(
				in_array($sentinel, $paths, true),
				'owned production sources listing includes sentinel '.$sentinel
			);
		}
		return $paths;
	}

	public function testDeprecatedOnlyAliasKeysAreNotSentByProductionCode()
	{
		// The three targets above are harmless only for as long as nobody
		// asks for them. Anything that starts sending one of these keys ships
		// a fault to every stock daemon, so the "nothing calls them" half of
		// the argument is checked here rather than left as a comment.
		$root = dirname(dirname(__DIR__));
		$paths = $this->ownedProductionSources($root);
		$deprecatedOnly = $this->deprecatedOnlyAliases();

		$callers = array();
		$definitions = array();
		$unreadable = array();
		$scanned = 0;

		foreach ($paths as $path) {
			$contents = @file_get_contents($root.'/'.$path);
			if ($contents === false) {
				$unreadable[] = $path;
				continue;
			}
			$scanned++;
			$lines = explode("\n", $contents);
			foreach ($lines as $lineNum => $line) {
				foreach ($deprecatedOnly as $alias => $target) {
					if (strpos($line, $alias) === false) {
						continue;
					}
					if ($path === 'php/methods-0.9.4.php') {
						$pattern = '/^\s*"'.preg_quote($alias, '/').'"\s*=>\s*array\(\s*"name"\s*=>\s*"'.preg_quote($target, '/').'",\s*"prm"\s*=>\s*[01]\s*\),?(\s*\/\/.*)?$/';
						if (preg_match($pattern, $line)) {
							$definitions[$alias]['php/methods-0.9.4.php'] = isset($definitions[$alias]['php/methods-0.9.4.php'])
								? $definitions[$alias]['php/methods-0.9.4.php'] + 1
								: 1;
							if ($definitions[$alias]['php/methods-0.9.4.php'] > 1) {
								$callers[] = $path.':'.($lineNum + 1).' extra definition of '.$alias;
							}
							continue;
						}
					} elseif ($path === 'js/content.js') {
						$pattern = '/^\s*"'.preg_quote($alias, '/').'"\s*:\s*\{\s*name:\s*"'.preg_quote($target, '/').'",\s*prm:\s*[01]\s*\},?(\s*\/\/.*)?$/';
						if (preg_match($pattern, $line)) {
							$definitions[$alias]['js/content.js'] = isset($definitions[$alias]['js/content.js'])
								? $definitions[$alias]['js/content.js'] + 1
								: 1;
							if ($definitions[$alias]['js/content.js'] > 1) {
								$callers[] = $path.':'.($lineNum + 1).' extra definition of '.$alias;
							}
							continue;
						}
					}
					$callers[] = $path.':'.($lineNum + 1).' sends '.$alias;
				}
			}
		}

		$this->assertEquals(array(), $unreadable, 'all owned production sources are readable; unreadable: '.implode(', ', $unreadable));
		$this->assertTrue($scanned > 100, 'the dormancy scan read production sources; files scanned: '.$scanned);

		foreach ($deprecatedOnly as $alias => $target) {
			$this->assertTrue(
				isset($definitions[$alias]['php/methods-0.9.4.php']) && $definitions[$alias]['php/methods-0.9.4.php'] === 1,
				'exact PHP definition found for '.$alias
			);
			$this->assertTrue(
				isset($definitions[$alias]['js/content.js']) && $definitions[$alias]['js/content.js'] === 1,
				'exact JS definition found for '.$alias
			);
		}

		$this->assertEquals(array(), $callers, 'the deprecated-only aliases stay confined to the exact alias table definitions; found: '.implode(', ', $callers));
	}

	public function testEveryAliasEntryIsWellFormedAtEveryVersionGate()
	{
		// rXMLRPCCommand reads "name" as the wire command and treats "prm" as
		// "prepend the empty target argument the 0.16 dispatcher insists on",
		// so a missing key, a stray trailing '=' or a prm outside {0,1} is a
		// malformed request rather than a wrong one -- and the version that
		// carries it may not be the version anyone runs the suite against.
		// Walk every gate, not just the current daemon's.
		$expected = array(
			0x809  => array('label' => '0.8.9',   'keys' => 3,   'targets' => 3),
			0x908  => array('label' => '0.9.8',   'keys' => 292, 'targets' => 283),
			0x0a02 => array('label' => '0.10.2',  'keys' => 307, 'targets' => 296),
			0x1000 => array('label' => '0.16.0',  'keys' => 307, 'targets' => 296),
			0x1010 => array('label' => '0.16.16', 'keys' => 310, 'targets' => 297),
			0x1012 => array('label' => '0.16.18', 'keys' => 310, 'targets' => 296),
			0x1015 => array('label' => '0.16.21', 'keys' => 310, 'targets' => 296),
		);

		foreach ($expected as $version => $counts) {
			$settings = $this->makeSettings($version);
			$label = $counts['label'];

			$malformed = array();
			$targets = array();
			foreach ($settings->aliases as $alias => $entry) {
				// Shape first, then the target. getCommand() reaches straight
				// into $entry['name'], so asking it for the target ahead of
				// this check makes a non-array entry a fatal before the
				// branch that names that exact malformation can report it.
				if (!is_array($entry) || !array_key_exists('name', $entry) || !array_key_exists('prm', $entry)) {
					$malformed[] = $alias.' is not an array carrying both name and prm';
					continue;
				}
				// The target must be proven a non-empty string before anything
				// treats it as one. getCommand() concatenates it and strpos()
				// demands it, so a name that is an array or a number fatals the
				// whole test file here -- taking every later version gate with
				// it -- instead of being reported as the malformation it is.
				if (!is_string($entry['name']) || $entry['name'] === '') {
					$malformed[] = $alias.' has an empty target';
					continue;
				}
				$targets[$settings->getCommand($alias)] = true;
				if (strpos($entry['name'], '=') !== false) {
					$malformed[] = $alias.' carries a trailing = in its target, which getCommand appends itself';
				}
				if (!in_array($entry['prm'], array(0, 1), true)) {
					$malformed[] = $alias.' has prm '.json_encode($entry['prm']).', which is neither 0 nor 1';
				}
			}
			$this->assertEquals(array(), $malformed, 'rTorrent '.$label.' alias entries are well formed; malformed: '.implode(', ', $malformed));
			$this->assertEquals($counts['keys'], count($settings->aliases), 'rTorrent '.$label.' has '.$counts['keys'].' effective alias keys, got '.count($settings->aliases));
			$this->assertEquals($counts['targets'], count($targets), 'rTorrent '.$label.' resolves them to '.$counts['targets'].' distinct targets, got '.count($targets));
		}
	}
}
