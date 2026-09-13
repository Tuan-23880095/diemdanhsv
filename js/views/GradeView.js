/* js/views/GradeView.js — Chỉ đụng DOM, không chứa logic nghiệp vụ */

class GradeView {

  constructor() {
    this.el = {
      stepMssv:   document.getElementById('step-mssv'),
      stepCode:   document.getElementById('step-code'),
      resultBox:  document.getElementById('grades-box'),

      formMssv:   document.getElementById('mssv-form'),
      mssv:       document.getElementById('mssv'),
      sendBtn:    document.getElementById('send-btn'),

      formCode:   document.getElementById('code-form'),
      code:       document.getElementById('code'),
      verifyBtn:  document.getElementById('verify-btn'),
      resendBtn:  document.getElementById('resend-btn'),
      codeHint:   document.getElementById('code-hint'),
      backBtn:    document.getElementById('back-btn'),

      message:    document.getElementById('message'),
      who:        document.getElementById('who'),
      exitBtn:    document.getElementById('exit-btn'),
      classes:    document.getElementById('classes')
    };
    this._timer = null;
  }

  /* ---------------- Sự kiện ---------------- */

  onRequestCode(h) {
    this.el.formMssv.addEventListener('submit', ev => {
      ev.preventDefault();
      h(this.el.mssv.value.trim());
    });
  }

  onVerify(h) {
    this.el.formCode.addEventListener('submit', ev => {
      ev.preventDefault();
      h(this.el.code.value.trim().toUpperCase());
    });
  }

  onResend(h) { this.el.resendBtn.addEventListener('click', h); }
  onBack(h)   { this.el.backBtn.addEventListener('click', h); }
  onExit(h)   { this.el.exitBtn.addEventListener('click', h); }

  /** Ép mã về chữ hoa ngay khi gõ */
  bindCodeUppercase() {
    this.el.code.addEventListener('input', () => {
      this.el.code.value = this.el.code.value.toUpperCase();
    });
  }

  getMssv() { return this.el.mssv.value.trim(); }

  /* ---------------- Chuyển bước ---------------- */

  showStepMssv() {
    this.el.stepMssv.hidden  = false;
    this.el.stepCode.hidden  = true;
    this.el.resultBox.hidden = true;
    this.el.code.value = '';
    this._stopTimer();
  }

  showStepCode(mssv) {
    this.el.stepMssv.hidden  = true;
    this.el.stepCode.hidden  = false;
    this.el.resultBox.hidden = true;
    this.el.codeHint.textContent = 'Mã đã gửi tới email của MSSV ' + mssv +
                                   '. Mã có hiệu lực 10 phút.';
    this.el.code.focus();
  }

  showGrades(data) {
    this.el.stepMssv.hidden  = true;
    this.el.stepCode.hidden  = true;
    this.el.resultBox.hidden = false;
    this.el.who.textContent  = 'MSSV ' + data.mssv;
    this._stopTimer();
    this._renderClasses(data.classes);
  }

  /* ---------------- Trạng thái nút ---------------- */

  setSending(on) {
    this.el.sendBtn.disabled = on;
    this.el.sendBtn.textContent = on ? 'Đang gửi…' : 'Gửi mã xác minh';
  }

  setVerifying(on) {
    this.el.verifyBtn.disabled = on;
    this.el.verifyBtn.textContent = on ? 'Đang kiểm tra…' : 'Xem điểm';
  }

  /**
   * Khoá nút gửi lại trong n giây. Máy chủ cũng chặn gửi trùng trong
   * khoảng này, nên khoá ở giao diện để sinh viên khỏi bấm vô ích.
   */
  startResendCooldown(seconds) {
    let left = seconds;
    const btn = this.el.resendBtn;
    const tick = () => {
      if (left <= 0) {
        btn.disabled = false;
        btn.textContent = 'Gửi lại mã';
        this._stopTimer();
        return;
      }
      btn.disabled = true;
      btn.textContent = 'Gửi lại mã sau ' + left + 's';
      left--;
    };
    this._stopTimer();
    tick();
    this._timer = setInterval(tick, 1000);
  }

  _stopTimer() {
    if (this._timer) { clearInterval(this._timer); this._timer = null; }
  }

  /* ---------------- Thông báo ---------------- */

  showInfo(msg)  { this._msg(msg, 'border-blue-200 bg-blue-50 text-blue-900'); }
  showError(msg) { this._msg(msg, 'border-red-200 bg-red-50 text-red-900'); }
  clearMessage() { this.el.message.hidden = true; }

  _msg(msg, tone) {
    this.el.message.className = 'mt-4 rounded-xl border p-4 text-sm ' + tone;
    this.el.message.textContent = msg;
    this.el.message.hidden = false;
  }

  /* ---------------- Bảng điểm ---------------- */

  _renderClasses(classes) {
    if (!classes || !classes.length) {
      this.el.classes.innerHTML =
        '<p class="rounded-xl border border-slate-200 bg-white p-6 text-center text-slate-500">' +
        'Bạn chưa được ghi danh lớp nào, hoặc chưa có điểm nào được nhập.</p>';
      return;
    }

    this.el.classes.innerHTML = classes.map(c => {
      const rows = c.columns.length
        ? c.columns.map(col => {
            const graded = col.score !== null && col.score !== undefined;
            return '<tr class="border-t border-slate-100">' +
              '<td class="px-3 py-2">' + this._esc(col.name) + '</td>' +
              '<td class="px-3 py-2 text-right text-slate-500">' + this._esc(col.weight) + '%</td>' +
              '<td class="px-3 py-2 text-right font-semibold ' +
                  (graded ? 'text-slate-900' : 'text-slate-400') + '">' +
                (graded ? this._esc(col.score) : 'chưa chấm') +
              '</td>' +
            '</tr>';
          }).join('')
        : '<tr><td colspan="3" class="px-3 py-6 text-center text-slate-400">' +
          'Lớp này chưa có đầu điểm nào.</td></tr>';

      // Nói rõ đây là điểm tạm khi chưa chấm hết — tránh hiểu nhầm là điểm cuối
      const done = Number(c.weightDone) || 0;
      const total = Number(c.weightTotal) || 0;
      const partial = done > 0 && done < total;
      const avgBlock = c.average === null || c.average === undefined
        ? '<p class="text-sm text-slate-500">Chưa có đầu điểm nào được chấm.</p>'
        : '<p class="text-sm text-slate-600">' + (partial ? 'Điểm tạm tính' : 'Điểm tổng kết') + '</p>' +
          '<p class="text-3xl font-extrabold ' +
            (Number(c.average) >= 5 ? 'text-emerald-700' : 'text-red-700') + '">' +
            this._esc(c.average) + '</p>' +
          (partial
            ? '<p class="mt-1 text-xs text-amber-700">Mới chấm ' + done + '/' + total +
              '% trọng số — điểm sẽ đổi khi chấm nốt phần còn lại.</p>'
            : '');

      return '<section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">' +
        '<h2 class="font-bold">' + this._esc(c.courseName || c.courseCode || '(chưa đặt tên môn)') + '</h2>' +
        '<p class="text-sm text-slate-500">Lớp ' + this._esc(c.classCode) + '</p>' +
        '<div class="table-wrap mt-3">' +
          '<table class="w-full text-left text-sm">' +
            '<thead class="text-xs uppercase text-slate-500">' +
              '<tr><th class="px-3 py-2">Đầu điểm</th>' +
                  '<th class="px-3 py-2 text-right">Trọng số</th>' +
                  '<th class="px-3 py-2 text-right">Điểm</th></tr>' +
            '</thead>' +
            '<tbody>' + rows + '</tbody>' +
          '</table>' +
        '</div>' +
        '<div class="mt-3 border-t border-slate-100 pt-3">' + avgBlock + '</div>' +
      '</section>';
    }).join('');
  }

  /** Mọi dữ liệu từ máy chủ đều đi qua đây trước khi vào innerHTML */
  _esc(v) {
    return String(v === undefined || v === null ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
}
