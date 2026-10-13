/* js/views/KhtdExamView.js — Phiếu LUYỆN THI nhận diện khoáng vật & đá + dự báo điểm thi (docs/04 mục 33).
   Bổ sung phương thức cho KhtdView (nạp SAU KhtdView.js). Chỉ đụng DOM. */

(function () {
  const KV = ['so_hieu', 'ten', 'tphh', 'do_cung', 'cat_khai', 'tinh_the', 'vet_vach', 'ty_trong', 'mau', 'anh', 'khac'];
  const DA = ['so_hieu', 'ten', 'nhom', 'thanh_phan', 'kien_truc', 'dac_diem', 'dieu_kien'];
  const HINT = {
    so_hieu: 'nhãn trên mẫu', ten: 'vd Orthoclas / Granit', tphh: 'vd KAlSi₃O₈', do_cung: 'vd 6', cat_khai: 'số hướng, góc', tinh_the: 'lăng trụ, tấm…',
    vet_vach: 'màu bột', ty_trong: 'vd 2,6', mau: '', anh: 'thủy tinh, xà cừ…', khac: 'HCl, từ tính, sọc…',
    nhom: 'magma / trầm tích / biến chất', thanh_phan: 'khoáng vật chính', kien_truc: 'hạt thô, vi hạt, phiến…', dac_diem: 'dấu hiệu quyết định', dieu_kien: '',
  };
  const LV = ['1', '0.75', '0.5', '0.25', '0'];
  const num = (v) => (v == null ? '—' : String(v).replace('.', ','));
  const kindOf = (v) => { const t = String(v || '').toLowerCase(); return t.startsWith('kho') ? 'kv' : (t.startsWith('đá') || t === 'da' ? 'da' : ''); };

  Object.assign(KhtdView.prototype, {
    /* Vị trí cột của từng trường trong bảng luyện thi (schema.fields). */
    _fidx(s) { const m = {}; (s.fields || []).forEach((f, i) => { m[f] = i; }); return m; },
    _specSection(schema) { return (schema.sections || []).find((x) => x.layout === 'specimens'); },

    /* Khung giới thiệu: rubric, mẫu in, tổng hợp luyện tập. */
    examIntroHtml(ws, ex) {
      const rt = ws.schema.rubric_text || {};
      const row = (k, label) => `<tr><th class="border bg-slate-50 px-2 py-1 text-left align-top">${label}</th>${LV.map((l) => `<td class="border px-2 py-1 align-top">${this._esc((rt[k] || {})[l] || '')}</td>`).join('')}</tr>`;
      const p = (ex && ex.practice) || {};
      const prac = p.attempts
        ? `<p class="mt-2">Bạn đã luyện <b>${p.attempts}</b> lần (${p.samples} mẫu, ${p.days} ngày) · dự báo cao nhất <b>${num(p.best)}/10</b> · lần gần nhất ${num(p.last)}/10 · đã thạo <b>${p.mastered}</b> loại khoáng vật/đá.
           Điểm cộng đề xuất hiện tại: <b>+${num(p.bonus)}</b> <span class="text-xs text-slate-500">(${this._esc(p.rule || '')}; giảng viên quyết định)</span></p>`
        : '<p class="mt-2">Bạn chưa luyện lần nào. Mỗi lần có thể chọn 1 hoặc nhiều mẫu (tối đa 10).</p>';
      return `<section class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
        <p><b>Phiếu luyện tập — không tính vào điểm tổng thực tập.</b> Chụp ảnh mẫu (điện thoại/máy tính) rồi điền như <i>Bảng nhận diện</i> của bài thi ĐG1.1 (10 mẫu / 30 phút ≈ ${Math.round((ex.secPerSample || 180) / 60)} phút/mẫu).
          Nộp xong có ngay điểm từng mẫu theo rubric, <b>dự báo điểm bài thi</b> và danh sách cần sửa. Kết quả các lần luyện dùng để giảng viên xét <b>điểm cộng</b> và đánh giá thái độ học tập.</p>
        ${prac}
        <p class="mt-2 flex flex-wrap gap-2"><a class="rounded border border-amber-400 bg-white px-2 py-1 font-semibold hover:bg-amber-100" href="phieu/Bang_NhanDien_KhoangVat_Da.pdf" target="_blank" rel="noopener">⬇ Bảng nhận diện (in ra làm)</a>
          <a class="rounded border border-amber-400 bg-white px-2 py-1 font-semibold hover:bg-amber-100" href="phieu/Rubric_NhanDien_KhoangDa.pdf" target="_blank" rel="noopener">⬇ Rubric chấm (PDF)</a></p>
        <details class="mt-2"><summary class="cursor-pointer font-semibold">Rubric chấm từng mẫu (1 điểm/mẫu)</summary>
          <div class="mt-1 overflow-x-auto"><table class="w-full border text-xs"><thead><tr><th class="border bg-slate-100 px-2 py-1">Điểm</th>${LV.map((l) => `<th class="border bg-slate-100 px-2 py-1">${num(l)}</th>`).join('')}</tr></thead>
          <tbody>${row('da', 'Đá')}${row('kv', 'Khoáng vật')}</tbody></table></div></details></section>`;
    },

    examStartHtml(ex) {
      const max = ex.maxSamples || 10; const per = Math.round((ex.secPerSample || 180) / 60);
      const opts = Array.from({ length: max }, (_, i) => i + 1).map((n) => `<option value="${n}" ${n === max ? 'selected' : ''}>${n} mẫu — ${n * per} phút</option>`).join('');
      return `<section class="mt-4 rounded-xl border-2 border-blue-200 bg-blue-50 p-4 text-center">
        <p class="font-semibold text-blue-900">Lần luyện này bạn làm mấy mẫu?</p>
        <p class="mt-1 text-sm text-blue-800">Đồng hồ chạy ngay khi bấm bắt đầu (giờ máy chủ). Hết giờ, bài <b>tự nộp</b> để chấm.</p>
        <div class="mt-3 flex flex-wrap items-center justify-center gap-2"><select id="exam-count" class="rounded border px-2 py-2">${opts}</select>
          <button type="button" id="btn-exam-start" class="rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white hover:bg-blue-800">▶ Bắt đầu tính giờ</button></div></section>`;
    },

    /* Thẻ từng mẫu: ảnh + loại mẫu + các ô theo Bảng nhận diện. */
    specimenCardsHtml(s, a, count, editable) {
      const fx = this._fidx(s); const rows = a[s.id] || [];
      const cell = (ri, f) => ((rows[ri] || [])[fx[f]] || '');
      const box = (ri, f, locked) => `<label class="block text-xs font-semibold text-slate-600">${this._esc(s.columns[fx[f]])}
          <textarea data-sec="${this._esc(s.id)}" data-r="${ri}" data-c="${fx[f]}" rows="${f === 'ten' || f === 'so_hieu' || f === 'do_cung' || f === 'ty_trong' ? 1 : 2}" ${locked ? 'disabled' : ''}
            placeholder="${this._esc(HINT[f] || '')}" class="mt-0.5 w-full resize-y rounded border border-slate-300 p-1 text-sm font-normal text-slate-900 focus:ring-1 focus:ring-blue-300">${this._esc(cell(ri, f))}</textarea></label>`;
      let h = '';
      for (let ri = 0; ri < count; ri++) {
        const k = kindOf(cell(ri, 'loai'));
        const grp = (key, list) => `<div data-kind-group="${key}" data-r="${ri}" class="mt-2 grid gap-2 sm:grid-cols-3 ${k === key ? '' : 'hidden'}">${list.filter((f) => f !== 'so_hieu' && f !== 'ten').map((f) => box(ri, f, !editable)).join('')}</div>`;
        const radio = (val, label) => `<label class="mr-3 whitespace-nowrap"><input type="radio" name="spec-kind-${ri}" data-spec-kind data-r="${ri}" value="${val}" ${k === kindOf(val) ? 'checked' : ''} ${editable ? '' : 'disabled'}/> ${label}</label>`;
        h += `<div class="mt-4 rounded-xl border border-slate-300 p-3" data-spec="${ri}">
          <div class="flex flex-wrap items-center justify-between gap-2"><h4 class="font-bold">Mẫu ${ri + 1}</h4><div class="text-sm">${radio('Khoáng vật', 'Khoáng vật')}${radio('Đá', 'Đá')}</div></div>
          <div class="mt-2 grid gap-3 sm:grid-cols-[8.5rem_1fr]">
            ${this._photoCell(s.id, ri, editable, 'div')}
            <div><div class="grid grid-cols-[7rem_1fr] gap-2">${box(ri, 'so_hieu', !editable)}${box(ri, 'ten', !editable)}</div>
              ${k ? '' : `<p class="mt-2 text-xs text-slate-500" data-kind-hint="${ri}">Chọn <b>Khoáng vật</b> hoặc <b>Đá</b> để hiện các ô cần điền.</p>`}
              ${grp('kv', KV)}${grp('da', DA)}</div></div></div>`;
      }
      return h;
    },

    setSpecKind(ri, val) {
      const k = kindOf(val);
      document.querySelectorAll(`[data-kind-group][data-r="${ri}"]`).forEach((el) => el.classList.toggle('hidden', el.dataset.kindGroup !== k));
      const hint = document.querySelector(`[data-kind-hint="${ri}"]`); if (hint) hint.remove();
    },

    /* Thu bài luyện thi: chỉ các ô của loại mẫu đã chọn (ô của loại kia để trống). */
    collectSpecimens(s) {
      const fx = this._fidx(s);
      return s.rows.map((_, ri) => {
        const out = s.columns.map(() => '');
        const r = document.querySelector(`input[data-spec-kind][data-r="${ri}"]:checked`);
        if (!document.querySelector(`[data-spec="${ri}"]`)) return out;
        const k = r ? kindOf(r.value) : '';
        if (r) out[fx.loai] = r.value;
        const keep = ['so_hieu', 'ten'].concat(k === 'kv' ? KV : (k === 'da' ? DA : []));
        keep.forEach((f) => { const el = document.querySelector(`textarea[data-sec="${s.id}"][data-r="${ri}"][data-c="${fx[f]}"]`); if (el) out[fx[f]] = el.value.trim(); });
        return out;
      });
    },

    renderExamTimer(sec, over) {
      const el = this.$('exam-timer'); if (!el) return;
      const s = Math.max(0, Math.floor(sec));
      const mm = String(Math.floor(s / 60)).padStart(2, '0'); const ss = String(s % 60).padStart(2, '0');
      el.textContent = over ? '⏰ Hết giờ — đang nộp bài…' : `⏱ Còn ${mm}:${ss}`;
      el.className = 'sticky top-0 z-20 -mx-4 -mt-4 mb-2 rounded-t-2xl px-4 py-2 text-center text-lg font-black ' + (s <= 60 ? 'bg-red-600 text-white' : 'bg-blue-700 text-white');
    },

    /* Danh sách "cần sửa / bổ sung" (dùng cho mọi phiếu). */
    fixesHtml(list, title) {
      if (!Array.isArray(list) || !list.length) return '';
      return `<details class="mt-3" open><summary class="cursor-pointer font-semibold text-red-800">${this._esc(title || 'Cần sửa / bổ sung để hoàn thiện bài')} (${list.length})</summary>
        <ol class="mt-1 list-decimal space-y-1 pl-5 text-sm">${list.map((f) => `<li>${f.muc ? `<b>${this._esc(f.muc)}:</b> ` : ''}${this._esc(f.van_de)}${f.cach_sua ? `<br/><span class="text-green-800">→ ${this._esc(f.cach_sua)}</span>` : ''}</li>`).join('')}</ol></details>`;
    },

    /* Dự báo điểm bài thi từ bảng mô tả mẫu của phiếu 1–6. */
    forecastHtml(f) {
      if (!f) return '';
      const items = (f.items || []).filter((x) => x.level < 1).slice(0, 10);
      return `<div class="mt-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-950">
        <p><b>Dự báo điểm bài thi nhận diện mẫu (ĐG1.1)</b> nếu mô tả như bảng của phiếu này: <b class="text-lg">${num(f.score10)}/10</b> <span class="text-xs">(${f.samples} mẫu đã gọi tên, ${f.perfect} mẫu đạt tối đa)</span></p>
        ${items.length ? `<ul class="mt-1 list-disc pl-5">${items.map((x) => `<li>${this._esc(x.mau)} – ${this._esc(x.ten)}: ${num(x.level)}/1 · cần thêm/sửa: ${this._esc((x.thieu || []).join(', '))}</li>`).join('')}</ul>` : ''}
        <p class="mt-1 text-xs">Bài thi chấm mỗi mẫu theo rubric (khoáng vật cần thành phần hóa học, độ cứng, cát khai…; đá cần nhóm đá, kiến trúc, khoáng vật chính, đặc điểm nhận diện). Luyện thêm ở <b>Phiếu luyện thi cuối khóa</b>.</p></div>`;
    },

    /* Kết quả một lần luyện: điểm từng mẫu + đối chiếu chuẩn + cần sửa. */
    examResultHtml(ai) {
      if (!ai) return '';
      const ex = ai.exam || {};
      const rows = (ai.criteria || []).map((c, i) => {
        const d = (ai.doi_chieu || [])[i] || {};
        const bg = c.score >= 1 ? 'bg-green-50' : (c.score > 0 ? 'bg-amber-50' : 'bg-red-50');
        return `<tr class="${bg}"><td class="border px-1 py-1 font-semibold">${this._esc(c.name)}</td><td class="border px-1 py-1">${this._esc(d.bai_lam || '')}</td>
          <td class="border px-1 py-1 text-center font-bold">${num(c.score)}</td><td class="border px-1 py-1">${this._esc(c.comment || '')}</td></tr>`;
      }).join('');
      const t = ex.time || {};
      const time = t.elapsedSec != null ? `Thời gian làm: ${Math.floor(t.elapsedSec / 60)} phút ${t.elapsedSec % 60} giây / giới hạn ${Math.round((t.limitSec || 0) / 60)} phút.` : '';
      const flags = (ai.flags || []).map((x) => `<p class="mt-1 font-semibold text-red-700">⚠ ${this._esc(x)}</p>`).join('');
      return `<p class="mt-1 text-lg">Dự báo điểm bài thi: <b>${num(ai.total)}/10</b> <span class="text-sm">(${ex.count || (ai.criteria || []).length} mẫu · không tính vào điểm tổng)</span></p>
        ${time ? `<p class="text-xs text-slate-600">${this._esc(time)}</p>` : ''}${flags}
        <div class="mt-2 overflow-x-auto"><table class="w-full border text-xs"><thead class="bg-slate-100"><tr><th class="border px-1 py-1 text-left">Mẫu</th><th class="border px-1 py-1 text-left">Bạn gọi tên</th><th class="border px-1 py-1">Điểm</th><th class="border px-1 py-1 text-left">Nhận xét</th></tr></thead><tbody>${rows}</tbody></table></div>
        ${ai.feedback ? `<p class="mt-2 text-sm italic">${this._esc(ai.feedback)}</p>` : ''}
        ${this.fixesHtml(ai.sua_bai)}
        ${(ai.doi_chieu || []).length ? `<details class="mt-2"><summary class="cursor-pointer font-semibold">Dữ liệu chuẩn của các mẫu bạn đã gọi tên</summary><ul class="mt-1 list-disc pl-5 text-xs">${ai.doi_chieu.map((d) => `<li><b>${this._esc(d.muc)}</b>: ${this._esc(d.dap_an)}</li>`).join('')}</ul></details>` : ''}`;
    },

    /* Thẻ dự báo ở danh sách phiếu (SV). */
    forecastCardHtml(ef) {
      if (!ef) return '';
      const pr = (ef.practice || [])[0]; const de = ef.describe || {};
      if (!pr && !de.samples) return '';
      const a = pr && pr.attempts
        ? `Luyện thi: dự báo cao nhất <b>${num(pr.best)}/10</b>, gần nhất ${num(pr.last)}/10 · ${pr.attempts} lần, ${pr.samples} mẫu · đã thạo ${pr.mastered} loại · điểm cộng đề xuất <b>+${num(pr.bonus)}</b>`
        : (pr ? 'Chưa luyện thi lần nào — mở <b>Phiếu ' + this._esc(pr.no) + '</b> để luyện (chọn 1 hoặc nhiều mẫu).' : '');
      const b = de.samples ? `Từ bảng mô tả mẫu ở phiếu ${this._esc((de.sheets || []).join(', '))}: ước tính <b>${num(de.score10)}/10</b> (${de.samples} mẫu, ${de.perfect} mẫu đạt tối đa).` : '';
      return `<div class="mb-3 rounded-xl border border-blue-200 bg-blue-50 p-3 text-sm text-blue-950"><p class="font-bold">🎯 Dự báo điểm bài thi nhận diện mẫu cuối khóa</p>
        ${a ? `<p class="mt-1">${a}</p>` : ''}${b ? `<p class="mt-1">${b}</p>` : ''}<p class="mt-1 text-xs">Chỉ để tự đánh giá — không phải điểm chính thức.</p></div>`;
    },

    /* ---------- Giảng viên ---------- */
    examKeyHtml(w) {
      return `<details class="mt-2 text-xs"><summary class="cursor-pointer font-semibold text-amber-800">Đáp án bộ mẫu (tuỳ chọn) · bảng luyện tập</summary>
        <p class="mt-1 text-slate-600">Mỗi dòng: <code>số hiệu = tên</code> (vd <code>KV01 = thạch anh</code>, <code>D05 = granit</code>). Có đáp án thì mẫu ghi sai tên so với số hiệu được 0 điểm; để trống thì chỉ đối chiếu tính chất với tên sinh viên ghi.</p>
        <textarea data-exam-key rows="5" class="mt-1 w-full rounded border p-1 font-mono">${this._esc(w.ExamKey || '')}</textarea>
        <div class="mt-1 flex flex-wrap gap-2"><button type="button" data-exam-key-save="${this._esc(w.WorksheetID)}" class="rounded bg-amber-600 px-2 py-1 text-white hover:bg-amber-700">Lưu đáp án</button>
          <button type="button" data-practice="${this._esc(w.WorksheetID)}" class="rounded border border-blue-700 px-2 py-1 text-blue-700 hover:bg-blue-50">Bảng luyện tập & thái độ</button></div></details>`;
    },

    renderPracticeBoard(b) {
      const box = this.$('lec-practice'); if (!box) return;
      if (!b) { box.classList.add('hidden'); return; }
      const e = (v) => this._esc(v);
      const tone = { 'Tích cực': 'bg-green-600 text-white', 'Có luyện': 'bg-blue-100 text-blue-900', 'Ít luyện': 'bg-amber-100 text-amber-900', 'Chưa luyện': 'bg-slate-200 text-slate-700' };
      const tally = Object.entries(b.tally || {}).map(([k, v]) => `<span class="mr-1 inline-block rounded px-2 py-0.5 text-xs font-semibold ${tone[k] || ''}">${e(k)}: ${v}</span>`).join('');
      box.innerHTML = `<div class="flex flex-wrap items-baseline justify-between gap-2"><h3 class="font-semibold">Bảng luyện tập – Phiếu ${e(b.no)}: ${e(b.title)}</h3><button type="button" id="practice-close" class="text-slate-500 hover:text-slate-900">✕</button></div>
        <p class="mt-1 text-xs text-slate-600">Điểm luyện tập <b>không</b> vào điểm tổng. Điểm cộng đề xuất: ${e(b.rule)}. Mức thái độ: <i>Tích cực</i> = ≥ 3 lần, ≥ 2 ngày, ≥ 10 mẫu; <i>Có luyện</i> = ≥ 3 mẫu; <i>Ít luyện</i> = ít hơn.</p>
        <p class="mt-1">${tally}</p>
        <div class="mt-2 overflow-x-auto"><table class="w-full text-xs"><thead class="bg-slate-100"><tr>
          <th class="px-1 py-1 text-left">MSSV</th><th class="px-1 py-1 text-left">Họ tên</th><th class="px-1 py-1">Lần</th><th class="px-1 py-1">Ngày</th><th class="px-1 py-1">Mẫu</th><th class="px-1 py-1" title="Số loại khoáng vật/đá đạt ≥ 0,75">Loại thạo</th>
          <th class="px-1 py-1">Lần đầu → gần nhất</th><th class="px-1 py-1">Cao nhất</th><th class="px-1 py-1">Phút</th><th class="px-1 py-1">Điểm cộng ĐX</th><th class="px-1 py-1">Thái độ</th></tr></thead><tbody>
          ${b.rows.map((r) => `<tr class="border-b"><td class="px-1 py-1">${e(r.mssv)}</td><td class="px-1 py-1">${e(r.name)}</td><td class="px-1 py-1 text-center">${r.attempts}</td><td class="px-1 py-1 text-center">${r.days}</td><td class="px-1 py-1 text-center">${r.samples}</td><td class="px-1 py-1 text-center">${r.mastered}</td>
            <td class="px-1 py-1 text-center">${r.first == null ? '—' : num(r.first) + ' → ' + num(r.last)}${r.first != null && r.last > r.first ? ' <span class="text-green-700">▲</span>' : ''}</td><td class="px-1 py-1 text-center font-semibold">${num(r.best)}</td><td class="px-1 py-1 text-center">${r.minutes}</td>
            <td class="px-1 py-1 text-center font-bold">+${num(r.bonus)}</td><td class="px-1 py-1"><span class="rounded px-1.5 ${tone[r.attitude] || ''}">${e(r.attitude)}</span></td></tr>`).join('')}
        </tbody></table></div>`;
      box.classList.remove('hidden');
    },

    /* Bài luyện thi ở trang duyệt (GV): mỗi mẫu một dòng, chỉ các ô của loại đã chọn. */
    specimensReviewHtml(s, a) {
      const fx = this._fidx(s); const rows = a[s.id] || [];
      const n = Math.max(1, Number((a._exam || {}).count) || rows.filter((r) => (r || []).some((c) => c)).length);
      const ph = (a._photos || {})[s.id] || {};
      let h = `<h4 class="mt-3 font-semibold">${this._esc(s.title)}</h4>`;
      for (let ri = 0; ri < n; ri++) {
        const r = rows[ri] || []; const k = kindOf(r[fx.loai]);
        const list = (k === 'da' ? DA : (k === 'kv' ? KV : ['so_hieu', 'ten'])).filter((f) => (r[fx[f]] || '') !== '');
        h += `<div class="mt-2 grid gap-2 rounded border p-2 text-xs sm:grid-cols-[6rem_1fr]"><div><b>Mẫu ${ri + 1}</b><br/>${this._esc(r[fx.loai] || '—')}<div class="mt-1 flex flex-wrap gap-1">${this._thumbsHtml(s.id, ri, false, ph[ri] || [])}</div></div>
          <div>${list.length ? list.map((f) => `<span class="mr-2 inline-block"><span class="text-slate-500">${this._esc(s.columns[fx[f]])}:</span> ${this._esc(r[fx[f]])}</span>`).join('') : '<span class="text-slate-400">(trống)</span>'}</div></div>`;
      }
      return h;
    },
  });
})();
