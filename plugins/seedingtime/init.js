plugin.loadLang();

if(plugin.canChangeColumns())
{
	plugin.config = theWebUI.config;
	theWebUI.config = function()
	{
		this.tables.trt.columns.push({text: 'SeedingTime', width: '100px', id: 'seedingtime', type: TYPE_NUMBER});
		this.tables.trt.columns.push({text: 'AddTime', width: '110px', id: 'addtime', type: TYPE_NUMBER});
		plugin.trtFormat = this.tables.trt.format;
		this.tables.trt.format = function(table,arr)
		{
			for(var i in arr)
			{
			        var s = table.getIdByCol(i);
				if(s=="seedingtime")
					arr[i] = arr[i] != -1 ? theConverter.time(arr[i],true) : "";
				else
				if(s=="addtime")
					arr[i] = arr[i] != -1 ? theConverter.date(arr[i]) : "";
		        }
			return(plugin.trtFormat(table,arr));
		}
		plugin.config.call(this);
		plugin.reqId1 = theRequestManager.addRequest("trt", theRequestManager.map("d.get_custom=")+"seedingtime",function(hash,torrent,value)
		{
			const epochSeconds = iv(value);
			torrent.seedingtime = (epochSeconds > 3600*24*365) ? Math.max(0, new Date().getTime()/1000-(epochSeconds+theWebUI.deltaTime/1000)) : -1;
		});
		plugin.reqId2 = theRequestManager.addRequest("trt", theRequestManager.map("d.get_custom=")+"addtime",function(hash,torrent,value)
		{
			const epochSeconds = iv(value);
			torrent.addtime = (epochSeconds > 3600*24*365) ? epochSeconds : -1;
			// "Created On" is d.creation_date -- the date the torrent's AUTHOR
			// built the file. A torrent added from a magnet link has none and
			// never will: BEP 9 transfers the info dictionary alone, and
			// 'creation date' is a top level key that never travels with it.
			// The column then stays blank for the rest of that torrent's life.
			//
			// Fall back to the add time so the cell is not empty. This lives
			// here rather than in js/rtorrent.js because addtime is fetched
			// here and nowhere else -- without this plugin the value the
			// fallback needs does not exist in the page at all.
			//
			// Only ever fills a MISSING date: a torrent that carries a real
			// creation date keeps it untouched.
			// Assigned as a string: rTorrentStub sets torrent.created from the
			// raw XML-RPC value, so keeping the type uniform across torrents
			// keeps the column's sort comparing like with like.
			if(iv(torrent.created) <= 0 && torrent.addtime > 0)
				torrent.created = String(torrent.addtime);
		});
		plugin.trtRenameColumn();
	}

	plugin.trtRenameColumn = function()
	{
		if(plugin.allStuffLoaded)
		{
			theWebUI.getTable("trt").renameColumnById("seedingtime",theUILang.seedingTime);
			theWebUI.getTable("trt").renameColumnById("addtime",theUILang.addTime);
			if(thePlugins.isInstalled("rss"))
				plugin.rssRenameColumn();
			if(thePlugins.isInstalled("extsearch"))
				plugin.tegRenameColumn();
		}
		else
			setTimeout(arguments.callee,1000);
	}

	plugin.rssRenameColumn = function()
	{
		if(theWebUI.getTable("rss").created)
		{
			theWebUI.getTable("rss").renameColumnById("seedingtime",theUILang.seedingTime);
			theWebUI.getTable("rss").renameColumnById("addtime",theUILang.addTime);
		}
		else
			setTimeout(arguments.callee,1000);
	}

	plugin.tegRenameColumn = function()
	{
		if(theWebUI.getTable("teg").created)
		{
			theWebUI.getTable("teg").renameColumnById("seedingtime",theUILang.seedingTime);
			theWebUI.getTable("teg").renameColumnById("addtime",theUILang.addTime);
		}
		else
			setTimeout(arguments.callee,1000);
	}
}

plugin.onRemove = function()
{
	theWebUI.getTable("trt").removeColumnById("seedingtime");
	theWebUI.getTable("trt").removeColumnById("addtime");
	if(thePlugins.isInstalled("rss"))
	{
		theWebUI.getTable("rss").removeColumnById("seedingtime");
		theWebUI.getTable("rss").removeColumnById("addtime");
	}
	theRequestManager.removeRequest( "trt", plugin.reqId1 );
	theRequestManager.removeRequest( "trt", plugin.reqId2 );
}
