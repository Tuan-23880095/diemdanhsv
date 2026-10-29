<?php
declare(strict_types=1);

/**
 * api/lib/auth.php — giảng viên đăng nhập bằng CHÍNH tài khoản của
 * diemdanhsv.com (bảng users), token lưu ở auth_tokens (Kind = 'LECTURER')
 * nên token của site chính cũng dùng được ở đây và ngược lại.
 */

const KT_TOKEN_TTL = 8 * 3600;
const KT_DUMMY_HASH = '$2y$10$neKGKFysV6YZtYjp/BsUjOwSabs12ACaEt.62BWwhTdJS0UUwEtEa';

function action_login(array $p): void
{
    $username = str_param($p, 'username', 60);
    $password = (string) ($p['password'] ?? '');
    $ip = client_ip();
    rate_guard('kt_login_ip', $ip, 20, 900);

    $u = q_one("SELECT * FROM users WHERE Username = :u AND Status = 'ACTIVE' LIMIT 1", ['u' => $username]);
    $ok = false;
    if ($u) {
        $hash = (string) $u['PasswordHash'];
        if (str_starts_with($hash, '$2') || str_starts_with($hash, '$argon2')) {
            $ok = password_verify($password, $hash);
        } elseif (hash_equals($hash, hash('sha256', ((string) ($u['Salt'] ?? '')) . '|' . $password))) {
            // Hash cũ từ Apps Script — đổi sang bcrypt ngay (giống site chính)
            $ok = true;
            q('UPDATE users SET PasswordHash = :h, Salt = NULL WHERE UserID = :id', ['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => $u['UserID']]);
        }
    } else {
        password_verify($password, KT_DUMMY_HASH);
    }
    if (!$ok || !in_array($u['Role'], ['ADMIN', 'LECTURER'], true)) {
        rate_hit('kt_login_ip', $ip, 900);
        fail('Sai tên đăng nhập hoặc mật khẩu.');
    }
    $token = bin2hex(random_bytes(32));
    q('INSERT INTO auth_tokens (Token, Kind, SubjectID, ExpiresAt) VALUES (:t, :k, :s, :e)', [
        't' => $token, 'k' => 'LECTURER', 's' => $u['UserID'], 'e' => dt(ts(now()) + KT_TOKEN_TTL),
    ]);
    api_ok(['token' => $token, 'fullName' => $u['FullName'], 'role' => $u['Role']]);
}

function action_logout(array $p): void
{
    q("DELETE FROM auth_tokens WHERE Token = :t AND Kind = 'LECTURER'", ['t' => (string) ($p['token'] ?? '')]);
    api_ok(['ok' => true]);
}

/** @return array{userId:string, role:string, name:string} */
function require_teacher(array $p): array
{
    $token = trim((string) ($p['token'] ?? ''));
    if ($token === '') {
        fail('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.');
    }
    $row = q_one(
        "SELECT t.SubjectID, u.Role, u.FullName FROM auth_tokens t JOIN users u ON u.UserID = t.SubjectID " .
        "WHERE t.Token = :t AND t.Kind = 'LECTURER' AND t.ExpiresAt > :now AND u.Status = 'ACTIVE' LIMIT 1",
        ['t' => $token, 'now' => now()]
    );
    if (!$row || !in_array($row['Role'], ['ADMIN', 'LECTURER'], true)) {
        fail('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.');
    }
    return ['userId' => (string) $row['SubjectID'], 'role' => (string) $row['Role'], 'name' => (string) $row['FullName']];
}

function lecturer_in(string $csv, string $userId): bool
{
    foreach (preg_split('/[,;.\s]+/', trim($csv)) ?: [] as $x) {
        if (trim($x) === $userId) {
            return true;
        }
    }
    return false;
}

/** Các lớp giảng viên phụ trách (ADMIN: tất cả lớp ACTIVE). */
function my_classes(array $me): array
{
    $rows = q_all(
        "SELECT c.ClassID, c.ClassCode, c.CourseID, c.LecturerID, c.Semester, c.AcademicYear, co.CourseCode, co.CourseName " .
        "FROM classes c JOIN courses co ON co.CourseID = c.CourseID WHERE c.Status = 'ACTIVE' ORDER BY co.CourseName, c.ClassCode"
    );
    if ($me['role'] === 'ADMIN') {
        return $rows;
    }
    return array_values(array_filter($rows, fn($r) => lecturer_in((string) $r['LecturerID'], $me['userId'])));
}

function assert_class(array $me, string $classId): array
{
    foreach (my_classes($me) as $c) {
        if ($c['ClassID'] === $classId) {
            return $c;
        }
    }
    fail('Không tìm thấy lớp, hoặc bạn không phụ trách lớp này.');
}

function assert_course(array $me, string $courseId): void
{
    foreach (my_classes($me) as $c) {
        if ($c['CourseID'] === $courseId) {
            return;
        }
    }
    fail('Bạn không phụ trách lớp nào của học phần này.');
}

function load_exam_for_teacher(array $me, string $examId): array
{
    $e = q_one('SELECT * FROM kt_exams WHERE ExamID = :id', ['id' => $examId]);
    if (!$e) {
        fail('Không tìm thấy đề kiểm tra.');
    }
    $cls = assert_class($me, (string) $e['ClassID']);
    $e['_class'] = $cls;
    return $e;
}

function action_me(array $p): void
{
    $me = require_teacher($p);
    $classes = my_classes($me);
    $courses = [];
    foreach ($classes as $c) {
        $courses[$c['CourseID']] = ['CourseID' => $c['CourseID'], 'CourseCode' => $c['CourseCode'], 'CourseName' => $c['CourseName']];
    }
    api_ok([
        'name' => $me['name'], 'role' => $me['role'],
        'classes' => array_map(fn($c) => array_diff_key($c, ['LecturerID' => 1]), $classes),
        'courses' => array_values($courses),
    ]);
}
