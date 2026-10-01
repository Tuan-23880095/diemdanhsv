<?php
declare(strict_types=1);

/**
 * tools/compare_gas_php.php — So khớp dữ liệu Apps Script (file JSON xuất bởi
 * gas/13-ExportJSON.gs) với CSDL MariaDB, phục vụ checklist GĐ9 mục "số liệu
 * khớp" (PLAN GĐ9; docs/06-GD9-staging-checklist.md).
 *
 * CLI-ONLY (rule 3). CHỈ ĐỌC — không ghi gì vào CSDL, chạy bao nhiêu lần cũng được.
 * PHP thuần, không gọi shell (host cấm proc_open/exec/shell_exec).
 *
 * Cách dùng (THẦY chạy qua SSH, từ thư mục public_html):
 *   php tools/compare_gas_php.php --file=../private/import/diemdanhsv-export-....json
 *   php tools/compare_gas_php.php --file=... --config=../private/config.test.php
 *   php tools/compare_gas_php.php --file=... --table=students --show=20
 *
 * Báo cáo gồm, cho từng bảng:
 *   - số dòng trong file vs trong CSDL;
 *   - ID có trong file mà THIẾU trong CSDL (chưa import, hoặc import lỗi);
 *   - ID có trong CSDL mà KHÔNG có trong file (nhập thêm trên web sau khi xuất —
 *     bình thường nếu thầy đã dùng trang quản trị, bất thường nếu chưa);
 *   - số dòng LỆCH NỘI DUNG, kèm tên cột lệch của vài dòng đầu.
 *
 * So sánh "mềm" đúng chỗ, để không báo lệch oan:
 *   - ngày giờ: 'yyyy-MM-ddTHH:mm:ss' của GAS vs 'Y-m-d H:i:s' của MySQL;
 *   - số: 8 == 8.00 == '8,0';
 *   - chuỗi: trim, '' == null;
 *   - bỏ qua các cột KHÔNG so được: xem SKIP_COLUMNS bên dưới.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Chỉ chạy được từ CLI.');
}

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);

/**
 * Cột không so sánh nội dung:
 *  - PasswordHash/Salt: bản PHP ĐÃ REHASH khi giảng viên đăng nhập lần đầu
 *    (sha256 cũ → password_hash), nên lệch là ĐÚNG.
 *  - CreatedAt/UpdatedAt: MySQL tự đặt DEFAULT CURRENT_TIMESTAMP khi import.
 *  - Data (audit_log): JSON, thứ tự khoá có thể khác.
 */
const SKIP_COLUMNS = [
    'users'     => ['PasswordHash', 'Salt', 'CreatedAt'],
    'audit_log' => ['Data', 'Time'],
    '*'         => ['CreatedAt', 'UpdatedAt'],
];

/** Khoá chính từng bảng — phải khớp tools/import.php. */
const PK = [
    'users' => 'UserID', 'courses' => 'CourseID', 'classes' => 'ClassID',
    'students' => 'StudentID', 'enrollments' => 'EnrollmentID', 'sessions' => 'SessionID',
    'attendance_keys' => 'KeyID', 'grade_columns' => 'GradeColumnID',
    'attendance' => 'AttendanceID', 'grades' => 'GradeID', 'complaints' => 'ComplaintID',
    'audit_log' => 'LogID',
];

$opts = ['file' => null, 'config' => null, 'table' => null, 'show' => 5];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--file=')) { $opts['file'] = substr($arg, 7); continue; }
    if (str_starts_with($arg, '--config=')) { $opts['config'] = substr($arg, 9); continue; }
    if (str_starts_with($arg, '--table=')) { $opts['table'] = substr($arg, 8); continue; }
    if (str_starts_with($arg, '--show=')) { $opts['show'] = max(0, (int) substr($arg, 7)); continue; }
    fwrite(STDERR, "Tham số không rõ: $arg\n");
    exit(2);
}

if (!$opts['file'] || !is_file($opts['file'])) {
    fwrite(STDERR, "Thiếu --file=<đường dẫn JSON xuất từ Apps Script>. Xem hướng dẫn ở đầu file này.\n");
    exit(2);
}
if ($opts['config'] !== null) {
    if (!is_file($opts['config'])) {
        fwrite(STDERR, "Không thấy file cấu hình: {$opts['config']}\n");
        exit(2);
    }
    putenv('DIEMDANH_CONFIG=' . realpath($opts['config']));
}

require $root . '/api/lib/config.php';
require $root . '/api/lib/db.php';

$payload = json_decode((string) file_get_contents($opts['file']), true);
if (!is_array($payload) || !is_array($payload['data'] ?? null)) {
    fwrite(STDERR, "File JSON không đúng khuôn (thiếu khoá \"data\"). Phải là file do exportAllToDriveJSON() tạo.\n");
    exit(2);
}

try {
    $cfg = app_config()['db'] ?? [];
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

/* ------------------------------------------------------------------ */
/*  So sánh mềm                                                        */
/* ------------------------------------------------------------------ */

/** Chuẩn hoá một giá trị để so sánh GAS ↔ MySQL. */
function cmp_norm($v): string
{
    if ($v === null) {
        return '';
    }
    if (is_bool($v)) {
        return $v ? '1' : '0';
    }
    $s = trim((string) $v);
    if ($s === '') {
        return '';
    }
    // Ngày giờ: 2026-10-02T07:30:00 ↔ 2026-10-02 07:30:00 (bỏ phần giây .000)
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}(?::\d{2})?)/', $s, $m)) {
        $time = strlen($m[2]) === 5 ? $m[2] . ':00' : $m[2];
        return $m[1] . ' ' . $time;
    }
    // Số: 8 ↔ 8.00 ↔ "8,0"
    $num = str_replace(',', '.', $s);
    if (is_numeric($num)) {
        return rtrim(rtrim(number_format((float) $num, 6, '.', ''), '0'), '.');
    }
    return $s;
}

function skipped_columns(string $table): array
{
    return array_merge(SKIP_COLUMNS['*'] ?? [], SKIP_COLUMNS[$table] ?? []);
}

/* ------------------------------------------------------------------ */
/*  Chạy                                                               */
/* ------------------------------------------------------------------ */

echo "Nguồn JSON : {$opts['file']}\n";
echo "Xuất lúc   : " . ($payload['exportedAt'] ?? '?') . "\n";
echo "CSDL đích  : " . ($cfg['name'] ?? '?') . '@' . ($cfg['host'] ?? '?') . "\n";
echo "CHỈ ĐỌC — không ghi gì vào CSDL.\n";
echo str_repeat('=', 96) . "\n";
printf("%-17s %9s %9s %9s %9s %9s\n", 'Bảng', 'File', 'CSDL', 'Thiếu', 'Thừa', 'Lệch');
echo str_repeat('-', 96) . "\n";

$tables = $opts['table'] !== null ? [$opts['table']] : array_keys(PK);
$details = [];
$totals = ['missing' => 0, 'extra' => 0, 'diff' => 0];

foreach ($tables as $table) {
    if (!isset(PK[$table])) {
        fwrite(STDERR, "Bảng \"$table\" không có trong danh sách so khớp.\n");
        exit(2);
    }
    $pk = PK[$table];
    $fileRows = is_array($payload['data'][$table] ?? null) ? $payload['data'][$table] : [];

    $fileById = [];
    foreach ($fileRows as $r) {
        $id = trim((string) ($r[$pk] ?? ''));
        if ($id !== '') {
            $fileById[$id] = $r;
        }
    }

    try {
        $dbRows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        fwrite(STDERR, "Lỗi đọc bảng $table: " . $e->getMessage() . "\n");
        exit(1);
    }
    $dbById = [];
    foreach ($dbRows as $r) {
        $dbById[trim((string) $r[$pk])] = $r;
    }

    $missing = array_keys(array_diff_key($fileById, $dbById));
    $extra   = array_keys(array_diff_key($dbById, $fileById));
    $skip = skipped_columns($table);

    $diffs = [];
    foreach (array_intersect_key($fileById, $dbById) as $id => $fileRow) {
        $dbRow = $dbById[$id];
        $bad = [];
        foreach ($dbRow as $col => $dbVal) {
            if (in_array($col, $skip, true)) continue;
            if (!array_key_exists($col, $fileRow)) continue; // cột mới của bản PHP
            if (cmp_norm($fileRow[$col]) !== cmp_norm($dbVal)) {
                $bad[] = $col . ': GAS="' . mb_substr(trim((string) $fileRow[$col]), 0, 40) .
                    '" ≠ CSDL="' . mb_substr(trim((string) $dbVal), 0, 40) . '"';
            }
        }
        if ($bad) {
            $diffs[$id] = $bad;
        }
    }

    printf("%-17s %9d %9d %9d %9d %9d\n", $table, count($fileById), count($dbById), count($missing), count($extra), count($diffs));
    $totals['missing'] += count($missing);
    $totals['extra'] += count($extra);
    $totals['diff'] += count($diffs);
    $details[$table] = ['missing' => $missing, 'extra' => $extra, 'diffs' => $diffs];
}

echo str_repeat('-', 96) . "\n";

if ($opts['show'] > 0) {
    foreach ($details as $table => $d) {
        if (!$d['missing'] && !$d['extra'] && !$d['diffs']) continue;
        echo "\n### $table\n";
        if ($d['missing']) {
            echo "  THIẾU trong CSDL (" . count($d['missing']) . "): " .
                implode(', ', array_slice($d['missing'], 0, $opts['show'])) . (count($d['missing']) > $opts['show'] ? ' …' : '') . "\n";
        }
        if ($d['extra']) {
            echo "  THỪA trong CSDL (" . count($d['extra']) . ", có thể do nhập trên web sau khi xuất): " .
                implode(', ', array_slice($d['extra'], 0, $opts['show'])) . (count($d['extra']) > $opts['show'] ? ' …' : '') . "\n";
        }
        $n = 0;
        foreach ($d['diffs'] as $id => $bad) {
            if ($n++ >= $opts['show']) {
                echo "  … và " . (count($d['diffs']) - $opts['show']) . " dòng lệch khác\n";
                break;
            }
            echo "  LỆCH $id: " . implode('; ', array_slice($bad, 0, 4)) . (count($bad) > 4 ? ' …' : '') . "\n";
        }
        $skip = skipped_columns($table);
        if ($skip) {
            echo "  (bỏ qua cột: " . implode(', ', $skip) . ")\n";
        }
    }
}

$ok = $totals['missing'] === 0 && $totals['diff'] === 0;
echo "\n" . ($ok
    ? "KẾT LUẬN: KHỚP — không thiếu dòng nào, không lệch nội dung" .
      ($totals['extra'] ? " (có {$totals['extra']} dòng CSDL không nằm trong file — xem ở trên)" : '') . ".\n"
    : "KẾT LUẬN: CHƯA KHỚP — thiếu {$totals['missing']} dòng, lệch {$totals['diff']} dòng. " .
      "Chạy lại tools/import.php với đúng file này rồi so khớp lại.\n");
exit($ok ? 0 : 1);
