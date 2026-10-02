<?php
declare(strict_types=1);

/**
 * api/lib/ratelimit.php — Giới hạn tần suất theo IP (và theo tên đăng nhập)
 * cho các action công khai không cần token: `login`, `checkin`,
 * `requestGradeCode`, `verifyGradeCode`. Sửa mục M4 của review bảo mật
 * (docs/05-GD5-smoke-review.md) — đối chiếu docs/04-API-PHP.md mục 19.
 *
 * Vì sao cần: mã điểm danh chỉ có 25^4 ≈ 390 000 khả năng, mã xem điểm cũng
 * vậy, và mật khẩu giảng viên trước đây thử được không giới hạn. Không có
 * lớp này thì một máy bắn request liên tục dò ra mã đang mở trong vài phút.
 *
 * Cách làm — một bảng đếm `rate_limits` (db/migrations/005-rate-limits.sql):
 *   (Bucket, ClientKey) → WindowStart, Hits   với cửa sổ CỐ ĐỊNH (fixed window):
 *   lượt đầu tiên mở cửa sổ; quá hạn cửa sổ thì lượt kế tiếp đặt lại Hits = 1.
 *   Đơn giản, một câu INSERT … ON DUPLICATE KEY UPDATE là đủ, không cần cron.
 *
 * Hai thao tác tách rời để nơi gọi tự quyết "đếm cái gì":
 *   - rate_limit_guard($bucket, $key)  — ĐỌC: đã vượt ngưỡng → api_fail() thông
 *     báo CHUNG (không lộ là chặn vì mã sai hay vì MSSV sai).
 *   - rate_limit_record($bucket, $key) — GHI: cộng 1 lượt. `login`/`checkin`/
 *     `verifyGradeCode` chỉ ghi khi THẤT BẠI (sinh viên điểm danh đúng trên
 *     WiFi chung của lớp — hàng trăm em cùng một IP NAT — không bị tính);
 *     `requestGradeCode` ghi MỌI lượt vì mỗi lượt là một email.
 *
 * Ngưỡng mặc định ở RATE_LIMIT_DEFAULTS, ghi đè được trong ../private/config.php
 * mục app.rate_limits (xem db/config.sample.php); limit = 0 là TẮT bucket đó.
 *
 * Fail-open có chủ ý: bảng chưa có (quên chạy migration 005) hay CSDL lỗi thì
 * ghi error_log và CHO QUA — giới hạn tần suất là lớp gia cố, không được làm
 * sập login/điểm danh của cả trường. tools/smoke_test.php kiểm bảng tồn tại.
 *
 * IP lấy từ REMOTE_ADDR. Trên Hostinger (LiteSpeed, không qua proxy) đây là IP
 * thật của client. Nếu sau này đặt Cloudflare phía trước thì phải đổi sang
 * header do Cloudflare đặt — KHÔNG tin X-Forwarded-For khi không có proxy,
 * client tự đặt được header đó để né giới hạn.
 */

const RATE_LIMIT_MESSAGE = 'Thao tác quá nhiều lần. Vui lòng đợi vài phút rồi thử lại.';

/** bucket => [số lượt tối đa, độ dài cửa sổ (giây)] */
const RATE_LIMIT_DEFAULTS = [
    'login_ip'         => [20, 900],  // 20 lần login SAI / 15 phút / IP
    'login_user'       => [10, 900],  // 10 lần login SAI / 15 phút / tên đăng nhập (chặn dò phân tán)
    'checkin_ip'       => [60, 600],  // 60 mã SAI / 10 phút / IP — lớp 100 em trên WiFi chung vẫn dư
    'gradecode_req_ip' => [30, 900],  // 30 lần xin mã (mọi lượt) / 15 phút / IP
    'gradecode_ver_ip' => [50, 900],  // 50 lần nhập mã SAI / 15 phút / IP (đã có 5 lần/SV)
];

/** Độ dài tối đa của ClientKey — khớp VARCHAR(64) trong db/schema.sql. */
const RATE_LIMIT_KEY_MAX = 64;

/** @return array{0:int,1:int} [limit, windowSeconds] — limit 0 = tắt. */
function rate_limit_rule(string $bucket): array
{
    $def = RATE_LIMIT_DEFAULTS[$bucket] ?? [0, 0];
    $cfg = [];
    try {
        $cfg = app_config()['app']['rate_limits'][$bucket] ?? [];
    } catch (Throwable $e) {
        // Không nạp được config → dùng mặc định (db() phía dưới sẽ báo lỗi thật nếu có).
    }
    if (!is_array($cfg)) {
        $cfg = [];
    }
    $limit  = (int) ($cfg['limit'] ?? $def[0]);
    $window = (int) ($cfg['window_sec'] ?? $def[1]);
    return [max(0, $limit), max(0, $window)];
}

/** IP client theo máy chủ thấy (không nhận IP tự khai) — '0.0.0.0' khi chạy CLI. */
function rate_limit_client_ip(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip !== '' ? substr($ip, 0, 45) : '0.0.0.0';
}

/** Chuẩn hoá khoá đếm: không rỗng, cắt theo cột; dùng cho tên đăng nhập. */
function rate_limit_key(string $raw): string
{
    $k = mb_strtolower(trim($raw));
    if ($k === '') {
        $k = '-';
    }
    return mb_substr($k, 0, RATE_LIMIT_KEY_MAX);
}

/** true nếu (bucket, key) đã dùng hết lượt trong cửa sổ hiện tại. */
function rate_limit_exceeded(string $bucket, string $key): bool
{
    [$limit, $window] = rate_limit_rule($bucket);
    if ($limit <= 0 || $window <= 0) {
        return false;
    }
    try {
        $stmt = db()->prepare(
            'SELECT Hits FROM rate_limits ' .
            'WHERE Bucket = :b AND ClientKey = :k AND WindowStart > NOW() - INTERVAL :w SECOND LIMIT 1'
        );
        $stmt->bindValue('b', $bucket);
        $stmt->bindValue('k', $key);
        $stmt->bindValue('w', $window, PDO::PARAM_INT);
        $stmt->execute();
        $hits = $stmt->fetchColumn();
        return $hits !== false && (int) $hits >= $limit;
    } catch (PDOException $e) {
        error_log('[ratelimit] ' . $bucket . ' đọc lỗi (fail-open): ' . $e->getMessage());
        return false;
    }
}

/**
 * Cộng 1 lượt cho (bucket, key). Cửa sổ hết hạn → đặt lại Hits = 1 và mở cửa
 * sổ mới. Một câu lệnh nguyên tử nên nhiều request song song không đếm sót.
 */
function rate_limit_record(string $bucket, string $key): void
{
    [$limit, $window] = rate_limit_rule($bucket);
    if ($limit <= 0 || $window <= 0) {
        return;
    }
    try {
        $pdo = db();
        // Trong ON DUPLICATE KEY UPDATE, MySQL gán theo thứ tự trái → phải:
        // Hits tính bằng WindowStart CŨ trước, rồi mới đổi WindowStart.
        // Cùng một tham số không được dùng hai tên trùng khi prepare thật
        // (ATTR_EMULATE_PREPARES = false) → :w1 và :w2.
        $stmt = $pdo->prepare(
            'INSERT INTO rate_limits (Bucket, ClientKey, WindowStart, Hits) VALUES (:b, :k, NOW(), 1) ' .
            'ON DUPLICATE KEY UPDATE ' .
            '  Hits = IF(WindowStart <= NOW() - INTERVAL :w1 SECOND, 1, Hits + 1), ' .
            '  WindowStart = IF(WindowStart <= NOW() - INTERVAL :w2 SECOND, NOW(), WindowStart)'
        );
        $stmt->bindValue('b', $bucket);
        $stmt->bindValue('k', $key);
        $stmt->bindValue('w1', $window, PDO::PARAM_INT);
        $stmt->bindValue('w2', $window, PDO::PARAM_INT);
        $stmt->execute();

        // Dọn dòng cũ thỉnh thoảng (≈ 1/50 lượt ghi) — bảng không phình, không cần cron.
        if (random_int(1, 50) === 1) {
            $pdo->exec('DELETE FROM rate_limits WHERE WindowStart < NOW() - INTERVAL 1 DAY');
        }
    } catch (PDOException $e) {
        error_log('[ratelimit] ' . $bucket . ' ghi lỗi (fail-open): ' . $e->getMessage());
    }
}

/**
 * Chặn ngay nếu đã vượt ngưỡng: api_fail() với thông báo chung (kết thúc
 * request). Gọi ở ĐẦU action, trước mọi truy vấn nghiệp vụ.
 */
function rate_limit_guard(string $bucket, string $key): void
{
    if (rate_limit_exceeded($bucket, $key)) {
        api_fail(RATE_LIMIT_MESSAGE);
    }
}
