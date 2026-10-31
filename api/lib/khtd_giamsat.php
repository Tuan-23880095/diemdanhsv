<?php
/**
 * api/lib/khtd_giamsat.php — Giám sát chống gian lận cho phiếu online (docs/04 mục 39).
 *
 *  - Phiếu bật giám sát khi SchemaJSON có 'giam_sat' => ['max' => 3, 'blur_grace_sec' => 3] (vd phiếu thử Đ&KV số 6).
 *  - Trình duyệt (js/ui/KhtdGiamSat.js) báo sự kiện: rời trang, mất tiêu điểm lâu, chia đôi / thu nhỏ màn hình,
 *    sao chép / cắt / dán, phím công cụ nhà phát triển, PrintScreen. Lúc SV bấm "Chụp ảnh" / "Chọn ảnh" mẫu thì
 *    tạm ngưng theo dõi (mở camera là rời trang hợp lệ). Logic phát hiện lấy từ KIEMTRA (kiemtra/assets/sv.js).
 *  - Đủ 'max' lần vi phạm → KHOÁ bài: máy chủ từ chối lưu nháp / nộp / tải ảnh. Giảng viên đến bàn, nhập PIN 6 số
 *    của phiếu trên máy SV (khtdGsUnlock) → mở, cho thêm 1 lượt (ngưỡng = số vi phạm lúc mở + 1); vi phạm tiếp lại khoá (giống KIEMTRA).
 *    PIN = HMAC(WorksheetID) với khoá lấy từ config.php (không lưu, không có trong Git); GV xem ở trang Duyệt phiếu.
 *  - Sự kiện ghi vào audit_log (Action 'khtdGiamSat', TargetType 'khtd_gs', TargetID = WorksheetID, Actor = StudentID)
 *    → không thêm bảng, không migration, không đụng AnswersJSON (không tranh ghi với lưu nháp).
 *  - Tín hiệu từ trình duyệt có thể bị tắt hoặc giả; đây là công cụ hỗ trợ giám thị, không phải bằng chứng duy nhất.
 */

const KHTD_GS_COUNTED = ['hidden', 'blur', 'split', 'copy', 'cut', 'paste', 'devtools', 'printscreen'];
const KHTD_GS_CLIENT_INFO = ['start', 'back', 'photo', 'offline', 'online', 'stop'];   // trình duyệt được báo
const KHTD_GS_SERVER_INFO = ['unlock', 'pin_fail'];                                    // chỉ máy chủ ghi
const KHTD_GS_MAX_EVENTS = 400;      // chặn spam: tối đa số sự kiện / SV / phiếu
const KHTD_GS_PIN_TRIES = 5;         // nhập sai PIN tối đa / 10 phút / SV / phiếu

/** Cấu hình giám sát của phiếu, null nếu phiếu không bật. */
function khtd_giamsat_cfg(array $schema): ?array
{
    $g = $schema['giam_sat'] ?? null;
    if (!is_array($g)) return null;
    return [
        'max'          => max(1, min(20, (int) ($g['max'] ?? 3))),
        'blurGraceSec' => max(0, min(30, (int) ($g['blur_grace_sec'] ?? 3))),
    ];
}

/** PIN giám thị 6 số của một phiếu (ổn định, không lưu; khoá bí mật lấy từ config.php). */
function khtd_giamsat_pin(string $worksheetId): string
{
    $cfg = app_config();
    $key = (string) ($cfg['app']['giamsat_secret'] ?? '');
    if ($key === '') $key = 'gs|' . (string) ($cfg['db']['pass'] ?? '') . '|' . (string) ($cfg['db']['name'] ?? '') . '|' . (string) ($cfg['gemini']['api_key'] ?? '');
    $h = hash_hmac('sha256', 'khtd_gs_pin|' . $worksheetId, $key);
    return str_pad((string) (hexdec(substr($h, 0, 8)) % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Nhật ký sự kiện của một SV trên một phiếu, cũ → mới. */
function khtd_giamsat_events(string $worksheetId, string $studentId): array
{
    $stmt = db()->prepare("SELECT `Time`, `Data` FROM audit_log WHERE TargetType = 'khtd_gs' AND TargetID = :w AND Actor = :s");
    $stmt->execute(['w' => $worksheetId, 's' => $studentId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $d = json_decode((string) $r['Data'], true) ?: [];
        $out[] = ['at' => (string) $r['Time'], 'ts' => (float) ($d['ts'] ?? 0), 'type' => (string) ($d['type'] ?? ''), 'detail' => (string) ($d['detail'] ?? ''),
                  'attemptNo' => (int) ($d['attemptNo'] ?? 0), 'counted' => in_array($d['type'] ?? '', KHTD_GS_COUNTED, true)];
    }
    // `Time` chỉ chính xác tới giây → xếp theo dấu thời gian micro giây ghi trong Data.
    usort($out, static fn ($a, $b) => [$a['at'], $a['ts']] <=> [$b['at'], $b['ts']]);
    foreach ($out as &$e) unset($e['ts']);
    unset($e);
    return $out;
}

/** Trạng thái giám sát của một SV: số vi phạm, số lần mở khoá, ngưỡng hiện tại, có đang khoá không. */
function khtd_giamsat_state(string $worksheetId, string $studentId, array $cfg, ?array $events = null): array
{
    $ev = $events ?? khtd_giamsat_events($worksheetId, $studentId);
    // Mỗi lần mở khoá: ngưỡng mới = số vi phạm lúc mở + 1 (vi phạm phát sinh trong lúc đang khoá không cộng dồn).
    $v = 0; $u = 0; $limit = $cfg['max'];
    foreach ($ev as $e) {
        if ($e['counted']) $v++;
        elseif ($e['type'] === 'unlock') { $u++; $limit = $v + 1; }
    }
    return ['violations' => $v, 'unlocks' => $u, 'limit' => $limit, 'max' => $cfg['max'], 'locked' => $v >= $limit];
}

/** Số vi phạm của cả lớp: khoá "WorksheetID|StudentID" → số lần. */
function khtd_giamsat_class_counts(string $classId): array
{
    $in = "'" . implode("','", KHTD_GS_COUNTED) . "'";
    $stmt = db()->prepare(
        "SELECT a.TargetID, a.Actor, COUNT(*) AS n FROM audit_log a JOIN khtd_worksheets w ON w.WorksheetID = a.TargetID " .
        "WHERE a.TargetType = 'khtd_gs' AND w.ClassID = :c AND JSON_UNQUOTE(JSON_EXTRACT(a.`Data`, '$.type')) IN ($in) GROUP BY a.TargetID, a.Actor"
    );
    $stmt->execute(['c' => $classId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) $out[$r['TargetID'] . '|' . $r['Actor']] = (int) $r['n'];
    return $out;
}

/** Lần làm mới nhất của SV trên phiếu (null nếu chưa có). */
function khtd_giamsat_latest(array $ws, string $studentId): ?array
{
    $sum = khtd_attempt_summary($ws, khtd_student_attempts($ws['WorksheetID'], $studentId), khtd_extra_attempts($ws['WorksheetID'], $studentId));
    return $sum['latest'] ?: null;
}

function khtd_giamsat_log(string $studentId, string $worksheetId, string $type, string $detail, ?array $latest): void
{
    $cnt = db()->prepare("SELECT COUNT(*) FROM audit_log WHERE TargetType = 'khtd_gs' AND TargetID = :w AND Actor = :s");
    $cnt->execute(['w' => $worksheetId, 's' => $studentId]);
    if ((int) $cnt->fetchColumn() >= KHTD_GS_MAX_EVENTS && !in_array($type, KHTD_GS_SERVER_INFO, true)) return;
    log_audit($studentId, 'STUDENT', 'khtdGiamSat', 'khtd_gs', $worksheetId,
        ['type' => $type, 'detail' => $detail, 'attemptNo' => $latest ? (int) $latest['AttemptNo'] : 1, 'ts' => microtime(true)]);
}

/**
 * Chặn ghi khi bài đang khoá (gọi ở lưu nháp / nộp / tải ảnh). Trả true nếu được phép; false thì đã api_fail.
 */
function khtd_giamsat_guard(array $ws, string $studentId): bool
{
    $cfg = khtd_giamsat_cfg($ws['schema']);
    if (!$cfg) return true;
    $st = khtd_giamsat_state($ws['WorksheetID'], $studentId, $cfg);
    if (!$st['locked']) return true;
    api_fail('Bài đang bị khoá do vi phạm giám sát ' . $st['violations'] . ' lần. Giơ tay gọi giảng viên nhập mã PIN để mở khoá.');
    return false;
}

/** POST khtdLogEvent {token, worksheetId, type, detail?} — SV báo một sự kiện giám sát. Trả trạng thái (có khoá không). */
function action_khtd_log_event(array $params): void
{
    $me = khtd_require_student((string) ($params['token'] ?? ''));
    $ws = khtd_worksheet((string) ($params['worksheetId'] ?? ''));
    khtd_assert_enrolled($me['studentId'], (string) $ws['ClassID']);
    $cfg = khtd_giamsat_cfg($ws['schema']);
    if (!$cfg) { api_fail('Phiếu này không bật giám sát.'); return; }
    if (!empty($me['review'])) { api_fail('Chế độ ôn tập ở nhà không làm phiếu có giám sát.'); return; }
    $type = trim((string) ($params['type'] ?? ''));
    if (!in_array($type, KHTD_GS_COUNTED, true) && !in_array($type, KHTD_GS_CLIENT_INFO, true)) { api_fail('Loại sự kiện không hợp lệ.'); return; }
    $detail = mb_substr(trim((string) ($params['detail'] ?? '')), 0, 160);
    $latest = khtd_giamsat_latest($ws, $me['studentId']);
    // Chỉ ghi khi đang làm (chưa có lần nào, hoặc lần mới nhất còn là nháp) — đã nộp thì bỏ qua lặng lẽ.
    if ($latest && $latest['Status'] !== 'DRAFT') {
        api_ok(khtd_giamsat_state($ws['WorksheetID'], $me['studentId'], $cfg) + ['ignored' => true]);
        return;
    }
    khtd_giamsat_log($me['studentId'], $ws['WorksheetID'], $type, $detail, $latest);
    api_ok(khtd_giamsat_state($ws['WorksheetID'], $me['studentId'], $cfg));
}

/** POST khtdGsUnlock {token, worksheetId, pin} — giảng viên nhập PIN trên máy SV để mở khoá (+1 lượt vi phạm). */
function action_khtd_gs_unlock(array $params): void
{
    $me = khtd_require_student((string) ($params['token'] ?? ''));
    $ws = khtd_worksheet((string) ($params['worksheetId'] ?? ''));
    khtd_assert_enrolled($me['studentId'], (string) $ws['ClassID']);
    $cfg = khtd_giamsat_cfg($ws['schema']);
    if (!$cfg) { api_fail('Phiếu này không bật giám sát.'); return; }
    if (!empty($me['review'])) { api_fail('Chế độ ôn tập ở nhà không làm phiếu có giám sát.'); return; }
    $st = khtd_giamsat_state($ws['WorksheetID'], $me['studentId'], $cfg);
    if (!$st['locked']) { api_ok($st); return; }
    $fails = db()->prepare("SELECT COUNT(*) FROM audit_log WHERE TargetType = 'khtd_gs' AND TargetID = :w AND Actor = :s AND `Time` > NOW() - INTERVAL 10 MINUTE AND JSON_UNQUOTE(JSON_EXTRACT(`Data`, '$.type')) = 'pin_fail'");
    $fails->execute(['w' => $ws['WorksheetID'], 's' => $me['studentId']]);
    if ((int) $fails->fetchColumn() >= KHTD_GS_PIN_TRIES) { api_fail('Nhập sai mã PIN quá ' . KHTD_GS_PIN_TRIES . ' lần — chờ 10 phút rồi thử lại.'); return; }
    $pin = preg_replace('/\D/', '', (string) ($params['pin'] ?? ''));
    $latest = khtd_giamsat_latest($ws, $me['studentId']);
    if (!hash_equals(khtd_giamsat_pin($ws['WorksheetID']), (string) $pin)) {
        khtd_giamsat_log($me['studentId'], $ws['WorksheetID'], 'pin_fail', 'Nhập sai mã PIN', $latest);
        api_fail('Mã PIN không đúng.');
        return;
    }
    khtd_giamsat_log($me['studentId'], $ws['WorksheetID'], 'unlock', 'Giảng viên mở khoá bằng PIN sau ' . $st['violations'] . ' vi phạm', $latest);
    api_ok(khtd_giamsat_state($ws['WorksheetID'], $me['studentId'], $cfg));
}
