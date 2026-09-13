/* js/controllers/LecturerController.js */

class LecturerController {

  constructor(api, view) {
    this.api = api;
    this.view = view;
    this.token = null;
    this.sessionId = null;
    this.timer = null;
  }

  init() {
    this.view.onLogin((u, p) => this.login(u, p));
    this.view.onLogout(() => this.logout());
    this.view.onClassChange(id => this.loadSessions(id));
    this.view.onSessionChange(id => this.selectSession(id));
    this.view.onOpen(id => this.openAttendance(id));
    this.view.onClose(id => this.closeAttendance(id));

    // Token giữ trong bộ nhớ phiên, không để lại trên máy dùng chung
    const saved = sessionStorage.getItem('dd_token');
    const name  = sessionStorage.getItem('dd_name');
    if (saved) { this.token = saved; this.view.enterDashboard(name || ''); this.loadClasses(); }
  }

  async login(username, password) {
    this.view.showLoginError('');
    try {
      const data = await this.api.login(username, password);
      this.token = data.token;
      sessionStorage.setItem('dd_token', data.token);
      sessionStorage.setItem('dd_name', data.fullName);
      this.view.enterDashboard(data.fullName);
      await this.loadClasses();
    } catch (err) {
      this.view.showLoginError(err.message);
    }
  }

  logout() {
    this.stopPolling();
    if (this.token) this.api.logout(this.token).catch(() => { /* đăng xuất phía máy chủ là best-effort */ });
    this.token = null;
    sessionStorage.removeItem('dd_token');
    sessionStorage.removeItem('dd_name');
    this.view.exitDashboard();
  }

  async loadClasses() {
    try {
      const rows = await this.api.listClasses(this.token);
      this.view.fillClasses(rows.map(r => new AcademicClass(r)));
    } catch (err) {
      this.view.toast(err.message, 'error');
      if (/hết hạn/.test(err.message)) this.logout();
    }
  }

  async loadSessions(classId) {
    this.stopPolling();
    this.view.hideCode();
    if (!classId) return this.view.fillSessions([]);
    try {
      const rows = await this.api.listSessions(this.token, classId);
      this.view.fillSessions(rows.map(r => new ClassSession(r)));
    } catch (err) {
      this.view.toast(err.message, 'error');
    }
  }

  selectSession(sessionId) {
    this.sessionId = sessionId || null;
    this.view.setSessionControls(!!sessionId);
    this.view.hideCode();
    if (sessionId) this.startPolling(); else this.stopPolling();
  }

  async openAttendance(sessionId) {
    try {
      const info = await this.api.openAttendance(this.token, sessionId, {});
      this.view.showCode(info);
      this.view.toast('Đã mở điểm danh. Mã: ' + info.code);
      this.refresh();
    } catch (err) {
      this.view.toast(err.message, 'error');
    }
  }

  async closeAttendance(sessionId) {
    try {
      const res = await this.api.closeAttendance(this.token, sessionId);
      this.view.hideCode();
      this.view.toast('Đã đóng điểm danh. Đánh dấu vắng ' + res.markedAbsent + ' sinh viên.');
      this.refresh();
    } catch (err) {
      this.view.toast(err.message, 'error');
    }
  }

  /**
   * D.8-5: danh sách phải TỰ làm mới. Giảng viên không bấm nút mỗi lần
   * một sinh viên gửi — màn hình phải luôn khớp với lớp đang ngồi trước mặt.
   */
  startPolling() {
    this.stopPolling();
    this.refresh();
    this.timer = setInterval(() => this.refresh(), CONFIG.ROSTER_POLL_MS);
  }

  stopPolling() {
    if (this.timer) { clearInterval(this.timer); this.timer = null; }
  }

  async refresh() {
    if (!this.sessionId || !this.token) return;
    try {
      this.view.renderRoster(await this.api.liveRoster(this.token, this.sessionId));
    } catch (err) {
      if (/hết hạn/.test(err.message)) { this.view.toast(err.message, 'error'); this.logout(); }
    }
  }
}
