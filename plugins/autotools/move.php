<?php
define('RUTORRENT_SYNCHRONOUS_HOOK', true);
require_once dirname(__DIR__, 2).'/php/cli-only.php';
rutorrent_require_cli(__FILE__);


// What this hook prints becomes x-dest; a PHP diagnostic goes to stderr.
ini_set( 'display_errors', 'stderr' );

if( !chdir( dirname( __FILE__) ) )
	exit();

if( count( $argv ) > 7 )
	$_SERVER['REMOTE_USER'] = $argv[7];

require_once( "./hook_lib.php" );
eval( FileUtil::getPluginConf( 'autotools' ) );

// rTorrent runs this finished hook in execute.capture: its main thread waits
// for this process, and what it prints becomes x-dest. The hook therefore
// never waits for the daemon or for the journal lock; what it cannot do at
// once is recorded for recover.php (see hook_lib.php).
if( $autodebug_enabled ) rtDbg( "AutoMove", "--- begin ---" );
echo AutoToolsFinishedHook::hook( array_slice( $argv, 1, 8 ), $autodebug_enabled );
if( $autodebug_enabled ) rtDbg( "AutoMove", "--- end ---" );
