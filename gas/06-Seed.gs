/**
 * 06-Seed.gs — Dữ liệu mẫu và kiểm thử đầu-cuối
 *
 * Chạy TAY từ trình soạn thảo Apps Script. Không có action API nào gọi tới
 * các hàm ở đây.
 *
 * runSmokeTest() chạy trọn vòng: mở điểm danh -> 3 sinh viên gửi -> xem danh
 * sách thời gian thực -> đóng. Nó kiểm chứng cả sáu lớp bù D.8 mà không cần
 * dựng frontend, kể cả các trường hợp PHẢI bị từ chối.
 */

const SEED = {
  courseCode: 'DEMO101',
  classCode: 'DEMO101_01',
  mssv: ['22000001', '22000002', '22000003', '22000004']
};

/** Tạo 1 môn, 1 lớp, 4 sinh viên, 3 ghi danh (sinh viên thứ 4 KHÔNG ghi danh), 1 buổi học */
function seedDemoData() {
  const out = [];

  let course = Repos.courses().findOne({ CourseCode: SEED.courseCode });
  if (!course) {
    course = {
      CourseID: newId('CRS'), CourseCode: SEED.courseCode,
      CourseName: 'Môn học thử nghiệm', Credits: 3,
      TheoryHours: 30, PracticeHours: 15,
      Status: RECORD_STATUS.ACTIVE, CreatedAt: nowStamp()
    };
    Repos.courses().insert(course);
    out.push('Tạo môn ' + SEED.courseCode);
  }

  // [D.9] Lớp demo phải đứng tên một giảng viên thật, nếu không mọi
  // LECTURER (kể cả tài khoản vừa tạo) sẽ bị assertClassAccess() chặn.
  // Lấy tạm tài khoản LECTURER/ADMIN đầu tiên đang có — đủ cho mục đích demo.
  const anyLecturer = Repos.users().findOne({ Role: ROLE.LECTURER, Status: RECORD_STATUS.ACTIVE }) ||
                       Repos.users().findOne({ Role: ROLE.ADMIN, Status: RECORD_STATUS.ACTIVE });

  let cls = Repos.classes().findOne({ ClassCode: SEED.classCode });
  if (!cls) {
    cls = {
      ClassID: newId('CLS'), CourseID: course.CourseID,
      LecturerID: anyLecturer ? anyLecturer.UserID : '',
      ClassCode: SEED.classCode, Semester: '1', AcademicYear: '2026-2027',
      RoomLat: 10.7626, RoomLng: 106.6822, AllowedRadiusM: CONFIG.DEFAULT_RADIUS_M,
      Status: RECORD_STATUS.ACTIVE, CreatedAt: nowStamp()
    };
    Repos.classes().insert(cls);
    out.push('Tạo lớp ' + SEED.classCode + ' (toạ độ mẫu: cơ sở Nguyễn Văn Cừ)' +
              (anyLecturer ? ', LecturerID = ' + anyLecturer.UserID : ''));
  } else if (!String(cls.LecturerID || '').trim() && anyLecturer) {
    // Lớp demo tạo TRƯỚC KHI có D.9 (LecturerID còn trống) — gán bù lại
    Repos.classes().updateRow(cls._row, { LecturerID: anyLecturer.UserID });
    cls.LecturerID = anyLecturer.UserID;
    out.push('Gán bù LecturerID = ' + anyLecturer.UserID + ' cho lớp demo đã có sẵn (D.9)');
  }

  SEED.mssv.forEach(function (mssv, i) {
    let st = Repos.students().findOne({ MSSV: mssv });
    if (!st) {
      st = {
        StudentID: newId('STD'), MSSV: mssv,
        FullName: 'Sinh viên thử ' + (i + 1),
        Email: mssv + '@student.hcmus.edu.vn',
        Status: RECORD_STATUS.ACTIVE, CreatedAt: nowStamp()
      };
      Repos.students().insert(st);
    }
    // Cố ý KHÔNG ghi danh sinh viên cuối, để kiểm chứng lớp bù D.8-2
    if (i < SEED.mssv.length - 1 &&
        !Repos.enrollments().findOne({ StudentID: st.StudentID, ClassID: cls.ClassID })) {
      Repos.enrollments().insert({
        EnrollmentID: newId('ENR'), StudentID: st.StudentID, ClassID: cls.ClassID,
        Status: RECORD_STATUS.ACTIVE, CreatedAt: nowStamp()
      });
    }
  });
  out.push('4 sinh viên, 3 ghi danh (' + SEED.mssv[3] + ' cố ý KHÔNG ghi danh)');

  let ses = Repos.sessions().findOne({ ClassID: cls.ClassID, SessionNo: 1 });
  if (!ses) {
    ses = {
      SessionID: newId('SES'), ClassID: cls.ClassID, SessionNo: 1,
      Date: Utilities.formatDate(new Date(), CONFIG.TIMEZONE, 'yyyy-MM-dd'),
      DayOfWeek: '', StartTime: '07:30', EndTime: '10:00',
      Content: 'Buổi thử nghiệm', Status: RECORD_STATUS.ACTIVE, CreatedAt: nowStamp()
    };
    Repos.sessions().insert(ses);
    out.push('Tạo buổi học số 1 — SessionID: ' + ses.SessionID);
  } else {
    out.push('Buổi học số 1 đã có — SessionID: ' + ses.SessionID);
  }

  const msg = out.join('\n');
  Logger.log(msg);
  return msg;
}

/**
 * Kiểm thử đầu-cuối. Cần có sẵn tài khoản giảng viên
 * (chạy createLecturerAccounts trước) và seedDemoData.
 * Sửa 2 biến dưới cho khớp tài khoản của thầy.
 */
function runSmokeTest() {
  const USERNAME = 'tuan';
  const PASSWORD = 'DOI_MAT_KHAU_NAY';

  const log = [];
  const check = function (label, cond, detail) {
    log.push((cond ? 'PASS  ' : 'FAIL  ') + label + (detail ? '  -> ' + detail : ''));
  };

  const login = AuthService.login(USERNAME, PASSWORD);
  if (!login.ok) {
    const m = 'FAIL  Đăng nhập: ' + login.reason + '\n(Sửa USERNAME/PASSWORD trong runSmokeTest.)';
    Logger.log(m); return m;
  }
  check('Đăng nhập giảng viên', true, login.role);
  const token = login.token;

  const cls = Repos.classes().findOne({ ClassCode: SEED.classCode });
  const ses = Repos.sessions().findOne({ ClassID: cls.ClassID, SessionNo: 1 });

  // Dọn bản ghi điểm danh cũ của buổi thử
  const att = Repos.attendance();
  att.findWhere({ SessionID: ses.SessionID })
     .sort(function (a, b) { return b._row - a._row; })
     .forEach(function (r) { att.sheet.deleteRow(r._row); });

  // 1. Mở điểm danh
  const opened = AttendanceService.open(token, ses.SessionID, { presentMinutes: 5, windowMinutes: 15 });
  check('Mở điểm danh, sinh mã', /^[A-Z0-9]{4}$/.test(opened.code), 'mã = ' + opened.code);

  // 2. Sinh viên hợp lệ, GPS trong bán kính
  const r1 = AttendanceService.checkin({
    mssv: SEED.mssv[0], code: opened.code,
    lat: 10.7626, lng: 106.6822, accuracy: 20,
    os: 'Android', browser: 'Chrome', deviceType: 'Mobile', deviceHash: 'DEVICE_A'
  });
  check('SV1 điểm danh', r1.status === ATTENDANCE_STATUS.PRESENT && r1.gpsFlag === GPS_FLAG.VALID,
        r1.status + ' / ' + r1.gpsFlag);

  // 3. [D.8-3] Gửi lại lần hai phải UPDATE, không tạo dòng mới
  const r1b = AttendanceService.checkin({
    mssv: SEED.mssv[0], code: opened.code, lat: 10.7626, lng: 106.6822,
    accuracy: 20, deviceHash: 'DEVICE_A'
  });
  check('[D.8-3] Chống ghi trùng', r1b.action === 'UPDATED', 'action = ' + r1b.action);

  // 4. [D.8-4] SV2 dùng CHUNG thiết bị với SV1
  const r2 = AttendanceService.checkin({
    mssv: SEED.mssv[1], code: opened.code, lat: 10.7626, lng: 106.6822,
    accuracy: 20, deviceHash: 'DEVICE_A'
  });
  check('SV2 điểm danh cùng thiết bị', !!r2.mssv, r2.status);

  // 5. SV3 ở xa lớp 3km
  const r3 = AttendanceService.checkin({
    mssv: SEED.mssv[2], code: opened.code, lat: 10.7900, lng: 106.7000,
    accuracy: 15, deviceHash: 'DEVICE_C'
  });
  check('[D.3] GPS ngoài bán kính bị gắn cờ, KHÔNG bị loại',
        r3.gpsFlag === GPS_FLAG.OUT_OF_RANGE, 'cách ' + r3.distanceM + 'm');

  // 6. [D.8-2] SV4 không ghi danh -> phải bị từ chối
  let rejected = false, reason = '';
  try { AttendanceService.checkin({ mssv: SEED.mssv[3], code: opened.code }); }
  catch (err) { rejected = true; reason = err.message; }
  check('[D.8-2] Từ chối SV không ghi danh', rejected, reason);

  // 7. Mã sai -> phải bị từ chối
  let badCode = false;
  try { AttendanceService.checkin({ mssv: SEED.mssv[0], code: 'ZZZZ' }); }
  catch (err) { badCode = true; }
  check('Từ chối mã sai', badCode);

  // 8. [D.8-5] Danh sách thời gian thực + cảnh báo
  const roster = AttendanceService.liveRoster(token, ses.SessionID);
  check('[D.8-5] Danh sách thời gian thực', roster.rows.length === 3,
        'có mặt ' + roster.counts.present + ', trễ ' + roster.counts.late + ', vắng ' + roster.counts.absent);
  check('[D.8-4] Cảnh báo trùng thiết bị', roster.deviceAlerts.length === 1,
        JSON.stringify(roster.deviceAlerts));
  check('Cảnh báo GPS bất thường', roster.gpsAlerts.length === 1, JSON.stringify(roster.gpsAlerts));

  // 9. Đóng điểm danh -> SV4 chưa ghi danh nên KHÔNG bị đánh vắng
  const closed = AttendanceService.close(token, ses.SessionID);
  check('Đóng điểm danh, đánh dấu vắng', closed.markedAbsent === 0,
        'đánh vắng ' + closed.markedAbsent + ' (đúng: cả 3 SV ghi danh đều đã điểm danh)');

  // 10. Mã đã đóng -> không dùng lại được
  let closedReject = false;
  try { AttendanceService.checkin({ mssv: SEED.mssv[0], code: opened.code }); }
  catch (err) { closedReject = true; }
  check('[D.8-1] Mã hết hiệu lực sau khi đóng', closedReject);

  // 11. [D.8-6] Nhật ký
  const logs = Repos.audit().findWhere({ TargetID: ses.SessionID });
  check('[D.8-6] Ghi AUDIT_LOG', logs.length >= 5, logs.length + ' dòng');

  const failed = log.filter(function (l) { return l.indexOf('FAIL') === 0; }).length;
  const msg = log.join('\n') + '\n\n' + (failed ? failed + ' MỤC THẤT BẠI' : 'TẤT CẢ ĐỀU PASS');
  Logger.log(msg);
  return msg;
}
