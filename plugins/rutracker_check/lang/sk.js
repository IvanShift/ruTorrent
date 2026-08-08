/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Slovak language file.
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
 				  "Čakanie na metadáta",
 				  "Zlúčené s inou témou — vyriešte ručne"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"Aktuálna verzia tejto témy je už v kliente: %s",
 				  "deleting":	"Téma chýba v zozname fóra; potvrdzovací cyklus %s",
 				  "topic-status": "Stav témy %s: zatvorená, neschválená alebo duplikát",
 				  "fuse":	"Tracker %s sa zdá nedostupný; kontrola je odložená"
 				  };

thePlugins.get("rutracker_check").langLoaded();
