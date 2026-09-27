<?php
require_once( 'unpack.php' );

ignore_user_abort(true);
set_time_limit(0);
$ret = array();

$input = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $_POST : $_GET;
if(isset($input['cmd']))
{
	$cmd = $input['cmd'];
	switch($cmd)
	{
		case "set":
		{
			Requests::requirePost();
			$up = rUnpack::load();
			$up->set();
			CachedEcho::send($up->get(),"application/javascript");
			break;
		}
		case "unpack":
		{
			Requests::requirePost();
			$up = rUnpack::load();
			$ret = $up->startTask( $input['hash'], $input['dir'], $input['mode'], $input['no'] );
			break;
		}
	}
}

CachedEcho::send(JSON::safeEncode($ret),"application/json");
