/*
 * PLUGIN RETRACKERS
 *
 * Swedish language file.
 *
 * Author: Magnus Holm (holmen@brasse.se)
 */

 theUILang.retrackers		= "Retrackers";
 theUILang.retrackersAdd	= "Lägg till meddelanden";
 theUILang.retrackersDel	= "Ta bort meddelanden";
 theUILang.dontAddToPrivate	= "Påverka ej privata torrents";
 theUILang.addToBegin		= "Lägg till meddelanden i början av listan med trackers";
 theUILang.retrackersTerminalJobs	= "Retrackers: queued jobs that used up their retries: %s. They are no longer retried automatically. After fixing the cause, run %s on the server to list them, then rerun it with a listed hash and --apply to reset one.";

thePlugins.get("retrackers").langLoaded();
