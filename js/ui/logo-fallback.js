// js/ui/logo-fallback.js — chỉ UI: ẩn ảnh logo nếu tải lỗi.
// Không dùng inline onerror trên <img> — bắt sự kiện 'error' ở capture phase
// (sự kiện 'error' của <img> không nổi bọt — phải bắt ở capture mới chắc ăn,
// và phải gắn sớm trong <head> trước khi ảnh bắt đầu tải).
// Không phải logic nghiệp vụ — xem PR "agent/web-ui-logo-footer".
document.addEventListener('error', function (e) {
  var el = e.target;
  if (el && el.tagName === 'IMG' && el.classList.contains('logo-img')) {
    el.style.display = 'none';
  }
}, true);
