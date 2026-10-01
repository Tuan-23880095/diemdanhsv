<?php
declare(strict_types=1);

/**
 * api/lib/queries.php — studentHistory/myGrades/listClasses/listSessions
 * (gas/05-Api.gs doGet, gas/04-AttendanceService.gs AttendanceService.studentHistory,
 * gas/08-GradeService.gs GradeService.myGrades). Đối chiếu đầy đủ tại
 * docs/04-API-PHP.md mục 5, mục 14 (GĐ5). 4 action ĐỌC cuối cùng trong 13
 * action cũ — sau action này, `api_dispatch()` không còn nhánh "chưa triển
 * khai" nào (xem api/lib/actions.php).
 *
 * `studentHistory` dùng lại `identify_student()` (api/lib/roles.php, đã có
 * từ GĐ4) — không cần token, xác thực Mức 1 bằng MSSV + kiểm ghi danh đúng
 * lớp, giống `checkin`. `myGrades`, `listClasses`, `listSessions` cần token
 * (GRADE cho myGrades — `require_student()` mới, LECTURER/ADMIN cho hai
 * action còn lại — `require_role()` đã có từ GĐ4).
 */

/**
 * studentHistory — GET. Không cần token (Mức 1: MSSV + ghi danh đúng lớp —
 * gas/04-AttendanceService.gs dòng 285-308). Trả đủ MỌI buổi ACTIVE của
 * lớp, kể cả buổi sinh viên chưa check-in (status mặc định 'ABSENT',
 * checkInTime rỗng) — KHÔNG chỉ những buổi đã có bản ghi attendance.
 */
function action_student_history(array $params): void
{
    $mssv = (string) ($params['mssv'] ?? '');
    $classId = trim((string) ($params['classId'] ?? ''));

    $auth = identify_student($mssv, $classId);
    if (!$auth['ok']) {
        api_fail((string) $auth['reason']);
        return;
    }
    $student = $auth['student'];

    $stmt = db()->prepare(
        "SELECT SessionID, SessionNo, `Date`, Content FROM sessions " .
        "WHERE ClassID = :cid AND Status = 'ACTIVE' ORDER BY SessionNo ASC"
    );
    $stmt->execute(['cid' => $classId]);
    $sessions = $stmt->fetchAll();

    $attStmt = db()->prepare('SELECT SessionID, Status, CheckInTime FROM attendance WHERE StudentID = :sid');
    $attStmt->execute(['sid' => $student['StudentID']]);
    $byId = [];
    foreach ($attStmt->fetchAll() as $r) {
        $byId[(string) $r['SessionID']] = $r;
    }

    $rows = [];
    foreach ($sessions as $s) {
        $r = $byId[(string) $s['SessionID']] ?? null;
        $rows[] = [
            'sessionNo'   => (int) $s['SessionNo'],
            'date'        => $s['Date'],
            'content'     => $s['Content'],
            // Không có bản ghi attendance = chưa từng check-in = ABSENT,
            // đúng ATTENDANCE_STATUS.ABSENT mặc định của bản gas cũ.
            'status'      => $r ? (string) $r['Status'] : 'ABSENT',
            'checkInTime' => ($r && $r['CheckInTime'] !== null)
                ? db_stamp_to_iso((string) $r['CheckInTime'])
                : '',
        ];
    }

    api_ok([
        'mssv'     => $student['MSSV'],
        'fullName' => $student['FullName'],
        'rows'     => $rows,
    ]);
}

/**
 * myGrades — GET. Cần token xem điểm (Kind='GRADE', cấp bởi verifyGradeCode
 * — api/lib/gradeauth.php). KHÔNG nhận mssv từ tham số (gas/08-GradeService.gs
 * dòng 172-175) — danh tính lấy từ token đã xác minh qua email, nên không
 * truyền MSSV bạn khác vào xem trộm được.
 */
function action_my_grades(array $params): void
{
    $me = require_student((string) ($params['token'] ?? ''));

    $stmt = db()->prepare(
        'SELECT en.ClassID, cl.ClassCode, co.CourseName, co.CourseCode ' .
        'FROM enrollments en ' .
        'JOIN classes cl ON cl.ClassID = en.ClassID ' .
        'JOIN courses co ON co.CourseID = cl.CourseID ' .
        "WHERE en.StudentID = :sid AND en.Status = 'ACTIVE'"
    );
    $stmt->execute(['sid' => $me['studentId']]);
    $enrolled = $stmt->fetchAll();

    $classes = [];
    foreach ($enrolled as $en) {
        $classId = (string) $en['ClassID'];

        $colStmt = db()->prepare(
            'SELECT GradeColumnID, Name, Weight FROM grade_columns ' .
            "WHERE ClassID = :cid AND Status = 'ACTIVE' ORDER BY SortOrder ASC"
        );
        $colStmt->execute(['cid' => $classId]);
        $cols = $colStmt->fetchAll();

        // Điểm của riêng sinh viên này trong lớp này — UNIQUE KEY uq_grade
        // (StudentID, GradeColumnID) đủ để tra đúng, không cần thêm ClassID
        // vào điều kiện (docs/04-API-PHP.md mục 4, dòng `10_GRADES`→`grades`).
        $scoreStmt = db()->prepare('SELECT GradeColumnID, Score FROM grades WHERE StudentID = :sid AND ClassID = :cid');
        $scoreStmt->execute(['sid' => $me['studentId'], 'cid' => $classId]);
        $scoresByCol = [];
        foreach ($scoreStmt->fetchAll() as $g) {
            $scoresByCol[(string) $g['GradeColumnID']] = $g['Score'];
        }

        $columns = [];
        foreach ($cols as $col) {
            $raw = $scoresByCol[(string) $col['GradeColumnID']] ?? null;
            $has = $raw !== null && trim((string) $raw) !== '';
            $columns[] = [
                'name'   => $col['Name'],
                'weight' => (float) $col['Weight'],
                'score'  => $has ? (float) $raw : null,
            ];
        }

        // GĐ8: điểm tổng = Σ điểm×trọng số/100 trên phần đã chấm, quy về tối đa
        // 10 (tổng trọng số có thể là 110% vì có "Điểm cộng" — thầy chốt
        // 02/10/2026). 'average' cũ (trung bình trên phần đã chấm) giữ lại cho
        // tương thích, nhưng giao diện dùng 'total'.
        $totals = grading_total($columns);
        $weighted = 0.0;
        $weightDone = 0.0;
        foreach ($columns as $c) {
            if ($c['score'] !== null) {
                $weighted += $c['score'] * $c['weight'];
                $weightDone += $c['weight'];
            }
        }

        // Chuyên cần + cấm thi tính TRỰC TIẾP từ điểm danh (luôn mới nhất, không
        // phụ thuộc giảng viên đã bấm "Ghi điểm chuyên cần" hay chưa).
        $stats = attendance_stats($classId);
        $mine = $stats['rows'][$me['studentId']] ?? null;

        $classes[] = [
            'classCode'   => $en['ClassCode'],
            'courseName'  => (string) $en['CourseName'],
            'courseCode'  => (string) $en['CourseCode'],
            'columns'     => $columns,
            'average'     => $weightDone > 0 ? round($weighted / $weightDone, 2) : null,
            'total'       => $totals['total'],
            'weightDone'  => $totals['weightDone'],
            'weightTotal' => $totals['weightTotal'],
            'attendance'  => $mine === null ? null : [
                'sessionsCounted'    => $stats['sessionsCounted'],
                'present'            => $mine['present'],
                'late'               => $mine['late'],
                'absent'             => $mine['absent'],
                'excused'            => $mine['excused'],
                'score'              => $mine['score'],
                'equivalentAbsences' => $mine['equivalentAbsences'],
                'banned'             => $mine['banned'],
            ],
        ];
    }

    api_ok(['mssv' => $me['mssv'], 'classes' => $classes]);
}

/**
 * listClasses — GET. [D.9] LECTURER chỉ thấy lớp mình đứng tên LecturerID
 * (danh sách nhiều UserID cách nhau dấu phẩy — class_has_lecturer());
 * ADMIN thấy hết (gas/05-Api.gs dòng 53-59).
 */
function action_list_classes(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);

    $stmt = db()->prepare("SELECT * FROM classes WHERE Status = 'ACTIVE'");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if ($me['role'] !== 'ADMIN') {
        $rows = array_values(array_filter($rows, function (array $c) use ($me): bool {
            return class_has_lecturer((string) $c['LecturerID'], $me['userId']);
        }));
    }

    api_ok($rows);
}

/**
 * listSessions — GET. `assertClassAccess` TRƯỚC khi trả dữ liệu (gas/05-Api.gs
 * dòng 61-67) — LECTURER không đứng tên lớp bị từ chối rõ ràng thay vì âm
 * thầm trả mảng rỗng.
 */
function action_list_sessions(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $classId = trim((string) ($params['classId'] ?? ''));
    assert_class_access($me, $classId);

    $stmt = db()->prepare(
        "SELECT * FROM sessions WHERE ClassID = :cid AND Status = 'ACTIVE' ORDER BY SessionNo ASC"
    );
    $stmt->execute(['cid' => $classId]);
    api_ok($stmt->fetchAll());
}
