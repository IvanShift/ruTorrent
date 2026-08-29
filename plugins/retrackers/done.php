<?php

$req = new rXMLRPCRequest(
	rTorrentSettings::get()->getOnInsertCommand(array('tadd_trackers1'.User::getUser(), getCmd('cat='))));
$req->run();
