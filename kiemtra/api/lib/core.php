<?php
declare(strict_types=1);

/**
 * api/lib/core.php — cấu hình, CSDL, phong bì JSON, tiện ích chung.
 *
 * Cấu hình bí mật dùng CHUNG với diemdanhsv.com:
 *   domains/diemdanhsv.com/private/config.php      (đã có, chứa mục 'db')
 *   domains/diemdanhsv.com/public_html/kiemtra/    (repo này)
 * Tuỳ chọn thêm mục 'kiemtra' => [...] trong config.php (xem config.sample.php).
 *
 * Chạy thử trên máy: biến môi trường KIEMTRA_CONFIG trỏ tới file cấu hình
 * dùng SQLite (chỉ có hiệu lực với php CLI / php -S, không với host thật).
 */

function kt_config_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    if (in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
        $env = getenv('KIEMTRA_CONFIG');
        if (is_string($env) && $env !== '' && is_file($env)) {
            return $path = $env;
        }
    }
    // Dò ngược từ thư mục chứa mã, hỗ trợ cả hai cách triển khai:
    //   thư mục con: domains/diemdanhsv.com/public_html/kiemtra/api/lib → domains/diemdanhsv.com/private/config.php
    //   subdomain:   domains/kiemtra.diemdanhsv.com/public_html/api/lib → domains/diemdanhsv.com/private/config.php
    //                (hoặc domains/diemdanhsv.com/public_html/kiemtra/… tuỳ cách hPanel tạo subdomain)
    $dir = __DIR__;
    for ($i = 0; $i < 7; $i++) {
        $dir = dirname($dir);
        foreach ([$dir . '/private/config.php', $dir . '/diemdanhsv.com/private/config.php'] as $cand) {
            if (@is_file($cand)) {
                return $path = $cand;
            }
        }
    }
    throw new RuntimeException('Chưa tìm thấy private/config.php (dùng chung với diemdanhsv.com).');
}

function kt_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $loaded = require kt_config_path();
        if (!is_array($loaded)) {
            throw new RuntimeException('File cấu hình không hợp lệ.');
        }
        $cfg = $loaded;
    }
    return $cfg;
}

/** Thiết lập riêng của KIEMTRA (mặc định an toàn nếu config.php chưa có mục 'kiemtra'). */
function kt_opt(string $key, $default = null)
{
    $k = kt_config()['kiemtra'] ?? [];
    return array_key_exists($key, $k) ? $k[$key] : $default;
}

function kt_upload_dir(): string
{
    $d = kt_opt('upload_dir');
    if (!$d) {
        $d = dirname(kt_config_path()) . '/kiemtra-uploads';
    }
    if (!is_dir($d)) {
        @mkdir($d, 0750, true);
    }
    return $d;
}

/* ------------------------------ CSDL ------------------------------ */

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $c = kt_config()['db'] ?? null;
    if (!is_array($c)) {
        throw new RuntimeException('Thiếu mục "db" trong cấu hình.');
    }
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    if (($c['driver'] ?? 'mysql') === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['path'], null, null, $opts);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
    } else {
        $opts[PDO::ATTR_EMULATE_PREPARES] = false;
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $c['host'] ?? 'localhost', $c['name'] ?? '', $c['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, (string) ($c['user'] ?? ''), (string) ($c['pass'] ?? ''), $opts);
        $pdo->exec("SET time_zone = '+07:00'");
    }
    return $pdo;
}

function db_is_sqlite(): bool
{
    return db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

/** " FOR UPDATE" trên MySQL, rỗng trên SQLite (SQLite khoá cả file khi ghi). */
function for_update(): string
{
    return db_is_sqlite() ? '' : ' FOR UPDATE';
}

function q(string $sql, array $p = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}
function q_all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function q_one(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function q_val(string $sql, array $p = []) { return q($sql, $p)->fetchColumn(); }

function tx(callable $fn)
{
    $pdo = db();
    if (db_is_sqlite()) {
        $pdo->exec('BEGIN IMMEDIATE');
    } else {
        $pdo->beginTransaction();
    }
    try {
        $r = $fn($pdo);
        if (db_is_sqlite()) { $pdo->exec('COMMIT'); } else { $pdo->commit(); }
        return $r;
    } catch (Throwable $e) {
        try {
            if (db_is_sqlite()) { $pdo->exec('ROLLBACK'); } elseif ($pdo->inTransaction()) { $pdo->rollBack(); }
        } catch (Throwable $ignored) {
        }
        throw $e;
    }
}

/** Giờ theo CSDL (giống db_now() của site chính) — mọi so sánh thời gian đều dùng mốc này. */
function now(): string
{
    if (db_is_sqlite()) {
        return date('Y-m-d H:i:s'); // chạy thử: giờ PHP (Asia/Ho_Chi_Minh)
    }
    return (string) q_val('SELECT NOW()');
}
function ts(?string $dt): ?int
{
    if (!$dt) {
        return null;
    }
    $t = strtotime($dt);
    return $t === false ? null : $t;
}
function dt(int $t): string { return date('Y-m-d H:i:s', $t); }

/** ID dạng tiền tố + 12 hex, cùng kiểu với site chính (GCL_xxx, GRD_xxx…). */
function new_id(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(6));
}

/* ------------------------- Phong bì JSON -------------------------- */

function api_ok($data = null, string $message = 'OK'): void
{
    echo json_encode(['status' => 'success', 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
}
function api_fail(string $message, $data = null): void
{
    echo json_encode(['status' => 'error', 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
}

/** Lỗi nghiệp vụ — thông điệp được hiển thị nguyên văn cho người dùng. */
class UserError extends RuntimeException
{
}
function fail(string $msg): void
{
    throw new UserError($msg);
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function jdec(?string $s, $def = [])
{
    if ($s === null || $s === '') {
        return $def;
    }
    $v = json_decode($s, true);
    return $v === null ? $def : $v;
}
function jenc($v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE);
}

function str_param(array $p, string $k, int $max = 2000): string
{
    $v = trim((string) ($p[$k] ?? ''));
    return mb_substr($v, 0, $max);
}

/** Giới hạn tần suất đơn giản, dùng chung bảng rate_limits của site chính. Lỗi bảng → bỏ qua (fail-open). */
function rate_guard(string $bucket, string $key, int $limit, int $windowSec): void
{
    try {
        $row = q_one('SELECT WindowStart, Hits FROM rate_limits WHERE Bucket = :b AND ClientKey = :k', ['b' => $bucket, 'k' => $key]);
        if ($row && ts($row['WindowStart']) > time() - $windowSec && (int) $row['Hits'] >= $limit) {
            fail('Thử quá nhiều lần. Vui lòng đợi vài phút rồi thử lại.');
        }
    } catch (PDOException $e) {
        error_log('[kt rate] ' . $e->getMessage());
    }
}
function rate_hit(string $bucket, string $key, int $windowSec): void
{
    try {
        $now = now();
        $row = q_one('SELECT WindowStart, Hits FROM rate_limits WHERE Bucket = :b AND ClientKey = :k', ['b' => $bucket, 'k' => $key]);
        if (!$row) {
            q('INSERT INTO rate_limits (Bucket, ClientKey, WindowStart, Hits) VALUES (:b, :k, :w, 1)', ['b' => $bucket, 'k' => $key, 'w' => $now]);
        } elseif (ts($row['WindowStart']) <= ts($now) - $windowSec) {
            q('UPDATE rate_limits SET WindowStart = :w, Hits = 1 WHERE Bucket = :b AND ClientKey = :k', ['b' => $bucket, 'k' => $key, 'w' => $now]);
        } else {
            q('UPDATE rate_limits SET Hits = Hits + 1 WHERE Bucket = :b AND ClientKey = :k', ['b' => $bucket, 'k' => $key]);
        }
    } catch (PDOException $e) {
        error_log('[kt rate] ' . $e->getMessage());
    }
}

/** Mã ngẫu nhiên từ bảng chữ dễ đọc (bỏ 0/O, 1/I/L, 2/Z, 5/S, 8/B — giống mã điểm danh). */
function random_code(int $len): string
{
    $alpha = 'ACDEFGHJKMNPQRTUVWXY34679';
    $s = '';
    for ($i = 0; $i < $len; $i++) {
        $s .= $alpha[random_int(0, strlen($alpha) - 1)];
    }
    return $s;
}
