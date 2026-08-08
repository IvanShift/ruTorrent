/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Korean language file.
 *
 * Author: Limerainne (limerainne@gmail.com)
 */

 theUILang.checkTorrent		= "업데이트 검사";
 theUILang.chkHdr		= "토렌트 업데이트 검사";
 theUILang.checkedAt		= "마지막 검사";
 theUILang.checkedResult	= "결과";
 theUILang.chkResults		= [
 				  "진행 중",
 				  "업데이트됨",
 				  "업데이트 불필요",
 				  "아마 삭제됨",
 				  "트래커 접근 중 오류",
 				  "rTorrent 통신 오류",
 				  "불필요함",
 				  "무시됨",
 				  "메타데이터 대기 중",
 				  "다른 게시글로 병합됨 — 직접 처리 필요"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"이 게시글의 현재 버전이 이미 클라이언트에 있습니다: %s",
 				  "deleting":	"포럼 목록에 이 게시글이 없습니다 (확인 주기 %s)",
 				  "topic-status": "게시글 상태 %s: 닫힘, 미승인 또는 중복",
 				  "fuse":	"트래커 %s에 접근할 수 없는 것으로 보여 검사를 연기했습니다"
 				  };

thePlugins.get("rutracker_check").langLoaded();
