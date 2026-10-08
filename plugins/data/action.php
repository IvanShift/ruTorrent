<?php
require_once( '../../php/rtorrent.php' );

if(isset($_REQUEST['result']))
	CachedEcho::send('noty(theUILang.cantAccessData,"error");',"text/html");

if(isset($_REQUEST['hash']) && isset($_REQUEST['no']))
{
	// The shared reader opens a closed download only to read the path and
	// returns it in the daemon's own bytes (AUD-ESC-06).
	$filename = rTorrent::getFilePath($_REQUEST['hash'], $_REQUEST['no']);
	if(($filename !== false) && SendFile::send($filename))
		exit;
}

if(isset($_REQUEST['readable']))
	CachedEcho::send("Can't retrieve such a large file, sorry","text/html");
else
{
	header("HTTP/1.0 302 Moved Temporarily");
	header("Location: action.php?result=0");
}
