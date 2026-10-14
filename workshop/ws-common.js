/* ws-common.js — dữ liệu dùng chung + gọi API + vẽ kết quả cho Workshop K2026.
 * Một nguồn duy nhất cho: 5 hình khối, 8 thẻ Eisenhower, 8 lĩnh vực bánh xe, 8 cam kết…
 * để slide (index.html), điện thoại sinh viên (sv.html) và cẩm nang (cam-nang.html) luôn khớp nhau. */
(function (global) {
  'use strict';

  var WS = {};
  WS.SESSION = 'k26-1810';
  WS.API = 'api.php';
  WS.JOIN_URL = 'https://diemdanhsv.com/workshop/sv.html';
  WS.JOIN_SHORT = 'diemdanhsv.com/workshop/sv';

  /* ---------- 5 hình khối (Psycho-Geometrics® — S. Dellinger) ---------- */
  WS.SHAPES = {
    vuong: {
      name: 'Hình Vuông', color: '#1F4E79',
      motto: '“Tốt nhất là tự mình làm lấy mọi chuyện.”',
      core: 'Ngăn nắp, kỹ từng chi tiết, đúng giờ, thích thời khóa biểu và số liệu cụ thể. Đã hứa là làm bằng được.',
      strong: 'Giữ lịch đều đặn, ít khi trễ hạn, ghi chép cẩn thận.',
      trap: 'Cầu toàn — sửa mãi một bài; chậm quyết định; “sốc” khi lịch bị đảo lộn.',
      tips: ['Đặt hộp thời gian (time-box) cho mỗi việc: hết giờ là nộp bản “đủ tốt”.', 'Chừa 20% lịch làm vùng đệm cho việc phát sinh.', 'Hỏi: “Việc này cần 100% hay 80% là đủ?”']
    },
    tamgiac: {
      name: 'Tam Giác', color: '#D2492A',
      motto: '“Đừng bao giờ để người khác thấy bạn đang lo sợ.”',
      core: 'Hướng mục tiêu, quyết đoán, tập trung cao, thích dẫn dắt và thích thắng.',
      strong: 'Biết việc nào quan trọng, ra quyết định nhanh, kéo nhóm về đích.',
      trap: 'Ôm quá nhiều mục tiêu, quên nghỉ, dễ kiệt sức; quyết nhanh mà chưa nghe đủ ý nhóm.',
      tips: ['Ghi giờ ngủ và giờ nghỉ vào lịch như một nhiệm vụ.', 'Mỗi học kỳ chỉ chọn tối đa 3 mục tiêu lớn.', 'Trước khi chốt việc nhóm: hỏi ý kiến từng người một lượt.']
    },
    chunhat: {
      name: 'Chữ Nhật', color: '#B7811A',
      motto: '“Mình đang trong giai đoạn thay đổi.”',
      core: 'Không phải một tính cách cố định mà là giai đoạn chuyển tiếp: đang khám phá, nhiều câu hỏi, học hỏi nhanh — nhưng dễ bối rối, hay quên.',
      strong: 'Cởi mở, dám thử điều mới, học được rất nhiều trong giai đoạn này.',
      trap: 'Kế hoạch lập ra rồi bỏ dở; quên hạn chót; dễ bị cuốn theo người khác.',
      tips: ['Một nơi duy nhất ghi mọi hạn chót (lịch điện thoại hoặc sổ).', 'Chia việc thành bước 30 phút, mỗi ngày làm xong ít nhất một bước.', 'Tìm một người đồng hành: bạn học cố định, anh chị khóa trên, cố vấn học tập.']
    },
    tron: {
      name: 'Hình Tròn', color: '#0F6B55',
      motto: '“Gian nan mới biết bạn hiền.”',
      core: 'Quan tâm con người, biết lắng nghe, là chất keo của nhóm; ghét mâu thuẫn.',
      strong: 'Xây nhóm học tốt, giữ không khí, giúp bạn bè vượt khó.',
      trap: 'Khó nói “không” — ôm việc của người khác, lịch của mình bị lấp đầy bởi lời nhờ vả.',
      tips: ['Câu từ chối mềm: “Mình đang làm X tới 17h, sau đó mình phụ bạn nhé.”', 'Giữ mỗi ngày một khối giờ “của riêng mình”, không nhận lời trong giờ đó.', 'Nhớ: mỗi lần nói “có” với việc ô 3 là nói “không” với việc ô 2 của mình.']
    },
    luonsong: {
      name: 'Lượn Sóng', color: '#6B4A9E',
      motto: '“Cuộc sống ngắn ngủi lắm, hãy tận hưởng niềm vui đi bạn.”',
      core: 'Sáng tạo, nhiều ý tưởng, nghĩ bằng trực giác, ghét khuôn khổ và việc lặp lại.',
      strong: 'Bùng nổ ý tưởng, làm rất nhanh khi có hứng, truyền cảm hứng cho nhóm.',
      trap: '“Vui làm buồn nghỉ”, nước đến chân mới nhảy, bỏ dở khi hết hứng thú.',
      tips: ['Chia dự án thành chặng ngắn, mỗi chặng có hạn chót riêng.', 'Biến việc chán thành trò chơi: đua với đồng hồ Pomodoro, tự thưởng khi xong.', 'Làm cặp với một bạn Vuông/Tam Giác để giữ nhịp.']
    }
  };
  WS.SHAPE_ORDER = ['vuong', 'tamgiac', 'chunhat', 'tron', 'luonsong'];

  WS.shapeSvg = function (key, size, opts) {
    var c = (WS.SHAPES[key] || {}).color || '#10212B';
    var s = size || 48;
    var sw = (opts && opts.stroke) || 7;
    var fill = (opts && opts.fill) ? c : 'none';
    var inner = {
      vuong: '<rect x="14" y="14" width="72" height="72" rx="3"/>',
      tamgiac: '<path d="M50 12 L90 86 L10 86 Z" stroke-linejoin="round"/>',
      chunhat: '<rect x="8" y="28" width="84" height="46" rx="3"/>',
      tron: '<circle cx="50" cy="50" r="38"/>',
      luonsong: '<path d="M8 50 C 20 22, 34 22, 46 50 S 72 78, 92 50" stroke-linecap="round"/>'
    }[key] || '';
    if (key === 'luonsong') fill = 'none';
    return '<svg class="ws-shape" viewBox="0 0 100 100" width="' + s + '" height="' + s + '" fill="' + fill + '" stroke="' + c + '" stroke-width="' + sw + '" aria-hidden="true">' + inner + '</svg>';
  };

  /* ---------- Hoạt động khởi động ---------- */
  WS.KHOIDONG = {
    cam: { q: 'Mấy tuần đầu đại học, thời gian của bạn đang…', opts: ['Dư dả', 'Vừa đủ', 'Hơi đuối', 'Ngập đầu'] },
    man: { q: 'Hôm qua bạn dùng điện thoại bao lâu? (mở “Thời gian sử dụng” / “Digital Wellbeing” để xem số thật)', opts: ['Dưới 2 giờ', '2–4 giờ', '4–6 giờ', '6–8 giờ', 'Trên 8 giờ'] }
  };

  /* ---------- 8 thẻ Eisenhower ---------- */
  WS.QUADS = [
    { n: 1, short: 'Làm ngay', name: 'LÀM NGAY', sub: 'Quan trọng · Khẩn cấp', color: '#D2492A' },
    { n: 2, short: 'Lên lịch', name: 'LÊN LỊCH', sub: 'Quan trọng · Không khẩn cấp', color: '#0F6B55' },
    { n: 3, short: 'Giảm/nhờ', name: 'GIẢM / NHỜ / TỪ CHỐI', sub: 'Không quan trọng · Khẩn cấp', color: '#B7811A' },
    { n: 4, short: 'Bỏ bớt', name: 'BỎ / HẠN CHẾ', sub: 'Không quan trọng · Không khẩn cấp', color: '#5B6B7C' }
  ];
  WS.EISEN = [
    { id: 'c1', icon: '⏰', text: 'Bài Nhập môn lập trình nộp trên Moodle trước 23:59 tối nay', q: 1, why: 'Có hạn chót ngay hôm nay và ảnh hưởng điểm.' },
    { id: 'c2', icon: '📘', text: 'Đọc trước chương 2 Giải tích cho buổi học thứ Năm', q: 2, why: 'Quan trọng nhưng còn thời gian — đây là việc ô 2 điển hình. Bỏ qua nó thì vài tuần sau nó thành ô 1.' },
    { id: 'c3', icon: '📦', text: 'Bạn cùng phòng nhờ chạy ra cổng lấy hộ đơn hàng ngay bây giờ', q: 3, why: 'Gấp — nhưng là gấp của người khác. Hẹn lại giờ khác hoặc từ chối mềm.' },
    { id: 'c4', icon: '📱', text: 'Lướt Reels/TikTok “thêm 5 phút” trước khi ngủ', q: 4, why: 'Không gấp, không quan trọng, và hiếm khi chỉ 5 phút.' },
    { id: 'c5', icon: '🏃', text: 'Tập thể dục 30 phút', q: 2, why: 'Không ai giục, nhưng sức khỏe là nền của mọi kế hoạch.' },
    { id: 'c6', icon: '💬', text: 'Group chat lớp báo 40 tin nhắn mới, phần lớn là chuyện phiếm', q: 3, why: 'Cảm giác rất gấp. Nhưng nếu trong đó có thông báo đổi phòng thi? → Đọc theo khung giờ cố định, ghim tin của lớp trưởng.' },
    { id: 'c7', icon: '😰', text: 'Mai thi giữa kỳ Vật lý mà chưa ôn chương 3', q: 1, why: 'Giờ thì phải làm ngay. Câu hỏi đáng giá hơn: vì sao việc này rơi vào ô 1?' },
    { id: 'c8', icon: '🧭', text: 'Gặp cố vấn học tập hỏi về lộ trình chọn chuyên ngành', q: 2, why: 'Không gấp hôm nay — nhưng quyết định 4 năm học của bạn.' }
  ];

  /* ---------- Bánh xe cuộc đời ---------- */
  WS.WHEEL = ['Học tập', 'Sức khỏe & giấc ngủ', 'Tài chính', 'Gia đình', 'Bạn bè & quan hệ', 'Phát triển bản thân', 'Giải trí & nghỉ ngơi', 'Không gian sống'];

  /* ---------- SMART tự kiểm ---------- */
  WS.SMART = [
    { k: 'S', name: 'Cụ thể', q: 'Nói rõ làm gì, cho môn/việc nào?' },
    { k: 'M', name: 'Đo được', q: 'Có con số để biết mình đạt hay chưa?' },
    { k: 'A', name: 'Khả thi', q: 'Làm được với thời gian và sức hiện có?' },
    { k: 'R', name: 'Phù hợp', q: 'Gắn với điều mình thật sự cần (môn học, ngành, sức khỏe)?' },
    { k: 'T', name: 'Có hạn', q: 'Có ngày hoặc tuần hoàn thành cụ thể?' }
  ];

  /* ---------- Cam kết 7 ngày ---------- */
  WS.PLEDGES = [
    'Ngủ đủ 7 tiếng, giờ đi ngủ cố định',
    'Mỗi tối 5 phút: ghi 3 việc quan trọng nhất cho ngày mai',
    '“Ăn con ếch”: làm việc khó nhất trước 10 giờ sáng',
    'Tắt thông báo mạng xã hội khi ngồi học',
    'Mỗi ngày 2 phiên Pomodoro cho môn khó nhất',
    'Rà soát tuần 20 phút vào tối Chủ nhật',
    'Hoàn thành bài trước hạn chót 24 giờ',
    'Nói “không” với một lời mời lệch mục tiêu'
  ];

  /* ---------- Thiết bị ẩn danh ---------- */
  WS.store = {
    get: function (k, d) { try { var v = localStorage.getItem('ws26.' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem('ws26.' + k, JSON.stringify(v)); } catch (e) {} }
  };
  WS.clientId = function () {
    var c = WS.store.get('client', null);
    if (!c || !/^[A-Za-z0-9]{8,32}$/.test(c)) {
      var a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789', s = '';
      var r = (global.crypto && crypto.getRandomValues) ? crypto.getRandomValues(new Uint8Array(20)) : null;
      for (var i = 0; i < 20; i++) s += a[(r ? r[i] : Math.floor(Math.random() * 256)) % a.length];
      c = s; WS.store.set('client', c);
    }
    return c;
  };

  /* ---------- API ---------- */
  function unwrap(r) {
    return r.json().then(function (j) {
      if (!j || j.status !== 'success') throw new Error((j && j.message) || 'Lỗi máy chủ');
      return j.data;
    });
  }
  WS.get = function (params) {
    var q = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
    return fetch(WS.API + '?s=' + WS.SESSION + '&' + q, { cache: 'no-store' }).then(unwrap);
  };
  WS.post = function (body) {
    body.s = WS.SESSION;
    return fetch(WS.API, { method: 'POST', headers: { 'Content-Type': 'text/plain;charset=utf-8' }, body: JSON.stringify(body) }).then(unwrap);
  };

  WS.esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]; });
  };
  function pct(a, n) { return n ? Math.round(a * 100 / n) : 0; }
  WS.pct = pct;

  /* ---------- Vẽ kết quả (dùng cho cả màn hình hội trường và điện thoại) ---------- */
  WS.render = {};

  WS.render.bars = function (labels, counts, opts) {
    opts = opts || {};
    var n = counts.reduce(function (a, b) { return a + b; }, 0);
    var max = Math.max.apply(null, counts.concat([1]));
    return '<div class="ws-bars">' + labels.map(function (l, i) {
      var c = counts[i] || 0;
      var col = (opts.colors && opts.colors[i]) || 'var(--luc)';
      return '<div class="ws-bar' + (opts.highlight === i ? ' is-hi' : '') + '"><div class="ws-bar-l">' + l + '</div>' +
        '<div class="ws-bar-t"><div class="ws-bar-f" style="width:' + (c * 100 / max) + '%;background:' + col + '"></div></div>' +
        '<div class="ws-bar-v"><b>' + pct(c, n) + '%</b><span>' + c + '</span></div></div>';
    }).join('') + '</div>';
  };

  WS.render.khoidong = function (d) {
    return '<div class="ws-two">' +
      '<div><h4 class="ws-h4">' + WS.KHOIDONG.cam.q + '</h4>' + WS.render.bars(WS.KHOIDONG.cam.opts, d.cam, { colors: ['#46A385', '#0F6B55', '#D6A130', '#D2492A'] }) + '</div>' +
      '<div><h4 class="ws-h4">Thời gian dùng điện thoại hôm qua</h4>' + WS.render.bars(WS.KHOIDONG.man.opts, d.man, { colors: ['#8FE0BD', '#46A385', '#D6A130', '#D2492A', '#8E2A15'] }) + '</div></div>';
  };

  WS.render.hinh = function (d) {
    var n = d.n || 0;
    var max = Math.max.apply(null, WS.SHAPE_ORDER.map(function (k) { return d.top[k] || 0; }).concat([1]));
    return '<div class="ws-shapecols">' + WS.SHAPE_ORDER.map(function (k) {
      var c = d.top[k] || 0, h = Math.round(c * 100 / max);
      return '<div class="ws-shapecol"><div class="ws-shapecol-v"><b>' + pct(c, n) + '%</b> <span>(' + c + ')</span></div>' +
        '<div class="ws-shapecol-t"><div class="ws-shapecol-f" style="height:' + h + '%;background:' + WS.SHAPES[k].color + '"></div></div>' +
        '<div class="ws-shapecol-i">' + WS.shapeSvg(k, 44) + '</div><div class="ws-shapecol-n">' + WS.SHAPES[k].name + '</div></div>';
    }).join('') + '</div>';
  };

  WS.render.eisen = function (d) {
    var cols = WS.QUADS.map(function (q) { return q.color; });
    return '<div class="ws-eisenres">' + WS.EISEN.map(function (c) {
      var arr = (d.cards && d.cards[c.id]) || [0, 0, 0, 0];
      var n = arr.reduce(function (a, b) { return a + b; }, 0);
      var seg = arr.map(function (v, i) {
        var p = pct(v, n);
        return p ? '<span style="width:' + p + '%;background:' + cols[i] + '" title="Ô ' + (i + 1) + ': ' + v + '">' + (p >= 9 ? 'Ô' + (i + 1) + ' ' + p + '%' : '') + '</span>' : '';
      }).join('');
      var agree = pct(arr[c.q - 1], n);
      return '<div class="ws-er"><div class="ws-er-t">' + c.icon + ' ' + WS.esc(c.text) + '</div><div class="ws-er-b">' + (seg || '<em>chưa có phiếu</em>') + '</div>' +
        '<div class="ws-er-a' + (n && agree < 50 ? ' is-split' : '') + '">' + (n ? agree + '% chọn ô ' + c.q : '') + '</div></div>';
    }).join('') + '</div>';
  };

  WS.render.radar = function (vals, opts) {
    opts = opts || {};
    var size = opts.size || 360, cx = size / 2, cy = size / 2, R = size / 2 - Math.max(58, size * 0.21), N = 8;
    function lines(l) {
      if (l.indexOf(' & ') > 0) { var p = l.split(' & '); return [p[0] + ' &', p[1]]; }
      if (l.length <= 11) return [l];
      var w = l.split(' '), best = 1, diff = 99;
      for (var k = 1; k < w.length; k++) { var d = Math.abs(w.slice(0, k).join(' ').length - w.slice(k).join(' ').length); if (d < diff) { diff = d; best = k; } }
      return [w.slice(0, best).join(' '), w.slice(best).join(' ')];
    }
    function pt(i, r) { var a = -Math.PI / 2 + i * 2 * Math.PI / N; return [cx + r * Math.cos(a), cy + r * Math.sin(a)]; }
    var g = '';
    [2, 4, 6, 8, 10].forEach(function (lv) {
      g += '<polygon points="' + WS.WHEEL.map(function (_, i) { return pt(i, R * lv / 10).join(','); }).join(' ') + '" fill="none" stroke="var(--line)" stroke-width="1"/>';
    });
    WS.WHEEL.forEach(function (l, i) {
      var p = pt(i, R), q = pt(i, R + 30);
      g += '<line x1="' + cx + '" y1="' + cy + '" x2="' + p[0] + '" y2="' + p[1] + '" stroke="var(--line)"/>';
      var anchor = Math.abs(q[0] - cx) < 8 ? 'middle' : (q[0] > cx ? 'start' : 'end');
      var words = lines(l);
      g += '<text x="' + q[0] + '" y="' + (q[1] + 4 - (words.length - 1) * 7) + '" text-anchor="' + anchor + '" class="ws-radar-l">' +
        words.map(function (w, j) { return '<tspan x="' + q[0] + '" dy="' + (j ? 15 : 0) + '">' + WS.esc(w) + '</tspan>'; }).join('') + '</text>';
    });
    (opts.series || [{ v: vals, color: 'var(--luc)' }]).forEach(function (s) {
      g += '<polygon points="' + s.v.map(function (v, i) { return pt(i, R * Math.max(0, Math.min(10, v)) / 10).join(','); }).join(' ') + '" fill="' + s.color + '" fill-opacity=".18" stroke="' + s.color + '" stroke-width="3" stroke-linejoin="round"/>';
      s.v.forEach(function (v, i) { var p = pt(i, R * v / 10); g += '<circle cx="' + p[0] + '" cy="' + p[1] + '" r="4.5" fill="' + s.color + '"/>'; });
    });
    return '<svg class="ws-radar" viewBox="0 0 ' + size + ' ' + size + '" role="img" aria-label="Biểu đồ bánh xe cuộc đời">' + g + '</svg>';
  };

  WS.render.banhxe = function (d) {
    var avg = d.avg || [0, 0, 0, 0, 0, 0, 0, 0];
    var order = avg.map(function (v, i) { return [v, i]; }).sort(function (a, b) { return a[0] - b[0]; });
    var lows = order.slice(0, 3).map(function (o) { return '<li><b>' + WS.WHEEL[o[1]] + '</b> — trung bình ' + o[0].toFixed(1) + '/10 · ' + (d.low ? d.low[o[1]] : 0) + ' bạn chấm ≤ 4</li>'; }).join('');
    return '<div class="ws-two ws-two-radar"><div>' + WS.render.radar(avg, { size: 380 }) + '</div><div><h4 class="ws-h4">Ba “nan hoa” thấp nhất của hội trường</h4><ol class="ws-lows">' + (d.n ? lows : '<li>Chưa có dữ liệu</li>') + '</ol></div></div>';
  };

  WS.render.muctieu = function (d) {
    var n = d.n || 0;
    return '<div class="ws-smartres">' + WS.SMART.map(function (s, i) {
      var p = pct((d.f || [])[i] || 0, n);
      return '<div class="ws-sr"><div class="ws-sr-k">' + s.k + '</div><div class="ws-sr-n">' + s.name + '</div><div class="ws-sr-t"><div style="height:' + p + '%"></div></div><div class="ws-sr-p">' + p + '%</div></div>';
    }).join('') + '<div class="ws-sr-full"><b>' + (d.full || 0) + '</b>/' + n + ' mục tiêu tự đánh giá đủ cả 5 tiêu chí</div></div>';
  };

  WS.render.camket = function (d) {
    var c = d.count || [];
    var idx = WS.PLEDGES.map(function (_, i) { return i; }).sort(function (a, b) { return (c[b] || 0) - (c[a] || 0); });
    return WS.render.bars(idx.map(function (i) { return WS.PLEDGES[i]; }), idx.map(function (i) { return c[i] || 0; }), { colors: idx.map(function () { return 'var(--luc)'; }), highlight: 0 });
  };

  WS.render.danhgia = function (d) {
    var s = d.sao || [0, 0, 0, 0, 0], n = d.n || 0;
    var avg = n ? (s.reduce(function (a, v, i) { return a + v * (i + 1); }, 0) / n) : 0;
    return '<div class="ws-two"><div class="ws-bignum"><b>' + avg.toFixed(1) + '</b><span>/ 5 sao · ' + n + ' phiếu</span></div><div>' +
      WS.render.bars(['★', '★★', '★★★', '★★★★', '★★★★★'], s, { colors: ['#D2492A', '#D6A130', '#D6A130', '#46A385', '#0F6B55'] }) + '</div></div>';
  };

  WS.render.nc0 = WS.render.nc1 = function (d) {
    return '<div class="ws-bignum" style="padding:40px 0"><b>' + d.n + '</b><span>phiếu khảo sát đã gửi · ẩn danh</span></div>';
  };

  /* ---------- Dữ liệu minh họa (chỉ khi diễn giả bật chế độ DEMO — luôn ghi rõ trên màn hình) ---------- */
  WS.demo = function (act) {
    function r(a, b) { return a + Math.floor(Math.random() * (b - a + 1)); }
    switch (act) {
      case 'khoidong': return { n: 120, cam: [8, 41, 52, 19], man: [6, 28, 44, 27, 15] };
      case 'hinh': return { n: 120, top: { vuong: 22, tamgiac: 17, chunhat: 38, tron: 27, luonsong: 16 }, second: {} };
      case 'eisen': return { n: 96, cards: { c1: [88, 4, 3, 1], c2: [9, 80, 2, 5], c3: [21, 2, 61, 12], c4: [1, 3, 9, 83], c5: [2, 71, 3, 20], c6: [6, 4, 47, 39], c7: [90, 4, 1, 1], c8: [5, 82, 6, 3] } };
      case 'banhxe': return { n: 110, avg: [6.1, 5.2, 5.6, 7.4, 6.6, 5.9, 6.3, 6.8], low: [18, 37, 30, 9, 15, 24, 19, 14] };
      case 'muctieu': return { n: 84, f: [70, 49, 66, 72, 41], full: 33 };
      case 'camket': return { n: 102, count: [21, 14, 12, 19, 9, 8, 11, 8] };
      case 'danhgia': return { n: 90, sao: [1, 2, 9, 34, 44] };
      case 'nc0': return { n: 87 };
      case 'nc1': return { n: 74 };
    }
    return { n: r(0, 0) };
  };

  global.WS = WS;
})(window);
