/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Turkish language file.
 *
 * Authors: Müslüm Barış Korkmazer (bkbabinco@gmail.com)
 *		    Selim Şumlu
 */

 theUILang.checkTorrent		= "Güncellemeleri denetle";
 theUILang.chkHdr		= "Torrent Güncelleme Kontrolü";
 theUILang.checkedAt		= "Son kontrol";
 theUILang.checkedResult	= "Sonuç";
 theUILang.chkResults		= [
 				  "Sürüyor",
 				  "Güncellendi",
 				  "Güncelleme gerekli değil",
 				  "Muhtemelen silindi",
 				  "İzleyiciye erişim hatası",
 				  "rTorrent ile etkileşim hatası",
 				  "Gerek yok",
 				  "Yoksayılmış",
 				  "Üstveri bekleniyor",
 				  "Başka bir konuya dahil edildi — elle çözün"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"Bu konunun güncel sürümü zaten istemcide mevcut: %s",
 				  "deleting":	"Konu forum listesinde yok; doğrulama döngüsü %s",
 				  "topic-status": "Konu durumu %s: kapalı, onaylanmamış veya yinelenen",
 				  "fuse":	"%s izleyicisi erişilemez görünüyor; kontrol ertelendi"
 				  };

thePlugins.get("rutracker_check").langLoaded();
