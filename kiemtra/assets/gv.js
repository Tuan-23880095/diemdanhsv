/* KIEMTRA — trang giảng viên. Định tuyến bằng hash:
 *   #exams · #bank · #clo · #report · #guide · #edit/<ExamID> · #mon/<ExamID> · #res/<ExamID>
 */
'use strict';

const G = {
  token: null, me: null, course: null,
  clos: [], questions: [], exams: [],
  monTimer: null, monSince: 0, monData: null, feed: [], projOpen: false,
};
const LSK = 'kt_gv_token';
const store = {
  get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
  set(k, v) { try { localStorage.setItem(k, v); } catch (e) { } },
  del(k) { try { localStorage.removeItem(k); } catch (e) { } },
};

async function gapi(action, data = {}) {
  try {
    return await api(action, Object.assign({ token: G.token }, data));
  } catch (e) {
    if (/hết hạn|đăng nhập lại/i.test(e.message)) { logout(true); }
    throw e;
  }
}

/* ======================= Đăng nhập ======================= */

async function boot() {
  G.token = store.get(LSK);
  if (G.token) {
    try { G.me = await gapi('me'); return startApp(); } catch (e) { /* rơi xuống đăng nhập */ }
  }
  showLogin();
}
function showLogin() {
  $('#v-login').classList.remove('hidden');
  $('#v-app').classList.add('hidden');
  ['#sel-course', '#btn-logout'].forEach(s => $(s).classList.add('hidden'));
  $('#who').textContent = '';
  const go = async () => {
    $('#lg-btn').disabled = true;
    try {
      const r = await api('login', { username: $('#lg-user').value.trim(), password: $('#lg-pass').value });
      G.token = r.token; store.set(LSK, r.token);
      G.me = await gapi('me');
      startApp();
    } catch (e) { toast(e.message, 'bad'); }
    finally { $('#lg-btn').disabled = false; }
  };
  $('#lg-btn').onclick = go;
  $('#lg-pass').onkeydown = e => { if (e.key === 'Enter') go(); };
  $('#lg-user').focus();
}
function logout(expired) {
  if (!expired) api('logout', { token: G.token }).catch(() => { });
  store.del(LSK); G.token = null; G.me = null;
  stopMonitor();
  showLogin();
  if (expired) toast('Phiên đăng nhập đã hết hạn — đăng nhập lại.', 'bad');
}

async function startApp() {
  $('#v-login').classList.add('hidden');
  $('#v-app').classList.remove('hidden');
  $('#who').textContent = G.me.name;
  $('#btn-logout').classList.remove('hidden');
  $('#btn-logout').onclick = () => logout(false);
  const sel = $('#sel-course');
  if (!G.me.courses.length) {
    $('#view').innerHTML = `<div class="card empty">Tài khoản chưa phụ trách lớp nào trên diemdanhsv.com. Hãy được gán làm giảng viên của lớp trước.</div>`;
    return;
  }
  sel.innerHTML = G.me.courses.map(c => `<option value="${esc(c.CourseID)}">${esc(c.CourseName)} (${esc(c.CourseCode)})</option>`).join('');
  const saved = store.get('kt_gv_course');
  if (saved && G.me.courses.some(c => c.CourseID === saved)) sel.value = saved;
  sel.classList.remove('hidden');
  sel.onchange = () => { store.set('kt_gv_course', sel.value); selectCourse(sel.value).then(route); };
  $$('#tabs button').forEach(b => b.onclick = () => { location.hash = b.dataset.tab; });
  window.onhashchange = route;
  await selectCourse(sel.value);
  route();
}

async function selectCourse(id) {
  G.course = G.me.courses.find(c => c.CourseID === id);
  [G.clos, G.questions] = await Promise.all([gapi('listClos', { courseId: id }), gapi('listQuestions', { courseId: id })]);
}
function courseClasses() { return G.me.classes.filter(c => c.CourseID === G.course.CourseID); }
function cloCode(id) { const c = G.clos.find(x => x.CloID === id); return c ? c.Code : ''; }

function route() {
  stopMonitor();
  closeProjector();
  const [tab, id] = (location.hash.slice(1) || 'exams').split('/');
  const tabName = { edit: 'exams', mon: 'exams', res: 'exams' }[tab] || tab;
  $$('#tabs button').forEach(b => b.classList.toggle('on', b.dataset.tab === tabName));
  const v = $('#view');
  v.innerHTML = '<div class="empty">Đang tải…</div>';
  const fn = { exams: viewExams, bank: viewBank, clo: viewClo, report: viewReport, guide: viewGuide, edit: viewEdit, mon: viewMonitor, res: viewResults }[tab] || viewExams;
  Promise.resolve(fn(v, id)).catch(e => { v.innerHTML = `<div class="card callout bad">${esc(e.message)}</div>`; });
}

const STATUS = { DRAFT: ['Nháp', ''], LOBBY: ['Đang chuẩn bị', 'acc'], RUNNING: ['Đang thi', 'warn'], CLOSED: ['Đã thu bài', 'ok'] };
const ATT = { JOINED: ['Đã vào', ''], READY: ['Sẵn sàng', 'acc'], DOING: ['Đang làm', 'warn'], LOCKED: ['BỊ KHOÁ', 'bad'], SUBMITTED: ['Đã nộp', 'ok'] };
const REASON = { sv_nop: 'SV nộp', het_gio: 'Hết giờ', gv_thu: 'GV thu', vi_pham: 'Vi phạm' };
function badge(map, k) { const x = map[k] || [k, '']; return `<span class="badge ${x[1]}">${esc(x[0])}</span>`; }

/* ======================= Đề kiểm tra ======================= */

async function viewExams(v) {
  const all = await gapi('listExams');
  G.exams = all.filter(e => e.CourseID === G.course.CourseID);
  v.innerHTML = `<div class="row between" style="margin-bottom:12px">
      <div><h1 style="margin:0">Đề kiểm tra</h1><div class="muted small">${esc(G.course.CourseName)} · ${G.questions.length} câu trong ngân hàng · ${G.clos.length} CLO</div></div>
      <button class="btn primary" id="btn-new">＋ Tạo đề mới</button></div>
    ${G.exams.length ? `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Bài kiểm tra</th><th>Lớp</th><th>Trạng thái</th><th class="num">Câu</th><th class="num">Bài làm</th><th>Thời gian</th><th></th></tr></thead><tbody>
      ${G.exams.map(e => `<tr>
        <td><b>${esc(e.Title)}</b><div class="tiny muted mono">Mã phòng ${esc(e.Code)}</div></td>
        <td>${esc(e.ClassCode)}</td><td>${badge(STATUS, e.Status)}</td>
        <td class="num">${e.ItemCount}</td><td class="num">${e.AttemptCount}</td>
        <td class="small">${e.DurationMinutes} phút${e.StartedAt ? '<br><span class="muted">' + fmtDateTime(e.StartedAt) + '</span>' : ''}</td>
        <td><div class="row" style="gap:6px;justify-content:flex-end">
          ${e.Status === 'DRAFT' ? `<a class="btn sm" href="#edit/${e.ExamID}">Soạn đề</a>` : ''}
          ${e.Status !== 'CLOSED' ? `<a class="btn sm primary" href="#mon/${e.ExamID}">${e.Status === 'DRAFT' ? 'Mở phòng' : 'Giám sát'}</a>` : ''}
          ${e.AttemptCount ? `<a class="btn sm" href="#res/${e.ExamID}">Kết quả</a>` : ''}
          <button class="btn sm" data-dup="${e.ExamID}" title="Nhân bản">⧉</button>
          ${!e.AttemptCount ? `<button class="btn sm danger" data-del="${e.ExamID}" title="Xoá">✕</button>` : ''}
        </div></td></tr>`).join('')}</tbody></table></div>` : `<div class="card empty">Chưa có đề nào. Bấm <b>Tạo đề mới</b>.<br><span class="small">Gợi ý: khai báo CLO và nhập câu hỏi vào ngân hàng trước.</span></div>`}`;
  $('#btn-new').onclick = () => examSettingsModal(null);
  $$('[data-dup]', v).forEach(b => b.onclick = async () => {
    const cls = courseClasses();
    const ok = await modal({
      title: 'Nhân bản đề', okText: 'Nhân bản',
      html: `<label class="f">Cho lớp</label><select id="dup-cls">${cls.map(c => `<option value="${c.ClassID}">${esc(c.ClassCode)}</option>`).join('')}</select><p class="small muted">Bản sao giữ nguyên câu hỏi và thiết lập, có mã phòng và PIN mới.</p>`,
      onOk: async bg => (await gapi('duplicateExam', { examId: b.dataset.dup, classId: $('#dup-cls', bg).value })).examId,
    });
    if (ok) { toast('Đã nhân bản.', 'ok'); location.hash = 'edit/' + ok; }
  });
  $$('[data-del]', v).forEach(b => b.onclick = async () => {
    if (await modal({ title: 'Xoá đề?', html: '<p>Đề chưa có bài làm nên xoá được. Thao tác không hoàn tác.</p>', okText: 'Xoá', danger: true })) {
      try { await gapi('deleteExam', { examId: b.dataset.del }); toast('Đã xoá.', 'ok'); route(); } catch (e) { toast(e.message, 'bad'); }
    }
  });
}

function settingsForm(e) {
  const s = (e && e.Settings) || {};
  const val = (k, d) => s[k] !== undefined ? s[k] : d;
  const ck = (k, d, label, help) => `<label class="check"><input type="checkbox" data-s="${k}" ${val(k, d) ? 'checked' : ''}><span>${label}${help ? `<br><span class="tiny muted">${help}</span>` : ''}</span></label>`;
  const cls = courseClasses();
  return `<div class="grid2">
      <div style="grid-column:1/-1"><label class="f">Tên bài kiểm tra</label><input type="text" id="es-title" value="${esc(e ? e.Title : 'Kiểm tra giữa kỳ — ' + G.course.CourseName)}"></div>
      <div><label class="f">Lớp</label><select id="es-class" ${e ? 'disabled' : ''}>${cls.map(c => `<option value="${c.ClassID}" ${e && e.ClassID === c.ClassID ? 'selected' : ''}>${esc(c.ClassCode)} · ${esc(c.Semester || '')} ${esc(c.AcademicYear || '')}</option>`).join('')}</select></div>
      <div class="grid2" style="gap:8px"><div><label class="f">Làm bài (phút)</label><input type="number" id="es-dur" min="1" max="300" value="${e ? e.DurationMinutes : 30}" ${e && e.Status !== 'DRAFT' ? 'disabled' : ''}></div>
      <div><label class="f">Chuẩn bị (phút)</label><input type="number" id="es-prep" min="0" max="30" value="${e ? e.PrepMinutes : 5}" ${e && e.Status !== 'DRAFT' ? 'disabled' : ''}></div></div>
    </div>
    <h3 style="margin-top:16px">Chống gian lận</h3>
    <div class="grid2">
      <div><label class="f">Số vi phạm tối đa</label><input type="number" data-s="maxViolations" min="1" max="20" value="${val('maxViolations', 3)}"></div>
      <div><label class="f">Khi vượt ngưỡng</label><select data-s="onLimit"><option value="lock" ${val('onLimit', 'lock') === 'lock' ? 'selected' : ''}>Khoá bài — giám thị mở bằng PIN</option><option value="submit" ${val('onLimit') === 'submit' ? 'selected' : ''}>Tự động nộp bài</option></select></div>
      <div><label class="f">Bỏ qua mất tiêu điểm ngắn hơn (giây)</label><input type="number" data-s="blurGraceSec" min="0" max="30" value="${val('blurGraceSec', 3)}"><div class="f-help">Tránh tính oan khi lỡ kéo thanh thông báo. Rời hẳn khỏi trang luôn bị tính.</div></div>
      <div><label class="f">Cho vào muộn tối đa (phút)</label><input type="number" data-s="lateJoinMinutes" min="0" max="120" value="${val('lateJoinMinutes', 10)}"></div>
    </div>
    <div class="stack" style="margin-top:10px">
      ${ck('requireFullscreen', true, 'Yêu cầu toàn màn hình', 'Laptop, Android. iPhone không hỗ trợ — khi đó chỉ dựa vào phát hiện rời trang.')}
      ${ck('shuffleQuestions', true, 'Xáo thứ tự câu hỏi cho từng sinh viên')}
      ${ck('shuffleOptions', true, 'Xáo thứ tự phương án', 'Câu có phương án kiểu "Tất cả các ý trên", "Cả A và B" được giữ nguyên thứ tự.')}
      ${ck('allowBack', true, 'Cho quay lại câu trước')}
      ${ck('watermark', true, 'Hình mờ MSSV + họ tên trên màn hình làm bài')}
      ${ck('showScore', true, 'Hiện điểm cho SV ngay khi nộp')}
      ${ck('partialCredit', false, 'Câu nhiều đáp án: chấm từng phần', 'Tắt = đúng hết mới có điểm.')}
    </div>`;
}
function readSettings(root) {
  const s = {};
  $$('[data-s]', root).forEach(el => { s[el.dataset.s] = el.type === 'checkbox' ? el.checked : (el.type === 'number' ? +el.value : el.value); });
  return { title: $('#es-title', root).value.trim(), classId: $('#es-class', root).value, durationMinutes: +$('#es-dur', root).value, prepMinutes: +$('#es-prep', root).value, settings: s };
}
async function examSettingsModal(e) {
  const id = await modal({
    title: e ? 'Thiết lập bài kiểm tra' : 'Tạo đề mới', okText: e ? 'Lưu' : 'Tạo đề & chọn câu hỏi', html: settingsForm(e),
    onOk: async bg => (await gapi('saveExam', Object.assign({ examId: e ? e.ExamID : '' }, readSettings(bg)))).examId,
  });
  if (id) { toast('Đã lưu.', 'ok'); if (!e) location.hash = 'edit/' + id; else route(); }
}

/* ---------- Soạn đề: chọn câu ---------- */

async function viewEdit(v, examId) {
  const e = await gapi('getExam', { examId });
  if (e.Status !== 'DRAFT') { location.hash = 'mon/' + examId; return; }
  const picked = new Map(e.Items.map(i => [i.QuestionID, +i.Points]));
  const order = e.Items.map(i => i.QuestionID);
  const filt = { clo: '', bloom: '', diff: '', q: '', only: false };

  v.innerHTML = `<div class="row between" style="margin-bottom:12px">
    <div><a href="#exams" class="small">← Đề kiểm tra</a><h1 style="margin:.2em 0 0">${esc(e.Title)}</h1><div class="muted small">Lớp ${esc(e.ClassCode)} · ${e.DurationMinutes} phút · chuẩn bị ${e.PrepMinutes} phút</div></div>
    <div class="row"><button class="btn" id="ed-set">⚙ Thiết lập</button><button class="btn" id="ed-draw">🎲 Rút theo ma trận</button><button class="btn primary" id="ed-save">Lưu đề</button><a class="btn rose" href="#mon/${e.ExamID}" id="ed-go">Sang phòng thi →</a></div></div>
    <div class="card" id="ed-blue"></div>
    <div class="card">
      <div class="row" style="margin-bottom:10px">
        <input type="search" id="f-q" placeholder="Tìm nội dung, chủ đề…" style="max-width:260px">
        <select id="f-clo" style="max-width:150px"><option value="">Mọi CLO</option>${G.clos.map(c => `<option value="${c.CloID}">${esc(c.Code)}</option>`).join('')}<option value="_none">Chưa gắn CLO</option></select>
        <select id="f-bloom" style="max-width:150px"><option value="">Mọi mức Bloom</option>${Object.entries(BLOOM).map(([k, n]) => `<option value="${k}">${k}. ${n}</option>`).join('')}</select>
        <select id="f-diff" style="max-width:140px"><option value="">Mọi độ khó</option>${Object.entries(DIFF).map(([k, n]) => `<option value="${k}">${n}</option>`).join('')}</select>
        <label class="check small"><input type="checkbox" id="f-only"> Chỉ câu đã chọn</label>
        <span class="grow"></span>
        <button class="btn sm" id="sel-all">Chọn tất cả đang lọc</button><button class="btn sm" id="sel-none">Bỏ chọn tất cả</button>
      </div>
      <div id="ed-list"></div>
    </div>`;

  const blueprint = () => {
    const qs = G.questions.filter(q => picked.has(q.QuestionID));
    const total = qs.reduce((s, q) => s + picked.get(q.QuestionID), 0);
    const rows = G.clos.map(c => c.CloID).concat(['_none']);
    const cell = (clo, b) => qs.filter(q => (q.CloID || '_none') === clo && (b === 0 ? !q.Bloom : q.Bloom === b)).length;
    const bl = [1, 2, 3, 4, 5, 6, 0];
    $('#ed-blue').innerHTML = `<div class="row between"><h2 style="margin:0">Ma trận đề</h2><div class="kpi" style="grid-template-columns:repeat(3,120px)">
      <div><b>${qs.length}</b><span>câu</span></div><div><b>${fmtNum(total, 1)}</b><span>tổng điểm</span></div><div><b>${qs.length ? Math.round(e.DurationMinutes * 60 / qs.length) : 0}s</b><span>/câu</span></div></div></div>
      <div class="tbl-wrap" style="margin-top:10px"><table class="tbl"><thead><tr><th>CLO</th>${bl.map(b => `<th class="num">${b ? b + '. ' + BLOOM[b] : 'Chưa gán'}</th>`).join('')}<th class="num">Số câu</th><th class="num">Điểm</th><th class="num">Tỉ trọng</th></tr></thead><tbody>
      ${rows.map(clo => {
        const cq = qs.filter(q => (q.CloID || '_none') === clo);
        if (clo === '_none' && !cq.length) return '';
        const pts = cq.reduce((s, q) => s + picked.get(q.QuestionID), 0);
        return `<tr><td><b>${clo === '_none' ? 'Chưa gắn CLO' : esc(cloCode(clo))}</b></td>${bl.map(b => `<td class="num">${cell(clo, b) || '<span class="muted">·</span>'}</td>`).join('')}<td class="num"><b>${cq.length}</b></td><td class="num">${fmtNum(pts, 1)}</td><td class="num">${total ? Math.round(pts / total * 100) : 0}%</td></tr>`;
      }).join('')}</tbody></table></div>
      ${qs.some(q => !q.CloID) ? '<p class="small" style="color:var(--warn)">⚠ Có câu chưa gắn CLO — sẽ không được tính vào mức đạt chuẩn đầu ra.</p>' : ''}`;
  };

  const list = () => {
    let qs = G.questions.filter(q =>
      (!filt.clo || (filt.clo === '_none' ? !q.CloID : q.CloID === filt.clo)) &&
      (!filt.bloom || q.Bloom === +filt.bloom) && (!filt.diff || q.Difficulty === +filt.diff) &&
      (!filt.only || picked.has(q.QuestionID)) &&
      (!filt.q || (q.Stem + ' ' + q.Topic).toLowerCase().includes(filt.q.toLowerCase())));
    const pos = id => { const i = order.indexOf(id); return i < 0 ? 1e9 : i; };
    if (filt.only) qs.sort((a, b) => pos(a.QuestionID) - pos(b.QuestionID));
    $('#ed-list').innerHTML = qs.length ? qs.map(q => {
      const on = picked.has(q.QuestionID);
      return `<div class="q-row" style="${on ? 'background:#f2f8f6' : ''}">
        <input type="checkbox" data-pick="${q.QuestionID}" ${on ? 'checked' : ''} style="width:20px;height:20px;accent-color:var(--accent)">
        <div>${qSummary(q)}</div>
        <div class="row" style="gap:6px">${on ? `<label class="tiny muted">Điểm <input type="number" step="0.25" min="0.25" data-pts="${q.QuestionID}" value="${picked.get(q.QuestionID)}" style="width:76px;min-height:32px;padding:.2em .4em"></label>` : ''}</div>
      </div>`;
    }).join('') : `<div class="empty">Không có câu nào khớp bộ lọc. <a href="#bank">Thêm câu hỏi →</a></div>`;
    $$('[data-pick]').forEach(c => c.onchange = () => {
      const id = c.dataset.pick;
      if (c.checked) { const q = G.questions.find(x => x.QuestionID === id); picked.set(id, q.Points); order.push(id); }
      else { picked.delete(id); order.splice(order.indexOf(id), 1); }
      list(); blueprint();
    });
    $$('[data-pts]').forEach(i => i.onchange = () => { picked.set(i.dataset.pts, Math.max(0.25, +i.value || 1)); blueprint(); });
  };
  $('#f-q').oninput = ev => { filt.q = ev.target.value; list(); };
  $('#f-clo').onchange = ev => { filt.clo = ev.target.value; list(); };
  $('#f-bloom').onchange = ev => { filt.bloom = ev.target.value; list(); };
  $('#f-diff').onchange = ev => { filt.diff = ev.target.value; list(); };
  $('#f-only').onchange = ev => { filt.only = ev.target.checked; list(); };
  $('#sel-all').onclick = () => { $$('[data-pick]').forEach(c => { if (!c.checked) { const q = G.questions.find(x => x.QuestionID === c.dataset.pick); picked.set(q.QuestionID, q.Points); order.push(q.QuestionID); } }); list(); blueprint(); };
  $('#sel-none').onclick = () => { picked.clear(); order.length = 0; list(); blueprint(); };
  const save = async () => {
    const items = order.filter(id => picked.has(id)).map(id => ({ questionId: id, points: picked.get(id) }));
    const r = await gapi('setExamItems', { examId: e.ExamID, items });
    toast(`Đã lưu đề: ${r.count} câu.`, 'ok');
    return r;
  };
  $('#ed-save').onclick = () => save().catch(err => toast(err.message, 'bad'));
  $('#ed-go').onclick = async ev => { ev.preventDefault(); try { await save(); location.hash = 'mon/' + e.ExamID; } catch (err) { toast(err.message, 'bad'); } };
  $('#ed-set').onclick = () => examSettingsModal(e);
  $('#ed-draw').onclick = async () => {
    const diffs = [0, 1, 2, 3];
    const avail = (clo, d) => G.questions.filter(q => (clo === '' ? true : q.CloID === clo) && (!d || q.Difficulty === d)).length;
    const res = await modal({
      title: 'Rút ngẫu nhiên theo ma trận CLO × độ khó', okText: 'Rút câu (thay danh sách hiện tại)', wide: true,
      html: `<p class="small muted">Nhập số câu cần rút ở mỗi ô. Số trong ngoặc là số câu sẵn có trong ngân hàng. Kết quả thay thế danh sách đã chọn; bạn vẫn sửa tay được sau đó.</p>
        <div class="tbl-wrap"><table class="tbl"><thead><tr><th>CLO</th>${diffs.map(d => `<th class="num">${d ? DIFF[d] : 'Bất kỳ độ khó'}</th>`).join('')}</tr></thead><tbody>
        ${G.clos.map(c => `<tr><td><b>${esc(c.Code)}</b><div class="tiny muted">${esc(c.Description.slice(0, 70))}</div></td>${diffs.map(d => `<td class="num"><input type="number" min="0" max="${avail(c.CloID, d)}" value="0" data-m="${c.CloID}|${d}" style="width:70px;min-height:32px"> <span class="tiny muted">(${avail(c.CloID, d)})</span></td>`).join('')}</tr>`).join('')}
        </tbody></table></div>`,
      onOk: async bg => {
        const matrix = $$('[data-m]', bg).filter(i => +i.value > 0).map(i => { const [cloId, d] = i.dataset.m.split('|'); return { cloId, difficulty: +d, count: +i.value }; });
        if (!matrix.length) throw new Error('Chưa nhập số câu.');
        return gapi('drawQuestions', { courseId: G.course.CourseID, matrix });
      },
    });
    if (res) {
      picked.clear(); order.length = 0;
      res.questionIds.forEach(id => { const q = G.questions.find(x => x.QuestionID === id); picked.set(id, q.Points); order.push(id); });
      list(); blueprint();
      if (res.shortages.length) toast('Một số ô không đủ câu trong ngân hàng — đã rút tối đa có thể.', 'bad', 6000);
      else toast(`Đã rút ${res.questionIds.length} câu. Bấm "Lưu đề" để lưu.`, 'ok');
    }
  };
  list(); blueprint();
}

function qSummary(q, opt = {}) {
  const labels = 'ABCDEFGH';
  const opts = q.Type === 'short'
    ? `Đáp án chấp nhận: <span class="key">${esc(q.Answer.join(' | '))}</span>`
    : q.Options.map((o, i) => `<span class="${q.Answer.includes(o.key) ? 'key' : ''}">${labels[i]}. ${esc(o.text)}</span>`).join(' &nbsp;·&nbsp; ');
  return `<div class="row" style="gap:6px;margin-bottom:4px">
      ${q.CloID ? `<span class="badge acc">${esc(cloCode(q.CloID))}</span>` : '<span class="badge warn">Chưa gắn CLO</span>'}
      ${q.Bloom ? `<span class="badge">${q.Bloom}. ${BLOOM[q.Bloom]}</span>` : ''}
      <span class="badge">${DIFF[q.Difficulty]}</span><span class="badge">${QTYPE[q.Type]}</span>
      ${q.Topic ? `<span class="tiny muted">${esc(q.Topic)}</span>` : ''}${opt.used && q.UsedIn ? `<span class="tiny muted">· đã dùng ${q.UsedIn} đề</span>` : ''}</div>
    <div class="stem">${esc(q.Stem)}</div>
    ${q.ImageID ? `<img class="thumb" style="margin:6px 0" src="${imgUrl(q.ImageID)}" alt="">` : ''}
    <div class="opts">${opts}</div>
    ${opt.expl && q.Explanation ? `<div class="expl tiny">💡 ${esc(q.Explanation)}</div>` : ''}`;
}

/* ======================= Phòng thi / giám sát ======================= */

function stopMonitor() { clearInterval(G.monTimer); G.monTimer = null; }

async function viewMonitor(v, examId) {
  G.monSince = 0; G.feed = [];
  v.innerHTML = `<div id="mon-head"></div><div id="mon-body"></div>`;
  await refreshMonitor(examId);
  G.monTimer = setInterval(() => refreshMonitor(examId).catch(() => { }), 3000);
}

async function refreshMonitor(examId) {
  const d = await gapi('monitor', { examId, sinceEvent: G.monSince });
  G.monData = d;
  if (d.events.length) { G.monSince = d.events[0].EventID; G.feed = d.events.concat(G.feed).slice(0, 300); }
  renderMonitor(d);
  if (G.projOpen) renderProjector(d);
}

function joinUrl(code) { return new URL('sv.html?c=' + code, location.href).href; }

function renderMonitor(d) {
  const e = d.exam, A = d.attempts;
  const cnt = s => A.filter(a => a.Status === s).length;
  const now = parseDT(d.serverNow);
  let clock = '';
  if (e.Status === 'LOBBY') clock = `Bắt đầu sau <b class="mono">${fmtClock(parseDT(e.StartAt) - srvNow())}</b>`;
  if (e.Status === 'RUNNING') clock = `Còn <b class="mono">${fmtClock(parseDT(e.EndsAt) - srvNow())}</b> · kết thúc ${fmtTime(e.EndsAt)}`;
  if (e.Status === 'CLOSED') clock = `Đã thu bài lúc ${fmtTime(e.ClosedAt)}`;
  const head = $('#mon-head');
  head.innerHTML = `<div class="row between" style="margin-bottom:12px">
    <div><a href="#exams" class="small">← Đề kiểm tra</a><h1 style="margin:.2em 0 0">${esc(e.Title)}</h1>
      <div class="row small" style="gap:8px">${badge(STATUS, e.Status)} <span class="muted">Lớp ${esc(e.ClassCode)} · ${e.ItemCount} câu · ${e.DurationMinutes} phút</span> <span>${clock}</span></div></div>
    <div class="row">
      ${e.Status === 'DRAFT' ? `<a class="btn" href="#edit/${e.ExamID}">Soạn đề</a><button class="btn rose lg" data-act="open">▶ Mở phòng (${e.PrepMinutes} phút chuẩn bị)</button>` : ''}
      ${e.Status !== 'DRAFT' && e.Status !== 'CLOSED' ? `<button class="btn primary" data-act="proj">⛶ Chiếu mã QR</button>` : ''}
      ${e.Status === 'LOBBY' ? `<button class="btn rose" data-act="start">▶ Bắt đầu ngay</button><button class="btn" data-act="plus" data-min="2">+2 phút chuẩn bị</button>` : ''}
      ${e.Status === 'RUNNING' ? `<button class="btn" data-act="plus" data-min="5">+5 phút cả phòng</button>` : ''}
      ${e.Status === 'LOBBY' || e.Status === 'RUNNING' ? `<button class="btn danger solid" data-act="close">■ Thu bài</button>` : ''}
      ${e.Status === 'CLOSED' ? `<a class="btn primary" href="#res/${e.ExamID}">Xem kết quả →</a>` : ''}
    </div></div>
    ${e.Status !== 'DRAFT' ? `<div class="row" style="gap:10px;margin-bottom:12px">
      <div class="card" style="padding:10px 14px;flex:1;min-width:240px"><div class="tiny muted">Mã phòng · đường dẫn</div><div class="row"><b class="mono" style="font-size:1.4rem;letter-spacing:.1em">${esc(e.Code)}</b><span class="small mono muted" style="word-break:break-all">${esc(joinUrl(e.Code))}</span></div></div>
      <div class="card" style="padding:10px 14px"><div class="tiny muted">PIN giám thị (mở khoá tại máy SV)</div><b class="mono" style="font-size:1.4rem;letter-spacing:.15em" id="pin">••••••</b> <button class="btn sm" id="pin-t">Hiện</button></div>
    </div>` : ''}
    <div class="kpi" style="margin-bottom:12px">
      <div><b>${A.length}/${d.rosterCount}</b><span>Đã vào phòng</span></div>
      <div><b>${cnt('READY')}</b><span>Sẵn sàng</span></div>
      <div><b>${cnt('DOING')}</b><span>Đang làm</span></div>
      <div style="${cnt('LOCKED') ? 'border-color:var(--bad);background:var(--bad-soft)' : ''}"><b>${cnt('LOCKED')}</b><span>Bị khoá</span></div>
      <div><b>${cnt('SUBMITTED')}</b><span>Đã nộp</span></div>
      <div><b>${A.filter(a => +a.Violations > 0).length}</b><span>SV có vi phạm</span></div>
      <div><b>${A.filter(a => a.Status !== 'SUBMITTED' && (a.SecondsSinceSeen ?? 99) > 25).length}</b><span>Mất kết nối</span></div>
    </div>`;
  $$('[data-act]', head).forEach(b => b.onclick = () => monAction(b.dataset.act, e, +b.dataset.min || 0));
  const pt = $('#pin-t');
  if (pt) pt.onclick = () => { const p = $('#pin'); const on = p.textContent.includes('•'); p.textContent = on ? e.SupervisorPin : '••••••'; pt.textContent = on ? 'Ẩn' : 'Hiện'; };

  const body = $('#mon-body');
  if (e.Status === 'DRAFT') {
    body.innerHTML = `<div class="card callout">Phòng chưa mở. Bấm <b>Mở phòng</b>: mã QR hiện ra để SV quét, đồng hồ <b>${e.PrepMinutes} phút chuẩn bị</b> bắt đầu chạy; hết giờ chuẩn bị bài tự bắt đầu (hoặc bấm <b>Bắt đầu ngay</b> khi cả lớp đã sẵn sàng). Sau khi mở phòng, câu hỏi được đóng băng — không sửa được nữa.</div>`;
    return;
  }
  const tile = a => {
    const off = a.Status !== 'SUBMITTED' && (a.SecondsSinceSeen ?? 99) > 25;
    const cls = a.Status === 'LOCKED' ? 'locked' : a.Status === 'SUBMITTED' ? 'done' : +a.Violations > 0 ? 'vio' : '';
    const dl = e.EndsAt ? parseDT(e.EndsAt) + a.ExtraMinutes * 60000 : null;
    return `<div class="tile ${cls} ${off ? 'offline' : ''}" data-att="${a.AttemptID}" title="${esc(a.DeviceInfo || '')}">
      <span class="s"><span class="dot ${off ? 'bad' : a.Status === 'SUBMITTED' ? '' : 'ok'}" title="${off ? 'Mất kết nối ' + a.SecondsSinceSeen + 's' : 'Trực tuyến'}"></span></span>
      <div class="row" style="gap:8px"><span class="seatn">#${a.SeatNo}</span><div class="nm">${esc(a.FullName)}</div></div>
      <div class="tiny mono muted">${esc(a.MSSV)}</div>
      <div class="row" style="gap:6px;margin-top:6px">${badge(ATT, a.Status)}
        ${+a.Violations ? `<span class="badge bad">⚠ ${a.Violations}</span>` : ''}
        ${a.Status === 'SUBMITTED' ? `<span class="badge">${a.Score10 !== null ? fmtNum(a.Score10, 1) + 'đ' : ''}</span>` : `<span class="tiny muted">${a.Answered}/${e.ItemCount} câu</span>`}
        ${+a.ExtraMinutes ? `<span class="badge acc">+${a.ExtraMinutes}′</span>` : ''}</div>
    </div>`;
  };
  const order = { LOCKED: 0, DOING: 1, READY: 2, JOINED: 3, SUBMITTED: 4 };
  const sorted = A.slice().sort((x, y) => (order[x.Status] - order[y.Status]) || (+y.Violations - +x.Violations) || (x.SeatNo - y.SeatNo));
  body.innerHTML = `<div style="display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:14px" class="mon-layout">
    <div>
      <div class="mon-grid">${sorted.map(tile).join('') || '<div class="empty">Chưa có sinh viên nào vào phòng.</div>'}</div>
      ${d.notJoined.length ? `<details class="card" style="margin-top:14px"><summary><b>${d.notJoined.length} SV chưa vào phòng</b></summary><div class="small" style="columns:3 180px;margin-top:8px">${d.notJoined.map(s => `<div><span class="mono">${esc(s.MSSV)}</span> ${esc(s.FullName)}</div>`).join('')}</div></details>` : ''}
    </div>
    <div class="card" style="padding:12px"><h3>Nhật ký giám sát</h3><div class="feed">${G.feed.map(ev => `<div class="${+ev.Counted ? 'cnt' : ''}"><span class="mono tiny muted">${fmtTime(ev.CreatedAt).slice(0, 5)}:${String(ev.CreatedAt).slice(17, 19)}</span> ${ev.SeatNo ? '<b>#' + ev.SeatNo + '</b> ' + esc(ev.FullName || '') + ' — ' : ''}${esc(evName(ev.Type))}${ev.Detail ? ': <span class="muted">' + esc(ev.Detail) + '</span>' : ''}</div>`).join('') || '<div class="muted">Chưa có sự kiện.</div>'}</div></div>
  </div>`;
  if (innerWidth < 900) $('.mon-layout').style.gridTemplateColumns = '1fr';
  $$('[data-att]', body).forEach(t => t.onclick = () => attemptModal(e, A.find(a => a.AttemptID === t.dataset.att)));
}

const EV = {
  hidden: 'Rời trang', blur: 'Mất tiêu điểm', fs_exit: 'Thoát toàn màn hình', split: 'Chia đôi / thu nhỏ màn hình', copy: 'Sao chép', cut: 'Cắt', paste: 'Dán',
  devtools: 'Mở DevTools', printscreen: 'Chụp màn hình', reload: 'Tải lại trang', second_device: 'Thiết bị thứ hai', contextmenu: 'Chuột phải', shortcut: 'Phím tắt',
  offline: 'Mất mạng', online: 'Có mạng lại', back_visible: 'Quay lại trang', focus_back: 'Trở lại cửa sổ', vao_phong: 'Vào phòng', vao_lai: 'Vào lại (cùng máy)',
  doi_may: 'Đổi máy', san_sang: 'Sẵn sàng', bat_dau_lam: 'Bắt đầu làm', nop_bai: 'Nộp bài', khoa_bai: 'BỊ KHOÁ', mo_khoa: 'Mở khoá', pin_sai: 'Sai PIN',
  tu_nop_vi_pham: 'Tự nộp do vi phạm', gv_bat_dau: 'GV bắt đầu', gv_cong_gio: 'Cộng giờ', gv_thu_bai: 'Thu bài', gv_thu_bai_sv: 'GV thu bài SV', gv_cho_doi_may: 'GV cho đổi máy', gv_xoa_luot: 'GV xoá lượt',
};
function evName(t) { return EV[t] || t; }

async function monAction(act, e, min) {
  try {
    if (act === 'open') {
      if (!(await modal({ title: 'Mở phòng thi?', html: `<p>Mã QR hiện ra, SV có <b>${e.PrepMinutes} phút</b> để vào phòng và bấm <b>Sẵn sàng</b>. Hết giờ chuẩn bị, bài tự bắt đầu.</p><p class="small muted">Câu hỏi được đóng băng từ thời điểm này.</p>`, okText: 'Mở phòng' }))) return;
      await gapi('openRoom', { examId: e.ExamID });
      G.projOpen = true;
    }
    if (act === 'proj') { G.projOpen = true; renderProjector(G.monData); return; }
    if (act === 'start') {
      const ready = G.monData.attempts.filter(a => a.Status === 'READY').length, total = G.monData.attempts.length;
      if (!(await modal({ title: 'Bắt đầu ngay?', html: `<p><b>${ready}/${total}</b> SV đã sẵn sàng. Thời gian ${e.DurationMinutes} phút bắt đầu tính từ bây giờ.</p>`, okText: 'Bắt đầu' }))) return;
      await gapi('startNow', { examId: e.ExamID });
    }
    if (act === 'plus') await gapi('addTime', { examId: e.ExamID, minutes: min });
    if (act === 'close') {
      const doing = G.monData.attempts.filter(a => a.Status !== 'SUBMITTED').length;
      if (!(await modal({ title: 'Thu bài và đóng phòng?', html: `<p>${doing ? `<b>${doing}</b> SV chưa nộp — bài của họ được chấm theo phần đã lưu.` : 'Tất cả SV đã nộp.'}</p><p class="small muted">Không mở lại được.</p>`, okText: 'Thu bài', danger: true }))) return;
      await gapi('closeExam', { examId: e.ExamID });
      closeProjector();
      toast('Đã thu bài.', 'ok');
    }
    await refreshMonitor(e.ExamID);
  } catch (err) { toast(err.message, 'bad'); }
}

async function attemptModal(e, a) {
  let log = { events: [] };
  try { log = await gapi('attemptLog', { examId: e.ExamID, attemptId: a.AttemptID }); } catch (x) { }
  const acts = [];
  if (a.Status === 'LOCKED') acts.push(['unlockAttempt', 'Mở khoá', 'primary']);
  if (a.Status !== 'SUBMITTED') acts.push(['allowRejoin', 'Cho đổi máy', ''], ['plus5', '+5 phút cho SV này', ''], ['forceSubmit', 'Thu bài SV này', 'danger']);
  if (e.Status === 'LOBBY') acts.push(['removeAttempt', 'Xoá khỏi phòng', 'danger']);
  await modal({
    title: `#${a.SeatNo} · ${a.FullName}`, cancelText: 'Đóng', hideOk: true, wide: true,
    html: `<div class="row small" style="gap:8px;margin-bottom:10px">${badge(ATT, a.Status)} <span class="mono">${esc(a.MSSV)}</span>
        <span class="muted">${esc(a.DeviceInfo || '')} · IP ${esc(a.IP || '')}</span></div>
      <div class="kpi" style="margin-bottom:12px"><div><b>${a.Violations}</b><span>Vi phạm</span></div><div><b>${a.Unlocks}</b><span>Lần mở khoá</span></div>
        <div><b>${a.Answered}/${e.ItemCount}</b><span>Đã trả lời</span></div><div><b>${a.SecondsSinceSeen ?? '—'}s</b><span>Lần cuối thấy</span></div></div>
      ${a.LockReason ? `<div class="callout bad" style="margin-bottom:10px">Khoá vì: ${esc(a.LockReason)}</div>` : ''}
      <div class="row" style="margin-bottom:12px">${acts.map(([k, l, c]) => `<button class="btn ${c}" data-a="${k}">${l}</button>`).join('')}</div>
      <div class="tbl-wrap" style="max-height:340px;overflow:auto"><table class="tbl"><thead><tr><th>Giờ</th><th>Sự kiện</th><th>Chi tiết</th></tr></thead><tbody>
      ${log.events.slice().reverse().map(ev => `<tr class="${+ev.Counted ? 'alert' : ''}"><td class="mono small">${String(ev.CreatedAt).slice(11, 19)}</td><td>${+ev.Counted ? '⚠ ' : ''}${esc(evName(ev.Type))}</td><td class="small muted">${esc(ev.Detail || '')}</td></tr>`).join('')}
      </tbody></table></div>`,
    onOpen: (bg, close) => $$('[data-a]', bg).forEach(b => b.onclick = async () => {
      const k = b.dataset.a;
      try {
        if (k === 'plus5') await gapi('addTime', { examId: e.ExamID, attemptId: a.AttemptID, minutes: 5 });
        else if (k === 'forceSubmit' || k === 'removeAttempt') {
          if (!confirm(k === 'forceSubmit' ? 'Thu bài của sinh viên này?' : 'Xoá sinh viên khỏi phòng?')) return;
          await gapi(k, { examId: e.ExamID, attemptId: a.AttemptID, note: 'Giám thị thu bài' });
        } else await gapi(k, { examId: e.ExamID, attemptId: a.AttemptID });
        toast('Đã thực hiện.', 'ok');
        close(true);
        refreshMonitor(e.ExamID);
      } catch (err) { toast(err.message, 'bad'); }
    }),
  });
}

/* ---------- Màn chiếu QR ---------- */

function renderProjector(d) {
  const p = $('#proj');
  const e = d.exam;
  if (e.Status === 'CLOSED' || e.Status === 'DRAFT') return closeProjector();
  const url = joinUrl(e.Code);
  const A = d.attempts;
  if (p.dataset.code !== e.Code) {
    p.innerHTML = `<button class="btn close" style="background:transparent;color:#fff;border-color:#45545e" id="proj-x">✕ Đóng (Esc)</button>
      <div class="qr"><img id="proj-qr" alt="Mã QR vào phòng thi"></div>
      <div><div class="ttl">${esc(e.Title)}</div><div style="opacity:.75;font-size:clamp(14px,1.6vw,24px)">Lớp ${esc(e.ClassCode)} · ${e.ItemCount} câu · ${e.DurationMinutes} phút</div>
        <div class="code">${esc(e.Code)}</div><div class="url">${esc(url.replace(/^https?:\/\//, ''))}</div>
        <div class="cd" id="proj-cd"></div><div class="cnt" id="proj-cnt"></div></div>`;
    $('#proj-qr').src = qrDataUrl(url, 10);
    p.dataset.code = e.Code;
    $('#proj-x').onclick = closeProjector;
  }
  const ready = A.filter(a => a.Status !== 'JOINED').length;
  const upd = () => {
    const ex = G.monData.exam;
    const cd = $('#proj-cd');
    if (!cd) return;
    if (ex.Status === 'LOBBY') cd.textContent = fmtClock(parseDT(ex.StartAt) - srvNow());
    else if (ex.Status === 'RUNNING') cd.textContent = 'Còn ' + fmtClock(parseDT(ex.EndsAt) - srvNow());
  };
  upd();
  clearInterval(p._t); p._t = setInterval(upd, 500);
  $('#proj-cnt').textContent = e.Status === 'LOBBY' ? `${A.length}/${d.rosterCount} đã vào phòng · ${ready} sẵn sàng` : `${A.filter(a => a.Status === 'SUBMITTED').length}/${A.length} đã nộp`;
  p.classList.remove('hidden');
  document.onkeydown = ev => { if (ev.key === 'Escape') closeProjector(); };
}
function closeProjector() {
  const p = $('#proj');
  G.projOpen = false;
  if (!p) return;
  clearInterval(p._t);
  p.classList.add('hidden');
  p.dataset.code = '';
  document.onkeydown = null;
}

/* ======================= Kết quả & khảo thí ======================= */

async function viewResults(v, examId) {
  const R = await gapi('results', { examId });
  const e = R.exam, st = R.stats;
  const pct = x => x === null || x === undefined ? '—' : Math.round(x) + '%';
  const bar = (x, cls = '') => `<div class="bar ${cls}"><i style="width:${Math.max(0, Math.min(100, x || 0))}%"></i></div>`;
  const maxH = Math.max(1, ...st.hist);
  const alphaTxt = st.alpha === null ? '—' : fmtNum(st.alpha, 2);
  const alphaNote = st.alpha === null ? 'cần ≥ 3 bài' : st.alpha >= 0.8 ? 'tốt' : st.alpha >= 0.7 ? 'chấp nhận được' : st.alpha >= 0.6 ? 'thấp' : 'kém';

  v.innerHTML = `<div class="row between" style="margin-bottom:12px">
    <div><a href="#exams" class="small">← Đề kiểm tra</a><h1 style="margin:.2em 0 0">${esc(e.Title)}</h1>
      <div class="muted small">${esc(e.CourseName)} (${esc(e.CourseCode)}) · Lớp ${esc(e.ClassCode)} · ${e.ItemCount} câu, ${fmtNum(e.MaxPoints, 1)} điểm thô · ${e.StartedAt ? fmtDateTime(e.StartedAt) : ''} ${badge(STATUS, e.Status)}</div></div>
    <div class="row noprint">
      ${e.Status !== 'CLOSED' ? `<a class="btn" href="#mon/${e.ExamID}">Giám sát</a>` : ''}
      <button class="btn" id="r-csv">⬇ Điểm (CSV)</button><button class="btn" id="r-csv2">⬇ Phân tích câu (CSV)</button><button class="btn" onclick="print()">🖨 In báo cáo</button>
      ${e.Status === 'CLOSED' ? `<button class="btn" id="r-pub">${e.ResultsPublished ? '🔒 Thu hồi đáp án' : '📢 Công bố đáp án cho SV'}</button><button class="btn primary" id="r-push">→ Chuyển điểm vào bảng điểm</button>` : ''}
    </div></div>

  <div class="kpi" style="margin-bottom:14px">
    <div><b>${st.n}/${st.n + st.notTaken + (st.joined - st.n)}</b><span>Bài đã chấm / sĩ số</span></div>
    <div><b>${fmtNum(st.mean, 2)}</b><span>Điểm trung bình</span></div><div><b>${fmtNum(st.sd, 2)}</b><span>Độ lệch chuẩn</span></div>
    <div><b>${fmtNum(st.median, 2)}</b><span>Trung vị</span></div><div><b>${fmtNum(st.min, 1)} – ${fmtNum(st.max, 1)}</b><span>Thấp nhất – cao nhất</span></div>
    <div><b>${pct(st.passRate)}</b><span>Đạt ≥ 5</span></div><div><b>${alphaTxt}</b><span>Độ tin cậy α (${alphaNote})</span></div>
    <div><b>${fmtNum(st.sem, 2)}</b><span>Sai số đo lường SEM</span></div><div><b>${st.violators}</b><span>SV có vi phạm</span></div>
  </div>

  <div class="grid2" style="grid-template-columns:repeat(auto-fit,minmax(340px,1fr));margin-bottom:14px">
    <div class="card"><h2>Phổ điểm</h2><div class="hist">${st.hist.map(c => `<div style="height:${c / maxH * 100}%"><span>${c || ''}</span></div>`).join('')}</div>
      <div class="hist-x">${st.hist.map((_, i) => `<span>${i}–${i + 1}</span>`).join('')}</div></div>
    <div class="card"><h2>Phân bố mức nhận thức (Bloom)</h2>${Object.entries(R.bloom).map(([b, c]) => `<div class="row" style="margin:6px 0"><span style="width:110px" class="small">${+b ? b + '. ' + BLOOM[b] : 'Chưa gán'}</span><span class="grow">${bar(c / e.ItemCount * 100)}</span><span class="small mono" style="width:60px;text-align:right">${c} câu</span></div>`).join('')}</div>
  </div>

  <div class="card"><h2>Mức đạt chuẩn đầu ra (CLO)</h2>
    <p class="small muted">Một SV <b>đạt CLO</b> khi đạt ≥ ngưỡng (% điểm các câu thuộc CLO đó). CLO của lớp <b>đạt</b> khi tỉ lệ SV đạt ≥ mục tiêu. Ngưỡng và mục tiêu chỉnh ở tab Chuẩn đầu ra.</p>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>CLO</th><th class="num">Số câu</th><th class="num">Tỉ trọng</th><th>Điểm TB phần CLO</th><th class="num">Ngưỡng SV</th><th>Tỉ lệ SV đạt</th><th class="num">Mục tiêu</th><th>Kết luận</th></tr></thead><tbody>
    ${R.clos.map(c => `<tr><td><b>${esc(c.code)}</b><div class="tiny muted">${esc(c.description)}</div></td><td class="num">${c.items}</td><td class="num">${Math.round(c.weight)}%</td>
      <td><div class="row" style="gap:6px"><span class="grow">${bar(c.avgPct)}</span><span class="mono small">${pct(c.avgPct)}</span></div></td><td class="num">${Math.round(c.passThreshold)}%</td>
      <td><div class="row" style="gap:6px"><span class="grow">${bar(c.achievedRate, c.met ? 'ok' : 'bad')}</span><span class="mono small">${pct(c.achievedRate)} (${c.achievedCount}/${st.n})</span></div></td>
      <td class="num">${Math.round(c.targetRate)}%</td><td>${c.cloId ? (c.met === null ? '—' : c.met ? '<span class="badge ok">ĐẠT</span>' : '<span class="badge bad">CHƯA ĐẠT</span>') : '<span class="tiny muted">không xét</span>'}</td></tr>`).join('')}
    </tbody></table></div></div>

  <div class="card"><div class="row between"><h2 style="margin:0">Phân tích câu hỏi</h2><label class="check small noprint"><input type="checkbox" id="r-flagonly"> Chỉ câu cần xem lại</label></div>
    <p class="small muted">p = độ khó (tỉ lệ làm đúng; 0,3–0,8 là tốt) · D = độ phân biệt nhóm 27% cao/thấp (≥ 0,4 rất tốt, 0,3–0,39 tốt, 0,2–0,29 tạm, &lt; 0,2 cần sửa) · r<sub>pb</sub> = tương quan câu–tổng (đã loại câu đó). Bấm một dòng để xem phương án nhiễu.</p>
    <div class="tbl-wrap"><table class="tbl" id="r-items"><thead><tr><th class="num">#</th><th>Câu hỏi</th><th>CLO</th><th class="num">Bloom</th><th class="num">p</th><th class="num">D</th><th class="num">r<sub>pb</sub></th><th class="num">Bỏ trống</th><th>Nhận xét</th></tr></thead><tbody></tbody></table></div></div>

  <div class="card"><h2>Bảng điểm & mức đạt CLO từng sinh viên</h2>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>MSSV</th><th>Họ tên</th><th>Trạng thái</th><th class="num">Điểm</th><th class="num">Thô</th>${R.clos.filter(c => c.cloId).map(c => `<th class="num">${esc(c.code)}</th>`).join('')}<th class="num">Vi phạm</th><th></th></tr></thead><tbody>
    ${R.students.map(s => `<tr class="${s.violations ? 'alert' : ''}"><td class="mono">${esc(s.mssv)}</td><td>${esc(s.name)}</td><td>${badge(ATT, s.status)} <span class="tiny muted">${esc(REASON[s.reason] || '')}</span></td>
      <td class="num"><b>${fmtNum(s.score10, 2)}</b></td><td class="num">${fmtNum(s.raw, 2)}</td>
      ${R.clos.filter(c => c.cloId).map(c => { const v = s.clo[c.cloId]; return `<td class="num" style="color:${v === undefined ? '' : v >= c.passThreshold ? 'var(--ok)' : 'var(--bad)'}">${v === undefined ? '—' : Math.round(v) + '%'}</td>`; }).join('')}
      <td class="num">${s.violations || ''}</td><td><button class="btn sm noprint" data-log="${s.attemptId}">Nhật ký</button></td></tr>`).join('')}
    ${R.notTaken.map(s => `<tr><td class="mono">${esc(s.MSSV)}</td><td>${esc(s.FullName)}</td><td><span class="badge">Không dự thi</span></td><td colspan="${3 + R.clos.filter(c => c.cloId).length}"></td><td></td></tr>`).join('')}
    </tbody></table></div></div>`;

  const renderItems = () => {
    const only = $('#r-flagonly').checked;
    const tb = $('#r-items tbody');
    tb.innerHTML = R.items.filter(it => !only || it.flags.length).map(it => {
      const pc = it.p === null ? '' : it.p > .9 || it.p < .2 ? 'color:var(--bad)' : it.p >= .3 && it.p <= .8 ? 'color:var(--ok)' : '';
      const dc = it.D === null ? '' : it.D < .2 ? 'color:var(--bad)' : it.D >= .3 ? 'color:var(--ok)' : 'color:var(--warn)';
      return `<tr data-it="${it.qid}" style="cursor:pointer"><td class="num">${it.ord}</td><td style="max-width:420px">${esc(it.stem.length > 140 ? it.stem.slice(0, 140) + '…' : it.stem)}</td>
        <td>${esc(it.cloCode || '—')}</td><td class="num">${it.bloom || '—'}</td><td class="num" style="${pc}">${fmtNum(it.p, 2)}</td><td class="num" style="${dc}">${fmtNum(it.D, 2)}</td>
        <td class="num">${fmtNum(it.rpb, 2)}</td><td class="num">${it.blank || ''}</td><td>${it.flags.map(f => `<span class="badge ${/âm|kém|khó/.test(f) ? 'bad' : 'warn'}">${esc(f)}</span>`).join(' ')}</td></tr>`;
    }).join('') || '<tr><td colspan="9" class="empty">Không có câu cần xem lại 👍</td></tr>';
    $$('[data-it]', tb).forEach(tr => tr.onclick = () => {
      const it = R.items.find(x => x.qid === tr.dataset.it);
      modal({
        title: 'Câu ' + it.ord, cancelText: 'Đóng', hideOk: true,
        html: `<p style="white-space:pre-wrap">${esc(it.stem)}</p><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Phương án</th><th class="num">Số SV chọn</th><th>Tỉ lệ</th><th class="num">Nhóm cao</th><th class="num">Nhóm thấp</th></tr></thead><tbody>
          ${it.options.map(o => `<tr style="${o.isKey ? 'background:var(--ok-soft)' : ''}"><td>${o.isKey ? '✔ ' : ''}${esc(o.key)}${it.type !== 'short' ? '. ' + esc(o.text) : ''}</td><td class="num">${o.count}</td><td>${bar(o.rate * 100, o.isKey ? 'ok' : 'warn')}</td><td class="num">${o.upper ?? ''}</td><td class="num">${o.lower ?? ''}</td></tr>`).join('')}
          </tbody></table></div><p class="small muted">Phương án nhiễu tốt: được nhóm thấp chọn nhiều hơn nhóm cao. Phương án không ai chọn nên thay.</p>`,
      });
    });
  };
  $('#r-flagonly').onchange = renderItems;
  renderItems();

  $$('[data-log]', v).forEach(b => b.onclick = async () => {
    const lg = await gapi('attemptLog', { examId, attemptId: b.dataset.log });
    modal({
      title: `${lg.attempt.MSSV} · ${lg.attempt.FullName}`, cancelText: 'Đóng', hideOk: true, wide: true,
      html: `<p class="small muted">${esc(lg.attempt.DeviceInfo || '')} · IP ${esc(lg.attempt.IP || '')}</p><div class="tbl-wrap" style="max-height:420px;overflow:auto"><table class="tbl"><thead><tr><th>Giờ</th><th>Sự kiện</th><th>Chi tiết</th></tr></thead><tbody>
        ${lg.events.map(ev => `<tr class="${+ev.Counted ? 'alert' : ''}"><td class="mono small">${String(ev.CreatedAt).slice(11, 19)}</td><td>${+ev.Counted ? '⚠ ' : ''}${esc(evName(ev.Type))}</td><td class="small muted">${esc(ev.Detail || '')}</td></tr>`).join('')}</tbody></table></div>`,
    });
  });

  const cloCols = R.clos.filter(c => c.cloId);
  $('#r-csv').onclick = () => downloadCSV(`diem_${e.ClassCode}_${e.Title.replace(/[^\p{L}\p{N}]+/gu, '_')}.csv`, [
    ['MSSV', 'Họ tên', 'Trạng thái', 'Lý do nộp', 'Điểm (10)', 'Điểm thô', 'Tối đa', ...cloCols.map(c => c.code + ' (%)'), 'Vi phạm', 'Nộp lúc'],
    ...R.students.map(s => [s.mssv, s.name, s.status, REASON[s.reason] || '', s.score10, s.raw, s.max, ...cloCols.map(c => s.clo[c.cloId] ?? ''), s.violations, s.submittedAt || '']),
    ...R.notTaken.map(s => [s.MSSV, s.FullName, 'Không dự thi']),
  ]);
  $('#r-csv2').onclick = () => downloadCSV(`phan_tich_cau_${e.ClassCode}.csv`, [
    ['Câu', 'Nội dung', 'CLO', 'Bloom', 'Độ khó (GV)', 'Điểm', 'p', 'D', 'r_pb', 'Bỏ trống', 'Nhận xét', 'Phân bố phương án'],
    ...R.items.map(it => [it.ord, it.stem, it.cloCode || '', it.bloom || '', DIFF[it.difficulty], it.points, it.p?.toFixed(3), it.D?.toFixed(3), it.rpb?.toFixed(3), it.blank, it.flags.join('; '), it.options.map(o => `${o.key}${o.isKey ? '*' : ''}:${o.count}`).join(' ')]),
    [], ['CLO', 'Số câu', 'Tỉ trọng %', 'Điểm TB %', 'Ngưỡng SV %', 'Tỉ lệ SV đạt %', 'Mục tiêu %', 'Kết luận'],
    ...R.clos.map(c => [c.code, c.items, Math.round(c.weight), c.avgPct?.toFixed(1), c.passThreshold, c.achievedRate?.toFixed(1), c.targetRate, c.cloId ? (c.met ? 'ĐẠT' : 'CHƯA ĐẠT') : '']),
    [], ['N', st.n, 'TB', st.mean?.toFixed(2), 'SD', st.sd?.toFixed(2), 'alpha', st.alpha?.toFixed(3), 'SEM', st.sem?.toFixed(3)],
  ]);
  const pub = $('#r-pub');
  if (pub) pub.onclick = async () => {
    try { await gapi('publishResults', { examId, published: !e.ResultsPublished }); toast(e.ResultsPublished ? 'Đã thu hồi.' : 'Đã công bố: SV mở lại link phòng thi để xem đáp án và giải thích.', 'ok', 5000); route(); }
    catch (err) { toast(err.message, 'bad'); }
  };
  const push = $('#r-push');
  if (push) push.onclick = async () => {
    const r = await modal({
      title: 'Chuyển điểm vào bảng điểm lớp', okText: 'Chuyển điểm',
      html: `<p class="small">Ghi điểm thang 10 của <b>${st.n}</b> bài đã chấm vào một cột điểm của lớp ${esc(e.ClassCode)} trên diemdanhsv.com (SV xem được ở trang Xem điểm). Ghi đè nếu cột đã có điểm.</p>
        <div class="grid2"><div><label class="f">Tên cột điểm</label><input type="text" id="pg-name" value="Giữa kỳ"></div><div><label class="f">Trọng số (%)</label><input type="number" id="pg-w" min="0" max="100" value="30"></div></div>`,
      onOk: bg => gapi('pushGrades', { examId, columnName: $('#pg-name', bg).value, weight: +$('#pg-w', bg).value }),
    });
    if (r) toast(`Đã ghi ${r.written} điểm vào cột "${r.columnName}"${r.created ? ' (cột mới)' : ''}.`, 'ok', 6000);
  };
}

/* ======================= Ngân hàng câu hỏi ======================= */

async function viewBank(v) {
  G.questions = await gapi('listQuestions', { courseId: G.course.CourseID });
  const filt = { q: '', clo: '', bloom: '', diff: '' };
  v.innerHTML = `<div class="row between" style="margin-bottom:12px"><div><h1 style="margin:0">Ngân hàng câu hỏi</h1><div class="muted small">${esc(G.course.CourseName)} · <span id="b-count"></span></div></div>
    <div class="row"><button class="btn" id="b-tpl">⬇ File mẫu</button><button class="btn" id="b-exp">⬇ Xuất CSV</button><button class="btn" id="b-imp">⬆ Nhập từ Excel/CSV</button><button class="btn primary" id="b-add">＋ Thêm câu hỏi</button></div></div>
    ${!G.clos.length ? '<div class="callout warn" style="margin-bottom:12px">Chưa khai báo CLO cho học phần — <a href="#clo">khai báo trước</a> để gắn câu hỏi với chuẩn đầu ra.</div>' : ''}
    <div class="card"><div class="row" style="margin-bottom:10px">
      <input type="search" id="b-q" placeholder="Tìm nội dung, chủ đề…" style="max-width:280px">
      <select id="b-clo" style="max-width:160px"><option value="">Mọi CLO</option>${G.clos.map(c => `<option value="${c.CloID}">${esc(c.Code)}</option>`).join('')}<option value="_none">Chưa gắn CLO</option></select>
      <select id="b-bloom" style="max-width:160px"><option value="">Mọi mức Bloom</option>${Object.entries(BLOOM).map(([k, n]) => `<option value="${k}">${k}. ${n}</option>`).join('')}</select>
      <select id="b-diff" style="max-width:140px"><option value="">Mọi độ khó</option>${Object.entries(DIFF).map(([k, n]) => `<option value="${k}">${n}</option>`).join('')}</select>
    </div><div id="b-list"></div></div>`;
  const list = () => {
    const qs = G.questions.filter(q => (!filt.clo || (filt.clo === '_none' ? !q.CloID : q.CloID === filt.clo)) && (!filt.bloom || q.Bloom === +filt.bloom) && (!filt.diff || q.Difficulty === +filt.diff) && (!filt.q || (q.Stem + ' ' + q.Topic + ' ' + q.Explanation).toLowerCase().includes(filt.q.toLowerCase())));
    $('#b-count').textContent = `${qs.length}/${G.questions.length} câu`;
    $('#b-list').innerHTML = qs.map((q, i) => `<div class="q-row"><span class="mono muted">${i + 1}</span><div>${qSummary(q, { expl: true, used: true })}</div>
      <div class="row" style="gap:6px"><button class="btn sm" data-ed="${q.QuestionID}">Sửa</button><button class="btn sm" data-cp="${q.QuestionID}" title="Nhân bản">⧉</button><button class="btn sm danger" data-rm="${q.QuestionID}">Xoá</button></div></div>`).join('')
      || '<div class="empty">Chưa có câu hỏi. Thêm từng câu hoặc nhập hàng loạt từ Excel.</div>';
    $$('[data-ed]').forEach(b => b.onclick = () => questionModal(G.questions.find(q => q.QuestionID === b.dataset.ed)));
    $$('[data-cp]').forEach(b => b.onclick = () => { const q = G.questions.find(x => x.QuestionID === b.dataset.cp); questionModal(Object.assign({}, q, { QuestionID: '' })); });
    $$('[data-rm]').forEach(b => b.onclick = async () => {
      if (!(await modal({ title: 'Xoá câu hỏi?', html: '<p>Câu bị ẩn khỏi ngân hàng. Các đề đã thi vẫn giữ bản lưu của câu này.</p>', okText: 'Xoá', danger: true }))) return;
      try { await gapi('deleteQuestion', { questionId: b.dataset.rm }); G.questions = G.questions.filter(q => q.QuestionID !== b.dataset.rm); list(); } catch (e) { toast(e.message, 'bad'); }
    });
  };
  $('#b-q').oninput = e => { filt.q = e.target.value; list(); };
  $('#b-clo').onchange = e => { filt.clo = e.target.value; list(); };
  $('#b-bloom').onchange = e => { filt.bloom = e.target.value; list(); };
  $('#b-diff').onchange = e => { filt.diff = e.target.value; list(); };
  $('#b-add').onclick = () => questionModal(null);
  $('#b-imp').onclick = importModal;
  $('#b-tpl').onclick = () => downloadCSV('mau_nhap_cau_hoi.csv', [IMPORT_HEAD,
    ['single', 'Trong thang độ cứng Mohs, khoáng vật chuẩn có độ cứng 7 là:', 'Thạch anh', 'Fenspat octocla', 'Topaz', 'Apatit', '', '', 'A', 'Thang Mohs: apatit 5, octocla 6, thạch anh 7, topaz 8.', 'CLO1', '1', '1', '1', 'Tính chất vật lý'],
    ['multi', 'Những khoáng vật nào có cát khai hoàn toàn theo 3 phương?', 'Calcit', 'Halit', 'Thạch anh', 'Muscovit', '', '', 'A,B', 'Calcit: cát khai mặt thoi 3 phương; halit: lập phương.', 'CLO1', '2', '2', '1', 'Cát khai'],
    ['short', 'Khoáng vật có thành phần SiO2, độ cứng 7, vết vỡ vỏ sò là gì?', '', '', '', '', '', '', 'thạch anh|quartz', 'Thạch anh không có cát khai.', 'CLO2', '1', '1', '1', 'Khoáng vật tạo đá']]);
  $('#b-exp').onclick = () => downloadCSV(`ngan_hang_${G.course.CourseCode}.csv`, [IMPORT_HEAD, ...G.questions.map(q => {
    const o = q.Options.map(x => x.text); while (o.length < 6) o.push('');
    return [q.Type, q.Stem, ...o.slice(0, 6), q.Type === 'short' ? q.Answer.join('|') : q.Answer.join(','), q.Explanation, cloCode(q.CloID), q.Bloom || '', q.Difficulty, q.Points, q.Topic];
  })]);
  list();
}

const IMPORT_HEAD = ['loai', 'cau_hoi', 'A', 'B', 'C', 'D', 'E', 'F', 'dap_an', 'giai_thich', 'clo', 'bloom', 'do_kho', 'diem', 'chu_de'];

function questionModal(q) {
  const isNew = !q || !q.QuestionID;
  q = q || { Type: 'single', Stem: '', Options: [{ key: 'A', text: '' }, { key: 'B', text: '' }, { key: 'C', text: '' }, { key: 'D', text: '' }], Answer: [], Explanation: '', CloID: '', Bloom: 1, Difficulty: 2, Points: 1, Topic: '', ImageID: null };
  let imageId = q.ImageID || null;
  const optRow = (o, i, type) => `<div class="opt-edit"><span class="kk"><input type="${type === 'single' ? 'radio' : 'checkbox'}" name="qa" value="${i}" ${q.Answer.includes(o.key) ? 'checked' : ''} title="Đáp án đúng"></span>
      <input type="text" data-o value="${esc(o.text)}" placeholder="Phương án ${'ABCDEFGH'[i]}"><button class="btn sm ghost" data-rmo title="Bỏ">✕</button></div>`;
  return modal({
    title: isNew ? 'Thêm câu hỏi' : 'Sửa câu hỏi', okText: 'Lưu', wide: true,
    html: `<div class="grid3"><div><label class="f">Loại câu</label><select id="q-type">${Object.entries(QTYPE).map(([k, n]) => `<option value="${k}" ${q.Type === k ? 'selected' : ''}>${n}</option>`).join('')}</select></div>
      <div><label class="f">CLO</label><select id="q-clo"><option value="">— Chưa gắn —</option>${G.clos.map(c => `<option value="${c.CloID}" ${q.CloID === c.CloID ? 'selected' : ''}>${esc(c.Code)} — ${esc(c.Description.slice(0, 50))}</option>`).join('')}</select></div>
      <div><label class="f">Mức Bloom</label><select id="q-bloom"><option value="">—</option>${Object.entries(BLOOM).map(([k, n]) => `<option value="${k}" ${+q.Bloom === +k ? 'selected' : ''}>${k}. ${n}</option>`).join('')}</select></div>
      <div><label class="f">Độ khó dự kiến</label><select id="q-diff">${Object.entries(DIFF).map(([k, n]) => `<option value="${k}" ${+q.Difficulty === +k ? 'selected' : ''}>${n}</option>`).join('')}</select></div>
      <div><label class="f">Điểm mặc định</label><input type="number" id="q-pts" step="0.25" min="0.25" value="${q.Points}"></div>
      <div><label class="f">Chủ đề / chương</label><input type="text" id="q-topic" value="${esc(q.Topic)}"></div></div>
      <div style="margin-top:12px"><label class="f">Nội dung câu hỏi</label><textarea id="q-stem" rows="3">${esc(q.Stem)}</textarea></div>
      <div style="margin-top:10px" class="row"><div id="q-imgbox">${imageId ? `<img class="thumb" src="${imgUrl(imageId)}">` : ''}</div>
        <label class="btn sm">🖼 ${imageId ? 'Đổi ảnh' : 'Thêm ảnh (mẫu đá, lát mỏng…)'}<input type="file" id="q-img" accept="image/*" class="hidden"></label>
        ${imageId ? '<button class="btn sm danger" id="q-imgrm">Bỏ ảnh</button>' : ''}</div>
      <div style="margin-top:12px" id="q-optwrap"><label class="f">Phương án <span class="tiny muted">(đánh dấu ô bên trái cho đáp án đúng)</span></label><div id="q-opts"></div><button class="btn sm" id="q-addopt">＋ Thêm phương án</button></div>
      <div style="margin-top:12px" id="q-shortwrap"><label class="f">Các đáp án được chấp nhận <span class="tiny muted">(mỗi dòng một cách viết; không phân biệt hoa/thường, chấp nhận viết không dấu)</span></label><textarea id="q-short" rows="3">${q.Type === 'short' ? esc(q.Answer.join('\n')) : ''}</textarea></div>
      <div style="margin-top:12px"><label class="f">Giải thích đáp án <span class="tiny muted">(SV xem sau khi công bố)</span></label><textarea id="q-expl" rows="3">${esc(q.Explanation)}</textarea></div>`,
    onOpen: bg => {
      const opts = q.Options.length ? q.Options.slice() : [{ key: 'A', text: '' }, { key: 'B', text: '' }];
      const draw = () => {
        const type = $('#q-type', bg).value;
        $('#q-optwrap', bg).classList.toggle('hidden', type === 'short');
        $('#q-shortwrap', bg).classList.toggle('hidden', type !== 'short');
        const cur = $$('#q-opts .opt-edit', bg).map((r, i) => ({ text: $('[data-o]', r).value, on: $('input[name=qa]', r).checked }));
        if (cur.length) { opts.length = 0; cur.forEach((c, i) => opts.push({ key: 'ABCDEFGH'[i], text: c.text })); q.Answer = cur.map((c, i) => c.on ? 'ABCDEFGH'[i] : null).filter(Boolean); }
        $('#q-opts', bg).innerHTML = opts.map((o, i) => optRow(o, i, type)).join('');
        $$('[data-rmo]', bg).forEach((b, i) => b.onclick = () => { $$('#q-opts .opt-edit', bg)[i].remove(); draw(); });
      };
      $('#q-type', bg).onchange = () => { if ($('#q-type', bg).value === 'single') q.Answer = q.Answer.slice(0, 1); draw(); };
      $('#q-addopt', bg).onclick = () => { draw(); if (opts.length >= 8) return toast('Tối đa 8 phương án.'); opts.push({ key: '', text: '' }); $('#q-opts', bg).insertAdjacentHTML('beforeend', optRow(opts[opts.length - 1], opts.length - 1, $('#q-type', bg).value)); draw(); };
      draw();
      $('#q-img', bg).onchange = async ev => {
        const f = ev.target.files[0]; if (!f) return;
        try {
          const dataUrl = await shrinkImage(f, 1600, 0.85);
          imageId = (await gapi('uploadImage', { dataUrl })).imageId;
          $('#q-imgbox', bg).innerHTML = `<img class="thumb" src="${imgUrl(imageId)}">`;
          toast('Đã tải ảnh lên.', 'ok');
        } catch (e) { toast(e.message, 'bad'); }
      };
      const rm = $('#q-imgrm', bg); if (rm) rm.onclick = () => { imageId = null; $('#q-imgbox', bg).innerHTML = '<span class="small muted">(đã bỏ ảnh)</span>'; rm.remove(); };
    },
    onOk: async bg => {
      const type = $('#q-type', bg).value;
      const rows = $$('#q-opts .opt-edit', bg);
      const options = rows.map((r, i) => ({ key: 'ABCDEFGH'[i], text: $('[data-o]', r).value }));
      const answer = type === 'short' ? $('#q-short', bg).value.split('\n').map(s => s.trim()).filter(Boolean) : rows.map((r, i) => $('input[name=qa]', r).checked ? 'ABCDEFGH'[i] : null).filter(Boolean);
      const payload = { type, stem: $('#q-stem', bg).value, options, answer, explanation: $('#q-expl', bg).value, clo: $('#q-clo', bg).value, bloom: $('#q-bloom', bg).value, difficulty: $('#q-diff', bg).value, points: $('#q-pts', bg).value, topic: $('#q-topic', bg).value, imageId };
      await gapi('saveQuestion', { courseId: G.course.CourseID, questionId: q.QuestionID || '', question: payload });
      G.questions = await gapi('listQuestions', { courseId: G.course.CourseID });
      toast('Đã lưu câu hỏi.', 'ok');
      if (location.hash.startsWith('#bank')) route();
    },
  });
}

function shrinkImage(file, maxSide, quality) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => {
      const k = Math.min(1, maxSide / Math.max(img.width, img.height));
      const c = document.createElement('canvas');
      c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      resolve(c.toDataURL(file.type === 'image/png' && k === 1 ? 'image/png' : 'image/jpeg', quality));
      URL.revokeObjectURL(img.src);
    };
    img.onerror = () => reject(new Error('Không đọc được ảnh.'));
    img.src = URL.createObjectURL(file);
  });
}

function tableToQuestions(text) {
  const rows = parseTable(text);
  if (rows.length < 2) throw new Error('Cần dòng tiêu đề và ít nhất 1 dòng câu hỏi.');
  const head = rows[0].map(deaccent);
  const idx = name => head.indexOf(name);
  const pick = (r, names) => { for (const n of names) { const i = idx(n); if (i >= 0) return (r[i] || '').trim(); } return ''; };
  if (idx('cau_hoi') < 0 && idx('noi_dung') < 0) throw new Error('Không thấy cột "cau_hoi". Dòng đầu phải là tiêu đề như file mẫu.');
  return rows.slice(1).map(r => {
    const options = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'].map(k => pick(r, [k])).filter(Boolean);
    let type = pick(r, ['loai', 'type']).toLowerCase() || (options.length ? 'single' : 'short');
    type = { 'mot': 'single', '1': 'single', 'nhieu': 'multi', 'ngan': 'short', 'tu_luan_ngan': 'short' }[deaccent(type)] || type;
    let ans = pick(r, ['dap_an', 'answer']);
    if (type === 'single' && /[,;]/.test(ans)) type = 'multi';
    return { type, stem: pick(r, ['cau_hoi', 'noi_dung']), options, answer: type === 'short' ? ans.split('|') : ans, explanation: pick(r, ['giai_thich', 'explanation']),
      clo: pick(r, ['clo']), bloom: pick(r, ['bloom', 'muc_bloom']), difficulty: pick(r, ['do_kho']) || 2, points: pick(r, ['diem']) || 1, topic: pick(r, ['chu_de', 'chuong']) };
  });
}

function importModal() {
  let parsed = null;
  modal({
    title: 'Nhập câu hỏi hàng loạt', okText: 'Nhập vào ngân hàng', wide: true,
    html: `<p class="small">Dán bảng từ <b>Excel / Google Sheets</b> (chọn vùng có dòng tiêu đề → Ctrl+C → dán vào ô dưới) hoặc chọn file <b>CSV</b>. Tải <b>File mẫu</b> để xem đúng các cột:</p>
      <p class="tiny mono" style="background:#f6f8f7;padding:8px;border-radius:8px">${IMPORT_HEAD.join(' | ')}</p>
      <ul class="small muted"><li><b>loai</b>: single (một đáp án) · multi (nhiều đáp án) · short (trả lời ngắn)</li><li><b>dap_an</b>: A hoặc A,C; câu short: các cách viết cách nhau bởi dấu |</li><li><b>clo</b>: mã như CLO1 (phải khai báo trước) · <b>bloom</b>: 1–6 hoặc Nhớ/Hiểu/Vận dụng… · <b>do_kho</b>: 1–3 hoặc Dễ/TB/Khó</li></ul>
      <div class="row" style="margin-bottom:8px"><label class="btn sm">📄 Chọn file CSV<input type="file" id="im-file" accept=".csv,.tsv,.txt" class="hidden"></label></div>
      <textarea id="im-text" rows="8" placeholder="Dán bảng vào đây…" style="font-family:var(--mono);font-size:.82rem"></textarea>
      <div class="row" style="margin-top:8px"><button class="btn" id="im-check">Kiểm tra</button><span id="im-res" class="small"></span></div>`,
    onOpen: bg => {
      $('#im-file', bg).onchange = async ev => { const f = ev.target.files[0]; if (f) { $('#im-text', bg).value = await f.text(); $('#im-check', bg).click(); } };
      $('#im-check', bg).onclick = async () => {
        try {
          parsed = tableToQuestions($('#im-text', bg).value);
          const r = await gapi('importQuestions', { courseId: G.course.CourseID, rows: parsed, dryRun: true });
          $('#im-res', bg).innerHTML = r.errors.length
            ? `<span style="color:var(--bad)"><b>${r.errors.length} dòng lỗi</b> (${r.valid} hợp lệ):</span><br>` + r.errors.slice(0, 20).map(x => `Dòng ${x.row + 1}: ${esc(x.message)}`).join('<br>')
            : `<span style="color:var(--ok)">✓ ${r.valid} câu hợp lệ — bấm "Nhập vào ngân hàng".</span>`;
        } catch (e) { $('#im-res', bg).innerHTML = `<span style="color:var(--bad)">${esc(e.message)}</span>`; parsed = null; }
      };
    },
    onOk: async bg => {
      if (!parsed) parsed = tableToQuestions($('#im-text', bg).value);
      const r = await gapi('importQuestions', { courseId: G.course.CourseID, rows: parsed });
      if (r.errors.length) { $('#im-check', bg).click(); throw new Error('Còn dòng lỗi — sửa rồi nhập lại (chưa nhập câu nào).'); }
      toast(`Đã nhập ${r.imported} câu.`, 'ok');
      route();
    },
  });
}

/* ======================= CLO ======================= */

async function viewClo(v) {
  G.clos = await gapi('listClos', { courseId: G.course.CourseID });
  const used = id => G.questions.filter(q => q.CloID === id).length;
  v.innerHTML = `<div class="row between" style="margin-bottom:12px"><div><h1 style="margin:0">Chuẩn đầu ra học phần (CLO)</h1><div class="muted small">${esc(G.course.CourseName)}</div></div><button class="btn primary" id="c-add">＋ Thêm CLO</button></div>
    <div class="callout small" style="margin-bottom:12px">Mỗi câu hỏi gắn với một CLO. Sau bài kiểm tra, hệ thống tính: <b>% điểm</b> mỗi SV đạt trên các câu của từng CLO → SV <b>đạt CLO</b> nếu ≥ <b>ngưỡng</b>; CLO của lớp <b>đạt</b> nếu tỉ lệ SV đạt ≥ <b>mục tiêu</b>. (Mặc định 50% và 70% — chỉnh theo đề cương học phần.)</div>
    ${G.clos.length ? `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Mã</th><th>Mô tả</th><th class="num">Ngưỡng SV</th><th class="num">Mục tiêu lớp</th><th class="num">Số câu</th><th></th></tr></thead><tbody>
      ${G.clos.map(c => `<tr><td><b>${esc(c.Code)}</b></td><td>${esc(c.Description)}</td><td class="num">${Math.round(c.PassThreshold)}%</td><td class="num">${Math.round(c.TargetRate)}%</td><td class="num">${used(c.CloID)}</td>
        <td><div class="row" style="gap:6px;justify-content:flex-end"><button class="btn sm" data-ce="${c.CloID}">Sửa</button><button class="btn sm danger" data-cd="${c.CloID}">Xoá</button></div></td></tr>`).join('')}
    </tbody></table></div>` : '<div class="card empty">Chưa có CLO. Thêm theo đề cương học phần (VD: CLO1 … CLO4).</div>'}`;
  $('#c-add').onclick = () => cloModal(null);
  $$('[data-ce]').forEach(b => b.onclick = () => cloModal(G.clos.find(c => c.CloID === b.dataset.ce)));
  $$('[data-cd]').forEach(b => b.onclick = async () => {
    const n = used(b.dataset.cd);
    if (!(await modal({ title: 'Xoá CLO?', html: n ? `<p><b>${n}</b> câu hỏi đang gắn CLO này sẽ thành "chưa gắn CLO".</p>` : '<p>Xoá CLO này?</p>', okText: 'Xoá', danger: true }))) return;
    try { await gapi('deleteClo', { cloId: b.dataset.cd }); route(); } catch (e) { toast(e.message, 'bad'); }
  });
}
function cloModal(c) {
  return modal({
    title: c ? 'Sửa ' + c.Code : 'Thêm CLO', okText: 'Lưu',
    html: `<div class="grid2"><div><label class="f">Mã</label><input type="text" id="cl-code" value="${esc(c ? c.Code : 'CLO' + (G.clos.length + 1))}"></div><div><label class="f">Thứ tự</label><input type="number" id="cl-ord" value="${c ? c.SortOrder : G.clos.length + 1}"></div></div>
      <div style="margin-top:10px"><label class="f">Mô tả chuẩn đầu ra</label><textarea id="cl-desc" rows="3">${esc(c ? c.Description : '')}</textarea></div>
      <div class="grid2" style="margin-top:10px"><div><label class="f">Ngưỡng SV đạt (% điểm)</label><input type="number" id="cl-pass" min="0" max="100" value="${c ? c.PassThreshold : 50}"></div>
      <div><label class="f">Mục tiêu lớp (% SV đạt)</label><input type="number" id="cl-target" min="0" max="100" value="${c ? c.TargetRate : 70}"></div></div>`,
    onOk: async bg => {
      await gapi('saveClo', { courseId: G.course.CourseID, cloId: c ? c.CloID : '', code: $('#cl-code', bg).value, description: $('#cl-desc', bg).value, passThreshold: +$('#cl-pass', bg).value, targetRate: +$('#cl-target', bg).value, sortOrder: +$('#cl-ord', bg).value });
      toast('Đã lưu.', 'ok'); route();
    },
  });
}

/* ======================= Báo cáo CLO theo lớp ======================= */

async function viewReport(v, classId) {
  const cls = courseClasses();
  classId = classId || cls[0].ClassID;
  const R = await gapi('cloReport', { classId });
  v.innerHTML = `<div class="row between" style="margin-bottom:12px"><div><h1 style="margin:0">Báo cáo mức đạt CLO</h1><div class="muted small">${esc(G.course.CourseName)} — tổng hợp các bài kiểm tra đã thu bài</div></div>
    <div class="row"><select id="rp-cls">${cls.map(c => `<option value="${c.ClassID}" ${c.ClassID === classId ? 'selected' : ''}>Lớp ${esc(c.ClassCode)}</option>`).join('')}</select><button class="btn" onclick="print()">🖨 In</button></div></div>
    ${R.exams.length ? `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>CLO</th>${R.exams.map(x => `<th class="num">${esc(x.title)}<div class="tiny muted" style="text-transform:none">${fmtDateTime(x.startedAt)} · ${x.n} bài</div></th>`).join('')}</tr></thead><tbody>
      ${R.clos.map(c => `<tr><td><b>${esc(c.Code)}</b><div class="tiny muted">${esc(c.Description)}</div><div class="tiny muted">Ngưỡng ${Math.round(c.PassThreshold)}% · mục tiêu ${Math.round(c.TargetRate)}%</div></td>
        ${R.exams.map(x => { const k = x.clos.find(y => y.cloId === c.CloID); return `<td class="num">${k ? `<b>${Math.round(k.achievedRate || 0)}%</b> SV đạt<br><span class="small muted">TB ${Math.round(k.avgPct || 0)}% · ${k.items} câu</span><br>${k.met ? '<span class="badge ok">ĐẠT</span>' : '<span class="badge bad">CHƯA ĐẠT</span>'}` : '<span class="muted">không đánh giá</span>'}</td>`; }).join('')}</tr>`).join('')}
    </tbody></table></div>` : '<div class="card empty">Lớp này chưa có bài kiểm tra nào đã thu bài.</div>'}`;
  $('#rp-cls').onchange = e => { location.hash = 'report/' + e.target.value; };
}

/* ======================= Hướng dẫn ======================= */

function viewGuide(v) {
  v.innerHTML = `<div class="card stack" style="max-width:860px">
    <h1>Quy trình một buổi kiểm tra</h1>
    <ol>
      <li><b>Chuẩn đầu ra</b>: khai báo CLO của học phần (tab Chuẩn đầu ra).</li>
      <li><b>Ngân hàng câu hỏi</b>: thêm từng câu (kèm ảnh mẫu đá/lát mỏng, giải thích đáp án) hoặc dán hàng loạt từ Excel. Gắn mỗi câu với CLO, mức Bloom, độ khó.</li>
      <li><b>Tạo đề</b>: chọn lớp, thời gian, thiết lập chống gian lận → chọn câu thủ công hoặc <b>rút ngẫu nhiên theo ma trận CLO × độ khó</b>. Kiểm tra bảng ma trận đề.</li>
      <li><b>Trên lớp</b>: vào <b>Giám sát</b> → <b>Mở phòng</b> → bật máy chiếu, bấm <b>Chiếu mã QR</b>. SV quét QR, nhập MSSV, đọc quy định, bấm <b>Sẵn sàng</b> trong 5 phút chuẩn bị. Hết giờ chuẩn bị bài tự bắt đầu (hoặc bấm <b>Bắt đầu ngay</b>).</li>
      <li><b>Trong giờ</b>: ô SV chuyển đỏ khi vi phạm, nhấp nháy khi <b>bị khoá</b>. Đến bàn SV (số máy hiển thị trên màn hình SV), xử lý rồi nhập <b>PIN giám thị</b> trên máy SV hoặc bấm Mở khoá trên bảng giám sát. Máy hết pin: bấm <b>Cho đổi máy</b> — SV vào lại bằng máy khác, bài đã làm còn nguyên.</li>
      <li><b>Thu bài</b> → <b>Kết quả</b>: phổ điểm, độ tin cậy, phân tích câu (p, D, r<sub>pb</sub>, phương án nhiễu), mức đạt CLO; xuất CSV; <b>Chuyển điểm</b> vào bảng điểm diemdanhsv; <b>Công bố đáp án</b> để SV xem giải thích.</li>
    </ol>
    <h2>Hệ thống phát hiện được gì?</h2>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Hành vi trên máy SV</th><th>Điện thoại</th><th>Laptop</th></tr></thead><tbody>
      <tr><td>Chuyển tab, mở ứng dụng khác, về màn hình chính, tắt màn hình</td><td>✔</td><td>✔</td></tr>
      <tr><td>Kéo thanh thông báo, cửa sổ nổi, trợ lý ảo đè lên (mất tiêu điểm &gt; ngưỡng)</td><td>✔ (phần lớn Android)</td><td>✔</td></tr>
      <tr><td>Chia đôi màn hình / thu nhỏ cửa sổ</td><td>✔ Android</td><td>✔</td></tr>
      <tr><td>Thoát toàn màn hình</td><td>✔ Android · ✖ iPhone (không có)</td><td>✔</td></tr>
      <tr><td>Sao chép / cắt / dán, chuột phải, Ctrl+P/S, F12 / DevTools</td><td>✔ (chặn + ghi)</td><td>✔ (chặn + ghi)</td></tr>
      <tr><td>Tải lại / đóng trang rồi mở lại</td><td>✔</td><td>✔</td></tr>
      <tr><td>Đăng nhập MSSV trên thiết bị thứ hai</td><td>✔ (chặn)</td><td>✔ (chặn)</td></tr>
      <tr><td>PrintScreen</td><td>—</td><td>✔ Windows (một phần)</td></tr>
      <tr><td><b>Điện thoại thứ hai, chụp màn hình bằng phím cứng, nhìn bài, trao đổi, AI trên máy khác</b></td><td colspan="2"><b>✖ Không phát hiện được — giám thị giám sát trực tiếp</b></td></tr>
    </tbody></table></div>
    <p class="small muted">Phát hiện ≠ ngăn chặn tuyệt đối: trình duyệt không có quyền điều khiển hệ điều hành. Hệ thống ghi nhận, khoá bài và buộc giám thị đến tận bàn — kết hợp hình mờ MSSV, xáo câu/phương án và đáp án không bao giờ gửi xuống máy SV.</p>
  </div>`;
}

boot();
