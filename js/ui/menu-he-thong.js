/**
 * menu-he-thong.js — MENU XỔ XUỐNG CHỨA TẤT CẢ CÁC TRANG của diemdanhsv.com và bdvh.diemdanhsv.com.
 * ThS. Đinh Quốc Tuấn · Trường ĐH Khoa học Tự nhiên, ĐHQG-HCM
 *
 * MỘT NGUỒN DUY NHẤT: thêm / bớt / đổi tên trang → chỉ sửa mảng NHOM bên dưới. Mọi trang có nhúng file này
 * (kể cả bdvh.diemdanhsv.com, nhúng qua https://diemdanhsv.com/js/ui/menu-he-thong.js) tự cập nhật theo.
 *
 * Cách gắn vào một trang:
 *   <span data-menu-he-thong></span>                       ← chỗ đặt nút "Tất cả trang" (trong header)
 *   <script src="https://diemdanhsv.com/js/ui/menu-he-thong.js" defer></script>
 * Không có chỗ đặt → nút tự gắn vào cuối thẻ <header> đầu tiên.
 */
(function () {
  'use strict';
  if (window.__menuHeThong) return;
  window.__menuHeThong = true;

  const D = 'https://diemdanhsv.com/';
  const B = 'https://bdvh.diemdanhsv.com/';

  // ai: SV = sinh viên · HS = học sinh · GV = giảng viên / giáo viên · '' = mọi người
  const NHOM = [
    { ten: 'Điểm danh & điểm', muc: [
      { ten: 'Trang chủ diemdanhsv.com', url: D + 'index.html', ai: '' },
      { ten: 'Điểm danh', url: D + 'pages/student.html', ai: 'SV' },
      { ten: 'Xem điểm', url: D + 'pages/diem.html', ai: 'SV', mo_ta: 'mã qua email' },
      { ten: 'Bảng điều khiển giảng viên', url: D + 'pages/lecturer.html', ai: 'GV' },
      { ten: 'Quản trị môn, lớp, điểm', url: D + 'pages/admin.html', ai: 'GV' },
      { ten: 'Lưu trữ cuối học kỳ', url: D + 'pages/luu-tru.html', ai: 'GV' },
    ] },
    { ten: 'Kiểm tra trực tuyến', muc: [
      { ten: 'Vào phòng kiểm tra', url: D + 'kiemtra/', ai: 'SV' },
      { ten: 'Ra đề, mở phòng, giám sát', url: D + 'kiemtra/gv.html', ai: 'GV' },
    ] },
    { ten: 'Đá và Khoáng vật (LEC10001)', muc: [
      { ten: 'Trang học phần', url: D + 'dkv/index.html', ai: 'SV' },
      { ten: 'Làm phiếu online', url: D + 'dkv/online.html', ai: 'SV' },
      { ten: 'Phòng học trực tiếp', url: D + 'dkv/sv.html', ai: 'SV' },
      { ten: 'Giảng viên', url: D + 'dkv/gv.html', ai: 'GV' },
      { ten: 'Trình chiếu', url: D + 'dkv/trinhchieu.html', ai: 'GV' },
      { ten: 'Quản lý', url: D + 'dkv/quanly.html', ai: 'GV' },
    ] },
    { ten: 'Thực tập Khoa học Trái đất', muc: [
      { ten: 'Trang học phần', url: D + 'khtd/index.html', ai: 'SV' },
      { ten: 'Làm phiếu online', url: D + 'khtd/online.html', ai: 'SV' },
      { ten: 'Giảng viên', url: D + 'khtd/gv.html', ai: 'GV' },
      { ten: 'Quản lý', url: D + 'khtd/quanly.html', ai: 'GV' },
      { ten: 'Rubric R1–R4', url: D + 'khtd/Rubric_R1-R4.html', ai: 'GV' },
    ] },
    { ten: 'Thực tập Cơ học đất (GEO10404)', muc: [
      { ten: 'Trang học phần', url: D + 'chd-tt/index.html', ai: 'SV' },
      { ten: 'Làm phiếu online', url: D + 'chd-tt/online.html', ai: 'SV' },
      { ten: 'Giảng viên', url: D + 'chd-tt/gv.html', ai: 'GV' },
      { ten: 'Quản lý', url: D + 'chd-tt/quanly.html', ai: 'GV' },
    ] },
    { ten: 'Bồi dưỡng văn hóa — học sinh', muc: [
      { ten: 'Trang chủ BDVH', url: B + 'index.html', ai: 'HS' },
      { ten: 'Đề kiểm tra tính giờ', url: B + 'index.html#de-kiem-tra', ai: 'HS' },
      { ten: 'Phiếu kết quả', url: B + 'ket-qua.html', ai: 'HS' },
      { ten: 'Phòng thi trực tiếp', url: B + 'phong-thi.html', ai: 'HS', mo_ta: 'quét QR của GV' },
      { ten: 'Bình chọn Toán 7', url: B + 'sv-toan7.html', ai: 'HS' },
    ] },
    { ten: 'Bồi dưỡng văn hóa — giáo viên', muc: [
      { ten: 'Chiếu bài hôm nay', url: B + 'trinh-chieu.html?hom_nay=1', ai: 'GV' },
      { ten: 'Bài giảng trình chiếu', url: B + 'bai-giang.html', ai: 'GV', mo_ta: 'đề Drive + Gemini' },
      { ten: 'Quản trị BDVH (Thầy Tuấn)', url: B + 'thay-tuan.html', ai: 'GV' },
      { ten: 'Giám sát phòng thi', url: B + 'giam-sat.html', ai: 'GV' },
      { ten: 'Phòng chiếu Slido', url: B + 'giao-vien.html', ai: 'GV' },
      { ten: 'Slide Toán 6', url: B + 'slide-toan6.html', ai: 'GV' },
      { ten: 'Slide Toán 7', url: B + 'slide-toan7.html', ai: 'GV' },
      { ten: 'Slide KHTN 6', url: B + 'slide-khtn6.html', ai: 'GV' },
    ] },
    { ten: 'Tuyển sinh & sự kiện', muc: [
      { ten: 'La bàn Hướng nghiệp 1', url: D + 'tvts01', ai: '' },
      { ten: 'La bàn Hướng nghiệp 2', url: D + 'TVTS02.html', ai: '' },
      { ten: 'La bàn HN 2 — quản trị', url: D + 'tvts02/admin.html', ai: 'GV' },
      { ten: 'Workshop Tân SV K2026', url: D + 'workshop/sv.html', ai: 'SV' },
      { ten: 'Workshop — Cẩm nang', url: D + 'workshop/cam-nang.html', ai: 'SV' },
      { ten: 'Workshop — Nghiên cứu', url: D + 'workshop/nghien-cuu.html', ai: 'SV' },
      { ten: 'Workshop — Theo dõi', url: D + 'workshop/theo-doi.html', ai: 'SV' },
      { ten: 'Workshop — Ban tổ chức', url: D + 'workshop/index.html', ai: 'GV' },
    ] },
    { ten: 'Công cụ của thầy', muc: [
      { ten: 'Hộp việc (đội agent)', url: 'https://hopviec.diemdanhsv.com/', ai: 'GV' },
    ] },
  ];

  const NHAN = { SV: 'Sinh viên', HS: 'Học sinh', GV: 'Giảng viên' };
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const khongDau = (s) => String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/gi, 'd').toLowerCase();

  const CSS = `
  .mht-nut{display:inline-flex;align-items:center;gap:8px;height:40px;padding:0 14px;border-radius:6px;border:1px solid #D5DCE1;background:#fff;color:#10212B;font:600 14px/1 "Be Vietnam Pro",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;cursor:pointer;white-space:nowrap}
  .mht-nut:hover,.mht-nut[aria-expanded="true"]{border-color:#0F6B55;color:#0F6B55}
  .mht-nut svg{width:16px;height:16px}
  .mht-nut .mht-mui{transition:transform .15s}
  .mht-nut[aria-expanded="true"] .mht-mui{transform:rotate(180deg)}
  .mht-goc{position:absolute;top:50%;right:16px;transform:translateY(-50%);z-index:5}
  .mht-nen{position:fixed;inset:0;background:rgba(16,33,43,.35);z-index:9998;display:none}
  .mht-hop{position:fixed;left:50%;transform:translateX(-50%);width:min(1120px,calc(100vw - 24px));max-height:calc(100vh - 96px);overflow:auto;background:#fff;color:#10212B;border-radius:12px;box-shadow:0 24px 64px rgba(10,20,30,.28);z-index:9999;display:none;font:400 14px/1.45 "Be Vietnam Pro",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;text-align:left}
  .mht-mo .mht-nen,.mht-mo .mht-hop{display:block}
  .mht-dau{position:sticky;top:0;background:#fff;display:flex;gap:12px;align-items:center;padding:14px 18px;border-bottom:1px solid #E2E7E9;z-index:1}
  .mht-dau b{font:700 17px/1.2 "Source Serif 4",Georgia,serif;color:#1F4E79;white-space:nowrap}
  .mht-tim{flex:1;height:38px;border:1px solid #D5DCE1;border-radius:6px;padding:0 12px;font:inherit;color:inherit;min-width:0}
  .mht-tim:focus{outline:none;border-color:#0F6B55;box-shadow:0 0 0 3px #E8F3EE}
  .mht-dong{width:36px;height:36px;border:0;background:none;border-radius:50%;cursor:pointer;color:#74828A;font-size:20px;line-height:1}
  .mht-dong:hover{background:#F2F4F1;color:#10212B}
  .mht-luoi{columns:4 230px;column-gap:24px;padding:16px 18px 20px}
  .mht-nhom{break-inside:avoid;margin:0 0 16px}
  .mht-nhom h3{margin:0 0 6px;padding-bottom:6px;border-bottom:2px solid #0F6B55;font:700 12px/1.3 "Be Vietnam Pro",system-ui,sans-serif;letter-spacing:.06em;text-transform:uppercase;color:#46555F}
  .mht-nhom ul{list-style:none;margin:0;padding:0}
  .mht-nhom a{display:flex;align-items:baseline;gap:8px;padding:6px 8px;margin:0 -8px;border-radius:6px;color:#10212B;text-decoration:none}
  .mht-nhom a:hover,.mht-nhom a:focus{background:#E8F3EE;color:#0F6B55;outline:none}
  .mht-nhom a.mht-day{background:#EAF1F8;color:#1F4E79;font-weight:600}
  .mht-ten{flex:1;min-width:0}
  .mht-ten small{color:#74828A;font-size:12px}
  .mht-ai{flex:none;font-size:11px;font-weight:600;padding:1px 7px;border-radius:999px;background:#EEF1EE;color:#46555F}
  .mht-ai.GV{background:#EAF1F8;color:#1F4E79}
  .mht-ai.SV,.mht-ai.HS{background:#E8F3EE;color:#0F6B55}
  .mht-trong{padding:24px 18px;color:#74828A}
  .mht-chan{padding:10px 18px;border-top:1px solid #E2E7E9;font-size:12px;color:#74828A;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
  @media (max-width:640px){.mht-goc{top:8px;right:8px;transform:none}.mht-nut .mht-chu{display:none}.mht-nut{padding:0 10px}.mht-hop{top:8px!important;max-height:calc(100vh - 16px)}.mht-luoi{columns:1}}
  @media print{.mht-nut,.mht-hop,.mht-nen{display:none!important}}`;

  function dungNut() {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'mht-nut';
    b.setAttribute('aria-haspopup', 'true');
    b.setAttribute('aria-expanded', 'false');
    b.setAttribute('aria-controls', 'mht-hop');
    b.title = 'Tất cả các trang của hệ thống';
    b.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>'
      + '<span class="mht-chu">Tất cả trang</span>'
      + '<svg class="mht-mui" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
    return b;
  }

  function laTrangNay(url) {
    try {
      const u = new URL(url, location.href);
      const p = (x) => x.replace(/\/index\.html?$/, '/').replace(/\/$/, '/');
      return u.host === location.host && p(u.pathname) === p(location.pathname) && !u.hash && (!u.search || u.search === location.search);
    } catch (e) { return false; }
  }

  function veLuoi(loc) {
    const q = khongDau(loc || '').trim();
    let dem = 0;
    const html = NHOM.map((n) => {
      const muc = n.muc.filter((m) => !q || khongDau(n.ten + ' ' + m.ten + ' ' + (m.mo_ta || '') + ' ' + (NHAN[m.ai] || '') + ' ' + m.url).includes(q));
      dem += muc.length;
      if (!muc.length) return '';
      return `<section class="mht-nhom"><h3>${esc(n.ten)}</h3><ul>${muc.map((m) => `<li><a href="${esc(m.url)}" class="${laTrangNay(m.url) ? 'mht-day' : ''}"${laTrangNay(m.url) ? ' aria-current="page"' : ''}>
        <span class="mht-ten">${esc(m.ten)}${m.mo_ta ? ` <small>· ${esc(m.mo_ta)}</small>` : ''}</span>${m.ai ? `<span class="mht-ai ${m.ai}">${NHAN[m.ai]}</span>` : ''}</a></li>`).join('')}</ul></section>`;
    }).join('');
    return dem ? html : '<p class="mht-trong">Không có trang nào khớp.</p>';
  }

  function khoiDong() {
    const st = document.createElement('style');
    st.textContent = CSS;
    document.head.appendChild(st);

    const nut = dungNut();
    const cho = document.querySelector('[data-menu-he-thong]');
    if (cho) {
      cho.replaceWith(nut);
      const h = nut.closest('.mht-goc') && nut.closest('header');
      if (h && getComputedStyle(h).position === 'static') h.style.position = 'relative';
    }
    else {
      const h = document.querySelector('header');
      if (!h) return;
      const hang = h.querySelector('.head-actions, nav, div') || h;
      hang.appendChild(nut);
    }

    const tong = NHOM.reduce((a, n) => a + n.muc.length, 0);
    const vo = document.createElement('div');
    vo.innerHTML = `<div class="mht-nen"></div>
      <div class="mht-hop" id="mht-hop" role="dialog" aria-label="Tất cả các trang">
        <div class="mht-dau"><b>Tất cả trang</b><input class="mht-tim" type="search" placeholder="Tìm trang… (ví dụ: điểm, giám sát, slide)" aria-label="Tìm trang"><button class="mht-dong" type="button" aria-label="Đóng">×</button></div>
        <div class="mht-luoi"></div>
        <div class="mht-chan"><span>${tong} trang · diemdanhsv.com và bdvh.diemdanhsv.com</span><span>ThS. Đinh Quốc Tuấn · ĐH Khoa học Tự nhiên, ĐHQG-HCM</span></div>
      </div>`;
    document.body.appendChild(vo);
    const hop = vo.querySelector('.mht-hop'), tim = vo.querySelector('.mht-tim'), luoi = vo.querySelector('.mht-luoi');
    luoi.innerHTML = veLuoi('');

    function mo() {
      const r = nut.getBoundingClientRect();
      hop.style.top = Math.min(r.bottom + 8, window.innerHeight - 200) + 'px';
      vo.classList.add('mht-mo');
      nut.setAttribute('aria-expanded', 'true');
      setTimeout(() => (window.innerWidth > 640 ? tim : luoi.querySelector('a') || tim).focus(), 30);
    }
    function dong() {
      vo.classList.remove('mht-mo');
      nut.setAttribute('aria-expanded', 'false');
      tim.value = ''; luoi.innerHTML = veLuoi('');
    }
    nut.addEventListener('click', () => (vo.classList.contains('mht-mo') ? dong() : mo()));
    vo.querySelector('.mht-nen').addEventListener('click', dong);
    vo.querySelector('.mht-dong').addEventListener('click', () => { dong(); nut.focus(); });
    tim.addEventListener('input', () => { luoi.innerHTML = veLuoi(tim.value); });
    tim.addEventListener('keydown', (e) => { if (e.key === 'Enter') { const a = luoi.querySelector('a'); if (a) location.href = a.href; } });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && vo.classList.contains('mht-mo')) { dong(); nut.focus(); }
    });
    window.addEventListener('resize', () => { if (vo.classList.contains('mht-mo')) mo(); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', khoiDong);
  else khoiDong();
  window.MenuHeThong = { NHOM, mo: () => document.querySelector('.mht-nut')?.click() };
})();
