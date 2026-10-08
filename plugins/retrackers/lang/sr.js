/*
 * PLUGIN RETRACKERS
 *
 * Serbian language file.
 *
 * Author: Zoltan Csala (zcsala021 at gmail dot com)
 */

 theUILang.retrackers		= "Ре-тракер";
 theUILang.retrackersAdd	= "Add Announce URLs";
 theUILang.retrackersDel	= "Remove Announce URLs";
 theUILang.dontAddToPrivate	= "Don't touch private torrents";
 theUILang.addToBegin		= "Add announce URLs to the beginning of the trackers list";
 theUILang.retrackersTerminalJobs	= "Retrackers: queued jobs that used up their retries: %s. They are no longer retried automatically. After fixing the cause, run %s on the server to list them, then rerun it with a listed hash and --apply to reset one.";

thePlugins.get("retrackers").langLoaded();
