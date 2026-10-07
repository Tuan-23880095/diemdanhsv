<?php

/**
 * api/index.php — Điểm vào duy nhất của API PHP, thay doGet/doPost cũ
 * (gas/05-Api.gs). Đối chiếu đầy đủ tại docs/04-API-PHP.md — sửa tài liệu
 * đó trước khi sửa router này nếu có thay đổi thiết kế.
 *
 * Giữ đúng khuôn dạng cũ (rule 5 của dự án, docs/04-API-PHP.md mục 1):
 *   - GET  cho thao tác ĐỌC:  ?action=...&...
 *   - POST cho thao tác GHI:  body JSON thô, Content-Type: text/plain;charset=utf-8
 *     (đúng như js/services/APIService.js đang gửi — KHÔNG đổi file đó).
 *   - Phong bì: { status: 'success'|'error', message, data } — xem api/lib/response.php.
 *   - HTTP 200 cho MỌI lỗi nghiệp vụ; chỉ đổi mã HTTP khi bản thân request
 *     hỏng (vd sai method) — không phải lỗi nghiệp vụ.
 *
 * Same-origin (frontend + API cùng domain diemdanhsv.com) → không cần xử lý
 * OPTIONS/CORS (docs/04-API-PHP.md mục 2).
 */

declare(strict_types=1);

// Không bao giờ in warning/notice lẫn vào JSON trả về (sẽ làm hỏng phong bì
// và có thể lộ đường dẫn) — chỉ ghi vào log phía server.
ini_set('display_errors', '0');

require __DIR__ . '/lib/response.php';
require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/ratelimit.php';
require __DIR__ . '/lib/mailer.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/gradeauth.php';
require __DIR__ . '/lib/roles.php';
require __DIR__ . '/lib/attendance.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/admin.php';
require __DIR__ . '/lib/grading.php';
require __DIR__ . '/lib/khtd.php';
require __DIR__ . '/lib/actions.php';

// Khớp CONFIG.TIMEZONE cũ (Asia/Ho_Chi_Minh) — đặt sớm, không phụ thuộc
// việc nạp được config bí mật hay chưa, để action như `ping` vẫn chạy được.
date_default_timezone_set('Asia/Ho_Chi_Minh');

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    $params = $_GET;
} elseif ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string) $raw, true);
    $params = is_array($decoded) ? $decoded : [];
} else {
    // Request tự nó hỏng (không phải lỗi nghiệp vụ) → đổi mã HTTP thật sự,
    // theo đúng ngoại lệ nêu ở mục 1.
    http_response_code(405);
    api_fail('Phương thức không được hỗ trợ.');
    exit;
}

$action = trim((string) ($params['action'] ?? ''));

try {
    api_dispatch($action, $method, $params);
} catch (PDOException | Error $e) {
    // GĐ5 review bảo mật (docs/05-GD5-smoke-review.md, M1): lỗi CSDL (PDO)
    // và lỗi lập trình (TypeError…) KHÔNG trả nguyên văn cho client — thông
    // điệp PDO lộ tên cột, tên user CSDL ("Access denied for user …"). Ghi
    // chi tiết vào error_log phía server, trả thông báo chung.
    error_log('[api] ' . $action . ': ' . get_class($e) . ': ' . $e->getMessage());
    api_fail('Lỗi máy chủ. Vui lòng thử lại sau.');
} catch (Throwable $e) {
    // Lỗi nghiệp vụ do code tự ném (RuntimeException từ require_role(),
    // require_student(), assert_class_access()…) — thông điệp viết sẵn cho
    // người dùng, giữ nguyên như catch(err){return fail(err.message)} cũ.
    api_fail($e->getMessage());
}
