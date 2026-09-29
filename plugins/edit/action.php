<?php

require_once( '../../php/settings.php' );
require_once( '../../php/rtorrent.php' );
require_once( '../../php/torrenttrackers.php' );

ignore_user_abort(true);
set_time_limit(0);
$errors = array();
$pending = false;
$hashes = array();
if(!isset($HTTP_RAW_POST_DATA))
	$HTTP_RAW_POST_DATA = file_get_contents("php://input");
$vars = explode('&', $HTTP_RAW_POST_DATA);
$trackerValues = array();
$comment = '';
$private = 0;
$setComment = false;
$setTrackers = false;
$setPrivate = false;
foreach($vars as $var)
{
	$parts = explode("=",$var,2);
	if(count($parts)!=2) continue;
	if($parts[0]=="hash")
		$hashes[] = $parts[1];
	else
	if($parts[0]=="comment")
		$comment = trim(rawurldecode($parts[1]));
	else
	if($parts[0]=="private")
		$private = intval($parts[1]);
	else
	if($parts[0]=="set_comment")
		$setComment = intval($parts[1]);
	else
	if($parts[0]=="set_trackers")
		$setTrackers = intval($parts[1]);
	else
	if($parts[0]=="set_private")
		$setPrivate = intval($parts[1]);
	else
	if($parts[0]=="tracker")
		$trackerValues[] = $parts[1];
}
$parsedTrackers = TorrentTrackerTiers::fromEditFormValues($trackerValues);
$announce_list = $parsedTrackers['tiers'];
$trackersCount = $parsedTrackers['count'];
if($setComment || $setTrackers || $setPrivate)
{
	foreach($hashes as $hash)
	{
		$req = new rXMLRPCRequest( array(
			new rXMLRPCCommand("get_session"),
			new rXMLRPCCommand("d.is_open",$hash),
			new rXMLRPCCommand("d.is_active",$hash),
			new rXMLRPCCommand("d.get_state",$hash),
			new rXMLRPCCommand("d.get_tied_to_file",$hash),
			new rXMLRPCCommand("d.get_custom1",$hash),
			new rXMLRPCCommand("d.get_directory_base",$hash),
			new rXMLRPCCommand("d.get_connection_seed",$hash),
			new rXMLRPCCommand("d.get_complete",$hash),
			) );
		if(rTorrentSettings::get()->isPluginRegistered("throttle"))
			$req->addCommand(new rXMLRPCCommand("d.get_throttle_name",$hash));
		if($req->run() && !$req->fault)
		{
			$isStart = (($req->val[1]!=0) && ($req->val[2]!=0) && ($req->val[3]!=0));
			$fname = $req->val[0].$hash.".torrent";
			if(empty($req->val[0]) || !is_readable($fname))
			{
				if(strlen($req->val[4]) && is_readable($req->val[4]))
					$fname = $req->val[4];
				else
					$fname = null;
			}
			if($fname)
			{
				$torrent = new Torrent( $fname );
				if( !$torrent->errors() )
				{
					// Read BEFORE the setters below; put back after
					// them. This is the user's own .torrent, edited in
					// place: changing a tracker, a comment or the private
					// flag does not make ruTorrent its author, and a
					// rewritten creation date travels out to the
					// "Created On" column and to the history plugin as
					// fact.
					//
					// On this fork the restore is a no-op today.
					// Torrent::touch() returns without writing anything
					// unless the class built the info dictionary itself
					// out of files on disk, which a path ending in
					// .torrent never triggers. It is kept because this
					// plugin is proposed to upstream Novik/ruTorrent
					// separately from php/Torrent.php (AGENTS.md,
					// "Upstream PR Handoff"), and upstream's touch()
					// still stamps both keys from is_private(),
					// announce(), announce_list(), comment() and their
					// clear_* siblings. Dropping the snapshot would
					// reinstate the data loss on any install carrying
					// this plugin without the class fix.
					$authored = array(
						'created by' => $torrent->meta('created by'),
						'creation date' => $torrent->meta('creation date'),
					);
					if($setPrivate)
					{
						$torrent->is_private($private);
					}
					if($setTrackers)
					{
						$torrent->clear_announce();
						$torrent->clear_announce_list();
						if(count($announce_list)>0)
						{
							$torrent->announce($announce_list[0][0]);
							if($trackersCount>1)
								$torrent->announce_list($announce_list);
						}
					}
					if($setComment)
					{
						$torrent->clear_comment();
						$comment = trim($comment);
						if(strlen($comment))
							$torrent->comment($comment);
					}
					// Put back what the file said, where absent is
					// also a value: clearing unconditionally would be the
					// mirror image of the bug, destroying a real author's
					// field because it assumed one could not be there.
					foreach($authored as $key => $value)
					{
						if(is_null($value))
							$torrent->clearMeta($key);
						else
							$torrent->setMeta($key, $value);
					}
					if(isset($torrent->{'rtorrent'}))
						unset($torrent->{'rtorrent'});
					$addition = array(
						getCmd("d.set_custom3")."=1",
						rTorrent::additionCommand("d.set_connection_seed",
							rXMLRPCRequest::unescapeValue($req->val[7])),
					);
					// d.get_throttle_name is only asked for when the throttle
					// plugin is registered, so without it there is no throttle
					// to forward and nothing is appended. An entry that is not
					// a command would refuse the whole reload.
					if(count($req->val)>9)
						$addition[] = rTorrent::additionCommand("d.set_throttle_name",
							rXMLRPCRequest::unescapeValue($req->val[9]));
					// Validate all reload commands before erasing the old torrent.
					if(!rTorrent::areValidAdditions($addition))
						$errors[] = array('desc'=>"theUILang.errorAddTorrent", 'prm'=>$fname);
					else
					{
						$eReq = new rXMLRPCRequest( new rXMLRPCCommand("d.erase", $hash ) );
						if($eReq->run() && !$eReq->fault)
						{
							$label = rawurldecode(rXMLRPCRequest::unescapeValue($req->val[5]));
							$reloaded = rTorrent::sendTorrent($torrent, $isStart, false,
								rXMLRPCRequest::unescapeValue($req->val[6]), $label, false,
								($req->val[8]==1), false, $addition);
							if($reloaded === false)
								$errors[] = array('desc'=>"theUILang.errorAddTorrent", 'prm'=>$fname);
							elseif($reloaded === null)
								$pending = true;
						}
						else
							$errors[] = array('desc'=>"theUILang.badLinkTorTorrent", 'prm'=>'');
					}
				}
				else
					$errors[] = array('desc'=>"theUILang.errorReadTorrent", 'prm'=>$fname);
			}
			else
				$errors[] = array('desc'=>"theUILang.cantFindTorrent", 'prm'=>'');
                }
        	        else
			$errors[] = array('desc'=>"theUILang.badLinkTorTorrent", 'prm'=>'');
	}
}

CachedEcho::send(JSON::safeEncode(array( "errors"=>$errors, "hash"=>$hashes, "pending"=>$pending )),"application/json");
