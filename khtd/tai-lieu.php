<?php
declare(strict_types=1);
/**
 * khtd/tai-lieu.php — phục vụ tài liệu CHỈ dành cho giảng viên trong khtd/_gv/
 * (hồ sơ nộp Khoa, đề thi). Thư mục _gv/ bị .htaccess chặn truy cập trực tiếp;
 * mọi lượt xem phải qua đây và phải có token LECTURER/ADMIN còn hạn.
 * Token nhận qua header X-Token (khtd/gv.html gửi bằng fetch) hoặc tham số token.
 */
$root = dirname(__DIR__);
require $root . '/api/lib/config.php';
require $root . '/api/lib/db.php';
require $root . '/api/lib/roles.php';

function khtd_tl_fail(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Khong xem duoc</title>'
       . '<p style="font-family:system-ui,sans-serif;margin:2rem;max-width:40rem">'
       . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

$token = trim((string) ($_SERVER['HTTP_X_TOKEN'] ?? ($_GET['token'] ?? '')));
try {
    require_role($token, ['LECTURER', 'ADMIN']);
} catch (Throwable $e) {
    khtd_tl_fail(403, 'Tài liệu này chỉ dành cho giảng viên. ' . $e->getMessage());
}

$f = (string) ($_GET['f'] ?? '');
if ($f === '' || str_contains($f, '..') || !preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*\.html$#', $f)) {
    khtd_tl_fail(400, 'Tên tài liệu không hợp lệ.');
}
$base = realpath(__DIR__ . '/_gv');
$real = realpath(__DIR__ . '/_gv/' . $f);
if ($base === false || $real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
    khtd_tl_fail(404, 'Không tìm thấy tài liệu.');
}
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');
readfile($real);
