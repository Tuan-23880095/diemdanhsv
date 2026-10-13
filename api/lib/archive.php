<?php
/**
 * api/lib/archive.php — Lưu trữ cuối học kỳ (docs/04-API-PHP.md mục 32).
 *
 * Quy trình cho MỘT lớp học phần đã kết thúc (giảng viên của lớp hoặc admin):
 *   1. Tải "gói lưu trữ" ZIP về máy (api/luu-tru.php): PDF phiếu điểm từng phiếu, ảnh mẫu, CSV điểm
 *      danh/bảng điểm/điểm phiếu, danh sách SV, toàn bộ dữ liệu gốc (JSON), thống kê. Ghi nhận vào
 *      class_archives (Status PACKAGED, SHA-256, kích thước, thời điểm).
 *   2. Xoá dữ liệu chi tiết của lớp (action archiveReset) — chỉ khi: đã tạo gói trong 14 ngày VÀ không có
 *      dữ liệu mới sau thời điểm tạo gói; không có mã điểm danh đang mở; nhập lại mật khẩu; gõ đúng mã lớp.
 *      Trước khi xoá ghi "bản cứu" dữ liệu chữ (JSON nén, KHÔNG gồm ảnh) vào ../private/archives, tự xoá
 *      sau 30 ngày. Lớp chuyển INACTIVE (ẩn khỏi danh sách); SV không còn học lớp nào khác bị xoá hồ sơ.
 *   3. Thống kê ẩn danh (StatsJSON — không họ tên, MSSV, email) được giữ để so sánh qua các năm.
 */

const ARCHIVE_SAFETY_DAYS  = 30;   // bản cứu dữ liệu chữ trên máy chủ
const ARCHIVE_PACKAGE_DAYS = 14;   // gói phải tạo trong vòng N ngày trước khi xoá

function archive_dir(): string
{
    $d = (string) (app_config()['app']['archive_dir'] ?? '');
    if ($d === '') $d = dirname(__DIR__, 2) . '/../private/archives';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    if (is_dir($d) && !is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
    return rtrim($d, '/\\');
}

function archive_row(string $classId): ?array
{
    $s = db()->prepare('SELECT * FROM class_archives WHERE ClassID = :c LIMIT 1');
    $s->execute(['c' => $classId]);
    return $s->fetch() ?: null;
}

/** Số liệu mô tả một dãy điểm: n, tb, trung vị, độ lệch chuẩn, min, max, phân bố, dãy ẩn danh đã sắp xếp. */
function archive_describe(array $vals, bool $withValues = true): array
{
    $vals = array_values(array_map('floatval', array_filter($vals, static fn ($v) => $v !== null && $v !== '')));
    sort($vals);
    $n = count($vals);
    $out = ['n' => $n, 'avg' => null, 'median' => null, 'sd' => null, 'min' => null, 'max' => null,
            'buckets' => ['<5' => 0, '5–<6,5' => 0, '6,5–<8' => 0, '≥8' => 0]];
    if ($n) {
        $avg = array_sum($vals) / $n;
        $out['avg'] = round($avg, 2);
        $out['median'] = $n % 2 ? $vals[intdiv($n, 2)] : round(($vals[$n / 2 - 1] + $vals[$n / 2]) / 2, 2);
        $out['sd'] = $n > 1 ? round(sqrt(array_sum(array_map(static fn ($v) => ($v - $avg) ** 2, $vals)) / ($n - 1)), 2) : 0.0;
        $out['min'] = $vals[0]; $out['max'] = $vals[$n - 1];
        foreach ($vals as $v) $out['buckets'][$v < 5 ? '<5' : ($v < 6.5 ? '5–<6,5' : ($v < 8 ? '6,5–<8' : '≥8'))]++;
    }
    if ($withValues) $out['values'] = $vals;
    return $out;
}

/**
 * Ảnh chụp thống kê ẩn danh của lớp (giữ lại sau khi xoá). Không chứa họ tên, MSSV, email, ID sinh viên.
 */
function archive_class_snapshot(string $classId): array
{
    $cls = khtd_class_info($classId);
    $snap = ['version' => 1, 'generatedAt' => date('Y-m-d H:i:s'),
             'class' => ['classCode' => $cls['ClassCode'], 'courseCode' => $cls['CourseCode'], 'courseName' => $cls['CourseName'],
                         'semester' => $cls['Semester'], 'academicYear' => $cls['AcademicYear'], 'lecturer' => $cls['LecturerName']]];

    // ---------- Chuyên cần ----------
    $att = attendance_stats($classId);
    $snap['class']['students'] = count($att['rows']);
    $ses = db()->prepare("SELECT s.SessionID, s.SessionNo, s.`Date`, " .
        "SUM(a.Status = 'PRESENT') AS present, SUM(a.Status = 'LATE') AS late, SUM(a.Status = 'ABSENT') AS absent, SUM(a.Status = 'EXCUSED') AS excused " .
        "FROM sessions s LEFT JOIN attendance a ON a.SessionID = s.SessionID WHERE s.ClassID = :c GROUP BY s.SessionID, s.SessionNo, s.`Date` ORDER BY s.SessionNo");
    $ses->execute(['c' => $classId]);
    $sessions = [];
    foreach ($ses->fetchAll() as $r) {
        $sessions[] = ['no' => (int) $r['SessionNo'], 'date' => $r['Date'], 'present' => (int) $r['present'], 'late' => (int) $r['late'],
                       'absent' => (int) $r['absent'], 'excused' => (int) $r['excused']];
    }
    $rates = []; $attScores = []; $banned = 0;
    foreach ($att['rows'] as $r) {
        if ($r['sessionsCounted'] > 0) $rates[] = round(($r['present'] + $r['late']) / $r['sessionsCounted'] * 100, 1);
        $attScores[] = $r['score'];
        if ($r['banned']) $banned++;
    }
    $rateD = archive_describe($rates);
    unset($rateD['buckets']);
    $snap['attendance'] = ['sessionsCounted' => $att['sessionsCounted'], 'sessions' => $sessions,
                           'attendanceRatePct' => $rateD, 'attendanceScore' => archive_describe($attScores), 'bannedCount' => $banned];

    // ---------- Bảng điểm ----------
    $cols = grading_columns($classId);
    $g = db()->prepare('SELECT StudentID, GradeColumnID, Score FROM grades WHERE ClassID = :c');
    $g->execute(['c' => $classId]);
    $byStu = [];
    foreach ($g->fetchAll() as $r) $byStu[$r['StudentID']][$r['GradeColumnID']] = $r['Score'] === null ? null : (float) $r['Score'];
    $colOut = []; $totals = [];
    foreach ($cols as $c) {
        $vals = [];
        foreach ($byStu as $sc) if (isset($sc[$c['GradeColumnID']])) $vals[] = $sc[$c['GradeColumnID']];
        $colOut[] = ['name' => $c['Name'], 'weight' => (float) $c['Weight']] + archive_describe($vals);
    }
    foreach (array_keys($att['rows']) as $sid) {
        $t = grading_total(array_map(static fn ($c) => ['weight' => $c['Weight'], 'score' => $byStu[$sid][$c['GradeColumnID']] ?? null], $cols));
        if ($t['total'] !== null) $totals[] = $t['total'];
    }
    $snap['grades'] = ['columns' => $colOut, 'total' => archive_describe($totals)];

    // ---------- Phiếu học tập ----------
    $sheets = [];
    $selfLabels = ['Chưa', 'Tạm được', 'Tự tin'];
    foreach (khtd_class_stats($classId) as $w) {
        $wsRow = khtd_worksheet($w['worksheetId']);
        $schema = $wsRow['schema'];
        $crit = [];
        foreach ((array) ($schema['rubric']['criteria'] ?? []) as $c) $crit[$c['id']] = ['id' => $c['id'], 'name' => $c['name'], 'max' => (float) $c['max'], 'scores' => []];
        $self = []; $attemptsDist = []; $photos = []; $levels = ['Thấp' => 0, 'Trung bình' => 0, 'Cao' => 0];
        foreach ((array) ($schema['sections'] ?? []) as $s) {
            if (($s['type'] ?? '') === 'self') foreach ($s['items'] as $i => $it) $self[$s['id'] . ':' . $i] = ['item' => $it, 'counts' => array_fill_keys($selfLabels, 0)];
        }
        foreach ($w['roster'] as $r) {
            if (!$r['submissionId']) continue;
            $attemptsDist[(string) $r['used']] = ($attemptsDist[(string) $r['used']] ?? 0) + 1;
            if ($r['risk']) $levels[$r['risk']['level']]++;
            $q = db()->prepare('SELECT AnswersJSON, AiJSON FROM khtd_submissions WHERE SubmissionID = :id');
            $q->execute(['id' => $r['submissionId']]);
            $row = $q->fetch();
            $ans = json_decode((string) $row['AnswersJSON'], true) ?: [];
            $ai = $row['AiJSON'] ? json_decode((string) $row['AiJSON'], true) : null;
            foreach ((array) ($ai['criteria'] ?? []) as $c) if (isset($crit[$c['id'] ?? ''])) $crit[$c['id']]['scores'][] = (float) $c['score'];
            foreach ($self as $k => $_) {
                [$sec, $i] = explode(':', $k);
                $v = $ans[$sec][(int) $i] ?? null;
                if ($v !== null && isset($selfLabels[(int) $v])) $self[$k]['counts'][$selfLabels[(int) $v]]++;
            }
            $np = 0;
            foreach ((array) ($ans['_photos'] ?? []) as $rows) foreach ((array) $rows as $ids) $np += count((array) $ids);
            $photos[] = $np;
        }
        $critOut = [];
        foreach ($crit as $c) {
            $d = archive_describe($c['scores'], false);
            $critOut[] = ['id' => $c['id'], 'name' => $c['name'], 'max' => $c['max'], 'n' => $d['n'], 'avg' => $d['avg'],
                          'pctOfMax' => $d['avg'] !== null && $c['max'] > 0 ? round($d['avg'] / $c['max'] * 100, 1) : null];
        }
        $scores = array_map(static fn ($r) => $r['score'], array_filter($w['roster'], static fn ($r) => $r['score'] !== null));
        $tagKinds = [];
        foreach ($w['special'] as $sp) foreach ($sp['tags'] as $t) {
            $k = preg_replace('/[:(].*$|\s+\d.*$/u', '', $t);
            $tagKinds[trim($k)] = ($tagKinds[trim($k)] ?? 0) + 1;
        }
        ksort($attemptsDist);
        $sheets[] = ['no' => $w['no'], 'title' => $w['title'], 'maxAttempts' => $w['maxAttempts'], 'scorePolicy' => $w['scorePolicy'],
                     'enrolled' => $w['enrolled'], 'studentsSubmitted' => $w['studentsSubmitted'], 'submissions' => $w['submissions'],
                     'score' => archive_describe($scores), 'criteria' => $critOut, 'selfAssessment' => array_values($self),
                     'attemptsDistribution' => $attemptsDist, 'aiRiskLevels' => $levels, 'specialTags' => $tagKinds,
                     'photosPerStudent' => archive_describe($photos, false)];
    }
    $snap['worksheets'] = $sheets;
    return $snap;
}

/** Dữ liệu gốc của lớp theo từng bảng (cho gói lưu trữ và bản cứu). */
function archive_dump_rows(string $classId): array
{
    $q = static function (string $sql, array $p = []): array { $s = db()->prepare($sql); $s->execute($p); return $s->fetchAll(); };
    $c = ['c' => $classId];
    return [
        'classes'             => $q('SELECT * FROM classes WHERE ClassID = :c', $c),
        'students'            => $q('SELECT s.* FROM students s JOIN enrollments e ON e.StudentID = s.StudentID WHERE e.ClassID = :c', $c),
        'enrollments'         => $q('SELECT * FROM enrollments WHERE ClassID = :c', $c),
        'sessions'            => $q('SELECT * FROM sessions WHERE ClassID = :c', $c),
        'attendance_keys'     => $q('SELECT k.* FROM attendance_keys k JOIN sessions s ON s.SessionID = k.SessionID WHERE s.ClassID = :c', $c),
        'attendance'          => $q('SELECT a.* FROM attendance a JOIN sessions s ON s.SessionID = a.SessionID WHERE s.ClassID = :c', $c),
        'grade_columns'       => $q('SELECT * FROM grade_columns WHERE ClassID = :c', $c),
        'grades'              => $q('SELECT * FROM grades WHERE ClassID = :c', $c),
        'complaints'          => $q('SELECT * FROM complaints WHERE ClassID = :c', $c),
        'khtd_worksheets'     => $q('SELECT * FROM khtd_worksheets WHERE ClassID = :c', $c),
        'khtd_submissions'    => $q('SELECT x.* FROM khtd_submissions x JOIN khtd_worksheets w ON w.WorksheetID = x.WorksheetID WHERE w.ClassID = :c', $c),
        'khtd_extra_attempts' => $q('SELECT x.* FROM khtd_extra_attempts x JOIN khtd_worksheets w ON w.WorksheetID = x.WorksheetID WHERE w.ClassID = :c', $c),
        'khtd_photos'         => $q('SELECT x.* FROM khtd_photos x JOIN khtd_worksheets w ON w.WorksheetID = x.WorksheetID WHERE w.ClassID = :c', $c),
    ];
}

/** Thời điểm dữ liệu của lớp thay đổi gần nhất (để biết gói lưu trữ còn "mới" không). */
function archive_last_change(string $classId): ?string
{
    $s = db()->prepare(
        "SELECT MAX(t) FROM (" .
        "SELECT MAX(x.UpdatedAt) t FROM khtd_submissions x JOIN khtd_worksheets w ON w.WorksheetID = x.WorksheetID WHERE w.ClassID = :c1 UNION ALL " .
        "SELECT MAX(p.CreatedAt) FROM khtd_photos p JOIN khtd_worksheets w ON w.WorksheetID = p.WorksheetID WHERE w.ClassID = :c2 UNION ALL " .
        "SELECT MAX(a.CreatedAt) FROM attendance a JOIN sessions s ON s.SessionID = a.SessionID WHERE s.ClassID = :c3 UNION ALL " .
        "SELECT MAX(UpdatedAt) FROM grades WHERE ClassID = :c4 UNION ALL " .
        "SELECT MAX(CreatedAt) FROM enrollments WHERE ClassID = :c5 UNION ALL " .
        "SELECT MAX(CreatedAt) FROM sessions WHERE ClassID = :c6) u"
    );
    $s->execute(['c1' => $classId, 'c2' => $classId, 'c3' => $classId, 'c4' => $classId, 'c5' => $classId, 'c6' => $classId]);
    $v = $s->fetchColumn();
    return $v ? (string) $v : null;
}

/** Số dòng/tệp sẽ bị xoá + những gì chặn việc xoá. */
function archive_plan(string $classId): array
{
    $dump = archive_dump_rows($classId);
    $counts = array_map('count', $dump);
    unset($counts['classes']);
    $bytes = 0; $files = 0;
    foreach ($dump['khtd_photos'] as $p) { $files++; $bytes += (int) $p['Bytes']; }
    $sids = array_column($dump['enrollments'], 'StudentID');
    $keep = [];
    if ($sids) {
        $in = implode(',', array_fill(0, count($sids), '?'));
        $o = db()->prepare("SELECT DISTINCT StudentID FROM enrollments WHERE StudentID IN ($in) AND ClassID <> ?");
        $o->execute(array_merge($sids, [$classId]));
        $keep = $o->fetchAll(PDO::FETCH_COLUMN);
    }
    $blockers = [];
    $open = db()->prepare("SELECT COUNT(*) FROM attendance_keys k JOIN sessions s ON s.SessionID = k.SessionID WHERE s.ClassID = :c AND k.Status = 'OPEN' AND k.EndTime > NOW()");
    $open->execute(['c' => $classId]);
    if ((int) $open->fetchColumn() > 0) $blockers[] = 'Lớp đang có mã điểm danh mở — chờ buổi học kết thúc.';
    $arc = archive_row($classId);
    $last = archive_last_change($classId);
    $pkg = null;
    if ($arc && $arc['PackagedAt']) {
        $fresh = !$last || $last <= $arc['PackagedAt'];
        $recent = strtotime((string) $arc['PackagedAt']) >= time() - ARCHIVE_PACKAGE_DAYS * 86400;
        $pkg = ['name' => $arc['PackageName'], 'bytes' => (int) $arc['PackageBytes'], 'sha256' => $arc['PackageSha256'],
                'at' => $arc['PackagedAt'], 'fresh' => $fresh, 'recent' => $recent];
        if (!$fresh) $blockers[] = 'Có dữ liệu mới sau khi tạo gói (' . $last . ') — tải lại gói lưu trữ.';
        elseif (!$recent) $blockers[] = 'Gói lưu trữ tạo quá ' . ARCHIVE_PACKAGE_DAYS . ' ngày — tải lại gói.';
    } else {
        $blockers[] = 'Chưa tải gói lưu trữ về máy.';
    }
    if ($arc && $arc['Status'] === 'RESET') $blockers = ['Lớp này đã được lưu trữ và xoá dữ liệu lúc ' . $arc['ResetAt'] . '.'];
    return ['counts' => $counts, 'photoFiles' => $files, 'photoBytes' => $bytes,
            'studentsToDelete' => count(array_diff($sids, $keep)), 'studentsKept' => count($keep),
            'package' => $pkg, 'lastChange' => $last, 'blockers' => $blockers, 'canReset' => !$blockers];
}

/** Xoá bản cứu quá ARCHIVE_SAFETY_DAYS ngày. */
function archive_purge_safety(): void
{
    foreach (glob(archive_dir() . '/*-du-lieu-chu.json.gz') ?: [] as $f) {
        if (filemtime($f) < time() - ARCHIVE_SAFETY_DAYS * 86400) @unlink($f);
    }
    foreach (glob(archive_dir() . '/tmp-*.zip') ?: [] as $f) {      // gói tạm còn sót do lỗi giữa chừng
        if (filemtime($f) < time() - 3600) @unlink($f);
    }
}

function archive_rmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        is_dir($p) ? archive_rmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

/* ------------------------------------------------------------------ */
/* Gói lưu trữ ZIP                                                       */
/* ------------------------------------------------------------------ */

function archive_csv(array $rows): string
{
    $out = "\xEF\xBB\xBF";
    foreach ($rows as $r) {
        $out .= implode(',', array_map(static fn ($v) => '"' . str_replace('"', '""', (string) ($v ?? '')) . '"', $r)) . "\r\n";
    }
    return $out;
}

/**
 * Tạo gói ZIP vào $zipPath. Trả ['name' => tên tải về, 'files' => số tệp, 'pdfErrors' => […]].
 */
function archive_build_package(string $classId, string $zipPath): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('Máy chủ thiếu tiện ích ZipArchive (PHP zip) — bật trong hPanel → PHP Configuration → Extensions.');
    $cls = khtd_class_info($classId);
    $base = khtd_ascii((string) $cls['ClassCode'], false) . '-luu-tru-' . date('Ymd');
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Không tạo được tệp ZIP tạm.');
    $n = 0; $pdfErrors = [];
    $add = static function (string $name, string $data) use ($zip, $base, &$n): void { $zip->addFromString($base . '/' . $name, $data); $n++; };

    $dump = archive_dump_rows($classId);
    $snap = archive_class_snapshot($classId);
    $att = attendance_stats($classId);
    $stats = khtd_class_stats($classId);

    // Danh sách SV
    $rows = [['MSSV', 'Họ và tên', 'Email', 'Ghi danh']];
    $enr = []; foreach ($dump['enrollments'] as $e) $enr[$e['StudentID']] = $e['Status'];
    foreach ($dump['students'] as $s) $rows[] = [$s['MSSV'], $s['FullName'], $s['Email'], $enr[$s['StudentID']] ?? ''];
    $add('danh-sach-sinh-vien.csv', archive_csv($rows));

    // Điểm danh
    $sesNo = []; foreach ($dump['sessions'] as $s) $sesNo[$s['SessionID']] = (int) $s['SessionNo'];
    asort($sesNo);
    $mark = []; foreach ($dump['attendance'] as $a) $mark[$a['StudentID']][$a['SessionID']] = $a['Status'];
    $lbl = ['PRESENT' => 'Có mặt', 'LATE' => 'Trễ', 'ABSENT' => 'Vắng', 'EXCUSED' => 'Có phép'];
    $rows = [array_merge(['MSSV', 'Họ và tên'], array_map(static fn ($no) => 'Buổi ' . $no, array_values($sesNo)), ['Có mặt', 'Trễ', 'Vắng', 'Có phép', 'Điểm chuyên cần', 'Cấm thi'])];
    foreach ($att['rows'] as $sid => $r) {
        $line = [$r['mssv'], $r['fullName']];
        foreach (array_keys($sesNo) as $ses) $line[] = isset($mark[$sid][$ses]) ? $lbl[$mark[$sid][$ses]] : '';
        $rows[] = array_merge($line, [$r['present'], $r['late'], $r['absent'], $r['excused'], $r['score'], $r['banned'] ? 'Có' : '']);
    }
    $add('diem-danh.csv', archive_csv($rows));

    // Bảng điểm
    $cols = grading_columns($classId);
    $sc = []; foreach ($dump['grades'] as $g) $sc[$g['StudentID']][$g['GradeColumnID']] = $g['Score'];
    $rows = [array_merge(['MSSV', 'Họ và tên'], array_map(static fn ($c) => $c['Name'] . ' (' . rtrim(rtrim((string) $c['Weight'], '0'), '.') . '%)', $cols), ['Tổng'])];
    foreach ($att['rows'] as $sid => $r) {
        $line = [$r['mssv'], $r['fullName']];
        foreach ($cols as $c) $line[] = $sc[$sid][$c['GradeColumnID']] ?? '';
        $t = grading_total(array_map(static fn ($c) => ['weight' => $c['Weight'], 'score' => isset($sc[$sid][$c['GradeColumnID']]) ? (float) $sc[$sid][$c['GradeColumnID']] : null], $cols));
        $line[] = $t['total'] ?? '';
        $rows[] = $line;
    }
    $add('bang-diem.csv', archive_csv($rows));

    // Điểm phiếu + PDF từng phiếu
    $hdr = ['MSSV', 'Họ và tên'];
    foreach ($stats as $w) { $hdr[] = 'Phiếu ' . $w['no']; $hdr[] = 'Phiếu ' . $w['no'] . ' – số lần nộp'; }
    $rows = [$hdr]; $map = [];
    foreach ($stats as $w) foreach ($w['roster'] as $r) $map[$r['mssv']][$w['no']] = $r;
    foreach ($att['rows'] as $r) {
        $line = [$r['mssv'], $r['fullName']];
        foreach ($stats as $w) { $x = $map[$r['mssv']][$w['no']] ?? null; $line[] = $x['score'] ?? ''; $line[] = $x['used'] ?? ''; }
        $rows[] = $line;
    }
    $add('phieu-hoc-tap/diem-phieu.csv', archive_csv($rows));
    foreach ($stats as $w) {
        if (!$w['submissions']['total']) continue;
        try {
            $pdf = khtd_pdf_worksheet_all($cls, $w);
            $add('phieu-hoc-tap/' . $pdf['name'], $pdf['data']);
        } catch (Throwable $e) {
            $pdfErrors[] = 'Phiếu ' . $w['no'] . ': ' . $e->getMessage();
        }
    }

    // Ảnh mẫu (mọi lần làm)
    $stuById = []; foreach ($dump['students'] as $s) $stuById[$s['StudentID']] = $s;
    $wsNo = []; foreach ($dump['khtd_worksheets'] as $w) $wsNo[$w['WorksheetID']] = (int) $w['No'];
    foreach ($dump['khtd_photos'] as $p) {
        $path = khtd_upload_dir() . '/' . basename($p['WorksheetID']) . '/' . basename($p['FileName']);
        if (!is_file($path)) continue;
        $st = $stuById[$p['StudentID']] ?? ['MSSV' => $p['StudentID'], 'FullName' => ''];
        $zip->addFile($path, $base . '/anh-mau/Phieu' . ($wsNo[$p['WorksheetID']] ?? 0) . '/' . $st['MSSV'] . '-' . khtd_ascii((string) $st['FullName']) . '/' . $p['SectionID'] . '-mau' . ((int) $p['RowIdx'] + 1) . '-' . basename($p['FileName']));
        $n++;
    }

    $add('du-lieu-goc.json', json_encode(['exportedAt' => date('c'), 'classId' => $classId, 'tables' => $dump], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $add('thong-ke-an-danh.json', json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $readme = "GÓI LƯU TRỮ LỚP " . $cls['ClassCode'] . " – " . $cls['CourseCode'] . " " . $cls['CourseName'] . "\r\n"
        . "Học kỳ: " . ($cls['Semester'] ?: '—') . " – Năm học: " . ($cls['AcademicYear'] ?: '—') . "\r\n"
        . "Tạo lúc: " . date('d/m/Y H:i') . " từ diemdanhsv.com\r\n\r\n"
        . "phieu-hoc-tap/*.pdf      Phiếu đánh giá điểm từng phiếu (trang tổng hợp + từng sinh viên) — dùng làm minh chứng\r\n"
        . "phieu-hoc-tap/diem-phieu.csv  Điểm các phiếu (lần được tính) và số lần nộp\r\n"
        . "anh-mau/                 Ảnh mẫu vật sinh viên chụp (mọi lần làm)\r\n"
        . "diem-danh.csv, bang-diem.csv, danh-sach-sinh-vien.csv  mở bằng Excel\r\n"
        . "du-lieu-goc.json         Toàn bộ dữ liệu gốc của lớp (để tra cứu hoặc khôi phục)\r\n"
        . "thong-ke-an-danh.json    Thống kê không tên — phần DUY NHẤT còn lại trên máy chủ sau khi xoá\r\n"
        . ($pdfErrors ? "\r\nLỖI TẠO PDF: " . implode('; ', $pdfErrors) . "\r\n" : '')
        . "\r\nHãy giữ ít nhất 2 bản (máy bàn + ổ đĩa ngoài/Drive) trước khi xoá dữ liệu trên web.\r\n";
    $add('00-DOC-TRUOC.txt', $readme);
    $zip->close();
    return ['name' => $base . '.zip', 'files' => $n, 'pdfErrors' => $pdfErrors];
}

/** Ghi nhận đã tạo gói (gọi sau khi tệp ZIP đã tạo xong). */
function archive_record_package(string $classId, string $name, string $zipPath, string $userId): array
{
    $cls = khtd_class_info($classId);
    $sha = hash_file('sha256', $zipPath); $bytes = filesize($zipPath);
    $arc = archive_row($classId);
    if ($arc && $arc['Status'] === 'RESET') throw new RuntimeException('Lớp đã được xoá dữ liệu.');
    if ($arc) {
        db()->prepare('UPDATE class_archives SET PackageName = :n, PackageBytes = :b, PackageSha256 = :h, PackagedAt = NOW(), PackagedBy = :u WHERE ArchiveID = :id')
            ->execute(['n' => $name, 'b' => $bytes, 'h' => $sha, 'u' => $userId, 'id' => $arc['ArchiveID']]);
    } else {
        db()->prepare('INSERT INTO class_archives (ArchiveID, ClassID, ClassCode, CourseCode, CourseName, Semester, AcademicYear, LecturerName, Status, PackageName, PackageBytes, PackageSha256, PackagedAt, PackagedBy) ' .
            "VALUES (:id, :c, :cc, :co, :cn, :se, :ay, :ln, 'PACKAGED', :n, :b, :h, NOW(), :u)")
            ->execute(['id' => new_id('ARC'), 'c' => $classId, 'cc' => $cls['ClassCode'], 'co' => $cls['CourseCode'], 'cn' => $cls['CourseName'],
                       'se' => $cls['Semester'], 'ay' => $cls['AcademicYear'], 'ln' => $cls['LecturerName'], 'n' => $name, 'b' => $bytes, 'h' => $sha, 'u' => $userId]);
    }
    log_audit($userId, 'LECTURER', 'archivePackage', 'class', $classId, ['bytes' => $bytes, 'sha256' => $sha]);
    return ['sha256' => $sha, 'bytes' => $bytes];
}

/* ------------------------------------------------------------------ */
/* Action                                                               */
/* ------------------------------------------------------------------ */

/** Lớp mà người dùng được xem lưu trữ (kể cả lớp đã INACTIVE). */
function archive_assert_access(array $me, string $classId): void
{
    $s = db()->prepare('SELECT LecturerID FROM classes WHERE ClassID = :c');
    $s->execute(['c' => $classId]);
    $lid = $s->fetchColumn();
    if ($lid === false) throw new RuntimeException('Không tìm thấy lớp.');
    if ($me['role'] !== 'ADMIN' && !class_has_lecturer((string) $lid, $me['userId'])) throw new RuntimeException('Bạn không có quyền với lớp này.');
}

/** GET archivePreview {token, classId} — thống kê ẩn danh sẽ giữ + những gì sẽ xoá + trạng thái gói. */
function action_archive_preview(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $classId = trim((string) ($params['classId'] ?? ''));
    archive_assert_access($me, $classId);
    archive_purge_safety();
    $arc = archive_row($classId);
    if ($arc && $arc['Status'] === 'RESET') {
        api_ok(['class' => khtd_class_info($classId), 'status' => 'RESET', 'resetAt' => $arc['ResetAt'],
                'snapshot' => json_decode((string) $arc['StatsJSON'], true), 'deleted' => json_decode((string) $arc['DeletedJSON'], true)]);
        return;
    }
    api_ok(['class' => khtd_class_info($classId), 'status' => $arc ? 'PACKAGED' : 'NONE',
            'snapshot' => archive_class_snapshot($classId), 'plan' => archive_plan($classId)]);
}

/** GET archiveList {token} — các lớp đã lưu trữ (để so sánh qua các năm). */
function action_archive_list(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $rows = db()->query("SELECT a.*, c.LecturerID FROM class_archives a LEFT JOIN classes c ON c.ClassID = a.ClassID WHERE a.Status = 'RESET' ORDER BY a.CourseCode, a.AcademicYear, a.Semester, a.ClassCode")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        if ($me['role'] !== 'ADMIN' && !class_has_lecturer((string) ($r['LecturerID'] ?? ''), $me['userId'])) continue;
        $out[] = ['archiveId' => $r['ArchiveID'], 'classId' => $r['ClassID'], 'classCode' => $r['ClassCode'], 'courseCode' => $r['CourseCode'],
                  'courseName' => $r['CourseName'], 'semester' => $r['Semester'], 'academicYear' => $r['AcademicYear'],
                  'lecturer' => $r['LecturerName'], 'students' => (int) $r['StudentsCount'], 'resetAt' => $r['ResetAt'],
                  'packageName' => $r['PackageName'], 'packageSha256' => $r['PackageSha256'],
                  'stats' => json_decode((string) $r['StatsJSON'], true)];
    }
    api_ok(['archives' => $out]);
}

/**
 * POST archiveReset {token, classId, password, confirmCode} — XOÁ dữ liệu chi tiết của lớp, giữ thống kê ẩn danh.
 * Không hoàn tác được trên web (bản cứu dữ liệu chữ 30 ngày chỉ khôi phục được bằng tay qua SSH).
 */
function action_archive_reset(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $classId = trim((string) ($params['classId'] ?? ''));
    archive_assert_access($me, $classId);
    $cls = khtd_class_info($classId);

    // Nhập lại mật khẩu (chặn trường hợp lộ token phiên) — dùng chung giới hạn sai mật khẩu với đăng nhập.
    $u = db()->prepare('SELECT Username, PasswordHash, Salt FROM users WHERE UserID = :id');
    $u->execute(['id' => $me['userId']]);
    $user = $u->fetch();
    $key = rate_limit_key((string) $user['Username']);
    rate_limit_guard('login_user', $key);
    $pw = (string) ($params['password'] ?? '');
    $hash = (string) $user['PasswordHash'];
    $ok = is_modern_hash($hash) ? password_verify($pw, $hash) : hash_equals($hash, legacy_sha256_hash($pw, (string) ($user['Salt'] ?? '')));
    if (!$ok) { rate_limit_record('login_user', $key); api_fail('Mật khẩu không đúng.'); return; }
    if (trim((string) ($params['confirmCode'] ?? '')) !== (string) $cls['ClassCode']) { api_fail('Mã lớp gõ xác nhận không khớp (phải gõ đúng: ' . $cls['ClassCode'] . ').'); return; }

    $plan = archive_plan($classId);
    if (!$plan['canReset']) { api_fail(implode(' ', $plan['blockers'])); return; }

    khtd_long_request();
    $snap = archive_class_snapshot($classId);
    $dump = archive_dump_rows($classId);

    // Bản cứu dữ liệu chữ (không gồm ảnh) — tự xoá sau ARCHIVE_SAFETY_DAYS ngày.
    $safety = archive_dir() . '/' . khtd_ascii((string) $cls['ClassCode'], false) . '-' . date('Ymd-His') . '-du-lieu-chu.json.gz';
    if (@file_put_contents($safety, gzencode(json_encode(['classId' => $classId, 'savedAt' => date('c'), 'tables' => $dump], JSON_UNESCAPED_UNICODE), 6)) === false) {
        api_fail('Không ghi được bản cứu trên máy chủ — dừng, chưa xoá gì.'); return;
    }
    $wsIds = array_column($dump['khtd_worksheets'], 'WorksheetID');
    $sids = array_column($dump['enrollments'], 'StudentID');

    $deleted = db_transaction(function (PDO $pdo) use ($classId, $wsIds, $sids, $snap, $safety, $me, $plan): array {
        $d = [];
        $run = static function (string $label, string $sql, array $p) use ($pdo, &$d): void { $s = $pdo->prepare($sql); $s->execute($p); $d[$label] = ($d[$label] ?? 0) + $s->rowCount(); };
        $c = ['c' => $classId];
        $run('complaints', 'DELETE FROM complaints WHERE ClassID = :c', $c);
        $run('attendance', 'DELETE a FROM attendance a JOIN sessions s ON s.SessionID = a.SessionID WHERE s.ClassID = :c', $c);
        $run('attendance_keys', 'DELETE k FROM attendance_keys k JOIN sessions s ON s.SessionID = k.SessionID WHERE s.ClassID = :c', $c);
        $run('sessions', 'DELETE FROM sessions WHERE ClassID = :c', $c);
        $run('grades', 'DELETE FROM grades WHERE ClassID = :c', $c);
        $run('grade_columns', 'DELETE FROM grade_columns WHERE ClassID = :c', $c);
        foreach ($wsIds as $w) {
            foreach (['khtd_photos', 'khtd_extra_attempts', 'khtd_submissions'] as $t) $run($t, "DELETE FROM $t WHERE WorksheetID = :w", ['w' => $w]);
        }
        $run('khtd_worksheets', 'DELETE FROM khtd_worksheets WHERE ClassID = :c', $c);
        $run('enrollments', 'DELETE FROM enrollments WHERE ClassID = :c', $c);
        // Hồ sơ SV không còn liên quan lớp nào khác.
        foreach (array_unique($sids) as $sid) {
            $chk = $pdo->prepare('SELECT (SELECT COUNT(*) FROM enrollments WHERE StudentID = :a) + (SELECT COUNT(*) FROM attendance WHERE StudentID = :b) + ' .
                '(SELECT COUNT(*) FROM grades WHERE StudentID = :c) + (SELECT COUNT(*) FROM complaints WHERE StudentID = :d) + (SELECT COUNT(*) FROM khtd_submissions WHERE StudentID = :e)');
            $chk->execute(['a' => $sid, 'b' => $sid, 'c' => $sid, 'd' => $sid, 'e' => $sid]);
            if ((int) $chk->fetchColumn() > 0) continue;
            $run('grade_codes', 'DELETE FROM grade_codes WHERE StudentID = :s', ['s' => $sid]);
            $run('auth_tokens', "DELETE FROM auth_tokens WHERE SubjectID = :s AND Kind IN ('GRADE','KHTD')", ['s' => $sid]);
            $run('students', 'DELETE FROM students WHERE StudentID = :s', ['s' => $sid]);
        }
        $pdo->prepare("UPDATE classes SET Status = 'INACTIVE' WHERE ClassID = :c")->execute($c);
        $d['photoFiles'] = $plan['photoFiles']; $d['photoBytes'] = $plan['photoBytes'];
        $pdo->prepare("UPDATE class_archives SET Status = 'RESET', StatsJSON = :s, StudentsCount = :n, DeletedJSON = :d, SafetyFile = :f, ResetAt = NOW(), ResetBy = :u WHERE ClassID = :c")
            ->execute(['s' => json_encode($snap, JSON_UNESCAPED_UNICODE), 'n' => (int) ($snap['class']['students'] ?? 0), 'd' => json_encode($d, JSON_UNESCAPED_UNICODE),
                       'f' => basename($safety), 'u' => $me['userId'], 'c' => $classId]);
        return $d;
    });
    foreach ($wsIds as $w) archive_rmdir(khtd_upload_dir() . '/' . basename($w));
    log_audit($me['userId'], 'LECTURER', 'archiveReset', 'class', $classId, $deleted);
    api_ok(['classCode' => $cls['ClassCode'], 'deleted' => $deleted, 'safetyFile' => basename($safety), 'safetyDays' => ARCHIVE_SAFETY_DAYS]);
}
