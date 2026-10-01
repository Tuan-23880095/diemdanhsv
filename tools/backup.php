<?php
declare(strict_types=1);

/**
 * tools/backup.php — Sao lưu CSDL MariaDB bằng mysqldump, nén gzip, lưu
 * NGOÀI public_html (rule 4), tự xoá bản cũ quá hạn giữ (PLAN GĐ6: "cron
 * mysqldump sao lưu").
 *
 * CLI-ONLY — không có token/vai trò nào bảo vệ, tuyệt đối không gọi qua web.
 *
 * Cách dùng (THẦY chạy thử qua SSH trước khi đặt cron trong hPanel):
 *   php tools/backup.php                # sao lưu + dọn bản cũ (giữ 14 bản gần nhất)
 *   php tools/backup.php --keep=30      # đổi số bản giữ lại
 *   php tools/backup.php --dry-run      # chỉ kiểm tra mysqldump/thư mục, không chạy dump thật
 *
 * Đặt lịch cron trong hPanel (hPanel → Advanced → Cron Jobs), ví dụ chạy
 * 03:00 hằng ngày — đường dẫn `php` và thư mục chính xác CẦN XÁC NHẬN trên
 * host thật (giống ghi chú "cần xác nhận khi viết code GĐ2" của config.php):
 *   0 3 * * *  /usr/bin/php /home/<user>/domains/diemdanhsv.com/public_html/tools/backup.php >> /home/<user>/domains/diemdanhsv.com/private/backups/cron.log 2>&1
 *
 * KHÔNG dùng -p<mật khẩu> trên dòng lệnh mysqldump (lộ qua `ps aux` cho
 * tiến trình khác trên cùng host) — script tự tạo file option tạm thời
 * (--defaults-extra-file), quyền 0600, xoá ngay sau khi dump xong.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Chỉ chạy được từ CLI.');
}

require __DIR__ . '/../api/lib/config.php';

function backup_args(array $argv): array
{
    $out = ['keep' => 14, 'dry_run' => false];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') { $out['dry_run'] = true; continue; }
        if (str_starts_with($arg, '--keep=')) { $out['keep'] = max(1, (int) substr($arg, 7)); continue; }
        fwrite(STDERR, "Tham số không rõ: $arg\n");
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
$cfg = app_config();
$db = $cfg['db'] ?? null;
if (!is_array($db)) {
    fwrite(STDERR, "Thiếu mục \"db\" trong file cấu hình bí mật.\n");
    exit(1);
}

// tools/ nằm trong public_html (ngang hàng api/); thư mục backup là ANH EM
// của public_html, giống cách api/lib/config.php trỏ tới ../private/.
$backupDir = dirname(__DIR__) . '/../private/backups';
$logFile = $backupDir . '/backup.log';

if (!is_dir($backupDir)) {
    if (!@mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Không tạo được thư mục sao lưu: $backupDir (tạo tay qua SSH/FTP rồi chạy lại).\n");
        exit(1);
    }
}

$mysqldumpBin = trim((string) @shell_exec('command -v mysqldump 2>/dev/null'));
if ($mysqldumpBin === '') {
    fwrite(STDERR, "Không tìm thấy lệnh mysqldump trên host. Trên Hostinger shared hosting thường có sẵn qua SSH — kiểm tra lại PATH hoặc báo thầy.\n");
    exit(1);
}

if ($args['dry_run']) {
    echo "DRY-RUN — không chạy mysqldump thật.\n";
    echo "mysqldump: $mysqldumpBin\n";
    echo "Thư mục sao lưu: $backupDir (" . (is_writable($backupDir) ? 'ghi được' : 'KHÔNG ghi được') . ")\n";
    echo "CSDL: " . ($db['name'] ?? '?') . "@" . ($db['host'] ?? '?') . "\n";
    echo "Giữ lại tối đa: {$args['keep']} bản.\n";
    exit(0);
}

$timestamp = date('Ymd-His');
$optionFile = $backupDir . '/.my.cnf.' . $timestamp . '.tmp';
$outFile = $backupDir . "/diemdanhsv-backup-$timestamp.sql.gz";

$optionContent = "[client]\n" .
    'host=' . ($db['host'] ?? 'localhost') . "\n" .
    'user=' . ($db['user'] ?? '') . "\n" .
    'password=' . ($db['pass'] ?? '') . "\n";

file_put_contents($optionFile, $optionContent);
chmod($optionFile, 0600);

$dbName = escapeshellarg($db['name'] ?? '');
$cmd = sprintf(
    '%s --defaults-extra-file=%s --single-transaction --quick --routines --triggers %s 2> %s | gzip > %s',
    escapeshellarg($mysqldumpBin),
    escapeshellarg($optionFile),
    $dbName,
    escapeshellarg($outFile . '.stderr'),
    escapeshellarg($outFile)
);

$start = microtime(true);
exec($cmd, $unused, $exitCode);
$duration = round(microtime(true) - $start, 1);

// Xoá file option NGAY — có chứa mật khẩu CSDL.
@unlink($optionFile);

$stderrFile = $outFile . '.stderr';
$stderrContent = is_file($stderrFile) ? trim((string) file_get_contents($stderrFile)) : '';
@unlink($stderrFile);

if ($exitCode !== 0 || $stderrContent !== '') {
    @unlink($outFile);
    backup_log($logFile, "THẤT BẠI (mã $exitCode, {$duration}s): $stderrContent");
    exit(1);
}

$size = is_file($outFile) ? filesize($outFile) : 0;
if ($size < 100) { // file gần như rỗng — nghi ngờ dump lỗi dù exit code 0
    backup_log($logFile, "CẢNH BÁO: file sao lưu chỉ $size byte — kiểm tra lại thủ công: $outFile");
} else {
    backup_log($logFile, "OK ({$duration}s, " . round($size / 1024, 1) . " KB): $outFile");
}

// Dọn bản cũ — giữ $keep bản gần nhất theo tên file (có timestamp nên sort tên = sort thời gian).
$existing = glob($backupDir . '/diemdanhsv-backup-*.sql.gz') ?: [];
sort($existing);
$toDelete = count($existing) > $args['keep'] ? array_slice($existing, 0, count($existing) - $args['keep']) : [];
foreach ($toDelete as $old) {
    @unlink($old);
    backup_log($logFile, 'Đã xoá bản cũ quá hạn giữ: ' . basename($old));
}

exit(0);
