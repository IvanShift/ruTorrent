/*
 * PLUGIN RETRACKERS
 *
 * English language file.
 *
 * Author:
 */

 theUILang.retrackers		= "Retrackers";
 theUILang.retrackersAdd	= "Add Announce URLs";
 theUILang.retrackersDel	= "Remove Announce URLs";
 theUILang.dontAddToPrivate	= "Don't touch private torrents";
 theUILang.addToBegin		= "Add announce URLs to the beginning of the tracker list";
 theUILang.retrackersEnqueueRefusals	= "Retrackers: session storage refused queue admission for %s torrent(s). Repair session storage, then run %s on the server to review candidates and rerun with --apply. Existing torrent metadata is preserved.";
 theUILang.retrackersTerminalJobs	= "Retrackers: queued jobs that used up their retries: %s. They are no longer retried automatically. After fixing the cause, run %s on the server to list them, then rerun it with a listed hash and --apply to reset one.";

thePlugins.get("retrackers").langLoaded();
