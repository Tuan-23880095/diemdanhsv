<?php
declare(strict_types=1);
/**
 * khtd/xuat-pdf.php — tải "phiếu đánh giá điểm" PDF (docs/04-API-PHP.md mục 31). CHỈ giảng viên/admin
 * có quyền với lớp. Token qua header X-Token (trang quanly.html tải bằng fetch) hoặc tham số token.
 *
 *   ?submissionId=SUB_…            một bài (đúng bản gửi kèm email cho sinh viên)
 *   ?worksheetId=WSH_…             cả lớp cho một phiếu (buổi): trang tổng hợp + phiếu của từng SV (lần được tính điểm)
 */
$root = dirname(__DIR__);
foreach (['response', 'config', 'db', 'audit', 'roles', 'mailer', 'ratelimit', 'khtd', 'khtd_stats', 'khtd_pdf'] as $lib) {
    require $root . '/api/lib/' . $lib . '.php';
}
date_default_timezone_set('Asia/Ho_Chi_Minh');

function khtd_xp_fail(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg;
    exit;
}

$token = trim((string) ($_SERVER['HTTP_X_TOKEN'] ?? ($_GET['token'] ?? '')));
try {
    $me = require_role($token, ['LECTURER', 'ADMIN']);
} catch (Throwable $e) {
    khtd_xp_fail(403, 'Chỉ giảng viên mới tải được phiếu điểm. ' . $e->getMessage());
}
khtd_long_request();
@ini_set('memory_limit', '512M');

try {
    $subId = trim((string) ($_GET['submissionId'] ?? ''));
    $wsId = trim((string) ($_GET['worksheetId'] ?? ''));
    if ($subId !== '') {
        $q = db()->prepare('SELECT w.ClassID FROM khtd_submissions sub JOIN khtd_worksheets w ON w.WorksheetID = sub.WorksheetID WHERE sub.SubmissionID = :id');
        $q->execute(['id' => $subId]);
        $classId = (string) ($q->fetchColumn() ?: '');
        if ($classId === '') khtd_xp_fail(404, 'Không tìm thấy bài nộp.');
        assert_class_access($me, $classId);
        $out = khtd_pdf_one($subId);
        $name = $out['name']; $data = $out['data'];
    } elseif ($wsId !== '') {
        $ws = khtd_worksheet($wsId);
        assert_class_access($me, (string) $ws['ClassID']);
        $stats = array_values(array_filter(khtd_class_stats((string) $ws['ClassID']), static fn ($x) => $x['worksheetId'] === $wsId))[0] ?? null;
        if (!$stats) khtd_xp_fail(404, 'Không tìm thấy phiếu.');
        $out = khtd_pdf_worksheet_all(khtd_class_info((string) $ws['ClassID']), $stats);
        $name = $out['name']; $data = $out['data'];
        log_audit((string) ($me['userId'] ?? ($me['UserID'] ?? '')), 'LECTURER', 'khtdExportPdf', 'worksheet', $wsId, ['students' => count(array_filter($stats['roster'], static fn ($r) => $r['submissionId']))]);
    } else {
        khtd_xp_fail(400, 'Thiếu submissionId hoặc worksheetId.');
    }
} catch (Throwable $e) {
    error_log('khtd xuat-pdf: ' . get_class($e) . ': ' . $e->getMessage());
    khtd_xp_fail(500, $e instanceof RuntimeException ? $e->getMessage() : 'Lỗi tạo PDF. Vui lòng thử lại.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($data));
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
echo $data;
