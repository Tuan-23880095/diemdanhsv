<?php
declare(strict_types=1);

/**
 * api/lib/db.php — Kết nối PDO dùng chung một request + wrapper transaction
 * thay LockService.getScriptLock() cũ (docs/04-API-PHP.md mục 6).
 *
 * Kết nối MỞ LAZY (chỉ khi action thật sự cần CSDL) — action `ping` (GĐ2)
 * không cần CSDL nên không đụng tới file này.
 */

/** Trả về kết nối PDO dùng chung cho cả request hiện tại. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $cfg = app_config()['db'] ?? null;
    if (!is_array($cfg)) {
        throw new RuntimeException('Thiếu mục "db" trong file cấu hình bí mật.');
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $cfg['host'] ?? 'localhost',
        $cfg['name'] ?? '',
        $cfg['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, (string) ($cfg['user'] ?? ''), (string) ($cfg['pass'] ?? ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

/**
 * Bọc một thao tác ghi trong transaction PDO — thay LockService cũ. Dựa vào
 * UNIQUE KEY của MySQL (vd uq_att_student_session) để tự chặn ghi trùng
 * thay vì tự cài khoá tiến trình như withLock_() cũ (docs/04-API-PHP.md mục 6).
 *
 * @param callable(PDO):mixed $fn
 * @return mixed Giá trị $fn trả về.
 */
function db_transaction(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
