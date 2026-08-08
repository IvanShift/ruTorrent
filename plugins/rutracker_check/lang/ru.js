/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Russian language file.
 *
 * Author:
 */

 theUILang.checkTorrent		= "Проверить обновление";
 theUILang.chkHdr		= "Проверка обновления торрента";
 theUILang.checkedAt		= "Произведена";
 theUILang.checkedResult	= "Результат";
 theUILang.chkResults		= [
 				  "В процессе",
 				  "Был обновлен",
 				  "Обновление не требуется",
 				  "Возможно, удален",
 				  "Ошибка доступа к трекеру",
 				  "Ошибка взаимодействия с rTorrent",
 				  "Нет необходимости",
 				  "Игнорируется",
 				  "Ожидание метаданных",
 				  "Поглощена другой раздачей — разберитесь вручную"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"Текущая версия этой раздачи уже есть в клиенте: %s",
 				  "deleting":	"Раздачи нет в списке форума; цикл подтверждения %s",
 				  "topic-status": "Статус темы %s: закрыта, не оформлена или повтор",
 				  "fuse":	"Трекер %s, похоже, недоступен; проверка отложена"
 				  };

thePlugins.get("rutracker_check").langLoaded();
