<?php
declare(strict_types=1);

/**
 * tools/smoke_liveroom.php — Kiểm thử phòng tương tác trang trình chiếu (docs/04 mục 36, api/lib/liveroom.php):
 * mở phòng (có/không gắn mã điểm danh), hai phòng song song KHÔNG lẫn dữ liệu, gửi quiz/nhóm/hỏi đáp, quiz trùng,
 * hỏi định kỳ theo mốc since, khoá trình chiếu sai, đổi bài, đóng phòng, và tải 3 lớp × 50 SV chung một IP.
 * CLI-ONLY, CHỈ chạy trên CSDL THỬ.
 *
 *   php tools/smoke_liveroom.php --config=../private/config.test.php
 *
 * Dữ liệu demo tiền tố LVT_ (MSSV 9940xxxx), dọn trước và sau khi chạy.
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

define('API_INPROCESS', true);
putenv('DIEMDANH_CONFIG=' . $configPath);
$_SERVER['REMOTE_ADDR'] = '10.99.0.1';
date_default_timezone_set('Asia/Ho_Chi_Minh');
ini_set('error_log', sys_get_temp_dir() . '/diemdanhsv-smoke-liveroom.log');
foreach (['response', 'config', 'db', 'audit', 'ratelimit', 'mailer', 'auth', 'gradeauth', 'roles', 'attendance', 'queries', 'admin', 'grading', 'khtd', 'khtd_stats', 'khtd_pdf', 'archive', 'liveroom', 'actions'] as $lib) {
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
    } catch (RuntimeException $e) {
        return ['status' => 'error', 'message' => $e->getMessage(), 'data' => null];
    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => get_class($e) . ': ' . $e->getMessage(), 'data' => null];
    }
}
function ok(array $r): bool { return ($r['status'] ?? '') === 'success'; }

$pdo = db();
$cleanup = static function (PDO $pdo): void {
    $pdo->exec("DELETE FROM live_rooms WHERE CreatedIp LIKE '10.99.%'");
    $pdo->exec("DELETE FROM enrollments WHERE EnrollmentID LIKE 'LVT\\_%'");
    $pdo->exec("DELETE FROM students WHERE StudentID LIKE 'LVT\\_%'");
    $pdo->exec("DELETE FROM attendance_keys WHERE KeyID LIKE 'LVT\\_%'");
    $pdo->exec("DELETE FROM sessions WHERE SessionID LIKE 'LVT\\_%'");
    $pdo->exec("DELETE FROM classes WHERE ClassID LIKE 'LVT\\_%'");
    $pdo->exec("DELETE FROM courses WHERE CourseID LIKE 'LVT\\_%'");
    $pdo->exec("DELETE FROM rate_limits WHERE ClientKey LIKE '10.99.%' OR Bucket LIKE 'live\\_%'");
};
$cleanup($pdo);
$ins = static function (string $table, array $row) use ($pdo): void {
    $cols = implode(',', array_keys($row)); $ph = ':' . implode(',:', array_keys($row));
    $pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($ph)")->execute($row);
};
$ins('courses', ['CourseID' => 'LVT_CO1', 'CourseCode' => 'LEC10001', 'CourseName' => 'Đá và khoáng vật (thử)']);
$ins('classes', ['ClassID' => 'LVT_C1', 'CourseID' => 'LVT_CO1', 'LecturerID' => 'LVT_U1', 'ClassCode' => 'LVT-LOP-A']);
$ins('sessions', ['SessionID' => 'LVT_SS1', 'ClassID' => 'LVT_C1', 'SessionNo' => 3]);
$attCode = 'Q' . substr(str_shuffle('ACDEFGHJKMNPRTUVWXY'), 0, 3);
$pdo->prepare("INSERT INTO attendance_keys (KeyID, SessionID, Code, StartTime, LateAfter, EndTime, Status)
               VALUES ('LVT_K1', 'LVT_SS1', :c, NOW(), NOW() + INTERVAL 5 MINUTE, NOW() + INTERVAL 30 MINUTE, 'OPEN')")->execute(['c' => $attCode]);
$ins('students', ['StudentID' => 'LVT_S1', 'MSSV' => '99400001', 'FullName' => 'SV Thử A']);
$ins('enrollments', ['EnrollmentID' => 'LVT_E1', 'StudentID' => 'LVT_S1', 'ClassID' => 'LVT_C1']);

echo "1. Mở phòng\n";
$a = call('POST', ['action' => 'roomOpen', 'lessonCode' => '4-1', 'attendanceCode' => strtolower($attCode)]);
check('mở phòng A gắn mã điểm danh', ok($a) && $a['data']['linked'] && str_contains($a['data']['label'], 'LVT-LOP-A'), $a);
$b = call('POST', ['action' => 'roomOpen', 'lessonCode' => '5-1']);
check('mở phòng B không gắn lớp', ok($b) && !$b['data']['linked'] && strlen($b['data']['code']) === 5, $b);
$A = $a['data'] ?? []; $B = $b['data'] ?? [];
check('mã A ≠ mã B', ($A['code'] ?? 1) !== ($B['code'] ?? 2));
check('hostKey 32 hex, CSDL chỉ lưu SHA-256', preg_match('/^[0-9a-f]{32}$/', $A['hostKey'] ?? '') === 1
    && (string) $pdo->query("SELECT HostKeyHash FROM live_rooms WHERE Code = '" . ($A['code'] ?? '') . "'")->fetchColumn() === hash('sha256', $A['hostKey'] ?? ''));
check('mã điểm danh sai bị từ chối', !ok(call('POST', ['action' => 'roomOpen', 'lessonCode' => '4-1', 'attendanceCode' => 'ZZZZ'])));
check('mã bài giảng sai bị từ chối', !ok(call('POST', ['action' => 'roomOpen', 'lessonCode' => '../x'])));
check('roomInfo nhận chữ thường', ok($r = call('GET', ['action' => 'roomInfo', 'code' => strtolower($A['code'])])) && $r['data']['lessonCode'] === '4-1', $r);
check('roomInfo mã sai', !ok(call('GET', ['action' => 'roomInfo', 'code' => 'ZZZZZ'])));

echo "2. Gửi câu trả lời\n";
$sub = static fn (array $p) => call('POST', ['action' => 'roomSubmit'] + $p);
$r = $sub(['code' => $A['code'], 'kind' => 'quiz', 'lessonCode' => '4-1', 'mssv' => '99400001', 'name' => 'SV Thử A', 'qIdx' => 0, 'choice' => 'B']);
check('A: quiz', ok($r) && !$r['data']['duplicate'], $r);
$r = $sub(['code' => $A['code'], 'kind' => 'quiz', 'lessonCode' => '4-1', 'mssv' => '99400001', 'name' => 'SV Thử A', 'qIdx' => 0, 'choice' => 'C']);
check('A: quiz trùng → giữ đáp án đầu', ok($r) && $r['data']['duplicate'], $r);
$r = $sub(['code' => $A['code'], 'kind' => 'group', 'lessonCode' => '4-1', 'mssv' => '99400999', 'name' => 'SV Ngoài', 'group' => 'Nhóm 2', 'text' => "Ý 1\nÝ 2"]);
check('A: bài nhóm', ok($r), $r);
$r = $sub(['code' => $B['code'], 'kind' => 'qa', 'lessonCode' => '5-1', 'mssv' => '99400002', 'name' => 'SV Thử B', 'text' => 'Câu hỏi lớp B']);
check('B: hỏi đáp', ok($r), $r);
$r = $sub(['code' => $B['code'], 'kind' => 'quiz', 'lessonCode' => '5-1', 'mssv' => '99400001', 'name' => 'SV Thử A', 'qIdx' => 0, 'choice' => 'A']);
check('B: cùng MSSV, câu 0 vẫn nhận (phòng khác)', ok($r) && !$r['data']['duplicate'], $r);
check('MSSV sai', !ok($sub(['code' => $A['code'], 'kind' => 'qa', 'mssv' => 'a b', 'name' => 'X Y', 'text' => 'hỏi'])));
check('thiếu họ tên', !ok($sub(['code' => $A['code'], 'kind' => 'qa', 'mssv' => '99400003', 'name' => '', 'text' => 'hỏi'])));
check('loại sai', !ok($sub(['code' => $A['code'], 'kind' => 'xyz', 'mssv' => '99400003', 'name' => 'X Y', 'text' => 'hỏi'])));
check('đáp án quiz sai dạng', !ok($sub(['code' => $A['code'], 'kind' => 'quiz', 'mssv' => '99400003', 'name' => 'X Y', 'qIdx' => -1, 'choice' => 'Z'])));

echo "3. Trang trình chiếu hỏi định kỳ — không lẫn phòng\n";
$pa = call('POST', ['action' => 'roomPoll', 'code' => $A['code'], 'hostKey' => $A['hostKey'], 'since' => 0]);
$pb = call('POST', ['action' => 'roomPoll', 'code' => $B['code'], 'hostKey' => $B['hostKey'], 'since' => 0]);
$ia = $pa['data']['items'] ?? []; $ib = $pb['data']['items'] ?? [];
check('A nhận đúng 2 bản ghi, đều bài 4-1', count($ia) === 2 && array_unique(array_column($ia, 'lesson')) === ['4-1'], $ia);
check('B nhận đúng 2 bản ghi, đều bài 5-1', count($ib) === 2 && array_unique(array_column($ib, 'lesson')) === ['5-1'], $ib);
check('không bản ghi nào xuất hiện ở cả hai phòng', !array_intersect(array_column($ia, 'id'), array_column($ib, 'id')));
check('cờ có trong danh sách lớp (A gắn lớp)', ($ia[0]['inRoster'] ?? null) === true && ($ia[1]['inRoster'] ?? null) === false, $ia);
check('B không gắn lớp → inRoster null', array_key_exists('inRoster', $ib[0] ?? []) && $ib[0]['inRoster'] === null, $ib);
check('since = last → rỗng', (call('POST', ['action' => 'roomPoll', 'code' => $A['code'], 'hostKey' => $A['hostKey'], 'since' => $pa['data']['last']])['data']['items'] ?? null) === []);
check('khoá của B không đọc được phòng A', !ok(call('POST', ['action' => 'roomPoll', 'code' => $A['code'], 'hostKey' => $B['hostKey']])));
check('roomPoll qua GET bị từ chối', !ok(call('GET', ['action' => 'roomPoll', 'code' => $A['code'], 'hostKey' => $A['hostKey']])));

echo "4. Tải: 3 phòng × 50 SV, chung một IP\n";
$rooms = [];
for ($i = 0; $i < 3; $i++) {
    $o = call('POST', ['action' => 'roomOpen', 'lessonCode' => '4-1']);
    $rooms[] = $o['data'] ?? [];
}
$sent = 0; $errs = [];
foreach ($rooms as $ri => $room) {
    for ($s = 0; $s < 50; $s++) {
        $mssv = sprintf('99410%d%02d', $ri, $s);
        for ($q = 0; $q < 6; $q++) {
            $r = $sub(['code' => $room['code'], 'kind' => 'quiz', 'lessonCode' => '4-1', 'mssv' => $mssv, 'name' => 'SV ' . $mssv, 'qIdx' => $q, 'choice' => 'ABCD'[($s + $q) % 4]]);
            ok($r) ? $sent++ : $errs[] = $r['message'];
        }
        $r = $sub(['code' => $room['code'], 'kind' => 'qa', 'lessonCode' => '4-1', 'mssv' => $mssv, 'name' => 'SV ' . $mssv, 'text' => 'Câu hỏi ' . $mssv]);
        ok($r) ? $sent++ : $errs[] = $r['message'];
    }
}
check('1050 lượt gửi từ 150 SV chung IP đều được nhận', $sent === 1050, array_slice(array_unique($errs), 0, 3));
foreach ($rooms as $ri => $room) {
    $all = []; $since = 0;
    do {
        $p = call('POST', ['action' => 'roomPoll', 'code' => $room['code'], 'hostKey' => $room['hostKey'], 'since' => $since]);
        $all = array_merge($all, $p['data']['items'] ?? []);
        $since = $p['data']['last'] ?? $since;
    } while (!empty($p['data']['more']));
    $own = array_filter($all, static fn ($it) => str_starts_with($it['mssv'], '99410' . $ri));
    check("phòng tải #" . ($ri + 1) . ": đủ 350 bản ghi, chỉ của phòng mình (phân trang)", count($all) === 350 && count($own) === 350, count($all));
}

echo "5. Đổi bài, đóng phòng\n";
$r = call('POST', ['action' => 'roomSetLesson', 'code' => $A['code'], 'hostKey' => $A['hostKey'], 'lessonCode' => '4-2']);
check('đổi bài 4-2', ok($r) && call('GET', ['action' => 'roomInfo', 'code' => $A['code']])['data']['lessonCode'] === '4-2', $r);
check('đổi bài sai khoá bị từ chối', !ok(call('POST', ['action' => 'roomSetLesson', 'code' => $A['code'], 'hostKey' => str_repeat('0', 32), 'lessonCode' => '4-3'])));
check('đóng phòng A', ok(call('POST', ['action' => 'roomClose', 'code' => $A['code'], 'hostKey' => $A['hostKey']])));
check('gửi vào phòng đã đóng bị từ chối', !ok($sub(['code' => $A['code'], 'kind' => 'qa', 'mssv' => '99400001', 'name' => 'SV Thử A', 'text' => 'hỏi'])));
check('phòng B vẫn mở', ok(call('GET', ['action' => 'roomInfo', 'code' => $B['code']])));
$pdo->exec("UPDATE live_rooms SET ExpiresAt = NOW() - INTERVAL 1 MINUTE WHERE Code = '" . $B['code'] . "' AND Status = 'OPEN'");
check('phòng quá hạn tự đóng', !ok(call('GET', ['action' => 'roomInfo', 'code' => $B['code']])));

$cleanup($pdo);
echo "\n$pass đạt, $fail lỗi\n";
exit($fail ? 1 : 0);
