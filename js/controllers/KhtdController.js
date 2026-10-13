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
    document.getElementById('ws-body').addEventListener('input', (e) => { if (!e.target.matches('[data-photo-input]')) this._scheduleAutosave(); });
    // Đếm thao tác dán/gõ (tín hiệu "nguy cơ dùng AI" cho GV, docs/04 mục 31) — chỉ đếm số ký tự, không lưu nội dung clipboard.
    document.getElementById('ws-body').addEventListener('paste', (e) => {
      if (!e.target.matches('textarea')) return;
      const t = (e.clipboardData && e.clipboardData.getData('text')) || '';
      this.view.meta.pasteChars = (this.view.meta.pasteChars | 0) + t.length; this.view.meta.pasteCount = (this.view.meta.pasteCount | 0) + 1;
    });
    document.getElementById('ws-body').addEventListener('keydown', (e) => { if (e.target.matches('textarea') && e.key.length === 1) this.view.meta.keys = (this.view.meta.keys | 0) + 1; });
    document.getElementById('ws-body').addEventListener('change', (e) => {
      if (e.target.matches('[data-photo-input]')) this.uploadPhotos(e.target);
      if (e.target.matches('[data-spec-kind]')) { this.view.setSpecKind(e.target.dataset.r, e.target.value); this._scheduleAutosave(); }
    });
    document.getElementById('ws-body').addEventListener('click', (e) => {
      if (e.target.closest('#btn-exam-start')) return this.startExam();
      const del = e.target.closest('[data-photo-del]'); if (del) return this.removePhoto(del.dataset.sec, Number(del.dataset.row), del.dataset.photoDel);
      const img = e.target.closest('img[data-photo-id]'); if (img) this.view.showPhoto(img.dataset.photoId);
    });
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
    clearTimeout(this.autosave); this._stopTimer();
    try {
      const d = await this.api.khtdListWorksheets(this.token);
      this.view.setStudent(d.student); this.view.renderWorksheetList(d.worksheets, d.examForecast);
    } catch (err) {
      sessionStorage.removeItem('khtd_token'); this.token = null;
      this.view.show('list', false); this.view.show('login', true); this.view.msg(err.message, 'err');
    }
  }

  async openWorksheet(id) {
    try {
      const d = await this.api.khtdGetWorksheet(this.token, id);
      this.view.imgToken = this.token;
      this.ws = d.worksheet; this.schema = d.worksheet.schema;
      this.sub = d.submission; this.attempt = d.attempt || null;
      this.view.show('list', false); this.view.show('ws-panel', true);
      this.view.renderWorksheet(d.worksheet, d.submission, d.attempt, { exam: d.exam || null, forecast: d.forecast || null });
      this._stopTimer();
      const clock = d.exam && d.exam.clock;
      if (clock && d.worksheet.open && (!d.submission || d.submission.Status === 'DRAFT')) this._startTimer(clock);
      window.scrollTo(0, 0);
    } catch (err) { this.view.msg(err.message, 'err'); }
  }

  /* ---------- Luyện thi: bấm giờ (giờ máy chủ; trình duyệt chỉ đếm ngược để hiển thị) ---------- */
  async startExam() {
    const sel = document.getElementById('exam-count'); const btn = document.getElementById('btn-exam-start');
    if (!this.ws || !sel) return;
    btn.disabled = true;
    try {
      const d = await this.api.khtdStartExam(this.token, this.ws.id, Number(sel.value));
      await this.openWorksheet(this.ws.id);
      if (!d.already) this.view.msg(`Bắt đầu! ${d.clock.count} mẫu — ${Math.round(d.clock.limitSec / 60)} phút. Hết giờ bài tự nộp.`, 'ok');
    } catch (err) { this.view.msg(err.message, 'err'); btn.disabled = false; }
  }

  _startTimer(clock) {
    this._deadline = Date.now() + clock.remainingSec * 1000; this._autoSent = false;
    const tick = () => {
      const left = (this._deadline - Date.now()) / 1000;
      this.view.renderExamTimer(left, left <= 0);
      if (left <= 0 && !this._autoSent) { this._autoSent = true; this._stopTimer(); this.submit(true); }
    };
    tick(); this._timer = setInterval(tick, 1000);
  }

  _stopTimer() { clearInterval(this._timer); this._timer = null; }

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
    } catch (err) {
      if (manual) this.view.msg(err.message, 'err');
      if (/hết giờ/i.test(err.message) && this._timer && !this._autoSent) { this._autoSent = true; this._stopTimer(); this.submit(true); }
    }
  }

  async submit(auto = false) {
    if (!this.ws) return;
    const answers = this.view.collectAnswers(this.schema);
    const att = this.attempt || {};
    const attemptNo = this.sub && this.sub.Status === 'DRAFT' ? Number(this.sub.AttemptNo || 1) : 1;
    const ok = auto === true ? true : await this.view.confirmSubmit(Object.assign({
      no: this.ws.no, attemptNo, allowed: att.allowed === undefined ? 1 : att.allowed, extra: att.extra || 0,
      remainingAfter: att.remaining == null ? (att.allowed === undefined ? 0 : null) : Math.max(0, att.remaining - 1),
    }, this.view.countBlanks(this.schema, answers)));
    if (!ok) return;
    clearTimeout(this.autosave);
    const btn = document.getElementById('btn-submit'); btn.disabled = true; btn.textContent = 'Đang nộp và chấm…';
    try {
      const d = await this.api.khtdSubmit(this.token, this.ws.id, answers);
      this._stopTimer();
      let m = `${auto === true ? 'Hết giờ — bài đã tự nộp' : 'Đã nộp thành công'}${d.attemptNo > 1 ? ' lần ' + d.attemptNo : ''}.`;
      if (d.ai) m += d.ai.exam ? ` Dự báo điểm bài thi: ${d.ai.total}/10.` : ` Điểm tạm tính: ${d.ai.total}/10.`;
      if (d.attempt && d.attempt.score != null && (d.attempt.allowed !== 1 || d.attempt.used > 1)) m += ` Điểm phiếu đang tính: ${d.attempt.score}/10.`;
      if (d.emailSent) m += ` Kết quả đã gửi tới ${d.emailTo}.`;
      else if (d.ai) m += ' (Không gửi được email — xem kết quả tại đây.)';
      if (d.aiError) m += ' ' + d.aiError;
      this.view.msg(m, 'ok');
      await this.openWorksheet(this.ws.id);
    } catch (err) { this.view.msg(err.message, 'err'); }
    finally { btn.disabled = false; btn.textContent = 'Nộp phiếu'; }
  }

  /* Nén ảnh như phiếu thực địa Long Hải: canvas, cạnh dài 1200 px, JPEG 70% (~150–400 KB). Bỏ luôn EXIF/GPS. */
  _compress(file) {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file); const img = new Image();
      img.onload = () => {
        const MAX = 1200; const sc = Math.min(1, MAX / Math.max(img.naturalWidth, img.naturalHeight));
        const c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(img.naturalWidth * sc)); c.height = Math.max(1, Math.round(img.naturalHeight * sc));
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url); resolve(c.toDataURL('image/jpeg', 0.7));
      };
      img.onerror = () => { URL.revokeObjectURL(url); reject(new Error(`Không đọc được ảnh "${file.name}". Hãy dùng nút "Chụp ảnh" hoặc chọn ảnh JPG/PNG.`)); };
      img.src = url;
    });
  }

  /* Tải ảnh cho một mẫu (một hàng bảng mô tả), tối đa 3 ảnh, rồi lưu nháp ngay để giữ tham chiếu. */
  async uploadPhotos(input) {
    const sec = input.dataset.sec; const ri = Number(input.dataset.row);
    const files = Array.from(input.files || []); input.value = '';
    if (!files.length || !this.ws) return;
    const room = 3 - this.view._rowPhotos(sec, ri).length;
    if (room <= 0) return this.view.msg('Mỗi mẫu tối đa 3 ảnh — bỏ bớt ảnh cũ (nút ×) rồi thêm.', 'err');
    const take = files.slice(0, room); let done = 0;
    for (const f of take) {
      this.view.setPhotoStatus(sec, ri, `Đang nén và gửi ảnh ${done + 1}/${take.length}…`);
      try {
        const data = await this._compress(f);
        const d = await this.api.khtdUploadPhoto(this.token, this.ws.id, sec, ri, data);
        this.view.addPhoto(sec, ri, d.photoId); done++;
      } catch (err) { this.view.msg(err.message, 'err'); break; }
    }
    this.view.refreshPhotoCell(sec, ri, true);
    if (done) {
      await this.saveDraft(false);
      if (files.length > room) this.view.msg(`Mỗi mẫu tối đa 3 ảnh — đã lấy ${done} ảnh đầu.`, 'info');
    }
  }

  async removePhoto(sec, ri, id) {
    this.view.removePhoto(sec, ri, id);
    this.view.refreshPhotoCell(sec, ri, true);
    await this.saveDraft(false);
  }

  /* Bắt đầu lần làm mới: máy chủ chép bài lần trước sang một bản nháp mới. */
  async retake() {
    if (!this.ws) return;
    const btn = document.getElementById('btn-retake'); btn.disabled = true;
    try {
      const d = await this.api.khtdRetake(this.token, this.ws.id);
      await this.openWorksheet(this.ws.id);
      this.view.msg(this.schema && this.schema.exam
        ? `Lần luyện ${d.attemptNo}: chọn số mẫu rồi bấm “Bắt đầu tính giờ”. Ảnh mẫu lần trước được giữ — thay ảnh nếu luyện mẫu khác.`
        : `Bắt đầu lần ${d.attemptNo}. Bài lần trước đã được chép sang — sửa rồi bấm “Nộp phiếu”.`, 'ok');
    } catch (err) { this.view.msg(err.message, 'err'); }
    finally { btn.disabled = false; }
  }

  /* ================= GIẢNG VIÊN ================= */
  _initLecturer() {
    this.token = sessionStorage.getItem('dd_token');
    if (!this.token) { document.getElementById('need-login').classList.remove('hidden'); return; }
    this.view.imgToken = this.token;
    document.getElementById('lec-main').classList.remove('hidden');
    document.getElementById('class-select').addEventListener('change', (e) => { this.classId = e.target.value; if (this.classId) this.loadClass(); });
    document.getElementById('btn-seed').addEventListener('click', () => this.seed());
    document.getElementById('btn-export').addEventListener('click', () => this.exportCsv());
    document.getElementById('btn-aitest').addEventListener('click', () => this.aiTest());
    document.getElementById('btn-reload').addEventListener('click', () => this.loadClass());
    document.getElementById('btn-open-code').addEventListener('click', () => this.openCode());
    document.getElementById('btn-refresh-code').addEventListener('click', () => this.loadCode());
    document.getElementById('lec-ws').addEventListener('click', (e) => {
      const b = e.target.closest('[data-ws-toggle]'); if (b) return this.toggleWs(b.dataset.wsToggle, b.dataset.next);
      const k = e.target.closest('[data-exam-key-save]'); if (k) return this.saveExamKey(k);
      const p = e.target.closest('[data-practice]'); if (p) return this.loadPractice(p.dataset.practice);
    });
    const pr = document.getElementById('lec-practice');
    if (pr) pr.addEventListener('click', (e) => { if (e.target.id === 'practice-close') pr.classList.add('hidden'); });
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
      const img = e.target.closest('img[data-photo-id]'); if (img) this.view.showPhoto(img.dataset.photoId);
      const pdf = e.target.closest('[data-pdf-sub]'); if (pdf) this.downloadPdf(`submissionId=${encodeURIComponent(pdf.dataset.pdfSub)}`, pdf);
    });
    document.getElementById('lec-stats').addEventListener('click', (e) => {
      const tab = e.target.closest('[data-stats-ws]'); if (tab) { this.view.statsSel = tab.dataset.statsWs; this.view.renderStats(this.stats); return; }
      const pdf = e.target.closest('[data-pdf-ws]'); if (pdf) return this.downloadPdf(`worksheetId=${encodeURIComponent(pdf.dataset.pdfWs)}`, pdf);
      const b = e.target.closest('[data-sub]'); if (b) this.openSubmission(b.dataset.sub);
    });
    document.getElementById('sub-detail').addEventListener('submit', (e) => { if (e.target.id === 'grade-form') { e.preventDefault(); this.grade(); } });
    this.loadClasses();
  }

  async loadClasses() {
    try { const d = await this.api.listClasses(this.token); this.view.renderClassOptions(Array.isArray(d) ? d : (d.classes || [])); }
    catch (err) { this.view.msg(err.message, 'err'); }
  }

  async loadClass() {
    this.view.renderPracticeBoard(null);
    try { this.view.renderLecturer(await this.api.khtdLecturerList(this.token, this.classId)); }
    catch (err) { this.view.msg(err.message, 'err'); }
    this.loadCode();
    this.loadStats();
  }

  async loadStats() {
    if (!this.classId) return;
    try { this.stats = await this.api.khtdStats(this.token, this.classId); this.view.renderStats(this.stats); }
    catch (err) { this.view.msg('Thống kê: ' + err.message, 'err'); }
  }

  /* Tải PDF từ khtd/xuat-pdf.php (token qua header X-Token, không nằm trong URL). */
  async downloadPdf(query, btn) {
    const old = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Đang tạo PDF…'; }
    try {
      const r = await fetch('xuat-pdf.php?' + query, { headers: { 'X-Token': this.token } });
      if (!r.ok) throw new Error((await r.text()) || ('Lỗi ' + r.status));
      const blob = await r.blob();
      const m = /filename="([^"]+)"/.exec(r.headers.get('Content-Disposition') || '');
      const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = m ? m[1] : 'phieu-diem.pdf';
      document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 30000);
      this.view.msg('Đã tạo ' + a.download, 'ok');
    } catch (err) { this.view.msg('Không tạo được PDF: ' + err.message, 'err'); }
    finally { if (btn) { btn.disabled = false; btn.textContent = old; } }
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
    try { const d = await this.api.khtdSeedWorksheets(this.token, this.classId); this.view.msg(d.created.length ? `Đã tạo ${d.created.length} phiếu mặc định (phiếu số ${d.created.map((x) => x.no).join(', ')}).` : 'Lớp đã đủ các phiếu mặc định.', 'ok'); this.loadClass(); }
    catch (err) { this.view.msg(err.message, 'err'); }
  }

  /* Đáp án bộ mẫu của phiếu luyện thi (mỗi dòng "số hiệu = tên"). */
  async saveExamKey(btn) {
    const card = btn.closest('[data-ws-card]'); const ta = card && card.querySelector('[data-exam-key]');
    if (!ta) return;
    try {
      const d = await this.api.khtdSetExamKey(this.token, btn.dataset.examKeySave, ta.value);
      let m = d.count ? `Đã lưu đáp án ${d.count} mẫu.` : 'Đã xoá đáp án bộ mẫu (chấm theo tên sinh viên ghi).';
      if (d.unknown && d.unknown.length) m += ' Không có trong danh mục mẫu (sẽ so đúng chữ): ' + d.unknown.join('; ');
      this.view.msg(m, d.unknown && d.unknown.length ? 'err' : 'ok');
    } catch (err) { this.view.msg(err.message, 'err'); }
  }

  async loadPractice(wsId) {
    try { this.view.renderPracticeBoard(await this.api.khtdPracticeBoard(this.token, wsId)); document.getElementById('lec-practice').scrollIntoView({ behavior: 'smooth' }); }
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
      let m = `Đã duyệt ${d.finalScore}/10`;
      if (d.emailSent) m += ` và gửi email cho sinh viên${d.pdfName ? ' kèm ' + d.pdfName : ''}.`;
      else if (mail) m += '. Chưa gửi được email (kiểm tra cấu hình SMTP hoặc email sinh viên).';
      else m += '.';
      if (d.pdfError) m += ' Không tạo được PDF: ' + d.pdfError;
      this.view.msg(m, d.pdfError || (mail && !d.emailSent) ? 'err' : 'ok');
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
      const cols = []; d.rows.forEach((r) => Object.keys(r).forEach((k) => { if (!cols.includes(k)) cols.push(k); }));
      if (!cols.length) cols.push('MSSV', 'HoTen');
      const csv = [cols.join(',')].concat(d.rows.map((r) => cols.map((c) => '"' + String(r[c] == null ? '' : r[c]).replace(/"/g, '""') + '"').join(','))).join('\r\n');
      const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })); a.download = `khtd_phieu_${this.classId}.csv`; a.click();
    } catch (err) { this.view.msg(err.message, 'err'); }
  }
}
