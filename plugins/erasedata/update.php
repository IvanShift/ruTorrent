<?php

// Guarded, not a bare count(): under a web SAPI $argv does not exist, and
// count(null) warns on PHP 7.4 but is a TypeError on PHP 8. An HTTP request
// therefore used to die here, three lines in, leaving the SAPI guard below
// unreachable on the runtime production actually uses -- fail-closed by
// accident, silently, and differently per version.
if( isset( $argv ) && is_array( $argv ) && count( $argv ) > 1 )
	$_SERVER['REMOTE_USER'] = $argv[1];

if(!class_exists('rXMLRPCRequest'))
	require_once( dirname(__FILE__)."/../../php/xmlrpc.php" );
require_once( dirname(__FILE__)."/filesystem.php" );
require_once( dirname(__FILE__)."/manifest.php" );
require_once( dirname(__FILE__)."/removewithdata.php" );
require_once( dirname(__FILE__)."/collector.php" );
eval(FileUtil::getPluginConf('erasedata'));

// Production wiring: the collector receives the live filesystem seam, the live
// rTorrent presence probe, the live path collector, the plugin logger and the
// configured force-deletion policy.
function erasedataCollectorService(ErasedataFilesystemOps $filesystem)
{
	global $enableForceDeletion;
	return(new ErasedataCollector($filesystem, 'erasedataTorrentPresence',
		'erasedataCollectPaths', 'eLog', !empty($enableForceDeletion)));
}

// Public entry point for other plugins: collect one list directory, optionally
// restricted to a single canonical hash. This takes NO scheduler lock -- only
// the per-hash locks the collector itself acquires -- so a caller that can run
// concurrently with the scheduled pass must take the scheduler lock itself, the
// way erasedataCollectorMain() below does.
function erasedataRunCollector($listPath, $onlyHash = null)
{
	$service = erasedataCollectorService(new ErasedataFilesystemOps());
	$service->run($listPath, $onlyHash);
}

function erasedataCollectorMain(ErasedataFilesystemOps $filesystem)
{
	global $argv;
	$listPath = FileUtil::getSettingsPath()."/erasedata";
	@FileUtil::makeDirectory($listPath);
	$onlyHash = null;
	if(is_array($argv) && count($argv) > 2)
	{
		$onlyHash = erasedataCanonicalHash($argv[2]);
		if($onlyHash === false)
		{
			eLog('Invalid targeted collector hash.');
			return;
		}
	}
	$schedulerLockPath = $listPath.'/scheduler.lock';
	$schedulerLock = @fopen($schedulerLockPath, 'c');
	if($schedulerLock === false)
		eLog('Could not open scheduler lock.');
	else
	{
		erasedataRepairFileMode($schedulerLockPath);
		if(@flock($schedulerLock, LOCK_EX | LOCK_NB))
		{
			$service = erasedataCollectorService($filesystem);
			$service->run($listPath, $onlyHash);
			@flock($schedulerLock, LOCK_UN);
			@fclose($schedulerLock);
		}
		else
		{
			eLog('Busy, wait for next time.');
			@fclose($schedulerLock);
		}
	}
}

// The scheduler spawns this file as a CLI script, and nothing else may start
// it. Plugins live under the document root, so an HTTP request for
// /plugins/erasedata/update.php sets SCRIPT_FILENAME to this very path and the
// path comparison alone would happily run the collector -- deleting payload --
// as the web server user. PHP_SAPI is what separates the two, and it cannot be
// forged from a request.
$erasedataScript = isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : null;
$erasedataIsEntryPoint = is_string($erasedataScript) && realpath($erasedataScript) === __FILE__;
if(erasedataMayStartCollector(PHP_SAPI, $erasedataScript, __FILE__))
	erasedataCollectorMain(new ErasedataFilesystemOps());
// Only when this file IS the entry point: being required by another plugin for
// erasedataRunCollector() is ordinary and says nothing. Reaching here otherwise
// is either the HTTP request this guard exists for, or the likelier and
// otherwise silent case -- conf/config.php invites $pathToExternals["php"] =
// "/usr/bin/php-cgi", whose SAPI is cgi-fcgi, so the scheduled collector would
// be refused every cycle for ever while payload accumulated.
elseif($erasedataIsEntryPoint)
	FileUtil::toLog('erasedata: update.php was started under the ' . PHP_SAPI
		. ' SAPI, and only cli may start the collector, so nothing was done;'
		. ' if this is the scheduler, point $pathToExternals["php"] at a CLI'
		. ' binary rather than a CGI one');
