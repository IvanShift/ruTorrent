<?php

require_once( '../../php/util.php' );
Requests::requirePost();
if(!isset($_POST['ip'], $_POST['comment']))
{
	header('HTTP/1.0 400 Bad Request', true, 400);
	die('Bad Request');
}
eval( FileUtil::getPluginConf( 'geoip' ) );
require_once( 'ip_db.php' );

$db = new ipDB();
$db->add($_POST["ip"],$_POST["comment"]);

CachedEcho::send( JSON::safeEncode( array( "ip"=>$_POST["ip"], "comment"=>$_POST["comment"] ) ), "application/json" );
