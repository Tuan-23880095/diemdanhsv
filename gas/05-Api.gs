/**
 * 05-Api.gs — Điểm vào duy nhất của Web App: doGet / doPost + router
 *
 * Mọi phản hồi dùng CHUNG một phong bì:
 *   { status: 'success' | 'error', message: string, data: any }
 *
 * QUAN TRỌNG CHO FRONTEND — cách gọi POST cho đúng.
 * URL Web App nằm ở CONFIG.API_URL (js/config/config.js), được truyền vào
 * APIService qua this.baseUrl — KHÔNG có biến tên SCRIPT_URL ở đâu cả,
 * dưới đây chỉ là ví dụ minh hoạ:
 *
 *   fetch(CONFIG.API_URL, {
 *     method: 'POST',
 *     headers: { 'Content-Type': 'text/plain;charset=utf-8' },  // tránh preflight
 *     body: JSON.stringify({ action: 'checkin', ... })
 *   }).then(r => r.json())
 *
 * KHÔNG dùng mode:'no-cors'. Đó là nợ kỹ thuật số 2 của bản cũ: với no-cors
 * trình duyệt luôn trả response rỗng, nên trang báo "Thành công" kể cả khi
 * server lỗi. Dùng 'text/plain' thì trình duyệt không gửi preflight OPTIONS
 * (Apps Script không trả lời được OPTIONS), và vẫn đọc được JSON trả về.
 */

// Đổi mỗi khi sửa code backend, rồi kiểm lại qua <API_URL>?action=ping —
// đây là cách DUY NHẤT biết chắc bản deploy đang chạy đã có code mới.
const API_VERSION = '2.4-fixquyen';

/** Router GET — chỉ dành cho thao tác ĐỌC */
function doGet(e) {
  try {
    const p = (e && e.parameter) || {};
    const action = String(p.action || '').trim();

    switch (action) {

      case 'ping':
        return ok({ time: nowStamp(), version: API_VERSION });

      // Sinh viên xem lịch sử điểm danh của mình
      case 'studentHistory':
        return ok(AttendanceService.studentHistory(p.mssv, p.classId));

      // Sinh viên xem điểm. KHÔNG nhận MSSV ở đây — danh tính lấy từ token
      // đã xác minh qua email, nên không truyền MSSV bạn khác vào xem trộm được.
      case 'myGrades':
        return ok(GradeService.myGrades(p.token));

      // Giảng viên: danh sách check-in thời gian thực (D.8 lớp 5)
      case 'liveRoster':
        return ok(AttendanceService.liveRoster(p.token, p.sessionId));

      // [D.9] LECTURER chỉ thấy lớp mình đứng tên LecturerID. ADMIN thấy hết.
      case 'listClasses': {
        const me = AuthService.requireRole(p.token, [ROLE.LECTURER, ROLE.ADMIN]);
        let classes = Repos.classes().findWhere({ Status: RECORD_STATUS.ACTIVE });
        if (me.role === ROLE.LECTURER) {
          classes = classes.filter(function (c) { return classHasLecturer_(c, me.userId); });
        }
        return ok(classes.map(stripRow_));
      }

      case 'listSessions': {
        const me = AuthService.requireRole(p.token, [ROLE.LECTURER, ROLE.ADMIN]);
        AuthService.assertClassAccess(me, p.classId);
        return ok(Repos.sessions()
                       .findWhere({ ClassID: p.classId })
                       .filter(isActiveRow_)
                       .sort(function (a, b) { return Number(a.SessionNo) - Number(b.SessionNo); })
                       .map(stripRow_));
      }

      case '':
        return fail('Thiếu tham số action.');

      default:
        return fail('Action không hợp lệ: ' + action);
    }
  } catch (err) {
    return fail(err.message);
  }
}

/** Router POST — mọi thao tác GHI. Mật khẩu không bao giờ đi qua GET. */
function doPost(e) {
  try {
    const body = parseBody_(e);
    const action = String(body.action || '').trim();

    switch (action) {

      case 'login': {
        const res = AuthService.login(body.username, body.password);
        if (!res.ok) return fail(res.reason);
        return ok({ token: res.token, role: res.role, fullName: res.fullName });
      }

      case 'logout':
        return ok(AuthService.logout(body.token));

      // Sinh viên xin mã xem điểm — hệ thống gửi mã tới email đã lưu sẵn
      case 'requestGradeCode': {
        const r = GradeAuth.requestCode(body.mssv);
        return r.ok ? ok({ message: r.message }) : fail(r.message);
      }

      // Đối chiếu mã, cấp token xem điểm
      case 'verifyGradeCode': {
        const r = GradeAuth.verifyCode(body.mssv, body.code);
        if (!r.ok) return fail(r.reason);
        return ok({ token: r.token, mssv: r.mssv, fullName: r.fullName });
      }

      case 'openAttendance':
        return ok(AttendanceService.open(body.token, body.sessionId, {
          presentMinutes: body.presentMinutes,
          windowMinutes: body.windowMinutes
        }));

      case 'closeAttendance':
        return ok(AttendanceService.close(body.token, body.sessionId));

      // Sinh viên gửi điểm danh — không cần token (xác minh Mức 1)
      case 'checkin':
        return ok(AttendanceService.checkin({
          mssv: body.mssv,
          code: body.code,
          lat: body.lat,
          lng: body.lng,
          accuracy: body.accuracy,
          ip: body.ip,
          os: body.os,
          browser: body.browser,
          deviceType: body.deviceType,
          deviceHash: body.deviceHash
        }));

      case '':
        return fail('Thiếu tham số action.');

      default:
        return fail('Action không hợp lệ: ' + action);
    }
  } catch (err) {
    return fail(err.message);
  }
}

/* ------------------------------------------------------------------ */

/**
 * Đọc body cho cả hai kiểu gửi:
 *  - JSON thô (text/plain) — kiểu khuyến nghị
 *  - form-urlencoded / FormData — để tương thích trang cũ
 */
function parseBody_(e) {
  if (e && e.postData && e.postData.contents) {
    const raw = String(e.postData.contents).trim();
    if (raw.charAt(0) === '{') {
      try { return JSON.parse(raw); } catch (err) { /* rơi xuống dưới */ }
    }
  }
  return (e && e.parameter) || {};
}

function ok(data) {
  return json_({ status: 'success', message: '', data: data === undefined ? null : data });
}

function fail(message) {
  return json_({ status: 'error', message: String(message || 'Lỗi không xác định.'), data: null });
}

function json_(obj) {
  return ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}

/** Bỏ trường nội bộ _row trước khi trả ra ngoài */
function stripRow_(r) {
  const out = {};
  Object.keys(r).forEach(function (k) { if (k !== '_row') out[k] = r[k]; });
  return out;
}
