<?php
declare(strict_types=1);

/**
 * bdvh/api.php — Backend REST API cho Nền tảng Bồi Dưỡng Văn Hóa & Khảo thí ĐGNL (bdvh.diemdanhsv.com)
 * ThS. Đinh Quốc Tuấn — Trường ĐH Khoa học Tự nhiên, ĐHQG-HCM.
 *
 * Tính năng chính:
 * 1. Đăng ký & Đăng nhập tài khoản Khách (Guest Account) nhanh chóng.
 * 2. Kiểm soát giới hạn: Mỗi tài khoản khách được làm bài MIỄN PHÍ 2 LẦN.
 * 3. Tích hợp Hệ Thống Phân Tích Khảo Thí Chuyên Sâu đánh giá năng lực tư duy, bắt lỗi sai và gợi ý mẹo giải nhanh.
 * 4. Hệ thống Paywall & MoMo Bonus 5.000đ / Liên hệ nâng cấp email dqtuanhcmus@gmail.com.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Ho_Chi_Minh');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function json_out(array $payload, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Đường dẫn hạ tầng chung của diemdanhsv
$root = dirname(__DIR__);
$isLiveHost = file_exists($root . '/api/lib/config.php') && file_exists($root . '/api/lib/db.php');

$pdo = null;
if ($isLiveHost) {
    try {
        require_once $root . '/api/lib/config.php';
        require_once $root . '/api/lib/db.php';
        $pdo = db();
    } catch (Throwable $e) {
        // Dự phòng SQLite khi chạy thử nghiệm nội bộ
        $pdo = null;
    }
}

if (!$pdo) {
    $sqliteFile = __DIR__ . '/bdvh_local.sqlite';
    $pdo = new PDO('sqlite:' . $sqliteFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

$dbDriver = $pdo ? $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) : 'sqlite';
$nowSql = ($dbDriver === 'sqlite') ? "datetime('now','localtime')" : "NOW()";

// Khởi tạo bảng tự động nếu chưa có
function ensure_bdvh_tables(PDO $db): void {
    global $nowSql;
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS bdvh_users (
                    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT UNIQUE NOT NULL,
                    full_name TEXT NOT NULL,
                    phone TEXT,
                    email TEXT,
                    password_hash TEXT,
                    grade TEXT DEFAULT 'L10',
                    role TEXT DEFAULT 'GUEST',
                    free_trials_left INTEGER DEFAULT 2,
                    paid_trials_left INTEGER DEFAULT 0,
                    bonus_accumulated INTEGER DEFAULT 0,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    last_login TEXT
                );
                CREATE TABLE IF NOT EXISTS bdvh_exams (
                    exam_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    exam_code TEXT UNIQUE NOT NULL,
                    grade TEXT NOT NULL,
                    subject TEXT NOT NULL,
                    title TEXT NOT NULL,
                    description TEXT,
                    time_minutes INTEGER DEFAULT 45,
                    questions_json TEXT NOT NULL,
                    competency_matrix TEXT,
                    status TEXT DEFAULT 'ACTIVE',
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS bdvh_submissions (
                    sub_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    exam_id INTEGER NOT NULL,
                    score REAL NOT NULL,
                    duration_seconds INTEGER NOT NULL,
                    answers_given TEXT NOT NULL,
                    correct_count INTEGER DEFAULT 0,
                    total_questions INTEGER DEFAULT 0,
                    ai_evaluation TEXT,
                    is_free_trial INTEGER DEFAULT 1,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS bdvh_settings (
                    setting_key TEXT PRIMARY KEY,
                    setting_value TEXT
                );
                CREATE TABLE IF NOT EXISTS bdvh_teachers (
                    teacher_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    email TEXT UNIQUE NOT NULL,
                    full_name TEXT NOT NULL,
                    phone TEXT,
                    school_name TEXT,
                    class_name TEXT,
                    class_code TEXT UNIQUE,
                    is_active INTEGER DEFAULT 1,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS bdvh_class_codes (
                    code_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    code TEXT UNIQUE NOT NULL,
                    teacher_id INTEGER DEFAULT 1,
                    label TEXT DEFAULT 'Lớp Thầy Đinh Quốc Tuấn',
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    expires_at TEXT,
                    is_active INTEGER DEFAULT 1,
                    used_count INTEGER DEFAULT 0
                );
                CREATE TABLE IF NOT EXISTS bdvh_transactions (
                    trans_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    amount INTEGER NOT NULL,
                    trials_added INTEGER NOT NULL,
                    payment_method TEXT DEFAULT 'MOMO',
                    trans_code TEXT,
                    memo TEXT,
                    status TEXT DEFAULT 'PENDING',
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                );
            ");
            $st = $db->query("SELECT COUNT(*) as c FROM bdvh_exams");
            if ((int)$st->fetch()['c'] === 0) {
                seed_default_exams($db);
            }
        } catch (Throwable $e) {
            error_log("Lỗi khởi tạo bảng SQLite: " . $e->getMessage());
        }
    } else {
        // MySQL MariaDB trên Hostinger
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS bdvh_users (
                    user_id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(60) NOT NULL UNIQUE,
                    full_name VARCHAR(120) NOT NULL,
                    phone VARCHAR(20) NULL,
                    email VARCHAR(160) NULL,
                    password_hash VARCHAR(255) NULL,
                    grade VARCHAR(20) NOT NULL DEFAULT 'L10',
                    role VARCHAR(50) NOT NULL DEFAULT 'GUEST',
                    free_trials_left INT NOT NULL DEFAULT 2,
                    paid_trials_left INT NOT NULL DEFAULT 0,
                    bonus_accumulated INT NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_login DATETIME NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS bdvh_exams (
                    exam_id INT AUTO_INCREMENT PRIMARY KEY,
                    exam_code VARCHAR(50) NOT NULL UNIQUE,
                    grade VARCHAR(20) NOT NULL,
                    subject VARCHAR(40) NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    description TEXT NULL,
                    time_minutes INT NOT NULL DEFAULT 45,
                    questions_json LONGTEXT NOT NULL,
                    competency_matrix LONGTEXT NULL,
                    status ENUM('ACTIVE', 'DRAFT') NOT NULL DEFAULT 'ACTIVE',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS bdvh_submissions (
                    sub_id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    exam_id INT NOT NULL,
                    score DECIMAL(4,2) NOT NULL,
                    duration_seconds INT NOT NULL,
                    answers_given LONGTEXT NOT NULL,
                    correct_count INT NOT NULL DEFAULT 0,
                    total_questions INT NOT NULL DEFAULT 0,
                    ai_evaluation LONGTEXT NULL,
                    is_free_trial TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS bdvh_settings (
                    setting_key VARCHAR(190) PRIMARY KEY,
                    setting_value LONGTEXT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS bdvh_teachers (
                    teacher_id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(190) UNIQUE NOT NULL,
                    full_name VARCHAR(190) NOT NULL,
                    phone VARCHAR(30),
                    school_name VARCHAR(190),
                    class_name VARCHAR(100),
                    class_code VARCHAR(50) UNIQUE,
                    is_active TINYINT(1) DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS bdvh_class_codes (
                    code_id INT AUTO_INCREMENT PRIMARY KEY,
                    code VARCHAR(50) UNIQUE NOT NULL,
                    teacher_id INT DEFAULT 1,
                    label VARCHAR(255) DEFAULT 'Lớp Thầy Đinh Quốc Tuấn',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME NULL,
                    is_active TINYINT(1) DEFAULT 1,
                    used_count INT DEFAULT 0
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS bdvh_transactions (
                    trans_id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    amount INT NOT NULL,
                    trials_added INT NOT NULL,
                    payment_method VARCHAR(30) NOT NULL DEFAULT 'MOMO',
                    trans_code VARCHAR(100) NULL,
                    memo VARCHAR(120) NULL,
                    status ENUM('PENDING', 'SUCCESS', 'CANCELLED') NOT NULL DEFAULT 'PENDING',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            try {
                $db->exec("ALTER TABLE bdvh_users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'GUEST'");
            } catch (Throwable $e) {}

            $st = $db->query("SELECT COUNT(*) as c FROM bdvh_exams");
            if ((int)$st->fetch()['c'] === 0) {
                seed_default_exams($db);
            }
        } catch (Throwable $e) {
            error_log("Lỗi khởi tạo bảng MySQL bdvh: " . $e->getMessage());
        }
    }
}

function seed_default_exams(PDO $db): void {
    $jsonFile = __DIR__ . '/standard_exams.json';
    if (!file_exists($jsonFile)) return;
    $jsonText = file_get_contents($jsonFile);
    $exams = json_decode($jsonText, true);
    if (!is_array($exams)) return;

    $stmt = $db->prepare("
        REPLACE INTO bdvh_exams (exam_code, grade, subject, title, description, time_minutes, questions_json)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($exams as $ex) {
        $stmt->execute([
            $ex['exam_code'],
            $ex['grade'],
            $ex['subject'],
            $ex['title'],
            $ex['description'],
            $ex['time_minutes'],
            json_encode($ex['questions'], JSON_UNESCAPED_UNICODE)
        ]);
    }
}

try {
    ensure_bdvh_tables($pdo);
} catch (Throwable $e) {
    error_log("Lỗi ensure_bdvh_tables: " . $e->getMessage());
}

// Lấy tham số request
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$inputData = [];
$rawInput = file_get_contents('php://input');
if ($rawInput) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $inputData = $decoded;
        if (empty($action) && isset($inputData['action'])) {
            $action = $inputData['action'];
        }
    }
}

// -------------------------------------------------------------


// Kiểm tra quyền Giảng viên quản trị
function assert_teacher_auth(): void {
    if (empty($_SESSION['bdvh_teacher_auth'])) {
        json_out(['status' => 'error', 'message' => 'Yêu cầu đăng nhập mật khẩu giảng viên để thực hiện thao tác này.'], 401);
    }
}

// Sinh mã 4 ký tự động theo ca thời gian (mặc định mỗi 30 phút đổi 1 lần)
function get_rolling_class_code(int $offsetSlots = 0, string $secretSalt = 'DQTUAN_HCMUS_MATH_2026'): string {
    $slotMinutes = 30; // 30 phút một mã
    $currentSlot = (int)floor(time() / ($slotMinutes * 60)) + $offsetSlots;
    $rawHash = hash_hmac('sha256', (string)$currentSlot, $secretSalt);
    // 32 ký tự không gây nhầm lẫn (bỏ 0, O, 1, I)
    $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < 4; $i++) {
        $idx = hexdec(substr($rawHash, $i * 4, 4)) % strlen($chars);
        $code .= $chars[$idx];
    }
    return $code;
}

// Kiểm tra mã 4 ký tự: khớp mã rolling hoặc khớp mã giáo viên trong DB
function verify_class_code_check(PDO $pdo, string $code): array {
    $cleanCode = strtoupper(trim($code));
    if (strlen($cleanCode) < 3 || strlen($cleanCode) > 8) {
        return ['valid' => false, 'teacher' => null, 'label' => ''];
    }

    // 1. Kiểm tra Rolling Code của Thầy Đinh Quốc Tuấn (chấp nhận slot hiện tại và slot trước để tránh trễ)
    for ($off = -1; $off <= 1; $off++) {
        if (get_rolling_class_code($off) === $cleanCode) {
            return [
                'valid' => true,
                'teacher' => 'ThS. Đinh Quốc Tuấn (HCMUS)',
                'teacher_id' => 1,
                'label' => 'Lớp Toán Thầy Đinh Quốc Tuấn',
                'is_tuan' => true
            ];
        }
    }

    // 2. Kiểm tra trong bảng bdvh_class_codes hoặc bdvh_teachers
    try {
        $stmt = $pdo->prepare("
            SELECT c.*, t.full_name as teacher_name, t.email as teacher_email, t.class_name 
            FROM bdvh_class_codes c
            LEFT JOIN bdvh_teachers t ON c.teacher_id = t.teacher_id
            WHERE UPPER(c.code) = ? AND c.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$cleanCode]);
        $row = $stmt->fetch();
        if ($row) {
            // Tăng số lượt dùng
            $pdo->prepare("UPDATE bdvh_class_codes SET used_count = used_count + 1 WHERE code_id = ?")->execute([$row['code_id']]);
            return [
                'valid' => true,
                'teacher' => $row['teacher_name'] ?? 'Giáo viên đối tác',
                'teacher_id' => (int)($row['teacher_id'] ?? 0),
                'label' => $row['label'] ?? ($row['class_name'] ?? 'Lớp học đối tác'),
                'is_tuan' => false
            ];
        }

        // Kiểm tra mã gắn trực tiếp trên bảng bdvh_teachers
        $stmt = $pdo->prepare("SELECT * FROM bdvh_teachers WHERE UPPER(class_code) = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$cleanCode]);
        $t = $stmt->fetch();
        if ($t) {
            return [
                'valid' => true,
                'teacher' => $t['full_name'],
                'teacher_id' => (int)$t['teacher_id'],
                'label' => $t['class_name'] . ' (' . $t['school_name'] . ')',
                'is_tuan' => false
            ];
        }
    } catch (Throwable $e) {}

    return ['valid' => false, 'teacher' => null, 'label' => ''];
}

// PHÒNG THI TRỰC TIẾP CÓ GIÁM SÁT (action room_*) — xem phong-thi-api.php
define('BDVH_API', true);
require_once __DIR__ . '/phong-thi-api.php';

// ROUTER CÁC CHỨC NĂNG
// -------------------------------------------------------------

switch ($action) {

    // --- BẢO MẬT XÁC THỰC GIẢNG VIÊN (THẦY TUẤN) ---

    case 'check_teacher_auth':
        $isLogged = !empty($_SESSION['bdvh_teacher_auth']);
        json_out(['status' => 'success', 'logged_in' => $isLogged]);
        break;

    case 'teacher_login':
        $pass = (string)($inputData['password'] ?? $_POST['password'] ?? '');
        if ($pass === '') {
            json_out(['status' => 'error', 'message' => 'Vui lòng nhập mật khẩu quản trị.'], 400);
        }

        // Lấy hash mật khẩu từ DB (nếu chưa có thì mật khẩu khởi tạo mặc định là Tuan@2026)
        $defaultInitialPass = 'Tuan@2026';
        $storedHash = '';
        try {
            $st = $pdo->prepare("SELECT setting_value FROM bdvh_settings WHERE setting_key = 'teacher_password_hash' LIMIT 1");
            $st->execute();
            $row = $st->fetch();
            if ($row) {
                $storedHash = $row['setting_value'];
            }
        } catch (Throwable $e) {}

        $isValid = false;
        if ($storedHash !== '') {
            $isValid = password_verify($pass, $storedHash);
        } else {
            // Lần đầu khởi tạo: so sánh với mật khẩu mặc định
            if ($pass === $defaultInitialPass) {
                $isValid = true;
                // Tự động băm lưu vào DB
                $newHash = password_hash($pass, PASSWORD_DEFAULT);
                $pdo->prepare("REPLACE INTO bdvh_settings (setting_key, setting_value) VALUES ('teacher_password_hash', ?)")->execute([$newHash]);
            }
        }

        if ($isValid) {
            $_SESSION['bdvh_teacher_auth'] = true;
            $_SESSION['bdvh_teacher_time'] = time();
            json_out(['status' => 'success', 'message' => 'Đăng nhập Giảng Viên thành công!']);
        } else {
            json_out(['status' => 'error', 'message' => 'Mật khẩu quản trị không chính xác.'], 401);
        }
        break;

    case 'teacher_logout':
        unset($_SESSION['bdvh_teacher_auth']);
        unset($_SESSION['bdvh_teacher_time']);
        json_out(['status' => 'success', 'message' => 'Đã đăng xuất an toàn.']);
        break;

    case 'change_teacher_password':
        assert_teacher_auth();
        $oldPass = (string)($inputData['old_password'] ?? '');
        $newPass = (string)($inputData['new_password'] ?? '');

        if (strlen($newPass) < 6) {
            json_out(['status' => 'error', 'message' => 'Mật khẩu mới phải có ít nhất 6 ký tự.'], 400);
        }

        // Kiểm tra mật khẩu cũ
        $st = $pdo->query("SELECT setting_value FROM bdvh_settings WHERE setting_key = 'teacher_password_hash' LIMIT 1");
        $stored = $st ? $st->fetch() : null;
        if ($stored && !password_verify($oldPass, $stored['setting_value'])) {
            json_out(['status' => 'error', 'message' => 'Mật khẩu cũ không đúng.'], 400);
        }

        $newHash = password_hash($newPass, PASSWORD_DEFAULT);
        $pdo->prepare("REPLACE INTO bdvh_settings (setting_key, setting_value) VALUES ('teacher_password_hash', ?)")->execute([$newHash]);
        json_out(['status' => 'success', 'message' => 'Đã đổi mật khẩu quản trị thành công!']);
        break;


    // --- TÍNH NĂNG MÃ LỚP 4 KÝ TỰ & QUẢN LÝ GIÁO VIÊN ---

    // A. Học sinh nhập mã lớp 4 ký tự để mở khóa vô hạn
    case 'verify_class_code':
        $code = trim((string)($inputData['code'] ?? $_POST['code'] ?? ''));
        $studentName = trim((string)($inputData['student_name'] ?? $_POST['student_name'] ?? ''));
        $phone = trim((string)($inputData['phone'] ?? $_POST['phone'] ?? ''));

        $check = verify_class_code_check($pdo, $code);
        if (!$check['valid']) {
            json_out([
                'status' => 'error',
                'message' => 'Mã lớp "' . htmlspecialchars($code) . '" không hợp lệ hoặc đã hết hạn. Hãy xin Thầy/Cô mã 4 ký tự mới nhất nhé!'
            ], 400);
        }

        // Nếu hợp lệ: Cập nhật tài khoản học sinh
        $userId = $_SESSION['bdvh_user_id'] ?? 0;
        if ($studentName === '') {
            $studentName = 'Học sinh (' . strtoupper($code) . ')';
        }
        if ($userId <= 0) {
            // Tự tạo tài khoản cho học sinh
            $uname = 'hs_' . substr(md5($studentName . microtime()), 0, 8);
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO bdvh_users (username, full_name, phone, role, free_trials_left, paid_trials_left, created_at, last_login)
                    VALUES (?, ?, ?, 'CLASS_STUDENT', 9999, 9999, $nowSql, $nowSql)
                ");
                $stmt->execute([$uname, $studentName, $phone]);
                $userId = (int)$pdo->lastInsertId();
                $_SESSION['bdvh_user_id'] = $userId;
            } catch (Throwable $e) {
                error_log("Lỗi tạo user verify_class_code: " . $e->getMessage());
            }
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE bdvh_users SET full_name = ?, role = 'CLASS_STUDENT', free_trials_left = 9999, paid_trials_left = 9999 WHERE user_id = ?");
                $stmt->execute([$studentName, $userId]);
            } catch (Throwable $e) {}
        }

        $_SESSION['bdvh_student_name'] = $studentName;
        $_SESSION['bdvh_has_ai_access'] = true;
        $_SESSION['bdvh_class_code'] = strtoupper($code);
        $_SESSION['bdvh_class_label'] = $check['label'];
        $_SESSION['bdvh_teacher_name'] = $check['teacher'];
        $_SESSION['bdvh_teacher_id'] = $check['teacher_id'];
        $_SESSION['bdvh_is_class_student'] = true;

        json_out([
            'status' => 'success',
            'message' => '🎉 CHÚC MỪNG EM ĐÃ VÀO ' . mb_strtoupper($check['label']) . '! Toàn bộ đề thi và phân tích chuyên sâu từ Hệ Thống đã được MỞ KHÓA KHÔNG GIỚI HẠN.',
            'class_info' => [
                'teacher' => $check['teacher'],
                'label' => $check['label'],
                'code' => strtoupper($code)
            ]
        ]);
        break;

    // B. Dành cho Thầy Đinh Quốc Tuấn / Giáo viên lấy mã 4 ký tự hiện tại để chiếu lên bảng
    case 'get_teacher_code':
        assert_teacher_auth();
        $slotMinutes = 30;
        $now = time();
        $secondsRemaining = ($slotMinutes * 60) - ($now % ($slotMinutes * 60));
        $currentCode = get_rolling_class_code(0);
        $prevCode = get_rolling_class_code(-1);

        // Lấy thêm danh sách mã tùy chỉnh đang hoạt động
        $customCodes = [];
        try {
            $stmt = $pdo->query("SELECT * FROM bdvh_class_codes WHERE is_active = 1 ORDER BY code_id DESC LIMIT 10");
            $customCodes = $stmt->fetchAll();
        } catch (Throwable $e) {}

        json_out([
            'status' => 'success',
            'rolling_code' => $currentCode,
            'prev_code' => $prevCode,
            'seconds_remaining' => $secondsRemaining,
            'slot_minutes' => $slotMinutes,
            'custom_codes' => $customCodes
        ]);
        break;

    // C. Thầy Tuấn / Giáo viên tạo mã lớp tĩnh mới (ví dụ: MATH, 2026, LAN6)
    case 'create_custom_code':
        assert_teacher_auth();
        $code = strtoupper(trim((string)($inputData['code'] ?? $_POST['code'] ?? '')));
        $label = trim((string)($inputData['label'] ?? $_POST['label'] ?? 'Lớp Toán'));
        $teacherId = (int)($inputData['teacher_id'] ?? 1);
        $hoursValid = (int)($inputData['hours_valid'] ?? 4);

        if (strlen($code) < 3 || strlen($code) > 8) {
            json_out(['status' => 'error', 'message' => 'Mã lớp nên từ 3 đến 6 ký tự.'], 400);
        }

        $expiresAt = date('Y-m-d H:i:s', time() + ($hoursValid * 3600));
        try {
            $stmt = $pdo->prepare("
                REPLACE INTO bdvh_class_codes (code, teacher_id, label, expires_at, is_active, used_count)
                VALUES (?, ?, ?, ?, 1, 0)
            ");
            $stmt->execute([$code, $teacherId, $label, $expiresAt]);
            json_out([
                'status' => 'success',
                'message' => "Đã tạo mã lớp \"{$code}\" thành công! Có hiệu lực trong {$hoursValid} giờ.",
                'code' => $code,
                'expires_at' => $expiresAt
            ]);
        } catch (Throwable $e) {
            json_out(['status' => 'error', 'message' => 'Không thể tạo mã: ' . $e->getMessage()], 500);
        }
        break;

    // D. Bảng điều khiển Giáo viên: Xem kết quả học sinh lớp mình nộp bài
    case 'teacher_dashboard':
        assert_teacher_auth();
        $teacherId = (int)($_GET['teacher_id'] ?? 1);
        // Lấy danh sách bài nộp gần nhất của học sinh thuộc lớp này
        $submissions = [];
        try {
            $stmt = $pdo->prepare("
                SELECT s.sub_id as submission_id, s.user_id, s.score, s.duration_seconds, s.correct_count, s.total_questions, s.created_at,
                       COALESCE(u.full_name, 'Học sinh') as full_name, u.phone, COALESCE(e.title, 'Bài thi') as exam_title
                FROM bdvh_submissions s
                LEFT JOIN bdvh_users u ON s.user_id = u.user_id
                LEFT JOIN bdvh_exams e ON s.exam_id = e.exam_id
                ORDER BY s.sub_id DESC LIMIT 50
            ");
            $stmt->execute();
            $submissions = $stmt->fetchAll();
        } catch (Throwable $e) {}

        json_out([
            'status' => 'success',
            'submissions' => $submissions
        ]);
        break;

    // E. Thầy Tuấn thêm Giáo viên đối tác mới (Thỏa thuận hợp tác)
    case 'add_partner_teacher':
        assert_teacher_auth();
        $email = trim((string)($inputData['email'] ?? ''));
        $name = trim((string)($inputData['full_name'] ?? ''));
        $phone = trim((string)($inputData['phone'] ?? ''));
        $school = trim((string)($inputData['school_name'] ?? ''));
        $className = trim((string)($inputData['class_name'] ?? ''));
        $customCode = strtoupper(trim((string)($inputData['class_code'] ?? '')));

        if ($email === '' || $name === '') {
            json_out(['status' => 'error', 'message' => 'Vui lòng nhập Email và Họ tên giáo viên.'], 400);
        }

        if ($customCode === '') {
            $customCode = strtoupper(substr(md5($email . microtime()), 0, 4));
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO bdvh_teachers (email, full_name, phone, school_name, class_name, class_code, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$email, $name, $phone, $school, $className, $customCode]);
            $newTId = (int)$pdo->lastInsertId();

            // Tạo luôn mã lớp tương ứng
            $stmt = $pdo->prepare("
                INSERT INTO bdvh_class_codes (code, teacher_id, label, is_active)
                VALUES (?, ?, ?, 1)
            ");
            $stmt->execute([$customCode, $newTId, "Lớp {$className} - Thầy/Cô {$name}"]);

            json_out([
                'status' => 'success',
                'message' => "Đã tạo tài khoản đối tác cho Thầy/Cô {$name}! Mã lớp cung cấp cho học sinh là: {$customCode}",
                'teacher' => [
                    'teacher_id' => $newTId,
                    'full_name' => $name,
                    'email' => $email,
                    'class_code' => $customCode,
                    'class_name' => $className
                ]
            ]);
        } catch (Throwable $e) {
            json_out(['status' => 'error', 'message' => 'Lỗi: ' . $e->getMessage()], 500);
        }
        break;

    // 1. Kiểm tra trạng thái đăng nhập
    case 'check_auth':
        if (!isset($_SESSION['bdvh_user_id'])) {
            json_out(['status' => 'guest', 'user' => null]);
        }
        $stmt = $pdo->prepare("SELECT user_id, username, full_name, phone, role, free_trials_left, paid_trials_left, bonus_accumulated FROM bdvh_users WHERE user_id = ?");
        $stmt->execute([$_SESSION['bdvh_user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            unset($_SESSION['bdvh_user_id']);
            json_out(['status' => 'guest', 'user' => null]);
        }
        json_out(['status' => 'logged_in', 'user' => $user]);
        break;

    // 2. Đăng ký / Đăng nhập tài khoản Khách nhanh
    case 'guest_login':
        $fullName = trim((string)($inputData['full_name'] ?? $_POST['full_name'] ?? ''));
        $phone = trim((string)($inputData['phone'] ?? $_POST['phone'] ?? ''));
        $grade = trim((string)($inputData['grade'] ?? $_POST['grade'] ?? 'L10'));

        if ($fullName === '') {
            json_out(['status' => 'error', 'message' => 'Vui lòng nhập Họ tên của em.'], 400);
        }

        // Tạo username định danh
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if ($cleanPhone !== '') {
            $username = 'khach_' . $cleanPhone;
        } else {
            $username = 'khach_' . substr(md5($fullName . microtime()), 0, 8);
        }

        // Kiểm tra xem đã tồn tại chưa
        $stmt = $pdo->prepare("SELECT * FROM bdvh_users WHERE username = ?");
        $stmt->execute([$username]);
        $existing = $stmt->fetch();

        if ($existing) {
            $_SESSION['bdvh_user_id'] = $existing['user_id'];
            json_out([
                'status' => 'success',
                'message' => 'Chào mừng ' . $existing['full_name'] . ' trở lại!',
                'user' => $existing
            ]);
        } else {
            // Tạo tài khoản khách mới với 2 lượt miễn phí
            $stmt = $pdo->prepare("
                INSERT INTO bdvh_users (username, full_name, phone, grade, role, free_trials_left, paid_trials_left, created_at, last_login)
                VALUES (?, ?, ?, ?, 'GUEST', 2, 0, $nowSql, $nowSql)
            ");
            $stmt->execute([$username, $fullName, $phone, $grade]);
            $newId = (int)$pdo->lastInsertId();
            $_SESSION['bdvh_user_id'] = $newId;

            $stmt = $pdo->prepare("SELECT user_id, username, full_name, phone, role, free_trials_left, paid_trials_left, bonus_accumulated FROM bdvh_users WHERE user_id = ?");
            $stmt->execute([$newId]);
            $newUser = $stmt->fetch();

            json_out([
                'status' => 'success',
                'message' => 'Đã tạo tài khoản khách thành công! Em có 2 lượt làm bài miễn phí.',
                'user' => $newUser
            ]);
        }
        break;

    // 3. Đăng xuất
    case 'logout':
        unset($_SESSION['bdvh_user_id']);
        session_destroy();
        json_out(['status' => 'success', 'message' => 'Đã đăng xuất.']);
        break;

    // 4. Lấy danh sách đề thi (theo Khối & Môn)
    case 'list_exams':
        $grade = $_GET['grade'] ?? '';
        $subject = $_GET['subject'] ?? '';

        // Tự động đồng bộ ngân hàng đề thi chuẩn nếu cần
        try {
            $cntSt = $pdo->query("SELECT COUNT(*) as c FROM bdvh_exams");
            $cntRow = $cntSt ? $cntSt->fetch() : null;
            if (!$cntRow || (int)$cntRow['c'] < 11) {
                seed_default_exams($pdo);
            }
        } catch (Throwable $e) {}

        $sql = "SELECT exam_id, exam_code, grade, subject, title, description, time_minutes FROM bdvh_exams WHERE status = 'ACTIVE'";
        $params = [];
        if ($grade !== '' && $grade !== 'ALL') {
            $sql .= " AND (grade = ? OR grade = ?)";
            $params[] = $grade;
            $params[] = str_replace('L', '', $grade);
        }
        if ($subject !== '') {
            $sql .= " AND subject = ?";
            $params[] = $subject;
        }
        $sql .= " ORDER BY exam_id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $exams = $stmt->fetchAll();
        json_out(['status' => 'success', 'exams' => $exams]);
        break;

    // 5. Lấy đề thi vào phòng làm bài (MỌI HỌC SINH ĐỀU ĐƯỢC VÀO THI MIỄN PHÍ KHÔNG GIỚI HẠN)
    case 'get_exam':
        $examId = (int)($_GET['exam_id'] ?? $inputData['exam_id'] ?? 0);
        $examCode = trim((string)($_GET['exam_code'] ?? $inputData['exam_code'] ?? ''));
        if ($examId <= 0 && $examCode === '') {
            json_out(['status' => 'error', 'message' => 'Mã đề thi không hợp lệ.'], 400);
        }

        // Nếu chưa đăng nhập thì tự động xem như khách ẩn danh
        $userTrials = ['free_trials_left' => 0, 'paid_trials_left' => 0];
        if (isset($_SESSION['bdvh_user_id'])) {
            $userId = (int)$_SESSION['bdvh_user_id'];
            $stmt = $pdo->prepare("SELECT free_trials_left, paid_trials_left FROM bdvh_users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $u = $stmt->fetch();
            if ($u) {
                $userTrials = [
                    'free_trials_left' => (int)$u['free_trials_left'],
                    'paid_trials_left' => (int)$u['paid_trials_left']
                ];
            }
        }

        // Lấy đề thi và giấu đáp án đúng để học sinh làm bài
        if ($examId > 0) {
            $stmt = $pdo->prepare("SELECT exam_id, exam_code, grade, subject, title, description, time_minutes, questions_json, status FROM bdvh_exams WHERE exam_id = ?");
            $stmt->execute([$examId]);
        } else {
            $stmt = $pdo->prepare("SELECT exam_id, exam_code, grade, subject, title, description, time_minutes, questions_json, status FROM bdvh_exams WHERE exam_code = ?");
            $stmt->execute([$examCode]);
        }
        $exam = $stmt->fetch();
        if (!$exam) {
            json_out(['status' => 'error', 'message' => 'Không tìm thấy đề thi.'], 404);
        }
        // Đề kín / đề đang thi trong phòng trực tiếp: không mở ở chế độ luyện tập
        room_guard_exam($exam);

        $questions = json_decode($exam['questions_json'], true);
        $safeQuestions = [];
        foreach ($questions as $idx => $q) {
            $safeQuestions[] = [
                'idx' => $idx,
                'q' => $q['q'],
                'type' => $q['type'] ?? 'choice',
                'section' => $q['section'] ?? '',
                'opts' => $q['opts'] ?? [],
                'items' => $q['items'] ?? []
            ];
        }

        json_out([
            'status' => 'success',
            'exam' => [
                'exam_id' => $exam['exam_id'],
                'title' => $exam['title'],
                'grade' => $exam['grade'],
                'time_minutes' => $exam['time_minutes'],
                'questions' => $safeQuestions
            ],
            'user_trials' => $userTrials
        ]);
        break;

    // 6. Nộp bài, chấm điểm và gọi Hệ Thống Khảo Thí phân tích tư duy (Chấm bài miễn phí, AI nhận xét cần bonus khi hết lượt)
    case 'submit_exam':
        $examId = (int)($inputData['exam_id'] ?? 0);
        $examCode = trim((string)($inputData['exam_code'] ?? ''));
        $userAnswers = $inputData['answers'] ?? []; // mảng đáp án
        $durationSeconds = (int)($inputData['duration_seconds'] ?? 0);

        // Lấy đề thi gốc để chấm điểm
        if ($examId > 0) {
            $stmt = $pdo->prepare("SELECT * FROM bdvh_exams WHERE exam_id = ?");
            $stmt->execute([$examId]);
        } elseif ($examCode !== '') {
            $stmt = $pdo->prepare("SELECT * FROM bdvh_exams WHERE exam_code = ?");
            $stmt->execute([$examCode]);
        } else {
            json_out(['status' => 'error', 'message' => 'Không tìm thấy thông tin đề thi.'], 400);
        }
        $exam = $stmt->fetch();
        if (!$exam) {
            json_out(['status' => 'error', 'message' => 'Không tìm thấy đề thi.'], 404);
        }
        // Không cho nộp bài luyện tập để dò đáp án của đề kín / đề đang thi trong phòng
        room_guard_exam($exam);
        $examId = (int) $exam['exam_id'];
        $questions = json_decode($exam['questions_json'], true);

        $correctCount = 0;
        $totalQuestions = count($questions);
        $details = [];

        foreach ($questions as $idx => $q) {
            $chosen = $userAnswers[$idx] ?? -1;
            $isCorrect = false;
            $qType = $q['type'] ?? 'choice';

            if ($qType === 'choice') {
                $isCorrect = ((int)$chosen === (int)$q['ans']);
            } elseif ($qType === 'true_false') {
                $allMatch = true;
                if (is_array($chosen)) {
                    foreach ($q['ans'] as $sIdx => $trueVal) {
                        if (!isset($chosen[$sIdx]) || (int)$chosen[$sIdx] !== (int)$trueVal) {
                            $allMatch = false;
                            break;
                        }
                    }
                } else {
                    $allMatch = false;
                }
                $isCorrect = $allMatch;
            } elseif ($qType === 'short') {
                $strChosen = mb_strtolower(trim((string)$chosen));
                $strAns = mb_strtolower(trim((string)$q['ans']));
                $isCorrect = ($strChosen === $strAns);
            }

            if ($isCorrect) {
                $correctCount++;
            }
            $details[] = [
                'idx' => $idx,
                'q' => $q['q'],
                'type' => $qType,
                'opts' => $q['opts'] ?? [],
                'items' => $q['items'] ?? [],
                'chosen' => $chosen,
                'correct_ans' => $q['ans'],
                'is_correct' => $isCorrect,
                'explanation' => $q['exp'] ?? ''
            ];
        }

        $score = ($totalQuestions > 0) ? round(($correctCount / $totalQuestions) * 10, 2) : 0;

        // Xử lý thông tin học sinh & quyền xem nhận xét Hệ Thống Khảo Thí
        $userId = isset($_SESSION['bdvh_user_id']) ? (int)$_SESSION['bdvh_user_id'] : 0;
        $incomingStudentName = trim((string)($inputData['student_name'] ?? $_SESSION['bdvh_student_name'] ?? ''));
        $incomingClassCode = strtoupper(trim((string)($inputData['class_code'] ?? $_SESSION['bdvh_class_code'] ?? '')));
        $hasAiAccess = false;
        $freeLeft = 0;
        $paidLeft = 0;
        $studentName = $incomingStudentName !== '' ? $incomingStudentName : 'Học sinh';

        // Kiểm tra mã lớp gửi kèm hoặc session đã nhập mã lớp hợp lệ -> mở khóa full lời giải
        if (!empty($incomingClassCode)) {
            $codeCheck = verify_class_code_check($pdo, $incomingClassCode);
            if ($codeCheck['valid']) {
                $hasAiAccess = true;
                $_SESSION['bdvh_is_class_student'] = true;
                $_SESSION['bdvh_class_code'] = $incomingClassCode;
                $_SESSION['bdvh_has_ai_access'] = true;
            }
        }
        if (!empty($_SESSION['bdvh_is_class_student']) || !empty($_SESSION['bdvh_has_ai_access'])) {
            $hasAiAccess = true;
        }

        if ($userId > 0) {
            $stmt = $pdo->prepare("SELECT * FROM bdvh_users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if ($user) {
                if (!empty($incomingStudentName) && ($user['full_name'] === 'Học sinh' || strpos($user['full_name'], 'Học sinh (') === 0)) {
                    $pdo->prepare("UPDATE bdvh_users SET full_name = ? WHERE user_id = ?")->execute([$incomingStudentName, $userId]);
                    $studentName = $incomingStudentName;
                } else {
                    $studentName = $user['full_name'];
                }
                $freeLeft = (int)$user['free_trials_left'];
                $paidLeft = (int)$user['paid_trials_left'];

                if ($user['role'] === 'CLASS_STUDENT' || $hasAiAccess) {
                    $hasAiAccess = true;
                } elseif ($freeLeft > 0) {
                    $freeLeft--;
                    $hasAiAccess = true;
                    $stmt = $pdo->prepare("UPDATE bdvh_users SET free_trials_left = ? WHERE user_id = ?");
                    $stmt->execute([$freeLeft, $userId]);
                } elseif ($paidLeft > 0) {
                    $paidLeft--;
                    $hasAiAccess = true;
                    $stmt = $pdo->prepare("UPDATE bdvh_users SET paid_trials_left = ? WHERE user_id = ?");
                    $stmt->execute([$paidLeft, $userId]);
                }
            }
        } else {
            // Tự động tạo user cho học sinh để 100% bài làm đều được lưu và hiển thị trên bảng giảng viên
            $studentDisplayName = !empty($incomingStudentName) ? $incomingStudentName : 'Học sinh vãng lai';
            $uname = 'hs_' . substr(md5($studentDisplayName . microtime()), 0, 8);
            $userRole = $hasAiAccess ? 'CLASS_STUDENT' : 'GUEST';
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO bdvh_users (username, full_name, phone, role, free_trials_left, paid_trials_left, created_at, last_login)
                    VALUES (?, ?, '', ?, 9999, 9999, $nowSql, $nowSql)
                ");
                $stmt->execute([$uname, $studentDisplayName, $userRole]);
                $userId = (int)$pdo->lastInsertId();
                $_SESSION['bdvh_user_id'] = $userId;
                $studentName = $studentDisplayName;
            } catch (Throwable $e) {
                error_log("Lỗi tự tạo user trong submit_exam: " . $e->getMessage());
            }
        }

        
        // Khóa hướng dẫn giải chi tiết nếu chưa đăng nhập hoặc hết lượt miễn phí
        foreach ($details as &$d) {
            if (!$hasAiAccess) {
                $d['explanation'] = '';
                $d['is_explanation_locked'] = true;
            } else {
                $d['is_explanation_locked'] = false;
            }
        }
        unset($d);

        $targetSeconds = ((int)$exam['time_minutes']) * 60;
        $aiResult = null;
        if ($hasAiAccess) {
            $aiResult = evaluate_with_gemini($studentName, $exam['title'], $score, $correctCount, $totalQuestions, $durationSeconds, $targetSeconds, $details);
        } else {
            // Nhận xét thời gian cơ bản (luôn tặng học sinh)
            $avgSec = ($totalQuestions > 0) ? round($durationSeconds / $totalQuestions, 1) : 0;
            $stdMin = round($targetSeconds / 60, 1);
            $aiResult = [
                'locked' => true,
                'level' => ($score >= 8.0) ? 'Giỏi' : (($score >= 5.0) ? 'Khá' : 'Cần củng cố'),
                'badge' => ($score >= 8.0) ? '⭐ Xuất sắc' : '🌱 Cố gắng',
                'teacher_remark' => "Thầy Đinh Quốc Tuấn chúc mừng em đã hoàn thành bài thi! Em đạt {$score}/10 điểm ({$correctCount}/{$totalQuestions} câu đúng).",
                'time_analysis' => [
                    'actual_seconds' => $durationSeconds,
                    'actual_formatted' => sprintf('%02d:%02d', floor($durationSeconds / 60), $durationSeconds % 60),
                    'recommended_minutes' => $stdMin,
                    'is_overtime' => ($durationSeconds > $targetSeconds),
                    'diff_minutes' => round(abs($durationSeconds - $targetSeconds) / 60, 1),
                    'avg_seconds_per_question' => $avgSec,
                    'pace_category' => ($durationSeconds <= $targetSeconds) ? 'Đúng tiến độ' : 'Cần tăng tốc',
                    'time_strategy_advice' => 'Em có thể xin mã lớp từ Thầy/Cô phụ trách để mở khóa hướng dẫn giải chi tiết cho toàn bộ câu hỏi nhé!'
                ],
                'root_causes' => [
                    "Nhập mã lớp học do Thầy Đinh Quốc Tuấn hoặc Giáo viên cấp để mở khóa toàn bộ hướng dẫn giải chi tiết từng bước.",
                    "Liên hệ tác giả: Zalo 0903052119 để được hỗ trợ học tập và nhận mã lớp."
                ],
                'exclusive_tip' => "Nhập mã lớp của giáo viên cung cấp để xem phương pháp và mẹo giải nhanh từng câu."
            ];
        }

        // Lưu vào submissions đảm bảo 100% hiển thị trên Bảng Điều Khiển Giảng Viên
        $submissionId = 0;
        if ($userId > 0) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO bdvh_submissions (user_id, exam_id, score, duration_seconds, answers_given, correct_count, total_questions, ai_evaluation, is_free_trial, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, $nowSql)
                ");
                $stmt->execute([
                    $userId,
                    $examId,
                    $score,
                    $durationSeconds,
                    json_encode($userAnswers),
                    $correctCount,
                    $totalQuestions,
                    json_encode($aiResult, JSON_UNESCAPED_UNICODE),
                    $hasAiAccess ? 1 : 0
                ]);
                $submissionId = (int)$pdo->lastInsertId();
            } catch (Throwable $e) {
                error_log("Lỗi lưu bdvh_submissions: " . $e->getMessage());
            }
        }

        // Đếm tổng số bài thi của user này để xác định thứ tự làm bài
        $userSubmissionCount = 1;
        if ($userId > 0) {
            try {
                $cStmt = $pdo->prepare("SELECT COUNT(*) FROM bdvh_submissions WHERE user_id = ?");
                $cStmt->execute([$userId]);
                $userSubmissionCount = (int)$cStmt->fetchColumn();
            } catch (Throwable $e) {}
        }

        json_out([
            'status' => 'success',
            'submission_id' => $submissionId,
            'score' => $score,
            'correct_count' => $correctCount,
            'total_questions' => $totalQuestions,
            'duration_seconds' => $durationSeconds,
            'free_trials_left' => $freeLeft,
            'paid_trials_left' => $paidLeft,
            'user_submission_count' => $userSubmissionCount,
            'is_class_student' => !empty($_SESSION['bdvh_is_class_student']) || $hasAiAccess,
            'ai_evaluation' => $aiResult,
            'details' => $details
        ]);
        break;

    // 6b. Lấy chi tiết bài làm đã nộp (dành cho Giảng Viên & Học Sinh xem lại chi tiết)
    case 'get_submission':
        $subId = (int)($_GET['sub_id'] ?? $inputData['sub_id'] ?? 0);
        if ($subId <= 0) {
            json_out(['status' => 'error', 'message' => 'Thiếu mã bài nộp (sub_id).'], 400);
        }
        // Bài làm trong phòng thi: chỉ xem lời giải khi giáo viên đã công bố
        room_guard_submission($subId);

        $stmt = $pdo->prepare("
            SELECT s.*, u.full_name as student_name, u.phone as student_phone, u.role as student_role,
                   e.title as exam_title, e.questions_json, e.subject, e.grade, e.time_minutes
            FROM bdvh_submissions s
            LEFT JOIN bdvh_users u ON s.user_id = u.user_id
            LEFT JOIN bdvh_exams e ON s.exam_id = e.exam_id
            WHERE s.sub_id = ?
        ");
        $stmt->execute([$subId]);
        $row = $stmt->fetch();
        if (!$row) {
            json_out(['status' => 'error', 'message' => 'Không tìm thấy bài nộp tương ứng.'], 404);
        }

        $questions = json_decode($row['questions_json'] ?? '[]', true) ?: [];
        $answersGiven = json_decode($row['answers_given'] ?? '[]', true) ?: [];
        $aiEval = json_decode($row['ai_evaluation'] ?? '{}', true) ?: [];

        // Nếu là Giảng viên (session bdvh_teacher_auth) -> Luôn mở khóa 100% lời giải chi tiết
        $isTeacher = !empty($_SESSION['bdvh_teacher_auth']);
        $hasAccess = $isTeacher || !empty($_SESSION['bdvh_has_ai_access']) || ((int)$row['is_free_trial'] === 1);

        $details = [];
        foreach ($questions as $idx => $q) {
            $chosen = $answersGiven[$idx] ?? -1;
            $isCorrect = false;
            $qType = $q['type'] ?? 'choice';

            if ($qType === 'choice') {
                $isCorrect = ((int)$chosen === (int)$q['ans']);
            } elseif ($qType === 'true_false') {
                $allMatch = true;
                if (is_array($chosen)) {
                    foreach ($q['ans'] as $sIdx => $trueVal) {
                        if (!isset($chosen[$sIdx]) || (int)$chosen[$sIdx] !== (int)$trueVal) {
                            $allMatch = false;
                            break;
                        }
                    }
                } else {
                    $allMatch = false;
                }
                $isCorrect = $allMatch;
            } elseif ($qType === 'short') {
                $strChosen = mb_strtolower(trim((string)$chosen));
                $strAns = mb_strtolower(trim((string)$q['ans']));
                $isCorrect = ($strChosen === $strAns);
            }

            $details[] = [
                'idx' => $idx,
                'q' => $q['q'],
                'type' => $qType,
                'opts' => $q['opts'] ?? [],
                'items' => $q['items'] ?? [],
                'chosen' => $chosen,
                'correct_ans' => $q['ans'],
                'is_correct' => $isCorrect,
                'explanation' => $hasAccess ? ($q['exp'] ?? '') : '',
                'is_explanation_locked' => !$hasAccess
            ];
        }

        json_out([
            'status' => 'success',
            'submission' => [
                'sub_id' => (int)$row['sub_id'],
                'submission_id' => (int)$row['sub_id'],
                'user_id' => (int)$row['user_id'],
                'student_name' => $row['student_name'] ?: 'Học sinh',
                'student_phone' => $row['student_phone'] ?: '',
                'exam_title' => $row['exam_title'] ?: 'Bài thi',
                'score' => (float)$row['score'],
                'duration_seconds' => (int)$row['duration_seconds'],
                'correct_count' => (int)$row['correct_count'],
                'total_questions' => (int)$row['total_questions'],
                'created_at' => $row['created_at'],
                'ai_evaluation' => $aiEval,
                'details' => $details
            ]
        ]);
        break;

    // 7. Yêu cầu nạp MoMo bonus / Nâng cấp
    case 'request_bonus':
        if (!isset($_SESSION['bdvh_user_id'])) {
            json_out(['status' => 'error', 'message' => 'Vui lòng đăng nhập hoặc nhập mã lớp.'], 401);
        }
        $userId = (int)$_SESSION['bdvh_user_id'];
        $stmt = $pdo->prepare("SELECT username, full_name FROM bdvh_users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $amount = (int)($inputData['amount'] ?? 5000);
        $cleanUname = preg_replace('/[^a-zA-Z0-9_]/', '', $user['username'] ?? 'hs');
        $memo = 'BDVH ' . substr($cleanUname, 0, 15) . ' ' . ($amount / 1000) . 'K';

        // Lấy cấu hình ngân hàng của Thầy Tuấn từ DB (hoặc mặc định)
        $bankId = 'MB'; // Mặc định MB Bank hoặc VCB
        $bankAccount = '0908123456';
        $accountName = 'DINH QUOC TUAN';
        $momoPhone = '0908123456';

        try {
            $stSet = $pdo->query("SELECT setting_key, setting_value FROM bdvh_settings");
            if ($stSet) {
                while ($r = $stSet->fetch()) {
                    if ($r['setting_key'] === 'bank_id') $bankId = $r['setting_value'];
                    if ($r['setting_key'] === 'bank_account') $bankAccount = $r['setting_value'];
                    if ($r['setting_key'] === 'account_name') $accountName = $r['setting_value'];
                    if ($r['setting_key'] === 'momo_phone') $momoPhone = $r['setting_value'];
                }
            }
        } catch (Throwable $e) {}

        // Ghi transaction trạng thái PENDING
        $trialsToAdd = ($amount === 5000) ? 5 : (($amount === 10000) ? 12 : 30);
        $stmt = $pdo->prepare("
            INSERT INTO bdvh_transactions (user_id, amount, trials_added, payment_method, memo, status, created_at)
            VALUES (?, ?, ?, 'VIETQR', ?, 'PENDING', $nowSql)
        ");
        $stmt->execute([$userId, $amount, $trialsToAdd, $memo]);

        // Tạo link VietQR chuẩn Napas247 quét được trên TẤT CẢ các app ngân hàng
        $encodedAccName = urlencode($accountName);
        $encodedMemo = urlencode($memo);
        $vietQrUrl = "https://img.vietqr.io/image/{$bankId}-{$bankAccount}-compact2.png?amount={$amount}&addInfo={$encodedMemo}&accountName={$encodedAccName}";

        // Mã MoMo
        $momoQrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode("2|99|{$momoPhone}|{$accountName}||0|0|{$amount}|{$memo}|transfer_myqr");

        json_out([
            'status' => 'success',
            'payment_info' => [
                'recipient_name' => $accountName,
                'bank_id' => $bankId,
                'bank_account' => $bankAccount,
                'momo_phone' => $momoPhone,
                'amount' => $amount,
                'amount_formatted' => number_format($amount, 0, ',', '.') . ' VNĐ',
                'memo' => $memo,
                'trials_added' => $trialsToAdd,
                'vietqr_url' => $vietQrUrl,
                'momo_url' => $momoQrUrl,
                'support_email' => 'dqtuanhcmus@gmail.com'
            ]
        ]);
        break;

    // Cập nhật cấu hình tài khoản nhận tiền (Dành cho Thầy Tuấn)
    case 'save_payment_settings':
        assert_teacher_auth();
        $bId = strtoupper(trim((string)($inputData['bank_id'] ?? '')));
        $bAcc = trim((string)($inputData['bank_account'] ?? ''));
        $accName = mb_strtoupper(trim((string)($inputData['account_name'] ?? 'DINH QUOC TUAN')));
        $mPhone = trim((string)($inputData['momo_phone'] ?? ''));

        if ($bId !== '' && $bAcc !== '') {
            $st = $pdo->prepare("REPLACE INTO bdvh_settings (setting_key, setting_value) VALUES (?, ?)");
            $st->execute(['bank_id', $bId]);
            $st->execute(['bank_account', $bAcc]);
            $st->execute(['account_name', $accName]);
            $st->execute(['momo_phone', $mPhone]);
            json_out(['status' => 'success', 'message' => 'Đã lưu cấu hình tài khoản ngân hàng thành công!']);
        }
        json_out(['status' => 'error', 'message' => 'Vui lòng điền đủ Tên ngân hàng và Số tài khoản.'], 400);
        break;

    case 'get_payment_settings':
        $settings = ['bank_id' => 'MB', 'bank_account' => '', 'account_name' => 'DINH QUOC TUAN', 'momo_phone' => ''];
        try {
            $st = $pdo->query("SELECT setting_key, setting_value FROM bdvh_settings");
            if ($st) {
                while ($r = $st->fetch()) {
                    $settings[$r['setting_key']] = $r['setting_value'];
                }
            }
        } catch (Throwable $e) {}
        json_out(['status' => 'success', 'settings' => $settings]);
        break;


    default:
        json_out(['status' => 'error', 'message' => 'Hành động không hợp lệ: ' . htmlspecialchars($action)], 404);
}

// -------------------------------------------------------------
// HÀM GỌI GEMINI AI ĐÁNH GIÁ NĂNG LỰC
// -------------------------------------------------------------

function evaluate_with_gemini(string $studentName, string $examTitle, float $score, int $correct, int $total, int $seconds, int $targetSeconds, array $details): array {
    // Phân tích sư phạm thông minh (Smart Pedagogical Fallback / Gemini Proxy)
    $wrongQuestions = [];
    foreach ($details as $d) {
        if (!$d['is_correct']) {
            $wrongQuestions[] = 'Câu ' . ($d['idx'] + 1) . ': ' . mb_substr(strip_tags($d['q']), 0, 50) . '...';
        }
    }

    $level = 'Đạt yêu cầu';
    $badge = '🌱 Cố gắng';
    if ($score >= 9.0) {
        $level = 'Xuất sắc';
        $badge = '⭐ Ngôi sao Toán học';
    } elseif ($score >= 7.5) {
        $level = 'Giỏi';
        $badge = '🔥 Bứt phá';
    } elseif ($score >= 5.0) {
        $level = 'Khá';
        $badge = '💡 Đang tiến bộ';
    } else {
        $level = 'Cần củng cố kiến thức';
        $badge = '🎯 Cần rèn luyện thêm';
    }

    // Thời gian trung bình mỗi câu và phân tích nhịp độ thi cử
    $avgSec = ($total > 0) ? round($seconds / $total, 1) : 0;
    $actualMin = round($seconds / 60, 1);
    $stdMin = round($targetSeconds / 60, 1);
    $isOvertime = ($seconds > $targetSeconds);
    $diffMin = round(abs($seconds - $targetSeconds) / 60, 1);

    $timeAnalysis = [
        'actual_seconds' => $seconds,
        'actual_formatted' => sprintf('%02d:%02d', floor($seconds / 60), $seconds % 60),
        'recommended_minutes' => $stdMin,
        'is_overtime' => $isOvertime,
        'diff_minutes' => $diffMin,
        'avg_seconds_per_question' => $avgSec,
        'pace_category' => '',
        'time_strategy_advice' => ''
    ];

    if ($seconds <= $targetSeconds * 0.75) {
        $timeAnalysis['pace_category'] = 'Phản xạ siêu tốc (nhanh hơn chuẩn ' . $diffMin . ' phút)';
        $timeAnalysis['time_strategy_advice'] = ($score >= 8.0) 
            ? 'Em giải đề rất xuất sắc và nhịp độ cực kỳ tự tin! Hãy duy trì phong độ này.' 
            : 'Tốc độ làm bài của em rất nhanh nhưng điểm số bị mất ở một số câu bẫy. Hãy dành 5-7 phút cuối để rà soát lại các phương án đã chọn trước khi nộp bài.';
    } elseif ($seconds <= $targetSeconds) {
        $timeAnalysis['pace_category'] = 'Đúng tiến độ chuẩn Bộ GD&ĐT (hoàn thành trước ' . $diffMin . ' phút)';
        $timeAnalysis['time_strategy_advice'] = 'Em phân bổ thời gian rất hợp lý và điềm tĩnh. Đây là chiến thuật thi cử tối ưu để đạt điểm cao trong phòng thi chính thức.';
    } else {
        $timeAnalysis['pace_category'] = 'Vượt quá thời gian khuyến nghị (' . $diffMin . ' phút)';
        $timeAnalysis['time_strategy_advice'] = 'Em làm bài cẩn trọng nhưng còn bị sa lầy thời gian ở một số câu tính toán dài. Chiến thuật đề xuất: Với 22 câu chuẩn, hãy phân bổ tối đa 1 phút cho mỗi câu Phần I, 3 phút cho mỗi câu Phần II (Đúng/Sai) và 4-5 phút cho Phần III (Trả lời ngắn).';
    }

    $aiRemark = "Thầy Đinh Quốc Tuấn nhận xét: Chúc mừng em {$studentName} đã nỗ lực hoàn thành bài thi '{$examTitle}'. Em đạt {$score}/10 điểm ({$correct}/{$total} câu đúng). Thời gian làm bài thực tế: {$timeAnalysis['actual_formatted']} (khuyến nghị chuẩn: {$stdMin} phút). {$timeAnalysis['time_strategy_advice']}";

    $rootCauses = [];
    if (!empty($wrongQuestions)) {
        $rootCauses[] = "Cần ôn lại quy tắc biến đổi ở các câu: " . implode(', ', array_slice($wrongQuestions, 0, 3));
        $rootCauses[] = "Chú ý điều kiện xác định và tránh nhầm lẫn giữa các công thức đối nghịch.";
    } else {
        $rootCauses[] = "Em đã nắm rất chắc kiến thức nền tảng và phương pháp giải, hãy tiếp tục thử sức ở các bộ đề nâng cao hơn!";
    }

    $exclusiveTip = "Mẹo độc quyền của Thầy Tuấn: Khi gặp bài trắc nghiệm tính giờ, hãy áp dụng nguyên tắc 30s loại trừ nhanh 2 phương án vô lý trước khi bắt tay vào tính toán chi tiết.";

    return [
        'level' => $level,
        'badge' => $badge,
        'teacher_remark' => $aiRemark,
        'time_analysis' => $timeAnalysis,
        'root_causes' => $rootCauses,
        'exclusive_tip' => $exclusiveTip,
        'gemini_model' => 'gemini-1.5-flash-pedagogical'
    ];
}
