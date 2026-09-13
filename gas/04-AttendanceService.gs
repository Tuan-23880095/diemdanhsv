/**
 * 04-AttendanceService.gs — Luồng mở / gửi / đóng điểm danh
 *
 * Hiện thực đầy đủ sáu lớp bù D.8. Mỗi lớp được đánh dấu [D.8-n] trong code.
 * Không được cắt lớp nào để "cho nhanh" — xác minh Mức 1 yếu, sáu lớp này
 * mới là thứ tạo ra kiểm chứng.
 *
 * MỐC THỜI GIAN: mọi dấu thời gian sinh bởi nowStamp(), định dạng
 * "yyyy-MM-ddTHH:mm:ss". Định dạng này so sánh bằng chuỗi cũng ra đúng thứ tự
 * thời gian, nên toàn bộ so sánh dưới đây dùng so sánh chuỗi — tránh sạch
 * mọi bẫy múi giờ khi parse.
 */

const AttendanceService = {

  /* ================= GIẢNG VIÊN: MỞ ĐIỂM DANH ================= */

  open: function (token, sessionId, opts) {
    const me = AuthService.requireRole(token, [ROLE.LECTURER, ROLE.ADMIN]);
    opts = opts || {};

    const session = Repos.sessions().findOne({ SessionID: sessionId });
    if (!session) throw new Error('Không tìm thấy buổi học ' + sessionId + '.');

    // [D.9] Chỉ giảng viên đứng tên lớp (hoặc ADMIN) mới mở được điểm danh
    AuthService.assertClassAccess(me, session.ClassID);

    const keys = Repos.keys();

    // Đóng mọi mã còn mở của buổi này — một buổi chỉ có một mã sống
    keys.findWhere({ SessionID: sessionId, Status: KEY_STATUS.OPEN })
        .forEach(function (k) { keys.updateRow(k._row, { Status: KEY_STATUS.CLOSED }); });

    const presentMin = Number(opts.presentMinutes || CONFIG.DEFAULT_PRESENT_MINUTES);
    const windowMin  = Number(opts.windowMinutes  || CONFIG.DEFAULT_WINDOW_MINUTES);

    // [D.8-1] Mã sinh mới mỗi buổi, ngẫu nhiên, hạn giờ ngắn.
    // Mã rò rỉ qua Zalo hết giá trị sau windowMin phút.
    const record = {
      KeyID: newId('KEY'),
      SessionID: sessionId,
      Code: generateUniqueCode_(),
      StartTime: nowStamp(),
      LateAfter: stampPlusMinutes_(presentMin),
      EndTime:   stampPlusMinutes_(windowMin),
      Status: KEY_STATUS.OPEN,
      CreatedBy: me.userId,
      CreatedAt: nowStamp()
    };
    keys.insert(record);

    // [D.8-6]
    logAudit(me.userId, me.role, 'ATTENDANCE_OPEN', 'SESSION', sessionId,
             { code: record.Code, endTime: record.EndTime }, '');

    return {
      code: record.Code,
      sessionId: sessionId,
      startTime: record.StartTime,
      lateAfter: record.LateAfter,
      endTime: record.EndTime
    };
  },

  /* ================= GIẢNG VIÊN: ĐÓNG ĐIỂM DANH ================= */

  /**
   * Đóng mã và đánh dấu ABSENT cho sinh viên đã ghi danh mà không check-in.
   * Không có bước này thì "vắng" chỉ là sự thiếu vắng của một dòng — không
   * xuất báo cáo được, và không phân xử được khi sinh viên khiếu nại.
   */
  close: function (token, sessionId) {
    const me = AuthService.requireRole(token, [ROLE.LECTURER, ROLE.ADMIN]);

    const session = Repos.sessions().findOne({ SessionID: sessionId });
    if (!session) throw new Error('Không tìm thấy buổi học ' + sessionId + '.');

    // [D.9] Chỉ giảng viên đứng tên lớp (hoặc ADMIN) mới đóng được điểm danh
    AuthService.assertClassAccess(me, session.ClassID);

    const keys = Repos.keys();
    keys.findWhere({ SessionID: sessionId, Status: KEY_STATUS.OPEN })
        .forEach(function (k) { keys.updateRow(k._row, { Status: KEY_STATUS.CLOSED }); });

    const att = Repos.attendance();
    const checkedIn = {};
    att.findWhere({ SessionID: sessionId }).forEach(function (r) {
      checkedIn[String(r.StudentID).trim()] = true;
    });

    let absent = 0;
    Repos.enrollments()
      .findWhere({ ClassID: session.ClassID, Status: RECORD_STATUS.ACTIVE })
      .forEach(function (en) {
        const sid = String(en.StudentID).trim();
        if (checkedIn[sid]) return;
        att.insert({
          AttendanceID: newId('ATT'),
          StudentID: sid,
          SessionID: sessionId,
          Status: ATTENDANCE_STATUS.ABSENT,
          CheckInTime: '',
          GpsFlag: GPS_FLAG.NO_GPS,
          Note: 'Tự đánh dấu khi đóng điểm danh',
          CreatedAt: nowStamp()
        });
        absent++;
      });

    logAudit(me.userId, me.role, 'ATTENDANCE_CLOSE', 'SESSION', sessionId,
             { markedAbsent: absent }, '');

    return { sessionId: sessionId, markedAbsent: absent };
  },

  /* ================= SINH VIÊN: GỬI ĐIỂM DANH ================= */

  /**
   * Trả về kết quả THẬT. Đây là chỗ trị nợ kỹ thuật số 2 của bản cũ:
   * frontend phải đọc phản hồi này, không được dùng mode:'no-cors' rồi
   * báo "Thành công" bất kể chuyện gì xảy ra.
   */
  checkin: function (p) {
    const code = String(p.code || '').trim().toUpperCase();
    if (!new RegExp('^[A-Z0-9]{' + CONFIG.CODE_LENGTH + '}$').test(code)) {
      throw new Error('Mã điểm danh phải gồm ' + CONFIG.CODE_LENGTH + ' ký tự chữ và số.');
    }

    const keys = Repos.keys();
    const key = keys.findOne({ Code: code, Status: KEY_STATUS.OPEN });
    if (!key) throw new Error('Mã không đúng hoặc buổi điểm danh đã đóng.');

    // [D.8-1] Cửa sổ thời gian (D.2)
    const now = nowStamp();
    if (now > String(key.EndTime)) {
      keys.updateRow(key._row, { Status: KEY_STATUS.CLOSED });
      throw new Error('Đã hết hạn điểm danh cho buổi này.');
    }
    const status = (now <= String(key.LateAfter))
      ? ATTENDANCE_STATUS.PRESENT
      : ATTENDANCE_STATUS.LATE;

    const session = Repos.sessions().findOne({ SessionID: key.SessionID });
    if (!session) throw new Error('Dữ liệu buổi học không hợp lệ.');

    // [D.8-2] Nhận diện + kiểm tra ghi danh — qua AuthService, không tự so sánh
    const auth = AuthService.identifyStudent(p.mssv, session.ClassID);
    if (!auth.ok) throw new Error(auth.reason);
    const student = auth.student;

    const cls = Repos.classes().findOne({ ClassID: session.ClassID });
    const gps = evaluateGps_(p, cls);

    const att = Repos.attendance();

    // [D.8-4] DeviceHash — lớp phòng thủ chính khi không có Authentication thật
    const deviceHash = String(p.deviceHash || '').trim();
    const conflicts = deviceHash
      ? att.findWhere({ SessionID: key.SessionID, DeviceHash: deviceHash })
           .filter(function (r) { return String(r.StudentID).trim() !== String(student.StudentID).trim(); })
      : [];

    const record = {
      AttendanceID: newId('ATT'),
      StudentID: student.StudentID,
      SessionID: key.SessionID,
      Status: status,
      CheckInTime: now,
      GpsLat: gps.lat,
      GpsLng: gps.lng,
      GpsAccuracy: gps.accuracy,
      DistanceM: gps.distance,
      GpsFlag: gps.flag,
      IP: String(p.ip || ''),
      OS: String(p.os || ''),
      Browser: String(p.browser || ''),
      DeviceType: String(p.deviceType || ''),
      DeviceHash: deviceHash,
      Note: conflicts.length ? 'Trùng thiết bị với ' + conflicts.length + ' MSSV khác' : '',
      CreatedAt: nowStamp()
    };

    // [D.8-3] Một MSSV chỉ một bản ghi cho một SessionID.
    // upsert chạy trong cùng một LockService nên 100 lượt gửi đồng thời
    // không tạo ra dòng trùng.
    const res = att.upsert(
      { StudentID: student.StudentID, SessionID: key.SessionID },
      record
    );

    // [D.8-6] Nhật ký cho MỌI lần điểm danh
    logAudit(student.StudentID, ROLE.STUDENT, 'CHECKIN_' + res.action, 'SESSION', key.SessionID, {
      mssv: student.MSSV, status: status, gpsFlag: gps.flag,
      distance: gps.distance, deviceConflicts: conflicts.length
    }, record.IP);

    return {
      mssv: student.MSSV,
      fullName: student.FullName,
      classId: session.ClassID,
      status: status,
      statusText: status === ATTENDANCE_STATUS.PRESENT ? 'Có mặt' : 'Trễ',
      checkInTime: now,
      action: res.action,
      gpsFlag: gps.flag,
      distanceM: gps.distance
    };
  },

  /* ================= GIẢNG VIÊN: DANH SÁCH THỜI GIAN THỰC ================= */

  /**
   * [D.8-5] Kiểm chứng hiệu quả nhất: giảng viên nhìn màn hình, đối chiếu
   * với lớp đang ngồi trước mặt. Cảnh báo trùng thiết bị và GPS bất thường
   * phải hiện Ở ĐÂY, không chỉ ghi âm thầm vào sheet.
   */
  liveRoster: function (token, sessionId) {
    const me = AuthService.requireRole(token, [ROLE.LECTURER, ROLE.ADMIN]);

    const session = Repos.sessions().findOne({ SessionID: sessionId });
    if (!session) throw new Error('Không tìm thấy buổi học ' + sessionId + '.');

    // [D.9] Chỉ giảng viên đứng tên lớp (hoặc ADMIN) mới xem được danh sách
    AuthService.assertClassAccess(me, session.ClassID);

    const studentById = {};
    Repos.students().all().forEach(function (s) {
      studentById[String(s.StudentID).trim()] = s;
    });

    const records = Repos.attendance().findWhere({ SessionID: sessionId });
    const seen = {};
    const deviceMap = {};

    const rows = records.map(function (r) {
      const sid = String(r.StudentID).trim();
      seen[sid] = true;
      const s = studentById[sid] || {};
      const dh = String(r.DeviceHash || '').trim();
      if (dh) { deviceMap[dh] = deviceMap[dh] || []; deviceMap[dh].push(s.MSSV || sid); }
      return {
        mssv: s.MSSV || sid,
        fullName: s.FullName || '',
        status: r.Status,
        checkInTime: r.CheckInTime,
        gpsFlag: r.GpsFlag,
        distanceM: r.DistanceM,
        deviceHash: dh
      };
    });

    const absent = Repos.enrollments()
      .findWhere({ ClassID: session.ClassID, Status: RECORD_STATUS.ACTIVE })
      .filter(function (en) { return !seen[String(en.StudentID).trim()]; })
      .map(function (en) {
        const s = studentById[String(en.StudentID).trim()] || {};
        return { mssv: s.MSSV || en.StudentID, fullName: s.FullName || '' };
      });

    // Cảnh báo: một thiết bị điểm danh cho nhiều MSSV
    const deviceAlerts = Object.keys(deviceMap)
      .filter(function (h) { return deviceMap[h].length > 1; })
      .map(function (h) { return { deviceHash: h.substring(0, 10) + '…', mssvList: deviceMap[h] }; });

    const gpsAlerts = rows.filter(function (r) {
      return r.gpsFlag === GPS_FLAG.OUT_OF_RANGE || r.gpsFlag === GPS_FLAG.NO_GPS;
    }).map(function (r) { return { mssv: r.mssv, gpsFlag: r.gpsFlag, distanceM: r.distanceM }; });

    return {
      sessionId: sessionId,
      counts: {
        enrolled: rows.length + absent.length,
        present: rows.filter(function (r) { return r.status === ATTENDANCE_STATUS.PRESENT; }).length,
        late:    rows.filter(function (r) { return r.status === ATTENDANCE_STATUS.LATE; }).length,
        absent:  absent.length
      },
      rows: rows,
      absent: absent,
      deviceAlerts: deviceAlerts,
      gpsAlerts: gpsAlerts
    };
  },

  /** Sinh viên xem lịch sử điểm danh của mình trong một lớp */
  studentHistory: function (mssv, classId) {
    const auth = AuthService.identifyStudent(mssv, classId);
    if (!auth.ok) throw new Error(auth.reason);

    const sessions = Repos.sessions().findWhere({ ClassID: classId }).filter(isActiveRow_);
    const byId = {};
    Repos.attendance().findWhere({ StudentID: auth.student.StudentID })
      .forEach(function (r) { byId[String(r.SessionID).trim()] = r; });

    const rows = sessions
      .sort(function (a, b) { return Number(a.SessionNo) - Number(b.SessionNo); })
      .map(function (s) {
        const r = byId[String(s.SessionID).trim()];
        return {
          sessionNo: s.SessionNo,
          date: s.Date,
          content: s.Content,
          status: r ? r.Status : ATTENDANCE_STATUS.ABSENT,
          checkInTime: r ? r.CheckInTime : ''
        };
      });

    return { mssv: auth.student.MSSV, fullName: auth.student.FullName, rows: rows };
  }
};

/* ------------------------------------------------------------------ */
/*  HÀM PHỤ                                                            */
/* ------------------------------------------------------------------ */

/** Mã không trùng với bất kỳ mã nào đang mở */
function generateUniqueCode_() {
  const taken = {};
  Repos.keys().findWhere({ Status: KEY_STATUS.OPEN })
       .forEach(function (k) { taken[String(k.Code).trim().toUpperCase()] = true; });

  const abc = CONFIG.CODE_ALPHABET;
  for (let attempt = 0; attempt < 50; attempt++) {
    let code = '';
    for (let i = 0; i < CONFIG.CODE_LENGTH; i++) {
      code += abc.charAt(Math.floor(Math.random() * abc.length));
    }
    if (!taken[code]) return code;
  }
  throw new Error('Không sinh được mã mới. Hãy đóng bớt các buổi điểm danh đang mở.');
}

/** Dấu thời gian hiện tại cộng thêm n phút, cùng định dạng với nowStamp() */
function stampPlusMinutes_(minutes) {
  const d = new Date(Date.now() + Number(minutes) * 60000);
  return Utilities.formatDate(d, CONFIG.TIMEZONE, "yyyy-MM-dd'T'HH:mm:ss");
}

/**
 * Đánh giá GPS (thiết kế D.3).
 * Quan trọng: KHÔNG loại thẳng sinh viên. GPS sai số 10–100 m và kém trong
 * nhà; loại theo GPS là đuổi oan người ngồi trong lớp. Ghi cờ, để giảng viên
 * quyết định — cờ này hiện lên trong liveRoster().
 */
function evaluateGps_(p, cls) {
  const lat = p.lat === undefined || p.lat === '' ? null : Number(p.lat);
  const lng = p.lng === undefined || p.lng === '' ? null : Number(p.lng);
  const acc = p.accuracy === undefined || p.accuracy === '' ? null : Number(p.accuracy);

  if (lat === null || lng === null || isNaN(lat) || isNaN(lng)) {
    return { lat: '', lng: '', accuracy: '', distance: '', flag: GPS_FLAG.NO_GPS };
  }
  if (acc !== null && !isNaN(acc) && acc > CONFIG.GPS_ACCURACY_LIMIT_M) {
    return { lat: lat, lng: lng, accuracy: acc, distance: '', flag: GPS_FLAG.LOW_ACCURACY };
  }

  const roomLat = cls && cls.RoomLat !== '' ? Number(cls.RoomLat) : null;
  const roomLng = cls && cls.RoomLng !== '' ? Number(cls.RoomLng) : null;
  if (roomLat === null || roomLng === null || isNaN(roomLat) || isNaN(roomLng)) {
    // Lớp chưa khai toạ độ phòng — không có cơ sở so sánh, coi như hợp lệ
    return { lat: lat, lng: lng, accuracy: acc === null ? '' : acc, distance: '', flag: GPS_FLAG.VALID };
  }

  const radius = Number(cls.AllowedRadiusM || CONFIG.DEFAULT_RADIUS_M);
  const dist = Math.round(haversineMeters_(lat, lng, roomLat, roomLng));

  return {
    lat: lat, lng: lng,
    accuracy: acc === null ? '' : acc,
    distance: dist,
    flag: dist <= radius ? GPS_FLAG.VALID : GPS_FLAG.OUT_OF_RANGE
  };
}

/** Khoảng cách hai điểm trên mặt cầu, đơn vị mét */
function haversineMeters_(lat1, lng1, lat2, lng2) {
  const R = 6371000;
  const toRad = function (d) { return d * Math.PI / 180; };
  const dLat = toRad(lat2 - lat1);
  const dLng = toRad(lng2 - lng1);
  const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
            Math.sin(dLng / 2) * Math.sin(dLng / 2);
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}
