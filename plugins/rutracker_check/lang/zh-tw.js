/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Chinese Traditional language file.
 *
 * Author:
 */

 theUILang.checkTorrent		= "Check for Update";
 theUILang.chkHdr		= "Torrent Update Check";
 theUILang.checkedAt		= "Last Checked";
 theUILang.checkedResult	= "Result";
 theUILang.chkResults		= [
 				  "In progress",
 				  "Updated",
 				  "No update required",
 				  "Probably deleted",
 				  "Error accessing the tracker",
 				  "Error interacting with rTorrent",
 				  "No need",
 				  "Ignored",
 				  "等待詮釋資料",
 				  "已合併到其他主題 — 請手動處理"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"此主題的目前版本已在用戶端中: %s",
 				  "deleting":	"此主題已不在論壇列表中; 確認週期 %s",
 				  "topic-status": "主題狀態 %s: 已關閉, 未通過審核或重複發布",
 				  "fuse":	"Tracker %s 似乎無法使用; 檢查已延後"
 				  };

thePlugins.get("rutracker_check").langLoaded();
