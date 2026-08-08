/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Norwegian language file.
 *
 * Author: nirosa (nirosax@gmail.com)
 */

 theUILang.checkTorrent		= "Søk etter oppdateringer";
 theUILang.chkHdr		= "Torrent-oppdateringssjekk";
 theUILang.checkedAt		= "Sist sjekket";
 theUILang.checkedResult	= "Resultat";
 theUILang.chkResults		= [
 				  "Pågår",
 				  "Oppdatert",
 				  "Ingen oppdatering nødvendig",
 				  "Sannsynligvis slettet",
 				  "Tilgangsfeil med trackeren oppstod",
 				  "Kommunikasjonsfeil med rTorrent oppstod",
 				  "Ikke nødvendig",
 				  "Ignorert",
 				  "Venter på metadata",
 				  "Slått sammen med et annet emne — løs det manuelt"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"Den nåværende versjonen av dette emnet finnes allerede i klienten: %s",
 				  "deleting":	"Emnet mangler i forumlisten; bekreftelsesrunde %s",
 				  "topic-status": "Emnestatus %s: lukket, ikke godkjent eller et duplikat",
 				  "fuse":	"Trackeren %s ser ut til å være utilgjengelig; sjekken er utsatt"
 				  };

thePlugins.get("rutracker_check").langLoaded();
