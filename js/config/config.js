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
 * GĐ9 — CHẠY THỬ SONG SONG hai backend trên cùng trang web.
 *
 *   ?api=gas  (hoặc không có tham số)  → Apps Script, tức bản ĐANG CHẠY THẬT
 *   ?api=php                           → backend PHP mới trên cùng domain
 *
 * CHỈ nhận hai giá trị trên. KHÔNG nhận URL tuỳ ý (?api=https://...): nếu nhận,
 * ai gửi cho sinh viên/giảng viên một đường link có URL lạ là lấy được MSSV, mã
 * điểm danh, cả mật khẩu giảng viên — trang vẫn đúng tên miền thật nên không ai
 * nghi. Thêm backend mới thì thêm vào API_TARGETS ở đây, không nhận từ URL.
 *
 * Lựa chọn được GHI NHỚ trong sessionStorage để còn giữ khi bấm qua trang khác
 * (student.html → diem.html…); mở tab mới là về mặc định Apps Script. Đang ở
 * chế độ PHP thì có dải băng vàng trên cùng mọi trang để không ai lẫn với thật.
 *
 * GĐ10 (cutover): đổi API_TARGETS.gas thành URL PHP, hoặc đổi DEFAULT_API sang
 * 'php' — xem docs/06-GD9-staging-checklist.md.
 */
const API_TARGETS = {
  gas: CONFIG.API_URL,
  php: 'https://diemdanhsv.com/api/index.php'
};
const DEFAULT_API = 'gas';

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
    bar.textContent = 'CHẾ ĐỘ CHẠY THỬ — đang gọi backend PHP mới (?api=php). ' +
      'Dữ liệu nhập ở đây KHÔNG vào hệ thống đang dùng thật.';
    const back = document.createElement('a');
    back.href = window.location.pathname + '?api=gas';
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
