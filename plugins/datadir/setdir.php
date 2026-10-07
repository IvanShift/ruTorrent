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

if (count($argv) < 6)
    FileUtil::toLog('datadir: worker refused: invalid-arguments');
else
    rtDataDirApply(trim($argv[1]), trim($argv[2]), trim($argv[3]) === '1',
        trim($argv[4]) === '1', trim($argv[5]) === '1', $datadir_debug_enabled);

Debug( "--- end ---" );

rtDataDirUnlock( $DataDir_Lock );
