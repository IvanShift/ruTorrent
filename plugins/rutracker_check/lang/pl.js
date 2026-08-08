/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Polish language file.
 *
 * Author: Dare (piczok@gmail.com)
 */

 theUILang.checkTorrent		= "Sprawdź aktualizacje";
 theUILang.chkHdr		= "Sprawdź aktualizacje torrenta";
 theUILang.checkedAt		= "Ostatnio sprawdzane";
 theUILang.checkedResult	= "Wynik";
 theUILang.chkResults		= [
 				  "W trakcie",
 				  "Zaktualizowano",
 				  "Bieżące",
 				  "Prawdopodobnie usunięte",
 				  "Błąd podczas próby dostępu do trackera",
 				  "Błąd interakcji z rTorrentem",
 				  "Nie potrzeba",
 				  "Zignorowano",
 				  "Oczekiwanie na metadane",
 				  "Scalone z innym tematem — rozwiąż ręcznie"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"Aktualna wersja tego tematu jest już w kliencie: %s",
 				  "deleting":	"Brak tematu na liście forum; cykl potwierdzania %s",
 				  "topic-status": "Status tematu %s: zamknięty, niezatwierdzony lub duplikat",
 				  "fuse":	"Tracker %s wydaje się niedostępny; sprawdzanie odłożone"
 				  };

thePlugins.get("rutracker_check").langLoaded();
