<?php
declare(strict_types=1);

/**
 * tools/smoke_test.php — Smoke test 13/13 action của API PHP trên CSDL THỬ
 * với dữ liệu DEMO giả lập (nợ GĐ5 của PLAN: "smoke test 13/13 trên seed").
 *
 * CLI-ONLY (rule 3). KHÔNG BAO GIỜ chạy vào CSDL thật đang dùng:
 *   - Bắt buộc --config=<file cấu hình CSDL THỬ>, và file đó KHÔNG được là
 *     ../private/config.php (file của CSDL thật) — script tự từ chối.
 *   - Từ chối chạy nếu CSDL đích có BẤT KỲ dòng users/courses/classes/
 *     students nào không phải dữ liệu demo của script này (ID 'SMOKE_…').
 *   - Mọi dữ liệu demo đều giả (MSSV 9900000x, email @example.invalid) —
 *     rule 2, không dùng dữ liệu sinh viên thật.
 *
 * Chuẩn bị (THẦY làm một lần trên hPanel):
 *   1. Tạo CSDL MariaDB thứ hai, ví dụ u464424582_ddtest (+ user riêng).
 *   2. Copy db/config.sample.php thành ../private/config.test.php, điền
 *      thông tin CSDL THỬ ở mục 'db' (mục 'app' giữ như mẫu).
 *
 * Chạy qua SSH (từ thư mục public_html):
 *   php tools/smoke_test.php --config=../private/config.test.php --init-schema
 *   php tools/smoke_test.php --config=../private/config.test.php            # các lần sau
 *   php tools/smoke_test.php --config=... --keep-data                       # giữ dữ liệu demo để xem tay
 *
 * Cách chạy: script bật máy chủ dev `php -S 127.0.0.1:<cổng>` (chỉ nghe
 * localhost, tự tắt khi xong) với biến môi trường DIEMDANH_CONFIG trỏ tới
 * file cấu hình THỬ (api/lib/config.php chỉ đọc biến này ở SAPI cli/
 * cli-server), rồi gọi API bằng HTTP thật — GET query string, POST body JSON
 * với Content-Type text/plain;charset=utf-8 đúng như js/services/APIService.js.
 *
 * Kết quả: mỗi kiểm tra in PASS/FAIL; mã thoát 0 khi tất cả PASS.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Chỉ chạy được từ CLI.');
}
if (!function_exists('curl_multi_init')) {
    fwrite(STDERR, "Cần extension curl của PHP (php -m | grep curl).\n");
    exit(2);
}

const SMOKE_PREFIX = 'SMOKE_';

/* ------------------------------------------------------------------ */
/*  Tham số + kiểm an toàn                                             */
/* ------------------------------------------------------------------ */

$opts = ['config' => null, 'init_schema' => false, 'keep_data' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--config=')) { $opts['config'] = substr($arg, 9); continue; }
    if ($arg === '--init-schema') { $opts['init_schema'] = true; continue; }
    if ($arg === '--keep-data') { $opts['keep_data'] = true; continue; }
    fwrite(STDERR, "Tham số không rõ: $arg\n");
    exit(2);
}

$root = dirname(__DIR__);
if (!$opts['config'] || !is_file($opts['config'])) {
    fwrite(STDERR, "Thiếu hoặc không thấy --config=<file cấu hình CSDL THỬ>. Xem hướng dẫn đầu file.\n");
    exit(2);
}
$configPath = realpath($opts['config']);
$prodConfig = realpath($root . '/../private/config.php');
if ($prodConfig !== false && $configPath === $prodConfig) {
    fwrite(STDERR, "TỪ CHỐI: --config đang trỏ vào file cấu hình CSDL THẬT. Dùng file cấu hình của CSDL THỬ.\n");
    exit(2);
}

$cfg = require $configPath;
if (!is_array($cfg) || !is_array($cfg['db'] ?? null)) {
    fwrite(STDERR, "File cấu hình không hợp lệ (cần return mảng có mục 'db').\n");
    exit(2);
}
$appCfg = $cfg['app'] ?? [];

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['db']['host'] ?? 'localhost', $cfg['db']['name'] ?? '', $cfg['db']['charset'] ?? 'utf8mb4'),
    (string) ($cfg['db']['user'] ?? ''),
    (string) ($cfg['db']['pass'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

if ($opts['init_schema']) {
    echo "Nạp db/schema.sql + db/migrations/*.sql vào CSDL thử…\n";
    $files = array_merge([$root . '/db/schema.sql'], glob($root . '/db/migrations/*.sql') ?: []);
    foreach ($files as $f) {
        $pdo->exec((string) file_get_contents($f));
        echo "  - " . basename($f) . "\n";
    }
    $pdo = null; // đóng kết nối multi-statement, mở lại sạch
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['db']['host'] ?? 'localhost', $cfg['db']['name'] ?? '', $cfg['db']['charset'] ?? 'utf8mb4'),
        (string) ($cfg['db']['user'] ?? ''),
        (string) ($cfg['db']['pass'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

$tables = ['users', 'courses', 'classes', 'students', 'enrollments', 'sessions', 'attendance',
    'attendance_keys', 'grade_columns', 'grades', 'complaints', 'audit_log', 'auth_tokens', 'grade_codes'];
foreach ($tables as $t) {
    try {
        $pdo->query("SELECT 1 FROM `$t` LIMIT 1");
    } catch (PDOException $e) {
        fwrite(STDERR, "Thiếu bảng `$t` trong CSDL thử — chạy lại với --init-schema.\n");
        exit(2);
    }
}

$guard = [
    'users' => 'UserID', 'courses' => 'CourseID', 'classes' => 'ClassID', 'students' => 'StudentID',
];
foreach ($guard as $t => $pk) {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM `$t` WHERE `$pk` NOT LIKE 'SMOKE\\_%'")->fetchColumn();
    if ($n > 0) {
        fwrite(STDERR, "TỪ CHỐI: bảng `$t` có $n dòng KHÔNG phải dữ liệu demo — đây không phải CSDL thử trống.\n");
        exit(2);
    }
}

/* ------------------------------------------------------------------ */
/*  Tiện ích kiểm tra                                                   */
/* ------------------------------------------------------------------ */

$results = ['pass' => 0, 'fail' => 0];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[$ok ? 'pass' : 'fail']++;
    echo ($ok ? '  PASS ' : '  FAIL ') . $name . ($ok || $detail === '' ? '' : "  — $detail") . "\n";
}

function section(string $title): void
{
    echo "\n== $title ==\n";
}

/** Phản hồi rút gọn để in khi FAIL. */
function brief($resp): string
{
    return mb_substr(json_encode($resp, JSON_UNESCAPED_UNICODE), 0, 300);
}

function is_envelope($r): bool
{
    return is_array($r) && array_key_exists('status', $r) && array_key_exists('message', $r) && array_key_exists('data', $r);
}

function ok($r): bool
{
    return is_envelope($r) && $r['status'] === 'success';
}

function err($r, ?string $contains = null): bool
{
    return is_envelope($r) && $r['status'] === 'error' && $r['data'] === null
        && ($contains === null || str_contains((string) $r['message'], $contains));
}

/* ------------------------------------------------------------------ */
/*  Máy chủ dev + client HTTP                                           */
/* ------------------------------------------------------------------ */

$port = 18000 + random_int(0, 999);
$base = "http://127.0.0.1:$port/api/index.php";
$env = [
    'DIEMDANH_CONFIG'        => $configPath,
    'PHP_CLI_SERVER_WORKERS' => '4', // để thử request song song thật (H1)
    'PATH'                   => (string) getenv('PATH'),
];
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    $root,
    $env
);
if (!is_resource($server)) {
    fwrite(STDERR, "Không bật được máy chủ dev php -S.\n");
    exit(2);
}
register_shutdown_function(static function () use ($server): void {
    proc_terminate($server);
});

function http_handle(string $method, array $params)
{
    global $base;
    $ch = curl_init();
    if ($method === 'GET') {
        curl_setopt($ch, CURLOPT_URL, $base . '?' . http_build_query($params));
    } else {
        curl_setopt($ch, CURLOPT_URL, $base);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/plain;charset=utf-8']);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    return $ch;
}

function decode_body($body)
{
    $j = json_decode((string) $body, true);
    return is_array($j) ? $j : ['__raw' => (string) $body];
}

function api(string $method, array $params)
{
    $ch = http_handle($method, $params);
    $body = curl_exec($ch);
    curl_close($ch);
    return decode_body($body);
}

/** Gửi nhiều request CÙNG LÚC (curl_multi) — dùng cho kiểm tra race (H1). */
function api_parallel(string $method, array $paramsList): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($paramsList as $i => $p) {
        $handles[$i] = http_handle($method, $p);
        curl_multi_add_handle($mh, $handles[$i]);
    }
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status === CURLM_OK);
    $out = [];
    foreach ($handles as $i => $ch) {
        $out[$i] = decode_body(curl_multi_getcontent($ch));
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    usleep(100000);
    $r = @api('GET', ['action' => 'ping']);
    $up = ok($r);
}
if (!$up) {
    fwrite(STDERR, "Máy chủ dev không phản hồi ping sau 5 giây.\n");
    exit(2);
}

/* ------------------------------------------------------------------ */
/*  Dọn + gieo dữ liệu demo                                             */
/* ------------------------------------------------------------------ */

function cleanup(PDO $pdo): void
{
    $like = "LIKE 'SMOKE\\_%'";
    $pdo->exec("DELETE FROM audit_log WHERE Actor $like OR TargetID $like");
    $pdo->exec("DELETE FROM auth_tokens WHERE SubjectID $like");
    $pdo->exec("DELETE FROM grade_codes WHERE StudentID $like");
    $pdo->exec("DELETE FROM grades WHERE StudentID $like");
    $pdo->exec("DELETE FROM grade_columns WHERE ClassID $like");
    $pdo->exec("DELETE FROM attendance WHERE SessionID $like");
    $pdo->exec("DELETE FROM attendance_keys WHERE SessionID $like");
    $pdo->exec("DELETE FROM complaints WHERE StudentID $like");
    $pdo->exec("DELETE FROM sessions WHERE ClassID $like");
    $pdo->exec("DELETE FROM enrollments WHERE ClassID $like");
    $pdo->exec("DELETE FROM classes WHERE ClassID $like");
    $pdo->exec("DELETE FROM students WHERE StudentID $like");
    $pdo->exec("DELETE FROM courses WHERE CourseID $like");
    $pdo->exec("DELETE FROM users WHERE UserID $like");
}

cleanup($pdo); // dọn tàn dư lần chạy trước (nếu có)

$PW = 'Demo@12345';
$SALT = 'smoke-salt';
$ins = static function (PDO $pdo, string $table, array $row): void {
    $cols = implode(', ', array_map(static fn ($c) => "`$c`", array_keys($row)));
    $ph = implode(', ', array_map(static fn ($c) => ":$c", array_keys($row)));
    $pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($ph)")->execute($row);
};

// Giảng viên 1: hash KIỂU CŨ sha256(salt|plain) — để kiểm rehash khi login.
$ins($pdo, 'users', ['UserID' => 'SMOKE_U1', 'Username' => 'smoke_gv1', 'FullName' => 'GV Demo Một',
    'Email' => 'gv1@example.invalid', 'PasswordHash' => hash('sha256', $SALT . '|' . $PW), 'Salt' => $SALT,
    'Role' => 'LECTURER', 'Status' => 'ACTIVE']);
// Giảng viên 2: hash mới password_hash().
$ins($pdo, 'users', ['UserID' => 'SMOKE_U2', 'Username' => 'smoke_gv2', 'FullName' => 'GV Demo Hai',
    'Email' => 'gv2@example.invalid', 'PasswordHash' => password_hash($PW, PASSWORD_DEFAULT), 'Salt' => null,
    'Role' => 'LECTURER', 'Status' => 'ACTIVE']);
$ins($pdo, 'courses', ['CourseID' => 'SMOKE_CO1', 'CourseCode' => 'DEMO101', 'CourseName' => 'Môn Demo']);
// Lớp 1 có toạ độ phòng (Hà Nội giả định) bán kính 100 m; lớp 2 của GV2.
$ins($pdo, 'classes', ['ClassID' => 'SMOKE_C1', 'CourseID' => 'SMOKE_CO1', 'LecturerID' => 'SMOKE_U1',
    'ClassCode' => 'DEMO101-01', 'RoomLat' => 21.0000000, 'RoomLng' => 105.8000000, 'AllowedRadiusM' => 100]);
$ins($pdo, 'classes', ['ClassID' => 'SMOKE_C2', 'CourseID' => 'SMOKE_CO1', 'LecturerID' => 'SMOKE_U2',
    'ClassCode' => 'DEMO101-02']);
foreach ([1, 2, 3, 4] as $n) {
    $ins($pdo, 'students', ['StudentID' => "SMOKE_S$n", 'MSSV' => "9900000$n", 'FullName' => "SV Demo $n",
        'Email' => "sv$n@example.invalid"]);
}
// S1, S2, S4 học lớp 1; S3 KHÔNG học lớp 1 (học lớp 2).
foreach ([[1, 1], [2, 1], [4, 1], [3, 2]] as [$s, $c]) {
    $ins($pdo, 'enrollments', ['EnrollmentID' => "SMOKE_E{$s}_{$c}", 'StudentID' => "SMOKE_S$s", 'ClassID' => "SMOKE_C$c"]);
}
$ins($pdo, 'sessions', ['SessionID' => 'SMOKE_SS1', 'ClassID' => 'SMOKE_C1', 'SessionNo' => 1, 'Date' => date('Y-m-d'), 'Content' => 'Buổi demo 1']);
$ins($pdo, 'sessions', ['SessionID' => 'SMOKE_SS2', 'ClassID' => 'SMOKE_C2', 'SessionNo' => 1, 'Date' => date('Y-m-d'), 'Content' => 'Buổi demo lớp 2']);
$ins($pdo, 'sessions', ['SessionID' => 'SMOKE_SS3', 'ClassID' => 'SMOKE_C1', 'SessionNo' => 2, 'Date' => date('Y-m-d'), 'Content' => 'Buổi demo 2 (mã hết hạn)']);
$ins($pdo, 'grade_columns', ['GradeColumnID' => 'SMOKE_G1', 'ClassID' => 'SMOKE_C1', 'Name' => 'Giữa kỳ', 'Weight' => 0.4, 'SortOrder' => 1]);
$ins($pdo, 'grade_columns', ['GradeColumnID' => 'SMOKE_G2', 'ClassID' => 'SMOKE_C1', 'Name' => 'Cuối kỳ', 'Weight' => 0.6, 'SortOrder' => 2]);
$ins($pdo, 'grades', ['GradeID' => 'SMOKE_GR1', 'StudentID' => 'SMOKE_S1', 'ClassID' => 'SMOKE_C1', 'GradeColumnID' => 'SMOKE_G1', 'Score' => 8.0]);

echo "Đã gieo dữ liệu demo (ID tiền tố " . SMOKE_PREFIX . ", MSSV 9900000x).\n";

/* ------------------------------------------------------------------ */
/*  Kiểm tra                                                            */
/* ------------------------------------------------------------------ */

section('Khuôn chung + ping');
$r = api('GET', ['action' => 'ping']);
check('ping trả success + version php-*', ok($r) && str_starts_with((string) ($r['data']['version'] ?? ''), 'php-'), brief($r));
check('thiếu action → error', err(api('GET', []), 'Thiếu tham số action'));
check('action lạ → error', err(api('GET', ['action' => 'khongCo']), 'Action không hợp lệ'));
check('login bằng GET → error (sai phương thức)', err(api('GET', ['action' => 'login']), 'phải gọi bằng POST'));
check('ping bằng POST → error (sai phương thức)', err(api('POST', ['action' => 'ping']), 'phải gọi bằng GET'));

section('login / logout');
check('login sai mật khẩu → error', err(api('POST', ['action' => 'login', 'username' => 'smoke_gv1', 'password' => 'sai'])));
check('login user không tồn tại → error', err(api('POST', ['action' => 'login', 'username' => 'khong_co', 'password' => $PW])));
$r = api('POST', ['action' => 'login', 'username' => 'smoke_gv1', 'password' => $PW]);
$tok1 = (string) ($r['data']['token'] ?? '');
check('login GV1 (hash cũ) → token 64 hex', ok($r) && preg_match('/^[0-9a-f]{64}$/', $tok1) === 1, brief($r));
$u1 = $pdo->query("SELECT PasswordHash, Salt FROM users WHERE UserID = 'SMOKE_U1'")->fetch();
check('hash cũ đã được rehash sang password_hash, Salt = NULL', str_starts_with((string) $u1['PasswordHash'], '$2') && $u1['Salt'] === null, brief($u1));
$r = api('POST', ['action' => 'login', 'username' => 'smoke_gv1', 'password' => $PW]);
check('login lại GV1 sau rehash → success', ok($r), brief($r));
$r = api('POST', ['action' => 'login', 'username' => 'smoke_gv2', 'password' => $PW]);
$tok2 = (string) ($r['data']['token'] ?? '');
check('login GV2 (hash mới) → success', ok($r), brief($r));

section('listClasses / listSessions (D.9 phân quyền theo lớp)');
check('listClasses không token → error', err(api('GET', ['action' => 'listClasses'])));
$r = api('GET', ['action' => 'listClasses', 'token' => $tok1]);
$ids = ok($r) ? array_column($r['data'], 'ClassID') : [];
check('listClasses GV1 chỉ thấy lớp của mình', $ids === ['SMOKE_C1'], brief($r));
$r = api('GET', ['action' => 'listSessions', 'token' => $tok1, 'classId' => 'SMOKE_C1']);
check('listSessions GV1 lớp mình → 2 buổi', ok($r) && count($r['data']) === 2, brief($r));
check('listSessions GV1 lớp người khác → error', err(api('GET', ['action' => 'listSessions', 'token' => $tok1, 'classId' => 'SMOKE_C2']), 'không có quyền'));

section('openAttendance');
check('openAttendance không token → error', err(api('POST', ['action' => 'openAttendance', 'sessionId' => 'SMOKE_SS1'])));
check('openAttendance lớp người khác → error', err(api('POST', ['action' => 'openAttendance', 'token' => $tok1, 'sessionId' => 'SMOKE_SS2']), 'không có quyền'));
check('openAttendance buổi không tồn tại → error', err(api('POST', ['action' => 'openAttendance', 'token' => $tok1, 'sessionId' => 'SMOKE_KHONG']), 'Không tìm thấy'));
$r = api('POST', ['action' => 'openAttendance', 'token' => $tok1, 'sessionId' => 'SMOKE_SS1']);
$code = (string) ($r['data']['code'] ?? '');
check('openAttendance GV1 → mã 4 ký tự', ok($r) && preg_match('/^[A-Z0-9]{4}$/', $code) === 1, brief($r));
check('mã có dạng ISO có chữ T (endTime)', str_contains((string) ($r['data']['endTime'] ?? ''), 'T'), brief($r));

section('checkin (D.8 lớp 1–4, 6)');
$dev = 'demo-device-hash-0001';
check('checkin mã sai định dạng → error', err(api('POST', ['action' => 'checkin', 'mssv' => '99000001', 'code' => 'ab'])));
check('checkin mã không mở → error', err(api('POST', ['action' => 'checkin', 'mssv' => '99000001', 'code' => 'Z1Z1']), 'Mã không đúng'));
check('checkin MSSV sai định dạng → error', err(api('POST', ['action' => 'checkin', 'mssv' => 'abc', 'code' => $code]), 'MSSV không hợp lệ'));
check('checkin MSSV không có trong lớp → error (D.8-2)', err(api('POST', ['action' => 'checkin', 'mssv' => '99000003', 'code' => $code]), 'không có trong danh sách lớp'));
$r = api('POST', ['action' => 'checkin', 'mssv' => '99000001', 'code' => strtolower($code), 'lat' => 21.0001, 'lng' => 105.8001,
    'accuracy' => 20, 'deviceHash' => $dev, 'ip' => '6.6.6.6', 'os' => 'DemoOS', 'browser' => 'DemoBrowser', 'deviceType' => 'mobile']);
check('checkin S1 (mã chữ thường vẫn nhận) → PRESENT/INSERTED/VALID', ok($r) && $r['data']['status'] === 'PRESENT'
    && $r['data']['action'] === 'INSERTED' && $r['data']['gpsFlag'] === 'VALID', brief($r));
$att = $pdo->query("SELECT IP FROM attendance WHERE StudentID = 'SMOKE_S1' AND SessionID = 'SMOKE_SS1'")->fetch();
check('IP lưu là IP máy chủ thấy, KHÔNG phải ip client tự khai (M3)', $att && $att['IP'] !== '6.6.6.6' && $att['IP'] !== '', brief($att));
$r = api('POST', ['action' => 'checkin', 'mssv' => '99000001', 'code' => $code, 'deviceHash' => $dev]);
$n = (int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE StudentID = 'SMOKE_S1' AND SessionID = 'SMOKE_SS1'")->fetchColumn();
check('checkin S1 lần 2 → UPDATED, vẫn đúng 1 dòng (D.8-3)', ok($r) && $r['data']['action'] === 'UPDATED' && $n === 1, brief($r));
$r = api('POST', ['action' => 'checkin', 'mssv' => '99000002', 'code' => $code, 'lat' => 21.05, 'lng' => 105.85, 'accuracy' => 10, 'deviceHash' => $dev]);
check('checkin S2 xa phòng → OUT_OF_RANGE (chỉ gắn cờ, không loại)', ok($r) && $r['data']['gpsFlag'] === 'OUT_OF_RANGE', brief($r));
$note = (string) $pdo->query("SELECT Note FROM attendance WHERE StudentID = 'SMOKE_S2' AND SessionID = 'SMOKE_SS1'")->fetchColumn();
check('S2 cùng thiết bị S1 → Note ghi trùng thiết bị (D.8-4)', str_contains($note, 'Trùng thiết bị'), $note);
$r = api('POST', ['action' => 'checkin', 'mssv' => '99000001', 'code' => $code, 'lat' => 21.0001, 'lng' => 105.8001,
    'accuracy' => 20, 'deviceHash' => $dev, 'browser' => str_repeat('x', 300)]);
check('lỗi CSDL (nếu có) không lộ SQLSTATE ra client (M1)', !str_contains((string) ($r['message'] ?? ''), 'SQLSTATE'), brief($r));
// Giờ tính bằng NOW() của CSDL (không dùng date() của PHP) để không lệch múi giờ.
$pdo->exec("INSERT INTO attendance_keys (KeyID, SessionID, Code, StartTime, LateAfter, EndTime, Status, CreatedBy) " .
    "VALUES ('SMOKE_K_OLD', 'SMOKE_SS3', 'Z0Z0', NOW() - INTERVAL 60 MINUTE, NOW() - INTERVAL 55 MINUTE, " .
    "NOW() - INTERVAL 30 MINUTE, 'OPEN', 'SMOKE_U1')");
check('checkin mã quá giờ → error hết hạn (D.8-1)', err(api('POST', ['action' => 'checkin', 'mssv' => '99000001', 'code' => 'Z0Z0']), 'hết hạn'));
$st = (string) $pdo->query("SELECT Status FROM attendance_keys WHERE KeyID = 'SMOKE_K_OLD'")->fetchColumn();
check('mã quá giờ bị tự đóng (Status CLOSED)', $st === 'CLOSED', $st);
$audit = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE Action LIKE 'CHECKIN\\_%' AND TargetID = 'SMOKE_SS1'")->fetchColumn();
check('audit_log có bản ghi cho mỗi lần checkin thành công (D.8-6)', $audit >= 3, "có $audit dòng");

section('liveRoster (D.8 lớp 5)');
check('liveRoster lớp người khác (token GV2) → error', err(api('GET', ['action' => 'liveRoster', 'token' => $tok2, 'sessionId' => 'SMOKE_SS1']), 'không có quyền'));
$r = api('GET', ['action' => 'liveRoster', 'token' => $tok1, 'sessionId' => 'SMOKE_SS1']);
$c = $r['data']['counts'] ?? [];
check('liveRoster đếm đúng: 3 ghi danh, 2 đã điểm danh, 1 vắng', ok($r) && ($c['enrolled'] ?? -1) === 3 && ($c['absent'] ?? -1) === 1, brief($r));
check('liveRoster có cảnh báo trùng thiết bị', ok($r) && count($r['data']['deviceAlerts']) === 1, brief($r['data']['deviceAlerts'] ?? null));
check('liveRoster có cảnh báo GPS cho S2', ok($r) && in_array('99000002', array_column($r['data']['gpsAlerts'], 'mssv'), true), brief($r['data']['gpsAlerts'] ?? null));

section('closeAttendance');
check('closeAttendance lớp người khác → error', err(api('POST', ['action' => 'closeAttendance', 'token' => $tok2, 'sessionId' => 'SMOKE_SS1']), 'không có quyền'));
$r = api('POST', ['action' => 'closeAttendance', 'token' => $tok1, 'sessionId' => 'SMOKE_SS1']);
check('closeAttendance → đánh ABSENT đúng 1 SV (S4)', ok($r) && (int) $r['data']['markedAbsent'] === 1, brief($r));
check('checkin sau khi đóng → error', err(api('POST', ['action' => 'checkin', 'mssv' => '99000004', 'code' => $code]), 'Mã không đúng'));

section('studentHistory');
$r = api('GET', ['action' => 'studentHistory', 'mssv' => '99000001', 'classId' => 'SMOKE_C1']);
$rows = $r['data']['rows'] ?? [];
check('studentHistory S1 → 2 buổi, buổi 1 PRESENT, buổi 2 ABSENT', ok($r) && count($rows) === 2
    && $rows[0]['status'] === 'PRESENT' && $rows[1]['status'] === 'ABSENT', brief($r));
check('studentHistory SV không học lớp → error', err(api('GET', ['action' => 'studentHistory', 'mssv' => '99000003', 'classId' => 'SMOKE_C1'])));

section('requestGradeCode');
$neutralOk = static fn ($r) => ok($r) && str_contains((string) ($r['data']['message'] ?? ''), 'Nếu mã số sinh viên đúng');
check('MSSV sai định dạng → thông báo trung lập (success)', $neutralOk(api('POST', ['action' => 'requestGradeCode', 'mssv' => 'abc'])));
check('MSSV không tồn tại → thông báo trung lập (success)', $neutralOk(api('POST', ['action' => 'requestGradeCode', 'mssv' => '99009999'])));
check('S1 xin mã → trung lập', $neutralOk(api('POST', ['action' => 'requestGradeCode', 'mssv' => '99000001'])));
check('S1 xin lại ngay (cooldown) → trung lập', $neutralOk(api('POST', ['action' => 'requestGradeCode', 'mssv' => '99000001'])));
$sc = (int) $pdo->query("SELECT SendCount FROM grade_codes WHERE StudentID = 'SMOKE_S1'")->fetchColumn();
check('cooldown: SendCount vẫn = 1', $sc === 1, "SendCount=$sc");
$pdo->exec("UPDATE grade_codes SET SendCount = 4, LastSentAt = NOW() - INTERVAL 2 MINUTE, WindowStartAt = NOW() - INTERVAL 1 HOUR WHERE StudentID = 'SMOKE_S1'");
$sentSql = "SELECT COUNT(*) FROM audit_log WHERE Action = 'GRADE_CODE_SENT' AND Actor = 'SMOKE_S1'";
$sentBefore = (int) $pdo->query($sentSql)->fetchColumn();
$par = api_parallel('POST', array_fill(0, 6, ['action' => 'requestGradeCode', 'mssv' => '99000001']));
$sentNew = (int) $pdo->query($sentSql)->fetchColumn() - $sentBefore;
$sc = (int) $pdo->query("SELECT SendCount FROM grade_codes WHERE StudentID = 'SMOKE_S1'")->fetchColumn();
check('6 request xin mã SONG SONG khi còn 1 lượt → chỉ gửi đúng 1 mã, SendCount = 5 (H1)', $sentNew === 1 && $sc === 5,
    "mã gửi mới=$sentNew, SendCount=$sc");
$pdo->exec("UPDATE grade_codes SET LastSentAt = NOW() - INTERVAL 2 MINUTE WHERE StudentID = 'SMOKE_S1'");
check('quá 5 lần/24h → error', err(api('POST', ['action' => 'requestGradeCode', 'mssv' => '99000001']), 'quá nhiều lần'));

section('verifyGradeCode');
$setCode = static function (PDO $pdo, string $c): void {
    $pdo->prepare("UPDATE grade_codes SET CodeHash = :h, Attempts = 0, ExpiresAt = NOW() + INTERVAL 10 MINUTE WHERE StudentID = 'SMOKE_S1'")
        ->execute(['h' => hash('sha256', $c)]);
};
$setCode($pdo, 'ACDE');
check('mã sai → error "Còn 4 lần thử"', err(api('POST', ['action' => 'verifyGradeCode', 'mssv' => '99000001', 'code' => 'XXXX']), 'Còn 4 lần'));
$r = api('POST', ['action' => 'verifyGradeCode', 'mssv' => '99000001', 'code' => 'acde']);
$gtok = (string) ($r['data']['token'] ?? '');
check('mã đúng (chữ thường) → token xem điểm', ok($r) && strlen($gtok) === 64, brief($r));
check('dùng lại mã đã dùng → error', err(api('POST', ['action' => 'verifyGradeCode', 'mssv' => '99000001', 'code' => 'ACDE']), 'hết hạn'));
$setCode($pdo, 'ACDF');
$par = api_parallel('POST', array_fill(0, 12, ['action' => 'verifyGradeCode', 'mssv' => '99000001', 'code' => 'XXXX']));
$wrong = count(array_filter($par, static fn ($x) => err($x, 'Mã không đúng')));
$att = (int) $pdo->query("SELECT Attempts FROM grade_codes WHERE StudentID = 'SMOKE_S1'")->fetchColumn();
check('12 lần đoán SONG SONG → tối đa 5 lần được chấm "sai" (H1)', $wrong <= 5, "wrong=$wrong, Attempts=$att");
$r = api('POST', ['action' => 'verifyGradeCode', 'mssv' => '99000001', 'code' => 'ACDF']);
check('sau 5 lần sai, mã đúng cũng bị từ chối', err($r), brief($r));
$setCode($pdo, 'ACDG');
$par = api_parallel('POST', array_fill(0, 6, ['action' => 'verifyGradeCode', 'mssv' => '99000001', 'code' => 'ACDG']));
$tokens = count(array_filter($par, static fn ($x) => ok($x)));
check('6 request mã ĐÚNG song song → chỉ cấp đúng 1 token (H1)', $tokens === 1, "tokens=$tokens");

section('myGrades');
check('myGrades không token → error', err(api('GET', ['action' => 'myGrades'])));
check('myGrades bằng token giảng viên → error', err(api('GET', ['action' => 'myGrades', 'token' => $tok1])));
$r = api('GET', ['action' => 'myGrades', 'token' => $gtok]);
$cls = $r['data']['classes'][0] ?? [];
check('myGrades S1 → 1 lớp, 2 cột, trung bình tạm 8 trên phần đã chấm', ok($r) && count($r['data']['classes']) === 1
    && count($cls['columns'] ?? []) === 2 && (float) $cls['average'] === 8.0, brief($r));
check('myGrades không nhận mssv từ tham số (vẫn trả điểm của chủ token)', ok($t = api('GET', ['action' => 'myGrades', 'token' => $gtok, 'mssv' => '99000002']))
    && $t['data']['mssv'] === '99000001', brief($t));

section('logout');
$r = api('POST', ['action' => 'logout', 'token' => $tok1]);
check('logout → {ok:true}', ok($r) && ($r['data']['ok'] ?? false) === true, brief($r));
check('token đã logout không dùng được nữa', err(api('GET', ['action' => 'listClasses', 'token' => $tok1]), 'hết hạn'));

/* ------------------------------------------------------------------ */

$covered = ['ping', 'login', 'logout', 'listClasses', 'listSessions', 'openAttendance', 'checkin', 'liveRoster',
    'closeAttendance', 'studentHistory', 'requestGradeCode', 'verifyGradeCode', 'myGrades'];
echo "\nĐã chạy " . count($covered) . "/13 action: " . implode(', ', $covered) . "\n";

if ($opts['keep_data']) {
    echo "--keep-data: GIỮ dữ liệu demo trong CSDL thử.\n";
} else {
    cleanup($pdo);
    echo "Đã dọn dữ liệu demo.\n";
}

echo "\nKẾT QUẢ: {$results['pass']} PASS, {$results['fail']} FAIL\n";
exit($results['fail'] === 0 ? 0 : 1);
