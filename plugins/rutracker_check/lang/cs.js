/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Czech language file.
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
 				  "Čekání na metadata",
 				  "Sloučeno s jiným tématem — vyřešte ručně"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"Aktuální verze tohoto tématu je již v klientu: %s",
 				  "deleting":	"Téma chybí v seznamu fóra; potvrzovací cyklus %s",
 				  "topic-status": "Stav tématu %s: uzavřeno, neschváleno nebo duplikát",
 				  "fuse":	"Tracker %s se zdá nedostupný; kontrola je odložena"
 				  };

thePlugins.get("rutracker_check").langLoaded();
