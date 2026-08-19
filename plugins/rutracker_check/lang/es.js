/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Spanish language file.
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
 				  "Esperando metadatos",
 				  "Absorbido por otro tema — resolver manualmente"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"La versión actual de este tema ya está en rTorrent: %s",
 				  "deleting":	"El tema no aparece en la lista del foro; ciclo de confirmación %s",
 				  "topic-status": "Estado del tema %s: cerrado, no aprobado o duplicado",
 				  "fuse":	"El tracker %s parece no estar disponible; la comprobación se aplaza"
 				  };

thePlugins.get("rutracker_check").langLoaded();
