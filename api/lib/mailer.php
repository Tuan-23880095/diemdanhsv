<?php
declare(strict_types=1);

/**
 * api/lib/mailer.php — Gửi email. STUB cho tới khi thầy tạo hộp thư thật
 * noreply@diemdanhsv.com (docs/04-API-PHP.md mục 4, mục 10.4; PLAN GĐ3:
 * "requestGradeCode/verifyGradeCode (SMTP stub)").
 *
 * STUB HIỆN TẠI: không mở kết nối SMTP nào — chỉ ghi lại (error_log) rằng
 * "sẽ gửi" email này, kèm mã xác minh, để còn kiểm tra luồng bằng tay lúc
 * chưa có hộp thư thật. KHÔNG dùng ở production thật cho tới khi thay bằng
 * SMTP thật — nếu chạy stub này trên host thật, sinh viên sẽ KHÔNG nhận
 * được email, chỉ có dòng log trong error_log của PHP.
 *
 * Khi thầy tạo xong noreply@diemdanhsv.com: thay thân hàm mail_send() bằng
 * PHPMailer/SMTP thật, đọc cấu hình từ app_config()['smtp'] (đã có sẵn
 * trong db/config.sample.php) — KHÔNG cần đổi chữ ký hàm hay nơi gọi.
 */
function mail_send(string $to, string $subject, string $body): void
{
    // GĐ5 review bảo mật (docs/05-GD5-smoke-review.md, H2): MẶC ĐỊNH KHÔNG
    // ghi thân email (có mã xác minh xem điểm) ra error_log — trên shared
    // hosting file error_log có thể nằm ngay trong public_html/api/. Chỉ ghi
    // thân email khi file cấu hình bí mật bật rõ ràng
    // app.mail_stub_log_body = true (dùng trên CSDL thử, KHÔNG bật ở host thật).
    $logBody = (app_config()['app']['mail_stub_log_body'] ?? false) === true;

    error_log(sprintf(
        '[mail_send STUB] chưa cấu hình SMTP thật (docs/04-API-PHP.md mục 10.4) — ' .
        'sẽ gửi tới %s | subject=%s | body=%s',
        $to,
        $subject,
        $logBody ? str_replace("\n", ' \\n ', $body) : '(ẩn — bật app.mail_stub_log_body để xem khi test)'
    ));
}
