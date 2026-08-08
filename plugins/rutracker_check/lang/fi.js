/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Finnish language file.
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
 				  "Odotetaan metatietoja",
 				  "Sulautettu toiseen ketjuun — selvitä käsin"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"Tämän ketjun nykyinen versio on jo asiakasohjelmassa: %s",
 				  "deleting":	"Ketjua ei ole foorumin listassa; varmistuskierros %s",
 				  "topic-status": "Ketjun tila %s: suljettu, hyväksymätön tai kaksoiskappale",
 				  "fuse":	"Seurantapalvelin %s ei näytä olevan saatavilla; tarkistus lykätty"
 				  };

thePlugins.get("rutracker_check").langLoaded();
