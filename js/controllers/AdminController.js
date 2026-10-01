/* js/controllers/AdminController.js — Trang quản trị (GĐ7): nối AdminView với APIService */

class AdminController {

  constructor(api, view) {
    this.api = api;
    this.view = view;
    this.token = null;
    this.role = null;
    this.classes = [];
    this.courses = [];
    this.sessions = [];
    this.rosterClassId = '';
    this.sessionClassId = '';
  }

  init() {
    const v = this.view;
    v.onLogin((u, p) => this.login(u, p));
    v.onLogout(() => this.logout());
    v.onTab(name => this.onTab(name));

    v.onClassNew(() => v.showClassForm(null));
    v.onClassCancel(() => v.hideClassForm());
    v.onClassEdit(id => v.showClassForm(this.classes.find(c => c.ClassID === id)));
    v.onClassSubmit(d => this.saveClass(d));

    v.onRosterClassChange(id => this.loadRoster(id));
    v.onEnrollSubmit(d => this.enroll(d));
    v.onUnenroll(mssv => this.unenroll(mssv));
    v.onCsvFile(csv => this.importRoster(csv, true));
    v.onCsvPreview(csv => this.importRoster(csv, true));
    v.onCsvImport(csv => this.importRoster(csv, false));

    v.onSessionClassChange(id => this.loadSessions(id));
    v.onSessionNew(() => v.showSessionForm(null, this.sessions.length + 1));
    v.onSessionCancel(() => v.hideSessionForm());
    v.onSessionEdit(id => v.showSessionForm(this.sessions.find(s => s.SessionID === id)));
    v.onSessionSubmit(d => this.saveSession(d));

    v.onCourseNew(() => v.showCourseForm(null));
    v.onCourseCancel(() => v.hideCourseForm());
    v.onCourseEdit(id => v.showCourseForm(this.courses.find(c => c.CourseID === id)));
    v.onCourseSubmit(d => this.saveCourse(d));

    // Cùng khoá sessionStorage với trang điểm danh → đăng nhập một lần dùng cả hai trang
    const saved = sessionStorage.getItem('dd_token');
    if (saved) {
      this.token = saved;
      this.role = sessionStorage.getItem('dd_role') || 'LECTURER';
      v.enterDashboard(sessionStorage.getItem('dd_name') || '', this.role);
      this.loadAll();
    }
  }

  /* ---- Phiên ---- */
  async login(username, password) {
    this.view.showLoginError('');
    try {
      const data = await this.api.login(username, password);
      this.token = data.token;
      this.role = data.role;
      sessionStorage.setItem('dd_token', data.token);
      sessionStorage.setItem('dd_name', data.fullName);
      sessionStorage.setItem('dd_role', data.role);
      this.view.enterDashboard(data.fullName, data.role);
      await this.loadAll();
    } catch (err) {
      this.view.showLoginError(err.message);
    }
  }

  logout() {
    if (this.token) this.api.logout(this.token).catch(() => {});
    this.token = null;
    ['dd_token', 'dd_name', 'dd_role'].forEach(k => sessionStorage.removeItem(k));
    this.view.exitDashboard();
  }

  /** Mọi lỗi API đi qua đây: hết hạn phiên → đăng xuất, còn lại → toast. */
  fail(err) {
    this.view.toast(err.message, 'error');
    if (/hết hạn/.test(err.message)) this.logout();
  }

  onTab(name) {
    if (name === 'roster' && this.rosterClassId) this.loadRoster(this.rosterClassId);
    if (name === 'sessions' && this.sessionClassId) this.loadSessions(this.sessionClassId);
  }

  /* ---- Nạp dữ liệu ---- */
  async loadAll() {
    try {
      await this.loadCourses();
      if (this.role === 'ADMIN') this.view.fillLecturerOptions(await this.api.adminListLecturers(this.token));
      await this.loadClasses();
    } catch (err) { this.fail(err); }
  }

  async loadCourses() {
    this.courses = await this.api.adminListCourses(this.token);
    this.view.renderCourses(this.courses);
    this.view.fillCourseOptions(this.courses);
  }

  async loadClasses() {
    this.classes = await this.api.adminListClasses(this.token);
    this.view.renderClasses(this.classes);
    this.view.fillClassPickers(this.classes);
  }

  async loadRoster(classId) {
    this.rosterClassId = classId;
    this.view.setRosterVisible(!!classId);
    if (!classId) return;
    try { this.view.renderRoster(await this.api.adminListRoster(this.token, classId)); }
    catch (err) { this.fail(err); }
  }

  async loadSessions(classId) {
    this.sessionClassId = classId;
    this.view.setSessionsVisible(!!classId);
    if (!classId) return;
    try {
      this.sessions = await this.api.adminListSessions(this.token, classId);
      this.view.renderSessions(this.sessions);
    } catch (err) { this.fail(err); }
  }

  /* ---- Ghi ---- */
  async saveClass(d) {
    try {
      const r = await this.api.adminSaveClass(this.token, d);
      this.view.hideClassForm();
      this.view.toast(r.action === 'INSERTED' ? 'Đã tạo lớp.' : 'Đã lưu lớp.');
      await this.loadClasses();
    } catch (err) { this.fail(err); }
  }

  async saveCourse(d) {
    try {
      const r = await this.api.adminSaveCourse(this.token, d);
      this.view.hideCourseForm();
      this.view.toast(r.action === 'INSERTED' ? 'Đã tạo môn.' : 'Đã lưu môn.');
      await this.loadCourses();
    } catch (err) { this.fail(err); }
  }

  async saveSession(d) {
    try {
      const r = await this.api.adminSaveSession(this.token, Object.assign({ classId: this.sessionClassId }, d));
      this.view.hideSessionForm();
      this.view.toast(r.action === 'INSERTED' ? 'Đã tạo buổi học.' : 'Đã lưu buổi học.');
      await this.loadSessions(this.sessionClassId);
    } catch (err) { this.fail(err); }
  }

  async enroll(d) {
    try {
      const r = await this.api.adminEnroll(this.token, this.rosterClassId, d);
      this.view.resetEnrollForm();
      this.view.toast(r.enroll === 'UNCHANGED' ? 'MSSV ' + r.mssv + ' đã có trong lớp.' : 'Đã thêm ' + r.mssv + ' vào lớp.');
      await this.loadRoster(this.rosterClassId);
    } catch (err) { this.fail(err); }
  }

  async unenroll(mssv) {
    try {
      await this.api.adminUnenroll(this.token, this.rosterClassId, mssv);
      this.view.toast('Đã gỡ ' + mssv + ' khỏi lớp.');
      await this.loadRoster(this.rosterClassId);
    } catch (err) { this.fail(err); }
  }

  /**
   * Nhập CSV hai bước, đúng tinh thần previewImport()/runImport() cũ:
   * kiểm tra trước (dryRun) → xem báo cáo → mới "Nhập thật".
   */
  async importRoster(csv, dryRun) {
    if (!csv || !csv.trim()) { this.view.toast('Chưa có nội dung CSV.', 'error'); return; }
    try {
      const rep = await this.api.adminImportRoster(this.token, this.rosterClassId, csv, dryRun);
      this.view.renderCsvReport(rep);
      if (rep.written) {
        this.view.toast('Đã nhập ' + rep.validRows + ' dòng.');
        await this.loadRoster(this.rosterClassId);
      }
    } catch (err) { this.fail(err); }
  }
}
