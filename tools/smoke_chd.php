<?php
declare(strict_types=1);

/**
 * tools/smoke_chd.php — Kiểm thử bộ phiếu "Thực tập Cơ học đất" (docs/04 mục 34): tạo phiếu bo=chd, mốc chuyên đề
 * ẩn theo lộ trình, ảnh minh chứng ở bảng số liệu, nộp bài, lời dặn chấm Gemini theo môn, email/PDF theo môn.
 * CLI-ONLY, CHỈ chạy trên CSDL THỬ (không có gemini.api_key).
 *
 *   php tools/smoke_chd.php --config=../private/config.test.php
 *
 * Dữ liệu demo tiền tố CHT_ (MSSV 9920000x), dọn trước và sau khi chạy.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Chỉ chạy được từ CLI.');
}

$root = dirname(__DIR__);
$configPath = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--config=')) $configPath = realpath(substr($arg, 9)) ?: null;
}
if ($configPath === null) { fwrite(STDERR, "Bắt buộc --config=<file cấu hình CSDL THỬ>.\n"); exit(2); }
$cfg = require $configPath;
if (!empty($cfg['gemini']['api_key'])) { fwrite(STDERR, "CSDL thử không được có gemini.api_key.\n"); exit(2); }

define('API_INPROCESS', true);
putenv('DIEMDANH_CONFIG=' . $configPath);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
date_default_timezone_set('Asia/Ho_Chi_Minh');
ini_set('error_log', sys_get_temp_dir() . '/diemdanhsv-smoke-chd.log');
foreach (['response', 'config', 'db', 'audit', 'ratelimit', 'mailer', 'auth', 'gradeauth', 'roles', 'attendance', 'queries', 'admin', 'grading', 'khtd', 'khtd_stats', 'khtd_pdf', 'actions'] as $lib) {
    require $root . '/api/lib/' . $lib . '.php';
}

$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $name\n"; return; }
    $fail++;
    echo "  FAIL  $name" . ($detail !== '' ? '  → ' . (is_string($detail) ? $detail : mb_substr(json_encode($detail, JSON_UNESCAPED_UNICODE), 0, 400)) : '') . "\n";
}
function call(string $method, array $params): array
{
    try {
        api_dispatch((string) $params['action'], $method, $params);
        return ['status' => 'error', 'message' => '(không phản hồi)', 'data' => null];
    } catch (ApiResponse $r) {
        return json_decode(json_encode($r->payload, JSON_UNESCAPED_UNICODE), true);
    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => get_class($e) . ': ' . $e->getMessage(), 'data' => null];
    }
}
function ok(array $r): bool { return ($r['status'] ?? '') === 'success'; }

$pdo = db();
$cleanup = static function (PDO $pdo): void {
    foreach (['khtd_submissions' => 'StudentID', 'khtd_extra_attempts' => 'StudentID', 'khtd_photos' => 'StudentID', 'auth_tokens' => 'SubjectID',
              'enrollments' => 'StudentID', 'students' => 'StudentID'] as $t => $c) $pdo->exec("DELETE FROM $t WHERE $c LIKE 'CHT\\_%'");
    $pdo->exec("DELETE FROM khtd_worksheets WHERE ClassID LIKE 'CHT\\_%'");
    $pdo->exec("DELETE FROM auth_tokens WHERE SubjectID LIKE 'CHT\\_%'");
    $pdo->exec("DELETE FROM classes WHERE ClassID LIKE 'CHT\\_%'");
    $pdo->exec("DELETE FROM courses WHERE CourseID LIKE 'CHT\\_%'");
    $pdo->exec("DELETE FROM users WHERE UserID LIKE 'CHT\\_%'");
    $pdo->exec("DELETE FROM rate_limits WHERE ClientKey = '127.0.0.1' OR ClientKey LIKE 'CHT\\_%'");
};
$cleanup($pdo);
$ins = static function (string $table, array $row) use ($pdo): void {
    $cols = implode(',', array_keys($row)); $ph = ':' . implode(',:', array_keys($row));
    $pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($ph)")->execute($row);
};
$ins('users', ['UserID' => 'CHT_U1', 'Username' => 'cht_gv1', 'FullName' => 'GV Thử CHĐ', 'Email' => 'gv@example.invalid',
    'PasswordHash' => password_hash('x', PASSWORD_DEFAULT), 'Role' => 'LECTURER', 'Status' => 'ACTIVE']);
$ins('courses', ['CourseID' => 'CHT_CO1', 'CourseCode' => 'GEO10404', 'CourseName' => 'Cơ học đất (thử)']);
$ins('classes', ['ClassID' => 'CHT_C1', 'CourseID' => 'CHT_CO1', 'LecturerID' => 'CHT_U1', 'ClassCode' => 'GEO10404-THU']);
$ins('classes', ['ClassID' => 'CHT_C2', 'CourseID' => 'CHT_CO1', 'LecturerID' => 'CHT_U1', 'ClassCode' => 'KHTD-THU']);
$ins('students', ['StudentID' => 'CHT_S1', 'MSSV' => '99200001', 'FullName' => 'SV Thử CHĐ', 'Email' => '']);
$ins('enrollments', ['EnrollmentID' => 'CHT_E1', 'StudentID' => 'CHT_S1', 'ClassID' => 'CHT_C1']);
$tok = static function (string $kind, string $subject) use ($pdo): string {
    $t = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO auth_tokens (Token, Kind, SubjectID, ExpiresAt) VALUES (:t, :k, :s, NOW() + INTERVAL 1 HOUR)")->execute(['t' => $t, 'k' => $kind, 's' => $subject]);
    return $t;
};
$gv = $tok('LECTURER', 'CHT_U1');
$sv = $tok('KHTD', 'CHT_S1');

echo "== Bộ phiếu CHĐ (api/lib/chd_phieu.json) ==\n";
$defs = chd_default_worksheets();
check('đọc được 11 phiếu (1–6 thực hành, 11–15 chuyên đề)', array_keys($defs) === [1, 2, 3, 4, 5, 6, 11, 12, 13, 14, 15], array_keys($defs));
$okRub = true; $okPhoto = true;
foreach ($defs as $no => $d) {
    $sum = array_sum(array_column($d['rubric']['criteria'], 'max'));
    if (abs($sum - 10) > 1e-9) $okRub = false;
    $hasPhoto = false; foreach ($d['sections'] as $s) if (($s['type'] ?? '') === 'table' && !empty($s['photos'])) $hasPhoto = true;
    if ($no <= 6 && !$hasPhoto) $okPhoto = false;
}
check('mỗi phiếu rubric tổng 10 điểm', $okRub);
check('mỗi phiếu thực hành 1–6 có bảng có cột ảnh minh chứng', $okPhoto);
check('phiếu có thông tin môn CHĐ', ($defs[1]['mon']['ma'] ?? '') === 'CHĐ' && str_contains($defs[1]['mon']['url'], 'chd-tt'));

echo "== Tạo phiếu cho lớp (bo=chd) ==\n";
$r = call('POST', ['action' => 'khtdSeedWorksheets', 'token' => $gv, 'classId' => 'CHT_C1', 'bo' => 'chd']);
check('GV tạo 11 phiếu CHĐ', ok($r) && count($r['data']['created']) === 11, $r);
$ids = []; foreach (($r['data']['created'] ?? []) as $c) $ids[(int) $c['no']] = $c['id'];
$r = call('POST', ['action' => 'khtdSeedWorksheets', 'token' => $gv, 'classId' => 'CHT_C1', 'bo' => 'chd']);
check('tạo lại không trùng (0 phiếu mới)', ok($r) && count($r['data']['created']) === 0, $r);
$st = $pdo->query("SELECT No, Status, MaxAttempts, ScorePolicy FROM khtd_worksheets WHERE ClassID = 'CHT_C1' ORDER BY No")->fetchAll();
$by = []; foreach ($st as $x) $by[(int) $x['No']] = $x;
check('phiếu 1–6 và mốc 1 (số 11) mở; mốc 12–15 ẩn', $by[1]['Status'] === 'OPEN' && $by[11]['Status'] === 'OPEN' && $by[12]['Status'] === 'HIDDEN' && $by[15]['Status'] === 'HIDDEN', $st);
check('phiếu thực hành cho làm 2 lần, lấy điểm cao nhất', (int) $by[1]['MaxAttempts'] === 2 && $by[1]['ScorePolicy'] === 'BEST', $by[1]);
check('mốc chuyên đề tính lần cuối', $by[11]['ScorePolicy'] === 'LAST' && (int) $by[14]['MaxAttempts'] === 1, [$by[11], $by[14]]);
$sch = json_decode((string) $pdo->query("SELECT SchemaJSON FROM khtd_worksheets WHERE WorksheetID = " . $pdo->quote($ids[12]))->fetchColumn(), true);
check('SchemaJSON không còn khoá status/max_attempts', !isset($sch['status']) && !isset($sch['max_attempts']), array_keys($sch));
$r = call('POST', ['action' => 'khtdSeedWorksheets', 'token' => $gv, 'classId' => 'CHT_C2']);
check('lớp khác không truyền bo → vẫn tạo bộ KHTĐ cũ (7 phiếu)', ok($r) && count($r['data']['created']) === 7, $r);

echo "== Sinh viên ==\n";
$r = call('GET', ['action' => 'khtdListWorksheets', 'token' => $sv]);
$nos = array_map(static fn ($w) => (int) ($w['No'] ?? $w['no'] ?? 0), $r['data']['worksheets'] ?? ($r['data'] ?? []));
check('SV thấy 7 phiếu đang mở (1–6, 11), không thấy mốc ẩn', ok($r) && !in_array(12, $nos, true) && in_array(11, $nos, true) && in_array(6, $nos, true), $nos ?: $r);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $sv, 'worksheetId' => $ids[1]]);
$schema = $r['data']['worksheet']['schema'] ?? ($r['data']['schema'] ?? []);
check('SV mở phiếu 1: không lộ đáp án / dữ liệu chuẩn', ok($r) && !isset($schema['answer_key']) && !isset($schema['reference']) && !isset($schema['answer_hints']), array_keys($schema));
$sec = null; foreach ($schema['sections'] ?? [] as $s) if (($s['id'] ?? '') === 'solieu') $sec = $s;
check('bảng số liệu có cột "Ảnh minh chứng"', $sec && !empty($sec['photos']) && ($sec['photo_label'] ?? '') === 'Ảnh minh chứng', $sec);

$png = 'data:image/png;base64,' . base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$r = call('POST', ['action' => 'khtdUploadPhoto', 'token' => $sv, 'worksheetId' => $ids[1], 'sectionId' => 'solieu', 'row' => 0, 'image' => $png]);
check('SV tải ảnh minh chứng cho hàng 1 bảng số liệu', ok($r) && str_starts_with((string) ($r['data']['photoId'] ?? ''), 'PHO_'), $r);
$pid = (string) ($r['data']['photoId'] ?? '');
$r2 = call('POST', ['action' => 'khtdUploadPhoto', 'token' => $sv, 'worksheetId' => $ids[1], 'sectionId' => 'ketqua', 'row' => 0, 'image' => $png]);
check('bảng không có cột ảnh → từ chối tải ảnh', !ok($r2), $r2);
$ans = ['solieu' => [['56,10', '56,08', '56,09']], 'ketqua' => [['37,26', 'w = (m2 − m3)/(m3 − m1)']], 'q1' => 'Để loại sai số ngẫu nhiên.',
        '_photos' => ['solieu' => ['0' => [$pid]]]];
$r = call('POST', ['action' => 'khtdSaveDraft', 'token' => $sv, 'worksheetId' => $ids[1], 'answers' => json_encode($ans, JSON_UNESCAPED_UNICODE)]);
check('SV lưu nháp kèm ảnh', ok($r), $r);
$r = call('POST', ['action' => 'khtdSubmit', 'token' => $sv, 'worksheetId' => $ids[1], 'answers' => json_encode($ans, JSON_UNESCAPED_UNICODE)]);
check('SV nộp phiếu 1 (chưa cấu hình Gemini → chờ GV chấm)', ok($r), $r);
$row = $pdo->query("SELECT SubmissionID, AnswersJSON, Status FROM khtd_submissions WHERE StudentID = 'CHT_S1' AND WorksheetID = " . $pdo->quote($ids[1]))->fetch();
$saved = json_decode((string) $row['AnswersJSON'], true);
check('bài nộp giữ tham chiếu ảnh', ($saved['_photos']['solieu']['0'][0] ?? ($saved['_photos']['solieu'][0][0] ?? '')) === $pid, $saved['_photos'] ?? null);
check('trạng thái SUBMITTED', $row['Status'] === 'SUBMITTED', $row['Status']);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $sv, 'worksheetId' => $ids[12]]);
check('SV không mở được mốc đang ẩn', !ok($r) || empty($r['data']['worksheet']['open'] ?? $r['data']['open'] ?? false), $r['status'] ?? $r);

echo "== Chấm theo môn ==\n";
$wsRow = khtd_worksheet($ids[1]);
$sys = khtd_grade_system($wsRow['schema']);
check('lời dặn Gemini: vai trò môn Cơ học đất', str_contains($sys, 'môn Cơ học đất (GEO10404') && !str_contains($sys, 'Khoa học Trái đất'), mb_substr($sys, 0, 160));
check('lời dặn Gemini: quy tắc tự tính lại số liệu và đổi đơn vị cv', str_contains($sys, 'TỰ TÍNH LẠI') && str_contains($sys, '3 155,76'));
check('lời dặn Gemini: vẫn giữ khuôn JSON và cấm nhắc AI trong nhận xét', str_contains($sys, '"criteria"') && str_contains($sys, 'TUYỆT ĐỐI không nhắc tới AI'));
$kh = khtd_default_worksheets()[1];
$sysK = khtd_grade_system($kh);
check('phiếu KHTĐ giữ nguyên lời dặn cũ', str_starts_with($sysK, 'Bạn là trợ giảng môn Khoa học Trái đất'));
$m = khtd_mon($wsRow['schema']);
check('email CHĐ: tiêu đề [CHĐ], link chd-tt', $m['ma'] === 'CHĐ' && str_contains($m['url'], 'chd-tt/online.html'), $m);
$mk = khtd_mon($kh);
check('email KHTĐ giữ [KHTĐ], rubric R2, link khtd', $mk['ma'] === 'KHTĐ' && $mk['rubric'] === 'rubric R2' && str_contains($mk['url'], 'khtd/online.html'), $mk);

echo "== Giảng viên ==\n";
$r = call('GET', ['action' => 'khtdLecturerList', 'token' => $gv, 'classId' => 'CHT_C1']);
check('GV xem danh sách 11 phiếu + bài nộp', ok($r) && count($r['data']['worksheets']) === 11 && count($r['data']['submissions'] ?? $r['data']['rows'] ?? []) >= 1, ok($r) ? array_keys($r['data']) : $r);
$r = call('POST', ['action' => 'khtdSetWorksheetStatus', 'token' => $gv, 'worksheetId' => $ids[12], 'status' => 'OPEN']);
check('GV mở mốc 2 khi tới hạn', ok($r), $r);
$r = call('GET', ['action' => 'khtdListWorksheets', 'token' => $sv]);
$nos = array_map(static fn ($w) => (int) ($w['No'] ?? $w['no'] ?? 0), $r['data']['worksheets'] ?? ($r['data'] ?? []));
check('SV thấy mốc 2 sau khi GV mở', in_array(12, $nos, true), $nos);
$r = call('POST', ['action' => 'khtdLecturerGrade', 'token' => $gv, 'submissionId' => $row['SubmissionID'], 'finalScore' => '8.5', 'note' => 'Đạt', 'sendEmail' => '1']);
check('GV duyệt điểm, tạo phiếu điểm PDF (SV thử không có email)', ok($r), $r);
$r = call('GET', ['action' => 'khtdStats', 'token' => $gv, 'classId' => 'CHT_C1']);
check('thống kê lớp chạy với phiếu có mục "info"', ok($r), $r['message'] ?? '');
$r = call('GET', ['action' => 'khtdExportCsv', 'token' => $gv, 'classId' => 'CHT_C1']);
check('xuất CSV điểm phiếu', ok($r), $r['message'] ?? '');

$cleanup($pdo);
echo "\nKết quả: $pass PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
