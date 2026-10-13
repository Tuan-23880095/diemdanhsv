<?php
declare(strict_types=1);

/**
 * tools/smoke_khtd_attempts.php — Kiểm thử "làm nhiều lần / làm lại" của phiếu học tập online
 * (docs/04-API-PHP.md mục 28, migration 007). CLI-ONLY, CHỈ chạy trên CSDL THỬ.
 *
 *   php tools/smoke_khtd_attempts.php --config=../private/config.test.php
 *
 * Gọi API trong cùng tiến trình (như smoke_test.php), gieo dữ liệu demo tiền tố KTA_
 * (MSSV 9910000x), dọn sạch trước và sau khi chạy. Không gọi Gemini (bỏ trống api_key ở
 * CSDL thử) — điểm AI được giả lập bằng cách ghi thẳng AiScore.
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
ini_set('error_log', sys_get_temp_dir() . '/diemdanhsv-smoke-khtd.log');
foreach (['response', 'config', 'db', 'audit', 'ratelimit', 'mailer', 'auth', 'gradeauth', 'roles', 'attendance', 'queries', 'admin', 'grading', 'khtd', 'actions'] as $lib) {
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
    $pdo->exec("DELETE FROM khtd_submissions WHERE StudentID LIKE 'KTA\\_%'");
    $pdo->exec("DELETE FROM khtd_extra_attempts WHERE StudentID LIKE 'KTA\\_%'");
    $pdo->exec("DELETE FROM khtd_worksheets WHERE ClassID LIKE 'KTA\\_%'");
    $pdo->exec("DELETE FROM auth_tokens WHERE SubjectID LIKE 'KTA\\_%'");
    $pdo->exec("DELETE FROM enrollments WHERE StudentID LIKE 'KTA\\_%'");
    $pdo->exec("DELETE FROM students WHERE StudentID LIKE 'KTA\\_%'");
    $pdo->exec("DELETE FROM classes WHERE ClassID LIKE 'KTA\\_%'");
    $pdo->exec("DELETE FROM courses WHERE CourseID LIKE 'KTA\\_%'");
    $pdo->exec("DELETE FROM users WHERE UserID LIKE 'KTA\\_%'");
    $pdo->exec("DELETE FROM rate_limits WHERE ClientKey = '127.0.0.1'");
};
$cleanup($pdo);

$ins = static function (string $table, array $row) use ($pdo): void {
    $cols = implode(',', array_keys($row)); $ph = ':' . implode(',:', array_keys($row));
    $pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($ph)")->execute($row);
};
$ins('users', ['UserID' => 'KTA_U1', 'Username' => 'kta_gv1', 'FullName' => 'GV Thử', 'Email' => 'gv@example.invalid',
    'PasswordHash' => password_hash('x', PASSWORD_DEFAULT), 'Role' => 'LECTURER', 'Status' => 'ACTIVE']);
$ins('courses', ['CourseID' => 'KTA_CO1', 'CourseCode' => 'KTA101', 'CourseName' => 'Môn thử phiếu']);
$ins('classes', ['ClassID' => 'KTA_C1', 'CourseID' => 'KTA_CO1', 'LecturerID' => 'KTA_U1', 'ClassCode' => 'KTA101-01']);
foreach ([1, 2] as $n) {
    $ins('students', ['StudentID' => "KTA_S$n", 'MSSV' => "9910000$n", 'FullName' => "SV Thử $n", 'Email' => '']);
    $ins('enrollments', ['EnrollmentID' => "KTA_E$n", 'StudentID' => "KTA_S$n", 'ClassID' => 'KTA_C1']);
}
$tok = static function (string $kind, string $subject) use ($pdo): string {
    $t = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO auth_tokens (Token, Kind, SubjectID, ExpiresAt) VALUES (:t, :k, :s, NOW() + INTERVAL 1 HOUR)")
        ->execute(['t' => $t, 'k' => $kind, 's' => $subject]);
    return $t;
};
$gv = $tok('LECTURER', 'KTA_U1');
$s1 = $tok('KHTD', 'KTA_S1');
$s2 = $tok('KHTD', 'KTA_S2');
$setAi = static function (string $subId, float $score) use ($pdo): void {
    $pdo->prepare("UPDATE khtd_submissions SET Status = 'AI_GRADED', AiScore = :s, AiJSON = :j WHERE SubmissionID = :id")
        ->execute(['s' => $score, 'id' => $subId, 'j' => json_encode(['criteria' => [], 'total' => $score, 'doi_chieu' => [['muc' => 'q1', 'dap_an' => 'ĐÁP ÁN BÍ MẬT']]])]);
};

echo "== Chuẩn bị phiếu ==\n";
$r = call('POST', ['action' => 'khtdSeedWorksheets', 'token' => $gv, 'classId' => 'KTA_C1']);
check('GV tạo 6 phiếu mặc định', ok($r) && count($r['data']['created']) === 6, $r);
$ws1 = $r['data']['created'][0]['id']; $ws2 = $r['data']['created'][1]['id'];
$r = call('GET', ['action' => 'khtdLecturerList', 'token' => $gv, 'classId' => 'KTA_C1']);
check('phiếu mới mặc định MaxAttempts = 1, ScorePolicy = BEST', ok($r) && (int) $r['data']['worksheets'][0]['MaxAttempts'] === 1 && $r['data']['worksheets'][0]['ScorePolicy'] === 'BEST', $r['data']['worksheets'][0] ?? $r);

echo "== Phiếu nộp một lần (mặc định) + GV cấp lượt làm lại ==\n";
$ans = ['q1' => 'lần 1'];
$r = call('POST', ['action' => 'khtdSaveDraft', 'token' => $s1, 'worksheetId' => $ws1, 'answers' => json_encode($ans)]);
check('SV1 lưu nháp → lần 1', ok($r) && $r['data']['attemptNo'] === 1, $r);
$r = call('POST', ['action' => 'khtdSubmit', 'token' => $s1, 'worksheetId' => $ws1, 'answers' => json_encode($ans)]);
check('SV1 nộp lần 1 (không có Gemini → SUBMITTED)', ok($r) && $r['data']['status'] === 'SUBMITTED' && $r['data']['attempt']['remaining'] === 0, $r);
$sub1 = $r['data']['submissionId'];
check('SV1 nộp lại → báo đã nộp', err(call('POST', ['action' => 'khtdSubmit', 'token' => $s1, 'worksheetId' => $ws1, 'answers' => '{}']), 'đã nộp'));
check('SV1 lưu nháp sau khi nộp → bị chặn', err(call('POST', ['action' => 'khtdSaveDraft', 'token' => $s1, 'worksheetId' => $ws1, 'answers' => '{}']), 'đã nộp'));
check('SV1 bấm Làm lại khi phiếu 1 lần → bị chặn, gợi ý báo GV', err(call('POST', ['action' => 'khtdRetake', 'token' => $s1, 'worksheetId' => $ws1]), 'báo giảng viên'));
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $s1, 'worksheetId' => $ws1]);
check('getWorksheet: canRetake = false, used 1/1', ok($r) && $r['data']['attempt']['canRetake'] === false && $r['data']['attempt']['used'] === 1 && $r['data']['attempt']['allowed'] === 1, $r['data']['attempt'] ?? $r);

check('SV không gọi được khtdGrantRetake', err(call('POST', ['action' => 'khtdGrantRetake', 'token' => $s1, 'submissionId' => $sub1])));
$r = call('POST', ['action' => 'khtdGrantRetake', 'token' => $gv, 'submissionId' => $sub1]);
check('GV cấp thêm 1 lượt → allowed 2, remaining 1', ok($r) && $r['data']['allowed'] === 2 && $r['data']['remaining'] === 1 && $r['data']['worksheetOpen'] === true, $r);
$r = call('POST', ['action' => 'khtdRetake', 'token' => $s1, 'worksheetId' => $ws1]);
check('SV1 Làm lại → lần 2', ok($r) && $r['data']['attemptNo'] === 2 && $r['data']['already'] === false, $r);
$r = call('POST', ['action' => 'khtdRetake', 'token' => $s1, 'worksheetId' => $ws1]);
check('bấm Làm lại lần nữa khi đang nháp → giữ lần 2 (không tạo thêm)', ok($r) && $r['data']['attemptNo'] === 2 && $r['data']['already'] === true, $r);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $s1, 'worksheetId' => $ws1]);
check('lần 2 được chép bài lần 1 để sửa tiếp', ok($r) && ($r['data']['submission']['answers']['q1'] ?? '') === 'lần 1' && $r['data']['submission']['Status'] === 'DRAFT' && (int) $r['data']['submission']['AttemptNo'] === 2, $r['data']['submission'] ?? $r);
$r = call('POST', ['action' => 'khtdSubmit', 'token' => $s1, 'worksheetId' => $ws1, 'answers' => json_encode(['q1' => 'lần 2'])]);
check('SV1 nộp lần 2', ok($r) && $r['data']['attemptNo'] === 2 && $r['data']['attempt']['remaining'] === 0, $r);
$sub2 = $r['data']['submissionId'];
check('hết lượt → Làm lại bị chặn', err(call('POST', ['action' => 'khtdRetake', 'token' => $s1, 'worksheetId' => $ws1]), 'hết'));

echo "== Điểm cao nhất (BEST) ==\n";
$setAi($sub1, 8.0); $setAi($sub2, 6.5);
$r = call('GET', ['action' => 'khtdListWorksheets', 'token' => $s1]);
$w = ok($r) ? array_values(array_filter($r['data']['worksheets'], fn ($x) => $x['WorksheetID'] === $ws1))[0] : null;
check('danh sách SV: điểm tính = 8 (lần 1, cao nhất)', $w && (float) $w['attempt']['score'] === 8.0 && $w['attempt']['countedNo'] === 1 && (float) $w['AiScore'] === 8.0, $w);
$r = call('GET', ['action' => 'khtdExportCsv', 'token' => $gv, 'classId' => 'KTA_C1']);
$row = ok($r) ? array_values(array_filter($r['data']['rows'], fn ($x) => $x['MSSV'] === '99100001'))[0] : null;
check('CSV: Phieu1 = 8 (cao nhất)', $row && (float) $row['Phieu1'] === 8.0, $row);
$r = call('POST', ['action' => 'khtdLecturerGrade', 'token' => $gv, 'submissionId' => $sub2, 'finalScore' => 9, 'note' => '', 'sendEmail' => false]);
check('GV duyệt lần 2 = 9', ok($r), $r);
$r = call('GET', ['action' => 'khtdExportCsv', 'token' => $gv, 'classId' => 'KTA_C1']);
$row = ok($r) ? array_values(array_filter($r['data']['rows'], fn ($x) => $x['MSSV'] === '99100001'))[0] : null;
check('CSV: Phieu1 = 9 (điểm GV duyệt lần 2 cao hơn)', $row && (float) $row['Phieu1'] === 9.0, $row);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $s1, 'worksheetId' => $ws1]);
check('SV xem bảng đối chiếu khi đã FINAL và hết lượt', ok($r) && isset($r['data']['submission']['ai']['doi_chieu']), $r['data']['submission'] ?? $r);

echo "== Đổi cách tính: lần cuối (LAST) ==\n";
check('SV không đổi được chính sách', err(call('POST', ['action' => 'khtdSetWorksheetPolicy', 'token' => $s1, 'worksheetId' => $ws1, 'maxAttempts' => 3, 'scorePolicy' => 'LAST'])));
check('maxAttempts sai (21) → lỗi', err(call('POST', ['action' => 'khtdSetWorksheetPolicy', 'token' => $gv, 'worksheetId' => $ws1, 'maxAttempts' => 21, 'scorePolicy' => 'BEST']), 'Số lần'));
check('scorePolicy sai → lỗi', err(call('POST', ['action' => 'khtdSetWorksheetPolicy', 'token' => $gv, 'worksheetId' => $ws1, 'maxAttempts' => 2, 'scorePolicy' => 'AVG'])));
$pdo->exec("UPDATE khtd_submissions SET FinalScore = NULL, Status = 'AI_GRADED' WHERE SubmissionID = " . $pdo->quote($sub2));
$r = call('POST', ['action' => 'khtdSetWorksheetPolicy', 'token' => $gv, 'worksheetId' => $ws1, 'maxAttempts' => 1, 'scorePolicy' => 'LAST']);
check('GV đặt LAST', ok($r), $r);
$r = call('GET', ['action' => 'khtdExportCsv', 'token' => $gv, 'classId' => 'KTA_C1']);
$row = ok($r) ? array_values(array_filter($r['data']['rows'], fn ($x) => $x['MSSV'] === '99100001'))[0] : null;
check('CSV: Phieu1 = 6.5 (lần cuối)', $row && (float) $row['Phieu1'] === 6.5, $row);

echo "== Phiếu cho làm 3 lần ==\n";
$r = call('POST', ['action' => 'khtdSetWorksheetPolicy', 'token' => $gv, 'worksheetId' => $ws2, 'maxAttempts' => 3, 'scorePolicy' => 'BEST']);
check('GV đặt phiếu 2: 3 lần, BEST', ok($r) && $r['data']['maxAttempts'] === 3, $r);
$subs = [];
foreach ([1, 2, 3] as $n) {
    if ($n > 1) {
        $r = call('POST', ['action' => 'khtdRetake', 'token' => $s2, 'worksheetId' => $ws2]);
        check("SV2 tự bấm Làm lại → lần $n", ok($r) && $r['data']['attemptNo'] === $n, $r);
    }
    $r = call('POST', ['action' => 'khtdSubmit', 'token' => $s2, 'worksheetId' => $ws2, 'answers' => json_encode(['q1' => "lần $n"])]);
    check("SV2 nộp lần $n", ok($r) && $r['data']['attemptNo'] === $n, $r);
    $subs[$n] = $r['data']['submissionId'];
}
check('SV2 hết 3 lượt → Làm lại bị chặn', err(call('POST', ['action' => 'khtdRetake', 'token' => $s2, 'worksheetId' => $ws2]), 'hết 3 lần'));
$setAi($subs[1], 5); $setAi($subs[2], 7.75); $setAi($subs[3], 7);
$r = call('GET', ['action' => 'khtdLecturerList', 'token' => $gv, 'classId' => 'KTA_C1']);
$mine = ok($r) ? array_values(array_filter($r['data']['submissions'], fn ($x) => $x['MSSV'] === '99100002')) : [];
check('trang GV: 3 dòng cho SV2, đánh dấu lần 2 là lần tính điểm, lần 3 là mới nhất',
    count($mine) === 3 && $mine[1]['IsCounted'] === true && $mine[0]['IsCounted'] === false && $mine[2]['IsLatest'] === true && $mine[2]['AttemptsAllowed'] === 3, $mine);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $s2, 'worksheetId' => $ws2]);
check('SV không thấy bảng đối chiếu khi chưa FINAL', ok($r) && !isset($r['data']['submission']['ai']['doi_chieu']), $r['data']['submission']['ai'] ?? null);
check('SV thấy lịch sử 3 lần, lần 2 được tính, điểm 7.75', ok($r) && count($r['data']['attempt']['attempts']) === 3 && $r['data']['attempt']['countedNo'] === 2 && (float) $r['data']['attempt']['score'] === 7.75, $r['data']['attempt'] ?? $r);
$r = call('GET', ['action' => 'khtdLecturerSubmission', 'token' => $gv, 'submissionId' => $subs[3]]);
check('GV xem bài lần 3: có danh sách 3 lần kèm submissionId', ok($r) && (int) $r['data']['AttemptNo'] === 3 && count($r['data']['attempt']['attempts']) === 3 && $r['data']['attempt']['attempts'][0]['submissionId'] === $subs[1], $r['data']['attempt'] ?? $r);

echo "== Phiếu đóng ==\n";
call('POST', ['action' => 'khtdSetWorksheetPolicy', 'token' => $gv, 'worksheetId' => $ws2, 'maxAttempts' => 0, 'scorePolicy' => 'BEST']);
check('GV cấp lượt khi phiếu không giới hạn → báo không cần', err(call('POST', ['action' => 'khtdGrantRetake', 'token' => $gv, 'submissionId' => $subs[3]]), 'không giới hạn'));
call('POST', ['action' => 'khtdSetWorksheetStatus', 'token' => $gv, 'worksheetId' => $ws2, 'status' => 'CLOSED']);
check('phiếu đóng → Làm lại bị chặn', err(call('POST', ['action' => 'khtdRetake', 'token' => $s2, 'worksheetId' => $ws2]), 'đóng'));

$cleanup($pdo);
echo "\nKết quả: $pass PASS, $fail FAIL\n";
exit($fail === 0 ? 0 : 1);
