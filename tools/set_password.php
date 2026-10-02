<?php
declare(strict_types=1);

/**
 * tools/set_password.php — Đặt lại mật khẩu cho một tài khoản giảng viên/
 * admin (bảng users). CLI ONLY — rule 3 của dự án (tools/README.md): đổi mật
 * khẩu người khác là việc quản trị không ràng buộc lớp, không bao giờ để lộ ra
 * web công khai.
 *
 * Vì sao có: hash sha256(salt|plain) cũ và password_hash() mới đều KHÔNG khôi
 * phục được mật khẩu; quên là phải đặt lại (02/10/2026, chuẩn bị checklist GĐ9).
 *
 * Cách dùng (SSH, từ public_html):
 *   php tools/set_password.php --user=1607 --generate            # sinh mật khẩu ngẫu nhiên, in ra
 *   php tools/set_password.php --user=tenlogin                   # nhập mật khẩu từ bàn phím (hiện chữ)
 *   php tools/set_password.php --user=1607 --generate --legacy   # ghi theo kiểu cũ sha256(salt|plain)
 *   php tools/set_password.php --user=1607 --generate --dry-run  # chỉ kiểm tài khoản, không ghi
 *
 * --user     UserID hoặc Username (bảng users).
 * --generate Sinh mật khẩu 12 ký tự dễ đọc (bỏ 0/O, 1/l/I) rồi IN RA một lần.
 *            Không có cờ này thì hỏi nhập từ STDIN — host cấm exec/system nên
 *            không tắt echo được: chữ sẽ hiện trên màn hình, đừng để ai nhìn.
 * --legacy   Ghi hash KIỂU CŨ sha256(salt + '|' + plain) với Salt mới ngẫu nhiên
 *            và IN RA Salt + PasswordHash để thầy dán vào sheet 01_USERS (cột
 *            Salt, PasswordHash) → Apps Script (?api=gas, đường lùi) cũng nhận
 *            mật khẩu mới. Lần đăng nhập đầu trên PHP sẽ tự rehash sang
 *            password_hash() (api/lib/auth.php) — đúng ca kiểm checklist mục 3.
 *            Không có cờ này: ghi password_hash() ngay (Apps Script KHÔNG nhận).
 * --yes      Bắt buộc để ghi thật; thiếu thì chỉ in ra sẽ làm gì.
 * --dry-run  Không ghi gì.
 *
 * Luôn: huỷ mọi token đăng nhập đang có của tài khoản đó (auth_tokens) và ghi
 * audit_log (TOOLS_SET_PASSWORD). Không in họ tên/email — chỉ UserID/Username/Role.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Chỉ chạy được từ CLI.');
}

require __DIR__ . '/../api/lib/config.php';
require __DIR__ . '/../api/lib/db.php';
require __DIR__ . '/../api/lib/audit.php';

$opts = ['user' => '', 'generate' => false, 'legacy' => false, 'yes' => false, 'dry_run' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--user=')) { $opts['user'] = trim(substr($arg, 7)); continue; }
    if ($arg === '--generate') { $opts['generate'] = true; continue; }
    if ($arg === '--legacy') { $opts['legacy'] = true; continue; }
    if ($arg === '--yes') { $opts['yes'] = true; continue; }
    if ($arg === '--dry-run') { $opts['dry_run'] = true; continue; }
    fwrite(STDERR, "Tham số không rõ: $arg\n");
    exit(2);
}
if ($opts['user'] === '') {
    fwrite(STDERR, "Thiếu --user=<UserID hoặc Username>. Xem hướng dẫn đầu file.\n");
    exit(2);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT UserID, Username, Role, Status, PasswordHash FROM users WHERE UserID = :a OR Username = :b LIMIT 2');
$stmt->execute(['a' => $opts['user'], 'b' => $opts['user']]);
$rows = $stmt->fetchAll();
if (count($rows) !== 1) {
    fwrite(STDERR, count($rows) === 0
        ? "Không thấy tài khoản '{$opts['user']}'.\n"
        : "'{$opts['user']}' khớp nhiều tài khoản — dùng UserID.\n");
    exit(1);
}
$u = $rows[0];
$kindNow = str_starts_with((string) $u['PasswordHash'], '$2') || str_starts_with((string) $u['PasswordHash'], '$argon2')
    ? 'password_hash (mới)' : 'sha256 cũ';
echo "Tài khoản: UserID={$u['UserID']} Username={$u['Username']} Role={$u['Role']} Status={$u['Status']} — hash hiện tại: $kindNow\n";

if ($opts['dry_run']) {
    echo "Dry-run xong — không ghi gì.\n";
    exit(0);
}

// Mật khẩu mới
if ($opts['generate']) {
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $plain = '';
    for ($i = 0; $i < 12; $i++) {
        $plain .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
} else {
    echo "Nhập mật khẩu mới (CHỮ SẼ HIỆN TRÊN MÀN HÌNH), tối thiểu 8 ký tự, rồi Enter: ";
    $plain = rtrim((string) fgets(STDIN), "\r\n");
    if (mb_strlen($plain) < 8) {
        fwrite(STDERR, "Mật khẩu quá ngắn (< 8). Không ghi gì.\n");
        exit(1);
    }
}

if ($opts['legacy']) {
    $salt = bin2hex(random_bytes(16)); // 32 hex — khớp VARCHAR(64), cùng công thức gas/03-Auth.gs hashPassword_
    $hash = hash('sha256', $salt . '|' . $plain);
} else {
    $salt = null;
    $hash = password_hash($plain, PASSWORD_DEFAULT);
}

if (!$opts['yes']) {
    echo "Sẽ ghi " . ($opts['legacy'] ? 'hash KIỂU CŨ sha256(salt|plain) + Salt mới' : 'password_hash() mới') .
        " cho UserID={$u['UserID']} và huỷ mọi token đăng nhập của tài khoản này.\n" .
        "Thiếu --yes — KHÔNG ghi gì. Thêm --yes để xác nhận.\n";
    exit(0);
}

db_transaction(static function (PDO $pdo) use ($u, $hash, $salt): void {
    $pdo->prepare('UPDATE users SET PasswordHash = :h, Salt = :s WHERE UserID = :id')
        ->execute(['h' => $hash, 's' => $salt, 'id' => $u['UserID']]);
    $pdo->prepare("DELETE FROM auth_tokens WHERE Kind = 'LECTURER' AND SubjectID = :id")
        ->execute(['id' => $u['UserID']]);
});
log_audit('cli:set_password.php', 'ADMIN', 'TOOLS_SET_PASSWORD', 'USER', (string) $u['UserID'], [
    'legacy' => $opts['legacy'], 'generated' => $opts['generate'],
]);

echo "ĐÃ ĐỔI mật khẩu cho UserID={$u['UserID']} (Username={$u['Username']}). Token đăng nhập cũ đã huỷ.\n";
if ($opts['generate']) {
    echo "MẬT KHẨU MỚI (chỉ in một lần, chép ngay): $plain\n";
}
if ($opts['legacy']) {
    echo "Để Apps Script (?api=gas) cũng nhận mật khẩu này, dán vào sheet 01_USERS dòng UserID={$u['UserID']}:\n" .
        "  Salt         = $salt\n" .
        "  PasswordHash = $hash\n" .
        "Lần đăng nhập đầu trên PHP sẽ tự rehash sang password_hash() và xoá Salt (checklist mục 3).\n";
}
