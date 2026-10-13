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

  /* Nhãn ngắn về số lần làm: "" khi phiếu chỉ cho nộp một lần và chưa được cấp thêm lượt. */
  _attemptBadge(att) {
    if (!att || (att.allowed === 1 && !att.extra)) return '';
    const of = att.allowed == null ? `${att.used} lần (không giới hạn)` : `${att.used}/${att.allowed} lần`;
    return `Đã nộp ${of} · tính điểm ${att.policy === 'LAST' ? 'lần cuối' : 'cao nhất'}`;
  }

  renderWorksheetList(list) {
    const box = this.$('ws-list'); if (!box) return;
    if (!list.length) { box.innerHTML = '<p class="text-slate-500">Lớp của bạn chưa có phiếu nào được mở.</p>'; return; }
    box.innerHTML = list.map((w) => {
      const st = w.SubStatus || 'Chưa làm';
      const att = w.attempt || null;
      const draftNo = att && att.currentNo > 1 && st === 'DRAFT' ? ` lần ${att.currentNo}` : '';
      const label = { DRAFT: `Đang làm${draftNo} (nháp)`, SUBMITTED: 'Đã nộp – chờ chấm', AI_GRADED: 'Đã nộp – có điểm tạm', FINAL: 'Đã có điểm chính thức' }[st] || st;
      const score = w.FinalScore != null ? `<b>${this._esc(w.FinalScore)}</b>/10` : (w.AiScore != null ? `tạm ${this._esc(w.AiScore)}/10` : '');
      const open = w.Status === 'OPEN';
      const badge = this._attemptBadge(att);
      const retake = att && att.canRetake ? '<br/><span class="text-xs font-semibold text-blue-700">Có thể làm lại</span>' : '';
      return `<button data-ws="${this._esc(w.WorksheetID)}" class="ws-item flex w-full items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-left shadow-sm hover:border-blue-400">
        <span><span class="text-xs text-slate-500">${this._esc(w.ClassCode)} · Phiếu ${this._esc(w.No)}</span><br/><span class="font-semibold">${this._esc(w.Title)}</span>${badge ? `<br/><span class="text-xs text-slate-500">${this._esc(badge)}</span>` : ''}</span>
        <span class="shrink-0 text-right text-sm"><span class="${open ? 'text-green-700' : 'text-slate-400'}">${open ? 'Đang mở' : 'Đã đóng'}</span><br/>${this._esc(label)} ${score}${retake}</span></button>`;
    }).join('');
  }

  renderWorksheet(ws, sub, att) {
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
    this.show('btn-retake', !!(att && att.canRetake));
    const rb = this.$('btn-retake');
    if (rb && att) rb.textContent = att.remaining == null ? 'Làm lại (lần mới)' : `Làm lại (còn ${att.remaining} lượt)`;
    this.renderAttempts(sub, att);
    this.renderResult(sub, att);
    if (!ws.open && !locked) this.msg('Phiếu này đã đóng — chỉ xem, không nộp được.', 'err');
  }

  /* Khung "các lần làm": quy định số lần + cách tính điểm + bảng lịch sử. Ẩn khi phiếu chỉ cho nộp 1 lần và chưa nộp lần 2. */
  renderAttempts(sub, att) {
    const box = this.$('ws-attempts'); if (!box) return;
    const multi = att && (att.allowed !== 1 || att.extra > 0 || (att.attempts || []).length > 1);
    if (!multi) { box.classList.add('hidden'); box.innerHTML = ''; return; }
    const rule = att.allowed == null ? 'không giới hạn số lần làm' : `được nộp tối đa <b>${this._esc(att.allowed)} lần</b>${att.extra ? ` (giảng viên cấp thêm ${this._esc(att.extra)})` : ''}`;
    const how = att.policy === 'LAST' ? 'điểm <b>lần nộp cuối cùng</b>' : 'điểm <b>cao nhất</b> trong các lần nộp';
    let h = `<p>Phiếu này ${rule}; điểm phiếu là ${how}.</p>`;
    if (sub && sub.Status === 'DRAFT' && Number(sub.AttemptNo) > 1) {
      h += `<p class="mt-1 font-semibold text-blue-900">Bạn đang làm lần ${this._esc(sub.AttemptNo)} — bài lần trước đã được chép sang, chỉ cần sửa chỗ chưa đúng rồi nộp.${att.policy === 'LAST' ? ' Lưu ý: lần nộp này sẽ thay điểm cũ, kể cả khi thấp hơn.' : ' Nếu lần này thấp hơn, điểm cao nhất vẫn được giữ.'}</p>`;
    }
    const done = (att.attempts || []).filter((a) => a.status !== 'DRAFT');
    if (done.length) {
      h += '<table class="mt-2 w-full text-xs"><thead><tr class="text-left text-slate-500"><th class="py-1 pr-2">Lần</th><th class="py-1 pr-2">Nộp lúc</th><th class="py-1 pr-2">Điểm</th><th class="py-1"></th></tr></thead><tbody>' +
        done.map((a) => `<tr class="border-t border-blue-100${a.counted ? ' font-semibold' : ''}"><td class="py-1 pr-2">${this._esc(a.no)}</td><td class="py-1 pr-2">${this._esc(String(a.submittedAt || '').slice(0, 16))}</td>
          <td class="py-1 pr-2">${a.score == null ? 'chờ chấm' : this._esc(a.score) + '/10' + (a.final ? ' (GV duyệt)' : ' (tạm)')}</td><td class="py-1">${a.counted ? '★ tính điểm' : ''}</td></tr>`).join('') + '</tbody></table>';
    }
    box.innerHTML = h; box.classList.remove('hidden');
  }

  /* Hộp xác nhận nộp bài (thay confirm() để giảm bấm nhầm): nút mặc định là "Quay lại làm tiếp". */
  confirmSubmit(info) {
    return new Promise((resolve) => {
      const wrap = document.createElement('div');
      wrap.className = 'fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4';
      wrap.setAttribute('role', 'dialog'); wrap.setAttribute('aria-modal', 'true');
      const blanks = [];
      if (info.emptyQ) blanks.push(`${info.emptyQ} câu trả lời`);
      if (info.emptyCells) blanks.push(`${info.emptyCells} ô trong bảng`);
      if (info.emptySelf) blanks.push(`${info.emptySelf} mục tự đánh giá`);
      let after;
      if (info.allowed === 1 && !info.extra) after = '<b>Phiếu này chỉ nộp một lần</b> — sau khi nộp không sửa được nữa.';
      else if (info.remainingAfter === 0) after = '<b>Đây là lần nộp cuối cùng</b> — sau khi nộp không làm lại được nữa.';
      else if (info.remainingAfter == null) after = 'Sau khi nộp bạn vẫn có thể bấm “Làm lại” khi phiếu còn mở.';
      else after = `Sau khi nộp bạn còn <b>${this._esc(info.remainingAfter)} lượt</b> làm lại.`;
      wrap.innerHTML = `<div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl">
        <h3 class="text-lg font-bold">Nộp phiếu ${this._esc(info.no)}${info.attemptNo > 1 ? ` – lần ${this._esc(info.attemptNo)}` : ''}?</h3>
        ${blanks.length ? `<p class="mt-2 rounded-lg bg-amber-50 p-2 text-sm text-amber-900">Còn để trống: ${this._esc(blanks.join(', '))}.</p>` : '<p class="mt-2 text-sm text-green-700">Bạn đã điền đủ các ô.</p>'}
        <p class="mt-2 text-sm">${after}</p>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
          <button type="button" data-k="0" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50">Quay lại làm tiếp</button>
          <button type="button" data-k="1" class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">Nộp bài</button>
        </div></div>`;
      const done = (v) => { document.removeEventListener('keydown', onKey); wrap.remove(); resolve(v); };
      const onKey = (e) => { if (e.key === 'Escape') done(false); };
      wrap.addEventListener('click', (e) => { const b = e.target.closest('[data-k]'); if (b) done(b.dataset.k === '1'); else if (e.target === wrap) done(false); });
      document.addEventListener('keydown', onKey);
      document.body.appendChild(wrap);
      wrap.querySelector('[data-k="0"]').focus();
    });
  }

  /* Đếm ô còn trống để báo trong hộp xác nhận nộp. */
  countBlanks(schema, answers) {
    let emptyQ = 0, emptyCells = 0, emptySelf = 0;
    schema.sections.forEach((s) => {
      if (s.type === 'table') (answers[s.id] || []).forEach((r) => r.forEach((c) => { if (!c) emptyCells++; }));
      else if (s.type === 'questions') s.items.forEach((q) => { if (!answers[q.id]) emptyQ++; });
      else if (s.type === 'text') { if (!answers[s.id]) emptyQ++; }
      else if (s.type === 'self') (answers[s.id] || []).forEach((v) => { if (v == null) emptySelf++; });
    });
    return { emptyQ, emptyCells, emptySelf };
  }

  renderResult(sub, att) {
    const box = this.$('ws-result'); if (!box) return;
    if (!sub || sub.Status === 'DRAFT') { box.classList.add('hidden'); return; }
    const ai = sub.ai;
    const multi = att && (att.allowed !== 1 || att.extra > 0 || (att.attempts || []).length > 1);
    let h = `<p class="text-sm">${multi ? `Lần ${this._esc(sub.AttemptNo)} — đ` : 'Đ'}ã nộp lúc <b>${this._esc(sub.SubmittedAt || '')}</b>.</p>`;
    if (sub.FinalScore != null) h += `<p class="mt-1 text-lg">Điểm chính thức${multi ? ' lần này' : ''}: <b>${this._esc(sub.FinalScore)}/10</b></p>${sub.FinalNote ? `<p class="text-sm">Nhận xét GV: ${this._esc(sub.FinalNote)}</p>` : ''}`;
    else if (ai) h += `<p class="mt-1 text-lg">Điểm tạm tính${multi ? ' lần này' : ''} (AI, GV sẽ duyệt): <b>${this._esc(ai.total)}/10</b></p>`;
    else h += '<p class="mt-1 text-sm text-slate-600">Đang chờ giảng viên chấm.</p>';
    if (multi && att.score != null) h += `<p class="text-sm">Điểm phiếu đang được tính: <b>${this._esc(att.score)}/10</b> (lần ${this._esc(att.countedNo)}, ${att.policy === 'LAST' ? 'lần cuối' : 'cao nhất'}).</p>`;
    if (ai) h += `<ul class="mt-2 list-disc pl-5 text-sm">${ai.criteria.map((c) => `<li><b>${this._esc(c.name)}</b>: ${this._esc(c.score)}/${this._esc(c.max)}${c.comment ? ' — ' + this._esc(c.comment) : ''}</li>`).join('')}</ul>${ai.feedback ? `<p class="mt-2 text-sm italic">${this._esc(ai.feedback)}</p>` : ''}`;
    if (ai && sub.FinalScore != null) h += this._doiChieu(ai);
    if (att && att.canRetake) h += `<p class="mt-3 text-sm font-semibold text-blue-800">Muốn cải thiện điểm? Bấm “Làm lại” ở cuối trang${att.remaining != null ? ` (còn ${this._esc(att.remaining)} lượt)` : ''}.</p>`;
    else if (att && att.allowed === 1 && !att.extra) h += '<p class="mt-3 text-xs text-slate-500">Bấm nhầm “Nộp phiếu”? Báo giảng viên để được cấp thêm một lượt làm lại.</p>';
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

  /* Khung mã vào lớp (khtd/quanly.html): mã điểm danh đang mở + chọn buổi để mở mã mới. */
  renderCode(d) {
    const box = this.$('code-box'); if (!box) return;
    const sel = this.$('code-session');
    if (!d) { box.classList.add('hidden'); return; }
    box.classList.remove('hidden');
    const k = d.key;
    this.$('code-text').textContent = k ? k.Code : '----';
    this.$('code-until').textContent = k
      ? `Buổi ${this._esc(k.SessionNo)} · đóng lúc ${String(k.EndTime).substring(11, 16)} · tính trễ sau ${String(k.LateAfter).substring(11, 16)}`
      : 'Lớp chưa có mã đang mở. Chọn buổi học rồi bấm “Mở mã vào lớp”.';
    this.$('code-text').classList.toggle('text-slate-400', !k);
    const opts = (d.sessions || []).map((s) => `<option value="${this._esc(s.SessionID)}">Buổi ${this._esc(s.SessionNo)}${s.Date ? ' – ' + this._esc(s.Date) : ''}${s.Content ? ' – ' + this._esc(String(s.Content).substring(0, 40)) : ''}</option>`).join('');
    if (sel) sel.innerHTML = '<option value="">— Chọn buổi —</option>' + opts;
  }

  renderLecturer(data) {
    const ws = this.$('lec-ws'); const tb = this.$('lec-subs');
    const maxOpts = (cur) => {
      const vals = [1, 2, 3, 5, 10, 0];
      if (!vals.includes(cur)) vals.splice(vals.length - 1, 0, cur);
      return vals.map((v) => `<option value="${v}" ${v === cur ? 'selected' : ''}>${v === 0 ? 'Không giới hạn' : v === 1 ? '1 lần (như cũ)' : v + ' lần'}</option>`).join('');
    };
    ws.innerHTML = data.worksheets.length ? data.worksheets.map((w) => {
      const max = Number(w.MaxAttempts == null ? 1 : w.MaxAttempts);
      return `<div class="rounded-lg border bg-white px-3 py-2 text-sm" data-ws-card="${this._esc(w.WorksheetID)}">
      <div class="flex items-center justify-between gap-2"><span>Phiếu ${this._esc(w.No)} – ${this._esc(w.Title)}</span>
      <span class="shrink-0"><span class="mr-2 ${w.Status === 'OPEN' ? 'text-green-700' : 'text-slate-500'}">${this._esc(w.Status)}</span>
      <button data-ws-toggle="${this._esc(w.WorksheetID)}" data-next="${w.Status === 'OPEN' ? 'CLOSED' : 'OPEN'}" class="rounded border px-2 py-0.5 hover:bg-slate-50">${w.Status === 'OPEN' ? 'Đóng' : 'Mở'}</button></span></div>
      <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-600">
        <label>Số lần nộp <select data-policy="max" class="rounded border px-1 py-0.5">${maxOpts(max)}</select></label>
        <label>Điểm tính <select data-policy="score" class="rounded border px-1 py-0.5" ${max === 1 ? 'title="Chỉ có tác dụng khi sinh viên nộp từ 2 lần (cho làm nhiều lần hoặc được cấp thêm lượt)"' : ''}>
          <option value="BEST" ${w.ScorePolicy !== 'LAST' ? 'selected' : ''}>cao nhất các lần</option><option value="LAST" ${w.ScorePolicy === 'LAST' ? 'selected' : ''}>lần nộp cuối</option></select></label>
      </div></div>`;
    }).join('')
      : '<p class="text-sm text-slate-500">Lớp chưa có phiếu — bấm "Tạo 6 phiếu mặc định".</p>';
    const subs = data.submissions;
    tb.innerHTML = subs.length ? subs.map((s) => {
      const multi = s.AttemptsAllowed !== 1 || Number(s.Extra) > 0 || Number(s.AttemptsUsed) > 1;
      const lan = `${this._esc(s.AttemptNo)}/${s.AttemptsAllowed == null ? '∞' : this._esc(s.AttemptsAllowed)}${multi && s.IsCounted ? ' <span title="Lần được tính điểm" class="text-amber-600">★</span>' : ''}`;
      const grant = s.IsLatest && s.Status !== 'DRAFT' && s.AttemptsAllowed != null
        ? `<button data-grant="${this._esc(s.SubmissionID)}" class="ml-1 rounded border border-blue-600 px-2 py-0.5 text-xs text-blue-700 hover:bg-blue-50" title="Cấp thêm 1 lượt làm lại cho sinh viên này (vd bấm nhầm nộp)">Cho làm lại</button>` : '';
      return `<tr class="border-b hover:bg-slate-50${s.IsLatest ? '' : ' text-slate-500'}">
      <td class="px-2 py-1">${this._esc(s.No)}</td><td class="px-2 py-1 whitespace-nowrap">${lan}</td><td class="px-2 py-1">${this._esc(s.MSSV)}</td><td class="px-2 py-1">${this._esc(s.FullName)}</td>
      <td class="px-2 py-1 text-xs">${this._esc(s.Status)}</td><td class="px-2 py-1 text-right">${s.AiScore != null ? this._esc(s.AiScore) : (s.AiError ? `<span class="cursor-help text-xs text-red-600" title="${this._esc(s.AiError)}">lỗi AI</span>` : '')}</td>
      <td class="px-2 py-1 text-right font-semibold">${s.FinalScore == null ? '' : this._esc(s.FinalScore)}</td><td class="px-2 py-1 text-xs">${s.EmailSentAt ? '✓' : ''}</td>
      <td class="px-2 py-1 whitespace-nowrap"><button data-sub="${this._esc(s.SubmissionID)}" class="rounded border px-2 py-0.5 text-xs hover:bg-slate-100">Xem / chấm</button>${grant}</td></tr>`;
    }).join('')
      : '<tr><td colspan="9" class="px-2 py-3 text-center text-slate-500">Chưa có bài nộp.</td></tr>';
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
    const aiErrH = (!ai && row.aiError) ? `<div class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm"><b>AI chưa chấm được:</b> ${this._esc(row.aiError)} — bấm “Chấm lại bằng AI” sau khi khắc phục, hoặc chấm tay bên dưới.</div>` : '';
    const aiH = ai ? `<div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm"><b>AI đề xuất: ${this._esc(ai.total)}/10</b> (${this._esc(ai.model)})<ul class="mt-1 list-disc pl-5">${ai.criteria.map((c) => `<li>${this._esc(c.name)}: ${this._esc(c.score)}/${this._esc(c.max)} — ${this._esc(c.comment)}</li>`).join('')}</ul><p class="mt-1 italic">${this._esc(ai.feedback)}</p>${(ai.flags || []).length ? `<p class="mt-1 text-red-700">Cờ: ${this._esc(ai.flags.join('; '))}</p>` : ''}${this._doiChieu(ai)}</div>` : '<p class="mt-3 text-sm text-slate-500">Chưa có chấm AI (chưa cấu hình Gemini hoặc lỗi gọi API).</p>';
    const att = row.attempt || null;
    const multi = att && (att.allowed !== 1 || att.extra > 0 || (att.attempts || []).length > 1);
    const nav = multi ? `<div class="mt-2 flex flex-wrap items-center gap-1 text-xs"><span class="text-slate-500">Các lần (${att.allowed == null ? 'không giới hạn' : 'tối đa ' + this._esc(att.allowed)}, tính ${att.policy === 'LAST' ? 'lần cuối' : 'cao nhất'}):</span>` +
      att.attempts.map((a) => `<button type="button" data-sub-open="${this._esc(a.submissionId)}" class="rounded border px-2 py-0.5 ${Number(a.no) === Number(row.AttemptNo) ? 'border-blue-600 bg-blue-50 font-semibold' : 'hover:bg-slate-50'}">Lần ${this._esc(a.no)}${a.status === 'DRAFT' ? ' (nháp)' : a.score != null ? ': ' + this._esc(a.score) : ''}${a.counted ? ' ★' : ''}</button>`).join('') + '</div>' : '';
    const canGrant = att && att.allowed != null && att.currentNo === Number(row.AttemptNo) && row.Status !== 'DRAFT';
    box.innerHTML = `<div class="flex items-start justify-between"><h3 class="text-lg font-bold">Phiếu ${this._esc(row.No)}${multi ? ' · lần ' + this._esc(row.AttemptNo) : ''} · ${this._esc(row.FullName)} (${this._esc(row.MSSV)})</h3><button id="sub-close" class="text-slate-500 hover:text-slate-900">✕</button></div>
      <p class="text-xs text-slate-500">Nộp: ${this._esc(row.SubmittedAt || '')} · Email: ${this._esc(row.Email || '(chưa có)')} · Trạng thái: ${this._esc(row.Status)}${canGrant ? ` · <button type="button" id="btn-grant" data-grant-id="${this._esc(row.SubmissionID)}" class="text-blue-700 underline">Cho làm lại (+1 lượt)</button>` : ''}</p>${nav}${body}${aiH}${aiErrH}
      <form id="grade-form" class="mt-4 grid gap-2 rounded-lg border p-3 sm:grid-cols-[120px_1fr_auto]">
        <label class="text-sm">Điểm chính thức<input id="grade-score" type="number" min="0" max="10" step="0.25" value="${row.FinalScore != null ? this._esc(row.FinalScore) : (ai ? this._esc(ai.total) : '')}" class="mt-1 w-full rounded border px-2 py-1" required/></label>
        <label class="text-sm">Nhận xét của GV (kèm vào email)<textarea id="grade-note" rows="2" class="mt-1 w-full rounded border px-2 py-1">${this._esc(row.FinalNote || '')}</textarea></label>
        <div class="flex flex-col justify-end gap-1 text-sm"><label><input id="grade-mail" type="checkbox" checked ${row.Email ? '' : 'disabled'}/> Gửi email</label><button class="rounded bg-blue-700 px-3 py-1.5 text-white hover:bg-blue-800">Duyệt điểm</button><button type="button" id="btn-regrade" class="rounded border px-3 py-1 text-xs hover:bg-slate-50">Chấm lại bằng AI</button></div>
      </form>`;
    box.dataset.sub = row.SubmissionID;
  }
}
