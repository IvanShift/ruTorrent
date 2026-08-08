/*
 * PLUGIN RUTRACKER_CHECK
 *
 * German language file.
 *
 * Author: Dario Rugani (kontakt@rugani.de)
 */

 theUILang.checkTorrent		= "Auf Update prüfen";
 theUILang.chkHdr		= "Torrent auf Update prüfen";
 theUILang.checkedAt		= "Letzte Prüfung";
 theUILang.checkedResult	= "Resultat";
 theUILang.chkResults		= [
 				  "In Bearbeitung",
 				  "Aktualisiert",
 				  "Kein Update nötig",
 				  "Wahrscheinlich gelöscht",
 				  "Fehler beim Zugriff auf Tracker",
 				  "Fehler beim Interagieren mit rTorrent",
 				  "Nicht nötig",
 				  "Ignoriert",
 				  "Warten auf Metadaten",
 				  "In einem anderen Thema aufgegangen — manuell klären"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"Die aktuelle Version dieses Themas ist bereits im Client: %s",
 				  "deleting":	"Das Thema fehlt in der Forumsliste; Bestätigungszyklus %s",
 				  "topic-status": "Themenstatus %s: geschlossen, nicht freigegeben oder ein Duplikat",
 				  "fuse":	"Tracker %s ist offenbar nicht erreichbar; die Prüfung wird verschoben"
 				  };

thePlugins.get("rutracker_check").langLoaded();
