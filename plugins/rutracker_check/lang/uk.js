/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Ukrainian language file.
 *
 * Author: Oleksandr Natalenko (oleksandr@natalenko.name)
 */

 theUILang.checkTorrent		= "Перевірити оновлення";
 theUILang.chkHdr		= "Перевірка оновлення torrent-файлу";
 theUILang.checkedAt		= "Виконано";
 theUILang.checkedResult	= "Результат";
 theUILang.chkResults		= [
 				  "Триває",
 				  "Оновлено",
 				  "Оновлення непотрібно",
 				  "Імовірно, видалено",
 				  "Сталася помилка доступу до трекера",
 				  "Сталася помилка взаємодії з rTorrent",
 				  "Немає необхідності",
 				  "Ігнорується",
 				  "Очікування метаданих",
 				  "Поглинуто іншою темою — розберіться вручну"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"Поточна версія цієї теми вже є в клієнті: %s",
 				  "deleting":	"Теми немає у списку форуму; цикл підтвердження %s",
 				  "topic-status": "Статус теми %s: закрита, не оформлена або повтор",
 				  "fuse":	"Трекер %s, здається, недоступний; перевірку відкладено"
 				  };

thePlugins.get("rutracker_check").langLoaded();
