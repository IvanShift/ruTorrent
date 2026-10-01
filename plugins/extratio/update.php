<?php
require_once dirname(__DIR__, 2).'/php/cli-only.php';
rutorrent_require_cli(__FILE__);

	$path = dirname(realpath($argv[0]));
	if(chdir($path))
	{
		if( count( $argv ) > 4 )
			$_SERVER['REMOTE_USER'] = $argv[4];
		require_once( './rules.php' );
		$mngr = rRatioRulesList::load();
		$rule = $mngr->getRule( rawurldecode($argv[2]), $argv[1] );
		if($rule)
		{
			$val = $rule->{$argv[3]};
			if($val!='')
				echo $val;
		}
	}
