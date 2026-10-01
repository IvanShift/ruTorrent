<?php
require_once dirname(__DIR__, 2).'/php/cli-only.php';
rutorrent_require_cli(__FILE__);

set_time_limit(0);
$path = dirname(realpath($argv[0]));
if(chdir($path))
{
	if( count( $argv ) > 1 )
		$_SERVER['REMOTE_USER'] = $argv[1];
	require_once('../../php/lock.php');
	if( $lock = rLock::obtain( 'rss/update' ) )
	{
		require_once('rss.php');
		$mngr = new rRSSManager();
		$mngr->update();
		$lock->release();
	}
}
