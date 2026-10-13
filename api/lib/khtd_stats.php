<?php
/**
 * api/lib/khtd_stats.php — Thống kê từng buổi (phiếu) và ước lượng nguy cơ dùng AI (docs/04 mục 31).
 *
 * "Nguy cơ dùng AI" CHỈ là tín hiệu để giảng viên xem lại, không phải kết luận. Gộp các dấu hiệu:
 *   - Gemini ước lượng khi chấm (AiJSON.ai_risk.muc 0–100, dấu hiệu văn phong);
 *   - tỉ lệ chữ được DÁN (paste) so với tổng chữ — trang làm phiếu đếm thao tác dán (answers._meta);
 *   - tốc độ viết: số ký tự / số phút từ lúc lưu nháp đầu tiên đến lúc nộp (giờ máy chủ);
 *   - cụm từ/định dạng đặc trưng chatbot ("Dưới đây là", "Tóm lại", **in đậm**, ### tiêu đề…);
 *   - nhiều thuật ngữ tiếng Anh trong ngoặc.
 * Giống bài sinh viên khác (sao chép lẫn nhau) được báo riêng ở "bài đặc biệt".
 */

const KHTD_RISK_HIGH = 60;
const KHTD_RISK_MID  = 35;

/** Lọc answers._meta do trình duyệt gửi (chỉ số nguyên, có trần). */
function khtd_clean_meta(array $answers): array
{
    $m = $answers['_meta'] ?? null;
    unset($answers['_meta']);
    if (!is_array($m)) return $answers;
    $out = [];
    foreach (['pasteChars', 'pasteCount', 'keys'] as $k) {
        if (isset($m[$k]) && is_numeric($m[$k])) $out[$k] = max(0, min(10000000, (int) $m[$k]));
    }
    if ($out) $answers['_meta'] = $out;
    return $answers;
}

/**
 * Chữ SV tự viết trong bài: ['all' => toàn bộ chữ, 'chars' => số ký tự, 'fields' => [id => chữ của câu tự luận],
 * 'filled' => số ô có nội dung, 'total' => số ô].
 */
function khtd_answer_text(array $answers, array $schema): array
{
    $all = []; $fields = []; $filled = 0; $total = 0;
    foreach ($schema['sections'] ?? [] as $s) {
        $t = $s['type'] ?? '';
        if ($t === 'table') {
            foreach (($s['rows'] ?? []) as $ri => $_) {
                foreach (($s['columns'] ?? []) as $ci => $__) {
                    $v = trim((string) ($answers[$s['id']][$ri][$ci] ?? ''));
                    $total++; if ($v !== '') { $filled++; $all[] = $v; }
                }
            }
        } elseif ($t === 'questions') {
            foreach ($s['items'] ?? [] as $q) {
                $v = trim((string) ($answers[$q['id']] ?? ''));
                $total++; if ($v !== '') { $filled++; $all[] = $v; $fields[$q['id']] = $v; }
            }
        } elseif ($t === 'text') {
            $v = trim((string) ($answers[$s['id']] ?? ''));
            $total++; if ($v !== '') { $filled++; $all[] = $v; $fields[$s['id']] = $v; }
        }
    }
    $txt = implode("\n", $all);
    return ['all' => $txt, 'chars' => mb_strlen($txt), 'fields' => $fields, 'filled' => $filled, 'total' => $total];
}

/**
 * Ước lượng nguy cơ dùng AI cho MỘT lần nộp.
 * $sub cần CreatedAt, SubmittedAt; $ai = AiJSON đã giải mã (có thể null).
 * @return array{score:int, level:string, reasons:string[]}
 */
function khtd_ai_risk(array $sub, array $answers, ?array $ai, array $schema): array
{
    $txt = khtd_answer_text($answers, $schema);
    $chars = $txt['chars'];
    $score = 0.0; $reasons = [];

    $gem = is_array($ai['ai_risk'] ?? null) ? (int) ($ai['ai_risk']['muc'] ?? 0) : null;
    if ($gem !== null) {
        $score += 0.45 * max(0, min(100, $gem));
        if ($gem >= 50) {
            $sig = array_slice(array_filter(array_map('strval', (array) ($ai['ai_risk']['dau_hieu'] ?? []))), 0, 2);
            $reasons[] = 'Gemini ước lượng ' . $gem . '%' . ($sig ? ': ' . implode('; ', $sig) : '');
        }
    }

    $meta = is_array($answers['_meta'] ?? null) ? $answers['_meta'] : [];
    $paste = (int) ($meta['pasteChars'] ?? 0);
    if ($chars >= 200 && $paste > 0) {
        $ratio = min(1, $paste / max(1, $chars));
        if ($ratio >= 0.6) { $score += 35; $reasons[] = 'Dán (paste) ' . round($ratio * 100) . '% nội dung'; }
        elseif ($ratio >= 0.3) { $score += 20; $reasons[] = 'Dán (paste) ' . round($ratio * 100) . '% nội dung'; }
        elseif ($paste >= 300) { $score += 8; $reasons[] = 'Dán ' . $paste . ' ký tự'; }
    }

    $t0 = strtotime((string) ($sub['CreatedAt'] ?? '')); $t1 = strtotime((string) ($sub['SubmittedAt'] ?? ''));
    if ($t0 && $t1 && $t1 >= $t0 && $chars >= 300) {
        $min = max(0.5, ($t1 - $t0) / 60);
        $cpm = $chars / $min;
        if ($cpm >= 300) { $score += 25; $reasons[] = 'Viết ' . $chars . ' ký tự trong ' . round($min, 1) . ' phút (' . round($cpm) . ' ký tự/phút)'; }
        elseif ($cpm >= 180) { $score += 12; $reasons[] = 'Tốc độ viết cao (' . round($cpm) . ' ký tự/phút)'; }
    }

    $all = $txt['all'];
    $markers = 0; $found = [];
    $pats = [
        '/\b(dưới đây là|chắc chắn rồi|tóm lại,|hy vọng (điều này|thông tin này)|với tư cách là|là một mô hình ngôn ngữ)/iu' => 'cụm từ kiểu chatbot',
        '/\b(as an ai|certainly!|in summary|here is|here are)\b/iu'                                                 => 'câu tiếng Anh kiểu chatbot',
        '/\*\*[^*\n]{2,80}\*\*/u'                                                                                    => 'chữ in đậm **…**',
        '/^\s{0,3}#{1,4}\s+\S/mu'                                                                                     => 'tiêu đề markdown #',
    ];
    foreach ($pats as $re => $label) {
        if (preg_match($re, $all)) { $markers++; $found[] = $label; }
    }
    if ($markers) { $score += min(20, 8 * $markers); $reasons[] = 'Văn phong/định dạng: ' . implode(', ', $found); }
    if (preg_match_all('/\((?:[A-Za-z][A-Za-z\- ]{3,30})\)/u', $all) >= 3) { $score += 8; $reasons[] = 'Nhiều thuật ngữ tiếng Anh trong ngoặc'; }

    $score = (int) round(min(100, $score));
    $level = $score >= KHTD_RISK_HIGH ? 'Cao' : ($score >= KHTD_RISK_MID ? 'Trung bình' : 'Thấp');
    return ['score' => $score, 'level' => $level, 'reasons' => $reasons];
}

/** Tập 3-từ liên tiếp đã chuẩn hoá (để so giống nhau giữa hai câu trả lời). */
function khtd_shingles(string $s): array
{
    $s = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s));
    $w = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    for ($i = 0; $i + 2 < count($w); $i++) $out[$w[$i] . ' ' . $w[$i + 1] . ' ' . $w[$i + 2]] = true;
    return $out;
}

/**
 * Cặp SV có câu tự luận giống nhau (Jaccard 3-từ ≥ 0,6 trên câu ≥ 80 ký tự).
 * $items: [key => ['fields' => [id => text], 'mssv' => …]]. Trả [key => [['mssv'=>…, 'field'=>…, 'pct'=>…], …]].
 */
function khtd_similar_pairs(array $items): array
{
    $sh = [];
    foreach ($items as $k => $it) foreach ($it['fields'] as $f => $t) if (mb_strlen($t) >= 80) $sh[$k][$f] = khtd_shingles($t);
    $keys = array_keys($sh); $out = [];
    for ($i = 0; $i < count($keys); $i++) {
        for ($j = $i + 1; $j < count($keys); $j++) {
            $a = $keys[$i]; $b = $keys[$j]; $best = 0.0; $bf = '';
            foreach ($sh[$a] as $f => $sa) {
                if (!isset($sh[$b][$f]) || !$sa || !$sh[$b][$f]) continue;
                $inter = count(array_intersect_key($sa, $sh[$b][$f]));
                $union = count($sa) + count($sh[$b][$f]) - $inter;
                $jac = $union > 0 ? $inter / $union : 0;
                if ($jac > $best) { $best = $jac; $bf = $f; }
            }
            if ($best >= 0.6) {
                $out[$a][] = ['mssv' => $items[$b]['mssv'], 'field' => $bf, 'pct' => (int) round($best * 100)];
                $out[$b][] = ['mssv' => $items[$a]['mssv'], 'field' => $bf, 'pct' => (int) round($best * 100)];
            }
        }
    }
    return $out;
}

/** GET khtdStats {token, classId} — thống kê từng phiếu (buổi) của lớp. */
function action_khtd_stats(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $classId = trim((string) ($params['classId'] ?? ''));
    assert_class_access($me, $classId);
    api_ok(['worksheets' => khtd_class_stats($classId), 'generatedAt' => date('Y-m-d H:i:s')]);
}

function khtd_class_stats(string $classId): array
{
    $ws = db()->prepare('SELECT * FROM khtd_worksheets WHERE ClassID = :c ORDER BY No');
    $ws->execute(['c' => $classId]);
    $sheets = $ws->fetchAll();
    $st = db()->prepare("SELECT s.StudentID, s.MSSV, s.FullName FROM enrollments e JOIN students s ON s.StudentID = e.StudentID WHERE e.ClassID = :c AND e.Status = 'ACTIVE' ORDER BY s.FullName");
    $st->execute(['c' => $classId]);
    $students = [];
    foreach ($st->fetchAll() as $s) $students[$s['StudentID']] = $s;
    $sb = db()->prepare('SELECT sub.* FROM khtd_submissions sub JOIN khtd_worksheets w ON w.WorksheetID = sub.WorksheetID WHERE w.ClassID = :c ORDER BY sub.AttemptNo');
    $sb->execute(['c' => $classId]);
    $rows = $sb->fetchAll();
    $sum = khtd_class_summaries($classId, $sheets, $rows);
    $byId = [];
    foreach ($rows as $r) $byId[$r['SubmissionID']] = $r;

    $out = [];
    foreach ($sheets as $w) {
        $schema = khtd_schema_normalize(json_decode((string) $w['SchemaJSON'], true) ?: []);
        $wsRows = array_values(array_filter($rows, static fn ($r) => $r['WorksheetID'] === $w['WorksheetID']));
        $subStats = ['total' => 0, 'final' => 0, 'aiOnly' => 0, 'ungraded' => 0, 'aiError' => 0, 'drafts' => 0];
        foreach ($wsRows as $r) {
            if ($r['Status'] === 'DRAFT') { $subStats['drafts']++; continue; }
            $subStats['total']++;
            if ($r['Status'] === 'FINAL') $subStats['final']++;
            elseif ($r['Status'] === 'AI_GRADED') $subStats['aiOnly']++;
            else { $subStats['ungraded']++; if ($r['AiScore'] === null && $r['AiJSON']) $subStats['aiError']++; }
        }

        $done = []; $draftOnly = []; $notStarted = []; $scores = []; $items = []; $special = []; $risk = []; $roster = [];
        foreach ($students as $sid => $s) {
            $x = $sum[$w['WorksheetID'] . '|' . $sid] ?? null;
            $who = ['mssv' => $s['MSSV'], 'name' => $s['FullName']];
            $roster[$sid] = $who + ['state' => 'Chưa làm', 'submissionId' => null, 'attemptNo' => null, 'used' => 0, 'score' => null, 'final' => false, 'risk' => null, 'tags' => []];
            if (!$x) { $notStarted[] = $who; continue; }
            if ($x['used'] === 0) { $draftOnly[] = $who; $roster[$sid]['state'] = 'Đang làm (nháp)'; continue; }
            $pick = $x['counted'] ?: null;
            if (!$pick) foreach (array_reverse($x['rows']) as $r) if ($r['Status'] !== 'DRAFT') { $pick = $r; break; }
            $full = $byId[$pick['SubmissionID']];
            $answers = json_decode((string) $full['AnswersJSON'], true) ?: [];
            $ai = $full['AiJSON'] ? json_decode((string) $full['AiJSON'], true) : null;
            $who['submissionId'] = $full['SubmissionID'];
            $who['attemptNo'] = (int) $full['AttemptNo'];
            $who['score'] = $x['score'];
            $who['final'] = $full['FinalScore'] !== null;
            $done[] = $who;
            $roster[$sid] = array_merge($roster[$sid], $who, ['used' => $x['used'],
                'state' => $full['Status'] === 'FINAL' ? 'GV đã chấm' : ($full['Status'] === 'AI_GRADED' ? 'Chỉ AI chấm' : 'Chưa có điểm')]);
            if ($x['score'] !== null) $scores[] = $who;
            $txt = khtd_answer_text($answers, $schema);
            $items[$sid] = ['fields' => $txt['fields'], 'mssv' => $s['MSSV']];

            $tags = [];
            if (is_array($ai) && !isset($ai['criteria'])) $tags[] = 'AI chưa chấm được';
            if (is_array($ai) && !empty($ai['flags'])) $tags[] = 'AI gắn cờ: ' . mb_substr(implode('; ', array_map('strval', (array) $ai['flags'])), 0, 160);
            if ($full['FinalScore'] !== null && $full['AiScore'] !== null && abs((float) $full['FinalScore'] - (float) $full['AiScore']) >= 2) {
                $tags[] = 'GV và AI chênh ' . rtrim(rtrim(number_format(abs((float) $full['FinalScore'] - (float) $full['AiScore']), 2, ',', ''), '0'), ',') . ' điểm';
            }
            if ($x['score'] !== null && $x['score'] < 5) $tags[] = 'Điểm dưới 5';
            if ($txt['total'] > 0 && $txt['filled'] / $txt['total'] < 0.15) $tags[] = 'Bài gần như trống (' . round($txt['filled'] / $txt['total'] * 100) . '% ô có nội dung)';
            if ($x['used'] >= 2) $tags[] = 'Nộp ' . $x['used'] . ' lần';
            if ($x['extra'] > 0) $tags[] = 'GV cấp thêm ' . $x['extra'] . ' lượt';
            if ($tags) $special[$sid] = $who + ['tags' => $tags];

            $rk = khtd_ai_risk($full, $answers, is_array($ai) ? $ai : null, $schema);
            $roster[$sid]['risk'] = $rk;
            if ($rk['score'] >= KHTD_RISK_MID) $risk[] = array_merge($who, ['riskScore' => $rk['score'], 'level' => $rk['level'], 'reasons' => $rk['reasons']]);
        }
        foreach (khtd_similar_pairs($items) as $sid => $pairs) {
            $s = $students[$sid];
            $tag = 'Giống bài ' . implode(', ', array_map(static fn ($p) => $p['mssv'] . ' (' . $p['field'] . ', ' . $p['pct'] . '%)', array_slice($pairs, 0, 3)));
            if (isset($special[$sid])) $special[$sid]['tags'][] = $tag;
            else {
                foreach ($done as $d) if ($d['mssv'] === $s['MSSV']) { $special[$sid] = $d + ['tags' => [$tag]]; break; }
            }
        }
        usort($risk, static fn ($a, $b) => $b['riskScore'] <=> $a['riskScore']);
        foreach ($special as $sid => $sp) $roster[$sid]['tags'] = $sp['tags'];

        $vals = array_map(static fn ($x) => (float) $x['score'], $scores);
        sort($vals);
        $n = count($vals);
        $sc = ['count' => $n, 'avg' => null, 'median' => null, 'max' => null, 'min' => null,
               'buckets' => ['<5' => 0, '5–<6,5' => 0, '6,5–<8' => 0, '≥8' => 0]];
        if ($n) {
            $sc['avg'] = round(array_sum($vals) / $n, 2);
            $sc['median'] = $n % 2 ? $vals[intdiv($n, 2)] : round(($vals[$n / 2 - 1] + $vals[$n / 2]) / 2, 2);
            $hi = max($vals); $lo = min($vals);
            $sc['max'] = ['score' => $hi, 'students' => array_values(array_filter($scores, static fn ($x) => (float) $x['score'] === $hi))];
            $sc['min'] = ['score' => $lo, 'students' => array_values(array_filter($scores, static fn ($x) => (float) $x['score'] === $lo))];
            foreach ($vals as $v) $sc['buckets'][$v < 5 ? '<5' : ($v < 6.5 ? '5–<6,5' : ($v < 8 ? '6,5–<8' : '≥8'))]++;
        }
        $out[] = ['worksheetId' => $w['WorksheetID'], 'no' => (int) $w['No'], 'title' => $w['Title'], 'status' => $w['Status'],
                  'maxAttempts' => (int) $w['MaxAttempts'], 'scorePolicy' => $w['ScorePolicy'],
                  'enrolled' => count($students), 'studentsSubmitted' => count($done), 'studentsDraftOnly' => count($draftOnly),
                  'studentsNotStarted' => count($notStarted), 'notStarted' => array_merge($draftOnly, $notStarted),
                  'submissions' => $subStats, 'scores' => $sc,
                  'special' => array_values($special), 'aiRisk' => $risk, 'roster' => array_values($roster)];
    }
    return $out;
}
