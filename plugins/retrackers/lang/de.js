/*
 * PLUGIN RETRACKERS
 *
 * German language file.
 *
 * Author: Dario Rugani (kontakt@rugani.de)
 */

 theUILang.retrackers		= "Retrackers";
 theUILang.retrackersAdd	= "Announces hinzufügen";
 theUILang.retrackersDel	= "Announces löschen";
 theUILang.dontAddToPrivate	= "Private Torrents ausschließen";
 theUILang.addToBegin		= "Announces zum Anfang der Trackers-Liste hinzufügen";
 theUILang.retrackersTerminalJobs	= "Retrackers: queued jobs that used up their retries: %s. They are no longer retried automatically. After fixing the cause, run %s on the server to list them, then rerun it with a listed hash and --apply to reset one.";

thePlugins.get("retrackers").langLoaded();
