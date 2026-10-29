/* bdvh — phòng thi trực tiếp (giáo viên): tạo phòng từ đề, chiếu QR, giám sát, kết quả & phân tích câu.
 * Định tuyến hash: #rooms · #exams · #guide · #mon/<room_id> · #res/<room_id>
 */
'use strict';

const G = { exams: [], monTimer: null, monSince: 0, monData: null, feed: [], projOpen: false };

const STATUS = { DRAFT: ['Chưa mở', ''], LOBBY: ['Đang chuẩn bị', 'acc'], RUNNING: ['Đang thi', 'warn'], CLOSED: ['Đã thu bài', 'ok'] };
const ATT = { JOINED: ['Đã vào', ''], READY: ['Sẵn sàng', 'acc'], DOING: ['Đang làm', 'warn'], LOCKED: ['BỊ KHOÁ', 'bad'], SUBMITTED: ['Đã nộp', 'ok'] };
const REASON = { hs_nop: 'HS nộp', het_gio: 'Hết giờ', gv_thu: 'GV thu', vi_pham: 'Vi phạm' };
const QT = { choice: 'Trắc nghiệm', true_false: 'Đúng/Sai', short: 'Trả lời ngắn' };
function badge(map, k) { const x = map[k] || [k, '']; return `<span class="badge ${x[1]}">${esc(x[0])}</span>`; }

async function gapi(action, data = {}) {
  try { return await api(action, data); }
  catch (e) { if (/đăng nhập giảng viên|đăng nhập mật khẩu/i.test(e.message)) showLogin(); throw e; }
}

/* ======================= Đăng nhập ======================= */

async function boot() {
  let ok = false;
  try { ok = (await api('check_teacher_auth', {}, { raw: true })).logged_in; } catch (e) { }
  if (ok) startApp(); else showLogin();
}
function showLogin() {
  stopMonitor(); closeProjector();
  $('#v-login').classList.remove('hidden');
  $('#v-app').classList.add('hidden');
  const go = async () => {
    $('#lg-btn').disabled = true;
    try { await api('teacher_login', { password: $('#lg-pass').value }, { raw: true }); startApp(); }
    catch (e) { toast(e.message, 'bad'); }
    finally { $('#lg-btn').disabled = false; }
  };
  $('#lg-btn').onclick = go;
  $('#lg-pass').onkeydown = e => { if (e.key === 'Enter') go(); };
  $('#lg-pass').focus();
}
function startApp() {
  $('#v-login').classList.add('hidden');
  $('#v-app').classList.remove('hidden');
  $$('#tabs button').forEach(b => b.onclick = () => { location.hash = b.dataset.tab; });
  window.onhashchange = route;
  route();
}
function route() {
  stopMonitor(); closeProjector();
  const [tab, id] = (location.hash.slice(1) || 'rooms').split('/');
  const tabName = { mon: 'rooms', res: 'rooms' }[tab] || tab;
  $$('#tabs button').forEach(b => b.classList.toggle('on', b.dataset.tab === tabName));
  const v = $('#view');
  v.innerHTML = '<div class="empty">Đang tải…</div>';
  const fn = { rooms: viewRooms, exams: viewExams, guide: viewGuide, mon: viewMonitor, res: viewResults }[tab] || viewRooms;
  Promise.resolve(fn(v, id)).catch(e => { v.innerHTML = `<div class="card callout bad">${esc(e.message)}</div>`; });
}

/* ======================= Danh sách phòng ======================= */

async function viewRooms(v) {
  const [rooms, exams] = await Promise.all([gapi('room_list'), gapi('room_exams')]);
  G.exams = exams;
  v.innerHTML = `<div class="row between" style="margin-bottom:12px"><div><h1 style="margin:0">Phòng thi trực tiếp</h1>
      <div class="muted small">Thi tại lớp bằng điện thoại/laptop: quét QR, 5 phút chuẩn bị, giám sát rời trang, khoá bài + PIN.</div></div>
      <button class="btn primary" id="btn-new">＋ Tạo phòng thi</button></div>
    ${rooms.length ? `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Phòng</th><th>Đề</th><th>Trạng thái</th><th class="num">Bài làm</th><th>Thời gian</th><th></th></tr></thead><tbody>
      ${rooms.map(r => `<tr><td><b>${esc(r.title)}</b><div class="tiny muted">${esc(r.label || '')} · <span class="mono">${esc(r.code)}</span></div></td>
        <td class="small">${esc(r.exam_title || '')} ${r.exam_status === 'DRAFT' ? '<span class="badge acc">đề kín</span>' : '<span class="badge warn">đề công khai</span>'}</td>
        <td>${badge(STATUS, r.status)}</td><td class="num">${r.attempts}</td>
        <td class="small">${r.duration_minutes} phút${r.started_at ? '<br><span class="muted">' + fmtDateTime(r.started_at) + '</span>' : ''}</td>
        <td><div class="row" style="gap:6px;justify-content:flex-end">
          ${r.status !== 'CLOSED' ? `<a class="btn sm primary" href="#mon/${r.room_id}">${r.status === 'DRAFT' ? 'Mở phòng' : 'Giám sát'}</a>` : ''}
          ${+r.attempts ? `<a class="btn sm" href="#res/${r.room_id}">Kết quả</a>` : ''}
          <button class="btn sm" data-dup="${r.room_id}" title="Tạo phòng mới cùng đề">⧉</button>
          ${!+r.attempts ? `<button class="btn sm danger" data-del="${r.room_id}">✕</button>` : ''}
        </div></td></tr>`).join('')}</tbody></table></div>`
      : `<div class="card empty">Chưa có phòng thi. Bấm <b>Tạo phòng thi</b>.<br><span class="small">Nên dùng <b>đề kín</b> (tab Đề thi &amp; đề kín) — đề công khai học sinh có thể đã luyện trước.</span></div>`}`;
  $('#btn-new').onclick = () => roomModal(null);
  $$('[data-dup]').forEach(b => b.onclick = () => { const r = rooms.find(x => String(x.room_id) === b.dataset.dup); roomModal({ exam_id: r.exam_id, title: r.title, label: r.label, duration_minutes: r.duration_minutes, prep_minutes: r.prep_minutes }); });
  $$('[data-del]').forEach(b => b.onclick = async () => {
    if (!(await modal({ title: 'Xoá phòng?', html: '<p>Phòng chưa có bài làm nên xoá được.</p>', okText: 'Xoá', danger: true }))) return;
    try { await gapi('room_delete', { room_id: +b.dataset.del }); route(); } catch (e) { toast(e.message, 'bad'); }
  });
}

function roomModal(pre) {
  pre = pre || {};
  const ex = G.exams;
  const s = pre.settings || {};
  const ck = (k, d, label, help) => `<label class="check"><input type="checkbox" data-s="${k}" ${(s[k] ?? d) ? 'checked' : ''}><span>${label}${help ? `<br><span class="tiny muted">${help}</span>` : ''}</span></label>`;
  return modal({
    title: 'Tạo phòng thi', okText: 'Tạo phòng', wide: true,
    html: `<div class="grid2">
      <div style="grid-column:1/-1"><label class="f">Đề thi</label><select id="rm-exam">
        <optgroup label="Đề kín (chỉ làm trong phòng thi)">${ex.filter(e => e.status === 'DRAFT').map(e => `<option value="${e.exam_id}" ${+pre.exam_id === +e.exam_id ? 'selected' : ''}>${esc(e.title)} · ${e.question_count} câu</option>`).join('')}</optgroup>
        <optgroup label="Đề công khai (học sinh có thể đã luyện)">${ex.filter(e => e.status !== 'DRAFT').map(e => `<option value="${e.exam_id}" ${+pre.exam_id === +e.exam_id ? 'selected' : ''}>${esc(e.title)} · ${e.question_count} câu</option>`).join('')}</optgroup>
      </select><div class="f-help">Chưa có đề kín? Tạo ở tab <b>Đề thi &amp; đề kín</b>.</div></div>
      <div><label class="f">Tên bài kiểm tra</label><input type="text" id="rm-title" value="${esc(pre.title || '')}" placeholder="Mặc định = tên đề"></div>
      <div><label class="f">Lớp / nhóm</label><input type="text" id="rm-label" value="${esc(pre.label || '')}" placeholder="VD: Lớp 6A – Toán"></div>
      <div><label class="f">Làm bài (phút)</label><input type="number" id="rm-dur" min="1" max="300" value="${pre.duration_minutes || 15}"></div>
      <div><label class="f">Chuẩn bị (phút)</label><input type="number" id="rm-prep" min="0" max="30" value="${pre.prep_minutes ?? 5}"></div>
    </div>
    <h3 style="margin-top:16px">Chống gian lận</h3>
    <div class="grid2">
      <div><label class="f">Số vi phạm tối đa</label><input type="number" data-s="maxViolations" min="1" max="20" value="${s.maxViolations ?? 3}"></div>
      <div><label class="f">Khi vượt ngưỡng</label><select data-s="onLimit"><option value="lock">Khoá bài — giáo viên mở bằng PIN</option><option value="submit">Tự động nộp bài</option></select></div>
      <div><label class="f">Bỏ qua mất tiêu điểm ngắn hơn (giây)</label><input type="number" data-s="blurGraceSec" min="0" max="30" value="${s.blurGraceSec ?? 3}"></div>
      <div><label class="f">Cho vào muộn tối đa (phút)</label><input type="number" data-s="lateJoinMinutes" min="0" max="120" value="${s.lateJoinMinutes ?? 10}"></div>
    </div>
    <div class="stack" style="margin-top:10px">
      ${ck('requireFullscreen', true, 'Yêu cầu toàn màn hình', 'Laptop, Android. iPhone không hỗ trợ — chỉ dựa vào phát hiện rời trang.')}
      ${ck('shuffleQuestions', true, 'Xáo thứ tự câu theo từng học sinh')}
      ${ck('shuffleOptions', true, 'Xáo phương án A–D', 'Câu có "Tất cả các ý trên", "Cả A và B"… giữ nguyên thứ tự.')}
      ${ck('allowBack', true, 'Cho quay lại câu trước')}
      ${ck('watermark', true, 'Hình mờ tên học sinh trên màn hình')}
      ${ck('showScore', true, 'Hiện điểm ngay khi nộp')}
    </div>`,
    onOk: async bg => {
      const settings = {};
      $$('[data-s]', bg).forEach(el => { settings[el.dataset.s] = el.type === 'checkbox' ? el.checked : (el.type === 'number' ? +el.value : el.value); });
      const r = await gapi('room_save', { exam_id: +$('#rm-exam', bg).value, title: $('#rm-title', bg).value, label: $('#rm-label', bg).value,
        duration_minutes: +$('#rm-dur', bg).value, prep_minutes: +$('#rm-prep', bg).value, settings });
      location.hash = 'mon/' + r.room_id;
    },
  });
}

/* ======================= Đề thi & đề kín ======================= */

async function viewExams(v) {
  G.exams = await gapi('room_exams');
  v.innerHTML = `<div class="row between" style="margin-bottom:12px"><div><h1 style="margin:0">Đề thi &amp; đề kín</h1>
      <div class="muted small"><b>Đề kín</b> không hiện ở trang luyện tập, chỉ làm được trong phòng thi — dùng cho kiểm tra thật.</div></div>
      <div class="row"><button class="btn" id="ex-tpl">⬇ File mẫu</button><button class="btn primary" id="ex-imp">⬆ Nhập đề kín từ Excel</button></div></div>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Đề</th><th>Loại</th><th class="num">Câu</th><th>Dạng câu</th><th class="num">Phút</th><th></th></tr></thead><tbody>
    ${G.exams.map(e => `<tr><td><b>${esc(e.title)}</b><div class="tiny muted mono">${esc(e.exam_code)} · ${esc(e.grade)} · ${esc(e.subject)}</div></td>
      <td>${e.status === 'DRAFT' ? '<span class="badge acc">Đề kín</span>' : '<span class="badge">Công khai</span>'}</td>
      <td class="num">${e.question_count}</td><td class="small">${Object.entries(e.types).map(([t, c]) => `${QT[t] || t}: ${c}`).join(' · ')}</td>
      <td class="num">${e.time_minutes}</td><td><button class="btn sm" data-pv="${e.exam_id}">Xem</button></td></tr>`).join('')}</tbody></table></div>`;
  $('#ex-imp').onclick = importModal;
  $('#ex-tpl').onclick = () => downloadCSV('mau_de_kin.csv', [IMPORT_HEAD,
    ['choice', 'Giá trị của $2^3$ là:', '6', '8', '9', '5', 'B', '$2^3 = 2 \\cdot 2 \\cdot 2 = 8$', 'Phần I'],
    ['true_false', 'Xét tính đúng sai của các khẳng định:', '$\\sqrt{4}=2$', '$1>2$', '0 là số tự nhiên', '$\\pi$ là số hữu tỉ', 'Đ,S,Đ,S', 'Ý d sai vì $\\pi$ vô tỉ', 'Phần II'],
    ['short', 'Tính $7 \\cdot 8$', '', '', '', '', '56', '', 'Phần III']]);
  $$('[data-pv]').forEach(b => b.onclick = async () => {
    const e = await gapi('room_exam_preview', { exam_id: +b.dataset.pv });
    modal({
      title: e.title, cancelText: 'Đóng', hideOk: true, wide: true,
      html: e.questions.map((q, i) => `<div class="rv-item"><b>Câu ${i + 1}</b> <span class="badge">${QT[q.type]}</span> ${q.section ? `<span class="tiny muted">${esc(q.section)}</span>` : ''}
        <div class="q-stem" style="font-size:1rem">${esc(q.q)}</div>
        ${q.type === 'choice' ? q.opts.map((o, k) => `<div class="rv-opt ${k === +q.ans ? 'key' : ''}">${'ABCDEF'[k]}. ${esc(o)}</div>`).join('') : ''}
        ${q.type === 'true_false' ? q.items.map((o, k) => `<div class="rv-opt">${'abcdef'[k]}) ${esc(o)} — <b>${q.ans[k] ? 'Đúng' : 'Sai'}</b></div>`).join('') : ''}
        ${q.type === 'short' ? `<div class="rv-opt key">Đáp án: ${esc(q.ans)}</div>` : ''}
        ${q.exp ? `<div class="expl">💡 ${esc(q.exp)}</div>` : ''}</div>`).join(''),
      onOpen: bg => typeset(bg),
    });
  });
}

const IMPORT_HEAD = ['loai', 'cau_hoi', 'A', 'B', 'C', 'D', 'dap_an', 'giai_thich', 'phan'];
function tableToRows(text) {
  const rows = parseTable(text);
  if (rows.length < 2) throw new Error('Cần dòng tiêu đề và ít nhất 1 câu.');
  const head = rows[0].map(deaccent);
  const at = (r, names) => { for (const n of names) { const i = head.indexOf(n); if (i >= 0) return (r[i] || '').trim(); } return ''; };
  if (head.indexOf('cau_hoi') < 0) throw new Error('Không thấy cột "cau_hoi" — dòng đầu phải là tiêu đề như file mẫu.');
  return rows.slice(1).map(r => {
    let type = deaccent(at(r, ['loai'])) || 'choice';
    type = { trac_nghiem: 'choice', tn: 'choice', dung_sai: 'true_false', ds: 'true_false', tra_loi_ngan: 'short', ngan: 'short' }[type] || type;
    return { type, q: at(r, ['cau_hoi']), opts: ['a', 'b', 'c', 'd', 'e', 'f'].map(k => at(r, [k])).filter(Boolean), ans: at(r, ['dap_an']), exp: at(r, ['giai_thich']), section: at(r, ['phan']) };
  });
}
function importModal() {
  let rows = null;
  modal({
    title: 'Nhập đề kín', okText: 'Tạo đề kín', wide: true,
    html: `<div class="grid2"><div style="grid-column:1/-1"><label class="f">Tên đề</label><input type="text" id="im-title" placeholder="VD: Kiểm tra 15 phút – Toán 6 – Chương 1"></div>
      <div><label class="f">Khối</label><select id="im-grade">${['L06', 'L07', 'L08', 'L09', 'L10', 'L11', 'L12', 'DGNL'].map(g => `<option>${g}</option>`).join('')}</select></div>
      <div><label class="f">Môn</label><select id="im-subj"><option value="TOAN">Toán</option><option value="KHTN">KHTN</option><option value="DGNL_TONGHOP">ĐGNL</option></select></div>
      <div><label class="f">Thời gian gợi ý (phút)</label><input type="number" id="im-min" value="15" min="1" max="300"></div></div>
      <p class="small" style="margin-top:12px">Dán bảng từ <b>Excel / Google Sheets</b> (có dòng tiêu đề) hoặc chọn file CSV. Cột: <span class="mono tiny">${IMPORT_HEAD.join(' | ')}</span></p>
      <ul class="small muted"><li><b>loai</b>: choice (trắc nghiệm A–D) · true_false (4 ý a–d đặt ở cột A–D, <b>dap_an</b> dạng Đ,S,Đ,S) · short (trả lời ngắn)</li>
        <li>Công thức viết LaTeX giữa hai dấu $, ví dụ <span class="mono">$\\frac{3}{4}$</span>. Cột <b>phan</b> (tuỳ chọn): Phần I/II/III.</li></ul>
      <div class="row" style="margin-bottom:8px"><label class="btn sm">📄 Chọn file CSV<input type="file" id="im-file" accept=".csv,.tsv,.txt" class="hidden"></label></div>
      <textarea id="im-text" rows="8" placeholder="Dán bảng vào đây…" style="font-family:var(--mono);font-size:.82rem"></textarea>
      <div class="row" style="margin-top:8px"><button class="btn" id="im-check">Kiểm tra</button><span id="im-res" class="small"></span></div>`,
    onOpen: bg => {
      $('#im-file', bg).onchange = async ev => { const f = ev.target.files[0]; if (f) { $('#im-text', bg).value = await f.text(); $('#im-check', bg).click(); } };
      $('#im-check', bg).onclick = async () => {
        try {
          rows = tableToRows($('#im-text', bg).value);
          const r = await gapi('room_import_exam', { title: $('#im-title', bg).value || 'kiểm tra', rows, dryRun: true });
          $('#im-res', bg).innerHTML = r.errors.length
            ? `<span style="color:var(--bad)"><b>${r.errors.length} dòng lỗi</b> (${r.valid} hợp lệ):</span><br>` + r.errors.slice(0, 20).map(x => `Dòng ${x.row + 1}: ${esc(x.message)}`).join('<br>')
            : `<span style="color:var(--ok)">✓ ${r.valid} câu hợp lệ.</span>`;
        } catch (e) { rows = null; $('#im-res', bg).innerHTML = `<span style="color:var(--bad)">${esc(e.message)}</span>`; }
      };
    },
    onOk: async bg => {
      if (!$('#im-title', bg).value.trim()) throw new Error('Cần nhập tên đề.');
      rows = tableToRows($('#im-text', bg).value);
      const r = await gapi('room_import_exam', { title: $('#im-title', bg).value, grade: $('#im-grade', bg).value, subject: $('#im-subj', bg).value, time_minutes: +$('#im-min', bg).value, rows });
      if (r.errors.length) { $('#im-check', bg).click(); throw new Error('Còn dòng lỗi — chưa tạo đề.'); }
      toast(`Đã tạo đề kín ${r.valid} câu.`, 'ok');
      route();
    },
  });
}

/* ======================= Giám sát ======================= */

function stopMonitor() { clearInterval(G.monTimer); G.monTimer = null; }
async function viewMonitor(v, id) {
  G.monSince = 0; G.feed = [];
  v.innerHTML = `<div id="mon-head"></div><div id="mon-body"></div>`;
  await refreshMonitor(+id);
  G.monTimer = setInterval(() => refreshMonitor(+id).catch(() => { }), 3000);
}
async function refreshMonitor(id) {
  const d = await gapi('room_monitor', { room_id: id, since_event: G.monSince });
  G.monData = d;
  if (d.events.length) { G.monSince = d.events[0].event_id; G.feed = d.events.concat(G.feed).slice(0, 300); }
  renderMonitor(d);
  if (G.projOpen) renderProjector(d);
}
function joinUrl(code) { return new URL('phong-thi.html?c=' + code, location.href).href; }

const EV = {
  hidden: 'Rời trang', blur: 'Mất tiêu điểm', fs_exit: 'Thoát toàn màn hình', split: 'Chia đôi / thu nhỏ màn hình', copy: 'Sao chép', cut: 'Cắt', paste: 'Dán',
  devtools: 'Mở DevTools', printscreen: 'Chụp màn hình', reload: 'Tải lại trang', second_device: 'Thiết bị thứ hai', contextmenu: 'Chuột phải', shortcut: 'Phím tắt',
  offline: 'Mất mạng', online: 'Có mạng lại', back_visible: 'Quay lại trang', focus_back: 'Trở lại cửa sổ', vao_phong: 'Vào phòng', vao_lai: 'Vào lại (cùng máy)',
  doi_may: 'Đổi máy', san_sang: 'Sẵn sàng', bat_dau_lam: 'Bắt đầu làm', nop_bai: 'Nộp bài', khoa_bai: 'BỊ KHOÁ', mo_khoa: 'Mở khoá', pin_sai: 'Sai PIN',
  tu_nop_vi_pham: 'Tự nộp do vi phạm', gv_bat_dau: 'GV bắt đầu', gv_cong_gio: 'Cộng giờ', gv_thu_bai: 'Thu bài', gv_thu_bai_hs: 'GV thu bài HS', gv_cho_doi_may: 'GV cho đổi máy', gv_xoa_luot: 'GV xoá lượt',
};

function renderMonitor(d) {
  const e = d.room, A = d.attempts;
  const cnt = s => A.filter(a => a.status === s).length;
  let clock = '';
  if (e.status === 'LOBBY') clock = `Bắt đầu sau <b class="mono">${fmtClock(parseDT(e.start_at) - srvNow())}</b>`;
  if (e.status === 'RUNNING') clock = `Còn <b class="mono">${fmtClock(parseDT(e.ends_at) - srvNow())}</b> · kết thúc ${fmtTime(e.ends_at)}`;
  if (e.status === 'CLOSED') clock = `Đã thu bài lúc ${fmtTime(e.closed_at)}`;
  const head = $('#mon-head');
  head.innerHTML = `<div class="row between" style="margin-bottom:12px">
    <div><a href="#rooms" class="small">← Phòng thi</a><h1 style="margin:.2em 0 0">${esc(e.title)}</h1>
      <div class="row small" style="gap:8px">${badge(STATUS, e.status)} <span class="muted">${esc(e.label || '')} · ${e.question_count} câu · ${e.duration_minutes} phút ${e.exam_status === 'DRAFT' ? '· đề kín' : '· <b style="color:var(--warn)">đề công khai</b>'}</span> <span>${clock}</span></div></div>
    <div class="row">
      ${e.status === 'DRAFT' ? `<button class="btn rose lg" data-act="open">▶ Mở phòng (${e.prep_minutes} phút chuẩn bị)</button>` : ''}
      ${e.status === 'LOBBY' || e.status === 'RUNNING' ? `<button class="btn primary" data-act="proj">⛶ Chiếu mã QR</button>` : ''}
      ${e.status === 'LOBBY' ? `<button class="btn rose" data-act="start">▶ Bắt đầu ngay</button><button class="btn" data-act="plus" data-min="2">+2 phút chuẩn bị</button>` : ''}
      ${e.status === 'RUNNING' ? `<button class="btn" data-act="plus" data-min="5">+5 phút cả phòng</button>` : ''}
      ${e.status === 'LOBBY' || e.status === 'RUNNING' ? `<button class="btn danger solid" data-act="close">■ Thu bài</button>` : ''}
      ${e.status === 'CLOSED' ? `<a class="btn primary" href="#res/${e.room_id}">Xem kết quả →</a>` : ''}
    </div></div>
    ${e.status !== 'DRAFT' ? `<div class="row" style="gap:10px;margin-bottom:12px">
      <div class="card" style="padding:10px 14px;flex:1;min-width:240px"><div class="tiny muted">Mã phòng · đường dẫn</div><div class="row"><b class="mono" style="font-size:1.4rem;letter-spacing:.1em">${esc(e.code)}</b><span class="small mono muted" style="word-break:break-all">${esc(joinUrl(e.code))}</span></div></div>
      <div class="card" style="padding:10px 14px"><div class="tiny muted">PIN mở khoá tại máy HS</div><b class="mono" style="font-size:1.4rem;letter-spacing:.15em" id="pin">••••••</b> <button class="btn sm" id="pin-t">Hiện</button></div>
    </div>` : ''}
    <div class="kpi" style="margin-bottom:12px">
      <div><b>${A.length}</b><span>Đã vào phòng</span></div><div><b>${cnt('READY')}</b><span>Sẵn sàng</span></div><div><b>${cnt('DOING')}</b><span>Đang làm</span></div>
      <div style="${cnt('LOCKED') ? 'border-color:var(--bad);background:var(--bad-soft)' : ''}"><b>${cnt('LOCKED')}</b><span>Bị khoá</span></div>
      <div><b>${cnt('SUBMITTED')}</b><span>Đã nộp</span></div><div><b>${A.filter(a => +a.violations > 0).length}</b><span>HS có vi phạm</span></div>
      <div><b>${A.filter(a => a.status !== 'SUBMITTED' && (a.seconds_since_seen ?? 99) > 25).length}</b><span>Mất kết nối</span></div>
    </div>`;
  $$('[data-act]', head).forEach(b => b.onclick = () => monAction(b.dataset.act, e, +b.dataset.min || 0));
  const pt = $('#pin-t');
  if (pt) pt.onclick = () => { const p = $('#pin'); const on = p.textContent.includes('•'); p.textContent = on ? e.pin : '••••••'; pt.textContent = on ? 'Ẩn' : 'Hiện'; };
  const body = $('#mon-body');
  if (e.status === 'DRAFT') {
    body.innerHTML = `<div class="card callout">Phòng chưa mở. Bấm <b>Mở phòng</b>: mã QR hiện ra, đồng hồ <b>${e.prep_minutes} phút chuẩn bị</b> chạy; hết giờ bài tự bắt đầu (hoặc bấm <b>Bắt đầu ngay</b>). Câu hỏi được đóng băng từ lúc mở phòng; đề bị khoá ở trang luyện tập cho tới khi thu bài.</div>`;
    return;
  }
  const order = { LOCKED: 0, DOING: 1, READY: 2, JOINED: 3, SUBMITTED: 4 };
  const sorted = A.slice().sort((x, y) => (order[x.status] - order[y.status]) || (+y.violations - +x.violations) || (x.seat_no - y.seat_no));
  const tile = a => {
    const off = a.status !== 'SUBMITTED' && (a.seconds_since_seen ?? 99) > 25;
    const cls = a.status === 'LOCKED' ? 'locked' : a.status === 'SUBMITTED' ? 'done' : +a.violations > 0 ? 'vio' : '';
    return `<div class="tile ${cls} ${off ? 'offline' : ''}" data-att="${a.attempt_id}" title="${esc(a.device_info || '')}">
      <span class="s"><span class="dot ${off ? 'bad' : a.status === 'SUBMITTED' ? '' : 'ok'}"></span></span>
      <div class="row" style="gap:8px"><span class="seatn">#${a.seat_no}</span><div class="nm">${esc(a.full_name)}</div></div>
      <div class="tiny mono muted">${esc(a.phone || '')}</div>
      <div class="row" style="gap:6px;margin-top:6px">${badge(ATT, a.status)}
        ${+a.violations ? `<span class="badge bad">⚠ ${a.violations}</span>` : ''}
        ${a.status === 'SUBMITTED' ? `<span class="badge">${a.score !== null ? fmtNum(a.score, 1) + 'đ' : ''}</span>` : `<span class="tiny muted">${a.answered}/${e.question_count} câu</span>`}
        ${+a.extra_minutes ? `<span class="badge acc">+${a.extra_minutes}′</span>` : ''}</div></div>`;
  };
  body.innerHTML = `<div style="display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:14px" class="mon-layout">
    <div class="mon-grid">${sorted.map(tile).join('') || '<div class="empty">Chưa có học sinh nào vào phòng.</div>'}</div>
    <div class="card" style="padding:12px"><h3>Nhật ký giám sát</h3><div class="feed">${G.feed.map(ev => `<div class="${+ev.counted ? 'cnt' : ''}"><span class="mono tiny muted">${String(ev.created_at).slice(11, 19)}</span> ${ev.seat_no ? '<b>#' + ev.seat_no + '</b> ' + esc(ev.full_name || '') + ' — ' : ''}${esc(EV[ev.type] || ev.type)}${ev.detail ? ': <span class="muted">' + esc(ev.detail) + '</span>' : ''}</div>`).join('') || '<div class="muted">Chưa có sự kiện.</div>'}</div></div>
  </div>`;
  if (innerWidth < 900) $('.mon-layout').style.gridTemplateColumns = '1fr';
  $$('[data-att]', body).forEach(t => t.onclick = () => attemptModal(e, A.find(a => String(a.attempt_id) === t.dataset.att)));
}

async function monAction(act, e, min) {
  try {
    if (act === 'open') {
      if (!(await modal({ title: 'Mở phòng thi?', html: `<p>Mã QR hiện ra, học sinh có <b>${e.prep_minutes} phút</b> để đăng nhập, vào phòng và bấm <b>Sẵn sàng</b>.</p>`, okText: 'Mở phòng' }))) return;
      await gapi('room_open', { room_id: e.room_id });
      G.projOpen = true;
    }
    if (act === 'proj') { G.projOpen = true; renderProjector(G.monData); return; }
    if (act === 'start') {
      const ready = G.monData.attempts.filter(a => a.status === 'READY').length;
      if (!(await modal({ title: 'Bắt đầu ngay?', html: `<p><b>${ready}/${G.monData.attempts.length}</b> học sinh đã sẵn sàng. ${e.duration_minutes} phút tính từ bây giờ.</p>`, okText: 'Bắt đầu' }))) return;
      await gapi('room_start', { room_id: e.room_id });
    }
    if (act === 'plus') await gapi('room_add_time', { room_id: e.room_id, minutes: min });
    if (act === 'close') {
      const doing = G.monData.attempts.filter(a => a.status !== 'SUBMITTED').length;
      if (!(await modal({ title: 'Thu bài và đóng phòng?', html: `<p>${doing ? `<b>${doing}</b> học sinh chưa nộp — chấm theo phần đã lưu.` : 'Tất cả đã nộp.'}</p>`, okText: 'Thu bài', danger: true }))) return;
      await gapi('room_close', { room_id: e.room_id });
      closeProjector();
    }
    await refreshMonitor(e.room_id);
  } catch (err) { toast(err.message, 'bad'); }
}

async function attemptModal(e, a) {
  let log = { events: [] };
  try { log = await gapi('room_attempt_log', { room_id: e.room_id, attempt_id: a.attempt_id }); } catch (x) { }
  const acts = [];
  if (a.status === 'LOCKED') acts.push(['room_unlock', 'Mở khoá', 'primary']);
  if (a.status !== 'SUBMITTED') acts.push(['room_allow_rejoin', 'Cho đổi máy', ''], ['plus5', '+5 phút cho em này', ''], ['room_force_submit', 'Thu bài em này', 'danger']);
  if (e.status === 'LOBBY') acts.push(['room_remove_attempt', 'Xoá khỏi phòng', 'danger']);
  await modal({
    title: `#${a.seat_no} · ${a.full_name}`, cancelText: 'Đóng', hideOk: true, wide: true,
    html: `<div class="row small" style="gap:8px;margin-bottom:10px">${badge(ATT, a.status)} <span class="mono">${esc(a.phone || '')}</span><span class="muted">${esc(a.device_info || '')} · IP ${esc(a.ip || '')}</span></div>
      <div class="kpi" style="margin-bottom:12px"><div><b>${a.violations}</b><span>Vi phạm</span></div><div><b>${a.unlocks}</b><span>Lần mở khoá</span></div>
        <div><b>${a.answered}/${e.question_count}</b><span>Đã làm</span></div><div><b>${a.seconds_since_seen ?? '—'}s</b><span>Lần cuối thấy</span></div></div>
      ${a.lock_reason ? `<div class="callout bad" style="margin-bottom:10px">Khoá vì: ${esc(a.lock_reason)}</div>` : ''}
      <div class="row" style="margin-bottom:12px">${acts.map(([k, l, c]) => `<button class="btn ${c}" data-a="${k}">${l}</button>`).join('')}</div>
      <div class="tbl-wrap" style="max-height:340px;overflow:auto"><table class="tbl"><thead><tr><th>Giờ</th><th>Sự kiện</th><th>Chi tiết</th></tr></thead><tbody>
      ${log.events.slice().reverse().map(ev => `<tr class="${+ev.counted ? 'alert' : ''}"><td class="mono small">${String(ev.created_at).slice(11, 19)}</td><td>${+ev.counted ? '⚠ ' : ''}${esc(EV[ev.type] || ev.type)}</td><td class="small muted">${esc(ev.detail || '')}</td></tr>`).join('')}
      </tbody></table></div>`,
    onOpen: (bg, close) => $$('[data-a]', bg).forEach(b => b.onclick = async () => {
      const k = b.dataset.a;
      try {
        if (k === 'plus5') await gapi('room_add_time', { room_id: e.room_id, attempt_id: a.attempt_id, minutes: 5 });
        else {
          if ((k === 'room_force_submit' || k === 'room_remove_attempt') && !confirm(k === 'room_force_submit' ? 'Thu bài của học sinh này?' : 'Xoá học sinh khỏi phòng?')) return;
          await gapi(k, { room_id: e.room_id, attempt_id: a.attempt_id });
        }
        toast('Đã thực hiện.', 'ok'); close(true); refreshMonitor(e.room_id);
      } catch (err) { toast(err.message, 'bad'); }
    }),
  });
}

function renderProjector(d) {
  const p = $('#proj'), e = d.room, A = d.attempts;
  if (e.status === 'CLOSED' || e.status === 'DRAFT') return closeProjector();
  const url = joinUrl(e.code);
  if (p.dataset.code !== e.code) {
    p.innerHTML = `<button class="btn close" style="background:transparent;color:#fff;border-color:#45545e" id="proj-x">✕ Đóng (Esc)</button>
      <div class="qr"><img id="proj-qr" alt="Mã QR vào phòng thi"></div>
      <div><div class="ttl">${esc(e.title)}</div><div style="opacity:.75;font-size:clamp(14px,1.6vw,24px)">${esc(e.label || '')} · ${e.question_count} câu · ${e.duration_minutes} phút</div>
        <div class="code">${esc(e.code)}</div><div class="url">${esc(url.replace(/^https?:\/\//, ''))}</div>
        <div class="cd" id="proj-cd"></div><div class="cnt" id="proj-cnt"></div></div>`;
    $('#proj-qr').src = qrDataUrl(url, 10);
    p.dataset.code = e.code;
    $('#proj-x').onclick = closeProjector;
  }
  const upd = () => {
    const ex = G.monData.room, cd = $('#proj-cd');
    if (!cd) return;
    if (ex.status === 'LOBBY') cd.textContent = fmtClock(parseDT(ex.start_at) - srvNow());
    else if (ex.status === 'RUNNING') cd.textContent = 'Còn ' + fmtClock(parseDT(ex.ends_at) - srvNow());
  };
  upd();
  clearInterval(p._t); p._t = setInterval(upd, 500);
  $('#proj-cnt').textContent = e.status === 'LOBBY' ? `${A.length} đã vào phòng · ${A.filter(a => a.status !== 'JOINED').length} sẵn sàng` : `${A.filter(a => a.status === 'SUBMITTED').length}/${A.length} đã nộp`;
  p.classList.remove('hidden');
  document.onkeydown = ev => { if (ev.key === 'Escape') closeProjector(); };
}
function closeProjector() {
  const p = $('#proj');
  G.projOpen = false;
  if (!p) return;
  clearInterval(p._t); p.classList.add('hidden'); p.dataset.code = ''; document.onkeydown = null;
}

/* ======================= Kết quả ======================= */

async function viewResults(v, id) {
  const R = await gapi('room_results', { room_id: +id });
  const e = R.room, st = R.stats;
  const pct = x => x === null || x === undefined ? '—' : Math.round(x) + '%';
  const bar = (x, cls = '') => `<div class="bar ${cls}"><i style="width:${Math.max(0, Math.min(100, x || 0))}%"></i></div>`;
  const maxH = Math.max(1, ...st.hist);
  const aNote = st.alpha === null ? 'cần ≥ 3 bài' : st.alpha >= 0.8 ? 'tốt' : st.alpha >= 0.7 ? 'chấp nhận được' : st.alpha >= 0.6 ? 'thấp' : 'kém';
  v.innerHTML = `<div class="row between" style="margin-bottom:12px">
    <div><a href="#rooms" class="small">← Phòng thi</a><h1 style="margin:.2em 0 0">${esc(e.title)}</h1>
      <div class="muted small">${esc(e.label || '')} · ${e.question_count} câu · ${e.started_at ? fmtDateTime(e.started_at) : ''} ${badge(STATUS, e.status)}</div></div>
    <div class="row noprint">
      ${e.status !== 'CLOSED' ? `<a class="btn" href="#mon/${e.room_id}">Giám sát</a>` : ''}
      <button class="btn" id="r-csv">⬇ Bảng điểm (CSV)</button><button class="btn" id="r-csv2">⬇ Phân tích câu (CSV)</button><button class="btn" onclick="print()">🖨 In</button>
      ${e.status === 'CLOSED' ? `<button class="btn primary" id="r-pub">${e.results_published ? '🔒 Thu hồi đáp án' : '📢 Công bố đáp án cho HS'}</button>` : ''}
    </div></div>
  <div class="kpi" style="margin-bottom:14px">
    <div><b>${st.n}/${st.joined}</b><span>Bài đã chấm / đã vào</span></div><div><b>${fmtNum(st.mean, 2)}</b><span>Điểm trung bình</span></div>
    <div><b>${fmtNum(st.sd, 2)}</b><span>Độ lệch chuẩn</span></div><div><b>${fmtNum(st.median, 2)}</b><span>Trung vị</span></div>
    <div><b>${fmtNum(st.min, 1)} – ${fmtNum(st.max, 1)}</b><span>Thấp – cao</span></div><div><b>${pct(st.passRate)}</b><span>Đạt ≥ 5</span></div>
    <div><b>${st.alpha === null ? '—' : fmtNum(st.alpha, 2)}</b><span>Độ tin cậy KR-20 (${aNote})</span></div><div><b>${st.violators}</b><span>HS có vi phạm</span></div>
  </div>
  <div class="card"><h2>Phổ điểm</h2><div class="hist">${st.hist.map(c => `<div style="height:${c / maxH * 100}%"><span>${c || ''}</span></div>`).join('')}</div>
    <div class="hist-x">${st.hist.map((_, i) => `<span>${i}–${i + 1}</span>`).join('')}</div></div>
  <div class="card"><div class="row between"><h2 style="margin:0">Phân tích câu hỏi</h2><label class="check small noprint"><input type="checkbox" id="r-flag"> Chỉ câu cần xem lại</label></div>
    <p class="small muted">p = tỉ lệ làm đúng (0,3–0,8 là tốt) · D = độ phân biệt nhóm 27% cao/thấp (&lt; 0,2 cần sửa) · r<sub>pb</sub> = tương quan câu–tổng. Bấm một dòng để xem phân bố lựa chọn.</p>
    <div class="tbl-wrap"><table class="tbl" id="r-items"><thead><tr><th class="num">#</th><th>Câu hỏi</th><th>Dạng</th><th>Đáp án</th><th class="num">p</th><th class="num">D</th><th class="num">r<sub>pb</sub></th><th class="num">Bỏ trống</th><th>Nhận xét</th></tr></thead><tbody></tbody></table></div></div>
  <div class="card"><h2>Bảng điểm</h2><div class="tbl-wrap"><table class="tbl"><thead><tr><th class="num">Máy</th><th>Họ tên</th><th>SĐT</th><th>Trạng thái</th><th class="num">Đúng</th><th class="num">Điểm</th><th class="num">Vi phạm</th><th></th></tr></thead><tbody>
    ${R.students.map(s => `<tr class="${s.violations ? 'alert' : ''}"><td class="num">${s.seat}</td><td>${esc(s.name)}</td><td class="mono small">${esc(s.phone || '')}</td>
      <td>${badge(ATT, s.status)} <span class="tiny muted">${esc(REASON[s.reason] || '')}</span></td><td class="num">${s.correct ?? '—'}/${s.total ?? '—'}</td>
      <td class="num"><b>${fmtNum(s.score, 2)}</b></td><td class="num">${s.violations || ''}</td>
      <td class="noprint">${s.subId ? `<a class="btn sm" target="_blank" href="ket-qua.html?sub_id=${s.subId}">Phiếu</a>` : ''} <button class="btn sm" data-log="${s.attemptId}">Nhật ký</button></td></tr>`).join('')}
  </tbody></table></div></div>`;
  const renderItems = () => {
    const only = $('#r-flag').checked, tb = $('#r-items tbody');
    tb.innerHTML = R.items.filter(it => !only || it.flags.length).map(it => {
      const pc = it.p === null ? '' : it.p > .9 || it.p < .2 ? 'color:var(--bad)' : it.p >= .3 && it.p <= .8 ? 'color:var(--ok)' : '';
      const dc = it.D === null ? '' : it.D < .2 ? 'color:var(--bad)' : it.D >= .3 ? 'color:var(--ok)' : 'color:var(--warn)';
      return `<tr data-it="${it.idx}" style="cursor:pointer"><td class="num">${it.ord}</td><td style="max-width:420px">${esc(it.stem.length > 160 ? it.stem.slice(0, 160) + '…' : it.stem)}</td>
        <td class="small">${QT[it.type]}</td><td class="mono">${esc(it.answer)}</td><td class="num" style="${pc}">${fmtNum(it.p, 2)}</td><td class="num" style="${dc}">${fmtNum(it.D, 2)}</td>
        <td class="num">${fmtNum(it.rpb, 2)}</td><td class="num">${it.blank || ''}</td><td>${it.flags.map(f => `<span class="badge ${/âm|kém|khó/.test(f) ? 'bad' : 'warn'}">${esc(f)}</span>`).join(' ')}</td></tr>`;
    }).join('') || '<tr><td colspan="9" class="empty">Không có câu cần xem lại 👍</td></tr>';
    typeset(tb);
    $$('[data-it]', tb).forEach(tr => tr.onclick = () => {
      const it = R.items.find(x => String(x.idx) === tr.dataset.it);
      modal({
        title: 'Câu ' + it.ord, cancelText: 'Đóng', hideOk: true,
        html: `<p style="white-space:pre-wrap">${esc(it.stem)}</p><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Lựa chọn</th><th class="num">Số HS</th><th>Tỉ lệ</th><th class="num">Nhóm cao</th><th class="num">Nhóm thấp</th></tr></thead><tbody>
          ${it.options.map(o => `<tr style="${o.isKey ? 'background:var(--ok-soft)' : ''}"><td>${o.isKey ? '✔ ' : ''}${esc(o.key)}${it.type === 'choice' ? '. ' + esc(o.text) : ''}</td><td class="num">${o.count}</td><td>${bar(o.rate * 100, o.isKey ? 'ok' : 'warn')}</td><td class="num">${o.upper ?? ''}</td><td class="num">${o.lower ?? ''}</td></tr>`).join('')}
          </tbody></table></div>`,
        onOpen: bg => typeset(bg),
      });
    });
  };
  $('#r-flag').onchange = renderItems;
  renderItems();
  $$('[data-log]', v).forEach(b => b.onclick = async () => {
    const lg = await gapi('room_attempt_log', { room_id: e.room_id, attempt_id: +b.dataset.log });
    modal({
      title: lg.attempt.full_name, cancelText: 'Đóng', hideOk: true, wide: true,
      html: `<p class="small muted">${esc(lg.attempt.device_info || '')} · IP ${esc(lg.attempt.ip || '')}</p><div class="tbl-wrap" style="max-height:420px;overflow:auto"><table class="tbl"><thead><tr><th>Giờ</th><th>Sự kiện</th><th>Chi tiết</th></tr></thead><tbody>
        ${lg.events.map(ev => `<tr class="${+ev.counted ? 'alert' : ''}"><td class="mono small">${String(ev.created_at).slice(11, 19)}</td><td>${+ev.counted ? '⚠ ' : ''}${esc(EV[ev.type] || ev.type)}</td><td class="small muted">${esc(ev.detail || '')}</td></tr>`).join('')}</tbody></table></div>`,
    });
  });
  const fname = e.title.replace(/[^\p{L}\p{N}]+/gu, '_');
  $('#r-csv').onclick = () => downloadCSV(`diem_${fname}.csv`, [['Máy', 'Họ tên', 'SĐT', 'Trạng thái', 'Lý do nộp', 'Số câu đúng', 'Tổng câu', 'Điểm (10)', 'Vi phạm', 'Nộp lúc'],
    ...R.students.map(s => [s.seat, s.name, s.phone, s.status, REASON[s.reason] || '', s.correct, s.total, s.score, s.violations, s.submittedAt || ''])]);
  $('#r-csv2').onclick = () => downloadCSV(`phan_tich_${fname}.csv`, [['Câu', 'Nội dung', 'Dạng', 'Đáp án', 'p', 'D', 'r_pb', 'Bỏ trống', 'Nhận xét', 'Phân bố'],
    ...R.items.map(it => [it.ord, it.stem, QT[it.type], it.answer, it.p?.toFixed(3), it.D?.toFixed(3), it.rpb?.toFixed(3), it.blank, it.flags.join('; '), it.options.map(o => `${o.key}${o.isKey ? '*' : ''}:${o.count}`).join(' ')]),
    [], ['N', st.n, 'TB', st.mean?.toFixed(2), 'SD', st.sd?.toFixed(2), 'KR-20', st.alpha?.toFixed(3)]]);
  const pub = $('#r-pub');
  if (pub) pub.onclick = async () => {
    try { await gapi('room_publish', { room_id: e.room_id, published: !e.results_published }); toast(e.results_published ? 'Đã thu hồi.' : 'Đã công bố: HS mở lại link phòng thi để xem phiếu kết quả và lời giải.', 'ok', 5000); route(); }
    catch (err) { toast(err.message, 'bad'); }
  };
}

/* ======================= Hướng dẫn ======================= */

function viewGuide(v) {
  v.innerHTML = `<div class="card stack" style="max-width:860px">
    <h1>Quy trình một buổi kiểm tra trên lớp</h1>
    <ol>
      <li><b>Chuẩn bị đề kín</b> (tab Đề thi &amp; đề kín): dán bảng từ Excel — trắc nghiệm A–D, Đúng/Sai 4 ý, trả lời ngắn; công thức viết LaTeX như trang luyện tập. Đề kín không hiện ở trang luyện tập.</li>
      <li><b>Tạo phòng thi</b>: chọn đề, thời gian, 5 phút chuẩn bị, thiết lập chống gian lận.</li>
      <li><b>Trên lớp</b>: <b>Mở phòng</b> → <b>Chiếu mã QR</b>. Học sinh quét QR, đăng nhập tài khoản BDVH (họ tên + số điện thoại), đọc quy định, bấm <b>Sẵn sàng</b>. Hết giờ chuẩn bị bài tự bắt đầu.</li>
      <li><b>Trong giờ</b>: ô học sinh đỏ khi vi phạm, nhấp nháy khi <b>bị khoá</b>. Đến bàn (số máy hiện trên màn hình HS), nhập <b>PIN</b> tại máy HS hoặc bấm Mở khoá. Máy hết pin → <b>Cho đổi máy</b>.</li>
      <li><b>Thu bài</b> → <b>Kết quả</b>: phổ điểm, KR-20, phân tích từng câu, xuất CSV. Mỗi bài cũng có <b>phiếu báo điểm</b> (ket-qua.html) như bài luyện tập và hiện ở bảng điều khiển cũ.</li>
      <li><b>Công bố đáp án</b>: học sinh mở lại link phòng thi để xem phiếu kết quả và lời giải.</li>
    </ol>
    <div class="callout warn">Trong lúc phòng còn mở, đề đó bị khoá ở trang luyện tập (không mở, không nộp thử được) và phiếu kết quả của bài trong phòng chỉ xem được sau khi công bố — để học sinh không dò đáp án bằng tab khác hay máy khác.</div>
    <h2>Phát hiện được gì?</h2>
    <p class="small">Rời trang (chuyển tab, mở ứng dụng khác — kể cả máy tính bỏ túi trên điện thoại, tắt màn hình), mất tiêu điểm lâu, chia đôi màn hình, thoát toàn màn hình (laptop/Android), copy/dán, tải lại trang, đăng nhập tài khoản trên máy thứ hai. <b>Không phát hiện được</b>: điện thoại/máy tính bỏ túi thứ hai, nhìn bài, trao đổi — thầy cô giám sát trực tiếp.</p>
    <p class="small muted">Học sinh đăng nhập bằng họ tên + số điện thoại (không có mật khẩu), nên ai biết số điện thoại của bạn có thể đăng nhập thay — đối chiếu danh sách tên và số máy trên bảng giám sát với học sinh thực tế trong lớp.</p>
  </div>`;
}

boot();
