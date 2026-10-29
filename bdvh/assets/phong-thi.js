/* bdvh — phòng thi trực tiếp (học sinh): đăng nhập tài khoản bdvh, vào phòng, chuẩn bị, làm bài có giám sát, nộp.
   Chung mã giám sát với KIEMTRA (diemdanhsv.com/kiemtra). */
'use strict';

const S = {
  code: '',
  token: null,
  hadTokenOnLoad: false,
  state: null,          // trạng thái mới nhất từ máy chủ
  paper: null,          // {questions, answers}
  answers: {},
  flags: {},
  cur: 0,
  monitoring: false,
  queue: [],            // sự kiện chờ gửi
  pendingSave: {},      // câu trả lời chờ lưu
  saveTimer: null,
  pollTimer: null,
  tickTimer: null,
  blurTimer: null,
  blurAt: 0,
  hiddenAt: 0,
  baseline: {},         // diện tích cửa sổ chuẩn theo hướng màn hình
  smallSince: 0,
  splitReported: false,
  fsWanted: false,
  submitting: false,
};

const LS = {
  get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
  set(k, v) { try { localStorage.setItem(k, v); } catch (e) { } },
  del(k) { try { localStorage.removeItem(k); } catch (e) { } },
};

function deviceId() {
  let id = LS.get('pt_dev');
  if (!id) {
    const a = new Uint8Array(16);
    crypto.getRandomValues(a);
    id = Array.from(a, b => b.toString(16).padStart(2, '0')).join('');
    LS.set('pt_dev', id);
  }
  return id;
}
function deviceInfo() {
  const ua = navigator.userAgent;
  const os = /iPhone|iPad|iPod/.test(ua) ? 'iOS' : /Android/.test(ua) ? 'Android' : /Windows/.test(ua) ? 'Windows' : /Mac OS/.test(ua) ? 'macOS' : /Linux/.test(ua) ? 'Linux' : 'Khác';
  const br = /Edg\//.test(ua) ? 'Edge' : /OPR\//.test(ua) ? 'Opera' : /SamsungBrowser/.test(ua) ? 'Samsung' : /CriOS|Chrome\//.test(ua) ? 'Chrome' : /FxiOS|Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : 'Khác';
  const mob = /Mobi|Android|iPhone|iPad/.test(ua) ? 'ĐT' : 'Máy tính';
  return `${mob} · ${os} · ${br} · ${screen.width}×${screen.height}`;
}
const isIOS = /iPhone|iPod/.test(navigator.userAgent) || (/iPad|Macintosh/.test(navigator.userAgent) && 'ontouchend' in document && !document.documentElement.requestFullscreen);
function fsSupported() { const d = document.documentElement; return !!(d.requestFullscreen || d.webkitRequestFullscreen) && !isIOS; }
function inFs() { return !!(document.fullscreenElement || document.webkitFullscreenElement); }
async function enterFs() {
  const d = document.documentElement;
  try {
    if (d.requestFullscreen) await d.requestFullscreen({ navigationUI: 'hide' });
    else if (d.webkitRequestFullscreen) d.webkitRequestFullscreen();
    return true;
  } catch (e) { return false; }
}

function show(id) {
  ['s-code', 's-join', 's-lobby', 's-exam', 's-done'].forEach(s => $('#' + s).classList.toggle('hidden', s !== id));
  window.scrollTo(0, 0);
}

/* ======================= Khởi động ======================= */

async function boot() {
  const qs = new URLSearchParams(location.search);
  S.code = (qs.get('c') || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
  if (S.code.length !== 6) {
    show('s-code');
    $('#in-code').focus();
    $('#btn-code').onclick = () => {
      const c = $('#in-code').value.toUpperCase().replace(/[^A-Z0-9]/g, '');
      if (c.length !== 6) return toast('Mã phòng gồm 6 ký tự.', 'bad');
      location.search = '?c=' + c;
    };
    $('#in-code').onkeydown = e => { if (e.key === 'Enter') $('#btn-code').click(); };
    return;
  }
  S.token = LS.get('pt_t_' + S.code);
  S.hadTokenOnLoad = !!S.token;
  if (S.token) {
    try {
      const st = await api('room_state', { t: S.token });
      return route(st);
    } catch (e) {
      LS.del('pt_t_' + S.code);
      S.token = null;
      S.hadTokenOnLoad = false;
      if (!/hiệu lực|không hợp lệ/i.test(e.message)) toast(e.message, 'bad');
    }
  }
  showJoin();
}

async function showJoin() {
  show('s-join');
  let me = null;
  try {
    const p = await api('room_peek', { code: S.code });
    const e = p.room;
    me = p.me;
    document.title = e.title;
    $('#j-course').textContent = 'Phòng thi trực tiếp · BDVH';
    $('#j-title').textContent = e.title;
    $('#j-class').textContent = e.label || 'Mã ' + e.code;
    $('#j-q').textContent = e.questionCount + ' câu';
    $('#j-dur').textContent = e.durationMinutes + ' phút';
    const stt = { LOBBY: ['Đang chuẩn bị', 'ok'], RUNNING: ['Đang làm bài', 'warn'], CLOSED: ['Đã đóng', 'bad'] }[e.status] || [e.status, ''];
    $('#j-status').textContent = stt[0];
    $('#j-status').className = 'badge ' + stt[1];
    if (e.status === 'CLOSED') $('#btn-join').disabled = true;
  } catch (e) {
    $('#j-title').textContent = 'Không mở được phòng thi';
    $('#j-course').textContent = e.message;
    $('#btn-join').disabled = true;
    return;
  }
  const showMe = () => {
    $('#j-me').classList.toggle('hidden', !me);
    $('#j-login').classList.toggle('hidden', !!me);
    $('#btn-other').classList.toggle('hidden', !me);
    if (me) $('#j-me').innerHTML = `Em đang đăng nhập: <b>${esc(me.name)}</b>${me.phone ? ' · ' + esc(me.phone) : ''}`;
    else { $('#in-name').value = LS.get('pt_name') || ''; $('#in-phone').value = LS.get('pt_phone') || ''; }
  };
  showMe();
  $('#btn-other').onclick = async () => {
    try { await api('logout', {}, { raw: true }); } catch (e) { }
    me = null; showMe(); $('#in-name').focus();
  };
  $('#in-phone').onkeydown = e => { if (e.key === 'Enter') $('#btn-join').click(); };
  $('#btn-join').onclick = async () => {
    $('#btn-join').disabled = true;
    try {
      if (!me) {
        const name = $('#in-name').value.trim(), phone = $('#in-phone').value.replace(/\D/g, '');
        if (name.length < 2) throw new Error('Em nhập họ và tên.');
        if (!/^0\d{9,10}$/.test(phone)) throw new Error('Số điện thoại gồm 10–11 chữ số, bắt đầu bằng 0.');
        await api('guest_login', { full_name: name, phone }, { raw: true });
        LS.set('pt_name', name); LS.set('pt_phone', phone);
      }
      const r = await api('room_join', { code: S.code, deviceId: deviceId(), deviceInfo: deviceInfo() });
      S.token = r.token;
      LS.set('pt_t_' + S.code, r.token);
      route(await api('room_state', { t: S.token }));
    } catch (e) {
      toast(e.message, 'bad', 6000);
    } finally {
      $('#btn-join').disabled = false;
    }
  };
}

/** Điều hướng theo trạng thái máy chủ. */
function route(st) {
  S.state = st;
  const me = st.me, ex = st.exam;
  document.title = ex.title;
  if (me.status === 'SUBMITTED') return showDone(st);
  if (me.status === 'DOING' || (me.status === 'LOCKED' && ex.status === 'RUNNING')) return startExam(S.hadTokenOnLoad);
  showLobby(st);
}

/* ======================= Phòng chờ ======================= */

function showLobby(st) {
  show('s-lobby');
  renderLobby(st);
  const rules = st.exam.rules;
  $('#l-rules').innerHTML = [
    `Thời gian làm bài <b>${st.exam.durationMinutes} phút</b>, tính theo đồng hồ máy chủ. Hết giờ bài tự nộp.`,
    `Không rời khỏi trang: chuyển tab, mở ứng dụng khác (kể cả máy tính bỏ túi trên điện thoại), kéo thanh thông báo, tắt màn hình, chia đôi màn hình đều <b>bị ghi nhận</b>.`,
    `Không sao chép, dán, chụp màn hình. ${rules.requireFullscreen && fsSupported() ? 'Không thoát chế độ toàn màn hình.' : ''}`,
    `Quá <b>${rules.maxViolations} lần vi phạm</b>: ${rules.onLimit === 'submit' ? 'bài <b>tự động nộp</b>' : 'bài <b>bị khoá</b>, phải gọi thầy cô mở'}.`,
    `Chỉ làm trên <b>một thiết bị</b>. Đăng nhập tài khoản từ máy khác sẽ bị chặn và ghi vi phạm.`,
    rules.allowBack ? 'Được quay lại sửa câu trước khi nộp.' : '<b>Không</b> quay lại câu đã qua.',
    'Màn hình có hình mờ tên của em. Điện thoại thứ hai, tài liệu, trao đổi bài — thầy cô giám sát trực tiếp.',
  ].map(x => `<li>${x}</li>`).join('');

  // kiểm tra trước khi thi
  $('#ck-net').textContent = 'Ổn định'; $('#ck-net').className = 'st badge ok';
  if (navigator.getBattery) {
    navigator.getBattery().then(b => {
      const pct = Math.round(b.level * 100);
      $('#ck-bat').textContent = pct + '%' + (b.charging ? ' · đang sạc' : '');
      $('#ck-bat').className = 'st badge ' + (pct >= 30 || b.charging ? 'ok' : 'bad');
    }).catch(() => { $('#ck-bat').textContent = 'Tự kiểm tra'; });
  } else { $('#ck-bat').textContent = 'Tự kiểm tra'; }
  if (!fsSupported()) {
    $('#ck-fs').outerHTML = `<span class="st badge warn">${isIOS ? 'iPhone không hỗ trợ — giữ nguyên trang' : 'Không hỗ trợ'}</span>`;
  } else {
    $('#ck-fs').onclick = async () => {
      if (await enterFs()) { $('#ck-fs').textContent = 'Đạt ✓'; $('#ck-fs').className = 'st badge ok'; setTimeout(() => document.exitFullscreen && document.exitFullscreen().catch(() => { }), 900); }
      else toast('Trình duyệt chặn toàn màn hình — thử lại hoặc báo thầy cô.', 'bad');
    };
  }
  const upd = () => { $('#btn-ready').disabled = !($('#ck-rules').checked && $('#ck-dnd').checked); };
  $('#ck-rules').onchange = upd; $('#ck-dnd').onchange = upd;
  $('#btn-ready').onclick = async () => {
    $('#btn-ready').disabled = true;
    try { renderLobby(await api('room_ready', { t: S.token, checks: { fs: fsSupported(), dev: deviceInfo().slice(0, 40) } })); }
    catch (e) { toast(e.message, 'bad'); upd(); }
  };
  $('#btn-go').onclick = async () => {
    if (st.exam.rules.requireFullscreen && fsSupported()) await enterFs();
    startExam(false);
  };
  clearInterval(S.pollTimer);
  S.pollTimer = setInterval(pollLobby, 3000);
  clearInterval(S.tickTimer);
  S.tickTimer = setInterval(() => renderLobbyClock(), 500);
}

function renderLobby(st) {
  S.state = st;
  const me = st.me, ex = st.exam;
  $('#l-seat').innerHTML = (me.seat || '–') + '<small>Số máy</small>';
  $('#l-title').textContent = ex.title + ' · ' + ex.durationMinutes + ' phút';
  $('#l-name').textContent = me.name;
  $('#l-mssv').textContent = me.mssv;
  $('#l-count').textContent = `${st.readyCount}/${st.joinedCount} bạn đã sẵn sàng`;
  const ready = me.status !== 'JOINED';
  $('#l-check').classList.toggle('hidden', ready);
  $('#l-ready').classList.toggle('hidden', !ready || ex.status === 'RUNNING');
  $('#l-go').classList.toggle('hidden', ex.status !== 'RUNNING');
  renderLobbyClock();
}
function renderLobbyClock() {
  const ex = S.state.exam;
  if (ex.status === 'RUNNING') {
    $('#l-cd-label').textContent = 'Đã đến giờ làm bài';
    $('#l-cd').textContent = 'BẮT ĐẦU';
    return;
  }
  const left = parseDT(ex.startAt) - srvNow();
  $('#l-cd-label').textContent = 'Thời gian chuẩn bị còn';
  $('#l-cd').textContent = left > 0 ? fmtClock(left) : '00:00';
}
async function pollLobby() {
  try {
    const st = await api('room_state', { t: S.token });
    if (st.me.status === 'SUBMITTED') { clearInterval(S.pollTimer); return showDone(st); }
    if (st.exam.status === 'CLOSED') { clearInterval(S.pollTimer); return showDone(st); }
    renderLobby(st);
  } catch (e) { if (!e.network) toast(e.message, 'bad'); }
}

/* ======================= Làm bài ======================= */

async function startExam(reloaded) {
  clearInterval(S.pollTimer);
  clearInterval(S.tickTimer);
  let p;
  try {
    p = await api('room_paper', { t: S.token, reloaded: !!reloaded });
  } catch (e) {
    toast(e.message, 'bad', 6000);
    const st = await api('room_state', { t: S.token }).catch(() => null);
    if (st) { if (st.me.status === 'SUBMITTED') return showDone(st); showLobby(st); }
    return;
  }
  S.state = p;
  S.paper = p;
  S.answers = Object.assign({}, p.answers || {});
  // khôi phục câu trả lời chưa kịp gửi (mất mạng / tải lại)
  try {
    const pend = JSON.parse(LS.get('pt_pend_' + S.code) || '{}');
    Object.assign(S.answers, pend);
    Object.assign(S.pendingSave, pend);
  } catch (e) { }
  try { S.flags = JSON.parse(LS.get('pt_flag_' + S.code) || '{}'); } catch (e) { S.flags = {}; }
  S.cur = Math.min(+(LS.get('pt_cur_' + S.code) || 0), p.questions.length - 1);
  show('s-exam');
  $('#x-title').textContent = p.exam.title;
  const tail = String(p.me.mssv || '').slice(-4);
  $('#x-who').textContent = `${p.me.name} · Máy ${p.me.seat}`;
  if (p.exam.rules.watermark) drawWatermark(`${p.me.name} · ${tail}`);
  if (!p.exam.rules.allowBack) $('#x-prev').classList.add('hidden');
  bindExamUI();
  renderQ();
  renderStatus();
  startMonitoring();
  flushSaves();
  S.pollTimer = setInterval(heartbeat, 8000);
  S.tickTimer = setInterval(tick, 500);
  if (p.exam.rules.requireFullscreen && fsSupported() && !inFs()) {
    // tải lại trang thì mất toàn màn hình → yêu cầu bấm để vào lại (cần thao tác người dùng)
    $('#ov-fs h1').textContent = 'Bấm để vào chế độ toàn màn hình';
    $('#ov-fs p').textContent = 'Bài làm cần chạy ở chế độ toàn màn hình.';
    $('#ov-fs').classList.remove('hidden');
  }
}

function drawWatermark(text) {
  const wm = $('#x-wm');
  wm.innerHTML = '';
  for (let y = -1; y < 9; y++) for (let x = -1; x < 4; x++) {
    const d = document.createElement('div');
    d.textContent = text;
    d.style.left = (x * 360 + (y % 2) * 180) + 'px';
    d.style.top = (y * 130) + 'px';
    wm.appendChild(d);
  }
}

function bindExamUI() {
  $('#x-prev').onclick = () => go(S.cur - 1);
  $('#x-next').onclick = () => go(S.cur + 1);
  $('#x-flag').onclick = () => {
    const q = S.paper.questions[S.cur];
    S.flags[q.id] = !S.flags[q.id];
    LS.set('pt_flag_' + S.code, JSON.stringify(S.flags));
    renderQ();
  };
  $('#x-togglenav').onclick = () => { $('#x-nav').classList.toggle('hidden'); renderGrid(); };
  $('#x-submit').onclick = confirmSubmit;
  $('#lk-btn').onclick = async () => {
    const pin = $('#lk-pin').value.replace(/\D/g, '');
    if (!pin) return;
    try { const st = await api('room_pin', { t: S.token, pin }); $('#lk-pin').value = ''; S.state = st; renderStatus(); toast('Đã mở khoá. Tiếp tục làm bài.', 'ok'); }
    catch (e) { toast(e.message, 'bad'); }
  };
  $('#fs-back').onclick = async () => { await enterFs(); $('#ov-fs').classList.add('hidden'); setTimeout(captureBaseline, 600); };
}

function go(i) {
  const n = S.paper.questions.length;
  if (i < 0 || i >= n) return;
  if (!S.paper.exam.rules.allowBack && i < S.cur) return;
  S.cur = i;
  LS.set('pt_cur_' + S.code, String(i));
  renderQ();
  window.scrollTo(0, 0);
}

function answered(q) {
  const v = S.answers[q.id];
  if (v === undefined || v === null || v === '') return false;
  if (q.type === 'true_false') return typeof v === 'object' && Object.keys(v).length === q.items.length;
  return true;
}

function renderQ() {
  const qs = S.paper.questions, q = qs[S.cur];
  const labels = 'ABCDEFGH';
  const v = S.answers[q.id];
  let body = '';
  if (q.type === 'short') {
    body = `<label class="f">Điền đáp số (số, phân số hoặc biểu thức ngắn):</label><input type="text" id="x-short" autocomplete="off" autocorrect="off" spellcheck="false" placeholder="VD: 15 hoặc -3.5 hoặc 7/4" value="${esc(v ?? '')}" style="font-size:1.1rem">`;
  } else if (q.type === 'true_false') {
    const cur = v && typeof v === 'object' ? v : {};
    body = q.items.map((it, i) => `<div class="opt" style="cursor:default;flex-wrap:wrap;align-items:center">
        <span class="tx" style="flex:1 1 200px"><b>${'abcdef'[i]})</b> ${esc(it)}</span>
        <span class="row" style="gap:6px">
          <button class="btn sm ${cur[i] === 1 || cur[String(i)] === 1 ? 'primary' : ''}" data-tf="${i}" data-v="1">Đúng</button>
          <button class="btn sm ${cur[i] === 0 || cur[String(i)] === 0 ? 'rose' : ''}" data-tf="${i}" data-v="0">Sai</button>
        </span></div>`).join('');
  } else {
    body = q.options.map((o, i) => {
      const on = v !== undefined && v !== null && +v === o.id;
      return `<button class="opt ${on ? 'on' : ''}" data-opt="${o.id}" aria-pressed="${on}"><span class="k">${labels[i]}</span><span class="tx">${esc(o.text)}</span></button>`;
    }).join('');
  }
  $('#x-q').innerHTML = `<div class="q-card">
    <div class="row between"><span class="q-num">Câu ${S.cur + 1} / ${qs.length}${q.section ? ' · ' + esc(q.section) : ''}</span><span class="tiny muted">${S.flags[q.id] ? '<b style="color:#c47a00">⚑ xem lại</b>' : ''}</span></div>
    <div class="q-stem">${esc(q.stem)}</div>
    ${q.type === 'true_false' ? '<p class="small" style="color:var(--rose);font-weight:600;margin-top:-.4em">Chọn Đúng hoặc Sai cho từng ý.</p>' : ''}
    ${body}</div>`;
  if (q.type === 'short') {
    const inp = $('#x-short');
    inp.oninput = () => setAnswer(q.id, inp.value.trim(), 900);
  } else if (q.type === 'true_false') {
    $$('[data-tf]', $('#x-q')).forEach(b => b.onclick = () => {
      const cur = Object.assign({}, S.answers[q.id] && typeof S.answers[q.id] === 'object' ? S.answers[q.id] : {});
      cur[b.dataset.tf] = +b.dataset.v;
      setAnswer(q.id, cur, 300);
      renderQ();
    });
  } else {
    $$('.opt', $('#x-q')).forEach(b => b.onclick = () => {
      const id = +b.dataset.opt;
      setAnswer(q.id, S.answers[q.id] !== undefined && +S.answers[q.id] === id ? null : id, 200);
      renderQ();
    });
  }
  $('#x-prev').disabled = S.cur === 0;
  $('#x-next').disabled = S.cur === qs.length - 1;
  $('#x-flag').textContent = S.flags[q.id] ? '⚑ Bỏ dấu' : '⚑ Đánh dấu';
  const done = qs.filter(answered).length;
  $('#x-done').textContent = `(${done}/${qs.length})`;
  $('#x-prog').style.width = (done / qs.length * 100) + '%';
  if (!$('#x-nav').classList.contains('hidden')) renderGrid();
  typeset($('#x-q'));
}

function renderGrid() {
  const qs = S.paper.questions;
  $('#x-grid').innerHTML = qs.map((q, i) =>
    `<button class="${answered(q) ? 'ans' : ''} ${i === S.cur ? 'cur' : ''} ${S.flags[q.id] ? 'flag' : ''}" data-i="${i}" ${!S.paper.exam.rules.allowBack && i < S.cur ? 'disabled' : ''}>${i + 1}</button>`).join('');
  $$('#x-grid button').forEach(b => b.onclick = () => { go(+b.dataset.i); $('#x-nav').classList.add('hidden'); });
}

function setAnswer(qid, val, delay) {
  if (val === '' || (Array.isArray(val) && !val.length)) val = null;
  if (val === null) delete S.answers[qid]; else S.answers[qid] = val;
  S.pendingSave[qid] = val;
  LS.set('pt_pend_' + S.code, JSON.stringify(S.pendingSave));
  $('#x-saving').textContent = 'Đang lưu…';
  clearTimeout(S.saveTimer);
  S.saveTimer = setTimeout(flushSaves, delay);
}

async function flushSaves() {
  const batch = Object.assign({}, S.pendingSave);
  if (!Object.keys(batch).length) return;
  try {
    await api('room_save_answers', { t: S.token, answers: batch });
    for (const k of Object.keys(batch)) if (S.pendingSave[k] === batch[k]) delete S.pendingSave[k];
    LS.set('pt_pend_' + S.code, JSON.stringify(S.pendingSave));
    $('#x-saving').textContent = '✓ Đã lưu ' + new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  } catch (e) {
    $('#x-saving').textContent = e.network ? '⚠ Chưa lưu được (mất mạng) — sẽ tự gửi lại' : '⚠ ' + e.message;
    if (!e.network && /khoá|giờ/.test(e.message)) heartbeat();
    else { clearTimeout(S.saveTimer); S.saveTimer = setTimeout(flushSaves, 4000); }
  }
}

function renderStatus() {
  const st = S.state, me = st.me;
  $('#x-vio').textContent = `⚠ ${me.violations}/${me.limit}`;
  $('#x-vio').classList.toggle('has', me.violations > 0);
  if (me.status === 'LOCKED') {
    $('#lk-seat').innerHTML = me.seat + '<small>Số máy</small>';
    $('#lk-reason').textContent = 'Lý do: ' + (me.lockReason || 'vượt số lần vi phạm cho phép') + '.';
    $('#ov-lock').classList.remove('hidden');
  } else {
    $('#ov-lock').classList.add('hidden');
  }
  if (me.status === 'SUBMITTED') finish(st);
}

function tick() {
  const dl = parseDT(S.state.me.deadline);
  if (!dl) return;
  const left = dl - srvNow();
  const t = $('#x-timer');
  t.textContent = fmtClock(left);
  t.className = 'timer' + (left < 60000 ? ' bad' : left < 5 * 60000 ? ' warn' : '');
  if (left <= 0 && !S.submitting && S.state.me.status !== 'SUBMITTED') doSubmit(true);
}

async function heartbeat() {
  const events = S.queue.splice(0, S.queue.length);
  try {
    const st = await api('room_state', { t: S.token, events }, { keepalive: true });
    S.state = Object.assign(S.state, st);
    renderStatus();
    if (Object.keys(S.pendingSave).length) flushSaves();
  } catch (e) {
    S.queue.unshift(...events);
    if (!e.network && /hiệu lực|không hợp lệ/i.test(e.message)) {
      stopMonitoring();
      clearInterval(S.pollTimer); clearInterval(S.tickTimer);
      document.body.innerHTML = `<div class="narrow"><div class="card" style="margin-top:10vh"><h1>Phiên làm bài đã chuyển sang thiết bị khác</h1><p>${esc(e.message)}</p></div></div>`;
    }
  }
}

/* ---------------- Giám sát thiết bị ---------------- */

function report(type, detail) {
  if (!S.monitoring) return;
  S.queue.push({ type, detail: detail || '', at: new Date().toISOString() });
  const counted = ['hidden', 'blur', 'fs_exit', 'split', 'copy', 'cut', 'paste', 'devtools', 'printscreen'].includes(type);
  if (counted) {
    flashWarn(type);
    heartbeat(); // gửi ngay
  }
}
const WARN_TEXT = {
  hidden: 'Em vừa rời khỏi trang làm bài — đã ghi nhận vi phạm.',
  blur: 'Cửa sổ làm bài mất tiêu điểm — đã ghi nhận vi phạm.',
  fs_exit: 'Thoát toàn màn hình — đã ghi nhận vi phạm.',
  split: 'Phát hiện chia đôi màn hình / thu nhỏ cửa sổ — đã ghi nhận vi phạm.',
  copy: 'Không được sao chép — đã ghi nhận vi phạm.', cut: 'Không được cắt nội dung — đã ghi nhận vi phạm.',
  paste: 'Không được dán nội dung — đã ghi nhận vi phạm.', devtools: 'Không được mở công cụ nhà phát triển — đã ghi nhận vi phạm.',
  printscreen: 'Không được chụp màn hình — đã ghi nhận vi phạm.',
};
function flashWarn(type) {
  const w = $('#ov-warn');
  w.textContent = '⚠ ' + (WARN_TEXT[type] || 'Đã ghi nhận vi phạm.');
  w.classList.remove('hidden');
  clearTimeout(w._t);
  w._t = setTimeout(() => w.classList.add('hidden'), 5000);
  if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
}

const H = {
  vis() {
    if (document.visibilityState === 'hidden') {
      clearTimeout(S.blurTimer); S.blurTimer = null;
      S.hiddenAt = Date.now();
      report('hidden', 'Rời trang (chuyển tab / ứng dụng khác / tắt màn hình)');
    } else if (S.hiddenAt) {
      report('back_visible', 'Quay lại sau ' + Math.round((Date.now() - S.hiddenAt) / 1000) + ' giây');
      S.hiddenAt = 0;
    }
  },
  blur() {
    S.blurAt = Date.now();
    clearTimeout(S.blurTimer);
    const grace = (S.state.exam.rules.blurGraceSec || 0) * 1000;
    S.blurTimer = setTimeout(() => {
      S.blurTimer = null;
      if (document.visibilityState === 'visible' && !document.hasFocus()) report('blur', 'Mất tiêu điểm hơn ' + Math.round(grace / 1000) + ' giây (cửa sổ khác / cửa sổ nổi / thanh thông báo)');
    }, Math.max(grace, 300));
  },
  focus() {
    if (S.blurTimer) { clearTimeout(S.blurTimer); S.blurTimer = null; }
    else if (S.blurAt && Date.now() - S.blurAt > 1000) report('focus_back', 'Trở lại sau ' + Math.round((Date.now() - S.blurAt) / 1000) + ' giây');
    S.blurAt = 0;
  },
  fs() {
    if (!S.fsWanted) return;
    if (!inFs()) {
      report('fs_exit', 'Thoát chế độ toàn màn hình');
      $('#ov-fs h1').textContent = 'Em đã thoát chế độ toàn màn hình';
      $('#ov-fs p').textContent = 'Việc này đã được ghi nhận là một lần vi phạm. Bấm nút dưới đây để tiếp tục làm bài.';
      $('#ov-fs').classList.remove('hidden');
    } else {
      $('#ov-fs').classList.add('hidden');
    }
  },
  resize() {
    const typing = document.activeElement && /INPUT|TEXTAREA/.test(document.activeElement.tagName);
    const key = innerWidth > innerHeight ? 'land' : 'port';
    const area = innerWidth * innerHeight;
    const base = S.baseline[key];
    if (!base || area > base) { S.baseline[key] = area; S.smallSince = 0; return; }
    if (!typing && area < base * 0.65) {
      if (!S.smallSince) S.smallSince = Date.now();
      setTimeout(() => {
        if (S.smallSince && Date.now() - S.smallSince >= 1400 && !S.splitReported && innerWidth * innerHeight < (S.baseline[innerWidth > innerHeight ? 'land' : 'port'] || 0) * 0.65) {
          S.splitReported = true;
          report('split', `Cửa sổ thu nhỏ còn ${innerWidth}×${innerHeight}`);
        }
      }, 1500);
    } else if (area >= base * 0.8) {
      S.smallSince = 0;
      S.splitReported = false;
    }
  },
  copy(e) { e.preventDefault(); report('copy', 'Sao chép'); },
  cut(e) { e.preventDefault(); report('cut', 'Cắt'); },
  paste(e) { e.preventDefault(); report('paste', 'Dán'); },
  ctx(e) { e.preventDefault(); report('contextmenu', 'Chuột phải / nhấn giữ'); },
  drag(e) { e.preventDefault(); },
  key(e) {
    const k = (e.key || '').toLowerCase(), mod = e.ctrlKey || e.metaKey;
    if (k === 'f12' || (mod && e.shiftKey && ['i', 'j', 'c'].includes(k)) || (mod && e.altKey && ['i', 'j'].includes(k)) || (mod && k === 'u')) {
      e.preventDefault(); return report('devtools', 'Phím ' + [mod ? 'Ctrl' : '', e.shiftKey ? 'Shift' : '', e.key].filter(Boolean).join('+'));
    }
    if (mod && k === 'c') { e.preventDefault(); return report('copy', 'Ctrl+C'); }
    if (mod && k === 'x') { e.preventDefault(); return report('cut', 'Ctrl+X'); }
    if (mod && k === 'v') { e.preventDefault(); return report('paste', 'Ctrl+V'); }
    if (mod && ['p', 's', 'a', 'f', 'g'].includes(k)) { e.preventDefault(); return report('shortcut', 'Ctrl+' + e.key); }
    if (k === 'printscreen') { report('printscreen', 'Phím PrintScreen'); }
    if (e.altKey && k === 'tab') report('shortcut', 'Alt+Tab');
  },
  keyup(e) { if ((e.key || '').toLowerCase() === 'printscreen') { try { navigator.clipboard && navigator.clipboard.writeText(''); } catch (x) { } } },
  unload(e) { if (S.monitoring && !S.submitting) { e.preventDefault(); e.returnValue = 'Rời trang sẽ bị ghi nhận vi phạm.'; return e.returnValue; } },
  pop() { if (S.monitoring) { history.pushState(null, '', location.href); toast('Không thể quay lại trong giờ làm bài.'); } },
  offline() { report('offline', 'Mất mạng'); $('#x-saving').textContent = '⚠ Mất mạng — bài vẫn được giữ trên máy'; },
  online() { report('online', 'Có mạng lại'); flushSaves(); heartbeat(); },
};

function captureBaseline() { S.baseline[innerWidth > innerHeight ? 'land' : 'port'] = innerWidth * innerHeight; }

function startMonitoring() {
  if (S.monitoring) return;
  S.monitoring = true;
  S.fsWanted = S.state.exam.rules.requireFullscreen && fsSupported();
  captureBaseline();
  document.addEventListener('visibilitychange', H.vis);
  window.addEventListener('blur', H.blur);
  window.addEventListener('focus', H.focus);
  document.addEventListener('fullscreenchange', H.fs);
  document.addEventListener('webkitfullscreenchange', H.fs);
  window.addEventListener('resize', H.resize);
  document.addEventListener('copy', H.copy);
  document.addEventListener('cut', H.cut);
  document.addEventListener('paste', H.paste);
  document.addEventListener('contextmenu', H.ctx);
  document.addEventListener('dragstart', H.drag);
  document.addEventListener('keydown', H.key, true);
  document.addEventListener('keyup', H.keyup, true);
  window.addEventListener('beforeunload', H.unload);
  window.addEventListener('popstate', H.pop);
  window.addEventListener('offline', H.offline);
  window.addEventListener('online', H.online);
  history.pushState(null, '', location.href);
  // ngay lúc bắt đầu mà trang đang bị che (vd mở trang ở nền) thì ghi nhận luôn
  if (document.visibilityState === 'hidden') H.vis();
}
function stopMonitoring() {
  S.monitoring = false;
  document.removeEventListener('visibilitychange', H.vis);
  window.removeEventListener('blur', H.blur);
  window.removeEventListener('focus', H.focus);
  document.removeEventListener('fullscreenchange', H.fs);
  document.removeEventListener('webkitfullscreenchange', H.fs);
  window.removeEventListener('resize', H.resize);
  document.removeEventListener('copy', H.copy);
  document.removeEventListener('cut', H.cut);
  document.removeEventListener('paste', H.paste);
  document.removeEventListener('contextmenu', H.ctx);
  document.removeEventListener('dragstart', H.drag);
  document.removeEventListener('keydown', H.key, true);
  document.removeEventListener('keyup', H.keyup, true);
  window.removeEventListener('beforeunload', H.unload);
  window.removeEventListener('popstate', H.pop);
  window.removeEventListener('offline', H.offline);
  window.removeEventListener('online', H.online);
}

/* ---------------- Nộp bài ---------------- */

async function confirmSubmit() {
  const qs = S.paper.questions;
  const blank = qs.filter(q => !answered(q)).length;
  const flagged = qs.filter(q => S.flags[q.id]).length;
  // tạm ngừng giám sát blur: hộp thoại không phải cửa sổ hệ thống nên không làm mất tiêu điểm
  const ok = await modal({
    title: 'Nộp bài?',
    html: `<p>Em đã trả lời <b>${qs.length - blank}/${qs.length}</b> câu.</p>
      ${blank ? `<div class="callout warn">Còn <b>${blank}</b> câu chưa trả lời.</div>` : ''}
      ${flagged ? `<div class="callout" style="margin-top:8px">Có <b>${flagged}</b> câu đánh dấu xem lại.</div>` : ''}
      <p class="small muted">Sau khi nộp không sửa được nữa.</p>`,
    okText: 'Nộp bài', cancelText: 'Làm tiếp', danger: true,
  });
  if (ok) doSubmit(false);
}

async function doSubmit(auto) {
  if (S.submitting) return;
  S.submitting = true;
  clearTimeout(S.saveTimer);
  try { await flushSaves(); } catch (e) { }
  try {
    const st = await api('room_submit', { t: S.token });
    finish(st);
  } catch (e) {
    // hết giờ: máy chủ tự chấm phần đã lưu; hỏi lại trạng thái
    try { const st = await api('room_state', { t: S.token }); if (st.me.status === 'SUBMITTED') return finish(st); } catch (x) { }
    S.submitting = false;
    toast(auto ? 'Hết giờ — đang nộp bài, giữ nguyên trang…' : e.message, 'bad', 5000);
    if (auto) setTimeout(() => doSubmit(true), 3000);
  }
}

function finish(st) {
  stopMonitoring();
  S.submitting = true;
  clearInterval(S.pollTimer); clearInterval(S.tickTimer);
  ['pt_pend_', 'pt_flag_', 'pt_cur_'].forEach(k => LS.del(k + S.code));
  if (document.exitFullscreen && inFs()) document.exitFullscreen().catch(() => { });
  $('#ov-lock').classList.add('hidden'); $('#ov-fs').classList.add('hidden'); $('#ov-warn').classList.add('hidden');
  showDone(st);
}

/* ======================= Đã nộp / xem lại ======================= */

function showDone(st) {
  show('s-done');
  clearInterval(S.pollTimer);
  const r = st.result || {};
  const why = { hs_nop: 'Em đã nộp bài', het_gio: 'Hết giờ — hệ thống tự nộp', gv_thu: 'Thầy cô thu bài', vi_pham: 'Tự động nộp do vượt số lần vi phạm' }[r.reason] || '';
  $('#d-info').textContent = [why, r.submittedAt ? 'lúc ' + fmtTime(r.submittedAt) : '', st.me ? '· ' + st.me.name : ''].join(' ');
  $('#d-score').innerHTML = r.score10 !== undefined
    ? `<div class="result-score">${fmtNum(r.score10, 2)}</div><div class="muted">điểm (thang 10) · đúng ${r.raw}/${r.max} câu</div>` : '<p class="muted">Điểm sẽ được thầy cô công bố sau.</p>';
  const btn = $('#btn-review');
  btn.classList.toggle('hidden', !r.subId);
  if (r.subId) btn.href = 'ket-qua.html?sub_id=' + r.subId;
  if (!r.subId && st.exam && !(st.exam.resultsPublished && st.exam.status === 'CLOSED')) {
    S.pollTimer = setInterval(async () => {
      try { const s2 = await api('room_state', { t: S.token }); if (s2.result && s2.result.subId) { clearInterval(S.pollTimer); showDone(s2); } } catch (e) { }
    }, 20000);
  }
}

boot();
