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
 *     students nào không phải dữ liệu demo của script này (users smoke_*,
 *     môn DEMO*, MSSV 990000xx).
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

// Luôn áp migrations (idempotent) để CSDL thử theo kịp schema mới nhất;
// --init-schema thì nạp cả db/schema.sql trước.
{
    $migs = glob($root . '/db/migrations/*.sql') ?: [];
    sort($migs);
    $files = $opts['init_schema'] ? array_merge([$root . '/db/schema.sql'], $migs) : $migs;
    echo ($opts['init_schema'] ? 'Nạp db/schema.sql + ' : 'Áp ') . "db/migrations/*.sql vào CSDL thử…\n";
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
    'users'    => "Username NOT LIKE 'smoke\\_%'",
    'courses'  => "CourseCode NOT LIKE 'DEMO%'",
    'classes'  => "CourseID NOT IN (SELECT CourseID FROM courses WHERE CourseCode LIKE 'DEMO%')",
    'students' => "MSSV NOT LIKE '990000%'",
];
foreach ($guard as $t => $where) {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM `$t` WHERE $where")->fetchColumn();
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

/*
 * Ba cách gọi API, chọn theo hàm host cho phép:
 *   1. proc_open  → bật php -S (máy chủ dev) rồi gọi HTTP thật (song song bằng curl_multi).
 *   2. exec       → như trên nhưng bật php -S bằng shell nền (Hostinger cấm proc_open, cho exec).
 *   3. in-process → không bật được máy chủ: nạp api/lib/*.php vào chính tiến trình này,
 *                   gọi api_dispatch() trực tiếp (api_ok/api_fail ném ApiResponse nhờ hằng
 *                   API_INPROCESS). Các ca "song song" khi đó chạy TUẦN TỰ — vẫn kiểm đúng
 *                   logic giới hạn, nhưng KHÔNG chứng minh được chống đua (H1).
 */
$mode = 'inprocess';
$port = 18000 + random_int(0, 999);
$base = "http://127.0.0.1:$port/api/index.php";
$serverPid = null;
$server = null;

if (function_exists('proc_open')) {
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
    if (is_resource($server)) {
        $mode = 'http';
        register_shutdown_function(static function () use ($server): void {
            proc_terminate($server);
        });
    }
} elseif (function_exists('exec')) {
    $cmd = sprintf(
        'cd %s && DIEMDANH_CONFIG=%s PHP_CLI_SERVER_WORKERS=4 nohup %s -S 127.0.0.1:%d -t %s >/dev/null 2>&1 & echo $!',
        escapeshellarg($root), escapeshellarg($configPath), escapeshellarg(PHP_BINARY), $port, escapeshellarg($root)
    );
    $out = [];
    @exec($cmd, $out);
    $serverPid = (int) ($out[0] ?? 0);
    if ($serverPid > 0) {
        $mode = 'http';
        register_shutdown_function(static function () use ($serverPid): void {
            @exec('kill ' . $serverPid . ' >/dev/null 2>&1');
        });
    }
}

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
    global $mode;
    if ($mode === 'inprocess') {
        return api_inprocess($method, $params);
    }
    $ch = http_handle($method, $params);
    $body = curl_exec($ch);
    curl_close($ch);
    return decode_body($body);
}

/**
 * Gọi api_dispatch() ngay trong tiến trình này — mô phỏng đúng api/index.php:
 * ApiResponse → phong bì; PDOException/Error → thông báo chung (M1);
 * RuntimeException nghiệp vụ → fail(message).
 */
function api_inprocess(string $method, array $params)
{
    $action = trim((string) ($params['action'] ?? ''));
    try {
        api_dispatch($action, $method, $params);
        return ['__raw' => '(không có phản hồi)'];
    } catch (ApiResponse $r) {
        // json_encode/decode để y hệt dữ liệu client nhận qua HTTP (float/int/null).
        return json_decode(json_encode($r->payload, JSON_UNESCAPED_UNICODE), true);
    } catch (PDOException | Error $e) {
        return ['status' => 'error', 'message' => 'Lỗi máy chủ. Vui lòng thử lại sau.', 'data' => null];
    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Lỗi không xác định.', 'data' => null];
    }
}

/** Gửi nhiều request CÙNG LÚC (curl_multi) — dùng cho kiểm tra race (H1). */
function api_parallel(string $method, array $paramsList): array
{
    global $mode;
    if ($mode === 'inprocess') {
        // Không có máy chủ → chạy tuần tự. Kết quả vẫn phải đúng giới hạn,
        // nhưng không chứng minh được chống đua (xem ghi chú đầu mục).
        return array_map(static fn ($p) => api_inprocess($method, $p), $paramsList);
    }
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

if ($mode === 'http') {
    $up = false;
    for ($i = 0; $i < 50 && !$up; $i++) {
        usleep(100000);
        $r = @api('GET', ['action' => 'ping']);
        $up = ok($r);
    }
    if (!$up) {
        echo "Máy chủ dev php -S không phản hồi sau 5 giây — chuyển sang chế độ in-process.\n";
        $mode = 'inprocess';
    }
}

if ($mode === 'inprocess') {
    // Nạp API vào chính tiến trình này. API_INPROCESS phải định nghĩa TRƯỚC
    // khi nạp response.php. DIEMDANH_CONFIG trỏ CSDL THỬ (config.php chỉ đọc
    // biến này ở SAPI cli).
    define('API_INPROCESS', true);
    putenv('DIEMDANH_CONFIG=' . $configPath);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    date_default_timezone_set('Asia/Ho_Chi_Minh');
    // error_log() của API (stub mail, lỗi CSDL…) ở CLI mặc định in ra màn hình
    // → chuyển vào file tạm để kết quả PASS/FAIL dễ đọc.
    $apiLog = sys_get_temp_dir() . '/diemdanhsv-smoke-api.log';
    ini_set('error_log', $apiLog);
    foreach (['response', 'config', 'db', 'audit', 'mailer', 'auth', 'gradeauth', 'roles', 'attendance', 'queries', 'admin', 'grading', 'actions'] as $lib) {
        require $root . '/api/lib/' . $lib . '.php';
    }
    echo "CHẾ ĐỘ: in-process (host không cho bật php -S — proc_open/exec bị cấm). " .
         "Các ca \"song song\" chạy tuần tự, không chứng minh chống đua. Log API: $apiLog\n";
} else {
    echo "CHẾ ĐỘ: HTTP thật qua php -S trên cổng $port" . ($serverPid ? " (pid $serverPid, bật bằng exec)" : '') . ".\n";
}

/* ------------------------------------------------------------------ */
/*  Dọn + gieo dữ liệu demo                                             */
/* ------------------------------------------------------------------ */

function cleanup(PDO $pdo): void
{
    // Dữ liệu demo nhận diện theo QUAN HỆ, không chỉ theo tiền tố ID — các dòng
    // tạo qua action quản trị GĐ7 có ID thật (CRS_/CLS_/SES_/STD_/ENR_) nhưng
    // đều treo vào users smoke_*, môn DEMO*, MSSV 990000xx.
    $demoStudents = "(SELECT StudentID FROM students WHERE MSSV LIKE '990000%')";
    $demoClasses  = "(SELECT ClassID FROM classes WHERE CourseID IN (SELECT CourseID FROM courses WHERE CourseCode LIKE 'DEMO%'))";
    $pdo->exec("DELETE FROM audit_log WHERE Actor LIKE 'SMOKE\\_%' OR TargetID LIKE 'SMOKE\\_%' OR Actor IN $demoStudents OR TargetID IN $demoClasses");
    $pdo->exec("DELETE FROM auth_tokens WHERE SubjectID LIKE 'SMOKE\\_%' OR SubjectID IN $demoStudents");
    $pdo->exec("DELETE FROM grade_codes WHERE StudentID IN $demoStudents");
    $pdo->exec("DELETE FROM grades WHERE StudentID IN $demoStudents");
    $pdo->exec("DELETE FROM grade_columns WHERE ClassID IN $demoClasses");
    $pdo->exec("DELETE FROM attendance WHERE StudentID IN $demoStudents");
    $pdo->exec("DELETE FROM attendance_keys WHERE SessionID IN (SELECT SessionID FROM sessions WHERE ClassID IN $demoClasses)");
    $pdo->exec("DELETE FROM complaints WHERE StudentID IN $demoStudents");
    $pdo->exec("DELETE FROM sessions WHERE ClassID IN $demoClasses");
    $pdo->exec("DELETE FROM enrollments WHERE StudentID IN $demoStudents");
    // Không tự tham chiếu bảng đang xoá (MySQL lỗi 1093) — đi qua courses.
    $pdo->exec("DELETE FROM classes WHERE CourseID IN (SELECT CourseID FROM courses WHERE CourseCode LIKE 'DEMO%')");
    $pdo->exec("DELETE FROM students WHERE MSSV LIKE '990000%'");
    $pdo->exec("DELETE FROM courses WHERE CourseCode LIKE 'DEMO%'");
    $pdo->exec("DELETE FROM users WHERE Username LIKE 'smoke\\_%'");
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
$ins($pdo, 'grade_columns', ['GradeColumnID' => 'SMOKE_G1', 'ClassID' => 'SMOKE_C1', 'Name' => 'Giữa kỳ', 'Weight' => 40, 'SortOrder' => 1]);
$ins($pdo, 'grade_columns', ['GradeColumnID' => 'SMOKE_G2', 'ClassID' => 'SMOKE_C1', 'Name' => 'Cuối kỳ', 'Weight' => 60, 'SortOrder' => 2]);
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
check('myGrades S1 → total = 8×40/100 = 3.2, weightDone 40/100 (GĐ8)', ok($r) && (float) $cls['total'] === 3.2
    && (float) $cls['weightDone'] === 40.0 && (float) $cls['weightTotal'] === 100.0, brief($cls));
check('myGrades S1 → chuyên cần: 1 buổi đã điểm danh, có mặt 1, điểm 10, không cấm thi (GĐ8)', ok($r) && ($cls['attendance']['sessionsCounted'] ?? -1) === 1
    && $cls['attendance']['present'] === 1 && (float) $cls['attendance']['score'] === 10.0 && $cls['attendance']['banned'] === false, brief($cls['attendance'] ?? null));
check('myGrades không nhận mssv từ tham số (vẫn trả điểm của chủ token)', ok($t = api('GET', ['action' => 'myGrades', 'token' => $gtok, 'mssv' => '99000002']))
    && $t['data']['mssv'] === '99000001', brief($t));

section('Quản trị GĐ7 (api/lib/admin.php)');
// Thêm một tài khoản ADMIN demo (hash mới) — GV1/GV2 là LECTURER.
$ins($pdo, 'users', ['UserID' => 'SMOKE_U3', 'Username' => 'smoke_admin', 'FullName' => 'Admin Demo',
    'Email' => 'admin@example.invalid', 'PasswordHash' => password_hash($PW, PASSWORD_DEFAULT), 'Salt' => null,
    'Role' => 'ADMIN', 'Status' => 'ACTIVE']);
$r = api('POST', ['action' => 'login', 'username' => 'smoke_admin', 'password' => $PW]);
$tokA = (string) ($r['data']['token'] ?? '');
check('login ADMIN → success, role ADMIN', ok($r) && ($r['data']['role'] ?? '') === 'ADMIN', brief($r));

check('adminListCourses không token → error', err(api('GET', ['action' => 'adminListCourses'])));
check('adminListLecturers bằng token LECTURER → error (chỉ ADMIN)', err(api('GET', ['action' => 'adminListLecturers', 'token' => $tok2]), 'không có quyền'));
$r = api('GET', ['action' => 'adminListLecturers', 'token' => $tokA]);
check('adminListLecturers ADMIN → có 3 tài khoản demo, không lộ PasswordHash', ok($r) && count($r['data']) === 3
    && !array_key_exists('PasswordHash', $r['data'][0]), brief($r));

check('adminSaveCourse bằng LECTURER → error', err(api('POST', ['action' => 'adminSaveCourse', 'token' => $tok2, 'courseCode' => 'X', 'courseName' => 'Y']), 'không có quyền'));
$r = api('POST', ['action' => 'adminSaveCourse', 'token' => $tokA, 'courseCode' => 'DEMO102', 'courseName' => 'Môn Demo 2', 'credits' => 2.5]);
$co2 = (string) ($r['data']['courseId'] ?? '');
check('adminSaveCourse ADMIN tạo môn → INSERTED', ok($r) && $r['data']['action'] === 'INSERTED' && str_starts_with($co2, 'CRS_'), brief($r));
$r = api('POST', ['action' => 'adminSaveCourse', 'token' => $tokA, 'courseId' => $co2, 'courseCode' => 'DEMO102', 'courseName' => 'Môn Demo 2 (sửa)', 'status' => 'INACTIVE']);
check('adminSaveCourse sửa môn → UPDATED', ok($r) && $r['data']['action'] === 'UPDATED', brief($r));
$r = api('GET', ['action' => 'adminListCourses', 'token' => $tok2]);
check('adminListCourses LECTURER thấy cả môn INACTIVE (2 môn)', ok($r) && count($r['data']) === 2, brief($r));
// Dọn: môn demo tạo bằng API (ID không có tiền tố SMOKE_) — ghi nhận để cleanup cuối

check('adminSaveClass LECTURER tạo lớp mới → error', err(api('POST', ['action' => 'adminSaveClass', 'token' => $tok2, 'classCode' => 'X']), 'Chỉ ADMIN'));
check('adminSaveClass ADMIN thiếu môn/giảng viên → error', err(api('POST', ['action' => 'adminSaveClass', 'token' => $tokA, 'classCode' => 'DEMO101-03'])));
check('adminSaveClass gán giảng viên không tồn tại → error', err(api('POST', ['action' => 'adminSaveClass', 'token' => $tokA, 'classCode' => 'DEMO101-03',
    'courseId' => 'SMOKE_CO1', 'lecturerIds' => 'SMOKE_KHONG']), 'Không tìm thấy giảng viên'));
$r = api('POST', ['action' => 'adminSaveClass', 'token' => $tokA, 'classCode' => 'DEMO101-03', 'courseId' => 'SMOKE_CO1',
    'lecturerIds' => 'SMOKE_U2, SMOKE_U1', 'semester' => '1', 'academicYear' => '2026-2027', 'roomLat' => 21.0, 'roomLng' => 105.8, 'allowedRadiusM' => 50]);
$c3 = (string) ($r['data']['classId'] ?? '');
check('adminSaveClass ADMIN tạo lớp 2 giảng viên → INSERTED', ok($r) && $r['data']['action'] === 'INSERTED' && str_starts_with($c3, 'CLS_'), brief($r));
$lect = (string) $pdo->query("SELECT LecturerID FROM classes WHERE ClassID = '$c3'")->fetchColumn();
check('LecturerID lưu dạng danh sách phẩy', $lect === 'SMOKE_U2,SMOKE_U1', $lect);
$r = api('GET', ['action' => 'adminListClasses', 'token' => $tok1]);
$ids = ok($r) ? array_column($r['data'], 'ClassID') : [];
check('adminListClasses GV1 thấy lớp mình + lớp mới được gán (kèm LecturerNames)', in_array('SMOKE_C1', $ids, true) && in_array($c3, $ids, true)
    && !in_array('SMOKE_C2', $ids, true) && isset($r['data'][0]['LecturerNames']), brief($ids));
$r = api('POST', ['action' => 'adminSaveClass', 'token' => $tok1, 'classId' => $c3, 'classCode' => 'DEMO101-03B', 'courseId' => 'SMOKE_KHONG',
    'lecturerIds' => 'SMOKE_U1', 'allowedRadiusM' => 80]);
$row = $pdo->query("SELECT ClassCode, CourseID, LecturerID, AllowedRadiusM FROM classes WHERE ClassID = '$c3'")->fetch();
check('adminSaveClass LECTURER sửa lớp mình: đổi mã/bán kính, KHÔNG đổi được môn/giảng viên', ok($r) && $row['ClassCode'] === 'DEMO101-03B'
    && $row['CourseID'] === 'SMOKE_CO1' && $row['LecturerID'] === 'SMOKE_U2,SMOKE_U1' && (int) $row['AllowedRadiusM'] === 80, brief($row));
check('adminSaveClass LECTURER sửa lớp người khác → error', err(api('POST', ['action' => 'adminSaveClass', 'token' => $tok1, 'classId' => 'SMOKE_C2', 'classCode' => 'HACK']), 'không có quyền'));

check('adminSaveSession lớp người khác → error', err(api('POST', ['action' => 'adminSaveSession', 'token' => $tok1, 'classId' => 'SMOKE_C2', 'sessionNo' => 1]), 'không có quyền'));
check('adminSaveSession ngày sai dạng → error', err(api('POST', ['action' => 'adminSaveSession', 'token' => $tok1, 'classId' => $c3, 'sessionNo' => 1, 'date' => '01/10/2026']), 'YYYY-MM-DD'));
$r = api('POST', ['action' => 'adminSaveSession', 'token' => $tok1, 'classId' => $c3, 'sessionNo' => 1, 'date' => '2026-10-02', 'startTime' => '07:30', 'endTime' => '09:30', 'content' => 'Buổi 1']);
$ses = (string) ($r['data']['sessionId'] ?? '');
check('adminSaveSession tạo buổi → INSERTED', ok($r) && $r['data']['action'] === 'INSERTED', brief($r));
check('adminSaveSession sửa buổi bằng sessionId của lớp khác → error', err(api('POST', ['action' => 'adminSaveSession', 'token' => $tok1, 'classId' => $c3, 'sessionId' => 'SMOKE_SS2', 'sessionNo' => 9]), 'Không tìm thấy buổi'));
$r = api('GET', ['action' => 'adminListSessions', 'token' => $tok1, 'classId' => $c3]);
check('adminListSessions → 1 buổi', ok($r) && count($r['data']) === 1 && $r['data'][0]['Content'] === 'Buổi 1', brief($r));

check('adminEnroll lớp người khác → error', err(api('POST', ['action' => 'adminEnroll', 'token' => $tok1, 'classId' => 'SMOKE_C2', 'mssv' => '99000001']), 'không có quyền'));
check('adminEnroll SV mới thiếu họ tên → error', err(api('POST', ['action' => 'adminEnroll', 'token' => $tok1, 'classId' => $c3, 'mssv' => '99000005']), 'thiếu họ tên'));
$r = api('POST', ['action' => 'adminEnroll', 'token' => $tok1, 'classId' => $c3, 'mssv' => '99000005', 'fullName' => 'SV Demo 5', 'email' => 'sv5@example.invalid']);
check('adminEnroll SV mới → student INSERTED + enroll INSERTED', ok($r) && $r['data']['student'] === 'INSERTED' && $r['data']['enroll'] === 'INSERTED', brief($r));
$r = api('POST', ['action' => 'adminEnroll', 'token' => $tok1, 'classId' => $c3, 'mssv' => '99000001']);
check('adminEnroll SV đã có (không gửi tên) → student UNCHANGED + enroll INSERTED', ok($r) && $r['data']['student'] === 'UNCHANGED' && $r['data']['enroll'] === 'INSERTED', brief($r));
$r = api('POST', ['action' => 'adminUnenroll', 'token' => $tok1, 'classId' => $c3, 'mssv' => '99000001']);
$st = (string) $pdo->query("SELECT Status FROM enrollments WHERE StudentID = 'SMOKE_S1' AND ClassID = '$c3'")->fetchColumn();
check('adminUnenroll → enrollment INACTIVE (không xoá dòng)', ok($r) && $st === 'INACTIVE', brief($r));
check('adminUnenroll lần 2 → error (không còn trong danh sách)', err(api('POST', ['action' => 'adminUnenroll', 'token' => $tok1, 'classId' => $c3, 'mssv' => '99000001'])));
$r = api('POST', ['action' => 'adminEnroll', 'token' => $tok1, 'classId' => $c3, 'mssv' => '99000001']);
check('adminEnroll lại SV đã gỡ → REACTIVATED', ok($r) && $r['data']['enroll'] === 'REACTIVATED', brief($r));
$r = api('GET', ['action' => 'adminListRoster', 'token' => $tok1, 'classId' => $c3]);
check('adminListRoster → 2 SV, có EnrollStatus', ok($r) && count($r['data']) === 2 && isset($r['data'][0]['EnrollStatus']), brief($r));

check('adminSaveStudent LECTURER sửa SV không thuộc lớp mình → error', err(api('POST', ['action' => 'adminSaveStudent', 'token' => $tok2, 'mssv' => '99000004', 'fullName' => 'X']), 'không thuộc lớp'));
$r = api('POST', ['action' => 'adminSaveStudent', 'token' => $tok1, 'mssv' => '99000001', 'fullName' => 'SV Demo 1 (sửa)']);
$nm = (string) $pdo->query("SELECT FullName FROM students WHERE MSSV = '99000001'")->fetchColumn();
check('adminSaveStudent LECTURER sửa SV lớp mình → UPDATED', ok($r) && $nm === 'SV Demo 1 (sửa)', brief($r));
check('adminSaveStudent email sai → error', err(api('POST', ['action' => 'adminSaveStudent', 'token' => $tokA, 'mssv' => '99000001', 'email' => 'sai']), 'Email'));

$csv = "\xEF\xBB\xBFSTT;Mã số sinh viên;Họ đệm;Tên;E-mail\n1;99000006;Nguyễn Văn;Sáu;sv6@example.invalid\n2;99000001;;;\n3;99000007;;;\n";
$r = api('POST', ['action' => 'adminImportRoster', 'token' => $tok1, 'classId' => $c3, 'csv' => $csv, 'dryRun' => true]);
check('adminImportRoster dry-run: nhận cột ; + BOM + Họ đệm/Tên; báo 1 dòng lỗi (SV mới thiếu tên); không ghi',
    ok($r) && $r['data']['dryRun'] === true && $r['data']['validRows'] === 2 && count($r['data']['errors']) === 1
    && (int) $pdo->query("SELECT COUNT(*) FROM students WHERE MSSV = '99000006'")->fetchColumn() === 0, brief($r));
$r = api('POST', ['action' => 'adminImportRoster', 'token' => $tok1, 'classId' => $c3, 'csv' => $csv, 'dryRun' => false]);
check('adminImportRoster ghi thật khi còn lỗi → error, không ghi dòng nào', err($r, 'dòng lỗi')
    && (int) $pdo->query("SELECT COUNT(*) FROM students WHERE MSSV = '99000006'")->fetchColumn() === 0, brief($r));
$csv2 = "MSSV,Ho ten,Email\n99000006,Nguyễn Văn Sáu,sv6@example.invalid\n99000001,,\n";
$r = api('POST', ['action' => 'adminImportRoster', 'token' => $tok1, 'classId' => $c3, 'csv' => $csv2, 'dryRun' => false]);
check('adminImportRoster ghi thật → 1 SV mới + 1 ghi danh mới, SV cũ giữ nguyên', ok($r) && $r['data']['written'] === true
    && $r['data']['counts']['studentsInserted'] === 1 && $r['data']['counts']['enrollmentsInserted'] === 1
    && $r['data']['counts']['studentsUpdated'] === 0, brief($r));
$r = api('POST', ['action' => 'adminImportRoster', 'token' => $tok1, 'classId' => $c3, 'csv' => $csv2, 'dryRun' => false]);
check('adminImportRoster chạy lại cùng file → idempotent (0 mới, 0 ghi danh mới)', ok($r) && $r['data']['counts']['studentsInserted'] === 0
    && $r['data']['counts']['enrollmentsInserted'] === 0, brief($r));
check('adminImportRoster không có cột MSSV → error', err(api('POST', ['action' => 'adminImportRoster', 'token' => $tok1, 'classId' => $c3, 'csv' => "STT,Ten\n1,X", 'dryRun' => true]), 'cột MSSV'));
check('adminImportRoster lớp người khác → error', err(api('POST', ['action' => 'adminImportRoster', 'token' => $tok1, 'classId' => 'SMOKE_C2', 'csv' => $csv2, 'dryRun' => true]), 'không có quyền'));
$audit = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE Action LIKE 'ADMIN\\_%'")->fetchColumn();
check('audit_log ghi mọi thao tác quản trị ghi', $audit >= 10, "có $audit dòng");

section('GĐ8 — chuyên cần / nhập điểm CSV / điểm danh tay (api/lib/grading.php)');
// Công thức thuần (không CSDL) — thầy chốt 02/10/2026
$rules = ['absent_penalty' => 3.0, 'excused_penalty' => 1.5, 'late_penalty' => 1.0, 'late_per_absence' => 3, 'excused_per_absence' => 2, 'ban_threshold' => 3, 'max_score' => 10.0];
$f = static fn (int $p, int $l, int $a, int $e) => attendance_score(['present' => $p, 'late' => $l, 'absent' => $a, 'excused' => $e], $rules);
check('công thức: đủ mặt → 10, không cấm', $f(10, 0, 0, 0) === ['score' => 10.0, 'equivalentAbsences' => 0, 'banned' => false]);
check('công thức: 1 vắng 1 phép 1 trễ → 10−3−1,5−1 = 4,5; tđ 1', $f(5, 1, 1, 1)['score'] === 4.5 && $f(5, 1, 1, 1)['equivalentAbsences'] === 1 && !$f(5, 1, 1, 1)['banned']);
check('công thức: 3 trễ = 1 vắng tđ; 2 phép = 1 vắng tđ; 1 vắng → 3 tđ → CẤM THI', $f(0, 3, 1, 2) === ['score' => 10.0 - 3 - 3 - 3, 'equivalentAbsences' => 3, 'banned' => true]);
check('công thức: 2 trễ + 1 phép → 0 tđ (chưa đủ quy đổi), điểm 6,5', $f(3, 2, 0, 1) === ['score' => 6.5, 'equivalentAbsences' => 0, 'banned' => false]);
check('công thức: 5 vắng → điểm không âm (0), cấm thi', $f(0, 0, 5, 0)['score'] === 0.0 && $f(0, 0, 5, 0)['banned']);
check('điểm tổng: 9×50% + 9×20% + 10×20% + 10×10% + 10×10% = 10,3 → quy về 10', grading_total([
    ['weight' => 50, 'score' => 9], ['weight' => 20, 'score' => 9], ['weight' => 20, 'score' => 10], ['weight' => 10, 'score' => 10], ['weight' => 10, 'score' => 10],
]) === ['total' => 10.0, 'weightDone' => 110.0, 'weightTotal' => 110.0]);
check('điểm tổng: 8×50% + 9×20% + 10×20% + 10×10% + 10×10% = 9,8 (chưa chạm trần)', grading_total([
    ['weight' => 50, 'score' => 8], ['weight' => 20, 'score' => 9], ['weight' => 20, 'score' => 10], ['weight' => 10, 'score' => 10], ['weight' => 10, 'score' => 10],
])['total'] === 9.8);
check('điểm tổng: chưa chấm cột nào → null', grading_total([['weight' => 50, 'score' => null]])['total'] === null);

// Lớp C1: SS1 đã đóng điểm danh (S1 PRESENT, S2 PRESENT/LATE?, S4 ABSENT). Thêm buổi SS3 bằng tay rồi tính.
$r = api('GET', ['action' => 'adminAttendanceReport', 'token' => $tok2, 'classId' => 'SMOKE_C1']);
check('adminAttendanceReport lớp người khác → error', err($r, 'không có quyền'));
$r = api('GET', ['action' => 'adminAttendanceReport', 'token' => $tok1, 'classId' => 'SMOKE_C1']);
$byMssv = static fn ($rep) => array_column($rep['data']['rows'] ?? [], null, 'mssv');
$rows = $byMssv($r);
check('adminAttendanceReport C1: 1 buổi đã điểm danh; S1 có mặt; S4 vắng (đóng điểm danh); S2 có bản ghi', ok($r) && $r['data']['sessionsCounted'] === 1
    && $rows['99000001']['present'] === 1 && $rows['99000004']['absent'] === 1 && (float) $rows['99000004']['score'] === 7.0, brief($r));

$r = api('GET', ['action' => 'adminSessionAttendance', 'token' => $tok1, 'sessionId' => 'SMOKE_SS3']);
check('adminSessionAttendance buổi chưa điểm danh → 3 SV, status null', ok($r) && count($r['data']['rows']) === 3 && $r['data']['rows'][0]['status'] === null, brief($r));
check('adminSessionAttendance buổi lớp khác → error', err(api('GET', ['action' => 'adminSessionAttendance', 'token' => $tok1, 'sessionId' => 'SMOKE_SS2']), 'không có quyền'));
check('adminSetAttendance trạng thái lạ → error, không ghi', err(api('POST', ['action' => 'adminSetAttendance', 'token' => $tok1, 'sessionId' => 'SMOKE_SS3',
    'marks' => [['mssv' => '99000001', 'status' => 'XYZ']]]), 'không hợp lệ'));
check('adminSetAttendance MSSV ngoài lớp → error', err(api('POST', ['action' => 'adminSetAttendance', 'token' => $tok1, 'sessionId' => 'SMOKE_SS3',
    'marks' => [['mssv' => '99000003', 'status' => 'PRESENT']]]), 'không có trong danh sách'));
$r = api('POST', ['action' => 'adminSetAttendance', 'token' => $tok1, 'sessionId' => 'SMOKE_SS3', 'marks' => [
    ['mssv' => '99000001', 'status' => 'LATE'], ['mssv' => '99000002', 'status' => 'EXCUSED'], ['mssv' => '99000004', 'status' => ''], // '' = không đụng
]]);
check('adminSetAttendance ghi 2 dòng mới, bỏ qua dòng rỗng', ok($r) && $r['data']['inserted'] === 2 && $r['data']['updated'] === 0, brief($r));
$r = api('POST', ['action' => 'adminSetAttendance', 'token' => $tok1, 'sessionId' => 'SMOKE_SS3', 'marks' => [['mssv' => '99000001', 'status' => 'LATE'], ['mssv' => '99000002', 'status' => 'ABSENT']]]);
check('adminSetAttendance lần 2: 1 giữ nguyên, 1 đổi (EXCUSED→ABSENT), vẫn 1 dòng/SV (D.8-3)', ok($r) && $r['data']['unchanged'] === 1 && $r['data']['updated'] === 1
    && (int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE SessionID = 'SMOKE_SS3'")->fetchColumn() === 2, brief($r));
$r = api('POST', ['action' => 'adminSetAttendance', 'token' => $tok1, 'sessionId' => 'SMOKE_SS1', 'marks' => [['mssv' => '99000004', 'status' => 'EXCUSED']]]);
$row = $pdo->query("SELECT Status, Note FROM attendance WHERE SessionID = 'SMOKE_SS1' AND StudentID = 'SMOKE_S4'")->fetch();
check('adminSetAttendance đổi ABSENT (do đóng điểm danh) → EXCUSED, Note ghi "trước: ABSENT"', ok($r) && $row['Status'] === 'EXCUSED' && str_contains((string) $row['Note'], 'trước: ABSENT'), brief($row));

// Bây giờ C1 có 2 buổi đã điểm danh: SS1 (S1 P, S2 P/L, S4 EXCUSED) + SS3 (S1 LATE, S2 ABSENT, S4 không có dòng = ABSENT)
$r = api('GET', ['action' => 'adminAttendanceReport', 'token' => $tok1, 'classId' => 'SMOKE_C1']);
$rows = $byMssv($r);
check('adminAttendanceReport sau nhập tay: 2 buổi; S1 = 1 mặt 1 trễ → 9; S4 = 1 phép 1 vắng → 5,5', ok($r) && $r['data']['sessionsCounted'] === 2
    && (float) $rows['99000001']['score'] === 9.0 && $rows['99000001']['late'] === 1
    && (float) $rows['99000004']['score'] === 5.5 && $rows['99000004']['excused'] === 1 && $rows['99000004']['absent'] === 1, brief($rows['99000004'] ?? null));

$r = api('POST', ['action' => 'adminApplyAttendanceScore', 'token' => $tok1, 'classId' => 'SMOKE_C1']);
$colId = (string) $pdo->query("SELECT GradeColumnID FROM grade_columns WHERE ClassID = 'SMOKE_C1' AND Name = 'Chuyên cần'")->fetchColumn();
$cc = $pdo->query("SELECT Weight FROM grade_columns WHERE GradeColumnID = '$colId'")->fetchColumn();
check('adminApplyAttendanceScore → tạo cột "Chuyên cần" 10%, ghi 3 SV', ok($r) && $r['data']['columnCreated'] === true && $r['data']['written'] === 3
    && $colId !== '' && (float) $cc === 10.0, brief($r));
$sc = $pdo->query("SELECT Score FROM grades WHERE GradeColumnID = '$colId' AND StudentID = 'SMOKE_S4'")->fetchColumn();
check('điểm chuyên cần S4 trong grades = 5,5', (float) $sc === 5.5, "Score=$sc");
$r = api('POST', ['action' => 'adminApplyAttendanceScore', 'token' => $tok1, 'classId' => 'SMOKE_C1']);
$ncol = (int) $pdo->query("SELECT COUNT(*) FROM grade_columns WHERE ClassID = 'SMOKE_C1' AND Name = 'Chuyên cần'")->fetchColumn();
check('adminApplyAttendanceScore chạy lại → idempotent (vẫn 1 cột, UPDATED)', ok($r) && $r['data']['columnCreated'] === false && $ncol === 1, brief($r));

$csv = "MSSV;Thường xuyên (20%);Giữa kỳ (20%);Cuối kỳ (50%);Điểm cộng (10%)\n99000001;8;7,5;8;\n99000002;9;;7;1\n99000003;5;5;5;\n99000004;11;;;\n";
$r = api('POST', ['action' => 'adminImportGrades', 'token' => $tok1, 'classId' => 'SMOKE_C1', 'csv' => $csv, 'dryRun' => true]);
check('adminImportGrades dry-run: 4 cột (Giữa kỳ đã có → cập nhật 40→20), 2 dòng hợp lệ, 2 lỗi (SV ngoài lớp, điểm 11), tổng 110%', ok($r)
    && count($r['data']['columns']) === 4 && $r['data']['validRows'] === 2 && count($r['data']['errors']) === 2
    && (float) $r['data']['weightTotal'] === 110.0 && str_contains((string) $r['data']['weightNote'], 'quy về'), brief($r));
check('adminImportGrades ghi thật khi còn lỗi → error, không ghi', err(api('POST', ['action' => 'adminImportGrades', 'token' => $tok1, 'classId' => 'SMOKE_C1', 'csv' => $csv, 'dryRun' => false]), 'lỗi')
    && (int) $pdo->query("SELECT COUNT(*) FROM grade_columns WHERE ClassID = 'SMOKE_C1' AND Name = 'Cuối kỳ' AND Weight = 50")->fetchColumn() === 0);
check('adminImportGrades tiêu đề thiếu trọng số cho cột mới → lỗi dòng 1', err(api('POST', ['action' => 'adminImportGrades', 'token' => $tok1, 'classId' => 'SMOKE_C1',
    'csv' => "MSSV,Bài tập lớn\n99000001,9", 'dryRun' => false]), 'thiếu trọng số'));
$csv2 = "MSSV,Thường xuyên (20%),Giữa kỳ (20%),Cuối kỳ (50%),Điểm cộng (10%)\n99000001,8,7.5,8,\n99000002,9,,7,1\n";
$r = api('POST', ['action' => 'adminImportGrades', 'token' => $tok1, 'classId' => 'SMOKE_C1', 'csv' => $csv2, 'dryRun' => false]);
check('adminImportGrades ghi thật → 2 cột mới + 2 cột cập nhật (Giữa kỳ, Cuối kỳ), 6 ô điểm', ok($r) && $r['data']['written'] === true
    && $r['data']['counts']['columnsCreated'] === 2 && $r['data']['counts']['columnsUpdated'] === 2 && $r['data']['counts']['scoresWritten'] === 6, brief($r));
$w = $pdo->query("SELECT Weight FROM grade_columns WHERE ClassID = 'SMOKE_C1' AND Name = 'Giữa kỳ'")->fetchColumn();
check('trọng số "Giữa kỳ" đổi 40 → 20 theo tiêu đề CSV', (float) $w === 20.0, "Weight=$w");
$r = api('POST', ['action' => 'adminImportGrades', 'token' => $tok1, 'classId' => 'SMOKE_C1', 'csv' => $csv2, 'dryRun' => false]);
$ng = (int) $pdo->query("SELECT COUNT(*) FROM grades WHERE ClassID = 'SMOKE_C1'")->fetchColumn();
check('adminImportGrades chạy lại → idempotent (0 cột mới, số dòng grades không tăng)', ok($r) && $r['data']['counts']['columnsCreated'] === 0 && $ng === 9, "grades=$ng");

$r = api('GET', ['action' => 'adminGradesReport', 'token' => $tok1, 'classId' => 'SMOKE_C1']);
$g = array_column($r['data']['rows'] ?? [], null, 'mssv');
// S1: TX 8×20 + GK 7.5×20 + CK 8×50 + CC 9×10 = 1.6+1.5+4+0.9 = 8.0; Điểm cộng chưa chấm → weightDone 100/110
check('adminGradesReport S1: 5 cột, tổng 8,0 trên 100/110% trọng số, không cấm thi', ok($r) && count($r['data']['columns']) === 5
    && (float) $g['99000001']['total'] === 8.0 && (float) $g['99000001']['weightDone'] === 100.0 && (float) $r['data']['weightTotal'] === 110.0
    && $g['99000001']['banned'] === false, brief($g['99000001'] ?? null));
// S2: TX 9×20 + CK 7×50 + ĐC 1×10 + CC (S2: SS1 PRESENT/LATE? + SS3 ABSENT) — chỉ kiểm total ≤ 10 và có attendanceScore
check('adminGradesReport S2: tổng ≤ 10, có attendanceScore', ok($r) && (float) $g['99000002']['total'] <= 10.0 && isset($g['99000002']['attendanceScore']), brief($g['99000002'] ?? null));
check('adminGradesReport lớp người khác → error', err(api('GET', ['action' => 'adminGradesReport', 'token' => $tok2, 'classId' => 'SMOKE_C1']), 'không có quyền'));

// Sinh viên xem điểm: tổng quy về 10 + chuyên cần trực tiếp từ điểm danh
$setCode($pdo, 'ACDH');
$r = api('POST', ['action' => 'verifyGradeCode', 'mssv' => '99000001', 'code' => 'ACDH']);
$gtok2 = (string) ($r['data']['token'] ?? '');
$r = api('GET', ['action' => 'myGrades', 'token' => $gtok2]);
$cls = [];
foreach ($r['data']['classes'] ?? [] as $c) { if ($c['classCode'] === 'DEMO101-01') $cls = $c; } // S1 đã được ghi danh thêm lớp c3 ở GĐ7
check('myGrades S1 sau GĐ8: total 8,0; attendance 2 buổi, trễ 1, điểm 9, không cấm thi', ok($r) && (float) $cls['total'] === 8.0
    && ($cls['attendance']['sessionsCounted'] ?? -1) === 2 && $cls['attendance']['late'] === 1 && (float) $cls['attendance']['score'] === 9.0
    && $cls['attendance']['banned'] === false, brief($cls));
$audit = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE Action IN ('ADMIN_SET_ATTENDANCE','ADMIN_ATTENDANCE_SCORE','ADMIN_IMPORT_GRADES')")->fetchColumn();
check('audit_log ghi các thao tác GĐ8', $audit >= 6, "có $audit dòng");

section('logout');
$r = api('POST', ['action' => 'logout', 'token' => $tok1]);
check('logout → {ok:true}', ok($r) && ($r['data']['ok'] ?? false) === true, brief($r));
check('token đã logout không dùng được nữa', err(api('GET', ['action' => 'listClasses', 'token' => $tok1]), 'hết hạn'));

/* ------------------------------------------------------------------ */

$covered = ['ping', 'login', 'logout', 'listClasses', 'listSessions', 'openAttendance', 'checkin', 'liveRoster',
    'closeAttendance', 'studentHistory', 'requestGradeCode', 'verifyGradeCode', 'myGrades'];
$coveredAdmin = ['adminListCourses', 'adminListLecturers', 'adminListClasses', 'adminListRoster', 'adminListSessions',
    'adminSaveCourse', 'adminSaveClass', 'adminSaveSession', 'adminSaveStudent', 'adminEnroll', 'adminUnenroll', 'adminImportRoster',
    'adminAttendanceReport', 'adminApplyAttendanceScore', 'adminGradesReport', 'adminImportGrades', 'adminSessionAttendance', 'adminSetAttendance'];
echo "\nĐã chạy " . count($covered) . "/13 action cũ: " . implode(', ', $covered) . "\n";
echo "Đã chạy " . count($coveredAdmin) . "/18 action quản trị GĐ7+GĐ8: " . implode(', ', $coveredAdmin) . "\n";

if ($opts['keep_data']) {
    echo "--keep-data: GIỮ dữ liệu demo trong CSDL thử.\n";
} else {
    cleanup($pdo);
    echo "Đã dọn dữ liệu demo.\n";
}

echo "\nKẾT QUẢ: {$results['pass']} PASS, {$results['fail']} FAIL\n";
exit($results['fail'] === 0 ? 0 : 1);
