<?php

require_once(__DIR__ . '/../../php/TestCase.php');

class HttprpcSettingsPolicyTest extends TestCase
{
	private function policyLoaded()
	{
		$file = __DIR__ . '/../../../plugins/httprpc/settingspolicy.php';
		$this->assertTrue(is_file($file), 'httprpc has one settings name policy');
		if(!is_file($file))
			return false;
		require_once($file);
		return true;
	}

	public function testSettingsNamesComeFromTheReadList()
	{
		if(!$this->policyLoaded())
			return;
		$read = HttprpcSettingsPolicy::readCommands();
		$this->assertTrue(in_array('get_directory', $read, true),
			'the existing settings read list includes the directory');
		$this->assertTrue(in_array('get_max_open_files', $read, true),
			'the existing settings read list includes the socket allocation input');
		$this->assertTrue(!in_array('get_xmlrpc_size_limit', $read, true),
			'the settings page has no XMLRPC size input');
		$expected = array(
			'get_check_hash', 'get_bind', 'get_dht_port', 'get_directory', 'get_download_rate',
			'get_hash_interval', 'get_hash_max_tries', 'get_hash_read_ahead', 'get_http_cacert', 'get_http_capath',
			'get_http_proxy', 'get_ip', 'get_max_downloads_div', 'get_max_downloads_global', 'get_max_file_size',
			'get_max_memory_usage', 'get_max_open_files', 'get_max_open_http', 'get_max_peers', 'get_max_peers_seed',
			'get_max_uploads', 'get_max_uploads_global', 'get_min_peers_seed', 'get_min_peers', 'get_peer_exchange',
			'get_port_open', 'get_upload_rate', 'get_port_random', 'get_port_range', 'get_preload_min_size',
			'get_preload_required_rate', 'get_preload_type', 'get_proxy_address', 'get_receive_buffer_size', 'get_safe_sync',
			'get_scgi_dont_route', 'get_send_buffer_size', 'get_session', 'get_session_lock', 'get_session_on_completion',
			'get_split_file_size', 'get_split_suffix', 'get_timeout_safe_sync', 'get_timeout_sync', 'get_tracker_numwant',
			'get_use_udp_trackers', 'get_max_uploads_div', 'get_max_open_sockets',
		);
		$this->assertTrue($read === $expected,
			'the full 48-position settings read response retains its historical order');
	}

	public function testOnlySettingsShownByThePageCanBeWritten()
	{
		if(!$this->policyLoaded())
			return;
		foreach(array('sdirectory', 'nmax_open_files', 'ndht', 'nscgi_dont_route') as $key)
			$this->assertTrue(HttprpcSettingsPolicy::allowsWrite($key),
				'the settings page can write '.$key);
		foreach(array('nxmlrpc_size_limit', 'nexecute', 'nmethod.insert',
				'n', 'xdirectory', 'nDirectory', 'nget_directory', 'ndirectory',
				'smax_open_files', 'nmax_open_sockets') as $key)
			$this->assertTrue(!HttprpcSettingsPolicy::allowsWrite($key),
				'forged setting is refused: '.$key);
	}

	public function testGuardRunsBeforeAnySetsettingsCommandIsBuilt()
	{
		$source = file_get_contents(__DIR__ . '/../../../plugins/httprpc/action.php');
		$start = strpos($source, 'case "setsettings":');
		$guard = strpos($source, 'HttprpcSettingsPolicy::allowsWrite(');
		$setter = strpos($source, "new rXMLRPCCommand('set_'.substr(");
		$this->assertTrue($start !== false && $guard !== false && $setter !== false
			&& $start < $guard && $guard < $setter,
			'the settings name is checked before it becomes a trusted RPC setter');
	}
}
