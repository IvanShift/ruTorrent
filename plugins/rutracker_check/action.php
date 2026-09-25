<?php

require_once( "../../php/util.php" );
require_once( dirname(__FILE__)."/launcher.php" );

/**
 * Answer the manual check request and stop.
 *
 * Every handled outcome keeps a 2xx status so the plugin's response handler
 * can report the accepted or rejected batch before the core follows up with
 * the torrent list. A failure status bypasses that handler and only reaches
 * the generic HTTP error callback. The outcome travels in the body, which
 * init.js reads.
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
