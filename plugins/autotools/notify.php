<?php
require_once dirname(__DIR__, 2).'/php/cli-only.php';
rutorrent_require_cli(__FILE__);


if( !chdir( dirname( __FILE__) ) )
	exit(1);

if( count( $argv ) > 4 && $argv[4] !== '' )
	$_SERVER['REMOTE_USER'] = $argv[4];

require_once( "./hook_lib.php" );

// Started detached by the finished hook (move.php) for a refusal it could not
// name itself: rTorrent waits for the hook, and is free again by the time this
// asks it to show the refusal (see AutoToolsFinishedHook::notifyLater()).
// Arguments: hash, operation, classified reason, user, detail.
exit( AutoToolsFinishedHook::notifyRefusal( isset($argv[1]) ? $argv[1] : '',
	isset($argv[2]) ? $argv[2] : '', isset($argv[3]) ? $argv[3] : '',
	isset($argv[5]) ? $argv[5] : '' ) ? 0 : 1 );
