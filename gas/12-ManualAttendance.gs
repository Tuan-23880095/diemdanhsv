/**
 * 12-ManualAttendance.gs — Nhập tay điểm danh các buổi đã học trước khi dùng web
 *
 * SHEET IMPORT_DS_DIEMDANH — dòng 1 là tiêu đề, mỗi dòng sau là MỘT sinh viên
 * trong MỘT buổi:
 *
 *   MSSV     | Buổi | Trạng thái   | Lớp (tuỳ chọn)
 *   19110432 | 1    | Có mặt       |
 *   19110432 | 2    | Trễ          |
 *   20110050 | 1    | Vắng         |
 *   20110050 | 2    | Vắng có phép | MTH10112_TTSP26
 *
 *  - Buổi: số buổi (SessionNo trong 06_SESSIONS), hoặc SessionID ("TTSP26_01").
 *  - Trạng thái: Có mặt / Trễ / Vắng / Vắng có phép (có dấu hay không dấu,
 *    hoa hay thường đều được; cũng nhận x / t / v / p).
 *  - Lớp: bỏ trống thì dùng MANUAL_ATT_CONFIG.defaultClassCode bên dưới.
 *    Ghi ClassCode ("MTH10112_TTSP26") hoặc ClassID đều được.
 *
 * CÁCH DÙNG — CHẠY TỪ TRÌNH SOẠN THẢO APPS SCRIPT
 *   1. (Một lần) chạy setupImportDsDiemDanh() — tạo sẵn tiêu đề, định dạng
 *      văn bản, danh sách chọn cho cột Trạng thái.
 *   2. Dán danh sách vào sheet.
 *   3. Chạy previewManualAttendance() — KHÔNG ghi gì vào 07_ATTENDANCE, chỉ
 *      điền cột "Kết quả" để thầy xem từng dòng sẽ được xử lý thế nào.
 *   4. Ổn thì chạy importManualAttendance().
 *
 * Chạy lại nhiều lần AN TOÀN:
 *  - Mỗi cặp MSSV + buổi chỉ có MỘT dòng trong 07_ATTENDANCE (thiết kế D.8-3).
 *    Đã có thì cập nhật trạng thái, không tạo dòng trùng.
 *  - Dòng lỗi (sai MSSV, không có buổi...) bị bỏ qua, các dòng đúng vẫn được
 *    nhập. Sửa dòng lỗi rồi chạy lại là đủ.
 *  - Mọi thay đổi đều ghi 12_AUDIT_LOG (thiết kế D.8-6).
 *
 * Nên chạy NGOÀI giờ đang mở điểm danh trên web, để không chen với lượt gửi
 * của sinh viên.
 */

const MANUAL_ATT_CONFIG = {
  sheetName: 'IMPORT_DS_DIEMDANH',
  defaultClassCode: 'MTH10112_TTSP26',
  note: 'Nhập tay từ IMPORT_DS_DIEMDANH'
};

/** Tạo tiêu đề + định dạng cho sheet nhập. Không xoá dữ liệu đã có. */
function setupImportDsDiemDanh() {
  const ss = getSpreadsheet();
  const sh = ss.getSheetByName(MANUAL_ATT_CONFIG.sheetName) || ss.insertSheet(MANUAL_ATT_CONFIG.sheetName);

  if (String(sh.getRange(1, 1).getValue()).trim()) {
    const m = 'Sheet ' + MANUAL_ATT_CONFIG.sheetName + ' đã có tiêu đề — không đụng tới.';
    Logger.log(m);
    return m;
  }

  const rows = Math.max(sh.getMaxRows() - 1, 1);
  sh.getRange(1, 1, 1, 5).setValues([['MSSV', 'Buổi', 'Trạng thái', 'Lớp', 'Kết quả']]).setFontWeight('bold');
  // Văn bản thuần: tránh Sheets tự đổi MSSV/mã lớp thành số
  sh.getRange(2, 1, rows, 4).setNumberFormat('@');
  sh.getRange(2, 3, rows, 1).setDataValidation(
    SpreadsheetApp.newDataValidation()
      .requireValueInList(['Có mặt', 'Trễ', 'Vắng', 'Vắng có phép'], true)
      .setAllowInvalid(true)
      .build());
  sh.setFrozenRows(1);

  const m = 'Đã tạo tiêu đề cho ' + MANUAL_ATT_CONFIG.sheetName + '. Dán danh sách từ dòng 2.';
  Logger.log(m);
  return m;
}

function previewManualAttendance() {
  const plan = analyzeManualAttendance_();
  writeManualResults_(plan, true);
  const msg = manualReport_(plan, true);
  Logger.log(msg);
  return msg;
}

function importManualAttendance() {
  const plan = analyzeManualAttendance_();
  const att = Repos.attendance();
  const now = nowStamp();

  att.insertMany(plan.inserts.map(function (it) {
    return {
      AttendanceID: newId('ATT'),
      StudentID: it.studentId,
      SessionID: it.sessionId,
      Status: it.status,
      CheckInTime: '',
      Note: MANUAL_ATT_CONFIG.note,
      CreatedAt: now
    };
  }));

  plan.updates.forEach(function (it) {
    att.updateRow(it.row, {
      Status: it.status,
      Note: (it.oldNote ? it.oldNote + ' | ' : '') +
            MANUAL_ATT_CONFIG.note + ' (sửa từ ' + it.from + ')'
    });
  });

  // [D.8-6] Nhật ký cho mọi dòng thay đổi — ghi một lần cho nhanh
  Repos.audit().insertMany(plan.inserts.concat(plan.updates).map(function (it) {
    return {
      LogID: newId('LOG'),
      Time: now,
      Actor: 'system',
      ActorRole: ROLE.ADMIN,
      Action: it.from ? 'ATTENDANCE_MANUAL_UPDATE' : 'ATTENDANCE_MANUAL_INSERT',
      TargetType: 'SESSION',
      TargetID: it.sessionId,
      Data: JSON.stringify({ mssv: it.mssv, status: it.status, from: it.from || '' }),
      IP: ''
    };
  }));

  writeManualResults_(plan, false);
  const msg = manualReport_(plan, false);
  Logger.log(msg);
  return msg;
}

/* ------------------------------------------------------------------ */

function analyzeManualAttendance_() {
  const sh = getSpreadsheet().getSheetByName(MANUAL_ATT_CONFIG.sheetName);
  if (!sh || sh.getLastRow() < 2) {
    throw new Error('Sheet ' + MANUAL_ATT_CONFIG.sheetName +
      ' chưa có hoặc đang trống. Chạy setupImportDsDiemDanh() rồi dán danh sách vào.');
  }

  const values = sh.getDataRange().getValues();
  const headers = values[0].map(function (h) { return normalizeHeader_(h); });
  const col = function (aliases) {
    for (let i = 0; i < headers.length; i++) if (aliases.indexOf(headers[i]) !== -1) return i;
    return -1;
  };
  const iMssv = col(['mssv', 'masosinhvien', 'masv']);
  const iBuoi = col(['buoi', 'buoihoc', 'buoiso', 'sessionno', 'sessionid']);
  const iTT   = col(['trangthai', 'status', 'diemdanh']);
  const iLop  = col(['lop', 'malop', 'classcode', 'classid']);
  const iKq   = col(['ketqua']);

  const missing = [];
  if (iMssv === -1) missing.push('MSSV');
  if (iBuoi === -1) missing.push('Buổi');
  if (iTT === -1) missing.push('Trạng thái');
  if (missing.length) {
    throw new Error('Sheet ' + MANUAL_ATT_CONFIG.sheetName + ' thiếu cột: ' + missing.join(', ') +
      '. Dòng 1 phải là tiêu đề: MSSV | Buổi | Trạng thái | Lớp (tuỳ chọn).');
  }

  /* ---- Bảng tra ---- */
  const classByRef = {};
  Repos.classes().all().forEach(function (c) {
    classByRef[String(c.ClassCode).trim()] = c;
    classByRef[String(c.ClassID).trim()] = c;
  });

  const studentByMssv = {};
  Repos.students().all().forEach(function (s) {
    const k = String(s.MSSV).trim();
    if (!studentByMssv[k] || isActiveRow_(s)) studentByMssv[k] = s;
  });

  const enrolled = {};
  Repos.enrollments().all().filter(isActiveRow_).forEach(function (e) {
    enrolled[String(e.StudentID).trim() + '|' + String(e.ClassID).trim()] = true;
  });

  const sessionById = {}, sessionByNo = {};
  Repos.sessions().all().filter(isActiveRow_).forEach(function (s) {
    sessionById[String(s.SessionID).trim()] = s;
    sessionByNo[String(s.ClassID).trim() + '|' + Number(s.SessionNo)] = s;
  });

  const existing = {};
  Repos.attendance().all().forEach(function (a) {
    existing[String(a.StudentID).trim() + '|' + String(a.SessionID).trim()] = a;
  });

  /* ---- Duyệt từng dòng ---- */
  const plan = {
    sheet: sh, resultCol: iKq === -1 ? headers.length : iKq, rowCount: values.length - 1,
    results: [], inserts: [], updates: [], unchanged: 0, errors: []
  };
  const seen = {};

  for (let r = 1; r < values.length; r++) {
    const row = values[r];
    const mssv = String(row[iMssv]).trim();
    const buoi = String(row[iBuoi]).trim();
    const ttRaw = String(row[iTT]).trim();
    const fail = function (msg) {
      plan.errors.push('Dòng ' + (r + 1) + ': ' + msg);
      plan.results[r - 1] = { kind: 'error', text: msg };
    };

    if (!mssv && !buoi && !ttRaw) { plan.results[r - 1] = { kind: 'blank' }; continue; }

    const classRef = (iLop !== -1 && String(row[iLop]).trim()) || MANUAL_ATT_CONFIG.defaultClassCode;
    const cls = classByRef[classRef];
    if (!cls) { fail('không có lớp "' + classRef + '" trong 03_CLASSES'); continue; }
    const classId = String(cls.ClassID).trim();

    const student = studentByMssv[mssv];
    if (!student) { fail('không tìm thấy MSSV ' + mssv + ' trong 04_STUDENTS'); continue; }
    const studentId = String(student.StudentID).trim();

    if (!enrolled[studentId + '|' + classId]) {
      fail('MSSV ' + mssv + ' không có trong danh sách lớp ' + cls.ClassCode); continue;
    }

    const session = (sessionById[buoi] && String(sessionById[buoi].ClassID).trim() === classId)
      ? sessionById[buoi]
      : sessionByNo[classId + '|' + Number(buoi)];
    if (!buoi || !session) { fail('lớp ' + cls.ClassCode + ' không có buổi "' + buoi + '" trong 06_SESSIONS'); continue; }
    const sessionId = String(session.SessionID).trim();

    const status = parseManualStatus_(ttRaw);
    if (!status) { fail('không hiểu trạng thái "' + ttRaw + '" — dùng Có mặt / Trễ / Vắng / Vắng có phép'); continue; }

    const key = studentId + '|' + sessionId;
    if (seen[key]) { fail('trùng dòng ' + seen[key] + ' (cùng MSSV, cùng buổi) — xoá bớt một dòng'); continue; }
    seen[key] = r + 1;

    const item = { mssv: mssv, studentId: studentId, sessionId: sessionId, status: status };
    const old = existing[key];

    if (!old) {
      plan.inserts.push(item);
      plan.results[r - 1] = { kind: 'insert', text: STATUS_LABEL_[status] };
    } else if (String(old.Status).trim().toUpperCase() === status) {
      plan.unchanged++;
      plan.results[r - 1] = { kind: 'same', text: STATUS_LABEL_[status] };
    } else {
      item.row = old._row;
      item.from = String(old.Status).trim();
      item.oldNote = String(old.Note || '').trim();
      plan.updates.push(item);
      plan.results[r - 1] = {
        kind: 'update',
        text: (STATUS_LABEL_[item.from] || item.from) + ' → ' + STATUS_LABEL_[status] +
              (old.CheckInTime ? ' (đè lên bản ghi sinh viên tự điểm danh trên web)' : '')
      };
    }
  }

  return plan;
}

const STATUS_LABEL_ = {
  PRESENT: 'Có mặt', LATE: 'Trễ', ABSENT: 'Vắng', EXCUSED: 'Vắng có phép'
};

/** "Có mặt" / "co mat" / "x" -> PRESENT ... Trả null nếu không hiểu. */
function parseManualStatus_(v) {
  const s = normalizeHeader_(v);
  if (['vangcophep', 'cophep', 'phep', 'p', 'vcp', 'excused'].indexOf(s) !== -1) return ATTENDANCE_STATUS.EXCUSED;
  if (['comat', 'cm', 'x', 'co', 'present'].indexOf(s) !== -1) return ATTENDANCE_STATUS.PRESENT;
  if (['tre', 'ditre', 'muon', 't', 'late'].indexOf(s) !== -1) return ATTENDANCE_STATUS.LATE;
  if (['vang', 'v', 'vangkhongphep', 'kp', 'absent'].indexOf(s) !== -1) return ATTENDANCE_STATUS.ABSENT;
  return null;
}

/** Điền cột "Kết quả" cạnh từng dòng để thầy thấy ngay dòng nào lỗi */
function writeManualResults_(plan, isPreview) {
  const verbs = isPreview
    ? { insert: 'Sẽ thêm: ', update: 'Sẽ sửa: ', same: 'Đã có sẵn: ' }
    : { insert: 'Đã thêm: ', update: 'Đã sửa: ', same: 'Đã có sẵn: ' };

  const out = [];
  for (let i = 0; i < plan.rowCount; i++) {
    const res = plan.results[i] || { kind: 'blank' };
    out.push([res.kind === 'blank' ? ''
      : res.kind === 'error' ? 'LỖI: ' + res.text
      : verbs[res.kind] + res.text]);
  }

  const c = plan.resultCol + 1;
  withLock_(function () {
    plan.sheet.getRange(1, c).setValue('Kết quả').setFontWeight('bold');
    if (out.length) plan.sheet.getRange(2, c, out.length, 1).setValues(out);
  });
}

function manualReport_(plan, isPreview) {
  const lines = [
    isPreview ? '=== XEM TRƯỚC — CHƯA GHI VÀO 07_ATTENDANCE ===' : '=== ĐÃ NHẬP XONG ===',
    'Thêm mới         : ' + plan.inserts.length,
    'Sửa trạng thái   : ' + plan.updates.length,
    'Đã có, giữ nguyên: ' + plan.unchanged,
    'Dòng lỗi, bỏ qua : ' + plan.errors.length
  ];
  if (plan.errors.length) {
    lines.push('');
    lines.push('LỖI (xem cột "Kết quả" trong sheet ' + MANUAL_ATT_CONFIG.sheetName + '):');
    plan.errors.slice(0, 30).forEach(function (e) { lines.push('  - ' + e); });
    if (plan.errors.length > 30) lines.push('  ... và ' + (plan.errors.length - 30) + ' lỗi nữa');
  }
  lines.push('');
  lines.push(isPreview
    ? 'Ổn thì chạy importManualAttendance().'
    : (plan.errors.length ? 'Sửa các dòng lỗi rồi chạy lại importManualAttendance() — dòng đã nhập sẽ không bị trùng.'
                          : 'Hoàn tất.'));
  return lines.join('\n');
}
