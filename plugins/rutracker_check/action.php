<?php

require_once( "../../php/util.php" );
require_once( dirname(__FILE__)."/launcher.php" );

/**
 * Answer the manual check request and stop.
 *
 * Every handled outcome keeps a 2xx status. The browser reaches this endpoint
 * through theWebUI.getTorrents(), whose shared error callback sets
 * systemInfo.rTorrent.started = false, so answering a refused batch with a
 * failure status reports the daemon as unreachable over a request the daemon
 * never saw -- and skips the plugin's own response handler, which is the only
 * thing that can tell the user what actually happened. The outcome therefore
 * travels in the body, and init.js reads it.
 */
function ruTrackerManualAnswer( $status, $accepted )
{
	CachedEcho::send( json_encode( array( 'status' => $status, 'accepted' => $accepted ) ),
		"application/json" );
}

if(!isset($HTTP_RAW_POST_DATA))
	// Bounded: one byte over the limit is enough to recognise an oversized body,
	// and nothing larger is ever buffered to find that out.
	$HTTP_RAW_POST_DATA = file_get_contents( "php://input", false, null, 0,
		RuTrackerBatchRequest::MAX_BODY_BYTES + 1 );

$error = null;
$hashes = RuTrackerBatchRequest::parseHashes( $HTTP_RAW_POST_DATA, $error );
if( $error !== null )
{
	FileUtil::toLog( 'rutracker_check: manual batch request rejected: '.$error );
	ruTrackerManualAnswer( 'rejected', 0 );
}

if( !count( $hashes ) )
	ruTrackerManualAnswer( 'rejected', 0 );

$dispatched = RuTrackerBatchDispatch::dispatch(
	$hashes,
	FileUtil::getTempDirectory(),
	Utility::getPHP(),
	dirname( __FILE__ )."/batch_check.php",
	User::getUser(),
	null,
	array( 'FileUtil', 'toLog' )
);

ruTrackerManualAnswer( $dispatched ? 'queued' : 'refused', $dispatched ? count( $hashes ) : 0 );
