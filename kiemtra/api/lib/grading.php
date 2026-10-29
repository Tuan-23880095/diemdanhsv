<?php
declare(strict_types=1);

/**
 * api/lib/grading.php — chấm bài theo bản đóng băng câu hỏi (SnapshotJSON).
 */

function exam_items_snapshot(string $examId): array
{
    static $cache = [];
    if (!isset($cache[$examId])) {
        $rows = q_all('SELECT QuestionID, Ord, Points, SnapshotJSON FROM kt_exam_items WHERE ExamID = :id ORDER BY Ord', ['id' => $examId]);
        $out = [];
        foreach ($rows as $r) {
            $s = jdec($r['SnapshotJSON']);
            if (!$s) { // phòng hờ: đề chưa đóng băng → đọc trực tiếp ngân hàng
                $qq = q_one('SELECT * FROM kt_questions WHERE QuestionID = :q', ['q' => $r['QuestionID']]);
                $s = $qq ? ['type' => $qq['Type'], 'stem' => $qq['Stem'], 'imageId' => $qq['ImageID'], 'options' => jdec($qq['OptionsJSON']),
                    'answer' => jdec($qq['AnswerJSON']), 'explanation' => (string) $qq['Explanation'], 'cloId' => $qq['CloID'], 'cloCode' => null,
                    'bloom' => $qq['Bloom'] === null ? null : (int) $qq['Bloom'], 'difficulty' => (int) $qq['Difficulty'], 'topic' => (string) $qq['Topic']] : null;
            }
            if ($s) {
                $s['qid'] = $r['QuestionID'];
                $s['ord'] = (int) $r['Ord'];
                $s['points'] = (float) $r['Points'];
                $out[$r['QuestionID']] = $s;
            }
        }
        $cache[$examId] = $out;
    }
    return $cache[$examId];
}

function norm_text(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = str_replace(',', '.', $s);
    return rtrim($s, '. ');
}
function strip_vn(string $s): string
{
    if (class_exists('Normalizer')) {
        $s = (string) Normalizer::normalize($s, Normalizer::FORM_D);
        $s = (string) preg_replace('/\p{Mn}+/u', '', $s);
        return str_replace(['đ', 'Đ'], ['d', 'D'], $s);
    }
    // host không có intl: bảng chuyển tay cho tiếng Việt (chữ thường — đã mb_strtolower trước đó)
    static $map = null;
    if ($map === null) {
        $groups = ['a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'e' => 'èéẹẻẽêềếệểễ', 'i' => 'ìíịỉĩ', 'o' => 'òóọỏõôồốộổỗơờớợởỡ',
            'u' => 'ùúụủũưừứựửữ', 'y' => 'ỳýỵỷỹ', 'd' => 'đ'];
        $map = [];
        foreach ($groups as $base => $chars) {
            foreach (mb_str_split($chars) as $ch) {
                $map[$ch] = $base;
            }
        }
    }
    return strtr($s, $map);
}

/** @return array{earned:float, status:string} status: correct | partial | wrong | blank */
function grade_item(array $item, $given, bool $partial): array
{
    $pts = (float) $item['points'];
    $key = (array) $item['answer'];
    if ($given === null || $given === '' || $given === []) {
        return ['earned' => 0.0, 'status' => 'blank'];
    }
    switch ($item['type']) {
        case 'single':
            $ok = is_string($given) && in_array($given, $key, true);
            return ['earned' => $ok ? $pts : 0.0, 'status' => $ok ? 'correct' : 'wrong'];
        case 'multi':
            $g = array_values(array_unique(array_map('strval', (array) $given)));
            sort($g);
            $k = $key;
            sort($k);
            if ($g === $k) {
                return ['earned' => $pts, 'status' => 'correct'];
            }
            if ($partial) {
                $right = count(array_intersect($g, $k));
                $wrong = count(array_diff($g, $k));
                $frac = max(0, ($right - $wrong) / max(1, count($k)));
                if ($frac > 0) {
                    return ['earned' => round($pts * $frac, 2), 'status' => 'partial'];
                }
            }
            return ['earned' => 0.0, 'status' => 'wrong'];
        case 'short':
            $g = norm_text((string) $given);
            foreach ($key as $acc) {
                $a = norm_text((string) $acc);
                if ($g === $a || strip_vn($g) === strip_vn($a)) {
                    return ['earned' => $pts, 'status' => 'correct'];
                }
            }
            return ['earned' => 0.0, 'status' => 'wrong'];
    }
    return ['earned' => 0.0, 'status' => 'wrong'];
}

/** Chấm + ghi kết quả. Không mở transaction riêng (bên gọi bọc nếu cần). Bỏ qua nếu đã nộp. */
function submit_attempt(array $e, array $a, string $reason): void
{
    $items = exam_items_snapshot((string) $e['ExamID']);
    $ans = jdec($a['AnswersJSON']);
    $partial = exam_settings($e)['partialCredit'];
    $raw = 0.0;
    $max = 0.0;
    $detail = [];
    foreach ($items as $qid => $it) {
        $g = grade_item($it, $ans[$qid] ?? null, $partial);
        $raw += $g['earned'];
        $max += (float) $it['points'];
        $detail[$qid] = ['e' => $g['earned'], 'm' => (float) $it['points'], 's' => $g['status']];
    }
    $score10 = $max > 0 ? round($raw / $max * 10, 2) : 0;
    q("UPDATE kt_attempts SET Status = 'SUBMITTED', SubmittedAt = :n, SubmitReason = :r, RawScore = :raw, MaxScore = :max, Score10 = :s, DetailJSON = :d " .
        "WHERE AttemptID = :a AND Status <> 'SUBMITTED'", [
        'n' => now(), 'r' => $reason, 'raw' => round($raw, 2), 'max' => round($max, 2), 's' => $score10, 'd' => jenc($detail), 'a' => $a['AttemptID'],
    ]);
}
