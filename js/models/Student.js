/* js/models/Student.js */
class Student {
  constructor(data = {}) {
    this.studentId = data.StudentID || data.studentId || '';
    this.mssv      = data.MSSV      || data.mssv      || '';
    this.fullName  = data.FullName  || data.fullName  || '';
    this.email     = data.Email     || data.email     || '';
  }
  get displayName() { return this.mssv + ' — ' + this.fullName; }
}
