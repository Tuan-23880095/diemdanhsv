/**
 * 03-Auth.gs — AuthService
 *
 * RÀNG BUỘC KIẾN TRÚC (thiết kế D.5): mọi kiểm tra danh tính đi qua đây.
 * Controller KHÔNG được tự so sánh MSSV hay mật khẩu.
 * Khi nâng lên Mức 3 (Google Login) chỉ thay THÂN của identifyStudent(),
 * luồng điểm danh ở 04-AttendanceService.gs không phải sửa một dòng nào.
 *
 * Mức đang dùng: Mức 1 — MSSV + mã 4 ký tự.
 * Mức 1 KHÔNG chứng minh được người gửi đúng là chủ MSSV. Sức mạnh của hệ
 * thống nằm ở sáu lớp bù D.8, không nằm ở đây.
 */

const AuthService = {

  /* ---------------- SINH VIÊN (Mức 1) ---------------- */

  /**
   * Nhận diện sinh viên và kiểm tra ghi danh (D.8 lớp 2).
   * @return {{ok:boolean, reason?:string, student?:Object}}
   */
  identifyStudent: function (mssv, classId) {
    const clean = String(mssv || '').trim().toUpperCase();

    if (!/^[0-9]{6,10}$/.test(clean)) {
      return { ok: false, reason: 'MSSV không hợp lệ (phải là 6–10 chữ số).' };
    }

    const student = Repos.students().findOne({ MSSV: clean, Status: RECORD_STATUS.ACTIVE });
    if (!student) {
      return { ok: false, reason: 'Không tìm thấy MSSV ' + clean + ' trong hệ thống.' };
    }

    // D.8 lớp 2 — MSSV phải có trong danh sách lớp đang mở điểm danh.
    // Không có bước này thì MSSV bất kỳ của trường đều ghi được một dòng rác.
    const enrolled = Repos.enrollments().findOne({
      StudentID: student.StudentID,
      ClassID: classId,
      Status: RECORD_STATUS.ACTIVE
    });
    if (!enrolled) {
      return { ok: false, reason: 'MSSV ' + clean + ' không có trong danh sách lớp này.' };
    }

    return { ok: true, student: student };
  },

  /* ---------------- GIẢNG VIÊN / ADMIN ---------------- */

  /**
   * Đăng nhập giảng viên. Trả token phiên, lưu trong CacheService.
   * Mật khẩu KHÔNG lưu dạng thô — đây là nợ kỹ thuật số 4 của bản XemDiem cũ.
   */
  login: function (username, password) {
    const user = Repos.users().findOne({
      Username: String(username || '').trim(),
      Status: RECORD_STATUS.ACTIVE
    });
    // Trả cùng một thông báo cho "sai tên" và "sai mật khẩu",
    // để không tiết lộ tài khoản nào tồn tại.
    const fail = { ok: false, reason: 'Sai tên đăng nhập hoặc mật khẩu.' };
    if (!user) return fail;

    const hash = hashPassword_(password, user.Salt);
    if (hash !== String(user.PasswordHash)) return fail;

    const token = Utilities.getUuid();
    CacheService.getScriptCache().put(
      'tok_' + token,
      JSON.stringify({ userId: user.UserID, role: user.Role, name: user.FullName }),
      21600 // 6 giờ — hết hạn thì đăng nhập lại
    );

    logAudit(user.UserID, user.Role, 'LOGIN', 'USER', user.UserID, {}, '');
    return { ok: true, token: token, role: user.Role, fullName: user.FullName };
  },

  /** Xác thực token. Ném lỗi nếu không hợp lệ hoặc sai vai trò. */
  requireRole: function (token, roles) {
    const raw = CacheService.getScriptCache().get('tok_' + String(token || ''));
    if (!raw) throw new Error('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.');

    const session = JSON.parse(raw);
    if (roles && roles.indexOf(session.role) === -1) {
      throw new Error('Tài khoản không có quyền thực hiện thao tác này.');
    }
    return session;
  },

  logout: function (token) {
    CacheService.getScriptCache().remove('tok_' + String(token || ''));
    return { ok: true };
  },

  /**
   * Kiểm tra quyền thao tác trên một lớp cụ thể (thiết kế D.9 — nhiều
   * giảng viên). ADMIN thao tác được mọi lớp. LECTURER chỉ thao tác được
   * lớp mình đứng tên LecturerID trong 03_CLASSES.
   * Gọi ngay sau requireRole(), TRƯỚC khi đọc/ghi bất cứ gì của lớp đó.
   * Ném lỗi rõ ràng thay vì âm thầm trả danh sách rỗng.
   */
  assertClassAccess: function (me, classId) {
    if (me.role === ROLE.ADMIN) return;

    const cls = Repos.classes().findOne({ ClassID: classId });
    if (!cls || String(cls.LecturerID).trim() !== String(me.userId).trim()) {
      throw new Error('Bạn không có quyền thao tác trên lớp này.');
    }
  }
};

/* ------------------------------------------------------------------ */

/** SHA-256 với salt riêng cho từng tài khoản */
function hashPassword_(plain, salt) {
  const bytes = Utilities.computeDigest(
    Utilities.DigestAlgorithm.SHA_256,
    String(salt) + '|' + String(plain),
    Utilities.Charset.UTF_8
  );
  return bytes.map(function (b) {
    return ('0' + (b & 0xFF).toString(16)).slice(-2);
  }).join('');
}

/**
 * Tạo MỘT HOẶC NHIỀU tài khoản giảng viên / admin cùng lúc (thiết kế D.9).
 * CHẠY TAY TỪ TRÌNH SOẠN THẢO Apps Script — không mở qua API.
 *
 * CÁCH DÙNG
 *   1. Điền mỗi giảng viên một dòng vào ACCOUNTS_TO_CREATE bên dưới.
 *   2. Chạy createLecturerAccounts().
 *   3. Đọc log — mỗi dòng có UserID vừa tạo. GHI LẠI để điền đúng vào cột
 *      LecturerID của 03_CLASSES cho lớp người đó dạy.
 *   4. XOÁ SẠCH nội dung mảng, đưa về [] — mật khẩu thô không được ở lại
 *      trong mã nguồn, kể cả khi sắp đưa file lên GitHub hay không.
 *
 * Chạy lại an toàn: username đã tồn tại thì bỏ qua, không tạo trùng.
 *
 * KHÔNG lưu mật khẩu thô xuống file trên đĩa: điền thẳng trong trình soạn
 * thảo Apps Script, chạy, xoá ngay, và đừng chép nội dung đó về máy. Repo
 * GitHub của dự án là public — lỡ commit một lần là mật khẩu nằm trong
 * lịch sử git vĩnh viễn, xoá sau cũng không sạch.
 */
const ACCOUNTS_TO_CREATE = [
  // Điền tạm, chạy hàm, rồi XOÁ NGAY về [] như hiện tại. Mẫu:
  // { username: 'giangvien2', fullName: 'Tên giảng viên 2', email: 'email2@...',
  //   password: 'MAT_KHAU_THAT', role: ROLE.LECTURER },
];

function createLecturerAccounts() {
  if (!ACCOUNTS_TO_CREATE.length) {
    const m = 'ACCOUNTS_TO_CREATE đang rỗng — điền danh sách giảng viên rồi chạy lại.';
    Logger.log(m);
    return m;
  }

  const repo = Repos.users();
  const out = [];

  ACCOUNTS_TO_CREATE.forEach(function (acc) {
    if (repo.findOne({ Username: acc.username })) {
      out.push('BỎ QUA — tài khoản "' + acc.username + '" đã tồn tại.');
      return;
    }

    const salt = Utilities.getUuid();
    const user = {
      UserID: newId('USR'),
      Username: acc.username,
      FullName: acc.fullName,
      Email: acc.email,
      PasswordHash: hashPassword_(acc.password, salt),
      Salt: salt,
      Role: acc.role || ROLE.LECTURER,
      Status: RECORD_STATUS.ACTIVE,
      CreatedAt: nowStamp()
    };
    repo.insert(user);
    out.push('Đã tạo ' + acc.username + ' (' + user.UserID + ') — dán UserID này vào ' +
              'cột LecturerID của 03_CLASSES cho đúng lớp người này dạy.');
  });

  const msg = out.join('\n') +
              '\n\nXOÁ SẠCH nội dung mảng ACCOUNTS_TO_CREATE (đưa về []) NGAY BÂY GIỜ.';
  Logger.log(msg);
  return msg;
}
