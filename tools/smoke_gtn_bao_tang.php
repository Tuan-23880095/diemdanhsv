<?php
declare(strict_types=1);

/**
 * tools/smoke_gtn_bao_tang.php — Kiểm thử mã tham quan Bảo tàng 24 giờ + Phiếu 7 (docs/04 mục 42).
 * CLI-ONLY, CHỈ chạy trên CSDL THỬ:
 *
 *   php tools/smoke_gtn_bao_tang.php --config=../private/config.test.php
 *
 * Gieo dữ liệu demo tiền tố GTQ_ (MSSV 9920000x), dọn sạch trước và sau khi chạy. Không gọi Gemini.
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
if ($configPath === null) {
    fwrite(STDERR, "Bắt buộc --config=<file cấu hình CSDL THỬ>. Không chạy trên CSDL thật.\n");
    exit(2);
}
$cfg = require $configPath;
if (!empty($cfg['gemini']['api_key'])) {
    fwrite(STDERR, "CSDL thử không được có gemini.api_key (kiểm thử không gọi AI thật).\n");
    exit(2);
}

define('API_INPROCESS', true);
putenv('DIEMDANH_CONFIG=' . $configPath);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
date_default_timezone_set('Asia/Ho_Chi_Minh');
ini_set('error_log', sys_get_temp_dir() . '/diemdanhsv-smoke-gtn.log');
foreach (['response', 'config', 'db', 'audit', 'ratelimit', 'mailer', 'auth', 'gradeauth', 'roles', 'attendance', 'queries', 'admin', 'grading', 'khtd', 'khtd_stats', 'khtd_pdf', 'actions'] as $lib) {
    require $root . '/api/lib/' . $lib . '.php';
}

$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $name\n"; return; }
    $fail++;
    echo "  FAIL  $name" . ($detail !== '' ? '  → ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)) : '') . "\n";
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
function err(array $r, string $contains = ''): bool { return ($r['status'] ?? '') === 'error' && ($contains === '' || str_contains((string) $r['message'], $contains)); }

$pdo = db();
$cleanup = static function (PDO $pdo): void {
    $pdo->exec("DELETE FROM khtd_submissions WHERE StudentID LIKE 'GTQ\\_%'");
    $pdo->exec("DELETE FROM khtd_photos WHERE StudentID LIKE 'GTQ\\_%'");
    $pdo->exec("DELETE FROM khtd_worksheets WHERE ClassID LIKE 'GTQ\\_%'");
    $pdo->exec("DELETE FROM attendance WHERE StudentID LIKE 'GTQ\\_%'");
    $pdo->exec("DELETE k FROM attendance_keys k JOIN sessions s ON s.SessionID = k.SessionID WHERE s.ClassID LIKE 'GTQ\\_%'");
    $pdo->exec("DELETE FROM sessions WHERE ClassID LIKE 'GTQ\\_%'");
    $pdo->exec("DELETE FROM auth_tokens WHERE SubjectID LIKE 'GTQ\\_%'");
    $pdo->exec("DELETE FROM enrollments WHERE StudentID LIKE 'GTQ\\_%'");
    $pdo->exec("DELETE FROM students WHERE StudentID LIKE 'GTQ\\_%'");
    $pdo->exec("DELETE FROM classes WHERE ClassID LIKE 'GTQ\\_%'");
    $pdo->exec("DELETE FROM courses WHERE CourseID LIKE 'GTQ\\_%'");
    $pdo->exec("DELETE FROM users WHERE UserID LIKE 'GTQ\\_%'");
    $pdo->exec("DELETE FROM rate_limits WHERE ClientKey = '127.0.0.1'");
};
$cleanup($pdo);

$ins = static function (string $table, array $row) use ($pdo): void {
    $cols = implode(',', array_keys($row)); $ph = ':' . implode(',:', array_keys($row));
    $pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($ph)")->execute($row);
};
$ins('users', ['UserID' => 'GTQ_U1', 'Username' => 'gtq_gv1', 'FullName' => 'GV Thử', 'Email' => 'gv@example.invalid',
    'PasswordHash' => password_hash('x', PASSWORD_DEFAULT), 'Role' => 'LECTURER', 'Status' => 'ACTIVE']);
$ins('users', ['UserID' => 'GTQ_U2', 'Username' => 'gtq_gv2', 'FullName' => 'GV Lớp khác', 'Email' => 'gv2@example.invalid',
    'PasswordHash' => password_hash('x', PASSWORD_DEFAULT), 'Role' => 'LECTURER', 'Status' => 'ACTIVE']);
$ins('courses', ['CourseID' => 'GTQ_CO1', 'CourseCode' => 'GTQ101', 'CourseName' => 'Giới thiệu ngành Địa chất (thử)']);
$ins('classes', ['ClassID' => 'GTQ_C1', 'CourseID' => 'GTQ_CO1', 'LecturerID' => 'GTQ_U1', 'ClassCode' => 'GTQ101-01']);
foreach ([1, 2] as $n) {
    $ins('students', ['StudentID' => "GTQ_S$n", 'MSSV' => "9920000$n", 'FullName' => "SV Thử $n", 'Email' => '']);
}
$ins('enrollments', ['EnrollmentID' => 'GTQ_E1', 'StudentID' => 'GTQ_S1', 'ClassID' => 'GTQ_C1']);   // SV2 không thuộc lớp
$tok = static function (string $kind, string $subject) use ($pdo): string {
    $t = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO auth_tokens (Token, Kind, SubjectID, ExpiresAt) VALUES (:t, :k, :s, NOW() + INTERVAL 1 HOUR)")
        ->execute(['t' => $t, 'k' => $kind, 's' => $subject]);
    return $t;
};
$gv = $tok('LECTURER', 'GTQ_U1');
$gv2 = $tok('LECTURER', 'GTQ_U2');

echo "== Buổi tham quan + mã chờ ==\n";
$r = call('POST', ['action' => 'adminSaveSession', 'token' => $gv, 'classId' => 'GTQ_C1', 'sessionNo' => 7, 'date' => date('Y-m-d'), 'startTime' => '07:30', 'content' => 'Tham quan Bảo tàng Địa chất']);
check('GV tạo buổi tham quan', ok($r), $r);
$sid = $r['data']['sessionId'] ?? '';
$r = call('GET', ['action' => 'gtnTqStatus', 'token' => $gv, 'classId' => 'GTQ_C1']);
check('trạng thái: buổi chưa có mã', ok($r) && count($r['data']['sessions']) === 1 && $r['data']['sessions'][0]['tq'] === null, $r);
check('GV lớp khác không xem được', err(call('GET', ['action' => 'gtnTqStatus', 'token' => $gv2, 'classId' => 'GTQ_C1'])));
check('GV lớp khác không tạo mã được', err(call('POST', ['action' => 'gtnTqCreate', 'token' => $gv2, 'sessionId' => $sid])));
$r = call('POST', ['action' => 'gtnTqCreate', 'token' => $gv, 'sessionId' => $sid]);
check('tạo mã chờ', ok($r) && $r['data']['tq']['state'] === 'pending' && strlen($r['data']['tq']['code']) === 4 && $r['data']['reused'] === false, $r);
$code = $r['data']['tq']['code'] ?? '';
$r = call('POST', ['action' => 'gtnTqCreate', 'token' => $gv, 'sessionId' => $sid]);
check('tạo lại → giữ nguyên mã đã in', ok($r) && $r['data']['tq']['code'] === $code && $r['data']['reused'] === true, $r);
check('mã chờ: SV chưa đăng nhập được', err(call('POST', ['action' => 'khtdLogin', 'mssv' => '99200001', 'code' => $code])));
check('mã chờ: chưa điểm danh được', err(call('POST', ['action' => 'checkin', 'mssv' => '99200001', 'code' => $code])));
$r = call('POST', ['action' => 'gtnTqCreate', 'token' => $gv, 'sessionId' => $sid, 'renew' => 1]);
check('đổi mã → mã mới, vẫn chờ', ok($r) && $r['data']['tq']['code'] !== '' && $r['data']['tq']['state'] === 'pending' && $r['data']['reused'] === false, $r);
$old = $code; $code = $r['data']['tq']['code'];
$n = (int) $pdo->query("SELECT COUNT(*) FROM attendance_keys WHERE SessionID = " . $pdo->quote($sid) . " AND KeyID LIKE 'KTQ\\_%' AND StartTime = EndTime")->fetchColumn();
check('chỉ còn 1 mã chờ của buổi (mã cũ không còn chờ, không xoá dòng)', $n === 1 && (int) $pdo->query("SELECT COUNT(*) FROM attendance_keys WHERE SessionID = " . $pdo->quote($sid))->fetchColumn() === 2);

echo "== Kích hoạt 24 giờ ==\n";
$r = call('POST', ['action' => 'openAttendance', 'token' => $gv, 'sessionId' => $sid, 'windowMinutes' => 15]);
check('(GV lỡ mở mã điểm danh thường của cùng buổi)', ok($r), $r);
$normal = $r['data']['code'] ?? '';
$r = call('POST', ['action' => 'gtnTqActivate', 'token' => $gv, 'sessionId' => $sid, 'lateMinutes' => 60]);
check('kích hoạt → active', ok($r) && $r['data']['tq']['state'] === 'active' && $r['data']['tq']['code'] === $code, $r);
$k = $pdo->query("SELECT * FROM attendance_keys WHERE Code = " . $pdo->quote($code) . " AND SessionID = " . $pdo->quote($sid) . " AND Status = 'OPEN'")->fetch();
$hrs = $k ? (strtotime($k['EndTime']) - strtotime($k['StartTime'])) / 3600 : 0;
check('hiệu lực đúng 24 giờ, muộn sau 60 phút', $k && abs($hrs - 24) < 0.01 && (strtotime($k['LateAfter']) - strtotime($k['StartTime'])) === 3600, $k);
check('mã điểm danh thường của buổi bị đóng', (string) $pdo->query("SELECT Status FROM attendance_keys WHERE Code = " . $pdo->quote($normal) . " AND SessionID = " . $pdo->quote($sid) . " AND KeyID NOT LIKE 'KTQ\\_%'")->fetchColumn() === 'CLOSED');
$r = call('POST', ['action' => 'gtnTqActivate', 'token' => $gv, 'sessionId' => $sid]);
check('kích hoạt lại → báo đã chạy, không đổi hạn', ok($r) && $r['data']['already'] === true, $r);
check('mã cũ (đã đổi) không đăng nhập được', err(call('POST', ['action' => 'khtdLogin', 'mssv' => '99200001', 'code' => $old])));
$r = call('POST', ['action' => 'khtdLogin', 'mssv' => '99200001', 'code' => $code]);
check('SV trong lớp đăng nhập bằng mã tham quan', ok($r) && $r['data']['classId'] === 'GTQ_C1', $r);
$s1 = $r['data']['token'] ?? '';
check('SV ngoài lớp bị từ chối', err(call('POST', ['action' => 'khtdLogin', 'mssv' => '99200002', 'code' => $code]), 'không có trong danh sách'));
$r = call('POST', ['action' => 'checkin', 'mssv' => '99200001', 'code' => $code, 'deviceHash' => 'DVTEST0001']);
check('SV điểm danh buổi tham quan bằng cùng mã → có mặt', ok($r) && $r['data']['status'] === 'PRESENT', $r);
$pdo->prepare("UPDATE attendance_keys SET StartTime = NOW() - INTERVAL 23 HOUR, LateAfter = NOW() - INTERVAL 22 HOUR, EndTime = NOW() + INTERVAL 1 HOUR WHERE Code = :c AND SessionID = :s")->execute(['c' => $code, 's' => $sid]);
check('23 giờ sau kích hoạt: vẫn đăng nhập được', ok(call('POST', ['action' => 'khtdLogin', 'mssv' => '99200001', 'code' => $code])));
$pdo->prepare("UPDATE attendance_keys SET EndTime = NOW() - INTERVAL 1 MINUTE WHERE Code = :c AND SessionID = :s")->execute(['c' => $code, 's' => $sid]);
check('quá 24 giờ: hết hạn', err(call('POST', ['action' => 'khtdLogin', 'mssv' => '99200001', 'code' => $code])));
$r = call('GET', ['action' => 'gtnTqStatus', 'token' => $gv, 'classId' => 'GTQ_C1']);
check('trạng thái: expired', ok($r) && $r['data']['sessions'][0]['tq']['state'] === 'expired', $r);
$pdo->prepare("UPDATE attendance_keys SET EndTime = NOW() + INTERVAL 2 HOUR WHERE Code = :c AND SessionID = :s")->execute(['c' => $code, 's' => $sid]);
$r = call('POST', ['action' => 'gtnTqStop', 'token' => $gv, 'sessionId' => $sid]);
check('GV dừng sớm', ok($r) && $r['data']['tq']['state'] === 'closed', $r);
check('sau khi dừng: không đăng nhập mới được', err(call('POST', ['action' => 'khtdLogin', 'mssv' => '99200001', 'code' => $code])));

echo "== Phiếu 7 (mục đánh dấu + 2 ảnh) ==\n";
$r = call('POST', ['action' => 'khtdSeedWorksheets', 'token' => $gv, 'classId' => 'GTQ_C1', 'bo' => 'gtn']);
$w7 = null; foreach (($r['data']['created'] ?? []) as $c) if ((int) $c['no'] === 7) $w7 = $c['id'];
check('bộ gtn tạo đủ 7 phiếu, có Phiếu 7', ok($r) && count($r['data']['created']) === 7 && $w7 !== null, $r);
$r = call('GET', ['action' => 'khtdListWorksheets', 'token' => $s1]);
$nos = ok($r) ? array_map(fn ($x) => (int) $x['No'], $r['data']['worksheets']) : [];
check('SV thấy Phiếu 7 trong danh sách', in_array(7, $nos, true), $r);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $s1, 'worksheetId' => $w7]);
$types = ok($r) ? array_column($r['data']['worksheet']['schema']['sections'], 'type') : [];
check('Phiếu 7 có mục choice và bảng ảnh', in_array('choice', $types, true) && in_array('table', $types, true), $types);
$schema = $r['data']['worksheet']['schema'] ?? [];
$hasRef = isset($schema['reference']) || isset($schema['answer_key']);
check('SV không thấy dữ liệu chuẩn chấm (reference)', !$hasRef);
$ans = ['chung' => [0, 1, 1], 'dinhdanh' => [['Nhóm 3, 20/11'], ['Spirifer sp. – tay cuộn'], ['Tay cuộn'], ['Devon'], ['Hà Giang']],
        'hinhthai' => [['5 × 3 × 2'], ['Hai vỏ không bằng nhau'], ['Rãnh giữa']], 'danhdau' => [4, 0, [0, 3], [0], [0, 1], 1],
        'q1' => 'Có tay cuộn', 'q2' => 'Định tuổi Devon', 'q3' => 'Đá vôi, karst', 'q4' => 'Thú vị', 'anh' => [['mẫu'], ['hình vẽ']]];
$r = call('POST', ['action' => 'khtdSaveDraft', 'token' => $s1, 'worksheetId' => $w7, 'answers' => json_encode($ans)]);
check('lưu nháp Phiếu 7', ok($r), $r);
$png = base64_encode(file_get_contents('data://text/plain;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
foreach ([0, 1] as $row) {
    $r = call('POST', ['action' => 'khtdUploadPhoto', 'token' => $s1, 'worksheetId' => $w7, 'sectionId' => 'anh', 'row' => (string) $row, 'image' => 'data:image/png;base64,' . $png]);
    check("tải ảnh dòng $row", ok($r), $r);
    if (ok($r)) $ans['_photos']['anh'][$row] = [$r['data']['photoId']];
}
check('bảng không có ảnh thì từ chối ảnh', err(call('POST', ['action' => 'khtdUploadPhoto', 'token' => $s1, 'worksheetId' => $w7, 'sectionId' => 'dinhdanh', 'row' => '0', 'image' => 'data:image/png;base64,' . $png])));
$r = call('POST', ['action' => 'khtdSubmit', 'token' => $s1, 'worksheetId' => $w7, 'answers' => json_encode($ans)]);
check('nộp Phiếu 7', ok($r) && $r['data']['status'] === 'SUBMITTED', $r);
$row = $pdo->query("SELECT AnswersJSON FROM khtd_submissions WHERE WorksheetID = " . $pdo->quote((string) $w7) . " AND StudentID = 'GTQ_S1'")->fetchColumn();
$saved = json_decode((string) $row, true) ?: [];
check('bài lưu giữ đúng ô đánh dấu và 2 ảnh', ($saved['danhdau'][2] ?? null) === [0, 3] && count($saved['_photos']['anh'] ?? []) === 2, $saved);
$ws = khtd_worksheet((string) $w7);
$lab = khtd_choice_labels($ws['schema'], $saved);
check('đổi chỉ số sang nhãn cho hệ thống chấm', ($lab['danhdau']['Hoa văn bề mặt'] ?? '') === 'Sườn thô, rõ, Đường sinh trưởng đồng tâm' && ($lab['chung']['Ngành học của bạn'] ?? '') === 'Địa chất học', $lab['danhdau'] ?? $lab);
$r = call('GET', ['action' => 'khtdLecturerList', 'token' => $gv, 'classId' => 'GTQ_C1']);
$subId = null; foreach (($r['data']['submissions'] ?? []) as $s) if ((int) $s['No'] === 7) $subId = $s['SubmissionID'];
check('GV thấy bài nộp Phiếu 7', $subId !== null, $r);
ob_start();
$pdfErr = null;
try { $bytes = khtd_pdf_one((string) $subId)['data']; } catch (Throwable $e) { $pdfErr = $e->getMessage(); $bytes = ''; }
ob_end_clean();
check('PDF phiếu báo điểm dựng được (có mục đánh dấu)', $pdfErr === null && str_starts_with((string) $bytes, '%PDF'), $pdfErr ?? substr((string) $bytes, 0, 20));

$cleanup($pdo);
echo "\n$pass PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
