/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Danish language file.
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
 				  "Venter på metadata",
 				  "Slået sammen med et andet emne – løs det manuelt"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"Den aktuelle version af dette emne findes allerede i klienten: %s",
 				  "deleting":	"Emnet mangler i forumlisten; bekræftelsesrunde %s",
 				  "topic-status": "Emnestatus %s: lukket, ikke godkendt eller en dublet",
 				  "fuse":	"Tracker %s ser ikke ud til at være tilgængelig; kontrollen er udskudt"
 				  };

thePlugins.get("rutracker_check").langLoaded();
