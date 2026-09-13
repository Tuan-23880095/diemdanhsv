/**
 * 10-AttendanceScore.gs — Tính điểm chuyên cần TỰ ĐỘNG từ dữ liệu điểm danh
 *
 * CĂN CỨ: rubric ĐG1.6 trong đề cương LEC10001 chia chuyên cần làm 3 tiêu chí
 *
 *   Tham dự và đúng giờ            50%   <-- ĐO ĐƯỢC từ 07_ATTENDANCE
 *   Chuẩn bị và tham gia hoạt động 30%   <-- giảng viên tự đánh giá
 *   Kỷ luật, an toàn, trung thực   20%   <-- giảng viên tự đánh giá
 *
 * Hàm này CHỈ tính được tiêu chí thứ nhất. Hai tiêu chí còn lại không có dữ
 * liệu nào trong hệ thống để suy ra, nên KHÔNG được bịa. Điểm ĐG1.6 đề xuất
 * = 50% điểm tham dự (tính tự động) + 50% điểm hai tiêu chí kia (lấy theo
 * ATT_SCORE_CONFIG.defaultOtherScore, mặc định 10 = không có gì để trừ).
 * Em nào cần trừ hai tiêu chí kia thì thầy sửa tay sau khi nhập.
 *
 * THANG ĐIỂM Tham dự bám đúng bốn mức của rubric, nội suy tuyến tính trong
 * mỗi mức để công bằng giữa các em cùng mức:
 *
 *   tỉ lệ >= 95%        -> 9,0 – 10    (Xuất sắc)
 *   90% <= tỉ lệ < 95%  -> 7,0 – 8,9   (Tốt)
 *   85% <= tỉ lệ < 90%  -> 5,0 – 6,9   (Đạt)
 *   tỉ lệ <  85%        -> 0   – 4,9   (Chưa đạt, và VI PHẠM điều kiện dự lớp)
 *
 * CÁCH DÙNG
 *   1. Điền classCode vào ATT_SCORE_CONFIG bên dưới.
 *   2. Chạy previewAttendanceScore() -> xem bảng tính, KHÔNG ghi gì.
 *   3. Đúng thì chạy runAttendanceScore() -> ghi vào 10_GRADES.
 *
 * Chạy lại bao nhiêu lần cũng được: điểm cũ được cập nhật, không tạo dòng trùng.
 */

const ATT_SCORE_CONFIG = {
  classCode: '',                      // ví dụ: '25KTE1'

  columnName:   'ĐG1.6 Chuyên cần',   // tên đầu điểm trong 09_GRADE_COLUMNS
  columnWeight: 5,                    // trọng số ĐG1.6 theo đề cương

  // Tỉ trọng tiêu chí "Tham dự và đúng giờ" bên trong ĐG1.6 (rubric: 50%)
  attendanceCriterionWeight: 50,
  // Điểm mặc định cho hai tiêu chí giảng viên tự đánh giá
  defaultOtherScore: 10,

  // Buổi đi TRỄ tính bằng bao nhiêu buổi có mặt (rubric coi đi muộn là trừ)
  latePenalty: 0.5,

  // Ngưỡng điều kiện dự lớp theo đề cương: LT 85%, TH 85%
  minRate: 85
};

/* ------------------------------------------------------------------ */
/*  XEM TRƯỚC — không ghi gì                                           */
/* ------------------------------------------------------------------ */

function previewAttendanceScore() {
  const r = analyzeAttendanceScore_();
  if (r.error) { Logger.log(r.error); return r.error; }

  const out = [];
  out.push('=== ĐIỂM CHUYÊN CẦN TỰ ĐỘNG — CHƯA GHI GÌ ===');
  out.push('');
  out.push('Lớp                    : ' + r.classCode);
  out.push('Số buổi đã điểm danh   : ' + r.sessionCount +
           (r.sessionCount ? '' : '   <-- chưa có buổi nào đóng điểm danh'));
  out.push('Sĩ số                  : ' + r.rows.length);
  out.push('Buổi trễ tính bằng     : ' + ATT_SCORE_CONFIG.latePenalty + ' buổi có mặt');
  out.push('Điểm 2 tiêu chí GV tự chấm: ' + ATT_SCORE_CONFIG.defaultOtherScore +
           ' (chiếm ' + (100 - ATT_SCORE_CONFIG.attendanceCriterionWeight) + '% của ĐG1.6)');
  out.push('');

  out.push(pad_('MSSV', 12) + pad_('Họ tên', 26) + pad_('Mặt', 5) + pad_('Trễ', 5) +
           pad_('Vắng', 6) + pad_('Phép', 6) + pad_('Tỉ lệ', 8) +
           pad_('Tham dự', 9) + 'ĐG1.6');
  out.push(new Array(90).join('-'));

  r.rows.forEach(function (s) {
    out.push(pad_(s.mssv, 12) + pad_(s.fullName, 26) +
             pad_(String(s.present), 5) + pad_(String(s.late), 5) +
             pad_(String(s.absent), 6) + pad_(String(s.excused), 6) +
             pad_(s.rate + '%', 8) +
             pad_(String(s.attendanceScore), 9) +
             s.finalScore + (s.belowMin ? '   <-- DƯỚI NGƯỠNG' : ''));
  });

  if (r.belowMin.length) {
    out.push('');
    out.push('CẢNH BÁO — ' + r.belowMin.length + ' sinh viên dưới ngưỡng dự lớp ' +
             ATT_SCORE_CONFIG.minRate + '% của đề cương:');
    r.belowMin.forEach(function (s) {
      out.push('  ' + pad_(s.mssv, 12) + pad_(s.fullName, 26) +
               s.rate + '%  (vắng ' + s.absent + '/' + r.sessionCount + ' buổi)');
    });
    out.push('  Theo đề cương, dưới 85% là vi phạm điều kiện chuyên cần.');
  }

  out.push('');
  out.push(r.blocking.length
    ? 'CHƯA CHẠY ĐƯỢC:\n- ' + r.blocking.join('\n- ')
    : 'Sẵn sàng. Chạy runAttendanceScore() để ghi điểm vào 10_GRADES.');

  const msg = out.join('\n');
  Logger.log(msg);
  return msg;
}

/* ------------------------------------------------------------------ */
/*  GHI THẬT                                                           */
/* ------------------------------------------------------------------ */

function runAttendanceScore() {
  const r = analyzeAttendanceScore_();
  if (r.error) { Logger.log(r.error); return r.error; }
  if (r.blocking.length) {
    const m = 'DỪNG — còn vướng:\n- ' + r.blocking.join('\n- ');
    Logger.log(m); return m;
  }

  const colRepo = Repos.gradeCols();
  const gradeRepo = Repos.grades();

  // Đầu điểm chuyên cần: có rồi thì dùng lại, chưa có thì tạo
  let col = colRepo.findOne({ ClassID: r.classId, Name: ATT_SCORE_CONFIG.columnName });
  let colCreated = false;
  if (!col) {
    col = {
      GradeColumnID: newId('GCL'),
      ClassID: r.classId,
      Name: ATT_SCORE_CONFIG.columnName,
      Weight: ATT_SCORE_CONFIG.columnWeight,
      SortOrder: 99,
      Status: RECORD_STATUS.ACTIVE,
      CreatedAt: nowStamp()
    };
    colRepo.insert(col);
    colCreated = true;
  }

  let inserted = 0, updated = 0;
  r.rows.forEach(function (s) {
    const res = gradeRepo.upsert(
      { StudentID: s.studentId, ClassID: r.classId, GradeColumnID: col.GradeColumnID },
      {
        GradeID: newId('GRD'),
        StudentID: s.studentId,
        ClassID: r.classId,
        GradeColumnID: col.GradeColumnID,
        Score: s.finalScore,
        UpdatedBy: 'auto-chuyencan',
        UpdatedAt: nowStamp()
      }
    );
    if (res.action === 'INSERTED') inserted++; else updated++;
  });

  logAudit('system', ROLE.ADMIN, 'AUTO_ATTENDANCE_SCORE', 'CLASS', r.classId,
           { sessions: r.sessionCount, students: r.rows.length, belowMin: r.belowMin.length }, '');

  const msg = [
    '=== ĐÃ GHI ĐIỂM CHUYÊN CẦN ===',
    'Lớp                : ' + r.classCode,
    'Đầu điểm           : ' + ATT_SCORE_CONFIG.columnName + (colCreated ? ' (vừa tạo)' : ' (đã có)'),
    'Tính trên          : ' + r.sessionCount + ' buổi đã điểm danh',
    'Ghi mới            : ' + inserted,
    'Cập nhật           : ' + updated,
    'Dưới ngưỡng ' + ATT_SCORE_CONFIG.minRate + '%   : ' + r.belowMin.length + ' sinh viên',
    '',
    'LƯU Ý: điểm này mới tính tiêu chí "Tham dự và đúng giờ" (50% của ĐG1.6).',
    'Hai tiêu chí còn lại đang lấy điểm mặc định ' + ATT_SCORE_CONFIG.defaultOtherScore + '.',
    'Em nào cần trừ thì sửa tay trong 10_GRADES sau khi chạy hàm này.'
  ].join('\n');

  Logger.log(msg);
  return msg;
}

/* ------------------------------------------------------------------ */
/*  PHÂN TÍCH                                                          */
/* ------------------------------------------------------------------ */

function analyzeAttendanceScore_() {
  const blocking = [];
  const classCode = String(ATT_SCORE_CONFIG.classCode || '').trim();
  if (!classCode) {
    return { error: 'Chưa điền ATT_SCORE_CONFIG.classCode. Ví dụ: \'25KTE1\'.' };
  }

  const cls = Repos.classes().findOne({ ClassCode: classCode });
  if (!cls) {
    return { error: 'Không tìm thấy lớp "' + classCode + '" trong 03_CLASSES.' };
  }
  const classId = cls.ClassID;

  // Chỉ tính trên buổi ĐÃ điểm danh. Buổi chưa dạy mà tính vào thì
  // đầu học kỳ em nào cũng "vắng", điểm chuyên cần thành vô nghĩa.
  const sessionIds = {};
  Repos.sessions().findWhere({ ClassID: classId, Status: RECORD_STATUS.ACTIVE })
    .forEach(function (s) { sessionIds[String(s.SessionID).trim()] = true; });

  const attBySession = {};
  const byStudent = {};
  Repos.attendance().all().forEach(function (a) {
    const sid = String(a.SessionID).trim();
    if (!sessionIds[sid]) return;
    attBySession[sid] = true;
    const st = String(a.StudentID).trim();
    byStudent[st] = byStudent[st] || {};
    byStudent[st][sid] = a.Status;
  });

  const sessionCount = Object.keys(attBySession).length;
  if (sessionCount === 0) {
    blocking.push('Lớp này chưa có buổi nào được điểm danh, không có gì để tính.');
  }

  const studentById = {};
  Repos.students().all().forEach(function (s) {
    studentById[String(s.StudentID).trim()] = s;
  });

  const rows = [];
  Repos.enrollments().findWhere({ ClassID: classId, Status: RECORD_STATUS.ACTIVE })
    .forEach(function (en) {
      const sid = String(en.StudentID).trim();
      const st = studentById[sid];
      if (!st) return;

      const marks = byStudent[sid] || {};
      let present = 0, late = 0, absent = 0, excused = 0;

      Object.keys(attBySession).forEach(function (sesId) {
        const status = marks[sesId];
        if (status === ATTENDANCE_STATUS.PRESENT)      present++;
        else if (status === ATTENDANCE_STATUS.LATE)    late++;
        else if (status === ATTENDANCE_STATUS.EXCUSED) excused++;
        else                                           absent++;  // ABSENT hoặc không có dòng nào
      });

      // Vắng có phép không bị trừ; đi trễ tính một phần
      const credited = present + excused + late * ATT_SCORE_CONFIG.latePenalty;
      const rate = sessionCount ? Math.round(credited / sessionCount * 1000) / 10 : 0;

      const attendanceScore = rateToScore_(rate);
      const w = ATT_SCORE_CONFIG.attendanceCriterionWeight / 100;
      const finalScore = Math.round(
        (attendanceScore * w + ATT_SCORE_CONFIG.defaultOtherScore * (1 - w)) * 100
      ) / 100;

      rows.push({
        studentId: sid, mssv: st.MSSV, fullName: st.FullName,
        present: present, late: late, absent: absent, excused: excused,
        rate: rate, attendanceScore: attendanceScore, finalScore: finalScore,
        belowMin: rate < ATT_SCORE_CONFIG.minRate
      });
    });

  if (!rows.length) blocking.push('Lớp này chưa có sinh viên ghi danh nào.');

  rows.sort(function (a, b) { return a.rate - b.rate; });  // thấp nhất lên đầu

  return {
    classCode: classCode, classId: classId,
    sessionCount: sessionCount,
    rows: rows,
    belowMin: rows.filter(function (s) { return s.belowMin; }),
    blocking: blocking
  };
}

/**
 * Quy tỉ lệ tham dự sang điểm 10 theo đúng bốn mức của rubric ĐG1.6,
 * nội suy tuyến tính bên trong mỗi mức.
 */
function rateToScore_(rate) {
  let s;
  if (rate >= 95)      s = 9.0 + (Math.min(rate, 100) - 95) / 5 * 1.0;   // 9,0 – 10
  else if (rate >= 90) s = 7.0 + (rate - 90) / 5 * 1.9;                  // 7,0 – 8,9
  else if (rate >= 85) s = 5.0 + (rate - 85) / 5 * 1.9;                  // 5,0 – 6,9
  else                 s = Math.max(0, rate) / 85 * 4.9;                 // 0 – 4,9
  return Math.round(s * 100) / 100;
}
