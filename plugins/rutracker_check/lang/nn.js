/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Norwegian (nynorsk) language file.
 *
 * Author: jakobbg (jakob@grimstveit.no)
 */

 theUILang.checkTorrent		= "Søk etter oppdateringar";
 theUILang.checkTorrentRejected	= "Invalid update check request";
 theUILang.checkTorrentRefused	= "Could not start the update check";
 theUILang.checkTorrentUnexpected	= "Unexpected update check response";
 theUILang.chkHdr		= "Torrent-oppdateringssjekk";
 theUILang.checkedAt		= "Sist sjekka";
 theUILang.checkedResult	= "Resultat";
 theUILang.chkResults		= [
 				  "Pågår",
 				  "Oppdatert",
 				  "Oppdatering ikkje nødvendig",
 				  "Sannsynlegvis sletta",
 				  "Tilgangsfeil med trackeren oppstod",
 				  "Kommunikasjonsfeil med rTorrent oppstod",
 				  "Ikkje nødvendig",
 				  "Ignorert",
 				  "Waiting for metadata",
 				  "Absorbed by another topic — resolve manually"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"The current version of this topic is already in the client: %s",
 				  "deleting":	"The topic is missing from the forum list; confirmation cycle %s",
 				  "topic-status": "Topic status %s: closed, not approved, or a duplicate",
 				  "fuse":	"Tracker %s looks unavailable; the check is postponed"
 				  };

thePlugins.get("rutracker_check").langLoaded();
