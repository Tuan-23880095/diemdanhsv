<?php
declare(strict_types=1);

/**
 * tvts02/api.php — Máy chủ của "La bàn Hướng nghiệp – bản có tự luận" (/TVTS02.html).
 *
 * Học sinh làm 30 câu sở thích (O*NET Mini-IP) + 10 câu tự luận. Máy chủ:
 *   1. chấm phần trắc nghiệm (RIASEC 0–20, mã Holland, độ phân hoá, độ nhất quán);
 *   2. sàng lọc nội dung cần quan tâm (tự hại, bạo hành…) → gắn cờ cho giáo viên;
 *   3. gửi bài viết + điểm trắc nghiệm + bộ tri thức (kb.php, rút từ notebook "huong nghiep")
 *      sang Gemini, nhận bản phân tích dạng JSON theo lược đồ cố định;
 *   4. kiểm từng câu trích dẫn: chỉ giữ câu có thật trong bài của học sinh;
 *   5. lọc các từ tự xưng công cụ (văn phong bản tư vấn), lưu CSDL, trả mã tra cứu.
 *
 * Dùng lại hạ tầng có sẵn: ../private/config.php (gemini.api_key), api/lib/db.php,
 * api/lib/response.php, api/lib/ratelimit.php (bucket tvts_submit_ip, tvts_get_ip),
 * và các hàm gọi Gemini trong api/lib/khtd.php (tự dò model, thử lần lượt, tắt "thinking").
 *
 * POST {a:'analyze', consent:true, info:{...}, r:[30 số 0–4], e:{q1..q10}, hp:''}
 * GET  ?a=get&code=XXXXXXXX
 *
 * Chạy thử cục bộ: TVTS_SQLITE=/tmp/t.sqlite TVTS_FAKE=/path/fake.json php -S localhost:8000
 * (chỉ CLI/cli-server; TVTS_FAKE = file JSON giả lập câu trả lời của Gemini).
 */

ini_set('display_errors', '0');
date_default_timezone_set('Asia/Ho_Chi_Minh');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$root = dirname(__DIR__);
require $root . '/api/lib/response.php';
require __DIR__ . '/kb.php';

$LOCAL = in_array(PHP_SAPI, ['cli', 'cli-server'], true);
$sqlitePath = $LOCAL ? getenv('TVTS_SQLITE') : false;
if (!$sqlitePath) {
    require $root . '/api/lib/config.php';
    require $root . '/api/lib/db.php';
    require $root . '/api/lib/ratelimit.php';
    require $root . '/api/lib/roles.php';
}

const TVTS_ALPHABET = 'ACDEFGHJKMNPQRTUVWXY34679';
const TVTS_CODE_LEN = 8;
const TVTS_MAX_ESSAY = 1500;     // ký tự / câu
const TVTS_MIN_ANSWERED = 7;     // số câu tự luận tối thiểu phải trả lời
const TVTS_MIN_TOTAL = 350;      // tổng ký tự tối thiểu của phần tự luận

/* ───────────── CSDL ───────────── */
/**
 * Kết nối CSDL. $fresh = true: mở kết nối MỚI — dùng sau lượt gọi phân tích dài (20–60 giây),
 * vì MySQL của hosting dùng chung đóng kết nối rảnh ("MySQL server has gone away").
 */
function tvts_db(bool $fresh = false): PDO
{
    global $sqlitePath;
    static $pdo = null;
    if ($pdo && !$fresh) return $pdo;
    if ($sqlitePath) {
        $pdo = new PDO('sqlite:' . $sqlitePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    } elseif (!$fresh) {
        $pdo = db();
    } else {
        $cfg = app_config()['db'] ?? [];
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['host'] ?? 'localhost', $cfg['name'] ?? '', $cfg['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, (string) ($cfg['user'] ?? ''), (string) ($cfg['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    }
    return $pdo;
}
function tvts_schema(): void
{
    $pdo = tvts_db();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('CREATE TABLE IF NOT EXISTS tvts02_reports (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, ts INTEGER, iph TEXT, grade TEXT, school TEXT, info TEXT, riasec TEXT, essays TEXT, report TEXT, flag INTEGER DEFAULT 0, flag_note TEXT, model TEXT, status TEXT)');
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS tvts02_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code CHAR(8) NOT NULL,
        ts INT NOT NULL,
        iph CHAR(16) NOT NULL DEFAULT '',
        grade VARCHAR(16) NOT NULL DEFAULT '',
        school VARCHAR(120) NOT NULL DEFAULT '',
        info TEXT NOT NULL,
        riasec TEXT NOT NULL,
        essays MEDIUMTEXT NOT NULL,
        report MEDIUMTEXT NOT NULL,
        flag TINYINT NOT NULL DEFAULT 0,
        flag_note VARCHAR(400) NOT NULL DEFAULT '',
        model VARCHAR(64) NOT NULL DEFAULT '',
        status VARCHAR(12) NOT NULL DEFAULT 'ok',
        UNIQUE KEY uq_tvts02_code (code), KEY k_tvts02_flag (flag, ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function tvts_run(callable $fn)
{
    try {
        return $fn(tvts_db());
    } catch (PDOException $e) {
        $m = $e->getMessage();
        if (stripos($m, 'gone away') !== false || stripos($m, 'Lost connection') !== false || stripos($m, '2006') !== false) {
            return $fn(tvts_db(true));                   // kết nối bị đóng do chờ lâu → mở lại một lần
        }
        if ($e->getCode() === '42S02' || stripos($m, 'no such table') !== false || stripos($m, "doesn't exist") !== false) {
            tvts_schema();
            return $fn(tvts_db());
        }
        throw $e;
    }
}
function tvts_ip(): string { return substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')), 0, 45); }
function tvts_guard(string $bucket): void { global $sqlitePath; if (!$sqlitePath) rate_limit_guard($bucket, tvts_ip()); }
function tvts_record(string $bucket): void { global $sqlitePath; if (!$sqlitePath) rate_limit_record($bucket, tvts_ip()); }
function tvts_code(): string
{
    $s = '';
    for ($i = 0; $i < TVTS_CODE_LEN; $i++) $s .= TVTS_ALPHABET[random_int(0, strlen(TVTS_ALPHABET) - 1)];
    return $s;
}
function tvts_str($v, int $max): string
{
    $s = is_string($v) ? $v : '';
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}

/* ───────────── CHẤM TRẮC NGHIỆM ───────────── */
function tvts_score_riasec(array $r): array
{
    $s = array_fill_keys(TVTS_ORDER, 0);
    foreach (TVTS_RIASEC_ITEMS as $i => $it) $s[$it[0]] += (int) $r[$i];
    $sorted = TVTS_ORDER;
    usort($sorted, fn($a, $b) => ($s[$b] <=> $s[$a]) ?: (array_search($a, TVTS_ORDER) <=> array_search($b, TVTS_ORDER)));
    $diff = $s[$sorted[0]] - $s[$sorted[5]];
    $d = abs(array_search($sorted[0], TVTS_ORDER) - array_search($sorted[1], TVTS_ORDER));
    $k = min($d, 6 - $d);
    return ['scores' => $s, 'code' => implode('', array_slice($sorted, 0, 3)), 'sorted' => $sorted,
            'diff' => $diff, 'cons' => $k === 1 ? 'cao' : ($k === 2 ? 'trung bình' : 'thấp')];
}

/* ───────────── SÀNG LỌC NỘI DUNG CẦN QUAN TÂM ───────────── */
function tvts_norm(string $s): string
{
    $s = mb_strtolower($s);
    $s = preg_replace('/[“”"‘’\'`´]/u', '', $s) ?? $s;
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}
function tvts_risk(string $all): string
{
    $t = tvts_norm($all);
    $pats = ['tự tử', 'tu tu ', 'muốn chết', 'không muốn sống', 'chẳng muốn sống', 'kết thúc cuộc đời', 'tự làm đau', 'tự làm hại',
             'rạch tay', 'cắt tay', 'nhảy lầu', 'uống thuốc ngủ', 'bị đánh đập', 'bạo hành', 'bị xâm hại', 'bị lạm dụng', 'bị quấy rối', 'trầm cảm nặng'];
    $hits = [];
    foreach ($pats as $p) if (mb_strpos($t, $p) !== false) $hits[] = trim($p);
    return implode(', ', $hits);
}

/* ───────────── LÀM SẠCH KẾT QUẢ ───────────── */
/** Bỏ các câu tự xưng công cụ (văn phong bản tư vấn). Không đụng tới tên ngành học hay lời trích của học sinh. */
function tvts_clean_voice(string $s): string
{
    $bad = '/(gemini|chatgpt|chatbot|mô hình ngôn ngữ|language model|\bLLM\b|trợ lý ảo|là một (AI|trí tuệ nhân tạo|mô hình)|với tư cách (là )?(một )?(AI|trí tuệ nhân tạo|mô hình)|(hệ thống|công cụ|phần mềm) (AI|trí tuệ nhân tạo)|(bằng|của|nhờ) (AI|trí tuệ nhân tạo) (phân tích|đánh giá))/iu';
    $parts = preg_split('/(?<=[.!?…])\s+/u', $s) ?: [$s];
    $keep = array_filter($parts, fn($p) => !preg_match($bad, $p));
    return trim(implode(' ', $keep));
}
function tvts_clean_tree($v)
{
    if (is_string($v)) return tvts_clean_voice($v);
    if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = tvts_clean_tree($x); return $v; }
    return $v;
}
/** Chỉ giữ câu trích có thật trong bài viết (so sau khi chuẩn hoá khoảng trắng, dấu nháy, chữ hoa). */
function tvts_verify_quotes(array $quotes, string $haystackNorm, int $max = 3): array
{
    $out = [];
    foreach ($quotes as $q) {
        if (!is_string($q)) continue;
        $q = trim($q, " \t\n\r\0\x0B\"“”'‘’…");
        $n = tvts_norm($q);
        if (mb_strlen($n) < 6) continue;
        if (mb_strpos($haystackNorm, $n) === false) {
            // cho phép trích bị cắt bớt ở đầu/cuối: thử phần lõi 80%
            $len = mb_strlen($n); $core = mb_substr($n, (int) ($len * 0.1), (int) ($len * 0.8));
            if (mb_strlen($core) < 12 || mb_strpos($haystackNorm, $core) === false) continue;
        }
        $out[] = mb_substr($q, 0, 260);
        if (count($out) >= $max) break;
    }
    return $out;
}
function tvts_shape_report(array $raw, string $hay): array
{
    $majors = tvts_majors_by_id();
    $s = fn($v, $m) => mb_substr(trim(is_string($v) ? $v : ''), 0, $m);
    $rep = [
        'chan_dung' => $s($raw['chan_dung'] ?? '', 900),
        'chu_de' => $s($raw['chu_de'] ?? '', 300),
        'ma_bai_viet' => preg_match('/^[RIASEC]{2,3}$/', (string) ($raw['ma_bai_viet'] ?? '')) ? (string) $raw['ma_bai_viet'] : '',
        'doi_chieu' => $s($raw['doi_chieu'] ?? '', 700),
        'nhan_dinh' => [], 'nhom_nganh' => [], 'buoc_tiep' => [], 'loi_nhan' => $s($raw['loi_nhan'] ?? '', 600),
        'can_quan_tam' => !empty($raw['can_quan_tam']), 'ly_do_quan_tam' => $s($raw['ly_do_quan_tam'] ?? '', 300),
    ];
    foreach ((array) ($raw['nhan_dinh'] ?? []) as $x) {
        if (!is_array($x)) continue;
        $t = $s($x['noi_dung'] ?? '', 800);
        if ($t === '') continue;
        $rep['nhan_dinh'][] = ['khia_canh' => $s($x['khia_canh'] ?? '', 80), 'noi_dung' => $t,
            'trich' => tvts_verify_quotes((array) ($x['trich'] ?? []), $hay), 'co_so' => $s($x['co_so'] ?? '', 160)];
        if (count($rep['nhan_dinh']) >= 7) break;
    }
    $seen = [];
    foreach ((array) ($raw['nhom_nganh'] ?? []) as $x) {
        if (!is_array($x)) continue;
        $id = (int) ($x['id'] ?? 0);
        if (!isset($majors[$id]) || isset($seen[$id])) continue;
        $seen[$id] = 1;
        $m = $majors[$id];
        $rep['nhom_nganh'][] = ['id' => $id, 'ten' => $m['n'], 'ma' => $m['c'], 'vi_du' => $m['ex'], 'mon' => $m['sn'],
            'ly_do' => $s($x['ly_do'] ?? '', 600), 'trich' => tvts_verify_quotes((array) ($x['trich'] ?? []), $hay, 2),
            'can_tim_hieu' => $s($x['can_tim_hieu'] ?? '', 300)];
        if (count($rep['nhom_nganh']) >= 8) break;
    }
    foreach ((array) ($raw['buoc_tiep'] ?? []) as $x) { $x = $s($x, 300); if ($x !== '') $rep['buoc_tiep'][] = $x; if (count($rep['buoc_tiep']) >= 6) break; }
    return tvts_clean_tree($rep);
}

/* ───────────── GỌI PHÂN TÍCH ───────────── */
function tvts_schema_json(): array
{
    $str = ['type' => 'STRING'];
    $arrStr = ['type' => 'ARRAY', 'items' => $str];
    return ['type' => 'OBJECT', 'properties' => [
        'chan_dung' => $str, 'chu_de' => $str, 'ma_bai_viet' => $str, 'doi_chieu' => $str,
        'nhan_dinh' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => [
            'khia_canh' => $str, 'noi_dung' => $str, 'trich' => $arrStr, 'co_so' => $str], 'required' => ['khia_canh', 'noi_dung', 'trich', 'co_so']]],
        'nhom_nganh' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => [
            'id' => ['type' => 'INTEGER'], 'ly_do' => $str, 'trich' => $arrStr, 'can_tim_hieu' => $str], 'required' => ['id', 'ly_do', 'trich', 'can_tim_hieu']]],
        'buoc_tiep' => $arrStr, 'loi_nhan' => $str,
        'can_quan_tam' => ['type' => 'BOOLEAN'], 'ly_do_quan_tam' => $str,
    ], 'required' => ['chan_dung', 'chu_de', 'ma_bai_viet', 'doi_chieu', 'nhan_dinh', 'nhom_nganh', 'buoc_tiep', 'loi_nhan', 'can_quan_tam', 'ly_do_quan_tam']];
}
/** @return array{0:?array,1:string,2:string} [kết quả thô, model, lỗi] */
function tvts_analyze(array $payload): array
{
    global $LOCAL;
    if ($LOCAL && getenv('TVTS_FAKE')) {
        $raw = json_decode((string) @file_get_contents((string) getenv('TVTS_FAKE')), true);
        return [is_array($raw) ? $raw : null, 'fake', is_array($raw) ? '' : 'fake json hỏng'];
    }
    try {
        require_once dirname(__DIR__) . '/api/lib/khtd.php';
        $g = khtd_gemini_config();
        if ($g['key'] === '') return [null, '', 'chưa có khóa'];
        $cand = khtd_gemini_candidates($g);
        if (!$cand['models']) return [null, '', 'không có model'];
        [$txt, $model] = khtd_gemini_generate($cand['models'], $g['key'], [
            'systemInstruction' => ['parts' => [['text' => tvts_kb_system()]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]]]],
            'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => 8192,
                'responseMimeType' => 'application/json', 'responseSchema' => tvts_schema_json()],
        ]);
        $txt = trim(preg_replace('/^```(?:json)?|```$/m', '', $txt) ?? $txt);
        $raw = json_decode($txt, true);
        return [is_array($raw) ? $raw : null, $model, is_array($raw) ? '' : 'không đọc được JSON'];
    } catch (Throwable $e) {
        error_log('[tvts02] analyze: ' . $e->getMessage());
        return [null, '', mb_substr($e->getMessage(), 0, 200)];
    }
}

/* ───────────── QUẢN TRỊ (chỉ tài khoản ADMIN của hệ thống diemdanhsv) ───────────── */
function tvts_admin_me(array $in): array
{
    global $sqlitePath;
    if ($sqlitePath && getenv('TVTS_ADMIN_TEST')) return ['userId' => 'test', 'role' => 'ADMIN', 'name' => 'Thử nghiệm'];
    return require_role((string) ($in['token'] ?? ''), ['ADMIN']);
}
function tvts_admin_row(array $x, bool $full): array
{
    $info = json_decode((string) $x['info'], true) ?: [];
    $rs = json_decode((string) $x['riasec'], true) ?: [];
    $rep = json_decode((string) $x['report'], true) ?: [];
    $o = ['code' => $x['code'], 'ts' => (int) $x['ts'], 'time' => date('Y-m-d H:i', (int) $x['ts']),
          'name' => $info['name'] ?? '', 'grade' => $x['grade'], 'school' => $x['school'], 'gender' => $info['gender'] ?? '',
          'ma_tn' => $rs['code'] ?? '', 'ma_bv' => $rep['ma_bai_viet'] ?? '', 'status' => $x['status'],
          'flag' => (int) $x['flag'], 'flag_note' => $x['flag_note'], 'model' => $x['model']];
    if ($full) { $o['info'] = $info; $o['riasec'] = $rs; $o['essays'] = json_decode((string) $x['essays'], true) ?: []; $o['report'] = $rep; }
    return $o;
}
function tvts_admin(array $in): void
{
    $me = tvts_admin_me($in);
    $op = (string) ($in['op'] ?? '');
    if ($op === 'me') api_ok(['name' => $me['name']]);
    if ($op === 'list') {
        $rows = tvts_run(function (PDO $pdo) use ($in) {
            $where = !empty($in['flagOnly']) ? 'WHERE flag = 1' : '';
            return $pdo->query("SELECT code, ts, grade, school, info, riasec, report, status, flag, flag_note, model FROM tvts02_reports $where ORDER BY ts DESC LIMIT 2000")->fetchAll();
        });
        $stats = tvts_run(fn(PDO $pdo) => $pdo->query("SELECT COUNT(*) AS n, SUM(flag) AS f, SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS p FROM tvts02_reports")->fetch());
        api_ok(['rows' => array_map(fn($x) => tvts_admin_row($x, false), $rows), 'stats' => ['n' => (int) $stats['n'], 'flag' => (int) $stats['f'], 'pending' => (int) $stats['p']]]);
    }
    if ($op === 'detail' || $op === 'reanalyze') {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($in['code'] ?? '')) ?? '');
        $x = tvts_run(function (PDO $pdo) use ($code) { $st = $pdo->prepare('SELECT * FROM tvts02_reports WHERE code = ?'); $st->execute([$code]); return $st->fetch() ?: null; });
        if (!$x) api_fail('Không tìm thấy bài.');
        if ($op === 'reanalyze') {
            $info = json_decode((string) $x['info'], true) ?: [];
            $rs = json_decode((string) $x['riasec'], true) ?: [];
            $essays = json_decode((string) $x['essays'], true) ?: [];
            $all = implode("\n", $essays);
            $payload = [
                'hoc_sinh' => ['lop' => $info['grade'] ?? '', 'gioi_tinh' => $info['gender'] ?? ''],
                'trac_nghiem_so_thich' => ['thang' => '0–20 mỗi nhóm', 'diem' => $rs['scores'] ?? [], 'ma_holland' => $rs['code'] ?? '', 'do_phan_hoa' => $rs['diff'] ?? 0, 'do_nhat_quan' => $rs['cons'] ?? ''],
                'bai_viet' => array_map(fn($k) => ['cau' => $k, 'hoi' => TVTS_ESSAYS[$k]['q'], 'tra_loi' => (string) ($essays[$k] ?? '')], array_keys(TVTS_ESSAYS)),
                'danh_sach_nhom_nganh' => tvts_majors_for_prompt(),
                'dau_hieu_can_quan_tam_so_bo' => tvts_risk($all),
            ];
            [$raw, $model, $err] = tvts_analyze($payload);
            tvts_db(true);
            $report = $raw ? tvts_shape_report($raw, tvts_norm($all)) : null;
            if (!$report) api_fail('Phân tích lại chưa được: ' . $err);
            $flag = ((int) $x['flag'] === 1 || $report['can_quan_tam']) ? 1 : 0;
            tvts_run(function (PDO $pdo) use ($code, $report, $model, $flag) {
                $st = $pdo->prepare("UPDATE tvts02_reports SET report = ?, model = ?, status = 'ok', flag = ? WHERE code = ?");
                $st->execute([json_encode($report, JSON_UNESCAPED_UNICODE), mb_substr($model, 0, 64), $flag, $code]);
            });
            $x['report'] = json_encode($report, JSON_UNESCAPED_UNICODE); $x['status'] = 'ok'; $x['model'] = $model; $x['flag'] = $flag;
        }
        api_ok(tvts_admin_row($x, true));
    }
    if ($op === 'export') {
        $rows = tvts_run(fn(PDO $pdo) => $pdo->query('SELECT * FROM tvts02_reports ORDER BY ts ASC')->fetchAll());
        $head = ['ma_tra_cuu', 'thoi_gian', 'ho_ten', 'lop', 'truong', 'gioi_tinh'];
        foreach (TVTS_ORDER as $t) $head[] = 'RIASEC_' . $t;
        array_push($head, 'ma_holland_tn', 'do_phan_hoa', 'do_nhat_quan');
        for ($i = 1; $i <= count(TVTS_RIASEC_ITEMS); $i++) $head[] = 'r' . $i;
        foreach (array_keys(TVTS_ESSAYS) as $k) $head[] = $k;
        array_push($head, 'ma_holland_bai_viet', 'chu_de', 'nhom_nganh_goi_y', 'can_quan_tam', 'ghi_chu_quan_tam', 'trang_thai', 'model');
        $out = [$head];
        foreach ($rows as $x) {
            $info = json_decode((string) $x['info'], true) ?: []; $rs = json_decode((string) $x['riasec'], true) ?: [];
            $es = json_decode((string) $x['essays'], true) ?: []; $rep = json_decode((string) $x['report'], true) ?: [];
            $line = [$x['code'], date('Y-m-d H:i:s', (int) $x['ts']), $info['name'] ?? '', $x['grade'], $x['school'], $info['gender'] ?? ''];
            foreach (TVTS_ORDER as $t) $line[] = $rs['scores'][$t] ?? '';
            array_push($line, $rs['code'] ?? '', $rs['diff'] ?? '', $rs['cons'] ?? '');
            for ($i = 0; $i < count(TVTS_RIASEC_ITEMS); $i++) $line[] = $rs['r'][$i] ?? '';
            foreach (array_keys(TVTS_ESSAYS) as $k) $line[] = $es[$k] ?? '';
            $mj = implode(' | ', array_map(fn($m) => $m['ten'] ?? '', (array) ($rep['nhom_nganh'] ?? [])));
            array_push($line, $rep['ma_bai_viet'] ?? '', $rep['chu_de'] ?? '', $mj, (int) $x['flag'], $x['flag_note'], $x['status'], $x['model']);
            $out[] = $line;
        }
        $q = fn($v) => '"' . str_replace('"', '""', (string) $v) . '"';
        $csv = "\u{FEFF}" . implode("\r\n", array_map(fn($l) => implode(',', array_map($q, $l)), $out)) . "\r\n";
        api_ok(['csv' => $csv, 'n' => count($rows), 'json' => array_map(fn($x) => tvts_admin_row($x, true), $rows)]);
    }
    api_fail('Thao tác quản trị không hợp lệ.');
}

/* ───────────── ĐIỀU PHỐI ───────────── */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    if ($method === 'GET') {
        $a = (string) ($_GET['a'] ?? '');
        if ($a === 'ping') api_ok(['ok' => true]);
        if ($a !== 'get') api_fail('Thao tác không hợp lệ.');
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['code'] ?? '')) ?? '');
        tvts_guard('tvts_get_ip');
        if (strlen($code) !== TVTS_CODE_LEN) { tvts_record('tvts_get_ip'); api_fail('Mã tra cứu gồm 8 ký tự.'); }
        $row = tvts_run(function (PDO $pdo) use ($code) {
            $st = $pdo->prepare('SELECT code, ts, info, riasec, report, status FROM tvts02_reports WHERE code = ?');
            $st->execute([$code]);
            return $st->fetch() ?: null;
        });
        if (!$row) { tvts_record('tvts_get_ip'); api_fail('Không tìm thấy kết quả với mã này.'); }
        api_ok(['code' => $row['code'], 'ts' => (int) $row['ts'], 'info' => json_decode($row['info'], true),
                'riasec' => json_decode($row['riasec'], true), 'report' => json_decode($row['report'], true), 'status' => $row['status']]);
    }
    if ($method !== 'POST') { http_response_code(405); api_fail('Phương thức không hợp lệ.'); }

    $in = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($in) && ($in['a'] ?? '') === 'admin') tvts_admin($in);
    if (!is_array($in) || ($in['a'] ?? '') !== 'analyze') api_fail('Dữ liệu không hợp lệ.');
    if (!empty($in['hp'])) api_fail('Dữ liệu không hợp lệ.');                 // bẫy máy gửi tự động
    if (($in['consent'] ?? false) !== true) api_fail('Em cần đánh dấu đồng ý trước khi gửi bài.');
    tvts_guard('tvts_submit_ip');

    $r = $in['r'] ?? null;
    if (!is_array($r) || count($r) !== count(TVTS_RIASEC_ITEMS)) api_fail('Phần trắc nghiệm chưa đủ 30 câu.');
    $r = array_values($r);
    foreach ($r as $i => $v) { if (!is_int($v) || $v < 0 || $v > 4) api_fail('Câu trắc nghiệm ' . ($i + 1) . ' không hợp lệ.'); }

    $infoIn = is_array($in['info'] ?? null) ? $in['info'] : [];
    $info = ['name' => tvts_str($infoIn['name'] ?? '', 60), 'grade' => tvts_str($infoIn['grade'] ?? '', 16),
             'school' => tvts_str($infoIn['school'] ?? '', 120), 'gender' => tvts_str($infoIn['gender'] ?? '', 16)];

    $eIn = is_array($in['e'] ?? null) ? $in['e'] : [];
    $essays = []; $answered = 0; $total = 0;
    foreach (TVTS_ESSAYS as $k => $q) {
        $t = tvts_str($eIn[$k] ?? '', TVTS_MAX_ESSAY);
        $essays[$k] = $t;
        if (mb_strlen($t) >= 10) { $answered++; $total += mb_strlen($t); }
    }
    if ($answered < TVTS_MIN_ANSWERED) api_fail('Em cần trả lời ít nhất ' . TVTS_MIN_ANSWERED . ' trong 10 câu tự luận.');
    if ($total < TVTS_MIN_TOTAL) api_fail('Bài viết còn quá ngắn để phân tích. Em hãy viết thêm vài câu ở mỗi phần.');
    tvts_record('tvts_submit_ip');

    $rs = tvts_score_riasec($r);
    $rs['r'] = $r;                                   // câu trả lời gốc — phục vụ nghiên cứu, chuẩn hoá thang đo
    $all = implode("\n", $essays);
    $risk = tvts_risk($all);

    $payload = [
        'hoc_sinh' => ['lop' => $info['grade'], 'gioi_tinh' => $info['gender']],
        'trac_nghiem_so_thich' => ['thang' => '0–20 mỗi nhóm', 'diem' => $rs['scores'], 'ma_holland' => $rs['code'],
            'do_phan_hoa' => $rs['diff'], 'do_nhat_quan' => $rs['cons']],
        'bai_viet' => array_map(fn($k) => ['cau' => $k, 'hoi' => TVTS_ESSAYS[$k]['q'], 'tra_loi' => $essays[$k]], array_keys(TVTS_ESSAYS)),
        'danh_sach_nhom_nganh' => tvts_majors_for_prompt(),
        'dau_hieu_can_quan_tam_so_bo' => $risk,
    ];
    // 1) Lưu bài TRƯỚC (trạng thái chờ) — nếu bước phân tích quá thời gian, bài của học sinh vẫn còn;
    //    quản trị viên bấm "Phân tích lại" sau.
    @set_time_limit(180);
    $code = '';
    tvts_run(function (PDO $pdo) use (&$code, $info, $rs, $essays, $risk) {
        for ($try = 0; $try < 5; $try++) {
            $code = tvts_code();
            try {
                $st = $pdo->prepare('INSERT INTO tvts02_reports (code, ts, iph, grade, school, info, riasec, essays, report, flag, flag_note, model, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $st->execute([$code, time(), substr(hash('sha256', tvts_ip() . 'tvts02'), 0, 16), $info['grade'], $info['school'],
                    json_encode($info, JSON_UNESCAPED_UNICODE), json_encode($rs, JSON_UNESCAPED_UNICODE),
                    json_encode($essays, JSON_UNESCAPED_UNICODE), json_encode(['loi' => 'đang phân tích'], JSON_UNESCAPED_UNICODE),
                    $risk !== '' ? 1 : 0, mb_substr($risk, 0, 400), '', 'pending']);
                return;
            } catch (PDOException $e) {
                if (stripos($e->getMessage(), 'unique') === false && stripos($e->getMessage(), 'duplicate') === false) throw $e;
            }
        }
        throw new RuntimeException('Không tạo được mã tra cứu.');
    });

    // 2) Phân tích rồi cập nhật.
    [$raw, $model, $err] = tvts_analyze($payload);
    tvts_db(true);                                   // mở kết nối mới sau lượt chờ dài
    $hay = tvts_norm($all);
    $report = $raw ? tvts_shape_report($raw, $hay) : null;
    if ($report && !$report['nhan_dinh'] && !$report['nhom_nganh']) $report = null;
    $status = $report ? 'ok' : 'pending';
    $flag = ($risk !== '' || ($report['can_quan_tam'] ?? false)) ? 1 : 0;
    $flagNote = trim($risk . ($report && $report['ly_do_quan_tam'] ? ' | ' . $report['ly_do_quan_tam'] : ''), ' |');
    tvts_run(function (PDO $pdo) use ($code, $report, $flag, $flagNote, $model, $status, $err) {
        $st = $pdo->prepare('UPDATE tvts02_reports SET report = ?, flag = ?, flag_note = ?, model = ?, status = ? WHERE code = ?');
        $st->execute([json_encode($report ?? ['loi' => $err], JSON_UNESCAPED_UNICODE), $flag, mb_substr($flagNote, 0, 400), mb_substr($model, 0, 64), $status, $code]);
    });

    api_ok(['code' => $code, 'info' => $info, 'riasec' => $rs, 'report' => $report, 'status' => $status, 'support' => $flag === 1]);
} catch (PDOException $e) {
    error_log('[tvts02] db: ' . $e->getMessage());
    api_fail('Máy chủ đang bận. Em thử lại sau ít phút nhé. (mã lỗi D' . (int) ($e->errorInfo[1] ?? 0) . ')');
} catch (RuntimeException $e) {                     // lỗi nghiệp vụ viết sẵn (vd require_role: hết phiên, không đủ quyền)
    api_fail($e->getMessage());
} catch (Throwable $e) {
    error_log('[tvts02] ' . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getLine());
    api_fail('Máy chủ đang bận. Em thử lại sau ít phút nhé. (mã lỗi T' . $e->getLine() . ')');
}
