<?php
declare(strict_types=1);

/**
 * tools/smoke_dkv.php — Kiểm thử bộ phiếu "Thực tập Đá và Khoáng vật" (docs/04 mục 35): tạo 5 phiếu bo=dkv,
 * cột ảnh mẫu ở bảng mô tả, nộp bài, lời dặn chấm theo môn, nội dung gửi SV gọi là "phần mềm chấm tự động"
 * (không nhắc AI), giảng viên duyệt điểm. CLI-ONLY, CHỈ chạy trên CSDL THỬ (không có gemini.api_key).
 *
 *   php tools/smoke_dkv.php --config=../private/config.test.php
 *
 * Dữ liệu demo tiền tố DKT_ (MSSV 9930000x), dọn trước và sau khi chạy.
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
ini_set('error_log', sys_get_temp_dir() . '/diemdanhsv-smoke-dkv.log');
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
              'enrollments' => 'StudentID', 'students' => 'StudentID'] as $t => $c) $pdo->exec("DELETE FROM $t WHERE $c LIKE 'DKT\\_%'");
    $pdo->exec("DELETE FROM khtd_worksheets WHERE ClassID LIKE 'DKT\\_%'");
    $pdo->exec("DELETE FROM auth_tokens WHERE SubjectID LIKE 'DKT\\_%'");
    $pdo->exec("DELETE FROM classes WHERE ClassID LIKE 'DKT\\_%'");
    $pdo->exec("DELETE FROM courses WHERE CourseID LIKE 'DKT\\_%'");
    $pdo->exec("DELETE FROM users WHERE UserID LIKE 'DKT\\_%'");
    $pdo->exec("DELETE FROM rate_limits WHERE ClientKey = '127.0.0.1' OR ClientKey LIKE 'DKT\\_%'");
};
$cleanup($pdo);
$ins = static function (string $table, array $row) use ($pdo): void {
    $cols = implode(',', array_keys($row)); $ph = ':' . implode(',:', array_keys($row));
    $pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($ph)")->execute($row);
};
$ins('users', ['UserID' => 'DKT_U1', 'Username' => 'dkt_gv1', 'FullName' => 'GV Thử Đ&KV', 'Email' => 'gv@example.invalid',
    'PasswordHash' => password_hash('x', PASSWORD_DEFAULT), 'Role' => 'LECTURER', 'Status' => 'ACTIVE']);
$ins('courses', ['CourseID' => 'DKT_CO1', 'CourseCode' => 'LEC10001', 'CourseName' => 'Đá và khoáng vật (thử)']);
$ins('classes', ['ClassID' => 'DKT_C1', 'CourseID' => 'DKT_CO1', 'LecturerID' => 'DKT_U1', 'ClassCode' => 'LEC10001-THU']);
$ins('classes', ['ClassID' => 'DKT_C2', 'CourseID' => 'DKT_CO1', 'LecturerID' => 'DKT_U1', 'ClassCode' => 'KHTD-THU']);
$ins('students', ['StudentID' => 'DKT_S1', 'MSSV' => '99300001', 'FullName' => 'SV Thử Đ&KV', 'Email' => '']);
$ins('enrollments', ['EnrollmentID' => 'DKT_E1', 'StudentID' => 'DKT_S1', 'ClassID' => 'DKT_C1']);
$tok = static function (string $kind, string $subject) use ($pdo): string {
    $t = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO auth_tokens (Token, Kind, SubjectID, ExpiresAt) VALUES (:t, :k, :s, NOW() + INTERVAL 1 HOUR)")->execute(['t' => $t, 'k' => $kind, 's' => $subject]);
    return $t;
};
$gv = $tok('LECTURER', 'DKT_U1');
$sv = $tok('KHTD', 'DKT_S1');

echo "== Bộ phiếu Đ&KV (api/lib/dkv_phieu.json) ==\n";
$defs = dkv_default_worksheets();
check('đọc được 6 phiếu (5 tuần thực tập + phiếu thử 2 mẫu)', array_keys($defs) === [1, 2, 3, 4, 5, 6], array_keys($defs));
$okRub = true; $okPhoto = true; $okMon = true;
foreach ($defs as $no => $d) {
    if (abs(array_sum(array_column($d['rubric']['criteria'], 'max')) - 10) > 1e-9) $okRub = false;
    $hasPhoto = false; foreach ($d['sections'] as $s) if (($s['id'] ?? '') === 'mota' && !empty($s['photos']) && ($s['photo_label'] ?? '') === 'Ảnh mẫu') $hasPhoto = true;
    if (!$hasPhoto) $okPhoto = false;
    if (($d['mon']['ma'] ?? '') !== 'Đ&KV' || !str_contains((string) ($d['mon']['url'] ?? ''), 'dkv/online.html')) $okMon = false;
}
check('mỗi phiếu rubric tổng 10 điểm', $okRub);
check('mỗi phiếu có bảng mô tả mẫu với cột "Ảnh mẫu"', $okPhoto);
check('mỗi phiếu có thông tin môn Đ&KV, link dkv/online.html', $okMon);
$rowsOf = static function (array $d): int { foreach ($d['sections'] as $s) if (($s['id'] ?? '') === 'mota') return count($s['rows']); return 0; };
check('phiếu khoáng vật 10 mẫu, phiếu đá 5 mẫu', $rowsOf($defs[1]) === 10 && $rowsOf($defs[2]) === 10 && $rowsOf($defs[3]) === 5 && $rowsOf($defs[4]) === 5 && $rowsOf($defs[5]) === 5 && $rowsOf($defs[6]) === 2);
$okCrit = true;
foreach ($defs as $d) { $n = $rowsOf($d); $ids = array_column($d['rubric']['criteria'], 'id'); for ($k = 1; $k <= $n; $k++) if (!in_array("m$k", $ids, true)) $okCrit = false; }
check('rubric chấm riêng từng mẫu (tiêu chí m1…mN)', $okCrit);
check('bảng đối chiếu khoáng vật có các khoáng vật ngoài 14 mẫu (vd pyrit, galenit, fluorit)', str_contains($defs[1]['reference'], 'Pyrit') && str_contains($defs[1]['reference'], 'Galenit') && str_contains($defs[2]['reference'], 'Fluorit'));
$nhan = (string) ($defs[1]['mon']['nhan_cham'] ?? '');
check('nhãn điểm gửi SV: phần mềm chấm tự động, GV chưa chốt, không chữ AI', str_contains($nhan, 'phần mềm chấm tự động') && str_contains($nhan, 'chưa chốt') && !preg_match('/\bAI\b|trí tuệ nhân tạo/u', $nhan), $nhan);

echo "== Tạo phiếu cho lớp (bo=dkv) ==\n";
$r = call('POST', ['action' => 'khtdSeedWorksheets', 'token' => $gv, 'classId' => 'DKT_C1', 'bo' => 'dkv']);
check('GV tạo 6 phiếu Đ&KV', ok($r) && count($r['data']['created']) === 6, $r);
$ids = []; foreach (($r['data']['created'] ?? []) as $c) $ids[(int) $c['no']] = $c['id'];
$r = call('POST', ['action' => 'khtdSeedWorksheets', 'token' => $gv, 'classId' => 'DKT_C1', 'bo' => 'dkv']);
check('tạo lại không trùng (0 phiếu mới, 0 cập nhật)', ok($r) && count($r['data']['created']) === 0 && count($r['data']['updated'] ?? []) === 0, $r);
$pdo->prepare("UPDATE khtd_worksheets SET SchemaJSON = :s WHERE WorksheetID = :id")->execute(['s' => '{"title":"cũ","sections":[],"rubric":{"criteria":[]}}', 'id' => $ids[2]]);
$r = call('POST', ['action' => 'khtdSeedWorksheets', 'token' => $gv, 'classId' => 'DKT_C1', 'bo' => 'dkv']);
check('phiếu đã tạo nhưng chưa có bài làm → cập nhật nội dung mới', ok($r) && array_column($r['data']['updated'] ?? [], 'no') === [2], $r['data'] ?? $r);
$st = $pdo->query("SELECT No, Status, MaxAttempts, ScorePolicy FROM khtd_worksheets WHERE ClassID = 'DKT_C1' ORDER BY No")->fetchAll();
check('6 phiếu đều mở, làm tối đa 2 lần, lấy điểm cao nhất', count($st) === 6 && !array_filter($st, static fn ($x) => $x['Status'] !== 'OPEN' || (int) $x['MaxAttempts'] !== 2 || $x['ScorePolicy'] !== 'BEST'), $st);
$r = call('POST', ['action' => 'khtdSeedWorksheets', 'token' => $gv, 'classId' => 'DKT_C2']);
check('lớp khác không truyền bo → vẫn tạo bộ KHTĐ cũ (7 phiếu)', ok($r) && count($r['data']['created']) === 7, $r);

echo "== Sinh viên ==\n";
$r = call('GET', ['action' => 'khtdListWorksheets', 'token' => $sv]);
$nos = array_map(static fn ($w) => (int) ($w['No'] ?? $w['no'] ?? 0), $r['data']['worksheets'] ?? ($r['data'] ?? []));
sort($nos);
check('SV thấy đúng 6 phiếu Đ&KV', ok($r) && $nos === [1, 2, 3, 4, 5, 6], $nos ?: $r);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $sv, 'worksheetId' => $ids[1]]);
$schema = $r['data']['worksheet']['schema'] ?? ($r['data']['schema'] ?? []);
check('SV mở phiếu 1: không lộ đáp án / dữ liệu chuẩn / lời dặn chấm', ok($r) && !isset($schema['answer_key']) && !isset($schema['reference']) && !isset($schema['answer_hints']), array_keys($schema));
$sec = null; foreach ($schema['sections'] ?? [] as $s) if (($s['id'] ?? '') === 'mota') $sec = $s;
check('bảng mô tả có cột "Ảnh mẫu", 12 cột tính chất, 10 mẫu', $sec && !empty($sec['photos']) && ($sec['photo_label'] ?? '') === 'Ảnh mẫu' && count($sec['columns']) === 12 && count($sec['rows']) === 10, $sec);

$png = 'data:image/png;base64,' . base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$r = call('POST', ['action' => 'khtdUploadPhoto', 'token' => $sv, 'worksheetId' => $ids[1], 'sectionId' => 'mota', 'row' => 0, 'image' => $png]);
check('SV tải ảnh mẫu 1', ok($r) && str_starts_with((string) ($r['data']['photoId'] ?? ''), 'PHO_'), $r);
$pid = (string) ($r['data']['photoId'] ?? '');
$r2 = call('POST', ['action' => 'khtdUploadPhoto', 'token' => $sv, 'worksheetId' => $ids[1], 'sectionId' => 'thongtin', 'row' => 0, 'image' => $png]);
check('bảng thông tin không có cột ảnh → từ chối tải ảnh', !ok($r2), $r2);
$ans = ['mota' => [['Không màu, trắng', 'Trắng', 'Thủy tinh', 'Trong đến nửa trong', '7', 'Không cát khai', 'Vỏ sò', 'Tinh thể lăng trụ', 'Vạch được kính', 'Thạch anh', 'Thành phần chính của đất cát', 'Thủy tinh, cát xây dựng']],
        'q1' => 'X là thạch anh, Y là canxit.', '_photos' => ['mota' => ['0' => [$pid]]]];
$r = call('POST', ['action' => 'khtdSaveDraft', 'token' => $sv, 'worksheetId' => $ids[1], 'answers' => json_encode($ans, JSON_UNESCAPED_UNICODE)]);
check('SV lưu nháp kèm ảnh', ok($r), $r);
$r = call('POST', ['action' => 'khtdSubmit', 'token' => $sv, 'worksheetId' => $ids[1], 'answers' => json_encode($ans, JSON_UNESCAPED_UNICODE)]);
check('SV nộp phiếu 1 (chưa cấu hình chấm tự động → chờ GV chấm)', ok($r), $r);
$row = $pdo->query("SELECT SubmissionID, AnswersJSON, Status FROM khtd_submissions WHERE StudentID = 'DKT_S1' AND WorksheetID = " . $pdo->quote($ids[1]))->fetch();
$saved = json_decode((string) $row['AnswersJSON'], true);
check('bài nộp giữ tham chiếu ảnh mẫu', ($saved['_photos']['mota']['0'][0] ?? ($saved['_photos']['mota'][0][0] ?? '')) === $pid, $saved['_photos'] ?? null);
check('trạng thái SUBMITTED', $row['Status'] === 'SUBMITTED', $row['Status']);
$pdo->prepare("UPDATE khtd_worksheets SET SchemaJSON = REPLACE(SchemaJSON, 'Tuần 1', 'Tuan 1') WHERE WorksheetID = :id")->execute(['id' => $ids[1]]);
$r = call('POST', ['action' => 'khtdSeedWorksheets', 'token' => $gv, 'classId' => 'DKT_C1', 'bo' => 'dkv']);
check('phiếu đã có bài làm → KHÔNG cập nhật nội dung', ok($r) && !in_array(1, array_column($r['data']['updated'] ?? [], 'no'), true), $r['data'] ?? $r);

echo "== Chấm theo môn, nội dung gửi sinh viên ==\n";
$wsRow = khtd_worksheet($ids[1]);
$sys = khtd_grade_system($wsRow['schema']);
check('lời dặn chấm: vai trò môn Đá và khoáng vật', str_contains($sys, 'môn Đá và khoáng vật (LEC10001') && !str_contains($sys, 'Khoa học Trái đất'), mb_substr($sys, 0, 160));
check('lời dặn chấm: chấm riêng từng mẫu theo tên đã gọi, lệch ≤ 0,5 bậc Mohs', str_contains($sys, "ứng với tiêu chí 'm{k}'") && str_contains($sys, 'lý thuyết của chính tên đã gọi') && str_contains($sys, 'không quá 0,5 bậc Mohs'));
check('lời dặn chấm: nhận xét gửi SV không nhắc AI, giữ khuôn JSON', str_contains($sys, 'KHÔNG nhắc tới AI') && str_contains($sys, '"criteria"'));
$m = khtd_mon($wsRow['schema']);
check('email Đ&KV: tiêu đề [Đ&KV], link dkv, nhãn phần mềm chấm tự động', $m['ma'] === 'Đ&KV' && str_contains($m['url'], 'dkv/online.html') && str_contains($m['nhan_cham'], 'phần mềm chấm tự động'), $m);
check('email KHTĐ giữ nhãn cũ (nhan_cham rỗng)', khtd_mon(khtd_default_worksheets()[1])['nhan_cham'] === '');
$ai = ['total' => 7.5, 'criteria' => [['id' => 'mota', 'name' => 'Bảng mô tả', 'score' => 3, 'max' => 4, 'comment' => 'Mô tả đúng ánh. Bài có văn phong giống AI.']],
       'sua_bai' => [['muc' => 'Mẫu 1 – Độ cứng', 'van_de' => 'Thiếu phép thử.', 'cach_sua' => 'Thử vạch kính. Không chép từ chatbot.']], 'feedback' => 'Ai cũng cần chụp ảnh rõ. Gemini đề xuất thêm.'];
$c = dkv_loc_ai_ket_qua($ai);
check('lọc nhận xét: bỏ câu nhắc AI/chatbot/Gemini, giữ chữ "Ai" tiếng Việt',
      $c['criteria'][0]['comment'] === 'Mô tả đúng ánh.' && $c['sua_bai'][0]['cach_sua'] === 'Thử vạch kính.' && $c['feedback'] === 'Ai cũng cần chụp ảnh rõ.', $c);
$log = ini_get('error_log'); @file_put_contents($log, '');
$cfgApp = app_config()['app'] ?? [];
khtd_send_result_mail(['email' => 'sv@example.invalid', 'fullName' => 'SV Thử', 'mssv' => '99300001'], ['No' => 1, 'Title' => $wsRow['Title'] ?? 'Phiếu 1', 'schema' => $wsRow['schema']], $ai, null, null);
$mailLog = (string) @file_get_contents($log);
if (($cfgApp['mail_stub_log_body'] ?? false) === true) {
    check('email tạm tính: "phần mềm chấm tự động, giảng viên chưa chốt điểm", không chữ AI',
          str_contains($mailLog, 'Điểm tạm tính (phần mềm chấm tự động, giảng viên chưa chốt điểm): 7.50/10') && !preg_match('/\bAI\b|Gemini|chatbot|trí tuệ nhân tạo/u', $mailLog) && str_contains($mailLog, '[Đ&KV]'), mb_substr($mailLog, 0, 600));
} else {
    echo "  BỎ QUA  kiểm tra thân email (bật app.mail_stub_log_body = true trong cấu hình CSDL thử để kiểm)\n";
}

echo "== Giảng viên ==\n";
$r = call('GET', ['action' => 'khtdLecturerList', 'token' => $gv, 'classId' => 'DKT_C1']);
check('GV xem danh sách 6 phiếu + bài nộp', ok($r) && count($r['data']['worksheets']) === 6 && count($r['data']['submissions'] ?? $r['data']['rows'] ?? []) >= 1, ok($r) ? array_keys($r['data']) : $r);
$r = call('POST', ['action' => 'khtdLecturerGrade', 'token' => $gv, 'submissionId' => $row['SubmissionID'], 'finalScore' => '8.5', 'note' => 'Mô tả tốt', 'sendEmail' => '1']);
check('GV chốt điểm, tạo phiếu điểm PDF (SV thử không có email)', ok($r), $r);
$r = call('GET', ['action' => 'khtdStats', 'token' => $gv, 'classId' => 'DKT_C1']);
check('thống kê lớp chạy với phiếu có mục "info"', ok($r), $r['message'] ?? '');
$r = call('GET', ['action' => 'khtdExportCsv', 'token' => $gv, 'classId' => 'DKT_C1']);
check('xuất CSV điểm phiếu', ok($r), $r['message'] ?? '');

$cleanup($pdo);
echo "\nKết quả: $pass PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
