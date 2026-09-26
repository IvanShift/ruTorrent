/*
 * PLUGIN RUTRACKER_CHECK
 *
 * French language file.
 *
 * Author: Nicobubulle (nicobubulle@gmail.com)
 */

 theUILang.checkTorrent		= "Vérifier les MAJ";
 theUILang.checkTorrentRejected	= "Demande de vérification des mises à jour invalide";
 theUILang.checkTorrentRefused	= "Impossible de lancer la vérification des mises à jour";
 theUILang.checkTorrentUnexpected	= "Réponse inattendue à la vérification des mises à jour";
 theUILang.chkHdr		= "Vérification de la MAJ du torrent";
 theUILang.checkedAt		= "Dernière vérification";
 theUILang.checkedResult	= "Resultat";
 theUILang.chkResults		= [
 				  "En cours",
 				  "Mis à jour",
 				  "A jour",
 				  "Certainement supprimé",
 				  "Erreur d'accès au tracker",
 				  "Problème d'accès à rTorrent",
 				  "Pas besoin",
 				  "Ignoré",
 				  "En attente des métadonnées",
 				  "Absorbé par un autre sujet — à régler manuellement"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"La version actuelle de ce sujet est déjà dans rTorrent : %s",
 				  "deleting":	"Sujet absent de la liste du forum ; cycle de confirmation %s",
 				  "topic-status": "Statut du sujet %s : fermé, non approuvé ou en doublon",
 				  "fuse":	"Le tracker %s semble indisponible ; la vérification est reportée"
 				  };

thePlugins.get("rutracker_check").langLoaded();
