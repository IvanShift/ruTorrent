<?php
require_once( 'throttle.php' );
Requests::requirePost();

$thr = new rThrottle();
if(isset($_POST['apply']))
{
	$thr->apply();
}
else
{
	if(!isset($_POST['default']))
	{
		header('HTTP/1.0 400 Bad Request', true, 400);
		die('Bad Request');
	}
	$thr->set();
	CachedEcho::send($thr->get(),"application/javascript");
}
