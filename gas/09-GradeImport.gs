/**
 * 09-GradeImport.gs — Nhập bảng điểm vào 09_GRADE_COLUMNS và 10_GRADES
 *
 * CÁCH DÙNG
 *   1. Tạo sheet tên "IMPORT_DIEM" trong chính file Google Sheet này.
 *   2. Dòng 1 là tiêu đề. Cột đầu là MSSV, các cột sau là từng đầu điểm,
 *      TÊN CỘT KÈM TRỌNG SỐ trong ngoặc:
 *
 *        MSSV | Chuyên cần (10%) | Giữa kỳ (30%) | Cuối kỳ (60%)
 *        25160003 | 9   | 7   | 8
 *        25160004 | 8.5 |     | 7
 *
 *   3. Chạy previewGradeImport() -> xem hiểu đúng chưa, KHÔNG ghi gì
 *   4. Thấy đúng thì chạy runGradeImport() -> mới thật sự ghi
 *
 * Ô ĐỂ TRỐNG nghĩa là CHƯA CHẤM, không phải 0 điểm — hàm bỏ qua ô đó,
 * không ghi gì. Nhờ vậy nhập điểm làm nhiều đợt được: chấm chuyên cần
 * trước thì nhập trước, cuối kỳ chấm xong nhập bổ sung sau.
 *
 * Chạy lại an toàn: điểm đã có thì CẬP NHẬT, không tạo dòng trùng.
 */

const GRADE_IMPORT_CONFIG = {
  sheetName: 'IMPORT_DIEM',

  // Dùng khi sheet không có cột mã lớp. Để '' nếu sheet đã có cột đó.
  defaultClassCode: '',

  // Thang điểm hợp lệ. Ngoài khoảng này bị coi là gõ nhầm và bị bỏ qua.
  minScore: 0,
  maxScore: 10
};

/* ------------------------------------------------------------------ */
/*  CHẠY THỬ — không ghi gì                                            */
/* ------------------------------------------------------------------ */

function previewGradeImport() {
  const r = analyzeGradeImport_();
  if (r.error) { Logger.log(r.error); return r.error; }

  const out = [];
  out.push('=== XEM TRƯỚC BẢNG ĐIỂM — CHƯA GHI GÌ ===');
  out.push('');
  out.push('Lớp nhận điểm: ' + r.classCode + (r.classExists ? '' : '   <-- CHƯA CÓ TRONG 03_CLASSES'));
  out.push('Cột MSSV     : "' + r.mssvHeader + '"');
  out.push('');

  out.push('Các đầu điểm đọc được:');
  r.columns.forEach(function (c) {
    out.push('  ' + pad_(c.name, 22) + 'trọng số ' + pad_(String(c.weight), 6) +
             (c.exists ? '(đã có trong 09_GRADE_COLUMNS, sẽ cập nhật trọng số)'
                       : '(sẽ tạo mới)'));
  });
  out.push('  ' + pad_('TỔNG TRỌNG SỐ', 22) + r.totalWeight +
           (r.totalWeight === 100 ? '' : '   <-- KHÁC 100, kiểm tra lại'));

  out.push('');
  out.push('Tổng số dòng dữ liệu : ' + r.totalRows);
  out.push('Sinh viên khớp       : ' + r.matched.length);
  out.push('Dòng bị bỏ qua       : ' + r.skipped.length);
  out.push('Số ô điểm sẽ ghi     : ' + r.cellCount);
  out.push('Ô để trống (chưa chấm, bỏ qua): ' + r.blankCount);

  if (r.badScores.length) {
    out.push('');
    out.push('Điểm không hợp lệ (bỏ qua, tối đa 10):');
    r.badScores.slice(0, 10).forEach(function (b) {
      out.push('  dòng ' + b.row + ' · ' + b.mssv + ' · ' + b.col + ' = "' + b.raw + '"');
    });
  }

  if (r.skipped.length) {
    out.push('');
    out.push('Dòng bị bỏ qua (tối đa 10):');
    r.skipped.slice(0, 10).forEach(function (s) {
      out.push('  dòng ' + s.row + ': ' + s.reason);
    });
  }

  out.push('');
  out.push('Năm dòng đầu đọc được:');
  r.matched.slice(0, 5).forEach(function (m) {
    const scores = r.columns.map(function (c) {
      const v = m.scores[c.name];
      return c.name + '=' + (v === undefined ? '—' : v);
    }).join('  ');
    out.push('  ' + pad_(m.mssv, 12) + pad_(m.fullName, 26) + scores);
  });

  out.push('');
  out.push(r.blocking.length
    ? 'CHƯA CHẠY ĐƯỢC:\n- ' + r.blocking.join('\n- ')
    : 'Sẵn sàng. Chạy runGradeImport() để ghi thật.');

  const msg = out.join('\n');
  Logger.log(msg);
  return msg;
}

/* ------------------------------------------------------------------ */
/*  GHI THẬT                                                           */
/* ------------------------------------------------------------------ */

function runGradeImport() {
  const r = analyzeGradeImport_();
  if (r.error) { Logger.log(r.error); return r.error; }
  if (r.blocking.length) {
    const m = 'DỪNG — còn vướng:\n- ' + r.blocking.join('\n- ') +
              '\nChạy previewGradeImport() để xem chi tiết.';
    Logger.log(m); return m;
  }

  const colRepo = Repos.gradeCols();
  const gradeRepo = Repos.grades();
  const stat = { colCreated: 0, colUpdated: 0, scoreInserted: 0, scoreUpdated: 0 };

  // 1. Tạo / cập nhật các đầu điểm
  const colIdByName = {};
  r.columns.forEach(function (c, i) {
    if (c.existing) {
      const patch = {};
      if (Number(c.existing.Weight) !== c.weight) patch.Weight = c.weight;
      if (Number(c.existing.SortOrder) !== i + 1) patch.SortOrder = i + 1;
      if (Object.keys(patch).length) { colRepo.updateRow(c.existing._row, patch); stat.colUpdated++; }
      colIdByName[c.name] = c.existing.GradeColumnID;
    } else {
      const rec = {
        GradeColumnID: newId('GCL'),
        ClassID: r.classId,
        Name: c.name,
        Weight: c.weight,
        SortOrder: i + 1,
        Status: RECORD_STATUS.ACTIVE,
        CreatedAt: nowStamp()
      };
      colRepo.insert(rec);
      colIdByName[c.name] = rec.GradeColumnID;
      stat.colCreated++;
    }
  });

  // 2. Ghi từng ô điểm. Ô trống đã bị loại từ bước phân tích.
  r.matched.forEach(function (m) {
    Object.keys(m.scores).forEach(function (colName) {
      const res = gradeRepo.upsert(
        {
          StudentID: m.studentId,
          ClassID: r.classId,
          GradeColumnID: colIdByName[colName]
        },
        {
          GradeID: newId('GRD'),
          StudentID: m.studentId,
          ClassID: r.classId,
          GradeColumnID: colIdByName[colName],
          Score: m.scores[colName],
          UpdatedBy: 'import',
          UpdatedAt: nowStamp()
        }
      );
      if (res.action === 'INSERTED') stat.scoreInserted++; else stat.scoreUpdated++;
    });
  });

  logAudit('system', ROLE.ADMIN, 'IMPORT_GRADES', 'CLASS', r.classId, stat, '');

  const msg = [
    '=== ĐÃ NHẬP ĐIỂM XONG ===',
    'Lớp                  : ' + r.classCode,
    'Đầu điểm tạo mới     : ' + stat.colCreated,
    'Đầu điểm cập nhật    : ' + stat.colUpdated,
    'Ô điểm ghi mới       : ' + stat.scoreInserted,
    'Ô điểm cập nhật      : ' + stat.scoreUpdated,
    '',
    'Sinh viên xem được ngay qua trang xem điểm.'
  ].join('\n');

  Logger.log(msg);
  return msg;
}

/* ------------------------------------------------------------------ */
/*  PHÂN TÍCH — dùng chung cho preview và ghi thật                     */
/* ------------------------------------------------------------------ */

function analyzeGradeImport_() {
  const ss = getSpreadsheet();
  const sheet = ss.getSheetByName(GRADE_IMPORT_CONFIG.sheetName);
  if (!sheet) {
    return { error: 'Không tìm thấy sheet "' + GRADE_IMPORT_CONFIG.sheetName +
                    '". Tạo sheet đó rồi dán bảng điểm vào.' };
  }
  if (sheet.getLastRow() < 2) {
    return { error: 'Sheet "' + GRADE_IMPORT_CONFIG.sheetName + '" chưa có dữ liệu.' };
  }

  const values  = sheet.getDataRange().getValues();
  const headers = values[0].map(function (h) { return String(h).trim(); });
  const blocking = [];

  // --- Cột MSSV (dùng chung alias với 07-Import.gs) ---
  let mssvIdx = -1;
  for (let i = 0; i < headers.length; i++) {
    if (headers[i] && COLUMN_ALIASES.mssv.indexOf(normalizeHeader_(headers[i])) !== -1) {
      mssvIdx = i; break;
    }
  }
  if (mssvIdx === -1) blocking.push('Không tìm thấy cột MSSV.');

  // --- Cột mã lớp (tuỳ chọn) ---
  let classIdx = -1;
  for (let i = 0; i < headers.length; i++) {
    if (i !== mssvIdx && headers[i] &&
        COLUMN_ALIASES.classCode.indexOf(normalizeHeader_(headers[i])) !== -1) {
      classIdx = i; break;
    }
  }

  // --- Các cột đầu điểm: mọi cột còn lại có tiêu đề ---
  const columns = [];
  for (let i = 0; i < headers.length; i++) {
    if (i === mssvIdx || i === classIdx || !headers[i]) continue;
    const parsed = parseGradeHeader_(headers[i]);
    columns.push({ idx: i, name: parsed.name, weight: parsed.weight, rawHeader: headers[i] });
  }
  if (!columns.length) blocking.push('Không có cột đầu điểm nào ngoài MSSV.');

  columns.forEach(function (c) {
    if (c.weight === null) {
      blocking.push('Cột "' + c.rawHeader + '" chưa ghi trọng số. ' +
                    'Sửa tiêu đề thành dạng "' + c.name + ' (30%)".');
    }
  });

  // --- Xác định lớp ---
  let classCode = GRADE_IMPORT_CONFIG.defaultClassCode;
  if (classIdx !== -1) {
    for (let r = 1; r < values.length; r++) {
      const v = String(values[r][classIdx]).trim();
      if (v) { classCode = v; break; }
    }
  }
  if (!classCode) {
    blocking.push('Không có cột mã lớp, và GRADE_IMPORT_CONFIG.defaultClassCode đang trống.');
  }

  const cls = classCode ? Repos.classes().findOne({ ClassCode: classCode }) : null;
  if (classCode && !cls) {
    blocking.push('Lớp "' + classCode + '" chưa có trong 03_CLASSES.');
  }
  const classId = cls ? cls.ClassID : '';

  // --- Đầu điểm đã tồn tại của lớp này ---
  const existingByName = {};
  if (classId) {
    Repos.gradeCols().findWhere({ ClassID: classId }).forEach(function (c) {
      existingByName[normalizeHeader_(c.Name)] = c;
    });
  }
  columns.forEach(function (c) {
    const found = existingByName[normalizeHeader_(c.name)];
    c.existing = found || null;
    c.exists   = !!found;
  });

  // --- Sinh viên của lớp ---
  const studentByMssv = {};
  Repos.students().all().forEach(function (s) {
    studentByMssv[String(s.MSSV).trim()] = s;
  });
  const enrolledIds = {};
  if (classId) {
    Repos.enrollments().findWhere({ ClassID: classId, Status: RECORD_STATUS.ACTIVE })
      .forEach(function (e) { enrolledIds[String(e.StudentID).trim()] = true; });
  }

  // --- Duyệt từng dòng ---
  const matched = [], skipped = [], badScores = [];
  const seen = {};
  let cellCount = 0, blankCount = 0;

  for (let r = 1; r < values.length; r++) {
    const row = values[r];
    if (row.every(function (c) { return String(c).trim() === ''; })) continue;

    const mssv = mssvIdx === -1 ? '' : String(row[mssvIdx]).trim();
    if (!mssv) { skipped.push({ row: r + 1, reason: 'thiếu MSSV' }); continue; }
    if (seen[mssv]) { skipped.push({ row: r + 1, reason: 'MSSV ' + mssv + ' trùng, giữ dòng đầu' }); continue; }
    seen[mssv] = true;

    const st = studentByMssv[mssv];
    if (!st) { skipped.push({ row: r + 1, reason: 'MSSV ' + mssv + ' chưa có trong 04_STUDENTS' }); continue; }
    if (classId && !enrolledIds[String(st.StudentID).trim()]) {
      skipped.push({ row: r + 1, reason: 'MSSV ' + mssv + ' không ghi danh lớp ' + classCode });
      continue;
    }

    const scores = {};
    columns.forEach(function (c) {
      const raw = String(row[c.idx]).trim();
      if (raw === '') { blankCount++; return; }          // chưa chấm — bỏ qua
      const n = Number(raw.replace(',', '.'));            // chấp nhận cả 8,5
      if (isNaN(n) || n < GRADE_IMPORT_CONFIG.minScore || n > GRADE_IMPORT_CONFIG.maxScore) {
        badScores.push({ row: r + 1, mssv: mssv, col: c.name, raw: raw });
        return;
      }
      scores[c.name] = n;
      cellCount++;
    });

    matched.push({ mssv: mssv, studentId: st.StudentID, fullName: st.FullName, scores: scores });
  }

  if (!matched.length) blocking.push('Không có sinh viên nào khớp.');

  let totalWeight = 0;
  columns.forEach(function (c) { totalWeight += Number(c.weight || 0); });

  return {
    mssvHeader: mssvIdx === -1 ? '(không có)' : headers[mssvIdx],
    classCode: classCode, classId: classId, classExists: !!cls,
    columns: columns, totalWeight: totalWeight,
    totalRows: values.length - 1,
    matched: matched, skipped: skipped, badScores: badScores,
    cellCount: cellCount, blankCount: blankCount,
    blocking: blocking
  };
}

/**
 * Tách tên đầu điểm và trọng số từ tiêu đề cột.
 * Nhận được: "Chuyên cần (10%)", "Chuyên cần (10)", "Chuyên cần 10%".
 * Không thấy số nào -> weight = null, hàm gọi sẽ báo lỗi bắt sửa tiêu đề.
 */
function parseGradeHeader_(header) {
  const s = String(header).trim();
  const m = s.match(/^(.*?)[\s(\[]*(\d+(?:[.,]\d+)?)\s*%?\s*[)\]]*$/);
  if (m && m[2] !== undefined && String(m[1]).trim() !== '') {
    return {
      name: String(m[1]).replace(/[\s(\[]+$/, '').trim(),
      weight: Number(String(m[2]).replace(',', '.'))
    };
  }
  return { name: s, weight: null };
}
