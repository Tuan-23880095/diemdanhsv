/* js/controllers/StudentController.js */

class StudentController {

  constructor(api, view) {
    this.api = api;
    this.view = view;
    this.device = null;
  }

  async init() {
    this.view.bindCodeUppercase();
    this.view.onSubmit(data => this.submit(data));

    this.view.setGps('Đang lấy vị trí…', 'pending');
    this.device = await DeviceService.collect();

    if (this.device.lat === '') {
      // D.3: không có GPS KHÔNG chặn điểm danh, chỉ báo cho sinh viên biết
      this.view.setGps('Không lấy được vị trí — vẫn điểm danh được, giảng viên sẽ xem lại.', 'warn');
    } else if (this.device.accuracy > 150) {
      this.view.setGps('Đã có vị trí nhưng sai số lớn (' + this.device.accuracy + 'm).', 'warn');
    } else {
      this.view.setGps('Đã có vị trí (sai số ' + this.device.accuracy + 'm).', 'ok');
    }
  }

  /** Lịch sử là phần phụ — hỏng thì im lặng, không phá kết quả điểm danh */
  async loadHistory(mssv, classId) {
    if (!classId) return;
    try {
      const hist = await this.api.studentHistory(mssv, classId);
      this.view.renderHistory(hist.rows);
    } catch (err) {
      /* bỏ qua */
    }
  }

  async submit(input) {
    if (!/^[0-9]{6,10}$/.test(input.mssv)) {
      return this.view.showError('MSSV phải là 6–10 chữ số.');
    }
    if (input.code.length !== CONFIG.CODE_LENGTH) {
      return this.view.showError('Mã điểm danh gồm ' + CONFIG.CODE_LENGTH + ' ký tự.');
    }

    this.view.setLoading(true);
    try {
      // Lấy lại vị trí ngay lúc gửi — vị trí lấy lúc mở trang có thể đã cũ
      const fresh = await DeviceService.getPosition();
      if (fresh) Object.assign(this.device, fresh);

      const data = await this.api.checkin(Object.assign(
        { mssv: input.mssv, code: input.code }, this.device
      ));

      this.view.showSuccess(data);
      this.view.el.code.value = '';
      this.loadHistory(input.mssv, data.classId);
    } catch (err) {
      // Lỗi thật từ máy chủ, không phải "Thành công" giả như bản cũ
      this.view.showError(err.message);
    } finally {
      this.view.setLoading(false);
    }
  }
}
