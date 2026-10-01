/**
 * 13-ExportJSON.gs — Xuất toàn bộ 12 sheet ra MỘT file JSON trong thư mục
 * Drive RIÊNG TƯ, để tools/import.php (GĐ6, PLAN diemdanhsv) nạp vào MariaDB.
 *
 * CHỈ CHẠY TAY từ trình soạn thảo Apps Script (Chạy -> exportAllToDriveJSON),
 * không gắn vào bất kỳ trigger/web app nào — đây là thao tác di dời dữ liệu
 * một lần (hoặc vài lần đối chiếu), không phải tính năng chạy thường trực.
 *
 * Vì sao MỘT file thay vì 12 file riêng: tools/import.php cần đối soát số
 * dòng theo một lần chụp dữ liệu nhất quán (cùng một thời điểm xuất); 12 file
 * rời rạc có thể lệch nhau vài giây nếu có người đang thao tác trên sheet.
 *
 * Tên cột trong mỗi sheet GIỮ NGUYÊN khớp 1-1 với tên cột MySQL (xem chú
 * thích đầu db/schema.sql), nên export không cần ánh xạ lại tên trường —
 * chỉ đổi tên SHEET (02_... — tiếng Việt viết hoa) thành tên bảng MySQL
 * (lowercase, đúng thứ tự phụ thuộc khoá ngoại để import.php chèn đúng thứ tự).
 */

/** Tên bảng MySQL đích, ĐÚNG THỨ TỰ PHỤ THUỘC KHOÁ NGOẠI (tools/import.php dùng lại thứ tự này). */
const EXPORT_TABLE_ORDER = [
  { table: 'users',          sheet: SHEETS.USERS },
  { table: 'courses',        sheet: SHEETS.COURSES },
  { table: 'classes',        sheet: SHEETS.CLASSES },
  { table: 'students',       sheet: SHEETS.STUDENTS },
  { table: 'enrollments',    sheet: SHEETS.ENROLLMENTS },
  { table: 'sessions',       sheet: SHEETS.SESSIONS },
  { table: 'attendance_keys',sheet: SHEETS.ATTENDANCE_KEYS },
  { table: 'grade_columns',  sheet: SHEETS.GRADE_COLUMNS },
  { table: 'attendance',     sheet: SHEETS.ATTENDANCE },
  { table: 'grades',         sheet: SHEETS.GRADES },
  { table: 'complaints',     sheet: SHEETS.COMPLAINTS },
  { table: 'audit_log',      sheet: SHEETS.AUDIT_LOG }
];
// Ghi chú: auth_tokens / grade_codes (2 bảng mới của bản PHP, GĐ3) KHÔNG
// xuất — không có sheet tương ứng, và token/mã xác minh cũ hết hạn theo
// CacheService nên không còn ý nghĩa để di dời.

/**
 * Hàm chính — chạy tay. Trả về (và Logger.log) đường dẫn file đã tạo.
 */
function exportAllToDriveJSON() {
  if (!CONFIG.EXPORT_FOLDER_ID) {
    throw new Error(
      'CONFIG.EXPORT_FOLDER_ID đang trống. Tạo một thư mục Drive RIÊNG TƯ ' +
      '(không chia sẻ công khai) rồi dán ID vào gas/00-Config.gs trước khi chạy hàm này.'
    );
  }

  const folder = DriveApp.getFolderById(CONFIG.EXPORT_FOLDER_ID);
  const exportedAt = nowStamp();
  const counts = {};
  const data = {};

  EXPORT_TABLE_ORDER.forEach(function (entry) {
    const rows = Repos[repoKeyFor_(entry.table)]().all().map(stripRowMeta_);
    data[entry.table] = rows;
    counts[entry.table] = rows.length;
  });

  const payload = {
    version: 'gas-export-1',
    exportedAt: exportedAt,
    spreadsheetId: CONFIG.SPREADSHEET_ID,
    counts: counts,
    data: data
  };

  const fileName = 'diemdanhsv-export-' +
    Utilities.formatDate(new Date(), CONFIG.TIMEZONE, 'yyyyMMdd-HHmmss') + '.json';

  const file = folder.createFile(
    fileName,
    JSON.stringify(payload, null, 0),
    MimeType.PLAIN_TEXT
  );

  const report = 'Đã xuất ' + fileName + ' vào thư mục Drive (ID ' + CONFIG.EXPORT_FOLDER_ID + ').\n' +
    'Số dòng mỗi bảng:\n- ' +
    EXPORT_TABLE_ORDER.map(function (e) { return e.table + ': ' + counts[e.table]; }).join('\n- ') + '\n\n' +
    'Bước tiếp theo (THẦY làm, không tự động):\n' +
    '1. Tải file này về (hoặc dùng link Drive) rồi copy lên host qua SFTP/SSH\n' +
    '   vào thư mục NGOÀI public_html, ví dụ ../private/import/' + fileName + '\n' +
    '2. SSH vào host, chạy thử: php tools/import.php --file=../private/import/' + fileName + ' --dry-run\n' +
    '3. Đọc kỹ báo cáo đối soát số dòng; chỉ chạy KHÔNG --dry-run khi báo cáo khớp mong đợi.\n' +
    '4. Sau khi import xong, XOÁ file JSON khỏi host và khỏi Drive (có chứa dữ liệu thật) —\n' +
    '   hoặc chuyển vào nơi lưu trữ riêng tư có kiểm soát truy cập, không để trong repo/Drive dùng chung.';

  Logger.log(report);
  return report;
}

/** 'attendance_keys' -> 'keys' (tên key trong Repos khác tên bảng, xem gas/02-Repo.gs) */
function repoKeyFor_(table) {
  const MAP = {
    users: 'users', courses: 'courses', classes: 'classes', students: 'students',
    enrollments: 'enrollments', sessions: 'sessions', attendance: 'attendance',
    attendance_keys: 'keys', grade_columns: 'gradeCols', grades: 'grades',
    complaints: 'complaints', audit_log: 'audit'
  };
  if (!MAP[table]) throw new Error('Không có Repos cho bảng "' + table + '".');
  return MAP[table];
}

/** SheetRepo.all() gắn thêm _row (số dòng thật) — bỏ trước khi xuất, không phải cột CSDL. */
function stripRowMeta_(row) {
  const copy = {};
  Object.keys(row).forEach(function (k) {
    if (k !== '_row') copy[k] = row[k];
  });
  return copy;
}

/**
 * Xuất thử MỘT bảng (để kiểm tra nhanh mà không đụng Drive) — chạy tay,
 * xem kết quả qua Logger, không ghi file.
 */
function previewExportTable(tableName) {
  const entry = EXPORT_TABLE_ORDER.filter(function (e) { return e.table === tableName; })[0];
  if (!entry) throw new Error('Không có bảng "' + tableName + '" trong EXPORT_TABLE_ORDER.');
  const rows = Repos[repoKeyFor_(entry.table)]().all().map(stripRowMeta_);
  const msg = tableName + ': ' + rows.length + ' dòng. Dòng đầu tiên:\n' +
    (rows.length ? JSON.stringify(rows[0], null, 2) : '(trống)');
  Logger.log(msg);
  return msg;
}
