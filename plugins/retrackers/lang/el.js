/*
 * PLUGIN RETRACKERS
 *
 * Greek language file.
 *
 * Author: Chris Kanatas (ckanatas@gmail.com)
 */

 theUILang.retrackers		= "Retrackers";
 theUILang.retrackersAdd	= "Προσθήκη ανακοινώσεων";
 theUILang.retrackersDel	= "Αφαίρεση ανακοινώσεων";
 theUILang.dontAddToPrivate	= "Να μην πειραχτούν τα ιδιωτικά torrent";
 theUILang.addToBegin		= "Προσθήκη ανακοινώσεων στην αρχή της λίστας των tracker";
 theUILang.retrackersTerminalJobs	= "Retrackers: queued jobs that used up their retries: %s. They are no longer retried automatically. After fixing the cause, run %s on the server to list them, then rerun it with a listed hash and --apply to reset one.";

thePlugins.get("retrackers").langLoaded();
