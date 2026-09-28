plugin.loadLang();

if(plugin.canChangeMenu())
{
	theWebUI.removeWithData = function(force)
	{
		plugin.force_delete = force;
		if( theWebUI.settings["webui.confirm_when_deleting"] )
		{
			this.delmode = "removewithdata";
			askYesNo( theUILang.Remove_torrents, (plugin.force_delete && plugin.enableForceDeletion ? theUILang.Rem_torrents_with_path_prompt : theUILang.Rem_torrents_content_prompt), () => theWebUI.doRemove() );
		}
		else
			theWebUI.perform( "removewithdata" );
	}

    if ( plugin.replaceRemoveTorrent )
	{
	    if (plugin.enabled)
	    {
	        theWebUI.removeTorrent = function()
	        {
	            theWebUI.removeWithData( plugin.enableForceDeletion );
	        }
	    }
	}
	else
	{
	    plugin.createMenu = theWebUI.createMenu;
	    theWebUI.createMenu = function( e, id )
	    {
		    plugin.createMenu.call(this, e, id);
		    if(plugin.enabled)
		    {
			    var el = theContextMenu.get( theUILang.Remove );
			    if( el )
			    {
				    var _c0 = [];
				    _c0.push( [theUILang.Delete_data,
					    (this.getTable("trt").selCount>1) ||
					    this.isTorrentCommandEnabled("remove",id) ? () => theWebUI.removeWithData(false) : null] );
				    if( plugin.enableForceDeletion )
				    {
					    _c0.push( [theUILang.Delete_data_with_path,
						    (this.getTable("trt").selCount>1) ||
						    this.isTorrentCommandEnabled("remove",id) ? () => theWebUI.removeWithData(true) : null] );
				    }
				    theContextMenu.add( el, [CMENU_CHILD, theUILang.Remove_and, _c0] );
			    }
		    }
	    }
	}

	// A successful HTTP status can still contain members that admission refused
	// or whose erase result is unresolved. The desktop action immediately follows
	// with a list refresh, so show the classified count before that response is
	// replaced by the fresh torrent list.
	rTorrentStub.prototype.removewithdataResponse = function(data)
	{
		if(data && Array.isArray(data.refused) && data.refused.length)
			noty("Deletion partly refused: " + data.refused.length +
				" torrent(s). Check the server log.", "error");
		if(data && Array.isArray(data.retained) && data.retained.length)
			noty("Deletion outcome unresolved for " + data.retained.length +
				" torrent(s). Check the server log.", "error");
		return data;
	}

	rTorrentStub.prototype.removewithdata = function()
	{
		if (plugin.debug) console.log("erasedata: removewithdata called, hashes:", this.hashes, "getCommon available:", typeof this.getCommon === "function");
		if (typeof this.getCommon === "function") {
			if (plugin.debug) console.log("erasedata: routing through getCommon (trusted handler)");
			this.vs[0] = (plugin.force_delete && plugin.enableForceDeletion ? "2" : "1");
			this.getCommon("removewithdata");
		} else {
			if (plugin.debug) console.log("erasedata: routing through plugins/erasedata/action.php");
			// No httprpc: POST to erasedata's own endpoint, which records the
			// delete list and erases (same shared logic httprpc uses). Setting
			// content without queuing commands makes the stub send it as-is,
			// like httprpc's getCommon().
			this.contentType = "application/x-www-form-urlencoded";
			this.mountPoint = "plugins/erasedata/action.php";
			this.dataType = "json";
			this.content = "mode=removewithdata";
			for( var i = 0; i < this.hashes.length; i++ )
				this.content += "&hash=" + this.hashes[i];
			this.content += "&v=" + (plugin.force_delete && plugin.enableForceDeletion ? "2" : "1");
		}
	}
}

// The drain can keep a durable obligation after the removal request has left
// the browser. Surface a queue that stays nonempty through several worker ticks.
plugin.init = function()
{
	plugin.queueSince = null;
	plugin.queueMessage = null;
	plugin.queueRemoved = false;
	plugin.queueUnavailableMessage = "Deletion queue status unavailable. Inspect the erasedata queue.";
	plugin.addPaneToStatusbar("erasedata-queue-pane",
		$("<div>").append($("<span>").attr("id", "erasedata-queue-status")),
		1, true);
	$("#erasedata-queue-pane").hide();

	plugin.showQueueStatus = function(message)
	{
		if(plugin.queueMessage === message)
			return;
		plugin.queueMessage = message;
		$("#erasedata-queue-status").text(message || "");
		$("#erasedata-queue-pane").toggle(!!message);
		if(message)
			noty(message, "error");
	};
	plugin.checkQueue = function()
	{
		$.ajax({
			type: "GET",
			url: "plugins/erasedata/status.php",
			dataType: "json",
			cache: false,
			timeout: theWebUI.settings["webui.reqtimeout"],
			success: function(status)
			{
				if(plugin.queueRemoved)
					return;
				if(!status || typeof status.candidates !== "number" || status.unreadable)
				{
					plugin.showQueueStatus(plugin.queueUnavailableMessage);
					return;
				}
				if(plugin.queueMessage === plugin.queueUnavailableMessage)
					plugin.showQueueStatus(null);
				if(status.empty)
				{
					plugin.queueSince = null;
					plugin.showQueueStatus(null);
				}
				else
				{
					if(plugin.queueSince === null)
						plugin.queueSince = Date.now();
					if(Date.now() - plugin.queueSince >= 300000)
						plugin.showQueueStatus("Deletion queue remains nonempty. Check the server log.");
				}
			},
			error: function()
			{
				if(!plugin.queueRemoved)
					plugin.showQueueStatus(plugin.queueUnavailableMessage);
			},
			complete: function()
			{
				if(!plugin.queueRemoved)
					plugin.queueTimer = window.setTimeout(plugin.checkQueue, 60000);
			}
		});
	};
	plugin.checkQueue();
	plugin.markLoaded();
};

plugin.onRemove = function()
{
	plugin.queueRemoved = true;
	if(plugin.queueTimer)
		window.clearTimeout(plugin.queueTimer);
	plugin.removePaneFromStatusbar("erasedata-queue-pane");
};

plugin.init();
