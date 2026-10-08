/*
 * PLUGIN RETRACKERS
 *
 * Italian language file.
 *
 * Author: Gianni
 */

 theUILang.retrackers		= "Retracker";
 theUILang.retrackersAdd	= "Aggiungi URL Announce";
 theUILang.retrackersDel	= "Rimuovi URL Announce";
 theUILang.dontAddToPrivate	= "Non modificare torrent privati";
 theUILang.addToBegin		= "Aggiungi URL Announce all'inizio della lista dei tracker";
 theUILang.retrackersTerminalJobs	= "Retrackers: queued jobs that used up their retries: %s. They are no longer retried automatically. After fixing the cause, run %s on the server to list them, then rerun it with a listed hash and --apply to reset one.";

thePlugins.get("retrackers").langLoaded();
