/**
 * 01-Schema.gs — Định nghĩa schema Google Sheets và hàm khởi tạo
 *
 * SCHEMA là NGUỒN CHÂN LÝ DUY NHẤT về cấu trúc dữ liệu.
 * Muốn thêm cột: sửa ở đây rồi chạy lại initializeSpreadsheet().
 *
 * initializeSpreadsheet() an toàn khi chạy nhiều lần:
 *   - Sheet chưa có  -> tạo mới kèm header
 *   - Sheet đã có    -> chỉ THÊM cột còn thiếu vào cuối, KHÔNG xoá, KHÔNG đổi chỗ
 *   - Không bao giờ đụng tới dữ liệu đã nhập
 */

const SHEETS = {
  USERS: '01_USERS',
  COURSES: '02_COURSES',
  CLASSES: '03_CLASSES',
  STUDENTS: '04_STUDENTS',
  ENROLLMENTS: '05_ENROLLMENTS',
  SESSIONS: '06_SESSIONS',
  ATTENDANCE: '07_ATTENDANCE',
  ATTENDANCE_KEYS: '08_ATTENDANCE_KEYS',
  GRADE_COLUMNS: '09_GRADE_COLUMNS',
  GRADES: '10_GRADES',
  COMPLAINTS: '11_COMPLAINTS',
  AUDIT_LOG: '12_AUDIT_LOG'
};

/**
 * Mỗi cột: { n: tên cột, t: kiểu, w: độ rộng px, list: [giá trị hợp lệ] }
 * Kiểu 't': 'text' | 'number' | 'date' | 'time' | 'datetime'
 * 'text' sẽ được ép định dạng văn bản thuần để Sheets không tự đổi
 * "0A1B" thành số hay "01/02" thành ngày.
 */
const SCHEMA = {

  [SHEETS.USERS]: [
    { n: 'UserID',    t: 'text', w: 170 },
    { n: 'Username',  t: 'text', w: 140 },
    { n: 'FullName',  t: 'text', w: 200 },
    { n: 'Email',        t: 'text', w: 220 },
    { n: 'PasswordHash',  t: 'text', w: 200 },
    { n: 'Salt',          t: 'text', w: 160 },
    { n: 'Role',      t: 'text', w: 110, list: [ROLE.ADMIN, ROLE.LECTURER, ROLE.STUDENT] },
    { n: 'Status',    t: 'text', w: 100, list: [RECORD_STATUS.ACTIVE, RECORD_STATUS.INACTIVE] },
    { n: 'CreatedAt', t: 'text', w: 160 }
  ],

  [SHEETS.COURSES]: [
    { n: 'CourseID',      t: 'text',   w: 170 },
    { n: 'CourseCode',    t: 'text',   w: 120 },
    { n: 'CourseName',    t: 'text',   w: 260 },
    { n: 'Credits',       t: 'number', w: 80 },
    { n: 'TheoryHours',   t: 'number', w: 100 },
    { n: 'PracticeHours', t: 'number', w: 110 },
    { n: 'Status',        t: 'text',   w: 100, list: [RECORD_STATUS.ACTIVE, RECORD_STATUS.INACTIVE] },
    { n: 'CreatedAt',     t: 'text',   w: 160 }
  ],

  // Toạ độ phòng học nằm ở CLASSES: mỗi lớp học ở một phòng khác nhau,
  // nên bán kính kiểm tra GPS phải theo lớp, không theo hệ thống.
  [SHEETS.CLASSES]: [
    { n: 'ClassID',        t: 'text',   w: 170 },
    { n: 'CourseID',       t: 'text',   w: 170 },
    { n: 'LecturerID',     t: 'text',   w: 170 },
    { n: 'ClassCode',      t: 'text',   w: 150 },
    { n: 'Semester',       t: 'text',   w: 90 },
    { n: 'AcademicYear',   t: 'text',   w: 120 },
    { n: 'RoomLat',        t: 'number', w: 110 },
    { n: 'RoomLng',        t: 'number', w: 110 },
    { n: 'AllowedRadiusM', t: 'number', w: 120 },
    { n: 'Status',         t: 'text',   w: 100, list: [RECORD_STATUS.ACTIVE, RECORD_STATUS.INACTIVE] },
    { n: 'CreatedAt',      t: 'text',   w: 160 }
  ],

  [SHEETS.STUDENTS]: [
    { n: 'StudentID', t: 'text', w: 170 },
    { n: 'MSSV',      t: 'text', w: 120 },
    { n: 'FullName',  t: 'text', w: 220 },
    { n: 'Email',     t: 'text', w: 240 },
    { n: 'Status',    t: 'text', w: 100, list: [RECORD_STATUS.ACTIVE, RECORD_STATUS.INACTIVE] },
    { n: 'CreatedAt', t: 'text', w: 160 }
  ],

  // Bảng nối nhiều-nhiều giữa Student và Class (thiết kế C.1).
  // Lớp bù số 2 ở D.8 kiểm tra ghi danh dựa trên bảng này.
  [SHEETS.ENROLLMENTS]: [
    { n: 'EnrollmentID', t: 'text', w: 170 },
    { n: 'StudentID',    t: 'text', w: 170 },
    { n: 'ClassID',      t: 'text', w: 170 },
    { n: 'Status',       t: 'text', w: 100, list: [RECORD_STATUS.ACTIVE, RECORD_STATUS.INACTIVE] },
    { n: 'CreatedAt',    t: 'text', w: 160 }
  ],

  [SHEETS.SESSIONS]: [
    { n: 'SessionID', t: 'text',   w: 170 },
    { n: 'ClassID',   t: 'text',   w: 170 },
    { n: 'SessionNo', t: 'number', w: 90 },
    { n: 'Date',      t: 'date',   w: 110 },
    { n: 'DayOfWeek', t: 'text',   w: 100 },
    { n: 'StartTime', t: 'text',   w: 90 },
    { n: 'EndTime',   t: 'text',   w: 90 },
    { n: 'Content',   t: 'text',   w: 340 },
    { n: 'Status',    t: 'text',   w: 100, list: [RECORD_STATUS.ACTIVE, RECORD_STATUS.INACTIVE] },
    { n: 'CreatedAt', t: 'text',   w: 160 }
  ],

  // Khoá nghiệp vụ: StudentID + SessionID (thiết kế C.1, D.8 lớp 3)
  [SHEETS.ATTENDANCE]: [
    { n: 'AttendanceID', t: 'text',   w: 170 },
    { n: 'StudentID',    t: 'text',   w: 170 },
    { n: 'SessionID',    t: 'text',   w: 170 },
    { n: 'Status',       t: 'text',   w: 100, list: [
        ATTENDANCE_STATUS.PRESENT, ATTENDANCE_STATUS.LATE,
        ATTENDANCE_STATUS.ABSENT,  ATTENDANCE_STATUS.EXCUSED ] },
    { n: 'CheckInTime',  t: 'text',   w: 160 },
    { n: 'GpsLat',       t: 'number', w: 110 },
    { n: 'GpsLng',       t: 'number', w: 110 },
    { n: 'GpsAccuracy',  t: 'number', w: 110 },
    { n: 'DistanceM',    t: 'number', w: 100 },
    { n: 'GpsFlag',      t: 'text',   w: 130, list: [
        GPS_FLAG.VALID, GPS_FLAG.OUT_OF_RANGE, GPS_FLAG.LOW_ACCURACY, GPS_FLAG.NO_GPS ] },
    { n: 'IP',           t: 'text',   w: 130 },
    { n: 'OS',           t: 'text',   w: 110 },
    { n: 'Browser',      t: 'text',   w: 110 },
    { n: 'DeviceType',   t: 'text',   w: 100 },
    { n: 'DeviceHash',   t: 'text',   w: 150 },
    { n: 'Note',         t: 'text',   w: 240 },
    { n: 'CreatedAt',    t: 'text',   w: 160 }
  ],

  // LateAfter là mốc chuyển PRESENT -> LATE; EndTime là lúc đóng hẳn (thiết kế D.2)
  [SHEETS.ATTENDANCE_KEYS]: [
    { n: 'KeyID',     t: 'text', w: 170 },
    { n: 'SessionID', t: 'text', w: 170 },
    { n: 'Code',      t: 'text', w: 90 },
    { n: 'StartTime', t: 'text', w: 160 },
    { n: 'LateAfter', t: 'text', w: 160 },
    { n: 'EndTime',   t: 'text', w: 160 },
    { n: 'Status',    t: 'text', w: 100, list: [KEY_STATUS.OPEN, KEY_STATUS.CLOSED] },
    { n: 'CreatedBy', t: 'text', w: 170 },
    { n: 'CreatedAt', t: 'text', w: 160 }
  ],

  // Cột điểm động (thiết kế C.4) — KHÔNG viết cứng CC/Quiz/GK/CK trong code
  [SHEETS.GRADE_COLUMNS]: [
    { n: 'GradeColumnID', t: 'text',   w: 170 },
    { n: 'ClassID',       t: 'text',   w: 170 },
    { n: 'Name',          t: 'text',   w: 200 },
    { n: 'Weight',        t: 'number', w: 90 },
    { n: 'SortOrder',     t: 'number', w: 90 },
    { n: 'Status',        t: 'text',   w: 100, list: [RECORD_STATUS.ACTIVE, RECORD_STATUS.INACTIVE] },
    { n: 'CreatedAt',     t: 'text',   w: 160 }
  ],

  [SHEETS.GRADES]: [
    { n: 'GradeID',       t: 'text',   w: 170 },
    { n: 'StudentID',     t: 'text',   w: 170 },
    { n: 'ClassID',       t: 'text',   w: 170 },
    { n: 'GradeColumnID', t: 'text',   w: 170 },
    { n: 'Score',         t: 'number', w: 90 },
    { n: 'UpdatedBy',     t: 'text',   w: 170 },
    { n: 'UpdatedAt',     t: 'text',   w: 160 }
  ],

  [SHEETS.COMPLAINTS]: [
    { n: 'ComplaintID', t: 'text', w: 170 },
    { n: 'StudentID',   t: 'text', w: 170 },
    { n: 'ClassID',     t: 'text', w: 170 },
    { n: 'Type',        t: 'text', w: 120, list: [
        COMPLAINT_TYPE.ATTENDANCE, COMPLAINT_TYPE.GRADE, COMPLAINT_TYPE.OTHER ] },
    { n: 'TargetID',    t: 'text', w: 170 },
    { n: 'Content',     t: 'text', w: 360 },
    { n: 'CreatedAt',   t: 'text', w: 160 },
    { n: 'Status',      t: 'text', w: 120, list: [
        COMPLAINT_STATUS.PENDING, COMPLAINT_STATUS.PROCESSING,
        COMPLAINT_STATUS.RESOLVED, COMPLAINT_STATUS.REJECTED ] },
    { n: 'Response',    t: 'text', w: 360 },
    { n: 'ResolvedBy',  t: 'text', w: 170 },
    { n: 'ResolvedAt',  t: 'text', w: 160 }
  ],

  // Bằng chứng khi xảy ra tranh chấp (thiết kế C.2, D.8 lớp 6) — KHÔNG tuỳ chọn
  [SHEETS.AUDIT_LOG]: [
    { n: 'LogID',      t: 'text', w: 170 },
    { n: 'Time',       t: 'text', w: 160 },
    { n: 'Actor',      t: 'text', w: 170 },
    { n: 'ActorRole',  t: 'text', w: 110 },
    { n: 'Action',     t: 'text', w: 180 },
    { n: 'TargetType', t: 'text', w: 130 },
    { n: 'TargetID',   t: 'text', w: 170 },
    { n: 'Data',       t: 'text', w: 400 },
    { n: 'IP',         t: 'text', w: 130 }
  ]
};

/* ------------------------------------------------------------------ */
/*  KHỞI TẠO                                                           */
/* ------------------------------------------------------------------ */

/**
 * Tạo / cập nhật toàn bộ 12 sheet theo SCHEMA.
 * Chạy được nhiều lần, không phá dữ liệu cũ.
 * CHẠY HÀM NÀY TRƯỚC TIÊN sau khi dán code vào Apps Script.
 */
function initializeSpreadsheet() {
  const ss = getSpreadsheet();
  const report = { created: [], updated: [], unchanged: [] };

  Object.keys(SCHEMA).forEach(function (sheetName) {
    const cols = SCHEMA[sheetName];
    let sheet = ss.getSheetByName(sheetName);

    if (!sheet) {
      sheet = ss.insertSheet(sheetName);
      writeHeaderRow_(sheet, cols);
      applyColumnFormats_(sheet, cols, 0);
      report.created.push(sheetName);
      return;
    }

    const existing = readHeaderRow_(sheet);

    if (existing.length === 0) {
      writeHeaderRow_(sheet, cols);
      applyColumnFormats_(sheet, cols, 0);
      report.created.push(sheetName + ' (header)');
      return;
    }

    // Chỉ thêm cột thiếu vào cuối — không xoá, không đổi chỗ cột đang có
    const missing = cols.filter(function (c) { return existing.indexOf(c.n) === -1; });

    if (missing.length === 0) {
      applyColumnFormats_(sheet, cols, 0);
      report.unchanged.push(sheetName);
      return;
    }

    const startCol = existing.length + 1;
    sheet.getRange(1, startCol, 1, missing.length)
         .setValues([missing.map(function (c) { return c.n; })]);
    applyColumnFormats_(sheet, missing, startCol - 1);
    styleHeaderRow_(sheet);
    report.updated.push(sheetName + ' (+' + missing.map(function (c) { return c.n; }).join(', ') + ')');
  });

  const msg = formatReport_(report);
  Logger.log(msg);
  return msg;
}

/**
 * Kiểm tra schema mà KHÔNG ghi gì. Dùng để soát trước khi chạy init,
 * hoặc để phát hiện ai đó đã sửa tay cấu trúc sheet.
 */
function verifySchema() {
  const ss = getSpreadsheet();
  const problems = [];

  Object.keys(SCHEMA).forEach(function (sheetName) {
    const sheet = ss.getSheetByName(sheetName);
    if (!sheet) { problems.push('THIẾU SHEET: ' + sheetName); return; }

    const existing = readHeaderRow_(sheet);
    const expected = SCHEMA[sheetName].map(function (c) { return c.n; });

    expected.forEach(function (name) {
      if (existing.indexOf(name) === -1) {
        problems.push(sheetName + ': thiếu cột "' + name + '"');
      }
    });

    const extra = existing.filter(function (n) { return n && expected.indexOf(n) === -1; });
    if (extra.length) {
      problems.push(sheetName + ': có cột lạ ngoài schema -> ' + extra.join(', '));
    }
  });

  const msg = problems.length
    ? 'CÓ VẤN ĐỀ:\n- ' + problems.join('\n- ')
    : 'OK — cả ' + Object.keys(SCHEMA).length + ' sheet khớp schema.';
  Logger.log(msg);
  return msg;
}

/* ------------------------------------------------------------------ */
/*  HÀM PHỤ (hậu tố _ = private, không hiện trong menu Run)            */
/* ------------------------------------------------------------------ */

function readHeaderRow_(sheet) {
  if (sheet.getLastColumn() === 0) return [];
  return sheet.getRange(1, 1, 1, sheet.getLastColumn())
              .getValues()[0]
              .map(function (v) { return String(v).trim(); })
              .filter(function (v) { return v !== ''; });
}

function writeHeaderRow_(sheet, cols) {
  sheet.getRange(1, 1, 1, cols.length)
       .setValues([cols.map(function (c) { return c.n; })]);
  styleHeaderRow_(sheet);
}

function styleHeaderRow_(sheet) {
  const lastCol = Math.max(sheet.getLastColumn(), 1);
  sheet.getRange(1, 1, 1, lastCol)
       .setFontWeight('bold')
       .setBackground('#E8EAED')
       .setVerticalAlignment('middle');
  sheet.setFrozenRows(1);
}

/**
 * Định dạng cột + data validation cho cột enum.
 * offset = số cột đã có trước nhóm cols này.
 *
 * Cột kiểu 'text' bị ép định dạng văn bản thuần. Đây không phải chi tiết
 * làm đẹp: nếu không ép, Sheets sẽ biến mã "3479" thành số và mất ý nghĩa,
 * hoặc biến MSSV bắt đầu bằng 0 thành số ngắn hơn.
 */
function applyColumnFormats_(sheet, cols, offset) {
  const maxRows = Math.max(sheet.getMaxRows() - 1, 1);

  cols.forEach(function (c, i) {
    const colIndex = offset + i + 1;
    if (c.w) sheet.setColumnWidth(colIndex, c.w);

    const body = sheet.getRange(2, colIndex, maxRows, 1);

    if (c.t === 'text')        body.setNumberFormat('@');
    else if (c.t === 'number') body.setNumberFormat('0.##');
    else if (c.t === 'date')   body.setNumberFormat('dd/MM/yyyy');

    if (c.list && c.list.length) {
      const rule = SpreadsheetApp.newDataValidation()
        .requireValueInList(c.list, true)
        .setAllowInvalid(false)
        .setHelpText('Chỉ nhận: ' + c.list.join(' | '))
        .build();
      body.setDataValidation(rule);
    }
  });
}

function formatReport_(report) {
  const lines = ['KHỞI TẠO SCHEMA HOÀN TẤT'];
  lines.push('Tạo mới     : ' + (report.created.length ? report.created.join(', ') : '(không có)'));
  lines.push('Thêm cột    : ' + (report.updated.length ? report.updated.join(' | ') : '(không có)'));
  lines.push('Giữ nguyên  : ' + (report.unchanged.length ? report.unchanged.join(', ') : '(không có)'));
  return lines.join('\n');
}
