/*
 * PLUGIN EDIT
 *
 * Greek language file.
 *
 * Author: Chris Kanatas (ckanatas@gmail.com)
 */

 theUILang.EditTrackers			= "Επεξεργασία Torrent...";
 theUILang.EditTorrentProperties	= "Ιδιότητες του Torrent";
 theUILang.errorAddTorrent		= "Σφάλμα κατά την προσθήκη του αρχείου torrent";
 theUILang.errorWriteTorrent		= "Σφάλμα κατά την εγγραφή στο αρχείο torrent";
 theUILang.errorReadTorrent		= "Σφάλμα κατά την ανάγνωση από το αρχείο torrent";
 theUILang.cantFindTorrent		= "Το πηγαίο αρχείο torrent για αυτή τη λήψη δεν βρέθηκε."

// English fallback until these recovery notices are translated.
 theUILang.editPreflightFailed		= "Edit refused before erasing the torrent";
 theUILang.editEraseFailed		= "rTorrent reported an erase error; edited recovery copy retained";
 theUILang.editEraseUnknown		= "Erase outcome unknown; edited recovery copy retained";
 theUILang.editReloadPending		= "Reload confirmation pending; edited recovery copy retained";

thePlugins.get("edit").langLoaded();
