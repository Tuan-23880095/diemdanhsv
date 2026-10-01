<?php
declare(strict_types=1);

/**
 * tools/migrate.php — Chạy các file db/migrations/*.sql theo thứ tự tên file
 * lên CSDL. CLI-ONLY (rule 3). Mọi migration trong dự án đều viết idempotent
 * (IF NOT EXISTS / IF EXISTS) nên chạy lại nhiều lần an toàn.
 *
 * Cách dùng (THẦY chạy qua SSH, từ thư mục public_html):
 *   php tools/migrate.php --dry-run                       # chỉ liệt kê file + câu SQL, không chạy
 *   php tools/migrate.php                                 # chạy lên CSDL THẬT (../private/config.php)
 *   php tools/migrate.php --config=../private/config.test.php   # chạy lên CSDL THỬ
 *
 * Không có cơ chế "đã chạy rồi thì bỏ qua" — vì các migration tự kiểm tồn
 * tại; đơn giản hơn một bảng schema_migrations và đủ cho quy mô dự án này.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Chỉ chạy được từ CLI.');
}

$root = dirname(__DIR__);
$opts = ['dry_run' => false, 'config' => null];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') { $opts['dry_run'] = true; continue; }
    if (str_starts_with($arg, '--config=')) { $opts['config'] = substr($arg, 9); continue; }
    fwrite(STDERR, "Tham số không rõ: $arg\n");
    exit(2);
}

if ($opts['config'] !== null) {
    if (!is_file($opts['config'])) {
        fwrite(STDERR, "Không thấy file cấu hình: {$opts['config']}\n");
        exit(2);
    }
    putenv('DIEMDANH_CONFIG=' . realpath($opts['config'])); // api/lib/config.php chỉ đọc biến này ở SAPI cli
}
require $root . '/api/lib/config.php';
require $root . '/api/lib/db.php';

$files = glob($root . '/db/migrations/*.sql') ?: [];
sort($files);
if (!$files) {
    echo "Không có file migration nào.\n";
    exit(0);
}

$cfg = app_config()['db'] ?? [];
echo 'CSDL đích: ' . ($cfg['name'] ?? '?') . '@' . ($cfg['host'] ?? '?') . ($opts['dry_run'] ? ' — DRY-RUN, không chạy gì' : '') . "\n";

/** Tách file SQL thành từng câu (theo dấu ; cuối dòng), bỏ dòng chú thích. */
function migrate_split(string $sql): array
{
    $lines = array_filter(array_map('rtrim', explode("\n", $sql)), static fn ($l) => !str_starts_with(ltrim($l), '--'));
    $stmts = [];
    $buf = '';
    foreach ($lines as $l) {
        $buf .= $l . "\n";
        if (str_ends_with(rtrim($l), ';')) {
            $stmts[] = trim($buf);
            $buf = '';
        }
    }
    if (trim($buf) !== '') {
        $stmts[] = trim($buf);
    }
    return $stmts;
}

$pdo = $opts['dry_run'] ? null : db();
$errors = 0;
foreach ($files as $f) {
    echo "\n== " . basename($f) . " ==\n";
    foreach (migrate_split((string) file_get_contents($f)) as $stmt) {
        $short = preg_replace('/\s+/', ' ', mb_substr($stmt, 0, 110));
        if ($opts['dry_run']) {
            echo "  (sẽ chạy) $short\n";
            continue;
        }
        try {
            $pdo->exec($stmt);
            echo "  OK   $short\n";
        } catch (PDOException $e) {
            $errors++;
            echo "  LỖI  $short\n       → " . $e->getMessage() . "\n";
        }
    }
}

echo $errors === 0 ? "\nXong, không lỗi.\n" : "\nXong, có $errors câu lỗi — xem ở trên.\n";
exit($errors === 0 ? 0 : 1);
