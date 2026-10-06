/*
 * PLUGIN EDIT
 *
 * French language file.
 *
 * Author: Nicobubulle (nicobubulle@gmail.com)
 */

 theUILang.EditTrackers			= "Éditer le(s) torrent(s)...";
 theUILang.EditTorrentProperties	= "Propriétés du ou des torrents";
 theUILang.errorAddTorrent		= "Erreur lors de l'ajout du .torrent";
 theUILang.errorWriteTorrent		= "Erreur lors de l'écriture du .torrent";
 theUILang.errorReadTorrent		= "Erreur lors de la lecture du .torrent";
 theUILang.cantFindTorrent		= "Le fichier source pour ce torrent n'a pas été trouvé."

// English fallback until these recovery notices are translated.
 theUILang.editPreflightFailed		= "Edit refused before erasing the torrent";
 theUILang.editEraseFailed		= "rTorrent reported an erase error; edited recovery copy retained";
 theUILang.editEraseUnknown		= "Erase outcome unknown; edited recovery copy retained";
 theUILang.editReloadPending		= "Reload confirmation pending; edited recovery copy retained";

thePlugins.get("edit").langLoaded();
