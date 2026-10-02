/* js/views/AdminView.js — Trang quản trị (GĐ7): chỉ đụng DOM, không gọi API */

class AdminView {

  constructor() {
    const $ = id => document.getElementById(id);
    this.el = {
      loginBox: $('login-box'), loginForm: $('login-form'), loginError: $('login-error'),
      username: $('username'), password: $('password'),
      dashboard: $('dashboard'), who: $('who'), logoutBtn: $('logout-btn'),
      toast: $('toast'),

      classTable: $('class-table'), classForm: $('class-form'), classFormTitle: $('class-form-title'),
      classNewBtn: $('class-new-btn'), classCancelBtn: $('class-cancel-btn'),

      rosterClass: $('roster-class'), rosterBody: $('roster-body'), rosterTable: $('roster-table'),
      rosterCount: $('roster-count'), enrollForm: $('enroll-form'),
      csvFile: $('csv-file'), csvText: $('csv-text'), csvPreviewBtn: $('csv-preview-btn'),
      csvImportBtn: $('csv-import-btn'), csvReport: $('csv-report'),

      sessionClass: $('session-class'), sessionBody: $('session-body'), sessionTable: $('session-table'),
      sessionForm: $('session-form'), sessionFormTitle: $('session-form-title'),
      sessionNewBtn: $('session-new-btn'), sessionCancelBtn: $('session-cancel-btn'),

      courseTable: $('course-table'), courseForm: $('course-form'), courseFormTitle: $('course-form-title'),
      courseNewBtn: $('course-new-btn'), courseCancelBtn: $('course-cancel-btn'),

      manualBox: $('manual-box'), manualTitle: $('manual-title'), manualTable: $('manual-table'),
      manualSaveBtn: $('manual-save-btn'), manualCloseBtn: $('manual-close-btn'), manualAllPresent: $('manual-all-present'),

      gradeClass: $('grade-class'), gradeBody: $('grade-body'),
      attPreviewBtn: $('att-preview-btn'), attApplyBtn: $('att-apply-btn'), attRules: $('att-rules'),
      attReport: $('att-report'), attSummary: $('att-summary'), attTable: $('att-table'),
      gradeFile: $('grade-file'), gradeCsv: $('grade-csv'), gradePreviewBtn: $('grade-preview-btn'),
      gradeImportBtn: $('grade-import-btn'), gradeCsvReport: $('grade-csv-report'),
      gradeRefreshBtn: $('grade-refresh-btn'), gradeWeightNote: $('grade-weight-note'),
      gradeThead: $('grade-thead'), gradeTable: $('grade-table')
    };
    this.tabs = Array.from(document.querySelectorAll('.tab-btn'));
    this.panels = Array.from(document.querySelectorAll('[data-panel]'));
    this.isAdmin = false;
  }

  /* ---- Đăng nhập ---- */
  onLogin(h)  { this.el.loginForm.addEventListener('submit', ev => { ev.preventDefault(); h(this.el.username.value.trim(), this.el.password.value); }); }
  onLogout(h) { this.el.logoutBtn.addEventListener('click', h); }
  showLoginError(msg) { this.el.loginError.textContent = msg; this.el.loginError.hidden = !msg; }

  enterDashboard(fullName, role) {
    this.isAdmin = role === 'ADMIN';
    this.el.who.textContent = fullName + (this.isAdmin ? ' (quản trị)' : '');
    this.el.loginBox.hidden = true;
    this.el.dashboard.hidden = false;
    document.querySelectorAll('.admin-only').forEach(n => { n.hidden = !this.isAdmin; });
    this.el.password.value = '';
    this.showTab('classes');
  }
  exitDashboard() { this.el.dashboard.hidden = true; this.el.loginBox.hidden = false; }

  /* ---- Tab ---- */
  onTab(h) { this.tabs.forEach(b => b.addEventListener('click', () => { this.showTab(b.dataset.tab); h(b.dataset.tab); })); }
  showTab(name) {
    this.tabs.forEach(b => b.classList.toggle('font-semibold', b.dataset.tab === name));
    this.tabs.forEach(b => b.classList.toggle('border-b-2', b.dataset.tab === name));
    this.tabs.forEach(b => b.classList.toggle('border-blue-700', b.dataset.tab === name));
    this.panels.forEach(p => { p.hidden = p.dataset.panel !== name; });
  }

  /* ---- Tiện ích chung ---- */
  _esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
  _status(s) { return s === 'ACTIVE' ? '<span class="rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-800">Hoạt động</span>' : '<span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Ngưng</span>'; }
  _formData(form) { const o = {}; new FormData(form).forEach((v, k) => { o[k] = v; }); return o; }
  _fillForm(form, data) {
    Array.from(form.elements).forEach(el => {
      if (!el.name) return;
      if (el.multiple) {
        const vals = String(data[el.name] || '').split(/[,;\s]+/);
        Array.from(el.options).forEach(o => { o.selected = vals.indexOf(o.value) !== -1; });
      } else {
        el.value = data[el.name] == null ? '' : data[el.name];
      }
    });
  }
  _fillSelect(select, rows, valueKey, labelFn, placeholder) {
    select.innerHTML = placeholder ? '<option value="">' + this._esc(placeholder) + '</option>' : '';
    rows.forEach(r => {
      const o = document.createElement('option');
      o.value = r[valueKey]; o.textContent = labelFn(r);
      select.appendChild(o);
    });
  }

  toast(msg, kind) {
    this.el.toast.textContent = msg;
    this.el.toast.className = 'fixed bottom-4 left-1/2 -translate-x-1/2 rounded-lg px-4 py-2 text-sm shadow-lg ' +
      (kind === 'error' ? 'bg-red-700 text-white' : 'bg-slate-900 text-white');
    this.el.toast.hidden = false;
    clearTimeout(this._t);
    this._t = setTimeout(() => { this.el.toast.hidden = true; }, 5000);
  }

  /* ---- Lớp ---- */
  onClassNew(h)    { this.el.classNewBtn.addEventListener('click', h); }
  onClassCancel(h) { this.el.classCancelBtn.addEventListener('click', h); }
  onClassSubmit(h) { this.el.classForm.addEventListener('submit', ev => { ev.preventDefault(); h(this._classFormData()); }); }
  onClassEdit(h)   { this.el.classTable.addEventListener('click', ev => { const b = ev.target.closest('[data-edit]'); if (b) h(b.dataset.edit); }); }

  _classFormData() {
    const d = this._formData(this.el.classForm);
    const sel = this.el.classForm.elements.lecturerIds;
    if (sel) d.lecturerIds = Array.from(sel.selectedOptions).map(o => o.value).join(',');
    return d;
  }
  fillCourseOptions(courses) {
    this._fillSelect(this.el.classForm.elements.courseId, courses.filter(c => c.Status === 'ACTIVE'), 'CourseID',
      c => c.CourseCode + ' — ' + c.CourseName, '— Chọn môn —');
  }
  fillLecturerOptions(users) {
    const sel = this.el.classForm.elements.lecturerIds;
    if (sel) this._fillSelect(sel, users.filter(u => u.Status === 'ACTIVE'), 'UserID', u => u.FullName + ' (' + u.Username + ')', '');
  }
  renderClasses(rows) {
    this.el.classTable.innerHTML = rows.length ? rows.map(c => '<tr class="border-t border-slate-100">' +
      '<td class="px-2 py-2 font-medium">' + this._esc(c.ClassCode) + '</td>' +
      '<td class="px-2 py-2">' + this._esc(c.CourseCode) + '<br><span class="text-xs text-slate-500">' + this._esc(c.CourseName) + '</span></td>' +
      '<td class="px-2 py-2">' + this._esc(c.Semester) + ' / ' + this._esc(c.AcademicYear) + '</td>' +
      '<td class="px-2 py-2 text-xs">' + this._esc(c.LecturerNames) + '</td>' +
      '<td class="px-2 py-2">' + this._status(c.Status) + '</td>' +
      '<td class="px-2 py-2 text-right"><button data-edit="' + this._esc(c.ClassID) + '" class="text-blue-700 hover:underline">Sửa</button></td></tr>').join('')
      : '<tr><td colspan="6" class="px-2 py-4 text-center text-slate-500">Chưa có lớp nào.</td></tr>';
  }
  showClassForm(cls) {
    this.el.classFormTitle.textContent = cls ? 'Sửa lớp ' + cls.ClassCode : 'Lớp mới';
    this._fillForm(this.el.classForm, cls ? {
      classId: cls.ClassID, courseId: cls.CourseID, classCode: cls.ClassCode, semester: cls.Semester,
      academicYear: cls.AcademicYear, lecturerIds: cls.LecturerID, roomLat: cls.RoomLat, roomLng: cls.RoomLng,
      allowedRadiusM: cls.AllowedRadiusM, status: cls.Status
    } : { classId: '', allowedRadiusM: 100, status: 'ACTIVE' });
    // Giảng viên thường không đổi được môn — khoá ô này khi không phải ADMIN
    this.el.classForm.elements.courseId.disabled = !this.isAdmin;
    this.el.classForm.hidden = false;
    this.el.classForm.elements.classCode.focus();
  }
  hideClassForm() { this.el.classForm.hidden = true; }

  /* ---- Lớp chọn ở tab sinh viên/buổi học ---- */
  fillClassPickers(rows) {
    [this.el.rosterClass, this.el.sessionClass, this.el.gradeClass].forEach(sel => {
      const cur = sel.value;
      this._fillSelect(sel, rows.filter(c => c.Status === 'ACTIVE'), 'ClassID', c => c.ClassCode + ' — ' + c.CourseName, '— Chọn lớp —');
      sel.value = cur;
    });
  }
  onRosterClassChange(h)  { this.el.rosterClass.addEventListener('change', () => h(this.el.rosterClass.value)); }
  onSessionClassChange(h) { this.el.sessionClass.addEventListener('change', () => h(this.el.sessionClass.value)); }
  onGradeClassChange(h)   { this.el.gradeClass.addEventListener('change', () => h(this.el.gradeClass.value)); }

  /* ---- Sinh viên của lớp ---- */
  onEnrollSubmit(h)  { this.el.enrollForm.addEventListener('submit', ev => { ev.preventDefault(); h(this._formData(this.el.enrollForm)); }); }
  onUnenroll(h)      { this.el.rosterTable.addEventListener('click', ev => { const b = ev.target.closest('[data-unenroll]'); if (b && confirm('Gỡ MSSV ' + b.dataset.unenroll + ' khỏi lớp? (Lịch sử điểm danh vẫn được giữ.)')) h(b.dataset.unenroll); }); }
  onCsvPreview(h)    { this.el.csvPreviewBtn.addEventListener('click', () => h(this.el.csvText.value)); }
  onCsvImport(h)     { this.el.csvImportBtn.addEventListener('click', () => h(this.el.csvText.value)); }
  onCsvFile(h) {
    this.el.csvFile.addEventListener('change', () => {
      const f = this.el.csvFile.files[0];
      if (!f) return;
      const r = new FileReader();
      r.onload = () => { this.el.csvText.value = String(r.result); h(String(r.result)); };
      r.readAsText(f, 'UTF-8');
    });
  }
  resetEnrollForm() { this.el.enrollForm.reset(); }
  setRosterVisible(v) { this.el.rosterBody.hidden = !v; if (!v) this.clearCsvReport(); }
  renderRoster(rows) {
    const active = rows.filter(r => r.EnrollStatus === 'ACTIVE').length;
    this.el.rosterCount.textContent = '(' + active + ' đang học' + (rows.length > active ? ', ' + (rows.length - active) + ' đã gỡ' : '') + ')';
    this.el.rosterTable.innerHTML = rows.length ? rows.map(r => '<tr class="border-t border-slate-100' + (r.EnrollStatus !== 'ACTIVE' ? ' opacity-50' : '') + '">' +
      '<td class="px-2 py-2 font-mono">' + this._esc(r.MSSV) + '</td>' +
      '<td class="px-2 py-2">' + this._esc(r.FullName) + '</td>' +
      '<td class="px-2 py-2 text-xs">' + this._esc(r.Email) + '</td>' +
      '<td class="px-2 py-2">' + this._status(r.EnrollStatus) + '</td>' +
      '<td class="px-2 py-2 text-right">' + (r.EnrollStatus === 'ACTIVE' ? '<button data-unenroll="' + this._esc(r.MSSV) + '" class="text-red-700 hover:underline">Gỡ</button>' : '') + '</td></tr>').join('')
      : '<tr><td colspan="5" class="px-2 py-4 text-center text-slate-500">Lớp chưa có sinh viên — thêm từng người hoặc nhập CSV.</td></tr>';
  }
  clearCsvReport() { this.el.csvReport.hidden = true; this.el.csvReport.innerHTML = ''; this.el.csvImportBtn.disabled = true; }
  renderCsvReport(rep) {
    const p = rep.plan || {};
    const ok = rep.errors.length === 0;
    let html = '<div class="rounded-lg ' + (ok ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900') + ' p-3">' +
      (rep.written
        ? '<b>Đã nhập xong.</b> Sinh viên mới: ' + rep.counts.studentsInserted + ', cập nhật: ' + rep.counts.studentsUpdated +
          ', ghi danh mới: ' + rep.counts.enrollmentsInserted + ', ghi danh lại: ' + rep.counts.enrollmentsReactivated + '.'
        : '<b>Kiểm tra:</b> ' + rep.validRows + '/' + rep.totalRows + ' dòng hợp lệ. Sẽ tạo ' + p.newStudents + ' sinh viên mới, ' +
          p.existingStudents + ' đã có; ghi danh mới ' + p.newEnrollments + ', đã trong lớp ' + p.alreadyEnrolled + '.') +
      '<div class="mt-1 text-xs">Cột nhận diện: ' + this._esc(Object.keys(rep.columns).map(k => k + ' ← "' + rep.columns[k] + '"').join('; ')) + '</div></div>';
    if (rep.errors.length) {
      html += '<ul class="mt-2 list-disc pl-5 text-red-800">' + rep.errors.slice(0, 20).map(e => '<li>Dòng ' + e.line + ': ' + this._esc(e.error) + '</li>').join('') +
        (rep.errors.length > 20 ? '<li>… và ' + (rep.errors.length - 20) + ' lỗi khác</li>' : '') + '</ul>' +
        '<p class="mt-1 text-xs text-slate-600">Sửa file rồi kiểm tra lại. Khi còn lỗi, hệ thống không ghi dòng nào.</p>';
    }
    if (!rep.written && rep.preview && rep.preview.length) {
      html += '<details class="mt-2 text-xs"><summary class="cursor-pointer text-slate-600">Xem ' + rep.preview.length + ' dòng đầu</summary><table class="mt-1 w-full">' +
        rep.preview.map(r => '<tr><td class="pr-2 font-mono">' + this._esc(r.mssv) + '</td><td class="pr-2">' + this._esc(r.fullName) + '</td><td class="pr-2">' + this._esc(r.email) +
          '</td><td class="text-slate-500">' + (r.alreadyEnrolled ? 'đã trong lớp' : r.studentExists ? 'đã có SV, ghi danh mới' : 'SV mới') + '</td></tr>').join('') + '</table></details>';
    }
    this.el.csvReport.innerHTML = html;
    this.el.csvReport.hidden = false;
    this.el.csvImportBtn.disabled = !(ok && !rep.written && rep.validRows > 0);
  }

  /* ---- Buổi học ---- */
  onSessionNew(h)    { this.el.sessionNewBtn.addEventListener('click', h); }
  onSessionCancel(h) { this.el.sessionCancelBtn.addEventListener('click', h); }
  onSessionSubmit(h) { this.el.sessionForm.addEventListener('submit', ev => { ev.preventDefault(); h(this._formData(this.el.sessionForm)); }); }
  onSessionEdit(h)   { this.el.sessionTable.addEventListener('click', ev => { const b = ev.target.closest('[data-edit]'); if (b) h(b.dataset.edit); }); }
  setSessionsVisible(v) { this.el.sessionBody.hidden = !v; this.hideSessionForm(); this.hideManual(); }
  renderSessions(rows) {
    this.el.sessionTable.innerHTML = rows.length ? rows.map(s => '<tr class="border-t border-slate-100">' +
      '<td class="px-2 py-2">' + this._esc(s.SessionNo) + '</td>' +
      '<td class="px-2 py-2">' + this._esc(s.Date || '') + '</td>' +
      '<td class="px-2 py-2 text-xs">' + this._esc((s.StartTime || '') + (s.EndTime ? '–' + s.EndTime : '')) + '</td>' +
      '<td class="px-2 py-2">' + this._esc(s.Content) + '</td>' +
      '<td class="px-2 py-2">' + this._status(s.Status) + '</td>' +
      '<td class="px-2 py-2 text-right whitespace-nowrap"><button data-manual="' + this._esc(s.SessionID) + '" class="mr-2 text-emerald-700 hover:underline">Điểm danh tay</button>' +
      '<button data-edit="' + this._esc(s.SessionID) + '" class="text-blue-700 hover:underline">Sửa</button></td></tr>').join('')
      : '<tr><td colspan="6" class="px-2 py-4 text-center text-slate-500">Chưa có buổi học — cần ít nhất một buổi để mở điểm danh.</td></tr>';
  }
  onManualOpen(h)  { this.el.sessionTable.addEventListener('click', ev => { const b = ev.target.closest('[data-manual]'); if (b) h(b.dataset.manual); }); }
  onManualSave(h)  { this.el.manualSaveBtn.addEventListener('click', () => h(this._manualMarks())); }
  onManualClose(h) { this.el.manualCloseBtn.addEventListener('click', h); }
  onManualAllPresent() {
    this.el.manualAllPresent.addEventListener('click', () => {
      this.el.manualTable.querySelectorAll('select').forEach(sel => { sel.value = 'PRESENT'; });
    });
  }
  _manualMarks() {
    // Chỉ gửi dòng có chọn giá trị khác hiện tại (rỗng = không đụng)
    return Array.from(this.el.manualTable.querySelectorAll('select')).map(sel => ({ mssv: sel.dataset.mssv, status: sel.value }))
      .filter(m => m.status !== '');
  }
  _statusLabel(s) {
    return { PRESENT: 'Có mặt', LATE: 'Trễ', ABSENT: 'Vắng', EXCUSED: 'Vắng có phép' }[s] || (s ? s : '— chưa có —');
  }
  showManual(info) {
    this.el.manualTitle.textContent = 'buổi ' + info.sessionNo + (info.date ? ' (' + info.date + ')' : '');
    const opts = [['', '(giữ nguyên)'], ['PRESENT', 'Có mặt'], ['LATE', 'Trễ'], ['ABSENT', 'Vắng'], ['EXCUSED', 'Vắng có phép']];
    this.el.manualTable.innerHTML = info.rows.length ? info.rows.map(r => '<tr class="border-t border-slate-100">' +
      '<td class="px-2 py-1 font-mono">' + this._esc(r.mssv) + '</td>' +
      '<td class="px-2 py-1">' + this._esc(r.fullName) + '</td>' +
      '<td class="px-2 py-1 text-xs">' + this._esc(this._statusLabel(r.status)) + (r.checkInTime ? '<br><span class="text-slate-400">' + this._esc(r.checkInTime.replace('T', ' ')) + '</span>' : '') + '</td>' +
      '<td class="px-2 py-1"><select data-mssv="' + this._esc(r.mssv) + '" class="rounded border border-slate-300 bg-white px-2 py-1 text-sm">' +
        opts.map(o => '<option value="' + o[0] + '">' + o[1] + '</option>').join('') + '</select></td></tr>').join('')
      : '<tr><td colspan="4" class="px-2 py-4 text-center text-slate-500">Lớp chưa có sinh viên.</td></tr>';
    this.el.manualBox.hidden = false;
    this.el.manualBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  hideManual() { this.el.manualBox.hidden = true; }
  showSessionForm(s, nextNo) {
    this.el.sessionFormTitle.textContent = s ? 'Sửa buổi ' + s.SessionNo : 'Buổi mới';
    this._fillForm(this.el.sessionForm, s ? {
      sessionId: s.SessionID, sessionNo: s.SessionNo, date: s.Date || '', startTime: s.StartTime || '',
      endTime: s.EndTime || '', content: s.Content || '', status: s.Status
    } : { sessionId: '', sessionNo: nextNo, status: 'ACTIVE' });
    this.el.sessionForm.hidden = false;
  }
  hideSessionForm() { this.el.sessionForm.hidden = true; }

  /* ---- Điểm & chuyên cần ---- */
  onAttPreview(h)    { this.el.attPreviewBtn.addEventListener('click', h); }
  onAttApply(h)      { this.el.attApplyBtn.addEventListener('click', () => { if (confirm('Ghi điểm chuyên cần vào bảng điểm của lớp này? Điểm cũ của cột "Chuyên cần" sẽ được cập nhật.')) h(); }); }
  onGradePreview(h)  { this.el.gradePreviewBtn.addEventListener('click', () => h(this.el.gradeCsv.value)); }
  onGradeImport(h)   { this.el.gradeImportBtn.addEventListener('click', () => h(this.el.gradeCsv.value)); }
  onGradeRefresh(h)  { this.el.gradeRefreshBtn.addEventListener('click', h); }
  onGradeFile(h) {
    this.el.gradeFile.addEventListener('change', () => {
      const f = this.el.gradeFile.files[0];
      if (!f) return;
      const r = new FileReader();
      r.onload = () => { this.el.gradeCsv.value = String(r.result); h(String(r.result)); };
      r.readAsText(f, 'UTF-8');
    });
  }
  setGradesVisible(v) {
    this.el.gradeBody.hidden = !v;
    this.el.attReport.hidden = true; this.el.attApplyBtn.disabled = true;
    this.clearGradeCsvReport();
  }
  renderAttendanceReport(rep, written) {
    const r = rep.rules || {};
    this.el.attRules.textContent = 'Công thức: ' + r.max_score + ' − ' + r.absent_penalty + '×vắng − ' + r.excused_penalty + '×có phép − ' + r.late_penalty +
      '×trễ (thấp nhất 0). Cấm thi khi vắng + trễ÷' + r.late_per_absence + ' + phép÷' + r.excused_per_absence + ' ≥ ' + r.ban_threshold +
      '. Cột "' + r.column_name + '" trọng số ' + r.column_weight + '%.';
    this.el.attSummary.innerHTML = (written ? '<b class="text-emerald-800">Đã ghi vào bảng điểm.</b> ' : '') +
      'Tính trên <b>' + rep.sessionsCounted + '</b> buổi đã điểm danh, ' + rep.rows.length + ' sinh viên' +
      (rep.bannedCount !== undefined ? ', <b class="' + (rep.bannedCount ? 'text-red-700' : '') + '">' + rep.bannedCount + ' cấm thi</b>.' : '.') +
      (rep.sessionsCounted === 0 ? ' <span class="text-amber-700">Chưa có buổi nào điểm danh — chưa ghi được.</span>' : '');
    this.el.attTable.innerHTML = rep.rows.map(x => '<tr class="border-t border-slate-100' + (x.banned ? ' bg-red-50' : '') + '">' +
      '<td class="px-2 py-1 font-mono">' + this._esc(x.mssv) + '</td><td class="px-2 py-1">' + this._esc(x.fullName) + '</td>' +
      '<td class="px-2 py-1 text-right">' + x.present + '</td><td class="px-2 py-1 text-right">' + x.late + '</td>' +
      '<td class="px-2 py-1 text-right">' + x.absent + '</td><td class="px-2 py-1 text-right">' + x.excused + '</td>' +
      '<td class="px-2 py-1 text-right font-semibold">' + this._esc(x.score) + '</td>' +
      '<td class="px-2 py-1">' + (x.banned ? '<span class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-800">Cấm thi (' + x.equivalentAbsences + ' vắng tđ)</span>' : '<span class="text-xs text-slate-400">' + x.equivalentAbsences + ' vắng tđ</span>') +
      // L11: SV ghi danh muộn — ghi rõ số buổi trước ngày ghi danh đã bỏ qua.
      (Number(x.skippedBeforeEnrollment) > 0
        ? ' <span class="text-xs text-sky-700" title="Ghi danh ' + this._esc(x.enrolledAt) + '">ghi danh muộn, tính ' + x.sessionsCounted + '/' + rep.sessionsCounted + ' buổi</span>'
        : '') +
      '</td></tr>').join('');
    this.el.attReport.hidden = false;
    this.el.attApplyBtn.disabled = written || rep.sessionsCounted === 0;
  }
  clearGradeCsvReport() { this.el.gradeCsvReport.hidden = true; this.el.gradeCsvReport.innerHTML = ''; this.el.gradeImportBtn.disabled = true; }
  renderGradeCsvReport(rep) {
    const ok = rep.errors.length === 0;
    let html = '<div class="rounded-lg ' + (ok ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900') + ' p-3">' +
      (rep.written
        ? '<b>Đã nhập xong.</b> Cột mới: ' + rep.counts.columnsCreated + ', cột cập nhật: ' + rep.counts.columnsUpdated + ', ô điểm ghi: ' + rep.counts.scoresWritten + '.'
        : '<b>Kiểm tra:</b> ' + rep.validRows + '/' + rep.totalRows + ' dòng hợp lệ, ' + rep.cells + ' ô điểm sẽ ghi.') +
      '<div class="mt-1 text-xs">Đầu điểm: ' + this._esc(rep.columns.map(c => c.name + ' (' + c.weight + '%' + (c.exists ? ', đã có' : ', mới') + ')').join('; ')) +
      ' — tổng ' + this._esc(rep.weightTotal) + '%' + (rep.weightNote ? '. ' + this._esc(rep.weightNote) : '') + '</div></div>';
    if (rep.errors.length) {
      html += '<ul class="mt-2 list-disc pl-5 text-red-800">' + rep.errors.slice(0, 20).map(e => '<li>Dòng ' + e.line + ': ' + this._esc(e.error) + '</li>').join('') +
        (rep.errors.length > 20 ? '<li>… và ' + (rep.errors.length - 20) + ' lỗi khác</li>' : '') + '</ul>' +
        '<p class="mt-1 text-xs text-slate-600">Sửa file rồi kiểm tra lại. Khi còn lỗi, hệ thống không ghi gì.</p>';
    }
    this.el.gradeCsvReport.innerHTML = html;
    this.el.gradeCsvReport.hidden = false;
    this.el.gradeImportBtn.disabled = !(ok && !rep.written && rep.cells > 0);
  }
  renderGradesReport(rep) {
    const cols = rep.columns || [];
    this.el.gradeWeightNote.textContent = cols.length
      ? 'Tổng trọng số ' + rep.weightTotal + '%' + (rep.weightTotal > 100 ? ' — điểm tổng quy về tối đa 10.' : rep.weightTotal < 100 ? ' — chưa đủ 100%.' : '.')
      : 'Lớp chưa có đầu điểm nào. Nhập CSV hoặc ghi điểm chuyên cần để tạo.';
    this.el.gradeThead.innerHTML = '<tr><th class="px-2 py-2">MSSV</th><th class="px-2 py-2">Họ tên</th>' +
      cols.map(c => '<th class="px-2 py-2 text-right">' + this._esc(c.name) + '<br><span class="font-normal normal-case text-slate-400">' + c.weight + '%</span></th>').join('') +
      '<th class="px-2 py-2 text-right">Tổng</th><th class="px-2 py-2">TT</th></tr>';
    this.el.gradeTable.innerHTML = rep.rows.length ? rep.rows.map(r => '<tr class="border-t border-slate-100' + (r.banned ? ' bg-red-50' : '') + '">' +
      '<td class="px-2 py-1 font-mono">' + this._esc(r.mssv) + '</td><td class="px-2 py-1">' + this._esc(r.fullName) + '</td>' +
      r.scores.map(v => '<td class="px-2 py-1 text-right' + (v === null ? ' text-slate-300' : '') + '">' + (v === null ? '–' : this._esc(v)) + '</td>').join('') +
      '<td class="px-2 py-1 text-right font-semibold">' + (r.total === null ? '–' : this._esc(r.total)) +
        (r.weightDone < r.weightTotal ? '<br><span class="text-xs font-normal text-amber-700">' + r.weightDone + '/' + r.weightTotal + '%</span>' : '') + '</td>' +
      '<td class="px-2 py-1">' + (r.banned ? '<span class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-800">Cấm thi</span>' : '') + '</td></tr>').join('')
      : '<tr><td colspan="' + (cols.length + 4) + '" class="px-2 py-4 text-center text-slate-500">Lớp chưa có sinh viên.</td></tr>';
  }

  /* ---- Môn ---- */
  onCourseNew(h)    { this.el.courseNewBtn.addEventListener('click', h); }
  onCourseCancel(h) { this.el.courseCancelBtn.addEventListener('click', h); }
  onCourseSubmit(h) { this.el.courseForm.addEventListener('submit', ev => { ev.preventDefault(); h(this._formData(this.el.courseForm)); }); }
  onCourseEdit(h)   { this.el.courseTable.addEventListener('click', ev => { const b = ev.target.closest('[data-edit]'); if (b) h(b.dataset.edit); }); }
  renderCourses(rows) {
    this.el.courseTable.innerHTML = rows.length ? rows.map(c => '<tr class="border-t border-slate-100">' +
      '<td class="px-2 py-2 font-medium">' + this._esc(c.CourseCode) + '</td>' +
      '<td class="px-2 py-2">' + this._esc(c.CourseName) + '</td>' +
      '<td class="px-2 py-2">' + this._esc(c.Credits == null ? '' : c.Credits) + '</td>' +
      '<td class="px-2 py-2">' + this._status(c.Status) + '</td>' +
      '<td class="px-2 py-2 text-right">' + (this.isAdmin ? '<button data-edit="' + this._esc(c.CourseID) + '" class="text-blue-700 hover:underline">Sửa</button>' : '') + '</td></tr>').join('')
      : '<tr><td colspan="5" class="px-2 py-4 text-center text-slate-500">Chưa có môn nào.</td></tr>';
  }
  showCourseForm(c) {
    this.el.courseFormTitle.textContent = c ? 'Sửa môn ' + c.CourseCode : 'Môn mới';
    this._fillForm(this.el.courseForm, c ? {
      courseId: c.CourseID, courseCode: c.CourseCode, courseName: c.CourseName, credits: c.Credits,
      theoryHours: c.TheoryHours, practiceHours: c.PracticeHours, status: c.Status
    } : { courseId: '', status: 'ACTIVE' });
    this.el.courseForm.hidden = false;
    this.el.courseForm.elements.courseCode.focus();
  }
  hideCourseForm() { this.el.courseForm.hidden = true; }
}
