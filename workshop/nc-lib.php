<?php
declare(strict_types=1);

/**
 * workshop/nc-lib.php — phần nghiên cứu của API Workshop K2026 (nạp từ api.php).
 *
 * 4 đợt khảo sát: nc0 (đầu buổi), nc1 (cuối buổi), nc2 (sau 2 tuần), nc3 (sau 6 tuần).
 * Mọi phiếu nc* phải có đồng ý tham gia (ok=1) và xác nhận đủ 18 tuổi (adult=1).
 * Phạm vi giá trị khớp workshop/ws-research.js.
 *
 * Bảo mật: nội dung phiếu không bao giờ trả qua endpoint công khai (chỉ đếm số phiếu);
 * dữ liệu chi tiết chỉ xuất qua admin op "research_csv" (cần PIN).
 */

if (!function_exists('api_fail')) {
    http_response_code(404);
    exit;
}

const WS_NC_ACTS = ['nc0', 'nc1', 'nc2', 'nc3'];
const WS_NC_DEMO = ['gioi' => 3, 'nganh' => 9, 'noio' => 4, 'lamthem' => 3, 'tinchi' => 4, 'coso' => 2, 'dahoc' => 1];
const WS_NC_TMQ_SUB = ['SRP', 'SRP', 'SRP', 'SRP', 'SRP', 'SRP', 'SRP', 'TA', 'TA', 'TA', 'TA', 'TA', 'TA', 'LRP', 'LRP', 'LRP', 'LRP', 'LRP'];
const WS_NC_KT_KEY = [2, 1, 2, 0, 1, 1, 2, 2];
const WS_NC_N = ['tmq' => 18, 'se' => 5, 'kt' => 8, 're' => 4, 'yd' => 3, 'ad' => 7];

function ws_nc_arr($v, int $n, int $min, int $max, string $label): array
{
    if (!is_array($v) || count($v) !== $n) {
        api_fail('Hãy trả lời đủ phần ' . $label . '.');
    }
    $out = [];
    foreach (array_values($v) as $x) {
        $i = ws_int($x, $min, $max);
        if ($i === null) {
            api_fail('Hãy trả lời đủ phần ' . $label . '.');
        }
        $out[] = $i;
    }
    return $out;
}

function ws_nc_sgic($v): string
{
    $s = strtoupper(trim((string) $v));
    if (!preg_match('/^[A-Z]{2}(0[1-9]|[12]\d|3[01])\d{2}$/', $s)) {
        api_fail('Mã ẩn danh chưa đúng dạng (ví dụ LA0739).');
    }
    return $s;
}

function ws_nc_clean(string $act, $d): array
{
    if (!is_array($d)) {
        api_fail('Phiếu không hợp lệ.');
    }
    if ((int) ($d['ok'] ?? 0) !== 1 || (int) ($d['adult'] ?? 0) !== 1) {
        api_fail('Phiếu nghiên cứu chỉ nhận khi bạn đã đồng ý tham gia và đủ 18 tuổi.');
    }
    $out = ['ok' => 1, 'adult' => 1, 'sg' => ws_nc_sgic($d['sg'] ?? '')];

    if ($act === 'nc0') {
        $dem = $d['dem'] ?? null;
        if (!is_array($dem)) {
            api_fail('Hãy trả lời phần thông tin chung.');
        }
        foreach (WS_NC_DEMO as $k => $max) {
            $i = ws_int($dem[$k] ?? null, 0, $max);
            if ($i === null) {
                api_fail('Hãy trả lời đủ phần thông tin chung.');
            }
            $out['dem'][$k] = $i;
        }
        $out['tmq'] = ws_nc_arr($d['tmq'] ?? null, 18, 1, 5, 'thói quen sử dụng thời gian');
        $out['se'] = ws_nc_arr($d['se'] ?? null, 5, 1, 5, 'mức độ tự tin');
        $out['kt'] = ws_nc_arr($d['kt'] ?? null, 8, 0, 3, 'câu hỏi kiến thức');
    } elseif ($act === 'nc1') {
        $out['kt'] = ws_nc_arr($d['kt'] ?? null, 8, 0, 3, 'câu hỏi kiến thức');
        $out['se'] = ws_nc_arr($d['se'] ?? null, 5, 1, 5, 'mức độ tự tin');
        $out['re'] = ws_nc_arr($d['re'] ?? null, 4, 1, 5, 'nhận xét về workshop');
        $out['yd'] = ws_nc_arr($d['yd'] ?? null, 3, 1, 5, 'dự định áp dụng');
    } else { // nc2, nc3
        $td = ws_int($d['thamdu'] ?? null, 0, 1);
        if ($td === null) {
            api_fail('Hãy cho biết bạn có tham dự workshop hay không.');
        }
        $out['thamdu'] = $td;
        $out['ad'] = ws_nc_arr($d['ad'] ?? null, 7, 0, 3, 'mức độ vận dụng');
        $tr = [];
        foreach ((array) ($d['tr'] ?? []) as $x) {
            $i = ws_int($x, 0, 7);
            if ($i !== null && !in_array($i, $tr, true)) {
                $tr[] = $i;
            }
        }
        sort($tr);
        $out['tr'] = $tr;
        $out['txt'] = ws_text((string) ($d['txt'] ?? ''), 400);
        if ($act === 'nc2') {
            $ck = ws_int($d['ck'] ?? null, 0, 8);
            if ($ck === null) {
                api_fail('Hãy cho biết số ngày bạn thực hiện cam kết.');
            }
            $out['ck'] = $ck;
        } else {
            $out['tmq'] = ws_nc_arr($d['tmq'] ?? null, 18, 1, 5, 'thói quen sử dụng thời gian');
            $out['se'] = ws_nc_arr($d['se'] ?? null, 5, 1, 5, 'mức độ tự tin');
            $tdi = ws_int($d['thaydoi'] ?? null, 1, 5);
            if ($tdi === null) {
                api_fail('Hãy đánh giá mức thay đổi của bạn.');
            }
            $out['thaydoi'] = $tdi;
        }
    }
    return $out;
}

function ws_nc_score(array $kt): int
{
    $s = 0;
    foreach (WS_NC_KT_KEY as $i => $a) {
        if (isset($kt[$i]) && (int) $kt[$i] === $a) {
            $s++;
        }
    }
    return $s;
}

/**
 * Bảng dữ liệu nghiên cứu dạng rộng: mỗi dòng = một người tham gia (theo mã SGIC).
 * Nối T0–T1 ưu tiên cùng thiết bị (client), sau đó theo SGIC; T2–T3 nối theo SGIC.
 * Dữ liệu hoạt động workshop chỉ đưa vào khi thiết bị đó đã đồng ý (có nc0 hoặc nc1).
 * Điểm TMQ để NGUYÊN (1–5), chưa đảo chiều — đảo chiều theo khóa chấm của bài gốc khi phân tích.
 */
function ws_nc_csv(string $sess): array
{
    $rows = ws_run(function (PDO $pdo) use ($sess) {
        $st = $pdo->prepare('SELECT act, client, data, ts FROM ws_votes WHERE sess = ? ORDER BY ts');
        $st->execute([$sess]);
        return $st->fetchAll();
    });
    $byClient = [];   // client => act => [data, ts]
    foreach ($rows as $r) {
        $byClient[$r['client']][$r['act']] = [json_decode((string) $r['data'], true) ?: [], (int) $r['ts']];
    }

    $P = [];          // sgic => record
    $order = [];
    $touch = function (string $sg) use (&$P, &$order) {
        if (!isset($P[$sg])) {
            $P[$sg] = ['sg' => $sg, 'clients' => []];
            $order[] = $sg;
        }
    };
    // T0 & T1 + hoạt động: theo thiết bị đã đồng ý
    foreach ($byClient as $client => $acts) {
        $sg = $acts['nc0'][0]['sg'] ?? ($acts['nc1'][0]['sg'] ?? null);
        if (!$sg) {
            continue;
        }
        $touch($sg);
        $P[$sg]['clients'][] = $client;
        foreach (['nc0', 'nc1', 'khoidong', 'hinh', 'muctieu', 'eisen', 'banhxe', 'camket', 'danhgia'] as $a) {
            if (isset($acts[$a]) && (!isset($P[$sg][$a]) || $acts[$a][1] >= $P[$sg][$a][1])) {
                $P[$sg][$a] = $acts[$a];
            }
        }
    }
    // T2 & T3: theo mã (thiết bị có thể khác)
    foreach ($byClient as $client => $acts) {
        foreach (['nc2', 'nc3'] as $a) {
            if (!isset($acts[$a])) {
                continue;
            }
            $sg = $acts[$a][0]['sg'];
            $touch($sg);
            if (!isset($P[$sg][$a]) || $acts[$a][1] >= $P[$sg][$a][1]) {
                $P[$sg][$a] = $acts[$a];
            }
        }
    }

    $h = ['ma_an_danh', 'so_thiet_bi', 'co_t0', 'co_t1', 'co_t2', 'co_t3'];
    foreach (array_keys(WS_NC_DEMO) as $k) {
        $h[] = 't0_' . $k;
    }
    $h[] = 't0_thoi_gian';
    for ($i = 1; $i <= 18; $i++) {
        $h[] = sprintf('t0_tmq%02d_%s', $i, WS_NC_TMQ_SUB[$i - 1]);
    }
    for ($i = 1; $i <= 5; $i++) { $h[] = 't0_se' . $i; }
    for ($i = 1; $i <= 8; $i++) { $h[] = 't0_kt' . $i; }
    $h[] = 't0_kt_diem';
    $h[] = 't1_thoi_gian';
    for ($i = 1; $i <= 8; $i++) { $h[] = 't1_kt' . $i; }
    $h[] = 't1_kt_diem';
    for ($i = 1; $i <= 5; $i++) { $h[] = 't1_se' . $i; }
    for ($i = 1; $i <= 4; $i++) { $h[] = 't1_re' . $i; }
    for ($i = 1; $i <= 3; $i++) { $h[] = 't1_yd' . $i; }
    array_push($h, 'hd_cam', 'hd_dien_thoai', 'hd_hinh_1', 'hd_hinh_2');
    for ($i = 1; $i <= 8; $i++) { $h[] = 'hd_eisen_c' . $i; }
    for ($i = 1; $i <= 8; $i++) { $h[] = 'hd_banhxe' . $i; }
    array_push($h, 'hd_muctieu_S', 'hd_muctieu_M', 'hd_muctieu_A', 'hd_muctieu_R', 'hd_muctieu_T', 'hd_muctieu_noidung', 'hd_camket', 'hd_danhgia_sao', 'hd_danhgia_noidung');
    foreach (['t2', 't3'] as $w) {
        $h[] = $w . '_thoi_gian';
        $h[] = $w . '_thamdu';
        for ($i = 1; $i <= 7; $i++) { $h[] = $w . '_ad' . $i; }
        if ($w === 't2') {
            $h[] = 't2_camket_so_ngay';
        } else {
            for ($i = 1; $i <= 18; $i++) { $h[] = sprintf('t3_tmq%02d_%s', $i, WS_NC_TMQ_SUB[$i - 1]); }
            for ($i = 1; $i <= 5; $i++) { $h[] = 't3_se' . $i; }
            $h[] = 't3_thay_doi';
        }
        $h[] = $w . '_tro_ngai';
        $h[] = $w . '_y_kien';
    }

    $fmt = fn ($ts) => $ts ? date('Y-m-d H:i', $ts) : '';
    $out = [];
    foreach ($order as $sg) {
        $p = $P[$sg];
        $g = fn ($a) => $p[$a][0] ?? null;
        $t = fn ($a) => $p[$a][1] ?? 0;
        $r = [$sg, count($p['clients']), $g('nc0') ? 1 : 0, $g('nc1') ? 1 : 0, $g('nc2') ? 1 : 0, $g('nc3') ? 1 : 0];
        $n0 = $g('nc0');
        foreach (array_keys(WS_NC_DEMO) as $k) { $r[] = $n0['dem'][$k] ?? ''; }
        $r[] = $fmt($t('nc0'));
        for ($i = 0; $i < 18; $i++) { $r[] = $n0['tmq'][$i] ?? ''; }
        for ($i = 0; $i < 5; $i++) { $r[] = $n0['se'][$i] ?? ''; }
        for ($i = 0; $i < 8; $i++) { $r[] = $n0['kt'][$i] ?? ''; }
        $r[] = $n0 ? ws_nc_score($n0['kt'] ?? []) : '';
        $n1 = $g('nc1');
        $r[] = $fmt($t('nc1'));
        for ($i = 0; $i < 8; $i++) { $r[] = $n1['kt'][$i] ?? ''; }
        $r[] = $n1 ? ws_nc_score($n1['kt'] ?? []) : '';
        for ($i = 0; $i < 5; $i++) { $r[] = $n1['se'][$i] ?? ''; }
        for ($i = 0; $i < 4; $i++) { $r[] = $n1['re'][$i] ?? ''; }
        for ($i = 0; $i < 3; $i++) { $r[] = $n1['yd'][$i] ?? ''; }
        $kd = $g('khoidong'); $hi = $g('hinh'); $ei = $g('eisen'); $bx = $g('banhxe'); $mt = $g('muctieu'); $ck = $g('camket'); $dg = $g('danhgia');
        array_push($r, $kd['cam'] ?? '', $kd['man'] ?? '', $hi['top'] ?? '', $hi['second'] ?? '');
        for ($i = 1; $i <= 8; $i++) { $r[] = $ei['cards']['c' . $i] ?? ''; }
        for ($i = 0; $i < 8; $i++) { $r[] = $bx['v'][$i] ?? ''; }
        for ($i = 0; $i < 5; $i++) { $r[] = $mt['f'][$i] ?? ''; }
        array_push($r, $mt['text'] ?? '', $ck['pick'] ?? '', $dg['sao'] ?? '', $dg['text'] ?? '');
        foreach (['nc2', 'nc3'] as $a) {
            $x = $g($a);
            $r[] = $fmt($t($a));
            $r[] = $x['thamdu'] ?? '';
            for ($i = 0; $i < 7; $i++) { $r[] = $x['ad'][$i] ?? ''; }
            if ($a === 'nc2') {
                $r[] = $x['ck'] ?? '';
            } else {
                for ($i = 0; $i < 18; $i++) { $r[] = $x['tmq'][$i] ?? ''; }
                for ($i = 0; $i < 5; $i++) { $r[] = $x['se'][$i] ?? ''; }
                $r[] = $x['thaydoi'] ?? '';
            }
            $r[] = $x ? implode(';', $x['tr'] ?? []) : '';
            $r[] = $x['txt'] ?? '';
        }
        $out[] = $r;
    }

    $esc = function ($v): string {
        $v = (string) $v;
        return preg_match('/[",\n\r;]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
    };
    $csv = "\xEF\xBB\xBF" . implode(',', $h) . "\r\n";
    foreach ($out as $r) {
        $csv .= implode(',', array_map($esc, $r)) . "\r\n";
    }
    $counts = ['nguoi' => count($out)];
    foreach (WS_NC_ACTS as $a) {
        $counts[$a] = count(array_filter($P, fn ($p) => isset($p[$a])));
    }
    return ['csv' => $csv, 'counts' => $counts];
}
