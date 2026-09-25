<?php

/**
 * The settings page's exact s= keys in its read-response order.
 * One list drives both the stg reads and the trusted setsettings boundary.
 */
class HttprpcSettingsPolicy
{
	private static $writableKeys = array(
		'ncheck_hash', 'sbind', 'sdht_port', 'sdirectory', 'sdownload_rate',
		'shash_interval', 'shash_max_tries', 'shash_read_ahead', 'shttp_cacert', 'shttp_capath',
		'shttp_proxy', 'sip', 'smax_downloads_div', 'nmax_downloads_global', 'smax_file_size',
		'nmax_memory_usage', 'nmax_open_files', 'nmax_open_http', 'smax_peers', 'smax_peers_seed',
		'smax_uploads', 'nmax_uploads_global', 'smin_peers_seed', 'smin_peers', 'npeer_exchange',
		'nport_open', 'supload_rate', 'nport_random', 'sport_range', 'spreload_min_size',
		'spreload_required_rate', 'npreload_type', 'sproxy_address', 'sreceive_buffer_size', 'nsafe_sync',
		'nscgi_dont_route', 'ssend_buffer_size', 'ssession', 'nsession_lock', 'nsession_on_completion',
		'ssplit_file_size', 'ssplit_suffix', 'stimeout_safe_sync', 'stimeout_sync', 'stracker_numwant',
		'nuse_udp_trackers', 'smax_uploads_div',
	);

	public static function readCommands()
	{
		$commands = array();
		foreach(self::$writableKeys as $key)
			$commands[] = 'get_'.substr($key, 1);
		// This value is shown by the settings page but has no input.
		$commands[] = 'get_max_open_sockets';
		return $commands;
	}

	public static function allowsWrite($key)
	{
		// DHT mode is displayed through dht_statistics, not get_dht.
		return $key === 'ndht' || in_array($key, self::$writableKeys, true);
	}
}
