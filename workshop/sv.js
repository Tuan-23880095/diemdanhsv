/* sv.js — trang điện thoại của sinh viên (ẩn danh, lưu tạm trên máy, tự gửi lại khi mất mạng). */
(function () {
  'use strict';
  var WS = window.WS, esc = WS.esc, st = WS.store;
  var CLIENT = WS.clientId();
  var $ = function (id) { return document.getElementById(id); };

  var TABS = [
    ['khoidong', 'Khởi động'], ['hinh', 'Hình khối'], ['muctieu', 'Mục tiêu'], ['eisen', 'Eisenhower'],
    ['banhxe', 'Bánh xe'], ['camket', 'Cam kết'], ['hoi', 'Hỏi đáp'], ['danhgia', 'Góp ý'], ['pomo', '⏱ Pomodoro'], ['kehoach', '★ Kế hoạch của tôi']
  ];
  var NAMES = {}; TABS.forEach(function (t) { NAMES[t[0]] = t[1]; });
  var current = null, liveAct = '';

  function done(act) { return !!st.get('v.' + act, null); }
  function drawTabs() {
    $('tabs').innerHTML = TABS.map(function (t) {
      return '<button type="button" data-t="' + t[0] + '" class="' + (t[0] === current ? 'on' : '') + (t[0] === liveAct && t[0] !== current ? ' live' : '') + '">' + t[1] + (done(t[0]) ? '<span class="ok">✓</span>' : '') + '</button>';
    }).join('');
  }
  function show(t) {
    if (!$('p-' + t)) t = 'khoidong';
    current = t;
    Array.prototype.forEach.call(document.querySelectorAll('.panel'), function (p) { p.classList.toggle('on', p.id === 'p-' + t); });
    drawTabs();
    var b = document.querySelector('nav.tabs button.on'); if (b && b.scrollIntoView) b.scrollIntoView({ inline: 'center', block: 'nearest' });
    if (history.replaceState) history.replaceState(null, '', '#' + t);
    $('liveBar').classList.toggle('on', !!liveAct && liveAct !== t);
    if (t === 'hoi') loadQ();
    if (t === 'kehoach') drawMine();
    var hall = { khoidong: 'kdHall', hinh: 'hHall', eisen: 'eHall', muctieu: 'gHall', camket: 'cHall' }[t];
    if (hall && done(t)) loadHall(t, hall);
    if (t === 'banhxe' && done('banhxe')) loadWheelHall();
    window.scrollTo(0, 0);
  }
  $('tabs').addEventListener('click', function (e) { var b = e.target.closest('[data-t]'); if (b) show(b.dataset.t); });
  $('liveGo').onclick = function () { if (liveAct) show(liveAct); };

  /* ───── gửi phiếu (có hàng đợi khi mất mạng) ───── */
  function setSt(id, t, cls) { var e = $(id); e.textContent = t; e.className = 'status' + (cls ? ' ' + cls : ''); }
  function sendVote(act, data, stId, onOk) {
    st.set('v.' + act, data);
    setSt(stId, 'Đang gửi…');
    return WS.post({ a: 'vote', act: act, c: CLIENT, d: data }).then(function () {
      var p = st.get('pending', {}); delete p[act]; st.set('pending', p);
      setSt(stId, '✓ Đã gửi. Bạn có thể sửa và gửi lại bất cứ lúc nào.', 'ok');
      drawTabs(); if (onOk) onOk();
    }).catch(function (e) {
      if (!navigator.onLine || /fetch|network|Failed/i.test(e.message)) {
        var p = st.get('pending', {}); p[act] = data; st.set('pending', p);
        setSt(stId, 'Chưa gửi được (mạng yếu). Đã lưu trên máy — sẽ tự gửi lại.', 'err');
        drawTabs(); if (onOk) onOk(true);
      } else setSt(stId, e.message, 'err');
    });
  }
  function flushPending() {
    var p = st.get('pending', {});
    Object.keys(p).forEach(function (act) {
      WS.post({ a: 'vote', act: act, c: CLIENT, d: p[act] }).then(function () { var q = st.get('pending', {}); delete q[act]; st.set('pending', q); }).catch(function () {});
    });
  }
  window.addEventListener('online', function () { $('offline').classList.remove('on'); flushPending(); });
  window.addEventListener('offline', function () { $('offline').classList.add('on'); });
  setInterval(flushPending, 20000);

  function loadHall(act, boxId) {
    var box = $(boxId);
    WS.get({ a: 'results', act: act }).then(function (d) {
      box.innerHTML = '<div class="card"><h3>Cả hội trường <span class="n">' + d.n + ' phiếu</span></h3>' + (d.n ? WS.render[act](d) : '<p class="empty">Chưa có phiếu.</p>') + '</div>';
    }).catch(function () { box.innerHTML = ''; });
  }

  /* ───── Khởi động ───── */
  function optGroup(boxId, opts, val, onPick) {
    var box = $(boxId);
    box.innerHTML = opts.map(function (o, i) { return '<button type="button" class="opt' + (val === i ? ' on' : '') + '" data-i="' + i + '"><span class="rd"></span>' + esc(o) + '</button>'; }).join('');
    box.onclick = function (e) { var b = e.target.closest('.opt'); if (!b) return; Array.prototype.forEach.call(box.children, function (c) { c.classList.toggle('on', c === b); }); onPick(+b.dataset.i); };
  }
  var kd = st.get('v.khoidong', {}) || {};
  $('kdCamQ').textContent = WS.KHOIDONG.cam.q; $('kdManQ').textContent = WS.KHOIDONG.man.q;
  optGroup('kdCam', WS.KHOIDONG.cam.opts, kd.cam, function (i) { kd.cam = i; });
  optGroup('kdMan', WS.KHOIDONG.man.opts, kd.man, function (i) { kd.man = i; });
  $('kdSend').onclick = function () {
    if (kd.cam == null || kd.man == null) return setSt('kdSt', 'Hãy chọn đủ hai câu.', 'err');
    sendVote('khoidong', { cam: kd.cam, man: kd.man }, 'kdSt', function () { loadHall('khoidong', 'kdHall'); });
  };

  /* ───── Hình khối ───── */
  var hv = st.get('v.hinh', null) || { top: '', second: '' };
  function drawShapes() {
    $('shapes').innerHTML = WS.SHAPE_ORDER.map(function (k) {
      var s = WS.SHAPES[k], n = hv.top === k ? 1 : hv.second === k ? 2 : 0;
      return '<button type="button" class="shp' + (n ? ' on' : '') + '" data-k="' + k + '" style="--c:' + s.color + '" aria-pressed="' + !!n + '"><span class="badge">' + n + '</span>' + WS.shapeSvg(k, 44) + s.name + '</button>';
    }).join('');
    $('hSend').disabled = !hv.top;
  }
  $('shapes').onclick = function (e) {
    var b = e.target.closest('.shp'); if (!b) return; var k = b.dataset.k;
    if (hv.top === k) { hv.top = hv.second; hv.second = ''; }
    else if (hv.second === k) hv.second = '';
    else if (!hv.top) hv.top = k;
    else hv.second = k;
    drawShapes();
  };
  function shapeCard(k, second) {
    var s = WS.SHAPES[k];
    return '<div class="card shape-res" style="--c:' + s.color + '"><div class="hd">' + WS.shapeSvg(k, 48) + '<div><div class="lb" style="margin:0">' + (second ? 'Hình thứ hai' : 'Hình nổi trội') + '</div><h3>' + s.name + '</h3></div></div>' +
      '<p class="motto">' + s.motto + '</p><p style="margin:0">' + s.core + '</p>' +
      (second ? '' : '<div class="lb">Điểm mạnh với thời gian</div><p style="margin:4px 0 0">' + s.strong + '</p><div class="lb">Bẫy thời gian</div><p style="margin:4px 0 0">' + s.trap + '</p>') +
      '<div class="lb">Thử ngay</div><ul class="tips">' + (second ? s.tips.slice(0, 1) : s.tips).map(function (t) { return '<li>' + t + '</li>'; }).join('') + '</ul></div>';
  }
  function drawShapeRes() { $('hRes').innerHTML = hv.top ? shapeCard(hv.top) + (hv.second ? shapeCard(hv.second, true) : '') : ''; }
  $('hSend').onclick = function () { sendVote('hinh', { top: hv.top, second: hv.second }, 'hSt', function () { drawShapeRes(); loadHall('hinh', 'hHall'); }); };
  drawShapes(); if (done('hinh')) drawShapeRes();

  /* ───── Mục tiêu SMART ───── */
  var gv = st.get('v.muctieu', null) || { text: '', f: [0, 0, 0, 0, 0] };
  $('goal').value = gv.text || '';
  function drawSmart() {
    var t = $('goal').value;
    var hasNum = /\d/.test(t);
    var hasTime = /(\d{1,2}\s*[\/\-.]\s*\d{1,2})|tuần|tháng|ngày|học kỳ|học kì|trước|cuối kỳ|giữa kỳ|thứ\s?(hai|ba|tư|năm|sáu|bảy|[2-7])|chủ nhật|hằng|mỗi/i.test(t);
    $('smart').innerHTML = WS.SMART.map(function (s, i) {
      var auto = (i === 1 && hasNum) ? '<span class="auto">· có con số ✓</span>' : (i === 4 && hasTime) ? '<span class="auto">· có mốc thời gian ✓</span>' : '';
      return '<button type="button" class="sm' + (gv.f[i] ? ' on' : '') + '" data-i="' + i + '" aria-pressed="' + !!gv.f[i] + '"><b class="k">' + s.k + '</b><span class="tx"><b>' + s.name + ' ' + auto + '</b>' + s.q + '</span><span class="sw"></span></button>';
    }).join('');
    $('goalCount').textContent = t.length + '/300';
  }
  $('goal').addEventListener('input', function () { gv.text = this.value; drawSmart(); });
  $('smart').onclick = function (e) { var b = e.target.closest('.sm'); if (!b) return; var i = +b.dataset.i; gv.f[i] = gv.f[i] ? 0 : 1; drawSmart(); };
  function goalFeedback() {
    var miss = WS.SMART.filter(function (_, i) { return !gv.f[i]; });
    var fix = { S: 'Nêu rõ môn/việc cụ thể và hành động (đọc, giải, viết, luyện…).', M: 'Thêm một con số: số bài, số giờ, số buổi, điểm mục tiêu.', A: 'Hạ mức hoặc kéo dài thời gian cho vừa sức — vừa đủ thử thách.', R: 'Hỏi: mục tiêu này phục vụ điều gì mình thật sự cần?', T: 'Thêm ngày, tuần hoặc “trước giữa kỳ”.' };
    $('gRes').innerHTML = '<div class="card"><h3>' + (miss.length ? 'Gần xong rồi — bổ sung thêm:' : '🎉 Đủ cả 5 tiêu chí!') + '</h3>' +
      (miss.length ? '<ul class="tips">' + miss.map(function (s) { return '<li><b>' + s.k + ' – ' + s.name + ':</b> ' + fix[s.k] + '</li>'; }).join('') + '</ul>' : '<p style="margin:0">Bước tiếp theo: viết ra <b>việc đầu tiên</b> cho mục tiêu này và đặt nó vào lịch tuần này.</p>') + '</div>';
  }
  $('gSend').onclick = function () {
    var t = $('goal').value.trim();
    if (t.length < 8) return setSt('gSt', 'Mục tiêu hơi ngắn — viết rõ hơn một chút nhé.', 'err');
    gv.text = t;
    sendVote('muctieu', { text: t, f: gv.f.slice() }, 'gSt', function () { goalFeedback(); loadHall('muctieu', 'gHall'); });
  };
  drawSmart(); if (done('muctieu')) goalFeedback();

  /* ───── Eisenhower ───── */
  var ev = (st.get('v.eisen', null) || { cards: {} }).cards;
  var eShown = done('eisen');
  $('legend4').innerHTML = WS.QUADS.map(function (q) { return '<span style="background:' + q.color + '">Ô ' + q.n + ' · ' + q.name + '<br><small style="font-weight:500;opacity:.9">' + q.sub + '</small></span>'; }).join('');
  function drawEisen() {
    var n = Object.keys(ev).length;
    $('ecards').innerHTML = WS.EISEN.map(function (c) {
      var pick = ev[c.id];
      var why = '';
      if (eShown && pick) {
        var same = pick === c.q;
        why = '<div class="why' + (same ? ' same' : '') + '">' + (same ? '✓ Giống gợi ý (ô ' + c.q + '). ' : 'Gợi ý: <b>ô ' + c.q + '</b> — bạn chọn ô ' + pick + '. ') + c.why + '</div>';
      }
      return '<div class="ecard"><div class="et">' + c.icon + ' ' + esc(c.text) + '</div><div class="qbtns" data-c="' + c.id + '">' + WS.QUADS.map(function (q) {
        var on = pick === q.n;
        return '<button type="button" data-q="' + q.n + '" class="' + (on ? 'on' : '') + '" style="' + (on ? 'background:' + q.color : '') + '" aria-pressed="' + on + '"><b>' + q.n + '</b>' + q.short + '</button>';
      }).join('') + '</div>' + why + '</div>';
    }).join('');
    $('eProg').style.width = (n / WS.EISEN.length * 100) + '%';
    $('eSend').disabled = n < WS.EISEN.length;
    $('eSend').textContent = n < WS.EISEN.length ? 'Còn ' + (WS.EISEN.length - n) + ' thẻ chưa xếp' : 'Gửi và xem gợi ý';
  }
  $('ecards').onclick = function (e) { var b = e.target.closest('button[data-q]'); if (!b) return; ev[b.parentNode.dataset.c] = +b.dataset.q; drawEisen(); };
  $('eSend').onclick = function () { sendVote('eisen', { cards: ev }, 'eSt', function () { eShown = true; drawEisen(); loadHall('eisen', 'eHall'); }); };
  drawEisen();

  /* ───── Bánh xe ───── */
  var bv = (st.get('v.banhxe', null) || { v: [5, 5, 5, 5, 5, 5, 5, 5] }).v.slice();
  var hallAvg = null;
  function drawRadar() {
    var series = [{ v: bv, color: '#0F6B55' }];
    if (hallAvg) series.unshift({ v: hallAvg, color: '#D6A130' });
    $('radarMe').innerHTML = WS.render.radar(bv, { size: 340, series: series });
    $('radarLegend').innerHTML = '<span><i style="background:var(--luc)"></i>Của bạn</span>' + (hallAvg ? '<span><i style="background:var(--tho)"></i>Trung bình hội trường</span>' : '');
  }
  $('sliders').innerHTML = WS.WHEEL.map(function (w, i) {
    return '<div class="sl"><label for="w' + i + '">' + w + '</label><output id="wo' + i + '">' + bv[i] + '</output><input type="range" id="w' + i + '" min="1" max="10" step="1" value="' + bv[i] + '" data-i="' + i + '"></div>';
  }).join('');
  $('sliders').addEventListener('input', function (e) { var i = +e.target.dataset.i; if (isNaN(i)) return; bv[i] = +e.target.value; $('wo' + i).textContent = bv[i]; drawRadar(); });
  function wheelAdvice() {
    var lo = bv.map(function (v, i) { return [v, i]; }).sort(function (a, b) { return a[0] - b[0]; })[0];
    $('bRes').innerHTML = '<div class="card"><h3>Nan hoa thấp nhất: ' + WS.WHEEL[lo[1]] + ' (' + lo[0] + '/10)</h3><p style="margin:0">Gợi ý: trong lịch tuần tới, đặt <b>một việc nhỏ</b> cho lĩnh vực này — ví dụ 30 phút, có ngày giờ cụ thể.</p></div>';
  }
  function loadWheelHall() { WS.get({ a: 'results', act: 'banhxe' }).then(function (d) { if (d.n) { hallAvg = d.avg; drawRadar(); } }).catch(function () {}); }
  $('bSend').onclick = function () { sendVote('banhxe', { v: bv.slice() }, 'bSt', function () { wheelAdvice(); loadWheelHall(); }); };
  drawRadar(); if (done('banhxe')) wheelAdvice();

  /* ───── Cam kết ───── */
  var cv = st.get('v.camket', null) || {};
  optGroup('pledges', WS.PLEDGES, cv.pick, function (i) { cv.pick = i; });
  $('frog').value = st.get('frog', '');
  $('frog').addEventListener('input', function () { st.set('frog', this.value); });
  $('cSend').onclick = function () {
    if (cv.pick == null) return setSt('cSt', 'Hãy chọn một cam kết.', 'err');
    sendVote('camket', { pick: cv.pick }, 'cSt', function () { loadHall('camket', 'cHall'); setSt('cSt', '✓ Đã cam kết! Vào “Kế hoạch của tôi” để tải lịch nhắc 7 ngày.', 'ok'); });
  };

  /* ───── Hỏi đáp ───── */
  var qTimer = null;
  function loadQ() {
    clearTimeout(qTimer);
    WS.get({ a: 'questions', c: CLIENT }).then(function (d) {
      var mine = d.mine || [];
      $('qList').innerHTML = d.items.length ? d.items.map(function (q) {
        var on = mine.indexOf(q.id) >= 0;
        return '<div class="qitem' + (q.status === 1 ? ' done' : '') + '"><div>' + esc(q.text) + (q.status === 1 ? ' <span class="auto">✓ đã trả lời</span>' : '') + '</div><button type="button" class="vb' + (on ? ' on' : '') + '" data-q="' + q.id + '"' + (on ? ' disabled aria-label="Đã bình chọn"' : ' aria-label="Bình chọn câu này"') + '>▲<span>' + q.votes + '</span></button></div>';
      }).join('') : '<p class="empty">Chưa có câu hỏi nào. Hãy là người đầu tiên!</p>';
    }).catch(function () { $('qList').innerHTML = '<p class="empty">Không tải được — kiểm tra mạng rồi bấm Làm mới.</p>'; })
      .then(function () { if (current === 'hoi' && !document.hidden) qTimer = setTimeout(loadQ, 8000); });
  }
  $('qRefresh').onclick = loadQ;
  $('qText').addEventListener('input', function () { $('qCount').textContent = this.value.length + '/300'; });
  $('qSend').onclick = function () {
    var t = $('qText').value.trim();
    if (t.length < 5) return setSt('qSt', 'Câu hỏi hơi ngắn — viết rõ hơn một chút nhé.', 'err');
    this.disabled = true;
    var btn = this;
    WS.post({ a: 'ask', c: CLIENT, text: t }).then(function () { $('qText').value = ''; $('qCount').textContent = '0/300'; setSt('qSt', '✓ Đã gửi câu hỏi.', 'ok'); st.set('v.hoi', 1); drawTabs(); loadQ(); })
      .catch(function (e) { setSt('qSt', e.message, 'err'); }).then(function () { btn.disabled = false; });
  };
  $('qList').onclick = function (e) {
    var b = e.target.closest('.vb'); if (!b || b.disabled) return;
    b.disabled = true; b.classList.add('on'); var sp = b.querySelector('span'); sp.textContent = +sp.textContent + 1;
    WS.post({ a: 'upq', c: CLIENT, qid: +b.dataset.q }).then(loadQ).catch(loadQ);
  };

  /* ───── Góp ý ───── */
  var fv = st.get('v.danhgia', null) || { sao: 0, text: '' };
  $('fbText').value = fv.text || '';
  function drawStars() {
    $('stars').innerHTML = [1, 2, 3, 4, 5].map(function (i) { return '<button type="button" data-s="' + i + '" class="' + (i <= fv.sao ? 'on' : '') + '" aria-label="' + i + ' sao">★</button>'; }).join('');
    $('fSend').disabled = !fv.sao;
  }
  $('stars').onclick = function (e) { var b = e.target.closest('[data-s]'); if (b) { fv.sao = +b.dataset.s; drawStars(); } };
  $('fSend').onclick = function () { fv.text = $('fbText').value.trim(); sendVote('danhgia', { sao: fv.sao, text: fv.text }, 'fSt', function () { setSt('fSt', '✓ Cảm ơn bạn đã góp ý!', 'ok'); }); };
  drawStars();

  /* ───── Pomodoro ───── */
  var pm = { w: 25, b: 5, left: 25 * 60, run: false, brk: false, n: 0, t: null, end: 0 };
  function pShow() { var m = Math.floor(pm.left / 60), s = pm.left % 60; $('pomoV').textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0'); $('pomoL').textContent = (pm.brk ? 'Nghỉ ngơi' : 'Phiên tập trung') + ' · đã xong ' + pm.n + ' phiên'; document.title = pm.run ? $('pomoV').textContent + ' · Pomodoro' : 'Tham gia Workshop K2026 — Quản lý thời gian · HCMUS'; }
  function pBeep() { try { var c = new (window.AudioContext || window.webkitAudioContext)(); [0, .4, .8].forEach(function (t) { var o = c.createOscillator(), g = c.createGain(); o.frequency.value = 784; o.connect(g); g.connect(c.destination); g.gain.setValueAtTime(.3, c.currentTime + t); g.gain.exponentialRampToValueAtTime(.001, c.currentTime + t + .35); o.start(c.currentTime + t); o.stop(c.currentTime + t + .35); }); } catch (e) {} if (navigator.vibrate) navigator.vibrate([300, 150, 300]); }
  function pTick() {
    pm.left = Math.max(0, Math.round((pm.end - Date.now()) / 1000)); pShow();
    if (pm.left <= 0) {
      pBeep();
      if (!pm.brk) { pm.n++; pm.brk = true; pm.left = (pm.n % 4 === 0 ? 20 : pm.b) * 60; } else { pm.brk = false; pm.left = pm.w * 60; }
      pm.end = Date.now() + pm.left * 1000; pShow();
    }
  }
  $('pomoGo').onclick = function () {
    pm.run = !pm.run;
    if (pm.run) { pm.end = Date.now() + pm.left * 1000; pm.t = setInterval(pTick, 500); this.textContent = 'Tạm dừng'; }
    else { clearInterval(pm.t); this.textContent = 'Tiếp tục'; }
    pShow();
  };
  $('pomoReset').onclick = function () { clearInterval(pm.t); pm.run = false; pm.brk = false; pm.left = pm.w * 60; $('pomoGo').textContent = 'Bắt đầu'; pShow(); };
  $('pomoModes').onclick = function (e) {
    var b = e.target.closest('[data-m]'); if (!b) return;
    Array.prototype.forEach.call(this.children, function (c) { c.classList.toggle('pri', c === b); });
    var m = b.dataset.m.split(','); pm.w = +m[0]; pm.b = +m[1]; $('pomoReset').click();
  };
  pShow();

  /* ───── Kế hoạch của tôi ───── */
  function drawMine() {
    var h = '<dl>';
    var hh = st.get('v.hinh', null);
    h += '<dt>Hình của tôi</dt><dd>' + (hh && hh.top ? '<span style="display:inline-flex;gap:8px;align-items:center;font-weight:600">' + WS.shapeSvg(hh.top, 28) + WS.SHAPES[hh.top].name + (hh.second ? ' · thứ hai: ' + WS.SHAPES[hh.second].name : '') + '</span><br><span class="hint">Thử ngay: ' + WS.SHAPES[hh.top].tips[0] + '</span>' : '<span class="empty">Chưa chọn</span>') + '</dd>';
    var g = st.get('v.muctieu', null);
    h += '<dt>Mục tiêu SMART</dt><dd>' + (g ? '“' + esc(g.text) + '”<br><span class="hint">Tự kiểm: ' + WS.SMART.map(function (s, i) { return (g.f[i] ? '✓' : '○') + ' ' + s.k; }).join(' · ') + '</span>' : '<span class="empty">Chưa viết</span>') + '</dd>';
    var b = st.get('v.banhxe', null);
    if (b) {
      var lo = b.v.map(function (v, i) { return [v, i]; }).sort(function (x, y) { return x[0] - y[0]; })[0];
      h += '<dt>Bánh xe cuộc đời</dt><dd><div style="max-width:300px;margin:0 auto">' + WS.render.radar(b.v, { size: 320, series: [{ v: b.v, color: '#0F6B55' }] }) + '</div>Nan hoa cần chăm: <b>' + WS.WHEEL[lo[1]] + '</b> (' + lo[0] + '/10)</dd>';
    } else h += '<dt>Bánh xe cuộc đời</dt><dd><span class="empty">Chưa chấm</span></dd>';
    var e = st.get('v.eisen', null);
    if (e) { var ok = WS.EISEN.filter(function (c) { return e.cards[c.id] === c.q; }).length; h += '<dt>Ma trận Eisenhower</dt><dd>' + ok + '/8 thẻ giống gợi ý. Tuần này, đặt ít nhất một việc ô 2 (đọc trước bài, tập thể dục, gặp cố vấn học tập…) vào lịch như một cuộc hẹn.</dd>'; }
    var c = st.get('v.camket', null), fr = st.get('frog', '');
    h += '<dt>Cam kết 7 ngày</dt><dd>' + (c && c.pick != null ? '<b>' + WS.PLEDGES[c.pick] + '</b>' : '<span class="empty">Chưa chọn</span>') + '</dd>';
    h += '<dt>🐸 Con ếch ngày mai</dt><dd>' + (fr ? esc(fr) : '<span class="empty">Chưa ghi</span>') + '</dd>';
    h += '<dt>Lịch tuần — 4 bước</dt><dd>1. Việc cố định → 2. Khối tự học vào giờ tỉnh táo → 3. Sức khỏe &amp; con người → 4. Chừa ~20% trống. Rà soát 20 phút tối Chủ nhật.</dd></dl>';
    $('mine').innerHTML = h;
  }

  /* tệp lịch .ics: nhắc cam kết 7 ngày + rà soát tuần 8 tuần */
  function icsDate(d) { return d.getFullYear() + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0') + 'T' + String(d.getHours()).padStart(2, '0') + String(d.getMinutes()).padStart(2, '0') + '00'; }
  function icsEsc(s) { return String(s).replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\n/g, '\\n'); }
  $('icsGo').onclick = function () {
    var c = st.get('v.camket', null);
    var title = c && c.pick != null ? WS.PLEDGES[c.pick] : 'Ghi 3 việc quan trọng nhất cho ngày mai';
    var tm = ($('remindAt').value || '21:30').split(':');
    var start = new Date(); start.setDate(start.getDate() + 1); start.setHours(+tm[0], +tm[1], 0, 0);
    var sun = new Date(); sun.setHours(20, 0, 0, 0); sun.setDate(sun.getDate() + ((7 - sun.getDay()) % 7 || 7));
    var now = new Date().toISOString().replace(/[-:]/g, '').slice(0, 15) + 'Z';
    function ev(uid, dt, mins, rr, sum, desc) {
      var end = new Date(dt.getTime() + mins * 60000);
      return ['BEGIN:VEVENT', 'UID:' + uid + '-' + CLIENT + '@diemdanhsv.com', 'DTSTAMP:' + now, 'DTSTART;TZID=Asia/Ho_Chi_Minh:' + icsDate(dt), 'DTEND;TZID=Asia/Ho_Chi_Minh:' + icsDate(end), 'RRULE:' + rr, 'SUMMARY:' + icsEsc(sum), 'DESCRIPTION:' + icsEsc(desc),
        'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' + icsEsc(sum), 'TRIGGER:PT0M', 'END:VALARM', 'END:VEVENT'].join('\r\n');
    }
    var ics = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//diemdanhsv//Workshop K2026//VI', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
      'BEGIN:VTIMEZONE', 'TZID:Asia/Ho_Chi_Minh', 'BEGIN:STANDARD', 'DTSTART:19700101T000000', 'TZOFFSETFROM:+0700', 'TZOFFSETTO:+0700', 'TZNAME:ICT', 'END:STANDARD', 'END:VTIMEZONE',
      ev('camket', start, 15, 'FREQ=DAILY;COUNT=7', '✅ Cam kết 7 ngày: ' + title, 'Cam kết từ Workshop Quản lý thời gian K2026 (HCMUS). Làm nhỏ, làm đều — lỡ một ngày thì quay lại ngày hôm sau.'),
      ev('rasoat', sun, 20, 'FREQ=WEEKLY;COUNT=8', '🔁 Rà soát tuần (20 phút)', '1. Nhìn lại: 3 việc đã làm được. 2. Gom mọi hạn chót về một chỗ. 3. Nhìn trước 2 tuần tới. 4. Chọn 3 việc quan trọng nhất tuần sau và đặt vào lịch.'),
      'END:VCALENDAR'].join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([ics], { type: 'text/calendar;charset=utf-8' }));
    a.download = 'nhac-workshop-k26.ics'; document.body.appendChild(a); a.click(); a.remove();
  };

  /* ───── hoạt động diễn giả đang mở ───── */
  var stTimer = null;
  function pollState() {
    clearTimeout(stTimer);
    if (document.hidden) return;
    WS.get({ a: 'state' }).then(function (d) {
      var a = d.current || '';
      if (a && (Date.now() / 1000 - d.ts) > 3 * 3600) a = '';
      liveAct = a;
      $('liveName').textContent = NAMES[a] || '';
      $('liveBar').classList.toggle('on', !!a && a !== current);
      drawTabs();
    }).catch(function () {}).then(function () { stTimer = setTimeout(pollState, 10000); });
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { pollState(); if (current === 'hoi') loadQ(); } });

  if (!navigator.onLine) $('offline').classList.add('on');
  show((location.hash || '').slice(1) || 'khoidong');
  pollState();
  flushPending();
})();
