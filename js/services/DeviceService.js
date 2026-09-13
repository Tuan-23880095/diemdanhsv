/* js/services/DeviceService.js — Thu thập GPS, thông tin thiết bị, IP */

class DeviceService {

  /**
   * Lấy toạ độ. KHÔNG ném lỗi khi thất bại — trả về null.
   * Thiết kế D.3: không có GPS thì gắn cờ, không loại sinh viên.
   */
  static getPosition() {
    return new Promise(resolve => {
      if (!navigator.geolocation) return resolve(null);
      navigator.geolocation.getCurrentPosition(
        pos => resolve({
          lat: pos.coords.latitude,
          lng: pos.coords.longitude,
          accuracy: Math.round(pos.coords.accuracy)
        }),
        () => resolve(null),
        { enableHighAccuracy: true, timeout: CONFIG.GPS_TIMEOUT_MS, maximumAge: 0 }
      );
    });
  }

  static getOS() {
    const ua = navigator.userAgent;
    if (/Windows NT 10/.test(ua))        return 'Windows 10/11';
    if (/Windows/.test(ua))              return 'Windows';
    if (/Android/.test(ua))              return 'Android';
    if (/iPhone|iPad|iPod/.test(ua))     return 'iOS';
    if (/Mac OS X/.test(ua))             return 'macOS';
    if (/Linux/.test(ua))                return 'Linux';
    return 'Không xác định';
  }

  static getBrowser() {
    const ua = navigator.userAgent;
    if (/Edg\//.test(ua))                return 'Edge';
    if (/OPR\//.test(ua))                return 'Opera';
    if (/SamsungBrowser/.test(ua))       return 'Samsung Internet';
    if (/Chrome\//.test(ua))             return 'Chrome';
    if (/Firefox\//.test(ua))            return 'Firefox';
    if (/Safari\//.test(ua))             return 'Safari';
    return 'Không xác định';
  }

  static getDeviceType() {
    return /Mobi|Android|iPhone/.test(navigator.userAgent) ? 'Mobile' : 'Desktop';
  }

  /**
   * Mã thiết bị (thiết kế D.4, lớp bù D.8-4).
   *
   * Có chủ đích KHÔNG phải định danh tuyệt đối. Mục tiêu duy nhất là PHÁT HIỆN
   * một điện thoại điểm danh cho nhiều MSSV trong cùng một buổi. Xoá dữ liệu
   * trình duyệt hoặc dùng cửa sổ ẩn danh sẽ sinh mã mới — chấp nhận được, vì
   * đây là lớp phát hiện chứ không phải lớp chặn.
   */
  static getDeviceHash() {
    let seed = null;
    try { seed = localStorage.getItem('dd_device_seed'); } catch (e) { seed = null; }

    if (!seed) {
      seed = Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
      try { localStorage.setItem('dd_device_seed', seed); } catch (e) { /* bỏ qua */ }
    }

    const parts = [
      seed,
      navigator.userAgent,
      screen.width + 'x' + screen.height,
      screen.colorDepth,
      navigator.language,
      new Date().getTimezoneOffset()
    ].join('|');

    // FNV-1a 32-bit — đủ để so trùng, không cần chống va chạm kiểu mật mã
    let h = 0x811c9dc5;
    for (let i = 0; i < parts.length; i++) {
      h ^= parts.charCodeAt(i);
      h = (h * 0x01000193) >>> 0;
    }
    return 'DV' + h.toString(16).toUpperCase().padStart(8, '0');
  }

  /** IP công cộng. Thất bại thì trả chuỗi rỗng — không chặn luồng điểm danh. */
  static async getIP() {
    try {
      const res = await fetch('https://api.ipify.org?format=json');
      const json = await res.json();
      return json.ip || '';
    } catch (e) {
      return '';
    }
  }

  /** Gom toàn bộ thông tin cần cho một lượt điểm danh */
  static async collect() {
    const [pos, ip] = await Promise.all([this.getPosition(), this.getIP()]);
    return {
      lat: pos ? pos.lat : '',
      lng: pos ? pos.lng : '',
      accuracy: pos ? pos.accuracy : '',
      ip: ip,
      os: this.getOS(),
      browser: this.getBrowser(),
      deviceType: this.getDeviceType(),
      deviceHash: this.getDeviceHash()
    };
  }
}
