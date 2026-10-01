<?php
declare(strict_types=1);

/**
 * api/lib/admin.php — GĐ7 (PLAN): quản trị web tối thiểu — CRUD môn/lớp/
 * sinh viên/buổi học + nhập CSV danh sách lớp. Đối chiếu đầy đủ tại
 * docs/04-API-PHP.md mục 16.
 *
 * Ranh giới với rule 3 ("code quản trị chỉ CLI") — thầy quyết 01/10/2026:
 *   - ĐƯỢC làm trên web: tính năng có token LECTURER/ADMIN, kiểm vai trò và
 *     giới hạn đúng lớp mình dạy (assert_class_access) — chính là các action
 *     trong file này.
 *   - VẪN CLI trong tools/: tạo tài khoản giảng viên, di dời/nhập điểm hàng
 *     loạt không ràng buộc lớp, sửa dữ liệu thật một lần.
 *
 * Phân quyền (thầy quyết 01/10/2026):
 *   - ADMIN: tạo/sửa mọi môn, lớp, sinh viên, buổi học; gán giảng viên.
 *   - LECTURER: chỉ lớp mình đứng tên (classes.LecturerID) — sửa thông tin
 *     lớp (KHÔNG đổi môn/giảng viên), thêm/bớt sinh viên, nhập CSV, buổi học.
 *
 * KHÔNG XOÁ dòng nào — chỉ đổi Status sang INACTIVE (attendance/grades có
 * khoá ngoại tới students/classes/sessions; giữ lịch sử để phân xử khiếu nại).
 * Mọi thao tác ghi đều ghi audit_log (D.8 lớp 6).
 */

const ADMIN_IMPORT_MAX_ROWS = 1000;
const ADMIN_CSV_MAX_BYTES = 512 * 1024;    // ~512 KB, quá đủ cho 1000 dòng
const ADMIN_MAX_ERRORS_REPORTED = 200;     // không json_encode hàng triệu lỗi

/* ------------------------------------------------------------------ */
/*  Hàm phụ dùng chung                                                  */
/* ------------------------------------------------------------------ */

/** Chuỗi đã trim, cắt theo độ dài cột để không văng lỗi "Data too long". */
function admin_str(array $p, string $key, int $max): string
{
    return mb_substr(trim((string) ($p[$key] ?? '')), 0, $max);
}

/** Số thập phân hoặc null nếu rỗng/không hợp lệ. */
function admin_num(array $p, string $key): ?float
{
    if (!isset($p[$key]) || $p[$key] === '' || $p[$key] === null) {
        return null;
    }
    return is_numeric($p[$key]) ? (float) $p[$key] : null;
}

/** Status chỉ nhận ACTIVE/INACTIVE; rỗng → giá trị mặc định. */
function admin_status(array $p, string $default = 'ACTIVE'): string
{
    $s = strtoupper(trim((string) ($p['status'] ?? '')));
    return in_array($s, ['ACTIVE', 'INACTIVE'], true) ? $s : $default;
}

/** Lớp theo ClassID, kèm kiểm quyền. Ném lỗi nếu không có/không có quyền. */
function admin_load_class(array $me, string $classId): array
{
    if ($classId === '') {
        throw new RuntimeException('Thiếu mã lớp (classId).');
    }
    $stmt = db()->prepare('SELECT * FROM classes WHERE ClassID = :id LIMIT 1');
    $stmt->execute(['id' => $classId]);
    $cls = $stmt->fetch();
    if (!$cls) {
        throw new RuntimeException('Không tìm thấy lớp ' . $classId . '.');
    }
    assert_class_access($me, $classId);
    return $cls;
}

/** Chuẩn hoá tiêu đề cột CSV: bỏ dấu, bỏ ký tự lạ, chữ thường (normalizeHeader_ cũ). */
function admin_norm_header(string $s): string
{
    $s = str_replace(['đ', 'Đ'], ['d', 'D'], $s);
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($s, Normalizer::FORM_D);
        if ($n !== false) {
            $s = preg_replace('/\p{Mn}+/u', '', $n) ?? $s;
        }
    } else {
        $s = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    }
    return preg_replace('/[^a-z0-9]/', '', strtolower($s)) ?? '';
}

/* ------------------------------------------------------------------ */
/*  Đọc                                                                 */
/* ------------------------------------------------------------------ */

/** adminListCourses — GET. LECTURER/ADMIN. Mọi môn (kể cả INACTIVE, để sửa lại). */
function action_admin_list_courses(array $params): void
{
    require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    api_ok(db()->query('SELECT * FROM courses ORDER BY Status ASC, CourseCode ASC')->fetchAll());
}

/** adminListLecturers — GET. ADMIN. Danh sách tài khoản để gán vào lớp (không trả hash). */
function action_admin_list_lecturers(array $params): void
{
    require_role((string) ($params['token'] ?? ''), ['ADMIN']);
    api_ok(db()->query(
        "SELECT UserID, Username, FullName, Role, Status FROM users " .
        "WHERE Role IN ('LECTURER','ADMIN') ORDER BY Status ASC, FullName ASC"
    )->fetchAll());
}

/**
 * adminListClasses — GET. Khác listClasses (chỉ ACTIVE, dành cho màn điểm
 * danh): trả cả lớp INACTIVE, kèm tên môn và tên giảng viên để hiện bảng
 * quản trị. [D.9] LECTURER chỉ thấy lớp mình.
 */
function action_admin_list_classes(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);

    $rows = db()->query(
        'SELECT cl.*, co.CourseCode, co.CourseName FROM classes cl ' .
        'JOIN courses co ON co.CourseID = cl.CourseID ' .
        'ORDER BY cl.Status ASC, cl.AcademicYear DESC, cl.Semester DESC, cl.ClassCode ASC'
    )->fetchAll();

    if ($me['role'] !== 'ADMIN') {
        $rows = array_values(array_filter($rows, static fn (array $c): bool =>
            class_has_lecturer((string) $c['LecturerID'], $me['userId'])));
    }

    $users = [];
    foreach (db()->query('SELECT UserID, FullName FROM users')->fetchAll() as $u) {
        $users[(string) $u['UserID']] = (string) $u['FullName'];
    }
    foreach ($rows as &$r) {
        $names = [];
        foreach (preg_split('/[,;.\s]+/', trim((string) $r['LecturerID'])) ?: [] as $id) {
            if ($id !== '') {
                $names[] = $users[$id] ?? $id;
            }
        }
        $r['LecturerNames'] = implode(', ', $names);
    }
    unset($r);

    api_ok($rows);
}

/** adminListRoster — GET. Sinh viên ghi danh (cả INACTIVE) của một lớp. assert_class_access. */
function action_admin_list_roster(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $classId = trim((string) ($params['classId'] ?? ''));
    admin_load_class($me, $classId);

    $stmt = db()->prepare(
        'SELECT s.StudentID, s.MSSV, s.FullName, s.Email, s.Status AS StudentStatus, ' .
        'en.EnrollmentID, en.Status AS EnrollStatus ' .
        'FROM enrollments en JOIN students s ON s.StudentID = en.StudentID ' .
        'WHERE en.ClassID = :cid ORDER BY en.Status ASC, s.MSSV ASC'
    );
    $stmt->execute(['cid' => $classId]);
    api_ok($stmt->fetchAll());
}

/** adminListSessions — GET. Khác listSessions: trả cả buổi INACTIVE. assert_class_access. */
function action_admin_list_sessions(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $classId = trim((string) ($params['classId'] ?? ''));
    admin_load_class($me, $classId);

    $stmt = db()->prepare('SELECT * FROM sessions WHERE ClassID = :cid ORDER BY SessionNo ASC');
    $stmt->execute(['cid' => $classId]);
    api_ok($stmt->fetchAll());
}

/* ------------------------------------------------------------------ */
/*  Ghi — môn                                                           */
/* ------------------------------------------------------------------ */

/** adminSaveCourse — POST. ADMIN. Có courseId → sửa; không → tạo mới. */
function action_admin_save_course(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['ADMIN']);

    $courseId = admin_str($params, 'courseId', 40);
    $code = admin_str($params, 'courseCode', 30);
    $name = admin_str($params, 'courseName', 200);
    if ($code === '' || $name === '') {
        api_fail('Thiếu mã môn hoặc tên môn.');
        return;
    }

    $isNew = $courseId === '';
    $old = null;
    if (!$isNew) {
        $st = db()->prepare('SELECT * FROM courses WHERE CourseID = :id LIMIT 1');
        $st->execute(['id' => $courseId]);
        $old = $st->fetch() ?: null;
        if (!$old) {
            api_fail('Không tìm thấy môn ' . $courseId . '.');
            return;
        }
    }

    // GĐ9 review lần 2 (M8): giữ nguyên cột không gửi.
    $keepC = static fn (string $k, string $col, $val) => $isNew || array_key_exists($k, $params) ? $val : $old[$col];
    $data = [
        'CourseCode'    => $code,
        'CourseName'    => $name,
        'Credits'       => $keepC('credits', 'Credits', admin_num($params, 'credits')),
        'TheoryHours'   => $keepC('theoryHours', 'TheoryHours', admin_num($params, 'theoryHours') === null ? null : (int) admin_num($params, 'theoryHours')),
        'PracticeHours' => $keepC('practiceHours', 'PracticeHours', admin_num($params, 'practiceHours') === null ? null : (int) admin_num($params, 'practiceHours')),
        'Status'        => $keepC('status', 'Status', admin_status($params, $old ? (string) $old['Status'] : 'ACTIVE')),
    ];
    $courseId = db_transaction(function (PDO $pdo) use ($data, $courseId, $isNew): string {
        if ($isNew) {
            $id = new_id('CRS');
            $pdo->prepare(
                'INSERT INTO courses (CourseID, CourseCode, CourseName, Credits, TheoryHours, PracticeHours, Status) ' .
                'VALUES (:id, :CourseCode, :CourseName, :Credits, :TheoryHours, :PracticeHours, :Status)'
            )->execute($data + ['id' => $id]);
            return $id;
        }
        $upd = $pdo->prepare(
            'UPDATE courses SET CourseCode = :CourseCode, CourseName = :CourseName, Credits = :Credits, ' .
            'TheoryHours = :TheoryHours, PracticeHours = :PracticeHours, Status = :Status WHERE CourseID = :id'
        );
        $upd->execute($data + ['id' => $courseId]);
        if ($upd->rowCount() === 0) {
            $chk = $pdo->prepare('SELECT 1 FROM courses WHERE CourseID = :id');
            $chk->execute(['id' => $courseId]);
            if (!$chk->fetch()) {
                throw new RuntimeException('Không tìm thấy môn ' . $courseId . '.');
            }
        }
        return $courseId;
    });

    log_audit($me['userId'], $me['role'], $isNew ? 'ADMIN_COURSE_CREATE' : 'ADMIN_COURSE_UPDATE', 'COURSE', $courseId, $data);
    api_ok(['courseId' => $courseId, 'action' => $isNew ? 'INSERTED' : 'UPDATED']);
}

/* ------------------------------------------------------------------ */
/*  Ghi — lớp                                                           */
/* ------------------------------------------------------------------ */

/**
 * adminSaveClass — POST. ADMIN tạo/sửa mọi lớp (kể cả đổi môn, gán giảng
 * viên). LECTURER chỉ sửa lớp mình: mã lớp, học kỳ, năm học, toạ độ phòng,
 * bán kính, trạng thái — KHÔNG đổi courseId/lecturerIds (bỏ qua nếu gửi).
 */
function action_admin_save_class(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);

    $classId = admin_str($params, 'classId', 40);
    $isNew = $classId === '';
    $existing = null;
    if (!$isNew) {
        $existing = admin_load_class($me, $classId); // ném lỗi nếu LECTURER không đứng tên
    } elseif ($me['role'] !== 'ADMIN') {
        api_fail('Chỉ ADMIN mới tạo được lớp mới.');
        return;
    }

    $classCode = admin_str($params, 'classCode', 40);
    if ($classCode === '') {
        api_fail('Thiếu mã lớp (classCode).');
        return;
    }

    // GĐ9 review lần 2 (M8): chỉ ghi những cột CÓ TRONG request. Trước đây gọi
    // adminSaveClass mà không gửi roomLat/roomLng là XOÁ toạ độ phòng → evaluate_gps()
    // trả VALID cho mọi check-in, tức tắt kiểm GPS của lớp đó mà không ai hay.
    $data = ['ClassCode' => $classCode];
    if ($isNew || array_key_exists('semester', $params))       $data['Semester'] = admin_str($params, 'semester', 10);
    if ($isNew || array_key_exists('academicYear', $params))   $data['AcademicYear'] = admin_str($params, 'academicYear', 20);
    if ($isNew || array_key_exists('roomLat', $params))        $data['RoomLat'] = admin_num($params, 'roomLat');
    if ($isNew || array_key_exists('roomLng', $params))        $data['RoomLng'] = admin_num($params, 'roomLng');
    if ($isNew || array_key_exists('allowedRadiusM', $params)) {
        $data['AllowedRadiusM'] = admin_num($params, 'allowedRadiusM') === null ? 100 : max(10, (int) admin_num($params, 'allowedRadiusM'));
    }
    if ($isNew || array_key_exists('status', $params)) {
        $data['Status'] = admin_status($params, $existing ? (string) $existing['Status'] : 'ACTIVE');
    }

    if ($me['role'] === 'ADMIN') {
        $courseId = admin_str($params, 'courseId', 40);
        // Nhiều giảng viên cách nhau bởi dấu phẩy — giữ đúng quy ước class_has_lecturer().
        $lecturerIds = implode(',', array_values(array_filter(array_map('trim',
            preg_split('/[,;\s]+/', (string) ($params['lecturerIds'] ?? '')) ?: []), 'strlen')));
        if ($isNew && ($courseId === '' || $lecturerIds === '')) {
            api_fail('Lớp mới cần có môn (courseId) và ít nhất một giảng viên (lecturerIds).');
            return;
        }
        if ($courseId !== '') {
            $chk = db()->prepare('SELECT 1 FROM courses WHERE CourseID = :id');
            $chk->execute(['id' => $courseId]);
            if (!$chk->fetch()) {
                api_fail('Không tìm thấy môn ' . $courseId . '.');
                return;
            }
            $data['CourseID'] = $courseId;
        }
        if ($lecturerIds !== '') {
            // Cột VARCHAR(40): cắt bớt là âm thầm làm một giảng viên mất quyền
            // (GĐ9 review lần 2, L9) → kiểm độ dài TRƯỚC, báo lỗi rõ ràng.
            if (strlen($lecturerIds) > 40) {
                api_fail('Danh sách giảng viên dài ' . strlen($lecturerIds) . ' ký tự, vượt giới hạn 40 của cột LecturerID — chọn ít giảng viên hơn cho một lớp.');
                return;
            }
            // Mọi UserID phải tồn tại và là LECTURER/ADMIN. Cột lưu DANH SÁCH nên
            // KHÔNG có khoá ngoại (db/migrations/003) — ứng dụng phải tự kiểm ở đây.
            foreach (explode(',', $lecturerIds) as $uid) {
                $chk = db()->prepare("SELECT 1 FROM users WHERE UserID = :id AND Role IN ('LECTURER','ADMIN')");
                $chk->execute(['id' => $uid]);
                if (!$chk->fetch()) {
                    api_fail('Không tìm thấy giảng viên ' . $uid . '.');
                    return;
                }
            }
            $data['LecturerID'] = $lecturerIds;
        }
    }

    $classId = db_transaction(function (PDO $pdo) use ($data, $classId, $isNew): string {
        if ($isNew) {
            $id = new_id('CLS');
            $cols = array_keys($data);
            $pdo->prepare(
                'INSERT INTO classes (ClassID, ' . implode(', ', $cols) . ') VALUES (:id, ' .
                implode(', ', array_map(static fn ($c) => ":$c", $cols)) . ')'
            )->execute($data + ['id' => $id]);
            return $id;
        }
        $pdo->prepare(
            'UPDATE classes SET ' . implode(', ', array_map(static fn ($c) => "$c = :$c", array_keys($data))) .
            ' WHERE ClassID = :id'
        )->execute($data + ['id' => $classId]);
        return $classId;
    });

    log_audit($me['userId'], $me['role'], $isNew ? 'ADMIN_CLASS_CREATE' : 'ADMIN_CLASS_UPDATE', 'CLASS', $classId, $data);
    api_ok(['classId' => $classId, 'action' => $isNew ? 'INSERTED' : 'UPDATED']);
}

/* ------------------------------------------------------------------ */
/*  Ghi — buổi học                                                      */
/* ------------------------------------------------------------------ */

/** adminSaveSession — POST. LECTURER (lớp mình)/ADMIN. Có sessionId → sửa; không → tạo. */
function action_admin_save_session(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $classId = admin_str($params, 'classId', 40);
    admin_load_class($me, $classId);

    $sessionId = admin_str($params, 'sessionId', 40);
    $sessionNo = (int) ($params['sessionNo'] ?? 0);
    if ($sessionNo <= 0) {
        api_fail('Số thứ tự buổi (sessionNo) phải là số nguyên dương.');
        return;
    }
    $date = admin_str($params, 'date', 10);
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        api_fail('Ngày học phải có dạng YYYY-MM-DD.');
        return;
    }
    foreach (['startTime', 'endTime'] as $k) {
        $v = admin_str($params, $k, 5);
        if ($v !== '' && !preg_match('/^\d{2}:\d{2}$/', $v)) {
            api_fail('Giờ (' . $k . ') phải có dạng HH:MM.');
            return;
        }
    }

    $isNew = $sessionId === '';
    $old = null;
    if (!$isNew) {
        $st = db()->prepare('SELECT * FROM sessions WHERE SessionID = :id AND ClassID = :cid LIMIT 1');
        $st->execute(['id' => $sessionId, 'cid' => $classId]);
        $old = $st->fetch() ?: null;
        if (!$old) {
            api_fail('Không tìm thấy buổi học ' . $sessionId . ' trong lớp này.');
            return;
        }
    }

    // GĐ9 review lần 2 (M8): giữ nguyên cột không gửi, không xoá Date/Content.
    $data = ['SessionNo' => $sessionNo];
    $keep = static fn (string $k, string $col, $val) => $isNew || array_key_exists($k, $params) ? $val : $old[$col];
    $data['Date']      = $keep('date', 'Date', $date === '' ? null : $date);
    $data['DayOfWeek'] = $keep('dayOfWeek', 'DayOfWeek', admin_str($params, 'dayOfWeek', 20));
    $data['StartTime'] = $keep('startTime', 'StartTime', admin_str($params, 'startTime', 5) ?: null);
    $data['EndTime']   = $keep('endTime', 'EndTime', admin_str($params, 'endTime', 5) ?: null);
    $data['Content']   = $keep('content', 'Content', trim((string) ($params['content'] ?? '')));
    $data['Status']    = $keep('status', 'Status', admin_status($params, $old ? (string) $old['Status'] : 'ACTIVE'));
    $sessionId = db_transaction(function (PDO $pdo) use ($data, $classId, $sessionId, $isNew): string {
        if ($isNew) {
            $id = new_id('SES');
            $pdo->prepare(
                'INSERT INTO sessions (SessionID, ClassID, SessionNo, `Date`, DayOfWeek, StartTime, EndTime, Content, Status) ' .
                'VALUES (:id, :cid, :SessionNo, :Date, :DayOfWeek, :StartTime, :EndTime, :Content, :Status)'
            )->execute($data + ['id' => $id, 'cid' => $classId]);
            return $id;
        }
        // WHERE kèm ClassID — không sửa nhầm buổi của lớp khác bằng sessionId lạ.
        $upd = $pdo->prepare(
            'UPDATE sessions SET SessionNo = :SessionNo, `Date` = :Date, DayOfWeek = :DayOfWeek, ' .
            'StartTime = :StartTime, EndTime = :EndTime, Content = :Content, Status = :Status ' .
            'WHERE SessionID = :id AND ClassID = :cid'
        );
        $upd->execute($data + ['id' => $sessionId, 'cid' => $classId]);
        return $sessionId;
    });

    log_audit($me['userId'], $me['role'], $isNew ? 'ADMIN_SESSION_CREATE' : 'ADMIN_SESSION_UPDATE', 'SESSION', $sessionId, $data + ['classId' => $classId]);
    api_ok(['sessionId' => $sessionId, 'action' => $isNew ? 'INSERTED' : 'UPDATED']);
}

/* ------------------------------------------------------------------ */
/*  Ghi — sinh viên & ghi danh                                          */
/* ------------------------------------------------------------------ */

/**
 * adminSaveStudent — POST. Sửa thông tin một sinh viên (họ tên, email,
 * trạng thái) theo MSSV. ADMIN: mọi sinh viên, kể cả tạo mới. LECTURER: chỉ
 * sinh viên đang ghi danh ở lớp mình (tạo mới thì dùng adminEnroll/CSV).
 */
function action_admin_save_student(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);

    $mssv = strtoupper(admin_str($params, 'mssv', 12));
    if (!preg_match('/^[0-9]{6,10}$/', $mssv)) {
        api_fail('MSSV không hợp lệ (phải là 6–10 chữ số).');
        return;
    }
    $fullName = admin_str($params, 'fullName', 120);
    $email = admin_str($params, 'email', 160);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_fail('Email không hợp lệ.');
        return;
    }

    $stmt = db()->prepare('SELECT * FROM students WHERE MSSV = :m LIMIT 1');
    $stmt->execute(['m' => $mssv]);
    $student = $stmt->fetch();

    if ($me['role'] !== 'ADMIN') {
        if (!$student) {
            api_fail('Sinh viên chưa có trong hệ thống — thêm vào lớp bằng "Thêm sinh viên" hoặc nhập CSV.');
            return;
        }
        $own = db()->prepare(
            "SELECT cl.LecturerID FROM enrollments en JOIN classes cl ON cl.ClassID = en.ClassID " .
            "WHERE en.StudentID = :sid AND en.Status = 'ACTIVE'"
        );
        $own->execute(['sid' => $student['StudentID']]);
        $allowed = false;
        foreach ($own->fetchAll() as $r) {
            if (class_has_lecturer((string) $r['LecturerID'], $me['userId'])) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            api_fail('Sinh viên này không thuộc lớp nào của bạn.');
            return;
        }
    }

    if (!$student && $fullName === '') {
        api_fail('Sinh viên mới cần có họ tên.');
        return;
    }
    // GĐ9 review lần 2 (H3): email là đường nhận mã xem điểm → chỉ ADMIN đổi được
    // email của sinh viên đã có. LECTURER sửa được họ tên (lớp mình) và điền email
    // khi ô đang trống.
    if ($student && $email !== '' && $email !== (string) ($student['Email'] ?? '')
        && trim((string) ($student['Email'] ?? '')) !== '' && $me['role'] !== 'ADMIN') {
        api_fail('Chỉ quản trị viên đổi được email của sinh viên đã có email (email là đường nhận mã xem điểm).');
        return;
    }

    $data = [
        'FullName' => $fullName !== '' ? $fullName : (string) $student['FullName'],
        'Email'    => $email !== '' ? $email : ($student ? $student['Email'] : null),
        'Status'   => admin_status($params, $student ? (string) $student['Status'] : 'ACTIVE'),
    ];

    $isNew = !$student;
    $studentId = db_transaction(function (PDO $pdo) use ($data, $student, $mssv, $isNew): string {
        if ($isNew) {
            $id = new_id('STD');
            $pdo->prepare(
                'INSERT INTO students (StudentID, MSSV, FullName, Email, Status) VALUES (:id, :m, :FullName, :Email, :Status)'
            )->execute($data + ['id' => $id, 'm' => $mssv]);
            return $id;
        }
        $pdo->prepare('UPDATE students SET FullName = :FullName, Email = :Email, Status = :Status WHERE StudentID = :id')
            ->execute($data + ['id' => $student['StudentID']]);
        return (string) $student['StudentID'];
    });

    log_audit($me['userId'], $me['role'], $isNew ? 'ADMIN_STUDENT_CREATE' : 'ADMIN_STUDENT_UPDATE', 'STUDENT', $studentId, ['mssv' => $mssv] + $data);
    api_ok(['studentId' => $studentId, 'mssv' => $mssv, 'action' => $isNew ? 'INSERTED' : 'UPDATED']);
}

/**
 * Lõi dùng chung cho adminEnroll và adminImportRoster: upsert một sinh viên
 * theo MSSV rồi ghi danh vào lớp. Trả về mã kết quả để đếm. KHÔNG tự mở
 * transaction — nơi gọi bọc.
 *
 * @return array{student:string, enroll:string} mỗi giá trị: INSERTED|UPDATED|UNCHANGED|REACTIVATED
 */
function admin_upsert_enroll(PDO $pdo, string $classId, string $mssv, string $fullName, string $email): array
{
    $out = ['student' => 'UNCHANGED', 'enroll' => 'UNCHANGED'];

    $stmt = $pdo->prepare('SELECT * FROM students WHERE MSSV = :m LIMIT 1 FOR UPDATE');
    $stmt->execute(['m' => $mssv]);
    $student = $stmt->fetch();

    if (!$student) {
        if ($fullName === '') {
            throw new RuntimeException('MSSV ' . $mssv . ' chưa có trong hệ thống và thiếu họ tên.');
        }
        $studentId = new_id('STD');
        $pdo->prepare("INSERT INTO students (StudentID, MSSV, FullName, Email, Status) VALUES (:id, :m, :n, :e, 'ACTIVE')")
            ->execute(['id' => $studentId, 'm' => $mssv, 'n' => $fullName, 'e' => $email !== '' ? $email : null]);
        $out['student'] = 'INSERTED';
    } else {
        // GĐ9 review lần 2 (H3): MSSV đã có trong hệ thống thì KHÔNG ghi đè họ tên/
        // email đang có — chỉ ĐIỀN VÀO CHỖ TRỐNG. Trước đây ai dạy một lớp bất kỳ
        // cũng đổi được email của MỌI sinh viên trong trường chỉ bằng MSSV (qua
        // adminEnroll/adminImportRoster), rồi xin mã xem điểm về hộp thư của mình
        // → xem được điểm mọi lớp của em đó. Cũng KHÔNG đụng Status: bật lại một
        // SV mà ADMIN đã khoá là việc của ADMIN (adminSaveStudent).
        $studentId = (string) $student['StudentID'];
        $set = [];
        $bind = ['id' => $studentId];
        if ($fullName !== '' && trim((string) $student['FullName']) === '') {
            $set[] = 'FullName = :n';
            $bind['n'] = $fullName;
        }
        if ($email !== '' && trim((string) ($student['Email'] ?? '')) === '') {
            $set[] = 'Email = :e';
            $bind['e'] = $email;
        }
        if ($set) {
            $pdo->prepare('UPDATE students SET ' . implode(', ', $set) . ' WHERE StudentID = :id')->execute($bind);
            $out['student'] = 'UPDATED';
        }
    }

    $stmt = $pdo->prepare('SELECT EnrollmentID, Status FROM enrollments WHERE StudentID = :sid AND ClassID = :cid LIMIT 1');
    $stmt->execute(['sid' => $studentId, 'cid' => $classId]);
    $en = $stmt->fetch();
    if (!$en) {
        $pdo->prepare("INSERT INTO enrollments (EnrollmentID, StudentID, ClassID, Status) VALUES (:id, :sid, :cid, 'ACTIVE')")
            ->execute(['id' => new_id('ENR'), 'sid' => $studentId, 'cid' => $classId]);
        $out['enroll'] = 'INSERTED';
    } elseif ($en['Status'] !== 'ACTIVE') {
        $pdo->prepare("UPDATE enrollments SET Status = 'ACTIVE' WHERE EnrollmentID = :id")->execute(['id' => $en['EnrollmentID']]);
        $out['enroll'] = 'REACTIVATED';
    }

    return $out;
}

/** adminEnroll — POST. Thêm MỘT sinh viên vào lớp (tạo mới nếu chưa có). assert_class_access. */
function action_admin_enroll(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $classId = admin_str($params, 'classId', 40);
    admin_load_class($me, $classId);

    $mssv = strtoupper(admin_str($params, 'mssv', 12));
    if (!preg_match('/^[0-9]{6,10}$/', $mssv)) {
        api_fail('MSSV không hợp lệ (phải là 6–10 chữ số).');
        return;
    }
    $email = admin_str($params, 'email', 160);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_fail('Email không hợp lệ.');
        return;
    }
    $fullName = admin_str($params, 'fullName', 120);

    $res = db_transaction(static fn (PDO $pdo): array => admin_upsert_enroll($pdo, $classId, $mssv, $fullName, $email));

    log_audit($me['userId'], $me['role'], 'ADMIN_ENROLL', 'CLASS', $classId, ['mssv' => $mssv] + $res);
    api_ok(['mssv' => $mssv, 'classId' => $classId] + $res);
}

/** adminUnenroll — POST. Gỡ sinh viên khỏi lớp = đổi enrollments.Status → INACTIVE (không xoá). */
function action_admin_unenroll(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $classId = admin_str($params, 'classId', 40);
    admin_load_class($me, $classId);
    $mssv = strtoupper(admin_str($params, 'mssv', 12));

    $n = db_transaction(static function (PDO $pdo) use ($classId, $mssv): int {
        $upd = $pdo->prepare(
            "UPDATE enrollments en JOIN students s ON s.StudentID = en.StudentID " .
            "SET en.Status = 'INACTIVE' WHERE en.ClassID = :cid AND s.MSSV = :m AND en.Status = 'ACTIVE'"
        );
        $upd->execute(['cid' => $classId, 'm' => $mssv]);
        return $upd->rowCount();
    });
    if ($n === 0) {
        api_fail('MSSV ' . $mssv . ' không có trong danh sách (đang hoạt động) của lớp này.');
        return;
    }

    log_audit($me['userId'], $me['role'], 'ADMIN_UNENROLL', 'CLASS', $classId, ['mssv' => $mssv]);
    api_ok(['mssv' => $mssv, 'classId' => $classId, 'action' => 'DEACTIVATED']);
}

/**
 * adminImportRoster — POST. Nhập CSV danh sách lớp (thay runImport/previewImport
 * của gas/07-Import.gs, nhưng cho MỘT lớp đã chọn, có token + assert_class_access).
 *
 * Tham số: classId, csv (nguyên văn file CSV, dòng đầu là tiêu đề),
 * dryRun (true → chỉ phân tích, KHÔNG ghi — đúng tinh thần previewImport()).
 * Tiêu đề cột nhận diện theo bí danh (không dấu, không phân biệt hoa thường):
 * MSSV | Họ tên (hoặc Họ đệm + Tên) | Email. Cột khác bị bỏ qua.
 *
 * Ghi thật: MỘT transaction cho cả file — một dòng lỗi thì không ghi gì cả.
 * Chạy lại cùng file an toàn (upsert theo MSSV, ghi danh đã có thì bỏ qua).
 */
function action_admin_import_roster(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $classId = admin_str($params, 'classId', 40);
    admin_load_class($me, $classId);

    $csv = (string) ($params['csv'] ?? '');
    $dryRun = filter_var($params['dryRun'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $parsed = admin_parse_roster_csv($csv);
    if (isset($parsed['error'])) {
        api_fail((string) $parsed['error']);
        return;
    }
    $rows = $parsed['rows'];
    $errors = $parsed['errors'];

    if (count($rows) + count($errors) === 0) {
        api_fail('File không có dòng dữ liệu nào.');
        return;
    }
    if (count($rows) > ADMIN_IMPORT_MAX_ROWS) {
        api_fail('File quá lớn (tối đa ' . ADMIN_IMPORT_MAX_ROWS . ' dòng mỗi lần).');
        return;
    }

    // Phân tích (dùng cho cả dry-run lẫn ghi thật): sinh viên nào mới, ai đã ghi danh.
    $mssvs = array_column($rows, 'mssv');
    $known = [];
    $enrolled = [];
    if ($mssvs) {
        $in = implode(',', array_fill(0, count($mssvs), '?'));
        $stmt = db()->prepare("SELECT StudentID, MSSV FROM students WHERE MSSV IN ($in)");
        $stmt->execute($mssvs);
        foreach ($stmt->fetchAll() as $s) {
            $known[(string) $s['MSSV']] = (string) $s['StudentID'];
        }
        if ($known) {
            $inIds = implode(',', array_fill(0, count($known), '?'));
            $stmt = db()->prepare("SELECT StudentID FROM enrollments WHERE ClassID = ? AND Status = 'ACTIVE' AND StudentID IN ($inIds)");
            $stmt->execute(array_merge([$classId], array_values($known)));
            foreach ($stmt->fetchAll() as $e) {
                $enrolled[(string) $e['StudentID']] = true;
            }
        }
    }

    $plan = ['newStudents' => 0, 'existingStudents' => 0, 'newEnrollments' => 0, 'alreadyEnrolled' => 0];
    $valid = [];
    foreach ($rows as $r) {
        $sid = $known[$r['mssv']] ?? null;
        if ($sid === null && $r['fullName'] === '') {
            $errors[] = ['line' => $r['line'], 'error' => 'MSSV ' . $r['mssv'] . ' chưa có trong hệ thống và dòng này thiếu họ tên.'];
            continue;
        }
        $r['studentExists'] = $sid !== null;
        $r['alreadyEnrolled'] = $sid !== null && isset($enrolled[$sid]);
        $plan[$sid === null ? 'newStudents' : 'existingStudents']++;
        $plan[$r['alreadyEnrolled'] ? 'alreadyEnrolled' : 'newEnrollments']++;
        $valid[] = $r;
    }
    $rows = $valid;
    usort($errors, static fn ($a, $b) => $a['line'] <=> $b['line']);

    $report = [
        'classId'   => $classId,
        'dryRun'    => $dryRun,
        'columns'   => $parsed['columns'],
        'totalRows' => count($rows) + count($errors),
        'validRows' => count($rows),
        'plan'      => $plan,
        'errorCount' => count($errors),
        'errors'    => array_slice($errors, 0, ADMIN_MAX_ERRORS_REPORTED),
        'preview'   => array_slice(array_map(static fn ($r) => [
            'line' => $r['line'], 'mssv' => $r['mssv'], 'fullName' => $r['fullName'], 'email' => $r['email'],
            'studentExists' => $r['studentExists'], 'alreadyEnrolled' => $r['alreadyEnrolled'],
        ], $rows), 0, 20),
        'written'   => false,
    ];

    if ($dryRun) {
        api_ok($report);
        return;
    }
    if ($errors) {
        api_fail('File có ' . count($errors) . ' dòng lỗi — sửa file rồi nhập lại. Không ghi gì. (Dòng ' .
            implode(', ', array_slice(array_column($errors, 'line'), 0, 10)) . '…)');
        return;
    }

    $counts = ['studentsInserted' => 0, 'studentsUpdated' => 0, 'enrollmentsInserted' => 0, 'enrollmentsReactivated' => 0];
    db_transaction(static function (PDO $pdo) use ($rows, $classId, &$counts): void {
        foreach ($rows as $r) {
            $res = admin_upsert_enroll($pdo, $classId, $r['mssv'], $r['fullName'], $r['email']);
            if ($res['student'] === 'INSERTED') $counts['studentsInserted']++;
            if ($res['student'] === 'UPDATED') $counts['studentsUpdated']++;
            if ($res['enroll'] === 'INSERTED') $counts['enrollmentsInserted']++;
            if ($res['enroll'] === 'REACTIVATED') $counts['enrollmentsReactivated']++;
        }
    });

    log_audit($me['userId'], $me['role'], 'ADMIN_IMPORT_ROSTER', 'CLASS', $classId, ['rows' => count($rows)] + $counts);
    $report['written'] = true;
    $report['counts'] = $counts;
    api_ok($report);
}

/**
 * Đọc CSV danh sách lớp. Tự nhận dấu phân cách (, ; tab), bỏ BOM, nhận diện
 * cột theo bí danh như COLUMN_ALIASES của gas/07-Import.gs.
 *
 * @return array{error?:string, rows?:array, errors?:array, columns?:array}
 */
function admin_parse_roster_csv(string $csv): array
{
    // Chặn file quá lớn TRƯỚC khi phân tích (GĐ9 review lần 2, M7).
    if (strlen($csv) > ADMIN_CSV_MAX_BYTES) {
        return ['error' => 'File quá lớn (' . round(strlen($csv) / 1024) . ' KB, tối đa ' . round(ADMIN_CSV_MAX_BYTES / 1024) . ' KB).'];
    }
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
    $csv = str_replace(["\r\n", "\r"], "\n", trim($csv));
    if ($csv === '') {
        return ['error' => 'Chưa có nội dung CSV.'];
    }
    $lines = explode("\n", $csv);
    if (count($lines) - 1 > ADMIN_IMPORT_MAX_ROWS) {
        return ['error' => 'File có ' . (count($lines) - 1) . ' dòng, quá ' . ADMIN_IMPORT_MAX_ROWS . ' dòng cho phép mỗi lần.'];
    }
    $header = $lines[0];
    $delim = ',';
    foreach ([';', "\t", ','] as $d) {
        if (substr_count($header, $d) > substr_count($header, $delim)) {
            $delim = $d;
        }
    }

    $aliases = [
        'mssv'      => ['mssv', 'masosinhvien', 'masv', 'mshs', 'sohieusinhvien', 'studentid', 'studentcode', 'id', 'ma'],
        'fullName'  => ['hoten', 'hovaten', 'hovatenlot', 'tensinhvien', 'hotensinhvien', 'fullname', 'name', 'hovatendem'],
        'lastName'  => ['hodem', 'holot', 'ho', 'hovadem', 'lastname', 'surname'],
        'firstName' => ['ten', 'tensv', 'firstname', 'givenname'],
        'email'     => ['email', 'mail', 'diachiemail', 'emailsinhvien'],
    ];
    $headers = array_map('trim', str_getcsv($header, $delim, '"', '\\'));
    $map = [];
    $used = [];
    foreach ($aliases as $field => $names) {
        foreach ($headers as $i => $h) {
            if (isset($used[$i]) || $h === '') continue;
            if (in_array(admin_norm_header($h), $names, true)) {
                $map[$field] = $i;
                $used[$i] = true;
                break;
            }
        }
    }
    if (!isset($map['fullName']) && isset($map['firstName']) && !isset($map['lastName'])) {
        $map['fullName'] = $map['firstName'];
        unset($map['firstName']);
    }
    if (!isset($map['mssv'])) {
        return ['error' => 'Không tìm thấy cột MSSV trong dòng tiêu đề (' . implode(' | ', $headers) . ').'];
    }

    $rows = [];
    $errors = [];
    $seen = [];
    for ($i = 1; $i < count($lines); $i++) {
        $line = $i + 1;
        if (trim($lines[$i]) === '') continue;
        $cells = str_getcsv($lines[$i], $delim, '"', '\\');
        $get = static fn (string $f): string => isset($map[$f], $cells[$map[$f]]) ? trim((string) $cells[$map[$f]]) : '';

        $mssv = strtoupper(preg_replace('/\s+/', '', $get('mssv')) ?? '');
        if (!preg_match('/^[0-9]{6,10}$/', $mssv)) {
            $errors[] = ['line' => $line, 'error' => 'MSSV không hợp lệ: "' . $get('mssv') . '".'];
            continue;
        }
        if (isset($seen[$mssv])) {
            $errors[] = ['line' => $line, 'error' => 'MSSV ' . $mssv . ' bị lặp (đã có ở dòng ' . $seen[$mssv] . ').'];
            continue;
        }
        $seen[$mssv] = $line;

        $fullName = $get('fullName');
        if ($fullName === '' && (isset($map['lastName']) || isset($map['firstName']))) {
            $fullName = trim($get('lastName') . ' ' . $get('firstName'));
        }
        $fullName = mb_substr(preg_replace('/\s+/u', ' ', $fullName) ?? $fullName, 0, 120);

        $email = mb_substr($get('email'), 0, 160);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = ['line' => $line, 'error' => 'Email không hợp lệ: "' . $email . '".'];
            continue;
        }

        $rows[] = ['line' => $line, 'mssv' => $mssv, 'fullName' => $fullName, 'email' => $email];
    }

    $columns = [];
    foreach ($map as $f => $i) {
        $columns[$f] = $headers[$i];
    }
    return ['rows' => $rows, 'errors' => $errors, 'columns' => $columns];
}
