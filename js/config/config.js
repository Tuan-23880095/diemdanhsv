/* js/config/config.js — Cấu hình frontend */

const CONFIG = {
  // URL của bản triển khai "diemdanh-03" (Triển khai → Quản lý các tùy chọn
  // triển khai). Khi sửa code backend: bấm BÚT CHÌ trên đúng bản này →
  // Phiên bản: "Phiên bản mới" → Triển khai. URL giữ nguyên, không phải sửa
  // lại dòng dưới. TUYỆT ĐỐI không bấm "Tùy chọn triển khai mới" — cái đó đẻ
  // ra URL khác, còn dòng này vẫn trỏ bản cũ đóng băng ở code cũ (đã mất cả
  // buổi vì lỗi này ngày 13/09/2026).
  // Kiểm chứng sau mỗi lần deploy: mở <API_URL>?action=ping, đối chiếu version.
  API_URL: 'https://script.google.com/macros/s/AKfycbw-8TxqTk8rzBFwZRKEr6B-jNmf0z5nA2nI_PDSJyIAQXKj1sdJli5Q28ZC4gRfAQoZBA/exec',

  SCHOOL_NAME: 'Trường Đại học Khoa học Tự nhiên, ĐHQG-HCM',
  SITE_NAME: 'Hệ thống điểm danh sinh viên',

  CODE_LENGTH: 4,
  ROSTER_POLL_MS: 10000,     // nhịp làm mới danh sách thời gian thực (D.8-5)
  GPS_TIMEOUT_MS: 10000
};

/*
 * GĐ10 — CUTOVER (02/10/2026): mặc định gọi backend PHP trên cùng domain.
 *
 *   ?api=php  (hoặc không có tham số)  → backend PHP (MariaDB, Hostinger) — BẢN THẬT
 *   ?api=gas                           → Apps Script cũ, giữ làm ĐƯỜNG LÙI ≥ 1 tuần
 *                                        sau cutover; sau đó mới gỡ (xem docs/06).
 *
 * Nguồn gốc cơ chế (GĐ9 — chạy thử song song hai backend trên cùng trang web):
 *
 * CHỈ nhận hai giá trị trên. KHÔNG nhận URL tuỳ ý (?api=https://...): nếu nhận,
 * ai gửi cho sinh viên/giảng viên một đường link có URL lạ là lấy được MSSV, mã
 * điểm danh, cả mật khẩu giảng viên — trang vẫn đúng tên miền thật nên không ai
 * nghi. Thêm backend mới thì thêm vào API_TARGETS ở đây, không nhận từ URL.
 *
 * Lựa chọn được GHI NHỚ trong sessionStorage để còn giữ khi bấm qua trang khác
 * (student.html → diem.html…); mở tab mới là về mặc định (PHP). Không ở backend
 * mặc định thì có dải băng vàng trên cùng mọi trang để không ai lẫn với thật.
 *
 * Mỗi lần sửa file này PHẢI đổi ?v= của config.js trong 4 trang pages/*.html,
 * nếu không máy sinh viên vẫn chạy bản cũ trong cache (docs/06, bước GĐ10).
 */
const API_TARGETS = {
  gas: CONFIG.API_URL,
  php: 'https://diemdanhsv.com/api/index.php'
};
const DEFAULT_API = 'php';

/* Dải băng cảnh báo khi KHÔNG ở backend mặc định — nội dung theo backend đang gọi. */
const API_MODE_BANNER = {
  gas: 'ĐANG DÙNG BẢN DỰ PHÒNG (Apps Script cũ, ?api=gas). Dữ liệu nhập ở đây KHÔNG vào hệ thống mới.',
  php: 'CHẾ ĐỘ CHẠY THỬ — đang gọi backend PHP (?api=php). Dữ liệu nhập ở đây KHÔNG vào hệ thống đang dùng thật.'
};

(function applyApiChoice() {
  let choice = DEFAULT_API;
  try {
    const asked = new URLSearchParams(window.location.search).get('api');
    const saved = sessionStorage.getItem('dd_api');
    if (asked && Object.prototype.hasOwnProperty.call(API_TARGETS, asked)) {
      choice = asked;
      sessionStorage.setItem('dd_api', choice);
    } else if (saved && Object.prototype.hasOwnProperty.call(API_TARGETS, saved)) {
      choice = saved;
    }
  } catch (err) {
    // sessionStorage bị tắt (chế độ riêng tư) → dùng mặc định, không làm sập trang
  }

  CONFIG.API_MODE = choice;
  CONFIG.API_URL = API_TARGETS[choice];

  if (choice === DEFAULT_API) return;

  // Dải băng cảnh báo — chèn khi DOM sẵn sàng, không phụ thuộc trang nào
  const show = function () {
    if (document.getElementById('api-mode-banner')) return;
    const bar = document.createElement('div');
    bar.id = 'api-mode-banner';
    bar.setAttribute('role', 'status');
    bar.style.cssText = 'position:sticky;top:0;z-index:50;background:#b45309;color:#fff;' +
      'padding:6px 12px;font-size:13px;text-align:center;font-weight:600';
    bar.textContent = API_MODE_BANNER[choice] || ('Đang gọi backend "' + choice + '" (không phải mặc định).');
    const back = document.createElement('a');
    back.href = window.location.pathname + '?api=' + DEFAULT_API;
    back.textContent = ' Quay lại bản thật';
    back.style.cssText = 'color:#fff;text-decoration:underline;margin-left:8px';
    bar.appendChild(back);
    document.body.insertBefore(bar, document.body.firstChild);
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', show);
  } else {
    show();
  }
})();

const STATUS_TEXT = {
  PRESENT: 'Có mặt',
  LATE: 'Trễ',
  ABSENT: 'Vắng',
  EXCUSED: 'Vắng có phép'
};

const GPS_TEXT = {
  VALID: 'Trong khu vực lớp',
  OUT_OF_RANGE: 'Ngoài bán kính cho phép',
  LOW_ACCURACY: 'GPS sai số lớn',
  NO_GPS: 'Không có GPS'
};
