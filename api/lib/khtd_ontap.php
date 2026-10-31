<?php
/**
 * api/lib/khtd_ontap.php — Ôn bài ở nhà cho phiếu online (docs/04 mục 40).
 *
 *  - MÃ ÔN TẬP của lớp: giảng viên tạo ở trang Duyệt phiếu (6 ký tự, hạn 1–180 ngày, thu hồi được). Lưu trong
 *    audit_log (Action 'khtdReviewCode', TargetType 'khtd_review', TargetID = ClassID; dòng mới nhất là hiệu lực)
 *    → không thêm bảng, không migration.
 *  - SV đăng nhập ở nhà bằng MSSV + mã ôn tập (khtdReviewLogin) → token Kind 'GRADE' (đã có trong ENUM; trang phiếu
 *    vốn chấp nhận GRADE nhưng chưa dùng) = CHẾ ĐỘ ÔN TẬP. Token KHTD (mã buổi học trên lớp) = làm phiếu như cũ.
 *  - Chế độ ôn tập: phiếu làm trên lớp CHỈ XEM LẠI (bài đã nộp, ảnh mẫu, điểm, nhận xét, chỗ cần sửa; đối chiếu đáp án
 *    theo quy tắc cũ — sau khi GV duyệt và hết lượt). Không lưu / nộp / tải ảnh / làm lại (khtd_review_guard).
 *    Phiếu ÔN TẬP (schema 'on_tap' => true, vd Đ&KV số 11–15) làm được ở cả hai chế độ: không giới hạn lượt,
 *    KHÔNG tính điểm (không vào CSV điểm phiếu), nộp xong thấy ngay đáp án tham khảo.
 */

const KHTD_REVIEW_CODE_LEN = 6;
const KHTD_REVIEW_TTL = 4 * 3600;   // phiên ôn tập 4 giờ

function khtd_is_ontap(array $schema): bool { return !empty($schema['on_tap']); }

/** Mã ôn tập hiện hành của lớp: ['code','expiresAt','active'] hoặc null. */
function khtd_review_code_current(string $classId): ?array
{
    $stmt = db()->prepare("SELECT `Time`, `Data` FROM audit_log WHERE TargetType = 'khtd_review' AND TargetID = :c ORDER BY `Time` DESC LIMIT 20");
    $stmt->execute(['c' => $classId]);
    $best = null; $bestKey = null;
    foreach ($stmt->fetchAll() as $r) {
        $d = json_decode((string) $r['Data'], true) ?: [];
        $k = [(string) $r['Time'], (float) ($d['ts'] ?? 0)];
        if ($bestKey === null || $k > $bestKey) { $bestKey = $k; $best = $d; }
    }
    if (!$best || !empty($best['revoked']) || empty($best['code'])) return null;
    $exp = (string) ($best['expiresAt'] ?? '');
    return ['code' => (string) $best['code'], 'expiresAt' => $exp, 'active' => $exp !== '' && strtotime($exp) > time()];
}

/** Chặn ghi khi đang ở chế độ ôn tập mà phiếu không phải phiếu ôn tập. Trả true nếu được phép. */
function khtd_review_guard(array $me, array $ws): bool
{
    if (empty($me['review']) || khtd_is_ontap($ws['schema'])) return true;
    api_fail('Bạn đang ở chế độ ôn tập ở nhà: phiếu làm trên lớp chỉ xem lại được, không sửa hay nộp. Hãy làm các phiếu Ôn tập.');
    return false;
}

/** POST khtdReviewCode {token, classId, op: get|create|revoke, days?} — giảng viên quản lý mã ôn tập của lớp. */
function action_khtd_review_code(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $classId = trim((string) ($params['classId'] ?? ''));
    assert_class_access($me, $classId);
    $op = (string) ($params['op'] ?? 'get');
    $uid = (string) ($me['userId'] ?? ($me['UserID'] ?? ''));
    if ($op === 'create') {
        $days = max(1, min(180, (int) ($params['days'] ?? 30)));
        $alpha = (string) (app_config()['app']['code_alphabet'] ?? 'ACDEFGHJKMNPQRTUVWXY34679');
        $code = '';
        for ($i = 0; $i < KHTD_REVIEW_CODE_LEN; $i++) $code .= $alpha[random_int(0, strlen($alpha) - 1)];
        $exp = date('Y-m-d 23:59:59', time() + $days * 86400);
        log_audit($uid, 'LECTURER', 'khtdReviewCode', 'khtd_review', $classId, ['code' => $code, 'expiresAt' => $exp, 'days' => $days, 'ts' => microtime(true)]);
    } elseif ($op === 'revoke') {
        log_audit($uid, 'LECTURER', 'khtdReviewCode', 'khtd_review', $classId, ['revoked' => true, 'ts' => microtime(true)]);
    } elseif ($op !== 'get') {
        api_fail('Thao tác không hợp lệ.'); return;
    }
    api_ok(['review' => khtd_review_code_current($classId)]);
}

/** POST khtdReviewLogin {mssv, code} — SV vào chế độ ôn tập ở nhà bằng MSSV + mã ôn tập 6 ký tự của lớp. */
function action_khtd_review_login(array $params): void
{
    $ip = rate_limit_client_ip();
    rate_limit_guard('khtd_login_ip', $ip);
    $mssv = strtoupper(trim((string) ($params['mssv'] ?? '')));
    $code = strtoupper(preg_replace('/\s+/', '', (string) ($params['code'] ?? '')));
    $bad = static function (string $m) use ($ip): void { rate_limit_record('khtd_login_ip', $ip); api_fail($m); };
    if (!preg_match('/^[0-9]{6,10}$/', $mssv)) { $bad('MSSV không hợp lệ (phải là 6–10 chữ số).'); return; }
    if (!preg_match('/^[A-Z0-9]{' . KHTD_REVIEW_CODE_LEN . '}$/', $code)) { $bad('Mã ôn tập gồm ' . KHTD_REVIEW_CODE_LEN . ' ký tự.'); return; }
    $st = db()->prepare("SELECT * FROM students WHERE MSSV = :m AND Status = 'ACTIVE' LIMIT 1");
    $st->execute(['m' => $mssv]);
    $student = $st->fetch();
    $classId = null;
    if ($student) {
        $cl = db()->prepare("SELECT ClassID FROM enrollments WHERE StudentID = :s AND Status = 'ACTIVE'");
        $cl->execute(['s' => $student['StudentID']]);
        foreach ($cl->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $cur = khtd_review_code_current((string) $cid);
            if ($cur && $cur['active'] && hash_equals($cur['code'], $code)) { $classId = (string) $cid; break; }
        }
    }
    if (!$classId) { $bad('MSSV hoặc mã ôn tập không đúng, hoặc mã đã hết hạn. Hỏi giảng viên mã ôn tập của lớp.'); return; }
    $token = issue_token('GRADE', (string) $student['StudentID'], KHTD_REVIEW_TTL);
    log_audit((string) $student['StudentID'], 'STUDENT', 'khtdReviewLogin', 'class', $classId, null);
    api_ok(['token' => $token, 'fullName' => $student['FullName'], 'mssv' => $student['MSSV'], 'email' => $student['Email'] ?? '',
            'classId' => $classId, 'review' => true, 'expiresIn' => KHTD_REVIEW_TTL]);
}

/** Đáp án tham khảo của phiếu ôn tập (hiện cho SV sau khi nộp): [{text, answer}]. */
function khtd_ontap_key(array $schema): array
{
    $key = (array) ($schema['answer_key'] ?? []);
    $out = [];
    foreach ($schema['sections'] ?? [] as $s) {
        if (($s['type'] ?? '') !== 'questions') continue;
        foreach ($s['items'] ?? [] as $q) $out[] = ['text' => (string) $q['text'], 'answer' => (string) ($key[$q['id']] ?? '')];
    }
    return $out;
}
