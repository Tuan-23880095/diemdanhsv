/* deck.js — slide trình chiếu Workshop K2026 + kết quả trực tiếp + bảng diễn giả. */
(function () {
  'use strict';
  var WS = window.WS, esc = WS.esc;
  var LOGO_HCMUS = 'https://firebasestorage.googleapis.com/v0/b/link-anh-web.firebasestorage.app/o/Logo-chinh-hcmus.png?alt=media&token=9cf3a1de-75f1-493b-8711-fd22c7b98738';
  var SEC = ['Mở đầu', 'Phần 1 · Thiết lập mục tiêu', 'Phần 2 · Ma trận Eisenhower', 'Phần 3 · Kế hoạch cá nhân', 'Phần 4 · Phương pháp quản lý thời gian'];

  function qrSvg(url) {
    try { var q = qrcode(0, 'M'); q.addData(url); q.make(); return q.createSvgTag({ cellSize: 6, margin: 2, scalable: true }); }
    catch (e) { return '<p>' + esc(url) + '</p>'; }
  }
  var QR = qrSvg(WS.JOIN_URL);
  var QR_BOOK = qrSvg('https://diemdanhsv.com/workshop/cam-nang.html');

  function joinBox(label) {
    return '<div class="join"><div class="qr">' + QR + '</div><b>' + (label || 'Quét để tham gia') + '</b><code>' + WS.JOIN_SHORT + '</code></div>';
  }
  function act(id, steps) {
    return '<div class="act-wrap"><div class="live" data-live="' + id + '">' +
      '<div class="live-head"><span class="dot"></span><span class="st">Kết quả trực tiếp</span><span class="demo-tag">MINH HỌA</span><span class="count">0 phiếu</span></div>' +
      '<div class="live-body"><p class="muted">Đang chờ phiếu đầu tiên…</p></div></div>' +
      '<div class="act-side">' + joinBox() + '<div class="act-steps">' + steps + '</div></div></div>';
  }

  /* ════════════════════════ NỘI DUNG SLIDE ════════════════════════ */
  var S = [];
  function add(o) { S.push(o); }

  add({ sec: 0, cls: 'cover', bare: true, title: 'Bìa', notes:
    'Trước giờ bắt đầu: để slide này trên màn hình, mời sinh viên quét QR (không cần đăng nhập, hoàn toàn ẩn danh).\n' +
    'Kỹ thuật (Tuấn): mở bảng Diễn giả (phím H) → đăng nhập PIN → xóa dữ liệu chạy thử → bật "Tự báo cho điện thoại SV".\n' +
    'Gợi ý mở lời (1 phút): "Bạn đã ở HCMUS được vài tuần. Hôm nay mình không nói về chuyện cố gắng hơn, mà về cách làm ít căng thẳng hơn mà vẫn đi đúng hướng."',
    html: '<div style="display:grid;grid-template-columns:1fr 330px;gap:70px;align-items:center;flex:1">' +
      '<div><div class="logos"><img class="hcmus" src="' + LOGO_HCMUS + '" alt="Trường ĐH Khoa học Tự nhiên, ĐHQG-HCM"><span class="sep dqt-sen"></span><img class="sen dqt-sen" src="assets/logo-sen.svg" alt="Logo hoa sen đọng sương — ThS. Đinh Quốc Tuấn"></div>' +
      '<p class="s-kicker sec3" style="--sec:var(--luc)">Workshop Tân sinh viên Khóa 2026</p>' +
      '<h1>Làm chủ <em>thời gian</em>,<br>tự vẽ kế hoạch của mình</h1>' +
      '<p class="sub">Thiết lập mục tiêu · Ma trận Eisenhower · Kế hoạch cá nhân · Phương pháp quản lý thời gian</p>' +
      '<div class="people"><div class="person"><span class="av" style="background:var(--luc)">KL</span><div><b>ThS. Đinh Thị Kim Liên</b><span>Diễn giả</span></div></div>' +
      '<div class="person"><span class="av" style="background:var(--lam)">QT</span><div><b>ThS. Đinh Quốc Tuấn</b><span>Trợ giảng · Khoa Địa chất</span></div></div></div>' +
      '<p class="cover-when">Chủ nhật, 18/10/2026 · Cơ sở Linh Trung — Trường ĐH Khoa học Tự nhiên, ĐHQG-HCM</p></div>' +
      '<div>' + joinBox('Mở điện thoại, quét mã để tham gia') + '<p class="muted small" style="text-align:center;margin-top:14px">Ẩn danh · không cần đăng nhập</p></div></div>'
  });

  add({ sec: 0, kicker: 'Mở đầu', title: '90 phút của chúng ta', notes:
    'Giới thiệu nhanh lộ trình và "3 thứ mang về". Nhấn mạnh: buổi này làm nhiều hơn nghe — điện thoại là công cụ học, không cần cất đi.\n' +
    'Thời lượng ghi bên phải là gợi ý; nếu trễ, rút bớt slide công cụ ở Phần 4 (đã có trong Cẩm nang).',
    html: '<div class="g2" style="grid-template-columns:1.25fr 1fr;gap:56px;flex:1">' +
      '<ol class="steps">' +
      '<li><div><b>Khảo sát đầu buổi · Khởi động</b><span class="muted small">12 phút · thời gian của bạn đang đi đâu?</span></div></li>' +
      '<li><div><b>Bạn là hình gì? — hiểu phong cách của mình</b><span class="muted small">10 phút · Tâm lý – Hình học</span></div></li>' +
      '<li><div><b>Phần 1 · Thiết lập mục tiêu</b><span class="muted small">18 phút · viết một mục tiêu SMART</span></div></li>' +
      '<li><div><b>Phần 2 · Ma trận Eisenhower</b><span class="muted small">15 phút · xếp 8 việc vào 4 ô</span></div></li>' +
      '<li><div><b>Phần 3 · Xây dựng kế hoạch cá nhân</b><span class="muted small">13 phút · bánh xe cuộc đời, lịch tuần</span></div></li>' +
      '<li><div><b>Phần 4 · Phương pháp quản lý thời gian</b><span class="muted small">10 phút · Pomodoro, GTD, ăn con ếch</span></div></li>' +
      '<li><div><b>Cam kết 7 ngày · Khảo sát cuối · Hỏi đáp</b><span class="muted small">12 phút</span></div></li></ol>' +
      '<div class="card" style="align-self:start;border-top:6px solid var(--luc)"><p class="tag">Bạn mang về</p><ul class="clean" style="margin-top:18px">' +
      '<li>Một <b>mục tiêu SMART</b> cho học kỳ này</li><li>Một <b>lịch tuần mẫu</b> có chỗ cho việc quan trọng</li><li>Một <b>cam kết 7 ngày</b> — nhỏ, cụ thể, làm được</li></ul>' +
      '<p class="meta">Tất cả lưu trên điện thoại của bạn, xem lại ở mục “Kế hoạch của tôi”.</p></div></div>'
  });

  add({ sec: 0, kicker: 'Trước khi bắt đầu · 5 phút', title: 'Giúp chúng tôi hiểu tân sinh viên hơn', act: 'nc0', notes:
    'Đọc to phần đồng ý (khoảng 40 giây): khảo sát phục vụ một nghiên cứu giáo dục; hoàn toàn tự nguyện, ẩn danh; không tham gia không ảnh hưởng gì; chỉ dành cho bạn từ đủ 18 tuổi; có thể rút dữ liệu bằng mã ẩn danh trước 31/12/2026.\n' +
    'Hướng dẫn tạo mã ẩn danh: 2 chữ cái đầu tên của mẹ + ngày sinh của bạn + 2 số cuối số điện thoại (ví dụ LA0739). Nhắc chụp màn hình mã.\n' +
    'Nên mở slide này từ 10–15 phút trước giờ bắt đầu để sinh viên đến sớm làm trước. Bật đồng hồ 5 phút (gõ 5 rồi T).\n' +
    'Lưu ý đạo đức: không nói hoặc ngụ ý rằng tham gia khảo sát được cộng điểm rèn luyện/điểm danh — điều đó làm mất tính tự nguyện.',
    html: act('nc0', '<p><b>Khảo sát đầu buổi</b> — khoảng 5 phút, ẩn danh, tự nguyện, dành cho bạn từ đủ 18 tuổi.</p><p class="small">Tạo mã ẩn danh: 2 chữ cái đầu tên mẹ + ngày sinh + 2 số cuối SĐT. Ví dụ: <b>LA0739</b>.</p>')
  });

  add({ sec: 0, kicker: 'Khởi động', title: 'Thời gian của bạn đang đi đâu?', act: 'khoidong', notes:
    'Mời sinh viên mở Cài đặt → Thời gian sử dụng (iPhone) hoặc Digital Wellbeing (Android) để xem SỐ THẬT của ngày hôm qua, rồi trả lời 2 câu.\n' +
    'Chờ khoảng 2 phút, khi số phiếu ổn định thì bình luận: "Một tuần có 168 giờ. Nếu mỗi ngày 5 giờ điện thoại, đó là 35 giờ — gần bằng một tuần làm việc."\n' +
    'Không phán xét — điện thoại không xấu, chỉ là giờ trôi đi mà mình không chọn.',
    html: act('khoidong', '<p><b>2 câu, 30 giây.</b></p><p>Mở “Thời gian sử dụng” trên điện thoại để trả lời bằng số thật của hôm qua.</p>')
  });

  add({ sec: 0, kicker: 'Khởi động', title: 'Một tuần đại học thực sự “nặng” bao nhiêu?', notes:
    'Số liệu có căn cứ: Thông tư 17/2021/TT-BGDĐT (chuẩn chương trình đào tạo) quy định 1 tín chỉ = 50 giờ học tập định mức, gồm cả giờ lên lớp, tự học, kiểm tra.\n' +
    '18 tín chỉ × 50 giờ = 900 giờ; chia cho khoảng 15 tuần học ≈ 60 giờ/tuần. Thời khóa biểu chỉ hiện phần lên lớp (≥ 15 giờ giảng cho 1 tín chỉ lý thuyết, tức khoảng 18–25 giờ/tuần).\n' +
    'Thông điệp: phần lớn việc học ở đại học KHÔNG nằm trên thời khóa biểu — nó nằm trong lịch do chính bạn sắp xếp.\n' +
    'Thanh 168 giờ bên dưới là ước tính minh họa, không phải số đo.',
    html: '<div class="g3" style="gap:40px;margin-bottom:46px">' +
      '<div class="bigstat sec3"><b>50 giờ</b><span>cho mỗi tín chỉ — gồm lên lớp, tự học, kiểm tra</span></div>' +
      '<div class="bigstat sec2"><b>≈ 60 giờ</b><span>mỗi tuần nếu học 18 tín chỉ (900 giờ ÷ ~15 tuần)</span></div>' +
      '<div class="bigstat sec1"><b>¼–⅓</b><span>là phần thời khóa biểu cho bạn thấy. Phần còn lại do bạn tự xếp.</span></div></div>' +
      '<p style="margin:0 0 12px;font-weight:600">168 giờ mỗi tuần — ai cũng như nhau <span class="muted small">(ước tính minh họa)</span></p>' +
      '<div class="hours" style="height:130px;font-size:20px"><div style="flex:49;background:#5B6B7C">Ngủ<small>7 giờ × 7 = 49 giờ</small></div>' +
      '<div style="flex:22;background:var(--lam)">Lên lớp<small>~22 giờ</small></div>' +
      '<div style="flex:38;background:var(--luc)">Tự học<small>~38 giờ</small></div>' +
      '<div style="flex:21;background:#B7811A">Ăn uống, sinh hoạt<small>~21 giờ</small></div>' +
      '<div style="flex:10;background:var(--tim)">Đi lại<small>~10</small></div>' +
      '<div style="flex:28;background:#fff;color:var(--muc);border:2px dashed var(--line)">Còn lại cho mọi thứ khác<small>~28 giờ</small></div></div>' +
      '<p class="src">Căn cứ: Thông tư 17/2021/TT-BGDĐT, Điều 7 — 1 tín chỉ tương đương 50 giờ học tập định mức; tối thiểu 15 giờ giảng hoặc 30 giờ thực hành.</p>'
  });

  add({ sec: 0, kicker: 'Khởi động', title: 'Từ THPT lên đại học: điều gì đã đổi?', notes:
    'Đọc nhanh từng dòng, hỏi hội trường: "Dòng nào bạn thấy rõ nhất trong mấy tuần qua?" (giơ tay).\n' +
    'Chốt: tự do lớn hơn đi kèm trách nhiệm tự quản lớn hơn. Kỹ năng quản lý thời gian không phải để "chạy nhanh hơn", mà để không ai phải nhắc bạn.',
    html: '<table class="cmp sec3"><thead><tr><th></th><th>Ở THPT</th><th>Ở đại học</th></tr></thead><tbody>' +
      '<tr><td>Lịch</td><td>Trường xếp kín từ sáng đến chiều</td><td>Vài buổi trên lớp; phần lớn thời gian bạn tự xếp</td></tr>' +
      '<tr><td>Người nhắc</td><td>Thầy cô, phụ huynh nhắc từng việc</td><td>Không ai nhắc — hạn chót nằm trên Moodle, email, nhóm lớp</td></tr>' +
      '<tr><td>Cách học</td><td>Học trên lớp là chính</td><td>Tự học là chính: hơn 2 giờ tự học cho mỗi giờ lên lớp</td></tr>' +
      '<tr><td>Đánh giá</td><td>Nhiều bài kiểm tra nhỏ, đều đặn</td><td>Ít cột điểm, mỗi cột nặng: giữa kỳ, cuối kỳ, đồ án</td></tr>' +
      '<tr><td>Lựa chọn</td><td>Ít</td><td>Rất nhiều: CLB, làm thêm, tình nguyện, hai cơ sở, các ngành hẹp</td></tr></tbody></table>' +
      '<p style="margin-top:30px;font:600 30px var(--serif);color:var(--luc)">Tự do lớn hơn = phải tự quản lý nhiều hơn.</p>'
  });

  add({ sec: 0, kicker: 'Hiểu mình trước', title: 'Bạn là hình gì?', act: 'hinh', notes:
    'Cách làm nhanh của TS. Susan Dellinger (Psycho-Geometrics®): đừng suy nghĩ lâu, chọn hình bạn thấy "là mình" nhất, rồi chọn hình thứ hai.\n' +
    'Lưu ý khi dẫn: đây là công cụ tự soi chiếu và khởi động trò chuyện, KHÔNG phải trắc nghiệm tâm lý chuẩn hóa. Mỗi người có cả 5 hình, chỉ khác mức độ. Không dùng để dán nhãn bạn bè.\n' +
    'Tham chiếu: ở 2 lớp Kỹ năng làm việc nhóm & học tập (HK2 2025–26), sinh viên tự chọn khoảng: Vuông ~38%, Tròn ~30%, Tam giác ~16%, Lượn sóng ~10%, Chữ nhật ~6% (ước lượng từ diễn đàn lớp). So sánh với hội trường hôm nay.\n' +
    'Ai muốn làm bản đầy đủ 3 phần × 7 câu: link có trong trang SV sau buổi học.',
    html: act('hinh', '<p><b>Chọn theo cảm giác đầu tiên:</b> hình giống bạn nhất, rồi hình thứ hai.</p><p class="small">Công cụ tự soi chiếu (S. Dellinger), không phải chẩn đoán.</p>')
  });

  add({ sec: 0, kicker: 'Hiểu mình trước', title: 'Mỗi hình có một “bẫy thời gian” riêng', notes:
    'Mời từng nhóm hình đứng lên / giơ tay khi đọc tới hình của mình — tạo không khí.\n' +
    'Nhấn vào Chữ Nhật: theo tài liệu gốc, đây là GIAI ĐOẠN chuyển tiếp chứ không phải tính cách — rất phổ biến ở tân sinh viên. Không có gì sai khi đang là Chữ Nhật.\n' +
    'Câu hỏi cho cặp đôi (1 phút): "Bẫy của hình mình đã xuất hiện lần nào trong tuần qua?"\n' +
    'Phần "Mạnh / Bẫy" dựa trên mô tả trong tài liệu Psycho-Geometrics (bản Việt: Nguyễn Thị Minh Tâm, Unity); phần "Thử ngay" là gợi ý ứng dụng vào quản lý thời gian của workshop.',
    html: '<div class="shapes-tbl">' + WS.SHAPE_ORDER.map(function (k) {
      var s = WS.SHAPES[k];
      return '<div class="shape-card" style="--c:' + s.color + '"><div class="hd">' + WS.shapeSvg(k, 46) + '<h3>' + s.name + '</h3></div>' +
        '<div><div class="lb">Mạnh</div>' + s.strong + '</div><div><div class="lb">Bẫy thời gian</div>' + s.trap + '</div>' +
        '<div class="try"><div class="lb">Thử ngay</div>' + s.tips[0] + '</div></div>';
    }).join('') + '</div>'
  });

  /* ── PHẦN 1 ── */
  add({ sec: 1, divider: 1, title: 'Thiết lập mục tiêu', lead: 'Không có đích đến thì mọi con đường đều có vẻ đúng — và mọi lời rủ rê đều có vẻ hợp lý.', notes:
    'Chuyển phần. Câu hỏi mở: "Ai đã viết ra mục tiêu cho học kỳ này — viết ra giấy hoặc điện thoại, không phải chỉ nghĩ trong đầu?"\n' +
    'K. Paul (2008), "Học khôn ngoan mà không gian nan" (kho KNLVN): mục tiêu viết ra luôn mạnh hơn mục tiêu nằm trong trí nhớ — nó giúp ôn lại, nhắc mình, và có sức nặng như một bản cam kết.' });

  add({ sec: 1, kicker: SEC[1], title: 'Từ ước mơ đến việc tuần này: 4 tầng mục tiêu', notes:
    'Phân tầng theo K. Paul (2008, tr. 170): dài hạn (5–10 năm), trung hạn (3–5 năm), ngắn hạn (1–2 năm), trước mắt (tháng/tuần/hôm nay).\n' +
    'Ví dụ trên slide là của một sinh viên khoa học tự nhiên giả định — mời 1–2 bạn nói tầng "dài hạn" của mình.\n' +
    'Điểm mấu chốt: tầng dưới cùng phải đủ nhỏ để bắt đầu NGAY tuần này. Một mục tiêu nhỏ làm xong trong 30 phút là vũ khí chống trì hoãn tốt nhất.',
    html: '<div style="display:grid;gap:14px;flex:1;align-content:center">' +
      [['Dài hạn · 5–10 năm', 'Trở thành người làm nghiên cứu / kỹ sư trong ngành mình chọn', '#1F4E79', 100],
       ['Trung hạn · cả khóa học', 'Vào được chuyên ngành mong muốn, có một lần thực tập thật', '#2F6DB0', 86],
       ['Ngắn hạn · học kỳ này', 'Qua tất cả các môn, không học lại; GPA học kỳ ≥ 7,0', '#0F6B55', 72],
       ['Trước mắt · tuần này', 'Làm hết bài tập chương 1–2 Giải tích trước tối thứ Sáu', '#46A385', 58]].map(function (r) {
        return '<div style="width:' + r[3] + '%;margin:0 auto;background:' + r[2] + ';color:#fff;border-radius:16px;padding:18px 28px;display:grid;grid-template-columns:300px 1fr;gap:24px;align-items:center"><b style="font-size:22px;letter-spacing:.04em">' + r[0] + '</b><span style="font-size:26px">' + r[1] + '</span></div>';
      }).join('') + '<p class="muted" style="text-align:center;margin-top:10px">Tầng dưới cùng phải đủ nhỏ để bắt đầu <b style="color:var(--muc)">ngay tuần này</b>.</p></div>' +
      '<p class="src">Phân tầng theo: K. Paul (2008), Học khôn ngoan mà không gian nan, tr. 170. NXB Lao động – Xã hội.</p>'
  });

  add({ sec: 1, kicker: SEC[1], title: 'SMART: biến “cố gắng hơn” thành việc làm được', notes:
    'SMART do George T. Doran đề xuất năm 1981. Đi qua ví dụ: câu trái là mong muốn, câu phải là kế hoạch.\n' +
    'Lưu ý chữ A (khả thi): mục tiêu quá cao làm nản, quá thấp làm chán. R: phù hợp với ưu tiên của BẠN — ví dụ ôn môn có bài kiểm tra ngày mai quan trọng hơn bài tập hạn tuần sau.\n' +
    'Hỏi hội trường: "Câu bên phải còn thiếu gì không?"',
    html: '<div class="g2" style="grid-template-columns:1fr 1.35fr;gap:40px;align-items:center;flex:1">' +
      '<div><div class="card" style="background:#FBEFEC;border-color:#F0C8BE;margin-bottom:22px"><p class="tag sec2">Mong muốn</p><p style="font:600 34px/1.3 var(--serif);margin-top:14px">“Em sẽ cố gắng học tốt Giải tích.”</p></div>' +
      '<div class="card" style="background:#E8F4EE;border-color:#BFE0CF"><p class="tag sec3">Mục tiêu SMART</p><p style="font:600 30px/1.35 var(--serif);margin-top:14px">“Trong 6 tuần tới, mỗi tuần em giải 15 bài tập Giải tích 1 và tự chấm vào tối Chủ nhật, để đạt từ 7 điểm giữa kỳ trở lên.”</p></div></div>' +
      '<div style="display:grid;gap:12px">' + WS.SMART.map(function (s, i) {
        var ex = ['giải bài tập Giải tích 1', '15 bài/tuần · từ 7 điểm', '2 bài/ngày, vừa sức', 'môn nền tảng của năm nhất', '6 tuần · tối Chủ nhật'][i];
        return '<div style="display:grid;grid-template-columns:70px 170px 1fr;gap:16px;align-items:center;background:#fff;border:1px solid var(--line);border-radius:14px;padding:12px 18px">' +
          '<b style="font:700 44px/1 var(--serif);color:var(--lam)">' + s.k + '</b><b>' + s.name + '</b><span class="muted" style="font-size:22px">' + s.q + ' <span style="color:var(--luc);font-weight:600">→ ' + ex + '</span></span></div>';
      }).join('') + '</div></div><p class="src">G. T. Doran (1981). There’s a S.M.A.R.T. way to write management’s goals and objectives. Management Review, 70(11).</p>'
  });

  add({ sec: 1, kicker: SEC[1], title: 'Mục tiêu thật của sinh viên năm nhất — sửa thế nào?', notes:
    'Ba câu bên trái là mục tiêu THẬT (ẩn danh) sinh viên năm nhất HCMUS viết trong bài tập SMART của học phần Kỹ năng làm việc nhóm & học tập, HK2 2025–26.\n' +
    'Lỗi phổ biến: quá xa và chung chung ("ra trường đúng hạn"), đặt điểm tuyệt đối (thứ mình không kiểm soát hết), thiếu thời hạn.\n' +
    'Ý quan trọng: điểm số là KẾT QUẢ; hãy gắn nó với HÀNH ĐỘNG mình kiểm soát được (số bài, số giờ, số buổi).',
    html: '<div style="display:grid;gap:18px;flex:1;align-content:center"><div style="display:grid;grid-template-columns:1fr 1fr 1.4fr;gap:20px;font-size:17px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--mute)"><span>Sinh viên viết</span><span>Vấn đề</span><span>Có thể sửa thành</span></div>' +
      [['“Ra trường đúng hạn.”', 'Quá xa, không có mốc đo ở học kỳ này', '“Học kỳ 1: tích lũy đủ 18/18 tín chỉ đăng ký, không môn nào dưới 5 điểm.”'],
       ['“Đạt 10.0 môn học này.”', 'Chỉ có kết quả, không có hành động; điểm tuyệt đối không hoàn toàn trong tầm tay', '“Nộp đủ 100% bài tập trước hạn 24 giờ và sửa lỗi theo nhận xét → nhắm từ 8,5.”'],
       ['“Chọn được chuyên ngành.”', 'Thiếu thời hạn và bước đi cụ thể', '“Trước 30/11: dự 2 buổi giới thiệu ngành, hỏi 2 anh chị khóa trên, ghi 3 ngành ưu tiên.”']].map(function (r) {
        return '<div style="display:grid;grid-template-columns:1fr 1fr 1.4fr;gap:20px;align-items:stretch">' +
          '<div class="card" style="padding:18px 22px;background:#FBEFEC;border-color:#F0C8BE"><p style="font:600 27px var(--serif)">' + r[0] + '</p></div>' +
          '<div class="card" style="padding:18px 22px"><p class="muted" style="font-size:21px">' + r[1] + '</p></div>' +
          '<div class="card" style="padding:18px 22px;background:#E8F4EE;border-color:#BFE0CF"><p style="font-size:23px;font-weight:600">' + r[2] + '</p></div></div>';
      }).join('') + '</div><p class="src">Ba câu bên trái: trích ẩn danh từ bài tập SMART của sinh viên năm nhất HCMUS (học phần Kỹ năng làm việc nhóm &amp; học tập, HK2 2025–26). Câu sửa là gợi ý.</p>'
  });

  add({ sec: 1, kicker: SEC[1], title: 'OKR cá nhân: một mục tiêu, ba con số', notes:
    'OKR (Objectives & Key Results) bắt nguồn từ Andy Grove ở Intel, được John Doerr phổ biến (sách "Measure What Matters", 2018).\n' +
    'Khác SMART ở chỗ: O truyền cảm hứng, KR là bằng chứng đo được. Không cần đạt 100% mọi KR — OKR cố ý đặt hơi tham vọng.\n' +
    'Gợi ý: chỉ 1 O cho học kỳ đầu là đủ.',
    html: '<div class="g2" style="grid-template-columns:1fr 1.3fr;gap:50px;align-items:center;flex:1">' +
      '<div class="card" style="background:var(--lam);color:#fff;border:0;padding:36px"><p style="font-size:20px;letter-spacing:.12em;opacity:.8;font-weight:700">OBJECTIVE · MỤC TIÊU</p><p style="font:700 42px/1.2 var(--serif);margin-top:14px">Đứng vững ở học kỳ đại học đầu tiên</p><p style="margin-top:18px;opacity:.85">Truyền cảm hứng, trả lời câu hỏi “mình muốn tới đâu?”</p></div>' +
      '<div style="display:grid;gap:16px">' +
      ['Không vắng buổi nào không phép ở các môn chuyên môn', 'Nộp 100% bài tập trước hạn ít nhất 24 giờ', 'Ngủ từ 7 tiếng trở lên ít nhất 5 đêm mỗi tuần'].map(function (t, i) {
        return '<div class="card" style="display:grid;grid-template-columns:90px 1fr;gap:18px;align-items:center;padding:20px 24px"><b style="font:700 30px var(--serif);color:var(--lam)">KR' + (i + 1) + '</b><span style="font-size:27px">' + t + '</span></div>';
      }).join('') + '<p class="muted small" style="margin:6px 0 0">Key Results = con số chứng minh bạn đã tới. Đạt 70–80% đã là một học kỳ tốt.</p></div></div>' +
      '<p class="src">OKR: A. Grove (Intel, 1970s); J. Doerr (2018), Measure What Matters.</p>'
  });

  add({ sec: 1, kicker: 'Hoạt động · 5 phút', title: 'Viết một mục tiêu SMART của bạn', act: 'muctieu', notes:
    'Bật đồng hồ 5 phút (gõ 5 rồi T). Sinh viên viết mục tiêu trên điện thoại, tự tích 5 tiêu chí rồi gửi.\n' +
    'Sau 3 phút: "Đưa điện thoại cho bạn bên cạnh. Đọc mục tiêu của bạn ấy — bạn có biết chính xác tuần này bạn ấy sẽ làm gì không?" (kiểm tra chéo cặp đôi).\n' +
    'Diễn giả xem nội dung mục tiêu ở bảng Diễn giả (H → Xem mục tiêu) và đọc to 2–3 mục tiêu hay (ẩn danh). Cột thấp nhất thường là M hoặc T → nhắc lại.',
    html: act('muctieu', '<p><b>Trên điện thoại:</b></p><p>1. Viết một mục tiêu cho học kỳ này.<br>2. Tự kiểm 5 tiêu chí S-M-A-R-T.<br>3. Đưa bạn bên cạnh đọc thử.</p>')
  });

  /* ── PHẦN 2 ── */
  add({ sec: 2, divider: 2, title: 'Ma trận Eisenhower', quote: '“Tôi có hai loại vấn đề: gấp và quan trọng. Việc gấp thì không quan trọng, còn việc quan trọng thì chẳng bao giờ gấp.”', by: 'Dwight D. Eisenhower, 1954', notes:
    'Câu nói trong diễn văn năm 1954 của Tổng thống Mỹ D. D. Eisenhower (ông dẫn lại lời một hiệu trưởng đại học). Stephen Covey phổ biến thành "ma trận quản lý thời gian" trong sách 7 Thói quen của người thành đạt (1989).\n' +
    'Hỏi: "Tuần qua, việc nào làm bạn bận rộn nhất? Nó gấp hay quan trọng?"' });

  add({ sec: 2, kicker: SEC[2], title: 'Hai câu hỏi, bốn ô', notes:
    'Trước mỗi việc, hỏi 2 câu: (1) Việc này có giúp mục tiêu của mình không? → quan trọng. (2) Nó có đòi hỏi làm ngay không? → khẩn cấp.\n' +
    'Ô 2 là nơi tạo ra khác biệt dài hạn: đọc bài trước, tập thể dục, học ngoại ngữ, xây quan hệ. Không ai giục, nên rất dễ bị bỏ quên.\n' +
    'Ô 3 với sinh viên thường là "gấp của người khác": nhờ vả, tin nhắn nhóm, cuộc gọi. Cách xử lý: giảm, gom lại, hẹn giờ khác, hoặc từ chối.',
    html: '<div class="matrix">' +
      '<div></div><div class="ax">Khẩn cấp</div><div class="ax">Không khẩn cấp</div>' +
      '<div class="ax v">Quan trọng</div>' +
      '<div class="quad" style="background:#FBEFEC;border-color:#E7A898"><span class="qn" style="color:var(--son)">Ô 1</span><h3>Làm ngay</h3><p>Hạn chót hôm nay, thi ngày mai, sự cố thật.</p><p class="ex">Mục tiêu: làm xong — và tìm vì sao nó lại thành gấp.</p></div>' +
      '<div class="quad" style="background:#E8F4EE;border-color:#86C5A9;box-shadow:0 0 0 4px rgba(15,107,85,.12)"><span class="qn" style="color:var(--luc)">Ô 2 · vùng tạo khác biệt</span><h3>Lên lịch</h3><p>Đọc bài trước, ôn đều, tập thể dục, ngủ đủ, gặp cố vấn học tập.</p><p class="ex">Mục tiêu: đặt vào lịch như một cuộc hẹn.</p></div>' +
      '<div class="ax v">Không quan trọng</div>' +
      '<div class="quad" style="background:#FBF4E3;border-color:#E4C67F"><span class="qn" style="color:#9A6B0C">Ô 3</span><h3>Giảm · nhờ · từ chối</h3><p>Nhờ vả vặt, tin nhắn nhóm liên tục, cuộc gọi bất chợt.</p><p class="ex">Mục tiêu: gom lại, hẹn giờ khác, nói “không” mềm mỏng.</p></div>' +
      '<div class="quad" style="background:#EEF1F3;border-color:#B5C0C8"><span class="qn" style="color:#5B6B7C">Ô 4</span><h3>Bỏ · hạn chế</h3><p>Lướt mạng vô thức, xem “thêm một tập”, game thâu đêm.</p><p class="ex">Giải trí có chủ đích thì không sai — vô thức mới là vấn đề.</p></div></div>'
  });

  add({ sec: 2, kicker: 'Hoạt động · 4 phút', title: 'Xếp 8 việc của tân sinh viên vào 4 ô', act: 'eisen', notes:
    'Bật đồng hồ 4 phút. Sinh viên chạm vào từng thẻ rồi chọn ô trên điện thoại.\n' +
    'Khi xem kết quả, KHÔNG chấm đúng/sai ngay. Tìm thẻ có màu đỏ ở cột phải (dưới 50% đồng ý) — đó là thẻ hội trường chia rẽ, đáng thảo luận nhất.\n' +
    'Thường chia rẽ: "group chat 40 tin nhắn" (ô 3 hay ô 4? nếu có thông báo đổi phòng thi thì sao?), "bạn nhờ lấy hàng" (ô 3 — gấp của người khác), "tập thể dục" (nhiều bạn xếp ô 4 — thật ra là ô 2).\n' +
    'Chốt bằng thẻ "Mai thi mà chưa ôn": nó đang ở ô 1, nhưng 2 tuần trước nó là ô 2.',
    html: act('eisen', '<p><b>Chạm từng thẻ → chọn ô 1–4.</b></p><p>Không có đáp án tuyệt đối: hãy xếp theo hoàn cảnh của bạn.</p><p class="small">Cột phải: % hội trường chọn ô gợi ý. <span style="color:var(--son);font-weight:700">Đỏ</span> = hội trường chia rẽ.</p>')
  });

  add({ sec: 2, kicker: SEC[2], title: 'Bẫy khẩn cấp — và ba lối thoát', notes:
    'Vòng luẩn quẩn: bỏ qua việc ô 2 → nó thành ô 1 → chạy deadline, kiệt sức → lướt mạng (ô 4) để xả → lại không còn sức cho ô 2.\n' +
    'Tài liệu nhóm sinh viên KNLVN gọi đây là trạng thái "bận rộn giả": làm nhiều nhưng hiệu quả không đáng kể.\n' +
    'Cho hội trường đọc to 3 câu từ chối — đặc biệt dành cho các bạn Hình Tròn.',
    html: '<div class="g2" style="grid-template-columns:1fr 1.15fr;gap:54px;flex:1;align-items:center">' +
      '<svg viewBox="0 0 520 470" style="width:100%;max-width:560px" role="img" aria-label="Vòng luẩn quẩn của bẫy khẩn cấp">' +
      '<defs><marker id="ar" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0,0 L10,5 L0,10 z" fill="#9AA8B2"/></marker></defs>' +
      '<path d="M260 70 A 175 175 0 0 1 430 250" fill="none" stroke="#9AA8B2" stroke-width="4" marker-end="url(#ar)"/>' +
      '<path d="M420 300 A 175 175 0 0 1 270 420" fill="none" stroke="#9AA8B2" stroke-width="4" marker-end="url(#ar)"/>' +
      '<path d="M215 418 A 175 175 0 0 1 85 270" fill="none" stroke="#9AA8B2" stroke-width="4" marker-end="url(#ar)"/>' +
      '<path d="M90 210 A 175 175 0 0 1 205 70" fill="none" stroke="#9AA8B2" stroke-width="4" marker-end="url(#ar)"/>' +
      '<g font-family="Be Vietnam Pro, sans-serif" font-size="21" font-weight="600" text-anchor="middle">' +
      '<rect x="150" y="18" width="220" height="64" rx="14" fill="#E8F4EE" stroke="#86C5A9"/><text x="260" y="46" fill="#0F6B55">Bỏ qua việc ô 2</text><text x="260" y="70" fill="#4E5D68" font-size="16" font-weight="500">“để mai đọc cũng được”</text>' +
      '<rect x="330" y="232" width="180" height="64" rx="14" fill="#FBEFEC" stroke="#E7A898"/><text x="420" y="260" fill="#D2492A">Thành ô 1</text><text x="420" y="284" fill="#4E5D68" font-size="16" font-weight="500">chạy deadline</text>' +
      '<rect x="150" y="392" width="220" height="64" rx="14" fill="#F3F0F7" stroke="#C3B3DC"/><text x="260" y="420" fill="#6B4A9E">Kiệt sức</text><text x="260" y="444" fill="#4E5D68" font-size="16" font-weight="500">thức khuya, mệt</text>' +
      '<rect x="10" y="200" width="180" height="64" rx="14" fill="#EEF1F3" stroke="#B5C0C8"/><text x="100" y="228" fill="#5B6B7C">Lướt mạng</text><text x="100" y="252" fill="#4E5D68" font-size="16" font-weight="500">ô 4 để “xả”</text></g></svg>' +
      '<div><ol class="steps sec2" style="gap:22px">' +
      '<li><div><b>Hẹn giờ cho ô 2</b><span class="muted">Đặt khối giờ cố định trong lịch: “Thứ 3, 19:00–21:00 — Giải tích”.</span></div></li>' +
      '<li><div><b>Hạn chót của mình sớm hơn 24 giờ</b><span class="muted">Hạn trên Moodle 23:59 thứ Sáu → hạn của bạn 23:59 thứ Năm.</span></div></li>' +
      '<li><div><b>Nói “không” mềm mỏng</b><span class="muted" style="display:block">“Mình đang làm bài tới 17h, sau đó mình phụ bạn nhé.”<br>“Lần này mình xin phép không đi, lần sau rủ mình nha.”<br>“Mình làm được phần A, phần B nhờ bạn khác được không?”</span></div></li></ol></div></div>'
  });

  /* ── PHẦN 3 ── */
  add({ sec: 3, divider: 3, title: 'Xây dựng kế hoạch cá nhân', lead: 'Kế hoạch tốt không phải lịch kín mít — mà là lịch có chỗ cho điều quan trọng và chừa chỗ cho điều bất ngờ.', notes:
    'Chuyển phần. Kế hoạch cá nhân = mục tiêu (Phần 1) + ưu tiên (Phần 2) đặt vào thời gian thật.\n' +
    'Quy trình 4 bước (kho KNLVN): chia việc thành bước 30–60 phút → xếp lịch theo ưu tiên → chuẩn bị nguồn lực → làm, theo dõi, điều chỉnh.' });

  add({ sec: 3, kicker: 'Hoạt động · 3 phút', title: 'Bánh xe cuộc đời: 8 nan hoa của bạn', act: 'banhxe', notes:
    'Bánh xe cuộc đời (Wheel of Life) — công cụ tự đánh giá thường gắn với Paul J. Meyer. Chấm 1–10 cho mức hài lòng hiện tại ở 8 lĩnh vực.\n' +
    'Trên điện thoại, mỗi bạn thấy bánh xe của riêng mình; màn hình này là trung bình cả hội trường (ẩn danh).\n' +
    'Câu hỏi: "Nếu bánh xe này lăn, nó có tròn không?" Kế hoạch tuần nên có ít nhất MỘT việc cho nan hoa thấp nhất của bạn — thường là sức khỏe & giấc ngủ hoặc tài chính.',
    html: act('banhxe', '<p><b>Chấm 1–10 cho 8 lĩnh vực.</b></p><p>Mức hài lòng <i>hiện tại</i> của bạn — không ai thấy điểm riêng của bạn.</p><p class="small">Nan hoa thấp nhất là gợi ý cho kế hoạch tuần.</p>')
  });

  add({ sec: 3, kicker: SEC[3], title: 'Lịch tuần: đặt “đá to” vào trước', notes:
    'Thứ tự xếp lịch theo K. Paul (2008, tr. 190–193): (1) việc cố định — lên lớp, thí nghiệm, làm thêm; (2) khối tự học vào giờ bạn tỉnh táo nhất; (3) sức khỏe, CLB, gia đình; (4) CHỪA KHOẢNG TRỐNG.\n' +
    'Trích tài liệu: "Tại sao hầu hết các thời gian biểu đều thất bại? Vì chúng quá kín."\n' +
    'Lịch mẫu chỉ minh họa — mời sinh viên mở Google Calendar/Lịch trên điện thoại và tạo ngay 1 khối "Tự học" lặp lại hằng tuần.',
    html: (function () {
      var D = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];
      var rows = ['Sáng', 'Trưa', 'Chiều', 'Chiều tối', 'Tối', 'Khuya'];
      var g = {
        'T2': ['lop:Lên lớp', 'free:', 'hoc:Tự học Giải tích', 'sk:Thể thao', 'hoc:Tự học Lập trình', 'free:Ngủ trước 23:30'],
        'T3': ['hoc:Thư viện', 'free:', 'lop:Thực hành', 'free:', 'hoc:Tự học Giải tích', 'free:'],
        'T4': ['lop:Lên lớp', 'free:', 'lop:Lên lớp', 'sk:Thể thao', 'clb:CLB', 'free:'],
        'T5': ['hoc:Ôn bài tuần', 'free:', 'dem:Vùng đệm', 'free:', 'hoc:Bài tập nhóm', 'free:'],
        'T6': ['lop:Lên lớp', 'free:', 'hoc:Tự học Vật lý', 'sk:Thể thao', 'free:Tự do', 'free:'],
        'T7': ['lam:Làm thêm', 'lam:Làm thêm', 'dem:Vùng đệm', 'free:Gia đình, bạn bè', 'free:Tự do', 'free:'],
        'CN': ['free:Ngủ bù', 'free:', 'free:Tự do', 'rv:Rà soát tuần', 'hoc:Chuẩn bị tuần mới', 'free:']
      };
      var h = '<div class="g2" style="grid-template-columns:1.55fr 1fr;gap:40px;flex:1;min-height:0;padding-bottom:16px"><div style="display:flex;flex-direction:column;gap:12px;min-height:0"><div class="week"><div></div>' + D.map(function (d) { return '<div class="h">' + d + '</div>'; }).join('');
      rows.forEach(function (r, ri) {
        h += '<div class="t">' + r + '</div>';
        D.forEach(function (d) { var c = g[d][ri].split(':'); h += '<div class="blk ' + c[0] + '">' + (c[1] || '') + '</div>'; });
      });
      h += '</div><div class="legend"><span><i style="background:var(--lam)"></i>Cố định</span><span><i style="background:var(--luc)"></i>Tự học (ô 2)</span><span><i style="background:#46A385"></i>Sức khỏe</span><span><i style="background:var(--tim)"></i>CLB</span><span><i style="background:#EDE3C9"></i>Vùng đệm</span><span><i style="background:var(--son)"></i>Rà soát</span><span><i style="background:#fff;border:2px dashed var(--line)"></i>Trống</span></div></div>';
      h += '<div><ol class="steps sec3" style="gap:20px"><li><div><b>Việc cố định</b><span class="muted small">Lên lớp, thực hành, làm thêm.</span></div></li><li><div><b>Khối tự học</b><span class="muted small">Đặt vào giờ bạn tỉnh táo nhất — mỗi khối 1–2 giờ, có tên môn.</span></div></li><li><div><b>Sức khỏe &amp; con người</b><span class="muted small">Thể thao, gia đình, bạn bè, CLB.</span></div></li><li><div><b>Chừa khoảng trống</b><span class="muted small">~20% làm vùng đệm. Lịch kín quá sẽ vỡ ở tuần đầu tiên.</span></div></li></ol></div></div>';
      return h;
    })()
  });

  add({ sec: 3, kicker: SEC[3], title: '20 phút tối Chủ nhật: rà soát tuần', notes:
    'Rà soát tuần (Weekly Review) là bước giữ cho cả hệ thống sống — xuất phát từ phương pháp GTD của David Allen.\n' +
    'Gợi ý: đặt lịch lặp lại "Rà soát tuần — CN 20:00, 20 phút" ngay bây giờ. Đây cũng là một lựa chọn trong cam kết 7 ngày.',
    html: '<div class="g4" style="flex:1;align-content:center">' +
      [['Nhìn lại', 'Tuần này mình làm được gì? Ghi 3 điều — kể cả việc nhỏ.', '📝'],
       ['Gom về một chỗ', 'Hạn chót trên Moodle, tin nhắn nhóm, giấy tờ → vào một danh sách duy nhất.', '📥'],
       ['Nhìn trước', 'Hai tuần tới có kiểm tra, nộp bài, sự kiện gì?', '🔭'],
       ['Chọn 3 việc lớn', 'Ba việc quan trọng nhất tuần sau — đặt luôn vào lịch.', '🎯']].map(function (r, i) {
        return '<div class="card" style="display:flex;flex-direction:column;gap:12px;border-top:6px solid var(--luc)"><span style="font-size:46px">' + r[2] + '</span><span class="tag sec3">Bước ' + (i + 1) + '</span><h3>' + r[0] + '</h3><p class="muted">' + r[1] + '</p></div>';
      }).join('') + '</div><p class="src">Dựa trên bước “Reflect / Weekly Review” trong D. Allen (2001), Getting Things Done.</p>'
  });

  /* ── PHẦN 4 ── */
  add({ sec: 4, divider: 4, title: 'Phương pháp quản lý thời gian', lead: 'Không có phương pháp nào hợp với mọi người. Thử một tuần, giữ cái hợp, bỏ cái không hợp.', notes:
    'Chuyển phần. Gợi ý theo hình: Lượn Sóng & Chữ Nhật thử Pomodoro; Vuông thử time-box; Tròn thử khối giờ riêng; Tam Giác thử lịch nghỉ.' });

  add({ sec: 4, kicker: SEC[4], title: 'Bốn công cụ dùng được ngay tối nay', notes:
    'Pomodoro: Francesco Cirillo, cuối thập niên 1980. Chuẩn là 25 phút + nghỉ 5 phút; sau 4 phiên nghỉ dài 15–30 phút. Tài liệu kho KNLVN cũng lưu ý: chu kỳ có thể điều chỉnh theo người — bài toán dài có thể thử 50/10.\n' +
    'Quy tắc 2 phút: từ GTD (David Allen) — việc dưới 2 phút thì làm luôn, đừng ghi vào danh sách.\n' +
    'Ăn con ếch: Brian Tracy, "Eat That Frog!" (2001) — làm việc khó và quan trọng nhất trước tiên.\n' +
    'Chia nhỏ: K. Paul (2008, tr. 154) — chia việc đến khi mỗi việc chỉ 30–60 phút.\n' +
    'Có thể bấm T để chạy thử 1 phiên Pomodoro rút gọn 1 phút cùng hội trường (gõ 1 rồi T).',
    html: '<div class="g4" style="flex:1;align-content:center;gap:22px">' +
      [['🍅', 'Pomodoro', '25 phút tập trung một việc, nghỉ 5 phút. Sau 4 phiên nghỉ dài 15–30 phút.', 'F. Cirillo · cuối 1980s', 'Bài toán dài: thử 50/10.'],
       ['⚡', 'Quy tắc 2 phút', 'Việc dưới 2 phút thì làm luôn: trả lời email thầy cô, nộp phiếu khảo sát, lưu file.', 'D. Allen · GTD, 2001', 'Đừng để việc nhỏ chiếm chỗ trong đầu.'],
       ['🐸', 'Ăn con ếch', 'Việc khó và quan trọng nhất làm đầu tiên, khi đầu óc còn tươi.', 'B. Tracy · 2001', 'Xong “con ếch”, cả ngày nhẹ hơn.'],
       ['✂️', 'Chia nhỏ việc lớn', 'Chia đến khi mỗi bước chỉ 30–60 phút. Bắt đầu bước đầu tiên ngay.', 'K. Paul · 2008', 'Bước nhỏ xong là đã thắng trì hoãn.']].map(function (r) {
        return '<div class="card" style="display:flex;flex-direction:column;gap:10px;border-top:6px solid #9A6B0C"><span style="font-size:52px;line-height:1">' + r[0] + '</span><h3>' + r[1] + '</h3><p>' + r[2] + '</p><p class="small" style="color:#9A6B0C;font-weight:600;margin-top:auto">' + r[4] + '</p><p class="meta">' + r[3] + '</p></div>';
      }).join('') + '</div>'
  });

  add({ sec: 4, kicker: SEC[4], title: 'GTD: đầu óc để suy nghĩ, không phải để nhớ', notes:
    'Getting Things Done — David Allen (2001). Năm bước: Thu thập, Làm rõ, Sắp xếp, Rà soát, Thực hiện.\n' +
    'Ý chính cho tân sinh viên: chọn MỘT nơi duy nhất để ghi mọi việc (Google Tasks, Todoist, Notion hay sổ giấy đều được). Hai nơi = không nơi nào đáng tin.\n' +
    'Moodle có lịch hạn nộp — có thể đồng bộ sang Google Calendar (hướng dẫn trong Cẩm nang).',
    html: '<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:16px;flex:1;align-content:center">' +
      [['Thu thập', 'Ghi mọi việc ra ngoài đầu: hạn nộp, lời hứa, ý tưởng.', 'Một app hoặc một cuốn sổ duy nhất'],
       ['Làm rõ', 'Việc này là gì? Làm được không? Dưới 2 phút → làm luôn.', '“Bài tập lớn” → “Đọc đề, liệt kê 5 bước”'],
       ['Sắp xếp', 'Gắn hạn, gắn môn, đưa vào lịch hoặc danh sách.', 'Tag theo môn · hạn chót'],
       ['Rà soát', 'Mỗi sáng 2 phút, mỗi tuần 20 phút.', 'Tối Chủ nhật'],
       ['Thực hiện', 'Làm với tâm thế yên tâm: không bỏ sót gì.', 'Pomodoro cho việc ô 2']].map(function (r, i) {
        return '<div style="display:flex;flex-direction:column;gap:12px"><div style="height:10px;border-radius:5px;background:#9A6B0C;opacity:' + (0.45 + i * 0.13) + '"></div><b style="font:700 32px var(--serif)">' + (i + 1) + '. ' + r[0] + '</b><p style="margin:0">' + r[1] + '</p><p class="muted small" style="margin:0">Ví dụ: ' + r[2] + '</p></div>';
      }).join('') + '</div><p class="src">D. Allen (2001). Getting Things Done: The Art of Stress-Free Productivity.</p>'
  });

  add({ sec: 4, kicker: SEC[4], title: 'Trì hoãn không phải lười — nó là né cảm giác khó chịu', notes:
    'Năm cách vượt trì hoãn theo K. Paul (2008, tr. 185–186): chuẩn bị, đặt mục tiêu học tập nhỏ, hình dung tổn hại, tưởng tượng niềm vui khi xong, và cho phép mình gác lại vài việc không quan trọng.\n' +
    'Tài liệu cũng nói: khoảng tập trung tốt cho một việc là 30–45 phút trước khi hiệu quả ghi nhớ giảm — một lý do để chia buổi học dài thành nhiều phiên.\n' +
    'Hỏi: "Bạn đang trì hoãn việc gì nhất lúc này? Bước 5 phút đầu tiên của nó là gì?"',
    html: '<div class="g2" style="grid-template-columns:1.2fr 1fr;gap:54px;flex:1;align-items:center">' +
      '<ol class="steps sec4" style="gap:18px">' +
      '<li><div><b>Chuẩn bị chỗ ngồi</b><span class="muted small">Bàn gọn, đủ dụng cụ, điện thoại úp xuống hoặc bật chế độ Tập trung.</span></div></li>' +
      '<li><div><b>Mục tiêu nhỏ 30 phút</b><span class="muted small">“Học chút ít” là bẫy. “Giải 5 bài 2.1–2.5” thì bắt đầu được.</span></div></li>' +
      '<li><div><b>Hình dung cái giá</b><span class="muted small">Đêm thức trắng trước hạn, điểm thấp, cảm giác tội lỗi.</span></div></li>' +
      '<li><div><b>Hình dung niềm vui khi xong</b><span class="muted small">Và tự thưởng thật: một tập phim, một ly trà sữa.</span></div></li>' +
      '<li><div><b>Cho phép gác lại việc phụ</b><span class="muted small">Không phải việc gì cũng cần làm hôm nay.</span></div></li></ol>' +
      '<div class="card" style="background:var(--muc);color:#fff;border:0;padding:40px"><p style="font:italic 400 36px/1.35 var(--serif);margin:0">“Bắt đầu nhỏ đến mức không thể từ chối: mở tập ra, viết dòng đầu tiên, đặt đồng hồ 5 phút.”</p><p style="margin-top:20px;opacity:.75;font-size:20px">Gợi ý thực hành của workshop</p></div></div>' +
      '<p class="src">Năm cách vượt trì hoãn: K. Paul (2008), Học khôn ngoan mà không gian nan, tr. 185–186.</p>'
  });

  add({ sec: 4, kicker: 'Thói quen', title: '66 ngày — không phải 21', notes:
    '"21 ngày tạo thói quen" là một ngộ nhận phổ biến. Nghiên cứu của Phillippa Lally và cộng sự (University College London, 2010) theo dõi người tham gia tập một hành vi mới hằng ngày: thời gian để hành vi thành tự động dao động 18–254 ngày, trung vị 66 ngày.\n' +
    'Hai tin tốt: (1) lỡ một ngày không làm hỏng quá trình; (2) việc càng nhỏ và gắn với một thời điểm cố định thì càng nhanh thành thói quen.\n' +
    'Vì vậy cam kết hôm nay chỉ là 7 ngày đầu tiên của một chặng dài hơn.',
    html: '<div class="g2" style="grid-template-columns:1fr 1.1fr;gap:60px;flex:1;align-items:center">' +
      '<div><div class="bigstat sec4"><b>66 ngày</b><span>trung vị để một hành vi mới thành tự động · dao động 18–254 ngày</span></div>' +
      '<div style="margin-top:34px;position:relative;height:56px;border-radius:12px;background:linear-gradient(90deg,#F6EFDC,#D6A130)"><span style="position:absolute;left:0;top:64px;font-size:18px" class="muted">18 ngày</span><span style="position:absolute;left:' + (66 / 254 * 100) + '%;top:-8px;bottom:-8px;width:4px;background:var(--muc)"></span><span style="position:absolute;left:calc(' + (66 / 254 * 100) + '% - 30px);top:64px;font-weight:700;font-size:18px">66</span><span style="position:absolute;right:0;top:64px;font-size:18px" class="muted">254 ngày</span></div></div>' +
      '<ul class="clean sec4" style="gap:22px;font-size:28px"><li><b>Lỡ một ngày</b> không làm hỏng cả quá trình — quay lại ngày hôm sau.</li><li><b>Càng nhỏ, càng cố định</b> (cùng giờ, cùng chỗ) thì càng nhanh thành nếp.</li><li>Hôm nay bạn chỉ cần <b>7 ngày đầu tiên</b>.</li></ul></div>' +
      '<p class="src">P. Lally, C. H. M. van Jaarsveld, H. W. W. Potts &amp; J. Wardle (2010). How are habits formed: Modelling habit formation in the real world. European Journal of Social Psychology, 40(6), 998–1009.</p>'
  });

  add({ sec: 4, kicker: 'Hoạt động · 2 phút', title: 'Cam kết 7 ngày của bạn', act: 'camket', notes:
    'Mỗi bạn chọn MỘT cam kết. Trên điện thoại có ô viết thêm "con ếch ngày mai của tôi là…" (chỉ lưu trên máy của bạn).\n' +
    'Gợi ý: chụp màn hình cam kết, đặt làm hình nền khóa trong 7 ngày.\n' +
    'Bình luận cam kết được chọn nhiều nhất và ít nhất.',
    html: act('camket', '<p><b>Chọn MỘT việc cho 7 ngày tới.</b></p><p>Nhỏ, cụ thể, làm được mỗi ngày.</p><p class="small">Mẹo: chụp màn hình làm hình nền khóa cả tuần.</p>')
  });

  add({ sec: 0, kicker: 'Trước khi hỏi đáp · 3 phút', title: 'Khảo sát cuối buổi', act: 'nc1', notes:
    'Mời các bạn đã làm khảo sát đầu buổi làm tiếp khảo sát cuối (3 phút) — câu kiến thức giống đầu buổi để đo phần học được; sau khi gửi, điện thoại hiện đáp án.\n' +
    'Nhắc: 2 khảo sát ngắn tiếp theo vào 01/11 và 29/11. Trong “Kế hoạch của tôi” có nút tải lịch nhắc kèm đường dẫn khảo sát.\n' +
    'Có thể mở hỏi đáp song song: sinh viên vừa làm khảo sát vừa gửi câu hỏi.',
    html: act('nc1', '<p><b>Khảo sát cuối buổi</b> — 3 phút. Gửi xong, điện thoại hiện đáp án phần kiến thức.</p><p class="small">Hẹn bạn ở khảo sát sau 2 tuần (01/11) và 6 tuần (29/11).</p>')
  });

  add({ sec: 0, kicker: 'Hỏi đáp', title: 'Câu hỏi của bạn', act: 'hoi', notes:
    'Câu hỏi gửi ẩn danh từ điện thoại, xếp theo lượt bình chọn. Trả lời từ trên xuống; đánh dấu ✓ trong bảng Diễn giả (H) để câu đó mờ đi.\n' +
    'Câu hỏi không phù hợp: bấm "Ẩn" trong bảng Diễn giả — sẽ biến khỏi màn hình ngay.\n' +
    'Gắn câu trả lời vào công cụ vừa học (Eisenhower, Pomodoro, lịch tuần…).',
    html: act('hoi', '<p><b>Gửi câu hỏi ẩn danh</b> và bấm ▲ cho câu bạn cũng muốn hỏi.</p><p class="small">Câu nhiều ▲ nhất được trả lời trước.</p>')
  });

  add({ sec: 0, kicker: 'Tổng kết', title: 'Bốn ý cho học kỳ đầu tiên', notes:
    'Tóm tắt 4 phần trong 1 phút. Mời sinh viên mở "Kế hoạch của tôi" trên điện thoại — nơi lưu hình khối, mục tiêu, bánh xe và cam kết của họ, có nút in/lưu PDF.\n' +
    'Cẩm nang in được (tài liệu tham khảo đầy đủ) ở mã QR bên phải.',
    html: '<div style="display:grid;grid-template-columns:1fr 300px;gap:50px;flex:1;align-items:center"><div class="g2" style="gap:24px">' +
      [[1, 'Mục tiêu', 'Viết ra, SMART, và có bước đầu tiên cho tuần này.'],
       [2, 'Ưu tiên', 'Trước mỗi việc: quan trọng không? gấp không? Bảo vệ ô 2.'],
       [3, 'Kế hoạch', 'Đá to vào lịch trước, chừa 20% vùng đệm, rà soát tối Chủ nhật.'],
       [4, 'Phương pháp', 'Pomodoro, 2 phút, ăn con ếch, chia nhỏ — thử 1 tuần, giữ cái hợp.']].map(function (r) {
        return '<div class="card sec' + r[0] + '" style="border-top:6px solid var(--sec)"><p class="tag">Phần ' + r[0] + '</p><h3 style="margin-top:12px">' + r[1] + '</h3><p>' + r[2] + '</p></div>';
      }).join('') + '</div><div class="join"><div class="qr">' + QR_BOOK + '</div><b>Cẩm nang in được</b><code style="font-size:17px">diemdanhsv.com/workshop/cam-nang</code></div></div>'
  });

  add({ sec: 0, kicker: 'Góp ý · 30 giây', title: 'Buổi hôm nay có ích với bạn không?', act: 'danhgia', notes:
    'Có thể bỏ qua slide này nếu đa số đã làm khảo sát cuối buổi (đã có phần nhận xét).\n' +
    'Phiếu góp ý ẩn danh — dữ liệu dùng làm minh chứng báo cáo sau workshop (xuất ở bảng Diễn giả → Tải CSV).\n' +
    'Đọc to 1–2 điều sinh viên "mang về" nếu có (bảng Diễn giả → Xem góp ý).',
    html: act('danhgia', '<p><b>Chấm 1–5 sao</b> và viết một điều bạn mang về.</p><p class="small">Ẩn danh. Giúp chúng tôi làm buổi sau tốt hơn.</p>')
  });

  add({ sec: 0, cls: 'cover', bare: true, title: 'Cảm ơn', notes: 'Kết thúc. Kỹ thuật: mở bảng Diễn giả → Tải CSV và JSON để lưu minh chứng trước khi đóng máy.',
    html: '<div style="display:grid;grid-template-columns:1fr 330px;gap:70px;align-items:center;flex:1"><div>' +
      '<div class="logos"><img class="hcmus" src="' + LOGO_HCMUS + '" alt="Trường ĐH Khoa học Tự nhiên, ĐHQG-HCM"><span class="sep dqt-sen"></span><img class="sen dqt-sen" src="assets/logo-sen.svg" alt="Logo hoa sen đọng sương"></div>' +
      '<h1>Cảm ơn các bạn!</h1><p class="sub">Thời gian là thứ duy nhất ai cũng có như nhau. Khác nhau ở chỗ ai là người quyết định nó đi đâu.</p>' +
      '<p class="cover-when" style="margin:-14px 0 28px">📝 Hẹn bạn ở khảo sát ngắn sau 2 tuần (01/11) và 6 tuần (29/11) — mở lại trang tham gia hoặc lịch nhắc.</p>' +
      '<div class="people"><div class="person"><span class="av" style="background:var(--luc)">KL</span><div><b>ThS. Đinh Thị Kim Liên</b><span>Diễn giả</span></div></div>' +
      '<div class="person"><span class="av" style="background:var(--lam)">QT</span><div><b>ThS. Đinh Quốc Tuấn</b><span>Trợ giảng · Khoa Địa chất</span></div></div></div></div>' +
      '<div>' + joinBox('Xem lại “Kế hoạch của tôi”') + '</div></div>'
  });

  /* ════════════════════════ DỰNG SLIDE ════════════════════════ */
  function slideHtml(s, i) {
    var cls = 'slide sec' + s.sec + (s.cls ? ' ' + s.cls : '') + (s.divider ? ' divider' : '');
    var inner = '';
    if (s.divider) {
      inner = '<div class="s-body divider"><span class="big-n">' + s.divider + '</span><p class="s-kicker">Phần ' + s.divider + '</p><h2 class="s-title">' + s.title + '</h2>' +
        (s.lead ? '<p class="s-lead" style="font-size:34px;max-width:1100px">' + s.lead + '</p>' : '') +
        (s.quote ? '<p class="quote" style="margin-top:26px">' + s.quote + '</p><p class="quote-by">— ' + s.by + '</p>' : '') + '</div>';
    } else if (s.bare) {
      inner = '<div class="s-body">' + s.html + '</div>';
    } else {
      inner = '<div class="s-body">' + (s.kicker ? '<p class="s-kicker">' + s.kicker + '</p>' : '') + '<h2 class="s-title">' + s.title + '</h2>' + (s.lead ? '<p class="s-lead">' + s.lead + '</p>' : '') + s.html + '</div>';
    }
    var foot = '<div class="s-foot"><img class="hcmus" src="' + LOGO_HCMUS + '" alt=""><span>Workshop Tân sinh viên K2026 · Quản lý thời gian &amp; kế hoạch cá nhân</span><span class="grow"></span>' +
      '<span>' + WS.JOIN_SHORT + '</span><span class="num">' + (i + 1) + ' / ' + S.length + '</span><img class="sen dqt-sen" src="assets/logo-sen.svg" alt=""></div>';
    return '<section class="' + cls + '" data-i="' + i + '">' + inner + (s.cls === 'cover' ? '' : foot) + '</section>';
  }

  var params = new URLSearchParams(location.search);
  var isNotes = params.get('view') === 'notes';
  var cur = 0, demo = false;
  var chan = ('BroadcastChannel' in window) ? new BroadcastChannel('ws26-deck') : null;
  var scaler = document.getElementById('scaler');

  function fit() {
    var w = window.innerWidth, h = window.innerHeight;
    var k = Math.min(w / 1600, h / 900);
    // Căn giữa bằng translate rồi mới scale: cửa sổ hẹp hơn 1600px (laptop, máy chiếu 1366/1280) vẫn nằm giữa.
    scaler.style.transform = 'translate(-50%, -50%) scale(' + k + ')';
  }

  function go(i, fromRemote) {
    i = Math.max(0, Math.min(S.length - 1, i));
    var changed = i !== cur || !scaler.firstChild;
    cur = i;
    if (changed) {
      scaler.innerHTML = slideHtml(S[i], i);
      scaler.firstChild.classList.add('enter');
    }
    document.getElementById('pos').textContent = (i + 1) + ' / ' + S.length;
    document.getElementById('progress').style.width = ((i + 1) / S.length * 100) + '%';
    if (history.replaceState) history.replaceState(null, '', location.pathname + location.search + '#' + (i + 1));
    if (chan && !fromRemote) chan.postMessage({ t: 'pos', i: i });
    startLive();
    pushState();
  }

  /* ───────── kết quả trực tiếp ───────── */
  var liveTimer = null, failCount = 0;
  function startLive() {
    clearInterval(liveTimer);
    var s = S[cur];
    if (!s.act) return;
    pollLive();
    liveTimer = setInterval(pollLive, 3000);
  }
  function setHead(n, ok, label) {
    var box = scaler.querySelector('.live-head');
    if (!box) return;
    box.classList.toggle('off', !ok);
    box.querySelector('.st').textContent = label || (ok ? 'Kết quả trực tiếp' : 'Mất kết nối máy chủ — đang thử lại…');
    if (n != null) box.querySelector('.count').textContent = n + (S[cur].act === 'hoi' ? ' câu hỏi' : ' phiếu');
  }
  function renderLive(a, d) {
    var body = scaler.querySelector('.live-body');
    if (!body) return;
    if (a === 'hoi') {
      var items = (d.items || []).filter(function (q) { return q.status < 2; });
      items.sort(function (x, y) { return (x.status - y.status) || (y.votes - x.votes) || (y.id - x.id); });
      body.innerHTML = items.length ? '<div class="qa-list">' + items.slice(0, 6).map(function (q) {
        return '<div class="qa-item' + (q.status === 1 ? ' done' : '') + '"><div class="v">' + q.votes + '<small>▲</small></div><div class="t">' + esc(q.text) + '</div></div>';
      }).join('') + '</div>' : '<p class="muted">Chưa có câu hỏi. Gửi câu đầu tiên từ điện thoại nhé!</p>';
      setHead(items.length, true, demo ? 'Dữ liệu minh họa' : null);
      return;
    }
    if (!d.n) { body.innerHTML = '<p class="muted">Đang chờ phiếu đầu tiên… Quét mã bên phải để tham gia.</p>'; setHead(0, true, demo ? 'Dữ liệu minh họa' : null); return; }
    body.innerHTML = WS.render[a](d);
    setHead(d.n, true, demo ? 'Dữ liệu minh họa' : null);
  }
  var DEMO_Q = { items: [
    { id: 3, text: 'Làm sao cân bằng giữa học ở Linh Trung và đi làm thêm buổi tối?', votes: 24, status: 0 },
    { id: 2, text: 'Em lỡ lướt điện thoại tới 2 giờ sáng gần như mỗi ngày, bắt đầu sửa từ đâu?', votes: 19, status: 0 },
    { id: 5, text: 'Bạn cùng nhóm hay trễ hạn thì mình nên làm gì?', votes: 15, status: 0 },
    { id: 1, text: 'Có nên tham gia nhiều CLB ngay năm nhất không ạ?', votes: 11, status: 0 },
    { id: 4, text: 'Pomodoro 25 phút có quá ngắn khi giải toán không?', votes: 8, status: 0 }] };
  function pollLive() {
    var a = S[cur].act;
    if (!a) return;
    if (demo) { renderLive(a, a === 'hoi' ? DEMO_Q : WS.demo(a)); return; }
    var p = a === 'hoi' ? WS.get({ a: 'questions' }) : WS.get({ a: 'results', act: a });
    p.then(function (d) { failCount = 0; if (S[cur].act === a) renderLive(a, d); })
      .catch(function () { failCount++; if (failCount >= 2) setHead(null, false); });
  }
  function setDemo(on) {
    demo = !!on; document.body.classList.toggle('demo', demo);
    var t = document.getElementById('demoToggle'); if (t) t.checked = demo;
    pollLive();
  }

  /* ───────── trạng thái cho điện thoại SV ───────── */
  var lastPushed = null;
  function pushState() {
    var pin = getPin();
    if (!pin || !document.getElementById('syncState').checked || demo) return;
    var a = S[cur].act || '';
    if (!a || a === lastPushed) return;
    lastPushed = a;
    WS.post({ a: 'admin', op: 'state', pin: pin, current: a }).catch(function () { lastPushed = null; });
  }

  /* ───────── bảng diễn giả ───────── */
  function getPin() { try { return sessionStorage.getItem('ws26.pin') || ''; } catch (e) { return ''; } }
  function setPin(p) { try { p ? sessionStorage.setItem('ws26.pin', p) : sessionStorage.removeItem('ws26.pin'); } catch (e) {} }
  function admin(op, extra) {
    var b = { a: 'admin', op: op, pin: getPin() }; for (var k in extra) b[k] = extra[k];
    return WS.post(b);
  }
  function hostShow() {
    var logged = !!getPin();
    document.getElementById('hostLogin').hidden = logged;
    document.getElementById('hostMain').hidden = !logged;
    if (logged) {
      var c = document.getElementById('hostConn');
      c.textContent = 'Đang kiểm tra máy chủ…';
      admin('check').then(function () { c.textContent = '✅ Máy chủ hoạt động · phiên ' + WS.SESSION; c.style.color = 'var(--luc)'; })
        .catch(function (e) { c.textContent = '⚠️ ' + e.message; c.style.color = 'var(--son)'; });
    }
  }
  function msg(id, t, bad) { var m = document.getElementById(id); m.textContent = t; m.style.color = bad ? 'var(--son)' : 'var(--mute)'; }
  document.getElementById('pinGo').onclick = function () {
    var p = document.getElementById('pinIn').value.trim();
    if (!p) return;
    msg('pinMsg', 'Đang kiểm tra…');
    WS.post({ a: 'admin', op: 'check', pin: p }).then(function () { setPin(p); document.getElementById('pinIn').value = ''; msg('pinMsg', ''); lastPushed = null; hostShow(); pushState(); })
      .catch(function (e) { msg('pinMsg', e.message, true); });
  };
  document.getElementById('pinIn').addEventListener('keydown', function (e) { if (e.key === 'Enter') document.getElementById('pinGo').click(); });
  document.getElementById('pinOut').onclick = function () { setPin(''); hostShow(); };
  document.getElementById('demoToggle').onchange = function () { setDemo(this.checked); };
  document.getElementById('syncState').onchange = function () { lastPushed = null; pushState(); };

  function loadHQ() {
    var box = document.getElementById('hqList');
    admin('questions').then(function (d) {
      box.innerHTML = d.items.length ? d.items.map(function (q) {
        return '<div class="hq' + (q.status === 2 ? ' hid' : '') + '"><b>' + q.votes + '▲</b><span>' + esc(q.text) + (q.status === 1 ? ' <span style="color:var(--luc)">✓</span>' : '') + '</span><span class="row">' +
          '<button class="btn sm" data-q="' + q.id + '" data-s="' + (q.status === 1 ? 0 : 1) + '">' + (q.status === 1 ? 'Bỏ ✓' : '✓ Đã trả lời') + '</button>' +
          '<button class="btn sm warn" data-q="' + q.id + '" data-s="' + (q.status === 2 ? 0 : 2) + '">' + (q.status === 2 ? 'Hiện lại' : 'Ẩn') + '</button></span></div>';
      }).join('') : '<p class="muted">Chưa có câu hỏi.</p>';
    }).catch(function (e) { box.innerHTML = '<p style="color:var(--son)">' + esc(e.message) + '</p>'; });
  }
  document.getElementById('hqLoad').onclick = loadHQ;
  document.getElementById('hqList').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-q]'); if (!b) return;
    admin('qstatus', { qid: +b.dataset.q, status: +b.dataset.s }).then(function () { loadHQ(); pollLive(); }).catch(function (er) { msg('hostMsg', er.message, true); });
  });
  Array.prototype.forEach.call(document.querySelectorAll('[data-detail]'), function (b) {
    b.onclick = function () {
      var box = document.getElementById('hdList');
      admin('details', { act: b.dataset.detail }).then(function (d) {
        var t = d.texts || [];
        if (b.dataset.detail === 'muctieu') {
          box.innerHTML = t.length ? t.map(function (g) { var ok = (g.f || []).filter(Boolean).length; return '<div class="goal-txt"><b>' + ok + '/5</b> · ' + esc(g.text) + '</div>'; }).join('') : '<p class="muted">Chưa có mục tiêu.</p>';
        } else {
          box.innerHTML = t.length ? t.map(function (x) { return '<div class="goal-txt">' + esc(x) + '</div>'; }).join('') : '<p class="muted">Chưa có góp ý bằng chữ.</p>';
        }
      }).catch(function (e) { box.innerHTML = '<p style="color:var(--son)">' + esc(e.message) + '</p>'; });
    };
  });
  function download(name, text, type) {
    var blob = new Blob([text], { type: type });
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name;
    document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
  }
  function stamp() { var d = new Date(); return d.getFullYear() + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0') + '-' + String(d.getHours()).padStart(2, '0') + String(d.getMinutes()).padStart(2, '0'); }
  document.getElementById('expJson').onclick = function () {
    admin('export').then(function (d) { download('workshop-k26-' + stamp() + '.json', JSON.stringify(d, null, 2), 'application/json'); }).catch(function (e) { msg('hostMsg', e.message, true); });
  };
  document.getElementById('expCsv').onclick = function () {
    admin('export').then(function (d) {
      var R = d.results, rows = [['Hoạt động', 'Mục', 'Giá trị / Số phiếu']];
      function push(a, b, c) { rows.push([a, b, c]); }
      push('Khởi động', 'Số phiếu', R.khoidong.n);
      WS.KHOIDONG.cam.opts.forEach(function (o, i) { push('Khởi động · Thời gian', o, R.khoidong.cam[i]); });
      WS.KHOIDONG.man.opts.forEach(function (o, i) { push('Khởi động · Điện thoại hôm qua', o, R.khoidong.man[i]); });
      push('Hình khối', 'Số phiếu', R.hinh.n);
      WS.SHAPE_ORDER.forEach(function (k) { push('Hình khối · chọn 1', WS.SHAPES[k].name, R.hinh.top[k]); push('Hình khối · chọn 2', WS.SHAPES[k].name, R.hinh.second[k]); });
      push('Eisenhower', 'Số phiếu', R.eisen.n);
      WS.EISEN.forEach(function (c) { [0, 1, 2, 3].forEach(function (q) { push('Eisenhower · ' + c.text, 'Ô ' + (q + 1) + (c.q === q + 1 ? ' (gợi ý)' : ''), R.eisen.cards[c.id][q]); }); });
      push('Bánh xe', 'Số phiếu', R.banhxe.n);
      WS.WHEEL.forEach(function (w, i) { push('Bánh xe · trung bình', w, R.banhxe.avg[i]); push('Bánh xe · số bạn chấm ≤ 4', w, R.banhxe.low[i]); });
      push('Mục tiêu SMART', 'Số phiếu', R.muctieu.n);
      WS.SMART.forEach(function (s, i) { push('Mục tiêu SMART · tự kiểm', s.k + ' – ' + s.name, R.muctieu.f[i]); });
      push('Mục tiêu SMART', 'Đủ 5 tiêu chí', R.muctieu.full);
      (R.muctieu.texts || []).forEach(function (g) { push('Mục tiêu SMART · nội dung', (g.f || []).join(''), g.text); });
      push('Cam kết', 'Số phiếu', R.camket.n);
      WS.PLEDGES.forEach(function (p, i) { push('Cam kết', p, R.camket.count[i]); });
      push('Góp ý', 'Số phiếu', R.danhgia.n);
      [1, 2, 3, 4, 5].forEach(function (s) { push('Góp ý · số sao', s + ' sao', R.danhgia.sao[s - 1]); });
      (R.danhgia.texts || []).forEach(function (t) { push('Góp ý · mang về', '', t); });
      (d.questions || []).forEach(function (q) { push('Câu hỏi Q&A', q.votes + ' ▲' + (q.status === 1 ? ' · đã trả lời' : q.status === 2 ? ' · đã ẩn' : ''), q.text); });
      var csv = '﻿' + rows.map(function (r) { return r.map(function (v) { v = String(v == null ? '' : v); return /[",\n;]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }).join(','); }).join('\r\n');
      download('workshop-k26-' + stamp() + '.csv', csv, 'text/csv;charset=utf-8');
    }).catch(function (e) { msg('hostMsg', e.message, true); });
  };
  document.getElementById('expNc').onclick = function () {
    admin('research_csv').then(function (d) {
      var c = d.counts;
      msg('hostMsg', 'Dữ liệu nghiên cứu: ' + c.nguoi + ' người · đầu buổi ' + c.nc0 + ' · cuối buổi ' + c.nc1 + ' · sau 2 tuần ' + c.nc2 + ' · sau 6 tuần ' + c.nc3);
      download('nghien-cuu-k26-' + stamp() + '.csv', d.csv, 'text/csv;charset=utf-8');
    }).catch(function (e) { msg('hostMsg', e.message, true); });
  };
  document.getElementById('rsGo').onclick = function () {
    var w = document.getElementById('rsWhat');
    var label = w.options[w.selectedIndex].text;
    if (!confirm('Xóa dữ liệu: ' + label + '?\nKhông khôi phục được. Nên tải CSV trước nếu cần.')) return;
    if (w.value === 'nghiencuu' && prompt('Xóa TOÀN BỘ phiếu nghiên cứu (đầu buổi, cuối buổi, theo dõi)? Gõ XOA để xác nhận.') !== 'XOA') return;
    admin('reset', { what: w.value }).then(function () { msg('hostMsg', '✅ Đã xóa: ' + label); pollLive(); }).catch(function (e) { msg('hostMsg', e.message, true); });
  };

  /* ───────── hộp thoại, đồng hồ, phím tắt ───────── */
  function openModal(id) { document.getElementById(id).classList.add('on'); if (id === 'mHost') hostShow(); if (id === 'mOverview') buildOverview(); }
  function closeModals() { Array.prototype.forEach.call(document.querySelectorAll('.modal.on'), function (m) { m.classList.remove('on'); }); }
  Array.prototype.forEach.call(document.querySelectorAll('.modal'), function (m) {
    m.addEventListener('click', function (e) { if (e.target === m || e.target.hasAttribute('data-close')) closeModals(); });
  });
  function buildOverview() {
    document.getElementById('ovList').innerHTML = S.map(function (s, i) {
      return '<button type="button" data-go="' + i + '"' + (i === cur ? ' class="cur"' : '') + '><small>' + (i + 1) + ' · ' + (s.divider ? 'Phần ' + s.divider : (s.kicker || SEC[s.sec])) + '</small>' + (s.act ? '📱 ' : '') + esc(s.title) + '</button>';
    }).join('');
  }
  document.getElementById('ovList').addEventListener('click', function (e) { var b = e.target.closest('[data-go]'); if (b) { closeModals(); go(+b.dataset.go); } });

  var tLeft = 0, tRun = false, tInt = null;
  function tShow() { var m = Math.floor(tLeft / 60), s = tLeft % 60; document.getElementById('timerV').textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0'); }
  function beep() { try { var c = new (window.AudioContext || window.webkitAudioContext)(); [0, .35, .7].forEach(function (t) { var o = c.createOscillator(), g = c.createGain(); o.frequency.value = 880; o.connect(g); g.connect(c.destination); g.gain.setValueAtTime(.25, c.currentTime + t); g.gain.exponentialRampToValueAtTime(.001, c.currentTime + t + .3); o.start(c.currentTime + t); o.stop(c.currentTime + t + .3); }); } catch (e) {} }
  function timerStart(min) {
    var el = document.getElementById('timer');
    tLeft = Math.round(min * 60); tRun = true; el.classList.add('on'); el.classList.remove('done'); tShow();
    document.getElementById('timerP').textContent = 'Tạm dừng';
    clearInterval(tInt);
    tInt = setInterval(function () { if (!tRun) return; tLeft--; tShow(); if (tLeft <= 0) { clearInterval(tInt); tRun = false; el.classList.add('done'); beep(); } }, 1000);
  }
  document.getElementById('timerP').onclick = function () { tRun = !tRun; this.textContent = tRun ? 'Tạm dừng' : 'Tiếp tục'; };
  document.getElementById('timerX').onclick = function () { clearInterval(tInt); document.getElementById('timer').classList.remove('on'); };

  function toggleFull() { if (!document.fullscreenElement) { (document.documentElement.requestFullscreen || function () {}).call(document.documentElement); } else { document.exitFullscreen(); } }
  function openNotes() { window.open(location.pathname + '?view=notes#' + (cur + 1), 'ws26notes', 'width=1000,height=760'); }

  var black = false, digits = '';
  function act2(k) {
    if (k === 'next') go(cur + 1); else if (k === 'prev') go(cur - 1);
    else if (k === 'overview') openModal('mOverview'); else if (k === 'host') openModal('mHost');
    else if (k === 'timer') timerStart(+digits || 5);
    else if (k === 'notes') openNotes(); else if (k === 'full') toggleFull();
    digits = '';
  }
  document.getElementById('ctrl').addEventListener('click', function (e) { var b = e.target.closest('[data-k]'); if (b) act2(b.dataset.k); });

  document.addEventListener('keydown', function (e) {
    if (e.target.closest('input, textarea, select')) return;
    if (document.querySelector('.modal.on')) { if (e.key === 'Escape') closeModals(); return; }
    var k = e.key;
    if (/^[0-9]$/.test(k)) { digits = (digits + k).slice(-2); return; }
    if (k === 'ArrowRight' || k === 'PageDown' || k === ' ' || k === 'Enter') { e.preventDefault(); act2('next'); }
    else if (k === 'ArrowLeft' || k === 'PageUp' || k === 'Backspace') { e.preventDefault(); act2('prev'); }
    else if (k === 'Home') go(0); else if (k === 'End') go(S.length - 1);
    else if (k === 'f' || k === 'F') act2('full');
    else if (k === 'o' || k === 'O') act2('overview');
    else if (k === 't' || k === 'T') act2('timer');
    else if (k === 'p' || k === 'P') act2('notes');
    else if (k === 'h' || k === 'H') act2('host');
    else if (k === 'b' || k === 'B' || k === '.') { black = !black; document.getElementById('stage').style.opacity = black ? 0 : 1; }
  });

  /* chạm/vuốt trên máy tính bảng */
  var tx = null;
  document.getElementById('stage').addEventListener('touchstart', function (e) { tx = e.touches[0].clientX; }, { passive: true });
  document.getElementById('stage').addEventListener('touchend', function (e) { if (tx == null) return; var dx = e.changedTouches[0].clientX - tx; if (Math.abs(dx) > 50) go(cur + (dx < 0 ? 1 : -1)); tx = null; });

  var hideT = null;
  document.addEventListener('mousemove', function () { document.body.classList.add('show-ctrl'); clearTimeout(hideT); hideT = setTimeout(function () { document.body.classList.remove('show-ctrl'); }, 2200); });

  /* bản in: dựng toàn bộ slide (Ctrl+P) */
  window.addEventListener('beforeprint', function () {
    document.getElementById('print').innerHTML = S.map(slideHtml).join('');
    Array.prototype.forEach.call(document.querySelectorAll('#print .live-body'), function (b) { b.innerHTML = '<p class="muted">Kết quả hiển thị trực tiếp tại hội trường.</p>'; });
  });

  /* ───────── cửa sổ ghi chú diễn giả ───────── */
  function notesView() {
    document.body.classList.add('notes-view');
    document.body.innerHTML = '<div class="notes-wrap"><div class="nbar"><button class="btn" id="nP">‹ Trước</button><button class="btn pri" id="nN">Sau ›</button><span class="muted" id="nPos"></span><span class="clock" id="nClock">00:00</span><button class="btn" id="nR" title="Đặt lại đồng hồ">↺</button></div>' +
      '<p class="muted" id="nSec" style="margin:0"></p><h1 id="nTitle"></h1><p class="next" id="nNext"></p><div class="notes" id="nText"></div></div>';
    var i = Math.max(0, Math.min(S.length - 1, (parseInt(location.hash.slice(1), 10) || 1) - 1));
    function show() {
      var s = S[i];
      document.getElementById('nPos').textContent = 'Slide ' + (i + 1) + ' / ' + S.length + (s.act ? ' · 📱 có bình chọn' : '');
      document.getElementById('nSec').textContent = s.divider ? 'Phần ' + s.divider : (s.kicker || SEC[s.sec]);
      document.getElementById('nTitle').textContent = s.title;
      document.getElementById('nNext').textContent = S[i + 1] ? 'Tiếp theo: ' + S[i + 1].title : 'Slide cuối';
      document.getElementById('nText').textContent = s.notes || '(Không có ghi chú)';
    }
    function nav(d) { i = Math.max(0, Math.min(S.length - 1, i + d)); show(); if (chan) chan.postMessage({ t: 'go', i: i }); }
    document.getElementById('nP').onclick = function () { nav(-1); };
    document.getElementById('nN').onclick = function () { nav(1); };
    document.addEventListener('keydown', function (e) { if (e.key === 'ArrowRight' || e.key === 'PageDown' || e.key === ' ') { e.preventDefault(); nav(1); } if (e.key === 'ArrowLeft' || e.key === 'PageUp') { e.preventDefault(); nav(-1); } });
    if (chan) chan.onmessage = function (m) { if (m.data && m.data.t === 'pos') { i = m.data.i; show(); } };
    var t0 = Date.now();
    document.getElementById('nR').onclick = function () { t0 = Date.now(); };
    setInterval(function () { var s = Math.floor((Date.now() - t0) / 1000); document.getElementById('nClock').textContent = String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0'); }, 1000);
    show();
  }

  if (isNotes) { notesView(); return; }
  if (chan) chan.onmessage = function (m) { if (m.data && m.data.t === 'go') go(m.data.i, true); };
  window.addEventListener('resize', fit);
  window.addEventListener('hashchange', function () { var n = (parseInt(location.hash.slice(1), 10) || 1) - 1; if (n !== cur) go(n); });
  fit();
  if (params.get('demo') === '1') setDemo(true);
  cur = -1;
  go((parseInt(location.hash.slice(1), 10) || 1) - 1);
})();
