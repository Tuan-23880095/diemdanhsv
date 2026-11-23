<?php
declare(strict_types=1);

/**
 * tools/cleanup_demo.php — Xoá dữ liệu demo DEMO101 khỏi CSDL (CLI ONLY).
 *
 * Nguồn gốc dữ liệu demo: gas/06-Seed.gs (seedDemoData / runSmokeTest):
 *   - Môn học: CourseCode = 'DEMO101' ('Môn học thử nghiệm')
 *   - Lớp học: ClassCode = 'DEMO101_01' / 'DEMO101-01' hoặc thuộc môn DEMO101
 *   - Sinh viên mẫu: MSSV '22000001'..'22000004' ('Sinh viên thử 1..4', trong đó
 *     '22000004' cố ý không ghi danh để kiểm chứng lớp bù D.8-2)
 *   - Toàn bộ dữ liệu liên quan: điểm danh, mã điểm danh, buổi học, điểm, cột điểm,
 *     khiếu nại, ghi danh, phiếu KHTĐ, phòng live, lưu trữ, kiểm tra kt_* (nếu có).
 *
 * Quy ước an toàn (tools/README.md):
 *   - Chỉ chạy từ CLI (403 nếu gọi qua web; .htaccess cũng chặn /tools).
 *   - Mặc định KHÔNG xoá dữ liệu smoke test (ID tiền tố SMOKE_ / ARX_) trừ khi
 *     truyền --include-smoke.
 *   - Chỉ xoá hồ sơ sinh viên nếu sau khi gỡ khỏi lớp demo KHÔNG còn liên quan đến
 *     bất kỳ lớp/dữ liệu nào khác.
 *   - Không bao giờ xoá audit_log (bảng chỉ INSERT); ghi 1 dòng TOOLS_CLEANUP_DEMO.
 *
 * Cách dùng (SSH, từ public_html — nên chạy php tools/backup.php trước):
 *   php tools/cleanup_demo.php --dry-run
 *   php tools/cleanup_demo.php --yes
 *   php tools/cleanup_demo.php --config=../private/config.test.php --dry-run
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Chỉ chạy được từ CLI.');
}

ini_set('display_errors', '1');
error_reporting(E_ALL);

const DEMO_COURSE_CODE = 'DEMO101';
const DEMO_STUDENT_MSSVS = ['22000001', '22000002', '22000003', '22000004'];

$opts = ['dry_run' => false, 'yes' => false, 'include_smoke' => false, 'config' => null];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') { $opts['dry_run'] = true; continue; }
    if ($arg === '--yes') { $opts['yes'] = true; continue; }
    if ($arg === '--include-smoke') { $opts['include_smoke'] = true; continue; }
    if (str_starts_with($arg, '--config=')) { $opts['config'] = substr($arg, 9); continue; }
    fwrite(STDERR, "Tham số không rõ: $arg\n");
    exit(2);
}

if ($opts['config'] !== null) {
    if (!is_file($opts['config'])) {
        fwrite(STDERR, "Không thấy file cấu hình: {$opts['config']}\n");
        exit(2);
    }
    putenv('DIEMDANH_CONFIG=' . realpath($opts['config']));
}

require __DIR__ . '/../api/lib/config.php';
require __DIR__ . '/../api/lib/db.php';
require __DIR__ . '/../api/lib/audit.php';

$pdo = db();

/** Kiểm tra bảng tồn tại trong CSDL hiện tại (tránh lỗi nếu chưa cài module phụ). */
function demo_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $st = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
    );
    $st->execute(['t' => $table]);
    return $cache[$table] = (bool) $st->fetchColumn();
}

/** Tạo danh sách placeholder ?,?,? và lọc ID rỗng/trùng. */
function demo_unique_ids(array $ids): array
{
    return array_values(array_unique(array_filter(array_map('strval', $ids), static fn ($v) => $v !== '')));
}

function demo_placeholders(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

function demo_is_smoke_id(string $id): bool
{
    return str_starts_with($id, 'SMOKE_') || str_starts_with($id, 'ARX_');
}

function demo_count_in(PDO $pdo, string $sql, array $params): int
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int) $st->fetchColumn();
}

// 1. Tìm môn học & lớp học demo
$courseRows = $pdo->prepare('SELECT CourseID, CourseCode, CourseName FROM courses WHERE CourseCode = :code');
$courseRows->execute(['code' => DEMO_COURSE_CODE]);
$courses = [];
foreach ($courseRows->fetchAll() as $c) {
    if (!$opts['include_smoke'] && demo_is_smoke_id((string) $c['CourseID'])) {
        continue;
    }
    $courses[] = $c;
}
$courseIds = demo_unique_ids(array_column($courses, 'CourseID'));

$classesById = [];
if ($courseIds) {
    $ph = demo_placeholders($courseIds);
    $st = $pdo->prepare("SELECT ClassID, CourseID, ClassCode, Status FROM classes WHERE CourseID IN ($ph)");
    $st->execute($courseIds);
    foreach ($st->fetchAll() as $r) {
        if (!$opts['include_smoke'] && demo_is_smoke_id((string) $r['ClassID'])) {
            continue;
        }
        $classesById[$r['ClassID']] = $r;
    }
}

$stCode = $pdo->query(
    "SELECT ClassID, CourseID, ClassCode, Status FROM classes " .
    "WHERE ClassCode = 'DEMO101' OR ClassCode LIKE 'DEMO101\\_%' OR ClassCode LIKE 'DEMO101-%'"
);
foreach ($stCode->fetchAll() as $r) {
    if (!$opts['include_smoke'] && demo_is_smoke_id((string) $r['ClassID'])) {
        continue;
    }
    $classesById[$r['ClassID']] = $r;
}
$classes = array_values($classesById);
$classIds = demo_unique_ids(array_column($classes, 'ClassID'));

// 2. Gom ID phụ thuộc (buổi học, phiếu KHTĐ, phòng live, đề kiểm tra, CLO, câu hỏi)
$sessionIds = [];
if ($classIds) {
    $ph = demo_placeholders($classIds);
    $st = $pdo->prepare("SELECT SessionID FROM sessions WHERE ClassID IN ($ph)");
    $st->execute($classIds);
    $sessionIds = demo_unique_ids($st->fetchAll(PDO::FETCH_COLUMN));
}

$worksheetIds = [];
if ($classIds && demo_table_exists($pdo, 'khtd_worksheets')) {
    $ph = demo_placeholders($classIds);
    $st = $pdo->prepare("SELECT WorksheetID FROM khtd_worksheets WHERE ClassID IN ($ph)");
    $st->execute($classIds);
    $worksheetIds = demo_unique_ids($st->fetchAll(PDO::FETCH_COLUMN));
}

$liveRoomIds = [];
if ($classIds && demo_table_exists($pdo, 'live_rooms')) {
    $ph = demo_placeholders($classIds);
    $st = $pdo->prepare("SELECT RoomID FROM live_rooms WHERE ClassID IN ($ph)");
    $st->execute($classIds);
    $liveRoomIds = demo_unique_ids($st->fetchAll(PDO::FETCH_COLUMN));
}

$ktExamIds = [];
if ($classIds && demo_table_exists($pdo, 'kt_exams')) {
    $ph = demo_placeholders($classIds);
    $st = $pdo->prepare("SELECT ExamID FROM kt_exams WHERE ClassID IN ($ph)");
    $st->execute($classIds);
    $ktExamIds = demo_unique_ids($st->fetchAll(PDO::FETCH_COLUMN));
}

$ktCloIds = [];
if ($courseIds && demo_table_exists($pdo, 'kt_clos')) {
    $ph = demo_placeholders($courseIds);
    $st = $pdo->prepare("SELECT CloID FROM kt_clos WHERE CourseID IN ($ph)");
    $st->execute($courseIds);
    $ktCloIds = demo_unique_ids($st->fetchAll(PDO::FETCH_COLUMN));
}

$ktQuestionIds = [];
if ($courseIds && demo_table_exists($pdo, 'kt_questions')) {
    $ph = demo_placeholders($courseIds);
    $st = $pdo->prepare("SELECT QuestionID FROM kt_questions WHERE CourseID IN ($ph)");
    $st->execute($courseIds);
    $ktQuestionIds = demo_unique_ids($st->fetchAll(PDO::FETCH_COLUMN));
}

// 3. Xác định danh sách sinh viên ứng viên xoá (SV ghi danh lớp demo + SV seed 22000001..22000004)
$candidateStudents = [];
if ($classIds) {
    $ph = demo_placeholders($classIds);
    $st = $pdo->prepare(
        "SELECT DISTINCT s.StudentID, s.MSSV, s.FullName FROM students s " .
        "JOIN enrollments e ON e.StudentID = s.StudentID WHERE e.ClassID IN ($ph)"
    );
    $st->execute($classIds);
    foreach ($st->fetchAll() as $s) {
        if (!$opts['include_smoke'] && demo_is_smoke_id((string) $s['StudentID'])) {
            continue;
        }
        $candidateStudents[$s['StudentID']] = $s;
    }
}

$phMssv = demo_placeholders(DEMO_STUDENT_MSSVS);
$stSeedStu = $pdo->prepare("SELECT StudentID, MSSV, FullName FROM students WHERE MSSV IN ($phMssv)");
$stSeedStu->execute(DEMO_STUDENT_MSSVS);
foreach ($stSeedStu->fetchAll() as $s) {
    if (!$opts['include_smoke'] && demo_is_smoke_id((string) $s['StudentID'])) {
        continue;
    }
    // Chỉ tự nhận SV chưa ghi danh nếu khớp mẫu seed ('Sinh viên thử %')
    if (isset($candidateStudents[$s['StudentID']]) || str_starts_with((string) $s['FullName'], 'Sinh viên thử')) {
        $candidateStudents[$s['StudentID']] = $s;
    }
}

// Kiểm tra SV nào thật sự an toàn để xoá (không còn dữ liệu ở bất kỳ lớp nào khác)
$studentsToDelete = [];
$studentsKept = [];
foreach ($candidateStudents as $sid => $s) {
    $refs = 0;
    if ($classIds) {
        $ph = demo_placeholders($classIds);
        $refs += demo_count_in($pdo, "SELECT COUNT(*) FROM enrollments WHERE StudentID = ? AND ClassID NOT IN ($ph)", array_merge([$sid], $classIds));
        $refs += demo_count_in($pdo, "SELECT COUNT(*) FROM grades WHERE StudentID = ? AND ClassID NOT IN ($ph)", array_merge([$sid], $classIds));
        $refs += demo_count_in($pdo, "SELECT COUNT(*) FROM complaints WHERE StudentID = ? AND (ClassID IS NULL OR ClassID NOT IN ($ph))", array_merge([$sid], $classIds));
    } else {
        $refs += demo_count_in($pdo, 'SELECT COUNT(*) FROM enrollments WHERE StudentID = ?', [$sid]);
        $refs += demo_count_in($pdo, 'SELECT COUNT(*) FROM grades WHERE StudentID = ?', [$sid]);
        $refs += demo_count_in($pdo, 'SELECT COUNT(*) FROM complaints WHERE StudentID = ?', [$sid]);
    }
    if ($sessionIds) {
        $ph = demo_placeholders($sessionIds);
        $refs += demo_count_in($pdo, "SELECT COUNT(*) FROM attendance WHERE StudentID = ? AND SessionID NOT IN ($ph)", array_merge([$sid], $sessionIds));
    } else {
        $refs += demo_count_in($pdo, 'SELECT COUNT(*) FROM attendance WHERE StudentID = ?', [$sid]);
    }
    if (demo_table_exists($pdo, 'khtd_submissions')) {
        if ($worksheetIds) {
            $ph = demo_placeholders($worksheetIds);
            $refs += demo_count_in($pdo, "SELECT COUNT(*) FROM khtd_submissions WHERE StudentID = ? AND WorksheetID NOT IN ($ph)", array_merge([$sid], $worksheetIds));
        } else {
            $refs += demo_count_in($pdo, 'SELECT COUNT(*) FROM khtd_submissions WHERE StudentID = ?', [$sid]);
        }
    }
    if (demo_table_exists($pdo, 'kt_attempts')) {
        if ($ktExamIds) {
            $ph = demo_placeholders($ktExamIds);
            $refs += demo_count_in($pdo, "SELECT COUNT(*) FROM kt_attempts WHERE StudentID = ? AND ExamID NOT IN ($ph)", array_merge([$sid], $ktExamIds));
        } else {
            $refs += demo_count_in($pdo, 'SELECT COUNT(*) FROM kt_attempts WHERE StudentID = ?', [$sid]);
        }
    }

    if ($refs > 0) {
        $studentsKept[] = $s + ['refs' => $refs];
    } else {
        $studentsToDelete[$sid] = $s;
    }
}
$deleteStudentIds = array_keys($studentsToDelete);

// 4. Đếm dự kiến số dòng sẽ xoá theo từng bảng
$plan = [];
if ($sessionIds) {
    $ph = demo_placeholders($sessionIds);
    $plan['attendance']      = demo_count_in($pdo, "SELECT COUNT(*) FROM attendance WHERE SessionID IN ($ph)", $sessionIds);
    $plan['attendance_keys'] = demo_count_in($pdo, "SELECT COUNT(*) FROM attendance_keys WHERE SessionID IN ($ph)", $sessionIds);
}
if ($classIds) {
    $ph = demo_placeholders($classIds);
    $plan['sessions']      = count($sessionIds);
    $plan['grades']        = demo_count_in($pdo, "SELECT COUNT(*) FROM grades WHERE ClassID IN ($ph)", $classIds);
    $plan['grade_columns'] = demo_count_in($pdo, "SELECT COUNT(*) FROM grade_columns WHERE ClassID IN ($ph)", $classIds);
    $plan['complaints']    = demo_count_in($pdo, "SELECT COUNT(*) FROM complaints WHERE ClassID IN ($ph)", $classIds);
    $plan['enrollments']   = demo_count_in($pdo, "SELECT COUNT(*) FROM enrollments WHERE ClassID IN ($ph)", $classIds);
    if (demo_table_exists($pdo, 'class_archives')) {
        $plan['class_archives'] = demo_count_in($pdo, "SELECT COUNT(*) FROM class_archives WHERE ClassID IN ($ph)", $classIds);
    }
}
if ($worksheetIds) {
    $ph = demo_placeholders($worksheetIds);
    if (demo_table_exists($pdo, 'khtd_photos'))         $plan['khtd_photos']         = demo_count_in($pdo, "SELECT COUNT(*) FROM khtd_photos WHERE WorksheetID IN ($ph)", $worksheetIds);
    if (demo_table_exists($pdo, 'khtd_extra_attempts')) $plan['khtd_extra_attempts'] = demo_count_in($pdo, "SELECT COUNT(*) FROM khtd_extra_attempts WHERE WorksheetID IN ($ph)", $worksheetIds);
    if (demo_table_exists($pdo, 'khtd_submissions'))    $plan['khtd_submissions']    = demo_count_in($pdo, "SELECT COUNT(*) FROM khtd_submissions WHERE WorksheetID IN ($ph)", $worksheetIds);
    $plan['khtd_worksheets'] = count($worksheetIds);
}
if ($liveRoomIds) {
    $ph = demo_placeholders($liveRoomIds);
    if (demo_table_exists($pdo, 'live_responses')) $plan['live_responses'] = demo_count_in($pdo, "SELECT COUNT(*) FROM live_responses WHERE RoomID IN ($ph)", $liveRoomIds);
    $plan['live_rooms'] = count($liveRoomIds);
}
if ($ktExamIds) {
    $ph = demo_placeholders($ktExamIds);
    if (demo_table_exists($pdo, 'kt_events'))     $plan['kt_events']     = demo_count_in($pdo, "SELECT COUNT(*) FROM kt_events WHERE ExamID IN ($ph)", $ktExamIds);
    if (demo_table_exists($pdo, 'kt_attempts'))   $plan['kt_attempts']   = demo_count_in($pdo, "SELECT COUNT(*) FROM kt_attempts WHERE ExamID IN ($ph)", $ktExamIds);
    $plan['kt_exams'] = count($ktExamIds);
}
if (($ktExamIds || $ktQuestionIds) && demo_table_exists($pdo, 'kt_exam_items')) {
    $where = [];
    $params = [];
    if ($ktExamIds) {
        $where[] = 'ExamID IN (' . demo_placeholders($ktExamIds) . ')';
        $params = array_merge($params, $ktExamIds);
    }
    if ($ktQuestionIds) {
        $where[] = 'QuestionID IN (' . demo_placeholders($ktQuestionIds) . ')';
        $params = array_merge($params, $ktQuestionIds);
    }
    $plan['kt_exam_items'] = demo_count_in($pdo, 'SELECT COUNT(*) FROM kt_exam_items WHERE ' . implode(' OR ', $where), $params);
}
if ($ktQuestionIds) {
    $plan['kt_questions'] = count($ktQuestionIds);
}
if ($ktCloIds) {
    $plan['kt_clos'] = count($ktCloIds);
}
if ($classIds) {
    $plan['classes'] = count($classIds);
}
if ($courseIds) {
    $ph = demo_placeholders($courseIds);
    // Chỉ xoá môn nếu không còn lớp nào khác tham chiếu
    $rem = demo_count_in(
        $pdo,
        $classIds
            ? "SELECT COUNT(*) FROM classes WHERE CourseID IN ($ph) AND ClassID NOT IN (" . demo_placeholders($classIds) . ")"
            : "SELECT COUNT(*) FROM classes WHERE CourseID IN ($ph)",
        $classIds ? array_merge($courseIds, $classIds) : $courseIds
    );
    if ($rem === 0) {
        $plan['courses'] = count($courseIds);
    } else {
        fwrite(STDERR, "Cảnh báo: còn $rem lớp khác gắn với môn DEMO101 — sẽ giữ lại bảng courses.\n");
    }
}
if ($deleteStudentIds) {
    $ph = demo_placeholders($deleteStudentIds);
    $plan['grade_codes'] = demo_count_in($pdo, "SELECT COUNT(*) FROM grade_codes WHERE StudentID IN ($ph)", $deleteStudentIds);
    $plan['auth_tokens'] = demo_count_in($pdo, "SELECT COUNT(*) FROM auth_tokens WHERE SubjectID IN ($ph) AND Kind IN ('GRADE','KHTD')", $deleteStudentIds);
    $plan['students']    = count($deleteStudentIds);
}

$totalRows = array_sum($plan);
if ($totalRows === 0 && !$studentsKept) {
    echo "Không có dữ liệu demo DEMO101 nào cần xoá.\n";
    exit(0);
}

echo "=== Đối tượng demo DEMO101 tìm thấy ===\n";
foreach ($courses as $c) {
    echo "  Môn:  CourseID={$c['CourseID']} CourseCode={$c['CourseCode']} Name={$c['CourseName']}\n";
}
foreach ($classes as $cl) {
    echo "  Lớp:  ClassID={$cl['ClassID']} ClassCode={$cl['ClassCode']} Status={$cl['Status']}\n";
}
foreach ($studentsToDelete as $s) {
    echo "  SV:   StudentID={$s['StudentID']} MSSV={$s['MSSV']} (sẽ xoá)\n";
}
foreach ($studentsKept as $s) {
    echo "  SV:   StudentID={$s['StudentID']} MSSV={$s['MSSV']} (GIỮ LẠI — còn {$s['refs']} liên kết ở lớp khác)\n";
}

echo "\n=== Dự kiến số dòng sẽ xoá ===\n";
foreach ($plan as $tbl => $cnt) {
    if ($cnt > 0) {
        printf("  %-22s %d\n", $tbl, $cnt);
    }
}
echo "  Tổng cộng:             $totalRows dòng\n";

if ($opts['dry_run'] || !$opts['yes']) {
    echo $opts['dry_run']
        ? "\nDry-run xong — chưa xoá gì.\n"
        : "\nThiếu --yes — chưa xoá gì. Thêm --yes để thực thi (khuyến nghị chạy php tools/backup.php trước).\n";
    exit(0);
}

// 5. Thực thi xoá trong một transaction duy nhất theo đúng thứ tự khoá ngoại
$deleted = db_transaction(static function (PDO $pdo) use (
    $sessionIds,
    $classIds,
    $worksheetIds,
    $liveRoomIds,
    $ktExamIds,
    $ktQuestionIds,
    $ktCloIds,
    $courseIds,
    $deleteStudentIds,
    $plan
): array {
    $d = [];
    $exec = static function (string $label, string $sql, array $params) use ($pdo, &$d): void {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $d[$label] = ($d[$label] ?? 0) + $st->rowCount();
    };

    if ($sessionIds) {
        $ph = demo_placeholders($sessionIds);
        $exec('attendance', "DELETE FROM attendance WHERE SessionID IN ($ph)", $sessionIds);
        $exec('attendance_keys', "DELETE FROM attendance_keys WHERE SessionID IN ($ph)", $sessionIds);
    }
    if ($classIds) {
        $ph = demo_placeholders($classIds);
        $exec('sessions', "DELETE FROM sessions WHERE ClassID IN ($ph)", $classIds);
        $exec('grades', "DELETE FROM grades WHERE ClassID IN ($ph)", $classIds);
        $exec('grade_columns', "DELETE FROM grade_columns WHERE ClassID IN ($ph)", $classIds);
        $exec('complaints', "DELETE FROM complaints WHERE ClassID IN ($ph)", $classIds);
        if (demo_table_exists($pdo, 'class_archives')) {
            $exec('class_archives', "DELETE FROM class_archives WHERE ClassID IN ($ph)", $classIds);
        }
    }
    if ($worksheetIds) {
        $ph = demo_placeholders($worksheetIds);
        if (demo_table_exists($pdo, 'khtd_photos')) {
            $exec('khtd_photos', "DELETE FROM khtd_photos WHERE WorksheetID IN ($ph)", $worksheetIds);
        }
        if (demo_table_exists($pdo, 'khtd_extra_attempts')) {
            $exec('khtd_extra_attempts', "DELETE FROM khtd_extra_attempts WHERE WorksheetID IN ($ph)", $worksheetIds);
        }
        if (demo_table_exists($pdo, 'khtd_submissions')) {
            $exec('khtd_submissions', "DELETE FROM khtd_submissions WHERE WorksheetID IN ($ph)", $worksheetIds);
        }
        $exec('khtd_worksheets', "DELETE FROM khtd_worksheets WHERE WorksheetID IN ($ph)", $worksheetIds);
    }
    if ($liveRoomIds) {
        $ph = demo_placeholders($liveRoomIds);
        if (demo_table_exists($pdo, 'live_responses')) {
            $exec('live_responses', "DELETE FROM live_responses WHERE RoomID IN ($ph)", $liveRoomIds);
        }
        $exec('live_rooms', "DELETE FROM live_rooms WHERE RoomID IN ($ph)", $liveRoomIds);
    }
    if ($ktExamIds) {
        $ph = demo_placeholders($ktExamIds);
        if (demo_table_exists($pdo, 'kt_events')) {
            $exec('kt_events', "DELETE FROM kt_events WHERE ExamID IN ($ph)", $ktExamIds);
        }
        if (demo_table_exists($pdo, 'kt_attempts')) {
            $exec('kt_attempts', "DELETE FROM kt_attempts WHERE ExamID IN ($ph)", $ktExamIds);
        }
        if (demo_table_exists($pdo, 'kt_exam_items')) {
            $exec('kt_exam_items', "DELETE FROM kt_exam_items WHERE ExamID IN ($ph)", $ktExamIds);
        }
        $exec('kt_exams', "DELETE FROM kt_exams WHERE ExamID IN ($ph)", $ktExamIds);
    }
    if ($ktQuestionIds) {
        $ph = demo_placeholders($ktQuestionIds);
        if (demo_table_exists($pdo, 'kt_exam_items')) {
            $exec('kt_exam_items', "DELETE FROM kt_exam_items WHERE QuestionID IN ($ph)", $ktQuestionIds);
        }
        $exec('kt_questions', "DELETE FROM kt_questions WHERE QuestionID IN ($ph)", $ktQuestionIds);
    }
    if ($ktCloIds) {
        $ph = demo_placeholders($ktCloIds);
        $exec('kt_clos', "DELETE FROM kt_clos WHERE CloID IN ($ph)", $ktCloIds);
    }
    if ($classIds) {
        $ph = demo_placeholders($classIds);
        $exec('enrollments', "DELETE FROM enrollments WHERE ClassID IN ($ph)", $classIds);
        $exec('classes', "DELETE FROM classes WHERE ClassID IN ($ph)", $classIds);
    }
    if (!empty($plan['courses']) && $courseIds) {
        $ph = demo_placeholders($courseIds);
        $exec('courses', "DELETE FROM courses WHERE CourseID IN ($ph)", $courseIds);
    }
    if ($deleteStudentIds) {
        $ph = demo_placeholders($deleteStudentIds);
        $exec('grade_codes', "DELETE FROM grade_codes WHERE StudentID IN ($ph)", $deleteStudentIds);
        $exec('auth_tokens', "DELETE FROM auth_tokens WHERE SubjectID IN ($ph) AND Kind IN ('GRADE','KHTD')", $deleteStudentIds);
        $exec('students', "DELETE FROM students WHERE StudentID IN ($ph)", $deleteStudentIds);
    }

    return $d;
});

log_audit(
    'cli:cleanup_demo.php',
    'ADMIN',
    'TOOLS_CLEANUP_DEMO',
    'COURSE',
    $courseIds ? implode(',', $courseIds) : ($classIds ? implode(',', $classIds) : null),
    ['courses' => $courseIds, 'classes' => $classIds, 'deleted' => $deleted]
);

echo "\n=== ĐÃ XOÁ THÀNH CÔNG ===\n";
foreach ($deleted as $tbl => $cnt) {
    if ($cnt > 0) {
        printf("  %-22s %d\n", $tbl, $cnt);
    }
}
echo "Tổng đã xoá: " . array_sum($deleted) . " dòng (đã ghi audit_log TOOLS_CLEANUP_DEMO).\n";
