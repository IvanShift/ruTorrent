<?php

if( !chdir( dirname( __FILE__) ) )
	exit();

if( count( $argv ) > 6 )
	$_SERVER['REMOTE_USER'] = $argv[6];

require_once( "./util_rt.php" );

// Only an old persisted Move hook calls check.php. Returning the source
// prevents its following directory setter from pointing at unmoved files.
$base_path = rtAddTailSlash( rtRemoveLastToken( rtRemoveTailSlash( $argv[1] ), '/' ) );
$sub_dir = $argv[3] ? rtAddTailSlash( $argv[2] ) : '';
FileUtil::toLog( 'autotools: legacy Move check refused; refresh the finished hook' );
echo $base_path.$sub_dir;
