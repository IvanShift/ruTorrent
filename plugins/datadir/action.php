<?php

require_once( '../../php/util.php' );
require_once( '../../php/xmlrpc.php' );
require_once( './util_setdir.php' );
require_once __DIR__ . '/apply.php';
require_once( './util_rt.php' );
require_once( './setdircommand.php' );
eval( FileUtil::getPluginConf( 'datadir' ) );

function Debug( $str )
{
	global $datadir_debug_enabled;
	if( $datadir_debug_enabled ) rtDbg( "DataDir", $str );
}

ignore_user_abort( true );
set_time_limit( 0 );

$errors = array();

if( !isset( $HTTP_RAW_POST_DATA ) )
	$HTTP_RAW_POST_DATA = file_get_contents( "php://input" );
$vars = explode( '&', $HTTP_RAW_POST_DATA );
$hash = null;
$datadir = "";
$move_addpath = "1";
$move_datafiles = "0";
$move_fastresume = "0";
foreach( $vars as $var )
{
	$parts = explode( "=", $var, 2 );
	if( count($parts) != 2 ) continue;
	if( $parts[0] == "hash" )
	{
		$hash = trim( $parts[1] );
		if( strlen($hash) != 40 || !ctype_xdigit($hash) )
		{
			$hash = null;
		}
	}
	else if( $parts[0] == "datadir" )
	{
		$datadir = trim( rawurldecode( $parts[1] ) );
	}
	else if($parts[0]=="move_addpath")
	{
		$move_addpath = intval( $parts[1] );
	}
	else if( $parts[0] == "move_datafiles" )
	{
		$move_datafiles = intval( $parts[1] );
	}
	else if( $parts[0] == "move_fastresume" )
	{
		$move_fastresume = intval( $parts[1] );
	}
}

if(!rTorrentSettings::get()->correctDirectory($datadir, true))
{
	$datadir = '';
}

Debug( "" );
Debug( "--- begin ---" );
Debug( $datadir );
Debug(
	"\"".($move_addpath    == '0' ? "don't " : "")."add path\"".
	", \"".($move_datafiles  == '0' ? "don't " : "")."move files\"".
	", \"".($move_fastresume == '0' ? "don't " : "")."fast resume\"" );

$res = false;

if( $hash && strlen( $datadir ) > 0 )
{
	$claimCapability = rtDataDirClaimCapability();
	if( $claimCapability !== 'available' )
	{
		$reason = $claimCapability === 'unsupported'
			? 'daemon-claim-unavailable' : 'daemon-claim-unconfirmed';
		FileUtil::toLog( 'datadir: setdatadir refused hash='.$hash.' reason='.$reason );
		$errors[] = array('desc'=>"theUILang.datadirSetDirFail", 'prm'=>$reason);
	}
	else
	{
		$taken = '';
		$preflightReason = DataDirMoveIntent::destinationReason($datadir);
		if($preflightReason === '')
		{
			try {
				$taken = $move_datafiles
					? rtDataDirCollision($hash, $datadir, $move_addpath, $datadir_debug_enabled)
					: '';
			} catch(Exception $e) { $preflightReason = $e->getMessage(); }
		}
		if($preflightReason !== '')
		{
			FileUtil::toLog('datadir: setdatadir refused hash='.$hash.' reason='.$preflightReason);
			$errors[] = array('desc'=>"theUILang.datadirSetDirFail", 'prm'=>$preflightReason);
		}
		elseif($taken !== '')
		{
			FileUtil::toLog('datadir: setdatadir refused hash='.$hash.' reason=occupied-destination');
			$errors[] = array('desc'=>"theUILang.datadirSetDirFail", 'prm'=>$taken);
		}
		elseif(!$move_datafiles)
		{
			// This operation is short and has no payload loop. Return its real
			// result so errors cannot hide behind a successful worker launch.
			$lock = null;
			try {
				$lock = rtDataDirLock(false);
				rtDataDirRecover();
				$res = rtDataDirApply($hash, $datadir, $move_addpath != 0, false,
					$move_fastresume != 0, $datadir_debug_enabled, $reason);
			} catch(Exception $e) {
				$reason = $e->getMessage();
				FileUtil::toLog('datadir: setdatadir refused hash='.$hash.' reason='.$reason);
			} finally { rtDataDirUnlock($lock); }
			if(!$res)
				$errors[] = array('desc'=>"theUILang.datadirSetDirFail", 'prm'=>$reason ?: 'change-unconfirmed');
		}
		else
		{
		$script_dir = rtAddTailSlash( dirname( __FILE__ ) );
		$php = Utility::getPHP();
		Debug( "script dir  : ".$script_dir );
		Debug( "path to php : ".$php );
		Debug( "hash        : ".$hash );
		Debug( "data dir    : ".$datadir );
		Debug( "add path    : ".$move_addpath );
		Debug( "move files  : ".$move_datafiles );
		Debug( "fast resume : ".$move_fastresume );
		$res = rtExec( "execute",
			array( "sh",
				"-c",
				rtSetDirCommand($php, $script_dir."setdir.php", $hash, $datadir,
					$move_addpath, $move_datafiles, $move_fastresume, User::getUser()),
			),
			$datadir_debug_enabled );
		if( !$res )
			FileUtil::toLog( 'datadir: worker-dispatch-unconfirmed hash='.$hash );
		}
	}
}
else
	FileUtil::toLog( 'datadir: setdatadir refused: '.($hash ? 'invalid destination' : 'invalid hash') );

if( !$res && !$errors )
{
	$errors[] = array('desc'=>"theUILang.datadirSetDirFail", 'prm'=>$datadir);
}

Debug( "--- end ---" );

CachedEcho::send(JSON::safeEncode(array( "errors"=>$errors )),"application/json");
