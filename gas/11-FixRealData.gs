/**
 * 11-FixRealData.gs — Dọn dữ liệu lớp thật (25KTE1) + hỗ trợ nhiều giảng viên/lớp
 *
 * Giải quyết 2 việc phát sinh khi thầy điền tay vào 03_CLASSES:
 *
 *  1) NHIỀU GIẢNG VIÊN CHUNG MỘT LỚP
 *     Thầy đã tạo NHIỀU DÒNG cùng ClassID, mỗi dòng một LecturerID khác
 *     nhau, mong mỗi giảng viên "đứng tên" một dòng. SAI — SheetRepo chỉ
 *     đọc dòng ĐẦU TIÊN trùng ClassID (findOne lấy phần tử đầu), nên các
 *     giảng viên ở dòng sau vẫn bị assertClassAccess() từ chối dù đã điền.
 *     Từ bản này, 03_CLASSES.LecturerID nhận DANH SÁCH UserID cách nhau
 *     bởi dấu phẩy (ví dụ "1607,2015,2962") — MỘT dòng cho MỖI ClassID.
 *     Việc so khớp quyền dùng chung hàm classHasLecturer_() ở 03-Auth.gs.
 *     fixRealData() gộp các dòng trùng ClassID hiện có thành một dòng,
 *     LecturerID là hợp của mọi UserID đã điền tay, rồi xoá các dòng thừa.
 *
 *  2) MỘT SINH VIÊN HỌC CÙNG LÚC HAI LỚP (lý thuyết + thực hành)
 *     50 sinh viên nhập từ sheet IMPORT bị ClassID trống ở 05_ENROLLMENTS
 *     (lỗi từ lần chạy runImport() đầu tiên, trước khi lớp thật được tạo
 *     trong 03_CLASSES). Thực tế lớp 25KTE1 có lịch lý thuyết chung (thứ 7,
 *     tất cả 50 SV, phòng NĐH4.5) và lịch thực hành riêng theo Nhóm —
 *     25KTE1A hoặc 25KTE1B (phòng, buổi khác). Một sinh viên cần HAI dòng
 *     ghi danh, không phải một.
 *     fixRealData() đọc lại sheet IMPORT (cột Lop, Nhóm, MonHoc — đúng
 *     nguyên cột thầy dán từ danh sách nhà trường, không cần sửa gì), rồi:
 *       - Điền ClassID còn trống ở 05_ENROLLMENTS = lớp lý thuyết
 *         (ClassCode = "<mã môn>_<Lop>", ví dụ "LEC10001_25KTE1")
 *       - Thêm ghi danh còn thiếu = lớp thực hành theo đúng Nhóm
 *         (ClassCode = "<mã môn>_<Nhóm>_TT", ví dụ "LEC10001_25KTE1A_TT")
 *     Lớp phải đã có sẵn trong 03_CLASSES với đúng ClassCode đó — hàm này
 *     không tự tạo lớp mới, chỉ nối đúng ghi danh vào lớp đã có.
 *
 * CÁCH DÙNG — CHẠY TỪ TRÌNH SOẠN THẢO APPS SCRIPT, KHÔNG QUA WEB APP
 *   1. Mở script.google.com, vào đúng project này.
 *   2. Ở thanh công cụ trên cùng, ô chọn hàm (bên trái nút ▶ Run) —
 *      chọn "previewFixRealData".
 *   3. Bấm ▶ Run. Lần đầu Google sẽ hỏi cấp quyền — bấm Cho phép (tài
 *      khoản của thầy, không phải tài khoản lạ).
 *   4. Xem kết quả: View > Logs (hoặc Ctrl+Enter / Cmd+Enter). Bước này
 *      KHÔNG ghi gì vào sheet — chỉ xem trước.
 *   5. Đọc log thấy hợp lý thì đổi ô chọn hàm sang "fixRealData", bấm
 *      ▶ Run lần nữa — lúc này mới thật sự ghi vào 03_CLASSES/05_ENROLLMENTS.
 *   6. Sau khi có kết quả, gọi lại previewFixRealData() — phải báo
 *      "không còn gì để sửa" thì mới xong hẳn.
 *
 * Chạy lại nhiều lần AN TOÀN — dòng đã đúng thì bỏ qua, không tạo trùng,
 * không xoá nhầm. Có thể dùng lại mỗi học kỳ sau khi runImport() xong.
 */

const FIX_REAL_DATA_CONFIG = {
  importSheetName: 'IMPORT',
  // Hậu tố lớp thực hành — phải khớp đúng ClassCode đã đặt trong 03_CLASSES.
  // "LEC10001" + "_" + "25KTE1A" + "_TT" = "LEC10001_25KTE1A_TT"
  practicalSuffix: '_TT'
};

/**
 * Đưa cột LecturerID của 03_CLASSES về dạng VĂN BẢN.
 *
 * Bảng tính dùng định dạng Việt Nam (dấu phẩy = dấu thập phân). Gõ "1607,2115"
 * vào ô định dạng Tự động thì Sheets lưu thành SỐ 1607.2115 -> giảng viên bị từ
 * chối quyền. Hàm này đặt cột thành "Văn bản thuần tuý" rồi ghi lại đúng chuỗi.
 * CHẠY TAY MỘT LẦN từ trình soạn thảo Apps Script. Chạy lại an toàn.
 */
function fixLecturerIdFormat() {
  const repo = Repos.classes();
  const last = repo.sheet.getLastRow();
  if (last < 2) return 'Không có lớp nào.';

  const range = repo.sheet.getRange(2, repo.colIndex('LecturerID'), last - 1, 1);
  const log = [];
  const fixed = range.getValues().map(function (r, i) {
    if (typeof r[0] !== 'number') return [String(r[0])];
    const text = String(r[0]).replace('.', ',');
    const parts = text.split(',');
    log.push('dòng ' + (i + 2) + ': ' + r[0] + ' -> "' + text + '"' +
      (parts.length === 2 && parts[0].length !== parts[1].length
        ? '   <-- KIỂM TRA TAY: số 0 cuối có thể đã bị Sheets cắt mất' : ''));
    return [text];
  });

  withLock_(function () {
    range.setNumberFormat('@');
    range.setValues(fixed);
  });

  const msg = log.length ? 'Đã sửa:\n' + log.join('\n') : 'Cột LecturerID đã ở dạng văn bản, không cần sửa.';
  Logger.log(msg);
  return msg;
}

function previewFixRealData() {
  const r = analyzeFixRealData_();
  Logger.log(r.report);
  return r.report;
}

function fixRealData() {
  const r = analyzeFixRealData_();

  const classes = Repos.classes();
  const enrolls = Repos.enrollments();

  // Thứ tự BẮT BUỘC: cập nhật LecturerID TRƯỚC khi xoá dòng nào cả.
  // updateRow() dùng số dòng chụp lúc phân tích — nếu xoá trước, dòng nằm
  // SAU một dòng vừa xoá (dù ở nhóm ClassID khác) sẽ bị lệch số, ghi nhầm.
  Object.keys(r.classFix.mergedLecturerByRow).forEach(function (rowNum) {
    classes.updateRow(Number(rowNum), { LecturerID: r.classFix.mergedLecturerByRow[rowNum] });
  });

  // Xoá dòng trùng ClassID — từ số dòng LỚN xuống NHỎ, để các lần xoá sau
  // không bị lệch bởi lần xoá trước.
  r.classFix.duplicatesToDelete
    .slice()
    .sort(function (a, b) { return b - a; })
    .forEach(function (rowNum) { classes.sheet.deleteRow(rowNum); });

  // Điền ClassID (lý thuyết) cho ghi danh đang trống
  r.enrollFix.fillBlankClassId.forEach(function (item) {
    enrolls.updateRow(item.row, { ClassID: item.classId });
  });

  // Thêm ghi danh thực hành còn thiếu
  r.enrollFix.insertPractical.forEach(function (item) {
    enrolls.insert({
      EnrollmentID: newId('ENR'), StudentID: item.studentId, ClassID: item.classId,
      Status: RECORD_STATUS.ACTIVE, CreatedAt: nowStamp()
    });
  });

  logAudit('system', ROLE.ADMIN, 'FIX_REAL_DATA', 'SHEET', '03_CLASSES+05_ENROLLMENTS', {
    classesDeduped: r.classFix.duplicatesToDelete.length,
    lecturerMerged: Object.keys(r.classFix.mergedLecturerByRow).length,
    enrollFilled: r.enrollFix.fillBlankClassId.length,
    enrollInserted: r.enrollFix.insertPractical.length
  }, '');

  const msg = [
    '=== ĐÃ SỬA XONG ===',
    'Dòng lớp trùng ClassID đã gộp & xoá : ' + r.classFix.duplicatesToDelete.length,
    'Lớp được gộp LecturerID thành danh sách : ' + Object.keys(r.classFix.mergedLecturerByRow).length,
    'Ghi danh lý thuyết đã điền ClassID : ' + r.enrollFix.fillBlankClassId.length,
    'Ghi danh thực hành tạo mới : ' + r.enrollFix.insertPractical.length,
    '',
    r.unresolved.length
      ? 'CHƯA XỬ LÝ ĐƯỢC (' + r.unresolved.length + ' — xem chi tiết ở previewFixRealData()):\n- ' +
        r.unresolved.slice(0, 10).join('\n- ')
      : 'Không còn ghi danh nào chưa xử lý được.',
    '',
    r.warnings.length
      ? 'CẦN THẦY KIỂM TRA THÊM (không tự sửa):\n- ' + r.warnings.join('\n- ')
      : 'Không còn cảnh báo.'
  ].join('\n');
  Logger.log(msg);
  return msg;
}

/* ------------------------------------------------------------------ */

function analyzeFixRealData_() {
  const warnings = [];

  /* ---- 1) 03_CLASSES: gom theo ClassID, phát hiện dòng trùng ---- */
  const classRepo = Repos.classes();
  const allClasses = classRepo.all();
  const byClassId = {};
  allClasses.forEach(function (c) {
    const id = String(c.ClassID).trim();
    if (!id) {
      warnings.push('03_CLASSES dòng ' + c._row + ': ClassID trống — bỏ qua, không gộp được.');
      return;
    }
    (byClassId[id] = byClassId[id] || []).push(c);
  });

  const courseIds = {};
  Repos.courses().all().forEach(function (co) { courseIds[String(co.CourseID).trim()] = true; });

  const duplicatesToDelete = [];
  const mergedLecturerByRow = {};

  Object.keys(byClassId).forEach(function (id) {
    const rows = byClassId[id];

    if (rows[0].CourseID && !courseIds[String(rows[0].CourseID).trim()]) {
      warnings.push('03_CLASSES dòng ' + rows[0]._row + ' (ClassCode ' + rows[0].ClassCode +
        '): CourseID "' + rows[0].CourseID + '" không có trong 02_COURSES — kiểm tra lại tay, KHÔNG tự sửa.');
    }

    if (rows.length === 1) return;

    // Trùng ClassID -> gộp LecturerID của mọi dòng, giữ dòng đầu, xoá dòng sau
    const first = rows[0];
    const lecturerSet = {};
    rows.forEach(function (r) {
      String(r.LecturerID || '').split(',').forEach(function (u) {
        u = u.trim(); if (u) lecturerSet[u] = true;
      });
    });
    mergedLecturerByRow[first._row] = Object.keys(lecturerSet).join(',');
    rows.slice(1).forEach(function (r) { duplicatesToDelete.push(r._row); });

    ['ClassCode', 'RoomLat', 'RoomLng', 'AllowedRadiusM', 'CourseID'].forEach(function (field) {
      const vals = {};
      rows.forEach(function (r) { vals[String(r[field]).trim()] = true; });
      if (Object.keys(vals).length > 1) {
        warnings.push('ClassID ' + id + ': các dòng trùng có ' + field + ' KHÁC NHAU (' +
          Object.keys(vals).join(' vs ') + ') — đã giữ giá trị ở dòng đầu (dòng ' + first._row +
          '), thầy kiểm tra lại cho chắc.');
      }
    });
  });

  // ClassCode -> lớp đã gộp (dùng dòng đầu của mỗi ClassID)
  const classByCode = {};
  Object.keys(byClassId).forEach(function (id) {
    const first = byClassId[id][0];
    classByCode[String(first.ClassCode).trim()] = first;
  });

  /* ---- 2) Đọc sheet IMPORT để biết Lop/Nhóm/Môn của từng MSSV ---- */
  const ss = getSpreadsheet();
  const importSheet = ss.getSheetByName(FIX_REAL_DATA_CONFIG.importSheetName);
  const importByMssv = {};

  if (importSheet && importSheet.getLastRow() >= 2) {
    const values = importSheet.getDataRange().getValues();
    const headers = values[0].map(function (h) { return normalizeHeader_(String(h)); });
    const colIdx = function (aliases) {
      for (let i = 0; i < headers.length; i++) if (aliases.indexOf(headers[i]) !== -1) return i;
      return -1;
    };
    const iMssv = colIdx(['mssv', 'masosinhvien']);
    const iLop  = colIdx(['lop', 'malop']);
    const iNhom = colIdx(['nhom']);
    const iMon  = colIdx(['monhoc']);

    if (iMssv === -1) {
      warnings.push('Sheet "' + FIX_REAL_DATA_CONFIG.importSheetName +
        '" không có cột MSSV — bỏ qua bước điền ClassID theo Lop/Nhóm.');
    } else {
      for (let r = 1; r < values.length; r++) {
        const mssv = String(values[r][iMssv]).trim();
        if (!mssv) continue;
        const monHoc = iMon !== -1 ? String(values[r][iMon]).trim() : '';
        // "LEC10001 - Đá và khoáng vật" -> "LEC10001"
        const courseCode = monHoc.split(' - ')[0].trim();
        importByMssv[mssv] = {
          lop: iLop !== -1 ? String(values[r][iLop]).trim() : '',
          nhom: iNhom !== -1 ? String(values[r][iNhom]).trim() : '',
          courseCode: courseCode
        };
      }
    }
  } else {
    warnings.push('Không thấy sheet "' + FIX_REAL_DATA_CONFIG.importSheetName +
      '" (hoặc đang trống) — bỏ qua bước điền ClassID theo Lop/Nhóm.');
  }

  /* ---- 3) 05_ENROLLMENTS: điền ClassID trống + thêm ghi danh thực hành ---- */
  const studentRepo = Repos.students();
  const mssvByStudentId = {};
  studentRepo.all().forEach(function (s) { mssvByStudentId[String(s.StudentID).trim()] = String(s.MSSV).trim(); });

  const enrollRepo = Repos.enrollments();
  const allEnrolls = enrollRepo.all();
  const existingKey = {};
  allEnrolls.forEach(function (e) {
    existingKey[String(e.StudentID).trim() + '|' + String(e.ClassID).trim()] = true;
  });

  const fillBlankClassId = [];
  const unresolved = [];

  allEnrolls.forEach(function (e) {
    if (String(e.ClassID).trim()) return; // đã có ClassID, không đụng tới

    const studentId = String(e.StudentID).trim();
    const mssv = mssvByStudentId[studentId];
    const info = mssv ? importByMssv[mssv] : null;

    if (!info || !info.lop || !info.courseCode) {
      unresolved.push('Ghi danh dòng ' + e._row + ' (MSSV ' + (mssv || studentId) +
        '): ClassID trống nhưng không tìm thấy Lop/MonHoc tương ứng trong sheet IMPORT để tự điền.');
      return;
    }

    const lectureCode = info.courseCode + '_' + info.lop;
    const cls = classByCode[lectureCode];
    if (!cls) {
      unresolved.push('Ghi danh dòng ' + e._row + ' (MSSV ' + mssv + '): cần lớp ClassCode "' +
        lectureCode + '" nhưng chưa có trong 03_CLASSES.');
      return;
    }

    fillBlankClassId.push({ row: e._row, classId: cls.ClassID });
    existingKey[studentId + '|' + String(cls.ClassID).trim()] = true;
  });

  const insertPractical = [];
  Object.keys(importByMssv).forEach(function (mssv) {
    const info = importByMssv[mssv];
    if (!info.nhom || !info.courseCode) return;

    const student = studentRepo.findOne({ MSSV: mssv });
    if (!student) return;

    const practicalCode = info.courseCode + '_' + info.nhom + FIX_REAL_DATA_CONFIG.practicalSuffix;
    const cls = classByCode[practicalCode];
    if (!cls) {
      unresolved.push('MSSV ' + mssv + ': cần lớp thực hành ClassCode "' + practicalCode +
        '" nhưng chưa có trong 03_CLASSES.');
      return;
    }

    const key = String(student.StudentID).trim() + '|' + String(cls.ClassID).trim();
    if (existingKey[key]) return; // đã ghi danh rồi (lần chạy trước, hoặc runImport())

    insertPractical.push({ studentId: student.StudentID, classId: cls.ClassID, mssv: mssv });
    existingKey[key] = true;
  });

  /* ---- Báo cáo ---- */
  const report = [];
  report.push('=== XEM TRƯỚC — CHƯA GHI GÌ ===');
  report.push('');
  report.push('03_CLASSES:');
  report.push('  Dòng trùng ClassID sẽ gộp LecturerID & xoá dòng thừa: ' + duplicatesToDelete.length);
  Object.keys(mergedLecturerByRow).forEach(function (row) {
    report.push('    dòng ' + row + ' -> LecturerID mới: "' + mergedLecturerByRow[row] + '"');
  });
  report.push('');
  report.push('05_ENROLLMENTS:');
  report.push('  Điền ClassID (lý thuyết) cho ghi danh đang trống : ' + fillBlankClassId.length);
  report.push('  Thêm ghi danh thực hành còn thiếu                : ' + insertPractical.length);
  if (unresolved.length) {
    report.push('');
    report.push('CHƯA XỬ LÝ ĐƯỢC (' + unresolved.length + '):');
    unresolved.slice(0, 20).forEach(function (u) { report.push('  - ' + u); });
  }
  if (warnings.length) {
    report.push('');
    report.push('CẢNH BÁO (không tự sửa, thầy xem lại):');
    warnings.forEach(function (w) { report.push('  - ' + w); });
  }
  report.push('');
  report.push(duplicatesToDelete.length + fillBlankClassId.length + insertPractical.length > 0
    ? 'Sẵn sàng. Chạy fixRealData() để ghi thật.'
    : 'Không còn gì để sửa.');

  return {
    report: report.join('\n'),
    warnings: warnings,
    unresolved: unresolved,
    classFix: { duplicatesToDelete: duplicatesToDelete, mergedLecturerByRow: mergedLecturerByRow },
    enrollFix: { fillBlankClassId: fillBlankClassId, insertPractical: insertPractical }
  };
}
