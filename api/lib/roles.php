<?php
declare(strict_types=1);

/**
 * api/lib/roles.php — requireRole/assertClassAccess/identifyStudent
 * (gas/03-Auth.gs). GĐ3 chỉ port login/logout/requestGradeCode/verifyGradeCode
 * (không cần phân quyền theo lớp); GĐ4 (PLAN) cần các hàm này cho
 * openAttendance/closeAttendance/checkin/liveRoster — xem docs/04-API-PHP.md
 * mục 5, mục 13.
 */

/**
 * requireRole — xác thực token giảng viên/admin (Kind='LECTURER' trong
 * auth_tokens), thay CacheService.get('tok_'+token) cũ. Ném lỗi rõ ràng nếu
 * token hết hạn/không tồn tại, hoặc vai trò không nằm trong $roles — đúng
 * AuthService.requireRole() (gas/03-Auth.gs dòng 79-88).
 *
 * GĐ9 review lần 2 (M10) — ĐỔI so với bản GAS: kiểm users.Status = 'ACTIVE' ở
 * đây. Bản gốc (CacheService) để token còn hạn 6 giờ dùng được kể cả sau khi
 * khoá tài khoản; từ GĐ7/GĐ8 token đó còn nhập được danh sách lớp, nhập điểm,
 * sửa điểm danh — quá rộng cho một tài khoản đã bị khoá. Khoá tài khoản giờ có
 * hiệu lực NGAY với mọi action cần vai trò.
 *
 * @param string[] $roles
 * @return array{userId:string, role:string, name:string}
 */
function require_role(string $token, array $roles): array
{
    $token = trim($token);
    $expiredMsg = 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.';
    if ($token === '') {
        throw new RuntimeException($expiredMsg);
    }

    $stmt = db()->prepare(
        "SELECT t.SubjectID, u.Role, u.FullName FROM auth_tokens t " .
        "JOIN users u ON u.UserID = t.SubjectID " .
        "WHERE t.Token = :token AND t.Kind = 'LECTURER' AND t.ExpiresAt > NOW() " .
        "AND u.Status = 'ACTIVE' LIMIT 1"
    );
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException($expiredMsg);
    }

    if (!in_array((string) $row['Role'], $roles, true)) {
        throw new RuntimeException('Tài khoản không có quyền thực hiện thao tác này.');
    }

    return [
        'userId' => (string) $row['SubjectID'],
        'role'   => (string) $row['Role'],
        'name'   => (string) $row['FullName'],
    ];
}

/**
 * assertClassAccess (thiết kế D.9 — nhiều giảng viên) — gọi NGAY SAU
 * require_role(), TRƯỚC khi đọc/ghi bất cứ gì của lớp đó. ADMIN thao tác
 * được mọi lớp; LECTURER chỉ thao tác được lớp có UserID mình trong
 * classes.LecturerID (danh sách cách nhau bởi dấu phẩy — gas/03-Auth.gs
 * dòng 96-134). Ném lỗi rõ ràng thay vì âm thầm trả danh sách rỗng.
 *
 * @param array{userId:string, role:string, name:string} $me
 */
function assert_class_access(array $me, string $classId): void
{
    if ($me['role'] === 'ADMIN') {
        return;
    }

    $stmt = db()->prepare('SELECT LecturerID FROM classes WHERE ClassID = :id LIMIT 1');
    $stmt->execute(['id' => $classId]);
    $cls = $stmt->fetch();

    if (!$cls || !class_has_lecturer((string) $cls['LecturerID'], $me['userId'])) {
        throw new RuntimeException(CLASS_ACCESS_DENIED_MESSAGE);
    }
}

/**
 * L8 (review lần 2, docs/04 mục 21): MỘT thông báo cho cả "không tồn tại" và
 * "không có quyền" khi người gọi là LECTURER. Trước đây "Không tìm thấy buổi
 * học X" ≠ "Bạn không có quyền…" → giảng viên dò được SessionID/ClassID nào
 * tồn tại (của lớp người khác) chỉ bằng cách đổi tham số. ADMIN có quyền trên
 * mọi lớp nên không có gì để lộ — vẫn nhận thông báo cụ thể để dễ sửa dữ liệu.
 * Thông báo chứa cả "Không tìm thấy buổi" và "không có quyền" để frontend/
 * smoke test cũ dò chuỗi vẫn khớp.
 */
const CLASS_ACCESS_DENIED_MESSAGE = 'Không tìm thấy buổi học/lớp này, hoặc bạn không có quyền thao tác.';

/** Thông báo "không tìm thấy <what> <id>" cho ADMIN; thông báo chung cho LECTURER. */
function not_found_message(array $me, string $what, string $id): string
{
    if (($me['role'] ?? '') === 'ADMIN') {
        return 'Không tìm thấy ' . $what . ' ' . $id . '.';
    }
    return CLASS_ACCESS_DENIED_MESSAGE;
}

/**
 * classHasLecturer_ (gas/03-Auth.gs dòng 124-134) — classes.LecturerID lưu
 * NHIỀU UserID cách nhau bởi dấu phẩy/chấm/chấm phẩy/khoảng trắng (bản Sheets
 * cũ từng bị Google Sheets tự đổi dấu phẩy thập phân thành dấu chấm — tách
 * theo cả hai để đọc đúng, giữ nguyên yêu cầu docblock gốc dù MySQL VARCHAR
 * không mắc lỗi tự-đổi-định-dạng đó).
 */
function class_has_lecturer(string $lecturerIdRaw, string $userId): bool
{
    $parts = preg_split('/[,;.\s]+/', trim($lecturerIdRaw)) ?: [];
    $needle = trim($userId);
    foreach ($parts as $p) {
        if (trim($p) === $needle) {
            return true;
        }
    }
    return false;
}

/**
 * require_student — xác thực token xem điểm (Kind='GRADE' trong auth_tokens,
 * cấp bởi `verifyGradeCode` — api/lib/gradeauth.php), thay
 * CacheService.get('gtok_'+token) cũ (GradeAuth.requireStudent,
 * gas/08-GradeService.gs dòng 160-164). Dùng cho `myGrades` (GĐ5) — KHÔNG
 * nhận mssv từ tham số, danh tính lấy từ token đã xác minh qua email nên
 * không truyền MSSV bạn khác vào xem trộm được điểm.
 *
 * @return array{studentId:string, mssv:string, fullName:string}
 */
function require_student(string $token): array
{
    $token = trim($token);
    // Nguyên văn thông báo cũ — GradeAuth.requireStudent chỉ có một lỗi duy
    // nhất, không phân biệt "thiếu token" và "hết hạn" như require_role().
    $expiredMsg = 'Phiên xem điểm đã hết hạn. Vui lòng xin mã mới.';
    if ($token === '') {
        throw new RuntimeException($expiredMsg);
    }

    $stmt = db()->prepare(
        "SELECT s.StudentID, s.MSSV, s.FullName FROM auth_tokens t " .
        "JOIN students s ON s.StudentID = t.SubjectID " .
        "WHERE t.Token = :token AND t.Kind = 'GRADE' AND t.ExpiresAt > NOW() LIMIT 1"
    );
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException($expiredMsg);
    }

    return [
        'studentId' => (string) $row['StudentID'],
        'mssv'      => (string) $row['MSSV'],
        'fullName'  => (string) $row['FullName'],
    ];
}

/**
 * identifyStudent (gas/03-Auth.gs dòng 22-46) — nhận diện SV theo MSSV +
 * kiểm tra ghi danh vào đúng lớp (D.8 lớp 2). Dùng cho `checkin` (GĐ4) và sẽ
 * dùng lại cho `studentHistory` (GĐ5) — đặt chung ở đây từ GĐ4 để tránh viết
 * lại logic xác thực MSSV ở GĐ5.
 *
 * @return array{ok:bool, reason?:string, student?:array}
 */
function identify_student(string $mssv, string $classId): array
{
    $clean = strtoupper(trim($mssv));

    if (!preg_match('/^[0-9]{6,10}$/', $clean)) {
        return ['ok' => false, 'reason' => 'MSSV không hợp lệ (phải là 6–10 chữ số).'];
    }

    $stmt = db()->prepare("SELECT * FROM students WHERE MSSV = :m AND Status = 'ACTIVE' LIMIT 1");
    $stmt->execute(['m' => $clean]);
    $student = $stmt->fetch();
    if (!$student) {
        return ['ok' => false, 'reason' => 'Không tìm thấy MSSV ' . $clean . ' trong hệ thống.'];
    }

    // [D.8 lớp 2] MSSV phải có trong danh sách lớp đang mở điểm danh — không
    // có bước này thì MSSV bất kỳ của trường đều ghi được một dòng rác.
    $stmt = db()->prepare(
        "SELECT EnrollmentID FROM enrollments WHERE StudentID = :sid AND ClassID = :cid AND Status = 'ACTIVE' LIMIT 1"
    );
    $stmt->execute(['sid' => $student['StudentID'], 'cid' => $classId]);
    if (!$stmt->fetch()) {
        return ['ok' => false, 'reason' => 'MSSV ' . $clean . ' không có trong danh sách lớp này.'];
    }

    return ['ok' => true, 'student' => $student];
}
