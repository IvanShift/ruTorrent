/*
 * PLUGIN EDIT
 *
 * Polish language file.
 *
 * Author: Dare (piczok@gmail.com)
 */

 theUILang.EditTrackers			= "Edytuj torrent...";
 theUILang.EditTorrentProperties	= "Właściwości torrenta";
 theUILang.errorAddTorrent		= "Błąd podczas dodawania pliku torrent";
 theUILang.errorWriteTorrent		= "Błąd podczas zapisywania pliku torrent";
 theUILang.errorReadTorrent		= "Błąd podczas odczytu pliku torrent";
 theUILang.cantFindTorrent		= "Źródło pliku torrent dla tego pobrania nie zostało znalezione."

// English fallback until these recovery notices are translated.
 theUILang.editPreflightFailed		= "Edit refused before erasing the torrent";
 theUILang.editEraseFailed		= "rTorrent reported an erase error; edited recovery copy retained";
 theUILang.editEraseUnknown		= "Erase outcome unknown; edited recovery copy retained";
 theUILang.editReloadPending		= "Reload confirmation pending; edited recovery copy retained";

thePlugins.get("edit").langLoaded();
