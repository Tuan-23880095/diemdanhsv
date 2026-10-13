<?php
declare(strict_types=1);

/**
 * tools/smoke_khtd_attempts.php — Kiểm thử "làm nhiều lần / làm lại" (docs/04 mục 29, migration 007)
 * "ảnh mẫu vật" (mục 30, migration 008), thống kê + nguy cơ AI + PDF/email (mục 31) của phiếu học tập online. CLI-ONLY, CHỈ chạy trên CSDL THỬ.
 * File cấu hình thử nên có 'khtd' => ['upload_dir' => '/tmp/...'] để ảnh thử không lẫn ảnh thật.
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
    $pdo->exec("DELETE FROM khtd_submissions WHERE StudentID LIKE 'KTA\\_%'");
    $pdo->exec("DELETE FROM khtd_extra_attempts WHERE StudentID LIKE 'KTA\\_%'");
    $pdo->exec("DELETE FROM khtd_photos WHERE StudentID LIKE 'KTA\\_%'");
    $pdo->exec("DELETE FROM khtd_worksheets WHERE ClassID LIKE 'KTA\\_%'");
    $pdo->exec("DELETE FROM auth_tokens WHERE SubjectID LIKE 'KTA\\_%'");
    $pdo->exec("DELETE FROM enrollments WHERE StudentID LIKE 'KTA\\_%'");
    $pdo->exec("DELETE FROM students WHERE StudentID LIKE 'KTA\\_%'");
    $pdo->exec("DELETE FROM classes WHERE ClassID LIKE 'KTA\\_%'");
    $pdo->exec("DELETE FROM courses WHERE CourseID LIKE 'KTA\\_%'");
    $pdo->exec("DELETE FROM users WHERE UserID LIKE 'KTA\\_%'");
    $pdo->exec("DELETE FROM rate_limits WHERE ClientKey = '127.0.0.1' OR ClientKey LIKE 'KTA\\_%'");
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
check('GV tạo 7 phiếu mặc định (6 phiếu + luyện thi)', ok($r) && count($r['data']['created']) === 7, $r);
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

echo "== Ảnh mẫu vật ==\n";
$ws3 = $r3 = null;
foreach (call('GET', ['action' => 'khtdLecturerList', 'token' => $gv, 'classId' => 'KTA_C1'])['data']['worksheets'] as $w) if ((int) $w['No'] === 3) $ws3 = $w['WorksheetID'];
$img = imagecreatetruecolor(2400, 1600); imagefill($img, 0, 0, imagecolorallocate($img, 120, 90, 60));
ob_start(); imagejpeg($img, null, 90); $jpg = 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean());
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $s1, 'worksheetId' => $ws3]);
$mota = ok($r) ? array_values(array_filter($r['data']['worksheet']['schema']['sections'], fn ($x) => $x['id'] === 'mota'))[0] : null;
check('bảng mô tả mẫu (mota) có photos = true', $mota && $mota['photos'] === true, $mota);
$pdo->prepare("UPDATE khtd_worksheets SET SchemaJSON = JSON_REMOVE(SchemaJSON, '$.sections[1].photos') WHERE WorksheetID = :w")->execute(['w' => $ws3]);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $s1, 'worksheetId' => $ws3]);
$sec = ok($r) ? $r['data']['worksheet']['schema']['sections'] : [];
check('phiếu tạo trước khi có tính năng ảnh: mota vẫn nhận ảnh, bảng khác không', ($sec[1]['photos'] ?? null) === true && ($sec[0]['photos'] ?? null) === false, array_map(fn ($x) => [$x['id'], $x['photos'] ?? null], $sec));
$up = static fn (string $tok, string $secId, $row, string $data) => call('POST', ['action' => 'khtdUploadPhoto', 'token' => $tok, 'worksheetId' => $ws3, 'sectionId' => $secId, 'row' => $row, 'image' => $data]);
$r = $up($s1, 'mota', 0, $jpg);
check('SV1 tải ảnh mẫu 1 → nén về cạnh dài 1600', ok($r) && $r['data']['width'] === 1600 && $r['data']['height'] === 1067, $r);
$p1 = $r['data']['photoId'] ?? '';
$f = $pdo->query("SELECT WorksheetID, FileName FROM khtd_photos WHERE PhotoID = " . $pdo->quote($p1))->fetch();
check('tệp ảnh nằm trong thư mục upload', $f && is_file(khtd_upload_dir() . '/' . $f['WorksheetID'] . '/' . $f['FileName']), khtd_upload_dir());
check('.htaccess chặn web được tạo trong thư mục upload', is_file(khtd_upload_dir() . '/.htaccess'));
check('bảng không nhận ảnh (hat) → lỗi', err($up($s1, 'hat', 0, $jpg), 'không nhận ảnh'));
check('hàng ngoài bảng (99) → lỗi', err($up($s1, 'mota', 99, $jpg), 'Mẫu không hợp lệ'));
check('dữ liệu không phải ảnh → lỗi', err($up($s1, 'mota', 1, 'data:image/png;base64,' . base64_encode('<?php echo 1;')), 'không phải ảnh'));
$r = $up($s2, 'mota', 0, $jpg); $pOther = $r['data']['photoId'] ?? '';
check('SV2 tải ảnh của mình', ok($r), $r);
$ans = ['_photos' => ['mota' => ['0' => [$p1, $pOther, 'PHO_FAKEFAKEFAKE'], '1' => [$p1]], 'hat' => ['0' => [$p1]]]];
$r = call('POST', ['action' => 'khtdSaveDraft', 'token' => $s1, 'worksheetId' => $ws3, 'answers' => json_encode($ans)]);
$saved = json_decode((string) $pdo->query("SELECT AnswersJSON FROM khtd_submissions WHERE WorksheetID = " . $pdo->quote($ws3) . " AND StudentID = 'KTA_S1'")->fetchColumn(), true);
check('lưu nháp chỉ giữ ảnh của chính SV, đúng mẫu (bỏ ảnh SV khác, ID giả, gắn sai hàng)', ok($r) && $saved['_photos'] === ['mota' => [[$p1]]], $saved);
call('POST', ['action' => 'khtdSetWorksheetPolicy', 'token' => $gv, 'worksheetId' => $ws3, 'maxAttempts' => 2, 'scorePolicy' => 'BEST']);
$r = call('POST', ['action' => 'khtdSubmit', 'token' => $s1, 'worksheetId' => $ws3, 'answers' => json_encode(['_photos' => ['mota' => ['0' => [$p1]]]])]);
check('nộp bài kèm ảnh', ok($r), $r);
check('đã nộp → không tải thêm ảnh', err($up($s1, 'mota', 1, $jpg), 'Làm lại'));
call('POST', ['action' => 'khtdRetake', 'token' => $s1, 'worksheetId' => $ws3]);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $s1, 'worksheetId' => $ws3]);
check('làm lại: ảnh lần trước đi theo bài', ok($r) && ($r['data']['submission']['answers']['_photos']['mota'][0][0] ?? '') === $p1, $r['data']['submission']['answers'] ?? $r);
check('làm lại: tải thêm ảnh được', ok($up($s1, 'mota', 1, $jpg)));
$sid = (string) $pdo->query("SELECT SubmissionID FROM khtd_submissions WHERE WorksheetID = " . $pdo->quote($ws3) . " AND StudentID = 'KTA_S1' AND AttemptNo = 1")->fetchColumn();
$r = call('GET', ['action' => 'khtdLecturerSubmission', 'token' => $gv, 'submissionId' => $sid]);
check('GV xem bài: có tham chiếu ảnh + schema có photos', ok($r) && ($r['data']['answers']['_photos']['mota'][0][0] ?? '') === $p1 && ($r['data']['schema']['sections'][1]['photos'] ?? null) === true, $r['data']['answers'] ?? $r);

echo "== Thống kê, nguy cơ AI, PDF, email đính kèm ==\n";
$schemaT = ['sections' => [['id' => 'q', 'type' => 'questions', 'items' => [['id' => 'q1', 'text' => 'x']]]]];
$long = str_repeat('Đá granit có kiến trúc hạt thô do nguội chậm dưới sâu. ', 12);
$rk = khtd_ai_risk(['CreatedAt' => '2026-10-14 08:00:00', 'SubmittedAt' => '2026-10-14 08:40:00'], ['q1' => $long], null, $schemaT);
check('bài tự gõ, 40 phút → nguy cơ Thấp', $rk['level'] === 'Thấp', $rk);
$rk = khtd_ai_risk(['CreatedAt' => '2026-10-14 08:00:00', 'SubmittedAt' => '2026-10-14 08:01:00'],
    ['q1' => "Dưới đây là phân tích:\n**Granit** (granite) có kiến trúc (texture) hạt thô (coarse). " . $long, '_meta' => ['pasteChars' => 900]],
    ['ai_risk' => ['muc' => 90, 'dau_hieu' => ['văn phong máy']]], $schemaT);
check('bài dán + nhanh + văn phong chatbot + Gemini 90% → nguy cơ Cao', $rk['level'] === 'Cao' && count($rk['reasons']) >= 4, $rk);
check('_meta chỉ giữ số nguyên', khtd_clean_meta(['_meta' => ['pasteChars' => '12', 'evil' => '<x>', 'keys' => -5]])['_meta'] === ['pasteChars' => 12, 'keys' => 0]);
$pairs = khtd_similar_pairs(['a' => ['mssv' => '1', 'fields' => ['q1' => $long]], 'b' => ['mssv' => '2', 'fields' => ['q1' => $long . ' Thêm.']], 'c' => ['mssv' => '3', 'fields' => ['q1' => str_repeat('Basalt ẩn tinh màu đen nặng phun trào nguội nhanh trên mặt đất. ', 6)]]]);
check('phát hiện hai bài giống nhau, không gán nhầm bài thứ ba', isset($pairs['a'], $pairs['b']) && !isset($pairs['c']) && $pairs['a'][0]['mssv'] === '2', $pairs);

$r = call('GET', ['action' => 'khtdStats', 'token' => $gv, 'classId' => 'KTA_C1']);
$st2 = ok($r) ? array_values(array_filter($r['data']['worksheets'], fn ($x) => $x['worksheetId'] === $ws2))[0] : null;
check('khtdStats phiếu 2: 1/2 SV nộp, 3 bài, 3 chỉ AI chấm, cao nhất 7.75', $st2 && $st2['studentsSubmitted'] === 1 && $st2['enrolled'] === 2 && $st2['submissions']['total'] === 3
    && $st2['submissions']['aiOnly'] === 3 && (float) $st2['scores']['max']['score'] === 7.75 && count($st2['roster']) === 2, $st2 ? ['sub' => $st2['submissions'], 'sc' => $st2['scores']] : $r);
check('khtdStats chặn SV', err(call('GET', ['action' => 'khtdStats', 'token' => $s1, 'classId' => 'KTA_C1'])));

$pdf = khtd_pdf_one($subs[2], false);
check('PDF một bài: tên tệp đúng mẫu', $pdf['name'] === '99100002-SvThu2-Diem-Phieu2-DaMagma-Lan2.pdf', $pdf['name']);
check('phiếu in: lọc câu nhắc AI trong nhận xét, giữ câu khác', khtd_pdf_clean('Mô tả đúng. Bài có dấu hiệu dùng AI. Cần thêm ảnh.') === 'Mô tả đúng. Cần thêm ảnh.' && khtd_pdf_clean('Văn phong giống ChatGPT') === '');
check('phiếu in: ghi chú bài đặc biệt không còn chữ AI', khtd_pdf_tag('AI gắn cờ: thiếu ảnh') === 'Lưu ý khi chấm: thiếu ảnh' && !preg_match('/\bAI\b/u', khtd_pdf_tag('GV và AI chênh 2,5 điểm') . khtd_pdf_tag('AI chưa chấm được')));
check('phiếu in: logo Trường có trong repo', is_file(khtd_pdf_logo() ?? '') && str_ends_with((string) khtd_pdf_logo(), 'logo-hcmus.png'));
check('PDF hợp lệ (%PDF-, có trang)', str_starts_with($pdf['data'], '%PDF-') && strlen($pdf['data']) > 5000, strlen($pdf['data']));
$msg = smtp_build_message('a@b.c', 'X', 'd@e.f', 'Tiêu đề', "Thân thư", 'h', [['name' => $pdf['name'], 'mime' => 'application/pdf', 'data' => $pdf['data']]]);
check('thư kèm PDF: multipart/mixed + filename', str_contains($msg, 'multipart/mixed') && str_contains($msg, 'filename="' . $pdf['name'] . '"'));
check('thư không đính kèm giữ nguyên text/plain', str_contains(smtp_build_message('a@b.c', 'X', 'd@e.f', 'T', 'B', 'h'), 'Content-Type: text/plain'));
$r = call('POST', ['action' => 'khtdLecturerGrade', 'token' => $gv, 'submissionId' => $subs[2], 'finalScore' => 8, 'note' => 'Tốt', 'sendEmail' => true]);
check('GV duyệt + gửi mail: tạo PDF đính kèm (SV không có email → không gửi)', ok($r) && $r['data']['pdfName'] === '99100002-SvThu2-Diem-Phieu2-DaMagma-Lan2.pdf' && $r['data']['pdfError'] === null, $r);
$pdo->exec("DELETE FROM khtd_photos WHERE StudentID LIKE 'KTA\\_%'");

$cleanup($pdo);
echo "\nKết quả: $pass PASS, $fail FAIL\n";
exit($fail === 0 ? 0 : 1);
