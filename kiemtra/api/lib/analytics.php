<?php
declare(strict_types=1);

/**
 * api/lib/analytics.php — kết quả, phân tích khảo thí (độ khó p, độ phân biệt D,
 * tương quan điểm-câu r_pb, phân tích phương án nhiễu, độ tin cậy KR-20/Cronbach α,
 * SEM), mức đạt CLO của từng SV và của lớp, đẩy điểm sang bảng điểm diemdanhsv.
 */

function mean(array $x): float { return $x ? array_sum($x) / count($x) : 0.0; }
function sd(array $x): float
{
    $n = count($x);
    if ($n < 2) {
        return 0.0;
    }
    $m = mean($x);
    $s = 0.0;
    foreach ($x as $v) {
        $s += ($v - $m) ** 2;
    }
    return sqrt($s / ($n - 1));
}
function median(array $x): float
{
    if (!$x) {
        return 0.0;
    }
    sort($x);
    $n = count($x);
    return $n % 2 ? (float) $x[intdiv($n, 2)] : ($x[$n / 2 - 1] + $x[$n / 2]) / 2;
}
function pearson(array $a, array $b): ?float
{
    $n = count($a);
    if ($n < 3) {
        return null;
    }
    $ma = mean($a);
    $mb = mean($b);
    $sab = $saa = $sbb = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $sab += ($a[$i] - $ma) * ($b[$i] - $mb);
        $saa += ($a[$i] - $ma) ** 2;
        $sbb += ($b[$i] - $mb) ** 2;
    }
    if ($saa <= 0 || $sbb <= 0) {
        return null;
    }
    return $sab / sqrt($saa * $sbb);
}

function action_results(array $p): void
{
    $me = require_teacher($p);
    $e = load_exam_for_teacher($me, str_param($p, 'examId', 40));
    tick_exam($e);
    api_ok(build_results($e));
}

function build_results(array $e): array
{
    $items = exam_items_snapshot((string) $e['ExamID']);
    $clos = clos_of((string) $e['_class']['CourseID']);
    $closById = array_column($clos, null, 'CloID');
    $atts = q_all("SELECT AttemptID, StudentID, MSSV, FullName, SeatNo, Status, Violations, Unlocks, SubmittedAt, SubmitReason, RawScore, MaxScore, Score10, AnswersJSON, DetailJSON " .
        "FROM kt_attempts WHERE ExamID = :id ORDER BY MSSV", ['id' => $e['ExamID']]);
    $done = array_values(array_filter($atts, fn($a) => $a['Status'] === 'SUBMITTED'));
    $n = count($done);

    // ma trận điểm từng câu
    $qids = array_keys($items);
    $M = [];       // [i][qid] = earned/max (0..1)
    $Ept = [];     // [i][qid] = earned points
    $totals = [];  // raw total
    foreach ($done as $i => $a) {
        $det = jdec($a['DetailJSON']);
        $tot = 0.0;
        foreach ($qids as $qid) {
            $e1 = (float) ($det[$qid]['e'] ?? 0);
            $mx = (float) $items[$qid]['points'];
            $Ept[$i][$qid] = $e1;
            $M[$i][$qid] = $mx > 0 ? $e1 / $mx : 0;
            $tot += $e1;
        }
        $totals[$i] = $tot;
    }

    // độ tin cậy Cronbach α (= KR-20 khi mọi câu chấm 0/1)
    $k = count($qids);
    $alpha = null;
    if ($n >= 3 && $k >= 2) {
        $varTot = sd($totals) ** 2;
        $sumVar = 0.0;
        foreach ($qids as $qid) {
            $sumVar += sd(array_column($Ept, $qid)) ** 2;
        }
        if ($varTot > 0) {
            $alpha = ($k / ($k - 1)) * (1 - $sumVar / $varTot);
        }
    }

    // nhóm cao / thấp 27%
    $order = array_keys($totals);
    usort($order, fn($x, $y) => $totals[$y] <=> $totals[$x]);
    $g = $n >= 4 ? max(1, (int) round($n * 0.27)) : 0;
    $upper = array_slice($order, 0, $g);
    $lower = $g ? array_slice($order, -$g) : [];

    $itemStats = [];
    foreach ($qids as $qid) {
        $it = $items[$qid];
        $col = array_column($M, $qid);
        $pv = $n ? mean($col) : null;
        $D = $g ? mean(array_map(fn($i) => $M[$i][$qid], $upper)) - mean(array_map(fn($i) => $M[$i][$qid], $lower)) : null;
        $rest = [];
        foreach ($totals as $i => $t) {
            $rest[$i] = $t - $Ept[$i][$qid];
        }
        $rpb = $n >= 3 ? pearson(array_values($col), array_values($rest)) : null;
        // thống kê phương án
        $optCount = [];
        $blank = 0;
        $upOpt = [];
        $lowOpt = [];
        foreach ($done as $i => $a) {
            $ans = jdec($a['AnswersJSON']);
            $gv = $ans[$qid] ?? null;
            if ($gv === null || $gv === '' || $gv === []) {
                $blank++;
                continue;
            }
            foreach ($it['type'] === 'short' ? [norm_text((string) $gv)] : (array) $gv as $ch) {
                $optCount[$ch] = ($optCount[$ch] ?? 0) + 1;
                if (in_array($i, $upper, true)) {
                    $upOpt[$ch] = ($upOpt[$ch] ?? 0) + 1;
                }
                if (in_array($i, $lower, true)) {
                    $lowOpt[$ch] = ($lowOpt[$ch] ?? 0) + 1;
                }
            }
        }
        $flags = [];
        if ($pv !== null && $n >= 5) {
            if ($pv > 0.9) {
                $flags[] = 'Quá dễ';
            }
            if ($pv < 0.2) {
                $flags[] = 'Quá khó';
            }
        }
        if ($D !== null && $D < 0.2) {
            $flags[] = $D < 0 ? 'Phân biệt âm — kiểm tra đáp án' : 'Phân biệt kém';
        }
        $distractors = [];
        if ($it['type'] !== 'short') {
            foreach ((array) $it['options'] as $o) {
                $isKey = in_array($o['key'], (array) $it['answer'], true);
                $c = $optCount[$o['key']] ?? 0;
                $distractors[] = ['key' => $o['key'], 'text' => $o['text'], 'isKey' => $isKey, 'count' => $c,
                    'rate' => $n ? $c / $n : 0, 'upper' => $upOpt[$o['key']] ?? 0, 'lower' => $lowOpt[$o['key']] ?? 0];
                if (!$isKey && $n >= 10 && $c === 0) {
                    $flags[] = 'Phương án ' . $o['key'] . ' không ai chọn';
                }
                if (!$isKey && $g && ($upOpt[$o['key']] ?? 0) > ($lowOpt[$o['key']] ?? 0)) {
                    $flags[] = 'Phương án nhiễu ' . $o['key'] . ' hút nhóm giỏi';
                }
            }
        } else {
            arsort($optCount);
            foreach (array_slice($optCount, 0, 6, true) as $txt => $c) {
                $distractors[] = ['key' => (string) $txt, 'text' => (string) $txt, 'isKey' => false, 'count' => $c, 'rate' => $n ? $c / $n : 0];
            }
        }
        $itemStats[] = [
            'qid' => $qid, 'ord' => $it['ord'], 'type' => $it['type'], 'stem' => $it['stem'], 'answer' => $it['answer'],
            'cloId' => $it['cloId'], 'cloCode' => $it['cloId'] ? ($closById[$it['cloId']]['Code'] ?? $it['cloCode']) : null,
            'bloom' => $it['bloom'], 'difficulty' => $it['difficulty'], 'points' => $it['points'],
            'p' => $pv, 'D' => $D, 'rpb' => $rpb, 'blank' => $blank, 'options' => $distractors, 'flags' => array_values(array_unique($flags)),
        ];
    }

    // CLO
    $cloKeys = array_values(array_unique(array_map(fn($it) => $it['cloId'] ?: '_none', $items)));
    $cloOut = [];
    $studentClo = [];
    foreach ($cloKeys as $ck) {
        $qs = array_keys(array_filter($items, fn($it) => ($it['cloId'] ?: '_none') === $ck));
        $maxPts = array_sum(array_map(fn($q) => (float) $items[$q]['points'], $qs));
        $meta = $closById[$ck] ?? null;
        $pass = $meta ? (float) $meta['PassThreshold'] : 50.0;
        $target = $meta ? (float) $meta['TargetRate'] : 70.0;
        $pcts = [];
        foreach ($done as $i => $a) {
            $got = array_sum(array_map(fn($q) => $Ept[$i][$q], $qs));
            $pct = $maxPts > 0 ? $got / $maxPts * 100 : 0;
            $pcts[] = $pct;
            $studentClo[$a['AttemptID']][$ck] = round($pct, 1);
        }
        $achieved = count(array_filter($pcts, fn($v) => $v >= $pass));
        $rate = $n ? $achieved / $n * 100 : null;
        $cloOut[] = [
            'cloId' => $ck === '_none' ? null : $ck, 'code' => $meta['Code'] ?? ($ck === '_none' ? 'Chưa gắn CLO' : '(CLO đã xoá)'),
            'description' => $meta['Description'] ?? '', 'items' => count($qs), 'maxPoints' => $maxPts,
            'weight' => array_sum(array_map(fn($it) => (float) $it['points'], $items)) > 0 ? $maxPts / array_sum(array_map(fn($it) => (float) $it['points'], $items)) * 100 : 0,
            'avgPct' => $n ? mean($pcts) : null, 'passThreshold' => $pass, 'targetRate' => $target,
            'achievedCount' => $achieved, 'achievedRate' => $rate, 'met' => $rate === null ? null : $rate >= $target,
            'bloom' => array_count_values(array_map(fn($q) => (string) ($items[$q]['bloom'] ?? '0'), $qs)),
        ];
    }
    usort($cloOut, fn($x, $y) => ($x['cloId'] === null) <=> ($y['cloId'] === null) ?: strnatcmp($x['code'], $y['code']));

    $scores = array_map(fn($a) => (float) $a['Score10'], $done);
    $hist = array_fill(0, 10, 0);
    foreach ($scores as $s) {
        $hist[min(9, (int) floor($s))]++;
    }
    $sd10 = sd($scores);
    $students = array_map(function ($a) use ($studentClo) {
        return [
            'attemptId' => $a['AttemptID'], 'studentId' => $a['StudentID'], 'mssv' => $a['MSSV'], 'name' => $a['FullName'], 'seat' => (int) $a['SeatNo'],
            'status' => $a['Status'], 'reason' => $a['SubmitReason'], 'submittedAt' => $a['SubmittedAt'], 'violations' => (int) $a['Violations'],
            'unlocks' => (int) $a['Unlocks'], 'raw' => $a['RawScore'] === null ? null : (float) $a['RawScore'], 'max' => $a['MaxScore'] === null ? null : (float) $a['MaxScore'],
            'score10' => $a['Score10'] === null ? null : (float) $a['Score10'], 'clo' => $studentClo[$a['AttemptID']] ?? (object) [],
        ];
    }, $atts);
    $notTaken = q_all("SELECT s.MSSV, s.FullName FROM enrollments en JOIN students s ON s.StudentID = en.StudentID WHERE en.ClassID = :c AND en.Status = 'ACTIVE' " .
        'AND s.StudentID NOT IN (SELECT StudentID FROM kt_attempts WHERE ExamID = :e) ORDER BY s.MSSV', ['c' => $e['ClassID'], 'e' => $e['ExamID']]);
    $bloomDist = array_count_values(array_map(fn($it) => (string) ($it['bloom'] ?? '0'), $items));
    ksort($bloomDist);

    return [
        'exam' => ['ExamID' => $e['ExamID'], 'Title' => $e['Title'], 'Status' => $e['Status'], 'ClassCode' => $e['_class']['ClassCode'],
            'CourseCode' => $e['_class']['CourseCode'], 'CourseName' => $e['_class']['CourseName'], 'StartedAt' => $e['StartedAt'], 'ClosedAt' => $e['ClosedAt'],
            'DurationMinutes' => (int) $e['DurationMinutes'], 'ResultsPublished' => (int) $e['ResultsPublished'], 'ItemCount' => $k,
            'MaxPoints' => array_sum(array_map(fn($it) => (float) $it['points'], $items))],
        'stats' => ['n' => $n, 'joined' => count($atts), 'notTaken' => count($notTaken), 'mean' => $n ? mean($scores) : null, 'sd' => $sd10,
            'median' => median($scores), 'min' => $n ? min($scores) : null, 'max' => $n ? max($scores) : null,
            'passRate' => $n ? count(array_filter($scores, fn($s) => $s >= 5)) / $n * 100 : null,
            'alpha' => $alpha, 'sem' => $alpha !== null ? $sd10 * sqrt(max(0, 1 - $alpha)) : null, 'hist' => $hist,
            'violators' => count(array_filter($atts, fn($a) => (int) $a['Violations'] > 0))],
        'items' => $itemStats,
        'clos' => $cloOut,
        'bloom' => $bloomDist,
        'students' => $students,
        'notTaken' => $notTaken,
    ];
}

/** Đẩy Score10 của bài đã nộp vào một cột điểm của lớp trên diemdanhsv (tạo cột nếu chưa có). */
function action_pushGrades(array $p): void
{
    $me = require_teacher($p);
    $e = load_exam_for_teacher($me, str_param($p, 'examId', 40));
    if ($e['Status'] !== 'CLOSED') {
        fail('Hãy thu bài (đóng phòng) trước khi chuyển điểm.');
    }
    $name = str_param($p, 'columnName', 100) ?: 'Giữa kỳ';
    $weight = max(0, min(100, (float) ($p['weight'] ?? 30)));
    $rows = q_all("SELECT StudentID, Score10 FROM kt_attempts WHERE ExamID = :e AND Status = 'SUBMITTED'", ['e' => $e['ExamID']]);
    $res = tx(function () use ($e, $name, $weight, $rows, $me) {
        $col = q_one('SELECT GradeColumnID, Weight FROM grade_columns WHERE ClassID = :c AND Name = :n' . for_update(), ['c' => $e['ClassID'], 'n' => $name]);
        $created = false;
        if ($col) {
            $colId = $col['GradeColumnID'];
            q("UPDATE grade_columns SET Weight = :w, Status = 'ACTIVE' WHERE GradeColumnID = :id", ['w' => $weight, 'id' => $colId]);
        } else {
            $colId = new_id('GCL');
            $max = (int) q_val('SELECT COALESCE(MAX(SortOrder),0) FROM grade_columns WHERE ClassID = :c', ['c' => $e['ClassID']]);
            q("INSERT INTO grade_columns (GradeColumnID, ClassID, Name, Weight, SortOrder, Status) VALUES (:id, :c, :n, :w, :o, 'ACTIVE')",
                ['id' => $colId, 'c' => $e['ClassID'], 'n' => $name, 'w' => $weight, 'o' => $max + 1]);
            $created = true;
        }
        $written = 0;
        foreach ($rows as $r) {
            $ex = q_val('SELECT GradeID FROM grades WHERE StudentID = :s AND GradeColumnID = :c', ['s' => $r['StudentID'], 'c' => $colId]);
            if ($ex) {
                q('UPDATE grades SET Score = :sc, UpdatedBy = :by, UpdatedAt = :n WHERE GradeID = :id', ['sc' => $r['Score10'], 'by' => $me['userId'], 'n' => now(), 'id' => $ex]);
            } else {
                q('INSERT INTO grades (GradeID, StudentID, ClassID, GradeColumnID, Score, UpdatedBy, UpdatedAt) VALUES (:id, :s, :c, :col, :sc, :by, :n)',
                    ['id' => new_id('GRD'), 's' => $r['StudentID'], 'c' => $e['ClassID'], 'col' => $colId, 'sc' => $r['Score10'], 'by' => $me['userId'], 'n' => now()]);
            }
            $written++;
        }
        return ['columnId' => $colId, 'created' => $created, 'written' => $written];
    });
    try {
        q('INSERT INTO audit_log (LogID, Actor, ActorRole, Action, TargetType, TargetID, Data, IP) VALUES (:id, :a, :r, :ac, :tt, :tid, :d, :ip)', [
            'id' => new_id('LOG'), 'a' => $me['userId'], 'r' => $me['role'], 'ac' => 'KT_PUSH_GRADES', 'tt' => 'CLASS', 'tid' => $e['ClassID'],
            'd' => jenc(['examId' => $e['ExamID'], 'column' => $name, 'weight' => $weight, 'written' => $res['written']]), 'ip' => client_ip(),
        ]);
    } catch (PDOException $ignored) {
    }
    api_ok($res + ['columnName' => $name, 'weight' => $weight]);
}

/** Báo cáo CLO tổng hợp nhiều đề của cùng học phần (vd giữa kỳ + cuối kỳ), theo lớp. */
function action_cloReport(array $p): void
{
    $me = require_teacher($p);
    $classId = str_param($p, 'classId', 40);
    $cls = assert_class($me, $classId);
    $exams = q_all("SELECT * FROM kt_exams WHERE ClassID = :c AND Status = 'CLOSED' ORDER BY StartedAt", ['c' => $classId]);
    $out = [];
    foreach ($exams as $ex) {
        $ex['_class'] = $cls;
        $r = build_results($ex);
        $out[] = ['examId' => $ex['ExamID'], 'title' => $ex['Title'], 'startedAt' => $ex['StartedAt'], 'n' => $r['stats']['n'], 'clos' => $r['clos']];
    }
    api_ok(['class' => $cls, 'clos' => clos_of((string) $cls['CourseID']), 'exams' => $out]);
}
