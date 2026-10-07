<?php
declare(strict_types=1);

/**
 * api/lib/mailer.php — Gửi email qua SMTP bằng PHP THUẦN (không PHPMailer/
 * Composer — host cấm exec, không có vendor/). Dùng cho mã xác minh xem điểm
 * (api/lib/gradeauth.php requestGradeCode; docs/04-API-PHP.md mục 10.4).
 *
 * Cấu hình đọc từ app_config()['smtp'] (../private/config.php, rule 4):
 *   host      smtp.hostinger.com
 *   port      465 (TLS ngầm — khuyên dùng) hoặc 587 (STARTTLS)
 *   user      noreply@diemdanhsv.com   (tên đăng nhập hộp thư = địa chỉ)
 *   pass      mật khẩu hộp thư
 *   from      (tuỳ chọn) địa chỉ người gửi — mặc định = user
 *   from_name (tuỳ chọn) tên hiển thị — mặc định "Hệ thống điểm danh & xem điểm"
 *   timeout   (tuỳ chọn) giây, mặc định 15
 *   tls       (tuỳ chọn) 'implicit' | 'starttls' — mặc định theo cổng (465 → implicit, khác → starttls)
 *   cafile    (tuỳ chọn) đường dẫn CA bundle riêng nếu PHP trên host không xác minh được chứng chỉ
 *
 * CHẾ ĐỘ STUB (giữ nguyên hành vi cũ): khi chưa có hộp thư — `pass` trống
 * hoặc còn 'CHANGE_ME', hoặc thiếu host/user — hàm KHÔNG mở kết nối nào, chỉ
 * ghi error_log "[mail_send STUB] …" và trả false. Nhờ vậy merge trước, tạo
 * hộp thư sau: điền mật khẩu vào config.php là gửi thật, không cần deploy lại.
 *
 * KHÔNG BAO GIỜ ném ngoại lệ ra ngoài: lỗi SMTP chỉ ghi error_log (không kèm
 * mật khẩu, không kèm thân email) và trả false — nơi gọi quyết định xử lý
 * (gradeauth.php vẫn trả phản hồi trung tính để không lộ MSSV nào có email).
 *
 * Thử trước bằng: php tools/mail_test.php --to=<email của thầy>
 */

const MAIL_DEFAULT_FROM_NAME = 'Hệ thống điểm danh & xem điểm';

/** true nếu cấu hình smtp đủ để gửi thật (có host, user, pass không phải placeholder). */
function mail_is_configured(array $smtp): bool
{
    $pass = (string) ($smtp['pass'] ?? '');
    return trim((string) ($smtp['host'] ?? '')) !== ''
        && trim((string) ($smtp['user'] ?? '')) !== ''
        && $pass !== '' && $pass !== 'CHANGE_ME';
}

/**
 * Gửi một email text/plain UTF-8. Trả true nếu máy chủ SMTP đã NHẬN thư
 * (250 sau DATA), false nếu đang ở chế độ stub hoặc gửi lỗi.
 */
/**
 * @param array $attachments Danh sách [['name' => 'KetQua.html', 'mime' => 'text/html', 'data' => '<bytes>'], ...]
 *                           (rỗng = thư text/plain như trước). Tổng ≤ ~2 MB.
 */
function mail_send(string $to, string $subject, string $body, array $attachments = []): bool
{
    $cfg  = app_config();
    $smtp = is_array($cfg['smtp'] ?? null) ? $cfg['smtp'] : [];

    if (!mail_is_configured($smtp)) {
        // GĐ5 review bảo mật (docs/05, H2): mặc định KHÔNG ghi thân email (có
        // mã xem điểm) ra error_log — chỉ khi app.mail_stub_log_body = true
        // (CSDL thử). Trên host thật file error_log có thể nằm trong public_html/api/.
        $logBody = ($cfg['app']['mail_stub_log_body'] ?? false) === true;
        error_log(sprintf(
            '[mail_send STUB] chưa cấu hình SMTP thật (docs/04-API-PHP.md mục 10.4) — ' .
            'sẽ gửi tới %s | subject=%s | body=%s',
            $to,
            $subject,
            $logBody ? str_replace("\n", ' \\n ', $body) : '(ẩn — bật app.mail_stub_log_body để xem khi test)'
        ));
        return false;
    }

    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        error_log('[mail_send] địa chỉ nhận không hợp lệ: ' . $to);
        return false;
    }

    try {
        return smtp_deliver($smtp, $to, $subject, $body, $attachments);
    } catch (Throwable $e) {
        // Không in mật khẩu, không in thân email.
        error_log('[mail_send] gửi thất bại tới ' . $to . ': ' . $e->getMessage());
        return false;
    }
}

/* ====================================================================== */
/*  SMTP client tối giản — chỉ phần cần cho một thư text/plain            */
/* ====================================================================== */

/**
 * Nối tới máy chủ SMTP, xác thực AUTH LOGIN, gửi một thư. Ném RuntimeException
 * khi máy chủ trả mã lỗi hoặc mất kết nối (mail_send() bắt và ghi log).
 */
function smtp_deliver(array $smtp, string $to, string $subject, string $body, array $attachments = []): bool
{
    $host     = trim((string) $smtp['host']);
    $port     = (int) ($smtp['port'] ?? 465);
    $user     = trim((string) $smtp['user']);
    $pass     = (string) $smtp['pass'];
    $from     = trim((string) ($smtp['from'] ?? '')) ?: $user;
    $fromName = trim((string) ($smtp['from_name'] ?? '')) ?: MAIL_DEFAULT_FROM_NAME;
    $timeout  = max(5, (int) ($smtp['timeout'] ?? 15));
    $tlsMode  = (string) ($smtp['tls'] ?? ($port === 465 ? 'implicit' : 'starttls'));
    $implicitTls = $tlsMode === 'implicit';

    $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host];
    if (!empty($smtp['cafile'])) {
        $ssl['cafile'] = (string) $smtp['cafile'];
    }
    $ctx = stream_context_create(['ssl' => $ssl]);
    $remote = ($implicitTls ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if ($fp === false) {
        throw new RuntimeException("không kết nối được $remote ($errno $errstr)");
    }
    stream_set_timeout($fp, $timeout);

    try {
        smtp_expect($fp, [220], 'chào');
        $ehloHost = smtp_ehlo_host();
        smtp_cmd($fp, "EHLO $ehloHost", [250], 'EHLO');

        if (!$implicitTls) {
            smtp_cmd($fp, 'STARTTLS', [220], 'STARTTLS');
            $ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($ok !== true) {
                throw new RuntimeException('STARTTLS thất bại (không bật được TLS)');
            }
            smtp_cmd($fp, "EHLO $ehloHost", [250], 'EHLO sau TLS');
        }

        smtp_cmd($fp, 'AUTH LOGIN', [334], 'AUTH LOGIN');
        smtp_cmd($fp, base64_encode($user), [334], 'AUTH user');
        smtp_cmd($fp, base64_encode($pass), [235], 'AUTH mật khẩu (sai mật khẩu hộp thư?)');

        smtp_cmd($fp, 'MAIL FROM:<' . $from . '>', [250], 'MAIL FROM');
        smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251], 'RCPT TO');
        smtp_cmd($fp, 'DATA', [354], 'DATA');

        $message = smtp_build_message($from, $fromName, $to, $subject, $body, $host, $attachments);
        // Dot-stuffing: dòng bắt đầu bằng "." phải thành ".."; kết thúc bằng CRLF.CRLF
        $message = preg_replace('/^\./m', '..', $message);
        smtp_write($fp, $message . "\r\n.\r\n");
        smtp_expect($fp, [250], 'kết thúc DATA');

        smtp_cmd($fp, 'QUIT', [221], 'QUIT', false);
        return true;
    } finally {
        @fclose($fp);
    }
}

/** Tên máy chủ cho EHLO — host web nếu biết, không thì localhost. */
function smtp_ehlo_host(): string
{
    $h = (string) ($_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost');
    return preg_match('/^[A-Za-z0-9.-]+$/', $h) ? $h : 'localhost';
}

/** Dựng thư RFC 5322 text/plain UTF-8, header UTF-8 mã hoá RFC 2047, thân base64. */
function smtp_build_message(string $from, string $fromName, string $to, string $subject, string $body, string $host, array $attachments = []): string
{
    $enc = static fn (string $s): string => '=?UTF-8?B?' . base64_encode($s) . '?=';
    $domain = substr($from, (int) strpos($from, '@') + 1) ?: $host;
    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . $enc($fromName) . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . $enc($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Auto-Submitted: auto-generated',
    ];
    // Thân thư base64 chia dòng 76 ký tự — an toàn với mọi máy chủ, không lo 8bit/độ dài dòng.
    $bodyB64 = rtrim(chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $body)), 76, "\r\n"), "\r\n");
    if (!$attachments) {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        return implode("\r\n", $headers) . "\r\n\r\n" . $bodyB64;
    }
    // multipart/mixed: phần 1 text/plain, các phần sau là tệp đính kèm (RFC 2046/2183).
    $boundary = '=_dd_' . bin2hex(random_bytes(12));
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
    $parts = [];
    $parts[] = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $bodyB64;
    foreach ($attachments as $a) {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($a['name'] ?? 'tep-dinh-kem'));
        $mime = preg_match('#^[a-z]+/[a-z0-9.+-]+$#i', (string) ($a['mime'] ?? '')) ? $a['mime'] : 'application/octet-stream';
        $data = rtrim(chunk_split(base64_encode((string) ($a['data'] ?? '')), 76, "\r\n"), "\r\n");
        $parts[] = "--$boundary\r\nContent-Type: $mime; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n" .
                   "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n" . $data;
    }
    return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts) . "\r\n--$boundary--";
}

/** Gửi một lệnh, chờ mã trả lời mong đợi. $strict=false: không ném lỗi (dùng cho QUIT). */
function smtp_cmd($fp, string $line, array $expect, string $what, bool $strict = true): void
{
    smtp_write($fp, $line . "\r\n");
    smtp_expect($fp, $expect, $what, $strict);
}

function smtp_write($fp, string $data): void
{
    $len = strlen($data);
    $sent = 0;
    while ($sent < $len) {
        $n = @fwrite($fp, substr($data, $sent));
        if ($n === false || $n === 0) {
            throw new RuntimeException('mất kết nối khi ghi SMTP');
        }
        $sent += $n;
    }
}

/**
 * Đọc trả lời SMTP (có thể nhiều dòng "250-…" rồi "250 …"), so mã với $expect.
 * Thông điệp lỗi chỉ gồm mã + dòng đầu của máy chủ (không chứa gì ta đã gửi).
 */
function smtp_expect($fp, array $expect, string $what, bool $strict = true): void
{
    $code = 0;
    $first = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($first === '') {
            $first = $line;
        }
        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            $code = (int) $m[1];
            if ($m[2] === ' ') {
                break;
            }
        } else {
            break;
        }
    }
    if ($code === 0) {
        $meta = stream_get_meta_data($fp);
        if (!$strict) {
            return;
        }
        throw new RuntimeException("không nhận được trả lời SMTP ở bước $what" . (($meta['timed_out'] ?? false) ? ' (hết giờ)' : ''));
    }
    if (!in_array($code, $expect, true) && $strict) {
        throw new RuntimeException("SMTP từ chối ở bước $what: " . mb_substr($first, 0, 200));
    }
}
