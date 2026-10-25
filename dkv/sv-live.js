/* sv-live.js — Trang tương tác của sinh viên (dkv/sv.html).
 *
 * Có mã phòng (sv.html?phong=MÃ hoặc nhập tay) + MSSV + họ tên → câu trả lời gửi lên máy chủ
 * (api/index.php: roomInfo, roomSubmit) và tới đúng màn hình trình chiếu của lớp mình.
 * Chạy bằng file:// (không có máy chủ) → dự phòng BroadcastChannel 'dkv_live_session' như cũ
 * (chỉ tới tab trình chiếu trên cùng trình duyệt). Docs: docs/04-API-PHP.md mục 36.
 */
var currentIdx = 0;
var lessons = window.SLIDES_DATA || [];
var ONLINE = /^https?:$/.test(location.protocol);
var API = ONLINE ? new URL('../api/index.php', location.href).href : null;
var ME_KEY = 'dkv_sv_me';
var room = null;            // {code, lessonCode, label, linked}
var me = { mssv: '', name: '' };
var infoTimer = null;

function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}
function $(id) { return document.getElementById(id); }
function lsGet(k, d) { try { var v = localStorage.getItem(k); return v ? JSON.parse(v) : d; } catch (e) { return d; } }
function lsSet(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
function nowHm() { return new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }); }

var toastTimer = null;
function toast(msg, bad) {
  var t = $('svToast');
  t.textContent = msg;
  t.className = 'on' + (bad ? ' bad' : '');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(function () { t.className = ''; }, bad ? 4500 : 2600);
}

function api(method, params) {
  var opts = { method: method, cache: 'no-store' };
  var url = API;
  if (method === 'GET') url += '?' + new URLSearchParams(params).toString();
  else { opts.headers = { 'Content-Type': 'text/plain;charset=utf-8' }; opts.body = JSON.stringify(params); }
  return fetch(url, opts).then(function (r) { return r.json(); }).then(function (j) {
    if (!j || j.status !== 'success') throw new Error((j && j.message) || 'Lỗi máy chủ.');
    return j.data;
  }).catch(function (e) {
    if (/Failed to fetch|NetworkError|Load failed/i.test(e.message)) throw new Error('Không kết nối được máy chủ. Kiểm tra Wi-Fi/4G rồi thử lại.');
    throw e;
  });
}

// ---------------- Khởi động ----------------
function init() {
  var sel = $('svLessonSelect');
  sel.innerHTML = lessons.map(function (l, i) {
    return '<option value="' + i + '">Mục ' + esc(l.code) + ' - ' + esc(l.title) + '</option>';
  }).join('');

  var params = new URLSearchParams(location.search);
  var code = params.get('lesson');
  if (code) setLessonByCode(code);

  me = lsGet(ME_KEY, { mssv: '', name: '' }) || { mssv: '', name: '' };
  $('jMssv').value = me.mssv || '';
  $('jName').value = me.name || '';

  if (!ONLINE) {
    // file:// — không có máy chủ: ẩn thẻ vào phòng, dùng BroadcastChannel như cũ.
    $('joinCard').innerHTML = '<p class="note" style="margin:0">Đang mở ngoại tuyến: câu trả lời chỉ tới trang trình chiếu mở trên cùng máy này.</p>';
  } else {
    var phong = (params.get('phong') || params.get('room') || (lsGet('dkv_sv_room', null) || {}).code || '').toUpperCase().replace(/\s+/g, '');
    $('jCode').value = phong;
    $('jCode').addEventListener('input', function () { this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, ''); });
    if (phong && me.mssv && me.name) joinRoom(true);
    else if (phong) setTimeout(function () { (me.mssv ? $('jName') : $('jMssv')).focus(); }, 300);
  }
  renderCurrentLesson();
  loadSavedSubmissions();
}

function setLessonByCode(code) {
  var f = lessons.findIndex(function (l) { return l.code === code; });
  if (f >= 0) { currentIdx = f; $('svLessonSelect').value = f; return true; }
  return false;
}

function switchLesson(val) {
  currentIdx = parseInt(val, 10);
  renderCurrentLesson();
}

// ---------------- Phòng tương tác ----------------
function joinRoom(silent) {
  var code = $('jCode').value.trim().toUpperCase();
  var mssv = $('jMssv').value.trim().toUpperCase().replace(/\s+/g, '');
  var name = $('jName').value.trim().replace(/\s+/g, ' ');
  var err = $('jErr');
  err.textContent = '';
  if (!/^[A-Z0-9]{5}$/.test(code)) { err.textContent = 'Mã phòng gồm 5 ký tự, xem trên màn hình lớp.'; return; }
  if (!/^[0-9A-Z]{5,15}$/.test(mssv)) { err.textContent = 'Vui lòng nhập đúng MSSV.'; return; }
  if (name.length < 2) { err.textContent = 'Vui lòng nhập họ tên.'; return; }
  var btn = $('jBtn');
  btn.disabled = true; btn.textContent = 'Đang vào phòng…';
  api('GET', { action: 'roomInfo', code: code }).then(function (d) {
    me = { mssv: mssv, name: name };
    lsSet(ME_KEY, me);
    lsSet('dkv_sv_room', { code: d.code });
    enterRoom(d);
    if (!silent) toast('Đã vào phòng ' + d.code);
  }).catch(function (e) {
    err.textContent = e.message;
    if (silent) lsSet('dkv_sv_room', null);
  }).then(function () {
    btn.disabled = false; btn.textContent = 'Vào phòng';
  });
}

function enterRoom(d) {
  room = d;
  $('joinForm').style.display = 'none';
  $('joinOn').style.display = '';
  $('jOnCode').textContent = d.code;
  $('jOnWho').innerHTML = (d.label ? '<b>' + esc(d.label) + '</b><br>' : '') + esc(me.mssv) + ' · ' + esc(me.name);
  // Bài giảng theo phòng: giảng viên đổi bài thì trang tự đổi theo.
  var sel = $('svLessonSelect');
  sel.disabled = true;
  sel.title = 'Bài giảng do giảng viên chọn trên màn hình lớp';
  if (setLessonByCode(d.lessonCode)) renderCurrentLesson();
  clearInterval(infoTimer);
  infoTimer = setInterval(checkRoom, 15000);
  var u = new URL(location.href);
  if (u.searchParams.get('phong') !== d.code) { u.searchParams.set('phong', d.code); history.replaceState(null, '', u.href); }
}

function leaveRoom(msg) {
  room = null;
  clearInterval(infoTimer);
  lsSet('dkv_sv_room', null);
  $('joinForm').style.display = '';
  $('joinOn').style.display = 'none';
  $('svLessonSelect').disabled = false;
  if (msg) $('jErr').textContent = msg;
}

function checkRoom() {
  if (!room || document.hidden) return;
  api('GET', { action: 'roomInfo', code: room.code }).then(function (d) {
    if (!room) return;
    var changed = d.lessonCode !== room.lessonCode;
    room = d;
    if (changed && setLessonByCode(d.lessonCode)) {
      renderCurrentLesson();
      toast('Giảng viên đã chuyển sang Mục ' + d.lessonCode);
    }
  }).catch(function (e) {
    if (/đã đóng|không đúng/i.test(e.message)) {
      leaveRoom('Phòng ' + room.code + ' đã đóng. Nhập mã phòng mới nếu giảng viên mở phòng khác.');
      toast('Phòng đã đóng', true);
    }
  });
}
document.addEventListener('visibilitychange', function () { if (!document.hidden) checkRoom(); });

/** Gửi một câu trả lời. Trả Promise<{duplicate?, message?}>. */
function sendLive(kind, payload) {
  var lessonCode = lessons[currentIdx] ? lessons[currentIdx].code : '';
  // Dự phòng / cùng máy: phát BroadcastChannel (trang trình chiếu bỏ qua khi đã có phòng máy chủ).
  try {
    var ch = new BroadcastChannel('dkv_live_session');
    ch.postMessage({ type: kind, lesson: lessonCode, data: Object.assign({ mssv: me.mssv, name: me.name }, payload) });
    ch.close();
  } catch (e) {}
  if (!ONLINE) return Promise.resolve({ offline: true });
  if (!room) {
    $('jErr').textContent = 'Hãy vào phòng trước: nhập mã phòng đang chiếu trên màn hình.';
    window.scrollTo({ top: 0, behavior: 'smooth' });
    (($('jCode').value ? ($('jMssv').value ? $('jName') : $('jMssv')) : $('jCode'))).focus();
    return Promise.reject(new Error('Bạn chưa vào phòng tương tác.'));
  }
  return api('POST', Object.assign({ action: 'roomSubmit', code: room.code, kind: kind, lessonCode: lessonCode, mssv: me.mssv, name: me.name }, payload))
    .catch(function (e) {
      if (/đã đóng|không đúng/i.test(e.message)) leaveRoom(e.message);
      throw e;
    });
}

// ---------------- Nội dung bài ----------------
function answersKey() {
  var l = lessons[currentIdx];
  return 'dkv_sv_ans_' + (room ? room.code : 'local') + '_' + (l ? l.code : '');
}

function renderCurrentLesson() {
  var cur = lessons[currentIdx];
  if (!cur) return;
  $('quizLessonTitle').innerText = 'Mục ' + cur.code + ' · ' + cur.title;

  var allQs = [];
  cur.slides.forEach(function (s) { if (s.type === 'quiz') allQs = allQs.concat(s.qs || []); });
  var quizContainer = $('quizList');
  if (!allQs.length) {
    quizContainer.innerHTML = '<p style="color:var(--mute)">Bài giảng này không có câu hỏi trắc nghiệm nhanh.</p>';
  } else {
    var done = lsGet(answersKey(), {}) || {};
    quizContainer.innerHTML = allQs.map(function (q, qIdx) {
      var picked = done[qIdx];
      var optsHtml = q.o.map(function (opt, oIdx) {
        var letter = 'ABCDEF'[oIdx];
        var cls = '';
        if (picked) cls = letter === q.a ? ' correct' : (letter === picked ? ' wrong' : '');
        return '<button type="button" class="opt-btn' + cls + '" data-q="' + qIdx + '" data-c="' + letter + '"' + (picked ? ' disabled' : '') + ' onclick="checkSvAnswer(this)">' +
          '<span class="num-b">' + letter + '</span><span>' + esc(opt) + '</span></button>';
      }).join('');
      return '<div class="q-card"><div class="q-title">Câu ' + (qIdx + 1) + '. ' + esc(q.q) + '</div>' +
        '<div class="opts">' + optsHtml + '</div>' +
        '<div class="q-msg" id="qmsg' + qIdx + '">' + (picked ? 'Bạn đã chọn ' + picked + '.' : '') + '</div></div>';
    }).join('');
  }

  var actSlides = cur.slides.filter(function (s) { return s.type === 'act'; });
  var actBox = $('actContent');
  if (actSlides.length > 0) {
    var a = actSlides[0];
    actBox.innerHTML = '<h3 style="color:var(--luc);margin:0 0 6px">' + esc(a.title || 'Hoạt động thảo luận') + ' (' + esc(a.time || '10 phút') + ')</h3>' +
      (a.sub ? '<p style="margin:0 0 8px;font-weight:600">' + esc(a.sub) + '</p>' : '') +
      '<ul style="margin:0;padding-left:20px;font-size:15px">' +
        (a.bullets || []).map(function (b) { return '<li>' + esc(b) + '</li>'; }).join('') +
      '</ul>';
  } else {
    actBox.innerHTML = '<p style="color:var(--mute);margin:0">Bài này tập trung vào bài tập cá nhân và thực hành mẫu vật.</p>';
  }
}

function checkSvAnswer(btn) {
  var qIdx = parseInt(btn.getAttribute('data-q'), 10);
  var choice = btn.getAttribute('data-c');
  var cur = lessons[currentIdx];
  var allQs = [];
  cur.slides.forEach(function (s) { if (s.type === 'quiz') allQs = allQs.concat(s.qs || []); });
  var q = allQs[qIdx];
  var parent = btn.closest('.opts');
  var msg = $('qmsg' + qIdx);
  var buttons = parent.querySelectorAll('.opt-btn');
  buttons.forEach(function (b) { b.disabled = true; });
  btn.classList.add('chosen');
  msg.textContent = 'Đang gửi…';

  var key = answersKey();
  sendLive('quiz', { qIdx: qIdx, choice: choice }).then(function (r) {
    var kept = choice;
    if (r && r.duplicate) {
      var done0 = lsGet(key, {}) || {};
      kept = done0[qIdx] || choice;
    }
    buttons.forEach(function (b) {
      var c = b.getAttribute('data-c');
      b.classList.remove('chosen');
      if (c === q.a) b.classList.add('correct');
      else if (c === kept) b.classList.add('wrong');
    });
    var done = lsGet(key, {}) || {};
    if (!done[qIdx]) done[qIdx] = kept;
    lsSet(key, done);
    msg.textContent = r && r.duplicate ? 'Bạn đã trả lời câu này trước đó — đáp án đầu tiên được giữ.'
      : (kept === q.a ? 'Chính xác! Đã gửi đáp án ' + kept + '.' : 'Đã gửi đáp án ' + kept + '. Đáp án đúng là ' + q.a + '.');
  }).catch(function (e) {
    buttons.forEach(function (b) { b.disabled = false; b.classList.remove('chosen'); });
    msg.textContent = '';
    toast(e.message, true);
  });
}

function openTab(tabId, btn) {
  document.querySelectorAll('.panel').forEach(function (p) { p.classList.remove('on'); });
  document.querySelectorAll('nav.tabs button').forEach(function (b) { b.classList.remove('on'); });
  $(tabId).classList.add('on');
  btn.classList.add('on');
}

function withBusy(btnId, promise) {
  var b = $(btnId);
  var txt = b.textContent;
  b.disabled = true; b.textContent = 'Đang gửi…';
  return promise.then(function (r) { b.disabled = false; b.textContent = txt; return r; },
    function (e) { b.disabled = false; b.textContent = txt; throw e; });
}

function submitGroupWork() {
  var grp = $('grpName').value.trim() || 'Một nhóm sinh viên';
  var ans = $('grpAnswer').value.trim();
  if (!ans) { toast('Vui lòng nhập ý kiến thảo luận của nhóm.', true); return; }
  withBusy('grpBtn', sendLive('group', { group: grp, answer: ans, text: ans })).then(function () {
    saveAndRender('groupWorks', { group: grp, answer: ans, time: nowHm() }, 'groupFeedback');
    $('grpAnswer').value = '';
    toast('Đã gửi kết quả của nhóm lên màn hình lớp.');
  }).catch(function (e) { toast(e.message, true); });
}

function submitQuestion() {
  var q = $('qaText').value.trim();
  if (!q) { toast('Vui lòng nhập câu hỏi.', true); return; }
  withBusy('qaBtn', sendLive('qa', { q: q, text: q })).then(function () {
    saveAndRender('qaListLocal', { q: q, time: nowHm() }, 'qaList');
    $('qaText').value = '';
    toast('Đã gửi câu hỏi tới giảng viên.');
  }).catch(function (e) { toast(e.message, true); });
}

function saveAndRender(storageKey, item, targetId) {
  var list = lsGet(storageKey, []) || [];
  list.unshift(item);
  lsSet(storageKey, list.slice(0, 20));
  renderItems(list, targetId);
}

function loadSavedSubmissions() {
  renderItems(lsGet('groupWorks', []) || [], 'groupFeedback');
  renderItems(lsGet('qaListLocal', []) || [], 'qaList');
}

function renderItems(list, targetId) {
  var el = $(targetId);
  if (!el) return;
  el.innerHTML = (list.length ? '<p class="note" style="margin-top:4px">Bạn đã gửi:</p>' : '') + list.slice(0, 5).map(function (it) {
    if (it.group) return '<div class="feed-item"><b>' + esc(it.group) + ' (' + esc(it.time) + '):</b> ' + esc(it.answer) + '</div>';
    return '<div class="feed-item"><b>' + esc(it.time) + ':</b> ' + esc(it.q) + '</div>';
  }).join('');
}

document.addEventListener('DOMContentLoaded', init);
