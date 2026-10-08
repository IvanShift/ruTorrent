/*
 * PLUGIN RETRACKERS
 *
 * Chinese Simplified language file.
 *
 * Author:
 */

 theUILang.retrackers		= "Retrackers";
 theUILang.retrackersAdd	= "增加 Announce URLs";
 theUILang.retrackersDel	= "移除 Announce URLs";
 theUILang.dontAddToPrivate	= "不要修改私有的 torrents";
 theUILang.addToBegin		= "将 announce URLs 添加到 trackers 列表的开头";
 theUILang.retrackersTerminalJobs	= "Retrackers: queued jobs that used up their retries: %s. They are no longer retried automatically. After fixing the cause, run %s on the server to list them, then rerun it with a listed hash and --apply to reset one.";

thePlugins.get("retrackers").langLoaded();
