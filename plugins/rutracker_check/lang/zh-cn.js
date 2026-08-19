/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Chinese Simplified language file.
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
 				  "等待元数据",
 				  "已合并到其他主题 — 请手动处理"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"此主题的当前版本已在客户端中: %s",
 				  "deleting":	"此主题已不在论坛列表中; 确认周期 %s",
 				  "topic-status": "主题状态 %s: 已关闭, 未通过审核或重复发布",
 				  "fuse":	"Tracker %s 似乎不可用; 检查已推迟"
 				  };

thePlugins.get("rutracker_check").langLoaded();
