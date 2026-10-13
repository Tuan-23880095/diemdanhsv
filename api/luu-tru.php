<?php
declare(strict_types=1);
/**
 * api/luu-tru.php?classId=… — tải GÓI LƯU TRỮ (ZIP) của một lớp học phần về máy (docs/04-API-PHP.md mục 32).
 * Giảng viên của lớp hoặc admin; token qua header X-Token (trang pages/luu-tru.html dùng fetch).
 * Ghi nhận class_archives.PackagedAt — điều kiện để được xoá dữ liệu lớp (action archiveReset).
 */
$root = dirname(__DIR__);
foreach (['response', 'config', 'db', 'audit', 'ratelimit', 'mailer', 'auth', 'roles', 'grading', 'khtd', 'khtd_stats', 'khtd_pdf', 'archive'] as $lib) {
    require $root . '/api/lib/' . $lib . '.php';
}
date_default_timezone_set('Asia/Ho_Chi_Minh');

function luutru_fail(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg;
    exit;
}

$token = trim((string) ($_SERVER['HTTP_X_TOKEN'] ?? ''));
try {
    $me = require_role($token, ['LECTURER', 'ADMIN']);
    $classId = trim((string) ($_GET['classId'] ?? ''));
    archive_assert_access($me, $classId);
    $arc = archive_row($classId);
    if ($arc && $arc['Status'] === 'RESET') luutru_fail(409, 'Lớp này đã được xoá dữ liệu — gói lưu trữ chỉ còn trên máy đã tải về.');
} catch (Throwable $e) {
    luutru_fail(403, $e->getMessage());
}

@set_time_limit(340);
@ini_set('max_execution_time', '340');
@ini_set('memory_limit', '768M');
ignore_user_abort(false);
$tmp = archive_dir() . '/tmp-' . bin2hex(random_bytes(6)) . '.zip';
try {
    $pkg = archive_build_package($classId, $tmp);
    $rec = archive_record_package($classId, $pkg['name'], $tmp, $me['userId']);
} catch (Throwable $e) {
    @unlink($tmp);
    error_log('luu-tru: ' . get_class($e) . ': ' . $e->getMessage());
    luutru_fail(500, $e instanceof RuntimeException ? $e->getMessage() : 'Lỗi tạo gói lưu trữ. Vui lòng thử lại.');
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $pkg['name'] . '"');
header('Content-Length: ' . (string) filesize($tmp));
header('X-Package-Sha256: ' . $rec['sha256']);
header('Cache-Control: private, no-store');
readfile($tmp);
@unlink($tmp);
