plugin.loadLang();

if(plugin.canChangeMenu())
{
	plugin.createMenu = theWebUI.createMenu;
	theWebUI.createMenu = function( e, id )
	{
		plugin.createMenu.call(this, e, id);
		if(plugin.enabled && plugin.canChangeMenu())
		{
			var el = theContextMenu.get( theUILang.Force_recheck );
			if( el )
			{
				theContextMenu.add( el, [theUILang.checkTorrent,
					((this.getTable("trt").selCount>1) && this.getHashes('checktorrent')) ||
					this.isTorrentCommandEnabled("checktorrent",id) ? "theWebUI.perform( 'checktorrent' )" : null] );
			}
		}
	}

	rTorrentStub.prototype.checktorrent = function()
	{
		this.content = "cmd=check";
		for( var i = 0; i < this.hashes.length; i++ )
			this.content += ("&hash="+this.hashes[i]);
		this.contentType = "application/x-www-form-urlencoded";
		this.mountPoint = "plugins/rutracker_check/action.php";
		this.dataType = "json";
	}
}

plugin.config = theWebUI.config;
theWebUI.config = function()
{
	plugin.reqId1 = theRequestManager.addRequest("trt", theRequestManager.map("d.get_custom=")+"chk-state",function(hash,torrent,value)
	{
		torrent.chkstate = value;
		if(torrent.chkstate==4 || torrent.chkstate==10)	// STE_DELETED, STE_ABSORBED
			torrent.state |= dStatus.error;
	});
	plugin.reqId2 = theRequestManager.addRequest("trt", theRequestManager.map("d.get_custom=")+"chk-time",function(hash,torrent,value)
	{
		torrent.chktime = value;
	});
	plugin.reqId3 = theRequestManager.addRequest("trt", theRequestManager.map("d.get_custom=")+"chk-msg",function(hash,torrent,value)
	{
		torrent.chkmsg = value;
	});
	plugin.config.call(this);
}

plugin.isTorrentCommandEnabled = theWebUI.isTorrentCommandEnabled;
theWebUI.isTorrentCommandEnabled = function(act,hash)
{
	if(act=="checktorrent")
	{
		var regex = '^(http|https)://';
		return(hash && (hash.length==40) && !(hash.match(regex)));
	}

	return(plugin.isTorrentCommandEnabled.call(this,act,hash));
}

// chk-msg is written by PHP as "<token>|<parameter>" (see ruTrackerChecker's
// CHKMSG_* constants), never as prose: the sentence itself lives here, in the
// viewer's own language, and the parameter is substituted for its %s.
// "absorbed" needs no sentence at all -- the status label already says
// "absorbed, resolve manually", so its topic id just becomes a plain URL.
// The result is used as an icon tooltip and via .text(), so it stays PLAIN
// TEXT; anything unrecognised renders as nothing rather than as "undefined".
plugin.chkMessageText = function(message)
{
	var raw = "" + message;
	var separator = raw.indexOf("|");
	if(separator < 1)
		return("");
	var token = raw.substr(0, separator);
	var param = raw.substr(separator + 1);
	if(param === "")
		return("");
	if(token == "absorbed")
		return(/^[0-9]+$/.test(param) ? "https://rutracker.org/forum/viewtopic.php?t=" + param : "");
	var sentence = theUILang.chkMessages ? theUILang.chkMessages[token] : null;
	if(typeof sentence != "string")
		return("");
	// Function replacement: a parameter containing "$&" or "$1" must be
	// inserted literally, not interpreted as a replacement pattern.
	return(sentence.replace("%s", function() { return(param); }));
}

// Status text for the chk-state result, with the chk-msg detail appended when present.
plugin.chkResultText = function(torrent)
{
	var text = theUILang.chkResults[torrent.chkstate-1];
	if(typeof text != "string")
		text = "";
	var detail = torrent.chkmsg ? plugin.chkMessageText(torrent.chkmsg) : "";
	if(detail)
		text += (text ? " — " : "") + detail;
	return(text);
}

plugin.getStatusIcon = theWebUI.getStatusIcon;
theWebUI.getStatusIcon = function(torrent)
{
	if(plugin.allStuffLoaded && (torrent.chkstate==4 || torrent.chkstate==10))	// STE_DELETED, STE_ABSORBED
		return(["Status_Error", plugin.chkResultText(torrent)]);
	return(	plugin.getStatusIcon.call(theWebUI,torrent) );
}

plugin.updateDetails = theWebUI.updateDetails;
theWebUI.updateDetails = function()
{
	plugin.updateDetails.call(this);
	if(plugin.enabled && (this.dID != "") && this.torrents[this.dID])
	{
		var torrent = this.torrents[this.dID];
		$("#chktime").text( torrent.chktime ? theConverter.time(new Date().getTime()/1000-(iv(torrent.chktime)+theWebUI.deltaTime/1000),true) : '' );
		$("#chkresult").text( torrent.chkstate ? plugin.chkResultText(torrent) : '' );
	}
}

plugin.onLangLoaded = function()
{
	$("#mainlayout").append(
		$("<div>").addClass("row Header").append(
			$("<div>").attr({id:"chkinfo1"}).addClass("col-12").append(
				$("<span>").text(theUILang.chkHdr),
			),
		),
		$("<div>").addClass("row").append(
			$("<div>").attr({id:"chkinfo2"}).addClass("col-12 col-md-2").append(
				$("<span>").addClass("det-hdr").text(theUILang.checkedAt + ": "),
			),
			$("<div>").addClass("col-12 col-md-10").append(
				$("<span>").attr({id:"chktime"}).addClass("det"),
			),
			$("<div>").addClass("col-12 col-md-2").append(
				$("<span>").addClass("det-hdr").text(theUILang.checkedResult + ": "),
			),
			$("<div>").addClass("col-12 col-md-10").append(
				$("<span>").attr({id:"chkresult"}).addClass("det"),
			),
		),
	);
}

plugin.onRemove = function()
{
	$("#chkinfo1,#chkinfo2").remove();
	theRequestManager.removeRequest( "trt", plugin.reqId1 );
	theRequestManager.removeRequest( "trt", plugin.reqId2 );
	theRequestManager.removeRequest( "trt", plugin.reqId3 );
	$.each(theWebUI.torrents,function(hash,torrent)
	{
		delete torrent.chkstate;
		delete torrent.chktime;
		delete torrent.chkmsg;
	});
}
