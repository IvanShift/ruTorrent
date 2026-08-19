/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Dutch language file.
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
 				  "Wachten op metadata",
 				  "Opgenomen in een ander onderwerp – handmatig oplossen"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"De huidige versie van dit onderwerp is al aanwezig in de client: %s",
 				  "deleting":	"Het onderwerp ontbreekt in de forumlijst; bevestigingsronde %s",
 				  "topic-status": "Onderwerpstatus %s: gesloten, niet goedgekeurd of een duplicaat",
 				  "fuse":	"Tracker %s lijkt niet beschikbaar; de controle is uitgesteld"
 				  };

thePlugins.get("rutracker_check").langLoaded();
