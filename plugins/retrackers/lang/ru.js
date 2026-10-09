/*
 * PLUGIN RETRACKERS
 *
 * Russian language file.
 *
 * Author:
 */

 theUILang.retrackers		= "Ретрекеры";
 theUILang.retrackersAdd	= "Добавить анонсеры";
 theUILang.retrackersDel	= "Удалить анонсеры";
 theUILang.dontAddToPrivate	= "Не модифицировать частные раздачи";
 theUILang.addToBegin		= "Добавлять анонсеры в начало списка";
 theUILang.retrackersEnqueueRefusals	= "Ретрекеры: хранилище сессии отказало в постановке %s раздач в очередь. Восстановите хранилище сессии, затем выполните на сервере %s, чтобы проверить кандидатов, и повторите команду с --apply. Метаданные существующих раздач сохраняются.";
 theUILang.retrackersTerminalJobs	= "Ретрекеры: заданий очереди с исчерпанными попытками — %s; автоматически они больше не повторяются. Устраните причину, затем выполните на сервере %s, чтобы увидеть список, и повторите команду с хешем из списка и --apply, чтобы сбросить задание.";

thePlugins.get("retrackers").langLoaded();
