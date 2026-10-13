/* js/controllers/KhtdController.js — Phiếu học tập online KHTĐ: chế độ 'student' (khtd/online.html) và 'lecturer' (khtd/quanly.html). */

class KhtdController {
  constructor(api, view, mode) {
    this.api = api; this.view = view; this.mode = mode;
    this.token = null; this.ws = null; this.schema = null; this.autosave = null; this.classId = null;
    this.sub = null; this.attempt = null;
  }

  init() {
    if (this.mode === 'student') this._initStudent(); else this._initLecturer();
  }

  /* ================= SINH VIÊN ================= */
  _initStudent() {
    const saved = sessionStorage.getItem('khtd_token');
    document.getElementById('login-form').addEventListener('submit', (e) => { e.preventDefault(); this.login(); });
    document.getElementById('btn-logout').addEventListener('click', () => { sessionStorage.removeItem('khtd_token'); location.reload(); });
    document.getElementById('ws-list').addEventListener('click', (e) => { const b = e.target.closest('.ws-item'); if (b) this.openWorksheet(b.dataset.ws); });
    document.getElementById('btn-back').addEventListener('click', () => this.showList());
    document.getElementById('btn-save').addEventListener('click', () => this.saveDraft(true));
    document.getElementById('btn-submit').addEventListener('click', () => this.submit());
    document.getElementById('btn-retake').addEventListener('click', () => this.retake());
    document.getElementById('ws-body').addEventListener('input', () => this._scheduleAutosave());
    if (saved) { this.token = saved; this.showList(); } else { this.view.show('login', true); }
  }

  async login() {
    const mssv = document.getElementById('mssv').value.trim();
    const code = document.getElementById('code').value.trim().toUpperCase();
    try {
      const d = await this.api.khtdLogin(mssv, code);
      this.token = d.token; sessionStorage.setItem('khtd_token', d.token);
      if (!d.email) this.view.msg('Hồ sơ của bạn chưa có email — kết quả sẽ không gửi được qua thư; báo giảng viên cập nhật.', 'err');
      this.showList();
    } catch (err) { this.view.msg(err.message, 'err'); }
  }

  async showList() {
    this.view.show('login', false); this.view.show('ws-panel', false); this.view.show('list', true);
    clearTimeout(this.autosave);
    try {
      const d = await this.api.khtdListWorksheets(this.token);
      this.view.setStudent(d.student); this.view.renderWorksheetList(d.worksheets);
    } catch (err) {
      sessionStorage.removeItem('khtd_token'); this.token = null;
      this.view.show('list', false); this.view.show('login', true); this.view.msg(err.message, 'err');
    }
  }

  async openWorksheet(id) {
    try {
      const d = await this.api.khtdGetWorksheet(this.token, id);
      this.ws = d.worksheet; this.schema = d.worksheet.schema;
      this.sub = d.submission; this.attempt = d.attempt || null;
      this.view.show('list', false); this.view.show('ws-panel', true);
      this.view.renderWorksheet(d.worksheet, d.submission, d.attempt);
      window.scrollTo(0, 0);
    } catch (err) { this.view.msg(err.message, 'err'); }
  }

  _scheduleAutosave() {
    clearTimeout(this.autosave);
    this.autosave = setTimeout(() => this.saveDraft(false), 4000);
  }

  async saveDraft(manual) {
    if (!this.ws) return;
    try {
      const d = await this.api.khtdSaveDraft(this.token, this.ws.id, this.view.collectAnswers(this.schema));
      const el = document.getElementById('save-state'); if (el) el.textContent = 'Đã lưu nháp ' + (d.savedAt || '').slice(11, 16);
      if (manual) this.view.msg('Đã lưu nháp. Bạn có thể quay lại làm tiếp trong thời gian phiếu còn mở.', 'ok');
    } catch (err) { if (manual) this.view.msg(err.message, 'err'); }
  }

  async submit() {
    if (!this.ws) return;
    const answers = this.view.collectAnswers(this.schema);
    const att = this.attempt || {};
    const attemptNo = this.sub && this.sub.Status === 'DRAFT' ? Number(this.sub.AttemptNo || 1) : 1;
    const ok = await this.view.confirmSubmit(Object.assign({
      no: this.ws.no, attemptNo, allowed: att.allowed === undefined ? 1 : att.allowed, extra: att.extra || 0,
      remainingAfter: att.remaining == null ? (att.allowed === undefined ? 0 : null) : Math.max(0, att.remaining - 1),
    }, this.view.countBlanks(this.schema, answers)));
    if (!ok) return;
    clearTimeout(this.autosave);
    const btn = document.getElementById('btn-submit'); btn.disabled = true; btn.textContent = 'Đang nộp và chấm…';
    try {
      const d = await this.api.khtdSubmit(this.token, this.ws.id, answers);
      let m = `Đã nộp thành công${d.attemptNo > 1 ? ' lần ' + d.attemptNo : ''}.`;
      if (d.ai) m += ` Điểm tạm tính: ${d.ai.total}/10.`;
      if (d.attempt && d.attempt.score != null && (d.attempt.allowed !== 1 || d.attempt.used > 1)) m += ` Điểm phiếu đang tính: ${d.attempt.score}/10.`;
      if (d.emailSent) m += ` Kết quả đã gửi tới ${d.emailTo}.`;
      else if (d.ai) m += ' (Không gửi được email — xem kết quả tại đây.)';
      if (d.aiError) m += ' ' + d.aiError;
      this.view.msg(m, 'ok');
      await this.openWorksheet(this.ws.id);
    } catch (err) { this.view.msg(err.message, 'err'); }
    finally { btn.disabled = false; btn.textContent = 'Nộp phiếu'; }
  }

  /* Bắt đầu lần làm mới: máy chủ chép bài lần trước sang một bản nháp mới. */
  async retake() {
    if (!this.ws) return;
    const btn = document.getElementById('btn-retake'); btn.disabled = true;
    try {
      const d = await this.api.khtdRetake(this.token, this.ws.id);
      await this.openWorksheet(this.ws.id);
      this.view.msg(`Bắt đầu lần ${d.attemptNo}. Bài lần trước đã được chép sang — sửa rồi bấm “Nộp phiếu”.`, 'ok');
    } catch (err) { this.view.msg(err.message, 'err'); }
    finally { btn.disabled = false; }
  }

  /* ================= GIẢNG VIÊN ================= */
  _initLecturer() {
    this.token = sessionStorage.getItem('dd_token');
    if (!this.token) { document.getElementById('need-login').classList.remove('hidden'); return; }
    document.getElementById('lec-main').classList.remove('hidden');
    document.getElementById('class-select').addEventListener('change', (e) => { this.classId = e.target.value; if (this.classId) this.loadClass(); });
    document.getElementById('btn-seed').addEventListener('click', () => this.seed());
    document.getElementById('btn-export').addEventListener('click', () => this.exportCsv());
    document.getElementById('btn-aitest').addEventListener('click', () => this.aiTest());
    document.getElementById('btn-reload').addEventListener('click', () => this.loadClass());
    document.getElementById('btn-open-code').addEventListener('click', () => this.openCode());
    document.getElementById('btn-refresh-code').addEventListener('click', () => this.loadCode());
    document.getElementById('lec-ws').addEventListener('click', (e) => { const b = e.target.closest('[data-ws-toggle]'); if (b) this.toggleWs(b.dataset.wsToggle, b.dataset.next); });
    document.getElementById('lec-ws').addEventListener('change', (e) => { const c = e.target.closest('[data-ws-card]'); if (c && e.target.dataset.policy) this.savePolicy(c); });
    document.getElementById('lec-subs').addEventListener('click', (e) => {
      const g = e.target.closest('[data-grant]'); if (g) return this.grantRetake(g.dataset.grant);
      const b = e.target.closest('[data-sub]'); if (b) this.openSubmission(b.dataset.sub);
    });
    document.getElementById('sub-detail').addEventListener('click', (e) => {
      if (e.target.id === 'sub-close') document.getElementById('sub-detail').classList.add('hidden');
      if (e.target.id === 'btn-regrade') this.regrade();
      if (e.target.id === 'btn-grant') this.grantRetake(e.target.dataset.grantId);
      const o = e.target.closest('[data-sub-open]'); if (o) this.openSubmission(o.dataset.subOpen);
    });
    document.getElementById('sub-detail').addEventListener('submit', (e) => { if (e.target.id === 'grade-form') { e.preventDefault(); this.grade(); } });
    this.loadClasses();
  }

  async loadClasses() {
    try { const d = await this.api.listClasses(this.token); this.view.renderClassOptions(Array.isArray(d) ? d : (d.classes || [])); }
    catch (err) { this.view.msg(err.message, 'err'); }
  }

  async loadClass() {
    try { this.view.renderLecturer(await this.api.khtdLecturerList(this.token, this.classId)); }
    catch (err) { this.view.msg(err.message, 'err'); }
    this.loadCode();
  }

  /* Mã vào lớp = mã điểm danh đang mở của lớp (một mã cho cả điểm danh và làm phiếu). */
  async loadCode() {
    clearTimeout(this._codeTimer);
    if (!this.classId) return this.view.renderCode(null);
    try {
      const d = await this.api.khtdActiveCode(this.token, this.classId);
      this.view.renderCode(d);
      if (d.key) this._codeTimer = setTimeout(() => this.loadCode(), 60000);   // tự cập nhật khi mã hết hạn
    } catch (err) { this.view.msg(err.message, 'err'); }
  }

  async openCode() {
    const sid = document.getElementById('code-session').value;
    if (!sid) return this.view.msg('Chọn buổi học để mở mã.', 'err');
    try {
      const info = await this.api.openAttendance(this.token, sid, {});
      this.view.msg('Đã mở mã vào lớp (đồng thời là mã điểm danh): ' + info.code, 'ok');
      this.loadCode();
    } catch (err) { this.view.msg(err.message, 'err'); }
  }

  async seed() {
    if (!this.classId) return this.view.msg('Chọn lớp trước.', 'err');
    try { const d = await this.api.khtdSeedWorksheets(this.token, this.classId); this.view.msg(`Đã tạo ${d.created.length} phiếu mặc định.`, 'ok'); this.loadClass(); }
    catch (err) { this.view.msg(err.message, 'err'); }
  }

  async toggleWs(id, next) {
    try { await this.api.khtdSetWorksheetStatus(this.token, id, next, null); this.loadClass(); }
    catch (err) { this.view.msg(err.message, 'err'); }
  }

  /* Lưu "số lần nộp" + "điểm tính" của một phiếu (hai ô chọn trong thẻ phiếu). */
  async savePolicy(card) {
    const id = card.dataset.wsCard;
    const max = card.querySelector('[data-policy="max"]').value;
    const score = card.querySelector('[data-policy="score"]').value;
    try {
      const d = await this.api.khtdSetWorksheetPolicy(this.token, id, max, score);
      this.view.msg(`Đã lưu: ${Number(d.maxAttempts) === 0 ? 'không giới hạn số lần' : d.maxAttempts + ' lần nộp'}, điểm tính ${d.scorePolicy === 'LAST' ? 'lần cuối' : 'cao nhất'}.`, 'ok');
      this.loadClass();
    } catch (err) { this.view.msg(err.message, 'err'); this.loadClass(); }
  }

  /* Cấp thêm 1 lượt làm lại cho sinh viên của bài nộp (vd bấm nhầm nộp). */
  async grantRetake(submissionId) {
    if (!confirm('Cấp thêm 1 lượt làm lại cho sinh viên này? Bài lần trước được giữ; sinh viên vào phiếu, bấm "Làm lại" để sửa và nộp lần mới.')) return;
    try {
      const d = await this.api.khtdGrantRetake(this.token, submissionId);
      let m = `Đã cấp thêm 1 lượt cho ${d.fullName} (${d.mssv}) — phiếu ${d.no}: đã nộp ${d.used}/${d.allowed}, còn ${d.remaining} lượt.`;
      if (!d.worksheetOpen) m += ' Lưu ý: phiếu đang ĐÓNG — bấm "Mở" để sinh viên làm được.';
      this.view.msg(m, d.worksheetOpen ? 'ok' : 'err');
      this.loadClass();
      if (!document.getElementById('sub-detail').classList.contains('hidden')) this.openSubmission(document.getElementById('sub-detail').dataset.sub);
    } catch (err) { this.view.msg(err.message, 'err'); }
  }

  async openSubmission(id) {
    try { this.view.renderSubmission(await this.api.khtdLecturerSubmission(this.token, id)); document.getElementById('sub-detail').scrollIntoView({ behavior: 'smooth' }); }
    catch (err) { this.view.msg(err.message, 'err'); }
  }

  async grade() {
    const id = document.getElementById('sub-detail').dataset.sub;
    const score = parseFloat(document.getElementById('grade-score').value);
    const note = document.getElementById('grade-note').value;
    const mail = document.getElementById('grade-mail').checked;
    try {
      const d = await this.api.khtdLecturerGrade(this.token, id, score, note, mail);
      this.view.msg(`Đã duyệt ${d.finalScore}/10${d.emailSent ? ' và gửi email cho sinh viên.' : '.'}`, 'ok');
      document.getElementById('sub-detail').classList.add('hidden'); this.loadClass();
    } catch (err) { this.view.msg(err.message, 'err'); }
  }

  async aiTest() {
    const box = document.getElementById('ai-test');
    const btn = document.getElementById('btn-aitest');
    btn.disabled = true; btn.textContent = 'Đang kiểm tra…';
    box.classList.remove('hidden'); box.textContent = 'Đang hỏi Google…';
    try {
      const d = await this.api.khtdAiTest(this.token, true);
      box.textContent = JSON.stringify(d, null, 2);
      this.view.msg(d.ket_luan || 'Đã kiểm tra.', d.goi_thu && d.goi_thu.ok ? 'ok' : 'err');
    } catch (err) { box.textContent = 'Lỗi: ' + err.message; this.view.msg(err.message, 'err'); }
    finally { btn.disabled = false; btn.textContent = 'Kiểm tra Gemini'; }
  }

  async regrade() {
    const id = document.getElementById('sub-detail').dataset.sub;
    const btn = document.getElementById('btn-regrade'); btn.disabled = true; btn.textContent = 'Đang chấm…';
    try { await this.api.khtdRegrade(this.token, id); this.view.msg('AI đã chấm lại.', 'ok'); await this.openSubmission(id); this.loadClass(); }
    catch (err) { this.view.msg(err.message, 'err'); }
    finally { btn.disabled = false; btn.textContent = 'Chấm lại bằng AI'; }
  }

  async exportCsv() {
    if (!this.classId) return this.view.msg('Chọn lớp trước.', 'err');
    try {
      const d = await this.api.khtdExportCsv(this.token, this.classId);
      const cols = ['MSSV', 'HoTen', 'Phieu1', 'Phieu2', 'Phieu3', 'Phieu4', 'Phieu5', 'Phieu6'];
      const csv = [cols.join(',')].concat(d.rows.map((r) => cols.map((c) => '"' + String(r[c] == null ? '' : r[c]).replace(/"/g, '""') + '"').join(','))).join('\r\n');
      const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })); a.download = `khtd_phieu_${this.classId}.csv`; a.click();
    } catch (err) { this.view.msg(err.message, 'err'); }
  }
}
