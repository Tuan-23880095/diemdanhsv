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

require __DIR__ . '/lib/response.php';
require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/mailer.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/gradeauth.php';
require __DIR__ . '/lib/roles.php';
require __DIR__ . '/lib/attendance.php';
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
} catch (Throwable $e) {
    // Tương đương catch(err){return fail(err.message)} của doGet/doPost cũ.
    api_fail($e->getMessage());
}
