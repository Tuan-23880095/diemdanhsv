<?php
declare(strict_types=1);

/**
 * tools/mail_test.php — Gửi thử MỘT email qua cấu hình SMTP trong
 * ../private/config.php để kiểm hộp thư noreply@ trước khi sinh viên dùng
 * xem điểm hai bước (docs/06 checklist mục 5; docs/04 mục 10.4).
 * CLI ONLY. Không chạm CSDL. Không in mật khẩu.
 *
 * Cách dùng (SSH, từ public_html):
 *   php tools/mail_test.php --to=thay@example.com
 *   php tools/mail_test.php --to=thay@example.com --config=../private/config.test.php
 *
 * Kết quả:
 *   "ĐÃ GỬI"          → máy chủ SMTP đã nhận thư; kiểm hộp thư đến (cả Spam).
 *   "CHẾ ĐỘ STUB"     → config.php chưa có smtp.pass (hoặc còn CHANGE_ME) — chưa gửi gì.
 *   "GỬI THẤT BẠI"    → xem dòng lỗi in ra (mail_send ghi error_log; lệnh này in lại).
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Chỉ chạy được từ CLI.');
}

ini_set('display_errors', '1');
error_reporting(E_ALL);

$to = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--to=')) { $to = trim(substr($arg, 5)); continue; }
    if (str_starts_with($arg, '--config=')) {
        $cfgPath = substr($arg, 9);
        if (!is_file($cfgPath)) { fwrite(STDERR, "Không thấy file cấu hình: $cfgPath\n"); exit(2); }
        putenv('DIEMDANH_CONFIG=' . realpath($cfgPath));
        continue;
    }
    fwrite(STDERR, "Tham số không rõ: $arg\n");
    exit(2);
}
if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Cần --to=<email hợp lệ> (hộp thư của thầy để nhận thử).\n");
    exit(2);
}

require __DIR__ . '/../api/lib/config.php';
require __DIR__ . '/../api/lib/mailer.php';

$smtp = app_config()['smtp'] ?? [];
if (!is_array($smtp) || !mail_is_configured($smtp)) {
    echo "CHẾ ĐỘ STUB — ../private/config.php chưa có mục smtp đầy đủ (host/user/pass; pass còn trống hoặc CHANGE_ME).\n";
    echo "Tạo hộp thư noreply@ trong hPanel → Emails, điền smtp.pass vào config.php rồi chạy lại. Chưa gửi gì.\n";
    exit(1);
}

$port = (int) ($smtp['port'] ?? 465);
$tls  = (string) ($smtp['tls'] ?? ($port === 465 ? 'implicit' : 'starttls'));
printf("SMTP: %s:%d (%s) user=%s from=%s\n", $smtp['host'], $port, $tls, $smtp['user'], $smtp['from'] ?? $smtp['user']);
echo "Gửi thử tới $to …\n";

// Bắt error_log của mail_send để in lại cho thầy thấy ngay (không chứa mật khẩu/thân thư).
$logFile = tempnam(sys_get_temp_dir(), 'mailtest');
$prevLog = ini_set('error_log', $logFile);
$t0 = microtime(true);
$ok = mail_send(
    $to,
    'Thử gửi mail — Hệ thống điểm danh & xem điểm',
    "Đây là email thử từ tools/mail_test.php lúc " . date('Y-m-d H:i:s') . ".\n\n" .
    "Nếu thầy nhận được thư này thì cấu hình SMTP đã đúng; sinh viên sẽ nhận được mã xem điểm.\n" .
    "Tiếng Việt có dấu: ă â đ ê ô ơ ư — kiểm mã hoá UTF-8."
);
$ms = (int) round((microtime(true) - $t0) * 1000);
ini_set('error_log', (string) $prevLog);
$log = is_file($logFile) ? trim((string) file_get_contents($logFile)) : '';
@unlink($logFile);

if ($ok) {
    echo "ĐÃ GỬI ({$ms} ms). Kiểm hộp thư đến của $to (xem cả thư mục Spam). Checklist mục 5 có thể kiểm tiếp.\n";
    exit(0);
}
echo "GỬI THẤT BẠI ({$ms} ms).\n";
if ($log !== '') {
    echo "Lỗi: " . preg_replace('/^\[[^\]]*\]\s*/m', '', $log) . "\n";
}
echo "Thường gặp: sai mật khẩu hộp thư (AUTH), hộp thư chưa tạo xong (vài phút), hoặc host chặn cổng — thử port 587 + tls 'starttls'.\n";
exit(1);
