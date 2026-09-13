/* js/models/Attendance.js — Một bản ghi điểm danh */
class Attendance {
  constructor(data = {}) {
    this.mssv        = data.mssv || '';
    this.fullName    = data.fullName || '';
    this.status      = data.status || 'ABSENT';
    this.checkInTime = data.checkInTime || '';
    this.gpsFlag     = data.gpsFlag || 'NO_GPS';
    this.distanceM   = data.distanceM === '' ? null : Number(data.distanceM);
    this.deviceHash  = data.deviceHash || '';
  }
  get statusText() { return STATUS_TEXT[this.status] || this.status; }
  get gpsText()    { return GPS_TEXT[this.gpsFlag] || this.gpsFlag; }

  /** Có điểm bất thường cần giảng viên nhìn bằng mắt không? */
  get needsAttention() {
    return this.gpsFlag === 'OUT_OF_RANGE' || this.gpsFlag === 'NO_GPS';
  }

  /** Chỉ giờ phút, bỏ phần ngày của dấu thời gian ISO */
  get timeOnly() {
    const i = this.checkInTime.indexOf('T');
    return i === -1 ? this.checkInTime : this.checkInTime.substring(i + 1, i + 6);
  }
}
