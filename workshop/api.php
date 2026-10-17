<?php
declare(strict_types=1);

/**
 * workshop/api.php — API nhỏ cho Workshop "Quản lý thời gian & Kế hoạch cá nhân" (K2026).
 *
 * Thay cho Firebase Realtime DB của bản trước (CSDL đó chưa từng được tạo → 404,
 * nên bình chọn trên điện thoại sinh viên không bao giờ về tới màn hình hội trường).
 * Dùng lại hạ tầng có sẵn của diemdanhsv: cấu hình bí mật ../private/config.php,
 * kết nối MySQL api/lib/db.php, phong bì {status,message,data} api/lib/response.php,
 * và bộ đếm chặn dò mật khẩu api/lib/ratelimit.php (bucket login_ip).
 *
 * Bảng ws_* tự tạo ở lần gọi đầu tiên (CREATE TABLE IF NOT EXISTS) — không cần migration tay.
 *
 * GET  ?a=results&act=<hoạt động>      tổng hợp (không trả nội dung chữ của sinh viên)
 * GET  ?a=questions&c=<client>         câu hỏi Q&A đang hiện + những câu client này đã bình chọn
 * GET  ?a=state                        hoạt động diễn giả đang mở
 * POST {a:'vote', act, c, d}           ghi/ghi đè phiếu của một thiết bị cho một hoạt động
 * POST {a:'ask', c, text}              gửi câu hỏi ẩn danh
 * POST {a:'upq', c, qid}               bình chọn câu hỏi (mỗi thiết bị 1 lần / câu)
 * POST {a:'admin', pin, op, ...}       thao tác của diễn giả — cần mã PIN
 *
 * Chạy thử cục bộ: WS_SQLITE=/tmp/ws.sqlite php -S localhost:8000  (chỉ CLI/cli-server).
 */

ini_set('display_errors', '0');
date_default_timezone_set('Asia/Ho_Chi_Minh');

$root = dirname(__DIR__);
require $root . '/api/lib/response.php';

/** Bcrypt của mã PIN diễn giả. Ghi đè được bằng app.workshop_pin_hash trong private/config.php. */
const WS_PIN_HASH = '$2y$11$5Tu3CvRZ3LcshAWDAD4bD.E5AVP9xABQVabnSq7ev5cshySKuB8BW';
const WS_SESSION_DEFAULT = 'k26-1810';
const WS_SHAPES = ['vuong', 'tamgiac', 'chunhat', 'tron', 'luonsong'];
const WS_EISEN_CARDS = ['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7', 'c8'];
const WS_ACTS = ['khoidong', 'hinh', 'muctieu', 'eisen', 'banhxe', 'camket', 'danhgia', 'nc0', 'nc1', 'nc2', 'nc3'];
const WS_ASK_PER_CLIENT = 6;     // câu hỏi / 10 phút / thiết bị
const WS_WRITES_PER_IP = 3000;   // lượt ghi / 10 phút / IP (cả hội trường có thể chung một IP NAT)

$sqlitePath = in_array(PHP_SAPI, ['cli', 'cli-server'], true) ? getenv('WS_SQLITE') : false;
if (!$sqlitePath) {
    require $root . '/api/lib/config.php';
    require $root . '/api/lib/db.php';
    require $root . '/api/lib/ratelimit.php';
}

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

function ws_db(): PDO
{
    global $sqlitePath;
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if ($sqlitePath) {
        $pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 3000');
    } else {
        $pdo = db();
    }
    return $pdo;
}

function ws_is_sqlite(): bool
{
    return ws_db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

function ws_schema(): void
{
    $pdo = ws_db();
    if (ws_is_sqlite()) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS ws_votes (id INTEGER PRIMARY KEY AUTOINCREMENT, sess TEXT, act TEXT, client TEXT, ip TEXT, data TEXT, ts INTEGER, UNIQUE(sess, act, client))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS ws_questions (id INTEGER PRIMARY KEY AUTOINCREMENT, sess TEXT, client TEXT, ip TEXT, text TEXT, votes INTEGER DEFAULT 0, status INTEGER DEFAULT 0, ts INTEGER)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS ws_qvotes (qid INTEGER, client TEXT, PRIMARY KEY (qid, client))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS ws_state (sess TEXT PRIMARY KEY, data TEXT, ts INTEGER)');
        return;
    }
    $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec('CREATE TABLE IF NOT EXISTS ws_votes (id INT AUTO_INCREMENT PRIMARY KEY, sess VARCHAR(24) NOT NULL, act VARCHAR(16) NOT NULL, client VARCHAR(32) NOT NULL, ip VARCHAR(45) NOT NULL DEFAULT \'\', data TEXT NOT NULL, ts INT NOT NULL, UNIQUE KEY uq_ws_vote (sess, act, client), KEY k_ws_vote_act (sess, act))' . $t);
    $pdo->exec('CREATE TABLE IF NOT EXISTS ws_questions (id INT AUTO_INCREMENT PRIMARY KEY, sess VARCHAR(24) NOT NULL, client VARCHAR(32) NOT NULL, ip VARCHAR(45) NOT NULL DEFAULT \'\', text VARCHAR(400) NOT NULL, votes INT NOT NULL DEFAULT 0, status TINYINT NOT NULL DEFAULT 0, ts INT NOT NULL, KEY k_ws_q (sess, status))' . $t);
    $pdo->exec('CREATE TABLE IF NOT EXISTS ws_qvotes (qid INT NOT NULL, client VARCHAR(32) NOT NULL, PRIMARY KEY (qid, client))' . $t);
    $pdo->exec('CREATE TABLE IF NOT EXISTS ws_state (sess VARCHAR(24) PRIMARY KEY, data TEXT NOT NULL, ts INT NOT NULL)' . $t);
}

/** Chạy $fn; nếu bảng chưa có thì tạo bảng rồi chạy lại đúng một lần. */
function ws_run(callable $fn)
{
    try {
        return $fn(ws_db());
    } catch (PDOException $e) {
        $m = $e->getMessage();
        if ($e->getCode() === '42S02' || stripos($m, 'no such table') !== false || stripos($m, "doesn't exist") !== false) {
            ws_schema();
            return $fn(ws_db());
        }
        throw $e;
    }
}

function ws_ip(): string
{
    return substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')), 0, 45);
}

function ws_sess(array $p): string
{
    $s = strtolower(trim((string) ($p['s'] ?? WS_SESSION_DEFAULT)));
    return preg_match('/^[a-z0-9-]{1,24}$/', $s) ? $s : WS_SESSION_DEFAULT;
}

function ws_client(array $p): string
{
    $c = (string) ($p['c'] ?? '');
    if (!preg_match('/^[A-Za-z0-9]{8,32}$/', $c)) {
        api_fail('Thiết bị chưa được nhận diện. Tải lại trang rồi thử lại.');
    }
    return $c;
}

function ws_text(string $s, int $max): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $max);
}

function ws_int($v, int $min, int $max): ?int
{
    if (!is_numeric($v)) {
        return null;
    }
    $i = (int) $v;
    return ($i >= $min && $i <= $max) ? $i : null;
}

function ws_guard_writes(string $sess): void
{
    $since = time() - 600;
    $ip = ws_ip();
    $n = (int) ws_run(function (PDO $pdo) use ($since, $ip, $sess) {
        $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM ws_votes WHERE ip = ? AND ts > ?) + (SELECT COUNT(*) FROM ws_questions WHERE ip = ? AND ts > ?)');
        $st->execute([$ip, $since, $ip, $since]);
        return $st->fetchColumn();
    });
    if ($n >= WS_WRITES_PER_IP) {
        api_fail('Mạng của bạn gửi quá nhiều lượt. Đợi vài phút rồi thử lại.');
    }
}

/** Kiểm tra & chuẩn hoá phiếu theo từng hoạt động. Trả mảng sạch để lưu. */
function ws_clean_vote(string $act, $d): array
{
    if (in_array($act, WS_NC_ACTS, true)) {
        return ws_nc_clean($act, $d);
    }
    if (!is_array($d)) {
        api_fail('Phiếu không hợp lệ.');
    }
    switch ($act) {
        case 'khoidong':
            $cam = ws_int($d['cam'] ?? null, 0, 3);
            $man = ws_int($d['man'] ?? null, 0, 4);
            if ($cam === null || $man === null) {
                api_fail('Hãy chọn đủ hai câu.');
            }
            return ['cam' => $cam, 'man' => $man];
        case 'hinh':
            $top = (string) ($d['top'] ?? '');
            $sec = (string) ($d['second'] ?? '');
            if (!in_array($top, WS_SHAPES, true)) {
                api_fail('Hãy chọn một hình.');
            }
            if (!in_array($sec, WS_SHAPES, true) || $sec === $top) {
                $sec = '';
            }
            return ['top' => $top, 'second' => $sec];
        case 'eisen':
            $cards = $d['cards'] ?? null;
            if (!is_array($cards)) {
                api_fail('Chưa xếp thẻ nào.');
            }
            $out = [];
            foreach (WS_EISEN_CARDS as $id) {
                $q = ws_int($cards[$id] ?? null, 1, 4);
                if ($q !== null) {
                    $out[$id] = $q;
                }
            }
            if (count($out) < count(WS_EISEN_CARDS)) {
                api_fail('Hãy xếp đủ ' . count(WS_EISEN_CARDS) . ' thẻ vào các ô.');
            }
            return ['cards' => $out];
        case 'banhxe':
            $v = $d['v'] ?? null;
            if (!is_array($v) || count($v) !== 8) {
                api_fail('Hãy chấm đủ 8 lĩnh vực.');
            }
            $out = [];
            foreach (array_values($v) as $x) {
                $i = ws_int($x, 1, 10);
                if ($i === null) {
                    api_fail('Điểm mỗi lĩnh vực từ 1 đến 10.');
                }
                $out[] = $i;
            }
            return ['v' => $out];
        case 'muctieu':
            $f = $d['f'] ?? null;
            if (!is_array($f) || count($f) !== 5) {
                api_fail('Thiếu phần tự kiểm SMART.');
            }
            $flags = array_map(fn ($x) => $x ? 1 : 0, array_values($f));
            $text = ws_text((string) ($d['text'] ?? ''), 300);
            if (mb_strlen($text) < 8) {
                api_fail('Mục tiêu hơi ngắn — viết rõ hơn một chút nhé.');
            }
            return ['f' => $flags, 'text' => $text];
        case 'camket':
            $pick = ws_int($d['pick'] ?? null, 0, 7);
            if ($pick === null) {
                api_fail('Hãy chọn một cam kết.');
            }
            return ['pick' => $pick];
        case 'danhgia':
            $sao = ws_int($d['sao'] ?? null, 1, 5);
            if ($sao === null) {
                api_fail('Hãy chọn số sao.');
            }
            return ['sao' => $sao, 'text' => ws_text((string) ($d['text'] ?? ''), 300)];
    }
    api_fail('Hoạt động không tồn tại.');
    return [];
}

/** Tổng hợp phiếu. $withText = true chỉ dành cho diễn giả (chứa chữ sinh viên viết). */
function ws_results(string $sess, string $act, bool $withText = false): array
{
    $rows = ws_run(function (PDO $pdo) use ($sess, $act) {
        $st = $pdo->prepare('SELECT data, ts FROM ws_votes WHERE sess = ? AND act = ? ORDER BY ts');
        $st->execute([$sess, $act]);
        return $st->fetchAll();
    });
    $n = count($rows);
    $out = ['act' => $act, 'n' => $n];
    $all = array_map(fn ($r) => json_decode((string) $r['data'], true) ?: [], $rows);

    switch ($act) {
        case 'khoidong':
            $cam = array_fill(0, 4, 0);
            $man = array_fill(0, 5, 0);
            foreach ($all as $d) {
                $cam[(int) ($d['cam'] ?? 0)]++;
                $man[(int) ($d['man'] ?? 0)]++;
            }
            $out += ['cam' => $cam, 'man' => $man];
            break;
        case 'hinh':
            $top = array_fill_keys(WS_SHAPES, 0);
            $sec = array_fill_keys(WS_SHAPES, 0);
            foreach ($all as $d) {
                if (isset($top[$d['top'] ?? ''])) {
                    $top[$d['top']]++;
                }
                if (isset($sec[$d['second'] ?? ''])) {
                    $sec[$d['second']]++;
                }
            }
            $out += ['top' => $top, 'second' => $sec];
            break;
        case 'eisen':
            $m = [];
            foreach (WS_EISEN_CARDS as $id) {
                $m[$id] = [0, 0, 0, 0];
            }
            foreach ($all as $d) {
                foreach (($d['cards'] ?? []) as $id => $q) {
                    if (isset($m[$id]) && $q >= 1 && $q <= 4) {
                        $m[$id][$q - 1]++;
                    }
                }
            }
            $out['cards'] = $m;
            break;
        case 'banhxe':
            $sum = array_fill(0, 8, 0);
            $low = array_fill(0, 8, 0);
            foreach ($all as $d) {
                foreach (($d['v'] ?? []) as $i => $x) {
                    if ($i < 8) {
                        $sum[$i] += (int) $x;
                        if ((int) $x <= 4) {
                            $low[$i]++;
                        }
                    }
                }
            }
            $out['avg'] = array_map(fn ($s) => $n ? round($s / $n, 2) : 0, $sum);
            $out['low'] = $low;
            break;
        case 'muctieu':
            $f = array_fill(0, 5, 0);
            $full = 0;
            foreach ($all as $d) {
                $ok = 0;
                foreach (($d['f'] ?? []) as $i => $x) {
                    if ($i < 5 && $x) {
                        $f[$i]++;
                        $ok++;
                    }
                }
                if ($ok === 5) {
                    $full++;
                }
            }
            $out += ['f' => $f, 'full' => $full];
            if ($withText) {
                $out['texts'] = array_values(array_map(fn ($d) => ['text' => $d['text'] ?? '', 'f' => $d['f'] ?? []], $all));
            }
            break;
        case 'camket':
            $c = array_fill(0, 8, 0);
            foreach ($all as $d) {
                $c[(int) ($d['pick'] ?? 0)]++;
            }
            $out['count'] = $c;
            break;
        case 'danhgia':
            $s = array_fill(0, 5, 0);
            foreach ($all as $d) {
                $s[(int) ($d['sao'] ?? 1) - 1]++;
            }
            $out['sao'] = $s;
            if ($withText) {
                $out['texts'] = array_values(array_filter(array_map(fn ($d) => $d['text'] ?? '', $all)));
            }
            break;
    }
    return $out;
}

function ws_questions(string $sess, bool $all = false): array
{
    return ws_run(function (PDO $pdo) use ($sess, $all) {
        $st = $pdo->prepare('SELECT id, text, votes, status, ts FROM ws_questions WHERE sess = ?' . ($all ? '' : ' AND status < 2') . ' ORDER BY status ASC, votes DESC, id DESC LIMIT 200');
        $st->execute([$sess]);
        return array_map(fn ($r) => ['id' => (int) $r['id'], 'text' => $r['text'], 'votes' => (int) $r['votes'], 'status' => (int) $r['status'], 'ts' => (int) $r['ts']], $st->fetchAll());
    });
}

function ws_state(string $sess): array
{
    $row = ws_run(function (PDO $pdo) use ($sess) {
        $st = $pdo->prepare('SELECT data, ts FROM ws_state WHERE sess = ?');
        $st->execute([$sess]);
        return $st->fetch();
    });
    $d = $row ? (json_decode((string) $row['data'], true) ?: []) : [];
    return ['current' => (string) ($d['current'] ?? ''), 'ts' => $row ? (int) $row['ts'] : 0];
}

function ws_check_pin(string $pin): void
{
    global $sqlitePath;
    $ip = ws_ip();
    if (!$sqlitePath) {
        rate_limit_guard('login_ip', $ip);
    }
    $hash = WS_PIN_HASH;
    if (!$sqlitePath) {
        try {
            $hash = (string) (app_config()['app']['workshop_pin_hash'] ?? WS_PIN_HASH);
        } catch (Throwable $e) {
            $hash = WS_PIN_HASH;
        }
    } elseif (getenv('WS_PIN_HASH')) {
        $hash = (string) getenv('WS_PIN_HASH');
    }
    if ($pin === '' || !password_verify($pin, $hash)) {
        if (!$sqlitePath) {
            rate_limit_record('login_ip', $ip);
        }
        api_fail('Mã PIN diễn giả không đúng.');
    }
}

require __DIR__ . '/nc-lib.php';
require __DIR__ . '/qua-lib.php';

// ───────────────────────── Router ─────────────────────────
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    $p = $_GET;
} elseif ($method === 'POST') {
    $p = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($p)) {
        api_fail('Dữ liệu gửi lên không hợp lệ.');
    }
} else {
    http_response_code(405);
    api_fail('Phương thức không được hỗ trợ.');
}

$a = (string) ($p['a'] ?? '');
$sess = ws_sess($p);

try {
    if ($method === 'GET') {
        if ($a === 'ping') {
            api_ok(['ok' => true, 't' => time()]);
        }
        if ($a === 'results') {
            $act = (string) ($p['act'] ?? '');
            if (!in_array($act, WS_ACTS, true)) {
                api_fail('Hoạt động không tồn tại.');
            }
            api_ok(ws_results($sess, $act));
        }
        if ($a === 'questions') {
            $qs = ws_questions($sess);
            $mine = [];
            $c = (string) ($p['c'] ?? '');
            if (preg_match('/^[A-Za-z0-9]{8,32}$/', $c)) {
                $mine = ws_run(function (PDO $pdo) use ($c, $sess) {
                    $st = $pdo->prepare('SELECT v.qid FROM ws_qvotes v JOIN ws_questions q ON q.id = v.qid WHERE v.client = ? AND q.sess = ?');
                    $st->execute([$c, $sess]);
                    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                });
            }
            api_ok(['items' => $qs, 'mine' => $mine]);
        }
        if ($a === 'state') {
            api_ok(ws_state($sess));
        }
        api_fail('Thao tác không tồn tại.');
    }

    // POST
    if ($a === 'vote') {
        $act = (string) ($p['act'] ?? '');
        if (!in_array($act, WS_ACTS, true)) {
            api_fail('Hoạt động không tồn tại.');
        }
        $c = ws_client($p);
        $clean = ws_clean_vote($act, $p['d'] ?? null);
        ws_guard_writes($sess);
        ws_run(function (PDO $pdo) use ($sess, $act, $c, $clean) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM ws_votes WHERE sess = ? AND act = ? AND client = ?')->execute([$sess, $act, $c]);
                $pdo->prepare('INSERT INTO ws_votes (sess, act, client, ip, data, ts) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$sess, $act, $c, ws_ip(), json_encode($clean, JSON_UNESCAPED_UNICODE), time()]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        });
        api_ok(['saved' => true]);
    }

    if ($a === 'ask') {
        $c = ws_client($p);
        $text = ws_text((string) ($p['text'] ?? ''), 300);
        if (mb_strlen($text) < 5) {
            api_fail('Câu hỏi hơi ngắn — viết rõ hơn một chút nhé.');
        }
        ws_guard_writes($sess);
        $recent = (int) ws_run(function (PDO $pdo) use ($sess, $c) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM ws_questions WHERE sess = ? AND client = ? AND ts > ?');
            $st->execute([$sess, $c, time() - 600]);
            return $st->fetchColumn();
        });
        if ($recent >= WS_ASK_PER_CLIENT) {
            api_fail('Bạn đã gửi nhiều câu hỏi rồi — đợi diễn giả trả lời bớt nhé.');
        }
        ws_run(function (PDO $pdo) use ($sess, $c, $text) {
            $pdo->prepare('INSERT INTO ws_questions (sess, client, ip, text, votes, status, ts) VALUES (?, ?, ?, ?, 0, 0, ?)')
                ->execute([$sess, $c, ws_ip(), $text, time()]);
        });
        api_ok(['saved' => true]);
    }

    if ($a === 'upq') {
        $c = ws_client($p);
        $qid = ws_int($p['qid'] ?? null, 1, PHP_INT_MAX);
        if ($qid === null) {
            api_fail('Câu hỏi không tồn tại.');
        }
        ws_guard_writes($sess);
        $added = ws_run(function (PDO $pdo) use ($qid, $c, $sess) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM ws_questions WHERE id = ? AND sess = ? AND status < 2');
            $st->execute([$qid, $sess]);
            if (!(int) $st->fetchColumn()) {
                return false;
            }
            try {
                $pdo->prepare('INSERT INTO ws_qvotes (qid, client) VALUES (?, ?)')->execute([$qid, $c]);
            } catch (PDOException $e) {
                return false; // đã bình chọn rồi (trùng khoá chính)
            }
            $pdo->prepare('UPDATE ws_questions SET votes = votes + 1 WHERE id = ?')->execute([$qid]);
            return true;
        });
        api_ok(['added' => (bool) $added]);
    }

    if ($a === 'admin') {
        ws_check_pin((string) ($p['pin'] ?? ''));
        $op = (string) ($p['op'] ?? '');
        if ($op === 'check') {
            ws_schema();
            api_ok(['ok' => true]);
        }
        if ($op === 'state') {
            $cur = (string) ($p['current'] ?? '');
            if ($cur !== '' && !in_array($cur, array_merge(WS_ACTS, ['hoi']), true)) {
                api_fail('Hoạt động không tồn tại.');
            }
            ws_run(function (PDO $pdo) use ($sess, $cur) {
                $pdo->prepare('DELETE FROM ws_state WHERE sess = ?')->execute([$sess]);
                $pdo->prepare('INSERT INTO ws_state (sess, data, ts) VALUES (?, ?, ?)')->execute([$sess, json_encode(['current' => $cur]), time()]);
            });
            api_ok(ws_state($sess));
        }
        if ($op === 'details') {
            $act = (string) ($p['act'] ?? '');
            if (!in_array($act, WS_ACTS, true)) {
                api_fail('Hoạt động không tồn tại.');
            }
            api_ok(ws_results($sess, $act, true));
        }
        if ($op === 'questions') {
            api_ok(['items' => ws_questions($sess, true)]);
        }
        if ($op === 'qstatus') {
            $qid = ws_int($p['qid'] ?? null, 1, PHP_INT_MAX);
            $status = ws_int($p['status'] ?? null, 0, 2);
            if ($qid === null || $status === null) {
                api_fail('Tham số không hợp lệ.');
            }
            ws_run(fn (PDO $pdo) => $pdo->prepare('UPDATE ws_questions SET status = ? WHERE id = ? AND sess = ?')->execute([$status, $qid, $sess]));
            api_ok(['ok' => true]);
        }
        if ($op === 'reset') {
            $what = (string) ($p['what'] ?? '');
            ws_run(function (PDO $pdo) use ($sess, $what) {
                if ($what === 'hoi' || $what === 'all') {
                    $pdo->prepare('DELETE FROM ws_qvotes WHERE qid IN (SELECT id FROM ws_questions WHERE sess = ?)')->execute([$sess]);
                    $pdo->prepare('DELETE FROM ws_questions WHERE sess = ?')->execute([$sess]);
                }
                if ($what === 'all') {
                    // "Tất cả" = dữ liệu hoạt động workshop; KHÔNG xóa phiếu nghiên cứu nc0–nc3.
                    $pdo->prepare("DELETE FROM ws_votes WHERE sess = ? AND act NOT IN ('nc0','nc1','nc2','nc3')")->execute([$sess]);
                    $pdo->prepare('DELETE FROM ws_state WHERE sess = ?')->execute([ws_qua_key($sess)]); // kết quả quay số
                } elseif ($what === 'nghiencuu') {
                    $pdo->prepare("DELETE FROM ws_votes WHERE sess = ? AND act IN ('nc0','nc1','nc2','nc3')")->execute([$sess]);
                } elseif (in_array($what, WS_ACTS, true)) {
                    $pdo->prepare('DELETE FROM ws_votes WHERE sess = ? AND act = ?')->execute([$sess, $what]);
                }
            });
            api_ok(['ok' => true]);
        }
        if ($op === 'lucky_draw' || $op === 'lucky_info') {
            $min = ws_int($p['min'] ?? 3, 1, count(WS_QUA_ACTS)) ?? 3;
            if ($op === 'lucky_info') {
                api_ok(ws_qua_info($sess, $min));
            }
            $n = ws_int($p['n'] ?? 3, 1, 10) ?? 3;
            api_ok(ws_qua_draw($sess, $n, $min));
        }
        if ($op === 'lucky_reset') {
            ws_qua_save($sess, []);
            api_ok(['ok' => true]);
        }
        if ($op === 'smart_rank') {
            $goals = ws_qua_goals($sess);
            if ((int) ($p['ai'] ?? 0) === 1) {
                api_ok(ws_qua_smart_ai($goals, 5));
            }
            $coGemini = function_exists('app_config') && trim((string) ((app_config()['gemini'] ?? [])['api_key'] ?? '')) !== '';
            api_ok(['items' => array_slice($goals, 0, 10), 'xet' => count($goals), 'co_gemini' => $coGemini]);
        }
        if ($op === 'research_csv') {
            api_ok(ws_nc_csv($sess));
        }
        if ($op === 'export') {
            $res = [];
            foreach (WS_ACTS as $act) {
                $res[$act] = ws_results($sess, $act, true);
            }
            api_ok(['session' => $sess, 'exported_at' => date('c'), 'results' => $res, 'questions' => ws_questions($sess, true)]);
        }
        api_fail('Thao tác quản trị không tồn tại.');
    }

    api_fail('Thao tác không tồn tại.');
} catch (PDOException | Error $e) {
    error_log('[workshop] ' . $a . ': ' . get_class($e) . ': ' . $e->getMessage());
    api_fail('Lỗi máy chủ. Vui lòng thử lại sau.');
}
