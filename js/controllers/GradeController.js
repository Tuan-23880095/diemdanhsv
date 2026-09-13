/* js/controllers/GradeController.js */

class GradeController {

  constructor(api, view) {
    this.api = api;
    this.view = view;
    this.mssv = '';
  }

  init() {
    this.view.bindCodeUppercase();
    this.view.onRequestCode(mssv => this.requestCode(mssv));
    this.view.onVerify(code => this.verify(code));
    this.view.onResend(() => this.requestCode(this.mssv, true));
    this.view.onBack(() => this.backToStart());
    this.view.onExit(() => this.exit());

    // Còn phiên cũ thì vào thẳng bảng điểm, khỏi bắt xin mã lại.
    // Token giữ trong sessionStorage: đóng tab là mất, không để lại
    // trên máy dùng chung.
    const saved = sessionStorage.getItem('dd_grade_token');
    if (saved) { this.loadGrades(saved, true); return; }

    this.view.showStepMssv();
  }

  /* ---------------- Bước 1: xin mã ---------------- */

  async requestCode(mssv, isResend) {
    if (!/^[0-9]{6,10}$/.test(mssv)) {
      return this.view.showError('Mã số sinh viên phải là 6–10 chữ số.');
    }

    this.mssv = mssv;
    this.view.clearMessage();
    this.view.setSending(true);

    try {
      const data = await this.api.requestGradeCode(mssv);
      this.view.showStepCode(mssv);
      this.view.showInfo(data.message);
      // Khớp với RESEND_COOLDOWN_SEC phía máy chủ
      this.view.startResendCooldown(60);
    } catch (err) {
      // Lỗi thật từ máy chủ: quá số lần xin mã, hoặc hết quota gửi email
      if (isResend) this.view.showError(err.message);
      else { this.view.showStepMssv(); this.view.showError(err.message); }
    } finally {
      this.view.setSending(false);
    }
  }

  /* ---------------- Bước 2: đối chiếu mã ---------------- */

  async verify(code) {
    if (code.length !== CONFIG.CODE_LENGTH) {
      return this.view.showError('Mã xác minh gồm ' + CONFIG.CODE_LENGTH + ' ký tự.');
    }

    this.view.clearMessage();
    this.view.setVerifying(true);

    try {
      const data = await this.api.verifyGradeCode(this.mssv, code);
      sessionStorage.setItem('dd_grade_token', data.token);
      await this.loadGrades(data.token);
    } catch (err) {
      // Thông báo của máy chủ có kèm số lần thử còn lại
      this.view.showError(err.message);
    } finally {
      this.view.setVerifying(false);
    }
  }

  /* ---------------- Bảng điểm ---------------- */

  /**
   * @param {boolean} silent  true khi khôi phục phiên cũ lúc mở trang:
   *   token hết hạn là chuyện bình thường, quay về bước 1 lặng lẽ chứ
   *   không nên doạ sinh viên bằng một thông báo lỗi đỏ.
   */
  async loadGrades(token, silent) {
    try {
      const data = await this.api.myGrades(token);
      this.view.showGrades(data);
    } catch (err) {
      sessionStorage.removeItem('dd_grade_token');
      this.view.showStepMssv();
      if (!silent) this.view.showError(err.message);
    }
  }

  backToStart() {
    this.view.clearMessage();
    this.view.showStepMssv();
  }

  exit() {
    sessionStorage.removeItem('dd_grade_token');
    this.mssv = '';
    this.view.clearMessage();
    this.view.showStepMssv();
  }
}
