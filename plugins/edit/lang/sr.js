/*
 * PLUGIN EDIT
 *
 * Serbian language file.
 *
 * Author: Zoltan Csala (zcsala021 at gmail dot com)
 */

 theUILang.EditTrackers			= "Уреди торент...";
 theUILang.EditTorrentProperties	= "Својства торента";
 theUILang.errorAddTorrent		= "Грешка код додавања датотеке торенту";
 theUILang.errorWriteTorrent		= "Грешка код писања торент-датотеке";
 theUILang.errorReadTorrent		= "Грешка код читања торент-датотеке";
 theUILang.cantFindTorrent		= "Изворна торент-датотека за овај низтовар није нађена."

// English fallback until these recovery notices are translated.
 theUILang.editPreflightFailed		= "Edit refused before erasing the torrent";
 theUILang.editEraseFailed		= "rTorrent reported an erase error; edited recovery copy retained";
 theUILang.editEraseUnknown		= "Erase outcome unknown; edited recovery copy retained";
 theUILang.editReloadPending		= "Reload confirmation pending; edited recovery copy retained";

thePlugins.get("edit").langLoaded();
