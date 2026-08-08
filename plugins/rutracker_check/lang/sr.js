/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Serbian language file.
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
 				  "Чекање на метаподатке",
 				  "Спојено са другом темом — решите ручно"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"Тренутна верзија ове теме је већ у клијенту: %s",
 				  "deleting":	"Теме нема на листи форума; циклус потврђивања %s",
 				  "topic-status": "Статус теме %s: затворена, неодобрена или дупликат",
 				  "fuse":	"Трекер %s изгледа недоступан; провера је одложена"
 				  };

thePlugins.get("rutracker_check").langLoaded();
