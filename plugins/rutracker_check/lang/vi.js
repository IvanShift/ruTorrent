/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Vietnamese language file.
 *
 * Author: Ta Xuan Truong (truongtx8 AT gmail DOT com)
 */

 theUILang.checkTorrent		= "Kiểm tra xem có cập nhật";
 theUILang.chkHdr		= "Kiểm tra cập nhật Torrent";
 theUILang.checkedAt		= "Lần kiểm tra cuối";
 theUILang.checkedResult	= "Kết quả";
 theUILang.chkResults		= [
 				  "Đang thực hiện",
 				  "Cập nhật",
 				  "Không yêu cầu cập nhật",
 				  "Có thể đã xóa",
 				  "Lỗi truy cập máy theo dỗi",
 				  "Lỗi giao tiếp với rTorrent",
 				  "Không cần thiết",
 				  "Bị bỏ qua",
 				  "Đang chờ dữ liệu mô tả",
 				  "Đã gộp vào chủ đề khác — cần xử lý thủ công"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"Phiên bản hiện tại của chủ đề này đã có trong chương trình: %s",
 				  "deleting":	"Không thấy chủ đề trong danh sách diễn đàn; chu kỳ xác nhận %s",
 				  "topic-status": "Trạng thái chủ đề %s: đã đóng, chưa được duyệt hoặc trùng lặp",
 				  "fuse":	"Máy theo dõi %s có vẻ không truy cập được; đã hoãn kiểm tra"
 				  };

thePlugins.get("rutracker_check").langLoaded();
