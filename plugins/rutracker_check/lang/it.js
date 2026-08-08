/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Italian language file.
 *
 * Author: Gianni
 */

 theUILang.checkTorrent		= "Controlla agiornamenti";
 theUILang.chkHdr		= "Controlla aggiornamento torrent";
 theUILang.checkedAt		= "Ultimo controllo";
 theUILang.checkedResult	= "Risultato";
 theUILang.chkResults		= [
 				  "In corso",
 				  "Aggiornato",
 				  "Nessun aggiornamento richiesto",
 				  "Probabilmente cancellato",
 				  "Errore di accesso al tracker",
 				  "Errore di interazione con rTorrent",
 				  "Non serve",
 				  "Ignorato",
 				  "In attesa dei metadati",
 				  "Assorbito da un'altra discussione — da risolvere manualmente"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"La versione attuale di questa discussione è già nel client: %s",
 				  "deleting":	"Discussione assente dall'elenco del forum; ciclo di conferma %s",
 				  "topic-status": "Stato della discussione %s: chiusa, non approvata o duplicata",
 				  "fuse":	"Il tracker %s sembra non disponibile; il controllo è rinviato"
 				  };

thePlugins.get("rutracker_check").langLoaded();
