<?php
declare(strict_types=1);

/**
 * tools/smoke_archive.php — Kiểm thử "lưu trữ cuối học kỳ" (docs/04-API-PHP.md mục 32): gói ZIP, các khoá
 * an toàn trước khi xoá, phạm vi xoá (không đụng lớp khác), thống kê ẩn danh. CLI-ONLY, CHỈ CSDL THỬ.
 *
 *   php tools/smoke_archive.php --config=../private/config.test.php
 *
 * Gieo dữ liệu demo tiền tố ARX_ (MSSV 9940000x), dọn sạch trước và sau. Cấu hình thử nên có
 * 'khtd' => ['upload_dir' => …] và 'app' => ['archive_dir' => …] trỏ ra thư mục tạm.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Chỉ chạy được từ CLI.'); }

$root = dirname(__DIR__);
$configPath = null;
foreach (array_slice($argv, 1) as $arg) if (str_starts_with($arg, '--config=')) $configPath = realpath(substr($arg, 9)) ?: null;
if ($configPath === null) { fwrite(STDERR, "Bắt buộc --config=<file cấu hình CSDL THỬ>.\n"); exit(2); }
$cfg = require $configPath;
if (!empty($cfg['gemini']['api_key'])) { fwrite(STDERR, "CSDL thử không được có gemini.api_key.\n"); exit(2); }

define('API_INPROCESS', true);
putenv('DIEMDANH_CONFIG=' . $configPath);
$_SERVER['REMOTE_ADDR'] = '127.0.0.9';
date_default_timezone_set('Asia/Ho_Chi_Minh');
ini_set('error_log', sys_get_temp_dir() . '/diemdanhsv-smoke-archive.log');
foreach (['response', 'config', 'db', 'audit', 'ratelimit', 'mailer', 'auth', 'gradeauth', 'roles', 'attendance', 'queries', 'admin', 'grading', 'khtd', 'khtd_stats', 'khtd_pdf', 'archive', 'actions'] as $lib) {
    require $root . '/api/lib/' . $lib . '.php';
}

$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $name\n"; return; }
    $fail++; echo "  FAIL  $name" . ($detail !== '' ? '  → ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)) : '') . "\n";
}
function call(string $method, array $params): array
{
    try { api_dispatch((string) $params['action'], $method, $params); return ['status' => 'error', 'message' => '(không phản hồi)']; }
    catch (ApiResponse $r) { return json_decode(json_encode($r->payload, JSON_UNESCAPED_UNICODE), true); }
    catch (Throwable $e) { return ['status' => 'error', 'message' => get_class($e) . ': ' . $e->getMessage()]; }
}
function ok(array $r): bool { return ($r['status'] ?? '') === 'success'; }
function err(array $r, string $c = ''): bool { return ($r['status'] ?? '') === 'error' && ($c === '' || str_contains((string) $r['message'], $c)); }

$pdo = db();
$cleanup = static function (PDO $pdo): void {
    $pdo->exec("DELETE FROM class_archives WHERE ClassID LIKE 'ARX\\_%'");
    $pdo->exec("DELETE x FROM khtd_photos x JOIN khtd_worksheets w ON w.WorksheetID = x.WorksheetID WHERE w.ClassID LIKE 'ARX\\_%'");
    $pdo->exec("DELETE x FROM khtd_submissions x JOIN khtd_worksheets w ON w.WorksheetID = x.WorksheetID WHERE w.ClassID LIKE 'ARX\\_%'");
    $pdo->exec("DELETE FROM khtd_worksheets WHERE ClassID LIKE 'ARX\\_%'");
    $pdo->exec("DELETE a FROM attendance a JOIN sessions s ON s.SessionID = a.SessionID WHERE s.ClassID LIKE 'ARX\\_%'");
    $pdo->exec("DELETE k FROM attendance_keys k JOIN sessions s ON s.SessionID = k.SessionID WHERE s.ClassID LIKE 'ARX\\_%'");
    foreach (['grades', 'grade_columns', 'sessions', 'enrollments', 'complaints'] as $t) $pdo->exec("DELETE FROM $t WHERE ClassID LIKE 'ARX\\_%'");
    $pdo->exec("DELETE FROM auth_tokens WHERE SubjectID LIKE 'ARX\\_%'");
    $pdo->exec("DELETE FROM students WHERE StudentID LIKE 'ARX\\_%'");
    $pdo->exec("DELETE FROM classes WHERE ClassID LIKE 'ARX\\_%'");
    $pdo->exec("DELETE FROM courses WHERE CourseID LIKE 'ARX\\_%'");
    $pdo->exec("DELETE FROM users WHERE UserID LIKE 'ARX\\_%'");
    $pdo->exec("DELETE FROM rate_limits WHERE ClientKey IN ('127.0.0.9', 'arx_gv') OR ClientKey LIKE 'ARX\\_%'");
};
$cleanup($pdo);
$ins = static function (string $t, array $r) use ($pdo): void { $c = implode(',', array_keys($r)); $p = ':' . implode(',:', array_keys($r)); $pdo->prepare("INSERT INTO `$t` ($c) VALUES ($p)")->execute($r); };
$PW = 'Mat-khau-thu-123';
$ins('users', ['UserID' => 'ARX_U1', 'Username' => 'arx_gv', 'FullName' => 'ThS. GV Lưu Trữ', 'PasswordHash' => password_hash($PW, PASSWORD_DEFAULT), 'Role' => 'LECTURER', 'Status' => 'ACTIVE']);
$ins('users', ['UserID' => 'ARX_U2', 'Username' => 'arx_gv2', 'FullName' => 'GV Khác', 'PasswordHash' => password_hash($PW, PASSWORD_DEFAULT), 'Role' => 'LECTURER', 'Status' => 'ACTIVE']);
$ins('courses', ['CourseID' => 'ARX_CO', 'CourseCode' => 'ARX101', 'CourseName' => 'Môn lưu trữ thử']);
$ins('classes', ['ClassID' => 'ARX_C1', 'CourseID' => 'ARX_CO', 'LecturerID' => 'ARX_U1', 'ClassCode' => 'ARX101-2026', 'Semester' => '1', 'AcademicYear' => '2026-2027']);
$ins('classes', ['ClassID' => 'ARX_C2', 'CourseID' => 'ARX_CO', 'LecturerID' => 'ARX_U1', 'ClassCode' => 'ARX101-KHAC']);
foreach ([1, 2, 3] as $n) {
    $ins('students', ['StudentID' => "ARX_S$n", 'MSSV' => "9940000$n", 'FullName' => "Sinh Viên Lưu Trữ $n", 'Email' => "arx$n@example.invalid"]);
    $ins('enrollments', ['EnrollmentID' => "ARX_E$n", 'StudentID' => "ARX_S$n", 'ClassID' => 'ARX_C1']);
}
$ins('enrollments', ['EnrollmentID' => 'ARX_E3B', 'StudentID' => 'ARX_S3', 'ClassID' => 'ARX_C2']);          // SV3 còn học lớp khác
foreach ([1, 2] as $n) $ins('sessions', ['SessionID' => "ARX_SS$n", 'ClassID' => 'ARX_C1', 'SessionNo' => $n, 'Date' => date('Y-m-d', strtotime("-$n day")), 'Content' => "Buổi $n"]);
$ins('sessions', ['SessionID' => 'ARX_SS9', 'ClassID' => 'ARX_C2', 'SessionNo' => 1, 'Date' => date('Y-m-d'), 'Content' => 'Lớp khác']);
foreach ([['ARX_S1', 'ARX_SS1', 'PRESENT'], ['ARX_S2', 'ARX_SS1', 'LATE'], ['ARX_S1', 'ARX_SS2', 'PRESENT'], ['ARX_S3', 'ARX_SS9', 'PRESENT']] as $i => [$s, $ss, $st]) {
    $ins('attendance', ['AttendanceID' => "ARX_A$i", 'StudentID' => $s, 'SessionID' => $ss, 'Status' => $st]);
}
$ins('grade_columns', ['GradeColumnID' => 'ARX_G1', 'ClassID' => 'ARX_C1', 'Name' => 'Cuối kỳ', 'Weight' => 100, 'SortOrder' => 1]);
foreach ([['ARX_S1', 8.5], ['ARX_S2', 6.0], ['ARX_S3', 9.0]] as [$s, $v]) $ins('grades', ['GradeID' => 'ARX_GR_' . $s, 'StudentID' => $s, 'ClassID' => 'ARX_C1', 'GradeColumnID' => 'ARX_G1', 'Score' => $v]);
$ins('complaints', ['ComplaintID' => 'ARX_CP1', 'StudentID' => 'ARX_S2', 'ClassID' => 'ARX_C1', 'Type' => 'GRADE', 'Content' => 'Xin phúc khảo']);
$tok = static function (string $kind, string $sub) use ($pdo): string { $t = bin2hex(random_bytes(32)); $pdo->prepare("INSERT INTO auth_tokens (Token, Kind, SubjectID, ExpiresAt) VALUES (?, ?, ?, NOW() + INTERVAL 1 HOUR)")->execute([$t, $kind, $sub]); return $t; };
$gv = $tok('LECTURER', 'ARX_U1'); $gv2 = $tok('LECTURER', 'ARX_U2'); $s1 = $tok('KHTD', 'ARX_S1');
$r = call('POST', ['action' => 'khtdSeedWorksheets', 'token' => $gv, 'classId' => 'ARX_C1']);
$ws1 = $r['data']['created'][0]['id'];
$img = imagecreatetruecolor(300, 200); ob_start(); imagejpeg($img); $jpg = 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean());
$ph = call('POST', ['action' => 'khtdUploadPhoto', 'token' => $s1, 'worksheetId' => $ws1, 'sectionId' => 'mota', 'row' => 0, 'image' => $jpg])['data']['photoId'] ?? '';
$r = call('POST', ['action' => 'khtdSubmit', 'token' => $s1, 'worksheetId' => $ws1, 'answers' => json_encode(['q1' => 'Thạch anh cứng 7', 'tdg' => [2, 1, 0, 2], '_photos' => ['mota' => ['0' => [$ph]]]])]);
$sub1 = $r['data']['submissionId'] ?? '';
$pdo->prepare("UPDATE khtd_submissions SET Status = 'AI_GRADED', AiScore = 7.5, AiJSON = :j WHERE SubmissionID = :id")
    ->execute(['id' => $sub1, 'j' => json_encode(['criteria' => [['id' => 'hd1', 'name' => 'Hoạt động mở đầu', 'max' => 2, 'score' => 1.5, 'comment' => ''], ['id' => 'bang', 'name' => 'Bảng', 'max' => 3, 'score' => 2.25, 'comment' => '']], 'total' => 7.5, 'feedback' => 'Tốt'])]);
call('POST', ['action' => 'khtdLecturerGrade', 'token' => $gv, 'submissionId' => $sub1, 'finalScore' => 8, 'note' => '', 'sendEmail' => false]);
echo "Đã gieo dữ liệu demo ARX_ (MSSV 9940000x).\n";

echo "== Xem trước ==\n";
check('GV lớp khác không xem được', err(call('GET', ['action' => 'archivePreview', 'token' => $gv2, 'classId' => 'ARX_C1']), 'không có quyền'));
check('SV không gọi được', err(call('GET', ['action' => 'archivePreview', 'token' => $s1, 'classId' => 'ARX_C1'])));
$r = call('GET', ['action' => 'archivePreview', 'token' => $gv, 'classId' => 'ARX_C1']);
$plan = $r['data']['plan'] ?? [];
check('xem trước: đếm đúng dòng sẽ xoá (3 ghi danh, 3 điểm danh, 3 điểm, 1 bài, 1 ảnh), xoá 2 SV giữ 1', ok($r)
    && $plan['counts']['enrollments'] === 3 && $plan['counts']['attendance'] === 3 && $plan['counts']['grades'] === 3 && $plan['counts']['khtd_submissions'] === 1
    && $plan['photoFiles'] === 1 && $plan['studentsToDelete'] === 2 && $plan['studentsKept'] === 1, $plan);
check('chưa tải gói → không được xoá', $plan['canReset'] === false && str_contains(implode(' ', $plan['blockers']), 'Chưa tải gói'), $plan['blockers'] ?? null);
$snap = $r['data']['snapshot'];
$js = json_encode($snap, JSON_UNESCAPED_UNICODE);
check('thống kê ẩn danh không chứa MSSV, họ tên, email', !preg_match('/9940000|Sinh Viên Lưu Trữ|example\.invalid|ARX_S/u', $js), $js);
check('thống kê có chuyên cần, bảng điểm, tiêu chí rubric, tự đánh giá', $snap['attendance']['sessionsCounted'] === 2 && $snap['grades']['columns'][0]['n'] === 3
    && (float) $snap['grades']['columns'][0]['avg'] === 7.83 && (float) $snap['worksheets'][0]['criteria'][0]['avg'] === 1.5
    && $snap['worksheets'][0]['selfAssessment'][0]['counts']['Tự tin'] === 1 && array_map('floatval', $snap['worksheets'][0]['score']['values']) === [8.0], $snap['worksheets'][0] ?? $snap);
check('xoá khi chưa tải gói → bị chặn', err(call('POST', ['action' => 'archiveReset', 'token' => $gv, 'classId' => 'ARX_C1', 'password' => $PW, 'confirmCode' => 'ARX101-2026']), 'Chưa tải gói'));

echo "== Gói ZIP ==\n";
$zipPath = archive_dir() . '/tmp-smoke.zip';
$pkg = archive_build_package('ARX_C1', $zipPath);
$z = new ZipArchive(); $z->open($zipPath);
$names = []; for ($i = 0; $i < $z->numFiles; $i++) $names[] = $z->getNameIndex($i);
$has = static fn (string $re) => (bool) preg_grep($re, $names);
check('gói có PDF phiếu, ảnh, CSV điểm danh/bảng điểm/điểm phiếu, dữ liệu gốc, thống kê, hướng dẫn',
    $has('#/phieu-hoc-tap/ARX101-2026-Diem-Phieu1-.*\.pdf$#') && $has('#/anh-mau/Phieu1/99400001-SinhVienLuuTru1/mota-mau1-PHO_#') && $has('#/diem-danh\.csv$#')
    && $has('#/bang-diem\.csv$#') && $has('#/phieu-hoc-tap/diem-phieu\.csv$#') && $has('#/du-lieu-goc\.json$#') && $has('#/thong-ke-an-danh\.json$#') && $has('#/00-DOC-TRUOC\.txt$#') && !$pkg['pdfErrors'], [$names, $pkg]);
$csv = (string) $z->getFromName(preg_grep('#/diem-danh\.csv$#', $names)[array_key_first(preg_grep('#/diem-danh\.csv$#', $names))]);
check('diem-danh.csv: có BOM, cột Buổi, đúng trạng thái', str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, '"Buổi 1"') && str_contains($csv, '"Trễ"'), $csv);
$z->close();
$rec = archive_record_package('ARX_C1', $pkg['name'], $zipPath, 'ARX_U1');
@unlink($zipPath);
$r = call('GET', ['action' => 'archivePreview', 'token' => $gv, 'classId' => 'ARX_C1']);
check('đã tạo gói → được xoá (SHA-256 ghi nhận)', ok($r) && $r['data']['plan']['canReset'] === true && $r['data']['plan']['package']['sha256'] === $rec['sha256'], $r['data']['plan'] ?? $r);

echo "== Khoá an toàn khi xoá ==\n";
check('sai mật khẩu → từ chối', err(call('POST', ['action' => 'archiveReset', 'token' => $gv, 'classId' => 'ARX_C1', 'password' => 'sai', 'confirmCode' => 'ARX101-2026']), 'Mật khẩu'));
check('gõ sai mã lớp → từ chối', err(call('POST', ['action' => 'archiveReset', 'token' => $gv, 'classId' => 'ARX_C1', 'password' => $PW, 'confirmCode' => 'ARX101']), 'không khớp'));
check('GV lớp khác → từ chối', err(call('POST', ['action' => 'archiveReset', 'token' => $gv2, 'classId' => 'ARX_C1', 'password' => $PW, 'confirmCode' => 'ARX101-2026']), 'không có quyền'));
$pdo->exec("UPDATE class_archives SET PackagedAt = NOW() - INTERVAL 1 MINUTE WHERE ClassID = 'ARX_C1'");
$ins('attendance', ['AttendanceID' => 'ARX_A_NEW', 'StudentID' => 'ARX_S2', 'SessionID' => 'ARX_SS2', 'Status' => 'PRESENT']);
check('có dữ liệu mới sau khi tạo gói → phải tải lại', err(call('POST', ['action' => 'archiveReset', 'token' => $gv, 'classId' => 'ARX_C1', 'password' => $PW, 'confirmCode' => 'ARX101-2026']), 'tải lại gói'));
$pdo->exec("UPDATE class_archives SET PackagedAt = NOW() - INTERVAL 20 DAY WHERE ClassID = 'ARX_C1'");
$pdo->exec("UPDATE attendance SET CreatedAt = NOW() - INTERVAL 21 DAY WHERE AttendanceID = 'ARX_A_NEW'");
$pdo->exec("UPDATE khtd_submissions SET UpdatedAt = NOW() - INTERVAL 21 DAY, CreatedAt = NOW() - INTERVAL 21 DAY WHERE WorksheetID = '$ws1'");
$pdo->exec("UPDATE khtd_photos SET CreatedAt = NOW() - INTERVAL 21 DAY WHERE WorksheetID = '$ws1'");
foreach (['attendance a JOIN sessions s ON s.SessionID = a.SessionID SET a.CreatedAt' => "s.ClassID = 'ARX_C1'", 'grades SET UpdatedAt' => "ClassID = 'ARX_C1'", 'enrollments SET CreatedAt' => "ClassID = 'ARX_C1'", 'sessions SET CreatedAt' => "ClassID = 'ARX_C1'"] as $t => $w) {
    $pdo->exec("UPDATE $t = NOW() - INTERVAL 21 DAY WHERE $w");
}
check('gói quá 14 ngày → phải tải lại', err(call('POST', ['action' => 'archiveReset', 'token' => $gv, 'classId' => 'ARX_C1', 'password' => $PW, 'confirmCode' => 'ARX101-2026']), 'quá 14 ngày'));
$pdo->exec("UPDATE class_archives SET PackagedAt = NOW() WHERE ClassID = 'ARX_C1'");
$pdo->exec("INSERT INTO attendance_keys (KeyID, SessionID, Code, StartTime, LateAfter, EndTime, Status) VALUES ('ARX_K1', 'ARX_SS2', 'ZZZZ', NOW(), NOW(), NOW() + INTERVAL 1 HOUR, 'OPEN')");
check('đang có mã điểm danh mở → chặn', err(call('POST', ['action' => 'archiveReset', 'token' => $gv, 'classId' => 'ARX_C1', 'password' => $PW, 'confirmCode' => 'ARX101-2026']), 'mã điểm danh mở'));
$pdo->exec("UPDATE attendance_keys SET Status = 'CLOSED' WHERE KeyID = 'ARX_K1'");

echo "== Xoá ==\n";
$photoDir = khtd_upload_dir() . '/' . $ws1;
check('trước khi xoá: thư mục ảnh tồn tại', is_dir($photoDir));
$r = call('POST', ['action' => 'archiveReset', 'token' => $gv, 'classId' => 'ARX_C1', 'password' => $PW, 'confirmCode' => 'ARX101-2026']);
check('xoá thành công', ok($r) && $r['data']['deleted']['students'] === 2 && $r['data']['deleted']['enrollments'] === 3 && $r['data']['deleted']['khtd_photos'] === 1, $r);
$cnt = static fn (string $sql) => (int) $pdo->query($sql)->fetchColumn();
check('lớp ARX_C1 không còn buổi/điểm danh/điểm/phiếu/bài/khiếu nại/ghi danh',
    $cnt("SELECT COUNT(*) FROM sessions WHERE ClassID='ARX_C1'") + $cnt("SELECT COUNT(*) FROM grades WHERE ClassID='ARX_C1'") + $cnt("SELECT COUNT(*) FROM grade_columns WHERE ClassID='ARX_C1'")
    + $cnt("SELECT COUNT(*) FROM khtd_worksheets WHERE ClassID='ARX_C1'") + $cnt("SELECT COUNT(*) FROM khtd_submissions WHERE WorksheetID='$ws1'") + $cnt("SELECT COUNT(*) FROM complaints WHERE ClassID='ARX_C1'")
    + $cnt("SELECT COUNT(*) FROM enrollments WHERE ClassID='ARX_C1'") + $cnt("SELECT COUNT(*) FROM attendance WHERE SessionID IN ('ARX_SS1','ARX_SS2')") === 0);
check('hồ sơ SV1, SV2 đã xoá; SV3 (còn học lớp khác) giữ nguyên cùng điểm danh lớp khác',
    $cnt("SELECT COUNT(*) FROM students WHERE StudentID IN ('ARX_S1','ARX_S2')") === 0 && $cnt("SELECT COUNT(*) FROM students WHERE StudentID='ARX_S3'") === 1
    && $cnt("SELECT COUNT(*) FROM attendance WHERE SessionID='ARX_SS9'") === 1 && $cnt("SELECT COUNT(*) FROM sessions WHERE ClassID='ARX_C2'") === 1);
check('token SV đã xoá', $cnt("SELECT COUNT(*) FROM auth_tokens WHERE SubjectID='ARX_S1'") === 0);
check('lớp chuyển INACTIVE (ẩn khỏi danh sách lớp)', $pdo->query("SELECT Status FROM classes WHERE ClassID='ARX_C1'")->fetchColumn() === 'INACTIVE'
    && !in_array('ARX_C1', array_column(call('GET', ['action' => 'listClasses', 'token' => $gv])['data'] ?? [], 'ClassID'), true));
check('thư mục ảnh của lớp đã xoá', !is_dir($photoDir));
check('bản cứu dữ liệu chữ có trong thư mục lưu trữ', is_file(archive_dir() . '/' . $r['data']['safetyFile']));
$r2 = call('GET', ['action' => 'archiveList', 'token' => $gv]);
$a = ok($r2) ? array_values(array_filter($r2['data']['archives'], fn ($x) => $x['classId'] === 'ARX_C1'))[0] ?? null : null;
check('danh sách lưu trữ có lớp, thống kê giữ lại, SV = 3', $a && $a['students'] === 3 && (float) $a['stats']['worksheets'][0]['score']['avg'] === 8.0 && $a['academicYear'] === '2026-2027', $a ? ['students' => $a['students'], 'avg' => $a['stats']['worksheets'][0]['score']['avg'] ?? null] : null);
check('GV khác không thấy lưu trữ của lớp', !in_array('ARX_C1', array_column(call('GET', ['action' => 'archiveList', 'token' => $gv2])['data']['archives'] ?? [], 'classId'), true));
check('xoá lần hai → báo đã lưu trữ', err(call('POST', ['action' => 'archiveReset', 'token' => $gv, 'classId' => 'ARX_C1', 'password' => $PW, 'confirmCode' => 'ARX101-2026']), 'đã được lưu trữ'));
check('xem trước sau khi xoá → trạng thái RESET kèm thống kê', ($p = call('GET', ['action' => 'archivePreview', 'token' => $gv, 'classId' => 'ARX_C1'])) && ok($p) && $p['data']['status'] === 'RESET' && isset($p['data']['snapshot']['grades']));

@unlink(archive_dir() . '/' . $r['data']['safetyFile']);
$cleanup($pdo);
echo "\nKết quả: $pass PASS, $fail FAIL\n";
exit($fail === 0 ? 0 : 1);
