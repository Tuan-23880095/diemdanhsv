<?php
declare(strict_types=1);

/**
 * tools/smoke_ontap.php — Kiểm thử ôn bài ở nhà (docs/04 mục 40): mã ôn tập của lớp, đăng nhập chế độ ôn tập,
 * phiếu trên lớp chỉ xem lại, phiếu ôn tập 11–15 làm không giới hạn + đáp án tham khảo, CSV không tính điểm ôn tập.
 * Dựa trên khung tools/smoke_dkv.php — Kiểm thử bộ phiếu "Thực tập Đá và Khoáng vật" (docs/04 mục 35): tạo 5 phiếu bo=dkv,
 * cột ảnh mẫu ở bảng mô tả, nộp bài, lời dặn chấm theo môn, nội dung gửi SV gọi là "phần mềm chấm tự động"
 * (không nhắc AI), giảng viên duyệt điểm. CLI-ONLY, CHỈ chạy trên CSDL THỬ (không có gemini.api_key).
 *
 *   php tools/smoke_dkv.php --config=../private/config.test.php
 *
 * Dữ liệu demo tiền tố ONT_ (MSSV 9933000x), dọn trước và sau khi chạy.
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
ini_set('error_log', sys_get_temp_dir() . '/diemdanhsv-smoke-ontap.log');
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
    $pdo->exec("DELETE FROM audit_log WHERE Actor LIKE 'ONT\\_%' OR TargetID LIKE 'ONT\\_%'");
    foreach (['khtd_submissions' => 'StudentID', 'khtd_extra_attempts' => 'StudentID', 'khtd_photos' => 'StudentID', 'auth_tokens' => 'SubjectID',
              'enrollments' => 'StudentID', 'students' => 'StudentID'] as $t => $c) $pdo->exec("DELETE FROM $t WHERE $c LIKE 'ONT\\_%'");
    $pdo->exec("DELETE FROM khtd_worksheets WHERE ClassID LIKE 'ONT\\_%'");
    $pdo->exec("DELETE FROM auth_tokens WHERE SubjectID LIKE 'ONT\\_%'");
    $pdo->exec("DELETE FROM classes WHERE ClassID LIKE 'ONT\\_%'");
    $pdo->exec("DELETE FROM courses WHERE CourseID LIKE 'ONT\\_%'");
    $pdo->exec("DELETE FROM users WHERE UserID LIKE 'ONT\\_%'");
    $pdo->exec("DELETE FROM rate_limits WHERE ClientKey = '127.0.0.1' OR ClientKey LIKE 'ONT\\_%'");
};
$cleanup($pdo);
$ins = static function (string $table, array $row) use ($pdo): void {
    $cols = implode(',', array_keys($row)); $ph = ':' . implode(',:', array_keys($row));
    $pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($ph)")->execute($row);
};
$ins('users', ['UserID' => 'ONT_U1', 'Username' => 'ont_gv1', 'FullName' => 'GV Thử ôn tập', 'Email' => 'gv@example.invalid',
    'PasswordHash' => password_hash('x', PASSWORD_DEFAULT), 'Role' => 'LECTURER', 'Status' => 'ACTIVE']);
$ins('courses', ['CourseID' => 'ONT_CO1', 'CourseCode' => 'LEC10001', 'CourseName' => 'Đá và khoáng vật (thử ôn tập)']);
$ins('classes', ['ClassID' => 'ONT_C1', 'CourseID' => 'ONT_CO1', 'LecturerID' => 'ONT_U1', 'ClassCode' => 'LEC10001-OT']);
$ins('classes', ['ClassID' => 'ONT_C2', 'CourseID' => 'ONT_CO1', 'LecturerID' => 'ONT_U1', 'ClassCode' => 'LEC10001-OT2']);
$ins('students', ['StudentID' => 'ONT_S1', 'MSSV' => '99330001', 'FullName' => 'SV Thử ôn tập', 'Email' => '']);
$ins('students', ['StudentID' => 'ONT_S2', 'MSSV' => '99330002', 'FullName' => 'SV lớp khác', 'Email' => '']);
$ins('enrollments', ['EnrollmentID' => 'ONT_E1', 'StudentID' => 'ONT_S1', 'ClassID' => 'ONT_C1']);
$ins('enrollments', ['EnrollmentID' => 'ONT_E2', 'StudentID' => 'ONT_S2', 'ClassID' => 'ONT_C2']);
$tok = static function (string $kind, string $subject) use ($pdo): string {
    $t = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO auth_tokens (Token, Kind, SubjectID, ExpiresAt) VALUES (:t, :k, :s, NOW() + INTERVAL 1 HOUR)")->execute(['t' => $t, 'k' => $kind, 's' => $subject]);
    return $t;
};
$gv = $tok('LECTURER', 'ONT_U1');
$svLop = $tok('KHTD', 'ONT_S1');   // token trên lớp (mã buổi học)

echo "== Nội dung 5 phiếu ôn tập ==\n";
$defs = dkv_default_worksheets();
$okOT = true;
foreach ([11, 12, 13, 14, 15] as $n) {
    $d = $defs[$n] ?? null;
    $q = []; foreach ($d['sections'] ?? [] as $s) if (($s['type'] ?? '') === 'questions') $q = $s['items'];
    if (!$d || empty($d['on_tap']) || (int) $d['max_attempts'] !== 0 || count($q) !== 10
        || array_keys($d['answer_key']) !== array_column($q, 'id') || in_array('', $d['answer_key'], true)
        || abs(array_sum(array_column($d['rubric']['criteria'], 'max')) - 10) > 1e-9 || isset($d['giam_sat'])) $okOT = false;
}
check('phiếu 11–15: on_tap, không giới hạn lượt, 10 câu có đủ đáp án, rubric 10 điểm, không giám sát', $okOT);
check('phiếu 1–6 không phải ôn tập', !array_filter([1, 2, 3, 4, 5, 6], static fn ($n) => !empty($defs[$n]['on_tap'])));

echo "== Mã ôn tập (GV) ==\n";
$r = call('POST', ['action' => 'khtdSeedWorksheets', 'token' => $gv, 'classId' => 'ONT_C1', 'bo' => 'dkv']);
$ids = []; foreach (($r['data']['created'] ?? []) as $c) $ids[(int) $c['no']] = $c['id'];
check('GV tạo 11 phiếu (có 11–15)', ok($r) && count($ids) === 11 && isset($ids[15]), $r);
$r = call('POST', ['action' => 'khtdReviewCode', 'token' => $gv, 'classId' => 'ONT_C1', 'op' => 'get']);
check('lớp chưa có mã ôn tập', ok($r) && $r['data']['review'] === null, $r);
$r = call('POST', ['action' => 'khtdReviewCode', 'token' => $svLop, 'classId' => 'ONT_C1', 'op' => 'create']);
check('token sinh viên không tạo được mã', !ok($r), $r);
$r = call('POST', ['action' => 'khtdReviewCode', 'token' => $gv, 'classId' => 'ONT_C1', 'op' => 'create', 'days' => 30]);
$code = $r['data']['review']['code'] ?? '';
check('GV tạo mã ôn tập 6 ký tự, còn hiệu lực ~30 ngày', ok($r) && preg_match('/^[A-Z0-9]{6}$/', $code) && $r['data']['review']['active'] && strtotime($r['data']['review']['expiresAt']) > time() + 29 * 86400, $r);

echo "== Đăng nhập ở nhà ==\n";
// SV làm phiếu 1 trên lớp trước (để có bài xem lại)
$ans1 = ['mota' => array_fill(0, 10, array_fill(0, 12, 'x'))];
$r = call('POST', ['action' => 'khtdSubmit', 'token' => $svLop, 'worksheetId' => $ids[1], 'answers' => $ans1]);
check('trên lớp: SV nộp phiếu 1', ok($r), $r);
$r = call('POST', ['action' => 'khtdReviewLogin', 'mssv' => '99330001', 'code' => 'ZZZZZZ']);
check('mã sai → từ chối', !ok($r), $r);
$r = call('POST', ['action' => 'khtdReviewLogin', 'mssv' => '99330002', 'code' => $code]);
check('SV lớp khác dùng mã của lớp này → từ chối', !ok($r), $r);
$r = call('POST', ['action' => 'khtdReviewLogin', 'mssv' => '99330001', 'code' => strtolower(substr($code, 0, 3)) . ' ' . strtolower(substr($code, 3))]);
$rv = $r['data']['token'] ?? '';
check('MSSV + mã ôn tập (chữ thường, có dấu cách) → vào chế độ ôn tập', ok($r) && $rv !== '' && $r['data']['review'] === true, $r);
$kind = $pdo->query("SELECT Kind FROM auth_tokens WHERE Token = " . $pdo->quote($rv))->fetchColumn();
check('token ôn tập là Kind GRADE', $kind === 'GRADE', $kind);

echo "== Chế độ ôn tập: phiếu trên lớp chỉ xem lại ==\n";
$r = call('GET', ['action' => 'khtdListWorksheets', 'token' => $rv]);
$by = []; foreach (($r['data']['worksheets'] ?? []) as $w) $by[(int) $w['No']] = $w;
check('danh sách: phiếu 1 xem lại, phiếu 11 ôn tập làm được', ok($r) && $r['data']['student']['review'] === true && $by[1]['ReviewOnly'] && !$by[1]['IsOnTap'] && !$by[11]['ReviewOnly'] && $by[11]['IsOnTap'] && !$by[1]['attempt']['canRetake'], $by[1] ?? $r);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $rv, 'worksheetId' => $ids[1]]);
check('mở phiếu 1: thấy bài đã nộp, open=false, reviewOnly, không làm lại', ok($r) && $r['data']['worksheet']['open'] === false && $r['data']['worksheet']['reviewOnly'] === true
      && ($r['data']['submission']['Status'] ?? '') !== 'DRAFT' && $r['data']['attempt']['canRetake'] === false && !isset($r['data']['worksheet']['schema']['reference']), $r['data']['worksheet'] ?? $r);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $rv, 'worksheetId' => $ids[6]]);
check('mở phiếu 6 (giám sát) ở nhà: không bật giám sát', ok($r) && !isset($r['data']['giamSat']), $r['data']['giamSat'] ?? '');
foreach ([['khtdSaveDraft', ['answers' => $ans1]], ['khtdSubmit', ['answers' => $ans1]], ['khtdRetake', []],
          ['khtdUploadPhoto', ['sectionId' => 'mota', 'row' => 0, 'image' => 'x']]] as [$act, $extra]) {
    $r = call('POST', ['action' => $act, 'token' => $rv, 'worksheetId' => $ids[2]] + $extra);
    check("$act phiếu 2 ở chế độ ôn tập → từ chối", !ok($r) && str_contains($r['message'] ?? '', 'ôn tập'), $r);
}
$r = call('POST', ['action' => 'khtdLogEvent', 'token' => $rv, 'worksheetId' => $ids[6], 'type' => 'hidden']);
check('báo giám sát ở chế độ ôn tập → từ chối', !ok($r), $r);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $svLop, 'worksheetId' => $ids[2]]);
check('token trên lớp vẫn làm phiếu bình thường (open, không reviewOnly)', ok($r) && $r['data']['worksheet']['open'] === true && $r['data']['worksheet']['reviewOnly'] === false, $r['data']['worksheet'] ?? $r);

echo "== Phiếu ôn tập ==\n";
$ansOT = ['q1' => 'Graphit', 'q2' => 'Pyrit'];
$r = call('POST', ['action' => 'khtdSaveDraft', 'token' => $rv, 'worksheetId' => $ids[11], 'answers' => $ansOT]);
check('ở nhà: lưu nháp phiếu ôn tập 11', ok($r), $r);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $rv, 'worksheetId' => $ids[11]]);
check('chưa nộp → chưa lộ đáp án', ok($r) && !isset($r['data']['onTapKey']) && !isset($r['data']['worksheet']['schema']['answer_key']), array_keys($r['data'] ?? []));
$r = call('POST', ['action' => 'khtdSubmit', 'token' => $rv, 'worksheetId' => $ids[11], 'answers' => $ansOT]);
check('ở nhà: nộp phiếu ôn tập 11 (CSDL thử không có Gemini)', ok($r), $r);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $rv, 'worksheetId' => $ids[11]]);
$key = $r['data']['onTapKey'] ?? [];
check('nộp xong → có đáp án tham khảo 10 câu', ok($r) && count($key) === 10 && str_starts_with($key[0]['answer'], 'Graphit'), $key[0] ?? $r);
check('phiếu ôn tập: làm lại được (không giới hạn)', ($r['data']['attempt']['canRetake'] ?? false) === true && $r['data']['attempt']['allowed'] === null, $r['data']['attempt'] ?? null);
$r = call('POST', ['action' => 'khtdRetake', 'token' => $rv, 'worksheetId' => $ids[11]]);
check('làm lại phiếu ôn tập → lần 2', ok($r) && (int) $r['data']['attemptNo'] === 2, $r);

echo "== Giảng viên: điểm ==\n";
$r = call('GET', ['action' => 'khtdExportCsv', 'token' => $gv, 'classId' => 'ONT_C1']);
$row = $r['data']['rows'][0] ?? [];
check('CSV: có Phieu1–6, không có cột Phieu11–15, có OnTap_SoLanNop', ok($r) && array_key_exists('Phieu1', $row) && array_key_exists('Phieu6', $row) && !array_key_exists('Phieu11', $row) && ($row['OnTap_SoLanNop'] ?? -1) >= 1, $row);

echo "== Thu hồi, đổi mã, hết hạn ==\n";
$r = call('POST', ['action' => 'khtdReviewCode', 'token' => $gv, 'classId' => 'ONT_C1', 'op' => 'create', 'days' => 7]);
$code2 = $r['data']['review']['code'] ?? '';
$r1 = call('POST', ['action' => 'khtdReviewLogin', 'mssv' => '99330001', 'code' => $code]);
$r2 = call('POST', ['action' => 'khtdReviewLogin', 'mssv' => '99330001', 'code' => $code2]);
check('tạo mã mới → mã cũ hết hiệu lực, mã mới dùng được', ($code2 === $code || !ok($r1)) && ok($r2), [$r1['message'] ?? '', $r2['message'] ?? '']);
$r = call('POST', ['action' => 'khtdReviewCode', 'token' => $gv, 'classId' => 'ONT_C1', 'op' => 'revoke']);
check('thu hồi → không còn mã', ok($r) && $r['data']['review'] === null, $r);
$r = call('POST', ['action' => 'khtdReviewLogin', 'mssv' => '99330001', 'code' => $code2]);
check('đã thu hồi → đăng nhập bị từ chối', !ok($r), $r);
log_audit('ONT_U1', 'LECTURER', 'khtdReviewCode', 'khtd_review', 'ONT_C1', ['code' => 'HETHAN', 'expiresAt' => '2020-01-01 23:59:59', 'ts' => microtime(true) + 1]);
$cur = khtd_review_code_current('ONT_C1');
$r = call('POST', ['action' => 'khtdReviewLogin', 'mssv' => '99330001', 'code' => 'HETHAN']);
check('mã quá hạn → không còn hiệu lực, đăng nhập bị từ chối', $cur && $cur['active'] === false && !ok($r), [$cur, $r['message'] ?? '']);

$cleanup($pdo);
echo "\nKết quả: $pass PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
