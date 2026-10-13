/* js/controllers/ArchiveController.js — Lưu trữ cuối học kỳ & so sánh qua các năm (pages/luu-tru.html, docs/04 mục 32). */

class ArchiveController {
  constructor(api) {
    this.api = api; this.token = null; this.classId = null; this.preview = null; this.archives = [];
    this.$ = (id) => document.getElementById(id);
  }

  esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
  num(v, d = 2) { return v == null ? '—' : String(Math.round(v * 10 ** d) / 10 ** d).replace('.', ','); }
  size(b) { return b >= 1048576 ? this.num(b / 1048576, 1) + ' MB' : this.num(b / 1024, 0) + ' KB'; }

  msg(text, kind = 'info') {
    const el = this.$('msg');
    el.className = 'mb-4 rounded-lg border px-3 py-2 text-sm ' + ({ info: 'border-blue-200 bg-blue-50 text-blue-800', ok: 'border-green-200 bg-green-50 text-green-800', err: 'border-red-200 bg-red-50 text-red-800' }[kind]);
    el.textContent = text; el.classList.remove('hidden');
    if (kind !== 'err') setTimeout(() => el.classList.add('hidden'), 8000);
  }

  init() {
    this.token = sessionStorage.getItem('dd_token');
    if (!this.token) { this.$('need-login').classList.remove('hidden'); return; }
    this.$('main').classList.remove('hidden');
    this.$('class-select').addEventListener('change', (e) => { this.classId = e.target.value; this.loadPreview(); });
    this.$('course-select').addEventListener('change', () => this.renderCompare());
    this.$('btn-csv').addEventListener('click', () => this.exportCsv());
    this.$('preview').addEventListener('click', (e) => {
      if (e.target.id === 'btn-package') this.downloadPackage(e.target);
      if (e.target.id === 'btn-reset') this.reset(e.target);
    });
    this.$('preview').addEventListener('input', () => this.updateResetButton());
    this.loadClasses(); this.loadArchives();
  }

  async loadClasses() {
    try {
      const d = await this.api.listClasses(this.token);
      const list = Array.isArray(d) ? d : (d.classes || []);
      this.$('class-select').innerHTML = '<option value="">— Chọn lớp đã kết thúc —</option>' + list.map((c) => `<option value="${this.esc(c.ClassID)}">${this.esc(c.ClassCode)}${c.AcademicYear ? ' · ' + this.esc(c.AcademicYear) : ''}</option>`).join('');
    } catch (err) { this.msg(err.message, 'err'); }
  }

  async loadArchives() {
    try { this.archives = (await this.api.archiveList(this.token)).archives || []; } catch (err) { this.msg(err.message, 'err'); this.archives = []; }
    this.refreshCourses();
  }

  /* Danh sách môn ở khung so sánh: môn đã có lưu trữ + môn của lớp đang chọn ở trên. */
  refreshCourses() {
    const sel = this.$('course-select'); const keep = sel.value;
    const names = {};
    this.archives.forEach((a) => { names[a.courseCode || '—'] = a.courseName || ''; });
    const live = this.preview && this.preview.status !== 'RESET' && this.preview.snapshot ? this.preview.snapshot.class : null;
    if (live) names[live.courseCode || '—'] = names[live.courseCode || '—'] || live.courseName || '';
    const codes = Object.keys(names);
    sel.innerHTML = codes.length ? codes.map((c) => `<option value="${this.esc(c)}">${this.esc(c)} – ${this.esc(names[c])}</option>`).join('') : '<option value="">(chưa có lớp nào được lưu trữ)</option>';
    if (codes.includes(keep)) sel.value = keep; else if (live) sel.value = live.courseCode || '—';
    this.renderCompare();
  }

  async loadPreview() {
    const box = this.$('preview');
    if (!this.classId) { box.innerHTML = ''; return; }
    box.innerHTML = '<p class="text-sm text-slate-500">Đang tính thống kê…</p>';
    try { this.preview = await this.api.archivePreview(this.token, this.classId); this.renderPreview(); this.refreshCourses(); }
    catch (err) { box.innerHTML = ''; this.msg(err.message, 'err'); }
  }

  /* Tóm tắt thống kê ẩn danh (dùng cho xem trước). */
  snapSummary(s) {
    if (!s) return '';
    const a = s.attendance || {}; const g = s.grades || {};
    const ws = (s.worksheets || []).filter((w) => w.score && w.score.n);
    return `<ul class="list-disc space-y-0.5 pl-5 text-sm">
      <li>${this.esc(s.class.students)} sinh viên · ${this.esc(a.sessionsCounted)} buổi điểm danh · tỉ lệ có mặt TB <b>${this.num(a.attendanceRatePct && a.attendanceRatePct.avg, 1)}%</b></li>
      <li>Bảng điểm: ${(g.columns || []).map((c) => `${this.esc(c.name)} TB <b>${this.num(c.avg)}</b>`).join(' · ') || 'chưa có'}${g.total && g.total.n ? ` · tổng TB <b>${this.num(g.total.avg)}</b>` : ''}</li>
      <li>Phiếu học tập: ${ws.map((w) => `P${w.no} TB <b>${this.num(w.score.avg)}</b> (${w.score.n} SV)`).join(' · ') || 'chưa có bài'}</li>
      <li class="text-xs text-slate-500">Kèm phân bố điểm, điểm từng tiêu chí rubric, tự đánh giá, số lần nộp — không có họ tên, MSSV, email.</li></ul>`;
  }

  renderPreview() {
    const p = this.preview; const box = this.$('preview'); const e = (v) => this.esc(v);
    if (p.status === 'RESET') {
      box.innerHTML = `<div class="rounded-xl border border-green-200 bg-green-50 p-3 text-sm">Lớp <b>${e(p.class.ClassCode)}</b> đã được lưu trữ và xoá dữ liệu lúc ${e(p.resetAt)}. Thống kê đã giữ:</div><div class="mt-2">${this.snapSummary(p.snapshot)}</div>`;
      return;
    }
    const pl = p.plan; const pk = pl.package; const c = pl.counts;
    const rows = [['Ghi danh', c.enrollments], ['Buổi học', c.sessions], ['Lượt điểm danh', c.attendance], ['Mã điểm danh', c.attendance_keys], ['Cột điểm', c.grade_columns], ['Điểm', c.grades],
      ['Khiếu nại', c.complaints], ['Phiếu học tập', c.khtd_worksheets], ['Bài làm (mọi lần)', c.khtd_submissions], ['Ảnh mẫu', `${pl.photoFiles} tệp · ${this.size(pl.photoBytes)}`]];
    const pkH = pk
      ? `<p class="text-sm">Đã tạo gói <b>${e(pk.name)}</b> (${this.size(pk.bytes)}) lúc ${e(pk.at)}. Mã kiểm tra SHA-256: <code class="text-xs">${e(String(pk.sha256).slice(0, 16))}…</code> ${pk.fresh && pk.recent ? '<span class="text-green-700">✓ còn dùng được</span>' : '<span class="text-red-700">— cần tải lại</span>'}</p>`
      : '<p class="text-sm text-slate-600">Chưa tải gói nào.</p>';
    box.innerHTML = `
      <ol class="space-y-4">
        <li class="rounded-xl border p-3"><h3 class="font-bold">Bước 1 — Tải gói lưu trữ về máy bàn</h3>
          <p class="mt-1 text-sm text-slate-600">Tệp ZIP gồm: PDF phiếu đánh giá điểm từng phiếu (minh chứng), ảnh mẫu, điểm danh, bảng điểm, điểm phiếu (CSV mở bằng Excel), danh sách sinh viên, toàn bộ dữ liệu gốc (JSON) và thống kê. Lớp đông có ảnh có thể mất 1–3 phút. Nên lưu thêm một bản dự phòng (ổ ngoài hoặc Drive).</p>
          <div class="mt-2 flex flex-wrap items-center gap-3"><button id="btn-package" class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">⬇ Tải gói lưu trữ (ZIP)</button>${pkH}</div></li>
        <li class="rounded-xl border p-3"><h3 class="font-bold">Bước 2 — Những gì sẽ bị xoá khỏi web</h3>
          <div class="mt-2 grid gap-3 md:grid-cols-2">
            <table class="text-sm"><tbody>${rows.map(([k, v]) => `<tr class="border-b"><td class="py-0.5 pr-3 text-slate-600">${e(k)}</td><td class="py-0.5 font-semibold">${e(v)}</td></tr>`).join('')}
              <tr><td class="py-0.5 pr-3 text-slate-600">Hồ sơ sinh viên</td><td class="py-0.5 font-semibold">${e(pl.studentsToDelete)} xoá · ${e(pl.studentsKept)} giữ (còn học lớp khác)</td></tr></tbody></table>
            <div><p class="text-sm font-semibold">Thống kê không tên sẽ được giữ lại:</p>${this.snapSummary(p.snapshot)}</div></div></li>
        <li class="rounded-xl border-2 border-red-200 p-3"><h3 class="font-bold text-red-800">Bước 3 — Xoá dữ liệu học kỳ của lớp ${e(p.class.ClassCode)}</h3>
          ${pl.blockers.length ? `<ul class="mt-1 list-disc pl-5 text-sm text-red-700">${pl.blockers.map((b) => `<li>${e(b)}</li>`).join('')}</ul>` : ''}
          <p class="mt-1 text-sm text-slate-600">Không hoàn tác được trên web. Máy chủ giữ thêm một bản cứu dữ liệu chữ (không gồm ảnh) trong 30 ngày, chỉ khôi phục được bằng tay.</p>
          <div class="mt-2 grid gap-2 sm:grid-cols-3">
            <label class="text-sm sm:col-span-3"><input id="chk-saved" type="checkbox" ${pl.canReset ? '' : 'disabled'}/> Tôi đã mở thử tệp ZIP trên máy và thấy đủ PDF, ảnh, bảng điểm.</label>
            <label class="text-sm">Gõ mã lớp <b>${e(p.class.ClassCode)}</b><input id="confirm-code" autocomplete="off" class="mt-1 w-full rounded border px-2 py-1.5" ${pl.canReset ? '' : 'disabled'}/></label>
            <label class="text-sm">Mật khẩu giảng viên<input id="confirm-pw" type="password" autocomplete="current-password" class="mt-1 w-full rounded border px-2 py-1.5" ${pl.canReset ? '' : 'disabled'}/></label>
            <div class="flex items-end"><button id="btn-reset" disabled class="w-full rounded bg-red-700 px-4 py-2 text-sm font-semibold text-white enabled:hover:bg-red-800 disabled:opacity-40">Xoá dữ liệu học kỳ</button></div>
          </div></li>
      </ol>`;
    this.updateResetButton();
  }

  updateResetButton() {
    const b = this.$('btn-reset'); if (!b || !this.preview || !this.preview.plan) return;
    b.disabled = !(this.preview.plan.canReset && this.$('chk-saved').checked && this.$('confirm-code').value.trim() === this.preview.class.ClassCode && this.$('confirm-pw').value !== '');
  }

  async downloadPackage(btn) {
    const old = btn.textContent; btn.disabled = true; btn.textContent = 'Đang tạo gói… (1–3 phút)';
    try {
      const r = await fetch('../api/luu-tru.php?classId=' + encodeURIComponent(this.classId), { headers: { 'X-Token': this.token } });
      if (!r.ok) throw new Error((await r.text()) || ('Lỗi ' + r.status));
      const blob = await r.blob();
      const m = /filename="([^"]+)"/.exec(r.headers.get('Content-Disposition') || '');
      const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = m ? m[1] : 'goi-luu-tru.zip';
      document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 60000);
      this.msg(`Đã tải ${a.download} (${this.size(blob.size)}). Mở thử tệp trên máy trước khi xoá.`, 'ok');
      await this.loadPreview();
    } catch (err) { this.msg('Không tạo được gói: ' + err.message, 'err'); btn.disabled = false; btn.textContent = old; }
  }

  async reset(btn) {
    const code = this.preview.class.ClassCode;
    if (!confirm(`XOÁ toàn bộ dữ liệu học kỳ của lớp ${code}? Chỉ còn thống kê không tên. Không hoàn tác được trên web.`)) return;
    btn.disabled = true; btn.textContent = 'Đang xoá…';
    try {
      const d = await this.api.archiveReset(this.token, this.classId, this.$('confirm-pw').value, this.$('confirm-code').value.trim());
      this.msg(`Đã lưu trữ lớp ${d.classCode}: xoá ${d.deleted.khtd_submissions || 0} bài làm, ${d.deleted.attendance || 0} lượt điểm danh, ${d.deleted.photoFiles || 0} ảnh, ${d.deleted.students || 0} hồ sơ sinh viên. Bản cứu giữ ${d.safetyDays} ngày trên máy chủ.`, 'ok');
      this.classId = null; this.preview = null; await this.loadClasses(); this.$('preview').innerHTML = ''; await this.loadArchives();
    } catch (err) { this.msg(err.message, 'err'); btn.textContent = 'Xoá dữ liệu học kỳ'; this.updateResetButton(); }
  }

  /* ---------- So sánh qua các năm ---------- */
  compareRows() {
    const course = this.$('course-select').value;
    const rows = this.archives.filter((a) => (a.courseCode || '—') === course).map((a) => ({ label: `${a.academicYear || '?'} · HK ${a.semester || '?'}`, classCode: a.classCode, stats: a.stats, live: false }));
    const p = this.preview;
    if (p && p.status !== 'RESET' && p.snapshot && (p.snapshot.class.courseCode || '—') === course) {
      rows.push({ label: `${p.snapshot.class.academicYear || 'hiện tại'} · HK ${p.snapshot.class.semester || '?'}`, classCode: p.snapshot.class.classCode + ' (đang học)', stats: p.snapshot, live: true });
    }
    return rows;
  }

  renderCompare() {
    const box = this.$('compare'); const e = (v) => this.esc(v);
    const rows = this.compareRows();
    if (!rows.length) { box.innerHTML = '<p class="text-sm text-slate-500">Chưa có lớp nào được lưu trữ cho môn này. Sau khi lưu trữ lớp đầu tiên, các năm sẽ hiện ở đây; lớp đang chọn ở trên cũng được đưa vào để so sánh.</p>'; return; }
    const nos = [...new Set(rows.flatMap((r) => (r.stats.worksheets || []).map((w) => w.no)))].sort((a, b) => a - b);
    const ws = (r, no) => (r.stats.worksheets || []).find((w) => w.no === no);
    const pct = (sc, k) => (sc && sc.n ? this.num(sc.buckets[k] / sc.n * 100, 0) + '%' : '—');
    const head = `<tr class="bg-slate-100"><th class="px-2 py-1 text-left">Học kỳ</th><th class="px-2 py-1 text-left">Lớp</th><th class="px-2 py-1">SV</th><th class="px-2 py-1">Có mặt TB</th><th class="px-2 py-1">Tổng kết TB</th>${nos.map((n) => `<th class="px-2 py-1">P${n} TB</th>`).join('')}<th class="px-2 py-1">% ≥ 8 (TB các phiếu)</th></tr>`;
    const body = rows.map((r) => {
      const s = r.stats; const sheets = nos.map((n) => ws(r, n)).filter((w) => w && w.score.n);
      const hi = sheets.length ? sheets.reduce((t, w) => t + w.score.buckets['≥8'] / w.score.n, 0) / sheets.length * 100 : null;
      return `<tr class="border-b ${r.live ? 'bg-amber-50' : ''}"><td class="px-2 py-1">${e(r.label)}</td><td class="px-2 py-1">${e(r.classCode)}</td><td class="px-2 py-1 text-center">${e(s.class.students)}</td>
        <td class="px-2 py-1 text-center">${s.attendance.attendanceRatePct.avg == null ? '—' : this.num(s.attendance.attendanceRatePct.avg, 1) + '%'}</td><td class="px-2 py-1 text-center">${this.num(s.grades.total.avg)}</td>
        ${nos.map((n) => { const w = ws(r, n); return `<td class="px-2 py-1 text-center">${w && w.score.n ? this.num(w.score.avg) + `<span class="block text-[10px] text-slate-500">n=${w.score.n} · ≥8: ${pct(w.score, '≥8')}</span>` : '—'}</td>`; }).join('')}
        <td class="px-2 py-1 text-center">${hi == null ? '—' : this.num(hi, 0) + '%'}</td></tr>`;
    }).join('');
    const crit = nos.map((n) => {
      const names = [...new Set(rows.flatMap((r) => ((ws(r, n) || {}).criteria || []).map((c) => c.name)))];
      if (!names.length || !rows.some((r) => ((ws(r, n) || {}).criteria || []).some((c) => c.pctOfMax != null))) return '';
      return `<div class="mt-3"><h4 class="text-sm font-semibold">Phiếu ${n} — mức đạt từng tiêu chí rubric (% điểm tối đa)</h4><div class="overflow-x-auto"><table class="mt-1 w-full text-xs"><thead><tr class="bg-slate-100"><th class="px-2 py-1 text-left">Học kỳ</th>${names.map((x) => `<th class="px-2 py-1">${e(x)}</th>`).join('')}<th class="px-2 py-1">Tự đánh giá "Tự tin"</th></tr></thead><tbody>${rows.map((r) => {
        const w = ws(r, n) || {};
        const self = (w.selfAssessment || []).reduce((t, x) => { const tot = x.counts['Chưa'] + x.counts['Tạm được'] + x.counts['Tự tin']; return tot ? [t[0] + x.counts['Tự tin'], t[1] + tot] : t; }, [0, 0]);
        return `<tr class="border-b ${r.live ? 'bg-amber-50' : ''}"><td class="px-2 py-1">${e(r.label)}</td>${names.map((x) => { const c = (w.criteria || []).find((cc) => cc.name === x); const v = c && c.pctOfMax; return `<td class="px-2 py-1 text-center">${v == null ? '—' : `<span class="inline-block h-2 rounded bg-blue-500 align-middle" style="width:${Math.round(v / 2)}px"></span> ${this.num(v, 0)}%`}</td>`; }).join('')}<td class="px-2 py-1 text-center">${self[1] ? this.num(self[0] / self[1] * 100, 0) + '%' : '—'}</td></tr>`;
      }).join('')}</tbody></table></div></div>`;
    }).join('');
    box.innerHTML = `<div class="overflow-x-auto"><table class="w-full text-sm">${head}${body}</table></div>${crit}
      <p class="mt-2 text-xs text-slate-500">Dòng nền vàng: lớp đang học (chưa lưu trữ). "Có mặt" = có mặt + trễ. Điểm phiếu theo lần được tính điểm (cao nhất hoặc lần cuối).</p>`;
  }

  exportCsv() {
    const rows = this.compareRows();
    if (!rows.length) return this.msg('Chưa có dữ liệu để xuất.', 'err');
    const nos = [...new Set(rows.flatMap((r) => (r.stats.worksheets || []).map((w) => w.no)))].sort((a, b) => a - b);
    const head = ['Hoc ky', 'Lop', 'So SV', 'Co mat TB (%)', 'Tong ket TB', 'Tong ket do lech chuan'].concat(nos.flatMap((n) => [`P${n} TB`, `P${n} trung vi`, `P${n} n`, `P${n} >=8 (%)`, `P${n} <5 (%)`]));
    const lines = [head].concat(rows.map((r) => {
      const s = r.stats;
      return [r.label, r.classCode, s.class.students, s.attendance.attendanceRatePct.avg, s.grades.total.avg, s.grades.total.sd].concat(nos.flatMap((n) => {
        const w = (s.worksheets || []).find((x) => x.no === n); const sc = w && w.score;
        return sc && sc.n ? [sc.avg, sc.median, sc.n, Math.round(sc.buckets['≥8'] / sc.n * 100), Math.round(sc.buckets['<5'] / sc.n * 100)] : ['', '', '', '', ''];
      }));
    }));
    const csv = lines.map((l) => l.map((v) => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"').join(',')).join('\r\n');
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }));
    a.download = `so-sanh-qua-cac-nam-${this.$('course-select').value || 'mon'}.csv`; a.click();
  }
}
