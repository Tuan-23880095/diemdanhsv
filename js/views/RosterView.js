/* js/views/RosterView.js — Màn hình điểm danh thời gian thực của giảng viên */

class RosterView {

  constructor() {
    this.el = {
      loginBox:   document.getElementById('login-box'),
      loginForm:  document.getElementById('login-form'),
      username:   document.getElementById('username'),
      password:   document.getElementById('password'),
      loginError: document.getElementById('login-error'),

      dash:       document.getElementById('dashboard'),
      who:        document.getElementById('who'),
      logoutBtn:  document.getElementById('logout-btn'),

      classSel:   document.getElementById('class-select'),
      sessionSel: document.getElementById('session-select'),
      openBtn:    document.getElementById('open-btn'),
      closeBtn:   document.getElementById('close-btn'),

      codeBox:    document.getElementById('code-box'),
      codeText:   document.getElementById('code-text'),
      codeUntil:  document.getElementById('code-until'),

      cPresent:   document.getElementById('c-present'),
      cLate:      document.getElementById('c-late'),
      cAbsent:    document.getElementById('c-absent'),
      cEnrolled:  document.getElementById('c-enrolled'),

      alerts:     document.getElementById('alerts'),
      rosterTbl:  document.getElementById('roster-table'),
      absentTbl:  document.getElementById('absent-table'),
      toast:      document.getElementById('toast')
    };
  }

  onLogin(h)   { this.el.loginForm.addEventListener('submit', ev => { ev.preventDefault();
                   h(this.el.username.value.trim(), this.el.password.value); }); }
  onLogout(h)  { this.el.logoutBtn.addEventListener('click', h); }
  onClassChange(h)   { this.el.classSel.addEventListener('change', () => h(this.el.classSel.value)); }
  onSessionChange(h) { this.el.sessionSel.addEventListener('change', () => h(this.el.sessionSel.value)); }
  onOpen(h)    { this.el.openBtn.addEventListener('click', () => h(this.el.sessionSel.value)); }
  onClose(h)   { this.el.closeBtn.addEventListener('click', () => h(this.el.sessionSel.value)); }

  showLoginError(msg) {
    this.el.loginError.textContent = msg;
    this.el.loginError.hidden = !msg;
  }

  enterDashboard(fullName) {
    this.el.loginBox.hidden = true;
    this.el.dash.hidden = false;
    this.el.who.textContent = fullName;
  }

  exitDashboard() {
    this.el.dash.hidden = true;
    this.el.loginBox.hidden = false;
    this.el.password.value = '';
  }

  fillClasses(classes) {
    this.el.classSel.innerHTML = '<option value="">— Chọn lớp —</option>' +
      classes.map(c => '<option value="' + this._esc(c.classId) + '">' +
                        this._esc(c.label) + '</option>').join('');
  }

  fillSessions(sessions) {
    this.el.sessionSel.innerHTML = '<option value="">— Chọn buổi —</option>' +
      sessions.map(s => '<option value="' + this._esc(s.sessionId) + '">' +
                         this._esc(s.label) + '</option>').join('');
    this.setSessionControls(false);
  }

  setSessionControls(enabled) {
    this.el.openBtn.disabled = !enabled;
    this.el.closeBtn.disabled = !enabled;
  }

  showCode(info) {
    this.el.codeText.textContent = info.code;
    this.el.codeUntil.textContent = 'Đóng lúc ' + info.endTime.substring(11, 16) +
                                    ' · tính trễ sau ' + info.lateAfter.substring(11, 16);
    this.el.codeBox.hidden = false;
  }

  hideCode() { this.el.codeBox.hidden = true; }

  toast(msg, kind) {
    this.el.toast.textContent = msg;
    this.el.toast.className = 'fixed bottom-4 left-1/2 -translate-x-1/2 rounded-lg px-4 py-2 text-sm shadow-lg ' +
      (kind === 'error' ? 'bg-red-600 text-white' : 'bg-slate-900 text-white');
    this.el.toast.hidden = false;
    clearTimeout(this._t);
    this._t = setTimeout(() => { this.el.toast.hidden = true; }, 4000);
  }

  renderRoster(data) {
    this.el.cPresent.textContent  = data.counts.present;
    this.el.cLate.textContent     = data.counts.late;
    this.el.cAbsent.textContent   = data.counts.absent;
    this.el.cEnrolled.textContent = data.counts.enrolled;

    const rows = data.rows.map(r => new Attendance(r));

    this.el.rosterTbl.innerHTML = rows.length ? rows.map(a => {
      const tone = a.status === 'PRESENT' ? 'text-emerald-700' : 'text-amber-700';
      const flag = a.needsAttention
        ? '<span class="rounded bg-orange-100 px-1.5 py-0.5 text-xs text-orange-800">' +
          this._esc(a.gpsText) + (a.distanceM !== null ? ' · ' + a.distanceM + 'm' : '') + '</span>'
        : '<span class="text-xs text-slate-400">' + this._esc(a.gpsText) + '</span>';
      return '<tr class="border-t border-slate-100">' +
        '<td class="px-3 py-2 font-mono">' + this._esc(a.mssv) + '</td>' +
        '<td class="px-3 py-2">' + this._esc(a.fullName) + '</td>' +
        '<td class="px-3 py-2 font-semibold ' + tone + '">' + this._esc(a.statusText) + '</td>' +
        '<td class="px-3 py-2 tabular-nums">' + this._esc(a.timeOnly) + '</td>' +
        '<td class="px-3 py-2">' + flag + '</td>' +
      '</tr>';
    }).join('') : '<tr><td colspan="5" class="px-3 py-6 text-center text-slate-400">Chưa có ai điểm danh.</td></tr>';

    this.el.absentTbl.innerHTML = data.absent.length ? data.absent.map(s =>
      '<tr class="border-t border-slate-100">' +
        '<td class="px-3 py-2 font-mono">' + this._esc(s.mssv) + '</td>' +
        '<td class="px-3 py-2">' + this._esc(s.fullName) + '</td>' +
      '</tr>').join('')
      : '<tr><td colspan="2" class="px-3 py-6 text-center text-slate-400">Không còn ai vắng.</td></tr>';

    this._renderAlerts(data);
  }

  /**
   * D.8-4 và D.3: cảnh báo phải HIỆN RA cho giảng viên, không chỉ nằm im
   * trong sheet. Đây là nơi hai lớp bù đó thực sự có tác dụng.
   */
  _renderAlerts(data) {
    const items = [];

    data.deviceAlerts.forEach(a => items.push(
      '<li class="rounded-lg border border-red-200 bg-red-50 px-3 py-2">' +
        '<span class="font-semibold text-red-900">Trùng thiết bị:</span> ' +
        '<span class="text-red-800">' + this._esc(a.mssvList.join(', ')) +
        ' cùng điểm danh từ một máy.</span>' +
      '</li>'));

    data.gpsAlerts.forEach(a => items.push(
      '<li class="rounded-lg border border-orange-200 bg-orange-50 px-3 py-2 text-orange-900">' +
        '<span class="font-mono">' + this._esc(a.mssv) + '</span> — ' +
        this._esc(GPS_TEXT[a.gpsFlag] || a.gpsFlag) +
        (a.distanceM !== '' && a.distanceM !== undefined ? ' (' + this._esc(a.distanceM) + 'm)' : '') +
      '</li>'));

    this.el.alerts.innerHTML = items.join('');
    this.el.alerts.hidden = items.length === 0;
  }

  _esc(v) {
    return String(v === undefined || v === null ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
}
