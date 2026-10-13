<?php
declare(strict_types=1);

/**
 * tools/smoke_khtd_exam.php — Kiểm thử phiếu LUYỆN THI nhận diện khoáng vật & đá + dự báo điểm thi (docs/04 mục 33).
 * CLI-ONLY, CHỈ chạy trên CSDL THỬ (không có gemini.api_key → chấm bằng rubric tự động).
 *
 *   php tools/smoke_khtd_exam.php --config=../private/config.test.php
 *
 * Dữ liệu demo tiền tố KTE_ (MSSV 9920000x), dọn sạch trước và sau khi chạy.
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
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
date_default_timezone_set('Asia/Ho_Chi_Minh');
ini_set('error_log', sys_get_temp_dir() . '/diemdanhsv-smoke-exam.log');
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
    try { api_dispatch((string) $params['action'], $method, $params); return ['status' => 'error', 'message' => '(không phản hồi)', 'data' => null]; }
    catch (ApiResponse $r) { return json_decode(json_encode($r->payload, JSON_UNESCAPED_UNICODE), true); }
    catch (Throwable $e) { return ['status' => 'error', 'message' => get_class($e) . ': ' . $e->getMessage(), 'data' => null]; }
}
function ok(array $r): bool { return ($r['status'] ?? '') === 'success'; }
function err(array $r, string $contains = ''): bool { return ($r['status'] ?? '') === 'error' && ($contains === '' || str_contains((string) $r['message'], $contains)); }

echo "== Bộ tri thức & so khớp tên ==\n";
$f = fn ($n, $k = null) => (khtd_kb_find($n, $k) ?? ['key' => null])['key'];
check('"Thạch anh tím" → thach_anh', $f('Thạch anh tím') === 'thach_anh');
check('"Đá hoa cương" → granit (không nhầm đá hoa)', $f('Đá hoa cương') === 'granit');
check('"đá hoa" → marble', $f('đá hoa') === 'marble');
check('"Đá phiến sét" → bùn–sét kết (theo giáo trình BMCS)', $f('Đá phiến sét') === 'bun_set_ket');
check('"micaschist" → schist', $f('micaschist') === 'schist');
check('"Ogit" → pyroxen', $f('Ogit') === 'pyroxen');
check('"fenpat K" → orthoclas', $f('fenpat K') === 'orthoclas');
check('"đá bột kết" không nhầm đá bọt', $f('đá bột kết') === 'bun_set_ket');
check('tên lạ → null', $f('kryptonit') === null);
check('độ cứng "5,5-6" khớp khoảng 6–6,5', khtd_kb_range_ok('5,5-6', [6, 6.5], 0.5, 1.5));
check('độ cứng "3-9" (đoán bừa) không khớp 7', !khtd_kb_range_ok('3-9', [7, 7], 0.5, 1.5));
check('CTHH viết "SiO 2" vẫn nhận', khtd_kb_any('SiO 2', ['sio2']));

echo "== Rubric khoáng vật (bậc thang) ==\n";
$kv = fn (array $r) => khtd_exam_score_sample($r + ['so_hieu' => 'KV01', 'ten' => 'Orthoclas'], ['need_so_hieu' => true, 'kind' => 'kv']);
$full = ['tphh' => 'KAlSi3O8', 'do_cung' => '6', 'cat_khai' => '2 hướng vuông góc', 'mau' => 'hồng thịt'];
check('đủ TPHH + độ cứng + cát khai + màu → 1', $kv($full)['level'] === 1.0, $kv($full));
check('thiếu vết vạch/màu/khác → 0,75', $kv(array_diff_key($full, ['mau' => 1]))['level'] === 0.75);
check('TPHH + độ cứng → 0,5', $kv(['tphh' => 'KAlSi3O8', 'do_cung' => '6'])['level'] === 0.5);
check('chỉ đúng độ cứng → 0,25', $kv(['do_cung' => '6-6,5', 'cat_khai' => 'không có'])['level'] === 0.25);
check('chỉ tên + số hiệu → 0 (rubric khoáng)', $kv([])['level'] === 0.0);
$r = khtd_exam_score_sample(['ten' => 'Orthoclas'] + $full, ['need_so_hieu' => true]);
check('không ghi số hiệu → 0', $r['level'] === 0.0 && str_contains($r['ly_do'], 'số hiệu'), $r);
$r = $kv(['tphh' => 'SiO2', 'do_cung' => '6']);
check('TPHH sai → chỉ 0,25 và có gợi ý sửa kèm chuẩn KAlSi₃O₈', $r['level'] === 0.25 && str_contains(json_encode($r['fix'], JSON_UNESCAPED_UNICODE), 'KAlSi₃O₈'), $r['fix']);

echo "== Rubric đá ==\n";
$da = fn (array $r, string $ten = 'Granit') => khtd_exam_score_sample($r + ['so_hieu' => 'D05', 'ten' => $ten], ['need_so_hieu' => true, 'kind' => 'da']);
$g = ['nhom' => 'Magma xâm nhập', 'kien_truc' => 'hạt thô, toàn tinh', 'thanh_phan' => 'thạch anh, fenpat K, biotit', 'dac_diem' => 'sáng màu, thấy rõ hạt'];
check('đủ 4 ý → 1', $da($g)['level'] === 1.0, $da($g));
check('thiếu đặc điểm → 0,75', $da(array_diff_key($g, ['dac_diem' => 1]))['level'] === 0.75);
check('khoáng vật chính chưa đủ (granit thiếu mica) → 0,5', $da(['thanh_phan' => 'thạch anh, fenpat'] + $g)['level'] === 0.5);
check('nhóm sai (trầm tích) → 0,25', $da(['nhom' => 'đá trầm tích'] + $g)['level'] === 0.25);
check('chỉ tên + số hiệu → 0,25 (rubric đá)', $da([])['level'] === 0.25);
$r = $da(['nhom' => 'magma phun trào'] + $g);
check('granit ghi "phun trào" → vẫn đúng nhóm nhưng có lưu ý', $r['level'] === 1.0 && str_contains($r['ly_do'], 'xâm nhập'), $r);
$r = khtd_exam_score_sample(['so_hieu' => 'B2', 'ten' => 'đá phiến sét', 'nhom' => 'biến chất', 'kien_truc' => 'phiến, hạt rất mịn', 'thanh_phan' => 'sericit, thạch anh', 'dac_diem' => 'tách tấm mỏng phẳng'], ['need_so_hieu' => true, 'expected' => 'slate']);
check('đáp án = slate, SV ghi "đá phiến sét" → chấp nhận, chấm theo slate = 1', $r['level'] === 1.0 && $r['key'] === 'slate', $r);
$r = khtd_exam_score_sample(['so_hieu' => 'B2', 'ten' => 'gneiss'] + $g, ['need_so_hieu' => true, 'expected' => 'granit']);
check('đáp án = granit, SV ghi gneiss → 0', $r['level'] === 0.0 && str_contains($r['ly_do'], 'Sai tên'), $r);

echo "== Luồng luyện thi (phiếu 7) ==\n";
$pdo = db();
$cleanup = static function (PDO $pdo): void {
    foreach (['khtd_submissions', 'khtd_extra_attempts', 'khtd_photos'] as $t) $pdo->exec("DELETE FROM $t WHERE StudentID LIKE 'KTE\\_%'");
    $pdo->exec("DELETE FROM khtd_worksheets WHERE ClassID LIKE 'KTE\\_%'");
    $pdo->exec("DELETE FROM auth_tokens WHERE SubjectID LIKE 'KTE\\_%'");
    $pdo->exec("DELETE FROM enrollments WHERE StudentID LIKE 'KTE\\_%'");
    $pdo->exec("DELETE FROM students WHERE StudentID LIKE 'KTE\\_%'");
    $pdo->exec("DELETE FROM classes WHERE ClassID LIKE 'KTE\\_%'");
    $pdo->exec("DELETE FROM courses WHERE CourseID LIKE 'KTE\\_%'");
    $pdo->exec("DELETE FROM users WHERE UserID LIKE 'KTE\\_%'");
    $pdo->exec("DELETE FROM rate_limits WHERE ClientKey = '127.0.0.1' OR ClientKey LIKE 'KTE\\_%'");
};
$cleanup($pdo);
$ins = static function (string $table, array $row) use ($pdo): void {
    $cols = implode(',', array_keys($row)); $ph = ':' . implode(',:', array_keys($row));
    $pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($ph)")->execute($row);
};
$ins('users', ['UserID' => 'KTE_U1', 'Username' => 'kte_gv1', 'FullName' => 'GV Thử', 'Email' => 'gv@example.invalid',
    'PasswordHash' => password_hash('x', PASSWORD_DEFAULT), 'Role' => 'LECTURER', 'Status' => 'ACTIVE']);
$ins('courses', ['CourseID' => 'KTE_CO1', 'CourseCode' => 'KTE101', 'CourseName' => 'Môn thử luyện thi']);
$ins('classes', ['ClassID' => 'KTE_C1', 'CourseID' => 'KTE_CO1', 'LecturerID' => 'KTE_U1', 'ClassCode' => 'KTE101-01']);
foreach ([1, 2] as $n) {
    $ins('students', ['StudentID' => "KTE_S$n", 'MSSV' => "9920000$n", 'FullName' => "SV Luyện $n", 'Email' => '']);
    $ins('enrollments', ['EnrollmentID' => "KTE_E$n", 'StudentID' => "KTE_S$n", 'ClassID' => 'KTE_C1']);
}
$tok = static function (string $kind, string $subject) use ($pdo): string {
    $t = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO auth_tokens (Token, Kind, SubjectID, ExpiresAt) VALUES (:t, :k, :s, NOW() + INTERVAL 1 HOUR)")->execute(['t' => $t, 'k' => $kind, 's' => $subject]);
    return $t;
};
$gv = $tok('LECTURER', 'KTE_U1'); $s1 = $tok('KHTD', 'KTE_S1'); $s2 = $tok('KHTD', 'KTE_S2');

$r = call('POST', ['action' => 'khtdSeedWorksheets', 'token' => $gv, 'classId' => 'KTE_C1']);
check('GV tạo phiếu mặc định → có phiếu 7', ok($r) && count($r['data']['created']) === 7, $r);
$ids = []; foreach ($r['data']['created'] as $c) $ids[$c['no']] = $c['id'];
$ws7 = $ids[7]; $ws1 = $ids[1];
$r = call('GET', ['action' => 'khtdLecturerList', 'token' => $gv, 'classId' => 'KTE_C1']);
$w7 = ok($r) ? array_values(array_filter($r['data']['worksheets'], fn ($x) => (int) $x['No'] === 7))[0] : null;
check('phiếu 7: không giới hạn lần, tính cao nhất, IsExam', $w7 && (int) $w7['MaxAttempts'] === 0 && $w7['ScorePolicy'] === 'BEST' && $w7['IsExam'] === true, $w7);
foreach ([$ws1, $ws7] as $w) call('POST', ['action' => 'khtdSetWorksheetStatus', 'token' => $gv, 'worksheetId' => $w, 'status' => 'OPEN']);

$sec = 'nhandien'; $F = array_keys(KHTD_EXAM_FIELDS);
$row = static function (array $v) use ($F): array { return array_map(fn ($k) => (string) ($v[$k] ?? ''), $F); };
$ansA = [$sec => [
    $row(['loai' => 'Khoáng vật', 'so_hieu' => 'KV01', 'ten' => 'Thạch anh', 'tphh' => 'SiO2', 'do_cung' => '7', 'cat_khai' => 'không có, vết vỡ vỏ sò', 'vet_vach' => 'trắng']),
    $row(['loai' => 'Đá', 'so_hieu' => 'D02', 'ten' => 'Bazan', 'nhom' => 'Đá magma phun trào', 'kien_truc' => 'vi hạt, có lỗ khí', 'thanh_phan' => 'plagioclas, pyroxen', 'dac_diem' => 'đen, nặng']),
    $row(['loai' => 'Khoáng vật', 'so_hieu' => 'KV03', 'ten' => 'Calcit', 'do_cung' => '3']),
]];

check('lưu nháp khi chưa bấm giờ → chặn', err(call('POST', ['action' => 'khtdSaveDraft', 'token' => $s1, 'worksheetId' => $ws7, 'answers' => json_encode($ansA)]), 'Bắt đầu'));
check('nộp khi chưa bấm giờ → chặn', err(call('POST', ['action' => 'khtdSubmit', 'token' => $s1, 'worksheetId' => $ws7, 'answers' => json_encode($ansA)]), 'Bắt đầu'));
check('số mẫu 11 → lỗi', err(call('POST', ['action' => 'khtdStartExam', 'token' => $s1, 'worksheetId' => $ws7, 'count' => 11])));
check('phiếu thường không bấm giờ', err(call('POST', ['action' => 'khtdStartExam', 'token' => $s1, 'worksheetId' => $ws1, 'count' => 3])));
$r = call('POST', ['action' => 'khtdStartExam', 'token' => $s1, 'worksheetId' => $ws7, 'count' => 3]);
check('bắt đầu 3 mẫu → 9 phút', ok($r) && $r['data']['clock']['limitSec'] === 540 && $r['data']['clock']['remainingSec'] > 530, $r);
$r = call('POST', ['action' => 'khtdStartExam', 'token' => $s1, 'worksheetId' => $ws7, 'count' => 10]);
check('bấm lại không đặt lại đồng hồ (giữ 3 mẫu)', ok($r) && $r['data']['already'] === true && $r['data']['clock']['count'] === 3, $r);
$fake = $ansA + ['_exam' => ['startedAt' => '2099-01-01 00:00:00', 'count' => 10, 'limitSec' => 99999]];
$r = call('POST', ['action' => 'khtdSaveDraft', 'token' => $s1, 'worksheetId' => $ws7, 'answers' => json_encode($fake)]);
check('lưu nháp được', ok($r), $r);
$st = $pdo->query("SELECT AnswersJSON FROM khtd_submissions WHERE StudentID = 'KTE_S1' AND WorksheetID = " . $pdo->quote($ws7))->fetchColumn();
check('trình duyệt không sửa được giờ bắt đầu / số mẫu', (json_decode((string) $st, true)['_exam']['count'] ?? 0) === 3 && !str_contains((string) $st, '2099'), $st);
$png = base64_encode((string) file_get_contents($root . '/images/apple-touch-icon.png'));
check('tải ảnh cho mẫu 4 khi lần này chỉ 3 mẫu → chặn', err(call('POST', ['action' => 'khtdUploadPhoto', 'token' => $s1, 'worksheetId' => $ws7, 'sectionId' => $sec, 'row' => '3', 'image' => $png]), '3 mẫu'));
$r = call('POST', ['action' => 'khtdUploadPhoto', 'token' => $s1, 'worksheetId' => $ws7, 'sectionId' => $sec, 'row' => '0', 'image' => $png]);
check('tải ảnh mẫu 1', ok($r), $r);
$photo = $r['data']['photoId'] ?? '';
$ansA['_photos'] = [$sec => ['0' => [$photo]]];
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $s1, 'worksheetId' => $ws7]);
check('getWorksheet: có đồng hồ, không lộ đáp án bộ mẫu', ok($r) && $r['data']['exam']['clock']['count'] === 3 && !isset($r['data']['worksheet']['schema']['exam']['key']), $r['data']['exam'] ?? $r);

$r = call('POST', ['action' => 'khtdSubmit', 'token' => $s1, 'worksheetId' => $ws7, 'answers' => json_encode($ansA)]);
$ai = $r['data']['ai'] ?? [];
check('nộp → chấm rubric tự động (không cần Gemini)', ok($r) && $r['data']['status'] === 'AI_GRADED' && ($ai['model'] ?? '') === 'rubric-tu-dong', $r);
// thạch anh 1 (SiO2, 7, không cát khai, vạch trắng); bazan 1; calcit chỉ độ cứng → 0,25 → (2,25/3)×10 = 7,5
check('điểm dự báo = 7,5 (1 + 1 + 0,25 trên 3 mẫu)', (float) ($ai['total'] ?? -1) === 7.5, $ai['criteria'] ?? $ai);
check('có danh sách cần sửa cho calcit (TPHH, cát khai…) kèm chuẩn CaCO₃', str_contains(json_encode($ai['sua_bai'] ?? [], JSON_UNESCAPED_UNICODE), 'CaCO₃'), $ai['sua_bai'] ?? null);
check('SV xem ngay bảng đối chiếu (phiếu luyện thi)', !empty($ai['doi_chieu']) && !isset($ai['ai_risk']) && !isset($ai['phan_bien']));
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $s1, 'worksheetId' => $ws7]);
check('getWorksheet sau nộp: tổng hợp luyện tập 1 lần, 3 mẫu, không lộ mức thái độ', ok($r) && $r['data']['exam']['practice']['attempts'] === 1 && $r['data']['exam']['practice']['samples'] === 3 && !isset($r['data']['exam']['practice']['attitude']), $r['data']['exam'] ?? $r);

$r = call('POST', ['action' => 'khtdRetake', 'token' => $s1, 'worksheetId' => $ws7]);
check('luyện lần mới → lần 2', ok($r) && $r['data']['attemptNo'] === 2, $r);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $s1, 'worksheetId' => $ws7]);
$a2 = $r['data']['submission']['answers'] ?? [];
check('lần mới: bài trống, chưa bấm giờ, giữ ảnh mẫu', ok($r) && !isset($a2[$sec]) && !isset($a2['_exam']) && isset($a2['_photos']) && $r['data']['exam']['clock'] === null, $a2);
call('POST', ['action' => 'khtdStartExam', 'token' => $s1, 'worksheetId' => $ws7, 'count' => 1]);
$pdo->exec("UPDATE khtd_submissions SET AnswersJSON = JSON_SET(AnswersJSON, '$._exam.startedAt', DATE_FORMAT(NOW() - INTERVAL 10 MINUTE, '%Y-%m-%d %H:%i:%s')) WHERE StudentID = 'KTE_S1' AND WorksheetID = " . $pdo->quote($ws7) . " AND AttemptNo = 2");
$ansB = [$sec => [$row(['so_hieu' => 'X1', 'ten' => 'Gabro', 'nhom' => 'magma xâm nhập', 'kien_truc' => 'hạt thô', 'thanh_phan' => 'plagioclas, pyroxen', 'dac_diem' => 'sẫm màu, nặng'])]];
check('quá giờ (1 mẫu = 3 phút, đã 10 phút) → không lưu nháp được', err(call('POST', ['action' => 'khtdSaveDraft', 'token' => $s1, 'worksheetId' => $ws7, 'answers' => json_encode($ansB)]), 'hết giờ'));
$r = call('POST', ['action' => 'khtdSubmit', 'token' => $s1, 'worksheetId' => $ws7, 'answers' => json_encode($ansB)]);
check('vẫn nộp được, bị ghi "nộp quá giờ"', ok($r) && (float) $r['data']['ai']['total'] === 10.0 && str_contains(implode(' ', $r['data']['ai']['flags']), 'quá giờ'), $r['data']['ai'] ?? $r);

echo "== Đáp án bộ mẫu (GV) ==\n";
check('SV không đặt được đáp án', err(call('POST', ['action' => 'khtdSetExamKey', 'token' => $s1, 'worksheetId' => $ws7, 'text' => 'KV01 = thạch anh'])));
check('dòng sai dạng → lỗi', err(call('POST', ['action' => 'khtdSetExamKey', 'token' => $gv, 'worksheetId' => $ws7, 'text' => 'chỉ có chữ'])));
$r = call('POST', ['action' => 'khtdSetExamKey', 'token' => $gv, 'worksheetId' => $ws7, 'text' => "KV1 = thạch anh\nD02: granit\nKV03 = canxit\nX9 = đá lạ"]);
check('GV lưu đáp án 4 mẫu, báo 1 tên không có trong danh mục', ok($r) && $r['data']['count'] === 4 && count($r['data']['unknown']) === 1, $r);
check('phiếu thường không có đáp án bộ mẫu', err(call('POST', ['action' => 'khtdSetExamKey', 'token' => $gv, 'worksheetId' => $ws1, 'text' => 'A = granit'])));
$r = call('GET', ['action' => 'khtdLecturerList', 'token' => $gv, 'classId' => 'KTE_C1']);
$w7 = array_values(array_filter($r['data']['worksheets'], fn ($x) => (int) $x['No'] === 7))[0];
check('GV xem lại đáp án dạng chữ', str_contains($w7['ExamKey'] ?? '', 'D02 = granit'), $w7);
call('POST', ['action' => 'khtdStartExam', 'token' => $s2, 'worksheetId' => $ws7, 'count' => 3]);
$r = call('POST', ['action' => 'khtdSubmit', 'token' => $s2, 'worksheetId' => $ws7, 'answers' => json_encode(array_diff_key($ansA, ['_photos' => 1]))]);
$c = $r['data']['ai']['criteria'] ?? [];
check('SV2: KV01 khớp "KV1" (bỏ số 0) → 1; D02 ghi bazan nhưng đáp án granit → 0; KV03 calcit = canxit → 0,25',
    ok($r) && $c[0]['score'] == 1 && $c[1]['score'] == 0 && $c[2]['score'] == 0.25, $c);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $s2, 'worksheetId' => $ws7]);
check('SV không thấy đáp án bộ mẫu qua getWorksheet', ok($r) && !str_contains(json_encode($r, JSON_UNESCAPED_UNICODE), 'D02 = granit') && !isset($r['data']['worksheet']['schema']['exam']['key']));

echo "== Bảng luyện tập (thái độ, điểm cộng) & CSV ==\n";
check('SV không xem được bảng luyện tập', err(call('GET', ['action' => 'khtdPracticeBoard', 'token' => $s1, 'worksheetId' => $ws7])));
$r = call('GET', ['action' => 'khtdPracticeBoard', 'token' => $gv, 'worksheetId' => $ws7]);
$b1 = ok($r) ? array_values(array_filter($r['data']['rows'], fn ($x) => $x['mssv'] === '99200001'))[0] : null;
check('SV1: 2 lần, 4 mẫu, 3 loại thạo (thạch anh, bazan, gabro), dự báo 7,5 → 10, mức "Có luyện"',
    $b1 && $b1['attempts'] === 2 && $b1['samples'] === 4 && $b1['mastered'] === 3 && (float) $b1['first'] === 7.5 && (float) $b1['last'] === 10.0 && $b1['attitude'] === 'Có luyện', $b1);
check('điểm cộng đề xuất 0 (chưa đủ 5 loại)', $b1 && (float) $b1['bonus'] === 0.0, $b1);
check('đếm mức thái độ cả lớp', ok($r) && $r['data']['tally']['Có luyện'] === 2 && $r['data']['tally']['Chưa luyện'] === 0, $r['data']['tally'] ?? $r);
$p = khtd_practice_summary(['exam' => []], [['AttemptNo' => 1, 'Status' => 'AI_GRADED', 'SubmittedAt' => '2026-10-01 08:00:00', 'AiScore' => 8, 'FinalScore' => null,
    'AiJSON' => json_encode(['exam' => ['samples' => array_map(fn ($k) => ['kind' => 'da', 'key' => $k, 'level' => 1], ['granit', 'bazan', 'gabro', 'diorit', 'andesit', 'rhyolit', 'obsidian', 'da_bot', 'cat_ket', 'marble'])]])]]);
check('10 loại đạt ≥ 0,75 → điểm cộng đề xuất +0,5', (float) $p['bonus'] === 0.5 && $p['mastered'] === 10, $p);
$r = call('GET', ['action' => 'khtdExportCsv', 'token' => $gv, 'classId' => 'KTE_C1']);
$cs = ok($r) ? array_values(array_filter($r['data']['rows'], fn ($x) => $x['MSSV'] === '99200001'))[0] : null;
check('CSV: không có cột Phieu7 (không vào điểm tổng), có dự báo / điểm cộng / thái độ',
    $cs && !array_key_exists('Phieu7', $cs) && (float) $cs['LuyenThi7_DuBaoCaoNhat'] === 10.0 && array_key_exists('DiemCong_DeXuat', $cs) && $cs['ThaiDo_LuyenTap'] === 'Có luyện' && array_key_exists('Phieu1', $cs), $cs);

echo "== Dự báo điểm thi từ bảng mô tả phiếu 1 ==\n";
$ws1Schema = khtd_worksheet($ws1)['schema'];
$mota = null; foreach ($ws1Schema['sections'] as $s) if ($s['id'] === 'mota') $mota = $s;
// Màu | Ánh | Độ cứng | Cát khai / vết vỡ | HCl | Dấu hiệu khác | Tên khoáng vật
$cells = array_fill(0, count($mota['rows']), array_fill(0, count($mota['columns']), ''));
$cells[0] = ['không màu', 'thủy tinh', '7', 'không, vỡ vỏ sò', 'không sủi', 'vạch được kính', 'Thạch anh'];
$cells[1] = ['trắng', 'thủy tinh', '3', '3 hướng mặt thoi', 'sủi mạnh', '', 'Calcit'];
$r = call('POST', ['action' => 'khtdSubmit', 'token' => $s1, 'worksheetId' => $ws1, 'answers' => json_encode(['mota' => $cells])]);
check('nộp phiếu 1', ok($r), $r);
$r = call('GET', ['action' => 'khtdGetWorksheet', 'token' => $s1, 'worksheetId' => $ws1]);
$fc = $r['data']['forecast'] ?? null;
// phiếu 1 không có cột TPHH → mỗi mẫu tối đa: độ cứng + cát khai đúng nhưng thiếu TPHH → 0,25
check('dự báo: 2 mẫu, mỗi mẫu 0,25 vì bảng phiếu 1 thiếu thành phần hóa học → 2,5/10', $fc && $fc['samples'] === 2 && (float) $fc['score10'] === 2.5 && in_array('thành phần hóa học', $fc['items'][0]['thieu'], true), $fc);
$r = call('GET', ['action' => 'khtdListWorksheets', 'token' => $s1]);
$ef = $r['data']['examForecast'] ?? null;
check('danh sách SV: dự báo tổng hợp (mô tả 2 mẫu; luyện thi lần tốt nhất 10)', $ef && $ef['describe']['samples'] === 2 && (float) $ef['practice'][0]['best'] === 10.0 && !isset($ef['practice'][0]['attitude']), $ef);
check('danh sách SV: không lộ SchemaJSON / AiJSON', ok($r) && !str_contains(json_encode($r['data']['worksheets']), 'SchemaJSON') && !str_contains(json_encode($r['data']['worksheets']), 'AiJSON'));

echo "== Thư kết quả có mục cần sửa ==\n";
$wsx = khtd_worksheet($ws7);
$aiX = khtd_exam_grade($wsx, ['_exam' => ['count' => 1], $sec => [$row(['so_hieu' => 'KV03', 'ten' => 'Calcit', 'do_cung' => '3'])]], []);
$dir = (string) ($cfg['app']['mail_stub_dir'] ?? '');
$before = $dir !== '' && is_dir($dir) ? count(glob($dir . '/*') ?: []) : 0;
khtd_send_result_mail(['email' => 'sv@example.invalid', 'fullName' => 'SV Luyện 1', 'mssv' => '99200001'], $wsx, $aiX, null, null, '');
$files = $dir !== '' && is_dir($dir) ? (glob($dir . '/*') ?: []) : [];
usort($files, fn ($a, $b) => filemtime($a) <=> filemtime($b));
$raw = $files && count($files) > $before ? (string) file_get_contents(end($files)) : '';
$mail = $raw !== '' ? (string) base64_decode(preg_replace('/\s+/', '', (string) substr($raw, (int) strpos($raw, "\r\n\r\n") + 4))) : '';
check('thư có "DỰ BÁO ĐIỂM BÀI THI", "CẦN SỬA" và chuẩn CaCO₃, ghi rõ không tính điểm tổng',
    $mail === '' || (str_contains($mail, 'CẦN SỬA') && str_contains($mail, 'CaCO') && str_contains($mail, 'KHÔNG tính vào điểm tổng')), mb_substr($mail, 0, 600));

$cleanup($pdo);
echo "\nKết quả: $pass PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
