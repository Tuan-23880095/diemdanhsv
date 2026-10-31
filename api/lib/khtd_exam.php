<?php
/**
 * api/lib/khtd_exam.php — Phiếu luyện thi cuối khóa (nhận diện khoáng vật & đá) và dự báo điểm thi (docs/04 mục 33).
 *
 *  - Phiếu có schema['exam'] (phiếu số 7 mặc định). Mỗi lần luyện SV chọn số mẫu (1–10); thời gian theo nhịp đề thi
 *    ĐG1.1 (10 mẫu / 30 phút → 3 phút/mẫu). Giờ bắt đầu do MÁY CHỦ ghi (answers._exam), trình duyệt không sửa được.
 *  - Chấm từng mẫu theo rubric bậc thang 1 / 0,75 / 0,5 / 0,25 / 0 (khtd_exam_score_sample) bằng bộ tri thức chuẩn
 *    (khtd_kb.php); Gemini (nếu có) xem thêm ảnh mẫu và chỉnh trong giới hạn. Điểm lần luyện = trung bình × 10 =
 *    "điểm dự báo bài thi". Điểm này KHÔNG vào điểm tổng thực tập — chỉ dùng đề xuất điểm cộng và đánh giá thái độ.
 *  - Với các phiếu 1–6, bảng mô tả mẫu (cột "Tên …") được chấm thử theo cùng rubric → "dự báo điểm thi".
 */

/** Trường của một mẫu trong phiếu luyện thi — khớp "Bảng nhận diện khoáng vật / đá" của bài thi. */
const KHTD_EXAM_FIELDS = [
    'loai' => 'Loại mẫu', 'so_hieu' => 'Số hiệu', 'ten' => 'Tên khoáng vật / tên đá',
    'tphh' => 'Thành phần hóa học', 'do_cung' => 'Độ cứng', 'cat_khai' => 'Cát khai', 'tinh_the' => 'Dạng tinh thể',
    'vet_vach' => 'Vết vạch', 'ty_trong' => 'Tỷ trọng', 'mau' => 'Màu', 'anh' => 'Ánh', 'khac' => 'Tính chất khác',
    'nhom' => 'Nhóm đá', 'thanh_phan' => 'Thành phần (khoáng vật chính)', 'kien_truc' => 'Kiến trúc (texture)',
    'dac_diem' => 'Đặc điểm nhận diện', 'dieu_kien' => 'Điều kiện thành tạo',
];
const KHTD_EXAM_KV_FIELDS = ['so_hieu', 'ten', 'tphh', 'do_cung', 'cat_khai', 'tinh_the', 'vet_vach', 'ty_trong', 'mau', 'anh', 'khac'];
const KHTD_EXAM_DA_FIELDS = ['so_hieu', 'ten', 'nhom', 'thanh_phan', 'kien_truc', 'dac_diem', 'dieu_kien'];
const KHTD_EXAM_LEVELS = [0, 0.25, 0.5, 0.75, 1];
const KHTD_EXAM_MAX_SAMPLES = 10;

function khtd_is_exam(array $schema): bool { return is_array($schema['exam'] ?? null); }

/** Bảng mẫu của phiếu luyện thi (section có layout 'specimens'). */
function khtd_exam_section(array $schema): ?array
{
    foreach ($schema['sections'] ?? [] as $s) if (($s['layout'] ?? '') === 'specimens') return $s;
    return null;
}

function khtd_exam_cfg(array $schema): array
{
    $e = (array) ($schema['exam'] ?? []);
    return [
        'secPerSample' => max(60, (int) ($e['sec_per_sample'] ?? 180)),
        'maxSamples'   => max(1, min(KHTD_EXAM_MAX_SAMPLES, (int) ($e['max_samples'] ?? KHTD_EXAM_MAX_SAMPLES))),
        'graceSec'     => max(0, (int) ($e['grace_sec'] ?? 60)),
        'bonus'        => array_merge(['step' => 5, 'add' => 0.25, 'cap' => 1.0, 'min_level' => 0.75], (array) ($e['bonus'] ?? [])),
        'key'          => (array) ($e['key'] ?? []),
    ];
}

/* ---------------- Ánh xạ cột bảng → trường ---------------- */

/** Ánh xạ chỉ số cột → trường chuẩn. Bảng luyện thi khai báo sẵn 'fields'; bảng phiếu 1–6 đoán theo tiêu đề cột. */
function khtd_exam_colmap(array $section): array
{
    if (!empty($section['fields']) && is_array($section['fields'])) return array_values($section['fields']);
    $out = [];
    foreach ((array) ($section['columns'] ?? []) as $ci => $label) {
        $t = trim(khtd_kb_text((string) $label));
        $f = null;
        if (preg_match('/^ten\b/', $t)) $f = 'ten';
        elseif (str_contains($t, 'so hieu')) $f = 'so_hieu';
        elseif (str_contains($t, 'thanh phan hoa hoc')) $f = 'tphh';
        elseif (str_contains($t, 'do cung') && !str_contains($t, 'hcl')) $f = 'do_cung';
        elseif (str_contains($t, 'cat khai')) $f = 'cat_khai';
        elseif (str_contains($t, 'tinh the') && !str_contains($t, 'kich thuoc')) $f = 'tinh_the';
        elseif (str_contains($t, 'vet vach')) $f = 'vet_vach';
        elseif (str_contains($t, 'ty trong')) $f = 'ty_trong';
        elseif (preg_match('/^mau\b/', $t)) $f = 'mau';
        elseif (preg_match('/^anh\b/', $t)) $f = 'anh';
        elseif (preg_match('/^nhom\b/', $t)) $f = 'nhom';
        elseif (str_contains($t, 'kien truc') || str_contains($t, 'co phien') || str_contains($t, 'co hat') || str_contains($t, 'kich thuoc hat')) $f = 'kien_truc';
        elseif (str_contains($t, 'khoang vat') || str_contains($t, 'thanh phan')) $f = 'thanh_phan';
        elseif (str_contains($t, 'dac diem') || str_contains($t, 'dau hieu quyet dinh') || str_contains($t, 'cau tao')) $f = 'dac_diem';
        elseif (str_contains($t, 'dieu kien') || str_contains($t, 'moi truong') || str_contains($t, 'xam nhap')) $f = 'dieu_kien';
        elseif (str_contains($t, 'dau hieu') || str_contains($t, 'tinh chat khac') || $t === 'hcl' || str_contains($t, 'hcl')) $f = 'khac';
        elseif (str_contains($t, 'khoang vat da')) $f = 'loai';
        $out[$ci] = $f;
    }
    return $out;
}

/** Một hàng bảng → bản ghi {trường: chữ}. Trường lặp (vd hai cột "dấu hiệu") được nối lại. */
function khtd_exam_record(array $colmap, array $cells): array
{
    $r = [];
    foreach ($colmap as $ci => $f) {
        if ($f === null) continue;
        $v = trim((string) ($cells[$ci] ?? ''));
        if ($v === '') continue;
        $r[$f] = isset($r[$f]) ? $r[$f] . '; ' . $v : $v;
    }
    return $r;
}

function khtd_exam_sohieu_norm(string $s): string
{
    $t = str_replace(' ', '', trim(khtd_kb_text($s)));
    return (string) preg_replace_callback('/\d+/', static fn ($m) => (string) (int) $m[0], $t);
}

/** Loại mẫu SV chọn: 'kv' | 'da' | null. */
function khtd_exam_kind(string $v): ?string
{
    $t = khtd_kb_text($v);
    if (khtd_kb_hit($t, 'khoang vat') || khtd_kb_hit($t, 'kv') || khtd_kb_hit($t, 'mineral')) return 'kv';
    if (khtd_kb_hit($t, 'da') || khtd_kb_hit($t, 'rock')) return 'da';
    return null;
}

/* ---------------- Chấm một mẫu theo rubric bậc thang ---------------- */

/**
 * Chấm một mẫu. $opt: need_so_hieu (bool), expected (tên đúng theo đáp án bộ mẫu, hoặc null), kind (gợi ý loại).
 * Trả: level, kind, key, ten_chuan, ok (trường → bool), thieu (các ý còn thiếu để lên bậc), ly_do, chuan (dữ liệu chuẩn).
 */
function khtd_exam_score_sample(array $rec, array $opt = []): array
{
    $name = trim((string) ($rec['ten'] ?? ''));
    $so = trim((string) ($rec['so_hieu'] ?? ''));
    $kindHint = $opt['kind'] ?? (isset($rec['loai']) ? khtd_exam_kind((string) $rec['loai']) : null);
    $res = ['level' => 0.0, 'kind' => $kindHint, 'key' => null, 'ten_chuan' => '', 'ten_sv' => $name, 'so_hieu' => $so,
            'ok' => [], 'thieu' => [], 'fix' => [], 'ly_do' => '', 'chuan' => '', 'trong' => false];
    $filled = array_filter($rec, static fn ($v) => trim((string) $v) !== '');
    unset($filled['loai']);
    if (!$filled) { $res['trong'] = true; $res['ly_do'] = 'Bỏ trống.'; return $res; }
    if ($name === '') { $res['ly_do'] = 'Chưa ghi tên khoáng vật / đá → 0 điểm.'; $res['thieu'][] = 'tên mẫu'; return $res; }

    $hit = khtd_kb_find($name, $kindHint) ?? khtd_kb_find($name, null);
    if (!$hit) { $res['ly_do'] = 'Tên "' . $name . '" không có trong danh mục mẫu của học phần (hoặc viết sai) → 0 điểm.'; return $res; }
    $res['kind'] = $hit['kind']; $res['key'] = $hit['key']; $res['ten_chuan'] = $hit['entry']['ten'];
    $res['chuan'] = khtd_kb_summary($hit['kind'], $hit['key']);
    $e = $hit['entry'];

    // Đáp án bộ mẫu (GV nhập): tên phải khớp mẫu mang số hiệu đó.
    if (($opt['expected'] ?? null) !== null) {
        $exp = khtd_kb_find((string) $opt['expected']);
        $same = $exp ? ($exp['key'] === $hit['key'] && $exp['kind'] === $hit['kind'])
                     : trim(khtd_kb_text((string) $opt['expected'])) === trim(khtd_kb_text($name));
        if (!$same && $exp && !empty($exp['entry']['alt_ok']) && khtd_kb_any($name, $exp['entry']['alt_ok'])) {   // tên gọi mơ hồ được chấp nhận
            $same = true; $hit = $exp;
            $res['kind'] = $hit['kind']; $res['key'] = $hit['key']; $res['ten_chuan'] = $hit['entry']['ten']; $res['chuan'] = khtd_kb_summary($hit['kind'], $hit['key']);
        }
        if (!$same) { $res['ly_do'] = 'Sai tên: mẫu ' . $so . ' không phải ' . $e['ten'] . ' → 0 điểm.'; $res['ok']['ten'] = false; return $res; }
        $e = $hit['entry'];
    }
    $res['ok']['ten'] = true;
    if (!empty($opt['need_so_hieu']) && $so === '') { $res['ly_do'] = 'Không ghi số hiệu mẫu → 0 điểm (theo rubric).'; $res['thieu'][] = 'số hiệu mẫu'; return $res; }
    if (($opt['expected_missing'] ?? false)) { $res['ly_do'] = 'Số hiệu "' . $so . '" không có trong bộ mẫu → 0 điểm.'; return $res; }

    if ($hit['kind'] === 'kv') {
        $T = khtd_kb_any((string) ($rec['tphh'] ?? ''), $e['tphh_ok']);
        $H = khtd_kb_range_ok((string) ($rec['do_cung'] ?? ''), $e['cung'], 0.5, 1.5);
        $C = khtd_kb_any((string) ($rec['cat_khai'] ?? ''), $e['cat_khai_ok']);
        $X = khtd_kb_any((string) ($rec['vet_vach'] ?? ''), $e['vach_ok']) || khtd_kb_any((string) ($rec['mau'] ?? ''), $e['mau_ok'])
          || khtd_kb_any((string) ($rec['khac'] ?? ''), $e['khac_ok']) || khtd_kb_any((string) ($rec['anh'] ?? ''), $e['anh_ok'])
          || khtd_kb_any((string) ($rec['tinh_the'] ?? ''), $e['tinh_the_ok'])
          || khtd_kb_range_ok((string) ($rec['ty_trong'] ?? ''), $e['ty_trong'], 0.15, 1.0);
        $res['ok'] += ['tphh' => $T, 'do_cung' => $H, 'cat_khai' => $C, 'khac' => $X];
        $hTxt = $e['cung'][0] == $e['cung'][1] ? (string) $e['cung'][0] : $e['cung'][0] . '–' . $e['cung'][1];
        $std = ['tphh' => ['Thành phần hóa học', $e['tphh']], 'do_cung' => ['Độ cứng', str_replace('.', ',', $hTxt) . ' (thang Mohs)'],
                'cat_khai' => ['Cát khai', $e['cat_khai']], 'khac' => ['Vết vạch / màu / tính chất khác', 'vết vạch ' . $e['vach'] . '; màu ' . $e['mau'] . '; ' . $e['khac']]];
        $given = ['tphh' => $rec['tphh'] ?? '', 'do_cung' => $rec['do_cung'] ?? '', 'cat_khai' => $rec['cat_khai'] ?? '',
                  'khac' => implode('; ', array_filter([$rec['vet_vach'] ?? '', $rec['mau'] ?? '', $rec['khac'] ?? '']))];
        if ($T && $H && $C && $X) $lv = 1.0;
        elseif ($T && $H && $C) $lv = 0.75;
        elseif ($T && ($H || $C)) $lv = 0.5;
        elseif ($T || $H || $C) $lv = 0.25;
        else $lv = 0.0;
        if (!$T) $res['thieu'][] = 'thành phần hóa học';
        if (!$H) $res['thieu'][] = 'độ cứng';
        if (!$C) $res['thieu'][] = 'cát khai';
        if (!$X) $res['thieu'][] = 'vết vạch / màu / tính chất khác';
        $res['ly_do'] = $lv === 0.0 ? 'Đúng tên nhưng chưa có thành phần hóa học, độ cứng hay cát khai đúng → 0 điểm (rubric khoáng vật).' : '';
    } else {
        $grpTxt = (string) ($rec['nhom'] ?? '');
        $kb = khtd_kb();
        $G = khtd_kb_any($grpTxt, $kb['group_ok'][$e['nhom']]);
        foreach ($kb['group_ok'] as $g => $toks) if ($g !== $e['nhom'] && $G && khtd_kb_any($grpTxt, array_diff($toks, $kb['group_ok'][$e['nhom']]))) $G = false;
        $K = khtd_kb_any((string) ($rec['kien_truc'] ?? ''), $e['kt_ok']);
        $mTxt = (string) ($rec['thanh_phan'] ?? '');
        $nm = 0; foreach ($e['kv_ok'] as $grp) if (khtd_kb_any($mTxt, $grp)) $nm++;
        $M = $nm >= min((int) $e['kv_need'], count($e['kv_ok']));
        $D = khtd_kb_any((string) ($rec['dac_diem'] ?? ''), $e['dd_ok']);
        $res['ok'] += ['nhom' => $G, 'kien_truc' => $K, 'thanh_phan' => $M, 'dac_diem' => $D];
        $std = ['nhom' => ['Nhóm đá', $e['nhom_txt']], 'kien_truc' => ['Kiến trúc', $e['kt']], 'thanh_phan' => ['Khoáng vật chính', $e['kv']], 'dac_diem' => ['Đặc điểm nhận diện', $e['dd']]];
        $given = ['nhom' => $grpTxt, 'kien_truc' => $rec['kien_truc'] ?? '', 'thanh_phan' => $mTxt, 'dac_diem' => $rec['dac_diem'] ?? ''];
        if ($G && $K && $M && $D) $lv = 1.0;
        elseif ($G && $K && $M) $lv = 0.75;
        elseif ($G && $K) $lv = 0.5;
        else $lv = 0.25;
        if (!$G) $res['thieu'][] = 'nhóm đá';
        if (!$K) $res['thieu'][] = 'kiến trúc';
        if (!$M) $res['thieu'][] = 'thành phần khoáng vật chính';
        if (!$D) $res['thieu'][] = 'đặc điểm nhận diện';
        // Nhóm phụ (xâm nhập ↔ phun trào) ghi chú, không trừ điểm.
        $sub = khtd_kb_text((string) $e['nhom_txt']);
        if ($G && khtd_kb_hit($sub, 'xam nhap') && khtd_kb_any($grpTxt, ['phun trao'])) $res['ly_do'] = 'Lưu ý: ' . $e['ten'] . ' là đá xâm nhập, không phải phun trào.';
        if ($G && khtd_kb_hit($sub, 'phun trao') && khtd_kb_any($grpTxt, ['xam nhap'])) $res['ly_do'] = 'Lưu ý: ' . $e['ten'] . ' là đá phun trào, không phải xâm nhập.';
    }
    $res['level'] = $lv;
    foreach ($std as $f => [$label, $chuan]) {
        if (!empty($res['ok'][$f])) continue;
        $res['fix'][] = ['truong' => $label, 'bai_lam' => trim((string) $given[$f]), 'chuan' => $chuan];
    }
    return $res;
}

/** Nhãn kết quả cho bảng đối chiếu. */
function khtd_exam_verdict(float $lv, bool $empty): string
{
    if ($empty) return 'trống';
    if ($lv >= 1) return 'đúng';
    if ($lv > 0) return 'đúng một phần';
    return 'sai';
}

/* ---------------- Chấm cả lần luyện ---------------- */

/** Số mẫu của lần luyện (SV chọn khi bắt đầu), mặc định = số hàng đã điền. */
function khtd_exam_count(array $answers, array $section): int
{
    $n = (int) ($answers['_exam']['count'] ?? 0);
    $rows = count((array) ($section['rows'] ?? []));
    if ($n < 1) {
        foreach ((array) ($answers[$section['id']] ?? []) as $ri => $cells) {
            if (array_filter((array) $cells, static fn ($v) => trim((string) $v) !== '')) $n = max($n, (int) $ri + 1);
        }
    }
    return max(1, min($rows, $n));
}

/** Đáp án bộ mẫu GV nhập: [số hiệu chuẩn hoá => tên]. */
function khtd_exam_keymap(array $schema): array
{
    $m = [];
    foreach (khtd_exam_cfg($schema)['key'] as $row) {
        $so = khtd_exam_sohieu_norm((string) ($row['so_hieu'] ?? ''));
        if ($so !== '' && trim((string) ($row['ten'] ?? '')) !== '') $m[$so] = (string) $row['ten'];
    }
    return $m;
}

/** Chấm tự động theo rubric (không cần Gemini). */
function khtd_exam_local(array $ws, array $answers): array
{
    $schema = $ws['schema'];
    $sec = khtd_exam_section($schema);
    if (!$sec) return ['samples' => [], 'sum' => 0.0, 'count' => 0, 'score10' => 0.0];
    $colmap = khtd_exam_colmap($sec);
    $n = khtd_exam_count($answers, $sec);
    $keymap = khtd_exam_keymap($schema);
    $out = []; $sum = 0.0;
    for ($ri = 0; $ri < $n; $ri++) {
        $rec = khtd_exam_record($colmap, (array) ($answers[$sec['id']][$ri] ?? []));
        $opt = ['need_so_hieu' => true];
        if ($keymap) {
            $so = khtd_exam_sohieu_norm((string) ($rec['so_hieu'] ?? ''));
            if ($so !== '' && isset($keymap[$so])) $opt['expected'] = $keymap[$so];
            elseif ($so !== '') $opt['expected_missing'] = true;
        }
        $r = khtd_exam_score_sample($rec, $opt);
        $r['stt'] = $ri + 1;
        $out[] = $r; $sum += $r['level'];
    }
    return ['samples' => $out, 'sum' => $sum, 'count' => $n, 'score10' => $n ? round($sum / $n * 10 * 4) / 4 : 0.0];
}

/** Ảnh mẫu (tệp trên đĩa) gửi kèm cho Gemini: nén về cạnh dài 768 px. Trả [[stt, mime, base64], …]. */
function khtd_exam_photos(array $ws, array $answers, int $n): array
{
    $sec = khtd_exam_section($ws['schema']);
    $refs = (array) ($answers['_photos'][$sec['id'] ?? ''] ?? []);
    if (!$refs) return [];
    $ids = [];
    for ($ri = 0; $ri < $n; $ri++) foreach (array_slice((array) ($refs[$ri] ?? $refs[(string) $ri] ?? []), 0, 2) as $pid) $ids[(string) $pid] = $ri + 1;
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT PhotoID, FileName FROM khtd_photos WHERE WorksheetID = ? AND PhotoID IN ($in)");
    $st->execute(array_merge([$ws['WorksheetID']], array_keys($ids)));
    $files = []; foreach ($st->fetchAll() as $p) $files[$p['PhotoID']] = $p['FileName'];
    $out = []; $budget = 6 * 1024 * 1024;
    foreach ($ids as $pid => $stt) {
        if (!isset($files[$pid])) continue;
        $path = khtd_upload_dir() . '/' . $ws['WorksheetID'] . '/' . $files[$pid];
        $bin = @file_get_contents($path);
        if ($bin === false || $bin === '') continue;
        $mime = 'image/jpeg';
        if (function_exists('imagecreatefromstring') && ($src = @imagecreatefromstring($bin)) !== false) {
            $w = imagesx($src); $h = imagesy($src); $sc = min(1, 768 / max($w, $h, 1));
            $dst = imagecreatetruecolor(max(1, (int) round($w * $sc)), max(1, (int) round($h * $sc)));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
            ob_start(); imagejpeg($dst, null, 75); $bin = (string) ob_get_clean();
            imagedestroy($src); imagedestroy($dst);
        } elseif (str_ends_with((string) $files[$pid], '.png')) { $mime = 'image/png'; }
        elseif (str_ends_with((string) $files[$pid], '.webp')) { $mime = 'image/webp'; }
        if (strlen($bin) > $budget) break;
        $budget -= strlen($bin);
        $out[] = [$stt, $mime, base64_encode($bin)];
        if (count($out) >= 20) break;
    }
    return $out;
}

/**
 * Chấm lần luyện: rubric tự động + Gemini (xem ảnh, nhận ra cách viết khác nghĩa tương đương).
 * Luôn trả kết quả (không có Gemini / Gemini lỗi → điểm rubric tự động). Cấu trúc tương thích khtd_gemini_grade.
 */
function khtd_exam_grade(array $ws, array $answers, array $student): array
{
    $local = khtd_exam_local($ws, $answers);
    $n = $local['count'];
    $lv = []; $notes = []; $fix = []; $photoTag = [];
    foreach ($local['samples'] as $s) { $lv[$s['stt']] = $s['level']; }
    $feedback = ''; $flags = []; $risk = null; $model = 'rubric-tu-dong'; $sysNote = null; $critique = [];

    $g = khtd_gemini_config();
    $hasContent = array_filter($local['samples'], static fn ($s) => !$s['trong']);
    if ($g['key'] !== '' && $hasContent) {
        try {
            $cand = khtd_gemini_candidates($g);
            if (!$cand['models']) throw new RuntimeException('Không lấy được danh sách model Gemini.');
            $sec = khtd_exam_section($ws['schema']);
            $colmap = khtd_exam_colmap($sec);
            $mau = [];
            foreach ($local['samples'] as $s) {
                $rec = khtd_exam_record($colmap, (array) ($answers[$sec['id']][$s['stt'] - 1] ?? []));
                $mau[] = ['stt' => $s['stt'], 'bai_lam' => $rec, 'cham_tu_dong' => ['diem' => $s['level'], 'nhan_ra_ten' => $s['ten_chuan'] ?: null, 'con_thieu' => $s['thieu'], 'ghi_chu' => $s['ly_do']],
                          'du_lieu_chuan' => $s['chuan'] ?: null];
            }
            $photos = khtd_exam_photos($ws, $answers, $n);
            $system = "Bạn là trợ giảng thực tập Khoa học Trái đất (Khoa Địa chất, ĐH KHTN – ĐHQG-HCM), chấm bài LUYỆN THI nhận diện mẫu khoáng vật và đá của sinh viên ngành Kinh tế đất đai.\n"
                . "Rubric mỗi mẫu tối đa 1 điểm, chỉ có các mức 1 / 0,75 / 0,5 / 0,25 / 0:\n"
                . "• ĐÁ: 1 = đúng tên + số hiệu + nhóm đá + kiến trúc + thành phần khoáng vật chính + đặc điểm nhận diện; 0,75 = đúng tên + số hiệu + nhóm đá + kiến trúc + thành phần khoáng vật chính; 0,5 = đúng tên + số hiệu + nhóm đá + kiến trúc; 0,25 = đúng tên + số hiệu; 0 = sai tên hoặc không ghi số hiệu.\n"
                . "• KHOÁNG VẬT: 1 = đúng số hiệu + tên + thành phần hóa học + độ cứng + cát khai + (vết vạch / màu / tính chất khác); 0,75 = số hiệu + tên + thành phần hóa học + độ cứng + cát khai; 0,5 = số hiệu + tên + thành phần hóa học + (độ cứng hoặc cát khai); 0,25 = số hiệu + tên + (thành phần hóa học hoặc độ cứng hoặc cát khai); 0 = không ghi số hiệu hoặc sai tên.\n"
                . "QUY TẮC:\n1. 'cham_tu_dong' là kết quả đối chiếu từ khóa với 'du_lieu_chuan' (giáo trình thực tập của Bộ môn). Giữ nguyên mức đó, TRỪ KHI: (a) sinh viên viết đúng ý nhưng bằng cách diễn đạt khác mà máy không nhận ra → được nâng tối đa 1 mức (0,25); (b) nội dung ghi sai kiến thức mà máy lỡ cho đạt → hạ mức; (c) ảnh mẫu cho thấy RÕ RÀNG không phải mẫu sinh viên gọi tên (vd gọi bazan nhưng ảnh là đá hạt thô màu hồng) → 0 điểm và ghi 'anh':'khong_khop'. Ảnh mờ, khó nhìn → 'khong_ro', không trừ điểm.\n"
                . "2. Nhận xét từng mẫu ngắn (≤ 2 câu), nêu cụ thể cần bổ sung/sửa gì để đạt 1 điểm (dẫn đúng tính chất chuẩn của mẫu đó).\n"
                . "3. 'feedback': 2–3 câu tổng quát giúp sinh viên đạt điểm tối đa bài thi thật (10 mẫu / 30 phút).\n"
                . "4. Ước lượng khả năng bài được viết bằng chatbot vào 'nguy_co_ai' (0–100). TUYỆT ĐỐI không nhắc tới AI, chatbot, trí tuệ nhân tạo trong 'nhan_xet', 'feedback', 'flags' (được in vào phiếu điểm làm minh chứng).\n"
                . "5. 'phan_bien' (chỉ giảng viên xem): nếu 'du_lieu_chuan' có chỗ sai/thiếu so với kiến thức khoáng vật – thạch học phổ thông, hoặc rubric khó áp dụng cho mẫu nào (vd tên gọi tiếng Việt mơ hồ như 'phiến sét'), ghi ngắn từng ý; không có thì mảng rỗng.\n"
                . "Chỉ trả JSON: {\"mau\":[{\"stt\":1,\"diem\":0.75,\"anh\":\"khop|khong_khop|khong_ro|khong_co\",\"nhan_xet\":\"...\"}],\"feedback\":\"...\",\"flags\":[\"...\"],\"phan_bien\":[\"...\"],\"nguy_co_ai\":{\"muc\":0,\"dau_hieu\":[\"...\"]}}";
            $parts = [['text' => json_encode(['so_mau' => $n, 'mau' => $mau, 'so_anh_gui_kem' => count($photos)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]];
            foreach ($photos as [$stt, $mime, $b64]) { $parts[] = ['text' => 'Ảnh của mẫu số ' . $stt . ':']; $parts[] = ['inlineData' => ['mimeType' => $mime, 'data' => $b64]]; }
            $body = ['systemInstruction' => ['parts' => [['text' => $system]]], 'contents' => [['role' => 'user', 'parts' => $parts]],
                     'generationConfig' => ['temperature' => 0.1, 'responseMimeType' => 'application/json', 'maxOutputTokens' => 6144]];
            [$text, $used] = khtd_gemini_generate($cand['models'], $g['key'], $body);
            $o = json_decode($text, true);
            if (!is_array($o) || !is_array($o['mau'] ?? null)) throw new RuntimeException('Gemini trả về không đúng JSON.');
            $model = $used;
            foreach ($o['mau'] as $m) {
                $stt = (int) ($m['stt'] ?? 0); if (!isset($lv[$stt])) continue;
                $base = $lv[$stt];
                $s = $local['samples'][$stt - 1];
                $gv = round(max(0, min(1, (float) ($m['diem'] ?? $base))) * 4) / 4;
                $ph = (string) ($m['anh'] ?? '');
                if ($ph === 'khong_khop' && $s['key'] !== null) $gv = 0.0;
                elseif ($s['key'] === null || $s['trong']) $gv = $base;              // không nhận ra tên / bỏ trống: giữ 0
                elseif (str_starts_with($s['ly_do'], 'Sai tên') || str_starts_with($s['ly_do'], 'Không ghi số hiệu') || str_starts_with($s['ly_do'], 'Số hiệu')) $gv = $base;
                else $gv = min($gv, $base + 0.25);                                   // nâng tối đa 1 mức
                $lv[$stt] = $gv;
                $photoTag[$stt] = $ph;
                $notes[$stt] = mb_substr((string) ($m['nhan_xet'] ?? ''), 0, 400);
            }
            $feedback = mb_substr((string) ($o['feedback'] ?? ''), 0, 1500);
            $flags = array_slice(array_values(array_filter((array) ($o['flags'] ?? []), 'is_scalar')), 0, 5);
            $critique = khtd_clean_list($o['phan_bien'] ?? [], 6);
            if (is_array($o['nguy_co_ai'] ?? null)) {
                $risk = ['muc' => max(0, min(100, (int) ($o['nguy_co_ai']['muc'] ?? 0))),
                         'dau_hieu' => array_map(static fn ($x) => mb_substr((string) $x, 0, 200), array_slice(array_filter((array) ($o['nguy_co_ai']['dau_hieu'] ?? []), 'is_scalar'), 0, 4))];
            }
        } catch (Throwable $e) {
            $sysNote = 'Chỉ chấm rubric tự động (' . mb_substr($e->getMessage(), 0, 200) . ').';
            error_log('khtd exam gemini: ' . $e->getMessage());
        }
    }

    $crit = []; $dc = []; $samples = []; $sum = 0.0; $fixes = [];
    foreach ($local['samples'] as $s) {
        $stt = $s['stt']; $l = $lv[$stt];
        $sum += $l;
        $cm = $notes[$stt] ?? '';
        if ($cm === '') {
            $cm = $s['trong'] ? 'Bỏ trống.' : ($l >= 1 ? 'Đạt tối đa.' . ($s['ly_do'] ? ' ' . $s['ly_do'] : '') : trim(($s['ly_do'] ? $s['ly_do'] . ' ' : '') . ($l < 1 && $s['key'] && $s['thieu'] ? 'Cần bổ sung/sửa: ' . implode(', ', $s['thieu']) . '.' : '')));
        }
        if (($photoTag[$stt] ?? '') === 'khong_khop') $cm = 'Ảnh mẫu không khớp với tên đã ghi. ' . $cm;
        $label = 'Mẫu ' . $stt . ($s['so_hieu'] !== '' ? ' (' . mb_substr($s['so_hieu'], 0, 20) . ')' : '');
        $crit[] = ['id' => 'm' . $stt, 'name' => $label, 'max' => 1.0, 'score' => $l, 'comment' => mb_substr($cm, 0, 600)];
        $dc[] = ['muc' => $label, 'bai_lam' => mb_substr($s['ten_sv'] !== '' ? $s['ten_sv'] : '(trống)', 0, 200),
                 'dap_an' => mb_substr($s['chuan'] ?: 'Không có trong danh mục mẫu', 0, 400), 'ket_qua' => khtd_exam_verdict($l, $s['trong'])];
        if ($l < 1) {
            if ($s['trong']) $fixes[] = ['muc' => $label, 'van_de' => 'Bỏ trống.', 'cach_sua' => 'Ghi đủ số hiệu, tên và các tính chất theo bảng nhận diện.'];
            elseif ($s['key'] === null) $fixes[] = ['muc' => $label, 'van_de' => $s['ly_do'], 'cach_sua' => 'Kiểm tra lại tên (đúng chính tả, đúng danh mục mẫu của học phần) và đối chiếu các dấu hiệu quyết định.'];
            elseif (($photoTag[$stt] ?? '') === 'khong_khop') $fixes[] = ['muc' => $label, 'van_de' => 'Ảnh mẫu không khớp với tên "' . $s['ten_sv'] . '".', 'cach_sua' => 'Quan sát lại mẫu: kiến trúc, màu, độ cứng, phản ứng HCl… rồi gọi tên lại.'];
            elseif ($s['level'] === 0.0 && $s['fix'] === []) $fixes[] = ['muc' => $label, 'van_de' => $s['ly_do'], 'cach_sua' => 'Ghi số hiệu mẫu (nhãn trên mẫu) và tên đúng.'];
            foreach ($s['fix'] as $f) {
                $fixes[] = ['muc' => $label . ' – ' . $f['truong'], 'van_de' => $f['bai_lam'] === '' ? 'Bỏ trống.' : 'Ghi "' . mb_substr($f['bai_lam'], 0, 120) . '" chưa đúng / chưa đủ.',
                            'cach_sua' => 'Chuẩn (' . $s['ten_chuan'] . '): ' . $f['chuan']];
            }
        }
        $samples[] = ['stt' => $stt, 'kind' => $s['kind'], 'key' => $s['key'], 'ten' => $s['ten_chuan'], 'level' => $l, 'auto' => $s['level'],
                      'thieu' => $s['thieu'], 'anh' => $photoTag[$stt] ?? null, 'trong' => $s['trong']];
    }
    $total = $n ? round($sum / $n * 10 * 4) / 4 : 0.0;
    if ($feedback === '') {
        $weak = [];
        foreach ($samples as $s) foreach ($s['thieu'] as $t) $weak[$t] = ($weak[$t] ?? 0) + 1;
        arsort($weak);
        $feedback = 'Dự báo điểm bài thi nhận diện mẫu: ' . str_replace('.', ',', (string) $total) . '/10 (' . $n . ' mẫu).'
            . ($weak ? ' Hay thiếu nhất: ' . implode(', ', array_slice(array_keys($weak), 0, 3)) . '.' : ' Rất tốt — giữ phong độ ở bài thi 10 mẫu / 30 phút.');
    }
    $time = $answers['_exam'] ?? null;
    if (is_array($time) && ($time['overtimeSec'] ?? 0) > 0) {
        $ov = (int) $time['overtimeSec'];
        $flags[] = 'Nộp quá giờ ' . intdiv($ov, 60) . ' phút ' . ($ov % 60) . ' giây — ở bài thi thật bài được thu đúng giờ.';
    }
    return ['model' => $model, 'doi_chieu' => $dc, 'criteria' => $crit, 'raw_total' => $sum, 'max' => (float) $n, 'total' => $total,
            'sua_bai' => array_slice($fixes, 0, 40), 'phan_bien' => $critique,
            'feedback' => $feedback, 'flags' => $flags, 'ai_risk' => $risk,
            'exam' => ['count' => $n, 'samples' => $samples, 'note' => $sysNote, 'time' => $time]];
}

/* ---------------- Dự báo điểm thi từ bảng mô tả của phiếu 1–6 ---------------- */

/**
 * Chấm thử các bảng mô tả mẫu (có cột "Tên …") theo rubric bài thi. Hàng là "Mẫu i" coi như đã có số hiệu.
 * Trả null nếu phiếu không có bảng phù hợp hoặc SV chưa gọi tên mẫu nào.
 */
function khtd_exam_forecast(array $schema, array $answers): ?array
{
    if (khtd_is_exam($schema)) return null;
    $items = [];
    foreach ($schema['sections'] ?? [] as $s) {
        if (($s['type'] ?? '') !== 'table') continue;
        $colmap = khtd_exam_colmap($s);
        if (!in_array('ten', $colmap, true)) continue;
        $kindHint = in_array('do_cung', $colmap, true) && !in_array('nhom', $colmap, true) ? 'kv'
                  : (in_array('kien_truc', $colmap, true) || in_array('nhom', $colmap, true) ? 'da' : null);
        if (in_array('loai', $colmap, true)) $kindHint = null;
        foreach ((array) ($s['rows'] ?? []) as $ri => $label) {
            $rec = khtd_exam_record($colmap, (array) ($answers[$s['id']][$ri] ?? []));
            if (trim((string) ($rec['ten'] ?? '')) === '') continue;
            $rec['so_hieu'] = $rec['so_hieu'] ?? (string) $label;
            $r = khtd_exam_score_sample($rec, ['need_so_hieu' => true, 'kind' => $kindHint]);
            $items[] = ['mau' => (string) $label, 'bang' => (string) ($s['title'] ?? ''), 'ten' => $r['ten_chuan'] ?: $r['ten_sv'],
                        'level' => $r['level'], 'thieu' => $r['key'] ? $r['thieu'] : ['tên đúng theo danh mục mẫu']];
        }
    }
    if (!$items) return null;
    $sum = array_sum(array_column($items, 'level'));
    return ['score10' => round($sum / count($items) * 10 * 4) / 4, 'samples' => count($items),
            'perfect' => count(array_filter($items, static fn ($x) => $x['level'] >= 1)), 'items' => $items];
}

/* ---------------- Tổng hợp luyện tập: điểm cộng & thái độ ---------------- */

/**
 * Tổng hợp các lần luyện (đã nộp) của MỘT SV ở phiếu luyện thi.
 * $rows: các dòng khtd_submissions (AttemptNo, Status, SubmittedAt, AiScore, FinalScore, AiJSON).
 */
function khtd_practice_summary(array $schema, array $rows): array
{
    $cfg = khtd_exam_cfg($schema)['bonus'];
    $done = array_values(array_filter($rows, static fn ($r) => ($r['Status'] ?? '') !== 'DRAFT'));
    usort($done, static fn ($a, $b) => (int) $a['AttemptNo'] <=> (int) $b['AttemptNo']);
    $best = []; $days = []; $samples = 0; $scores = []; $secs = 0;
    foreach ($done as $r) {
        $sc = $r['FinalScore'] !== null ? (float) $r['FinalScore'] : ($r['AiScore'] !== null ? (float) $r['AiScore'] : null);
        if ($sc !== null) $scores[] = $sc;
        if (!empty($r['SubmittedAt'])) $days[substr((string) $r['SubmittedAt'], 0, 10)] = true;
        $ai = is_array($r['AiJSON'] ?? null) ? $r['AiJSON'] : (json_decode((string) ($r['AiJSON'] ?? ''), true) ?: []);
        foreach ((array) ($ai['exam']['samples'] ?? []) as $s) {
            if (!empty($s['trong'])) continue;
            $samples++;
            if (!empty($s['key'])) { $k = $s['kind'] . ':' . $s['key']; $best[$k] = max($best[$k] ?? 0, (float) $s['level']); }
        }
        $secs += (int) ($ai['exam']['time']['elapsedSec'] ?? 0);
    }
    $mastered = count(array_filter($best, static fn ($v) => $v >= (float) $cfg['min_level']));
    $bonus = min((float) $cfg['cap'], floor($mastered / max(1, (int) $cfg['step'])) * (float) $cfg['add']);
    $n = count($done);
    $level = $n === 0 ? 'Chưa luyện' : (($n >= 3 && count($days) >= 2 && $samples >= 10) ? 'Tích cực' : (($samples >= 3) ? 'Có luyện' : 'Ít luyện'));
    return ['attempts' => $n, 'days' => count($days), 'samples' => $samples, 'types' => count($best), 'mastered' => $mastered,
            'first' => $scores ? $scores[0] : null, 'last' => $scores ? end($scores) : null, 'best' => $scores ? max($scores) : null,
            'minutes' => (int) round($secs / 60), 'bonus' => $bonus, 'attitude' => $level,
            'rule' => 'Mỗi ' . (int) $cfg['step'] . ' loại khoáng vật/đá đạt ≥ ' . str_replace('.', ',', (string) $cfg['min_level']) . ' điểm → +' . str_replace('.', ',', (string) $cfg['add']) . ' (tối đa +' . str_replace('.', ',', (string) $cfg['cap']) . ')'];
}

/* ---------------- Bấm giờ ---------------- */

/** Trạng thái giờ làm của lần luyện (giờ máy chủ). null nếu chưa bắt đầu. */
function khtd_exam_clock(array $schema, array $answers): ?array
{
    $e = $answers['_exam'] ?? null;
    if (!is_array($e) || empty($e['startedAt'])) return null;
    $cfg = khtd_exam_cfg($schema);
    $limit = (int) ($e['limitSec'] ?? ((int) ($e['count'] ?? 1) * $cfg['secPerSample']));
    $st = db()->prepare('SELECT TIMESTAMPDIFF(SECOND, :s, NOW())');
    $st->execute(['s' => $e['startedAt']]);
    $elapsed = (int) $st->fetchColumn();
    return ['startedAt' => $e['startedAt'], 'count' => (int) ($e['count'] ?? 1), 'limitSec' => $limit, 'elapsedSec' => $elapsed,
            'remainingSec' => $limit - $elapsed, 'graceSec' => $cfg['graceSec'], 'over' => $elapsed > $limit + $cfg['graceSec']];
}

/** Giữ _exam của máy chủ (bỏ _exam trình duyệt gửi lên). */
function khtd_exam_keep_state(array $answers, ?string $prevJson): array
{
    unset($answers['_exam']);
    $prev = json_decode((string) $prevJson, true);
    if (is_array($prev['_exam'] ?? null)) $answers['_exam'] = $prev['_exam'];
    return $answers;
}

function khtd_latest_answers_json(?array $latest): ?string
{
    if (!$latest) return null;
    $st = db()->prepare('SELECT AnswersJSON FROM khtd_submissions WHERE SubmissionID = :id');
    $st->execute(['id' => $latest['SubmissionID']]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

/**
 * POST khtdStartExam {token, worksheetId, count} — bắt đầu tính giờ lần luyện với `count` mẫu (1–10).
 * Gọi lại khi đã bắt đầu → trả trạng thái giờ hiện tại (không đặt lại đồng hồ).
 */
function action_khtd_start_exam(array $params): void
{
    $me = khtd_require_student((string) ($params['token'] ?? ''));
    $ws = khtd_worksheet((string) ($params['worksheetId'] ?? ''));
    khtd_assert_enrolled($me['studentId'], (string) $ws['ClassID']);
    if (!khtd_is_exam($ws['schema'])) { api_fail('Phiếu này không bấm giờ.'); return; }
    if (!khtd_review_guard($me, $ws)) return;   // chế độ ôn tập ở nhà (mục 40)
    if (!khtd_is_open($ws)) { api_fail('Phiếu đã đóng.'); return; }
    $cfg = khtd_exam_cfg($ws['schema']);
    $count = (int) ($params['count'] ?? 0);
    if ($count < 1 || $count > $cfg['maxSamples']) { api_fail('Chọn số mẫu từ 1 đến ' . $cfg['maxSamples'] . '.'); return; }
    $sum = khtd_attempt_summary($ws, khtd_student_attempts($ws['WorksheetID'], $me['studentId']), khtd_extra_attempts($ws['WorksheetID'], $me['studentId']));
    $latest = $sum['latest'];
    if ($latest && $latest['Status'] !== 'DRAFT') {
        api_fail($sum['canRetake'] ? 'Lần trước đã nộp — bấm "Luyện lần mới" để bắt đầu lần tiếp theo.' : khtd_already_submitted_msg($sum)); return;
    }
    $answers = json_decode((string) khtd_latest_answers_json($latest), true) ?: [];
    if (!empty($answers['_exam']['startedAt'])) { api_ok(['clock' => khtd_exam_clock($ws['schema'], $answers), 'already' => true]); return; }
    $now = db_now();
    $answers['_exam'] = ['startedAt' => $now, 'count' => $count, 'limitSec' => $count * $cfg['secPerSample']];
    $json = json_encode($answers, JSON_UNESCAPED_UNICODE);
    if ($latest) {
        db()->prepare("UPDATE khtd_submissions SET AnswersJSON = :a WHERE SubmissionID = :id AND Status = 'DRAFT'")->execute(['a' => $json, 'id' => $latest['SubmissionID']]);
    } else {
        db()->prepare("INSERT INTO khtd_submissions (SubmissionID, WorksheetID, StudentID, AttemptNo, AnswersJSON, Status) VALUES (:id, :w, :s, 1, :a, 'DRAFT')")
            ->execute(['id' => new_id('SUB'), 'w' => $ws['WorksheetID'], 's' => $me['studentId'], 'a' => $json]);
    }
    log_audit($me['studentId'], 'STUDENT', 'khtdStartExam', 'worksheet', $ws['WorksheetID'], ['count' => $count]);
    api_ok(['clock' => khtd_exam_clock($ws['schema'], $answers), 'already' => false]);
}

/** Đọc "đáp án bộ mẫu" GV dán: mỗi dòng "số hiệu = tên" (hoặc ":" / tab). */
function khtd_exam_parse_key(string $text): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!preg_match('/^(.{1,30}?)\s*(?:=|:|\t|–|—|\s-\s)\s*(.{2,80})$/u', $line, $m)) throw new RuntimeException('Dòng không đúng dạng "số hiệu = tên": ' . mb_substr($line, 0, 60));
        $out[] = ['so_hieu' => trim($m[1]), 'ten' => trim($m[2])];
        if (count($out) > 200) throw new RuntimeException('Tối đa 200 mẫu.');
    }
    return $out;
}

/** POST khtdSetExamKey {token, worksheetId, text} — GV lưu đáp án bộ mẫu (để trống = xoá). */
function action_khtd_set_exam_key(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $ws = khtd_worksheet((string) ($params['worksheetId'] ?? ''));
    assert_class_access($me, (string) $ws['ClassID']);
    if (!khtd_is_exam($ws['schema'])) { api_fail('Chỉ phiếu luyện thi mới có đáp án bộ mẫu.'); return; }
    try { $key = khtd_exam_parse_key((string) ($params['text'] ?? '')); } catch (RuntimeException $e) { api_fail($e->getMessage()); return; }
    $unknown = [];
    foreach ($key as $k) if (!khtd_kb_find($k['ten'])) $unknown[] = $k['so_hieu'] . ' = ' . $k['ten'];
    $raw = json_decode((string) $ws['SchemaJSON'], true) ?: [];
    $raw['exam']['key'] = $key;
    db()->prepare('UPDATE khtd_worksheets SET SchemaJSON = :s WHERE WorksheetID = :id')->execute(['s' => json_encode($raw, JSON_UNESCAPED_UNICODE), 'id' => $ws['WorksheetID']]);
    log_audit((string) ($me['userId'] ?? ($me['UserID'] ?? '')), 'LECTURER', 'khtdSetExamKey', 'worksheet', $ws['WorksheetID'], ['n' => count($key)]);
    api_ok(['count' => count($key), 'unknown' => $unknown]);
}

/** Đáp án bộ mẫu dạng chữ (hiển thị lại cho GV). */
function khtd_exam_key_text(array $schema): string
{
    return implode("\n", array_map(static fn ($k) => $k['so_hieu'] . ' = ' . $k['ten'], khtd_exam_cfg($schema)['key']));
}

/** GET khtdPracticeBoard {token, worksheetId} — bảng luyện tập cả lớp: thái độ + điểm cộng đề xuất (GV). */
function action_khtd_practice_board(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $ws = khtd_worksheet((string) ($params['worksheetId'] ?? ''));
    assert_class_access($me, (string) $ws['ClassID']);
    if (!khtd_is_exam($ws['schema'])) { api_fail('Phiếu này không phải phiếu luyện thi.'); return; }
    api_ok(khtd_practice_board($ws));
}

function khtd_practice_board(array $ws): array
{
    $st = db()->prepare("SELECT s.StudentID, s.MSSV, s.FullName FROM enrollments e JOIN students s ON s.StudentID = e.StudentID WHERE e.ClassID = :c AND e.Status = 'ACTIVE' ORDER BY s.FullName");
    $st->execute(['c' => $ws['ClassID']]);
    $subs = db()->prepare('SELECT StudentID, AttemptNo, Status, SubmittedAt, AiScore, FinalScore, AiJSON FROM khtd_submissions WHERE WorksheetID = :w');
    $subs->execute(['w' => $ws['WorksheetID']]);
    $by = [];
    foreach ($subs->fetchAll() as $r) $by[$r['StudentID']][] = $r;
    $rows = []; $tally = ['Tích cực' => 0, 'Có luyện' => 0, 'Ít luyện' => 0, 'Chưa luyện' => 0];
    foreach ($st->fetchAll() as $s) {
        $p = khtd_practice_summary($ws['schema'], $by[$s['StudentID']] ?? []);
        $tally[$p['attitude']]++;
        $rows[] = ['mssv' => $s['MSSV'], 'name' => $s['FullName']] + $p;
    }
    $rule = $rows ? $rows[0]['rule'] : khtd_practice_summary($ws['schema'], [])['rule'];
    return ['worksheetId' => $ws['WorksheetID'], 'no' => (int) $ws['No'], 'title' => $ws['Title'], 'rows' => $rows, 'tally' => $tally, 'rule' => $rule];
}
