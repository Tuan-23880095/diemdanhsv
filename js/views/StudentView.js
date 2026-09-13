/* js/views/StudentView.js — Chỉ đụng DOM, không chứa logic nghiệp vụ */

class StudentView {

  constructor() {
    this.el = {
      form:       document.getElementById('checkin-form'),
      mssv:       document.getElementById('mssv'),
      code:       document.getElementById('code'),
      submit:     document.getElementById('submit-btn'),
      gpsBox:     document.getElementById('gps-status'),
      gpsText:    document.getElementById('gps-text'),
      result:     document.getElementById('result'),
      historyBox: document.getElementById('history-box'),
      historyTbl: document.getElementById('history-table')
    };
  }

  onSubmit(handler) {
    this.el.form.addEventListener('submit', ev => {
      ev.preventDefault();
      handler({
        mssv: this.el.mssv.value.trim(),
        code: this.el.code.value.trim().toUpperCase()
      });
    });
  }

  /** Ép mã về chữ hoa ngay khi gõ — sinh viên không phải bận tâm hoa thường */
  bindCodeUppercase() {
    this.el.code.addEventListener('input', () => {
      this.el.code.value = this.el.code.value.toUpperCase();
    });
  }

  setGps(text, state) {
    const map = {
      pending: 'bg-amber-50 text-amber-800 border-amber-200',
      ok:      'bg-emerald-50 text-emerald-800 border-emerald-200',
      warn:    'bg-orange-50 text-orange-800 border-orange-200'
    };
    this.el.gpsBox.className =
      'flex items-center gap-2 rounded-lg border px-3 py-2 text-sm ' + (map[state] || map.pending);
    this.el.gpsText.textContent = text;
  }

  setLoading(on) {
    this.el.submit.disabled = on;
    this.el.submit.textContent = on ? 'Đang gửi…' : 'Gửi điểm danh';
  }

  showSuccess(data) {
    const warn = (data.gpsFlag === 'OUT_OF_RANGE' || data.gpsFlag === 'NO_GPS')
      ? '<p class="mt-2 text-sm text-orange-700">Lưu ý: ' + (GPS_TEXT[data.gpsFlag] || '') +
        '. Điểm danh vẫn được ghi nhận, giảng viên sẽ xem lại.</p>'
      : '';

    this.el.result.innerHTML =
      '<div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">' +
        '<p class="text-lg font-bold text-emerald-900">Đã ghi nhận — ' + this._esc(data.statusText) + '</p>' +
        '<p class="mt-1 text-sm text-emerald-800">' + this._esc(data.fullName) +
          ' · ' + this._esc(data.mssv) + '</p>' +
        '<p class="text-sm text-emerald-800">Lúc ' + this._esc(data.checkInTime.replace('T', ' ')) + '</p>' +
        warn +
      '</div>';
    this.el.result.hidden = false;
  }

  showError(message) {
    this.el.result.innerHTML =
      '<div class="rounded-xl border border-red-200 bg-red-50 p-4">' +
        '<p class="font-semibold text-red-900">Không điểm danh được</p>' +
        '<p class="mt-1 text-sm text-red-800">' + this._esc(message) + '</p>' +
      '</div>';
    this.el.result.hidden = false;
  }

  renderHistory(rows) {
    if (!rows || !rows.length) { this.el.historyBox.hidden = true; return; }
    const body = rows.map(r => {
      const a = new Attendance(r);
      const tone = { PRESENT: 'text-emerald-700', LATE: 'text-amber-700' }[a.status] || 'text-red-700';
      return '<tr class="border-t border-slate-100">' +
        '<td class="px-3 py-2">' + this._esc(r.sessionNo) + '</td>' +
        '<td class="px-3 py-2">' + this._esc(ClassSession.formatDate(r.date)) + '</td>' +
        '<td class="px-3 py-2 text-slate-600">' + this._esc(r.content || '') + '</td>' +
        '<td class="px-3 py-2 font-semibold ' + tone + '">' + this._esc(a.statusText) + '</td>' +
      '</tr>';
    }).join('');
    this.el.historyTbl.innerHTML = body;
    this.el.historyBox.hidden = false;
  }

  /** Mọi dữ liệu từ máy chủ đều đi qua đây trước khi vào innerHTML */
  _esc(v) {
    return String(v === undefined || v === null ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
}
