/* js/ui/KhtdGiamSat.js — Giám sát chống gian lận cho phiếu online có schema.giam_sat (docs/04 mục 39).
 * Cách phát hiện lấy từ KIEMTRA (kiemtra/assets/sv.js): rời trang, mất tiêu điểm lâu hơn ngưỡng, chia đôi / thu nhỏ
 * cửa sổ, sao chép / cắt / dán, phím công cụ nhà phát triển, PrintScreen. Khác KIEMTRA:
 *  - KHÔNG yêu cầu toàn màn hình; mỗi vi phạm cảnh báo + gửi máy chủ; đủ ngưỡng (mặc định 3) thì KHOÁ: màn khoá che
 *    phiếu, máy chủ từ chối lưu / nộp / tải ảnh; giảng viên nhập PIN 6 số trên máy SV để mở (+1 lượt, vi phạm tiếp lại khoá);
 *  - bấm "Chụp ảnh" / "Chọn ảnh" mẫu ([data-photo-input]) thì tạm ngưng theo dõi tới khi quay lại trang (+3 giây),
 *    vì mở camera / thư viện ảnh là rời trang hợp lệ.
 * send(type, detail) / unlock(pin) → Promise<{violations, limit, max, locked}> do KhtdController cung cấp
 * (gọi khtdLogEvent / khtdGsUnlock). Trạng thái máy chủ trả về là chuẩn; trình duyệt chỉ hiển thị. */
class KhtdGiamSat {
  constructor(send, unlock) {
    this.send = send; this.unlock = unlock; this.on = false; this.cfg = null; this.limit = 3; this.locked = false;
    this.violations = 0; this.lastAt = {}; this.pause = 0; this.blurTimer = null; this.hiddenAt = 0;
    this.base = {}; this.smallSince = 0; this.splitReported = false;
    this.h = {
      vis: () => this._vis(), blur: () => this._blur(), focus: () => this._focus(), resize: () => this._resize(),
      copy: (e) => { e.preventDefault(); this._report('copy', 'Sao chép'); },
      cut: (e) => { e.preventDefault(); this._report('cut', 'Cắt'); },
      paste: (e) => { e.preventDefault(); this._report('paste', 'Dán'); },
      key: (e) => this._key(e),
      photo: (e) => { if (e.target.closest('label') && e.target.closest('label').querySelector('[data-photo-input]')) this._photoPause(); },
      offline: () => this._report('offline', 'Mất mạng'), online: () => this._report('online', 'Có mạng lại'),
    };
  }

  start(cfg) {
    if (this.on) return;
    this.cfg = cfg; this.on = true; this._apply(cfg);
    this.base[this._orient()] = innerWidth * innerHeight;
    document.addEventListener('visibilitychange', this.h.vis);
    window.addEventListener('blur', this.h.blur); window.addEventListener('focus', this.h.focus);
    window.addEventListener('resize', this.h.resize);
    document.addEventListener('copy', this.h.copy); document.addEventListener('cut', this.h.cut); document.addEventListener('paste', this.h.paste);
    document.addEventListener('keydown', this.h.key);
    document.addEventListener('click', this.h.photo, true);
    window.addEventListener('offline', this.h.offline); window.addEventListener('online', this.h.online);
    this._banner();
    this._send('start', 'Mở phiếu có giám sát');
  }

  stop(reason) {
    if (!this.on) return;
    if (reason) this._send('stop', reason);
    this.on = false; clearTimeout(this.blurTimer);
    document.removeEventListener('visibilitychange', this.h.vis);
    window.removeEventListener('blur', this.h.blur); window.removeEventListener('focus', this.h.focus);
    window.removeEventListener('resize', this.h.resize);
    document.removeEventListener('copy', this.h.copy); document.removeEventListener('cut', this.h.cut); document.removeEventListener('paste', this.h.paste);
    document.removeEventListener('keydown', this.h.key);
    document.removeEventListener('click', this.h.photo, true);
    window.removeEventListener('offline', this.h.offline); window.removeEventListener('online', this.h.online);
    ['gs-bar', 'gs-lock'].forEach((id) => { const el = document.getElementById(id); if (el) el.remove(); });
  }

  /* ---------- phát hiện ---------- */
  _paused() { return this.pause && Date.now() < this.pause; }
  _photoPause() {
    const now = Date.now(); const again = this.photoAt && now - this.photoAt < 2000;   // nhấp nhãn + ô file = 2 lần click
    this.photoAt = now; this.pause = now + 3 * 60 * 1000;
    if (!again) this._send('photo', 'Bấm chụp / chọn ảnh mẫu');
  }
  _orient() { return innerWidth > innerHeight ? 'land' : 'port'; }

  _vis() {
    if (document.visibilityState === 'hidden') {
      clearTimeout(this.blurTimer); this.blurTimer = null;
      if (this._paused()) { this.hiddenAt = -1; return; }          // đang mở camera / thư viện ảnh
      this.hiddenAt = Date.now();
      this._report('hidden', 'Rời trang (chuyển tab / ứng dụng khác / tắt màn hình)');
    } else {
      if (this.hiddenAt === -1) { this.pause = Date.now() + 3000; }  // quay lại từ camera: còn 3 giây ân hạn
      else if (this.hiddenAt) this._send('back', 'Quay lại sau ' + Math.round((Date.now() - this.hiddenAt) / 1000) + ' giây');
      this.hiddenAt = 0;
    }
  }

  _blur() {
    clearTimeout(this.blurTimer);
    if (this._paused()) return;
    const grace = Math.max(300, (this.cfg.blurGraceSec || 0) * 1000);
    this.blurTimer = setTimeout(() => {
      this.blurTimer = null;
      if (!this._paused() && document.visibilityState === 'visible' && !document.hasFocus()) {
        this._report('blur', 'Mất tiêu điểm hơn ' + Math.round(grace / 1000) + ' giây (cửa sổ khác / cửa sổ nổi)');
      }
    }, grace);
  }

  _focus() { clearTimeout(this.blurTimer); this.blurTimer = null; }

  _resize() {
    const typing = document.activeElement && /INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName);
    const k = this._orient(); const area = innerWidth * innerHeight; const base = this.base[k];
    if (!base || area > base) { this.base[k] = area; this.smallSince = 0; return; }
    if (!typing && area < base * 0.65) {
      if (!this.smallSince) this.smallSince = Date.now();
      setTimeout(() => {
        const b = this.base[this._orient()] || 0;
        if (this.on && this.smallSince && Date.now() - this.smallSince >= 1400 && !this.splitReported && !this._paused() && innerWidth * innerHeight < b * 0.65) {
          this.splitReported = true;
          this._report('split', `Cửa sổ thu nhỏ còn ${innerWidth}×${innerHeight}`);
        }
      }, 1500);
    } else if (area >= base * 0.8) { this.smallSince = 0; this.splitReported = false; }
  }

  _key(e) {
    const k = (e.key || '').toLowerCase(); const mod = e.ctrlKey || e.metaKey;
    if (k === 'f12' || (mod && e.shiftKey && ['i', 'j', 'c'].includes(k)) || (mod && k === 'u')) { e.preventDefault(); return this._report('devtools', 'Phím công cụ nhà phát triển'); }
    if (k === 'printscreen') this._report('printscreen', 'Phím PrintScreen');
  }

  /* ---------- ghi nhận ---------- */
  _report(type, detail) {
    if (!this.on) return;
    const now = Date.now();
    if (this.lastAt[type] && now - this.lastAt[type] < 2000) return;   // gộp tín hiệu dồn dập
    this.lastAt[type] = now;
    this.violations++; this._warn(detail);
    if (this.violations >= this.limit) this.locked = true;
    this._banner(); this._lockUi();
    this._send(type, detail);
  }

  _send(type, detail) {
    Promise.resolve(this.send(type, detail)).then((d) => this._apply(d)).catch(() => {});
  }

  /* Áp trạng thái máy chủ trả về. */
  _apply(d) {
    if (!this.on || !d || typeof d.violations !== 'number') return;   // đã tắt (rời phiếu / nộp) thì bỏ phản hồi về muộn
    this.violations = d.violations; this.limit = Number(d.limit || d.max || 3); this.locked = !!d.locked;
    this._banner(); this._lockUi();
  }

  /* Màn khoá: che phiếu, chỉ còn ô nhập PIN giám thị. */
  _lockUi() {
    let o = document.getElementById('gs-lock');
    if (!this.locked) { if (o) o.remove(); return; }
    if (o) return;
    o = document.createElement('div'); o.id = 'gs-lock';
    o.style.cssText = 'position:fixed;inset:0;z-index:70;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(127,29,29,.94);color:#fff;font:15px/1.5 system-ui,sans-serif';
    o.innerHTML = `<div style="max-width:420px;width:100%;text-align:center">
      <div style="font-size:44px">🔒</div>
      <h2 style="margin:4px 0 6px;font-size:22px">Bài đang bị khoá</h2>
      <p style="margin:0 0 14px">Em đã vi phạm giám sát <b id="gs-lock-n"></b> lần. Giơ tay gọi giảng viên đến nhập <b>mã PIN</b> để mở khoá. Bài làm và ảnh đã lưu vẫn còn nguyên.</p>
      <input id="gs-pin" inputmode="numeric" autocomplete="off" maxlength="6" placeholder="Mã PIN 6 số" style="width:100%;box-sizing:border-box;padding:12px;border-radius:10px;border:0;font-size:24px;letter-spacing:6px;text-align:center;color:#111">
      <button id="gs-pin-ok" style="margin-top:10px;width:100%;padding:12px;border:0;border-radius:10px;background:#fff;color:#7f1d1d;font-weight:700;font-size:16px;cursor:pointer">Mở khoá</button>
      <p id="gs-pin-msg" style="margin:8px 0 0;min-height:1.5em;color:#fecaca"></p></div>`;
    document.body.appendChild(o);
    const go = () => {
      const pin = o.querySelector('#gs-pin').value.replace(/\D/g, ''); const msg = o.querySelector('#gs-pin-msg');
      if (pin.length !== 6) { msg.textContent = 'Nhập đủ 6 số.'; return; }
      msg.textContent = 'Đang kiểm tra…';
      Promise.resolve(this.unlock(pin)).then((d) => { this._apply(d); if (this.locked) msg.textContent = 'Chưa mở được.'; })
        .catch((e) => { msg.textContent = (e && e.message) || 'Không mở được.'; o.querySelector('#gs-pin').value = ''; });
    };
    o.querySelector('#gs-pin-ok').addEventListener('click', go);
    o.querySelector('#gs-pin').addEventListener('keydown', (e) => { if (e.key === 'Enter') go(); });
    this._lockCount();
  }

  _lockCount() { const n = document.getElementById('gs-lock-n'); if (n) n.textContent = this.violations; }

  _banner() {
    let b = document.getElementById('gs-bar');
    if (!b) {
      b = document.createElement('div'); b.id = 'gs-bar';
      b.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:60;padding:6px 12px;font:600 13px/1.4 system-ui,sans-serif;text-align:center;color:#fff';
      document.body.appendChild(b);
    }
    const lim = this.limit || 3; const v = this.violations;
    b.style.background = this.locked ? '#b91c1c' : v > 0 ? '#b45309' : '#0b4f9e';
    b.textContent = `👁 Phiếu có giám sát · vi phạm ${v}/${lim}` + (this.locked ? ' — bài đang khoá' : ` · đủ ${lim} lần bài sẽ bị khoá · chụp ảnh mẫu không tính`);
    this._lockCount();
  }

  _warn(detail) {
    let w = document.getElementById('gs-warn');
    if (!w) {
      w = document.createElement('div'); w.id = 'gs-warn';
      w.style.cssText = 'position:fixed;left:50%;top:12px;transform:translateX(-50%);z-index:61;max-width:92vw;padding:10px 14px;border-radius:10px;background:#b91c1c;color:#fff;font:600 14px/1.4 system-ui,sans-serif;box-shadow:0 6px 20px rgba(0,0,0,.25)';
      document.body.appendChild(w);
    }
    w.textContent = '⚠ ' + detail + ` — vi phạm ${this.violations}/${this.limit}.` + (this.violations >= this.limit ? ' Bài bị khoá.' : '');
    w.hidden = false; clearTimeout(w._t); w._t = setTimeout(() => { w.hidden = true; }, 5000);
    if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
  }
}
