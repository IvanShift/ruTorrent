/*
 * PLUGIN EDIT
 *
 * Ukrainian language file.
 *
 * Author: Oleksandr Natalenko (oleksandr@natalenko.name)
 */

 theUILang.EditTrackers			= "Редагувати...";
 theUILang.EditTorrentProperties	= "Властивості торента";
 theUILang.errorAddTorrent		= "Сталася помилка додавання торента";
 theUILang.errorWriteTorrent		= "Сталася помилка записування у файл";
 theUILang.errorReadTorrent		= "Сталася помилка читання з файлу";
 theUILang.cantFindTorrent		= "Вихідний файл торента для цього завантаження не знайдено."

// English fallback until these recovery notices are translated.
 theUILang.editPreflightFailed		= "Edit refused before erasing the torrent";
 theUILang.editEraseFailed		= "rTorrent reported an erase error; edited recovery copy retained";
 theUILang.editEraseUnknown		= "Erase outcome unknown; edited recovery copy retained";
 theUILang.editReloadPending		= "Reload confirmation pending; edited recovery copy retained";

thePlugins.get("edit").langLoaded();
