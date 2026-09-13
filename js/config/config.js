/* js/config/config.js — Cấu hình frontend */

const CONFIG = {
  // URL của bản triển khai "diemdanh-03" (Triển khai → Quản lý các tùy chọn
  // triển khai). Khi sửa code backend: bấm BÚT CHÌ trên đúng bản này →
  // Phiên bản: "Phiên bản mới" → Triển khai. URL giữ nguyên, không phải sửa
  // lại dòng dưới. TUYỆT ĐỐI không bấm "Tùy chọn triển khai mới" — cái đó đẻ
  // ra URL khác, còn dòng này vẫn trỏ bản cũ đóng băng ở code cũ (đã mất cả
  // buổi vì lỗi này ngày 13/09/2026).
  // Kiểm chứng sau mỗi lần deploy: mở <API_URL>?action=ping, đối chiếu version.
  API_URL: 'https://script.google.com/macros/s/AKfycbw-8TxqTk8rzBFwZRKEr6B-jNmf0z5nA2nI_PDSJyIAQXKj1sdJli5Q28ZC4gRfAQoZBA/exec',

  SCHOOL_NAME: 'Trường Đại học Khoa học Tự nhiên, ĐHQG-HCM',
  SITE_NAME: 'Hệ thống điểm danh sinh viên',

  CODE_LENGTH: 4,
  ROSTER_POLL_MS: 10000,     // nhịp làm mới danh sách thời gian thực (D.8-5)
  GPS_TIMEOUT_MS: 10000
};

const STATUS_TEXT = {
  PRESENT: 'Có mặt',
  LATE: 'Trễ',
  ABSENT: 'Vắng',
  EXCUSED: 'Vắng có phép'
};

const GPS_TEXT = {
  VALID: 'Trong khu vực lớp',
  OUT_OF_RANGE: 'Ngoài bán kính cho phép',
  LOW_ACCURACY: 'GPS sai số lớn',
  NO_GPS: 'Không có GPS'
};
