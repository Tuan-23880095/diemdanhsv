/* js/models/ClassSession.js — Một buổi học */
class ClassSession {
  constructor(data = {}) {
    this.sessionId = data.SessionID || '';
    this.classId   = data.ClassID || '';
    this.sessionNo = Number(data.SessionNo || 0);
    this.date      = data.Date || '';
    this.startTime = data.StartTime || '';
    this.endTime   = data.EndTime || '';
    this.content   = data.Content || '';
  }
  get label() {
    const d = this.date ? ClassSession.formatDate(this.date) : '';
    return 'Buổi ' + this.sessionNo + (d ? ' — ' + d : '') +
           (this.content ? ' — ' + this.content : '');
  }
  static formatDate(value) {
    const d = new Date(value);
    if (isNaN(d.getTime())) return String(value);
    return String(d.getDate()).padStart(2, '0') + '/' +
           String(d.getMonth() + 1).padStart(2, '0') + '/' + d.getFullYear();
  }
}
