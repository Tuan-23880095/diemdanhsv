/* js/views/KhtdView.js — Giao diện phiếu học tập online (SV) và trang duyệt (GV). Chỉ đụng DOM. */

class KhtdView {
  constructor() {
    this.$ = (id) => document.getElementById(id);
    this.photos = {};          // bài đang làm: {sectionId: {rowIdx: [PhotoID]}}
    this.imgToken = '';        // token gửi kèm khi tải ảnh (header X-Token)
    this._blob = {};           // PhotoID → Promise<objectURL>
    this.meta = {};            // đếm thao tác của SV: {pasteChars, pasteCount, keys} (docs/04 mục 31)
    this.statsSel = null;      // phiếu đang xem ở khung thống kê (GV)
  }

  /* ---------- Ảnh mẫu vật (docs/04 mục 30) ---------- */
  _rowPhotos(sec, ri) { return ((this.photos[sec] || {})[ri]) || []; }

  _thumbsHtml(sec, ri, editable, list) {
    const ids = list || this._rowPhotos(sec, ri);
    return ids.map((id) => `<span class="relative inline-block"><img data-photo-id="${this._esc(id)}" alt="Ảnh mẫu" class="h-14 w-14 max-w-none cursor-zoom-in rounded border border-slate-300 bg-slate-100 object-cover"/>` +
      (editable ? `<button type="button" data-photo-del="${this._esc(id)}" data-sec="${this._esc(sec)}" data-row="${ri}" title="Bỏ ảnh này" class="absolute -right-1 -top-1 h-5 w-5 rounded-full bg-red-600 text-xs leading-5 text-white">×</button>` : '') + '</span>').join('');
  }

  /* Ô ảnh của một mẫu — giống ô chụp ảnh của phiếu thực địa Long Hải: viền đứt, mở thẳng camera. */
  _photoCell(sec, ri, editable) {
    const n = this._rowPhotos(sec, ri).length;
    const add = editable && n < 3 ? `<div class="mt-1 flex flex-col gap-1">
        <label class="cursor-pointer rounded border border-dashed border-green-500 bg-green-50 px-1 py-1 text-center text-xs font-semibold text-green-800 hover:bg-green-100">📸 Chụp ảnh<input type="file" accept="image/*" capture="environment" class="hidden" data-photo-input data-sec="${this._esc(sec)}" data-row="${ri}"/></label>
        <label class="cursor-pointer rounded border border-dashed border-slate-400 px-1 py-1 text-center text-xs text-slate-700 hover:bg-slate-50">🖼 Chọn ảnh<input type="file" accept="image/*" multiple class="hidden" data-photo-input data-sec="${this._esc(sec)}" data-row="${ri}"/></label></div>` : '';
    return `<td class="border p-1 align-top" style="min-width:7.5rem" data-photo-cell="${this._esc(sec)}:${ri}"><div class="flex flex-wrap gap-1">${this._thumbsHtml(sec, ri, editable)}</div>${add}<p class="hidden text-xs text-blue-700" data-photo-status></p></td>`;
  }

  refreshPhotoCell(sec, ri, editable = true) {
    const td = document.querySelector(`[data-photo-cell="${CSS.escape(sec + ':' + ri)}"]`); if (!td) return;
    td.outerHTML = this._photoCell(sec, ri, editable);
    this.hydratePhotos(this.$('ws-body'));
  }

  setPhotoStatus(sec, ri, text) {
    const el = document.querySelector(`[data-photo-cell="${CSS.escape(sec + ':' + ri)}"] [data-photo-status]`); if (!el) return;
    el.textContent = text || ''; el.classList.toggle('hidden', !text);
  }

  addPhoto(sec, ri, id) {
    this.photos[sec] = this.photos[sec] || {};
    this.photos[sec][ri] = (this.photos[sec][ri] || []).concat([id]).slice(0, 3);
  }

  removePhoto(sec, ri, id) {
    if (!this.photos[sec] || !this.photos[sec][ri]) return;
    this.photos[sec][ri] = this.photos[sec][ri].filter((x) => x !== id);
  }

  /* Ảnh nằm sau khtd/anh.php có kiểm token → tải bằng fetch + header X-Token (token không lộ trong URL). */
  hydratePhotos(root) {
    if (!root) return;
    root.querySelectorAll('img[data-photo-id]').forEach((img) => {
      if (img.dataset.loaded) return; img.dataset.loaded = '1';
      const id = img.dataset.photoId;
      if (!this._blob[id]) {
        this._blob[id] = fetch('anh.php?id=' + encodeURIComponent(id), { headers: { 'X-Token': this.imgToken } })
          .then((r) => { if (!r.ok) throw new Error(String(r.status)); return r.blob(); })
          .then((b) => URL.createObjectURL(b));
        this._blob[id].catch(() => { delete this._blob[id]; });
      }
      this._blob[id].then((u) => { img.src = u; }).catch(() => { img.alt = 'Lỗi tải ảnh'; img.classList.add('border-red-400'); });
    });
  }

  showPhoto(id) {
    if (!this._blob[id]) return;
    this._blob[id].then((u) => {
      const wrap = document.createElement('div');
      wrap.className = 'fixed inset-0 z-50 flex cursor-zoom-out items-center justify-center bg-slate-900/80 p-4';
      wrap.innerHTML = `<img src="${u}" alt="Ảnh mẫu" class="max-h-full max-w-full rounded shadow-xl"/>`;
      const close = () => { document.removeEventListener('keydown', onKey); wrap.remove(); };
      const onKey = (e) => { if (e.key === 'Escape') close(); };
      wrap.addEventListener('click', close); document.addEventListener('keydown', onKey);
      document.body.appendChild(wrap);
    }).catch(() => {});
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
    this.photos = JSON.parse(JSON.stringify(a._photos || {}));
    this.meta = Object.assign({ pasteChars: 0, pasteCount: 0, keys: 0 }, a._meta || {});
    const parts = ws.schema.sections.map((s) => {
      if (s.type === 'table') {
        const editable = !locked && ws.open;
        const rows = s.rows.map((r, ri) => `<tr><th class="border px-2 py-1 text-left bg-slate-50 whitespace-nowrap">${this._esc(r)}</th>` +
          (s.photos ? this._photoCell(s.id, ri, editable) : '') +
          s.columns.map((c, ci) => `<td class="border p-0"><textarea data-sec="${s.id}" data-r="${ri}" data-c="${ci}" rows="2" ${locked ? 'disabled' : ''} class="w-full resize-y border-0 p-1 text-sm focus:ring-1 focus:ring-blue-300">${this._esc(((a[s.id] || [])[ri] || [])[ci] || '')}</textarea></td>`).join('') + '</tr>').join('');
        const hint = s.photos ? '<p class="text-xs text-slate-600">Mỗi mẫu chụp 1–3 ảnh rõ nét (toàn mẫu và cận cảnh mặt vỡ, cát khai, kiến trúc); ảnh được nén tự động trước khi gửi.</p>' : '';
        return `<section class="mt-5"><h3 class="font-bold">${this._esc(s.title)}</h3>${hint}<div class="table-wrap mt-2 overflow-x-auto"><table class="w-full border text-sm"><thead><tr><th class="border px-2 py-1 bg-slate-100"></th>${s.photos ? '<th class="border px-2 py-1 bg-green-50 text-left">Ảnh mẫu</th>' : ''}${s.columns.map((c) => `<th class="border px-2 py-1 bg-slate-100 text-left">${this._esc(c)}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table></div></section>`;
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
    this.hydratePhotos(this.$('ws-body'));
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
      if (info.noPhoto) blanks.push(`${info.noPhoto} mẫu chưa có ảnh`);
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
    let emptyQ = 0, emptyCells = 0, emptySelf = 0, noPhoto = 0;
    const ph = answers._photos || {};
    schema.sections.forEach((s) => {
      if (s.type === 'table' && s.photos) s.rows.forEach((_, ri) => { if (!((ph[s.id] || {})[ri] || []).length) noPhoto++; });
      if (s.type === 'table') (answers[s.id] || []).forEach((r) => r.forEach((c) => { if (!c) emptyCells++; }));
      else if (s.type === 'questions') s.items.forEach((q) => { if (!answers[q.id]) emptyQ++; });
      else if (s.type === 'text') { if (!answers[s.id]) emptyQ++; }
      else if (s.type === 'self') (answers[s.id] || []).forEach((v) => { if (v == null) emptySelf++; });
    });
    return { emptyQ, emptyCells, emptySelf, noPhoto };
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
    const ph = {};
    Object.keys(this.photos || {}).forEach((sec) => Object.keys(this.photos[sec] || {}).forEach((ri) => {
      if ((this.photos[sec][ri] || []).length) { ph[sec] = ph[sec] || {}; ph[sec][ri] = this.photos[sec][ri]; }
    }));
    if (Object.keys(ph).length) out._photos = ph;
    if (this.meta && (this.meta.pasteCount || this.meta.keys)) out._meta = { pasteChars: this.meta.pasteChars | 0, pasteCount: this.meta.pasteCount | 0, keys: this.meta.keys | 0 };
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
      if (s.type === 'table') {
        const ph = (a._photos || {})[s.id] || {};
        return `<h4 class="mt-3 font-semibold">${this._esc(s.title)}</h4><div class="overflow-x-auto"><table class="w-full border text-xs"><thead><tr><th class="border px-1"></th>${s.photos ? '<th class="border px-1 text-left">Ảnh</th>' : ''}${s.columns.map((c) => `<th class="border px-1 text-left">${this._esc(c)}</th>`).join('')}</tr></thead><tbody>${s.rows.map((r, ri) => `<tr><th class="border px-1 text-left bg-slate-50">${this._esc(r)}</th>${s.photos ? `<td class="border p-1 align-top" style="min-width:4.25rem"><div class="flex flex-wrap gap-1">${this._thumbsHtml(s.id, ri, false, ph[ri] || [])}</div>${(ph[ri] || []).length ? '' : '<span class="text-slate-400">—</span>'}</td>` : ''}${s.columns.map((_, ci) => `<td class="border px-1">${this._esc(((a[s.id] || [])[ri] || [])[ci] || '')}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
      }
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
      <p class="text-xs text-slate-500">Nộp: ${this._esc(row.SubmittedAt || '')} · Email: ${this._esc(row.Email || '(chưa có)')} · Trạng thái: ${this._esc(row.Status)}${canGrant ? ` · <button type="button" id="btn-grant" data-grant-id="${this._esc(row.SubmissionID)}" class="text-blue-700 underline">Cho làm lại (+1 lượt)</button>` : ''}${row.Status !== 'DRAFT' ? ` · <button type="button" data-pdf-sub="${this._esc(row.SubmissionID)}" class="text-blue-700 underline">Tải PDF (bản gửi SV)</button> · <button type="button" data-pdf-sub="${this._esc(row.SubmissionID)}" data-ban="gv" class="text-blue-700 underline">PDF bản GV</button>` : ''}</p>${nav}${body}${aiH}${aiErrH}
      <form id="grade-form" class="mt-4 grid gap-2 rounded-lg border p-3 sm:grid-cols-[120px_1fr_auto]">
        <label class="text-sm">Điểm chính thức<input id="grade-score" type="number" min="0" max="10" step="0.25" value="${row.FinalScore != null ? this._esc(row.FinalScore) : (ai ? this._esc(ai.total) : '')}" class="mt-1 w-full rounded border px-2 py-1" required/></label>
        <label class="text-sm">Nhận xét của GV (kèm vào email)<textarea id="grade-note" rows="2" class="mt-1 w-full rounded border px-2 py-1">${this._esc(row.FinalNote || '')}</textarea></label>
        <div class="flex flex-col justify-end gap-1 text-sm"><label title="Thư kèm phiếu đánh giá điểm PDF"><input id="grade-mail" type="checkbox" checked ${row.Email ? '' : 'disabled'}/> Gửi email + PDF</label><button class="rounded bg-blue-700 px-3 py-1.5 text-white hover:bg-blue-800">Duyệt điểm</button><button type="button" id="btn-regrade" class="rounded border px-3 py-1 text-xs hover:bg-slate-50">Chấm lại bằng AI</button></div>
      </form>`;
    box.dataset.sub = row.SubmissionID;
    this.hydratePhotos(box);
  }

  /* ---------- Thống kê theo buổi (GV, docs/04 mục 31) ---------- */
  renderStats(data) {
    const box = this.$('lec-stats'); if (!box) return;
    const list = (data && data.worksheets) || [];
    const t = this.$('stats-time'); if (t) t.textContent = data && data.generatedAt ? `cập nhật ${String(data.generatedAt).slice(11, 16)}` : '';
    if (!list.length) { box.innerHTML = '<p class="text-sm text-slate-500">Lớp chưa có phiếu.</p>'; return; }
    if (!list.some((w) => w.worksheetId === this.statsSel)) {
      const withSub = list.filter((w) => w.submissions.total > 0);
      this.statsSel = (withSub.length ? withSub[withSub.length - 1] : list[0]).worksheetId;
    }
    const w = list.find((x) => x.worksheetId === this.statsSel);
    const e = (v) => this._esc(v);
    const num = (v) => (v == null ? '—' : String(v).replace('.', ','));
    const who = (arr) => (arr || []).map((x) => `<button type="button" data-sub="${e(x.submissionId)}" class="underline decoration-dotted hover:text-blue-700">${e(x.name)}</button> (${e(x.mssv)})`).join(', ');
    const tabs = list.map((x) => `<button type="button" data-stats-ws="${e(x.worksheetId)}" class="rounded-full border px-3 py-1 text-xs ${x.worksheetId === w.worksheetId ? 'border-blue-700 bg-blue-700 text-white' : 'hover:bg-slate-50'}">Phiếu ${e(x.no)}${x.submissions.total ? ` · ${x.studentsSubmitted}/${x.enrolled}` : ''}</button>`).join('');
    const sb = w.submissions; const sc = w.scores;
    const tile = (label, val, sub, cls) => `<div class="rounded-xl border p-2 ${cls}"><div class="text-xs text-slate-600">${label}</div><div class="text-xl font-bold">${val}</div>${sub ? `<div class="text-xs text-slate-500">${sub}</div>` : ''}</div>`;
    const high = w.aiRisk.filter((r) => r.level === 'Cao').length;
    const tiles = [
      tile('Sinh viên đã làm bài', `${w.studentsSubmitted}/${w.enrolled}`, `${w.studentsDraftOnly} đang nháp · ${w.studentsNotStarted} chưa mở`, 'bg-green-50 border-green-200'),
      tile('Tổng bài nộp', sb.total, sb.total !== w.studentsSubmitted ? 'tính cả các lần làm lại' : '', 'bg-blue-50 border-blue-200'),
      tile('GV đã chấm', sb.final, '', 'bg-green-50 border-green-200'),
      tile('Chưa chấm – chỉ có AI chấm', sb.aiOnly, '', 'bg-amber-50 border-amber-200'),
      tile('Chưa có điểm nào', sb.ungraded, sb.aiError ? `${sb.aiError} bài AI lỗi` : '', sb.ungraded ? 'bg-red-50 border-red-200' : 'bg-slate-50'),
      tile('Nguy cơ dùng AI', w.aiRisk.length, w.aiRisk.length ? `${high} cao · ${w.aiRisk.length - high} trung bình` : 'không có bài đáng ngờ', w.aiRisk.length ? 'bg-pink-50 border-pink-200' : 'bg-slate-50'),
    ].join('');
    const maxB = Math.max(1, ...Object.values(sc.buckets));
    const bars = Object.entries(sc.buckets).map(([k, v]) => `<div class="flex items-center gap-2 text-xs"><span class="w-14 shrink-0 text-right text-slate-600">${e(k)}</span><span class="h-3 rounded bg-blue-500" style="width:${Math.round(v / maxB * 140)}px"></span><span>${v}</span></div>`).join('');
    const scoreH = sc.count
      ? `<div class="grid gap-3 sm:grid-cols-[1fr_auto]"><div class="space-y-1 text-sm">
          <p>Điểm trung bình <b>${num(sc.avg)}</b> · trung vị <b>${num(sc.median)}</b> <span class="text-xs text-slate-500">(${sc.count} SV có điểm, theo lần được tính)</span></p>
          <p>🏆 Cao nhất <b class="text-green-700">${num(sc.max.score)}</b>: ${who(sc.max.students)}</p>
          <p>⬇ Thấp nhất <b class="text-red-700">${num(sc.min.score)}</b>: ${who(sc.min.students)}</p></div>
          <div class="space-y-1">${bars}</div></div>`
      : '<p class="text-sm text-slate-500">Chưa có bài nào có điểm.</p>';
    const special = w.special.length
      ? `<ul class="mt-1 space-y-1 text-sm">${w.special.map((x) => `<li><button type="button" data-sub="${e(x.submissionId)}" class="font-semibold underline decoration-dotted hover:text-blue-700">${e(x.name)}</button> <span class="text-xs text-slate-500">${e(x.mssv)}${x.score != null ? ' · ' + num(x.score) + 'đ' : ''}</span> — ${x.tags.map((g) => `<span class="mr-1 inline-block rounded bg-amber-100 px-1.5 text-xs text-amber-900">${e(g)}</span>`).join('')}</li>`).join('')}</ul>`
      : '<p class="text-sm text-slate-500">Không có.</p>';
    const risk = w.aiRisk.length
      ? `<ul class="mt-1 space-y-1 text-sm">${w.aiRisk.map((x) => `<li><span class="mr-1 inline-block rounded px-1.5 text-xs font-bold ${x.level === 'Cao' ? 'bg-red-600 text-white' : 'bg-amber-200 text-amber-900'}">${e(x.level)} ${e(x.riskScore)}</span><button type="button" data-sub="${e(x.submissionId)}" class="font-semibold underline decoration-dotted hover:text-blue-700">${e(x.name)}</button> <span class="text-xs text-slate-500">${e(x.mssv)}</span><br/><span class="text-xs text-slate-600">${e(x.reasons.join(' · '))}</span></li>`).join('')}</ul>`
      : '<p class="text-sm text-slate-500">Không có bài nào ở mức trung bình trở lên.</p>';
    const missing = w.notStarted.length ? `<details class="mt-2 text-sm"><summary class="cursor-pointer text-slate-600">${w.notStarted.length} sinh viên chưa nộp</summary><p class="mt-1">${w.notStarted.map((x) => `${e(x.name)} (${e(x.mssv)})`).join(', ')}</p></details>` : '';
    box.innerHTML = `<div class="flex flex-wrap gap-1">${tabs}</div>
      <div class="mt-3 flex flex-wrap items-baseline justify-between gap-2"><h3 class="font-semibold">Phiếu ${e(w.no)} – ${e(w.title)}</h3>
        <button type="button" data-pdf-ws="${e(w.worksheetId)}" class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-900" ${w.submissions.total ? '' : 'disabled title="Chưa có bài nộp"'}>⬇ Xuất PDF tất cả bài</button></div>
      <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">${tiles}</div>
      <div class="mt-3">${scoreH}</div>
      <div class="mt-3 grid gap-4 lg:grid-cols-2">
        <div><h4 class="text-sm font-bold">Bài đặc biệt</h4>${special}</div>
        <div><h4 class="text-sm font-bold">Nguy cơ dùng trí tuệ nhân tạo</h4>${risk}
          <p class="mt-1 text-xs text-slate-500">Ước lượng tự động (tỉ lệ nội dung dán, tốc độ viết, văn phong chatbot, ước lượng của Gemini khi chấm). Chỉ để giảng viên xem lại bài — không phải kết luận.</p></div>
      </div>${missing}`;
  }
}
