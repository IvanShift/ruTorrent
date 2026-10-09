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
		$datadir = rawurldecode( $parts[1] );
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

// One validation of the hash, the configured top (physical path) and the
// destination's existing ancestor; the CLI worker uses the same function.
$refusal = rtDataDirRequestRefusal( $hash, $datadir );
if( $refusal === 'destination-invalid' )
	$datadir = '';

Debug( "" );
Debug( "--- begin ---" );
Debug( $datadir );
Debug(
	"\"".($move_addpath    == '0' ? "don't " : "")."add path\"".
	", \"".($move_datafiles  == '0' ? "don't " : "")."move files\"".
	", \"".($move_fastresume == '0' ? "don't " : "")."fast resume\"" );

$res = false;
$warnings = array();

if( $refusal === 'invalid-arguments' || $refusal === 'destination-invalid' )
{
	// Neither a malformed hash nor a refused path is copied into the log or
	// the answer; the dialog gets the classified reason. A refused path comes
	// with a validated hash, which names the download like every other refusal.
	FileUtil::toLog( $refusal === 'invalid-arguments' ? 'datadir: setdatadir refused: invalid hash'
		: 'datadir: setdatadir refused hash='.strtoupper($hash).' reason=destination-invalid' );
	$errors[] = array('desc'=>"theUILang.datadirSetDirFail", 'prm'=>$refusal);
}
else
{
	if( $refusal === '' )
		try { $refusal = rtDataDirPendingRefusal($hash); }
		catch(Exception $e) { $refusal = $e->getMessage(); }
	if( $refusal === 'prior-job-pending' )
		rtDataDirWorkerRefusal($hash, $refusal);
	// The claim ABI is read once per request.
	if( $refusal === '' )
		$refusal = rtDataDirClaimRefusal();
	$taken = '';
	if( $refusal === '' && $move_datafiles )
	{
		try {
			$taken = rtDataDirCollision($hash, $datadir, $move_addpath, $datadir_debug_enabled);
		} catch(Exception $e) { $refusal = $e->getMessage(); }
	}
	if( $refusal !== '' || $taken !== '' )
	{
		FileUtil::toLog('datadir: setdatadir refused hash='.$hash.' reason='.($refusal !== '' ? $refusal : 'occupied-destination'));
		$errors[] = array('desc'=>"theUILang.datadirSetDirFail", 'prm'=>$refusal !== '' ? $refusal : $taken);
	}
	elseif( !$move_datafiles )
	{
		// A directory-only change runs here, so the dialog sees its real
		// result. It never waits behind a payload move: a busy profile lock
		// answers datadir-busy, and payload jobs are not replayed in FPM.
		$res = rtDataDirApplyLocked($hash, $datadir, $move_addpath != 0, false,
			$move_fastresume != 0, $datadir_debug_enabled, true, $failure, $warning);
		if( !$res )
			$errors[] = array('desc'=>"theUILang.datadirSetDirFail", 'prm'=>$failure);
		elseif( $warning !== '' )
			$warnings[] = array('desc'=>"theUILang.datadirStoppedAfterCheck", 'prm'=>$warning);
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

if( !$res && !$errors )
{
	$errors[] = array('desc'=>"theUILang.datadirSetDirFail", 'prm'=>$datadir);
}

Debug( "--- end ---" );

$answer = array( "errors"=>$errors );
// A warning reports an applied change that did not end as asked.
if( $warnings )
	$answer["warnings"] = $warnings;
CachedEcho::send(JSON::safeEncode($answer),"application/json");
