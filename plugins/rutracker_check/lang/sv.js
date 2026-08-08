/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Swedish language file.
 *
 * Author: Magnus Holm (holmen@brasse.se)
 */

 theUILang.checkTorrent		= "Sök efter uppdateringar";
 theUILang.chkHdr		= "Torrent-uppdateringskontroll";
 theUILang.checkedAt		= "Senaste sökning";
 theUILang.checkedResult	= "Resultat";
 theUILang.chkResults		= [
 				  "Pågår",
 				  "Updaterad",
 				  "Ingen uppdatering behövs",
 				  "Förmodligen borttagen",
 				  "Fel, uppstigning tracker",
 				  "Fel vid interaktivitet med rTorrent",
 				  "Behövs inte",
 				  "Ignorerad",
 				  "Väntar på metadata",
 				  "Sammanslagen med ett annat ämne — åtgärda manuellt"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"Den aktuella versionen av det här ämnet finns redan i klienten: %s",
 				  "deleting":	"Ämnet saknas i forumlistan; bekräftelseomgång %s",
 				  "topic-status": "Ämnesstatus %s: stängt, inte godkänt eller en dubblett",
 				  "fuse":	"Trackern %s verkar vara otillgänglig; kontrollen skjuts upp"
 				  };

thePlugins.get("rutracker_check").langLoaded();
