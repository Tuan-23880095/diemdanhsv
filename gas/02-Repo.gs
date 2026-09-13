/**
 * 02-Repo.gs — Lớp truy cập dữ liệu theo TÊN CỘT
 *
 * Đây là lớp duy nhất trong dự án được phép đụng tới SpreadsheetApp.
 * Mọi service/controller gọi qua đây.
 *
 * Vì sao tồn tại: bản XemDiem cũ đọc điểm bằng data[i][44]. Chèn một cột
 * vào giữa sheet là toàn bộ bảng điểm lệch mà không có lỗi nào báo ra.
 * SheetRepo đọc header rồi ánh xạ theo tên, nên thêm/đổi chỗ cột vô hại.
 */

class SheetRepo {

  constructor(sheetName) {
    this.sheetName = sheetName;
    this._sheet = null;
    this._headers = null;
  }

  get sheet() {
    if (!this._sheet) {
      this._sheet = getSpreadsheet().getSheetByName(this.sheetName);
      if (!this._sheet) {
        throw new Error('Không tìm thấy sheet "' + this.sheetName + '". Chạy initializeSpreadsheet() trước.');
      }
    }
    return this._sheet;
  }

  get headers() {
    if (!this._headers) {
      const lastCol = this.sheet.getLastColumn();
      this._headers = lastCol === 0 ? [] :
        this.sheet.getRange(1, 1, 1, lastCol).getValues()[0].map(function (v) { return String(v).trim(); });
    }
    return this._headers;
  }

  colIndex(name) {
    const i = this.headers.indexOf(name);
    if (i === -1) throw new Error('Sheet "' + this.sheetName + '" không có cột "' + name + '".');
    return i + 1;
  }

  /**
   * Toàn bộ dòng dữ liệu dưới dạng object { TênCột: giá trị, _row: số dòng thật }
   *
   * Ô ngày/giờ được trả về dạng chuỗi "yyyy-MM-ddTHH:mm:ss", KHÔNG phải Date.
   * Lý do: nowStamp() ghi chuỗi, nhưng Google Sheets tự đổi chuỗi đó thành
   * ngày-giờ. Đọc lại ra Date thì String(Date) = "Sun Sep 13 2026 ...", mọi phép
   * so sánh chuỗi thời gian trong AttendanceService sai hết (mã điểm danh
   * không bao giờ hết hạn, ai gửi trễ cũng thành "Có mặt").
   */
  all() {
    const last = this.sheet.getLastRow();
    if (last < 2) return [];
    const values = this.sheet.getRange(2, 1, last - 1, this.headers.length).getValues();
    const headers = this.headers;
    const tz = getSpreadsheet().getSpreadsheetTimeZone() || CONFIG.TIMEZONE;
    return values.map(function (row, i) {
      const obj = { _row: i + 2 };
      headers.forEach(function (h, c) {
        if (!h) return;
        const v = row[c];
        obj[h] = v instanceof Date ? Utilities.formatDate(v, tz, "yyyy-MM-dd'T'HH:mm:ss") : v;
      });
      return obj;
    });
  }

  /**
   * Lọc theo nhiều điều kiện: findWhere({ ClassID: 'C1', Status: 'ACTIVE' })
   * So sánh dạng chuỗi đã trim để tránh bẫy số/chuỗi của Sheets.
   */
  findWhere(criteria) {
    const keys = Object.keys(criteria);
    return this.all().filter(function (r) {
      return keys.every(function (k) {
        return String(r[k]).trim() === String(criteria[k]).trim();
      });
    });
  }

  findOne(criteria) {
    const rows = this.findWhere(criteria);
    return rows.length ? rows[0] : null;
  }

  exists(criteria) {
    return this.findOne(criteria) !== null;
  }

  /** Thêm một dòng. Trường thiếu để trống, trường lạ bị bỏ qua. */
  insert(obj) {
    const self = this;
    return withLock_(function () {
      const row = self.headers.map(function (h) {
        return Object.prototype.hasOwnProperty.call(obj, h) ? obj[h] : '';
      });
      self.sheet.appendRow(row);
      return obj;
    });
  }

  /**
   * Thêm nhiều dòng trong MỘT lần ghi. Dùng khi nhập hàng trăm dòng —
   * gọi insert() lặp lại thì mỗi appendRow tốn ~0,3 giây, dễ vượt giới hạn
   * 6 phút của Apps Script.
   */
  insertMany(objs) {
    if (!objs.length) return 0;
    const self = this;
    return withLock_(function () {
      const rows = objs.map(function (obj) {
        return self.headers.map(function (h) {
          return Object.prototype.hasOwnProperty.call(obj, h) ? obj[h] : '';
        });
      });
      self.sheet.getRange(self.sheet.getLastRow() + 1, 1, rows.length, self.headers.length).setValues(rows);
      return rows.length;
    });
  }

  /** Cập nhật một phần dòng đã biết số dòng thật (_row). */
  updateRow(rowNumber, patch) {
    const self = this;
    return withLock_(function () {
      Object.keys(patch).forEach(function (k) {
        const c = self.colIndex(k);
        self.sheet.getRange(rowNumber, c).setValue(patch[k]);
      });
      return true;
    });
  }

  /**
   * Có thì cập nhật, chưa có thì thêm — trong CÙNG một lock.
   * Đây là hàm chống ghi trùng cho điểm danh (thiết kế D.6, D.8 lớp 3):
   *   repo.upsert({ StudentID: s, SessionID: k }, record)
   */
  upsert(criteria, obj) {
    const self = this;
    return withLock_(function () {
      const found = self.findOne(criteria);
      if (found) {
        Object.keys(obj).forEach(function (k) {
          if (self.headers.indexOf(k) !== -1) {
            self.sheet.getRange(found._row, self.colIndex(k)).setValue(obj[k]);
          }
        });
        return { action: 'UPDATED', row: found._row };
      }
      const row = self.headers.map(function (h) {
        return Object.prototype.hasOwnProperty.call(obj, h) ? obj[h] : '';
      });
      self.sheet.appendRow(row);
      return { action: 'INSERTED', row: self.sheet.getLastRow() };
    });
  }
}

/* ------------------------------------------------------------------ */

/**
 * Bọc mọi thao tác ghi trong script lock (thiết kế D.6).
 * 100 sinh viên bấm gửi cùng lúc lúc 08:00 sẽ tranh chấp ghi nếu không có.
 */
function withLock_(fn) {
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(CONFIG.LOCK_TIMEOUT_MS)) {
    throw new Error('Hệ thống đang bận, vui lòng thử lại sau vài giây.');
  }
  try {
    return fn();
  } finally {
    lock.releaseLock();
  }
}

/** Repo dùng lại được — tạo một lần mỗi lượt thực thi */
const Repos = {
  users:       function () { return new SheetRepo(SHEETS.USERS); },
  courses:     function () { return new SheetRepo(SHEETS.COURSES); },
  classes:     function () { return new SheetRepo(SHEETS.CLASSES); },
  students:    function () { return new SheetRepo(SHEETS.STUDENTS); },
  enrollments: function () { return new SheetRepo(SHEETS.ENROLLMENTS); },
  sessions:    function () { return new SheetRepo(SHEETS.SESSIONS); },
  attendance:  function () { return new SheetRepo(SHEETS.ATTENDANCE); },
  keys:        function () { return new SheetRepo(SHEETS.ATTENDANCE_KEYS); },
  gradeCols:   function () { return new SheetRepo(SHEETS.GRADE_COLUMNS); },
  grades:      function () { return new SheetRepo(SHEETS.GRADES); },
  complaints:  function () { return new SheetRepo(SHEETS.COMPLAINTS); },
  audit:       function () { return new SheetRepo(SHEETS.AUDIT_LOG); }
};

/**
 * Ghi nhật ký. Gọi cho MỌI lần điểm danh và MỌI lần sửa điểm danh/điểm
 * (thiết kế D.8 lớp 6). Khi sinh viên khiếu nại, đây là thứ duy nhất phân xử được.
 */
function logAudit(actor, actorRole, action, targetType, targetId, data, ip) {
  try {
    Repos.audit().insert({
      LogID: newId('LOG'),
      Time: nowStamp(),
      Actor: actor || '',
      ActorRole: actorRole || '',
      Action: action,
      TargetType: targetType || '',
      TargetID: targetId || '',
      Data: typeof data === 'string' ? data : JSON.stringify(data || {}),
      IP: ip || ''
    });
  } catch (err) {
    // Nhật ký hỏng không được phép làm hỏng nghiệp vụ chính
    Logger.log('logAudit lỗi: ' + err.message);
  }
}

/* ------------------------------------------------------------------ */

/**
 * Tự kiểm tra sau khi chạy initializeSpreadsheet().
 * Ghi 1 dòng thử vào AUDIT_LOG, đọc lại, rồi xoá.
 */
function selfTest() {
  const out = [];
  out.push(verifySchema());

  const marker = 'SELFTEST_' + Date.now();
  logAudit('system', ROLE.ADMIN, marker, 'TEST', '-', { ok: true }, '');

  const repo = Repos.audit();
  const found = repo.findOne({ Action: marker });
  out.push(found ? 'Ghi + đọc AUDIT_LOG: OK (dòng ' + found._row + ')'
                 : 'Ghi + đọc AUDIT_LOG: THẤT BẠI');

  if (found) {
    repo.sheet.deleteRow(found._row);
    out.push('Đã dọn dòng thử.');
  }

  const msg = out.join('\n');
  Logger.log(msg);
  return msg;
}
