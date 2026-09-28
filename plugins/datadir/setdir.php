<?php

if( !chdir( dirname( __FILE__) ) )
	exit();

# Script arguments are:
# 0: script name
# 1: hash
# 2: target datadir
# 3: flag, "1" means "add torrent's path"
# 4: flag, "1" means "move datafiles"
# 5: flag, "1" means "fast resume"
# 6: username
if( count( $argv ) > 6 )
	$_SERVER['REMOTE_USER'] = $argv[6];

require_once( '../../php/xmlrpc.php' );
require_once( './util_setdir.php' );
require_once( './util_rt.php' );
eval( FileUtil::getPluginConf( 'datadir' ) );

try {
    $DataDir_Lock = rtDataDirLock(false);
} catch (Exception $e) {
    FileUtil::toLog('datadir: worker refused: ' . $e->getMessage());
    exit(1);
}

// The same lock also serializes replay with a new request.
rtDataDirRecover();

function Debug( $str )
{
	global $datadir_debug_enabled;
	if( $datadir_debug_enabled ) rtDbg( "SetDir", $str );
}

Debug( "" );
Debug( "--- begin ---" );

$is_ok = true;

if( count( $argv ) < 6 )
{
	Debug( "called without arguments (at least 5 params wanted)" );
	FileUtil::toLog( 'datadir: worker refused: invalid-arguments' );
	$is_ok = false;
}
else {
	$hash            = trim( $argv[1] );
	$datadir         = trim( $argv[2] );
	$move_addpath    = trim( $argv[3] );
	$move_datafiles  = trim( $argv[4] );
	$move_fastresume = trim( $argv[5] );
}

if( $is_ok && (strlen($hash) != 40 || !ctype_xdigit($hash) || $datadir == '') )
{
	FileUtil::toLog( 'datadir: worker refused: invalid-arguments' );
	$is_ok = false;
}

if( $is_ok && !rTorrentSettings::get()->correctDirectory($datadir, true) )
{
	FileUtil::toLog( 'datadir: destination-invalid hash='.$hash );
	$is_ok = false;
}

if( $is_ok )
{
    $claimCapability = rtDataDirClaimCapability();
    if( $claimCapability !== 'available' )
    {
        $reason = $claimCapability === 'unsupported'
            ? 'daemon-claim-unavailable' : 'daemon-claim-unconfirmed';
        FileUtil::toLog( 'datadir: worker refused hash='.$hash.' reason='.$reason );
        $is_ok = false;
    }
}

if( $is_ok )
{
	Debug( "hash        : ".$hash );
	Debug( "data dir    : ".$datadir );
	Debug( "add path    : ".$move_addpath );
	Debug( "move files  : ".$move_datafiles );
	Debug( "fast resume : ".$move_fastresume );

	if( !rtMkDir( $datadir, 0777 ) )
	{
		Debug( "can't create ".$datadir );
		FileUtil::toLog( 'datadir: destination-create-failed hash='.$hash );
	}
	else if( !rTorrentSettings::get()->correctDirectory($datadir, true) )
		FileUtil::toLog( 'datadir: destination-invalid hash='.$hash );
	else
	{
		$result = rtSetDataDir( $hash, $datadir,
			$move_addpath == '1', $move_datafiles == '1', $move_fastresume == '1',
			$datadir_debug_enabled );
		if( $result === null )
			Debug( "rtSetDataDir() pending confirmation" );
		elseif( $result === false )
		{
			Debug( "rtSetDataDir() fail!" );
			FileUtil::toLog( 'datadir: transfer-failed hash='.$hash );
		}
	}
}

Debug( "--- end ---" );

rtDataDirUnlock( $DataDir_Lock );
