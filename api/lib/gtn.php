<?php
/**
 * api/lib/gtn.php — Bộ phiếu online "Seminar Giới thiệu ngành Địa chất" (tân sinh viên) chạy trên module phiếu
 * học tập khtd (docs/04-API-PHP.md mục 41). Giống bộ CHĐ/Đ&KV: không thêm bảng, không thêm action, không migration;
 * dùng lại mã vào lớp, mã ôn tập, chấm theo rubric, phiếu điểm PDF, thống kê, lưu trữ cuối học kỳ.
 *
 *  - Nội dung phiếu ở api/lib/gtn_phieu.json (thư mục api/lib bị .htaccess chặn web).
 *    Số 1 = Thẻ khám phá bản thân (cá nhân) · 2 = Họp nhóm lần đầu · 3 = Đề cương (mốc tuần 1) ·
 *    4 = Tiến độ tuần 2 · 5 = Phản biện nhóm bạn (tuần 3) · 6 = Đánh giá chéo trong nhóm và tự nhìn lại.
 *  - khtdSeedWorksheets {token, classId, bo: 'gtn'} tạo các phiếu này cho lớp (bỏ qua số đã có).
 *  - Trang: gtn-dc/index.html (cẩm nang + khám phá bản thân, chạy trên máy SV, không gửi dữ liệu),
 *    gtn-dc/online.html (SV làm phiếu), gtn-dc/quanly.html (GV, KHTD_BO='gtn').
 */

/** Bộ phiếu GTN: [No => định nghĩa] như khtd_default_worksheets(). */
function gtn_default_worksheets(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $raw = @file_get_contents(__DIR__ . '/gtn_phieu.json');
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) throw new RuntimeException('Thiếu hoặc hỏng tệp gtn_phieu.json.');
    $out = [];
    foreach ($data as $no => $def) {
        if (!is_array($def) || !isset($def['title'], $def['sections'], $def['rubric'])) continue;
        $out[(int) $no] = $def;
    }
    ksort($out);
    return $cache = $out;
}

/* ------------------------------------------------------------------ */
/* Mã tham quan Bảo tàng Địa chất (docs/04 mục 42)                     */
/* ------------------------------------------------------------------ */
/*
 * Buổi tham quan cần một mã in sẵn trên giấy (kèm QR) và dùng được cả ngày, khác mã điểm danh thường
 * (sinh khi mở, sống tối đa app.max_window_minutes = 60 phút). Không thêm bảng, không migration:
 * mã là một dòng attendance_keys của buổi học, KeyID tiền tố 'KTQ_'.
 *   - Tạo mã (chờ): Status CLOSED, StartTime = LateAfter = EndTime = lúc tạo → khtdLogin / checkin
 *     (chỉ nhận Status OPEN) chưa nhận mã này; GV in giấy trước.
 *   - Kích hoạt: Status OPEN, StartTime = bây giờ, LateAfter = bây giờ + lateMinutes, EndTime = bây giờ + 24 giờ.
 *     Từ lúc này mã vừa là mã điểm danh buổi tham quan, vừa là mã đăng nhập gtn-dc/bao-tang.html và phiếu online.
 *   - Một buổi chỉ một mã sống: kích hoạt đóng mọi mã đang mở khác của buổi (như openAttendance).
 * Ngoại lệ có chủ đích với kẹp 60 phút của openAttendance (M5): chỉ GV đứng lớp tạo được, mỗi lần tạo/kích hoạt ghi
 * audit_log, mã vẫn qua rate limit khtd_login_ip / checkin_ip, MSSV phải có trong danh sách lớp; hết 24 giờ là hết.
 */

const GTN_TQ_HOURS = 24;

/** Mã tham quan của một buổi: ['key' => dòng attendance_keys mới nhất có KeyID KTQ_, 'state' => pending|active|expired|closed]. */
function gtn_tq_state(?array $k): string
{
    if (!$k) return 'none';
    if ($k['Status'] === 'CLOSED' && (string) $k['StartTime'] === (string) $k['EndTime']) return 'pending';
    if ($k['Status'] === 'OPEN' && (string) $k['EndTime'] > db_now()) return 'active';
    if ($k['Status'] === 'OPEN') return 'expired';
    return 'closed';
}

function gtn_tq_session(array $me, string $sessionId): array
{
    $stmt = db()->prepare('SELECT SessionID, ClassID, SessionNo, `Date`, Content FROM sessions WHERE SessionID = :id LIMIT 1');
    $stmt->execute(['id' => $sessionId]);
    $s = $stmt->fetch();
    if (!$s) throw new RuntimeException(not_found_message($me, 'buổi học', $sessionId));
    assert_class_access($me, (string) $s['ClassID']);
    return $s;
}

/** Mã tham quan mới nhất của buổi. Trong cùng một giây (đổi mã ngay sau khi tạo) ưu tiên mã đang mở, rồi mã chờ, rồi mã hạn xa hơn. */
function gtn_tq_latest(string $sessionId): ?array
{
    $stmt = db()->prepare("SELECT * FROM attendance_keys WHERE SessionID = :s AND KeyID LIKE 'KTQ\\_%' ORDER BY CreatedAt DESC, (Status = 'OPEN') DESC, (StartTime = EndTime) DESC, EndTime DESC LIMIT 1");
    $stmt->execute(['s' => $sessionId]);
    return $stmt->fetch() ?: null;
}

function gtn_tq_public(?array $k): ?array
{
    if (!$k) return null;
    $st = gtn_tq_state($k);
    return ['code' => $k['Code'], 'state' => $st, 'sessionId' => $k['SessionID'],
            'activatedAt' => $st === 'pending' ? null : $k['StartTime'], 'lateAfter' => $st === 'pending' ? null : $k['LateAfter'],
            'expiresAt' => $st === 'pending' ? null : $k['EndTime'], 'createdAt' => $k['CreatedAt']];
}

/** GET gtnTqStatus {token, classId} — các buổi ACTIVE của lớp + mã tham quan mới nhất của từng buổi. */
function action_gtn_tq_status(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $classId = trim((string) ($params['classId'] ?? ''));
    assert_class_access($me, $classId);
    $s = db()->prepare("SELECT SessionID, SessionNo, `Date`, StartTime, Content FROM sessions WHERE ClassID = :c AND Status = 'ACTIVE' ORDER BY SessionNo");
    $s->execute(['c' => $classId]);
    $out = [];
    foreach ($s->fetchAll() as $row) {
        $row['tq'] = gtn_tq_public(gtn_tq_latest((string) $row['SessionID']));
        $out[] = $row;
    }
    api_ok(['sessions' => $out, 'now' => db_now(), 'hours' => GTN_TQ_HOURS]);
}

/** POST gtnTqCreate {token, sessionId, renew?} — tạo mã chờ để in (đã có mã chờ thì trả lại mã đó, trừ khi renew=1). */
function action_gtn_tq_create(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $s = gtn_tq_session($me, trim((string) ($params['sessionId'] ?? '')));
    $renew = in_array((string) ($params['renew'] ?? ''), ['1', 'true'], true);
    $cur = gtn_tq_latest((string) $s['SessionID']);
    $st = gtn_tq_state($cur);
    if ($st === 'active' && !$renew) { api_ok(['tq' => gtn_tq_public($cur), 'reused' => true]); return; }
    if ($st === 'pending' && !$renew) { api_ok(['tq' => gtn_tq_public($cur), 'reused' => true]); return; }
    $appCfg = app_config()['app'] ?? [];
    $alphabet = (string) ($appCfg['code_alphabet'] ?? 'ACDEFGHJKMNPQRTUVWXY34679');
    $length = (int) ($appCfg['code_length'] ?? 4);
    $code = null; $id = new_id('KTQ');
    db_transaction(function (PDO $pdo) use ($s, $cur, $st, $alphabet, $length, $me, $id, &$code): void {
        // Đổi mã: mã chờ cũ không còn là "chờ" (EndTime lệch StartTime 1 giây) — không xoá dòng nào.
        if ($cur && $st === 'pending') {
            $pdo->prepare("UPDATE attendance_keys SET EndTime = DATE_ADD(StartTime, INTERVAL 1 SECOND) WHERE KeyID = :id")->execute(['id' => $cur['KeyID']]);
        }
        if ($cur && $st === 'active') {
            $pdo->prepare("UPDATE attendance_keys SET Status = 'CLOSED' WHERE KeyID = :id")->execute(['id' => $cur['KeyID']]);
        }
        // Tránh trùng cả mã đang mở lẫn mã tham quan đang chờ của buổi khác.
        $taken = [];
        foreach ($pdo->query("SELECT Code FROM attendance_keys WHERE Status = 'OPEN' OR (KeyID LIKE 'KTQ\\_%' AND StartTime = EndTime)")->fetchAll() as $r) $taken[strtoupper((string) $r['Code'])] = true;
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < 60 && $code === null; $i++) {
            $c = ''; for ($j = 0; $j < $length; $j++) $c .= $alphabet[random_int(0, $max)];
            if (!isset($taken[$c])) $code = $c;
        }
        if ($code === null) throw new RuntimeException('Không sinh được mã mới. Thử lại sau ít phút.');
        $now = db_now();
        $pdo->prepare("INSERT INTO attendance_keys (KeyID, SessionID, Code, StartTime, LateAfter, EndTime, Status, CreatedBy) VALUES (:id, :sid, :code, :t, :t2, :t3, 'CLOSED', :by)")
            ->execute(['id' => $id, 'sid' => $s['SessionID'], 'code' => $code, 't' => $now, 't2' => $now, 't3' => $now, 'by' => $me['userId']]);
    });
    log_audit($me['userId'], $me['role'], 'GTN_TQ_CREATE', 'SESSION', (string) $s['SessionID'], ['code' => $code, 'renew' => $renew]);
    api_ok(['tq' => gtn_tq_public(gtn_tq_latest((string) $s['SessionID'])), 'reused' => false]);
}

/** POST gtnTqActivate {token, sessionId, lateMinutes?} — kích hoạt mã chờ: hiệu lực 24 giờ kể từ bây giờ. */
function action_gtn_tq_activate(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $s = gtn_tq_session($me, trim((string) ($params['sessionId'] ?? '')));
    $cur = gtn_tq_latest((string) $s['SessionID']);
    $st = gtn_tq_state($cur);
    if ($st === 'active') { api_ok(['tq' => gtn_tq_public($cur), 'already' => true]); return; }
    if ($st !== 'pending') { api_fail('Buổi này chưa có mã tham quan đang chờ. Bấm “Tạo mã” trước.'); return; }
    $late = (int) ($params['lateMinutes'] ?? 60);
    $late = max(1, min(GTN_TQ_HOURS * 60, $late));
    $dup = db()->prepare("SELECT COUNT(*) FROM attendance_keys WHERE Code = :c AND Status = 'OPEN' AND EndTime > NOW() AND KeyID <> :id");
    $dup->execute(['c' => $cur['Code'], 'id' => $cur['KeyID']]);
    if ((int) $dup->fetchColumn() > 0) { api_fail('Mã ' . $cur['Code'] . ' đang trùng với một mã điểm danh khác đang mở. Bấm “Đổi mã”, in lại rồi kích hoạt.'); return; }
    $now = new DateTimeImmutable(db_now());
    $start = $now->format('Y-m-d H:i:s');
    $lateAt = $now->modify('+' . $late . ' minutes')->format('Y-m-d H:i:s');
    $end = $now->modify('+' . GTN_TQ_HOURS . ' hours')->format('Y-m-d H:i:s');
    db_transaction(function (PDO $pdo) use ($s, $cur, $start, $lateAt, $end): void {
        $pdo->prepare("UPDATE attendance_keys SET Status = 'CLOSED' WHERE SessionID = :sid AND Status = 'OPEN' AND KeyID <> :id")
            ->execute(['sid' => $s['SessionID'], 'id' => $cur['KeyID']]);
        $pdo->prepare("UPDATE attendance_keys SET Status = 'OPEN', StartTime = :a, LateAfter = :l, EndTime = :e WHERE KeyID = :id")
            ->execute(['a' => $start, 'l' => $lateAt, 'e' => $end, 'id' => $cur['KeyID']]);
    });
    log_audit($me['userId'], $me['role'], 'GTN_TQ_ACTIVATE', 'SESSION', (string) $s['SessionID'], ['code' => $cur['Code'], 'endTime' => $end, 'lateMinutes' => $late]);
    api_ok(['tq' => gtn_tq_public(gtn_tq_latest((string) $s['SessionID'])), 'already' => false]);
}

/** POST gtnTqStop {token, sessionId} — dừng sớm mã tham quan đang chạy (không xoá; sinh viên không đăng nhập mới được nữa). */
function action_gtn_tq_stop(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $s = gtn_tq_session($me, trim((string) ($params['sessionId'] ?? '')));
    $cur = gtn_tq_latest((string) $s['SessionID']);
    if (gtn_tq_state($cur) !== 'active') { api_fail('Không có mã tham quan đang chạy.'); return; }
    db()->prepare("UPDATE attendance_keys SET Status = 'CLOSED' WHERE KeyID = :id")->execute(['id' => $cur['KeyID']]);
    log_audit($me['userId'], $me['role'], 'GTN_TQ_STOP', 'SESSION', (string) $s['SessionID'], ['code' => $cur['Code']]);
    api_ok(['tq' => gtn_tq_public(gtn_tq_latest((string) $s['SessionID']))]);
}
