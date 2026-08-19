/*
 * PLUGIN RUTRACKER_CHECK
 *
 * English language file.
 *
 * Author:
 */

 theUILang.checkTorrent		= "Check for Update";
 theUILang.chkHdr		= "Torrent Update Check";
 theUILang.checkedAt		= "Last Checked";
 theUILang.checkedResult	= "Result";
 theUILang.chkResults		= [
 				  "In progress",
 				  "Updated",
 				  "No update required",
 				  "Probably deleted",
 				  "Error accessing the tracker",
 				  "Error interacting with rTorrent",
 				  "No need",
 				  "Ignored",
 				  "Waiting for metadata",
 				  "Absorbed by another topic — resolve manually"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"The current version of this topic is already in the client: %s",
 				  "deleting":	"The topic is missing from the forum list; confirmation cycle %s",
 				  "topic-status": "Topic status %s: closed, not approved, or a duplicate",
 				  "fuse":	"Tracker %s looks unavailable; the check is postponed"
 				  };

thePlugins.get("rutracker_check").langLoaded();
