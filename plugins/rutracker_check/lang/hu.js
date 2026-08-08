/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Hungarian language file.
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
 				  "Várakozás a metaadatokra",
 				  "Beolvadt egy másik témába — rendezd kézzel"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"A téma jelenlegi verziója már megvan a kliensben: %s",
 				  "deleting":	"A téma nem szerepel a fórum listájában; megerősítési ciklus %s",
 				  "topic-status": "A téma állapota %s: lezárt, nem jóváhagyott vagy duplikátum",
 				  "fuse":	"%s tracker nem elérhetőnek tűnik; az ellenőrzés elhalasztva"
 				  };

thePlugins.get("rutracker_check").langLoaded();
