<?php

// The torrent presence probe shared by erasedata, rutracker_check and datadir,
// under the names erasedata has always used. The probe itself is
// HashFault::presence() in core, which addtorrent.php uses as well: only the
// daemon's own missing-hash answer proves absence; a transport failure, any
// other fault or a malformed answer is unknown, and only a matching d.hash is
// presence. datadir loads this file alone, without the removal machinery in
// removewithdata.php. The caller loads php/xmlrpc.php.
require_once(dirname(__FILE__).'/../../php/utility/hashfault.php');
if(!defined('ERASEDATA_TORRENT_PRESENT'))
	define('ERASEDATA_TORRENT_PRESENT', HashFault::PRESENT);
if(!defined('ERASEDATA_TORRENT_ABSENT'))
	define('ERASEDATA_TORRENT_ABSENT', HashFault::ABSENT);
if(!defined('ERASEDATA_TORRENT_UNKNOWN'))
	define('ERASEDATA_TORRENT_UNKNOWN', HashFault::UNKNOWN);

if(!function_exists('erasedataTorrentPresence'))
{
	function erasedataTorrentPresence($hash)
	{
		return(HashFault::presence($hash));
	}
}
