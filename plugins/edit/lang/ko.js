/*
 * PLUGIN EDIT
 *
 * Korean language file.
 *
 * Author: Limerainne (limerainne@gmail.com)
 */

 theUILang.EditTrackers			= "토렌트 편집...";
 theUILang.EditTorrentProperties	= "토렌트 속성";
 theUILang.errorAddTorrent		= "토렌트 파일 추가 실패";
 theUILang.errorWriteTorrent		= "토렌트 파일 쓰기 실패";
 theUILang.errorReadTorrent		= "토렌트 파일 읽기 실패";
 theUILang.cantFindTorrent		= "본 다운로드에 해당하는 원본 토렌트 파일을 찾지 못했습니다."

// English fallback until these recovery notices are translated.
 theUILang.editPreflightFailed		= "Edit refused before erasing the torrent";
 theUILang.editEraseFailed		= "rTorrent reported an erase error; edited recovery copy retained";
 theUILang.editEraseUnknown		= "Erase outcome unknown; edited recovery copy retained";
 theUILang.editReloadPending		= "Reload confirmation pending; edited recovery copy retained";

thePlugins.get("edit").langLoaded();
