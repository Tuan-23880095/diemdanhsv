<?php
declare(strict_types=1);
/**
 * khtd/anh.php?id=PHO_xxxxxxxxxxxx — trả ảnh mẫu vật sinh viên tải lên trong phiếu online
 * (docs/04-API-PHP.md mục 30). Tệp nằm ngoài public_html; chỉ xem được khi:
 *   - token sinh viên (KHTD/GRADE) của CHÍNH chủ ảnh, hoặc
 *   - token giảng viên/admin có quyền với lớp của phiếu.
 * Token nhận qua header X-Token (trang gửi bằng fetch) hoặc tham số token.
 */
$root = dirname(__DIR__);
require $root . '/api/lib/config.php';
require $root . '/api/lib/db.php';
require $root . '/api/lib/roles.php';
require $root . '/api/lib/khtd.php';

function khtd_anh_fail(int $code): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $code === 404 ? 'Khong tim thay anh.' : 'Khong co quyen xem anh nay.';
    exit;
}

$token = trim((string) ($_SERVER['HTTP_X_TOKEN'] ?? ($_GET['token'] ?? '')));
$id = (string) ($_GET['id'] ?? '');
if ($token === '' || !preg_match('/^PHO_[A-F0-9]{12}$/', $id)) khtd_anh_fail(403);

try {
    $stmt = db()->prepare('SELECT p.*, w.ClassID FROM khtd_photos p JOIN khtd_worksheets w ON w.WorksheetID = p.WorksheetID WHERE p.PhotoID = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    error_log('khtd anh: ' . $e->getMessage());
    khtd_anh_fail(404);
}
if (!$row) khtd_anh_fail(404);

$allowed = false;
try {
    $st = khtd_require_student($token);
    $allowed = $st['studentId'] === (string) $row['StudentID'];
} catch (Throwable $e) { /* không phải token sinh viên */ }
if (!$allowed) {
    try {
        $me = require_role($token, ['LECTURER', 'ADMIN']);
        assert_class_access($me, (string) $row['ClassID']);
        $allowed = true;
    } catch (Throwable $e) { /* không có quyền */ }
}
if (!$allowed) khtd_anh_fail(403);

$path = khtd_upload_dir() . '/' . basename((string) $row['WorksheetID']) . '/' . basename((string) $row['FileName']);
if (!is_file($path)) khtd_anh_fail(404);

header('Content-Type: ' . (in_array($row['Mime'], ['image/jpeg', 'image/png', 'image/webp'], true) ? $row['Mime'] : 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
readfile($path);
