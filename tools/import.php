<?php
declare(strict_types=1);

/**
 * tools/import.php — Di dời dữ liệu từ file JSON xuất bởi
 * gas/13-ExportJSON.gs (exportAllToDriveJSON()) sang MariaDB (PLAN GĐ6).
 *
 * CLI-ONLY (rule 3 của dự án) — không có token/vai trò, đụng toàn bộ CSDL,
 * nên tuyệt đối không được gọi qua web. THẦY chạy qua SSH trên host thật.
 *
 * Cách dùng:
 *   php tools/import.php --file=../private/import/diemdanhsv-export-....json --dry-run
 *   php tools/import.php --file=../private/import/diemdanhsv-export-....json --yes
 *   php tools/import.php --file=... --dry-run --only=students,enrollments
 *
 * Idempotent: mỗi bảng dùng INSERT ... ON DUPLICATE KEY UPDATE theo khoá
 * chính (đúng ID đã gán từ Apps Script, không sinh ID mới) — chạy lại cùng
 * file JSON nhiều lần an toàn, không tạo dòng trùng.
 *
 * --dry-run: KHÔNG ghi gì vào CSDL. Chỉ đọc (SELECT khoá chính hiện có) rồi
 * so với file để báo "sẽ thêm mới / sẽ cập nhật" theo từng bảng, cộng cảnh
 * báo tham chiếu khoá ngoại lạ (ID tham chiếu tới một ID không có cả trong
 * CSDL lẫn trong file) — đây là tiêu chí "Xong khi" của GĐ6 trong PLAN.
 *
 * --yes: bắt buộc để THỰC SỰ ghi (không có cờ này thì dù bỏ --dry-run script
 * vẫn chỉ in ra "cần thêm --yes" rồi dừng — tránh ghi nhầm vào CSDL thật).
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Chỉ chạy được từ CLI.');
}

require __DIR__ . '/../api/lib/config.php';
require __DIR__ . '/../api/lib/db.php';
require __DIR__ . '/../api/lib/audit.php';

/**
 * Định nghĩa 12 bảng cũ (thứ tự ĐÚNG phụ thuộc khoá ngoại — PHẢI khớp
 * EXPORT_TABLE_ORDER trong gas/13-ExportJSON.gs). Không gồm auth_tokens/
 * grade_codes (2 bảng mới của bản PHP, không có sheet nguồn).
 *
 * cols: tên cột => kiểu ('text' | 'number' | 'date' | 'datetime')
 * pk:   tên (các) cột khoá chính, dùng cho ON DUPLICATE KEY UPDATE
 * fk:   tên cột => bảng được tham chiếu (bỏ qua kiểm tra nếu giá trị rỗng/null)
 */
const IMPORT_TABLES = [
    'users' => [
        'pk' => ['UserID'],
        'cols' => [
            'UserID' => 'text', 'Username' => 'text', 'FullName' => 'text',
            'Email' => 'text', 'PasswordHash' => 'text', 'Salt' => 'text',
            'Role' => 'text', 'Status' => 'text', 'CreatedAt' => 'datetime',
        ],
        'fk' => [],
    ],
    'courses' => [
        'pk' => ['CourseID'],
        'cols' => [
            'CourseID' => 'text', 'CourseCode' => 'text', 'CourseName' => 'text',
            'Credits' => 'number', 'TheoryHours' => 'number', 'PracticeHours' => 'number',
            'Status' => 'text', 'CreatedAt' => 'datetime',
        ],
        'fk' => [],
    ],
    'classes' => [
        'pk' => ['ClassID'],
        'cols' => [
            'ClassID' => 'text', 'CourseID' => 'text', 'LecturerID' => 'text',
            'ClassCode' => 'text', 'Semester' => 'text', 'AcademicYear' => 'text',
            'RoomLat' => 'number', 'RoomLng' => 'number', 'AllowedRadiusM' => 'number',
            'Status' => 'text', 'CreatedAt' => 'datetime',
        ],
        'fk' => ['CourseID' => 'courses', 'LecturerID' => 'users'],
    ],
    'students' => [
        'pk' => ['StudentID'],
        'cols' => [
            'StudentID' => 'text', 'MSSV' => 'text', 'FullName' => 'text',
            'Email' => 'text', 'Status' => 'text', 'CreatedAt' => 'datetime',
        ],
        'fk' => [],
    ],
    'enrollments' => [
        'pk' => ['EnrollmentID'],
        'cols' => [
            'EnrollmentID' => 'text', 'StudentID' => 'text', 'ClassID' => 'text',
            'Status' => 'text', 'CreatedAt' => 'datetime',
        ],
        'fk' => ['StudentID' => 'students', 'ClassID' => 'classes'],
    ],
    'sessions' => [
        'pk' => ['SessionID'],
        'cols' => [
            'SessionID' => 'text', 'ClassID' => 'text', 'SessionNo' => 'sessionno',
            'Date' => 'date', 'DayOfWeek' => 'text', 'StartTime' => 'time',
            'EndTime' => 'time', 'Content' => 'text', 'Status' => 'text',
            'CreatedAt' => 'datetime',
        ],
        'fk' => ['ClassID' => 'classes'],
    ],
    'attendance_keys' => [
        'pk' => ['KeyID'],
        'cols' => [
            'KeyID' => 'text', 'SessionID' => 'text', 'Code' => 'text',
            'StartTime' => 'datetime', 'LateAfter' => 'datetime', 'EndTime' => 'datetime',
            'Status' => 'text', 'CreatedBy' => 'text', 'CreatedAt' => 'datetime',
        ],
        'fk' => ['SessionID' => 'sessions', 'CreatedBy' => 'users'],
    ],
    'grade_columns' => [
        'pk' => ['GradeColumnID'],
        'cols' => [
            'GradeColumnID' => 'text', 'ClassID' => 'text', 'Name' => 'text',
            'Weight' => 'number', 'SortOrder' => 'number', 'Status' => 'text',
            'CreatedAt' => 'datetime',
        ],
        'fk' => ['ClassID' => 'classes'],
    ],
    'attendance' => [
        'pk' => ['AttendanceID'],
        'cols' => [
            'AttendanceID' => 'text', 'StudentID' => 'text', 'SessionID' => 'text',
            'Status' => 'text', 'CheckInTime' => 'datetime', 'GpsLat' => 'number',
            'GpsLng' => 'number', 'GpsAccuracy' => 'number', 'DistanceM' => 'number',
            'GpsFlag' => 'text', 'IP' => 'text', 'OS' => 'text', 'Browser' => 'text',
            'DeviceType' => 'text', 'DeviceHash' => 'text', 'Note' => 'text',
            'CreatedAt' => 'datetime',
        ],
        'fk' => ['StudentID' => 'students', 'SessionID' => 'sessions'],
    ],
    'grades' => [
        'pk' => ['GradeID'],
        'cols' => [
            'GradeID' => 'text', 'StudentID' => 'text', 'ClassID' => 'text',
            'GradeColumnID' => 'text', 'Score' => 'number', 'UpdatedBy' => 'text',
            'UpdatedAt' => 'datetime',
        ],
        'fk' => ['StudentID' => 'students', 'ClassID' => 'classes', 'GradeColumnID' => 'grade_columns'],
    ],
    'complaints' => [
        'pk' => ['ComplaintID'],
        'cols' => [
            'ComplaintID' => 'text', 'StudentID' => 'text', 'ClassID' => 'text',
            'Type' => 'text', 'TargetID' => 'text', 'Content' => 'text',
            'CreatedAt' => 'datetime', 'Status' => 'text', 'Response' => 'text',
            'ResolvedBy' => 'text', 'ResolvedAt' => 'datetime',
        ],
        'fk' => ['StudentID' => 'students', 'ClassID' => 'classes', 'ResolvedBy' => 'users'],
    ],
    'audit_log' => [
        'pk' => ['LogID'],
        'cols' => [
            'LogID' => 'text', 'Time' => 'datetime', 'Actor' => 'text',
            'ActorRole' => 'text', 'Action' => 'text', 'TargetType' => 'text',
            'TargetID' => 'text', 'Data' => 'text', 'IP' => 'text',
        ],
        'fk' => [],
    ],
];

/* --------------------------------------------------------------- */
/* Tiện ích                                                         */
/* --------------------------------------------------------------- */

function import_args(array $argv): array
{
    $out = ['file' => null, 'dry_run' => false, 'yes' => false, 'only' => null];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') { $out['dry_run'] = true; continue; }
        if ($arg === '--yes') { $out['yes'] = true; continue; }
        if (str_starts_with($arg, '--file=')) { $out['file'] = substr($arg, 7); continue; }
        if (str_starts_with($arg, '--only=')) {
            $out['only'] = array_filter(array_map('trim', explode(',', substr($arg, 7))));
            continue;
        }
        fwrite(STDERR, "Tham số không rõ: $arg\n");
    }
    return $out;
}

/** '' hoặc null -> null; chuỗi có 'T' -> thay bằng khoảng trắng; chỉ ngày -> thêm 00:00:00. */
function import_norm_datetime($v): ?string
{
    if ($v === null) return null;
    $v = trim((string) $v);
    if ($v === '') return null;
    $v = str_replace('T', ' ', $v);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        $v .= ' 00:00:00';
    }
    return $v;
}

/** Chỉ lấy phần ngày (yyyy-MM-dd); nhận cả 'dd/MM/yyyy' do có thể nhập tay trong Sheets. */
function import_norm_date($v): ?string
{
    if ($v === null) return null;
    $v = trim((string) $v);
    if ($v === '') return null;
    if (str_contains($v, 'T')) {
        return substr($v, 0, strpos($v, 'T'));
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $v, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
        return substr($v, 0, 10);
    }
    return $v; // không khớp mẫu nào — để nguyên, MySQL sẽ báo lỗi rõ ràng nếu sai
}

/**
 * Giờ 'HH:MM' cho cột VARCHAR(5) (sessions.StartTime/EndTime). Google Sheets lưu
 * ô "chỉ giờ" thành ngày giờ trên mốc 1899-12-30 ("1899-12-30T08:00:00") — phát
 * hiện khi nạp dữ liệu thật 02/10/2026: để kiểu 'text' thì MariaDB cắt còn
 * "1899-" cho CẢ 33 buổi. Nhận: "1899-12-30T08:00:00", "2026-09-07T08:00:00",
 * "08:00:00", "8:00", "08:00". Không hiểu được → NULL (cột cho phép NULL), không
 * ghi rác.
 */
function import_norm_time($v): ?string
{
    if ($v === null) return null;
    $v = trim((string) $v);
    if ($v === '') return null;
    if (preg_match('/T(\d{1,2}):(\d{2})/', $v, $m) || preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $v, $m)) {
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59) {
            return sprintf('%02d:%02d', $h, $i);
        }
    }
    return null;
}

/** Số buổi "END" (buổi tổng kết, thầy ghi tay trong Sheets) → 99 để xếp CUỐI danh sách. */
const IMPORT_SESSIONNO_END = 99;

/**
 * sessions.SessionNo là INT. Trong Sheets thầy ghi "END" cho buổi tổng kết →
 * ép kiểu thành 0 và buổi đó nhảy lên ĐẦU danh sách (ORDER BY SessionNo).
 * Nay: số → giữ; không phải số (END, trống…) → IMPORT_SESSIONNO_END. Thầy chọn
 * cách này 02/10/2026 (thay vì sửa Sheet); sửa lại số buổi trên trang quản trị
 * bất cứ lúc nào.
 */
function import_norm_sessionno($v): string
{
    $v = trim((string) ($v ?? ''));
    if ($v !== '' && is_numeric($v)) {
        return (string) (int) $v;
    }
    return (string) IMPORT_SESSIONNO_END;
}

function import_norm_number($v): ?string
{
    if ($v === null) return null;
    $v = trim((string) $v);
    return $v === '' ? null : $v;
}

function import_norm_text($v): string
{
    return $v === null ? '' : (string) $v;
}

/** Chuẩn hoá một dòng theo định nghĩa cột của bảng. Cột thiếu trong file -> rỗng/null hợp lệ. */
function import_norm_row(array $row, array $colDefs): array
{
    $out = [];
    foreach ($colDefs as $col => $type) {
        $v = $row[$col] ?? null;
        $out[$col] = match ($type) {
            'number' => import_norm_number($v),
            'date' => import_norm_date($v),
            'datetime' => import_norm_datetime($v),
            'time' => import_norm_time($v),
            'sessionno' => import_norm_sessionno($v),
            default => import_norm_text($v),
        };
    }
    return $out;
}

/* --------------------------------------------------------------- */
/* Chương trình chính                                                */
/* --------------------------------------------------------------- */

$args = import_args($argv);

if (!$args['file']) {
    fwrite(STDERR, "Thiếu --file=<đường dẫn JSON>. Xem hướng dẫn ở đầu file này.\n");
    exit(1);
}
if (!is_file($args['file'])) {
    fwrite(STDERR, "Không tìm thấy file: {$args['file']}\n");
    exit(1);
}

$raw = file_get_contents($args['file']);
$payload = json_decode($raw, true);
if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
    fwrite(STDERR, "File JSON không đúng khuôn dạng mong đợi (thiếu khoá \"data\").\n");
    exit(1);
}
if (($payload['version'] ?? null) !== 'gas-export-1') {
    fwrite(STDERR, "Cảnh báo: version file (\"" . ($payload['version'] ?? '?') . "\") khác \"gas-export-1\" — có thể không tương thích.\n");
}

$tableNames = array_keys(IMPORT_TABLES);
if ($args['only']) {
    $tableNames = array_values(array_intersect($tableNames, $args['only']));
    if (!$tableNames) {
        fwrite(STDERR, "--only không khớp bảng nào trong danh sách GĐ6.\n");
        exit(1);
    }
}

$pdo = db();
$knownIds = []; // bảng => set ID (đã có trong CSDL HOẶC có trong file, theo thứ tự xử lý)

echo "Nguồn: {$args['file']}\n";
echo "Xuất lúc: " . ($payload['exportedAt'] ?? '?') . "\n";
echo ($args['dry_run'] ? "CHẾ ĐỘ: DRY-RUN (không ghi gì vào CSDL)\n" : "CHẾ ĐỘ: GHI THẬT\n");
echo str_repeat('-', 92) . "\n";
printf("%-16s %10s %10s %10s %10s %10s\n", 'Bảng', 'TrongFile', 'ĐãCóCSDL', 'SẽThêm', 'SẽCậpNhật', 'CảnhBáoFK');
echo str_repeat('-', 92) . "\n";

$summary = [];

foreach ($tableNames as $table) {
    $def = IMPORT_TABLES[$table];
    $rowsRaw = $payload['data'][$table] ?? [];
    if (!is_array($rowsRaw)) $rowsRaw = [];

    $pkCol = $def['pk'][0]; // cả 12 bảng GĐ6 đều khoá chính 1 cột (VARCHAR ID)

    // L13 (review lần 2): chỉ ghi những cột THẬT SỰ có trong file. Trước đây
    // mọi cột của IMPORT_TABLES đều vào INSERT … ON DUPLICATE KEY UPDATE, nên
    // chạy lại với một bản export thiếu cột (sheet bớt cột, hoặc export cũ)
    // là cột đó trong CSDL bị ghi trắng cho mọi dòng đã có. Cột xuất hiện ở
    // bất kỳ dòng nào của bảng → coi là có; khoá chính luôn có.
    $presentCols = [];
    foreach ($rowsRaw as $r) {
        if (!is_array($r)) continue;
        foreach ($def['cols'] as $col => $_type) {
            if (array_key_exists($col, $r)) $presentCols[$col] = true;
        }
    }
    foreach ($def['pk'] as $pc) $presentCols[$pc] = true;
    $colDefs = array_intersect_key($def['cols'], $presentCols);
    $missingCols = array_keys(array_diff_key($def['cols'], $presentCols));
    if ($rowsRaw && $missingCols) {
        echo "  (!) $table: file không có cột " . implode(', ', $missingCols) . " — giữ nguyên giá trị đang có trong CSDL, không ghi trắng.\n";
    }

    $rows = array_map(fn($r) => import_norm_row($r, $colDefs), $rowsRaw);

    $fileIds = array_map(fn($r) => $r[$pkCol], $rows);
    $fileIdSet = array_fill_keys($fileIds, true);

    // ID đã có trong CSDL
    $existingIds = [];
    try {
        $stmt = $pdo->query("SELECT `$pkCol` FROM `$table`");
        $existingIds = $stmt ? array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true) : [];
    } catch (Throwable $e) {
        fwrite(STDERR, "Lỗi đọc bảng $table: " . $e->getMessage() . "\n");
        exit(1);
    }

    $willInsert = count(array_diff_key($fileIdSet, $existingIds));
    $willUpdate = count(array_intersect_key($fileIdSet, $existingIds));

    // Cộng dồn tập ID đã biết (CSDL hiện có ∪ file) để bảng sau kiểm FK
    $knownIds[$table] = $existingIds + $fileIdSet;

    // Kiểm tham chiếu khoá ngoại lạ (không có cả trong CSDL lẫn trong file các bảng đã xử lý trước)
    $fkWarnings = 0;
    foreach ($def['fk'] as $col => $refTable) {
        $refSet = $knownIds[$refTable] ?? [];
        foreach ($rows as $r) {
            $v = $r[$col] ?? null;
            if ($v === null || $v === '') continue; // FK rỗng cho phép (cột nullable)
            if (!isset($refSet[$v])) $fkWarnings++;
        }
    }

    printf("%-16s %10d %10d %10d %10d %10d\n", $table, count($rows), count($existingIds), $willInsert, $willUpdate, $fkWarnings);
    $summary[$table] = ['rows' => $rows, 'def' => $def, 'cols' => array_keys($colDefs), 'willInsert' => $willInsert, 'willUpdate' => $willUpdate, 'fkWarnings' => $fkWarnings];
}

echo str_repeat('-', 92) . "\n";

$totalFkWarnings = array_sum(array_column($summary, 'fkWarnings'));
if ($totalFkWarnings > 0) {
    echo "CẢNH BÁO: có $totalFkWarnings dòng tham chiếu tới ID không thấy trong CSDL lẫn trong file này.\n";
    echo "Xem lại trước khi chạy thật — nếu chạy thật, CSDL sẽ TỪ CHỐI (lỗi khoá ngoại) và KHÔNG ghi dòng nào (cả file nằm trong một transaction).\n";
}

if ($args['dry_run']) {
    echo "Dry-run xong — không có gì được ghi. Đối chiếu số \"TrongFile\" ở trên với số dòng gốc trong Google Sheets trước khi chạy thật.\n";
    exit(0);
}

if (!$args['yes']) {
    echo "\nĐây là lần chạy THẬT nhưng thiếu --yes — KHÔNG ghi gì. Thêm --yes vào lệnh để xác nhận.\n";
    exit(0);
}

try {
    db_transaction(function (PDO $pdo) use ($summary) {
        foreach ($summary as $table => $info) {
            $def = $info['def'];
            $cols = $info['cols']; // L13: chỉ cột có trong file (khoá chính luôn có)
            $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
            $placeholders = implode(', ', array_map(fn($c) => ":$c", $cols));
            $updateList = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", array_diff($cols, $def['pk'])));

            $sql = "INSERT INTO `$table` ($colList) VALUES ($placeholders)" .
                   ($updateList !== '' ? " ON DUPLICATE KEY UPDATE $updateList" : " ON DUPLICATE KEY UPDATE `{$def['pk'][0]}` = `{$def['pk'][0]}`");
            $stmt = $pdo->prepare($sql);

            foreach ($info['rows'] as $row) {
                $stmt->execute($row);
            }
        }

        log_audit('cli:import.php', 'ADMIN', 'TOOLS_IMPORT_GD6', 'SYSTEM', null, [
            'tables' => array_map(fn($t, $i) => ['table' => $t, 'rows' => count($i['rows'])], array_keys($summary), $summary),
        ]);
    });
} catch (Throwable $e) {
    fwrite(STDERR, "THẤT BẠI — đã rollback toàn bộ (không ghi dòng nào): " . $e->getMessage() . "\n");
    exit(1);
}

echo "Đã ghi xong (transaction đơn, toàn bộ hoặc không gì cả):\n";
foreach ($summary as $table => $info) {
    echo "- $table: " . count($info['rows']) . " dòng nạp/cập nhật\n";
}
echo "\nNHỚ: xoá file JSON nguồn khỏi host sau khi xác nhận dữ liệu đúng (chứa dữ liệu thật, rule 2 của dự án).\n";
