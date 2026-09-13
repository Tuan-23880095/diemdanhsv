/**
 * 07-Import.gs — Nhập danh sách sinh viên vào 04_STUDENTS và 05_ENROLLMENTS
 *
 * CÁCH DÙNG
 *   1. Tạo một sheet tên "IMPORT" trong chính file Google Sheet này.
 *   2. Dán nguyên danh sách lớp vào đó, GIỮ NGUYÊN dòng tiêu đề gốc.
 *      Không cần đổi tên cột, không cần sắp lại thứ tự.
 *   3. Chạy previewImport()  -> xem hàm hiểu file thế nào, KHÔNG ghi gì
 *   4. Xem log, thấy đúng thì chạy runImport() -> mới thật sự ghi
 *
 * previewImport() tồn tại vì lý do rất cụ thể: nhập sai 05_ENROLLMENTS thì
 * sinh viên bị từ chối ngay giữa lớp, lúc đó sửa không kịp.
 *
 * Hỗ trợ nhiều lớp trong cùng một file: chỉ cần có cột mã lớp.
 * Không có cột đó thì tất cả vào lớp ghi ở IMPORT_CONFIG.defaultClassCode.
 *
 * Chạy lại nhiều lần an toàn: MSSV đã có thì cập nhật họ tên/email,
 * không tạo trùng. Ghi danh đã có thì bỏ qua.
 */

const IMPORT_CONFIG = {
  sheetName: 'IMPORT',

  // Dùng khi file không có cột mã lớp. Để '' nếu file đã có cột đó.
  defaultClassCode: '',

  // true  = gặp mã lớp lạ thì tạo lớp mới (lớp mới CHƯA có toạ độ phòng)
  // false = báo lỗi và bỏ qua dòng đó
  createMissingClass: false
};

/** Tên cột có thể gặp. So khớp sau khi bỏ dấu, bỏ khoảng trắng, về chữ thường. */
const COLUMN_ALIASES = {
  mssv: [
    'mssv', 'masosinhvien', 'masv', 'mshs', 'sohieusinhvien',
    'studentid', 'studentcode', 'id', 'ma'
  ],
  // Cột họ tên gộp sẵn — ưu tiên dùng nếu có
  fullName: [
    'hoten', 'hovaten', 'hovatenlot', 'tensinhvien', 'hotensinhvien',
    'fullname', 'name', 'hovatendem'
  ],
  // Danh sách của trường rất hay tách làm hai cột. Có cả hai thì nối lại.
  lastName:  ['hodem', 'holot', 'ho', 'hovadem', 'lastname', 'surname'],
  firstName: ['ten', 'tensv', 'firstname', 'givenname'],
  email: ['email', 'mail', 'diachiemail', 'emailsinhvien'],
  classCode: [
    'lop', 'malop', 'malophocphan', 'mahocphan', 'lophocphan',
    'class', 'classcode', 'malhp', 'lhp'
  ]
};

/* ------------------------------------------------------------------ */
/*  CHẠY THỬ — không ghi gì                                            */
/* ------------------------------------------------------------------ */

function previewImport() {
  const r = analyzeImport_();
  if (r.error) { Logger.log(r.error); return r.error; }

  const out = [];
  out.push('=== XEM TRƯỚC — CHƯA GHI GÌ VÀO HỆ THỐNG ===');
  out.push('');
  out.push('Nhận diện cột:');
  Object.keys(r.mapping).forEach(function (k) {
    out.push('  ' + pad_(k, 10) + ' -> cột "' + r.mapping[k] + '"');
  });
  if (r.splitName) out.push('  (ghép hai cột họ đệm + tên thành họ tên đầy đủ)');
  if (!r.mapping.mssv) out.push('  THIẾU CỘT BẮT BUỘC: mssv');
  if (!r.mapping.fullName && !r.splitName) out.push('  THIẾU CỘT BẮT BUỘC: hoten');
  if (!r.mapping.email) out.push('  (không có cột email — bỏ trống, không sao)');
  if (!r.mapping.classCode) {
    out.push('  (không có cột mã lớp — dùng defaultClassCode: "' +
             IMPORT_CONFIG.defaultClassCode + '")');
  }

  out.push('');
  out.push('Tổng số dòng dữ liệu : ' + r.totalRows);
  out.push('Dòng hợp lệ          : ' + r.valid.length);
  out.push('Dòng bị bỏ qua       : ' + r.skipped.length);
  out.push('');

  out.push('Phân bổ theo lớp:');
  Object.keys(r.byClass).forEach(function (code) {
    const exists = r.classExists[code];
    out.push('  ' + pad_(code, 18) + r.byClass[code] + ' sinh viên' +
             (exists ? '' : '   <-- LỚP NÀY CHƯA CÓ TRONG 03_CLASSES'));
  });

  out.push('');
  out.push('Năm dòng đầu đọc được:');
  r.valid.slice(0, 5).forEach(function (v) {
    out.push('  ' + pad_(v.mssv, 12) + pad_(v.fullName, 28) +
             pad_(v.email || '-', 30) + v.classCode);
  });

  if (r.skipped.length) {
    out.push('');
    out.push('Dòng bị bỏ qua (tối đa 10):');
    r.skipped.slice(0, 10).forEach(function (s) {
      out.push('  dòng ' + s.row + ': ' + s.reason);
    });
  }

  if (r.duplicates.length) {
    out.push('');
    out.push('MSSV trùng nhau trong file (giữ dòng đầu): ' + r.duplicates.join(', '));
  }

  out.push('');
  out.push(r.blocking.length
    ? 'CHƯA CHẠY ĐƯỢC:\n- ' + r.blocking.join('\n- ')
    : 'Sẵn sàng. Chạy runImport() để ghi thật.');

  const msg = out.join('\n');
  Logger.log(msg);
  return msg;
}

/* ------------------------------------------------------------------ */
/*  GHI THẬT                                                           */
/* ------------------------------------------------------------------ */

function runImport() {
  const r = analyzeImport_();
  if (r.error) { Logger.log(r.error); return r.error; }
  if (r.blocking.length) {
    const m = 'DỪNG — còn vướng:\n- ' + r.blocking.join('\n- ') +
              '\nChạy previewImport() để xem chi tiết.';
    Logger.log(m); return m;
  }

  const students = Repos.students();
  const enrolls  = Repos.enrollments();
  const classes  = Repos.classes();

  // Nạp sẵn vào bộ nhớ, tránh đọc lại sheet cho từng dòng
  const studentByMssv = {};
  students.all().forEach(function (s) { studentByMssv[String(s.MSSV).trim()] = s; });

  const classByCode = {};
  classes.all().forEach(function (c) { classByCode[String(c.ClassCode).trim()] = c; });

  const enrolledKey = {};
  enrolls.all().forEach(function (e) {
    enrolledKey[String(e.StudentID).trim() + '|' + String(e.ClassID).trim()] = true;
  });

  const stat = { created: 0, updated: 0, enrolled: 0, alreadyEnrolled: 0, classCreated: 0 };

  r.valid.forEach(function (v) {

    let cls = classByCode[v.classCode];
    if (!cls && IMPORT_CONFIG.createMissingClass) {
      cls = {
        ClassID: newId('CLS'), CourseID: '', LecturerID: '',
        ClassCode: v.classCode, Semester: '', AcademicYear: '',
        RoomLat: '', RoomLng: '', AllowedRadiusM: CONFIG.DEFAULT_RADIUS_M,
        Status: RECORD_STATUS.ACTIVE, CreatedAt: nowStamp()
      };
      classes.insert(cls);
      classByCode[v.classCode] = cls;
      stat.classCreated++;
    }
    if (!cls) return;

    let st = studentByMssv[v.mssv];
    if (!st) {
      st = {
        StudentID: newId('STD'), MSSV: v.mssv, FullName: v.fullName,
        Email: v.email, Status: RECORD_STATUS.ACTIVE, CreatedAt: nowStamp()
      };
      students.insert(st);
      studentByMssv[v.mssv] = st;
      stat.created++;
    } else {
      // Chỉ cập nhật khi có dữ liệu mới và khác dữ liệu cũ
      const patch = {};
      if (v.fullName && v.fullName !== String(st.FullName).trim()) patch.FullName = v.fullName;
      if (v.email    && v.email    !== String(st.Email).trim())    patch.Email = v.email;
      if (Object.keys(patch).length) { students.updateRow(st._row, patch); stat.updated++; }
    }

    const key = String(st.StudentID).trim() + '|' + String(cls.ClassID).trim();
    if (enrolledKey[key]) { stat.alreadyEnrolled++; return; }

    enrolls.insert({
      EnrollmentID: newId('ENR'), StudentID: st.StudentID, ClassID: cls.ClassID,
      Status: RECORD_STATUS.ACTIVE, CreatedAt: nowStamp()
    });
    enrolledKey[key] = true;
    stat.enrolled++;
  });

  logAudit('system', ROLE.ADMIN, 'IMPORT_STUDENTS', 'SHEET', IMPORT_CONFIG.sheetName, stat, '');

  const msg = [
    '=== ĐÃ NHẬP XONG ===',
    'Sinh viên tạo mới    : ' + stat.created,
    'Sinh viên cập nhật   : ' + stat.updated,
    'Ghi danh tạo mới     : ' + stat.enrolled,
    'Ghi danh đã có sẵn   : ' + stat.alreadyEnrolled,
    'Lớp tạo mới          : ' + stat.classCreated,
    '',
    stat.classCreated
      ? 'LƯU Ý: lớp mới chưa có toạ độ phòng học. Mở 03_CLASSES điền RoomLat, RoomLng, ' +
        'AllowedRadiusM — không có thì mọi lượt điểm danh đều mang cờ VALID mà không kiểm tra gì.'
      : 'Kiểm tra lại 04_STUDENTS và 05_ENROLLMENTS trước khi dạy.'
  ].join('\n');

  Logger.log(msg);
  return msg;
}

/* ------------------------------------------------------------------ */
/*  PHÂN TÍCH — dùng chung cho cả preview và import                    */
/* ------------------------------------------------------------------ */

function analyzeImport_() {
  const ss = getSpreadsheet();
  const sheet = ss.getSheetByName(IMPORT_CONFIG.sheetName);
  if (!sheet) {
    return { error: 'Không tìm thấy sheet "' + IMPORT_CONFIG.sheetName +
                    '". Tạo sheet đó rồi dán danh sách vào, giữ nguyên dòng tiêu đề.' };
  }
  if (sheet.getLastRow() < 2) {
    return { error: 'Sheet "' + IMPORT_CONFIG.sheetName + '" chưa có dữ liệu.' };
  }

  const values  = sheet.getDataRange().getValues();
  const headers = values[0].map(function (h) { return String(h).trim(); });

  // Ánh xạ cột theo tên đã chuẩn hoá
  const mapping = {};
  const usedCols = {};
  Object.keys(COLUMN_ALIASES).forEach(function (field) {
    for (let i = 0; i < headers.length; i++) {
      if (usedCols[i] || !headers[i]) continue;
      const norm = normalizeHeader_(headers[i]);
      if (COLUMN_ALIASES[field].indexOf(norm) !== -1) {
        mapping[field] = headers[i];
        mapping['_' + field] = i;
        usedCols[i] = true;
        return;
      }
    }
  });

  // Không có cột gộp nhưng có cột "Tên" đứng một mình -> coi đó là họ tên
  if (mapping._fullName === undefined &&
      mapping._firstName !== undefined && mapping._lastName === undefined) {
    mapping.fullName = mapping.firstName;
    mapping._fullName = mapping._firstName;
    mapping._firstName = undefined;
  }

  const splitName = mapping._fullName === undefined &&
                    mapping._lastName !== undefined &&
                    mapping._firstName !== undefined;

  const blocking = [];
  if (mapping._mssv === undefined) blocking.push('Không tìm thấy cột MSSV.');
  if (mapping._fullName === undefined && !splitName) {
    blocking.push('Không tìm thấy cột họ tên.');
  }
  if (mapping._classCode === undefined && !IMPORT_CONFIG.defaultClassCode) {
    blocking.push('Không có cột mã lớp, và IMPORT_CONFIG.defaultClassCode đang để trống.');
  }

  const valid = [], skipped = [], duplicates = [];
  const seen = {}, byClass = {};

  for (let r = 1; r < values.length; r++) {
    const row = values[r];
    if (row.every(function (c) { return String(c).trim() === ''; })) continue;

    const mssv = mapping._mssv === undefined ? '' : String(row[mapping._mssv]).trim();
    if (!mssv) { skipped.push({ row: r + 1, reason: 'thiếu MSSV' }); continue; }
    if (!/^[0-9]{6,10}$/.test(mssv)) {
      skipped.push({ row: r + 1, reason: 'MSSV "' + mssv + '" không phải 6–10 chữ số' });
      continue;
    }
    if (seen[mssv]) { duplicates.push(mssv); continue; }
    seen[mssv] = true;

    const fullName = splitName
      ? (String(row[mapping._lastName]).trim() + ' ' + String(row[mapping._firstName]).trim()).trim()
      : (mapping._fullName === undefined ? '' : String(row[mapping._fullName]).trim());
    if (!fullName) { skipped.push({ row: r + 1, reason: 'MSSV ' + mssv + ' thiếu họ tên' }); continue; }

    const classCode = mapping._classCode === undefined
      ? IMPORT_CONFIG.defaultClassCode
      : (String(row[mapping._classCode]).trim() || IMPORT_CONFIG.defaultClassCode);
    if (!classCode) { skipped.push({ row: r + 1, reason: 'MSSV ' + mssv + ' thiếu mã lớp' }); continue; }

    valid.push({
      mssv: mssv,
      fullName: fullName,
      email: mapping._email === undefined ? '' : String(row[mapping._email]).trim(),
      classCode: classCode
    });
    byClass[classCode] = (byClass[classCode] || 0) + 1;
  }

  // Lớp nào đã có trong 03_CLASSES?
  const classExists = {};
  const known = {};
  Repos.classes().all().forEach(function (c) { known[String(c.ClassCode).trim()] = true; });
  Object.keys(byClass).forEach(function (code) {
    classExists[code] = !!known[code];
    if (!known[code] && !IMPORT_CONFIG.createMissingClass) {
      blocking.push('Lớp "' + code + '" chưa có trong 03_CLASSES. ' +
                    'Tạo trước, hoặc đặt IMPORT_CONFIG.createMissingClass = true.');
    }
  });

  if (!valid.length) blocking.push('Không có dòng nào hợp lệ.');

  return {
    mapping: pickLabels_(mapping),
    splitName: splitName,
    totalRows: values.length - 1,
    valid: valid, skipped: skipped, duplicates: duplicates,
    byClass: byClass, classExists: classExists, blocking: blocking
  };
}

/**
 * Chuẩn hoá tên cột: bỏ dấu tiếng Việt, bỏ mọi thứ không phải chữ và số.
 * Nhờ vậy "Họ và tên", "HO VA TEN", "ho_va_ten" đều về cùng một chuỗi.
 */
function normalizeHeader_(s) {
  return String(s)
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .replace(/đ/g, 'd').replace(/Đ/g, 'D')
    .toLowerCase()
    .replace(/[^a-z0-9]/g, '');
}

function pickLabels_(mapping) {
  const out = {};
  ['mssv', 'fullName', 'lastName', 'firstName', 'email', 'classCode'].forEach(function (k) {
    if (mapping[k]) out[k] = mapping[k];
  });
  return out;
}

function pad_(s, n) {
  s = String(s === undefined || s === null ? '' : s);
  return s.length >= n ? s + ' ' : s + new Array(n - s.length + 1).join(' ');
}
