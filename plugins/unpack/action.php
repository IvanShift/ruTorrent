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
			$directory = FileUtil::requestPath($input['dir'] ?? null);
			if($directory !== null)
				$ret = $up->startTask( $input['hash'], $directory, $input['mode'], $input['no'] );
			else
				$ret = array('no' => -1, 'pid' => 0, 'status' => 255, 'log' => array(), 'errors' => array('Invalid directory.'));
			break;
		}
	}
}

CachedEcho::send(JSON::safeEncode($ret),"application/json");
