/* js/views/KhtdView.js — Giao diện phiếu học tập online (SV) và trang duyệt (GV). Chỉ đụng DOM. */

class KhtdView {
  constructor() {
    this.$ = (id) => document.getElementById(id);
  }

  _esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  _doiChieu(ai) {
    if (!ai || !Array.isArray(ai.doi_chieu) || !ai.doi_chieu.length) return '';
    const mau = { 'đúng': 'bg-green-50', 'đúng một phần': 'bg-amber-50', 'thiếu': 'bg-amber-50', 'sai': 'bg-red-50', 'trống': 'bg-slate-100' };
    return `<details class="mt-3" open><summary class="cursor-pointer font-semibold">Đối chiếu với đáp án (${ai.doi_chieu.length} mục)</summary>
      <div class="mt-2 overflow-x-auto"><table class="w-full border text-xs"><thead class="bg-slate-100"><tr>
      <th class="border px-1 py-1 text-left">Mục</th><th class="border px-1 py-1 text-left">Bài làm</th><th class="border px-1 py-1 text-left">Đáp án</th><th class="border px-1 py-1">Kết quả</th></tr></thead><tbody>` +
      ai.doi_chieu.map((d) => `<tr class="${mau[d.ket_qua] || ''}"><td class="border px-1 py-1">${this._esc(d.muc)}</td><td class="border px-1 py-1">${this._esc(d.bai_lam)}</td><td class="border px-1 py-1">${this._esc(d.dap_an)}</td><td class="border px-1 py-1 text-center font-semibold">${this._esc(d.ket_qua)}</td></tr>`).join('') +
      '</tbody></table></div></details>';
  }

  show(id, on = true) { const el = this.$(id); if (el) el.classList.toggle('hidden', !on); }

  msg(text, kind = 'info') {
    const el = this.$('msg'); if (!el) return;
    el.className = 'mt-3 rounded-lg border px-3 py-2 text-sm ' + ({ info: 'border-blue-200 bg-blue-50 text-blue-800', ok: 'border-green-200 bg-green-50 text-green-800', err: 'border-red-200 bg-red-50 text-red-800' }[kind]);
    el.textContent = text; el.classList.remove('hidden');
    if (kind !== 'err') setTimeout(() => el.classList.add('hidden'), 6000);
  }

  /* ---------- Sinh viên ---------- */
  setStudent(st) {
    const el = this.$('who'); if (el) el.textContent = st ? `${st.fullName} · ${st.mssv}${st.email ? ' · ' + st.email : ''}` : '';
  }

  renderWorksheetList(list) {
    const box = this.$('ws-list'); if (!box) return;
    if (!list.length) { box.innerHTML = '<p class="text-slate-500">Lớp của bạn chưa có phiếu nào được mở.</p>'; return; }
    box.innerHTML = list.map((w) => {
      const st = w.SubStatus || 'Chưa làm';
      const label = { DRAFT: 'Đang làm (nháp)', SUBMITTED: 'Đã nộp – chờ chấm', AI_GRADED: 'Đã nộp – có điểm tạm', FINAL: 'Đã có điểm chính thức' }[st] || st;
      const score = w.FinalScore != null ? `<b>${this._esc(w.FinalScore)}</b>/10` : (w.AiScore != null ? `tạm ${this._esc(w.AiScore)}/10` : '');
      const open = w.Status === 'OPEN';
      return `<button data-ws="${this._esc(w.WorksheetID)}" class="ws-item flex w-full items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-3 text-left shadow-sm hover:border-blue-400">
        <span><span class="text-xs text-slate-500">${this._esc(w.ClassCode)} · Phiếu ${this._esc(w.No)}</span><br/><span class="font-semibold">${this._esc(w.Title)}</span></span>
        <span class="text-right text-sm"><span class="${open ? 'text-green-700' : 'text-slate-400'}">${open ? 'Đang mở' : 'Đã đóng'}</span><br/>${this._esc(label)} ${score}</span></button>`;
    }).join('');
  }

  renderWorksheet(ws, sub) {
    this.$('ws-title').textContent = `Phiếu học tập số ${ws.no}: ${ws.title}`;
    const locked = sub && sub.Status !== 'DRAFT';
    const a = (sub && sub.answers) || {};
    const parts = ws.schema.sections.map((s) => {
      if (s.type === 'table') {
        const rows = s.rows.map((r, ri) => `<tr><th class="border px-2 py-1 text-left bg-slate-50 whitespace-nowrap">${this._esc(r)}</th>` +
          s.columns.map((c, ci) => `<td class="border p-0"><textarea data-sec="${s.id}" data-r="${ri}" data-c="${ci}" rows="2" ${locked ? 'disabled' : ''} class="w-full resize-y border-0 p-1 text-sm focus:ring-1 focus:ring-blue-300">${this._esc(((a[s.id] || [])[ri] || [])[ci] || '')}</textarea></td>`).join('') + '</tr>').join('');
        return `<section class="mt-5"><h3 class="font-bold">${this._esc(s.title)}</h3><div class="table-wrap mt-2 overflow-x-auto"><table class="w-full border text-sm"><thead><tr><th class="border px-2 py-1 bg-slate-100"></th>${s.columns.map((c) => `<th class="border px-2 py-1 bg-slate-100 text-left">${this._esc(c)}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table></div></section>`;
      }
      if (s.type === 'questions') {
        return `<section class="mt-5"><h3 class="font-bold">${this._esc(s.title)}</h3>` + s.items.map((q, i) => `<div class="mt-3"><label class="block text-sm"><b>Câu ${i + 1}.</b> ${this._esc(q.text)}</label><textarea data-q="${q.id}" rows="3" ${locked ? 'disabled' : ''} class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm">${this._esc(a[q.id] || '')}</textarea></div>`).join('') + '</section>';
      }
      if (s.type === 'text') {
        return `<section class="mt-5"><h3 class="font-bold">${this._esc(s.title)}</h3><p class="text-sm">${this._esc(s.prompt)}</p><textarea data-q="${s.id}" rows="3" ${locked ? 'disabled' : ''} class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm">${this._esc(a[s.id] || '')}</textarea></section>`;
      }
      if (s.type === 'self') {
        const v = a[s.id] || [];
        return `<section class="mt-5"><h3 class="font-bold">${this._esc(s.title)}</h3><table class="mt-2 w-full text-sm"><tbody>` + s.items.map((it, i) => `<tr class="border-b"><td class="py-1 pr-2">${this._esc(it)}</td>` +
          [0, 1, 2].map((k) => `<td class="whitespace-nowrap px-2"><label><input type="radio" name="self-${s.id}-${i}" data-self="${s.id}" data-i="${i}" value="${k}" ${String(v[i]) === String(k) ? 'checked' : ''} ${locked ? 'disabled' : ''}/> ${['Chưa', 'Tạm được', 'Tự tin'][k]}</label></td>`).join('') + '</tr>').join('') + '</tbody></table></section>';
      }
      return '';
    });
    this.$('ws-body').innerHTML = parts.join('');
    this.show('btn-save', !locked && ws.open); this.show('btn-submit', !locked && ws.open);
    this.renderResult(sub);
    if (!ws.open && !locked) this.msg('Phiếu này đã đóng — chỉ xem, không nộp được.', 'err');
  }

  renderResult(sub) {
    const box = this.$('ws-result'); if (!box) return;
    if (!sub || sub.Status === 'DRAFT') { box.classList.add('hidden'); return; }
    const ai = sub.ai;
    let h = `<p class="text-sm">Đã nộp lúc <b>${this._esc(sub.SubmittedAt || '')}</b>.</p>`;
    if (sub.FinalScore != null) h += `<p class="mt-1 text-lg">Điểm chính thức: <b>${this._esc(sub.FinalScore)}/10</b></p>${sub.FinalNote ? `<p class="text-sm">Nhận xét GV: ${this._esc(sub.FinalNote)}</p>` : ''}`;
    else if (ai) h += `<p class="mt-1 text-lg">Điểm tạm tính (AI, GV sẽ duyệt): <b>${this._esc(ai.total)}/10</b></p>`;
    else h += '<p class="mt-1 text-sm text-slate-600">Đang chờ giảng viên chấm.</p>';
    if (ai) h += `<ul class="mt-2 list-disc pl-5 text-sm">${ai.criteria.map((c) => `<li><b>${this._esc(c.name)}</b>: ${this._esc(c.score)}/${this._esc(c.max)}${c.comment ? ' — ' + this._esc(c.comment) : ''}</li>`).join('')}</ul>${ai.feedback ? `<p class="mt-2 text-sm italic">${this._esc(ai.feedback)}</p>` : ''}`;
    if (ai && sub.FinalScore != null) h += this._doiChieu(ai);
    box.innerHTML = h; box.classList.remove('hidden');
  }

  collectAnswers(schema) {
    const out = {};
    schema.sections.forEach((s) => {
      if (s.type === 'table') {
        out[s.id] = s.rows.map((_, ri) => s.columns.map((_, ci) => { const el = document.querySelector(`textarea[data-sec="${s.id}"][data-r="${ri}"][data-c="${ci}"]`); return el ? el.value.trim() : ''; }));
      } else if (s.type === 'questions') {
        s.items.forEach((q) => { const el = document.querySelector(`textarea[data-q="${q.id}"]`); out[q.id] = el ? el.value.trim() : ''; });
      } else if (s.type === 'text') {
        const el = document.querySelector(`textarea[data-q="${s.id}"]`); out[s.id] = el ? el.value.trim() : '';
      } else if (s.type === 'self') {
        out[s.id] = s.items.map((_, i) => { const el = document.querySelector(`input[data-self="${s.id}"][data-i="${i}"]:checked`); return el ? Number(el.value) : null; });
      }
    });
    return out;
  }

  /* ---------- Giảng viên ---------- */
  renderClassOptions(classes) {
    const sel = this.$('class-select'); if (!sel) return;
    sel.innerHTML = '<option value="">— Chọn lớp —</option>' + classes.map((c) => `<option value="${this._esc(c.ClassID || c.classId)}">${this._esc(c.ClassCode || c.classCode)} – ${this._esc(c.CourseName || c.courseName || '')}</option>`).join('');
  }

  renderLecturer(data) {
    const ws = this.$('lec-ws'); const tb = this.$('lec-subs');
    ws.innerHTML = data.worksheets.length ? data.worksheets.map((w) => `<div class="flex items-center justify-between rounded-lg border bg-white px-3 py-2 text-sm"><span>Phiếu ${this._esc(w.No)} – ${this._esc(w.Title)}</span>
      <span><span class="mr-2 ${w.Status === 'OPEN' ? 'text-green-700' : 'text-slate-500'}">${this._esc(w.Status)}</span>
      <button data-ws-toggle="${this._esc(w.WorksheetID)}" data-next="${w.Status === 'OPEN' ? 'CLOSED' : 'OPEN'}" class="rounded border px-2 py-0.5 hover:bg-slate-50">${w.Status === 'OPEN' ? 'Đóng' : 'Mở'}</button></span></div>`).join('')
      : '<p class="text-sm text-slate-500">Lớp chưa có phiếu — bấm "Tạo 6 phiếu mặc định".</p>';
    const subs = data.submissions;
    tb.innerHTML = subs.length ? subs.map((s) => `<tr class="border-b hover:bg-slate-50">
      <td class="px-2 py-1">${this._esc(s.No)}</td><td class="px-2 py-1">${this._esc(s.MSSV)}</td><td class="px-2 py-1">${this._esc(s.FullName)}</td>
      <td class="px-2 py-1 text-xs">${this._esc(s.Status)}</td><td class="px-2 py-1 text-right">${s.AiScore == null ? '' : this._esc(s.AiScore)}</td>
      <td class="px-2 py-1 text-right font-semibold">${s.FinalScore == null ? '' : this._esc(s.FinalScore)}</td><td class="px-2 py-1 text-xs">${s.EmailSentAt ? '✓' : ''}</td>
      <td class="px-2 py-1"><button data-sub="${this._esc(s.SubmissionID)}" class="rounded border px-2 py-0.5 text-xs hover:bg-slate-100">Xem / chấm</button></td></tr>`).join('')
      : '<tr><td colspan="8" class="px-2 py-3 text-center text-slate-500">Chưa có bài nộp.</td></tr>';
  }

  renderSubmission(row) {
    const box = this.$('sub-detail'); box.classList.remove('hidden');
    const a = row.answers || {}; const ai = row.ai;
    const body = row.schema.sections.map((s) => {
      if (s.type === 'table') return `<h4 class="mt-3 font-semibold">${this._esc(s.title)}</h4><div class="overflow-x-auto"><table class="w-full border text-xs"><thead><tr><th class="border px-1"></th>${s.columns.map((c) => `<th class="border px-1 text-left">${this._esc(c)}</th>`).join('')}</tr></thead><tbody>${s.rows.map((r, ri) => `<tr><th class="border px-1 text-left bg-slate-50">${this._esc(r)}</th>${s.columns.map((_, ci) => `<td class="border px-1">${this._esc(((a[s.id] || [])[ri] || [])[ci] || '')}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
      if (s.type === 'questions') return `<h4 class="mt-3 font-semibold">${this._esc(s.title)}</h4>` + s.items.map((q, i) => `<p class="mt-1 text-sm"><b>Câu ${i + 1}.</b> ${this._esc(q.text)}<br/><span class="whitespace-pre-wrap text-blue-900">${this._esc(a[q.id] || '(trống)')}</span></p>`).join('');
      if (s.type === 'text') return `<h4 class="mt-3 font-semibold">${this._esc(s.title)}</h4><p class="whitespace-pre-wrap text-sm text-blue-900">${this._esc(a[s.id] || '(trống)')}</p>`;
      if (s.type === 'self') return `<h4 class="mt-3 font-semibold">${this._esc(s.title)}</h4><p class="text-sm">${s.items.map((it, i) => `${this._esc(it)}: <b>${['Chưa', 'Tạm được', 'Tự tin'][(a[s.id] || [])[i]] || '—'}</b>`).join(' · ')}</p>`;
      return '';
    }).join('');
    const aiH = ai ? `<div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm"><b>AI đề xuất: ${this._esc(ai.total)}/10</b> (${this._esc(ai.model)})<ul class="mt-1 list-disc pl-5">${ai.criteria.map((c) => `<li>${this._esc(c.name)}: ${this._esc(c.score)}/${this._esc(c.max)} — ${this._esc(c.comment)}</li>`).join('')}</ul><p class="mt-1 italic">${this._esc(ai.feedback)}</p>${(ai.flags || []).length ? `<p class="mt-1 text-red-700">Cờ: ${this._esc(ai.flags.join('; '))}</p>` : ''}${this._doiChieu(ai)}</div>` : '<p class="mt-3 text-sm text-slate-500">Chưa có chấm AI (chưa cấu hình Gemini hoặc lỗi gọi API).</p>';
    box.innerHTML = `<div class="flex items-start justify-between"><h3 class="text-lg font-bold">Phiếu ${this._esc(row.No)} · ${this._esc(row.FullName)} (${this._esc(row.MSSV)})</h3><button id="sub-close" class="text-slate-500 hover:text-slate-900">✕</button></div>
      <p class="text-xs text-slate-500">Nộp: ${this._esc(row.SubmittedAt || '')} · Email: ${this._esc(row.Email || '(chưa có)')} · Trạng thái: ${this._esc(row.Status)}</p>${body}${aiH}
      <form id="grade-form" class="mt-4 grid gap-2 rounded-lg border p-3 sm:grid-cols-[120px_1fr_auto]">
        <label class="text-sm">Điểm chính thức<input id="grade-score" type="number" min="0" max="10" step="0.25" value="${row.FinalScore != null ? this._esc(row.FinalScore) : (ai ? this._esc(ai.total) : '')}" class="mt-1 w-full rounded border px-2 py-1" required/></label>
        <label class="text-sm">Nhận xét của GV (kèm vào email)<textarea id="grade-note" rows="2" class="mt-1 w-full rounded border px-2 py-1">${this._esc(row.FinalNote || '')}</textarea></label>
        <div class="flex flex-col justify-end gap-1 text-sm"><label><input id="grade-mail" type="checkbox" checked ${row.Email ? '' : 'disabled'}/> Gửi email</label><button class="rounded bg-blue-700 px-3 py-1.5 text-white hover:bg-blue-800">Duyệt điểm</button><button type="button" id="btn-regrade" class="rounded border px-3 py-1 text-xs hover:bg-slate-50">Chấm lại bằng AI</button></div>
      </form>`;
    box.dataset.sub = row.SubmissionID;
  }
}
