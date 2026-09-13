/* js/services/APIService.js — Lớp duy nhất được phép gọi fetch */

class APIService {

  constructor(baseUrl) {
    this.baseUrl = baseUrl;
  }

  /**
   * Bóc phong bì { status, message, data } của backend.
   * Ném Error khi status === 'error' để controller bắt một chỗ.
   */
  _unwrap(json) {
    if (!json || typeof json !== 'object') {
      throw new Error('Máy chủ trả về dữ liệu không đọc được.');
    }
    if (json.status === 'error') throw new Error(json.message);
    return json.data;
  }

  async get(action, params = {}) {
    const url = new URL(this.baseUrl);
    url.searchParams.set('action', action);
    Object.keys(params).forEach(k => {
      if (params[k] !== undefined && params[k] !== null) url.searchParams.set(k, params[k]);
    });

    const res = await fetch(url.toString(), { method: 'GET', redirect: 'follow' });
    if (!res.ok) throw new Error('Không kết nối được máy chủ (HTTP ' + res.status + ').');
    return this._unwrap(await res.json());
  }

  /**
   * QUAN TRỌNG — hai điều không được đổi:
   *
   * 1. Content-Type là 'text/plain', KHÔNG phải 'application/json'.
   *    Apps Script không trả lời được request preflight OPTIONS;
   *    'text/plain' là kiểu không kích hoạt preflight.
   *
   * 2. KHÔNG dùng mode:'no-cors'. Đó là nợ kỹ thuật số 2 của bản cũ —
   *    với no-cors trình duyệt luôn trả response rỗng, nên trang báo
   *    "Thành công" kể cả khi máy chủ lỗi. Phải đọc được phản hồi thật.
   */
  async post(action, payload = {}) {
    const res = await fetch(this.baseUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'text/plain;charset=utf-8' },
      redirect: 'follow',
      body: JSON.stringify(Object.assign({ action }, payload))
    });
    if (!res.ok) throw new Error('Không kết nối được máy chủ (HTTP ' + res.status + ').');
    return this._unwrap(await res.json());
  }

  /* ---- Các lời gọi cụ thể ---- */

  ping()                              { return this.get('ping'); }
  studentHistory(mssv, classId)       { return this.get('studentHistory', { mssv, classId }); }
  liveRoster(token, sessionId)        { return this.get('liveRoster', { token, sessionId }); }
  listClasses(token)                  { return this.get('listClasses', { token }); }
  listSessions(token, classId)        { return this.get('listSessions', { token, classId }); }

  login(username, password)           { return this.post('login', { username, password }); }
  logout(token)                       { return this.post('logout', { token }); }
  openAttendance(token, sessionId, o) { return this.post('openAttendance', Object.assign({ token, sessionId }, o || {})); }
  closeAttendance(token, sessionId)   { return this.post('closeAttendance', { token, sessionId }); }
  checkin(payload)                    { return this.post('checkin', payload); }

  /* ---- Xem điểm: xác minh hai bước qua email ----
     myGrades KHÔNG nhận mssv. Danh tính nằm trong token đã xác minh, nên
     không thể truyền MSSV của bạn khác vào để xem trộm điểm. */
  requestGradeCode(mssv)              { return this.post('requestGradeCode', { mssv }); }
  verifyGradeCode(mssv, code)         { return this.post('verifyGradeCode', { mssv, code }); }
  myGrades(token)                     { return this.get('myGrades', { token }); }
}
