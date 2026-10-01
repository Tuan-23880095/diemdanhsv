<?php
declare(strict_types=1);

/**
 * tools/backup.php — Sao lưu CSDL MariaDB bằng PHP THUẦN (PDO), nén gzip,
 * lưu NGOÀI public_html (rule 4), tự xoá bản cũ quá hạn giữ (PLAN GĐ6:
 * "cron mysqldump sao lưu").
 *
 * Vì sao không dùng mysqldump: Hostinger shared hosting CẤM proc_open, exec,
 * shell_exec (phát hiện 01/10/2026 khi chạy trên host — bản đầu dùng
 * mysqldump chết im lặng). Bản này chỉ dùng PDO + zlib, không gọi shell nào,
 * nên mật khẩu CSDL cũng không bao giờ đi qua dòng lệnh/ps aux.
 *
 * File .sql.gz tạo ra đọc được bằng phpMyAdmin (Import) hoặc `mysql <
 * file.sql` — gồm DROP/CREATE TABLE + INSERT theo lô, SET FOREIGN_KEY_CHECKS=0
 * ở đầu để nạp lại không phụ thuộc thứ tự bảng. Toàn bộ dump đọc trong MỘT
 * transaction REPEATABLE READ (snapshot nhất quán, tương đương
 * --single-transaction của mysqldump).
 *
 * CLI-ONLY — không có token/vai trò nào bảo vệ, tuyệt đối không gọi qua web.
 *
 * Cách dùng (THẦY chạy thử qua SSH trước khi đặt cron trong hPanel):
 *   php tools/backup.php                # sao lưu + dọn bản cũ (giữ 14 bản gần nhất)
 *   php tools/backup.php --keep=30      # đổi số bản giữ lại
 *   php tools/backup.php --dry-run      # chỉ kiểm kết nối/thư mục/số dòng, không ghi file
 *   php tools/backup.php --config=../private/config.test.php   # sao lưu CSDL THỬ
 *
 * Đặt lịch cron trong hPanel (Advanced → Cron Jobs), ví dụ 03:00 hằng ngày:
 *   0 3 * * *  /opt/alt/php83/usr/bin/php /home/u464424582/domains/diemdanhsv.com/public_html/tools/backup.php >> /home/u464424582/domains/diemdanhsv.com/private/backups/cron.log 2>&1
 * (đường dẫn php lấy từ `php -r 'echo PHP_BINARY;'` trên host — đã kiểm 01/10/2026.)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Chỉ chạy được từ CLI.');
}

// CLI trên host có thể tắt display_errors → lỗi chết im lặng. Bật lại để
// thầy luôn thấy nguyên nhân khi chạy tay/cron.
ini_set('display_errors', '1');
error_reporting(E_ALL);

const BACKUP_ROWS_PER_INSERT = 200;

function backup_args(array $argv): array
{
    $out = ['keep' => 14, 'dry_run' => false, 'config' => null];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') { $out['dry_run'] = true; continue; }
        if (str_starts_with($arg, '--keep=')) { $out['keep'] = max(1, (int) substr($arg, 7)); continue; }
        if (str_starts_with($arg, '--config=')) { $out['config'] = substr($arg, 9); continue; }
        fwrite(STDERR, "Tham số không rõ: $arg\n");
        exit(2);
    }
    return $out;
}

function backup_log(string $logFile, string $line): void
{
    $stamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$stamp] $line\n", FILE_APPEND | LOCK_EX);
    echo "$line\n";
}

$args = backup_args($argv);
if ($args['config'] !== null) {
    if (!is_file($args['config'])) {
        fwrite(STDERR, "Không thấy file cấu hình: {$args['config']}\n");
        exit(2);
    }
    putenv('DIEMDANH_CONFIG=' . realpath($args['config'])); // api/lib/config.php chỉ đọc biến này ở SAPI cli
}

require __DIR__ . '/../api/lib/config.php';
require __DIR__ . '/../api/lib/db.php';

if (!function_exists('gzopen')) {
    fwrite(STDERR, "PHP trên host thiếu extension zlib (gzopen) — không nén được. Báo Quản gia.\n");
    exit(1);
}

$cfg = app_config();
$dbCfg = $cfg['db'] ?? null;
if (!is_array($dbCfg)) {
    fwrite(STDERR, "Thiếu mục \"db\" trong file cấu hình bí mật.\n");
    exit(1);
}
$dbName = (string) ($dbCfg['name'] ?? '');

// tools/ nằm trong public_html (ngang hàng api/); thư mục backup là ANH EM
// của public_html, giống cách api/lib/config.php trỏ tới ../private/.
$backupDir = dirname(__DIR__) . '/../private/backups';
$logFile = $backupDir . '/backup.log';

if (!is_dir($backupDir)) {
    if (!@mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Không tạo được thư mục sao lưu: $backupDir (tạo tay qua SSH/File Manager rồi chạy lại).\n");
        exit(1);
    }
}

$start = microtime(true);
try {
    $pdo = db();
    // Snapshot nhất quán cho toàn bộ dump (InnoDB) — tương đương --single-transaction.
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    $tables = array_map(static fn ($r) => (string) $r[0], $tables);
    sort($tables);

    $counts = [];
    foreach ($tables as $t) {
        $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    }
} catch (PDOException $e) {
    fwrite(STDERR, "Không kết nối/đọc được CSDL: " . $e->getMessage() . "\n");
    exit(1);
}

if ($args['dry_run']) {
    echo "DRY-RUN — không ghi file nào.\n";
    echo "CSDL: $dbName@" . ($dbCfg['host'] ?? '?') . "\n";
    echo "Thư mục sao lưu: $backupDir (" . (is_writable($backupDir) ? 'ghi được' : 'KHÔNG ghi được') . ")\n";
    echo "Giữ lại tối đa: {$args['keep']} bản.\n";
    echo count($tables) . " bảng sẽ được sao lưu:\n";
    foreach ($counts as $t => $n) {
        printf("  %-18s %8d dòng\n", $t, $n);
    }
    $pdo->exec('ROLLBACK');
    exit(0);
}

$timestamp = date('Ymd-His');
$outFile = $backupDir . "/diemdanhsv-backup-$timestamp.sql.gz";
$tmpFile = $outFile . '.part';

$gz = gzopen($tmpFile, 'wb6');
if ($gz === false) {
    fwrite(STDERR, "Không mở được file ghi: $tmpFile\n");
    exit(1);
}
$write = static function (string $s) use ($gz): void {
    if (gzwrite($gz, $s) === false) {
        throw new RuntimeException('Ghi file sao lưu thất bại (hết dung lượng?).');
    }
};

$totalRows = 0;
try {
    $write("-- diemdanhsv backup (PHP/PDO, không dùng mysqldump)\n");
    $write("-- CSDL: $dbName | Lúc: " . date('Y-m-d H:i:s') . " | Bảng: " . count($tables) . "\n");
    $write("-- Nạp lại: phpMyAdmin → Import, hoặc: gunzip -c file.sql.gz | mysql -u <user> -p <csdl>\n\n");
    $write("SET NAMES utf8mb4;\nSET time_zone = '+07:00';\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET AUTOCOMMIT = 0;\nSTART TRANSACTION;\n\n");

    foreach ($tables as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
        $write("-- ---------------- $t ({$counts[$t]} dòng) ----------------\n");
        $write("DROP TABLE IF EXISTS `$t`;\n");
        $write((string) $create[1] . ";\n\n");

        if ($counts[$t] === 0) {
            continue;
        }

        $cols = array_map(static fn ($c) => '`' . $c['Field'] . '`', $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll());
        $colList = implode(', ', $cols);

        // Đọc theo luồng (unbuffered) để không nạp cả bảng vào RAM; phải fetch
        // hết trước khi chạy câu khác — vòng while bên dưới làm đúng điều đó.
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $stmt = $pdo->query("SELECT * FROM `$t`");
        $batch = [];
        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            $vals = [];
            foreach ($row as $v) {
                if ($v === null) {
                    $vals[] = 'NULL';
                } elseif (is_int($v) || is_float($v)) {
                    $vals[] = (string) $v;
                } else {
                    $vals[] = $pdo->quote((string) $v);
                }
            }
            $batch[] = '(' . implode(', ', $vals) . ')';
            $totalRows++;
            if (count($batch) >= BACKUP_ROWS_PER_INSERT) {
                $write("INSERT INTO `$t` ($colList) VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) {
            $write("INSERT INTO `$t` ($colList) VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        $stmt->closeCursor();
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        $write("\n");
    }

    $write("COMMIT;\nSET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n-- Hết.\n");
    gzclose($gz);
    $pdo->exec('ROLLBACK'); // chỉ đọc — kết thúc snapshot

    if (!rename($tmpFile, $outFile)) {
        throw new RuntimeException("Không đổi tên được $tmpFile → $outFile");
    }
    @chmod($outFile, 0600);
} catch (Throwable $e) {
    @gzclose($gz);
    @unlink($tmpFile);
    backup_log($logFile, 'THẤT BẠI: ' . $e->getMessage());
    exit(1);
}

$duration = round(microtime(true) - $start, 1);
$size = (int) filesize($outFile);

// Kiểm nhanh file vừa ghi: giải nén đọc được tới dòng cuối "-- Hết."
$check = gzopen($outFile, 'rb');
$lastLine = '';
if ($check !== false) {
    while (($line = gzgets($check)) !== false) {
        $lastLine = $line;
    }
    gzclose($check);
}
if (trim($lastLine) !== '-- Hết.') {
    backup_log($logFile, "CẢNH BÁO: file sao lưu không đọc lại được tới cuối — kiểm tra tay: $outFile");
    exit(1);
}

backup_log($logFile, sprintf('OK (%ss, %s KB, %d bảng, %d dòng): %s',
    $duration, number_format($size / 1024, 1), count($tables), $totalRows, basename($outFile)));

// Dọn bản cũ — giữ $keep bản gần nhất theo tên file (có timestamp nên sort tên = sort thời gian).
$existing = glob($backupDir . '/diemdanhsv-backup-*.sql.gz') ?: [];
sort($existing);
$toDelete = count($existing) > $args['keep'] ? array_slice($existing, 0, count($existing) - $args['keep']) : [];
foreach ($toDelete as $old) {
    @unlink($old);
    backup_log($logFile, 'Đã xoá bản cũ quá hạn giữ: ' . basename($old));
}

exit(0);
