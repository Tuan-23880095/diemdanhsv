<?php
declare(strict_types=1);

/**
 * tools/user.php — Quản lý tài khoản giảng viên / admin (bảng users).
 * CLI ONLY — rule 3 của dự án (tools/README.md): tạo tài khoản và đổi vai trò là
 * việc quản trị không ràng buộc lớp, không bao giờ để lộ ra web công khai.
 * Thay cho createLecturerAccounts() của gas/03-Auth.gs (docs/04 mục 9 — tên dự
 * kiến `create-lecturer.php`, gộp thêm đổi vai trò/trạng thái nên đặt `user.php`).
 *
 * Cách dùng (SSH, từ public_html):
 *   php tools/user.php --list
 *       Liệt kê UserID / Username / Role / Status / kiểu hash (KHÔNG in họ tên, email).
 *
 *   php tools/user.php --create --username=admin --fullname="Quản trị hệ thống" --role=ADMIN --generate --yes
 *   php tools/user.php --create --username=gv02 --fullname="Nguyễn Văn B" --email=b@example.com --generate --legacy --yes
 *       Tạo tài khoản mới. --role=ADMIN|LECTURER (mặc định LECTURER). --id=<UserID>
 *       tuỳ chọn (mặc định sinh "U" + 7 chữ số không trùng, cùng cỡ với ID 4 chữ số
 *       của sheet cũ nhưng không lẫn). Mật khẩu: --generate (12 ký tự, in MỘT lần)
 *       hoặc nhập từ STDIN (chữ hiện trên màn hình). --legacy: ghi sha256(salt|plain)
 *       + in Salt/PasswordHash để dán vào sheet 01_USERS cho Apps Script dự phòng
 *       (PHP tự rehash lần đăng nhập đầu) — chỉ cần khi còn chạy ?api=gas.
 *
 *   php tools/user.php --set-role --user=1607 --role=ADMIN --yes
 *       Đổi vai trò tài khoản đang có (UserID hoặc Username).
 *
 *   php tools/user.php --set-status --user=gv02 --status=INACTIVE --yes
 *       Khoá/mở tài khoản (INACTIVE không đăng nhập được, token hiện có bị huỷ;
 *       lịch sử giữ nguyên — không xoá dòng nào).
 *
 * --dry-run: chỉ kiểm, không ghi. --yes: bắt buộc để ghi thật.
 * Mọi thao tác ghi đều vào audit_log (TOOLS_USER_CREATE / TOOLS_USER_SET_ROLE /
 * TOOLS_USER_SET_STATUS). Không bao giờ tự hạ vai trò ADMIN cuối cùng.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Chỉ chạy được từ CLI.');
}

require __DIR__ . '/../api/lib/config.php';
require __DIR__ . '/../api/lib/db.php';
require __DIR__ . '/../api/lib/audit.php';

const USER_ROLES    = ['ADMIN', 'LECTURER'];
const USER_STATUSES = ['ACTIVE', 'INACTIVE'];
const USER_PW_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';

$opts = [
    'cmd' => null, 'user' => '', 'id' => '', 'username' => '', 'fullname' => '', 'email' => '',
    'role' => '', 'status' => '', 'generate' => false, 'legacy' => false, 'yes' => false, 'dry_run' => false,
];
foreach (array_slice($argv, 1) as $arg) {
    foreach (['list', 'create', 'set-role', 'set-status'] as $c) {
        if ($arg === "--$c") {
            if ($opts['cmd'] !== null) { fwrite(STDERR, "Chỉ chọn một lệnh: --list | --create | --set-role | --set-status\n"); exit(2); }
            $opts['cmd'] = $c;
            continue 2;
        }
    }
    foreach (['user', 'id', 'username', 'fullname', 'email', 'role', 'status'] as $k) {
        if (str_starts_with($arg, "--$k=")) { $opts[$k] = trim(substr($arg, strlen($k) + 3)); continue 2; }
    }
    if ($arg === '--generate') { $opts['generate'] = true; continue; }
    if ($arg === '--legacy')   { $opts['legacy'] = true; continue; }
    if ($arg === '--yes')      { $opts['yes'] = true; continue; }
    if ($arg === '--dry-run')  { $opts['dry_run'] = true; continue; }
    fwrite(STDERR, "Tham số không rõ: $arg\n");
    exit(2);
}
if ($opts['cmd'] === null) {
    fwrite(STDERR, "Thiếu lệnh. Xem hướng dẫn đầu file: --list | --create | --set-role | --set-status\n");
    exit(2);
}

$pdo = db();

function user_hash_kind(string $hash): string
{
    return str_starts_with($hash, '$2') || str_starts_with($hash, '$argon2') ? 'password_hash (mới)' : 'sha256 cũ';
}

/** Tìm đúng MỘT tài khoản theo UserID hoặc Username; thoát với lỗi rõ nếu không. */
function user_find_one(PDO $pdo, string $key): array
{
    $st = $pdo->prepare('SELECT UserID, Username, Role, Status, PasswordHash FROM users WHERE UserID = :a OR Username = :b LIMIT 2');
    $st->execute(['a' => $key, 'b' => $key]);
    $rows = $st->fetchAll();
    if (count($rows) !== 1) {
        fwrite(STDERR, count($rows) === 0 ? "Không thấy tài khoản '$key'.\n" : "'$key' khớp nhiều tài khoản — dùng UserID.\n");
        exit(1);
    }
    return $rows[0];
}

function user_brief(array $u): string
{
    return "UserID={$u['UserID']} Username={$u['Username']} Role={$u['Role']} Status={$u['Status']}";
}

function user_count_active_admins(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE Role = 'ADMIN' AND Status = 'ACTIVE'")->fetchColumn();
}

/* ------------------------------------------------------------------ */
/*  --list                                                              */
/* ------------------------------------------------------------------ */
if ($opts['cmd'] === 'list') {
    $rows = $pdo->query('SELECT UserID, Username, Role, Status, PasswordHash, CreatedAt FROM users ORDER BY Role, Username')->fetchAll();
    printf("%-12s %-20s %-9s %-9s %-20s %s\n", 'UserID', 'Username', 'Role', 'Status', 'Hash', 'CreatedAt');
    foreach ($rows as $r) {
        printf("%-12s %-20s %-9s %-9s %-20s %s\n", $r['UserID'], $r['Username'], $r['Role'], $r['Status'],
            user_hash_kind((string) $r['PasswordHash']), $r['CreatedAt']);
    }
    echo count($rows) . " tài khoản; ADMIN đang hoạt động: " . user_count_active_admins($pdo) . "\n";
    exit(0);
}

/* ------------------------------------------------------------------ */
/*  --set-role                                                          */
/* ------------------------------------------------------------------ */
if ($opts['cmd'] === 'set-role') {
    if ($opts['user'] === '' || !in_array($opts['role'], USER_ROLES, true)) {
        fwrite(STDERR, "Cần --user=<UserID|Username> và --role=ADMIN|LECTURER.\n");
        exit(2);
    }
    $u = user_find_one($pdo, $opts['user']);
    echo 'Tài khoản: ' . user_brief($u) . "\n";
    if ($u['Role'] === $opts['role']) {
        echo "Đã là {$opts['role']} — không cần đổi.\n";
        exit(0);
    }
    if ($u['Role'] === 'ADMIN' && $u['Status'] === 'ACTIVE' && user_count_active_admins($pdo) <= 1) {
        fwrite(STDERR, "Từ chối: đây là ADMIN đang hoạt động CUỐI CÙNG. Tạo/nâng admin khác trước.\n");
        exit(1);
    }
    if ($opts['dry_run'] || !$opts['yes']) {
        echo "Sẽ đổi Role {$u['Role']} → {$opts['role']}. " . ($opts['dry_run'] ? "Dry-run — không ghi.\n" : "Thiếu --yes — KHÔNG ghi gì.\n");
        exit(0);
    }
    $pdo->prepare('UPDATE users SET Role = :r WHERE UserID = :id')->execute(['r' => $opts['role'], 'id' => $u['UserID']]);
    log_audit('cli:user.php', 'ADMIN', 'TOOLS_USER_SET_ROLE', 'USER', (string) $u['UserID'], ['from' => $u['Role'], 'to' => $opts['role']]);
    echo "ĐÃ ĐỔI: UserID={$u['UserID']} Role {$u['Role']} → {$opts['role']}. Đăng xuất/đăng nhập lại để trang quản trị nhận vai trò mới.\n";
    exit(0);
}

/* ------------------------------------------------------------------ */
/*  --set-status                                                        */
/* ------------------------------------------------------------------ */
if ($opts['cmd'] === 'set-status') {
    if ($opts['user'] === '' || !in_array($opts['status'], USER_STATUSES, true)) {
        fwrite(STDERR, "Cần --user=<UserID|Username> và --status=ACTIVE|INACTIVE.\n");
        exit(2);
    }
    $u = user_find_one($pdo, $opts['user']);
    echo 'Tài khoản: ' . user_brief($u) . "\n";
    if ($u['Status'] === $opts['status']) {
        echo "Đã là {$opts['status']} — không cần đổi.\n";
        exit(0);
    }
    if ($opts['status'] === 'INACTIVE' && $u['Role'] === 'ADMIN' && user_count_active_admins($pdo) <= 1) {
        fwrite(STDERR, "Từ chối: đây là ADMIN đang hoạt động CUỐI CÙNG.\n");
        exit(1);
    }
    if ($opts['dry_run'] || !$opts['yes']) {
        echo "Sẽ đổi Status {$u['Status']} → {$opts['status']}" . ($opts['status'] === 'INACTIVE' ? ' và huỷ token đăng nhập hiện có' : '') . '. '
            . ($opts['dry_run'] ? "Dry-run — không ghi.\n" : "Thiếu --yes — KHÔNG ghi gì.\n");
        exit(0);
    }
    db_transaction(static function (PDO $pdo) use ($u, $opts): void {
        $pdo->prepare('UPDATE users SET Status = :s WHERE UserID = :id')->execute(['s' => $opts['status'], 'id' => $u['UserID']]);
        if ($opts['status'] === 'INACTIVE') {
            $pdo->prepare("DELETE FROM auth_tokens WHERE Kind = 'LECTURER' AND SubjectID = :id")->execute(['id' => $u['UserID']]);
        }
    });
    log_audit('cli:user.php', 'ADMIN', 'TOOLS_USER_SET_STATUS', 'USER', (string) $u['UserID'], ['from' => $u['Status'], 'to' => $opts['status']]);
    echo "ĐÃ ĐỔI: UserID={$u['UserID']} Status {$u['Status']} → {$opts['status']}.\n";
    exit(0);
}

/* ------------------------------------------------------------------ */
/*  --create                                                            */
/* ------------------------------------------------------------------ */
$username = $opts['username'];
$fullname = $opts['fullname'];
$role     = $opts['role'] === '' ? 'LECTURER' : $opts['role'];
$email    = $opts['email'] === '' ? null : $opts['email'];

if ($username === '' || !preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
    fwrite(STDERR, "--username bắt buộc, 3–60 ký tự chữ/số/._- (không dấu, không khoảng trắng).\n");
    exit(2);
}
if (mb_strlen($fullname) < 2) {
    fwrite(STDERR, "--fullname bắt buộc (họ tên hiển thị trên trang giảng viên).\n");
    exit(2);
}
if (!in_array($role, USER_ROLES, true)) {
    fwrite(STDERR, "--role phải là ADMIN hoặc LECTURER.\n");
    exit(2);
}
if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "--email không hợp lệ.\n");
    exit(2);
}

$exists = $pdo->prepare('SELECT UserID FROM users WHERE Username = :u LIMIT 1');
$exists->execute(['u' => $username]);
if ($exists->fetchColumn() !== false) {
    fwrite(STDERR, "Username '$username' đã có. Dùng --set-role / --set-status (hoặc tools/set_password.php) để sửa tài khoản đó.\n");
    exit(1);
}

$userId = $opts['id'];
if ($userId === '') {
    $chk = $pdo->prepare('SELECT 1 FROM users WHERE UserID = :id');
    do {
        $userId = 'U' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
        $chk->execute(['id' => $userId]);
    } while ($chk->fetchColumn() !== false);
} else {
    if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $userId)) {
        fwrite(STDERR, "--id chỉ gồm chữ/số/_-, tối đa 40 ký tự.\n");
        exit(2);
    }
    $chk = $pdo->prepare('SELECT 1 FROM users WHERE UserID = :id');
    $chk->execute(['id' => $userId]);
    if ($chk->fetchColumn() !== false) {
        fwrite(STDERR, "UserID '$userId' đã có.\n");
        exit(1);
    }
}

echo "Sẽ tạo: UserID=$userId Username=$username Role=$role" . ($email !== null ? ' (có email)' : ' (không email)') .
    ' — mật khẩu ' . ($opts['generate'] ? 'sinh ngẫu nhiên' : 'nhập tay') . ($opts['legacy'] ? ', hash KIỂU CŨ + Salt (cho Apps Script)' : ', password_hash() mới') . "\n";
if ($opts['dry_run']) {
    echo "Dry-run xong — không ghi gì.\n";
    exit(0);
}

if ($opts['generate']) {
    $plain = '';
    for ($i = 0; $i < 12; $i++) {
        $plain .= USER_PW_ALPHABET[random_int(0, strlen(USER_PW_ALPHABET) - 1)];
    }
} else {
    echo "Nhập mật khẩu (CHỮ SẼ HIỆN TRÊN MÀN HÌNH), tối thiểu 8 ký tự, rồi Enter: ";
    $plain = rtrim((string) fgets(STDIN), "\r\n");
    if (mb_strlen($plain) < 8) {
        fwrite(STDERR, "Mật khẩu quá ngắn (< 8). Không ghi gì.\n");
        exit(1);
    }
}

if ($opts['legacy']) {
    $salt = bin2hex(random_bytes(16));
    $hash = hash('sha256', $salt . '|' . $plain); // cùng công thức gas/03-Auth.gs hashPassword_
} else {
    $salt = null;
    $hash = password_hash($plain, PASSWORD_DEFAULT);
}

if (!$opts['yes']) {
    echo "Thiếu --yes — KHÔNG ghi gì. Thêm --yes để tạo thật.\n";
    exit(0);
}

$pdo->prepare('INSERT INTO users (UserID, Username, FullName, Email, PasswordHash, Salt, Role, Status) VALUES (:id, :u, :f, :e, :h, :s, :r, \'ACTIVE\')')
    ->execute(['id' => $userId, 'u' => $username, 'f' => $fullname, 'e' => $email, 'h' => $hash, 's' => $salt, 'r' => $role]);
log_audit('cli:user.php', 'ADMIN', 'TOOLS_USER_CREATE', 'USER', $userId, ['role' => $role, 'legacy' => $opts['legacy'], 'generated' => $opts['generate']]);

echo "ĐÃ TẠO: UserID=$userId Username=$username Role=$role Status=ACTIVE.\n";
if ($opts['generate']) {
    echo "MẬT KHẨU (chỉ in một lần, chép ngay): $plain\n";
}
if ($opts['legacy']) {
    echo "Để Apps Script (?api=gas) cũng nhận tài khoản này, thêm dòng vào sheet 01_USERS:\n" .
        "  UserID=$userId  Username=$username  Role=$role  Status=ACTIVE\n" .
        "  Salt         = $salt\n" .
        "  PasswordHash = $hash\n" .
        "(FullName/Email điền tay.) Lần đăng nhập đầu trên PHP sẽ tự rehash sang password_hash().\n";
}
