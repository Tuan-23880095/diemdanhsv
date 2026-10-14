/* ws-survey.js — vẽ và gửi phiếu khảo sát nghiên cứu (nc0–nc3). Dùng chung cho sv.html và theo-doi.html.
 * WSS.mount(boxEl, wave, opts) — wave: 'nc0' | 'nc1' | 'nc2' | 'nc3'. */
(function (global) {
  'use strict';
  var WS = global.WS, R = global.WSR, esc = WS.esc, st = WS.store;
  var S = {};

  S.sgic = function () { return st.get('nc.sg', ''); };
  S.consented = function () { return !!st.get('nc.ok', 0); };
  S.declined = function () { return !!st.get('nc.no', 0); };
  S.done = function (w) { return !!st.get('nc.done.' + w, 0); };

  function likert(name, items, scale, vals, opts) {
    opts = opts || {};
    return '<div class="sv-likert" data-g="' + name + '">' + items.map(function (it, i) {
      var q = typeof it === 'string' ? it : it.q;
      var base = opts.base == null ? 1 : opts.base;
      return '<div class="sv-li" data-i="' + i + '"><p class="sv-q"><span class="sv-n">' + (i + 1) + '</span>' + esc(q) + '</p><div class="sv-scale" style="--k:' + scale.length + '" role="radiogroup" aria-label="' + esc(q) + '">' +
        scale.map(function (lab, j) {
          var v = j + base, on = vals[i] === v;
          return '<button type="button" role="radio" aria-checked="' + on + '" data-v="' + v + '" class="' + (on ? 'on' : '') + '" title="' + esc(lab) + '"><b>' + (opts.showNum === false ? '' : v) + '</b><span>' + esc(lab) + '</span></button>';
        }).join('') + '</div></div>';
    }).join('') + '</div>';
  }
  function choice(name, item, val) {
    return '<div class="sv-li sv-ch" data-g="' + name + '"><p class="sv-q">' + esc(item.q) + '</p><div class="sv-opts">' +
      item.o.map(function (o, j) { return '<button type="button" role="radio" aria-checked="' + (val === j) + '" data-v="' + j + '" class="' + (val === j ? 'on' : '') + '"><span class="rd"></span>' + esc(o) + '</button>'; }).join('') + '</div></div>';
  }
  function mcq(name, items, vals) {
    return items.map(function (it, i) {
      return '<div class="sv-li sv-ch" data-g="' + name + '" data-i="' + i + '"><p class="sv-q"><span class="sv-n">' + (i + 1) + '</span>' + esc(it.q) + '</p><div class="sv-opts">' +
        it.o.map(function (o, j) { return '<button type="button" role="radio" aria-checked="' + (vals[i] === j) + '" data-v="' + j + '" class="' + (vals[i] === j ? 'on' : '') + '"><span class="rd"></span>' + esc(o) + '</button>'; }).join('') + '</div></div>';
    }).join('');
  }

  function consentBlock(d) {
    var sg = d.sgp || {};
    return '<div class="card sv-consent"><h3>Thông tin nghiên cứu</h3><ul class="sv-cl">' + R.CONSENT_SHORT.map(function (t) { return '<li>' + t + '</li>'; }).join('') + '</ul>' +
      '<p class="hint"><a href="nghien-cuu.html" target="_blank" rel="noopener">Đọc đầy đủ phiếu thông tin nghiên cứu</a></p>' +
      '<label class="sv-chk"><input type="checkbox" data-k="adult"' + (d.adult ? ' checked' : '') + '><span>Tôi đã đủ 18 tuổi.</span></label>' +
      '<label class="sv-chk"><input type="checkbox" data-k="ok"' + (d.ok ? ' checked' : '') + '><span>Tôi đã đọc thông tin trên và <b>đồng ý tham gia</b> nghiên cứu.</span></label></div>' +
      '<div class="card"><h3>Mã ẩn danh của bạn</h3><p class="hint" style="margin-top:0">' + R.SGIC_HELP + '</p>' +
      '<div class="sv-sg"><label>Tên của mẹ<input type="text" data-sg="a" maxlength="20" autocomplete="off" value="' + esc(sg.a || '') + '" placeholder="Lan"></label>' +
      '<label>Ngày sinh của bạn<input type="text" inputmode="numeric" data-sg="b" maxlength="2" value="' + esc(sg.b || '') + '" placeholder="07"></label>' +
      '<label>2 số cuối SĐT<input type="text" inputmode="numeric" data-sg="c" maxlength="12" value="' + esc(sg.c || '') + '" placeholder="39"></label></div>' +
      '<p class="sv-code">Mã của bạn: <b data-code>' + (d.sg || '——————') + '</b></p><p class="hint" style="margin:0">Hệ thống chỉ lưu mã 6 ký tự, không lưu tên mẹ hay số điện thoại. Hãy <b>chụp màn hình mã này</b> — bạn sẽ dùng lại ở khảo sát sau 2 và 6 tuần.</p></div>';
  }

  var DEF = {
    nc0: function (d) {
      return consentBlock(d) +
        '<div class="card"><h3>Phần 1 · Thông tin chung</h3>' + R.DEMO.map(function (it) { return choice('dem.' + it.k, it, (d.dem || {})[it.k]); }).join('') + '</div>' +
        '<div class="card"><h3>Phần 2 · Thói quen sử dụng thời gian</h3><p class="hint" style="margin-top:0">Chọn mức đúng với bạn <b>trong thời gian gần đây</b>. Không có câu trả lời đúng/sai.</p>' + likert('tmq', R.TMQ, R.TMQ_SCALE, d.tmq || []) + '</div>' +
        '<div class="card"><h3>Phần 3 · Bạn tự tin đến mức nào?</h3><p class="hint" style="margin-top:0">Tôi tin mình có thể…</p>' + likert('se', R.SE, R.SE_SCALE, d.se || []) + '</div>' +
        '<div class="card"><h3>Phần 4 · Kiến thức nhanh</h3><p class="hint" style="margin-top:0">Chưa biết thì chọn phương án bạn thấy hợp lý nhất — không tính điểm cá nhân.</p>' + mcq('kt', R.KT, d.kt || []) + '</div>';
    },
    nc1: function (d) {
      return (S.consented() ? '<div class="card"><p style="margin:0">Mã ẩn danh của bạn: <b>' + esc(S.sgic()) + '</b> · đã đồng ý tham gia ✓</p></div>' : consentBlock(d)) +
        '<div class="card"><h3>Phần 1 · Kiến thức nhanh</h3><p class="hint" style="margin-top:0">Câu hỏi giống đầu buổi — để đo những gì bạn đã học được.</p>' + mcq('kt', R.KT, d.kt || []) + '</div>' +
        '<div class="card"><h3>Phần 2 · Bây giờ bạn tự tin đến mức nào?</h3><p class="hint" style="margin-top:0">Tôi tin mình có thể…</p>' + likert('se', R.SE, R.SE_SCALE, d.se || []) + '</div>' +
        '<div class="card"><h3>Phần 3 · Nhận xét về workshop</h3>' + likert('re', R.RE, R.AGREE, d.re || []) + '</div>' +
        '<div class="card"><h3>Phần 4 · Dự định của bạn</h3>' + likert('yd', R.YD, R.AGREE, d.yd || []) + '</div>';
    },
    nc2: function (d) { return follow(d, 2); },
    nc3: function (d) { return follow(d, 3); }
  };
  function follow(d, dot) {
    var h = consentBlock(d) +
      '<div class="card">' + choice('thamdu', { q: 'Bạn có tham dự workshop “Làm chủ thời gian” ngày 18/10/2026 không?', o: ['Không tham dự', 'Có tham dự'] }, d.thamdu) + '</div>' +
      '<div class="card"><h3>' + (dot === 2 ? 'Trong 2 tuần qua' : 'Trong 2 tuần gần đây') + ', bạn đã dùng các cách sau bao nhiêu lần?</h3>' + likert('ad', R.AD, R.AD_SCALE, d.ad || [], { base: 0, showNum: false }) + '</div>';
    if (dot === 2) {
      h += '<div class="card">' + choice('ck', { q: 'Trong 7 ngày đầu sau workshop, bạn thực hiện cam kết 7 ngày được bao nhiêu ngày?', o: R.CK }, d.ck) + '</div>';
    } else {
      h += '<div class="card"><h3>Thói quen sử dụng thời gian</h3><p class="hint" style="margin-top:0">Chọn mức đúng với bạn <b>trong thời gian gần đây</b>.</p>' + likert('tmq', R.TMQ, R.TMQ_SCALE, d.tmq || []) + '</div>' +
        '<div class="card"><h3>Bạn tự tin đến mức nào?</h3><p class="hint" style="margin-top:0">Tôi tin mình có thể…</p>' + likert('se', R.SE, R.SE_SCALE, d.se || []) + '</div>' +
        '<div class="card">' + choice('thaydoi', { q: 'So với đầu học kỳ, việc quản lý thời gian của bạn hiện nay:', o: R.THAYDOI }, d.thaydoi == null ? null : d.thaydoi - 1) + '</div>';
    }
    h += '<div class="card"><h3>Điều gì cản trở bạn vận dụng? <span class="hint">(chọn nhiều)</span></h3><div class="sv-opts sv-multi" data-g="tr">' +
      R.TR.map(function (t, j) { var on = (d.tr || []).indexOf(j) >= 0; return '<button type="button" role="checkbox" aria-checked="' + on + '" data-v="' + j + '" class="' + (on ? 'on' : '') + '"><span class="rd sq"></span>' + esc(t) + '</button>'; }).join('') + '</div></div>' +
      '<div class="card"><label class="sv-q" for="svTxt">Kể một thay đổi cụ thể (hoặc một khó khăn) trong cách bạn dùng thời gian <span class="hint">(không bắt buộc)</span></label><textarea id="svTxt" data-k="txt" maxlength="400">' + esc(d.txt || '') + '</textarea></div>';
    return h;
  }

  S.mount = function (box, wave, opts) {
    opts = opts || {};
    var key = 'nc.draft.' + wave;
    var d = st.get(key, null) || {};
    if (S.consented()) { d.ok = 1; d.adult = 1; d.sg = d.sg || S.sgic(); }
    function save() { st.set(key, d); }

    function render() {
      if (S.done(wave) && !opts.allowRedo) {
        box.innerHTML = thanks();
        bindRedo();
        return;
      }
      box.innerHTML = '<div class="sv-intro card"><p style="margin:0"><b>' + R.WAVES[wave].name + '</b> · khoảng ' + R.WAVES[wave].mins + ' phút · ẩn danh, tự nguyện.</p>' +
        (wave === 'nc0' || wave === 'nc1' ? '<button type="button" class="btn sm" data-decline style="margin-top:10px">Tôi không tham gia khảo sát</button>' : '') + '</div>' +
        DEF[wave](d) + '<div class="sv-prog"><div></div></div><button type="button" class="btn pri wide" data-send>Gửi phiếu</button><div class="status" data-st></div>';
      bind();
      prog();
    }
    function thanks() {
      var h = '<div class="card sv-thanks"><h3>✓ Cảm ơn bạn đã hoàn thành ' + R.WAVES[wave].name.toLowerCase() + '!</h3><p>Mã ẩn danh của bạn: <b style="font-size:1.3em;letter-spacing:.08em">' + esc(S.sgic() || d.sg || '') + '</b></p>';
      if (wave === 'nc0') h += '<p style="margin:0">Hẹn bạn ở khảo sát cuối buổi (3 phút). Giờ thì cùng vào hoạt động đầu tiên nhé!</p>';
      if (wave === 'nc1') h += '<p>Hai khảo sát ngắn tiếp theo: <b>' + R.WAVES.nc2.when + '</b> và <b>' + R.WAVES.nc3.when + '</b>. Vào “Kế hoạch của tôi” để tải lịch nhắc — đường dẫn khảo sát có sẵn trong lịch.</p>' + ktFeedback();
      if (wave === 'nc2' || wave === 'nc3') h += '<p style="margin:0">Dữ liệu của bạn giúp nhóm nghiên cứu hiểu tân sinh viên vận dụng kỹ năng thế nào sau khóa học.</p>';
      return h + '<button type="button" class="btn sm" data-redo style="margin-top:12px">Sửa câu trả lời</button></div>';
    }
    function ktFeedback() {
      var kt = (st.get('nc.draft.nc1', {}) || {}).kt || [];
      if (!kt.length) return '';
      var ok = R.KT.filter(function (q, i) { return kt[i] === q.a; }).length;
      return '<div class="lb" style="margin-top:12px">Kiến thức: ' + ok + '/' + R.KT.length + ' câu đúng</div><ul class="tips">' + R.KT.map(function (q, i) {
        return kt[i] === q.a ? '' : '<li>' + esc(q.q) + ' → <b>' + esc(q.o[q.a]) + '</b></li>';
      }).join('') + '</ul>';
    }
    function bindRedo() { var b = box.querySelector('[data-redo]'); if (b) b.onclick = function () { st.set('nc.done.' + wave, 0); render(); }; }

    function setPath(path, v) { var p = path.split('.'); if (p.length === 2) { d[p[0]] = d[p[0]] || {}; d[p[0]][p[1]] = v; } else d[path] = v; }
    function bind() {
      box.querySelectorAll('.sv-likert').forEach(function (g) {
        g.addEventListener('click', function (e) {
          var b = e.target.closest('button[data-v]'); if (!b) return;
          var li = b.closest('.sv-li'), i = +li.dataset.i, name = g.dataset.g;
          d[name] = d[name] || []; d[name][i] = +b.dataset.v;
          li.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); x.setAttribute('aria-checked', x === b); });
          li.classList.remove('miss'); save(); prog();
        });
      });
      box.querySelectorAll('.sv-ch').forEach(function (li) {
        li.addEventListener('click', function (e) {
          var b = e.target.closest('button[data-v]'); if (!b) return;
          var name = li.dataset.g, v = +b.dataset.v;
          if (li.dataset.i != null) { d[name] = d[name] || []; d[name][+li.dataset.i] = v; }
          else if (name === 'thaydoi') d.thaydoi = v + 1;
          else setPath(name, v);
          li.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); x.setAttribute('aria-checked', x === b); });
          li.classList.remove('miss'); save(); prog();
        });
      });
      var multi = box.querySelector('.sv-multi');
      if (multi) multi.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-v]'); if (!b) return;
        var v = +b.dataset.v; d.tr = d.tr || [];
        var k = d.tr.indexOf(v); if (k >= 0) d.tr.splice(k, 1); else d.tr.push(v);
        b.classList.toggle('on', k < 0); b.setAttribute('aria-checked', k < 0); save();
      });
      box.querySelectorAll('input[type=checkbox][data-k]').forEach(function (c) { c.onchange = function () { d[c.dataset.k] = c.checked ? 1 : 0; save(); prog(); }; });
      var sgIn = box.querySelectorAll('input[data-sg]');
      sgIn.forEach(function (inp) {
        inp.oninput = function () {
          d.sgp = d.sgp || {}; d.sgp[inp.dataset.sg] = inp.value;
          d.sg = R.normSgic(d.sgp.a, d.sgp.b, d.sgp.c);
          var c = box.querySelector('[data-code]'); if (c) c.textContent = d.sg || '——————';
          save(); prog();
        };
      });
      var tx = box.querySelector('textarea[data-k=txt]'); if (tx) tx.oninput = function () { d.txt = tx.value; save(); };
      var dc = box.querySelector('[data-decline]');
      if (dc) dc.onclick = function () { st.set('nc.no', 1); if (opts.onDecline) opts.onDecline(); };
      box.querySelector('[data-send]').onclick = send;
    }

    function missing() {
      var miss = [];
      if (!d.ok || !d.adult) miss.push(box.querySelector('.sv-consent'));
      if (!d.sg) miss.push(box.querySelector('.sv-sg'));
      box.querySelectorAll('.sv-li').forEach(function (li) {
        var g = li.dataset.g || (li.closest('.sv-likert') || {}).dataset.g;
        var v;
        if (li.closest('.sv-likert')) v = (d[li.closest('.sv-likert').dataset.g] || [])[+li.dataset.i];
        else if (li.dataset.i != null) v = (d[g] || [])[+li.dataset.i];
        else if (g === 'thaydoi') v = d.thaydoi;
        else { var p = g.split('.'); v = p.length === 2 ? (d[p[0]] || {})[p[1]] : d[g]; }
        if (v == null) miss.push(li);
      });
      return miss.filter(Boolean);
    }
    function prog() {
      var all = box.querySelectorAll('.sv-li').length + 2;
      var m = missing().length;
      var bar = box.querySelector('.sv-prog div'); if (bar) bar.style.width = Math.round((all - m) / all * 100) + '%';
    }
    function payload() {
      var p = { ok: d.ok, adult: d.adult, sg: d.sg };
      ({ nc0: ['dem', 'tmq', 'se', 'kt'], nc1: ['kt', 'se', 're', 'yd'], nc2: ['thamdu', 'ad', 'ck', 'tr', 'txt'], nc3: ['thamdu', 'ad', 'tmq', 'se', 'thaydoi', 'tr', 'txt'] })[wave].forEach(function (k) { p[k] = d[k] == null ? (k === 'tr' ? [] : null) : d[k]; });
      return p;
    }
    function send() {
      var stEl = box.querySelector('[data-st]');
      var miss = missing();
      if (!d.ok || !d.adult) { stEl.className = 'status err'; stEl.textContent = 'Phiếu nghiên cứu chỉ nhận khi bạn đủ 18 tuổi và đồng ý tham gia. Nếu không muốn tham gia, bấm “Tôi không tham gia khảo sát”.'; miss[0].scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
      if (miss.length) {
        miss.forEach(function (m) { m.classList.add('miss'); });
        stEl.className = 'status err'; stEl.textContent = 'Còn ' + miss.length + ' mục chưa trả lời (đánh dấu đỏ).';
        miss[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }
      var btn = box.querySelector('[data-send]'); btn.disabled = true;
      stEl.className = 'status'; stEl.textContent = 'Đang gửi…';
      WS.post({ a: 'vote', act: wave, c: WS.clientId(), d: payload() }).then(function () {
        st.set('nc.ok', 1); st.set('nc.sg', d.sg); st.set('nc.no', 0); st.set('nc.done.' + wave, 1);
        render(); box.scrollIntoView({ behavior: 'smooth' });
        if (opts.onDone) opts.onDone();
      }).catch(function (e) {
        btn.disabled = false;
        stEl.className = 'status err';
        stEl.textContent = /fetch|network|Failed/i.test(e.message) ? 'Mạng yếu — câu trả lời vẫn lưu trên máy. Thử gửi lại sau ít phút.' : e.message;
      });
    }
    render();
  };

  S.CSS = '.sv-li{padding:12px 0;border-top:1px solid var(--line)}.sv-li:first-child{border-top:0}.sv-li.miss{background:#FBEFEC;border-radius:10px;padding:12px 10px;outline:2px solid #F0C8BE}' +
    '.sv-q{margin:0 0 10px;font-weight:600;line-height:1.4;display:block}.sv-n{display:inline-block;min-width:1.6em;color:var(--mute);font-weight:700}' +
    '.sv-scale{display:grid;grid-template-columns:repeat(var(--k,5),1fr);gap:5px}.sv-scale button{border:1.5px solid var(--line);background:#fff;border-radius:10px;min-height:52px;padding:6px 2px;cursor:pointer;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;font:600 11px/1.15 var(--sans);color:var(--mute)}' +
    '.sv-scale button b{font-size:16px;color:var(--muc)}.sv-scale button.on{background:var(--luc);border-color:var(--luc);color:#fff}.sv-scale button.on b{color:#fff}' +
    '.sv-opts{display:grid;gap:6px}.sv-opts button{display:flex;gap:10px;align-items:center;text-align:left;border:1.5px solid var(--line);background:#fff;border-radius:12px;padding:10px 12px;min-height:46px;cursor:pointer;font:15px var(--sans);color:var(--muc)}' +
    '.sv-opts button.on{border-color:var(--luc);background:#E8F4EE;font-weight:600}.sv-opts .rd{width:18px;height:18px;border-radius:50%;border:2px solid var(--bac);flex:none}.sv-opts .rd.sq{border-radius:4px}.sv-opts button.on .rd{border:6px solid var(--luc)}' +
    '.sv-cl{margin:0 0 8px;padding-left:20px;font-size:14.5px}.sv-cl li{margin-bottom:6px}.sv-chk{display:flex;gap:10px;align-items:flex-start;padding:8px 0;font-size:15px;cursor:pointer}.sv-chk input{width:22px;height:22px;flex:none;accent-color:var(--luc);margin-top:1px}' +
    '.sv-consent.miss,.sv-sg.miss{outline:2px solid #F0C8BE;border-radius:12px}.sv-sg{display:grid;grid-template-columns:1.4fr 1fr 1fr;gap:8px}.sv-sg label{font-size:13px;font-weight:600;color:var(--mute);display:grid;gap:4px}.sv-sg input{padding:10px;font-size:16px}' +
    '.sv-code{margin:12px 0 6px;font-size:16px}.sv-code b{font:700 24px var(--sans);letter-spacing:.12em;color:var(--luc)}.sv-prog{height:6px;background:var(--bac);border-radius:3px;overflow:hidden;margin:6px 0 12px}.sv-prog div{height:100%;background:var(--luc);transition:width .3s}' +
    '.sv-thanks{border-top:6px solid var(--luc)}@media(max-width:380px){.sv-scale button span{font-size:10px}}';

  global.WSS = S;
})(window);
