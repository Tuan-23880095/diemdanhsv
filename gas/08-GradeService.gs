/**
 * 08-GradeService.gs — Xem điểm cho sinh viên, xác minh bằng mã gửi qua email
 *
 * CƠ CHẾ XÁC MINH (chốt 13/09/2026)
 *   Sinh viên nhập MSSV -> hệ thống gửi mã 4 ký tự tới ĐÚNG email đã lưu
 *   trong 04_STUDENTS -> nhập đúng mã mới xem được điểm.
 *
 * Vì sao không dùng riêng MSSV như trang điểm danh: MSSV trong trường không
 * phải bí mật (dán bảng, in danh sách lớp, bạn cùng lớp đều biết), mà điểm
 * số nhạy cảm hơn lịch sử điểm danh nhiều. Bước email chứng minh người xin
 * xem thật sự sở hữu hộp thư của sinh viên đó.
 *
 * Email LUÔN lấy từ 04_STUDENTS, KHÔNG bao giờ lấy từ dữ liệu người dùng gửi
 * lên. Cho người dùng tự khai email nhận mã thì bước xác minh thành vô nghĩa.
 *
 * HẠN MỨC GỬI MAIL — ràng buộc thật, không phải chi tiết nhỏ:
 * tài khoản Gmail thường chỉ gửi được 100 người nhận mỗi 24 giờ. Lớp 50 em,
 * nếu mỗi lần bấm là một email thì hết quota rất nhanh và những em xin sau
 * không nhận được gì. Vì vậy:
 *   - mã còn hạn thì DÙNG LẠI, không gửi email mới;
 *   - mỗi MSSV tối đa MAX_SENDS_PER_DAY lần gửi trong 24 giờ;
 *   - xác minh xong cấp token sống TOKEN_TTL_SECONDS để xem nhiều lần
 *     mà không phải xin mã lại.
 */

const GRADE_AUTH = {
  CODE_TTL_SECONDS:   600,   // mã sống 10 phút
  TOKEN_TTL_SECONDS: 1800,   // đăng nhập xem điểm sống 30 phút
  MAX_ATTEMPTS:         5,   // sai quá số này thì mã bị huỷ
  MAX_SENDS_PER_DAY:    5,   // số lần xin mã tối đa cho một MSSV trong 24h
  RESEND_COOLDOWN_SEC: 60    // trong khoảng này thì dùng lại mã cũ
};

const GradeAuth = {

  /**
   * Xin mã xác minh. Trả về THÔNG BÁO TRUNG LẬP trong mọi trường hợp —
   * không tiết lộ MSSV có tồn tại hay không, để người ngoài không dò được
   * danh sách sinh viên của trường.
   */
  requestCode: function (mssv) {
    const clean = String(mssv || '').trim();
    const neutral = {
      ok: true,
      message: 'Nếu mã số sinh viên đúng, mã xác minh đã được gửi tới email ' +
               'của bạn. Kiểm tra cả hộp thư rác. Mã có hiệu lực 10 phút.'
    };

    if (!/^[0-9]{6,10}$/.test(clean)) return neutral;

    const student = Repos.students().findOne({
      MSSV: clean, Status: RECORD_STATUS.ACTIVE
    });
    if (!student) return neutral;

    const email = String(student.Email || '').trim();
    if (!email || email.indexOf('@') === -1) return neutral;

    const cache = CacheService.getScriptCache();

    // Mã cũ còn hạn và vừa gửi xong -> không gửi lại, tiết kiệm quota
    const existing = cache.get('gcode_' + clean);
    const lastSent = cache.get('gsent_' + clean);
    if (existing && lastSent) return neutral;

    // Chặn xin mã liên tục làm ngập hộp thư sinh viên và cạn quota
    const sentToday = Number(cache.get('gcount_' + clean) || 0);
    if (sentToday >= GRADE_AUTH.MAX_SENDS_PER_DAY) {
      return {
        ok: false,
        message: 'Bạn đã xin mã quá nhiều lần hôm nay. Vui lòng thử lại sau ' +
                 'hoặc liên hệ giảng viên.'
      };
    }

    // Hết quota gửi mail của hệ thống -> nói thật, đừng để sinh viên chờ vô ích
    if (MailApp.getRemainingDailyQuota() < 1) {
      return {
        ok: false,
        message: 'Hệ thống đã hết lượt gửi email trong hôm nay. ' +
                 'Vui lòng thử lại sau hoặc liên hệ giảng viên.'
      };
    }

    const code = generateGradeCode_();

    cache.put('gcode_'  + clean, code, GRADE_AUTH.CODE_TTL_SECONDS);
    cache.put('gtry_'   + clean, '0',  GRADE_AUTH.CODE_TTL_SECONDS);
    cache.put('gsent_'  + clean, '1',  GRADE_AUTH.RESEND_COOLDOWN_SEC);
    cache.put('gcount_' + clean, String(sentToday + 1), 86400);

    MailApp.sendEmail({
      to: email,
      subject: 'Mã xác minh xem điểm — ' + CONFIG_SITE_NAME_,
      body: [
        'Chào ' + student.FullName + ',',
        '',
        'Mã xác minh để xem điểm của bạn là:  ' + code,
        '',
        'Mã có hiệu lực trong 10 phút và chỉ dùng được một lần.',
        '',
        'Nếu bạn không yêu cầu xem điểm, hãy bỏ qua email này — ' +
        'không ai xem được điểm của bạn nếu không có mã trên.'
      ].join('\n')
    });

    logAudit(student.StudentID, ROLE.STUDENT, 'GRADE_CODE_SENT', 'STUDENT',
             student.StudentID, { mssv: clean }, '');

    return neutral;
  },

  /**
   * Đối chiếu mã. Đúng thì cấp token xem điểm.
   * Sai quá MAX_ATTEMPTS lần thì huỷ mã, buộc xin mã mới — chặn dò mã.
   */
  verifyCode: function (mssv, code) {
    const clean    = String(mssv || '').trim();
    const cleanCode = String(code || '').trim().toUpperCase();
    const cache    = CacheService.getScriptCache();

    const saved = cache.get('gcode_' + clean);
    if (!saved) {
      return { ok: false, reason: 'Mã đã hết hạn hoặc chưa được gửi. Hãy xin mã mới.' };
    }

    const tries = Number(cache.get('gtry_' + clean) || 0);
    if (tries >= GRADE_AUTH.MAX_ATTEMPTS) {
      cache.remove('gcode_' + clean);
      return { ok: false, reason: 'Nhập sai quá nhiều lần. Mã đã bị huỷ, hãy xin mã mới.' };
    }

    if (cleanCode !== saved) {
      cache.put('gtry_' + clean, String(tries + 1), GRADE_AUTH.CODE_TTL_SECONDS);
      return {
        ok: false,
        reason: 'Mã không đúng. Còn ' + (GRADE_AUTH.MAX_ATTEMPTS - tries - 1) + ' lần thử.'
      };
    }

    const student = Repos.students().findOne({ MSSV: clean, Status: RECORD_STATUS.ACTIVE });
    if (!student) return { ok: false, reason: 'Không tìm thấy sinh viên.' };

    // Mã dùng một lần
    cache.remove('gcode_' + clean);
    cache.remove('gtry_'  + clean);

    const token = Utilities.getUuid();
    cache.put('gtok_' + token,
              JSON.stringify({ studentId: student.StudentID, mssv: student.MSSV }),
              GRADE_AUTH.TOKEN_TTL_SECONDS);

    logAudit(student.StudentID, ROLE.STUDENT, 'GRADE_LOGIN', 'STUDENT',
             student.StudentID, {}, '');

    return { ok: true, token: token, mssv: student.MSSV, fullName: student.FullName };
  },

  /** Đổi token lấy danh tính sinh viên. Ném lỗi nếu hết hạn. */
  requireStudent: function (token) {
    const raw = CacheService.getScriptCache().get('gtok_' + String(token || ''));
    if (!raw) throw new Error('Phiên xem điểm đã hết hạn. Vui lòng xin mã mới.');
    return JSON.parse(raw);
  }
};

/* ------------------------------------------------------------------ */

const GradeService = {

  /**
   * Bảng điểm của chính sinh viên đang đăng nhập, cho MỌI lớp em đó ghi danh.
   * Không nhận MSSV từ bên ngoài — danh tính lấy từ token, nên không thể
   * truyền MSSV của bạn khác vào để xem trộm.
   */
  myGrades: function (token) {
    const me = GradeAuth.requireStudent(token);

    const classById = {};
    Repos.classes().all().forEach(function (c) {
      classById[String(c.ClassID).trim()] = c;
    });

    const courseById = {};
    Repos.courses().all().forEach(function (c) {
      courseById[String(c.CourseID).trim()] = c;
    });

    // Điểm của riêng sinh viên này
    const myScores = {};
    Repos.grades().findWhere({ StudentID: me.studentId }).forEach(function (g) {
      myScores[String(g.ClassID).trim() + '|' + String(g.GradeColumnID).trim()] = g.Score;
    });

    const enrolled = Repos.enrollments().findWhere({
      StudentID: me.studentId, Status: RECORD_STATUS.ACTIVE
    });

    const classes = enrolled.map(function (en) {
      const classId = String(en.ClassID).trim();
      const cls = classById[classId];
      if (!cls) return null;

      const course = courseById[String(cls.CourseID).trim()];

      const cols = Repos.gradeCols()
        .findWhere({ ClassID: classId, Status: RECORD_STATUS.ACTIVE })
        .sort(function (a, b) { return Number(a.SortOrder || 0) - Number(b.SortOrder || 0); });

      let weighted = 0, weightDone = 0, weightTotal = 0;

      const rows = cols.map(function (col) {
        const w = Number(col.Weight || 0);
        weightTotal += w;

        const raw = myScores[classId + '|' + String(col.GradeColumnID).trim()];
        const has = raw !== undefined && raw !== null && String(raw).trim() !== '';
        const score = has ? Number(raw) : null;

        if (has && !isNaN(score)) { weighted += score * w; weightDone += w; }

        return {
          name:   col.Name,
          weight: w,
          score:  has && !isNaN(score) ? score : null
        };
      });

      return {
        classCode:  cls.ClassCode,
        courseName: course ? course.CourseName : '',
        courseCode: course ? course.CourseCode : '',
        columns:    rows,
        // Trung bình tính trên phần ĐÃ có điểm, kèm tỉ lệ đã chấm để
        // sinh viên biết đây là điểm tạm hay điểm cuối cùng
        average:     weightDone > 0 ? Math.round(weighted / weightDone * 100) / 100 : null,
        weightDone:  weightDone,
        weightTotal: weightTotal
      };
    }).filter(function (x) { return x !== null; });

    return { mssv: me.mssv, classes: classes };
  }
};

/* ------------------------------------------------------------------ */

/** Tên hệ thống dùng trong tiêu đề email */
const CONFIG_SITE_NAME_ = 'Hệ thống điểm danh & xem điểm';

/** Mã 4 ký tự, cùng bộ ký tự dễ đọc với mã điểm danh (bỏ 0/O, 1/I/L...) */
function generateGradeCode_() {
  const abc = CONFIG.CODE_ALPHABET;
  let code = '';
  for (let i = 0; i < CONFIG.CODE_LENGTH; i++) {
    code += abc.charAt(Math.floor(Math.random() * abc.length));
  }
  return code;
}
