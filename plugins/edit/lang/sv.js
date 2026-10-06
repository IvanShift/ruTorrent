/*
 * PLUGIN EDIT
 *
 * Swedish language file.
 *
 * Author: Magnus Holm (holmen@brasse.se)
 */

 theUILang.EditTrackers			= "Redigera torrent...";
 theUILang.EditTorrentProperties	= "Torrentegenskaper";
 theUILang.errorAddTorrent		= "Fel när torrentfilen lades till";
 theUILang.errorWriteTorrent		= "Fel vid skrivning till torrentfil";
 theUILang.errorReadTorrent		= "Fel vid läsning av torrentfil";
 theUILang.cantFindTorrent		= "Källfil (.torrent) för denna nedladdning kunde inte hittas."

// English fallback until these recovery notices are translated.
 theUILang.editPreflightFailed		= "Edit refused before erasing the torrent";
 theUILang.editEraseFailed		= "rTorrent reported an erase error; edited recovery copy retained";
 theUILang.editEraseUnknown		= "Erase outcome unknown; edited recovery copy retained";
 theUILang.editReloadPending		= "Reload confirmation pending; edited recovery copy retained";

thePlugins.get("edit").langLoaded();
