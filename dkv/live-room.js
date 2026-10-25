/* live-room.js — Phòng tương tác trên trang trình chiếu (dkv/trinhchieu.html).
 *
 * Giảng viên bấm "Mở phòng tương tác" → máy chủ (api/index.php?action=roomOpen) cấp mã phòng 5 ký tự
 * + khoá trình chiếu (hostKey). Trang hiện mã + QR trỏ tới sv.html?phong=MÃ, rồi hỏi máy chủ mỗi 4 giây
 * (roomPoll, chỉ lấy bản ghi mới hơn mốc `since`) để gom câu trả lời của ĐÚNG phòng đó.
 *
 * Dự phòng: chạy bằng file:// (không có máy chủ) hoặc chưa mở phòng → vẫn nghe BroadcastChannel
 * 'dkv_live_session' như cũ (chỉ nhận từ tab sv.html trên cùng trình duyệt).
 *
 * Phụ thuộc: deck-engine.js phát sự kiện 'dkv:lesson' và để window.DKV_DECK.lesson(); qrcode-generator.
 * Docs: docs/04-API-PHP.md mục 36.
 */
(function () {
  'use strict';

  var POLL_MS = 4000;
  var POLL_MAX_MS = 15000;
  var STORE_KEY = 'dkv_live_room';
  var ONLINE = /^https?:$/.test(location.protocol);
  var API = ONLINE ? new URL('../api/index.php', location.href).href : null;

  var room = null;          // {code, hostKey, lessonCode, label, linked, expiresAt}
  var since = 0;
  var items = [];           // mọi câu trả lời đã nhận (server + BroadcastChannel khi offline)
  var seen = {};            // id đã có → bỏ trùng
  var pollTimer = null;
  var pollDelay = POLL_MS;
  var lastOkAt = 0;
  var tab = 'quiz';
  var panelOpen = false;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function $(id) { return document.getElementById(id); }
  function store(v) {
    try { if (v) localStorage.setItem(STORE_KEY, JSON.stringify(v)); else localStorage.removeItem(STORE_KEY); } catch (e) {}
  }
  function loadStore() {
    try { return JSON.parse(localStorage.getItem(STORE_KEY) || 'null'); } catch (e) { return null; }
  }
  function curLesson() {
    return (window.DKV_DECK && window.DKV_DECK.lesson()) || null;
  }
  function joinUrl(code) {
    var base = ONLINE ? new URL('sv.html', location.href).href.split('?')[0] : 'https://diemdanhsv.com/dkv/sv.html';
    return base + '?phong=' + encodeURIComponent(code);
  }
  function shortUrl(code) {
    return joinUrl(code).replace(/^https?:\/\//, '');
  }
  function qrSvg(text, cell) {
    try {
      var q = window.qrcode(0, 'M');
      q.addData(text); q.make();
      return q.createSvgTag({ cellSize: cell || 5, margin: 2, scalable: true });
    } catch (e) { return ''; }
  }

  // ---- Gọi API (cùng khuôn APIService: GET đọc, POST body JSON text/plain) ----
  function api(method, params) {
    var opts = { method: method, cache: 'no-store' };
    var url = API;
    if (method === 'GET') {
      url += '?' + new URLSearchParams(params).toString();
    } else {
      opts.headers = { 'Content-Type': 'text/plain;charset=utf-8' };
      opts.body = JSON.stringify(params);
    }
    return fetch(url, opts).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || j.status !== 'success') throw new Error((j && j.message) || 'Lỗi máy chủ.');
      return j.data;
    });
  }

  // ---- Giao diện ----
  function injectUi() {
    var css = document.createElement('style');
    css.textContent =
      '#liveBtn{background:var(--luc)!important;font-weight:700}' +
      '#liveBtn.on{background:#B4371B!important}' +
      '#livePanel{position:fixed;top:12px;right:12px;width:380px;max-width:calc(100vw - 24px);max-height:calc(100vh - 90px);overflow:auto;' +
        'background:#fff;color:var(--muc);border:1px solid var(--line);border-radius:16px;box-shadow:0 18px 40px rgba(16,33,43,.28);z-index:60;' +
        'font-family:var(--sans);font-size:15px;display:none}' +
      '#livePanel.on{display:block}' +
      '#livePanel .lp-h{display:flex;align-items:center;gap:8px;padding:12px 14px;background:linear-gradient(110deg,var(--lam),var(--luc));color:#fff;border-radius:16px 16px 0 0;position:sticky;top:0;z-index:2}' +
      '#livePanel .lp-h b{flex:1;font:700 17px var(--serif)}' +
      '#livePanel .lp-h button{background:rgba(255,255,255,.18);border:0;color:#fff;border-radius:8px;padding:4px 10px;cursor:pointer;font-size:14px}' +
      '#livePanel .lp-b{padding:14px}' +
      '#livePanel .lp-code{display:flex;gap:12px;align-items:center}' +
      '#livePanel .lp-qr{width:120px;flex:none;border:1px solid var(--line);border-radius:10px;padding:4px;background:#fff;cursor:zoom-in}' +
      '#livePanel .lp-qr svg{display:block;width:100%;height:auto}' +
      '#livePanel .lp-big{font:700 40px/1 var(--sans);letter-spacing:6px;color:var(--luc)}' +
      '#livePanel .lp-url{font-size:12.5px;color:var(--mute);word-break:break-all;margin-top:6px}' +
      '#livePanel .lp-lbl{font-size:13px;color:var(--lam);font-weight:600;margin-top:4px}' +
      '#livePanel .lp-st{font-size:12.5px;color:var(--mute);margin:10px 0 0;display:flex;gap:6px;align-items:center}' +
      '#livePanel .lp-st i{width:9px;height:9px;border-radius:50%;background:#2AA36B;display:inline-block}' +
      '#livePanel .lp-st.err i{background:var(--son)}' +
      '#livePanel .lp-tabs{display:flex;gap:6px;margin:12px 0 10px}' +
      '#livePanel .lp-tabs button{flex:1;border:1.5px solid var(--line);background:#fff;border-radius:99px;padding:6px 4px;font:600 13px var(--sans);cursor:pointer;color:var(--muc)}' +
      '#livePanel .lp-tabs button.on{background:var(--luc);border-color:var(--luc);color:#fff}' +
      '#livePanel .lp-q{border:1px solid var(--line);border-radius:12px;padding:10px 12px;margin-bottom:8px}' +
      '#livePanel .lp-q h4{margin:0 0 6px;font-size:14px}' +
      '#livePanel .lp-bar{display:flex;align-items:center;gap:6px;font-size:13px;margin:3px 0}' +
      '#livePanel .lp-bar span.l{width:18px;font-weight:700}' +
      '#livePanel .lp-bar .t{flex:1;height:12px;background:var(--giay);border-radius:6px;overflow:hidden}' +
      '#livePanel .lp-bar .t i{display:block;height:100%;background:var(--lam-suong,#2F6DB0)}' +
      '#livePanel .lp-bar.ok .t i{background:var(--luc)}' +
      '#livePanel .lp-bar.ok span.l{color:var(--luc)}' +
      '#livePanel .lp-bar em{width:34px;text-align:right;font-style:normal;color:var(--mute)}' +
      '#livePanel .lp-it{background:var(--giay);border:1px solid var(--line);border-radius:10px;padding:8px 10px;margin-bottom:6px;font-size:14px;white-space:pre-wrap;word-break:break-word}' +
      '#livePanel .lp-it b{color:var(--luc)}' +
      '#livePanel .lp-it small{color:var(--mute)}' +
      '#livePanel .lp-tag{font-size:11px;background:#FBEFEC;color:var(--son);border-radius:6px;padding:1px 6px;margin-left:4px}' +
      '#livePanel .lp-ft{display:flex;gap:8px;margin-top:12px}' +
      '#livePanel .lp-ft button,#livePanel .lp-open{flex:1;border:0;border-radius:10px;padding:9px 10px;font:600 14px var(--sans);cursor:pointer}' +
      '#livePanel .lp-open{width:100%;background:var(--luc);color:#fff;font-size:15px;padding:11px}' +
      '#livePanel .lp-ft .sec{background:var(--giay);border:1px solid var(--line);color:var(--muc)}' +
      '#livePanel .lp-ft .dan{background:#FBEFEC;color:var(--son)}' +
      '#livePanel input{width:100%;font:600 16px var(--sans);letter-spacing:3px;text-transform:uppercase;border:1.5px solid var(--line);border-radius:10px;padding:9px 12px;margin:6px 0 10px}' +
      '#livePanel .lp-note{font-size:13px;color:var(--mute);margin:8px 0 0}' +
      '#livePanel .lp-err{color:var(--son);font-size:13.5px;margin:8px 0 0}' +
      '#liveBig{position:fixed;inset:0;background:rgba(16,33,43,.94);z-index:70;display:none;align-items:center;justify-content:center;flex-direction:column;color:#fff;font-family:var(--sans);cursor:zoom-out;text-align:center;padding:20px}' +
      '#liveBig.on{display:flex}' +
      '#liveBig .qr{background:#fff;border-radius:18px;padding:14px;width:min(60vh,80vw)}' +
      '#liveBig .qr svg{display:block;width:100%;height:auto}' +
      '#liveBig .code{font:700 min(12vh,14vw)/1.1 var(--sans);letter-spacing:.15em;margin-top:16px;color:var(--ngoc)}' +
      '#liveBig p{font-size:min(3.2vh,4.5vw);margin:6px 0 0;opacity:.9}' +
      '@media print{#livePanel,#liveBig{display:none!important}}';
    document.head.appendChild(css);

    var ctrl = $('ctrl');
    if (ctrl) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.id = 'liveBtn';
      btn.title = 'Mở phòng để sinh viên gửi câu trả lời từ điện thoại';
      btn.textContent = 'Mở phòng tương tác';
      btn.addEventListener('click', function () { togglePanel(); });
      var svLink = ctrl.querySelector('a[href^="sv.html"]');
      ctrl.insertBefore(btn, svLink || null);
    }

    var p = document.createElement('div');
    p.id = 'livePanel';
    p.setAttribute('role', 'dialog');
    p.setAttribute('aria-label', 'Phòng tương tác');
    document.body.appendChild(p);

    var big = document.createElement('div');
    big.id = 'liveBig';
    big.title = 'Bấm để đóng';
    big.addEventListener('click', function () { big.classList.remove('on'); });
    document.body.appendChild(big);

    p.addEventListener('click', onPanelClick);
  }

  function togglePanel(force) {
    panelOpen = typeof force === 'boolean' ? force : !panelOpen;
    $('livePanel').classList.toggle('on', panelOpen);
    render();
  }

  function updateButton() {
    var btn = $('liveBtn');
    if (!btn) return;
    if (room) {
      btn.classList.add('on');
      btn.textContent = 'Phòng ' + room.code + ' · ' + countStudents() + ' SV';
    } else {
      btn.classList.remove('on');
      btn.textContent = 'Mở phòng tương tác';
    }
    var sv = document.querySelector('#ctrl a[href^="sv.html"]');
    if (sv) {
      var l = curLesson();
      sv.href = room ? 'sv.html?phong=' + encodeURIComponent(room.code) : 'sv.html' + (l ? '?lesson=' + encodeURIComponent(l.code) : '');
    }
  }

  function countStudents() {
    var m = {};
    items.forEach(function (it) { if (it.mssv) m[it.mssv] = 1; });
    return Object.keys(m).length;
  }

  function lessonQuestions(lesson) {
    if (!lesson) return [];
    var out = [];
    (lesson.slides || []).forEach(function (s) { if (s.type === 'quiz') out = out.concat(s.qs || []); });
    return out;
  }

  function render() {
    updateButton();
    var p = $('livePanel');
    if (!p || !panelOpen) return;
    if (!room) { p.innerHTML = renderClosed(); return; }
    var lesson = curLesson();
    var lessonCode = lesson ? lesson.code : room.lessonCode;
    var mine = items.filter(function (it) { return it.lesson === lessonCode; });
    var groups = mine.filter(function (it) { return it.kind === 'group'; }).reverse();
    var qas = mine.filter(function (it) { return it.kind === 'qa'; }).reverse();
    var age = lastOkAt ? Math.round((Date.now() - lastOkAt) / 1000) : null;
    var stErr = room.offline ? false : (age === null || age > 20);

    var body;
    if (tab === 'quiz') body = renderQuiz(lesson, mine);
    else if (tab === 'group') body = groups.length ? groups.map(function (g) {
      return '<div class="lp-it"><b>' + esc(g.group) + '</b> <small>' + esc(hhmm(g.time)) + '</small>' + rosterTag(g) + '\n' + esc(g.text) + '</div>';
    }).join('') : '<p class="lp-note">Chưa có nhóm nào gửi kết quả.</p>';
    else body = qas.length ? qas.map(function (q) {
      return '<div class="lp-it"><small>' + esc(hhmm(q.time)) + ' · ẩn danh</small>\n' + esc(q.text) + '</div>';
    }).join('') : '<p class="lp-note">Chưa có câu hỏi nào.</p>';

    var nQuiz = mine.filter(function (it) { return it.kind === 'quiz'; }).length;
    p.innerHTML =
      '<div class="lp-h"><b>Phòng tương tác</b><button type="button" data-act="big" title="Phóng to mã cho cả lớp quét">Phóng to</button><button type="button" data-act="hide" title="Thu gọn (phòng vẫn mở)">✕</button></div>' +
      '<div class="lp-b">' +
        (room.offline
          ? '<p class="lp-note" style="margin-top:0">Đang chạy ngoại tuyến (file://): chỉ nhận câu trả lời từ trang Sinh viên mở trên <b>cùng máy</b>. Mở bài giảng tại diemdanhsv.com để nhận từ điện thoại.</p>'
          : '<div class="lp-code"><div class="lp-qr" data-act="big" title="Phóng to">' + qrSvg(joinUrl(room.code), 4) + '</div>' +
            '<div><div style="font-size:13px;color:var(--mute)">Mã phòng</div><div class="lp-big">' + esc(room.code) + '</div>' +
            '<div class="lp-url">' + esc(shortUrl(room.code)) + '</div>' +
            (room.label ? '<div class="lp-lbl">' + esc(room.label) + '</div>' : '') + '</div></div>' +
            '<div class="lp-st' + (stErr ? ' err' : '') + '"><i></i>' +
              (stErr ? 'Mất kết nối máy chủ, đang thử lại…' : 'Đang nhận · ' + countStudents() + ' sinh viên · bài ' + esc(lessonCode)) + '</div>') +
        '<div class="lp-tabs">' +
          '<button type="button" data-tab="quiz" class="' + (tab === 'quiz' ? 'on' : '') + '">Trắc nghiệm (' + nQuiz + ')</button>' +
          '<button type="button" data-tab="group" class="' + (tab === 'group' ? 'on' : '') + '">Nhóm (' + groups.length + ')</button>' +
          '<button type="button" data-tab="qa" class="' + (tab === 'qa' ? 'on' : '') + '">Hỏi đáp (' + qas.length + ')</button>' +
        '</div>' + body +
        '<div class="lp-ft">' +
          '<button type="button" class="sec" data-act="csv" title="Tải mọi câu trả lời (có MSSV, họ tên) về máy">Tải CSV</button>' +
          '<button type="button" class="dan" data-act="close">' + (room.offline ? 'Tắt' : 'Đóng phòng') + '</button>' +
        '</div>' +
      '</div>';
  }

  function renderClosed() {
    var l = curLesson();
    return '<div class="lp-h"><b>Phòng tương tác</b><button type="button" data-act="hide">✕</button></div>' +
      '<div class="lp-b">' +
        (ONLINE
          ? '<p style="margin:0 0 8px">Mở phòng cho bài <b>' + esc(l ? l.code + ' · ' + l.title : '') + '</b>. Sinh viên quét QR hoặc nhập mã phòng để gửi câu trả lời trắc nghiệm, kết quả nhóm và câu hỏi. Mỗi phòng có mã riêng nên các lớp học cùng giờ không lẫn nhau.</p>' +
            '<label style="font-size:13.5px;font-weight:600">Mã điểm danh đang mở của lớp (không bắt buộc)</label>' +
            '<input id="liveAtt" maxlength="4" placeholder="VD: KX7M" autocomplete="off">' +
            '<button type="button" class="lp-open" data-act="open">Mở phòng tương tác</button>' +
            '<p class="lp-note">Có mã điểm danh: phòng gắn với lớp/buổi đó và đánh dấu sinh viên ngoài danh sách. Phòng tự đóng sau 4 giờ.</p>' +
            '<p class="lp-err" id="liveErr"></p>'
          : '<p style="margin:0 0 8px">Đang mở bằng file:// nên không có máy chủ. Trang chỉ nhận câu trả lời từ trang Sinh viên mở trên <b>cùng trình duyệt</b> của máy này.</p>' +
            '<button type="button" class="lp-open" data-act="offline">Bật nhận câu trả lời ngoại tuyến</button>') +
      '</div>';
  }

  function renderQuiz(lesson, mine) {
    var qs = lessonQuestions(lesson);
    if (!qs.length) return '<p class="lp-note">Bài này không có câu hỏi trắc nghiệm.</p>';
    var ans = {};
    mine.forEach(function (it) {
      if (it.kind !== 'quiz' || it.qIdx == null) return;
      (ans[it.qIdx] = ans[it.qIdx] || {})[it.mssv || ('#' + it.id)] = it.choice; // mỗi SV một đáp án
    });
    return qs.map(function (q, i) {
      var cnt = {}, total = 0;
      Object.keys(ans[i] || {}).forEach(function (k) { var c = ans[i][k]; cnt[c] = (cnt[c] || 0) + 1; total++; });
      var right = cnt[q.a] || 0;
      var bars = (q.o || []).map(function (opt, oi) {
        var L = 'ABCDEF'[oi], n = cnt[L] || 0, pct = total ? Math.round(n * 100 / total) : 0;
        return '<div class="lp-bar' + (L === q.a ? ' ok' : '') + '" title="' + esc(opt) + '"><span class="l">' + L + '</span><span class="t"><i style="width:' + pct + '%"></i></span><em>' + n + '</em></div>';
      }).join('');
      return '<div class="lp-q"><h4>Câu ' + (i + 1) + ' · ' + total + ' trả lời' + (total ? ' · ' + Math.round(right * 100 / total) + '% đúng' : '') + '</h4>' + bars + '</div>';
    }).join('');
  }

  function rosterTag(it) { return it.inRoster === false ? '<span class="lp-tag">ngoài DS lớp</span>' : ''; }
  function hhmm(iso) {
    var d = iso ? new Date(iso) : new Date();
    if (isNaN(d)) return '';
    return d.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
  }

  function showBig() {
    if (!room || room.offline) return;
    var b = $('liveBig');
    b.innerHTML = '<div class="qr">' + qrSvg(joinUrl(room.code), 8) + '</div><div class="code">' + esc(room.code) + '</div>' +
      '<p>Quét mã QR hoặc vào <b>' + esc(shortUrl(room.code).split('?')[0]) + '</b> rồi nhập mã phòng</p>' +
      (room.label ? '<p>' + esc(room.label) + '</p>' : '');
    b.classList.add('on');
  }

  function onPanelClick(e) {
    var t = e.target.closest('[data-act],[data-tab]');
    if (!t) return;
    if (t.dataset.tab) { tab = t.dataset.tab; render(); return; }
    var act = t.dataset.act;
    if (act === 'hide') togglePanel(false);
    else if (act === 'big') showBig();
    else if (act === 'open') openRoom();
    else if (act === 'offline') { room = { code: 'OFFLINE', offline: true }; render(); }
    else if (act === 'csv') downloadCsv();
    else if (act === 'close') closeRoom();
  }

  // ---- Mở / đóng phòng, hỏi định kỳ ----
  function openRoom() {
    var l = curLesson();
    var att = ($('liveAtt') && $('liveAtt').value || '').trim();
    var btn = document.querySelector('#livePanel [data-act="open"]');
    if (btn) { btn.disabled = true; btn.textContent = 'Đang mở phòng…'; }
    api('POST', { action: 'roomOpen', lessonCode: l ? l.code : '', attendanceCode: att }).then(function (d) {
      room = d; since = 0; items = []; seen = {};
      firstBatchDone = true;
      store(room);
      lastOkAt = Date.now();
      tab = 'quiz';
      render();
      showBig();
      schedulePoll(0);
    }).catch(function (err) {
      if (btn) { btn.disabled = false; btn.textContent = 'Mở phòng tương tác'; }
      var el = $('liveErr');
      if (el) el.textContent = err.message === 'Failed to fetch' ? 'Không kết nối được máy chủ. Kiểm tra mạng rồi thử lại.' : err.message;
    });
  }

  function closeRoom() {
    if (!room) return;
    if (room.offline) { room = null; render(); return; }
    if (!confirm('Đóng phòng ' + room.code + '? Sinh viên sẽ không gửi thêm được. Nên bấm "Tải CSV" trước nếu cần lưu.')) return;
    var r = room;
    api('POST', { action: 'roomClose', code: r.code, hostKey: r.hostKey }).catch(function () {});
    stopRoom();
  }

  function stopRoom() {
    clearTimeout(pollTimer);
    room = null; since = 0;
    store(null);
    render();
  }

  function schedulePoll(ms) {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(poll, ms);
  }

  function poll() {
    if (!room || room.offline) return;
    var r = room;
    api('POST', { action: 'roomPoll', code: r.code, hostKey: r.hostKey, since: since }).then(function (d) {
      if (room !== r) return;
      lastOkAt = Date.now();
      pollDelay = POLL_MS;
      if (d.room) { room.label = d.room.label; room.lessonCode = d.room.lessonCode; room.expiresAt = d.room.expiresAt; }
      since = Math.max(since, d.last || 0);
      var fresh = (d.items || []).filter(add);
      if (fresh.length && since > 0) toastBatch(fresh);
      render();
      schedulePoll(d.more ? 50 : pollDelay);
    }).catch(function (err) {
      if (room !== r) return;
      if (/đã đóng|hết hạn/i.test(err.message)) {
        toast('⏱ Phòng ' + r.code + ' đã đóng hoặc hết hạn.');
        stopRoom();
        return;
      }
      pollDelay = Math.min(POLL_MAX_MS, pollDelay * 1.6);
      render();
      schedulePoll(pollDelay);
    });
  }

  function add(it) {
    var key = it.id;
    if (seen[key]) return false;
    seen[key] = 1;
    items.push(it);
    return true;
  }

  // Lô đầu tiên khi mở lại trang (since=0) không bật thông báo; sau đó chỉ báo nhóm/hỏi đáp (quiz cộng dồn vào biểu đồ).
  var firstBatchDone = false;
  function toastBatch(fresh) {
    if (!firstBatchDone) { firstBatchDone = true; return; }
    fresh.forEach(function (it) {
      if (it.kind === 'group') toast('👥 <b>' + esc(it.group) + ':</b> ' + esc(it.text.substring(0, 60)) + (it.text.length > 60 ? '…' : ''));
      else if (it.kind === 'qa') toast('💬 <b>Câu hỏi mới:</b> ' + esc(it.text.substring(0, 60)) + (it.text.length > 60 ? '…' : ''));
    });
  }

  var toastY = 0;
  function toast(html) {
    var t = document.createElement('div');
    var top = 20 + (toastY++ % 4) * 58;
    t.style.cssText = 'position:fixed;top:' + top + 'px;left:20px;max-width:46vw;background:rgba(16,33,43,0.92);color:#fff;padding:12px 20px;border-radius:12px;z-index:9999;font-size:16px;box-shadow:0 8px 25px rgba(0,0,0,0.3);border-left:5px solid var(--luc-non);animation:sIn .3s ease;';
    t.innerHTML = html;
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); toastY = Math.max(0, toastY - 1); }, 4500);
  }

  function downloadCsv() {
    var rows = [['Thời gian', 'Loại', 'Bài', 'MSSV', 'Họ tên', 'Trong DS lớp', 'Câu', 'Đáp án', 'Kết quả', 'Nhóm', 'Nội dung']];
    var lessons = (window.DKV_DECK && window.DKV_DECK.lessons()) || [];
    items.forEach(function (it) {
      var res = '';
      if (it.kind === 'quiz') {
        var L = lessons.filter(function (x) { return x.code === it.lesson; })[0];
        var q = lessonQuestions(L)[it.qIdx];
        res = q ? (q.a === it.choice ? 'Đúng' : 'Sai') : '';
      }
      rows.push([
        it.time ? new Date(it.time).toLocaleString('vi-VN') : '',
        { quiz: 'Trắc nghiệm', group: 'Nhóm', qa: 'Hỏi đáp' }[it.kind] || it.kind,
        it.lesson, it.mssv || '', it.name || '',
        it.inRoster === true ? 'Có' : it.inRoster === false ? 'Không' : '',
        it.qIdx != null ? it.qIdx + 1 : '', it.choice || '', res, it.group || '', it.text || ''
      ]);
    });
    var csv = rows.map(function (r) {
      return r.map(function (c) { c = String(c == null ? '' : c); return /[",\n;]/.test(c) ? '"' + c.replace(/"/g, '""') + '"' : c; }).join(',');
    }).join('\r\n');
    var blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'phong-' + (room && !room.offline ? room.code : 'ngoai-tuyen') + '-' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(a); a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  }

  // ---- Dự phòng BroadcastChannel (file:// hoặc chưa mở phòng máy chủ) ----
  var bcSeq = 0;
  function setupBroadcast() {
    try {
      var ch = new BroadcastChannel('dkv_live_session');
      ch.onmessage = function (e) {
        var msg = e.data;
        if (!msg || !msg.data) return;
        if (room && !room.offline) return; // đã có phòng máy chủ → bỏ qua để không đếm trùng
        var d = msg.data;
        var it = {
          id: 'bc' + (++bcSeq), kind: msg.type, lesson: msg.lesson, mssv: d.mssv || '', name: d.name || '',
          inRoster: null, qIdx: d.qIdx != null ? d.qIdx : null, choice: d.choice || '',
          group: d.group || '', text: d.answer || d.q || d.text || '', time: new Date().toISOString()
        };
        add(it);
        if (msg.type === 'quiz') {
          toast('⚡ <b>Sinh viên vừa trả lời:</b> Câu ' + (d.qIdx + 1) + ' chọn <b>' + esc(d.choice) + '</b>');
        } else if (msg.type === 'group') {
          toast('👥 <b>Ý kiến mới từ ' + esc(it.group) + ':</b> ' + esc(it.text.substring(0, 45)) + '…');
        } else if (msg.type === 'qa') {
          toast('💬 <b>Câu hỏi mới:</b> ' + esc(it.text.substring(0, 50)) + '…');
        }
        render();
      };
    } catch (err) {}
  }

  // ---- Khởi động ----
  function init() {
    injectUi();
    setupBroadcast();

    document.addEventListener('dkv:lesson', function () {
      if (room && !room.offline) {
        var l = curLesson();
        if (l && l.code !== room.lessonCode) {
          room.lessonCode = l.code;
          store(room);
          api('POST', { action: 'roomSetLesson', code: room.code, hostKey: room.hostKey, lessonCode: l.code }).catch(function () {});
        }
      }
      render();
    });

    // Mở lại trang (F5) trong lúc phòng còn hạn → nối lại phòng cũ.
    var saved = ONLINE ? loadStore() : null;
    if (saved && saved.code && saved.hostKey && (!saved.expiresAt || new Date(saved.expiresAt) > new Date())) {
      room = saved;
      firstBatchDone = false;
      schedulePoll(0);
    } else {
      store(null);
    }
    updateButton();
    setInterval(function () { if (panelOpen && room) render(); }, 5000); // làm mới dòng trạng thái
  }

  window.DKV_LIVE = { init: init };
})();
