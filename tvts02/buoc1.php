<?php
declare(strict_types=1);

/**
 * tvts02/buoc1.php — Lưu kết quả BƯỚC 1 (/TVTS01/index.htm, trắc nghiệm 5 phần) lên máy chủ
 * và nối sang BƯỚC 2 (/TVTS02.html, tự luận). Được api.php nạp; dùng chung CSDL, giới hạn
 * tần suất và quyền ADMIN.
 *
 * POST {a:'save1', consent:true, hp:'', info:{name,grade,school,dream}, r:[30×0–4], b:[20×1–5],
 *       v:[20×1–5], c:[12×1–5], sub:{TO:0–5,…}, need:'', sc:{…điểm trang tính sẵn…}, code?, key?}
 *   → {code, key}. Có code + key đúng thì CẬP NHẬT bài cũ (học sinh sửa câu trả lời), không tạo bài trùng.
 * GET  ?a=get1&code=XXXXXXXX → {code, info:{name,grade,school}, r} — để Bước 2 điền sẵn 30 câu sở thích.
 *
 * Câu trả lời gốc là dữ liệu chuẩn cho nghiên cứu; điểm RIASEC được máy chủ chấm lại,
 * các điểm còn lại (Big Five, giá trị, CAAS, ngành gợi ý) lấy theo trang để khớp đúng bản học sinh xem.
 */

const T1_N = ['r' => 30, 'b' => 20, 'v' => 20, 'c' => 12];
const T1_RANGE = ['r' => [0, 4], 'b' => [1, 5], 'v' => [1, 5], 'c' => [1, 5]];
const T1_SUBJECTS = ['TO', 'VA', 'AN', 'LI', 'HO', 'SI', 'SU', 'DI', 'KP', 'TI', 'CN', 'MT', 'AM', 'TC'];
const T1_SUBJECT_NAMES = ['TO' => 'Toán', 'VA' => 'Ngữ văn', 'AN' => 'Tiếng Anh', 'LI' => 'Vật lí', 'HO' => 'Hoá học', 'SI' => 'Sinh học',
    'SU' => 'Lịch sử', 'DI' => 'Địa lí', 'KP' => 'GD KT&PL', 'TI' => 'Tin học', 'CN' => 'Công nghệ', 'MT' => 'Mĩ thuật', 'AM' => 'Âm nhạc', 'TC' => 'Thể chất'];
const T1_B5 = ['O', 'C', 'E', 'A', 'S'];
const T1_GT = ['ACH', 'WC', 'REC', 'REL', 'SUP', 'IND'];
const T1_CAAS = ['CON', 'COL', 'CUR', 'COF', 'total'];

function t1_schema(): void
{
    $pdo = tvts_db();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('CREATE TABLE IF NOT EXISTS tvts01_results (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, ekey TEXT, ts INTEGER, upd INTEGER, iph TEXT, grade TEXT, school TEXT, info TEXT, answers TEXT, scores TEXT)');
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS tvts01_results (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code CHAR(8) NOT NULL,
        ekey CHAR(64) NOT NULL,
        ts INT NOT NULL,
        upd INT NOT NULL,
        iph CHAR(16) NOT NULL DEFAULT '',
        grade VARCHAR(16) NOT NULL DEFAULT '',
        school VARCHAR(120) NOT NULL DEFAULT '',
        info TEXT NOT NULL,
        answers TEXT NOT NULL,
        scores TEXT NOT NULL,
        UNIQUE KEY uq_tvts01_code (code), KEY k_tvts01_ts (ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
/** Như tvts_run nhưng tự tạo bảng tvts01_results khi chưa có. */
function t1_run(callable $fn)
{
    try {
        return tvts_run($fn);
    } catch (PDOException $e) {
        $m = $e->getMessage();
        if ($e->getCode() === '42S02' || stripos($m, 'no such table') !== false || stripos($m, "doesn't exist") !== false) {
            t1_schema();
            return tvts_run($fn);
        }
        throw $e;
    }
}
function t1_code_in($v): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $v) ?? ''); }
function t1_num($v, float $lo, float $hi): ?float
{
    if (!is_int($v) && !is_float($v)) return null;
    $f = (float) $v;
    return ($f >= $lo && $f <= $hi) ? round($f, 3) : null;
}

/** Kiểm và chuẩn hoá dữ liệu học sinh gửi lên. */
function t1_clean(array $in): array
{
    $ans = [];
    foreach (T1_N as $k => $n) {
        $arr = $in[$k] ?? null;
        if (!is_array($arr) || count($arr) !== $n) api_fail('Bài làm chưa đủ câu. Em kiểm tra lại các phần trắc nghiệm.');
        $arr = array_values($arr);
        [$lo, $hi] = T1_RANGE[$k];
        foreach ($arr as $i => $v) { if (!is_int($v) || $v < $lo || $v > $hi) api_fail('Câu trả lời không hợp lệ (phần ' . $k . ', câu ' . ($i + 1) . ').'); }
        $ans[$k] = $arr;
    }
    $subIn = is_array($in['sub'] ?? null) ? $in['sub'] : [];
    $ans['sub'] = [];
    foreach (T1_SUBJECTS as $s) { $v = $subIn[$s] ?? 0; $ans['sub'][$s] = (is_int($v) && $v >= 0 && $v <= 5) ? $v : 0; }
    $ans['need'] = tvts_str($in['need'] ?? '', 600);

    $infoIn = is_array($in['info'] ?? null) ? $in['info'] : [];
    $info = ['name' => tvts_str($infoIn['name'] ?? '', 60), 'grade' => tvts_str($infoIn['grade'] ?? '', 16),
             'school' => tvts_str($infoIn['school'] ?? '', 120), 'dream' => tvts_str($infoIn['dream'] ?? '', 80)];

    $scIn = is_array($in['sc'] ?? null) ? $in['sc'] : [];
    $rs = tvts_score_riasec($ans['r']);
    $sc = ['riasec' => $rs['scores'], 'code' => $rs['code'], 'diff' => $rs['diff'], 'cons' => $rs['cons'], 'b5' => [], 'gt' => [], 'caas' => [], 'top' => []];
    foreach (['b5' => T1_B5, 'gt' => T1_GT, 'caas' => T1_CAAS] as $grp => $keys) {
        $src = is_array($scIn[$grp] ?? null) ? $scIn[$grp] : [];
        foreach ($keys as $k) $sc[$grp][$k] = t1_num($src[$k] ?? null, 0, 5);
    }
    foreach (array_slice(is_array($scIn['top'] ?? null) ? $scIn['top'] : [], 0, 5) as $t) $sc['top'][] = tvts_str($t, 120);
    return [$info, $ans, $sc];
}

function t1_save(array $in): void
{
    if (!empty($in['hp'])) api_fail('Dữ liệu không hợp lệ.');
    if (($in['consent'] ?? false) !== true) api_fail('Em cần đánh dấu đồng ý trước khi gửi kết quả.');
    tvts_guard('tvts1_save_ip');
    [$info, $ans, $sc] = t1_clean($in);
    tvts_record('tvts1_save_ip');

    $now = time();
    $jI = json_encode($info, JSON_UNESCAPED_UNICODE); $jA = json_encode($ans, JSON_UNESCAPED_UNICODE); $jS = json_encode($sc, JSON_UNESCAPED_UNICODE);
    $code = t1_code_in($in['code'] ?? ''); $key = (string) ($in['key'] ?? '');
    if (strlen($code) === TVTS_CODE_LEN && preg_match('/^[a-f0-9]{32}$/', $key)) {
        $n = t1_run(function (PDO $pdo) use ($code, $key, $now, $info, $jI, $jA, $jS) {
            $st = $pdo->prepare('UPDATE tvts01_results SET upd = ?, grade = ?, school = ?, info = ?, answers = ?, scores = ? WHERE code = ? AND ekey = ?');
            $st->execute([$now, $info['grade'], $info['school'], $jI, $jA, $jS, $code, hash('sha256', $key)]);
            return $st->rowCount();
        });
        // rowCount = 0 cũng có thể do dữ liệu không đổi → kiểm lại mã + khoá có thật không.
        if (!$n) {
            $n = t1_run(function (PDO $pdo) use ($code, $key) { $st = $pdo->prepare('SELECT COUNT(*) FROM tvts01_results WHERE code = ? AND ekey = ?'); $st->execute([$code, hash('sha256', $key)]); return (int) $st->fetchColumn(); });
        }
        if ($n) api_ok(['code' => $code, 'key' => $key, 'updated' => true]);
    }
    $key = bin2hex(random_bytes(16));
    t1_run(function (PDO $pdo) use (&$code, $key, $now, $info, $jI, $jA, $jS) {
        for ($try = 0; $try < 5; $try++) {
            $code = tvts_code();
            try {
                $st = $pdo->prepare('INSERT INTO tvts01_results (code, ekey, ts, upd, iph, grade, school, info, answers, scores) VALUES (?,?,?,?,?,?,?,?,?,?)');
                $st->execute([$code, hash('sha256', $key), $now, $now, substr(hash('sha256', tvts_ip() . 'tvts01'), 0, 16), $info['grade'], $info['school'], $jI, $jA, $jS]);
                return;
            } catch (PDOException $e) {
                if (stripos($e->getMessage(), 'unique') === false && stripos($e->getMessage(), 'duplicate') === false) throw $e;
            }
        }
        throw new RuntimeException('Không tạo được mã kết quả.');
    });
    api_ok(['code' => $code, 'key' => $key, 'updated' => false]);
}

/** GET ?a=get1 — Bước 2 lấy 30 câu sở thích + thông tin để điền sẵn. */
function t1_get(string $codeIn): void
{
    $code = t1_code_in($codeIn);
    tvts_guard('tvts_get_ip');
    if (strlen($code) !== TVTS_CODE_LEN) { tvts_record('tvts_get_ip'); api_fail('Mã kết quả Bước 1 gồm 8 ký tự.'); }
    $x = t1_run(function (PDO $pdo) use ($code) { $st = $pdo->prepare('SELECT code, info, answers FROM tvts01_results WHERE code = ?'); $st->execute([$code]); return $st->fetch() ?: null; });
    if (!$x) { tvts_record('tvts_get_ip'); api_fail('Không tìm thấy kết quả Bước 1 với mã này.'); }
    $info = json_decode((string) $x['info'], true) ?: []; $ans = json_decode((string) $x['answers'], true) ?: [];
    api_ok(['code' => $x['code'], 'info' => ['name' => $info['name'] ?? '', 'grade' => $info['grade'] ?? '', 'school' => $info['school'] ?? ''], 'r' => $ans['r'] ?? []]);
}

/** Bản đồ mã Bước 1 → các mã Bước 2 (học sinh đi tiếp từ nút "Bước 2"). */
function t1_links(): array
{
    $rows = tvts_run(fn(PDO $pdo) => $pdo->query('SELECT code, info FROM tvts02_reports')->fetchAll());
    $m = [];
    foreach ($rows as $x) {
        $i = json_decode((string) $x['info'], true) ?: [];
        if (!empty($i['t1'])) $m[$i['t1']][] = $x['code'];
    }
    return $m;
}
function t1_row(array $x, array $links, bool $full): array
{
    $info = json_decode((string) $x['info'], true) ?: []; $sc = json_decode((string) $x['scores'], true) ?: [];
    $o = ['code' => $x['code'], 'ts' => (int) $x['ts'], 'time' => date('Y-m-d H:i', (int) $x['ts']), 'upd' => date('Y-m-d H:i', (int) $x['upd']),
          'name' => $info['name'] ?? '', 'grade' => $x['grade'], 'school' => $x['school'], 'dream' => $info['dream'] ?? '',
          'ma' => $sc['code'] ?? '', 'top1' => $sc['top'][0] ?? '', 'b2' => $links[$x['code']] ?? []];
    if ($full) { $o['info'] = $info; $o['scores'] = $sc; $o['answers'] = json_decode((string) $x['answers'], true) ?: []; }
    return $o;
}

/** Thao tác quản trị cho Bước 1: list1 | detail1 | delete1 | export1. */
function t1_admin(string $op, array $in, array $me): void
{
    if ($op === 'list1') {
        $rows = t1_run(fn(PDO $pdo) => $pdo->query('SELECT code, ts, upd, grade, school, info, scores FROM tvts01_results ORDER BY ts DESC LIMIT 3000')->fetchAll());
        $links = t1_links();
        $out = array_map(fn($x) => t1_row($x, $links, false), $rows);
        api_ok(['rows' => $out, 'stats' => ['n' => count($out), 'linked' => count(array_filter($out, fn($r) => $r['b2']))]]);
    }
    $code = t1_code_in($in['code'] ?? '');
    if ($op === 'detail1') {
        $x = t1_run(function (PDO $pdo) use ($code) { $st = $pdo->prepare('SELECT * FROM tvts01_results WHERE code = ?'); $st->execute([$code]); return $st->fetch() ?: null; });
        if (!$x) api_fail('Không tìm thấy bài.');
        api_ok(t1_row($x, t1_links(), true));
    }
    if ($op === 'delete1') {
        $n = t1_run(function (PDO $pdo) use ($code) { $st = $pdo->prepare('DELETE FROM tvts01_results WHERE code = ?'); $st->execute([$code]); return $st->rowCount(); });
        if (!$n) api_fail('Không tìm thấy bài để xoá.');
        error_log('[tvts01] admin ' . $me['userId'] . ' xoá bài ' . $code);
        api_ok(['deleted' => $code]);
    }
    if ($op === 'export1') {
        $rows = t1_run(fn(PDO $pdo) => $pdo->query('SELECT * FROM tvts01_results ORDER BY ts ASC')->fetchAll());
        $links = t1_links();
        $head = ['ma_buoc1', 'ma_buoc2', 'thoi_gian', 'cap_nhat', 'ho_ten', 'lop', 'truong', 'ma_holland'];
        foreach (TVTS_ORDER as $t) $head[] = 'RIASEC_' . $t;
        array_push($head, 'phan_hoa', 'nhat_quan');
        foreach (T1_B5 as $k) $head[] = 'BIG5_' . $k;
        foreach (T1_GT as $k) $head[] = 'GT_' . $k;
        foreach (['CON', 'COL', 'CUR', 'COF'] as $k) $head[] = 'CAAS_' . $k;
        $head[] = 'CAAS_chung';
        foreach (T1_SUBJECTS as $s) $head[] = 'MON_' . $s;
        array_push($head, 'nganh_goi_y_1', 'nganh_goi_y_2', 'nganh_goi_y_3', 'nghe_mo_uoc', 'xa_hoi_can');
        foreach (T1_N as $k => $n) for ($i = 1; $i <= $n; $i++) $head[] = $k . $i;
        $out = [$head]; $json = [];
        foreach ($rows as $x) {
            $info = json_decode((string) $x['info'], true) ?: []; $a = json_decode((string) $x['answers'], true) ?: []; $sc = json_decode((string) $x['scores'], true) ?: [];
            $line = [$x['code'], implode(' ', $links[$x['code']] ?? []), date('Y-m-d H:i:s', (int) $x['ts']), date('Y-m-d H:i:s', (int) $x['upd']),
                     $info['name'] ?? '', $x['grade'], $x['school'], $sc['code'] ?? ''];
            foreach (TVTS_ORDER as $t) $line[] = $sc['riasec'][$t] ?? '';
            array_push($line, $sc['diff'] ?? '', $sc['cons'] ?? '');
            foreach (T1_B5 as $k) $line[] = $sc['b5'][$k] ?? '';
            foreach (T1_GT as $k) $line[] = $sc['gt'][$k] ?? '';
            foreach (T1_CAAS as $k) $line[] = $sc['caas'][$k] ?? '';
            foreach (T1_SUBJECTS as $s) $line[] = $a['sub'][$s] ?? '';
            array_push($line, $sc['top'][0] ?? '', $sc['top'][1] ?? '', $sc['top'][2] ?? '', $info['dream'] ?? '', $a['need'] ?? '');
            foreach (T1_N as $k => $n) for ($i = 0; $i < $n; $i++) $line[] = $a[$k][$i] ?? '';
            $out[] = $line;
            $json[] = t1_row($x, $links, true);
        }
        $q = fn($v) => '"' . str_replace('"', '""', (string) $v) . '"';
        $csv = "\u{FEFF}" . implode("\r\n", array_map(fn($l) => implode(',', array_map($q, $l)), $out)) . "\r\n";
        api_ok(['csv' => $csv, 'n' => count($rows), 'json' => $json]);
    }
    api_fail('Thao tác quản trị không hợp lệ.');
}
