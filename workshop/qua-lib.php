<?php
/**
 * qua-lib.php — quà tặng trong workshop (chỉ Bảng diễn giả gọi, sau khi kiểm PIN).
 *
 *  - Quay số may mắn: chọn ngẫu nhiên (random_int) trong các THIẾT BỊ đã làm ≥ min hoạt động workshop.
 *    Chỉ tính 7 hoạt động trên điện thoại; KHÔNG tính phiếu nghiên cứu nc0–nc3 (quà không gắn với khảo sát).
 *    Mã may mắn = 5 ký tự đầu của mã thiết bị ẩn danh (WS.clientId), viết hoa — trang SV hiện cùng mã.
 *  - Xếp hạng mục tiêu SMART: điểm gợi ý tự động (luật đơn giản) và, nếu máy chủ có khóa Gemini,
 *    nhờ Gemini chấm theo SMART. Diễn giả là người quyết định cuối cùng.
 *
 * Nạp từ workshop/api.php sau ws_* và api_ok/api_fail. Mở trực tiếp trên web → 404.
 */
if (!function_exists('api_fail')) {
    http_response_code(404);
    exit;
}

const WS_QUA_ACTS = ['khoidong', 'hinh', 'muctieu', 'eisen', 'banhxe', 'camket', 'danhgia'];

function ws_qua_code(string $client): string
{
    return strtoupper(substr($client, 0, 5));
}

function ws_qua_key(string $sess): string
{
    return substr($sess, 0, 17) . '-lucky';   // khóa phụ trong ws_state (≤ 24 ký tự)
}

/** client => số hoạt động workshop khác nhau đã gửi. */
function ws_qua_counts(string $sess): array
{
    return ws_run(function (PDO $pdo) use ($sess) {
        $in = implode(',', array_fill(0, count(WS_QUA_ACTS), '?'));
        $st = $pdo->prepare("SELECT client, COUNT(DISTINCT act) AS n FROM ws_votes WHERE sess = ? AND act IN ($in) GROUP BY client");
        $st->execute(array_merge([$sess], WS_QUA_ACTS));
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(string) $r['client']] = (int) $r['n'];
        }
        return $out;
    });
}

function ws_qua_winners(string $sess): array
{
    $raw = ws_run(function (PDO $pdo) use ($sess) {
        $st = $pdo->prepare('SELECT data FROM ws_state WHERE sess = ?');
        $st->execute([ws_qua_key($sess)]);
        return $st->fetchColumn();
    });
    $d = $raw ? json_decode((string) $raw, true) : null;
    return is_array($d['winners'] ?? null) ? $d['winners'] : [];
}

function ws_qua_save(string $sess, array $winners): void
{
    ws_run(function (PDO $pdo) use ($sess, $winners) {
        $k = ws_qua_key($sess);
        $pdo->prepare('DELETE FROM ws_state WHERE sess = ?')->execute([$k]);
        if ($winners) {
            $pdo->prepare('INSERT INTO ws_state (sess, data, ts) VALUES (?, ?, ?)')->execute([$k, json_encode(['winners' => $winners]), time()]);
        }
    });
}

/** Mô tả kết quả cho Bảng diễn giả (không trả mã thiết bị đầy đủ). */
function ws_qua_view(array $counts, array $winners, array $new, int $min, int $left): array
{
    $codes = [];
    foreach (array_keys($counts) as $c) {
        $codes[ws_qua_code($c)] = ($codes[ws_qua_code($c)] ?? 0) + 1;
    }
    $show = fn (array $w) => ['code' => $w['code'], 'n' => $w['n'], 'trung' => ($codes[$w['code']] ?? 0) > 1];
    return [
        'moi' => array_map($show, $new),
        'da_trung' => array_map($show, $winners),
        'du_dieu_kien' => count(array_filter($counts, fn ($k) => $k >= $min)),
        'thiet_bi' => count($counts),
        'con_lai' => $left,
        'min' => $min,
    ];
}

function ws_qua_draw(string $sess, int $n, int $min): array
{
    $counts = ws_qua_counts($sess);
    $prev = ws_qua_winners($sess);
    $taken = array_column($prev, 'client');
    $pool = [];
    foreach ($counts as $c => $k) {
        if ($k >= $min && !in_array($c, $taken, true)) {
            $pool[] = $c;
        }
    }
    $new = [];
    for ($i = 0; $i < $n && $pool; $i++) {
        $j = random_int(0, count($pool) - 1);
        $c = $pool[$j];
        array_splice($pool, $j, 1);
        $new[] = ['client' => $c, 'code' => ws_qua_code($c), 'n' => $counts[$c], 't' => time()];
    }
    if ($new) {
        ws_qua_save($sess, array_merge($prev, $new));
    }
    return ws_qua_view($counts, $prev, $new, $min, count($pool));
}

function ws_qua_info(string $sess, int $min): array
{
    $counts = ws_qua_counts($sess);
    $prev = ws_qua_winners($sess);
    $taken = array_column($prev, 'client');
    $left = count(array_filter($counts, fn ($k, $c) => $k >= $min && !in_array($c, $taken, true), ARRAY_FILTER_USE_BOTH));
    return ws_qua_view($counts, $prev, [], $min, $left);
}

/* ------------------------------------------------------------------ mục tiêu SMART */

/** Điểm gợi ý 0–10 theo dấu hiệu SMART trong câu chữ + phần tự kiểm của sinh viên. */
function ws_qua_smart_score(string $t, array $f): array
{
    $s = 0.0;
    $dau = [];
    $len = mb_strlen($t);
    if ($len >= 30) { $s += 2; $dau[] = 'cụ thể'; } elseif ($len >= 15) { $s += 1; }
    if (preg_match('/\d/u', $t)) { $s += 2; $dau[] = 'có con số'; }
    if (preg_match('/tuần|tháng|ngày|học kỳ|học kì|\bhk\s*\d|\d{1,2}\s*\/\s*\d{1,2}|trước|cuối kỳ|giữa kỳ|hết|mỗi (sáng|tối|ngày|tuần)/iu', $t)) { $s += 2; $dau[] = 'có thời hạn'; }
    if (preg_match('/học|ôn|làm|đọc|viết|luyện|tập|ngủ|nộp|giải|hoàn thành|đạt|dành|tham gia|đăng ký/iu', $t)) { $s += 1; $dau[] = 'có hành động'; }
    $tk = count(array_filter($f));
    $s += min(3, $tk * 0.6);
    return ['diem' => round(min(10, $s), 1), 'dau' => $dau, 'tu_kiem' => $tk];
}

/** Mục tiêu mới nhất của mỗi thiết bị, kèm điểm gợi ý, xếp giảm dần. */
function ws_qua_goals(string $sess): array
{
    $rows = ws_run(function (PDO $pdo) use ($sess) {
        $st = $pdo->prepare("SELECT client, data FROM ws_votes WHERE sess = ? AND act = 'muctieu' ORDER BY ts");
        $st->execute([$sess]);
        return $st->fetchAll();
    });
    $by = [];
    foreach ($rows as $r) {
        $d = json_decode((string) $r['data'], true) ?: [];
        $t = trim((string) ($d['text'] ?? ''));
        if ($t !== '') {
            $by[(string) $r['client']] = ['text' => $t, 'f' => (array) ($d['f'] ?? [])];
        }
    }
    $seen = [];
    foreach ($by as $g) {
        $k = mb_strtolower(preg_replace('/\s+/u', ' ', $g['text']));
        $seen[$k] = ($seen[$k] ?? 0) + 1;
    }
    $out = [];
    foreach ($by as $c => $g) {
        $sc = ws_qua_smart_score($g['text'], $g['f']);
        $k = mb_strtolower(preg_replace('/\s+/u', ' ', $g['text']));
        $out[] = ['code' => ws_qua_code($c), 'text' => $g['text'], 'diem' => $sc['diem'], 'dau' => $sc['dau'],
                  'tu_kiem' => $sc['tu_kiem'], 'trung_noi_dung' => $seen[$k] > 1];
    }
    usort($out, fn ($a, $b) => $b['diem'] <=> $a['diem'] ?: mb_strlen($b['text']) <=> mb_strlen($a['text']));
    return $out;
}

/** Nhờ Gemini chấm (chỉ gửi nội dung mục tiêu, không gửi mã thiết bị). Trả danh sách đã gắn điểm AI. */
function ws_qua_smart_ai(array $goals, int $top): array
{
    $pool = array_slice(array_values(array_filter($goals, fn ($g) => !$g['trung_noi_dung'])), 0, 40);
    if (!$pool) {
        api_fail('Chưa có mục tiêu nào để chấm.');
    }
    $list = [];
    foreach ($pool as $i => $g) {
        $list[] = ($i + 1) . '. ' . str_replace(["\r", "\n"], ' ', $g['text']);
    }
    $prompt = "Bạn là giám khảo một workshop kỹ năng quản lý thời gian cho sinh viên năm nhất đại học ở Việt Nam.\n"
        . "Dưới đây là các mục tiêu học kỳ do sinh viên tự viết (ẩn danh). Chấm mỗi mục tiêu theo 5 tiêu chí SMART, mỗi tiêu chí 0–2 điểm:\n"
        . "S cụ thể (làm gì, môn/việc nào), M đo được (có con số/chỉ báo), A khả thi với sinh viên năm nhất, R phù hợp với việc học/phát triển bản thân, T có thời hạn rõ.\n"
        . "Chỉ chọn " . $top . " mục tiêu tốt nhất. Ưu tiên mục tiêu gắn với HÀNH ĐỘNG sinh viên kiểm soát được hơn là chỉ nêu điểm số.\n"
        . "Trả về DUY NHẤT một mảng JSON, mỗi phần tử: {\"so\": số thứ tự, \"S\":0-2, \"M\":0-2, \"A\":0-2, \"R\":0-2, \"T\":0-2, \"tong\":0-10, \"nhan_xet\": \"một câu tiếng Việt ≤ 25 từ, nêu điểm mạnh\"}, xếp tổng điểm giảm dần.\n\n"
        . implode("\n", $list);
    $txt = '';
    if (PHP_SAPI === 'cli-server' && getenv('WS_GEMINI_FAKE')) {          // chỉ dùng khi chạy thử cục bộ
        $txt = (string) getenv('WS_GEMINI_FAKE');
        $model = 'fake';
    } else {
        if (!function_exists('app_config')) {
            api_fail('Máy chủ chưa nạp cấu hình — không gọi được Gemini.');
        }
        require_once dirname(__DIR__) . '/api/lib/khtd.php';
        $g = khtd_gemini_config();
        if ($g['key'] === '') {
            api_fail('Máy chủ chưa có khóa Gemini (private/config.php → gemini.api_key). Dùng bảng xếp hạng tự động.');
        }
        try {
            $cand = khtd_gemini_candidates($g);
            [$txt, $model] = khtd_gemini_generate($cand['models'], $g['key'], [
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.2, 'responseMimeType' => 'application/json', 'maxOutputTokens' => 4096],
            ]);
        } catch (Throwable $e) {
            api_fail('Gemini lỗi: ' . mb_substr($e->getMessage(), 0, 200));
        }
    }
    $txt = trim(preg_replace('/^```(?:json)?|```$/m', '', $txt));
    $arr = json_decode($txt, true);
    if (!is_array($arr)) {
        api_fail('Gemini trả kết quả không đọc được. Dùng bảng xếp hạng tự động.');
    }
    $out = [];
    foreach ($arr as $r) {
        $i = (int) ($r['so'] ?? 0) - 1;
        if (!isset($pool[$i])) {
            continue;
        }
        $g = $pool[$i];
        $sum = 0;
        $part = [];
        foreach (['S', 'M', 'A', 'R', 'T'] as $k) {
            $v = max(0, min(2, (int) ($r[$k] ?? 0)));
            $part[$k] = $v;
            $sum += $v;
        }
        $out[] = ['code' => $g['code'], 'text' => $g['text'], 'diem' => $sum, 'smart' => $part,
                  'nhan_xet' => mb_substr((string) ($r['nhan_xet'] ?? ''), 0, 200), 'tu_kiem' => $g['tu_kiem']];
        if (count($out) >= $top) {
            break;
        }
    }
    usort($out, fn ($a, $b) => $b['diem'] <=> $a['diem']);
    return ['items' => $out, 'model' => $model, 'xet' => count($pool)];
}
