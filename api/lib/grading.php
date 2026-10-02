<?php
declare(strict_types=1);

/**
 * api/lib/grading.php — GĐ8 (PLAN): điểm chuyên cần tự động, nhập điểm CSV,
 * nhập tay điểm danh buổi cũ. Thay gas/10-AttendanceScore.gs, 09-GradeImport.gs,
 * 12-ManualAttendance.gs. Đối chiếu đầy đủ tại docs/04-API-PHP.md mục 17.
 *
 * Mọi action đều cần token LECTURER/ADMIN + assert_class_access (đúng ranh
 * giới rule 3 thầy quyết 01/10/2026: có vai trò + đúng lớp mình → web).
 *
 * CÔNG THỨC CHUYÊN CẦN — thầy chốt 02/10/2026 (thay rubric ĐG1.6 của bản GAS):
 *   - Thang 10, trọng số 10% tổng điểm.
 *   - Mỗi buổi: vắng không phép −3, vắng có phép −1,5, trễ −1. Thấp nhất 0.
 *   - Cấm thi khi số "vắng không phép tương đương" ≥ 3, với
 *       tương đương = vắng + floor(trễ / 3) + floor(có phép / 2)
 *     (3 trễ = 1 vắng; 2 có phép = 1 vắng — khớp đúng mức trừ điểm).
 *   - Chỉ tính trên buổi ĐÃ điểm danh (có ít nhất một bản ghi attendance);
 *     sinh viên không có bản ghi ở buổi đó = vắng không phép (giữ như bản GAS).
 *   Mọi hằng số đọc từ app_config()['app']['attendance_rules'] nếu có.
 *
 * TRỌNG SỐ ĐIỂM — lưu theo PHẦN TRĂM trong grade_columns.Weight (đúng
 * parseGradeHeader_ cũ "Giữa kỳ (20%)" → 20). Khuôn thầy chốt: Cuối kỳ 50,
 * Giữa kỳ 20, Thường xuyên 20, Điểm cộng 10, Chuyên cần 10 — tổng 110%,
 * điểm tổng = Σ điểm×trọng số/100, QUY VỀ TỐI ĐA 10 (grading_total()).
 */

const GRADING_CSV_MAX_BYTES = 512 * 1024;  // ~512 KB, quá đủ cho 1000 dòng × 10 cột
const GRADING_MAX_ROWS = 1000;             // số dòng dữ liệu tối đa mỗi lần nhập
const GRADING_MAX_ERRORS_REPORTED = 200;   // không json_encode hàng triệu lỗi

/** Hằng số công thức, cho phép ghi đè trong config bí mật (app.attendance_rules). */
function attendance_rules(): array
{
    static $rules = null;
    if ($rules === null) {
        $defaults = [
            'absent_penalty'      => 3.0,   // vắng không phép
            'excused_penalty'     => 1.5,   // vắng có phép
            'late_penalty'        => 1.0,   // trễ
            'late_per_absence'    => 3,     // 3 trễ = 1 vắng (xét cấm thi)
            'excused_per_absence' => 2,     // 2 có phép = 1 vắng (xét cấm thi)
            'ban_threshold'       => 3,     // ≥ 3 vắng tương đương → cấm thi
            'max_score'           => 10.0,
            'column_name'         => 'Chuyên cần',
            'column_weight'       => 10.0,  // %
        ];
        $cfg = app_config()['app']['attendance_rules'] ?? [];
        $rules = is_array($cfg) ? array_merge($defaults, $cfg) : $defaults;
    }
    return $rules;
}

/**
 * Thống kê chuyên cần của MỌI sinh viên ACTIVE trong lớp (chỉ buổi đã điểm danh).
 *
 * @return array{sessionsCounted:int, sessionIds:string[], rows:array<string,array>} rows theo StudentID:
 *   {studentId, mssv, fullName, present, late, absent, excused, score, equivalentAbsences, banned}
 */
function attendance_stats(string $classId): array
{
    $r = attendance_rules();

    // Buổi ACTIVE của lớp có ít nhất một bản ghi attendance = "đã điểm danh".
    $stmt = db()->prepare(
        "SELECT DISTINCT s.SessionID FROM sessions s " .
        "JOIN attendance a ON a.SessionID = s.SessionID " .
        "WHERE s.ClassID = :cid AND s.Status = 'ACTIVE'"
    );
    $stmt->execute(['cid' => $classId]);
    $sessionIds = array_map(static fn ($x) => (string) $x['SessionID'], $stmt->fetchAll());
    $n = count($sessionIds);

    $stmt = db()->prepare(
        "SELECT s.StudentID, s.MSSV, s.FullName FROM enrollments en " .
        "JOIN students s ON s.StudentID = en.StudentID " .
        "WHERE en.ClassID = :cid AND en.Status = 'ACTIVE' ORDER BY s.MSSV"
    );
    $stmt->execute(['cid' => $classId]);
    $students = $stmt->fetchAll();

    $marks = [];
    if ($n > 0 && $students) {
        $in = implode(',', array_fill(0, $n, '?'));
        $stmt = db()->prepare("SELECT StudentID, SessionID, Status FROM attendance WHERE SessionID IN ($in)");
        $stmt->execute($sessionIds);
        foreach ($stmt->fetchAll() as $a) {
            $marks[(string) $a['StudentID']][(string) $a['SessionID']] = (string) $a['Status'];
        }
    }

    $rows = [];
    foreach ($students as $st) {
        $sid = (string) $st['StudentID'];
        $c = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
        foreach ($sessionIds as $ses) {
            $status = $marks[$sid][$ses] ?? 'ABSENT'; // không có dòng = vắng không phép
            if ($status === 'PRESENT') $c['present']++;
            elseif ($status === 'LATE') $c['late']++;
            elseif ($status === 'EXCUSED') $c['excused']++;
            else $c['absent']++;
        }
        $rows[$sid] = ['studentId' => $sid, 'mssv' => (string) $st['MSSV'], 'fullName' => (string) $st['FullName']]
            + $c + attendance_score($c, $r);
    }

    return ['sessionsCounted' => $n, 'sessionIds' => $sessionIds, 'rows' => $rows];
}

/** Áp công thức lên bộ đếm {present, late, absent, excused}. */
function attendance_score(array $c, ?array $r = null): array
{
    $r = $r ?? attendance_rules();
    $score = (float) $r['max_score']
        - $c['absent'] * (float) $r['absent_penalty']
        - $c['excused'] * (float) $r['excused_penalty']
        - $c['late'] * (float) $r['late_penalty'];
    $score = max(0.0, round($score, 2));

    $equiv = $c['absent']
        + intdiv($c['late'], max(1, (int) $r['late_per_absence']))
        + intdiv($c['excused'], max(1, (int) $r['excused_per_absence']));

    return [
        'score'              => $score,
        'equivalentAbsences' => $equiv,
        'banned'             => $equiv >= (int) $r['ban_threshold'],
    ];
}

/** Điểm tổng = Σ điểm×trọng số/100 trên các cột ĐÃ chấm, quy về tối đa 10. */
function grading_total(array $columns): array
{
    $sum = 0.0;
    $weightDone = 0.0;
    $weightTotal = 0.0;
    foreach ($columns as $col) {
        $w = (float) $col['weight'];
        $weightTotal += $w;
        if ($col['score'] !== null) {
            $sum += (float) $col['score'] * $w / 100;
            $weightDone += $w;
        }
    }
    return [
        'total'       => $weightDone > 0 ? round(min(10.0, $sum), 2) : null,
        'weightDone'  => round($weightDone, 2),
        'weightTotal' => round($weightTotal, 2),
    ];
}

/* ------------------------------------------------------------------ */
/*  Chuyên cần                                                          */
/* ------------------------------------------------------------------ */

/** adminAttendanceReport — GET. Bảng chuyên cần tính thử (không ghi gì). */
function action_admin_attendance_report(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $classId = trim((string) ($params['classId'] ?? ''));
    admin_load_class($me, $classId);

    $stats = attendance_stats($classId);
    $r = attendance_rules();
    api_ok([
        'classId'         => $classId,
        'sessionsCounted' => $stats['sessionsCounted'],
        'rules'           => $r,
        'rows'            => array_values($stats['rows']),
        'bannedCount'     => count(array_filter($stats['rows'], static fn ($x) => $x['banned'])),
    ]);
}

/**
 * adminApplyAttendanceScore — POST. Ghi điểm chuyên cần vào bảng điểm: tạo/
 * cập nhật cột "Chuyên cần" (trọng số 10%) rồi upsert grades cho mọi SV ACTIVE
 * (thay runAttendanceScore cũ). Chạy lại bao nhiêu lần cũng được.
 */
function action_admin_apply_attendance_score(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $classId = trim((string) ($params['classId'] ?? ''));
    admin_load_class($me, $classId);

    $stats = attendance_stats($classId);
    if ($stats['sessionsCounted'] === 0) {
        api_fail('Lớp này chưa có buổi nào được điểm danh — không có gì để tính.');
        return;
    }
    $r = attendance_rules();

    $result = db_transaction(static function (PDO $pdo) use ($classId, $stats, $r, $me): array {
        $col = grading_ensure_column($pdo, $classId, (string) $r['column_name'], (float) $r['column_weight']);
        $n = 0;
        foreach ($stats['rows'] as $row) {
            grading_upsert_score($pdo, $classId, $row['studentId'], $col['GradeColumnID'], $row['score'], $me['userId']);
            $n++;
        }
        return ['columnId' => $col['GradeColumnID'], 'created' => $col['created'], 'written' => $n];
    });

    log_audit($me['userId'], $me['role'], 'ADMIN_ATTENDANCE_SCORE', 'CLASS', $classId, [
        'sessions' => $stats['sessionsCounted'], 'students' => $result['written'],
    ]);
    api_ok([
        'classId'         => $classId,
        'sessionsCounted' => $stats['sessionsCounted'],
        'columnId'        => $result['columnId'],
        'columnCreated'   => $result['created'],
        'written'         => $result['written'],
        'rows'            => array_values($stats['rows']),
    ]);
}

/** Cột điểm theo tên trong lớp: có thì cập nhật trọng số, không thì tạo. */
function grading_ensure_column(PDO $pdo, string $classId, string $name, float $weight): array
{
    $stmt = $pdo->prepare('SELECT GradeColumnID, Weight FROM grade_columns WHERE ClassID = :cid AND Name = :n LIMIT 1 FOR UPDATE');
    $stmt->execute(['cid' => $classId, 'n' => $name]);
    $col = $stmt->fetch();
    if ($col) {
        if ((float) $col['Weight'] !== $weight) {
            $pdo->prepare("UPDATE grade_columns SET Weight = :w, Status = 'ACTIVE' WHERE GradeColumnID = :id")
                ->execute(['w' => $weight, 'id' => $col['GradeColumnID']]);
        }
        return ['GradeColumnID' => (string) $col['GradeColumnID'], 'created' => false];
    }
    $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(SortOrder), 0) FROM grade_columns WHERE ClassID = :cid');
    $maxStmt->execute(['cid' => $classId]);
    $max = (int) $maxStmt->fetchColumn();
    $id = new_id('GCL');
    $pdo->prepare("INSERT INTO grade_columns (GradeColumnID, ClassID, Name, Weight, SortOrder, Status) VALUES (:id, :cid, :n, :w, :o, 'ACTIVE')")
        ->execute(['id' => $id, 'cid' => $classId, 'n' => $name, 'w' => $weight, 'o' => $max + 1]);
    return ['GradeColumnID' => $id, 'created' => true];
}

/** Upsert một điểm theo UNIQUE uq_grade (StudentID, GradeColumnID). */
function grading_upsert_score(PDO $pdo, string $classId, string $studentId, string $columnId, float $score, string $by): void
{
    $pdo->prepare(
        'INSERT INTO grades (GradeID, StudentID, ClassID, GradeColumnID, Score, UpdatedBy) ' .
        'VALUES (:id, :sid, :cid, :col, :s, :by) ' .
        'ON DUPLICATE KEY UPDATE Score = VALUES(Score), UpdatedBy = VALUES(UpdatedBy)'
    )->execute(['id' => new_id('GRD'), 'sid' => $studentId, 'cid' => $classId, 'col' => $columnId, 's' => $score, 'by' => $by]);
}

/* ------------------------------------------------------------------ */
/*  Bảng điểm + nhập điểm CSV                                           */
/* ------------------------------------------------------------------ */

/** adminGradesReport — GET. Ma trận sinh viên × cột điểm, kèm tổng và cờ cấm thi. */
function action_admin_grades_report(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $classId = trim((string) ($params['classId'] ?? ''));
    admin_load_class($me, $classId);

    $cols = grading_columns($classId);
    $stats = attendance_stats($classId);

    $stmt = db()->prepare('SELECT StudentID, GradeColumnID, Score FROM grades WHERE ClassID = :cid');
    $stmt->execute(['cid' => $classId]);
    $scores = [];
    foreach ($stmt->fetchAll() as $g) {
        $scores[(string) $g['StudentID']][(string) $g['GradeColumnID']] = $g['Score'] === null ? null : (float) $g['Score'];
    }

    $rows = [];
    foreach ($stats['rows'] as $sid => $st) {
        $line = [];
        foreach ($cols as $c) {
            $line[] = ['columnId' => $c['GradeColumnID'], 'name' => $c['Name'], 'weight' => (float) $c['Weight'],
                'score' => $scores[$sid][$c['GradeColumnID']] ?? null];
        }
        $rows[] = ['mssv' => $st['mssv'], 'fullName' => $st['fullName'], 'scores' => array_map(static fn ($l) => $l['score'], $line),
            'banned' => $st['banned'], 'attendanceScore' => $st['score']] + grading_total($line);
    }

    api_ok([
        'classId' => $classId,
        'columns' => array_map(static fn ($c) => ['columnId' => $c['GradeColumnID'], 'name' => $c['Name'], 'weight' => (float) $c['Weight']], $cols),
        'weightTotal' => round(array_sum(array_map(static fn ($c) => (float) $c['Weight'], $cols)), 2),
        'rows' => $rows,
    ]);
}

function grading_columns(string $classId): array
{
    $stmt = db()->prepare("SELECT GradeColumnID, Name, Weight, SortOrder FROM grade_columns WHERE ClassID = :cid AND Status = 'ACTIVE' ORDER BY SortOrder ASC, Name ASC");
    $stmt->execute(['cid' => $classId]);
    return $stmt->fetchAll();
}

/**
 * adminImportGrades — POST. Nhập bảng điểm CSV cho lớp (thay previewGradeImport/
 * runGradeImport). Tham số: classId, csv, dryRun.
 * Tiêu đề: cột đầu MSSV, các cột sau "Tên đầu điểm (NN%)" — ví dụ
 *   MSSV,Thường xuyên (20%),Giữa kỳ (20%),Cuối kỳ (50%),Điểm cộng (10%)
 * Ô trống = CHƯA CHẤM (bỏ qua, không ghi 0). Điểm 0–10. Cột trùng tên cột đã
 * có thì cập nhật trọng số + điểm; chưa có thì tạo. Dry-run không ghi gì.
 * Ghi thật: một transaction, chỉ khi không còn dòng lỗi.
 */
function action_admin_import_grades(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $classId = trim((string) ($params['classId'] ?? ''));
    admin_load_class($me, $classId);
    $dryRun = filter_var($params['dryRun'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $parsed = grading_parse_csv((string) ($params['csv'] ?? ''));
    if (isset($parsed['error'])) {
        api_fail((string) $parsed['error']);
        return;
    }
    $columns = $parsed['columns']; // [{name, weight|null, index}]
    $rows = $parsed['rows'];       // [{line, mssv, scores: [colIndex => float|null]}]
    $errors = $parsed['errors'];

    // Đối chiếu sinh viên trong lớp
    $stmt = db()->prepare(
        "SELECT s.StudentID, s.MSSV FROM enrollments en JOIN students s ON s.StudentID = en.StudentID " .
        "WHERE en.ClassID = :cid AND en.Status = 'ACTIVE'"
    );
    $stmt->execute(['cid' => $classId]);
    $enrolled = [];
    foreach ($stmt->fetchAll() as $s) {
        $enrolled[(string) $s['MSSV']] = (string) $s['StudentID'];
    }

    $existingCols = [];
    foreach (grading_columns($classId) as $c) {
        $existingCols[mb_strtolower((string) $c['Name'])] = $c;
    }
    foreach ($columns as &$c) {
        $ex = $existingCols[mb_strtolower($c['name'])] ?? null;
        $c['exists'] = $ex !== null;
        if ($c['weight'] === null) {
            if ($ex) {
                $c['weight'] = (float) $ex['Weight'];
            } else {
                $errors[] = ['line' => 1, 'error' => 'Cột "' . $c['name'] . '" chưa có trong lớp và tiêu đề thiếu trọng số — ghi dạng "' . $c['name'] . ' (20%)".'];
            }
        }
    }
    unset($c);

    $valid = [];
    $cellCount = 0;
    foreach ($rows as $r) {
        if (!isset($enrolled[$r['mssv']])) {
            $errors[] = ['line' => $r['line'], 'error' => 'MSSV ' . $r['mssv'] . ' không có trong danh sách lớp (đang hoạt động).'];
            continue;
        }
        $r['studentId'] = $enrolled[$r['mssv']];
        $cellCount += count(array_filter($r['scores'], static fn ($v) => $v !== null));
        $valid[] = $r;
    }
    usort($errors, static fn ($a, $b) => $a['line'] <=> $b['line']);

    $weightTotal = round(array_sum(array_map(static fn ($c) => (float) ($c['weight'] ?? 0), $columns))
        + array_sum(array_map(static fn ($c) => (float) $c['Weight'],
            array_filter($existingCols, static fn ($c) => !in_array(mb_strtolower((string) $c['Name']), array_map(static fn ($x) => mb_strtolower($x['name']), $columns), true)))), 2);

    $report = [
        'classId'     => $classId,
        'dryRun'      => $dryRun,
        'columns'     => array_map(static fn ($c) => ['name' => $c['name'], 'weight' => $c['weight'], 'exists' => $c['exists']], $columns),
        'weightTotal' => $weightTotal,
        'weightNote'  => $weightTotal > 100 ? 'Tổng trọng số ' . $weightTotal . '% > 100 — điểm tổng sẽ quy về tối đa 10.' : ($weightTotal < 100 ? 'Tổng trọng số ' . $weightTotal . '% < 100 — kiểm tra lại nếu không cố ý.' : ''),
        'totalRows'   => count($valid) + count(array_filter($errors, static fn ($e) => $e['line'] > 1)),
        'validRows'   => count($valid),
        'cells'       => $cellCount,
        'errorCount'  => count($errors),
        'errors'      => array_slice($errors, 0, GRADING_MAX_ERRORS_REPORTED),
        'preview'     => array_slice(array_map(static fn ($r) => ['line' => $r['line'], 'mssv' => $r['mssv'], 'scores' => $r['scores']], $valid), 0, 20),
        'written'     => false,
    ];

    if ($dryRun) {
        api_ok($report);
        return;
    }
    if ($errors) {
        api_fail('File có ' . count($errors) . ' lỗi — sửa rồi nhập lại. Không ghi gì. (Dòng ' .
            implode(', ', array_slice(array_column($errors, 'line'), 0, 10)) . '…)');
        return;
    }
    if ($cellCount === 0) {
        api_fail('File không có ô điểm nào (toàn ô trống).');
        return;
    }

    $counts = ['columnsCreated' => 0, 'columnsUpdated' => 0, 'scoresWritten' => 0];
    db_transaction(static function (PDO $pdo) use ($classId, $columns, $valid, $me, &$counts): void {
        $colIds = [];
        foreach ($columns as $i => $c) {
            $res = grading_ensure_column($pdo, $classId, $c['name'], (float) $c['weight']);
            $colIds[$i] = $res['GradeColumnID'];
            $counts[$res['created'] ? 'columnsCreated' : 'columnsUpdated']++;
        }
        foreach ($valid as $r) {
            foreach ($r['scores'] as $i => $v) {
                if ($v === null) continue;
                grading_upsert_score($pdo, $classId, $r['studentId'], $colIds[$i], (float) $v, $me['userId']);
                $counts['scoresWritten']++;
            }
        }
    });

    log_audit($me['userId'], $me['role'], 'ADMIN_IMPORT_GRADES', 'CLASS', $classId, ['rows' => count($valid)] + $counts);
    $report['written'] = true;
    $report['counts'] = $counts;
    api_ok($report);
}

/**
 * Đọc CSV điểm: cột đầu tiên nhận diện là MSSV (theo bí danh), các cột còn
 * lại là đầu điểm "Tên (NN%)" (parseGradeHeader_ cũ). Ô trống → null.
 */
function grading_parse_csv(string $csv): array
{
    // Chặn file quá lớn trước khi phân tích (GĐ9 review lần 2, M7).
    if (strlen($csv) > GRADING_CSV_MAX_BYTES) {
        return ['error' => 'File quá lớn (' . round(strlen($csv) / 1024) . ' KB, tối đa ' . round(GRADING_CSV_MAX_BYTES / 1024) . ' KB).'];
    }
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
    $csv = str_replace(["\r\n", "\r"], "\n", trim($csv));
    if ($csv === '') {
        return ['error' => 'Chưa có nội dung CSV.'];
    }
    $lines = explode("\n", $csv);
    $header = $lines[0];
    $delim = ',';
    foreach ([';', "\t", ','] as $d) {
        if (substr_count($header, $d) > substr_count($header, $delim)) {
            $delim = $d;
        }
    }
    $headers = array_map('trim', str_getcsv($header, $delim, '"', '\\'));

    $mssvAliases = ['mssv', 'masosinhvien', 'masv', 'mshs', 'studentid', 'studentcode', 'id', 'ma'];
    $mssvIdx = null;
    foreach ($headers as $i => $h) {
        if (in_array(admin_norm_header($h), $mssvAliases, true)) {
            $mssvIdx = $i;
            break;
        }
    }
    if ($mssvIdx === null) {
        return ['error' => 'Không tìm thấy cột MSSV trong dòng tiêu đề (' . implode(' | ', $headers) . ').'];
    }

    $skipAliases = ['hoten', 'hovaten', 'ten', 'fullname', 'name', 'stt', 'ghichu', 'note', 'lop', 'malop', 'email'];
    $columns = [];
    foreach ($headers as $i => $h) {
        if ($i === $mssvIdx || $h === '' || in_array(admin_norm_header($h), $skipAliases, true)) continue;
        // Trọng số CHỈ nhận khi ở trong ngoặc — "Giữa kỳ (20%)", "Giữa kỳ (20)" —
        // hoặc có dấu % — "Giữa kỳ 20%". KHÔNG nhận số trần cuối tên: "Bài tập 1",
        // "Bài tập 2" trước đây đều bị đọc thành cột "Bài tập" trọng số 1, 2 rồi
        // ghi đè nhau (GĐ9 review lần 2, H4). Cột không có trọng số → lấy trọng số
        // của cột cùng tên đã có trong lớp, không có thì báo lỗi.
        if (preg_match('/^(.*\S)\s*[(\[](\d+(?:[.,]\d+)?)\s*%?[)\]]$/u', $h, $m)
            || preg_match('/^(.*\S)\s+(\d+(?:[.,]\d+)?)\s*%$/u', $h, $m)) {
            $weight = (float) str_replace(',', '.', $m[2]);
            if ($weight < 0 || $weight > 100) {
                // Trọng số vô lý (vd 500%) làm điểm tổng của CẢ LỚP chạm trần 10
                // (GĐ9 review lần 2, M6).
                return ['error' => 'Trọng số "' . $m[2] . '%" ở cột "' . trim($m[1]) . '" không hợp lệ (0–100).'];
            }
            $columns[] = ['name' => mb_substr(trim($m[1]), 0, 100), 'weight' => $weight, 'index' => $i];
        } else {
            $columns[] = ['name' => mb_substr($h, 0, 100), 'weight' => null, 'index' => $i];
        }
    }
    if (!$columns) {
        return ['error' => 'Dòng tiêu đề không có đầu điểm nào ngoài MSSV.'];
    }
    // Hai cột cùng tên sẽ ghi đè nhau trong grades (uq_grade) — chặn ngay.
    $names = [];
    foreach ($columns as $c) {
        $k = mb_strtolower($c['name']);
        if (isset($names[$k])) {
            return ['error' => 'Hai cột cùng tên "' . $c['name'] . '" trong dòng tiêu đề — đặt tên khác nhau (điểm sẽ ghi đè nhau).'];
        }
        $names[$k] = true;
    }

    if (count($lines) - 1 > GRADING_MAX_ROWS) {
        return ['error' => 'File có ' . (count($lines) - 1) . ' dòng, quá ' . GRADING_MAX_ROWS . ' dòng cho phép mỗi lần.'];
    }

    $rows = [];
    $errors = [];
    $seen = [];
    for ($i = 1; $i < count($lines); $i++) {
        $line = $i + 1;
        if (trim($lines[$i]) === '') continue;
        $cells = str_getcsv($lines[$i], $delim, '"', '\\');
        $mssv = strtoupper(preg_replace('/\s+/', '', (string) ($cells[$mssvIdx] ?? '')) ?? '');
        if (!preg_match('/^[0-9]{6,10}$/', $mssv)) {
            $errors[] = ['line' => $line, 'error' => 'MSSV không hợp lệ: "' . ($cells[$mssvIdx] ?? '') . '".'];
            continue;
        }
        if (isset($seen[$mssv])) {
            $errors[] = ['line' => $line, 'error' => 'MSSV ' . $mssv . ' bị lặp (đã có ở dòng ' . $seen[$mssv] . ').'];
            continue;
        }
        $seen[$mssv] = $line;

        $scores = [];
        $bad = false;
        foreach ($columns as $ci => $c) {
            $raw = trim((string) ($cells[$c['index']] ?? ''));
            if ($raw === '') {
                $scores[$ci] = null;
                continue;
            }
            $num = str_replace(',', '.', $raw);
            if (!is_numeric($num) || (float) $num < 0 || (float) $num > 10) {
                $errors[] = ['line' => $line, 'error' => 'Điểm "' . $raw . '" ở cột "' . $c['name'] . '" không hợp lệ (0–10, ô trống = chưa chấm).'];
                $bad = true;
                break;
            }
            $scores[$ci] = round((float) $num, 2);
        }
        if ($bad) continue;
        $rows[] = ['line' => $line, 'mssv' => $mssv, 'scores' => $scores];
    }

    return ['columns' => $columns, 'rows' => $rows, 'errors' => $errors];
}

/* ------------------------------------------------------------------ */
/*  Nhập tay điểm danh buổi cũ                                          */
/* ------------------------------------------------------------------ */

/** adminSessionAttendance — GET. Danh sách lớp + trạng thái hiện có của một buổi (để tick). */
function action_admin_session_attendance(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $sessionId = trim((string) ($params['sessionId'] ?? ''));
    $session = grading_load_session($me, $sessionId);

    $stmt = db()->prepare(
        "SELECT s.StudentID, s.MSSV, s.FullName, a.Status, a.CheckInTime, a.Note FROM enrollments en " .
        "JOIN students s ON s.StudentID = en.StudentID " .
        "LEFT JOIN attendance a ON a.StudentID = s.StudentID AND a.SessionID = :sid " .
        "WHERE en.ClassID = :cid AND en.Status = 'ACTIVE' ORDER BY s.MSSV"
    );
    $stmt->execute(['sid' => $sessionId, 'cid' => $session['ClassID']]);
    $rows = array_map(static fn ($r) => [
        'mssv' => $r['MSSV'], 'fullName' => $r['FullName'],
        'status' => $r['Status'], // null = chưa có bản ghi
        'checkInTime' => $r['CheckInTime'] !== null ? db_stamp_to_iso((string) $r['CheckInTime']) : '',
        'note' => (string) ($r['Note'] ?? ''),
    ], $stmt->fetchAll());

    api_ok(['sessionId' => $sessionId, 'classId' => $session['ClassID'], 'sessionNo' => (int) $session['SessionNo'],
        'date' => $session['Date'], 'rows' => $rows]);
}

/**
 * adminSetAttendance — POST. Ghi/sửa trạng thái điểm danh nhiều sinh viên của
 * MỘT buổi (thay importManualAttendance). Tham số: sessionId, marks: [{mssv,
 * status}] với status PRESENT|LATE|ABSENT|EXCUSED|"" (rỗng = xoá bản ghi
 * nhập tay? KHÔNG — rỗng = bỏ qua, không đụng). Một MSSV một bản ghi (D.8-3).
 * Không đổi CheckInTime/GPS/thiết bị của bản ghi check-in thật — chỉ Status +
 * Note. Ghi audit cho từng thay đổi (D.8-6).
 */
function action_admin_set_attendance(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $sessionId = trim((string) ($params['sessionId'] ?? ''));
    $session = grading_load_session($me, $sessionId);
    $classId = (string) $session['ClassID'];

    $marks = $params['marks'] ?? null;
    if (!is_array($marks) || !$marks) {
        api_fail('Thiếu danh sách marks.');
        return;
    }
    if (count($marks) > 500) {
        api_fail('Quá 500 dòng một lần.');
        return;
    }
    $allowed = ['PRESENT', 'LATE', 'ABSENT', 'EXCUSED'];

    $stmt = db()->prepare(
        "SELECT s.StudentID, s.MSSV FROM enrollments en JOIN students s ON s.StudentID = en.StudentID " .
        "WHERE en.ClassID = :cid AND en.Status = 'ACTIVE'"
    );
    $stmt->execute(['cid' => $classId]);
    $enrolled = [];
    foreach ($stmt->fetchAll() as $s) {
        $enrolled[(string) $s['MSSV']] = (string) $s['StudentID'];
    }

    $plan = [];
    $errors = [];
    foreach ($marks as $i => $m) {
        $mssv = strtoupper(trim((string) ($m['mssv'] ?? '')));
        $status = strtoupper(trim((string) ($m['status'] ?? '')));
        if ($status === '') continue; // không đụng
        if (!in_array($status, $allowed, true)) {
            $errors[] = 'MSSV ' . $mssv . ': trạng thái "' . $status . '" không hợp lệ.';
            continue;
        }
        if (!isset($enrolled[$mssv])) {
            $errors[] = 'MSSV ' . $mssv . ' không có trong danh sách lớp.';
            continue;
        }
        $plan[$enrolled[$mssv]] = ['mssv' => $mssv, 'status' => $status];
    }
    if ($errors) {
        api_fail('Có ' . count($errors) . ' dòng lỗi, không ghi gì: ' . implode(' ', array_slice($errors, 0, 5)));
        return;
    }
    if (!$plan) {
        api_fail('Không có dòng nào cần ghi.');
        return;
    }

    $note = 'Nhập tay bởi ' . $me['name'];
    $counts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
    $changes = [];
    db_transaction(static function (PDO $pdo) use ($plan, $sessionId, $note, &$counts, &$changes): void {
        $sel = $pdo->prepare('SELECT AttendanceID, Status FROM attendance WHERE StudentID = :sid AND SessionID = :ses FOR UPDATE');
        $ins = $pdo->prepare(
            "INSERT INTO attendance (AttendanceID, StudentID, SessionID, Status, CheckInTime, GpsFlag, Note) " .
            "VALUES (:id, :sid, :ses, :st, NULL, 'NO_GPS', :note)"
        );
        // NỐI thêm vào Note, KHÔNG ghi đè: Note của bản check-in thật có thể đang
        // giữ cảnh báo "Trùng thiết bị với N MSSV khác" (D.8-4) — xoá đi là mất
        // bằng chứng trên liveRoster (GĐ9 review lần 2, L10).
        $upd = $pdo->prepare(
            "UPDATE attendance SET Status = :st, " .
            "Note = TRIM(BOTH ' | ' FROM CONCAT(COALESCE(Note, ''), ' | ', :note)) WHERE AttendanceID = :id"
        );
        foreach ($plan as $studentId => $p) {
            $sel->execute(['sid' => $studentId, 'ses' => $sessionId]);
            $cur = $sel->fetch();
            if (!$cur) {
                $ins->execute(['id' => new_id('ATT'), 'sid' => $studentId, 'ses' => $sessionId, 'st' => $p['status'], 'note' => $note]);
                $counts['inserted']++;
                $changes[] = ['mssv' => $p['mssv'], 'from' => null, 'to' => $p['status']];
            } elseif ((string) $cur['Status'] !== $p['status']) {
                $upd->execute(['st' => $p['status'], 'note' => $note . ' (trước: ' . $cur['Status'] . ')', 'id' => $cur['AttendanceID']]);
                $counts['updated']++;
                $changes[] = ['mssv' => $p['mssv'], 'from' => (string) $cur['Status'], 'to' => $p['status']];
            } else {
                $counts['unchanged']++;
            }
        }
    });

    log_audit($me['userId'], $me['role'], 'ADMIN_SET_ATTENDANCE', 'SESSION', $sessionId, $counts + ['changes' => array_slice($changes, 0, 100)]);
    api_ok(['sessionId' => $sessionId] + $counts);
}

/** Buổi học + kiểm quyền trên lớp của buổi đó. */
function grading_load_session(array $me, string $sessionId): array
{
    if ($sessionId === '') {
        throw new RuntimeException('Thiếu mã buổi học (sessionId).');
    }
    $stmt = db()->prepare('SELECT * FROM sessions WHERE SessionID = :id LIMIT 1');
    $stmt->execute(['id' => $sessionId]);
    $session = $stmt->fetch();
    if (!$session) {
        throw new RuntimeException(not_found_message($me, 'buổi học', $sessionId)); // L8
    }
    assert_class_access($me, (string) $session['ClassID']);
    return $session;
}
