<?php
declare(strict_types=1);

/**
 * api/lib/auth.php — login/logout giảng viên (gas/03-Auth.gs → AuthService),
 * cộng cơ chế rehash mật khẩu cũ. Đối chiếu đầy đủ tại docs/04-API-PHP.md
 * mục 6. Token lưu ở bảng auth_tokens thay CacheService (docs mục 6).
 *
 * requireRole()/assertClassAccess() (đọc token để phân quyền các action GET)
 * CHƯA nằm trong phạm vi GĐ3 — GĐ3 chỉ port login/logout/requestGradeCode/
 * verifyGradeCode (PLAN). Sẽ thêm ở GĐ4 khi cần cho listClasses/liveRoster/
 * openAttendance/closeAttendance, để không nhảy cóc giai đoạn.
 */

const AUTH_TOKEN_TTL_LECTURER = 6 * 3600; // 6 giờ, đúng CacheService cũ

/**
 * Sinh token ngẫu nhiên (64 hex, bin2hex(random_bytes(32))) và lưu vào
 * auth_tokens — thay CacheService.put('tok_'/'gtok_' + uuid, ...) cũ.
 */
function issue_token(string $kind, string $subjectId, int $ttlSeconds): string
{
    $token = bin2hex(random_bytes(32));
    $stmt = db()->prepare(
        'INSERT INTO auth_tokens (Token, Kind, SubjectID, ExpiresAt) ' .
        'VALUES (:token, :kind, :subject, DATE_ADD(NOW(), INTERVAL :ttl SECOND))'
    );
    $stmt->execute([
        'token'   => $token,
        'kind'    => $kind,
        'subject' => $subjectId,
        'ttl'     => $ttlSeconds,
    ]);
    return $token;
}

/** Băm mật khẩu theo kiểu cũ sha256(salt + '|' + plain) hex (gas/03-Auth.gs hashPassword_). */
function legacy_sha256_hash(string $plain, string $salt): string
{
    return hash('sha256', $salt . '|' . $plain);
}

/** true nếu $hash đã là bcrypt/argon2 (đã rehash bằng password_hash() của PHP). */
function is_modern_hash(string $hash): bool
{
    return str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$') ||
           str_starts_with($hash, '$2b$') || str_starts_with($hash, '$argon2i$') ||
           str_starts_with($hash, '$argon2id$');
}

/**
 * login — POST action 'login' (docs/04-API-PHP.md mục 5, mục 6).
 * Trả CÙNG một thông báo cho "sai tên" và "sai mật khẩu" — không tiết lộ
 * tài khoản nào tồn tại (đúng gas/03-Auth.gs).
 */
function action_login(array $params): void
{
    $username = trim((string) ($params['username'] ?? ''));
    $password = (string) ($params['password'] ?? '');
    $fail = 'Sai tên đăng nhập hoặc mật khẩu.';

    $stmt = db()->prepare(
        "SELECT * FROM users WHERE Username = :u AND Status = 'ACTIVE' LIMIT 1"
    );
    $stmt->execute(['u' => $username]);
    $user = $stmt->fetch();

    if (!$user) {
        api_fail($fail);
        return;
    }

    $hash = (string) $user['PasswordHash'];
    $verified = false;

    if (is_modern_hash($hash)) {
        // Bước 2 (mục 6): đã rehash trước đó, hoặc tài khoản tạo mới bằng PHP.
        $verified = password_verify($password, $hash);
    } else {
        // Bước 1 (mục 6): còn hash sha256 cũ — verify rồi rehash NGAY LẬP TỨC.
        $salt = (string) ($user['Salt'] ?? '');
        if (hash_equals($hash, legacy_sha256_hash($password, $salt))) {
            $verified = true;
            db_transaction(function (PDO $pdo) use ($user, $password): void {
                $upd = $pdo->prepare(
                    'UPDATE users SET PasswordHash = :hash, Salt = NULL WHERE UserID = :id'
                );
                $upd->execute([
                    'hash' => password_hash($password, PASSWORD_DEFAULT),
                    'id'   => $user['UserID'],
                ]);
            });
        }
    }

    if (!$verified) {
        api_fail($fail);
        return;
    }

    $token = issue_token('LECTURER', (string) $user['UserID'], AUTH_TOKEN_TTL_LECTURER);
    log_audit((string) $user['UserID'], (string) $user['Role'], 'LOGIN', 'USER', (string) $user['UserID']);

    api_ok([
        'token'    => $token,
        'role'     => $user['Role'],
        'fullName' => $user['FullName'],
    ]);
}

/** logout — POST action 'logout'. Xoá token, luôn trả {ok:true} như bản cũ. */
function action_logout(array $params): void
{
    $token = (string) ($params['token'] ?? '');
    $stmt = db()->prepare("DELETE FROM auth_tokens WHERE Token = :t AND Kind = 'LECTURER'");
    $stmt->execute(['t' => $token]);
    api_ok(['ok' => true]);
}
