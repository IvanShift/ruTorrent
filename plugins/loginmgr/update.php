<?php
require_once dirname(__DIR__, 2).'/php/cli-only.php';
rutorrent_require_cli(__FILE__);

	$path = dirname(realpath($argv[0]));
	if(chdir($path))
	{
		if( count( $argv ) > 1 )
			$_SERVER['REMOTE_USER'] = $argv[1];
		require_once( './accounts.php' );

		$acc = accountManager::load();
		$acc->checkAuto();
	}
