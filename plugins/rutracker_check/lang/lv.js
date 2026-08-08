/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Latvian language file.
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
 				  "Gaida metadatus",
 				  "Apvienots ar citu tēmu — atrisiniet manuāli"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"Šīs tēmas pašreizējā versija jau ir klientā: %s",
 				  "deleting":	"Tēmas nav foruma sarakstā; apstiprinājuma cikls %s",
 				  "topic-status": "Tēmas statuss %s: slēgta, neapstiprināta vai dublikāts",
 				  "fuse":	"Trakeris %s, šķiet, nav pieejams; pārbaude atlikta"
 				  };

thePlugins.get("rutracker_check").langLoaded();
