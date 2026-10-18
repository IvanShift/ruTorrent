/*
 * PLUGIN RETRACKERS
 *
 * Norwegian (nynorsk) language file.
 *
 * Author: jakobbg (jakob@grimstveit.no)
 */

 theUILang.retrackers		= "Retrackerar";
 theUILang.retrackersAdd	= "Legg til Announce-URL-ar";
 theUILang.retrackersDel	= "Fjern Announce-URL-ar";
 theUILang.dontAddToPrivate	= "Ikkje rør private torrentar";
 theUILang.addToBegin		= "Legg til announce-URL-ar til byrjinga av tracker-lista";
 theUILang.retrackersTerminalJobs	= "Retrackers: queued jobs that used up their retries: %s. They are no longer retried automatically. After fixing the cause, run %s on the server to list them, then rerun it with a listed hash and --apply to reset one.";

thePlugins.get("retrackers").langLoaded();
