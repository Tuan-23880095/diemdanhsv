/* js/models/AcademicClass.js — Lớp mở trong một học kỳ (Class ≠ Course) */
class AcademicClass {
  constructor(data = {}) {
    this.classId      = data.ClassID || '';
    this.courseId     = data.CourseID || '';
    this.classCode    = data.ClassCode || '';
    this.semester     = data.Semester || '';
    this.academicYear = data.AcademicYear || '';
    this.roomLat      = data.RoomLat === '' ? null : Number(data.RoomLat);
    this.roomLng      = data.RoomLng === '' ? null : Number(data.RoomLng);
    this.radiusM      = Number(data.AllowedRadiusM || 0);
  }
  get label() {
    return this.classCode + ' (HK' + this.semester + ' ' + this.academicYear + ')';
  }
  get hasRoomLocation() { return this.roomLat !== null && this.roomLng !== null; }
}
