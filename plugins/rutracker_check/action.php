<?php

require_once( "../../php/util.php" );
require_once( __DIR__ . "/launcher.php" );

if(!isset($HTTP_RAW_POST_DATA))
	$HTTP_RAW_POST_DATA = file_get_contents("php://input", false, null, 0, RuTrackerBatchRequest::MAX_BODY_BYTES + 1);

if($HTTP_RAW_POST_DATA !== false && strlen($HTTP_RAW_POST_DATA) > RuTrackerBatchRequest::MAX_BODY_BYTES)
{
	header("HTTP/1.0 413 Payload Too Large");
	CachedEcho::send(json_encode(array('status' => 'error', 'error' => 'payload_too_large')), "application/json");
	exit;
}

if(!isset($HTTP_RAW_POST_DATA))
{
	header("HTTP/1.0 400 Bad Request");
	CachedEcho::send(json_encode(array('status' => 'error', 'error' => 'no_valid_hashes', 'accepted' => 0)), "application/json");
	exit;
}

$error = null;
$ret = RuTrackerBatchRequest::parseHashes($HTTP_RAW_POST_DATA, $error);
if($error !== null)
{
	FileUtil::toLog('rutracker_check: manual batch request rejected: ' . $error);
	header("HTTP/1.0 400 Bad Request");
	CachedEcho::send(json_encode(array('status' => 'error', 'error' => 'no_valid_hashes', 'accepted' => 0)), "application/json");
	exit;
}

if(empty($ret))
{
	header("HTTP/1.0 400 Bad Request");
	CachedEcho::send(json_encode(array('status' => 'error', 'error' => 'no_valid_hashes', 'accepted' => 0)), "application/json");
	exit;
}

$dispatched = RuTrackerBatchDispatch::dispatch(
	$ret,
	FileUtil::getTempDirectory(),
	Utility::getPHP(),
	dirname(__FILE__) . "/batch_check.php",
	User::getUser(),
	null,
	array('FileUtil', 'toLog')
);

if($dispatched)
{
	header("HTTP/1.0 202 Accepted");
	CachedEcho::send(json_encode(array('status' => 'queued', 'accepted' => count($ret))), "application/json");
}
else
{
	header("HTTP/1.0 503 Service Unavailable");
	CachedEcho::send(json_encode(array('status' => 'error', 'error' => 'dispatch_failed', 'accepted' => 0)), "application/json");
}
