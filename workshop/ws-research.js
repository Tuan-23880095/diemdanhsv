/* ws-research.js — bộ công cụ đo của nghiên cứu đi kèm Workshop K2026.
 * Một nguồn duy nhất cho trang SV (sv.html), trang theo dõi (theo-doi.html) và trang thông tin (nghien-cuu.html).
 * Phạm vi giá trị phải khớp WS_NC_SPEC trong nc-lib.php.
 *
 * Thiết kế: một nhóm, đo trước–sau + theo dõi (T0 đầu buổi, T1 cuối buổi, T2 sau 2 tuần, T3 sau 6 tuần),
 * khung đánh giá Kirkpatrick mức 1–3. Liên kết các đợt bằng mã ẩn danh tự tạo (SGIC). */
(function (global) {
  'use strict';
  var R = {};

  R.CONTACT = 'ThS. Đinh Quốc Tuấn — dqtuan@hcmus.edu.vn';
  R.TITLE = 'Kỹ năng quản lý thời gian của tân sinh viên: thực trạng, kết quả học tập kỹ năng và mức độ vận dụng sau workshop';
  R.WAVES = {
    nc0: { name: 'Khảo sát đầu buổi', when: 'Trước khi workshop bắt đầu', mins: 5 },
    nc1: { name: 'Khảo sát cuối buổi', when: 'Cuối workshop', mins: 3 },
    nc2: { name: 'Khảo sát sau 2 tuần', when: '01/11 – 10/11/2026', mins: 3, open: '2026-11-01', url: 'https://diemdanhsv.com/workshop/theo-doi.html?dot=2' },
    nc3: { name: 'Khảo sát sau 6 tuần', when: '29/11 – 10/12/2026', mins: 5, open: '2026-11-29', url: 'https://diemdanhsv.com/workshop/theo-doi.html?dot=3' }
  };

  R.CONSENT_SHORT = [
    'Khảo sát này phục vụ một nghiên cứu giáo dục về kỹ năng quản lý thời gian của tân sinh viên.',
    'Tham gia hoàn toàn <b>tự nguyện</b>. Không tham gia hoặc dừng giữa chừng không ảnh hưởng gì đến bạn; bạn vẫn dùng mọi hoạt động của workshop bình thường.',
    'Khảo sát <b>ẩn danh</b>: không hỏi họ tên, MSSV, email. Kết quả chỉ công bố dưới dạng tổng hợp.',
    'Có 4 đợt ngắn: đầu buổi, cuối buổi, sau 2 tuần và sau 6 tuần. Mã ẩn danh bạn tự tạo giúp nối các đợt mà không cần biết bạn là ai.',
    'Muốn rút dữ liệu: gửi mã ẩn danh của bạn tới ' + 'dqtuan@hcmus.edu.vn' + ' trước ngày 31/12/2026.'
  ];

  /* Mã ẩn danh tự tạo (self-generated identification code) */
  R.SGIC_HELP = 'Ghép 3 phần: <b>2 chữ cái đầu tên của mẹ</b> (không dấu) + <b>ngày sinh của bạn</b> (2 số) + <b>2 số cuối số điện thoại của bạn</b>. Ví dụ: mẹ tên Lan, bạn sinh ngày 7, số điện thoại tận cùng 39 → <b>LA0739</b>.';
  R.normSgic = function (a, b, c) {
    function strip(s) { return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[đĐ]/g, 'D').toUpperCase().replace(/[^A-Z]/g, ''); }
    var l = strip(a).slice(0, 2);
    var d = String(b || '').replace(/\D/g, ''); if (d.length === 1) d = '0' + d;
    var p = String(c || '').replace(/\D/g, '').slice(-2);
    var code = l + d + p;
    return /^[A-Z]{2}(0[1-9]|[12]\d|3[01])\d{2}$/.test(code) ? code : '';
  };

  /* Thông tin nền (T0) — giá trị = chỉ số lựa chọn */
  R.DEMO = [
    { k: 'gioi', q: 'Giới tính', o: ['Nam', 'Nữ', 'Khác', 'Không muốn trả lời'] },
    { k: 'nganh', q: 'Nhóm ngành của bạn', o: ['Toán – Thống kê – Khoa học dữ liệu', 'Công nghệ thông tin – Trí tuệ nhân tạo', 'Vật lý – Điện tử – Hạt nhân – Vật lý y khoa – Bán dẫn', 'Hóa học – Công nghệ hóa học', 'Sinh học – Công nghệ sinh học', 'Khoa học & Công nghệ vật liệu', 'Địa chất – Hải dương – Môi trường – Tài nguyên', 'Công nghệ giáo dục', 'Khác', 'Không muốn trả lời'] },
    { k: 'noio', q: 'Bạn đang ở', o: ['Cùng gia đình', 'Ký túc xá', 'Nhà trọ', 'Nhà người thân', 'Khác'] },
    { k: 'lamthem', q: 'Làm thêm mỗi tuần', o: ['Không làm thêm', 'Dưới 10 giờ', '10–20 giờ', 'Trên 20 giờ'] },
    { k: 'tinchi', q: 'Số tín chỉ đăng ký học kỳ này', o: ['Dưới 15', '15–18', '19–22', 'Trên 22', 'Không rõ'] },
    { k: 'coso', q: 'Bạn học chủ yếu ở', o: ['Cơ sở Linh Trung', 'Cơ sở Nguyễn Văn Cừ', 'Cả hai cơ sở'] },
    { k: 'dahoc', q: 'Trước đây bạn đã từng học hoặc tham gia một khóa/buổi về quản lý thời gian chưa?', o: ['Chưa', 'Rồi'] }
  ];

  /* Time Management Questionnaire — Britton & Tesser (1991), 18 câu, 3 thang con.
   * Thứ tự, phân nhóm và câu đảo chiều đã đối chiếu với Bảng 1 của bài gốc (J. Educ. Psychol., 83(3), 405–410):
   * câu 8, 10, 11, 13, 16 chấm đảo (6 − x); câu 17 chấm thuận như bài gốc (theo dõi tương quan biến–tổng khi phân tích).
   * Bản dịch tiếng Việt vẫn cần dịch ngược độc lập trước khi công bố.
   * s: SRP = lập kế hoạch ngắn hạn, TA = thái độ với thời gian, LRP = lập kế hoạch dài hạn; r: 1 = câu đảo chiều. */
  R.TMQ_SCALE = ['Không bao giờ', 'Hiếm khi', 'Thỉnh thoảng', 'Thường xuyên', 'Luôn luôn']; // lưu 1–5
  R.TMQ = [
    { s: 'SRP', q: 'Bạn có lập danh sách những việc cần làm mỗi ngày không?' },
    { s: 'SRP', q: 'Bạn có lên kế hoạch cho một ngày trước khi bắt đầu ngày đó không?' },
    { s: 'SRP', q: 'Bạn có lập lịch các việc phải làm trong những ngày đi học không?' },
    { s: 'SRP', q: 'Bạn có đặt mục tiêu cho bản thân cho từng ngày không?' },
    { s: 'SRP', q: 'Mỗi ngày, bạn có dành thời gian để lập kế hoạch không?' },
    { s: 'SRP', q: 'Bạn có biết rõ mình muốn hoàn thành những gì trong tuần tới không?' },
    { s: 'SRP', q: 'Bạn có đặt thứ tự ưu tiên cho các việc và làm theo thứ tự đó không?' },
    { s: 'TA', q: 'Bạn có hay làm những việc ảnh hưởng đến việc học chỉ vì ngại nói “không” với người khác không?', r: 1 },
    { s: 'TA', q: 'Nhìn chung, bạn có cảm thấy mình làm chủ được thời gian của mình không?' },
    { s: 'TA', q: 'Trong một ngày đi học bình thường, bạn có dành cho việc chăm chút cá nhân (ăn mặc, ngoại hình…) nhiều thời gian hơn cho việc học không?', r: 1 },
    { s: 'TA', q: 'Bạn có nghĩ cách mình quản lý thời gian vẫn còn chỗ cần cải thiện không?', r: 1 },
    { s: 'TA', q: 'Bạn có sử dụng thời gian của mình một cách hữu ích không?' },
    { s: 'TA', q: 'Bạn có tiếp tục những thói quen hoặc hoạt động không mang lại lợi ích không?', r: 1 },
    { s: 'LRP', q: 'Bạn có thường giữ bàn học gọn, chỉ để những thứ phục vụ việc đang làm không?' },
    { s: 'LRP', q: 'Bạn có đặt ra một bộ mục tiêu cho cả học kỳ không?' },
    { s: 'LRP', q: 'Vào đêm trước hạn nộp một bài lớn, bạn có thường vẫn còn đang làm dở không?', r: 1 },
    { s: 'LRP', q: 'Khi có nhiều việc phải làm, bạn có cho rằng tốt nhất là mỗi việc làm một ít không?' },
    { s: 'LRP', q: 'Bạn có thường xem lại bài ghi trên lớp ngay cả khi chưa sắp kiểm tra không?' }
  ];

  /* Tự tin về quản lý thời gian (thang tự xây dựng theo hướng dẫn của Bandura, 2006) */
  R.SE_SCALE = ['Hoàn toàn không tự tin', 'Ít tự tin', 'Tương đối', 'Khá tự tin', 'Rất tự tin']; // 1–5
  R.SE = [
    'Viết được một mục tiêu học tập cụ thể và đo được',
    'Phân biệt được việc quan trọng với việc chỉ khẩn cấp',
    'Lập một kế hoạch tuần thực tế và làm theo được phần lớn',
    'Từ chối được lời nhờ vả làm lệch kế hoạch của mình',
    'Bắt đầu ngay một việc khó thay vì trì hoãn'
  ];

  /* Bài kiểm tra kiến thức (T0 và T1, cùng câu hỏi). a = chỉ số đáp án đúng. */
  R.KT = [
    { q: 'Mục tiêu nào đáp ứng đầy đủ nhất tiêu chí SMART?', o: ['“Học tốt tiếng Anh.”', '“Cố gắng chăm chỉ hơn mỗi ngày.”', '“Trong 8 tuần, mỗi ngày học 20 từ mới và làm 1 bài nghe, để đạt từ 6.0 trong bài thi thử ngày 15/12.”', '“Đạt điểm cao trong kỳ thi tiếng Anh.”'], a: 2 },
    { q: 'Theo ma trận Eisenhower, “đọc trước bài cho buổi học tuần sau” thuộc ô nào?', o: ['Ô 1 — quan trọng và khẩn cấp', 'Ô 2 — quan trọng, không khẩn cấp', 'Ô 3 — khẩn cấp, không quan trọng', 'Ô 4 — không quan trọng, không khẩn cấp'], a: 1 },
    { q: 'Việc “khẩn cấp nhưng không quan trọng” nên được xử lý thế nào?', o: ['Làm ngay lập tức', 'Đặt vào lịch như một cuộc hẹn', 'Giảm bớt, nhờ người khác hoặc từ chối', 'Luôn ưu tiên trước việc quan trọng'], a: 2 },
    { q: 'Một phiên Pomodoro chuẩn gồm:', o: ['25 phút tập trung, nghỉ 5 phút', '60 phút tập trung, nghỉ 10 phút', '45 phút tập trung, nghỉ 15 phút', 'Tập trung đến khi xong việc rồi mới nghỉ'], a: 0 },
    { q: 'Khi lập lịch tuần, cách nào hợp lý nhất?', o: ['Lấp kín mọi khung giờ để không lãng phí', 'Chừa khoảng trống làm vùng đệm cho việc phát sinh', 'Chỉ cần ghi lịch học trên lớp', 'Lập một lần đầu học kỳ và không thay đổi'], a: 1 },
    { q: '“Ăn con ếch” trong quản lý thời gian nghĩa là:', o: ['Làm việc dễ trước để lấy đà', 'Làm việc khó và quan trọng nhất trước', 'Làm nhiều việc cùng lúc', 'Nghỉ ngơi trước khi làm việc khó'], a: 1 },
    { q: 'Theo nghiên cứu của Lally và cộng sự (2010), thời gian trung vị để một hành vi mới trở thành thói quen là khoảng:', o: ['7 ngày', '21 ngày', '66 ngày', '1 năm'], a: 2 },
    { q: 'Theo quy định hiện hành, một tín chỉ tương đương bao nhiêu giờ học tập định mức (gồm cả tự học)?', o: ['15 giờ', '30 giờ', '50 giờ', '100 giờ'], a: 2 }
  ];

  /* Phản hồi (Kirkpatrick mức 1) và ý định vận dụng — T1 */
  R.AGREE = ['Hoàn toàn không đồng ý', 'Không đồng ý', 'Phân vân', 'Đồng ý', 'Hoàn toàn đồng ý']; // 1–5
  R.RE = [
    'Nội dung workshop hữu ích với tôi',
    'Nội dung phù hợp với hoàn cảnh của tân sinh viên',
    'Các hoạt động trên điện thoại giúp tôi hiểu bài hơn',
    'Tôi sẽ giới thiệu buổi workshop này cho bạn bè'
  ];
  R.YD = [
    'Tôi dự định áp dụng ít nhất một kỹ thuật đã học trong 2 tuần tới',
    'Tôi dự định lập kế hoạch cho tuần tới',
    'Tôi tin rằng áp dụng các kỹ thuật này sẽ giúp việc học của tôi tốt hơn'
  ];

  /* Vận dụng (Kirkpatrick mức 3) — T2, T3 */
  R.AD_SCALE = ['Chưa lần nào', '1–2 lần', 'Vài lần mỗi tuần', 'Gần như mỗi ngày']; // 0–3
  R.AD = [
    'Viết ra mục tiêu cụ thể, đo được cho việc học (SMART)',
    'Phân loại việc theo mức quan trọng / khẩn cấp (ma trận Eisenhower)',
    'Lập lịch tuần, đặt khối giờ tự học',
    'Rà soát tuần: nhìn lại tuần qua và lên kế hoạch tuần sau',
    'Pomodoro: làm tập trung theo phiên, có giờ nghỉ',
    'Quy tắc 2 phút: việc dưới 2 phút làm luôn',
    '“Ăn con ếch”: làm việc khó nhất trước'
  ];
  R.CK = ['0 ngày', '1 ngày', '2 ngày', '3 ngày', '4 ngày', '5 ngày', '6 ngày', 'Cả 7 ngày', 'Tôi không chọn cam kết nào'];
  R.TR = ['Quên mất', 'Không đủ thời gian', 'Lịch học / làm thêm thay đổi', 'Thiếu động lực', 'Kỹ thuật không hợp với tôi', 'Không biết bắt đầu từ đâu', 'Bị cuốn vào điện thoại, mạng xã hội', 'Khác'];
  R.THAYDOI = ['Kém hơn nhiều', 'Kém hơn một chút', 'Không thay đổi', 'Tốt hơn một chút', 'Tốt hơn nhiều']; // 1–5

  global.WSR = R;
})(window);
