<?php
require_once( 'cookies.php' );

$cmd = '';
if(isset($_REQUEST['mode']))
	$cmd = $_REQUEST['mode'];

switch($cmd)
{
	case 'info':
	{
		$cookies = rCookies::load();
		if(isset($_REQUEST['host']))
			CachedEcho::send(JSON::safeEncode($cookies->getCookiesForHost($_REQUEST['host'])),"application/json");
		else
			CachedEcho::send(JSON::safeEncode($cookies->getInfo()),"application/json");
	}
	case 'add':
	{
		Requests::requirePost();
		$cookies = rCookies::load();
		if(isset($_POST['host'], $_POST['cookies']))
			$cookies->add($_POST['host'], rawurldecode($_POST['cookies']));
        	CachedEcho::send(JSON::safeEncode($cookies->getInfo()),"application/json");
	}
	case 'set':
	case '':
	{
		Requests::requirePost();
		$cookies = new rCookies();
		$cookies->set();
		CachedEcho::send($cookies->get(),"application/javascript");
	}
	default:
	{
		header('HTTP/1.0 400 Bad Request', true, 400);
		die('Invalid mode');
	}
}
