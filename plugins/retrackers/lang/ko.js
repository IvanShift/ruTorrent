/*
 * PLUGIN RETRACKERS
 *
 * Korean language file.
 *
 * Author: Limerainne (limerainne@gmail.com)
 */

 theUILang.retrackers		= "Retrackers";
 theUILang.retrackersAdd	= "Announce 추가";
 theUILang.retrackersDel	= "Announce 제거";
 theUILang.dontAddToPrivate	= "비공개 토렌트에는 사용하지 않음";
 theUILang.addToBegin		= "트래커 목록 앞에 Announce 추가";
 theUILang.retrackersTerminalJobs	= "Retrackers: queued jobs that used up their retries: %s. They are no longer retried automatically. After fixing the cause, run %s on the server to list them, then rerun it with a listed hash and --apply to reset one.";

thePlugins.get("retrackers").langLoaded();
