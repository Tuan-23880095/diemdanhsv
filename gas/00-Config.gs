/**
 * 00-Config.gs — Cấu hình và hằng số toàn hệ thống
 * Dự án: Web điểm danh sinh viên có kiểm chứng
 *
 * KHÔNG viết cứng chỉ số cột ở bất kỳ đâu trong dự án.
 * Mọi truy cập dữ liệu đi qua SheetRepo (02-Repo.gs), dùng TÊN CỘT.
 */

const CONFIG = {
  SPREADSHEET_ID: '1ZmgdFwiHNGxCW3OFIOtoPYOZ5EoIjkS1qrL4n0luWFU',

  // Múi giờ dùng cho mọi phép định dạng ngày giờ
  TIMEZONE: 'Asia/Ho_Chi_Minh',

  // Mã điểm danh — CHỐT 4 ký tự (thiết kế D.1)
  CODE_LENGTH: 4,
  // Bỏ các ký tự dễ đọc nhầm: 0/O, 1/I/L, 2/Z, 5/S, 8/B
  CODE_ALPHABET: 'ACDEFGHJKMNPQRTUVWXY34679',

  // Cửa sổ thời gian mặc định, tính từ lúc giảng viên mở điểm danh (thiết kế D.2)
  DEFAULT_PRESENT_MINUTES: 5,   // 0–5 phút  -> PRESENT
  DEFAULT_WINDOW_MINUTES: 15,   // 5–15 phút -> LATE; sau đó đóng

  // GPS (thiết kế D.3)
  DEFAULT_RADIUS_M: 100,        // bán kính cho phép quanh phòng học
  GPS_ACCURACY_LIMIT_M: 150,    // accuracy lớn hơn mức này thì đánh dấu LOW_ACCURACY

  // Bảo vệ ghi đồng thời (thiết kế D.6)
  LOCK_TIMEOUT_MS: 30000,

  // Cache (thiết kế F)
  CACHE_TTL_SECONDS: 300
};

/** Trạng thái điểm danh (thiết kế C.3) */
const ATTENDANCE_STATUS = {
  PRESENT: 'PRESENT',
  LATE: 'LATE',
  ABSENT: 'ABSENT',
  EXCUSED: 'EXCUSED'
};

/** Vai trò người dùng — có ADMIN ngay từ đầu (thiết kế C.3) */
const ROLE = {
  ADMIN: 'ADMIN',
  LECTURER: 'LECTURER',
  STUDENT: 'STUDENT'
};

const RECORD_STATUS = { ACTIVE: 'ACTIVE', INACTIVE: 'INACTIVE' };

const KEY_STATUS = { OPEN: 'OPEN', CLOSED: 'CLOSED' };

const COMPLAINT_TYPE = { ATTENDANCE: 'ATTENDANCE', GRADE: 'GRADE', OTHER: 'OTHER' };

const COMPLAINT_STATUS = {
  PENDING: 'PENDING',
  PROCESSING: 'PROCESSING',
  RESOLVED: 'RESOLVED',
  REJECTED: 'REJECTED'
};

const GPS_FLAG = {
  VALID: 'VALID',
  OUT_OF_RANGE: 'OUT_OF_RANGE',
  LOW_ACCURACY: 'LOW_ACCURACY',
  NO_GPS: 'NO_GPS'
};

/** Truy cập spreadsheet — luôn qua hàm này, không gọi openById rải rác */
function getSpreadsheet() {
  return SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
}

/** Sinh ID duy nhất có tiền tố. KHÔNG dùng số dòng làm ID (thiết kế C.1) */
function newId(prefix) {
  return prefix + '_' + Utilities.getUuid().replace(/-/g, '').substring(0, 12).toUpperCase();
}

/** Dấu thời gian ISO theo múi giờ hệ thống */
function nowStamp() {
  return Utilities.formatDate(new Date(), CONFIG.TIMEZONE, "yyyy-MM-dd'T'HH:mm:ss");
}
