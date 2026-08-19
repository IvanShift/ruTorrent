/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Greek language file.
 *
 * Author: Chris Kanatas (ckanatas@gmail.com)
 */

 theUILang.checkTorrent		= "Έλεγχος για ενημέρωση";
 theUILang.chkHdr		= "Έλεγχος ενημέρωσης Torrent";
 theUILang.checkedAt		= "Τελευταίος έλεγχος";
 theUILang.checkedResult	= "Αποτέλεσμα";
 theUILang.chkResults		= [
 				  "Σε διαδικασία",
 				  "Ενημερώθηκε",
 				  "Ενημερωμένο",
 				  "Μάλλον διαγράφηκε",
 				  "Σφάλμα πρόσβασης στον tracker",
 				  "Σφάλμα αλληλεπίδρασης με το rTorrent",
 				  "Δεν χρειάζεται",
 				  "Αγνοήθηκε",
 				  "Αναμονή μεταδεδομένων",
 				  "Απορροφήθηκε από άλλο θέμα — επιλύστε χειροκίνητα"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"Η τρέχουσα έκδοση αυτού του θέματος υπάρχει ήδη στο πρόγραμμα-πελάτη: %s",
 				  "deleting":	"Το θέμα δεν υπάρχει στη λίστα του φόρουμ· κύκλος επιβεβαίωσης %s",
 				  "topic-status": "Κατάσταση θέματος %s: κλειστό, μη εγκεκριμένο ή διπλότυπο",
 				  "fuse":	"Ο tracker %s φαίνεται μη διαθέσιμος· ο έλεγχος αναβάλλεται"
 				  };

thePlugins.get("rutracker_check").langLoaded();
