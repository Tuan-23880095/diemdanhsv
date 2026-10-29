<?php
declare(strict_types=1);

/**
 * bdvh/phong-thi-api.php — PHÒNG THI TRỰC TIẾP CÓ GIÁM SÁT (thi tại lớp bằng điện thoại/laptop).
 * Được api.php nạp ngay trước bộ định tuyến; xử lý mọi action bắt đầu bằng "room_".
 * Không gọi trực tiếp qua web (BDVH_API chưa được định nghĩa → dừng).
 *
 * Luồng: GV tạo phòng từ một đề (đề công khai hoặc ĐỀ KÍN nhập từ Excel) → Mở phòng (QR + 5 phút chuẩn bị)
 *        → HS đăng nhập tài khoản bdvh, quét QR, bấm Sẵn sàng → làm bài có giám sát (rời trang, chuyển app,
 *        chia màn hình, toàn màn hình, copy/dán, thiết bị thứ hai…) → vượt ngưỡng bị KHOÁ, GV nhập PIN mở
 *        → nộp / hết giờ tự nộp → ghi bdvh_submissions + nhận xét như bài thường → GV công bố thì HS xem
 *        ket-qua.html (đáp án + lời giải).
 *
 * Chặn lộ đáp án: khi đề đang nằm trong phòng LOBBY/RUNNING, get_exam/submit_exam thường của đề đó bị khoá;
 * đề kín (status DRAFT) không mở được ngoài phòng thi; ket-qua của bài trong phòng chỉ mở khi GV công bố.
 */

if (!defined('BDVH_API')) {
    http_response_code(404);
    exit;
}

const ROOM_DEFAULT_SETTINGS = [
    'shuffleQuestions' => true, 'shuffleOptions' => true, 'allowBack' => true,
    'maxViolations' => 3, 'onLimit' => 'lock', 'blurGraceSec' => 3, 'requireFullscreen' => true,
    'lateJoinMinutes' => 10, 'showScore' => true, 'watermark' => true,
];
const ROOM_COUNTED = [
    'hidden' => 'Rời trang (chuyển tab / ứng dụng khác / tắt màn hình)', 'blur' => 'Cửa sổ mất tiêu điểm quá lâu',
    'fs_exit' => 'Thoát chế độ toàn màn hình', 'split' => 'Thu nhỏ / chia đôi màn hình / cửa sổ nổi',
    'copy' => 'Sao chép nội dung', 'cut' => 'Cắt nội dung', 'paste' => 'Dán nội dung', 'devtools' => 'Mở công cụ nhà phát triển',
    'printscreen' => 'Chụp màn hình (PrintScreen)', 'reload' => 'Tải lại / mở lại trang khi đang làm bài',
    'second_device' => 'Đăng nhập tài khoản này từ thiết bị khác',
];
const ROOM_LOGGED = ['contextmenu', 'shortcut', 'offline', 'online', 'back_visible', 'focus_back'];

/* ============================ Hạ tầng ============================ */

class RoomError extends RuntimeException
{
}
function rfail(string $m): void { throw new RoomError($m); }
function rok($data = null): void { json_out(['status' => 'success', 'data' => $data]); }
function rnow(): string { return date('Y-m-d H:i:s'); }
function rts(?string $s): ?int { if (!$s) { return null; } $t = strtotime($s); return $t === false ? null : $t; }
function rdt(int $t): string { return date('Y-m-d H:i:s', $t); }
function rjd(?string $s, $def = []) { if ($s === null || $s === '') { return $def; } $v = json_decode($s, true); return $v === null ? $def : $v; }
function rje($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE); }

function rq(string $sql, array $p = []): PDOStatement
{
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st;
}
function rq_one(string $sql, array $p = []): ?array { $r = rq($sql, $p)->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
function rq_all(string $sql, array $p = []): array { return rq($sql, $p)->fetchAll(PDO::FETCH_ASSOC); }
function rq_val(string $sql, array $p = []) { return rq($sql, $p)->fetchColumn(); }
function r_sqlite(): bool { global $pdo; return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'; }
function r_forupdate(): string { return r_sqlite() ? '' : ' FOR UPDATE'; }
function rtx(callable $fn)
{
    global $pdo;
    if (r_sqlite()) { $pdo->exec('BEGIN IMMEDIATE'); } else { $pdo->beginTransaction(); }
    try {
        $r = $fn();
        if (r_sqlite()) { $pdo->exec('COMMIT'); } else { $pdo->commit(); }
        return $r;
    } catch (Throwable $e) {
        try { if (r_sqlite()) { $pdo->exec('ROLLBACK'); } elseif ($pdo->inTransaction()) { $pdo->rollBack(); } } catch (Throwable $x) {}
        throw $e;
    }
}
function r_code(int $len): string
{
    $a = 'ACDEFGHJKMNPQRTUVWXY34679';
    $s = '';
    for ($i = 0; $i < $len; $i++) { $s .= $a[random_int(0, strlen($a) - 1)]; }
    return $s;
}
function r_is_teacher(): bool { return !empty($_SESSION['bdvh_teacher_auth']); }
function r_teacher(): void { if (!r_is_teacher()) { rfail('Yêu cầu đăng nhập giảng viên (trang thay-tuan.html).'); } }

function room_ensure_tables(): void
{
    global $pdo;
    try { $pdo->query('SELECT 1 FROM bdvh_room_events LIMIT 1'); return; } catch (Throwable $e) {}
    if (r_sqlite()) {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS bdvh_rooms (room_id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL, exam_id INTEGER NOT NULL,
            title TEXT NOT NULL, label TEXT, status TEXT NOT NULL DEFAULT 'DRAFT', prep_minutes INTEGER NOT NULL DEFAULT 5,
            duration_minutes INTEGER NOT NULL DEFAULT 45, settings_json TEXT, pin TEXT NOT NULL, snapshot_json TEXT,
            opened_at TEXT, start_at TEXT, started_at TEXT, ends_at TEXT, closed_at TEXT, results_published INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL);
          CREATE TABLE IF NOT EXISTS bdvh_room_attempts (attempt_id INTEGER PRIMARY KEY AUTOINCREMENT, room_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
            full_name TEXT NOT NULL, phone TEXT, seat_no INTEGER NOT NULL DEFAULT 0, token_hash TEXT NOT NULL, device_id TEXT, device_info TEXT, ip TEXT,
            status TEXT NOT NULL DEFAULT 'JOINED', layout_json TEXT, answers_json TEXT, violations INTEGER NOT NULL DEFAULT 0,
            unlocks INTEGER NOT NULL DEFAULT 0, pin_fails INTEGER NOT NULL DEFAULT 0, lock_reason TEXT, extra_minutes INTEGER NOT NULL DEFAULT 0,
            allow_rejoin INTEGER NOT NULL DEFAULT 0, joined_at TEXT, ready_at TEXT, last_seen_at TEXT, submitted_at TEXT, submit_reason TEXT,
            correct_count INTEGER, total_questions INTEGER, score REAL, detail_json TEXT, sub_id INTEGER, UNIQUE (room_id, user_id));
          CREATE TABLE IF NOT EXISTS bdvh_room_events (event_id INTEGER PRIMARY KEY AUTOINCREMENT, room_id INTEGER NOT NULL, attempt_id INTEGER,
            type TEXT NOT NULL, detail TEXT, counted INTEGER NOT NULL DEFAULT 0, client_at TEXT, created_at TEXT NOT NULL);
        ");
    } else {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS bdvh_rooms (room_id INT AUTO_INCREMENT PRIMARY KEY, code CHAR(6) NOT NULL, exam_id INT NOT NULL,
            title VARCHAR(255) NOT NULL, label VARCHAR(190) NULL, status VARCHAR(10) NOT NULL DEFAULT 'DRAFT', prep_minutes INT NOT NULL DEFAULT 5,
            duration_minutes INT NOT NULL DEFAULT 45, settings_json TEXT NULL, pin VARCHAR(8) NOT NULL, snapshot_json LONGTEXT NULL,
            opened_at DATETIME NULL, start_at DATETIME NULL, started_at DATETIME NULL, ends_at DATETIME NULL, closed_at DATETIME NULL,
            results_published TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, KEY idx_room_code (code, status), KEY idx_room_exam (exam_id, status)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
          CREATE TABLE IF NOT EXISTS bdvh_room_attempts (attempt_id INT AUTO_INCREMENT PRIMARY KEY, room_id INT NOT NULL, user_id INT NOT NULL,
            full_name VARCHAR(190) NOT NULL, phone VARCHAR(30) NULL, seat_no INT NOT NULL DEFAULT 0, token_hash CHAR(64) NOT NULL, device_id VARCHAR(64) NULL,
            device_info VARCHAR(200) NULL, ip VARCHAR(45) NULL, status VARCHAR(10) NOT NULL DEFAULT 'JOINED', layout_json TEXT NULL, answers_json MEDIUMTEXT NULL,
            violations INT NOT NULL DEFAULT 0, unlocks INT NOT NULL DEFAULT 0, pin_fails INT NOT NULL DEFAULT 0, lock_reason VARCHAR(190) NULL,
            extra_minutes INT NOT NULL DEFAULT 0, allow_rejoin TINYINT(1) NOT NULL DEFAULT 0, joined_at DATETIME NULL, ready_at DATETIME NULL,
            last_seen_at DATETIME NULL, submitted_at DATETIME NULL, submit_reason VARCHAR(20) NULL, correct_count INT NULL, total_questions INT NULL,
            score DECIMAL(4,2) NULL, detail_json MEDIUMTEXT NULL, sub_id INT NULL,
            UNIQUE KEY uq_room_user (room_id, user_id), KEY idx_att_token (token_hash), KEY idx_att_sub (sub_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
          CREATE TABLE IF NOT EXISTS bdvh_room_events (event_id BIGINT AUTO_INCREMENT PRIMARY KEY, room_id INT NOT NULL, attempt_id INT NULL,
            type VARCHAR(30) NOT NULL, detail VARCHAR(255) NULL, counted TINYINT(1) NOT NULL DEFAULT 0, client_at VARCHAR(30) NULL,
            created_at DATETIME NOT NULL, KEY idx_ev_room (room_id, event_id), KEY idx_ev_att (attempt_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }
}

/* ===================== Bảo vệ đáp án cho api.php ===================== */

/** Đề đang dùng trong phòng thi chưa đóng? (gọi từ get_exam / submit_exam thường) */
function room_exam_in_use(int $examId): bool
{
    try {
        return (bool) rq_val("SELECT 1 FROM bdvh_rooms WHERE exam_id = ? AND status IN ('LOBBY','RUNNING') LIMIT 1", [$examId]);
    } catch (Throwable $e) {
        return false;
    }
}
/** Chặn get_exam/submit_exam thường với đề kín (DRAFT) hoặc đề đang thi trong phòng. */
function room_guard_exam(?array $exam): void
{
    if (!$exam || r_is_teacher()) {
        return;
    }
    if (($exam['status'] ?? 'ACTIVE') === 'DRAFT') {
        json_out(['status' => 'error', 'message' => 'Đề này chỉ làm được trong phòng thi trực tiếp của giáo viên.'], 403);
    }
    if (room_exam_in_use((int) $exam['exam_id'])) {
        json_out(['status' => 'error', 'message' => 'Đề này đang được dùng trong một phòng thi trực tiếp — tạm thời không mở được ở chế độ luyện tập.'], 403);
    }
}
/** Chặn xem lời giải của bài làm trong phòng thi khi GV chưa công bố. */
function room_guard_submission(int $subId): void
{
    if (r_is_teacher()) {
        return;
    }
    try {
        $r = rq_one('SELECT r.results_published, r.status FROM bdvh_room_attempts a JOIN bdvh_rooms r ON r.room_id = a.room_id WHERE a.sub_id = ? LIMIT 1', [$subId]);
    } catch (Throwable $e) {
        return;
    }
    if ($r && !((int) $r['results_published'] && $r['status'] === 'CLOSED')) {
        json_out(['status' => 'error', 'message' => 'Bài làm trong phòng thi — đáp án và lời giải sẽ xem được khi giáo viên công bố.'], 403);
    }
}

/* ============================ Đề thi ============================ */

function room_settings(array $r): array
{
    $s = array_merge(ROOM_DEFAULT_SETTINGS, rjd($r['settings_json'] ?? null));
    $s['maxViolations'] = max(1, min(20, (int) $s['maxViolations']));
    $s['blurGraceSec'] = max(0, min(30, (int) $s['blurGraceSec']));
    $s['lateJoinMinutes'] = max(0, min(120, (int) $s['lateJoinMinutes']));
    $s['onLimit'] = $s['onLimit'] === 'submit' ? 'submit' : 'lock';
    foreach (['shuffleQuestions', 'shuffleOptions', 'allowBack', 'requireFullscreen', 'showScore', 'watermark'] as $k) {
        $s[$k] = (bool) $s[$k];
    }
    return $s;
}

function exam_questions(array $exam): array
{
    $qs = rjd($exam['questions_json'] ?? '[]');
    $out = [];
    foreach ($qs as $i => $q) {
        $out[] = ['idx' => (int) $i, 'q' => (string) ($q['q'] ?? ''), 'type' => $q['type'] ?? 'choice', 'section' => (string) ($q['section'] ?? ''),
            'opts' => array_values((array) ($q['opts'] ?? [])), 'items' => array_values((array) ($q['items'] ?? [])), 'ans' => $q['ans'] ?? null,
            'exp' => (string) ($q['exp'] ?? '')];
    }
    return $out;
}

function action_room_exams(): void
{
    r_teacher();
    $rows = rq_all('SELECT exam_id, exam_code, grade, subject, title, time_minutes, status, questions_json FROM bdvh_exams ORDER BY status DESC, exam_id DESC');
    rok(array_map(function ($e) {
        $qs = rjd($e['questions_json']);
        unset($e['questions_json']);
        $e['question_count'] = count($qs);
        $e['types'] = array_count_values(array_map(fn($q) => $q['type'] ?? 'choice', $qs));
        return $e;
    }, $rows));
}

/** Xem trước đề (GV) — kèm đáp án. */
function action_room_exam_preview(array $in): void
{
    r_teacher();
    $e = rq_one('SELECT * FROM bdvh_exams WHERE exam_id = ?', [(int) ($in['exam_id'] ?? 0)]);
    if (!$e) { rfail('Không tìm thấy đề.'); }
    rok(['exam_id' => (int) $e['exam_id'], 'title' => $e['title'], 'status' => $e['status'], 'time_minutes' => (int) $e['time_minutes'], 'questions' => exam_questions($e)]);
}

/**
 * Nhập ĐỀ KÍN (status DRAFT — không hiện ở trang luyện tập, chỉ làm trong phòng thi).
 * rows: [{type: choice|true_false|short, q, opts[], ans, exp, section}] — client đã tách bảng Excel/CSV.
 */
function action_room_import_exam(array $in): void
{
    r_teacher();
    global $nowSql;
    $title = trim((string) ($in['title'] ?? ''));
    if ($title === '') { rfail('Cần nhập tên đề.'); }
    $grade = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($in['grade'] ?? 'L10'))) ?: 'L10';
    $subject = preg_replace('/[^A-Z0-9_]/', '', strtoupper((string) ($in['subject'] ?? 'TOAN'))) ?: 'TOAN';
    $minutes = max(1, min(300, (int) ($in['time_minutes'] ?? 45)));
    $rows = (array) ($in['rows'] ?? []);
    if (!$rows || count($rows) > 300) { rfail('Đề cần từ 1 đến 300 câu.'); }
    $qs = [];
    $errors = [];
    foreach ($rows as $i => $r) {
        try { $qs[] = room_norm_question((array) $r); } catch (RoomError $e) { $errors[] = ['row' => $i + 1, 'message' => $e->getMessage()]; }
    }
    if ($errors || !empty($in['dryRun'])) {
        rok(['valid' => count($qs), 'errors' => $errors, 'exam_id' => null]);
    }
    $code = 'KIN_' . date('ymd') . '_' . r_code(4);
    rq("INSERT INTO bdvh_exams (exam_code, grade, subject, title, description, time_minutes, questions_json, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'DRAFT', $nowSql)",
        [$code, $grade, $subject, $title, 'Đề kín dùng cho phòng thi trực tiếp', $minutes, rje($qs)]);
    global $pdo;
    rok(['valid' => count($qs), 'errors' => [], 'exam_id' => (int) $pdo->lastInsertId(), 'exam_code' => $code]);
}

function room_norm_question(array $r): array
{
    $type = in_array($r['type'] ?? '', ['choice', 'true_false', 'short'], true) ? $r['type'] : 'choice';
    $q = trim((string) ($r['q'] ?? ''));
    if ($q === '') { rfail('Thiếu nội dung câu hỏi.'); }
    $opts = array_values(array_filter(array_map(fn($x) => trim((string) $x), (array) ($r['opts'] ?? [])), fn($x) => $x !== ''));
    $ansRaw = trim((string) ($r['ans'] ?? ''));
    $out = ['q' => $q, 'type' => $type, 'exp' => trim((string) ($r['exp'] ?? ''))];
    if (trim((string) ($r['section'] ?? '')) !== '') { $out['section'] = trim((string) $r['section']); }
    if ($type === 'choice') {
        if (count($opts) < 2 || count($opts) > 6) { rfail('Câu trắc nghiệm cần 2–6 phương án.'); }
        $k = strpos('ABCDEF', strtoupper($ansRaw));
        if (strlen($ansRaw) !== 1 || $k === false || $k >= count($opts)) { rfail('Đáp án phải là một chữ cái ứng với phương án có nội dung.'); }
        $out['opts'] = $opts;
        $out['ans'] = $k;
    } elseif ($type === 'true_false') {
        if (count($opts) < 2 || count($opts) > 6) { rfail('Câu Đúng/Sai cần 2–6 ý (đặt ở các cột A, B, C, D…).'); }
        $marks = preg_split('/[\s,;]+/u', mb_strtoupper($ansRaw)) ?: [];
        $ans = [];
        foreach ($marks as $m) {
            if (in_array($m, ['Đ', 'D', 'T', '1', 'ĐÚNG', 'DUNG', 'TRUE'], true)) { $ans[] = 1; }
            elseif (in_array($m, ['S', 'F', '0', 'SAI', 'FALSE'], true)) { $ans[] = 0; }
        }
        if (count($ans) !== count($opts)) { rfail('Đáp án Đúng/Sai cần đủ ' . count($opts) . ' ký hiệu, ví dụ: Đ,S,Đ,S.'); }
        $out['items'] = $opts;
        $out['ans'] = $ans;
    } else {
        if ($ansRaw === '') { rfail('Câu trả lời ngắn cần đáp án.'); }
        $out['ans'] = $ansRaw;
    }
    return $out;
}

/* ============================ Phòng thi (GV) ============================ */

function room_load(int $id): array
{
    $r = rq_one('SELECT * FROM bdvh_rooms WHERE room_id = ?', [$id]);
    if (!$r) { rfail('Không tìm thấy phòng thi.'); }
    return $r;
}
function room_unique_code(): string
{
    for ($i = 0; $i < 20; $i++) {
        $c = r_code(6);
        if (!rq_val("SELECT 1 FROM bdvh_rooms WHERE code = ? AND status <> 'CLOSED'", [$c])) { return $c; }
    }
    rfail('Không sinh được mã phòng, thử lại.');
}

function action_room_list(): void
{
    r_teacher();
    $rows = rq_all('SELECT r.room_id, r.code, r.exam_id, r.title, r.label, r.status, r.prep_minutes, r.duration_minutes, r.opened_at, r.started_at, r.closed_at, r.created_at, ' .
        'r.results_published, e.title AS exam_title, e.status AS exam_status, (SELECT COUNT(*) FROM bdvh_room_attempts a WHERE a.room_id = r.room_id) AS attempts ' .
        'FROM bdvh_rooms r LEFT JOIN bdvh_exams e ON e.exam_id = r.exam_id ORDER BY r.room_id DESC LIMIT 100');
    foreach ($rows as &$r) {
        if (in_array($r['status'], ['LOBBY', 'RUNNING'], true)) {
            $full = room_load((int) $r['room_id']);
            room_tick($full);
            $r['status'] = $full['status'];
        }
    }
    rok($rows);
}

function action_room_save(array $in): void
{
    r_teacher();
    $examId = (int) ($in['exam_id'] ?? 0);
    $exam = rq_one('SELECT * FROM bdvh_exams WHERE exam_id = ?', [$examId]);
    if (!$exam) { rfail('Chưa chọn đề thi.'); }
    $title = trim((string) ($in['title'] ?? '')) ?: $exam['title'];
    $label = trim((string) ($in['label'] ?? ''));
    $dur = max(1, min(300, (int) ($in['duration_minutes'] ?? $exam['time_minutes'])));
    $prep = max(0, min(30, (int) ($in['prep_minutes'] ?? 5)));
    $s = [];
    foreach (ROOM_DEFAULT_SETTINGS as $k => $d) {
        $v = $in['settings'][$k] ?? $d;
        $s[$k] = is_bool($d) ? (bool) $v : (is_int($d) ? (int) $v : (string) $v);
    }
    $s = room_settings(['settings_json' => rje($s)]);
    $id = (int) ($in['room_id'] ?? 0);
    if ($id) {
        $r = room_load($id);
        if ($r['status'] === 'DRAFT') {
            rq('UPDATE bdvh_rooms SET exam_id = ?, title = ?, label = ?, duration_minutes = ?, prep_minutes = ?, settings_json = ? WHERE room_id = ?',
                [$examId, $title, $label, $dur, $prep, rje($s), $id]);
        } else {
            rq('UPDATE bdvh_rooms SET title = ?, label = ?, settings_json = ? WHERE room_id = ?', [$title, $label, rje($s), $id]);
        }
    } else {
        rq("INSERT INTO bdvh_rooms (code, exam_id, title, label, status, prep_minutes, duration_minutes, settings_json, pin, created_at) VALUES (?, ?, ?, ?, 'DRAFT', ?, ?, ?, ?, ?)",
            [room_unique_code(), $examId, $title, $label, $prep, $dur, rje($s), sprintf('%06d', random_int(0, 999999)), rnow()]);
        global $pdo;
        $id = (int) $pdo->lastInsertId();
    }
    rok(['room_id' => $id]);
}

function action_room_delete(array $in): void
{
    r_teacher();
    $r = room_load((int) ($in['room_id'] ?? 0));
    if ((int) rq_val('SELECT COUNT(*) FROM bdvh_room_attempts WHERE room_id = ?', [$r['room_id']]) > 0) {
        rfail('Phòng đã có học sinh làm bài — không xoá được.');
    }
    rq('DELETE FROM bdvh_room_events WHERE room_id = ?', [$r['room_id']]);
    rq('DELETE FROM bdvh_rooms WHERE room_id = ?', [$r['room_id']]);
    rok(['ok' => true]);
}

function action_room_open(array $in): void
{
    r_teacher();
    $r = room_load((int) ($in['room_id'] ?? 0));
    if ($r['status'] !== 'DRAFT') { rfail('Phòng đã được mở trước đó.'); }
    $exam = rq_one('SELECT * FROM bdvh_exams WHERE exam_id = ?', [$r['exam_id']]);
    $qs = $exam ? exam_questions($exam) : [];
    if (!$qs) { rfail('Đề thi không có câu hỏi.'); }
    $n = time();
    rq("UPDATE bdvh_rooms SET status = 'LOBBY', code = ?, snapshot_json = ?, opened_at = ?, start_at = ? WHERE room_id = ?",
        [room_unique_code(), rje(['exam_title' => $exam['title'], 'questions' => $qs]), rdt($n), rdt($n + 60 * (int) $r['prep_minutes']), $r['room_id']]);
    rok(['ok' => true]);
}

function action_room_start(array $in): void
{
    r_teacher();
    $r = room_load((int) ($in['room_id'] ?? 0));
    if ($r['status'] !== 'LOBBY') { rfail('Chỉ bắt đầu được khi phòng đang chuẩn bị.'); }
    $n = time();
    rq("UPDATE bdvh_rooms SET status = 'RUNNING', start_at = ?, started_at = ?, ends_at = ? WHERE room_id = ?",
        [rdt($n), rdt($n), rdt($n + 60 * (int) $r['duration_minutes']), $r['room_id']]);
    room_log((int) $r['room_id'], null, 'gv_bat_dau', 'Giáo viên bắt đầu sớm', 0);
    rok(['ok' => true]);
}

function action_room_add_time(array $in): void
{
    r_teacher();
    $r = room_load((int) ($in['room_id'] ?? 0));
    $m = (int) ($in['minutes'] ?? 0);
    if ($m === 0 || abs($m) > 120) { rfail('Số phút không hợp lệ.'); }
    $att = (int) ($in['attempt_id'] ?? 0);
    if ($att) {
        $a = rq_one('SELECT extra_minutes FROM bdvh_room_attempts WHERE attempt_id = ? AND room_id = ?', [$att, $r['room_id']]);
        if (!$a) { rfail('Không tìm thấy bài làm.'); }
        rq('UPDATE bdvh_room_attempts SET extra_minutes = ? WHERE attempt_id = ?', [max(0, min(180, (int) $a['extra_minutes'] + $m)), $att]);
        room_log((int) $r['room_id'], $att, 'gv_cong_gio', ($m > 0 ? '+' : '') . $m . ' phút', 0);
    } elseif ($r['status'] === 'LOBBY') {
        rq('UPDATE bdvh_rooms SET start_at = ? WHERE room_id = ?', [rdt(max(time(), rts($r['start_at']) + 60 * $m)), $r['room_id']]);
    } elseif ($r['status'] === 'RUNNING') {
        rq('UPDATE bdvh_rooms SET ends_at = ? WHERE room_id = ?', [rdt(max(time() + 60, rts($r['ends_at']) + 60 * $m)), $r['room_id']]);
        room_log((int) $r['room_id'], null, 'gv_cong_gio', 'Cả phòng ' . ($m > 0 ? '+' : '') . $m . ' phút', 0);
    } else {
        rfail('Phòng chưa mở hoặc đã đóng.');
    }
    rok(['ok' => true]);
}

function action_room_close(array $in): void
{
    r_teacher();
    $r = room_load((int) ($in['room_id'] ?? 0));
    if (!in_array($r['status'], ['LOBBY', 'RUNNING'], true)) { rfail('Phòng không ở trạng thái đang mở.'); }
    room_close($r, 'gv_thu');
    room_log((int) $r['room_id'], null, 'gv_thu_bai', 'Giáo viên thu bài, đóng phòng', 0);
    rok(['ok' => true]);
}

function action_room_publish(array $in): void
{
    r_teacher();
    $r = room_load((int) ($in['room_id'] ?? 0));
    $on = !empty($in['published']) ? 1 : 0;
    if ($on && $r['status'] !== 'CLOSED') { rfail('Chỉ công bố đáp án sau khi đã thu bài.'); }
    rq('UPDATE bdvh_rooms SET results_published = ? WHERE room_id = ?', [$on, $r['room_id']]);
    rok(['published' => $on]);
}

/** Chuyển trạng thái lười: hết giờ chuẩn bị → RUNNING; quá hạn → chấm bài quá giờ → đóng phòng. */
function room_tick(array &$r): void
{
    $now = time();
    if ($r['status'] === 'LOBBY' && $r['start_at'] && $now >= rts($r['start_at'])) {
        $s = rts($r['start_at']);
        rq("UPDATE bdvh_rooms SET status = 'RUNNING', started_at = ?, ends_at = ? WHERE room_id = ? AND status = 'LOBBY'",
            [rdt($s), rdt($s + 60 * (int) $r['duration_minutes']), $r['room_id']]);
        $r = room_load((int) $r['room_id']);
    }
    if ($r['status'] === 'RUNNING' && $r['ends_at'] && $now > rts($r['ends_at'])) {
        foreach (rq_all("SELECT * FROM bdvh_room_attempts WHERE room_id = ? AND status <> 'SUBMITTED'", [$r['room_id']]) as $a) {
            if ($now > room_deadline($r, $a) + 30) {
                room_submit($r, $a, 'het_gio');
            }
        }
        $maxExtra = (int) rq_val("SELECT COALESCE(MAX(extra_minutes),0) FROM bdvh_room_attempts WHERE room_id = ? AND status <> 'SUBMITTED'", [$r['room_id']]);
        if ($now > rts($r['ends_at']) + 60 * $maxExtra + 120) {
            room_close($r, 'het_gio');
            $r['status'] = 'CLOSED';
        }
    }
}
function room_deadline(array $r, array $a): ?int
{
    return $r['ends_at'] ? rts($r['ends_at']) + 60 * (int) $a['extra_minutes'] : null;
}
function room_close(array $r, string $reason): void
{
    foreach (rq_all("SELECT * FROM bdvh_room_attempts WHERE room_id = ? AND status <> 'SUBMITTED'", [$r['room_id']]) as $a) {
        room_submit($r, $a, $reason);
    }
    rq("UPDATE bdvh_rooms SET status = 'CLOSED', closed_at = ? WHERE room_id = ?", [rnow(), $r['room_id']]);
}

function room_log(int $roomId, ?int $attId, string $type, string $detail, int $counted, ?string $clientAt = null): void
{
    rq('INSERT INTO bdvh_room_events (room_id, attempt_id, type, detail, counted, client_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$roomId, $attId, mb_substr($type, 0, 30), mb_substr($detail, 0, 255), $counted, $clientAt ? mb_substr($clientAt, 0, 30) : null, rnow()]);
}

function action_room_monitor(array $in): void
{
    r_teacher();
    $r = room_load((int) ($in['room_id'] ?? 0));
    room_tick($r);
    $snap = rjd($r['snapshot_json']);
    $total = count($snap['questions'] ?? []);
    $atts = rq_all('SELECT attempt_id, user_id, full_name, phone, seat_no, status, violations, unlocks, lock_reason, extra_minutes, allow_rejoin, device_info, ip, ' .
        'joined_at, ready_at, last_seen_at, submitted_at, submit_reason, score, correct_count, total_questions, answers_json FROM bdvh_room_attempts WHERE room_id = ? ORDER BY seat_no', [$r['room_id']]);
    $now = time();
    foreach ($atts as &$a) {
        $ans = rjd($a['answers_json']);
        $a['answered'] = count(array_filter($ans, fn($v) => $v !== null && $v !== '' && $v !== []));
        unset($a['answers_json']);
        $a['seconds_since_seen'] = $a['last_seen_at'] ? $now - rts($a['last_seen_at']) : null;
    }
    unset($a);
    $since = (int) ($in['since_event'] ?? 0);
    $events = rq_all('SELECT ev.event_id, ev.attempt_id, ev.type, ev.detail, ev.counted, ev.created_at, a.full_name, a.seat_no FROM bdvh_room_events ev ' .
        'LEFT JOIN bdvh_room_attempts a ON a.attempt_id = ev.attempt_id WHERE ev.room_id = ? AND ev.event_id > ? ORDER BY ev.event_id DESC LIMIT 200', [$r['room_id'], $since]);
    $exam = rq_one('SELECT title, status FROM bdvh_exams WHERE exam_id = ?', [$r['exam_id']]);
    rok([
        'room' => ['room_id' => (int) $r['room_id'], 'code' => $r['code'], 'title' => $r['title'], 'label' => $r['label'], 'status' => $r['status'],
            'pin' => $r['pin'], 'prep_minutes' => (int) $r['prep_minutes'], 'duration_minutes' => (int) $r['duration_minutes'], 'opened_at' => $r['opened_at'],
            'start_at' => $r['start_at'], 'started_at' => $r['started_at'], 'ends_at' => $r['ends_at'], 'closed_at' => $r['closed_at'],
            'results_published' => (int) $r['results_published'], 'settings' => room_settings($r), 'question_count' => $total ?: (int) count(exam_questions(rq_one('SELECT questions_json FROM bdvh_exams WHERE exam_id = ?', [$r['exam_id']]) ?? [])),
            'exam_id' => (int) $r['exam_id'], 'exam_title' => $exam['title'] ?? '', 'exam_status' => $exam['status'] ?? ''],
        'serverNow' => rnow(), 'attempts' => $atts, 'events' => $events,
    ]);
}

function room_att_for_teacher(array $in): array
{
    r_teacher();
    $r = room_load((int) ($in['room_id'] ?? 0));
    $a = rq_one('SELECT * FROM bdvh_room_attempts WHERE attempt_id = ? AND room_id = ?', [(int) ($in['attempt_id'] ?? 0), $r['room_id']]);
    if (!$a) { rfail('Không tìm thấy bài làm.'); }
    return [$r, $a];
}
function room_unlock(array $r, array $a, string $how): void
{
    rq("UPDATE bdvh_room_attempts SET status = ?, unlocks = unlocks + 1, lock_reason = NULL, pin_fails = 0 WHERE attempt_id = ? AND status = 'LOCKED'",
        [$r['status'] === 'RUNNING' ? 'DOING' : 'READY', $a['attempt_id']]);
    room_log((int) $r['room_id'], (int) $a['attempt_id'], 'mo_khoa', $how, 0);
}
function action_room_unlock(array $in): void
{
    [$r, $a] = room_att_for_teacher($in);
    if ($a['status'] !== 'LOCKED') { rfail('Bài không ở trạng thái bị khoá.'); }
    room_unlock($r, $a, 'Giáo viên mở khoá từ bảng giám sát');
    rok(['ok' => true]);
}
function action_room_allow_rejoin(array $in): void
{
    [$r, $a] = room_att_for_teacher($in);
    if ($a['status'] === 'SUBMITTED') { rfail('Học sinh đã nộp bài.'); }
    rq('UPDATE bdvh_room_attempts SET allow_rejoin = 1 WHERE attempt_id = ?', [$a['attempt_id']]);
    room_log((int) $r['room_id'], (int) $a['attempt_id'], 'gv_cho_doi_may', 'Cho phép vào lại trên thiết bị khác', 0);
    rok(['ok' => true]);
}
function action_room_force_submit(array $in): void
{
    [$r, $a] = room_att_for_teacher($in);
    if ($a['status'] === 'SUBMITTED') { rfail('Học sinh đã nộp bài.'); }
    room_submit($r, $a, 'gv_thu');
    room_log((int) $r['room_id'], (int) $a['attempt_id'], 'gv_thu_bai_hs', 'Giáo viên thu bài', 0);
    rok(['ok' => true]);
}
function action_room_remove_attempt(array $in): void
{
    [$r, $a] = room_att_for_teacher($in);
    if ($r['status'] !== 'LOBBY') { rfail('Chỉ xoá được khi phòng chưa bắt đầu.'); }
    rq('DELETE FROM bdvh_room_attempts WHERE attempt_id = ?', [$a['attempt_id']]);
    room_log((int) $r['room_id'], null, 'gv_xoa_luot', $a['full_name'], 0);
    rok(['ok' => true]);
}
function action_room_attempt_log(array $in): void
{
    [$r, $a] = room_att_for_teacher($in);
    $ev = rq_all('SELECT event_id, type, detail, counted, client_at, created_at FROM bdvh_room_events WHERE attempt_id = ? ORDER BY event_id', [$a['attempt_id']]);
    unset($a['token_hash'], $a['layout_json']);
    rok(['attempt' => $a, 'events' => $ev]);
}

/* ============================ Học sinh ============================ */

function room_by_code(string $code): array
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    if (strlen($code) !== 6) { rfail('Mã phòng thi gồm 6 ký tự.'); }
    $r = rq_one("SELECT * FROM bdvh_rooms WHERE code = ? AND status <> 'DRAFT' ORDER BY room_id DESC LIMIT 1", [$code]);
    if (!$r) { rfail('Không tìm thấy phòng thi mã ' . $code . '.'); }
    room_tick($r);
    return $r;
}
function room_public(array $r): array
{
    $s = room_settings($r);
    $snap = rjd($r['snapshot_json']);
    return ['title' => $r['title'], 'label' => $r['label'], 'status' => $r['status'], 'code' => $r['code'], 'prepMinutes' => (int) $r['prep_minutes'],
        'durationMinutes' => (int) $r['duration_minutes'], 'startAt' => $r['start_at'], 'startedAt' => $r['started_at'], 'endsAt' => $r['ends_at'],
        'resultsPublished' => (int) $r['results_published'], 'questionCount' => count($snap['questions'] ?? []),
        'rules' => ['maxViolations' => $s['maxViolations'], 'onLimit' => $s['onLimit'], 'blurGraceSec' => $s['blurGraceSec'], 'requireFullscreen' => $s['requireFullscreen'],
            'allowBack' => $s['allowBack'], 'watermark' => $s['watermark'], 'lateJoinMinutes' => $s['lateJoinMinutes'], 'showScore' => $s['showScore']]];
}

function action_room_peek(array $in): void
{
    $r = room_by_code((string) ($in['code'] ?? ''));
    $me = null;
    if (!empty($_SESSION['bdvh_user_id'])) {
        $u = rq_one('SELECT user_id, full_name, phone, role FROM bdvh_users WHERE user_id = ?', [(int) $_SESSION['bdvh_user_id']]);
        if ($u) { $me = ['name' => $u['full_name'], 'phone' => $u['phone']]; }
    }
    rok(['room' => room_public($r), 'me' => $me, 'serverNow' => rnow()]);
}

function room_layout(array $r, array $s): array
{
    $qs = rjd($r['snapshot_json'])['questions'] ?? [];
    $order = array_map(fn($q) => (int) $q['idx'], $qs);
    if ($s['shuffleQuestions']) { shuffle($order); }
    $opt = [];
    foreach ($qs as $q) {
        if ($q['type'] !== 'choice') { continue; }
        $perm = array_keys($q['opts']);
        $fixed = false;
        foreach ($q['opts'] as $t) {
            if (preg_match('/(tất cả|cả (hai|ba|bốn)|đều (đúng|sai)|không có (đáp án|phương án|ý) nào|các (ý|phương án|đáp án) trên|\b[A-F] và [A-F]\b)/iu', (string) $t)) { $fixed = true; }
        }
        if ($s['shuffleOptions'] && !$fixed) { shuffle($perm); }
        $opt[(string) $q['idx']] = $perm;
    }
    return ['order' => $order, 'opt' => $opt];
}

function action_room_join(array $in): void
{
    $r = room_by_code((string) ($in['code'] ?? ''));
    if ($r['status'] === 'CLOSED') { rfail('Phòng thi đã đóng.'); }
    $uid = (int) ($_SESSION['bdvh_user_id'] ?? 0);
    $u = $uid ? rq_one('SELECT user_id, full_name, phone FROM bdvh_users WHERE user_id = ?', [$uid]) : null;
    if (!$u) { rfail('Em cần đăng nhập tài khoản bdvh (họ tên + số điện thoại) trước khi vào phòng.'); }
    $dev = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($in['deviceId'] ?? ''));
    if (strlen($dev) < 16) { rfail('Trình duyệt không lưu được dữ liệu — tắt chế độ ẩn danh rồi thử lại.'); }
    $info = mb_substr((string) ($in['deviceInfo'] ?? ''), 0, 200);
    $s = room_settings($r);
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $res = rtx(function () use ($r, $u, $dev, $info, $hash, $ip, $s) {
        $a = rq_one('SELECT * FROM bdvh_room_attempts WHERE room_id = ? AND user_id = ?' . r_forupdate(), [$r['room_id'], $u['user_id']]);
        if ($a) {
            if ($a['status'] === 'SUBMITTED') { return ['submitted' => true]; }
            $same = hash_equals((string) $a['device_id'], $dev);
            if (!$same && !(int) $a['allow_rejoin']) {
                $c = $a['status'] === 'DOING' ? 1 : 0;
                room_log((int) $r['room_id'], (int) $a['attempt_id'], 'second_device', 'Thiết bị khác cố vào: ' . $info, $c);
                if ($c) { room_violation($r, $a, 'second_device'); }
                return ['blocked' => true];
            }
            rq('UPDATE bdvh_room_attempts SET token_hash = ?, device_id = ?, device_info = ?, ip = ?, allow_rejoin = 0, last_seen_at = ? WHERE attempt_id = ?',
                [$hash, $dev, $info, $ip, rnow(), $a['attempt_id']]);
            room_log((int) $r['room_id'], (int) $a['attempt_id'], $same ? 'vao_lai' : 'doi_may', $info, 0);
            return ['ok' => true];
        }
        if ($r['status'] === 'RUNNING' && time() - rts($r['started_at']) > 60 * $s['lateJoinMinutes']) {
            rfail('Đã quá ' . $s['lateJoinMinutes'] . ' phút từ giờ bắt đầu — không vào phòng được nữa. Gặp giáo viên.');
        }
        $seat = (int) rq_val('SELECT COALESCE(MAX(seat_no),0) FROM bdvh_room_attempts WHERE room_id = ?', [$r['room_id']]) + 1;
        rq("INSERT INTO bdvh_room_attempts (room_id, user_id, full_name, phone, seat_no, token_hash, device_id, device_info, ip, status, layout_json, answers_json, joined_at, last_seen_at) " .
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'JOINED', ?, '{}', ?, ?)",
            [$r['room_id'], $u['user_id'], $u['full_name'], $u['phone'], $seat, $hash, $dev, $info, $ip, rje(room_layout($r, $s)), rnow(), rnow()]);
        global $pdo;
        room_log((int) $r['room_id'], (int) $pdo->lastInsertId(), 'vao_phong', $info, 0);
        return ['ok' => true];
    });
    if (!empty($res['blocked'])) { rfail('Tài khoản này đang làm bài trên thiết bị khác. Giơ tay báo giáo viên nếu em cần đổi máy.'); }
    if (!empty($res['submitted'])) { rfail('Em đã nộp bài này rồi.'); }
    rok(['token' => $token]);
}

function room_require_attempt(array $in): array
{
    $t = (string) ($in['t'] ?? '');
    if (!preg_match('/^[0-9a-f]{64}$/', $t)) { rfail('Phiên làm bài không hợp lệ. Hãy quét lại mã QR.'); }
    $a = rq_one('SELECT * FROM bdvh_room_attempts WHERE token_hash = ?', [hash('sha256', $t)]);
    if (!$a) { rfail('Phiên làm bài đã chuyển sang thiết bị khác hoặc không còn hiệu lực.'); }
    $r = room_load((int) $a['room_id']);
    room_tick($r);
    $a = rq_one('SELECT * FROM bdvh_room_attempts WHERE attempt_id = ?', [$a['attempt_id']]);
    return [$r, $a];
}

function room_violation(array $r, array $a, string $type): array
{
    rq('UPDATE bdvh_room_attempts SET violations = violations + 1 WHERE attempt_id = ?', [$a['attempt_id']]);
    $a = rq_one('SELECT * FROM bdvh_room_attempts WHERE attempt_id = ?', [$a['attempt_id']]);
    $s = room_settings($r);
    if ($a['status'] === 'DOING' && (int) $a['violations'] >= $s['maxViolations'] + (int) $a['unlocks']) {
        $why = ROOM_COUNTED[$type] ?? $type;
        if ($s['onLimit'] === 'submit') {
            room_submit($r, $a, 'vi_pham');
            room_log((int) $r['room_id'], (int) $a['attempt_id'], 'tu_nop_vi_pham', 'Vượt ' . $s['maxViolations'] . ' vi phạm — tự nộp bài', 0);
        } else {
            rq("UPDATE bdvh_room_attempts SET status = 'LOCKED', lock_reason = ? WHERE attempt_id = ?", [mb_substr($why, 0, 190), $a['attempt_id']]);
            room_log((int) $r['room_id'], (int) $a['attempt_id'], 'khoa_bai', 'Khoá bài sau vi phạm: ' . $why, 0);
        }
        $a = rq_one('SELECT * FROM bdvh_room_attempts WHERE attempt_id = ?', [$a['attempt_id']]);
    }
    return $a;
}

function room_ingest(array $r, array $a, array $events): array
{
    if (!$events) { return $a; }
    $events = array_slice($events, 0, 30);
    return rtx(function () use ($r, $a, $events) {
        $a = rq_one('SELECT * FROM bdvh_room_attempts WHERE attempt_id = ?' . r_forupdate(), [$a['attempt_id']]);
        $last = rts((string) rq_val('SELECT MAX(created_at) FROM bdvh_room_events WHERE attempt_id = ? AND counted = 1', [$a['attempt_id']])) ?? 0;
        foreach ($events as $ev) {
            $type = preg_replace('/[^a-z_]/', '', (string) ($ev['type'] ?? ''));
            $detail = mb_substr((string) ($ev['detail'] ?? ''), 0, 200);
            $at = mb_substr((string) ($ev['at'] ?? ''), 0, 30);
            if (isset(ROOM_COUNTED[$type])) {
                $active = $r['status'] === 'RUNNING' && $a['status'] === 'DOING';
                $dup = time() - $last < 2;
                $c = $active && !$dup ? 1 : 0;
                room_log((int) $r['room_id'], (int) $a['attempt_id'], $type, ($detail ?: ROOM_COUNTED[$type]) . ($active && $dup ? ' (gộp)' : ''), $c, $at);
                if ($c) { $last = time(); $a = room_violation($r, $a, $type); }
            } elseif (in_array($type, ROOM_LOGGED, true)) {
                room_log((int) $r['room_id'], (int) $a['attempt_id'], $type, $detail, 0, $at);
            }
        }
        return $a;
    });
}

function room_state(array $r, array $a): array
{
    $s = room_settings($r);
    $dl = room_deadline($r, $a);
    $out = ['serverNow' => rnow(), 'exam' => room_public($r),
        'me' => ['name' => $a['full_name'], 'mssv' => $a['phone'] ?: ('#' . $a['user_id']), 'seat' => (int) $a['seat_no'], 'status' => $a['status'],
            'violations' => (int) $a['violations'], 'limit' => $s['maxViolations'] + (int) $a['unlocks'], 'lockReason' => $a['lock_reason'],
            'deadline' => $dl ? rdt($dl) : null, 'readyAt' => $a['ready_at']],
        'readyCount' => (int) rq_val("SELECT COUNT(*) FROM bdvh_room_attempts WHERE room_id = ? AND status <> 'JOINED'", [$r['room_id']]),
        'joinedCount' => (int) rq_val('SELECT COUNT(*) FROM bdvh_room_attempts WHERE room_id = ?', [$r['room_id']])];
    if ($a['status'] === 'SUBMITTED') {
        $out['result'] = ['submittedAt' => $a['submitted_at'], 'reason' => $a['submit_reason']];
        if ($s['showScore'] || (int) $r['results_published']) {
            $out['result'] += ['score10' => (float) $a['score'], 'raw' => (int) $a['correct_count'], 'max' => (int) $a['total_questions']];
        }
        if ((int) $r['results_published'] && $r['status'] === 'CLOSED' && $a['sub_id']) {
            $out['result']['subId'] = (int) $a['sub_id'];
        }
    }
    return $out;
}

function action_room_state(array $in): void
{
    [$r, $a] = room_require_attempt($in);
    if ($a['status'] !== 'SUBMITTED') {
        $a = room_ingest($r, $a, (array) ($in['events'] ?? []));
        rq('UPDATE bdvh_room_attempts SET last_seen_at = ? WHERE attempt_id = ?', [rnow(), $a['attempt_id']]);
    }
    rok(room_state($r, $a));
}
function action_room_ready(array $in): void
{
    [$r, $a] = room_require_attempt($in);
    if ($a['status'] === 'JOINED') {
        rq("UPDATE bdvh_room_attempts SET status = 'READY', ready_at = ? WHERE attempt_id = ?", [rnow(), $a['attempt_id']]);
        room_log((int) $r['room_id'], (int) $a['attempt_id'], 'san_sang', mb_substr(rje((array) ($in['checks'] ?? [])), 0, 200), 0);
        $a['status'] = 'READY';
    }
    rok(room_state($r, $a));
}

function action_room_paper(array $in): void
{
    [$r, $a] = room_require_attempt($in);
    if ($a['status'] === 'SUBMITTED') { rfail('Em đã nộp bài.'); }
    if ($r['status'] !== 'RUNNING') { rfail($r['status'] === 'LOBBY' ? 'Chưa đến giờ làm bài.' : 'Phòng thi đã đóng.'); }
    if (in_array($a['status'], ['JOINED', 'READY'], true)) {
        rq("UPDATE bdvh_room_attempts SET status = 'DOING' WHERE attempt_id = ? AND status IN ('JOINED','READY')", [$a['attempt_id']]);
        room_log((int) $r['room_id'], (int) $a['attempt_id'], 'bat_dau_lam', '', 0);
        $a['status'] = 'DOING';
    } elseif (!empty($in['reloaded']) && $a['status'] === 'DOING') {
        $a = room_ingest($r, $a, [['type' => 'reload', 'detail' => 'Mở lại trang khi đang làm bài']]);
    }
    $qs = [];
    foreach (rjd($r['snapshot_json'])['questions'] ?? [] as $q) { $qs[(int) $q['idx']] = $q; }
    $lay = rjd($a['layout_json']);
    $out = [];
    $n = 0;
    foreach ((array) ($lay['order'] ?? array_keys($qs)) as $idx) {
        if (!isset($qs[$idx])) { continue; }
        $q = $qs[$idx];
        $opts = [];
        if ($q['type'] === 'choice') {
            foreach ((array) ($lay['opt'][(string) $idx] ?? array_keys($q['opts'])) as $k) {
                if (isset($q['opts'][$k])) { $opts[] = ['id' => (int) $k, 'text' => $q['opts'][$k]]; }
            }
        }
        $out[] = ['id' => (int) $idx, 'n' => ++$n, 'type' => $q['type'], 'stem' => $q['q'], 'section' => $q['section'], 'options' => $opts,
            'items' => $q['type'] === 'true_false' ? $q['items'] : [], 'points' => 1];
    }
    $st = room_state($r, $a);
    $st['questions'] = $out;
    $st['answers'] = (object) rjd($a['answers_json']);
    rok($st);
}

function action_room_save_answers(array $in): void
{
    [$r, $a] = room_require_attempt($in);
    if ($a['status'] === 'LOCKED') { rfail('Bài đang bị khoá — giơ tay báo giáo viên.'); }
    if ($a['status'] !== 'DOING' || $r['status'] !== 'RUNNING') { rfail('Không còn trong giờ làm bài.'); }
    if (time() > room_deadline($r, $a) + 30) { rfail('Đã hết giờ làm bài.'); }
    $qs = [];
    foreach (rjd($r['snapshot_json'])['questions'] ?? [] as $q) { $qs[(int) $q['idx']] = $q; }
    $changes = (array) ($in['answers'] ?? []);
    rtx(function () use ($a, $qs, $changes) {
        $cur = rjd((string) rq_val('SELECT answers_json FROM bdvh_room_attempts WHERE attempt_id = ?' . r_forupdate(), [$a['attempt_id']]));
        foreach ($changes as $k => $v) {
            $idx = (int) $k;
            if (!isset($qs[$idx])) { continue; }
            $q = $qs[$idx];
            $key = (string) $idx;
            if ($v === null || $v === '' || $v === []) { unset($cur[$key]); continue; }
            if ($q['type'] === 'choice') {
                if (is_numeric($v) && isset($q['opts'][(int) $v])) { $cur[$key] = (int) $v; }
            } elseif ($q['type'] === 'true_false') {
                $m = [];
                foreach ((array) $v as $si => $sv) {
                    if (isset($q['items'][(int) $si]) && ($sv === 0 || $sv === 1 || $sv === '0' || $sv === '1')) { $m[(string) (int) $si] = (int) $sv; }
                }
                if ($m) { $cur[$key] = $m; } else { unset($cur[$key]); }
            } else {
                $cur[$key] = mb_substr((string) $v, 0, 200);
            }
        }
        rq('UPDATE bdvh_room_attempts SET answers_json = ?, last_seen_at = ? WHERE attempt_id = ?', [rje((object) $cur), rnow(), $a['attempt_id']]);
    });
    rok(['saved' => true, 'serverNow' => rnow()]);
}

function action_room_pin(array $in): void
{
    [$r, $a] = room_require_attempt($in);
    if ($a['status'] !== 'LOCKED') { rok(room_state($r, $a)); }
    if ((int) $a['pin_fails'] >= 5) { rfail('Nhập sai PIN quá 5 lần — giáo viên mở khoá trên bảng giám sát.'); }
    $pin = preg_replace('/\D/', '', (string) ($in['pin'] ?? ''));
    if (!hash_equals((string) $r['pin'], $pin)) {
        rq('UPDATE bdvh_room_attempts SET pin_fails = pin_fails + 1 WHERE attempt_id = ?', [$a['attempt_id']]);
        room_log((int) $r['room_id'], (int) $a['attempt_id'], 'pin_sai', 'Nhập sai PIN', 0);
        rfail('PIN không đúng.');
    }
    room_unlock($r, $a, 'Giáo viên nhập PIN tại chỗ');
    rok(room_state($r, rq_one('SELECT * FROM bdvh_room_attempts WHERE attempt_id = ?', [$a['attempt_id']])));
}

function action_room_submit(array $in): void
{
    [$r, $a] = room_require_attempt($in);
    if ($a['status'] === 'SUBMITTED') { rok(room_state($r, $a)); }
    if ($a['status'] === 'LOCKED') { rfail('Bài đang bị khoá — giơ tay báo giáo viên.'); }
    if ($r['status'] !== 'RUNNING') { rfail('Chưa đến giờ làm bài.'); }
    room_submit($r, $a, 'hs_nop');
    room_log((int) $r['room_id'], (int) $a['attempt_id'], 'nop_bai', '', 0);
    rok(room_state($r, rq_one('SELECT * FROM bdvh_room_attempts WHERE attempt_id = ?', [$a['attempt_id']])));
}

/* ============================ Chấm bài ============================ */

function room_norm_short(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = str_replace([',', ' '], ['.', ''], $s);
    return rtrim($s, '.');
}
function room_is_correct(array $q, $given): ?bool
{
    if ($given === null || $given === '' || $given === []) { return null; }
    if ($q['type'] === 'choice') { return (int) $given === (int) $q['ans']; }
    if ($q['type'] === 'true_false') {
        foreach ((array) $q['ans'] as $si => $tv) {
            if (!isset($given[(string) $si]) || (int) $given[(string) $si] !== (int) $tv) { return false; }
        }
        return true;
    }
    return room_norm_short((string) $given) === room_norm_short((string) $q['ans']);
}

/** Chấm, ghi kết quả vào lượt làm và bdvh_submissions (để có phiếu báo điểm ket-qua.html). Bỏ qua nếu đã nộp. */
function room_submit(array $r, array $a, string $reason): void
{
    global $nowSql;
    $cur = rq_one('SELECT status FROM bdvh_room_attempts WHERE attempt_id = ?', [$a['attempt_id']]);
    if (!$cur || $cur['status'] === 'SUBMITTED') { return; }
    $a = rq_one('SELECT * FROM bdvh_room_attempts WHERE attempt_id = ?', [$a['attempt_id']]);
    $qs = rjd($r['snapshot_json'])['questions'] ?? [];
    $ans = rjd($a['answers_json']);
    $correct = 0;
    $detail = [];
    $details = [];
    $answersGiven = [];
    foreach ($qs as $q) {
        $g = $ans[(string) $q['idx']] ?? null;
        $ok = room_is_correct($q, $g);
        if ($ok) { $correct++; }
        $detail[(string) $q['idx']] = $ok === null ? 'blank' : ($ok ? 'correct' : 'wrong');
        $answersGiven[(int) $q['idx']] = $g ?? -1;
        $details[] = ['idx' => (int) $q['idx'], 'q' => $q['q'], 'type' => $q['type'], 'opts' => $q['opts'], 'items' => $q['items'], 'chosen' => $g ?? -1,
            'correct_ans' => $q['ans'], 'is_correct' => (bool) $ok, 'explanation' => $q['exp']];
    }
    $total = count($qs);
    $score = $total ? round($correct / $total * 10, 2) : 0;
    $start = max(rts($r['started_at']) ?? time(), rts($a['joined_at']) ?? 0);
    $dur = max(0, min(time(), (room_deadline($r, $a) ?? time()) + 30) - $start);
    $claimed = rq("UPDATE bdvh_room_attempts SET status = 'SUBMITTED', submitted_at = ?, submit_reason = ?, correct_count = ?, total_questions = ?, score = ?, detail_json = ? " .
        "WHERE attempt_id = ? AND status <> 'SUBMITTED'", [rnow(), $reason, $correct, $total, $score, rje($detail), $a['attempt_id']])->rowCount();
    if (!$claimed) { return; }
    $snap = rjd($r['snapshot_json']);
    try {
        $ai = function_exists('evaluate_with_gemini')
            ? evaluate_with_gemini((string) $a['full_name'], (string) ($snap['exam_title'] ?? $r['title']), (float) $score, $correct, $total, $dur, 60 * (int) $r['duration_minutes'], $details)
            : null;
    } catch (Throwable $e) {
        $ai = null;
    }
    if (is_array($ai)) {
        $ai['room'] = ['code' => $r['code'], 'title' => $r['title'], 'violations' => (int) $a['violations'], 'reason' => $reason];
    }
    try {
        rq("INSERT INTO bdvh_submissions (user_id, exam_id, score, duration_seconds, answers_given, correct_count, total_questions, ai_evaluation, is_free_trial, created_at) " .
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, $nowSql)",
            [$a['user_id'], $r['exam_id'], $score, $dur, json_encode($answersGiven), $correct, $total, rje($ai)]);
        global $pdo;
        rq('UPDATE bdvh_room_attempts SET sub_id = ? WHERE attempt_id = ?', [(int) $pdo->lastInsertId(), $a['attempt_id']]);
    } catch (Throwable $e) {
        error_log('[phong-thi] ghi bdvh_submissions: ' . $e->getMessage());
    }
}

/* ============================ Kết quả & phân tích ============================ */

function r_mean(array $x): float { return $x ? array_sum($x) / count($x) : 0.0; }
function r_sd(array $x): float
{
    $n = count($x);
    if ($n < 2) { return 0.0; }
    $m = r_mean($x);
    $s = 0.0;
    foreach ($x as $v) { $s += ($v - $m) ** 2; }
    return sqrt($s / ($n - 1));
}
function r_pearson(array $a, array $b): ?float
{
    $n = count($a);
    if ($n < 3) { return null; }
    $ma = r_mean($a); $mb = r_mean($b);
    $sab = $saa = $sbb = 0.0;
    for ($i = 0; $i < $n; $i++) { $sab += ($a[$i] - $ma) * ($b[$i] - $mb); $saa += ($a[$i] - $ma) ** 2; $sbb += ($b[$i] - $mb) ** 2; }
    return ($saa > 0 && $sbb > 0) ? $sab / sqrt($saa * $sbb) : null;
}

function action_room_results(array $in): void
{
    r_teacher();
    $r = room_load((int) ($in['room_id'] ?? 0));
    room_tick($r);
    $qs = rjd($r['snapshot_json'])['questions'] ?? [];
    $atts = rq_all('SELECT attempt_id, user_id, full_name, phone, seat_no, status, violations, unlocks, submitted_at, submit_reason, correct_count, total_questions, score, answers_json, detail_json, sub_id ' .
        'FROM bdvh_room_attempts WHERE room_id = ? ORDER BY seat_no', [$r['room_id']]);
    $done = array_values(array_filter($atts, fn($a) => $a['status'] === 'SUBMITTED'));
    $n = count($done);
    $M = [];
    $tot = [];
    foreach ($done as $i => $a) {
        $d = rjd($a['detail_json']);
        foreach ($qs as $q) { $M[$i][$q['idx']] = ($d[(string) $q['idx']] ?? '') === 'correct' ? 1 : 0; }
        $tot[$i] = array_sum($M[$i] ?? []);
    }
    $k = count($qs);
    $alpha = null;
    if ($n >= 3 && $k >= 2) {
        $vt = r_sd($tot) ** 2;
        $sv = 0.0;
        foreach ($qs as $q) { $sv += r_sd(array_column($M, $q['idx'])) ** 2; }
        if ($vt > 0) { $alpha = ($k / ($k - 1)) * (1 - $sv / $vt); }
    }
    $order = array_keys($tot);
    usort($order, fn($x, $y) => $tot[$y] <=> $tot[$x]);
    $g = $n >= 4 ? max(1, (int) round($n * 0.27)) : 0;
    $up = array_slice($order, 0, $g);
    $lo = $g ? array_slice($order, -$g) : [];
    $items = [];
    foreach ($qs as $pos => $q) {
        $col = array_column($M, $q['idx']);
        $p = $n ? r_mean($col) : null;
        $D = $g ? r_mean(array_map(fn($i) => $M[$i][$q['idx']], $up)) - r_mean(array_map(fn($i) => $M[$i][$q['idx']], $lo)) : null;
        $rest = [];
        foreach ($tot as $i => $t) { $rest[$i] = $t - $M[$i][$q['idx']]; }
        $rpb = $n >= 3 ? r_pearson(array_values($col), array_values($rest)) : null;
        $cnt = [];
        $blank = 0;
        $upC = [];
        $loC = [];
        foreach ($done as $i => $a) {
            $gv = rjd($a['answers_json'])[(string) $q['idx']] ?? null;
            if ($gv === null || $gv === '' || $gv === []) { $blank++; continue; }
            $key = $q['type'] === 'choice' ? 'ABCDEF'[(int) $gv] ?? '?' : ($q['type'] === 'short' ? room_norm_short((string) $gv) : (room_is_correct($q, $gv) ? 'đúng hết' : 'sai ít nhất 1 ý'));
            $cnt[$key] = ($cnt[$key] ?? 0) + 1;
            if (in_array($i, $up, true)) { $upC[$key] = ($upC[$key] ?? 0) + 1; }
            if (in_array($i, $lo, true)) { $loC[$key] = ($loC[$key] ?? 0) + 1; }
        }
        $opts = [];
        $flags = [];
        if ($q['type'] === 'choice') {
            foreach ($q['opts'] as $oi => $t) {
                $L = 'ABCDEF'[$oi];
                $isKey = (int) $q['ans'] === $oi;
                $opts[] = ['key' => $L, 'text' => $t, 'isKey' => $isKey, 'count' => $cnt[$L] ?? 0, 'rate' => $n ? ($cnt[$L] ?? 0) / $n : 0, 'upper' => $upC[$L] ?? 0, 'lower' => $loC[$L] ?? 0];
                if (!$isKey && $n >= 10 && empty($cnt[$L])) { $flags[] = 'Phương án ' . $L . ' không ai chọn'; }
                if (!$isKey && $g && ($upC[$L] ?? 0) > ($loC[$L] ?? 0)) { $flags[] = 'Phương án nhiễu ' . $L . ' hút nhóm giỏi'; }
            }
        } else {
            arsort($cnt);
            foreach (array_slice($cnt, 0, 6, true) as $t => $c) { $opts[] = ['key' => (string) $t, 'text' => (string) $t, 'isKey' => false, 'count' => $c, 'rate' => $n ? $c / $n : 0]; }
        }
        if ($p !== null && $n >= 5) { if ($p > 0.9) { $flags[] = 'Quá dễ'; } if ($p < 0.2) { $flags[] = 'Quá khó'; } }
        if ($D !== null && $D < 0.2) { $flags[] = $D < 0 ? 'Phân biệt âm — kiểm tra đáp án' : 'Phân biệt kém'; }
        $ansTxt = $q['type'] === 'choice' ? 'ABCDEF'[(int) $q['ans']] : ($q['type'] === 'true_false' ? implode(',', array_map(fn($v) => $v ? 'Đ' : 'S', (array) $q['ans'])) : (string) $q['ans']);
        $items[] = ['idx' => $q['idx'], 'ord' => $pos + 1, 'type' => $q['type'], 'stem' => $q['q'], 'section' => $q['section'], 'answer' => $ansTxt,
            'p' => $p, 'D' => $D, 'rpb' => $rpb, 'blank' => $blank, 'options' => $opts, 'flags' => array_values(array_unique($flags))];
    }
    $scores = array_map(fn($a) => (float) $a['score'], $done);
    $hist = array_fill(0, 10, 0);
    foreach ($scores as $s) { $hist[min(9, (int) floor($s))]++; }
    $sd = r_sd($scores);
    $sorted = $scores;
    sort($sorted);
    $med = $n ? ($n % 2 ? $sorted[intdiv($n, 2)] : ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2) : null;
    rok([
        'room' => ['room_id' => (int) $r['room_id'], 'title' => $r['title'], 'label' => $r['label'], 'status' => $r['status'], 'code' => $r['code'],
            'started_at' => $r['started_at'], 'closed_at' => $r['closed_at'], 'duration_minutes' => (int) $r['duration_minutes'], 'results_published' => (int) $r['results_published'], 'question_count' => $k],
        'stats' => ['n' => $n, 'joined' => count($atts), 'mean' => $n ? r_mean($scores) : null, 'sd' => $sd, 'median' => $med, 'min' => $n ? min($scores) : null,
            'max' => $n ? max($scores) : null, 'passRate' => $n ? count(array_filter($scores, fn($s) => $s >= 5)) / $n * 100 : null,
            'alpha' => $alpha, 'sem' => $alpha !== null ? $sd * sqrt(max(0, 1 - $alpha)) : null, 'hist' => $hist,
            'violators' => count(array_filter($atts, fn($a) => (int) $a['violations'] > 0))],
        'items' => $items,
        'students' => array_map(fn($a) => ['attemptId' => (int) $a['attempt_id'], 'name' => $a['full_name'], 'phone' => $a['phone'], 'seat' => (int) $a['seat_no'],
            'status' => $a['status'], 'reason' => $a['submit_reason'], 'submittedAt' => $a['submitted_at'], 'violations' => (int) $a['violations'], 'unlocks' => (int) $a['unlocks'],
            'correct' => $a['correct_count'] === null ? null : (int) $a['correct_count'], 'total' => $a['total_questions'] === null ? null : (int) $a['total_questions'],
            'score' => $a['score'] === null ? null : (float) $a['score'], 'subId' => $a['sub_id'] ? (int) $a['sub_id'] : null], $atts),
    ]);
}

/* ============================ Bộ định tuyến ============================ */

function room_dispatch(string $action, array $in): void
{
    room_ensure_tables();
    $map = [
        'room_exams' => 'action_room_exams', 'room_exam_preview' => 'action_room_exam_preview', 'room_import_exam' => 'action_room_import_exam',
        'room_list' => 'action_room_list', 'room_save' => 'action_room_save', 'room_delete' => 'action_room_delete', 'room_open' => 'action_room_open',
        'room_start' => 'action_room_start', 'room_add_time' => 'action_room_add_time', 'room_close' => 'action_room_close', 'room_publish' => 'action_room_publish',
        'room_monitor' => 'action_room_monitor', 'room_unlock' => 'action_room_unlock', 'room_allow_rejoin' => 'action_room_allow_rejoin',
        'room_force_submit' => 'action_room_force_submit', 'room_remove_attempt' => 'action_room_remove_attempt', 'room_attempt_log' => 'action_room_attempt_log',
        'room_results' => 'action_room_results',
        'room_peek' => 'action_room_peek', 'room_join' => 'action_room_join', 'room_state' => 'action_room_state', 'room_ready' => 'action_room_ready',
        'room_paper' => 'action_room_paper', 'room_save_answers' => 'action_room_save_answers', 'room_pin' => 'action_room_pin', 'room_submit' => 'action_room_submit',
    ];
    if (!isset($map[$action])) {
        json_out(['status' => 'error', 'message' => 'Thao tác phòng thi không hợp lệ.'], 404);
    }
    try {
        $fn = $map[$action];
        $fn($in);
    } catch (RoomError $e) {
        json_out(['status' => 'error', 'message' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('[phong-thi] ' . $action . ': ' . $e->getMessage() . ' @' . $e->getLine());
        json_out(['status' => 'error', 'message' => 'Lỗi máy chủ. Vui lòng thử lại.'], 500);
    }
}

// Tự định tuyến khi được api.php nạp
if (isset($action) && is_string($action) && str_starts_with($action, 'room_')) {
    room_dispatch($action, is_array($inputData ?? null) ? $inputData : []);
}
