<?php
require_once dirname(__DIR__, 2).'/php/cli-only.php';
rutorrent_require_cli(__FILE__);

// execute_capture runs while the daemon waits for this synchronous hook.
define('RUTORRENT_SYNCHRONOUS_HOOK', true);

	$path = dirname(realpath($argv[0]));
	if(chdir($path))
	{
		if( count( $argv ) > 4 )
			$_SERVER['REMOTE_USER'] = $argv[4];
		require_once( './rules.php' );
		$mngr = rRatioRulesList::load();
		try
		{
			$rule = $mngr->getRule( rawurldecode($argv[2]), $argv[1] );
		}
		catch(RuntimeException $failure)
		{
			if($failure->getMessage() !== 'settings-cache-unavailable-in-synchronous-hook')
				throw $failure;
			// The settings owner already logged the classified refusal. Never
			// return a PHP diagnostic as the captured ratio or throttle name.
			exit(1);
		}
		if($rule)
		{
			$val = $rule->{$argv[3]};
			if($val!='')
				echo $val;
		}
	}
