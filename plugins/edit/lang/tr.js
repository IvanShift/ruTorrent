/*
 * PLUGIN EDIT
 *
 * Turkish language file.
 *
 * Author: Selim Şumlu
 */

 theUILang.EditTrackers			= "Torrent'ı düzenle...";
 theUILang.EditTorrentProperties	= "Torrent Özellikleri";
 theUILang.errorAddTorrent		= "Torrent dosyası eklenirken hata oluştu";
 theUILang.errorWriteTorrent		=  "Torrent dosyası yazılırken hata oluştu";
 theUILang.errorReadTorrent		= "Torrent dosyası okunurken hata oluştu";
 theUILang.cantFindTorrent		= "Bu indirmenin kaynak torrent dosyası bulunamadı."

// English fallback until these recovery notices are translated.
 theUILang.editPreflightFailed		= "Edit refused before erasing the torrent";
 theUILang.editEraseFailed		= "rTorrent reported an erase error; edited recovery copy retained";
 theUILang.editEraseUnknown		= "Erase outcome unknown; edited recovery copy retained";
 theUILang.editReloadPending		= "Reload confirmation pending; edited recovery copy retained";

thePlugins.get("edit").langLoaded();
