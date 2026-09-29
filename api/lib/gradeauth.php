<?php
declare(strict_types=1);

/**
 * api/lib/gradeauth.php — requestGradeCode/verifyGradeCode (gas/08-GradeService.gs
 * GradeAuth). Đối chiếu đầy đủ tại docs/04-API-PHP.md mục 5, mục 6, mục 10.1.
 *
 * Thay CacheService bằng bảng grade_codes (db/schema.sql, bổ sung 3 cột
 * SendCount/WindowStartAt/LastSentAt ở GĐ3 — db/migrations/002-...sql cho
 * CSDL đã tồn tại trên host thật). Một dòng/sinh viên, KHÔNG xoá dòng khi
 * mã hết hạn hay đã dùng — chỉ vô hiệu hoá (invalidate_grade_code) để giữ
 * lại SendCount/WindowStartAt cho giới hạn 5 lần/24h cuộn.
 *
 * SMTP THẬT CHƯA CÓ (docs mục 10.4) — mail_send() (api/lib/mailer.php) là
 * STUB, chỉ ghi log. Không có API kiểm "quota gửi mail còn lại" như
 * MailApp.getRemainingDailyQuota() cũ nên bỏ qua bước đó cho tới khi có
 * SMTP thật.
 */

const GRADE_CODE_TTL_SECONDS    = 600;  // mã sống 10 phút
const GRADE_TOKEN_TTL_SECONDS   = 1800; // token xem điểm sống 30 phút
const GRADE_MAX_ATTEMPTS        = 5;    // sai quá số này thì mã bị huỷ
const GRADE_MAX_SENDS_PER_DAY   = 5;    // số lần xin mã tối đa / 24h (cuộn)
const GRADE_RESEND_COOLDOWN_SEC = 60;   // trong khoảng này thì không gửi lại

const GRADE_CODE_NEUTRAL_MESSAGE =
    'Nếu mã số sinh viên đúng, mã xác minh đã được gửi tới email ' .
    'của bạn. Kiểm tra cả hộp thư rác. Mã có hiệu lực 10 phút.';

/**
 * requestGradeCode — POST. Trả THÔNG BÁO TRUNG LẬP trong hầu hết trường
 * hợp — không tiết lộ MSSV có tồn tại hay không (đúng gas/08-GradeService.gs).
 * Hai trường hợp DUY NHẤT trả status:'error' thật: xin quá nhiều lần/24h.
 */
function action_request_grade_code(array $params): void
{
    $mssv = trim((string) ($params['mssv'] ?? ''));
    $neutral = ['message' => GRADE_CODE_NEUTRAL_MESSAGE];

    if (!preg_match('/^[0-9]{6,10}$/', $mssv)) {
        api_ok($neutral);
        return;
    }

    $stmt = db()->prepare("SELECT * FROM students WHERE MSSV = :m AND Status = 'ACTIVE' LIMIT 1");
    $stmt->execute(['m' => $mssv]);
    $student = $stmt->fetch();
    if (!$student) {
        api_ok($neutral);
        return;
    }

    $email = trim((string) ($student['Email'] ?? ''));
    if ($email === '' || !str_contains($email, '@')) {
        api_ok($neutral);
        return;
    }

    $stmt = db()->prepare('SELECT SendCount, WindowStartAt, LastSentAt FROM grade_codes WHERE StudentID = :id');
    $stmt->execute(['id' => $student['StudentID']]);
    $row = $stmt->fetch();

    $now = new DateTimeImmutable(db_now());

    // Cooldown 60s — vừa gửi xong thì không gửi lại, tiết kiệm quota mail
    // (đúng cơ chế 'gsent_' cũ; xem gas/08-GradeService.gs).
    if ($row && $row['LastSentAt'] !== null) {
        $secsSinceSent = $now->getTimestamp() - (new DateTimeImmutable($row['LastSentAt']))->getTimestamp();
        if ($secsSinceSent < GRADE_RESEND_COOLDOWN_SEC) {
            api_ok($neutral);
            return;
        }
    }

    // Cửa sổ 24h CUỘN (không theo ngày lịch) — đúng 'gcount_' cũ (TTL 86400s).
    $windowValid = false;
    if ($row && $row['WindowStartAt'] !== null) {
        $secsSinceWindow = $now->getTimestamp() - (new DateTimeImmutable($row['WindowStartAt']))->getTimestamp();
        $windowValid = $secsSinceWindow < 86400;
    }
    $sentToday = $windowValid ? (int) $row['SendCount'] : 0;

    if ($sentToday >= GRADE_MAX_SENDS_PER_DAY) {
        api_fail('Bạn đã xin mã quá nhiều lần hôm nay. Vui lòng thử lại sau hoặc liên hệ giảng viên.');
        return;
    }

    $appCfg = app_config()['app'] ?? [];
    $code = generate_grade_code(
        (string) ($appCfg['code_alphabet'] ?? 'ACDEFGHJKMNPQRTUVWXY34679'),
        (int) ($appCfg['code_length'] ?? 4)
    );
    $codeHash = hash('sha256', $code);
    $windowStart = $windowValid ? $row['WindowStartAt'] : $now->format('Y-m-d H:i:s');
    $newSendCount = $sentToday + 1;

    db_transaction(function (PDO $pdo) use ($student, $codeHash, $newSendCount, $windowStart): void {
        $stmt = $pdo->prepare(
            'INSERT INTO grade_codes (StudentID, CodeHash, Attempts, ExpiresAt, SendCount, WindowStartAt, LastSentAt) ' .
            'VALUES (:id, :hash, 0, DATE_ADD(NOW(), INTERVAL :ttl SECOND), :sendcount, :window, NOW()) ' .
            'ON DUPLICATE KEY UPDATE ' .
            'CodeHash = VALUES(CodeHash), Attempts = 0, ExpiresAt = VALUES(ExpiresAt), ' .
            'SendCount = VALUES(SendCount), WindowStartAt = VALUES(WindowStartAt), LastSentAt = VALUES(LastSentAt)'
        );
        $stmt->execute([
            'id'        => $student['StudentID'],
            'hash'      => $codeHash,
            'ttl'       => GRADE_CODE_TTL_SECONDS,
            'sendcount' => $newSendCount,
            'window'    => $windowStart,
        ]);
    });

    mail_send(
        $email,
        'Mã xác minh xem điểm — Hệ thống điểm danh & xem điểm',
        implode("\n", [
            'Chào ' . $student['FullName'] . ',',
            '',
            'Mã xác minh để xem điểm của bạn là:  ' . $code,
            '',
            'Mã có hiệu lực trong 10 phút và chỉ dùng được một lần.',
            '',
            'Nếu bạn không yêu cầu xem điểm, hãy bỏ qua email này — ' .
            'không ai xem được điểm của bạn nếu không có mã trên.',
        ])
    );

    log_audit(
        (string) $student['StudentID'],
        'STUDENT',
        'GRADE_CODE_SENT',
        'STUDENT',
        (string) $student['StudentID'],
        ['mssv' => $mssv]
    );

    api_ok($neutral);
}

/**
 * verifyGradeCode — POST. Đúng thì cấp token xem điểm (Kind='GRADE', 30
 * phút). Sai quá GRADE_MAX_ATTEMPTS lần thì huỷ mã, buộc xin mã mới.
 */
function action_verify_grade_code(array $params): void
{
    $mssv = trim((string) ($params['mssv'] ?? ''));
    $code = strtoupper(trim((string) ($params['code'] ?? '')));
    $expiredMsg = 'Mã đã hết hạn hoặc chưa được gửi. Hãy xin mã mới.';

    $stmt = db()->prepare("SELECT StudentID, FullName, MSSV FROM students WHERE MSSV = :m AND Status = 'ACTIVE' LIMIT 1");
    $stmt->execute(['m' => $mssv]);
    $student = $stmt->fetch();
    if (!$student) {
        api_fail($expiredMsg);
        return;
    }

    $stmt = db()->prepare('SELECT CodeHash, Attempts, ExpiresAt FROM grade_codes WHERE StudentID = :id');
    $stmt->execute(['id' => $student['StudentID']]);
    $row = $stmt->fetch();

    if (!$row || $row['CodeHash'] === '') {
        api_fail($expiredMsg);
        return;
    }

    $now = new DateTimeImmutable(db_now());
    if (new DateTimeImmutable($row['ExpiresAt']) <= $now) {
        api_fail($expiredMsg);
        return;
    }

    $attempts = (int) $row['Attempts'];
    if ($attempts >= GRADE_MAX_ATTEMPTS) {
        invalidate_grade_code((string) $student['StudentID']);
        api_fail('Nhập sai quá nhiều lần. Mã đã bị huỷ, hãy xin mã mới.');
        return;
    }

    if (hash('sha256', $code) !== $row['CodeHash']) {
        $stmt = db()->prepare('UPDATE grade_codes SET Attempts = :a WHERE StudentID = :id');
        $stmt->execute(['a' => $attempts + 1, 'id' => $student['StudentID']]);
        $remaining = GRADE_MAX_ATTEMPTS - $attempts - 1;
        api_fail('Mã không đúng. Còn ' . $remaining . ' lần thử.');
        return;
    }

    // Mã dùng một lần. GIỮ SendCount/WindowStartAt/LastSentAt (không đụng ở
    // đây) để không reset giới hạn 24h — khác hai cache key riêng của bản cũ.
    invalidate_grade_code((string) $student['StudentID']);

    $token = issue_token('GRADE', (string) $student['StudentID'], GRADE_TOKEN_TTL_SECONDS);
    log_audit((string) $student['StudentID'], 'STUDENT', 'GRADE_LOGIN', 'STUDENT', (string) $student['StudentID']);

    api_ok([
        'token'    => $token,
        'mssv'     => $student['MSSV'],
        'fullName' => $student['FullName'],
    ]);
}

/** Vô hiệu hoá mã hiện tại của một sinh viên — không xoá dòng (mục docblock trên). */
function invalidate_grade_code(string $studentId): void
{
    $stmt = db()->prepare(
        "UPDATE grade_codes SET CodeHash = '', Attempts = 0, ExpiresAt = '1970-01-01 00:00:00' " .
        'WHERE StudentID = :id'
    );
    $stmt->execute(['id' => $studentId]);
}

/** Mã 4 ký tự, cùng bộ ký tự dễ đọc với mã điểm danh (generateGradeCode_ cũ). */
function generate_grade_code(string $alphabet, int $length): string
{
    $max = strlen($alphabet) - 1;
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, $max)];
    }
    return $code;
}
