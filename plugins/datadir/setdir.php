<?php
require_once dirname(__DIR__, 2).'/php/cli-only.php';
rutorrent_require_cli(__FILE__);


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
require_once __DIR__ . '/apply.php';
require_once( './util_rt.php' );
eval( FileUtil::getPluginConf( 'datadir' ) );

function Debug( $str )
{
	global $datadir_debug_enabled;
	if( $datadir_debug_enabled ) rtDbg( "SetDir", $str );
}

Debug( "" );
Debug( "--- begin ---" );

if( count( $argv ) < 6 )
{
	Debug( "called without arguments (at least 5 params wanted)" );
	FileUtil::toLog( 'datadir: worker refused: invalid-arguments' );
}
else
{
	$hash = trim( $argv[1] );
	$datadir = $argv[2];
	Debug( "data dir    : ".$datadir );
	Debug( "add path    : ".trim( $argv[3] ) );
	Debug( "move files  : ".trim( $argv[4] ) );
	Debug( "fast resume : ".trim( $argv[5] ) );
	// The same request validation as the web door, then the claim ABI once.
	$refusal = rtDataDirRequestRefusal( $hash, $datadir );
	if( $refusal === '' )
		$refusal = rtDataDirClaimRefusal();
	if( $refusal === 'invalid-arguments' )
		// A malformed hash is not copied into the log.
		FileUtil::toLog( 'datadir: worker refused: invalid-arguments' );
	elseif( $refusal !== '' )
	{
		FileUtil::toLog( 'datadir: worker refused hash='.strtoupper( $hash ).' reason='.$refusal );
		rtDataDirWorkerRefusal( $hash, $refusal );
	}
	else
	{
		Debug( "hash        : ".$hash );
		$applied = rtDataDirApplyLocked( $hash, $datadir, trim( $argv[3] ) === '1',
			trim( $argv[4] ) === '1', trim( $argv[5] ) === '1', $datadir_debug_enabled, false,
			$failure, $warning );
		Debug( $applied ? "applied".($warning === '' ? '' : ": ".$warning) : "refused: ".$failure );
		if( !$applied )
			rtDataDirWorkerRefusal( $hash, $failure );
	}
}

Debug( "--- end ---" );
