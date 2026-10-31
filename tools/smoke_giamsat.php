<?php
declare(strict_types=1);

/**
 * tools/smoke_giamsat.php — Kiểm thử giám sát chống gian lận phiếu online (docs/04 mục 39): phiếu Đ&KV số 6 bật
 * schema.giam_sat; SV báo sự kiện (khtdLogEvent) → audit_log; GV thấy số vi phạm + nhật ký; đã nộp thì bỏ qua.
 * Dựa trên khung tools/smoke_dkv.php — Kiểm thử bộ phiếu "Thực tập Đá và Khoáng vật" (docs/04 mục 35): tạo 5 phiếu bo=dkv,
 * cột ảnh mẫu ở bảng mô tả, nộp bài, lời dặn chấm theo môn, nội dung gửi SV gọi là "phần mềm chấm tự động"
 * (không nhắc AI), giảng viên duyệt điểm. CLI-ONLY, CHỈ chạy trên CSDL THỬ (không có gemini.api_key).
 *
 *   php tools/smoke_dkv.php --config=../private/config.test.php
 *
 * Dữ liệu demo tiền tố GST_ (MSSV 9931000x), dọn trước và sau khi chạy.
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
ini_set('error_log', sys_get_temp_dir() . '/diemdanhsv-smoke-giamsat.log');
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
    $pdo->exec("DELETE FROM audit_log WHERE Actor LIKE 'GST\\_%'");
    foreach (['khtd_submissions' => 'StudentID', 'khtd_extra_attempts' => 'StudentID', 'khtd_photos' => 'StudentID', 'auth_tokens' => 'SubjectID',
              'enrollments' => 'StudentID', 'students' => 'StudentID'] as $t => $c) $pdo->exec("DELETE FROM $t WHERE $c LIKE 'GST\\_%'");
    $pdo->exec("DELETE FROM khtd_worksheets WHERE ClassID LIKE 'GST\\_%'");
    $pdo->exec("DELETE FROM auth_tokens WHERE SubjectID LIKE 'GST\\_%'");
    $pdo->exec("DELETE FROM classes WHERE ClassID LIKE 'GST\\_%'");
    $pdo->exec("DELETE FROM courses WHERE CourseID LIKE 'GST\\_%'");
    $pdo->exec("DELETE FROM users WHERE UserID LIKE 'GST\\_%'");
    $pdo->exec("DELETE FROM rate_limits WHERE ClientKey = '127.0.0.1' OR ClientKey LIKE 'GST\\_%'");
};
$cleanup($pdo);
$ins = static function (string $table, array $row) use ($pdo): void {
    $cols = implode(',', array_keys($row)); $ph = ':' . implode(',:', array_keys($row));
    $pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($ph)")->execute($row);
};
$ins('users', ['UserID' => 'GST_U1', 'Username' => 'gst_gv1', 'FullName' => 'GV Thử giám sát', 'Email' => 'gv@example.invalid',
    'PasswordHash' => password_hash('x', PASSWORD_DEFAULT), 'Role' => 'LECTURER', 'Status' => 'ACTIVE']);
$ins('courses', ['CourseID' => 'GST_CO1', 'CourseCode' => 'LEC10001', 'CourseName' => 'Đá và khoáng vật (thử giám sát)']);
$ins('classes', ['ClassID' => 'GST_C1', 'CourseID' => 'GST_CO1', 'LecturerID' => 'GST_U1', 'ClassCode' => 'LEC10001-GS']);
$ins('students', ['StudentID' => 'GST_S1', 'MSSV' => '99310001', 'FullName' => 'SV Thử giám sát', 'Email' => '']);
$ins('enrollments', ['EnrollmentID' => 'GST_E1', 'StudentID' => 'GST_S1', 'ClassID' => 'GST_C1']);
$tok = static function (string $kind, string $subject) use ($pdo): string {
    $t = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO auth_tokens (Token, Kind, SubjectID, ExpiresAt) VALUES (:t, :k, :s, NOW() + INTERVAL 1 HOUR)")->execute(['t' => $t, 'k' => $kind, 's' => $subject]);
    return $t;
};
$gv = $tok('LECTURER', 'GST_U1');
$sv = $tok('KHTD', 'GST_S1');

echo "== Nội dung phiếu 6 ==\n";
$defs = dkv_default_worksheets();
$p6 = $defs[6]; $mota = static function (array $d) { foreach ($d['sections'] as $s) if (($s['id'] ?? '') === 'mota') return $s; return null; };
check('phiếu 6 bật giám sát (ngưỡng 3)', ($p6['giam_sat']['max'] ?? null) === 3, $p6['giam_sat'] ?? null);
check('phiếu 6 chỉ có lời dặn + bảng mô tả mẫu (không câu hỏi, không tự đánh giá)', array_column($p6['sections'], 'id') === ['mau', 'mota'], array_column($p6['sections'], 'id'));
check('bảng mô tả phiếu 6 giống phiếu 1–5 (12 cột, ảnh mẫu), 2 mẫu', $mota($p6)['columns'] === $mota($defs[2])['columns'] && !empty($mota($p6)['photos']) && count($mota($p6)['rows']) === 2);
check('phiếu 1–5 không bật giám sát', !array_filter([1, 2, 3, 4, 5], static fn ($n) => isset($defs[$n]['giam_sat'])));

echo "== Sinh viên ==\n";
$r = call('POST', ['action' => 'khtdSeedWorksheets', 'token' => $gv, 'classId' => 'GST_C1', 'bo' => 'dkv']);
$ids = []; foreach (($r['data']['created'] ?? []) as $c) $ids[(int) $c['no']] = $c['id'];
check('GV tạo bộ phiếu (có số 6)', ok($r) && isset($ids[6]), $r);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $sv, 'worksheetId' => $ids[6]]);
$g = $r['data']['giamSat'] ?? [];
check('mở phiếu 6 → giamSat: ngưỡng 3, 0 vi phạm, không khoá, không lộ PIN', ok($r) && $g['max'] === 3 && $g['limit'] === 3 && $g['violations'] === 0 && $g['locked'] === false && !isset($g['pin']), $g ?: $r);
check('schema gửi SV không lộ dữ liệu chuẩn', !isset($r['data']['worksheet']['schema']['reference']));
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $sv, 'worksheetId' => $ids[1]]);
check('mở phiếu 1 → không có giamSat', ok($r) && !isset($r['data']['giamSat']));
$r = call('POST', ['action' => 'khtdLogEvent', 'token' => $sv, 'worksheetId' => $ids[1], 'type' => 'hidden']);
check('báo sự kiện trên phiếu không giám sát → từ chối', !ok($r), $r);
$r = call('POST', ['action' => 'khtdLogEvent', 'token' => $sv, 'worksheetId' => $ids[6], 'type' => 'hack', 'detail' => 'x']);
check('loại sự kiện lạ → từ chối', !ok($r), $r);
$r = call('POST', ['action' => 'khtdLogEvent', 'token' => 'sai', 'worksheetId' => $ids[6], 'type' => 'hidden']);
check('token sai → từ chối', !ok($r), $r);
foreach (['start', 'photo'] as $t) $r = call('POST', ['action' => 'khtdLogEvent', 'token' => $sv, 'worksheetId' => $ids[6], 'type' => $t, 'detail' => $t]);
check('sự kiện mở phiếu / chụp ảnh mẫu không tính vi phạm', ok($r) && $r['data']['violations'] === 0, $r);
$r = call('POST', ['action' => 'khtdLogEvent', 'token' => $sv, 'worksheetId' => $ids[6], 'type' => 'hidden', 'detail' => 'Rời trang']);
$r = call('POST', ['action' => 'khtdLogEvent', 'token' => $sv, 'worksheetId' => $ids[6], 'type' => 'paste', 'detail' => str_repeat('d', 500)]);
check('rời trang + dán → 2 vi phạm, ngưỡng 3', ok($r) && $r['data']['violations'] === 2 && $r['data']['max'] === 3, $r);
$len = (int) $pdo->query("SELECT MAX(CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(`Data`, '$.detail')))) FROM audit_log WHERE Actor = 'GST_S1'")->fetchColumn();
check('chi tiết sự kiện cắt ở 160 ký tự', $len === 160, $len);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $sv, 'worksheetId' => $ids[6]]);
check('mở lại phiếu 6 → violations 2 (không reset khi tải lại)', ok($r) && $r['data']['giamSat']['violations'] === 2 && !$r['data']['giamSat']['locked'], $r['data']['giamSat'] ?? $r);

echo "== Khoá bài và mở bằng PIN ==\n";
$r = call('POST', ['action' => 'khtdLogEvent', 'token' => $sv, 'worksheetId' => $ids[6], 'type' => 'split', 'detail' => 'Chia đôi']);
check('vi phạm thứ 3 → bài bị khoá', ok($r) && $r['data']['violations'] === 3 && $r['data']['locked'] === true, $r);
$ans0 = ['mota' => [array_fill(0, 12, 'x'), array_fill(0, 12, '')]];
$r = call('POST', ['action' => 'khtdSaveDraft', 'token' => $sv, 'worksheetId' => $ids[6], 'answers' => $ans0]);
check('đang khoá → không lưu nháp được', !ok($r) && str_contains($r['message'] ?? '', 'khoá'), $r);
$r = call('POST', ['action' => 'khtdSubmit', 'token' => $sv, 'worksheetId' => $ids[6], 'answers' => $ans0]);
check('đang khoá → không nộp được', !ok($r) && str_contains($r['message'] ?? '', 'khoá'), $r);
$r = call('POST', ['action' => 'khtdUploadPhoto', 'token' => $sv, 'worksheetId' => $ids[6], 'sectionId' => 'mota', 'row' => 0, 'image' => 'x']);
check('đang khoá → không tải ảnh được', !ok($r) && str_contains($r['message'] ?? '', 'khoá'), $r);
$r = call('POST', ['action' => 'khtdLogEvent', 'token' => $sv, 'worksheetId' => $ids[6], 'type' => 'hidden', 'detail' => 'trong lúc khoá']);
check('vi phạm trong lúc khoá vẫn ghi (4)', ok($r) && $r['data']['violations'] === 4 && $r['data']['locked'], $r);
$pin = khtd_giamsat_pin($ids[6]);
check('PIN 6 số, ổn định, khác giữa các phiếu', preg_match('/^\d{6}$/', $pin) === 1 && $pin === khtd_giamsat_pin($ids[6]) && $pin !== khtd_giamsat_pin($ids[5]), $pin);
$wrong = $pin === '000000' ? '111111' : '000000';
$r = call('POST', ['action' => 'khtdGsUnlock', 'token' => $sv, 'worksheetId' => $ids[6], 'pin' => $wrong]);
check('PIN sai → từ chối, vẫn khoá', !ok($r), $r);
$r = call('POST', ['action' => 'khtdLogEvent', 'token' => $sv, 'worksheetId' => $ids[6], 'type' => 'unlock']);
check('trình duyệt không tự ghi được sự kiện "unlock"', !ok($r), $r);
$r = call('POST', ['action' => 'khtdGsUnlock', 'token' => $sv, 'worksheetId' => $ids[6], 'pin' => $pin]);
check('PIN đúng → mở khoá, ngưỡng mới = 4 vi phạm + 1', ok($r) && $r['data']['locked'] === false && $r['data']['limit'] === 5 && $r['data']['unlocks'] === 1, $r);
$r = call('POST', ['action' => 'khtdSaveDraft', 'token' => $sv, 'worksheetId' => $ids[6], 'answers' => $ans0]);
check('mở khoá xong → lưu nháp được', ok($r), $r);
$r = call('POST', ['action' => 'khtdLogEvent', 'token' => $sv, 'worksheetId' => $ids[6], 'type' => 'copy', 'detail' => 'Sao chép']);
check('vi phạm tiếp → khoá lại', ok($r) && $r['data']['locked'] === true && $r['data']['violations'] === 5, $r);
for ($i = 0; $i < 5; $i++) call('POST', ['action' => 'khtdGsUnlock', 'token' => $sv, 'worksheetId' => $ids[6], 'pin' => $wrong]);
$r = call('POST', ['action' => 'khtdGsUnlock', 'token' => $sv, 'worksheetId' => $ids[6], 'pin' => $pin]);
check('sai PIN 5 lần trong 10 phút → tạm chặn kể cả PIN đúng', !ok($r) && str_contains($r['message'] ?? '', '10 phút'), $r);
$pdo->exec("DELETE FROM audit_log WHERE Actor = 'GST_S1' AND JSON_UNQUOTE(JSON_EXTRACT(`Data`, '$.type')) = 'pin_fail'");
$r = call('POST', ['action' => 'khtdGsUnlock', 'token' => $sv, 'worksheetId' => $ids[6], 'pin' => $pin]);
check('hết hạn chặn → PIN đúng mở được', ok($r) && $r['data']['locked'] === false, $r);

echo "== Giảng viên ==\n";
$ans = ['mota' => [['trắng', 'trắng', 'thủy tinh', 'trong', '3', '3 phương thoi', 'không', 'khối', 'sủi HCl', 'Canxit', 'cải tạo đất chua', 'vôi'], array_fill(0, 12, '')]];
$r = call('POST', ['action' => 'khtdSaveDraft', 'token' => $sv, 'worksheetId' => $ids[6], 'answers' => $ans]);
check('SV lưu nháp phiếu 6', ok($r), $r);
$r = call('GET', ['action' => 'khtdLecturerList', 'token' => $gv, 'classId' => 'GST_C1']);
$row6 = null; foreach (($r['data']['submissions'] ?? []) as $s) if ((int) $s['No'] === 6) $row6 = $s;
check('danh sách bài nộp: phiếu 6 có Violations = 5', $row6 && (int) $row6['Violations'] === 5, $row6 ?? $r);
$w6 = null; foreach (($r['data']['worksheets'] ?? []) as $w) if ((int) $w['No'] === 6) $w6 = $w;
check('thẻ phiếu 6 cho GV có PIN; phiếu 1 không', ($w6['GiamSat']['pin'] ?? '') === $pin && !array_filter($r['data']['worksheets'], static fn ($w) => (int) $w['No'] === 1 && isset($w['GiamSat'])), $w6);
$r = call('GET', ['action' => 'khtdLecturerSubmission', 'token' => $gv, 'submissionId' => $row6['SubmissionID'] ?? '']);
$ev = $r['data']['giamSat']['events'] ?? [];
check('xem bài: nhật ký đúng thứ tự (kể cả cùng một giây), 5 vi phạm, 2 lần mở khoá, có PIN',
      ok($r) && array_slice(array_column($ev, 'type'), 0, 6) === ['start', 'photo', 'hidden', 'paste', 'split', 'hidden'] && count(array_filter($ev, static fn ($e) => $e['counted'])) === 5
      && $r['data']['giamSat']['unlocks'] === 2 && $r['data']['giamSat']['pin'] === $pin && $r['data']['giamSat']['locked'] === false, array_column($ev, 'type'));

echo "== Sau khi nộp ==\n";
$r = call('POST', ['action' => 'khtdSubmit', 'token' => $sv, 'worksheetId' => $ids[6], 'answers' => $ans]);
check('SV nộp phiếu 6 (CSDL thử không có Gemini → vẫn nộp được)', ok($r), $r);
$r = call('POST', ['action' => 'khtdLogEvent', 'token' => $sv, 'worksheetId' => $ids[6], 'type' => 'hidden']);
check('sự kiện sau khi nộp → bỏ qua, không tăng vi phạm', ok($r) && !empty($r['data']['ignored']) && $r['data']['violations'] === 5, $r);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $sv, 'worksheetId' => $ids[6]]);
check('phiếu đã nộp vẫn trả cấu hình (trình duyệt tự tắt theo dõi vì không còn nháp)', ok($r) && ($r['data']['submission']['Status'] ?? '') !== 'DRAFT');

$cleanup($pdo);
echo "\nKết quả: $pass PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
