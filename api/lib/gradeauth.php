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
 * Email gửi qua mail_send() (api/lib/mailer.php, SMTP PHP thuần — docs mục
 * 10.4): trả true/false, không ném lỗi. Chưa có hộp thư trong config.php thì
 * nó ở chế độ stub (chỉ ghi log) và trả false → audit GRADE_CODE_MAIL_FAIL.
 * Không có API kiểm "quota gửi mail còn lại" như MailApp.getRemainingDailyQuota()
 * cũ — Hostinger giới hạn theo hộp thư, xem docs mục 10.4.
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

    // M4 (api/lib/ratelimit.php, docs/04 mục 19): đếm MỌI lượt theo IP — mỗi
    // lượt hợp lệ là một email gửi đi; giới hạn 5 lần/24h theo SV bên dưới
    // không chặn được một IP quét hàng nghìn MSSV để dội thư cả trường.
    $ip = rate_limit_client_ip();
    rate_limit_guard('gradecode_req_ip', $ip);
    rate_limit_record('gradecode_req_ip', $ip);

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

    $appCfg = app_config()['app'] ?? [];

    // GĐ5 review bảo mật (docs/05-GD5-smoke-review.md, H1): kiểm cooldown /
    // giới hạn 5 lần/24h VÀ ghi mã mới trong CÙNG một transaction, khoá dòng
    // students của SV này (SELECT ... FOR UPDATE) để các request song song
    // của cùng một MSSV phải xếp hàng — trước đây đọc-rồi-ghi tách rời nên
    // bắn N request cùng lúc sẽ vượt giới hạn gửi (và mỗi mã mới lại reset
    // Attempts=0). Khoá theo dòng students (luôn tồn tại) thay vì grade_codes
    // (lần xin mã đầu tiên chưa có dòng để khoá).
    $outcome = db_transaction(function (PDO $pdo) use ($student, $appCfg): array {
        $pdo->prepare('SELECT StudentID FROM students WHERE StudentID = :id FOR UPDATE')
            ->execute(['id' => $student['StudentID']]);

        $stmt = $pdo->prepare('SELECT SendCount, WindowStartAt, LastSentAt FROM grade_codes WHERE StudentID = :id FOR UPDATE');
        $stmt->execute(['id' => $student['StudentID']]);
        $row = $stmt->fetch();

        $now = new DateTimeImmutable(db_now());

        // Cooldown 60s — vừa gửi xong thì không gửi lại, tiết kiệm quota mail
        // (đúng cơ chế 'gsent_' cũ; xem gas/08-GradeService.gs).
        if ($row && $row['LastSentAt'] !== null) {
            $secsSinceSent = $now->getTimestamp() - (new DateTimeImmutable($row['LastSentAt']))->getTimestamp();
            if ($secsSinceSent < GRADE_RESEND_COOLDOWN_SEC) {
                return ['kind' => 'neutral'];
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
            return ['kind' => 'limit'];
        }

        $code = generate_grade_code(
            (string) ($appCfg['code_alphabet'] ?? 'ACDEFGHJKMNPQRTUVWXY34679'),
            (int) ($appCfg['code_length'] ?? 4)
        );
        $codeHash = hash('sha256', $code);
        $windowStart = $windowValid ? $row['WindowStartAt'] : $now->format('Y-m-d H:i:s');
        $newSendCount = $sentToday + 1;

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

        return ['kind' => 'sent', 'code' => $code];
    });

    // api_ok()/api_fail() gọi exit — chỉ gọi SAU khi transaction đã commit.
    if ($outcome['kind'] === 'neutral') {
        api_ok($neutral);
        return;
    }
    if ($outcome['kind'] === 'limit') {
        api_fail('Bạn đã xin mã quá nhiều lần hôm nay. Vui lòng thử lại sau hoặc liên hệ giảng viên.');
        return;
    }
    $code = (string) $outcome['code'];

    $mailed = mail_send(
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

    // Gửi hỏng (SMTP lỗi hoặc còn chế độ stub): vẫn trả phản hồi trung tính —
    // không lộ MSSV nào có email — nhưng ghi audit GRADE_CODE_MAIL_FAIL để thầy
    // thấy trong audit_log; chi tiết lỗi ở error_log (api/lib/mailer.php).
    log_audit(
        (string) $student['StudentID'],
        'STUDENT',
        $mailed ? 'GRADE_CODE_SENT' : 'GRADE_CODE_MAIL_FAIL',
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

    // M4 (api/lib/ratelimit.php, docs/04 mục 19): theo IP, chỉ đếm lần THẤT
    // BẠI. Giới hạn 5 lần đoán/SV đã có, nhưng một IP vẫn đổi MSSV liên tục để
    // dò xem MSSV nào đang có mã (hoặc em nào tồn tại) — lớp này chặn việc đó.
    $ip = rate_limit_client_ip();
    rate_limit_guard('gradecode_ver_ip', $ip);

    $stmt = db()->prepare("SELECT StudentID, FullName, MSSV FROM students WHERE MSSV = :m AND Status = 'ACTIVE' LIMIT 1");
    $stmt->execute(['m' => $mssv]);
    $student = $stmt->fetch();
    if (!$student) {
        rate_limit_record('gradecode_ver_ip', $ip);
        api_fail($expiredMsg);
        return;
    }

    // GĐ5 review bảo mật (docs/05-GD5-smoke-review.md, H1): toàn bộ bước
    // đọc Attempts → so mã → ghi Attempts/huỷ mã chạy trong MỘT transaction
    // với SELECT ... FOR UPDATE trên dòng grade_codes. Trước đây đọc rồi ghi
    // tách rời → bắn nhiều request song song thì mọi request đều thấy
    // Attempts=0, vượt giới hạn 5 lần đoán (không gian mã chỉ 25^4), và một
    // mã đúng có thể đổi được nhiều token. Transaction chỉ trả kết quả;
    // api_ok()/api_fail() (có exit) gọi SAU khi commit để không mất lượt ghi.
    $studentId = (string) $student['StudentID'];
    $result = db_transaction(function (PDO $pdo) use ($studentId, $code): array {
        $stmt = $pdo->prepare('SELECT CodeHash, Attempts, ExpiresAt FROM grade_codes WHERE StudentID = :id FOR UPDATE');
        $stmt->execute(['id' => $studentId]);
        $row = $stmt->fetch();

        if (!$row || $row['CodeHash'] === '') {
            return ['kind' => 'expired'];
        }

        $now = new DateTimeImmutable(db_now());
        if (new DateTimeImmutable($row['ExpiresAt']) <= $now) {
            return ['kind' => 'expired'];
        }

        $attempts = (int) $row['Attempts'];
        if ($attempts >= GRADE_MAX_ATTEMPTS) {
            invalidate_grade_code($studentId);
            return ['kind' => 'locked'];
        }

        if (!hash_equals((string) $row['CodeHash'], hash('sha256', $code))) {
            $pdo->prepare('UPDATE grade_codes SET Attempts = Attempts + 1 WHERE StudentID = :id')
                ->execute(['id' => $studentId]);
            return ['kind' => 'wrong', 'remaining' => GRADE_MAX_ATTEMPTS - $attempts - 1];
        }

        // Mã dùng một lần. GIỮ SendCount/WindowStartAt/LastSentAt (không đụng
        // ở đây) để không reset giới hạn 24h — khác hai cache key riêng của bản cũ.
        invalidate_grade_code($studentId);
        return ['kind' => 'ok'];
    });

    if ($result['kind'] !== 'ok') {
        // Mọi nhánh thất bại đều tính một lượt theo IP (M4) — ghi SAU commit.
        rate_limit_record('gradecode_ver_ip', $ip);
    }
    if ($result['kind'] === 'expired') {
        api_fail($expiredMsg);
        return;
    }
    if ($result['kind'] === 'locked') {
        api_fail('Nhập sai quá nhiều lần. Mã đã bị huỷ, hãy xin mã mới.');
        return;
    }
    if ($result['kind'] === 'wrong') {
        api_fail('Mã không đúng. Còn ' . $result['remaining'] . ' lần thử.');
        return;
    }

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
